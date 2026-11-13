<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Core;

use DateTimeImmutable;
use DateTimeZone;
use PlugixaCarParking\Core\Compat\LegacyHooks;
use PlugixaCarParking\Core\Database\NamedLock;
use PlugixaCarParking\Core\Database\Schema;
use PlugixaCarParking\Core\Modules\ModuleRegistry;
use PlugixaCarParking\Core\Security\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Activation and schema migration.
 */
final class Activator {

	/**
	 * Stores the plugin version the schema was last migrated for.
	 *
	 * Version 1 stored its own schema number, `'1'`, under this name. That never equals a plugin
	 * version, so every version 1 install migrates on its first request after the update.
	 */
	public const VERSION_OPTION = 'plugixacarparking_db_version';

	/**
	 * Stores the fingerprint of the modules the schema was last migrated for.
	 */
	public const MODULES_OPTION = 'plugixa_carparking_module_set';

	/**
	 * Set to `done` once every audit timestamp has been converted to UTC.
	 */
	public const AUDIT_UTC_OPTION = 'plugixa_carparking_audit_utc';

	/**
	 * Where a migration that did not finish is written down: when, and what each module said.
	 *
	 * Present means "the database is not up to date and the plugin knows it". `Admin\MigrationNotice`
	 * reads it, and it spaces out the retries. Deleted by the first migration that finishes.
	 */
	public const FAILURE_OPTION = 'plugixa_carparking_migration_failed';

	/**
	 * Seconds before the plugin tries a failed migration again by itself.
	 *
	 * Without it, a database user that may not create tables would have every module's dbDelta
	 * run on every request, front end included, for as long as the fault lasted. An administrator
	 * who is waiting presses "Try again" on the notice instead.
	 */
	public const RETRY_AFTER = 300;

	/**
	 * The most rows one request converts. A larger site finishes over several requests.
	 */
	private const AUDIT_UTC_BATCH = 5000;

	/**
	 * Settings version 1 stored that nothing reads any more.
	 *
	 * `free_location_limit` capped how many locations a site could have. There is no cap now.
	 * `default_billing_type` claimed to fill in for a location without a billing unit, but every
	 * location has one, and nothing ever read it.
	 *
	 * @var string[]
	 */
	private const RETIRED_SETTINGS = array( 'free_location_limit', 'default_billing_type' );

	/**
	 * Runs on plugin activation.
	 */
	public static function activate(): void {
		self::migrate();

		Settings::ensure_defaults();

		/**
		 * Fires after the plugin is activated and its tables exist.
		 *
		 * Modules seed their starter content here. It fires on activation only, never on a
		 * migration, so content an administrator deleted is not put back by an update.
		 */
		do_action( 'plugixa_carparking_activated' );

		flush_rewrite_rules();
	}

	/**
	 * Create/upgrade tables and capabilities. Idempotent, so it is safe to run on
	 * activation and again whenever the stored version differs from the shipped one.
	 *
	 * @return bool Whether every module migrated. False means the version was NOT recorded and
	 *              {@see failure()} says why.
	 */
	public static function migrate(): bool {
		$modules = Plugin::instance()->modules();

		// A file-copy update can reach this before boot() has run.
		if ( ! $modules->all() ) {
			$modules->discover();
		}

		$failures = $modules->migrate();

		// Version 1's cron events were renamed. Each module schedules its own under the new name;
		// the old ones would otherwise fire for ever with nothing listening.
		LegacyHooks::clear_cron();

		/*
		 * Version 1 stored credentials for outside services as plain text. Writing the option
		 * back is what encrypts them: `Settings::conceal_secrets()` runs on every write, and a
		 * value that is already ciphertext is left alone, so this is safe on every migration.
		 * Skipped when no installed module has a secret, so a site with nothing to protect does
		 * not rewrite an autoloaded option for nothing.
		 */
		if ( array() !== Settings::secret_keys() && false !== get_option( Settings::OPTION ) ) {
			update_option( Settings::OPTION, Settings::all() );
		}

		self::drop_retired_settings();

		// After the modules have migrated: their migrations fill in `modified_at` on rows that
		// predate it, and the value they fill in is one this step has to convert.
		self::convert_audit_timestamps();

		Capabilities::grant(
			array_merge(
				array_fill_keys( Capabilities::ALL, array( 'administrator' ) ),
				$modules->capabilities()
			)
		);

		/*
		 * THE CAPABILITY BACKFILL IS DELIBERATELY NOT CALLED HERE. It has to read users, and
		 * `get_userdata()` is pluggable — `pluggable.php` is required after the plugin files are
		 * included, so anything reaching `migrate()` early would fatal on an undefined function.
		 * It runs on `init` instead, from `Capabilities::register_hooks()`, guarded by its own
		 * marker.
		 */

		if ( array() !== $failures ) {
			/*
			 * NOT RECORDED AS DONE. The version stays what it was, so the drift guard in
			 * `Plugin::boot()` still sees a database that is behind and comes back to it after
			 * RETRY_AFTER. What failed is stored as facts and put into words by
			 * `Admin\MigrationNotice`, because this can run before a translation is loaded.
			 */
			update_option(
				self::FAILURE_OPTION,
				array(
					'at'       => time(),
					'failures' => $failures,
				),
				false
			);

			/**
			 * Fires when a migration did not finish.
			 *
			 * @param array<string, array{tables: string[], reason: string}> $failures Module id => what went wrong.
			 */
			do_action( 'plugixa_carparking_migration_failed', $failures );

			return false;
		}

		delete_option( self::FAILURE_OPTION );

		update_option( self::VERSION_OPTION, PLUGIXA_CARPARKING_VERSION );

		/*
		 * WRITTEN TOGETHER WITH THE VERSION, AND READ TOGETHER WITH IT BY `Plugin::boot()`. The
		 * version answers "is this a newer build"; this answers "are these the same modules". A
		 * premium install dropped over a free one at the same version changes the second and not
		 * the first — see `ModuleRegistry::fingerprint()`.
		 */
		update_option( self::MODULES_OPTION, $modules->fingerprint() );

		return true;
	}

