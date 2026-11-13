<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Modules\Payments\Http;

use PlugixaCarParking\Core\Http\PublicController;
use PlugixaCarParking\Core\Http\RawResponse;
use PlugixaCarParking\Modules\Bookings\Repositories\BookingRepository;
use PlugixaCarParking\Modules\Frontend\ReturnPage;
use PlugixaCarParking\Modules\Payments\Repositories\PaymentRepository;
use PlugixaCarParking\Modules\Payments\Services\GatewayRegistry;
use PlugixaCarParking\Modules\Payments\Services\PaymentAdminService;
use PlugixaCarParking\Modules\Payments\Services\PaymentKey;
use PlugixaCarParking\Modules\Payments\Services\PaymentService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The public side of a payment: start one, come back from the gateway, hear from the gateway.
 *
 * Starting a payment is guarded like any other public write. The return and the webhook are not
 * rate limited and carry no nonce: a payment provider calls them, from its own addresses and on
 * its own schedule, and a refused call there is a payment the site never hears about. What they
 * rely on is the gateway: it confirms a return with the provider, and checks a webhook's
 * signature or asks the provider, before anything is recorded.
 *
 * A payment is reported by both routes, often at the same moment. PaymentService records a
 * transaction once however many times it is reported.
 */
final class PublicPaymentController extends PublicController {

	/**
	 * The type of a payment of a booking's whole total.
	 */
	private const WHOLE = 'full';

	/**
	 * The type of a payment of what is left once part has been paid.
	 */
	private const BALANCE = 'balance';

	/**
	 * What a booking still owes, to the cent, never below nothing.
	 *
	 * @param array<string, mixed> $booking The booking row.
	 */
	private static function owed( array $booking ): float {
		return max( 0.0, round( (float) $booking['total'] - (float) ( $booking['amount_paid'] ?? 0 ), 2 ) );
	}

