<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Modules\Payments\Services;

use PlugixaCarParking\Core\Compat\LegacyHooks;
use PlugixaCarParking\Core\Database\NamedLock;
use PlugixaCarParking\Core\Settings;
use PlugixaCarParking\Modules\Bookings\Repositories\BookingRepository;
use PlugixaCarParking\Modules\Bookings\Services\Availability;
use PlugixaCarParking\Modules\Payments\Repositories\PaymentRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Records money in the ledger and moves a booking's status as it lands.
 *
 * A booking's paid state is never set directly. It is always DERIVED from the ledger, here, so
 * the two cannot disagree.
 *
 * ONE TRANSACTION IS RECORDED ONCE. An online payment is reported twice by design: the customer's
 * browser returns from the gateway, and the gateway also calls the webhook, and the two can
 * arrive in the same instant. "Has this transaction been recorded" followed by "record it" is a
 * check and a write, so both happen under a lock held per booking: the second report waits, looks
 * again, and finds the first.
 */
final class PaymentService {

	public function __construct(
		private readonly PaymentRepository $payments,
		private readonly BookingRepository $bookings,
		private readonly NamedLock $lock,
		private readonly Availability $availability
	) {}

	/**
	 * Record a payment and re-derive the booking's paid state.
	 *
	 * @param int                  $booking_id     The booking.
	 * @param string               $gateway        Gateway id.
	 * @param string               $type           'full', 'deposit', 'balance', 'refund', or a type a module adds.
	 * @param float                $amount         The amount, always positive.
	 * @param string               $status         'completed', 'pending' or 'failed'.
	 * @param string|null          $transaction_id The gateway's reference for the payment.
	 * @param array<string, mixed> $response       What the gateway sent, kept for support.
	 *
	 * @return int The ledger row's id: the new one, or the existing one for a transaction already
	 *             recorded. 0 when it could not be recorded.
	 */
	public function record_payment(
		int $booking_id,
		string $gateway,
		string $type,
		float $amount,
		string $status,
		?string $transaction_id = null,
		array $response = array()
	): int {
		$payment_id = $this->lock->run(
			'payment:' . $booking_id,
			function () use ( $booking_id, $gateway, $type, $amount, $status, $transaction_id, $response ): array {
				// A failed attempt is recorded once too, but only ever matches another failure: a
				// gateway may report a failure and then a success under one reference.
				if ( $transaction_id && in_array( $status, array( 'completed', 'failed' ), true ) ) {
					$existing = $this->payments->find_by_transaction( $gateway, $transaction_id, $status );

					if ( null !== $existing ) {
						return array( (int) $existing['id'], false );
					}
				}

				$booking = $this->bookings->find( $booking_id );

				$id = $this->payments->insert(
					array(
						'booking_id'     => $booking_id,
						'gateway'        => $gateway,
						'transaction_id' => $transaction_id,
						'type'           => $type,
						'amount'         => $amount,
						'currency'       => $booking['currency'] ?? Settings::get( 'currency', 'USD' ),
						'status'         => $status,
						'response'       => array() !== $response ? $response : null,
					)
				);

				return array( $id, $id > 0 && 'completed' === $status );
			}
		);

		// The lock could not be had: another report of a payment for this booking is still being
		// written. Nothing is recorded, and the gateway's retry or the customer's return finds it.
		if ( null === $payment_id ) {
			return 0;
		}

		[ $id, $changed ] = $payment_id;

		if ( $changed ) {
			$this->refresh_booking_paid_state( $booking_id );
		}

		return $id;
	}

	/**
	 * The lock every refund of a booking is measured and written under, from the admin or a gateway.
	 *
	 * @param int $booking_id The booking.
	 */
	public static function refund_lock( int $booking_id ): string {
		return 'refund:' . $booking_id;
	}

	/**
	 * Record a refund a gateway reports it has made.
	 *
	 * The money has already gone back, so the refund is recorded even where the ledger thought
	 * less was paid: it is cut to what the booking holds, never below nothing, and the amount the
	 * gateway reported is kept with the row. A booking that holds nothing records nothing.
	 *
	 * Taken under the same lock as a refund an administrator makes ({@see PaymentAdminService}),
	 * so a refund made from the admin and then reported by the webhook is found, not doubled.
	 *
	 * @param int                  $booking_id     The booking.
	 * @param string               $gateway        Gateway id.
	 * @param float                $amount         What the gateway refunded.
	 * @param string               $transaction_id The gateway's id for the refund.
	 * @param array<string, mixed> $response       What the gateway sent, kept for support.
	 *
	 * @return int|null The refund row's id, the existing one for a refund already recorded, 0 when
	 *                  there was nothing left to refund, null when it could not be recorded now.
	 */
	public function record_refund( int $booking_id, string $gateway, float $amount, string $transaction_id, array $response = array() ): ?int {
		$id = $this->lock->run(
			self::refund_lock( $booking_id ),
			function () use ( $booking_id, $gateway, $amount, $transaction_id, $response ): ?int {
				$existing = $this->payments->find_by_transaction( $gateway, $transaction_id, 'completed' );
				if ( null !== $existing ) {
					return (int) $existing['id'];
				}

				$held = round( $this->payments->total_paid( $booking_id ), 4 );
				if ( $held <= 0 ) {
					return 0;
				}

				$recorded = min( round( $amount, 4 ), $held );
				if ( $recorded < $amount ) {
					$response['reported_amount'] = $amount;
				}

				$id = $this->record_payment( $booking_id, $gateway, 'refund', $recorded, 'completed', $transaction_id, $response );

				return $id > 0 ? $id : null;
			}
		);

		return is_int( $id ) ? $id : null;
	}

