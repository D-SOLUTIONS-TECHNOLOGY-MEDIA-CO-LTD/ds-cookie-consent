<?php
/**
 * Last headless deep scan inside the compliance block: time, site, pages, banner and the violations of each phase.
 *
 * @var array $c Output of ComplianceReport::build().
 * @package DSolutions\CookieConsent
 */

use DSolutions\CookieConsent\Admin\CatalogLabels;
use DSolutions\CookieConsent\Admin\SettingsPage;

defined( 'ABSPATH' ) || exit;

$deep    = $c['deep'];
$labels  = CatalogLabels::categories_with_unclassified();
$reasons = array(
	'no_pages'  => __( 'no page could be loaded', 'ds-cookie-consent' ),
	'no_banner' => __( 'no Reject / Accept button found on the banner', 'ds-cookie-consent' ),
	'no_plugin' => __( 'the page has no DS Cookie Consent (window.DSCC)', 'ds-cookie-consent' ),
);
$days    = max( 0, (int) floor( ( time() - (int) strtotime( $deep['finished_at'] . ' UTC' ) ) / DAY_IN_SECONDS ) );
$phases  = array(
	'before_choice' => __( 'When the visitor has not chosen', 'ds-cookie-consent' ),
	'after_reject'  => __( 'After the visitor rejected', 'ds-cookie-consent' ),
);
?>
<div class="dscc-deep" id="dscc-deep-scan">
	<h3><?php esc_html_e( 'Latest deep scan (headless browser)', 'ds-cookie-consent' ); ?></h3>
	<p id="dscc-deep-summary">
		<?php
		echo esc_html(
			sprintf(
				/* translators: 1: date and time, 2: site address, 3: number of pages, 4: yes/no for banner */
				__( '%1$s - %2$s - %3$d pages - banner: %4$s.', 'ds-cookie-consent' ),
				get_date_from_gmt( $deep['finished_at'], 'd/m/Y H:i' ),
				$deep['site'],
				$deep['pages'],
				$deep['banner_found'] ? __( 'found', 'ds-cookie-consent' ) : __( 'not found', 'ds-cookie-consent' )
			)
		);
		?>
	</p>
	<p id="dscc-deep-age">
		<?php
		echo esc_html(
			0 === $days ? __( 'Scanned today.', 'ds-cookie-consent' ) : sprintf(
				/* translators: %d: days */
				__( 'Scanned %d days ago.', 'ds-cookie-consent' ),
				$days
			)
		);
		?>
	</p>
	<?php if ( ! $deep['conclusive'] ) : ?>
		<p class="notice notice-warning inline" id="dscc-deep-inconclusive">
			<?php
			echo esc_html(
				sprintf(
					/* translators: %s: reason */
					__( 'The scan was inconclusive: %s. Its "clean" result is not counted and the older deep scan alerts are kept.', 'ds-cookie-consent' ),
					isset( $reasons[ $deep['reason'] ] ) ? $reasons[ $deep['reason'] ] : $deep['reason']
				)
			);
			?>
		</p>
	<?php elseif ( ! $deep['count'] ) : ?>
		<p class="dscc-deep__ok" id="dscc-deep-ok"><?php esc_html_e( 'No Analytics / Marketing cookie or service appeared before the visitor consented.', 'ds-cookie-consent' ); ?></p>
	<?php endif; ?>
	<?php if ( $deep['platforms'] ) : ?>
		<p id="dscc-deep-platforms">
			<?php esc_html_e( 'New platforms created by the scan:', 'ds-cookie-consent' ); ?>
			<?php foreach ( $deep['platforms'] as $platform ) : ?>
				<a class="dscc-badge" href="<?php echo esc_url( SettingsPage::url( 'platforms' ) ); ?>" data-dscc-deep-platform="<?php echo esc_attr( $platform['slug'] ); ?>"><?php echo esc_html( $platform['name'] ); ?></a>
			<?php endforeach; ?>
		</p>
	<?php endif; ?>
	<?php foreach ( $phases as $phase => $title ) : ?>
		<?php if ( $deep['violations'][ $phase ] ) : ?>
			<h4 id="dscc-deep-<?php echo esc_attr( $phase ); ?>"><?php echo esc_html( $title ); ?></h4>
			<ul class="dscc-compliance__list">
				<?php foreach ( $deep['violations'][ $phase ] as $item ) : ?>
					<li data-dscc-deep-violation="<?php echo esc_attr( $item['name'] ); ?>">
						<code><?php echo esc_html( $item['name'] ); ?></code> -
						<?php echo esc_html( isset( $labels[ $item['category'] ] ) ? $labels[ $item['category'] ] : $item['category'] ); ?>
						<?php if ( 'host' === $item['storage'] ) : ?>
							<em><?php esc_html_e( 'third-party servers', 'ds-cookie-consent' ); ?></em>
						<?php elseif ( 'cookie' !== $item['storage'] ) : ?>
							<em><?php echo esc_html( $item['storage'] ); ?></em>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	<?php endforeach; ?>
	<?php
	$pings = array();
	foreach ( $deep['warnings'] as $phase_list ) {
		foreach ( $phase_list as $item ) {
			$pings[ $item['name'] ] = $item;
		}
	}
	?>
	<?php if ( $pings ) : ?>
		<div class="notice notice-info inline" id="dscc-deep-warnings">
			<p><?php esc_html_e( 'Warning: before consent the browser still contacted these Google servers, but no Google cookie was set. This is usually a cookieless Google Consent Mode ping and is not counted as a violation. To avoid any contact before consent, choose "Block GTM too" in the Google Tag Manager mode on the Blocking tab.', 'ds-cookie-consent' ); ?></p>
			<ul class="dscc-compliance__list">
				<?php foreach ( $pings as $item ) : ?>
					<li data-dscc-deep-warning="<?php echo esc_attr( $item['name'] ); ?>">
						<code><?php echo esc_html( $item['name'] ); ?></code> -
						<?php echo esc_html( isset( $labels[ $item['category'] ] ) ? $labels[ $item['category'] ] : $item['category'] ); ?>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
	<?php endif; ?>
</div>
