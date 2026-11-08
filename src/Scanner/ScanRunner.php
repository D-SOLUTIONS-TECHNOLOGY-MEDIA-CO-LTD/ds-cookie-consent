<?php
/**
 * Runs server scans (spec 6.2 A): collects the URLs, fetches them in batches of five and merges what it finds into the
 * catalog through FindingStore. A batch is driven by whoever asks first: the chained single cron event `dscc_scan_tick`
 * (a scan finishes without any admin screen open) or the admin's progress polling (`dscc_scan_status`, which keeps the
 * progress bar moving even when WP-Cron is slow). A lock keeps the two from working on the same run at once.
 *
 * Run row: type server, status running|done|failed. While running, `report.queue` lists the URLs still to fetch.
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Scanner;

use DSolutions\CookieConsent\Catalog\KnownDb;
use DSolutions\CookieConsent\Catalog\ServiceRepository;
use DSolutions\CookieConsent\Settings\Settings;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class ScanRunner {

	const BATCH       = 5;
	const MAX_GTM     = 5;
	const STALE_AFTER = 1800;
	const LOCK_OPTION = 'dscc_scan_lock';
	const LOCK_TTL    = 180;
	const TICK_DELAY  = 5;

	/** Report list caps: the report is stored as one JSON column. */
	const MAX_REPORT_URLS    = 200;
	const MAX_REPORT_COOKIES = 150;

	/**
	 * Register hooks.
	 */
	public static function register() {
		add_action( 'dscc_scan_tick', array( __CLASS__, 'cron_tick' ) );
		add_action( 'dscc_scan_weekly', array( __CLASS__, 'weekly' ) );
	}

	/**
	 * Start a scan. A scan that is already running (and not stale) is reused.
	 *
	 * @param string $trigger manual|cron.
	 * @return array|WP_Error {id, total, resumed}
	 */
	public static function start( $trigger = 'manual' ) {
		$running = ScanRunRepository::latest( 'server', 'running' );
		if ( $running ) {
			if ( ! self::is_stale( $running ) ) {
				self::schedule_tick( $running['id'], 2 );
				return array(
					'id'      => $running['id'],
					'total'   => (int) $running['summary']['total'],
					'resumed' => true,
				);
			}
			self::fail( $running, 'stale' );
		}
		$urls = UrlCollector::collect( (int) Settings::get( 'scanner', 'max_urls', 50 ) );
		if ( ! $urls ) {
			return new WP_Error( 'dscc_scan_no_urls', __( 'No page found to scan.', 'ds-cookie-consent' ) );
		}
		$id = ScanRunRepository::create(
			'server',
			array(
				'trigger'     => 'cron' === $trigger ? 'cron' : 'manual',
				'total'       => count( $urls ),
				'done'        => 0,
				'failed'      => 0,
				'cookies'     => 0,
				'new_cookies' => 0,
				'touched'     => time(),
			),
			array(
				'queue'         => $urls,
				'urls'          => array(),
				'cookies'       => array(),
				'services'      => array(),
				'gtm_ids'       => array(),
				'gtm'           => array(),
				'unknown_hosts' => array(),
			)
		);
		if ( ! $id ) {
			return new WP_Error( 'dscc_scan_db', __( 'Could not create the scan run.', 'ds-cookie-consent' ) );
		}
		self::schedule_tick( $id, 2 );
		return array(
			'id'      => $id,
			'total'   => count( $urls ),
			'resumed' => false,
		);
	}

	/**
	 * Cron callback: one batch of a run, then the next event.
	 *
	 * @param int $run_id Run id.
	 */
	public static function cron_tick( $run_id ) {
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 120 ); // A batch is five pages of up to 15 seconds each.
		}
		self::tick( (int) $run_id );
	}

	/**
	 * Weekly callback: start a scan when the setting still asks for it.
	 */
	public static function weekly() {
		if ( 'weekly' === Settings::get( 'scanner', 'auto_scan', 'off' ) ) {
			self::start( 'cron' );
		}
	}

	/**
	 * Work one batch of a run (when nobody else is) and report the progress.
	 *
	 * @param int $run_id Run id.
	 * @return array Status, see status().
	 */
	public static function tick( $run_id ) {
		$run = ScanRunRepository::find( $run_id );
		if ( ! $run || 'server' !== $run['type'] || 'running' !== $run['status'] ) {
			return self::status( $run_id );
		}
		if ( self::is_stale( $run ) ) {
			self::fail( $run, 'stale' );
			return self::status( $run_id );
		}
		if ( ! self::lock() ) {
			return self::status( $run_id );
		}
		// Queue the next batch first: if this one dies (fatal error, hard timeout) the scan still carries on.
		self::schedule_tick( $run_id, self::TICK_DELAY );
		try {
			self::process( $run );
		} catch ( \Throwable $e ) {
			$fresh = ScanRunRepository::find( $run_id );
			if ( $fresh && 'running' === $fresh['status'] ) {
				self::fail( $fresh, 'exception' );
			}
		} finally {
			self::unlock();
		}
		return self::status( $run_id );
	}

	/**
	 * Progress of a run.
	 *
	 * @param int $run_id Run id.
	 * @return array {id, status, total, done, failed, cookies, new_cookies, finished, message}
	 */
	public static function status( $run_id ) {
		$run = ScanRunRepository::find( $run_id );
		if ( ! $run ) {
			return array(
				'id'          => (int) $run_id,
				'status'      => 'missing',
				'total'       => 0,
				'done'        => 0,
				'failed'      => 0,
				'cookies'     => 0,
				'new_cookies' => 0,
				'finished'    => true,
				'message'     => 'missing',
			);
		}
		$s = $run['summary'];
		return array(
			'id'          => $run['id'],
			'status'      => $run['status'],
			'total'       => (int) ( isset( $s['total'] ) ? $s['total'] : 0 ),
			'done'        => (int) ( isset( $s['done'] ) ? $s['done'] : 0 ),
			'failed'      => (int) ( isset( $s['failed'] ) ? $s['failed'] : 0 ),
			'cookies'     => (int) ( isset( $s['cookies'] ) ? $s['cookies'] : 0 ),
			'new_cookies' => (int) ( isset( $s['new_cookies'] ) ? $s['new_cookies'] : 0 ),
			'finished'    => 'running' !== $run['status'],
			'message'     => isset( $s['message'] ) ? (string) $s['message'] : '',
		);
	}

	/**
	 * Fetch the next batch of a run and merge it.
	 *
	 * @param array $run Run row.
	 */
	private static function process( array $run ) {
		$report = $run['report'];
		$sum    = $run['summary'];
		$queue  = isset( $report['queue'] ) && is_array( $report['queue'] ) ? $report['queue'] : array();
		$batch  = array_splice( $queue, 0, self::BATCH );
		$known  = KnownDb::load();
		$hosts  = UrlCollector::own_hosts();
		$own    = $hosts ? $hosts[0] : '';
		foreach ( $batch as $url ) {
			self::absorb( ServerScanner::fetch( (string) $url ), $report, $sum, $known, $own );
		}
		$report['queue'] = $queue;
		$sum['touched']  = time();
		if ( $queue ) {
			ScanRunRepository::update(
				$run['id'],
				array(
					'summary' => $sum,
					'report'  => $report,
				)
			);
			return;
		}
		self::finish( $run['id'], $report, $sum, $known, (string) $run['started_at'] );
	}

	/**
	 * Merge one fetched page into the catalog and the run report.
	 *
	 * @param array        $page   ServerScanner::fetch() result.
	 * @param array        $report Run report (by reference).
	 * @param array        $sum    Run summary (by reference).
	 * @param KnownDb|null $known  Known database.
	 * @param string       $own    Own host.
	 */
	private static function absorb( array $page, array &$report, array &$sum, $known, $own ) {
		++$sum['done'];
		if ( '' !== $page['error'] ) {
			++$sum['failed'];
		}
		if ( count( $report['urls'] ) < self::MAX_REPORT_URLS ) {
			$report['urls'][] = array(
				'p' => $page['path'],
				'c' => $page['code'],
				'e' => $page['error'],
			);
		}

		$observations = array();
		foreach ( $page['cookies'] as $cookie ) {
			$observations[ $cookie['name'] ] = array(
				'name'         => $cookie['name'],
				'domain'       => $cookie['domain'],
				'path'         => $cookie['path'],
				'max_age'      => $cookie['max_age'],
				'has_lifetime' => true,
				'http_only'    => $cookie['http_only'],
				'set_by'       => 'server',
				'url'          => $page['path'],
			);
		}
		$set_names = array_keys( $observations );
		foreach ( $page['held'] as $name ) {
			if ( ! isset( $observations[ $name ] ) ) {
				$observations[ $name ] = array(
					'name'   => $name,
					'set_by' => 'server',
					'url'    => $page['path'],
				);
			}
		}
		$results = FindingStore::merge_cookies(
			array_values( $observations ),
			array(
				'source' => 'scan',
				'via'    => 'server',
			)
		);
		foreach ( $results as $result ) {
			$name = $result['name'];
			$held = ! in_array( $name, $set_names, true );
			if ( isset( $report['cookies'][ $name ] ) ) {
				$report['cookies'][ $name ]['held'] = $report['cookies'][ $name ]['held'] && $held;
				++$report['cookies'][ $name ]['urls'];
			} elseif ( count( $report['cookies'] ) < self::MAX_REPORT_COOKIES ) {
				$report['cookies'][ $name ] = array(
					'name'      => $name,
					'held'      => $held,
					'urls'      => 1,
					'domain'    => isset( $observations[ $name ]['domain'] ) ? $observations[ $name ]['domain'] : '',
					'http_only' => ! empty( $observations[ $name ]['http_only'] ),
				);
				$sum['cookies']             = count( $report['cookies'] );
			}
			if ( $result['created'] && 'new' === $result['status'] ) {
				++$sum['new_cookies'];
			}
		}

		if ( '' !== $page['html'] && $known ) {
			$extracted = HtmlScanner::extract( $page['html'] );
			$detected  = HtmlScanner::detect( $extracted['resources'], $known, $own );
			foreach ( $detected['services'] as $slug => $evidence ) {
				if ( ! isset( $report['services'][ $slug ]['html'] ) ) {
					FindingStore::merge_detection( $slug, 'html' );
					$report['services'][ $slug ]['html'] = $evidence;
				}
			}
			$report['unknown_hosts'] = array_slice( array_values( array_unique( array_merge( $report['unknown_hosts'], $detected['unknown_hosts'] ) ) ), 0, 30 );
			$report['gtm_ids']       = array_slice( array_values( array_unique( array_merge( $report['gtm_ids'], $extracted['gtm_ids'] ) ) ), 0, 10 );
		}
	}

	/**
	 * Last batch done: read Tag Manager containers, close the run, notify.
	 *
	 * @param int          $id     Run id.
	 * @param array        $report Report.
	 * @param array        $sum    Summary.
	 * @param KnownDb|null $known  Known database.
	 * @param string       $started_at Run start (MySQL, GMT).
	 */
	private static function finish( $id, array $report, array $sum, $known, $started_at ) {
		foreach ( array_slice( $report['gtm_ids'], 0, self::MAX_GTM ) as $container ) {
			$read  = GtmContainerReader::read( $container );
			$entry = array(
				'id'       => $container,
				'ok'       => $read['ok'],
				'error'    => $read['error'],
				'tags'     => array_slice( $read['tag_ids'], 0, 20 ),
				'services' => array(),
			);
			if ( $read['ok'] && $known ) {
				$urls = array_merge( array_map( array( GtmParser::class, 'tag_url' ), $read['tag_ids'] ), $read['urls'] );
				$map  = array();
				foreach ( $urls as $url ) {
					$map[] = array(
						'kind'   => 'script',
						'src'    => $url,
						'inline' => '',
					);
				}
				$found = HtmlScanner::detect( $map, $known, '' );
				foreach ( $found['services'] as $slug => $evidence ) {
					FindingStore::merge_detection( $slug, 'gtm' );
					$report['services'][ $slug ]['gtm'] = $evidence;
					$entry['services'][]                = $slug;
				}
			}
			$report['gtm'][] = $entry;
		}
		unset( $report['queue'] );

		// Platforms this scan added: from now on the blocker covers them, the admin should know.
		$sum['new_platforms'] = array();
		foreach ( ServiceRepository::all( 'auto' ) as $service ) {
			if ( $service['created_at'] >= $started_at && $service['block_enabled'] && 0 !== strpos( $service['slug'], 'site-' ) && count( $sum['new_platforms'] ) < 20 ) {
				$sum['new_platforms'][] = $service['name'];
			}
		}

		$loaded = (int) $sum['done'] - (int) $sum['failed'];
		if ( $loaded <= 0 ) {
			$sum['message'] = 'no_page_loaded';
		}
		ScanRunRepository::update(
			$id,
			array(
				'status'      => $loaded > 0 ? 'done' : 'failed',
				'finished_at' => current_time( 'mysql', true ),
				'summary'     => $sum,
				'report'      => $report,
			)
		);
		if ( $loaded > 0 ) {
			update_option( 'dscc_last_scan', time(), false );
			ScanNotifier::maybe_send( $sum );
		}
		do_action( 'dscc_scan_finished', $id, $sum, $report );
	}

	/**
	 * Close a run as failed.
	 *
	 * @param array  $run     Run row.
	 * @param string $message Reason code.
	 */
	private static function fail( array $run, $message ) {
		$sum            = $run['summary'];
		$sum['message'] = $message;
		$report         = $run['report'];
		unset( $report['queue'] );
		ScanRunRepository::update(
			$run['id'],
			array(
				'status'      => 'failed',
				'finished_at' => current_time( 'mysql', true ),
				'summary'     => $sum,
				'report'      => $report,
			)
		);
	}

	/**
	 * Has a running run stopped making progress?
	 *
	 * @param array $run Run row.
	 * @return bool
	 */
	private static function is_stale( array $run ) {
		$touched = isset( $run['summary']['touched'] ) ? (int) $run['summary']['touched'] : 0;
		return time() - $touched > self::STALE_AFTER;
	}

	/**
	 * Queue the next cron event of a run (one pending event per run).
	 *
	 * @param int $run_id Run id.
	 * @param int $delay  Seconds from now.
	 */
	private static function schedule_tick( $run_id, $delay ) {
		if ( ! wp_next_scheduled( 'dscc_scan_tick', array( (int) $run_id ) ) ) {
			wp_schedule_single_event( time() + (int) $delay, 'dscc_scan_tick', array( (int) $run_id ) );
		}
	}

	/**
	 * Take the scan lock (best effort; a lock older than LOCK_TTL is considered abandoned).
	 *
	 * @return bool
	 */
	private static function lock() {
		if ( add_option( self::LOCK_OPTION, time(), '', 'no' ) ) {
			return true;
		}
		if ( time() - (int) get_option( self::LOCK_OPTION ) > self::LOCK_TTL ) {
			update_option( self::LOCK_OPTION, time(), false );
			return true;
		}
		return false;
	}

	/**
	 * Release the scan lock.
	 */
	private static function unlock() {
		delete_option( self::LOCK_OPTION );
	}
}
