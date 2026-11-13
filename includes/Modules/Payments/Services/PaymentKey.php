<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Modules\Payments\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The key that shows a request comes from the customer who made a booking, as a WooCommerce
 * order key does: it is what starting a payment and the page shown on the way back ask for.
 *
 * A reference is short and printed on everything, so it proves nothing. Without this key, anyone
 * who guessed one could open a provider's checkout for the booking, which shows the customer's
 * email, or learn from the page shown after paying whether the booking is paid.
 *
 * The key is handed out once, to whoever made the booking or holds its summary link, and travels
 * from there inside the addresses given to the provider. It goes to more places than the summary
 * token does, so it is a different value: holding it opens the payment, never the summary.
 *
 * THE KEY'S DERIVATION IS FROZEN while a checkout may be open with it in its return address.
 */
final class PaymentKey {

	/**
	 * The query argument that carries the key back from a provider.
	 */
	public const ARG = 'plugixa_carparking_key';

	/**
	 * The key for one booking.
	 *
	 * @param string $ref Booking reference.
	 */
	public static function for_ref( string $ref ): string {
		return substr( hash_hmac( 'sha256', 'pcp-payment|' . $ref, wp_salt( 'auth' ) ), 0, 32 );
	}

	/**
	 * Whether a key is the right one for a booking reference.
	 *
	 * @param string $ref Booking reference.
	 * @param string $key The key the request carried.
	 */
	public static function verify( string $ref, string $key ): bool {
		return '' !== $ref && '' !== $key && hash_equals( self::for_ref( $ref ), $key );
	}
}
