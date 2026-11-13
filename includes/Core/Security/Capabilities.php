<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Core\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Authorisation for every read and write of booking data.
 *
 * SIX REAL WORDPRESS CAPABILITIES, PLUS ONE THAT IS PLUMBING.
 *
 * Core answers WHICH ACTIONS you may perform, through capabilities WordPress itself understands —
 * so `get_role()`, `add_cap()`, Members and User Role Editor all see the same thing. An add-on can
 * then answer the two questions a capability cannot: on WHICH ENTITIES, and on WHOSE RECORDS. That
 * is why `user_can()` takes the record itself even though nothing here looks at it.
 *
 * NOTHING HERE GRANTS THESE TO ANYBODY BUT AN ADMINISTRATOR. `Activator::migrate()` grants all six
 * to `administrator` and to nobody else. The capabilities are ordinary WordPress ones, so any role
 * editor can give an attendant access, but the plugin ships no screen for it. This file is where
 * somebody will come looking when a non-administrator cannot open the app.
 *
 * `MANAGE` IS NOT ONE OF THE SIX AND IS NEVER A CHECKBOX. It means "may open the app" — the admin
 * menu gates on it — and it is granted at runtime by `has_cap()` below to anyone holding any of the
 * six. Storing it as a seventh choice would let somebody build a role with `VIEW` and no `MANAGE`:
 * every REST call would pass and no menu would appear, which reads as broken rather than as denied.
 *
 * THE IMPLICATION RUNS ONE WAY ONLY. Holding `MANAGE` does not confer the six, or the six would
 * mean nothing. Installs that predate this split are carried over once by `backfill()`.
 */
final class Capabilities {

	public const MANAGE = 'plugixa_carparking_manage';

	public const VIEW   = 'plugixa_carparking_view';
	public const CREATE = 'plugixa_carparking_create';
	public const EDIT   = 'plugixa_carparking_edit';
	public const DELETE = 'plugixa_carparking_delete';
	public const EXPORT = 'plugixa_carparking_export';

	public const MANAGE_SETTINGS = 'plugixa_carparking_manage_settings';

	/**
	 * Records the version that carried this install onto the granular capabilities.
	 */
	public const BACKFILL_OPTION = 'plugixa_carparking_caps_backfilled';

	/**
	 * Version 1 capabilities that were renamed, mapped to what replaces them.
	 *
	 * Version 1 also had `_manage`, `_edit` and `_delete`; those names survive unchanged.
	 *
	 * @var array<string, string>
	 */
	private const RENAMED = array(
		'plugixa_carparking_publish'  => self::CREATE,
		'plugixa_carparking_settings' => self::MANAGE_SETTINGS,
	);

	/**
	 * The action vocabulary `user_can()` speaks, mapped to the capability that answers it.
	 *
	 * @var array<string, string>
	 */
	public const ACTION_CAPS = array(
		'read'   => self::VIEW,
		'create' => self::CREATE,
		'edit'   => self::EDIT,
		'delete' => self::DELETE,
		'export' => self::EXPORT,
	);

	/**
	 * The capabilities a role may be given.
	 *
	 * `MANAGE` is deliberately absent: it is derived, never chosen. See the class docblock.
	 *
	 * @var string[]
	 */
	public const GRANTABLE = array(
		self::VIEW,
		self::CREATE,
		self::EDIT,
		self::DELETE,
		self::EXPORT,
		self::MANAGE_SETTINGS,
	);

	/**
	 * Everything this plugin introduces, including the derived one. Administrators get all of it.
	 *
	 * @var string[]
	 */
	public const ALL = array(
		self::MANAGE,
		self::VIEW,
		self::CREATE,
		self::EDIT,
		self::DELETE,
		self::EXPORT,
		self::MANAGE_SETTINGS,
	);

	/**
	 * Grant `MANAGE` to anybody holding any of the six.
	 *
	 * WHY A FILTER RATHER THAN A STORED CAPABILITY. Somebody granting `plugixa_carparking_view` from
	 * Members, from WP-CLI or from another plugin's role editor is supposed to work, because these
	 * are ordinary WordPress capabilities. With a stored `MANAGE` that would produce a user who
	 * passes every REST check and has no way in. Derived state that is computed cannot drift from
	 * the thing it is derived from.
	 */
	public static function register_hooks(): void {
		add_filter( 'user_has_cap', array( self::class, 'has_cap' ), 10, 1 );

		// Not in `Activator::migrate()`: this reads users, and the functions for that are pluggable.
		add_action( 'init', array( self::class, 'backfill' ), 0 );
	}

