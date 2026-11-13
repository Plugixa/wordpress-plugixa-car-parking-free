<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Modules\Bookings\Services;

use PlugixaCarParking\Core\Settings;
use PlugixaCarParking\Modules\Forms\Repositories\FormRepository;
use PlugixaCarParking\Modules\Forms\Services\FormScope;
use PlugixaCarParking\Modules\Locations\Repositories\LocationRepository;
use PlugixaCarParking\Modules\Spaces\Repositories\SpaceRepository;
use PlugixaCarParking\Modules\SpaceTypes\Repositories\SpaceTypeRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * How the pieces of a booking fit together, read the way a customer meets them.
 *
 * Forms, locations, space types, spaces and prices are each edited on their own screen, and
 * whether a customer can book a type at a location depends on all of them at once. This puts the
 * answer in one place, from the same rule the public routes use ({@see Offer}), so what the admin
 * is told and what a customer is offered cannot disagree.
 */
final class SetupReport {

	public function __construct(
		private readonly Offer $offer,
		private readonly FormRepository $forms,
		private readonly LocationRepository $locations,
		private readonly SpaceRepository $spaces,
		private readonly SpaceTypeRepository $space_types
	) {}

	/**
	 * Everything that decides what a location offers.
	 *
	 * Each space type the location stocks has an `issue` when customers cannot book it:
	 * `type_inactive` (the type is switched off) or `no_price` (no price for the location's
	 * billing unit). Null means it is offered.
	 *
	 * @param array<string, mixed> $location The location row.
	 *
	 * @return array<string, mixed>
	 */
	public function for_location( array $location ): array {
		$location_id = (int) $location['id'];
		$stock       = array();

		foreach ( $this->spaces->for_location( $location_id ) as $row ) {
			$type_id = (int) $row['space_type_id'];

			$stock[ $type_id ] ??= array(
				'quantity' => 0,
				'numbered' => 0,
			);

			$stock[ $type_id ]['quantity'] += 1 === (int) $row['is_assigned'] ? 1 : (int) $row['quantity'];
			$stock[ $type_id ]['numbered'] += 1 === (int) $row['is_assigned'] ? 1 : 0;
		}

		$offered = $this->offer->space_type_ids( $location );
		$types   = array();

		foreach ( $stock as $type_id => $counts ) {
			$type   = $this->space_types->find( $type_id );
			$issue  = null;
			$active = null !== $type && 'active' === ( $type['status'] ?? '' );

			if ( ! $active ) {
				$issue = 'type_inactive';
			} elseif ( ! in_array( $type_id, $offered, true ) ) {
				$issue = 'no_price';
			}

			$types[] = array(
				'space_type_id' => $type_id,
				'name'          => (string) ( $type['name'] ?? '#' . $type_id ),
				'quantity'      => $counts['quantity'],
				'numbered'      => $counts['numbered'],
				'issue'         => $issue,
			);
		}

		$forms = array();

		foreach ( $this->forms->live() as $form ) {
			if ( ! FormScope::allows_location( $form, $location_id ) ) {
				continue;
			}

			$forms[] = array(
				'id'             => (int) $form['id'],
				'name'           => (string) $form['name'],
				'slug'           => (string) $form['slug'],
				'status'         => (string) $form['status'],
				'is_default'     => (bool) $form['is_default'],
				'all_locations'  => array() === FormScope::location_ids( $form ),
				'space_type_ids' => FormScope::space_type_ids( $form ),
			);
		}

		$report = array(
			'location_id'  => $location_id,
			'status'       => (string) ( $location['status'] ?? '' ),
			'billing_type' => (string) ( $location['default_billing_type'] ?? 'day' ),
			'currency'     => self::currency( $location ),
			'space_types'  => $types,
			'forms'        => $forms,
		);

		/**
		 * What else applies at a location, for its Booking setup panel.
		 *
		 * A module that limits something of its own to locations adds one group: array(
		 * 'label' => translated group name, 'items' => list of array( 'id' => int, 'name' =>
		 * string, 'status' => string, 'route' => the admin screen to open it on, e.g. '/x/3' ) ).
		 *
		 * @param array<int, array<string, mixed>> $groups   Groups so far.
		 * @param array<string, mixed>             $location The location row.
		 */
		$groups = apply_filters( 'plugixa_carparking_location_setup_groups', array(), $location );

		$report['also_applies'] = is_array( $groups ) ? array_values( array_filter( $groups, 'is_array' ) ) : array();

		return $report;
	}

	/**
	 * Every location that stocks a space type, and whether the type is priced for how it bills.
	 *
	 * @param int $space_type_id The space type.
	 *
	 * @return array<int, array{location_id: int, name: string, billing_type: string, currency: string, priced: bool}>
	 */
	public function coverage( int $space_type_id ): array {
		$rows = array();

		foreach ( $this->locations->active() as $location ) {
			if ( ! in_array( $space_type_id, $this->spaces->space_type_ids( (int) $location['id'] ), true ) ) {
				continue;
			}

			$rows[] = array(
				'location_id'  => (int) $location['id'],
				'name'         => (string) $location['name'],
				'billing_type' => (string) ( $location['default_billing_type'] ?? 'day' ),
				'currency'     => self::currency( $location ),
				'priced'       => $this->offer->priced( $space_type_id, $location ),
			);
		}

		return $rows;
	}

	/**
	 * The currency a location charges in: its own, else the site's.
	 *
	 * @param array<string, mixed> $location The location row.
	 */
	public static function currency( array $location ): string {
		$own = (string) ( $location['currency'] ?? '' );

		return '' !== $own ? $own : (string) Settings::get( 'currency', 'USD' );
	}

	/**
	 * What a form offers: each location it books at, and the space types it offers there.
	 *
	 * A location the form lists that a customer would not see is still returned, with `issue`
	 * saying why: `location_inactive`, or `nothing_offered` when no type can be booked there.
	 *
	 * @param array<string, mixed> $form The form row.
	 *
	 * @return array{locations: array<int, array<string, mixed>>}
	 */
	public function for_form( array $form ): array {
		$scope     = FormScope::location_ids( $form );
		$locations = array() === $scope ? $this->locations->active() : array_filter( array_map( fn( int $id ): ?array => $this->locations->find( $id ), $scope ) );
		$rows      = array();

		foreach ( $locations as $location ) {
			$active  = 'active' === ( $location['status'] ?? '' );
			$offered = $active ? $this->offer->space_type_ids( $location, $form ) : array();
			$names   = $this->space_types->names( $offered );

			$rows[] = array(
				'id'          => (int) $location['id'],
				'name'        => (string) $location['name'],
				'space_types' => array_map(
					static fn( int $id ): array => array(
						'id'   => $id,
						'name' => (string) ( $names[ $id ] ?? '#' . $id ),
					),
					$offered
				),
				'issue'       => ! $active ? 'location_inactive' : ( array() === $offered ? 'nothing_offered' : null ),
			);
		}

		return array( 'locations' => $rows );
	}
}
