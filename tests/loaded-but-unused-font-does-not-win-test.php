<?php
/**
 * Regression guard: a font the source LOADS but never RENDERS IN must not become the site's font.
 *
 * WHY THIS FILE EXISTS. A captured source was converted with the wrong typeface on every single element —
 * 135 elements at 1440px, every band. Measured, per-family, over the text leaves of both pages:
 *
 *     source   176 of 178 leaves render in  -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto,
 *                                           Helvetica, Arial, sans-serif      (exactly 1 renders in Inter)
 *     build    167 of its leaves render in  Inter
 *
 * The source's <head> carries a Google Fonts link for THREE families — a display face, Inter, and a
 * condensed face — and renders in NONE of them: the link is there, the CSS never references it. So the
 * source's real typography is the system stack, and the conversion replaced it wholesale.
 *
 * `detect_computed_fonts()` was already right. It reads the <body>'s COMPUTED family, finds a generic /
 * system keyword, and deliberately returns '' — "the source names no real family". The defect is what
 * happens next, in `tokens_to_design_config()`:
 *
 *     $head_font = $gfonts[0];                                        // no system guard at all
 *     $body_font = $gfonts[1] ?? ( $gfonts[0] when ! $body_system );  // guard on ONE branch only
 *
 * `$body_system` existed and was correct; it simply did not cover the branch that fired. With two or more
 * families in the URL, `$gfonts[1]` was taken unconditionally, so a measured system body stack lost to the
 * SECOND family named in a stylesheet link. The heading had no such guard in any branch.
 *
 * The rule this pins: the Google-Fonts URL is a list of what the page DOWNLOADED, which is not evidence of
 * what it DISPLAYS. It may fill a role only when the measurement left that role unknown — never when the
 * measurement says the role is the system stack.
 *
 * Both directions are asserted, because a guard tested in one direction is how the original defect shipped:
 * a source that genuinely renders in its loaded faces must still get them.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/loaded-but-unused-font-does-not-win-test.php"
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

$SYSTEM = '-apple-system, BlinkMacSystemFont, Segoe UI, Roboto, Helvetica, Arial, sans-serif';
$GURL   = 'https://fonts.googleapis.com/css2?family=Anton&family=Inter:wght@400;700&family=Oswald:wght@600&display=swap';

/** A page whose body, heading and paragraph all render in $ff, with $gurl linked in the head. */
$page = function ( $ff, $gurl ) {
	return '<!DOCTYPE html><html><head><link rel="stylesheet" href="' . $gurl . '"></head>'
		. '<body data-sc-cs="font-family:' . $ff . ';background-color:rgb(12,12,13)">'
		. '<main><section data-sc-cs="padding:80px 0px;display:block">'
		. '<h2 data-sc-cs="font-family:' . $ff . ';font-size:48px;font-weight:900">Cut Craft Confidence</h2>'
		. '<p data-sc-cs="font-family:' . $ff . ';font-size:16px">Precision cuts and expert grooming.</p>'
		. '</section></main></body></html>';
};

echo "\n== A system-stack page does not adopt the faces it merely downloads\n";

$cfg = FW_Site_Converter_Stitch::tokens_to_design_config(
	array( 'fonts' => array( $GURL ) ), $page( $SYSTEM, $GURL ), 'T'
);
$fonts = (array) ( $cfg['fonts'] ?? array() );
$body  = (string) ( $fonts['body'] ?? '' );
$head  = (string) ( $fonts['heading'] ?? '' );

// The exact failure: the SECOND family in the URL became the body font.
$ok( 0 !== strcasecmp( $body, 'Inter' ), "the body font is not the URL's second family (got '$body')" );
// …and the FIRST became the heading font, which had no guard at all.
$ok( 0 !== strcasecmp( $head, 'Anton' ), "the heading font is not the URL's first family (got '$head')" );
$ok( 0 !== strcasecmp( $head, 'Oswald' ) && 0 !== strcasecmp( $body, 'Oswald' ),
	'and neither role took the third family' );

echo "\n== …it keeps the stack it actually renders in\n";

// A system stack is not a named family, so the roles are left unset and the theme's own system default
// applies. What must NOT happen is a named face being invented for them.
$ok( '' === $body, 'the body role is left unset rather than given an invented face' );
$ok( '' === $head, 'the heading role likewise' );

echo "\n== NEGATIVE: a source that REALLY uses its loaded faces still gets them\n";

// The guard must key on the MEASUREMENT, not on the presence of a Google link. Same URL, but the page now
// genuinely renders in those faces.
$real = '<!DOCTYPE html><html><head><link rel="stylesheet" href="' . $GURL . '"></head>'
	. '<body data-sc-cs="font-family:Inter, sans-serif;background-color:rgb(255,255,255)">'
	. '<main><section data-sc-cs="padding:80px 0px;display:block">'
	. '<h2 data-sc-cs="font-family:Anton, sans-serif;font-size:48px;font-weight:900">Cut Craft Confidence</h2>'
	. '<p data-sc-cs="font-family:Inter, sans-serif;font-size:16px">Precision cuts and expert grooming.</p>'
	. '</section></main></body></html>';
$cfg2 = FW_Site_Converter_Stitch::tokens_to_design_config( array( 'fonts' => array( $GURL ) ), $real, 'T' );
$f2   = (array) ( $cfg2['fonts'] ?? array() );
$ok( 0 === strcasecmp( (string) ( $f2['body'] ?? '' ), 'Inter' ), 'a measured Inter body is still carried' );
$ok( 0 === strcasecmp( (string) ( $f2['heading'] ?? '' ), 'Anton' ), 'a measured Anton heading is still carried' );

echo "\n== NEGATIVE: an unmeasurable page may still fall back to the URL\n";

// No font-family stamps anywhere: the measurement knows nothing, so the downloaded faces remain the best
// available evidence and the old fallback must still fire. Removing it would strand such sources.
$bare = '<!DOCTYPE html><html><head><link rel="stylesheet" href="' . $GURL . '"></head>'
	. '<body data-sc-cs="background-color:rgb(255,255,255)">'
	. '<main><section data-sc-cs="padding:80px 0px;display:block">'
	. '<h2 data-sc-cs="font-size:48px;font-weight:900">Cut Craft Confidence</h2>'
	. '<p data-sc-cs="font-size:16px">Precision cuts and expert grooming.</p>'
	. '</section></main></body></html>';
$cfg3 = FW_Site_Converter_Stitch::tokens_to_design_config( array( 'fonts' => array( $GURL ) ), $bare, 'T' );
$f3   = (array) ( $cfg3['fonts'] ?? array() );
$ok( '' !== (string) ( $f3['heading'] ?? '' ), 'an unstamped page still gets a heading face from the URL' );

printf( "\n%s\n", $fails ? "{$fails} FAIL(S)" : 'ALL PASS' );
exit( $fails ? 1 : 0 );
