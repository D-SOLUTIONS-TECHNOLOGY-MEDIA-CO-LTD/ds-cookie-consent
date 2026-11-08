<?php
/**
 * Observe-only mode: what the blocker WOULD have blocked, for the compliance view. Bounded on purpose: at most
 * MAX_SERVICES services, MAX_SAMPLES distinct samples each, MAX_SAMPLE_LENGTH characters per sample. It is
 * autoloaded (a few KB at most) so reading it costs no extra query; the write happens only when a new sample
 * appears, or counters are older than FLUSH_SECONDS.
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Blocker;

defined( 'ABSPATH' ) || exit;

final class ObservedMatches {

	const OPTION            = 'dscc_observed';
	const MAX_SERVICES      = 30;
	const MAX_SAMPLES       = 3;
	const MAX_SAMPLE_LENGTH = 160;
	const FLUSH_SECONDS     = 300;

	/**
	 * Merge the matches of one page view into the stored report.
	 *
	 * @param array[] $matches Matches {service, category, kind, sample}.
	 * @param int     $now     Unix time.
	 * @return bool True when the option was written.
	 */
	public static function record( array $matches, $now = 0 ) {
		if ( ! $matches ) {
			return false;
		}
		$now   = $now ? (int) $now : time();
		$data  = self::load();
		$dirty = false;
		$seen  = array();
		foreach ( $matches as $match ) {
			$slug = substr( (string) $match['service'], 0, 40 );
			if ( ! isset( $data['items'][ $slug ] ) ) {
				if ( count( $data['items'] ) >= self::MAX_SERVICES ) {
					continue;
				}
				$data['items'][ $slug ] = array(
					'category' => (string) $match['category'],
					'pages'    => 0,
					'last'     => $now,
					'samples'  => array(),
				);
				$dirty                  = true;
			}
			if ( ! isset( $seen[ $slug ] ) ) {
				$seen[ $slug ] = true;
				++$data['items'][ $slug ]['pages'];
			}
			$sample = function_exists( 'mb_substr' ) ? mb_substr( $match['kind'] . ' ' . $match['sample'], 0, self::MAX_SAMPLE_LENGTH ) : substr( $match['kind'] . ' ' . $match['sample'], 0, self::MAX_SAMPLE_LENGTH );
			if ( count( $data['items'][ $slug ]['samples'] ) < self::MAX_SAMPLES && ! in_array( $sample, $data['items'][ $slug ]['samples'], true ) ) {
				$data['items'][ $slug ]['samples'][] = $sample;
				$dirty                               = true;
			}
		}
		if ( ! $dirty && $now - (int) $data['flushed'] < self::FLUSH_SECONDS ) {
			return false;
		}
		$data['flushed'] = $now;
		foreach ( array_keys( $seen ) as $slug ) {
			$data['items'][ $slug ]['last'] = $now;
		}
		update_option( self::OPTION, $data, true );
		return true;
	}

	/**
	 * Stored report.
	 *
	 * @return array{flushed:int,items:array<string,array>}
	 */
	public static function load() {
		$data = get_option( self::OPTION );
		if ( ! is_array( $data ) || ! isset( $data['items'] ) || ! is_array( $data['items'] ) ) {
			return array(
				'flushed' => 0,
				'items'   => array(),
			);
		}
		$data['flushed'] = isset( $data['flushed'] ) ? (int) $data['flushed'] : 0;
		return $data;
	}

	/**
	 * Delete the report.
	 */
	public static function clear() {
		delete_option( self::OPTION );
	}
}
