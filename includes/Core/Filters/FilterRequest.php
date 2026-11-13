<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Core\Filters;

use PlugixaCarParking\Core\Entities\EntityRegistry;
use WP_Error;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Applies a request's filters to every list query it makes, and to nothing else.
 *
 * WHY A PER-REQUEST CLOSURE AND NOT A PROPERTY. A property holding "the current request" would be
 * read by every QueryBuilder in the process, survive a nested `rest_do_request()`, and leak between
 * requests in a test suite. A closure that exists only for the dispatch it belongs to cannot go
 * stale.
 *
 * WHY `plugixa_carparking_query_clauses` AND NOT THE CONTROLLERS. Two reasons:
 *
 *   1. Anything that builds its own SELECT and re-fires this same filter — an export — is reached
 *      for free. That matters, because a filtered list whose export ignored the filter would
 *      produce a file that does not match the screen, silently, after the fact.
 *   2. A list route need not use `paged_list()` to be narrowed: every query a repository builds
 *      passes through this hook. Hooking here means no controller changes at all.
 */
final class FilterRequest {

	/**
	 * Registry slug keyed by api_base, built once per request. See api_bases().
	 *
	 * @var array<string, string>|null
	 */
	private ?array $by_api_base = null;

	public function __construct(
		private readonly FilterCatalogue $catalogue,
		private readonly FilterCompiler $compiler,
		private readonly EntityRegistry $entities
	) {}

	public function register_hooks(): void {
		// The exact closure that was added, so it is the exact closure that gets removed.
		// remove_all_filters() here would be a quiet act of vandalism: the record-level ACL hangs
		// on this same filter, and tearing it off mid-request would not fail — it would just stop
		// denying things.
		$clause = null;

		/**
		 * Take the previous dispatch's clause off, wherever we are in the cycle.
		 *
		 * CALLED AT BOTH ENDS, AND THE FRONT ONE IS NOT BELT-AND-BRACES — IT IS THE ONLY ONE THAT
		 * ALWAYS RUNS. `rest_post_dispatch` is fired by `WP_REST_Server::serve_request()`, NOT by
		 * `dispatch()`. So an internal `rest_do_request()` — and every request a test suite
		 * makes — adds a clause that is never removed, and the NEXT list request in the same
		 * process gets both: `t.id NOT IN (…) AND t.id IN (…)`, which is satisfiable by nothing.
		 * The list comes back empty, with no error, for a filter that is perfectly valid.
		 */
		$reset = static function () use ( &$clause ): void {
			if ( null !== $clause ) {
				remove_filter( 'plugixa_carparking_query_clauses', $clause, 10 );

				$clause = null;
			}
		};

		add_filter(
			'rest_pre_dispatch',
			static function ( $result ) use ( $reset ) {
				$reset();

				return $result;
			}
		);

		/*
		 * ═══════════════════════════════════════════════════════════════════════════════════════
		 * PARSED AFTER THE PERMISSION CHECK, NOT BEFORE IT.
		 *
		 * `rest_pre_dispatch` would be too early: WordPress fires it before it has matched the
		 * route, so before any permission callback. A signed-out visitor filtering on a field the
		 * list does not have would be answered 400 naming the field; filtering on one it has would
		 * go on to be refused 401. Two answers to somebody allowed to see neither, and the
		 * difference tells them which fields exist — custom field keys included.
		 *
		 * `rest_dispatch_request` is the first hook WordPress fires once the route's permission
		 * callback has said yes, and the last before the callback runs, so the 400 still arrives
		 * before a single query and the clause is still in place for the list that follows. It
		 * receives the handler, which is also why a refusal from the permission check cannot be
		 * pre-empted: WordPress does not call this at all when that check failed.
		 * ═══════════════════════════════════════════════════════════════════════════════════════
		 */
		add_filter(
			'rest_dispatch_request',
			function ( $result, WP_REST_Request $request ) use ( &$clause, $reset ) {
				// Again here, so a clause never outlives the request it was compiled for even where
				// this fires without a `rest_pre_dispatch` of its own in front of it.
				$reset();

				if ( null !== $result ) {
					return $result;
				}

				$entity_type = $this->entity_for( $request );

				if ( '' === $entity_type ) {
					return $result;
				}

				$filters = $this->compiler->parse( $request->get_query_params(), $entity_type );

				if ( $filters instanceof WP_Error ) {
					// Short-circuits the dispatch. A filter naming a field this list does not have
					// is answered before a single query runs, which is the difference between a
					// clear 400 and a list that quietly ignored half of what was asked.
					return $filters;
				}

				if ( array() === $filters ) {
					return $result;
				}

				$compiled = $this->compiler->compile( $filters, $entity_type );

				$clause = self::clause( $entity_type, $compiled );

				add_filter( 'plugixa_carparking_query_clauses', $clause, 10, 3 );

				return $result;
			},
			10,
			2
		);

		add_filter(
			'rest_post_dispatch',
			static function ( $response ) use ( $reset ) {
				$reset();

				return $response;
			},
			99
		);
	}

