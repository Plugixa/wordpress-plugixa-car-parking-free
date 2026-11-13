<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Core\Entities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The catalogue of car-park entities and their fields.
 *
 * This is the highest-leverage seam in the plugin. Anything that needs to reason
 * about "what entities exist and what fields do they have" reads it: the filter
 * drawer, the generic record loader and writer behind mass actions, and any add-on
 * that works on records generically.
 *
 * Because the catalogue is a filter, a module registers a whole new entity with one
 * call and immediately inherits everything that reads the catalogue, without writing
 * any of it. That is what keeps a module small.
 */
final class EntityRegistry {

	/**
	 * @var array<string, array<string, mixed>>|null
	 */
	private ?array $entities = null;

	/**
	 * Every registered entity, keyed by slug.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function all(): array {
		if ( null !== $this->entities ) {
			return $this->entities;
		}

		/**
		 * Register car-park entities.
		 *
		 * Each entry is keyed by slug and describes the entity to the generic UI and
		 * to any consumer that reasons about entities generically.
		 *
		 * @param array<string, array<string, mixed>> $entities Entity slug => descriptor.
		 */
		$entities = (array) apply_filters( 'plugixa_carparking_entity_types', array() );

		foreach ( $entities as $slug => $descriptor ) {
			/**
			 * Filter the field definitions of a single car-park entity.
			 *
			 * Lets a module add computed or rolled-up fields to an entity it does not
			 * own — e.g. a "Bookings today" field on a location.
			 *
			 * @param array<string, mixed> $fields Field definitions.
			 * @param string               $slug   Entity slug.
			 */
			$entities[ $slug ]['fields'] = (array) apply_filters(
				'plugixa_carparking_entity_fields',
				$descriptor['fields'] ?? array(),
				$slug
			);
		}

		$this->entities = $entities;

		return $this->entities;
	}

	/**
	 * One entity descriptor.
	 *
	 * @param string $slug Entity slug.
	 *
	 * @return array<string, mixed>|null
	 */
	public function get( string $slug ): ?array {
		return $this->all()[ $slug ] ?? null;
	}

	/**
	 * Whether an entity is registered.
	 *
	 * @param string $slug Entity slug.
	 */
	public function has( string $slug ): bool {
		return isset( $this->all()[ $slug ] );
	}
}
