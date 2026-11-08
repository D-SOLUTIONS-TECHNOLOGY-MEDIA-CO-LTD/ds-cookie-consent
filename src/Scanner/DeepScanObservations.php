<?php
/**
 * Turns a validated deep scan report into catalog observations for FindingStore::merge_cookies(). Pure: no WordPress
 * calls (the site's own hosts are passed in).
 *
 * Trust decision (consistent with the ScriptRules rule that only `set_by=server` unclassified cookies are enforced):
 * the deep scan is run by an authenticated administrator in a clean browser, so what it saw is real. A cookie of the
 * site's own host (or a parent / sub domain of it) is filed as `server` only when a Set-Cookie header of an own host
 * carried its name; otherwise it is `client` (written by script) and is catalogued but not enforced. Cookies of other
 * domains are `unknown`: the site cannot set or remove them, so they are catalogued for the policy only.
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Scanner;

final class DeepScanObservations {

	/**
	 * Is a cookie domain / host the site's own (same host, a parent domain or a sub domain)?
	 *
	 * @param string   $domain    Cookie domain or request host.
	 * @param string[] $own_hosts Hosts of the site.
	 * @return bool
	 */
	public static function is_own_domain( $domain, array $own_hosts ) {
		$domain = strtolower( ltrim( (string) $domain, '.' ) );
		if ( '' === $domain ) {
			return false;
		}
		foreach ( $own_hosts as $host ) {
			$host = strtolower( $host );
			if ( $domain === $host || ( false !== strpos( $domain, '.' ) && ( substr( $host, -strlen( $domain ) - 1 ) === '.' . $domain || substr( $domain, -strlen( $host ) - 1 ) === '.' . $host ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Observations for cookies and web storage, one per name (the most certain `set_by` wins).
	 *
	 * @param array    $data      Output of DeepScanValidator::validate()['data'].
	 * @param string[] $own_hosts Hosts of the site.
	 * @return array {observations: array[], skipped: int}
	 */
	public static function build( array $data, array $own_hosts ) {
		$server_names = array();
		foreach ( $data['phases'] as $phase ) {
			foreach ( $phase['set_cookies'] as $entry ) {
				if ( self::is_own_domain( $entry['host'], $own_hosts ) ) {
					$server_names = array_merge( $server_names, $entry['names'] );
				}
			}
		}
		$server_names = array_flip( $server_names );
		$found        = array();
		$skipped      = 0;
		foreach ( DeepScanValidator::PHASES as $phase_name ) {
			$phase = $data['phases'][ $phase_name ];
			$url   = isset( $phase['pages'][0] ) ? $phase['pages'][0] : '';
			foreach ( $phase['cookies'] as $cookie ) {
				$name = $cookie['name'];
				if ( ! BeaconValidator::literal_name( $name ) || BeaconValidator::looks_personal( $name ) || BeaconValidator::is_admin_cookie( $name ) ) {
					++$skipped;
					continue;
				}
				$own    = self::is_own_domain( $cookie['domain'], $own_hosts );
				$set_by = $own ? ( isset( $server_names[ $name ] ) ? 'server' : 'client' ) : 'unknown';
				$age    = $cookie['expires'] > 0 ? $cookie['expires'] - $data['scanned_at'] : 0;
				self::keep(
					$found,
					'cookie',
					$name,
					array(
						'name'         => $name,
						'storage'      => 'cookie',
						'domain'       => ltrim( $cookie['domain'], '.' ),
						'path'         => $cookie['path'],
						'max_age'      => $age > 0 ? $age : null,
						'has_lifetime' => true,
						'http_only'    => $cookie['http_only'],
						'set_by'       => $set_by,
						'url'          => $url,
					)
				);
			}
			foreach ( array(
				'localStorage'   => $phase['local'],
				'sessionStorage' => $phase['session'],
			) as $storage => $entries ) {
				foreach ( $entries as $entry ) {
					$host = parse_url( $entry['origin'], PHP_URL_HOST ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- pure helper.
					if ( ! is_string( $host ) || ! self::is_own_domain( $host, $own_hosts ) ) {
						continue; // Keys of other origins (embedded frames) are not the site's own state.
					}
					if ( ! BeaconValidator::literal_name( $entry['key'] ) || BeaconValidator::looks_personal( $entry['key'] ) ) {
						++$skipped;
						continue;
					}
					self::keep(
						$found,
						$storage,
						$entry['key'],
						array(
							'name'    => $entry['key'],
							'storage' => $storage,
							'set_by'  => 'client',
							'url'     => $url,
						)
					);
				}
			}
		}
		return array(
			'observations' => array_values( $found ),
			'skipped'      => $skipped,
		);
	}

	/**
	 * Keep one observation per (storage, name); a more certain `set_by` replaces a weaker one.
	 *
	 * @param array  $found   Collected observations (by reference).
	 * @param string $storage Storage kind.
	 * @param string $name    Name.
	 * @param array  $obs     Observation.
	 */
	private static function keep( array &$found, $storage, $name, array $obs ) {
		$key = $storage . '|' . $name;
		if ( ! isset( $found[ $key ] ) || FindingStore::SET_BY_RANK[ $obs['set_by'] ] > FindingStore::SET_BY_RANK[ $found[ $key ]['set_by'] ] ) {
			$found[ $key ] = $obs;
		}
	}
}
