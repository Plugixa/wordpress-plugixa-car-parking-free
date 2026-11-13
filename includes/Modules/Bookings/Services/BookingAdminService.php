<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Modules\Bookings\Services;

use PlugixaCarParking\Core\Database\NamedLock;
use PlugixaCarParking\Core\Entities\RawInput;
use PlugixaCarParking\Modules\Bookings\Repositories\BookingRepository;
use PlugixaCarParking\Modules\Bookings\Repositories\ItemTable;
use PlugixaCarParking\Modules\Locations\Repositories\LocationRepository;
use PlugixaCarParking\Modules\Locations\Services\LocationService;
use PlugixaCarParking\Modules\Payments\Repositories\PaymentRepository;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The rules for a booking an administrator makes or changes.
 *
 * Everything that writes a booking from the admin side comes through here: the REST controller, a
 * mass action, an importer. Creating one is handed to {@see BookingService}, the same path a
 * customer's booking takes, so it is checked for a free space, priced and given its line items in
 * exactly the same way.
 *
 * WHAT AN ADMINISTRATOR CANNOT SET. A booking's payment status and amount paid are worked out from
 * the payments ledger and nowhere else, so sending either is refused: storing what was sent would
 * make the booking disagree with its own payments. Its totals are the quote it was created with
 * and are not editable here either.
 */
final class BookingAdminService {

	/**
	 * Fields worked out from the payments ledger. Sending one is an error, not something to ignore.
	 *
	 * @var string[]
	 */
	private const DERIVED_FIELDS = array( 'payment_status', 'amount_paid' );

	/**
	 * Plain single-line text columns, cleaned the same way.
	 *
	 * @var string[]
	 */
	private const TEXT_FIELDS = array( 'customer_name', 'customer_phone', 'license_plate' );

	/**
	 * The fields that decide which space a booking holds, and when. Changing one is a reschedule.
	 *
	 * @var string[]
	 */
	private const WINDOW_FIELDS = array( 'entry_datetime', 'exit_datetime', 'quantity', 'space_type_id', 'space_id' );

	public function __construct(
		private readonly BookingService $engine,
		private readonly BookingRepository $bookings,
		private readonly ItemTable $items,
		private readonly Availability $availability,
		private readonly LocationRepository $locations,
		private readonly PaymentRepository $payments,
		private readonly NamedLock $lock,
		private readonly CustomAnswers $answers
	) {}

	/**
	 * One booking with the lines it was priced as.
	 *
	 * @param int $id Booking id.
	 *
	 * @return array<string, mixed>|null
	 */
	public function get( int $id ): ?array {
		$row = $this->bookings->find( $id );

		if ( null === $row ) {
			return null;
		}

		$row          = $this->bookings->cast( $row );
		$row['items'] = array_map( array( $this, 'cast_item' ), $this->items->for_booking( $id ) );

		// The answers to the form's own fields, each under the label it was asked with.
		$row['custom_fields'] = $this->answers->rows( $row );

		return $row;
	}

