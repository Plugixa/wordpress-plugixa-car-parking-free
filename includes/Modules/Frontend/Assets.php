<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Modules\Frontend;

use PlugixaCarParking\Core\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The stylesheet and the scripts the public pages bring with them.
 *
 * One stylesheet serves every page. Each page has a script of its own, and all of them load
 * after a small shared one that holds what they have in common and the registry other scripts
 * extend them through.
 *
 * A PAGE IS EXTENDED FROM OUTSIDE THROUGH THESE, and nothing in this module knows what by:
 *
 * - `plugixa_carparking_public_assets` (action): a page's script has been enqueued. Enqueue a
 *   script that depends on it, and hand that script its own settings and strings.
 * - `plugixa_carparking_public_datepicker` (filter): whether a page needs the calendar.
 * - `plugixa_carparking_public_style_tags` (filter): the shortcodes whose presence in a page's
 *   content means the stylesheet belongs in the document head.
 * - `plugixa_carparking_enqueue_public_styles` (action): do it to load the stylesheet on a page
 *   none of the plugin's own shortcodes is on.
 */
final class Assets {

	public const STYLE      = 'plugixa-car-parking-frontend';
	public const CORE       = 'plugixa-car-parking-core';
	public const DATEPICKER = 'plugixa-car-parking-flatpickr';

	/**
	 * WordPress locales whose calendar translation is not filed under the language code.
	 *
	 * Locale => array( file under vendor/flatpickr/l10n, the key that file registers itself as ).
	 * Every other locale is looked up by its language code, which is both the file and the key.
	 *
	 * @var array<string, array{0:string,1:string}>
	 */
	private const DATEPICKER_LOCALES = array(
		'zh_CN'  => array( 'zh', 'zh' ),
		'zh_SG'  => array( 'zh', 'zh' ),
		'zh_TW'  => array( 'zh-tw', 'zh_tw' ),
		'zh_HK'  => array( 'zh-tw', 'zh_tw' ),
		'de_AT'  => array( 'at', 'at' ),
		'el'     => array( 'gr', 'gr' ),
		'ca'     => array( 'cat', 'cat' ),
		'kk'     => array( 'kz', 'kz' ),
		'vi'     => array( 'vn', 'vn' ),
		'nb_NO'  => array( 'no', 'no' ),
		'nn_NO'  => array( 'nn', 'nn' ),
		'sr_RS'  => array( 'sr-cyr', 'sr' ),
		'uz_UZ'  => array( 'uz_latn', 'uz_latn' ),
		'ary'    => array( 'ar', 'ar' ),
		'ar_DZ'  => array( 'ar-dz', 'ar' ),
		'he_IL'  => array( 'he', 'he' ),
		'ja'     => array( 'ja', 'ja' ),
		'my_MM'  => array( 'my', 'my' ),
		'si_LK'  => array( 'si', 'si' ),
		'pa_IN'  => array( 'pa', 'pa' ),
		'bn_BD'  => array( 'bn', 'bn' ),
		'hi_IN'  => array( 'hi', 'hi' ),
		'fa_IR'  => array( 'fa', 'fa' ),
		'fa_AF'  => array( 'fa', 'fa' ),
		'bs_BA'  => array( 'bs', 'bs' ),
		'ka_GE'  => array( 'ka', 'ka' ),
		'mk_MK'  => array( 'mk', 'mk' ),
		'et'     => array( 'et', 'et' ),
		'sq'     => array( 'sq', 'sq' ),
		'ckb'    => array( 'ckb', 'ckb' ),
		'id_ID'  => array( 'id', 'id' ),
		'is_IS'  => array( 'is', 'is' ),
		'ko_KR'  => array( 'ko', 'ko' ),
		'sl_SI'  => array( 'sl', 'sl' ),
		'sv_SE'  => array( 'sv', 'sv' ),
		'da_DK'  => array( 'da', 'da' ),
		'cs_CZ'  => array( 'cs', 'cs' ),
		'uk'     => array( 'uk', 'uk' ),
	);

	/**
	 * Language codes with a file in the vendored package that does not work when loaded alone,
	 * so the calendar stays in English for them.
	 *
	 * @var string[]
	 */
	private const DATEPICKER_BROKEN = array( 'ms', 'ga' );

	public function register_hooks(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_for_content' ) );
		add_action( 'plugixa_carparking_enqueue_public_styles', array( $this, 'styles' ) );
	}

