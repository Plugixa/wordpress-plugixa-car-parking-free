<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Modules\Bookings\Repositories;

use PlugixaCarParking\Core\Database\Schema as Db;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A booking's line items.
 *
 * Not a Repository: the lines are the record of the quote a customer accepted. They are written
 * once with the booking and are not edited or trashed on their own.
 *
 * A line whose price something adjusted keeps what it cost before and what adjusted it, in the
 * `meta` column as JSON. They are read back under the same three keys the quote carries them
 * under, so a stored line and a quoted line are drawn by the same code.
 */
final class ItemTable {

	/**
	 * What a quoted line says about a change to its price. See Quote::with_adjustments().
	 *
	 * @var string[]
	 */
	public const DETAIL_KEYS = array( 'original_unit_price', 'original_amount', 'adjustments' );

	/**
	 * The `meta` value that keeps a quoted line's adjustments, or null for a line that has none.
	 *
	 * @param array<string, mixed> $line A line of a quote.
	 */
	public static function details( array $line ): ?string {
		if ( ! is_array( $line['adjustments'] ?? null ) || array() === $line['adjustments'] ) {
			return null;
		}

		$json = wp_json_encode( array_intersect_key( $line, array_flip( self::DETAIL_KEYS ) ) );

		return is_string( $json ) ? $json : null;
	}

	/**
	 * Add one line to a booking.
	 *
	 * @param array<string, mixed> $item booking_id, type, reference_id, label, quantity, unit_price, total, meta.
	 *
	 * @return int The new row id, or 0 on failure.
	 */
	public function insert( array $item ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom table.
		$ok = $wpdb->insert( Db::table( 'booking_items' ), $item );

		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * A booking's lines, in the order they were priced.
	 *
	 * @param int $booking_id The booking.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function for_booking( int $booking_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i WHERE booking_id = %d ORDER BY id ASC', Db::table( 'booking_items' ), $booking_id ),
			ARRAY_A
		);

		return array_map( array( self::class, 'with_details' ), (array) $rows );
	}

	/**
	 * A stored line with its adjustments back under the keys a quoted line has them under.
	 *
	 * A line written before adjustments were kept has an empty `meta`, and is returned without the
	 * three keys: nothing is known about it, which is not the same as nothing having changed.
	 *
	 * @param array<string, mixed> $row A stored line.
	 *
	 * @return array<string, mixed>
	 */
	private static function with_details( array $row ): array {
		$meta = is_string( $row['meta'] ?? null ) && '' !== $row['meta'] ? json_decode( $row['meta'], true ) : null;

		if ( ! is_array( $meta ) || ! is_array( $meta['adjustments'] ?? null ) ) {
			return $row;
		}

		$adjustments = array();

		foreach ( $meta['adjustments'] as $adjustment ) {
			if ( is_array( $adjustment ) && is_numeric( $adjustment['amount'] ?? null ) ) {
				$adjustments[] = array(
					'label'  => is_scalar( $adjustment['label'] ?? null ) ? (string) $adjustment['label'] : '',
					'amount' => (float) $adjustment['amount'],
				);
			}
		}

		if ( array() === $adjustments ) {
			return $row;
		}

		$row['original_unit_price'] = (float) ( $meta['original_unit_price'] ?? 0 );
		$row['original_amount']     = (float) ( $meta['original_amount'] ?? 0 );
		$row['adjustments']         = $adjustments;

		return $row;
	}

	/**
	 * Remove every line of a booking, before it is repriced or destroyed.
	 *
	 * @param int $booking_id The booking.
	 */
	public function delete_for_booking( int $booking_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to invalidate.
		$wpdb->delete( Db::table( 'booking_items' ), array( 'booking_id' => $booking_id ) );
	}
}
