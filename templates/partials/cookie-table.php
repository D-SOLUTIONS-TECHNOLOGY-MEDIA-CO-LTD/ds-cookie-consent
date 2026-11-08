<?php
/**
 * Table of cookies for one service.
 *
 * @var array $vm      View model.
 * @var array $args    cookies: rows with name/duration/purpose pairs and provider; link_provider: link the provider to its policy (new tab, nofollow noopener noreferrer).
 * @package DSolutions\CookieConsent
 */

use DSolutions\CookieConsent\Frontend\Text;
use DSolutions\CookieConsent\Support\LinkHelper;

defined( 'ABSPATH' ) || exit;

$cookies = isset( $args['cookies'] ) ? $args['cookies'] : array();
$link    = ! empty( $args['link_provider'] );
if ( empty( $cookies ) ) {
	return;
}
?>
<table>
	<thead>
		<tr>
			<th scope="col"><?php echo Text::html( $vm, 'th_name' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside Text. ?></th>
			<th scope="col"><?php echo Text::html( $vm, 'th_provider' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside Text. ?></th>
			<th scope="col"><?php echo Text::html( $vm, 'th_duration' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside Text. ?></th>
			<th scope="col"><?php echo Text::html( $vm, 'th_purpose' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside Text. ?></th>
		</tr>
	</thead>
	<tbody>
	<?php foreach ( $cookies as $cookie ) : ?>
		<?php
		$provider = esc_html( $cookie['provider'] );
		if ( $link && '' !== LinkHelper::external_policy_link( $cookie['privacy_url'], $cookie['provider'] ) ) {
			$provider = LinkHelper::external_policy_link( $cookie['privacy_url'], $cookie['provider'] );
		}
		?>
		<tr>
			<td<?php echo Text::attr( $vm, 'data-th', 'th_name' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside Text. ?>><?php echo Text::value( $vm, $cookie['name'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside Text. ?></td>
			<td<?php echo Text::attr( $vm, 'data-th', 'th_provider' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside Text. ?>><?php echo $provider; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?></td>
			<td<?php echo Text::attr( $vm, 'data-th', 'th_duration' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside Text. ?>><?php echo Text::value( $vm, $cookie['duration'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside Text. ?></td>
			<td<?php echo Text::attr( $vm, 'data-th', 'th_purpose' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside Text. ?>><?php echo Text::value( $vm, $cookie['purpose'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside Text. ?></td>
		</tr>
	<?php endforeach; ?>
	</tbody>
</table>
