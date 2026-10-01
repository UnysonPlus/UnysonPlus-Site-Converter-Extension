<?php
/**
 * Regression guard: a vertical numbered timeline keeps its badge, its side, and its connecting line.
 *
 * A vertical timeline is written as `<ol class="relative">` holding, FIRST, an absolutely-positioned 1px
 * spine (it has to come first so it paints behind the steps), then the `<li>` steps — each a flex row of a
 * bordered circle with the numeral and a title/body stack beside it.
 *
 * Three separate defects stacked up on that one shape, and all three are the same kind of mistake:
 *
 *  1. THE SPINE WAS READ AS THE FIRST STEP. `detect_steps_design()` took the first ELEMENT child, which is
 *     the out-of-flow spine — not a flex row, and empty — so `is_row` was false and `lead` was null. Neither
 *     numInline nor numBadge fired, and the mapper fell through to its "big faded number in the corner"
 *     convention: every numeral rendered on the FAR RIGHT with no circle, against a source that draws it on
 *     the left. Out-of-flow layers take no part in the flow.
 *  2. THE RADIUS WAS IN SCIENTIFIC NOTATION. A `rounded-full` chip computes to `border-radius:3.35544e+07px`,
 *     and the shape regex accepted only `[0-9.]+`, so a circle was classified `square`. The very same test
 *     200 lines further down already allowed the exponent — fixed in one copy, not the other.
 *  3. TWO CONNECTOR DETECTORS, LAST ONE WINS. The badge branch MEASURES the spine (thin, tall, painted,
 *     textless) and sets `solid`; a later scan, which only recognises a HORIZONTAL `h-px` bar, then
 *     overwrote it with `none` unconditionally. The line the source draws was found and then discarded.
 *
 * All three are native options — marker / marker_shape / connector — so none of this needs custom CSS.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/steps-vertical-spine-test.php"
 *
 * Exit code 0 = all PASS, 1 = at least one FAIL.
 */

if ( ! class_exists( 'FW_Site_Converter_Stitch' ) ) {
	fwrite( STDERR, "FAIL: site-converter not loaded (run inside a WP install with the plugin active)\n" );
	exit( 1 );
}

$fails = 0;
$ok    = function ( $cond, $msg ) use ( &$fails ) {
	if ( $cond ) { echo "  \xe2\x9c\x93 $msg\n"; } else { $fails++; echo "  \xe2\x9c\x97 FAIL: $msg\n"; }
};

$design_of = function ( $html ) {
	$ld = new ReflectionMethod( 'FW_Site_Converter_Stitch', 'load_dom' );
	$ld->setAccessible( true );
	$dom = $ld->invoke( null, '<!DOCTYPE html><html><body>' . $html . '</body></html>' );
	$list = $dom ? $dom->getElementsByTagName( 'ol' )->item( 0 ) : null;
	if ( ! ( $list instanceof DOMElement ) ) { $list = $dom ? $dom->getElementsByTagName( 'ul' )->item( 0 ) : null; }
	if ( ! ( $list instanceof DOMElement ) ) { return array(); }
	$m = new ReflectionMethod( 'FW_Site_Converter_Stitch', 'detect_steps_design' );
	$m->setAccessible( true );
	return (array) $m->invoke( null, $list, array() );
};

$INK = 'color:lab(25.76 0 0);font-family:Inter, sans-serif;font-size:16px;line-height:24px;text-align:start;';

