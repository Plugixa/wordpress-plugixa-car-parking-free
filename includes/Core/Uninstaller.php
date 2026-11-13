<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Core;

use PlugixaCarParking\Core\Security\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What deleting the plugin does to the bookings.
 *
 * Registered through the Freemius SDK's after_uninstall action rather than a root
 * uninstall.php, which Freemius ships itself and forbids the plugin from providing.
 *
 * BY DEFAULT, NOTHING. DELETING THE PLUGIN REMOVES ITS FILES AND KEEPS THE DATA.
 *
 * The people who delete this plugin are overwhelmingly not people who want their bookings and
 * payment ledger gone: they are reinstalling to fix something, or tidying the plugin list after an
 * upgrade — and the upgrade is the dangerous one. Buying the premium build installs a SECOND copy
 * beside the free one; a customer who deletes the free copy "because it is the old one" would be
 * deleting the tables the premium copy runs on.
 *
 * So erasing is something an administrator asks for, with a switch in Settings that is off by
 * default — and even then it is refused while any other copy of the plugin is still installed,
 * because that copy uses the same tables and would come back to nothing.
 *
 * WHEN THE DATA IS KEPT, NOTHING IS TOUCHED — not the options, not the schema markers, and not the
 * capabilities. Revoking capabilities from an install that is staying would strip every custom
 * role and personal grant, and a reinstall only re-grants administrators: the data would come back
 * with the staff locked out of it. Version 1 deleted the settings and capabilities even when it
 * kept the tables; that is the behaviour this replaces.
 */
final class Uninstaller {

	/** The setting that turns erasing on. */
	public const SETTING = 'delete_data_on_uninstall';

	public const REFUSED = 'refused';
	public const KEPT    = 'kept';
	public const ERASED  = 'erased';

	public static function uninstall(): void {
		self::run( WP_PLUGIN_DIR, PLUGIXA_CARPARKING_BASENAME );
	}

	/**
	 * Decide, and act on the decision.
	 *
	 * The two parameters exist so the decision can be tested against a plugins folder that is not
	 * the real one; production passes the real one.
	 *
	 * @param string $plugins_dir  Where plugins are installed.
	 * @param string $own_basename This copy's basename, e.g. `plugixa-car-parking/plugixa-car-parking.php`.
	 *
	 * @return string One of the three constants above.
	 */
	public static function run( string $plugins_dir, string $own_basename ): string {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return self::REFUSED;
		}

		if ( ! self::should_erase( $plugins_dir, $own_basename ) ) {
			return self::KEPT;
		}

		self::erase();

		return self::ERASED;
	}

	/**
	 * Whether an uninstall right now would erase the data.
	 *
	 * Separate from `run()` so the decision can be asserted without anything being destroyed.
	 *
	 * @param string $plugins_dir  Where plugins are installed.
	 * @param string $own_basename This copy's basename.
	 */
	public static function should_erase( string $plugins_dir, string $own_basename ): bool {
		/*
		 * Read raw: only the stored value means anything during an uninstall. Version 1 saved this
		 * switch through a checkbox, so a truthy stored value counts; a missing option keeps the
		 * data.
		 */
		if ( empty( Settings::all()[ self::SETTING ] ) ) {
			return false;
		}

		return array() === self::other_copies( $plugins_dir, $own_basename );
	}

	/**
	 * Other installed copies of this plugin, active or not.
	 *
	 * A FOLDER SCAN RATHER THAN ASKING THE FREEMIUS SDK. The SDK can name "the other version", but
	 * it guesses that folder's name, and it only answers whether that copy is ACTIVE — a site with
	 * both copies deactivated for five minutes of troubleshooting would lose everything to one
	 * click. Every build of this plugin has the same main file name and the same text domain, so a
	 * folder holding both is another copy, whatever the folder is called.
	 *
	 * @param string $plugins_dir  Where plugins are installed.
	 * @param string $own_basename This copy's basename.
	 *
	 * @return string[] Absolute paths of the other copies' main files.
	 */
	public static function other_copies( string $plugins_dir, string $own_basename ): array {
		$own_folder = dirname( $own_basename );
		$found      = array();

		foreach ( (array) glob( trailingslashit( $plugins_dir ) . '*/' . basename( $own_basename ) ) as $file ) {
			if ( ! is_string( $file ) || basename( dirname( $file ) ) === $own_folder ) {
				continue;
			}

			$headers = get_file_data( $file, array( 'domain' => 'Text Domain' ) );

			if ( 'plugixa-car-parking' === $headers['domain'] ) {
				$found[] = $file;
			}
		}

		return $found;
	}

	/**
	 * Remove everything the plugin stored. Only ever reached through `run()`.
	 */
	private static function erase(): void {
		global $wpdb;

		/**
		 * Fires during uninstall, before the tables are dropped.
		 *
		 * A module that stores an option of its own deletes it here — the same rule as the
		 * schema: each module owns what it created.
		 */
		do_action( 'plugixa_carparking_uninstall' );

		// Drop by prefix rather than by a hard-coded list, so a table created by a module that
		// has since been removed, or by an older version, still gets cleaned up.
		$like = $wpdb->esc_like( $wpdb->prefix . 'plugixacarparking_' ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
		$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );

		foreach ( $tables as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- uninstall drops the plugin's own tables; nothing to cache.
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
		}

		delete_option( Settings::OPTION );
		delete_option( Activator::VERSION_OPTION );

		// Leaving it behind would tell a reinstall that the module set had not changed, and skip
		// the migration that recreates every table this uninstall just dropped.
		delete_option( Activator::MODULES_OPTION );
		delete_option( Activator::AUDIT_UTC_OPTION );

		self::delete_transients();

		Capabilities::revoke_all();
	}

	/**
	 * Remove the plugin's transients: the public rate-limit counters and the cached page lookups.
	 *
	 * They would expire on their own, but an administrator who asked for everything to be erased
	 * should not find rows of ours in the options table afterwards. Deleted by prefix, because the
	 * rate-limit keys end in a hash of the visitor and cannot be listed.
	 *
	 * Public so it can be tested without erasing anything else. On a site with a persistent object
	 * cache the transients are not in this table and expire there.
	 *
	 * @return int How many rows were deleted.
	 */
	public static function delete_transients(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall removes the plugin's own rows; nothing to cache.
		return (int) $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s',
				$wpdb->options,
				$wpdb->esc_like( '_transient_plugixa_carparking_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_plugixa_carparking_' ) . '%'
			)
		);
	}
}
