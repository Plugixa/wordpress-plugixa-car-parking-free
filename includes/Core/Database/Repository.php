<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Core\Database;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Base repository. Every record type gets one.
 *
 * Beyond CRUD, this class is the single place that fires the entity-lifecycle hooks.
 * That centralisation is deliberate and load-bearing: because REST writes, mass actions,
 * the public booking flow and premium code all go through a repository, a listener hooked
 * here sees *every* change to *every* record.
 *
 * Subclasses supply the table and the entity slug; the hooks come for free.
 *
 * CONTRACT: every table backing a Repository must have these columns, because this
 * class writes them unconditionally —
 *   deleted_at  datetime DEFAULT NULL   (soft delete; KEY it)
 *   created_at, modified_at  datetime
 *   created_by, modified_by  bigint(20) unsigned
 *
 * A table without them — a log, a child collection replaced as a set — is not a record and
 * does not get a Repository; it gets a plain table gateway in its module.
 *
 * THE AUDIT COLUMNS ARE UTC. `created_at` and `modified_at` say when the ROW changed and are
 * written with `current_time( 'mysql', true )`. A record's own datetimes — a booking's entry and
 * exit, a coupon's validity — are the car park's wall-clock time and are the caller's to supply.
 * The two kinds are stored differently on purpose.
 */
abstract class Repository {

	/**
	 * Bare table name, e.g. "bookings".
	 */
	abstract protected function table(): string;

	/**
	 * Entity slug used in hook payloads, e.g. "booking".
	 */
	abstract protected function entity_type(): string;

	/**
	 * Columns that may be written. Anything else in the payload is dropped.
	 *
	 * @return string[]
	 */
	abstract protected function fillable(): array;

	/**
	 * Columns stored as JSON. Encoded on the way in and decoded on the way out, so callers work
	 * with arrays and never see the string.
	 *
	 * @return string[]
	 */
	protected function json_columns(): array {
		return array();
	}

	/**
	 * Columns a client must receive as a number or a boolean, mapped to 'int', 'float' or 'bool'.
	 *
	 * MySQL hands every column back as a string, and "0" is truthy in JavaScript: a client testing
	 * a flag or an id to decide what to show is right about the rows that have one and wrong about
	 * the rest. `id`, `created_by` and `modified_by` are always cast; a repository lists the others.
	 *
	 * @return array<string, string>
	 */
	protected function casts(): array {
		return array();
	}

	/**
	 * A row as a client should receive it: JSON columns as arrays, numbers as numbers.
	 *
	 * A NULL stays NULL. "No space type" and "space type 0" are different answers, and so are
	 * "no limit" and "a limit of 0".
	 *
	 * @param array<string, mixed> $row A row, raw or already decoded.
	 *
	 * @return array<string, mixed>
	 */
	final public function cast( array $row ): array {
		$row = $this->decode( $row );

		$casts = $this->casts() + array(
			'id'          => 'int',
			'created_by'  => 'int',
			'modified_by' => 'int',
		);

		foreach ( $casts as $column => $type ) {
			if ( ! isset( $row[ $column ] ) ) {
				continue;
			}

			$row[ $column ] = match ( $type ) {
				'int'   => (int) $row[ $column ],
				'float' => (float) $row[ $column ],
				'bool'  => (bool) (int) $row[ $column ],
				default => $row[ $column ],
			};
		}

		return $row;
	}

	/**
	 * Columns that may be sorted on. Guards against SQL injection via ?orderby=.
	 *
	 * @return string[]
	 */
	protected function sortable(): array {
		return array( 'id', 'created_at', 'modified_at' );
	}

