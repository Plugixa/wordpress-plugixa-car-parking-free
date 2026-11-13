<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Modules\Locations\Services;

use PlugixaCarParking\Core\Entities\RawInput;
use PlugixaCarParking\Modules\Bookings\Services\ModeRegistry;
use PlugixaCarParking\Modules\Locations\Repositories\ExceptionsTable;
use PlugixaCarParking\Modules\Locations\Repositories\HoursTable;
use PlugixaCarParking\Modules\Locations\Repositories\LocationRepository;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The rules for creating and changing a location, its opening hours and its dated exceptions.
 *
 * Everything that writes a location comes through here — the REST controller, a mass action, an
 * importer — so the rules are in one place and none of them can be skipped by taking another route.
 */
final class LocationService {

	/** @var string[] */
	public const STATUSES = array( 'active', 'inactive' );

	/** @var string[] */
	public const BILLING_TYPES = array( 'minute', 'hour', 'day', 'week', 'month' );

	/** @var string[] */
	public const EXCEPTION_TYPES = array( 'closed', 'special' );

	/**
	 * Plain single-line text columns, cleaned the same way.
	 *
	 * @var string[]
	 */
	private const TEXT_FIELDS = array(
		'name',
		'currency',
		'timezone',
		'address_line1',
		'address_line2',
		'city',
		'state',
		'postcode',
		'country',
		'phone',
	);

	public function __construct(
		private readonly LocationRepository $locations,
		private readonly HoursTable $hours,
		private readonly ExceptionsTable $exceptions,
		private readonly ModeRegistry $modes
	) {}

	/**
	 * @param int $id Location id.
	 *
	 * @return array<string, mixed>|null
	 */
	public function get( int $id ): ?array {
		$row = $this->locations->find( $id );

		return null === $row ? null : $this->locations->cast( $row );
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

		$data = $this->sanitize( $input, true );

		$invalid = $this->validate( $data );
		if ( $invalid instanceof WP_Error ) {
			return $invalid;
		}

		$data['slug'] = $this->locations->unique_slug( '' !== (string) ( $data['slug'] ?? '' ) ? $data['slug'] : $data['name'] );

		$id = $this->locations->insert( $data );

		return $id > 0
			? $id
			: new WP_Error( 'plugixa_carparking_insert_failed', __( 'The location could not be saved.', 'plugixa-car-parking' ), array( 'status' => 500 ) );
	}

	/**
	 * @param int                  $id    Location id.
	 * @param array<string, mixed> $input Raw, untrusted payload.
	 *
	 * @return bool|WP_Error
	 */
	public function update( int $id, array $input ): bool|WP_Error {
		if ( null === $this->locations->find( $id ) ) {
			return $this->not_found();
		}

		$refused = $this->refuse_unknown_choices( $input );
		if ( $refused instanceof WP_Error ) {
			return $refused;
		}

		$data = $this->sanitize( $input, false );

		$invalid = $this->validate( $data );
		if ( $invalid instanceof WP_Error ) {
			return $invalid;
		}

		// Only when a slug was sent: a save that does not mention it must not rename the location's
		// address out from under a shortcode that names it.
		if ( array_key_exists( 'slug', $data ) ) {
			$data['slug'] = $this->locations->unique_slug( $data['slug'], $id );
		}

		return $this->locations->update( $id, $data );
	}

	/**
	 * Trash a location, unless something says it must stay.
	 *
	 * This module cannot know what else points at a location, and must not: it asks, and whichever
	 * module holds a reference answers. Each answer is a SENTENCE, not a boolean, because "you
	 * cannot delete this" without saying why leaves the user with no move.
	 *
	 * @param int $id Location id.
	 *
	 * @return bool|WP_Error
	 */
	public function delete( int $id ): bool|WP_Error {
		$location = $this->locations->find( $id );

		if ( null === $location ) {
			return $this->not_found();
		}

		/**
		 * Reasons this location must not be trashed.
		 *
		 * @param string[]             $blockers Human-readable sentences. Empty means "go ahead".
		 * @param int                  $id       Location id.
		 * @param array<string, mixed> $location The row.
		 */
		$blockers = (array) apply_filters( 'plugixa_carparking_location_delete_blockers', array(), $id, $location );

		if ( array() !== $blockers ) {
			return new WP_Error(
				'plugixa_carparking_location_in_use',
				implode( ' ', array_map( 'strval', $blockers ) ),
				array(
					'status'   => 409,
					'blockers' => array_values( $blockers ),
				)
			);
		}

		return $this->locations->delete( $id );
	}

	/**
	 * Bring a trashed location back.
	 *
	 * Its slug is still its own: a trashed row keeps holding its slug, so nothing can have taken
	 * it in the meantime.
	 *
	 * @param int $id Location id.
	 *
	 * @return bool|WP_Error
	 */
	public function restore( int $id ): bool|WP_Error {
		return null === $this->locations->find( $id, true )
			? $this->not_found()
			: $this->locations->restore( $id );
	}

