<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Modules\Frontend;

use PlugixaCarParking\Core\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What a customer reads in place of a status the database stores.
 *
 * A booking's status and its payment status are stored as short English slugs. A public page
 * shows the label instead: translated, and in the site's own wording where it has chosen one.
 */
final class Labels {

	/**
	 * A booking status's label, by slug.
	 *
	 * The site can rename its statuses in the settings. A name it has changed is the site's own
	 * wording and is shown as written; one it has left alone is shown translated.
	 *
	 * @return array<string, string>
	 */
	public static function booking(): array {
		$english = array(
			'pending'   => 'Pending',
			'confirmed' => 'Confirmed',
			'paid'      => 'Paid',
			'completed' => 'Completed',
			'cancelled' => 'Cancelled',
			'refunded'  => 'Refunded',
			'no_show'   => 'No-show',
			'expired'   => 'Expired',
		);

		$labels = array(
			'pending'   => __( 'Pending', 'plugixa-car-parking' ),
			'confirmed' => __( 'Confirmed', 'plugixa-car-parking' ),
			'paid'      => __( 'Paid', 'plugixa-car-parking' ),
			'completed' => __( 'Completed', 'plugixa-car-parking' ),
			'cancelled' => __( 'Cancelled', 'plugixa-car-parking' ),
			'refunded'  => __( 'Refunded', 'plugixa-car-parking' ),
			'no_show'   => __( 'No-show', 'plugixa-car-parking' ),
			'expired'   => __( 'Expired', 'plugixa-car-parking' ),
		);

		$stored = Settings::get( 'booking_statuses', array() );

		foreach ( is_array( $stored ) ? $stored : array() as $slug => $label ) {
			if ( ! is_string( $slug ) || ! is_string( $label ) || '' === trim( $label ) ) {
				continue;
			}

			if ( ! isset( $english[ $slug ] ) || $english[ $slug ] !== $label ) {
				$labels[ $slug ] = $label;
			}
		}

		return self::filtered( $labels, 'booking' );
	}

	/**
	 * A payment status's label, by slug.
	 *
	 * @return array<string, string>
	 */
	public static function payment(): array {
		return self::filtered(
			array(
				'unpaid'       => __( 'Unpaid', 'plugixa-car-parking' ),
				'partial'      => __( 'Part paid', 'plugixa-car-parking' ),
				// The status the ledger gives a booking some money has been paid on.
				'deposit_paid' => __( 'Part paid', 'plugixa-car-parking' ),
				'paid'         => __( 'Paid', 'plugixa-car-parking' ),
				'refunded'     => __( 'Refunded', 'plugixa-car-parking' ),
			),
			'payment'
		);
	}

	/**
	 * The label of one status, or the slug made readable when nothing names it.
	 *
	 * @param array<string, string> $labels booking() or payment().
	 * @param string                $status The stored slug.
	 */
	public static function of( array $labels, string $status ): string {
		return $labels[ $status ] ?? ucfirst( str_replace( array( '_', '-' ), ' ', $status ) );
	}

	/**
	 * @param array<string, string> $labels Slug => label.
	 * @param string                $kind   'booking' or 'payment'.
	 *
	 * @return array<string, string>
	 */
	private static function filtered( array $labels, string $kind ): array {
		/**
		 * The labels a public page shows for stored statuses.
		 *
		 * @param array<string, string> $labels Slug => label.
		 * @param string                $kind   'booking' or 'payment'.
		 */
		$filtered = apply_filters( 'plugixa_carparking_status_labels', $labels, $kind );

		return is_array( $filtered ) ? array_filter( $filtered, 'is_string' ) : $labels;
	}
}
