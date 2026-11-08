<?php
/**
 * Structure rules for one service entry of a known-cookie database (service fields, script patterns, cookies).
 * Optional cookie fields: domain_hint, table_group, applies_to, applies_to_en (all strings).
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Catalog;

final class KnownDbServiceRules {

	/**
	 * Errors of one service entry.
	 *
	 * @param mixed  $s     Service.
	 * @param string $where Location label.
	 * @return string[]
	 */
	public static function service_errors( $s, $where ) {
		if ( ! is_array( $s ) ) {
			return array( $where . ': not an object' );
		}
		$e = array();
		if ( empty( $s['slug'] ) || ! is_string( $s['slug'] ) || strlen( $s['slug'] ) > ServiceRepository::MAX_SLUG || ! preg_match( '/^[a-z0-9]+(-[a-z0-9]+)*$/', $s['slug'] ) ) {
			$e[] = $where . ': invalid slug';
		}
		if ( empty( $s['name'] ) || ! is_string( $s['name'] ) ) {
			$e[] = $where . ': name required';
		}
		if ( empty( $s['category'] ) || ! in_array( $s['category'], KnownDbValidator::CATEGORIES, true ) ) {
			$e[] = $where . ': invalid category';
		}
		if ( empty( $s['provider_company'] ) || ! is_string( $s['provider_company'] ) ) {
			$e[] = $where . ': provider_company required';
		}
		if ( empty( $s['privacy_url'] ) || ! is_string( $s['privacy_url'] ) || ! preg_match( '#^https://\S+$#', $s['privacy_url'] ) ) {
			$e[] = $where . ': privacy_url must be an https URL';
		}
		if ( empty( $s['country'] ) || ! is_string( $s['country'] ) || ! preg_match( '/^[A-Z]{2}$/', $s['country'] ) ) {
			$e[] = $where . ': country must be ISO 3166-1 alpha-2';
		}
		if ( ! isset( $s['is_third_party'] ) || ! is_bool( $s['is_third_party'] ) ) {
			$e[] = $where . ': is_third_party must be boolean';
		}
		$e = array_merge( $e, self::pattern_errors( isset( $s['script_patterns'] ) ? $s['script_patterns'] : null, $where ) );
		if ( ! isset( $s['cookies'] ) || ! is_array( $s['cookies'] ) || count( $s['cookies'] ) > KnownDbValidator::MAX_COOKIES ) {
			return array_merge( $e, array( $where . ': cookies must be an array' ) );
		}
		foreach ( array_values( $s['cookies'] ) as $j => $cookie ) {
			$e = array_merge( $e, self::cookie_errors( $cookie, $where . '.cookies[' . $j . ']' ) );
		}
		return $e;
	}

	/**
	 * Errors of a script_patterns object.
	 *
	 * @param mixed  $sp    Value.
	 * @param string $where Location label.
	 * @return string[]
	 */
	public static function pattern_errors( $sp, $where ) {
		if ( ! is_array( $sp ) ) {
			return array( $where . ': script_patterns required' );
		}
		$e = array();
		foreach ( array( 'hosts', 'src_regex', 'inline_regex' ) as $key ) {
			if ( ! isset( $sp[ $key ] ) || ! is_array( $sp[ $key ] ) || array_filter( $sp[ $key ], 'is_string' ) !== $sp[ $key ] ) {
				$e[] = $where . '.script_patterns.' . $key . ' must be string[]';
			}
		}
		if ( $e ) {
			return $e;
		}
		foreach ( $sp['hosts'] as $host ) {
			if ( ! preg_match( '/^[a-z0-9.\-]+$/', $host ) ) {
				$e[] = $where . ': host must be a bare lowercase hostname';
			}
		}
		foreach ( array_merge( $sp['src_regex'], $sp['inline_regex'] ) as $regex ) {
			if ( ! PatternMatcher::valid_regex( $regex ) ) {
				$e[] = $where . ': invalid regex';
			}
		}
		return $e;
	}

	/**
	 * Errors of one cookie entry.
	 *
	 * @param mixed  $c     Cookie.
	 * @param string $where Location label.
	 * @return string[]
	 */
	public static function cookie_errors( $c, $where ) {
		if ( ! is_array( $c ) ) {
			return array( $where . ': not an object' );
		}
		$e = array();
		if ( empty( $c['name'] ) || ! is_string( $c['name'] ) || strlen( $c['name'] ) > 100 ) {
			$e[] = $where . ': name required (max 100)';
		} elseif ( ! isset( $c['is_pattern'] ) || ! is_bool( $c['is_pattern'] ) || PatternMatcher::is_pattern( $c['name'] ) !== $c['is_pattern'] ) {
			$e[] = $where . ': is_pattern must equal (name contains "*")';
		}
		if ( empty( $c['storage'] ) || ! in_array( $c['storage'], CookieRepository::STORAGES, true ) ) {
			$e[] = $where . ': invalid storage';
		}
		foreach ( array( 'duration_vi', 'duration_en', 'purpose_vi', 'purpose_en' ) as $key ) {
			if ( ! isset( $c[ $key ] ) || ! is_string( $c[ $key ] ) || '' === trim( $c[ $key ] ) ) {
				$e[] = $where . ': ' . $key . ' required';
			}
		}
		if ( isset( $c['duration_seconds'] ) && ! ( is_int( $c['duration_seconds'] ) && $c['duration_seconds'] >= 0 ) ) {
			$e[] = $where . ': duration_seconds must be a non-negative integer or null';
		}
		foreach ( array( 'domain_hint', 'table_group', 'applies_to', 'applies_to_en' ) as $key ) {
			if ( isset( $c[ $key ] ) && ! is_string( $c[ $key ] ) ) {
				$e[] = $where . ': ' . $key . ' must be a string';
			}
		}
		return $e;
	}
}
