<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Modules\Bookings\Services;

use PlugixaCarParking\Core\Compat\LegacyHooks;
use PlugixaCarParking\Core\Settings;
use PlugixaCarParking\Modules\Bookings\Repositories\BookingRepository;
use PlugixaCarParking\Modules\Invoice\Services\Invoice;
use PlugixaCarParking\Modules\Locations\Repositories\LocationRepository;
use PlugixaCarParking\Modules\SpaceTypes\Repositories\SpaceTypeRepository;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What a customer may see and do with their own bookings.
 *
 * A booking belongs to whoever is signed in as the user it was made by, or to whoever knows both
 * its reference and the email address it was made with. The second is how a guest, who has no
 * account, reaches theirs.
 *
 * `project()` is the public view of a booking. It names each column it exposes, so contact
 * details, the licence plate, the user id and the audit columns never leave through it.
 */
final class CustomerBookings {

	/**
	 * The statuses a customer may still cancel or move a booking in.
	 *
	 * @var string[]
	 */
	public const CHANGEABLE = array( 'pending', 'confirmed', 'paid' );

	/**
	 * Location and space type names already looked up, keyed by id.
	 *
	 * @var array{location: array<int, ?string>, space_type: array<int, ?string>}
	 */
	private array $names = array(
		'location'   => array(),
		'space_type' => array(),
	);

	public function __construct(
		private readonly BookingRepository $bookings,
		private readonly LocationRepository $locations,
		private readonly SpaceTypeRepository $space_types
	) {}

	/**
	 * A signed-in customer's bookings, latest entry first.
	 *
	 * @param int $user_id The WordPress user.
	 *
	 * @return array<int, array<string, mixed>> Booking rows.
	 */
	public function for_user( int $user_id ): array {
		return $this->bookings->for_user( $user_id );
	}

	/**
	 * The booking a guest names by reference and email, or null when the two do not match.
	 *
	 * A wrong reference and a wrong email get the same answer, so the lookup cannot be used to
	 * learn which references exist.
	 *
	 * @param string $ref   Booking reference.
	 * @param string $email The email address the booking was made with.
	 *
	 * @return array<string, mixed>|null
	 */
	public function lookup( string $ref, string $email ): ?array {
		$booking = $this->bookings->find_by_ref( $ref );

		if ( null === $booking || ! $this->email_matches( $booking, $email ) ) {
			return null;
		}

		return $booking;
	}

	/**
	 * The booking behind a reference, if the caller owns it.
	 *
	 * @param string $ref   Booking reference.
	 * @param string $email The email the caller supplied, or '' when they supplied none.
	 *
	 * @return array<string, mixed>|WP_Error The booking, or a 404 or 403.
	 */
	public function owned( string $ref, string $email ): array|WP_Error {
		$booking = $this->bookings->find_by_ref( $ref );

		if ( null === $booking ) {
			return self::refusal( __( 'Booking not found.', 'plugixa-car-parking' ), 404 );
		}

		$user_id = get_current_user_id();

		if ( $user_id && (int) $booking['user_id'] === $user_id ) {
			return $booking;
		}

		if ( $this->email_matches( $booking, $email ) ) {
			return $booking;
		}

		return self::refusal( __( 'You are not allowed to manage this booking.', 'plugixa-car-parking' ), 403 );
	}

	/**
	 * Cancel a booking for its customer.
	 *
	 * @param array<string, mixed> $booking The booking row, already shown to be the caller's.
	 *
	 * @return array<string, mixed>|WP_Error The cancelled booking, or why it cannot be cancelled.
	 */
	public function cancel( array $booking ): array|WP_Error {
		if ( ! in_array( $booking['status'], self::CHANGEABLE, true ) ) {
			return self::refusal( __( 'This booking can no longer be cancelled.', 'plugixa-car-parking' ), 409 );
		}

		if ( ! $this->within_cancel_window( $booking ) ) {
			return self::refusal( __( 'The cancellation cut-off for this booking has passed.', 'plugixa-car-parking' ), 409 );
		}

		$this->bookings->update( (int) $booking['id'], array( 'status' => 'cancelled' ) );

		$fresh = (array) $this->bookings->find( (int) $booking['id'] );

		/**
		 * Fires when a customer cancels their own booking.
		 *
		 * @param array $fresh The cancelled booking.
		 */
		do_action( 'plugixa_carparking_booking_cancelled', $fresh );
		LegacyHooks::action( 'plugixacarparking_booking_cancelled', 'plugixa_carparking_booking_cancelled', array( $fresh ) );

		return $fresh;
	}

