<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Modules\Bookings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The strings of the screens under `src/modules/bookings/`, translated.
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
			'booking_action_reinstate'         => __( 'Reinstate', 'plugixa-car-parking' ),
			'booking_action_confirm'           => __( 'Confirm', 'plugixa-car-parking' ),
			'booking_action_complete'          => __( 'Mark completed', 'plugixa-car-parking' ),
			'booking_action_cancel'            => __( 'Cancel booking', 'plugixa-car-parking' ),
			'booking_action_no_show'           => __( 'Mark no-show', 'plugixa-car-parking' ),
			'booking_customer'                 => __( 'Customer', 'plugixa-car-parking' ),
			'booking_email'                    => __( 'Email', 'plugixa-car-parking' ),
			'booking_phone'                    => __( 'Phone', 'plugixa-car-parking' ),
			'booking_plate'                    => __( 'Licence plate', 'plugixa-car-parking' ),
			'booking_vehicle'                  => __( 'Vehicle', 'plugixa-car-parking' ),
			'booking_location'                 => __( 'Location', 'plugixa-car-parking' ),
			'booking_space_type'               => __( 'Space type', 'plugixa-car-parking' ),
			'booking_quantity'                 => __( 'Spaces', 'plugixa-car-parking' ),
			'booking_entry'                    => __( 'Entry', 'plugixa-car-parking' ),
			'booking_exit'                     => __( 'Exit', 'plugixa-car-parking' ),
			'booking_duration'                 => __( 'Duration', 'plugixa-car-parking' ),
			'booking_billed'                   => __( 'Billed', 'plugixa-car-parking' ),
			'booking_held_until'               => __( 'Space held until', 'plugixa-car-parking' ),
			'booking'                          => __( 'Booking', 'plugixa-car-parking' ),
			'bookings'                         => __( 'Bookings', 'plugixa-car-parking' ),
			'booking_payment'                  => __( 'Payment', 'plugixa-car-parking' ),
			'booking_total'                    => __( 'Total', 'plugixa-car-parking' ),
			'booking_paid'                     => __( 'Paid', 'plugixa-car-parking' ),
			'booking_balance'                  => __( 'Balance due', 'plugixa-car-parking' ),
			'booking_force_action'             => __( 'Do it anyway', 'plugixa-car-parking' ),
			'booking_reload'                   => __( 'Reload', 'plugixa-car-parking' ),
			'booking_price'                    => __( 'Price', 'plugixa-car-parking' ),
			'booking_no_lines'                 => __( 'This booking has no price lines.', 'plugixa-car-parking' ),
			'booking_line_item'                => __( 'Item', 'plugixa-car-parking' ),
			'booking_line_quantity'            => __( 'Qty', 'plugixa-car-parking' ),
			'booking_line_unit_price'          => __( 'Unit price', 'plugixa-car-parking' ),
			'booking_line_amount'              => __( 'Amount', 'plugixa-car-parking' ),
			'booking_notes'                    => __( 'Notes', 'plugixa-car-parking' ),
			'booking_no_notes'                 => __( 'No notes on this booking.', 'plugixa-car-parking' ),
			'booking_cancel_title'             => __( 'Cancel this booking?', 'plugixa-car-parking' ),
			'booking_cancel_body'              => __( 'Its space becomes free for somebody else to book. Payments already recorded stay as they are.', 'plugixa-car-parking' ),
			'booking_cancel_keep'              => __( 'Keep booking', 'plugixa-car-parking' ),
			'edit_booking'                     => __( 'Edit booking', 'plugixa-car-parking' ),
			'new_booking'                      => __( 'New booking', 'plugixa-car-parking' ),
			'booking_status'                   => __( 'Status', 'plugixa-car-parking' ),
			'booking_status_default_hint'      => __( 'Left on the default, it takes the status the settings give a new booking.', 'plugixa-car-parking' ),
			'booking_status_default'           => __( 'Default for new bookings', 'plugixa-car-parking' ),
			'booking_coupon'                   => __( 'Coupon code', 'plugixa-car-parking' ),
			'booking_coupon_hint'              => __( 'Optional. The price below shows whether it applies.', 'plugixa-car-parking' ),
			'booking_price_kept'               => __( 'A booking keeps the price it was made at. Changing the dates, the space type or the number of spaces does not change what the customer owes.', 'plugixa-car-parking' ),
			'booking_section_stay'             => __( 'Stay', 'plugixa-car-parking' ),
			'booking_location_none'            => __( 'There are no locations yet. Add one under Locations first.', 'plugixa-car-parking' ),
			'booking_stay_length'              => __( 'A stay of {length}, on the location’s own clock.', 'plugixa-car-parking' ),
			'booking_dates_hint'               => __( 'Entry and exit are on the location’s own clock, whatever timezone you are in.', 'plugixa-car-parking' ),
			'booking_force'                    => __( 'Save it anyway, even though the location is full or closed at that time', 'plugixa-car-parking' ),
			'booking_section_customer'         => __( 'Customer', 'plugixa-car-parking' ),
			'booking_customer_name'            => __( 'Name', 'plugixa-car-parking' ),
			'booking_section_vehicle'          => __( 'Vehicle', 'plugixa-car-parking' ),
			'booking_notes_hint'               => __( 'For your team. Customers do not see these.', 'plugixa-car-parking' ),
			'booking_mass_status'              => __( 'Set status', 'plugixa-car-parking' ),
			'booking_ref'                      => __( 'Reference', 'plugixa-car-parking' ),
			'booking_payment_status'           => __( 'Payment', 'plugixa-car-parking' ),
			'booking_created'                  => __( 'Created', 'plugixa-car-parking' ),
			'search_bookings'                  => __( 'Search reference, customer or plate…', 'plugixa-car-parking' ),
			'no_bookings'                      => __( 'No bookings yet', 'plugixa-car-parking' ),
			'no_bookings_hint'                 => __( 'A booking holds a space at a location for a stay. Customers make them on your site; you can also take one here, over the phone or at the gate.', 'plugixa-car-parking' ),
			'booking_view'                     => __( 'View booking', 'plugixa-car-parking' ),
			'booking_price_waiting'            => __( 'Choose a location, a space type and the dates to see what the stay costs.', 'plugixa-car-parking' ),
			'booking_coupon_not_applied'       => __( 'That coupon does not apply to this booking, so the price does not include it.', 'plugixa-car-parking' ),
			'booking_price_fixed_on_save'      => __( 'The price is fixed when the booking is saved.', 'plugixa-car-parking' ),
			'booking_space_type_pick_location' => __( 'Choose a location first.', 'plugixa-car-parking' ),
			'booking_space_type_load_failed'   => __( 'The space types of this location could not be loaded.', 'plugixa-car-parking' ),
			'booking_space_type_none'          => __( 'This location has no spaces yet. Add them on the location’s page.', 'plugixa-car-parking' ),
			'booking_duration_days'            => __( '{n} d', 'plugixa-car-parking' ),
			'booking_duration_hours'           => __( '{n} h', 'plugixa-car-parking' ),
			'booking_duration_minutes'         => __( '{n} min', 'plugixa-car-parking' ),
			'booking_refusal_full'             => __( 'There is no free space of that type for those dates.', 'plugixa-car-parking' ),
			'booking_refusal_too_soon'         => __( 'The entry is in the past, or sooner than the notice the booking settings ask for. That limit applies to bookings made here too.', 'plugixa-car-parking' ),
			'booking_refusal_too_far'          => __( 'The entry is further ahead than the booking settings allow. That limit applies to bookings made here too.', 'plugixa-car-parking' ),
			'booking_location_required'        => __( 'Choose the location the booking is for.', 'plugixa-car-parking' ),
			'booking_space_type_required'      => __( 'Choose a space type.', 'plugixa-car-parking' ),
			'booking_entry_required'           => __( 'Say when the stay starts.', 'plugixa-car-parking' ),
			'booking_exit_required'            => __( 'Say when the stay ends.', 'plugixa-car-parking' ),
			'booking_quantity_invalid'         => __( 'A booking is for at least one space.', 'plugixa-car-parking' ),
			'booking_email_invalid'            => __( 'That is not an email address.', 'plugixa-car-parking' ),
			'booking_exit_before_entry'        => __( 'The exit must be after the entry.', 'plugixa-car-parking' ),

			// Added by generate-strings.php. Check the wording, then move each line to where it reads best.
			'booking_restore_force_consequence' => __( 'Restoring it anyway puts the booking back without a free space to hold. The space may then be double-booked, or the booking may fall at a time the location is closed.', 'plugixa-car-parking' ),
			'booking_additional_details'      => __( 'Additional details', 'plugixa-car-parking' ),
			'booking_additional_details_hint' => __( 'What the customer answered in the fields this booking’s form adds. Each keeps the label it was asked under, even if the form has changed since.', 'plugixa-car-parking' ),
			'booking_origin_staff'            => __( 'Added by staff', 'plugixa-car-parking' ),
			'booking_origin_customer'         => __( 'Customer account', 'plugixa-car-parking' ),
			'booking_origin_guest'            => __( 'Guest', 'plugixa-car-parking' ),
			'booking_origin_staff_hint'       => __( 'A member of your team recorded this booking for the customer.', 'plugixa-car-parking' ),
			'booking_origin_customer_hint'    => __( 'The customer made this booking while signed in to their account.', 'plugixa-car-parking' ),
			'booking_origin_guest_hint'       => __( 'Made without an account. The customer reaches it with the reference and their email address.', 'plugixa-car-parking' ),
		);
	}
}
