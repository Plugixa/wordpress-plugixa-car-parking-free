<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Modules\Bookings\Services;

use PlugixaCarParking\Modules\SpaceTypes\Repositories\PriceTable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns a booking window into billable units and applies the base rates.
 */
final class Pricing {

	/**
	 * A booking's billing type, mapped to the price row that holds its rate.
	 *
	 * @var array<string, string>
	 */
	public const USE_TYPE_MAP = array(
		'minute' => 'rental_minute',
		'hour'   => 'rental_hour',
		'day'    => 'rental_day',
		'week'   => 'rental_week',
		'month'  => 'rental_month',
	);

	public function __construct( private readonly PriceTable $prices ) {}

	/**
	 * Seconds in one unit of a billing type. A month is 30 days here, for whatever needs one length
	 * for it; a stay is counted in calendar months ({@see calculate_units()}).
	 *
	 * @param string $billing_type minute|hour|day|week|month.
	 */
	public function unit_seconds( string $billing_type ): int {
		switch ( $billing_type ) {
			case 'minute':
				return 60;
			case 'hour':
				return 3600;
			case 'week':
				return 604800;
			case 'month':
				return 2592000;
			case 'day':
			default:
				return 86400;
		}
	}

	/**
	 * How many units a window is billed as.
	 *
	 * THE POLICY, AND IT IS A BUSINESS DECISION: a part unit is rounded UP, and the minimum is
	 * one unit. A 25-hour stay billed per day is two days; a 90-minute stay billed per hour is
	 * two hours. This is the common parking convention ("you pay for the day you enter").
	 *
	 * A MONTH IS A CALENDAR MONTH: 15 January to 15 April is three months, and 1 January to
	 * 1 January is twelve, whatever the months' lengths. Counted in blocks of 30 days the year
	 * would be thirteen, and a stay paid by the month would be charged a month nobody stays.
	 *
	 * Change only this method to alter how every booking is priced.
	 *
	 * @param string $entry        Entry datetime.
	 * @param string $exit         Exit datetime.
	 * @param string $billing_type minute|hour|day|week|month.
	 *
	 * @return float Billable units, never less than 1.
	 */
	public function calculate_units( string $entry, string $exit, string $billing_type ): float {
		if ( 'month' === $billing_type ) {
			return (float) self::calendar_months( $entry, $exit );
		}

		$seconds = max( 0, strtotime( $exit ) - strtotime( $entry ) );
		$unit    = $this->unit_seconds( $billing_type );

		if ( $unit <= 0 ) {
			return 1.0;
		}

		return max( 1.0, (float) ceil( $seconds / $unit ) );
	}

	/**
	 * How many calendar months a window spans, a part month counted as one, never less than one.
	 *
	 * Months are stepped on the calendar from the entry, so the 31st of a month runs to the last
	 * day of a shorter one: 31 January to 28 February is one month.
	 *
	 * @param string $entry Entry datetime.
	 * @param string $exit  Exit datetime.
	 */
	public static function calendar_months( string $entry, string $exit ): int {
		try {
			$from = new \DateTimeImmutable( $entry );
			$to   = new \DateTimeImmutable( $exit );
		} catch ( \Exception ) {
			return 1;
		}

		if ( $to <= $from ) {
			return 1;
		}

		$span   = $from->diff( $to );
		$months = $span->y * 12 + $span->m;

		if ( $span->d > 0 || $span->h > 0 || $span->i > 0 || $span->s > 0 ) {
			++$months;
		}

		return max( 1, $months );
	}

	/**
	 * The base charge for one space of a type over a window.
	 *
	 * @param int      $space_type_id The space type.
	 * @param int|null $location_id   The location, for a location-specific price.
	 * @param string   $billing_type  minute|hour|day|week|month.
	 * @param string   $entry         Entry datetime.
	 * @param string   $exit          Exit datetime.
	 *
	 * @return array{use_type: string, units: float, unit_price: float, initial_fee: float, subtotal: float}
	 */
	public function base_charge( int $space_type_id, ?int $location_id, string $billing_type, string $entry, string $exit ): array {
		$use_type = self::USE_TYPE_MAP[ $billing_type ] ?? 'rental_day';
		$units    = $this->calculate_units( $entry, $exit, $billing_type );

		$rate    = $this->prices->get_rate( $space_type_id, $location_id, $use_type ) ?? 0.0;
		$initial = $this->prices->get_rate( $space_type_id, $location_id, 'initial' ) ?? 0.0;

		return array(
			'use_type'    => $use_type,
			'units'       => $units,
			'unit_price'  => $rate,
			'initial_fee' => $initial,
			'subtotal'    => ( $units * $rate ) + $initial,
		);
	}
}