	public function not_found(): WP_Error {
		return new WP_Error(
			'plugixa_carparking_not_found',
			__( 'That location does not exist.', 'plugixa-car-parking' ),
			array( 'status' => 404 )
		);
	}

	// --- Opening hours -------------------------------------------------------

	/**
	 * A location's week, always seven rows, Sunday first.
	 *
	 * A weekday with nothing stored is shown as open nine to six. That is what the form offers to
	 * start from, not what is enforced: a location with no rows at all is open around the clock.
	 *
	 * @param int $location_id Location id.
	 *
	 * @return array<int, array{day_of_week: int, open_time: string, close_time: string, is_closed: bool}>
	 */
	public function hours( int $location_id ): array {
		$stored = $this->hours->map( $location_id );
		$week   = array();

		for ( $day = 0; $day <= 6; $day++ ) {
			$row    = $stored[ $day ] ?? null;
			$week[] = array(
				'day_of_week' => $day,
				'open_time'   => (string) ( $row['open_time'] ?? '09:00:00' ),
				'close_time'  => (string) ( $row['close_time'] ?? '18:00:00' ),
				'is_closed'   => null !== $row && 1 === (int) $row['is_closed'],
			);
		}

		return $week;
	}

	/**
	 * Whether a location has a stored week at all.
	 *
	 * `hours()` fills an unsaved week with starting values, so its answer alone cannot tell a
	 * location that opens nine to six from one that has no rows and is open around the clock.
	 *
	 * @param int $location_id Location id.
	 */
	public function has_hours( int $location_id ): bool {
		return array() !== $this->hours->map( $location_id );
	}

	/**
	 * Replace a location's week.
	 *
	 * @param int   $location_id Location id.
	 * @param mixed $input       The `hours` the request sent: a list of weekday rows.
	 *
	 * @return true|WP_Error
	 */
	public function save_hours( int $location_id, mixed $input ): true|WP_Error {
		if ( null === $this->locations->find( $location_id ) ) {
			return $this->not_found();
		}

		if ( ! is_array( $input ) ) {
			return new WP_Error( 'plugixa_carparking_invalid_hours', __( 'Opening hours must be a list of weekday rows.', 'plugixa-car-parking' ), array( 'status' => 422 ) );
		}

		$rows = array();
		foreach ( $input as $row ) {
			$day = is_array( $row ) ? (int) ( $row['day_of_week'] ?? -1 ) : -1;

			if ( $day < 0 || $day > 6 ) {
				continue;
			}

			// Keyed by weekday, so a payload that repeats a day keeps the last and stores one.
			$rows[ $day ] = array(
				'day_of_week' => $day,
				'open_time'   => $this->time( $row['open_time'] ?? '' ) ?? '09:00:00',
				'close_time'  => $this->time( $row['close_time'] ?? '' ) ?? '18:00:00',
				'is_closed'   => true === rest_sanitize_boolean( $row['is_closed'] ?? false ),
			);
		}

		ksort( $rows );
		$this->hours->replace( $location_id, array_values( $rows ) );

		return true;
	}

	// --- Dated exceptions ----------------------------------------------------

	/**
	 * @param int $location_id Location id.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function exceptions( int $location_id ): array {
		return array_map( array( $this, 'cast_exception' ), $this->exceptions->for_location( $location_id ) );
	}

	/**
	 * @param int                  $location_id Location id.
	 * @param array<string, mixed> $input       Raw, untrusted payload.
	 *
	 * @return array<string, mixed>|WP_Error The stored exception.
	 */
	public function add_exception( int $location_id, array $input ): array|WP_Error {
		if ( null === $this->locations->find( $location_id ) ) {
			return $this->not_found();
		}

		$data = $this->sanitize_exception( $input, array() );
		if ( $data instanceof WP_Error ) {
			return $data;
		}

		$id = $this->exceptions->insert( array( 'location_id' => $location_id ) + $data );

		if ( 0 === $id ) {
			return new WP_Error( 'plugixa_carparking_insert_failed', __( 'The closure could not be saved.', 'plugixa-car-parking' ), array( 'status' => 500 ) );
		}

		return $this->cast_exception( (array) $this->exceptions->find( $location_id, $id ) );
	}

	/**
	 * @param int                  $location_id Location id.
	 * @param int                  $id          Exception id.
	 * @param array<string, mixed> $input       Raw, untrusted payload.
	 *
	 * @return array<string, mixed>|WP_Error The stored exception.
	 */
	public function update_exception( int $location_id, int $id, array $input ): array|WP_Error {
		$existing = $this->exceptions->find( $location_id, $id );
		if ( null === $existing ) {
			return $this->exception_not_found();
		}

		$data = $this->sanitize_exception( $input, $existing );
		if ( $data instanceof WP_Error ) {
			return $data;
		}

		$this->exceptions->update( $id, $data );

		return $this->cast_exception( (array) $this->exceptions->find( $location_id, $id ) );
	}

