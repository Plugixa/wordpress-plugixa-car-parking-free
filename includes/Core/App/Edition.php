<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Core\App;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Which build is installed: free or premium.
 *
 * The premium build is the free one plus a `pro/` folder, so the folder is the answer. No licence
 * check: a licence is Freemius's business, and the free build works with no account at all. The
 * admin app reads this to decide whether to show the Pro features page, its header button and its
 * dashboard card, which the premium build carries too and must never draw.
 */
final class Edition {

	/**
	 * The premium plans on plugixa.com: where the free build's "See Pro plans" link goes.
	 *
	 * It travels to the app in its config, so the app's own source names no outside host.
	 */
	public const PRICING_URL = 'https://www.plugixa.com/plugins/plugixa-car-parking#pricing';

	/**
	 * `premium` when the `pro/` folder is on disk, `free` otherwise.
	 */
	public static function current(): string {
		return is_dir( PLUGIXA_CARPARKING_PATH . 'pro' ) ? 'premium' : 'free';
	}
}
