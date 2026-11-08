<?php
/**
 * Classifies an observed cookie / storage key / script host (spec 6.3), first match wins:
 *   1. the site's own confirmed catalog        -> keep as is           (match: confirmed,  status confirmed)
 *   2. known database, exact name then pattern -> service + details    (match: known_exact / known_pattern, status auto)
 *   3. known database script patterns (host)   -> service, cookie new  (match: script_host, status new)
 *   4. nothing                                 -> unclassified, new    (match: unclassified, status new)
 * An unclassified item is blocked like marketing and never shown to visitors until an admin classifies it.
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Catalog;

defined( 'ABSPATH' ) || exit;

final class Classifier {

	/**
	 * Known database (null when it could not be loaded: only steps 1 and 4 apply).
	 *
	 * @var KnownDb|null
	 */
	private $known;

	/**
	 * Looks up the site's confirmed catalog: function( name, storage ): array|null with
	 * keys service_slug, category, and optionally cookie (the stored row).
	 *
	 * @var callable
	 */
	private $site_lookup;

	/**
	 * Constructor.
	 *
	 * @param KnownDb|null  $known       Known database.
	 * @param callable|null $site_lookup Confirmed-catalog lookup; the repositories when omitted.
	 */
	public function __construct( $known, $site_lookup = null ) {
		$this->known       = $known;
		$this->site_lookup = null === $site_lookup ? array( __CLASS__, 'lookup_site_catalog' ) : $site_lookup;
	}

	/**
	 * Classifier over the database in use and the site's repositories.
	 *
	 * @return Classifier
	 */
	public static function create() {
		return new self( KnownDb::load() );
	}

	/**
	 * Classify one observation.
	 *
	 * @param array $obs name, storage (default cookie), host and/or src (script origin), inline (script body).
	 * @return array {match, status, category, service_slug, known_cookie, known_service}
	 */
	public function classify( array $obs ) {
		$name    = isset( $obs['name'] ) ? (string) $obs['name'] : '';
		$storage = isset( $obs['storage'] ) ? (string) $obs['storage'] : 'cookie';

		if ( '' !== $name ) {
			$site = call_user_func( $this->site_lookup, $name, $storage );
			if ( is_array( $site ) ) {
				return $this->result( 'confirmed', 'confirmed', $site['category'], $site['service_slug'], null, null );
			}
			if ( $this->known ) {
				$hit = $this->known->find_cookie( $name, $storage );
				if ( $hit ) {
					return $this->result( $hit['exact'] ? 'known_exact' : 'known_pattern', 'auto', $hit['service']['category'], $hit['service']['slug'], $hit['cookie'], $hit['service'] );
				}
			}
		}

		if ( $this->known ) {
			$src     = isset( $obs['src'] ) ? (string) $obs['src'] : ( isset( $obs['host'] ) ? 'https://' . $obs['host'] . '/' : '' );
			$inline  = isset( $obs['inline'] ) ? (string) $obs['inline'] : '';
			$service = '' !== $src || '' !== $inline ? $this->known->find_service_by_script( $src, $inline ) : null;
			if ( $service ) {
				return $this->result( 'script_host', 'new', $service['category'], $service['slug'], null, $service );
			}
		}
		return $this->result( 'unclassified', 'new', 'unclassified', '', null, null );
	}

	/**
	 * Default step-1 lookup: the site's confirmed cookie rows and their service.
	 *
	 * @param string $name    Observed name.
	 * @param string $storage Storage kind.
	 * @return array|null
	 */
	public static function lookup_site_catalog( $name, $storage ) {
		$row = CookieRepository::match_confirmed( $name, $storage );
		if ( ! $row ) {
			return null;
		}
		$service = $row['service_id'] ? ServiceRepository::find( $row['service_id'] ) : null;
		return array(
			'service_slug' => $service ? $service['slug'] : '',
			'category'     => $service ? $service['category_key'] : 'unclassified',
		);
	}

	/**
	 * Result array.
	 *
	 * @param string     $kind     Match kind.
	 * @param string     $status   Suggested cookie status.
	 * @param string     $category Category key.
	 * @param string     $slug     Service slug or ''.
	 * @param array|null $cookie   Known cookie entry.
	 * @param array|null $service  Known service entry.
	 * @return array
	 */
	private function result( $kind, $status, $category, $slug, $cookie, $service ) {
		return array(
			'match'         => $kind,
			'status'        => $status,
			'category'      => $category,
			'service_slug'  => $slug,
			'known_cookie'  => $cookie,
			'known_service' => $service,
		);
	}
}
