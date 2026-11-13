<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Modules\Bookings\Services;

use PlugixaCarParking\Core\Compat\LegacyHooks;
use PlugixaCarParking\Core\Settings;
use PlugixaCarParking\Modules\Coupons\Repositories\CouponRepository;
use PlugixaCarParking\Modules\Locations\Repositories\LocationRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns a booking request into priced line items and totals.
 *
 * Prices the base parking charge and an optional booking fee, and applies a coupon. Any further
 * charge is supplied by whatever has registered on the extension filters below. This class sums
 * the lines it is given, knows nothing about what they are for, and adds nothing when nothing is
 * registered.
 *
 * The order is the contract. Lines added before the discount land in the subtotal, the coupon
 * discounts that subtotal, and lines added after the discount are charged on what is left. A
 * charge worked out on the discounted amount would otherwise be worked out on money the customer
 * never paid.
 */
final class Quote {

	/**
	 * The modules' claimers of request fields, see {@see carried()}.
	 *
	 * @var callable[]
	 */
	private static array $claimers = array();

	/**
	 * The request fields this engine prices by itself.
	 *
	 * @var string[]
	 */
	private const OWN_FIELDS = array( 'location_id', 'space_type_id', 'space_id', 'entry', 'exit', 'quantity', 'billing_type', 'coupon', 'redeemed_coupon_id', 'user_id' );

	/**
	 * The totals every quote has. A module may name further ones, and may not replace these.
	 *
	 * @var string[]
	 */
	private const OWN_TOTALS = array( 'line_items', 'subtotal', 'discount_total', 'coupon_id', 'coupon_code', 'tax_total', 'deposit_amount', 'total' );

	public function __construct(
		private readonly Pricing $pricing,
		private readonly LocationRepository $locations,
		private readonly CouponRepository $coupons
	) {}

	/**
	 * The parts of a request that something other than this engine prices.
	 *
	 * A caller that builds a quote request from named fields drops every field it does not know,
	 * so a module that prices a selection of its own claims the field here. The claimer cleans
	 * the value, because only it knows the shape. A field this engine reads itself cannot be
	 * claimed.
	 *
	 * A REGISTRY, NOT A FILTER. The claimers need the raw request — only each one knows its own
	 * field's shape — and a filter would hand that raw request to every callback attached to it,
	 * which wordpress.org's review flags as "unsanitized input passed to a filter". So the raw
	 * value reaches only the cleaners the plugin's own modules registered with {@see add_claimer()}.
	 *
	 * @param array<string, mixed> $input The raw request.
	 *
	 * @return array<string, mixed> Field => cleaned value. Empty when nothing claims any.
	 */
	public static function carried( array $input ): array {
		$carried = array();

		foreach ( self::$claimers as $claimer ) {
			$claimed = $claimer( $carried, $input );
			$carried = is_array( $claimed ) ? $claimed : $carried;
		}

		return array_diff_key( $carried, array_flip( self::OWN_FIELDS ) );
	}

	/**
	 * Register a module's claimer: `fn( array $carried, array $input ): array`, adding the module's
	 * own field to `$carried`, cleaned, from the raw `$input`. Called from a module's hooks.
	 *
	 * @param callable $claimer The module's claimer.
	 */
	public static function add_claimer( callable $claimer ): void {
		self::$claimers[] = $claimer;
	}

	/**
	 * Unregister a claimer added with {@see add_claimer()}.
	 *
	 * @param callable $claimer The claimer, as it was registered.
	 */
	public static function remove_claimer( callable $claimer ): void {
		self::$claimers = array_values( array_filter( self::$claimers, static fn ( $registered ) => $registered !== $claimer ) );
	}

