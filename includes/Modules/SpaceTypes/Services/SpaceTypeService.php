<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Modules\SpaceTypes\Services;

use PlugixaCarParking\Core\Entities\RawInput;
use PlugixaCarParking\Modules\Bookings\Services\Pricing;
use PlugixaCarParking\Modules\Locations\Repositories\LocationRepository;
use PlugixaCarParking\Modules\SpaceTypes\Repositories\PriceTable;
use PlugixaCarParking\Modules\SpaceTypes\Repositories\SpaceTypeRepository;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The rules for creating and changing a space type and its base prices.
 *
 * Everything that writes a space type comes through here — the REST controller, a mass action, an
 * importer — so the rules are in one place and none of them can be skipped by taking another route.
 */
final class SpaceTypeService {

	/** @var string[] */
	public const STATUSES = array( 'active', 'inactive' );

	/**
	 * The largest width or length the column holds: DECIMAL(8,2) leaves six integer digits.
	 */
	private const MAX_DIMENSION = 999999.99;

	/**
	 * Plain single-line text columns, cleaned the same way.
	 *
	 * @var string[]
	 */
	private const TEXT_FIELDS = array( 'name', 'icon', 'color', 'dimension_unit' );

	public function __construct(
		private readonly SpaceTypeRepository $types,
		private readonly PriceTable $prices,
		private readonly LocationRepository $locations
	) {}

	/**
	 * @param int $id Space type id.
	 *
	 * @return array<string, mixed>|null
	 */
	public function get( int $id ): ?array {
		$row = $this->types->find( $id );

		return null === $row ? null : $this->types->cast( $row );
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

		$invalid = $this->validate( $data );
		if ( $invalid instanceof WP_Error ) {
			return $invalid;
		}

		$data['slug'] = $this->types->unique_slug( '' !== (string) ( $data['slug'] ?? '' ) ? $data['slug'] : $data['name'] );

		$id = $this->types->insert( $data );

		return $id > 0
			? $id
			: new WP_Error( 'plugixa_carparking_insert_failed', __( 'The space type could not be saved.', 'plugixa-car-parking' ), array( 'status' => 500 ) );
	}

	/**
	 * @param int                  $id    Space type id.
	 * @param array<string, mixed> $input Raw, untrusted payload.
	 *
	 * @return bool|WP_Error
	 */
	public function update( int $id, array $input ): bool|WP_Error {
		if ( null === $this->types->find( $id ) ) {
			return $this->not_found();
		}

		$refused = $this->refuse_unusable( $input );
		if ( $refused instanceof WP_Error ) {
			return $refused;
		}

		$data = $this->sanitize( $input, false );

		$invalid = $this->validate( $data );
		if ( $invalid instanceof WP_Error ) {
			return $invalid;
		}

		// Only when a slug was sent: a save that does not mention it must not rename the type out
		// from under anything that refers to it by slug.
		if ( array_key_exists( 'slug', $data ) ) {
			$data['slug'] = $this->types->unique_slug( $data['slug'], $id );
		}

		return $this->types->update( $id, $data );
	}

	/**
	 * Trash a space type, unless something says it must stay.
	 *
	 * This module cannot know what else points at a space type, and must not: it asks, and
	 * whichever module holds a reference answers with a sentence the user can act on.
	 *
	 * @param int $id Space type id.
	 *
	 * @return bool|WP_Error
	 */
	public function delete( int $id ): bool|WP_Error {
		$type = $this->types->find( $id );

		if ( null === $type ) {
			return $this->not_found();
		}

		/**
		 * Reasons this space type must not be trashed.
		 *
		 * @param string[]             $blockers Human-readable sentences. Empty means "go ahead".
		 * @param int                  $id       Space type id.
		 * @param array<string, mixed> $type     The row.
		 */
		$blockers = (array) apply_filters( 'plugixa_carparking_space_type_delete_blockers', array(), $id, $type );

		if ( array() !== $blockers ) {
			return new WP_Error(
				'plugixa_carparking_space_type_in_use',
				implode( ' ', array_map( 'strval', $blockers ) ),
				array(
					'status'   => 409,
					'blockers' => array_values( $blockers ),
				)
			);
		}

		return $this->types->delete( $id );
	}

	/**
	 * Bring a trashed space type back.
	 *
	 * Its slug is still its own: a trashed row keeps holding its slug, so nothing can have taken
	 * it in the meantime.
	 *
	 * @param int $id Space type id.
	 *
	 * @return bool|WP_Error
	 */
	public function restore( int $id ): bool|WP_Error {
		return null === $this->types->find( $id, true )
			? $this->not_found()
			: $this->types->restore( $id );
	}

