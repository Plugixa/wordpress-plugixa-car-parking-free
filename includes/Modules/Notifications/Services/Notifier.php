<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Modules\Notifications\Services;

use PlugixaCarParking\Core\Money;
use PlugixaCarParking\Core\Settings;
use PlugixaCarParking\Modules\Bookings\Services\CustomAnswers;
use PlugixaCarParking\Modules\Frontend\Labels;
use PlugixaCarParking\Modules\Invoice\Services\Invoice;
use PlugixaCarParking\Modules\Locations\Repositories\LocationRepository;
use PlugixaCarParking\Modules\Notifications\Repositories\LogTable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sends the messages a booking event calls for, and logs each attempt.
 *
 * The plugin itself emails the customer and the site administrator. Any other way of reaching
 * somebody attaches to `plugixa_carparking_booking_notify`, and extra mail attachments to
 * `plugixa_carparking_email_attachments`; this class knows nothing about which exist.
 *
 * Settings are read when a message is sent, not when this object is built: it is built once at
 * boot, and a setting saved later in the same request has to count.
 */
final class Notifier {

	/**
	 * What a customer whose booking is not confirmed yet is told, in place of the shipped wording.
	 *
	 * The shipped template says "is confirmed", which is only true of a booking that is. A site
	 * that has written its own template is sent exactly what it wrote, whatever the status.
	 */
	private const RECEIVED_SUBJECT = 'We have received your parking booking {booking_ref}';
	private const RECEIVED_MESSAGE = "Hello {customer_name},\n\nWe have received your booking {booking_ref} at {location_name}. It is awaiting confirmation.\nEntry: {entry_datetime}\nExit: {exit_datetime}\nTotal: {total}\n\nThank you!";

	/**
	 * The statuses in which a booking is settled, so "confirmed" is the right word for it.
	 *
	 * @var string[]
	 */
	private const CONFIRMED = array( 'confirmed', 'paid', 'completed' );

	public function __construct(
		private readonly LocationRepository $locations,
		private readonly LogTable $log,
		private readonly CustomAnswers $answers
	) {}

	public function register_hooks(): void {
		add_action( 'plugixa_carparking_booking_created', array( $this, 'on_booking_created' ) );
		add_action( 'plugixa_carparking_payment_recorded', array( $this, 'on_payment_recorded' ), 10, 3 );
		add_action( 'plugixa_carparking_payment_notice', array( $this, 'on_payment_notice' ), 10, 4 );
	}

