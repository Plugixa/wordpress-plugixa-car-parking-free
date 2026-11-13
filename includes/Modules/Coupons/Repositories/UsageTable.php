<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Modules\Coupons\Repositories;

use PlugixaCarParking\Core\Database\Schema as Db;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The log of coupon redemptions. Appended to, never edited.
 */
final class UsageTable {

	/**
	 * Record one redemption.
	 *
	 * @param int   $coupon_id  The coupon.
	 * @param int   $booking_id The booking it was used on.
	 * @param int   $user_id    The signed-in customer, or 0 for a guest.
	 * @param float $amount     The discount it gave.
	 *
	 * @return int The new row id, or 0 on failure.
	 */
	public function log( int $coupon_id, int $booking_id, int $user_id, float $amount ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom table.
		$ok = $wpdb->insert(
			Db::table( 'coupon_usage' ),
			array(
				'coupon_id'  => $coupon_id,
				'booking_id' => $booking_id,
				'user_id'    => $user_id > 0 ? $user_id : null,
				'amount'     => $amount,
				'used_at'    => current_time( 'mysql' ),
			)
		);

		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * How many times one account has redeemed a coupon.
	 *
	 * A guest is logged with no user and cannot be told apart from any other guest, so a
	 * per-account limit only ever binds a signed-in customer.
	 *
	 * @param int $coupon_id The coupon.
	 * @param int $user_id   The WordPress user.
	 */
	public function count_for_user( int $coupon_id, int $user_id ): int {
		if ( $user_id <= 0 ) {
			return 0;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a usage limit must count the redemption made a moment ago.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE coupon_id = %d AND user_id = %d',
				Db::table( 'coupon_usage' ),
				$coupon_id,
				$user_id
			)
		);
	}
}
