<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Modules\Frontend;

use PlugixaCarParking\Core\Settings;
use PlugixaCarParking\Modules\Payments\Services\PaymentKey;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The page a customer is sent back to once a payment made on another site has finished.
 *
 * That page has to be one that can say how the payment went. The home page cannot, and a customer
 * who has just paid and lands on a home page with nothing about their booking on it has no way to
 * tell whether the money was taken. So the page is looked for, in this order:
 *
 * 1. The booking page chosen in the settings.
 * 2. The booking summary page chosen in the settings.
 * 3. The first published page that holds the booking form, as a shortcode or as the block.
 * 4. The home page, when the site has none of them.
 *
 * The booking form and the summary both show the payment's result to somebody who arrives with
 * the two arguments Shortcodes::RETURN_STATUS and Shortcodes::RETURN_BOOKING in the address.
 */
final class ReturnPage {

	/**
	 * Remembers the page found by the third step, so each returning customer does not cost a
	 * search of every page's content. Holds a page id, or 0 for "looked, and there is none".
	 */
	public const CACHE = 'plugixa_carparking_form_page';

	public static function register_hooks(): void {
		// A page that gains or loses the form changes the answer, so the answer is dropped
		// whenever a page is saved, trashed or deleted.
		add_action( 'save_post_page', array( self::class, 'forget' ) );
		add_action( 'deleted_post', array( self::class, 'forget' ) );

		add_filter( 'wp_robots', array( self::class, 'robots' ) );

		// The same addresses are one customer's, so no page cache may keep them either.
		add_action( 'template_redirect', array( PrivateResponse::class, 'guard' ) );
	}

	/**
	 * Whether this request's address names a booking, or carries the key or token for one.
	 *
	 * Only whether the arguments are there is read, never what they say: an address that
	 * carries a wrong token is still one customer's guess, and is treated as private too.
	 */
	public static function names_booking(): bool {
		$args = array( Shortcodes::RETURN_STATUS, Shortcodes::RETURN_BOOKING, PaymentKey::ARG, 'plugixa_carparking_ref', 'plugixa_carparking_token' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only whether the arguments are there is read.
		return (bool) array_intersect( $args, array_keys( $_GET ) );
	}

	/**
	 * Keep a booking's own pages out of search engines.
	 *
	 * An address that names a booking, or carries the key or token for one, is one customer's,
	 * and the summary page shows nothing to anybody else. None of it belongs in an index, an
	 * archive, or a crawler's list of links to follow.
	 *
	 * @param array<string, bool|string> $robots The robots directives WordPress is printing.
	 *
	 * @return array<string, bool|string>
	 */
	public static function robots( array $robots ): array {
		$summary = (int) Settings::get( 'summary_page_id', 0 );

		if ( ! self::names_booking() &&! ( $summary > 0 && is_page( $summary ) ) ) {
			return $robots;
		}

		unset( $robots['index'], $robots['follow'], $robots['max-image-preview'] );

		$robots['noindex']   = true;
		$robots['nofollow']  = true;
		$robots['noarchive'] = true;

		return $robots;
	}

	public static function forget(): void {
		delete_transient( self::CACHE );
	}

	/**
	 * The address of the page to send a returning customer to, without any arguments.
	 */
	public static function url(): string {
		foreach ( array( 'booking_page_id', 'summary_page_id' ) as $setting ) {
			$url = self::address( (int) Settings::get( $setting, 0 ) );

			if ( '' !== $url ) {
				return $url;
			}
		}

		$url = self::address( self::form_page() );

		return '' !== $url ? $url : home_url( '/' );
	}

	/**
	 * The address of the page that holds the booking form, or an empty string when the site has
	 * none: the chosen booking page, or else the first page found to hold the form.
	 */
	public static function form_url(): string {
		$url = self::address( (int) Settings::get( 'booking_page_id', 0 ) );

		return '' !== $url ? $url : self::address( self::form_page() );
	}

	/**
	 * The first published page that holds the booking form, or 0.
	 */
	public static function form_page(): int {
		$cached = get_transient( self::CACHE );

		// A remembered page is checked again before it is used: its content may have been
		// changed by something that does not announce a save.
		if ( is_numeric( $cached ) && ( 0 === (int) $cached || self::holds_form( (int) $cached ) ) ) {
			return (int) $cached;
		}

		$found = self::search();

		// "There is none" is remembered for an hour only, so a form placed by something that
		// does not save a page the ordinary way is found without anyone clearing a cache.
		set_transient( self::CACHE, $found, $found ? WEEK_IN_SECONDS : HOUR_IN_SECONDS );

		return $found;
	}

	/**
	 * Look through the published pages for one that holds the form.
	 */
	private static function search(): int {
		global $wpdb;

		// The two patterns narrow the search in the database. They also match the summary and
		// account shortcodes, whose tags begin the same way, so each page is checked afterwards.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a search of page content WP_Query cannot express; form_page() caches the answer.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM %i WHERE post_type = 'page' AND post_status = 'publish' AND post_password = '' AND ( post_content LIKE %s OR post_content LIKE %s ) ORDER BY ID ASC LIMIT 50",
				$wpdb->posts,
				'%' . $wpdb->esc_like( '[' . Shortcodes::WIZARD ) . '%',
				'%' . $wpdb->esc_like( '<!-- wp:' . BookingFormBlock::NAME ) . '%'
			)
		);

		foreach ( (array) $ids as $id ) {
			if ( self::holds_form( (int) $id ) ) {
				return (int) $id;
			}
		}

		return 0;
	}

	/**
	 * Whether a page is published, open to everyone and holds the booking form.
	 *
	 * @param int $id Page id.
	 */
	private static function holds_form( int $id ): bool {
		$post = get_post( $id );

		if ( ! $post instanceof \WP_Post || 'page' !== $post->post_type || 'publish' !== $post->post_status || '' !== (string) $post->post_password ) {
			return false;
		}

		return has_shortcode( (string) $post->post_content, Shortcodes::WIZARD ) || has_block( BookingFormBlock::NAME, $post );
	}

	/**
	 * A published page's address, or an empty string for a page that is not there to be shown.
	 *
	 * @param int $id Page id, or 0.
	 */
	private static function address( int $id ): string {
		if ( $id <= 0 || 'publish' !== get_post_status( $id ) ) {
			return '';
		}

		$url = get_permalink( $id );

		return is_string( $url ) ? $url : '';
	}
}
