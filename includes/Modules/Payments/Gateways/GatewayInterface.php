<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Modules\Payments\Gateways;

use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The contract every payment gateway implements, so the registry and the public payment flow
 * treat them all alike.
 */
interface GatewayInterface {

	/**
	 * Stable gateway id, e.g. 'offline'.
	 */
	public function id(): string;

	/**
	 * The label a customer chooses it by.
	 */
	public function label(): string;

	/**
	 * Whether the site has switched this gateway on.
	 */
	public function is_enabled(): bool;

	/**
	 * Whether it has what it needs to take a payment, such as credentials.
	 */
	public function is_configured(): bool;

	/**
	 * Whether it collects money online by sending the customer off-site.
	 */
	public function supports_online(): bool;

	/**
	 * Begin a payment for a booking.
	 *
	 * @param array<string, mixed> $booking The booking row.
	 * @param float                $amount  Amount to charge now: the total, or a part of it.
	 * @param string               $type    The payment type it is recorded as.
	 *
	 * @return array{status: string, redirect_url?: ?string, extra?: array<string, mixed>}
	 *         status is 'completed', 'redirect' or 'pending'.
	 */
	public function start( array $booking, float $amount, string $type ): array;

	/**
	 * Handle the customer's browser returning from the gateway.
	 *
	 * @param WP_REST_Request $request The return request.
	 *
	 * @return array{status: string, transaction_id?: string, amount?: float, booking_ref?: string, type?: string}
	 *         status is 'paid', 'failed', or 'processing' when the provider has no outcome yet: the
	 *         customer is told it is being confirmed, and the webhook reports how it ended.
	 */
	public function handle_return( WP_REST_Request $request ): array;

	/**
	 * Handle a notification the gateway sends server to server.
	 *
	 * `event` says what the notification reports, 'payment' when absent:
	 *  - 'payment'        money taken; recorded when `status` is 'completed'.
	 *  - 'payment_failed' an attempt that failed; recorded as a failed row, the booking unchanged.
	 *  - 'refund'         money sent back; `transaction_id` is the refund's own id.
	 *  - 'refund_failed'  a refund the provider accepted did not happen; `transaction_id` is the
	 *                     refund's own id. The ledger takes it back and it is announced as a notice.
	 *  - 'notice'         nothing for the ledger (`notice` names it, `data` details it); announced
	 *                     on `plugixa_carparking_payment_notice`.
	 *  - 'retry'          the notification could not be understood yet, the provider being out of
	 *                     reach; nothing is recorded and the gateway is asked to send it again.
	 *
	 * The booking is `booking_id`, or else the booking of the payment recorded under
	 * `parent_transaction_id` (a refund or a dispute names the payment, not the booking).
	 *
	 * A notification that reports several things answers `events`, a list of those results, applied
	 * in order. It may arrive more than once, so each must be safe to apply again.
	 *
	 * @param WP_REST_Request $request The webhook request.
	 *
	 * @return array{event?: string, events?: array<int, array<string, mixed>>, booking_id?: int, parent_transaction_id?: string, status?: string, transaction_id?: string, amount?: float, type?: string, notice?: string, data?: array<string, mixed>}|null
	 *         A normalised result, or null to ignore the notification.
	 */
	public function handle_webhook( WP_REST_Request $request ): ?array;
}
