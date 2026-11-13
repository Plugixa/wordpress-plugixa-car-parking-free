<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Modules\Bookings\Repositories;

use PlugixaCarParking\Core\Database\QueryBuilder;
use PlugixaCarParking\Core\Database\Repository;
use PlugixaCarParking\Core\Database\SlugSet;
use PlugixaCarParking\Core\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bookings.
 *
 * Holds the availability primitive the booking engine is built on. Two rules decide whether an
 * existing booking takes up a space in a requested window:
 *
 *  - only a booking whose status is in the caller's hold list consumes inventory;
 *  - a pending hold whose `hold_expires_at` has passed consumes nothing, even before the cron
 *    has flipped it to `expired`.
 *
 * A trashed booking consumes nothing either: trashing one is how an administrator gives the
 * space back.
 *
 * `hold_expires_at` IS COMPARED ON THE SITE'S CLOCK, like the entry and exit datetimes it sits
 * beside, and unlike the audit columns. See the Bookings Schema.
 */
final class BookingRepository extends Repository {

	/**
	 * The statuses a booking can be in when the site has never saved its own list.
	 *
	 * @var string[]
	 */
	public const DEFAULT_STATUSES = array( 'pending', 'confirmed', 'paid', 'completed', 'cancelled', 'refunded', 'no_show', 'expired' );

	/**
	 * What a booking's payment status can be. Each one is derived from the payments ledger.
	 *
	 * @var string[]
	 */
	public const PAYMENT_STATUSES = array( 'unpaid', 'deposit_paid', 'paid' );

	/**
	 * Every payment status a booking can be in: the plugin's own, and any a module derives.
	 *
	 * @return string[]
	 */
	public static function payment_statuses(): array {
		/**
		 * The payment statuses a booking can be in. A module that adds one also derives it, on
		 * `plugixa_carparking_paid_state`.
		 *
		 * @param string[] $statuses 'unpaid', 'deposit_paid', 'paid', and whatever modules add.
		 */
		$statuses = apply_filters( 'plugixa_carparking_payment_statuses', self::PAYMENT_STATUSES );

		return array_values( array_unique( array_merge( self::PAYMENT_STATUSES, array_filter( array_map( 'sanitize_key', is_array( $statuses ) ? $statuses : array() ) ) ) ) );
	}

	protected function table(): string {
		return 'bookings';
	}

	protected function entity_type(): string {
		return 'booking';
	}

	/**
	 * @return string[]
	 */
	protected function fillable(): array {
		return array(
			'booking_ref',
			'language_code',
			'location_id',
			'space_type_id',
			'space_id',
			'booking_form_id',
			'user_id',
			'status',
			'billing_type',
			'entry_datetime',
			'exit_datetime',
			'quantity',
			'hold_expires_at',
			'license_plate',
			'vehicle',
			'customer_name',
			'customer_email',
			'customer_phone',
			'billing',
			'currency',
			'subtotal',
			'discount_total',
			'tax_total',
			'deposit_amount',
			'total',
			'amount_paid',
			'coupon_id',
			'payment_method',
			'payment_status',
			'notes',
			'meta',
			'created_at',
			'created_by',
		);
	}

	/**
	 * @return string[]
	 */
	protected function json_columns(): array {
		return array( 'vehicle', 'billing', 'meta' );
	}

	protected function name_column(): string {
		return 'booking_ref';
	}

	/**
	 * @return array<string, string>
	 */
	protected function casts(): array {
		return array(
			'location_id'     => 'int',
			'space_type_id'   => 'int',
			'space_id'        => 'int',
			'booking_form_id' => 'int',
			'user_id'         => 'int',
			'quantity'        => 'int',
			'coupon_id'       => 'int',
			'subtotal'        => 'float',
			'discount_total'  => 'float',
			'tax_total'       => 'float',
			'deposit_amount'  => 'float',
			'total'           => 'float',
			'amount_paid'     => 'float',
		);
	}

	/**
	 * @return string[]
	 */
	protected function sortable(): array {
		return array(
			'id',
			'booking_ref',
			'status',
			'payment_status',
			'entry_datetime',
			'exit_datetime',
			'customer_name',
			'total',
			'created_at',
			'modified_at',
		);
	}

	/**
	 * The statuses a booking can be in on this site, as key => label.
	 *
	 * The list is a setting, so a site can add its own. Keys are what is stored; a label is what
	 * the site calls it.
	 *
	 * @return array<string, string>
	 */
	public function statuses(): array {
		$stored = Settings::get( 'booking_statuses' );
		$map    = array();

		foreach ( is_array( $stored ) ? $stored : array() as $key => $label ) {
			$key = sanitize_key( (string) $key );

			if ( '' !== $key ) {
				$map[ $key ] = is_scalar( $label ) && '' !== (string) $label ? (string) $label : $key;
			}
		}

		return array() !== $map ? $map : array_combine( self::DEFAULT_STATUSES, self::DEFAULT_STATUSES );
	}