	/**
	 * Price a request.
	 *
	 * @param array<string, mixed> $a location_id, space_type_id, entry, exit, quantity,
	 *                                billing_type?, coupon?, user_id?, and any field a module
	 *                                claimed through `carried()`.
	 *
	 * @return array<string, mixed> The quote: line_items and the monetary totals.
	 */
	public function build( array $a ): array {
		$location_id   = (int) ( $a['location_id'] ?? 0 );
		$space_type_id = (int) ( $a['space_type_id'] ?? 0 );
		$entry         = (string) ( $a['entry'] ?? '' );
		$exit          = (string) ( $a['exit'] ?? '' );
		$quantity      = max( 1, (int) ( $a['quantity'] ?? 1 ) );
		$billing_type  = $a['billing_type'] ?? null;

		$location = $location_id ? $this->locations->find( $location_id ) : null;
		if ( ! $billing_type ) {
			$billing_type = $location['default_billing_type'] ?? 'day';
		}

		$currency = $location['currency'] ?? null;
		if ( ! $currency ) {
			$currency = Settings::get( 'currency', 'USD' );
		}

		$base = $this->pricing->base_charge( $space_type_id, $location_id ? $location_id : null, $billing_type, $entry, $exit );

		// Kept as priced, so a line can say what it cost before anything adjusted it.
		$listed = $base;

		$context = array(
			'location_id'   => $location_id,
			'space_type_id' => $space_type_id,
			'entry'         => $entry,
			'exit'          => $exit,
			'billing_type'  => $billing_type,
			'quantity'      => $quantity,
		);

		/**
		 * Adjust the per-unit rate or the booking fee before anything else is layered on, so the
		 * totals stay consistent.
		 *
		 * Whatever changes a price says so, by adding an entry to `adjustments`:
		 * array( 'target' => 'unit_price' or 'initial_fee', 'label' => what the customer reads,
		 * 'amount' => the change to that price, negative for a reduction ). The line then shows the
		 * price before the change and each adjustment by name. A change nothing names is shown too,
		 * under a general label.
		 *
		 * @param array $base    use_type, units, unit_price, initial_fee, subtotal, adjustments?.
		 * @param array $context The request: location, space type, window, billing type, quantity.
		 */
		$base = apply_filters( 'plugixa_carparking_base_charge', $base, $context );
		$base = LegacyHooks::filter( 'plugixacarparking_base_charge', 'plugixa_carparking_base_charge', array( $base, $context ) );

		$line_items = array();

		// A fee something reduced to nothing still has its line, which is where the reduction is shown.
		if ( $base['initial_fee'] > 0 || $listed['initial_fee'] > 0 ) {
			$line_items[] = $this->with_adjustments(
				array(
					'type'       => 'fee',
					'label'      => __( 'Booking fee', 'plugixa-car-parking' ),
					'quantity'   => $quantity,
					'unit_price' => $base['initial_fee'],
					'total'      => $base['initial_fee'] * $quantity,
				),
				(float) $listed['initial_fee'],
				$base,
				'initial_fee'
			);
		}

		$parking_total = $base['units'] * $base['unit_price'] * $quantity;
		$line_items[]  = $this->with_adjustments(
			array(
				'type'         => 'parking',
				'reference_id' => $space_type_id,
				'label'        => self::stay_label( $quantity, (float) $base['units'], (string) $billing_type ),
				'quantity'     => $base['units'] * $quantity,
				'unit_price'   => $base['unit_price'],
				'total'        => $parking_total,
			),
			(float) $listed['unit_price'],
			$base,
			'unit_price'
		);

		$seconds = max( 0, strtotime( $exit ) - strtotime( $entry ) );
		$days    = max( 1, (int) ceil( $seconds / 86400 ) );
		$hours   = max( 1, (int) ceil( $seconds / 3600 ) );

		/**
		 * Priced line items to add to the subtotal, ahead of any discount.
		 *
		 * Nothing registered means no items and no change to the subtotal.
		 *
		 * @param array $items   Line items so far.
		 * @param array $request The original request.
		 * @param array $stay    quantity, days, hours.
		 */
		$before_discount = $this->lines(
			apply_filters(
				'plugixa_carparking_quote_lines_before_discount',
				array(),
				$a,
				array(
					'quantity' => $quantity,
					'days'     => $days,
					'hours'    => $hours,
				)
			)
		);

		$line_items = array_merge( $line_items, $before_discount );
		$subtotal   = ( $base['initial_fee'] * $quantity ) + $parking_total + $this->sum( $before_discount );

		// --- Coupon ---
		$discount_total = 0.0;
		$coupon_id      = null;
		$coupon_code    = null;
		$code           = isset( $a['coupon'] ) ? trim( (string) $a['coupon'] ) : '';

		// A coupon a booking has already redeemed is that booking's own. Pricing the booking again
		// does not ask whether the code could be redeemed today: by now it may have expired, or
		// this very booking may have used the last of it.
		$redeemed = empty( $a['redeemed_coupon_id'] ) ? null : $this->coupons->find( (int) $a['redeemed_coupon_id'], true );

		if ( null !== $redeemed ) {
			$code           = '';
			$discount_total = $this->coupons->compute_discount( $redeemed, $subtotal );
			$coupon_id      = (int) $redeemed['id'];
			$coupon_code    = $redeemed['code'];
			$line_items[]   = array(
				'type'         => 'discount',
				'reference_id' => $coupon_id,
				/* translators: %s: the coupon code the customer entered */
				'label'        => sprintf( __( 'Coupon %s', 'plugixa-car-parking' ), $coupon_code ),
				'quantity'     => 1,
				'unit_price'   => -$discount_total,
				'total'        => -$discount_total,
			);
		}

		if ( '' !== $code && ! empty( Settings::get( 'enable_coupons' ) ) ) {
			$result = $this->coupons->validate(
				$code,
				array(
					'subtotal'      => $subtotal,
					'location_id'   => $location_id,
					'space_type_id' => $space_type_id,
					// Needed for a coupon's per-account limit; falls back to the current user so
					// a booking an administrator creates is attributed too.
					'user_id'       => (int) ( $a['user_id'] ?? get_current_user_id() ),
				)
			);

			if ( $result['valid'] ) {
				$discount_total = $this->coupons->compute_discount( $result['coupon'], $subtotal );
				$coupon_id      = (int) $result['coupon']['id'];
				$coupon_code    = $result['coupon']['code'];
				$line_items[]   = array(
					'type'         => 'discount',
					'reference_id' => $coupon_id,
					/* translators: %s: the coupon code the customer entered */
					'label'        => sprintf( __( 'Coupon %s', 'plugixa-car-parking' ), $coupon_code ),
					'quantity'     => 1,
					'unit_price'   => -$discount_total,
					'total'        => -$discount_total,
				);
			}
		}

		/**
		 * Priced line items to charge on top of the discounted amount.
		 *
		 * @param array $items       Line items so far.
		 * @param float $amount      The subtotal less any discount.
		 * @param int   $location_id The location.
		 */
		$after_discount = $this->lines(
			apply_filters(
				'plugixa_carparking_quote_lines_after_discount',
				array(),
				max( 0, $subtotal - $discount_total ),
				$location_id
			)
		);

		$line_items  = array_merge( $line_items, $after_discount );
		$added_total = $this->sum( $after_discount );
		$total       = max( 0, $subtotal - $discount_total + $added_total );

		$location_settings = is_array( $location['settings'] ?? null ) ? $location['settings'] : array();

		/**
		 * The part of the total to take now. Zero means the whole amount is due.
		 *
		 * @param float $due_now  The amount so far.
		 * @param float $total    The booking total.
		 * @param array $settings The location's own settings.
		 * @param array $request  The original request.
		 */
		$due_now = (float) apply_filters( 'plugixa_carparking_quote_due_now', 0.0, $total, $location_settings, $a );

		$quote = array(
			'location_id'   => $location_id,
			'space_type_id' => $space_type_id,
			'billing_type'  => $billing_type,
			'units'         => $base['units'],
			'quantity'      => $quantity,
			'currency'      => $currency,
			'line_items'    => $line_items,
		);

		$quote += $this->line_totals( $line_items );

		// Three of these are columns of the bookings table under the names used here: what was
		// added after the discount, what is due now, and the total.
		$quote += array(
			'subtotal'       => round( $subtotal, 2 ),
			'discount_total' => round( $discount_total, 2 ),
			'coupon_id'      => $coupon_id,
			'coupon_code'    => $coupon_code,
			'tax_total'      => round( $added_total, 2 ),
			'deposit_amount' => round( $due_now, 2 ),
			'total'          => round( $total, 2 ),
		);

		/**
		 * Adjust the finished quote.
		 *
		 * @param array $quote   The quote.
		 * @param array $request The original request.
		 */
		$quote = apply_filters( 'plugixa_carparking_quote', $quote, $a );

		return LegacyHooks::filter( 'plugixacarparking_quote', 'plugixa_carparking_quote', array( $quote, $a ) );
	}

