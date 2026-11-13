<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Modules\Bookings\Services;

use PlugixaCarParking\Core\Settings;
use PlugixaCarParking\Modules\Locations\Repositories\ExceptionsTable;
use PlugixaCarParking\Modules\Locations\Repositories\HoursTable;
use PlugixaCarParking\Modules\Locations\Repositories\LocationRepository;
use PlugixaCarParking\Modules\Spaces\Repositories\SpaceRepository;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether, and how much of, a location can be booked in a window.
 *
 * Three questions, asked in this order by anything that creates or moves a booking: is the window
 * itself acceptable, is the car park open at both ends of it, and is there a space.
 */
final class Availability {

	private const DEFAULT_HOLD_STATUSES = array( 'pending', 'confirmed', 'paid', 'completed' );

	public function __construct(
		private readonly LocationRepository $locations,
		private readonly SpaceRepository $spaces,
		private readonly HoursTable $hours,
		private readonly ExceptionsTable $exceptions,
		private readonly ModeRegistry $modes
	) {}

	/**
	 * The booking statuses that take up a space. Configurable in settings.
	 *
	 * @return string[]
	 */
	public function hold_statuses(): array {
		$list = Settings::get( 'inventory_hold_statuses', self::DEFAULT_HOLD_STATUSES );

		return is_array( $list ) ? array_values( $list ) : self::DEFAULT_HOLD_STATUSES;
	}

	/**
	 * Check a requested window against the basic booking rules.
	 *
	 * @param string $entry Requested entry.
	 * @param string $exit  Requested exit.
	 *
	 * @return WP_Error|null Null when the window is acceptable.
	 */
	public function validate_window( string $entry, string $exit ): ?WP_Error {
		if ( ! strtotime( $entry ) || ! strtotime( $exit ) ) {
			return new WP_Error( 'invalid_dates', __( 'Invalid entry or exit date.', 'plugixa-car-parking' ), array( 'status' => 422 ) );
		}

		// The customer types the site's wall-clock time; compare every side as a true instant so
		// the advance-booking limits are right on a site that is not on UTC.
		$entry_ts = strtotime( get_gmt_from_date( $entry ) );
		$exit_ts  = strtotime( get_gmt_from_date( $exit ) );
		$now_ts   = time();

		if ( $entry_ts >= $exit_ts ) {
			return new WP_Error( 'invalid_range', __( 'The exit time must be after the entry time.', 'plugixa-car-parking' ), array( 'status' => 422 ) );
		}

		$min_advance = (int) Settings::get( 'min_advance_minutes', 0 );
		$max_advance = (int) Settings::get( 'max_advance_days', 365 );

		if ( $entry_ts < ( $now_ts + $min_advance * 60 ) ) {
			return new WP_Error( 'too_soon', __( 'The entry time is too soon or in the past.', 'plugixa-car-parking' ), array( 'status' => 422 ) );
		}
		if ( $max_advance > 0 && $entry_ts > ( $now_ts + $max_advance * 86400 ) ) {
			return new WP_Error( 'too_far', __( 'The entry date is too far in the future.', 'plugixa-car-parking' ), array( 'status' => 422 ) );
		}

		return null;
	}

	/**
	 * Check a location is open at both the entry and the exit.
	 *
	 * Each moment must fall on an open weekday within that day's hours, and outside any closure.
	 * A "special" exception replaces the weekday's hours for its dates. A location with no hours
	 * configured is open around the clock on every day that has no exception.
	 *
	 * A CLOSURE APPLIES WHETHER OR NOT WEEKLY HOURS ARE SET. Version 1 returned "open" as soon as
	 * it found no weekly hours, before looking at the dated exceptions, so a car park that never
	 * closes in a normal week could not be closed for a single day either.
	 *
	 * @param int    $location_id The location.
	 * @param string $entry       Requested entry.
	 * @param string $exit        Requested exit.
	 *
	 * @return WP_Error|null Null when the location is open at both moments.
	 */
	public function check_open( int $location_id, string $entry, string $exit ): ?WP_Error {
		$hours = $this->hours->map( $location_id );

		$moments = array(
			'entry' => $entry,
			'exit'  => $exit,
		);

		foreach ( $moments as $which => $datetime ) {
			$ts = strtotime( $datetime );
			if ( ! $ts ) {
				continue;
			}

			$date = gmdate( 'Y-m-d', $ts );
			$time = gmdate( 'H:i:s', $ts );
			$dow  = (int) gmdate( 'w', $ts );

			$open_time  = null;
			$close_time = null;
			$resolved   = false;

			// A closure sorts first, so if the date has one it is the row this loop sees.
			foreach ( $this->exceptions->covering( $location_id, $date ) as $exception ) {
				if ( 'closed' === ( $exception['type'] ?? 'closed' ) ) {
					return $this->closed_error( $which, $date );
				}

				$open_time  = $exception['open_time'];
				$close_time = $exception['close_time'];
				$resolved   = true;
				break;
			}

			if ( ! $resolved ) {
				$row = $hours[ $dow ] ?? null;
				if ( $row && 1 === (int) $row['is_closed'] ) {
					return $this->closed_error( $which, $date );
				}

				$open_time  = $row['open_time'] ?? null;
				$close_time = $row['close_time'] ?? null;
			}

			if ( $open_time && $time < $open_time ) {
				return $this->hours_error( $which );
			}
			if ( $close_time && $time > $close_time ) {
				return $this->hours_error( $which );
			}
		}

		return null;
	}

