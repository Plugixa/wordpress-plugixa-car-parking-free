<?php
/**
 * @package PlugixaCarParking
 */

declare( strict_types = 1 );

namespace PlugixaCarParking\Core\App;

use PlugixaCarParking\Core\Admin\Hmr;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The compiled app: where it mounts, and how its files reach the page.
 *
 * TWO BUNDLES EXIST AND EXACTLY ONE IS SERVED. The free bundle is in `assets/backend/build`. The
 * premium one, in `pro/assets/backend/build`, is the whole app again with the premium screens
 * compiled in, not an addition to the free one. The premium bundle is served when it is on disk
 * and complete; otherwise the free one is.
 */
final class Bundle {

	public const HANDLE = 'plugixa-car-parking-app';

	/**
	 * The id of the element the app mounts into. The app's entry file looks for the same id.
	 */
	public const ROOT_ID = 'plugixa-car-parking-root';

	/**
	 * The manifest the bundler writes at the root of a build, and the key of the app's entry in
	 * it. Both are set in the app's vite.config.js.
	 */
	private const MANIFEST = 'manifest.json';
	private const ENTRY    = 'src/main.tsx';

	/**
	 * Where a build can be, relative to the plugin, in the order they are tried.
	 *
	 * @var string[]
	 */
	private const BUILD_DIRS = array(
		'pro/assets/backend/build/',
		'assets/backend/build/',
	);

	/**
	 * The app's mount point.
	 */
	public static function mount_html(): string {
		return '<div id="' . esc_attr( self::ROOT_ID ) . '"></div>';
	}

	/**
	 * What the app's page prints: the mount point, or a notice when there is nothing to mount.
	 *
	 * An empty mount point is a blank page with no explanation, so a copy of the plugin without
	 * its compiled app says so instead.
	 *
	 * @param bool|null $loadable Whether the app can be loaded. Null asks `is_loadable()`.
	 */
	public static function page_html( ?bool $loadable = null ): string {
		return ( $loadable ?? self::is_loadable() ) ? self::mount_html() : self::missing_notice_html();
	}

	/**
	 * Whether there is anything to load: a development server, or a complete build.
	 */
	public static function is_loadable(): bool {
		return self::hmr_available() || null !== self::find_build( PLUGIXA_CARPARKING_PATH );
	}

	/**
	 * The notice shown in place of the app when its files are not there.
	 */
	public static function missing_notice_html(): string {
		return '<div class="wrap"><h1>' . esc_html__( 'Plugixa Car Parking', 'plugixa-car-parking' ) . '</h1>'
			. '<div class="notice notice-error inline"><p>'
			. esc_html__( 'The files of the admin app are missing from this copy of the plugin, so this page has nothing to show. Installing the plugin again from its download restores them.', 'plugixa-car-parking' )
			. '</p><p>'
			. sprintf(
				/* translators: 1: a shell command, 2: a directory inside the plugin. */
				esc_html__( 'Developers: build the app by running %1$s in %2$s.', 'plugixa-car-parking' ),
				'<code>npm install &amp;&amp; npm run build</code>',
				'<code>assets/backend/app</code>'
			)
			. '</p></div></div>';
	}

	/**
	 * Load the app: from the development server when one is running, from the build otherwise.
	 */
	public static function enqueue(): void {
		if ( self::hmr_available() ) {
			Hmr::enqueue();

			return;
		}

		self::enqueue_build();
	}

	/**
	 * Whether to load from the development server.
	 *
	 * The class that does it is deleted from both release archives, so on a customer's site this
	 * is false before anything else is asked. On a working copy, a constant decides when it is
	 * defined, either way; otherwise the server is used when it is actually listening.
	 */
	private static function hmr_available(): bool {
		if ( ! file_exists( PLUGIXA_CARPARKING_PATH . 'includes/Core/Admin/Hmr.php' ) ) {
			return false;
		}

		if ( defined( 'PLUGIXA_CARPARKING_HMR' ) ) {
			return (bool) PLUGIXA_CARPARKING_HMR;
		}

		return Hmr::server_is_up();
	}

