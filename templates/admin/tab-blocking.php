<?php
/**
 * Blocking tab.
 *
 * @var array      $s           Blocking settings.
 * @var array|null $perf        Slow page warning (ms, bytes, path, time) or null.
 * @var array|null $failopen    Latest fail-open record (reason, path, time) or null.
 * @var array      $observed    Observe-only report (items by service).
 * @var array[]    $integration Integration rows (label, detected, note).
 * @var array[]    $platforms   Services with script patterns (not ignored, not necessary).
 * @var string     $reset_url   URL (with nonce) that clears the observe report.
 * @package DSolutions\CookieConsent
 */

use DSolutions\CookieConsent\Admin\FieldRenderer as F;
use DSolutions\CookieConsent\Admin\SettingsPage;
use DSolutions\CookieConsent\Blocker\PerfMonitor;

defined( 'ABSPATH' ) || exit;

$g = 'blocking';
?>
<div class="notice notice-info inline" id="dscc-cache-note"><p>
	<?php esc_html_e( 'Page cache limits: blocked scripts in the HTML are identical for every visitor, so the cache does not affect them. Only cookies set by the server (for example _fbp from the Meta Pixel plugin) are filtered when the request reaches PHP; a page already in the cache does not run this filter. The thorough fix is to stop that plugin from setting the cookie in the first place (see the Integrations section below).', 'ds-cookie-consent' ); ?>
</p></div>

<?php if ( $perf ) : ?>
	<div class="notice notice-warning inline" id="dscc-perf-warning"><p>
		<?php
		echo esc_html(
			sprintf(
				/* translators: 1: milliseconds, 2: page path, 3: date and time, 4: limit in milliseconds */
				__( 'The script blocker took %1$s ms (limit %4$d ms) on %2$s at %3$s. Add heavy scripts that do not need blocking to the exclusion list, or turn blocking off for the related platform. Save this tab to measure again.', 'ds-cookie-consent' ),
				number_format_i18n( (float) $perf['ms'], 1 ),
				'' !== $perf['path'] ? $perf['path'] : '/',
				wp_date( 'd/m/Y H:i', (int) $perf['time'] ),
				PerfMonitor::THRESHOLD_MS
			)
		);
		?>
	</p></div>
<?php endif; ?>

<?php if ( $failopen ) : ?>
	<?php
	$reasons = array(
		'pcre'          => __( 'A limit error of the regular expression engine (PCRE) on a page that is too large or has an unusual structure.', 'ds-cookie-consent' ),
		'exception'     => __( 'A PHP error occurred in the blocker.', 'ds-cookie-consent' ),
		'unparsed_tag'  => __( 'An HTML tag had more than 1000 attributes or was too long, so it could not be parsed and was left as is.', 'ds-cookie-consent' ),
		'partial_flush' => __( 'A plugin flushed part of the page before it ended, so the blocker could not process that page.', 'ds-cookie-consent' ),
	);
	?>
	<div class="notice notice-error inline" id="dscc-failopen-warning"><p>
		<?php
		echo esc_html(
			sprintf(
				/* translators: 1: reason, 2: page path, 3: date and time */
				__( 'The blocker let a page through without blocking (the tracking scripts on that page ran before the visitor consented). Reason: %1$s Page: %2$s at %3$s. Save this tab to clear the warning.', 'ds-cookie-consent' ),
				isset( $reasons[ $failopen['reason'] ] ) ? $reasons[ $failopen['reason'] ] : $failopen['reason'],
				'' !== $failopen['path'] ? $failopen['path'] : '/',
				wp_date( 'd/m/Y H:i', (int) $failopen['time'] )
			)
		);
		?>
	</p></div>
<?php endif; ?>

