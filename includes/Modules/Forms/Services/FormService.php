<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Modules\Forms\Services;

use PlugixaCarParking\Core\Entities\RawInput;
use PlugixaCarParking\Modules\Forms\Repositories\FormRepository;
use PlugixaCarParking\Modules\Locations\Repositories\LocationRepository;
use PlugixaCarParking\Modules\SpaceTypes\Repositories\SpaceTypeRepository;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The rules for creating and changing a booking form: its steps, its custom fields and its look.
 *
 * Everything that writes a form comes through here — the REST controller, a mass action, an
 * importer — so the rules are in one place and none of them can be skipped by taking another route.
 */
final class FormService {

	/**
	 * The modules' claimers of keys in a booking form's settings, see {@see add_setting_claimer()}.
	 *
	 * @var callable[]
	 */
	private static array $setting_claimers = array();

	/**
	 * Register a module's claimer: `fn( array $owned, array $settings, array $existing ): array`,
	 * adding the keys the module keeps in a form's settings to `$owned`, cleaned, from what the
	 * request sent under `settings` (untrusted). A key no claimer returns is not stored.
	 *
	 * @param callable $claimer The module's claimer.
	 */
	public static function add_setting_claimer( callable $claimer ): void {
		self::$setting_claimers[] = $claimer;
	}

	/** @var string[] */
	public const STATUSES = array( 'active', 'inactive' );

	/**
	 * The steps every form can be built from. `step_keys()` is the list to check against.
	 *
	 * @var string[]
	 */
	private const STEP_KEYS = array( 'dates', 'space', 'details', 'payment', 'confirm' );

	/**
	 * The kinds of custom field a form can ask for.
	 *
	 * @var string[]
	 */
	public const FIELD_TYPES = array( 'text', 'email', 'tel', 'select', 'checkbox', 'textarea' );

	/**
	 * The accent colour of a form that names none, or names one that is not a colour. It is the
	 * admin app's own brand colour, so a new form matches the screen it was made on.
	 */
	public const DEFAULT_COLOR = '#1976d2';

	/**
	 * The looks the public form can be drawn in. The first is the one a form that names none,
	 * or names one the plugin does not have, is drawn in. Each is a `data-pcp-design` value the
	 * stylesheet knows: assets/frontend/src/designs/.
	 *
	 * @var string[]
	 */
	public const DESIGNS = array( 'classic', 'split', 'minimal' );

	public function __construct(
		private readonly FormRepository $forms,
		private readonly LocationRepository $locations,
		private readonly SpaceTypeRepository $space_types
	) {}

	/**
	 * The steps a form can be built from on this site.
	 *
	 * Public and static because the public booking form reads the same list. A module that puts
	 * a step of its own on the form adds its key, so a form that uses the step can be saved only
	 * while the module that draws it is installed.
	 *
	 * @return string[]
	 */
	public static function step_keys(): array {
		/**
		 * The steps a booking form can be built from, in the order the builder offers them.
		 *
		 * @param string[] $keys Step keys.
		 */
		$keys = (array) apply_filters( 'plugixa_carparking_form_step_keys', self::STEP_KEYS );
		$keys = array_filter( array_map( 'sanitize_key', array_filter( $keys, 'is_string' ) ) );

		// The form's own steps cannot be filtered away: a form without them books nothing.
		return array_values( array_unique( array_merge( $keys, self::STEP_KEYS ) ) );
	}