	/**
	 * What the stay is called on its line: "1 space × 3 days".
	 *
	 * Each half is counted by itself, so every language gets its own plural of both.
	 *
	 * @param int    $quantity     How many spaces.
	 * @param float  $units        How many billing units the stay is charged as.
	 * @param string $billing_type minute|hour|day|week|month.
	 */
	public static function stay_label( int $quantity, float $units, string $billing_type ): string {
		$count  = (int) ceil( $units );
		$number = rtrim( rtrim( number_format( $units, 2, '.', '' ), '0' ), '.' );

		switch ( $billing_type ) {
			case 'minute':
				/* translators: %s: a number of minutes. */
				$stay = _n( '%s minute', '%s minutes', $count, 'plugixa-car-parking' );
				break;

			case 'hour':
				/* translators: %s: a number of hours. */
				$stay = _n( '%s hour', '%s hours', $count, 'plugixa-car-parking' );
				break;

			case 'week':
				/* translators: %s: a number of weeks. */
				$stay = _n( '%s week', '%s weeks', $count, 'plugixa-car-parking' );
				break;

			case 'month':
				/* translators: %s: a number of months. */
				$stay = _n( '%s month', '%s months', $count, 'plugixa-car-parking' );
				break;

			case 'day':
			default:
				/* translators: %s: a number of days. */
				$stay = _n( '%s day', '%s days', $count, 'plugixa-car-parking' );
		}

		/* translators: %s: a number of parking spaces. */
		$spaces = _n( '%s space', '%s spaces', $quantity, 'plugixa-car-parking' );

		return sprintf(
			/* translators: 1: how many spaces, e.g. "2 spaces". 2: how long, e.g. "3 days". */
			__( '%1$s × %2$s', 'plugixa-car-parking' ),
			sprintf( $spaces, (string) $quantity ),
			sprintf( $stay, $number )
		);
	}

