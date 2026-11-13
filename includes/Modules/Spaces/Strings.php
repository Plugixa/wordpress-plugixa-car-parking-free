<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Modules\Spaces;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The strings of the screens under `src/modules/spaces/`, translated.
 *
 * Every key those screens ask for with `t( key, fallback )` has an entry here, with the same
 * English. A key that is missing still shows, in English, in every language: that is the
 * failure this list exists to prevent.
 */
final class Strings {

	/**
	 * @return array<string, string> Key => translated text.
	 */
	public static function all(): array {
		return array(
			'space_choose_type'      => __( 'Choose a space type.', 'plugixa-car-parking' ),
			'space_quantity_invalid' => __( 'A whole number of spaces, zero or more.', 'plugixa-car-parking' ),
			'space_order_invalid'    => __( 'A whole number.', 'plugixa-car-parking' ),
			'spaces_title'           => __( 'Spaces', 'plugixa-car-parking' ),
			'spaces_hint'            => __( 'Which space types this location offers, and how many of each. Customers can book up to that many at the same time.', 'plugixa-car-parking' ),
			'space_add'              => __( 'Add spaces', 'plugixa-car-parking' ),
			'spaces_empty'           => __( 'This location has no spaces yet, so nothing can be booked here. Add the space types it offers and how many of each it has.', 'plugixa-car-parking' ),
			'space_type'             => __( 'Space type', 'plugixa-car-parking' ),
			'space_label'            => __( 'Label', 'plugixa-car-parking' ),
			'space_quantity'         => __( 'Spaces', 'plugixa-car-parking' ),
			'space_order'            => __( 'Order', 'plugixa-car-parking' ),
			'space_status'           => __( 'Status', 'plugixa-car-parking' ),
			'space_remove'           => __( 'Remove', 'plugixa-car-parking' ),
			/* translators: %s: a value the app fills in. */
			'spaces_total'           => __( '%s spaces can be booked here in total. Inactive rows are not counted.', 'plugixa-car-parking' ),
			'space_new'              => __( 'Add spaces', 'plugixa-car-parking' ),
			'space_edit'             => __( 'Edit spaces', 'plugixa-car-parking' ),
			'space_no_types'         => __( 'No space types yet. Add one under Space types first.', 'plugixa-car-parking' ),
			'space_quantity_hint'    => __( 'How many the location has.', 'plugixa-car-parking' ),
			'space_label_hint'       => __( 'Optional. A name for this group of spaces, such as a level or a zone.', 'plugixa-car-parking' ),
			'space_status_hint'      => __( 'Inactive spaces cannot be booked.', 'plugixa-car-parking' ),
			'space_order_hint'       => __( 'Lower numbers are listed first.', 'plugixa-car-parking' ),
			'space_remove_title'     => __( 'Remove these spaces?', 'plugixa-car-parking' ),
			/* translators: %1$s: a value the app fills in, %2$s: a value the app fills in. */
			'space_remove_body'      => __( 'The %1$s “%2$s” spaces will be moved to the trash and can no longer be booked at this location.', 'plugixa-car-parking' ),
		);
	}
}
