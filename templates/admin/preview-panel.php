<?php
/**
 * Live preview panel (iframe fed by the real frontend template).
 *
 * @package DSolutions\CookieConsent
 */

defined( 'ABSPATH' ) || exit;
?>
<aside class="dscc-preview" data-dscc-preview aria-label="<?php esc_attr_e( 'Live preview', 'ds-cookie-consent' ); ?>">
	<div class="dscc-preview__tools">
		<div class="dscc-preview__group" role="group" aria-label="<?php esc_attr_e( 'Preview screen', 'ds-cookie-consent' ); ?>">
			<button type="button" class="button button-small is-active" data-dscc-preview-view="banner"><?php esc_html_e( 'Banner', 'ds-cookie-consent' ); ?></button>
			<button type="button" class="button button-small" data-dscc-preview-view="prefs"><?php esc_html_e( 'Preferences', 'ds-cookie-consent' ); ?></button>
			<button type="button" class="button button-small" data-dscc-preview-view="widget"><?php esc_html_e( 'Icon', 'ds-cookie-consent' ); ?></button>
		</div>
		<div class="dscc-preview__group" role="group" aria-label="<?php esc_attr_e( 'Frame size', 'ds-cookie-consent' ); ?>">
			<button type="button" class="button button-small is-active" data-dscc-preview-device="desktop"><?php esc_html_e( 'Desktop', 'ds-cookie-consent' ); ?></button>
			<button type="button" class="button button-small" data-dscc-preview-device="mobile"><?php esc_html_e( 'Mobile 390px', 'ds-cookie-consent' ); ?></button>
		</div>
	</div>
	<div class="dscc-preview__stage" data-dscc-preview-stage>
		<iframe class="dscc-preview__frame" title="<?php esc_attr_e( 'Cookie banner preview', 'ds-cookie-consent' ); ?>" data-dscc-preview-frame></iframe>
	</div>
	<p class="description"><?php esc_html_e( 'The preview updates as you change colours, text or logo, without saving.', 'ds-cookie-consent' ); ?></p>
</aside>
