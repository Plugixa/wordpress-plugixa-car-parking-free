<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Modules\Payments\Gateways;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pay on arrival. Nothing is charged online: the booking stays unpaid and the customer is shown
 * the site's instructions for paying at the car park.
 */
final class OfflineGateway extends AbstractGateway {

	public function id(): string {
		return 'offline';
	}

	public function label(): string {
		return __( 'Pay on arrival', 'plugixa-car-parking' );
	}

	public function supports_online(): bool {
		return false;
	}

	/**
	 * @return array{status: string, redirect_url: null, extra: array{instructions: string}}
	 */
	public function start( array $booking, float $amount, string $type ): array {
		unset( $booking, $amount, $type );

		return array(
			'status'       => 'pending',
			'redirect_url' => null,
			'extra'        => array(
				'instructions' => (string) $this->setting( 'offline_instructions', '' ),
			),
		);
	}
}