<h2><?php esc_html_e( 'Blocking mode', 'ds-cookie-consent' ); ?></h2>
<table class="form-table" role="presentation">
	<?php
	F::row(
		__( 'Mode', 'ds-cookie-consent' ),
		'dscc-blocking-mode',
		F::select(
			$g,
			'mode',
			$s['mode'],
			array(
				'block'   => __( 'Block scripts before consent', 'ds-cookie-consent' ),
				'observe' => __( 'Observe only (do not block, only record what would be blocked)', 'ds-cookie-consent' ),
			)
		),
		__( 'Run "Observe only" for about a week to see which scripts the blocker would touch before turning real blocking on. In this mode the plugin does not change the HTML, does not delete cookies, and the integrations (Meta Pixel, PixelYourSite) block nothing.', 'ds-cookie-consent' )
	);
	F::row(
		__( 'Google Tag Manager', 'ds-cookie-consent' ),
		'dscc-blocking-gtm_mode',
		F::select(
			$g,
			'gtm_mode',
			$s['gtm_mode'],
			array(
				'consent_mode' => __( 'GTM + Consent Mode (do not block GTM)', 'ds-cookie-consent' ),
				'block'        => __( 'Block GTM too until the visitor consents to Analytics or Marketing', 'ds-cookie-consent' ),
			)
		),
		__( 'Only applies when the Google Tag Manager platform is ticked on the Platforms tab. "GTM + Consent Mode": GTM is loaded up front and only the Google tags inside the container stop on their own through Consent Mode; third-party tags in the container (Meta, TikTok...) only stop if the container sets a trigger on the dscc_consent_update event or on the dscc_analytics / dscc_marketing variables. "Block GTM": GTM is only loaded when the visitor consents to Analytics OR Marketing, so consenting to Analytics alone also runs the Marketing tags in the container: set a trigger condition on each tag.', 'ds-cookie-consent' )
	);
	F::row(
		__( 'Exclusion list', 'ds-cookie-consent' ),
		'dscc-blocking-exclusions',
		F::textarea( $g, 'exclusions', implode( "\n", (array) $s['exclusions'] ), 4 ),
		__( 'One piece of text per line (matched inside script and iframe tags) or a regular expression written between /.../. Matching tags are not blocked. At most 50 lines, 3 to 200 characters each.', 'ds-cookie-consent' )
	);
	F::row( __( 'Unclassified services', 'ds-cookie-consent' ), 'dscc-blocking-block_unclassified', F::checkbox( $g, 'block_unclassified', $s['block_unclassified'], __( 'Block detected third-party services that are not classified yet like the Marketing category', 'ds-cookie-consent' ) ), __( 'Also covers new cookies found by a scan that are not classified yet (WordPress, WooCommerce and PHP cookies are always kept). Unclassified localStorage/sessionStorage keys are not deleted.', 'ds-cookie-consent' ) );
	F::row(
		__( 'Unclassified cookies set by the server', 'ds-cookie-consent' ),
		'dscc-blocking-unclassified_cookie_mode',
		F::select(
			$g,
			'unclassified_cookie_mode',
			$s['unclassified_cookie_mode'],
			array(
				'report' => __( 'Report only (default): list and warn, do not delete cookies', 'ds-cookie-consent' ),
				'block'  => __( 'Block like the Marketing category until you classify them', 'ds-cookie-consent' ),
			)
		),
		__( 'Applies to unknown cookies that a scan saw the server send to a new visitor. "Report only" (default): the cookies are still sent and only appear in the list and compliance alerts for you to classify; safe for the site\'s own cookies (login, cart). "Block": the cookies are removed by the server filter and deleted by the browser until the visitor consents or you classify them as Necessary; stricter, but it can break unclassified own cookies. Cookies that are confirmed or recognised automatically always follow their category, whatever this mode says.', 'ds-cookie-consent' )
	);
	F::row(
		__( 'Cookies to always keep', 'ds-cookie-consent' ),
		'dscc-blocking-keep_cookies',
		F::textarea( $g, 'keep_cookies', implode( "\n", (array) $s['keep_cookies'] ), 3 ),
		__( 'One cookie name or pattern with * per line (for example my_session, shop_*). These cookies are never filtered or deleted, and are placed in the Necessary category when scanning. At most 30 lines, 3 to 64 characters each (letters, digits, _ . - % *).', 'ds-cookie-consent' )
	);
	F::row( __( 'Meta Pixel for WordPress', 'ds-cookie-consent' ), 'dscc-blocking-meta_hold', F::checkbox( $g, 'meta_hold', $s['meta_hold'], __( 'Keep the Meta Pixel plugin on "hold" until the visitor consents to Marketing', 'ds-cookie-consent' ) ), __( 'Only takes effect when the Meta Pixel for WordPress plugin is active. Every page is rendered in the hold state (identical for every visitor, which suits page caching); for a visitor who consented, the browser calls release(), which adds one admin-ajax request per page view. Turn it off to avoid this cost; then only the script blocker and the server cookie filter remain.', 'ds-cookie-consent' ) );
	F::row( __( 'Withdraw consent', 'ds-cookie-consent' ), 'dscc-blocking-reload_on_withdraw', F::checkbox( $g, 'reload_on_withdraw', $s['reload_on_withdraw'], __( 'Reload the page after the visitor withdraws consent (so scripts that already ran stop setting cookies)', 'ds-cookie-consent' ) ) );
	?>
</table>
<?php submit_button( __( 'Save changes', 'ds-cookie-consent' ) ); ?>

