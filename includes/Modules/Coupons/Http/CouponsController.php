<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Modules\Coupons\Http;

use PlugixaCarParking\Core\Database\Repository;
use PlugixaCarParking\Core\Http\CrudController;
use PlugixaCarParking\Modules\Coupons\Repositories\CouponRepository;
use PlugixaCarParking\Modules\Coupons\Services\CouponService;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Coupons over REST: the seven record routes.
 */
final class CouponsController extends CrudController {

	public function __construct(
		private readonly CouponService $service,
		private readonly CouponRepository $coupons
	) {}

	protected function entity_type(): string {
		return 'coupon';
	}

	protected function api_base(): string {
		return 'coupons';
	}

	protected function repository(): Repository {
		return $this->coupons;
	}

	protected function service(): object {
		return $this->service;
	}

	/**
	 * A coupon has no name: the code is what it is known and searched by.
	 *
	 * @return string[]
	 */
	protected function search_columns(): array {
		return array( 't.code' );
	}

	/**
	 * Through the service, which asks every other module whether the coupon is still in use.
	 *
	 * @param int $id Coupon id.
	 *
	 * @return bool|WP_Error
	 */
	protected function do_trash( int $id ): bool|WP_Error {
		return $this->service->delete( $id );
	}

	/**
	 * @param int $id Coupon id.
	 *
	 * @return bool|WP_Error
	 */
	protected function do_restore( int $id ): bool|WP_Error {
		return $this->service->restore( $id );
	}
}
