<?php
/**
 * Email to the admin when a scan finds new cookies. Throttled to one email per day; can be switched off.
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Scanner;

use DSolutions\CookieConsent\Admin\CookiesTab;
use DSolutions\CookieConsent\Admin\SettingsPage;
use DSolutions\CookieConsent\Catalog\CookieQuery;
use DSolutions\CookieConsent\Settings\Settings;

defined( 'ABSPATH' ) || exit;

final class ScanNotifier {

	const OPTION   = 'dscc_scan_mail_at';
	const INTERVAL = 86400;
	const MAX_LIST = 20;

	/**
	 * Recipient: the configured address, else the site admin's.
	 *
	 * @return string
	 */
	public static function recipient() {
		$email = (string) Settings::get( 'scanner', 'notify_email', '' );
		if ( '' === $email || ! is_email( $email ) ) {
			$email = (string) get_option( 'admin_email' );
		}
		return is_email( $email ) ? $email : '';
	}

	/**
	 * Send the email when the finished scan created new cookies, notifications are on and none went out today.
	 *
	 * @param array $summary Run summary (new_cookies).
	 * @param int   $now     Unix time (default now).
	 * @return bool True when an email was sent.
	 */
	public static function maybe_send( array $summary, $now = 0 ) {
		$now = $now ? (int) $now : time();
		if ( ( empty( $summary['new_cookies'] ) && empty( $summary['new_platforms'] ) ) || ! Settings::get( 'scanner', 'notify_enabled', true ) ) {
			return false;
		}
		$last = (int) get_option( self::OPTION, 0 );
		if ( $last && $now - $last < self::INTERVAL ) {
			return false;
		}
		$to = self::recipient();
		if ( '' === $to ) {
			return false;
		}
		$list  = CookieQuery::run(
			array(
				'status'   => 'new',
				'per_page' => self::MAX_LIST,
				'orderby'  => 'name',
				'order'    => 'asc',
			)
		);
		$lines = array();
		foreach ( $list['rows'] as $row ) {
			$lines[] = '- ' . $row['name'] . ( 'cookie' !== $row['storage'] ? ' (' . $row['storage'] . ')' : '' );
		}
		$site    = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$subject = sprintf(
			/* translators: 1: site name, 2: number of new cookies */
			__( '[%1$s] Cookie Consent: new cookies to classify: %2$d', 'ds-cookie-consent' ),
			$site,
			(int) $list['total']
		);
		$body = sprintf(
			/* translators: 1: site name, 2: number of cookies waiting for review */
			__( 'The cookie scan on %1$s just found new cookies. Cookies waiting for classification: %2$d. Unclassified cookies are blocked like the Marketing category and are hidden from visitors until you handle them.', 'ds-cookie-consent' ),
			$site,
			(int) $list['total']
		);
		$body .= "\n\n" . implode( "\n", $lines );
		if ( $list['total'] > count( $lines ) ) {
			$body .= "\n" . sprintf(
				/* translators: %d: number of cookies not listed */
				__( '... and %d more.', 'ds-cookie-consent' ),
				$list['total'] - count( $lines )
			);
		}
		if ( ! empty( $summary['new_platforms'] ) ) {
			$body .= "\n\n" . sprintf(
				/* translators: %s: platform names */
				__( 'Newly detected platforms, blocked from now on until the visitor consents (not shown in the popup until you confirm them on the Platforms tab): %s', 'ds-cookie-consent' ),
				implode( ', ', $summary['new_platforms'] )
			);
		}
		$body .= "\n\n" . SettingsPage::url( CookiesTab::TAB, array( 'status' => 'new' ) ) . "\n";

		$sent = (bool) wp_mail( $to, $subject, $body );
		if ( $sent ) {
			update_option( self::OPTION, $now, false );
		}
		return $sent;
	}
}
