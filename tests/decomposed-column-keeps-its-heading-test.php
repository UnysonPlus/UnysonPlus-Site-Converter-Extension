<?php
/**
 * Regression guard: a DECOMPOSED column keeps its heading and its prose.
 *
 * WHY THIS FILE EXISTS. A contact band lost its own `<h2>` and the paragraph under it — the heading the
 * whole section is named after. Measured on a real conversion, three strings gone from one band:
 *
 *     "Get in Touch with Our Concierge Team"   (the band's h2)
 *     "Have questions regarding custom …"      (its intro paragraph)
 *     "Send an Inquiry"                        (the form panel's own heading)
 *
 * The cause is a deliberate decomposition doing its job too narrowly. A column that leads with an EYEBROW
 * and then lists items is split apart on purpose — otherwise `card_from_cell()` claims the whole column by
 * its first item's heading and the label plus the other items are lost. That path emits exactly two things:
 *
 *     array( 'overline' => …, 'grid' => array( 'cells' => $inner, 'gridCols' => 1 ) )
 *
 * Anything sitting BETWEEN the eyebrow and the list — which is where a band's heading and intro live — is
 * in neither, so it is dropped silently. The column kept `DIRECT REACH` and the contact rows, and threw
 * away the sentence they were introducing.
 *
 * The sibling path already had the answer: `cell_lead_heading_block()` collects exactly that heading and
 * the prose after it. It simply was not called here.
 *
 * Order matters as much as presence: `gridCols: 1` stacks its cells, so the heading and intro are prepended
 * as cells of that stack and come out above the list, where the source has them.
 *
 * The fixture is a reduced real capture (30.8 KB → 6.9 KB), trimmed under a predicate requiring the
 * overline to still be CARRIED while the heading is still LOST — so it pins this defect and not a different
 * one. Image srcs neutralised.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/decomposed-column-keeps-its-heading-test.php"
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

$fixture = __DIR__ . '/fixtures/decomposed-column-lead-heading.html';
if ( ! is_file( $fixture ) ) { fwrite( STDERR, "FAIL: fixture missing\n" ); exit( 1 ); }
$html = file_get_contents( $fixture );

echo "\n== The column is still the decomposed shape\n";

$map = FW_Site_Converter_Stitch::html_to_mapping( $html, 'R', 'r', true );
$mb  = wp_json_encode( $map );

// If the decomposition stops happening the fixture has drifted and nothing below is about this defect.
$decomposed = false;
$walk = function ( $v ) use ( &$walk, &$decomposed ) {
	if ( ! is_array( $v ) ) { return; }
	if ( ! empty( $v['overline'] ) && isset( $v['grid']['cells'] ) ) { $decomposed = true; }
	foreach ( $v as $x ) { $walk( $x ); }
};
$walk( $map );
$ok( $decomposed, 'a column decomposes into overline + a single-track grid' );
$ok( false !== strpos( $mb, 'DIRECT REACH' ), 'and its eyebrow is carried' );

echo "\n== Its heading and prose are carried too\n";

$ok( false !== strpos( $mb, 'Get in Touch with Our Concierge Team' ), 'the band heading survives' );

$pb = wp_json_encode( FW_Site_Converter_Mapper::build_pages( $map ) );
$ok( false !== strpos( $pb, 'Get in Touch with Our Concierge Team' ), '…all the way to the built page' );

echo "\n== …in the source's order\n";

// `gridCols: 1` stacks, so the heading belongs above the list it introduces.
$head_at = strpos( $pb, 'Get in Touch with Our Concierge Team' );
$list_at = strpos( $pb, 'Toll-Free Hotline' );
$ok( false !== $head_at && false !== $list_at && $head_at < $list_at,
	'the heading precedes the items it introduces' );

// …and below its own eyebrow, which leads the column.
$ov_at = strpos( $pb, 'DIRECT REACH' );
$ok( false !== $ov_at && $ov_at < $head_at, 'and follows the eyebrow that leads the column' );

echo "\n== NEGATIVE: nothing is duplicated\n";

// The heading must be ADDED where it was missing, not emitted twice — the eyebrow path and the lead-heading
// path both walk the same cell.
$ok( 1 === substr_count( $pb, 'Get in Touch with Our Concierge Team' ), 'the heading appears exactly once' );
$ok( 1 === substr_count( $pb, 'DIRECT REACH' ), 'and the eyebrow exactly once' );

echo "\n== NEGATIVE: a column with no lead heading is unchanged\n";

// An eyebrow + list with nothing between them must still decompose to just those two things.
$nohead = '<!DOCTYPE html><html><body><main>'
	. '<section data-sc-cs="background-color:rgb(255,255,255);padding:80px 0px;display:block">'
	. '<div data-sc-cs="display:grid;grid-template-columns:600px 600px;gap:48px">'
	. '<div data-sc-cs="display:block">'
		. '<p data-sc-cs="font-size:12px;text-transform:uppercase;letter-spacing:1.5px">THE PROBLEM</p>'
		. '<div data-sc-cs="display:block"><div data-sc-cs="padding:16px;display:block"><h4 data-sc-cs="font-size:16px;font-weight:700">Slow response</h4>'
			. '<p data-sc-cs="font-size:14px">Calls go unanswered for days.</p></div>'
		. '<div data-sc-cs="padding:16px;display:block"><h4 data-sc-cs="font-size:16px;font-weight:700">Hidden fees</h4>'
			. '<p data-sc-cs="font-size:14px">The quote is never the invoice.</p></div></div></div>'
	. '<div data-sc-cs="display:block"><p data-sc-cs="font-size:14px">A second column.</p></div>'
	. '</div></section></main></body></html>';
$nb = wp_json_encode( FW_Site_Converter_Stitch::html_to_mapping( $nohead, 'N', 'n', true ) );
$ok( false !== strpos( $nb, 'THE PROBLEM' ), 'an eyebrow-only column still carries its eyebrow' );
$ok( false !== strpos( $nb, 'Slow response' ), '…and its items' );

printf( "\n%s\n", $fails ? "{$fails} FAIL(S)" : 'ALL PASS' );
exit( $fails ? 1 : 0 );