	/**
	 * Carry version 1 installs onto the six granular capabilities. Runs exactly once.
	 *
	 * WITHOUT THIS, UPGRADING TAKES EVERYONE'S ACCESS AWAY. `read` asks for `VIEW`, which version 1
	 * never granted to anybody — so every role somebody added `MANAGE` to would keep the menu
	 * (`has_cap()` still opens the app) and be refused by every permission callback behind it.
	 * That is the worst shape a permission bug can take: the door opens and nothing inside works.
	 *
	 * BOTH PLACES A CAPABILITY CAN LIVE: roles in the roles option, personal grants in user meta.
	 * Sweeping only the first would leave exactly the users an administrator had singled out.
	 *
	 * ONCE, AND MARKED. Re-running would quietly undo a deliberate narrowing: somebody who edited a
	 * role down to view-only would find it back to full access after the next update.
	 */
	public static function backfill(): void {
		if ( false !== get_option( self::BACKFILL_OPTION ) ) {
			return;
		}

		$legacy = array_merge( array( self::MANAGE ), array_keys( self::RENAMED ) );

		foreach ( wp_roles()->role_objects as $role ) {
			self::carry_over( $role );
		}

		$users = new \WP_User_Query(
			array(
				'capability__in' => $legacy,
				'fields'         => 'ID',
				'number'         => -1,
			)
		);

		foreach ( (array) $users->get_results() as $user_id ) {
			$user = get_userdata( (int) $user_id );

			/*
			 * ONLY PERSONAL GRANTS. Somebody who holds a capability through a role was already
			 * handled by the loop above, and copying the six onto them here would pin the old
			 * permissions to the person rather than the role — narrowing the role afterwards
			 * would then change nothing for anybody who happened to be in it at upgrade time.
			 * `carry_over()` reads `$user->caps`, which holds personal grants only.
			 */
			if ( false !== $user ) {
				self::carry_over( $user );
			}
		}

		update_option( self::BACKFILL_OPTION, PLUGIXA_CARPARKING_VERSION );
	}

	/**
	 * Translate what one role or one user holds directly.
	 *
	 * @param \WP_Role|\WP_User $holder The role, or the user whose personal grants are read.
	 */
	private static function carry_over( \WP_Role|\WP_User $holder ): void {
		$held = $holder instanceof \WP_User ? (array) $holder->caps : (array) $holder->capabilities;

		foreach ( self::RENAMED as $old => $new ) {
			if ( ! empty( $held[ $old ] ) ) {
				$holder->add_cap( $new );
			}

			// Removed whether granted or explicitly denied: the name means nothing any more.
			if ( array_key_exists( $old, $held ) ) {
				$holder->remove_cap( $old );
			}
		}

		// In version 1, holding `MANAGE` meant you could do everything.
		if ( ! empty( $held[ self::MANAGE ] ) ) {
			foreach ( self::GRANTABLE as $cap ) {
				$holder->add_cap( $cap );
			}
		}
	}

	/**
	 * @param array<string, bool> $allcaps Every capability the user holds.
	 *
	 * @return array<string, bool>
	 */
	public static function has_cap( array $allcaps ): array {
		/*
		 * AN ADMINISTRATOR HOLDS EVERYTHING, UNCONDITIONALLY. Activation writes these onto the
		 * role as well, so the stored state and this agree and a third-party role editor sees the
		 * truth. This is the belt: capabilities are ordinary WordPress strings and anything on the
		 * site may remove one, and an administrator who lost `VIEW` to somebody else's bulk edit
		 * would get a 403 from every route with no symptom pointing at what did it.
		 */
		if ( ! empty( $allcaps['manage_options'] ) ) {
			foreach ( self::ALL as $cap ) {
				$allcaps[ $cap ] = true;
			}

			return $allcaps;
		}

		if ( ! empty( $allcaps[ self::MANAGE ] ) ) {
			return $allcaps;
		}

		foreach ( self::GRANTABLE as $cap ) {
			if ( ! empty( $allcaps[ $cap ] ) ) {
				$allcaps[ self::MANAGE ] = true;

				break;
			}
		}

		return $allcaps;
	}

	/**
	 * `WP_User_Query` arguments that find everybody who may open the app — and possibly a few who
	 * may not, which is why every caller confirms each result with `user_can( $id, MANAGE )`.
	 *
	 * NEVER `'capability' => MANAGE`. A user query reads only what is STORED, and `MANAGE` is
	 * stored almost nowhere: it is derived at runtime by `has_cap()` above. So the query asks for
	 * anything `has_cap()` derives access FROM: any of the six, `MANAGE` itself where an older
	 * install stored it, and `manage_options`, which is given everything.
	 *
	 * @return array{capability__in: string[]}
	 */
	public static function holder_query(): array {
		return array( 'capability__in' => array_merge( self::ALL, array( 'manage_options' ) ) );
	}

