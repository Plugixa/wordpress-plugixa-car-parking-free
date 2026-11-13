<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Core\Entities;

use PlugixaCarParking\Core\Database\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Load any record, given only its entity slug and its id.
 *
 * WHY THIS EXISTS, AND WHY IT IS IN CORE
 *
 * A cross-cutting module — mass actions, record-level access, anything that treats records
 * generically — works on a record it does not own and must not depend on the module that does.
 * Acting on a booking cannot mean importing the bookings repository, or the two modules are
 * welded together and neither can move.
 *
 * But authorisation needs the record. Capabilities::user_can( 'edit', 'booking', $record ) is
 * how an add-on that scopes records answers "may you edit THIS one", and a caller that hands it
 * null behaves identically without one while silently granting an "own records only" user the
 * right to edit every booking in the database.
 *
 * So the lookup has to exist, it has to be generic, and it cannot live in a leaf module. It sits
 * next to EntityRegistry because that is the thing that knows what an entity slug means.
 *
 * THE ASSUMPTION, STATED OUT LOUD
 *
 * A descriptor carries `api_base` ("bookings"), not a table name, and the lookup assumes the
 * table is the api_base with its hyphens turned into underscores. That is a convention, not a
 * contract — nothing enforces it, and a module could register an entity where it does not hold,
 * in which case a permission check quietly reads nothing.
 *
 * The `plugixa_carparking_load_record` filter is the escape hatch for an entity that needs one.
 */
final class RecordLoader {

	public function __construct( private readonly EntityRegistry $entities ) {}

	/**
	 * The record, or null if there is no such entity type or no such row.
	 *
	 * @param string $entity_type Entity slug, e.g. "booking".
	 * @param int    $id          Record id.
	 *
	 * @return array<string, mixed>|null
	 */
	public function load( string $entity_type, int $id ): ?array {
		$record = null;

		$descriptor = $this->entities->get( $entity_type );

		if ( null !== $descriptor && $id > 0 ) {
			$record = $this->from_table( (string) ( $descriptor['api_base'] ?? '' ), $id );
		}

		/**
		 * Filters the record a cross-cutting module loaded for an authorisation check.
		 *
		 * The default lookup assumes an entity's table is named after its api_base. An entity
		 * that does not follow that convention must answer here, or record-level permissions
		 * will not see it.
		 *
		 * @param array<string, mixed>|null $record      The record, or null.
		 * @param string                    $entity_type Entity slug.
		 * @param int                       $id          Record id.
		 */
		return apply_filters( 'plugixa_carparking_load_record', $record, $entity_type, $id );
	}

	/**
	 * The bare table name an api_base refers to.
	 *
	 * ═══════════════════════════════════════════════════════════════════════════════════════════
	 * ONE `str_replace`, AND WITHOUT IT A TWO-WORD ENTITY IS UNLOADABLE.
	 *
	 * An api_base is a REST path segment and a table name is an identifier, and they are spelled
	 * differently the moment an entity has a two-word name: the route is `/location-spaces`
	 * because that is how a URL reads, and the table is `location_spaces` because MySQL names in
	 * this plugin never carry hyphens. Using the api_base verbatim works for every single-word
	 * entity — bookings, locations, coupons, payments — and misses the rest.
	 *
	 * WHY NOTHING WOULD SHOW IT: `from_table()` answers null when the table does not exist,
	 * because a record that cannot be found is deliberately indistinguishable from one the user
	 * may not see. The caller passes that null on to `Capabilities::user_can()`, so a record-level
	 * rule is asked to judge a record it was never given. While authorisation is all-or-nothing
	 * and never looks at the record, nothing behaves differently — it becomes a permission hole
	 * precisely when an add-on that scopes records is installed, which is the worst possible time
	 * to find it.
	 *
	 * PUBLIC AND STATIC so a test can assert the mapping directly rather than inferring it from a
	 * null return, which is the shape that hides this.
	 * ═══════════════════════════════════════════════════════════════════════════════════════════
	 *
	 * @param string $api_base The descriptor's api_base, e.g. "location-spaces".
	 */
	public static function table_for( string $api_base ): string {
		return str_replace( '-', '_', $api_base );
	}

	/**
	 * @param string $table Bare table name, or the api_base it is derived from.
	 * @param int    $id    Record id.
	 *
	 * @return array<string, mixed>|null
	 */
	private function from_table( string $table, int $id ): ?array {
		global $wpdb;

		$table = self::table_for( $table );

		if ( '' === $table || ! Schema::exists( $table ) ) {
			return null;
		}

		$name = Schema::table( $table );

		// The table name is bound as an identifier, and it never comes from the request anyway:
		// it comes from a registered descriptor and is checked to exist first.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d AND deleted_at IS NULL LIMIT 1', $name, $id ),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}
}
