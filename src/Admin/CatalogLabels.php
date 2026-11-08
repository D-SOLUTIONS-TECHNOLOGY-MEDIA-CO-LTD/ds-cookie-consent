<?php
/**
 * Admin-side labels for catalog categories, statuses and sources.
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Admin;

defined( 'ABSPATH' ) || exit;

final class CatalogLabels {

	/**
	 * Categories an admin can assign, in display order.
	 *
	 * @return array<string,string>
	 */
	public static function categories() {
		return array(
			'necessary'  => __( 'Necessary', 'ds-cookie-consent' ),
			'functional' => __( 'Functional', 'ds-cookie-consent' ),
			'analytics'  => __( 'Analytics', 'ds-cookie-consent' ),
			'marketing'  => __( 'Marketing', 'ds-cookie-consent' ),
		);
	}

	/**
	 * Assignable categories plus the internal "unclassified" (for filters and table cells).
	 *
	 * @return array<string,string>
	 */
	public static function categories_with_unclassified() {
		return array_merge( self::categories(), array( 'unclassified' => __( 'Unclassified', 'ds-cookie-consent' ) ) );
	}

	/**
	 * Cookie statuses.
	 *
	 * @return array<string,string>
	 */
	public static function statuses() {
		return array(
			'new'       => __( 'New', 'ds-cookie-consent' ),
			'confirmed' => __( 'Confirmed', 'ds-cookie-consent' ),
			'auto'      => __( 'Automatic', 'ds-cookie-consent' ),
			'ignored'   => __( 'Ignored', 'ds-cookie-consent' ),
		);
	}

	/**
	 * Cookie sources.
	 *
	 * @return array<string,string>
	 */
	public static function sources() {
		return array(
			'known_db'  => __( 'Known database', 'ds-cookie-consent' ),
			'scan'      => __( 'Scan', 'ds-cookie-consent' ),
			'deep_scan' => __( 'Deep scan', 'ds-cookie-consent' ),
			'manual'    => __( 'Manual', 'ds-cookie-consent' ),
		);
	}
}
