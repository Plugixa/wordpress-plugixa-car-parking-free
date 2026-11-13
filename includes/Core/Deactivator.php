<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Core;

use PlugixaCarParking\Core\Compat\LegacyHooks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Deactivation. Removes scheduled work but never touches data — a deactivated plugin
 * that destroyed the user's bookings would be a very unpleasant surprise.
 *
 * IT NAMES NOTHING A MODULE OWNS. A module that schedules cron hooks the action below and
 * unschedules itself, so this file does not grow a line for every module that ever schedules
 * anything.
 */
final class Deactivator {

	public static function deactivate(): void {
		/**
		 * Fires on deactivation, before scheduled events are cleared.
		 *
		 * A module that schedules cron clears it here. Modules are booted on this request like
		 * any other, so their listeners are live by the time this runs.
		 */
		do_action( 'plugixa_carparking_deactivated' );

		// A site deactivated before it ever migrated may still have version 1's events.
		LegacyHooks::clear_cron();

		flush_rewrite_rules();
	}
}
