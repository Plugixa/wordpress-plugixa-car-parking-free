<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Modules\Locations\Http;

use PlugixaCarParking\Core\Database\Repository;
use PlugixaCarParking\Core\Http\CrudController;
use PlugixaCarParking\Core\Security\Capabilities;
use PlugixaCarParking\Modules\Forms\Repositories\FormRepository;
use PlugixaCarParking\Modules\Forms\Services\FormScope;
use PlugixaCarParking\Modules\Locations\Repositories\LocationRepository;
use PlugixaCarParking\Modules\Locations\Services\LocationService;
use PlugixaCarParking\Modules\Spaces\Repositories\SpaceRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Locations over REST: the seven record routes, plus each location's opening hours and closures.
 *
 * Hours and closures are reached THROUGH their location, at `/locations/{id}/…`, and are
 * authorised as the location is: they have no life of their own, so "may edit this location" is
 * the whole question.
 */
final class LocationsController extends CrudController {

	public function __construct(
		private readonly LocationService $service,
		private readonly LocationRepository $locations,
		private readonly SpaceRepository $spaces,
		private readonly FormRepository $forms
	) {}

	/**
	 * Live forms, read once for a list of locations rather than once a row.
	 *
	 * @var array<int, array<string, mixed>>|null
	 */
	private ?array $live_forms = null;

	protected function entity_type(): string {
		return 'location';
	}

	protected function api_base(): string {
		return 'locations';
	}

	protected function repository(): Repository {
		return $this->locations;
	}

	protected function service(): object {
		return $this->service;
	}

	/**
	 * @return string[]
	 */
	protected function search_columns(): array {
		return array( 't.name', 't.city', 't.address_line1', 't.postcode' );
	}

	/**
	 * How many kinds of space the location has, which a list shows beside its name, and a preview
	 * of its picture, which the form shows: the column holds an attachment id, not an address.
	 *
	 * @return callable fn(array $row): array
	 */
	protected function decorate(): ?callable {
		return function ( array $row ): array {
			$row['space_type_count'] = count( $this->spaces->space_type_ids( (int) $row['id'] ) );
			$row['forms']            = $this->forms_offering( (int) $row['id'] );

			$url              = (int) ( $row['image_id'] ?? 0 ) > 0 ? wp_get_attachment_image_url( (int) $row['image_id'], 'medium' ) : false;
			$row['image_url'] = is_string( $url ) ? $url : null;

			return $row;
		};
	}

	/**
	 * The live forms that book at a location, each saying whether it books at all of them.
	 *
	 * @param int $location_id The location.
	 *
	 * @return array<int, array{id: int, name: string, status: string, all_locations: bool}>
	 */
	private function forms_offering( int $location_id ): array {
		$this->live_forms ??= $this->forms->live();

		$offering = array();

		foreach ( $this->live_forms as $form ) {
			if ( FormScope::allows_location( $form, $location_id ) ) {
				$offering[] = array(
					'id'            => (int) $form['id'],
					'name'          => (string) $form['name'],
					'status'        => (string) $form['status'],
					'all_locations' => array() === FormScope::location_ids( $form ),
				);
			}
		}

		return $offering;
	}

	/**
	 * Through the service, which asks every other module whether the location is still in use.
	 *
	 * @param int $id Location id.
	 *
	 * @return bool|WP_Error
	 */
	protected function do_trash( int $id ): bool|WP_Error {
		return $this->service->delete( $id );
	}

	/**
	 * @param int $id Location id.
	 *
	 * @return bool|WP_Error
	 */
	protected function do_restore( int $id ): bool|WP_Error {
		return $this->service->restore( $id );
	}

	protected function register_extra_routes(): void {
		register_rest_route(
			$this->namespace,
			'/locations/(?P<id>\d+)/hours',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'hours' ),
					'permission_callback' => array( $this, 'can_read' ),
				),
				array(
					'methods'             => 'PUT, POST',
					'callback'            => array( $this, 'save_hours' ),
					'permission_callback' => array( $this, 'can_edit_parent' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/locations/(?P<id>\d+)/exceptions',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'exceptions' ),
					'permission_callback' => array( $this, 'can_read' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'add_exception' ),
					'permission_callback' => array( $this, 'can_edit_parent' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/locations/(?P<id>\d+)/exceptions/(?P<exception_id>\d+)',
			array(
				array(
					'methods'             => 'PUT, PATCH',
					'callback'            => array( $this, 'update_exception' ),
					'permission_callback' => array( $this, 'can_edit_parent' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'delete_exception' ),
					'permission_callback' => array( $this, 'can_edit_parent' ),
				),
			)
		);
	}

	/**
	 * May the current user edit the location these hours or closures belong to?
	 *
	 * NOT `can_edit()`, which also checks the request's `modified_at` against the location's.
	 * That check protects an edit of the location's own fields. Saving a closure does not change
	 * them, and a form for hours would have no reason to be carrying the location's timestamp.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function can_edit_parent( WP_REST_Request $request ): bool|WP_Error {
		return Capabilities::user_can( 'edit', $this->entity_type(), $this->record_for( $request ) )
			? true
			: $this->forbidden();
	}

	/**
	 * @param WP_REST_Request $request The request.
	 */
	public function hours( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id = (int) $request['id'];

		if ( null === $this->service->get( $id ) ) {
			return $this->service->not_found();
		}

		return $this->ok(
			array(
				'location_id' => $id,
				'hours'       => $this->service->hours( $id ),
				// False while nothing is stored, which is when the location is open around the clock.
				'has_hours'   => $this->service->has_hours( $id ),
			)
		);
	}

	/**
	 * @param WP_REST_Request $request The request.
	 */
	public function save_hours( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$saved = $this->service->save_hours( (int) $request['id'], ( (array) $request->get_json_params() )['hours'] ?? null );

		return $saved instanceof WP_Error ? $saved : $this->hours( $request );
	}

	/**
	 * @param WP_REST_Request $request The request.
	 */
	public function exceptions( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id = (int) $request['id'];

		if ( null === $this->service->get( $id ) ) {
			return $this->service->not_found();
		}

		return $this->ok( $this->service->exceptions( $id ) );
	}

	/**
	 * @param WP_REST_Request $request The request.
	 */
	public function add_exception( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$exception = $this->service->add_exception( (int) $request['id'], (array) $request->get_json_params() );

		return $exception instanceof WP_Error ? $exception : $this->ok( $exception, array(), 201 );
	}

	/**
	 * @param WP_REST_Request $request The request.
	 */
	public function update_exception( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$exception = $this->service->update_exception( (int) $request['id'], (int) $request['exception_id'], (array) $request->get_json_params() );

		return $exception instanceof WP_Error ? $exception : $this->ok( $exception );
	}

	/**
	 * @param WP_REST_Request $request The request.
	 */
	public function delete_exception( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$deleted = $this->service->delete_exception( (int) $request['id'], (int) $request['exception_id'] );

		return $deleted instanceof WP_Error ? $deleted : $this->ok( array( 'deleted' => true ) );
	}
}
