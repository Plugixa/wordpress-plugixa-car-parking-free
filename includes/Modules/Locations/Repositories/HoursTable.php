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
 * A location's weekly opening hours (day_of_week 0 = Sunday … 6 = Saturday).
 *
 * Not a Repository: the seven rows are one value, replaced together, with no trash and no
 * history of their own.
 */
final class HoursTable {

	/**
	 * Every weekday row for a location, Sunday first.
	 *
	 * @param int $location_id The location.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function for_location( int $location_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- opening hours decide whether a booking is accepted; a cached answer could admit one outside them.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE location_id = %d ORDER BY day_of_week ASC',
				Db::table( 'location_hours' ),
				$location_id
			),
			ARRAY_A
		);

		return (array) $rows;
	}

	/**
	 * The same rows keyed by weekday, for lookup.
	 *
	 * @param int $location_id The location.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function map( int $location_id ): array {
		$map = array();

		foreach ( $this->for_location( $location_id ) as $row ) {
			$map[ (int) $row['day_of_week'] ] = $row;
		}

		return $map;
	}

	/**
	 * Replace the whole week for a location.
	 *
	 * @param int                              $location_id The location.
	 * @param array<int, array<string, mixed>> $rows        Each: day_of_week, open_time, close_time, is_closed.
	 */
	public function replace( int $location_id, array $rows ): void {
		global $wpdb;

		$table = Db::table( 'location_hours' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to invalidate.
		$wpdb->delete( $table, array( 'location_id' => $location_id ) );

		foreach ( $rows as $row ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom table.
			$wpdb->insert(
				$table,
				array(
					'location_id' => $location_id,
					'day_of_week' => (int) $row['day_of_week'],
					'open_time'   => ! empty( $row['open_time'] ) ? $row['open_time'] : null,
					'close_time'  => ! empty( $row['close_time'] ) ? $row['close_time'] : null,
					'is_closed'   => ! empty( $row['is_closed'] ) ? 1 : 0,
				)
			);
		}
	}
}