	/**
	 * Whether the site's cancellation cut-off before entry has not yet passed.
	 *
	 * @param array<string, mixed> $booking The booking row.
	 */
	public function within_cancel_window( array $booking ): bool {
		$cutoff_hours = (int) Settings::get( 'cancel_cutoff_hours', 0 );

		// The entry is the site's wall-clock time; compare both sides as true instants so the
		// cut-off is right on a site that is not on UTC.
		$entry_ts = strtotime( get_gmt_from_date( (string) $booking['entry_datetime'] ) );

		if ( ! $entry_ts ) {
			return true;
		}

		return time() <= ( $entry_ts - $cutoff_hours * HOUR_IN_SECONDS );
	}

	/**
	 * A booking as its customer may see it.
	 *
	 * @param array<string, mixed> $booking The booking row.
	 *
	 * @return array<string, mixed>
	 */
	public function project( array $booking ): array {
		return array(
			'id'              => (int) $booking['id'],
			'booking_ref'     => $booking['booking_ref'],
			'status'          => $booking['status'],
			'payment_status'  => $booking['payment_status'] ?? 'unpaid',
			'entry_datetime'  => $booking['entry_datetime'],
			'exit_datetime'   => $booking['exit_datetime'],
			'quantity'        => (int) ( $booking['quantity'] ?? 1 ),
			'total'           => (float) ( $booking['total'] ?? 0 ),
			'currency'        => $booking['currency'] ?? null,
			'location_name'   => $this->name( 'location', (int) ( $booking['location_id'] ?? 0 ) ),
			'space_type_name' => $this->name( 'space_type', (int) ( $booking['space_type_id'] ?? 0 ) ),
			'can_cancel'      => in_array( $booking['status'], self::CHANGEABLE, true ) && $this->within_cancel_window( $booking ),
			'summary_url'     => Invoice::summary_url( (string) $booking['booking_ref'] ),
		);
	}

	/**
	 * Whether an email is the one a booking was made with. An empty one never is.
	 *
	 * @param array<string, mixed> $booking The booking row.
	 * @param string               $email   The email the caller supplied.
	 */
	private function email_matches( array $booking, string $email ): bool {
		return '' !== $email && strtolower( (string) $booking['customer_email'] ) === strtolower( $email );
	}

	/**
	 * The name of a booking's location or space type, looked up once per id.
	 *
	 * A trashed one is still named: the booking was made there, whatever happened to it since.
	 *
	 * @param string $kind 'location' or 'space_type'.
	 * @param int    $id   Its id, or 0 when the booking has none.
	 */
	private function name( string $kind, int $id ): ?string {
		if ( $id <= 0 ) {
			return null;
		}

		if ( ! array_key_exists( $id, $this->names[ $kind ] ) ) {
			$row = 'location' === $kind ? $this->locations->find( $id, true ) : $this->space_types->find( $id, true );

			$this->names[ $kind ][ $id ] = isset( $row['name'] ) ? (string) $row['name'] : null;
		}

		return $this->names[ $kind ][ $id ];
	}

	/**
	 * A refusal in the shape the public scripts read.
	 *
	 * @param string $message A sentence for the customer.
	 * @param int    $status  HTTP status.
	 */
	public static function refusal( string $message, int $status ): WP_Error {
		return new WP_Error(
			'rest_error',
			$message,
			array(
				'status' => $status,
				'errors' => array(),
			)
		);
	}
}
