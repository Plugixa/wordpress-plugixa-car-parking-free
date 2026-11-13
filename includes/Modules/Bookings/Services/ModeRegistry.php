<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Modules\Bookings\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The inventory modes this site can use, and the strategy behind each.
 *
 * Which modes exist is decided by which strategies are registered, and nothing else: there is no
 * list of modes anywhere that a strategy has to be on.
 */
final class ModeRegistry {

	/**
	 * Resolved once per request.
	 *
	 * @var array<string, InventoryStrategy>|null
	 */
	private ?array $strategies = null;

	public function __construct( private readonly CapacityStrategy $capacity ) {}

	/**
	 * Every registered strategy, keyed by the mode it answers for.
	 *
	 * @return array<string, InventoryStrategy>
	 */
	public function strategies(): array {
		if ( null === $this->strategies ) {
			/**
			 * Register further ways of counting a location's spaces.
			 *
			 * @param array<string, InventoryStrategy> $strategies Inventory mode => strategy.
			 */
			$registered = (array) apply_filters(
				'plugixa_carparking_inventory_strategies',
				array( CapacityStrategy::MODE => $this->capacity )
			);

			$this->strategies = array_filter(
				$registered,
				static fn( $strategy ): bool => $strategy instanceof InventoryStrategy
			);

			// The pool strategy is what every unrecognised mode falls back to, so it cannot be
			// filtered away.
			$this->strategies[ CapacityStrategy::MODE ] ??= $this->capacity;
		}

		return $this->strategies;
	}

	/**
	 * The strategy for a location's mode.
	 *
	 * A mode nothing answers for is counted as a pool. That is what version 1 did for every mode
	 * but one, and it keeps a location bookable if the add-on that provided its mode is removed.
	 *
	 * @param string $mode The location's inventory_mode.
	 */
	public function strategy( string $mode ): InventoryStrategy {
		return $this->strategies()[ $mode ] ?? $this->capacity;
	}

	/**
	 * Whether a mode has a strategy of its own.
	 *
	 * @param string $mode Inventory mode.
	 */
	public function supports( string $mode ): bool {
		return isset( $this->strategies()[ $mode ] );
	}

	/**
	 * Whether any registered mode holds a booking to one particular space.
	 */
	public function identifies_spaces(): bool {
		foreach ( $this->strategies() as $strategy ) {
			if ( $strategy->identifies_spaces() ) {
				return true;
			}
		}

		return false;
	}
}
