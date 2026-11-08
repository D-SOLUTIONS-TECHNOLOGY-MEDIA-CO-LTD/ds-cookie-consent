<?php
/**
 * Quick-setup controls of one unclassified cookie in the compliance report: a checkbox for the bulk action and one
 * button per action. Every control belongs to the form #dscc-quick-form through the `form` attribute, so the report
 * works as plain POST when JavaScript is off.
 *
 * @var int    $qs_id   Cookie id.
 * @var string $qs_name Cookie name.
 * @package DSolutions\CookieConsent
 */

defined( 'ABSPATH' ) || exit;

$qs_buttons = array(
	'necessary' => __( 'Necessary', 'ds-cookie-consent' ),
	'analytics' => __( 'Analytics', 'ds-cookie-consent' ),
	'marketing' => __( 'Marketing', 'ds-cookie-consent' ),
	'keep'      => __( 'Always keep', 'ds-cookie-consent' ),
	'ignore'    => __( 'Ignored', 'ds-cookie-consent' ),
);
?>
<input type="checkbox" name="ids[]" value="<?php echo (int) $qs_id; ?>" form="dscc-quick-form" class="dscc-qs-check" aria-label="<?php echo esc_attr( $qs_name ); ?>">
<span class="dscc-qs-actions">
	<?php foreach ( $qs_buttons as $qs_action => $qs_label ) : ?>
		<button type="submit" form="dscc-quick-form" name="row_action" value="<?php echo esc_attr( $qs_action . ':' . (int) $qs_id ); ?>" class="button button-small dscc-qs-<?php echo esc_attr( $qs_action ); ?>" aria-label="<?php echo esc_attr( $qs_label . ' ' . $qs_name ); ?>"><?php echo esc_html( $qs_label ); ?></button>
	<?php endforeach; ?>
</span>
