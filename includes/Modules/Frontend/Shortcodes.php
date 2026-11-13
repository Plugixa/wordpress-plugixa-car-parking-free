<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Modules\Frontend;

use PlugixaCarParking\Core\Http\PublicController;
use PlugixaCarParking\Core\Plugin;
use PlugixaCarParking\Core\Security\Capabilities;
use PlugixaCarParking\Core\Settings;
use PlugixaCarParking\Modules\Bookings\Repositories\BookingRepository;
use PlugixaCarParking\Modules\Forms\Repositories\FormRepository;
use PlugixaCarParking\Modules\Forms\Services\FormScope;
use PlugixaCarParking\Modules\Forms\Services\FormService;
use PlugixaCarParking\Modules\Invoice\Services\Invoice;
use PlugixaCarParking\Modules\Locations\Repositories\LocationRepository;
use PlugixaCarParking\Modules\Payments\Services\GatewayRegistry;
use PlugixaCarParking\Modules\Payments\Services\PaymentKey;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The three shortcodes a site builds its public pages from.
 *
 * [plugixa_car_parking] is the booking wizard, [plugixa_car_parking_summary] a booking's summary
 * opened from a link, and [plugixa_car_parking_account] a customer's own bookings. The tags and
 * their attributes are in pages customers' sites already have, so none of them changes.
 *
 * Each shortcode returns markup only. Its script and its settings are enqueued, never printed
 * into the markup, so the output holds no <script> and no <style>.
 *
 * Every wrapper carries the class `pcp-ui`, which is what the stylesheet hangs its colours and
 * its components on, beside the class that says which page it is.
 */
final class Shortcodes {

	public const WIZARD  = 'plugixa_car_parking';
	public const SUMMARY = 'plugixa_car_parking_summary';
	public const ACCOUNT = 'plugixa_car_parking_account';

	/**
	 * The two query arguments a customer comes back from a payment provider with.
	 */
	public const RETURN_STATUS  = 'plugixa_carparking_status';
	public const RETURN_BOOKING = 'plugixa_carparking_booking';

	/**
	 * Whether this page has already shown how a payment went. Once is enough, however many
	 * booking forms the page holds.
	 */
	private bool $return_shown = false;

	public function __construct(
		private readonly LocationRepository $locations,
		private readonly FormRepository $forms,
		private readonly BookingRepository $bookings,
		private readonly GatewayRegistry $gateways,
		private readonly Invoice $invoice,
		private readonly Assets $assets
	) {}

	public function register(): void {
		add_shortcode( self::WIZARD, array( $this, 'render' ) );
		add_shortcode( self::SUMMARY, array( $this, 'render_summary' ) );
		add_shortcode( self::ACCOUNT, array( $this, 'render_account' ) );
	}

