<?php
/**
 * Updates the plugin from GitHub Releases through the core update machinery, so the normal Plugins screen,
 * auto-updates, WP-CLI and remote managers (MainWP, ManageWP) all see the update. The feed itself is ReleaseFeed, the
 * download and its checksum check are PackageDownloader; this class holds the WordPress hooks.
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Update;

defined( 'ABSPATH' ) || exit;

final class GitHubUpdater {

	const DEFAULT_REPO = ReleaseFeed::DEFAULT_REPO;
	const ASSET_NAME   = ReleaseFeed::ASSET_NAME;
	const SLUG         = 'ds-cookie-consent';
	const CACHE_KEY    = ReleaseFeed::CACHE_KEY;

	/**
	 * Register hooks.
	 */
	public static function register() {
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'inject_update' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'plugin_information' ), 20, 3 );
		add_filter( 'upgrader_pre_download', array( __CLASS__, 'download_package' ), 10, 4 );
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'fix_source_folder' ), 10, 4 );
		add_action( 'admin_notices', array( __CLASS__, 'digest_notice' ) );
	}

	/**
	 * Configured repository (see ReleaseFeed::repo()).
	 *
	 * @return string
	 */
	public static function repo() {
		return ReleaseFeed::repo();
	}

	/**
	 * Latest release (see ReleaseFeed::latest()).
	 *
	 * @param bool $fetch Whether a missing cache may trigger a request.
	 * @return array|null
	 */
	public static function latest( $fetch = true ) {
		return ReleaseFeed::latest( $fetch );
	}

	/**
	 * URL WordPress downloads the update from. With a token the asset API URL is used (a private repository cannot
	 * serve browser_download_url); PackageDownloader then adds the header.
	 *
	 * @param array $release Parsed release.
	 * @return string
	 */
	private static function package_url( array $release ) {
		return '' !== ReleaseFeed::token() ? $release['api_url'] : $release['package'];
	}

	/**
	 * Add this plugin to the core update list when a newer release exists (or to "no update" so the Plugins screen
	 * can offer the auto-update toggle).
	 *
	 * @param mixed $transient Value of the update_plugins site transient.
	 * @return mixed
	 */
	public static function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}
		// Only the real update check (which fills "checked") may cost a request; other writes use the cache.
		$release = self::latest( ! empty( $transient->checked ) );
		$base    = plugin_basename( DSCC_FILE );
		$item    = (object) array(
			'id'           => 'github.com/' . self::repo(),
			'slug'         => self::SLUG,
			'plugin'       => $base,
			'new_version'  => DSCC_VERSION,
			'url'          => '',
			'package'      => '',
			'requires'     => '6.0',
			'requires_php' => '7.4',
		);
		if ( $release && ReleaseParser::is_newer( $release['version'], DSCC_VERSION ) ) {
			$item->new_version = $release['version'];
			$item->url         = $release['url'];
			$item->package     = self::package_url( $release );
			// What the download step needs to verify the file, kept with the update so it survives the release cache.
			$item->dscc_package = $release['package'];
			$item->dscc_api_url = $release['api_url'];
			$item->dscc_digest  = $release['digest'];
			if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
				$transient->response = array();
			}
			$transient->response[ $base ] = $item;
			if ( isset( $transient->no_update[ $base ] ) ) {
				unset( $transient->no_update[ $base ] );
			}
		} elseif ( '' !== self::repo() ) {
			if ( ! isset( $transient->no_update ) || ! is_array( $transient->no_update ) ) {
				$transient->no_update = array();
			}
			$transient->no_update[ $base ] = $item;
		}
		return $transient;
	}

	/**
	 * Data for the "View details" popup of the Plugins screen.
	 *
	 * @param false|object|array $result Earlier result.
	 * @param string             $action API action.
	 * @param object             $args   Request arguments.
	 * @return false|object|array
	 */
	public static function plugin_information( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! is_object( $args ) || ! isset( $args->slug ) || self::SLUG !== $args->slug ) {
			return $result;
		}
		$release = self::latest( true );
		if ( ! $release ) {
			return $result;
		}
		$notes = '' !== $release['notes'] ? wp_kses_post( wpautop( esc_html( $release['notes'] ) ) ) : '';
		return (object) array(
			'name'          => 'DS Cookie Consent',
			'slug'          => self::SLUG,
			'version'       => $release['version'],
			'author'        => '<a href="https://d-solutions.vn/">D-Solutions</a>',
			'homepage'      => $release['url'],
			'requires'      => '6.0',
			'requires_php'  => '7.4',
			'last_updated'  => $release['published'],
			'download_link' => self::package_url( $release ),
			'sections'      => array(
				'description' => esc_html__( 'Cookie consent banner with Google Consent Mode v2, consent log and live-preview settings. Reusable across many sites.', 'ds-cookie-consent' ),
				'changelog'   => $notes,
			),
		);
	}

	/**
	 * Download the package of this plugin ourselves (see PackageDownloader).
	 *
	 * @param mixed  $reply      Earlier reply (false = let core download).
	 * @param string $package    Package URL.
	 * @param object $upgrader   Upgrader.
	 * @param array  $hook_extra Hook data.
	 * @return mixed
	 */
	public static function download_package( $reply, $package, $upgrader = null, $hook_extra = array() ) {
		return PackageDownloader::download( $reply, $package, $upgrader, $hook_extra );
	}

	/**
	 * Tell admins when the newest release has no published checksum, so the download cannot be verified.
	 */
	public static function digest_notice() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->id, array( 'plugins', 'update-core' ), true ) || ! current_user_can( 'update_plugins' ) ) {
			return;
		}
		$release = ReleaseFeed::latest( false );
		if ( ! $release || '' !== $release['digest'] || ! ReleaseParser::is_newer( $release['version'], DSCC_VERSION ) ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>' . esc_html(
			sprintf(
				/* translators: %s: version number */
				__( 'DS Cookie Consent %s has no published SHA-256 checksum on GitHub, so the download cannot be verified before it is installed.', 'ds-cookie-consent' ),
				$release['version']
			)
		) . '</p></div>';
	}

	/**
	 * Make the extracted folder carry the name of the installed plugin folder, so the update replaces the existing
	 * plugin even when it was installed under another folder name.
	 *
	 * @param string|\WP_Error $source        Extracted folder.
	 * @param string           $remote_source Temporary working folder.
	 * @param object           $upgrader      Upgrader.
	 * @param array            $hook_extra    Hook data.
	 * @return string|\WP_Error
	 */
	public static function fix_source_folder( $source, $remote_source, $upgrader = null, $hook_extra = array() ) {
		unset( $upgrader );
		$base = plugin_basename( DSCC_FILE );
		if ( is_wp_error( $source ) || ! is_array( $hook_extra ) || ! isset( $hook_extra['plugin'] ) || $base !== $hook_extra['plugin'] ) {
			return $source;
		}
		global $wp_filesystem;
		$wanted = dirname( $base );
		if ( ! $wp_filesystem || '.' === $wanted ) {
			return $source;
		}
		if ( ! $wp_filesystem->exists( trailingslashit( $source ) . 'ds-cookie-consent.php' ) ) {
			return new \WP_Error( 'dscc_bad_package', __( 'The update package does not contain the DS Cookie Consent plugin file.', 'ds-cookie-consent' ) );
		}
		if ( basename( untrailingslashit( $source ) ) === $wanted ) {
			return $source;
		}
		$target = trailingslashit( $remote_source ) . $wanted;
		if ( $wp_filesystem->exists( $target ) ) {
			$wp_filesystem->delete( $target, true );
		}
		if ( ! $wp_filesystem->move( untrailingslashit( $source ), $target ) ) {
			return new \WP_Error( 'dscc_rename_failed', __( 'Could not rename the update folder to match the installed plugin.', 'ds-cookie-consent' ) );
		}
		return trailingslashit( $target );
	}
}
