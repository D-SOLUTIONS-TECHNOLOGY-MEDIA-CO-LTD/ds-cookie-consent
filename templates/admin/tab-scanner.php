<?php
/**
 * Scanner tab.
 *
 * @var array  $s         Scanner settings.
 * @var string $recipient Address the alert email goes to.
 * @package DSolutions\CookieConsent
 */

use DSolutions\CookieConsent\Admin\CookiesTab;
use DSolutions\CookieConsent\Admin\FieldRenderer as F;
use DSolutions\CookieConsent\Admin\SettingsPage;

defined( 'ABSPATH' ) || exit;

$g = 'scanner';
?>
<p class="description">
	<?php esc_html_e( 'The automatic scan detects the cookies and platforms running on the site. The manual scan is on the Cookies tab.', 'ds-cookie-consent' ); ?>
	<a href="<?php echo esc_url( SettingsPage::url( CookiesTab::TAB ) ); ?>"><?php esc_html_e( 'Open the Cookies tab', 'ds-cookie-consent' ); ?></a>
</p>

<h2><?php esc_html_e( 'Server scan', 'ds-cookie-consent' ); ?></h2>
<table class="form-table" role="presentation">
	<?php
	F::row(
		__( 'Automatic scan', 'ds-cookie-consent' ),
		'dscc-scanner-auto_scan',
		F::select(
			$g,
			'auto_scan',
			$s['auto_scan'],
			array(
				'off'    => __( 'Off', 'ds-cookie-consent' ),
				'weekly' => __( 'Once a week (WP-Cron)', 'ds-cookie-consent' ),
			)
		)
	);
	F::row(
		__( 'Maximum pages per run', 'ds-cookie-consent' ),
		'dscc-scanner-max_urls',
		F::input(
			$g,
			'max_urls',
			$s['max_urls'],
			'number',
			array(
				'min'   => 1,
				'max'   => 200,
				'class' => 'small-text',
			)
		),
		__( 'The home page, pages with forms, WooCommerce pages and the 20 latest items of each public content type. The plugin only loads pages of this site itself.', 'ds-cookie-consent' )
	);
	?>
</table>

<h2><?php esc_html_e( 'Collect cookies from visitors', 'ds-cookie-consent' ); ?></h2>
<table class="form-table" role="presentation">
	<?php
	F::row(
		__( 'Passive collection', 'ds-cookie-consent' ),
		'dscc-scanner-beacon_enabled',
		F::checkbox( $g, 'beacon_enabled', $s['beacon_enabled'], __( 'Collect cookies from visitors (beacon)', 'ds-cookie-consent' ) ),
		__( 'Off by default. When on, a small share of visitors who accepted or rejected report to the site, 5 seconds after the page loads, the NAMES of cookies, localStorage/sessionStorage keys and the domains of loaded resources. Cookie values are never sent, no IP address is stored, and nothing is sent from logged-in users. Used to detect cookies set by client-side scripts and cookies that leak after the visitor rejected.', 'ds-cookie-consent' )
	);
	F::row(
		__( 'Sampling rate (%)', 'ds-cookie-consent' ),
		'dscc-scanner-beacon_sample',
		F::input(
			$g,
			'beacon_sample',
			$s['beacon_sample'],
			'number',
			array(
				'min'   => 0,
				'max'   => 100,
				'class' => 'small-text',
			)
		),
		__( 'From 0 to 100. Visitors are picked at random in the browser, so the page HTML stays identical for every visitor and no extra database queries are made.', 'ds-cookie-consent' )
	);
	?>
</table>

<h2><?php esc_html_e( 'Notifications', 'ds-cookie-consent' ); ?></h2>
<table class="form-table" role="presentation">
	<?php
	F::row(
		__( 'Email on new cookies', 'ds-cookie-consent' ),
		'dscc-scanner-notify_enabled',
		F::checkbox( $g, 'notify_enabled', $s['notify_enabled'], __( 'Send an email when a scan finds new cookies', 'ds-cookie-consent' ) ),
		__( 'At most one email per day.', 'ds-cookie-consent' )
	);
	F::row(
		__( 'Recipient address', 'ds-cookie-consent' ),
		'dscc-scanner-notify_email',
		F::input(
			$g,
			'notify_email',
			$s['notify_email'],
			'email',
			array(
				'class'       => 'regular-text',
				'placeholder' => (string) get_option( 'admin_email' ),
			)
		),
		/* translators: %s: email address that is used now */
		sprintf( __( 'Leave empty to use the site admin email. Currently sending to: %s', 'ds-cookie-consent' ), '' !== $recipient ? $recipient : '-' )
	);
	?>
</table>
<?php submit_button( __( 'Save changes', 'ds-cookie-consent' ) ); ?>
