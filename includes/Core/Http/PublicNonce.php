<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Core\Http;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lets a signed-out visitor's public nonce outlive the page cache that serves it.
 *
 * ════════════════════════════════════════════════════════════════════════════════════════
 * THE BOOKING FORM IS ON A CACHEABLE PAGE, AND A DAY-OLD COPY OF IT COULD NOT BOOK.
 *
 * The wizard, the summary and the account page print a nonce for PublicController::NONCE_ACTION
 * into the page, and every state-changing public route verifies it. WordPress's nonce verifies
 * for 12 to 24 hours; a page cache keeps the page for as long as it is configured to (seven
 * days by default on LiteSpeed). So a visitor served a copy more than a day old filled in the
 * whole wizard and was told "Your session expired. Please refresh", and refreshing served them
 * the same cached copy, with the same dead nonce.
 *
 * Lengthening it for THIS action and a SIGNED-OUT visitor weakens nothing. A signed-out
 * visitor's nonce has no user and no session in it, so it is the same value for every visitor
 * and sits in the cached HTML for anybody to read: it shows a request came from one of this
 * site's pages, and was never a secret that a forged request lacked. What protects the public
 * routes is said beside each one (rate limit, honeypot, payment key, reference + email).
 * Signed-in users are unchanged: page caches do not serve them, and their nonce is the one
 * with a user and a session in it.
 *
 * Hooked on every request, not only on public pages: the nonce is minted while a page renders
 * and verified during a REST request, and both have to agree on how long it lives.
 *
 * `max()`, so a site that has already lengthened nonces everywhere is not shortened here.
 * WordPress passes the action to `nonce_life` since 6.1; the floor is 6.5.
 * ════════════════════════════════════════════════════════════════════════════════════════
 *
 * Same rule, same length, as plugixa-exam's `FormHandler::guest_gate_nonce_life()`.
 */
final class PublicNonce {

	/**
	 * How long a signed-out visitor's public nonce verifies, in seconds.
	 */
	public const LIFE = 60 * DAY_IN_SECONDS;

	public static function register_hooks(): void {
		add_filter( 'nonce_life', array( self::class, 'life' ), 10, 2 );
	}

	/**
	 * @param int|mixed  $life   Seconds, as filtered so far.
	 * @param string|int $action The nonce action.
	 */
	public static function life( $life, $action = -1 ): int {
		if ( PublicController::NONCE_ACTION !== $action || is_user_logged_in() ) {
			return (int) $life;
		}

		return max( (int) $life, self::LIFE );
	}
}
