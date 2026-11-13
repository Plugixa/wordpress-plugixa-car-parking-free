<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * An amount, written the way the site writes money.
 *
 * IN CORE BECAUSE IT MUST EXIST EXACTLY ONCE. The booking form and the account page write an
 * amount in the browser, from the settings `money()` in assets/frontend/js/core.js is handed.
 * Whatever PHP prints (the summary page, an email, a report) is read beside those, so it follows
 * the same five settings in the same way: the symbol, the side it sits on, the two separators and
 * the number of decimals. A formatter private to each service is how an invoice came to say
 * "$1,234.50" under a booking form that said "1.234,50 €".
 *
 * The rules, which are the script's:
 *
 * - The thousands separator may be empty, and the digits are then not grouped.
 * - With no decimals there is no decimal separator either.
 * - `left_space` and `right_space` put one space between the symbol and the number. It is a
 *   no-break space, so an amount is never split across two lines.
 * - A negative amount carries a minus sign (U+2212) in front of everything, symbol included. An
 *   amount that rounds to zero carries none.
 */
final class Money {

	/**
	 * What separates the symbol from the number on the two spaced sides: a no-break space.
	 */
	private const SPACE = "\u{00A0}";

	/**
	 * Format an amount.
	 *
	 * @param float       $amount The amount, in the site's currency.
	 * @param string|null $symbol A symbol to use in place of the site's. A record that keeps the
	 *                            currency it was charged in passes its own.
	 */
	public static function format( float $amount, ?string $symbol = null ): string {
		$decimals = max( 0, (int) Settings::get( 'decimals', 2 ) );
		$symbol   = $symbol ?? (string) Settings::get( 'currency_symbol', '$' );

		$number = number_format(
			abs( $amount ),
			$decimals,
			(string) Settings::get( 'decimal_separator', '.' ),
			(string) Settings::get( 'thousand_separator', ',' )
		);

		// Decided on the rounded amount, so that -0.001 is written "0.00" and not "−0.00".
		$sign = $amount < 0 && 0.0 !== round( $amount, $decimals ) ? "\u{2212}" : '';

		switch ( (string) Settings::get( 'currency_position', 'left' ) ) {
			case 'right':
				return $sign . $number . $symbol;

			case 'left_space':
				return $sign . $symbol . self::SPACE . $number;

			case 'right_space':
				return $sign . $number . self::SPACE . $symbol;

			default:
				return $sign . $symbol . $number;
		}
	}

	/**
	 * A change to a price, with its direction written out: "−€8.00" or "+€8.00".
	 *
	 * An amount on its own reads as a price. One that is added to or taken off another has to say
	 * which, and an increase carries no sign unless it is given one.
	 *
	 * @param float       $amount The change, negative for a reduction.
	 * @param string|null $symbol A symbol to use in place of the site's.
	 */
	public static function signed( float $amount, ?string $symbol = null ): string {
		$formatted = self::format( $amount, $symbol );

		return $amount > 0 && self::format( 0.0, $symbol ) !== $formatted ? '+' . $formatted : $formatted;
	}
}
