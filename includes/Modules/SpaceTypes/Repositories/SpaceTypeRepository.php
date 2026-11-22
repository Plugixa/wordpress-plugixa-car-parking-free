<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Modules\SpaceTypes\Repositories;

use PlugixaCarParking\Core\Database\Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Space types: the kinds of parking space a site offers.
 */
final class SpaceTypeRepository extends Repository {

	protected function table(): string {
		return 'space_types';
	}

	protected function entity_type(): string {
		return 'space_type';
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
			'icon',
			'color',
			'badge',
			'width',
			'length',
			'dimension_unit',
			'features',
			'display_order',
			'status',
			'created_at',
			'created_by',
		);
	}

	/**
	 * @return string[]
	 */
	protected function json_columns(): array {
		return array( 'features' );
	}

	/**
	 * @return array<string, string>
	 */
	protected function casts(): array {
		return array(
			'width'         => 'float',
			'length'        => 'float',
			'display_order' => 'int',
		);
	}

	/**
	 * @return string[]
	 */
	protected function sortable(): array {
		return array( 'id', 'name', 'status', 'display_order', 'created_at', 'modified_at' );
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
}
