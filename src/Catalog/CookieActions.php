<?php
/**
 * Admin operations on catalog cookies: confirm / ignore / delete / move to another category / quick edit / add by hand.
 *
 * Category rule: a cookie's category is its service's category. Moving a cookie of a vendor service moves that whole
 * vendor (its other cookies follow); a cookie without a vendor, or held by the site's own "site-*" service, is
 * assigned to the first-party service of the target category instead.
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Catalog;

use WP_Error;

defined( 'ABSPATH' ) || exit;

final class CookieActions {

	/** Categories an admin can assign. */
	const ASSIGNABLE = array( 'necessary', 'functional', 'analytics', 'marketing' );

	/**
	 * Confirm cookies. The vendor of a confirmed cookie is confirmed too, so it shows to visitors.
	 * A cookie with no vendor, or whose vendor is unclassified, cannot be confirmed (it would have nowhere to be listed).
	 *
	 * @param int[] $ids Cookie ids.
	 * @return array{changed:int, skipped:int}
	 */
	public static function confirm( array $ids ) {
		$ok      = array();
		$skipped = 0;
		foreach ( $ids as $id ) {
			$cookie = CookieRepository::find( $id );
			if ( ! $cookie ) {
				continue;
			}
			$service = $cookie['service_id'] ? ServiceRepository::find( $cookie['service_id'] ) : null;
			if ( ! $service || 'unclassified' === $service['category_key'] ) {
				++$skipped; // Nowhere to list it: it needs a vendor or a category first.
				continue;
			}
			if ( 'confirmed' !== $service['status'] ) {
				ServiceRepository::set_status( $service['slug'], 'confirmed' );
			}
			$ok[] = $cookie['id'];
		}
		return array(
			'changed' => CookieRepository::set_status_many( $ok, 'confirmed' ),
			'skipped' => $skipped,
		);
	}

	/**
	 * Ignore cookies (kept in the table, never shown to visitors).
	 *
	 * @param int[] $ids Cookie ids.
	 * @return int Rows changed.
	 */
	public static function ignore( array $ids ) {
		return CookieRepository::set_status_many( $ids, 'ignored' );
	}

	/**
	 * Delete cookies.
	 *
	 * @param int[] $ids Cookie ids.
	 * @return int Rows deleted.
	 */
	public static function delete( array $ids ) {
		return CookieRepository::delete_many( $ids );
	}

	/**
	 * Move cookies to a category (see the class comment for the vendor rule).
	 *
	 * @param int[]  $ids      Cookie ids.
	 * @param string $category necessary|functional|analytics|marketing.
	 * @return int Cookies whose effective category now equals $category.
	 */
	public static function move_category( array $ids, $category ) {
		if ( ! in_array( $category, self::ASSIGNABLE, true ) ) {
			return 0;
		}
		$done = 0;
		$seen = array();
		foreach ( $ids as $id ) {
			$cookie = CookieRepository::find( $id );
			if ( ! $cookie ) {
				continue;
			}
			$service = $cookie['service_id'] ? ServiceRepository::find( $cookie['service_id'] ) : null;
			if ( $service && 0 !== strpos( $service['slug'], 'site-' ) ) {
				if ( ! isset( $seen[ $service['id'] ] ) ) {
					ServiceRepository::set_category( $service['id'], $category );
					$seen[ $service['id'] ] = true;
				}
			} else {
				$holder = ServiceRepository::site_service( $category );
				if ( $holder ) {
					CookieRepository::update( $cookie['id'], array( 'service_id' => $holder ) );
				}
			}
			++$done;
		}
		return $done;
	}

	/**
	 * Quick edit: purpose and duration texts (vi/en) and, when given, the category.
	 *
	 * @param int   $id     Cookie id.
	 * @param array $fields purpose, purpose_en, duration_label, duration_label_en, table_group, applies_to, applies_to_en, category.
	 * @return array|WP_Error The cookie row joined with its category, or an error.
	 */
	public static function quick_edit( $id, array $fields ) {
		$cookie = CookieRepository::find( $id );
		if ( ! $cookie ) {
			return new WP_Error( 'dscc_cookie_missing', __( 'Cookie not found.', 'ds-cookie-consent' ) );
		}
		$text = array_intersect_key( $fields, array_flip( array( 'purpose', 'purpose_en', 'duration_label', 'duration_label_en', 'table_group', 'applies_to', 'applies_to_en' ) ) );
		if ( $text ) {
			CookieRepository::update( $cookie['id'], $text );
		}
		if ( isset( $fields['category'] ) && '' !== $fields['category'] ) {
			if ( ! in_array( $fields['category'], self::ASSIGNABLE, true ) ) {
				return new WP_Error( 'dscc_cookie_category', __( 'Invalid cookie category.', 'ds-cookie-consent' ) );
			}
			if ( self::category_of( CookieRepository::find( $cookie['id'] ) ) !== $fields['category'] ) {
				self::move_category( array( $cookie['id'] ), $fields['category'] );
			}
		}
		$fresh = CookieRepository::find( $cookie['id'] );
		return array_merge( $fresh, array( 'category_key' => self::category_of( $fresh ) ) );
	}

	/**
	 * Add a cookie by hand. It is created confirmed; it needs a vendor (existing service slug) or a category.
	 *
	 * @param array $input name, storage, domain, service (slug), category, purpose, purpose_en, duration_label, duration_label_en.
	 * @return int|WP_Error New cookie id.
	 */
	public static function add_manual( array $input ) {
		$name = isset( $input['name'] ) ? trim( sanitize_text_field( (string) $input['name'] ) ) : '';
		if ( '' === $name ) {
			return new WP_Error( 'dscc_cookie_name', __( 'Enter a cookie name.', 'ds-cookie-consent' ) );
		}
		$service_id = 0;
		if ( ! empty( $input['service'] ) ) {
			$service = ServiceRepository::find_by_slug( (string) $input['service'] );
			if ( ! $service ) {
				return new WP_Error( 'dscc_cookie_service', __( 'The vendor does not exist.', 'ds-cookie-consent' ) );
			}
			$service_id = $service['id'];
			if ( 'confirmed' !== $service['status'] ) {
				ServiceRepository::set_status( $service['slug'], 'confirmed' );
			}
		} elseif ( isset( $input['category'] ) && in_array( $input['category'], self::ASSIGNABLE, true ) ) {
			$service_id = ServiceRepository::site_service( $input['category'] );
		} else {
			return new WP_Error( 'dscc_cookie_category', __( 'Choose a vendor or a cookie category.', 'ds-cookie-consent' ) );
		}
		$id = CookieRepository::upsert(
			array_merge(
				array_intersect_key( $input, array_flip( array( 'storage', 'domain', 'purpose', 'purpose_en', 'duration_label', 'duration_label_en', 'table_group', 'applies_to', 'applies_to_en' ) ) ),
				array(
					'name'       => $name,
					'service_id' => $service_id,
					'status'     => 'confirmed',
					'source'     => 'manual',
				)
			)
		);
		return $id ? $id : new WP_Error( 'dscc_cookie_save', __( 'Could not save the cookie.', 'ds-cookie-consent' ) );
	}

	/**
	 * Effective category of a cookie row.
	 *
	 * @param array $cookie Cookie row.
	 * @return string
	 */
	public static function category_of( array $cookie ) {
		$service = ! empty( $cookie['service_id'] ) ? ServiceRepository::find( $cookie['service_id'] ) : null;
		return $service ? $service['category_key'] : 'unclassified';
	}
}
