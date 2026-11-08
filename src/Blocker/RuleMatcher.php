<?php
/**
 * Decides whether a script, iframe or pixel belongs to a blocking rule, and whether the admin excluded it.
 * Pure: no WordPress functions.
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Blocker;

use DSolutions\CookieConsent\Catalog\PatternMatcher;

final class RuleMatcher {

	/**
	 * First rule that claims a URL (iframe, pixel, script) or an inline script body.
	 *
	 * @param array[] $rules  Rules {s, c, h, r, i}.
	 * @param string  $src    URL ('' for inline scripts).
	 * @param string  $inline Inline body.
	 * @return array{rule:array,fragment:string}|null The rule and the part of the text that matched.
	 */
	public static function match( array $rules, $src, $inline ) {
		$host = '' !== $src ? PatternMatcher::host_of( $src ) : '';
		foreach ( $rules as $rule ) {
			if ( '' !== $src ) {
				if ( $rule['h'] && PatternMatcher::host_matches( $host, $rule['h'] ) ) {
					return array(
						'rule'     => $rule,
						'fragment' => $src,
					);
				}
				foreach ( $rule['r'] as $regex ) {
					if ( 1 === preg_match( '~' . $regex . '~i', $src, $m ) ) {
						return array(
							'rule'     => $rule,
							'fragment' => $m[0],
						);
					}
				}
			} elseif ( '' !== $inline ) {
				foreach ( $rule['i'] as $regex ) {
					if ( 1 === preg_match( '~' . $regex . '~i', $inline, $m ) ) {
						return array(
							'rule'     => $rule,
							'fragment' => $m[0],
						);
					}
				}
			}
		}
		return null;
	}

	/**
	 * A tracking pixel: 0 or 1 pixel wide/high, or hidden by an inline style.
	 *
	 * @param array $attrs Attributes from HtmlAttributes::parse().
	 * @return bool
	 */
	public static function is_pixel( array $attrs ) {
		foreach ( array( 'width', 'height' ) as $name ) {
			if ( isset( $attrs[ $name ] ) && in_array( trim( (string) $attrs[ $name ]['value'] ), array( '0', '1', '1px', '0px' ), true ) ) {
				return true;
			}
		}
		return isset( $attrs['style'] ) && (bool) preg_match( '/display\s*:\s*none|visibility\s*:\s*hidden|(?:width|height)\s*:\s*[01]px/i', (string) $attrs['style']['value'] );
	}

	/**
	 * Exclusion lines to matchers: "/regex/" entries are compiled case-insensitively (unsafe ones dropped), the rest
	 * are substrings.
	 *
	 * @param string[] $lines Lines.
	 * @return array[] Each [type, needle].
	 */
	public static function compile_exclusions( array $lines ) {
		$out = array();
		foreach ( $lines as $line ) {
			$line = trim( (string) $line );
			if ( strlen( $line ) > 2 && '/' === $line[0] && '/' === substr( $line, -1 ) ) {
				$regex = substr( $line, 1, -1 );
				if ( PatternMatcher::valid_regex( $regex ) ) {
					$out[] = array( 'regex', '~' . $regex . '~i' );
				}
			} elseif ( '' !== $line ) {
				$out[] = array( 'text', $line );
			}
		}
		return $out;
	}

	/**
	 * Is the element on the exclusion list?
	 *
	 * @param string  $tag        Element markup.
	 * @param array[] $exclusions From compile_exclusions().
	 * @return bool
	 */
	public static function excluded( $tag, array $exclusions ) {
		foreach ( $exclusions as $entry ) {
			if ( 'regex' === $entry[0] ? 1 === preg_match( $entry[1], $tag ) : false !== stripos( $tag, $entry[1] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Short sample of what matched, for the observe report. Cut by characters, never in the middle of one.
	 *
	 * @param string $text Matched fragment.
	 * @return string
	 */
	public static function sample( $text ) {
		$text = trim( (string) preg_replace( '/\s+/u', ' ', (string) $text ) );
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $text, 0, 160 );
		}
		return strlen( $text ) > 160 ? substr( $text, 0, 160 ) : $text;
	}
}
