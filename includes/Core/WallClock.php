<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Core;

use DateTimeImmutable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The formats a browser sends, turned into the format MySQL stores.
 *
 * IN CORE BECAUSE IT MUST EXIST EXACTLY ONCE. A parser private to each service gets rewritten per
 * service, and the easy rewrite — a `strlen() === 16` check plus a regex — accepts `Y-m-d H:i` and
 * `Y-m-d H:i:s` and **silently nulls a bare `Y-m-d`**.
 *
 * That mistake is invisible from the browser wherever the form sends `datetime-local`. It is only
 * reachable through the REST API — where a client sending a bare `2026-09-30`, which is a legitimate
 * date, gets a stored NULL and a 200. "A format the form never sends" is not a reason to refuse it:
 * the form is not the only caller.
 *
 * So: one owner, and the rule stated once.
 */
final class WallClock {

	/**
	 * Accept a date or a datetime; store a datetime.
	 *
	 * A browser's `datetime-local` yields "2026-09-30T14:00", and a date input yields "2026-09-30". Both
	 * are legitimate; neither is a MySQL datetime. A bare date means midnight — `!` resets the fields the
	 * format does not mention, so "2026-09-30" becomes "2026-09-30 00:00:00" rather than today's time on
	 * that date, which is what the same call without `!` would silently give.
	 *
	 * A value in no known format returns null rather than a guess: a wrong date that looks right is worse
	 * than an empty one, and `'30/09/2026'` is not a format this API accepts.
	 *
	 * @param mixed $value Raw input.
	 */
	public static function datetime( mixed $value ): ?string {
		$value = is_string( $value ) ? trim( $value ) : '';

		if ( '' === $value ) {
			return null;
		}

		$value = str_replace( 'T', ' ', $value );

		foreach ( array( 'Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d' ) as $format ) {
			$parsed = DateTimeImmutable::createFromFormat( '!' . $format, $value );

			if ( false !== $parsed ) {
				return $parsed->format( 'Y-m-d H:i:s' );
			}
		}

		return null;
	}

	/**
	 * Is this a real Y-m-d date? Used to validate a calendar range's bounds.
	 *
	 * The round-trip comparison is the point: `createFromFormat` is lenient and rolls "2026-02-31" over to
	 * March 3rd rather than failing, so a plain `false !== $parsed` would call an impossible date valid.
	 *
	 * @param string $value A Y-m-d date.
	 */
	/**
	 * How far ahead of UTC the site is, right now, in seconds.
	 *
	 * ═══════════════════════════════════════════════════════════════════════════════════════════════
	 * FOR GROUPING A UTC COLUMN INTO THE READER'S DAYS, AND FOR NOTHING ELSE.
	 *
	 * Audit columns are written UTC (`current_time( 'mysql', true )`), so `DATE( created_at )` cuts
	 * the day at UTC midnight. On a Tokyo site that is 9am local: everything between midnight and
	 * 9am is filed under yesterday, and a daily chart is wrong for a third of every day. Adding this
	 * offset before taking `DATE()` moves the cut to local midnight.
	 *
	 * WHY NOT `CONVERT_TZ`. It is the textbook answer and it returns NULL — silently, for every row
	 * — on any MySQL whose timezone tables have not been loaded, which is the default on a stock
	 * install and normal on shared hosting. A report that renders empty on some hosts and not others
	 * is a worse failure than the one being fixed. A bound integer of seconds works everywhere.
	 *
	 * THE LIMIT, STATED RATHER THAN HIDDEN: this is ONE offset, read once per request, applied to
	 * every row. Over a range that crosses a daylight-saving boundary the rows on the far side are
	 * bucketed an hour out, which can move a booking made near midnight into the neighbouring day.
	 * Being exact would mean `CONVERT_TZ` and its NULLs, or grouping in PHP and giving up the LIMIT
	 * that keeps these queries bounded. The trade is deliberate: an hour of edge error twice a year,
	 * against nine hours of error every day.
	 *
	 * Not the same thing as `FilterCompiler::bound()`, which converts ONE instant and so can afford
	 * to be DST-exact. This one has to be a constant inside a GROUP BY.
	 * ═══════════════════════════════════════════════════════════════════════════════════════════════
	 */
	public static function utc_offset(): int {
		return (int) wp_timezone()->getOffset( new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ) );
	}

	public static function is_date( string $value ): bool {
		$parsed = DateTimeImmutable::createFromFormat( '!Y-m-d', $value );

		return false !== $parsed && $parsed->format( 'Y-m-d' ) === $value;
	}
}
