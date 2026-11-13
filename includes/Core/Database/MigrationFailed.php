<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Core\Database;

use RuntimeException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A table a migration was told to create does not exist afterwards.
 *
 * Thrown by {@see Schema::delta()}, caught per module by `ModuleRegistry::migrate()`, and written
 * down by `Activator::migrate()` so `Admin\MigrationNotice` can say which table and why.
 */
final class MigrationFailed extends RuntimeException {

	/**
	 * @param string[] $tables The tables that are missing.
	 * @param string   $reason What the database said, as it said it. Empty when it said nothing.
	 */
	public function __construct( private readonly array $tables, private readonly string $reason ) {
		parent::__construct(
			'Could not create ' . implode( ', ', $tables ) . ( '' === $reason ? '.' : ': ' . $reason )
		);
	}

	/**
	 * The tables that are missing.
	 *
	 * @return string[]
	 */
	public function tables(): array {
		return $this->tables;
	}

	/**
	 * What the database said.
	 */
	public function reason(): string {
		return $this->reason;
	}
}