	public function __construct(
		private readonly GatewayRegistry $gateways,
		private readonly PaymentService $payments,
		private readonly BookingRepository $bookings,
		private readonly PaymentRepository $ledger
	) {}

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/payment/start',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'start' ),
					// Public by design: a guest pays for their own booking. Rate limited, needs the public nonce, and the booking must match its payment key.
					'permission_callback' => '__return_true',
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/payment/return',
			array(
				array(
					'methods'             => 'GET, POST',
					'callback'            => array( $this, 'handle_return' ),
					// Public by design: the payment provider sends the customer back here. Nothing is recorded until the gateway has confirmed the payment with the provider.
					'permission_callback' => '__return_true',
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/payment/webhook/(?P<gateway>[a-z0-9_-]+)',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'handle_webhook' ),
					// Public by design: the payment provider calls it from its own servers. Nothing is recorded until the gateway has verified the report with its signature or with the provider.
					'permission_callback' => '__return_true',
				),
			)
		);
	}

	/**
	 * Begin a payment for a booking.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function start( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$guarded = $this->guard( $request, 'payment_start' );
		if ( $guarded instanceof WP_Error ) {
			return $guarded;
		}

		$params = $this->params( $request );

		$refused = $this->verify_nonce( $request, $params );
		if ( $refused instanceof WP_Error ) {
			return $refused;
		}

		$booking_ref = sanitize_text_field( (string) ( $params['booking_ref'] ?? '' ) );
		$gateway_id  = sanitize_key( (string) ( $params['gateway'] ?? 'offline' ) );
		$requested   = is_scalar( $params['pay_type'] ?? null ) ? sanitize_key( (string) $params['pay_type'] ) : self::WHOLE;

		$booking_key = is_scalar( $params['booking_key'] ?? null ) ? sanitize_text_field( (string) $params['booking_key'] ) : '';

		// A reference alone does not open a checkout: the provider's page shows the customer's
		// email. A key that does not fit is answered as a booking that is not there, so the
		// answer does not say which references exist.
		$booking = PaymentKey::verify( $booking_ref, $booking_key ) ? $this->bookings->find_by_ref( $booking_ref ) : null;
		if ( null === $booking ) {
			return $this->error( __( 'Booking not found.', 'plugixa-car-parking' ), 404 );
		}

		if ( self::owed( $booking ) <= 0 ) {
			return $this->error( __( 'This booking is already paid.', 'plugixa-car-parking' ), 400 );
		}

		/**
		 * Refuse a payment a booking must not take now, with the reason the customer is shown.
		 *
		 * For a booking whose money arrives some other way, so a payment here would take it twice.
		 *
		 * @param string $refusal The reason, or empty to let the payment start.
		 * @param array  $booking The booking row.
		 */
		$refusal = (string) apply_filters( 'plugixa_carparking_payment_refusal', '', $booking );
		if ( '' !== $refusal ) {
			return $this->error( $refusal, 409 );
		}

		$gateway = $this->gateways->get( $gateway_id );
		if ( null === $gateway || ! $gateway->is_enabled() || ! $gateway->is_configured() ) {
			return $this->error( __( 'That payment method is unavailable.', 'plugixa-car-parking' ), 400 );
		}

		// A part payment is money taken now, online. A method that takes nothing online has no
		// "now": whatever the request asked for, the whole total is what is owed, in person.
		if ( ! $gateway->supports_online() ) {
			$requested = self::WHOLE;
		}

		$charge = $this->charge( $booking, $requested );
		$result = $gateway->start( $booking, $charge['amount'], $charge['type'] );

		if ( 'error' === ( $result['status'] ?? '' ) ) {
			return $this->error( (string) ( $result['error'] ?? __( 'Payment could not be started.', 'plugixa-car-parking' ) ), 400 );
		}

		return $this->success(
			array(
				'status'       => $result['status'] ?? 'pending',
				'redirect_url' => $result['redirect_url'] ?? null,
				'extra'        => $result['extra'] ?? array(),
			)
		);
	}

	/**
	 * The customer's browser coming back from a gateway: record the payment, then send them on.
	 *
	 * Always answers with a redirect to the booking page, saying whether the payment went through.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function handle_return( WP_REST_Request $request ): WP_REST_Response {
		$gateway_id = sanitize_key( (string) $request->get_param( 'plugixa_carparking_gateway' ) );
		$gateway    = $this->gateways->get( $gateway_id );

		if ( null === $gateway ) {
			return $this->redirect( null, 'failed' );
		}

		$result  = $gateway->handle_return( $request );
		$status  = (string) ( $result['status'] ?? 'failed' );
		$booking = $this->result_booking( $gateway_id, $result );

		if ( 'paid' === $status && null !== $booking ) {
			$this->payments->record_payment(
				(int) $booking['id'],
				$gateway_id,
				(string) ( $result['type'] ?? 'full' ),
				(float) ( $result['amount'] ?? $booking['total'] ),
				'completed',
				isset( $result['transaction_id'] ) ? (string) $result['transaction_id'] : null,
				array( 'via' => 'return' )
			);
		}

		// A failed payment names no booking, so the customer would land on a page that cannot say
		// which booking it was about. The return address carries the reference; it only chooses the
		// page shown, never what is recorded.
		$booking ??= $this->bookings->find_by_ref( sanitize_text_field( (string) $request->get_param( 'booking_ref' ) ) );

		// The key is passed on as the provider brought it back, never worked out here: anyone
		// can call this address with a reference, and must not be handed the key for it.
		$key = sanitize_text_field( (string) $request->get_param( PaymentKey::ARG ) );

		return $this->redirect( $booking, in_array( $status, array( 'paid', 'processing' ), true ) ? $status : 'failed', $key );
	}

	/**
	 * A gateway reporting a payment, a refund or a notice server to server.
	 *
	 * A notification the gateway does not accept, a wrong signature included, is acknowledged and
	 * ignored: nothing is recorded, and the answer gives a forger nothing to tune against.
	 *
	 * One the gateway accepts but the ledger could not record at that moment (the booking was
	 * locked by another report past the wait) is answered 503, so the gateway sends it again.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function handle_webhook( WP_REST_Request $request ): WP_REST_Response {
		$gateway_id = sanitize_key( (string) $request['gateway'] );
		$gateway    = $this->gateways->get( $gateway_id );

		if ( null === $gateway ) {
			return new WP_REST_Response( array( 'received' => false ), 404 );
		}

		// A gateway with no credentials cannot have taken a payment, so it has nothing to report.
		// Not `is_enabled()`: a payment begun before a gateway was switched off may still finish.
		$result = $gateway->is_configured() ? $gateway->handle_webhook( $request ) : null;
		$result = is_array( $result ) ? $result : array();

		// The gateway could not tell what the notification means yet. Acknowledging it would lose
		// whatever it reports, so it is left for the gateway to send again.
		if ( 'retry' === ( $result['event'] ?? '' ) ) {
			return new WP_REST_Response( array( 'received' => false ), 503 );
		}

		$events = is_array( $result['events'] ?? null ) ? $result['events'] : array( $result );

		foreach ( $events as $event ) {
			$booking = is_array( $event ) && array() !== $event ? $this->result_booking( $gateway_id, $event ) : null;

			// The whole notification comes again, and what is already written is recognised.
			if ( null !== $booking && ! $this->apply_webhook( $gateway_id, $booking, $event ) ) {
				return new WP_REST_Response( array( 'received' => false ), 503 );
			}
		}

		// 200 for everything else, so the gateway stops retrying.
		return new WP_REST_Response( array( 'received' => true ), 200 );
	}

	/**
	 * The booking a gateway's result is about: by its id, else its reference, else the booking of
	 * the payment it names (a refund or a dispute names the payment, not the booking).
	 *
	 * @param string               $gateway_id Gateway id.
	 * @param array<string, mixed> $result     The gateway's normalised result.
	 *
	 * @return array<string, mixed>|null
	 */
	private function result_booking( string $gateway_id, array $result ): ?array {
		if ( ! empty( $result['booking_id'] ) ) {
			$booking = $this->bookings->find( (int) $result['booking_id'] );
			if ( null !== $booking ) {
				return $booking;
			}
		}

		if ( ! empty( $result['booking_ref'] ) ) {
			$booking = $this->bookings->find_by_ref( (string) $result['booking_ref'] );
			if ( null !== $booking ) {
				return $booking;
			}
		}

		if ( ! empty( $result['parent_transaction_id'] ) ) {
			$parent = $this->ledger->find_by_transaction( $gateway_id, (string) $result['parent_transaction_id'], 'completed' );
			if ( null !== $parent ) {
				return $this->bookings->find( (int) $parent['booking_id'] );
			}
		}

		return null;
	}

	/**
	 * Write what a webhook reports.
	 *
	 * @param string               $gateway_id Gateway id.
	 * @param array<string, mixed> $booking    The booking.
	 * @param array<string, mixed> $result     The gateway's normalised result.
	 *
	 * @return bool False when it should be sent again: it could not be recorded now.
	 */
	private function apply_webhook( string $gateway_id, array $booking, array $result ): bool {
		$booking_id     = (int) $booking['id'];
		$transaction_id = isset( $result['transaction_id'] ) ? (string) $result['transaction_id'] : null;
		$amount         = (float) ( $result['amount'] ?? 0 );

		switch ( (string) ( $result['event'] ?? 'payment' ) ) {
			case 'payment':
				if ( 'completed' !== ( $result['status'] ?? '' ) ) {
					return true;
				}
				return $this->payments->record_payment( $booking_id, $gateway_id, (string) ( $result['type'] ?? self::WHOLE ), $amount, 'completed', $transaction_id, array( 'via' => 'webhook' ) ) > 0;

			case 'payment_failed':
				return $this->payments->record_payment( $booking_id, $gateway_id, (string) ( $result['type'] ?? self::WHOLE ), $amount, 'failed', $transaction_id, array( 'via' => 'webhook' ) ) > 0;

			case 'refund':
				if ( null === $transaction_id || '' === $transaction_id || $amount <= 0 ) {
					return true;
				}
				return null !== $this->payments->record_refund( $booking_id, $gateway_id, $amount, $transaction_id, array( 'via' => 'webhook' ) );

			case 'refund_failed':
				if ( null !== $transaction_id && '' !== $transaction_id && ! $this->payments->fail_refund( $booking_id, $gateway_id, $transaction_id ) ) {
					return false;
				}

				$booking = (array) $this->bookings->find( $booking_id );
				// Announced as any other notice, below.
				// Fall through.

			case 'notice':
				/**
				 * Fires when a gateway reports something about a booking's payment that changes no
				 * money in the ledger: a checkout left to expire, a payment still processing, a
				 * dispute opened or closed, a refund that failed.
				 *
				 * @param string $notice  What happened, e.g. 'checkout_expired', 'dispute_opened'.
				 * @param array  $booking The booking.
				 * @param string $gateway Gateway id.
				 * @param array  $data    What the gateway reported about it.
				 */
				do_action( 'plugixa_carparking_payment_notice', (string) ( $result['notice'] ?? 'refund_failed' ), $booking, $gateway_id, (array) ( $result['data'] ?? array() ) );
		}

		return true;
	}

	/**
	 * What to charge now, and the payment type it is recorded as.
	 *
	 * What is still owed, unless something registered answers the request with less. An answer
	 * that is not an amount between nothing and what is owed, or not a type a payment can have, is
	 * set aside and what is owed is charged.
	 *
	 * WHAT IS OWED, NOT THE TOTAL. A booking with part of its total already paid owes
	 * the rest, and asking for the total again would take that part twice. What is left after
	 * part has been paid is recorded as the balance.
	 *
	 * @param array<string, mixed> $booking   The booking row.
	 * @param string               $requested The payment type the request asked for.
	 *
	 * @return array{amount: float, type: string}
	 */
	private function charge( array $booking, string $requested ): array {
		$paid  = (float) ( $booking['amount_paid'] ?? 0 );
		$whole = array(
			'amount' => self::owed( $booking ),
			'type'   => $paid > 0 ? self::BALANCE : self::WHOLE,
		);

		/**
		 * Decide what a customer is charged when they start a payment.
		 *
		 * @param array  $charge    amount, type. The whole total, as a payment in full.
		 * @param array  $booking   The booking row.
		 * @param string $requested The payment type the request asked for.
		 */
		$charge = apply_filters( 'plugixa_carparking_payment_charge', $whole, $booking, $requested );

		if ( ! is_array( $charge ) || ! is_numeric( $charge['amount'] ?? null ) || ! is_string( $charge['type'] ?? null ) ) {
			return $whole;
		}

		$amount = (float) $charge['amount'];

		if ( $amount <= 0 || $amount > $whole['amount'] || ! in_array( $charge['type'], PaymentAdminService::types(), true ) ) {
			return $whole;
		}

		return array(
			'amount' => $amount,
			'type'   => $charge['type'],
		);
	}

	/**
	 * A redirect to the booking page, carrying the booking and how its payment went.
	 *
	 * ReturnPage decides which page that is on a site that has not chosen one, so the customer
	 * lands somewhere that can show the result.
	 *
	 * A response rather than `wp_safe_redirect()` and `exit`, so the REST server finishes the
	 * request in the ordinary way. The address is validated as that function validates it, and
	 * the body is sent empty.
	 *
	 * @param array<string, mixed>|null $booking The booking, when the gateway named one.
	 * @param string                    $status  'paid', 'processing' or 'failed'.
	 * @param string                    $key     The booking's payment key as the request carried
	 *                                           it. Passed on only when it fits the booking.
	 */
	private function redirect( ?array $booking, string $status, string $key = '' ): WP_REST_Response {
		$ref  = (string) ( $booking['booking_ref'] ?? '' );
		$args = array(
			'plugixa_carparking_booking' => $ref,
			'plugixa_carparking_status'  => $status,
		);

		if ( PaymentKey::verify( $ref, $key ) ) {
			$args[ PaymentKey::ARG ] = $key;
		}

		$url = add_query_arg( $args, ReturnPage::url() );

		$response = new WP_REST_Response( '', 302 );

		$response->set_headers(
			array(
				RawResponse::HEADER => '1',
				'Location'          => wp_validate_redirect( wp_sanitize_redirect( $url ), home_url( '/' ) ),
				'X-Redirect-By'     => 'WordPress',
			)
		);

		return $response;
	}
}
