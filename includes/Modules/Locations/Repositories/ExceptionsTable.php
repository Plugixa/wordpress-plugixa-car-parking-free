<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Modules\Locations\Repositories;

use PlugixaCarParking\Core\Database\Schema as Db;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Date ranges on which a location's weekly hours do not apply: closed, or open at special times.
 *
 * Not a Repository: an exception belongs to its location, is edited on that location's screen,
 * and is simply removed when it no longer applies.
 */
final class ExceptionsTable {

	/**
	 * Every exception for a location, soonest first.
	 *
	 * @param int $location_id The location.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function for_location( int $location_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE location_id = %d ORDER BY start_date ASC',
				Db::table( 'location_exceptions' ),
				$location_id
			),
			ARRAY_A
		);

		return (array) $rows;
	}

	/**
	 * One exception, provided it belongs to the given location.
	 *
	 * The location is part of the lookup, not a check made afterwards: an exception is only ever
	 * reached through its location's route, and one that belongs elsewhere must read as missing.
	 *
	 * @param int $location_id The location.
	 * @param int $id          The exception.
	 *
	 * @return array<string, mixed>|null
	 */
	public function find( int $location_id, int $id ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE id = %d AND location_id = %d',
				Db::table( 'location_exceptions' ),
				$id,
				$location_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Add an exception.
	 *
	 * @param array<string, mixed> $data location_id, type, start_date, end_date, open_time, close_time, note.
	 *
	 * @return int The new row id, or 0 on failure.
	 */
	public function insert( array $data ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom table.
		$ok = $wpdb->insert( Db::table( 'location_exceptions' ), $data );

		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Change an exception.
	 *
	 * @param int                  $id   The exception.
	 * @param array<string, mixed> $data Columns to change.
	 */
	public function update( int $id, array $data ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to invalidate.
		return false !== $wpdb->update( Db::table( 'location_exceptions' ), $data, array( 'id' => $id ) );
	}

	/**
	 * Remove an exception.
	 *
	 * @param int $id The exception.
	 */
	public function delete( int $id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to invalidate.
		return (bool) $wpdb->delete( Db::table( 'location_exceptions' ), array( 'id' => $id ) );
	}

	/**
	 * The exceptions whose range covers a date, a closure before any special hours.
	 *
	 * @param int    $location_id The location.
	 * @param string $date        The date, Y-m-d.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function covering( int $location_id, string $date ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- closures decide whether a booking is accepted; a cached answer could admit one on a closed day.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE location_id = %d AND start_date <= %s AND end_date >= %s ORDER BY (type = 'closed') DESC",
				Db::table( 'location_exceptions' ),
				$location_id,
				$date,
				$date
			),
			ARRAY_A
		);

		return (array) $rows;
	}
}
