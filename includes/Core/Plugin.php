<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Core;

use PlugixaCarParking\Core\Admin\Admin;
use PlugixaCarParking\Core\Admin\Assets;
use PlugixaCarParking\Core\Admin\MigrationNotice;
use PlugixaCarParking\Core\Compat\LegacyRoutes;
use PlugixaCarParking\Core\Filters\FilterRequest;
use PlugixaCarParking\Core\Http\BootstrapController;
use PlugixaCarParking\Core\Http\FiltersController;
use PlugixaCarParking\Core\Http\MassActionController;
use PlugixaCarParking\Core\Http\PublicNonce;
use PlugixaCarParking\Core\Http\PublicRequestGuard;
use PlugixaCarParking\Core\Http\RawResponse;
use PlugixaCarParking\Core\Http\SettingsController;
use PlugixaCarParking\Core\Http\UsersController;
use PlugixaCarParking\Core\Modules\ModuleRegistry;
use PlugixaCarParking\Core\Security\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The composition root.
 *
 * Holds no business logic: it builds the container, discovers the modules, and lets
 * the premium overlay wire itself in if it is present.
 */
final class Plugin {

	/**
	 * REST namespace every module registers its routes on.
	 */
	public const REST_NAMESPACE = 'plugixa-car-parking/v1';

	private static ?self $instance = null;

	private Container $container;

	private ModuleRegistry $modules;

	private function __construct() {
		$this->container = new Container();
		$this->container->instance( Container::class, $this->container );

		$this->modules = new ModuleRegistry( $this->container );
		$this->container->instance( ModuleRegistry::class, $this->modules );
	}

	public static function instance(): self {
		self::$instance ??= new self();

		return self::$instance;
	}

	public function container(): Container {
		return $this->container;
	}

	/**
	 * Start the plugin. Called on plugins_loaded, ONCE.
	 *
	 * Once is a real precondition, not a figure of speech. Booting twice registers every module's
	 * hooks twice, because `add_action()` deduplicates a callback by identity and two closures are
	 * never the same object — the second registration appends rather than replaces, and every
	 * listener fires twice. There is deliberately no guard here: the one caller is the
	 * `plugins_loaded` hook in plugixa-car-parking.php, and a boot() that quietly absorbed a second
	 * call would hide the mistake rather than prevent it. `PluginBootTest` asserts no hook ends up
	 * with a duplicate listener.
	 */
	public function boot(): void {
		/*
		 * Premium modules are discovered in the same call, from `pro/includes/Modules`. There is
		 * no probe for them and no `class_exists` check: Freemius deletes that directory from the
		 * free build, so on a free site there is nothing to find. Feature availability is decided
		 * by CODE PRESENCE, never by a licence check — see the ModuleRegistry docblock.
		 */
		$this->modules->discover();
		$this->modules->registerHooks();

		// AFTER the modules, deliberately: which settings are secrets is something the modules
		// say, and the option must not be read or written until they have said it.
		Settings::register_hooks();

		// AFTER the modules too. It reads the filter catalogue, which the modules answer, and a
		// request-scoped narrowing that registered before its own vocabulary existed would see an
		// empty catalogue and refuse every filter as unknown.
		$this->container->get( FilterRequest::class )->register_hooks();

		// The app's menu entry and its page. Nothing outside wp-admin has either.
		if ( is_admin() ) {
			( new Admin() )->register_hooks();
			( new Assets() )->register_hooks();
		}

		( new RawResponse() )->register_hooks();

		// Puts the Retry-After header on a rate-limited answer from a public route.
		PublicRequestGuard::register_hooks();

		// A cached booking page's nonce has to outlive the cache. Minted on a page, verified
		// in a REST request, so it is hooked on every request.
		PublicNonce::register_hooks();

		// `plugixa_carparking_manage` is derived from the six granular capabilities rather than
		// stored, so the filter that derives it has to be in place before anything asks.
		Capabilities::register_hooks();

		add_action( 'rest_api_init', array( $this, 'register_routes' ) );

		/*
		 * Schema drift guard: a plugin updated by file copy never fires its activation hook, so
		 * new tables and columns would silently not exist.
		 *
		 * TWO QUESTIONS, NOT ONE. Comparing only the version catches an upgrade and misses the
		 * premium build being installed over the free one at the SAME version — which adds
		 * modules to disk and leaves their tables uncreated. `ModuleRegistry::fingerprint()`
		 * carries the full account.
		 *
		 * `due()` keeps a migration that CANNOT finish from running on every request: after a
		 * failure it waits, and `Admin\MigrationNotice` tells an administrator what was refused.
		 */
		if ( ! Activator::schema_is_current( $this->modules ) && Activator::due() ) {
			Activator::migrate();
		}

		/*
		 * Hooked on every request, not only inside wp-admin: the two hooks fire nowhere else, so it
		 * costs nothing, and a line that runs only in wp-admin is a line no test ever executes.
		 */
		MigrationNotice::register_hooks();

		/**
		 * Fires once the plugin is fully booted.
		 *
		 * @param Container $container The plugin container.
		 */
		do_action( 'plugixa_carparking_loaded', $this->container );
	}

	/**
	 * Register core and module REST routes.
	 */
	public function register_routes(): void {
		$this->container->get( BootstrapController::class )->register_routes();

		// Core owns these endpoints but knows none of their content: the modules contribute the
		// settings and the filter vocabulary they own, through filters.
		$this->container->get( SettingsController::class )->register_routes();
		$this->container->get( UsersController::class )->register_routes();
		$this->container->get( FiltersController::class )->register_routes();

		// Acting on many records of a type known only at runtime.
		$this->container->get( MassActionController::class )->register_routes();

		$this->modules->registerRoutes();

		/**
		 * Register additional REST routes.
		 *
		 * @param string    $namespace The plugin's REST namespace.
		 * @param Container $container The plugin container.
		 */
		do_action( 'plugixa_carparking_register_rest_routes', self::REST_NAMESPACE, $this->container );

		// Last, because an alias copies a route that has to be registered already.
		( new LegacyRoutes() )->register( rest_get_server() );
	}

	public function modules(): ModuleRegistry {
		return $this->modules;
	}
}