	/**
	 * WHAT A LIST MAY BE FILTERED BY. The twin of sortable(), and for the same reason.
	 *
	 * `prepare()` binds values, never columns, so the only safe way to let a request narrow a list
	 * is to have it send a KEY that is looked up here and never a column that is used. An unknown
	 * key is a 400, not a query.
	 *
	 * Each entry is `key => array( label, sql, type, options?, labels?, entity?, stored? )`:
	 *   sql     a developer-written fragment, always `t.<column>`, never touched by the request
	 *   type    one of the filter operator types — decides which operators are offered
	 *   options for `select`, a LIST OF VALUES — the client labels them
	 *   labels  optional value => label map, only for options that are rows the user created
	 *   entity  for `entity`, the slug the id points at, so the client knows what to pick from
	 *   stored  `utc` for a column written in UTC, so a day picked by the user is shifted to match
	 *
	 * Default is empty, deliberately: a repository opts in by overriding, and gets
	 * `common_filters()` to avoid retyping the two every record shares.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function filterable(): array {
		return array();
	}

	/**
	 * The two filters every record shares.
	 *
	 * `'stored' => 'utc'` IS NOT DECORATION. These columns are written in UTC, while a date picker
	 * sends the day the user is living in. Without the flag, "created on the 4th" on a UTC+2 site
	 * would silently mean 02:00 on the 4th to 01:59 on the 5th.
	 *
	 * A RECORD'S OWN DATETIMES MUST NOT CARRY THIS FLAG. A booking that enters on the 31st enters
	 * on the 31st wherever you read it from.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	final protected function common_filters(): array {
		return array(
			'created_at'  => array(
				'label'  => __( 'Created', 'plugixa-car-parking' ),
				'sql'    => 't.created_at',
				'type'   => 'date',
				'stored' => 'utc',
			),
			'modified_at' => array(
				'label'  => __( 'Modified', 'plugixa-car-parking' ),
				'sql'    => 't.modified_at',
				'type'   => 'date',
				'stored' => 'utc',
			),
		);
	}

	final protected function table_name(): string {
		return Schema::table( $this->table() );
	}

	/**
	 * The column holding a record's display name. Override where it is not `name`.
	 */
	protected function name_column(): string {
		return 'name';
	}

	/**
	 * The display names of some records, keyed by id, in one query.
	 *
	 * Trashed rows are resolved too, deliberately: a row pointing at a trashed parent should render
	 * the parent's name and let the reader see what happened, not silently show "—" as though the
	 * link had never existed.
	 *
	 * @param int[] $ids Records to resolve.
	 *
	 * @return array<int, string> Keyed by record id.
	 */
	public function names( array $ids ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );

		if ( ! $ids ) {
			return array();
		}

		global $wpdb;

		$table  = $this->table_name();
		$column = $this->name_column();

		$names = array();

