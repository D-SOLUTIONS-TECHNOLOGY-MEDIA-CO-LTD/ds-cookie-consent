<?php
/**
 * Content tab: policy links and all visitor texts (vi/en) with restore-default buttons.
 *
 * @var array $s Content settings.
 * @package DSolutions\CookieConsent
 */

use DSolutions\CookieConsent\Admin\FieldRenderer as F;
use DSolutions\CookieConsent\Settings\DefaultTexts;
use DSolutions\CookieConsent\Settings\Settings;

defined( 'ABSPATH' ) || exit;

$g = 'content';
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- values are escaped by esc_url_raw()/wp_dropdown_pages() and at output time.
$restore     = static function ( $lang ) {
	return esc_url_raw( add_query_arg( 'dscc_restore_nonce', wp_create_nonce( 'dscc_restore_texts' ), admin_url( 'admin-post.php?action=dscc_restore_texts&lang=' . rawurlencode( $lang ) ) ) );
};
$page_select = static function ( $key, $selected ) {
	return wp_dropdown_pages(
		array(
			'name'              => 'dscc[content][' . $key . ']',
			'id'                => 'dscc-content-' . $key,
			'selected'          => (int) $selected,
			'show_option_none'  => __( '— None —', 'ds-cookie-consent' ),
			'option_none_value' => '0',
			'echo'              => 0,
		)
	);
};
// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
?>
<div class="dscc-split">
	<div class="dscc-split__form">
		<h2><?php esc_html_e( 'Policy links', 'ds-cookie-consent' ); ?></h2>
		<table class="form-table" role="presentation">
			<?php
			F::row( __( 'Cookie Policy page', 'ds-cookie-consent' ), 'dscc-content-cookie_policy_page_id', $page_select( 'cookie_policy_page_id', $s['cookie_policy_page_id'] ) );
			F::row( __( 'or enter the Cookie Policy URL', 'ds-cookie-consent' ), 'dscc-content-cookie_policy_url', F::input( $g, 'cookie_policy_url', $s['cookie_policy_url'], 'url', array( 'class' => 'regular-text' ) ), __( 'The page selected above takes priority.', 'ds-cookie-consent' ) );
			F::row( __( 'Privacy Policy page', 'ds-cookie-consent' ), 'dscc-content-privacy_page_id', $page_select( 'privacy_page_id', $s['privacy_page_id'] ) );
			F::row( __( 'or enter the Privacy Policy URL', 'ds-cookie-consent' ), 'dscc-content-privacy_url', F::input( $g, 'privacy_url', $s['privacy_url'], 'url', array( 'class' => 'regular-text' ) ), __( 'If not configured, the Privacy policy tab is not shown in the popup.', 'ds-cookie-consent' ) );
			F::row(
				__( 'How the policy appears in the popup', 'ds-cookie-consent' ),
				'dscc-content-policy_embed_mode',
				F::select(
					$g,
					'policy_embed_mode',
					$s['policy_embed_mode'],
					array(
						'iframe' => __( 'Embed the page (iframe, same-domain pages only)', 'ds-cookie-consent' ),
						'link'   => __( 'Only show a button that opens the page', 'ds-cookie-consent' ),
					)
				)
			);
			?>
		</table>

		<h2><?php esc_html_e( 'Text shown to visitors', 'ds-cookie-consent' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Leave empty to use the default text (shown faded in the field). You can use {site} (site name) and {policy_link} (link to the Cookie Policy).', 'ds-cookie-consent' ); ?></p>
		<?php foreach ( DefaultTexts::LANGS as $lang ) : ?>
			<?php
			$defaults  = DefaultTexts::all( $lang );
			$overrides = Settings::text_overrides( $lang );
			?>
			<details class="dscc-texts"<?php echo 'vi' === $lang ? ' open' : ''; ?>>
				<summary><?php echo esc_html( 'vi' === $lang ? __( 'Vietnamese', 'ds-cookie-consent' ) : __( 'English', 'ds-cookie-consent' ) ); ?></summary>
				<p><a class="button" href="<?php echo esc_url( $restore( $lang ) ); ?>"><?php esc_html_e( 'Restore default texts', 'ds-cookie-consent' ); ?></a></p>
				<table class="form-table" role="presentation">
					<?php foreach ( $defaults as $key => $default ) : ?>
						<tr>
							<th scope="row"><label for="dscc-t-<?php echo esc_attr( $lang . '-' . $key ); ?>"><code><?php echo esc_html( $key ); ?></code></label></th>
							<td><textarea class="large-text" rows="<?php echo strlen( $default ) > 90 ? 3 : 1; ?>" id="dscc-t-<?php echo esc_attr( $lang . '-' . $key ); ?>" name="dscc_texts[<?php echo esc_attr( $lang ); ?>][<?php echo esc_attr( $key ); ?>]" placeholder="<?php echo esc_attr( $default ); ?>" data-dscc-text="<?php echo esc_attr( $lang . '.' . $key ); ?>"><?php echo esc_textarea( isset( $overrides[ $key ] ) ? $overrides[ $key ] : '' ); ?></textarea></td>
						</tr>
					<?php endforeach; ?>
				</table>
			</details>
		<?php endforeach; ?>
		<p><a class="button" href="<?php echo esc_url( $restore( 'all' ) ); ?>"><?php esc_html_e( 'Restore default texts (both languages)', 'ds-cookie-consent' ); ?></a></p>
		<?php submit_button( __( 'Save changes', 'ds-cookie-consent' ) ); ?>
	</div>
	<div class="dscc-split__preview">
		<?php require DSCC_DIR . 'templates/admin/preview-panel.php'; ?>
	</div>
</div>
