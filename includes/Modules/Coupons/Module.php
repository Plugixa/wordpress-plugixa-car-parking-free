<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Modules\Coupons;

use PlugixaCarParking\Core\Container;
use PlugixaCarParking\Core\Modules\ModuleInterface;
use PlugixaCarParking\Modules\Coupons\Http\CouponsController;
use PlugixaCarParking\Modules\Coupons\Http\PublicCouponController;
use PlugixaCarParking\Modules\Coupons\Repositories\CouponRepository;
use PlugixaCarParking\Modules\Coupons\Services\CouponService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Coupons: discount codes and the log of their redemptions.
 */
final class Module implements ModuleInterface {

	public function id(): string {
		return 'coupons';
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
		$container->get( CouponsController::class )->register_routes();
		$container->get( PublicCouponController::class )->register_routes();
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
				return 'coupon' === $entity_type
					? array_merge( $fields, $container->get( CouponRepository::class )->filterable() )
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
				$writers['coupon'] = array(
					'create' => array( $container->get( CouponService::class ), 'create' ),
					'update' => array( $container->get( CouponService::class ), 'update' ),
					'delete' => array( $container->get( CouponService::class ), 'delete' ),
				);

				return $writers;
			}
		);

		// How another module's list shows "SUMMER10" beside a coupon_id without knowing which
		// table, or which column, holds a coupon's code.
		add_filter(
			'plugixa_carparking_record_names',
			static function ( array $names, string $entity_type, array $ids ) use ( $container ): array {
				return 'coupon' === $entity_type
					? $names + $container->get( CouponRepository::class )->names( $ids )
					: $names;
			},
			10,
			3
		);
	}

	/**
	 * Describe a coupon to the generic parts of the app.
	 *
	 * @param array<string, array<string, mixed>> $entities Entity slug => descriptor.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function register_entity( array $entities ): array {
		$entities['coupon'] = array(
			'label'    => __( 'Coupon', 'plugixa-car-parking' ),
			'plural'   => __( 'Coupons', 'plugixa-car-parking' ),
			'api_base' => 'coupons',
			'fields'   => array(
				'code'   => array(
					'label'    => __( 'Code', 'plugixa-car-parking' ),
					'type'     => 'text',
					'required' => true,
				),
				'type'   => array(
					'label'   => __( 'Discount type', 'plugixa-car-parking' ),
					'type'    => 'select',
					'options' => CouponService::TYPES,
				),
				'amount' => array(
					'label' => __( 'Amount', 'plugixa-car-parking' ),
					'type'  => 'number',
				),
				'status' => array(
					'label'   => __( 'Status', 'plugixa-car-parking' ),
					'type'    => 'select',
					'options' => CouponService::STATUSES,
				),
			),
		);

		return $entities;
	}
}
