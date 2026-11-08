<?php
/**
 * Appearance tab.
 *
 * @var array $s Appearance settings.
 * @package DSolutions\CookieConsent
 */

use DSolutions\CookieConsent\Admin\FieldRenderer as F;

defined( 'ABSPATH' ) || exit;

$g = 'appearance';
?>
<div class="dscc-split">
	<div class="dscc-split__form">
		<div class="dscc-contrast-live" data-dscc-contrast-live aria-live="polite"></div>
		<table class="form-table" role="presentation">
			<?php
			F::row(
				__( 'Layout style', 'ds-cookie-consent' ),
				'dscc-appearance-layout',
				F::select(
					$g,
					'layout',
					$s['layout'],
					array(
						'card' => __( 'Compact (small box in a corner)', 'ds-cookie-consent' ),
						'bar'  => __( 'Horizontal bar (with a 3-tab popup)', 'ds-cookie-consent' ),
					)
				)
			);
			F::row( __( 'Banner logo', 'ds-cookie-consent' ), 'dscc-appearance-logo_id', F::media( $g, 'logo_id', (int) $s['logo_id'] ), __( 'PNG, WebP or SVG (SVG is sanitised on upload). Leave empty to use the site logo.', 'ds-cookie-consent' ) );
			F::row( __( 'Popup logo', 'ds-cookie-consent' ), 'dscc-appearance-logo_popup_id', F::media( $g, 'logo_popup_id', (int) $s['logo_popup_id'] ), __( 'Leave empty to use the banner logo.', 'ds-cookie-consent' ) );
			F::row(
				__( 'Banner logo height (px)', 'ds-cookie-consent' ),
				'dscc-appearance-logo_height',
				F::input(
					$g,
					'logo_height',
					$s['logo_height'],
					'number',
					array(
						'min'   => 12,
						'max'   => 120,
						'class' => 'small-text',
					)
				)
			);
			F::row(
				__( 'Popup logo height (px)', 'ds-cookie-consent' ),
				'dscc-appearance-logo_popup_height',
				F::input(
					$g,
					'logo_popup_height',
					$s['logo_popup_height'],
					'number',
					array(
						'min'   => 12,
						'max'   => 120,
						'class' => 'small-text',
					)
				)
			);
			F::row( __( 'Primary colour', 'ds-cookie-consent' ), 'dscc-appearance-color_primary', F::color( $g, 'color_primary', $s['color_primary'], '#005ea3' ), __( 'Accept button, active toggles, links, the selected tab.', 'ds-cookie-consent' ) );
			F::row( __( 'Primary colour on hover', 'ds-cookie-consent' ), 'dscc-appearance-color_primary_hover', F::color( $g, 'color_primary_hover', $s['color_primary_hover'] ), __( 'Leave empty to compute it (12% darker).', 'ds-cookie-consent' ) );
			F::row( __( 'Banner text colour', 'ds-cookie-consent' ), 'dscc-appearance-color_text', F::color( $g, 'color_text', $s['color_text'], '#1e3a5f' ) );
			F::row( __( 'Banner background', 'ds-cookie-consent' ), 'dscc-appearance-color_bar_bg', F::color( $g, 'color_bar_bg', $s['color_bar_bg'], '#f1f5f9' ) );
			F::row( __( 'Reject button background', 'ds-cookie-consent' ), 'dscc-appearance-color_reject_bg', F::color( $g, 'color_reject_bg', $s['color_reject_bg'] ), __( 'Leave empty to compute it from the primary colour (12% mixed with white).', 'ds-cookie-consent' ) );
			F::row( __( 'Popup background', 'ds-cookie-consent' ), 'dscc-appearance-color_modal_bg', F::color( $g, 'color_modal_bg', $s['color_modal_bg'], '#f6f6f6' ) );
			F::row( __( 'Font', 'ds-cookie-consent' ), 'dscc-appearance-font', F::input( $g, 'font', $s['font'], 'text', array( 'class' => 'regular-text' ) ), __( '"inherit" to follow the theme, or the name of a font already available on the site.', 'ds-cookie-consent' ) );
			F::row(
				__( 'Button corner radius', 'ds-cookie-consent' ),
				'dscc-appearance-button_radius',
				F::input(
					$g,
					'button_radius',
					$s['button_radius'],
					'text',
					array(
						'class'       => 'small-text',
						'placeholder' => 'auto',
					)
				),
				__( 'Leave empty for automatic (bar: pill, compact box: 8px). Enter "pill" or a number of px.', 'ds-cookie-consent' )
			);
			F::row(
				__( 'Button order on the banner', 'ds-cookie-consent' ),
				'dscc-appearance-button_order',
				F::select(
					$g,
					'button_order',
					$s['button_order'],
					array(
						''             => __( 'Automatic by layout style', 'ds-cookie-consent' ),
						'accept_first' => __( 'Accept first', 'ds-cookie-consent' ),
						'accept_last'  => __( 'Accept at the far right', 'ds-cookie-consent' ),
					)
				)
			);
			F::row(
				__( 'Customise button style', 'ds-cookie-consent' ),
				'dscc-appearance-customize_style',
				F::select(
					$g,
					'customize_style',
					$s['customize_style'],
					array(
						'link'   => __( 'Link', 'ds-cookie-consent' ),
						'button' => __( 'Button', 'ds-cookie-consent' ),
					)
				)
			);
			F::row(
				__( 'Box position (Compact style)', 'ds-cookie-consent' ),
				'dscc-appearance-card_position',
				F::select(
					$g,
					'card_position',
					$s['card_position'],
					array(
						'bottom_left'   => __( 'Bottom left', 'ds-cookie-consent' ),
						'bottom_right'  => __( 'Bottom right', 'ds-cookie-consent' ),
						'bottom_center' => __( 'Bottom centre', 'ds-cookie-consent' ),
					)
				)
			);
			F::row( __( 'Minimised icon', 'ds-cookie-consent' ), 'dscc-appearance-widget_enabled', F::checkbox( $g, 'widget_enabled', $s['widget_enabled'], __( 'Show the shield icon after the visitor chooses', 'ds-cookie-consent' ) ), __( 'The Compact style always shows the icon because it is the visitor\'s only way to change the choice.', 'ds-cookie-consent' ) );
			F::row(
				__( 'Icon corner', 'ds-cookie-consent' ),
				'dscc-appearance-widget_side',
				F::select(
					$g,
					'widget_side',
					$s['widget_side'],
					array(
						'left'  => __( 'Left', 'ds-cookie-consent' ),
						'right' => __( 'Right', 'ds-cookie-consent' ),
					)
				)
			);
			F::row(
				__( 'Distance from the bottom edge (px)', 'ds-cookie-consent' ),
				'dscc-appearance-widget_bottom',
				F::input(
					$g,
					'widget_bottom',
					$s['widget_bottom'],
					'number',
					array(
						'min'   => 0,
						'max'   => 400,
						'class' => 'small-text',
					)
				)
			);
			F::row(
				__( 'Distance from the side edge (px)', 'ds-cookie-consent' ),
				'dscc-appearance-widget_offset_x',
				F::input(
					$g,
					'widget_offset_x',
					$s['widget_offset_x'],
					'number',
					array(
						'min'   => 0,
						'max'   => 400,
						'class' => 'small-text',
					)
				)
			);
			F::row(
				__( 'When the icon is shown', 'ds-cookie-consent' ),
				'dscc-appearance-widget_show',
				F::select(
					$g,
					'widget_show',
					$s['widget_show'],
					array(
						'always'       => __( 'After every choice', 'ds-cookie-consent' ),
						'after_reject' => __( 'Only after Reject (bar style)', 'ds-cookie-consent' ),
					)
				)
			);
			F::row(
				__( 'Text on icon hover', 'ds-cookie-consent' ),
				'dscc-appearance-widget_label',
				F::input(
					$g,
					'widget_label',
					$s['widget_label'],
					'text',
					array(
						'class'     => 'regular-text',
						'maxlength' => 40,
					)
				),
				__( 'Leave empty to use the default text (Content tab).', 'ds-cookie-consent' )
			);
			F::row(
				__( 'Dark overlay while the popup is open (%)', 'ds-cookie-consent' ),
				'dscc-appearance-overlay_opacity',
				F::input(
					$g,
					'overlay_opacity',
					$s['overlay_opacity'],
					'number',
					array(
						'min'   => 0,
						'max'   => 60,
						'class' => 'small-text',
					)
				)
			);
			F::row( __( 'Per-vendor toggles', 'ds-cookie-consent' ), 'dscc-appearance-per_service_toggle', F::checkbox( $g, 'per_service_toggle', $s['per_service_toggle'], __( 'Let visitors switch each vendor on or off in the popup', 'ds-cookie-consent' ) ), __( 'Only takes effect when the cookie catalog has vendors.', 'ds-cookie-consent' ) );
			F::row( __( 'Custom CSS', 'ds-cookie-consent' ), 'dscc-appearance-custom_css', F::textarea( $g, 'custom_css', $s['custom_css'], 6 ), __( 'Every selector is prefixed with #dscc automatically. @import, url(javascript:) and expression() are not allowed.', 'ds-cookie-consent' ) );
			?>
		</table>
		<?php submit_button( __( 'Save changes', 'ds-cookie-consent' ) ); ?>
	</div>
	<div class="dscc-split__preview">
		<?php require DSCC_DIR . 'templates/admin/preview-panel.php'; ?>
	</div>
</div>
