<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Core\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A list of slugs as ONE bound value, for `FIND_IN_SET( column, %s )`.
 *
 * wordpress.org's review wants the string handed to `$wpdb->prepare()` to be a single literal, so a
 * run of placeholders sized to the list cannot be glued into it. A set whose length is not fixed —
 * statuses and payment types are both editable — is therefore bound as one comma-joined string.
 * {@see IdList} is the answer for ids, where the length can be padded; this is the answer for slugs.
 *
 * A member containing a comma would read as two members, so the list is cleaned with
 * `sanitize_key()`, which never leaves one. An empty set binds `''`, which matches nothing — the
 * same answer `IN ()` would give if it were valid SQL.
 *
 * THIS RETURNS A VALUE, NEVER SQL.
 */
final class SlugSet {

	/**
	 * The slugs as one comma-joined value.
	 *
	 * @param array<mixed> $slugs The members. Non-strings and empties are dropped.
	 */
	public static function csv( array $slugs ): string {
		$clean = array();

		foreach ( $slugs as $slug ) {
			if ( ! is_string( $slug ) ) {
				continue;
			}

			$slug = sanitize_key( $slug );

			if ( '' !== $slug ) {
				$clean[ $slug ] = $slug;
			}
		}

		return implode( ',', $clean );
	}
}