		// The query text never varies: exactly IdList::CHUNK placeholders, always bound, however
		// many ids were asked for. {@see IdList} for why the list is batched and padded rather
		// than interpolated.
		foreach ( IdList::chunks( $ids ) as $batch ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, so there is no object cache to read from.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT id, %i AS display_name FROM %i
					  WHERE id IN (%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d,%d)',
					$column,
					$table,
					$batch[0], $batch[1], $batch[2], $batch[3], $batch[4],
					$batch[5], $batch[6], $batch[7], $batch[8], $batch[9],
					$batch[10], $batch[11], $batch[12], $batch[13], $batch[14],
					$batch[15], $batch[16], $batch[17], $batch[18], $batch[19],
				),
				ARRAY_A
			);

			foreach ( (array) $rows as $row ) {
				$names[ (int) $row['id'] ] = (string) $row['display_name'];
			}
		}

		return $names;
	}

	/**
	 * Find one live row by primary key.
	 *
	 * Soft-deleted rows are invisible here by default, so no caller can leak a trashed
	 * record by forgetting the trash exists. Pass $with_trashed to reach one — restore()
	 * and purge() do.
	 *
	 * @param int  $id           Row id.
	 * @param bool $with_trashed Include a soft-deleted row.
	 *
	 * @return array<string, mixed>|null
	 */
	public function find( int $id, bool $with_trashed = false ): ?array {
		global $wpdb;

		$table = $this->table_name();

		// Two shapes, two literal statements: prepare() can only be audited when the
		// statement it receives is visible at the call site.
		if ( $with_trashed ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $table, $id ), ARRAY_A );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d AND deleted_at IS NULL', $table, $id ), ARRAY_A );
		}

		return is_array( $row ) ? $this->decode( $row ) : null;
	}

	/**
	 * Find one live row by the value of a single column.
	 *
	 * For the natural keys a record is looked up by from outside: a booking's reference, a
	 * coupon's code, a form's slug.
	 *
	 * @param string $column       Column name. A literal from the caller, never request data.
	 * @param string $value        Value to match.
	 * @param bool   $with_trashed Include a soft-deleted row.
	 *
	 * @return array<string, mixed>|null
	 */
	public function find_by( string $column, string $value, bool $with_trashed = false ): ?array {
		global $wpdb;

		$table = $this->table_name();

		if ( $with_trashed ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE %i = %s LIMIT 1', $table, $column, $value ), ARRAY_A );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE %i = %s AND deleted_at IS NULL LIMIT 1', $table, $column, $value ), ARRAY_A );
		}

		return is_array( $row ) ? $this->decode( $row ) : null;
	}

	/**
	 * A slug no other row of this table uses, made from a name or a requested slug.
	 *
	 * TRASHED ROWS COUNT AS TAKEN. A slug column carries a unique key, and the database cannot
	 * tell a trashed row from a live one, so a slug only becomes free again when its row is
	 * purged. Checking live rows alone would hand out a slug the insert then fails on.
	 *
	 * @param string $base       What to make the slug from.
	 * @param int    $exclude_id The row being updated, so it does not collide with itself.
	 * @param string $column     The slug column.
	 */
	public function unique_slug( string $base, int $exclude_id = 0, string $column = 'slug' ): string {
		$slug = sanitize_title( $base );

		if ( '' === $slug ) {
			$slug = $this->entity_type();
		}

		// Leave room for a "-99" suffix inside the column, so the suffixed slug is not truncated
		// back into the one it was meant to differ from.
		$slug      = substr( $slug, 0, 180 );
		$candidate = $slug;
		$suffix    = 2;

		while ( true ) {
			$taken = $this->find_by( $column, $candidate, true );

			if ( null === $taken || (int) $taken['id'] === $exclude_id ) {
				return $candidate;
			}

			$candidate = $slug . '-' . $suffix;
			++$suffix;
		}
	}

	/**
	 * A query builder scoped to this entity, with the ACL seam already wired.
	 *
	 * Rows it returns are raw: pass each through decode() if the JSON columns are needed.
	 *
	 * @param string $context Query context.
	 */
	public function query( string $context = 'list' ): QueryBuilder {
		return new QueryBuilder( $this->table_name(), $this->entity_type(), $context );
	}

	/**
	 * Insert a row.
	 *
	 * @param array<string, mixed> $data Column => value.
	 *
	 * @return int The new row id, or 0 on failure.
	 */
	public function insert( array $data ): int {
		global $wpdb;

		$entity = $this->entity_type();

		/**
		 * Filter an entity's data immediately before insert.
		 *
		 * @param array<string, mixed> $data   Sanitised, fillable-filtered payload.
		 * @param string               $entity Entity slug.
		 */
		$data = (array) apply_filters( 'plugixa_carparking_before_insert', $this->only_fillable( $data ), $entity );

		$data['created_at']  = $data['created_at'] ?? current_time( 'mysql', true );
		$data['modified_at'] = $data['created_at'];
		$data['created_by']  = $data['created_by'] ?? get_current_user_id();
		$data['modified_by'] = $data['created_by'];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
		$ok = $wpdb->insert( $this->table_name(), $this->encode( $data ) );
		if ( ! $ok ) {
			return 0;
		}

		$id = (int) $wpdb->insert_id;

		/**
		 * Fires after any entity is created.
		 *
		 * @param string               $entity Entity slug.
		 * @param int                  $id     New row id.
		 * @param array<string, mixed> $data   The inserted data.
		 */
		do_action( 'plugixa_carparking_entity_created', $entity, $id, $data );

		return $id;
	}

	/**
	 * Update a row.
	 *
	 * @param int                  $id   Row id.
	 * @param array<string, mixed> $data Column => value.
	 */
	public function update( int $id, array $data ): bool {
		global $wpdb;

		$old = $this->find( $id );
		if ( null === $old ) {
			return false;
		}

		$entity = $this->entity_type();

		/**
		 * Filter an entity's data immediately before update.
		 *
		 * @param array<string, mixed> $data   Sanitised, fillable-filtered payload.
		 * @param string               $entity Entity slug.
		 * @param int                  $id     Row id.
		 * @param array<string, mixed> $old    The row as it currently stands.
		 */
		$data = (array) apply_filters( 'plugixa_carparking_before_update', $this->only_fillable( $data ), $entity, $id, $old );

		$data['modified_at'] = current_time( 'mysql', true );
		$data['modified_by'] = get_current_user_id();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
		$result = $wpdb->update( $this->table_name(), $this->encode( $data ), array( 'id' => $id ) );
		if ( false === $result ) {
			return false;
		}

		// One event per changed field, so a listener can react to "status became paid" without
		// reconstructing it from a whole-row diff.
		foreach ( $data as $field => $new_value ) {
			if ( ! array_key_exists( $field, $old ) || is_array( $new_value ) || is_array( $old[ $field ] ) ) {
				continue;
			}

			if ( (string) $old[ $field ] !== (string) $new_value ) {
				/**
				 * Fires for each individual scalar field that changed on an entity.
				 *
				 * @param string $entity    Entity slug.
				 * @param int    $id        Row id.
				 * @param string $field     Column name.
				 * @param mixed  $new_value New value.
				 * @param mixed  $old_value Previous value.
				 */
				do_action( 'plugixa_carparking_entity_field_changed', $entity, $id, $field, $new_value, $old[ $field ] );
			}
		}

		/**
		 * Fires after any entity is updated.
		 *
		 * @param string               $entity Entity slug.
		 * @param int                  $id     Row id.
		 * @param array<string, mixed> $data   The applied changes.
		 * @param array<string, mixed> $old    The row before the update.
		 */
		do_action( 'plugixa_carparking_entity_updated', $entity, $id, $data, $old );

		return true;
	}

	/**
	 * Move a row to the trash.
	 *
	 * Records are soft-deleted. A hard DELETE on a location would orphan every booking ever made
	 * there, with no way back — and an accidental click is not worth that. purge() is the
	 * deliberate, irreversible one.
	 *
	 * @param int $id Row id.
	 */
	public function delete( int $id ): bool {
		global $wpdb;

		$old = $this->find( $id );
		if ( null === $old ) {
			return false;
		}

		$entity = $this->entity_type();

		/**
		 * Veto a trash. Return anything but `true` to refuse.
		 *
		 * THE ONE PLACE "THIS CANNOT BE DELETED" IS ENFORCED, rather than in each service that
		 * happens to remember — a mass action or a module written next year all reach this method.
		 *
		 * A FILTER CANNOT CARRY A MESSAGE, so this is the backstop and not the explanation. The
		 * service that knows why produces the wording and the HTTP status; this makes sure that
		 * even a caller that skipped the service cannot get past it.
		 *
		 * @param bool                 $allowed Whether the trash may proceed.
		 * @param string               $entity  Entity slug.
		 * @param int                  $id      Row id.
		 * @param array<string, mixed> $old     The row about to be trashed.
		 */
		$allowed = apply_filters( 'plugixa_carparking_can_delete', true, $entity, $id, $old );

		if ( true !== $allowed ) {
			return false;
		}

		/**
		 * Fires before an entity is trashed.
		 *
		 * @param string               $entity Entity slug.
		 * @param int                  $id     Row id.
		 * @param array<string, mixed> $old    The row about to be trashed.
		 */
		do_action( 'plugixa_carparking_before_delete', $entity, $id, $old );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
		$result = $wpdb->update(
			$this->table_name(),
			array( 'deleted_at' => current_time( 'mysql', true ) ),
			array( 'id' => $id )
		);

		if ( false === $result ) {
			return false;
		}

		/**
		 * Fires after an entity is trashed. The row still exists and can be restored.
		 *
		 * @param string               $entity Entity slug.
		 * @param int                  $id     Row id.
		 * @param array<string, mixed> $old    The trashed row.
		 */
		do_action( 'plugixa_carparking_entity_deleted', $entity, $id, $old );

		return true;
	}

	/**
	 * Bring a row back out of the trash.
	 *
	 * @param int $id Row id.
	 */
	public function restore( int $id ): bool {
		global $wpdb;

		$row = $this->find( $id, true );
		if ( null === $row || null === $row['deleted_at'] ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
		$result = $wpdb->update(
			$this->table_name(),
			array( 'deleted_at' => null ),
			array( 'id' => $id )
		);

		if ( false === $result ) {
			return false;
		}

		/**
		 * Fires after a trashed entity is restored.
		 *
		 * @param string               $entity Entity slug.
		 * @param int                  $id     Row id.
		 * @param array<string, mixed> $row    The restored row.
		 */
		do_action( 'plugixa_carparking_entity_restored', $this->entity_type(), $id, $row );

		return true;
	}

	/**
	 * Delete a row permanently. There is no way back from here.
	 *
	 * @param int $id Row id.
	 */
	public function purge( int $id ): bool {
		global $wpdb;

		$row = $this->find( $id, true );
		if ( null === $row ) {
			return false;
		}

		$entity = $this->entity_type();

		/**
		 * Veto a permanent delete. Return anything but `true` to refuse.
		 *
		 * Separate from `plugixa_carparking_can_delete`, because the two questions differ: a
		 * booking may be trashed at any time and may not be destroyed while money is recorded
		 * against it.
		 *
		 * @param bool                 $allowed Whether the purge may proceed.
		 * @param string               $entity  Entity slug.
		 * @param int                  $id      Row id.
		 * @param array<string, mixed> $row     The row about to be destroyed.
		 */
		$allowed = apply_filters( 'plugixa_carparking_can_purge', true, $entity, $id, $row );

		if ( true !== $allowed ) {
			return false;
		}

		/**
		 * Fires before an entity is permanently deleted. Listeners should clean up anything
		 * that references it — this is the last moment the row exists.
		 *
		 * @param string               $entity Entity slug.
		 * @param int                  $id     Row id.
		 * @param array<string, mixed> $row    The row about to be destroyed.
		 */
		do_action( 'plugixa_carparking_before_purge', $entity, $id, $row );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no object cache to read from.
		$ok = (bool) $wpdb->delete( $this->table_name(), array( 'id' => $id ) );
		if ( ! $ok ) {
			return false;
		}

		/**
		 * Fires after an entity is permanently deleted.
		 *
		 * @param string               $entity Entity slug.
		 * @param int                  $id     Row id.
		 * @param array<string, mixed> $row    The destroyed row.
		 */
		do_action( 'plugixa_carparking_entity_purged', $entity, $id, $row );

		return true;
	}

	/**
	 * Refuse text its column cannot hold, naming the field — before `$wpdb` refuses it without a word.
	 *
	 * `wpdb::insert()` checks every value against its column and, when one is too long, returns
	 * false with nothing but a line in the database error log. A caller turns that into "could not
	 * be saved" with status 500, so a typo-level mistake by a client reads as a server fault and
	 * the form has no field to point at.
	 *
	 * The limit is the database's own, read through `wpdb::get_col_length()`, so it cannot drift
	 * from the schema the way a limit copied into PHP would.
	 *
	 * @param array<string, mixed> $data Column => value, sanitized.
	 */
	final public function too_long( array $data ): ?WP_Error {
		global $wpdb;

		foreach ( $this->only_fillable( $data ) as $column => $value ) {
			if ( ! is_string( $value ) || '' === $value ) {
				continue;
			}

			$limit = $wpdb->get_col_length( $this->table_name(), $column );

			if ( ! is_array( $limit ) || empty( $limit['length'] ) ) {
				continue;
			}

			$length = 'byte' === ( $limit['type'] ?? '' ) ? strlen( $value ) : mb_strlen( $value, 'UTF-8' );

			if ( $length > (int) $limit['length'] ) {
				return new WP_Error(
					'plugixa_carparking_too_long',
					sprintf(
						/* translators: %d: the maximum number of characters the field holds. */
						__( 'This is too long: at most %d characters.', 'plugixa-car-parking' ),
						(int) $limit['length']
					),
					array(
						'status' => 422,
						'field'  => $column,
						'max'    => (int) $limit['length'],
					)
				);
			}
		}

		return null;
	}

	/**
	 * Turn a raw row's JSON columns into arrays.
	 *
	 * Public because a row fetched through query() is raw, and the caller decides whether the
	 * cost of decoding a whole page is worth paying.
	 *
	 * @param array<string, mixed> $row A row as the database returned it.
	 *
	 * @return array<string, mixed>
	 */
	final public function decode( array $row ): array {
		foreach ( $this->json_columns() as $column ) {
			if ( isset( $row[ $column ] ) && is_string( $row[ $column ] ) && '' !== $row[ $column ] ) {
				$row[ $column ] = json_decode( $row[ $column ], true );
			}
		}

		return $row;
	}

	/**
	 * Turn the JSON columns of a payload into strings for storage.
	 *
	 * @param array<string, mixed> $data Column => value.
	 *
	 * @return array<string, mixed>
	 */
	final protected function encode( array $data ): array {
		foreach ( $this->json_columns() as $column ) {
			if ( array_key_exists( $column, $data ) && null !== $data[ $column ] && ! is_string( $data[ $column ] ) ) {
				$data[ $column ] = wp_json_encode( $data[ $column ] );
			}
		}

		return $data;
	}

	/**
	 * Drop any key that is not a writable column.
	 *
	 * @param array<string, mixed> $data Raw payload.
	 *
	 * @return array<string, mixed>
	 */
	final protected function only_fillable( array $data ): array {
		return array_intersect_key( $data, array_flip( $this->fillable() ) );
	}

	/**
	 * Resolve a requested sort column against the allow-list.
	 *
	 * @param string $requested Column asked for by the client.
	 */
	final public function safe_sort_column( string $requested ): string {
		return in_array( $requested, $this->sortable(), true ) ? $requested : 'id';
	}
}
