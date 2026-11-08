<?php
/**
 * The known-cookie database: the file bundled with the plugin (data/known-cookies.json) or a newer, verified copy
 * downloaded into uploads/dscc/. Used by the platform picker and the classifier; never loaded on the front end.
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Catalog;

defined( 'ABSPATH' ) || exit;

final class KnownDb {

	/**
	 * Loaded instance for this request.
	 *
	 * @var KnownDb|null|false False = not loaded yet.
	 */
	private static $instance = false;

	/**
	 * Validated file contents.
	 *
	 * @var array
	 */
	private $data;

	/**
	 * Services indexed by slug.
	 *
	 * @var array<string,array>
	 */
	private $by_slug = array();

	/**
	 * Exact cookie names: "storage:name" => [slug, cookie index].
	 *
	 * @var array<string,array>
	 */
	private $exact = array();

	/**
	 * Pattern cookies: list of [pattern, storage, slug, cookie index].
	 *
	 * @var array[]
	 */
	private $patterns = array();

	/**
	 * Build the lookup tables for a validated database.
	 *
	 * @param array $data Output of KnownDbValidator::parse()['data'].
	 */
	public function __construct( array $data ) {
		$this->data = $data;
		foreach ( $data['services'] as $service ) {
			$this->by_slug[ $service['slug'] ] = $service;
			foreach ( $service['cookies'] as $i => $cookie ) {
				if ( $cookie['is_pattern'] ) {
					$this->patterns[] = array( $cookie['name'], $cookie['storage'], $service['slug'], $i );
				} else {
					$this->exact[ $cookie['storage'] . ':' . $cookie['name'] ] = array( $service['slug'], $i );
				}
			}
		}
	}

	/**
	 * Path of the file shipped with the plugin.
	 *
	 * @return string
	 */
	public static function bundled_path() {
		return DSCC_DIR . 'data/known-cookies.json';
	}

	/**
	 * Path of the downloaded copy.
	 *
	 * @return string
	 */
	public static function stored_path() {
		$uploads = wp_upload_dir( null, false );
		return trailingslashit( $uploads['basedir'] ) . 'dscc/known-cookies.json';
	}

	/**
	 * The database in use: the downloaded copy when it is valid and newer than the bundled one, else the bundled one.
	 *
	 * @return KnownDb|null Null when even the bundled file is unreadable or invalid.
	 */
	public static function load() {
		if ( false !== self::$instance ) {
			return self::$instance;
		}
		$bundled = self::from_file( self::bundled_path() );
		$stored  = self::from_file( self::stored_path() );
		if ( $stored && ( ! $bundled || version_compare( $stored->version(), $bundled->version(), '>' ) ) ) {
			self::$instance = $stored;
		} else {
			self::$instance = $bundled;
		}
		return self::$instance;
	}

	/**
	 * Forget the loaded instance (after a download, and in tests).
	 */
	public static function flush() {
		self::$instance = false;
	}

	/**
	 * Read and validate one file.
	 *
	 * @param string $path File path.
	 * @return KnownDb|null
	 */
	public static function from_file( $path ) {
		if ( ! is_readable( $path ) ) {
			return null;
		}
		$json = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file inside the plugin or uploads.
		return false === $json ? null : self::from_json( $json );
	}

	/**
	 * Validate a JSON string.
	 *
	 * @param string $json File contents.
	 * @return KnownDb|null
	 */
	public static function from_json( $json ) {
		$result = KnownDbValidator::parse( $json );
		return null === $result['data'] ? null : new self( $result['data'] );
	}

	/**
	 * Database version string.
	 *
	 * @return string
	 */
	public function version() {
		return (string) $this->data['version'];
	}

	/**
	 * All services in file order.
	 *
	 * @return array[]
	 */
	public function services() {
		return $this->data['services'];
	}

	/**
	 * One service by slug.
	 *
	 * @param string $slug Slug.
	 * @return array|null
	 */
	public function service( $slug ) {
		return isset( $this->by_slug[ $slug ] ) ? $this->by_slug[ $slug ] : null;
	}

	/**
	 * Total number of cookie entries.
	 *
	 * @return int
	 */
	public function cookie_count() {
		$n = 0;
		foreach ( $this->data['services'] as $service ) {
			$n += count( $service['cookies'] );
		}
		return $n;
	}

	/**
	 * Find the known cookie for an observed name: exact name first, then patterns.
	 *
	 * @param string $name    Observed name.
	 * @param string $storage Storage kind.
	 * @return array|null {service: array, cookie: array, exact: bool}
	 */
	public function find_cookie( $name, $storage = 'cookie' ) {
		$key = $storage . ':' . $name;
		if ( isset( $this->exact[ $key ] ) ) {
			return $this->hit( $this->exact[ $key ][0], $this->exact[ $key ][1], true );
		}
		foreach ( $this->patterns as $entry ) {
			if ( $entry[1] === $storage && PatternMatcher::name_matches( $entry[0], $name ) ) {
				return $this->hit( $entry[2], $entry[3], false );
			}
		}
		return null;
	}

	/**
	 * Find the service whose script patterns match a script (external src and/or inline body).
	 *
	 * @param string $src    Script or resource URL.
	 * @param string $inline Inline script body.
	 * @return array|null Service.
	 */
	public function find_service_by_script( $src = '', $inline = '' ) {
		foreach ( $this->data['services'] as $service ) {
			if ( PatternMatcher::script_matches( $service['script_patterns'], $src, $inline ) ) {
				return $service;
			}
		}
		return null;
	}

	/**
	 * Result shape of find_cookie().
	 *
	 * @param string $slug  Service slug.
	 * @param int    $index Cookie index in the service.
	 * @param bool   $exact Exact name match.
	 * @return array
	 */
	private function hit( $slug, $index, $exact ) {
		return array(
			'service' => $this->by_slug[ $slug ],
			'cookie'  => $this->by_slug[ $slug ]['cookies'][ $index ],
			'exact'   => $exact,
		);
	}
}
