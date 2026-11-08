<?php
/**
 * Advanced tab.
 *
 * @var array $s Advanced settings.
 * @package DSolutions\CookieConsent
 */

use DSolutions\CookieConsent\Admin\FieldRenderer as F;
use DSolutions\CookieConsent\Settings\SettingsSchema;

defined( 'ABSPATH' ) || exit;

$g    = 'advanced';
$bump = add_query_arg( 'dscc_bump_nonce', wp_create_nonce( 'dscc_bump_version' ), admin_url( 'admin-post.php?action=dscc_bump_version' ) );
?>
<h2><?php esc_html_e( 'Visitor choice', 'ds-cookie-consent' ); ?></h2>
<table class="form-table" role="presentation">
	<?php
	F::row(
		__( 'Policy version', 'ds-cookie-consent' ),
		'dscc-advanced-policy_version',
		F::input(
			$g,
			'policy_version',
			$s['policy_version'],
			'text',
			array(
				'class'     => 'regular-text',
				'maxlength' => 20,
			)
		) . ' <button type="submit" class="button" name="action" value="dscc_bump_version" formaction="' . esc_url( $bump ) . '" formnovalidate>' . esc_html__(
			'Ask every visitor to choose again',
			'ds-cookie-consent'
		) . '</button>',
		__( 'Change this value (or press the button) so every visitor sees the banner again.', 'ds-cookie-consent' )
	);
	F::row(
		__( 'Choice retention (days)', 'ds-cookie-consent' ),
		'dscc-advanced-consent_days',
		F::input(
			$g,
			'consent_days',
			$s['consent_days'],
			'number',
			array(
				'min'   => 1,
				'max'   => 730,
				'class' => 'small-text',
			)
		)
	);
	F::row(
		__( 'Consent cookie name', 'ds-cookie-consent' ),
		'dscc-advanced-cookie_name',
		F::input( $g, 'cookie_name', $s['cookie_name'], 'text', array( 'class' => 'regular-text' ) ), /* translators: %d: maximum length */
		sprintf( __( 'Letters, digits, underscore and hyphen; at most %d characters.', 'ds-cookie-consent' ), SettingsSchema::MAX_COOKIE_NAME )
	);
	F::row(
		__( 'Cookie domain', 'ds-cookie-consent' ),
		'dscc-advanced-cookie_domain',
		F::input(
			$g,
			'cookie_domain',
			$s['cookie_domain'],
			'text',
			array(
				'class'       => 'regular-text',
				'placeholder' => '.example.com',
			)
		),
		__( 'Leave empty for the current host. Set ".example.com" to share it across subdomains.', 'ds-cookie-consent' )
	);
	?>
</table>

<h2><?php esc_html_e( 'Language', 'ds-cookie-consent' ); ?></h2>
<table class="form-table" role="presentation">
	<?php
	F::row(
		__( 'Default language', 'ds-cookie-consent' ),
		'dscc-advanced-default_lang',
		F::select(
			$g,
			'default_lang',
			$s['default_lang'],
			array(
				'vi' => __( 'Vietnamese', 'ds-cookie-consent' ),
				'en' => 'English',
			)
		),
		__( 'Used for every site language other than Vietnamese or English.', 'ds-cookie-consent' )
	);
	F::row(
		__( 'Language detection', 'ds-cookie-consent' ),
		'dscc-advanced-lang_source',
		F::select(
			$g,
			'lang_source',
			$s['lang_source'],
			array(
				'server' => __( 'By site (WordPress, WPML, Polylang)', 'ds-cookie-consent' ),
				'client' => __( 'By browser (the page lang attribute, for GTranslate)', 'ds-cookie-consent' ),
			)
		),
		__( 'When GTranslate is active the browser mode is used automatically: both languages are in the HTML and the browser picks one.', 'ds-cookie-consent' )
	);
	?>
</table>

<h2><?php esc_html_e( 'Skip the banner', 'ds-cookie-consent' ); ?></h2>
<table class="form-table" role="presentation">
	<?php
	F::row( __( 'Skip by User-Agent', 'ds-cookie-consent' ), 'dscc-advanced-skip_ua_patterns', F::textarea( $g, 'skip_ua_patterns', implode( "\n", (array) $s['skip_ua_patterns'] ), 3 ), __( 'One regular expression per line (case-insensitive). On a match no banner is shown, Consent Mode stays denied and nothing is logged.', 'ds-cookie-consent' ) );
	F::row(
		__( 'When the User-Agent matches', 'ds-cookie-consent' ),
		'dscc-advanced-skip_ua_mode',
		F::select(
			$g,
			'skip_ua_mode',
			$s['skip_ua_mode'],
			array(
				'deny'  => __( 'Like a bot: hide the banner, keep tracking scripts blocked, restore embedded videos and maps', 'ds-cookie-consent' ),
				'grant' => __( 'The host app manages consent: run every script as if the visitor pressed Accept all', 'ds-cookie-consent' ),
			)
		),
		__( 'Applies to the User-Agent list above (for example an app webview). "Run every script" writes no consent cookie and no log; choose it only when the app already asked the visitor for consent. Search bots always follow the "like a bot" behaviour.', 'ds-cookie-consent' )
	);
	F::row( __( 'Skip search bots', 'ds-cookie-consent' ), 'dscc-advanced-skip_bots', F::checkbox( $g, 'skip_bots', $s['skip_bots'], __( 'Googlebot, Bingbot and similar bots see no banner and are not logged', 'ds-cookie-consent' ) ) );
	?>
