<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Core\Admin;

use PlugixaCarParking\Core\App\Bundle;
use PlugixaCarParking\Core\App\Config;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loads the app on its own admin page, and keeps wp-admin's styles from breaking it.
 *
 * THE APP DRAWS ITS OWN CONTROLS, AND WP-ADMIN STYLES THE SAME ELEMENTS. wp-admin's form
 * stylesheet targets `input[type=text]` and friends by element and attribute. The component
 * library styles its inputs with a single generated class. An element-plus-attribute selector
 * outranks a single class, so wp-admin wins: borders, padding, heights and focus rings come out
 * wrong, and no rule written in the app can outrank it without also fighting the library.
 *
 * So the stylesheets are not overridden. On the app's page they are not printed at all.
 */
final class Assets {

	/**
	 * wp-admin stylesheets that style things the app draws for itself.
	 *
	 * Removed from the list of stylesheets to print rather than dequeued, because `wp-admin` is a
	 * bundle that DEPENDS on them: dequeuing one handle leaves it to be pulled back in as a
	 * dependency.
	 *
	 * Everything the admin frame itself needs stays: the admin bar, the side menu, dashicons, and
	 * the styles of the media library and editor the app opens.
	 *
	 * @var string[]
	 */
	private const DROP_STYLES = array(
		'forms',
		'dashboard',
		'list-tables',
		'edit',
		'revisions',
		'media',
		'themes',
		'about',
		'nav-menus',
		'widgets',
		'wp-pointer',
	);

	private const BODY_CLASS = 'plugixa-car-parking-screen';

	/**
	 * Whether this request is rendering the app's page. Set while scripts are enqueued, and read
	 * by the two filters that run after it.
	 */
	private bool $on_app_screen = false;

	public function register_hooks(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'print_styles_array', array( $this, 'strip_unused_admin_styles' ) );
		add_filter( 'admin_body_class', array( $this, 'mark_body' ) );
	}

	/**
	 * Whether a hook suffix is the app's page, and only the app's page.
	 *
	 * An exact comparison. The licensing SDK's pages live under the same menu and their hook
	 * suffixes contain this one's slug, so anything looser would load the app on them.
	 *
	 * @param string $hook_suffix What `admin_enqueue_scripts` received.
	 */
	public static function is_app_screen( string $hook_suffix ): bool {
		return Admin::HOOK === $hook_suffix;
	}

	/**
	 * @param string $hook_suffix The current admin page's hook suffix.
	 */
	public function enqueue( string $hook_suffix ): void {
		if ( ! self::is_app_screen( $hook_suffix ) ) {
			return;
		}

		// With no app to load, the page shows a notice instead, and is left an ordinary admin
		// page so that the notice is styled like one.
		if ( ! Bundle::is_loadable() ) {
			return;
		}

		$this->on_app_screen = true;

		Bundle::enqueue();
		Config::inject( 'admin' );
		$this->reset_admin_chrome();

		// The rich-text field is WordPress's own editor, and the image picker its media library.
		wp_enqueue_editor();
		wp_enqueue_media();

		/**
		 * Fires after the app's own assets are enqueued on its page.
		 *
		 * @param string $hook_suffix The app page's hook suffix.
		 */
		do_action( 'plugixa_carparking_enqueue_assets', $hook_suffix );
	}

	/**
	 * A body class, so a rule can reach the menus and dialogs the app renders outside its root.
	 *
	 * @param string $classes The admin body's classes so far.
	 */
	public function mark_body( string $classes ): string {
		return $this->on_app_screen ? trim( $classes . ' ' . self::BODY_CLASS ) : $classes;
	}

	/**
	 * Leave the conflicting wp-admin stylesheets out of what is printed on the app's page.
	 *
	 * @param string[] $handles Stylesheet handles about to be printed.
	 *
	 * @return string[]
	 */
	public function strip_unused_admin_styles( array $handles ): array {
		if ( ! $this->on_app_screen ) {
			return $handles;
		}

		return array_values( array_diff( $handles, self::DROP_STYLES ) );
	}

	/**
	 * The few rules that survive the stylesheets being left out.
	 *
	 * The focus ring and the heading colour come from the stylesheets that stay, so they are
	 * answered here, scoped by the body class so they also reach what the app renders in a portal.
	 */
	private function reset_admin_chrome(): void {
		$body = 'body.' . self::BODY_CLASS;

		wp_register_style( 'plugixa-car-parking-admin', false, array(), PLUGIXA_CARPARKING_VERSION ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters -- a handle registered with no src on purpose: it exists only to carry wp_add_inline_style(), which is the documented way to attach inline CSS without shipping an empty file.
		wp_enqueue_style( 'plugixa-car-parking-admin' );
		wp_add_inline_style(
			'plugixa-car-parking-admin',
			// The app fills the frame edge to edge and draws its own page padding.
			'#wpcontent{padding-left:0}'
			. '#wpbody-content{padding-bottom:0}'
			. '#wpfooter{display:none}'
			. '#' . Bundle::ROOT_ID . '{margin:0}'
			// Fullscreen hides the admin bar and side menu, and gives the app their space.
			. 'html:fullscreen{--wp-admin--admin-bar--height:0px;padding-top:0}'
			. 'html:fullscreen #wpadminbar,html:fullscreen #adminmenumain{display:none}'
			. 'html:fullscreen #wpcontent,html:fullscreen #wpfooter{margin-left:0}'
			. 'html:fullscreen #wpbody{padding-top:0}'
			// The admin colour scheme's focus ring, drawn on top of the app's own.
			. $body . ' input:focus,' . $body . ' select:focus,' . $body . ' textarea:focus{box-shadow:none}'
			. $body . ' a:focus{box-shadow:none}'
			// wp-admin colours every heading; the app's headings take the theme's text colour.
			. $body . ' h1,' . $body . ' h2,' . $body . ' h3,' . $body . ' h4,' . $body . ' h5,' . $body . ' h6{color:inherit}'
		);
	}
}
