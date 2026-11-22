<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Modules\Bookings\Http;

use PlugixaCarParking\Core\Http\PublicController;
use PlugixaCarParking\Modules\Bookings\Services\Availability;
use PlugixaCarParking\Modules\Bookings\Services\BookingService;
use PlugixaCarParking\Modules\Bookings\Services\Offer;
use PlugixaCarParking\Modules\Bookings\Services\Quote;
use PlugixaCarParking\Modules\Forms\Services\FormScope;
use PlugixaCarParking\Modules\Invoice\Services\Invoice;
use PlugixaCarParking\Modules\Payments\Services\PaymentKey;
use PlugixaCarParking\Modules\SpaceTypes\Repositories\SpaceTypeRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The three routes the booking wizard calls: what is free, what it costs, and book it.
 *
 * All three are open to a signed-out visitor. Availability and a quote change nothing, so the
 * rate limit is their only guard. Creating a booking also needs the public nonce and an empty
 * honeypot field.
 */
final class PublicBookingController extends PublicController {

	/**
	 * The form field a person never sees. A request that fills it was not sent by the wizard.
	 */
	public const HONEYPOT = 'pcp_hp';

	public function __construct(
		private readonly Availability $availability,
		private readonly Quote $quote,
		private readonly BookingService $bookings,
		private readonly SpaceTypeRepository $space_types,
		private readonly Offer $offer,
		private readonly FormScope $forms
	) {}

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/availability',
			array(
				array(
					'methods'             => 'GET, POST',
					'callback'            => array( $this, 'availability' ),
					// Public by design: the booking wizard asks it before anyone signs in. Changes nothing and returns only free counts and prices the wizard shows anyway; rate limited.
					'permission_callback' => '__return_true',
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/quote',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'quote' ),
					// Public by design: the wizard prices a stay before anyone signs in. Changes nothing; rate limited.
					'permission_callback' => '__return_true',
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/booking',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create_booking' ),
					// Public by design: a visitor may book without an account. Rate limited, and refused without the public nonce or with the honeypot field filled.
					'permission_callback' => '__return_true',
				),
			)
		);
	}

	/**
	 * What is free at a location in a window, with a price for one space of each type.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function availability( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$guarded = $this->guard( $request, 'availability' );
		if ( $guarded instanceof WP_Error ) {
			return $guarded;
		}

		$location_id   = (int) $request->get_param( 'location_id' );
		$entry         = $this->normalize_datetime( (string) $request->get_param( 'entry' ) );
		$exit          = $this->normalize_datetime( (string) $request->get_param( 'exit' ) );
		$space_type_id = $request->get_param( 'space_type_id' ) ? (int) $request->get_param( 'space_type_id' ) : null;

		if ( ! $location_id || '' === $entry || '' === $exit ) {
			return $this->error( __( 'location_id, entry and exit are required.', 'plugixa-car-parking' ), 422 );
		}

		$scope = $this->scope( $request->get_params(), $location_id, $space_type_id );
		if ( $scope instanceof WP_Error ) {
			return $scope;
		}

		$refused = $this->availability->validate_window( $entry, $exit ) ?? $this->availability->check_open( $location_id, $entry, $exit );
		if ( $refused instanceof WP_Error ) {
			return $refused;
		}

		$offered = $this->offer->space_type_ids( $scope['location'], $scope['form'] );
		$rows    = array_values(
			array_filter(
				$this->availability->for_location( $location_id, $entry, $exit, $space_type_id ),
				static fn( array $row ): bool => in_array( (int) $row['space_type_id'], $offered, true )
			)
		);

		foreach ( $rows as &$row ) {
			$type = $this->space_types->find( (int) $row['space_type_id'], true );

			$row['space_type_name'] = $type['name'] ?? null;
			$row['icon']            = $type['icon'] ?? null;
			$row['color']           = $type['color'] ?? null;
			$row['badge']           = $type['badge'] ?? null;

			// What one space costs across the window, to show beside the type.
			$preview = $this->quote->build(
				array(
					'location_id'   => $location_id,
					'space_type_id' => (int) $row['space_type_id'],
					'entry'         => $entry,
					'exit'          => $exit,
					'quantity'      => 1,
				)
			);

			$row['price_preview'] = $preview['total'];
			$row['currency']      = $preview['currency'];
			$row['units']         = $preview['units'];
			$row['billing_type']  = $preview['billing_type'];
		}
		unset( $row );

		$data = array(
			'location_id'  => $location_id,
			'entry'        => $entry,
			'exit'         => $exit,
			'availability' => $rows,
		);

		/**
		 * Add to what the booking form is told about a location and a window.
		 *
		 * A module that puts a choice of its own on the form sends what it offers here, under a
		 * key of its own. The four keys above are the form's and are restored if removed.
		 *
		 * @param array<string, mixed> $data        The answer so far.
		 * @param int                  $location_id The location.
		 * @param string               $entry       Requested entry, Y-m-d H:i:s.
		 * @param string               $exit        Requested exit, Y-m-d H:i:s.
		 */
		$added = apply_filters( 'plugixa_carparking_availability_response', $data, $location_id, $entry, $exit );

		return $this->success( is_array( $added ) ? array_merge( $added, $data ) : $data );
	}

	/**
	 * The full price of a selection.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function quote( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$guarded = $this->guard( $request, 'quote' );
		if ( $guarded instanceof WP_Error ) {
			return $guarded;
		}

		$params = $this->params( $request );

		// A quote says whether a coupon code works, so one that carries a code is counted a
		// second time, against a limit that makes guessing codes through here slow.
		if ( isset( $params['coupon'] ) && is_scalar( $params['coupon'] ) && '' !== trim( (string) $params['coupon'] ) ) {
			$guarded = $this->guard( $request, 'quote_coupon' );
			if ( $guarded instanceof WP_Error ) {
				return $guarded;
			}
		}

		$refused = $this->availability->validate_window( (string) ( $params['entry'] ?? '' ), (string) ( $params['exit'] ?? '' ) );
		if ( $refused instanceof WP_Error ) {
			return $refused;
		}

		$scope = $this->scope( $params, (int) ( $params['location_id'] ?? 0 ), (int) ( $params['space_type_id'] ?? 0 ) );
		if ( $scope instanceof WP_Error ) {
			return $scope;
		}

		return $this->success( $this->quote->build( $this->priced( $this->held_to( $params, $scope['form'] ) ) ) );
	}

	/**
	 * The fields a visitor may price a stay with, named one by one.
	 *
	 * The pricing engine also reads fields only the plugin itself may set, such as the coupon a
	 * stored booking already redeemed, which it applies without asking whether that coupon is
	 * still valid. Passed the raw request, a visitor could set them. So the request is rebuilt
	 * from the fields the wizard sends, as creating a booking and the admin quote do.
	 *
	 * @param array<string, mixed> $params The request's parameters, held to the form.
	 *
	 * @return array<string, mixed>
	 */
	private function priced( array $params ): array {
		return array(
			'location_id'   => (int) ( $params['location_id'] ?? 0 ),
			'space_type_id' => (int) ( $params['space_type_id'] ?? 0 ),
			'entry'         => (string) ( $params['entry'] ?? '' ),
			'exit'          => (string) ( $params['exit'] ?? '' ),
			'quantity'      => is_numeric( $params['quantity'] ?? null ) ? max( 1, (int) $params['quantity'] ) : 1,
			'coupon'        => is_scalar( $params['coupon'] ?? null ) ? sanitize_text_field( (string) $params['coupon'] ) : '',
		) + Quote::carried( $params );
	}

	/**
	 * Create a booking.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function create_booking( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$guarded = $this->guard( $request, 'booking', self::HONEYPOT );
		if ( $guarded instanceof WP_Error ) {
			return $guarded;
		}

		$params = $this->params( $request );

		$refused = $this->verify_nonce( $request, $params );
		if ( $refused instanceof WP_Error ) {
			return $refused;
		}

		// The booking belongs to whoever is signed in. A user id in the body would let a visitor
		// file a booking under somebody else's account.
		unset( $params['user_id'], $params[ self::HONEYPOT ] );

		$scope = $this->scope( $params, (int) ( $params['location_id'] ?? 0 ), (int) ( $params['space_type_id'] ?? 0 ) );
		if ( $scope instanceof WP_Error ) {
			return $scope;
		}

		$answers = FormScope::answers( $scope['form'], $params['meta'] ?? null );
		if ( $answers instanceof WP_Error ) {
			return $answers;
		}

		$params                    = $this->held_to( $params, $scope['form'] );
		$params['meta']            = $answers;
		$params['booking_form_id'] = isset( $scope['form']['id'] ) ? (int) $scope['form']['id'] : null;

		$booking = $this->bookings->create( $params );
		if ( $booking instanceof WP_Error ) {
			return $booking;
		}

		// The page the confirmation email links to, so the form can offer it straight away. It is
		// left out, not sent empty, on a site that has no summary page.
		$summary_url = Invoice::summary_url( (string) ( $booking['booking_ref'] ?? '' ) );
		if ( '' !== $summary_url ) {
			$booking['summary_url'] = $summary_url;
		}

		// What lets the form go on to start a payment for the booking it has just made.
		$booking['payment_key'] = PaymentKey::for_ref( (string) ( $booking['booking_ref'] ?? '' ) );

		return $this->success( $booking, __( 'Your booking has been created.', 'plugixa-car-parking' ) );
	}

	/**
	 * The form a request is made on and the location it books at, or why it may not.
	 *
	 * The form is named as `form_id`; `booking_form_id` is what a page before 1.1.0 sent, and
	 * names the same thing. Neither means the site's default form.
	 *
	 * @param array<string, mixed> $params        The request's parameters.
	 * @param int                  $location_id   The location asked for.
	 * @param int|null             $space_type_id The space type asked for, if any.
	 *
	 * @return array{form: array<string, mixed>, location: array<string, mixed>}|WP_Error
	 */
	private function scope( array $params, int $location_id, ?int $space_type_id ): array|WP_Error {
		$form = $this->forms->resolve( $params['form_id'] ?? $params['booking_form_id'] ?? null );
		if ( $form instanceof WP_Error ) {
			return $form;
		}

		$location = $this->offer->location( $form, $location_id );
		if ( $location instanceof WP_Error ) {
			return $location;
		}

		if ( $space_type_id ) {
			$refused = $this->offer->refuse_space_type( $form, $location, $space_type_id );
			if ( $refused instanceof WP_Error ) {
				return $refused;
			}
		}

		return array(
			'form'     => $form,
			'location' => $location,
		);
	}

	/**
	 * A public request held to what its form allows.
	 *
	 * The billing unit is the location's, never the visitor's: a unit with a lower price, or none,
	 * would otherwise be one request away. A coupon is dropped where the form takes none.
	 *
	 * @param array<string, mixed> $params The request's parameters.
	 * @param array<string, mixed> $form   The form row, or empty for none.
	 *
	 * @return array<string, mixed>
	 */
	private function held_to( array $params, array $form ): array {
		unset( $params['billing_type'] );

		if ( ! FormScope::takes_coupons( $form ) ) {
			unset( $params['coupon'] );
		}

		return $params;
	}

	/**
	 * A datetime as 'Y-m-d H:i:s', or an empty string when it cannot be read.
	 *
	 * @param string $value What the request sent.
	 */
	private function normalize_datetime( string $value ): string {
		if ( '' === $value ) {
			return '';
		}

		$ts = strtotime( $value );

		return $ts ? gmdate( 'Y-m-d H:i:s', $ts ) : '';
	}
}
