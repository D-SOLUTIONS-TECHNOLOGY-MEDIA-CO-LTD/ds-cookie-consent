<?php
/**
 * Field definitions for dscc_settings: [ type, default, extra ] per key, grouped by settings group.
 *
 * Types: bool, int (extra [min,max]), enum (extra options), color, color_opt, text (extra max length),
 * url, https_url, font, radius, css, cookie_name, cookie_domain, version, ua_list, selector_list, region_list,
 * exclusion_list, email (empty allowed), cookie_pattern_list.
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Settings;

final class SettingsFields {

	/**
	 * Field definitions grouped by settings group.
	 *
	 * @return array<string,array<string,array>>
	 */
	public static function all() {
		return array(
			'appearance' => array(
				'layout'              => array( 'enum', 'card', array( 'card', 'bar' ) ),
				'logo_id'             => array( 'int', 0, array( 0, 2147483647 ) ),
				'logo_popup_id'       => array( 'int', 0, array( 0, 2147483647 ) ),
				'logo_height'         => array( 'int', 30, array( 12, 120 ) ),
				'logo_popup_height'   => array( 'int', 22, array( 12, 120 ) ),
				'color_primary'       => array( 'color', '#005ea3' ),
				'color_primary_hover' => array( 'color_opt', '' ),
				'color_text'          => array( 'color', '#1e3a5f' ),
				'color_bar_bg'        => array( 'color', '#f1f5f9' ),
				'color_reject_bg'     => array( 'color_opt', '' ),
				'color_modal_bg'      => array( 'color', '#f6f6f6' ),
				'font'                => array( 'font', 'inherit' ),
				'button_radius'       => array( 'radius', '' ),
				'button_order'        => array( 'enum', '', array( '', 'accept_first', 'accept_last' ) ),
				'customize_style'     => array( 'enum', 'link', array( 'link', 'button' ) ),
				'card_position'       => array( 'enum', 'bottom_left', array( 'bottom_left', 'bottom_right', 'bottom_center' ) ),
				'widget_enabled'      => array( 'bool', true ),
				'widget_side'         => array( 'enum', 'left', array( 'left', 'right' ) ),
				'widget_bottom'       => array( 'int', 20, array( 0, 400 ) ),
				'widget_offset_x'     => array( 'int', 20, array( 0, 400 ) ),
				'widget_show'         => array( 'enum', 'always', array( 'always', 'after_reject' ) ),
				'widget_label'        => array( 'text', '', 40 ),
				'overlay_opacity'     => array( 'int', 35, array( 0, 60 ) ),
				'per_service_toggle'  => array( 'bool', true ),
				'custom_css'          => array( 'css', '' ),
			),
			'content'    => array(
				'cookie_policy_page_id' => array( 'int', 0, array( 0, 2147483647 ) ),
				'cookie_policy_url'     => array( 'url', '' ),
				'privacy_page_id'       => array( 'int', 0, array( 0, 2147483647 ) ),
				'privacy_url'           => array( 'url', '' ),
				'policy_embed_mode'     => array( 'enum', 'iframe', array( 'iframe', 'link' ) ),
			),
			'blocking'   => array(
				'mode'                     => array( 'enum', 'block', array( 'block', 'observe' ) ),
				'gtm_mode'                 => array( 'enum', 'consent_mode', array( 'consent_mode', 'block' ) ),
				'exclusions'               => array( 'exclusion_list', array() ),
				'block_unclassified'       => array( 'bool', true ),
				'unclassified_cookie_mode' => array( 'enum', 'report', array( 'block', 'report' ) ),
				'keep_cookies'             => array( 'cookie_pattern_list', array() ),
				'reload_on_withdraw'       => array( 'bool', true ),
				'meta_hold'                => array( 'bool', true ),
			),
			'scanner'    => array(
				'auto_scan'      => array( 'enum', 'off', array( 'off', 'weekly' ) ),
				'max_urls'       => array( 'int', 50, array( 1, 200 ) ),
				'beacon_enabled' => array( 'bool', false ),
				'beacon_sample'  => array( 'int', 5, array( 0, 100 ) ),
				'notify_enabled' => array( 'bool', true ),
				'notify_email'   => array( 'email', '' ),
			),
			'advanced'   => array(
				'policy_version'           => array( 'version', '' ),
				'consent_days'             => array( 'int', 180, array( 1, 730 ) ),
				'cookie_name'              => array( 'cookie_name', 'dscc_consent' ),
				'cookie_domain'            => array( 'cookie_domain', '' ),
				'skip_ua_patterns'         => array( 'ua_list', array() ),
				'skip_ua_mode'             => array( 'enum', 'deny', array( 'deny', 'grant' ) ),
				'skip_bots'                => array( 'bool', true ),
				'hide_selectors'           => array( 'selector_list', array() ),
				'raise_widget'             => array( 'bool', true ),
				'retention_months'         => array( 'int', 24, array( 0, 120 ) ),
				'delete_data_on_uninstall' => array( 'bool', false ),
				'show_functional'          => array( 'bool', false ),
				'default_lang'             => array( 'enum', 'vi', array( 'vi', 'en' ) ),
				'lang_source'              => array( 'enum', 'server', array( 'server', 'client' ) ),
				'trusted_ip_header'        => array( 'enum', 'none', array( 'none', 'cf-connecting-ip', 'x-forwarded-for' ) ),
				'consent_regions'          => array( 'region_list', array() ),
				'url_passthrough'          => array( 'bool', false ),
				'known_db_url'             => array( 'https_url', '' ),
			),
		);
	}
}
