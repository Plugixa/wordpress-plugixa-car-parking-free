<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Modules\Forms\Http;

use PlugixaCarParking\Core\Database\Repository;
use PlugixaCarParking\Core\Http\CrudController;
use PlugixaCarParking\Modules\Forms\Repositories\FormRepository;
use PlugixaCarParking\Modules\Forms\Services\FormService;
use PlugixaCarParking\Modules\Locations\Repositories\LocationRepository;
use PlugixaCarParking\Modules\SpaceTypes\Repositories\SpaceTypeRepository;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Booking forms over REST: the seven record routes.
 */
final class FormsController extends CrudController {

	public function __construct(
		private readonly FormService $service,
		private readonly FormRepository $forms,
		private readonly LocationRepository $locations,
		private readonly SpaceTypeRepository $space_types
	) {}

	protected function entity_type(): string {
		return 'booking_form';
	}

	protected function api_base(): string {
		return 'booking-forms';
	}

	protected function repository(): Repository {
		return $this->forms;
	}

	protected function service(): object {
		return $this->service;
	}

	/**
	 * @return string[]
	 */
	protected function search_columns(): array {
		return array( 't.name', 't.slug' );
	}

	/**
	 * A form's custom fields at the top level, and the default steps where it has none.
	 *
	 * On every route that returns a form, list and detail alike: the builder reads `fields` and
	 * `steps` from whichever one it loaded, and must find the same shape in both.
	 *
	 * @return callable fn(array $row): array
	 */
	protected function decorate(): ?callable {
		return array( $this->service, 'present' );
	}

	/**
	 * The names behind each form's locations and space types, which the list shows instead of
	 * the ids. One lookup per entity for the whole page. Empty where the form takes all of them.
	 *
	 * @return callable fn(array $rows): array
	 */
	protected function decorate_page(): ?callable {
		return function ( array $rows ): array {
			$lists = array(
				'location_ids'   => array( 'location_names', $this->locations ),
				'space_type_ids' => array( 'space_type_names', $this->space_types ),
			);

			foreach ( $lists as $column => [ $key, $repository ] ) {
				$ids     = array();
				$per_row = array();

				foreach ( $rows as $index => $row ) {
					$per_row[ $index ] = is_array( $row[ $column ] ?? null ) ? array_map( 'intval', $row[ $column ] ) : array();
					$ids               = array_merge( $ids, $per_row[ $index ] );
				}

				$names = $repository->names( $ids );

				foreach ( $rows as $index => $row ) {
					$rows[ $index ][ $key ] = array_values(
						array_map(
							static fn( int $id ): string => (string) ( $names[ $id ] ?? '#' . $id ),
							$per_row[ $index ]
						)
					);
				}
			}

			return $rows;
		};
	}

	/**
	 * Through the service, which asks every other module whether the form is still in use.
	 *
	 * @param int $id Form id.
	 *
	 * @return bool|WP_Error
	 */
	protected function do_trash( int $id ): bool|WP_Error {
		return $this->service->delete( $id );
	}

	/**
	 * @param int $id Form id.
	 *
	 * @return bool|WP_Error
	 */
	protected function do_restore( int $id ): bool|WP_Error {
		return $this->service->restore( $id );
	}
}