	/**
	 * Create a booking through the booking engine.
	 *
	 * THE CUSTOMER'S BOOKING WINDOW APPLIES HERE TOO. The engine refuses an entry in the past or
	 * outside the advance-booking limits, and it does so for every caller.
	 *
	 * Version 1's admin route wrote the row directly: no check for a free space, no price, no line
	 * items, and a total of zero.
	 *
	 * @param array<string, mixed> $input Raw, untrusted payload.
	 *
	 * @return int|WP_Error
	 */
	public function create( array $input ): int|WP_Error {
		$refused = RawInput::first(
			$this->refuse_derived( $input ),
			$this->refuse_unusable( $input ),
			RawInput::date( $input, 'entry', __( 'Entry', 'plugixa-car-parking' ) ),
			RawInput::date( $input, 'exit', __( 'Exit', 'plugixa-car-parking' ) ),
			RawInput::choice( $input, 'billing_type', LocationService::BILLING_TYPES, __( 'Billing unit', 'plugixa-car-parking' ) )
		);

		if ( $refused instanceof WP_Error ) {
			return $refused;
		}

		$data = $this->sanitize( $input );

		$invalid = $this->validate( $data );
		if ( $invalid instanceof WP_Error ) {
			return $invalid;
		}

		$location_id = $this->whole_number( $input['location_id'] ?? 0 );

		// Asked here because the engine answers "no space" for a car park it cannot find, which
		// sends whoever is filling in the form looking at the dates.
		if ( $location_id > 0 && null === $this->locations->find( $location_id ) ) {
			return new WP_Error(
				'plugixa_carparking_invalid_location',
				__( 'That location does not exist.', 'plugixa-car-parking' ),
				array(
					'status' => 422,
					'field'  => 'location_id',
				)
			);
		}

		$request = array(
			'location_id'   => $location_id,
			'entry'         => (string) ( $data['entry_datetime'] ?? RawInput::to_mysql( $this->text( $input['entry'] ?? '' ) ) ?? '' ),
			'exit'          => (string) ( $data['exit_datetime'] ?? RawInput::to_mysql( $this->text( $input['exit'] ?? '' ) ) ?? '' ),
			'space_type_id' => (int) ( $data['space_type_id'] ?? 0 ),
			'space_id'      => $data['space_id'] ?? null,
			'quantity'      => (int) ( $data['quantity'] ?? 1 ),
		);

		foreach ( array( 'customer_name', 'customer_email', 'customer_phone', 'license_plate', 'vehicle', 'billing', 'notes', 'meta' ) as $field ) {
			if ( isset( $data[ $field ] ) ) {
				$request[ $field ] = $data[ $field ];
			}
		}

		if ( isset( $input['billing_type'] ) && is_string( $input['billing_type'] ) && '' !== trim( $input['billing_type'] ) ) {
			$request['billing_type'] = sanitize_key( $input['billing_type'] );
		}

		if ( isset( $input['coupon'] ) && is_scalar( $input['coupon'] ) ) {
			$request['coupon'] = sanitize_text_field( (string) $input['coupon'] );
		}

		// What another module prices, carried as that module cleaned it.
		$request += Quote::carried( $input );

		if ( isset( $input['gateway'] ) && is_scalar( $input['gateway'] ) ) {
			$request['gateway'] = sanitize_key( (string) $input['gateway'] );
		}

		$booking_form_id = $this->whole_number( $input['booking_form_id'] ?? 0 );
		if ( $booking_form_id > 0 ) {
			$request['booking_form_id'] = $booking_form_id;
		}

		$booking = $this->engine->create( $request );

		if ( $booking instanceof WP_Error ) {
			return $booking;
		}

		$id = (int) $booking['id'];

		// The engine files a booking under whoever is signed in, which for a customer is the
		// customer. Here that person is the administrator taking the booking, so it belongs to the
		// customer account the request names, or to none.
		$customer = $this->whole_number( $input['user_id'] ?? 0 );
		$after    = array( 'user_id' => $customer > 0 ? $customer : null );

		if ( isset( $data['status'] ) && $data['status'] !== $booking['status'] ) {
			$after += $this->status_change( $data['status'] );
		}

		$this->bookings->update( $id, $after );

		return $id;
	}

