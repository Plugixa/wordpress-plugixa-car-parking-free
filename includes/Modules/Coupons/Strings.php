<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Modules\Coupons;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The strings of the screens under `src/modules/coupons/`, translated.
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
			'coupons'                          => __( 'Coupons', 'plugixa-car-parking' ),
			'edit_coupon'                      => __( 'Edit coupon', 'plugixa-car-parking' ),
			'new_coupon'                       => __( 'New coupon', 'plugixa-car-parking' ),
			'coupon_status'                    => __( 'Status', 'plugixa-car-parking' ),
			'coupon_status_hint'               => __( 'An inactive coupon is refused when a customer types it.', 'plugixa-car-parking' ),
			'coupon_redeemed'                  => __( 'Redeemed', 'plugixa-car-parking' ),
			'coupon_redeemed_of'               => __( '{used} of {limit} times', 'plugixa-car-parking' ),
			'coupon_redeemed_times'            => __( '{used} times', 'plugixa-car-parking' ),
			'coupon_code'                      => __( 'Code', 'plugixa-car-parking' ),
			'coupon_code_hint'                 => __( 'What the customer types when booking.', 'plugixa-car-parking' ),
			'coupon_generate'                  => __( 'Generate a code', 'plugixa-car-parking' ),
			'coupon_discount_type'             => __( 'Discount type', 'plugixa-car-parking' ),
			'coupon_amount'                    => __( 'Amount', 'plugixa-car-parking' ),
			'coupon_amount_hint_percent'       => __( 'The share of the price taken off, up to 100.', 'plugixa-car-parking' ),
			'coupon_amount_hint_fixed'         => __( 'Taken off the price. Never more than there is to pay.', 'plugixa-car-parking' ),
			'coupon_limits'                    => __( 'Limits', 'plugixa-car-parking' ),
			'coupon_min_amount'                => __( 'Minimum spend', 'plugixa-car-parking' ),
			'coupon_min_amount_hint'           => __( 'The price a booking must reach. Empty for none.', 'plugixa-car-parking' ),
			'coupon_usage_limit'               => __( 'Usage limit', 'plugixa-car-parking' ),
			'coupon_usage_limit_hint'          => __( 'How many bookings may use it in all. Empty for no limit.', 'plugixa-car-parking' ),
			'coupon_usage_limit_per_user'      => __( 'Usage limit per customer', 'plugixa-car-parking' ),
			'coupon_usage_limit_per_user_hint' => __( 'Applies to signed-in customers only. Empty for no limit.', 'plugixa-car-parking' ),
			'coupon_validity_window'           => __( 'Validity', 'plugixa-car-parking' ),
			'coupon_valid_from'                => __( 'Valid from', 'plugixa-car-parking' ),
			'coupon_valid_from_hint'           => __( 'On the site’s clock. Empty to start at once.', 'plugixa-car-parking' ),
			'coupon_valid_to'                  => __( 'Valid until', 'plugixa-car-parking' ),
			'coupon_valid_to_hint'             => __( 'On the site’s clock. Empty to never end.', 'plugixa-car-parking' ),
			'coupon_scope'                     => __( 'Limited to', 'plugixa-car-parking' ),
			'coupon_locations'                 => __( 'Locations', 'plugixa-car-parking' ),
			'coupon_locations_hint'            => __( 'Empty for any location.', 'plugixa-car-parking' ),
			'coupon_space_types'               => __( 'Space types', 'plugixa-car-parking' ),
			'coupon_space_types_hint'          => __( 'Empty for any space type.', 'plugixa-car-parking' ),
			'coupon_state_scheduled'           => __( 'Scheduled', 'plugixa-car-parking' ),
			'coupon_state_used_up'             => __( 'Used up', 'plugixa-car-parking' ),
			'coupon_why_inactive'              => __( 'Switched off.', 'plugixa-car-parking' ),
			'coupon_why_scheduled'             => __( 'Starts {date}.', 'plugixa-car-parking' ),
			'coupon_why_expired'               => __( 'Ended {date}.', 'plugixa-car-parking' ),
			'coupon_why_used_up'               => __( 'Redeemed {used} of {limit} times.', 'plugixa-car-parking' ),
			'coupon_why_active'                => __( 'Can be redeemed now.', 'plugixa-car-parking' ),
			'coupon_scope_one_location'        => __( '1 location', 'plugixa-car-parking' ),
			'coupon_scope_locations'           => __( '{count} locations', 'plugixa-car-parking' ),
			'coupon_scope_one_space_type'      => __( '1 space type', 'plugixa-car-parking' ),
			'coupon_scope_space_types'         => __( '{count} space types', 'plugixa-car-parking' ),
			'coupons_switched_off'             => __( 'Coupons are switched off in the settings, so customers are not asked for a code. You can still prepare codes here.', 'plugixa-car-parking' ),
			'coupon_type'                      => __( 'Type', 'plugixa-car-parking' ),
			'coupon_value'                     => __( 'Value', 'plugixa-car-parking' ),
			'coupon_usage'                     => __( 'Used', 'plugixa-car-parking' ),
			'coupon_per_customer_n'            => __( '{count} per customer', 'plugixa-car-parking' ),
			'coupon_validity'                  => __( 'Valid', 'plugixa-car-parking' ),
			'coupon_valid_always'              => __( 'Always', 'plugixa-car-parking' ),
			'coupon_scope_anything'            => __( 'Anything', 'plugixa-car-parking' ),
			'search_coupons'                   => __( 'Search by code…', 'plugixa-car-parking' ),
			'no_coupons'                       => __( 'No coupons yet', 'plugixa-car-parking' ),
			'no_coupons_hint'                  => __( 'A coupon is a code a customer types when booking to get money off. Add one, and say how much it takes off and when it is valid.', 'plugixa-car-parking' ),
			'coupon_type_fixed'                => __( 'Fixed amount', 'plugixa-car-parking' ),
			'coupon_type_percent'              => __( 'Percentage', 'plugixa-car-parking' ),
			'coupon_limit_invalid'             => __( 'A whole number of at least 1, or empty for no limit.', 'plugixa-car-parking' ),
			'coupon_date_invalid'              => __( 'That is not a date and time.', 'plugixa-car-parking' ),
			'coupon_code_required'             => __( 'A coupon needs a code.', 'plugixa-car-parking' ),
			'coupon_code_too_long'             => __( 'A code can be at most 60 characters.', 'plugixa-car-parking' ),
			'coupon_amount_invalid'            => __( 'A number, with a dot for decimals.', 'plugixa-car-parking' ),
			'coupon_percent_too_large'         => __( 'A percentage coupon cannot take off more than 100.', 'plugixa-car-parking' ),
			'coupon_range_invalid'             => __( 'The coupon must stop being valid on or after the day it starts.', 'plugixa-car-parking' ),
		);
	}
}
