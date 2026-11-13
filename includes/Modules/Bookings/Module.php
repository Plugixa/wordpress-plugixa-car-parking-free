<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Modules\Bookings;

use PlugixaCarParking\Core\Container;
use PlugixaCarParking\Core\Modules\ModuleInterface;
use PlugixaCarParking\Modules\Bookings\Http\AccountController;
use PlugixaCarParking\Modules\Bookings\Http\BookingsController;
use PlugixaCarParking\Modules\Bookings\Http\PublicBookingController;
use PlugixaCarParking\Modules\Bookings\Http\SetupController;
use PlugixaCarParking\Modules\Bookings\Repositories\BookingRepository;
use PlugixaCarParking\Modules\Bookings\Repositories\ItemTable;
use PlugixaCarParking\Modules\Bookings\Services\Availability;
use PlugixaCarParking\Modules\Bookings\Services\BookingAdminService;
use PlugixaCarParking\Modules\Bookings\Services\HoldExpiry;
use PlugixaCarParking\Modules\Bookings\Services\ModeRegistry;
use PlugixaCarParking\Modules\Payments\Repositories\PaymentRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bookings: the reservation record, its priced line items, and the engine that creates them.
 *
 * The engine lives here rather than in a module of its own because every part of it — what is
 * free, what it costs, writing it down — exists to produce a booking.
 */
final class Module implements ModuleInterface {

	public function id(): string {
		return 'bookings';
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
		$container->get( BookingsController::class )->register_routes();

		// What the public pages call: the booking wizard, and a customer's own bookings.
		$container->get( PublicBookingController::class )->register_routes();
		$container->get( AccountController::class )->register_routes();

		// The admin's view of what each location and each form offers.
		$container->get( SetupController::class )->register_routes();
	}

	public function registerHooks( Container $container ): void {
		add_filter(
			'plugixa_carparking_admin_i18n_strings',
			static fn( array $strings ): array => $strings + Strings::all()
		);

		$container->get( HoldExpiry::class )->register_hooks();

		// A location, a space type or an identified space that bookings still to come rely on stays
		// until those bookings are moved or cancelled. Trashing it would leave a customer holding
		// a booking for a car park the site no longer shows.
		$blockers = array(
			'location'       => 'location_id',
			'space_type'     => 'space_type_id',
			'location_space' => 'space_id',
		);

		foreach ( $blockers as $entity => $column ) {
			add_filter(
				'plugixa_carparking_' . $entity . '_delete_blockers',
				static function ( array $reasons, int $id ) use ( $container, $column ): array {
					$count = $container->get( BookingRepository::class )->upcoming_count( $column, $id, $container->get( Availability::class )->hold_statuses() );

					if ( $count > 0 ) {
						$reasons[] = sprintf(
							/* translators: %d: how many bookings. */
							_n(
								'%d upcoming booking still uses it. Move or cancel it first, or switch this off instead of trashing it.',
								'%d upcoming bookings still use it. Move or cancel them first, or switch this off instead of trashing it.',
								$count,
								'plugixa-car-parking'
							),
							$count
						);
					}

					return $reasons;
				},
				10,
				2
			);
		}

		// The ways a location can count its spaces, for the form that offers the choice. Keys
		// only: whatever registered a mode registers its name with the app's strings.
		add_filter(
			'plugixa_carparking_admin_config',
			static function ( array $config ) use ( $container ): array {
				$config['inventoryModes'] = array_map( 'strval', array_keys( $container->get( ModeRegistry::class )->strategies() ) );

				return $config;
			}
		);

		add_filter(
			'plugixa_carparking_entity_types',
			static function ( array $entities ) use ( $container ): array {
				$entities['booking'] = self::entity( $container->get( BookingRepository::class )->statuses() );

				return $entities;
			}
		);

		add_filter(
			'plugixa_carparking_filterable_fields',
			static function ( array $fields, string $entity_type ) use ( $container ): array {
				return 'booking' === $entity_type
					? array_merge( $fields, $container->get( BookingRepository::class )->filterable() )
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
				$writers['booking'] = array(
					'create' => array( $container->get( BookingAdminService::class ), 'create' ),
					'update' => array( $container->get( BookingAdminService::class ), 'update' ),
					'delete' => array( $container->get( BookingAdminService::class ), 'delete' ),
				);

				return $writers;
			}
		);

		// How a payment's list shows "PKG-7F3A91" beside a booking_id.
		add_filter(
			'plugixa_carparking_record_names',
			static function ( array $names, string $entity_type, array $ids ) use ( $container ): array {
				return 'booking' === $entity_type
					? $names + $container->get( BookingRepository::class )->names( $ids )
					: $names;
			},
			10,
			3
		);

		// The two refusals below are also made, with their reasons, by the services that own
		// them. They are repeated here because a filter on the repository is the one place every
		// permanent delete passes, whichever route asked for it.
		add_filter(
			'plugixa_carparking_can_purge',
			static function ( mixed $allowed, string $entity_type, int $id ) use ( $container ): mixed {
				// A booking with money recorded against it stays, so the ledger always has a
				// booking to point at.
				if ( 'booking' === $entity_type && $container->get( PaymentRepository::class )->has_completed( $id ) ) {
					return false;
				}

				// A location that has ever been booked stays, trashed bookings included: each of
				// them names it.
				if ( 'location' === $entity_type && $container->get( BookingRepository::class )->exists_for_location( $id ) ) {
					return false;
				}

				return $allowed;
			},
			10,
			3
		);

		// A booking's lines have no life without it. Removed once the booking is gone, so a purge
		// that was refused leaves them where they were.
		add_action(
			'plugixa_carparking_entity_purged',
			static function ( string $entity_type, int $id ) use ( $container ): void {
				if ( 'booking' === $entity_type ) {
					$container->get( ItemTable::class )->delete_for_booking( $id );
				}
			},
			10,
			2
		);
	}

	/**
	 * A booking, described to the generic parts of the app.
	 *
	 * @param array<string, string> $statuses The site's booking statuses, key => label.
	 *
	 * @return array<string, mixed>
	 */
	private static function entity( array $statuses ): array {
		return array(
			'label'    => __( 'Booking', 'plugixa-car-parking' ),
			'plural'   => __( 'Bookings', 'plugixa-car-parking' ),
			'api_base' => 'bookings',
			'fields'   => array(
				'status'         => array(
					'label'   => __( 'Status', 'plugixa-car-parking' ),
					'type'    => 'select',
					'options' => array_keys( $statuses ),
				),
				'customer_name'  => array(
					'label' => __( 'Customer', 'plugixa-car-parking' ),
					'type'  => 'text',
				),
				'customer_email' => array(
					'label' => __( 'Email', 'plugixa-car-parking' ),
					'type'  => 'text',
				),
				'customer_phone' => array(
					'label' => __( 'Phone', 'plugixa-car-parking' ),
					'type'  => 'text',
				),
				'license_plate'  => array(
					'label' => __( 'Licence plate', 'plugixa-car-parking' ),
					'type'  => 'text',
				),
				'notes'          => array(
					'label' => __( 'Notes', 'plugixa-car-parking' ),
					'type'  => 'textarea',
				),
			),
		);
	}
}