	/**
	 * Change a booking. Only the fields sent are touched.
	 *
	 * A RESCHEDULE IS CHECKED, AND IS NOT REPRICED. Moving the entry or exit, or changing the
	 * quantity, the space type or the space, is checked against the car park's opening hours and
	 * its free spaces, with this booking left out of the count. The booking keeps the total and the
	 * line items it was created with: what the customer agreed to pay does not change because the
	 * dates did.
	 *
	 * The same check is made when a booking that held no space starts to hold one, which is what
	 * reinstating a cancelled booking does. It is skipped while the booking is in a status that
	 * holds nothing, because there is nothing for it to collide with.
	 *
	 * `$force` lets an administrator put a booking into a window that is closed or full. It
	 * overrides those two checks and nothing else.
	 *
	 * @param int                  $id    Booking id.
	 * @param array<string, mixed> $input Raw, untrusted payload.
	 * @param bool                 $force Skip the opening-hours and free-space checks.
	 *
	 * @return bool|WP_Error
	 */
	public function update( int $id, array $input, bool $force = false ): bool|WP_Error {
		$existing = $this->bookings->find( $id );

		if ( null === $existing ) {
			return $this->not_found();
		}

		$refused = RawInput::first( $this->refuse_derived( $input ), $this->refuse_unusable( $input ) );
		if ( $refused instanceof WP_Error ) {
			return $refused;
		}

		$data = $this->sanitize( $input );

		$invalid = $this->validate( $data );
		if ( $invalid instanceof WP_Error ) {
			return $invalid;
		}

		if ( isset( $data['customer_email'] ) ) {
			$data['customer_email'] = sanitize_email( $data['customer_email'] );
		}

		if ( isset( $data['status'] ) ) {
			$data = $this->status_change( $data['status'] ) + $data;
		}

		// Answers are stored with the label each was asked under, which a change must not lose.
		if ( array_key_exists( 'meta', $data ) ) {
			$data['meta'] = $this->answers->store(
				$data['meta'],
				! empty( $existing['booking_form_id'] ) ? (int) $existing['booking_form_id'] : null,
				is_array( $existing['meta'] ?? null ) ? $existing['meta'] : array()
			);
		}

		if ( array() === $data ) {
			return true;
		}

		$merged = $data + $existing;
		$window = $this->window( $merged );
		$moved  = $this->is_reschedule( $data, $existing );

		if ( $moved && $window['entry'] >= $window['exit'] ) {
			return new WP_Error(
				'invalid_range',
				__( 'The exit time must be after the entry time.', 'plugixa-car-parking' ),
				array(
					'status' => 422,
					'field'  => 'exit_datetime',
				)
			);
		}

		$takes_space = $this->holds_space( $merged ) && ( $moved || ! $this->holds_space( $existing ) );

		if ( $force || ! $takes_space ) {
			$done = $this->bookings->update( $id, $data );
		} else {
			// The same lock the engine creates a booking under, so a move and a new booking cannot
			// both count the same free space.
			$done = $this->lock->run(
				'booking:' . $window['location_id'],
				function () use ( $id, $data, $window ): bool|WP_Error {
					$conflict = $this->conflict( $window, $id );

					return $conflict instanceof WP_Error ? $conflict : $this->bookings->update( $id, $data );
				}
			) ?? $this->busy();
		}

		if ( true === $done && isset( $data['status'] ) && $data['status'] !== $existing['status'] ) {
			/**
			 * Fires when someone in the admin changes a booking's status, one booking at a time
			 * or as part of a mass action. A customer cancelling their own booking fires
			 * `plugixa_carparking_booking_cancelled` instead.
			 *
			 * @param array<string, mixed> $booking The booking after the change.
			 * @param string               $from    The status before.
			 * @param string               $to      The status now.
			 */
			do_action( 'plugixa_carparking_booking_status_changed', (array) $this->bookings->find( $id ), (string) $existing['status'], (string) $data['status'] );
		}

		return $done;
	}

	/**
	 * Move a booking to the trash. Its space is free from that moment.
	 *
	 * @param int $id Booking id.
	 *
	 * @return bool|WP_Error
	 */
	public function delete( int $id ): bool|WP_Error {
		return null === $this->bookings->find( $id )
			? $this->not_found()
			: $this->bookings->delete( $id );
	}

