<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Modules\Forms;

use PlugixaCarParking\Core\Container;
use PlugixaCarParking\Core\Modules\ModuleInterface;
use PlugixaCarParking\Modules\Forms\Http\FormsController;
use PlugixaCarParking\Modules\Forms\Repositories\FormRepository;
use PlugixaCarParking\Modules\Forms\Services\FormService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Booking forms: the configurable steps and fields a customer is taken through.
 */
final class Module implements ModuleInterface {

	public function id(): string {
		return 'forms';
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
		$container->get( FormsController::class )->register_routes();
	}

	public function registerHooks( Container $container ): void {
		add_filter(
			'plugixa_carparking_admin_i18n_strings',
			static fn( array $strings ): array => $strings + Strings::all()
		);

		add_action( 'plugixa_carparking_activated', array( Seeder::class, 'run' ) );

		// A location or space type that is trashed leaves every form that listed it, so a form
		// never claims something it cannot book. See FormRepository::forget_scope_id() for the
		// form whose list that empties.
		add_action(
			'plugixa_carparking_entity_deleted',
			static function ( string $entity, int $id ) use ( $container ): void {
				$column = array(
					'location'   => 'location_ids',
					'space_type' => 'space_type_ids',
				)[ $entity ] ?? null;

				if ( null !== $column ) {
					$container->get( FormRepository::class )->forget_scope_id( $column, $id );
				}
			},
			10,
			2
		);

		add_filter( 'plugixa_carparking_entity_types', array( $this, 'register_entity' ) );

		add_filter(
			'plugixa_carparking_filterable_fields',
			static function ( array $fields, string $entity_type ) use ( $container ): array {
				return 'booking_form' === $entity_type
					? array_merge( $fields, $container->get( FormRepository::class )->filterable() )
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
				$writers['booking_form'] = array(
					'create' => array( $container->get( FormService::class ), 'create' ),
					'update' => array( $container->get( FormService::class ), 'update' ),
					'delete' => array( $container->get( FormService::class ), 'delete' ),
				);

				return $writers;
			}
		);

		// How another module's list shows "Airport form" beside a booking_form_id without knowing
		// which table, or which column, holds a form's name.
		add_filter(
			'plugixa_carparking_record_names',
			static function ( array $names, string $entity_type, array $ids ) use ( $container ): array {
				return 'booking_form' === $entity_type
					? $names + $container->get( FormRepository::class )->names( $ids )
					: $names;
			},
			10,
			3
		);
	}

	/**
	 * Describe a booking form to the generic parts of the app.
	 *
	 * @param array<string, array<string, mixed>> $entities Entity slug => descriptor.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function register_entity( array $entities ): array {
		$entities['booking_form'] = array(
			'label'    => __( 'Booking form', 'plugixa-car-parking' ),
			'plural'   => __( 'Booking forms', 'plugixa-car-parking' ),
			'api_base' => 'booking-forms',
			'fields'   => array(
				'name'   => array(
					'label'    => __( 'Name', 'plugixa-car-parking' ),
					'type'     => 'text',
					'required' => true,
				),
				'status' => array(
					'label'   => __( 'Status', 'plugixa-car-parking' ),
					'type'    => 'select',
					'options' => FormService::STATUSES,
				),
			),
		);

		return $entities;
	}
}
