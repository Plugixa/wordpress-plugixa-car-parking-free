<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Modules\Bookings\Http;

use PlugixaCarParking\Core\Database\Repository;
use PlugixaCarParking\Core\Filters\FilterCompiler;
use PlugixaCarParking\Core\Entities\RawInput;
use PlugixaCarParking\Core\Http\CrudController;
use PlugixaCarParking\Core\Http\RawResponse;
use PlugixaCarParking\Core\Security\Capabilities;
use PlugixaCarParking\Modules\Bookings\Repositories\BookingRepository;
use PlugixaCarParking\Modules\Bookings\Services\BookingAdminService;
use PlugixaCarParking\Modules\Bookings\Services\CustomAnswers;
use PlugixaCarParking\Modules\Bookings\Services\Quote;
use PlugixaCarParking\Modules\Frontend\Labels;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bookings over REST: the seven record routes, the list as a CSV file, and the price of a booking
 * that has not been made yet.
 */
final class BookingsController extends CrudController {

	/**
	 * The most rows one export holds.
	 */
	private const EXPORT_LIMIT = 10000;

	public function __construct(
		private readonly BookingAdminService $service,
		private readonly BookingRepository $bookings,
		private readonly FilterCompiler $filters,
		private readonly Quote $quote,
		private readonly CustomAnswers $answers
	) {}

	protected function entity_type(): string {
		return 'booking';
	}

	protected function api_base(): string {
		return 'bookings';
	}

	protected function repository(): Repository {
		return $this->bookings;
	}

	protected function service(): object {
		return $this->service;
	}

	/**
	 * @return string[]
	 */
	protected function search_columns(): array {
		return array( 't.booking_ref', 't.customer_name', 't.customer_email', 't.license_plate' );
	}

	/**
	 * The names of the car park and the space type, which a list shows in place of their ids.
	 *
	 * @return callable fn(array $rows): array
	 */
	protected function decorate_page(): ?callable {
		return fn( array $rows ): array => $this->with_names( $rows );
	}

	/**
	 * @param int $id Booking id.
	 *
	 * @return bool|WP_Error
	 */
	protected function do_trash( int $id ): bool|WP_Error {
		return $this->service->delete( $id );
	}

	/**
	 * Through the service, which refuses while the booking has money recorded against it.
	 *
	 * @param int $id Booking id.
	 *
	 * @return bool|WP_Error
	 */
	protected function do_purge( int $id ): bool|WP_Error {
		return $this->service->purge( $id );
	}

