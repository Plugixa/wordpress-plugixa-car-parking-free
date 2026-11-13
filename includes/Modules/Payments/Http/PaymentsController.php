<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Modules\Payments\Http;

use PlugixaCarParking\Core\Database\QueryBuilder;
use PlugixaCarParking\Core\Http\RestController;
use PlugixaCarParking\Modules\Payments\Repositories\PaymentRepository;
use PlugixaCarParking\Modules\Payments\Services\GatewayRegistry;
use PlugixaCarParking\Modules\Payments\Services\PaymentAdminService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The payments ledger over REST: read it, record a payment taken by hand, record a refund.
 *
 * NOT THE SEVEN RECORD ROUTES. The ledger is only ever added to, so there is no route that
 * changes a row, trashes one, restores one or destroys one, and this controller registers none
 * that would have to answer "not allowed".
 */
final class PaymentsController extends RestController {

	public function __construct(
		private readonly PaymentAdminService $service,
		private readonly PaymentRepository $payments,
		private readonly GatewayRegistry $gateways
	) {}

	protected function entity_type(): string {
		return 'payment';
	}

	/**
	 * @param WP_REST_Request $request The request.
	 *
	 * @return array<string, mixed>|null
	 */
	protected function record_for( WP_REST_Request $request ): ?array {
		$id = (int) ( $request['id'] ?? 0 );

		return $id > 0 ? $this->payments->find( $id ) : null;
	}

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/payments',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'index' ),
					'permission_callback' => array( $this, 'can_read_ledger' ),
					'args'                => $this->get_collection_params(),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'store' ),
					'permission_callback' => array( $this, 'can_create' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/payments/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'show' ),
					'permission_callback' => array( $this, 'can_read_ledger' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/payments/(?P<id>\d+)/refund',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'refund' ),
					'permission_callback' => array( $this, 'can_edit' ),
				),
			)
		);
	}

	/**
	 * A payment row names its booking, so reading the ledger is reading bookings too.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function can_read_ledger( WP_REST_Request $request ): bool|WP_Error {
		unset( $request );

		return $this->allowed_on( 'read', 'payment', 'booking' );
	}

	/**
	 * The ledger, newest first. `?booking_id=` narrows it to one booking's rows.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function index( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->paged_list(
			$request,
			$this->payments,
			array( 't.transaction_id' ),
			static function ( QueryBuilder $query, WP_REST_Request $request ): ?WP_Error {
				$booking_id = (int) $request->get_param( 'booking_id' );

				if ( $booking_id > 0 ) {
					$query->where( 't.booking_id = %d', array( $booking_id ) );
				}

				return null;
			},
			null,
			fn( array $rows ): array => $this->decorate( $rows )
		);
	}

	/**
	 * @param WP_REST_Request $request The request.
	 */
	public function show( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->one( (int) $request['id'] );
	}

	/**
	 * @param WP_REST_Request $request The request.
	 */
	public function store( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id = $this->service->record( (array) $request->get_json_params() );

		return $id instanceof WP_Error ? $id : $this->one( $id, 201 );
	}

	/**
	 * Answers with the refund row, not the payment it was made against.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function refund( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id = $this->service->refund( (int) $request['id'], (array) $request->get_json_params() );

		return $id instanceof WP_Error ? $id : $this->one( $id, 201 );
	}

	/**
	 * One ledger row as a response, carrying the same keys a list row does.
	 *
	 * @param int $id     Payment id.
	 * @param int $status HTTP status.
	 */
	private function one( int $id, int $status = 200 ): WP_REST_Response|WP_Error {
		$row = $this->service->get( $id );

		if ( null === $row ) {
			return $this->service->not_found();
		}

		return $this->ok( $this->decorate( array( $row ) )[0], array(), $status );
	}

	/**
	 * What a row carries beyond its columns: its booking's reference and its gateway's name.
	 *
	 * @param array<int, array<string, mixed>> $rows Ledger rows.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function decorate( array $rows ): array {
		return $this->with_gateway_labels( $this->with_booking_refs( $rows ) );
	}

	/**
	 * Name each row's gateway as the gateway names itself.
	 *
	 * A row keeps the id of the gateway that took the money whether or not that gateway is still
	 * installed. One that is gone has nobody left to name it, so its id is the label.
	 *
	 * @param array<int, array<string, mixed>> $rows Ledger rows.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function with_gateway_labels( array $rows ): array {
		foreach ( $rows as $index => $row ) {
			$id      = (string) ( $row['gateway'] ?? '' );
			$gateway = '' !== $id ? $this->gateways->get( $id ) : null;

			$rows[ $index ]['gateway_label'] = null !== $gateway ? $gateway->label() : $id;
		}

		return $rows;
	}

	/**
	 * @param array<int, array<string, mixed>> $rows Ledger rows.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function with_booking_refs( array $rows ): array {
		return $this->resolve_names( $rows, array( 'booking_ref' => array( 'booking_id', 'booking' ) ) );
	}
}
