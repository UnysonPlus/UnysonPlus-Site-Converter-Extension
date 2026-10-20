<?php
/**
 * Regression guard: footer geometry that no native option can express still reaches the page, and the
 * Custom CSS it rides on is not thrown away by a later writer.
 *
 * WHY THIS FILE EXISTS. Three defects, one theme. The converter's documented answer to "the source
 * measured X and no option can hold X" is to store the nearest value the control CAN keep -- so the
 * layout never jumps the first time the user saves that tab -- and carry the exact measurement as a
 * scoped `!important` rule. All three of these were failures of that mechanism, not of the measurement.
 *
 * D-1  THE EXACT RULE WAS WRITTEN AND THEN ERASED.
 *      misc_custom_css was ASSIGNED near the end of the chrome assembly, discarding anything an earlier
 *      reader had appended to it. Several readers do append; the ones that ran after that line survived
 *      and the ones before it were silently wiped. flush_band_pad_css() is one of the latter, so the
 *      copyright bar's exact padding never shipped: the spacing ladder's nearest step is 24px against a
 *      measured 32px, the override was emitted correctly, and it was deleted a few hundred lines later.
 *      The bar rendered 24px and the belt-and-braces mechanism looked broken when it had worked.
 *      Fixed by carrying the existing value through -- at the END, so an earlier, more specific rule
 *      still out-ranks the base on a tie, which is the whole point of a per-band override.
 *
 * D-2  THE BAR WAS NOT RECOGNISED AS A BAR.
 *      The band walk ascends from the copyright element only to an ancestor carrying a top BORDER. A
 *      bottom bar that separates itself by SPACE alone (`pt-8 flex justify-between`, no rule) left the
 *      band as the copyright element itself, which holds no padding, no background and no border -- so
 *      every field the band detector reads came back empty and there was nothing for D-1 to lose.
 *      The fallback is the copyright element's PARENT, which is how detect_footer_legal_links() already
 *      defines the band, so the reader that STYLES the bar and the reader that FILLS it cannot disagree
 *      about which element it is. Guarded: in a footer with no distinct bottom row the notice sits in the
 *      footer BODY, whose padding and background are the footer's own and already carried elsewhere.
 *      A body holds the column GRID; a bar does not. That is the negative below.
 *
 * D-3  A SPLIT THE TWELFTHS CANNOT SAY WAS STORED AS IF THEY COULD.
 *      The column split is snapped onto the slider's twelfths. A brand column spanning two of five tracks
 *      beside three single-track columns measures 40 / 20 / 20 / 20 -- and 40% is 4.8 twelfths. The snap
 *      rounds to 5 / 2 / 2 / 2 = eleven, then hands the leftover unit to the WIDEST column, giving
 *      50 / 16.7 / 16.7 / 16.7: the brand ~80px too wide, every column's x position shifted. No whole-
 *      twelfth split expresses it (5 / 3 / 2 / 2 makes one link column half again its siblings, which
 *      reads worse than the error it fixes), so the exact ratio now rides as scoped CSS -- reusing the
 *      five-column branch's machinery, which is already count-agnostic.
 *      Gated on the snap having MOVED the measurement by more than a couple of points, because a 57 / 43
 *      source sits on twelfths almost exactly and pinning that would make the split control silently
 *      inert for every ordinary footer. That gate is a negative below.
 *
 *      A fourth bug surfaced while proving D-3: a cell spanning N tracks also swallows the N-1 GAPS it
 *      spans over, which that emitter was not counting -- a whole gutter, not a rounding error. Two
 *      204.8px tracks with a 40px gap are 449.6px, not 409.6px, so the ratio came out a clean 2:1
 *      against a true 2.2:1 and the brand was drawn ~24px narrow. Its sibling emitter had always added
 *      them.
 *
 * MEASURED AFTER, source vs. build at 1440px: footer height 435 -> 434px; column headings at
 * x = 618 / 862 / 1107 against 618 / 863 / 1107.
 *
 * The fixture is the real footer, reduced, with the source's own naming and contact details replaced.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/footer-geometry-no-option-can-hold-test.php"
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

$ref = new ReflectionClass( 'FW_Site_Converter_Stitch' );
$pt  = $ref->getMethod( 'parse_tokens' ); $pt->setAccessible( true );

/** The chrome values plus its Custom CSS as one string. */
$chrome = function ( $html ) use ( $pt ) {
	$c = FW_Site_Converter_Stitch::tokens_to_theme_settings_chrome( (array) $pt->invoke( null, $html ), $html, 'T' );
	$v = (array) ( $c['values'] ?? array() );
	$m = $v['misc_custom_css'] ?? array();
	return array( $v, is_array( $m ) ? (string) ( $m['custom_css'] ?? '' ) : (string) $m );
};

$fixture = __DIR__ . '/fixtures/footer-contact-rows-are-not-one-row.html';
if ( ! is_file( $fixture ) ) { fwrite( STDERR, "FAIL: fixture missing\n" ); exit( 1 ); }
list( $values, $css ) = $chrome( file_get_contents( $fixture ) );

echo "\n== D-1/D-2: the bottom bar's exact padding survives to the page\n";

$ok( (bool) preg_match( '/\.footer-section--copyright\s*\{[^}]*padding-top:\s*32px[^}]*!important/', $css ),
	'the 32px override is present, scoped and !important' );

// Stated separately because it is the specific way the mechanism failed: the ladder's nearest step is
// 24px, so a rule saying 24px would mean the snap was taken as the answer.
$ok( ! preg_match( '/\.footer-section--copyright\s*\{[^}]*padding-top:\s*24px/', $css ),
	'and it is the measured 32px, not the ladder\'s nearest 24px step' );

