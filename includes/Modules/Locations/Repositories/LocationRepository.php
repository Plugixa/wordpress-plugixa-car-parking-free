<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Modules\Locations\Repositories;

use PlugixaCarParking\Core\Database\Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Locations: the car parks.
 */
final class LocationRepository extends Repository {

	protected function table(): string {
		return 'locations';
	}

	protected function entity_type(): string {
		return 'location';
	}

	/**
	 * @return string[]
	 */
	protected function fillable(): array {
		return array(
			'language_code',
			'translation_group',
			'name',
			'slug',
			'description',
			'status',
			'inventory_mode',
			'default_billing_type',
			'currency',
			'timezone',
			'address_line1',
			'address_line2',
			'city',
			'state',
			'postcode',
			'country',
			'latitude',
			'longitude',
			'phone',
			'email',
			'image_id',
			'gallery',
			'settings',
			'display_order',
			'created_at',
			'created_by',
		);
	}

	/**
	 * @return string[]
	 */
	protected function json_columns(): array {
		return array( 'gallery', 'settings' );
	}

	/**
	 * @return array<string, string>
	 */
	protected function casts(): array {
		return array(
			'latitude'      => 'float',
			'longitude'     => 'float',
			'image_id'      => 'int',
			'display_order' => 'int',
		);
	}

	/**
	 * @return string[]
	 */
	protected function sortable(): array {
		return array( 'id', 'name', 'status', 'city', 'display_order', 'created_at', 'modified_at' );
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public function filterable(): array {
		return array(
			'status'               => array(
				'label'   => __( 'Status', 'plugixa-car-parking' ),
				'sql'     => 't.status',
				'type'    => 'select',
				'options' => array( 'active', 'inactive' ),
			),
			'default_billing_type' => array(
				'label'   => __( 'Billing unit', 'plugixa-car-parking' ),
				'sql'     => 't.default_billing_type',
				'type'    => 'select',
				'options' => array( 'minute', 'hour', 'day', 'week', 'month' ),
			),
			'city'                 => array(
				'label' => __( 'City', 'plugixa-car-parking' ),
				'sql'   => 't.city',
				'type'  => 'text',
			),
			'country'              => array(
				'label' => __( 'Country', 'plugixa-car-parking' ),
				'sql'   => 't.country',
				'type'  => 'text',
			),
		) + $this->common_filters();
	}

	/**
	 * Active locations, in display order.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function active(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE status = 'active' AND deleted_at IS NULL ORDER BY display_order ASC, id ASC",
				$this->table_name()
			),
			ARRAY_A
		);

		return array_map( array( $this, 'decode' ), (array) $rows );
	}
}