	/**
	 * Put the stylesheet in the document head when the page being shown has one of the plugin's
	 * shortcodes or its block in it.
	 *
	 * A shortcode enqueues what it needs when it runs. On a theme that prints its head before it
	 * renders the content, that is too late for the head: the stylesheet is printed in the footer
	 * and the page shows unstyled until it arrives. Looking at the content first avoids that.
	 * The enqueue a shortcode makes for itself stays, for content this cannot see: a widget, a
	 * page builder's own storage, a template part.
	 */
	public function enqueue_for_content(): void {
		if ( ! is_singular() ) {
			return;
		}

		$post = get_post();

		if ( ! $post instanceof \WP_Post || '' === (string) $post->post_content ) {
			return;
		}

		/**
		 * The shortcodes that need the public stylesheet.
		 *
		 * @param string[] $tags Shortcode tags.
		 */
		$tags = (array) apply_filters(
			'plugixa_carparking_public_style_tags',
			array( Shortcodes::WIZARD, Shortcodes::SUMMARY, Shortcodes::ACCOUNT )
		);

		$found = has_block( BookingFormBlock::NAME, $post );

		foreach ( $tags as $tag ) {
			if ( $found ) {
				break;
			}

			$found = is_string( $tag ) && has_shortcode( $post->post_content, $tag );
		}

		if ( $found ) {
			$this->styles();
		}
	}

	/**
	 * Register the public stylesheet, so something that names its handle can find it.
	 */
	public function register_style(): void {
		if ( wp_style_is( self::STYLE, 'registered' ) ) {
			return;
		}

		$relative = 'assets/frontend/build/css/frontend.css';
		$path     = PLUGIXA_CARPARKING_PATH . $relative;

		wp_register_style(
			self::STYLE,
			PLUGIXA_CARPARKING_URL . $relative,
			array(),
			file_exists( $path ) ? (string) filemtime( $path ) : PLUGIXA_CARPARKING_VERSION
		);
	}

	/**
	 * Enqueue the public stylesheet.
	 */
	public function styles(): void {
		$this->register_style();
		wp_enqueue_style( self::STYLE );
	}

	/**
	 * Enqueue a page's script, with everything it loads after, and tell whoever extends the page.
	 *
	 * The files are shipped as written: nothing builds them.
	 *
	 * @param string               $surface 'wizard', 'account' or 'summary'.
	 * @param string               $handle  Script handle.
	 * @param string               $file    File name under assets/frontend/js.
	 * @param string               $name    The global the script reads its settings from.
	 * @param array<string, mixed> $data    The settings.
	 * @param array<string, mixed> $context What the page is showing, for whoever extends it.
	 */
	public function page( string $surface, string $handle, string $file, string $name, array $data, array $context = array() ): void {
		$this->styles();

		$deps = array( $this->core() );

		/**
		 * Whether a page needs the calendar.
		 *
		 * @param bool   $needed  Whether the page's own script uses it.
		 * @param string $surface 'wizard', 'account' or 'summary'.
		 */
		if ( apply_filters( 'plugixa_carparking_public_datepicker', 'wizard' === $surface, $surface ) ) {
			$deps = array_merge( $deps, $this->datepicker() );
		}

		$this->script( $handle, 'assets/frontend/js/' . $file, $deps );
		$this->localize_once( $handle, $name, $data );

		/**
		 * A public page's script has been enqueued.
		 *
		 * @param string               $surface 'wizard', 'account' or 'summary'.
		 * @param string               $handle  The script's handle, to depend on.
		 * @param array<string, mixed> $context What the page is showing. For the wizard: `form`,
		 *                                      the saved form's settings or null, `location_ids`,
		 *                                      and `gateways`, the ways to pay it offers. For the
		 *                                      summary: `booking`, the booking row, and `gateways`,
		 *                                      the ways to pay what is owed. Empty for the account
		 *                                      page.
		 */
		do_action( 'plugixa_carparking_public_assets', $surface, $handle, $context );
	}

	/**
	 * Enqueue a script that ships with the plugin.
	 *
	 * @param string   $handle   Script handle.
	 * @param string   $relative Its path, relative to the plugin's directory.
	 * @param string[] $deps     Handles it needs loaded first.
	 */
	public function script( string $handle, string $relative, array $deps = array() ): void {
		$path = PLUGIXA_CARPARKING_PATH . $relative;

		wp_enqueue_script(
			$handle,
			PLUGIXA_CARPARKING_URL . $relative,
			$deps,
			file_exists( $path ) ? (string) filemtime( $path ) : PLUGIXA_CARPARKING_VERSION,
			true
		);
	}

	/**
	 * Hand a script its settings, once a page.
	 *
	 * A page can hold the same shortcode twice. The script reads one global, so the first render
	 * decides it and a second does not print the object again.
	 *
	 * @param string               $handle Script handle.
	 * @param string               $name   The global the script reads.
	 * @param array<string, mixed> $data   The settings.
	 */
	public function localize_once( string $handle, string $name, array $data ): void {
		if ( str_contains( (string) wp_scripts()->get_data( $handle, 'data' ), 'var ' . $name . ' =' ) ) {
			return;
		}

		wp_localize_script( $handle, $name, $data );
	}

