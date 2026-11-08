<?php
/**
 * Small helpers that print escaped settings form controls named dscc[group][key].
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Admin;

defined( 'ABSPATH' ) || exit;

final class FieldRenderer {

	/**
	 * Form control name.
	 *
	 * @param string $group Group.
	 * @param string $key   Key.
	 * @return string
	 */
	private static function name( $group, $key ) {
		return 'dscc[' . $group . '][' . $key . ']';
	}

	/**
	 * Field id.
	 *
	 * @param string $group Group.
	 * @param string $key   Key.
	 * @return string
	 */
	private static function id( $group, $key ) {
		return 'dscc-' . $group . '-' . $key;
	}

	/**
	 * Table row wrapper.
	 *
	 * @param string $label    Label text.
	 * @param string $field_id Control id.
	 * @param string $control  Escaped control HTML.
	 * @param string $help     Optional help text.
	 */
	public static function row( $label, $field_id, $control, $help = '' ) {
		echo '<tr><th scope="row"><label for="' . esc_attr( $field_id ) . '">' . esc_html( $label ) . '</label></th><td>';
		echo $control; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built by the helpers below with escaping.
		if ( '' !== $help ) {
			echo '<p class="description">' . esc_html( $help ) . '</p>';
		}
		echo '</td></tr>';
	}

	/**
	 * Single-line text/number/url input HTML.
	 *
	 * @param string $group Group.
	 * @param string $key   Key.
	 * @param mixed  $value Current value.
	 * @param string $type  Input type.
	 * @param array  $attrs Extra attributes (min, max, placeholder, class).
	 * @return string
	 */
	public static function input( $group, $key, $value, $type = 'text', array $attrs = array() ) {
		$extra = '';
		foreach ( $attrs as $name => $val ) {
			$extra .= ' ' . esc_attr( $name ) . '="' . esc_attr( $val ) . '"';
		}
		return '<input type="' . esc_attr( $type ) . '" id="' . esc_attr( self::id( $group, $key ) ) . '" name="' . esc_attr( self::name( $group, $key ) ) . '" value="' . esc_attr( (string) $value ) . '" data-dscc-field="' . esc_attr( $group . '.' . $key ) . '"' . $extra . '>';
	}

	/**
	 * Colour picker input HTML (wp-color-picker enhances it).
	 *
	 * @param string $group       Group.
	 * @param string $key         Key.
	 * @param string $value       Current hex or empty.
	 * @param string $placeholder Shown when empty.
	 * @return string
	 */
	public static function color( $group, $key, $value, $placeholder = '' ) {
		return self::input(
			$group,
			$key,
			$value,
			'text',
			array(
				'class'              => 'dscc-color',
				'data-default-color' => $placeholder,
			)
		);
	}

	/**
	 * Select HTML.
	 *
	 * @param string   $group   Group.
	 * @param string   $key     Key.
	 * @param string   $value   Current value.
	 * @param string[] $options value => label.
	 * @return string
	 */
	public static function select( $group, $key, $value, array $options ) {
		$html = '<select id="' . esc_attr( self::id( $group, $key ) ) . '" name="' . esc_attr( self::name( $group, $key ) ) . '" data-dscc-field="' . esc_attr( $group . '.' . $key ) . '">';
		foreach ( $options as $option => $label ) {
			$html .= '<option value="' . esc_attr( (string) $option ) . '"' . selected( (string) $value, (string) $option, false ) . '>' . esc_html( $label ) . '</option>';
		}
		return $html . '</select>';
	}

	/**
	 * Checkbox HTML.
	 *
	 * @param string $group Group.
	 * @param string $key   Key.
	 * @param bool   $value Current value.
	 * @param string $label Text next to the box.
	 * @return string
	 */
	public static function checkbox( $group, $key, $value, $label ) {
		return '<label><input type="checkbox" id="' . esc_attr( self::id( $group, $key ) ) . '" name="' . esc_attr( self::name( $group, $key ) ) . '" value="1" data-dscc-field="' . esc_attr( $group . '.' . $key ) . '"' . checked( (bool) $value, true, false ) . '> ' . esc_html( $label ) . '</label>';
	}

	/**
	 * Textarea HTML.
	 *
	 * @param string $group Group.
	 * @param string $key   Key.
	 * @param string $value Current value.
	 * @param int    $rows  Rows.
	 * @return string
	 */
	public static function textarea( $group, $key, $value, $rows = 4 ) {
		return '<textarea class="large-text code" rows="' . (int) $rows . '" id="' . esc_attr( self::id( $group, $key ) ) . '" name="' . esc_attr( self::name( $group, $key ) ) . '" data-dscc-field="' . esc_attr( $group . '.' . $key ) . '">' . esc_textarea( (string) $value ) . '</textarea>';
	}

	/**
	 * Media library picker: hidden id field, thumbnail and buttons.
	 *
	 * @param string $group Group.
	 * @param string $key   Key (attachment id).
	 * @param int    $id    Attachment id.
	 * @return string
	 */
	public static function media( $group, $key, $id ) {
		$url   = $id > 0 ? wp_get_attachment_url( $id ) : '';
		$html  = '<div class="dscc-media" data-dscc-media>';
		$html .= '<input type="hidden" id="' . esc_attr( self::id( $group, $key ) ) . '" name="' . esc_attr( self::name( $group, $key ) ) . '" value="' . (int) $id . '" data-dscc-field="' . esc_attr( $group . '.' . $key ) . '" data-dscc-media-url="' . esc_url( (string) $url ) . '">';
		$html .= '<img class="dscc-media__img" alt=""' . ( $url ? ' src="' . esc_url( $url ) . '"' : ' hidden' ) . '>';
		$html .= '<button type="button" class="button" data-dscc-media-pick>' . esc_html__( 'Select image', 'ds-cookie-consent' ) . '</button> ';
		$html .= '<button type="button" class="button-link" data-dscc-media-clear' . ( $url ? '' : ' hidden' ) . '>' . esc_html__( 'Delete', 'ds-cookie-consent' ) . '</button>';
		return $html . '</div>';
	}
}