	/**
	 * Bring a trashed booking back.
	 *
	 * Trashing it gave its space away, and somebody may have taken it since. A booking that holds
	 * a space is therefore checked like a reschedule before it returns.
	 *
	 * @param int  $id    Booking id.
	 * @param bool $force Skip the opening-hours and free-space checks.
	 *
	 * @return bool|WP_Error
	 */
	public function restore( int $id, bool $force = false ): bool|WP_Error {
		$booking = $this->bookings->find( $id, true );

		if ( null === $booking || null === $booking['deleted_at'] ) {
			return $this->not_found();
		}

		if ( $force || ! $this->holds_space( $booking ) ) {
			return $this->bookings->restore( $id );
		}

		$window = $this->window( $booking );

		$done = $this->lock->run(
			'booking:' . $window['location_id'],
			function () use ( $id, $window ): bool|WP_Error {
				$conflict = $this->conflict( $window, $id );

				return $conflict instanceof WP_Error ? $conflict : $this->bookings->restore( $id );
			}
		);

		return $done ?? $this->busy();
	}

	/**
	 * Destroy a booking for good, unless money has been recorded against it.
	 *
	 * The ledger is the record of what was received and refunded. A payment whose booking no longer
	 * exists is money nobody can account for, so the booking stays for as long as it has one.
	 *
	 * @param int $id Booking id.
	 *
	 * @return bool|WP_Error
	 */
	public function purge( int $id ): bool|WP_Error {
		if ( null === $this->bookings->find( $id, true ) ) {
			return $this->not_found();
		}

		if ( $this->payments->has_completed( $id ) ) {
			return new WP_Error(
				'plugixa_carparking_booking_has_payments',
				__( 'This booking has payments recorded against it, so it cannot be deleted permanently.', 'plugixa-car-parking' ),
				array( 'status' => 409 )
			);
		}

		return $this->bookings->purge( $id );
	}

	public function not_found(): WP_Error {
		return new WP_Error(
			'plugixa_carparking_not_found',
			__( 'That booking does not exist.', 'plugixa-car-parking' ),
			array( 'status' => 404 )
		);
	}

	// --- Cleaning and checking -----------------------------------------------

	/**
	 * Refuse a field that is worked out from the ledger, naming it.
	 *
	 * @param array<string, mixed> $input Raw payload.
	 */
	private function refuse_derived( array $input ): ?WP_Error {
		foreach ( self::DERIVED_FIELDS as $field ) {
			if ( array_key_exists( $field, $input ) ) {
				return new WP_Error(
					'plugixa_carparking_derived_field',
					sprintf(
						/* translators: %s: a field's key, e.g. "payment_status". */
						__( '"%s" is worked out from the booking\'s payments and cannot be set. Record a payment or a refund instead.', 'plugixa-car-parking' ),
						$field
					),
					array(
						'status' => 422,
						'field'  => $field,
					)
				);
			}
		}

		return null;
	}

	/**
	 * Refuse a value that was sent and cannot be used, before sanitize() would replace it.
	 *
	 * @param array<string, mixed> $input Raw payload.
	 */
	private function refuse_unusable( array $input ): ?WP_Error {
		return RawInput::first(
			RawInput::choice( $input, 'status', array_keys( $this->bookings->statuses() ), __( 'Status', 'plugixa-car-parking' ) ),
			RawInput::date( $input, 'entry_datetime', __( 'Entry', 'plugixa-car-parking' ) ),
			RawInput::date( $input, 'exit_datetime', __( 'Exit', 'plugixa-car-parking' ) ),
			RawInput::number( $input, 'quantity', __( 'Quantity', 'plugixa-car-parking' ) ),
			RawInput::number( $input, 'space_type_id', __( 'Space type', 'plugixa-car-parking' ) ),
			RawInput::number( $input, 'space_id', __( 'Space', 'plugixa-car-parking' ) )
		);
	}

