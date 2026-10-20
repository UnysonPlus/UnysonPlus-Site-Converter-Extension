<?php
/**
 * Regression guard: one source box produces ONE painted box.
 *
 * WHY THIS FILE EXISTS. A rating pill — `bg-white/5 border border-white/10 px-4 py-2 rounded-full
 * backdrop-blur-sm` — converted with a visible DOUBLE BACKGROUND: a ring around a ring. Measured:
 *
 *   source        258x34  padding 8px 16px   one painted box
 *   build outer   292x59  padding 8px 16px   background:rgba(255,255,255,.05) + backdrop-filter
 *   build inner   258x41  padding .5rem 1rem background-color:rgb(255 255 255 / 0.05)
 *
 * The two rules are not even written the same way, which is the tell: `rgba()` + `px` is the box-skin
 * harvester's notation, `rgb(… / …)` + `rem` is a direct translation of the element's own Tailwind classes.
 * The outer then adds its own 8px/16px around the inner pill, which is the visible ring.
 *
 * THE CAUSE. The column carries BOTH:
 *   - `cardBox`, a skin harvested from the pill by cell_card_skin(), painted on the wrapper flexbox, and
 *   - `html`, the pill itself riding as a verbatim mirror, which already carries its own measured box.
 *
 * The same source box, translated twice by two different paths, onto two nested elements. The mapping was
 * not wrong about the box; it was right about it twice.
 *
 * THE RULE: when a cell's content rides VERBATIM, no skin is harvested from it. The verbatim copy already
 * reproduces the box exactly, and the skin can only duplicate it onto a wrapper the converter itself added.
 * (Stripping the box out of the verbatim markup instead would mean editing a mirror whose entire purpose is
 * to be unedited.)
 *
 * This does NOT touch the case the skin exists for: a cell the converter DECOMPOSES into real shortcodes
 * keeps its harvested `cardBox`, because nothing else would paint it. Pinned by the negative below.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/verbatim-cell-is-not-also-skinned-test.php"
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

$fixture = __DIR__ . '/fixtures/pill-box-harvested-twice.html';
if ( ! is_file( $fixture ) ) { fwrite( STDERR, "FAIL: fixture missing\n" ); exit( 1 ); }
$html = file_get_contents( $fixture );
$map  = FW_Site_Converter_Stitch::html_to_mapping( $html, 'B', 'b', true );

echo "\n== The fixture still carries a verbatim pill\n";

// If the pill stops riding verbatim the fixture has drifted and the rest proves nothing.
$verbatim = 0;
$both     = 0;
$walk = function ( $v ) use ( &$walk, &$verbatim, &$both ) {
	if ( ! is_array( $v ) ) { return; }
	$has_html = isset( $v['html'] ) && '' !== trim( (string) $v['html'] );
	$has_skin = ! empty( $v['cardBox'] );
	if ( $has_html && false !== strpos( (string) $v['html'], 'rounded-full' ) ) { $verbatim++; }
	if ( $has_html && $has_skin ) { $both++; }
	foreach ( $v as $x ) { $walk( $x ); }
};
$walk( $map );
$ok( $verbatim > 0, "the pill rides verbatim ($verbatim)" );

echo "\n== …and no skin is harvested on top of it\n";

$ok( 0 === $both, "no cell carries BOTH a verbatim html and a harvested cardBox ($both)" );

echo "\n== …so the box is painted once, not twice\n";

$blob = wp_json_encode( FW_Site_Converter_Mapper::build_pages( $map ) );
// The harvester's own notation is the fingerprint: rgba() + a pill radius in one generated rule. The
// element's own copy, inside the verbatim markup, is written differently and is not counted here.
$skin_rules = preg_match_all( '/selector\{[^}]*border-radius:9999px[^}]*\}/i', $blob, $sm );
$ok( $skin_rules <= 1, "at most one generated pill rule ($skin_rules)" );

echo "\n== NEGATIVE: a DECOMPOSED cell keeps its skin\n";

// The skin exists for the cell the converter turns into real shortcodes — there, nothing else paints the
// box, so removing it would strip the card. A heading-plus-prose panel is exactly that case.
$card = '<!DOCTYPE html><html><body><main>'
	. '<section data-sc-cs="background-color:rgb(255,255,255);padding:80px 0px;display:block">'
	. '<div data-sc-cs="display:grid;grid-template-columns:384px 384px;gap:32px">'
	. '<div data-sc-cs="padding:24px;border-radius:12px;background-color:rgb(241,245,249);display:block">'
		. '<h3 data-sc-cs="font-size:20px;font-weight:700;line-height:28px">Trained Staff</h3>'
		. '<p data-sc-cs="font-size:14px;line-height:20px">Every member is background checked and insured.</p></div>'
	. '<div data-sc-cs="padding:24px;border-radius:12px;background-color:rgb(241,245,249);display:block">'
		. '<h3 data-sc-cs="font-size:20px;font-weight:700;line-height:28px">Eco Products</h3>'
		. '<p data-sc-cs="font-size:14px;line-height:20px">Non-toxic supplies that care for your space.</p></div>'
	. '</div></section></main></body></html>';
$cmap = FW_Site_Converter_Stitch::html_to_mapping( $card, 'C', 'c', true );
$skins = 0;
$cwalk = function ( $v ) use ( &$cwalk, &$skins ) {
	if ( ! is_array( $v ) ) { return; }
	if ( ! empty( $v['cardBox'] ) ) { $skins++; }
	foreach ( $v as $x ) { $cwalk( $x ); }
};
$cwalk( $cmap );
$ok( $skins > 0, "a decomposed card cell still carries its cardBox ($skins)" );
$ok( false !== strpos( wp_json_encode( $cmap ), 'Trained Staff' ), '…and its content' );

printf( "\n%s\n", $fails ? "{$fails} FAIL(S)" : 'ALL PASS' );
exit( $fails ? 1 : 0 );
