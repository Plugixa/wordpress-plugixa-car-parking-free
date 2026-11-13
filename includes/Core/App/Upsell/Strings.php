<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Core\App\Upsell;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The words of the free build's Pro features page, header button and dashboard card.
 *
 * Kept apart from the shell's strings because this is the one free file allowed to name what the
 * premium plugin adds: ArchitectureTest and tools/bin/free-scan.ps1 excuse these exact sentences
 * and nothing else. A sentence changed here has to be changed in both of those lists too, or the
 * suite and the release scan fail. Premium carries this file but never shows it (see Edition).
 *
 * The cards follow the readme's "Pro Features" section as published on WordPress.org. Change
 * them together, and only to describe what the premium plugin really does.
 *
 * The keys name no premium feature, because a key is code: the scans read it as such.
 */
final class Strings {

	/**
	 * @return array<string, string> Key => translated text.
	 */
	public static function all(): array {
		return array(
			// The menu entry, the header button and the page.
			'pro_nav'            => __( 'Upgrade to Pro', 'plugixa-car-parking' ),
			'pro_see_features'   => __( 'See Pro features', 'plugixa-car-parking' ),
			'pro_page_title'     => __( 'Plugixa Car Parking Pro', 'plugixa-car-parking' ),
			'pro_page_intro'     => __( 'Everything in the free plugin keeps working. Pro adds the tools below for selling more kinds of parking, taking payment online and keeping customers informed. Your locations, bookings and payments stay as they are when you upgrade.', 'plugixa-car-parking' ),
			'pro_see_plans'      => __( 'See Pro plans', 'plugixa-car-parking' ),
			'pro_compare_plans'  => __( 'Compare plans', 'plugixa-car-parking' ),
			'pro_owned_note'     => __( 'Already bought Pro? Download it from your Plugixa account and install it. It takes over from this plugin and keeps all your data.', 'plugixa-car-parking' ),
			'pro_opens_new_tab'  => __( '(opens in a new tab)', 'plugixa-car-parking' ),

			// The page's groups and cards.
			'pro_group_pricing'  => __( 'Pricing', 'plugixa-car-parking' ),
			'pro_group_payments' => __( 'Payments', 'plugixa-car-parking' ),
			'pro_group_spaces'   => __( 'Spaces and bookings', 'plugixa-car-parking' ),
			'pro_group_messages' => __( 'Messages', 'plugixa-car-parking' ),

			'pro_f_price_rules'       => __( 'Pricing rules', 'plugixa-car-parking' ),
			'pro_f_price_rules_desc'  => __( 'Set prices by customer group, date, weekday, arrival time, length of stay, location or space type.', 'plugixa-car-parking' ),
			'pro_f_group_prices'      => __( 'Customer group prices', 'plugixa-car-parking' ),
			'pro_f_group_prices_desc' => __( 'Give regulars, staff or partner companies their own prices.', 'plugixa-car-parking' ),
			'pro_f_rates'             => __( 'Tax rates', 'plugixa-car-parking' ),
			'pro_f_rates_desc'        => __( 'Add tax by rate, shown on every booking and invoice.', 'plugixa-car-parking' ),
			'pro_f_part_pay'          => __( 'Deposits', 'plugixa-car-parking' ),
			'pro_f_part_pay_desc'     => __( 'Take part of the price when booking and the rest later.', 'plugixa-car-parking' ),

			'pro_f_cards'             => __( 'Stripe card payments', 'plugixa-car-parking' ),
			'pro_f_cards_desc'        => __( 'Card payments through Stripe Checkout, with refunds from the booking.', 'plugixa-car-parking' ),
			'pro_f_monthly'           => __( 'Monthly payments', 'plugixa-car-parking' ),
			'pro_f_monthly_desc'      => __( 'Let long stays be paid month by month by card.', 'plugixa-car-parking' ),
			'pro_f_europe'            => __( 'PayPal, Mollie, Adyen and Klarna', 'plugixa-car-parking' ),
			'pro_f_europe_desc'       => __( 'More ways to pay, including the local methods European customers use.', 'plugixa-car-parking' ),
			'pro_f_invoices'          => __( 'PDF invoices', 'plugixa-car-parking' ),
			'pro_f_invoices_desc'     => __( 'Invoices as PDF files, attached to the emails your customers get.', 'plugixa-car-parking' ),

			'pro_f_bays'              => __( 'Numbered spaces', 'plugixa-car-parking' ),
			'pro_f_bays_desc'         => __( 'Add bays in a run, A1 to A20, and let customers pick one or get one assigned.', 'plugixa-car-parking' ),
			'pro_f_add_ons'           => __( 'Bookable extras', 'plugixa-car-parking' ),
			'pro_f_add_ons_desc'      => __( 'Offer add-ons such as a car wash or EV charging during booking.', 'plugixa-car-parking' ),
			'pro_f_move_dates'        => __( 'Customers change their dates', 'plugixa-car-parking' ),
			'pro_f_move_dates_desc'   => __( 'Customers move a booking to other dates themselves.', 'plugixa-car-parking' ),
			'pro_f_finder'            => __( 'Car park map', 'plugixa-car-parking' ),
			'pro_f_finder_desc'       => __( 'A Google Map of your locations, also inside the booking form.', 'plugixa-car-parking' ),

			'pro_f_texts'             => __( 'Text messages', 'plugixa-car-parking' ),
			'pro_f_texts_desc'        => __( 'Booking messages by SMS through Twilio or Vonage.', 'plugixa-car-parking' ),
			'pro_f_chat'              => __( 'Telegram notices', 'plugixa-car-parking' ),
			'pro_f_chat_desc'         => __( 'Booking notices posted to your Telegram chat.', 'plugixa-car-parking' ),
			'pro_f_before_stay'       => __( 'Reminders', 'plugixa-car-parking' ),
			'pro_f_before_stay_desc'  => __( 'Remind customers before they arrive.', 'plugixa-car-parking' ),
			'pro_f_digest'            => __( 'Daily email summary', 'plugixa-car-parking' ),
			'pro_f_digest_desc'       => __( 'A summary of the last day of bookings, emailed to you every day.', 'plugixa-car-parking' ),

			'pro_group_office'        => __( 'Emails and reports', 'plugixa-car-parking' ),
			'pro_f_mail_look'         => __( 'Designed emails', 'plugixa-car-parking' ),
			'pro_f_mail_look_desc'    => __( 'Your logo and colours on every email, with a preview and a test send.', 'plugixa-car-parking' ),
			'pro_f_more_mail'         => __( 'More booking emails', 'plugixa-car-parking' ),
			'pro_f_more_mail_desc'    => __( 'Tell customers when a booking is cancelled, runs out unpaid, moves or changes status.', 'plugixa-car-parking' ),
			'pro_f_figures'           => __( 'Reports', 'plugixa-car-parking' ),
			'pro_f_figures_desc'      => __( 'Revenue, occupancy and coupon use for any dates and location, with CSV export.', 'plugixa-car-parking' ),
			'pro_f_numbering'         => __( 'Invoice numbers', 'plugixa-car-parking' ),
			'pro_f_numbering_desc'    => __( 'Numbers in order with no gaps, on every invoice and in emails.', 'plugixa-car-parking' ),

			'pro_group_gate'          => __( 'Arrivals and regulars', 'plugixa-car-parking' ),
			'pro_f_gate'              => __( 'Check-in with QR codes', 'plugixa-car-parking' ),
			'pro_f_gate_desc'         => __( 'Staff scan the code on each booking, or look it up by plate, to check cars in and out.', 'plugixa-car-parking' ),
			'pro_f_regulars'          => __( 'Season passes', 'plugixa-car-parking' ),
			'pro_f_regulars_desc'     => __( 'Sell monthly parking to regulars, paid by card each month until they cancel.', 'plugixa-car-parking' ),

			// The dashboard card.
			'pro_card_title'     => __( 'Get more from Plugixa Car Parking', 'plugixa-car-parking' ),
			'pro_card_body'      => __( 'Online card payments, numbered spaces, pricing rules, deposits and more come with Pro.', 'plugixa-car-parking' ),
			'pro_card_hide'      => __( 'Hide this card', 'plugixa-car-parking' ),
		);
	}
}
