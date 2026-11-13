<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Core\Http;

use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Base for the routes a visitor reaches without signing in.
 *
 * Separate from RestController, which answers the admin app: that one authorises by capability,
 * and a visitor has none. A public route is open to the whole internet, so what protects it is
 * said by the handler itself: the guard, then the public nonce, a signed token, or the gateway's
 * own signature.
 *
 * The response envelope here is the one the public scripts were written against, and differs from
 * the admin app's: a success always carries a `message` key, and an error always has the code
 * `rest_error` and an `errors` list in its data.
 */
abstract class PublicController extends WP_REST_Controller {

	/**
	 * The nonce action the public pages mint and the state-changing routes verify.
	 *
	 * Frozen: a page cached with a nonce for this action must keep working across an update.
	 */
	public const NONCE_ACTION = 'plugixacarparking_public';

	/**
	 * REST namespace shared by the whole plugin.
	 *
	 * @var string
	 */
	protected $namespace = 'plugixa-car-parking/v1';

	/**
	 * Run the abuse guard for a request. A handler calls this before anything else.
	 *
	 * @param WP_REST_Request $request  The request.
	 * @param string          $action   The rate-limit bucket, as PublicRequestGuard::limits() lists it.
	 * @param string          $honeypot Name of a field that must arrive empty, or '' for none.
	 */
	protected function guard( WP_REST_Request $request, string $action, string $honeypot = '' ): true|WP_Error {
		return PublicRequestGuard::check(
			$request,
			array(
				'action'   => $action,
				'honeypot' => $honeypot,
			)
		);
	}

	/**
	 * The request's parameters: the JSON body when there is one, otherwise everything else.
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return array<string, mixed>
	 */
	protected function params( WP_REST_Request $request ): array {
		$json = $request->get_json_params();

		return $json ? (array) $json : (array) $request->get_params();
	}

	/**
	 * Check the public nonce, sent as `security` in the body or in a header.
	 *
	 * The nonce shows the request came from one of this site's own pages. It identifies nobody:
	 * every visitor can read it from the page.
	 *
	 * @param WP_REST_Request      $request The request.
	 * @param array<string, mixed> $params  The request's parameters.
	 */
	protected function verify_nonce( WP_REST_Request $request, array $params ): ?WP_Error {
		$nonce = $params['security'] ?? $request->get_header( 'x_plugixa_carparking_nonce' );

		if ( ! wp_verify_nonce( is_scalar( $nonce ) ? (string) $nonce : '', self::NONCE_ACTION ) ) {
			return $this->error( __( 'Your session expired. Please refresh and try again.', 'plugixa-car-parking' ), 403 );
		}

		return null;
	}

	/**
	 * A successful response.
	 *
	 * @param mixed  $data    Payload.
	 * @param string $message A sentence for the customer, when there is one.
	 */
	protected function success( mixed $data, string $message = '' ): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => $message,
				'data'    => $data,
			),
			200
		);
	}

	/**
	 * An error response.
	 *
	 * @param string $message A sentence for the customer.
	 * @param int    $status  HTTP status.
	 */
	protected function error( string $message, int $status = 400 ): WP_Error {
		return new WP_Error(
			'rest_error',
			$message,
			array(
				'status' => $status,
				'errors' => array(),
			)
		);
	}
}
