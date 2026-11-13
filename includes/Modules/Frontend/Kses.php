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
 * What HTML the public pages may print.
 *
 * Every fragment the plugin assembles itself is passed through wp_kses() with the list below at
 * the moment it is echoed. The list is what post content may contain, plus the vocabulary the
 * plugin's own markup uses: tables, buttons, form controls and inline SVG icons.
 *
 * There is no <script> and no <style> in either context. Scripts are enqueued, and styling that
 * differs per page goes through wp_add_inline_style() and the --pcp-* custom properties.
 */
final class Kses {

	/**
	 * The wp_kses() allowlist for a rendering context.
	 *
	 * - 'content' is text an administrator wrote: payment instructions, invoice notes. It is
	 *   what a post may contain, adjustable with the plugixa_carparking_content_allowed_html filter.
	 * - 'frontend' is a whole fragment the plugin renders. It contains everything 'content' does,
	 *   because fragments embed authored text, plus the plugin's own interface vocabulary.
	 *
	 * @param string $context 'frontend' or 'content'.
	 *
	 * @return array<string, array<string, bool>>
	 */
	public static function allowed_html( string $context = 'frontend' ): array {
		$content = apply_filters( 'plugixa_carparking_content_allowed_html', wp_kses_allowed_html( 'post' ) );

		if ( 'content' === $context ) {
			return $content;
		}

		$allowed = $content;

		// Structural and interactive elements the plugin's markup emits.
		$allowed['button']     = array(
			'type'     => true,
			'name'     => true,
			'value'    => true,
			'disabled' => true,
		);
		$allowed['form']       = array_merge(
			$allowed['form'] ?? array(),
			array(
				'action'     => true,
				'method'     => true,
				'enctype'    => true,
				'novalidate' => true,
			)
		);
		$allowed['input']      = array_merge(
			$allowed['input'] ?? array(),
			array(
				'type'         => true,
				'name'         => true,
				'value'        => true,
				'placeholder'  => true,
				'required'     => true,
				'maxlength'    => true,
				'autocomplete' => true,
				'min'          => true,
				'max'          => true,
				'step'         => true,
				'checked'      => true,
				'disabled'     => true,
				'readonly'     => true,
				'pattern'      => true,
				'inputmode'    => true,
			)
		);
		$allowed['textarea']   = array(
			'name'        => true,
			'rows'        => true,
			'cols'        => true,
			'placeholder' => true,
			'maxlength'   => true,
			'required'    => true,
			'disabled'    => true,
		);
		$allowed['select']     = array_merge(
			$allowed['select'] ?? array(),
			array(
				'name'     => true,
				'multiple' => true,
				'disabled' => true,
				'required' => true,
			)
		);
		$allowed['option']     = array_merge(
			$allowed['option'] ?? array(),
			array(
				'value'    => true,
				'selected' => true,
				'disabled' => true,
			)
		);
		$allowed['optgroup']   = array( 'label' => true );
		$allowed['label']      = array_merge( $allowed['label'] ?? array(), array( 'for' => true ) );
		$allowed['fieldset']   = array( 'disabled' => true );
		$allowed['legend']     = array();
		$allowed['details']    = array( 'open' => true );
		$allowed['summary']    = array();
		$allowed['nav']        = array();
		$allowed['figure']     = $allowed['figure'] ?? array();
		$allowed['figcaption'] = $allowed['figcaption'] ?? array();
		$allowed['time']       = array( 'datetime' => true );
		$allowed['mark']       = array();
		$allowed['progress']   = array(
			'value' => true,
			'max'   => true,
		);

		// The invoice and summary body is a table layout.
		foreach ( array( 'table', 'thead', 'tbody', 'tfoot', 'tr' ) as $tag ) {
			$allowed[ $tag ] = $allowed[ $tag ] ?? array();
		}
		$allowed['th'] = array_merge(
			$allowed['th'] ?? array(),
			array(
				'colspan' => true,
				'rowspan' => true,
				'scope'   => true,
				'abbr'    => true,
			)
		);
		$allowed['td'] = array_merge(
			$allowed['td'] ?? array(),
			array(
				'colspan' => true,
				'rowspan' => true,
				'headers' => true,
			)
		);

		// Inline SVG icons. wp_kses() lowercases attribute names, so viewBox is listed as viewbox.
		$svg_paint = array(
			'fill'            => true,
			'stroke'          => true,
			'stroke-width'    => true,
			'stroke-linecap'  => true,
			'stroke-linejoin' => true,
			'fill-rule'       => true,
			'clip-rule'       => true,
			'transform'       => true,
		);

		$allowed['svg']      = array_merge(
			$svg_paint,
			array(
				'xmlns'               => true,
				'viewbox'             => true,
				'width'               => true,
				'height'              => true,
				'focusable'           => true,
				'preserveaspectratio' => true,
			)
		);
		$allowed['path']     = array_merge( $svg_paint, array( 'd' => true ) );
		$allowed['circle']   = array_merge(
			$svg_paint,
			array(
				'cx' => true,
				'cy' => true,
				'r'  => true,
			)
		);
		$allowed['line']     = array_merge(
			$svg_paint,
			array(
				'x1' => true,
				'y1' => true,
				'x2' => true,
				'y2' => true,
			)
		);
		$allowed['rect']     = array_merge(
			$svg_paint,
			array(
				'x'      => true,
				'y'      => true,
				'width'  => true,
				'height' => true,
				'rx'     => true,
				'ry'     => true,
			)
		);
		$allowed['polyline'] = array_merge( $svg_paint, array( 'points' => true ) );
		$allowed['polygon']  = array_merge( $svg_paint, array( 'points' => true ) );
		$allowed['g']        = $svg_paint;

		// Link and image attributes beyond what a post allows.
		$allowed['a']   = array_merge(
			$allowed['a'] ?? array(),
			array(
				'target'   => true,
				'rel'      => true,
				'download' => true,
			)
		);
		$allowed['img'] = array_merge(
			$allowed['img'] ?? array(),
			array(
				'loading'  => true,
				'decoding' => true,
				'srcset'   => true,
				'sizes'    => true,
			)
		);

		// Attributes every allowed element may carry. wp_kses() honours one wildcard, data-*, and
		// no other: an ARIA attribute that is not listed here is stripped without a word.
		$global_attrs = array(
			'id'               => true,
			'class'            => true,
			'style'            => true,
			'role'             => true,
			'title'            => true,
			'tabindex'         => true,
			'hidden'           => true,
			'lang'             => true,
			'dir'              => true,
			'data-*'           => true,
			'aria-label'       => true,
			'aria-labelledby'  => true,
			'aria-describedby' => true,
			'aria-hidden'      => true,
			'aria-expanded'    => true,
			'aria-controls'    => true,
			'aria-current'     => true,
			'aria-live'        => true,
			'aria-pressed'     => true,
			'aria-selected'    => true,
			'aria-disabled'    => true,
			'aria-haspopup'    => true,
			'aria-invalid'     => true,
			'aria-required'    => true,
			'aria-busy'        => true,
		);

		foreach ( $allowed as $tag => $attrs ) {
			$allowed[ $tag ] = array_merge( is_array( $attrs ) ? $attrs : array(), $global_attrs );
		}

		/**
		 * Filter the HTML the public pages may print.
		 *
		 * @param array  $allowed wp_kses() allowlist.
		 * @param string $context Rendering context.
		 */
		return apply_filters( 'plugixa_carparking_allowed_html', $allowed, $context );
	}

