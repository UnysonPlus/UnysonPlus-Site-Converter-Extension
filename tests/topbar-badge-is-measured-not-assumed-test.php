<?php
/**
 * Regression guard: the top-bar BADGE keeps the type the source gave it.
 *
 * `topbar_cell_html()` builds the pill in the top bar as inline-styled markup for a `custom_html` element.
 * It read the badge's background and ink from the capture and then wrote everything else as a constant:
 *
 *     padding:2px 12px; border-radius:4px; font-size:11px; font-weight:600
 *
 * Those are a guess that happens to look close. Measured on a captured header the badge is 10px on a 16px
 * line box, so the converted chip rendered a pixel larger on a line box it inherited from the body (17.93px)
 * — and no amount of looking at the page would tell you the size was invented rather than read.
 *
 * The chip path elsewhere in this file emits a native `list_item` with its skin as scoped CSS keyed by the
 * element class; this top-bar path is the one that actually renders on a two-row masthead, and it was the one
 * guessing. The constants stay as the fallback for a badge whose stamp says nothing.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/topbar-badge-is-measured-not-assumed-test.php"
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

$fn = new ReflectionMethod( 'FW_Site_Converter_Stitch', 'topbar_cell_html' );
$fn->setAccessible( true );

$cell = function ( $badge_cs ) {
	$dom = new DOMDocument();
	libxml_use_internal_errors( true );
	$dom->loadHTML( '<!DOCTYPE html><html><body><div id="cell" data-sc-cs="display:flex;align-items:center">'
		. '<span data-sc-cs="' . $badge_cs . '">State Bar Certified Personal Injury Specialists</span>'
		. '</div></body></html>' );
	libxml_clear_errors();
	return $dom->getElementById( 'cell' );
};

$MEASURED = 'background-color:rgb(12, 31, 64);color:rgb(255, 255, 255);font-size:10px;font-weight:600;'
	. 'line-height:16px;padding:2px 12px;border-radius:4px';

echo "\n== The measured shape: a 10px badge on a 16px line box\n";

$html = (string) $fn->invoke( null, $cell( $MEASURED ), '#ffffff' );
$ok( '' !== trim( $html ), 'the badge markup is produced at all' );
$ok( false !== strpos( $html, 'State Bar Certified' ), 'it carries the badge text' );
$ok( false !== strpos( $html, 'font-size:10px' ), 'the measured size, not the assumed 11px: ' . mb_substr( $html, 0, 170 ) );
$ok( false === strpos( $html, 'font-size:11px' ), '…and the assumed 11px is gone' );
$ok( false !== strpos( $html, 'line-height:16px' ), 'and the measured line box, which was never carried at all' );
$ok( false !== strpos( $html, 'rgb(12, 31, 64)' ), 'its measured fill still rides along' );

echo "\n== NEGATIVE: an unstamped badge keeps the documented fallback\n";

$bare = (string) $fn->invoke( null, $cell( 'background-color:rgb(12, 31, 64);color:rgb(255, 255, 255)' ), '#ffffff' );
$ok( '' !== trim( $bare ), 'markup is still produced' );
$ok( false !== strpos( $bare, 'font-size:11px' ), 'the fallback size stands when the source states none' );
$ok( false === strpos( $bare, 'line-height' ), '…and no line box is invented' );

echo "\n== NEGATIVE: a cell with no badge is unaffected\n";

$dom = new DOMDocument();
libxml_use_internal_errors( true );
$dom->loadHTML( '<!DOCTYPE html><html><body><div id="cell" data-sc-cs="display:flex"><span>127 reviews</span></div></body></html>' );
libxml_clear_errors();
$plain = (string) $fn->invoke( null, $dom->getElementById( 'cell' ), '#ffffff' );
$ok( false === strpos( $plain, 'border-radius' ), 'plain text is not turned into a pill' );
$ok( false !== strpos( $plain, '127 reviews' ), '…and its text survives' );

printf( "\n%s\n", $fails ? "{$fails} FAIL(S)" : 'ALL PASS' );
exit( $fails ? 1 : 0 );