	protected function register_extra_routes(): void {
		register_rest_route(
			$this->namespace,
			'/bookings/export',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'export' ),
					'permission_callback' => array( $this, 'can_export' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/bookings/quote',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'quote' ),
					'permission_callback' => array( $this, 'can_read' ),
				),
			)
		);
	}

	/**
	 * What a booking would cost, for the form that is about to create it.
	 *
	 * Priced by the same service the create path uses, so the figure shown is the figure charged.
	 * It writes nothing and checks nothing but the request's own shape: whether there is a free
	 * space is answered when the booking is saved, under the lock.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function quote( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$input = (array) $request->get_json_params();

		$refused = RawInput::first(
			RawInput::number( $input, 'location_id', __( 'Location', 'plugixa-car-parking' ) ),
			RawInput::number( $input, 'space_type_id', __( 'Space type', 'plugixa-car-parking' ) ),
			RawInput::number( $input, 'quantity', __( 'Quantity', 'plugixa-car-parking' ) ),
			RawInput::date( $input, 'entry_datetime', __( 'Entry', 'plugixa-car-parking' ) ),
			RawInput::date( $input, 'exit_datetime', __( 'Exit', 'plugixa-car-parking' ) )
		);

		if ( $refused instanceof WP_Error ) {
			return $refused;
		}

		$location_id   = is_numeric( $input['location_id'] ?? null ) ? (int) $input['location_id'] : 0;
		$space_type_id = is_numeric( $input['space_type_id'] ?? null ) ? (int) $input['space_type_id'] : 0;
		$entry         = RawInput::to_mysql( is_scalar( $input['entry_datetime'] ?? null ) ? (string) $input['entry_datetime'] : '' );
		$exit          = RawInput::to_mysql( is_scalar( $input['exit_datetime'] ?? null ) ? (string) $input['exit_datetime'] : '' );

		if ( $location_id < 1 || $space_type_id < 1 || null === $entry || null === $exit ) {
			return new WP_Error(
				'missing_fields',
				__( 'Location, space type, entry and exit are required.', 'plugixa-car-parking' ),
				array( 'status' => 422 )
			);
		}

		if ( $entry >= $exit ) {
			return new WP_Error(
				'invalid_range',
				__( 'The exit time must be after the entry time.', 'plugixa-car-parking' ),
				array(
					'status' => 422,
					'field'  => 'exit_datetime',
				)
			);
		}

		$priced = array(
			'location_id'   => $location_id,
			'space_type_id' => $space_type_id,
			'entry'         => $entry,
			'exit'          => $exit,
			'quantity'      => is_numeric( $input['quantity'] ?? null ) ? max( 1, (int) $input['quantity'] ) : 1,
			'coupon'        => is_scalar( $input['coupon'] ?? null ) ? sanitize_text_field( (string) $input['coupon'] ) : '',
		);

		if ( isset( $input['billing_type'] ) && is_string( $input['billing_type'] ) && '' !== trim( $input['billing_type'] ) ) {
			$priced['billing_type'] = sanitize_key( $input['billing_type'] );
		}

		// Carried exactly as creating a booking carries it, so the price shown before saving is
		// the price saved. Whatever prices these fields also cleans and validates them.
		$priced += Quote::carried( $input );

		return $this->ok( $this->quote->build( $priced ) );
	}

	/**
	 * @param WP_REST_Request $request The request.
	 */
	public function can_export( WP_REST_Request $request ): bool|WP_Error {
		unset( $request );

		return Capabilities::user_can( 'export', $this->entity_type() )
			? true
			: $this->forbidden();
	}

	/**
	 * With `force`, which the service needs and the shared update route does not pass on.
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function update( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id   = (int) $request['id'];
		$done = $this->service->update( $id, (array) $request->get_json_params(), $this->forced( $request ) );

		if ( $done instanceof WP_Error ) {
			return $done;
		}

		return $this->ok( $this->decorated( $this->service->get( $id ) ) );
	}

	/**
	 * With `force`, for the same reason as update().
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function restore( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$done = $this->service->restore( (int) $request['id'], $this->forced( $request ) );

		if ( $done instanceof WP_Error ) {
			return $done;
		}

		return $done ? $this->ok( array( 'restored' => true ) ) : $this->service->not_found();
	}

	/**
	 * The list as a CSV file: the same search, view and filters, and every matching row up to the
	 * limit instead of one page.
	 *
	 * THE FILTERS ARE APPLIED HERE. The filter layer narrows a request by its path, and it reads
	 * `/bookings` as the list and `/bookings/export` as nothing it knows. Left to it, the file would
	 * hold rows the filtered screen never showed.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function export( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$filters = $this->filters->parse( $request->get_query_params(), $this->entity_type() );

		if ( $filters instanceof WP_Error ) {
			return $filters;
		}

		$query = $this->bookings->query( 'export' );

		if ( 'trash' === $request->get_param( 'view' ) ) {
			$query->only_trashed();
		}

		if ( array() !== $filters ) {
			$compiled = $this->filters->compile( $filters, $this->entity_type() );

			$query->where( implode( ' AND ', $compiled['where'] ), $compiled['params'] );
		}

		$args = $this->list_args( $request );

		$query->search( $args['search'], $this->search_columns() );

		$rows = array_map(
			array( $this->bookings, 'cast' ),
			$this->bookings->drain( $query, self::EXPORT_LIMIT, $args['order'] )
		);

		/** This filter is documented in includes/Core/Http/RestController.php */
		$rows = (array) apply_filters( 'plugixa_carparking_filter_output', $this->with_names( $rows ), $this->entity_type() );
		$rows = array_map( array( $this, 'readable' ), $rows );

		return RawResponse::csv( 'bookings-' . current_time( 'Y-m-d' ) . '.csv', $this->csv( $rows ) );
	}

	/**
	 * @param array<int, array<string, mixed>> $rows Booking rows.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function with_names( array $rows ): array {
		return $this->resolve_names(
			$rows,
			array(
				'location_name'   => array( 'location_id', 'location' ),
				'space_type_name' => array( 'space_type_id', 'space_type' ),
			)
		);
	}

	/**
	 * A booking row as a person reads it in a spreadsheet.
	 *
	 * THE FILE IS FOR PEOPLE, SO IT HOLDS WHAT THE SCREEN SHOWS. A status is written as the site
	 * calls it, not as the slug it is stored under, and the answers to the form's own fields are
	 * one "Additional details" cell: "Label: answer; Label: answer". One cell and not a column for
	 * each field, because the fields differ from form to form and from month to month, and a file
	 * whose columns move cannot be laid beside last month's.
	 *
	 * @param array<string, mixed> $row A booking row.
	 *
	 * @return array<string, mixed>
	 */
	private function readable( array $row ): array {
		$row['additional_details'] = $this->answers->text( $row, '; ' );
		$row['status']             = Labels::of( Labels::booking(), (string) ( $row['status'] ?? '' ) );
		$row['payment_status']     = Labels::of( Labels::payment(), (string) ( $row['payment_status'] ?? '' ) );

		return $row;
	}

	/**
	 * @param WP_REST_Request $request The request.
	 */
	private function forced( WP_REST_Request $request ): bool {
		return true === rest_sanitize_boolean( $request->get_param( 'force' ) ?? false );
	}

	/**
	 * Booking rows as a CSV document with a header row.
	 *
	 * @param array<int, array<string, mixed>> $rows Booking rows, with their names resolved.
	 */
	private function csv( array $rows ): string {
		$columns = array(
			'booking_ref'     => __( 'Reference', 'plugixa-car-parking' ),
			'status'          => __( 'Status', 'plugixa-car-parking' ),
			'payment_status'  => __( 'Payment status', 'plugixa-car-parking' ),
			'location_name'   => __( 'Location', 'plugixa-car-parking' ),
			'space_type_name' => __( 'Space type', 'plugixa-car-parking' ),
			'entry_datetime'  => __( 'Entry', 'plugixa-car-parking' ),
			'exit_datetime'   => __( 'Exit', 'plugixa-car-parking' ),
			'quantity'        => __( 'Quantity', 'plugixa-car-parking' ),
			'customer_name'   => __( 'Customer', 'plugixa-car-parking' ),
			'customer_email'  => __( 'Email', 'plugixa-car-parking' ),
			'customer_phone'  => __( 'Phone', 'plugixa-car-parking' ),
			'license_plate'   => __( 'Licence plate', 'plugixa-car-parking' ),
			'currency'        => __( 'Currency', 'plugixa-car-parking' ),
			'subtotal'        => __( 'Subtotal', 'plugixa-car-parking' ),
			'discount_total'  => __( 'Discount', 'plugixa-car-parking' ),
			'total'           => __( 'Total', 'plugixa-car-parking' ),
			'amount_paid'     => __( 'Paid', 'plugixa-car-parking' ),
			'notes'              => __( 'Notes', 'plugixa-car-parking' ),
			'additional_details' => __( 'Additional details', 'plugixa-car-parking' ),
			'created_at'         => __( 'Created (UTC)', 'plugixa-car-parking' ),
		);

		/**
		 * The columns of the bookings export, in order.
		 *
		 * A module that fills a column of the bookings table adds it here with its heading. A
		 * key that is not a field of a booking row exports as an empty cell.
		 *
		 * @param array<string, string> $columns Booking field => column heading.
		 */
		$columns = array_map( 'strval', (array) apply_filters( 'plugixa_carparking_booking_export_columns', $columns ) );

		$lines = array( $this->csv_line( array_values( $columns ) ) );

		foreach ( $rows as $row ) {
			$cells = array();

			foreach ( array_keys( $columns ) as $key ) {
				$cells[] = $row[ $key ] ?? '';
			}

			$lines[] = $this->csv_line( $cells );
		}

		return implode( "\r\n", $lines ) . "\r\n";
	}

	/**
	 * One CSV line.
	 *
	 * A CELL THAT STARTS LIKE A FORMULA IS WRITTEN AS TEXT. A spreadsheet runs a cell beginning
	 * with `=`, `+`, `-` or `@`, and a customer's name and notes are whatever a visitor typed into
	 * the booking form. A leading apostrophe makes the spreadsheet show the cell and not run it.
	 * A number is left alone, so a negative amount stays a number.
	 *
	 * @param array<int, mixed> $cells The line's values.
	 */
	private function csv_line( array $cells ): string {
		$out = array();

		foreach ( $cells as $cell ) {
			$cell = is_scalar( $cell ) ? (string) $cell : '';

			if ( '' !== $cell && ! is_numeric( $cell ) && 1 === preg_match( '/^[=+\-@\t\r]/', $cell ) ) {
				$cell = "'" . $cell;
			}

			$out[] = '"' . str_replace( '"', '""', $cell ) . '"';
		}

		return implode( ',', $out );
	}
}
