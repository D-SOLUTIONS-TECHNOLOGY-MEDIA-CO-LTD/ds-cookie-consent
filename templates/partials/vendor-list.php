<?php
/**
 * Service (vendor) rows inside one category of the preferences popup.
 *
 * @var array $vm  View model.
 * @var array $args Values: cat (category: key, required, services).
 * @package DSolutions\CookieConsent
 */

use DSolutions\CookieConsent\Frontend\Template;
use DSolutions\CookieConsent\Frontend\Text;
use DSolutions\CookieConsent\Support\LinkHelper;

defined( 'ABSPATH' ) || exit;

$cat = $args['cat'];
?>
<?php foreach ( $cat['services'] as $svc ) : ?>
	<?php
	$count = count( $svc['cookies'] );
	$cid   = 'dscc-c-' . $svc['slug'];
	?>
	<div class="dscc-vendor" data-dscc-service-row="<?php echo esc_attr( $svc['slug'] ); ?>">
		<div class="dscc-vendor__main">
			<span class="dscc-vendor__name"><?php echo esc_html( $svc['name'] ); ?></span>
			<span class="dscc-vendor__right">
				<?php if ( $cat['required'] ) : ?>
					<span class="dscc-vendor__on"><?php echo Text::html( $vm, 'always_on' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside Text. ?></span>
				<?php elseif ( $vm['per_service'] && ! empty( $svc['blockable'] ) ) : ?>
					<label class="dscc-switch dscc-switch--sm">
						<input type="checkbox" role="switch" data-dscc-service="<?php echo esc_attr( $svc['slug'] ); ?>"<?php echo Text::attr( $vm, 'aria-label', 'toggle_aria', array( '{name}' => $svc['name'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside Text. ?>>
						<span class="dscc-switch__track" aria-hidden="true"></span>
					</label>
				<?php endif; ?>
				<?php echo LinkHelper::external_policy_link( $svc['privacy_url'], Text::html( $vm, 'vendor_privacy' ), true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside the helper. ?>
				<?php if ( $count > 0 ) : ?>
					<button type="button" class="dscc-link" data-dscc-action="toggle-cookies" aria-expanded="false" aria-controls="<?php echo esc_attr( $cid ); ?>"><?php echo Text::html( $vm, 'view_cookies', array( '{n}' => (string) $count ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside Text. ?></button>
				<?php endif; ?>
			</span>
		</div>
		<?php if ( $count > 0 ) : ?>
			<div class="dscc-ck" id="<?php echo esc_attr( $cid ); ?>" hidden>
				<?php Template::render( 'partials/cookie-table.php', $vm, array( 'cookies' => $svc['cookies'] ) ); ?>
			</div>
		<?php endif; ?>
	</div>
<?php endforeach; ?>
