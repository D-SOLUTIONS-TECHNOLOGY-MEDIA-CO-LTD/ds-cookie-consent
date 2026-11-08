<?php
/**
 * Database schema (dbDelta). Later milestones extend install() with more tables.
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Database;

defined( 'ABSPATH' ) || exit;

final class Schema {

	/** Bump when any table definition changes. */
	const VERSION = '4';

	/**
	 * Consent log table name.
	 */
	public static function log_table() {
		global $wpdb;
		return $wpdb->prefix . 'dscc_consent_log';
	}

	/**
	 * Services (platforms) table name.
	 */
	public static function services_table() {
		global $wpdb;
		return $wpdb->prefix . 'dscc_services';
	}

	/**
	 * Cookies table name.
	 */
	public static function cookies_table() {
		global $wpdb;
		return $wpdb->prefix . 'dscc_cookies';
	}

	/**
	 * Scan runs table name.
	 */
	public static function scan_runs_table() {
		global $wpdb;
		return $wpdb->prefix . 'dscc_scan_runs';
	}

	/**
	 * Create or update all tables.
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::log_table();
		$charset = $wpdb->get_charset_collate();

		self::install_catalog( $charset );
		self::install_scan_runs( $charset );

		dbDelta(
			"CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  consent_id varchar(64) NOT NULL DEFAULT '',
  choice varchar(16) NOT NULL DEFAULT '',
  analytics tinyint(1) NOT NULL DEFAULT 0,
  marketing tinyint(1) NOT NULL DEFAULT 0,
  services_json text NULL,
  policy_version varchar(20) NOT NULL DEFAULT '',
  lang varchar(10) NOT NULL DEFAULT '',
  page_url varchar(255) NOT NULL DEFAULT '',
  ip_masked varchar(64) NOT NULL DEFAULT '',
  user_agent varchar(255) NOT NULL DEFAULT '',
  src varchar(16) NOT NULL DEFAULT 'banner',
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY consent_id (consent_id),
  KEY created_at (created_at),
  KEY choice (choice)
) {$charset};"
		);
	}

	/**
	 * Catalog tables. script_patterns holds the JSON object {hosts, src_regex, inline_regex}.
	 * The cookie identity key (name, storage, domain) stays under the 767 byte index limit of older MySQL (180 chars x 4).
	 *
	 * @param string $charset Charset and collation clause.
	 */
	private static function install_catalog( $charset ) {
		$services = self::services_table();
		$cookies  = self::cookies_table();

		dbDelta(
			"CREATE TABLE {$services} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  slug varchar(40) NOT NULL,
  name varchar(120) NOT NULL DEFAULT '',
  category_key varchar(20) NOT NULL DEFAULT 'unclassified',
  provider_company varchar(160) NOT NULL DEFAULT '',
  privacy_url varchar(255) NOT NULL DEFAULT '',
  is_third_party tinyint(1) NOT NULL DEFAULT 1,
  transfers_abroad tinyint(1) NOT NULL DEFAULT 0,
  country varchar(2) NOT NULL DEFAULT '',
  script_patterns longtext NULL,
  block_enabled tinyint(1) NOT NULL DEFAULT 1,
  status varchar(12) NOT NULL DEFAULT 'auto',
  source varchar(12) NOT NULL DEFAULT 'manual',
  detected_via text NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY slug (slug),
  KEY status (status),
  KEY category_key (category_key)
) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$cookies} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  service_id bigint(20) unsigned NOT NULL DEFAULT 0,
  name varchar(100) NOT NULL,
  name_is_pattern tinyint(1) NOT NULL DEFAULT 0,
  storage varchar(16) NOT NULL DEFAULT 'cookie',
  domain varchar(64) NOT NULL DEFAULT '',
  path varchar(100) NOT NULL DEFAULT '/',
  duration_label varchar(120) NOT NULL DEFAULT '',
  duration_label_en varchar(120) NOT NULL DEFAULT '',
  duration_seconds bigint(20) unsigned NULL,
  purpose text NULL,
  purpose_en text NULL,
  http_only tinyint(1) NOT NULL DEFAULT 0,
  set_by varchar(8) NOT NULL DEFAULT 'unknown',
  first_seen_at datetime NULL,
  last_seen_at datetime NULL,
  seen_count int(10) unsigned NOT NULL DEFAULT 0,
  seen_on_urls text NULL,
  table_group varchar(60) NOT NULL DEFAULT '',
  applies_to varchar(255) NOT NULL DEFAULT '',
  applies_to_en varchar(255) NOT NULL DEFAULT '',
  status varchar(12) NOT NULL DEFAULT 'new',
  source varchar(12) NOT NULL DEFAULT 'manual',
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY identity (name,storage,domain),
  KEY service_id (service_id),
  KEY status (status)
) {$charset};"
		);
	}

	/**
	 * Scan runs: one row per scan. `summary` is a small JSON object (counts, trigger, progress); `report` holds the
	 * details and, while the run is in progress, the queue of URLs still to fetch.
	 *
	 * @param string $charset Charset and collation clause.
	 */
	private static function install_scan_runs( $charset ) {
		$table = self::scan_runs_table();
		dbDelta(
			"CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  type varchar(8) NOT NULL DEFAULT 'server',
  started_at datetime NOT NULL,
  finished_at datetime NULL,
  status varchar(12) NOT NULL DEFAULT 'running',
  summary text NULL,
  report longtext NULL,
  PRIMARY KEY  (id),
  KEY type_started (type,started_at)
) {$charset};"
		);
	}
}
