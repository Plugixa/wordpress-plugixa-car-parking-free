<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Core\Http;


use PlugixaCarParking\Core\Database\Repository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The seven routes every plain entity has, written once.
 *
 * WHY THIS EXISTS. Written out per module, these routes are mechanically identical apart from one
 * word — locations, space types, spaces, coupons — and that is the kind of duplication a
 * copy-paste slip hides in. Seven routes times every module is that mistake with more places to
 * hide, and the fix is the same one `Repository` applies a layer down.
 *
 * WHAT IS SHARED IS THE TRANSPORT, NOT THE RULES. Every method here does what the layering already
 * demands of a controller — authorise, coerce, hand off, shape — and nothing else. There is no
 * validation in this file, no sanitising, and no business decision. A module that needs a rule
 * puts it in its service, which is where a mass action would find it too.
 *
 * WHAT A MODULE OVERRIDES:
 *
 *   - `search_columns()`  which columns a search matches.
 *   - `decorate()`        anything a row needs that is not a column.
 *   - `narrow()`          extra filtering, and the chance to REFUSE a request.
 *   - `do_trash()`        when deleting has rules — a location that still has bookings, a space
 *                         type spaces still point at.
 *   - `do_restore()`      when restoring has rules, which for anything with a unique-ish code is
 *                         always: the trash is the one place a duplicate can get past a
 *                         uniqueness check enforced among live rows.
 *   - `supports_purge()`  false for anything whose hard delete is somebody else's decision.
 *
 * The two defaults that are deliberately UNSAFE-LOOKING: `do_trash()` and `do_restore()` go
 * straight to the repository, which enforces nothing. That is correct for an entity with no rules
 * and wrong for one with them — and it is wrong LOUDLY, because a module with a rule that forgot to
 * override will fail its own smoke test on the first collision. The alternative, routing through a
 * service method that may not exist, would be `method_exists()` at runtime: quieter, and it fails
 * by silently doing the wrong thing instead.
 */
abstract class CrudController extends RestController {

	/**
	 * The collection path segment, e.g. "locations". No leading slash.
	 */
	abstract protected function api_base(): string;

	/**
	 * The entity's repository.
	 */
	abstract protected function repository(): Repository;

	/**
	 * The entity's service.
	 *
	 * Typed loosely on purpose: there is no shared interface to implement, because the four
	 * methods this class calls are the four every service already has, and an interface would be a
	 * second place to declare them. `get()`, `create()`, `update()` and `not_found()`.
	 */
	abstract protected function service(): object;

	/**
	 * Columns a search matches, e.g. `array( 't.name', 't.code' )`.
	 *
	 * @return string[]
	 */
	protected function search_columns(): array {
		return array( 't.name' );
	}

	/**
	 * Extra narrowing for the list, or null.
	 *
	 * Returning a WP_Error from the callable REFUSES the request rather than narrowing it.
	 *
	 * @return callable|null fn(QueryBuilder, WP_REST_Request): ?WP_Error
	 */
	protected function narrow(): ?callable {
		return null;
	}

	/**
	 * Per-row decoration, or null.
	 *
	 * NOT ONLY FOR THE LIST.
	 *
	 * If `index()` alone passed this callable, a row fetched one at a time would come back
	 * UNDECORATED: the locations list would carry `image_url` and `GET /locations/97` would not.
	 * The detail page would read it as absent, so a location the list showed with a picture would
	 * open on a page that said it had none — a wrong answer, in a field the reader has no reason
	 * to doubt.
	 *
	 * It is applied by `decorated()` below, on every route that returns one record: `show()`,
	 * `store()` and `update()`.
	 *
	 * @return callable|null fn(array $row): array
	 */
	protected function decorate(): ?callable {
		return null;
	}

	/**
	 * Page-level decoration, or null.
	 *
	 * FOR WORK THAT CANNOT BE DONE A ROW AT A TIME — which in practice means resolving the names
	 * behind foreign keys, because doing that per row is one query per row per column. See
	 * `RestController::resolve_names()`.
	 *
	 * It runs on a SINGLE record too, through `decorated()`, so a detail page gets the same fields a
	 * list row does. That property is easy to lose by moving work here and wiring it only into
	 * `index()`.
	 *
	 * @return callable|null fn(array $rows): array
	 */
	protected function decorate_page(): ?callable {
		return null;
	}

	/**
	 * Decoration for LIST ROWS ONLY, or null.
	 *
	 * ═══════════════════════════════════════════════════════════════════════════════════════════
	 * FOR THE ONE THING `decorate()` MUST NEVER DO: take a field AWAY.
	 *
	 * A decorator normally adds — `location_name`, `image_url`, a count — so sharing one callable
	 * between the list and the detail routes is right, and `decorated()` exists precisely so a
	 * detail route does not lose the keys a list row has.
	 *
	 * A decorator that trims breaks that symmetry. `unset`ting a booking form's `steps` is correct
	 * for a list (it is the biggest column in the payload and no list screen reads it) and
	 * catastrophic for the detail route, which exists to deliver exactly that field: the edit screen
	 * would load a form with no steps, render an empty builder, and saving it would write the empty
	 * steps back over a configured form.
	 *
	 * So the seam is split by INTENT rather than by entity. Anything that adds keys every route
	 * should share goes in `decorate()`. Anything that trims a payload for a screen that does not
	 * need it goes here, and `index()` is the only caller.
	 * ═══════════════════════════════════════════════════════════════════════════════════════════
	 *
	 * @return callable|null fn(array $row): array
	 */
	protected function decorate_list(): ?callable {
		return null;
	}

