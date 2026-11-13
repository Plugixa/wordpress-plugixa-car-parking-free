<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Modules\Payments\Services;

use PlugixaCarParking\Modules\Payments\Gateways\GatewayInterface;
use PlugixaCarParking\Modules\Payments\Gateways\OfflineGateway;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The payment gateways this site has.
 *
 * The plugin ships one, pay on arrival. Any other is supplied by whatever registers on the action
 * below; there is no list here of gateways the plugin does not itself implement.
 */
final class GatewayRegistry {

	/**
	 * Registered gateways, keyed by id.
	 *
	 * @var array<string, GatewayInterface>
	 */
	private array $gateways = array();

	/**
	 * Whether add-ons have been asked for theirs yet.
	 */
	private bool $collected = false;

	public function __construct( OfflineGateway $offline ) {
		$this->register( $offline );
	}

	public function register( GatewayInterface $gateway ): void {
		$this->gateways[ $gateway->id() ] = $gateway;
	}

	/**
	 * One gateway by id.
	 *
	 * @param string $id Gateway id.
	 */
	public function get( string $id ): ?GatewayInterface {
		return $this->all()[ $id ] ?? null;
	}

	/**
	 * Every registered gateway.
	 *
	 * @return array<string, GatewayInterface>
	 */
	public function all(): array {
		$this->collect();

		return $this->gateways;
	}

	/**
	 * The gateways a customer can pay with: switched on, and configured.
	 *
	 * @return array<int, array{id: string, label: string, online: bool}>
	 */
	public function available(): array {
		$available = array();

		foreach ( $this->all() as $gateway ) {
			if ( $gateway->is_enabled() && $gateway->is_configured() ) {
				$available[] = array(
					'id'     => $gateway->id(),
					'label'  => $gateway->label(),
					'online' => $gateway->supports_online(),
				);
			}
		}

		return $available;
	}

	/**
	 * Ask add-ons for their gateways, the first time anything needs the list.
	 *
	 * Deferred to first use rather than done in the constructor, so the action fires after every
	 * module has had the chance to attach to it, whatever order they were discovered in.
	 */
	private function collect(): void {
		if ( $this->collected ) {
			return;
		}

		$this->collected = true;

		/**
		 * Register additional payment gateways.
		 *
		 * @param GatewayRegistry $registry Call $registry->register( $gateway ).
		 */
		do_action( 'plugixa_carparking_register_gateways', $this );
	}
}
