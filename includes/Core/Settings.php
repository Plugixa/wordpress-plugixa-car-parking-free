<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Core;

use PlugixaCarParking\Core\Security\Secrets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The plugin's settings: one option, what may be stored in it, and how each value is cleaned.
 *
 * A SETTING EXISTS BECAUSE THE SCHEMA SAYS SO. `schema()` maps each key to its type, and that
 * type is what cleans an incoming value. A key the schema does not list is dropped from a save.
 * A module that has settings of its own adds them through `plugixa_carparking_settings_schema`
 * and their starting values through `plugixa_carparking_settings_defaults`, so nothing here knows
 * the name of a setting for a feature that is not installed.
 *
 * SECRETS ARE ENCRYPTED WHERE THE OPTION MEETS THE DATABASE, not by each thing that reads one.
 * `register_hooks()` decrypts on the way out of the option and encrypts on the way in, so every
 * reader sees the real value, every writer may hand one over in the clear, and what is stored is
 * always ciphertext. A reader does not have to remember to decrypt, and a writer cannot forget to
 * encrypt, because neither is their job.
 */
final class Settings {

	/**
	 * The option every setting is stored in.
	 *
	 * The name predates the `plugixa_carparking_` prefix and is kept: it is on every existing
	 * site, and it is named in wpml-config.xml.
	 */
	public const OPTION = 'plugixacarparking_settings';

	/**
	 * What a secret reads as over REST once one is stored: nothing, plus a flag saying so.
	 */
	public const SECRET_SET_SUFFIX = '_set';

	/**
	 * Decrypt secrets as the option is read, and encrypt them as it is written.
	 */
	public static function register_hooks(): void {
		add_filter( 'option_' . self::OPTION, array( self::class, 'reveal_secrets' ) );
		add_filter( 'pre_update_option_' . self::OPTION, array( self::class, 'conceal_secrets' ) );
	}

	/**
	 * Every stored setting.
	 *
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION, array() );

		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * One setting, or a fallback when it has never been saved.
	 *
	 * @param string $key      Setting key.
	 * @param mixed  $fallback Returned when the key is absent.
	 */
	public static function get( string $key, mixed $fallback = null ): mixed {
		return self::all()[ $key ] ?? $fallback;
	}

