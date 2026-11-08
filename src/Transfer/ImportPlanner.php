<?php
/**
 * Works out what importing a document would change (the preview) and performs the import.
 *
 * Rules: settings in the file overwrite the same settings, others stay; text overrides are merged; platforms and
 * cookies are upserted by slug and by (name, storage, domain) and never deleted; the consent log is never touched.
 * Every value goes through the same sanitisers as the admin forms, and the preview is computed with them, so what
 * is shown is what is stored.
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Transfer;

use DSolutions\CookieConsent\Catalog\CookieRepository;
use DSolutions\CookieConsent\Catalog\ServiceRepository;
use DSolutions\CookieConsent\Settings\DefaultTexts;
use DSolutions\CookieConsent\Settings\Settings;
use DSolutions\CookieConsent\Settings\SettingsSchema;

defined( 'ABSPATH' ) || exit;

final class ImportPlanner {

	/**
	 * Diff of a document against the current site.
	 *
	 * @param array $doc Document from ImportReader::read().
	 * @return array {settings: {changes[], count}, texts: {vi, en}, services: {new, updated, unchanged, names[]},
	 *                cookies: {new, updated, unchanged, skipped, names[]}, warnings[], changes: int}
	 */
	public static function plan( array $doc ) {
		$warnings = $doc['warnings'];
		$settings = ImportDiff::settings( $doc['settings'], $warnings );
		$texts    = ImportDiff::texts( $doc['texts'] );
		$services = ImportDiff::services( $doc['services'] );
		$cookies  = ImportDiff::cookies( $doc['cookies'], $doc['services'] );
		foreach ( $cookies['skipped_names'] as $name ) {
			/* translators: %s: cookie name */
			$warnings[] = sprintf( __( 'The cookie "%s" was skipped because its platform is not in the file and not on this site.', 'ds-cookie-consent' ), $name );
		}
		$changes = $settings['count'] + array_sum( $texts ) + $services['new'] + $services['updated'] + $cookies['new'] + $cookies['updated'];
		return array(
			'settings' => $settings,
			'texts'    => $texts,
			'services' => $services,
			'cookies'  => $cookies,
			'warnings' => array_slice( $warnings, 0, 30 ),
			'changes'  => $changes,
		);
	}

	/**
	 * Import a document.
	 *
	 * @param array $doc Document from ImportReader::read().
	 * @return array The plan that was executed.
	 */
	public static function apply( array $doc ) {
		$plan = self::plan( $doc );

		foreach ( $doc['settings'] as $group => $values ) {
			Settings::patch_group( $group, $values );
		}
		foreach ( DefaultTexts::LANGS as $lang ) {
			if ( ! empty( $doc['texts'][ $lang ] ) ) {
				$merged = array_merge( Settings::text_overrides( $lang ), SettingsSchema::sanitize_texts( $lang, $doc['texts'][ $lang ] ) );
				update_option( 'dscc_texts_' . $lang, $merged, true );
			}
		}

		$ids = array();
		foreach ( $doc['services'] as $slug => $row ) {
			$ids[ $slug ] = ServiceRepository::upsert( ImportDiff::service_input( $row ) );
		}
		foreach ( $doc['cookies'] as $row ) {
			$service_id = self::resolve_service( $row['service_slug'], $ids );
			if ( null === $service_id ) {
				continue;
			}
			CookieRepository::upsert( array_merge( ImportDiff::cookie_input( $row ), array( 'service_id' => $service_id ) ) );
		}
		return $plan;
	}

	/**
	 * Service id for a cookie row: 0 for none, the id of a platform imported just now, or the stored platform.
	 *
	 * @param string $slug Platform slug ('' = none).
	 * @param int[]  $ids  Ids of platforms upserted in this import.
	 * @return int|null Null when the platform does not exist anywhere.
	 */
	private static function resolve_service( $slug, array $ids ) {
		if ( '' === $slug ) {
			return 0;
		}
		if ( ! empty( $ids[ $slug ] ) ) {
			return (int) $ids[ $slug ];
		}
		$existing = ServiceRepository::find_by_slug( $slug );
		return $existing ? (int) $existing['id'] : null;
	}
}
