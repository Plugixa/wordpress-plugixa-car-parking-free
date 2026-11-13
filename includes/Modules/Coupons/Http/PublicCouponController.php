<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Modules\Coupons\Http;

use PlugixaCarParking\Core\Http\PublicController;
use PlugixaCarParking\Modules\Coupons\Repositories\CouponRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Checking a coupon code before a booking is placed.
 *
 * Open to a signed-out visitor, and an answer of "valid" is exactly what someone guessing codes
 * wants, so the route is tightly rate limited and needs the public nonce.
 */
final class PublicCouponController extends PublicController {

	public function __construct( private readonly CouponRepository $coupons ) {}

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/coupon/validate',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'validate_code' ),
					// Public by design: a guest checks a code before booking. Tightly rate limited, needs the public nonce, and answers only valid or not and the discount.
					'permission_callback' => '__return_true',
				),
			)
		);
	}

	/**
	 * Whether a code applies, and the discount it would take off.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function validate_code( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$guarded = $this->guard( $request, 'coupon_validate' );
		if ( $guarded instanceof WP_Error ) {
			return $guarded;
		}

		$params = $this->params( $request );

		$refused = $this->verify_nonce( $request, $params );
		if ( $refused instanceof WP_Error ) {
			return $refused;
		}

		$code     = sanitize_text_field( (string) ( $params['code'] ?? '' ) );
		$subtotal = (float) ( $params['subtotal'] ?? 0 );

		$result = $this->coupons->validate(
			$code,
			array(
				'subtotal'      => $subtotal,
				'location_id'   => (int) ( $params['location_id'] ?? 0 ),
				'space_type_id' => (int) ( $params['space_type_id'] ?? 0 ),
				// The session's user, never one the caller names: trusting the body would let
				// anyone check a per-account limit as somebody else.
				'user_id'       => get_current_user_id(),
			)
		);

		if ( ! $result['valid'] ) {
			return $this->success(
				array(
					'valid'    => false,
					'reason'   => $result['reason'],
					'discount' => 0,
				)
			);
		}

		return $this->success(
			array(
				'valid'    => true,
				'code'     => $result['coupon']['code'],
				'discount' => $this->coupons->compute_discount( $result['coupon'], $subtotal ),
			)
		);
	}
}
