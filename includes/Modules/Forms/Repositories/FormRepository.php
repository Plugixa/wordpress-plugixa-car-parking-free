<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Modules\Forms\Repositories;

use PlugixaCarParking\Core\Database\Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Booking forms: the steps and fields a customer is taken through.
 */
final class FormRepository extends Repository {

	protected function table(): string {
		return 'booking_forms';
	}

	protected function entity_type(): string {
		return 'booking_form';
	}

	/**
	 * @return string[]
	 */
	protected function fillable(): array {
		return array(
			'name',
			'slug',
			'status',
			'steps',
			'style',
			'location_ids',
			'space_type_ids',
			'is_default',
			'settings',
			'created_at',
			'created_by',
		);
	}

	/**
	 * @return string[]
	 */
	protected function json_columns(): array {
		return array( 'steps', 'style', 'location_ids', 'space_type_ids', 'settings' );
	}

	/**
	 * @return array<string, string>
	 */
	protected function casts(): array {
		return array( 'is_default' => 'bool' );
	}

	/**
	 * The form a page that names none is shown, or null on a site with no live form.
	 *
	 * @return array<string, mixed>|null
	 */
	public function default_form(): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE is_default = 1 AND deleted_at IS NULL ORDER BY id ASC LIMIT 1', $this->table_name() ),
			ARRAY_A
		);

		return is_array( $row ) ? $this->decode( $row ) : null;
	}

	/**
	 * Every live form, active or not, in name order. A site has a handful.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function live(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i WHERE deleted_at IS NULL ORDER BY name ASC, id ASC', $this->table_name() ),
			ARRAY_A
		);

		return array_map( fn( array $row ): array => $this->cast( $row ), (array) $rows );
	}

	/**
	 * Take the default mark off every form but one.
	 *
	 * @param int $keep_id The form that keeps it.
	 */
	public function clear_default_except( int $keep_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one flag across the plugin's own table.
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET is_default = 0 WHERE id <> %d AND is_default = 1', $this->table_name(), $keep_id ) );
	}

	/**
	 * Take an id out of the scope of every form that lists it.
	 *
	 * A form whose list empties this way would start offering EVERY location, the opposite of
	 * what its owner chose, so such a form is switched off instead and keeps its last id.
	 *
	 * @param string $column `location_ids` or `space_type_ids`.
	 * @param int    $id     The location or space type that went.
	 *
	 * @return int[] The forms switched off.
	 */
	public function forget_scope_id( string $column, int $id ): array {
		global $wpdb;

		if ( ! in_array( $column, array( 'location_ids', 'space_type_ids' ), true ) ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, read fresh before writing it back.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, is_default, %i AS scope FROM %i', $column, $this->table_name() ), ARRAY_A );

		$switched_off = array();

		foreach ( $rows as $row ) {
			$scope = is_string( $row['scope'] ?? null ) ? json_decode( $row['scope'], true ) : null;
			$scope = is_array( $scope ) ? array_map( 'intval', $scope ) : array();

			if ( ! in_array( $id, $scope, true ) ) {
				continue;
			}

			$left = array_values( array_diff( $scope, array( $id ) ) );

			if ( array() === $left ) {
				// The default form must keep taking bookings: it falls back to "all" instead.
				if ( 1 === (int) $row['is_default'] ) {
					$this->update( (int) $row['id'], array( $column => array() ) );
				} else {
					$this->update( (int) $row['id'], array( 'status' => 'inactive' ) );
					$switched_off[] = (int) $row['id'];
				}
				continue;
			}

			$this->update( (int) $row['id'], array( $column => $left ) );
		}

		return $switched_off;
	}

	/**
	 * @return string[]
	 */
	protected function sortable(): array {
		return array( 'id', 'name', 'status', 'is_default', 'created_at', 'modified_at' );
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public function filterable(): array {
		return array(
			'status' => array(
				'label'   => __( 'Status', 'plugixa-car-parking' ),
				'sql'     => 't.status',
				'type'    => 'select',
				'options' => array( 'active', 'inactive' ),
			),
		) + $this->common_filters();
	}

	/**
	 * What each custom field is called: field key => label.
	 *
	 * For one form, or for every form when none is named. Trashed forms are read too: a booking
	 * made on a form keeps the answers whatever happened to the form afterwards. Where two forms
	 * use the same key, the form saved first names it.
	 *
	 * @param int|null $form_id A form id, or null for every form.
	 *
	 * @return array<string, string>
	 */
	public function field_labels( ?int $form_id = null ): array {
		global $wpdb;

		if ( null === $form_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one column of the plugin's own table, trashed rows included; a form can be saved between two reads.
			$stored = $wpdb->get_col( $wpdb->prepare( 'SELECT settings FROM %i ORDER BY id ASC', $this->table_name() ) );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one column of the plugin's own table, trashed rows included; a form can be saved between two reads.
			$stored = $wpdb->get_col( $wpdb->prepare( 'SELECT settings FROM %i WHERE id = %d', $this->table_name(), $form_id ) );
		}

		$labels = array();

		foreach ( (array) $stored as $json ) {
			$settings = is_string( $json ) ? json_decode( $json, true ) : null;

			foreach ( is_array( $settings['fields'] ?? null ) ? $settings['fields'] : array() as $field ) {
				$key   = is_array( $field ) && is_scalar( $field['key'] ?? null ) ? (string) $field['key'] : '';
				$label = is_array( $field ) && is_scalar( $field['label'] ?? null ) ? (string) $field['label'] : '';

				if ( '' !== $key && '' !== $label && ! isset( $labels[ $key ] ) ) {
					$labels[ $key ] = $label;
				}
			}
		}

		return $labels;
	}
}
