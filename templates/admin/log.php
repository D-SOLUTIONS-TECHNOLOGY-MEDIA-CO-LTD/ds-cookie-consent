<?php
/**
 * Consent log screen.
 *
 * @var array  $filters Active filters.
 * @var array  $result  rows + total.
 * @var int    $page    Current page.
 * @var int    $pages   Page count.
 * @var string $export  Export URL.
 * @var array  $choices Valid choice values.
 * @package DSolutions\CookieConsent
 */

use DSolutions\CookieConsent\Admin\LogPage;

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap dscc-admin">
	<h1><?php esc_html_e( 'Cookie Consent - Log', 'ds-cookie-consent' ); ?></h1>
	<p><?php esc_html_e( 'Each row is one visitor choice on the banner. The data is anonymous: the IP is masked and not tied to a name or email. Times are UTC.', 'ds-cookie-consent' ); ?></p>

	<form method="get" class="dscc-filter">
		<input type="hidden" name="page" value="<?php echo esc_attr( LogPage::SLUG ); ?>">
		<label><?php esc_html_e( 'From date', 'ds-cookie-consent' ); ?> <input type="date" name="date_from" value="<?php echo esc_attr( $filters['date_from'] ); ?>"></label>
		<label><?php esc_html_e( 'To date', 'ds-cookie-consent' ); ?> <input type="date" name="date_to" value="<?php echo esc_attr( $filters['date_to'] ); ?>"></label>
		<label><?php esc_html_e( 'Choice', 'ds-cookie-consent' ); ?>
			<select name="choice">
				<option value=""><?php esc_html_e( 'All', 'ds-cookie-consent' ); ?></option>
				<?php foreach ( $choices as $c ) : ?>
					<option value="<?php echo esc_attr( $c ); ?>"<?php selected( $filters['choice'], $c ); ?>><?php echo esc_html( LogPage::choice_label( $c ) ); ?></option>
				<?php endforeach; ?>
			</select>
		</label>
		<button type="submit" class="button"><?php esc_html_e( 'Filter', 'ds-cookie-consent' ); ?></button>
		<a class="button button-primary" href="<?php echo esc_url( $export ); ?>"><?php esc_html_e( 'Export CSV', 'ds-cookie-consent' ); ?></a>
	</form>

	<p>
		<?php
		/* translators: %d: number of rows */
		echo esc_html( sprintf( __( 'Records: %d.', 'ds-cookie-consent' ), $result['total'] ) );
		?>
	</p>
	<table class="widefat striped dscc-log-table">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Time (UTC)', 'ds-cookie-consent' ); ?></th>
				<th><?php esc_html_e( 'Choice', 'ds-cookie-consent' ); ?></th>
				<th><?php esc_html_e( 'Analytics', 'ds-cookie-consent' ); ?></th>
				<th><?php esc_html_e( 'Marketing', 'ds-cookie-consent' ); ?></th>
				<th><?php esc_html_e( 'Source', 'ds-cookie-consent' ); ?></th>
				<th><?php esc_html_e( 'Consent ID', 'ds-cookie-consent' ); ?></th>
				<th><?php esc_html_e( 'Version', 'ds-cookie-consent' ); ?></th>
				<th><?php esc_html_e( 'Language', 'ds-cookie-consent' ); ?></th>
				<th><?php esc_html_e( 'Page', 'ds-cookie-consent' ); ?></th>
				<th><?php esc_html_e( 'IP (masked)', 'ds-cookie-consent' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $result['rows'] ) ) : ?>
				<tr><td colspan="10"><?php esc_html_e( 'No data yet.', 'ds-cookie-consent' ); ?></td></tr>
			<?php endif; ?>
			<?php foreach ( $result['rows'] as $row ) : ?>
				<tr>
					<td><?php echo esc_html( $row['created_at'] ); ?></td>
					<td data-choice="<?php echo esc_attr( $row['choice'] ); ?>"><?php echo esc_html( LogPage::choice_label( $row['choice'] ) ); ?></td>
					<td><?php echo $row['analytics'] ? esc_html__( 'On', 'ds-cookie-consent' ) : esc_html__( 'Off', 'ds-cookie-consent' ); ?></td>
					<td><?php echo $row['marketing'] ? esc_html__( 'On', 'ds-cookie-consent' ) : esc_html__( 'Off', 'ds-cookie-consent' ); ?></td>
					<td><?php echo esc_html( $row['src'] ); ?></td>
					<td><code><?php echo esc_html( $row['consent_id'] ); ?></code></td>
					<td><?php echo esc_html( $row['policy_version'] ); ?></td>
					<td><?php echo esc_html( $row['lang'] ); ?></td>
					<td><?php echo esc_html( $row['page_url'] ); ?></td>
					<td><?php echo esc_html( $row['ip_masked'] ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<?php if ( $pages > 1 ) : ?>
		<div class="tablenav"><div class="tablenav-pages">
			<?php
			echo wp_kses_post(
				paginate_links(
					array(
						'base'      => add_query_arg( 'paged', '%#%' ),
						'format'    => '',
						'current'   => $page,
						'total'     => $pages,
						'prev_text' => '&laquo;',
						'next_text' => '&raquo;',
					)
				)
			);
			?>
		</div></div>
	<?php endif; ?>
</div>