	/**
	 * The migration that did not finish, if there is one.
	 *
	 * @return array{at: int, failures: array<string, array{tables: string[], reason: string}>}|null
	 */
	public static function failure(): ?array {
		$recorded = get_option( self::FAILURE_OPTION );

		if ( ! is_array( $recorded ) || empty( $recorded['failures'] ) || ! is_array( $recorded['failures'] ) ) {
			return null;
		}

		return array(
			'at'       => (int) ( $recorded['at'] ?? 0 ),
			'failures' => $recorded['failures'],
		);
	}

	/**
	 * Whether the drift guard should try the migration now.
	 *
	 * Always, unless the last attempt failed less than {@see RETRY_AFTER} seconds ago. Activation
	 * and the notice's "Try again" do not ask: somebody pressed something.
	 */
	public static function due(): bool {
		$failure = self::failure();

		return null === $failure || ( time() - $failure['at'] ) >= self::RETRY_AFTER;
	}

	/**
	 * Convert the audit timestamps version 1 wrote, from the site's clock to UTC. Runs once.
	 *
	 * `created_at` and `modified_at` say when a row changed and are stored in UTC. Version 1
	 * stored them on the site's clock, so on a site that is not on UTC its rows are out by the
	 * site's offset. Its last writer has been removed, so they can be put right for good.
	 *
	 * ONLY THE AUDIT COLUMNS. A booking's entry and exit, a hold's expiry and a coupon's validity
	 * are the car park's wall-clock time and stay as they are. `deleted_at` is left alone too:
	 * version 1 had no trash, so every value in it was written in UTC.
	 *
	 * WHICH ROWS. `created_by` and `modified_by` did not exist in version 1, and the record layer
	 * has filled both on every write since it arrived, with 0 for a guest. So a NULL in one of
	 * them means the matching timestamp was never written by the record layer:
	 *
	 *  - `created_by IS NULL`: the row was inserted by version 1, and `created_at` is converted.
	 *  - `modified_by IS NULL`: the record layer has not updated the row. Its `modified_at` was
	 *    filled in by a migration from `updated_at` or `created_at`, both on the site's clock,
	 *    and it is converted when it still equals one of them. When it equals neither, something
	 *    wrote it later without naming a user, which the job that expires holds does, in UTC.
	 *    That value is left as it is: shifting a correct time would damage it, and leaving a
	 *    stale one loses nothing that was not lost already.
	 *
	 * Each visited row has its NULLs replaced with 0 in the same statement that converts it, so
	 * the mark and the conversion cannot come apart. That is what makes the step safe to stop and
	 * start: a row is either still waiting or finished, and a second run finds only those waiting.
	 *
	 * A table with timestamps and neither of those columns has nothing to tell its old rows from
	 * its new ones, so it is not touched.
	 *
	 * Converted row by row with the site's timezone, not by adding a fixed offset, so a time in
	 * summer and a time in winter each move by the right amount. An hour that happened twice
	 * when the clocks went back cannot be told apart from its twin; PHP reads it as the later
	 * one, on standard time, so such a row may be an hour out.
	 *
	 * @param int $max_rows The most rows to convert in this call.
	 *
	 * @return bool Whether the conversion is finished.
	 */
	public static function convert_audit_timestamps( int $max_rows = self::AUDIT_UTC_BATCH ): bool {
		if ( 'done' === get_option( self::AUDIT_UTC_OPTION ) ) {
			return true;
		}

		$timezone = wp_timezone();

		// On a site that keeps UTC, the two clocks are the same clock and every row is right.
		if ( self::is_utc( $timezone ) ) {
			update_option( self::AUDIT_UTC_OPTION, 'done' );

			return true;
		}

		$tables = Plugin::instance()->modules()->tables();

		// One request at a time. A second one arriving mid-way leaves the work to the first.
		$finished = ( new NamedLock() )->run(
			'audit-utc',
			static function () use ( $tables, $timezone, $max_rows ): bool {
				$left = max( 1, $max_rows );

				foreach ( $tables as $table ) {
					$left -= self::convert_table( $table, $timezone, $left );

					if ( $left <= 0 ) {
						return false;
					}
				}

				return true;
			}
		);

		if ( true === $finished ) {
			update_option( self::AUDIT_UTC_OPTION, 'done' );
		}

		return true === $finished;
	}

