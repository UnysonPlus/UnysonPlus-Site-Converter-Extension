<?php
/**
 * Regression guard: footer link typography is sampled from a NAV link, not from a phone number.
 *
 * `footer_link_css()` reads one representative footer anchor and carries its measured family, weight, size,
 * tracking, alignment and line-height onto every footer link. The sample was simply the first anchor with
 * real text, at most four words, that is not a social icon.
 *
 * A contact column breaks that. Measured on a captured footer, the first qualifying anchor is
 * `<a href="tel:...  " class="font-semibold">415-789-2200</a>` — deliberately bolder than the links around
 * it — so weight 600 was carried onto the whole footer. The property diff reported it five times at once:
 *
 *     info@...        font-weight  mock 400 -> dev 600
 *     About Us        font-weight  mock 400 -> dev 600
 *     Contact         font-weight  mock 400 -> dev 600
 *     Costs & Fees    font-weight  mock 400 -> dev 600
 *     FAQ             font-weight  mock 400 -> dev 600
 *
 * A `tel:` or `mailto:` anchor is a contact LINE, not a nav link; it is emitted as its own list item with its
 * own styling, so it must not set the house style for the rest. It stays as the fallback sample, so a footer
 * whose only links are contact links is still styled from what it has.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/footer-link-style-samples-a-nav-link-test.php"
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

$fn = new ReflectionMethod( 'FW_Site_Converter_Stitch', 'footer_link_css' );
$fn->setAccessible( true );

$page = function ( $footer_inner ) {
	return '<!DOCTYPE html><html><body><main><section data-sc-cs="background-color:rgb(255,255,255)"><h1>Home</h1></section></main>'
		. '<footer>' . $footer_inner . '</footer></body></html>';
};

echo "\n== The measured shape: a bold phone number above ordinary nav links\n";

$contact_first =
	'<div data-sc-cs="background-color:rgb(247,245,255);padding-top:80px">'
	. '<div data-sc-cs="display:grid;grid-template-columns:400px 400px">'
		. '<div><h3 data-sc-cs="font-size:12px">Office</h3><div>'
			. '<a href="tel:4157892200" class="font-semibold" data-sc-cs="font-weight:600;font-size:14px;font-family:Inter, system-ui, sans-serif">415-789-2200</a>'
			. '<a href="mailto:hello@example-domain.test" data-sc-cs="font-weight:400;font-size:14px;font-family:Inter, system-ui, sans-serif">hello@example-domain.test</a>'
		. '</div></div>'
		. '<div><h3 data-sc-cs="font-size:12px">Menu</h3><div>'
			. '<a href="/about-us" data-sc-cs="font-weight:400;font-size:14px;font-family:Inter, system-ui, sans-serif">About Us</a>'
			. '<a href="/faq" data-sc-cs="font-weight:400;font-size:14px;font-family:Inter, system-ui, sans-serif">FAQ</a>'
		. '</div></div>'
	. '</div></div>';

$css = (string) $fn->invoke( null, $page( $contact_first ) );
$ok( '' !== trim( $css ), 'footer link CSS is produced at all' );
$ok( false === strpos( $css, 'font-weight:600' ), 'the phone number\'s 600 is NOT carried onto every link' );
$ok( false !== strpos( $css, 'font-weight:400' ), 'the nav link\'s measured 400 is: ' . mb_substr( trim( $css ), 0, 120 ) );

echo "\n== NEGATIVE: a footer whose ONLY links are contact links is still styled\n";

$contact_only =
	'<div data-sc-cs="background-color:rgb(247,245,255);padding-top:80px">'
	. '<div><h3 data-sc-cs="font-size:12px">Office</h3><div>'
		. '<a href="tel:4157892200" class="font-semibold" data-sc-cs="font-weight:600;font-size:14px">415-789-2200</a>'
	. '</div></div></div>';

$css2 = (string) $fn->invoke( null, $page( $contact_only ) );
$ok( '' !== trim( $css2 ), 'CSS is still produced from the contact link: ' . mb_substr( trim( $css2 ), 0, 100 ) );
$ok( false !== strpos( $css2, 'font-weight:600' ), '…carrying its own measured weight, since there is nothing else' );

echo "\n== NEGATIVE: a footer with no links at all yields nothing\n";

$no_links = '<div data-sc-cs="background-color:rgb(247,245,255)"><p>&copy; 2024 Example Holdings.</p></div>';
$ok( '' === trim( (string) $fn->invoke( null, $page( $no_links ) ) ), 'no link CSS is invented' );

printf( "\n%s\n", $fails ? "{$fails} FAIL(S)" : 'ALL PASS' );
exit( $fails ? 1 : 0 );
