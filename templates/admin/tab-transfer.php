<?php
/**
 * Import / Export tab.
 *
 * @var array      $presets Bundled presets: slug => label, description.
 * @var array|null $pending Stored preview: doc, plan.
 * @var string     $token   Preview token.
 * @package DSolutions\CookieConsent
 */

defined( 'ABSPATH' ) || exit;

$dscc_export_url = wp_nonce_url( admin_url( 'admin-post.php?action=dscc_export' ), 'dscc_export' );
$dscc_format     = static function ( $value ) {
	if ( is_bool( $value ) ) {
		return $value ? 'true' : 'false';
	}
	if ( is_array( $value ) ) {
		return (string) wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}
	return '' === (string) $value ? '""' : (string) $value;
};
?>
<div class="dscc-transfer" id="dscc-transfer">
	<h2><?php esc_html_e( 'Export', 'ds-cookie-consent' ); ?></h2>
	<p class="description"><?php esc_html_e( 'Downloads the settings, text overrides, platforms and cookies as a JSON file to copy this configuration to another site. The consent log is never included.', 'ds-cookie-consent' ); ?></p>
	<p><a class="button" id="dscc-export-download" href="<?php echo esc_url( $dscc_export_url ); ?>"><?php esc_html_e( 'Download configuration', 'ds-cookie-consent' ); ?></a></p>

	<h2><?php esc_html_e( 'Import', 'ds-cookie-consent' ); ?></h2>
	<p class="description"><?php esc_html_e( 'Choose a file or a bundled preset. You see what would change first; nothing is saved until you apply it. Settings in the file overwrite the same settings here, platforms and cookies are added or updated, nothing is deleted and the consent log is not touched.', 'ds-cookie-consent' ); ?></p>
	<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="dscc-import-form">
		<input type="hidden" name="action" value="dscc_import_preview">
		<?php wp_nonce_field( 'dscc_import_preview' ); ?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="dscc-import-file"><?php esc_html_e( 'Export file (.json)', 'ds-cookie-consent' ); ?></label></th>
				<td><input type="file" id="dscc-import-file" name="dscc_import_file" accept="application/json,.json"></td>
			</tr>
			<?php if ( $presets ) : ?>
			<tr>
				<th scope="row"><label for="dscc-import-preset"><?php esc_html_e( 'Or a bundled preset', 'ds-cookie-consent' ); ?></label></th>
				<td>
					<select id="dscc-import-preset" name="dscc_import_preset">
						<option value=""><?php esc_html_e( '— None —', 'ds-cookie-consent' ); ?></option>
						<?php foreach ( $presets as $dscc_slug => $dscc_preset ) : ?>
							<option value="<?php echo esc_attr( $dscc_slug ); ?>"><?php echo esc_html( $dscc_preset['label'] ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'A preset is a ready-made configuration for one site. It is never applied automatically; a file you upload wins over the preset.', 'ds-cookie-consent' ); ?></p>
				</td>
			</tr>
			<?php endif; ?>
		</table>
		<p><button type="submit" class="button button-primary" id="dscc-import-preview-button"><?php esc_html_e( 'Preview import', 'ds-cookie-consent' ); ?></button></p>
	</form>

	<?php if ( $pending ) : ?>
		<?php $dscc_plan = $pending['plan']; ?>
		<div class="dscc-import-preview" id="dscc-import-preview">
			<h2><?php esc_html_e( 'What would change', 'ds-cookie-consent' ); ?></h2>
			<?php if ( ! empty( $pending['doc']['preset']['label'] ) ) : ?>
				<p><strong><?php echo esc_html( $pending['doc']['preset']['label'] ); ?></strong>
				<?php if ( ! empty( $pending['doc']['preset']['description'] ) ) : ?>
					<br><span class="description"><?php echo esc_html( $pending['doc']['preset']['description'] ); ?></span>
				<?php endif; ?>
				</p>
			<?php endif; ?>
			<?php foreach ( $dscc_plan['warnings'] as $dscc_warning ) : ?>
				<div class="notice notice-warning inline"><p><?php echo esc_html( $dscc_warning ); ?></p></div>
			<?php endforeach; ?>
			<table class="widefat striped" id="dscc-import-counts">
				<thead><tr>
					<th scope="col"><?php esc_html_e( 'Item', 'ds-cookie-consent' ); ?></th>
					<th scope="col"><?php esc_html_e( 'New', 'ds-cookie-consent' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Updated', 'ds-cookie-consent' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Unchanged', 'ds-cookie-consent' ); ?></th>
				</tr></thead>
				<tbody>
					<tr data-dscc-row="settings">
						<th scope="row"><?php esc_html_e( 'Settings', 'ds-cookie-consent' ); ?></th>
						<td>—</td>
						<td><?php echo (int) $dscc_plan['settings']['count']; ?></td>
						<td>—</td>
					</tr>
					<tr data-dscc-row="texts">
						<th scope="row"><?php esc_html_e( 'Texts', 'ds-cookie-consent' ); ?></th>
						<td>—</td>
						<td><?php echo (int) array_sum( $dscc_plan['texts'] ); ?></td>
						<td>—</td>
					</tr>
					<tr data-dscc-row="services">
						<th scope="row"><?php esc_html_e( 'Platforms', 'ds-cookie-consent' ); ?></th>
						<td><?php echo (int) $dscc_plan['services']['new']; ?></td>
						<td><?php echo (int) $dscc_plan['services']['updated']; ?></td>
						<td><?php echo (int) $dscc_plan['services']['unchanged']; ?></td>
					</tr>
					<tr data-dscc-row="cookies">
						<th scope="row"><?php esc_html_e( 'Cookies', 'ds-cookie-consent' ); ?></th>
						<td><?php echo (int) $dscc_plan['cookies']['new']; ?></td>
						<td><?php echo (int) $dscc_plan['cookies']['updated']; ?></td>
						<td><?php echo (int) $dscc_plan['cookies']['unchanged']; ?></td>
					</tr>
				</tbody>
			</table>

			<?php if ( $dscc_plan['settings']['changes'] ) : ?>
				<h3><?php esc_html_e( 'Settings that change', 'ds-cookie-consent' ); ?></h3>
				<table class="widefat striped" id="dscc-import-settings">
					<thead><tr>
						<th scope="col"><?php esc_html_e( 'Setting', 'ds-cookie-consent' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Current', 'ds-cookie-consent' ); ?></th>
						<th scope="col"><?php esc_html_e( 'After import', 'ds-cookie-consent' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( array_slice( $dscc_plan['settings']['changes'], 0, 60 ) as $dscc_change ) : ?>
						<tr>
							<th scope="row"><code><?php echo esc_html( $dscc_change['group'] . '.' . $dscc_change['key'] ); ?></code></th>
							<td><code><?php echo esc_html( $dscc_format( $dscc_change['old'] ) ); ?></code></td>
							<td><code><?php echo esc_html( $dscc_format( $dscc_change['new'] ) ); ?></code></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<?php if ( $dscc_plan['services']['names'] ) : ?>
				<p class="description"><?php esc_html_e( 'Platforms added or updated:', 'ds-cookie-consent' ); ?> <?php echo esc_html( implode( ', ', $dscc_plan['services']['names'] ) ); ?></p>
			<?php endif; ?>
			<?php if ( $dscc_plan['cookies']['names'] ) : ?>
				<p class="description"><?php esc_html_e( 'Cookies added or updated:', 'ds-cookie-consent' ); ?> <?php echo esc_html( implode( ', ', $dscc_plan['cookies']['names'] ) ); ?></p>
			<?php endif; ?>

			<?php if ( 0 === (int) $dscc_plan['changes'] ) : ?>
				<p id="dscc-import-nothing"><?php esc_html_e( 'This file would not change anything: the site already has these settings, platforms and cookies.', 'ds-cookie-consent' ); ?></p>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="dscc_import_apply">
					<input type="hidden" name="dscc_import_token" value="<?php echo esc_attr( $token ); ?>">
					<?php wp_nonce_field( 'dscc_import_apply' ); ?>
					<p><button type="submit" class="button button-primary" id="dscc-import-apply"><?php esc_html_e( 'Apply import', 'ds-cookie-consent' ); ?></button></p>
				</form>
			<?php endif; ?>
		</div>
	<?php endif; ?>
</div>
