<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Modules\Bookings\Http;

use PlugixaCarParking\Core\Http\RestController;
use PlugixaCarParking\Core\Security\Capabilities;
use PlugixaCarParking\Modules\Bookings\Services\SetupReport;
use PlugixaCarParking\Modules\Forms\Repositories\FormRepository;
use PlugixaCarParking\Modules\Locations\Repositories\LocationRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Two read-only answers for the admin: what a location offers, and what a form offers.
 *
 * Both are worked out from the rule the public routes book by, so a warning here means a
 * customer is really not offered the thing.
 */
final class SetupController extends RestController {

	public function __construct(
		private readonly SetupReport $report,
		private readonly LocationRepository $locations,
		private readonly FormRepository $forms
	) {}

	protected function entity_type(): string {
		return 'location';
	}

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/locations/(?P<id>\d+)/setup',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'location' ),
					'permission_callback' => array( $this, 'can_read' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/booking-forms/(?P<id>\d+)/preview',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'form' ),
					'permission_callback' => array( $this, 'can_read_forms' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/space-types/(?P<id>\d+)/coverage',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'space_type' ),
					'permission_callback' => array( $this, 'can_read_space_types' ),
				),
			)
		);
	}

	/**
	 * @param WP_REST_Request $request The request.
	 */
	public function can_read_forms( WP_REST_Request $request ): bool|WP_Error {
		unset( $request );

		return Capabilities::user_can( 'read', 'booking_form' ) ? true : $this->forbidden();
	}

	/**
	 * @param WP_REST_Request $request The request.
	 */
	public function can_read_space_types( WP_REST_Request $request ): bool|WP_Error {
		unset( $request );

		return Capabilities::user_can( 'read', 'space_type' ) ? true : $this->forbidden();
	}

	/**
	 * @param WP_REST_Request $request The request.
	 */
	public function space_type( WP_REST_Request $request ): WP_REST_Response {
		return $this->ok( $this->report->coverage( (int) $request['id'] ) );
	}

	/**
	 * @param WP_REST_Request $request The request.
	 */
	public function location( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$location = $this->locations->find( (int) $request['id'] );

		return null === $location
			? new WP_Error( 'plugixa_carparking_not_found', __( 'That location does not exist.', 'plugixa-car-parking' ), array( 'status' => 404 ) )
			: $this->ok( $this->report->for_location( $location ) );
	}

	/**
	 * @param WP_REST_Request $request The request.
	 */
	public function form( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$form = $this->forms->find( (int) $request['id'] );

		return null === $form
			? new WP_Error( 'plugixa_carparking_not_found', __( 'That form does not exist.', 'plugixa-car-parking' ), array( 'status' => 404 ) )
			: $this->ok( $this->report->for_form( $form ) );
	}
}
