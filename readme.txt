=== Plugixa Car Parking – Parking Booking, Reservations & Live Availability ===
Contributors: zakaria04, freemius
Tags: parking, parking booking, reservation, car park, booking
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 1.3.2
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Online parking reservations: a step-by-step booking form with live availability, prices per hour, day or month, and a React admin.

== Description ==

🅿️ Take parking reservations on your own site with **Plugixa Car Parking** - a step-by-step booking form with live availability, prices worked out on the server, and an admin app for your bookings, locations and payments.

📚 Read the [**Documentation**](https://www.plugixa.com/documentation/plugixa-car-parking)
🌐 Visit the official website [**Plugixa**](https://www.plugixa.com/)
✉️ Questions or support requests: [**Contact us**](https://www.plugixa.com/contact)
💬 Ask on the [**Support Forum**](https://wordpress.org/support/plugin/plugixa-car-parking/)
🚀 [**Upgrade to Pro**](https://www.plugixa.com/plugins/plugixa-car-parking#pricing)

Built for parking lots, garages, airport park & ride, valet, hospital and hotel parking, residential and EV-charging spaces.

Take your first booking in **3 simple steps**:

1. Add a location - its opening hours, closures and the kinds of space it offers
2. Set your prices - per minute, hour, day, week or month for each space type
3. Place the booking form on a page with a shortcode or a block - customers pick their dates, see what is free and book

Records live in the plugin's **own database tables**, not in posts and post meta, so the bookings list, the calendar and the dashboard stay fast as your history grows.

### Bookings That Cannot Overbook

✅ **Booking form** - dates, space, details and confirmation, in three designs
✅ **Live availability** - each space type shows how many spaces are left for the dates chosen
✅ **Server-side pricing** - the price is worked out on the server, never trusted from the browser
✅ **Calendar** - every booking by month, week and day, with occupancy
✅ **Pay on arrival** - with your own instructions, and a payments ledger to record what was paid

= Key Features of Plugixa Car Parking =

**Booking on your site**

* A step-by-step booking form, placed with the `[plugixa_car_parking]` shortcode or a Gutenberg block
* Three form designs: Classic (numbered steps with the summary beside them), Split (the steps and the summary in a side column) and Minimal (one column with a progress bar)
* Several booking forms, each with its own steps, fields, colour, locations and space types
* Your own fields on the booking form, checked by the server
* Quick stay lengths (1 day, 3 days, 1 week…), the length of the stay under the dates, and a minus and plus for the number of spaces
* Coupon codes with percentage or fixed discounts
* A booking summary page with an invoice and a Print button (`[plugixa_car_parking_summary]`)
* A customer account page to find a booking and cancel it (`[plugixa_car_parking_account]`)

**Locations and prices**

* Any number of locations, each with opening hours, closures and special days
* Space types with an icon, a colour, a size and features, counted by capacity
* Prices per minute, hour, day, week and month for each space type, with a one-off fee if you need one
* Different prices for the same space type at different locations
* A booking setup check on each location that lists what is missing before it can be booked

**Managing bookings**

* A dashboard with revenue, bookings, occupancy, arrivals and departures for the last 30 days
* A bookings list with search, filters, sorting and mass actions, and CSV export
* Taking a booking in the admin, priced and checked exactly like one from the site
* A calendar by month, week and day
* Pending bookings that expire and free their space
* A payments ledger: record a payment against a booking, and refund one
* Booking received, new booking and payment received emails, with a message log
* A trash with restore on every list

**Administration**

* Fast, Material UI-based admin application
* Public pages built for keyboard and screen-reader use
* A dark mode on the booking pages that follows the visitor's device, and support for right-to-left languages
* Translation-ready
* A REST API, actions and filters, and six capabilities to grant with a role editor

= Plugixa Car Parking Pro Features =

**Pricing**

* Pricing rules by date, weekday, arrival time, length of stay, location, space type and customer group
* Customer groups, tax rates and deposits

**Payments**

* Card payments through Stripe Checkout, and monthly payments for long stays
* PayPal, Mollie, Adyen and Klarna payments
* PDF invoices, attached to customer emails

**Spaces and bookings**

* Numbered spaces: a customer picks one, or is given one
* Extras a customer can add to a booking
* Customers move a booking to other dates themselves
* A map of your locations

**Messages**

* Text messages through Twilio or Vonage, and Telegram notices
* Reminders before arrival and a daily report by email
* Designed emails with your logo and colours, a preview and a test send
* Emails when a booking is cancelled, runs out unpaid, moves to other dates or changes status

**Reports and invoices**

* Reports of revenue, occupancy, coupons and extras for any dates and location, with CSV export
* Invoice numbers in order with no gaps, on every invoice and in emails

**Arrivals and regulars**

* Check-in with QR codes: staff scan the code on a booking, or find it by licence plate, to check cars in and out
* Season passes: monthly parking for regulars, paid by card each month until they cancel, sold with the `[plugixa_car_parking_passes]` shortcode

[Upgrade to Plugixa Car Parking Pro](https://www.plugixa.com/plugins/plugixa-car-parking#pricing)

= 🚗 A Space Is Never Sold Twice =

Availability is checked on the server when a booking is made, against every other booking that holds a space at that location for those dates. A pending booking holds its space only until it expires, and then the space is free again.

= 💶 Prices Worked Out on the Server =

The browser shows a price, but the booking is priced again on the server, in the location's billing unit. A space type with no price for a location's billing unit is not offered there, so nothing is ever booked for free by mistake.

= 🕒 Opening Hours and Closures =

A booking can only start and end while its location is open. Set a week of opening hours, or leave a location open around the clock, and add closures and special days such as holidays and maintenance work.

= 🖥️ On Your Own Site, in Your Own Theme =

The booking form, the summary page and the account page render inside your theme, in the design you choose for each form. They work with the keyboard and screen readers, follow the visitor's dark mode and read right to left where the language does.

### 👨‍💻 Author

[**Plugixa Car Parking**](https://www.plugixa.com/plugins/plugixa-car-parking) is developed by [**Plugixa**](https://www.plugixa.com/), building focused, well-documented WordPress plugins with an emphasis on performance, clean code and correct data.

= Shortcode Usage =

The booking form:
`[plugixa_car_parking]`

The booking form in another design:
`[plugixa_car_parking design="split"]`

The customer's account page:
`[plugixa_car_parking_account]`

The booking summary page:
`[plugixa_car_parking_summary]`

== Installation ==

= MINIMUM REQUIREMENTS =

* WordPress 6.5 or greater
* PHP version 8.2 or greater

= AUTOMATIC INSTALLATION (EASIEST WAY) =

1. In your WordPress admin, go to Plugins > Add New
2. Search for "Plugixa Car Parking"
3. Click Install Now, then Activate
4. Open "Car Parking" in the admin menu and configure your first location

= MANUAL INSTALLATION =

1. Download the plugin ZIP
2. Go to Plugins > Add New > Upload Plugin, choose the ZIP and click Install Now
   (or upload the `plugixa-car-parking` folder to `/wp-content/plugins/` over FTP)
3. Activate the plugin through the Plugins menu. The database tables are created on activation
4. Open "Car Parking" in the admin menu

== Frequently Asked Questions ==

= Q. Does the free plugin stop working after a trial period? =

No. There is no trial and no expiry. Every feature listed under "Key Features" works indefinitely.

= Q. Can two customers book the same space? =

No. Availability is checked on the server when the booking is made, against every booking that holds a space at that location for those dates.

= Q. Can I take card payments? =

Card payments (Stripe, PayPal, Mollie, Adyen, Klarna) are part of Pro. The free plugin supports pay-on-arrival / offline payment with instructions you write yourself, and a payments ledger to record what was paid.

= Q. Can I charge by the hour, the day or the month? =

Yes. Each location has a billing unit - minute, hour, day, week or month - and each space type has a price for it.

= Q. Does it store bookings as posts or custom post types? =

No. It uses its own database tables, created on activation. Bookings, prices and payments are relational data, and storing them as posts and post meta makes every list and report slower as the history grows.

= Q. What is the difference between the free and Pro versions? =

The free version is a complete booking system, neither time-limited nor feature-locked. Pro is a separate download that adds features; nothing in the free build is disabled, and the free build contains no Pro code to unlock. See the [Pro features](https://www.plugixa.com/plugins/plugixa-car-parking#pricing).

= Q. Does the plugin send any of my data anywhere? =

Not unless you ask it to. See the "External services" section below.

= Q. Where can I find the documentation? =

Full documentation - getting started, locations, pricing, booking forms, bookings, the calendar and payments - is at https://www.plugixa.com/documentation/plugixa-car-parking

= Q. Does the booking form load fonts from Google or another service? =

No. The booking form uses Inter and Space Grotesk, both under the SIL Open Font License 1.1. They are included in the plugin, in `assets/frontend/vendor/fonts/` with their licences, and are served from your own site.

== External services ==

The plugin itself sends nothing to any outside service: no analytics, no tracking, no remote configuration, no font or script CDN. Bookings, customers and payments stay in your WordPress database. Two things can contact a service, and both are listed here.

**Freemius.** Used for licence activation, plugin updates, and an optional opt-in. On activation you are shown an opt-in screen; if you skip it, no identifiable data is sent. When you activate a licence or check for an update, your site URL, the plugin and SDK versions and your licence key are sent to Freemius (api.freemius.com). If you opt in, the data that screen lists is sent to the same address: your WordPress user's first and last name and email address; your site's homepage URL and title, WordPress and PHP versions and site language; the plugin's version and whether it is active or uninstalled; and, unless you switch that item off on the opt-in screen, the names, slugs and versions of your installed plugins and themes and whether each is active. Terms: https://freemius.com/terms/ — Privacy: https://freemius.com/privacy/

**Payment, messaging and map services (Pro only, and only if you configure them).** The Pro version can contact the services below. Each stays inactive until you enter your own credentials for it in Settings, and the code for all of them ships only in Pro:

* Stripe (api.stripe.com) — when a customer pays by card: the amount, currency, booking reference and the customer's email. Terms: https://stripe.com/legal — Privacy: https://stripe.com/privacy
* PayPal (api-m.paypal.com, or api-m.sandbox.paypal.com in test mode) — when a customer pays with PayPal: the amount, currency and booking reference. Terms: https://www.paypal.com/legalhub/useragreement-full — Privacy: https://www.paypal.com/legalhub/privacy-full
* Mollie (api.mollie.com) — when a customer pays through Mollie: the amount, currency, booking reference and your site's return and notification addresses. Terms: https://www.mollie.com/user-agreement — Privacy: https://www.mollie.com/privacy
* Adyen (checkout-test.adyen.com in test mode, or your own *-checkout-live.adyenpayments.com address) — when a customer pays through Adyen: the amount, currency, booking reference, the country you set, the customer's email and your site's return address. Terms: https://www.adyen.com/legal/terms-and-conditions — Privacy: https://www.adyen.com/policies-and-disclaimer/privacy-policy
* Klarna (api.klarna.com, api-na.klarna.com or api-oc.klarna.com by the region you choose, or api.playground.klarna.com, api-na.playground.klarna.com or api-oc.playground.klarna.com in test mode) — when a customer pays with Klarna: the amount and the tax in it, currency, booking reference, the country you set, the customer's name, email and phone number, and your site's return and notification addresses. The plugin also asks Klarna how each payment went, up to three times in the 90 minutes after it starts. Terms: https://www.klarna.com/international/terms-and-conditions/ — Privacy: https://www.klarna.com/international/privacy-policy/
* Twilio (api.twilio.com) — when an SMS notification is sent: the customer's phone number and the message. Terms: https://www.twilio.com/legal/tos — Privacy: https://www.twilio.com/legal/privacy
* Vonage (rest.nexmo.com) — when an SMS notification is sent: the customer's phone number and the message. Terms: https://www.vonage.com/legal/communications-apis/terms-of-use/ — Privacy: https://www.vonage.com/legal/privacy-policy/
* Telegram (api.telegram.org) — when a booking notification is sent: the booking summary, to the chat you configure. Terms: https://telegram.org/tos — Privacy: https://telegram.org/privacy
* Google Maps JavaScript API (maps.googleapis.com) — loaded in the visitor's browser, with the API key you supply, only on pages that show the map; Google receives the visitor's IP address and browser details as with any page that loads its maps. The map's "Directions" link opens Google Maps only when the visitor clicks it. Terms: https://cloud.google.com/maps-platform/terms — Privacy: https://policies.google.com/privacy

== Screenshots ==

1. Dashboard - revenue, bookings, occupancy, arrivals and departures for the last 30 days
2. Bookings - every booking, with search, filters, sorting and CSV export
3. Booking details - the customer, the vehicle, the stay, the price and the payments of one booking
4. New booking - taking a booking in the admin, priced and checked like one from the site
5. Calendar - every booking by month, with arrivals, departures and occupancy
6. Payments - the ledger of payments and refunds recorded against bookings
7. Locations - every car park, with its billing unit, space types and booking forms
8. Location editor - the name, billing unit, address and contact details of a car park
9. Opening hours - the week a location is open; a booking starts and ends only inside it
10. Closures and special days - the dates a location is closed or keeps other hours
11. Space types - standard, covered, EV charging and every other kind of space you offer
12. Space type editor - the icon, colour, size and features of a kind of space
13. Coupons - percentage or fixed discounts, with usage limits and validity dates
14. Booking forms - several forms, each with its own locations and space types
15. Form editor - the steps and fields of a booking form, and the shortcode that places it
16. Settings - business details, currency, dates and times
17. Booking form, Classic design - where and when, with quick stay lengths
18. Choosing a space - live availability and the price of each space type for the dates chosen
19. Booking form, Split design - the steps and the booking summary in a side column
20. Booking form, Minimal design - one column with a progress bar

== Changelog ==

= 1.3.2 =
* Fix: emails show the entry and exit times in your date and time format, as the invoice does, instead of "2026-11-24 00:00:00".
* Fix: when two payment methods would show the same name to the customer, each shows its full name instead, so the customer can tell them apart.
* Fix: the change from the previous period is rounded to one decimal ("+87.9%").
* Add-on: editing a season pass shows its location and space type by name, instead of "#3".

= 1.3.1 =
* New: a fresh look for the booking form, the customer's bookings page and the booking summary, in all three designs: Space Grotesk headings, Inter text, flatter rows with a coloured mark on the one you choose, and tighter corners. Both typefaces are included in the plugin and are never loaded from a font service.
* New: the booking form shows the chosen car park's lowest price ("from $8.00 / day") before the customer checks availability.
* New: the dates start at the next full hour, after your minimum notice, instead of tomorrow at 08:00.
* New: an optional badge for each space type, such as "Most popular", and an optional reassurance line under the booking form's first button, such as your cancellation policy (Settings, Bookings). Both stay empty until you write them.
* New: each payment method shows an icon and a plain name ("Pay on arrival", "Pay online", "Credit / debit card").
* Improved: on phones the steps show the current step's name in full, every field is large enough that an iPhone does not zoom in, and the admin lists show one card per row instead of a table that scrolls sideways.
* Improved: the customer's bookings page and the booking summary use your booking form's colour.
* Improved: the Minimal design keeps to 720px on large screens.
* Add-on: with the map on, a wide booking form shows the fields on the left and the map on the right. Pins show the unit of their price ($8/day, $1.50/h), buttons are easier to tap, and extras with a quantity have a minus and plus.
* Developers: new filter `plugixa_carparking_payment_logos` to show logos beside a payment method. A gateway may add `customer_label()` and `icon()`.

= 1.3.0 =
* Add-on: email templates. Every email is sent in a designed layout with your logo, colour and footer, with the plain text kept for mail apps that cannot show it. New emails for a booking that is cancelled, runs out unpaid, is moved to other dates or has its status changed in the admin (this one starts off). Each can be switched off, reworded, copied to other addresses, previewed and sent to yourself as a test.
* Add-on: reports. Net revenue, bookings, occupancy and average stay for any dates and location, against the period before, with revenue by day, money by payment method, occupancy by location, coupons and extras. Each table exports as CSV.
* Add-on: invoice numbers. A booking gets its number the first time it is paid, so the numbers run in order with no gaps and are never reused. Set a prefix (with the year) and the number of digits, and number older paid bookings in one go.
* Add-on: check-in. A QR code on each booking's summary page, PDF invoice and emails, and a Check-in screen where staff scan it with the camera or find a booking by reference or licence plate, then check the car in and out. Checking out completes the booking. A new capability, plugixa_carparking_check_in, is given to administrators.
* Add-on: season passes. Monthly parking at one location and space type for a fixed price, with a limit on holders, sold with the `[plugixa_car_parking_passes]` shortcode and paid by card through Stripe each month until cancelled. Each pass holds a space while it runs, and holders can register two licence plates.
* Fix (add-on): text messages and Telegram notices said a booking was confirmed for every event, including a failed or ending monthly payment. They are now sent only for a new booking, a payment and the reminder.
* Developers: new hooks `plugixa_carparking_email_message`, `plugixa_carparking_email_placeholders`, `plugixa_carparking_booking_expired`, `plugixa_carparking_booking_status_changed`, `plugixa_carparking_invoice_header_lines` and `plugixa_carparking_invoice_after_header`. The notifier gains `compose()`, `deliver()` and `placeholders()`.

= 1.2.0 =
* Add-on: Mollie payments, with Mollie's hosted checkout: cards, iDEAL, Bancontact and the other methods you choose. Refunds go through Mollie, and refunds made in the Mollie dashboard and chargebacks are reported back. There is no webhook to set up, but your site must be reachable from the internet.
* Add-on: Adyen payments, with Adyen's Hosted Checkout. Refunds go through Adyen, and refunds made in the Customer Area and chargebacks are reported back. Needs a Standard webhook with an HMAC key.
* Add-on: Klarna payments, with Klarna's Hosted Payment Page. Each payment is captured as soon as the customer completes it, and refunds go through Klarna. Disputes and refunds made in Klarna's portal are not reported back. There is no webhook to set up.
* New: a customer who comes back before the payment provider has decided sees "Confirming your payment" instead of "Payment not completed".
* Fix: a payment that arrived after a booking's hold had run out confirmed the booking even when its space had since gone to someone else, selling it twice. The booking now stays expired, the money stays on its Payments panel, the site is emailed, and the customer is told. If the space is still free, the payment confirms the booking as before.
* Fix: a refund the payment provider accepted and then failed to make stayed on the booking as if the customer had the money. It is now marked Failed, the booking holds the money again, and the site is emailed. This covers Stripe, PayPal, Mollie and Adyen.
* New: the site is emailed when a customer's bank disputes a payment, on any gateway that reports disputes, so the provider's deadline to respond is not missed.
* Fix: on a site with a page cache, a booking form served from a copy more than a day old said "Your session expired" and could not book. A signed-out visitor's booking form now keeps working for as long as a cache is likely to keep the page.
* Fix: a booking's summary link and the screen a customer sees after paying are now kept out of page caches, so a customer is never shown a stale payment status, or another customer's booking on a cache set to ignore the address.
* Developers: a gateway's webhook handler may answer several `events` from one notification, `retry` to have the provider send it again, or `refund_failed` to take back a recorded refund. `handle_return()` may answer `processing`.

= 1.1.2 =
* Change: the plugin no longer stores or prints custom CSS. WordPress has its own editor for this: move any rules you kept in the plugin's custom CSS setting to Appearance > Customize > Additional CSS, or to Styles in the Site Editor. Your old value is not deleted, but it is no longer used.

= 1.1.1 =
* Fix: when the database refused to create one of the plugin's tables, activation and updates reported success all the same, and the table was never created later. The plugin now checks each table, tries again by itself after five minutes, and shows administrators a notice naming the table and what the database said, with a "Try again" button.
* Security: the PDF library used for invoices (add-on) is updated to dompdf 3.1.6, which fixes a published security advisory. The Freemius SDK is updated to 2.13.5.
* Change: the "External services" section of this readme now says exactly what each service receives and when, including what Freemius receives when a licence is activated or an update is checked.
* Developers: the filters `plugixa_carparking_quote_request_fields` and `plugixa_carparking_form_settings` are removed, because they passed the raw request to every callback. Modules register with `Quote::add_claimer()` and `FormService::add_setting_claimer()` instead. The `plugixa_carparking_sanitize_settings` filter now receives the cleaned settings only.

= 1.1.0 =
* New: three designs for the booking form, chosen for each form in the form editor: Classic (numbered steps with the booking summary beside them), Split (the steps and the summary in a side column) and Minimal (one column with a progress bar). A page can use another design with the `design` attribute of the shortcode or the block. The account and summary pages follow the design too.
* New: the booking form has been redesigned: icons in the fields, quick stay lengths (1 day, 3 days, 1 week…), the length of the stay under the dates, a minus and plus for the number of spaces, how many spaces are left on each type, a summary bar fixed to the bottom of the screen on phones, and a button that copies the booking reference.
* New: the booking form block can be set to wide or full width.
* Fix: printing a booking summary printed the whole page, with the site's menu and footer. It now prints the invoice alone.
* Add-on: monthly payments. A location can let customers pay a stay of two months or more month by month, by card through Stripe: the first month and any one-off fee when booking, then the same amount on the same day each month. A missed month marks the booking overdue and emails the customer and the site; the plan stops by itself once the booking is paid in full. Customers change their card from the booking's summary page. A location can also offer plans that carry on after the stay until the customer cancels: the stay chosen is the shortest they commit to, the space is held for them as far ahead as anyone can book, and when the plan is cancelled the space is free again after the last month paid. Subscribe the Stripe webhook to invoice.paid, invoice.payment_failed, customer.subscription.updated and customer.subscription.deleted.
* Change: a stay billed by the month is now counted in calendar months. 1 January to 1 January is twelve months, not thirteen.
* Fix: paying the rest of a booking that was part paid (a deposit, for example) charged the whole total again. It now charges what is left.
* Change: the locations and space types a booking form is limited to are now enforced by the server, not only by the page. A request made by hand can no longer book a location, or a kind of space, that the form does not offer.
* Change: a booking form can now be limited to some space types as well as some locations. The form editor says "available at all locations" with a switch, instead of an empty list meaning all.
* Change: one form is the default form, and a page that names no form shows it. Previously such a page showed built-in steps and ignored the "Default Booking Form". The default form cannot be switched off or trashed; make another form the default instead.
* Change: a space type with no price for a location's billing unit is no longer offered there. It used to be booked for nothing. The location's new "Booking setup" panel and the space type's prices list what is missing. A price of 0 is still a free space.
* Change: a booking is always priced in its location's billing unit, whatever the request asks for.
* Change: a form's custom fields are checked by the server: required fields, email addresses and the options of a list.
* Change: a coupon is applied only on a form that offers the coupon field.
* Change: a location, a space type or a space with upcoming bookings can no longer be trashed. Trashing a location or space type removes it from the forms that listed it; a form left with none is switched off.
* Change: several booking forms on one page now each keep their own fields and settings.
* Change: the form "layout" setting (wizard or compact) and the "default billing unit" setting are removed; neither did anything.
* Add-on: numbered spaces can be added in a run (A1 to A20) from the location's Spaces panel. A booking always holds a real, free space: one the customer picked, or one assigned to them. The "hourly" inventory mode is removed; locations that used it count by capacity, as it always did.
* Fix: a booking page whose location setting was not one of its form's locations could not be used. It now starts on the form's first location.

= 1.0.1 =
* Fix: PayPal payments were taken by PayPal but not recorded on the booking, which stayed unpaid, and the customer was shown "payment not completed". They are now recorded. A payment missed by 1.0.0 is recorded, refundable through PayPal, when the customer's PayPal return link for that order is opened again.
* Security: the Stripe webhook no longer accepts unsigned events. Sites using Stripe must set the webhook signing secret.
* Stripe: handles delayed payments, expired checkouts, refunds made in the Stripe dashboard and disputes; admin refunds are sent through Stripe.
* PayPal: new verified webhook (set the Webhook ID in the PayPal settings) for captures, refunds made on PayPal, reversals and disputes; admin refunds are sent through PayPal; deposits paid by PayPal are recorded as deposits.
* Fix: a refund with no amount after a part refund now refunds what is left, instead of being refused.
* Fix: the "payment not completed" page shows the booking reference again.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.1.2 =
The plugin's custom CSS setting is removed. If you used it, move your rules to Additional CSS in WordPress.

= 1.1.1 =
Includes a security update of the invoice PDF library (add-on). If custom code of yours used the plugixa_carparking_quote_request_fields or plugixa_carparking_form_settings filter, see the changelog.

= 1.1.0 =
The booking form has a new look, in three designs to choose from in each form's settings. Forms start on Classic. If your site's CSS restyles the form, check your booking pages after updating. Monthly payments (add-on) need four more Stripe webhook events: see the changelog.

= 1.0.1 =
If you take payments by PayPal, update now: 1.0.0 did not record them. Compare your PayPal account with your bookings for payments taken on 1.0.0. If you take payments by Stripe, set the webhook signing secret; by PayPal, set the Webhook ID. Without them, webhook events are ignored.

= 1.0.0 =
Initial release.
