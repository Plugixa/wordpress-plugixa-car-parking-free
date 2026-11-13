<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Core\Http;

use PlugixaCarParking\Core\Security\Capabilities;
use WP_REST_Request;
use WP_REST_Response;
use WP_User_Query;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The people a record can point at, for a user picker.
 *
 * Deliberately not the site's whole user table — only people who can open the app, because
 * assigning a record to a subscriber who cannot is a row nobody can act on. And deliberately minimal:
 * id and display name, which is everything a picker needs and nothing a privacy audit has to think
 * about.
 *
 * "WHO CAN OPEN THE APP" IS ASKED THE WAY `Capabilities::holder_query()` SAYS. Querying
 * `plugixa_carparking_manage` directly would miss people: it is derived at runtime and stored almost
 * nowhere, so a user granted one of the narrower capabilities would never be offered here.
 */
final class UsersController extends RestController {

	/** A picker, not a directory: one screenful, narrowed by typing. */
	private const LIMIT = 50;

	protected function entity_type(): string {
		return 'core';
	}

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/users',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'index' ),
					'permission_callback' => array( $this, 'can_read' ),
					'args'                => array(
						'search' => array( 'type' => 'string' ),
					),
				),
			)
		);
	}

	/**
	 * @param WP_REST_Request $request The request.
	 */
	public function index( WP_REST_Request $request ): WP_REST_Response {
		$search = trim( (string) ( $request->get_param( 'search' ) ?? '' ) );

		$query = new WP_User_Query(
			array_merge(
				Capabilities::holder_query(),
				array(
					'number'         => self::LIMIT,
					'orderby'        => 'display_name',
					'order'          => 'ASC',
					'fields'         => array( 'ID', 'display_name' ),
					'search'         => '' !== $search ? '*' . $search . '*' : '',
					// Search by the name a picker displays, not by login or email.
					'search_columns' => array( 'display_name' ),
				)
			)
		);

		$users = array();

		foreach ( (array) $query->get_results() as $user ) {
			// The query reads stored capabilities; this applies the whole picture. See holder_query().
			if ( ! user_can( (int) $user->ID, Capabilities::MANAGE ) ) {
				continue;
			}

			$users[] = array(
				'id'   => (int) $user->ID,
				'name' => (string) $user->display_name,
			);
		}

		return $this->ok( $users );
	}
}