	/**
	 * Take back a refund the gateway accepted and then failed to make.
	 *
	 * The money never left, so the booking holds it again. A refund the ledger does not have (one
	 * that failed before it was recorded, or a report that came twice) changes nothing.
	 *
	 * @param int    $booking_id     The booking.
	 * @param string $gateway        Gateway id.
	 * @param string $transaction_id The gateway's id for the refund.
	 *
	 * @return bool False when it could not be done now, and should be reported again.
	 */
	public function fail_refund( int $booking_id, string $gateway, string $transaction_id ): bool {
		$done = $this->lock->run(
			self::refund_lock( $booking_id ),
			function () use ( $booking_id, $gateway, $transaction_id ): bool {
				$refund = $this->payments->find_by_transaction( $gateway, $transaction_id, 'completed' );

				if ( null !== $refund && 'refund' === $refund['type'] && $booking_id === (int) $refund['booking_id'] ) {
					$this->payments->update( (int) $refund['id'], array( 'status' => 'failed' ) );
					$this->refresh_booking_paid_state( $booking_id, false );
				}

				return true;
			}
		);

		return true === $done;
	}

	/**
	 * Re-derive a booking's amount paid, payment status and status from the ledger.
	 *
	 * @param int  $booking_id The booking.
	 * @param bool $new_money  False when the booking only holds again money it never lost, which
	 *                         the customer has nothing to be told about.
	 */
	public function refresh_booking_paid_state( int $booking_id, bool $new_money = true ): void {
		$booking = $this->bookings->find( $booking_id );
		if ( null === $booking ) {
			return;
		}

		$paid  = $this->payments->total_paid( $booking_id );
		$total = (float) $booking['total'];

		if ( $paid >= $total && $total > 0 ) {
			$payment_status = 'paid';
			$status         = 'paid';
		} elseif ( $paid > 0 ) {
			$payment_status = 'deposit_paid';
			$status         = 'confirmed';
		} else {
			$payment_status = 'unpaid';
			// Everything was refunded out of a booking that had been paid for.
			$status = in_array( $booking['status'], array( 'paid', 'confirmed' ), true ) ? 'refunded' : $booking['status'];
		}

		$update = array(
			'amount_paid'    => $paid,
			'payment_status' => $payment_status,
			'status'         => $status,
		);

		/**
		 * The paid state just derived for a booking, before it is written.
		 *
		 * A module that takes payment in a way of its own can name a state the ledger total alone
		 * cannot see, such as a payment that was due and has not come. Only `payment_status` and
		 * `status` are taken from the answer; what was paid is the ledger's to say.
		 *
		 * @param array $update  amount_paid, payment_status, status.
		 * @param array $booking The booking as it was.
		 * @param float $paid    What it has been paid in total.
		 */
		$derived = apply_filters( 'plugixa_carparking_paid_state', $update, $booking, $paid );

		if ( is_array( $derived ) ) {
			foreach ( array( 'payment_status', 'status' ) as $key ) {
				if ( is_string( $derived[ $key ] ?? null ) && '' !== $derived[ $key ] ) {
					$update[ $key ] = sanitize_key( $derived[ $key ] );
				}
			}
		}

		// Once money is in, the booking is no longer a hold that can lapse.
		if ( $paid > 0 ) {
			$update['hold_expires_at'] = null;
		}

		// Money that came after the hold lapsed, for a space since given to someone else, cannot
		// confirm the booking: that would sell the space twice. The money stays on the ledger and
		// the booking stays expired, for someone to refund or to find the customer another space.
		$no_space = $paid > 0 && $this->space_was_lost( $booking );

		if ( $no_space ) {
			$update['status'] = 'expired';
		}

		$this->bookings->update( $booking_id, $update );

		$fresh = $this->bookings->find( $booking_id );

		if ( $no_space && $paid > (float) $booking['amount_paid'] ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals -- documented where the gateways fire it.
			do_action( 'plugixa_carparking_payment_notice', 'late_payment_no_space', $fresh, (string) $booking['payment_method'], array( 'amount' => $paid ) );
		}

		/**
		 * Fires when a booking's paid state has been re-derived after a payment.
		 *
		 * @param array $fresh The updated booking.
		 * @param float $paid  What it has now been paid in total.
		 * @param bool  $new_money False when a failed refund was taken back and no payment came.
		 */
		do_action( 'plugixa_carparking_payment_recorded', $fresh, $paid, $new_money );
		LegacyHooks::action( 'plugixacarparking_payment_recorded', 'plugixa_carparking_payment_recorded', array( $fresh, $paid ) );
	}

	/**
	 * Whether a booking whose hold has lapsed can no longer have its space.
	 *
	 * A lapsed hold counts for nothing in availability, so its space can have been booked since. One
	 * that is still free is the booking's again, and the payment confirms it as any other would.
	 *
	 * @param array<string, mixed> $booking The booking as it was before this payment.
	 */
	private function space_was_lost( array $booking ): bool {
		$hold   = (string) ( $booking['hold_expires_at'] ?? '' );
		$lapsed = 'expired' === $booking['status'] || ( 'pending' === $booking['status'] && '' !== $hold && $hold <= current_time( 'mysql' ) );

		if ( ! $lapsed ) {
			return false;
		}

		return ! $this->availability->can_book(
			(int) $booking['location_id'],
			(int) $booking['space_type_id'],
			(string) $booking['entry_datetime'],
			(string) $booking['exit_datetime'],
			(int) $booking['quantity'],
			empty( $booking['space_id'] ) ? null : (int) $booking['space_id'],
			(int) $booking['id']
		);
	}
}
