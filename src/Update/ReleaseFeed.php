<?php
/**
 * The latest GitHub release of this plugin, read from the cache or from api.github.com.
 *
 * - Repository: constant DSCC_UPDATE_REPO ("owner/name"), filter dscc_update_repo.
 * - Optional token (constant DSCC_GITHUB_TOKEN, filter dscc_github_token) for rate limits or a private repository. It
 *   is only ever put in an Authorization header of a request to api.github.com.
 * - Answers are cached in a site transient: 12 hours for a release or "no release yet", one hour for anything that
 *   went wrong (transport error, redirect, other status, a body that is not a usable release).
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Update;

defined( 'ABSPATH' ) || exit;

final class ReleaseFeed {

	const DEFAULT_REPO = 'D-SOLUTIONS-TECHNOLOGY-MEDIA-CO-LTD/ds-cookie-consent';
	const ASSET_NAME   = 'ds-cookie-consent.zip';
	const CACHE_KEY    = 'dscc_gh_release';
	const CACHE_TTL    = 12 * HOUR_IN_SECONDS;
	const ERROR_TTL    = HOUR_IN_SECONDS;
	const API_HOST     = 'api.github.com';

	/**
	 * Configured repository ("owner/name"); empty when it is not valid, which switches the updater off.
	 *
	 * @return string
	 */
	public static function repo() {
		$repo = defined( 'DSCC_UPDATE_REPO' ) ? DSCC_UPDATE_REPO : self::DEFAULT_REPO;
		$repo = apply_filters( 'dscc_update_repo', $repo );
		return ReleaseParser::valid_repo( $repo ) ? $repo : '';
	}

	/**
	 * Optional API token.
	 *
	 * @return string
	 */
	public static function token() {
		$token = defined( 'DSCC_GITHUB_TOKEN' ) && is_string( DSCC_GITHUB_TOKEN ) ? DSCC_GITHUB_TOKEN : '';
		$token = apply_filters( 'dscc_github_token', $token );
		return is_string( $token ) ? trim( $token ) : '';
	}

	/**
	 * Request headers for GitHub. The token is added only for https://api.github.com.
	 *
	 * @param string $url    Request URL.
	 * @param string $accept Accept header.
	 * @return array
	 */
	public static function headers( $url, $accept ) {
		$headers = array(
			'Accept'               => $accept,
			'User-Agent'           => 'DS-Cookie-Consent/' . DSCC_VERSION,
			'X-GitHub-Api-Version' => '2022-11-28',
		);
		$token   = self::token();
		if ( '' !== $token && self::API_HOST === wp_parse_url( $url, PHP_URL_HOST ) && 0 === strpos( $url, 'https://' ) ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}
		return $headers;
	}

	/**
	 * The latest release, from the cache or from GitHub.
	 *
	 * @param bool $fetch Whether a missing cache may trigger a request.
	 * @return array|null Parsed release (see ReleaseParser::parse) or null when none is known.
	 */
	public static function latest( $fetch = true ) {
		$repo = self::repo();
		if ( '' === $repo ) {
			return null;
		}
		$cached = get_site_transient( self::CACHE_KEY );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only flag set by core's "Check again" link; only users who may update plugins can skip the cache.
		$forced = is_admin() && isset( $_GET['force-check'] ) && current_user_can( 'update_plugins' );
		if ( is_array( $cached ) && isset( $cached['repo'] ) && $cached['repo'] === $repo && ! $forced ) {
			return isset( $cached['release'] ) && is_array( $cached['release'] ) ? $cached['release'] : null;
		}
		if ( ! $fetch ) {
			return null;
		}

		$url      = 'https://' . self::API_HOST . '/repos/' . $repo . '/releases/latest';
		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 8,
				// A redirect would send the request (and its token) somewhere else; treat it as an error.
				'redirection' => 0,
				'headers'     => self::headers( $url, 'application/vnd.github+json' ),
				'user-agent'  => 'DS-Cookie-Consent/' . DSCC_VERSION,
			)
		);
		if ( is_wp_error( $response ) ) {
			self::remember( $repo, null, self::ERROR_TTL );
			return null;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 404 === $code ) {
			// No release yet (or a private repository without a token): ask again later, not on every check.
			self::remember( $repo, null, self::CACHE_TTL );
			return null;
		}
		if ( 200 !== $code ) {
			self::remember( $repo, null, self::ERROR_TTL );
			return null;
		}
		$release = ReleaseParser::parse( wp_remote_retrieve_body( $response ), self::ASSET_NAME );
		// A 200 that is not a usable release (malformed, draft, no asset) is retried sooner than a real answer.
		self::remember( $repo, $release, null === $release ? self::ERROR_TTL : self::CACHE_TTL );
		return $release;
	}

	/**
	 * Store the answer.
	 *
	 * @param string     $repo    Repository.
	 * @param array|null $release Parsed release.
	 * @param int        $ttl     Lifetime in seconds.
	 */
	private static function remember( $repo, $release, $ttl ) {
		set_site_transient(
			self::CACHE_KEY,
			array(
				'repo'    => $repo,
				'release' => $release,
				'checked' => time(),
			),
			$ttl
		);
	}
}