	/**
	 * @param int $location_id Location id.
	 * @param int $id          Exception id.
	 *
	 * @return true|WP_Error
	 */
	public function delete_exception( int $location_id, int $id ): true|WP_Error {
		if ( null === $this->exceptions->find( $location_id, $id ) ) {
			return $this->exception_not_found();
		}

		$this->exceptions->delete( $id );

		return true;
	}

	// --- Cleaning and checking -----------------------------------------------

	/**
	 * Refuse a choice that is not one of the options, before sanitize() would quietly replace it.
	 *
	 * A status of "archived" must come back as an error naming the field, not be stored as
	 * "active" with a success response: the caller would believe something was saved that was not.
	 *
	 * @param array<string, mixed> $input Raw payload.
	 */
	private function refuse_unknown_choices( array $input ): ?WP_Error {
		return RawInput::first(
			RawInput::choice( $input, 'status', self::STATUSES, __( 'Status', 'plugixa-car-parking' ) ),
			RawInput::choice( $input, 'default_billing_type', self::BILLING_TYPES, __( 'Billing unit', 'plugixa-car-parking' ) ),
			RawInput::choice( $input, 'inventory_mode', array_keys( $this->modes->strategies() ), __( 'Inventory mode', 'plugixa-car-parking' ) )
		);
	}

	/**
	 * @param array<string, mixed> $input         Raw payload.
	 * @param bool                 $with_defaults Fill absent keys, for a create.
	 *
	 * @return array<string, mixed>
	 */
	private function sanitize( array $input, bool $with_defaults ): array {
		$data = array();

		foreach ( self::TEXT_FIELDS as $field ) {
			if ( array_key_exists( $field, $input ) ) {
				$data[ $field ] = sanitize_text_field( (string) $input[ $field ] );
			}
		}

		if ( $with_defaults ) {
			$data['name'] = (string) ( $data['name'] ?? '' );
		}

		if ( array_key_exists( 'slug', $input ) && '' !== trim( (string) $input['slug'] ) ) {
			$data['slug'] = sanitize_title( (string) $input['slug'] );
		}

		if ( array_key_exists( 'description', $input ) ) {
			$data['description'] = wp_kses_post( (string) $input['description'] );
		}

		if ( array_key_exists( 'email', $input ) ) {
			$data['email'] = sanitize_email( (string) $input['email'] );
		}

		if ( $with_defaults || array_key_exists( 'status', $input ) ) {
			$data['status'] = sanitize_key( (string) ( $input['status'] ?? 'active' ) );
		}

		if ( $with_defaults || array_key_exists( 'inventory_mode', $input ) ) {
			$data['inventory_mode'] = sanitize_key( (string) ( $input['inventory_mode'] ?? 'capacity' ) );
		}

		if ( $with_defaults || array_key_exists( 'default_billing_type', $input ) ) {
			$data['default_billing_type'] = sanitize_key( (string) ( $input['default_billing_type'] ?? 'day' ) );
		}

		// An empty coordinate is "not set", which is NULL, not the equator.
		foreach ( array( 'latitude', 'longitude' ) as $field ) {
			if ( array_key_exists( $field, $input ) ) {
				$data[ $field ] = ( '' === $input[ $field ] || null === $input[ $field ] ) ? null : (float) $input[ $field ];
			}
		}

		if ( array_key_exists( 'image_id', $input ) ) {
			$data['image_id'] = (int) $input['image_id'] > 0 ? (int) $input['image_id'] : null;
		}

		if ( array_key_exists( 'display_order', $input ) ) {
			$data['display_order'] = (int) $input['display_order'];
		}

		if ( array_key_exists( 'gallery', $input ) && is_array( $input['gallery'] ) ) {
			$data['gallery'] = array_values( array_filter( array_map( 'intval', $input['gallery'] ) ) );
		}

		if ( array_key_exists( 'settings', $input ) && is_array( $input['settings'] ) ) {
			$data['settings'] = $this->sanitize_settings( $input['settings'] );
		}

		return $data;
	}

	/**
	 * A location's own settings: a flat map of scalars under clean keys.
	 *
	 * What the keys MEAN belongs to whichever module reads them; this only makes sure the blob is
	 * a map of plain values and not a place to park arbitrary structures.
	 *
	 * @param array<mixed> $settings What the request sent.
	 *
	 * @return array<string, bool|int|float|string>
	 */
	private function sanitize_settings( array $settings ): array {
		$clean = array();

		foreach ( $settings as $key => $value ) {
			$key = sanitize_key( (string) $key );

			if ( '' === $key || ! is_scalar( $value ) ) {
				continue;
			}

			$clean[ $key ] = is_string( $value ) ? sanitize_text_field( $value ) : $value;
		}

		return $clean;
	}

