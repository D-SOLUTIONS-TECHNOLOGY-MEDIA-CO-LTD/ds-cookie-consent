<?php
/**
 * Cookie tab: status views, filters, the cookie table with bulk actions, and the add-cookie form.
 *
 * @var \DSolutions\CookieConsent\Admin\CookieListTable $table    List table (items loaded).
 * @var array                                           $filters  Active filters.
 * @var array                                           $counts   Status => count.
 * @var array<string,string>                            $services Service slug => name.
 * @var string                                          $base     Tab URL.
 * @var array                                           $scan       Scan panel data.
 * @var array                                           $compliance Compliance report.
 * @package DSolutions\CookieConsent
 */

use DSolutions\CookieConsent\Admin\CatalogLabels;

defined( 'ABSPATH' ) || exit;

$status_labels   = CatalogLabels::statuses();
$category_labels = CatalogLabels::categories_with_unclassified();
$source_labels   = CatalogLabels::sources();
$total           = array_sum( $counts );
?>
<?php require DSCC_DIR . 'templates/admin/scan-panel.php'; ?>
<?php require DSCC_DIR . 'templates/admin/compliance-block.php'; ?>
<div class="dscc-cookies" data-dscc-qe-nonce="<?php echo esc_attr( wp_create_nonce( 'dscc_cookie_quick_edit' ) ); ?>" data-dscc-ajax="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>">
	<ul class="subsubsub">
		<li><a href="<?php echo esc_url( $base ); ?>"<?php echo '' === $filters['status'] ? ' class="current"' : ''; ?>><?php esc_html_e( 'All', 'ds-cookie-consent' ); ?> <span class="count">(<?php echo (int) $total; ?>)</span></a> |</li>
		<?php foreach ( $status_labels as $key => $label ) : ?>
			<li><a href="<?php echo esc_url( add_query_arg( 'status', $key, $base ) ); ?>"<?php echo $key === $filters['status'] ? ' class="current"' : ''; ?>><?php echo esc_html( $label ); ?> <span class="count">(<?php echo (int) $counts[ $key ]; ?>)</span></a><?php echo 'ignored' === $key ? '' : ' |'; ?></li>
		<?php endforeach; ?>
	</ul>

	<form method="get" class="dscc-filter" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
		<input type="hidden" name="page" value="dscc-settings">
		<input type="hidden" name="tab" value="cookies">
		<?php if ( '' !== $filters['status'] ) : ?>
			<input type="hidden" name="status" value="<?php echo esc_attr( $filters['status'] ); ?>">
		<?php endif; ?>
		<label for="dscc-f-category" class="screen-reader-text"><?php esc_html_e( 'Category', 'ds-cookie-consent' ); ?></label>
		<select name="category" id="dscc-f-category">
			<option value=""><?php esc_html_e( 'Every category', 'ds-cookie-consent' ); ?></option>
			<?php foreach ( $category_labels as $key => $label ) : ?>
				<option value="<?php echo esc_attr( $key ); ?>"<?php selected( $filters['category'], $key ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
		<label for="dscc-f-source" class="screen-reader-text"><?php esc_html_e( 'Source', 'ds-cookie-consent' ); ?></label>
		<select name="source" id="dscc-f-source">
			<option value=""><?php esc_html_e( 'Every source', 'ds-cookie-consent' ); ?></option>
			<?php foreach ( $source_labels as $key => $label ) : ?>
				<option value="<?php echo esc_attr( $key ); ?>"<?php selected( $filters['source'], $key ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
		<label for="dscc-f-search" class="screen-reader-text"><?php esc_html_e( 'Search cookies', 'ds-cookie-consent' ); ?></label>
		<input type="search" name="s" id="dscc-f-search" value="<?php echo esc_attr( $filters['search'] ); ?>" placeholder="<?php esc_attr_e( 'Search by cookie name or vendor', 'ds-cookie-consent' ); ?>">
		<button type="submit" class="button"><?php esc_html_e( 'Filter', 'ds-cookie-consent' ); ?></button>
	</form>

	<form method="post" action="<?php echo esc_url( $base ); ?>" id="dscc-cookies-form">
		<?php $table->display(); ?>
	</form>

	<template id="dscc-qe-categories">
		<option value=""><?php esc_html_e( '— Unclassified —', 'ds-cookie-consent' ); ?></option>
		<?php foreach ( CatalogLabels::categories() as $key => $label ) : ?>
			<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
		<?php endforeach; ?>
	</template>

	<hr>
	<h2><?php esc_html_e( 'Add a cookie manually', 'ds-cookie-consent' ); ?></h2>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="dscc-cookie-add-form">
		<input type="hidden" name="action" value="dscc_cookie_add">
		<?php wp_nonce_field( 'dscc_cookie_add' ); ?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="dscc-ca-name"><?php esc_html_e( 'Cookie name', 'ds-cookie-consent' ); ?></label></th>
				<td>
					<input type="text" class="regular-text" id="dscc-ca-name" name="dscc_cookie[name]" required maxlength="100">
					<p class="description"><?php esc_html_e( 'Use * for the variable part, for example _ga_* or ttcsid*.', 'ds-cookie-consent' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="dscc-ca-storage"><?php esc_html_e( 'Storage type', 'ds-cookie-consent' ); ?></label></th>
				<td>
					<select id="dscc-ca-storage" name="dscc_cookie[storage]">
						<?php foreach ( \DSolutions\CookieConsent\Catalog\CookieRepository::STORAGES as $storage ) : ?>
							<option value="<?php echo esc_attr( $storage ); ?>"><?php echo esc_html( $storage ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="dscc-ca-service"><?php esc_html_e( 'Vendor', 'ds-cookie-consent' ); ?></label></th>
				<td>
					<select id="dscc-ca-service" name="dscc_cookie[service]">
						<option value=""><?php esc_html_e( '— Choose a category below —', 'ds-cookie-consent' ); ?></option>
						<?php foreach ( $services as $slug => $name ) : ?>
							<option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $name ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="dscc-ca-category"><?php esc_html_e( 'Category (when no vendor is chosen)', 'ds-cookie-consent' ); ?></label></th>
				<td>
					<select id="dscc-ca-category" name="dscc_cookie[category]">
						<?php foreach ( CatalogLabels::categories() as $key => $label ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="dscc-ca-domain"><?php esc_html_e( 'Domain (optional)', 'ds-cookie-consent' ); ?></label></th>
				<td><input type="text" class="regular-text" id="dscc-ca-domain" name="dscc_cookie[domain]" maxlength="64" placeholder=".example.com"></td>
			</tr>
			<tr>
				<th scope="row"><label for="dscc-ca-purpose"><?php esc_html_e( 'Purpose (vi / en)', 'ds-cookie-consent' ); ?></label></th>
				<td>
					<textarea class="large-text" rows="2" id="dscc-ca-purpose" name="dscc_cookie[purpose]"></textarea>
					<textarea class="large-text" rows="2" id="dscc-ca-purpose-en" name="dscc_cookie[purpose_en]" aria-label="<?php esc_attr_e( 'Purpose (English)', 'ds-cookie-consent' ); ?>"></textarea>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="dscc-ca-duration"><?php esc_html_e( 'Duration (vi / en)', 'ds-cookie-consent' ); ?></label></th>
				<td>
					<input type="text" class="regular-text" id="dscc-ca-duration" name="dscc_cookie[duration_label]" maxlength="120" placeholder="<?php echo esc_attr__( 'e.g. 180 ngày (Vietnamese text)', 'ds-cookie-consent' ); ?>">
					<input type="text" class="regular-text" id="dscc-ca-duration-en" name="dscc_cookie[duration_label_en]" maxlength="120" placeholder="180 days" aria-label="<?php esc_attr_e( 'Duration (English)', 'ds-cookie-consent' ); ?>">
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="dscc-ca-applies"><?php esc_html_e( 'Applies to (vi / en)', 'ds-cookie-consent' ); ?></label></th>
				<td>
					<input type="text" class="regular-text" id="dscc-ca-applies" name="dscc_cookie[applies_to]" maxlength="255">
					<input type="text" class="regular-text" id="dscc-ca-applies-en" name="dscc_cookie[applies_to_en]" maxlength="255" aria-label="<?php esc_attr_e( 'Applies to (English)', 'ds-cookie-consent' ); ?>">
					<p class="description"><?php esc_html_e( 'Optional. If empty, the policy table shows the cookie\'s domain or the site domain.', 'ds-cookie-consent' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="dscc-ca-group"><?php esc_html_e( 'Row group in the policy table', 'ds-cookie-consent' ); ?></label></th>
				<td>
					<input type="text" class="regular-text" id="dscc-ca-group" name="dscc_cookie[table_group]" maxlength="60">
					<p class="description"><?php esc_html_e( 'Optional. Cookies of one group share a row (names joined by commas); the purpose, duration and where they apply come from the first cookie.', 'ds-cookie-consent' ); ?></p>
				</td>
			</tr>
		</table>
		<?php submit_button( __( 'Add cookie', 'ds-cookie-consent' ), 'secondary', 'submit', true, array( 'id' => 'dscc-cookie-add' ) ); ?>
	</form>
</div>
