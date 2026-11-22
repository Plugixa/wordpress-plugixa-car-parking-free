<?php
/**
 * The lowest price a booking form can quote at a location, for its first step.
 *
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Modules\Frontend;

use PlugixaCarParking\Core\Money;
use PlugixaCarParking\Modules\Bookings\Services\Offer;
use PlugixaCarParking\Modules\Bookings\Services\Pricing;
use PlugixaCarParking\Modules\SpaceTypes\Repositories\PriceTable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "From $8.00 / day" beside the first step's button: a customer sees a price before the first
 * click, where they used to see one only after "Check availability".
 *
 * The lowest rate among the space types the form offers at the location, in the unit the
 * location bills by. The form's own limit counts: a form for EV bays only must not quote the
 * cheaper standard bay it never sells.
 */
final class FromPrice {

	public function __construct(
		private readonly Offer $offer,
		private readonly PriceTable $prices
	) {}

	/**
	 * @param array<string, mixed> $location The location row.
	 * @param array<string, mixed> $form     The form row, or empty for none.
	 *
	 * @return string The line to show, or '' when nothing at the location has a price.
	 */
	public function label( array $location, array $form = array() ): string {
		$unit     = (string) ( $location['default_billing_type'] ?? 'day' );
		$use_type = Pricing::USE_TYPE_MAP[ $unit ] ?? 'rental_day';

		$lowest = null;
		foreach ( $this->offer->space_type_ids( $location, $form ) as $type_id ) {
			$rate = $this->prices->get_rate( (int) $type_id, (int) $location['id'], $use_type );
			if ( null !== $rate && $rate > 0 && ( null === $lowest || $rate < $lowest ) ) {
				$lowest = $rate;
			}
		}

		if ( null === $lowest ) {
			return '';
		}

		$price = Money::format( $lowest );

		switch ( $unit ) {
			case 'minute':
				/* translators: %s: a price. */
				return sprintf( __( 'from %s / minute', 'plugixa-car-parking' ), $price );
			case 'hour':
				/* translators: %s: a price. */
				return sprintf( __( 'from %s / hour', 'plugixa-car-parking' ), $price );
			case 'week':
				/* translators: %s: a price. */
				return sprintf( __( 'from %s / week', 'plugixa-car-parking' ), $price );
			case 'month':
				/* translators: %s: a price. */
				return sprintf( __( 'from %s / month', 'plugixa-car-parking' ), $price );
			default:
				/* translators: %s: a price. */
				return sprintf( __( 'from %s / day', 'plugixa-car-parking' ), $price );
		}
	}
}
