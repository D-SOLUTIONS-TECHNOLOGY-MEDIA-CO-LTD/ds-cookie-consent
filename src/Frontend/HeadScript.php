<?php
/**
 * Inline head script (priority 0): Consent Mode v2 defaults, skip rules and the boot object.
 * Hard limit: 1500 bytes including the config, so it can run before any tag manager at no cost.
 * Consent regions are never dropped (a region without a default would count as granted); the settings schema
 * caps them at SettingsSchema::MAX_REGIONS so they always fit. User-agent skip patterns that do not fit the
 * budget are enforced later by the main script from #dscc-data.
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Frontend;

use DSolutions\CookieConsent\Settings\Settings;

defined( 'ABSPATH' ) || exit;

final class HeadScript {

	/** Maximum size of the finished script body, in bytes. */
	const MAX_BYTES = 1500;

	/**
	 * Script body; %CFG% is replaced with the JSON config.
	 * Written compact on purpose. Cookie format: {v,id,t,c,s,src}; a cookie without a valid timestamp counts as expired.
	 */
	const TEMPLATE = <<<'JS'
(function(C){var w=window,d=document,L=w.dataLayer=w.dataLayer||[],k=0,c=0,o,t,m,G='granted',g=function(b){return b?G:'denied'};w.gtag=w.gtag||function(){L.push(arguments)};
try{k=C.u&&new RegExp(C.u,'i').test(navigator.userAgent)}catch(e){}
try{m=!k&&d.cookie.match(new RegExp('(?:^|; )'+C.n+'=([^;]+)'));o=m&&JSON.parse(decodeURIComponent(m[1]));t=o&&o.id&&o.v===C.v&&+o.t;if(t&&t+C.d*864e2>Date.now()/1e3){c=o.c&&{a:!!o.c.analytics,m:!!o.c.marketing,s:o.s||{}};if(c)c.id=o.id}}catch(e){c=0}
var A=!!(c&&c.a),M=!!(c&&c.m),X=g(M),D={analytics_storage:g(A),ad_storage:X,ad_user_data:X,ad_personalization:X,functionality_storage:G,security_storage:G,wait_for_update:500};
if(C.r)D.region=C.r.split(',');gtag('consent','default',D);if(!M)gtag('set','ads_data_redaction',true);if(C.p)gtag('set','url_passthrough',true);
if(k)d.documentElement.className+=' dscc-skip';w.DSCC_BOOT={skip:!!k,consent:c||null,cfg:C};
if(c)L.push({event:'dscc_consent_update',dscc_analytics:A,dscc_marketing:M,dscc_services:c.s});
})(%CFG%);
JS;

	/**
	 * Register hooks.
	 */
	public static function register() {
		add_action( 'wp_head', array( __CLASS__, 'print_script' ), 0 );
	}

	/**
	 * Config object consumed by the inline script and exposed as DSCC_BOOT.cfg:
	 * n cookie name, v policy version, d days, u user-agent regex (as many user patterns as fit),
	 * r comma separated consent regions, p url_passthrough flag. The bot pattern is not part of it:
	 * crawlers carry no consent cookie, so the main script handles them from #dscc-data.
	 *
	 * Regions are always included in full, before user patterns, because they change the Consent Mode defaults.
	 *
	 * @param array $advanced Advanced settings group.
	 * @return array
	 */
	public static function config( array $advanced ) {
		$cfg  = array(
			'n' => (string) $advanced['cookie_name'],
			'v' => (string) $advanced['policy_version'],
			'd' => (int) $advanced['consent_days'],
			'u' => '',
			'r' => '',
			'p' => empty( $advanced['url_passthrough'] ) ? 0 : 1,
		);
		$room = self::MAX_BYTES - strlen( str_replace( '%CFG%', '', self::TEMPLATE ) );

		$cfg['r'] = implode( ',', (array) $advanced['consent_regions'] );
		foreach ( (array) $advanced['skip_ua_patterns'] as $pattern ) {
			$candidate      = $cfg;
			$candidate['u'] = '' === $cfg['u'] ? $pattern : $cfg['u'] . '|' . $pattern;
			if ( strlen( self::encode( $candidate ) ) <= $room ) {
				$cfg = $candidate;
			}
		}
		return $cfg;
	}

	/**
	 * Size in bytes of the script the current advanced settings produce.
	 *
	 * @param array $advanced Advanced settings group.
	 * @return int
	 */
	public static function size( array $advanced ) {
		return strlen( self::build( self::config( $advanced ) ) );
	}

	/**
	 * True when the script no longer fits its budget. Nothing is dropped to make it fit (a missing region would count
	 * as granted); the admin is told instead.
	 *
	 * @param array $advanced Advanced settings group.
	 * @return bool
	 */
	public static function over_budget( array $advanced ) {
		return self::size( $advanced ) > self::MAX_BYTES;
	}

	/**
	 * The final script body for the given config.
	 *
	 * @param array $cfg Output of config().
	 * @return string
	 */
	public static function build( array $cfg ) {
		return str_replace( '%CFG%', self::encode( $cfg ), self::TEMPLATE );
	}

	/**
	 * JSON with HEX flags so "</script>" and HTML specials stay out of the inline script.
	 *
	 * @param array $cfg Config.
	 * @return string
	 */
	private static function encode( array $cfg ) {
		return (string) wp_json_encode( $cfg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES );
	}

	/**
	 * Print the script on every front-end request that can load tracking tags.
	 */
	public static function print_script() {
		if ( ! Renderer::should_print_head() ) {
			return;
		}
		$advanced = Settings::group( 'advanced' );
		$script   = self::build( self::config( $advanced ) );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON is encoded with HEX flags; the template is a constant.
		echo '<script data-no-optimize="1" data-no-defer="1" data-cfasync="false" data-dscc-ignore id="dscc-boot">' . $script . "</script>\n";
	}
}
