<?php
/**
 * Regression guard: a BOOKING / FORM column must not be claimed as a second pricing table.
 *
 * WHY THIS FILE EXISTS. A converted page rendered its service price list TWICE. Measured on the rendered
 * build, two `.fw-pt` elements inside one band:
 *
 *     y=1255  design-dark     5 rows  names ["HAIRCUT","BEARD TRIM","HAIR COLOR","HOT TOWEL SHAVE",
 *                                             "STYLING & FINISH"]   amounts [75,40,120,60,35]   <- correct
 *     y=1419  design-classic  5 rows  names ["Plan","Plan","Plan","Plan","Plan"]
 *                                             amounts [75,40,120,60,130]                        <- invented
 *
 * The source has exactly ONE price list. Checked for the usual explanation and it is not that: there is no
 * hidden responsive duplicate (every price leaf in the source is visible, `display:block`, opacity 1) and
 * the amount `130` does not occur in the source at all.
 *
 * Reproduced deterministically from the capture — the band is a two-column section and BOTH columns are
 * claimed:
 *
 *     sections.1.blocks.0.cols.0.blocks.1   layout=list  5 plans  the real price list
 *     sections.1.blocks.0.cols.1.blocks.3   layout=list  5 plans  the BOOKING WIDGET
 *
 * Column 1 is the appointment form. Its three dropdowns are flattened to text elsewhere in the pipeline,
 * and the resulting price-bearing lines clear the pricing gate: enough priced children, so
 * `is_pricing_table()` says yes. It has no plan names, which is why every row fell back to the literal
 * "Plan" (`$title !== '' ? $title : 'Plan'` in the mapper) — the placeholder was the symptom, the
 * misclassified column was the cause.
 *
 * THE RULE: a region that contains real form CONTROLS is a form, not a price table. `<select>` and
 * `<textarea>` are the discriminator chosen deliberately — a genuine pricing table may well contain an
 * `<input>` (a billing monthly/yearly switch is routinely a checkbox, and the shortcode's own toggle is a
 * button), but it does not contain a dropdown or a free-text area. Keying on `<input>` would have broken
 * every pricing table with a billing toggle.
 *
 * The fixture is the real section, reduced from the 392KB capture under a predicate requiring the defect
 * to SURVIVE each trim (two pricing blocks, one of them nameless), with class/aria noise stripped and
 * image srcs neutralised. Trimming under anything weaker produces a fixture that pins a different defect.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/booking-widget-is-not-a-price-table-test.php"
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

/** Every pricing block in a mapping, as array( 'names' => [...], 'layout' => '' ). */
$pricing_blocks = function ( array $map ) {
	$found = array();
	$walk  = function ( $v ) use ( &$walk, &$found ) {
		if ( ! is_array( $v ) ) { return; }
		if ( 'pricing' === ( $v['t'] ?? '' ) ) {
			$names = array();
			foreach ( (array) ( $v['plans'] ?? array() ) as $p ) { $names[] = (string) ( $p['title'] ?? '' ); }
			$found[] = array( 'names' => $names, 'layout' => (string) ( $v['layout'] ?? '' ) );
		}
		foreach ( $v as $x ) { $walk( $x ); }
	};
	$walk( $map );
	return $found;
};

$fixture = __DIR__ . '/fixtures/booking-widget-is-not-a-price-table.html';
if ( ! is_file( $fixture ) ) { fwrite( STDERR, "FAIL: fixture missing\n" ); exit( 1 ); }

echo "\n== The band yields ONE pricing table, not two\n";

$map    = FW_Site_Converter_Stitch::html_to_mapping( file_get_contents( $fixture ), 'B', 'b', true );
$blocks = $pricing_blocks( $map );

$ok( 1 === count( $blocks ), sprintf( 'exactly one pricing block (got %d)', count( $blocks ) ) );

echo "\n== …and it is the real price list, with its names\n";

