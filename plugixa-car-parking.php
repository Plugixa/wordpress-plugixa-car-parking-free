<?php

/**
 * Plugin Name: Plugixa Car Parking
 * Plugin URI: https://www.plugixa.com/plugins/plugixa-car-parking
 * Description: Car-park booking and reservation system with a React admin panel, a capacity-based booking engine, live availability, a booking wizard shortcode and Gutenberg block, coupons, offline payment and email notifications.
 * Version: 1.2.0
 * Author: Plugixa
 * Author URI: https://plugixa.com
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: plugixa-car-parking
 * Domain Path: /languages
 * Requires at least: 6.5
 * Requires PHP: 8.2
 *
 */
declare (strict_types = 1);
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
/*
 * ONE COPY AT A TIME. If another copy of this plugin already loaded, this one steps aside.
 *
 * Buying the premium build installs it as a SECOND plugin beside the free one, and Freemius
 * deactivates the free copy when the premium one is activated — but only when that activation
 * happens inside wp-admin. Activate the premium copy from WP-CLI or a hosting panel and both stay
 * active: every constant below is then defined twice, and on a host that displays warnings those
 * land inside REST responses and the app cannot parse its own API.
 *
 * Whichever copy WordPress loads first owns the request, and the other returns before defining
 * anything, so there is nothing to register twice.
 */
if ( defined( 'PLUGIXA_CARPARKING_PATH' ) ) {
    $plugixa_carparking_ignored_copy = plugin_basename( __FILE__ );
    add_action( 'admin_notices', static function () use($plugixa_carparking_ignored_copy) : void {
        if ( !current_user_can( 'activate_plugins' ) ) {
            return;
        }
        echo '<div class="notice notice-warning"><p>';
        printf( 
            /* translators: %s: the plugin file that was not loaded, e.g. "plugixa-car-parking/plugixa-car-parking.php". */
            esc_html__( 'Two copies of Plugixa Car Parking are active. Only one can run, so %s was not loaded. Deactivate the copy you no longer need — your bookings are shared and stay as they are.', 'plugixa-car-parking' ),
            '<code>' . esc_html( $plugixa_carparking_ignored_copy ) . '</code>'
         );
        echo '</p></div>';
    } );
    return;
}
// Plugin constants
define( 'PLUGIXA_CARPARKING_VERSION', '1.2.0' );
define( 'PLUGIXA_CARPARKING_PATH', plugin_dir_path( __FILE__ ) );
define( 'PLUGIXA_CARPARKING_URL', plugin_dir_url( __FILE__ ) );
define( 'PLUGIXA_CARPARKING_BASENAME', plugin_basename( __FILE__ ) );
// Core Composer autoloader: the PSR-4 root `PlugixaCarParking\` => includes/.
$plugixa_carparking_autoload = PLUGIXA_CARPARKING_PATH . 'vendor/autoload.php';
if ( !file_exists( $plugixa_carparking_autoload ) ) {
    add_action( 'admin_notices', static function () : void {
        if ( !current_user_can( 'activate_plugins' ) ) {
            return;
        }
        echo '<div class="notice notice-error"><p>';
        esc_html_e( 'Plugixa Car Parking: dependencies are missing. Run "composer install" in the plugin directory.', 'plugixa-car-parking' );
        echo '</p></div>';
    } );
    return;
}
require_once $plugixa_carparking_autoload;
/*
 * THE PREMIUM CLASS LOADER, AND WHY IT IS NOT A LINE IN composer.json.
 *
 * `"PlugixaCarParking\\Pro\\": "pro/includes/"` in the PSR-4 block looks equivalent and is not,
 * because this project builds with `optimize-autoloader`. That turns every PSR-4 root into a
 * CLASSMAP at `composer dump-autoload` time, and the premium classes are on disk when the release
 * is built — so the free download's classmap would list premium class names, and Composer includes
 * a classmap hit without checking the file exists, so every lookup would warn once pro/ is stripped.
 *
 * A prefix loader guarded by is_readable() has neither problem: a missing tree is simply a class
 * that does not exist, which is the whole overlay model.
 *
 * The namespace is named here rather than under includes/ deliberately: ArchitectureTest
 * forbids a free file there from naming premium code.
 */
