<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Core\Admin;

use PlugixaCarParking\Core\Activator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tells an administrator that the plugin's database could not be set up, and why.
 *
 * WordPress.org's activation check asks that "anything your plugin needs and cannot be sure about
 * is checked before it is used, and the plugin explains the problem instead of failing". The first
 * half is {@see Activator::migrate()}, which no longer records a migration that did not finish as
 * done. This is the second half.
 *
 * WHERE: the Plugins screen, the Dashboard and this plugin's own screen — not every admin page.
 * WHO: whoever may activate plugins; it names tables and repeats what the database said.
 * IT CANNOT BE DISMISSED, AND IT REMOVES ITSELF with the first migration that finishes.
 * THE WORDS ARE CHOSEN HERE: a migration can run before a translation is loaded, so what is stored
 * is the table names and the database's own message.
 */
final class MigrationNotice {

	/**
	 * The admin-post action, and the nonce, of the "Try again" button.
	 */
	public const ACTION = 'plugixa_carparking_migrate';

	/**
	 * Hook the notice and its retry action.
	 */
	public static function register_hooks(): void {
		add_action( 'admin_notices', array( self::class, 'render' ) );
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'retry' ) );
	}

	/**
	 * Print the notice, if there is a failure to report to this person on this screen.
	 */
	public static function render(): void {
		$failure = Activator::failure();

		if ( null === $failure || ! current_user_can( 'activate_plugins' ) || ! self::relevant_screen() ) {
			return;
		}

		$retry = wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION ), self::ACTION );

		echo '<div class="notice notice-error"><p><strong>';
		echo esc_html__( 'Plugixa Car Parking could not finish setting up its database, so parts of it will not work.', 'plugixa-car-parking' );
		echo '</strong></p><ul class="ul-disc">';

		foreach ( $failure['failures'] as $module => $what ) {
			echo '<li>' . esc_html( self::describe( (string) $module, (array) $what ) ) . '</li>';
		}

		echo '</ul><p>';
		echo esc_html__( 'The usual cause is a database user that is not allowed to create tables. Once that is put right, try again.', 'plugixa-car-parking' );
		echo ' <a class="button button-secondary" href="' . esc_url( $retry ) . '">' . esc_html__( 'Try again', 'plugixa-car-parking' ) . '</a>';
		echo '</p></div>';
	}

	/**
	 * Run the migration now, because somebody asked for it. Verifies the nonce, then the capability.
	 */
	public static function retry(): void {
		check_admin_referer( self::ACTION );

		if ( ! current_user_can( 'activate_plugins' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'plugixa-car-parking' ), '', array( 'response' => 403 ) );
		}

		Activator::migrate();

		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'plugins.php' ) );
		exit;
	}

	/**
	 * One failure, as a sentence.
	 *
	 * @param string               $module The module whose migration did not finish.
	 * @param array<string, mixed> $what   `tables` and `reason`, as {@see Activator} stored them.
	 */
	private static function describe( string $module, array $what ): string {
		$tables = array_map( 'strval', (array) ( $what['tables'] ?? array() ) );

		// Somebody else's text, and possibly long: shortened here, escaped by the caller.
		$reason = wp_html_excerpt( trim( (string) ( $what['reason'] ?? '' ) ), 300, '…' );

		if ( array() === $tables ) {
			return sprintf(
				/* translators: 1: the part of the plugin that failed, e.g. "bookings"; 2: the error message. */
				__( 'Setting up %1$s failed: %2$s', 'plugixa-car-parking' ),
				$module,
				$reason
			);
		}

		if ( '' === $reason ) {
			return sprintf(
				/* translators: %s: one or more database table names. */
				__( 'The table %s could not be created.', 'plugixa-car-parking' ),
				implode( ', ', $tables )
			);
		}

		return sprintf(
			/* translators: 1: one or more database table names; 2: the database's own error message. */
			__( 'The table %1$s could not be created. The database said: %2$s', 'plugixa-car-parking' ),
			implode( ', ', $tables ),
			$reason
		);
	}

	/**
	 * Whether this is a screen where the notice belongs.
	 */
	private static function relevant_screen(): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( null === $screen ) {
			return false;
		}

		return in_array( $screen->id, array( 'plugins', 'dashboard' ), true )
			|| str_contains( (string) $screen->id, Admin::SLUG );
	}
}
