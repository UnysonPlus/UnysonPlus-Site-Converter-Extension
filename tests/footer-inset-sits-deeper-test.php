<?php
/**
 * Regression guard: a footer's vertical inset can sit below the <footer> element.
 *
 * The footer padding reader matched `<footer ... data-sc-cs="…padding: …">` and took the shorthand off that
 * one element. A hand-written footer pads itself, so that worked.
 *
 * Measured on a captured footer, it does not. The `<footer>` pads 0 on all four sides, and so do the two
 * wrappers inside it; the real inset — `padding: 60px 0px 30px` — sits THREE levels down:
 *
 *     footer                         0px 0px 0px 0px
 *       div                          0px 0px 0px 0px
 *         div.elementor              0px 0px 0px 0px
 *           div.elementor-element    60px 0px 30px 0px   <- the band
 *
 * So nothing was written, the footer fell back to the theme's default, and it rendered 32px/24px against the
 * source's 60px/30px — the whole footer sitting visibly tighter than the source for want of one lookup.
 *
 * The rule is "the shallowest stamped vertical inset inside the footer wins". Shallowest matters: a footer
 * also contains padded things that are NOT its band — a link row at `0px 0px 9px`, a card, a button. Taking
 * the first in document order means the band is found before any of them, and the depth limit stops the walk
 * from wandering into content.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/footer-inset-sits-deeper-test.php"
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

$fn = new ReflectionMethod( 'FW_Site_Converter_Stitch', 'footer_inset_px' );
$fn->setAccessible( true );

$page = function ( $footer_inner, $footer_cs = 'display:block' ) {
	return '<!DOCTYPE html><html><body><main data-sc-cs="padding:120px 0px 90px"><p>page body</p></main>'
		. '<footer data-sc-cs="' . $footer_cs . '">' . $footer_inner . '</footer></body></html>';
};

echo "\n== The measured shape: the inset is three wrappers down\n";

$deep = $page(
	'<div data-sc-cs="display:block">'
	. '<div class="shell" data-sc-cs="display:block">'
	. '<div class="band" data-sc-cs="display:block;padding:60px 0px 30px">'
	. '<a href="/x" data-sc-cs="display:block;padding:0px 0px 9px">How it works</a>'
	. '</div></div></div>' );
$got = (array) $fn->invoke( null, $deep );
$ok( ! empty( $got ), 'an inset is found at all (was: nothing)' );
$ok( 60.0 === (float) ( $got['top'] ?? 0 ), 'the 60px top is read: ' . var_export( $got['top'] ?? null, true ) );
$ok( 30.0 === (float) ( $got['bottom'] ?? 0 ), 'the 30px bottom is read: ' . var_export( $got['bottom'] ?? null, true ) );

echo "\n== The SHALLOWEST inset wins, so a padded link row never stands in for the band\n";

$ok( 9.0 !== (float) ( $got['bottom'] ?? 0 ), 'the link row\'s 9px did not win' );

echo "\n== NEGATIVE: a footer that pads ITSELF is read exactly as before\n";

$own = $page( '<div class="band" data-sc-cs="display:block;padding:10px 0px 10px">x</div>',
	'display:block;padding:72px 0px 40px' );
$o2  = (array) $fn->invoke( null, $own );
$ok( 72.0 === (float) ( $o2['top'] ?? 0 ) && 40.0 === (float) ( $o2['bottom'] ?? 0 ),
	'the footer\'s own 72/40 is used, not the inner 10/10' );

echo "\n== NEGATIVE: no inset anywhere means nothing is written\n";

$bare = $page( '<div data-sc-cs="display:block"><p data-sc-cs="font-size:14px">All rights reserved.</p></div>' );
$ok( array() === (array) $fn->invoke( null, $bare ), 'a footer with no padding yields nothing' );

echo "\n== NEGATIVE: the page body's inset is not mistaken for the footer's\n";

// <main> above carries 120px/90px. Reading it would pad the footer with the page's rhythm.
$ok( 120.0 !== (float) ( $got['top'] ?? 0 ), 'the page body\'s 120px top is not adopted' );
$ok( array() === (array) $fn->invoke( null, '<!DOCTYPE html><html><body><main data-sc-cs="padding:120px 0px 90px">x</main></body></html>' ),
	'a document with no footer at all yields nothing' );

echo "\n== A one-sided inset is carried as given\n";

$one = $page( '<div class="band" data-sc-cs="display:block;padding:48px 0px 0px">x</div>' );
$o3  = (array) $fn->invoke( null, $one );
$ok( 48.0 === (float) ( $o3['top'] ?? 0 ), 'the 48px top is read' );
$ok( 0.0 === (float) ( $o3['bottom'] ?? -1 ), 'and the untouched bottom reads 0, not absent' );

printf( "\n%s\n", $fails ? "{$fails} FAIL(S)" : 'ALL PASS' );
exit( $fails ? 1 : 0 );
