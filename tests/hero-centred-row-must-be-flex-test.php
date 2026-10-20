<?php
/**
 * Regression guard: a wrapper that is told to CENTRE must actually be a flex container.
 *
 * WHY THIS FILE EXISTS. A converted hero rendered its text hard against the top of the band with ~530px
 * of empty space under it; the source centres that text vertically. Measured on both pages:
 *
 *     source   inner wrapper 1440x912  display:flex  flex-direction:column  justify-content:center
 *                                      padding 96/48   -> the 516px text block sits centred
 *     build    inner wrapper 1344x900  display:BLOCK  align-items:center     -> 201px gap below
 *
 * The emitted node said so plainly:
 *
 *     display='block'  direction='row'  align_items='center'  justify_content=''
 *     custom_css = selector{width:100%} selector{min-height:90vh}
 *
 * So the measurement was right and the carry was right: the hero's height (90vh) and its centring intent
 * both survived. They were simply written onto a node that is not a flex container, and `align-items` does
 * nothing on a block. The framework confirms it — the ONLY rule that can give `.fw-flexbox` a display is
 * gated on a span-classed child:
 *
 *     .fw-flexbox:not(.fw-flex):not(.fw-grid):has(> [class*=fw-span-]){display:flex;flex-wrap:wrap}
 *
 * The hero wrapper has no `fw-span-*` child, so it stays `display:block`. Measured across the whole page,
 * 44 of 51 `.fw-flexbox` elements fail that gate, so this is a class of defect, not one hero.
 *
 * THE AXIS MATTERS, and this is the part that is easy to get wrong. The node was `direction:row`, and on a
 * ROW flex `align-items:center` does centre vertically — so merely flipping `display` to flex would have
 * looked correct in isolation. But a `display:block` wrapper STACKS its children, and this one holds two
 * (an eyebrow row and the heading); turning it into a ROW flex would put them side by side. The source's
 * own mechanism is the one to mirror: `flex-direction:column` with `justify-content:center`, which centres
 * on the column's MAIN axis while leaving the children full-width and left-aligned exactly as block flow
 * had them. `align_items` must therefore be cleared, not kept — kept, it would centre them horizontally,
 * and the source's hero text is left-aligned.
 *
 * The fixture is the real hero section, reduced from the 392KB capture under a predicate requiring the
 * defect to SURVIVE the trim. Note it deliberately keeps `class` attributes: the hero's height comes from
 * a `min-h-[90vh]` utility class, and the first reduction stripped classes and lost the defect entirely.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/hero-centred-row-must-be-flex-test.php"
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

/** Every flexbox node in a built page, flattened. */
$flexboxes = function ( $pages ) {
	$out  = array();
	$walk = function ( $n ) use ( &$walk, &$out ) {
		if ( ! is_array( $n ) ) { return; }
		foreach ( $n as $x ) {
			if ( ! is_array( $x ) ) { continue; }
			if ( 'flexbox' === ( $x['type'] ?? '' ) ) { $out[] = $x; }
			$walk( $x['_items'] ?? $x );
		}
	};
	$walk( $pages );
	return $out;
};

/** A node that carries alignment intent but cannot act on it. */
$inert = function ( array $x ) {
	$a = $x['atts'] ?? array();
	if ( 'block' !== (string) ( $a['display'] ?? '' ) ) { return false; }
	return '' !== (string) ( $a['align_items']['base'] ?? '' )
		|| '' !== (string) ( $a['justify_content']['base'] ?? '' );
};

$fixture = __DIR__ . '/fixtures/hero-centred-row-must-be-flex.html';
if ( ! is_file( $fixture ) ) { fwrite( STDERR, "FAIL: fixture missing\n" ); exit( 1 ); }

$map   = FW_Site_Converter_Stitch::html_to_mapping( file_get_contents( $fixture ), 'H', 'h', true );
$pages = FW_Site_Converter_Mapper::build_pages( $map );
$boxes = $flexboxes( $pages );

echo "\n== No wrapper is told to align while unable to\n";

$bad = array_values( array_filter( $boxes, $inert ) );
$ok( 0 === count( $bad ), sprintf( 'no flexbox is display:block with an alignment set (got %d)', count( $bad ) ) );

echo "\n== The band centres its content on a real flex container\n";

