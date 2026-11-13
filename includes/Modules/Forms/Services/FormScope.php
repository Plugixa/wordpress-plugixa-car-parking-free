<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Modules\Forms\Services;

use PlugixaCarParking\Modules\Forms\Repositories\FormRepository;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Which form a public request is made on, and what that form lets it book.
 *
 * A form names the locations it books at and, optionally, the space types. Each list is empty for
 * "all of them". The page decides which form is shown, so a request carries the form's id, and
 * the server holds the request to that form's lists here. The page only showing the right choices
 * is not enough: a request is easy to write by hand.
 */
final class FormScope {

	public function __construct( private readonly FormRepository $forms ) {}

	/**
	 * The live, active form a request is made on: the one it names, else the site's default.
	 *
	 * @param mixed $form_id What the request sent. Empty for the default form.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public function resolve( mixed $form_id ): array|WP_Error {
		$id   = is_numeric( $form_id ) ? (int) $form_id : 0;
		$form = $id > 0 ? $this->forms->find( $id ) : $this->forms->default_form();

		// A site that has never had a form, or a request from before forms named themselves,
		// books with no form at all: every location and type.
		if ( null === $form && 0 === $id ) {
			return array();
		}

		if ( null === $form || 'active' !== ( $form['status'] ?? '' ) ) {
			return new WP_Error(
				'form_unavailable',
				__( 'This booking form is no longer taking bookings. Please reload the page.', 'plugixa-car-parking' ),
				array( 'status' => 410 )
			);
		}

		return $form;
	}

	/**
	 * The locations a form books at. Empty for all of them.
	 *
	 * @param array<string, mixed> $form A form row, or empty for none.
	 *
	 * @return int[]
	 */
	public static function location_ids( array $form ): array {
		return self::ids( $form['location_ids'] ?? null );
	}

	/**
	 * The space types a form books. Empty for all of them.
	 *
	 * @param array<string, mixed> $form A form row, or empty for none.
	 *
	 * @return int[]
	 */
	public static function space_type_ids( array $form ): array {
		return self::ids( $form['space_type_ids'] ?? null );
	}

	/**
	 * Whether a form books at a location.
	 *
	 * @param array<string, mixed> $form        A form row, or empty for none.
	 * @param int                  $location_id The location.
	 */
	public static function allows_location( array $form, int $location_id ): bool {
		$scope = self::location_ids( $form );

		return array() === $scope || in_array( $location_id, $scope, true );
	}

	/**
	 * Whether a form books a space type.
	 *
	 * @param array<string, mixed> $form          A form row, or empty for none.
	 * @param int                  $space_type_id The space type.
	 */
	public static function allows_space_type( array $form, int $space_type_id ): bool {
		$scope = self::space_type_ids( $form );

		return array() === $scope || in_array( $space_type_id, $scope, true );
	}

	/**
	 * Whether a form takes a coupon code. Read from the step that shows the field, as the page does.
	 *
	 * @param array<string, mixed> $form A form row, or empty for none.
	 */
	public static function takes_coupons( array $form ): bool {
		$steps = is_array( $form['steps'] ?? null ) && array() !== $form['steps'] ? $form['steps'] : FormService::default_steps();

		foreach ( $steps as $step ) {
			if ( is_array( $step ) && 'space' === ( $step['key'] ?? '' ) ) {
				return false !== ( $step['options']['coupon'] ?? true );
			}
		}

		return true;
	}

	/**
	 * The custom fields a form asks for.
	 *
	 * @param array<string, mixed> $form A form row, or empty for none.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function fields( array $form ): array {
		$settings = is_array( $form['settings'] ?? null ) ? $form['settings'] : array();

		return is_array( $settings['fields'] ?? null ) ? array_values( array_filter( $settings['fields'], 'is_array' ) ) : array();
	}

	/**
	 * A customer's answers to a form's own fields, checked as the page checks them.
	 *
	 * A required field must be answered (a required checkbox ticked), an email must be an email,
	 * and a choice must be one of the field's options. A key the form does not ask for is dropped.
	 *
	 * @param array<string, mixed> $form    The form row, or empty for none.
	 * @param mixed                $answers What the request sent: field key => answer.
	 *
	 * @return array<string, string>|WP_Error The answers to keep.
	 */
	public static function answers( array $form, mixed $answers ): array|WP_Error {
		$answers = is_array( $answers ) ? $answers : array();
		$kept    = array();

		foreach ( self::fields( $form ) as $field ) {
			$key   = (string) ( $field['key'] ?? '' );
			$type  = (string) ( $field['type'] ?? 'text' );
			$raw   = $answers[ $key ] ?? '';
			$value = is_bool( $raw ) ? ( $raw ? '1' : '' ) : ( is_scalar( $raw ) ? trim( (string) $raw ) : '' );

			// A box is sent as true/false; an older page sent its own "Yes"/"No" text.
			if ( 'checkbox' === $type ) {
				$unticked = array( '', '0', 'false', 'no', 'off', strtolower( __( 'No', 'plugixa-car-parking' ) ) );
				$value    = in_array( strtolower( $value ), $unticked, true ) ? '' : __( 'Yes', 'plugixa-car-parking' );

				if ( '' === $value && empty( $field['required'] ) && array_key_exists( $key, $answers ) ) {
					$kept[ $key ] = __( 'No', 'plugixa-car-parking' );
					continue;
				}
			}

			$refused = null;

			if ( '' === $value ) {
				$refused = empty( $field['required'] ) ? null : __( 'This field is required.', 'plugixa-car-parking' );
			} elseif ( 'email' === $type && ! is_email( $value ) ) {
				$refused = __( 'Enter a valid email address.', 'plugixa-car-parking' );
			} elseif ( 'select' === $type && ! in_array( $value, array_map( 'strval', (array) ( $field['options'] ?? array() ) ), true ) ) {
				$refused = __( 'Choose one of the options.', 'plugixa-car-parking' );
			}

			if ( null !== $refused ) {
				return new WP_Error(
					'invalid_field',
					/* translators: 1: a field's label, 2: what is wrong with the answer. */
					sprintf( __( '%1$s: %2$s', 'plugixa-car-parking' ), (string) ( $field['label'] ?? $key ), $refused ),
					array(
						'status' => 422,
						'field'  => $key,
					)
				);
			}

			if ( '' !== $value ) {
				$kept[ $key ] = 'textarea' === $type ? sanitize_textarea_field( $value ) : sanitize_text_field( $value );
			}
		}

		return $kept;
	}

	/**
	 * A stored id list, decoded or not, as integers.
	 *
	 * @param mixed $value The column.
	 *
	 * @return int[]
	 */
	private static function ids( mixed $value ): array {
		if ( is_string( $value ) ) {
			$value = json_decode( $value, true );
		}

		return is_array( $value ) ? array_values( array_filter( array_map( 'intval', $value ) ) ) : array();
	}
}
