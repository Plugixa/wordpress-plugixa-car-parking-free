<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Core\Compat;

use PlugixaCarParking\Core\Plugin;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The version 1 REST addresses that can never move.
 *
 * Version 1 served everything from `plugixacarparking/v1`. The routes are now on the plugin's one
 * namespace, and the public pages call them there. A few addresses are written down outside the
 * plugin, where an update cannot reach them: the webhook URL pasted into a payment provider's
 * dashboard, the return URL a provider sends a customer back to, and links in emails customers
 * already hold. Those stay answered at the old address for good.
 *
 * An alias is the route itself under a second name: the same callback, the same permission check
 * and the same methods, read from what the owning module registered. Nothing is dispatched twice
 * and nothing here knows which module that is.
 *
 * Every other version 1 address is gone and answers 404.
 */
final class LegacyRoutes {

	/**
	 * The namespace version 1 used.
	 */
	public const REST_NAMESPACE = 'plugixacarparking/v1';

	/**
	 * Paths kept at the old address, as they are registered on the current namespace.
	 *
	 * @var string[]
	 */
	private const ALIASES = array(
		// Registered with a payment provider as the address to notify.
		'/payment/webhook/(?P<gateway>[a-z0-9_-]+)',
		// Where a payment provider sends the customer's browser back to.
		'/payment/return',
	);

	/**
	 * Register each alias whose route exists. Called after every module has registered its own.
	 *
	 * @param WP_REST_Server $server The REST server.
	 */
	public function register( WP_REST_Server $server ): void {
		/**
		 * Filter the paths kept alive on the version 1 namespace.
		 *
		 * For a module that owns an address written down outside the plugin. A path is aliased
		 * only when a route is registered for it on the current namespace, so listing one adds
		 * nothing by itself.
		 *
		 * @param string[] $paths Route paths below the namespace, e.g. '/payment/return'.
		 */
		$paths  = array_unique( array_map( 'strval', (array) apply_filters( 'plugixa_carparking_legacy_route_aliases', self::ALIASES ) ) );
		$routes = $server->get_routes( Plugin::REST_NAMESPACE );

		foreach ( $paths as $path ) {
			$handlers = $routes[ '/' . Plugin::REST_NAMESPACE . $path ] ?? null;

			if ( ! is_array( $handlers ) ) {
				continue;
			}

			$copies = array();

			foreach ( $handlers as $handler ) {
				// A copy keeps the original's own check. A handler without one is not copied, so this
				// can never open a route that the current address does not.
				if ( ! is_array( $handler ) || ! isset( $handler['callback'], $handler['permission_callback'] ) ) {
					continue;
				}

				$copies[] = array(
					// The server keeps methods as a map of name => true; registration wants the names.
					'methods'             => array_keys( array_filter( (array) $handler['methods'] ) ),
					'callback'            => $handler['callback'],
					'permission_callback' => $handler['permission_callback'],
					'args'                => $handler['args'] ?? array(),
				);
			}

			if ( $copies ) {
				register_rest_route( self::REST_NAMESPACE, $path, $copies );
			}
		}
	}
}
