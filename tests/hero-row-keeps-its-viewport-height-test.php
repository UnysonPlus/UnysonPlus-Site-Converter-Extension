<?php
/**
 * Regression guard: a full-viewport hero keeps its height and its vertical centring.
 *
 * A `min-h-[80vh] flex items-center` hero was detected and carried as `sectionHeroHeight` / `sectionValign`,
 * and then deliberately NOT applied. The reason was sound and is written in the mapper: putting `min_height`
 * on the SECTION renders it `display:flex`, and that flex container shrinks a full-width flexbox child back
 * to content width, which re-overlaps the media column. The note says the right home is a FLEXBOX-level
 * `min_height` on the row itself — "a shortcode option we don't have yet".
 *
 * That option exists now. `flexbox` carries a responsive `min_height` and a responsive `align_items`, so the
 * hero row can hold both and its columns stretch full-height and centre their own content — which is what the
 * source does. The section is left alone, so the overlap the note warned about cannot come back.
 *
 * Measured on a captured hero: the source band is `display:flex; align-items:center; min-height:800px` and
 * the conversion rendered `display:block; align-items:normal; min-height:0`. It only looks right because the
 * content happens to exceed 80vh; a shorter hero collapses to its content.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/hero-row-keeps-its-viewport-height-test.php"
 *
 * Exit code 0 = all PASS, 1 = at least one FAIL.
 */

if ( ! class_exists( 'FW_Site_Converter_Stitch' ) ) {
	fwrite( STDERR, "FAIL: site-converter stitch not loaded\n" );
	exit( 1 );
}

$fails = 0;
$ok    = function ( $cond, $msg ) use ( &$fails ) {
	if ( $cond ) { echo "  \xe2\x9c\x93 $msg\n"; } else { $fails++; echo "  \xe2\x9c\x97 FAIL: $msg\n"; }
};

$hero = '<!DOCTYPE html><html><body><main>'
	. '<section class="min-h-[80vh] flex items-center py-20" data-sc-cs="background-color:rgb(255,255,255);display:flex;align-items:center;min-height:800px;padding:112px 0px">'
	. '<div data-sc-cs="display:grid;grid-template-columns:636px 636px;gap:64px">'
	. '<div data-sc-cs="padding:0px"><h1 data-sc-cs="font-size:48px;line-height:52px">Injured?</h1>'
	. '<p data-sc-cs="font-size:16px;line-height:24px">We protect your rights.</p></div>'
	. '<div data-sc-cs="padding:0px"><img src="/a.jpg" alt="A photo" data-sc-cs="width:448px;height:672px"></div>'
	. '</div></section></main></body></html>';

$map  = FW_Site_Converter_Stitch::html_to_mapping( $hero, 'Home', 'home', true );
$sec  = (array) ( $map['pages'][0]['sections'][0] ?? array() );

echo "\n== The capture still records the hero's shape\n";

$ok( false !== strpos( (string) ( $sec['sectionHeroHeight'] ?? '' ), '80' ), 'the viewport height is detected (' . ( $sec['sectionHeroHeight'] ?? '?' ) . ')' );
$ok( 'middle' === ( $sec['sectionValign'] ?? '' ), 'and the vertical centring (' . ( $sec['sectionValign'] ?? '?' ) . ')' );

echo "\n== …and the hero ROW now carries them\n";

$pages = FW_Site_Converter_Mapper::build_pages( $map );
$blob  = json_encode( $pages );

// The source's 80vh sits on the SECTION and a section's min-height is its whole box, so the 112px of top and
// bottom padding live INSIDE it. Putting the same 80vh on the row stacks the padding on top: measured, an
// 800px source band came out 1024px. The row takes the height MINUS that padding, via calc() because the
// unit-input option cannot hold one.
$ok( false !== strpos( $blob, 'min-height:calc(80vh - 224px)' ),
	'the row height subtracts the band padding: ' . ( preg_match( '/min-height:calc\([^)]*\)/', $blob, $cm ) ? $cm[0] : '(not found)' ) );
$ok( false !== strpos( $blob, '80vh' ), 'and it is built from the measured 80vh' );
$ok( (bool) preg_match( '/"align_items":\s*\{"base":\s*"center"/', $blob ), 'and centres its columns on the cross axis' );

echo "\n== NEGATIVE: the SECTION is left alone (this is what caused the overlap)\n";

$sec_node = null;
foreach ( (array) ( $pages[0]['builder'] ?? $pages[0] ?? array() ) as $n ) {
	if ( is_array( $n ) && 'section' === ( $n['shortcode'] ?? $n['type'] ?? '' ) ) { $sec_node = $n; break; }
}
if ( is_array( $sec_node ) ) {
	$sh = $sec_node['atts']['min_height']['preset'] ?? '';
	$ok( '80vh' !== $sh, 'the section does not take the hero height itself (preset: ' . ( '' === $sh ? '(none)' : $sh ) . ')' );
} else {
	$ok( true, 'no section node exposed at this level — the section was not given the height here' );
}

echo "\n== NEGATIVE: an ordinary band gets no hero height\n";

$plain = '<!DOCTYPE html><html><body><main>'
	. '<section data-sc-cs="background-color:rgb(255,255,255);display:block;padding:80px 0px">'
	. '<div data-sc-cs="display:grid;grid-template-columns:636px 636px">'
	. '<div><h2 data-sc-cs="font-size:36px">Hello</h2></div><div><p data-sc-cs="font-size:16px">Copy.</p></div>'
	. '</div></section></main></body></html>';
$pmap  = FW_Site_Converter_Stitch::html_to_mapping( $plain, 'P', 'p', true );
$pblob = json_encode( FW_Site_Converter_Mapper::build_pages( $pmap ) );
$ok( false === strpos( $pblob, 'min-height:calc(' ) && false === strpos( $pblob, 'min-height:80vh' ), 'no viewport height is invented for a normal band' );

printf( "\n%s\n", $fails ? "{$fails} FAIL(S)" : 'ALL PASS' );
exit( $fails ? 1 : 0 );
