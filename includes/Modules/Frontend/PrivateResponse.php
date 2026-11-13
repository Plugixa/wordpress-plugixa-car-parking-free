<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Modules\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Marks a response as belonging to one customer, so no page cache stores it.
 *
 * ════════════════════════════════════════════════════════════════════════════════════════
 * A BOOKING'S OWN PAGES SENT NO CACHE INSTRUCTION.
 *
 * The summary link (`?plugixa_carparking_ref=…&plugixa_carparking_token=…`) renders one
 * customer's booking on the server — their name, email, stay and what they owe — and the
 * payment-return screen (`?plugixa_carparking_status=…&plugixa_carparking_booking=…`) renders
 * how their payment went. Both told search engines to stay away (ReturnPage::robots()) and
 * told caches nothing. A cache that keys on the whole address only hands the copy to people
 * who already hold the link, but it still shows a payment as unpaid after it was paid; and a
 * cache set to ignore query strings would hand one customer's booking to the next visitor.
 *
 * Headers alone do not stop that. `nocache_headers()` is what a proxy or a CDN reads, but the
 * caching PLUGINS decide in PHP, and the signal they share is the `DONOTCACHEPAGE` constant:
 * WP Super Cache, W3 Total Cache, WP Rocket and LiteSpeed Cache all honour it. So this sends
 * both, from `template_redirect`, before the first byte of the page is written.
 * ════════════════════════════════════════════════════════════════════════════════════════
 *
 * ⚠️ MARK ONLY WHAT IS PRIVATE. The booking form, the summary page with nothing in its address
 * and the account page's shell are the same for every signed-out visitor, and stay cacheable:
 * marking them would switch caching off for the audience it exists for. The account page
 * fills itself over REST, and signed-in visitors are not served from a page cache.
 *
 * Same contract as plugixa-exam's `Core\Frontend\PrivateResponse`.
 */
final class PrivateResponse {

	/**
	 * How many times this request was marked. Read by the test suite.
	 */
	private static int $marks = 0;

	/**
	 * Keep this response out of every page cache.
	 */
	public static function mark(): void {
		++self::$marks;

		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- the page-caching plugins' shared constant; it only works under this exact name.
			define( 'DONOTCACHEPAGE', true );
		}

		// Returns without a word when headers are already out; the constant above still holds.
		nocache_headers();
	}

	/**
	 * On `template_redirect`: mark the page when its address names a booking.
	 */
	public static function guard(): void {
		if ( ReturnPage::names_booking() ) {
			self::mark();
		}
	}

	/**
	 * How many times this request was marked.
	 *
	 * @internal For the test suite.
	 */
	public static function marks(): int {
		return self::$marks;
	}

	/**
	 * Forget previous marks.
	 *
	 * @internal For the test suite. The constant cannot be undefined, and does not need to be:
	 *           nothing caches a command-line process.
	 */
	public static function reset(): void {
		self::$marks = 0;
	}
}
