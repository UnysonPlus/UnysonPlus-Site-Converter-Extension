<?php
/**
 * Regression guard: an inlined SVG illustration keeps the width CAP the source gave it.
 *
 * WordPress cannot host SVG in the media library, so `inline_svg_imgs()` replaces an `<img src=*.svg>` in a
 * raw-HTML column with the SVG markup itself, wrapped in
 * `<span class="sc-illustration" style="display:inline-block;max-width:100%">`.
 *
 * That wrapper throws away everything the `<img>` said about its own size. A source illustration written
 * `class="w-full max-w-md"` is capped at 448px; inlined, it stretched to fill its column.
 *
 * Measured on a captured band: the `<img>` stamps `max-width:448px; height:373.406px` in a 636px column, and
 * the conversion rendered the SVG at 636 x 530 — 42% oversized, which is what made the band read as a
 * different design from its source.
 *
 * So the cap rides onto the <svg> as its SINGLE max-width, with `width:100%` so it still shrinks on a narrow
 * screen. It goes there and not on the wrapper because WordPress's inline-style filter keeps one max-width per
 * element: a second declaration beside the wrapper's `max-width:100%` was dropped outright, and the page still
 * showed the illustration oversized even though the emitted markup looked right. An `<img>` with no cap (or
 * `none` / `100%`, which is the default and states nothing) is untouched.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/inlined-svg-keeps-its-cap-test.php"
 *
 * Exit code 0 = all PASS, 1 = at least one FAIL.
 */

if ( ! class_exists( 'FW_Site_Converter_Mapper' ) ) {
	fwrite( STDERR, "FAIL: site-converter mapper not loaded\n" );
	exit( 1 );
}

$fails = 0;
$ok    = function ( $cond, $msg ) use ( &$fails ) {
	if ( $cond ) { echo "  \xe2\x9c\x93 $msg\n"; } else { $fails++; echo "  \xe2\x9c\x97 FAIL: $msg\n"; }
};

$fn = new ReflectionMethod( 'FW_Site_Converter_Mapper', 'inline_svg_imgs' );
$fn->setAccessible( true );
$cache = new ReflectionProperty( 'FW_Site_Converter_Mapper', 'svg_cache' );
$cache->setAccessible( true );
$abs_fn = new ReflectionMethod( 'FW_Site_Converter_Mapper', 'abs_asset' );
$abs_fn->setAccessible( true );

// Prime the fetch cache so the test never touches the network. fetch_svg_cached() keys on the ABSOLUTISED
// src, so the key has to go through abs_asset() exactly as the inliner does.
$SRC = 'https://assets.example-domain.test/test-illustration-AAAA1111.svg'; // absolute: fetch_svg_cached keys on it as-is
$SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 800 800"><circle cx="400" cy="400" r="300"/></svg>';
$key = (string) $abs_fn->invoke( null, $SRC );
$cur = (array) $cache->getValue();
$cur[ $key ] = $SVG;
$cur[ $SRC ] = $SVG;
$cache->setValue( null, $cur );

$img = function ( $cs ) use ( $SRC ) {
	return '<div class="sc-tw"><div class="flex justify-center">'
		. '<img src="' . $SRC . '" alt="An illustration" class="w-full max-w-md" loading="lazy" width="800" height="800" data-sc-cs="' . $cs . '">'
		. '</div></div>';
};

echo "\n== The measured shape: a 448px-capped illustration in a 636px column\n";

$out = (string) $fn->invoke( null, $img( 'max-width:448px;height:373.406px;display:block' ) );

if ( false === strpos( $out, 'sc-illustration' ) ) {
	$ok( false, 'the SVG could not be inlined here (fetch unavailable) — cannot assert the cap' );
} else {
	$ok( true, 'the SVG is inlined' );
	$ok( false !== strpos( $out, 'max-width:448px' ), 'the measured cap rides onto the SVG: ' . mb_substr( $out, 0, 150 ) );
	$ok( false !== strpos( $out, 'width:100%' ), '…with width:100%, so it still shrinks on a narrow screen' );
	$ok( 1 === preg_match_all( '/max-width:\s*448px/', $out ), 'the cap is declared exactly once' );
}

echo "\n== NEGATIVE: an uncapped illustration is untouched\n";

$full = (string) $fn->invoke( null, $img( 'height:373px;display:block' ) );
if ( false !== strpos( $full, 'sc-illustration' ) ) {
	$ok( false === strpos( $full, 'max-width:448px' ), 'no cap is invented for it' );
}

echo "\n== NEGATIVE: max-width:100% / none are defaults, not a chosen size\n";

foreach ( array( 'max-width:100%;height:373px', 'max-width:none;height:373px' ) as $cs ) {
	$o = (string) $fn->invoke( null, $img( $cs ) );
	if ( false === strpos( $o, 'sc-illustration' ) ) { continue; }
	$ok( false === strpos( $o, 'max-width:none' ), '`' . $cs . '` → `none` is not emitted' );
	$n_caps = preg_match_all( '/max-width:\s*[0-9.]+px/i', $o );
	$ok( $n_caps <= 1, '`' . $cs . '` → no PIXEL cap is added (' . $n_caps . ')' );
}

printf( "\n%s\n", $fails ? "{$fails} FAIL(S)" : 'ALL PASS' );
exit( $fails ? 1 : 0 );
