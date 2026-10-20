<?php
/**
 * Regression guard: a source CSS grid is recorded as one, so the conversion can stay a grid.
 *
 * A row recognizer records a source container's columns, gap and stacking, but never recorded whether the
 * container was a `display:grid` in the first place. Without that fact the mapper has to guess, and its rule
 * was a narrow one: emit a native grid only for an equal `grid-cols-N` whose N does NOT divide 12 (5, 7, …),
 * because the 12-column span model cannot express 1/5 or 1/7. Every other row — including every 2, 3, 4 and
 * 6 column grid — was spread as nested flex boxes.
 *
 * That difference is visible, because a CSS grid stretches the items in a row to the row's height and a
 * stack of flex boxes does not. Measured on a captured testimonials band, a 2-column source grid:
 *
 *     source     row 1: 285 / 285    row 2: 270 / 270     (equal — the grid stretches them)
 *     converted  row 1: 316 / 218    row 2: 218 / 270     (ragged — each card sizes to its own content)
 *
 * So the container's own display is carried as a fact. What the mapper does with it is a separate decision;
 * this guard is only that the measurement is taken and is honest — a grid says grid, a flex row does not.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/source-grid-stays-a-grid-test.php"
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

$ld = new ReflectionMethod( 'FW_Site_Converter_Stitch', 'load_dom' );
$ld->setAccessible( true );
$ig = new ReflectionMethod( 'FW_Site_Converter_Stitch', 'el_is_source_grid' );
$ig->setAccessible( true );

$el_of = function ( $cs ) use ( $ld ) {
	$html = '<!DOCTYPE html><html><body><div class="box" data-sc-cs="' . $cs . '">'
		. '<div data-sc-cs="display:block">a</div><div data-sc-cs="display:block">b</div>'
		. '</div></body></html>';
	$dom = $ld->invoke( null, $html );
	foreach ( $dom->getElementsByTagName( 'div' ) as $d ) {
		if ( 'box' === (string) $d->getAttribute( 'class' ) ) { return $d; }
	}
	return null;
};

echo "\n== The measured shape: a two-column source grid\n";

$ok( true === (bool) $ig->invoke( null, $el_of( 'display:grid;grid-template-columns:repeat(2, 1fr);gap:20px' ) ),
	'a grid container reports as a grid' );
$ok( true === (bool) $ig->invoke( null, $el_of( 'display:grid;grid-template-columns:1fr 1fr' ) ),
	'…however its tracks are written' );
$ok( true === (bool) $ig->invoke( null, $el_of( 'display:inline-grid;grid-template-columns:1fr 1fr' ) ),
	'an inline-grid is a grid too' );

echo "\n== NEGATIVE: a flex row is not a grid\n";

$ok( false === (bool) $ig->invoke( null, $el_of( 'display:flex;gap:20px' ) ), 'a flex row is not a grid' );
$ok( false === (bool) $ig->invoke( null, $el_of( 'display:inline-flex;gap:20px' ) ), 'nor an inline-flex row' );
$ok( false === (bool) $ig->invoke( null, $el_of( 'display:block' ) ), 'nor a plain block' );
$ok( false === (bool) $ig->invoke( null, $el_of( '' ) ), 'an unstamped element claims nothing' );

echo "\n== NEGATIVE: the word must be the DISPLAY, not any mention of it\n";

// `grid-template-columns` alone, or a class called "grid", is not a display declaration.
$ok( false === (bool) $ig->invoke( null, $el_of( 'display:block;grid-template-columns:repeat(3, 1fr)' ) ),
	'grid-template-columns on a block element does not make it a grid' );

printf( "\n%s\n", $fails ? "{$fails} FAIL(S)" : 'ALL PASS' );
exit( $fails ? 1 : 0 );
