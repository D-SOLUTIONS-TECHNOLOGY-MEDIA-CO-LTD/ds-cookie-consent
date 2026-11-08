<?php
/**
 * Settings screen shell: notices, tab navigation and the active tab form.
 *
 * @var array  $tabs    Registered tabs.
 * @var string $current Active tab slug.
 * @var string $message Status flag from the redirect.
 * @package DSolutions\CookieConsent
 */

use DSolutions\CookieConsent\Admin\Flash;
use DSolutions\CookieConsent\Admin\SettingsPage;
use DSolutions\CookieConsent\Frontend\HeadScript;
use DSolutions\CookieConsent\Settings\Settings;
use DSolutions\CookieConsent\Settings\SettingsSchema;

defined( 'ABSPATH' ) || exit;

$notices = array(
	'saved'          => __( 'Settings saved.', 'ds-cookie-consent' ),
	'texts_restored' => __( 'Default texts restored.', 'ds-cookie-consent' ),
	'version_bumped' => __( 'Policy version increased: every visitor will be asked again.', 'ds-cookie-consent' ),
);
$errors  = array(
	'css_unbalanced'         => __( 'Custom CSS was not saved: the number of { and } does not match, which could break the whole page style. The previous CSS is kept; the other settings were saved.', 'ds-cookie-consent' ),
	'regions_too_many'       => sprintf(
		/* translators: %d: maximum number of regions */
		__( 'The Consent Mode region list exceeds the limit of %1$d regions: only the first %2$d regions were saved and the extra ones were dropped. The other settings were saved.', 'ds-cookie-consent' ),
		SettingsSchema::MAX_REGIONS,
		SettingsSchema::MAX_REGIONS
	),
	'regions_too_long'       => sprintf(
		/* translators: %d: maximum characters */
		__( 'The Consent Mode region list exceeds the limit of %d characters (codes joined by commas, because the head script is only 1.5 KB): the last regions were dropped. The other settings were saved.', 'ds-cookie-consent' ),
		SettingsSchema::MAX_REGION_CHARS
	),
	'cookie_name_kept'       => sprintf(
		/* translators: %d: maximum length */
		__( 'The consent cookie name is invalid (letters, digits, underscore and hyphen only; at most %d characters): the old name is kept so visitors are not asked again. The other settings were saved.', 'ds-cookie-consent' ),
		SettingsSchema::MAX_COOKIE_NAME
	),
	'known_db_url_not_https' => __( 'The cookie database update URL must start with https://: the previous value is kept. The other settings were saved.', 'ds-cookie-consent' ),
	'css_too_long'           => __( 'Custom CSS was not saved: it is longer than 20,000 characters. The previous CSS is kept; the other settings were saved.', 'ds-cookie-consent' ),
);
?>
<div class="wrap dscc-admin">
	<h1><?php esc_html_e( 'Cookie Consent - Settings', 'ds-cookie-consent' ); ?></h1>

	<?php if ( isset( $notices[ $message ] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notices[ $message ] ); ?></p></div>
	<?php endif; ?>
	<?php if ( isset( $errors[ $message ] ) ) : ?>
		<div class="notice notice-error is-dismissible" id="<?php echo esc_attr( 0 === strpos( $message, 'css_' ) ? 'dscc-css-rejected' : 'dscc-setting-rejected' ); ?>"><p><?php echo esc_html( $errors[ $message ] ); ?></p></div>
	<?php endif; ?>
	<?php foreach ( Flash::take() as $flash ) : ?>
		<div class="notice notice-<?php echo esc_attr( $flash['type'] ); ?> is-dismissible dscc-flash"><p><?php echo esc_html( $flash['message'] ); ?></p></div>
	<?php endforeach; ?>
	<?php foreach ( SettingsPage::contrast_warnings() as $warning ) : ?>
		<div class="notice notice-warning"><p><?php echo esc_html( $warning ); ?></p></div>
	<?php endforeach; ?>

	<?php if ( HeadScript::over_budget( Settings::group( 'advanced' ) ) ) : ?>
		<div class="notice notice-warning" id="dscc-head-over-budget"><p>
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: current size in bytes, 2: limit in bytes */
					__( 'The head script (Consent Mode) is %1$d bytes long, above the recommended %2$d bytes. Nothing was cut; shorten the consent cookie name or the region list.', 'ds-cookie-consent' ),
					HeadScript::size( Settings::group( 'advanced' ) ),
					HeadScript::MAX_BYTES
				)
			);
			?>
		</p></div>
	<?php endif; ?>

	<h2 class="nav-tab-wrapper">
		<?php foreach ( $tabs as $slug => $tab ) : ?>
			<a class="nav-tab<?php echo $slug === $current ? ' nav-tab-active' : ''; ?>" href="<?php echo esc_url( SettingsPage::url( $slug ) ); ?>"><?php echo esc_html( $tab['label'] ); ?></a>
		<?php endforeach; ?>
	</h2>

	<?php if ( isset( $tabs[ $current ]['form'] ) && false === $tabs[ $current ]['form'] ) : ?>
		<?php call_user_func( $tabs[ $current ]['render'] ); // This tab prints its own forms. ?>
	<?php else : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="dscc-form" id="dscc-settings-form">
			<input type="hidden" name="action" value="dscc_save_settings">
			<input type="hidden" name="dscc_tab" value="<?php echo esc_attr( $current ); ?>">
			<?php wp_nonce_field( 'dscc_save_settings' ); ?>
			<?php call_user_func( $tabs[ $current ]['render'] ); ?>
		</form>
	<?php endif; ?>
</div>
