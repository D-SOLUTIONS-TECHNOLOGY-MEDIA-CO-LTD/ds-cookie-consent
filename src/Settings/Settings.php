<?php
/**
 * Runtime accessor for dscc_settings and the dscc_texts_{lang} overrides.
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Settings;

defined( 'ABSPATH' ) || exit;

final class Settings {

	/**
	 * Per-request cache of merged settings.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Create the settings option when it is missing (activation, and every request via init) so the
	 * policy version, which defaults to the current month, stays stable instead of changing monthly.
	 */
	public static function ensure_defaults() {
		if ( false === get_option( SettingsSchema::OPTION ) ) {
			add_option( SettingsSchema::OPTION, SettingsSchema::defaults(), '', 'yes' );
		}
		self::flush();
	}

	/**
	 * Drop the per-request cache.
	 */
	public static function flush() {
		self::$cache = null;
	}

	/**
	 * Stored settings merged over defaults, group by group.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function all() {
		if ( null !== self::$cache ) {
			return self::$cache;
		}
		$stored = get_option( SettingsSchema::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$merged = SettingsSchema::defaults();
		foreach ( $merged as $group => $values ) {
			if ( isset( $stored[ $group ] ) && is_array( $stored[ $group ] ) ) {
				$merged[ $group ] = array_merge( $values, $stored[ $group ] );
			}
		}
		// Extra groups added by later phases pass through untouched.
		foreach ( $stored as $group => $values ) {
			if ( ! isset( $merged[ $group ] ) ) {
				$merged[ $group ] = $values;
			}
		}
		self::$cache = $merged;
		return self::$cache;
	}

	/**
	 * One group of settings.
	 *
	 * @param string $group Group key.
	 * @return array<string,mixed>
	 */
	public static function group( $group ) {
		$all = self::all();
		return isset( $all[ $group ] ) ? $all[ $group ] : array();
	}

	/**
	 * One setting value.
	 *
	 * @param string $group    Group key.
	 * @param string $key      Setting key.
	 * @param mixed  $fallback Value when unknown.
	 * @return mixed
	 */
	public static function get( $group, $key, $fallback = null ) {
		$values = self::group( $group );
		return array_key_exists( $key, $values ) ? $values[ $key ] : $fallback;
	}

	/**
	 * Persist one sanitised group.
	 *
	 * @param string $group  Group key.
	 * @param array  $values Raw group input (will be sanitised).
	 */
	public static function save_group( $group, array $values ) {
		$current = self::all();
		$new     = SettingsSchema::sanitize( array( $group => $values ), $current );
		update_option( SettingsSchema::OPTION, $new, true );
		self::flush();
	}

	/**
	 * Change some keys of one group and keep the rest as stored. Unlike save_group(), keys that are not mentioned keep
	 * their value even when they are checkboxes. Every value goes through the schema sanitising.
	 *
	 * @param string $group   Group key.
	 * @param array  $changes Key => new raw value.
	 */
	public static function patch_group( $group, array $changes ) {
		$fields = SettingsSchema::fields();
		if ( ! isset( $fields[ $group ] ) ) {
			return;
		}
		$known = array_intersect_key( $changes, $fields[ $group ] );
		self::save_group( $group, array_merge( self::group( $group ), $known ) );
	}

	/**
	 * Persist an already-sanitised full settings array (used by the version bump).
	 *
	 * @param array $settings Full settings.
	 */
	public static function replace( array $settings ) {
		update_option( SettingsSchema::OPTION, $settings, true );
		self::flush();
	}

	/**
	 * Visitor texts for a language: defaults, then admin overrides.
	 *
	 * @param string $lang vi|en.
	 * @return array<string,string>
	 */
	public static function texts( $lang ) {
		$lang      = 'en' === $lang ? 'en' : 'vi';
		$overrides = get_option( 'dscc_texts_' . $lang, array() );
		return DefaultTexts::merged( $lang, is_array( $overrides ) ? $overrides : array() );
	}

	/**
	 * Raw overrides (admin form).
	 *
	 * @param string $lang vi|en.
	 * @return array<string,string>
	 */
	public static function text_overrides( $lang ) {
		$overrides = get_option( 'dscc_texts_' . ( 'en' === $lang ? 'en' : 'vi' ), array() );
		return is_array( $overrides ) ? $overrides : array();
	}

	/**
	 * Increase the policy version so every visitor is asked again.
	 *
	 * @return string The new version.
	 */
	public static function bump_policy_version() {
		$settings                               = self::all();
		$settings['advanced']['policy_version'] = gmdate( 'Y-m-d-His' );
		self::replace( $settings );
		return $settings['advanced']['policy_version'];
	}
}
