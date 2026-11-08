<?php
/**
 * Category rows with toggles. Used by the bar popup (with vendor details) and the card preferences view.
 *
 * @var array  $vm      View model.
 * @var array  $args    variant: 'modal' (details + vendors) or 'card' (toggles only).
 * @package DSolutions\CookieConsent
 */

use DSolutions\CookieConsent\Frontend\Template;
use DSolutions\CookieConsent\Frontend\Text;

defined( 'ABSPATH' ) || exit;

$variant = isset( $args['variant'] ) ? $args['variant'] : 'modal';
?>
<?php foreach ( $vm['categories'] as $cat ) : ?>
	<?php
	$has_details = 'modal' === $variant && ! empty( $cat['services'] );
	$title_id    = 'dscc-cat-' . $cat['key'] . '-t';
	$desc_id     = 'dscc-cat-' . $cat['key'] . '-d';
	?>
	<div class="dscc-cat" data-dscc-cat="<?php echo esc_attr( $cat['key'] ); ?>">
		<div class="dscc-cat__row">
			<div class="dscc-cat__main">
				<div class="dscc-cat__title" id="<?php echo esc_attr( $title_id ); ?>" data-dscc-t="cat_<?php echo esc_attr( $cat['key'] ); ?>"><?php echo Text::html( $vm, 'cat_' . $cat['key'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside Text. ?></div>
				<p class="dscc-cat__desc" id="<?php echo esc_attr( $desc_id ); ?>" data-dscc-t="cat_<?php echo esc_attr( $cat['key'] ); ?>_desc"><?php echo Text::html( $vm, 'cat_' . $cat['key'] . '_desc' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside Text. ?></p>
				<?php if ( $has_details ) : ?>
					<button type="button" class="dscc-link" data-dscc-action="toggle-details" aria-expanded="false" aria-controls="dscc-d-<?php echo esc_attr( $cat['key'] ); ?>">
						<span data-dscc-t="details"><?php echo Text::html( $vm, 'details' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside Text. ?></span>
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m6 9 6 6 6-6"/></svg>
					</button>
				<?php endif; ?>
			</div>
			<?php if ( $cat['required'] ) : ?>
				<span class="dscc-always" data-dscc-t="always_on"><?php echo Text::html( $vm, 'always_on' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside Text. ?></span>
			<?php else : ?>
				<label class="dscc-switch">
					<input type="checkbox" role="switch" data-dscc-category="<?php echo esc_attr( $cat['key'] ); ?>" aria-labelledby="<?php echo esc_attr( $title_id ); ?>" aria-describedby="<?php echo esc_attr( $desc_id ); ?>">
					<span class="dscc-switch__track" aria-hidden="true"></span>
				</label>
			<?php endif; ?>
		</div>
		<?php if ( $has_details ) : ?>
			<div class="dscc-vendors" id="dscc-d-<?php echo esc_attr( $cat['key'] ); ?>" hidden>
				<?php Template::render( 'partials/vendor-list.php', $vm, array( 'cat' => $cat ) ); ?>
			</div>
		<?php endif; ?>
	</div>
<?php endforeach; ?>
