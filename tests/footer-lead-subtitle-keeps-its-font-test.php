<?php
/**
 * Regression guard: the footer lockup's SUBTITLE keeps the font family the source gave it.
 *
 * `footer_lead_css()` scopes the lockup's measured type onto `.footer-lead-title`, `.footer-lead-eyebrow`
 * and `.footer-lead-subtitle`. The title and the eyebrow each prepend the measured `font-family` explicitly,
 * because `significant_text_decls()` deliberately leaves family out. The subtitle did not — the one line of
 * the three that was never given it.
 *
 * Measured on a captured footer: the lockup is a serif display heading over a serif italic subtitle
 * ("Get in touch"). The subtitle rendered in the body sans, because nothing carried its family and the
 * theme's default won.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/footer-lead-subtitle-keeps-its-font-test.php"
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

$fn = new ReflectionMethod( 'FW_Site_Converter_Stitch', 'footer_lead_css' );
$fn->setAccessible( true );

$SERIF = 'font-family:&quot;Playfair Display&quot;, Georgia, serif';

$html = '<!DOCTYPE html><html><body><main><section data-sc-cs="background-color:rgb(255,255,255)"><h1>Home</h1></section></main>'
	. '<footer><div data-sc-cs="background-color:rgb(247,245,255);padding-top:80px">'
	. '<div data-sc-cs="display:grid;grid-template-columns:400px 400px">'
	. '<div><h2 data-sc-cs="color:rgb(12,31,64);' . $SERIF . ';font-size:36px;font-weight:700">Tell us your story</h2>'
	. '<p data-sc-cs="color:rgb(139,92,246);' . $SERIF . ';font-size:24px;font-weight:600;font-style:italic">Get in touch</p></div>'
	. '<div><h3 data-sc-cs="font-size:12px">Menu</h3><div><a href="/about-us">About Us</a><a href="/faq">FAQ</a></div></div>'
	. '</div></div></footer></body></html>';

$css = (string) $fn->invoke( null, $html );

echo "\n== The measured shape: a serif lockup over a serif italic subtitle\n";

$ok( '' !== trim( $css ), 'lockup CSS is produced at all' );
$ok( false !== strpos( $css, '.footer-lead-subtitle{' ), 'the subtitle gets its own rule' );

$i = strpos( $css, '.footer-lead-subtitle{' );
$sub_rule = false !== $i ? substr( $css, $i, strpos( $css, '}', $i ) - $i ) : '';
$ok( false !== stripos( $sub_rule, 'font-family' ), 'it carries a font-family: ' . mb_substr( $sub_rule, 0, 130 ) );
$ok( false !== stripos( $sub_rule, 'Playfair' ), '…the measured serif, not the theme default' );

echo "\n== The title still carries its own (unchanged behaviour)\n";

$j = strpos( $css, '.footer-lead-title{' );
$title_rule = false !== $j ? substr( $css, $j, strpos( $css, '}', $j ) - $j ) : '';
$ok( false !== stripos( $title_rule, 'Playfair' ), 'the title keeps its measured family' );

echo "\n== NEGATIVE: a subtitle with no measured family gets none\n";

$plain = str_replace( 'color:rgb(139,92,246);' . $SERIF . ';font-size:24px', 'color:rgb(139,92,246);font-size:24px', $html );
$css2  = (string) $fn->invoke( null, $plain );
$k     = strpos( $css2, '.footer-lead-subtitle{' );
$sub2  = false !== $k ? substr( $css2, $k, strpos( $css2, '}', $k ) - $k ) : '';
$ok( false === stripos( $sub2, 'font-family' ), 'no family is invented when the source states none' );

printf( "\n%s\n", $fails ? "{$fails} FAIL(S)" : 'ALL PASS' );
exit( $fails ? 1 : 0 );
