<?php
/**
 * Type-driven sanitising of single settings values (see SettingsFields for the type list).
 * A value that fails validation keeps its previous value; rejections the admin should hear about
 * (custom CSS) are collected and can be read once with take_rejections().
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Settings;

final class FieldSanitizer {

	/**
	 * Field key => reason code, for values refused since the last take_rejections().
	 *
	 * @var array<string,string>
	 */
	private static $rejections = array();

	/**
	 * Return and clear the collected rejections.
	 *
	 * @return array<string,string>
	 */
	public static function take_rejections() {
		$out              = self::$rejections;
		self::$rejections = array();
		return $out;
	}

	/**
	 * Sanitise one field value.
	 *
	 * @param string $key            Field key.
	 * @param array  $def            Field definition.
	 * @param mixed  $value          Raw value.
	 * @param mixed  $fallback       Value to keep when invalid.
	 * @param mixed  $schema_default Schema default.
	 * @return mixed
	 */
	public static function value( $key, array $def, $value, $fallback, $schema_default ) {
		$type  = $def[0];
		$extra = isset( $def[2] ) ? $def[2] : null;

		switch ( $type ) {
			case 'int':
				if ( is_array( $value ) || ! is_numeric( $value ) ) {
					return $fallback;
				}
				return max( $extra[0], min( $extra[1], (int) $value ) );

			case 'enum':
				return is_string( $value ) && in_array( $value, $extra, true ) ? $value : $fallback;

			case 'color':
				$hex = ColorUtils::normalize_hex( $value );
				return null === $hex ? $fallback : $hex;

			case 'color_opt':
				if ( is_string( $value ) && '' === trim( $value ) ) {
					return '';
				}
				$hex = ColorUtils::normalize_hex( $value );
				return null === $hex ? $fallback : $hex;

			case 'text':
				if ( ! is_string( $value ) ) {
					return $fallback;
				}
				$value = trim( sanitize_text_field( $value ) );
				return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, (int) $extra ) : substr( $value, 0, (int) $extra );

			case 'url':
				return self::url( $value, $fallback );

			case 'email':
				if ( ! is_string( $value ) ) {
					return $fallback;
				}
				$value = trim( $value );
				if ( '' === $value ) {
					return '';
				}
				$clean = sanitize_email( $value );
				return '' !== $clean && is_email( $clean ) ? $clean : $fallback;

			case 'https_url':
				return self::https_url( $key, $value, $fallback );

			case 'font':
				return self::font( $value, $fallback );

			case 'radius':
				if ( is_string( $value ) && ( '' === $value || 'pill' === $value ) ) {
					return $value;
				}
				return is_numeric( $value ) ? (string) max( 0, min( 40, (int) $value ) ) : $fallback;

			case 'css':
				return self::css( $key, $value, $fallback );

			case 'cookie_name':
				return self::cookie_name( $key, $value, $fallback, $schema_default );

			case 'cookie_domain':
				$value = is_string( $value ) ? strtolower( trim( $value ) ) : '';
				return preg_match( '/^\.?[a-z0-9][a-z0-9.\-]{0,251}$/', $value ) ? $value : '';

			case 'version':
				return is_string( $value ) && preg_match( '/^[A-Za-z0-9._\-]{1,20}$/', trim( $value ) ) ? trim( $value ) : $fallback;

			case 'ua_list':
				return self::lines( $value, 30, array( FieldValidators::class, 'ua_pattern' ) );

			case 'selector_list':
				return self::lines( $value, 20, array( FieldValidators::class, 'selector' ) );

			case 'region_list':
				return self::regions( $value, $key );

			case 'cookie_pattern_list':
				return self::lines( $value, 30, array( FieldValidators::class, 'cookie_pattern' ) );

			case 'exclusion_list':
				return self::lines( $value, 50, array( FieldValidators::class, 'exclusion' ) );
		}
		return $fallback;
	}

	/**
	 * Checkbox semantics.
	 *
	 * @param mixed $value Raw value.
	 * @return bool
	 */
	public static function to_bool( $value ) {
		if ( is_string( $value ) ) {
			return ! in_array( strtolower( trim( $value ) ), array( '', '0', 'false', 'off', 'no' ), true );
		}
		return ! empty( $value );
	}

	/**
	 * Custom CSS: a refused stylesheet keeps the previous value and is reported to the admin.
	 *
	 * @param string $key      Field key.
	 * @param mixed  $value    Raw CSS.
	 * @param mixed  $fallback Previous value.
	 * @return mixed
	 */
	private static function css( $key, $value, $fallback ) {
		if ( ! is_string( $value ) ) {
			return $fallback;
		}
		$checked = CssSanitizer::check( $value );
		if ( '' !== $checked['error'] ) {
			self::$rejections[ $key ] = $checked['error'];
			return $fallback;
		}
		return $checked['css'];
	}

	/**
	 * Font stack: plain family names only. Quotes, semicolons, braces or any other punctuation make
	 * the whole value invalid (the previous value is kept) instead of being silently rewritten.
	 *
	 * @param mixed $value    Raw value.
	 * @param mixed $fallback Previous value.
	 * @return string
	 */
	private static function font( $value, $fallback ) {
		if ( ! is_string( $value ) ) {
			return $fallback;
		}
		$value = trim( $value );
		if ( '' === $value ) {
			return 'inherit';
		}
		if ( strlen( $value ) > 100 || ! preg_match( '/^[A-Za-z0-9 ,\-_]+$/', $value ) ) {
			return $fallback;
		}
		return $value;
	}

	/**
	 * An absolute https URL, or empty. Anything else is refused: the previous value is kept and reported.
	 *
	 * @param string $key      Field key.
	 * @param mixed  $value    Raw URL.
	 * @param mixed  $fallback Previous value.
	 * @return string
	 */
	private static function https_url( $key, $value, $fallback ) {
		if ( ! is_string( $value ) ) {
			return $fallback;
		}
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}
		if ( ! preg_match( '#^https://[^\s/]+#i', $value ) ) {
			self::$rejections[ $key ] = 'not_https';
			return $fallback;
		}
		return esc_url_raw( $value, array( 'https' ) );
	}

	/**
	 * Accept http(s) URLs or site-relative paths; anything else becomes empty.
	 *
	 * @param mixed $value    Raw URL.
	 * @param mixed $fallback Fallback.
	 * @return string
	 */
	private static function url( $value, $fallback ) {
		if ( ! is_string( $value ) ) {
			return $fallback;
		}
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}
		if ( '/' === $value[0] && ( ! isset( $value[1] ) || '/' !== $value[1] ) ) {
			return esc_url_raw( $value );
		}
		if ( preg_match( '#^https?://#i', $value ) ) {
			return esc_url_raw( $value );
		}
		return '';
	}

	/**
	 * Cookie name: letters, digits, _ and -, up to SettingsSchema::MAX_COOKIE_NAME. An invalid new value never resets
	 * the stored one (that would ask every returning visitor again): the previous value is kept and reported.
	 *
	 * @param string $key            Field key.
	 * @param mixed  $value          Raw value.
	 * @param mixed  $fallback       Previous value.
	 * @param string $schema_default Default name.
	 * @return string
	 */
	private static function cookie_name( $key, $value, $fallback, $schema_default ) {
		$value = is_string( $value ) ? trim( $value ) : '';
		if ( preg_match( '/^[A-Za-z0-9_\-]{1,' . SettingsSchema::MAX_COOKIE_NAME . '}$/', $value ) ) {
			return $value;
		}
		self::$rejections[ $key ] = strlen( $value ) > SettingsSchema::MAX_COOKIE_NAME ? 'too_long' : 'invalid';
		return is_string( $fallback ) && preg_match( '/^[A-Za-z0-9_\-]{1,64}$/', $fallback ) ? $fallback : $schema_default;
	}

	/**
	 * Consent Mode regions, limited by entry count and by joined length (see SettingsSchema::MAX_REGIONS).
	 * Entries beyond either limit are dropped and reported as a 'too_many' rejection.
	 *
	 * @param mixed  $value Raw value.
	 * @param string $key   Field key.
	 * @return string[]
	 */
	public static function regions( $value, $key ) {
		$out    = array();
		$length = 0;
		foreach ( self::lines( $value, SettingsSchema::MAX_REGIONS, array( FieldValidators::class, 'region' ), $key ) as $region ) {
			$length += strlen( $region ) + ( $out ? 1 : 0 );
			if ( $length > SettingsSchema::MAX_REGION_CHARS ) {
				self::$rejections[ $key ] = 'too_long';
				break;
			}
			$out[] = $region;
		}
		return $out;
	}

	/**
	 * Turn a textarea string or array into a deduplicated list of validated lines.
	 *
	 * @param mixed    $value Raw value.
	 * @param int      $max   Maximum entries.
	 * @param callable $check Validator for one entry.
	 * @param string   $key   Field key; when set, valid entries beyond $max are reported as a 'too_many' rejection.
	 * @return string[]
	 */
	private static function lines( $value, $max, $check, $key = '' ) {
		if ( is_string( $value ) ) {
			$value = preg_split( '/\r\n|\r|\n/', $value );
		}
		if ( ! is_array( $value ) ) {
			return array();
		}
		$out = array();
		foreach ( $value as $line ) {
			if ( ! is_string( $line ) ) {
				continue;
			}
			$line = trim( $line );
			if ( '' === $line || ! call_user_func( $check, $line ) ) {
				continue;
			}
			if ( count( $out ) >= $max && ! isset( $out[ $line ] ) ) {
				if ( '' === $key ) {
					break;
				}
				self::$rejections[ $key ] = 'too_many';
				break;
			}
			$out[ $line ] = $line;
		}
		return array_values( $out );
	}
}
