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
	 * `label` is what the customer reads. A gateway may word it differently from its admin name
	 * with an optional `customer_label()`: "Pay online" where the admin needs the provider's name
	 * to tell two card gateways apart. `icon` is a neutral glyph (card, wallet, cash, invoice) from an
	 * optional `icon()`. `logos` is empty unless the filter below adds image URLs, such as the
	 * official card marks a site has the right to show.
	 *
	 * @return array<int, array{id: string, label: string, online: bool, icon: string, logos: string[]}>
	 */
	public function available(): array {
		$available = array();

		foreach ( $this->all() as $gateway ) {
			if ( $gateway->is_enabled() && $gateway->is_configured() ) {
				$icon = method_exists( $gateway, 'icon' ) ? (string) $gateway->icon() : '';

				/**
				 * Logo images shown beside a payment method on the booking form.
				 *
				 * Empty by default: the plugin ships no brand marks. A site adds the official ones
				 * it is entitled to show, e.g. its card schemes' acceptance marks.
				 *
				 * @param string[] $logos   Image URLs.
				 * @param string   $gateway The gateway's id.
				 */
				$logos = (array) apply_filters( 'plugixa_carparking_payment_logos', array(), $gateway->id() );

				$available[] = array(
					'id'     => $gateway->id(),
					'label'  => method_exists( $gateway, 'customer_label' ) ? (string) $gateway->customer_label() : $gateway->label(),
					'online' => $gateway->supports_online(),
					'icon'   => in_array( $icon, array( 'card', 'wallet', 'cash', 'invoice' ), true ) ? $icon : ( $gateway->supports_online() ? 'card' : 'cash' ),
					'logos'  => array_values( array_filter( array_map( 'esc_url_raw', array_map( 'strval', $logos ) ) ) ),
				);
			}
		}

		return $this->disambiguate( $available );
	}

	/**
	 * Give two methods that would read the same to the customer their full names instead.
	 *
	 * Two online gateways can both word themselves "Pay online". Shown side by side, the customer
	 * cannot tell them apart, so each method whose short label is shared falls back to its admin
	 * name, which names the provider.
	 *
	 * @param array<int, array{id: string, label: string, online: bool, icon: string, logos: string[]}> $available The methods on offer.
	 * @return array<int, array{id: string, label: string, online: bool, icon: string, logos: string[]}>
	 */
	private function disambiguate( array $available ): array {
		$counts = array_count_values( array_column( $available, 'label' ) );

		foreach ( $available as $index => $method ) {
			if ( ( $counts[ $method['label'] ] ?? 0 ) > 1 && isset( $this->gateways[ $method['id'] ] ) ) {
				$available[ $index ]['label'] = $this->gateways[ $method['id'] ]->label();
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
