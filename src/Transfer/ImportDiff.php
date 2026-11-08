<?php
/**
 * The comparison half of an import: which settings, texts, platforms and cookies a document would add or change on this
 * site, computed with the same sanitisers the real import uses, so the preview shows what would be stored.
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Transfer;

use DSolutions\CookieConsent\Catalog\CookieRepository;
use DSolutions\CookieConsent\Catalog\CookieRow;
use DSolutions\CookieConsent\Catalog\ServiceRepository;
use DSolutions\CookieConsent\Catalog\ServiceRow;
use DSolutions\CookieConsent\Settings\DefaultTexts;
use DSolutions\CookieConsent\Settings\Settings;
use DSolutions\CookieConsent\Settings\SettingsSchema;

defined( 'ABSPATH' ) || exit;

final class ImportDiff {

	/** Names listed per category in the preview. */
	const LIST_LIMIT = 40;

	/**
	 * Changed settings, as the schema would store them.
	 *
	 * @param array    $settings Group => key => raw value.
	 * @param string[] $warnings Collected warnings.
	 * @return array{changes:array[],count:int}
	 */
	public static function settings( array $settings, array &$warnings ) {
		$changes = array();
		SettingsSchema::take_rejections();
		foreach ( $settings as $group => $values ) {
			$current   = Settings::group( $group );
			$candidate = SettingsSchema::sanitize_group( $group, array_merge( $current, $values ), $current );
			foreach ( array_keys( $values ) as $key ) {
				$before = isset( $current[ $key ] ) ? $current[ $key ] : null;
				$after  = isset( $candidate[ $key ] ) ? $candidate[ $key ] : null;
				if ( self::canon( $before ) !== self::canon( $after ) ) {
					$changes[] = array(
						'group' => $group,
						'key'   => $key,
						'old'   => $before,
						'new'   => $after,
					);
				}
			}
			foreach ( array_keys( SettingsSchema::take_rejections() ) as $field ) {
				/* translators: %s: setting name such as advanced.cookie_name */
				$warnings[] = sprintf( __( 'The value of %s is not valid: the current value is kept.', 'ds-cookie-consent' ), $group . '.' . $field );
			}
		}
		SettingsSchema::take_rejections();
		return array(
			'changes' => $changes,
			'count'   => count( $changes ),
		);
	}

	/**
	 * Number of text overrides that change, per language.
	 *
	 * @param array $texts Lang => key => text.
	 * @return array<string,int>
	 */
	public static function texts( array $texts ) {
		$out = array();
		foreach ( DefaultTexts::LANGS as $lang ) {
			$out[ $lang ] = 0;
			if ( empty( $texts[ $lang ] ) ) {
				continue;
			}
			$current = Settings::text_overrides( $lang );
			foreach ( SettingsSchema::sanitize_texts( $lang, $texts[ $lang ] ) as $key => $text ) {
				if ( ! isset( $current[ $key ] ) || $current[ $key ] !== $text ) {
					++$out[ $lang ];
				}
			}
		}
		return $out;
	}

	/**
	 * Platforms: new / updated / unchanged.
	 *
	 * @param array[] $services Rows keyed by slug.
	 * @return array{new:int,updated:int,unchanged:int,names:string[]}
	 */
	public static function services( array $services ) {
		$out = array(
			'new'       => 0,
			'updated'   => 0,
			'unchanged' => 0,
			'names'     => array(),
		);
		foreach ( $services as $slug => $row ) {
			$clean    = self::service_input( $row );
			$existing = ServiceRepository::find_by_slug( $slug );
			if ( ! $existing ) {
				++$out['new'];
				$out['names'][] = $slug;
				continue;
			}
			if ( self::differs( ServiceRow::sanitize( $clean ), $existing, array( 'slug' ) ) ) {
				++$out['updated'];
				$out['names'][] = $slug;
			} else {
				++$out['unchanged'];
			}
		}
		$out['names'] = array_slice( $out['names'], 0, self::LIST_LIMIT );
		return $out;
	}

	/**
	 * Cookies: new / updated / unchanged / skipped (platform unknown).
	 *
	 * @param array[] $cookies  Cookie rows.
	 * @param array[] $services Platforms of the file, keyed by slug.
	 * @return array
	 */
	public static function cookies( array $cookies, array $services ) {
		$out = array(
			'new'           => 0,
			'updated'       => 0,
			'unchanged'     => 0,
			'skipped'       => 0,
			'names'         => array(),
			'skipped_names' => array(),
		);
		foreach ( $cookies as $row ) {
			$slug = $row['service_slug'];
			if ( '' !== $slug && ! isset( $services[ $slug ] ) && ! ServiceRepository::find_by_slug( $slug ) ) {
				++$out['skipped'];
				$out['skipped_names'][] = (string) $row['name'];
				continue;
			}
			$clean    = CookieRow::sanitize( self::cookie_input( $row ) );
			$existing = CookieRepository::find_identity( $clean['name'], isset( $clean['storage'] ) ? $clean['storage'] : 'cookie', isset( $clean['domain'] ) ? $clean['domain'] : '' );
			if ( ! $existing ) {
				++$out['new'];
				$out['names'][] = $clean['name'];
				continue;
			}
			$service = '' === $slug ? null : ServiceRepository::find_by_slug( $slug );
			// A platform that is created by this very import cannot be the one the stored cookie points at.
			$same_service = '' === $slug ? 0 === (int) $existing['service_id'] : ( $service && (int) $service['id'] === (int) $existing['service_id'] );
			if ( ! $same_service || self::differs( $clean, $existing, array( 'name', 'storage', 'domain', 'name_is_pattern' ) ) ) {
				++$out['updated'];
				$out['names'][] = $clean['name'];
			} else {
				++$out['unchanged'];
			}
		}
		$out['names']         = array_slice( $out['names'], 0, self::LIST_LIMIT );
		$out['skipped_names'] = array_slice( $out['skipped_names'], 0, 10 );
		return $out;
	}

	/**
	 * Writable platform fields present in a document row.
	 *
	 * @param array $row Document row.
	 * @return array
	 */
	public static function service_input( array $row ) {
		return array_intersect_key( $row, array_flip( ExportBuilder::SERVICE_FIELDS ) );
	}

	/**
	 * Writable cookie fields present in a document row.
	 *
	 * @param array $row Document row.
	 * @return array
	 */
	public static function cookie_input( array $row ) {
		return array_intersect_key( $row, array_flip( ExportBuilder::COOKIE_FIELDS ) );
	}

	/**
	 * Whether any sanitised field differs from the stored row.
	 *
	 * @param array    $clean    Sanitised input.
	 * @param array    $existing Stored (hydrated) row.
	 * @param string[] $skip     Keys that identify the row and are not compared.
	 * @return bool
	 */
	private static function differs( array $clean, array $existing, array $skip ) {
		foreach ( $clean as $key => $value ) {
			if ( in_array( $key, $skip, true ) || ! array_key_exists( $key, $existing ) ) {
				continue;
			}
			$stored = $existing[ $key ];
			if ( 'script_patterns' === $key && is_string( $value ) ) {
				$value = json_decode( $value, true );
			}
			if ( 'detected_via' === $key ) {
				continue;
			}
			if ( self::canon( $value ) !== self::canon( $stored ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Comparable form of a value (arrays by JSON, scalars by string, null as empty).
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function canon( $value ) {
		if ( is_array( $value ) ) {
			return (string) wp_json_encode( $value );
		}
		if ( is_bool( $value ) ) {
			return $value ? '1' : '0';
		}
		return null === $value ? '' : (string) $value;
	}
}
