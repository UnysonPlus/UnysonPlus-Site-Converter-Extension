<?php
/**
 * Regression guard: the header WORDMARK keeps the line box the source gave it.
 *
 * The brand lockup's measured type is split between native options (Site Title Size, Site Title Font Family)
 * and a scoped `.site-title-text` rule that carries what has no field of its own — tracking and case. The
 * line box had neither: no option, and no place in that rule, so the wordmark took the theme's ratio.
 *
 * Measured on a captured header: the wordmark sits on a 32px line box and rendered at 24px. It shows up twice
 * in a property diff, because the lockup is two-tone ("Apex Injury Advocates" with an accent span), and the
 * masthead measures a little shorter than the source on every page.
 *
 * `normal` states nothing and is skipped, as everywhere else.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/wordmark-keeps-its-line-height-test.php"
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

$dl = new ReflectionMethod( 'FW_Site_Converter_Stitch', 'detect_logo' );
$dl->setAccessible( true );

$page = function ( $wm_cs ) {
	return '<!DOCTYPE html><html><head><title>Apex Injury Advocates</title></head><body>'
		. '<header data-sc-cs="background-color:rgb(255, 255, 255);height:96px">'
		. '<div data-sc-cs="display:flex;align-items:center;justify-content:space-between">'
		. '<a href="/" data-sc-cs="' . $wm_cs . '">Apex <span data-sc-cs="color:rgb(130, 121, 230)">Injury</span> Advocates</a>'
		. '<nav data-sc-cs="display:flex;gap:32px">'
		. '<a href="/about-us" data-sc-cs="font-size:16px;line-height:24px">About Us</a>'
		. '<a href="/costs" data-sc-cs="font-size:16px;line-height:24px">Costs</a>'
		. '</nav></div></header>'
		. '<main><section data-sc-cs="background-color:rgb(255,255,255);padding:80px 0px"><h1 data-sc-cs="font-size:48px">Hello</h1></section></main>'
		. '</body></html>';
};

echo "\n== The measured shape: a 24px wordmark on a 32px line box\n";

$logo = (array) $dl->invoke( null, $page(
	'color:rgb(12, 31, 64);font-family:Inter, system-ui, sans-serif;font-size:24px;font-weight:700;line-height:32px;letter-spacing:-0.5px'
), 'Apex Injury Advocates' );

$ok( ! empty( $logo ), 'the lockup is detected at all' );
$ok( ! empty( $logo['title_lh'] ), 'its line box is recorded (' . ( $logo['title_lh'] ?? '(none)' ) . ')' );
$ok( false !== strpos( (string) ( $logo['title_lh'] ?? '' ), '32' ), 'and it is the measured 32' );
$ok( '' !== trim( (string) ( $logo['title_size'] ?? '' ) ), 'its siblings are still read (size)' );

echo "\n== …and it reaches the page as a scoped rule\n";

$chrome = FW_Site_Converter_Stitch::tokens_to_theme_settings_chrome( array(), $page(
	'color:rgb(12, 31, 64);font-family:Inter, system-ui, sans-serif;font-size:24px;font-weight:700;line-height:32px;letter-spacing:-0.5px'
), 'Apex Injury Advocates' );
$blob = json_encode( $chrome['values'] ?? array() );
$ok( (bool) preg_match( '/site-title-text\{[^}]*line-height:32px/', (string) $blob ), 'the wordmark rule carries the measured line box' );

echo "\n== NEGATIVE: an unstamped wordmark invents nothing\n";

$bare = (array) $dl->invoke( null, $page( 'color:rgb(12, 31, 64);font-size:24px;font-weight:700' ), 'Apex Injury Advocates' );
$ok( empty( $bare['title_lh'] ), 'no line box is recorded when the source states none' );

$chrome2 = FW_Site_Converter_Stitch::tokens_to_theme_settings_chrome( array(), $page( 'color:rgb(12, 31, 64);font-size:24px;font-weight:700' ), 'Apex' );
$blob2   = json_encode( $chrome2['values'] ?? array() );
$ok( ! preg_match( '/site-title-text\{[^}]*line-height/', (string) $blob2 ), '…and none is emitted' );

echo "\n== NEGATIVE: a `normal` line-height is not a measurement\n";

$norm = (array) $dl->invoke( null, $page( 'color:rgb(12, 31, 64);font-size:24px;line-height:normal' ), 'Apex Injury Advocates' );
$ok( empty( $norm['title_lh'] ), '`normal` is not recorded as a chosen line box' );

printf( "\n%s\n", $fails ? "{$fails} FAIL(S)" : 'ALL PASS' );
exit( $fails ? 1 : 0 );
