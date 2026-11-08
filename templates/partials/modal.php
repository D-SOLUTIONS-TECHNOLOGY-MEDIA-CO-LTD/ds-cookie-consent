<?php
/**
 * Popup markup (rendered inside <template id="dscc-tpl-modal">, cloned on first open).
 * Mode "full" (bar layout): preferences, privacy and cookie policy tabs with Save/Exit.
 * Mode "info" (card layout): privacy and cookie policy tabs only, read-only, with Exit.
 *
 * @var array $vm View model.
 * @package DSolutions\CookieConsent
 */

use DSolutions\CookieConsent\Frontend\Template;
use DSolutions\CookieConsent\Frontend\Text;

defined( 'ABSPATH' ) || exit;

$mode   = $vm['modal_mode'];
$policy = $vm['policy'];
$full   = 'full' === $mode;

// Tabs: key => text key. The privacy tab only exists when a privacy URL is configured.
$tabs = array();
if ( $full ) {
	$tabs['prefs'] = 'tab_prefs';
}
if ( '' !== $policy['privacy_url'] ) {
	$tabs['privacy'] = 'tab_privacy';
}
$tabs['cookies'] = 'tab_cookies';
$first_tab       = key( $tabs );
?>
<div class="dscc-modal" role="dialog" aria-modal="true" aria-labelledby="dscc-modal-title" tabindex="-1" data-dscc-mode="<?php echo esc_attr( $mode ); ?>">
	<div class="dscc-head">
		<div class="dscc-head__left">
			<?php if ( '' !== $vm['logo_popup']['url'] ) : ?>
				<img class="dscc-head__logo" src="<?php echo esc_url( $vm['logo_popup']['url'] ); ?>" alt="<?php echo esc_attr( $vm['site_name'] ); ?>"<?php echo Template::image_size_attrs( $vm['logo_popup'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- integers only. ?>>
			<?php endif; ?>
			<h2 class="dscc-head__title" id="dscc-modal-title" data-dscc-t="modal_title"><?php echo Text::html( $vm, 'modal_title' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside Text. ?></h2>
		</div>
		<button type="button" class="dscc-x" data-dscc-action="close"<?php echo Text::attr( $vm, 'aria-label', 'close' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside Text. ?>>
			<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true" focusable="false"><path d="M6 6l12 12M18 6 6 18"/></svg>
		</button>
	</div>
	<div class="dscc-tabs" role="tablist"<?php echo Text::attr( $vm, 'aria-label', 'modal_title' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside Text. ?>>
		<?php foreach ( $tabs as $key => $label_key ) : ?>
			<button type="button" class="dscc-tab" role="tab" id="dscc-tab-<?php echo esc_attr( $key ); ?>" data-dscc-tab="<?php echo esc_attr( $key ); ?>" aria-controls="dscc-panel-<?php echo esc_attr( $key ); ?>" aria-selected="<?php echo $key === $first_tab ? 'true' : 'false'; ?>"<?php echo $key === $first_tab ? '' : ' tabindex="-1"'; ?> data-dscc-t="tab_<?php echo esc_attr( $key ); ?>"><?php echo Text::html( $vm, $label_key ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside Text. ?></button>
		<?php endforeach; ?>
	</div>
	<div class="dscc-body">
		<?php if ( $full ) : ?>
			<div role="tabpanel" id="dscc-panel-prefs" aria-labelledby="dscc-tab-prefs"<?php echo 'prefs' === $first_tab ? '' : ' hidden'; ?>>
				<h3 class="dscc-intro__title" data-dscc-t="modal_intro_title"><?php echo Text::html( $vm, 'modal_intro_title' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside Text. ?></h3>
				<p class="dscc-intro__text" data-dscc-t="modal_intro_text"><?php echo Text::html( $vm, 'modal_intro_text' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside Text. ?></p>
				<div class="dscc-cats">
					<?php Template::render( 'partials/category-list.php', $vm, array( 'variant' => 'modal' ) ); ?>
				</div>
				<?php if ( $vm['overflow'] > 0 ) : ?>
					<p class="dscc-intro__text dscc-more-vendors">
						<?php echo Text::html( $vm, 'more_vendors', array( '{n}' => (string) $vm['overflow'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside Text. ?>
						<button type="button" class="dscc-link" data-dscc-action="open-tab" data-dscc-tab="cookies"><?php echo Text::html( $vm, 'more_vendors_link' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside Text. ?></button>
					</p>
				<?php endif; ?>
			</div>
		<?php endif; ?>

		<?php if ( isset( $tabs['privacy'] ) ) : ?>
			<div role="tabpanel" id="dscc-panel-privacy" aria-labelledby="dscc-tab-privacy" hidden>
				<?php if ( '' !== $policy['privacy_frame'] ) : ?>
					<iframe class="dscc-frame"<?php echo Text::attr( $vm, 'title', 'policy_frame_title' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside Text. ?> data-dscc-src="<?php echo esc_url( $policy['privacy_frame'] ); ?>" loading="lazy" referrerpolicy="same-origin"></iframe>
				<?php else : ?>
					<div class="dscc-doc">
						<p data-dscc-t="privacy_summary"><?php echo Text::html( $vm, 'privacy_summary' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside Text. ?></p>
						<p><a class="dscc-btn dscc-btn--accept" href="<?php echo esc_url( $policy['privacy_url'] ); ?>" target="_blank" rel="noopener" data-dscc-t="privacy_open"><?php echo Text::html( $vm, 'privacy_open' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside Text. ?></a></p>
					</div>
				<?php endif; ?>
			</div>
		<?php endif; ?>

		<div role="tabpanel" id="dscc-panel-cookies" aria-labelledby="dscc-tab-cookies"<?php echo 'cookies' === $first_tab ? '' : ' hidden'; ?>>
			<?php if ( '' !== $policy['cookie_frame'] ) : ?>
				<iframe class="dscc-frame"<?php echo Text::attr( $vm, 'title', 'policy_frame_title' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside Text. ?> data-dscc-src="<?php echo esc_url( $policy['cookie_frame'] ); ?>" loading="lazy" referrerpolicy="same-origin"></iframe>
			<?php elseif ( '' !== $policy['cookie_url'] ) : ?>
				<div class="dscc-doc">
					<p data-dscc-t="cookie_policy_summary"><?php echo Text::html( $vm, 'cookie_policy_summary' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside Text. ?></p>
					<p><a class="dscc-btn dscc-btn--accept" href="<?php echo esc_url( $policy['cookie_url'] ); ?>" target="_blank" rel="noopener" data-dscc-t="cookie_policy_open"><?php echo Text::html( $vm, 'cookie_policy_open' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside Text. ?></a></p>
				</div>
			<?php else : ?>
				<div class="dscc-doc dscc-ck-all">
					<?php
					$has_cookies = false;
					foreach ( $vm['policy_categories'] as $cat ) {
						foreach ( $cat['services'] as $svc ) {
							if ( empty( $svc['cookies'] ) ) {
								continue;
							}
							$has_cookies = true;
							echo '<h3>' . Text::html( $vm, 'cat_' . $cat['key'] ) . esc_html( ' - ' . $svc['name'] ) . '</h3><div class="dscc-ck">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside Text.
							Template::render(
								'partials/cookie-table.php',
								$vm,
								array(
									'cookies'       => $svc['cookies'],
									'link_provider' => true,
								)
							);
							echo '</div>';
						}
					}
					if ( ! $has_cookies ) {
						echo '<p data-dscc-t="no_cookie_data">' . Text::html( $vm, 'no_cookie_data' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside Text.
					}
					?>
				</div>
			<?php endif; ?>
		</div>
	</div>
	<div class="dscc-foot">
		<?php if ( $full ) : ?>
			<button type="button" class="dscc-btn dscc-btn--save" data-dscc-action="save" data-dscc-t="save"><?php echo Text::html( $vm, 'save' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside Text. ?></button>
		<?php endif; ?>
		<button type="button" class="dscc-btn dscc-btn--exit" data-dscc-action="close" data-dscc-t="exit"><?php echo Text::html( $vm, 'exit' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside Text. ?></button>
	</div>
</div>
