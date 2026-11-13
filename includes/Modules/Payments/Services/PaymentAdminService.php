<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Modules\Payments\Services;

use PlugixaCarParking\Core\Database\NamedLock;
use PlugixaCarParking\Core\Entities\RawInput;
use PlugixaCarParking\Modules\Bookings\Repositories\BookingRepository;
use PlugixaCarParking\Modules\Payments\Gateways\RefundingGatewayInterface;
use PlugixaCarParking\Modules\Payments\Repositories\PaymentRepository;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The rules for money an administrator records by hand: cash taken at the gate, a bank transfer,
 * a refund.
 *
 * It decides whether a payment or a refund may be recorded and hands the writing to
 * {@see PaymentService}, so a payment recorded here moves the booking's paid state exactly as one
 * reported by a gateway does.
 *
 * THERE IS NO UPDATE AND NO DELETE. The ledger is added to and never changed: a payment recorded
 * by mistake is put right with a refund, which leaves both on the record.
 */
final class PaymentAdminService {

	/**
	 * What a payment recorded by hand can be. A refund has its own route.
	 *
	 * @var string[]
	 */
	public const TYPES = array( 'full', 'deposit', 'balance' );

	/**
	 * Every type money coming in can be recorded as: the plugin's own, and any a module adds.
	 *
	 * A module that takes payments of a kind of its own (paying in parts, say) names the type
	 * here, and the ledger counts it as money in everywhere: what a booking has been paid, the
	 * revenue, the filters. A refund is not money in and is never one of these.
	 *
	 * @return string[]
	 */
	public static function types(): array {
		/**
		 * The types a payment into a booking can be recorded as.
		 *
		 * @param string[] $types 'full', 'deposit', 'balance', and whatever modules add.
		 */
		$types = apply_filters( 'plugixa_carparking_payment_types', self::TYPES );

		$types = array_values( array_unique( array_filter( array_map( 'sanitize_key', is_array( $types ) ? $types : array() ) ) ) );

		// The plugin's own are always there, and a refund is never money in.
		return array_values( array_diff( array_unique( array_merge( self::TYPES, $types ) ), array( 'refund' ) ) );
	}

	/**
	 * Amounts are stored to four decimal places, so two that differ by less than this are equal.
	 */
	private const TOLERANCE = 0.00005;

	public function __construct(
		private readonly PaymentService $ledger,
		private readonly PaymentRepository $payments,
		private readonly BookingRepository $bookings,
		private readonly GatewayRegistry $gateways,
		private readonly NamedLock $lock
	) {}

	/**
	 * @param int $id Payment id.
	 *
	 * @return array<string, mixed>|null
	 */
	public function get( int $id ): ?array {
		$row = $this->payments->find( $id );

		return null === $row ? null : $this->payments->cast( $row );
	}

	/**
	 * Record a payment received outside a gateway's own flow.
	 *
	 * @param array<string, mixed> $input Raw, untrusted payload: booking_id, amount, type?, gateway?.
	 *
	 * @return int|WP_Error The new ledger row's id.
	 */
	public function record( array $input ): int|WP_Error {
		$refused = RawInput::first(
			RawInput::number( $input, 'booking_id', __( 'Booking', 'plugixa-car-parking' ) ),
			RawInput::number( $input, 'amount', __( 'Amount', 'plugixa-car-parking' ) ),
			RawInput::choice( $input, 'type', self::types(), __( 'Type', 'plugixa-car-parking' ) ),
			RawInput::choice( $input, 'gateway', array_keys( $this->gateways->all() ), __( 'Gateway', 'plugixa-car-parking' ) )
		);

		if ( $refused instanceof WP_Error ) {
			return $refused;
		}

		$booking_id = (int) ( $input['booking_id'] ?? 0 );

		if ( $booking_id < 1 || null === $this->bookings->find( $booking_id ) ) {
			return new WP_Error(
				'plugixa_carparking_invalid_booking',
				__( 'A payment needs a booking, and that booking does not exist.', 'plugixa-car-parking' ),
				array(
					'status' => 422,
					'field'  => 'booking_id',
				)
			);
		}

		$amount = round( (float) ( $input['amount'] ?? 0 ), 4 );

		if ( $amount <= 0 ) {
			return $this->not_positive();
		}

		$type    = $this->key( $input['type'] ?? '', 'full' );
		$gateway = $this->key( $input['gateway'] ?? '', 'offline' );

		$id = $this->ledger->record_payment( $booking_id, $gateway, $type, $amount, 'completed', null, array( 'source' => 'manual' ) );

		return $id > 0 ? $id : $this->not_recorded();
	}

