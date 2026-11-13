<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Modules\Dashboard\Http;

use PlugixaCarParking\Core\Http\RestController;
use PlugixaCarParking\Modules\Dashboard\Services\Stats;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The dashboard's figures over REST.
 */
final class DashboardController extends RestController {

	public function __construct( private readonly Stats $stats ) {}

	protected function entity_type(): string {
		return 'booking';
	}

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/dashboard',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'index' ),
					'permission_callback' => array( $this, 'can_read_figures' ),
				),
			)
		);
	}

	/**
	 * The figures are made of bookings and of payments, so both must be readable.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function can_read_figures( WP_REST_Request $request ): bool|WP_Error {
		unset( $request );

		return $this->allowed_on( 'read', 'booking', 'payment' );
	}

	/**
	 * @param WP_REST_Request $request The request.
	 */
	public function index( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		unset( $request );

		$figures = $this->stats->all();

		foreach ( array( 'recent_bookings', 'upcoming_arrivals' ) as $list ) {
			$figures[ $list ] = $this->resolve_names(
				$figures[ $list ],
				array( 'location_name' => array( 'location_id', 'location' ) )
			);
		}

		return $this->ok( $figures );
	}
}
