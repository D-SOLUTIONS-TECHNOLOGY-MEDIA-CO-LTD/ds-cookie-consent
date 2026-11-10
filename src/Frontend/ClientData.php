<?php
/**
 * Data for the client script, printed as JSON in #dscc-data.
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Frontend;

use DSolutions\CookieConsent\Blocker\CookieSafelist;
use DSolutions\CookieConsent\Blocker\ScriptRules;
use DSolutions\CookieConsent\Integrations\ChatWidgets;
use DSolutions\CookieConsent\Integrations\Registry;
use DSolutions\CookieConsent\Scanner\BeaconEndpoint;

defined( 'ABSPATH' ) || exit;

final class ClientData {

	/**
	 * Build the client payload.
	 *
	 * @param array $vm View model (without data).
	 * @return array
	 */
	public static function build( array $vm ) {
		$adv = $vm['settings']['advanced'];
		$a   = $vm['settings']['appearance'];

		$cats = array();
		foreach ( $vm['categories'] as $cat ) {
			// Only services with a switch of their own are tracked one by one; the others follow their category.
			$switchable = array_filter(
				$cat['services'],
				static function ( $svc ) {
					return ! empty( $svc['blockable'] );
				}
			);
			$cats[]     = array(
				'key'      => $cat['key'],
				'required' => $cat['required'],
				'services' => array_values( wp_list_pluck( $switchable, 'slug' ) ),
			);
		}
		$services = array();
		foreach ( $vm['services'] as $svc ) {
			$services[] = array(
				'slug' => $svc['slug'],
				'cat'  => $svc['category'],
			);
		}

		$blocking = $vm['settings']['blocking'];
		$block    = 'block' === $blocking['mode'];

		return array(
			'v'          => (string) $adv['policy_version'],
			'layout'     => $vm['layout'],
			'lang'       => $vm['lang'],
			'dual'       => $vm['dual'],
			'preview'    => $vm['preview'],
			'ajax'       => admin_url( 'admin-ajax.php' ),
			'rest'       => esc_url_raw( rest_url( 'dscc/v1/log' ) ),
			'cookie'     => array(
				'name'   => (string) $adv['cookie_name'],
				'days'   => (int) $adv['consent_days'],
				'domain' => (string) $adv['cookie_domain'],
			),
			'perService' => $vm['per_service'],
			'widget'     => array(
				'enabled' => $vm['widget'],
				'show'    => 'after_reject' === $a['widget_show'] && 'bar' === $vm['layout'] ? 'after_reject' : 'always',
			),
			'hide'       => ChatWidgets::merge( array_values( (array) $adv['hide_selectors'] ) ),
			'raise'      => ! empty( $adv['raise_widget'] ),
			'bots'       => UserAgentRules::bot_pattern( ! empty( $adv['skip_bots'] ) ),
			'ua'         => array_values( (array) $adv['skip_ua_patterns'] ),
			'skipMode'   => 'grant' === $adv['skip_ua_mode'] ? 'grant' : 'deny',
			'cats'       => $cats,
			'services'   => $services,
			'policy'     => array(
				'mode' => $vm['policy']['mode'],
			),
			// Blocking: `purge` lists the cookies and storage keys to delete per service (empty in observe mode, which
			// changes nothing; `keep` names are never deleted); `reload` asks for a page reload after a withdrawal.
			'block'      => array(
				'mode'   => $blocking['mode'],
				'reload' => ! empty( $blocking['reload_on_withdraw'] ),
				'purge'  => $block ? ScriptRules::purge_list() : array(),
				'keep'   => CookieSafelist::patterns(),
			),
			'int'        => Registry::client_flags(),
			// Beacon: present only when collection is on. The sample rate is applied in the browser, so the page is
			// identical for every visitor.
			'beacon'     => BeaconEndpoint::enabled() ? array( 'rate' => BeaconEndpoint::rate() ) : null,
		);
	}
}
