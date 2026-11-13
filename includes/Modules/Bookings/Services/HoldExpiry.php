<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Modules\Bookings\Services;

use PlugixaCarParking\Modules\Bookings\Repositories\BookingRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The hourly job that marks lapsed pending bookings as expired.
 *
 * Availability already ignores a hold the moment it lapses, so this job frees nothing: it makes
 * the booking's status say what has happened to it.
 */
final class HoldExpiry {

	public const HOOK = 'plugixa_carparking_expire_holds';

	public function __construct( private readonly BookingRepository $bookings ) {}

	public function register_hooks(): void {
		add_action( self::HOOK, array( $this, 'run' ) );
		add_action( 'plugixa_carparking_deactivated', array( self::class, 'unschedule' ) );

		// Scheduled here rather than on activation, so an install updated by copying files over
		// gets the event without being deactivated and activated again.
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 300, 'hourly', self::HOOK );
		}
	}

	public function run(): void {
		$this->bookings->expire_pending_holds();
	}

	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}
}
