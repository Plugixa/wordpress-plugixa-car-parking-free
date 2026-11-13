<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Modules\Dashboard\Services;

use PlugixaCarParking\Core\Database\QueryBuilder;
use PlugixaCarParking\Core\Database\SlugSet;
use PlugixaCarParking\Core\WallClock;
use PlugixaCarParking\Modules\Bookings\Repositories\BookingRepository;
use PlugixaCarParking\Modules\Bookings\Services\Availability;
use PlugixaCarParking\Modules\Locations\Repositories\LocationRepository;
use PlugixaCarParking\Modules\Payments\Repositories\PaymentRepository;
use PlugixaCarParking\Modules\Payments\Services\PaymentAdminService;
use PlugixaCarParking\Modules\Spaces\Repositories\SpaceRepository;
use PlugixaCarParking\Modules\SpaceTypes\Repositories\SpaceTypeRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The dashboard's figures.
 *
 * EVERY FIGURE IS COUNTED THROUGH A REPOSITORY'S QUERY BUILDER, never with a statement written
 * here. The builder leaves trashed rows out and applies whatever narrows the records a user may
 * see, so a total cannot include a booking its reader is not allowed to open.
 *
 * TWO CLOCKS. "Today", "now" and a booking's entry and exit are the site's wall-clock time. The
 * day a booking was made or a payment recorded comes from `created_at`, which is UTC, and is
 * moved onto the site's clock before it is cut into days.
 */
final class Stats {

	/**
	 * Statuses of a booking that will not be honoured, left out of what is booked and upcoming.
	 *
	 * @var string[]
	 */
	private const DEAD_STATUSES = array( 'cancelled', 'refunded', 'expired' );

	/**
	 * How many days the two daily series cover, today included.
	 */
	private const SERIES_DAYS = 30;

	/**
	 * How many days back `bookings_per_day` reaches, not counting today.
	 */
	private const PER_DAY_REACH = 14;

	/**
	 * How many of the next arrivals are listed.
	 */
	private const UPCOMING_LIMIT = 6;

	/**
	 * The most locations the occupancy breakdown covers.
	 */
	private const LOCATIONS_LIMIT = 50;

	public function __construct(
		private readonly BookingRepository $bookings,
		private readonly PaymentRepository $payments,
		private readonly LocationRepository $locations,
		private readonly SpaceTypeRepository $space_types,
		private readonly SpaceRepository $spaces,
		private readonly Availability $availability
	) {}

	/**
	 * Every figure, keyed as the dashboard reads them.
	 *
	 * @return array<string, mixed>
	 */
	public function all(): array {
		$now   = current_time( 'mysql' );
		$today = current_time( 'Y-m-d' );
		$days  = $this->days( $today );

		$by_status = $this->bookings_by_status();
		$capacity  = (int) $this->figure( $this->spaces->query( 'dashboard' )->where( 't.status = %s', array( 'active' ) ), 'COALESCE(SUM(t.quantity), 0)' );
		$held      = (int) $this->figure(
			$this->holding( $now )->where( 't.entry_datetime <= %s', array( $now ) )->where( 't.exit_datetime > %s', array( $now ) ),
			'COALESCE(SUM(t.quantity), 0)'
		);

		$made = $this->bookings_made( $days );

		return array(
			'total_bookings'     => array_sum( $by_status ),
			'bookings_by_status' => $by_status,
			'revenue_collected'  => round( $this->figure( $this->bookings->query( 'dashboard' ), 'COALESCE(SUM(t.amount_paid), 0)' ), 2 ),
			'revenue_booked'     => round( $this->figure( $this->alive(), 'COALESCE(SUM(t.total), 0)' ), 2 ),
			'upcoming_bookings'  => (int) $this->figure( $this->alive()->where( 't.entry_datetime > %s', array( $now ) ), 'COUNT(*)' ),
			'total_locations'    => (int) $this->figure( $this->locations->query( 'dashboard' ), 'COUNT(*)' ),
			'total_space_types'  => (int) $this->figure( $this->space_types->query( 'dashboard' ), 'COUNT(*)' ),
			'total_capacity'     => $capacity,
			'bookings_per_day'   => $this->per_day( $made ),
			'recent_bookings'    => $this->recent(),
			'arrivals_today'     => $this->on_day( 't.entry_datetime', $today, $now ),
			'departures_today'   => $this->on_day( 't.exit_datetime', $today, $now ),
			// Capped at 1: a booking forced into a full car park makes it full, not 110% full.
			'occupancy_today'    => $capacity > 0 ? round( min( 1.0, $held / $capacity ), 4 ) : 0.0,
			'revenue_30d'        => $this->series( $this->revenue_received( $days ), 'amount' ),
			'bookings_30d'       => $this->series( $made, 'count' ),
			// The site's day, so the screen dates "today" by the car park's clock and not the reader's.
			'today'              => $today,
			'hold_statuses'      => $this->availability->hold_statuses(),
			'upcoming_arrivals'  => $this->upcoming( $now ),
			'occupancy_by_location' => $this->occupancy_by_location( $now ),
		);
	}

