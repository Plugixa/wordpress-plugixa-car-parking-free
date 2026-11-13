<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Modules\Payments;

use PlugixaCarParking\Core\Database\Schema as Db;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The payments ledger.
 */
final class Schema {

	/**
	 * @return string[]
	 */
	public static function tables(): array {
		return array( 'payments' );
	}

	/**
	 * @return array<string, string> What dbDelta changed. Empty on a settled schema.
	 */
	public static function install(): array {
		$changes = Db::delta( self::payments( Db::charset() ) );

		Db::ensure_innodb( ...self::tables() );
		Db::backfill_modified_at( 'payments', true );

		return $changes;
	}

	/**
	 * Money in and money out, one row per movement.
	 *
	 * APPEND-ONLY. A refund is a new row of type `refund`, never an edit to the payment it
	 * reverses, so what a booking has been paid is always the sum of its rows. The one change a row
	 * can see is a refund the provider accepted and then failed to make: it becomes `failed`, because
	 * the money never went back. `deleted_at` is
	 * here only because every record table carries it; no route trashes a payment.
	 *
	 * @param string $charset Engine and charset clause.
	 */
	private static function payments( string $charset ): string {
		$table = Db::table( 'payments' );

		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL auto_increment,
			booking_id bigint(20) unsigned NOT NULL,
			gateway varchar(40) NOT NULL,
			transaction_id varchar(190) default NULL,
			type varchar(20) NOT NULL default 'full',
			amount decimal(12,4) NOT NULL default 0,
			currency varchar(10) default NULL,
			status varchar(20) NOT NULL default 'pending',
			response longtext,
			created_by bigint(20) unsigned default NULL,
			modified_by bigint(20) unsigned default NULL,
			created_at datetime NOT NULL,
			updated_at datetime default NULL,
			modified_at datetime default NULL,
			deleted_at datetime default NULL,
			PRIMARY KEY  (id),
			KEY booking_id (booking_id),
			KEY gateway_txn (gateway,transaction_id),
			KEY deleted_at (deleted_at)
		) {$charset};";
	}
}
