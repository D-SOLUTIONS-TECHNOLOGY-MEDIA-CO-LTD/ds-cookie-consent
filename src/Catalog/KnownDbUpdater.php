<?php
/**
 * Downloads a newer known-cookie database from a configurable URL. Nothing is stored unless the file
 * passes the structure checks AND its SHA-256 proof: the sha256 field recomputed over the services payload,
 * and/or the "<url>.sha256" sidecar (sha256sum format) over the whole file. A mismatch rejects the update.
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Catalog;

use WP_Error;

defined( 'ABSPATH' ) || exit;

final class KnownDbUpdater {

	const META_OPTION = 'dscc_known_db_meta';

	/**
	 * Configured source URL (setting advanced.known_db_url, filter dscc_known_db_url).
	 *
	 * @return string Empty when none is configured.
	 */
	public static function configured_url() {
		$settings = \DSolutions\CookieConsent\Settings\Settings::group( 'advanced' );
		$default  = isset( $settings['known_db_url'] ) ? (string) $settings['known_db_url'] : '';
		return (string) apply_filters( 'dscc_known_db_url', $default );
	}

	/**
	 * Download, verify and store the database. The request goes through wp_safe_remote_get(), which refuses
	 * private and loopback addresses.
	 *
	 * @param string $url HTTPS URL of known-cookies.json.
	 * @return array|WP_Error {version, services, cookies} on success.
	 */
	public static function update( $url ) {
		$url = trim( (string) $url );
		if ( 0 !== strpos( $url, 'https://' ) ) {
			return new WP_Error( 'dscc_db_url', __( 'The update URL must be a valid https address.', 'ds-cookie-consent' ) );
		}
		$body = self::fetch( $url );
		if ( is_wp_error( $body ) ) {
			return $body;
		}

		// Sidecar: whole-file digest. Optional, but a present and wrong one is a rejection.
		$sidecar = self::fetch( self::sidecar_url( $url ), true );
		if ( is_wp_error( $sidecar ) ) {
			return $sidecar; // The sidecar exists or could not be read: either way the update is not trusted.
		}
		if ( null !== $sidecar ) {
			if ( ! preg_match( '/\b([0-9a-fA-F]{64})\b/', $sidecar, $m ) || ! hash_equals( strtolower( $m[1] ), hash( 'sha256', $body ) ) ) {
				return new WP_Error( 'dscc_db_sidecar', __( 'Update refused: the accompanying SHA-256 checksum does not match the downloaded file.', 'ds-cookie-consent' ) );
			}
		}

		$parsed = KnownDbValidator::parse( $body );
		if ( null === $parsed['data'] ) {
			/* translators: %s: first validation error */
			return new WP_Error( 'dscc_db_invalid', sprintf( __( 'Update refused: the file is invalid (%s).', 'ds-cookie-consent' ), reset( $parsed['errors'] ) ) );
		}
		$has_sidecar = null !== $sidecar;
		if ( ! isset( $parsed['data']['sha256'] ) && ! $has_sidecar ) {
			return new WP_Error( 'dscc_db_unverifiable', __( 'Update refused: the file has no SHA-256 checksum to verify.', 'ds-cookie-consent' ) );
		}

		$saved = self::store( $body );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		KnownDb::flush();
		update_option(
			self::META_OPTION,
			array(
				'version'    => $parsed['data']['version'],
				'url'        => $url,
				'updated_at' => time(),
			),
			false
		);
		$db = KnownDb::from_json( $body );
		return array(
			'version'  => $parsed['data']['version'],
			'services' => count( $parsed['data']['services'] ),
			'cookies'  => $db ? $db->cookie_count() : 0,
		);
	}

	/**
	 * URL of the "<file>.sha256" sidecar: ".sha256" is appended to the path, the query string is kept.
	 *
	 * @param string $url File URL.
	 * @return string
	 */
	public static function sidecar_url( $url ) {
		$parts = explode( '#', (string) $url, 2 );
		$split = explode( '?', $parts[0], 2 );
		return $split[0] . '.sha256' . ( isset( $split[1] ) ? '?' . $split[1] : '' );
	}

	/**
	 * GET a URL with a size cap.
	 *
	 * @param string $url      URL.
	 * @param bool   $optional When true only a 404 means "absent" (null); any other failure is still an error.
	 * @return string|WP_Error|null
	 */
	private static function fetch( $url, $optional = false ) {
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 20,
				'redirection'         => 3,
				'limit_response_size' => KnownDbValidator::MAX_BYTES + 1,
			)
		);
		if ( $optional && ! is_wp_error( $response ) && 404 === (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return new WP_Error( 'dscc_db_download', __( 'Could not download the cookie database file from the configured URL.', 'ds-cookie-consent' ) );
		}
		return (string) wp_remote_retrieve_body( $response );
	}

	/**
	 * Write the file atomically into uploads/dscc/.
	 *
	 * @param string $body Verified file contents.
	 * @return true|WP_Error
	 */
	private static function store( $body ) {
		$path = KnownDb::stored_path();
		$dir  = dirname( $path );
		if ( ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'dscc_db_write', __( 'Could not create the uploads/dscc folder.', 'ds-cookie-consent' ) );
		}
		$tmp = $path . '.tmp';
		// Atomic write of a verified file inside uploads: write a sibling temp file, then replace.
		if ( false === file_put_contents( $tmp, $body ) || ! rename( $tmp, $path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.WP.AlternativeFunctions.rename_rename
			wp_delete_file( $tmp );
			return new WP_Error( 'dscc_db_write', __( 'Could not write the cookie database file to the uploads folder.', 'ds-cookie-consent' ) );
		}
		return true;
	}
}