	/**
	 * How many bookings there are in each status.
	 *
	 * @return array<string, int>
	 */
	private function bookings_by_status(): array {
		$rows = $this->bookings
			->query( 'dashboard' )
			->group_by( array( 'status' => 't.status' ) )
			->aggregate( array( 'c' => 'COUNT(*)' ) );

		$counts = array();

		foreach ( $rows as $row ) {
			$counts[ (string) $row['status'] ] = (int) $row['c'];
		}

		return $counts;
	}

	/**
	 * Bookings that have not been cancelled, refunded or left to expire.
	 */
	private function alive(): QueryBuilder {
		return $this->bookings
			->query( 'dashboard' )
			->where( 't.status NOT IN (%s, %s, %s)', self::DEAD_STATUSES );
	}

	/**
	 * Bookings that take up a space: in a status that holds one, and not a hold that has lapsed.
	 *
	 * The same two rules availability counts by, so the dashboard and the booking form agree on
	 * what is full.
	 *
	 * @param string $now The moment to judge a hold against, on the site's clock.
	 */
	private function holding( string $now ): QueryBuilder {
		$statuses = $this->availability->hold_statuses();
		$query    = $this->bookings->query( 'dashboard' );

		if ( array() === $statuses ) {
			// No status holds a space on this site, so nothing matches.
			return $query->where( '1 = 0' );
		}

		return $query
			->where( 'FIND_IN_SET( t.status, %s )', array( SlugSet::csv( $statuses ) ) )
			->where( '(t.hold_expires_at IS NULL OR t.hold_expires_at > %s)', array( $now ) );
	}

	/**
	 * How many space-holding bookings have an entry, or an exit, on a day.
	 *
	 * @param string $column `t.entry_datetime` or `t.exit_datetime`.
	 * @param string $day    Y-m-d, on the site's clock.
	 * @param string $now    The moment to judge a hold against.
	 */
	private function on_day( string $column, string $day, string $now ): int {
		$range = array( $day . ' 00:00:00', $day . ' 23:59:59' );
		$query = 't.exit_datetime' === $column
			? $this->holding( $now )->where( 't.exit_datetime BETWEEN %s AND %s', $range )
			: $this->holding( $now )->where( 't.entry_datetime BETWEEN %s AND %s', $range );

		return (int) $this->figure( $query, 'COUNT(*)' );
	}

	/**
	 * How many bookings were made on each of the series' days.
	 *
	 * @param string[] $days The days, oldest first.
	 *
	 * @return array<string, float> Y-m-d => count, every day present.
	 */
	private function bookings_made( array $days ): array {
		return $this->daily( $this->bookings->query( 'dashboard' ), 'COUNT(*)', $days );
	}

	/**
	 * The money received on each of the series' days: completed payments less completed refunds.
	 *
	 * Read from the ledger, by the day each row was recorded. A booking's `amount_paid` says how
	 * much, and not when.
	 *
	 * @param string[] $days The days, oldest first.
	 *
	 * @return array<string, float> Y-m-d => amount, every day present.
	 */
	private function revenue_received( array $days ): array {
		$types = array_merge( PaymentAdminService::types(), array( 'refund' ) );
		$query = $this->payments
			->query( 'dashboard' )
			->where( 't.status = %s', array( 'completed' ) )
			->where( 'FIND_IN_SET( t.type, %s )', array( SlugSet::csv( $types ) ) );

		return $this->daily( $query, "SUM(CASE WHEN t.type = 'refund' THEN -t.amount ELSE t.amount END)", $days );
	}

	/**
	 * One aggregate per day, by the day each row was created.
	 *
	 * `created_at` is UTC. It is shifted by the site's offset before the date is taken, so a day
	 * here is the site's day. The offset is one number for the whole range; see
	 * {@see WallClock::utc_offset()} for what that costs across a clock change.
	 *
	 * @param QueryBuilder $query     A builder over a table with `created_at`.
	 * @param string       $aggregate The SQL aggregate. A literal from the caller.
	 * @param string[]     $days      The days to report, oldest first.
	 *
	 * @return array<string, float> Y-m-d => value, every day present, zero where nothing happened.
	 */
	private function daily( QueryBuilder $query, string $aggregate, array $days ): array {
		$rows = $query
			->where( 't.created_at >= %s', array( get_gmt_from_date( $days[0] . ' 00:00:00' ) ) )
			->group_by( array( 'd' => 'DATE(DATE_ADD(t.created_at, INTERVAL %d SECOND))' ), array( WallClock::utc_offset() ) )
			->aggregate( array( 'v' => $aggregate ), self::SERIES_DAYS + 2 );

		$values = array_fill_keys( $days, 0.0 );

		foreach ( $rows as $row ) {
			if ( isset( $values[ $row['d'] ] ) ) {
				$values[ $row['d'] ] = (float) $row['v'];
			}
		}

		return $values;
	}

