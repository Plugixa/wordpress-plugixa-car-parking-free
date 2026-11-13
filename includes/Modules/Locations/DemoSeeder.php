<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Modules\Locations;

use PlugixaCarParking\Core\Database\Schema as Db;
use PlugixaCarParking\Core\Settings;
use PlugixaCarParking\Modules\SpaceTypes\Seeder as SpaceTypeSeeder;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One working car park, so a new install can take a booking straight away.
 *
 * It reaches into two tables other modules own — a location is not bookable until it has spaces
 * and those spaces have a price — which is why this is the demo seeder and not part of how a
 * location is normally created.
 */
final class DemoSeeder {

	/**
	 * Per starter space type: how many spaces the demo car park has, and what they cost.
	 *
	 * @var array<string, array{quantity: int, day: float, hour: float}>
	 */
	private const INVENTORY = array(
		'standard' => array(
			'quantity' => 50,
			'day'      => 10.00,
			'hour'     => 2.00,
		),
		'covered'  => array(
			'quantity' => 20,
			'day'      => 15.00,
			'hour'     => 3.00,
		),
		'disabled' => array(
			'quantity' => 5,
			'day'      => 8.00,
			'hour'     => 1.50,
		),
		'electric' => array(
			'quantity' => 10,
			'day'      => 18.00,
			'hour'     => 4.00,
		),
	);

	/**
	 * Seed the demo car park, unless the site already has any location, trashed ones included.
	 */
	public static function run(): void {
		global $wpdb;

		$locations = Db::table( 'locations' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off check on activation.
		if ( (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $locations ) ) > 0 ) {
			return;
		}

		$now = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- seeding the plugin's own table.
		$wpdb->insert(
			$locations,
			array(
				'name'                 => 'Main Car Park',
				'slug'                 => 'main-car-park',
				'description'          => 'Demo parking location created on activation.',
				'status'               => 'active',
				'inventory_mode'       => 'capacity',
				'default_billing_type' => 'day',
				'currency'             => (string) Settings::get( 'currency', 'USD' ),
				'settings'             => wp_json_encode(
					array(
						'default_entry_time' => Settings::get( 'default_entry_time', '08:00' ),
						'default_exit_time'  => Settings::get( 'default_exit_time', '18:00' ),
						'max_advance_days'   => Settings::get( 'max_advance_days', 365 ),
					)
				),
				'display_order'        => 0,
				'created_at'           => $now,
				'modified_at'          => $now,
				// Nobody is signed in while a site is seeded. Zero rather than NULL marks the
				// row as written with its timestamps in UTC.
				'created_by'           => 0,
				'modified_by'          => 0,
			)
		);

		$location_id = (int) $wpdb->insert_id;

		if ( 0 === $location_id ) {
			return;
		}

		// Open around the clock, every day.
		for ( $day = 0; $day <= 6; $day++ ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- seeding the plugin's own table.
			$wpdb->insert(
				Db::table( 'location_hours' ),
				array(
					'location_id' => $location_id,
					'day_of_week' => $day,
					'open_time'   => '00:00:00',
					'close_time'  => '23:59:59',
					'is_closed'   => 0,
				)
			);
		}

		$type_ids = SpaceTypeSeeder::ids();

		foreach ( self::INVENTORY as $slug => $stock ) {
			if ( empty( $type_ids[ $slug ] ) ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- seeding the plugin's own table.
			$wpdb->insert(
				Db::table( 'location_spaces' ),
				array(
					'location_id'   => $location_id,
					'space_type_id' => $type_ids[ $slug ],
					'quantity'      => $stock['quantity'],
					'is_assigned'   => 0,
					'status'        => 'active',
					'created_at'    => $now,
					'modified_at'   => $now,
					// Nobody is signed in while a site is seeded. Zero rather than NULL marks the
					// row as written with its timestamps in UTC.
					'created_by'    => 0,
					'modified_by'   => 0,
				)
			);

			foreach ( array(
				'rental_day'  => $stock['day'],
				'rental_hour' => $stock['hour'],
			) as $use_type => $amount ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- seeding the plugin's own table.
				$wpdb->insert(
					Db::table( 'space_type_prices' ),
					array(
						'space_type_id' => $type_ids[ $slug ],
						'location_id'   => null,
						'use_type'      => $use_type,
						'amount'        => $amount,
					)
				);
			}
		}
	}
}
