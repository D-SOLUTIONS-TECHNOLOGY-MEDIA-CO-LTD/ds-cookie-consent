<?php
/**
 * Overview screen: choice ratios for 7 and 30 days as a hand-drawn SVG bar chart (no external libraries).
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Admin;

use DSolutions\CookieConsent\Blocker\PerfMonitor;
use DSolutions\CookieConsent\Catalog\CookieQuery;
use DSolutions\CookieConsent\Catalog\CookieRepository;
use DSolutions\CookieConsent\Consent\ConsentLogRepository;
use DSolutions\CookieConsent\Consent\RateLimitStats;

defined( 'ABSPATH' ) || exit;

final class DashboardPage {

	/**
	 * Print the screen.
	 */
	public static function render() {
		if ( ! current_user_can( 'dscc_view_log' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'ds-cookie-consent' ), 403 );
		}
		$windows    = array(
			7  => ConsentLogRepository::counts_by_choice( 7 ),
			30 => ConsentLogRepository::counts_by_choice( 30 ),
		);
		$blocked    = array(
			'today' => RateLimitStats::today(),
			'week'  => RateLimitStats::week(),
		);
		$categories = CookieQuery::category_counts();
		$new        = CookieRepository::count_new();
		$compliance = ComplianceReport::build();
		$last_scan  = (int) get_option( 'dscc_last_scan', 0 );
		$perf       = PerfMonitor::warning();
		include DSCC_DIR . 'templates/admin/dashboard.php';
	}

	/**
	 * Inline SVG bar chart for one window.
	 *
	 * @param array<string,int> $counts Counts keyed by choice.
	 * @return string SVG markup (escaped values only).
	 */
	public static function chart( array $counts ) {
		$series = array(
			'accept_all' => '#067647',
			'reject_all' => '#b42318',
			'custom'     => '#005ea3',
		);
		$max    = max( 1, max( array_intersect_key( $counts, $series ) ) );
		$svg    = '<svg class="dscc-chart" viewBox="0 0 300 140" role="img" aria-label="' . esc_attr__( 'Visitor choices chart', 'ds-cookie-consent' ) . '">';
		$x      = 20;
		foreach ( $series as $choice => $color ) {
			$n      = (int) $counts[ $choice ];
			$height = (int) round( 90 * $n / $max );
			$svg   .= '<rect x="' . (int) $x . '" y="' . (int) ( 100 - $height ) . '" width="60" height="' . (int) $height . '" rx="4" fill="' . esc_attr( $color ) . '"></rect>';
			$svg   .= '<text x="' . (int) ( $x + 30 ) . '" y="' . (int) ( 96 - $height ) . '" text-anchor="middle" font-size="12" fill="#1d2939">' . (int) $n . '</text>';
			$svg   .= '<text x="' . (int) ( $x + 30 ) . '" y="120" text-anchor="middle" font-size="11" fill="#475467">' . esc_html( LogPage::choice_label( $choice ) ) . '</text>';
			$x     += 95;
		}
		return $svg . '</svg>';
	}
}
