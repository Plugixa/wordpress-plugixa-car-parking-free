<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Modules\Bookings\Services;

use PlugixaCarParking\Modules\Forms\Repositories\FormRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What a customer answered in the fields a booking form adds of its own.
 *
 * A booking keeps each answer under the field's key, in its `meta` column. A key such as `dpvm7p`
 * says nothing to a reader, and the form it came from can be edited or deleted afterwards, so the
 * booking also keeps what each field was called when it was answered, under the reserved key
 * LABELS. Everything that shows a booking asks `rows()`, and nothing else reads the column.
 *
 * A booking made before labels were kept has none. Its labels are looked up in the form it was
 * made on, then in any form that still has a field with that key, and the key itself is made
 * readable when nothing names it.
 */
final class CustomAnswers {

	/**
	 * The key of `meta` that holds field key => the label the field had when it was answered.
	 */
	public const LABELS = '_labels';

	public function __construct( private readonly FormRepository $forms ) {}

	/**
	 * The id of the form a booking says it was made on, or null when there is no such form.
	 *
	 * A public request names its form, and a name is not proof: only the id of a form that exists
	 * is stored on a booking.
	 *
	 * @param mixed $form_id What the request sent.
	 */
	public function form_id( mixed $form_id ): ?int {
		$id = is_numeric( $form_id ) ? (int) $form_id : 0;

		return $id > 0 && null !== $this->forms->find( $id ) ? $id : null;
	}

	/**
	 * The `meta` to store for a set of answers: each under its key, with its label beside it.
	 *
	 * A label already kept is never replaced, so changing an answer later does not rename the
	 * question it answered. A key that is new takes the label its field has on the form today.
	 *
	 * @param mixed                $answers  What the request sent: field key => answer.
	 * @param int|null             $form_id  The form the booking was made on.
	 * @param array<string, mixed> $existing The booking's stored `meta`, when it is being changed.
	 *
	 * @return array<string, mixed>|null Null when there is nothing to keep.
	 */
	public function store( mixed $answers, ?int $form_id, array $existing = array() ): ?array {
		$clean = array();

		foreach ( is_array( $answers ) ? $answers : array() as $key => $value ) {
			$key = sanitize_key( (string) $key );

			if ( '' === $key || self::LABELS === $key || ! is_scalar( $value ) ) {
				continue;
			}

			$clean[ $key ] = is_bool( $value ) ? ( $value ? '1' : '' ) : sanitize_text_field( (string) $value );
		}

		if ( array() === $clean ) {
			return null;
		}

		$kept   = is_array( $existing[ self::LABELS ] ?? null ) ? $existing[ self::LABELS ] : array();
		$labels = array();

		foreach ( array_keys( $clean ) as $key ) {
			$label = is_string( $kept[ $key ] ?? null ) ? $kept[ $key ] : ( $this->form_labels( (int) $form_id )[ $key ] ?? '' );

			if ( '' !== $label ) {
				$labels[ (string) $key ] = sanitize_text_field( $label );
			}
		}

		if ( array() !== $labels ) {
			$clean[ self::LABELS ] = $labels;
		}

		return $clean;
	}

	/**
	 * A booking's answers, each with the label to show beside it. An empty answer is left out.
	 *
	 * @param array<string, mixed> $booking The booking row, with `meta` decoded.
	 *
	 * @return array<int, array{key: string, label: string, value: string}>
	 */
	public function rows( array $booking ): array {
		$meta = is_array( $booking['meta'] ?? null ) ? $booking['meta'] : array();
		$kept = is_array( $meta[ self::LABELS ] ?? null ) ? $meta[ self::LABELS ] : array();
		$rows = array();

		foreach ( $meta as $key => $value ) {
			$key = (string) $key;

			if ( self::LABELS === $key || ! is_scalar( $value ) || '' === trim( (string) $value ) ) {
				continue;
			}

			$rows[] = array(
				'key'   => $key,
				'label' => is_string( $kept[ $key ] ?? null ) && '' !== $kept[ $key ]
					? $kept[ $key ]
					: $this->label( $key, (int) ( $booking['booking_form_id'] ?? 0 ) ),
				'value' => (string) $value,
			);
		}

		return $rows;
	}

	/**
	 * A booking's answers as text, one "Label: answer" each.
	 *
	 * @param array<string, mixed> $booking   The booking row, with `meta` decoded.
	 * @param string               $separator What goes between two answers.
	 */
	public function text( array $booking, string $separator = "\n" ): string {
		return implode(
			$separator,
			array_map(
				static fn( array $row ): string => $row['label'] . ': ' . $row['value'],
				$this->rows( $booking )
			)
		);
	}

	/**
	 * What to call a field no booking kept a label for.
	 *
	 * @param string $key     The field's key.
	 * @param int    $form_id The form the booking was made on, or 0 when it names none.
	 */
	private function label( string $key, int $form_id ): string {
		$label = $this->form_labels( $form_id )[ $key ] ?? $this->form_labels( 0 )[ $key ] ?? '';

		return '' !== $label ? $label : ucfirst( trim( str_replace( array( '_', '-' ), ' ', $key ) ) );
	}

	/**
	 * Field key => label, for one form or, with 0, for every form there is.
	 *
	 * Asked each time and not remembered: a form can be saved between two bookings being shown.
	 *
	 * @param int $form_id A form id, or 0.
	 *
	 * @return array<string, string>
	 */
	private function form_labels( int $form_id ): array {
		return $this->forms->field_labels( $form_id > 0 ? $form_id : null );
	}
}
