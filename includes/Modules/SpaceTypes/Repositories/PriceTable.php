<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Modules\SpaceTypes\Repositories;

use PlugixaCarParking\Core\Database\Schema as Db;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The base prices of each space type, per unit of time.
 *
 * Not a Repository: a type's prices are one value, replaced together.
 */
final class PriceTable {

	/**
	 * The most specific rate for a space type and unit.
	 *
	 * A price set for the location wins over the global one (no location). Where several rows
	 * match, the newest is the one in force. Null when no price is configured at all.
	 *
	 * @param int      $space_type_id The space type.
	 * @param int|null $location_id   The location, or null to read only the global price.
	 * @param string   $use_type      The unit: `rental_day`, `rental_hour`, … or `initial`.
	 */
	public function get_rate( int $space_type_id, ?int $location_id, string $use_type ): ?float {
		global $wpdb;

		$table = Db::table( 'space_type_prices' );

		if ( null !== $location_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a price is read to charge a customer; it must be the one in force now.
			$amount = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT amount FROM %i WHERE space_type_id = %d AND use_type = %s AND location_id = %d ORDER BY id DESC LIMIT 1',
					$table,
					$space_type_id,
					$use_type,
					$location_id
				)
			);

			if ( null !== $amount ) {
				return (float) $amount;
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a price is read to charge a customer; it must be the one in force now.
		$amount = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT amount FROM %i WHERE space_type_id = %d AND use_type = %s AND location_id IS NULL ORDER BY id DESC LIMIT 1',
				$table,
				$space_type_id,
				$use_type
			)
		);

		return null === $amount ? null : (float) $amount;
	}

	/**
	 * Every price row of a space type, oldest first.
	 *
	 * @param int $space_type_id The space type.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function for_space_type( int $space_type_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i WHERE space_type_id = %d ORDER BY id ASC', Db::table( 'space_type_prices' ), $space_type_id ),
			ARRAY_A
		);

		return (array) $rows;
	}

	/**
	 * Replace every price of a space type with a new set.
	 *
	 * One transaction, so a write that fails part-way leaves the old prices in force. Without it
	 * a type could be left with half its prices, or none, and a booking priced in that moment
	 * would be charged nothing.
	 *
	 * @param int                              $space_type_id The space type.
	 * @param array<int, array<string, mixed>> $rows          Each: use_type, amount, location_id or null, currency or null.
	 *
	 * @return bool False when a write failed and the old prices were kept.
	 */
	public function replace( int $space_type_id, array $rows ): bool {
		global $wpdb;

		$table = Db::table( 'space_type_prices' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- transaction control.
		$wpdb->query( 'START TRANSACTION' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to invalidate.
		$saved = false !== $wpdb->delete( $table, array( 'space_type_id' => $space_type_id ) );

		foreach ( $rows as $row ) {
			if ( ! $saved ) {
				break;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom table.
			$saved = false !== $wpdb->insert(
				$table,
				array(
					'space_type_id' => $space_type_id,
					'location_id'   => isset( $row['location_id'] ) && (int) $row['location_id'] > 0 ? (int) $row['location_id'] : null,
					'use_type'      => (string) $row['use_type'],
					'amount'        => (float) $row['amount'],
					'currency'      => isset( $row['currency'] ) && '' !== $row['currency'] ? (string) $row['currency'] : null,
				)
			);
		}

		if ( ! $saved ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- transaction control.
			$wpdb->query( 'ROLLBACK' );

			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- transaction control.
		$wpdb->query( 'COMMIT' );

		return true;
	}
}
