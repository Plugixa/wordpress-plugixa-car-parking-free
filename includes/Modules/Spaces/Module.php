<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Modules\Spaces;

use PlugixaCarParking\Core\Container;
use PlugixaCarParking\Core\Modules\ModuleInterface;
use PlugixaCarParking\Modules\Spaces\Http\SpacesController;
use PlugixaCarParking\Modules\Spaces\Repositories\SpaceRepository;
use PlugixaCarParking\Modules\Spaces\Services\SpaceService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Spaces: how many spaces of each type a location has.
 */
final class Module implements ModuleInterface {

	public function id(): string {
		return 'spaces';
	}

	public function migrate(): void {
		Schema::install();
	}

	/**
	 * @return array<string, string[]>
	 */
	public function capabilities(): array {
		return array();
	}

	public function registerServices( Container $container ): void {
		unset( $container );
	}

	public function registerRoutes( Container $container ): void {
		$container->get( SpacesController::class )->register_routes();
	}

	public function registerHooks( Container $container ): void {
		add_filter(
			'plugixa_carparking_admin_i18n_strings',
			static fn( array $strings ): array => $strings + Strings::all()
		);

		add_filter( 'plugixa_carparking_entity_types', array( $this, 'register_entity' ) );

		add_filter(
			'plugixa_carparking_filterable_fields',
			static function ( array $fields, string $entity_type ) use ( $container ): array {
				return 'location_space' === $entity_type
					? array_merge( $fields, $container->get( SpaceRepository::class )->filterable() )
					: $fields;
			},
			10,
			2
		);

		// How a mass action, or anything else that knows a record only by its entity type,
		// reaches this module's rules instead of writing to the table past them.
		add_filter(
			'plugixa_carparking_record_writers',
			static function ( array $writers ) use ( $container ): array {
				$writers['location_space'] = array(
					'create' => array( $container->get( SpaceService::class ), 'create' ),
					'update' => array( $container->get( SpaceService::class ), 'update' ),
					'delete' => array( $container->get( SpaceService::class ), 'delete' ),
				);

				return $writers;
			}
		);

		// How another module's list shows "Bay 14" beside a space_id without knowing which table,
		// or which column, holds a space's label.
		add_filter(
			'plugixa_carparking_record_names',
			static function ( array $names, string $entity_type, array $ids ) use ( $container ): array {
				return 'location_space' === $entity_type
					? $names + $container->get( SpaceRepository::class )->names( $ids )
					: $names;
			},
			10,
			3
		);
	}

	/**
	 * Describe a space to the generic parts of the app.
	 *
	 * @param array<string, array<string, mixed>> $entities Entity slug => descriptor.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function register_entity( array $entities ): array {
		$entities['location_space'] = array(
			'label'    => __( 'Space', 'plugixa-car-parking' ),
			'plural'   => __( 'Spaces', 'plugixa-car-parking' ),
			'api_base' => 'location-spaces',
			'fields'   => array(
				'location_id'   => array(
					'label'    => __( 'Location', 'plugixa-car-parking' ),
					'type'     => 'entity',
					'entity'   => 'location',
					'required' => true,
				),
				'space_type_id' => array(
					'label'    => __( 'Space type', 'plugixa-car-parking' ),
					'type'     => 'entity',
					'entity'   => 'space_type',
					'required' => true,
				),
				'label'         => array(
					'label' => __( 'Label', 'plugixa-car-parking' ),
					'type'  => 'text',
				),
				'quantity'      => array(
					'label' => __( 'Quantity', 'plugixa-car-parking' ),
					'type'  => 'number',
				),
				'status'        => array(
					'label'   => __( 'Status', 'plugixa-car-parking' ),
					'type'    => 'select',
					'options' => SpaceService::STATUSES,
				),
			),
		);

		return $entities;
	}
}