	/**
	 * The clause that narrows one entity's lists.
	 *
	 * @param string                                            $entity_type Registry slug.
	 * @param array{where: string[], params: array<int, mixed>} $compiled    From the compiler.
	 *
	 * @return callable(array<string, mixed>, string, string): array<string, mixed>
	 */
	private static function clause( string $entity_type, array $compiled ): callable {
		return static function ( array $clauses, string $type, string $context ) use ( $entity_type, $compiled ): array {
			// ONLY THE LISTS. The same builder answers other contexts too — totals, a search — and
			// narrowing those would be a silent wrong answer: a dashboard would report one
			// location's bookings as the whole site's, with no filter visible anywhere to explain
			// the number. 'list' and 'count' must both be narrowed or the grid and its
			// pager would disagree about how many rows there are.
			if ( 'list' !== $context && 'count' !== $context ) {
				return $clauses;
			}

			// The request filtered ONE entity. A list route that internally counts something else —
			// a roll-up, a related total — must not inherit a filter meant for its own rows.
			if ( $type !== $entity_type ) {
				return $clauses;
			}

			foreach ( $compiled['where'] as $where ) {
				$clauses['where'][] = $where;
			}

			$clauses['params'] = array_merge( $clauses['params'] ?? array(), $compiled['params'] );

			return $clauses;
		};
	}

	/**
	 * Which entity a request is listing, or '' if it is not one this can narrow.
	 *
	 * TWO ROUTE SHAPES, AND THE SECOND IS THE WHOLE POINT.
	 *
	 *   /plugixa-car-parking/v1/bookings        a list  — the segment is an api_base
	 *   /plugixa-car-parking/v1/export/booking  an export — the segment is already a registry slug
	 *
	 * Export has to be here or the feature is half-built: someone looking at a filtered list and
	 * clicking Export would get a file containing rows the screen never showed. Nothing would error
	 * and nobody would notice until the numbers were used for something.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	private function entity_for( WP_REST_Request $request ): string {
		$route = $request->get_route();

		if ( preg_match( '#^/plugixa-car-parking/v1/export/([a-z_]+)/?$#', $route, $matches ) ) {
			$entity_type = $matches[1];
		} elseif ( preg_match( '#^/plugixa-car-parking/v1/([a-z0-9_-]+)/?$#', $route, $matches ) ) {
			$entity_type = $this->api_bases()[ $matches[1] ] ?? '';
		} else {
			return '';
		}

		// An entity nobody declared a filter for cannot be filtered, and asking here saves parsing
		// the params of every request to every other route in the plugin.
		return '' === $entity_type || array() === $this->catalogue->for( $entity_type )
			? ''
			: $entity_type;
	}

	/**
	 * Registry slug keyed by api_base.
	 *
	 * The api_base-to-table convention `RecordLoader` documents is not needed here — this only
	 * maps a path segment back to a slug, and an entity registered without an api_base is simply
	 * left out.
	 *
	 * @return array<string, string>
	 */
	private function api_bases(): array {
		if ( null === $this->by_api_base ) {
			$map = array();

			foreach ( $this->entities->all() as $slug => $descriptor ) {
				$api_base = (string) ( $descriptor['api_base'] ?? '' );

				if ( '' !== $api_base ) {
					$map[ $api_base ] = (string) $slug;
				}
			}

			/**
			 * Extra URL segment => filter slug mappings, for lists that are not registered entities.
			 *
			 * NOT EVERY FILTERABLE LIST IS A RECORD. A list of configuration or of log rows has a
			 * table with no owner column, and registering it as an entity would invite everything
			 * that treats entities generically — record-level scoping by an `assigned_user_id` that
			 * does not exist, for one, which is a SQL error on every list query. So it declares
			 * filters without declaring itself a record.
			 *
			 * The registry's own entries are added first and can be OVERRIDDEN here, which is the
			 * point: a module whose route segment differs from its filter slug (`booking-forms` versus
			 * `booking_forms`) has no other way to be reachable. Getting this wrong is silent — the
			 * drawer offers filters, the client sends them, and every one is dropped on a 200.
			 *
			 * @param array<string, string> $map URL segment => filter slug.
			 */
			$this->by_api_base = (array) apply_filters( 'plugixa_carparking_filter_api_bases', $map );
		}

		return $this->by_api_base;
	}
}
