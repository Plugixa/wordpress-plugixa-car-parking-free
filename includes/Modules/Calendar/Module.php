<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Modules\Calendar;

use PlugixaCarParking\Core\Container;
use PlugixaCarParking\Core\Modules\ModuleInterface;
use PlugixaCarParking\Modules\Calendar\Http\CalendarController;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Calendar: the bookings that fall in a span of dates, for a month grid.
 *
 * Owns no table. It reads bookings and the capacity they are measured against.
 */
final class Module implements ModuleInterface {

	public function id(): string {
		return 'calendar';
	}

	public function migrate(): void {
		// Nothing to create: this module stores nothing of its own.
	}

	/**
	 * @return array<string, string[]>
	 */
	public function capabilities(): array {
		return array();
	}

	public function registerServices( Container $container ): void {
		unset( $container );
	}

	public function registerRoutes( Container $container ): void {
		$container->get( CalendarController::class )->register_routes();
	}

	public function registerHooks( Container $container ): void {
		add_filter(
			'plugixa_carparking_admin_i18n_strings',
			static fn( array $strings ): array => $strings + Strings::all()
		);

		unset( $container );
	}
}