	/**
	 * Steps in an order the public form can walk: the dates first, the review last.
	 *
	 * A space can only be offered once the stay is known, and the review is where the booking is
	 * placed, so those two ends are fixed. Everything between them keeps the order it was given.
	 * Used when a form is saved and again when it is shown, so what is stored and what a customer
	 * walks through are the same order.
	 *
	 * @param array<int, array<string, mixed>> $steps Steps, each with a `key`.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function walkable( array $steps ): array {
		$first  = array();
		$middle = array();
		$last   = array();

		foreach ( $steps as $step ) {
			$key = is_array( $step ) ? ( $step['key'] ?? '' ) : '';

			if ( 'dates' === $key ) {
				$first[] = $step;
			} elseif ( 'confirm' === $key ) {
				$last[] = $step;
			} else {
				$middle[] = $step;
			}
		}

		return array_merge( $first, $middle, $last );
	}

	/**
	 * The screens the public form shows for a set of steps, in the order it shows them.
	 *
	 * A step that is switched off is not shown. The step for choosing how to pay is a screen only
	 * when there is a choice to make: with one way to pay, or none, that way is used and the
	 * customer is not asked. A step some other code added to the list is not a screen of the
	 * form's own, and whatever added it draws it inside one of these.
	 *
	 * @param array<int, array<string, mixed>> $steps           A form's steps. Empty for the standard ones.
	 * @param int                              $payment_methods How many ways to pay a customer is offered.
	 *
	 * @return string[] Step keys.
	 */
	public static function flow( array $steps, int $payment_methods ): array {
		$flow = array();

		foreach ( self::walkable( array() !== $steps ? $steps : self::default_steps() ) as $step ) {
			$key = is_array( $step ) && is_string( $step['key'] ?? null ) ? $step['key'] : '';

			if ( ! in_array( $key, self::STEP_KEYS, true ) || in_array( $key, $flow, true ) || empty( $step['enabled'] ) ) {
				continue;
			}

			if ( 'payment' === $key && $payment_methods < 2 ) {
				continue;
			}

			$flow[] = $key;
		}

		// A form stored with every screen off still has to book something.
		return array() !== $flow ? $flow : array( 'dates', 'space', 'details', 'confirm' );
	}

	/**
	 * The steps of a form that has none of its own.
	 *
	 * Public and static because the public booking form falls back to the same set, and two
	 * copies of it would drift.
	 *
	 * An option is listed on the step the public form reads it from. The form shows its coupon
	 * field beside the choice of space, and reads whether to show it from that step and no other.
	 *
	 * @return array<int, array{key: string, enabled: bool, options: array<string, bool>}>
	 */
	public static function default_steps(): array {
		return array(
			array(
				'key'     => 'dates',
				'enabled' => true,
				'options' => array(),
			),
			array(
				'key'     => 'space',
				'enabled' => true,
				'options' => array( 'coupon' => true ),
			),
			array(
				'key'     => 'details',
				'enabled' => true,
				'options' => array(),
			),
			array(
				'key'     => 'payment',
				'enabled' => true,
				'options' => array(),
			),
			array(
				'key'     => 'confirm',
				'enabled' => true,
				'options' => array(),
			),
		);
	}

	/**
	 * A form as its readers expect it, which is not quite how it is stored.
	 *
	 * Custom fields have no column of their own: they live inside the `settings` JSON, and are
	 * lifted to a top-level `fields` key here. A form with no steps is given the default set, so a
	 * reader never has to decide what an empty form means.
	 *
	 * @param array<string, mixed> $row A stored form, decoded.
	 *
	 * @return array<string, mixed>
	 */
	public function present( array $row ): array {
		$settings = is_array( $row['settings'] ?? null ) ? $row['settings'] : array();

		$row['fields'] = $settings['fields'] ?? array();

		// The switch the editor shows. Stored as an empty list, which is what "all" has always meant.
		$row['all_locations'] = array() === ( is_array( $row['location_ids'] ?? null ) ? $row['location_ids'] : array() );

		unset( $row['mode'] );

		if ( ! is_array( $row['steps'] ?? null ) || array() === $row['steps'] ) {
			$row['steps'] = self::default_steps();
		}

		return $row;
	}

	/**
	 * @param int $id Form id.
	 *
	 * @return array<string, mixed>|null
	 */
	public function get( int $id ): ?array {
		$row = $this->forms->find( $id );

		return null === $row ? null : $this->forms->cast( $row );
	}

	/**
	 * @param array<string, mixed> $input Raw, untrusted payload.
	 *
	 * @return int|WP_Error
	 */
	public function create( array $input ): int|WP_Error {
		$refused = $this->refuse_unknown_choices( $input );
		if ( $refused instanceof WP_Error ) {
			return $refused;
		}

		$data = $this->sanitize( $input, true, array() );

		$invalid = $this->validate( $data );
		if ( $invalid instanceof WP_Error ) {
			return $invalid;
		}

		$data['slug'] = $this->forms->unique_slug( '' !== (string) ( $data['slug'] ?? '' ) ? $data['slug'] : $data['name'] );

		// The first form of a site is its default: a page that names none has to show something.
		if ( null === $this->forms->default_form() ) {
			$data['is_default'] = 1;
		}

		$refused = $this->refuse_default_change( $data, array() );
		if ( $refused instanceof WP_Error ) {
			return $refused;
		}

		$id = $this->forms->insert( $data );

		if ( $id > 0 && ! empty( $data['is_default'] ) ) {
			$this->forms->clear_default_except( $id );
		}

		return $id > 0
			? $id
			: new WP_Error( 'plugixa_carparking_insert_failed', __( 'The form could not be saved.', 'plugixa-car-parking' ), array( 'status' => 500 ) );
	}

