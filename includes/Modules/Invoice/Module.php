<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Modules\Invoice;

use PlugixaCarParking\Core\Container;
use PlugixaCarParking\Core\Modules\ModuleInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Invoice: a booking's summary as a page a customer can open without signing in.
 *
 * Owns no table. It reads a booking and its line items and renders them.
 */
final class Module implements ModuleInterface {

	public function id(): string {
		return 'invoice';
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
		unset( $container );
	}

	public function registerHooks( Container $container ): void {
		unset( $container );
	}
}
