<?php
/**
 * Regression guard: a band's measured HEIGHT belongs to the BAND, not to whichever row comes first.
 *
 * WHY THIS FILE EXISTS. A converted band came out 1742px against the source's 1000px — +742px, which was
 * 86% of that whole page's height error. Measured in the emitted builder JSON:
 *
 *     SECTION  min_height = null, no min-height in its scoped CSS
 *       item0  (a small EYEBROW row)   custom_css: min-height:85vh     <- 850px at a 1000px viewport
 *       item1  (heading + copy)        (none)
 *       item2  (the stats row)         (none)
 *
 * The hero-height carry walks the band's items, writes `selector{min-height:<measured>}` onto the FIRST
 * flexbox it finds, and breaks — "the hero's own row, not every row in the band". That is correct when a
 * band HAS one row: the row is the band, and the height belongs on it (putting it on the section instead
 * renders the section `display:flex`, which shrinks a full-width flexbox child — the reason it lives on the
 * row at all).
 *
 * It is wrong the moment a band has SIBLING rows. Here the first row is an eyebrow of a few words: it was
 * inflated from roughly 100px to 850px, and the heading block and stats row then stacked below it rather
 * than inside it. The source puts all three in ONE 845px column overlaid on the band, so the source band is
 * 1000px and the build is 850 + 510 + 92 + padding.
 *
 * THE RULE: with more than one sibling row, the measured height describes the BAND and must go on the
 * section — via its scoped CSS, not its `min_height` option, so the section does not become a flex
 * container and shrink its rows. With exactly one row the existing behaviour is kept, because there the row
 * genuinely is the band.
 *
 * A NOTE ON HOW THIS WAS FOUND, because the first attempt was wrong. The three build rows look exactly like
 * the source's three layers (two `position:absolute; inset:0` covers and a `z-index:10` content column), so
 * the first diagnosis was "the decorative covers are being emitted as content". That was false — dumping the
 * MAPPING showed the covers already captured in `sectionLayers` and every block a content block — and the
 * change made for it moved the count the wrong way, from 3 to 4. Read the mapping before theorising about
 * the render.
 *
 * The fixture is the real band, reduced under a predicate requiring the defect itself to survive: more than
 * one flexbox row, with the first one carrying the min-height.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/hero-height-on-a-multi-row-band-test.php"
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

/** The built section containing $needle, or null. */
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
$flex_rows = function ( array $sec ) {
	$out = array();
	foreach ( (array) ( $sec['_items'] ?? array() ) as $it ) {
		if ( is_array( $it ) && 'flexbox' === ( $it['type'] ?? '' ) ) { $out[] = $it; }
	}
	return $out;
};
$has_min_h = function ( $node ) {
	return (bool) preg_match( '/min-height\s*:/i', (string) ( $node['atts']['custom_css'] ?? '' ) );
};

$fixture = __DIR__ . '/fixtures/hero-height-on-a-multi-row-band.html';
if ( ! is_file( $fixture ) ) { fwrite( STDERR, "FAIL: fixture missing\n" ); exit( 1 ); }

$map   = FW_Site_Converter_Stitch::html_to_mapping( file_get_contents( $fixture ), 'H', 'h', true );
$pages = FW_Site_Converter_Mapper::build_pages( $map );
$sec   = $section_with( $pages, 'UNTOUCHED' );

echo "\n== The band still converts, with its rows intact\n";
$ok( null !== $sec, 'the band produces a section' );
if ( null === $sec ) { printf( "\n%d FAIL(S)\n", ++$fails ); exit( 1 ); }
$rows = $flex_rows( $sec );
$ok( count( $rows ) >= 2, sprintf( 'it has sibling rows — the case under test (got %d)', count( $rows ) ) );
$blob = (string) wp_json_encode( $sec );
$ok( false !== strpos( $blob, 'UNTOUCHED' ), 'its heading survives' );
$ok( false !== strpos( $blob, 'BEYOND THE REACH' ), 'and its eyebrow' );

echo "\n== The height is NOT pinned to the first row\n";

$ok( ! $has_min_h( $rows[0] ),
	'the first row carries no min-height of its own' );

echo "\n== …it is on the band\n";

$sec_css = (string) ( $sec['atts']['custom_css'] ?? '' );
$ok( (bool) preg_match( '/min-height\s*:/i', $sec_css ),
	'the section carries the measured height in its scoped CSS' );
// The section's min_height OPTION must stay empty: setting it renders the section display:flex, which
// shrinks a full-width flexbox row — the reason this value was moved off the section in the first place.
$opt = $sec['atts']['min_height']['base']['value'] ?? '';
$ok( '' === (string) $opt,
	sprintf( "and NOT in its min_height option, which would make the section a flex container (got '%s')", $opt ) );

echo "\n== NEGATIVE: a SINGLE-row hero still keeps the height on its row\n";

// With one row, the row IS the band, and the height belongs on it. This is the shape the original
// behaviour was written for and it must not change.
$single = '<!DOCTYPE html><html><body data-sc-cs="font-family:Inter, sans-serif"><main>'
	. '<section class="min-h-[80vh]" data-sc-cs="display:flex;align-items:center;min-height:800px;padding:0px">'
	. '<div data-sc-cs="display:flex;flex-direction:column;justify-content:center;width:1200px">'
	. '<h1 data-sc-cs="font-size:56px;font-weight:800">One Row Hero</h1>'
	. '<p data-sc-cs="font-size:18px">A single content column carries the whole band.</p>'
	. '</div></section></main></body></html>';
$ssec = $section_with( FW_Site_Converter_Mapper::build_pages( FW_Site_Converter_Stitch::html_to_mapping( $single, 'S', 's', true ) ), 'One Row Hero' );
$ok( null !== $ssec, 'the single-row hero converts' );
if ( null !== $ssec ) {
	$srows = $flex_rows( $ssec );
	$on_row = $srows && $has_min_h( $srows[0] );
	$on_sec = (bool) preg_match( '/min-height\s*:/i', (string) ( $ssec['atts']['custom_css'] ?? '' ) );
	$ok( 1 === count( $srows ), sprintf( 'it has exactly one row (got %d)', count( $srows ) ) );
	$ok( $on_row || $on_sec, 'and its measured height is carried somewhere' );
}

printf( "\n%s\n", $fails ? "{$fails} FAIL(S)" : 'ALL PASS' );
exit( $fails ? 1 : 0 );