	/**
	 * Record a refund against the booking a payment belongs to.
	 *
	 * A refund is a new row of its own. The payment it answers is left as it was.
	 *
	 * NO MORE THAN THE BOOKING HAS BEEN PAID. The limit is what the booking holds now, payments
	 * less earlier refunds, and it is read and the refund written under one lock: two refunds
	 * sent at once would otherwise each be measured against the same balance and together take it
	 * below zero.
	 *
	 * @param int                  $payment_id The payment being refunded.
	 * @param array<string, mixed> $input      Raw, untrusted payload: amount?. When absent, the whole
	 *                                         payment, or as much of it as the booking still holds.
	 *
	 * @return int|WP_Error The refund row's id.
	 */
	public function refund( int $payment_id, array $input ): int|WP_Error {
		$payment = $this->payments->find( $payment_id );

		if ( null === $payment ) {
			return $this->not_found();
		}

		if ( 'refund' === $payment['type'] || 'completed' !== $payment['status'] ) {
			return new WP_Error(
				'plugixa_carparking_not_refundable',
				__( 'Only a completed payment can be refunded.', 'plugixa-car-parking' ),
				array( 'status' => 422 )
			);
		}

		$refused = RawInput::number( $input, 'amount', __( 'Amount', 'plugixa-car-parking' ) );
		if ( $refused instanceof WP_Error ) {
			return $refused;
		}

		$booking_id = (int) $payment['booking_id'];

		// A trashed booking's paid state is not re-derived, so a refund recorded against one would
		// leave the booking saying it still holds the money.
		if ( null === $this->bookings->find( $booking_id ) ) {
			return new WP_Error(
				'plugixa_carparking_booking_unavailable',
				__( 'This payment belongs to a booking that is in the trash or no longer exists. Restore the booking before refunding it.', 'plugixa-car-parking' ),
				array( 'status' => 409 )
			);
		}

		// No amount means "refund this payment": what is left of it, decided under the lock below.
		$sent   = $input['amount'] ?? '';
		$amount = is_string( $sent ) && '' === trim( $sent ) ? null : round( (float) $sent, 4 );

		if ( null !== $amount && $amount <= 0 ) {
			return $this->not_positive();
		}

		$id = $this->lock->run(
			PaymentService::refund_lock( $booking_id ),
			function () use ( $booking_id, $payment, $payment_id, $amount ): int|WP_Error {
				$paid = $this->payments->total_paid( $booking_id );

				// After a part refund, of this payment or of another one on the booking, the whole
				// payment is more than the booking still holds. The rest of it is what goes back.
				if ( null === $amount ) {
					$amount = round( min( (float) $payment['amount'], max( 0.0, $paid ) ), 4 );
					if ( $amount <= 0 ) {
						return new WP_Error(
							'plugixa_carparking_refund_too_large',
							__( 'This booking has already been refunded in full.', 'plugixa-car-parking' ),
							array(
								'status' => 422,
								'field'  => 'amount',
								'max'    => 0.0,
							)
						);
					}
				}

				if ( $amount > $paid + self::TOLERANCE ) {
					return new WP_Error(
						'plugixa_carparking_refund_too_large',
						__( 'A refund cannot be more than the booking has been paid.', 'plugixa-car-parking' ),
						array(
							'status' => 422,
							'field'  => 'amount',
							'max'    => round( max( 0.0, $paid ), 4 ),
						)
					);
				}

				$sent = $this->refund_at_gateway( $payment, $amount, $paid );
				if ( $sent instanceof WP_Error ) {
					return $sent;
				}

				return $this->ledger->record_payment( $booking_id, (string) $payment['gateway'], 'refund', $amount, 'completed', $sent, array( 'refund_of' => $payment_id ) );
			}
		);

		if ( $id instanceof WP_Error ) {
			return $id;
		}

		return null !== $id && $id > 0 ? $id : $this->not_recorded();
	}