	/**
	 * Convert up to a number of one table's waiting rows, lowest id first.
	 *
	 * @param string       $table    Bare table name.
	 * @param DateTimeZone $timezone The site's timezone.
	 * @param int          $limit    The most rows to convert.
	 *
	 * @return int How many rows were visited.
	 */
	private static function convert_table( string $table, DateTimeZone $timezone, int $limit ): int {
		global $wpdb;

		if ( ! Schema::exists( $table ) ) {
			return 0;
		}

		foreach ( array( 'created_at', 'modified_at', 'created_by', 'modified_by' ) as $column ) {
			if ( ! Schema::has_column( $table, $column ) ) {
				return 0;
			}
		}

		$name        = Schema::table( $table );
		$has_updated = Schema::has_column( $table, 'updated_at' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a one-off conversion of the plugin's own table; the rows it reads are the ones it is about to change.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE created_by IS NULL OR modified_by IS NULL ORDER BY id ASC LIMIT %d',
				$name,
				$limit
			),
			ARRAY_A
		);

		foreach ( (array) $rows as $row ) {
			$change = array(
				'created_by'  => $row['created_by'] ?? 0,
				'modified_by' => $row['modified_by'] ?? 0,
			);

			if ( null === $row['created_by'] ) {
				$change['created_at'] = self::to_utc( $row['created_at'], $timezone );
			}

			// What a migration filled `modified_at` with, on the site's clock.
			$filled = $has_updated && ! empty( $row['updated_at'] ) ? $row['updated_at'] : $row['created_at'];

			if ( null === $row['modified_by'] && null !== $row['modified_at'] && in_array( $row['modified_at'], array( $filled, $row['created_at'] ), true ) ) {
				$change['modified_at'] = self::to_utc( $row['modified_at'], $timezone );
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one statement per row, so a row is never left half converted.
			$wpdb->update( $name, $change, array( 'id' => (int) $row['id'] ) );
		}

		return count( (array) $rows );
	}

	/**
	 * A datetime on the site's clock, as the same moment in UTC.
	 *
	 * A value that is not a real date, such as MySQL's zero date, is returned as it came.
	 *
	 * @param mixed        $value    A `Y-m-d H:i:s` datetime, or null.
	 * @param DateTimeZone $timezone The site's timezone.
	 */
	private static function to_utc( mixed $value, DateTimeZone $timezone ): mixed {
		if ( ! is_string( $value ) || str_starts_with( $value, '0000' ) ) {
			return $value;
		}

		$local = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, $timezone );

		return false === $local
			? $value
			: $local->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Whether a timezone is UTC under any of its names: no offset now, and none at any other time.
	 *
	 * @param DateTimeZone $timezone The site's timezone.
	 */
	private static function is_utc( DateTimeZone $timezone ): bool {
		if ( 0 !== $timezone->getOffset( new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) ) ) {
			return false;
		}

		// London has no offset in winter and is not UTC. A zone is UTC only if it never has one.
		foreach ( (array) $timezone->getTransitions() as $transition ) {
			if ( 0 !== (int) ( $transition['offset'] ?? 0 ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Remove stored settings that no longer mean anything.
	 *
	 * A stored key the schema does not list is normally carried forward, because it may belong
	 * to a module that is not installed right now. These belong to nothing, so they are removed.
	 * The option is written only when one of them is found, which is once per site.
	 */
	private static function drop_retired_settings(): void {
		$stored = get_option( Settings::OPTION );

		if ( ! is_array( $stored ) ) {
			return;
		}

		$kept = array_diff_key( $stored, array_flip( self::RETIRED_SETTINGS ) );

		if ( count( $kept ) !== count( $stored ) ) {
			update_option( Settings::OPTION, $kept );
		}
	}

	/**
	 * Whether the database already matches the code on disk.
	 *
	 * A method rather than a condition inside `Plugin::boot()`, so it can be tested.
	 *
	 * A site that has never migrated stores `false` for both options, which is not equal to
	 * either value, so it migrates rather than waiting for a version bump that may never come.
	 *
	 * @param ModuleRegistry $modules The registry, already discovered.
	 */
	public static function schema_is_current( ModuleRegistry $modules ): bool {
		return PLUGIXA_CARPARKING_VERSION === get_option( self::VERSION_OPTION )
			&& $modules->fingerprint() === get_option( self::MODULES_OPTION )
			// A conversion that ran out of its batch is carried on by the next request's migration.
			&& 'done' === get_option( self::AUDIT_UTC_OPTION );
	}
}