	/**
	 * Tell the site about money that is not where it thinks: held for a space it no longer has, a
	 * refund that never reached the customer, or a payment the customer's bank is disputing. Sent
	 * whatever the notification settings: someone has to act on it.
	 *
	 * @param string               $notice  What was reported.
	 * @param array<string, mixed> $booking The booking.
	 * @param string               $gateway Gateway id.
	 * @param array<string, mixed> $data    What the gateway said about it.
	 */
	public function on_payment_notice( string $notice, array $booking, string $gateway = '', array $data = array() ): void {
		if ( ! in_array( $notice, array( 'late_payment_no_space', 'refund_failed', 'dispute_opened' ), true ) ) {
			return;
		}

		// A gateway can report the same dispute many times. One email is enough.
		$mailed = '';
		if ( 'dispute_opened' === $notice && '' !== (string) ( $data['dispute_id'] ?? '' ) ) {
			$mailed = 'plugixa_carparking_dispute_mailed_' . md5( $gateway . '|' . (string) $data['dispute_id'] );

			if ( false !== get_transient( $mailed ) ) {
				return;
			}
		}

		$admin = Settings::get( 'admin_email', get_option( 'admin_email' ) );

		if ( ! $admin ) {
			return;
		}

		$reference = (string) ( $booking['booking_ref'] ?? '' );

		if ( 'refund_failed' === $notice ) {
			/* translators: %s: the booking reference */
			$subject = sprintf( __( 'A refund failed: %s', 'plugixa-car-parking' ), $reference );
			/* translators: %s: the booking reference */
			$body = sprintf( __( 'The payment provider could not complete a refund for booking %s, so the customer has not received that money. The refund is marked Failed on the booking\'s Payments panel. Check your provider account, then refund again.', 'plugixa-car-parking' ), $reference );
		} elseif ( 'dispute_opened' === $notice ) {
			/* translators: %s: the booking reference */
			$subject = sprintf( __( 'A payment was disputed: %s', 'plugixa-car-parking' ), $reference );
			/* translators: %s: the booking reference */
			$body = sprintf( __( 'A customer\'s bank has opened a dispute or chargeback on a payment for booking %s. Nothing on the booking has changed. Open the dispute in your payment provider\'s dashboard and respond before its deadline, or you may lose it by default.', 'plugixa-car-parking' ), $reference );
		} else {
			/* translators: %s: the booking reference */
			$subject = sprintf( __( 'Late payment needs attention: %s', 'plugixa-car-parking' ), $reference );
			/* translators: %s: the booking reference */
			$body = sprintf( __( 'A payment arrived for booking %s after its hold ran out, and its space has since been booked by someone else. The booking stays expired and the money is on its Payments panel. Refund the customer, or contact them about another space.', 'plugixa-car-parking' ), $reference );
		}

		[ $sent, $error ] = $this->send( (string) $admin, $subject, $body, array(), $notice, $booking );
		$this->log->record( (int) $booking['id'], 'email', (string) $admin, $sent ? 'sent' : 'failed', $notice, $error );

		if ( $sent && '' !== $mailed ) {
			set_transient( $mailed, 1, 6 * MONTH_IN_SECONDS );
		}
	}

	/**
	 * @param array<string, mixed> $booking The new booking.
	 */
	public function on_booking_created( array $booking ): void {
		if ( ! empty( Settings::get( 'notification_enabled' ) ) ) {
			$this->dispatch( $booking, 'booking_created' );
		}
	}

	/**
	 * @param array<string, mixed> $booking The booking, with its paid state just re-derived.
	 * @param float                $paid    What it has now been paid in total.
	 * @param bool                 $new_money False when a failed refund was taken back: no receipt.
	 */
	public function on_payment_recorded( array $booking, float $paid, bool $new_money = true ): void {
		unset( $paid );

		if ( ! $new_money ) {
			return;
		}

		// A booking still expired with money on it has no space: nothing to confirm to the customer.
		if ( 'expired' === ( $booking['status'] ?? '' ) ) {
			return;
		}

		if ( ! empty( Settings::get( 'notification_enabled' ) ) && 'paid' === ( $booking['payment_status'] ?? '' ) ) {
			$this->dispatch( $booking, 'payment_received' );
		}
	}

