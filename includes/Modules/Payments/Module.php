<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Modules\Payments;

use PlugixaCarParking\Core\Container;
use PlugixaCarParking\Core\Modules\ModuleInterface;
use PlugixaCarParking\Modules\Payments\Http\PaymentsController;
use PlugixaCarParking\Modules\Payments\Http\PublicPaymentController;
use PlugixaCarParking\Modules\Payments\Repositories\PaymentRepository;
use PlugixaCarParking\Modules\Payments\Services\GatewayRegistry;
use PlugixaCarParking\Modules\Payments\Services\PaymentService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Payments: the ledger of money received and refunded against bookings.
 */
final class Module implements ModuleInterface {

	public function id(): string {
		return 'payments';
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
		$container->get( PaymentsController::class )->register_routes();

		// What a customer's browser and the payment providers call.
		$container->get( PublicPaymentController::class )->register_routes();
	}

	public function registerHooks( Container $container ): void {
		add_filter(
			'plugixa_carparking_admin_i18n_strings',
			static fn( array $strings ): array => $strings + Strings::all()
		);

		add_filter( 'plugixa_carparking_entity_types', array( $this, 'register_entity' ) );

		// A booking whose total changed owes a different amount. Only where money has been
		// taken: with nothing paid there is nothing to weigh against the total, and the booking's
		// status is not the ledger's to change.
		add_action(
			'plugixa_carparking_booking_repriced',
			static function ( int $booking_id ) use ( $container ): void {
				if ( $container->get( PaymentRepository::class )->total_paid( $booking_id ) > 0 ) {
					$container->get( PaymentService::class )->refresh_booking_paid_state( $booking_id );
				}
			}
		);

		add_filter(
			'plugixa_carparking_filterable_fields',
			static function ( array $fields, string $entity_type ) use ( $container ): array {
				if ( 'payment' !== $entity_type ) {
					return $fields;
				}

				$own = $container->get( PaymentRepository::class )->filterable();

				// The gateways installed now, as well as the ones the ledger already names: a
				// gateway that has taken no payment yet is still one a list can be narrowed to.
				$own['gateway']['options'] = array_values(
					array_unique(
						array_merge(
							array_keys( $container->get( GatewayRegistry::class )->all() ),
							$own['gateway']['options']
						)
					)
				);

				return array_merge( $fields, $own );
			},
			10,
			2
		);

		/*
		 * NO `plugixa_carparking_record_writers` ENTRY, ON PURPOSE. That hook is how a mass action
		 * creates, changes and trashes records of a type it knows only by name. A payment is money
		 * that was received or refunded: it is recorded one at a time against a booking, and a row
		 * is never changed or removed, so none of the three has a meaning here.
		 */

		// A payment attempt has no life without its booking. Only attempts that never completed
		// can be reached here: a booking with completed money against it is never destroyed.
		add_action(
			'plugixa_carparking_entity_purged',
			static function ( string $entity_type, int $id ) use ( $container ): void {
				if ( 'booking' === $entity_type ) {
					$container->get( PaymentRepository::class )->delete_for_booking( $id );
				}
			},
			10,
			2
		);

		// How another module's list shows a payment's gateway reference beside a payment_id.
		add_filter(
			'plugixa_carparking_record_names',
			static function ( array $names, string $entity_type, array $ids ) use ( $container ): array {
				return 'payment' === $entity_type
					? $names + $container->get( PaymentRepository::class )->names( $ids )
					: $names;
			},
			10,
			3
		);
	}

	/**
	 * Describe a payment to the generic parts of the app.
	 *
	 * @param array<string, array<string, mixed>> $entities Entity slug => descriptor.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function register_entity( array $entities ): array {
		$entities['payment'] = array(
			'label'    => __( 'Payment', 'plugixa-car-parking' ),
			'plural'   => __( 'Payments', 'plugixa-car-parking' ),
			'api_base' => 'payments',
			'fields'   => array(
				'gateway' => array(
					'label' => __( 'Gateway', 'plugixa-car-parking' ),
					'type'  => 'text',
				),
				'type'    => array(
					'label'   => __( 'Type', 'plugixa-car-parking' ),
					'type'    => 'select',
					'options' => array_merge( Services\PaymentAdminService::types(), array( 'refund' ) ),
				),
				'status'  => array(
					'label'   => __( 'Status', 'plugixa-car-parking' ),
					'type'    => 'select',
					'options' => array( 'completed', 'pending', 'failed' ),
				),
				'amount'  => array(
					'label' => __( 'Amount', 'plugixa-car-parking' ),
					'type'  => 'number',
				),
			),
		);

		return $entities;
	}
}
