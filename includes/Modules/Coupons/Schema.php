<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Modules\Coupons;

use PlugixaCarParking\Core\Database\Schema as Db;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The coupons table and the log of redemptions.
 */
final class Schema {

	/**
	 * @return string[]
	 */
	public static function tables(): array {
		return array( 'coupons', 'coupon_usage' );
	}

	/**
	 * @return array<string, string> What dbDelta changed. Empty on a settled schema.
	 */
	public static function install(): array {
		$charset = Db::charset();
		$changes = Db::delta( self::coupons( $charset ), self::coupon_usage( $charset ) );

		Db::ensure_innodb( ...self::tables() );
		Db::backfill_modified_at( 'coupons' );

		return $changes;
	}

	/**
	 * A discount code.
	 *
	 * `code` is unique across live AND trashed coupons: the database key cannot tell them apart,
	 * so a trashed code is not free to reuse until it is purged.
	 *
	 * `valid_from` and `valid_to` are the site's wall-clock time, like a booking's entry and exit.
	 *
	 * @param string $charset Engine and charset clause.
	 */
	private static function coupons( string $charset ): string {
		$table = Db::table( 'coupons' );

		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL auto_increment,
			code varchar(60) NOT NULL,
			type varchar(20) NOT NULL default 'percent',
			amount decimal(12,4) NOT NULL default 0,
			usage_limit int(10) unsigned default NULL,
			used_count int(10) unsigned NOT NULL default 0,
			usage_limit_per_user int(10) unsigned default NULL,
			min_amount decimal(12,4) default NULL,
			valid_from datetime default NULL,
			valid_to datetime default NULL,
			applies_to longtext,
			period_type varchar(20) NOT NULL default 'both',
			status varchar(20) NOT NULL default 'active',
			created_by bigint(20) unsigned default NULL,
			modified_by bigint(20) unsigned default NULL,
			created_at datetime default NULL,
			modified_at datetime default NULL,
			deleted_at datetime default NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY code (code),
			KEY status (status),
			KEY deleted_at (deleted_at)
		) {$charset};";
	}

	/**
	 * One row per redemption. A log: appended to, never edited.
	 *
	 * @param string $charset Engine and charset clause.
	 */
	private static function coupon_usage( string $charset ): string {
		$table = Db::table( 'coupon_usage' );

		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL auto_increment,
			coupon_id bigint(20) unsigned NOT NULL,
			booking_id bigint(20) unsigned NOT NULL,
			user_id bigint(20) unsigned default NULL,
			amount decimal(12,4) NOT NULL default 0,
			used_at datetime default NULL,
			PRIMARY KEY  (id),
			KEY coupon_booking (coupon_id,booking_id)
		) {$charset};";
	}
}
