<?php
/**
 * Cookies and storage keys the blocker and the purger never touch, whatever the catalog says: the consent cookie
 * itself, this plugin's own keys (`dscc_*`) and the cookies and storage keys WordPress (including its emoji check),
 * WooCommerce and PHP need to work. One list for PHP (ServerCookieGuard, rules) and
 * the browser (passed to the purger in #dscc-data), so both sides agree.
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Blocker;

use DSolutions\CookieConsent\Catalog\PatternMatcher;
use DSolutions\CookieConsent\Settings\FieldValidators;
use DSolutions\CookieConsent\Settings\Settings;

defined( 'ABSPATH' ) || exit;

final class CookieSafelist {

	/** Name patterns ("*" = any run of characters). */
	const PATTERNS = array(
		'wordpress_*',
		'wp-*',
		'wp_*',
		'woocommerce_*',
		'PHPSESSID',
		'comment_*',
		'wc_facebook_signals_state',
		'wpEmojiSettingsSupports',
		'dscc_*',
		'_lscache_vary',
		'wfwaf-*',
	);

	/**
	 * The built-in patterns, the admin's extra list (`blocking.keep_cookies`), the filter `dscc_cookie_safelist` and the name
	 * of the consent cookie.
	 *
	 * @return string[]
	 */
	public static function patterns() {
		$patterns = self::PATTERNS;
		$extra    = Settings::get( 'blocking', 'keep_cookies', array() );
		if ( is_array( $extra ) ) {
			$patterns = array_merge( $patterns, array_values( array_filter( $extra, 'is_string' ) ) );
		}
		// Filter entries get the same sanity check as the admin list: a bare "*" must not switch the blocker off.
		foreach ( (array) apply_filters( 'dscc_cookie_safelist', array() ) as $entry ) {
			if ( is_string( $entry ) && FieldValidators::cookie_pattern( $entry ) ) {
				$patterns[] = $entry;
			}
		}
		$consent = (string) Settings::get( 'advanced', 'cookie_name', 'dscc_consent' );
		if ( '' !== $consent ) {
			$patterns[] = $consent;
		}
		return $patterns;
	}

	/**
	 * Is this name protected?
	 *
	 * @param string   $name     Cookie or storage key name.
	 * @param string[] $patterns Patterns from patterns() (read once by callers that test many names).
	 * @return bool
	 */
	public static function matches( $name, array $patterns ) {
		foreach ( $patterns as $pattern ) {
			if ( PatternMatcher::name_matches( $pattern, $name ) ) {
				return true;
			}
		}
		return false;
	}
}
