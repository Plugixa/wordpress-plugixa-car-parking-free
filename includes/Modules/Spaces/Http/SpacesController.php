<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Modules\Spaces\Http;

use PlugixaCarParking\Core\Database\Repository;
use PlugixaCarParking\Core\Http\CrudController;
use PlugixaCarParking\Modules\Spaces\Repositories\SpaceRepository;
use PlugixaCarParking\Modules\Spaces\Services\SpaceService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A location's inventory rows over REST: the seven record routes.
 *
 * Served at `/location-spaces`, which mirrors the table name with a dash: anything that knows a
 * record only by its entity type finds the table from that path segment.
 */
final class SpacesController extends CrudController {

	public function __construct(
		private readonly SpaceService $service,
		private readonly SpaceRepository $spaces
	) {}

	protected function entity_type(): string {
		return 'location_space';
	}

	protected function api_base(): string {
		return 'location-spaces';
	}

	protected function repository(): Repository {
		return $this->spaces;
	}

	protected function service(): object {
		return $this->service;
	}

	/**
	 * @return string[]
	 */
	protected function search_columns(): array {
		return array( 't.label' );
	}

	/**
	 * The names behind each row's location and space type, which a list shows instead of the ids.
	 *
	 * Per page rather than per row, so a page costs one lookup per entity however long it is.
	 *
	 * @return callable fn(array $rows): array
	 */
	protected function decorate_page(): ?callable {
		return fn( array $rows ): array => $this->resolve_names(
			$rows,
			array(
				'location_name'   => array( 'location_id', 'location' ),
				'space_type_name' => array( 'space_type_id', 'space_type' ),
			)
		);
	}

	protected function register_extra_routes(): void {
		register_rest_route(
			$this->namespace,
			'/location-spaces/numbered',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'store_numbered' ),
					'permission_callback' => array( $this, 'can_create' ),
				),
			)
		);
	}

	/**
	 * Add a run of identified spaces: { location_id, space_type_id, prefix, from, to }.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function store_numbered( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$ids = $this->service->create_numbered( (array) $request->get_json_params() );

		return $ids instanceof WP_Error ? $ids : $this->ok( array( 'ids' => $ids ), array(), 201 );
	}

	/**
	 * Through the service, which asks every other module whether the space is still in use.
	 *
	 * @param int $id Space id.
	 *
	 * @return bool|WP_Error
	 */
	protected function do_trash( int $id ): bool|WP_Error {
		return $this->service->delete( $id );
	}

	/**
	 * @param int $id Space id.
	 *
	 * @return bool|WP_Error
	 */
	protected function do_restore( int $id ): bool|WP_Error {
		return $this->service->restore( $id );
	}
}
