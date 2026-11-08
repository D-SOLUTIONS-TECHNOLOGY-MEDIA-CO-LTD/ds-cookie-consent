<?php
/**
 * Reads and validates an export document before anything touches the database. Structure problems are errors; keys
 * that are unknown or site-bound are dropped with a warning. Values are sanitised later by the same row and schema
 * sanitisers the admin forms use, so the preview shows exactly what would be stored.
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Transfer;

use DSolutions\CookieConsent\Catalog\ServiceRepository;
use DSolutions\CookieConsent\Settings\DefaultTexts;
use DSolutions\CookieConsent\Settings\SettingsSchema;

defined( 'ABSPATH' ) || exit;

final class ImportReader {

	const MAX_BYTES    = 2097152;
	const MAX_SERVICES = 400;
	const MAX_COOKIES  = 3000;

	/**
	 * Parse a JSON string.
	 *
	 * @param mixed $json Document text.
	 * @return array|\WP_Error Normalised document: settings, texts, services (list), cookies (list), warnings, preset.
	 */
	public static function read( $json ) {
		if ( ! is_string( $json ) || '' === trim( $json ) ) {
			return self::error( 'dscc_import_empty', __( 'The file is empty.', 'ds-cookie-consent' ) );
		}
		if ( strlen( $json ) > self::MAX_BYTES ) {
			return self::error( 'dscc_import_too_large', __( 'The file is larger than 2 MB.', 'ds-cookie-consent' ) );
		}
		// A UTF-8 byte order mark (Windows editors) is not part of the JSON.
		$json = preg_replace( '/^\xEF\xBB\xBF/', '', $json );
		$doc  = json_decode( (string) $json, true, 16 );
		if ( ! is_array( $doc ) || array() === $doc || array_keys( $doc ) === range( 0, count( $doc ) - 1 ) ) {
			return self::error( 'dscc_import_bad_json', __( 'The file is not a valid JSON object.', 'ds-cookie-consent' ) );
		}
		if ( ! isset( $doc['format'] ) || ExportBuilder::FORMAT !== $doc['format'] ) {
			return self::error( 'dscc_import_bad_format', __( 'This is not a DS Cookie Consent export file.', 'ds-cookie-consent' ) );
		}
		if ( ! isset( $doc['version'] ) || ! is_int( $doc['version'] ) || $doc['version'] < 1 || $doc['version'] > ExportBuilder::VERSION ) {
			return self::error( 'dscc_import_bad_version', __( 'This export file was made by a newer version of the plugin. Update the plugin first.', 'ds-cookie-consent' ) );
		}
		foreach ( array( 'settings', 'texts', 'services', 'cookies' ) as $section ) {
			if ( isset( $doc[ $section ] ) && ! is_array( $doc[ $section ] ) ) {
				/* translators: %s: section name in the file */
				return self::error( 'dscc_import_bad_section', sprintf( __( 'The "%s" section of the file is malformed.', 'ds-cookie-consent' ), $section ) );
			}
		}

		$warnings = array();
		$out      = array(
			'settings' => self::settings( isset( $doc['settings'] ) ? $doc['settings'] : array(), $warnings ),
			'texts'    => self::texts( isset( $doc['texts'] ) ? $doc['texts'] : array(), $warnings ),
			'preset'   => self::preset( $doc ),
		);

		$services = self::list_section( $doc, 'services', self::MAX_SERVICES );
		if ( is_wp_error( $services ) ) {
			return $services;
		}
		$cookies = self::list_section( $doc, 'cookies', self::MAX_COOKIES );
		if ( is_wp_error( $cookies ) ) {
			return $cookies;
		}
		$out['services'] = self::services( $services );
		if ( is_wp_error( $out['services'] ) ) {
			return $out['services'];
		}
		$out['cookies'] = self::cookies( $cookies );
		if ( is_wp_error( $out['cookies'] ) ) {
			return $out['cookies'];
		}
		$out['warnings'] = $warnings;
		return $out;
	}

	/**
	 * Build a WP_Error.
	 *
	 * @param string $code    Code.
	 * @param string $message Message.
	 * @return \WP_Error
	 */
	private static function error( $code, $message ) {
		return new \WP_Error( $code, $message );
	}

	/**
	 * Preset label and description when the file is a bundled preset.
	 *
	 * @param array $doc Document.
	 * @return array{label:string,description:string}
	 */
	private static function preset( array $doc ) {
		$meta = isset( $doc['preset'] ) && is_array( $doc['preset'] ) ? $doc['preset'] : array();
		return array(
			'label'       => sanitize_text_field( Presets::localized( $meta, 'label' ) ),
			'description' => sanitize_text_field( Presets::localized( $meta, 'description' ) ),
		);
	}

	/**
	 * A section that must be a list of objects.
	 *
	 * @param array  $doc     Document.
	 * @param string $name    Section.
	 * @param int    $maximum Largest allowed list.
	 * @return array|\WP_Error
	 */
	private static function list_section( array $doc, $name, $maximum ) {
		$items = isset( $doc[ $name ] ) ? $doc[ $name ] : array();
		if ( array() !== $items && array_keys( $items ) !== range( 0, count( $items ) - 1 ) ) {
			/* translators: %s: section name in the file */
			return self::error( 'dscc_import_bad_section', sprintf( __( 'The "%s" section of the file is malformed.', 'ds-cookie-consent' ), $name ) );
		}
		if ( count( $items ) > $maximum ) {
			/* translators: 1: section name, 2: limit */
			return self::error( 'dscc_import_too_many', sprintf( __( 'The "%1$s" section has more than %2$d entries.', 'ds-cookie-consent' ), $name, $maximum ) );
		}
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				/* translators: %s: section name in the file */
				return self::error( 'dscc_import_bad_section', sprintf( __( 'The "%s" section of the file is malformed.', 'ds-cookie-consent' ), $name ) );
			}
		}
		return $items;
	}

	/**
	 * Keep known, portable settings keys.
	 *
	 * @param array    $settings Raw settings section.
	 * @param string[] $warnings Collected warnings.
	 * @return array
	 */
	private static function settings( array $settings, array &$warnings ) {
		$fields  = SettingsSchema::fields();
		$out     = array();
		$ignored = array();
		foreach ( $settings as $group => $values ) {
			if ( ! is_string( $group ) || ! isset( $fields[ $group ] ) || ! is_array( $values ) ) {
				$ignored[] = is_string( $group ) ? $group : '?';
				continue;
			}
			foreach ( $values as $key => $value ) {
				if ( ! is_string( $key ) || ! isset( $fields[ $group ][ $key ] ) || ! ExportBuilder::portable( $group, $key ) ) {
					$ignored[] = $group . '.' . ( is_string( $key ) ? $key : '?' );
					continue;
				}
				$out[ $group ][ $key ] = $value;
			}
		}
		if ( $ignored ) {
			/* translators: %s: list of setting names */
			$warnings[] = sprintf( __( 'Settings that do not exist or are specific to one site were ignored: %s', 'ds-cookie-consent' ), implode( ', ', array_slice( $ignored, 0, 15 ) ) . ( count( $ignored ) > 15 ? ', ...' : '' ) );
		}
		return $out;
	}

	/**
	 * Keep known text keys of the two languages.
	 *
	 * @param array    $texts    Raw texts section.
	 * @param string[] $warnings Collected warnings.
	 * @return array<string,array<string,string>>
	 */
	private static function texts( array $texts, array &$warnings ) {
		$out     = array();
		$ignored = 0;
		foreach ( DefaultTexts::LANGS as $lang ) {
			$known = DefaultTexts::all( $lang );
			foreach ( isset( $texts[ $lang ] ) && is_array( $texts[ $lang ] ) ? $texts[ $lang ] : array() as $key => $text ) {
				if ( is_string( $key ) && isset( $known[ $key ] ) && is_string( $text ) ) {
					$out[ $lang ][ $key ] = $text;
				} else {
					++$ignored;
				}
			}
		}
		if ( $ignored ) {
			/* translators: %d: number of texts */
			$warnings[] = sprintf( __( 'Texts with an unknown key were ignored: %d.', 'ds-cookie-consent' ), $ignored );
		}
		return $out;
	}

	/**
	 * Check the platforms: a usable unique slug and a name are required.
	 *
	 * @param array[] $items Raw services.
	 * @return array[]|\WP_Error Rows keyed by slug.
	 */
	private static function services( array $items ) {
		$out = array();
		foreach ( $items as $row ) {
			$slug = isset( $row['slug'] ) && is_string( $row['slug'] ) ? sanitize_key( $row['slug'] ) : '';
			if ( '' === $slug || strlen( $slug ) > ServiceRepository::MAX_SLUG || ! isset( $row['name'] ) || ! is_string( $row['name'] ) || '' === trim( $row['name'] ) ) {
				return self::error( 'dscc_import_bad_service', __( 'A platform in the file has no valid slug or name.', 'ds-cookie-consent' ) );
			}
			if ( isset( $out[ $slug ] ) ) {
				/* translators: %s: platform slug */
				return self::error( 'dscc_import_duplicate_service', sprintf( __( 'The platform "%s" appears twice in the file.', 'ds-cookie-consent' ), $slug ) );
			}
			$row['slug']  = $slug;
			$out[ $slug ] = $row;
		}
		return $out;
	}

	/**
	 * Check the cookies: a name is required and (name, storage, domain) must be unique.
	 *
	 * @param array[] $items Raw cookies.
	 * @return array[]|\WP_Error
	 */
	private static function cookies( array $items ) {
		$seen = array();
		$out  = array();
		foreach ( $items as $row ) {
			if ( ! isset( $row['name'] ) || ! is_string( $row['name'] ) || '' === trim( $row['name'] ) ) {
				return self::error( 'dscc_import_bad_cookie', __( 'A cookie in the file has no name.', 'ds-cookie-consent' ) );
			}
			if ( isset( $row['service_slug'] ) && ! is_string( $row['service_slug'] ) ) {
				return self::error( 'dscc_import_bad_cookie', __( 'A cookie in the file has an invalid platform reference.', 'ds-cookie-consent' ) );
			}
			$row['service_slug'] = isset( $row['service_slug'] ) ? sanitize_key( $row['service_slug'] ) : '';
			$identity            = $row['name'] . "\0" . ( isset( $row['storage'] ) && is_string( $row['storage'] ) ? $row['storage'] : 'cookie' ) . "\0" . ( isset( $row['domain'] ) && is_string( $row['domain'] ) ? strtolower( $row['domain'] ) : '' );
			if ( isset( $seen[ $identity ] ) ) {
				/* translators: %s: cookie name */
				return self::error( 'dscc_import_duplicate_cookie', sprintf( __( 'The cookie "%s" appears twice in the file.', 'ds-cookie-consent' ), $row['name'] ) );
			}
			$seen[ $identity ] = true;
			$out[]             = $row;
		}
		return $out;
	}
}
