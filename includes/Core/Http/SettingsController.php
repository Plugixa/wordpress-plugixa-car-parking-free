<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Core\Http;

use PlugixaCarParking\Core\Security\Capabilities;
use PlugixaCarParking\Core\Settings;
use PlugixaCarParking\Core\Uninstaller;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reading and saving the plugin's settings.
 *
 * Core owns this endpoint but knows none of the module-specific content: modules contribute the
 * settings they own through the schema and defaults filters on {@see Settings}.
 */
final class SettingsController extends RestController {

	/**
	 * Settings that only a site administrator may change, whoever else may change the rest.
	 *
	 * Erasing every booking on uninstall is not the same job as choosing a date format.
	 *
	 * @var string[]
	 */
	private const ADMINISTRATOR_ONLY = array( Uninstaller::SETTING );

	/**
	 * The key of a save request that lists the stored secrets to erase.
	 */
	private const CLEAR = 'clear';

	protected function entity_type(): string {
		return 'settings';
	}

	/**
	 * Settings are administration, not data, and they hold the configuration of outside services.
	 *
	 * BOTH ROUTES TAKE THE PLUGIN'S OWN CAPABILITY RATHER THAN `manage_options`. "Runs the car
	 * park" and "administers the WordPress site" are different jobs, and a capability of its own
	 * is what lets a site say the first without granting the second. Administrators are
	 * unaffected: activation grants them every capability the plugin has.
	 *
	 * What the app needs from the settings to draw a screen — the currency, the date format —
	 * reaches every user of the app through the bootstrap payload instead.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function can_read( WP_REST_Request $request ): bool|WP_Error {
		unset( $request );

		return current_user_can( Capabilities::MANAGE_SETTINGS ) ? true : $this->forbidden();
	}

	/**
	 * A FEW KEYS NEED `manage_options` TOO, and each is checked as a CHANGE, not as a presence.
	 * The settings form re-sends every value it loaded, so refusing any payload that merely
	 * carries one of them would lock a settings manager out of everything else on the screen.
	 * A no-op is not an attempt.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function can_manage_settings( WP_REST_Request $request ): bool|WP_Error {
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			return $this->forbidden();
		}

		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		$incoming = Settings::sanitize( (array) $request->get_json_params() );

		foreach ( self::ADMINISTRATOR_ONLY as $key ) {
			if ( array_key_exists( $key, $incoming ) && Settings::get( $key ) !== $incoming[ $key ] ) {
				return $this->fail(
					'plugixa_carparking_forbidden',
					__( 'Only a site administrator can change that setting.', 'plugixa-car-parking' ),
					rest_authorization_required_code()
				);
			}
		}

		return true;
	}

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/settings',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'show' ),
					'permission_callback' => array( $this, 'can_read' ),
				),
				array(
					'methods'             => 'POST, PUT',
					'callback'            => array( $this, 'update' ),
					'permission_callback' => array( $this, 'can_manage_settings' ),
				),
			)
		);
	}

	/**
	 * @param WP_REST_Request $request The request.
	 */
	public function show( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );

		return $this->ok( $this->payload() );
	}

	/**
	 * @param WP_REST_Request $request The request.
	 */
	public function update( WP_REST_Request $request ): WP_REST_Response {
		$input = (array) $request->get_json_params();

		// `clear` is not a setting: it lists the stored secrets this save erases. `Settings::save()`
		// ignores any key in it that is not a secret.
		$clear = is_array( $input[ self::CLEAR ] ?? null ) ? array_values( $input[ self::CLEAR ] ) : array();

		Settings::save( $input, $clear );

		return $this->ok( $this->payload() );
	}

	/**
	 * The settings as a browser may see them, and what each choice may be set to.
	 *
	 * SECRETS NEVER LEAVE. A stored credential reads as an empty string with a flag beside it
	 * saying one is set, which is all a form needs to show "configured" and offer to replace it.
	 *
	 * @return array{settings: array<string, mixed>, choices: array<string, mixed>}
	 */
	private function payload(): array {
		$choices = array(
			// Sent as a value, not offered as a choice: the timezone is WordPress's to set.
			'site_timezone' => wp_timezone_string(),
		);

		foreach ( Settings::schema() as $key => $field ) {
			if ( 'choice' === ( $field['type'] ?? '' ) ) {
				$choices[ $key ] = array_values( (array) ( $field['options'] ?? array() ) );
			}
		}

		return array(
			'settings' => Settings::for_client(),
			/**
			 * The lists a settings control needs to offer a choice.
			 *
			 * Every `choice` setting's options are here already, keyed by the setting. A module
			 * adds a list that is not a fixed set of options, such as the gateways installed.
			 *
			 * @param array<string, mixed> $choices Keyed by control.
			 */
			'choices'  => (array) apply_filters( 'plugixa_carparking_settings_choices', $choices ),
		);
	}
}