	/**
	 * ENTRY AND EXIT ARE NOT MARKED AS UTC. They are the car park's wall-clock time, so a day
	 * picked in a filter is compared with them as it is.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function filterable(): array {
		$statuses = $this->statuses();

		return array(
			'status'         => array(
				'label'   => __( 'Status', 'plugixa-car-parking' ),
				'sql'     => 't.status',
				'type'    => 'select',
				'options' => array_keys( $statuses ),
				'labels'  => $statuses,
			),
			'payment_status' => array(
				'label'   => __( 'Payment status', 'plugixa-car-parking' ),
				'sql'     => 't.payment_status',
				'type'    => 'select',
				'options' => self::payment_statuses(),
			),
			'location_id'    => array(
				'label'  => __( 'Location', 'plugixa-car-parking' ),
				'sql'    => 't.location_id',
				'type'   => 'entity',
				'entity' => 'location',
			),
			'space_type_id'  => array(
				'label'  => __( 'Space type', 'plugixa-car-parking' ),
				'sql'    => 't.space_type_id',
				'type'   => 'entity',
				'entity' => 'space_type',
			),
			'entry_datetime' => array(
				'label' => __( 'Entry', 'plugixa-car-parking' ),
				'sql'   => 't.entry_datetime',
				'type'  => 'date',
			),
			'exit_datetime'  => array(
				'label' => __( 'Exit', 'plugixa-car-parking' ),
				'sql'   => 't.exit_datetime',
				'type'  => 'date',
			),
			'total'          => array(
				'label' => __( 'Total', 'plugixa-car-parking' ),
				'sql'   => 't.total',
				'type'  => 'number',
			),
		) + $this->common_filters();
	}

	/**
	 * Whether a location has ever been booked, counting trashed bookings.
	 *
	 * @param int $location_id The location.
	 */
	public function exists_for_location( int $location_id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- decides whether a location may be destroyed; a cached miss would orphan a booking made a moment ago.
		$id = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE location_id = %d LIMIT 1', $this->table_name(), $location_id ) );

		return null !== $id;
	}

	/**
	 * Every row a query matches, up to a cap, read a page at a time.
	 *
	 * For a reader that needs the whole answer and not one screen of it: a calendar month, an
	 * export. The builder returns at most 200 rows a call, so this asks again until a page comes
	 * back short.
	 *
	 * ORDERED BY ID, WHATEVER THE CALLER SET. Pages only join up without a gap or a repeat when
	 * the order is one no two rows share, and the id is the one column that guarantees it. A
	 * caller that wants another order sorts the rows it gets back.
	 *
	 * @param QueryBuilder $query     A builder from query(), already narrowed.
	 * @param int          $cap       The most rows to return.
	 * @param string       $direction ASC or DESC, by id.
	 *
	 * @return array<int, array<string, mixed>> Raw rows.
	 */
	public function drain( QueryBuilder $query, int $cap, string $direction = 'ASC' ): array {
		$rows = array();
		$page = 200;

		$query->order_by( 't.id', $direction );

		for ( $offset = 0; $offset < $cap; $offset += $page ) {
			$batch = $query->paginate( min( $page, $cap - $offset ), $offset )->get();
			$rows  = array_merge( $rows, $batch );

			if ( count( $batch ) < $page ) {
				break;
			}
		}

		return $rows;
	}

	/**
	 * One live booking by its public reference.
	 *
	 * @param string $ref Booking reference, e.g. PKG-7F3A91.
	 *
	 * @return array<string, mixed>|null
	 */
	public function find_by_ref( string $ref ): ?array {
		return $this->find_by( 'booking_ref', $ref );
	}

