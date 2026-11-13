<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Core\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Table-name and dbDelta helpers shared by every module's migration.
 *
 * Schema ownership is decentralised: this class creates no tables of its own. Each
 * module declares and creates the tables it owns, so a premium module can add tables
 * without any free file changing.
 */
final class Schema {

	/**
	 * Fully-qualified table name.
	 *
	 * @param string $name Bare table name, e.g. "bookings".
	 */
	public static function table( string $name ): string {
		global $wpdb;

		return $wpdb->prefix . 'plugixacarparking_' . $name;
	}

	/**
	 * The engine and charset clause every table is created with.
	 *
	 * InnoDB is requested EXPLICITLY rather than inherited from the server's default,
	 * because that default cannot be relied on — the machine this plugin was first built on
	 * defaults to MyISAM, and version 1 created all of its tables that way. A booking system on
	 * MyISAM is quietly broken in three ways that never announce themselves:
	 *
	 *  - no transactions. START TRANSACTION and ROLLBACK are accepted and silently ignored,
	 *    so code that believes it is atomic simply is not.
	 *  - table-level locking. Every write locks the whole table, so two customers booking at
	 *    the same time serialise behind each other.
	 *  - no crash recovery. A power cut leaves a corrupt table rather than a rolled-back
	 *    transaction.
	 *
	 * The first is load-bearing: creating a booking writes the booking, its line items and a
	 * coupon redemption, and a failure half way must leave none of them.
	 */
	public static function charset(): string {
		global $wpdb;

		return 'ENGINE=InnoDB ' . $wpdb->get_charset_collate();
	}

	/**
	 * Convert any table that is not already InnoDB.
	 *
	 * dbDelta only ever adds and alters columns — it will not change a table's engine — so
	 * an installation created by version 1 would stay on MyISAM forever. This is the upgrade
	 * path, and it is a no-op on a table that is already right.
	 *
	 * @param string ...$names Bare table names.
	 */
	public static function ensure_innodb( string ...$names ): void {
		global $wpdb;

		foreach ( $names as $name ) {
			$table = self::table( $name );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
			$engine = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
					$table
				)
			);

			if ( null === $engine || 0 === strcasecmp( (string) $engine, 'InnoDB' ) ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- a schema change is the entire point of this method; nothing to cache.
			$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ENGINE=InnoDB', $table ) );
		}
	}

	/**
	 * Run one or more CREATE TABLE statements through dbDelta.
	 *
	 * dbDelta is picky in ways that fail silently: types must be lowercase, there
	 * must be two spaces after PRIMARY KEY, and every KEY must be named. Statements
	 * are passed through verbatim, so those rules are the caller's to honour.
	 *
	 * Returns what dbDelta changed. On a schema that has already been applied that must
	 * be empty — if it is not, dbDelta disagrees with what MySQL actually stored and will
	 * keep issuing ALTERs on every migration, silently, forever. SchemaTest asserts
	 * exactly that, which is the only way to catch the whole class of bug.
	 *
	 * A REFUSED CREATE IS NOT REPORTED BY dbDelta. It does not look at what the database answered:
	 * it returns as usual, listing the table among what it "created". So this looks — a table
	 * that is not there afterwards is thrown as {@see MigrationFailed}, with what the database
	 * said, and the version is not recorded (`Activator::migrate()`).
	 *
	 * @param string ...$statements CREATE TABLE statements.
	 *
	 * @return array<string, string> What dbDelta changed, keyed by table.column.
	 *
	 * @throws MigrationFailed When a table named in a statement does not exist afterwards.
	 */
	public static function delta( string ...$statements ): array {
		global $wpdb;

		$changes = array();
		$missing = array();
		$reason  = '';

		// `dbDelta()` is defined in a file only wp-admin loads, and a migration can run on any request.
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		foreach ( $statements as $sql ) {
			$changes = array_merge( $changes, (array) dbDelta( $sql ) );

			// Read first: the check below is a query, and it clears what the CREATE left here.
			$said = (string) $wpdb->last_error;

			// The same expression dbDelta uses to find the name in the statement.
			if ( 1 !== preg_match( '|CREATE TABLE ([^ ]*)|', $sql, $named ) ) {
				continue;
			}

			$table = trim( $named[1], '`' );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- asks whether a table it was just told to create exists; a cached answer would be the bug.
			if ( $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
				continue;
			}

			$missing[] = $table;
			$reason    = '' !== $said ? $said : $reason;
		}

		if ( array() !== $missing ) {
			// Built, then thrown. It carries the table names and the database's message as they are:
			// nothing is printed here, and Admin\MigrationNotice escapes both where it prints them.
			$failed = new MigrationFailed( $missing, $reason );

			throw $failed;
		}

		return $changes;
	}

	/**
	 * Give `modified_at` a value on rows that predate the column.
	 *
	 * Version 1 tracked the last change in `updated_at` on some tables and not at all on others.
	 * The record layer reads `modified_at` to detect an edit conflict, and a NULL there would let
	 * the first save after an upgrade overwrite somebody else's without warning.
	 *
	 * Safe to repeat: it only ever touches rows where the column is still NULL.
	 *
	 * @param string $name           Bare table name.
	 * @param bool   $has_updated_at Whether the table carries version 1's `updated_at`.
	 */
	public static function backfill_modified_at( string $name, bool $has_updated_at = false ): void {
		global $wpdb;

		$table = self::table( $name );

		if ( $has_updated_at ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off backfill of the plugin's own table.
			$wpdb->query( $wpdb->prepare( 'UPDATE %i SET modified_at = COALESCE(updated_at, created_at) WHERE modified_at IS NULL', $table ) );

			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off backfill of the plugin's own table.
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET modified_at = created_at WHERE modified_at IS NULL', $table ) );
	}

	/**
	 * Whether a table exists.
	 *
	 * @param string $name Bare table name.
	 */
	public static function exists( string $name ): bool {
		global $wpdb;

		$table = self::table( $name );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}

	/**
	 * Whether a table has a column.
	 *
	 * dbDelta can add a column and cannot remove one, so a migration that moves data out of a
	 * column has to drop it explicitly afterwards, and has to ask first, because it will run again
	 * on the next upgrade.
	 *
	 * @param string $table  Bare table name.
	 * @param string $column Column name.
	 */
	public static function has_column( string $table, string $column ): bool {
		global $wpdb;

		$name = self::table( $table );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- schema introspection; a cached answer would be the whole bug.
		return (bool) $wpdb->get_var(
			$wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $name, $column )
		);
	}

	/**
	 * Drop a column, if it is still there.
	 *
	 * Guarded rather than blind, because a migration runs on every version bump and the second run
	 * must be a no-op rather than an error.
	 *
	 * @param string $table  Bare table name.
	 * @param string $column Column name.
	 */
	public static function drop_column( string $table, string $column ): void {
		if ( ! self::has_column( $table, $column ) ) {
			return;
		}

		global $wpdb;

		$name = self::table( $table );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- a schema change is the entire point of this method; nothing to cache.
		$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP COLUMN %i', $name, $column ) );
	}

	/**
	 * Drop tables.
	 *
	 * A method that drops tables is worth keeping to callers whose entire job is destroying data
	 * on purpose. A migration never calls it: an upgrade must not be able to lose a booking.
	 *
	 * @param string ...$names Bare table names.
	 */
	public static function drop( string ...$names ): void {
		global $wpdb;

		foreach ( $names as $name ) {
			$table = self::table( $name );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- dropping the plugin's own tables; nothing to cache.
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
		}
	}
}