	/**
	 * @param array<string, mixed> $data Sanitized payload.
	 */
	private function validate( array $data ): ?WP_Error {
		if ( array_key_exists( 'name', $data ) && '' === trim( (string) $data['name'] ) ) {
			return new WP_Error(
				'plugixa_carparking_missing_name',
				__( 'A location needs a name.', 'plugixa-car-parking' ),
				array(
					'status' => 422,
					'field'  => 'name',
				)
			);
		}

		if ( isset( $data['latitude'] ) && ( $data['latitude'] < -90 || $data['latitude'] > 90 ) ) {
			return new WP_Error(
				'plugixa_carparking_invalid_latitude',
				__( 'Latitude must be between -90 and 90.', 'plugixa-car-parking' ),
				array(
					'status' => 422,
					'field'  => 'latitude',
				)
			);
		}

		if ( isset( $data['longitude'] ) && ( $data['longitude'] < -180 || $data['longitude'] > 180 ) ) {
			return new WP_Error(
				'plugixa_carparking_invalid_longitude',
				__( 'Longitude must be between -180 and 180.', 'plugixa-car-parking' ),
				array(
					'status' => 422,
					'field'  => 'longitude',
				)
			);
		}

		return $this->locations->too_long( $data );
	}

	/**
	 * Clean an exception, checked as it will stand once saved.
	 *
	 * The dates are validated on the MERGED row: an update that sends only an end date has to be
	 * compared with the start date already stored.
	 *
	 * @param array<string, mixed> $input    Raw payload.
	 * @param array<string, mixed> $existing The stored row when updating, empty when creating.
	 *
	 * @return array<string, mixed>|WP_Error Columns to write.
	 */
	private function sanitize_exception( array $input, array $existing ): array|WP_Error {
		$refused = RawInput::choice( $input, 'type', self::EXCEPTION_TYPES, __( 'Type', 'plugixa-car-parking' ) );
		if ( $refused instanceof WP_Error ) {
			return $refused;
		}

		$data = array();

		if ( array() === $existing || array_key_exists( 'type', $input ) ) {
			$data['type'] = sanitize_key( (string) ( $input['type'] ?? 'closed' ) );
		}

		foreach ( array( 'start_date', 'end_date' ) as $field ) {
			if ( array_key_exists( $field, $input ) ) {
				$value          = sanitize_text_field( (string) $input[ $field ] );
				$data[ $field ] = 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : '';
			}
		}

		foreach ( array( 'open_time', 'close_time' ) as $field ) {
			if ( array_key_exists( $field, $input ) ) {
				$data[ $field ] = $this->time( $input[ $field ] );
			}
		}

		if ( array_key_exists( 'note', $input ) ) {
			$data['note'] = sanitize_text_field( (string) $input['note'] );
		}

		$merged = $data + $existing;

		if ( empty( $merged['start_date'] ) || empty( $merged['end_date'] ) ) {
			return new WP_Error( 'plugixa_carparking_missing_dates', __( 'A closure needs a start date and an end date.', 'plugixa-car-parking' ), array( 'status' => 422 ) );
		}

		if ( $merged['end_date'] < $merged['start_date'] ) {
			return new WP_Error(
				'plugixa_carparking_invalid_range',
				__( 'The end date must be on or after the start date.', 'plugixa-car-parking' ),
				array(
					'status' => 422,
					'field'  => 'end_date',
				)
			);
		}

		return $data;
	}

	/**
	 * A time of day as HH:MM:SS, or null when the value is empty or is not a time.
	 *
	 * @param mixed $value What the request sent: HH:MM or HH:MM:SS.
	 */
	private function time( mixed $value ): ?string {
		$value = is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';

		if ( 1 === preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $value ) ) {
			return $value . ':00';
		}

		return 1 === preg_match( '/^([01]\d|2[0-3]):[0-5]\d:[0-5]\d$/', $value ) ? $value : null;
	}

	/**
	 * @param array<string, mixed> $row A stored exception.
	 *
	 * @return array<string, mixed>
	 */
	private function cast_exception( array $row ): array {
		$row['id']          = (int) ( $row['id'] ?? 0 );
		$row['location_id'] = (int) ( $row['location_id'] ?? 0 );

		return $row;
	}

	private function exception_not_found(): WP_Error {
		return new WP_Error(
			'plugixa_carparking_not_found',
			__( 'That closure does not exist.', 'plugixa-car-parking' ),
			array( 'status' => 404 )
		);
	}
}
