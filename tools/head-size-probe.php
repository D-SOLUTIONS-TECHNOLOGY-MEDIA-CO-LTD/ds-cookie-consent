<?php
/**
 * Prints the head script size for typical and worst-case configs (used by tools/check-size.mjs).
 * Runs without WordPress: only the two stubs below are needed.
 *
 * Output: one line per case, "<label>=<bytes>".
 */

define( 'ABSPATH', __DIR__ . '/' );

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $flags = 0 ) { // phpcs:ignore
		return json_encode( $data, $flags );
	}
}

require dirname( __DIR__ ) . '/src/Frontend/HeadScript.php';

use DSolutions\CookieConsent\Frontend\HeadScript;

$typical = array(
	'cookie_name'      => 'dscc_consent',
	'policy_version'   => '2026-11',
	'consent_days'     => 180,
	'skip_ua_patterns' => array( 'LittleD-App' ),
	'skip_bots'        => true,
	'consent_regions'  => array(),
	'url_passthrough'  => false,
);

// Worst case: longest names, escape-heavy patterns (& and ' are expanded to \u00xx), full region list.
$worst = $typical;
$worst['cookie_name']      = str_repeat( 'c', 32 );
$worst['policy_version']   = str_repeat( 'v', 20 );
$worst['consent_days']     = 730;
$worst['url_passthrough']  = true;
$worst['skip_ua_patterns'] = array();
for ( $i = 0; $i < 30; $i++ ) {
	$worst['skip_ua_patterns'][] = str_repeat( "&'\\", 33 ) . $i;
}
$worst['consent_regions'] = array();
for ( $i = 0; $i < 20; $i++ ) { // 140 joined characters is the schema cap; longest code shape (US-XYZ).
	$worst['consent_regions'][] = 'US-' . chr( 65 + ( $i % 26 ) ) . chr( 65 + ( intdiv( $i, 26 ) ) ) . 'Z';
}

$cases = array(
	'typical' => $typical,
	'worst'   => $worst,
);
foreach ( $cases as $label => $adv ) {
	echo $label . '=' . strlen( HeadScript::build( HeadScript::config( $adv ) ) ) . "\n"; // phpcs:ignore
}
