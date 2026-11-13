<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Core\Compat;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The hook names version 1 used, kept working for code written against them.
 *
 * Version 1 mixed two prefixes, `plugixacarparking_` and `plugixa_carparking_`. Every hook now
 * uses the second. The ones that used the first still fire under their old name, straight after
 * the new one, and WordPress reports each use as deprecated so a site owner with debugging on can
 * see what to rename.
 *
 * A caller fires the new hook itself, where a reader expects to find it, and then calls one of
 * the two methods here. Nothing runs unless something is still attached to the old name.
 */
final class LegacyHooks {

	/**
	 * The version the old names were deprecated in.
	 */
	private const SINCE = '1.0.0';

	/**
	 * What every cron hook version 1 scheduled began with. Each has been renamed, and no hook the
	 * plugin schedules now begins this way.
	 */
	public const CRON_PREFIX = 'plugixacarparking_';

	/**
	 * Fire a renamed action under its old name.
	 *
	 * @param string            $old  The version 1 hook name.
	 * @param string            $name What it is called now.
	 * @param array<int, mixed> $args The arguments the action carries.
	 */
	public static function action( string $old, string $name, array $args ): void {
		do_action_deprecated( $old, $args, self::SINCE, $name );
	}

	/**
	 * Pass a value through a renamed filter under its old name.
	 *
	 * @param string            $old  The version 1 hook name.
	 * @param string            $name What it is called now.
	 * @param array<int, mixed> $args The value being filtered, then any further arguments.
	 */
	public static function filter( string $old, string $name, array $args ): mixed {
		return apply_filters_deprecated( $old, $args, self::SINCE, $name );
	}

	/**
	 * Unschedule every event version 1 left behind.
	 *
	 * Run on migration and on deactivation, so an upgraded site is not left running an event
	 * nothing listens to. The events are found by their prefix and not from a list, so one that
	 * belonged to a part of version 1 this build does not include is cleared too.
	 */
	public static function clear_cron(): void {
		foreach ( self::scheduled() as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
	}

	/**
	 * The version 1 cron hooks that still have an event scheduled.
	 *
	 * @return string[]
	 */
	public static function scheduled(): array {
		$hooks = array();

		foreach ( (array) _get_cron_array() as $events ) {
			foreach ( array_keys( (array) $events ) as $hook ) {
				if ( str_starts_with( (string) $hook, self::CRON_PREFIX ) ) {
					$hooks[ (string) $hook ] = true;
				}
			}
		}

		return array_keys( $hooks );
	}
}