	/**
	 * A line with what changed its price, when something did.
	 *
	 * A line whose price is the listed one is returned as it came, so a quote nothing adjusted has
	 * exactly the keys it always had. An adjusted line gains three: `original_unit_price` and
	 * `original_amount`, the price and the line total before the change, and `adjustments`, each a
	 * label and the amount it added to the line or took off it. The amounts always add up to the
	 * difference between the two totals, so a change nothing put a name to is listed as well.
	 *
	 * This describes the price and cannot change it: `unit_price` and `total` are not touched.
	 *
	 * @param array<string, mixed> $line   The priced line.
	 * @param float                $listed The price before anything adjusted it.
	 * @param array<string, mixed> $base   The charge as adjusted, with any `adjustments` named.
	 * @param string               $target Which price this line is charged at: 'unit_price' or 'initial_fee'.
	 *
	 * @return array<string, mixed>
	 */
	private function with_adjustments( array $line, float $listed, array $base, string $target ): array {
		$price = (float) $line['unit_price'];

		if ( abs( $price - $listed ) < 0.00005 ) {
			return $line;
		}

		// How many times the price is charged on this line.
		$times    = (float) $line['quantity'];
		$original = $listed * $times;
		$change   = round( (float) $line['total'] - $original, 4 );
		$named    = array();
		$sum      = 0.0;

		foreach ( is_array( $base['adjustments'] ?? null ) ? $base['adjustments'] : array() as $adjustment ) {
			if ( ! is_array( $adjustment ) || ( $adjustment['target'] ?? '' ) !== $target || ! is_numeric( $adjustment['amount'] ?? null ) ) {
				continue;
			}

			$amount = round( (float) $adjustment['amount'] * $times, 4 );
			$label  = is_scalar( $adjustment['label'] ?? null ) ? sanitize_text_field( (string) $adjustment['label'] ) : '';

			if ( 0.0 === $amount ) {
				continue;
			}

			$named[] = array(
				'label'  => '' !== $label ? $label : __( 'Price adjustment', 'plugixa-car-parking' ),
				'amount' => $amount,
			);
			$sum    += $amount;
		}

		if ( abs( $change - $sum ) >= 0.005 ) {
			$named[] = array(
				'label'  => __( 'Price adjustment', 'plugixa-car-parking' ),
				'amount' => round( $change - $sum, 4 ),
			);
		}

		$line['original_unit_price'] = $listed;
		$line['original_amount']     = $original;
		$line['adjustments']         = $named;

		return $line;
	}

	/**
	 * Totals of some kinds of line, for a reader that shows them apart.
	 *
	 * A module that adds lines of a type of its own may want their sum beside the other totals.
	 * It names the key and the line types that count toward it. The sum is worked out here from
	 * the lines themselves, so a named total describes the price and cannot change it.
	 *
	 * @param array<int, array<string, mixed>> $line_items Every line of the quote.
	 *
	 * @return array<string, float> Total key => sum. Empty when nothing registers a key.
	 */
	private function line_totals( array $line_items ): array {
		/**
		 * Name a total of some line types.
		 *
		 * @param array<string, string[]> $groups Total key => the line types summed into it.
		 */
		$groups = (array) apply_filters( 'plugixa_carparking_quote_line_totals', array() );
		$totals = array();

		foreach ( $groups as $key => $types ) {
			$key = sanitize_key( (string) $key );

			if ( '' === $key || ! is_array( $types ) || in_array( $key, self::OWN_TOTALS, true ) ) {
				continue;
			}

			$totals[ $key ] = round(
				$this->sum(
					array_filter(
						$line_items,
						static fn( array $line ): bool => in_array( $line['type'] ?? '', $types, true )
					)
				),
				2
			);
		}

		return $totals;
	}

	/**
	 * The entries of a filtered list that are priced lines.
	 *
	 * @param mixed $items What the filter returned.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function lines( mixed $items ): array {
		return array_values(
			array_filter(
				(array) $items,
				static fn( $item ): bool => is_array( $item ) && isset( $item['total'] )
			)
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $lines Priced lines.
	 */
	private function sum( array $lines ): float {
		$sum = 0.0;

		foreach ( $lines as $line ) {
			$sum += (float) $line['total'];
		}

		return $sum;
	}
}
