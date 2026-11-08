<?php
/**
 * Layout "card": compact box in a corner with an in-place preferences view, plus the shield widget.
 * Override in a theme as ds-cookie-consent/card.php.
 *
 * @var array $vm View model.
 * @package DSolutions\CookieConsent
 */

use DSolutions\CookieConsent\Frontend\Template;
use DSolutions\CookieConsent\Frontend\Text;
use DSolutions\CookieConsent\Frontend\ViewModel;

defined( 'ABSPATH' ) || exit;

$a   = $vm['settings']['appearance'];
$btn = static function ( $action, $modifier, $key ) use ( $vm ) {
	return '<button type="button" class="dscc-btn ' . esc_attr( $modifier ) . '" data-dscc-action="' . esc_attr( $action ) . '" data-dscc-t="' . esc_attr( $key ) . '">' . Text::html( $vm, $key ) . '</button>';
};

$customize_class = 'button' === $a['customize_style'] ? 'dscc-btn--outline' : 'dscc-btn--text';
$accept          = $btn( 'accept', 'dscc-btn--primary', 'accept_all' );
$reject          = $btn( 'reject', 'dscc-btn--outline', 'reject_all' );
$main_pair       = 'accept_first' === $vm['order'] ? $accept . $reject : $reject . $accept;
$accept_outline  = $btn( 'accept', 'dscc-btn--outline', 'accept_all' );
$prefs_pair      = 'accept_first' === $vm['order'] ? $accept_outline . $reject : $reject . $accept_outline;
?>
<div id="dscc" class="dscc dscc--card" data-layout="card" data-card-pos="<?php echo esc_attr( $a['card_position'] ); ?>" data-widget-side="<?php echo esc_attr( $a['widget_side'] ); ?>" style="<?php echo esc_attr( $vm['style'] ); ?>"<?php echo $vm['dual'] ? ' translate="no"' : ''; ?> hidden>
	<div class="dscc-card" role="dialog" aria-modal="false" aria-labelledby="dscc-card-title" hidden>
		<button type="button" class="dscc-card__x" data-dscc-action="close"<?php echo Text::attr( $vm, 'aria-label', 'close' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside Text. ?> hidden>
			<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true" focusable="false"><path d="M6 6l12 12M18 6 6 18"/></svg>
		</button>

		<div class="dscc-view" data-dscc-view="main">
			<h2 class="dscc-card__title" id="dscc-card-title" data-dscc-t="title"><?php echo Text::html( $vm, 'title' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside Text. ?></h2>
			<p class="dscc-card__text" data-dscc-t-html="text"><?php echo wp_kses_post( ViewModel::message_html( $vm, 'text' ) ); ?></p>
			<div class="dscc-actions">
				<button type="button" class="dscc-btn <?php echo esc_attr( $customize_class ); ?>" data-dscc-action="customize" data-dscc-t="customize"><?php echo Text::html( $vm, 'customize' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside Text. ?></button>
				<span class="dscc-push"></span>
				<?php echo $main_pair; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts above. ?>
			</div>
		</div>

		<div class="dscc-view" data-dscc-view="prefs" hidden>
			<h2 class="dscc-card__title" id="dscc-card-title-prefs" data-dscc-t="prefs_title"><?php echo Text::html( $vm, 'prefs_title' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside Text. ?></h2>
			<p class="dscc-card__text" data-dscc-t="prefs_text"><?php echo Text::html( $vm, 'prefs_text' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside Text. ?></p>
			<div class="dscc-cats">
				<?php Template::render( 'partials/category-list.php', $vm, array( 'variant' => 'card' ) ); ?>
			</div>
			<p class="dscc-card__more"><button type="button" class="dscc-linkbtn" data-dscc-action="open-tab" data-dscc-tab="cookies" data-dscc-t="details_link"><?php echo Text::html( $vm, 'details_link' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside Text. ?></button></p>
			<div class="dscc-actions dscc-actions--prefs">
				<?php echo $btn( 'save', 'dscc-btn--primary', 'save_choices' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php echo $prefs_pair; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</div>
		</div>
	</div>
	<?php Template::render( 'partials/widget.php', $vm ); ?>
	<div class="dscc-overlay" hidden></div>
</div>