// WHICH BOX centres is not the invariant — that it centres at all, on a box that can, is.
//
// When this guard was written the centring lived on the hero's single ROW, converted from a block into a
// column flex. A later fix moved a MULTI-ROW band's measured height off the first row and onto the
// SECTION (it was landing on a few-word eyebrow and inflating it by ~750px), which took the per-row pass
// with it — and this test caught that collision, reporting `direction='row'` where it wanted `column`.
// The resolution mirrors what such a source does on its own section: the SECTION becomes the centring
// column. So the assertion is now on the outcome.
$hero_sec = null;
$findsec  = function ( $n ) use ( &$findsec, &$hero_sec ) {
	if ( ! is_array( $n ) || null !== $hero_sec ) { return; }
	foreach ( $n as $x ) {
		if ( ! is_array( $x ) ) { continue; }
		if ( 'section' === ( $x['type'] ?? '' ) ) { $hero_sec = $x; return; }
		$findsec( $x['_items'] ?? $x );
	}
};
$findsec( $pages );
$ok( null !== $hero_sec, 'the hero band produces a section' );

$sec_css  = (string) ( $hero_sec['atts']['custom_css'] ?? '' );
$sec_cent = (bool) preg_match( '/display\s*:\s*flex/i', $sec_css )
	&& (bool) preg_match( '/flex-direction\s*:\s*column/i', $sec_css )
	&& (bool) preg_match( '/justify-content\s*:\s*center/i', $sec_css );

// …or, for a single-row band, on the row itself — the original shape, still valid.
$row_cent = false;
foreach ( $boxes as $x ) {
	$a = $x['atts'];
	if ( 'flex' === (string) ( $a['display'] ?? '' )
		&& 'column' === (string) ( $a['direction']['base'] ?? '' )
		&& 'center' === (string) ( $a['justify_content']['base'] ?? '' ) ) { $row_cent = true; break; }
}

$ok( $sec_cent || $row_cent, 'something centres it on the vertical axis — the section, or a single row' );
$ok( (bool) preg_match( '/min-height\s*:/i', $sec_css ) || $row_cent,
	'and the band carries its measured height' );

// The cross axis must be left alone either way: on a column it is the HORIZONTAL one, and centring there
// would centre text the source left-aligns.
$cross_centred = (bool) preg_match( '/align-items\s*:\s*center/i', $sec_css );
$ok( ! $cross_centred, 'the cross axis is not centred, so the text stays left-aligned like the source' );

echo "\n== NEGATIVE: a genuine ROW hero keeps its cross-axis centring\n";

// A two-column hero (text beside media) is a real row: there `align-items:center` is the correct
// vertical centring and must not be rewritten into a column.
$row = '<!DOCTYPE html><html><body data-sc-cs="font-family:Inter, sans-serif"><main>'
	. '<section class="min-h-[70vh]" data-sc-cs="display:flex;align-items:center;min-height:700px;padding:0px">'
	. '<div data-sc-cs="display:flex;flex-direction:row;align-items:center;gap:48px">'
	. '<div data-sc-cs="display:block;width:600px"><h1 data-sc-cs="font-size:56px;font-weight:800">Headline here</h1>'
		. '<p data-sc-cs="font-size:18px">A supporting sentence for the hero.</p></div>'
	. '<div data-sc-cs="display:block;width:600px"><img src="about:blank" data-sc-cs="display:block;width:600px;height:400px"></div>'
	. '</div></section></main></body></html>';
$rboxes = $flexboxes( FW_Site_Converter_Mapper::build_pages( FW_Site_Converter_Stitch::html_to_mapping( $row, 'R', 'r', true ) ) );
$rbad   = array_values( array_filter( $rboxes, $inert ) );
$ok( 0 === count( $rbad ), sprintf( 'a row hero also has no inert alignment (got %d)', count( $rbad ) ) );
$has_row = false;
foreach ( $rboxes as $x ) {
	if ( 'row' === (string) ( $x['atts']['direction']['base'] ?? '' ) && 'flex' === (string) ( $x['atts']['display'] ?? '' ) ) { $has_row = true; break; }
}
$ok( $has_row, 'and its two columns are still a ROW flex, not rewritten to a column' );

printf( "\n%s\n", $fails ? "{$fails} FAIL(S)" : 'ALL PASS' );
exit( $fails ? 1 : 0 );