echo "\n== D-1: the Custom CSS written before the assembly is not discarded\n";

// The band rule is appended EARLY. Its presence above already proves the key is no longer overwritten,
// so this asserts the base content is still there too -- a fix that kept the early content by dropping
// the assembled base would pass the first check and break everything else.
$ok( false !== strpos( $css, 'converted header/footer styles' ),
	'the assembled base is still present alongside it' );

echo "\n== D-3: the measured track ratio reaches the page\n";

$ok( (bool) preg_match( '/\.footer-row--grid\s*\{[^}]*grid-template-columns:\s*([^;}]+)/', $css, $gm ),
	'a grid-template-columns override is emitted' );
$tracks = isset( $gm[1] ) ? trim( $gm[1] ) : '';
$ok( '2.2fr 1fr 1fr 1fr' === $tracks,
	sprintf( 'with the spanned gap counted: 2.2fr, not a clean 2fr (got %s)', wp_json_encode( $tracks ) ) );
$ok( (bool) preg_match( '/\.footer-row--grid\s*\{[^}]*column-gap:\s*40px/', $css ),
	'and the source\'s own 40px gap' );

echo "\n== ...while the split stays on the control's twelfths\n";

// Both halves of the pattern matter. If the split were written as the raw measurement the footer would
// visibly reflow the first time the user saved the tab, which is what snapping exists to prevent.
$mfc   = (array) ( $values['main_footer_columns'] ?? array() );
$n     = (string) ( $mfc['count'] ?? '' );
$split = (array) ( $mfc[ $n ]['main_footer_split'] ?? array() );
$ws    = array();
foreach ( $split as $sg ) { $ws[] = (float) $sg['w']; }
$on_twelfths = count( $ws ) > 0;
foreach ( $ws as $w ) { if ( abs( $w / ( 100 / 12 ) - round( $w / ( 100 / 12 ) ) ) > 0.01 ) { $on_twelfths = false; } }
$ok( $on_twelfths, sprintf( 'every stored segment is a whole twelfth (got %s)', wp_json_encode( $ws ) ) );

echo "\n== NEGATIVE: a source the twelfths CAN express is not pinned\n";

// The gate. Without it the split control would be inert on every ordinary footer: the CSS would always
// win, so a user dragging the slider would see nothing move.
$svg = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"><path d="M12 2v20"/></svg>';
$two = '<!DOCTYPE html><html><body data-sc-cs="font-family:Inter, sans-serif">'
	. '<main><section data-sc-cs="padding:80px 0px"><h1 data-sc-cs="font-size:56px">Page</h1></section></main>'
	. '<footer data-sc-cs="padding:64px 0px;background-color:rgb(17, 17, 17)">'
	// 2 cells, 2 tracks, 7:5 -- 58.3 / 41.7, which IS a whole-twelfths split
	. '<div data-sc-cs="display:grid;grid-template-columns:613.66px 438.33px;column-gap:40px">'
	. '<div><h4 data-sc-cs="font-size:12px">ABOUT</h4><a href="/a">One</a><a href="/b">Two</a><a href="/c">Three</a></div>'
	. '<div><h4 data-sc-cs="font-size:12px">LINKS</h4><a href="/d">Four</a><a href="/e">Five</a><a href="/f">Six</a></div>'
	. '</div>'
	. '<div class="pt-8 flex items-center justify-between" data-sc-cs="display:flex;justify-content:space-between;padding:32px 0px 0px">'
	. '<div>&copy; 2026 EXAMPLE. ALL RIGHTS RESERVED.</div>'
	. '<div class="flex items-center gap-6" data-sc-cs="display:flex;column-gap:24px">'
	. '<a href="/privacy">PRIVACY POLICY</a><a href="/terms">TERMS &amp; CONDITIONS</a></div>'
	. '</div></footer></body></html>';
list( , $two_css ) = $chrome( $two );
$ok( ! preg_match( '/\.footer-row--grid\s*\{[^}]*grid-template-columns/', $two_css ),
	'a 7:5 split gets no track override -- the slider holds it' );

echo "\n== NEGATIVE: the footer BODY is not mistaken for a bottom bar\n";

// If the parent fallback were unguarded, a footer whose notice sits directly in its body would restate
// the footer's own padding and background on a band that does not exist.
$body_cp = '<!DOCTYPE html><html><body data-sc-cs="font-family:Inter, sans-serif">'
	. '<main><section data-sc-cs="padding:80px 0px"><h1 data-sc-cs="font-size:56px">Page</h1></section></main>'
	. '<footer data-sc-cs="padding:64px 0px;background-color:rgb(17, 17, 17)">'
	. '<div data-sc-cs="padding:96px 0px;display:block">'
	. '<div data-sc-cs="display:grid;grid-template-columns:300px 300px;column-gap:40px">'
	. '<div><h4 data-sc-cs="font-size:12px">ABOUT</h4><a href="/a">One</a><a href="/b">Two</a></div>'
	. '<div><h4 data-sc-cs="font-size:12px">LINKS</h4><a href="/c">Three</a><a href="/d">Four</a></div>'
	. '</div>'
	. '<div>&copy; 2026 EXAMPLE. ALL RIGHTS RESERVED.</div>'
	. '</div></footer></body></html>';
list( , $body_css ) = $chrome( $body_cp );
$ok( ! preg_match( '/\.footer-section--copyright\s*\{[^}]*padding-top:\s*96px/', $body_css ),
	'the body\'s own 96px inset is not restated as the copyright bar\'s' );

printf( "\n%s\n", $fails ? "{$fails} FAIL(S)" : 'ALL PASS' );
exit( $fails ? 1 : 0 );
