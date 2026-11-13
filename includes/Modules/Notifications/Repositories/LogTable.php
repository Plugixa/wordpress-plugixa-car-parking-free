<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Modules\Notifications\Repositories;

use PlugixaCarParking\Core\Database\Schema as Db;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The log of messages sent about bookings. Appended to, never edited.
 */
final class LogTable {

	/**
	 * Record one delivery attempt.
	 *
	 * @param int    $booking_id The booking the message was about.
	 * @param string $channel    How it was sent: `email`, or a channel an add-on provides.
	 * @param string $recipient  Who it was sent to.
	 * @param string $status     `sent` or `failed`.
	 * @param string $event      What prompted it. Accepted for callers; the table has no column for it.
	 * @param string $error      Why it failed, when it did.
	 *
	 * @return int The new row id, or 0 on failure.
	 */
	public function record( int $booking_id, string $channel, string $recipient, string $status, string $event = '', string $error = '' ): int {
		unset( $event );

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom table.
		$ok = $wpdb->insert(
			Db::table( 'notification_log' ),
			array(
				'booking_id' => $booking_id,
				'channel'    => $channel,
				'recipient'  => '' !== $recipient ? $recipient : null,
				'status'     => $status,
				'error'      => '' !== $error ? $error : null,
				'sent_at'    => current_time( 'mysql' ),
			)
		);

		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * One page of the log, newest first, with how many rows there are in all.
	 *
	 * Ordered by id because rows are only ever appended, so the id is the order they were written
	 * in, and unlike `sent_at` no two rows share one.
	 *
	 * @param int|null $booking_id Only this booking's messages, or null for every booking's.
	 * @param int      $limit      Rows to return.
	 * @param int      $offset     Rows to skip.
	 *
	 * @return array{rows: array<int, array<string, mixed>>, total: int}
	 */
	public function page( ?int $booking_id, int $limit, int $offset ): array {
		global $wpdb;

		$table = Db::table( 'notification_log' );

		if ( null === $booking_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT %d OFFSET %d', $table, $limit, $offset ), ARRAY_A );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
			$total = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE booking_id = %d ORDER BY id DESC LIMIT %d OFFSET %d', $table, $booking_id, $limit, $offset ), ARRAY_A );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
			$total = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE booking_id = %d', $table, $booking_id ) );
		}

		return array(
			'rows'  => (array) $rows,
			'total' => (int) $total,
		);
	}

	/**
	 * Remove a booking's log once the booking itself has been destroyed.
	 *
	 * @param int $booking_id The destroyed booking.
	 */
	public function delete_for_booking( int $booking_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to invalidate.
		$wpdb->delete( Db::table( 'notification_log' ), array( 'booking_id' => $booking_id ) );
	}

	/**
	 * Whether a channel has already been logged as sent for a booking.
	 *
	 * How a scheduled job avoids sending the same thing twice: it records a marker channel once
	 * it has sent, and asks here before sending again.
	 *
	 * @param int    $booking_id The booking.
	 * @param string $channel    The channel, or marker, to look for.
	 */
	public function already_sent( int $booking_id, string $channel ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the de-duplication check; a cached miss would send the same message twice.
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i WHERE booking_id = %d AND channel = %s AND status = 'sent'",
				Db::table( 'notification_log' ),
				$booking_id,
				$channel
			)
		);

		return (int) $count > 0;
	}
}
