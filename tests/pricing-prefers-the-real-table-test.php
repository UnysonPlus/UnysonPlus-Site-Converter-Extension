<?php
/**
 * Regression guard: "prefer the innermost pricing table" must not prefer a CARD'S OWN PRICE BLOCK.
 *
 * WHY THIS FILE EXISTS. A guard added earlier the same day fixed a real defect: a price LIST sits inside a
 * band that also clears the pricing gate, the walk meets the BAND first, and it built three junk plans out
 * of three unrelated columns. The rule written then was "a container that CONTAINS a pricing table is not
 * itself one" — prefer the innermost.
 *
 * On the next source that rule fired the wrong way. A membership band — three cards, three prices, a perk
 * list in each — was NOT claimed at all, because each card holds its own price block:
 *
 *     <div class="pb-6 mb-6 border-b">  "$81/MONTH"  +  "Billed annually ($972/year)"
 *
 * Two priced children. That clears the gate on its own, so every card contained a "pricing table", and the
 * real three-card table was rejected. The whole band fell through to plain cards and its sixteen perk lines
 * went with it.
 *
 * THE DISCRIMINATOR IS THE COUNT, measured on both sources:
 *
 *   band that should lose to its inner list   outer 1 priced child · inner 3   -> prefer INNER
 *   membership table with per-card prices     outer 3 priced children · inner 2 -> prefer OUTER
 *
 * So an inner container only pre-empts its ancestor when it has MORE priced children. A card's price area
 * always has fewer than the table of cards around it; a genuine inner table always has more than the band
 * that merely contains it.
 *
 * Both directions are asserted here, from reduced real captures, because a guard that only ever gets tested
 * in one direction is how this defect was introduced.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/pricing-prefers-the-real-table-test.php"
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

echo "\n== OUTER wins: a membership table whose cards each hold a price block\n";

$fixture = __DIR__ . '/fixtures/membership-cards-with-price-blocks.html';
if ( ! is_file( $fixture ) ) { fwrite( STDERR, "FAIL: fixture missing\n" ); exit( 1 ); }
$html = file_get_contents( $fixture );

$map = FW_Site_Converter_Stitch::html_to_mapping( $html, 'M', 'm', true );
$mb  = wp_json_encode( $map );

$plans = 0;
$walk = function ( $v ) use ( &$walk, &$plans ) {
	if ( ! is_array( $v ) ) { return; }
	if ( 'pricing' === ( $v['t'] ?? '' ) ) { $plans = max( $plans, count( (array) ( $v['plans'] ?? array() ) ) ); }
	foreach ( $v as $x ) { $walk( $x ); }
};
$walk( $map );

$ok( false !== strpos( $mb, '"t":"pricing"' ), 'the band is claimed as a pricing table' );
$ok( 3 === $plans, "with THREE plans, not a card's two-line price block ($plans)" );

echo "\n== …and the plans are the cards, not the price lines\n";

$ok( false !== strpos( $mb, 'BLACK CARD' ) || false !== strpos( $mb, 'STUDIO VIP' ),
	'a plan name from the cards is carried' );

echo "\n== INNER still wins where it should (the case the guard was written for)\n";

// The opposite direction, from the other capture: a band whose own children are a copy column, a mock-up
// and the price list. Only the list is a real table, and it has MORE priced children than the band.
$inner_fx = __DIR__ . '/fixtures/price-list-rows.html';
if ( is_file( $inner_fx ) ) {
	$imap = FW_Site_Converter_Stitch::html_to_mapping( file_get_contents( $inner_fx ), 'P', 'p', true );
	$iblob = wp_json_encode( $imap );
	$iplans = 0;
	$iwalk = function ( $v ) use ( &$iwalk, &$iplans ) {
		if ( ! is_array( $v ) ) { return; }
		if ( 'pricing' === ( $v['t'] ?? '' ) ) { $iplans = max( $iplans, count( (array) ( $v['plans'] ?? array() ) ) ); }
		foreach ( $v as $x ) { $iwalk( $x ); }
	};
	$iwalk( $imap );
	$ok( false !== strpos( $iblob, '"t":"pricing"' ), 'the price list is still claimed' );
	$ok( 3 === $iplans, "with its three rows as the plans ($iplans)" );
	$ok( (bool) preg_match( '/"layout":"list"/', $iblob ), '…and still as a list layout' );
}

echo "\n== NEGATIVE: a plain feature grid is still not a pricing table\n";

$feat = '<!DOCTYPE html><html><body><main>'
	. '<section data-sc-cs="padding:80px 0px;display:block">'
	. '<div data-sc-cs="display:grid;grid-template-columns:384px 384px 384px;gap:32px">'
	. '<div data-sc-cs="padding:24px"><h3>Fast</h3><p>We arrive on time, every time.</p></div>'
	. '<div data-sc-cs="padding:24px"><h3>Safe</h3><p>Fully insured and background checked.</p></div>'
	. '<div data-sc-cs="padding:24px"><h3>Clean</h3><p>Non-toxic supplies as standard.</p></div>'
	. '</div></section></main></body></html>';
$fblob = wp_json_encode( FW_Site_Converter_Stitch::html_to_mapping( $feat, 'F', 'f', true ) );
$ok( false === strpos( $fblob, '"t":"pricing"' ), 'an unpriced grid is not claimed' );

printf( "\n%s\n", $fails ? "{$fails} FAIL(S)" : 'ALL PASS' );
exit( $fails ? 1 : 0 );
