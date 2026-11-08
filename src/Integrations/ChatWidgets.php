<?php
/**
 * Selectors of chat widgets that sit in the corner the banner uses (Pancake, Zalo). When the platform is ticked in the
 * catalog, the selectors join the "hide while the banner is open / raise the shield icon" list. Admin entries are kept.
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Integrations;

use DSolutions\CookieConsent\Catalog\FrontendCatalogCache;

defined( 'ABSPATH' ) || exit;

final class ChatWidgets {

	/** Platform slug => selectors of its floating widget. */
	const SELECTORS = array(
		'pancake-chat' => array( '#pancake-chat-plugin-root' ),
		'zalo'         => array( '.zalo-chat-widget', '#zalo-chat-widget' ),
	);

	/**
	 * Admin list plus the selectors of the ticked chat platforms (no duplicates).
	 *
	 * @param string[] $selectors Admin selectors.
	 * @return string[]
	 */
	public static function merge( array $selectors ) {
		$ticked = array();
		foreach ( FrontendCatalogCache::services() as $service ) {
			$ticked[ $service['slug'] ] = true;
		}
		foreach ( self::SELECTORS as $slug => $list ) {
			if ( isset( $ticked[ $slug ] ) ) {
				$selectors = array_merge( $selectors, $list );
			}
		}
		return array_values( array_unique( $selectors ) );
	}
}