	/**
	 * Send the notifications for one booking event.
	 *
	 * Public so a scheduled job an add-on registers reuses the same templates, logging and
	 * delivery.
	 *
	 * @param array<string, mixed> $booking The booking row.
	 * @param string               $event   'booking_created', 'payment_received', or an event a
	 *                                      module has given a template.
	 */
	public function dispatch( array $booking, string $event ): void {
		$context    = $this->context( $booking );
		$booking_id = (int) $booking['id'];

		// The customer.
		$composed = $this->compose( $booking, $event );

		if ( ! empty( $booking['customer_email'] ) && null !== $composed ) {
			[ $subject, $body ] = $composed;

			/**
			 * Absolute paths of files to attach to the customer's email.
			 *
			 * Whatever adds a path is responsible for creating the file. A file under the
			 * system's temporary directory is deleted once the mail has been handed over.
			 *
			 * @param string[] $attachments Paths so far.
			 * @param array    $booking     The booking row.
			 * @param string   $event       The event being notified.
			 */
			$attachments = (array) apply_filters( 'plugixa_carparking_email_attachments', array(), $booking, $event );
			$attachments = array_values( array_filter( $attachments, 'file_exists' ) );

			[ $sent, $error ] = $this->send( (string) $booking['customer_email'], $subject, $body, $attachments, $event, $booking );
			$this->log->record( $booking_id, 'email', (string) $booking['customer_email'], $sent ? 'sent' : 'failed', $event, $error );

			foreach ( $attachments as $path ) {
				if ( str_starts_with( $path, get_temp_dir() ) ) {
					wp_delete_file( $path );
				}
			}
		}

		// The site administrator, for new bookings only.
		if ( 'booking_created' === $event ) {
			$admin = Settings::get( 'admin_email', get_option( 'admin_email' ) );

			if ( $admin ) {
				[ $sent, $error ] = $this->send(
					(string) $admin,
					strtr( (string) Settings::get( 'admin_notify_subject', 'New booking {booking_ref}' ), $context ),
					strtr( (string) Settings::get( 'admin_notify_message', '' ), $context ),
					array(),
					$event,
					$booking
				);
				$this->log->record( $booking_id, 'email', (string) $admin, $sent ? 'sent' : 'failed', $event, $error );
			}
		}

		/**
		 * Deliver this booking event some other way.
		 *
		 * @param array    $booking The booking row.
		 * @param array    $context Placeholder => value, for filling a template.
		 * @param string   $event   The event being notified.
		 * @param LogTable $log     The delivery log, so every attempt is recorded in one place.
		 */
		do_action( 'plugixa_carparking_booking_notify', $booking, $context, $event, $this->log );
	}

	/**
	 * The customer's email for an event, filled in for this booking: subject, then body.
	 *
	 * Exactly what dispatch() sends the customer, so a preview of a message is the message. Null
	 * when the event has no email.
	 *
	 * @param array<string, mixed> $booking The booking row.
	 * @param string               $event   The event.
	 *
	 * @return array{0: string, 1: string}|null
	 */
	public function compose( array $booking, string $event ): ?array {
		[ $subject, $message ] = $this->email_template( $event, $booking );

		if ( '' === $subject ) {
			return null;
		}

		$context = $this->context( $booking );
		$body    = strtr( $message, $context );

		// Add the link to the booking summary, unless the site's template already carries it.
		$summary_url = $context['{summary_url}'] ?? '';
		if ( '' !== $summary_url && ! str_contains( $body, $summary_url ) ) {
			$body .= "\n\n" . __( 'View or manage your booking:', 'plugixa-car-parking' ) . "\n" . $summary_url;
		}

		return array( strtr( $subject, $context ), $body );
	}

	/**
	 * Send one message about a booking and log it, the way every message of the plugin's is.
	 *
	 * Public so a module that mails someone the plugin does not, such as a second address for
	 * a copy, still goes through the same headers, filters and log.
	 *
	 * @param array<string, mixed> $booking The booking row.
	 * @param string               $to      Recipient.
	 * @param string               $subject Subject line, already filled in.
	 * @param string               $body    Plain-text body, already filled in.
	 * @param string               $event   The event, as the log records it.
	 */
	public function deliver( array $booking, string $to, string $subject, string $body, string $event ): bool {
		[ $sent, $error ] = $this->send( $to, $subject, $body, array(), $event, $booking );
		$this->log->record( (int) ( $booking['id'] ?? 0 ), 'email', $to, $sent ? 'sent' : 'failed', $event, $error );

		return $sent;
	}

	/**
	 * The placeholders a template may use, with this booking's values.
	 *
	 * @param array<string, mixed> $booking The booking row.
	 *
	 * @return array<string, string>
	 */
	public function placeholders( array $booking ): array {
		return $this->context( $booking );
	}

	/**
	 * The From and Content-Type every message goes out with.
	 *
	 * Public so mail an add-on schedules matches what the plugin sends.
	 *
	 * @return string[]
	 */
	public function email_headers(): array {
		return array(
			'Content-Type: text/plain; charset=UTF-8',
			sprintf(
				'From: %s <%s>',
				Settings::get( 'email_from_name', get_bloginfo( 'name' ) ),
				Settings::get( 'email_from_address', get_option( 'admin_email' ) )
			),
		);
	}

