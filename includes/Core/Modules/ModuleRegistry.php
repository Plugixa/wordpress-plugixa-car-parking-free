<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Core\Modules;

use PlugixaCarParking\Core\Container;
use PlugixaCarParking\Core\Database\MigrationFailed;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Discovers modules on disk and boots them.
 *
 * Discovery is by filesystem glob, in two roots:
 *
 *   includes/Modules/<Name>/Module.php      ->  PlugixaCarParking\Modules\<Name>\Module
 *   pro/includes/Modules/<Name>/Module.php  ->  PlugixaCarParking\Pro\Modules\<Name>\Module
 *
 * THE SECOND ROOT IS THE ENTIRE FREE/PREMIUM SEAM, and it works by ABSENCE. Freemius deletes
 * `pro/` from the free build (see `@fs_premium_only` in plugixa-car-parking.php), so on a free
 * site the glob finds nothing and premium modules DO NOT EXIST — as opposed to existing and being
 * locked behind a licence check, which is what WordPress.org rejects as trialware. There is
 * deliberately no `is_premium()` test here, and there must never be one: the only question this
 * class asks is whether a file is on disk.
 *
 * `is_dir()` inside scan() is what makes a missing directory a non-event rather than a warning.
 *
 * A third root arrives through the `plugixa_carparking_modules` filter below, which is how an
 * add-on plugin contributes a module without living in this directory at all.
 */
final class ModuleRegistry {

	/**
	 * Booted modules, keyed by id.
	 *
	 * @var array<string, ModuleInterface>
	 */
	private array $modules = array();

	public function __construct( private readonly Container $container ) {}

	/**
	 * Discover and instantiate every module, then let each register its services.
	 */
	public function discover(): void {
		$classes = array_merge(
			$this->scan( PLUGIXA_CARPARKING_PATH . 'includes/Modules', 'PlugixaCarParking\\Modules\\' ),
			$this->scan( PLUGIXA_CARPARKING_PATH . 'pro/includes/Modules', 'PlugixaCarParking\\Pro\\Modules\\' )
		);

		/**
		 * Filter the discovered module classes.
		 *
		 * Lets a third-party add-on contribute a module without living inside this
		 * plugin's directory.
		 *
		 * @param string[] $classes Fully-qualified class names implementing ModuleInterface.
		 */
		$classes = (array) apply_filters( 'plugixa_carparking_modules', $classes );

		foreach ( $classes as $class_name ) {
			if ( ! is_string( $class_name ) || ! class_exists( $class_name ) ) {
				continue;
			}
			if ( ! is_subclass_of( $class_name, ModuleInterface::class ) ) {
				continue;
			}

			// A broken module must not take the whole plugin (and its REST API) down.
			try {
				$module = $this->container->get( $class_name );
				$module->registerServices( $this->container );
				$this->modules[ $module->id() ] = $module;
			} catch ( Throwable $e ) {
				$this->log_failure( $class_name, $e );
			}
		}
	}

	/**
	 * Let every module register its WordPress hooks.
	 */
	public function registerHooks(): void {
		foreach ( $this->modules as $module ) {
			try {
				$module->registerHooks( $this->container );
			} catch ( Throwable $e ) {
				$this->log_failure( $module::class, $e );
			}
		}
	}

	/**
	 * Let every module register its REST routes. Called on rest_api_init.
	 */
	public function registerRoutes(): void {
		foreach ( $this->modules as $module ) {
			try {
				$module->registerRoutes( $this->container );
			} catch ( Throwable $e ) {
				$this->log_failure( $module::class, $e );
			}
		}
	}

