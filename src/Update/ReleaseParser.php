<?php
/**
 * Pure helpers for the GitHub release feed: repository slug check, tag to version, version compare and the parsing
 * of one "latest release" API response. No WordPress calls, so it is unit tested with fixtures.
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Update;

defined( 'ABSPATH' ) || exit;

final class ReleaseParser {

	/** Largest release note text kept (bytes); the rest is cut so the cached answer stays small. */
	const MAX_NOTES = 20000;

	/**
	 * Whether a string is an "owner/name" GitHub repository slug.
	 *
	 * @param mixed $slug Candidate.
	 * @return bool
	 */
	public static function valid_repo( $slug ) {
		return is_string( $slug ) && 1 === preg_match( '#^[A-Za-z0-9_.-]{1,100}/[A-Za-z0-9_.-]{1,100}$#', $slug ) && false === strpos( $slug, '..' );
	}

	/**
	 * Version number from a release tag ("v1.2.3" -> "1.2.3"). Empty string when the tag is not a version.
	 *
	 * @param mixed $tag Tag name.
	 * @return string
	 */
	public static function version_from_tag( $tag ) {
		if ( ! is_string( $tag ) ) {
			return '';
		}
		$tag = ltrim( trim( $tag ), 'vV' );
		return 1 === preg_match( '/^\d+(?:\.\d+){0,3}(?:-[0-9A-Za-z.]+)?$/', $tag ) ? $tag : '';
	}

	/**
	 * Whether $remote is strictly newer than $local (PHP version_compare rules, so 1.0.0 > 1.0.0-rc1).
	 *
	 * @param string $remote Remote version.
	 * @param string $local  Installed version.
	 * @return bool
	 */
	public static function is_newer( $remote, $local ) {
		return '' !== $remote && version_compare( $remote, (string) $local, '>' );
	}

	/**
	 * Read the answer of GET /repos/{owner}/{repo}/releases/latest.
	 *
	 * Returns null for anything that cannot be installed: invalid JSON, a draft or pre-release, a tag that is not a
	 * version, or a release without the expected zip asset.
	 *
	 * @param string $json       Response body.
	 * @param string $asset_name Name of the zip asset to install.
	 * @return array|null Keys: version, tag, package (public download URL), api_url (asset API URL, used with a token),
	 *                    digest (sha256 hex or ''), size, notes, published, url.
	 */
	public static function parse( $json, $asset_name ) {
		$data = json_decode( (string) $json, true );
		if ( ! is_array( $data ) || ! empty( $data['draft'] ) || ! empty( $data['prerelease'] ) ) {
			return null;
		}
		$version = self::version_from_tag( isset( $data['tag_name'] ) ? $data['tag_name'] : '' );
		if ( '' === $version || ! isset( $data['assets'] ) || ! is_array( $data['assets'] ) ) {
			return null;
		}
		foreach ( $data['assets'] as $asset ) {
			if ( ! is_array( $asset ) || ( isset( $asset['name'] ) ? $asset['name'] : '' ) !== $asset_name ) {
				continue;
			}
			$package = isset( $asset['browser_download_url'] ) ? (string) $asset['browser_download_url'] : '';
			$api_url = isset( $asset['url'] ) ? (string) $asset['url'] : '';
			if ( 0 !== strpos( $package, 'https://' ) || 0 !== strpos( $api_url, 'https://api.github.com/' ) ) {
				continue;
			}
			$digest = '';
			if ( isset( $asset['digest'] ) && is_string( $asset['digest'] ) && 1 === preg_match( '/^sha256:([0-9a-f]{64})$/i', $asset['digest'], $m ) ) {
				$digest = strtolower( $m[1] );
			}
			$notes = isset( $data['body'] ) && is_string( $data['body'] ) ? $data['body'] : '';
			return array(
				'version'   => $version,
				'tag'       => (string) $data['tag_name'],
				'package'   => $package,
				'api_url'   => $api_url,
				'digest'    => $digest,
				'size'      => isset( $asset['size'] ) ? (int) $asset['size'] : 0,
				'notes'     => function_exists( 'mb_substr' ) ? mb_substr( $notes, 0, self::MAX_NOTES ) : substr( $notes, 0, self::MAX_NOTES ),
				'published' => isset( $data['published_at'] ) && is_string( $data['published_at'] ) ? $data['published_at'] : '',
				'url'       => isset( $data['html_url'] ) && is_string( $data['html_url'] ) && 0 === strpos( $data['html_url'], 'https://' ) ? $data['html_url'] : '',
			);
		}
		return null;
	}
}
