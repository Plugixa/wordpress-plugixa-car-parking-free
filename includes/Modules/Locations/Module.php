<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Modules\Locations;

use PlugixaCarParking\Core\Container;
use PlugixaCarParking\Core\Modules\ModuleInterface;
use PlugixaCarParking\Modules\Locations\Http\LocationsController;
use PlugixaCarParking\Modules\Locations\Repositories\LocationRepository;
use PlugixaCarParking\Modules\Locations\Services\LocationService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Locations: the car parks, their opening hours and the dates they are closed.
 */
final class Module implements ModuleInterface {

	public function id(): string {
		return 'locations';
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
		$container->get( LocationsController::class )->register_routes();
	}

	public function registerHooks( Container $container ): void {
		add_action( 'plugixa_carparking_activated', array( DemoSeeder::class, 'run' ) );

		add_filter( 'plugixa_carparking_entity_types', array( $this, 'register_entity' ) );

		add_filter(
			'plugixa_carparking_admin_i18n_strings',
			static fn( array $strings ): array => $strings + Strings::all()
		);

		add_filter(
			'plugixa_carparking_filterable_fields',
			static function ( array $fields, string $entity_type ) use ( $container ): array {
				return 'location' === $entity_type
					? array_merge( $fields, $container->get( LocationRepository::class )->filterable() )
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
				$writers['location'] = array(
					'create' => array( $container->get( LocationService::class ), 'create' ),
					'update' => array( $container->get( LocationService::class ), 'update' ),
					'delete' => array( $container->get( LocationService::class ), 'delete' ),
				);

				return $writers;
			}
		);

		// How another module's list shows "Main Car Park" beside a location_id without knowing
		// which table, or which column, holds a location's name.
		add_filter(
			'plugixa_carparking_record_names',
			static function ( array $names, string $entity_type, array $ids ) use ( $container ): array {
				return 'location' === $entity_type
					? $names + $container->get( LocationRepository::class )->names( $ids )
					: $names;
			},
			10,
			3
		);
	}

	/**
	 * Describe a location to the generic parts of the app.
	 *
	 * @param array<string, array<string, mixed>> $entities Entity slug => descriptor.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function register_entity( array $entities ): array {
		$entities['location'] = array(
			'label'    => __( 'Location', 'plugixa-car-parking' ),
			'plural'   => __( 'Locations', 'plugixa-car-parking' ),
			'api_base' => 'locations',
			'fields'   => array(
				'name'                 => array(
					'label'    => __( 'Name', 'plugixa-car-parking' ),
					'type'     => 'text',
					'required' => true,
				),
				'status'               => array(
					'label'   => __( 'Status', 'plugixa-car-parking' ),
					'type'    => 'select',
					'options' => LocationService::STATUSES,
				),
				'default_billing_type' => array(
					'label'   => __( 'Billing unit', 'plugixa-car-parking' ),
					'type'    => 'select',
					'options' => LocationService::BILLING_TYPES,
				),
				'city'                 => array(
					'label' => __( 'City', 'plugixa-car-parking' ),
					'type'  => 'text',
				),
				'country'              => array(
					'label' => __( 'Country', 'plugixa-car-parking' ),
					'type'  => 'text',
				),
			),
		);

		return $entities;
	}
}
