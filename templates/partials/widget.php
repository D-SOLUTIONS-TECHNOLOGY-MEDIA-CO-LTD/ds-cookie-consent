<?php
/**
 * Minimised shield widget (shared by both layouts).
 *
 * @var array $vm View model.
 * @package DSolutions\CookieConsent
 */

defined( 'ABSPATH' ) || exit;

if ( empty( $vm['widget'] ) ) {
	return;
}
?>
<button type="button" class="dscc-widget" data-dscc-action="open-prefs"<?php echo \DSolutions\CookieConsent\Frontend\Text::attr( $vm, 'aria-label', 'widget_label' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside Text. ?> hidden>
	<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><path d="M12 1 3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4Zm-1 16-4-4 1.41-1.41L11 14.17l6.59-6.59L19 9l-8 8Z"/></svg>
	<span class="dscc-widget__label" data-dscc-t="widget_label"><?php echo \DSolutions\CookieConsent\Frontend\Text::html( $vm, 'widget_label' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside Text. ?></span>
</button>