	/**
	 * Enqueue the script every public page shares, with the site's formats.
	 *
	 * @return string Its handle.
	 */
	private function core(): string {
		$this->script( self::CORE, 'assets/frontend/js/core.js' );

		$time_format = (string) Settings::get( 'time_format', 'H:i' );
		$week_start  = Settings::get( 'week_starts_on', null );

		$this->localize_once(
			self::CORE,
			'PlugixaCarParkingCore',
			array(
				'locale'           => str_replace( '_', '-', determine_locale() ),
				// A 12-hour clock is one whose format has an am/pm mark or a 12-hour hour in it.
				'time24'           => ! preg_match( '/[aAgh]/', $time_format ),
				// The site's own formats, as PHP writes them. The script writes every date and
				// time a customer reads with these, so a page and an email agree.
				'dateFormat'       => (string) Settings::get( 'date_format', 'Y-m-d' ),
				'timeFormat'       => $time_format,
				'weekStart'        => is_numeric( $week_start ) ? (int) $week_start : (int) get_option( 'start_of_week', 1 ),
				'datepickerLocale' => $this->datepicker_locale()[1] ?? '',
				'money'            => array(
					'symbol'   => (string) Settings::get( 'currency_symbol', '$' ),
					'position' => (string) Settings::get( 'currency_position', 'left' ),
					'decimals' => (int) Settings::get( 'decimals', 2 ),
					'thousand' => (string) Settings::get( 'thousand_separator', ',' ),
					'decimal'  => (string) Settings::get( 'decimal_separator', '.' ),
				),
				'i18n'             => array(
					'genericError' => __( 'Something went wrong. Please try again.', 'plugixa-car-parking' ),
					'networkError' => __( 'We could not reach the server. Check your connection and try again.', 'plugixa-car-parking' ),
				),
			)
		);

		return self::CORE;
	}

	/**
	 * Enqueue the bundled calendar, when it is on disk, in the site's language when it has one.
	 *
	 * The calendar's files are copied in by `npm install`, and a date field falls back to the
	 * browser's own picker without them, so nothing here is required.
	 *
	 * @return string[] What a script that uses the calendar must load after.
	 */
	private function datepicker(): array {
		$base = 'assets/frontend/vendor/flatpickr/';
		$css  = PLUGIXA_CARPARKING_PATH . $base . 'flatpickr.min.css';
		$js   = PLUGIXA_CARPARKING_PATH . $base . 'flatpickr.min.js';

		if ( ! file_exists( $css ) || ! file_exists( $js ) ) {
			return array();
		}

		// The public stylesheet restyles the calendar, so it is printed after the calendar's own.
		wp_enqueue_style( self::DATEPICKER, PLUGIXA_CARPARKING_URL . $base . 'flatpickr.min.css', array(), (string) filemtime( $css ) );
		wp_enqueue_script( self::DATEPICKER, PLUGIXA_CARPARKING_URL . $base . 'flatpickr.min.js', array(), (string) filemtime( $js ), true );

		$locale = $this->datepicker_locale();

		if ( ! $locale ) {
			return array( self::DATEPICKER );
		}

		$relative = $base . 'l10n/' . $locale[0] . '.js';

		wp_enqueue_script(
			self::DATEPICKER . '-l10n',
			PLUGIXA_CARPARKING_URL . $relative,
			array( self::DATEPICKER ),
			(string) filemtime( PLUGIXA_CARPARKING_PATH . $relative ),
			true
		);

		return array( self::DATEPICKER, self::DATEPICKER . '-l10n' );
	}

	/**
	 * The calendar translation for the site's language.
	 *
	 * Only a translation that is on disk is offered. English is the calendar's own language and
	 * needs no file, so it, and any language without a file, gives an empty answer and the
	 * calendar stays in English.
	 *
	 * @return array{0:string,1:string}|array{} The file's name without its extension, and the key
	 *                                           it registers itself under.
	 */
	public function datepicker_locale(): array {
		$locale = determine_locale();
		$code   = strtolower( (string) strtok( $locale, '_' ) );

		$choice = self::DATEPICKER_LOCALES[ $locale ] ?? array( $code, $code );

		if ( 'en' === $code || in_array( $choice[0], self::DATEPICKER_BROKEN, true ) || ! preg_match( '/^[a-z_-]+$/', $choice[0] ) ) {
			return array();
		}

		return file_exists( PLUGIXA_CARPARKING_PATH . 'assets/frontend/vendor/flatpickr/l10n/' . $choice[0] . '.js' ) ? $choice : array();
	}
}