	/**
	 * `decorate()` and `decorate_list()` as one callable, for the list route.
	 *
	 * Shared decoration runs FIRST, so a list-only step can trim something the shared step added.
	 *
	 * @return callable|null fn(array $row): array
	 */
	private function list_row_decorator(): ?callable {
		$shared = $this->decorate();
		$only   = $this->decorate_list();

		if ( null === $only ) {
			return $shared;
		}

		if ( null === $shared ) {
			return $only;
		}

		return static fn( array $row ): array => $only( $shared( $row ) );
	}

	/**
	 * One record, with whatever `decorate()` adds.
	 *
	 * Null in, null out — a missing record stays missing rather than being handed to a callable that
	 * expects an array.
	 *
	 * @param array<string, mixed>|null $row The record.
	 *
	 * @return array<string, mixed>|null
	 */
	protected function decorated( ?array $row ): ?array {
		if ( null === $row ) {
			return null;
		}

		$decorate = $this->decorate();

		if ( null !== $decorate ) {
			$row = $decorate( $row );
		}

		$page = $this->decorate_page();

		if ( null !== $page ) {
			// A page of one. The page-level decorator does not care how many rows it is given, and
			// running it here is what keeps a detail route carrying the same keys as a list row.
			$decorated = $page( array( $row ) );
			$row       = $decorated[0] ?? $row;
		}

		return $row;
	}

	/**
	 * Whether this entity offers a permanent delete over REST.
	 */
	protected function supports_purge(): bool {
		return true;
	}

	/**
	 * @param WP_REST_Request $request The request.
	 *
	 * @return array<string, mixed>|null
	 */
	protected function record_for( WP_REST_Request $request ): ?array {
		$id = (int) ( $request['id'] ?? 0 );

		return $id > 0 ? $this->repository()->find( $id, true ) : null;
	}

	public function register_routes(): void {
		$base = $this->api_base();

		register_rest_route(
			$this->namespace,
			'/' . $base,
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'index' ),
					'permission_callback' => array( $this, 'can_read' ),
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
			'/' . $base . '/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'show' ),
					'permission_callback' => array( $this, 'can_read' ),
				),
				array(
					'methods'             => 'PUT, PATCH',
					'callback'            => array( $this, 'update' ),
					'permission_callback' => array( $this, 'can_edit' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'trash' ),
					'permission_callback' => array( $this, 'can_delete' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $base . '/(?P<id>\d+)/restore',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'restore' ),
					'permission_callback' => array( $this, 'can_delete' ),
				),
			)
		);

		if ( $this->supports_purge() ) {
			register_rest_route(
				$this->namespace,
				'/' . $base . '/(?P<id>\d+)/purge',
				array(
					array(
						'methods'             => 'DELETE',
						'callback'            => array( $this, 'purge' ),
						'permission_callback' => array( $this, 'can_delete' ),
					),
				)
			);
		}

		$this->register_extra_routes();
	}

	/**
	 * Routes beyond the seven. Override to add them; the default adds none.
	 */
	protected function register_extra_routes(): void {}

	/**
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function index( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->paged_list(
			$request,
			$this->repository(),
			$this->search_columns(),
			$this->narrow(),
			$this->list_row_decorator(),
			$this->decorate_page()
		);
	}

	/**
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function store( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id = $this->service()->create( (array) $request->get_json_params() );

		if ( $id instanceof WP_Error ) {
			return $id;
		}

		return $this->ok( $this->decorated( $this->service()->get( (int) $id ) ), array(), 201 );
	}

	/**
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function show( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$row = $this->decorated( $this->service()->get( (int) $request['id'] ) );

		return null === $row ? $this->service()->not_found() : $this->ok( $row );
	}

	/**
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function update( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id   = (int) $request['id'];
		$done = $this->service()->update( $id, (array) $request->get_json_params() );

		if ( $done instanceof WP_Error ) {
			return $done;
		}

		return $this->ok( $this->decorated( $this->service()->get( $id ) ) );
	}

	/**
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function trash( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$done = $this->do_trash( (int) $request['id'] );

		if ( $done instanceof WP_Error ) {
			return $done;
		}

		return $done ? $this->ok( array( 'trashed' => true ) ) : $this->service()->not_found();
	}

	/**
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function restore( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$done = $this->do_restore( (int) $request['id'] );

		if ( $done instanceof WP_Error ) {
			return $done;
		}

		return $done ? $this->ok( array( 'restored' => true ) ) : $this->service()->not_found();
	}

	/**
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function purge( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$done = $this->do_purge( (int) $request['id'] );

		if ( $done instanceof WP_Error ) {
			return $done;
		}

		return $done ? $this->ok( array( 'purged' => true ) ) : $this->service()->not_found();
	}

	/**
	 * Trash one row. Override where deleting has rules.
	 *
	 * @param int $id Row id.
	 *
	 * @return bool|WP_Error
	 */
	protected function do_trash( int $id ): bool|WP_Error {
		return $this->repository()->delete( $id );
	}

	/**
	 * Restore one row. Override where restoring has rules — which is anything with a code or a
	 * slug, because uniqueness is enforced among LIVE rows and the trash is the way past it.
	 *
	 * @param int $id Row id.
	 *
	 * @return bool|WP_Error
	 */
	protected function do_restore( int $id ): bool|WP_Error {
		return $this->repository()->restore( $id );
	}

	/**
	 * Permanently delete one row. Override where it has rules.
	 *
	 * @param int $id Row id.
	 *
	 * @return bool|WP_Error
	 */
	protected function do_purge( int $id ): bool|WP_Error {
		return $this->repository()->purge( $id );
	}
}
