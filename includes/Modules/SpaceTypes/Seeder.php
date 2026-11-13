<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Modules\SpaceTypes;

use PlugixaCarParking\Core\Database\Schema as Db;
use PlugixaCarParking\Core\Database\SlugSet;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The four space types a new install starts with.
 */
final class Seeder {

	/**
	 * Slug => what the type is.
	 *
	 * @var array<string, array{name: string, icon: string, color: string, features: array<string, bool>}>
	 */
	private const TYPES = array(
		'standard' => array(
			'name'     => 'Standard',
			'icon'     => 'directions_car',
			'color'    => '#2271b1',
			'features' => array(),
		),
		'covered'  => array(
			'name'     => 'Covered',
			'icon'     => 'garage',
			'color'    => '#2e7d32',
			'features' => array( 'covered' => true ),
		),
		'disabled' => array(
			'name'     => 'Disabled',
			'icon'     => 'accessible',
			'color'    => '#0288d1',
			'features' => array( 'disabled' => true ),
		),
		'electric' => array(
			'name'     => 'Electric',
			'icon'     => 'ev_station',
			'color'    => '#ed6c02',
			'features' => array( 'ev_charger' => true ),
		),
	);

	/**
	 * Seed the starter types, unless the site already has any.
	 *
	 * "Any" includes trashed ones: somebody who cleared the list did so on purpose.
	 */
	public static function run(): void {
		global $wpdb;

		$table = Db::table( 'space_types' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off check on activation.
		if ( (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) ) > 0 ) {
			return;
		}

		$now   = current_time( 'mysql', true );
		$order = 0;

		foreach ( self::TYPES as $slug => $type ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- seeding the plugin's own table.
			$wpdb->insert(
				$table,
				array(
					'name'           => $type['name'],
					'slug'           => $slug,
					'icon'           => $type['icon'],
					'color'          => $type['color'],
					'dimension_unit' => 'm',
					'features'       => wp_json_encode( $type['features'] ),
					'display_order'  => $order++,
					'status'         => 'active',
					'created_at'     => $now,
					'modified_at'    => $now,
					// Nobody is signed in while a site is seeded. Zero rather than NULL marks the
					// row as written with its timestamps in UTC.
					'created_by'     => 0,
					'modified_by'    => 0,
				)
			);
		}
	}

	/**
	 * The id of each starter type that exists, keyed by slug.
	 *
	 * @return array<string, int>
	 */
	public static function ids(): array {
		global $wpdb;

		$slugs = array_keys( self::TYPES );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off read on activation.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, slug FROM %i WHERE FIND_IN_SET( slug, %s )',
				Db::table( 'space_types' ),
				SlugSet::csv( $slugs )
			),
			ARRAY_A
		);

		$ids = array();
		foreach ( (array) $rows as $row ) {
			$ids[ (string) $row['slug'] ] = (int) $row['id'];
		}

		return $ids;
	}
}
