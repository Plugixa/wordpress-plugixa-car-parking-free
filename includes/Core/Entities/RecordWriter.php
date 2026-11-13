<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Core\Entities;

use PlugixaCarParking\Core\Security\Capabilities;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creating and updating a record whose type is only known at runtime.
 *
 * THE WRITE TWIN OF {@see RecordLoader}, and the seam a generic writer such as {@see MassAction}
 * needs. Such a caller is handed an entity slug and a set of values; it has to write a record without
 * knowing what kind of record that is, and every module keeps its own service with nothing else
 * indexing them.
 *
 * IT GOES THROUGH SERVICES, NOT REPOSITORIES, AND THAT IS THE ENTIRE POINT. A repository insert is a
 * row; a service call is the business logic — validation, sanitisation, derived columns like a
 * booking's reference or a location's slug, and every lifecycle event a listener depends on. A
 * generic writer that wrote rows directly would produce records that look right in a list and are
 * missing everything a record created through the UI has. `Repository` and `RestController` both
 * carry docblocks promising that business logic lives in services precisely so that every caller
 * reaches the same code; this is what makes that promise keepable.
 *
 * A MODULE OPTS IN. Registering an entity in `plugixa_carparking_entity_types` says "this thing exists";
 * registering a writer says "and it can be written to generically". The two are separate because some
 * entities should not be: a payment is money that was taken or refunded, and manufacturing one from
 * a generic write would be inventing history that never happened.
 */
final class RecordWriter {

	/** @var array<string, array{create?: callable, update?: callable}>|null */
	private ?array $writers = null;

	public function __construct( private readonly EntityRegistry $entities ) {}

	/**
	 * Every entity that can be written generically, as slug => label.
	 *
	 * What a picker of writable entities is built from — an entity with no writer simply is not
	 * offered, rather than being offered and then failing on the first row.
	 *
	 * @return array<string, string>
	 */
	public function writable(): array {
		$out = array();

		foreach ( array_keys( $this->all() ) as $slug ) {
			$descriptor = $this->entities->get( $slug );

			if ( null !== $descriptor ) {
				$out[ $slug ] = (string) ( $descriptor['plural'] ?? $descriptor['label'] ?? $slug );
			}
		}

		asort( $out );

		return $out;
	}

	/**
	 * Whether this entity can be written generically.
	 *
	 * @param string $entity_type Entity slug.
	 */
	public function knows( string $entity_type ): bool {
		return isset( $this->all()[ $entity_type ]['create'] );
	}

	/**
	 * Create one record.
	 *
	 * THE PERMISSION CHECK IS HERE, not only in the caller. This is a generic write path open to
	 * any caller, and a check that lives in one caller is a check the second caller forgets.
	 *
	 * @param string               $entity_type Entity slug.
	 * @param array<string, mixed> $data        Field values.
	 *
	 * @return int|WP_Error The new id.
	 */
	public function create( string $entity_type, array $data ): int|WP_Error {
		$writer = $this->all()[ $entity_type ]['create'] ?? null;

		if ( null === $writer ) {
			return $this->cannot( $entity_type );
		}

		if ( ! Capabilities::user_can( 'create', $entity_type ) ) {
			return $this->forbidden();
		}

		$result = $writer( $data );

		return is_int( $result ) || $result instanceof WP_Error
			? $result
			: new WP_Error( 'plugixa_carparking_write_failed', __( 'The record could not be created.', 'plugixa-car-parking' ) );
	}

	/**
	 * Update one record.
	 *
	 * @param string               $entity_type Entity slug.
	 * @param int                  $id          Record id.
	 * @param array<string, mixed> $data        Field values.
	 *
	 * @return true|WP_Error
	 */
	public function update( string $entity_type, int $id, array $data ): true|WP_Error {
		$writer = $this->all()[ $entity_type ]['update'] ?? null;

		if ( null === $writer ) {
			return $this->cannot( $entity_type );
		}

		if ( ! Capabilities::user_can( 'edit', $entity_type ) ) {
			return $this->forbidden();
		}

		$result = $writer( $id, $data );

		return true === $result || $result instanceof WP_Error
			? $result
			: new WP_Error( 'plugixa_carparking_write_failed', __( 'The record could not be updated.', 'plugixa-car-parking' ) );
	}

	/**
	 * Trash one record.
	 *
	 * TRASH, NOT PURGE, and the registered callable is the repository's `delete()` for exactly that
	 * reason. Its caller is a mass delete, and two hundred bookings deleted by mistake have to be
	 * recoverable — which they are from the trash and are not from a purge.
	 *
	 * @param string $entity_type Entity slug.
	 * @param int    $id          Record id.
	 */
	public function delete( string $entity_type, int $id ): bool {
		$writer = $this->all()[ $entity_type ]['delete'] ?? null;

		if ( null === $writer || ! Capabilities::user_can( 'delete', $entity_type ) ) {
			return false;
		}

		$result = $writer( $id );

		/*
		 * A REFUSAL IS NOT A SUCCESS, AND `(bool) $wp_error` IS `true`.
		 *
		 * A registered `delete` callable may be a service method returning `bool|WP_Error`, and a
		 * WP_Error is an object — so the obvious `return (bool) $writer( $id );` would report every
		 * refused delete as done. Where a service refuses to delete a location that still has
		 * bookings, a mass delete of two hundred such locations would answer `done: 200, failed: 0`
		 * and remove nothing.
		 *
		 * {@see MassAction::run()} tests `$result instanceof WP_Error || false === $result`, and its
		 * `failed` count is only as honest as this return value: a false success here would leave
		 * the records in place while telling the user they had been removed.
		 */
		return ! $result instanceof WP_Error && (bool) $result;
	}

	/**
	 * The registered writers.
	 *
	 * @return array<string, array{create?: callable, update?: callable}>
	 */
	private function all(): array {
		if ( null !== $this->writers ) {
			return $this->writers;
		}

		/**
		 * Register generic write access to an entity.
		 *
		 * Each entry is keyed by entity slug and holds `create` and `update` callables — in practice
		 * the module's own service methods, which is what keeps a generic write and the REST API on
		 * one code path.
		 *
		 * @param array<string, array{create?: callable, update?: callable}> $writers Slug => callables.
		 */
		$writers = (array) apply_filters( 'plugixa_carparking_record_writers', array() );

		// An entity that is not registered cannot be written, whatever a filter says: without a
		// descriptor there is no field list to validate against and no ACL scope to check.
		foreach ( array_keys( $writers ) as $slug ) {
			if ( ! $this->entities->has( (string) $slug ) ) {
				unset( $writers[ $slug ] );
			}
		}

		$this->writers = $writers;

		return $this->writers;
	}

	/**
	 * @param string $entity_type Entity slug.
	 */
	private function cannot( string $entity_type ): WP_Error {
		return new WP_Error(
			'plugixa_carparking_not_writable',
			sprintf(
				/* translators: %s: an entity slug, e.g. "booking". */
				__( 'Records of type "%s" cannot be changed this way.', 'plugixa-car-parking' ),
				$entity_type
			),
			array( 'status' => 422 )
		);
	}

	private function forbidden(): WP_Error {
		return new WP_Error(
			'plugixa_carparking_forbidden',
			__( 'You do not have permission to save that kind of record.', 'plugixa-car-parking' ),
			array( 'status' => 403 )
		);
	}
}
