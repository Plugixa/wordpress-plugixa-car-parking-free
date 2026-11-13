<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Modules\Payments\Gateways;

use PlugixaCarParking\Core\Settings;
use PlugixaCarParking\Modules\Frontend\ReturnPage;
use PlugixaCarParking\Modules\Payments\Services\PaymentKey;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What every gateway shares: reading its settings, and the URLs it sends a customer to.
 */
abstract class AbstractGateway implements GatewayInterface {

	/**
	 * The REST route a gateway sends the customer's browser back to.
	 *
	 * On the namespace version 1 used, and it stays there: this URL is registered in payment
	 * providers' dashboards, where the plugin cannot update it.
	 */
	private const RETURN_ROUTE = 'plugixacarparking/v1/payment/return';

	/**
	 * @return array<string, mixed>
	 */
	protected function settings(): array {
		return Settings::all();
	}

	/**
	 * One setting, or a fallback when it has never been saved.
	 *
	 * @param string $key      Setting key.
	 * @param mixed  $fallback Returned when the key is absent.
	 */
	protected function setting( string $key, mixed $fallback = '' ): mixed {
		return Settings::get( $key, $fallback );
	}

	/**
	 * Whether this gateway's id is in the site's enabled gateways.
	 */
	public function is_enabled(): bool {
		return in_array( $this->id(), (array) $this->setting( 'enabled_gateways', array( 'offline' ) ), true );
	}

	public function is_configured(): bool {
		return true;
	}

	public function supports_online(): bool {
		return true;
	}

	/**
	 * Where the gateway redirects the customer back to.
	 *
	 * @param array<string, mixed> $booking The booking row.
	 */
	protected function return_url( array $booking ): string {
		return add_query_arg(
			array(
				'plugixa_carparking_gateway' => $this->id(),
				'booking_ref'                => $booking['booking_ref'] ?? '',
				PaymentKey::ARG              => PaymentKey::for_ref( (string) ( $booking['booking_ref'] ?? '' ) ),
			),
			rest_url( self::RETURN_ROUTE )
		);
	}

	/**
	 * The page a customer lands on once the payment has finished, either way.
	 *
	 * @param array<string, mixed> $booking The booking row.
	 * @param string               $status  'paid', 'failed', or 'cancelled' (the customer left the
	 *                                      gateway's checkout; shown as not completed).
	 */
	protected function thankyou_url( array $booking, string $status ): string {
		return add_query_arg(
			array(
				'plugixa_carparking_booking' => $booking['booking_ref'] ?? '',
				'plugixa_carparking_status'  => $status,
				PaymentKey::ARG              => PaymentKey::for_ref( (string) ( $booking['booking_ref'] ?? '' ) ),
			),
			ReturnPage::url()
		);
	}

	/**
	 * @return array{status: string}
	 */
	public function handle_return( WP_REST_Request $request ): array {
		unset( $request );

		return array( 'status' => 'failed' );
	}

	public function handle_webhook( WP_REST_Request $request ): ?array {
		unset( $request );

		return null;
	}
}