	public function not_found(): WP_Error {
		return new WP_Error(
			'plugixa_carparking_not_found',
			__( 'That space type does not exist.', 'plugixa-car-parking' ),
			array( 'status' => 404 )
		);
	}

	// --- Base prices ---------------------------------------------------------

	/**
	 * The units a price may be set for: the one-off fee, then one per billing unit.
	 *
	 * Read from the pricing engine's own map, so a price can only be saved under a name the
	 * engine will look for.
	 *
	 * @return string[]
	 */
	public static function use_types(): array {
		return array_merge( array( 'initial' ), array_values( Pricing::USE_TYPE_MAP ) );
	}

	/**
	 * A space type's base prices, oldest first.
	 *
	 * @param int $space_type_id Space type id.
	 *
	 * @return array<int, array{use_type: string, amount: float, location_id: int|null, currency: string|null}>
	 */
	public function prices( int $space_type_id ): array {
		$rows = array();

		foreach ( $this->prices->for_space_type( $space_type_id ) as $row ) {
			// A location of NULL is the price everywhere and a currency of NULL is the site's own;
			// both stay NULL so the client can tell "not set" from a value.
			$rows[] = array(
				'use_type'    => (string) $row['use_type'],
				'amount'      => (float) $row['amount'],
				'location_id' => null === $row['location_id'] ? null : (int) $row['location_id'],
				'currency'    => null === $row['currency'] || '' === $row['currency'] ? null : (string) $row['currency'],
			);
		}

		return $rows;
	}

	/**
	 * Replace a space type's base prices with the set that was sent.
	 *
	 * The whole payload is checked before anything is written, and the write is one transaction:
	 * a list with one bad line changes nothing, so a type is never left with half its prices.
	 *
	 * @param int   $space_type_id Space type id.
	 * @param mixed $input         The `prices` the request sent: a list of price rows.
	 *
	 * @return true|WP_Error
	 */
	public function save_prices( int $space_type_id, mixed $input ): true|WP_Error {
		if ( null === $this->types->find( $space_type_id ) ) {
			return $this->not_found();
		}

		if ( ! is_array( $input ) ) {
			return new WP_Error( 'plugixa_carparking_invalid_prices', __( 'Prices must be a list of price rows.', 'plugixa-car-parking' ), array( 'status' => 422 ) );
		}

		$rows = array();
		$seen = array();

		foreach ( array_values( $input ) as $index => $raw ) {
			$row = is_array( $raw )
				? $this->sanitize_price( $raw )
				: new WP_Error( 'plugixa_carparking_invalid_prices', __( 'Each price must be a row with a unit and an amount.', 'plugixa-car-parking' ), array( 'status' => 422 ) );

			if ( ! $row instanceof WP_Error ) {
				// Pricing reads ONE rate per location and unit. Two rows for the same pair would
				// leave which one applies to the order they happened to be written in.
				$key = ( $row['location_id'] ?? 0 ) . ':' . $row['use_type'];

				if ( isset( $seen[ $key ] ) ) {
					$row = new WP_Error(
						'plugixa_carparking_duplicate_price',
						__( 'There is already a price for this unit at this location. Each unit can have one price per location.', 'plugixa-car-parking' ),
						array(
							'status' => 422,
							'field'  => 'use_type',
						)
					);
				}

				$seen[ $key ] = true;
			}

			if ( $row instanceof WP_Error ) {
				return RawInput::for_line( $row, is_array( $raw ) ? $raw : array(), $index );
			}

			$rows[] = $row;
		}

		return $this->prices->replace( $space_type_id, $rows )
			? true
			: new WP_Error( 'plugixa_carparking_insert_failed', __( 'The prices could not be saved.', 'plugixa-car-parking' ), array( 'status' => 500 ) );
	}

	/**
	 * One price row, cleaned, or the reason it cannot be stored.
	 *
	 * @param array<string, mixed> $raw The row as sent.
	 *
	 * @return array{use_type: string, amount: float, location_id: int|null, currency: string|null}|WP_Error
	 */
	private function sanitize_price( array $raw ): array|WP_Error {
		$refused = RawInput::first(
			RawInput::choice( $raw, 'use_type', self::use_types(), __( 'Unit', 'plugixa-car-parking' ) ),
			RawInput::number( $raw, 'amount', __( 'Amount', 'plugixa-car-parking' ) )
		);
		if ( $refused instanceof WP_Error ) {
			return $refused;
		}

		// The two checks above pass a value that was not sent, because elsewhere a default applies.
		// A price has no default: a row with no unit prices nothing, and a row with no amount
		// would be stored as free.
		foreach ( array( 'use_type', 'amount' ) as $field ) {
			if ( ! isset( $raw[ $field ] ) || '' === trim( (string) $raw[ $field ] ) ) {
				return new WP_Error(
					'plugixa_carparking_missing_price_field',
					__( 'A price needs a unit and an amount.', 'plugixa-car-parking' ),
					array(
						'status' => 422,
						'field'  => $field,
					)
				);
			}
		}

		$location_id = $this->price_location( $raw['location_id'] ?? null );
		if ( $location_id instanceof WP_Error ) {
			return $location_id;
		}

		$currency = is_scalar( $raw['currency'] ?? null ) ? sanitize_text_field( (string) $raw['currency'] ) : '';

		if ( strlen( $currency ) > 10 ) {
			return new WP_Error(
				'plugixa_carparking_too_long',
				__( 'A currency code is at most 10 characters.', 'plugixa-car-parking' ),
				array(
					'status' => 422,
					'field'  => 'currency',
					'max'    => 10,
				)
			);
		}

		return array(
			'use_type'    => sanitize_key( (string) $raw['use_type'] ),
			'amount'      => (float) $raw['amount'],
			'location_id' => $location_id,
			'currency'    => '' === $currency ? null : $currency,
		);
	}

