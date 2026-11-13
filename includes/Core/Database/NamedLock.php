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
 * Run a piece of work while holding a named database lock.
 *
 * For work that has to read, decide and then write, where two requests doing it at once would
 * each decide on what the other is about to change: checking a car park has a free space and then
 * booking it, or checking a payment has not been recorded and then recording it.
 *
 * A NAMED LOCK RATHER THAN A ROW LOCK, because in both cases the thing being protected is the
 * ABSENCE of a row. `SELECT … FOR UPDATE` locks rows that exist; it cannot stop a second request
 * inserting a booking beside the one this request is about to insert.
 *
 * The lock belongs to the database connection, so it is released if the request dies, and it
 * works across every PHP process and web server talking to the same database.
 */
final class NamedLock {

	/**
	 * Seconds to wait for another request to finish before giving up.
	 */
	public const TIMEOUT = 5;

	/**
	 * Run a callback under the lock.
	 *
	 * @template T
	 *
	 * @param string       $name What is being protected, e.g. `booking:12`. Scoped to this site here.
	 * @param callable():T $work The work to do while holding it.
	 *
	 * @return T|null What the callback returned, or null when the lock could not be had in time.
	 */
	public function run( string $name, callable $work ): mixed {
		global $wpdb;

		$key = $this->key( $name );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a lock is the opposite of a cacheable read.
		$got = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $key, self::TIMEOUT ) );

		if ( '1' !== (string) $got ) {
			return null;
		}

		try {
			return $work();
		} finally {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- releasing the lock taken above.
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $key ) );
		}
	}

	/**
	 * The lock's name on the server.
	 *
	 * Lock names are global to the database SERVER, not the database, so two sites on one server
	 * would otherwise share a lock named `booking:12`. The table prefix and database name make it
	 * this site's. Hashed because MySQL limits a lock name to 64 characters.
	 *
	 * @param string $name The caller's name for what is protected.
	 */
	private function key( string $name ): string {
		global $wpdb;

		return 'plugixa_cp_' . md5( DB_NAME . '|' . $wpdb->prefix . '|' . $name );
	}
}
