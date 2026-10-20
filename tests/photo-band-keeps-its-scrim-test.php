<?php
/**
 * Regression guard: a photo band keeps the SCRIM that makes its text readable.
 *
 * WHY THIS FILE EXISTS. A converted band showed its background photo at full brightness where the
 * source darkens it heavily, leaving white body copy and a stats row sitting on bright water
 * reflections. The ensemble lens put a number on it: 57.1% pixel and 24.65% perceptual difference,
 * the largest on that page. The same defect appeared on the hero, so it is systemic to full-bleed
 * photo bands rather than one band's bad luck.
 *
 * The source stacks two covers over the photo:
 *
 *     <div position:absolute inset:0  background-image:linear-gradient(to top,
 *            rgb(8,9,12), rgba(0,0,0,0.45), rgba(8,9,12,0.8))>
 *     <div position:absolute inset:0  background-color:rgba(0,0,0,0.35) + backdrop blur>
 *
 * THE STITCH CAPTURES BOTH. They arrive in the mapping under `sectionLayers`. The mapper has a
 * consumer for that key, and it drops them for two compounding reasons:
 *
 *   1. the gradient hoist is guarded by `! isset( $secd['background-image'] )`, so a section that
 *      already has a PHOTO never reaches it — and a scrim over a photo is the whole point of a scrim;
 *   2. `layer_band_gradient()` refuses a translucent gradient on purpose, with the comment
 *      "A SCRIM (photo overlay) carries transparency in its stops — not a solid band fill". That is
 *      correct about its own job — it looks for a BAND FILL — and it means no code path owned the
 *      scrim at all.
 *
 * So neither piece was wrong; the case simply had no owner. A scrim belongs LAYERED OVER the photo,
 * which CSS expresses natively: `background-image: <gradient>, url(photo)`.
 *
 * The fixture is the real band, reduced under a predicate requiring the defect to survive: a section
 * with a photo, at least one translucent full-bleed layer captured, and NO gradient carried.
 *
 * (The predicate had to be tightened once: matching /gradient/ against the section's encoded
 * background matched the empty `"gradient"` KEY that is always present, so the first run reported
 * the defect absent. It tests for real stops, or a literal gradient function in the CSS.)
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/photo-band-keeps-its-scrim-test.php"
 *
 * Exit code 0 = all PASS, 1 = at least one FAIL.
 */

if ( ! class_exists( 'FW_Site_Converter_Stitch' ) || ! class_exists( 'FW_Site_Converter_Mapper' ) ) {
	fwrite( STDERR, "FAIL: site-converter not loaded\n" );
	exit( 1 );
}

$fails = 0;
$ok    = function ( $cond, $msg ) use ( &$fails ) {
	if ( $cond ) { echo "  \xe2\x9c\x93 $msg\n"; } else { $fails++; echo "  \xe2\x9c\x97 FAIL: $msg\n"; }
};

$section_with = function ( $pages, $needle ) {
	$hit  = null;
	$walk = function ( $n ) use ( &$walk, &$hit, $needle ) {
		if ( ! is_array( $n ) || null !== $hit ) { return; }
		foreach ( $n as $x ) {
			if ( ! is_array( $x ) ) { continue; }
			if ( 'section' === ( $x['type'] ?? '' ) && false !== strpos( (string) wp_json_encode( $x ), $needle ) ) { $hit = $x; return; }
			$walk( $x['_items'] ?? $x );
		}
	};
	$walk( $pages );
	return $hit;
};
/** Every place a section can legitimately carry a scrim. */
$scrim_css = function ( array $sec ) {
	$a = (array) ( $sec['atts'] ?? array() );
	return (string) wp_json_encode( $a['background'] ?? array() ) . ' ' . (string) ( $a['custom_css'] ?? '' );
};

$fixture = __DIR__ . '/fixtures/photo-band-keeps-its-scrim.html';
if ( ! is_file( $fixture ) ) { fwrite( STDERR, "FAIL: fixture missing\n" ); exit( 1 ); }