/* The badge: a 64px bordered circle whose radius computes in SCIENTIFIC NOTATION, as `rounded-full` does. */
$badge = function ( $n ) use ( $INK ) {
	return '<div class="shrink-0 w-16 h-16 rounded-full bg-background border border-border flex items-center justify-center" '
		. 'data-sc-cs="background-color:lab(100 0 0);color:lab(27.79 -4.5 -26.68);font-size:30px;font-weight:600;line-height:36px;'
		. 'border-top-width:1px;border-top-style:solid;border-bottom-width:1px;border-radius:3.35544e+07px;height:64px;'
		. 'display:flex;justify-content:center;align-items:center;z-index:10">' . $n . '</div>';
};
$step = function ( $n, $title, $body ) use ( $INK, $badge ) {
	return '<li class="relative flex gap-6 pb-14" data-sc-cs="' . $INK . 'display:flex;gap:24px;padding-bottom:56px;position:relative">'
		. $badge( $n )
		// The content column carries the step's READING MEASURE (`flex-1 max-w-3xl`), and the TITLE carries
		// the gap to its copy (`mb-3`) — the copy itself has no top margin.
		. '<div class="flex-1 max-w-3xl" data-sc-cs="' . $INK . 'display:block;max-width:768px">'
		. '<h3 data-sc-cs="font-size:24px;font-weight:600;margin:0px 0px 12px">' . $title . '</h3>'
		. '<p data-sc-cs="font-size:16px;line-height:26px">' . $body . '</p></div></li>';
};
/* The spine is written FIRST so it paints behind the steps — that is the whole trap. */
$SPINE = '<div class="absolute left-6 top-2 bottom-2 w-px" aria-hidden="true" '
	. 'data-sc-cs="background-color:lab(89.56 0 0);line-height:24px;height:812.5px;position:absolute;width:1px"></div>';

$STEPS = $step( '1', 'Ger&auml;t vorbeibringen', 'Ohne Termin w&auml;hrend der &Ouml;ffnungszeiten, einfach vorbeikommen.' )
	. $step( '2', '&Uuml;berpr&uuml;fung', 'Wir pr&uuml;fen das Ger&auml;t und melden uns mit einer ehrlichen Einsch&auml;tzung.' )
	. $step( '3', 'Reparatur nach Freigabe', 'Nichts passiert ohne Zustimmung des Kunden, niemals auf Verdacht.' )
	. $step( '4', 'Test und Abholung', 'Nach der Reparatur wird das Ger&auml;t gepr&uuml;ft und kann abgeholt werden.' );

echo "\n== The spine is not mistaken for the first step\n";

$d = $design_of( '<ol class="relative" data-sc-cs="' . $INK . 'display:block;position:relative">' . $SPINE . $STEPS . '</ol>' );
$ok( ! empty( $d ), 'the list is recognised as steps' );
$ok( 'vertical' === (string) ( $d['design'] ?? '' ),
	'it is the VERTICAL design (got "' . ( $d['design'] ?? '' ) . '")' );
$ok( ! empty( $d['numBadge'] ),
	'the numeral is seen as a painted BADGE, so it rides the native marker on the LEFT (got ' . var_export( $d['numBadge'] ?? null, true ) . ')' );
$ok( 64 === (int) ( $d['markerSize'] ?? 0 ),
	'...at the source\'s measured 64px (got ' . var_export( $d['markerSize'] ?? null, true ) . ')' );

echo "\n== A `rounded-full` radius in scientific notation is still a circle\n";

$ok( 'circle' === (string) ( $d['numShape'] ?? '' ),
	'`border-radius:3.35544e+07px` reads as circle, not square (got "' . ( $d['numShape'] ?? '' ) . '")' );

echo "\n== The measured spine survives the later connector scan\n";

$ok( 'solid' === (string) ( $d['connector'] ?? '' ),
	'the 1px spine gives connector:solid and is NOT overwritten by the horizontal-only scan (got "' . ( $d['connector'] ?? '' ) . '")' );

echo "
== The step's own reading measure, and the gap under its title
";

// The copy beside the badge is capped by the source (`max-w-3xl`); the LIST already has its own cap, so this
// is a reading MEASURE. Without it the body ran the full item width and wrapped to fewer lines than the
// source: measured 942px against the source's 768, and the band came back 11% shorter.
$ok( isset( $d['bodyMaxW'] ) && 768 === (int) $d['bodyMaxW'],
	'the step copy keeps its 768px measure (got ' . var_export( $d['bodyMaxW'] ?? null, true ) . ')' );

