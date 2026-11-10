<?php
/**
 * The platform picker's operations: tick a known platform (service + its cookies become confirmed),
 * untick it (service and cookies become ignored, data is kept), change "block until consent",
 * and add a custom platform.
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Catalog;

use WP_Error;

defined( 'ABSPATH' ) || exit;

final class PlatformManager {

	/**
	 * Tick a platform. A known-db slug imports the service and every cookie it lists as confirmed;
	 * an existing service of the site (custom or scanned) is confirmed together with its cookies.
	 * The admin's "block until consent" choice survives a re-tick.
	 *
	 * @param string $slug Service slug.
	 * @return bool False when the slug is neither in the known database nor in the site catalog.
	 */
	public static function enable( $slug ) {
		$known = KnownDb::load();
		$def   = $known ? $known->service( $slug ) : null;

		if ( $def ) {
			$data     = array(
				'slug'             => $def['slug'],
				'name'             => $def['name'],
				'category_key'     => $def['category'],
				'provider_company' => $def['provider_company'],
				'privacy_url'      => $def['privacy_url'],
				'is_third_party'   => $def['is_third_party'],
				'country'          => $def['country'],
				'script_patterns'  => $def['script_patterns'],
				'status'           => 'confirmed',
				'source'           => 'known_db',
			);
			$existing = ServiceRepository::find_by_slug( $slug );
			$id       = ServiceRepository::upsert( $existing ? self::fill_missing_service( $existing, $data ) : $data );
			if ( ! $id ) {
				return false;
			}
			foreach ( $def['cookies'] as $cookie ) {
				CookieRepository::upsert( self::cookie_fields( $id, $cookie ) );
			}
			self::set_cookies_status( $id, 'confirmed' );
			return true;
		}

		$service = ServiceRepository::find_by_slug( $slug );
		if ( ! $service ) {
			return false;
		}
		ServiceRepository::set_status( $slug, 'confirmed' );
		self::set_cookies_status( $service['id'], 'confirmed' );
		return true;
	}

	/**
	 * Fields of a known-db cookie as catalog row values. A row that already exists keeps what the admin may have
	 * edited: only empty text fields are filled from the database; the vendor and the status are always set.
	 *
	 * @param int   $service_id Service id.
	 * @param array $cookie     Known-db cookie entry.
	 * @return array
	 */
	public static function cookie_fields( $service_id, array $cookie ) {
		$fields   = array(
			'service_id'        => $service_id,
			'name'              => $cookie['name'],
			'storage'           => $cookie['storage'],
			'domain'            => $cookie['domain_hint'],
			'duration_label'    => $cookie['duration_vi'],
			'duration_label_en' => $cookie['duration_en'],
			'duration_seconds'  => $cookie['duration_seconds'],
			'purpose'           => $cookie['purpose_vi'],
			'purpose_en'        => $cookie['purpose_en'],
			'table_group'       => isset( $cookie['table_group'] ) ? $cookie['table_group'] : '',
			'applies_to'        => isset( $cookie['applies_to'] ) ? $cookie['applies_to'] : '',
			'applies_to_en'     => isset( $cookie['applies_to_en'] ) ? $cookie['applies_to_en'] : '',
			'status'            => 'confirmed',
			'source'            => 'known_db',
		);
		$existing = CookieRepository::find_identity( $fields['name'], $fields['storage'], $fields['domain'] );
		if ( $existing ) {
			foreach ( array( 'duration_label', 'duration_label_en', 'purpose', 'purpose_en', 'table_group', 'applies_to', 'applies_to_en' ) as $key ) {
				if ( '' !== (string) $existing[ $key ] ) {
					unset( $fields[ $key ] );
				}
			}
			if ( null !== $existing['duration_seconds'] ) {
				unset( $fields['duration_seconds'] );
			}
			unset( $fields['source'] );
		}
		return $fields;
	}

	/**
	 * A service that exists already is only completed, never overwritten: the admin may have renamed it, moved it to
	 * another category or edited its script patterns. Status becomes confirmed; source stays as it was.
	 *
	 * @param array $existing Stored service.
	 * @param array $data     Values from the known database.
	 * @return array
	 */
	private static function fill_missing_service( array $existing, array $data ) {
		$out = array(
			'slug'   => $data['slug'],
			'status' => 'confirmed',
		);
		foreach ( array( 'name', 'provider_company', 'privacy_url', 'country' ) as $key ) {
			if ( '' === (string) $existing[ $key ] ) {
				$out[ $key ] = $data[ $key ];
			}
		}
		if ( 'unclassified' === $existing['category_key'] ) {
			$out['category_key'] = $data['category_key'];
		}
		if ( ! array_filter( $existing['script_patterns'] ) ) {
			$out['script_patterns'] = $data['script_patterns'];
		}
		return $out;
	}

	/**
	 * Untick a platform: the service and its cookies are ignored, nothing is deleted.
	 *
	 * @param string $slug Service slug.
	 * @return bool False when the service does not exist.
	 */
	public static function disable( $slug ) {
		$service = ServiceRepository::find_by_slug( $slug );
		if ( ! $service ) {
			return false;
		}
		ServiceRepository::set_status( $slug, 'ignored' );
		self::set_cookies_status( $service['id'], 'ignored' );
		return true;
	}

	/**
	 * Add a platform the known database does not cover. It is created confirmed.
	 *
	 * @param array $input name, category (necessary|functional|analytics|marketing), privacy_url, hosts (string[] or text),
	 *                     src_regex (string[] or text), provider_company.
	 * @return string|WP_Error The new slug.
	 */
	public static function add_custom( array $input ) {
		$name = isset( $input['name'] ) ? trim( sanitize_text_field( (string) $input['name'] ) ) : '';
		if ( '' === $name ) {
			return new WP_Error( 'dscc_platform_name', __( 'Enter a platform name.', 'ds-cookie-consent' ) );
		}
		$category = isset( $input['category'] ) ? (string) $input['category'] : '';
		if ( ! in_array( $category, array( 'necessary', 'functional', 'analytics', 'marketing' ), true ) ) {
			return new WP_Error( 'dscc_platform_category', __( 'Choose a valid cookie category.', 'ds-cookie-consent' ) );
		}
		$hosts = array();
		foreach ( self::lines( isset( $input['hosts'] ) ? $input['hosts'] : array() ) as $host ) {
			$host = strtolower( preg_replace( '#^https?://#i', '', $host ) );
			$host = (string) preg_replace( '#[/:?].*$#', '', $host );
			if ( preg_match( '/^[a-z0-9]([a-z0-9.\-]*[a-z0-9])?$/', $host ) ) {
				$hosts[] = $host;
			}
		}
		$regexes = array_values( array_filter( self::lines( isset( $input['src_regex'] ) ? $input['src_regex'] : array() ), array( PatternMatcher::class, 'valid_regex' ) ) );

		$known    = KnownDb::load();
		$reserved = array();
		foreach ( $known ? $known->services() : array() as $service ) {
			$reserved[] = $service['slug'];
		}
		$slug = ServiceRepository::unique_slug( $name, $reserved );
		$id   = ServiceRepository::upsert(
			array(
				'slug'             => $slug,
				'name'             => $name,
				'category_key'     => $category,
				'provider_company' => isset( $input['provider_company'] ) ? $input['provider_company'] : '',
				'privacy_url'      => isset( $input['privacy_url'] ) ? $input['privacy_url'] : '',
				'script_patterns'  => array(
					'hosts'     => array_values( array_unique( $hosts ) ),
					'src_regex' => $regexes,
				),
				'status'           => 'confirmed',
				'source'           => 'manual',
			)
		);
		return $id ? $slug : new WP_Error( 'dscc_platform_save', __( 'Could not save the platform.', 'ds-cookie-consent' ) );
	}

	/**
	 * Picker state: every known platform plus the site's own services, by slug.
	 *
	 * @return array<string,array> slug => {slug, name, category, provider, privacy_url, cookies, enabled, block, detected, custom}
	 */
	public static function overview() {
		$known = KnownDb::load();
		$out   = array();
		foreach ( $known ? $known->services() : array() as $def ) {
			$out[ $def['slug'] ] = array(
				'slug'        => $def['slug'],
				'name'        => $def['name'],
				'category'    => $def['category'],
				'provider'    => $def['provider_company'],
				'privacy_url' => $def['privacy_url'],
				'cookies'     => count( $def['cookies'] ),
				'enabled'     => false,
				'block'       => ServiceRepository::default_block( $def['category'], ! empty( $def['is_third_party'] ) ),
				'detected'    => array(),
				'custom'      => false,
			);
		}
		foreach ( ServiceRepository::all() as $service ) {
			if ( 0 === strpos( $service['slug'], 'site-' ) && 'manual' === $service['source'] && ! isset( $out[ $service['slug'] ] ) ) {
				continue; // First-party holders of manually classified cookies are not platforms.
			}
			$slug            = $service['slug'];
			$row             = isset( $out[ $slug ] ) ? $out[ $slug ] : array(
				'slug'        => $slug,
				'name'        => $service['name'],
				'category'    => $service['category_key'],
				'provider'    => $service['provider_company'],
				'privacy_url' => $service['privacy_url'],
				'cookies'     => count( CookieRepository::for_service( $service['id'] ) ),
				'custom'      => true,
			);
			$row['enabled']  = 'confirmed' === $service['status'];
			$row['block']    = (int) $service['block_enabled'];
			$row['detected'] = $service['detected_via'];
			$out[ $slug ]    = $row;
		}
		return $out;
	}

	/**
	 * Set the status of every cookie of a service that is not waiting for review.
	 *
	 * @param int    $service_id Service id.
	 * @param string $status     confirmed|ignored.
	 */
	private static function set_cookies_status( $service_id, $status ) {
		$ids = array();
		foreach ( CookieRepository::for_service( $service_id ) as $cookie ) {
			if ( 'new' !== $cookie['status'] && $cookie['status'] !== $status ) {
				$ids[] = $cookie['id'];
			}
		}
		if ( $ids ) {
			CookieRepository::set_status_many( $ids, $status );
		}
	}

	/**
	 * Non-empty trimmed lines from a textarea string or an array.
	 *
	 * @param mixed $value Raw value.
	 * @return string[]
	 */
	private static function lines( $value ) {
		if ( is_string( $value ) ) {
			$value = preg_split( '/\r\n|\r|\n/', $value );
		}
		$out = array();
		foreach ( is_array( $value ) ? $value : array() as $line ) {
			$line = is_string( $line ) ? trim( $line ) : '';
			if ( '' !== $line ) {
				$out[] = $line;
			}
		}
		return array_slice( $out, 0, 20 );
	}
}
