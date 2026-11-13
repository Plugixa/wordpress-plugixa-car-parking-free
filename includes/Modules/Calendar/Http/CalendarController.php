<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Modules\Calendar\Http;

use PlugixaCarParking\Core\Entities\RawInput;
use PlugixaCarParking\Core\Database\SlugSet;
use PlugixaCarParking\Core\Http\RestController;
use PlugixaCarParking\Modules\Bookings\Repositories\BookingRepository;
use PlugixaCarParking\Modules\Spaces\Repositories\SpaceRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The bookings overlapping a span of dates, for the calendar's month grid.
 *
 * What it returns is booking data, so it is authorised as bookings are.
 */
final class CalendarController extends RestController {

	/**
	 * The most bookings one span returns. A month grid asks for about six weeks.
	 */
	private const LIMIT = 5000;

	/**
	 * What a calendar entry carries. Written here, never taken from the request.
	 *
	 * @var string[]
	 */
	private const COLUMNS = array(
		't.id',
		't.booking_ref',
		't.status',
		't.payment_status',
		't.customer_name',
		't.entry_datetime',
		't.exit_datetime',
		't.quantity',
		't.total',
		't.currency',
		't.location_id',
		't.space_type_id',
	);

	public function __construct(
		private readonly BookingRepository $bookings,
		private readonly SpaceRepository $spaces
	) {}

	protected function entity_type(): string {
		return 'booking';
	}

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/calendar',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'index' ),
					'permission_callback' => array( $this, 'can_read' ),
				),
			)
		);
	}

	/**
	 * Bookings whose stay touches the span, earliest entry first, and the capacity to hold them
	 * against.
	 *
	 * A booking is in the span when it enters on or before the span's end and leaves on or after
	 * its start. Both ends count, so a booking that leaves on the first day shown is drawn on it.
	 *
	 * Trashed bookings are left out: a trashed booking holds no space.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function index( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$from = $this->bound( $request->get_param( 'from' ), '00:00:00' );
		$to   = $this->bound( $request->get_param( 'to' ), '23:59:59' );

		if ( null === $from || null === $to ) {
			return $this->fail(
				'plugixa_carparking_invalid_range',
				__( 'A calendar needs a from date and a to date, written YYYY-MM-DD.', 'plugixa-car-parking' ),
				422
			);
		}

		if ( $from > $to ) {
			return $this->fail(
				'plugixa_carparking_invalid_range',
				__( 'The to date must be on or after the from date.', 'plugixa-car-parking' ),
				422
			);
		}

		$location_id   = (int) $request->get_param( 'location_id' );
		$space_type_id = (int) $request->get_param( 'space_type_id' );

		$query = $this->bookings
			->query( 'calendar' )
			->select( ...self::COLUMNS )
			->where( 't.entry_datetime <= %s', array( $to ) )
			->where( 't.exit_datetime >= %s', array( $from ) );

		if ( $location_id > 0 ) {
			$query->where( 't.location_id = %d', array( $location_id ) );
		}

		if ( $space_type_id > 0 ) {
			$query->where( 't.space_type_id = %d', array( $space_type_id ) );
		}

		$statuses = $this->statuses( $request->get_param( 'status' ) );

		if ( array() !== $statuses ) {
			$query->where( 'FIND_IN_SET( t.status, %s )', array( SlugSet::csv( $statuses ) ) );
		}

		$rows = array_map( array( $this->bookings, 'cast' ), $this->bookings->drain( $query, self::LIMIT ) );

		usort(
			$rows,
			static fn( array $a, array $b ): int => array( $a['entry_datetime'], $a['id'] ) <=> array( $b['entry_datetime'], $b['id'] )
		);

		$rows = $this->resolve_names(
			$rows,
			array(
				'location_name'   => array( 'location_id', 'location' ),
				'space_type_name' => array( 'space_type_id', 'space_type' ),
			)
		);

		return $this->ok(
			array(
				'bookings' => $rows,
				'capacity' => $this->capacity( $location_id, $space_type_id ),
			)
		);
	}

	/**
	 * One end of the span as a datetime, or null when it is missing or not a date.
	 *
	 * A date sent without a time means the whole of that day: its first second as the start of a
	 * span, its last as the end. Read literally, a `to` of 2027-05-31 would end the span at
	 * midnight and leave out everything that arrives during the 31st.
	 *
	 * @param mixed  $value What the request sent.
	 * @param string $time  The time a bare date stands for at this end of the span.
	 */
	private function bound( mixed $value, string $time ): ?string {
		$value = is_string( $value ) ? trim( $value ) : '';

		if ( 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			$value .= ' ' . $time;
		}

		return RawInput::to_mysql( $value );
	}

	/**
	 * The statuses asked for: one, or several separated by commas.
	 *
	 * @param mixed $value What the request sent.
	 *
	 * @return string[]
	 */
	private function statuses( mixed $value ): array {
		if ( ! is_string( $value ) ) {
			return array();
		}

		return array_values( array_unique( array_filter( array_map( 'sanitize_key', explode( ',', $value ) ) ) ) );
	}

	/**
	 * How many spaces the bookings shown are competing for.
	 *
	 * Zero unless the calendar is showing one location: a figure summed across car parks says
	 * nothing about whether any one of them is full on a given day.
	 *
	 * @param int $location_id   The location shown, or 0 for all of them.
	 * @param int $space_type_id The space type shown, or 0 for all of them.
	 */
	private function capacity( int $location_id, int $space_type_id ): int {
		if ( $location_id < 1 ) {
			return 0;
		}

		$spaces = $this->spaces->for_location( $location_id, $space_type_id > 0 ? $space_type_id : null );

		return (int) array_sum( array_map( 'intval', array_column( $spaces, 'quantity' ) ) );
	}
}