	/**
	 * The days that had a booking, as `d` and `c`: today and the fourteen days before it.
	 *
	 * @param array<string, float> $made Y-m-d => bookings made, from bookings_made().
	 *
	 * @return array<int, array{d: string, c: int}>
	 */
	private function per_day( array $made ): array {
		$out = array();

		foreach ( array_slice( $made, -( self::PER_DAY_REACH + 1 ), null, true ) as $day => $count ) {
			if ( $count > 0 ) {
				$out[] = array(
					'd' => $day,
					'c' => (int) $count,
				);
			}
		}

		return $out;
	}

	/**
	 * A day-keyed map as a list a chart can draw in order.
	 *
	 * @param array<string, float> $values Y-m-d => value.
	 * @param string               $key    What the value is called: `count` or `amount`.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function series( array $values, string $key ): array {
		$out = array();

		foreach ( $values as $day => $value ) {
			$out[] = array(
				'date' => $day,
				$key   => 'count' === $key ? (int) $value : round( $value, 2 ),
			);
		}

		return $out;
	}

	/**
	 * The five bookings made most recently.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function recent(): array {
		$rows = $this->bookings
			->query( 'dashboard' )
			->select( 't.id', 't.booking_ref', 't.status', 't.customer_name', 't.entry_datetime', 't.exit_datetime', 't.total', 't.currency', 't.location_id' )
			->order_by( 't.created_at', 'DESC' )
			->paginate( 5, 0 )
			->get();

		return array_map( array( $this->bookings, 'cast' ), $rows );
	}

	/**
	 * The next bookings due to arrive, soonest first.
	 *
	 * Narrowed exactly as `upcoming_bookings` is counted, so the list is the head of that count.
	 *
	 * @param string $now The moment after which an entry is still to come, on the site's clock.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function upcoming( string $now ): array {
		$rows = $this->alive()
			->where( 't.entry_datetime > %s', array( $now ) )
			->select( 't.id', 't.booking_ref', 't.status', 't.customer_name', 't.entry_datetime', 't.exit_datetime', 't.quantity', 't.location_id' )
			->order_by( 't.entry_datetime', 'ASC' )
			->paginate( self::UPCOMING_LIMIT, 0 )
			->get();

		return array_map( array( $this->bookings, 'cast' ), $rows );
	}

	/**
	 * The spaces held right now against the capacity, for each location that has any capacity.
	 *
	 * The same two counts `occupancy_today` is made of, grouped by location. `held` is not capped:
	 * a location forced past full reports more held than it has, and the reader decides how to
	 * draw that.
	 *
	 * @param string $now The moment to count at, on the site's clock.
	 *
	 * @return array<int, array{location_id: int, name: string, capacity: int, held: int}>
	 */
	private function occupancy_by_location( string $now ): array {
		$capacity = $this->per_location( $this->spaces->query( 'dashboard' )->where( 't.status = %s', array( 'active' ) ) );
		$held     = $this->per_location(
			$this->holding( $now )->where( 't.entry_datetime <= %s', array( $now ) )->where( 't.exit_datetime > %s', array( $now ) )
		);

		$locations = $this->locations
			->query( 'dashboard' )
			->select( 't.id', 't.name' )
			->order_by( 't.display_order', 'ASC' )
			->paginate( self::LOCATIONS_LIMIT, 0 )
			->get();

		$out = array();

		foreach ( $locations as $location ) {
			$id = (int) $location['id'];

			if ( ( $capacity[ $id ] ?? 0 ) < 1 ) {
				continue;
			}

			$out[] = array(
				'location_id' => $id,
				'name'        => (string) $location['name'],
				'capacity'    => $capacity[ $id ],
				'held'        => $held[ $id ] ?? 0,
			);
		}

		return $out;
	}

	/**
	 * A query's summed `quantity`, keyed by location.
	 *
	 * @param QueryBuilder $query A narrowed builder over a table with `location_id` and `quantity`.
	 *
	 * @return array<int, int>
	 */
	private function per_location( QueryBuilder $query ): array {
		$rows = $query
			->group_by( array( 'location_id' => 't.location_id' ) )
			->aggregate( array( 'v' => 'COALESCE(SUM(t.quantity), 0)' ) );

		$out = array();

		foreach ( $rows as $row ) {
			$out[ (int) $row['location_id'] ] = (int) $row['v'];
		}

		return $out;
	}

	/**
	 * The series' days, oldest first, ending today.
	 *
	 * @param string $today Y-m-d, on the site's clock.
	 *
	 * @return string[]
	 */
	private function days( string $today ): array {
		$end  = (int) strtotime( $today . ' 00:00:00 UTC' );
		$days = array();

		for ( $back = self::SERIES_DAYS - 1; $back >= 0; $back-- ) {
			$days[] = gmdate( 'Y-m-d', $end - $back * DAY_IN_SECONDS );
		}

		return $days;
	}

	/**
	 * One number from a query.
	 *
	 * @param QueryBuilder $query     The narrowed builder.
	 * @param string       $aggregate The SQL aggregate. A literal from the caller.
	 */
	private function figure( QueryBuilder $query, string $aggregate ): float {
		$rows = $query->aggregate( array( 'v' => $aggregate ), 1 );

		return (float) ( $rows[0]['v'] ?? 0 );
	}
}
