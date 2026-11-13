<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Modules\Spaces\Repositories;

use PlugixaCarParking\Core\Database\Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Location spaces: the inventory of each location.
 *
 * One row with a quantity is a pool of interchangeable spaces; a row marked `is_assigned` with a
 * label is one identified space. Both feed the same availability sums.
 *
 * Every read here excludes trashed rows: a trashed space is one the site no longer has, and
 * counting it would sell a space that is not there.
 */
final class SpaceRepository extends Repository {

	protected function table(): string {
		return 'location_spaces';
	}

	protected function entity_type(): string {
		return 'location_space';
	}

	/**
	 * @return string[]
	 */
	protected function fillable(): array {
		return array(
			'location_id',
			'space_type_id',
			'label',
			'quantity',
			'is_assigned',
			'status',
			'display_order',
			'meta',
			'created_at',
			'created_by',
		);
	}

	/**
	 * @return string[]
	 */
	protected function json_columns(): array {
		return array( 'meta' );
	}

	protected function name_column(): string {
		return 'label';
	}

	/**
	 * @return array<string, string>
	 */
	protected function casts(): array {
		return array(
			'location_id'   => 'int',
			'space_type_id' => 'int',
			'quantity'      => 'int',
			'is_assigned'   => 'bool',
			'display_order' => 'int',
		);
	}

	/**
	 * @return string[]
	 */
	protected function sortable(): array {
		return array( 'id', 'label', 'quantity', 'status', 'display_order', 'created_at', 'modified_at' );
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public function filterable(): array {
		return array(
			'location_id'   => array(
				'label'  => __( 'Location', 'plugixa-car-parking' ),
				'sql'    => 't.location_id',
				'type'   => 'entity',
				'entity' => 'location',
			),
			'space_type_id' => array(
				'label'  => __( 'Space type', 'plugixa-car-parking' ),
				'sql'    => 't.space_type_id',
				'type'   => 'entity',
				'entity' => 'space_type',
			),
			'status'        => array(
				'label'   => __( 'Status', 'plugixa-car-parking' ),
				'sql'     => 't.status',
				'type'    => 'select',
				'options' => array( 'active', 'inactive' ),
			),
		) + $this->common_filters();
	}

	/**
	 * Every active inventory row of a location, optionally of one space type, in display order.
	 *
	 * @param int      $location_id   The location.
	 * @param int|null $space_type_id Restrict to one space type.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function for_location( int $location_id, ?int $space_type_id = null ): array {
		global $wpdb;

		if ( null === $space_type_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- inventory read for availability; must reflect the live row set.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM %i WHERE location_id = %d AND status = 'active' AND deleted_at IS NULL ORDER BY display_order ASC, id ASC",
					$this->table_name(),
					$location_id
				),
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- inventory read for availability; must reflect the live row set.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM %i WHERE location_id = %d AND space_type_id = %d AND status = 'active' AND deleted_at IS NULL ORDER BY display_order ASC, id ASC",
					$this->table_name(),
					$location_id,
					$space_type_id
				),
				ARRAY_A
			);
		}

		return array_map( array( $this, 'decode' ), (array) $rows );
	}

	/**
	 * How many spaces of a type a location has: the sum of its active rows.
	 *
	 * @param int $location_id   The location.
	 * @param int $space_type_id The space type.
	 */
	public function total_capacity( int $location_id, int $space_type_id ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- inventory read for availability; must reflect the live row set.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(quantity), 0) FROM %i WHERE location_id = %d AND space_type_id = %d AND status = 'active' AND deleted_at IS NULL",
				$this->table_name(),
				$location_id,
				$space_type_id
			)
		);
	}

	/**
	 * The space types a location has any active space of.
	 *
	 * @param int $location_id The location.
	 *
	 * @return int[]
	 */
	public function space_type_ids( int $location_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- inventory read for availability; must reflect the live row set.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT space_type_id FROM %i WHERE location_id = %d AND status = 'active' AND deleted_at IS NULL",
				$this->table_name(),
				$location_id
			)
		);

		return array_map( 'intval', (array) $ids );
	}
}