<h2><?php esc_html_e( 'Platforms being blocked', 'ds-cookie-consent' ); ?></h2>
<p class="description">
	<?php esc_html_e( 'The "block until consent" switch of each platform is on the Platforms tab.', 'ds-cookie-consent' ); ?>
	<a href="<?php echo esc_url( SettingsPage::url( 'platforms' ) ); ?>"><?php esc_html_e( 'Open the Platforms tab', 'ds-cookie-consent' ); ?></a>
</p>
<?php if ( ! $platforms ) : ?>
	<p id="dscc-no-platforms"><?php esc_html_e( 'No platform has a script pattern yet. Tick platforms on the Platforms tab to start blocking.', 'ds-cookie-consent' ); ?></p>
<?php else : ?>
	<table class="widefat striped dscc-blocking-platforms" id="dscc-blocking-platforms">
		<thead><tr>
			<th><?php esc_html_e( 'Platforms', 'ds-cookie-consent' ); ?></th>
			<th><?php esc_html_e( 'Category', 'ds-cookie-consent' ); ?></th>
			<th><?php esc_html_e( 'Block', 'ds-cookie-consent' ); ?></th>
		</tr></thead>
		<tbody>
			<?php foreach ( $platforms as $p ) : ?>
				<tr data-dscc-service="<?php echo esc_attr( $p['slug'] ); ?>">
					<td><?php echo esc_html( $p['name'] ); ?></td>
					<td><?php echo esc_html( $p['category_key'] ); ?></td>
					<td><?php echo $p['block_enabled'] ? esc_html__( 'Yes', 'ds-cookie-consent' ) : esc_html__( 'No', 'ds-cookie-consent' ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
<?php endif; ?>

<?php if ( 'observe' === $s['mode'] || $observed['items'] ) : ?>
	<h2><?php esc_html_e( 'Observe mode report', 'ds-cookie-consent' ); ?></h2>
	<?php if ( ! $observed['items'] ) : ?>
		<p id="dscc-observed-empty"><?php esc_html_e( 'No script that would be blocked has been recorded yet. The report is written when visitors view pages (pages served from the cache do not run the blocker).', 'ds-cookie-consent' ); ?></p>
	<?php else : ?>
		<table class="widefat striped" id="dscc-observed">
			<thead><tr>
				<th><?php esc_html_e( 'Service', 'ds-cookie-consent' ); ?></th>
				<th><?php esc_html_e( 'Category', 'ds-cookie-consent' ); ?></th>
				<th><?php esc_html_e( 'Page views', 'ds-cookie-consent' ); ?></th>
				<th><?php esc_html_e( 'Example', 'ds-cookie-consent' ); ?></th>
			</tr></thead>
			<tbody>
				<?php foreach ( $observed['items'] as $slug => $item ) : ?>
					<tr data-dscc-service="<?php echo esc_attr( $slug ); ?>">
						<td><?php echo esc_html( $slug ); ?></td>
						<td><?php echo esc_html( $item['category'] ); ?></td>
						<td><?php echo (int) $item['pages']; ?></td>
						<td><code><?php echo esc_html( implode( ' | ', (array) $item['samples'] ) ); ?></code></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p><button type="submit" class="button" name="action" value="dscc_observed_reset" formaction="<?php echo esc_url( $reset_url ); ?>" formnovalidate><?php esc_html_e( 'Clear report', 'ds-cookie-consent' ); ?></button></p>
	<?php endif; ?>
<?php endif; ?>

<h2><?php esc_html_e( 'Integrations', 'ds-cookie-consent' ); ?></h2>
<table class="widefat striped" id="dscc-integrations">
	<thead><tr>
		<th><?php esc_html_e( 'Plugin', 'ds-cookie-consent' ); ?></th>
		<th><?php esc_html_e( 'Status', 'ds-cookie-consent' ); ?></th>
		<th><?php esc_html_e( 'Handling', 'ds-cookie-consent' ); ?></th>
	</tr></thead>
	<tbody>
		<?php foreach ( $integration as $row ) : ?>
			<tr>
				<td><?php echo esc_html( $row['label'] ); ?></td>
				<td><?php echo $row['detected'] ? esc_html__( 'In use', 'ds-cookie-consent' ) : esc_html__( 'Not present', 'ds-cookie-consent' ); ?></td>
				<td><?php echo $row['detected'] ? esc_html( $row['note'] ) : ''; ?></td>
			</tr>
		<?php endforeach; ?>
	</tbody>
</table>
<p class="description"><?php esc_html_e( 'Contact Form 7, Elementor Forms, Cloudflare Turnstile and reCAPTCHA are never blocked. WPML, Polylang and GTranslate only provide the banner language.', 'ds-cookie-consent' ); ?></p>
