<?php
/**
 * Regression guard: a testimonials claim must not discard a card it does not recognise.
 *
 * `is_testimonials_grid()` claimed a grid when `$cards >= count( $kids ) - 1` — that is, while explicitly
 * NOT recognising one child. The builder then keeps the recognised quote cards and the unrecognised sibling
 * is simply gone.
 *
 * Measured on a captured page: a six-card feature grid, written as two rows of three. The first row is
 *
 *     Customer Input | Designer Tools | Artwork Generation
 *
 * and the Designer Tools card reads "…that fits each product best — Zakeke, Customily, Aurigma, KornitX,
 * Printess, and more." The weak "dash before a capitalised word" signal in looks_quote_card() treats that as
 * an attribution, so 2 of the 3 matched, `3 >= 3 - 1` passed, the row was claimed as testimonials with TWO
 * items, and the Customer Input card vanished from the converted page. The second row (Routing, Fulfillment,
 * Tracking) has no dash and converts correctly as a three-column row — which is also the proof that the
 * fallback for this shape is good.
 *
 * The fix is the one the existing comment in looks_quote_card() argues for: do not re-tighten the weak
 * signal (that was tried, reverted, and measured WORSE, because a rejected card fell to a path that kept
 * less than the wrong claim did). Instead, stop the claim from discarding siblings. If every child is a
 * quote card, the grid is a testimonials grid and nothing is lost by claiming it. If one is not, claiming
 * costs content, so the grid decomposes instead and every card survives.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/testimonials-claim-keeps-every-card-test.php"
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

$ld = new ReflectionMethod( 'FW_Site_Converter_Stitch', 'load_dom' );
$ld->setAccessible( true );
$is = new ReflectionMethod( 'FW_Site_Converter_Stitch', 'is_testimonials_grid' );
$is->setAccessible( true );

$grid_of = function ( $cards ) use ( $ld ) {
	$html = '<!DOCTYPE html><html><body><div class="grid" data-sc-cs="display:grid;grid-template-columns:repeat(3, 1fr)">'
		. "\n" . implode( "\n", $cards ) . "\n" . '</div></body></html>';
	$dom = $ld->invoke( null, $html );
	foreach ( $dom->getElementsByTagName( 'div' ) as $d ) {
		if ( 'grid' === (string) $d->getAttribute( 'class' ) ) { return $d; }
	}
	return null;
};
$card = function ( $title, $body ) {
	return '<div class="card" data-sc-cs="display:block;border-radius:8px">'
		. '<h3 data-sc-cs="font-size:20px;font-weight:700">' . $title . '</h3>'
		. '<p data-sc-cs="font-size:16px">' . $body . '</p></div>';
};
$quote_card = function ( $quote, $who ) {
	return '<div class="card" data-sc-cs="display:block;border-radius:8px">'
		. '<p data-sc-cs="font-size:16px">&ldquo;' . $quote . '&rdquo;</p>'
		. '<div class="author" data-sc-cs="display:flex"><img src="/a.jpg" data-sc-cs="width:40px;height:40px">'
		. '<div><div data-sc-cs="font-weight:700">' . $who . '</div>'
		. '<div data-sc-cs="font-size:13px">Operations lead</div></div></div></div>';
};

echo "\n== The measured shape: one card carries a dash, its siblings do not\n";

// "best — Zakeke, Customily" trips the weak attribution signal; the other two cards carry nothing like it.
$mixed = $grid_of( array(
	$card( 'Customer Input', 'Personalization details, product choices, quantities, and delivery needs are captured automatically at the point of sale.' ),
	$card( 'Designer Tools', 'We work with the online designer that fits each product best — Zakeke, Customily, Aurigma, KornitX, Printess, and more.' ),
	$card( 'Artwork Generation', 'Print-ready files build themselves from the buyer\'s choices, with no designer in the loop and no manual cleanup.' ),
) );
$ok( $mixed instanceof DOMElement, 'the grid is built' );

// The REAL reason the third card matched, measured: its body reads "from the buyer’s choices", and the
// quote test treated U+2019 as a quotation mark. An apostrophe inside a word is not a quote.
$lq = new ReflectionMethod( 'FW_Site_Converter_Stitch', 'looks_quote_card' );
$lq->setAccessible( true );
$apos = $grid_of( array( $card( 'Artwork Generation',
	'Print-ready files build themselves from the buyer’s choices, with no designer in the loop and no manual cleanup.' ) ) );
$apos_card = null;
foreach ( $apos->childNodes as $c ) { if ( $c instanceof DOMElement ) { $apos_card = $c; break; } }
$ok( false === (bool) $lq->invoke( null, $apos_card ),
	'a card whose only quote-ish character is an in-word apostrophe is NOT a quote card' );
$ok( false === (bool) $is->invoke( null, $mixed ),
	'a grid whose cards are NOT all quote cards is not a testimonials grid — claiming it would discard one' );

echo "\n== NEGATIVE: a real testimonials grid is still claimed\n";

$real = $grid_of( array(
	$quote_card( 'They cut our turnaround in half and the files just arrive ready.', 'Dana Whitfield' ),
	$quote_card( 'We stopped rebuilding artwork by hand entirely.', 'Marco Reyes' ),
	$quote_card( 'Orders route themselves now, even at peak.', 'Priya Raman' ),
) );
$ok( true === (bool) $is->invoke( null, $real ), 'every card is a quote card, so nothing is lost by claiming it' );

$pair = $grid_of( array(
	$quote_card( 'The handoff to production is finally boring, which is what we wanted.', 'Sam Oduya' ),
	$quote_card( 'Fewer reprints, fewer apologies to customers.', 'Lena Fischer' ),
) );
$ok( true === (bool) $is->invoke( null, $pair ), 'a two-card testimonials grid is still claimed' );

echo "\n== NEGATIVE: a grid of plain feature cards is not testimonials either\n";

$plain = $grid_of( array(
	$card( 'Routing', 'Orders and files flow automatically to the right press, team, or fulfillment partner.' ),
	$card( 'Fulfillment', 'Production handoff and shipping coordination happen without chasing, exceptions, or rework.' ),
	$card( 'Tracking', 'Order status stays visible from input to delivery, for you and your customers.' ),
) );
$ok( false === (bool) $is->invoke( null, $plain ), 'no quotes, no authors, no ratings — not testimonials' );

echo "\n== NEGATIVE: too few cards is still not a grid\n";

$one = $grid_of( array( $quote_card( 'A single pull quote is not a grid of them.', 'Ada Okonkwo' ) ) );
$ok( false === (bool) $is->invoke( null, $one ), 'one card is not a testimonials grid' );

printf( "\n%s\n", $fails ? "{$fails} FAIL(S)" : 'ALL PASS' );
exit( $fails ? 1 : 0 );
