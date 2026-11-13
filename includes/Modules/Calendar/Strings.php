<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Modules\Calendar;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The strings of the screens under `src/modules/calendar/`, translated.
 *
 * Every key those screens ask for with `t( key, fallback )` has an entry here, with the same
 * English. A key that is missing still shows, in English, in every language: that is the
 * failure this list exists to prevent.
 */
final class Strings {

	/**
	 * @return array<string, string> Key => translated text.
	 */
	public static function all(): array {
		return array(
			'calendar'                    => __( 'Calendar', 'plugixa-car-parking' ),
			'calendar_new_booking'        => __( 'New booking', 'plugixa-car-parking' ),
			'calendar_location'           => __( 'Location', 'plugixa-car-parking' ),
			'calendar_no_locations'       => __( 'No locations yet. Add one under Car parks.', 'plugixa-car-parking' ),
			'calendar_space_type'         => __( 'Space type', 'plugixa-car-parking' ),
			'calendar_no_space_types'     => __( 'No space types yet. Add one under Car parks.', 'plugixa-car-parking' ),
			'calendar_loading'            => __( 'Loading bookings', 'plugixa-car-parking' ),
			'calendar_today'              => __( 'Today', 'plugixa-car-parking' ),
			'calendar_month'              => __( 'Month', 'plugixa-car-parking' ),
			'calendar_week'               => __( 'Week', 'plugixa-car-parking' ),
			'calendar_day'                => __( 'Day', 'plugixa-car-parking' ),
			'calendar_hint_create'        => __( 'Choose a booking to open it. Double-click an empty slot to start a booking for that time.', 'plugixa-car-parking' ),
			'calendar_hint'               => __( 'Choose a booking to open it.', 'plugixa-car-parking' ),
			'calendar_arriving'           => __( 'arriving', 'plugixa-car-parking' ),
			'calendar_arriving_tip'       => __( 'Spaces whose stay starts in the days shown. Open the list of them.', 'plugixa-car-parking' ),
			'calendar_leaving'            => __( 'leaving', 'plugixa-car-parking' ),
			'calendar_leaving_tip'        => __( 'Spaces whose stay ends in the days shown. Open the list of them.', 'plugixa-car-parking' ),
			'calendar_parked_through'     => __( 'parked through', 'plugixa-car-parking' ),
			'calendar_parked_through_tip' => __( 'The most spaces parked for a whole day, neither arriving nor leaving, on any one day shown.', 'plugixa-car-parking' ),
			/* translators: %s: a value the app fills in. */
			'calendar_peak_on'            => __( 'at the peak, on %s', 'plugixa-car-parking' ),
			'calendar_peak'               => __( 'at the peak', 'plugixa-car-parking' ),
			'calendar_peak_over_tip'      => __( 'The busiest day takes more spaces than this location has.', 'plugixa-car-parking' ),
			'calendar_peak_capacity_tip'  => __( 'The most spaces taken on one day, against what this location has.', 'plugixa-car-parking' ),
			'calendar_peak_tip'           => __( 'The most spaces taken on one day. Choose a location to measure it against its capacity.', 'plugixa-car-parking' ),
			'calendar_summary_note'       => __( 'Counted in spaces, leaving out cancelled, refunded and expired bookings.', 'plugixa-car-parking' ),
			'calendar_legend'             => __( 'Status', 'plugixa-car-parking' ),
			'calendar_show_all_statuses'  => __( 'Show all', 'plugixa-car-parking' ),
		);
	}
}
