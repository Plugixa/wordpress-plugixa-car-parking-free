<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Modules\Bookings\Services;

use PlugixaCarParking\Core\Compat\LegacyHooks;
use PlugixaCarParking\Core\Database\NamedLock;
use PlugixaCarParking\Core\Settings;
use PlugixaCarParking\Modules\Bookings\Repositories\BookingRepository;
use PlugixaCarParking\Modules\Bookings\Repositories\ItemTable;
use PlugixaCarParking\Modules\Coupons\Repositories\CouponRepository;
use PlugixaCarParking\Modules\Coupons\Repositories\UsageTable;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The one path that turns a request into a stored booking.
 *
 * Everything that creates a booking comes through here, so every booking gets the same checks,
 * the same pricing and the same guarantee against being sold twice.
 *
 * THE LAST SPACE IS SOLD ONCE. "Is there a space" and "write the booking" are two statements,
 * and two customers can both get a yes before either writes. So the check and the write happen
 * together under a lock held per location: the second request waits for the first, then counts
 * again and sees the space is gone.
 *
 * AND THE WRITE IS ALL OR NOTHING. A booking, its line items and the coupon redemption are one
 * transaction, so a failure half way leaves no booking without lines and no coupon counted for a
 * booking that does not exist.
 */
final class BookingService {

	public function __construct(
		private readonly Availability $availability,
		private readonly Quote $quote,
		private readonly BookingRepository $bookings,
		private readonly ItemTable $items,
		private readonly CouponRepository $coupons,
		private readonly UsageTable $coupon_usage,
		private readonly NamedLock $lock,
		private readonly CustomAnswers $answers
	) {}

	/**
	 * Create a booking from a sanitised request.
	 *
	 * @param array<string, mixed> $input Required: location_id, space_type_id, entry, exit.
	 *                                    Optional: quantity, space_id, billing_type, coupon,
	 *                                    gateway, customer_name, customer_email, customer_phone,
	 *                                    license_plate, vehicle, billing, notes, meta,
	 *                                    booking_form_id, user_id, and any field a module
	 *                                    claims for the quote.
	 *
	 * @return array<string, mixed>|WP_Error The created booking, or why it was refused.
	 */
	public function create( array $input ): array|WP_Error {
		$location_id   = (int) ( $input['location_id'] ?? 0 );
		$space_type_id = (int) ( $input['space_type_id'] ?? 0 );
		$entry         = $this->normalize_datetime( (string) ( $input['entry'] ?? '' ) );
		$exit          = $this->normalize_datetime( (string) ( $input['exit'] ?? '' ) );
		$quantity      = max( 1, (int) ( $input['quantity'] ?? 1 ) );
		$space_id      = ! empty( $input['space_id'] ) ? (int) $input['space_id'] : null;

		if ( ! $location_id || ! $space_type_id || '' === $entry || '' === $exit ) {
			return new WP_Error( 'missing_fields', __( 'Location, space type, entry and exit are required.', 'plugixa-car-parking' ), array( 'status' => 422 ) );
		}

		$window = $this->availability->validate_window( $entry, $exit );
		if ( $window instanceof WP_Error ) {
			return $window;
		}

		$open = $this->availability->check_open( $location_id, $entry, $exit );
		if ( $open instanceof WP_Error ) {
			return $open;
		}

		$request = array(
			'location_id'   => $location_id,
			'space_type_id' => $space_type_id,
			'space_id'      => $space_id,
			'entry'         => $entry,
			'exit'          => $exit,
			'quantity'      => $quantity,
		);

		$booking_id = $this->lock->run(
			'booking:' . $location_id,
			fn(): int|WP_Error => $this->reserve( $request, $input )
		);

		if ( null === $booking_id ) {
			return new WP_Error(
				'busy',
				__( 'This car park is taking another booking right now. Please try again.', 'plugixa-car-parking' ),
				array( 'status' => 409 )
			);
		}

		if ( $booking_id instanceof WP_Error ) {
			return $booking_id;
		}

		$booking = $this->bookings->find( $booking_id );

		// Announced after the lock is released: a listener sends email, and the next customer
		// should not wait on a mail server.
		/**
		 * Fires after a booking is created.
		 *
		 * @param array $booking The stored booking.
		 */
		do_action( 'plugixa_carparking_booking_created', $booking );
		LegacyHooks::action( 'plugixacarparking_booking_created', 'plugixa_carparking_booking_created', array( $booking ) );

		return $booking;
	}

