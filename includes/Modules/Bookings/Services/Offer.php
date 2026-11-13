<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Modules\Bookings\Services;

use PlugixaCarParking\Modules\Forms\Services\FormScope;
use PlugixaCarParking\Modules\Locations\Repositories\LocationRepository;
use PlugixaCarParking\Modules\Spaces\Repositories\SpaceRepository;
use PlugixaCarParking\Modules\SpaceTypes\Repositories\PriceTable;
use PlugixaCarParking\Modules\SpaceTypes\Repositories\SpaceTypeRepository;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What a customer may book: the one rule the booking page, its three routes and the admin's
 * set-up warnings all read.
 *
 * A space type is offered at a location when all of these hold:
 * - the location is active;
 * - the type is active and the location has an active spaces row of it;
 * - the type has a price for the location's billing unit (a price of 0 counts; no price does not);
 * - the form the customer is on books that location and that type.
 *
 * A missing price used to be read as 0, so a type nobody had priced was given away. It is now
 * not offered, and the admin is told why on the location's Booking setup panel.
 */
final class Offer {

	public function __construct(
		private readonly LocationRepository $locations,
		private readonly SpaceTypeRepository $space_types,
		private readonly SpaceRepository $spaces,
		private readonly PriceTable $prices
	) {}

	/**
	 * The space types a form offers at a location, in no particular order.
	 *
	 * @param array<string, mixed> $location The location row.
	 * @param array<string, mixed> $form     The form row, or empty for none.
	 *
	 * @return int[]
	 */
	public function space_type_ids( array $location, array $form = array() ): array {
		$offered = array();

		foreach ( $this->spaces->space_type_ids( (int) $location['id'] ) as $type_id ) {
			if ( FormScope::allows_space_type( $form, $type_id ) && $this->type_is_active( $type_id ) && $this->priced( $type_id, $location ) ) {
				$offered[] = $type_id;
			}
		}

		return $offered;
	}

	/**
	 * Whether a space type has a price for the unit a location bills by.
	 *
	 * @param int                  $space_type_id The space type.
	 * @param array<string, mixed> $location      The location row.
	 */
	public function priced( int $space_type_id, array $location ): bool {
		$unit = (string) ( $location['default_billing_type'] ?? 'day' );

		return null !== $this->prices->get_rate( $space_type_id, (int) $location['id'], Pricing::USE_TYPE_MAP[ $unit ] ?? 'rental_day' );
	}

	/**
	 * The location a public request may book at, or why not.
	 *
	 * @param array<string, mixed> $form        The form row, or empty for none.
	 * @param int                  $location_id The location asked for.
	 *
	 * @return array<string, mixed>|WP_Error The location row.
	 */
	public function location( array $form, int $location_id ): array|WP_Error {
		$location = $location_id > 0 ? $this->locations->find( $location_id ) : null;

		if ( null === $location || 'active' !== ( $location['status'] ?? '' ) || ! FormScope::allows_location( $form, $location_id ) ) {
			return new WP_Error(
				'location_unavailable',
				__( 'That location cannot be booked on this form.', 'plugixa-car-parking' ),
				array( 'status' => 422 )
			);
		}

		return $location;
	}

	/**
	 * Refuse a space type the form does not offer at the location, saying why.
	 *
	 * @param array<string, mixed> $form          The form row, or empty for none.
	 * @param array<string, mixed> $location      The location row.
	 * @param int                  $space_type_id The space type asked for.
	 */
	public function refuse_space_type( array $form, array $location, int $space_type_id ): ?WP_Error {
		$stocked = in_array( $space_type_id, $this->spaces->space_type_ids( (int) $location['id'] ), true );

		if ( ! $stocked || ! $this->type_is_active( $space_type_id ) || ! FormScope::allows_space_type( $form, $space_type_id ) ) {
			return new WP_Error(
				'type_unavailable',
				__( 'That kind of space cannot be booked here.', 'plugixa-car-parking' ),
				array( 'status' => 422 )
			);
		}

		if ( ! $this->priced( $space_type_id, $location ) ) {
			return new WP_Error(
				'no_price',
				__( 'That kind of space has no price here yet, so it cannot be booked.', 'plugixa-car-parking' ),
				array( 'status' => 422 )
			);
		}

		return null;
	}

	/**
	 * @param int $space_type_id The space type.
	 */
	private function type_is_active( int $space_type_id ): bool {
		$type = $this->space_types->find( $space_type_id );

		return null !== $type && 'active' === ( $type['status'] ?? '' );
	}
}
