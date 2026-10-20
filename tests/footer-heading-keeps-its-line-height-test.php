<?php
/**
 * Regression guard: a footer COLUMN TITLE keeps the line box the source gave it.
 *
 * `footer_heading_css()` carries the column title's measured family, case, tracking, weight, size and colour
 * onto a scoped `.footer-links-title` rule, every declaration `!important` because the generated theme sets a
 * site-wide heading family that would otherwise win. Line-height was not among them.
 *
 * Measured on a captured footer: the source titles sit on a 16px line box and rendered at 18px, because the
 * theme's own `h3 { line-height: var(--h3-line-height, revert) }` had nothing competing with it. Two property
 * deltas on every page of the site, and the column titles sit a little lower than the source everywhere.
 *
 * The same `!important` the other declarations already carry is what makes this land, so it rides the same
 * string. A title whose stamp states no line-height is untouched.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/footer-heading-keeps-its-line-height-test.php"
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

$fn = new ReflectionMethod( 'FW_Site_Converter_Stitch', 'footer_heading_css' );
$fn->setAccessible( true );

$page = function ( $title_cs ) {
	return '<!DOCTYPE html><html><body><main><section data-sc-cs="background-color:rgb(255,255,255)"><h1>Home</h1></section></main>'
		. '<footer><div data-sc-cs="background-color:rgb(247,245,255);padding-top:80px">'
		. '<div data-sc-cs="display:grid;grid-template-columns:400px 400px">'
		. '<div><h3 data-sc-cs="' . $title_cs . '">Menu</h3>'
			. '<div><a href="/about-us">About Us</a><a href="/faq">FAQ</a></div></div>'
		. '<div><h3 data-sc-cs="' . $title_cs . '">Company</h3>'
			. '<div><a href="/careers">Careers</a><a href="/press">Press</a></div></div>'
		. '</div></div></footer></body></html>';
};

echo "\n== The measured shape: a 12px title on a 16px line box\n";

$css = (string) $fn->invoke( null, $page(
	'color:rgba(12, 31, 64, 0.6);font-size:12px;font-weight:600;line-height:16px;text-transform:uppercase;letter-spacing:1.5px'
) );

$ok( '' !== trim( $css ), 'a column-title rule is produced at all' );
$ok( false !== strpos( $css, '.footer-links-title' ), 'scoped to the column title' );
$ok( false !== strpos( $css, 'line-height:16px' ), 'carrying the measured line box: ' . mb_substr( trim( $css ), 0, 150 ) );
$ok( (bool) preg_match( '/line-height:16px\s*!important/', $css ), '…with !important, so the theme\'s own h3 rule cannot outrank it' );
$ok( false !== strpos( $css, 'font-size:12px' ), 'and its siblings still ride along (size)' );
$ok( false !== strpos( $css, 'text-transform:uppercase' ), '…and case' );

echo "\n== NEGATIVE: a title with no measured line box gets none\n";

$bare = (string) $fn->invoke( null, $page( 'color:rgba(12, 31, 64, 0.6);font-size:12px;font-weight:600' ) );
$ok( '' !== trim( $bare ), 'the rule is still produced' );
$ok( false === strpos( $bare, 'line-height' ), 'but no line box is invented' );

echo "\n== NEGATIVE: a `normal` line-height is not a measurement\n";

$norm = (string) $fn->invoke( null, $page( 'font-size:12px;font-weight:600;line-height:normal' ) );
$ok( false === strpos( $norm, 'line-height' ), '`normal` is not emitted as a chosen line box' );

printf( "\n%s\n", $fails ? "{$fails} FAIL(S)" : 'ALL PASS' );
exit( $fails ? 1 : 0 );
