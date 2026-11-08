<?php
/**
 * Overview screen.
 *
 * @var array $windows Counts per window (7, 30 days).
 * @var array $blocked Log requests refused by the rate limiter: today, week.
 * @var array $categories Cookie count per category (ignored cookies left out).
 * @var int   $new        Cookies waiting for review.
 * @var array $compliance Compliance report.
 * @var int   $last_scan  Unix time of the last finished scan (0 = never).
 * @var array|null $perf  Slow page warning or null.
 * @package DSolutions\CookieConsent
 */

use DSolutions\CookieConsent\Admin\CatalogLabels;
use DSolutions\CookieConsent\Admin\CookiesTab;
use DSolutions\CookieConsent\Admin\DashboardPage;
use DSolutions\CookieConsent\Admin\LogPage;
use DSolutions\CookieConsent\Admin\SettingsPage;

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap dscc-admin">
	<h1><?php esc_html_e( 'Cookie Consent - Overview', 'ds-cookie-consent' ); ?></h1>
	<?php if ( $perf ) : ?>
		<div class="notice notice-warning inline" id="dscc-dash-perf"><p>
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: milliseconds, 2: page path */
					__( 'The script blocker took %1$s ms on %2$s. See the Script blocking tab to fix it.', 'ds-cookie-consent' ),
					number_format_i18n( (float) $perf['ms'], 1 ),
					'' !== $perf['path'] ? $perf['path'] : '/'
				)
			);
			?>
		</p></div>
	<?php endif; ?>
	<?php require DSCC_DIR . 'templates/admin/compliance-block.php'; ?>
	<section class="dscc-card-admin dscc-overview" id="dscc-overview">
		<h2><?php esc_html_e( 'Cookies on the site', 'ds-cookie-consent' ); ?></h2>
		<ul class="dscc-legend">
			<?php foreach ( CatalogLabels::categories_with_unclassified() as $key => $label ) : ?>
				<li><?php echo esc_html( $label . ': ' . (int) $categories[ $key ] ); ?></li>
			<?php endforeach; ?>
		</ul>
		<p id="dscc-overview-new">
			<?php
			echo esc_html(
				sprintf(
					/* translators: %d: number of new cookies */
					_n( '%d new cookie is waiting for classification.', '%d new cookies are waiting for classification.', $new, 'ds-cookie-consent' ),
					$new
				)
			);
			?>
			<?php if ( current_user_can( 'manage_options' ) ) : ?>
				<a href="<?php echo esc_url( SettingsPage::url( CookiesTab::TAB, array( 'status' => 'new' ) ) ); ?>"><?php esc_html_e( 'View list', 'ds-cookie-consent' ); ?></a>
			<?php endif; ?>
		</p>
		<p id="dscc-overview-scan">
			<?php
			echo $last_scan
				? esc_html( sprintf( /* translators: %s: date and time */ __( 'Latest cookie scan: %s.', 'ds-cookie-consent' ), wp_date( 'd/m/Y H:i', $last_scan ) ) )
				: esc_html__( 'No cookie scan has run yet.', 'ds-cookie-consent' );
			?>
		</p>
	</section>
	<div class="dscc-cards">
		<?php foreach ( $windows as $days => $counts ) : ?>
			<?php $total = array_sum( $counts ); ?>
			<section class="dscc-card-admin">
				<h2>
					<?php
					/* translators: %d: number of days */
					echo esc_html( sprintf( _n( 'Last %d day', 'Last %d days', $days, 'ds-cookie-consent' ), $days ) );
					?>
				</h2>
				<?php echo DashboardPage::chart( $counts ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- chart() escapes every value. ?>
				<p>
					<?php
					/* translators: %d: number of recorded choices */
					echo esc_html( sprintf( __( 'Total choices recorded: %d.', 'ds-cookie-consent' ), $total ) );
					?>
				</p>
				<ul class="dscc-legend">
					<?php foreach ( $counts as $choice => $n ) : ?>
						<li><?php echo esc_html( LogPage::choice_label( $choice ) . ': ' . $n ); ?><?php echo $total > 0 ? esc_html( ' (' . round( 100 * $n / $total ) . '%)' ) : ''; ?></li>
					<?php endforeach; ?>
				</ul>
			</section>
		<?php endforeach; ?>
	</div>
	<p class="dscc-blocked" id="dscc-blocked">
		<?php
		/* translators: 1: requests refused today, 2: requests refused in the last 7 days */
		echo esc_html( sprintf( __( 'Log requests blocked by the rate limit: %1$d today, %2$d in 7 days.', 'ds-cookie-consent' ), $blocked['today'], $blocked['week'] ) );
		?>
	</p>
	<p>
		<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=' . LogPage::SLUG ) ); ?>"><?php esc_html_e( 'View log', 'ds-cookie-consent' ); ?></a>
		<?php if ( current_user_can( 'manage_options' ) ) : ?>
			<a class="button" href="<?php echo esc_url( SettingsPage::url() ); ?>"><?php esc_html_e( 'Settings', 'ds-cookie-consent' ); ?></a>
		<?php endif; ?>
	</p>
</div>
