<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Modules\SpaceTypes;

use PlugixaCarParking\Core\Database\Schema as Db;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The space_types table and the base prices of each type.
 */
final class Schema {

	/**
	 * @return string[]
	 */
	public static function tables(): array {
		return array( 'space_types', 'space_type_prices' );
	}

	/**
	 * @return array<string, string> What dbDelta changed. Empty on a settled schema.
	 */
	public static function install(): array {
		$charset = Db::charset();
		$changes = Db::delta( self::space_types( $charset ), self::space_type_prices( $charset ) );

		Db::ensure_innodb( ...self::tables() );
		Db::backfill_modified_at( 'space_types' );

		return $changes;
	}

	/**
	 * A kind of parking space: standard, covered, electric and so on.
	 *
	 * Global rather than per location. A location says how many of each it has; what a "covered"
	 * space is does not change from one car park to the next.
	 *
	 * @param string $charset Engine and charset clause.
	 */
	private static function space_types( string $charset ): string {
		$table = Db::table( 'space_types' );

		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL auto_increment,
			language_code varchar(10) default NULL,
			translation_group varchar(36) default NULL,
			name varchar(255) NOT NULL,
			slug varchar(191) NOT NULL,
			description text,
			icon varchar(100) default NULL,
			color varchar(20) default NULL,
			width decimal(8,2) default NULL,
			length decimal(8,2) default NULL,
			dimension_unit varchar(10) default 'm',
			features longtext,
			display_order int(11) NOT NULL default 0,
			status varchar(20) NOT NULL default 'active',
			created_by bigint(20) unsigned default NULL,
			modified_by bigint(20) unsigned default NULL,
			created_at datetime default NULL,
			modified_at datetime default NULL,
			deleted_at datetime default NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug),
			KEY display_order (display_order),
			KEY status (status),
			KEY deleted_at (deleted_at)
		) {$charset};";
	}

	/**
	 * What one space of a type costs, per unit of time.
	 *
	 * `use_type` names the unit (`rental_day`, `rental_hour`, …) or `initial` for a one-off fee.
	 * A row with a `location_id` overrides the row without one, so a site can charge more at one
	 * car park without restating every price.
	 *
	 * NO UNIQUE KEY, DELIBERATELY. `location_id` is nullable and MySQL treats every NULL as
	 * distinct, so a key on (space_type_id, location_id, use_type) would not stop two global rows
	 * for the same unit. Pricing reads the newest matching row instead, and the service that
	 * writes prices replaces the whole set for a type.
	 *
	 * @param string $charset Engine and charset clause.
	 */
	private static function space_type_prices( string $charset ): string {
		$table = Db::table( 'space_type_prices' );

		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL auto_increment,
			space_type_id bigint(20) unsigned NOT NULL,
			location_id bigint(20) unsigned default NULL,
			use_type varchar(20) NOT NULL,
			amount decimal(12,4) NOT NULL default 0,
			currency varchar(10) default NULL,
			PRIMARY KEY  (id),
			KEY type_location_use (space_type_id,location_id,use_type)
		) {$charset};";
	}
}
