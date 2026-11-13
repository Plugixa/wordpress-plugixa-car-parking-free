<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Core\App;

use PlugixaCarParking\Core\Admin\Strings;
use PlugixaCarParking\Core\App\Upsell\Strings as UpsellStrings;
use PlugixaCarParking\Core\Plugin;
use PlugixaCarParking\Core\Security\Capabilities;
use PlugixaCarParking\Core\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What the app is told before it makes its first request.
 *
 * Printed onto the page as `window.PlugixaCarParkingConfig`. Everything here is something the app
 * needs in order to START: where the API is, the nonce that authenticates it, who is signed in,
 * what they may do, and how to format what it shows. Anything that can wait is fetched instead.
 */
final class Config {

	private const HANDLE = 'plugixa-car-parking-config';

	/**
	 * Print the configuration for the app.
	 *
	 * @param string               $context Where the app is mounted, e.g. 'admin'.
	 * @param array<string, mixed> $extra   Further keys for this mount.
	 */
	public static function inject( string $context, array $extra = array() ): void {
		$user = wp_get_current_user();

		$config = array(
			'apiUrl'      => esc_url_raw( rest_url( Plugin::REST_NAMESPACE . '/' ) ),
			// The REST cookie nonce. WordPress verifies it on every request the app makes.
			'nonce'       => wp_create_nonce( 'wp_rest' ),
			'adminUrl'    => esc_url_raw( admin_url() ),
			'homeUrl'     => esc_url_raw( home_url( '/' ) ),
			'context'     => $context,

			// Hides controls a user cannot use. It enforces nothing: every route asks again.
			'permissions' => Capabilities::map(),
			'version'     => PLUGIXA_CARPARKING_VERSION,
			'i18n'        => self::translations(),

			'currentUser' => array(
				'id'    => (int) $user->ID,
				'name'  => (string) $user->display_name,
				'email' => (string) $user->user_email,
			),

			// So the app can label a staging site as one.
			'env'         => wp_get_environment_type(),
			'logoutUrl'   => esc_url_raw( wp_logout_url() ),

			// Formatting and vocabulary only. See Settings::for_display() for why not all of it.
			'settings'    => Settings::for_display(),
			'timezone'    => wp_timezone_string(),

			// `free` or `premium`. The free build shows site managers a Pro features page; the
			// premium build carries the same code and must not. See Edition.
			'edition'     => Edition::current(),

			// Where "See Pro plans" goes. A link somebody clicks, never a request, and sent from
			// here so the app's own source names no outside host.
			'pricingUrl'  => Edition::PRICING_URL,
		);

		/**
		 * Filter what the app is told at startup.
		 *
		 * A module adds what its screens need before their first request. Never a credential:
		 * this is printed into the page.
		 *
		 * @param array<string, mixed> $config The configuration.
		 */
		$config = (array) apply_filters( 'plugixa_carparking_admin_config', array_merge( $config, $extra ) );

		// A classic script with no source, because an inline script cannot be attached to a
		// script module, and this has to run before the module does.
		wp_register_script( self::HANDLE, '', array(), PLUGIXA_CARPARKING_VERSION, false ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.NotInFooter -- must be defined before the app's module runs.
		wp_enqueue_script( self::HANDLE );
		wp_add_inline_script(
			self::HANDLE,
			'window.PlugixaCarParkingConfig = ' . wp_json_encode( $config ) . ';',
			'before'
		);
	}

	/**
	 * Every string the app shows, already translated.
	 *
	 * The app looks a string up by key and falls back to the English it was written with, so a
	 * key missing from here is a string that simply shows in English.
	 *
	 * @return array<string, string>
	 */
	private static function translations(): array {
		/**
		 * The app's strings, key => translated text.
		 *
		 * Each module adds the strings its own screens use.
		 *
		 * @param array<string, string> $strings Strings so far.
		 */
		return (array) apply_filters( 'plugixa_carparking_admin_i18n_strings', array_merge( Strings::all(), UpsellStrings::all() ) );
	}
}
