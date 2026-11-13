<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Modules\Coupons\Repositories;

use PlugixaCarParking\Core\Database\Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Coupons, and the rules that decide whether one applies.
 */
final class CouponRepository extends Repository {

	public function __construct( private readonly UsageTable $usage ) {}

	protected function table(): string {
		return 'coupons';
	}

	protected function entity_type(): string {
		return 'coupon';
	}

	/**
	 * @return string[]
	 */
	protected function fillable(): array {
		return array(
			'code',
			'type',
			'amount',
			'usage_limit',
			'used_count',
			'usage_limit_per_user',
			'min_amount',
			'valid_from',
			'valid_to',
			'applies_to',
			'period_type',
			'status',
			'created_at',
			'created_by',
		);
	}

	/**
	 * @return string[]
	 */
	protected function json_columns(): array {
		return array( 'applies_to' );
	}

	protected function name_column(): string {
		return 'code';
	}

	/**
	 * @return array<string, string>
	 */
	protected function casts(): array {
		return array(
			'amount'               => 'float',
			'usage_limit'          => 'int',
			'used_count'           => 'int',
			'usage_limit_per_user' => 'int',
			'min_amount'           => 'float',
		);
	}

	/**
	 * @return string[]
	 */
	protected function sortable(): array {
		return array( 'id', 'code', 'type', 'amount', 'used_count', 'status', 'valid_to', 'created_at', 'modified_at' );
	}

	/**
	 * `valid_to` carries no `stored` flag: it is the site's wall-clock time, not UTC, so a day
	 * picked in the filter is compared as written.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function filterable(): array {
		return array(
			'status'   => array(
				'label'   => __( 'Status', 'plugixa-car-parking' ),
				'sql'     => 't.status',
				'type'    => 'select',
				'options' => array( 'active', 'inactive' ),
			),
			'type'     => array(
				'label'   => __( 'Discount type', 'plugixa-car-parking' ),
				'sql'     => 't.type',
				'type'    => 'select',
				'options' => array( 'percent', 'fixed' ),
			),
			'valid_to' => array(
				'label' => __( 'Valid until', 'plugixa-car-parking' ),
				'sql'   => 't.valid_to',
				'type'  => 'date',
			),
		) + $this->common_filters();
	}

	/**
	 * One live coupon by the code a customer types.
	 *
	 * @param string $code The coupon code.
	 *
	 * @return array<string, mixed>|null
	 */
	public function find_by_code( string $code ): ?array {
		return $this->find_by( 'code', $code );
	}

	/**
	 * Whether a code applies to a booking.
	 *
	 * @param string               $code The code as typed.
	 * @param array<string, mixed> $ctx  subtotal, location_id, space_type_id, user_id.
	 *
	 * @return array{valid: bool, coupon: ?array, reason: string}
	 */
	public function validate( string $code, array $ctx ): array {
		$code = trim( $code );
		if ( '' === $code ) {
			return self::refused( 'empty' );
		}

		$coupon = $this->find_by_code( $code );
		if ( null === $coupon || 'active' !== $coupon['status'] ) {
			return self::refused( 'not_found' );
		}

		// Validity dates are the site's wall-clock time; compare them as true instants.
		$now = time();
		if ( ! empty( $coupon['valid_from'] ) && strtotime( get_gmt_from_date( $coupon['valid_from'] ) ) > $now ) {
			return self::refused( 'not_started' );
		}
		if ( ! empty( $coupon['valid_to'] ) && strtotime( get_gmt_from_date( $coupon['valid_to'] ) ) < $now ) {
			return self::refused( 'expired' );
		}
		if ( ! empty( $coupon['usage_limit'] ) && (int) $coupon['used_count'] >= (int) $coupon['usage_limit'] ) {
			return self::refused( 'usage_limit' );
		}

		/*
		 * Per-account limit. Counted from the redemption log rather than the coupon's own
		 * used_count, which is a site-wide tally. A guest booking is logged with no user and
		 * cannot be attributed to anyone, so this can only bind a signed-in customer — the
		 * site-wide usage_limit above remains the only cap that applies to everyone.
		 */
		if ( ! empty( $coupon['usage_limit_per_user'] ) ) {
			$user_id = (int) ( $ctx['user_id'] ?? get_current_user_id() );

			if ( $user_id > 0 && $this->usage->count_for_user( (int) $coupon['id'], $user_id ) >= (int) $coupon['usage_limit_per_user'] ) {
				return self::refused( 'usage_limit_per_user' );
			}
		}

		if ( ! empty( $coupon['min_amount'] ) && (float) ( $ctx['subtotal'] ?? 0 ) < (float) $coupon['min_amount'] ) {
			return self::refused( 'min_amount' );
		}

		// Scope: applies_to is { locations: [], space_types: [] }; an empty list means "any".
		$scope = $coupon['applies_to'] ?? null;
		if ( is_array( $scope ) ) {
			if ( ! empty( $scope['locations'] ) && ! in_array( (int) ( $ctx['location_id'] ?? 0 ), array_map( 'intval', $scope['locations'] ), true ) ) {
				return self::refused( 'location' );
			}
			if ( ! empty( $scope['space_types'] ) && ! in_array( (int) ( $ctx['space_type_id'] ?? 0 ), array_map( 'intval', $scope['space_types'] ), true ) ) {
				return self::refused( 'space_type' );
			}
		}

		return array(
			'valid'  => true,
			'coupon' => $coupon,
			'reason' => 'ok',
		);
	}

	/**
	 * The discount a coupon takes off an amount.
	 *
	 * @param array<string, mixed> $coupon The coupon row.
	 * @param float                $amount The amount being discounted.
	 */
	public function compute_discount( array $coupon, float $amount ): float {
		if ( 'percent' === ( $coupon['type'] ?? 'percent' ) ) {
			return round( $amount * (float) $coupon['amount'] / 100, 2 );
		}

		// A fixed coupon never discounts more than there is to pay.
		return min( $amount, round( (float) $coupon['amount'], 2 ) );
	}

	/**
	 * Count one more redemption.
	 *
	 * A single UPDATE that adds one, rather than read-then-write, so two bookings redeeming the
	 * same coupon at once both count.
	 *
	 * @param int $coupon_id The coupon.
	 */
	public function increment_usage( int $coupon_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- an atomic counter on the plugin's own table.
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET used_count = used_count + 1 WHERE id = %d', $this->table_name(), $coupon_id ) );
	}

	/**
	 * @param string $reason Why the code does not apply.
	 *
	 * @return array{valid: bool, coupon: null, reason: string}
	 */
	private static function refused( string $reason ): array {
		return array(
			'valid'  => false,
			'coupon' => null,
			'reason' => $reason,
		);
	}
}
