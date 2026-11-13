<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Modules\Notifications;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The strings of the screens under `src/modules/notifications/`, translated.
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
			'message_log'              => __( 'Message log', 'plugixa-car-parking' ),
			'message_view_all'         => __( 'View all', 'plugixa-car-parking' ),
			'message_none_for_booking' => __( 'No message has been sent about this booking yet.', 'plugixa-car-parking' ),
			'message_showing_latest'   => __( 'Showing the latest {shown} of {total}.', 'plugixa-car-parking' ),
			'message_log_intro'        => __( 'Every message sent to a customer or an administrator about a booking, and whether it went.', 'plugixa-car-parking' ),
			'message_for_booking'      => __( 'Booking {ref}', 'plugixa-car-parking' ),
			'message_log_empty'        => __( 'No messages yet', 'plugixa-car-parking' ),
			'message_log_empty_hint'   => __( 'When a booking is made or changes, the messages sent about it are recorded here, with the reason for any that failed.', 'plugixa-car-parking' ),
			'rows_per_page'            => __( 'Rows per page:', 'plugixa-car-parking' ),
			'message_sent_at'          => __( 'When', 'plugixa-car-parking' ),
			'message_booking'          => __( 'Booking', 'plugixa-car-parking' ),
			'message_channel'          => __( 'Channel', 'plugixa-car-parking' ),
			'message_recipient'        => __( 'Recipient', 'plugixa-car-parking' ),
			'message_status'           => __( 'Status', 'plugixa-car-parking' ),
		);
	}
}
