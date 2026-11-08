<?php
/**
 * E2E fixture (copied into mu-plugins by the spec): one analytics service through the catalog filter.
 */

add_filter(
	'dscc_catalog_services',
	static function () {
		return array(
			array(
				'slug'        => 'littled-ga',
				'name'        => 'LittleD Analytics',
				'category'    => 'analytics',
				'privacy_url' => 'https://policies.example/privacy',
				'cookies'     => array(
					array(
						'name'     => '_littled',
						'duration' => '1 nam',
						'purpose'  => 'Test',
					),
				),
			),
		);
	}
);
