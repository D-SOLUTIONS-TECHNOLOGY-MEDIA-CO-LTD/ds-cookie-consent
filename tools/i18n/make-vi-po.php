<?php
/**
 * Fill the Vietnamese catalog from the template made by `wp i18n make-pot`.
 *
 * Usage: php tools/i18n/make-vi-po.php <template.pot> <output.po> [--strict]
 *
 * Source strings in the plugin are English; tools/i18n/vi-translations.json maps each one to its Vietnamese text.
 * With --strict the script exits non-zero when a template entry has no translation or the table holds an entry the
 * template no longer contains, so a stale table is caught by the release check instead of shipping untranslated.
 *
 * @package DSolutions\CookieConsent
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}

$template = $argv[1] ?? '';
$output   = $argv[2] ?? '';
$strict   = in_array( '--strict', $argv, true );
if ( '' === $template || '' === $output || ! is_readable( $template ) ) {
	fwrite( STDERR, "Usage: php make-vi-po.php <template.pot> <output.po> [--strict]\n" );
	exit( 2 );
}

$table = json_decode( (string) file_get_contents( __DIR__ . '/vi-translations.json' ), true );
if ( ! is_array( $table ) ) {
	fwrite( STDERR, "vi-translations.json is not valid JSON\n" );
	exit( 2 );
}
$by_id = array();
foreach ( $table as $row ) {
	$by_id[ $row['msgid'] ] = $row;
}

/**
 * Decode the quoted string lines of one field into a PHP string.
 *
 * @param string[] $lines Lines such as `"text"`.
 * @return string
 */
function dscc_po_decode( array $lines ) {
	$out = '';
	foreach ( $lines as $line ) {
		$out .= stripcslashes( substr( trim( $line ), 1, -1 ) );
	}
	return $out;
}

/**
 * Encode text as PO string lines (one quoted line; long text is left on one line, which msgfmt accepts).
 *
 * @param string $text Text.
 * @return string
 */
function dscc_po_encode( $text ) {
	return '"' . str_replace( array( '\\', '"', "\n", "\t" ), array( '\\\\', '\\"', '\\n', '\\t' ), $text ) . '"';
}

$blocks  = preg_split( "/\n{2,}/", trim( (string) file_get_contents( $template ) ) );
$entries = array();
$header  = array();
foreach ( $blocks as $block ) {
	$comments = array();
	$fields   = array();
	$current  = null;
	foreach ( explode( "\n", $block ) as $line ) {
		if ( '' === trim( $line ) ) {
			continue;
		}
		if ( '#' === $line[0] ) {
			$comments[] = $line;
			continue;
		}
		if ( preg_match( '/^(msgctxt|msgid_plural|msgid|msgstr(?:\[\d+\])?)\s+(".*")\s*$/', $line, $m ) ) {
			$current            = $m[1];
			$fields[ $current ] = array( $m[2] );
		} elseif ( null !== $current && '"' === $line[0] ) {
			$fields[ $current ][] = $line;
		}
	}
	if ( ! isset( $fields['msgid'] ) ) {
		continue;
	}
	$id = dscc_po_decode( $fields['msgid'] );
	if ( '' === $id ) {
		$header = $comments;
		continue;
	}
	$entries[] = array(
		'comments' => $comments,
		'ctx'      => isset( $fields['msgctxt'] ) ? dscc_po_decode( $fields['msgctxt'] ) : null,
		'id'       => $id,
		'plural'   => isset( $fields['msgid_plural'] ) ? dscc_po_decode( $fields['msgid_plural'] ) : null,
	);
}

$project = 'DS Cookie Consent';
$lines   = array();
foreach ( $header as $c ) {
	if ( 0 !== strpos( $c, '#. ' ) && 0 !== strpos( $c, '#, ' ) ) {
		$lines[] = $c;
	}
}
$out     = implode( "\n", $lines ) . ( $lines ? "\n" : '' );
$out    .= "msgid \"\"\nmsgstr \"\"\n";
foreach ( array(
	'Project-Id-Version: ' . $project . "\n",
	"PO-Revision-Date: 2026-11-07 00:00+0000\n",
	"Last-Translator: D-Solutions\n",
	"Language-Team: D-Solutions\n",
	"Language: vi\n",
	"MIME-Version: 1.0\n",
	"Content-Type: text/plain; charset=UTF-8\n",
	"Content-Transfer-Encoding: 8bit\n",
	"Plural-Forms: nplurals=1; plural=0;\n",
	"X-Domain: ds-cookie-consent\n",
) as $h ) {
	$out .= dscc_po_encode( $h ) . "\n";
}

$missing = array();
$used    = array();
foreach ( $entries as $e ) {
	$row = $by_id[ $e['id'] ] ?? null;
	if ( null === $row ) {
		$missing[] = $e['id'];
	} else {
		$used[ $e['id'] ] = true;
	}
	$out .= "\n" . implode( "\n", $e['comments'] ) . "\n";
	if ( null !== $e['ctx'] ) {
		$out .= 'msgctxt ' . dscc_po_encode( $e['ctx'] ) . "\n";
	}
	$out .= 'msgid ' . dscc_po_encode( $e['id'] ) . "\n";
	$msgstr = null === $row ? '' : $row['msgstr'];
	if ( null !== $e['plural'] ) {
		$out .= 'msgid_plural ' . dscc_po_encode( $e['plural'] ) . "\n";
		$out .= 'msgstr[0] ' . dscc_po_encode( $msgstr ) . "\n";
	} else {
		$out .= 'msgstr ' . dscc_po_encode( $msgstr ) . "\n";
	}
}
$out = preg_replace( "/\n\n\n+/", "\n\n", $out );

$stale = array_diff( array_keys( $by_id ), array_keys( $used ) );
if ( false === file_put_contents( $output, $out ) ) {
	fwrite( STDERR, "Cannot write {$output}\n" );
	exit( 2 );
}
fwrite( STDOUT, sprintf( "make-vi-po: %d entries, %d untranslated, %d stale table rows\n", count( $entries ), count( $missing ), count( $stale ) ) );
foreach ( $missing as $id ) {
	fwrite( STDERR, "untranslated: {$id}\n" );
}
foreach ( $stale as $id ) {
	fwrite( STDERR, "stale: {$id}\n" );
}
exit( $strict && ( $missing || $stale ) ? 1 : 0 );
