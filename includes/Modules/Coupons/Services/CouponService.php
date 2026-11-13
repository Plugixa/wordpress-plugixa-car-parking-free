<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Modules\Coupons\Services;

use PlugixaCarParking\Core\Entities\RawInput;
use PlugixaCarParking\Modules\Coupons\Repositories\CouponRepository;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The rules for creating and changing a coupon.
 *
 * Everything that writes a coupon comes through here — the REST controller, a mass action, an
 * importer — so the rules are in one place and none of them can be skipped by taking another route.
 * Whether a code APPLIES to a booking is a different question, answered by the repository.
 */
final class CouponService {

	/** @var string[] */
	public const TYPES = array( 'percent', 'fixed' );

	/** @var string[] */
	public const STATUSES = array( 'active', 'inactive' );

	public function __construct( private readonly CouponRepository $coupons ) {}

	/**
	 * @param int $id Coupon id.
	 *
	 * @return array<string, mixed>|null
	 */
	public function get( int $id ): ?array {
		$row = $this->coupons->find( $id );

		return null === $row ? null : $this->coupons->cast( $row );
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

		$invalid = $this->validate( $data, array() ) ?? $this->refuse_taken_code( $data, 0 );
		if ( $invalid instanceof WP_Error ) {
			return $invalid;
		}

		$id = $this->coupons->insert( $data );

		return $id > 0
			? $id
			: new WP_Error( 'plugixa_carparking_insert_failed', __( 'The coupon could not be saved.', 'plugixa-car-parking' ), array( 'status' => 500 ) );
	}

	/**
	 * @param int                  $id    Coupon id.
	 * @param array<string, mixed> $input Raw, untrusted payload.
	 *
	 * @return bool|WP_Error
	 */
	public function update( int $id, array $input ): bool|WP_Error {
		$existing = $this->coupons->find( $id );

		if ( null === $existing ) {
			return $this->not_found();
		}

		$refused = $this->refuse_unusable( $input );
		if ( $refused instanceof WP_Error ) {
			return $refused;
		}

		$data = $this->sanitize( $input, false );

		$invalid = $this->validate( $data, $existing ) ?? $this->refuse_taken_code( $data, $id );
		if ( $invalid instanceof WP_Error ) {
			return $invalid;
		}

		return $this->coupons->update( $id, $data );
	}

	/**
	 * Trash a coupon, unless something says it must stay.
	 *
	 * This module cannot know what else points at a coupon, and must not: it asks, and whichever
	 * module holds a reference answers with a sentence the user can act on.
	 *
	 * @param int $id Coupon id.
	 *
	 * @return bool|WP_Error
	 */
	public function delete( int $id ): bool|WP_Error {
		$coupon = $this->coupons->find( $id );

		if ( null === $coupon ) {
			return $this->not_found();
		}

		/**
		 * Reasons this coupon must not be trashed.
		 *
		 * @param string[]             $blockers Human-readable sentences. Empty means "go ahead".
		 * @param int                  $id       Coupon id.
		 * @param array<string, mixed> $coupon   The row.
		 */
		$blockers = (array) apply_filters( 'plugixa_carparking_coupon_delete_blockers', array(), $id, $coupon );

		if ( array() !== $blockers ) {
			return new WP_Error(
				'plugixa_carparking_coupon_in_use',
				implode( ' ', array_map( 'strval', $blockers ) ),
				array(
					'status'   => 409,
					'blockers' => array_values( $blockers ),
				)
			);
		}

		return $this->coupons->delete( $id );
	}

	/**
	 * Bring a trashed coupon back.
	 *
	 * Its code is still its own: a trashed coupon keeps holding its code, so nothing can have
	 * taken it in the meantime.
	 *
	 * @param int $id Coupon id.
	 *
	 * @return bool|WP_Error
	 */
	public function restore( int $id ): bool|WP_Error {
		return null === $this->coupons->find( $id, true )
			? $this->not_found()
			: $this->coupons->restore( $id );
	}

