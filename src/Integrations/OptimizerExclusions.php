<?php
/**
 * Strings that keep page optimisers away from the consent script and the blocked tags.
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Integrations;

use DSolutions\CookieConsent\Blocker\HtmlRewriter;
use DSolutions\CookieConsent\Blocker\ScriptRules;

defined( 'ABSPATH' ) || exit;

final class OptimizerExclusions {

	/**
	 * Path of the consent script under the site (substring that identifies it in a src URL).
	 *
	 * @return string
	 */
	public static function own_script_path() {
		return 'assets/dist/dscc.js';
	}

	/**
	 * Hosts of the active blocking rules (their scripts must stay out of combined files).
	 *
	 * @return string[]
	 */
	public static function hosts() {
		$hosts = array();
		foreach ( ScriptRules::active()['rules'] as $rule ) {
			foreach ( $rule['h'] as $host ) {
				$hosts[ $host ] = $host;
			}
		}
		return array_values( $hosts );
	}

	/**
	 * Marker placed in blocked inline scripts (WP Rocket matches inline exclusions against the script body only).
	 *
	 * @return string
	 */
	public static function inline_marker() {
		return HtmlRewriter::INLINE_MARKER;
	}
}
