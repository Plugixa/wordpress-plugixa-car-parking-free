<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Modules\Frontend;

use PlugixaCarParking\Core\Container;
use PlugixaCarParking\Core\Modules\ModuleInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Frontend: what the plugin puts on a site's public pages.
 *
 * The shortcodes, the block, and the scripts and stylesheet they bring with them. It owns no
 * table and no route: the pages it renders call the routes the other modules register.
 *
 * A public page is extended from outside this module, by hooks whose names say where they sit
 * and not what uses them. They are listed on Assets, Labels, the summary shortcode and the
 * invoice template; the script side of the same seam is described at the top of
 * assets/frontend/js/core.js.
 */
final class Module implements ModuleInterface {

	public function id(): string {
		return 'frontend';
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
		add_action( 'init', static fn() => $container->get( Shortcodes::class )->register() );
		add_action( 'init', static fn() => $container->get( BookingFormBlock::class )->register() );

		$container->get( Assets::class )->register_hooks();

		ReturnPage::register_hooks();

		Kses::register();
	}
}
