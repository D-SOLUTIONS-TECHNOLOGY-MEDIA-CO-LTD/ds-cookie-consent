<?php
/**
 * Scan panel of the Cookie tab.
 *
 * @var array $scan Output of ScanPanel::data().
 * @package DSolutions\CookieConsent
 */

defined( 'ABSPATH' ) || exit;

$last   = $scan['last'];
$labels = array(
	'html'   => __( 'in HTML', 'ds-cookie-consent' ),
	'gtm'    => __( 'detected through GTM', 'ds-cookie-consent' ),
	'server' => __( 'cookie set by the server', 'ds-cookie-consent' ),
	'beacon' => __( 'from visitors', 'ds-cookie-consent' ),
	'deep'   => __( 'deep scan', 'ds-cookie-consent' ),
);
?>
<div class="dscc-scan-panel" id="dscc-scan-panel"<?php echo $scan['running'] ? ' data-dscc-running="' . esc_attr( (string) $scan['running']['id'] ) . '"' : ''; ?>>
	<p>
		<button type="button" class="button button-primary" id="dscc-scan-start"><?php esc_html_e( 'Scan now', 'ds-cookie-consent' ); ?></button>
		<span class="description"><?php esc_html_e( 'Loads the site\'s pages like a new visitor (nothing chosen yet), records the cookies the server sets and detects platforms in the HTML and GTM containers.', 'ds-cookie-consent' ); ?></span>
	</p>
	<div class="dscc-scan-progress" id="dscc-scan-progress" role="status" aria-live="polite" hidden>
		<progress id="dscc-scan-bar" max="100" value="0"></progress>
		<span id="dscc-scan-text"></span>
	</div>
	<p id="dscc-scan-last">
		<?php if ( $last ) : ?>
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: date and time, 2: pages loaded, 3: cookies found, 4: new cookies */
					__( 'Latest scan: %1$s - %2$d pages, %3$d cookies set by the server, %4$d new cookies.', 'ds-cookie-consent' ),
					get_date_from_gmt( (string) $last['finished_at'], 'd/m/Y H:i' ),
					max( 0, (int) $last['summary']['done'] - (int) $last['summary']['failed'] ),
					(int) $last['summary']['cookies'],
					(int) $last['summary']['new_cookies']
				)
			);
			?>
		<?php else : ?>
			<?php esc_html_e( 'No scan has run yet.', 'ds-cookie-consent' ); ?>
		<?php endif; ?>
		<?php if ( $scan['deep'] ) : ?>
			<?php
			echo esc_html(
				sprintf(
					/* translators: %s: date and time */
					__( 'Latest deep scan: %s.', 'ds-cookie-consent' ),
					get_date_from_gmt( \DSolutions\CookieConsent\Scanner\ScanRunRepository::finished_gmt( $scan['deep'] ), 'd/m/Y H:i' )
				)
			);
			?>
		<?php endif; ?>
	</p>

	<?php if ( ! empty( $scan['dropped'] ) ) : ?>
		<p class="notice notice-warning inline" id="dscc-scan-dropped">
			<?php
			echo esc_html(
				sprintf(
					/* translators: %d: number of findings */
					__( 'Findings from visitors dropped because the review queue is full: %d. Classify or ignore some new cookies (server scan results are not subject to this limit).', 'ds-cookie-consent' ),
					(int) $scan['dropped']
				)
			);
			?>
		</p>
	<?php endif; ?>
	<?php if ( $scan['platforms'] ) : ?>
		<p><strong><?php esc_html_e( 'Platforms detected on the site:', 'ds-cookie-consent' ); ?></strong></p>
		<ul class="dscc-scan-platforms" id="dscc-scan-platforms">
			<?php foreach ( $scan['platforms'] as $platform ) : ?>
				<li>
					<?php echo esc_html( $platform['name'] ); ?>
					<span class="description">(
					<?php
					echo esc_html(
						implode(
							', ',
							array_map(
								static function ( $via ) use ( $labels ) {
									return isset( $labels[ $via ] ) ? $labels[ $via ] : $via;
								},
								$platform['via']
							)
						)
					);
					?>
					)</span>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
	<?php if ( $scan['unknown'] ) : ?>
		<p class="description" id="dscc-scan-unknown">
			<?php
			echo esc_html(
				sprintf(
					/* translators: %s: list of host names */
					__( 'Unrecognised third-party domains (not blocked; add a custom platform if they are tracking tools): %s', 'ds-cookie-consent' ),
					implode( ', ', array_slice( $scan['unknown'], 0, 12 ) )
				)
			);
			?>
		</p>
	<?php endif; ?>
</div>