	public function not_found(): WP_Error {
		return new WP_Error(
			'plugixa_carparking_not_found',
			__( 'That coupon does not exist.', 'plugixa-car-parking' ),
			array( 'status' => 404 )
		);
	}

	// --- Cleaning and checking -----------------------------------------------

	/**
	 * Refuse a value that cannot be used, before sanitize() would quietly turn it into another.
	 *
	 * A type of "bogof" must come back as an error naming the field, not be stored as a
	 * percentage; an amount of "ten" must not be stored as a coupon worth nothing; a date that is
	 * not on the calendar must not be stored as no limit at all.
	 *
	 * @param array<string, mixed> $input Raw payload.
	 */
	private function refuse_unusable( array $input ): ?WP_Error {
		return RawInput::first(
			RawInput::choice( $input, 'type', self::TYPES, __( 'Discount type', 'plugixa-car-parking' ) ),
			RawInput::choice( $input, 'status', self::STATUSES, __( 'Status', 'plugixa-car-parking' ) ),
			RawInput::number( $input, 'amount', __( 'Amount', 'plugixa-car-parking' ) ),
			RawInput::number( $input, 'min_amount', __( 'Minimum spend', 'plugixa-car-parking' ) ),
			RawInput::number( $input, 'usage_limit', __( 'Usage limit', 'plugixa-car-parking' ) ),
			RawInput::number( $input, 'usage_limit_per_user', __( 'Usage limit per customer', 'plugixa-car-parking' ) ),
			RawInput::date( $input, 'valid_from', __( 'Valid from', 'plugixa-car-parking' ) ),
			RawInput::date( $input, 'valid_to', __( 'Valid until', 'plugixa-car-parking' ) )
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

		if ( $with_defaults || array_key_exists( 'code', $input ) ) {
			$data['code'] = sanitize_text_field( (string) ( $input['code'] ?? '' ) );
		}

		if ( $with_defaults || array_key_exists( 'type', $input ) ) {
			$data['type'] = sanitize_key( (string) ( $input['type'] ?? 'percent' ) );
		}

		if ( $with_defaults || array_key_exists( 'status', $input ) ) {
			$data['status'] = sanitize_key( (string) ( $input['status'] ?? 'active' ) );
		}

		if ( array_key_exists( 'period_type', $input ) && ! self::is_blank( $input['period_type'] ) ) {
			$data['period_type'] = sanitize_text_field( (string) $input['period_type'] );
		}

		// The amount is never NULL: a coupon with no amount takes nothing off.
		if ( array_key_exists( 'amount', $input ) ) {
			$data['amount'] = self::is_blank( $input['amount'] ) ? 0.0 : (float) $input['amount'];
		}

		// An empty limit is "no limit", which is NULL, not a limit of zero.
		if ( array_key_exists( 'min_amount', $input ) ) {
			$data['min_amount'] = self::is_blank( $input['min_amount'] ) ? null : (float) $input['min_amount'];
		}

		foreach ( array( 'usage_limit', 'usage_limit_per_user' ) as $field ) {
			if ( array_key_exists( $field, $input ) ) {
				$data[ $field ] = self::is_blank( $input[ $field ] ) ? null : (int) $input[ $field ];
			}
		}

		// Stored exactly as given: validity is the site's wall-clock time, like a booking's entry
		// and exit, so nothing is converted. An empty date is "no limit on this side".
		foreach ( array( 'valid_from', 'valid_to' ) as $field ) {
			if ( array_key_exists( $field, $input ) ) {
				$data[ $field ] = self::is_blank( $input[ $field ] ) ? null : RawInput::to_mysql( (string) $input[ $field ] );
			}
		}

		if ( array_key_exists( 'applies_to', $input ) && is_array( $input['applies_to'] ) ) {
			$data['applies_to'] = $this->sanitize_scope( $input['applies_to'] );
		}

		return $data;
	}

	/**
	 * What a coupon is limited to: lists of location ids and space type ids.
	 *
	 * An empty or absent list means "any", which is how the check that applies a coupon reads it.
	 *
	 * @param array<mixed> $scope What the request sent.
	 *
	 * @return array<string, int[]>
	 */
	private function sanitize_scope( array $scope ): array {
		$clean = array();

		foreach ( array( 'locations', 'space_types' ) as $key ) {
			if ( isset( $scope[ $key ] ) && is_array( $scope[ $key ] ) ) {
				$clean[ $key ] = array_values( array_unique( array_filter( array_map( 'intval', $scope[ $key ] ) ) ) );
			}
		}

		return $clean;
	}

	/**
	 * Check a coupon as it will stand once saved.
	 *
	 * The rules that compare two fields are checked on the MERGED row: an update that sends only
	 * an end date has to be compared with the start date already stored.
	 *
	 * @param array<string, mixed> $data     Sanitized payload.
	 * @param array<string, mixed> $existing The stored row when updating, empty when creating.
	 */
	private function validate( array $data, array $existing ): ?WP_Error {
		if ( array_key_exists( 'code', $data ) && '' === $data['code'] ) {
			return new WP_Error(
				'plugixa_carparking_missing_code',
				__( 'A coupon needs a code.', 'plugixa-car-parking' ),
				array(
					'status' => 422,
					'field'  => 'code',
				)
			);
		}

		$merged = $data + $existing;

		// More than the whole price off would turn a discount into a negative total.
		$touches_amount = array_key_exists( 'amount', $data ) || array_key_exists( 'type', $data );

		if ( $touches_amount && 'percent' === ( $merged['type'] ?? 'percent' ) && (float) ( $merged['amount'] ?? 0 ) > 100 ) {
			return new WP_Error(
				'plugixa_carparking_invalid_percent',
				__( 'A percentage coupon cannot take off more than 100.', 'plugixa-car-parking' ),
				array(
					'status' => 422,
					'field'  => 'amount',
				)
			);
		}

		$touches_dates = array_key_exists( 'valid_from', $data ) || array_key_exists( 'valid_to', $data );

		if ( $touches_dates && ! empty( $merged['valid_from'] ) && ! empty( $merged['valid_to'] ) && $merged['valid_to'] < $merged['valid_from'] ) {
			return new WP_Error(
				'plugixa_carparking_invalid_range',
				__( 'The coupon must stop being valid on or after the day it starts.', 'plugixa-car-parking' ),
				array(
					'status' => 422,
					'field'  => 'valid_to',
				)
			);
		}

		return $this->coupons->too_long( $data );
	}

	/**
	 * Refuse a code another coupon already holds.
	 *
	 * TRASHED COUPONS COUNT. The code column carries a unique key, and the database cannot tell a
	 * trashed row from a live one, so checking live rows alone would let through a save the
	 * insert then fails on with no field to point at. A code becomes free again when its coupon is
	 * purged.
	 *
	 * @param array<string, mixed> $data       Sanitized payload.
	 * @param int                  $exclude_id The coupon being updated, so it does not collide with itself.
	 */
	private function refuse_taken_code( array $data, int $exclude_id ): ?WP_Error {
		if ( ! array_key_exists( 'code', $data ) ) {
			return null;
		}

		$holder = $this->coupons->find_by( 'code', (string) $data['code'], true );

		if ( null === $holder || (int) $holder['id'] === $exclude_id ) {
			return null;
		}

		return new WP_Error(
			'plugixa_carparking_duplicate_code',
			null === $holder['deleted_at']
				? __( 'Another coupon already uses that code.', 'plugixa-car-parking' )
				: __( 'A coupon in the trash already uses that code. Restore it, or delete it permanently to free the code.', 'plugixa-car-parking' ),
			array(
				'status' => 409,
				'field'  => 'code',
			)
		);
	}

	/**
	 * Whether a value says nothing: absent, or whitespace alone.
	 *
	 * @param mixed $value What the request sent.
	 */
	private static function is_blank( mixed $value ): bool {
		return null === $value || ( is_string( $value ) && '' === trim( $value ) );
	}
}
