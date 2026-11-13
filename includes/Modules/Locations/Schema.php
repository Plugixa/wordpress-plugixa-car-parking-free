<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Modules\Locations;

use PlugixaCarParking\Core\Database\Schema as Db;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The locations table, with each location's weekly hours and dated exceptions.
 */
final class Schema {

	/**
	 * @return string[]
	 */
	public static function tables(): array {
		return array( 'locations', 'location_hours', 'location_exceptions' );
	}

	/**
	 * @return array<string, string> What dbDelta changed. Empty on a settled schema.
	 */
	public static function install(): array {
		$charset = Db::charset();
		$changes = Db::delta(
			self::locations( $charset ),
			self::location_hours( $charset ),
			self::location_exceptions( $charset )
		);

		Db::ensure_innodb( ...self::tables() );
		Db::backfill_modified_at( 'locations', true );

		return $changes;
	}

	/**
	 * A car park.
	 *
	 * `inventory_mode` says how its spaces are counted and `default_billing_type` what unit a
	 * stay is charged in when the booking does not say.
	 *
	 * `updated_at` is version 1's "last changed" column. It stays because existing rows carry it;
	 * `modified_at` is the one the record layer reads.
	 *
	 * @param string $charset Engine and charset clause.
	 */
	private static function locations( string $charset ): string {
		$table = Db::table( 'locations' );

		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL auto_increment,
			language_code varchar(10) default NULL,
			translation_group varchar(36) default NULL,
			name varchar(255) NOT NULL,
			slug varchar(191) NOT NULL,
			description longtext,
			status varchar(20) NOT NULL default 'active',
			inventory_mode varchar(20) NOT NULL default 'capacity',
			default_billing_type varchar(20) NOT NULL default 'day',
			currency varchar(10) default NULL,
			timezone varchar(64) default NULL,
			address_line1 varchar(255) default NULL,
			address_line2 varchar(255) default NULL,
			city varchar(120) default NULL,
			state varchar(120) default NULL,
			postcode varchar(30) default NULL,
			country varchar(2) default NULL,
			latitude decimal(10,7) default NULL,
			longitude decimal(10,7) default NULL,
			phone varchar(50) default NULL,
			email varchar(190) default NULL,
			image_id bigint(20) unsigned default NULL,
			gallery longtext,
			settings longtext,
			display_order int(11) NOT NULL default 0,
			created_by bigint(20) unsigned default NULL,
			modified_by bigint(20) unsigned default NULL,
			created_at datetime NOT NULL,
			updated_at datetime default NULL,
			modified_at datetime default NULL,
			deleted_at datetime default NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug),
			KEY status (status),
			KEY display_order (display_order),
			KEY language_code (language_code),
			KEY translation_group (translation_group),
			KEY deleted_at (deleted_at)
		) {$charset};";
	}

	/**
	 * Opening hours, one row per weekday (0 = Sunday).
	 *
	 * A location with no rows at all is open around the clock. The seven rows are replaced
	 * together, so they carry no audit columns of their own.
	 *
	 * @param string $charset Engine and charset clause.
	 */
	private static function location_hours( string $charset ): string {
		$table = Db::table( 'location_hours' );

		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL auto_increment,
			location_id bigint(20) unsigned NOT NULL,
			day_of_week tinyint(1) NOT NULL,
			open_time time default NULL,
			close_time time default NULL,
			is_closed tinyint(1) NOT NULL default 0,
			PRIMARY KEY  (id),
			KEY location_day (location_id,day_of_week)
		) {$charset};";
	}

	/**
	 * A date range on which the weekly hours do not apply: closed, or open at special times.
	 *
	 * @param string $charset Engine and charset clause.
	 */
	private static function location_exceptions( string $charset ): string {
		$table = Db::table( 'location_exceptions' );

		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL auto_increment,
			location_id bigint(20) unsigned NOT NULL,
			type varchar(20) NOT NULL default 'closed',
			start_date date NOT NULL,
			end_date date NOT NULL,
			open_time time default NULL,
			close_time time default NULL,
			note varchar(255) default NULL,
			PRIMARY KEY  (id),
			KEY location_range (location_id,start_date,end_date)
		) {$charset};";
	}
}
