<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Modules\Spaces;

use PlugixaCarParking\Core\Database\Schema as Db;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The location_spaces table.
 */
final class Schema {

	/**
	 * @return string[]
	 */
	public static function tables(): array {
		return array( 'location_spaces' );
	}

	/**
	 * @return array<string, string> What dbDelta changed. Empty on a settled schema.
	 */
	public static function install(): array {
		$changes = Db::delta( self::location_spaces( Db::charset() ) );

		Db::ensure_innodb( ...self::tables() );

		return $changes;
	}

	/**
	 * The inventory of one location: how many spaces of each type it has.
	 *
	 * One row with a `quantity` is a pool of interchangeable spaces. A row with `is_assigned` set
	 * and a `label` is one individually identified space. Both shapes live in the same table so
	 * availability is a single SUM whichever a location uses.
	 *
	 * Version 1 created this table with no `created_at`, so on an upgraded site older rows have
	 * NULL there and in `modified_at`.
	 *
	 * @param string $charset Engine and charset clause.
	 */
	private static function location_spaces( string $charset ): string {
		$table = Db::table( 'location_spaces' );

		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL auto_increment,
			location_id bigint(20) unsigned NOT NULL,
			space_type_id bigint(20) unsigned NOT NULL,
			label varchar(100) default NULL,
			quantity int(10) unsigned NOT NULL default 1,
			is_assigned tinyint(1) NOT NULL default 0,
			status varchar(20) NOT NULL default 'active',
			display_order int(11) NOT NULL default 0,
			meta longtext,
			created_by bigint(20) unsigned default NULL,
			modified_by bigint(20) unsigned default NULL,
			created_at datetime default NULL,
			modified_at datetime default NULL,
			deleted_at datetime default NULL,
			PRIMARY KEY  (id),
			KEY location_type (location_id,space_type_id,status),
			KEY space_type_id (space_type_id),
			KEY deleted_at (deleted_at)
		) {$charset};";
	}
}
