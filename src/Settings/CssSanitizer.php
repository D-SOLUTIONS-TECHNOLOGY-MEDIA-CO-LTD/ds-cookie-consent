<?php
/**
 * Sanitises the admin supplied custom CSS and scopes it under #dscc.
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Settings;

final class CssSanitizer {

	const MAX_LENGTH = 20000;

	/**
	 * Sanitised CSS, or an empty string when the stylesheet is refused (see check()).
	 *
	 * @param string $css Raw CSS.
	 * @return string
	 */
	public static function sanitize( $css ) {
		$result = self::check( $css );
		return $result['css'];
	}

	/**
	 * Remove constructs that could break out of the style element or load active/external content,
	 * and refuse stylesheets that are too long or have unbalanced braces (they could swallow the page styles).
	 *
	 * @param string $css Raw CSS.
	 * @return array {css: string, error: string} error is '', 'too_long' or 'unbalanced'.
	 */
	public static function check( $css ) {
		if ( ! is_string( $css ) ) {
			return array(
				'css'   => '',
				'error' => '',
			);
		}
		if ( strlen( $css ) > self::MAX_LENGTH ) {
			return array(
				'css'   => '',
				'error' => 'too_long',
			);
		}
		// Control characters and NUL bytes.
		$css = (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $css );
		// Strip HTML angle brackets entirely: no tags can ever be injected through the style element.
		$css = str_replace( array( '<', '>' ), '', $css );
		// Strip comments first so they cannot be used to split blocked tokens.
		$css = (string) preg_replace( '#/\*.*?\*/#s', '', $css );

		$patterns = array(
			'/@import[^;{}]*;?/i',
			'/@charset[^;{}]*;?/i',
			'/@namespace[^;{}]*;?/i',
			'/url\s*\(\s*[\'"]?\s*(javascript|vbscript)\s*:[^)]*\)?/i',
			'/expression\s*\(/i',
			'/behavior\s*:[^;}]*/i',
			'/-moz-binding\s*:[^;}]*/i',
			'/@font-face\s*\{[^}]*\}/i',
		);
		foreach ( $patterns as $pattern ) {
			$css = (string) preg_replace( $pattern, '', $css );
		}

		if ( substr_count( $css, '{' ) !== substr_count( $css, '}' ) ) {
			return array(
				'css'   => '',
				'error' => 'unbalanced',
			);
		}
		return array(
			'css'   => trim( $css ),
			'error' => '',
		);
	}

	/**
	 * Prefix every selector with #dscc so custom CSS cannot restyle the theme.
	 * Plain declarations at the top level (outside any rule) are wrapped into one #dscc rule.
	 *
	 * @param string $css Sanitised CSS.
	 * @return string
	 */
	public static function scope( $css ) {
		$css = trim( (string) $css );
		if ( '' === $css ) {
			return '';
		}
		return self::scope_blocks( $css );
	}

	/**
	 * Walk the blocks of a fragment and scope their selectors. Text before a selector that ends with ";"
	 * is a declaration (or a statement at-rule, which is dropped); declarations are collected into a
	 * single "#dscc{...}" rule placed first.
	 *
	 * @param string $css CSS fragment.
	 * @return string
	 */
	private static function scope_blocks( $css ) {
		$out   = '';
		$decls = '';
		$len   = strlen( $css );
		$pos   = 0;
		while ( $pos < $len ) {
			$open = strpos( $css, '{', $pos );
			if ( false === $open ) {
				$decls .= self::declarations( substr( $css, $pos ) );
				break;
			}
			$before  = substr( $css, $pos, $open - $pos );
			$cut     = strrpos( $before, ';' );
			$prelude = trim( false === $cut ? $before : substr( $before, $cut + 1 ) );
			if ( false !== $cut ) {
				$decls .= self::declarations( substr( $before, 0, $cut + 1 ) );
			}
			$depth = 1;
			$i     = $open + 1;
			while ( $i < $len && $depth > 0 ) {
				if ( '{' === $css[ $i ] ) {
					++$depth;
				} elseif ( '}' === $css[ $i ] ) {
					--$depth;
				}
				++$i;
			}
			$inner = substr( $css, $open + 1, $i - $open - 2 );
			$pos   = $i;

			if ( '' === $prelude ) {
				continue;
			}
			if ( '@' === $prelude[0] ) {
				if ( preg_match( '/^@(media|supports|container|layer)\b/i', $prelude ) ) {
					$out .= $prelude . '{' . self::scope_blocks( $inner ) . '}';
				} elseif ( preg_match( '/^@(-webkit-)?keyframes\b/i', $prelude ) ) {
					$out .= $prelude . '{' . $inner . '}';
				}
				// Any other at-rule is dropped.
				continue;
			}
			$out .= self::scope_selector_list( $prelude ) . '{' . $inner . '}';
		}
		return '' === trim( $decls ) ? $out : '#dscc{' . $decls . '}' . $out;
	}

	/**
	 * Keep the plain declarations of a ";"-separated chunk; statement at-rules (such as "@layer a, b;") are dropped.
	 *
	 * @param string $chunk Text made of declarations.
	 * @return string
	 */
	private static function declarations( $chunk ) {
		$out = '';
		foreach ( explode( ';', $chunk ) as $part ) {
			$part = trim( $part );
			if ( '' === $part || '@' === $part[0] || ! preg_match( '/^-{0,2}[a-zA-Z][a-zA-Z0-9\-]*\s*:/', $part ) ) {
				continue;
			}
			$out .= $part . ';';
		}
		return $out;
	}

	/**
	 * Prefix each comma separated selector (ignoring commas inside parentheses).
	 *
	 * @param string $selectors Selector list.
	 * @return string
	 */
	private static function scope_selector_list( $selectors ) {
		$parts = array();
		$buf   = '';
		$depth = 0;
		$len   = strlen( $selectors );
		for ( $i = 0; $i < $len; $i++ ) {
			$ch = $selectors[ $i ];
			if ( '(' === $ch ) {
				++$depth;
			} elseif ( ')' === $ch ) {
				--$depth;
			}
			if ( ',' === $ch && 0 === $depth ) {
				$parts[] = $buf;
				$buf     = '';
				continue;
			}
			$buf .= $ch;
		}
		$parts[] = $buf;

		$scoped = array();
		foreach ( $parts as $part ) {
			$part = trim( $part );
			if ( '' === $part ) {
				continue;
			}
			$scoped[] = 0 === strpos( $part, '#dscc' ) ? $part : '#dscc ' . $part;
		}
		return implode( ',', $scoped );
	}
}