// THE HARNESS HAS TO MIRROR PRODUCTION. The section-layer pass lives inside `if ( self::$style_on )`,
// and that flag is only raised by `set_style_config()`, which the real conversion calls and a bare
// build_pages() does not. Without this line the whole region — including the band-fill hoist this fix
// sits beside — never executes, so the golden stayed RED against a fix that already worked. A test
// harness that skips a setup call the product makes is measuring a different program.
FW_Site_Converter_Mapper::set_style_config( array() );

$map   = FW_Site_Converter_Stitch::html_to_mapping( file_get_contents( $fixture ), 'S', 's', true );
$pages = FW_Site_Converter_Mapper::build_pages( $map );
$sec   = $section_with( $pages, 'UNTOUCHED' );

echo "\n== The band converts, and still carries its photo\n";
$ok( null !== $sec, 'the band produces a section' );
if ( null === $sec ) { printf( "\n%d FAIL(S)\n", ++$fails ); exit( 1 ); }
$atts = (array) $sec['atts'];
$ok( ! empty( $atts['background']['image']['src']['url'] ) || false !== stripos( (string) ( $atts['custom_css'] ?? '' ), 'url(' ),
	'the background photo survives' );

echo "\n== The stitch captured the covers (the input this fix depends on)\n";

$layers = 0;
foreach ( (array) ( $map['pages'][0]['sections'] ?? array() ) as $s ) {
	if ( false === strpos( (string) wp_json_encode( $s ), 'UNTOUCHED' ) ) { continue; }
	foreach ( (array) ( $s['sectionLayers'] ?? array() ) as $l ) {
		if ( preg_match( '/gradient|rgba\([^)]*0?\.\d+\s*\)/i', (string) ( $l['cs'] ?? '' ) ) ) { $layers++; }
	}
}
$ok( $layers >= 1, sprintf( 'at least one translucent full-bleed layer is in sectionLayers (got %d)', $layers ) );

echo "\n== …and the scrim reaches the page\n";

$css = $scrim_css( $sec );
$ok( (bool) preg_match( '/(?:linear|radial|conic)-gradient\s*\(/i', $css )
	|| count( (array) ( $atts['background']['gradient']['data']['stops'] ?? array() ) ) > 0,
	'the section carries a gradient scrim over its photo' );

echo "\n== NEGATIVE: an OPAQUE full-bleed layer is not turned into a scrim\n";

// A scrim is defined by TRANSPARENCY. An opaque `absolute inset-0` cover is a band fill and belongs
// to the existing hoist; this fix must not reach it, or a solid band would gain a gradient it never
// had. Asserted on a band whose cover is fully opaque.
//
// (An earlier version of this negative asserted that the opaque layer IS hoisted as the band fill.
// It failed BEFORE any change — the hoist keys off a Tailwind `bg-*` class that synthetic markup has
// no reason to carry — so it was testing nothing. A negative that is red before the fix is not a
// guard; it is noise.)
$solid = '<!DOCTYPE html><html><body data-sc-cs="font-family:Inter, sans-serif"><main>'
	. '<section class="relative" data-sc-cs="padding:80px 0px;display:block;position:relative">'
	. '<div class="absolute inset-0 bg-emerald-900" data-sc-cs="position:absolute;top:0px;right:0px;bottom:0px;left:0px;height:400px;background-color:rgb(12, 74, 56)"></div>'
	. '<div data-sc-cs="display:block;position:relative;z-index:10"><h2 data-sc-cs="font-size:40px;font-weight:800">Solid Band</h2>'
	. '<p data-sc-cs="font-size:16px">Copy on a solid colour band.</p></div>'
	. '</section></main></body></html>';
$ssec = $section_with( FW_Site_Converter_Mapper::build_pages( FW_Site_Converter_Stitch::html_to_mapping( $solid, 'B', 'b', true ) ), 'Solid Band' );
$ok( null !== $ssec, 'a solid-fill band still converts' );
if ( null !== $ssec ) {
	$sblob = $scrim_css( $ssec );
	$ok( ! preg_match( '/(?:linear|radial|conic)-gradient\s*\(/i', $sblob )
		&& 0 === count( (array) ( $ssec['atts']['background']['gradient']['data']['stops'] ?? array() ) ),
		'and it gains no gradient — an opaque cover is a fill, not a scrim' );
}

printf( "\n%s\n", $fails ? "{$fails} FAIL(S)" : 'ALL PASS' );
exit( $fails ? 1 : 0 );
