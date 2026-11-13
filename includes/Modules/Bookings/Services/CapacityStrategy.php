<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Modules\Bookings\Services;

use PlugixaCarParking\Modules\Bookings\Repositories\BookingRepository;
use PlugixaCarParking\Modules\Spaces\Repositories\SpaceRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A pool of interchangeable spaces: what is free is the total less what is held.
 */
final class CapacityStrategy implements InventoryStrategy {

	public const MODE = 'capacity';

	public function __construct(
		private readonly SpaceRepository $spaces,
		private readonly BookingRepository $bookings
	) {}

	/**
	 * @return array<string, mixed>
	 */
	public function availability( int $location_id, int $space_type_id, string $entry, string $exit, array $hold_statuses ): array {
		$total = $this->spaces->total_capacity( $location_id, $space_type_id );
		$held  = $this->bookings->held_quantity( $location_id, $entry, $exit, $hold_statuses, $space_type_id );

		return array(
			'space_type_id' => $space_type_id,
			'mode'          => self::MODE,
			'total'         => $total,
			'available'     => max( 0, $total - $held ),
		);
	}

	public function can_book( int $location_id, int $space_type_id, string $entry, string $exit, int $quantity, ?int $space_id, ?int $exclude_id, array $hold_statuses ): bool {
		unset( $space_id );

		$total = $this->spaces->total_capacity( $location_id, $space_type_id );
		$held  = $this->bookings->held_quantity( $location_id, $entry, $exit, $hold_statuses, $space_type_id, null, $exclude_id );

		return ( $total - $held ) >= max( 1, $quantity );
	}

	public function identifies_spaces(): bool {
		return false;
	}

	/**
	 * A pool holds no particular space, whatever the request named.
	 */
	public function space_for( int $location_id, int $space_type_id, string $entry, string $exit, ?int $requested, ?int $exclude_id, array $hold_statuses ): ?int {
		unset( $location_id, $space_type_id, $entry, $exit, $requested, $exclude_id, $hold_statuses );

		return null;
	}
}
