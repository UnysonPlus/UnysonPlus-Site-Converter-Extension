<?php
/**
 * Guard for RE-RUNNING ONE PAGE.
 *
 * A conversion used to be all-or-nothing: one page wrong meant hand-fixing it (lost on the next
 * conversion) or reconverting the whole site (which re-derives the design system and discards every other
 * page's state). It could not be offered before because a converted page carried no record of the URL it
 * was built from — the bundle knew and threw it away.
 *
 * Two bugs from building it are pinned here, because both would fail silently:
 *   - the first version called `import_json()` (which takes a JSON STRING) with an ARRAY. PHP stringified
 *     it to "Array", nothing was written, and the re-run reported success.
 *   - the success check accepted an empty result as success, which is how a re-run that did nothing at all
 *     got reported as "Rebuilt". Success now requires a page ID as proof.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs --allow-root eval-file \
 *     "D:/Web Dev/unysonplus/framework/extensions/site-converter/tests/rerun-test.php"
 */

if ( ! class_exists( 'FW_Site_Converter_Rerun' ) || ! class_exists( 'FW_Site_Converter_Pages' ) ) {
	fwrite( STDERR, "Site Converter not loaded — activate the extension first.\n" );
	exit( 1 );
}

$pass = 0; $fail = 0;
$ok = function ( $c, $what, $d = '' ) use ( &$pass, &$fail ) {
	if ( $c ) { $pass++; echo "  \xE2\x9C\x93 {$what}\n"; }
	else { $fail++; echo "  \xE2\x9C\x97 FAIL: {$what}" . ( '' !== $d ? " -- {$d}" : '' ) . "\n"; }
};

$count_pages = function () {
	return count( get_posts( array( 'post_type' => 'page', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ) ) );
};

// A minimal captured page: the computed-style stamps are what make it a RENDER rather than a raw fetch.
$html = '<!doctype html><html><head><title>Re-run</title></head><body><main>'
	. '<section data-sc-cs="display:block"><h1>A heading on the re-run fixture</h1>'
	. '<p>Body copy long enough to be carried across as a real text block.</p></section>'
	. '</main></body></html>';

/* ------------------------------------------------- set up a page to re-run -- */
echo "\n=== A converted page remembers where it came from ===\n";

$before_count = $count_pages();
$imported = FW_Site_Converter_Pages::import( array( 'pages' => array( array(
	'title'      => 'Rerun Fixture',
	'slug'       => 'rerun-fixture',
	'status'     => 'draft',
	'source_url' => 'https://example.test/rerun-fixture',
	'builder'    => array(),
) ) ) );
$row = ( is_array( $imported ) && ! empty( $imported['pages'] ) ) ? reset( $imported['pages'] ) : array();
$pid = (int) ( $row['id'] ?? 0 );
$ok( $pid > 0, 'the fixture page was created', 'row=' . wp_json_encode( $row ) );