	/**
	 * Send a refund back through the gateway that took the payment, when it can.
	 *
	 * A payment taken online by a gateway that refunds is refunded there, or not at all: a refund
	 * only written in the ledger would leave the customer unpaid and the books saying otherwise.
	 * Money taken any other way (cash, a transfer, a gateway that cannot refund) is refunded by
	 * hand and only recorded here.
	 *
	 * @param array<string, mixed> $payment The payment being refunded.
	 * @param float                $amount  How much.
	 * @param float                $paid    What the booking holds before this refund.
	 *
	 * @return string|null|WP_Error The gateway's id for the refund, null when none was sent.
	 */
	private function refund_at_gateway( array $payment, float $amount, float $paid ): string|null|WP_Error {
		$gateway = $this->gateways->get( (string) $payment['gateway'] );

		if ( ! $gateway instanceof RefundingGatewayInterface || empty( $payment['transaction_id'] ) ) {
			return null;
		}

		if ( ! $gateway->is_configured() ) {
			return new WP_Error(
				'plugixa_carparking_gateway_unavailable',
				__( 'This payment was taken online, but the payment method is no longer set up, so it cannot be refunded from here.', 'plugixa-car-parking' ),
				array( 'status' => 409 )
			);
		}

		// The same refund asked for again (a double click, a retry after a timeout) carries the
		// same key, so the gateway makes it once. What the booking holds is part of the key, so a
		// second refund made on purpose, once the first is recorded, is a new one. So is another try
		// after one failed: the failure puts the booking back where it was, and the same key would
		// only bring back the answer the gateway gave the first time.
		$key = sprintf( 'pcp-refund-%d-%s-%s', (int) $payment['id'], number_format( $paid, 4, '.', '' ), number_format( $amount, 4, '.', '' ) );

		$failed = count(
			array_filter(
				$this->payments->for_booking( (int) $payment['booking_id'] ),
				static fn( array $row ): bool => 'refund' === $row['type'] && 'failed' === $row['status']
			)
		);

		if ( $failed > 0 ) {
			$key .= '-' . $failed;
		}

		$sent = $gateway->refund( $payment, $amount, $key );

		if ( $sent instanceof WP_Error ) {
			return new WP_Error(
				'plugixa_carparking_gateway_refund_failed',
				sprintf(
					/* translators: %s: the payment provider's error message */
					__( 'The refund was refused by the payment provider: %s', 'plugixa-car-parking' ),
					$sent->get_error_message()
				),
				array( 'status' => 502 )
			);
		}

		return $sent;
	}

	public function not_found(): WP_Error {
		return new WP_Error(
			'plugixa_carparking_not_found',
			__( 'That payment does not exist.', 'plugixa-car-parking' ),
			array( 'status' => 404 )
		);
	}

	private function not_positive(): WP_Error {
		return new WP_Error(
			'plugixa_carparking_invalid_amount',
			__( 'The amount must be more than zero.', 'plugixa-car-parking' ),
			array(
				'status' => 422,
				'field'  => 'amount',
			)
		);
	}

	/**
	 * The ledger refused the row, or another payment for the same booking held it past the wait.
	 */
	private function not_recorded(): WP_Error {
		return new WP_Error(
			'plugixa_carparking_payment_not_recorded',
			__( 'The payment could not be recorded. Please try again.', 'plugixa-car-parking' ),
			array( 'status' => 409 )
		);
	}

	/**
	 * A machine key from the request, or the default when none was sent.
	 *
	 * @param mixed  $value    What the request sent.
	 * @param string $fallback Used when the value is absent or empty.
	 */
	private function key( mixed $value, string $fallback ): string {
		$key = is_string( $value ) ? sanitize_key( $value ) : '';

		return '' !== $key ? $key : $fallback;
	}
}
