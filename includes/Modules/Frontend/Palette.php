<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Modules\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The colours that have to be worked out from a form's one primary colour.
 *
 * The stylesheet derives every tint and shade of the primary colour itself, with color-mix().
 * What it cannot do in every browser is decide: whether text on the primary colour should be
 * white or dark, and how far the colour has to be darkened, or lightened, before it can be used
 * as text. Those are thresholds, and they are worked out here against the WCAG contrast formula
 * and handed to the page as custom properties.
 *
 * A pale colour is the case this exists for. White text on a yellow button, or yellow link text
 * on a white card, is unreadable, and nothing in the form builder stops a colour like that.
 */
final class Palette {

	/**
	 * The colour of a form that has not chosen one. The stylesheet declares the same value.
	 */
	public const DEFAULT_PRIMARY = '#1976d2';

	/**
	 * The contrast WCAG AA asks of ordinary text.
	 */
	private const AA = 4.5;

	/**
	 * The card a page is drawn on, in each colour scheme. The stylesheet declares the same two.
	 */
	private const SURFACE_LIGHT = '#ffffff';
	private const SURFACE_DARK  = '#111827';

	/**
	 * A style attribute's worth of custom properties for a primary colour.
	 *
	 * Empty for no colour, for one that is not a hex colour, and for the default: the stylesheet
	 * already has the right values for that one.
	 *
	 * @param mixed $color The colour a form stores, e.g. "#112233".
	 */
	public static function style( mixed $color ): string {
		$tokens = self::tokens( $color );

		$style = '';
		foreach ( $tokens as $name => $value ) {
			$style .= $name . ':' . $value . ';';
		}

		return $style;
	}

	/**
	 * The custom properties for a primary colour.
	 *
	 * @param mixed $color The colour a form stores.
	 *
	 * @return array<string, string> Property => hex colour. Empty when there is nothing to override.
	 */
	public static function tokens( mixed $color ): array {
		$hex = is_string( $color ) ? sanitize_hex_color( trim( $color ) ) : null;

		if ( ! $hex ) {
			return array();
		}

		$hex = self::expand( $hex );

		if ( self::DEFAULT_PRIMARY === $hex ) {
			return array();
		}

		$rgb = self::rgb( $hex );

		return array(
			'--pcp-primary'           => $hex,
			'--pcp-primary-contrast'  => self::on( $rgb ),
			'--pcp-primary-ink-light' => self::hex( self::readable( $rgb, self::rgb( self::SURFACE_LIGHT ), array( 0, 0, 0 ) ) ),
			'--pcp-primary-ink-dark'  => self::hex( self::readable( $rgb, self::rgb( self::SURFACE_DARK ), array( 255, 255, 255 ) ) ),
		);
	}

	/**
	 * White or black, whichever reads better on a colour.
	 *
	 * One of the two always reaches AA: the worst case, where they are equally good, is 4.58 to 1.
	 *
	 * @param array{0:int,1:int,2:int} $rgb The background.
	 */
	private static function on( array $rgb ): string {
		$white = self::contrast( $rgb, array( 255, 255, 255 ) );
		$black = self::contrast( $rgb, array( 0, 0, 0 ) );

		return $white >= $black ? '#ffffff' : '#000000';
	}

	/**
	 * A colour moved toward black or white until it can be read as text on a surface.
	 *
	 * The surface it has to work on is the card and the tint of itself that the stylesheet lays
	 * over the card for a selected or highlighted row, whichever is harder.
	 *
	 * @param array{0:int,1:int,2:int} $rgb     The colour.
	 * @param array{0:int,1:int,2:int} $surface The card.
	 * @param array{0:int,1:int,2:int} $toward  Black on a light card, white on a dark one.
	 *
	 * @return array{0:int,1:int,2:int}
	 */
	private static function readable( array $rgb, array $surface, array $toward ): array {
		$tint = self::mix( $rgb, $surface, 0.14 );

		for ( $step = 0; $step <= 20; $step++ ) {
			$candidate = self::mix( $toward, $rgb, $step / 20 );

			if ( self::contrast( $candidate, $surface ) >= self::AA && self::contrast( $candidate, $tint ) >= self::AA ) {
				return $candidate;
			}
		}

		return $toward;
	}

	/**
	 * Two colours mixed: `$share` of the first, the rest of the second.
	 *
	 * @param array{0:int,1:int,2:int} $a     The first colour.
	 * @param array{0:int,1:int,2:int} $b     The second colour.
	 * @param float                    $share How much of the first, 0 to 1.
	 *
	 * @return array{0:int,1:int,2:int}
	 */
	private static function mix( array $a, array $b, float $share ): array {
		return array(
			(int) round( $a[0] * $share + $b[0] * ( 1 - $share ) ),
			(int) round( $a[1] * $share + $b[1] * ( 1 - $share ) ),
			(int) round( $a[2] * $share + $b[2] * ( 1 - $share ) ),
		);
	}

	/**
	 * The WCAG contrast ratio of two colours, from 1 to 21.
	 *
	 * @param array{0:int,1:int,2:int} $a One colour.
	 * @param array{0:int,1:int,2:int} $b The other.
	 */
	private static function contrast( array $a, array $b ): float {
		$la = self::luminance( $a );
		$lb = self::luminance( $b );

		return ( max( $la, $lb ) + 0.05 ) / ( min( $la, $lb ) + 0.05 );
	}

	/**
	 * A colour's relative luminance, as WCAG defines it.
	 *
	 * @param array{0:int,1:int,2:int} $rgb The colour.
	 */
	private static function luminance( array $rgb ): float {
		$linear = array_map(
			static function ( int $channel ): float {
				$value = $channel / 255;

				return $value <= 0.04045 ? $value / 12.92 : ( ( $value + 0.055 ) / 1.055 ) ** 2.4;
			},
			$rgb
		);

		return 0.2126 * $linear[0] + 0.7152 * $linear[1] + 0.0722 * $linear[2];
	}

	/**
	 * "#abc" as "#aabbcc", in lower case.
	 *
	 * @param string $hex A three or six digit hex colour.
	 */
	private static function expand( string $hex ): string {
		$digits = strtolower( ltrim( $hex, '#' ) );

		if ( 3 === strlen( $digits ) ) {
			$digits = $digits[0] . $digits[0] . $digits[1] . $digits[1] . $digits[2] . $digits[2];
		}

		return '#' . $digits;
	}

	/**
	 * @param string $hex A six digit hex colour.
	 *
	 * @return array{0:int,1:int,2:int}
	 */
	private static function rgb( string $hex ): array {
		$digits = ltrim( $hex, '#' );

		return array(
			(int) hexdec( substr( $digits, 0, 2 ) ),
			(int) hexdec( substr( $digits, 2, 2 ) ),
			(int) hexdec( substr( $digits, 4, 2 ) ),
		);
	}

	/**
	 * @param array{0:int,1:int,2:int} $rgb A colour.
	 */
	private static function hex( array $rgb ): string {
		return sprintf( '#%02x%02x%02x', $rgb[0], $rgb[1], $rgb[2] );
	}
}
