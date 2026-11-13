<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Modules\Payments\Repositories;

use PlugixaCarParking\Core\Database\Repository;
use PlugixaCarParking\Core\Database\SlugSet;
use PlugixaCarParking\Modules\Payments\Services\PaymentAdminService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The payments ledger.
 *
 * Rows are added and never changed: a refund is a new row, not an edit. What a booking has been
 * paid is therefore always a sum over its rows.
 */
final class PaymentRepository extends Repository {

	protected function table(): string {
		return 'payments';
	}

	protected function entity_type(): string {
		return 'payment';
	}

	/**
	 * @return string[]
	 */
	protected function fillable(): array {
		return array(
			'booking_id',
			'gateway',
			'transaction_id',
			'type',
			'amount',
			'currency',
			'status',
			'response',
			'created_at',
			'created_by',
		);
	}

	/**
	 * @return string[]
	 */
	protected function json_columns(): array {
		return array( 'response' );
	}

	protected function name_column(): string {
		return 'transaction_id';
	}

	/**
	 * @return array<string, string>
	 */
	protected function casts(): array {
		return array(
			'booking_id' => 'int',
			'amount'     => 'float',
		);
	}

	/**
	 * @return string[]
	 */
	protected function sortable(): array {
		return array( 'id', 'booking_id', 'gateway', 'type', 'amount', 'status', 'created_at' );
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public function filterable(): array {
		return array(
			'booking_id' => array(
				'label'  => __( 'Booking', 'plugixa-car-parking' ),
				'sql'    => 't.booking_id',
				'type'   => 'entity',
				'entity' => 'booking',
			),
			'gateway'    => array(
				'label'   => __( 'Gateway', 'plugixa-car-parking' ),
				'sql'     => 't.gateway',
				'type'    => 'select',
				'options' => $this->gateways(),
			),
			'type'       => array(
				'label'   => __( 'Type', 'plugixa-car-parking' ),
				'sql'     => 't.type',
				'type'    => 'select',
				'options' => array_merge( PaymentAdminService::types(), array( 'refund' ) ),
			),
			'status'     => array(
				'label'   => __( 'Status', 'plugixa-car-parking' ),
				'sql'     => 't.status',
				'type'    => 'select',
				'options' => array( 'completed', 'pending', 'failed' ),
			),
			'amount'     => array(
				'label' => __( 'Amount', 'plugixa-car-parking' ),
				'sql'   => 't.amount',
				'type'  => 'number',
			),
		) + $this->common_filters();
	}

	/**
	 * Every gateway the ledger has a row for.
	 *
	 * A row keeps the id of the gateway that took the money whether or not that gateway is still
	 * installed, so this list can hold ids the gateway registry no longer knows.
	 *
	 * @return string[]
	 */
	public function gateways(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT gateway FROM %i WHERE deleted_at IS NULL ORDER BY gateway ASC', $this->table_name() ) );

		return array_values( array_filter( array_map( 'strval', (array) $ids ) ) );
	}

	/**
	 * Whether money has been recorded against a booking: any completed row, a refund included.
	 *
	 * @param int $booking_id The booking.
	 */
	public function has_completed( int $booking_id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- decides whether a booking may be destroyed; must include the payment recorded a moment ago.
		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM %i WHERE booking_id = %d AND status = 'completed' AND deleted_at IS NULL LIMIT 1",
				$this->table_name(),
				$booking_id
			)
		);

		return null !== $id;
	}

	/**
	 * Remove what is left of a booking's ledger once the booking itself has been destroyed.
	 *
	 * A booking with completed money against it cannot be destroyed at all, so the only rows this
	 * can ever reach are attempts that never completed. Left behind, they would be payments for a
	 * booking that does not exist.
	 *
	 * @param int $booking_id The destroyed booking.
	 */
	public function delete_for_booking( int $booking_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to invalidate.
		$wpdb->delete( $this->table_name(), array( 'booking_id' => $booking_id ) );
	}

	/**
	 * Every ledger row of a booking, oldest first.
	 *
	 * @param int $booking_id The booking.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function for_booking( int $booking_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a ledger read must include the payment recorded a moment ago.
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i WHERE booking_id = %d AND deleted_at IS NULL ORDER BY id ASC', $this->table_name(), $booking_id ),
			ARRAY_A
		);

		return array_map( array( $this, 'decode' ), (array) $rows );
	}

	/**
	 * What a booking has been paid: completed money in, less completed refunds.
	 *
	 * @param int $booking_id The booking.
	 */
	public function total_paid( int $booking_id ): float {
		global $wpdb;

		$types = array_merge( PaymentAdminService::types(), array( 'refund' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a ledger read must include the payment recorded a moment ago.
		return (float) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(CASE WHEN type = 'refund' THEN -amount ELSE amount END), 0)
				   FROM %i
				  WHERE booking_id = %d AND status = 'completed' AND deleted_at IS NULL
				    AND FIND_IN_SET( type, %s )",
				$this->table_name(),
				$booking_id,
				SlugSet::csv( $types )
			)
		);
	}

	/**
	 * The ledger row a gateway transaction was recorded as, if it has been.
	 *
	 * @param string $gateway        Gateway id.
	 * @param string $transaction_id The gateway's own reference for the payment.
	 * @param string $status         The row's status: one reference can carry a failure and a success.
	 *
	 * @return array<string, mixed>|null
	 */
	public function find_by_transaction( string $gateway, string $transaction_id, string $status ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the idempotency check; a cached miss would record the same payment twice.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE gateway = %s AND transaction_id = %s AND status = %s AND deleted_at IS NULL LIMIT 1',
				$this->table_name(),
				$gateway,
				$transaction_id,
				$status
			),
			ARRAY_A
		);

		return is_array( $row ) ? $this->decode( $row ) : null;
	}
}
