<?php
/**
 * Regression guard: the footer's own COLUMN GRID is never lifted out as a CTA band.
 *
 * `footer_split_cta()` lifts a leading FILLED band out of the footer so it can become its own Pre-Footer bar
 * with its own fill — written for a coloured call-to-action strip ("Craving a Juicy Burger? … BOOK A TABLE")
 * that lives inside `<footer>` above the link grid. It qualified a band on two tests: an opaque fill differing
 * from the footer's own, and a lead heading somewhere inside it.
 *
 * Neither test excludes the footer's MAIN GRID. Measured on a captured footer whose grid is
 * `bg-cream py-20` holding three columns — a display headline, a titled OFFICE column (address / phone /
 * email) and a titled MENU column (four links) — the whole 16,948-byte grid qualified: the band is filled
 * (#f7f5ff) where `<footer>` itself is transparent, and the headline reads as a lead heading. It was lifted,
 * so `detect_footer_columns()` ran on a footer with nothing left in it:
 *
 *     detect_footer_columns( full html )   2 groups   (Office, Menu)
 *     detect_footer_columns( route html )  0 groups
 *
 * With no columns and no flat links the brand-only branch ran, and the footer shipped as a logo beside the
 * disclaimer — headline, address, phone, email and all four menu links gone.
 *
 * So a band that IS a column grid is not a CTA, however it is filled. A real CTA lockup is a heading and a
 * button; it yields no column groups, and it is still lifted.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/footer-grid-is-not-a-cta-test.php"
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

$split = new ReflectionMethod( 'FW_Site_Converter_Stitch', 'footer_split_cta' );
$split->setAccessible( true );
$cols = new ReflectionMethod( 'FW_Site_Converter_Stitch', 'detect_footer_columns' );
$cols->setAccessible( true );

$page = function ( $footer_inner ) {
	return '<!DOCTYPE html><html><body><main><section data-sc-cs="background-color:rgb(255, 255, 255)">'
		. '<h1>Home</h1></section></main><footer>' . $footer_inner . '</footer></body></html>';
};

/* The measured shape: a filled band that IS the footer's three-column grid. */
$grid_band =
	'<div data-sc-cs="background-color:rgb(247, 245, 255);padding-top:80px;padding-bottom:80px">'
	. '<div data-sc-cs="display:grid;grid-template-columns:400px 400px 400px;gap:48px">'
		. '<div><h2 data-sc-cs="font-size:36px;font-weight:700">Tell us your story</h2>'
			. '<p data-sc-cs="font-size:24px">Get in touch</p></div>'
		. '<div><h3 data-sc-cs="font-size:12px;text-transform:uppercase">Office</h3>'
			. '<div><span>1250 Market Street, Suite 400</span>'
			. '<a href="tel:4157892200">415-789-2200</a>'
			. '<a href="mailto:hello@example-domain.test">hello@example-domain.test</a></div></div>'
		. '<div><h3 data-sc-cs="font-size:12px;text-transform:uppercase">Menu</h3>'
			. '<div data-sc-cs="display:grid;grid-template-columns:140px 140px">'
			. '<a href="/about-us">About Us</a><a href="/contact">Contact</a>'
			. '<a href="/costs">Costs &amp; Fees</a><a href="/faq">FAQ</a></div></div>'
	. '</div></div>';

$bottom_bar =
	'<div data-sc-cs="background-color:rgb(247, 245, 255);padding-top:24px;padding-bottom:24px">'
	. '<div data-sc-cs="display:flex;justify-content:space-between">'
	. '<p>&copy; 2024 Example Holdings. All rights reserved.</p><p>Website by a studio</p></div></div>';

$html = $page( $grid_band . $bottom_bar );

echo "\n== The footer's own column grid is not a CTA\n";

$before = count( (array) $cols->invoke( null, $html ) );
$ok( $before >= 1, "the grid yields {$before} column group(s) before any split" );

$res = $split->invoke( null, $html );
$ok( null === $res, 'footer_split_cta() leaves it in place' );

if ( is_array( $res ) ) {
	$after = count( (array) $cols->invoke( null, (string) $res['rest'] ) );
	$ok( false, "…it was lifted, and column detection then finds {$after} group(s)" );
} else {
	$after = count( (array) $cols->invoke( null, $html ) );
	$ok( $after === $before, "column detection still finds {$after} group(s) — nothing was lost" );
}

echo "\n== NEGATIVE: a genuine CTA lockup is still lifted\n";

$cta_band =
	'<div data-sc-cs="background-color:rgb(198, 40, 40);padding-top:64px;padding-bottom:64px">'
	. '<div data-sc-cs="display:flex;flex-direction:column;align-items:center">'
	. '<h2 data-sc-cs="font-size:40px;font-weight:700;color:rgb(255, 255, 255)">Ready to get started?</h2>'
	. '<a href="/book" data-sc-cs="background-color:rgb(255, 255, 255);padding-top:14px;padding-bottom:14px">Book a table</a>'
	. '</div></div>';

$link_grid =
	'<div data-sc-cs="background-color:rgb(255, 255, 255);padding-top:64px;padding-bottom:64px">'
	. '<div data-sc-cs="display:grid;grid-template-columns:300px 300px">'
		. '<div><h3 data-sc-cs="font-size:12px">Company</h3><div>'
			. '<a href="/about">About</a><a href="/careers">Careers</a></div></div>'
		. '<div><h3 data-sc-cs="font-size:12px">Support</h3><div>'
			. '<a href="/help">Help</a><a href="/status">Status</a></div></div>'
	. '</div></div>';

$cta_html = $page( $cta_band . $link_grid );
$cta_res  = $split->invoke( null, $cta_html );
$ok( is_array( $cta_res ), 'the coloured heading+button strip is still recognised as a CTA' );
if ( is_array( $cta_res ) ) {
	$lifted = trim( preg_replace( '/\s+/', ' ', strip_tags( (string) $cta_res['cta'] ) ) );
	$ok( false !== strpos( $lifted, 'Ready to get started?' ), 'the lifted band is the CTA: "' . mb_substr( $lifted, 0, 60 ) . '"' );
	$ok( false === strpos( $lifted, 'Careers' ), '…and the link grid was not dragged out with it' );
	$rest_n = count( (array) $cols->invoke( null, (string) $cta_res['rest'] ) );
	$ok( $rest_n >= 2, "the link grid survives the split: {$rest_n} column group(s)" );
}

printf( "\n%s\n", $fails ? "{$fails} FAIL(S)" : 'ALL PASS' );
exit( $fails ? 1 : 0 );