	/**
	 * @param int                  $id    Form id.
	 * @param array<string, mixed> $input Raw, untrusted payload.
	 *
	 * @return bool|WP_Error
	 */
	public function update( int $id, array $input ): bool|WP_Error {
		$existing = $this->forms->find( $id );

		if ( null === $existing ) {
			return $this->not_found();
		}

		$refused = $this->refuse_unknown_choices( $input );
		if ( $refused instanceof WP_Error ) {
			return $refused;
		}

		$data = $this->sanitize( $input, false, $existing );

		$invalid = $this->validate( $data );
		if ( $invalid instanceof WP_Error ) {
			return $invalid;
		}

		// Only when a slug was sent: a save that does not mention it must not rename the form out
		// from under a shortcode that names it.
		if ( array_key_exists( 'slug', $data ) ) {
			$data['slug'] = $this->forms->unique_slug( $data['slug'], $id );
		}

		$refused = $this->refuse_default_change( $data, $existing );
		if ( $refused instanceof WP_Error ) {
			return $refused;
		}

		$saved = $this->forms->update( $id, $data );

		if ( $saved && ! empty( $data['is_default'] ) ) {
			$this->forms->clear_default_except( $id );
		}

		return $saved;
	}

	/**
	 * Trash a form, unless something says it must stay.
	 *
	 * This module cannot know what else points at a form, and must not: it asks, and whichever
	 * module holds a reference answers with a sentence the user can act on.
	 *
	 * @param int $id Form id.
	 *
	 * @return bool|WP_Error
	 */
	public function delete( int $id ): bool|WP_Error {
		$form = $this->forms->find( $id );

		if ( null === $form ) {
			return $this->not_found();
		}

		if ( ! empty( $form['is_default'] ) ) {
			return new WP_Error(
				'plugixa_carparking_booking_form_in_use',
				__( 'This is the default form, shown on every booking page that names no form. Make another form the default first.', 'plugixa-car-parking' ),
				array( 'status' => 409 )
			);
		}

		/**
		 * Reasons this form must not be trashed.
		 *
		 * @param string[]             $blockers Human-readable sentences. Empty means "go ahead".
		 * @param int                  $id       Form id.
		 * @param array<string, mixed> $form     The row.
		 */
		$blockers = (array) apply_filters( 'plugixa_carparking_booking_form_delete_blockers', array(), $id, $form );

		if ( array() !== $blockers ) {
			return new WP_Error(
				'plugixa_carparking_booking_form_in_use',
				implode( ' ', array_map( 'strval', $blockers ) ),
				array(
					'status'   => 409,
					'blockers' => array_values( $blockers ),
				)
			);
		}

		return $this->forms->delete( $id );
	}

	/**
	 * Bring a trashed form back.
	 *
	 * Its slug is still its own: a trashed row keeps holding its slug, so nothing can have taken
	 * it in the meantime.
	 *
	 * @param int $id Form id.
	 *
	 * @return bool|WP_Error
	 */
	public function restore( int $id ): bool|WP_Error {
		return null === $this->forms->find( $id, true )
			? $this->not_found()
			: $this->forms->restore( $id );
	}

	public function not_found(): WP_Error {
		return new WP_Error(
			'plugixa_carparking_not_found',
			__( 'That form does not exist.', 'plugixa-car-parking' ),
			array( 'status' => 404 )
		);
	}

	// --- Cleaning and checking -----------------------------------------------

