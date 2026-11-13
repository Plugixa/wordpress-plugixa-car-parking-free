<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Core\Http;

use WP_Error;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abuse control for the routes a signed-out visitor can reach.
 *
 * A public route cannot ask who is calling, so it asks how often instead. Its handler calls
 * `check()` before anything else. Three independent checks run, cheapest first:
 *
 *   1. Honeypot: a form field a person never sees and a naive bot fills in.
 *   2. Rate limit: per address, per action, held in a transient.
 *   3. CAPTCHA: a filter seam. Nothing is bundled, because that would mean an outside service;
 *      a site adds one by hooking a single filter.
 *
 * The rate-limit key is a hash of the address, so no raw address sits in the options table under
 * a guessable name, and the address never travels in a URL or a response.
 */
final class PublicRequestGuard {

	/**
	 * How many requests one address may make to each public route, and in how many seconds.
	 *
	 * Every public threshold lives here and nowhere else. Each entry is `action => array( limit,
	 * window in seconds )`, counted per visitor address. A site tunes them with the
	 * `plugixa_carparking_rate_limits` filter.
	 *
	 * An address is often shared: an office, a hotel or a mobile carrier puts many customers
	 * behind one. The numbers are set so that several people booking at once from one address
	 * never meet a limit, and a script meets it within minutes.
	 *
	 * @var array<string, array{0: int, 1: int}>
	 */
	private const LIMITS = array(
		// Fires each time a customer checks a pair of dates. Generous: people try many.
		'availability'    => array( 120, 10 * MINUTE_IN_SECONDS ),
		// Fires on every change of quantity, add-on or space while a customer chooses. The busiest route.
		'quote'           => array( 240, 10 * MINUTE_IN_SECONDS ),
		// A quote that carries a coupon code says whether the code works, so it is also a way to guess codes.
		'quote_coupon'    => array( 30, 10 * MINUTE_IN_SECONDS ),
		// Creating a booking holds a space and sends email. Ten an hour is more than a household books.
		'booking'         => array( 10, HOUR_IN_SECONDS ),
		// Starting a payment calls the payment provider. A customer retries a few times at most.
		'payment_start'   => array( 20, HOUR_IN_SECONDS ),
		// A guest finding a booking by reference and email. The strictest: every miss is a guess at somebody else's booking.
		'account_lookup'  => array( 10, 15 * MINUTE_IN_SECONDS ),
		// A signed-in customer listing their own bookings. The page reloads the list after each change they make.
		'account_list'    => array( 60, 10 * MINUTE_IN_SECONDS ),
		// Cancelling or moving a booking. Rare for a real customer, and each attempt tests a reference and email.
		'account_change'  => array( 10, HOUR_IN_SECONDS ),
		// Checking a coupon code directly. Strict: this is the cheapest way to guess a code.
		'coupon_validate' => array( 10, 10 * MINUTE_IN_SECONDS ),
		// Downloading an invoice. Rendering one is slow, and each request tests a token.
		'invoice'         => array( 30, 10 * MINUTE_IN_SECONDS ),
	);

	/**
	 * What an action is limited to when nothing lists it.
	 */
	private const DEFAULT_LIMIT = array( 20, HOUR_IN_SECONDS );

	/**
	 * The limits in force, after the site has had its say.
	 *
	 * @return array<string, array{0: int, 1: int}> Action => requests, then window in seconds.
	 */
	public static function limits(): array {
		/**
		 * Filter the rate limits of the public routes.
		 *
		 * @param array<string, array{0: int, 1: int}> $limits Action => array( requests, seconds ).
		 */
		$limits = (array) apply_filters( 'plugixa_carparking_rate_limits', self::LIMITS );

		// A filter that returns something unusable for an action must not switch its limit off.
		foreach ( $limits as $action => $limit ) {
			if ( ! is_array( $limit ) || ! isset( $limit[0], $limit[1] ) ) {
				$limits[ $action ] = self::LIMITS[ $action ] ?? self::DEFAULT_LIMIT;
			}
		}

		return $limits;
	}

	public static function register_hooks(): void {
		add_filter( 'rest_request_after_callbacks', array( self::class, 'send_retry_after' ) );
	}

	/**
	 * Tell a limited client how long to wait, in the header HTTP has for it.
	 *
	 * A refusal leaves its handler as a WP_Error, which cannot carry a header. The wait travels
	 * in the error's data, and here the error becomes the response it was going to become
	 * anyway, with the header on it.
	 *
	 * @param mixed $response What the route's handler returned.
	 */
	public static function send_retry_after( mixed $response ): mixed {
		if ( ! $response instanceof WP_Error || 'plugixa_carparking_rate_limited' !== $response->get_error_code() ) {
			return $response;
		}

		$data = (array) $response->get_error_data();

		$limited = rest_convert_error_to_response( $response );
		$limited->header( 'Retry-After', (string) max( 1, (int) ( $data['retry_after'] ?? 1 ) ) );

		return $limited;
	}

