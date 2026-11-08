<?php
/**
 * User-agent based skip rules, kept in one place and shared by the head script and settings.
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Frontend;

defined( 'ABSPATH' ) || exit;

final class UserAgentRules {

	/**
	 * Crawlers that never see the banner. Lighthouse is deliberately NOT listed: performance
	 * audits must measure the real page including the banner.
	 */
	const BOT_PATTERN = 'googlebot|bingbot|yandex(bot|images)?|baiduspider|duckduckbot|slurp|facebookexternalhit|applebot';

	/**
	 * Bot regex (JS compatible, used case-insensitively) or empty when disabled.
	 *
	 * @param bool $enabled skip_bots setting.
	 * @return string
	 */
	public static function bot_pattern( $enabled ) {
		return $enabled ? self::BOT_PATTERN : '';
	}

	/**
	 * Does the user agent match one of the admin's skip patterns (same matching as the head script: case-insensitive)?
	 *
	 * @param string[] $patterns Patterns without delimiters.
	 * @param string   $agent    User agent.
	 * @return bool
	 */
	public static function matches( array $patterns, $agent ) {
		foreach ( $patterns as $pattern ) {
			if ( '' !== $pattern && 1 === @preg_match( '~' . str_replace( '~', '\\~', $pattern ) . '~i', (string) $agent ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- patterns were validated when saved.
				return true;
			}
		}
		return false;
	}
}
