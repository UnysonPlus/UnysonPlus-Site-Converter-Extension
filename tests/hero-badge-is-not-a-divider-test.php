<?php
/**
 * Regression guard: a band's own artwork is not an edge SHAPE DIVIDER, and a divider with no colour
 * is not emitted at all.
 *
 * WHY THIS FILE EXISTS. A converted hero had a WHITE DIAGONAL BAND painted straight across its dark
 * photograph — the most visible thing on the page, and invented out of nothing. The source has no
 * divider anywhere. What the stitch produced was:
 *
 *     divider: { placement: "top", shape: "tilt", height: "", color: "", flip: "no" }
 *
 * and an empty colour makes the shortcode paint its default, which is white.
 *
 * HOW IT GOT THERE. `detect_section_divider()` walks every `<svg>` in a section and climbs up to three
 * levels looking for an absolutely-positioned wrapper pinned to `top-0` or `bottom-0`. A hero's
 * decorative badge sits inside the band's `absolute inset-0` scrim — which IS absolutely positioned
 * and DOES have `top:0`. So the climb found it, called the badge a top divider, read no curves in its
 * path so chose "tilt", and found no fill so left the colour empty.
 *
 * TWO THINGS WERE WRONG, and both are fixed:
 *
 *   1. AN EDGE DIVIDER IS PINNED TO ONE EDGE. A wrapper pinned to all four (`inset-0`) is a full-bleed
 *      COVER — a scrim, a backdrop, a click-shield — and the SVGs inside it are the band's artwork.
 *   2. NO COLOUR, NO DIVIDER. An edge shape exists to carry the NEXT band's fill across the seam, so
 *      one whose colour cannot be read cannot be reproduced. Skipping leaves a clean edge — wrong by
 *      one shape. Inventing a white band is wrong by the width of the page.
 *
 * A SYNTHETIC FIXTURE DID NOT REPRODUCE THIS. A hand-written hero with a badge inside an `inset-0`
 * cover returned null even BEFORE the fix, so building on it would have proved nothing. The fixture
 * here is the real hero, reduced under a predicate requiring the invented divider to survive the trim.
 * The synthetic is kept below as the POSITIVE case only, where it does hold.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/hero-badge-is-not-a-divider-test.php"
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
$det = $ref->getMethod( 'detect_section_divider' );
$det->setAccessible( true );

/** First <section> of an HTML string. */
$first_section = function ( $html ) {
	$d = new DOMDocument();
	libxml_use_internal_errors( true );
	$d->loadHTML( '<?xml encoding="utf-8" ?>' . $html );
	libxml_clear_errors();
	return $d->getElementsByTagName( 'section' )->item( 0 );
};

echo "\n== A hero's own artwork is not an edge divider\n";

$fixture = __DIR__ . '/fixtures/hero-badge-is-not-a-divider.html';
if ( ! is_file( $fixture ) ) { fwrite( STDERR, "FAIL: fixture missing\n" ); exit( 1 ); }
$hero = $first_section( file_get_contents( $fixture ) );
$ok( null !== $hero, 'the hero fixture parses' );
$hd = $hero ? $det->invoke( null, $hero ) : null;
$ok( empty( $hd ), sprintf( 'the hero yields NO divider (got %s)', wp_json_encode( $hd ) ) );

echo "\n== …and the whole page gains no white band\n";

FW_Site_Converter_Mapper::set_style_config( array() );
$map  = FW_Site_Converter_Stitch::html_to_mapping( file_get_contents( $fixture ), 'D', 'd', true );
$any  = 0;
$white = 0;
foreach ( (array) ( $map['pages'][0]['sections'] ?? array() ) as $s ) {
	$d = $s['divider'] ?? null;
	if ( empty( $d ) ) { continue; }
	$any++;
	if ( '' === (string) ( $d['color'] ?? '' ) ) { $white++; }
}
$ok( 0 === $any, sprintf( 'no section carries an invented divider (got %d)', $any ) );
$ok( 0 === $white, 'and none carries a colourless one, which would paint white' );

echo "\n== POSITIVE: a genuine edge shape is still detected, with its colour\n";

// Pinned to ONE edge, full width, short, with an explicit fill — a real divider.
$real = '<section data-sc-cs="position:relative">'
	. '<div class="absolute bottom-0 left-0 w-full" data-sc-cs="position:absolute;bottom:0px;left:0px;width:1440px;height:80px">'
	. '<svg width="1440" height="80" viewBox="0 0 1440 80"><path fill="#0c4a38" d="M0 80 L1440 0 L1440 80 Z"/></svg></div>'
	. '<div><h2 data-sc-cs="font-size:40px">Band</h2></div></section>';
$rd = $det->invoke( null, $first_section( $real ) );
$ok( is_array( $rd ), 'a real bottom-edge shape is still found' );
if ( is_array( $rd ) ) {
	$ok( 'bottom' === ( $rd['placement'] ?? '' ), 'with its placement' );
	$ok( '#0c4a38' === strtolower( (string) ( $rd['color'] ?? '' ) ), sprintf( 'and its colour (got %s)', wp_json_encode( $rd['color'] ?? null ) ) );
	$ok( '80' === (string) ( $rd['height'] ?? '' ), 'and its height' );
}

echo "\n== NEGATIVE: a badge inside a full-bleed cover stays artwork\n";

// The synthetic shape. It did not reproduce the defect, but it does pin the rule.
$cover = '<section data-sc-cs="position:relative;min-height:900px">'
	. '<div class="absolute inset-0" data-sc-cs="position:absolute;top:0px;right:0px;bottom:0px;left:0px;height:898px;background-image:linear-gradient(to top, rgb(8,9,12), rgba(0,0,0,0.45))"></div>'
	. '<div class="relative z-10"><h1 data-sc-cs="font-size:80px">Hero</h1>'
	. '<div class="absolute bottom-8 right-8"><svg width="96" height="96" viewBox="0 0 96 96"><path d="M48 8 L48 88"/></svg></div></div></section>';
$ok( empty( $det->invoke( null, $first_section( $cover ) ) ), 'a badge inside an inset-0 cover is not a divider' );

printf( "\n%s\n", $fails ? "{$fails} FAIL(S)" : 'ALL PASS' );
exit( $fails ? 1 : 0 );
