<?php
/**
 * Attribute parsing and editing for the blocker. Works on the raw attribute text of one opening tag and keeps byte
 * spans, so edits never reformat what they do not touch. Parsing is bounded: a tag with more than MAX_ATTRIBUTES
 * attributes, or more than MAX_RAW bytes of attributes, is reported as unparseable (null) and left alone by the caller.
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Blocker;

final class HtmlAttributes {

	const MAX_ATTRIBUTES = 1000;
	const MAX_RAW        = 65536;

	/**
	 * Attributes of a tag in order. Unquoted values may contain "=" (the browser keeps them whole).
	 *
	 * @param string $raw Attribute text (what follows the tag name, without the closing ">").
	 * @return array<string,array{name:string,value:?string,start:int,end:int}>|null Keyed by lower-case name (first one
	 *         wins); null when the tag is too large to parse safely.
	 */
	public static function parse( $raw ) {
		$length = strlen( $raw );
		if ( $length > self::MAX_RAW ) {
			return null;
		}
		$out   = array();
		$count = 0;
		$pos   = 0;
		$re    = '~\G[\s/]*+([^\s"\'<>/=]++)(?:\s*+=\s*+(?:"([^"]*+)"|\'([^\']*+)\'|([^\s"\'<>`]++)))?~';
		while ( $pos < $length ) {
			if ( ! preg_match( $re, $raw, $m, PREG_OFFSET_CAPTURE, $pos ) ) {
				++$pos; // A stray quote or "=": skip it.
				continue;
			}
			if ( ++$count > self::MAX_ATTRIBUTES ) {
				return null;
			}
			$end  = $m[0][1] + strlen( $m[0][0] );
			$name = strtolower( $m[1][0] );
			if ( ! isset( $out[ $name ] ) ) {
				$value = null;
				foreach ( array( 2, 3, 4 ) as $i ) {
					if ( isset( $m[ $i ] ) && -1 !== $m[ $i ][1] ) {
						$value = $m[ $i ][0];
						break;
					}
				}
				$out[ $name ] = array(
					'name'  => $m[1][0],
					'value' => $value,
					'start' => $m[1][1],
					'end'   => $end,
				);
			}
			$pos = max( $end, $pos + 1 );
		}
		return $out;
	}

	/**
	 * Apply span edits to a string. Spans must not overlap; a removed span also takes one leading space.
	 *
	 * @param string  $text  Text.
	 * @param array[] $edits Each [start, end, replacement].
	 * @return string
	 */
	public static function apply( $text, array $edits ) {
		usort(
			$edits,
			static function ( $a, $b ) {
				return $b[0] - $a[0];
			}
		);
		foreach ( $edits as $edit ) {
			list( $start, $end, $with ) = $edit;
			if ( '' === $with && $start > 0 && ' ' === $text[ $start - 1 ] ) {
				--$start;
			}
			$text = substr( $text, 0, $start ) . $with . substr( $text, $end );
		}
		return $text;
	}

	/**
	 * Edit that renames an attribute (the value stays).
	 *
	 * @param array  $attr Entry from parse().
	 * @param string $to   New name.
	 * @return array [start, end, replacement]
	 */
	public static function rename( array $attr, $to ) {
		return array( $attr['start'], $attr['start'] + strlen( $attr['name'] ), $to );
	}

	/**
	 * Edit that removes an attribute.
	 *
	 * @param array $attr Entry from parse().
	 * @return array [start, end, replacement]
	 */
	public static function remove( array $attr ) {
		return array( $attr['start'], $attr['end'], '' );
	}
}
