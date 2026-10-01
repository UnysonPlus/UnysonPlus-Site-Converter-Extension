<?php
/**
 * Regression guard: an editorial FAQ (hairline dividers, no box) converts to the FLUSH style.
 *
 * The accordion's style family is a native option and one of its five choices is exactly this shape —
 * "Flush = hairline dividers only (editorial FAQ)". A source writes it as `<div class="divide-y border-y">`:
 * a rule between each row, and a rule above and below the list. No side edges, no radius, no fill.
 *
 * The chooser asked only whether the TRACK had a top border, and `border-y` sets one — so it answered
 * `bordered`, which the option itself documents as "one rounded box". The converted FAQ grew a bordered panel
 * around a list the source draws as plain rules on the page background.
 *
 * A real box also has SIDE edges, or a radius. That is the whole distinction, and it is what this pins.
 *
 * NOTE ON THE FIXTURE — the first version of this test asserted nothing. accordion_design() only recognises a
 * list whose items are `<details>` or whose toggles carry `aria-expanded`; hand-built `<button>`s had neither,
 * so it returned an empty array and the `bordered` being asserted against was merely the shortcode's default.
 * The toggles below therefore carry `aria-expanded`, and the NEGATIVE cases prove the detector really ran.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/accordion-flush-test.php"
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

$INK = 'color:lab(5.26 0 0);font-family:Inter, sans-serif;font-size:16px;line-height:24px;text-align:start;';

/** accordion_design() over a FAQ list whose TRACK carries $track_cs. */
$design = function ( $track_cs ) use ( $INK ) {
	$qs = array(
		array( 'Was kostet die Überprüfung?', 'Die Überprüfung kostet 40 Euro und wird bei einer Reparatur verrechnet.' ),
		array( 'Wie lange dauert die Reparatur?', 'Die Dauer hängt vom Fehlerbild und der Ersatzteilverfügbarkeit ab.' ),
		array( 'Muss ich einen Termin vereinbaren?', 'Nein, Sie können während unserer Öffnungszeiten vorbeikommen.' ),
		array( 'Welche Marken reparieren Sie?', 'Alle gängigen Marken, von Apple über Lenovo bis Schenker.' ),
	);
	$items = '';
	foreach ( $qs as $i => $q ) {
		$items .= '<div data-sc-cs="' . $INK . 'display:block;background-color:rgba(0, 0, 0, 0);border-radius:0px">'
			// aria-expanded is what marks this as an accordion toggle — without it the detector sees no items.
			. '<button type="button" aria-expanded="' . ( 0 === $i ? 'true' : 'false' ) . '" data-sc-cs="' . $INK
			. 'display:flex;justify-content:space-between;align-items:center;padding:16px 0px">'
			. '<span data-sc-cs="' . $INK . 'display:block;font-weight:600">' . esc_html( $q[0] ) . '</span>'
			. '<span data-sc-cs="display:block">+</span></button>'
			. '<div data-sc-cs="' . $INK . 'display:block"><p data-sc-cs="font-size:15px;line-height:24px">' . esc_html( $q[1] ) . '</p></div>'
			. '</div>';
	}
	$dom = new DOMDocument();
	libxml_use_internal_errors( true );
	$dom->loadHTML( '<!DOCTYPE html><html><body><div class="divide-y" data-sc-cs="' . $track_cs . '">' . $items . '</div></body></html>', LIBXML_NOERROR | LIBXML_NOWARNING );
	libxml_clear_errors();
	$m = new ReflectionMethod( 'FW_Site_Converter_Stitch', 'accordion_design' );
	$m->setAccessible( true );
	return (array) $m->invoke( null, $dom->getElementsByTagName( 'div' )->item( 0 ) );
};

$BASE = $INK . 'display:block;';

echo "\n== The detector actually runs on this fixture\n";

$probe = $design( $BASE . 'border-top-width:1px;border-bottom-width:1px;border-left-width:0px;border-right-width:0px;border-radius:0px' );
$ok( ! empty( $probe ),
	'accordion_design() returns a design — the toggles carry aria-expanded, so the items are seen (got ' . count( $probe ) . ' keys)' );

echo "\n== `border-y` with no side edges is the EDITORIAL shape\n";

$ok( 'flush' === ( $probe['accordion_style'] ?? '' ),
	'a `divide-y border-y` track is FLUSH, not a box (got "' . ( $probe['accordion_style'] ?? '' ) . '")' );

echo "\n== NEGATIVE: a real BOX is still bordered\n";

$b = $design( $BASE . 'border-top-width:1px;border-bottom-width:1px;border-left-width:1px;border-right-width:1px;border-radius:0px' );
$ok( 'bordered' === ( $b['accordion_style'] ?? '' ),
	'NEGATIVE: borders on all four sides is a box (got "' . ( $b['accordion_style'] ?? '' ) . '")' );

$r = $design( $BASE . 'border-top-width:1px;border-bottom-width:1px;border-left-width:0px;border-right-width:0px;border-radius:12px' );
$ok( 'bordered' === ( $r['accordion_style'] ?? '' ),
	'NEGATIVE: ...and so is a rule plus a RADIUS — a rounded edge implies the box (got "' . ( $r['accordion_style'] ?? '' ) . '")' );

echo "\n== NEGATIVE: a track with no rules at all is still flush\n";

$n = $design( $BASE . 'border-top-width:0px;border-bottom-width:0px;border-radius:0px' );
$ok( 'flush' === ( $n['accordion_style'] ?? '' ),
	'NEGATIVE: no border anywhere is flush, as it always was (got "' . ( $n['accordion_style'] ?? '' ) . '")' );

echo $fails ? "\n\xe2\x9c\x97 $fails FAILED\n" : "\n\xe2\x9c\x93 ALL PASS - an editorial FAQ keeps its hairlines and gains no card\n";
exit( $fails ? 1 : 0 );
