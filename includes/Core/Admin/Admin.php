<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Core\Admin;

use PlugixaCarParking\Core\App\Bundle;
use PlugixaCarParking\Core\Security\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The plugin's place in the WordPress admin menu.
 *
 * ONE PAGE, NOT ONE PER SCREEN. The app is a single-page application with its own sidebar, and
 * every screen in it is a route inside that one page. The menu item opens that page, and the
 * app's own sidebar moves between screens, so no links into it are listed under the menu item.
 *
 * That is also what keeps the app off pages that are not its own. The licensing SDK adds its
 * account and pricing pages under this same menu, with slugs that begin with this one. Because
 * the app has exactly one page, "is this the app's page" is an exact comparison against one hook
 * suffix and can never match theirs.
 */
final class Admin {

	public const SLUG = 'plugixa-car-parking';

	/**
	 * The hook suffix WordPress gives the app's page, which `admin_enqueue_scripts` receives.
	 */
	public const HOOK = 'toplevel_page_plugixa-car-parking';

	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
	}

	public function add_menu(): void {
		add_menu_page(
			__( 'Plugixa Car Parking', 'plugixa-car-parking' ),
			__( 'Plugixa Car Parking', 'plugixa-car-parking' ),
			Capabilities::MANAGE,
			self::SLUG,
			array( $this, 'render' ),
			'dashicons-car',
			26
		);

		// The first entry repeats the page itself. On its own, WordPress drops it and the menu item
		// shows no submenu. Once the licensing SDK adds its account or pricing page, it keeps the
		// menu item opening the app, not the first of those pages.
		add_submenu_page(
			self::SLUG,
			__( 'Dashboard', 'plugixa-car-parking' ),
			__( 'Dashboard', 'plugixa-car-parking' ),
			Capabilities::MANAGE,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * The app's mount point, where React takes over, or a notice when the app has no files to load.
	 */
	public function render(): void {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed markup built by Bundle, which escapes every value it prints.
		echo Bundle::page_html();
	}
}
