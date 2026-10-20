<?php
/**
 * Regression guard: a header positioned `absolute; top:0` is an OVERLAY, not a static bar.
 *
 * WHY THIS FILE EXISTS. A converted page put a solid dark bar across the top where the source has a
 * transparent gradient nav floating over the hero photograph. Measured, source vs. build:
 *
 *     background-color   rgba(0, 0, 0, 0)   ->   rgb(20, 20, 20)
 *     position           absolute           ->   relative
 *
 * HOW IT GOT THERE. `detect_header()` recognised exactly two pinning keywords, `fixed` and `sticky`.
 * The source header carries neither: it is `absolute top-0 left-0 right-0` with a
 * `linear-gradient(rgba(0,0,0,.8), rgba(0,0,0,.4), rgba(0,0,0,0))` background-image and no
 * background-COLOR at all. So nothing matched, `sticky` stayed false, `header_position` resolved to
 * `static`, and from there two more things followed on their own:
 *
 *   1. a static header is IN FLOW, so it reserved its own 96px and pushed the hero down by it;
 *   2. `$header_transparent` is keyed to the overlay/sticky set, so a static header is assumed to be a
 *      solid bar and takes the fallback fill -- `#141414` here, because the pill/dark probe had read the
 *      nav as dark. An opaque colour under a fade-to-transparent gradient erases the fade.
 *
 * One unrecognised keyword, three visible defects: the header's position, its fill, and the vertical
 * offset of every band below it.
 *
 * WHY `absolute` BELONGS WITH `fixed`. The decision downstream turns on ONE property -- whether the
 * header is in flow -- and `absolute` is out of flow exactly as `fixed` is. They differ on SCROLL
 * (absolute scrolls away, fixed stays) and the theme's overlay pins once stuck, so this is not a perfect
 * match. It is a mismatch of one scroll behaviour in place of a mismatch of position, fill and page
 * offset. The theme's overlay base is literally `position:absolute; top:0` becoming `fixed` when stuck,
 * so the RESTING state -- what a visitor sees first, and what every band measurement is taken against --
 * matches the source exactly.
 *
 * TOP-PINNED IS REQUIRED, and the second negative below is why: `absolute` is the commonest positioning
 * on a page, and a header absolutely placed at an offset inside a relative hero is not chrome.
 *
 * The fixture is the real header plus the first section, reduced, with the source's own naming replaced.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/absolute-header-is-an-overlay-test.php"
 *
 * Exit code 0 = all PASS, 1 = at least one FAIL.
 */

if ( ! class_exists( 'FW_Site_Converter_Stitch' ) ) {
	fwrite( STDERR, "FAIL: site-converter not loaded\n" );
	exit( 1 );
}

$fails = 0;
$ok    = function ( $cond, $msg ) use ( &$fails ) {
	if ( $cond ) { echo "  \xe2\x9c\x93 $msg\n"; } else { $fails++; echo "  \xe2\x9c\x97 FAIL: $msg\n"; }
};

$ref = new ReflectionClass( 'FW_Site_Converter_Stitch' );
$pt  = $ref->getMethod( 'parse_tokens' );  $pt->setAccessible( true );
$dh  = $ref->getMethod( 'detect_header' ); $dh->setAccessible( true );

/** Everything this guard reads, in one call: the detector's keyword + the emitted chrome values. */
$chrome = function ( $html ) use ( $pt, $dh ) {
	$c  = FW_Site_Converter_Stitch::tokens_to_theme_settings_chrome( (array) $pt->invoke( null, $html ), $html, 'T' );
	$hl = (array) ( $c['values']['header_layout'] ?? array() );
	return array(
		'pinned' => (string) ( $dh->invoke( null, $html )['pinned'] ?? '' ),
		'pos'    => (string) ( $hl['header_position'] ?? '' ),
		'fill'   => (string) ( $hl['bg_color']['custom'] ?? '' ),
		'css'    => (string) ( $c['css'] ?? '' ) . ' ' . (string) wp_json_encode( $c['values'] ?? array() ),
	);
};

echo "\n== The real absolute-pinned header converts as an overlay\n";

$fixture = __DIR__ . '/fixtures/absolute-header-is-an-overlay.html';
if ( ! is_file( $fixture ) ) { fwrite( STDERR, "FAIL: fixture missing\n" ); exit( 1 ); }
$h = file_get_contents( $fixture );
$g = $chrome( $h );

$ok( 'absolute' === $g['pinned'], sprintf( 'the detector keeps the keyword `absolute` (got %s)', wp_json_encode( $g['pinned'] ) ) );
$ok( 'overlay' === $g['pos'], sprintf( 'header_position is overlay, not static (got %s)', wp_json_encode( $g['pos'] ) ) );

echo "\n== …and takes NO opaque fallback fill\n";

// This is the visible half of the defect. `overlay` alone makes the theme add `site-header--transparent`,
// so an EMPTY bg_color is what lets the source's gradient show. Any custom colour here paints over it.
$ok( '' === $g['fill'], sprintf( 'bg_color carries no custom fill (got %s)', wp_json_encode( $g['fill'] ) ) );

echo "\n== …and the source's own gradient is what paints the bar\n";

$ok( (bool) preg_match( '/linear-gradient\(\s*rgba\(\s*0\s*,\s*0\s*,\s*0\s*,\s*0?\.8/i', $g['css'] ),
	'the captured black-to-transparent gradient reaches the emitted chrome' );

echo "\n== NEGATIVE: a plain static solid header is untouched\n";

// The ordinary case must not move. A header with no positioning keyword stays static and keeps its fill;
// if this went overlay too, every conventional site would lose its header background.
$static = '<!DOCTYPE html><html><body data-sc-cs="font-family:Inter, sans-serif">'
	. '<header data-sc-cs="position:static;height:80px;background-color:rgb(255, 255, 255)">'
	. '<a href="/">Mark</a><nav><a href="/a">One</a><a href="/b">Two</a></nav></header>'
	. '<main><section data-sc-cs="padding:80px 0px"><h1 data-sc-cs="font-size:56px">Page</h1></section></main></body></html>';
$sg = $chrome( $static );
$ok( '' === $sg['pinned'], sprintf( 'it gains no pinning keyword (got %s)', wp_json_encode( $sg['pinned'] ) ) );
$ok( 'static' === $sg['pos'], sprintf( 'and stays static (got %s)', wp_json_encode( $sg['pos'] ) ) );

echo "\n== NEGATIVE: `absolute` away from the top edge is not chrome\n";

// `absolute` is the commonest positioning there is. Without the top-pinned requirement, a header placed
// at an offset inside a relative hero would be read as an overlay and lose its fill.
$offset = str_replace(
	'position:static;height:80px',
	'position:absolute;top:240px;height:80px',
	$static
);
$og = $chrome( $offset );
$ok( 'absolute' !== $og['pinned'], sprintf( 'an absolute header at top:240px is not pinned (got %s)', wp_json_encode( $og['pinned'] ) ) );

printf( "\n%s\n", $fails ? "{$fails} FAIL(S)" : 'ALL PASS' );
exit( $fails ? 1 : 0 );