	/**
	 * May the current user perform an action on an entity?
	 *
	 * @param string                    $action      read|create|edit|delete|export.
	 * @param string                    $entity_type Entity slug.
	 * @param array<string, mixed>|null $record      The record, when acting on one.
	 * @param int|null                  $user_id     Defaults to the current user.
	 */
	public static function user_can( string $action, string $entity_type, ?array $record = null, ?int $user_id = null ): bool {
		$user_id = $user_id ?? get_current_user_id();

		/*
		 * AN UNRECOGNISED ACTION FALLS BACK TO `MANAGE`, WHICH IS THE NARROW ANSWER HERE, not the
		 * lax one: `MANAGE` is held only by people who already hold one of the six. A new verb
		 * added without a capability therefore behaves like the old all-or-nothing model until it
		 * is mapped, rather than being refused outright.
		 */
		$allowed = user_can( $user_id, self::ACTION_CAPS[ $action ] ?? self::MANAGE );

		/**
		 * Filter whether a user may perform an action on an entity.
		 *
		 * @param bool                      $allowed     The capability-only verdict.
		 * @param string                    $action      read|create|edit|delete|export.
		 * @param string                    $entity_type Entity slug.
		 * @param array<string, mixed>|null $record      The record, when acting on one.
		 * @param int                       $user_id     User being checked.
		 */
		return (bool) apply_filters( 'plugixa_carparking_user_can', $allowed, $action, $entity_type, $record, $user_id );
	}

	/**
	 * The capability map exposed to the React app so it can hide UI a user cannot use.
	 *
	 * IT HIDES A CONTROL, IT DOES NOT ENFORCE ANYTHING — every one of these is re-asked on the
	 * server by the route's own `permission_callback`. A client that lied would only be showing
	 * somebody a button that 403s.
	 *
	 * `settings` STAYS `manage_options` AND IS NOT THE SAME QUESTION AS `manage_settings`. The
	 * first is "may you administer this site"; the second is "may you change this plugin's own
	 * settings".
	 *
	 * @return array<string, bool>
	 */
	public static function map(): array {
		$map = array(
			'manage'          => current_user_can( self::MANAGE ),
			'view'            => current_user_can( self::VIEW ),
			'create'          => current_user_can( self::CREATE ),
			'edit'            => current_user_can( self::EDIT ),
			'delete'          => current_user_can( self::DELETE ),
			'export'          => current_user_can( self::EXPORT ),
			'manage_settings' => current_user_can( self::MANAGE_SETTINGS ),
			'settings'        => current_user_can( 'manage_options' ),
		);

		/**
		 * Filter the capability map handed to the admin application.
		 *
		 * @param array<string, bool> $map Capability => granted.
		 */
		return (array) apply_filters( 'plugixa_carparking_capabilities', $map );
	}

	/**
	 * Grant capabilities to roles.
	 *
	 * @param array<string, string[]> $caps Capability => role names.
	 */
	public static function grant( array $caps ): void {
		foreach ( $caps as $cap => $roles ) {
			foreach ( $roles as $role_name ) {
				$role = get_role( $role_name );
				$role?->add_cap( $cap );
			}
		}
	}

	/**
	 * Remove every capability this plugin introduced. Used by the uninstaller.
	 *
	 * BOTH PLACES A CAPABILITY CAN LIVE. Per-user grants live in user meta, not in the roles
	 * option, so walking the roles alone would leave them on every user ever granted one, on a
	 * site with no plugin to define what they mean.
	 *
	 * The user sweep runs first: a role-level revoke can change what `WP_User_Query` matches on
	 * the very next line, and a cleanup whose completeness depends on statement order is one
	 * statement away from being wrong.
	 */
	public static function revoke_all(): void {
		delete_option( self::BACKFILL_OPTION );

		$users = new \WP_User_Query(
			array(
				'capability__in' => array_merge( self::ALL, array_keys( self::RENAMED ) ),
				'fields'         => 'ID',
				'number'         => -1,
			)
		);

		foreach ( (array) $users->get_results() as $user_id ) {
			$user = get_userdata( (int) $user_id );

			if ( false === $user ) {
				continue;
			}

			foreach ( array_keys( (array) $user->caps ) as $cap ) {
				if ( str_starts_with( (string) $cap, 'plugixa_carparking_' ) ) {
					$user->remove_cap( (string) $cap );
				}
			}
		}

		foreach ( wp_roles()->role_objects as $role ) {
			foreach ( array_keys( $role->capabilities ) as $cap ) {
				if ( str_starts_with( (string) $cap, 'plugixa_carparking_' ) ) {
					$role->remove_cap( (string) $cap );
				}
			}
		}
	}
}
