<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Modules\Forms;

use PlugixaCarParking\Core\Database\Schema as Db;
use PlugixaCarParking\Modules\Forms\Services\FormService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The booking form a new install starts with.
 */
final class Seeder {

	/**
	 * Seed the default form, unless the site already has any form, trashed ones included.
	 */
	public static function run(): void {
		global $wpdb;

		$table = Db::table( 'booking_forms' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off check on activation.
		if ( (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) ) > 0 ) {
			return;
		}

		$now = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- seeding the plugin's own table.
		$wpdb->insert(
			$table,
			array(
				'name'         => 'Default Booking Form',
				'slug'         => 'default',
				'status'       => 'active',
				// No steps of its own: a form without any is answered with the standard ones,
				// so the starter form follows them if a later version changes what they are.
				'steps'        => wp_json_encode( array() ),
				'style'        => wp_json_encode(
					array(
						'primary_color' => FormService::DEFAULT_COLOR,
						'design'        => FormService::DESIGNS[0],
					)
				),
				'location_ids' => wp_json_encode( array() ),
				'is_default'   => 1,
				'created_at'   => $now,
				'modified_at'  => $now,
				// Nobody is signed in while a site is seeded. Zero rather than NULL marks the
				// row as written with its timestamps in UTC.
				'created_by'   => 0,
				'modified_by'  => 0,
			)
		);
	}
}
