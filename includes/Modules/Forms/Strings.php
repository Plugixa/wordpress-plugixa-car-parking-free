<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Modules\Forms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The strings of the screens under `src/modules/forms/`, translated.
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
			'form_fields_incomplete'      => __( 'Not saved yet: a field below is not finished.', 'plugixa-car-parking' ),
			'booking_forms'               => __( 'Booking forms', 'plugixa-car-parking' ),
			'edit_form'                   => __( 'Edit booking form', 'plugixa-car-parking' ),
			'new_form'                    => __( 'New booking form', 'plugixa-car-parking' ),
			'form_shortcode'              => __( 'Shortcode', 'plugixa-car-parking' ),
			'form_shortcode_hint'         => __( 'Paste it into any page or post to show this form there.', 'plugixa-car-parking' ),
			'form_shortcode_after_save'   => __( 'Save the form to get the shortcode that puts it on a page.', 'plugixa-car-parking' ),
			'form_status'                 => __( 'Status', 'plugixa-car-parking' ),
			'form_appearance'             => __( 'Appearance', 'plugixa-car-parking' ),
			'form_primary_color'          => __( 'Primary colour', 'plugixa-car-parking' ),
			'form_primary_color_hint'     => __( 'Used for the buttons, the current step and the links on the form.', 'plugixa-car-parking' ),
			'form_name'                   => __( 'Name', 'plugixa-car-parking' ),
			'form_name_hint'              => __( 'Only you see it. It tells your forms apart.', 'plugixa-car-parking' ),
			'form_slug'                   => __( 'Slug', 'plugixa-car-parking' ),
			'form_slug_edit_hint'         => __( 'The shortcode names the form by it. Change it and pages using the old one stop showing this form.', 'plugixa-car-parking' ),
			'form_slug_hint'              => __( 'Left empty, it is made from the name.', 'plugixa-car-parking' ),
			'form_new_hint'               => __( 'A new form works as it is: the steps below are the standard ones. Change them or add fields now, or come back to it later.', 'plugixa-car-parking' ),
			'form_custom_fields'          => __( 'Custom fields', 'plugixa-car-parking' ),
			'form_modified'               => __( 'Last changed', 'plugixa-car-parking' ),
			'search_forms'                => __( 'Search booking forms…', 'plugixa-car-parking' ),
			'no_forms'                    => __( 'No booking forms yet', 'plugixa-car-parking' ),
			'no_forms_hint'               => __( 'A booking form is what customers fill in to reserve a space. Create one, then put its shortcode on a page.', 'plugixa-car-parking' ),
			'form_color_hex'              => __( 'Hex code', 'plugixa-car-parking' ),
			'form_field_label_required'   => __( 'A field needs a label.', 'plugixa-car-parking' ),
			'form_field_options_required' => __( 'Add at least one choice.', 'plugixa-car-parking' ),
			'form_fields'                 => __( 'Fields', 'plugixa-car-parking' ),
			'form_add_field'              => __( 'Add field', 'plugixa-car-parking' ),
			'form_fields_hint'            => __( 'Asked on the customer details step. The answers are kept with the booking.', 'plugixa-car-parking' ),
			'form_builtin_locked'         => __( 'Every booking needs this field, so it cannot be changed or removed.', 'plugixa-car-parking' ),
			'form_field_required'         => __( 'Required', 'plugixa-car-parking' ),
			'form_field_untitled'         => __( 'Untitled field', 'plugixa-car-parking' ),
			/* translators: %s: a value the app fills in. */
			'form_move_field'             => __( 'Move the field %s', 'plugixa-car-parking' ),
			'form_field_label'            => __( 'Label', 'plugixa-car-parking' ),
			'form_field_type'             => __( 'Type', 'plugixa-car-parking' ),
			'form_field_options'          => __( 'Choices', 'plugixa-car-parking' ),
			'form_field_options_hint'     => __( 'Type a choice and press Enter. They are offered in this order.', 'plugixa-car-parking' ),
			'form_field_must_tick'        => __( 'Must be ticked to book', 'plugixa-car-parking' ),
			'form_remove_field'           => __( 'Remove field', 'plugixa-car-parking' ),
			/* translators: %s: a value the app fills in. */
			'form_remove_field_named'     => __( 'Remove the field %s', 'plugixa-car-parking' ),
			'form_no_fields'              => __( 'No fields of your own yet. Add one to ask for something more, such as a flight number or the car’s make.', 'plugixa-car-parking' ),
			'form_no_locations'           => __( 'There are no locations to choose from yet.', 'plugixa-car-parking' ),
			'form_locations'              => __( 'Locations', 'plugixa-car-parking' ),
			'form_shortcode_copied'       => __( 'Shortcode copied.', 'plugixa-car-parking' ),
			'form_shortcode_copy_failed'  => __( 'Could not copy. Select the shortcode and copy it by hand.', 'plugixa-car-parking' ),
			'form_copy_shortcode'         => __( 'Copy shortcode', 'plugixa-car-parking' ),
			'form_drag_instructions'      => __( 'Press Space or Enter to pick this row up. Use the arrow keys to move it, Space or Enter to drop it, and Escape to cancel.', 'plugixa-car-parking' ),
			'form_drag_to_reorder'        => __( 'Drag to reorder', 'plugixa-car-parking' ),
			'form_steps'                  => __( 'Steps', 'plugixa-car-parking' ),
			'form_steps_hint'             => __( 'Drag a step to change where it comes: the booking form shows them in this order. The form always starts with the dates and ends with the review.', 'plugixa-car-parking' ),
			/* translators: %s: a value the app fills in. */
			'form_move_step'              => __( 'Move the step %s', 'plugixa-car-parking' ),
			'form_step_required'          => __( 'Always shown', 'plugixa-car-parking' ),
			'form_step_on'                => __( 'On', 'plugixa-car-parking' ),
			'form_step_off'               => __( 'Off', 'plugixa-car-parking' ),
			'form_step_coupon'            => __( 'Offer the coupon code field on this form', 'plugixa-car-parking' ),
			'form_step_dates'             => __( 'Dates and location', 'plugixa-car-parking' ),
			'form_step_space'             => __( 'Choose a space', 'plugixa-car-parking' ),
			'form_step_details'           => __( 'Customer details', 'plugixa-car-parking' ),
			'form_step_payment'           => __( 'Payment method', 'plugixa-car-parking' ),
			'form_step_confirm'           => __( 'Review and confirm', 'plugixa-car-parking' ),
			'form_step_dates_hint'        => __( 'The customer picks a location, an entry time and an exit time.', 'plugixa-car-parking' ),
			'form_step_space_hint'        => __( 'The spaces free for that stay, with the price of each.', 'plugixa-car-parking' ),
			'form_step_details_hint'      => __( 'Name, contact details and the fields you add below.', 'plugixa-car-parking' ),
			'form_step_confirm_hint'      => __( 'A summary of the booking and the button that places it.', 'plugixa-car-parking' ),
			'form_field_type_text'        => __( 'Text', 'plugixa-car-parking' ),
			'form_field_type_email'       => __( 'Email address', 'plugixa-car-parking' ),
			'form_field_type_tel'         => __( 'Phone number', 'plugixa-car-parking' ),
			'form_field_type_textarea'    => __( 'Paragraph', 'plugixa-car-parking' ),
			'form_field_type_select'      => __( 'List of choices', 'plugixa-car-parking' ),
			'form_field_type_checkbox'    => __( 'Checkbox', 'plugixa-car-parking' ),
			'form_builtin_name'           => __( 'Full name', 'plugixa-car-parking' ),
			'form_builtin_email'          => __( 'Email', 'plugixa-car-parking' ),
			'form_builtin_phone'          => __( 'Phone', 'plugixa-car-parking' ),
			'form_builtin_plate'          => __( 'License plate', 'plugixa-car-parking' ),
			'form_name_required'          => __( 'A form needs a name.', 'plugixa-car-parking' ),
			'form_color_invalid'          => __( 'A colour is written like #1976d2.', 'plugixa-car-parking' ),

			// What a screen reader is told as a row is dragged. %1$s is always the row's own name.
			'form_step_move_refused' => __( 'That step stays where it is. A space can only be offered once the dates are known, and the booking is placed on the review, so the dates come first and the review last.', 'plugixa-car-parking' ),
			/* translators: 1: the name of the step or field being moved. 2: its position in the list. 3: how many rows the list has. */
			'sort_picked_up'         => __( 'Picked up %1$s. It is in position %2$s of %3$s.', 'plugixa-car-parking' ),
			/* translators: %1$s: the name of the step or field being moved. */
			'sort_not_over'          => __( '%1$s is not over a place it can be dropped.', 'plugixa-car-parking' ),
			/* translators: 1: the name of the step or field being moved. 2: its position in the list. 3: how many rows the list has. */
			'sort_over_refused'      => __( '%1$s cannot go in position %2$s of %3$s.', 'plugixa-car-parking' ),
			/* translators: 1: the name of the step or field being moved. 2: its position in the list. 3: how many rows the list has. */
			'sort_moved_over'        => __( '%1$s is now in position %2$s of %3$s.', 'plugixa-car-parking' ),
			/* translators: %1$s: the name of the step or field being moved. */
			'sort_put_back'          => __( '%1$s was put back where it was.', 'plugixa-car-parking' ),
			/* translators: %1$s: the name of the step or field being moved. */
			'sort_refused'           => __( '%1$s cannot go there, so it was put back where it was.', 'plugixa-car-parking' ),
			/* translators: 1: the name of the step or field being moved. 2: its position in the list. 3: how many rows the list has. */
			'sort_dropped'           => __( '%1$s was dropped in position %2$s of %3$s.', 'plugixa-car-parking' ),
			/* translators: %1$s: the name of the step or field being moved. */
			'sort_cancelled'         => __( 'Moving %1$s was cancelled. It was put back where it was.', 'plugixa-car-parking' ),

			// Added by generate-strings.php. Check the wording, then move each line to where it reads best.
			'form_default'                   => __( 'Default form', 'plugixa-car-parking' ),
			'form_default_locked_hint'       => __( 'Pages that name no form use this one. Make another form the default to change it.', 'plugixa-car-parking' ),
			'form_default_hint'              => __( 'Turn on to use this form on pages that name no form, in place of the current default.', 'plugixa-car-parking' ),
			'form_space_types'               => __( 'Space types', 'plugixa-car-parking' ),
			'form_space_types_hint'          => __( 'Leave empty to offer every type.', 'plugixa-car-parking' ),
			'form_no_space_types'            => __( 'There are no space types to choose from yet.', 'plugixa-car-parking' ),
			'form_make_default'              => __( 'Make default', 'plugixa-car-parking' ),
			'form_preview_location_inactive' => __( 'This location is switched off, so customers do not see it.', 'plugixa-car-parking' ),
			'form_preview_nothing_offered'   => __( 'Nothing can be booked here: no space type this form offers has spaces and a price at this location.', 'plugixa-car-parking' ),
			'form_preview_title'             => __( 'What customers will see', 'plugixa-car-parking' ),
			'form_preview_empty'             => __( 'This form has no location a customer can book at.', 'plugixa-car-parking' ),
			'form_all_locations'             => __( 'Available at all locations (including ones added later)', 'plugixa-car-parking' ),
			'form_locations_only_hint'       => __( 'The form books only at these.', 'plugixa-car-parking' ),
			'form_scope_failed'              => __( 'The list could not be loaded.', 'plugixa-car-parking' ),
			'form_shortcode_location'        => __( 'Start on location', 'plugixa-car-parking' ),
			'form_shortcode_location_any'    => __( 'First available', 'plugixa-car-parking' ),
			'form_shortcode_block_hint'      => __( 'In the block editor, the “Car Parking Booking Form” block does the same: choose this form in its settings.', 'plugixa-car-parking' ),
			'form_step_coupon_setting'       => __( 'Coupons also need “Accept coupon codes” turned on under Settings → Bookings.', 'plugixa-car-parking' ),
			'form_step_coupon_setting_off'   => __( '“Accept coupon codes” is turned off under Settings → Bookings, so no form takes a code until it is turned on.', 'plugixa-car-parking' ),
			'form_step_payment_methods_hint' => __( 'Lets the customer choose how to pay. Shown only when two or more payment methods are set up; otherwise the one method is used. Switched off, every booking is paid on arrival.', 'plugixa-car-parking' ),
			'form_locations_required'        => __( 'Choose at least one location, or make the form available at all locations.', 'plugixa-car-parking' ),
			'form_list_locations'       => __( 'Locations', 'plugixa-car-parking' ),
			'form_list_all_locations'   => __( 'All locations', 'plugixa-car-parking' ),
			'form_list_space_types'     => __( 'Space types', 'plugixa-car-parking' ),
			'form_list_all_space_types' => __( 'All space types', 'plugixa-car-parking' ),
			'form_copy'                 => __( 'Copy', 'plugixa-car-parking' ),
			'form_design_split'        => __( 'Split', 'plugixa-car-parking' ),
			'form_design_split_hint'   => __( 'Steps and summary in a side column. Needs a wide page.', 'plugixa-car-parking' ),
			'form_design_minimal'      => __( 'Minimal', 'plugixa-car-parking' ),
			'form_design_minimal_hint' => __( 'One quiet column with a progress bar.', 'plugixa-car-parking' ),
			'form_design_classic'      => __( 'Classic', 'plugixa-car-parking' ),
			'form_design_classic_hint' => __( 'Numbered steps with the summary beside them.', 'plugixa-car-parking' ),
			'form_design'              => __( 'Design', 'plugixa-car-parking' ),
		);
	}
}