	/**
	 * A signed-in customer's own bookings, latest entry first.
	 *
	 * @param int $user_id The WordPress user.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function for_user( int $user_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a customer's own bookings must reflect a cancellation made a moment ago.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE user_id = %d AND deleted_at IS NULL ORDER BY entry_datetime DESC',
				$this->table_name(),
				$user_id
			),
			ARRAY_A
		);

		return array_map( array( $this, 'decode' ), (array) $rows );
	}

	/**
	 * How many spaces are held in a requested window.
	 *
	 * Interval overlap: an existing booking collides with the requested [entry, exit) window when
	 * its entry is before the requested exit AND its exit is after the requested entry. Touching
	 * edges do not collide.
	 *
	 * @param int      $location_id   The location.
	 * @param string   $entry         Requested entry, Y-m-d H:i:s.
	 * @param string   $exit          Requested exit, Y-m-d H:i:s.
	 * @param string[] $hold_statuses Statuses that consume inventory.
	 * @param int|null $space_type_id Restrict to one space type.
	 * @param int|null $space_id      Restrict to one identified space.
	 * @param int|null $exclude_id    A booking to ignore, e.g. the one being rescheduled.
	 */
	public function held_quantity(
		int $location_id,
		string $entry,
		string $exit,
		array $hold_statuses,
		?int $space_type_id = null,
		?int $space_id = null,
		?int $exclude_id = null
	): int {
		if ( array() === $hold_statuses ) {
			return 0;
		}

		global $wpdb;

		// Interval overlap, the hold statuses as one bound set, a lapsed pending hold not counted, and
		// each optional filter a gate bound to 0 when it is off (ids are positive, so 0 is never one).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- availability must reflect the live row set or a space is sold twice.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COALESCE(SUM(quantity), 0) FROM %i
				  WHERE location_id = %d AND entry_datetime < %s AND exit_datetime > %s
				    AND FIND_IN_SET( status, %s )
				    AND (hold_expires_at IS NULL OR hold_expires_at > %s) AND deleted_at IS NULL
				    AND ( %d = 0 OR id != %d )
				    AND ( %d = 0 OR space_type_id = %d )
				    AND ( %d = 0 OR space_id = %d )',
				$this->table_name(),
				$location_id,
				$exit,
				$entry,
				SlugSet::csv( $hold_statuses ),
				current_time( 'mysql' ),
				(int) $exclude_id,
				(int) $exclude_id,
				(int) $space_type_id,
				(int) $space_type_id,
				(int) $space_id,
				(int) $space_id
			)
		);
	}

	/**
	 * The identified spaces already held in a requested window.
	 *
	 * @param int      $location_id   The location.
	 * @param string   $entry         Requested entry, Y-m-d H:i:s.
	 * @param string   $exit          Requested exit, Y-m-d H:i:s.
	 * @param string[] $hold_statuses Statuses that consume inventory.
	 * @param int|null $exclude_id    A booking to ignore.
	 *
	 * @return int[]
	 */
	public function held_space_ids(
		int $location_id,
		string $entry,
		string $exit,
		array $hold_statuses,
		?int $exclude_id = null
	): array {
		if ( array() === $hold_statuses ) {
			return array();
		}

		global $wpdb;

		// The same window as held_quantity(), written out: a query handed to prepare() is one literal.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- availability must reflect the live row set or a space is sold twice.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT DISTINCT space_id FROM %i
				  WHERE location_id = %d AND entry_datetime < %s AND exit_datetime > %s
				    AND FIND_IN_SET( status, %s )
				    AND (hold_expires_at IS NULL OR hold_expires_at > %s) AND deleted_at IS NULL
				    AND ( %d = 0 OR id != %d )
				    AND space_id IS NOT NULL',
				$this->table_name(),
				$location_id,
				$exit,
				$entry,
				SlugSet::csv( $hold_statuses ),
				current_time( 'mysql' ),
				(int) $exclude_id,
				(int) $exclude_id
			)
		);

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * How many bookings still to come hold something: a location, a space type or one space.
	 *
	 * "Still to come" is a stay that has not ended, in a status that holds inventory, and not a
	 * pending hold that has lapsed.
	 *
	 * @param string   $column        `location_id`, `space_type_id` or `space_id`.
	 * @param int      $id            What they hold.
	 * @param string[] $hold_statuses Booking statuses that consume inventory.
	 */
	public function upcoming_count( string $column, int $id, array $hold_statuses ): int {
		if ( ! in_array( $column, array( 'location_id', 'space_type_id', 'space_id' ), true ) || array() === $hold_statuses ) {
			return 0;
		}

		global $wpdb;

		$now = current_time( 'mysql' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- must reflect the live row set.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE %i = %d AND exit_datetime > %s AND FIND_IN_SET( status, %s ) AND (hold_expires_at IS NULL OR hold_expires_at > %s) AND deleted_at IS NULL',
				$this->table_name(),
				$column,
				$id,
				$now,
				SlugSet::csv( $hold_statuses ),
				$now
			)
		);
	}

	/**
	 * A booking reference nothing else uses, e.g. PKG-7F3A91.
	 *
	 * Checked against trashed bookings too: the reference is a unique key, and a customer may
	 * still be holding an email that quotes a trashed one.
	 *
	 * @param string $prefix What every reference starts with.
	 */
	public function generate_ref( string $prefix = 'PKG-' ): string {
		do {
			// From a random source, not the clock, so one reference says nothing about the next.
			$ref = $prefix . strtoupper( bin2hex( random_bytes( 3 ) ) );
		} while ( null !== $this->find_by( 'booking_ref', $ref, true ) );

		return $ref;
	}

	/**
	 * Mark lapsed pending holds as expired. Run by the hourly cron.
	 *
	 * @return int How many bookings were expired.
	 */
	public function expire_pending_holds(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a bulk status change on the plugin's own table.
		return (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE %i SET status = 'expired', modified_at = %s WHERE status = 'pending' AND hold_expires_at IS NOT NULL AND hold_expires_at <= %s",
				$this->table_name(),
				current_time( 'mysql', true ),
				current_time( 'mysql' )
			)
		);
	}
}
