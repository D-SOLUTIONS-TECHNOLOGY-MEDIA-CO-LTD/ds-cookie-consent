<?php
/**
 * Which known platforms does a request host belong to? Uses the host-level patterns of the known database
 * (`script_patterns.hosts`: the host itself or any sub domain), so a request that carries no script path still counts.
 * Pure: no WordPress calls.
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Scanner;

use DSolutions\CookieConsent\Catalog\KnownDb;
use DSolutions\CookieConsent\Catalog\PatternMatcher;

final class VendorHostMatcher {

	/**
	 * Known-database services whose host patterns cover the host.
	 *
	 * @param KnownDb $known Known database.
	 * @param string  $host  Request host.
	 * @return array[] Service entries.
	 */
	public static function services_for( KnownDb $known, $host ) {
		$found = array();
		foreach ( $known->services() as $service ) {
			$hosts = isset( $service['script_patterns']['hosts'] ) && is_array( $service['script_patterns']['hosts'] ) ? $service['script_patterns']['hosts'] : array();
			if ( $hosts && PatternMatcher::host_matches( $host, $hosts ) ) {
				$found[] = $service;
			}
		}
		return $found;
	}
}