// EITHER SIDE may carry the title-to-copy gap. Reading only the COPY's top margin missed `mb-3` on the
// title — and because the emitted rule also ZEROES the title's own margin, a gap found as 0 did not merely
// fail to carry the source's 12px, it actively removed it, on every step.
$ok( isset( $d['titleGap'] ) && 12.0 === (float) $d['titleGap'],
	'...and the 12px gap under the title, wherever the source puts it (got ' . var_export( $d['titleGap'] ?? null, true ) . ')' );

echo "\n== The step rhythm is read from PADDING, not only margin\n";

// A timeline spaces its steps with `pb-14 lg:pb-24` — padding, because the spine must run THROUGH the gap
// rather than stop at it. Reading only margin-top, the source's inset never arrived and the design's own
// tighter default stood: the converted band measured 155% off its source's height.
$ok( isset( $d['itemGap'] ) && abs( (float) $d['itemGap'] - 56 ) <= 2,
	'the item\'s 56px padding-bottom is the step gap (got ' . var_export( $d['itemGap'] ?? null, true ) . ')' );

echo "\n== NEGATIVE: a list with NO spine gets no connector\n";

$d2 = $design_of( '<ol class="relative" data-sc-cs="' . $INK . 'display:block;position:relative">' . $STEPS . '</ol>' );
$ok( ! empty( $d2['numBadge'] ),
	'NEGATIVE: the badge is still found without a spine present' );
$ok( 'none' === (string) ( $d2['connector'] ?? '' ),
	'NEGATIVE: ...and no line is invented (got "' . ( $d2['connector'] ?? '' ) . '")' );

echo "\n== NEGATIVE: a square chip stays square\n";

$sq = str_replace( 'border-radius:3.35544e+07px', 'border-radius:0px', '<ol class="relative" data-sc-cs="' . $INK . 'display:block;position:relative">' . $SPINE . $STEPS . '</ol>' );
$d3 = $design_of( $sq );
$ok( 'square' === (string) ( $d3['numShape'] ?? '' ),
	'NEGATIVE: a 0px radius is square, so the shape is really being measured (got "' . ( $d3['numShape'] ?? '' ) . '")' );

echo "\n== NEGATIVE: a bare numeral with no badge is not promoted to a marker\n";

$plain = '<ol class="relative" data-sc-cs="' . $INK . 'display:block;position:relative">'
	. '<li data-sc-cs="' . $INK . 'display:flex;gap:24px"><div data-sc-cs="font-size:30px">1</div>'
	. '<div data-sc-cs="' . $INK . 'display:block"><h3 data-sc-cs="font-size:24px">Ger&auml;t vorbeibringen</h3>'
	. '<p data-sc-cs="font-size:16px;line-height:26px">Ohne Termin w&auml;hrend der &Ouml;ffnungszeiten vorbeikommen.</p></div></li>'
	. '<li data-sc-cs="' . $INK . 'display:flex;gap:24px"><div data-sc-cs="font-size:30px">2</div>'
	. '<div data-sc-cs="' . $INK . 'display:block"><h3 data-sc-cs="font-size:24px">&Uuml;berpr&uuml;fung</h3>'
	. '<p data-sc-cs="font-size:16px;line-height:26px">Wir pr&uuml;fen das Ger&auml;t und melden uns ehrlich.</p></div></li></ol>';
$d4 = $design_of( $plain );
$ok( empty( $d4['numBadge'] ),
	'NEGATIVE: an unpainted numeral is not a badge — nothing is fabricated (got ' . var_export( $d4['numBadge'] ?? null, true ) . ')' );

echo $fails ? "\n\xe2\x9c\x97 $fails FAILED\n" : "\n\xe2\x9c\x93 ALL PASS - a vertical timeline keeps its badge, its side and its line\n";
exit( $fails ? 1 : 0 );
