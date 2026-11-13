<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Modules\Notifications;

use PlugixaCarParking\Core\Container;
use PlugixaCarParking\Core\Modules\ModuleInterface;
use PlugixaCarParking\Modules\Notifications\Http\LogController;
use PlugixaCarParking\Modules\Notifications\Repositories\LogTable;
use PlugixaCarParking\Modules\Notifications\Services\Notifier;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Notifications: the messages sent about a booking, and the record of every one.
 */
final class Module implements ModuleInterface {

	public function id(): string {
		return 'notifications';
	}

	public function migrate(): void {
		Schema::install();
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
		$container->get( LogController::class )->register_routes();
	}

	public function registerHooks( Container $container ): void {
		add_filter(
			'plugixa_carparking_admin_i18n_strings',
			static fn( array $strings ): array => $strings + Strings::all()
		);

		$container->get( Notifier::class )->register_hooks();

		// The log of messages about a booking goes when the booking is destroyed, not before:
		// a trashed booking keeps its history, to come back with.
		add_action(
			'plugixa_carparking_entity_purged',
			static function ( string $entity_type, int $id ) use ( $container ): void {
				if ( 'booking' === $entity_type ) {
					$container->get( LogTable::class )->delete_for_booking( $id );
				}
			},
			10,
			2
		);
	}
}