	/**
	 * Run every module's migration. Idempotent.
	 *
	 * One module failing does not stop the others, but the failure is HANDED BACK: the activator
	 * records the version only when this is empty, so a missing table is never marked as done.
	 *
	 * @return array<string, array{tables: string[], reason: string}> Module id => what went wrong. Empty when all finished.
	 */
	public function migrate(): array {
		$failures = array();

		foreach ( $this->modules as $module ) {
			try {
				$module->migrate();
			} catch ( MigrationFailed $e ) {
				$failures[ $module->id() ] = array(
					'tables' => $e->tables(),
					'reason' => $e->reason(),
				);

				$this->log_failure( $module::class, $e );
			} catch ( Throwable $e ) {
				// Not a missing table, but a migration that did not finish all the same.
				$failures[ $module->id() ] = array(
					'tables' => array(),
					'reason' => $e->getMessage(),
				);

				$this->log_failure( $module::class, $e );
			}
		}

		return $failures;
	}

	/**
	 * The instance behind an id.
	 *
	 * @param string $id Module id.
	 */
	public function module( string $id ): ?ModuleInterface {
		return $this->modules[ $id ] ?? null;
	}

	/**
	 * Every capability introduced by every module, merged.
	 *
	 * @return array<string, string[]> Capability => roles.
	 */
	public function capabilities(): array {
		$caps = array();
		foreach ( $this->modules as $module ) {
			foreach ( $module->capabilities() as $cap => $roles ) {
				$caps[ $cap ] = array_unique( array_merge( $caps[ $cap ] ?? array(), $roles ) );
			}
		}

		return $caps;
	}

	/**
	 * Every table the booted modules declare, as bare names.
	 *
	 * A module that stores anything keeps a `Schema` class beside its `Module`, and that class
	 * lists its tables. The list is read from there, so a step that has to visit every table
	 * needs no list of its own to fall out of date when a module is added.
	 *
	 * @return string[]
	 */
	public function tables(): array {
		$tables = array();

		foreach ( $this->modules as $module ) {
			$schema = substr( $module::class, 0, (int) strrpos( $module::class, '\\' ) ) . '\\Schema';

			if ( class_exists( $schema ) && is_callable( array( $schema, 'tables' ) ) ) {
				$tables = array_merge( $tables, array_map( 'strval', (array) $schema::tables() ) );
			}
		}

		return array_values( array_unique( $tables ) );
	}

	/**
	 * @return array<string, ModuleInterface> Booted modules, keyed by id.
	 */
	public function all(): array {
		return $this->modules;
	}

	/**
	 * A stable fingerprint of WHICH modules are on disk.
	 *
	 * THE VERSION NUMBER CANNOT ANSWER "HAS THE SCHEMA WORK BEEN DONE". Comparing only the stored
	 * version catches an upgrade and misses the premium build being installed over the free one AT
	 * THE SAME VERSION: no activation hook fires on an in-place update, the versions match, and the
	 * premium modules that appeared on disk would have their routes registered against tables that
	 * were never created.
	 *
	 * Deliberately the module IDS and not their count: swapping one module for another is a change
	 * this has to notice, and two different sets can be the same size. Sorted, so discovery order —
	 * a filesystem glob plus whatever `plugixa_carparking_modules` adds — cannot make an unchanged
	 * install look changed and migrate on every request.
	 */
	public function fingerprint(): string {
		$ids = array_keys( $this->modules );

		sort( $ids );

		return md5( implode( '|', $ids ) );
	}

	/**
	 * Map each <Name>/Module.php under a root to its fully-qualified class name.
	 *
	 * @param string $dir              Absolute directory to scan.
	 * @param string $namespace_prefix PSR-4 prefix the directory maps to.
	 *
	 * @return string[]
	 */
	private function scan( string $dir, string $namespace_prefix ): array {
		if ( ! is_dir( $dir ) ) {
			return array();
		}

		$classes = array();
		foreach ( (array) glob( $dir . '/*/Module.php' ) as $file ) {
			$name      = basename( dirname( (string) $file ) );
			$classes[] = $namespace_prefix . $name . '\\Module';
		}

		return $classes;
	}

	/**
	 * @param string    $class_name The module that failed.
	 * @param Throwable $e          The failure.
	 */
	private function log_failure( string $class_name, Throwable $e ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- module discovery failing is a developer error that must not be silent, and the call is already inside a WP_DEBUG guard.
				sprintf( '[Plugixa Car Parking] Module "%s" failed: %s', $class_name, $e->getMessage() )
			);
		}
	}
}