	/**
	 * The booking wizard.
	 *
	 * A customer who has just come back from paying online is shown how that went instead: the
	 * booking they made is finished, and a fresh form under the message would only invite them
	 * to book it again.
	 *
	 * @param array<string, string>|string $atts Shortcode attributes: form, location, view, mode, design.
	 */
	public function render( $atts ): string {
		$atts = shortcode_atts(
			array(
				'form'     => '',
				'location' => '',
				'view'     => 'wizard',
				'mode'     => '',
				'design'   => '',
			),
			(array) $atts,
			self::WIZARD
		);

		// A saved form decides the wizard's steps, fields and colours, and which locations it
		// offers. A page that names none is shown the site's default form.
		if ( '' !== (string) $atts['form'] ) {
			// Trashed forms are read too, so a page that names one can be told apart from a
			// page that names a form that never existed.
			$form = $this->forms->find_by( 'slug', sanitize_title( (string) $atts['form'] ), true );
		} else {
			$form = $this->forms->default_form();
		}

		if ( null !== $form && ( 'active' !== ( $form['status'] ?? '' ) || ! empty( $form['deleted_at'] ) ) ) {
			return $this->inactive_form_notice();
		}

		// The form's own colour, and the colours that have to be worked out from it.
		$style = Palette::style( is_array( $form['style'] ?? null ) ? ( $form['style']['primary_color'] ?? '' ) : '' );

		// How the form looks: the page's own choice, else the form's.
		$design = self::resolve_design( (string) $atts['design'], $form );

		$returned = $this->payment_return( $style, $design, remove_query_arg( array( self::RETURN_STATUS, self::RETURN_BOOKING ) ) );
		if ( null !== $returned ) {
			return $returned;
		}

		$locations = array_values(
			array_filter(
				$this->locations->active(),
				static fn( array $location ): bool => FormScope::allows_location( $form ?? array(), (int) $location['id'] )
			)
		);

		$template = PLUGIXA_CARPARKING_PATH . 'templates/booking-form.php';

		if ( ! file_exists( $template ) ) {
			return '<!-- plugixa-car-parking: template missing -->';
		}

		// The variables the template reads. It is included here so they are its locals.
		$locations = array_map(
			static fn( array $location ): array => array(
				'id'                   => (int) $location['id'],
				'name'                 => $location['name'],
				'inventory_mode'       => $location['inventory_mode'],
				'default_billing_type' => $location['default_billing_type'],
				'currency'             => $location['currency'],
				'slug'                 => $location['slug'],
			),
			$locations
		);

		// Printed on the form's own markup, so each form on a page keeps its own steps, fields
		// and choices. The script's shared settings hold only what every form on the page shares.
		$config = null !== $form ? $this->form_config( $form ) : null;
		if ( null !== $config ) {
			$config['style']['design'] = $design;
		}

		$this->enqueue_wizard( $config, array_column( $locations, 'id' ) );

		// The screens this form shows, in the order it shows them. The template prints the
		// progress bar from it, and the script reads the order back from that markup.
		$flow = $this->flow( $form );

		$default_location = $this->default_location( $locations, (string) $atts['location'] );

		// A page that starts on a location this form does not book at starts on the form's first
		// one instead. Whoever can fix the page is told; a visitor just sees a working form.
		$location_notice = 0 === $default_location && $locations && Capabilities::user_can( 'edit', 'booking_form' )
			? __( 'The location this page names is not one this form books at, so the form starts on its first location.', 'plugixa-car-parking' )
			: '';

		if ( 0 === $default_location && $locations ) {
			$default_location = (int) $locations[0]['id'];
		}

		ob_start();
		include $template;

		return (string) ob_get_clean();
	}

