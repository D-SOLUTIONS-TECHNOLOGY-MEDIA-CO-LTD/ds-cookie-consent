<?php
/**
 * Entry-level validators for list settings (user-agent patterns, CSS selectors, region codes).
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Settings;

final class FieldValidators {

	/**
	 * A user-agent fragment must be a short, compilable regular expression.
	 *
	 * @param string $pattern Pattern without delimiters.
	 * @return bool
	 */
	public static function ua_pattern( $pattern ) {
		if ( strlen( $pattern ) > 100 || preg_match( '/[\x00-\x1F\x7F<>"]/', $pattern ) ) {
			return false;
		}
		return false !== @preg_match( '~' . str_replace( '~', '\~', $pattern ) . '~i', '' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- invalid patterns are rejected here.
	}

	/**
	 * CSS selector used to hide or avoid third-party elements: no braces, quotes breaking out, or HTML.
	 *
	 * @param string $selector Selector.
	 * @return bool
	 */
	public static function selector( $selector ) {
		return strlen( $selector ) <= 100 && ! preg_match( '/[{};<\\\\@]|\/\*/', $selector );
	}

	/**
	 * ISO 3166 country or region code such as VN, US-CA.
	 *
	 * @param string $region Code.
	 * @return bool
	 */
	public static function region( $region ) {
		return (bool) preg_match( '/^[A-Z]{2}(-[A-Z0-9]{1,3})?$/', $region );
	}

	/**
	 * Blocker exclusion entry: a plain substring (3 to 200 characters) or a regular expression written as /.../ that
	 * compiles and has no nested quantifier. Control characters and angle brackets are refused.
	 *
	 * @param string $entry Entry.
	 * @return bool
	 */
	public static function exclusion( $entry ) {
		if ( strlen( $entry ) < 3 || strlen( $entry ) > 200 || preg_match( '/[\x00-\x1F\x7F<>]/', $entry ) ) {
			return false;
		}
		if ( strlen( $entry ) > 2 && '/' === $entry[0] && '/' === substr( $entry, -1 ) ) {
			return \DSolutions\CookieConsent\Catalog\PatternMatcher::valid_regex( substr( $entry, 1, -1 ) );
		}
		return true;
	}

	/**
	 * A cookie name or glob the admin wants kept ("*" = any run of characters): 3 to 64 safe characters, at least two of
	 * them not "*", so a single "*" can never switch the blocker off for everything.
	 *
	 * @param string $entry Entry.
	 * @return bool
	 */
	public static function cookie_pattern( $entry ) {
		return strlen( $entry ) >= 3 && strlen( $entry ) <= 64 && (bool) preg_match( '/^[A-Za-z0-9_.\-%*]+$/', $entry ) && strlen( str_replace( '*', '', $entry ) ) >= 2;
	}
}