	/**
	 * What is free at a location in a window, per space type.
	 *
	 * @param int      $location_id   The location.
	 * @param string   $entry         Requested entry.
	 * @param string   $exit          Requested exit.
	 * @param int|null $space_type_id Restrict to one space type.
	 *
	 * @return array<int, array<string, mixed>> One entry per space type.
	 */
	public function for_location( int $location_id, string $entry, string $exit, ?int $space_type_id = null ): array {
		$location = $this->locations->find( $location_id );
		if ( null === $location ) {
			return array();
		}

		$strategy = $this->modes->strategy( (string) ( $location['inventory_mode'] ?? CapacityStrategy::MODE ) );
		$hold     = $this->hold_statuses();
		$type_ids = $space_type_id ? array( $space_type_id ) : $this->spaces->space_type_ids( $location_id );

		$result = array();
		foreach ( $type_ids as $type_id ) {
			$result[] = $strategy->availability( $location_id, (int) $type_id, $entry, $exit, $hold );
		}

		return $result;
	}

	/**
	 * Whether one request can be fulfilled right now.
	 *
	 * The final guard before a booking is written. On its own it does not stop two requests both
	 * passing it at the same moment; the caller holds a lock around this check and its insert.
	 *
	 * @param int      $location_id   The location.
	 * @param int      $space_type_id The space type.
	 * @param string   $entry         Requested entry.
	 * @param string   $exit          Requested exit.
	 * @param int      $quantity      Spaces wanted.
	 * @param int|null $space_id      A particular space, where the location's mode has such a thing.
	 * @param int|null $exclude_id    A booking to ignore, e.g. the one being rescheduled.
	 */
	public function can_book( int $location_id, int $space_type_id, string $entry, string $exit, int $quantity, ?int $space_id = null, ?int $exclude_id = null ): bool {
		$location = $this->locations->find( $location_id );
		if ( null === $location ) {
			return false;
		}

		return $this->modes
			->strategy( (string) ( $location['inventory_mode'] ?? CapacityStrategy::MODE ) )
			->can_book( $location_id, $space_type_id, $entry, $exit, $quantity, $space_id, $exclude_id, $this->hold_statuses() );
	}

	/**
	 * The particular space a booking would be held to: null where the location counts a pool,
	 * 0 where it identifies spaces and none can be held.
	 *
	 * @param int      $location_id   The location.
	 * @param int      $space_type_id The space type.
	 * @param string   $entry         Requested entry.
	 * @param string   $exit          Requested exit.
	 * @param int|null $requested     The space the customer chose, if any.
	 * @param int|null $exclude_id    A booking to ignore, e.g. the one being moved.
	 */
	public function space_for( int $location_id, int $space_type_id, string $entry, string $exit, ?int $requested, ?int $exclude_id = null ): ?int {
		$location = $this->locations->find( $location_id );
		if ( null === $location ) {
			return 0;
		}

		return $this->modes
			->strategy( (string) ( $location['inventory_mode'] ?? CapacityStrategy::MODE ) )
			->space_for( $location_id, $space_type_id, $entry, $exit, $requested, $exclude_id, $this->hold_statuses() );
	}

	private function closed_error( string $which, string $date ): WP_Error {
		return new WP_Error(
			'location_closed',
			'exit' === $which
				/* translators: %s: the exit date, formatted Y-m-d */
				? sprintf( __( 'The car park is closed on the exit date (%s).', 'plugixa-car-parking' ), $date )
				/* translators: %s: the entry date, formatted Y-m-d */
				: sprintf( __( 'The car park is closed on the entry date (%s).', 'plugixa-car-parking' ), $date ),
			array( 'status' => 422 )
		);
	}

	private function hours_error( string $which ): WP_Error {
		return new WP_Error(
			'outside_hours',
			'exit' === $which
				? __( 'The exit time is outside the car park business hours.', 'plugixa-car-parking' )
				: __( 'The entry time is outside the car park business hours.', 'plugixa-car-parking' ),
			array( 'status' => 422 )
		);
	}
}
