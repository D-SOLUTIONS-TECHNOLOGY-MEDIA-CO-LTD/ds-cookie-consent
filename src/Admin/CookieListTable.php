<?php
/**
 * The cookie catalog table (spec 6.6): cookies with their vendor, category, duration, who sets them, when they
 * were last seen, status and source. New cookies are listed first; rows have inline quick edit.
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Admin;

use DSolutions\CookieConsent\Catalog\CookieQuery;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

final class CookieListTable extends \WP_List_Table {

	const PER_PAGE = 20;

	/**
	 * Active filters read from the query string.
	 *
	 * @var array
	 */
	private $filters = array();

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'cookie',
				'plural'   => 'cookies',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Filters from the current request (display only, no state change).
	 *
	 * @return array
	 */
	public static function request_filters() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- list filters and sorting only read data.
		$read = static function ( $key ) {
			return isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : '';
		};
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		return array(
			'category' => sanitize_key( $read( 'category' ) ),
			'status'   => sanitize_key( $read( 'status' ) ),
			'source'   => sanitize_key( $read( 'source' ) ),
			'search'   => $read( 's' ),
			'orderby'  => sanitize_key( $read( 'orderby' ) ),
			'order'    => sanitize_key( $read( 'order' ) ),
		);
	}

	/**
	 * Columns.
	 *
	 * @return array<string,string>
	 */
	public function get_columns() {
		return array(
			'cb'        => '<input type="checkbox" />',
			'name'      => __( 'Cookie name', 'ds-cookie-consent' ),
			'service'   => __( 'Vendor', 'ds-cookie-consent' ),
			'category'  => __( 'Category', 'ds-cookie-consent' ),
			'duration'  => __( 'Duration', 'ds-cookie-consent' ),
			'set_by'    => __( 'Set by', 'ds-cookie-consent' ),
			'last_seen' => __( 'Last seen', 'ds-cookie-consent' ),
			'seen'      => __( 'Times seen', 'ds-cookie-consent' ),
			'status'    => __( 'Status', 'ds-cookie-consent' ),
			'source'    => __( 'Source', 'ds-cookie-consent' ),
		);
	}

	/**
	 * Sortable columns.
	 *
	 * @return array
	 */
	protected function get_sortable_columns() {
		return array(
			'name'      => array( 'name', false ),
			'service'   => array( 'service', false ),
			'category'  => array( 'category', false ),
			'last_seen' => array( 'last_seen', true ),
			'seen'      => array( 'seen', true ),
			'status'    => array( 'status', false ),
			'source'    => array( 'source', false ),
		);
	}

	/**
	 * Bulk actions.
	 *
	 * @return array<string,string>
	 */
	protected function get_bulk_actions() {
		$actions = array(
			'confirm' => __( 'Confirm', 'ds-cookie-consent' ),
			'ignore'  => __( 'Ignored', 'ds-cookie-consent' ),
		);
		foreach ( CatalogLabels::categories() as $key => $label ) {
			/* translators: %s: category name */
			$actions[ 'move_' . $key ] = sprintf( __( 'Move to category: %s', 'ds-cookie-consent' ), $label );
		}
		$actions['delete'] = __( 'Delete', 'ds-cookie-consent' );
		return $actions;
	}

	/**
	 * Load the rows.
	 */
	public function prepare_items() {
		$this->filters         = self::request_filters();
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'name' );
		$result                = CookieQuery::run(
			array_merge(
				$this->filters,
				array(
					'page'     => $this->get_pagenum(),
					'per_page' => self::PER_PAGE,
				)
			)
		);
		$this->items           = $result['rows'];
		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => self::PER_PAGE,
			)
		);
	}

	/**
	 * Message for an empty table.
	 */
	public function no_items() {
		esc_html_e( 'No cookies yet. Tick a platform on the Platforms tab or add a cookie manually below.', 'ds-cookie-consent' );
	}

	/**
	 * Row checkbox.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_cb( $item ) {
		$id = 'dscc-cb-' . (int) $item['id'];
		return '<label class="screen-reader-text" for="' . esc_attr( $id ) . '">'
			/* translators: %s: cookie name */
			. esc_html( sprintf( __( 'Select %s', 'ds-cookie-consent' ), $item['name'] ) ) . '</label>'
			. '<input type="checkbox" id="' . esc_attr( $id ) . '" name="cookie_ids[]" value="' . esc_attr( $item['id'] ) . '" />';
	}

	/**
	 * Name column: name, purpose, row actions and the data the quick edit form starts from.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_name( $item ) {
		$edit = wp_json_encode(
			array(
				'id'                => $item['id'],
				'category'          => 'unclassified' === $item['category_key'] ? '' : $item['category_key'],
				'purpose'           => (string) $item['purpose'],
				'purpose_en'        => (string) $item['purpose_en'],
				'duration_label'    => $item['duration_label'],
				'duration_label_en' => $item['duration_label_en'],
				'table_group'       => $item['table_group'],
				'applies_to'        => $item['applies_to'],
				'applies_to_en'     => $item['applies_to_en'],
				'vendor'            => (string) $item['service_name'],
				'shared'            => ! empty( $item['service_slug'] ) && 0 !== strpos( (string) $item['service_slug'], 'site-' ),
			)
		);
		$out  = '<strong class="dscc-ck-name">' . esc_html( $item['name'] ) . '</strong>';
		if ( 'cookie' !== $item['storage'] ) {
			$out .= ' <span class="dscc-badge">' . esc_html( $item['storage'] ) . '</span>';
		}
		$out .= '<div class="dscc-ck-purpose">' . esc_html( (string) $item['purpose'] ) . '</div>';
		$out .= '<div class="dscc-ck-actions"><button type="button" class="button-link dscc-qe-open" data-dscc-edit="' . esc_attr( (string) $edit ) . '">' . esc_html__( 'Quick edit', 'ds-cookie-consent' ) . '</button></div>';
		return $out;
	}

	/**
	 * Plain columns.
	 *
	 * @param array  $item        Row.
	 * @param string $column_name Column key.
	 * @return string
	 */
	protected function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'service':
				return esc_html( (string) $item['service_name'] );
			case 'category':
				$labels = CatalogLabels::categories_with_unclassified();
				return '<span class="dscc-ck-category">' . esc_html( isset( $labels[ $item['category_key'] ] ) ? $labels[ $item['category_key'] ] : $item['category_key'] ) . '</span>';
			case 'duration':
				return '<span class="dscc-ck-duration">' . esc_html( $item['duration_label'] ) . '</span>';
			case 'set_by':
				return esc_html( $item['set_by'] );
			case 'last_seen':
				return $item['last_seen_at'] ? esc_html( get_date_from_gmt( $item['last_seen_at'], 'd/m/Y H:i' ) ) : '&mdash;';
			case 'seen':
				return esc_html( (string) $item['seen_count'] );
			case 'status':
				$labels = CatalogLabels::statuses();
				return '<span class="dscc-status dscc-status--' . esc_attr( $item['status'] ) . '">' . esc_html( isset( $labels[ $item['status'] ] ) ? $labels[ $item['status'] ] : $item['status'] ) . '</span>';
			case 'source':
				$labels = CatalogLabels::sources();
				return esc_html( isset( $labels[ $item['source'] ] ) ? $labels[ $item['source'] ] : $item['source'] );
		}
		return '';
	}

	/**
	 * Row markup with the row id the quick edit script looks for.
	 *
	 * @param array $item Row.
	 */
	public function single_row( $item ) {
		echo '<tr data-dscc-cookie="' . esc_attr( $item['id'] ) . '" class="dscc-ck-row dscc-ck-row--' . esc_attr( $item['status'] ) . '">';
		$this->single_row_columns( $item );
		echo '</tr>';
	}
}
