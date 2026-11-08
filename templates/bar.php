<?php
/**
 * Layout "bar": full-width banner at the bottom, popup with three tabs, shield widget.
 * Override in a theme as ds-cookie-consent/bar.php.
 *
 * @var array $vm View model.
 * @package DSolutions\CookieConsent
 */

use DSolutions\CookieConsent\Frontend\Template;
use DSolutions\CookieConsent\Frontend\Text;
use DSolutions\CookieConsent\Frontend\ViewModel;

defined( 'ABSPATH' ) || exit;

$a        = $vm['settings']['appearance'];
$btn_cust = 'button' === $a['customize_style'] ? 'dscc-btn dscc-btn--outline' : 'dscc-btn dscc-btn--prefs';

$buttons = array(
	'accept'    => '<button type="button" class="dscc-btn dscc-btn--accept" data-dscc-action="accept" data-dscc-t="accept">' . Text::html( $vm, 'accept' ) . '</button>',
	'reject'    => '<button type="button" class="dscc-btn dscc-btn--reject" data-dscc-action="reject" data-dscc-t="reject">' . Text::html( $vm, 'reject' ) . '</button>',
	'customize' => '<button type="button" class="' . esc_attr( $btn_cust ) . '" data-dscc-action="customize" data-dscc-t="customize">' . Text::html( $vm, 'customize' ) . '</button>',
);
// Reading order equals visual order: accept_first puts Accept first, accept_last puts it on the far right.
$sequence = 'accept_first' === $vm['order'] ? array( 'accept', 'reject', 'customize' ) : array( 'customize', 'reject', 'accept' );
?>
<div id="dscc" class="dscc dscc--bar" data-layout="bar" data-widget-side="<?php echo esc_attr( $a['widget_side'] ); ?>" style="<?php echo esc_attr( $vm['style'] ); ?>"<?php echo $vm['dual'] ? ' translate="no"' : ''; ?> hidden>
	<section class="dscc-bar" role="region"<?php echo Text::attr( $vm, 'aria-label', 'bar_aria' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside Text. ?> hidden>
		<?php if ( '' !== $vm['logo']['url'] ) : ?>
			<img class="dscc-bar__logo" src="<?php echo esc_url( $vm['logo']['url'] ); ?>" alt="<?php echo esc_attr( $vm['site_name'] ); ?>"<?php echo Template::image_size_attrs( $vm['logo'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- integers only. ?>>
		<?php endif; ?>
		<p class="dscc-bar__msg" data-dscc-t-html="bar_text"><?php echo wp_kses_post( ViewModel::message_html( $vm, 'bar_text' ) ); ?></p>
		<div class="dscc-bar__btns">
			<?php
			foreach ( $sequence as $name ) {
				echo $buttons[ $name ]; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above from escaped parts.
			}
			?>
		</div>
	</section>
	<?php Template::render( 'partials/widget.php', $vm ); ?>
	<div class="dscc-overlay" hidden></div>
</div>