	/**
	 * A booking's summary, with a way to pay when something is owed and a print button.
	 *
	 * Reachable without signing in, because a customer who booked as a guest has no account.
	 * What authorises the page is the token in the link: without a reference and the token that
	 * matches it, the shortcode prints a notice and nothing else, so nothing can be listed.
	 *
	 * @param array<string, string>|string $atts Shortcode attributes: design.
	 */
	public function render_summary( $atts ): string {
		$atts   = shortcode_atts( array( 'design' => '' ), (array) $atts, self::SUMMARY );
		$design = self::resolve_design( (string) $atts['design'], $this->forms->default_form() );

		// A nonce cannot authorise this: the link is opened from an email, days later, by a
		// signed-out customer, so there is no session for one to belong to. The token checked
		// below is the authorisation, and the page changes nothing.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- authorised by the token; read-only.
		$ref = isset( $_GET['plugixa_carparking_ref'] ) ? sanitize_text_field( wp_unslash( $_GET['plugixa_carparking_ref'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- authorised by the token; read-only.
		$token = isset( $_GET['plugixa_carparking_token'] ) ? sanitize_text_field( wp_unslash( $_GET['plugixa_carparking_token'] ) ) : '';

		// A site with no booking page chosen sends a customer who has just paid here. They carry
		// no summary link, so they are shown how the payment went, with the way back to the
		// booking form when the site has one.
		if ( '' === $ref ) {
			$returned = $this->payment_return( '', $design, ReturnPage::form_url() );
			if ( null !== $returned ) {
				return $returned;
			}
		}

		if ( '' === $ref || ! Invoice::verify( $ref, $token ) ) {
			return $this->summary_notice( __( 'This summary link is invalid or has expired.', 'plugixa-car-parking' ), $design );
		}

		$booking = $this->bookings->find_by_ref( $ref );

		if ( null === $booking ) {
			return $this->summary_notice( __( 'Booking not found.', 'plugixa-car-parking' ), $design );
		}

		$unpaid = (float) ( $booking['total'] ?? 0 ) - (float) ( $booking['amount_paid'] ?? 0 ) > 0.001
			&& 'paid' !== ( $booking['payment_status'] ?? '' );

		$gateways = $unpaid ? $this->gateways->available() : array();

		/**
		 * The ways to pay a booking's summary page offers. Empty offers none.
		 *
		 * @param array $gateways The ways to pay what is owed.
		 * @param array $booking  The booking row.
		 */
		$gateways = (array) apply_filters( 'plugixa_carparking_summary_gateways', $gateways, $booking );

		/**
		 * Further action links shown beside the print button.
		 *
		 * Each entry: array( 'url' => string, 'label' => string ). Empty unless something adds one.
		 *
		 * @param array  $actions The links.
		 * @param string $ref     Booking reference.
		 */
		$actions = (array) apply_filters( 'plugixa_carparking_summary_actions', array(), $ref );

		$this->assets->page(
			'summary',
			'plugixa-car-parking-summary',
			'summary.js',
			'PlugixaCarParkingSummary',
			array(
				'apiUrl'    => $this->api_url(),
				'nonce'     => wp_create_nonce( PublicController::NONCE_ACTION ),
				'restNonce' => wp_create_nonce( 'wp_rest' ),
				'ref'       => $ref,
				// The token above has shown this is the booking's customer, so the page may
				// start a payment for it.
				'key'       => PaymentKey::for_ref( $ref ),
				'offline'   => (string) Settings::get( 'offline_instructions', '' ),
				'i18n'      => array(
					'starting' => __( 'Starting payment…', 'plugixa-car-parking' ),
					'follow'   => __( 'Please follow the payment instructions.', 'plugixa-car-parking' ),
					'failed'   => __( 'Could not start payment.', 'plugixa-car-parking' ),
				),
			),
			array(
				'booking'  => $booking,
				'gateways' => $gateways,
			)
		);

		$summary_html = $this->invoice->html( $booking );

		ob_start();
		include PLUGIXA_CARPARKING_PATH . 'templates/booking-summary.php';

		return (string) ob_get_clean();
	}

	/**
	 * A customer's own bookings. The page is an empty shell its script fills.
	 *
	 * @param array<string, string>|string $atts Shortcode attributes: design.
	 */
	public function render_account( $atts ): string {
		$atts   = shortcode_atts( array( 'design' => '' ), (array) $atts, self::ACCOUNT );
		$design = self::resolve_design( (string) $atts['design'], $this->forms->default_form() );

		$this->assets->page(
			'account',
			'plugixa-car-parking-account',
			'account.js',
			'PlugixaCarParkingAccount',
			array(
				'apiUrl'     => $this->api_url(),
				'nonce'      => wp_create_nonce( PublicController::NONCE_ACTION ),
				'restNonce'  => wp_create_nonce( 'wp_rest' ),
				'isLoggedIn' => is_user_logged_in(),
				'statuses'   => Labels::booking(),
				'i18n'       => array(
					'myBookings'       => __( 'My bookings', 'plugixa-car-parking' ),
					'lookupTitle'      => __( 'Find your booking', 'plugixa-car-parking' ),
					'lookupIntro'      => __( 'Enter the reference from your confirmation and the email address you booked with.', 'plugixa-car-parking' ),
					'refLabel'         => __( 'Booking reference', 'plugixa-car-parking' ),
					'emailLabel'       => __( 'Email address', 'plugixa-car-parking' ),
					'find'             => __( 'Find booking', 'plugixa-car-parking' ),
					'searching'        => __( 'Searching…', 'plugixa-car-parking' ),
					'lookupAnother'    => __( 'Look up another booking', 'plugixa-car-parking' ),
					'none'             => __( 'No bookings found.', 'plugixa-car-parking' ),
					'ref'              => __( 'Reference', 'plugixa-car-parking' ),
					'dates'            => __( 'Details', 'plugixa-car-parking' ),
					'status'           => __( 'Status', 'plugixa-car-parking' ),
					'total'            => __( 'Total', 'plugixa-car-parking' ),
					'actions'          => __( 'Actions', 'plugixa-car-parking' ),
					'view'             => __( 'View', 'plugixa-car-parking' ),
					'cancel'           => __( 'Cancel', 'plugixa-car-parking' ),
					'confirmCancel'    => __( 'Cancel this booking?', 'plugixa-car-parking' ),
					'confirmCancelYes' => __( 'Yes, cancel it', 'plugixa-car-parking' ),
					'keep'             => __( 'Keep booking', 'plugixa-car-parking' ),
					'cancelled'        => __( 'Your booking has been cancelled.', 'plugixa-car-parking' ),
					'working'          => __( 'Working…', 'plugixa-car-parking' ),
					'loading'          => __( 'Loading your bookings…', 'plugixa-car-parking' ),
					'fieldRequired'    => __( 'This field is required.', 'plugixa-car-parking' ),
					'emailInvalid'     => __( 'Enter a valid email address.', 'plugixa-car-parking' ),
					'error'            => __( 'Something went wrong. Please try again.', 'plugixa-car-parking' ),
				),
			)
		);

		return '<div class="pcp-ui pcp-account" data-pcp-design="' . esc_attr( $design ) . '"><p class="pcp-acc-loading">' . esc_html__( 'Loading…', 'plugixa-car-parking' ) . '</p></div>';
	}

	/**
	 * What a page shows a customer who has come back from paying online.
	 *
	 * The provider's answer has already been recorded by the time the customer is sent here, so
	 * what is shown is read from the booking and not from the address: `paid` in a link somebody
	 * typed is not a payment. A booking the address names that is not paid after all is shown as
	 * still being confirmed, which is true of a payment the provider reports a moment later.
	 *
	 * @param string $style   Custom properties for the form's own colour, as a style attribute's value.
	 * @param string $design  The look it is drawn in: one of FormService::DESIGNS.
	 * @param string $restart Where the screen's button leads: the booking form. Empty for no button.
	 *
	 * @return string|null The screen, or null when the customer is not coming back from anything.
	 */
	private function payment_return( string $style, string $design, string $restart ): ?string {
		// Reading how a payment went changes nothing, and the customer arrives from another
		// site's page: there is no nonce of ours they could be carrying.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only; see above.
		$status = isset( $_GET[ self::RETURN_STATUS ] ) ? sanitize_key( wp_unslash( $_GET[ self::RETURN_STATUS ] ) ) : '';

		// 'cancelled' is what a gateway's own cancel link carries (the customer left its checkout);
		// it is shown as a payment that did not go through.
		if ( $this->return_shown || ! in_array( $status, array( 'paid', 'processing', 'failed', 'cancelled' ), true ) ) {
			return null;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only; see above.
		$ref = isset( $_GET[ self::RETURN_BOOKING ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::RETURN_BOOKING ] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only; see above.
		$key = isset( $_GET[ PaymentKey::ARG ] ) ? sanitize_text_field( wp_unslash( $_GET[ PaymentKey::ARG ] ) ) : '';

		// The booking is read only for the customer who holds its key. Anyone else is shown the
		// screen for an address that names no booking, so a guessed reference tells them nothing.
		$booking = PaymentKey::verify( $ref, $key ) ? $this->bookings->find_by_ref( $ref ) : null;

		// 'processing' is a payment the provider has not decided yet. It may be recorded by now.
		if ( in_array( $status, array( 'paid', 'processing' ), true ) ) {
			$settled = null !== $booking && ! in_array( (string) ( $booking['payment_status'] ?? 'unpaid' ), array( '', 'unpaid' ), true );
			$state   = $settled ? 'paid' : 'pending';

			// The money came, but after the hold ran out and the space had gone to someone else.
			if ( $settled && 'expired' === ( $booking['status'] ?? '' ) ) {
				$state = 'no_space';
			}
		} else {
			$state = 'failed';
		}

		// Only a reference that belongs to a booking is printed back.
		$reference = null !== $booking ? (string) $booking['booking_ref'] : '';
		$standing  = null !== $booking ? $this->standing( $booking ) : '';

		$this->return_shown = true;
		$this->assets->styles();

		ob_start();
		include PLUGIXA_CARPARKING_PATH . 'templates/payment-return.php';

		return (string) ob_get_clean();
	}

	/**
	 * Where a booking stands once a payment for it has failed: what, if anything, is left to do.
	 *
	 * @param array<string, mixed> $booking The booking row.
	 *
	 * @return string 'paid', 'cancelled', 'expired' or 'unpaid'.
	 */
	private function standing( array $booking ): string {
		$status = (string) ( $booking['status'] ?? '' );

		if ( 'paid' === ( $booking['payment_status'] ?? '' ) ) {
			return 'paid';
		}

		if ( 'expired' === $status ) {
			return 'expired';
		}

		// Refunded and no-show are over in the same sense: nothing can be paid on them.
		return in_array( $status, array( 'cancelled', 'refunded', 'no_show' ), true ) ? 'cancelled' : 'unpaid';
	}

	/**
	 * The screens a form shows, each with the label its place in the progress bar carries and
	 * the line under it that the split design shows.
	 *
	 * @param array<string, mixed>|null $form The saved form, or null for the standard wizard.
	 *
	 * @return array<int, array{key: string, label: string, desc: string}>
	 */
	private function flow( ?array $form ): array {
		$labels = array(
			'dates'   => __( 'Dates', 'plugixa-car-parking' ),
			'space'   => __( 'Space', 'plugixa-car-parking' ),
			'details' => __( 'Details', 'plugixa-car-parking' ),
			'payment' => __( 'Payment', 'plugixa-car-parking' ),
			'confirm' => __( 'Confirm', 'plugixa-car-parking' ),
		);
		$descs  = array(
			'dates'   => __( 'Where and when', 'plugixa-car-parking' ),
			'space'   => __( 'Pick your space', 'plugixa-car-parking' ),
			'details' => __( 'Contact and vehicle', 'plugixa-car-parking' ),
			'payment' => __( 'How you pay', 'plugixa-car-parking' ),
			'confirm' => __( 'Check and book', 'plugixa-car-parking' ),
		);

		$steps = is_array( $form['steps'] ?? null ) ? $form['steps'] : array();

		return array_map(
			static fn( string $key ): array => array(
				'key'   => $key,
				'label' => $labels[ $key ],
				'desc'  => $descs[ $key ] ?? '',
			),
			FormService::flow( $steps, count( $this->gateways->available() ) )
		);
	}

	/**
	 * What a page shows in place of a form that is switched off or in the trash.
	 *
	 * A visitor sees nothing: a form taken out of use must not keep taking bookings from a page
	 * nobody remembered. Somebody who can edit forms is told why the page looks empty.
	 */
	private function inactive_form_notice(): string {
		if ( ! Capabilities::user_can( 'edit', 'booking_form' ) ) {
			return '';
		}

		$this->assets->styles();

		return '<div class="pcp-ui pcp-notice pcp-form-inactive"><p class="pcp-alert pcp-alert-info">'
			. esc_html__( 'This booking form is inactive, so visitors do not see it.', 'plugixa-car-parking' )
			. '</p></div>';
	}

	/**
	 * What the summary page shows in place of a summary it cannot show.
	 *
	 * @param string $message Why not.
	 * @param string $design  The look it is drawn in: one of FormService::DESIGNS.
	 */
	private function summary_notice( string $message, string $design ): string {
		$this->assets->styles();

		return '<div class="pcp-ui pcp-summary pcp-summary-error" data-pcp-design="' . esc_attr( $design ) . '"><p class="pcp-alert pcp-alert-error" role="alert">' . esc_html( $message ) . '</p></div>';
	}

	/**
	 * The location the wizard starts on: the one the shortcode names, else the first offered.
	 *
	 * @param array<int, array<string, mixed>> $locations The locations offered.
	 * @param string                           $wanted    A location id or slug, or '' for none.
	 */
	private function default_location( array $locations, string $wanted ): int {
		if ( '' === $wanted ) {
			return $locations ? (int) $locations[0]['id'] : 0;
		}

		foreach ( $locations as $location ) {
			if ( (string) $location['id'] === $wanted || $location['slug'] === $wanted ) {
				return (int) $location['id'];
			}
		}

		return 0;
	}

	/**
	 * The look a public page is drawn in.
	 *
	 * Two places can name one: the page, through the shortcode's or the block's `design`
	 * attribute, and the saved form, in its `style`. Whatever comes back is printed as the
	 * `data-pcp-design` attribute the stylesheet draws from, so it is always one of
	 * FormService::DESIGNS and never anything a page typed.
	 *
	 * @param string                    $wanted What the page asks for; '' when it names none.
	 * @param array<string, mixed>|null $form   The saved form, or null for the standard wizard.
	 *
	 * @return string One of FormService::DESIGNS.
	 */
	public static function resolve_design( string $wanted, ?array $form ): string {
		// TODO(you): which one wins, and what an unknown value falls back to. See the note in
		// the conversation. This placeholder lets the page win, then the form, then 'classic'.
		$wanted = sanitize_key( $wanted );
		$saved  = is_array( $form['style'] ?? null ) ? (string) ( $form['style']['design'] ?? '' ) : '';

		foreach ( array( $wanted, $saved ) as $candidate ) {
			if ( in_array( $candidate, FormService::DESIGNS, true ) ) {
				return $candidate;
			}
		}

		return FormService::DESIGNS[0];
	}

	/**
	 * What the wizard's script needs to know about a saved form.
	 *
	 * @param array<string, mixed> $form The form row.
	 *
	 * @return array<string, mixed>
	 */
	private function form_config( array $form ): array {
		$settings = is_array( $form['settings'] ?? null ) ? $form['settings'] : array();

		/**
		 * What the public form is told about the saved form, printed on its markup and handed to
		 * `plugixa_carparking_public_assets` as `form`. A module adds the choices it keeps in the
		 * form's settings (see `plugixa_carparking_form_settings`) for its own script to read.
		 *
		 * @param array<string, mixed> $config   The form's public settings.
		 * @param array<string, mixed> $form     The stored form.
		 * @param array<string, mixed> $settings The form's settings JSON.
		 */
		return (array) apply_filters( 'plugixa_carparking_public_form_config', $this->base_form_config( $form, $settings ), $form, $settings );
	}

	/**
	 * @param array<string, mixed> $form     The stored form.
	 * @param array<string, mixed> $settings The form's settings JSON.
	 *
	 * @return array<string, mixed>
	 */
	private function base_form_config( array $form, array $settings ): array {
		return array(
			// Sent with every request, so the server holds each one to this form's choices.
			'id'      => (int) ( $form['id'] ?? 0 ),
			'slug'    => $form['slug'] ?? '',
			// A form with no steps of its own follows the standard ones.
			'steps'   => ( is_array( $form['steps'] ?? null ) && $form['steps'] ) ? $form['steps'] : FormService::default_steps(),
			'fields'  => $settings['fields'] ?? array(),
			// Only a colour that reads as one: this is printed on the page.
			'style'   => array(
				'primary_color' => (string) ( sanitize_hex_color( (string) ( $form['style']['primary_color'] ?? '' ) ) ?? '' ),
				'design'        => self::resolve_design( '', $form ),
			),
			'coupons' => ! empty( Settings::get( 'enable_coupons' ) ) && FormScope::takes_coupons( $form ),
		);
	}

	/**
	 * Enqueue the wizard's script, its date picker, and its settings.
	 *
	 * @param array<string, mixed>|null $form         The saved form's config, or null for the standard wizard.
	 * @param int[]                     $location_ids The locations the wizard offers.
	 */
	private function enqueue_wizard( ?array $form, array $location_ids ): void {
		$this->assets->page(
			'wizard',
			'plugixa-car-parking-frontend',
			'booking-wizard.js',
			'PlugixaCarParkingFront',
			array(
				'apiUrl'              => $this->api_url(),
				'nonce'               => wp_create_nonce( PublicController::NONCE_ACTION ),
				'restNonce'           => wp_create_nonce( 'wp_rest' ),
				'offlineInstructions' => Settings::get( 'offline_instructions', '' ),
				'couponsEnabled'      => ! empty( Settings::get( 'enable_coupons' ) ),
				'gateways'            => $this->gateways->available(),
				'form'                => $form,
				'i18n'                => array(
					'checking'          => __( 'Checking…', 'plugixa-car-parking' ),
					'noAvailability'    => __( 'No spaces are available for those dates.', 'plugixa-car-parking' ),
					'selectSpace'       => __( 'Please choose a space type.', 'plugixa-car-parking' ),
					/* translators: %1$s: how many spaces of a type are free. */
					'availableCount'    => __( '%1$s available', 'plugixa-car-parking' ),
					'soldOut'           => __( 'Sold out', 'plugixa-car-parking' ),
					'total'             => __( 'Total', 'plugixa-car-parking' ),
					'payWith'           => __( 'Payment method', 'plugixa-car-parking' ),
					'couponApplied'     => __( 'Coupon applied', 'plugixa-car-parking' ),
					'couponInvalid'     => __( 'That code is not valid for this booking.', 'plugixa-car-parking' ),
					'pickSpace'         => __( 'Pick a space', 'plugixa-car-parking' ),
					'anySpace'          => __( 'Any available', 'plugixa-car-parking' ),
					'processing'        => __( 'Processing…', 'plugixa-car-parking' ),
					'genericError'      => __( 'Something went wrong. Please try again.', 'plugixa-car-parking' ),

					// What a field is told when it is refused.
					'fieldRequired'     => __( 'This field is required.', 'plugixa-car-parking' ),
					'emailInvalid'      => __( 'Enter a valid email address.', 'plugixa-car-parking' ),
					'exitAfterEntry'    => __( 'Exit must be after entry.', 'plugixa-car-parking' ),

					// The stepper's labels. The template prints them translated, but the script
					// redraws the stepper for a saved form and needs them again.
					'stepDates'         => __( 'Dates', 'plugixa-car-parking' ),
					'stepSpace'         => __( 'Space', 'plugixa-car-parking' ),
					'stepDetails'       => __( 'Details', 'plugixa-car-parking' ),
					'stepPayment'       => __( 'Payment', 'plugixa-car-parking' ),
					'stepConfirm'       => __( 'Confirm', 'plugixa-car-parking' ),
					// What the button that leaves a step says: the review when that is next.
					'continue'          => __( 'Continue', 'plugixa-car-parking' ),
					'review'            => __( 'Review', 'plugixa-car-parking' ),
					'stepDone'          => __( '(completed)', 'plugixa-car-parking' ),
					/* translators: 1: the current step's number. 2: how many steps there are. */
					'stepOf'            => __( 'Step %1$s of %2$s', 'plugixa-car-parking' ),

					// Row labels for the order summary, the review step and the confirmation.
					'yourBooking'       => __( 'Your booking', 'plugixa-car-parking' ),
					'labelLocation'     => __( 'Location', 'plugixa-car-parking' ),
					'labelSpace'        => __( 'Space', 'plugixa-car-parking' ),
					'labelEntry'        => __( 'Entry', 'plugixa-car-parking' ),
					'labelExit'         => __( 'Exit', 'plugixa-car-parking' ),
					'labelDuration'     => __( 'Duration', 'plugixa-car-parking' ),
					'labelName'         => __( 'Name', 'plugixa-car-parking' ),
					'labelEmail'        => __( 'Email', 'plugixa-car-parking' ),
					'labelPhone'        => __( 'Phone', 'plugixa-car-parking' ),
					'labelPlate'        => __( 'License plate', 'plugixa-car-parking' ),
					'labelPayment'      => __( 'Payment method', 'plugixa-car-parking' ),

					// A stay's length, in each unit a location can bill by. The script has no
					// plural rules of its own, so it is given the two forms English has.
					'unitMinute'        => __( 'minute', 'plugixa-car-parking' ),
					'unitMinutes'       => __( 'minutes', 'plugixa-car-parking' ),
					'unitHour'          => __( 'hour', 'plugixa-car-parking' ),
					'unitHours'         => __( 'hours', 'plugixa-car-parking' ),
					'unitDay'           => __( 'day', 'plugixa-car-parking' ),
					'unitDays'          => __( 'days', 'plugixa-car-parking' ),
					'unitWeek'          => __( 'week', 'plugixa-car-parking' ),
					'unitWeeks'         => __( 'weeks', 'plugixa-car-parking' ),
					'unitMonth'         => __( 'month', 'plugixa-car-parking' ),
					'unitMonths'        => __( 'months', 'plugixa-car-parking' ),
					// The stay on its line of the price: how many spaces of which type.
					/* translators: 1: a space type's name. 2: how many spaces. */
					'spaceCount'        => __( '%1$s × %2$s', 'plugixa-car-parking' ),

					// What a ticked or unticked box of the form's own is stored as.
					'yes'               => __( 'Yes', 'plugixa-car-parking' ),
					'no'                => __( 'No', 'plugixa-car-parking' ),

					// The confirmation.
					'confirmed'         => __( 'Booking confirmed', 'plugixa-car-parking' ),
					'received'          => __( 'Booking received', 'plugixa-car-parking' ),
					'successLead'       => __( 'Keep your reference: you will need it to view or change this booking.', 'plugixa-car-parking' ),
					'reference'         => __( 'Your reference', 'plugixa-car-parking' ),
					'copy'              => __( 'Copy', 'plugixa-car-parking' ),
					'copied'            => __( 'Copied', 'plugixa-car-parking' ),
					'viewBooking'       => __( 'View your booking', 'plugixa-car-parking' ),
					'addToCalendar'     => __( 'Add to Google Calendar', 'plugixa-car-parking' ),
					/* translators: %1$s: the booking reference. */
					'calendarTitle'     => __( 'Parking booking %1$s', 'plugixa-car-parking' ),
					'bookAnother'       => __( 'Make another booking', 'plugixa-car-parking' ),
					'paymentNotStarted' => __( 'Your booking is saved, but the payment could not be started.', 'plugixa-car-parking' ),
				),
			),
			array(
				'form'         => $form,
				'location_ids' => array_map( 'intval', $location_ids ),
				'gateways'     => $this->gateways->available(),
			)
		);
	}

	/**
	 * The address the public scripts send their requests to.
	 */
	private function api_url(): string {
		return esc_url_raw( rest_url( Plugin::REST_NAMESPACE . '/' ) );
	}
}
