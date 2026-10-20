<?php
/**
 * Regression guard: the small print under the © row survives the conversion.
 *
 * `detect_footer_disclaimer()` finds the legal paragraph that sits below the copyright row and appends it
 * under the © text, so the notice is not dropped. Two things stopped it finding a real one.
 *
 * 1. The class test read `strpos( $cls, 'text-\[' )` — a REGEX escape inside a plain string search, so it
 *    looked for the five literal characters `text-\[` and could never match Tailwind's arbitrary-value
 *    sizes. A paragraph classed `text-[10px]` was invisible to it; only the fixed `text-xs` ever matched.
 *
 * 2. The paragraph is also what `detect_footer_tagline()` returns (it is the smallest prose in the footer),
 *    and a guard drops any paragraph equal to the tagline so a brand blurb cannot double into the © bar.
 *    On a footer whose brand column is a display HEADLINE rather than a blurb, that guard threw away the
 *    only disclaimer there was.
 *
 * Measured on a captured footer: `<p class="text-[10px] … mt-4 text-center">The information on this website
 * is for general information purposes only…</p>` sits inside the copyright band, under the © row. It
 * returned '' and the converted footer shipped with no small print at all.
 *
 * So: the class test matches an arbitrary-value size, and a paragraph that lives INSIDE THE COPYRIGHT BAND
 * is a disclaimer whatever the tagline reader thinks — a brand blurb lives in the grid above, not there.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/footer-disclaimer-survives-test.php"
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

$fn = new ReflectionMethod( 'FW_Site_Converter_Stitch', 'detect_footer_disclaimer' );
$fn->setAccessible( true );

$NOTICE = 'The information on this website is for general information purposes only. Nothing on this site should be taken as legal advice.';

$page = function ( $footer_inner ) {
	return '<!DOCTYPE html><html><body><main><section data-sc-cs="background-color:rgb(255, 255, 255)">'
		. '<h1>Home</h1></section></main><footer>' . $footer_inner . '</footer></body></html>';
};

$grid_band =
	'<div data-sc-cs="background-color:rgb(247, 245, 255);padding-top:80px;padding-bottom:80px">'
	. '<div data-sc-cs="display:grid;grid-template-columns:400px 400px">'
		. '<div><h2 data-sc-cs="font-size:36px;font-weight:700">Tell us your story</h2></div>'
		. '<div><h3 data-sc-cs="font-size:12px">Menu</h3><div>'
			. '<a href="/about-us">About Us</a><a href="/faq">FAQ</a></div></div>'
	. '</div></div>';

echo "\n== An arbitrary-value small paragraph in the copyright band is the disclaimer\n";

$copy_band =
	'<div data-sc-cs="background-color:rgb(247, 245, 255);border-top-width:1px;padding-top:24px;padding-bottom:24px">'
	. '<div data-sc-cs="display:flex;justify-content:space-between">'
		. '<p>&copy; 2024 Example Holdings. All rights reserved.</p><p>Website by a studio</p></div>'
	. '<p class="text-[10px] mt-4 text-center" data-sc-cs="font-size:10px;text-align:center">' . $NOTICE . '</p>'
	. '</div>';

$got = (string) $fn->invoke( null, $page( $grid_band . $copy_band ) );
$ok( '' !== trim( $got ), 'a disclaimer is found at all' );
$ok( false !== strpos( $got, 'general information purposes' ), 'it is the small print: "' . mb_substr( trim( strip_tags( $got ) ), 0, 56 ) . '"' );
$ok( false === strpos( $got, 'All rights reserved' ), '…and not the © line itself' );

echo "\n== …and the fixed `text-xs` size still works (unchanged behaviour)\n";

$xs_band = str_replace( 'class="text-[10px] mt-4 text-center"', 'class="text-xs mt-4 text-center"', $copy_band );
$got_xs  = (string) $fn->invoke( null, $page( $grid_band . $xs_band ) );
$ok( false !== strpos( $got_xs, 'general information purposes' ), 'a text-xs disclaimer is still found' );

echo "\n== NEGATIVE: a brand blurb in the GRID is not a disclaimer\n";

// The blurb sits in the brand column of the grid above, which is where detect_footer_tagline takes it from.
// It must not double into the copyright bar.
$blurb_grid = str_replace(
	'<h2 data-sc-cs="font-size:36px;font-weight:700">Tell us your story</h2>',
	'<h2 data-sc-cs="font-size:36px;font-weight:700">Tell us your story</h2>'
		. '<p class="text-xs" data-sc-cs="font-size:12px">We are a small studio building careful software for people who read the small print closely.</p>',
	$grid_band
);
$plain_copy = '<div data-sc-cs="background-color:rgb(247, 245, 255);padding-top:24px;padding-bottom:24px">'
	. '<p>&copy; 2024 Example Holdings. All rights reserved.</p></div>';

$got_blurb = (string) $fn->invoke( null, $page( $blurb_grid . $plain_copy ) );
$ok( false === strpos( $got_blurb, 'careful software' ), 'the brand blurb is not returned as the disclaimer' );

echo "\n== NEGATIVE: a footer with no small print yields nothing\n";

$none = (string) $fn->invoke( null, $page( $grid_band . $plain_copy ) );
$ok( '' === trim( $none ), 'no disclaimer is invented' );

printf( "\n%s\n", $fails ? "{$fails} FAIL(S)" : 'ALL PASS' );
exit( $fails ? 1 : 0 );
