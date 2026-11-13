<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Modules\SpaceTypes;

use PlugixaCarParking\Core\Container;
use PlugixaCarParking\Core\Modules\ModuleInterface;
use PlugixaCarParking\Modules\SpaceTypes\Http\SpaceTypesController;
use PlugixaCarParking\Modules\SpaceTypes\Repositories\PriceTable;
use PlugixaCarParking\Modules\SpaceTypes\Repositories\SpaceTypeRepository;
use PlugixaCarParking\Modules\SpaceTypes\Services\SpaceTypeService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Space types: the kinds of parking space a site offers, and what each costs.
 */
final class Module implements ModuleInterface {

	public function id(): string {
		return 'space_types';
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
		$container->get( SpaceTypesController::class )->register_routes();
	}

	public function registerHooks( Container $container ): void {
		add_filter(
			'plugixa_carparking_admin_i18n_strings',
			static fn( array $strings ): array => $strings + Strings::all()
		);

		// Before the demo car park, which gives each of these types spaces and a price.
		add_action( 'plugixa_carparking_activated', array( Seeder::class, 'run' ), 5 );

		add_filter( 'plugixa_carparking_entity_types', array( $this, 'register_entity' ) );

		add_filter(
			'plugixa_carparking_filterable_fields',
			static function ( array $fields, string $entity_type ) use ( $container ): array {
				return 'space_type' === $entity_type
					? array_merge( $fields, $container->get( SpaceTypeRepository::class )->filterable() )
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
				$writers['space_type'] = array(
					'create' => array( $container->get( SpaceTypeService::class ), 'create' ),
					'update' => array( $container->get( SpaceTypeService::class ), 'update' ),
					'delete' => array( $container->get( SpaceTypeService::class ), 'delete' ),
				);

				return $writers;
			}
		);

		// How another module's list shows "Covered" beside a space_type_id without knowing which
		// table, or which column, holds a space type's name.
		add_filter(
			'plugixa_carparking_record_names',
			static function ( array $names, string $entity_type, array $ids ) use ( $container ): array {
				return 'space_type' === $entity_type
					? $names + $container->get( SpaceTypeRepository::class )->names( $ids )
					: $names;
			},
			10,
			3
		);

		// A type's prices have no row of their own to purge and nothing else that would remove
		// them, so they go when the type is destroyed. A trashed type keeps them, to come back with.
		add_action(
			'plugixa_carparking_entity_purged',
			static function ( string $entity_type, int $id ) use ( $container ): void {
				if ( 'space_type' === $entity_type ) {
					$container->get( PriceTable::class )->replace( $id, array() );
				}
			},
			10,
			2
		);
	}

	/**
	 * Describe a space type to the generic parts of the app.
	 *
	 * @param array<string, array<string, mixed>> $entities Entity slug => descriptor.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function register_entity( array $entities ): array {
		$entities['space_type'] = array(
			'label'    => __( 'Space type', 'plugixa-car-parking' ),
			'plural'   => __( 'Space types', 'plugixa-car-parking' ),
			'api_base' => 'space-types',
			'fields'   => array(
				'name'          => array(
					'label'    => __( 'Name', 'plugixa-car-parking' ),
					'type'     => 'text',
					'required' => true,
				),
				'status'        => array(
					'label'   => __( 'Status', 'plugixa-car-parking' ),
					'type'    => 'select',
					'options' => SpaceTypeService::STATUSES,
				),
				'display_order' => array(
					'label' => __( 'Display order', 'plugixa-car-parking' ),
					'type'  => 'number',
				),
			),
		);

		return $entities;
	}
}
