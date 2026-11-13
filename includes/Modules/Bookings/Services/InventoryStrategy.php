<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Modules\Bookings\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One way of counting a location's spaces.
 *
 * A location's `inventory_mode` names the strategy that answers for it. The plugin ships one,
 * for a pool of interchangeable spaces; an add-on registers others on
 * `plugixa_carparking_inventory_strategies`.
 */
interface InventoryStrategy {

	/**
	 * What is free of one space type at a location in a window.
	 *
	 * @param int      $location_id   The location.
	 * @param int      $space_type_id The space type.
	 * @param string   $entry         Requested entry, Y-m-d H:i:s.
	 * @param string   $exit          Requested exit, Y-m-d H:i:s.
	 * @param string[] $hold_statuses Booking statuses that consume inventory.
	 *
	 * @return array<string, mixed> At least: space_type_id, mode, total, available.
	 */
	public function availability( int $location_id, int $space_type_id, string $entry, string $exit, array $hold_statuses ): array;

	/**
	 * Whether one request can be fulfilled right now.
	 *
	 * @param int      $location_id   The location.
	 * @param int      $space_type_id The space type.
	 * @param string   $entry         Requested entry.
	 * @param string   $exit          Requested exit.
	 * @param int      $quantity      Spaces wanted.
	 * @param int|null $space_id      A particular space, where the strategy has such a thing.
	 * @param int|null $exclude_id    A booking to ignore, e.g. the one being rescheduled.
	 * @param string[] $hold_statuses Booking statuses that consume inventory.
	 */
	public function can_book( int $location_id, int $space_type_id, string $entry, string $exit, int $quantity, ?int $space_id, ?int $exclude_id, array $hold_statuses ): bool;

	/**
	 * Whether this strategy holds a booking to one particular space.
	 *
	 * A space can be marked as individually identified only where some strategy answers yes.
	 * Under any other, the row is counted as part of a pool and the mark would mean nothing.
	 */
	public function identifies_spaces(): bool;

	/**
	 * The particular space a booking is held to.
	 *
	 * A strategy that counts a pool answers null: its bookings hold no particular space. One that
	 * identifies spaces answers the space requested when it is one of its own and free, or picks a
	 * free one when none was requested, and answers 0 when it can do neither.
	 *
	 * @param int      $location_id   The location.
	 * @param int      $space_type_id The space type.
	 * @param string   $entry         Requested entry.
	 * @param string   $exit          Requested exit.
	 * @param int|null $requested     The space the customer chose, if any.
	 * @param int|null $exclude_id    A booking to ignore, e.g. the one being moved.
	 * @param string[] $hold_statuses Booking statuses that consume inventory.
	 */
	public function space_for( int $location_id, int $space_type_id, string $entry, string $exit, ?int $requested, ?int $exclude_id, array $hold_statuses ): ?int;
}