	/**
	 * Let the plugin's CSS custom properties through a style attribute.
	 *
	 * wp_kses() checks each declaration in a style attribute with safecss_filter_attr(), and how
	 * well that accepts custom properties and var() varies between WordPress versions. A page is
	 * styled through --pcp-* properties, so those must always survive.
	 *
	 * @param bool   $allow           WordPress's own verdict for this declaration.
	 * @param string $css_test_string The one CSS declaration being checked.
	 */
	public static function allow_custom_property_css( $allow, $css_test_string ) {
		if ( $allow ) {
			return $allow;
		}

		// "--pcp-accent:#2271b1" declares a custom property; "background:var(--pcp-accent)" reads one.
		if ( preg_match( '/^--[a-zA-Z0-9_-]+\s*:/', (string) $css_test_string )
			|| preg_match( '/^[a-zA-Z-]+\s*:\s*[^;]*var\(--[a-zA-Z0-9_-]+/', (string) $css_test_string ) ) {
			return true;
		}

		return $allow;
	}

	/**
	 * Hook the custom-property allowance. Safe to call more than once.
	 */
	public static function register(): void {
		if ( ! has_filter( 'safecss_filter_attr_allow_css', array( self::class, 'allow_custom_property_css' ) ) ) {
			add_filter( 'safecss_filter_attr_allow_css', array( self::class, 'allow_custom_property_css' ), 10, 2 );
		}
	}
}