	/**
	 * Check there is a space and write the booking. Runs under the location's lock.
	 *
	 * @param array<string, mixed> $request Normalised location, space type, window and quantity.
	 * @param array<string, mixed> $input   The original request, for the customer's details.
	 *
	 * @return int|WP_Error The new booking id, or why it was refused.
	 */
	private function reserve( array $request, array $input ): int|WP_Error {
		global $wpdb;

		// The space the booking holds, worked out under the lock: none for a pool, else the one
		// chosen if it is free, else one picked. A pooled booking never stores a space it was sent.
		$space_id = $this->availability->space_for( $request['location_id'], $request['space_type_id'], $request['entry'], $request['exit'], $request['space_id'] );

		if ( 0 === $space_id ) {
			return new WP_Error( 'no_availability', __( 'Sorry, those spaces are no longer available for the selected dates.', 'plugixa-car-parking' ), array( 'status' => 409 ) );
		}

		$request['space_id'] = $space_id;

		if ( ! $this->availability->can_book( $request['location_id'], $request['space_type_id'], $request['entry'], $request['exit'], $request['quantity'], $request['space_id'] ) ) {
			return new WP_Error( 'no_availability', __( 'Sorry, those spaces are no longer available for the selected dates.', 'plugixa-car-parking' ), array( 'status' => 409 ) );
		}

		$quote = $this->quote->build(
			array(
				'location_id'   => $request['location_id'],
				'space_type_id' => $request['space_type_id'],
				'entry'         => $request['entry'],
				'exit'          => $request['exit'],
				'quantity'      => $request['quantity'],
				'billing_type'  => $input['billing_type'] ?? null,
				'coupon'        => $input['coupon'] ?? '',
			) + Quote::carried( $input )
		);

		$status       = (string) Settings::get( 'default_booking_status', 'pending' );
		$hold_minutes = (int) Settings::get( 'pending_hold_minutes', 0 );

		// On the site's clock, the same basis the overlap query compares it on.
		$hold_expires = ( 'pending' === $status && $hold_minutes > 0 )
			? gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) + $hold_minutes * 60 )
			: null;

		$user_id = ! empty( $input['user_id'] ) ? (int) $input['user_id'] : get_current_user_id();
		$form_id = $this->answers->form_id( $input['booking_form_id'] ?? null );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- transaction control.
		$wpdb->query( 'START TRANSACTION' );

		$booking_id = $this->bookings->insert(
			array(
				'booking_ref'     => $this->bookings->generate_ref( (string) Settings::get( 'booking_ref_prefix', 'PKG-' ) ),
				'location_id'     => $request['location_id'],
				'space_type_id'   => $request['space_type_id'],
				'space_id'        => $request['space_id'],
				'booking_form_id' => $form_id,
				'user_id'         => $user_id > 0 ? $user_id : null,
				'status'          => $status,
				'billing_type'    => $quote['billing_type'],
				'entry_datetime'  => $request['entry'],
				'exit_datetime'   => $request['exit'],
				'quantity'        => $request['quantity'],
				'hold_expires_at' => $hold_expires,
				'license_plate'   => isset( $input['license_plate'] ) ? sanitize_text_field( (string) $input['license_plate'] ) : null,
				'vehicle'         => isset( $input['vehicle'] ) && is_array( $input['vehicle'] ) ? $input['vehicle'] : null,
				'customer_name'   => isset( $input['customer_name'] ) ? sanitize_text_field( (string) $input['customer_name'] ) : null,
				'customer_email'  => isset( $input['customer_email'] ) ? sanitize_email( (string) $input['customer_email'] ) : null,
				'customer_phone'  => isset( $input['customer_phone'] ) ? sanitize_text_field( (string) $input['customer_phone'] ) : null,
				'billing'         => isset( $input['billing'] ) && is_array( $input['billing'] ) ? $input['billing'] : null,
				'currency'        => $quote['currency'],
				'subtotal'        => $quote['subtotal'],
				'discount_total'  => $quote['discount_total'],
				'tax_total'       => $quote['tax_total'],
				'deposit_amount'  => $quote['deposit_amount'],
				'total'           => $quote['total'],
				'coupon_id'       => $quote['coupon_id'] ?? null,
				'amount_paid'     => 0,
				'payment_method'  => isset( $input['gateway'] ) ? sanitize_key( (string) $input['gateway'] ) : 'offline',
				'payment_status'  => 'unpaid',
				'notes'           => isset( $input['notes'] ) ? sanitize_textarea_field( (string) $input['notes'] ) : null,
				// The answers to the form's own fields, each with what its field was called.
				'meta'            => $this->answers->store( $input['meta'] ?? null, $form_id ),
			)
		);

		if ( ! $booking_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- transaction control.
			$wpdb->query( 'ROLLBACK' );

			return new WP_Error( 'create_failed', __( 'Could not create the booking.', 'plugixa-car-parking' ), array( 'status' => 500 ) );
		}

		foreach ( $quote['line_items'] as $line ) {
			$this->items->insert(
				array(
					'booking_id'   => $booking_id,
					'type'         => $line['type'],
					'reference_id' => $line['reference_id'] ?? null,
					'label'        => $line['label'],
					'quantity'     => $line['quantity'],
					'unit_price'   => $line['unit_price'],
					'total'        => $line['total'],
					'meta'         => ItemTable::details( $line ),
				)
			);
		}

		if ( ! empty( $quote['coupon_id'] ) ) {
			$this->coupons->increment_usage( (int) $quote['coupon_id'] );
			$this->coupon_usage->log( (int) $quote['coupon_id'], $booking_id, $user_id, (float) $quote['discount_total'] );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- transaction control.
		$wpdb->query( 'COMMIT' );

		return $booking_id;
	}

	/**
	 * What a booking would cost on other dates.
	 *
	 * Priced from what the booking already is: its location, its kind of space, its quantity and
	 * billing unit, and the coupon it redeemed. Anything a module added to the booking when it was
	 * made is asked for again from that module, which reads it back from the booking's own lines.
	 *
	 * @param array<string, mixed> $booking The booking row.
	 * @param string               $entry   New entry, 'Y-m-d H:i:s'.
	 * @param string               $exit    New exit, 'Y-m-d H:i:s'.
	 *
	 * @return array<string, mixed> The quote.
	 */
	public function requote( array $booking, string $entry, string $exit ): array {
		/**
		 * Request fields to price a booking again with, rebuilt from the booking as stored.
		 *
		 * @param array<string, mixed>             $fields  Fields so far.
		 * @param array<string, mixed>             $booking The booking row.
		 * @param array<int, array<string, mixed>> $items   Its stored lines.
		 */
		$fields = (array) apply_filters( 'plugixa_carparking_requote_fields', array(), $booking, $this->items->for_booking( (int) $booking['id'] ) );

		return $this->quote->build(
			array(
				'location_id'        => (int) $booking['location_id'],
				'space_type_id'      => (int) $booking['space_type_id'],
				'entry'              => $entry,
				'exit'               => $exit,
				'quantity'           => max( 1, (int) ( $booking['quantity'] ?? 1 ) ),
				'billing_type'       => $booking['billing_type'] ?? null,
				'user_id'            => (int) ( $booking['user_id'] ?? 0 ),
				'redeemed_coupon_id' => $booking['coupon_id'] ?? null,
			) + Quote::carried( $fields )
		);
	}

	/**
	 * Move a booking to other dates, and charge what those dates cost.
	 *
	 * A stay that gets longer costs more and one that gets shorter costs less: the total and the
	 * lines are replaced with the new quote in the same step that moves the dates, so a booking
	 * never shows five days at the price of one. What has been paid is not touched here. Whoever
	 * keeps the ledger hears about the new total and works out what is now owed.
	 *
	 * @param array<string, mixed> $booking        The booking row.
	 * @param string               $entry          New entry, 'Y-m-d H:i:s'.
	 * @param string               $exit           New exit, 'Y-m-d H:i:s'.
	 * @param float|null           $expected_total The total the caller was shown and agreed to.
	 *                                             When the price is no longer that, nothing moves.
	 *
	 * @return array<string, mixed>|WP_Error The booking as it now is.
	 */
	public function move( array $booking, string $entry, string $exit, ?float $expected_total = null ): array|WP_Error {
		$booking_id  = (int) $booking['id'];
		$location_id = (int) $booking['location_id'];

		$refused = $this->availability->validate_window( $entry, $exit ) ?? $this->availability->check_open( $location_id, $entry, $exit );
		if ( $refused instanceof WP_Error ) {
			return $refused;
		}

		// "Is there a space" and "take it" are two statements. They run under the lock a new
		// booking is created under, so a move and a new booking cannot both count the same space.
		$moved = $this->lock->run(
			'booking:' . $location_id,
			fn(): bool|WP_Error => $this->reprice( $booking, $entry, $exit, $expected_total )
		);

		if ( null === $moved ) {
			return new WP_Error( 'busy', __( 'This car park is taking another booking right now. Please try again.', 'plugixa-car-parking' ), array( 'status' => 409 ) );
		}

		if ( $moved instanceof WP_Error ) {
			return $moved;
		}

		/**
		 * A booking's total has been worked out again.
		 *
		 * @param int   $booking_id The booking.
		 * @param float $previous   The total before.
		 */
		do_action( 'plugixa_carparking_booking_repriced', $booking_id, (float) ( $booking['total'] ?? 0 ) );

		return (array) $this->bookings->find( $booking_id );
	}

	/**
	 * The locked part of a move: check the space, price the stay, store both.
	 *
	 * @param array<string, mixed> $booking        The booking row.
	 * @param string               $entry          New entry.
	 * @param string               $exit           New exit.
	 * @param float|null           $expected_total The total the caller agreed to, if it said.
	 */
	private function reprice( array $booking, string $entry, string $exit, ?float $expected_total ): bool|WP_Error {
		global $wpdb;

		$booking_id = (int) $booking['id'];
		$held       = ! empty( $booking['space_id'] ) ? (int) $booking['space_id'] : null;

		// The booking does not compete with itself for the space it already holds. Where that
		// space is taken on the new dates, another free one of the same type will do.
		$space_id = $this->availability->space_for( (int) $booking['location_id'], (int) $booking['space_type_id'], $entry, $exit, $held, $booking_id );

		if ( 0 === $space_id && null !== $held ) {
			$space_id = $this->availability->space_for( (int) $booking['location_id'], (int) $booking['space_type_id'], $entry, $exit, null, $booking_id );
		}

		$free = 0 !== $space_id && $this->availability->can_book(
			(int) $booking['location_id'],
			(int) $booking['space_type_id'],
			$entry,
			$exit,
			max( 1, (int) ( $booking['quantity'] ?? 1 ) ),
			$space_id,
			$booking_id
		);

		if ( ! $free ) {
			return new WP_Error( 'no_availability', __( 'Those dates are not available. Please choose another time.', 'plugixa-car-parking' ), array( 'status' => 409 ) );
		}

		$quote = $this->requote( $booking, $entry, $exit );

		if ( null !== $expected_total && abs( (float) $quote['total'] - $expected_total ) >= 0.005 ) {
			return new WP_Error(
				'price_changed',
				__( 'The price for those dates has changed. Please check the new total and confirm again.', 'plugixa-car-parking' ),
				array(
					'status' => 409,
					'total'  => (float) $quote['total'],
				)
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- transaction control.
		$wpdb->query( 'START TRANSACTION' );

		$stored = $this->bookings->update(
			$booking_id,
			array(
				'entry_datetime' => $entry,
				'exit_datetime'  => $exit,
				'space_id'       => $space_id,
				'billing_type'   => $quote['billing_type'],
				'subtotal'       => $quote['subtotal'],
				'discount_total' => $quote['discount_total'],
				'tax_total'      => $quote['tax_total'],
				'deposit_amount' => $quote['deposit_amount'],
				'total'          => $quote['total'],
			)
		);

		if ( ! $stored ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- transaction control.
			$wpdb->query( 'ROLLBACK' );

			return new WP_Error( 'update_failed', __( 'The booking could not be changed.', 'plugixa-car-parking' ), array( 'status' => 500 ) );
		}

		$this->items->delete_for_booking( $booking_id );

		foreach ( $quote['line_items'] as $line ) {
			$this->items->insert(
				array(
					'booking_id'   => $booking_id,
					'type'         => $line['type'],
					'reference_id' => $line['reference_id'] ?? null,
					'label'        => $line['label'],
					'quantity'     => $line['quantity'],
					'unit_price'   => $line['unit_price'],
					'total'        => $line['total'],
					'meta'         => ItemTable::details( $line ),
				)
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- transaction control.
		$wpdb->query( 'COMMIT' );

		return true;
	}

	/**
	 * A datetime as 'Y-m-d H:i:s', or an empty string when it cannot be read.
	 *
	 * @param string $value What the request sent.
	 */
	private function normalize_datetime( string $value ): string {
		$ts = strtotime( $value );

		return $ts ? gmdate( 'Y-m-d H:i:s', $ts ) : '';
	}
}
