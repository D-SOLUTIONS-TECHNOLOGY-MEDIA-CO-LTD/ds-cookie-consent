<?php
/**
 * E2E fixture (wp eval-file): fills the catalog through the same code the admin screens use.
 * Usage: wp eval-file <path> <command> [args...]
 *   enable <slug> [<slug>...]   tick known platforms (service + cookies confirmed)
 *   disable <slug> [...]        untick
 *   block <slug> <0|1>          "block until consent" switch
 *   preset                      load presets/d-solutions-standard.json (rows, with their own statuses)
 *   service <slug> <name> <category> <status>  a custom vendor with the given status (for example auto)
 *   newcookie <slug> <name>     a cookie waiting for review (status new) of an existing vendor
 *   orphan <name>               a cookie with no vendor, status new (as a scan would leave it)
 *   addcookie <slug> <name> <storage>  a confirmed cookie / storage key for an existing vendor
 *   page <title> <content>      create a published page, prints its id
 */

use DSolutions\CookieConsent\Catalog\FrontendCatalogCache;
use DSolutions\CookieConsent\Catalog\PlatformManager;
use DSolutions\CookieConsent\Catalog\ServiceRepository;

$command = isset( $args[0] ) ? $args[0] : '';
$rest    = array_slice( $args, 1 );

if ( 'enable' === $command ) {
	foreach ( $rest as $slug ) {
		PlatformManager::enable( $slug ) || WP_CLI::error( 'unknown platform ' . $slug );
	}
} elseif ( 'disable' === $command ) {
	foreach ( $rest as $slug ) {
		PlatformManager::disable( $slug );
	}
} elseif ( 'block' === $command ) {
	ServiceRepository::set_block( $rest[0], '1' === $rest[1] );
} elseif ( 'preset' === $command ) {
	require_once dirname( __DIR__, 2 ) . '/php/integration/fixtures/PresetFixture.php';
	Dscc_Preset_Fixture::load();
} elseif ( 'service' === $command ) {
	ServiceRepository::upsert(
		array(
			'slug'         => $rest[0],
			'name'         => $rest[1],
			'category_key' => $rest[2],
			'status'       => $rest[3],
			'source'       => 'manual',
		)
	);
} elseif ( 'newcookie' === $command ) {
	$service = ServiceRepository::find_by_slug( $rest[0] );
	$service || WP_CLI::error( 'unknown service ' . $rest[0] );
	\DSolutions\CookieConsent\Catalog\CookieRepository::upsert(
		array(
			'name'       => $rest[1],
			'service_id' => $service['id'],
			'status'     => 'new',
			'source'     => 'scan',
		)
	);
} elseif ( 'orphan' === $command ) {
	\DSolutions\CookieConsent\Catalog\CookieRepository::upsert(
		array(
			'name'   => $rest[0],
			'status' => 'new',
			'source' => 'scan',
		)
	);
} elseif ( 'addcookie' === $command ) {
	$service = ServiceRepository::find_by_slug( $rest[0] );
	$service || WP_CLI::error( 'unknown service ' . $rest[0] );
	\DSolutions\CookieConsent\Catalog\CookieRepository::upsert(
		array(
			'name'       => $rest[1],
			'storage'    => $rest[2],
			'service_id' => $service['id'],
			'status'     => 'confirmed',
			'source'     => 'manual',
		)
	);
} elseif ( 'page' === $command ) {
	echo wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => $rest[0],
			'post_content' => $rest[1],
		)
	) . "\n";
	return;
} else {
	WP_CLI::error( 'unknown command ' . $command );
}

FrontendCatalogCache::rebuild();
echo "catalog ok\n";
