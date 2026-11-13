<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Modules\Dashboard;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The strings of the screens under `src/modules/dashboard/`, translated.
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
			'dashboard_no_customer_name'        => __( 'No name given', 'plugixa-car-parking' ),
			'dashboard_good_morning'            => __( 'Good morning', 'plugixa-car-parking' ),
			'dashboard_good_afternoon'          => __( 'Good afternoon', 'plugixa-car-parking' ),
			'dashboard_good_evening'            => __( 'Good evening', 'plugixa-car-parking' ),
			/* translators: %s: a value the app fills in. */
			'dashboard_subtitle_dated'          => __( 'Here is how your car parks are doing on %s.', 'plugixa-car-parking' ),
			'dashboard_subtitle'                => __( 'Here is how your car parks are doing.', 'plugixa-car-parking' ),
			'dashboard_new_booking'             => __( 'New booking', 'plugixa-car-parking' ),
			'dashboard_revenue_30d'             => __( 'Revenue, 30 days', 'plugixa-car-parking' ),
			'dashboard_revenue_caption'         => __( 'Payments received, less refunds', 'plugixa-car-parking' ),
			/* translators: %s: a value the app fills in. */
			'dashboard_revenue_all_time'        => __( '%s collected in total', 'plugixa-car-parking' ),
			'dashboard_bookings_30d'            => __( 'Bookings, 30 days', 'plugixa-car-parking' ),
			'dashboard_bookings_caption'        => __( 'By the day each was made', 'plugixa-car-parking' ),
			/* translators: %s: a value the app fills in. */
			'dashboard_bookings_all_time'       => __( '%s in total', 'plugixa-car-parking' ),
			'dashboard_occupancy_now'           => __( 'Occupancy now', 'plugixa-car-parking' ),
			/* translators: %s: a value the app fills in. */
			'dashboard_occupancy_of'            => __( 'Of %s spaces', 'plugixa-car-parking' ),
			'dashboard_arrivals_today'          => __( 'Arrivals today', 'plugixa-car-parking' ),
			'dashboard_holding_hint'            => __( 'Bookings that hold a space', 'plugixa-car-parking' ),
			'dashboard_departures_today'        => __( 'Departures today', 'plugixa-car-parking' ),
			'dashboard_upcoming'                => __( 'Upcoming bookings', 'plugixa-car-parking' ),
			'dashboard_upcoming_hint'           => __( 'Still to arrive', 'plugixa-car-parking' ),
			'dashboard_trend'                   => __( 'Last 30 days', 'plugixa-car-parking' ),
			'dashboard_trend_measure'           => __( 'What the chart shows', 'plugixa-car-parking' ),
			'dashboard_trend_revenue'           => __( 'Revenue', 'plugixa-car-parking' ),
			'dashboard_trend_bookings'          => __( 'Bookings', 'plugixa-car-parking' ),
			'dashboard_trend_revenue_total'     => __( 'received in 30 days', 'plugixa-car-parking' ),
			'dashboard_trend_bookings_total'    => __( 'bookings made in 30 days', 'plugixa-car-parking' ),
			'dashboard_trend_revenue_empty'     => __( 'No payments in the last 30 days. Money shows here on the day it is received.', 'plugixa-car-parking' ),
			'dashboard_trend_bookings_empty'    => __( 'No bookings were made in the last 30 days. Each one shows here on the day it is made.', 'plugixa-car-parking' ),
			'dashboard_by_status'               => __( 'Bookings by status', 'plugixa-car-parking' ),
			'dashboard_view_all'                => __( 'View all', 'plugixa-car-parking' ),
			'dashboard_by_status_empty'         => __( 'No bookings yet. They are counted here by status as they come in.', 'plugixa-car-parking' ),
			/* translators: %s: a value the app fills in. */
			'dashboard_booked_value'            => __( '%s booked, leaving out cancelled, refunded and expired bookings.', 'plugixa-car-parking' ),
			'dashboard_upcoming_arrivals'       => __( 'Upcoming arrivals', 'plugixa-car-parking' ),
			'dashboard_upcoming_arrivals_empty' => __( 'Nobody is due to arrive. The next bookings to arrive are listed here, soonest first.', 'plugixa-car-parking' ),
			'dashboard_one_space'               => __( '1 space', 'plugixa-car-parking' ),
			/* translators: %s: a value the app fills in. */
			'dashboard_n_spaces'                => __( '%s spaces', 'plugixa-car-parking' ),
			'dashboard_recent_bookings'         => __( 'Recent bookings', 'plugixa-car-parking' ),
			'dashboard_recent_bookings_empty'   => __( 'No bookings yet. The five most recent are listed here as customers book.', 'plugixa-car-parking' ),
			'dashboard_by_location'             => __( 'Occupancy by location', 'plugixa-car-parking' ),
			'dashboard_spaces_held_now'         => __( 'Spaces held right now', 'plugixa-car-parking' ),
			'dashboard_by_location_empty'       => __( 'No location has spaces yet. Give a location its space types and it is measured here.', 'plugixa-car-parking' ),
			'dashboard'                         => __( 'Dashboard', 'plugixa-car-parking' ),
		);
	}
}
