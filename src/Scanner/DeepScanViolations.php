<?php
/**
 * Compliance violations of a deep scan (spec 6.2 C): an analytics or marketing cookie, web storage key or vendor host
 * that is present before the visitor chose (`before_choice`) or after they rejected (`after_reject`). Pure: no
 * WordPress calls; the classification comes from the merge results and the vendor host list.
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Scanner;

final class DeepScanViolations {

	/** Phases in which analytics and marketing must be absent. */
	const CHECKED = array( 'before_choice', 'after_reject' );

	/** Consent state of both checked phases: nothing optional was allowed. */
	const CONSENT = array(
		'c' => array(
			'analytics' => 0,
			'marketing' => 0,
		),
		's' => array(),
	);

	/**
	 * Violations per checked phase.
	 *
	 * @param array   $data          Validated report (DeepScanValidator::validate()['data']).
	 * @param array[] $results       FindingStore::merge_cookies() results.
	 * @param array[] $vendor_hosts  Known vendor hosts that are watched: {host, slug, category}.
	 * @return array<string,array[]> phase => list of {storage: cookie|localStorage|sessionStorage|host, name, category, service, domain}
	 */
	public static function compute( array $data, array $results, array $vendor_hosts ) {
		$by_key = array();
		foreach ( $results as $result ) {
			$by_key[ $result['storage'] . '|' . $result['name'] ] = $result;
		}
		$out = array();
		foreach ( self::CHECKED as $name ) {
			$phase = $data['phases'][ $name ];
			$found = array();
			foreach ( $phase['cookies'] as $cookie ) {
				self::add_findings( $found, $by_key, 'cookie', $cookie['name'], $cookie['domain'] );
			}
			foreach ( array(
				'localStorage'   => $phase['local'],
				'sessionStorage' => $phase['session'],
			) as $storage => $entries ) {
				foreach ( $entries as $entry ) {
					self::add_findings( $found, $by_key, $storage, $entry['key'], (string) parse_url( $entry['origin'], PHP_URL_HOST ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- pure helper.
				}
			}
			foreach ( $vendor_hosts as $vendor ) {
				if ( in_array( $vendor['host'], $phase['hosts'], true ) ) {
					$found[ 'host|' . $vendor['host'] ] = array(
						'storage'  => 'host',
						'name'     => $vendor['host'],
						'category' => $vendor['category'],
						'service'  => $vendor['slug'],
						'domain'   => '',
					);
				}
			}
			$out[ $name ] = array_values( $found );
		}
		return $out;
	}

	/**
	 * Add the finding of one name when the empty consent does not allow it.
	 *
	 * @param array  $found    Violations collected so far (by reference).
	 * @param array  $by_key  Merge results by "storage|name".
	 * @param string $storage Storage kind.
	 * @param string $name    Name.
	 * @param string $domain  Cookie domain or storage origin host.
	 */
	private static function add_findings( array &$found, array $by_key, $storage, $name, $domain ) {
		$key = $storage . '|' . $name;
		if ( ! isset( $by_key[ $key ] ) ) {
			return;
		}
		foreach ( LeakDetector::find( array( $by_key[ $key ] ), self::CONSENT ) as $leak ) {
			$found[ $key ] = array(
				'storage'  => $storage,
				'name'     => $name,
				'category' => $leak['category'],
				'service'  => (string) $leak['service_slug'],
				'domain'   => $domain,
			);
		}
	}
}
