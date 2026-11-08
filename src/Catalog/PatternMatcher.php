<?php
/**
 * Pure matching helpers: cookie-name patterns and script patterns. No WordPress or database access.
 *
 * Cookie names: a name containing "*" is a glob ("*" = any run of characters), for example "_ga_*".
 * Script patterns (service.script_patterns): {hosts[], src_regex[], inline_regex[]}; the regexes carry no delimiters
 * and are matched case-insensitively (wrapped in "~...~i", so "~" is not allowed inside them).
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Catalog;

final class PatternMatcher {

	/**
	 * True when the stored name is a glob pattern.
	 *
	 * @param string $name Stored cookie name.
	 * @return bool
	 */
	public static function is_pattern( $name ) {
		return false !== strpos( (string) $name, '*' );
	}

	/**
	 * Does a concrete cookie name match a stored name or pattern? Case-sensitive, like cookie names.
	 *
	 * @param string $pattern   Stored name or pattern.
	 * @param string $candidate Observed name.
	 * @return bool
	 */
	public static function name_matches( $pattern, $candidate ) {
		$pattern   = (string) $pattern;
		$candidate = (string) $candidate;
		if ( ! self::is_pattern( $pattern ) ) {
			return $pattern === $candidate;
		}
		$parts = array_map(
			static function ( $part ) {
				return preg_quote( $part, '~' );
			},
			explode( '*', $pattern )
		);
		return 1 === preg_match( '~^' . implode( '.*', $parts ) . '$~s', $candidate );
	}

	/**
	 * Name shown to visitors. A trailing "*" after "_" or "-" in a name that starts with "_" becomes a placeholder
	 * ("_ga_*" -> "_ga_<mã>", "_gat_UA-*" -> "_gat_UA-<mã>"); every other pattern keeps the literal "*"
	 * ("ttcsid*", "_sp_id.*", "cly_*", "sbjs_*"), as cookie policies usually print them. Names stored in localStorage or
	 * sessionStorage get a suffix saying so, unless the name is already a sentence (contains a space).
	 *
	 * @param string $name    Stored name.
	 * @param string $lang    vi|en (the placeholder reads "<mã>" or "<id>").
	 * @param string $storage Storage kind.
	 * @return string
	 */
	public static function display_name( $name, $lang = 'vi', $storage = 'cookie' ) {
		$name = (string) $name;
		if ( 0 === strpos( $name, '_' ) ) {
			$name = (string) preg_replace( '/([_-])\*$/', '$1' . ( 'en' === $lang ? '<id>' : '<mã>' ), $name );
		}
		$suffix = array(
			'localStorage'   => array(
				'vi' => ' (lưu cục bộ)',
				'en' => ' (local storage)',
			),
			'sessionStorage' => array(
				'vi' => ' (lưu theo phiên)',
				'en' => ' (session storage)',
			),
		);
		if ( isset( $suffix[ $storage ] ) && false === strpos( $name, ' ' ) ) {
			$name .= $suffix[ $storage ][ 'en' === $lang ? 'en' : 'vi' ];
		}
		return $name;
	}

	/**
	 * Lower-case host of a URL or protocol-relative URL; empty when there is none.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function host_of( $url ) {
		$url = trim( (string) $url );
		if ( 0 === strpos( $url, '//' ) ) {
			$url = 'https:' . $url;
		}
		$host = parse_url( $url, PHP_URL_HOST ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- plain PHP file, WordPress is not needed here.
		return is_string( $host ) ? strtolower( $host ) : '';
	}

	/**
	 * Is the host equal to, or a subdomain of, one of the listed hosts?
	 *
	 * @param string   $host  Host.
	 * @param string[] $hosts Bare hostnames.
	 * @return bool
	 */
	public static function host_matches( $host, array $hosts ) {
		$host = strtolower( (string) $host );
		if ( '' === $host ) {
			return false;
		}
		foreach ( $hosts as $candidate ) {
			$candidate = strtolower( trim( (string) $candidate ) );
			if ( '' === $candidate ) {
				continue;
			}
			if ( $host === $candidate || substr( $host, -strlen( $candidate ) - 1 ) === '.' . $candidate ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Does a script (external src and/or inline body) belong to a service with these patterns?
	 *
	 * @param array  $patterns {hosts, src_regex, inline_regex}.
	 * @param string $src      External script or resource URL ('' for inline).
	 * @param string $inline   Inline script body ('' for external).
	 * @return bool
	 */
	public static function script_matches( array $patterns, $src = '', $inline = '' ) {
		$src    = (string) $src;
		$inline = (string) $inline;
		if ( '' !== $src ) {
			if ( self::host_matches( self::host_of( $src ), self::list_of( $patterns, 'hosts' ) ) ) {
				return true;
			}
			if ( self::any_regex( self::list_of( $patterns, 'src_regex' ), $src ) ) {
				return true;
			}
		}
		return '' !== $inline && self::any_regex( self::list_of( $patterns, 'inline_regex' ), $inline );
	}

	/**
	 * Is the regex source acceptable (no delimiter character, compiles)?
	 *
	 * @param string $regex Regex without delimiters.
	 * @return bool
	 */
	public static function valid_regex( $regex ) {
		$regex = (string) $regex;
		return '' !== $regex && false === strpos( $regex, '~' ) && strlen( $regex ) <= 300 && ! self::has_nested_quantifier( $regex ) && false !== @preg_match( '~' . $regex . '~i', '' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- invalid patterns are rejected here.
	}

	/**
	 * Heuristic for catastrophic backtracking: a group that contains a quantifier ("+", "*") and is itself repeated,
	 * such as "(a+)+" or "(\\s*x*)*". Such patterns are refused; they would run against whole pages.
	 *
	 * @param string $regex Regex without delimiters.
	 * @return bool
	 */
	public static function has_nested_quantifier( $regex ) {
		return 1 === preg_match( '/\((?:[^()\\\\]|\\\\.)*[+*](?:[^()\\\\]|\\\\.)*\)\s*(?:[+*]|\{\d*,\d*\})/', (string) $regex );
	}

	/**
	 * Normalise a script_patterns value to the three string lists.
	 *
	 * @param mixed $value Decoded JSON.
	 * @return array{hosts:string[],src_regex:string[],inline_regex:string[]}
	 */
	public static function normalize_patterns( $value ) {
		$out = array(
			'hosts'        => array(),
			'src_regex'    => array(),
			'inline_regex' => array(),
		);
		if ( ! is_array( $value ) ) {
			return $out;
		}
		foreach ( array_keys( $out ) as $key ) {
			$out[ $key ] = self::list_of( $value, $key );
		}
		return $out;
	}

	/**
	 * String list stored under a key.
	 *
	 * @param array  $patterns Patterns.
	 * @param string $key      Key.
	 * @return string[]
	 */
	private static function list_of( array $patterns, $key ) {
		if ( empty( $patterns[ $key ] ) || ! is_array( $patterns[ $key ] ) ) {
			return array();
		}
		return array_values( array_filter( $patterns[ $key ], 'is_string' ) );
	}

	/**
	 * Does any regex match the subject?
	 *
	 * @param string[] $regexes Regex sources.
	 * @param string   $subject Subject.
	 * @return bool
	 */
	private static function any_regex( array $regexes, $subject ) {
		foreach ( $regexes as $regex ) {
			if ( self::valid_regex( $regex ) && 1 === preg_match( '~' . $regex . '~i', $subject ) ) {
				return true;
			}
		}
		return false;
	}
}
