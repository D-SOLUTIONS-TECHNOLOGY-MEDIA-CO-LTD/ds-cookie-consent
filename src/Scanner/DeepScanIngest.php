<?php
/**
 * Stores one validated deep scan: merges the cookies and platforms into the catalog (FindingStore), computes the
 * compliance violations, records them as leak alerts, saves the run (`dscc_scan_runs`, type `deep`) and mails the
 * violations (DeepScanAlertMailer).
 * No cookie value is ever present in the data this class receives.
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Scanner;

use DSolutions\CookieConsent\Catalog\KnownDb;
use DSolutions\CookieConsent\Catalog\ServiceRepository;

defined( 'ABSPATH' ) || exit;

final class DeepScanIngest {

	/** Cookie names kept per phase in the stored report. */
	const MAX_REPORT_NAMES = 200;

	/**
	 * Does the report describe this site?
	 *
	 * @param string $site Site address from the report.
	 * @return bool
	 */
	public static function is_own_site( $site ) {
		$host = parse_url( $site, PHP_URL_HOST ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- address was validated.
		return is_string( $host ) && in_array( strtolower( $host ), self::own_hosts(), true );
	}

	/**
	 * Hosts that count as this site: the home and site address hosts (with the www. variant), plus what the filter
	 * `dscc_scan_own_hosts` adds (for example the name a container uses to reach the site).
	 *
	 * @return string[]
	 */
	public static function own_hosts() {
		$hosts = apply_filters( 'dscc_scan_own_hosts', UrlCollector::own_hosts() );
		$clean = array();
		foreach ( is_array( $hosts ) ? $hosts : array() as $host ) {
			$host = DeepScanFields::host( is_string( $host ) ? trim( $host ) : '' );
			if ( null !== $host ) {
				$clean[] = $host;
			}
		}
		return array_values( array_unique( $clean ) );
	}

	/**
	 * Why a scan cannot be trusted to say "clean" (empty string = conclusive).
	 *
	 * @param array $data Validated report.
	 * @return string no_pages|no_banner|no_plugin|''
	 */
	public static function inconclusive_reason( array $data ) {
		if ( empty( $data['phases']['before_choice']['pages'] ) ) {
			return 'no_pages';
		}
		if ( empty( $data['banner_found'] ) ) {
			return 'no_banner';
		}
		return empty( $data['plugin_detected'] ) ? 'no_plugin' : '';
	}

	/**
	 * Merge and store.
	 *
	 * @param array $data Output of DeepScanValidator::validate()['data'].
	 * @return array {run_id, merged, created, skipped, violations: {phase: int}}
	 */
	public static function run( array $data ) {
		$built   = DeepScanObservations::build( $data, self::own_hosts() );
		$results = FindingStore::merge_cookies(
			$built['observations'],
			array(
				'source' => 'deep_scan',
				'via'    => 'deep',
			)
		);
		$created = 0;
		$new     = 0;
		foreach ( $results as $result ) {
			if ( $result['created'] ) {
				++$created;
				$new += 'new' === $result['status'] ? 1 : 0;
			}
		}

		$vendors = self::detect_vendors( $data );
		/**
		 * Service slugs whose host-only contact before consent is a cookieless Google Consent Mode ping: listed as a
		 * warning on the deep scan card, not as a violation (no alert, no email).
		 *
		 * @param string[] $slugs Default: google-analytics, google-ads, google-floodlight.
		 */
		$ping_slugs = array_values( array_filter( (array) apply_filters( 'dscc_consent_mode_ping_services', DeepScanViolations::CONSENT_MODE_SERVICES ), 'is_string' ) );
		$split      = DeepScanViolations::split_consent_mode_pings( DeepScanViolations::compute( $data, $results, $vendors['watched'] ), $ping_slugs );
		$violations = $split['violations'];
		$warnings   = $split['warnings'];
		$reason     = self::inconclusive_reason( $data );
		self::record_alerts( $violations, '' === $reason );

		$pages = array();
		foreach ( $data['phases'] as $phase ) {
			$pages = array_merge( $pages, $phase['pages'] );
		}
		$counts  = array_map( 'count', $violations );
		$summary = array(
			'site'            => $data['site'],
			'scanned_at'      => $data['scanned_at'],
			'pages'           => count( array_unique( $pages ) ),
			'banner_found'    => $data['banner_found'],
			'plugin_detected' => $data['plugin_detected'],
			'cookies'         => count( $results ),
			'new_cookies'     => $new,
			'violations'      => $counts,
			'warnings'        => array_map( 'count', $warnings ),
			'errors'          => count( $data['errors'] ),
			'conclusive'      => '' === $reason,
			'reason'          => $reason,
			'platforms'       => $vendors['created'],
		);
		$run_id  = ScanRunRepository::create( 'deep', $summary, self::stored_report( $data, $violations, $warnings ), 'done' );
		if ( $run_id ) {
			ScanRunRepository::update( $run_id, array( 'finished_at' => current_time( 'mysql', true ) ) );
			DeepScanAlertMailer::maybe_send( $violations, '' === $reason, $data['scanned_at'] );
		}
		return array(
			'run_id'            => $run_id,
			'merged'            => count( $results ),
			'created'           => $created,
			'skipped'           => $built['skipped'],
			'violations'        => $counts,
			'warnings'          => array_map( 'count', $warnings ),
			'conclusive'        => '' === $reason,
			'platforms_created' => $vendors['created'],
		);
	}

	/**
	 * Platforms whose hosts the browser contacted (host-level patterns of the known database). Every one is recorded
	 * on the site (the deep scan is a trusted source); the analytics and marketing ones come back as hosts that must be
	 * absent before a choice.
	 *
	 * @param array $data Validated report.
	 * @return array {watched: array[] {host, slug, category}, created: array[] {slug, name, category} new platforms}
	 */
	private static function detect_vendors( array $data ) {
		$known = KnownDb::load();
		$hosts = array();
		foreach ( $data['phases'] as $phase ) {
			$hosts = array_merge( $hosts, $phase['hosts'] );
		}
		$out = array(
			'watched' => array(),
			'created' => array(),
		);
		if ( ! $known ) {
			return $out;
		}
		foreach ( array_unique( $hosts ) as $host ) {
			foreach ( VendorHostMatcher::services_for( $known, $host ) as $def ) {
				$slug    = $def['slug'];
				$existed = null !== ServiceRepository::find_by_slug( $slug );
				FindingStore::merge_detection( $slug, 'deep' );
				$service = ServiceRepository::find_by_slug( $slug );
				if ( ! $service ) {
					continue;
				}
				if ( ! $existed ) {
					$out['created'][ $slug ] = array(
						'slug'     => $slug,
						'name'     => (string) $service['name'],
						'category' => (string) $service['category_key'],
					);
				}
				if ( 'ignored' !== $service['status'] && in_array( $service['category_key'], LeakDetector::WATCHED, true ) ) {
					$out['watched'][] = array(
						'host'     => $host,
						'slug'     => $slug,
						'category' => $service['category_key'],
					);
				}
			}
		}
		$out['created'] = array_values( $out['created'] );
		return $out;
	}

	/**
	 * A conclusive deep scan replaces the alerts of the previous one.
	 *
	 * @param array $violations Violations per phase.
	 * @param bool  $conclusive Whether the scan can say "clean"; an inconclusive one keeps the earlier alerts.
	 */
	private static function record_alerts( array $violations, $conclusive ) {
		if ( $conclusive ) {
			AlertStore::clear_source( 'deep' );
		}
		$leaks = array();
		foreach ( $violations as $list ) {
			foreach ( $list as $item ) {
				$leaks[ $item['storage'] . ':' . $item['name'] ] = array(
					'name'         => $item['name'],
					'storage'      => $item['storage'],
					'category'     => $item['category'],
					'service_slug' => $item['service'],
				);
			}
		}
		AlertStore::record_leaks( array_values( $leaks ), 'deep' );
	}

	/**
	 * The compact report that is stored: names and hosts only.
	 *
	 * @param array $data       Validated report.
	 * @param array $violations Violations per phase.
	 * @param array $warnings   Cookieless Consent Mode pings per phase.
	 * @return array
	 */
	private static function stored_report( array $data, array $violations, array $warnings ) {
		$phases = array();
		foreach ( $data['phases'] as $name => $phase ) {
			$phases[ $name ] = array(
				'pages'      => $phase['pages'],
				'cookies'    => array_slice( array_values( array_unique( wp_list_pluck( $phase['cookies'], 'name' ) ) ), 0, self::MAX_REPORT_NAMES ),
				'hosts'      => $phase['hosts'],
				'violations' => isset( $violations[ $name ] ) ? $violations[ $name ] : array(),
				'warnings'   => isset( $warnings[ $name ] ) ? $warnings[ $name ] : array(),
			);
		}
		return array(
			'schema'       => $data['schema'],
			'site'         => $data['site'],
			'scanned_at'   => $data['scanned_at'],
			'tool_version' => $data['tool_version'],
			'errors'       => $data['errors'],
			'phases'       => $phases,
		);
	}
}