$names = $blocks ? $blocks[0]['names'] : array();
$ok( in_array( 'HAIRCUT', $names, true ), 'the surviving block carries the service names' );
$ok( 5 === count( $names ), sprintf( 'with all five rows (got %d)', count( $names ) ) );

echo "\n== No nameless block survives anywhere\n";

$nameless = 0;
foreach ( $blocks as $b ) { if ( $b['names'] && '' === implode( '', $b['names'] ) ) { $nameless++; } }
$ok( 0 === $nameless, sprintf( 'no block has all-empty plan names (got %d)', $nameless ) );

echo "\n== …so the placeholder never reaches the page\n";

$pb = wp_json_encode( FW_Site_Converter_Mapper::build_pages( $map ) );
$ok( false === strpos( $pb, '"plan_title":"Plan"' ), 'no plan is titled with the literal placeholder' );
// The fabricated amount rode on the misclassified column; the source has no such price.
$ok( false === strpos( $pb, '"130"' ), 'and the amount the source never had is gone' );

echo "\n== NEGATIVE: a real pricing table is still claimed\n";

// The membership fixture: three cards, each with its own price block. This is the case an earlier guard in
// this same function broke, so it is asserted here too.
$memb = __DIR__ . '/fixtures/membership-cards-with-price-blocks.html';
if ( is_file( $memb ) ) {
	$mblocks = $pricing_blocks( FW_Site_Converter_Stitch::html_to_mapping( file_get_contents( $memb ), 'M', 'm', true ) );
	$ok( count( $mblocks ) >= 1, 'a membership table is still claimed' );
	$mnames = $mblocks ? $mblocks[0]['names'] : array();
	$ok( 3 === count( $mnames ), sprintf( 'with its three plans (got %d)', count( $mnames ) ) );
}

// …and the price LIST fixture, the other direction of the same gate.
$plist = __DIR__ . '/fixtures/price-list-rows.html';
if ( is_file( $plist ) ) {
	$pblocks = $pricing_blocks( FW_Site_Converter_Stitch::html_to_mapping( file_get_contents( $plist ), 'P', 'p', true ) );
	$ok( count( $pblocks ) >= 1, 'a plain price list is still claimed' );
}

echo "\n== NEGATIVE: a pricing table with a billing CHECKBOX keeps working\n";

// The discriminator is a dropdown / free-text area, NOT any form input: a monthly/yearly switch is often a
// checkbox, and excluding on `<input>` would have disqualified every pricing table that has one.
$toggle = '<!DOCTYPE html><html><body data-sc-cs="font-family:Inter, sans-serif"><main>'
	. '<section data-sc-cs="padding:80px 0px;display:block">'
	. '<div data-sc-cs="display:block"><label data-sc-cs="display:inline-block">Yearly'
		. '<input type="checkbox" data-sc-cs="display:inline-block"></label></div>'
	. '<div data-sc-cs="display:grid;grid-template-columns:360px 360px;gap:32px">'
	. '<div data-sc-cs="padding:24px"><h3 data-sc-cs="font-size:20px">Starter</h3>'
		. '<p data-sc-cs="font-size:36px;font-weight:800">$19</p>'
		. '<ul><li data-sc-cs="font-size:14px">One seat</li><li data-sc-cs="font-size:14px">Email support</li></ul></div>'
	. '<div data-sc-cs="padding:24px"><h3 data-sc-cs="font-size:20px">Team</h3>'
		. '<p data-sc-cs="font-size:36px;font-weight:800">$49</p>'
		. '<ul><li data-sc-cs="font-size:14px">Ten seats</li><li data-sc-cs="font-size:14px">Priority support</li></ul></div>'
	. '</div></section></main></body></html>';
$tblocks = $pricing_blocks( FW_Site_Converter_Stitch::html_to_mapping( $toggle, 'T', 't', true ) );
$ok( count( $tblocks ) >= 1, 'a priced grid beside a checkbox toggle is still a pricing table' );

printf( "\n%s\n", $fails ? "{$fails} FAIL(S)" : 'ALL PASS' );
exit( $fails ? 1 : 0 );
