<?php
/**
 * Compliance alerts (spec 6.6): what the last scans and visitors say about cookies and consent.
 *  - before_consent: analytics / marketing / unclassified cookies the site sent to a first-time visitor (last server scan);
 *  - held: cookies the site tried to set but the server cookie guard removed (information, not a violation);
 *  - leaks: analytics / marketing cookies seen in browsers that had denied them (beacon, deep scan);
 *  - unclassified: cookies nobody has classified yet;
 *  - not_in_policy: cookies seen on the site that the cookie policy page does not mention.
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Admin;

use DSolutions\CookieConsent\Blocker\ScriptRules;
use DSolutions\CookieConsent\Catalog\CookieQuery;
use DSolutions\CookieConsent\Scanner\AlertStore;
use DSolutions\CookieConsent\Scanner\BeaconValidator;
use DSolutions\CookieConsent\Scanner\FindingStore;
use DSolutions\CookieConsent\Scanner\ScanRunRepository;
use DSolutions\CookieConsent\Settings\Settings;

defined( 'ABSPATH' ) || exit;

final class ComplianceReport {

	/**
	 * Build the report from stored data (a few queries; admin screens only).
	 *
	 * @return array
	 */
	public static function build() {
		$run    = ScanRunRepository::latest( 'server', 'done' );
		$report = array(
			'scan'           => $run ? array(
				'id'          => $run['id'],
				'finished_at' => (string) $run['finished_at'],
			) : null,
			'before_consent' => array(),
			'held'           => array(),
			'leaks'          => AlertStore::leaks(),
			'unclassified'   => array(),
			'unclassified_n' => 0,
			'not_in_policy'  => array(),
			'policy_state'   => 'ok',
			'new_capped'     => ScriptRules::new_cookies_capped(),
			'total'          => 0,
			'deep'           => self::deep_summary(),
			'mode'           => 'block' === Settings::get( 'blocking', 'unclassified_cookie_mode', 'report' ) ? 'block' : 'report',
		);

		if ( $run && ! empty( $run['report']['cookies'] ) ) {
			$cookies  = $run['report']['cookies'];
			$resolved = FindingStore::resolve( array_keys( $cookies ) );
			foreach ( $cookies as $name => $cookie ) {
				$category = isset( $resolved[ $name ] ) ? $resolved[ $name ]['category'] : 'unclassified';
				if ( ! in_array( $category, array( 'analytics', 'marketing', 'unclassified' ), true ) ) {
					continue;
				}
				$item = array(
					'id'       => isset( $resolved[ $name ] ) ? (int) $resolved[ $name ]['id'] : 0,
					'name'     => (string) $name,
					'category' => $category,
					'urls'     => (int) $cookie['urls'],
				);
				if ( ! empty( $cookie['held'] ) ) {
					$report['held'][] = $item;
				} else {
					$report['before_consent'][] = $item;
				}
			}
		}

		$open                     = CookieQuery::unclassified_open( 20 );
		$report['unclassified']   = $open['rows'];
		$report['unclassified_n'] = $open['total'];

		$policy                 = PolicyPageText::get();
		$report['policy_state'] = $policy['state'];
		if ( 'ok' === $policy['state'] ) {
			$seen = array();
			foreach ( CookieQuery::seen_on_site() as $row ) {
				if ( 'cookie' === $row['storage'] && BeaconValidator::is_admin_cookie( $row['name'] ) ) {
					continue;
				}
				if ( ! isset( $seen[ $row['name'] ] ) && ! PolicyPageText::mentions( $policy['text'], $row['name'] ) ) {
					$seen[ $row['name'] ]      = true;
					$report['not_in_policy'][] = $row['name'];
				}
			}
		}

		$report['total'] = count( $report['before_consent'] ) + count( $report['leaks'] ) + $report['unclassified_n'] + count( $report['not_in_policy'] );
		return $report;
	}

	/**
	 * The last headless deep scan: time, site, pages, banner and the violations of each checked phase.
	 *
	 * @return array|null
	 */
	private static function deep_summary() {
		$run = ScanRunRepository::latest( 'deep', 'done' );
		if ( ! $run ) {
			return null;
		}
		$summary    = $run['summary'];
		$violations = array();
		foreach ( array( 'before_choice', 'after_reject' ) as $phase ) {
			$violations[ $phase ] = isset( $run['report']['phases'][ $phase ]['violations'] ) ? (array) $run['report']['phases'][ $phase ]['violations'] : array();
		}
		return array(
			'id'              => $run['id'],
			'finished_at'     => ScanRunRepository::finished_gmt( $run ),
			'site'            => isset( $summary['site'] ) ? (string) $summary['site'] : '',
			'pages'           => isset( $summary['pages'] ) ? (int) $summary['pages'] : 0,
			'banner_found'    => ! empty( $summary['banner_found'] ),
			'plugin_detected' => ! empty( $summary['plugin_detected'] ),
			'conclusive'      => ! isset( $summary['conclusive'] ) || ! empty( $summary['conclusive'] ),
			'reason'          => isset( $summary['reason'] ) ? (string) $summary['reason'] : '',
			'platforms'       => isset( $summary['platforms'] ) && is_array( $summary['platforms'] ) ? $summary['platforms'] : array(),
			'violations'      => $violations,
			'count'           => count( $violations['before_choice'] ) + count( $violations['after_reject'] ),
		);
	}
}
