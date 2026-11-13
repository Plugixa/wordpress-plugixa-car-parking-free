<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Modules\Forms;

use PlugixaCarParking\Core\Database\Schema as Db;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The booking_forms table.
 */
final class Schema {

	/**
	 * @return string[]
	 */
	public static function tables(): array {
		return array( 'booking_forms' );
	}

	/**
	 * @return array<string, string> What dbDelta changed. Empty on a settled schema.
	 */
	public static function install(): array {
		$changes = Db::delta( self::booking_forms( Db::charset() ) );

		Db::ensure_innodb( ...self::tables() );
		Db::backfill_modified_at( 'booking_forms' );
		self::backfill_default();
		self::forget_v1_steps();

		return $changes;
	}

	/**
	 * Clear the steps version 1 stored as a list of names ("dates_location", "space_type", …).
	 *
	 * Nothing since has read that shape: a form with such steps shows the fallback screens and no
	 * payment step. With none stored, the form follows the standard steps, which is what its owner
	 * last saw it do in version 1. A stored step is now a row, so its JSON starts `[{`.
	 */
	private static function forget_v1_steps(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off migration of the plugin's own table.
		$wpdb->query( $wpdb->prepare( "UPDATE %i SET steps = '[]' WHERE steps LIKE %s", Db::table( 'booking_forms' ), '["%' ) );
	}

	/**
	 * Mark a default form on a site that has forms and none marked.
	 *
	 * The form seeded on install, slug `default`, when it is live; else the oldest live form. A
	 * site with no live form is left alone, and the seeder or the first save marks one.
	 */
	private static function backfill_default(): void {
		global $wpdb;

		$table = Db::table( 'booking_forms' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off migration of the plugin's own table.
		if ( (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE is_default = 1 AND deleted_at IS NULL', $table ) ) > 0 ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off migration of the plugin's own table.
		$id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM %i WHERE deleted_at IS NULL ORDER BY ( slug = 'default' ) DESC, ( status = 'active' ) DESC, id ASC LIMIT 1",
				$table
			)
		);

		if ( $id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off migration of the plugin's own table.
			$wpdb->update( $table, array( 'is_default' => 1, 'status' => 'active' ), array( 'id' => $id ) );
		}
	}

	/**
	 * A booking form: which steps it has, in what order, how it looks, and where it books.
	 *
	 * `location_ids` and `space_type_ids` are the form's scope, each an empty list for "all of
	 * them". `is_default` marks the one form a page that names none is shown. `mode` is no longer
	 * read; the column stays so a downgrade finds what it wrote.
	 *
	 * `slug` is what the shortcode's `form` attribute names, so it is unique across live and
	 * trashed forms alike.
	 *
	 * @param string $charset Engine and charset clause.
	 */
	private static function booking_forms( string $charset ): string {
		$table = Db::table( 'booking_forms' );

		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL auto_increment,
			name varchar(255) NOT NULL,
			slug varchar(191) NOT NULL,
			status varchar(20) NOT NULL default 'active',
			mode varchar(20) NOT NULL default 'wizard',
			steps longtext,
			style longtext,
			location_ids longtext,
			space_type_ids longtext,
			is_default tinyint(1) unsigned NOT NULL default 0,
			settings longtext,
			created_by bigint(20) unsigned default NULL,
			modified_by bigint(20) unsigned default NULL,
			created_at datetime default NULL,
			modified_at datetime default NULL,
			deleted_at datetime default NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug),
			KEY status (status),
			KEY deleted_at (deleted_at)
		) {$charset};";
	}
}