	/**
	 * The location a price is for: null for the price everywhere, or the id of a real location.
	 *
	 * A trashed location still counts. Its prices are kept while it sits in the trash so that
	 * restoring it brings them back, and the editor must be able to save a list that contains them.
	 *
	 * @param mixed $value What the row sent.
	 *
	 * @return int|WP_Error|null
	 */
	private function price_location( mixed $value ): int|WP_Error|null {
		if ( null === $value || '' === $value || 0 === $value || '0' === $value ) {
			return null;
		}

		$is_id = is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) );

		if ( $is_id && (int) $value > 0 && null !== $this->locations->find( (int) $value, true ) ) {
			return (int) $value;
		}

		return new WP_Error(
			'plugixa_carparking_invalid_location',
			__( 'That location does not exist. Leave the location empty for a price that applies everywhere.', 'plugixa-car-parking' ),
			array(
				'status' => 422,
				'field'  => 'location_id',
			)
		);
	}

	// --- Cleaning and checking -----------------------------------------------

	/**
	 * Refuse a value that cannot be used, before sanitize() would quietly turn it into another.
	 *
	 * A status of "archived" must come back as an error naming the field, not be stored as
	 * "active" with a success response; a width of "wide" must not be stored as zero.
	 *
	 * @param array<string, mixed> $input Raw payload.
	 */
	private function refuse_unusable( array $input ): ?WP_Error {
		return RawInput::first(
			RawInput::choice( $input, 'status', self::STATUSES, __( 'Status', 'plugixa-car-parking' ) ),
			RawInput::number( $input, 'width', __( 'Width', 'plugixa-car-parking' ) ),
			RawInput::number( $input, 'length', __( 'Length', 'plugixa-car-parking' ) )
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

		if ( $with_defaults || array_key_exists( 'status', $input ) ) {
			$data['status'] = sanitize_key( (string) ( $input['status'] ?? 'active' ) );
		}

		// An empty dimension is "not measured", which is NULL, not a space zero metres wide.
		foreach ( array( 'width', 'length' ) as $field ) {
			if ( array_key_exists( $field, $input ) ) {
				$data[ $field ] = ( null === $input[ $field ] || '' === trim( (string) $input[ $field ] ) ) ? null : (float) $input[ $field ];
			}
		}

		if ( array_key_exists( 'display_order', $input ) ) {
			$data['display_order'] = (int) $input['display_order'];
		}

		if ( array_key_exists( 'features', $input ) && is_array( $input['features'] ) ) {
			$data['features'] = $this->sanitize_features( $input['features'] );
		}

		return $data;
	}

	/**
	 * A space type's features: a flat map of scalars under clean keys, e.g. `covered => true`.
	 *
	 * What a feature MEANS belongs to whichever screen shows it; this only makes sure the blob is
	 * a map of plain values and not a place to park arbitrary structures.
	 *
	 * @param array<mixed> $features What the request sent.
	 *
	 * @return array<string, bool|int|float|string>
	 */
	private function sanitize_features( array $features ): array {
		$clean = array();

		foreach ( $features as $key => $value ) {
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
				__( 'A space type needs a name.', 'plugixa-car-parking' ),
				array(
					'status' => 422,
					'field'  => 'name',
				)
			);
		}

		foreach ( array( 'width', 'length' ) as $field ) {
			if ( isset( $data[ $field ] ) && $data[ $field ] > self::MAX_DIMENSION ) {
				return new WP_Error(
					'plugixa_carparking_number_too_large',
					__( 'That dimension is too large.', 'plugixa-car-parking' ),
					array(
						'status' => 422,
						'field'  => $field,
					)
				);
			}
		}

		return $this->types->too_long( $data );
	}
}