	/**
	 * Send one message and report why it failed, not just that it did.
	 *
	 * wp_mail() returns a bare boolean; the reason lives in the WP_Error that WordPress passes to
	 * `wp_mail_failed`. Capturing it is what lets the log say something useful to a site owner
	 * asking why a customer never got their confirmation.
	 *
	 * The listener is attached around each individual send. A permanently registered one would
	 * also swallow other plugins' mail failures.
	 *
	 * @param string   $to          Recipient.
	 * @param string   $subject     Subject line.
	 * @param string   $body        Plain-text body.
	 * @param string[]             $attachments Absolute paths.
	 * @param string               $event       The event the message is about.
	 * @param array<string, mixed> $booking     The booking it is about.
	 *
	 * @return array{0: bool, 1: string} Whether it was sent, and the failure reason ('' when sent).
	 */
	private function send( string $to, string $subject, string $body, array $attachments = array(), string $event = '', array $booking = array() ): array {
		$error = '';

		$message = array(
			'to'          => $to,
			'subject'     => $subject,
			'body'        => $body,
			'headers'     => $this->email_headers(),
			'attachments' => $attachments,
		);

		/**
		 * A message just before it is handed to wp_mail().
		 *
		 * The plugin sends plain text. A module may change the body and headers here, for
		 * example to send a designed layout, as long as it keeps all five keys.
		 *
		 * @param array{to: string, subject: string, body: string, headers: string[], attachments: string[]} $message The message.
		 * @param string               $event   The event the message is about.
		 * @param array<string, mixed> $booking The booking it is about.
		 */
		$filtered = apply_filters( 'plugixa_carparking_email_message', $message, $event, $booking );

		if ( is_array( $filtered ) && is_string( $filtered['body'] ?? null ) && is_string( $filtered['subject'] ?? null ) && is_array( $filtered['headers'] ?? null ) ) {
			$message = array_merge( $message, array_intersect_key( $filtered, $message ) );
		}

		$capture = static function ( $wp_error ) use ( &$error ): void {
			$error = is_wp_error( $wp_error ) ? $wp_error->get_error_message() : '';
		};

		add_action( 'wp_mail_failed', $capture );
		$sent = wp_mail( (string) $message['to'], (string) $message['subject'], (string) $message['body'], (array) $message['headers'], (array) $message['attachments'] );
		remove_action( 'wp_mail_failed', $capture );

		return array( (bool) $sent, $error );
	}

	/**
	 * The subject and body of the customer's email for an event.
	 *
	 * The plugin words two events itself, from settings. Any other event has no email until a
	 * module gives it a template, so an event nothing recognises sends the customer nothing
	 * instead of the wrong message.
	 *
	 * @param string               $event   The event being notified.
	 * @param array<string, mixed> $booking The booking row.
	 *
	 * @return array{0: string, 1: string}
	 */
	private function email_template( string $event, array $booking ): array {
		switch ( $event ) {
			case 'payment_received':
				$template = array(
					(string) Settings::get( 'payment_subject', 'Payment received for {booking_ref}' ),
					(string) Settings::get( 'payment_message', "Hello {customer_name},\n\nWe've received your payment for booking {booking_ref}. Total: {total}.\n\nThank you!" ),
				);
				break;
			case 'booking_created':
				$template = $this->created_template( $booking );
				break;
			default:
				$template = array( '', '' );
		}

		/**
		 * The subject and body of the customer's email for a booking event.
		 *
		 * A module that announces an event of its own returns its wording here. Both may use the
		 * placeholders the plugin's own templates use. An empty subject sends nothing.
		 *
		 * @param array{0: string, 1: string} $template Subject, then body.
		 * @param string                      $event    The event being notified.
		 * @param array<string, mixed>        $booking  The booking row.
		 */
		$filtered = apply_filters( 'plugixa_carparking_email_template', $template, $event, $booking );

		return is_array( $filtered ) && is_string( $filtered[0] ?? null ) && is_string( $filtered[1] ?? null )
			? array( $filtered[0], $filtered[1] )
			: $template;
	}