	/**
	 * The editable fields of a payload, cleaned. A field that was not sent is not in the result.
	 *
	 * @param array<string, mixed> $input Raw payload.
	 *
	 * @return array<string, mixed>
	 */
	private function sanitize( array $input ): array {
		$data = array();

		foreach ( self::TEXT_FIELDS as $field ) {
			if ( array_key_exists( $field, $input ) ) {
				$data[ $field ] = sanitize_text_field( $this->text( $input[ $field ] ) );
			}
		}

		if ( array_key_exists( 'customer_email', $input ) ) {
			// Kept as sent, trimmed, so validate() can tell an address that is wrong from one that
			// is empty. sanitize_email() would turn both into an empty string.
			$data['customer_email'] = trim( $this->text( $input['customer_email'] ) );
		}

		if ( array_key_exists( 'notes', $input ) ) {
			$data['notes'] = sanitize_textarea_field( $this->text( $input['notes'] ) );
		}

		if ( isset( $input['status'] ) && is_string( $input['status'] ) && '' !== trim( $input['status'] ) ) {
			$data['status'] = sanitize_key( $input['status'] );
		}

		foreach ( array( 'entry_datetime', 'exit_datetime' ) as $field ) {
			$value = RawInput::to_mysql( $this->text( $input[ $field ] ?? '' ) );

			if ( null !== $value ) {
				$data[ $field ] = $value;
			}
		}

		foreach ( array( 'quantity', 'space_type_id' ) as $field ) {
			if ( isset( $input[ $field ] ) && '' !== $input[ $field ] ) {
				$data[ $field ] = $this->whole_number( $input[ $field ] );
			}
		}

		// An empty space is "no particular space", which is NULL.
		if ( array_key_exists( 'space_id', $input ) ) {
			$space            = $this->whole_number( $input['space_id'] );
			$data['space_id'] = $space > 0 ? $space : null;
		}

		foreach ( array( 'vehicle', 'billing', 'meta' ) as $field ) {
			if ( array_key_exists( $field, $input ) && is_array( $input[ $field ] ) ) {
				$data[ $field ] = $this->sanitize_map( $input[ $field ] );
			}
		}

		return $data;
	}

	/**
	 * A flat map of plain values under clean keys.
	 *
	 * What the keys mean belongs to whoever reads them. This only makes sure the stored blob is a
	 * map of scalars.
	 *
	 * @param array<mixed> $map What the request sent.
	 *
	 * @return array<string, bool|int|float|string>
	 */
	private function sanitize_map( array $map ): array {
		$clean = array();

		foreach ( $map as $key => $value ) {
			$key = sanitize_key( (string) $key );

			if ( '' === $key || ! is_scalar( $value ) ) {
				continue;
			}

			$clean[ $key ] = is_string( $value ) ? sanitize_text_field( $value ) : $value;
		}

		return $clean;
	}

	/**
	 * @param array<string, mixed> $data Sanitized payload.
	 */
	private function validate( array $data ): ?WP_Error {
		if ( isset( $data['customer_email'] ) && '' !== $data['customer_email'] && ! is_email( $data['customer_email'] ) ) {
			return new WP_Error(
				'plugixa_carparking_invalid_email',
				__( 'That is not an email address.', 'plugixa-car-parking' ),
				array(
					'status' => 422,
					'field'  => 'customer_email',
				)
			);
		}

		foreach ( array( 'quantity', 'space_type_id' ) as $field ) {
			if ( isset( $data[ $field ] ) && $data[ $field ] < 1 ) {
				return new WP_Error(
					'plugixa_carparking_invalid_' . $field,
					'quantity' === $field
						? __( 'A booking is for at least one space.', 'plugixa-car-parking' )
						: __( 'A booking needs a space type.', 'plugixa-car-parking' ),
					array(
						'status' => 422,
						'field'  => $field,
					)
				);
			}
		}

		return $this->bookings->too_long( $data );
	}

	/**
	 * What changes with a booking's status.
	 *
	 * A HOLD ONLY BELONGS TO A PENDING BOOKING. `hold_expires_at` is the moment an unpaid, pending
	 * booking stops holding its space, and availability honours it whatever the status says. A
	 * booking an administrator has confirmed must keep its space past that moment, so the hold is
	 * cleared with the change. Version 1 left it in place.
	 *
	 * @param string $status The new status.
	 *
	 * @return array<string, mixed> Columns to write.
	 */
	private function status_change( string $status ): array {
		return 'pending' === $status
			? array( 'status' => $status )
			: array(
				'status'          => $status,
				'hold_expires_at' => null,
			);
	}

