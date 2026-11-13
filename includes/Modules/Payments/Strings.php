<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Modules\Payments;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The strings of the screens under `src/modules/payments/`, translated.
 *
 * Every key those screens ask for with `t( key, fallback )` has an entry here, with the same
 * English. A key that is missing still shows, in English, in every language: that is the
 * failure this list exists to prevent.
 */
final class Strings {

	/**
	 * @return array<string, string> Key => translated text.
	 */
	public static function all(): array {
		return array(
			'payments'                    => __( 'Payments', 'plugixa-car-parking' ),
			'payment_record'              => __( 'Record payment', 'plugixa-car-parking' ),
			'payment_summary_total'       => __( 'Booking total', 'plugixa-car-parking' ),
			'payment_summary_paid'        => __( 'Paid', 'plugixa-car-parking' ),
			'payment_summary_refunded'    => __( 'Refunded', 'plugixa-car-parking' ),
			'payment_summary_due'         => __( 'Balance due', 'plugixa-car-parking' ),
			'payment_none_for_booking'    => __( 'Nothing has been paid on this booking yet.', 'plugixa-car-parking' ),
			'payment_date'                => __( 'Date', 'plugixa-car-parking' ),
			'payment_method'              => __( 'Method', 'plugixa-car-parking' ),
			'payment_type'                => __( 'Type', 'plugixa-car-parking' ),
			'payment_amount'              => __( 'Amount', 'plugixa-car-parking' ),
			'payment_status'              => __( 'Status', 'plugixa-car-parking' ),
			'payment_transaction'         => __( 'Transaction ID', 'plugixa-car-parking' ),
			'payment_refund'              => __( 'Refund', 'plugixa-car-parking' ),
			'payment_record_intro'        => __( 'For money taken outside the booking form, such as cash on arrival or a bank transfer.', 'plugixa-car-parking' ),
			'payment_record_balance_hint' => __( '{amount} is still owed on this booking.', 'plugixa-car-parking' ),
			'payment_refund_intro'        => __( 'Records a refund against the payment of {amount} by {method}. It is added to the ledger as a row of its own; the payment stays as it is.', 'plugixa-car-parking' ),
			'payment_refund_nothing'      => __( 'This booking holds no money to refund.', 'plugixa-car-parking' ),
			'payment_refund_max_hint'     => __( 'Up to {max} can be refunded on this booking.', 'plugixa-car-parking' ),
			'payment_refund_confirm'      => __( 'Record refund', 'plugixa-car-parking' ),
			'payment_booking'             => __( 'Booking', 'plugixa-car-parking' ),
			'search_payments'             => __( 'Search by transaction ID…', 'plugixa-car-parking' ),
			'no_payments'                 => __( 'No payments yet', 'plugixa-car-parking' ),
			'no_payments_hint'            => __( 'Every payment and refund recorded against a booking is listed here. Record one from the booking it belongs to.', 'plugixa-car-parking' ),
			'payment_amount_not_positive' => __( 'The amount must be more than zero.', 'plugixa-car-parking' ),
			'payment_refund_too_large'    => __( 'A refund cannot be more than the booking has been paid ({max}).', 'plugixa-car-parking' ),
			'payment_amount_invalid'      => __( 'A number, with a dot for decimals.', 'plugixa-car-parking' ),
			'payment_type_refund'         => __( 'Refund', 'plugixa-car-parking' ),
			'payment_type_payment'        => __( 'Payment', 'plugixa-car-parking' ),
		);
	}
}
