<?php
/**
 * Checks an assembled plugin folder before it is zipped (and again after the zip is extracted).
 *
 * Usage: php tools/release/verify-package.php <plugin-dir> [tag]
 *
 * Fails (exit 1) when: a development path is present, a required runtime path is missing, the plugin version differs
 * between the header, the constant, readme.txt and the tag, the known-cookie database does not match its checksum
 * files, or any file name or content mentions the forbidden word or the former client brand.
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}

$dir = isset( $argv[1] ) ? rtrim( $argv[1], '/' ) : '';
$tag = isset( $argv[2] ) ? trim( $argv[2] ) : '';
if ( '' === $dir || ! is_dir( $dir ) ) {
	fwrite( STDERR, "verify-package: plugin directory not found\n" );
	exit( 2 );
}

$errors = array();

// Development material that must never ship.
$forbidden = array(
	'reference', 'SPEC.md', 'CHANGELOG.md', 'tests', 'node_modules', 'plans', 'tools', 'docs', 'bin', 'vendor',
	'.git', '.github', '.distignore', '.gitignore', '.wp-env.json', 'composer.json', 'composer.lock',
	'package.json', 'package-lock.json', 'phpcs.xml.dist', 'phpunit.xml.dist', 'phpunit-integration.xml.dist',
	'assets/src', 'test-results', 'playwright-report', '.phpunit.result.cache', '.DS_Store',
);
// Agent configuration and instruction files are named after the forbidden word, which is kept encoded here.
$word        = base64_decode( 'Y2xhdWRl' );
// A former client brand must not appear in any file name or file content of the package. The pattern is assembled
// from two parts so this script does not contain the brand itself.
$brand = '~(^|[^a-z])' . 'ip' . 'os~i';
$forbidden[] = '.' . $word;
$forbidden[] = strtoupper( $word ) . '.md';
foreach ( $forbidden as $path ) {
	if ( file_exists( $dir . '/' . $path ) ) {
		$errors[] = "must not ship: {$path}";
	}
}

// Everything the plugin needs at run time.
$required = array(
	'ds-cookie-consent.php', 'uninstall.php', 'readme.txt', 'README.md',
	'src/Plugin.php', 'src/Update/GitHubUpdater.php', 'templates/bar.php', 'templates/card.php', 'templates/admin/settings.php',
	'assets/dist/dscc.js', 'assets/dist/dscc-bar.css', 'assets/dist/dscc-card.css', 'assets/dist/dscc-admin.js', 'assets/dist/dscc-admin.css',
	'data/known-cookies.json', 'data/known-cookies.json.sha256', 'data/LICENSE-open-cookie-database.txt', 'data/NOTICE',
	'presets/d-solutions-standard.json',
	'languages/ds-cookie-consent.pot', 'languages/ds-cookie-consent-vi.po', 'languages/ds-cookie-consent-vi.mo',
	'vendor-prefixed/autoload.php', 'vendor-prefixed/enshrined/svg-sanitize/src/Sanitizer.php',
);
foreach ( $required as $path ) {
	if ( ! is_file( $dir . '/' . $path ) ) {
		$errors[] = "missing: {$path}";
	}
}

// One version everywhere.
$main    = is_file( $dir . '/ds-cookie-consent.php' ) ? (string) file_get_contents( $dir . '/ds-cookie-consent.php' ) : '';
$readme  = is_file( $dir . '/readme.txt' ) ? (string) file_get_contents( $dir . '/readme.txt' ) : '';
$header  = preg_match( '/^\s*\*\s*Version:\s*(\S+)/m', $main, $m1 ) ? $m1[1] : '';
$const   = preg_match( "/define\(\s*'DSCC_VERSION'\s*,\s*'([^']+)'/", $main, $m2 ) ? $m2[1] : '';
$stable  = preg_match( '/^Stable tag:\s*(\S+)/mi', $readme, $m3 ) ? $m3[1] : '';
if ( '' === $header || $header !== $const ) {
	$errors[] = "plugin header version ({$header}) and DSCC_VERSION ({$const}) differ";
}
if ( $stable !== $header ) {
	$errors[] = "readme.txt Stable tag ({$stable}) differs from the plugin version ({$header})";
}
if ( '' !== $tag && ltrim( $tag, 'vV' ) !== $header ) {
	$errors[] = "tag {$tag} does not match plugin version {$header}";
}
if ( false === strpos( $readme, '= ' . $header . ' =' ) ) {
	$errors[] = "readme.txt has no changelog entry for {$header}";
}

// Known-cookie database: sidecar checksum of the whole file, and the file carries its own services hash.
$db_file = $dir . '/data/known-cookies.json';
if ( is_file( $db_file ) ) {
	$raw     = (string) file_get_contents( $db_file );
	$sidecar = (string) @file_get_contents( $db_file . '.sha256' );
	if ( ! preg_match( '/^([0-9a-f]{64})\s/', $sidecar, $sm ) || hash( 'sha256', $raw ) !== $sm[1] ) {
		$errors[] = 'data/known-cookies.json does not match data/known-cookies.json.sha256';
	}
	$data = json_decode( $raw, true );
	if ( ! is_array( $data ) || empty( $data['version'] ) || empty( $data['sha256'] ) ) {
		$errors[] = 'data/known-cookies.json has no version or sha256 field';
	} else {
		if ( ! defined( 'ABSPATH' ) ) {
			define( 'ABSPATH', $dir . '/' );
		}
		if ( ! function_exists( 'wp_json_encode' ) ) {
			function wp_json_encode( $value, $flags = 0 ) { // phpcs:ignore
				return json_encode( $value, $flags );
			}
		}
		require_once $dir . '/src/Catalog/KnownDbValidator.php';
		if ( DSolutions\CookieConsent\Catalog\KnownDbValidator::services_hash( $raw ) !== strtolower( (string) $data['sha256'] ) ) {
			$errors[] = 'the sha256 field of data/known-cookies.json does not match its services';
		}
	}
}

// The forbidden word and the former client brand, case-insensitive, anywhere in the package (file names and contents).
$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
$files    = 0;
foreach ( $iterator as $file ) {
	++$files;
	$relative = substr( (string) $file, strlen( $dir ) + 1 );
	if ( false !== stripos( $relative, $word ) ) {
		$errors[] = "forbidden word in file name: {$relative}";
		continue;
	}
	if ( 1 === preg_match( $brand, $relative ) ) {
		$errors[] = "former client brand in file name: {$relative}";
		continue;
	}
	if ( $file->isFile() && $file->getSize() < 5000000 ) {
		$content = (string) file_get_contents( (string) $file );
		if ( false !== stripos( $content, $word ) ) {
			$errors[] = "forbidden word in file: {$relative}";
		} elseif ( 1 === preg_match( $brand, $content ) ) {
			$errors[] = "former client brand in file: {$relative}";
		}
	}
}

if ( $errors ) {
	fwrite( STDERR, "verify-package: FAILED\n - " . implode( "\n - ", $errors ) . "\n" );
	exit( 1 );
}
echo "verify-package: ok ({$files} files, version {$header})\n";
