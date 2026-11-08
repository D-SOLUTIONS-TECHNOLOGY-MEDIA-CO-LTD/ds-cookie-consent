<?php
/**
 * Platform picker tab: grouped grid of known platforms with search and category filter, tick + block switch per
 * platform, custom platform form and known-database refresh.
 *
 * @var array  $groups   Category => platforms.
 * @var array  $labels   Category => label.
 * @var array  $db_info  version, cookies, services, url.
 * @var int    $enabled  Ticked platforms.
 * @package DSolutions\CookieConsent
 */

use DSolutions\CookieConsent\Admin\SettingsPage;
use DSolutions\CookieConsent\Support\LinkHelper;

defined( 'ABSPATH' ) || exit;
?>
<div class="dscc-platforms">
	<p class="description">
		<?php esc_html_e( 'Tick the platforms used on the site. The cookies of the selected platforms enter the cookie catalog (confirmed) and appear in the popup for visitors.', 'ds-cookie-consent' ); ?>
		<?php
		/* translators: 1: database version, 2: number of platforms, 3: number of cookies */
		echo esc_html( sprintf( __( 'Known cookie catalog: version %1$s, %2$d platforms, %3$d cookies.', 'ds-cookie-consent' ), $db_info['version'], $db_info['services'], $db_info['cookies'] ) );
		?>
	</p>

	<div class="dscc-platform-tools">
		<label class="screen-reader-text" for="dscc-platform-search"><?php esc_html_e( 'Search platforms', 'ds-cookie-consent' ); ?></label>
		<input type="search" id="dscc-platform-search" placeholder="<?php esc_attr_e( 'Search platforms...', 'ds-cookie-consent' ); ?>" autocomplete="off">
		<label class="screen-reader-text" for="dscc-platform-category"><?php esc_html_e( 'Filter by category', 'ds-cookie-consent' ); ?></label>
		<select id="dscc-platform-category">
			<option value=""><?php esc_html_e( 'All categories', 'ds-cookie-consent' ); ?></option>
			<?php foreach ( $labels as $key => $label ) : ?>
				<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
		<span class="dscc-platform-count" id="dscc-platform-count" aria-live="polite"></span>
	</div>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="dscc-platforms-form">
		<input type="hidden" name="action" value="dscc_save_platforms">
		<?php wp_nonce_field( 'dscc_save_platforms' ); ?>

		<?php foreach ( $groups as $key => $items ) : ?>
			<?php
			if ( ! $items ) {
				continue;
			}
			?>
			<section class="dscc-platform-group" data-dscc-group="<?php echo esc_attr( $key ); ?>">
				<h3><?php echo esc_html( $labels[ $key ] ); ?> <span class="dscc-platform-group__n">(<?php echo (int) count( $items ); ?>)</span></h3>
				<div class="dscc-platform-cards">
					<?php foreach ( $items as $p ) : ?>
						<?php $fid = 'dscc-pf-' . $p['slug']; ?>
						<div class="dscc-platform<?php echo $p['enabled'] ? ' is-on' : ''; ?>" data-dscc-platform="<?php echo esc_attr( $p['slug'] ); ?>" data-dscc-category="<?php echo esc_attr( $key ); ?>" data-dscc-search="<?php echo esc_attr( strtolower( $p['name'] . ' ' . $p['provider'] . ' ' . $p['slug'] ) ); ?>">
							<input type="hidden" name="dscc_platform_all[]" value="<?php echo esc_attr( $p['slug'] ); ?>">
							<div class="dscc-platform__head">
								<strong class="dscc-platform__name"><?php echo esc_html( $p['name'] ); ?></strong>
								<?php if ( ! empty( $p['detected'] ) ) : ?>
									<span class="dscc-badge dscc-badge--detected"><?php echo array( 'gtm' ) === array_values( $p['detected'] ) ? esc_html__( 'Detected through GTM', 'ds-cookie-consent' ) : esc_html__( 'Detected on the site', 'ds-cookie-consent' ); ?></span>
								<?php endif; ?>
								<?php if ( $p['custom'] ) : ?>
									<span class="dscc-badge"><?php esc_html_e( 'User-defined', 'ds-cookie-consent' ); ?></span>
								<?php endif; ?>
							</div>
							<div class="dscc-platform__meta">
								<?php echo esc_html( $labels[ $key ] ); ?>
								<?php if ( '' !== $p['provider'] ) : ?>
									&middot; <?php echo esc_html( $p['provider'] ); ?>
								<?php endif; ?>
								&middot;
								<?php
								/* translators: %d: number of cookies */
								echo esc_html( sprintf( _n( '%d cookie', '%d cookies', (int) $p['cookies'], 'ds-cookie-consent' ), (int) $p['cookies'] ) );
								?>
								<?php $policy_link = LinkHelper::external_policy_link( $p['privacy_url'], __( 'Policy', 'ds-cookie-consent' ) ); ?>
								<?php if ( '' !== $policy_link ) : ?>
									&middot; <?php echo $policy_link; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside the helper. ?>
								<?php endif; ?>
							</div>
							<label class="dscc-platform__tick" for="<?php echo esc_attr( $fid ); ?>">
								<input type="checkbox" id="<?php echo esc_attr( $fid ); ?>" name="dscc_platform_on[]" value="<?php echo esc_attr( $p['slug'] ); ?>"<?php checked( $p['enabled'] ); ?>>
								<?php esc_html_e( 'In use on the site', 'ds-cookie-consent' ); ?>
							</label>
							<label class="dscc-platform__block" for="<?php echo esc_attr( $fid . '-block' ); ?>">
								<input type="checkbox" id="<?php echo esc_attr( $fid . '-block' ); ?>" name="dscc_platform_block[<?php echo esc_attr( $p['slug'] ); ?>]" value="1"<?php checked( (bool) $p['block'] ); ?><?php disabled( 'necessary' === $key ); ?>>
								<?php esc_html_e( 'Block before the visitor consents', 'ds-cookie-consent' ); ?>
							</label>
						</div>
					<?php endforeach; ?>
				</div>
			</section>
		<?php endforeach; ?>
		<p class="dscc-platform-empty" id="dscc-platform-empty" hidden><?php esc_html_e( 'No matching platform.', 'ds-cookie-consent' ); ?></p>
		<?php submit_button( __( 'Save platform list', 'ds-cookie-consent' ), 'primary', 'submit', true, array( 'id' => 'dscc-platforms-save' ) ); ?>
	</form>

	<hr>
	<h2><?php esc_html_e( 'Add a custom platform', 'ds-cookie-consent' ); ?></h2>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="dscc-custom-platform-form">
		<input type="hidden" name="action" value="dscc_add_platform">
		<?php wp_nonce_field( 'dscc_add_platform' ); ?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="dscc-custom-name"><?php esc_html_e( 'Platform name', 'ds-cookie-consent' ); ?></label></th>
				<td><input type="text" class="regular-text" id="dscc-custom-name" name="dscc_custom[name]" required maxlength="120"></td>
			</tr>
			<tr>
				<th scope="row"><label for="dscc-custom-category"><?php esc_html_e( 'Cookie category', 'ds-cookie-consent' ); ?></label></th>
				<td>
					<select id="dscc-custom-category" name="dscc_custom[category]">
						<?php foreach ( $labels as $key => $label ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>"<?php selected( 'analytics', $key ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="dscc-custom-privacy"><?php esc_html_e( 'Platform policy link', 'ds-cookie-consent' ); ?></label></th>
				<td><input type="url" class="regular-text" id="dscc-custom-privacy" name="dscc_custom[privacy_url]" placeholder="https://"></td>
			</tr>
			<tr>
				<th scope="row"><label for="dscc-custom-hosts"><?php esc_html_e( 'Script hosts', 'ds-cookie-consent' ); ?></label></th>
				<td>
					<textarea class="large-text" rows="3" id="dscc-custom-hosts" name="dscc_custom[hosts]"></textarea>
					<p class="description"><?php esc_html_e( 'One domain per line, for example cdn.littled.example. Used to block the script before the visitor consents.', 'ds-cookie-consent' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="dscc-custom-regex"><?php esc_html_e( 'Script URL regex (optional)', 'ds-cookie-consent' ); ?></label></th>
				<td>
					<textarea class="large-text code" rows="2" id="dscc-custom-regex" name="dscc_custom[src_regex]"></textarea>
					<p class="description"><?php esc_html_e( 'One expression per line, without delimiters and without the ~ character.', 'ds-cookie-consent' ); ?></p>
				</td>
			</tr>
		</table>
		<?php submit_button( __( 'Add platform', 'ds-cookie-consent' ), 'secondary', 'submit', true, array( 'id' => 'dscc-custom-add' ) ); ?>
	</form>

	<hr>
	<h2><?php esc_html_e( 'Update the cookie catalog', 'ds-cookie-consent' ); ?></h2>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="dscc_update_known_db">
		<?php wp_nonce_field( 'dscc_update_known_db' ); ?>
		<p class="description">
			<?php if ( '' === $db_info['url'] ) : ?>
				<?php
				printf(
					/* translators: %s: link to the Advanced tab */
					esc_html__( 'No source URL yet. Enter a URL on the %s tab to enable updates.', 'ds-cookie-consent' ),
					'<a href="' . esc_url( SettingsPage::url( 'advanced' ) ) . '">' . esc_html__( 'Advanced', 'ds-cookie-consent' ) . '</a>'
				);
				?>
			<?php else : ?>
				<?php echo esc_html( sprintf( /* translators: %s: source URL */ __( 'Source: %s. The file is only loaded when its SHA-256 checksum matches.', 'ds-cookie-consent' ), $db_info['url'] ) ); ?>
			<?php endif; ?>
		</p>
		<?php submit_button( __( 'Update the cookie catalog', 'ds-cookie-consent' ), 'secondary', 'submit', true, '' === $db_info['url'] ? array( 'disabled' => 'disabled' ) : array() ); ?>
	</form>
</div>
