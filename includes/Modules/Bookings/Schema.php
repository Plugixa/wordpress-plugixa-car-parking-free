<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Modules\Bookings;

use PlugixaCarParking\Core\Database\Schema as Db;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The bookings table and its line items.
 */
final class Schema {

	/**
	 * @return string[]
	 */
	public static function tables(): array {
		return array( 'bookings', 'booking_items' );
	}

	/**
	 * @return array<string, string> What dbDelta changed. Empty on a settled schema.
	 */
	public static function install(): array {
		$charset = Db::charset();
		$changes = Db::delta( self::bookings( $charset ), self::booking_items( $charset ) );

		Db::ensure_innodb( ...self::tables() );
		Db::backfill_modified_at( 'bookings', true );

		return $changes;
	}

	/**
	 * One reservation.
	 *
	 * The customer and the vehicle are columns here rather than records of their own: a booking
	 * has to stay readable as it was made, whatever later happens to the account that made it.
	 *
	 * TWO KINDS OF DATETIME, AND THEY ARE NOT ON THE SAME CLOCK. `entry_datetime`,
	 * `exit_datetime` and `hold_expires_at` are the car park's wall-clock time, because that is
	 * what a customer types and what a sign at the gate says. The audit columns at the bottom are
	 * about the row, not the visit.
	 *
	 * `updated_at` is version 1's "last changed" column. It stays because existing rows carry it;
	 * `modified_at` is the one the record layer reads.
	 *
	 * @param string $charset Engine and charset clause.
	 */
	private static function bookings( string $charset ): string {
		$table = Db::table( 'bookings' );

		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL auto_increment,
			booking_ref varchar(40) NOT NULL,
			language_code varchar(10) default NULL,
			location_id bigint(20) unsigned NOT NULL,
			space_type_id bigint(20) unsigned default NULL,
			space_id bigint(20) unsigned default NULL,
			booking_form_id bigint(20) unsigned default NULL,
			user_id bigint(20) unsigned default NULL,
			status varchar(20) NOT NULL default 'pending',
			billing_type varchar(20) NOT NULL default 'day',
			entry_datetime datetime NOT NULL,
			exit_datetime datetime NOT NULL,
			quantity int(10) unsigned NOT NULL default 1,
			hold_expires_at datetime default NULL,
			license_plate varchar(30) default NULL,
			vehicle longtext,
			customer_name varchar(255) default NULL,
			customer_email varchar(190) default NULL,
			customer_phone varchar(50) default NULL,
			billing longtext,
			currency varchar(10) default NULL,
			subtotal decimal(12,4) NOT NULL default 0,
			discount_total decimal(12,4) NOT NULL default 0,
			tax_total decimal(12,4) NOT NULL default 0,
			deposit_amount decimal(12,4) NOT NULL default 0,
			total decimal(12,4) NOT NULL default 0,
			amount_paid decimal(12,4) NOT NULL default 0,
			coupon_id bigint(20) unsigned default NULL,
			payment_method varchar(40) default NULL,
			payment_status varchar(20) NOT NULL default 'unpaid',
			notes text,
			meta longtext,
			created_by bigint(20) unsigned default NULL,
			modified_by bigint(20) unsigned default NULL,
			created_at datetime NOT NULL,
			updated_at datetime default NULL,
			modified_at datetime default NULL,
			deleted_at datetime default NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY booking_ref (booking_ref),
			KEY overlap (location_id,status,entry_datetime,exit_datetime),
			KEY space_overlap (space_id,status,entry_datetime,exit_datetime),
			KEY space_type_id (space_type_id),
			KEY user_id (user_id),
			KEY status (status),
			KEY hold_expires_at (hold_expires_at),
			KEY deleted_at (deleted_at)
		) {$charset};";
	}

	/**
	 * What a booking was charged for, line by line.
	 *
	 * Written once when the booking is priced and never edited: it is the record of the quote the
	 * customer accepted, so it has no audit or trash columns of its own.
	 *
	 * @param string $charset Engine and charset clause.
	 */
	private static function booking_items( string $charset ): string {
		$table = Db::table( 'booking_items' );

		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL auto_increment,
			booking_id bigint(20) unsigned NOT NULL,
			type varchar(20) NOT NULL,
			reference_id bigint(20) unsigned default NULL,
			label varchar(255) default NULL,
			quantity decimal(10,2) NOT NULL default 1,
			unit_price decimal(12,4) NOT NULL default 0,
			total decimal(12,4) NOT NULL default 0,
			tax_rate_id bigint(20) unsigned default NULL,
			meta longtext,
			PRIMARY KEY  (id),
			KEY booking_id (booking_id)
		) {$charset};";
	}
}