	/**
	 * Refuse a choice that is not one of the options, before sanitize() would quietly replace it.
	 *
	 * A custom field of type "date" must come back as an error, not be stored as a text box with
	 * a success response: the caller would believe a form was saved that was not.
	 *
	 * @param array<string, mixed> $input Raw payload.
	 */
	private function refuse_unknown_choices( array $input ): ?WP_Error {
		return RawInput::first(
			RawInput::choice( $input, 'status', self::STATUSES, __( 'Status', 'plugixa-car-parking' ) ),
			$this->refuse_unknown_in_rows( $input, 'steps', 'key', self::step_keys(), __( 'Step', 'plugixa-car-parking' ) ),
			$this->refuse_unknown_in_rows( $input, 'fields', 'type', self::FIELD_TYPES, __( 'Field type', 'plugixa-car-parking' ) )
		);
	}

	/**
	 * The same check for a choice made once per row of a list.
	 *
	 * The refusal names the LIST as its field, because that is the input the form has, and says
	 * which line it is about. An entry that is not a row carries no choice to judge, and is left
	 * for sanitize() to drop.
	 *
	 * @param array<string, mixed> $input   Raw payload.
	 * @param string               $list    The key holding the list: `steps` or `fields`.
	 * @param string               $key     The key each row makes its choice under.
	 * @param string[]             $allowed The accepted values.
	 * @param string               $label   What the choice is called, translated.
	 */
	private function refuse_unknown_in_rows( array $input, string $list, string $key, array $allowed, string $label ): ?WP_Error {
		if ( ! isset( $input[ $list ] ) || ! is_array( $input[ $list ] ) ) {
			return null;
		}

		foreach ( array_values( $input[ $list ] ) as $index => $row ) {
			$refused = is_array( $row ) ? RawInput::choice( $row, $key, $allowed, $label ) : null;

			if ( $refused instanceof WP_Error ) {
				$refused->add_data(
					array_merge(
						(array) $refused->get_error_data(),
						array(
							'field' => $list,
							'line'  => $index + 1,
						)
					)
				);

				return $refused;
			}
		}

		return null;
	}

	/**
	 * @param array<string, mixed> $input         Raw payload.
	 * @param bool                 $with_defaults Fill absent keys, for a create.
	 * @param array<string, mixed> $existing      The stored row when updating, empty when creating.
	 *
	 * @return array<string, mixed>
	 */
	private function sanitize( array $input, bool $with_defaults, array $existing ): array {
		$data = array();

		if ( $with_defaults || array_key_exists( 'name', $input ) ) {
			$data['name'] = sanitize_text_field( (string) ( $input['name'] ?? '' ) );
		}

		if ( array_key_exists( 'slug', $input ) && '' !== trim( (string) $input['slug'] ) ) {
			$data['slug'] = sanitize_title( (string) $input['slug'] );
		}

		if ( $with_defaults || array_key_exists( 'status', $input ) ) {
			$data['status'] = sanitize_key( (string) ( $input['status'] ?? 'active' ) );
		}

		if ( array_key_exists( 'is_default', $input ) ) {
			$data['is_default'] = true === rest_sanitize_boolean( is_scalar( $input['is_default'] ) ? $input['is_default'] : false ) ? 1 : 0;
		}

		// A colour is free text, not a choice from a list, so one that cannot be read falls back
		// to the default accent instead of refusing the whole form.
		if ( array_key_exists( 'style', $input ) && is_array( $input['style'] ) ) {
			$color = sanitize_hex_color( is_string( $input['style']['primary_color'] ?? null ) ? $input['style']['primary_color'] : '' );

			$design = is_string( $input['style']['design'] ?? null ) ? sanitize_key( $input['style']['design'] ) : '';

			$data['style'] = array(
				'primary_color' => '' !== (string) $color ? $color : self::DEFAULT_COLOR,
				'design'        => in_array( $design, self::DESIGNS, true ) ? $design : self::DESIGNS[0],
			);
		}

		// An empty list is "every location", which is how the public form reads it.
		if ( array_key_exists( 'location_ids', $input ) ) {
			$data['location_ids'] = self::id_list( $input['location_ids'] );
		}

		// The editor's switch, when it is sent, decides. "Only these" with none chosen is reported
		// by validate(), rather than quietly read as "all".
		if ( array_key_exists( 'all_locations', $input ) ) {
			if ( true === rest_sanitize_boolean( is_scalar( $input['all_locations'] ) ? $input['all_locations'] : false ) ) {
				$data['location_ids'] = array();
			} else {
				$data['location_ids']    = $data['location_ids'] ?? self::id_list( $existing['location_ids'] ?? array() );
				$data['_only_locations'] = true;
			}
		}

		// Empty for every space type.
		if ( array_key_exists( 'space_type_ids', $input ) ) {
			$data['space_type_ids'] = self::id_list( $input['space_type_ids'] );
		}

		if ( array_key_exists( 'steps', $input ) && is_array( $input['steps'] ) ) {
			$data['steps'] = $this->sanitize_steps( $input['steps'] );
		}

		// Custom fields have no column of their own: they are one key of the `settings` JSON, so
		// they are written into whatever else is already stored there, not over it.
		if ( array_key_exists( 'fields', $input ) && is_array( $input['fields'] ) ) {
			$settings           = is_array( $existing['settings'] ?? null ) ? $existing['settings'] : array();
			$settings['fields'] = $this->sanitize_fields( $input['fields'] );
			$data['settings']   = $settings;
		}

		// Other modules keep choices of their own in the same JSON, sent under `settings`. Each one
		// takes from it only the keys it owns, cleaned; nothing is stored that no module claimed,
		// and the custom fields above cannot be written this way.
		if ( array_key_exists( 'settings', $input ) && is_array( $input['settings'] ) ) {
			// Through the modules' registered claimers, not a filter: what the request sent is
			// untrusted, and a filter would hand it to every callback attached to it.
			$owned = array();

			foreach ( self::$setting_claimers as $claimer ) {
				$claimed = $claimer( $owned, $input['settings'], $existing );
				$owned   = is_array( $claimed ) ? $claimed : $owned;
			}

			unset( $owned['fields'] );

			if ( array() !== $owned ) {
				$settings         = $data['settings'] ?? ( is_array( $existing['settings'] ?? null ) ? $existing['settings'] : array() );
				$data['settings'] = array_merge( $settings, $owned );
			}
		}

		return $data;
	}