spl_autoload_register( static function ( string $plugixa_carparking_class ) : void {
    $prefix = 'PlugixaCarParking\\Pro\\';
    if ( 0 !== strncmp( $prefix, $plugixa_carparking_class, strlen( $prefix ) ) ) {
        return;
    }
    $file = PLUGIXA_CARPARKING_PATH . 'pro/includes/' . str_replace( '\\', '/', substr( $plugixa_carparking_class, strlen( $prefix ) ) ) . '.php';
    if ( is_readable( $file ) ) {
        require_once $file;
    }
} );
/**
 * --- Freemius SDK bootstrap ----------------------------------------------
 *
 * Freemius is wired with the live product credentials (dashboard ID 32520,
 * slug "plugixa-car-parking"). The SDK is vendored, so it is loaded via an
 * explicit require of start.php — it is NOT on the Composer autoloader.
 *
 * The file_exists() guard keeps plugixa_carparking_fs() null-safe: if the vendored
 * SDK is ever missing, plugixa_carparking_fs() returns null and the plugin keeps
 * running rather than fataling. Nothing in the plugin's own code reads the SDK to
 * decide what to run — which features exist is determined solely by which files
 * are installed (see PlugixaCarParking\Core\Modules\ModuleRegistry).
 */
if ( !function_exists( 'plugixa_carparking_fs' ) ) {
    // Create a helper function for easy SDK access.
    function plugixa_carparking_fs() {
        global $plugixa_carparking_fs;
        if ( !isset( $plugixa_carparking_fs ) ) {
            $plugixa_carparking_fs = null;
            // Include Freemius SDK (vendored — loaded via explicit require, not Composer autoload).
            $fs_sdk = PLUGIXA_CARPARKING_PATH . 'vendor/freemius/wordpress-sdk/start.php';
            if ( file_exists( $fs_sdk ) ) {
                require_once $fs_sdk;
                $plugixa_carparking_fs = fs_dynamic_init( array(
                    'id'               => '32520',
                    'slug'             => 'plugixa-car-parking',
                    'type'             => 'plugin',
                    'public_key'       => 'pk_075ec8bebd3b8930798b257601322',
                    'is_premium'       => false,
                    'premium_suffix'   => 'Pro',
                    'has_addons'       => false,
                    'has_paid_plans'   => true,
                    'is_org_compliant' => true,
                    'menu'             => array(
                        'slug'    => 'plugixa-car-parking',
                        'support' => false,
                    ),
                    'is_live'          => true,
                ) );
            }
        }
        return $plugixa_carparking_fs;
    }

    // Init Freemius.
    plugixa_carparking_fs();
    // Signal that SDK was initiated.
    do_action( 'plugixa_carparking_fs_loaded' );
}
/**
 * Plugin activation hook.
 *
 * Boot the plugin first. On activation the `plugins_loaded` action has already
 * fired (the plugin was not active when it ran), so without this no module would
 * have registered its hooks: Activator::activate() would seed defaults for the
 * core only, and nothing would be listening when it announces the activation,
 * so no starter content would be created.
 */
register_activation_hook( __FILE__, function () {
    if ( !did_action( 'plugixa_carparking_loaded' ) ) {
        \PlugixaCarParking\Core\Plugin::instance()->boot();
    }
    \PlugixaCarParking\Core\Activator::activate();
} );
/**
 * Plugin deactivation hook
 */
register_deactivation_hook( __FILE__, [\PlugixaCarParking\Core\Deactivator::class, 'deactivate'] );
/**
 * Plugin uninstall cleanup.
 *
 * Freemius ships its own uninstall.php and forbids the plugin from bundling
 * one, so cleanup runs via the Freemius `after_uninstall` action when Freemius
 * is active. When the SDK is not on disk, the standard uninstall hook is
 * registered instead so cleanup still works during local development.
 */
$plugixa_carparking_fs_instance = ( function_exists( 'plugixa_carparking_fs' ) ? plugixa_carparking_fs() : null );
if ( $plugixa_carparking_fs_instance ) {
    $plugixa_carparking_fs_instance->add_action( 'after_uninstall', function () {
        \PlugixaCarParking\Core\Uninstaller::uninstall();
    } );
} else {
    register_uninstall_hook( __FILE__, [\PlugixaCarParking\Core\Uninstaller::class, 'uninstall'] );
}
/**
 * Start the plugin. Everything it does is registered by a module that boot() discovers.
 */
add_action( 'plugins_loaded', function () {
    \PlugixaCarParking\Core\Plugin::instance()->boot();
} );