if ( $pid ) {
	$ok( 'https://example.test/rerun-fixture' === get_post_meta( $pid, '_upw_source_url', true ),
		'its source URL is stored as post meta (this is what makes a re-run possible at all)' );

	$listed = false;
	foreach ( FW_Site_Converter_Rerun::pages() as $p ) { if ( (int) $p['id'] === $pid ) { $listed = true; break; } }
	$ok( $listed, 'it appears in the re-runnable page list' );

	/* --------------------------------------------- the re-run itself -------- */
	echo "\n=== Re-running writes, and writes to THAT page ===\n";

	$n_before = $count_pages();
	$res = FW_Site_Converter_Rerun::rebuild( $pid, $html );
	$ok( ! empty( $res['ok'] ), 'the re-run reported success', wp_json_encode( $res ) );
	// Proof, not absence of error: the first version reported success while writing nothing.
	$ok( (int) ( $res['id'] ?? 0 ) === $pid, 'it rebuilt THAT page rather than creating another',
		'id=' . ( $res['id'] ?? 'none' ) . ' expected=' . $pid );
	$ok( $count_pages() === $n_before, 'no extra page was created', $n_before . ' -> ' . $count_pages() );
	$json = (string) get_post_meta( $pid, 'fw:opt:ext:pb:page-builder:json', true );
	$ok( strlen( $json ) > 20, 'a builder tree was actually written', strlen( $json ) . ' chars' );
	$ok( 'https://example.test/rerun-fixture' === get_post_meta( $pid, '_upw_source_url', true ),
		'the source URL survives the re-run (so the page stays re-runnable)' );

	/* --------------------------------------------- determinism -------------- */
	echo "\n=== The same source through the same converter gives the same page ===\n";
	$strip = function ( $s ) {
		// unique_id and the u<hash>/fx- class names derived from it are regenerated per build by design.
		$s = preg_replace( '/"unique_id":"[^"]*"/', '"unique_id":"X"', (string) $s );
		return preg_replace( '/\b(?:u|fx-|hd-)[0-9a-f]{8}\b/', 'HASH', $s );
	};
	$first = $strip( $json );
	FW_Site_Converter_Rerun::rebuild( $pid, $html );
	$second = $strip( (string) get_post_meta( $pid, 'fw:opt:ext:pb:page-builder:json', true ) );
	$ok( $first === $second, 'a second re-run produces the same tree (ids aside) — it is deterministic, not an AI pass' );

	/* --------------------------------------------- the guards --------------- */
	echo "\n=== NEGATIVES: a re-run refuses what it cannot honestly do ===\n";

	$r1 = FW_Site_Converter_Rerun::rebuild( $pid, '' );
	$ok( empty( $r1['ok'] ), 'NEGATIVE: an empty capture is refused' );

	// A raw fetch has no computed-style stamps. Building from it yields a page that looks nothing like the
	// source, which reads as "the re-run broke my page" — better to refuse and name the missing component.
	$r2 = FW_Site_Converter_Rerun::rebuild( $pid, '<html><body><p>plain</p></body></html>' );
	$ok( empty( $r2['ok'] ), 'NEGATIVE: an unrendered (un-stamped) capture is refused' );
	$ok( false !== stripos( (string) ( $r2['error'] ?? '' ), 'capture service' ),
		'…and the error names the component that is missing', (string) ( $r2['error'] ?? '' ) );

	$r3 = FW_Site_Converter_Rerun::rebuild( 99999999, $html );
	$ok( empty( $r3['ok'] ), 'NEGATIVE: an unknown page id is refused' );

	wp_delete_post( $pid, true );
	$ok( $count_pages() === $before_count, 'the fixture cleaned up after itself' );
}

/* ------------------------------------------- scoping a refine to ONE page -- */
echo "\n=== An AI refine's CSS is confined to the page it was measured on ===\n";

// The refine loop measures ONE page and keeps CSS only when THAT page's drift dropped. Writing it
// unscoped would apply it site-wide, where it was never measured and can only make things worse — a pass
// that improves one page by 4% while quietly degrading eight others is not an improvement, and nothing
// downstream would notice.
$sc = function ( $css ) { return trim( preg_replace( '/\s+/', ' ', FW_Site_Converter_Rerun::scope_css( $css, 'body.page-id-7' ) ) ); };

$ok( 'body.page-id-7 .hero{color:red}' === $sc( '.hero{color:red}' ), 'a plain selector is prefixed' );
$ok( 'body.page-id-7 .a, body.page-id-7 .b{x:1}' === $sc( '.a,.b{x:1}' ),
	'EVERY selector in a list is prefixed (doing only the first leaks the rest site-wide)' );
$ok( 'body.page-id-7{background:#fff}' === $sc( 'body{background:#fff}' ),
	'body is REWRITTEN, not descended from — `body.page-id-7 body` matches nothing' );
$ok( 'body.page-id-7 .x{y:1}' === $sc( 'body .x{y:1}' ),
	'a descendant of body keeps its space; the combinator is not swallowed' );
$ok( 'body.page-id-7.dark .x{y:1}' === $sc( 'html.dark .x{y:1}' ),
	'a compound on html folds into the scope without gaining a space' );
$ok( false !== strpos( $sc( '@media (max-width:600px){.a{x:1}}' ), '@media (max-width:600px){ body.page-id-7 .a' ),
	'an at-rule stays at the top level and its CONTENTS are scoped' );
$ok( '@keyframes spin{from{opacity:0}to{opacity:1}}' === $sc( '@keyframes spin{from{opacity:0}to{opacity:1}}' ),
	'NEGATIVE: @keyframes passes through untouched (scoping `from`/`0%` would destroy the animation)' );
$ok( '@font-face{font-family:X}' === $sc( '@font-face{font-family:X}' ),
	'NEGATIVE: @font-face passes through untouched' );
$ok( '' === $sc( '' ), 'NEGATIVE: empty input yields nothing' );
$ok( '' === $sc( '.a{x:1' ), 'NEGATIVE: unbalanced CSS is dropped rather than half-emitted' );
$ok( '' === FW_Site_Converter_Rerun::scope_css( '.a{x:1}', '' ),
	'NEGATIVE: with no scope it yields nothing — never unscoped CSS' );

echo "\n========================================\n";
echo 'RERUN RESULT: ' . ( $fail ? 'FAIL' : 'PASS' ) . "   ({$pass} passed, {$fail} failed)\n";
echo "========================================\n";
exit( $fail ? 1 : 0 );
