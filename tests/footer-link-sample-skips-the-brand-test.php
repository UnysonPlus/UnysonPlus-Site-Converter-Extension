<?php
/**
 * Regression guard: the footer link style is sampled from a NAV LINK, not the brand mark.
 *
 * WHY THIS FILE EXISTS. Measured on a real conversion, every footer link came out 16px/24px against the
 * source's 14px/20px — the single largest remaining type delta on the page, and visible as a footer whose
 * columns stand noticeably taller than the source's.
 *
 * `footer_link_css()` samples ONE anchor and emits its type for `.footer-column .footer-link`. It took the
 * first anchor in the footer, which was the BRAND link:
 *
 *   a[0]  href="/"          svg=1  descendants=9  text "CleanSpaceSERVICES"   16px / 24px
 *   a[1]  href="/services"  svg=0  descendants=0  text "Residential Cleaning" 14px / 20px
 *
 * The existing guards did not catch it. `str_word_count( $t ) <= 4` passed because `self::text()`
 * concatenates without separators, so the logo wordmark and the next column's heading ran together into a
 * single token — the same no-separator behaviour that has now produced four separate defects in this file's
 * neighbours. And `social_lucide( $href )` is empty for "/", so the social-icon guard passed too.
 *
 * THE RULE: a nav label's anchor is a TEXT LEAF. A link wrapping a logo — an `<svg>` or an `<img>` — is a
 * brand mark, and its type says nothing about the link columns.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/footer-link-sample-skips-the-brand-test.php"
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

$m = new ReflectionMethod( 'FW_Site_Converter_Stitch', 'footer_link_css' );
$m->setAccessible( true );

// The measured shape: a brand link carrying an inline logo at 16/24, then real nav links at 14/20.
$BRAND_FIRST = '<!DOCTYPE html><html><body><main><section><p>Body</p></section></main>'
	. '<footer data-sc-cs="background-color:rgb(3,20,43);padding:64px 0px;display:block">'
	. '<div data-sc-cs="display:grid;grid-template-columns:384px 384px;gap:32px">'
	. '<div><a href="/" data-sc-cs="color:rgb(255,255,255);font-size:16px;line-height:24px;font-weight:700">'
		. '<svg width="24" height="24"><circle cx="12" cy="12" r="10"></circle></svg>'
		. '<span data-sc-cs="font-size:16px;line-height:24px">Example</span>'
		. '<span data-sc-cs="font-size:10px;line-height:15px">SERVICES</span></a></div>'
	. '<div><ul data-sc-cs="display:block">'
		. '<li><a href="/services" data-sc-cs="color:rgb(148,163,184);font-size:14px;line-height:20px;font-weight:400">Residential Cleaning</a></li>'
		. '<li><a href="/services" data-sc-cs="color:rgb(148,163,184);font-size:14px;line-height:20px;font-weight:400">Commercial Cleaning</a></li>'
		. '<li><a href="/services" data-sc-cs="color:rgb(148,163,184);font-size:14px;line-height:20px;font-weight:400">Deep Sanitization</a></li>'
	. '</ul></div></div></footer></body></html>';

$css = (string) $m->invoke( null, $BRAND_FIRST );

echo "\n== The sampled link is a nav link\n";

$ok( '' !== trim( $css ), 'a footer-link rule is emitted at all' );
$ok( false !== strpos( $css, 'font-size:14px' ),
	'the size is the nav link\'s 14px, not the brand\'s 16px (' .
	( preg_match( '/font-size:\s*([0-9.]+px)/', $css, $fm ) ? $fm[1] : 'none' ) . ')' );
$ok( false === strpos( $css, 'font-size:16px' ), 'the brand\'s size is not emitted' );
$ok( false !== strpos( $css, 'line-height:20px' ),
	'and the leading is the nav link\'s 20px (' .
	( preg_match( '/line-height:\s*([0-9.]+px)/', $css, $lm ) ? $lm[1] : 'none' ) . ')' );

echo "\n== NEGATIVE: a footer with ONLY a brand link emits nothing invented\n";

// With no nav link to sample there is nothing to say; the theme's own defaults are a better answer than the
// brand mark's type pushed onto every column.
$BRAND_ONLY = '<!DOCTYPE html><html><body><main><section><p>Body</p></section></main>'
	. '<footer data-sc-cs="background-color:rgb(3,20,43);padding:64px 0px;display:block">'
	. '<div><a href="/" data-sc-cs="color:rgb(255,255,255);font-size:16px;line-height:24px">'
		. '<svg width="24" height="24"><circle cx="12" cy="12" r="10"></circle></svg>'
		. '<span data-sc-cs="font-size:16px">Example</span></a></div>'
	. '</footer></body></html>';
$css2 = (string) $m->invoke( null, $BRAND_ONLY );
$ok( false === strpos( $css2, 'font-size:16px' ), 'the brand mark\'s type is not adopted as the link style' );

echo "\n== NEGATIVE: a plain footer still gets its links sampled\n";

// The guard must not reject ordinary anchors — this is the case the function exists for.
$PLAIN = '<!DOCTYPE html><html><body><main><section><p>Body</p></section></main>'
	. '<footer data-sc-cs="background-color:rgb(3,20,43);padding:64px 0px;display:block">'
	. '<div><ul data-sc-cs="display:block">'
		. '<li><a href="/a" data-sc-cs="color:rgb(148,163,184);font-size:13px;line-height:18px;font-weight:500">About Us</a></li>'
		. '<li><a href="/b" data-sc-cs="color:rgb(148,163,184);font-size:13px;line-height:18px;font-weight:500">Our Team</a></li>'
	. '</ul></div></footer></body></html>';
$css3 = (string) $m->invoke( null, $PLAIN );
$ok( false !== strpos( $css3, 'font-size:13px' ), 'an ordinary nav link is still sampled (13px)' );
$ok( false !== strpos( $css3, 'line-height:18px' ), '…with its leading' );

printf( "\n%s\n", $fails ? "{$fails} FAIL(S)" : 'ALL PASS' );
exit( $fails ? 1 : 0 );
