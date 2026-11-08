<?php
/**
 * Compliance alerts block (overview screen and Cookie tab).
 *
 * @var array $compliance Output of ComplianceReport::build().
 * @package DSolutions\CookieConsent
 */

use DSolutions\CookieConsent\Admin\CatalogLabels;
use DSolutions\CookieConsent\Admin\CookiesTab;
use DSolutions\CookieConsent\Admin\SettingsPage;

defined( 'ABSPATH' ) || exit;

$c      = $compliance;
$labels = CatalogLabels::categories_with_unclassified();
$bad    = $c['total'] > 0;
?>
<section class="dscc-compliance notice <?php echo $bad ? 'notice-error' : 'notice-success'; ?> inline" id="dscc-compliance">
	<h2><?php esc_html_e( 'Compliance alerts', 'ds-cookie-consent' ); ?></h2>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="dscc-quick-form">
		<input type="hidden" name="action" value="dscc_quick_setup">
		<?php wp_nonce_field( 'dscc_quick_setup' ); ?>
	</form>
	<p class="dscc-mode" id="dscc-mode">
		<?php esc_html_e( 'Unclassified cookies:', 'ds-cookie-consent' ); ?>
		<span class="dscc-badge dscc-badge--<?php echo esc_attr( $c['mode'] ); ?>" id="dscc-mode-badge"><?php echo 'block' === $c['mode'] ? esc_html__( 'Blocking', 'ds-cookie-consent' ) : esc_html__( 'Report only', 'ds-cookie-consent' ); ?></span>
		<a href="<?php echo esc_url( SettingsPage::url( 'blocking' ) . '#dscc-blocking-unclassified_cookie_mode' ); ?>" id="dscc-mode-link"><?php esc_html_e( 'Change the mode on the Script blocking tab', 'ds-cookie-consent' ); ?></a>
	</p>
	<?php if ( $c['new_capped'] ) : ?>
		<p class="notice notice-warning inline" id="dscc-cw-capped"><?php esc_html_e( 'The list of unknown cookies being blocked has reached the limit of 100 cookies; newer cookies are not blocked yet. Classify or ignore some cookies on the Cookies tab.', 'ds-cookie-consent' ); ?></p>
	<?php endif; ?>
	<?php if ( ! $bad ) : ?>
		<p id="dscc-compliance-ok"><?php echo $c['scan'] || $c['held'] || $c['deep'] ? esc_html__( 'No alerts from recent scans.', 'ds-cookie-consent' ) : esc_html__( 'No alerts yet. Run "Scan now" on the Cookies tab to check.', 'ds-cookie-consent' ); ?></p>
	<?php endif; ?>

	<?php if ( $c['before_consent'] ) : ?>
		<h3 id="dscc-cw-before"><?php esc_html_e( 'Cookies set before the visitor consented', 'ds-cookie-consent' ); ?></h3>
		<ul class="dscc-compliance__list">
			<?php foreach ( $c['before_consent'] as $item ) : ?>
				<?php $qs_row = 'unclassified' === $item['category'] && ! empty( $item['id'] ) && current_user_can( 'manage_options' ); ?>
				<li data-dscc-cookie-name="<?php echo esc_attr( $item['name'] ); ?>"<?php echo $qs_row ? ' data-dscc-cookie-id="' . (int) $item['id'] . '"' : ''; ?>>
					<code><?php echo esc_html( $item['name'] ); ?></code> - <?php echo esc_html( $labels[ $item['category'] ] ); ?>
					<?php
					if ( $qs_row ) {
						$qs_id   = (int) $item['id'];
						$qs_name = $item['name'];
						include DSCC_DIR . 'templates/admin/compliance-quick-actions.php';
					}
					?>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>

	<?php if ( $c['leaks'] ) : ?>
		<h3 id="dscc-cw-leaks"><?php esc_html_e( 'Cookies leaking after the visitor rejected', 'ds-cookie-consent' ); ?></h3>
		<ul class="dscc-compliance__list">
			<?php foreach ( $c['leaks'] as $leak ) : ?>
				<li data-dscc-leak="<?php echo esc_attr( $leak['name'] ); ?>">
					<code><?php echo esc_html( $leak['name'] ); ?></code> - <?php echo esc_html( isset( $labels[ $leak['category'] ] ) ? $labels[ $leak['category'] ] : $leak['category'] ); ?>
					<em><?php echo 'beacon' === $leak['source'] ? esc_html__( 'Reported by visitors', 'ds-cookie-consent' ) : esc_html__( 'Reported by the deep scan', 'ds-cookie-consent' ); ?></em>
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: number of sightings, 2: date */
							__( '(%1$d times, latest %2$s)', 'ds-cookie-consent' ),
							(int) $leak['count'],
							wp_date( 'd/m/Y H:i', (int) $leak['last'] )
						)
					);
					?>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php if ( current_user_can( 'manage_options' ) ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="dscc_alerts_clear">
				<?php wp_nonce_field( 'dscc_alerts_clear' ); ?>
				<button type="submit" class="button" id="dscc-alerts-clear"><?php esc_html_e( 'Clear leak alerts (after you have fixed them)', 'ds-cookie-consent' ); ?></button>
			</form>
		<?php endif; ?>
	<?php endif; ?>

	<?php
	if ( $c['deep'] ) {
		include DSCC_DIR . 'templates/admin/compliance-deep-scan.php';
	}
	?>

	<?php if ( $c['unclassified_n'] > 0 ) : ?>
	<div id="dscc-cw-unclassified-box">
		<h3 id="dscc-cw-unclassified">
			<?php
			echo esc_html(
				sprintf(
					/* translators: %d: number of unclassified cookies */
					_n( '%d unclassified cookie', '%d unclassified cookies', $c['unclassified_n'], 'ds-cookie-consent' ),
					$c['unclassified_n']
				)
			);
			?>
		</h3>
		<p class="description"><?php echo 'block' === $c['mode'] ? esc_html__( 'Blocked like the Marketing category and hidden from visitors until you classify them.', 'ds-cookie-consent' ) : esc_html__( 'Report only: the cookies are still sent and are not shown to visitors yet. Choose a category for each row below; Analytics and Marketing will be blocked until the visitor consents.', 'ds-cookie-consent' ); ?>
			<a href="<?php echo esc_url( SettingsPage::url( CookiesTab::TAB, array( 'category' => 'unclassified' ) ) ); ?>"><?php esc_html_e( 'Classify now', 'ds-cookie-consent' ); ?></a></p>
		<?php if ( current_user_can( 'manage_options' ) ) : ?>
			<p class="dscc-qs-bulk" id="dscc-quick-bulk">
				<label for="dscc-quick-action" class="screen-reader-text"><?php esc_html_e( 'Actions for the selected cookies', 'ds-cookie-consent' ); ?></label>
				<select name="qs_action" id="dscc-quick-action" form="dscc-quick-form">
					<option value=""><?php esc_html_e( 'Actions for the selected rows...', 'ds-cookie-consent' ); ?></option>
					<option value="necessary"><?php esc_html_e( 'Necessary', 'ds-cookie-consent' ); ?></option>
					<option value="analytics"><?php esc_html_e( 'Analytics (blocked until consent)', 'ds-cookie-consent' ); ?></option>
					<option value="marketing"><?php esc_html_e( 'Marketing (blocked until consent)', 'ds-cookie-consent' ); ?></option>
					<option value="keep"><?php esc_html_e( 'Always keep', 'ds-cookie-consent' ); ?></option>
					<option value="ignore"><?php esc_html_e( 'Ignored', 'ds-cookie-consent' ); ?></option>
				</select>
				<button type="submit" form="dscc-quick-form" class="button" id="dscc-quick-apply"><?php esc_html_e( 'Apply', 'ds-cookie-consent' ); ?></button>
				<span class="description" id="dscc-quick-status" role="status"></span>
			</p>
		<?php endif; ?>
		<ul class="dscc-compliance__list">
			<?php foreach ( $c['unclassified'] as $row ) : ?>
				<li data-dscc-unclassified-name="<?php echo esc_attr( $row['name'] ); ?>" data-dscc-cookie-id="<?php echo (int) $row['id']; ?>">
					<code><?php echo esc_html( $row['name'] ); ?></code>
					<?php
					if ( current_user_can( 'manage_options' ) ) {
						$qs_id   = (int) $row['id'];
						$qs_name = $row['name'];
						include DSCC_DIR . 'templates/admin/compliance-quick-actions.php';
					}
					?>
				</li>
			<?php endforeach; ?>
		</ul>
	</div>
	<?php endif; ?>

	<?php if ( $c['not_in_policy'] ) : ?>
		<h3 id="dscc-cw-policy"><?php esc_html_e( 'Cookies present on the site but missing from the policy page', 'ds-cookie-consent' ); ?></h3>
		<ul class="dscc-compliance__list">
			<?php foreach ( $c['not_in_policy'] as $name ) : ?>
				<li><code><?php echo esc_html( $name ); ?></code></li>
			<?php endforeach; ?>
		</ul>
	<?php elseif ( 'no_page' === $c['policy_state'] ) : ?>
		<p class="description" id="dscc-cw-policy-missing"><?php esc_html_e( 'No Cookie policy page is selected on the Content tab, so cookies cannot be compared with the policy yet.', 'ds-cookie-consent' ); ?></p>
	<?php endif; ?>

	<?php if ( $c['held'] ) : ?>
		<p class="description" id="dscc-cw-held">
			<?php
			echo esc_html(
				sprintf(
					/* translators: %s: cookie names */
					__( 'Held back by the server cookie filter, a visitor who has not consented does not receive them: %s', 'ds-cookie-consent' ),
					implode( ', ', wp_list_pluck( $c['held'], 'name' ) )
				)
			);
			?>
		</p>
	<?php endif; ?>
</section>