</table>

<h2><?php esc_html_e( 'Third-party widget compatibility', 'ds-cookie-consent' ); ?></h2>
<table class="form-table" role="presentation">
	<?php
	F::row( __( 'Hide elements while the banner is open', 'ds-cookie-consent' ), 'dscc-advanced-hide_selectors', F::textarea( $g, 'hide_selectors', implode( "\n", (array) $s['hide_selectors'] ), 3 ), __( 'One CSS selector per line, for example #chat-widget-root. The elements are hidden while the banner is shown.', 'ds-cookie-consent' ) );
	F::row( __( 'Move the minimised icon automatically', 'ds-cookie-consent' ), 'dscc-advanced-raise_widget', F::checkbox( $g, 'raise_widget', $s['raise_widget'], __( 'Push the icon above the elements in the list above if they occupy the same corner', 'ds-cookie-consent' ) ) );
	?>
</table>

<h2><?php esc_html_e( 'Consent Mode', 'ds-cookie-consent' ); ?></h2>
<table class="form-table" role="presentation">
	<?php
	F::row(
		__( 'Regions where "denied" is the default', 'ds-cookie-consent' ),
		'dscc-advanced-consent_regions',
		F::textarea( $g, 'consent_regions', implode( "\n", (array) $s['consent_regions'] ), 2 ), /* translators: 1: maximum entries, 2: maximum characters */
		sprintf( __( 'Leave empty for every region. One ISO 3166 code per line (for example VN, US-CA). At most %1$d regions and %2$d characters in total (the limit of the head script); the excess is dropped.', 'ds-cookie-consent' ), SettingsSchema::MAX_REGIONS, SettingsSchema::MAX_REGION_CHARS )
	);
	F::row( __( 'URL passthrough', 'ds-cookie-consent' ), 'dscc-advanced-url_passthrough', F::checkbox( $g, 'url_passthrough', $s['url_passthrough'], __( 'Keep advertising information in the URL while there is no cookie consent', 'ds-cookie-consent' ) ) );
	F::row( __( 'Functional category', 'ds-cookie-consent' ), 'dscc-advanced-show_functional', F::checkbox( $g, 'show_functional', $s['show_functional'], __( 'Show the Functional cookie category separately (merged into Necessary by default)', 'ds-cookie-consent' ) ) );
	?>
</table>

<h2><?php esc_html_e( 'Data', 'ds-cookie-consent' ); ?></h2>
<table class="form-table" role="presentation">
	<?php
	F::row(
		__( 'Visitor IP source', 'ds-cookie-consent' ),
		'dscc-advanced-trusted_ip_header',
		F::select(
			$g,
			'trusted_ip_header',
			$s['trusted_ip_header'],
			array(
				'none'             => __( 'Direct connection address (default)', 'ds-cookie-consent' ),
				'cf-connecting-ip' => 'CF-Connecting-IP (Cloudflare)',
				'x-forwarded-for'  => __( 'X-Forwarded-For (first IP)', 'ds-cookie-consent' ),
			)
		),
		__( 'Choose a header only when the site really sits behind that proxy: the header can be forged if the site is reachable directly. Used to mask the IP in the log and to rate-limit per visitor.', 'ds-cookie-consent' )
	);
	F::row(
		__( 'Keep log (months)', 'ds-cookie-consent' ),
		'dscc-advanced-retention_months',
		F::input(
			$g,
			'retention_months',
			$s['retention_months'],
			'number',
			array(
				'min'   => 0,
				'max'   => 120,
				'class' => 'small-text',
			)
		),
		__( '0 = never delete automatically.', 'ds-cookie-consent' )
	);
	F::row(
		__( 'Cookie database update URL', 'ds-cookie-consent' ),
		'dscc-advanced-known_db_url',
		F::input(
			$g,
			'known_db_url',
			$s['known_db_url'],
			'url',
			array(
				'class'       => 'regular-text',
				'placeholder' => 'https://.../known-cookies.json',
			)
		),
		__( 'The https address of known-cookies.json (known-cookies.json.sha256 in the same folder). The update button on the Platforms tab only loads the file when the SHA-256 checksum matches.', 'ds-cookie-consent' )
	);
	F::row( __( 'Delete data on uninstall', 'ds-cookie-consent' ), 'dscc-advanced-delete_data_on_uninstall', F::checkbox( $g, 'delete_data_on_uninstall', $s['delete_data_on_uninstall'], __( 'Also delete the log table when the plugin is uninstalled', 'ds-cookie-consent' ) ) );
	?>
</table>
<?php submit_button( __( 'Save changes', 'ds-cookie-consent' ) ); ?>