	/**
	 * Whether a change moves the booking: one of the window fields was sent with a new value.
	 *
	 * @param array<string, mixed> $data     Sanitized payload.
	 * @param array<string, mixed> $existing The stored row.
	 */
	private function is_reschedule( array $data, array $existing ): bool {
		foreach ( self::WINDOW_FIELDS as $field ) {
			if ( array_key_exists( $field, $data ) && (string) $data[ $field ] !== (string) ( $existing[ $field ] ?? '' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a booking in this state takes up a space: the two rules availability counts by.
	 *
	 * @param array<string, mixed> $row A booking row, or one merged with the changes to it.
	 */
	private function holds_space( array $row ): bool {
		if ( ! in_array( $row['status'] ?? '', $this->availability->hold_statuses(), true ) ) {
			return false;
		}

		return empty( $row['hold_expires_at'] ) || (string) $row['hold_expires_at'] > current_time( 'mysql' );
	}

	/**
	 * Where and when a booking holds its space.
	 *
	 * @param array<string, mixed> $row A booking row, or one merged with the changes to it.
	 *
	 * @return array{location_id: int, space_type_id: int, space_id: int|null, entry: string, exit: string, quantity: int}
	 */
	private function window( array $row ): array {
		return array(
			'location_id'   => (int) $row['location_id'],
			'space_type_id' => (int) ( $row['space_type_id'] ?? 0 ),
			'space_id'      => ! empty( $row['space_id'] ) ? (int) $row['space_id'] : null,
			'entry'         => (string) $row['entry_datetime'],
			'exit'          => (string) $row['exit_datetime'],
			'quantity'      => max( 1, (int) $row['quantity'] ),
		);
	}

	/**
	 * Why a booking cannot hold this window, or null when it can. Call it under the location's lock.
	 *
	 * @param array{location_id: int, space_type_id: int, space_id: int|null, entry: string, exit: string, quantity: int} $window     From window().
	 * @param int                                                                                                         $exclude_id The booking itself, left out of the count.
	 */
	private function conflict( array $window, int $exclude_id ): ?WP_Error {
		$closed = $this->availability->check_open( $window['location_id'], $window['entry'], $window['exit'] );

		if ( $closed instanceof WP_Error ) {
			return $closed;
		}

		if ( $this->availability->can_book( $window['location_id'], $window['space_type_id'], $window['entry'], $window['exit'], $window['quantity'], $window['space_id'], $exclude_id ) ) {
			return null;
		}

		return new WP_Error(
			'no_availability',
			__( 'There is no free space for those dates. Send force to save the booking anyway.', 'plugixa-car-parking' ),
			array( 'status' => 409 )
		);
	}

	private function busy(): WP_Error {
		return new WP_Error(
			'busy',
			__( 'This car park is taking another booking right now. Please try again.', 'plugixa-car-parking' ),
			array( 'status' => 409 )
		);
	}

	/**
	 * @param array<string, mixed> $item A stored line item.
	 *
	 * @return array<string, mixed>
	 */
	private function cast_item( array $item ): array {
		$item['id']           = (int) ( $item['id'] ?? 0 );
		$item['booking_id']   = (int) ( $item['booking_id'] ?? 0 );
		$item['reference_id'] = isset( $item['reference_id'] ) ? (int) $item['reference_id'] : null;
		$item['quantity']     = (float) ( $item['quantity'] ?? 0 );
		$item['unit_price']   = (float) ( $item['unit_price'] ?? 0 );
		$item['total']        = (float) ( $item['total'] ?? 0 );

		return $item;
	}

	/**
	 * A value as text, or an empty string when it is not something that can be written as text.
	 *
	 * @param mixed $value What the request sent.
	 */
	private function text( mixed $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * A value as a whole number, or 0 when it is not a number.
	 *
	 * @param mixed $value What the request sent.
	 */
	private function whole_number( mixed $value ): int {
		return is_numeric( $value ) ? (int) $value : 0;
	}
}