	/**
	 * The steps of a form, in the order sent, with the dates first and the review last.
	 *
	 * A step is on unless it says otherwise, so a row that names only its key is an enabled step.
	 * An entry that is not a row, or names no step, is dropped: it describes nothing.
	 *
	 * The two ends are put where the public form needs them, by the rule in `walkable()`. The
	 * builder refuses to move them, so this only changes a list that came from somewhere else.
	 *
	 * @param array<mixed> $steps What the request sent. Unknown step keys were refused earlier.
	 *
	 * @return array<int, array{key: string, enabled: bool, options: array<string, bool>}>
	 */
	private function sanitize_steps( array $steps ): array {
		$clean = array();

		foreach ( $steps as $step ) {
			$key = is_array( $step ) && is_string( $step['key'] ?? null ) ? sanitize_key( $step['key'] ) : '';

			if ( ! in_array( $key, self::step_keys(), true ) ) {
				continue;
			}

			$options = array();

			if ( isset( $step['options'] ) && is_array( $step['options'] ) ) {
				foreach ( $step['options'] as $option => $value ) {
					$options[ sanitize_key( (string) $option ) ] = true === rest_sanitize_boolean( is_scalar( $value ) ? $value : false );
				}
			}

			$clean[] = array(
				'key'     => $key,
				'enabled' => ! isset( $step['enabled'] ) || true === rest_sanitize_boolean( is_scalar( $step['enabled'] ) ? $step['enabled'] : false ),
				'options' => $options,
			);
		}

		return self::walkable( $clean );
	}

