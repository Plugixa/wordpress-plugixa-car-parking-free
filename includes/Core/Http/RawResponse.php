<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Core\Http;

use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Serving a file from a REST route without WordPress turning it into JSON.
 *
 * THE PROBLEM. `WP_REST_Response` holding a string is JSON-encoded by the REST server on its way out,
 * which is right for an API and ruinous for a download: a `.csv` arrives as one quoted, escaped string
 * that a spreadsheet opens into a single cell, and a `.pdf` arrives as bytes no reader will touch.
 * `rest_pre_serve_request` is the one hook that runs after the response is built and before it is
 * encoded, so it is the only place a body can be sent untouched.
 *
 * WHY THIS IS IN CORE RATHER THAN IN THE MODULE THAT NEEDS IT FIRST. A listener owned by one module
 * is right only while that module has the plugin's one raw route. `apply_filters()` runs EVERY
 * callback regardless of what an earlier one returned, so two modules each echoing a body they
 * recognise would write the same file to the output stream twice and produce one that opens nowhere.
 *
 * One listener, registered once, with `$served` honoured — which is the part that makes another raw
 * route free rather than another chance to get this wrong.
 */
final class RawResponse {

	/**
	 * Marks a response whose body must be sent as-is.
	 *
	 * A header rather than a subclass or a global, because it is the only thing that survives the trip
	 * through WordPress's dispatcher to the point where the decision can be acted on.
	 */
	public const HEADER = 'X-Plugixa-Raw';

	/**
	 * A CSV response the REST server must not turn into JSON.
	 *
	 * ═══════════════════════════════════════════════════════════════════════════════════════════
	 * HERE SO THAT EVERY CONTROLLER SERVING A CSV SHARES ONE COPY.
	 *
	 * Two copies of four headers is exactly the duplication this class's own docblock warns about
	 * for the listener below, and the header that matters least to a reader is the one that matters
	 * most:
	 *
	 * `X-Content-Type-Options: nosniff` is not boilerplate. A CSV is a generated file made of
	 * user-supplied strings, and without it a browser is free to decide that one whose first cell
	 * looks like markup is HTML — and render it on this origin. That is stored XSS delivered by the
	 * export button, and a copy of this method that dropped the header would have it.
	 * ═══════════════════════════════════════════════════════════════════════════════════════════
	 *
	 * @param string $filename What the browser saves it as.
	 * @param string $csv      The document.
	 */
	public static function csv( string $filename, string $csv ): WP_REST_Response {
		$response = new WP_REST_Response( $csv, 200 );

		$response->set_headers(
			array(
				self::HEADER             => '1',
				'Content-Type'           => 'text/csv; charset=utf-8',
				'Content-Disposition'    => 'attachment; filename="' . sanitize_file_name( $filename ) . '"',
				'X-Content-Type-Options' => 'nosniff',
			)
		);

		return $response;
	}

	public function register_hooks(): void {
		add_filter( 'rest_pre_serve_request', array( $this, 'serve' ), 10, 2 );
	}

	/**
	 * @param bool  $served Whether the request has already been served.
	 * @param mixed $result The response being sent.
	 */
	public function serve( bool $served, mixed $result ): bool {
		// Somebody else already wrote the body. Writing ours after it would corrupt both.
		if ( $served || ! $result instanceof WP_REST_Response ) {
			return $served;
		}

		$headers = $result->get_headers();

		if ( empty( $headers[ self::HEADER ] ) ) {
			return $served;
		}

		$body = $result->get_data();

		if ( ! is_string( $body ) ) {
			return $served;
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a generated file (CSV, PDF), sent with its own Content-Type and nosniff. Escaping it for HTML would corrupt the very bytes this path exists to deliver.
		echo $body;

		return true;
	}
}
