<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Modules\SpaceTypes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The strings of the screens under `src/modules/space-types/`, translated.
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
			'space_type_icon_none'              => __( 'No icon chosen.', 'plugixa-car-parking' ),
			'space_type_price_initial'          => __( 'One-off fee', 'plugixa-car-parking' ),
			'space_type_price_invalid'          => __( 'An amount of zero or more, with at most four decimals.', 'plugixa-car-parking' ),
			'space_type_price_missing'          => __( 'Enter an amount.', 'plugixa-car-parking' ),
			'space_type_prices_invalid'         => __( 'Some prices need correcting before they can be saved.', 'plugixa-car-parking' ),
			'space_type_prices_title'           => __( 'Prices', 'plugixa-car-parking' ),
			'space_type_price_initial_hint'     => __( 'Charged once per booking, on top of the time.', 'plugixa-car-parking' ),
			'space_type_location_prices'        => __( 'Prices at one location', 'plugixa-car-parking' ),
			'space_type_location_prices_hint'   => __( 'A price set here is charged at that location instead of the price above. Every other location keeps the price above.', 'plugixa-car-parking' ),
			'space_type_price_location'         => __( 'Location', 'plugixa-car-parking' ),
			'space_type_price_location_missing' => __( 'Choose a location.', 'plugixa-car-parking' ),
			'space_type_price_unit'             => __( 'For', 'plugixa-car-parking' ),
			'space_type_price_duplicate'        => __( 'This location already has a price for this.', 'plugixa-car-parking' ),
			'space_type_price_amount'           => __( 'Amount', 'plugixa-car-parking' ),
			'space_type_price_remove'           => __( 'Remove this price', 'plugixa-car-parking' ),
			'space_type_price_add'              => __( 'Add a price for one location', 'plugixa-car-parking' ),
			'space_type_prices_unsaved'         => __( 'These prices are saved with the space type, when you press Save.', 'plugixa-car-parking' ),
			'space_type_feature_covered'        => __( 'Covered', 'plugixa-car-parking' ),
			'space_type_feature_accessible'     => __( 'Accessible', 'plugixa-car-parking' ),
			'space_type_feature_charging'       => __( 'Charging point', 'plugixa-car-parking' ),
			'space_type_unit_m'                 => __( 'Metres', 'plugixa-car-parking' ),
			'space_type_unit_ft'                => __( 'Feet', 'plugixa-car-parking' ),
			'space_types'                       => __( 'Space types', 'plugixa-car-parking' ),
			'edit_space_type'                   => __( 'Edit space type', 'plugixa-car-parking' ),
			'new_space_type'                    => __( 'New space type', 'plugixa-car-parking' ),
			'space_type_status'                 => __( 'Status', 'plugixa-car-parking' ),
			'space_type_status_hint'            => __( 'An inactive space type is not offered to customers.', 'plugixa-car-parking' ),
			'space_type_order'                  => __( 'Order', 'plugixa-car-parking' ),
			'space_type_order_hint'             => __( 'Lower numbers are listed first.', 'plugixa-car-parking' ),
			'space_type_features'               => __( 'Features', 'plugixa-car-parking' ),
			'space_type_name'                   => __( 'Name', 'plugixa-car-parking' ),
			'space_type_slug'                   => __( 'Slug', 'plugixa-car-parking' ),
			'space_type_slug_hint'              => __( 'Left empty, it is made from the name.', 'plugixa-car-parking' ),
			'space_type_description'            => __( 'Description', 'plugixa-car-parking' ),
			'space_type_appearance'             => __( 'Appearance', 'plugixa-car-parking' ),
			'space_type_appearance_hint'        => __( 'How this space type is marked in lists and on the booking form.', 'plugixa-car-parking' ),
			'space_type_icon'                   => __( 'Icon', 'plugixa-car-parking' ),
			'space_type_color'                  => __( 'Colour', 'plugixa-car-parking' ),
			'space_type_color_hint'             => __( 'Pick a swatch or type a hex code.', 'plugixa-car-parking' ),
			'space_type_dimensions'             => __( 'Size of one space', 'plugixa-car-parking' ),
			'space_type_width'                  => __( 'Width', 'plugixa-car-parking' ),
			'space_type_length'                 => __( 'Length', 'plugixa-car-parking' ),
			'space_type_unit'                   => __( 'Measured in', 'plugixa-car-parking' ),
			'space_type_save_first'             => __( 'Save the space type to set its prices.', 'plugixa-car-parking' ),
			'space_type_size'                   => __( 'Size', 'plugixa-car-parking' ),
			'search_space_types'                => __( 'Search space types…', 'plugixa-car-parking' ),
			'no_space_types'                    => __( 'No space types yet', 'plugixa-car-parking' ),
			'no_space_types_hint'               => __( 'A space type is a kind of parking space, such as standard, covered or accessible. Add one, give it a price, then say how many of it each location has.', 'plugixa-car-parking' ),
			'space_type_icon_car'               => __( 'Car', 'plugixa-car-parking' ),
			'space_type_icon_parking'           => __( 'Parking', 'plugixa-car-parking' ),
			'space_type_icon_garage'            => __( 'Garage', 'plugixa-car-parking' ),
			'space_type_icon_indoor'            => __( 'Indoor', 'plugixa-car-parking' ),
			'space_type_icon_accessible'        => __( 'Accessible', 'plugixa-car-parking' ),
			'space_type_icon_family'            => __( 'Family', 'plugixa-car-parking' ),
			'space_type_icon_charging'          => __( 'Charging point', 'plugixa-car-parking' ),
			'space_type_icon_electric_car'      => __( 'Electric car', 'plugixa-car-parking' ),
			'space_type_icon_motorcycle'        => __( 'Motorcycle', 'plugixa-car-parking' ),
			'space_type_icon_moped'             => __( 'Moped', 'plugixa-car-parking' ),
			'space_type_icon_bicycle'           => __( 'Bicycle', 'plugixa-car-parking' ),
			'space_type_icon_electric_bike'     => __( 'Electric bicycle', 'plugixa-car-parking' ),
			'space_type_icon_scooter'           => __( 'Scooter', 'plugixa-car-parking' ),
			'space_type_icon_van'               => __( 'Van', 'plugixa-car-parking' ),
			'space_type_icon_bus'               => __( 'Bus or coach', 'plugixa-car-parking' ),
			'space_type_icon_truck'             => __( 'Truck', 'plugixa-car-parking' ),
			'space_type_icon_motorhome'         => __( 'Motorhome', 'plugixa-car-parking' ),
			'space_type_icon_secured'           => __( 'Secured', 'plugixa-car-parking' ),
			'space_type_icon_reserved'          => __( 'Reserved', 'plugixa-car-parking' ),
			'space_type_dimension_invalid'      => __( 'A number with at most two decimals.', 'plugixa-car-parking' ),
			'space_type_name_required'          => __( 'A space type needs a name.', 'plugixa-car-parking' ),
			'space_type_color_invalid'          => __( 'A hex colour such as #2271b1.', 'plugixa-car-parking' ),
			'space_type_order_invalid'          => __( 'A whole number.', 'plugixa-car-parking' ),

			// Added by generate-strings.php. Check the wording, then move each line to where it reads best.
			'space_type_prices_needed_hint' => __( 'What one space of this type costs for each unit a stay can be billed in. A unit left empty has no price: a location billed in it cannot offer this type.', 'plugixa-car-parking' ),
			/* translators: %1$s: a billing unit as a word, such as day; %2$s: a location's name. */
			'space_type_unpriced'           => __( 'No %1$s price for %2$s: customers can’t book this type there.', 'plugixa-car-parking' ),
		);
	}
}