	/**
	 * The custom fields of a form, in the order sent.
	 *
	 * A row with no label is dropped: it is a line the builder added and nobody filled in, and a
	 * field with no label cannot be shown to a customer. A field with no key is given one from its
	 * position, because answers are stored under the key.
	 *
	 * @param array<mixed> $fields What the request sent. Unknown field types were refused earlier.
	 *
	 * @return array<int, array{key: string, label: string, type: string, required: bool, options: string[]}>
	 */
	private function sanitize_fields( array $fields ): array {
		$clean = array();

		foreach ( array_values( $fields ) as $index => $field ) {
			$label = is_array( $field ) && is_scalar( $field['label'] ?? null ) ? sanitize_text_field( (string) $field['label'] ) : '';

			if ( '' === $label ) {
				continue;
			}

			$type = is_string( $field['type'] ?? null ) ? sanitize_key( $field['type'] ) : '';
			$key  = is_scalar( $field['key'] ?? null ) ? sanitize_key( (string) $field['key'] ) : '';

			$options = array();

			if ( isset( $field['options'] ) && is_array( $field['options'] ) ) {
				foreach ( $field['options'] as $option ) {
					$option = is_scalar( $option ) ? sanitize_text_field( (string) $option ) : '';

					if ( '' !== $option ) {
						$options[] = $option;
					}
				}
			}

			$clean[] = array(
				'key'      => '' !== $key ? $key : 'field_' . $index,
				'label'    => $label,
				'type'     => in_array( $type, self::FIELD_TYPES, true ) ? $type : 'text',
				'required' => ! empty( $field['required'] ),
				'options'  => $options,
			);
		}

		return $clean;
	}

	/**
	 * Positive, distinct integer ids from whatever a request sent.
	 *
	 * @param mixed $value A list, or anything else for none.
	 *
	 * @return int[]
	 */
	private static function id_list( mixed $value ): array {
		$ids = array_filter(
			array_map( 'intval', is_array( $value ) ? $value : array() ),
			static fn( int $id ): bool => $id > 0
		);

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Keep exactly one default form, and keep it able to take bookings.
	 *
	 * The default moves by making another form the default, never by taking the mark off: a site
	 * with none would leave every page that names no form empty.
	 *
	 * @param array<string, mixed> $data     The sanitized change.
	 * @param array<string, mixed> $existing The stored form, empty for a new one.
	 */
	private function refuse_default_change( array $data, array $existing ): ?WP_Error {
		$was_default  = ! empty( $existing['is_default'] );
		$will_default = array_key_exists( 'is_default', $data ) ? ! empty( $data['is_default'] ) : $was_default;
		$status       = (string) ( $data['status'] ?? $existing['status'] ?? 'active' );

		if ( $was_default && ! $will_default ) {
			return new WP_Error(
				'plugixa_carparking_default_form',
				__( 'A site always has a default form. Make another form the default instead.', 'plugixa-car-parking' ),
				array(
					'status' => 422,
					'field'  => 'is_default',
				)
			);
		}

		if ( $will_default && 'active' !== $status ) {
			return new WP_Error(
				'plugixa_carparking_default_form',
				__( 'The default form has to be active: it is shown on every booking page that names no form.', 'plugixa-car-parking' ),
				array(
					'status' => 422,
					'field'  => 'status',
				)
			);
		}

		return null;
	}

	/**
	 * Check a sanitized payload. Takes out the marker sanitize() leaves for the location switch.
	 *
	 * @param array<string, mixed> $data Sanitized payload.
	 */
	private function validate( array &$data ): ?WP_Error {
		$only_some = ! empty( $data['_only_locations'] );
		unset( $data['_only_locations'] );

		if ( $only_some && array() === ( $data['location_ids'] ?? array() ) ) {
			return new WP_Error(
				'plugixa_carparking_no_locations',
				__( 'Choose at least one location, or make the form available at all locations.', 'plugixa-car-parking' ),
				array(
					'status' => 422,
					'field'  => 'location_ids',
				)
			);
		}

		$scopes = array(
			'location_ids'   => array( $this->locations, __( 'One of the chosen locations does not exist.', 'plugixa-car-parking' ) ),
			'space_type_ids' => array( $this->space_types, __( 'One of the chosen space types does not exist.', 'plugixa-car-parking' ) ),
		);

		foreach ( $scopes as $field => [ $repository, $message ] ) {
			foreach ( (array) ( $data[ $field ] ?? array() ) as $id ) {
				if ( null === $repository->find( (int) $id ) ) {
					return new WP_Error(
						'plugixa_carparking_unknown_scope',
						$message,
						array(
							'status' => 422,
							'field'  => $field,
						)
					);
				}
			}
		}

		if ( array_key_exists( 'name', $data ) && '' === trim( (string) $data['name'] ) ) {
			return new WP_Error(
				'plugixa_carparking_missing_name',
				__( 'A form needs a name.', 'plugixa-car-parking' ),
				array(
					'status' => 422,
					'field'  => 'name',
				)
			);
		}

		return $this->forms->too_long( $data );
	}
}
