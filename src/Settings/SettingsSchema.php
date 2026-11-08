<?php
/**
 * Single source of truth for dscc_settings: defaults and group-level sanitising.
 * Field definitions live in SettingsFields, per-type handling in FieldSanitizer.
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Settings;

final class SettingsSchema {

	const OPTION = 'dscc_settings';

	/**
	 * Consent Mode regions: at most this many entries and this many characters once joined with commas.
	 * They sit in the inline head script, which has a hard 1500 byte budget; a region that did not fit would get
	 * no default and count as granted, so the schema refuses extras instead of letting the head drop them.
	 */
	const MAX_REGIONS      = 40;
	const MAX_REGION_CHARS = 140;
	/** Longest cookie name that still fits the head script budget together with the regions above. */
	const MAX_COOKIE_NAME = 32;

	/**
	 * Field definitions grouped by settings group.
	 *
	 * @return array<string,array<string,array>>
	 */
	public static function fields() {
		return SettingsFields::all();
	}

	/**
	 * Default settings. The policy version defaults to the install month.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function defaults() {
		$out = array();
		foreach ( self::fields() as $group => $fields ) {
			foreach ( $fields as $key => $def ) {
				$out[ $group ][ $key ] = $def[1];
			}
		}
		$out['advanced']['policy_version'] = gmdate( 'Y-m' );
		return apply_filters( 'dscc_settings_defaults', $out );
	}

	/**
	 * Sanitise one settings group. Fields missing from $input keep their current value,
	 * except booleans, which are checkboxes (absent means off) because forms post a whole group.
	 *
	 * @param string $group   Group key.
	 * @param array  $input   Raw, unslashed input for the group.
	 * @param array  $current Current stored values for the group.
	 * @return array
	 */
	public static function sanitize_group( $group, array $input, array $current ) {
		$fields = self::fields();
		if ( ! isset( $fields[ $group ] ) ) {
			return $current;
		}
		$defaults = self::defaults();
		$out      = array();
		foreach ( $fields[ $group ] as $key => $def ) {
			$fallback = array_key_exists( $key, $current ) ? $current[ $key ] : $defaults[ $group ][ $key ];
			if ( 'bool' === $def[0] ) {
				$out[ $key ] = array_key_exists( $key, $input ) ? FieldSanitizer::to_bool( $input[ $key ] ) : false;
				continue;
			}
			if ( ! array_key_exists( $key, $input ) ) {
				$out[ $key ] = $fallback;
				continue;
			}
			$out[ $key ] = FieldSanitizer::value( $key, $def, $input[ $key ], $fallback, $defaults[ $group ][ $key ] );
		}
		return $out;
	}

	/**
	 * Sanitise a full settings payload (only groups present in $input are touched).
	 *
	 * @param array $input   Raw input keyed by group.
	 * @param array $current Current full settings.
	 * @return array
	 */
	public static function sanitize( array $input, array $current ) {
		$out = $current;
		foreach ( array_keys( self::fields() ) as $group ) {
			if ( isset( $input[ $group ] ) && is_array( $input[ $group ] ) ) {
				$out[ $group ] = self::sanitize_group( $group, $input[ $group ], isset( $current[ $group ] ) && is_array( $current[ $group ] ) ? $current[ $group ] : array() );
			}
		}
		return $out;
	}

	/**
	 * Safety net for direct writes of the option (imports, other code): the limits that keep the head script within
	 * its byte budget are enforced here too. Normal saves already went through sanitize_group().
	 *
	 * @param mixed $value New option value.
	 * @return mixed
	 */
	public static function enforce_limits( $value ) {
		if ( ! is_array( $value ) || ! isset( $value['advanced'] ) || ! is_array( $value['advanced'] ) ) {
			return $value;
		}
		if ( isset( $value['advanced']['consent_regions'] ) ) {
			$value['advanced']['consent_regions'] = FieldSanitizer::regions( $value['advanced']['consent_regions'], 'consent_regions' );
		}
		if ( isset( $value['advanced']['cookie_name'] ) ) {
			$old                              = get_option( self::OPTION );
			$previous                         = is_array( $old ) && isset( $old['advanced']['cookie_name'] ) ? $old['advanced']['cookie_name'] : self::defaults()['advanced']['cookie_name'];
			$value['advanced']['cookie_name'] = FieldSanitizer::value( 'cookie_name', array( 'cookie_name', 'dscc_consent' ), $value['advanced']['cookie_name'], $previous, 'dscc_consent' );
		}
		return $value;
	}

	/**
	 * Values refused during sanitising since the last call (field key => reason), then cleared.
	 *
	 * @return array<string,string>
	 */
	public static function take_rejections() {
		return FieldSanitizer::take_rejections();
	}

	/**
	 * Sanitise text overrides for one language: unknown keys dropped, values that equal the default dropped.
	 *
	 * @param string $lang  vi|en.
	 * @param array  $input Raw, unslashed input key => text.
	 * @return array<string,string>
	 */
	public static function sanitize_texts( $lang, array $input ) {
		$defaults = DefaultTexts::all( $lang );
		$out      = array();
		foreach ( $input as $key => $value ) {
			if ( ! is_string( $key ) || ! isset( $defaults[ $key ] ) || ! is_string( $value ) ) {
				continue;
			}
			$value = trim( sanitize_textarea_field( $value ) );
			$value = function_exists( 'mb_substr' ) ? mb_substr( $value, 0, 800 ) : substr( $value, 0, 800 );
			if ( '' === $value || $value === $defaults[ $key ] ) {
				continue;
			}
			$out[ $key ] = $value;
		}
		return $out;
	}

	/**
	 * User-agent fragment check.
	 *
	 * @param string $pattern Pattern without delimiters.
	 * @return bool
	 */
	public static function valid_ua_pattern( $pattern ) {
		return FieldValidators::ua_pattern( $pattern );
	}

	/**
	 * Selector check.
	 *
	 * @param string $selector Selector.
	 * @return bool
	 */
	public static function valid_selector( $selector ) {
		return FieldValidators::selector( $selector );
	}

	/**
	 * Region code check.
	 *
	 * @param string $region Code.
	 * @return bool
	 */
	public static function valid_region( $region ) {
		return FieldValidators::region( $region );
	}
}
