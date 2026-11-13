<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Modules\Notifications\Http;

use PlugixaCarParking\Core\Http\RestController;
use PlugixaCarParking\Modules\Notifications\Repositories\LogTable;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The notification log over REST: which messages were sent about which booking, and which failed.
 *
 * Read-only. A row names a booking and the address its message went to, so the log is authorised
 * as bookings are.
 */
final class LogController extends RestController {

	public function __construct( private readonly LogTable $log ) {}

	protected function entity_type(): string {
		return 'booking';
	}

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/notification-log',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'index' ),
					'permission_callback' => array( $this, 'can_read' ),
				),
			)
		);
	}

	/**
	 * A page of the log, newest first. `?booking_id=` narrows it to one booking's messages.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function index( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$args       = $this->list_args( $request );
		$booking_id = (int) $request->get_param( 'booking_id' );

		$page = $this->log->page( $booking_id > 0 ? $booking_id : null, $args['limit'], $args['offset'] );

		$labels = $this->channel_labels();

		$rows = array_map(
			static function ( array $row ) use ( $labels ): array {
				$row['id']          = (int) $row['id'];
				$row['booking_id']  = isset( $row['booking_id'] ) ? (int) $row['booking_id'] : null;
				$row['template_id'] = isset( $row['template_id'] ) ? (int) $row['template_id'] : null;

				$channel              = (string) ( $row['channel'] ?? '' );
				$row['channel_label'] = (string) ( $labels[ $channel ] ?? $channel );

				return $row;
			},
			$page['rows']
		);

		return $this->ok(
			$this->resolve_names( $rows, array( 'booking_ref' => array( 'booking_id', 'booking' ) ) ),
			array(
				'total' => $page['total'],
				'pages' => (int) ceil( $page['total'] / max( 1, $args['limit'] ) ),
			)
		);
	}

	/**
	 * What each channel is called, keyed by the id the log stores.
	 *
	 * The plugin sends by email and names that one. Whatever adds another channel names it on the
	 * filter; a channel nobody names is shown by its id.
	 *
	 * @return array<string, string>
	 */
	private function channel_labels(): array {
		/**
		 * The names of the channels a message can be sent by.
		 *
		 * @param array<string, string> $labels Channel id => translated name.
		 */
		return (array) apply_filters(
			'plugixa_carparking_notification_channel_labels',
			array( 'email' => __( 'Email', 'plugixa-car-parking' ) )
		);
	}
}