	private static function enqueue_build(): void {
		$build = self::find_build( PLUGIXA_CARPARKING_PATH );
		if ( null === $build ) {
			return;
		}

		$dir = $build['dir'];

		/*
		 * NO VERSION ON THE MODULE, deliberately. The file name carries a content hash, which is
		 * the cache-buster. A `?ver=` would also make the entry a different URL from the one its
		 * own chunks import it back by, so the browser would load the entry twice: two copies of
		 * React on one page, and hooks failing with an error that points nowhere near the cause.
		 */
		wp_enqueue_script_module(
			self::HANDLE,
			PLUGIXA_CARPARKING_URL . $dir . $build['file'],
			array(),
			null // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- the file name is content-hashed; see the comment above.
		);

		$stylesheet = PLUGIXA_CARPARKING_PATH . $dir . 'css/main.css';

		if ( file_exists( $stylesheet ) ) {
			wp_enqueue_style(
				self::HANDLE,
				PLUGIXA_CARPARKING_URL . $dir . 'css/main.css',
				array(),
				self::asset_version( $stylesheet )
			);
		}
	}

	/**
	 * A cache-buster for a file whose name does not change between builds.
	 *
	 * @param string $path Absolute path to the file.
	 */
	private static function asset_version( string $path ): string {
		$mtime = file_exists( $path ) ? filemtime( $path ) : false;

		return false !== $mtime ? (string) $mtime : PLUGIXA_CARPARKING_VERSION;
	}

	/**
	 * The build to serve: the premium one when it is complete, the free one otherwise.
	 *
	 * A DIRECTORY BEING THERE IS NOT A BUILD BEING THERE. The premium directory can exist and
	 * hold nothing the app can start from: a build that was never run, or one left by an earlier
	 * version of the app. Choosing it on its presence alone would leave the page blank while a
	 * working free bundle sat beside it, so each directory is judged by its manifest.
	 *
	 * @param string $plugin_path The plugin's directory, with a trailing slash.
	 *
	 * @return array{dir: string, file: string}|null The build directory, relative to the plugin
	 *                                               with a trailing slash, and its entry file.
	 */
	public static function find_build( string $plugin_path ): ?array {
		foreach ( self::BUILD_DIRS as $dir ) {
			$file = self::manifest_entry( $plugin_path . $dir );

			if ( null !== $file ) {
				return array(
					'dir'  => $dir,
					'file' => $file,
				);
			}
		}

		return null;
	}

	/**
	 * The entry file named in a build's manifest, or null when the build cannot be used.
	 *
	 * ONLY THE MANIFEST THIS APP'S BUILD WRITES IS READ. The bundler's default location,
	 * `.vite/manifest.json`, is where the previous app's build kept its own, under the same
	 * entry key. Accepting it would load that app into a page prepared for this one.
	 *
	 * The entry has to be on disk as well: a manifest that outlived its files names a script
	 * the browser cannot fetch.
	 *
	 * @param string $build_path Absolute path to a build directory, with a trailing slash.
	 */
	private static function manifest_entry( string $build_path ): ?string {
		$path = $build_path . self::MANIFEST;

		if ( ! is_file( $path ) ) {
			return null;
		}

		$manifest = json_decode( (string) file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- reads the bundler's manifest from this plugin's own build directory. WP_Filesystem is for user-writable paths and would ask the host for FTP credentials to read a file the plugin shipped.
		$entry    = is_array( $manifest ) ? ( $manifest[ self::ENTRY ] ?? null ) : null;
		$file     = is_array( $entry ) && is_string( $entry['file'] ?? null ) ? $entry['file'] : '';

		return '' !== $file && is_file( $build_path . $file ) ? $file : null;
	}
}
