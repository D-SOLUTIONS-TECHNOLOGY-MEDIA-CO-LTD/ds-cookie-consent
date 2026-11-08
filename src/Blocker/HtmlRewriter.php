<?php
/**
 * Rewrites the markup of tracking scripts, iframes and pixels into the blocked form (contract: scripts become
 * type="text/plain" with data-dscc-category / data-dscc-service and data-dscc-src; iframes keep their address in
 * data-dscc-src and get a placeholder; pixels move src to data-dscc-src). The browser script puts them back when the
 * visitor consents. Pure string work: no WordPress functions, no DOM parser (a parser would reformat the page).
 *
 * The page is scanned element by element: closing tags are found with a literal search (no regex repetition over
 * page content), so one huge script cannot hit PCRE limits. The rewrite never depends on the visitor, so the result is
 * identical for everyone and safe to page-cache.
 *
 * Tracker images and iframes inside <noscript> are removed (a visitor without JavaScript cannot consent); every other
 * noscript content stays. Comments, <template>, <style> and <textarea> content is never touched.
 *
 * Known limits: <link rel=preload|preconnect>, <embed>, <object> and trackers added by scripts that are not blocked
 * (document.write) are not covered.
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Blocker;

final class HtmlRewriter {

	/** Script types that are JavaScript; every other type (JSON-LD, templates, import maps...) is left alone. */
	const JS_TYPES = array( '', 'text/javascript', 'application/javascript', 'application/x-javascript', 'text/ecmascript', 'application/ecmascript', 'module' );

	/** Content that WP Rocket's "defer inline jQuery" wraps even in a text/plain script; the marker keeps that off. */
	const INLINE_MARKER = '/*data-dscc*/';

	/** Attributes that can hold the address of an iframe, in order of preference. */
	const FRAME_SOURCES = array( 'src', 'data-src', 'data-lazy-src' );

	/** One attribute list: quoted values may contain ">". Possessive, so it never backtracks. */
	const ATTRS = '(?:"[^"]*+"|\'[^\']*+\'|[^\'">]++)*+';

	/**
	 * Find blocked elements and (optionally) rewrite them.
	 *
	 * Options: rewrite (bool, false = observe only), exclusions (string[], plain substring or "/regex/"),
	 * own_url (string, plugin URL prefix that is never touched), skip (callable(string $kind, array $attrs, string $src): bool),
	 * placeholder (callable(array $info): string, HTML put before a blocked iframe).
	 *
	 * @param string  $html  Page HTML.
	 * @param array[] $rules Rules {s slug, c category, h hosts[], r src regexes[], i inline regexes[]}.
	 * @param array   $opts  Options.
	 * @return array{html:string,matches:array[],error:bool,unparsed:int} Matches: {service, category, kind, sample}.
	 *         `error`: a PCRE failure, the page is returned unchanged. `unparsed`: tags too large to parse, left as they were.
	 */
	public static function process( $html, array $rules, array $opts = array() ) {
		$result = array(
			'html'     => $html,
			'matches'  => array(),
			'error'    => false,
			'unparsed' => 0,
		);
		if ( ! $rules || '' === $html ) {
			return $result;
		}
		$ctx = array(
			'rules'       => $rules,
			'rewrite'     => ! isset( $opts['rewrite'] ) || ! empty( $opts['rewrite'] ),
			'exclusions'  => RuleMatcher::compile_exclusions( isset( $opts['exclusions'] ) ? (array) $opts['exclusions'] : array() ),
			'own_url'     => isset( $opts['own_url'] ) ? (string) $opts['own_url'] : '',
			'skip'        => isset( $opts['skip'] ) && is_callable( $opts['skip'] ) ? $opts['skip'] : null,
			'placeholder' => isset( $opts['placeholder'] ) && is_callable( $opts['placeholder'] ) ? $opts['placeholder'] : null,
			'matches'     => array(),
			'unparsed'    => 0,
		);

		$out = self::scan( $html, $ctx );
		if ( null === $out ) {
			$result['error'] = true; // A PCRE failure: leave the page exactly as it was.
			return $result;
		}
		$result['html']     = $ctx['rewrite'] ? $out : $html;
		$result['matches']  = $ctx['matches'];
		$result['unparsed'] = $ctx['unparsed'];
		return $result;
	}

	/**
	 * Walk the page and replace the elements that need it.
	 *
	 * @param string $html Page.
	 * @param array  $ctx  Context (matches and counters are updated).
	 * @return string|null New page, null on a PCRE failure.
	 */
	private static function scan( $html, array &$ctx ) {
		$length = strlen( $html );
		$find   = '~<(?:!--|(noscript|template|style|script|textarea|iframe|img)(?=[\s/>]))~i';
		$pos    = 0;
		$last   = 0;
		$parts  = array();

		while ( $pos < $length ) {
			$found = preg_match( $find, $html, $m, PREG_OFFSET_CAPTURE, $pos );
			if ( false === $found ) {
				return null;
			}
			if ( ! $found ) {
				break;
			}
			$start = $m[0][1];
			if ( ! isset( $m[1] ) || '' === $m[1][0] ) { // A comment.
				$close = strpos( $html, '-->', $start + 4 );
				if ( false === $close ) {
					break; // An unclosed comment swallows the rest of the page in every browser.
				}
				$pos = $close + 3;
				continue;
			}
			$name = strtolower( $m[1][0] );
			$open = preg_match( '~\G(' . self::ATTRS . ')>~', $html, $t, 0, $start + 1 + strlen( $name ) );
			if ( false === $open ) {
				return null;
			}
			if ( ! $open ) {
				$pos = $start + 1; // No end to this tag: it is text.
				continue;
			}
			$tag_end = $start + 1 + strlen( $name ) + strlen( $t[0] );
			$raw     = $t[1];

			if ( 'iframe' === $name || 'img' === $name ) {
				$new = self::embed( substr( $html, $start, $tag_end - $start ), $raw, $name, $ctx );
				if ( null !== $new ) {
					$parts[] = substr( $html, $last, $start - $last );
					$parts[] = $new;
					$last    = $tag_end;
				}
				$pos = $tag_end;
				continue;
			}

			$closing = self::closing_tag( $html, $name, $tag_end );
			if ( null === $closing ) {
				break; // Unclosed script/style/...: the rest of the page is its content.
			}
			list( $body_end, $end ) = $closing;
			$new                    = null;
			if ( 'script' === $name ) {
				$new = self::script( $raw, substr( $html, $tag_end, $body_end - $tag_end ), substr( $html, $body_end, $end - $body_end ), $ctx );
			} elseif ( 'noscript' === $name ) {
				$new = self::noscript( substr( $html, $start, $tag_end - $start ), substr( $html, $tag_end, $body_end - $tag_end ), substr( $html, $body_end, $end - $body_end ), $ctx );
			}
			if ( null !== $new ) {
				$parts[] = substr( $html, $last, $start - $last );
				$parts[] = $new;
				$last    = $end;
			}
			$pos = $end;
		}
		if ( ! $parts ) {
			return $html;
		}
		$parts[] = substr( $html, $last );
		return implode( '', $parts );
	}

	/**
	 * Position of the closing tag of an element: [start of "</name", end after ">"], or null when there is none.
	 *
	 * @param string $html Page.
	 * @param string $name Lower-case tag name.
	 * @param int    $from Offset after the opening tag.
	 * @return int[]|null
	 */
	private static function closing_tag( $html, $name, $from ) {
		// A literal, case-insensitive search: no repetition, so no PCRE limits. stripos() is avoided on purpose:
		// before PHP 8.2 it lower-cases a copy of the whole remaining page on every call (quadratic on big pages).
		if ( 1 !== preg_match( '~</' . preg_quote( $name, '~' ) . '(?=[\s/>])~i', $html, $m, PREG_OFFSET_CAPTURE, $from ) ) {
			return null;
		}
		$c  = $m[0][1];
		$gt = strpos( $html, '>', $c + strlen( $m[0][0] ) );
		return false === $gt ? null : array( $c, $gt + 1 );
	}

	/**
	 * A <script> element.
	 *
	 * @param string $raw     Attribute text of the opening tag.
	 * @param string $body    Content.
	 * @param string $closing Closing tag as written.
	 * @param array  $ctx     Context.
	 * @return string|null Replacement, null to keep the element.
	 */
	private static function script( $raw, $body, $closing, array &$ctx ) {
		$attrs = HtmlAttributes::parse( $raw );
		if ( null === $attrs ) {
			++$ctx['unparsed'];
			return null;
		}
		if ( isset( $attrs['data-dscc-ignore'] ) || isset( $attrs['data-dscc-category'] ) ) {
			return null;
		}
		$type = isset( $attrs['type'] ) ? strtolower( trim( (string) $attrs['type']['value'] ) ) : '';
		if ( ! in_array( $type, self::JS_TYPES, true ) ) {
			return null;
		}
		$src = isset( $attrs['src'] ) ? html_entity_decode( (string) $attrs['src']['value'], ENT_QUOTES ) : '';
		if ( isset( $attrs['src'] ) && '' === trim( $src ) ) {
			return null;
		}
		if ( '' !== $src && '' !== $ctx['own_url'] && 0 === strpos( $src, $ctx['own_url'] ) ) {
			return null;
		}
		$hit = '' !== $src ? RuleMatcher::match( $ctx['rules'], $src, '' ) : RuleMatcher::match( $ctx['rules'], '', $body );
		if ( null === $hit || self::vetoed( '<script' . $raw . '>' . $body, 'script', $attrs, $src, $ctx ) ) {
			return null;
		}
		$rule             = $hit['rule'];
		$ctx['matches'][] = array(
			'service'  => $rule['s'],
			'category' => $rule['c'],
			'kind'     => '' !== $src ? 'script' : 'inline',
			'sample'   => RuleMatcher::sample( $hit['fragment'] ),
		);
		if ( ! $ctx['rewrite'] ) {
			return null;
		}

		$edits = array();
		if ( isset( $attrs['type'] ) ) {
			$edits[] = HtmlAttributes::remove( $attrs['type'] );
		}
		if ( isset( $attrs['src'] ) ) {
			$edits[] = HtmlAttributes::rename( $attrs['src'], 'data-dscc-src' );
		}
		$extra = ' type="text/plain" data-dscc-category="' . self::esc( $rule['c'] ) . '" data-dscc-service="' . self::esc( $rule['s'] ) . '"';
		if ( isset( $attrs['type'] ) ) {
			$extra .= ' data-dscc-type="' . self::esc( trim( (string) $attrs['type']['value'] ) ) . '"';
		}
		$extra .= ' data-no-optimize="1"';
		if ( '' === $src && preg_match( '/jQuery|\$[.(]/', $body ) ) {
			$body = self::INLINE_MARKER . $body;
		}
		return '<script' . $extra . HtmlAttributes::apply( $raw, $edits ) . '>' . $body . $closing;
	}

	/**
	 * A <noscript> element: tracker images and iframes inside are removed (no-JS visitors cannot give consent).
	 *
	 * @param string $open    Opening tag.
	 * @param string $content Content.
	 * @param string $closing Closing tag.
	 * @param array  $ctx     Context.
	 * @return string|null
	 */
	private static function noscript( $open, $content, $closing, array &$ctx ) {
		if ( false === stripos( $content, '<img' ) && false === stripos( $content, '<iframe' ) ) {
			return null;
		}
		$changed = false;
		$clean   = preg_replace_callback(
			'~<(iframe|img)(?=[\s/>])(' . self::ATTRS . ')>(\s*+</iframe\s*+>)?~i',
			static function ( $m ) use ( &$ctx, &$changed ) {
				$kind  = strtolower( $m[1] );
				$attrs = HtmlAttributes::parse( $m[2] );
				if ( null === $attrs ) {
					++$ctx['unparsed'];
					return $m[0];
				}
				$src = isset( $attrs['src'] ) ? html_entity_decode( (string) $attrs['src']['value'], ENT_QUOTES ) : '';
				if ( isset( $attrs['data-dscc-ignore'] ) || '' === trim( $src ) ) {
					return $m[0];
				}
				$hit = RuleMatcher::match( $ctx['rules'], $src, '' );
				if ( null === $hit || RuleMatcher::excluded( $m[0], $ctx['exclusions'] ) || self::vetoed( $m[0], $kind, $attrs, $src, $ctx ) ) {
					return $m[0];
				}
				$ctx['matches'][] = array(
					'service'  => $hit['rule']['s'],
					'category' => $hit['rule']['c'],
					'kind'     => 'noscript',
					'sample'   => RuleMatcher::sample( $hit['fragment'] ),
				);
				$changed          = true;
				return $ctx['rewrite'] ? '' : $m[0];
			},
			$content
		);
		if ( null === $clean ) {
			++$ctx['unparsed'];
			return null;
		}
		return $changed && $ctx['rewrite'] ? $open . $clean . $closing : null;
	}

	/**
	 * An <iframe> or <img> element (opening tag only).
	 *
	 * @param string $tag  Whole tag.
	 * @param string $raw  Attribute text.
	 * @param string $kind iframe|img.
	 * @param array  $ctx  Context.
	 * @return string|null Replacement, null to keep the tag.
	 */
	private static function embed( $tag, $raw, $kind, array &$ctx ) {
		$attrs = HtmlAttributes::parse( $raw );
		if ( null === $attrs ) {
			++$ctx['unparsed'];
			return null;
		}
		if ( isset( $attrs['data-dscc-ignore'] ) || isset( $attrs['data-dscc-category'] ) || isset( $attrs['srcdoc'] ) ) {
			return null;
		}
		$source = null;
		$src    = '';
		foreach ( 'iframe' === $kind ? self::FRAME_SOURCES : array( 'src' ) as $name ) {
			if ( ! isset( $attrs[ $name ] ) ) {
				continue;
			}
			$value = html_entity_decode( (string) $attrs[ $name ]['value'], ENT_QUOTES );
			if ( '' === trim( $value ) || 0 === stripos( ltrim( $value ), 'data:' ) || 0 === stripos( ltrim( $value ), 'about:' ) ) {
				continue;
			}
			$source = $attrs[ $name ];
			$src    = $value;
			break;
		}
		if ( null === $source || ( 'img' === $kind && ! RuleMatcher::is_pixel( $attrs ) ) ) {
			return null;
		}
		$hit = RuleMatcher::match( $ctx['rules'], $src, '' );
		if ( null === $hit || self::vetoed( $tag, $kind, $attrs, $src, $ctx ) ) {
			return null;
		}
		$rule             = $hit['rule'];
		$ctx['matches'][] = array(
			'service'  => $rule['s'],
			'category' => $rule['c'],
			'kind'     => $kind,
			'sample'   => RuleMatcher::sample( $hit['fragment'] ),
		);
		if ( ! $ctx['rewrite'] ) {
			return null;
		}

		$new   = HtmlAttributes::apply( $raw, array( HtmlAttributes::rename( $source, 'data-dscc-src' ) ) );
		$extra = ' data-dscc-category="' . self::esc( $rule['c'] ) . '" data-dscc-service="' . self::esc( $rule['s'] ) . '"';
		$out   = '';
		if ( 'iframe' === $kind ) {
			$extra .= ' hidden';
			if ( $ctx['placeholder'] ) {
				$out = (string) call_user_func(
					$ctx['placeholder'],
					array(
						'service'  => $rule['s'],
						'category' => $rule['c'],
						'src'      => $src,
						'width'    => isset( $attrs['width'] ) ? (string) $attrs['width']['value'] : '',
						'height'   => isset( $attrs['height'] ) ? (string) $attrs['height']['value'] : '',
					)
				);
			}
		}
		return $out . '<' . $kind . $extra . $new . '>';
	}

	/**
	 * Exclusion list or integration veto.
	 *
	 * @param string $markup Element markup (for the exclusion list).
	 * @param string $kind   script|iframe|img.
	 * @param array  $attrs  Attributes.
	 * @param string $src    URL.
	 * @param array  $ctx    Context.
	 * @return bool
	 */
	private static function vetoed( $markup, $kind, array $attrs, $src, array $ctx ) {
		if ( RuleMatcher::excluded( $markup, $ctx['exclusions'] ) ) {
			return true;
		}
		return $ctx['skip'] && (bool) call_user_func( $ctx['skip'], $kind, $attrs, $src );
	}

	/**
	 * Attribute-safe text.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private static function esc( $value ) {
		return htmlspecialchars( (string) $value, ENT_QUOTES );
	}
}