	/**
	 * Run the guard for one public request.
	 *
	 * @param WP_REST_Request      $request The incoming request.
	 * @param array<string, mixed> $options Tuning. Keys:
	 *                                      - action   string Names the rate-limit bucket, and looks its limit up in limits().
	 *                                      - limit    int    Requests allowed per window, instead of the listed one.
	 *                                      - window   int    Window length in seconds, instead of the listed one.
	 *                                      - honeypot string Name of the field that must be empty. Default '' (off).
	 *
	 * @return true|WP_Error True to proceed; a 400, 403 or 429 error to refuse.
	 */
	public static function check( WP_REST_Request $request, array $options = array() ): true|WP_Error {
		$action = isset( $options['action'] ) ? sanitize_key( (string) $options['action'] ) : 'public';

		$honeypot = isset( $options['honeypot'] ) ? (string) $options['honeypot'] : '';
		if ( '' !== $honeypot ) {
			$trap = $request->get_param( $honeypot );

			if ( null !== $trap && '' !== trim( is_scalar( $trap ) ? (string) $trap : 'filled' ) ) {
				// Deliberately says nothing a bot could learn from.
				return new WP_Error(
					'plugixa_carparking_rejected',
					__( 'This request could not be processed.', 'plugixa-car-parking' ),
					array( 'status' => 400 )
				);
			}
		}

		$listed = self::limits()[ $action ] ?? self::DEFAULT_LIMIT;
		$limit  = max( 1, (int) ( $options['limit'] ?? $listed[0] ) );
		$window = max( 1, (int) ( $options['window'] ?? $listed[1] ) );

		$refused = self::rate_limit( $action, $limit, $window );
		if ( $refused instanceof WP_Error ) {
			return $refused;
		}

		/**
		 * Verify a public request against a CAPTCHA/anti-bot service.
		 *
		 * Nothing is registered by default, so the default verdict is "allowed". A site that wants
		 * Turnstile/hCaptcha hooks this, reads its token from the request, and returns false or a
		 * WP_Error to refuse. Returning a WP_Error lets the site control the status and message.
		 *
		 * @param true|false|WP_Error $verdict The running verdict; true unless a filter refuses.
		 * @param WP_REST_Request     $request The request, carrying the CAPTCHA token if any.
		 * @param string              $action  The route's action name.
		 */
		$captcha = apply_filters( 'plugixa_carparking_public_request_captcha', true, $request, $action );

		if ( $captcha instanceof WP_Error ) {
			return $captcha;
		}

		if ( false === $captcha ) {
			return new WP_Error(
				'plugixa_carparking_captcha_failed',
				__( 'The anti-spam check did not pass. Please try again.', 'plugixa-car-parking' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Fixed-window counter per address, in a transient.
	 *
	 * A fixed window costs one transient read and one write, with no row per request. Its known
	 * edge is that a burst straddling two windows can briefly reach twice the limit, which for a
	 * booking form is harmless.
	 *
	 * The transient holds the count and the moment the window ends. The first request fixes the
	 * end and every later write keeps it, so a steady trickle cannot hold a window open for ever,
	 * and a refused client can be told exactly how long to wait.
	 *
	 * @param string $action Bucket name.
	 * @param int    $limit  Requests per window.
	 * @param int    $window Seconds.
	 *
	 * @return true|WP_Error
	 */
	private static function rate_limit( string $action, int $limit, int $window ): true|WP_Error {
		$ip = self::client_ip();

		// No address at all (CLI, a misconfigured proxy): skip the limit rather than share one
		// counter between every visitor and lock the whole site out behind it.
		if ( '' === $ip ) {
			return true;
		}

		$key    = 'plugixa_carparking_rl_' . $action . '_' . hash( 'sha256', $ip . '|' . $action );
		$now    = time();
		$stored = get_transient( $key );

		$count = is_array( $stored ) ? (int) ( $stored['count'] ?? 0 ) : 0;
		$ends  = is_array( $stored ) ? (int) ( $stored['ends'] ?? 0 ) : 0;

		// Nothing stored yet, or a window that ended on a cache that has not dropped it.
		if ( $ends <= $now ) {
			$count = 0;
			$ends  = $now + $window;
		}

		if ( $count >= $limit ) {
			return new WP_Error(
				'plugixa_carparking_rate_limited',
				__( 'Too many requests. Please wait a little while and try again.', 'plugixa-car-parking' ),
				array(
					'status'      => 429,
					'retry_after' => $ends - $now,
				)
			);
		}

		set_transient(
			$key,
			array(
				'count' => $count + 1,
				'ends'  => $ends,
			),
			$ends - $now
		);

		return true;
	}

	/**
	 * The requesting client's IP address.
	 *
	 * REMOTE_ADDR only, on purpose. `X-Forwarded-For` is attacker-controlled unless a known proxy
	 * sits in front, so trusting it by default would let anyone reset their own bucket by forging
	 * a header. A site behind a real proxy (Cloudflare, a load balancer) hooks the filter to read
	 * the header it trusts; that is a fact about the deployment this code cannot know.
	 *
	 * @return string The address, or '' if none is available.
	 */
	public static function client_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] )
			? trim( sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) )
			: '';

		if ( '' !== $ip && false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			$ip = '';
		}

		/**
		 * Filter the address a public request is attributed to.
		 *
		 * @param string $ip The REMOTE_ADDR-derived address, validated, possibly ''.
		 */
		return (string) apply_filters( 'plugixa_carparking_client_ip', $ip );
	}
}