	/**
	 * The subject and body sent when a booking is made, worded by the state the booking is in.
	 *
	 * @param array<string, mixed> $booking The booking row.
	 *
	 * @return array{0: string, 1: string}
	 */
	private function created_template( array $booking ): array {
		$defaults = Settings::defaults();
		$subject  = (string) Settings::get( 'customer_confirm_subject', $defaults['customer_confirm_subject'] );
		$message  = (string) Settings::get( 'customer_confirm_message', '' );

		if ( in_array( (string) ( $booking['status'] ?? '' ), self::CONFIRMED, true ) ) {
			return array( $subject, $message );
		}

		// Each half is judged by itself: a site may have reworded the body and left the subject.
		return array(
			$subject === $defaults['customer_confirm_subject'] ? self::RECEIVED_SUBJECT : $subject,
			$message === $defaults['customer_confirm_message'] ? self::RECEIVED_MESSAGE : $message,
		);
	}

	/**
	 * The placeholders a template may use, with this booking's values.
	 *
	 * @param array<string, mixed> $booking The booking row.
	 *
	 * @return array<string, string>
	 */
	private function context( array $booking ): array {
		// A trashed location still has a name, and the message should still say where.
		$location = ! empty( $booking['location_id'] ) ? $this->locations->find( (int) $booking['location_id'], true ) : null;

		$context = array(
			'{booking_ref}'    => (string) ( $booking['booking_ref'] ?? '' ),
			'{customer_name}'  => (string) ( $booking['customer_name'] ?? '' ),
			'{customer_email}' => (string) ( $booking['customer_email'] ?? '' ),
			'{location_name}'  => (string) ( $location['name'] ?? '' ),
			'{entry_datetime}' => $this->when( $booking['entry_datetime'] ?? '' ),
			'{exit_datetime}'  => $this->when( $booking['exit_datetime'] ?? '' ),
			'{quantity}'       => (string) ( $booking['quantity'] ?? 1 ),
			'{total}'          => Money::format( (float) ( $booking['total'] ?? 0 ) ),
			// The status as the site calls it, not the slug it is stored under.
			'{status}'         => Labels::of( Labels::booking(), (string) ( $booking['status'] ?? '' ) ),
			// What the customer answered in the form's own fields, one "Label: answer" a line.
			'{custom_fields}'  => $this->answers->text( $booking ),
			'{summary_url}'    => Invoice::summary_url( (string) ( $booking['booking_ref'] ?? '' ) ),
		);

		/**
		 * The placeholders a message may use, with this booking's values.
		 *
		 * A module adds its own here. The plugin's own keep their values: a key already set is
		 * not replaced.
		 *
		 * @param array<string, string> $extra   Placeholder => value, to add.
		 * @param array<string, mixed>  $booking The booking row.
		 */
		$extra = apply_filters( 'plugixa_carparking_email_placeholders', array(), $booking );

		foreach ( (array) $extra as $token => $value ) {
			if ( is_string( $token ) && is_scalar( $value ) && ! isset( $context[ $token ] ) ) {
				$context[ $token ] = (string) $value;
			}
		}

		return $context;
	}

	/**
	 * A stored datetime in the site's own date and time format, as the invoice shows it.
	 *
	 * The stored value is the car park's wall-clock time, so it is formatted as is, with no
	 * timezone shift. Month names come out translated. A value that will not parse is kept.
	 *
	 * @param mixed $value The stored datetime.
	 */
	private function when( mixed $value ): string {
		$value = is_scalar( $value ) ? (string) $value : '';
		if ( '' === $value ) {
			return '';
		}

		$format    = trim( (string) Settings::get( 'date_format', 'Y-m-d' ) . ' ' . (string) Settings::get( 'time_format', 'H:i' ) );
		$formatted = mysql2date( '' !== $format ? $format : 'Y-m-d H:i', $value );

		return is_string( $formatted ) && '' !== $formatted ? $formatted : $value;
	}
}
