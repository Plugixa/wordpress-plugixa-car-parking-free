<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Modules\Notifications;

use PlugixaCarParking\Core\Database\Schema as Db;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The notification log.
 */
final class Schema {

	/**
	 * @return string[]
	 */
	public static function tables(): array {
		return array( 'notification_log' );
	}

	/**
	 * @return array<string, string> What dbDelta changed. Empty on a settled schema.
	 */
	public static function install(): array {
		$changes = Db::delta( self::notification_log( Db::charset() ) );

		Db::ensure_innodb( ...self::tables() );

		return $changes;
	}

	/**
	 * One row per message sent, or attempted, about a booking. A log: appended to, never edited.
	 *
	 * @param string $charset Engine and charset clause.
	 */
	private static function notification_log( string $charset ): string {
		$table = Db::table( 'notification_log' );

		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL auto_increment,
			booking_id bigint(20) unsigned default NULL,
			template_id bigint(20) unsigned default NULL,
			channel varchar(20) default NULL,
			recipient varchar(255) default NULL,
			status varchar(20) default NULL,
			error text,
			sent_at datetime default NULL,
			PRIMARY KEY  (id),
			KEY booking_id (booking_id)
		) {$charset};";
	}
}
