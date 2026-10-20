<?php
/**
 * Regression guard: the footer's small print keeps the TYPE the source gave it.
 *
 * `detect_footer_disclaimer()` finds the legal paragraph under the © row and returns it as
 * `<p class="footer-disclaimer">…</p>` — the text, and nothing about how it looks. The paragraph then takes
 * the theme's footer body type, which is the wrong size and the wrong alignment.
 *
 * Measured on a captured footer, the source stamps
 * `font-size:10px; line-height:15px; color:rgba(12,31,64,0.25); text-align:center` on a line it centres under
 * the whole bar. The conversion rendered it at 12px, flush left — visibly a different line in a different
 * place, and the only element in the copyright bar whose type nothing carried.
 *
 * The measured declarations ride as a SCOPED RULE on `.footer-disclaimer`, a class this converter emits
 * itself. Not an inline style: WordPress's safecss_filter_attr() drops every functional colour notation —
 * `color:rgba(…)` and even `color:rgb(…)` are stripped, only hex survives — so an inline rule silently lost
 * the ink and kept the rest.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/footer-disclaimer-keeps-its-type-test.php"
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
$css_fn = new ReflectionMethod( 'FW_Site_Converter_Stitch', 'footer_disclaimer_css' );
$css_fn->setAccessible( true );
// the markup is returned; the TYPE is accumulated and flushed separately
$type_of = function ( $html ) use ( $fn, $css_fn ) { $fn->invoke( null, $html ); return (string) $css_fn->invoke( null ); };

$NOTICE = 'The information on this website is for general information purposes only. Nothing on this site should be taken as legal advice.';

$page = function ( $p_attrs ) use ( $NOTICE ) {
	return '<!DOCTYPE html><html><body><main><section data-sc-cs="background-color:rgb(255,255,255)"><h1>Home</h1></section></main>'
		. '<footer><div data-sc-cs="background-color:rgb(247,245,255);padding-top:80px">'
		. '<div data-sc-cs="display:grid;grid-template-columns:400px 400px">'
		. '<div><h2 data-sc-cs="font-size:36px">Tell us your story</h2></div>'
		. '<div><h3 data-sc-cs="font-size:12px">Menu</h3><div><a href="/a">About</a><a href="/f">FAQ</a></div></div>'
		. '</div></div>'
		. '<div data-sc-cs="background-color:rgb(247,245,255);border-top-width:1px;padding-top:24px">'
		. '<div data-sc-cs="display:flex;justify-content:space-between"><p>&copy; 2024 Example Holdings. All rights reserved.</p><p>Website by a studio</p></div>'
		. '<p ' . $p_attrs . '>' . $NOTICE . '</p>'
		. '</div></footer></body></html>';
};

echo "\n== The measured shape: 10px, centred, quarter-opacity ink\n";

$page_html = $page(
	'class="text-[10px] mt-4 text-center" data-sc-cs="color:rgba(12, 31, 64, 0.25);font-size:10px;font-weight:400;line-height:15px;text-align:center;margin:16px 0px 0px"'
);
$got = (string) $fn->invoke( null, $page_html );
$type = (string) $css_fn->invoke( null );

$ok( '' !== trim( $got ), 'a disclaimer is found at all' );
$ok( false !== strpos( $got, 'general information purposes' ), 'it is the small print' );
$ok( '' !== trim( $type ), 'its measured type is emitted as a scoped rule: ' . mb_substr( trim( $type ), 0, 150 ) );
$ok( false !== strpos( $type, 'font-size:10px' ), '…the measured size, not the theme default' );
$ok( false !== strpos( $type, 'text-align:center' ), '…and the centring the source gave it' );
$ok( false !== strpos( $type, 'line-height:15px' ), '…and its line box' );
$ok( false !== stripos( $type, 'rgba(12, 31, 64, 0.25)' ), '…and its ink, alpha intact' );
// The bar emits `.footer-section--copyright .builder-text-element{color:<bar ink> !important}` at (0,2,0),
// which outranks a plain `.footer-disclaimer` (0,1,0) — CDP named it as the winner and the small print
// rendered in the bar's ink. So the ink goes out at a specificity that clears it.
$ok( false !== strpos( $type, '.footer-disclaimer.footer-disclaimer' ),
	'…at a specificity that clears the copyright bar own !important ink rule' );

echo "\n== NEGATIVE: an unstamped paragraph gets no invented style\n";

$bare = (string) $fn->invoke( null, $page( 'class="text-xs"' ) );
$bare_type = (string) $css_fn->invoke( null );
$ok( '' !== trim( $bare ), 'it is still found without a stamp' );
$ok( '' === trim( $bare_type ), 'but no type is invented for it' );

echo "\n== NEGATIVE: a default-ish alignment is not emitted as a choice\n";

$fn->invoke( null, $page( 'class="text-xs" data-sc-cs="font-size:12px;text-align:start"' ) );
$left = (string) $css_fn->invoke( null );
$ok( false === strpos( $left, 'text-align' ), '`start` says nothing, so it is not carried' );
$ok( false !== strpos( $left, 'font-size:12px' ), '…while the size it did state is' );

printf( "\n%s\n", $fails ? "{$fails} FAIL(S)" : 'ALL PASS' );
exit( $fails ? 1 : 0 );
