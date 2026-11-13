<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Modules\Payments\Gateways;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A gateway that can send money back through the provider.
 *
 * A refund of a payment taken by such a gateway is made at the provider first, and recorded with
 * the provider's id for it. The provider then reports the same refund to the webhook, and the
 * ledger recognises the id and records it once.
 */
interface RefundingGatewayInterface {

	/**
	 * Refund part or all of a payment at the provider.
	 *
	 * @param array<string, mixed> $payment         The ledger row of the payment being refunded.
	 * @param float                $amount          How much to refund, in the payment's currency.
	 * @param string               $idempotency_key The same key for a retry of the same refund.
	 *
	 * @return string|WP_Error The provider's id for the refund.
	 */
	public function refund( array $payment, float $amount, string $idempotency_key ): string|WP_Error;
}
