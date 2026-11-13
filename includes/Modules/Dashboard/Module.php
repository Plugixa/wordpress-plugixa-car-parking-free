<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Modules\Dashboard;

use PlugixaCarParking\Core\Container;
use PlugixaCarParking\Core\Modules\ModuleInterface;
use PlugixaCarParking\Modules\Dashboard\Http\DashboardController;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dashboard: the figures the admin app opens on.
 *
 * Owns no table. Every figure is counted from bookings, payments and the inventory.
 */
final class Module implements ModuleInterface {

	public function id(): string {
		return 'dashboard';
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
		$container->get( DashboardController::class )->register_routes();
	}

	public function registerHooks( Container $container ): void {
		add_filter(
			'plugixa_carparking_admin_i18n_strings',
			static fn( array $strings ): array => $strings + Strings::all()
		);

		unset( $container );
	}
}
