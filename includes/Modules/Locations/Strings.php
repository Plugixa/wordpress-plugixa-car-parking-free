<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Modules\Locations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The strings of the Locations screens, translated.
 *
 * Every key the screens under `src/modules/locations/` ask for with `t( key, fallback )` has an
 * entry here, with the same English. A key that is missing still shows, in English, in every
 * language: that is the failure this list exists to prevent.
 */
final class Strings {

	/**
	 * @return array<string, string> Key => translated text.
	 */
	public static function all(): array {
		return array(
			'locations'                  => __( 'Locations', 'plugixa-car-parking' ),
			'new_location'               => __( 'New location', 'plugixa-car-parking' ),
			'edit_location'              => __( 'Edit location', 'plugixa-car-parking' ),
			'search_locations'           => __( 'Search locations…', 'plugixa-car-parking' ),
			'no_locations'               => __( 'No locations yet', 'plugixa-car-parking' ),
			'no_locations_hint'          => __( 'A location is a car park customers can book a space in. Add one, then say which space types it has.', 'plugixa-car-parking' ),
			'location_name'              => __( 'Name', 'plugixa-car-parking' ),
			'location_slug'              => __( 'Slug', 'plugixa-car-parking' ),
			'location_slug_hint'         => __( 'Left empty, it is made from the name.', 'plugixa-car-parking' ),
			'location_status'            => __( 'Status', 'plugixa-car-parking' ),
			'location_description'       => __( 'Description', 'plugixa-car-parking' ),
			'location_space_types'       => __( 'Space types', 'plugixa-car-parking' ),
			'location_billing'           => __( 'Billed', 'plugixa-car-parking' ),
			'location_billing_unit'      => __( 'Billing unit', 'plugixa-car-parking' ),
			'location_billing_unit_hint' => __( 'The unit a stay here is priced in.', 'plugixa-car-parking' ),
			'location_inventory_mode'    => __( 'Spaces are counted', 'plugixa-car-parking' ),
			'inventory_mode_capacity'    => __( 'By capacity', 'plugixa-car-parking' ),
			'location_order'             => __( 'Order', 'plugixa-car-parking' ),
			'location_order_hint'        => __( 'Lower numbers are listed first.', 'plugixa-car-parking' ),
			'location_image'             => __( 'Picture', 'plugixa-car-parking' ),
			'location_address'           => __( 'Address', 'plugixa-car-parking' ),
			'location_address_line1'     => __( 'Address line 1', 'plugixa-car-parking' ),
			'location_address_line2'     => __( 'Address line 2', 'plugixa-car-parking' ),
			'location_city'              => __( 'City', 'plugixa-car-parking' ),
			'location_state'             => __( 'State or region', 'plugixa-car-parking' ),
			'location_postcode'          => __( 'Postcode', 'plugixa-car-parking' ),
			'location_country'           => __( 'Country', 'plugixa-car-parking' ),
			'location_contact'           => __( 'Contact', 'plugixa-car-parking' ),
			'location_phone'             => __( 'Phone', 'plugixa-car-parking' ),
			'location_email'             => __( 'Email', 'plugixa-car-parking' ),
			'location_regional'          => __( 'Regional', 'plugixa-car-parking' ),
			'location_timezone'          => __( 'Timezone', 'plugixa-car-parking' ),
			'location_timezone_hint'     => __( 'For example Europe/Paris. Left empty, the site’s timezone is used.', 'plugixa-car-parking' ),
			'location_currency'          => __( 'Currency', 'plugixa-car-parking' ),
			'location_currency_hint'     => __( 'Left empty, the site’s currency is used.', 'plugixa-car-parking' ),
			'location_latitude'          => __( 'Latitude', 'plugixa-car-parking' ),
			'location_longitude'         => __( 'Longitude', 'plugixa-car-parking' ),
			'location_save_first'        => __( 'Save the location to set its opening hours and its spaces.', 'plugixa-car-parking' ),

			// Added by generate-strings.php. Check the wording, then move each line to where it reads best.
			'location_closure_type_special'   => __( 'Special hours', 'plugixa-car-parking' ),
			'location_closure_type_closed'    => __( 'Closed', 'plugixa-car-parking' ),
			'location_closure_all_day'        => __( 'Closed all day', 'plugixa-car-parking' ),
			/* translators: %s: a value the app fills in. */
			'location_closure_from'           => __( 'From %s', 'plugixa-car-parking' ),
			/* translators: %s: a value the app fills in. */
			'location_closure_until'          => __( 'Until %s', 'plugixa-car-parking' ),
			'location_closure_open_all_day'   => __( 'Open all day', 'plugixa-car-parking' ),
			'location_closure_date_missing'   => __( 'Choose a date.', 'plugixa-car-parking' ),
			'location_closure_date_order'     => __( 'Must be on or after the first day.', 'plugixa-car-parking' ),
			'location_hours_time_order'       => __( 'Must be after opening.', 'plugixa-car-parking' ),
			'location_closures_title'         => __( 'Closures and special days', 'plugixa-car-parking' ),
			'location_closures_hint'          => __( 'Dates when the location is closed, or keeps other hours than its usual week. They apply whether or not opening hours are set.', 'plugixa-car-parking' ),
			'location_closure_add'            => __( 'Add dates', 'plugixa-car-parking' ),
			'location_closures_empty'         => __( 'No closures yet. Add the public holidays and maintenance days on which customers must not be able to book.', 'plugixa-car-parking' ),
			'location_closure_dates'          => __( 'Dates', 'plugixa-car-parking' ),
			'location_closure_type'           => __( 'Type', 'plugixa-car-parking' ),
			'location_closure_hours'          => __( 'Hours', 'plugixa-car-parking' ),
			'location_closure_note'           => __( 'Note', 'plugixa-car-parking' ),
			'location_closure_new'            => __( 'Add a closure or special day', 'plugixa-car-parking' ),
			'location_closure_edit'           => __( 'Edit closure or special day', 'plugixa-car-parking' ),
			'location_closure_special_hint'   => __( 'On these dates the hours below replace the usual week.', 'plugixa-car-parking' ),
			'location_closure_closed_hint'    => __( 'No booking can start or end on these dates.', 'plugixa-car-parking' ),
			'location_closure_first_day'      => __( 'First day', 'plugixa-car-parking' ),
			'location_closure_last_day'       => __( 'Last day', 'plugixa-car-parking' ),
			'location_hours_opens'            => __( 'Opens', 'plugixa-car-parking' ),
			'location_closure_opens_hint'     => __( 'Left empty, there is no earliest time.', 'plugixa-car-parking' ),
			'location_hours_closes'           => __( 'Closes', 'plugixa-car-parking' ),
			'location_closure_closes_hint'    => __( 'Left empty, there is no latest time.', 'plugixa-car-parking' ),
			'location_closure_note_hint'      => __( 'For your own reference, such as the name of the holiday.', 'plugixa-car-parking' ),
			'location_closure_delete_title'   => __( 'Delete these dates?', 'plugixa-car-parking' ),
			/* translators: %s: a value the app fills in. */
			'location_closure_delete_body'    => __( '%s will follow the usual opening hours again. This cannot be undone.', 'plugixa-car-parking' ),
			'location_hours_sunday'           => __( 'Sunday', 'plugixa-car-parking' ),
			'location_hours_monday'           => __( 'Monday', 'plugixa-car-parking' ),
			'location_hours_tuesday'          => __( 'Tuesday', 'plugixa-car-parking' ),
			'location_hours_wednesday'        => __( 'Wednesday', 'plugixa-car-parking' ),
			'location_hours_thursday'         => __( 'Thursday', 'plugixa-car-parking' ),
			'location_hours_friday'           => __( 'Friday', 'plugixa-car-parking' ),
			'location_hours_saturday'         => __( 'Saturday', 'plugixa-car-parking' ),
			'location_hours_time_missing'     => __( 'Enter a time.', 'plugixa-car-parking' ),
			'location_hours_invalid'          => __( 'Some opening hours need correcting before they can be saved.', 'plugixa-car-parking' ),
			'location_hours_title'            => __( 'Opening hours', 'plugixa-car-parking' ),
			'location_hours_hint'             => __( 'A booking can only start and end while the location is open. A location with no opening hours is open around the clock, every day.', 'plugixa-car-parking' ),
			'location_hours_always_open'      => __( 'Open around the clock', 'plugixa-car-parking' ),
			'location_hours_always_open_hint' => __( 'No opening hours are stored, so bookings are accepted at any time of any day. Switch this off to set a week. Closures below still apply.', 'plugixa-car-parking' ),
			/* translators: %s: a value the app fills in. */
			'location_hours_open_on'          => __( 'Open on %s', 'plugixa-car-parking' ),
			'location_hours_closed'           => __( 'Closed', 'plugixa-car-parking' ),
			'location_hours_open'             => __( 'Open', 'plugixa-car-parking' ),
			'location_hours_unsaved'          => __( 'These hours are saved with the location, when you press Save.', 'plugixa-car-parking' ),
			'setup_type_inactive'       => __( 'Space type switched off', 'plugixa-car-parking' ),
			/* translators: %s: a billing unit as a word, such as day or hour. */
			'setup_type_no_price'       => __( 'No %s price — customers can’t book it', 'plugixa-car-parking' ),
			'setup_type_ok'             => __( 'OK', 'plugixa-car-parking' ),
			'setup_title'               => __( 'Booking setup', 'plugixa-car-parking' ),
			'setup_hint'                => __( 'What customers can book here, and which forms offer it. Each line is changed on its own screen.', 'plugixa-car-parking' ),
			/* translators: %1$s: how the location bills, such as Per day; %2$s: a currency code. */
			'setup_billing'             => __( 'Billing unit: %1$s · Currency: %2$s', 'plugixa-car-parking' ),
			'setup_no_spaces'           => __( 'No spaces yet, so nothing can be booked here. Add them in the Spaces panel below.', 'plugixa-car-parking' ),
			'setup_state'               => __( 'Can be booked', 'plugixa-car-parking' ),
			/* translators: %s: how many of the spaces are numbered. */
			'setup_numbered'            => __( '%s numbered', 'plugixa-car-parking' ),
			'setup_forms'               => __( 'Forms that offer this location', 'plugixa-car-parking' ),
			'setup_no_forms'            => __( 'No form offers this location, so customers cannot choose it.', 'plugixa-car-parking' ),
			'setup_all_locations'       => __( 'All locations', 'plugixa-car-parking' ),
			'setup_form_inactive'       => __( '(switched off)', 'plugixa-car-parking' ),
			/* translators: %s: how many forms book at every location. */
			'location_forms_everywhere' => __( 'All-location forms (%s)', 'plugixa-car-parking' ),
			'location_forms'            => __( 'Forms', 'plugixa-car-parking' ),
			'setup_space_type' => __( 'Space type', 'plugixa-car-parking' ),
			'setup_quantity'   => __( 'Spaces', 'plugixa-car-parking' ),
		);
	}
}
