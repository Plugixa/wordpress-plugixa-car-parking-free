<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Modules\Invoice\Services;

use PlugixaCarParking\Core\Money;
use PlugixaCarParking\Core\Settings;
use PlugixaCarParking\Modules\Bookings\Repositories\ItemTable;
use PlugixaCarParking\Modules\Bookings\Services\CustomAnswers;
use PlugixaCarParking\Modules\Locations\Repositories\LocationRepository;
use PlugixaCarParking\Modules\SpaceTypes\Repositories\SpaceTypeRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the booking summary shown on the public summary page and linked from emails.
 *
 * A customer reaches a summary with no login. What authorises them is a token derived from the
 * booking reference and a secret only this site holds, so knowing a reference is not enough.
 *
 * THE TOKEN'S DERIVATION IS FROZEN. Links carrying it are already in customers' inboxes; change
 * the message, the algorithm or the length and every one of them stops working.
 */
final class Invoice {

	public function __construct(
		private readonly ItemTable $items,
		private readonly LocationRepository $locations,
		private readonly SpaceTypeRepository $space_types,
		private readonly CustomAnswers $answers
	) {}

	/**
	 * The access token for one booking's summary.
	 *
	 * @param string $ref Booking reference.
	 */
	public static function token( string $ref ): string {
		return substr( hash_hmac( 'sha256', 'pcp-invoice|' . $ref, wp_salt( 'auth' ) ), 0, 32 );
	}

	/**
	 * Whether a token is the right one for a booking reference.
	 *
	 * @param string $ref   Booking reference.
	 * @param string $token The token the request carried.
	 */
	public static function verify( string $ref, string $token ): bool {
		return '' !== $ref && hash_equals( self::token( $ref ), $token );
	}

	/**
	 * The public summary page for a booking, or an empty string when the site has not set one.
	 *
	 * @param string $ref Booking reference.
	 */
	public static function summary_url( string $ref ): string {
		$page = (int) Settings::get( 'summary_page_id', 0 );
		if ( ! $page ) {
			return '';
		}

		return add_query_arg(
			array(
				'plugixa_carparking_ref'   => rawurlencode( $ref ),
				'plugixa_carparking_token' => self::token( $ref ),
			),
			get_permalink( $page )
		);
	}

	/**
	 * Everything the invoice template renders.
	 *
	 * @param array<string, mixed> $booking The booking row.
	 *
	 * @return array<string, mixed>
	 */
	public function data( array $booking ): array {
		$location = ! empty( $booking['location_id'] ) ? $this->locations->find( (int) $booking['location_id'], true ) : null;
		$type     = ! empty( $booking['space_type_id'] ) ? $this->space_types->find( (int) $booking['space_type_id'], true ) : null;

		$total = (float) ( $booking['total'] ?? 0 );
		$paid  = (float) ( $booking['amount_paid'] ?? 0 );

		return array(
			'company'         => Settings::get( 'plugin_name', get_bloginfo( 'name' ) ),
			'currency_symbol' => Settings::get( 'currency_symbol', $booking['currency'] ?? '$' ),
			'booking'         => $booking,
			'items'           => $this->items->for_booking( (int) $booking['id'] ),
			// What the customer answered in the form's own fields: each a label and a value.
			'custom_fields'   => $this->answers->rows( $booking ),
			'location_name'   => $location['name'] ?? '',
			'space_type_name' => $type['name'] ?? '',
			'total'           => $total,
			'paid'            => $paid,
			'balance'         => max( 0, $total - $paid ),
		);
	}

	/**
	 * An amount as the site writes money, the same way the booking form writes it.
	 *
	 * @param float                $amount The amount.
	 * @param array<string, mixed> $d      The data() array, for the symbol.
	 */
	public function money( float $amount, array $d ): string {
		return Money::format( $amount, isset( $d['currency_symbol'] ) ? (string) $d['currency_symbol'] : null );
	}

	/**
	 * One adjustment to a line's price, as the line under it reads: "−€8.00 Long stay".
	 *
	 * @param array<string, mixed> $adjustment label, amount.
	 * @param array<string, mixed> $d          The data() array, for the symbol.
	 */
	public function adjustment( array $adjustment, array $d ): string {
		return trim(
			Money::signed( (float) ( $adjustment['amount'] ?? 0 ), isset( $d['currency_symbol'] ) ? (string) $d['currency_symbol'] : null )
			. ' ' . (string) ( $adjustment['label'] ?? '' )
		);
	}

	/**
	 * The invoice as HTML: the on-page summary, and the source of any other rendering of it.
	 *
	 * @param array<string, mixed> $booking The booking row.
	 */
	public function html( array $booking ): string {
		// The template reads $invoice, and calls $invoice['engine']->money().
		$invoice           = $this->data( $booking );
		$invoice['engine'] = $this;

		$template = PLUGIXA_CARPARKING_PATH . 'templates/invoice.php';
		if ( ! file_exists( $template ) ) {
			return '<p>Invoice template missing.</p>';
		}

		ob_start();
		include $template;

		return (string) ob_get_clean();
	}
}
