<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Modules\Bookings\Http;

use PlugixaCarParking\Core\Http\PublicController;
use PlugixaCarParking\Modules\Bookings\Services\CustomerBookings;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A customer's own bookings: list them, find one as a guest, cancel one.
 *
 * Open to a signed-out visitor, because a customer who booked as a guest has no account. What
 * stands in for one is knowing both the booking's reference and the email it was made with. The
 * guest lookup is therefore a way to guess at other people's bookings, and carries the strictest
 * rate limit of any route.
 */
final class AccountController extends PublicController {

	public function __construct( private readonly CustomerBookings $customer ) {}

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/account/bookings',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'list_bookings' ),
					// Public by design: a guest booking has no account. A signed-in caller gets only their own bookings; a guest gets one booking, and only by naming its reference AND the email it was made with. Strictest rate limit of any route.
					'permission_callback' => '__return_true',
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/account/bookings/(?P<ref>[A-Za-z0-9\-]+)/cancel',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'cancel' ),
					// Public by design: a guest booking has no account. authorize() runs first: rate limit, the public nonce, and ownership (the signed-in owner, or reference + email).
					'permission_callback' => '__return_true',
				),
			)
		);
	}

	/**
	 * A signed-in customer's bookings, or the one a guest names by reference and email.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function list_bookings( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$user_id = get_current_user_id();

		$guarded = $this->guard( $request, $user_id ? 'account_list' : 'account_lookup' );
		if ( $guarded instanceof WP_Error ) {
			return $guarded;
		}

		if ( $user_id ) {
			$rows = $this->customer->for_user( $user_id );
		} else {
			$ref   = sanitize_text_field( (string) $request->get_param( 'ref' ) );
			$email = sanitize_email( (string) $request->get_param( 'email' ) );

			if ( '' === $ref || '' === $email ) {
				return $this->error( __( 'Enter your booking reference and email.', 'plugixa-car-parking' ), 422 );
			}

			$booking = $this->customer->lookup( $ref, $email );

			if ( null === $booking ) {
				return $this->error( __( 'No booking matches that reference and email.', 'plugixa-car-parking' ), 404 );
			}

			$rows = array( $booking );
		}

		return $this->success( array( 'bookings' => array_map( array( $this->customer, 'project' ), $rows ) ) );
	}

	/**
	 * Cancel the caller's own booking.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function cancel( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$booking = $this->authorize( $request );
		if ( $booking instanceof WP_Error ) {
			return $booking;
		}

		$cancelled = $this->customer->cancel( $booking );
		if ( $cancelled instanceof WP_Error ) {
			return $cancelled;
		}

		return $this->success( $this->customer->project( $cancelled ), __( 'Your booking has been cancelled.', 'plugixa-car-parking' ) );
	}

	/**
	 * The guard, the public nonce, then ownership: what every change to a customer's booking passes.
	 *
	 * Public so a module that adds another change to a booking asks the same three questions in
	 * the same order.
	 *
	 * @param WP_REST_Request $request The request, with the booking reference in its path.
	 *
	 * @return array<string, mixed>|WP_Error The booking, or the refusal.
	 */
	public function authorize( WP_REST_Request $request ): array|WP_Error {
		$guarded = $this->guard( $request, 'account_change' );
		if ( $guarded instanceof WP_Error ) {
			return $guarded;
		}

		$params = $this->params( $request );

		$refused = $this->verify_nonce( $request, $params );
		if ( $refused instanceof WP_Error ) {
			return $refused;
		}

		return $this->customer->owned(
			sanitize_text_field( (string) $request['ref'] ),
			sanitize_email( is_scalar( $params['email'] ?? null ) ? (string) $params['email'] : '' )
		);
	}
}
