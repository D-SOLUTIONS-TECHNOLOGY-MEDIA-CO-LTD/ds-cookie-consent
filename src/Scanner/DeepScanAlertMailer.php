<?php
/**
 * Plain-text email to the site admin when a deep scan finds compliance violations (an analytics or marketing item
 * present before the visitor chose or after they rejected). Separate from ScanNotifier, which reports new cookies.
 * The same set of violations is not mailed again within a day; a different set goes out at once.
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Scanner;

use DSolutions\CookieConsent\Admin\CatalogLabels;
use DSolutions\CookieConsent\Catalog\ServiceRepository;
use DSolutions\CookieConsent\Settings\Settings;

defined( 'ABSPATH' ) || exit;

final class DeepScanAlertMailer {

	/** Option holding {hash, at} of the last mailed violation set. */
	const OPTION   = 'dscc_deep_alert_mail';
	const INTERVAL = 86400;
	const MAX_LIST = 20;

	/**
	 * Send the alert when there is at least one violation, the toggle is on and this exact set was not mailed
	 * during the last day. A conclusive scan with no violations forgets the last set, so a problem that comes
	 * back is reported again.
	 *
	 * @param array<string,array[]> $violations Violations per phase (DeepScanViolations::compute()).
	 * @param bool                  $conclusive Whether the scan could have said "clean".
	 * @param string                $scanned_at Scan time from the report (ISO 8601, UTC).
	 * @param int                   $now        Unix time (default now).
	 * @return bool True when an email was sent.
	 */
	public static function maybe_send( array $violations, $conclusive, $scanned_at, $now = 0 ) {
		$now   = $now ? (int) $now : time();
		$total = 0;
		foreach ( $violations as $list ) {
			$total += count( $list );
		}
		if ( 0 === $total ) {
			if ( $conclusive ) {
				delete_option( self::OPTION );
			}
			return false;
		}
		if ( ! Settings::get( 'scanner', 'notify_violations', true ) ) {
			return false;
		}
		$to = ScanNotifier::recipient();
		if ( '' === $to ) {
			return false;
		}
		$hash = self::fingerprint( $violations );
		$last = get_option( self::OPTION, array() );
		if ( is_array( $last ) && isset( $last['hash'], $last['at'] ) && hash_equals( (string) $last['hash'], $hash ) && $now - (int) $last['at'] < self::INTERVAL ) {
			return false;
		}

		$site    = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$subject = sprintf(
			/* translators: 1: site name, 2: number of violations */
			__( '[%1$s] Cookie Consent: deep scan found %2$d consent violation(s)', 'ds-cookie-consent' ),
			$site,
			$total
		);
		$sent = (bool) wp_mail( $to, $subject, self::body( $site, $violations, $conclusive, $scanned_at, $total ) );
		if ( $sent ) {
			update_option(
				self::OPTION,
				array(
					'hash' => $hash,
					'at'   => $now,
				),
				false
			);
		}
		return $sent;
	}

	/**
	 * Stable hash of the violation set (phase, storage and name; order does not matter).
	 *
	 * @param array<string,array[]> $violations Violations per phase.
	 * @return string
	 */
	public static function fingerprint( array $violations ) {
		$keys = array();
		foreach ( $violations as $phase => $list ) {
			foreach ( $list as $item ) {
				$keys[] = $phase . '|' . $item['storage'] . '|' . $item['name'];
			}
		}
		sort( $keys );
		return hash( 'sha256', implode( "\n", array_unique( $keys ) ) );
	}

	/**
	 * Email text.
	 *
	 * @param string                $site       Site name.
	 * @param array<string,array[]> $violations Violations per phase.
	 * @param bool                  $conclusive Conclusive scan.
	 * @param string                $scanned_at Scan time (ISO 8601, UTC).
	 * @param int                   $total      Number of violations.
	 * @return string
	 */
	private static function body( $site, array $violations, $conclusive, $scanned_at, $total ) {
		$time = strtotime( $scanned_at );
		$when = $time ? get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $time ), 'd/m/Y H:i' ) : $scanned_at;
		$body = sprintf(
			/* translators: 1: site name, 2: scan date and time */
			__( 'The deep scan of %1$s (%2$s) found cookies, storage or requests of analytics or marketing services that should not be there.', 'ds-cookie-consent' ),
			$site,
			$when
		) . "\n";
		$body .= $conclusive
			? __( 'The scan is conclusive: it loaded pages and found the banner and the plugin.', 'ds-cookie-consent' )
			: __( 'The scan is not conclusive (for example it did not find the banner or the plugin), so treat the result with care.', 'ds-cookie-consent' );
		$body .= "\n";

		$phases = array(
			'before_choice' => __( 'When the visitor has not chosen', 'ds-cookie-consent' ),
			'after_reject'  => __( 'After the visitor rejected', 'ds-cookie-consent' ),
		);
		$labels = CatalogLabels::categories_with_unclassified();
		$shown  = 0;
		foreach ( $phases as $phase => $label ) {
			if ( empty( $violations[ $phase ] ) ) {
				continue;
			}
			$body .= "\n" . $label . ' (' . count( $violations[ $phase ] ) . "):\n";
			foreach ( $violations[ $phase ] as $item ) {
				if ( $shown >= self::MAX_LIST ) {
					break;
				}
				$category = isset( $labels[ $item['category'] ] ) ? $labels[ $item['category'] ] : (string) $item['category'];
				$body    .= '- ' . $item['name'] . ' [' . $item['storage'] . '] - ' . $category . ' - ' . self::platform( (string) $item['service'] ) . "\n";
				++$shown;
			}
		}
		if ( $total > $shown ) {
			$body .= "\n" . sprintf(
				/* translators: %d: number of violations not listed */
				__( '... and %d more.', 'ds-cookie-consent' ),
				$total - $shown
			) . "\n";
		}
		return $body . "\n" . admin_url( 'admin.php?page=dscc' ) . "#dscc-deep-scan\n";
	}

	/**
	 * Platform name of a service slug.
	 *
	 * @param string $slug Service slug.
	 * @return string
	 */
	private static function platform( $slug ) {
		if ( '' === $slug ) {
			return __( 'unknown platform', 'ds-cookie-consent' );
		}
		$service = ServiceRepository::find_by_slug( $slug );
		return $service && '' !== (string) $service['name'] ? (string) $service['name'] : $slug;
	}
}
