<?php
/**
 * [dscc_cookie_table category="analytics" columns="name,service,purpose,duration,applies" lang="vi"]
 *
 * Builds the cookie tables of a cookie policy page from the confirmed catalog, laid out as a cookie policy section:
 * a heading per group ("Cookie cần thiết", ...) and a table with Cookie | Bên đặt | Mục đích | Thời hạn | Áp dụng tại.
 * Cookies of one service with the same purpose share a row ("_clck, _clsk"). No category = one table per group
 * (necessary, analytics, marketing; functional too when that category is enabled, otherwise it is merged into necessary).
 * The provider links to its own policy through LinkHelper (new tab, nofollow noopener noreferrer).
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Frontend;

use DSolutions\CookieConsent\Settings\Settings;
use DSolutions\CookieConsent\Support\LinkHelper;

defined( 'ABSPATH' ) || exit;

final class CookieTableShortcode {

	const COLUMNS = array( 'name', 'service', 'purpose', 'duration', 'applies' );

	/**
	 * Shortcode callback.
	 *
	 * @param array|string $atts category (necessary|functional|analytics|marketing, comma separated), columns, lang (vi|en), heading (1|0).
	 * @return string Safe HTML.
	 */
	public static function render( $atts ) {
		$atts    = shortcode_atts(
			array(
				'category' => '',
				'columns'  => implode( ',', self::COLUMNS ),
				'lang'     => '',
				'heading'  => '1',
			),
			$atts,
			'dscc_cookie_table'
		);
		$lang    = in_array( $atts['lang'], array( 'vi', 'en' ), true ) ? $atts['lang'] : LanguageResolver::current();
		$texts   = Settings::texts( $lang );
		$show_fn = ! empty( Settings::get( 'advanced', 'show_functional', false ) );
		$columns = array_values( array_intersect( self::COLUMNS, array_map( 'trim', explode( ',', strtolower( (string) $atts['columns'] ) ) ) ) );
		if ( ! $columns ) {
			$columns = self::COLUMNS;
		}

		$services = ServiceCatalog::services( $show_fn, 0 );
		$html     = '';
		foreach ( self::categories( (string) $atts['category'], $show_fn ) as $category ) {
			$rows  = self::rows( $services, $category, $lang );
			$html .= '<div class="dscc-cookie-table dscc-cookie-table--' . esc_attr( $category ) . '">';
			if ( '0' !== (string) $atts['heading'] ) {
				$html .= '<h4 class="dscc-cookie-table__title">' . esc_html( $texts[ 'tbl_title_' . $category ] ) . '</h4>';
			}
			$html .= $rows ? self::table( $rows, $columns, $texts ) : '<p class="dscc-cookie-table__empty">' . esc_html( $texts['tbl_empty'] ) . '</p>';
			$html .= '</div>';
		}
		return $html;
	}

	/**
	 * Categories to print.
	 *
	 * @param string $requested Attribute value.
	 * @param bool   $show_fn   Functional category enabled.
	 * @return string[]
	 */
	private static function categories( $requested, $show_fn ) {
		$all = $show_fn ? array( 'necessary', 'functional', 'analytics', 'marketing' ) : array( 'necessary', 'analytics', 'marketing' );
		if ( '' === trim( $requested ) ) {
			return $all;
		}
		$out = array();
		foreach ( explode( ',', strtolower( $requested ) ) as $key ) {
			$key = trim( $key );
			$key = 'functional' === $key && ! $show_fn ? 'necessary' : $key;
			if ( in_array( $key, $all, true ) && ! in_array( $key, $out, true ) ) {
				$out[] = $key;
			}
		}
		return $out;
	}

	/**
	 * Table rows of one category, in the order the cookies were added (the order of the policy table).
	 *
	 * - Cookies sharing a table_group form one row: names joined by ", " in that order; the first cookie of the
	 *   group (the lead) provides the vendor, purpose, duration and "applies to" texts of the row.
	 * - Other cookies of one vendor with the same purpose share a row; if their durations differ they are listed per
	 *   cookie ("_ga: 2 năm; _gid: 24 giờ") so no duration is ambiguous.
	 *
	 * @param array[] $services Normalised services.
	 * @param string  $category Category key.
	 * @param string  $lang     vi|en.
	 * @return array[] Rows with names, service, purpose, duration, applies.
	 */
	private static function rows( array $services, $category, $lang ) {
		$site  = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$items = array();
		foreach ( $services as $service ) {
			if ( $service['category'] === $category ) {
				foreach ( $service['cookies'] as $cookie ) {
					$items[] = array(
						'service' => $service,
						'cookie'  => $cookie,
					);
				}
			}
		}
		// Stable sort by cookie id (usort is not stable before PHP 8): keep the original index as a tie-breaker.
		foreach ( $items as $i => $item ) {
			$items[ $i ]['pos'] = $i;
		}
		usort(
			$items,
			static function ( $a, $b ) {
				return array( $a['cookie']['id'], $a['pos'] ) <=> array( $b['cookie']['id'], $b['pos'] );
			}
		);

		$groups = array();
		foreach ( $items as $item ) {
			$cookie = $item['cookie'];
			$key    = '' !== $cookie['group'] ? 'g:' . $cookie['group'] : 's:' . $item['service']['slug'] . '|' . $cookie['purpose'][ $lang ];
			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array(
					'grouped' => '' !== $cookie['group'],
					'service' => $item['service'],
					'cookies' => array(),
				);
			}
			$groups[ $key ]['cookies'][] = $cookie;
		}

		$rows = array();
		foreach ( $groups as $group ) {
			$lead   = $group['cookies'][0];
			$rows[] = array(
				'names'    => array_map(
					static function ( $c ) use ( $lang ) {
						return $c['name'][ $lang ];
					},
					$group['cookies']
				),
				'service'  => $group['service'],
				'purpose'  => $lead['purpose'][ $lang ],
				'duration' => $group['grouped'] ? $lead['duration'][ $lang ] : self::merged_durations( $group['cookies'], $lang ),
				'applies'  => $group['grouped'] ? self::applies_of( $lead, $lang, $site ) : self::merged_applies( $group['cookies'], $lang, $site ),
			);
		}
		return $rows;
	}

	/**
	 * "Applies to" text of a cookie: the admin's text, else its domain, else the site host.
	 *
	 * @param array  $cookie Cookie.
	 * @param string $lang   vi|en.
	 * @param string $site   Site host.
	 * @return string
	 */
	private static function applies_of( array $cookie, $lang, $site ) {
		if ( '' !== $cookie['applies'][ $lang ] ) {
			return $cookie['applies'][ $lang ];
		}
		return '' !== $cookie['domain'] ? $cookie['domain'] : $site;
	}

	/**
	 * Distinct "applies to" texts of cookies merged only because they share a purpose.
	 *
	 * @param array[] $cookies Cookies of the row.
	 * @param string  $lang    vi|en.
	 * @param string  $site    Site host.
	 * @return string
	 */
	private static function merged_applies( array $cookies, $lang, $site ) {
		$texts = array();
		foreach ( $cookies as $cookie ) {
			$texts[] = self::applies_of( $cookie, $lang, $site );
		}
		return implode( ', ', array_values( array_unique( $texts ) ) );
	}

	/**
	 * Durations of cookies merged only because they share a purpose: one text when they agree, otherwise per cookie.
	 *
	 * @param array[] $cookies Cookies of the row.
	 * @param string  $lang    vi|en.
	 * @return string
	 */
	private static function merged_durations( array $cookies, $lang ) {
		$durations = array();
		foreach ( $cookies as $cookie ) {
			$durations[] = $cookie['duration'][ $lang ];
		}
		$unique = array_values( array_unique( $durations ) );
		if ( 1 === count( $unique ) ) {
			return $unique[0];
		}
		$parts = array();
		foreach ( $cookies as $i => $cookie ) {
			if ( '' !== $durations[ $i ] ) {
				$parts[] = $cookie['name'][ $lang ] . ': ' . $durations[ $i ];
			}
		}
		return implode( '; ', $parts );
	}

	/**
	 * Table markup.
	 *
	 * @param array[]  $rows    Rows.
	 * @param string[] $columns Column keys.
	 * @param array    $texts   Texts for the language.
	 * @return string Safe HTML.
	 */
	private static function table( array $rows, array $columns, array $texts ) {
		$html = '<table class="dscc-cookie-table__grid"><thead><tr>';
		foreach ( $columns as $column ) {
			$html .= '<th scope="col">' . esc_html( $texts[ 'tbl_col_' . $column ] ) . '</th>';
		}
		$html .= '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$html .= '<tr>';
			foreach ( $columns as $column ) {
				$html .= '<td data-th="' . esc_attr( $texts[ 'tbl_col_' . $column ] ) . '">' . self::cell( $column, $row ) . '</td>';
			}
			$html .= '</tr>';
		}
		return $html . '</tbody></table>';
	}

	/**
	 * One cell.
	 *
	 * @param string $column Column key.
	 * @param array  $row    Row.
	 * @return string Safe HTML.
	 */
	private static function cell( $column, array $row ) {
		switch ( $column ) {
			case 'name':
				return esc_html( implode( ', ', $row['names'] ) );
			case 'service':
				$link = LinkHelper::external_policy_link( $row['service']['privacy_url'], $row['service']['name'] );
				return '' !== $link ? $link : esc_html( $row['service']['name'] );
			case 'purpose':
				return esc_html( $row['purpose'] );
			case 'duration':
				return esc_html( $row['duration'] );
			default:
				return esc_html( $row['applies'] );
		}
	}
}
