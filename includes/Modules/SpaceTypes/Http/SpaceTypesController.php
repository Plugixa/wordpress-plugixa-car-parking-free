<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Modules\SpaceTypes\Http;

use PlugixaCarParking\Core\Database\Repository;
use PlugixaCarParking\Core\Http\CrudController;
use PlugixaCarParking\Core\Security\Capabilities;
use PlugixaCarParking\Modules\SpaceTypes\Repositories\SpaceTypeRepository;
use PlugixaCarParking\Modules\SpaceTypes\Services\SpaceTypeService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Space types over REST: the seven record routes, plus each type's base prices.
 *
 * Prices are reached THROUGH their space type, at `/space-types/{id}/prices`, and are authorised
 * as the type is: they have no life of their own, so "may edit this space type" is the whole
 * question.
 */
final class SpaceTypesController extends CrudController {

	public function __construct(
		private readonly SpaceTypeService $service,
		private readonly SpaceTypeRepository $types
	) {}

	protected function entity_type(): string {
		return 'space_type';
	}

	protected function api_base(): string {
		return 'space-types';
	}

	protected function repository(): Repository {
		return $this->types;
	}

	protected function service(): object {
		return $this->service;
	}

	/**
	 * @return string[]
	 */
	protected function search_columns(): array {
		return array( 't.name', 't.slug' );
	}

	/**
	 * Through the service, which asks every other module whether the type is still in use.
	 *
	 * @param int $id Space type id.
	 *
	 * @return bool|WP_Error
	 */
	protected function do_trash( int $id ): bool|WP_Error {
		return $this->service->delete( $id );
	}

	/**
	 * @param int $id Space type id.
	 *
	 * @return bool|WP_Error
	 */
	protected function do_restore( int $id ): bool|WP_Error {
		return $this->service->restore( $id );
	}

	protected function register_extra_routes(): void {
		register_rest_route(
			$this->namespace,
			'/space-types/(?P<id>\d+)/prices',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'prices' ),
					'permission_callback' => array( $this, 'can_read' ),
				),
				array(
					'methods'             => 'PUT',
					'callback'            => array( $this, 'save_prices' ),
					'permission_callback' => array( $this, 'can_edit_parent' ),
				),
			)
		);
	}

	/**
	 * May the current user edit the space type these prices belong to?
	 *
	 * NOT `can_edit()`, which also checks the request's `modified_at` against the type's. That
	 * check protects an edit of the type's own fields. Saving prices does not change them, and a
	 * price editor would have no reason to be carrying the type's timestamp.
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
	public function prices( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id = (int) $request['id'];

		if ( null === $this->service->get( $id ) ) {
			return $this->service->not_found();
		}

		return $this->ok(
			array(
				'space_type_id' => $id,
				'prices'        => $this->service->prices( $id ),
			)
		);
	}

	/**
	 * @param WP_REST_Request $request The request.
	 */
	public function save_prices( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$saved = $this->service->save_prices( (int) $request['id'], ( (array) $request->get_json_params() )['prices'] ?? null );

		return $saved instanceof WP_Error ? $saved : $this->prices( $request );
	}
}