	/**
	 * What may be stored, and how each value is cleaned.
	 *
	 * Each entry is `key => array( 'type' => …, … )`:
	 *
	 *   text      one line of plain text
	 *   textarea  plain text that keeps its line breaks
	 *   email     an email address
	 *   int       a whole number, clamped to `min` and `max` where given
	 *   bool      true or false
	 *   choice    one of `options`; anything else becomes `default`
	 *   keys      a list of machine keys, e.g. the booking statuses that hold a space
	 *   map       machine key => label, e.g. the booking statuses and what each is called
	 *   secret    a credential; stored encrypted, never sent back to a browser
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function schema(): array {
		$schema = array(
			// General.
			'plugin_name'              => array( 'type' => 'text' ),
			'items_per_page'           => array(
				'type' => 'int',
				'min'  => 10,
				// A page size of 100000 is not a preference, it is a denial of service against
				// the site's own database.
				'max'  => 200,
			),

			// Localisation and formatting.
			'currency'                 => array( 'type' => 'text' ),
			'currency_symbol'          => array( 'type' => 'text' ),
			'currency_position'        => array(
				'type'    => 'choice',
				'options' => array( 'left', 'right' ),
				'default' => 'left',
			),
			'thousand_separator'       => array(
				'type'    => 'choice',
				'options' => array( ',', '.', ' ', "'", '' ),
				'default' => ',',
			),
			'decimal_separator'        => array(
				'type'    => 'choice',
				'options' => array( '.', ',' ),
				'default' => '.',
			),
			'decimals'                 => array(
				'type' => 'int',
				'min'  => 0,
				// Four is the widest any real currency goes; the clamp is what stops a submitted
				// 40 from reaching number_format().
				'max'  => 4,
			),
			'date_format'              => array( 'type' => 'text' ),
			'time_format'              => array( 'type' => 'text' ),
			'week_starts_on'           => array(
				'type' => 'int',
				'min'  => 0,
				'max'  => 6,
			),
			'length_unit'              => array(
				'type'    => 'choice',
				'options' => array( 'm', 'ft' ),
				'default' => 'm',
			),

			// Booking.
			'default_booking_status'   => array(
				'type'    => 'choice',
				'options' => array( 'pending', 'confirmed' ),
				'default' => 'pending',
			),
			'min_advance_minutes'      => array(
				'type' => 'int',
				'min'  => 0,
			),
			'max_advance_days'         => array(
				'type' => 'int',
				'min'  => 0,
				'max'  => 3650,
			),
			'default_entry_time'       => array( 'type' => 'text' ),
			'default_exit_time'        => array( 'type' => 'text' ),
			'booking_trust_line'       => array( 'type' => 'text' ),
			'booking_ref_prefix'       => array( 'type' => 'text' ),
			'booking_statuses'         => array( 'type' => 'map' ),
			'inventory_hold_statuses'  => array( 'type' => 'keys' ),
			'pending_hold_minutes'     => array(
				'type' => 'int',
				'min'  => 0,
				'max'  => 10080,
			),
			'cancel_cutoff_hours'      => array(
				'type' => 'int',
				'min'  => 0,
			),

			// Frontend display.
			'enable_coupons'           => array( 'type' => 'bool' ),

			// Payments.
			'enabled_gateways'         => array( 'type' => 'keys' ),
			'offline_instructions'     => array( 'type' => 'textarea' ),
			'booking_page_id'          => array(
				'type' => 'int',
				'min'  => 0,
			),
			'summary_page_id'          => array(
				'type' => 'int',
				'min'  => 0,
			),

			// Email notifications.
			'notification_enabled'     => array( 'type' => 'bool' ),
			'admin_email'              => array( 'type' => 'email' ),
			'email_from_name'          => array( 'type' => 'text' ),
			'email_from_address'       => array( 'type' => 'email' ),
			'customer_confirm_subject' => array( 'type' => 'text' ),
			'customer_confirm_message' => array( 'type' => 'textarea' ),
			'admin_notify_subject'     => array( 'type' => 'text' ),
			'admin_notify_message'     => array( 'type' => 'textarea' ),
			'payment_subject'          => array( 'type' => 'text' ),
			'payment_message'          => array( 'type' => 'textarea' ),

			// Off: deleting the plugin keeps the bookings. See `Uninstaller`.
			Uninstaller::SETTING       => array( 'type' => 'bool' ),
		);

		/**
		 * The settings that exist, and the type that cleans each one.
		 *
		 * This is how a module contributes a setting it owns. A key no entry claims is dropped
		 * from an incoming save, though one already stored survives — see `keep_unclaimed()`.
		 *
		 * @param array<string, array<string, mixed>> $schema Setting key => descriptor.
		 */
		return (array) apply_filters( 'plugixa_carparking_settings_schema', $schema );
	}

	/**
	 * What a fresh install starts with.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		$defaults = array(
			// General.
			'plugin_name'              => 'Plugixa Car Parking',
			'items_per_page'           => 20,

			// Localisation and formatting.
			'currency'                 => 'USD',
			'currency_symbol'          => '$',
			'currency_position'        => 'left',
			'thousand_separator'       => ',',
			'decimal_separator'        => '.',
			'decimals'                 => 2,
			'date_format'              => 'Y-m-d',
			'time_format'              => 'H:i',
			'week_starts_on'           => 1,
			'length_unit'              => 'm',

			// Booking.
			'default_booking_status'   => 'pending',
			'min_advance_minutes'      => 0,
			'max_advance_days'         => 365,
			'default_entry_time'       => '08:00',
			'default_exit_time'        => '18:00',
			// Empty: nothing is shown until the owner writes a line that is true for them.
			'booking_trust_line'       => '',
			'booking_ref_prefix'       => 'PKG-',

			'booking_statuses'         => array(
				'pending'   => 'Pending',
				'confirmed' => 'Confirmed',
				'paid'      => 'Paid',
				'completed' => 'Completed',
				'cancelled' => 'Cancelled',
				'refunded'  => 'Refunded',
				'no_show'   => 'No-show',
				'expired'   => 'Expired',
			),
			// Which statuses hold a space in availability checks.
			'inventory_hold_statuses'  => array( 'pending', 'confirmed', 'paid', 'completed' ),
			// Minutes a pending booking holds a space before expiring (0 = never).
			'pending_hold_minutes'     => 30,
			// Hours before entry after which a customer can no longer cancel (0 = up to entry).
			'cancel_cutoff_hours'      => 0,

			// Frontend display.
			'enable_coupons'           => true,

			// Payments.
			'enabled_gateways'         => array( 'offline' ),
			'offline_instructions'     => 'Please pay on arrival at the parking entrance.',
			'booking_page_id'          => 0,
			'summary_page_id'          => 0,

			// Email notifications.
			'notification_enabled'     => true,
			'admin_email'              => get_option( 'admin_email' ),
			'email_from_name'          => get_bloginfo( 'name' ),
			'email_from_address'       => get_option( 'admin_email' ),
			'customer_confirm_subject' => 'Your parking booking {booking_ref} is confirmed',
			'customer_confirm_message' => "Hello {customer_name},\n\nYour booking {booking_ref} at {location_name} is confirmed.\nEntry: {entry_datetime}\nExit: {exit_datetime}\nTotal: {total}\n\nThank you!",
			'admin_notify_subject'     => 'New parking booking {booking_ref}',
			'admin_notify_message'     => 'A new booking {booking_ref} was placed by {customer_name} at {location_name}.',
			'payment_subject'          => 'Payment received for {booking_ref}',
			'payment_message'          => "Hello {customer_name},\n\nWe've received your payment for booking {booking_ref}. Total: {total}.\n\nThank you!",

			Uninstaller::SETTING       => false,
		);

		/**
		 * Default settings.
		 *
		 * Modules add defaults for the settings they own.
		 *
		 * @param array<string, mixed> $defaults Setting key => default value.
		 */
		return (array) apply_filters( 'plugixa_carparking_settings_defaults', $defaults );
	}

	/**
	 * Create the option on a fresh install, and add any default a later version introduced.
	 *
	 * Only ever ADDS a missing key. A value somebody saved is never overwritten, and a stored key
	 * no default claims is left alone: it may belong to a module that is not installed right now.
	 */
	public static function ensure_defaults(): void {
		$defaults = self::defaults();

		if ( false === get_option( self::OPTION ) ) {
			add_option( self::OPTION, $defaults );

			return;
		}

		$stored  = self::all();
		$missing = array_diff_key( $defaults, $stored );

		if ( array() !== $missing ) {
			update_option( self::OPTION, $stored + $missing );
		}
	}

	/**
	 * Save an untrusted payload, keeping only what the schema recognises.
	 *
	 * A PARTIAL PAYLOAD IS A PARTIAL SAVE. Only the keys it carries are cleaned and changed;
	 * everything else stays as stored. A form that sends one tab's fields must not reset another's.
	 *
	 * ERASING A SECRET IS SAID OUT LOUD. A secret is never sent to the browser, so a form that was
	 * merely re-saved sends it back empty, and empty therefore means "unchanged". A stored secret
	 * is erased only by naming its key in `$clear`.
	 *
	 * @param array<string, mixed> $input Raw settings.
	 * @param string[]             $clear Keys of secrets to erase. A key that is not a secret is
	 *                                    ignored: every other setting is emptied by saving it empty.
	 *
	 * @return array<string, mixed> The settings as saved.
	 */
	public static function save( array $input, array $clear = array() ): array {
		$stored  = self::all();
		$clean   = self::sanitize( $input );
		$secrets = self::secret_keys();

		foreach ( $secrets as $key ) {
			if ( array_key_exists( $key, $clean ) && '' === $clean[ $key ] ) {
				unset( $clean[ $key ] );
			}
		}

		foreach ( array_intersect( $secrets, array_filter( $clear, 'is_string' ) ) as $key ) {
			// A new value sent in the same save is the later intention, and wins.
			if ( ! array_key_exists( $key, $clean ) ) {
				$clean[ $key ] = '';
			}
		}

		$settings = self::keep_unclaimed( array_merge( $stored, $clean ), $stored );

		update_option( self::OPTION, $settings );

		/**
		 * Fires after the settings are saved.
		 *
		 * @param array<string, mixed> $settings The saved settings.
		 */
		do_action( 'plugixa_carparking_settings_saved', $settings );

		return $settings;
	}

	/**
	 * Clean an untrusted payload: each recognised key by its type, every other key dropped.
	 *
	 * @param array<string, mixed> $input Raw settings.
	 *
	 * @return array<string, mixed> Only the keys the payload carried and the schema lists.
	 */
	public static function sanitize( array $input ): array {
		$clean = array();

		foreach ( self::schema() as $key => $field ) {
			if ( array_key_exists( $key, $input ) ) {
				$clean[ $key ] = self::clean_value( $input[ $key ], (array) $field );
			}
		}

		/**
		 * Adjust settings after each has been cleaned by its type.
		 *
		 * For a rule that spans more than one setting. A single setting is better described in
		 * the schema.
		 *
		 * Receives the CLEANED settings only. The raw payload is not passed on: a filter hands its
		 * arguments to every callback attached, and unsanitized input reaching a filter is what
		 * wordpress.org's review flags.
		 *
		 * @param array<string, mixed> $clean Cleaned settings.
		 */
		return (array) apply_filters( 'plugixa_carparking_sanitize_settings', $clean );
	}

	/**
	 * The settings as a browser may see them: every secret blanked, with a flag saying whether
	 * one is stored.
	 *
	 * @return array<string, mixed>
	 */
	public static function for_client(): array {
		$settings = self::all();

		foreach ( self::secret_keys() as $key ) {
			$settings[ $key . self::SECRET_SET_SUFFIX ] = '' !== (string) ( $settings[ $key ] ?? '' );
			$settings[ $key ]                           = '';
		}

		return $settings;
	}

	/**
	 * The few settings every user of the app needs in order to draw a screen.
	 *
	 * Formatting and vocabulary only: how an amount, a date and a booking status are shown.
	 * Everything else stays behind the settings capability, because the rest of the option is
	 * the configuration of the site and of the outside services it talks to.
	 *
	 * @return array<string, mixed>
	 */
	public static function for_display(): array {
		/**
		 * Which settings reach every user of the app, not only those who may manage settings.
		 *
		 * A module adds a key the app needs before its first request. Never add a credential.
		 *
		 * @param string[] $keys Setting keys.
		 */
		$keys = (array) apply_filters(
			'plugixa_carparking_config_settings_keys',
			array(
				'currency',
				'currency_symbol',
				'currency_position',
				'thousand_separator',
				'decimal_separator',
				'decimals',
				'date_format',
				'time_format',
				'week_starts_on',
				'length_unit',
				'items_per_page',
				'booking_statuses',
				'default_entry_time',
				'default_exit_time',
				'enable_coupons',
			)
		);

		$keys = array_diff( array_map( 'strval', $keys ), self::secret_keys() );

		return array_intersect_key( self::all() + self::defaults(), array_flip( $keys ) );
	}

	/**
	 * The keys the schema marks as secrets.
	 *
	 * @return string[]
	 */
	public static function secret_keys(): array {
		$keys = array();

		foreach ( self::schema() as $key => $field ) {
			if ( 'secret' === ( $field['type'] ?? '' ) ) {
				$keys[] = (string) $key;
			}
		}

		return $keys;
	}

	/**
	 * Decrypt each secret as the option leaves the database.
	 *
	 * A value saved before encryption existed is plain text and is returned as it is. One that
	 * can no longer be decrypted — the site's salts were rotated — reads as empty, which every
	 * consumer already treats as "not configured".
	 *
	 * @param mixed $settings The stored option.
	 */
	public static function reveal_secrets( mixed $settings ): mixed {
		if ( ! is_array( $settings ) ) {
			return $settings;
		}

		foreach ( self::secret_keys() as $key ) {
			$stored = (string) ( $settings[ $key ] ?? '' );

			if ( Secrets::is_encrypted( $stored ) ) {
				$settings[ $key ] = (string) Secrets::decrypt( $stored );
			}
		}

		return $settings;
	}

	/**
	 * Encrypt each secret as the option goes to the database.
	 *
	 * ON A HOST THAT CANNOT ENCRYPT, THE VALUE IS LEFT AS IT IS. `Secrets::encrypt()` returns an
	 * empty string there, and storing that would erase a working credential to no purpose.
	 *
	 * @param mixed $settings The option about to be stored.
	 */
	public static function conceal_secrets( mixed $settings ): mixed {
		if ( ! is_array( $settings ) || ! Secrets::available() ) {
			return $settings;
		}

		foreach ( self::secret_keys() as $key ) {
			$value = (string) ( $settings[ $key ] ?? '' );

			if ( '' !== $value && ! Secrets::is_encrypted( $value ) ) {
				$settings[ $key ] = Secrets::encrypt( $value );
			}
		}

		return $settings;
	}

	/**
	 * Carry forward settings that are ALREADY STORED and that the schema no longer lists.
	 *
	 * THIS IS THE DIFFERENCE BETWEEN A LAPSED LICENCE AND A DATA LOSS. A module's settings are in
	 * the schema only while the module is installed. A site that goes back to the free build has
	 * stored keys nothing claims; without this, the next save would write the option without them,
	 * and reinstalling the module would find its configuration, API keys included, gone.
	 *
	 * WHAT IS PRESERVED IS ONLY WHAT WAS ALREADY ON DISK, never an unrecognised key from the
	 * payload. The option is autoloaded on every request, so letting a request park arbitrary data
	 * in it would be a denial of service with a REST endpoint in front of it.
	 *
	 * @param array<string, mixed> $settings The settings about to be stored.
	 * @param array<string, mixed> $stored   The settings as they stand.
	 *
	 * @return array<string, mixed>
	 */
	private static function keep_unclaimed( array $settings, array $stored ): array {
		return $settings + $stored;
	}

	/**
	 * Clean one value by its declared type.
	 *
	 * @param mixed                $value The raw value.
	 * @param array<string, mixed> $field The setting's schema entry.
	 */
	private static function clean_value( mixed $value, array $field ): mixed {
		switch ( $field['type'] ?? 'text' ) {
			case 'int':
				$number = (int) $value;
				if ( isset( $field['min'] ) ) {
					$number = max( (int) $field['min'], $number );
				}
				if ( isset( $field['max'] ) ) {
					$number = min( (int) $field['max'], $number );
				}

				return $number;

			case 'bool':
				// A short list of things that mean yes, and nothing else does. WordPress's own
				// rest_sanitize_boolean() reads any unrecognised string as true, and one of
				// these switches decides whether deleting the plugin erases every booking.
				return in_array( $value, array( true, 1, '1', 'true', 'yes', 'on' ), true );

			case 'choice':
				$options = (array) ( $field['options'] ?? array() );
				$chosen  = is_scalar( $value ) ? (string) $value : '';

				return in_array( $chosen, $options, true ) ? $chosen : ( $field['default'] ?? ( $options[0] ?? '' ) );

			case 'keys':
				return array_values( array_unique( array_filter( array_map( 'sanitize_key', array_map( 'strval', array_filter( (array) $value, 'is_scalar' ) ) ) ) ) );

			case 'map':
				$map = array();
				foreach ( (array) $value as $key => $label ) {
					$key = sanitize_key( (string) $key );
					if ( '' !== $key && is_scalar( $label ) ) {
						$map[ $key ] = sanitize_text_field( (string) $label );
					}
				}

				return $map;

			case 'email':
				return sanitize_email( is_scalar( $value ) ? (string) $value : '' );

			case 'textarea':
				return sanitize_textarea_field( is_scalar( $value ) ? (string) $value : '' );

			case 'secret':
				// Not sanitize_text_field(): a credential is opaque, and a filter that trims or
				// strips characters from one would store a key that no longer works.
				return is_scalar( $value ) ? trim( (string) $value ) : '';

			case 'text':
			default:
				return sanitize_text_field( is_scalar( $value ) ? (string) $value : '' );
		}
	}
}
