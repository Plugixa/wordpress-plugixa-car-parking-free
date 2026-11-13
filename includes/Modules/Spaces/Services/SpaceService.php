<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Modules\Spaces\Services;

use PlugixaCarParking\Core\Entities\RawInput;
use PlugixaCarParking\Modules\Bookings\Services\ModeRegistry;
use PlugixaCarParking\Modules\Locations\Repositories\LocationRepository;
use PlugixaCarParking\Modules\Spaces\Repositories\SpaceRepository;
use PlugixaCarParking\Modules\SpaceTypes\Repositories\SpaceTypeRepository;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The rules for creating and changing a location's inventory rows.
 *
 * Everything that writes a space comes through here — the REST controller, a mass action, an
 * importer — so the rules are in one place and none of them can be skipped by taking another route.
 */
final class SpaceService {

	/** @var string[] */
	public const STATUSES = array( 'active', 'inactive' );

	/**
	 * The most identified spaces one request adds.
	 */
	public const MAX_NUMBERED = 500;

	public function __construct(
		private readonly SpaceRepository $spaces,
		private readonly LocationRepository $locations,
		private readonly SpaceTypeRepository $types,
		private readonly ModeRegistry $modes
	) {}

	/**
	 * @param int $id Space id.
	 *
	 * @return array<string, mixed>|null
	 */
	public function get( int $id ): ?array {
		$row = $this->spaces->find( $id );

		return null === $row ? null : $this->spaces->cast( $row );
	}

	/**
	 * @param array<string, mixed> $input Raw, untrusted payload.
	 *
	 * @return int|WP_Error
	 */
	public function create( array $input ): int|WP_Error {
		$refused = $this->refuse_unusable( $input );
		if ( $refused instanceof WP_Error ) {
			return $refused;
		}

		$data = $this->sanitize( $input, true );

		$invalid = $this->validate( $data, array() );
		if ( $invalid instanceof WP_Error ) {
			return $invalid;
		}

		$id = $this->spaces->insert( $data );

		return $id > 0
			? $id
			: new WP_Error( 'plugixa_carparking_insert_failed', __( 'The space could not be saved.', 'plugixa-car-parking' ), array( 'status' => 500 ) );
	}

	/**
	 * Add a run of identified spaces at once: prefix "A", 1 to 20 makes A1 … A20.
	 *
	 * Each is its own row of one space, held to bookings one at a time. All are checked before any
	 * is written, so a run that cannot be added adds nothing.
	 *
	 * @param array<string, mixed> $input location_id, space_type_id, prefix, from, to.
	 *
	 * @return int[]|WP_Error The new row ids.
	 */
	public function create_numbered( array $input ): array|WP_Error {
		$from   = (int) ( $input['from'] ?? 1 );
		$to     = (int) ( $input['to'] ?? 0 );
		$prefix = sanitize_text_field( (string) ( $input['prefix'] ?? '' ) );

		if ( $from < 0 || $to < $from || $to - $from >= self::MAX_NUMBERED ) {
			return $this->invalid(
				'to',
				/* translators: %d: the most spaces that can be added at once. */
				sprintf( __( 'Give a range of up to %d numbers, the second no smaller than the first.', 'plugixa-car-parking' ), self::MAX_NUMBERED )
			);
		}

		$rows = array();

		for ( $number = $from; $number <= $to; $number++ ) {
			$data = $this->sanitize(
				array(
					'location_id'   => $input['location_id'] ?? 0,
					'space_type_id' => $input['space_type_id'] ?? 0,
					'quantity'      => 1,
					'label'         => $prefix . $number,
					'is_assigned'   => true,
					'display_order' => $number,
				),
				true
			);

			$invalid = $this->validate( $data, array() );
			if ( $invalid instanceof WP_Error ) {
				return $invalid;
			}

			$rows[] = $data;
		}

		return array_values( array_filter( array_map( fn( array $data ): int => $this->spaces->insert( $data ), $rows ) ) );
	}

	/**
	 * @param int                  $id    Space id.
	 * @param array<string, mixed> $input Raw, untrusted payload.
	 *
	 * @return bool|WP_Error
	 */
	public function update( int $id, array $input ): bool|WP_Error {
		$existing = $this->spaces->find( $id );

		if ( null === $existing ) {
			return $this->not_found();
		}

		$refused = $this->refuse_unusable( $input );
		if ( $refused instanceof WP_Error ) {
			return $refused;
		}

		$data = $this->sanitize( $input, false );

		$invalid = $this->validate( $data, $existing );
		if ( $invalid instanceof WP_Error ) {
			return $invalid;
		}

		return $this->spaces->update( $id, $data );
	}

	/**
	 * Trash a space, unless something says it must stay.
	 *
	 * This module cannot know what else points at a space, and must not: it asks, and whichever
	 * module holds a reference answers with a sentence the user can act on.
	 *
	 * @param int $id Space id.
	 *
	 * @return bool|WP_Error
	 */
	public function delete( int $id ): bool|WP_Error {
		$space = $this->spaces->find( $id );

		if ( null === $space ) {
			return $this->not_found();
		}

		/**
		 * Reasons this space must not be trashed.
		 *
		 * @param string[]             $blockers Human-readable sentences. Empty means "go ahead".
		 * @param int                  $id       Space id.
		 * @param array<string, mixed> $space    The row.
		 */
		$blockers = (array) apply_filters( 'plugixa_carparking_location_space_delete_blockers', array(), $id, $space );

		if ( array() !== $blockers ) {
			return new WP_Error(
				'plugixa_carparking_location_space_in_use',
				implode( ' ', array_map( 'strval', $blockers ) ),
				array(
					'status'   => 409,
					'blockers' => array_values( $blockers ),
				)
			);
		}

		return $this->spaces->delete( $id );
	}

	/**
	 * Bring a trashed space back.
	 *
	 * @param int $id Space id.
	 *
	 * @return bool|WP_Error
	 */
	public function restore( int $id ): bool|WP_Error {
		return null === $this->spaces->find( $id, true )
			? $this->not_found()
			: $this->spaces->restore( $id );
	}

	public function not_found(): WP_Error {
		return new WP_Error(
			'plugixa_carparking_not_found',
			__( 'That space does not exist.', 'plugixa-car-parking' ),
			array( 'status' => 404 )
		);
	}

	// --- Cleaning and checking -----------------------------------------------

	/**
	 * Refuse a value that cannot be used, before sanitize() would quietly turn it into another.
	 *
	 * A status of "archived" must come back as an error naming the field, not be stored as
	 * "active" with a success response; a quantity of "many" must not be stored as zero spaces.
	 *
	 * @param array<string, mixed> $input Raw payload.
	 */
	private function refuse_unusable( array $input ): ?WP_Error {
		return RawInput::first(
			RawInput::choice( $input, 'status', self::STATUSES, __( 'Status', 'plugixa-car-parking' ) ),
			RawInput::number( $input, 'quantity', __( 'Quantity', 'plugixa-car-parking' ) )
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

		foreach ( array( 'location_id', 'space_type_id', 'quantity', 'display_order' ) as $field ) {
			if ( array_key_exists( $field, $input ) ) {
				$data[ $field ] = max( 0, (int) $input[ $field ] );
			}
		}

		// A pool with no quantity given holds one space, the smallest thing a row can describe.
		if ( $with_defaults ) {
			$data['quantity'] = $data['quantity'] ?? 1;
		}

		// No label is NULL: a pool of interchangeable spaces has no name of its own.
		if ( array_key_exists( 'label', $input ) ) {
			$data['label'] = null === $input['label'] ? null : sanitize_text_field( (string) $input['label'] );
		}

		if ( array_key_exists( 'is_assigned', $input ) ) {
			$data['is_assigned'] = true === rest_sanitize_boolean( $input['is_assigned'] ) ? 1 : 0;
		}

		if ( $with_defaults || array_key_exists( 'status', $input ) ) {
			$data['status'] = sanitize_key( (string) ( $input['status'] ?? 'active' ) );
		}

		if ( array_key_exists( 'meta', $input ) && is_array( $input['meta'] ) ) {
			$data['meta'] = $this->sanitize_meta( $input['meta'] );
		}

		return $data;
	}

	/**
	 * A space's own details: a flat map of scalars under clean keys.
	 *
	 * What the keys MEAN belongs to whichever module reads them; this only makes sure the blob is
	 * a map of plain values and not a place to park arbitrary structures.
	 *
	 * @param array<mixed> $meta What the request sent.
	 *
	 * @return array<string, bool|int|float|string>
	 */
	private function sanitize_meta( array $meta ): array {
		$clean = array();

		foreach ( $meta as $key => $value ) {
			$key = sanitize_key( (string) $key );

			if ( '' === $key || ! is_scalar( $value ) ) {
				continue;
			}

			$clean[ $key ] = is_string( $value ) ? sanitize_text_field( $value ) : $value;
		}

		return $clean;
	}

	/**
	 * Check a space as it will stand once saved.
	 *
	 * "Has a location and a space type" is asked of the MERGED row: an update that sends only a
	 * label has both already. "Does that location exist" is asked only of an id this request
	 * sent, so a row whose parent has since been trashed can still have its own fields edited.
	 *
	 * @param array<string, mixed> $data     Sanitized payload.
	 * @param array<string, mixed> $existing The stored row when updating, empty when creating.
	 */
	private function validate( array $data, array $existing ): ?WP_Error {
		$merged = $data + $existing;

		if ( (int) ( $merged['location_id'] ?? 0 ) <= 0 ) {
			return $this->invalid( 'location_id', __( 'A space needs a location.', 'plugixa-car-parking' ) );
		}

		if ( (int) ( $merged['space_type_id'] ?? 0 ) <= 0 ) {
			return $this->invalid( 'space_type_id', __( 'A space needs a space type.', 'plugixa-car-parking' ) );
		}

		if ( isset( $data['location_id'] ) && null === $this->locations->find( $data['location_id'] ) ) {
			return $this->invalid( 'location_id', __( 'That location does not exist.', 'plugixa-car-parking' ) );
		}

		if ( isset( $data['space_type_id'] ) && null === $this->types->find( $data['space_type_id'] ) ) {
			return $this->invalid( 'space_type_id', __( 'That space type does not exist.', 'plugixa-car-parking' ) );
		}

		// Individually identified spaces need their location to count that way. Under any other
		// mode the row is counted as part of a pool, and nothing holds a booking to that one space.
		$location = ! empty( $data['is_assigned'] ) ? $this->locations->find( (int) $merged['location_id'] ) : null;

		if ( ! empty( $data['is_assigned'] ) && ( null === $location || ! $this->modes->strategy( (string) $location['inventory_mode'] )->identifies_spaces() ) ) {
			return new WP_Error(
				'plugixa_carparking_mode_unsupported',
				__( 'Individually identified spaces can only be added to a location that counts each space on its own.', 'plugixa-car-parking' ),
				array(
					'status' => 400,
					'field'  => 'is_assigned',
				)
			);
		}

		return $this->spaces->too_long( $data );
	}

	/**
	 * @param string $field   The field the refusal is about.
	 * @param string $message What to tell the user.
	 */
	private function invalid( string $field, string $message ): WP_Error {
		return new WP_Error(
			'plugixa_carparking_invalid_' . $field,
			$message,
			array(
				'status' => 422,
				'field'  => $field,
			)
		);
	}
}
