<?php
/**
 * Regression guard: a card grid's cell must find its skin even when a wrapper inside the cell carries it.
 *
 * A hand-written card paints itself, so reading the cell's own computed style finds everything. A page
 * builder does not: it nests a structural wrapper inside the grid cell and paints THAT, leaving the cell
 * bare. The cell is still the card — it is what the recognizer matched and what the grid lays out.
 *
 * `cell_card_skin()` exists precisely to resolve that ("sometimes the cell, sometimes one wrapper down"),
 * and the sibling grid paths already call it. The CARD-GRID path called `read_card_skin()` instead, which
 * reads the cell's own stamp only. Measured on a captured source: three service cards (white fill, 15px
 * radius, 2px border, 25px padding) converted to flat text columns, and because no `cardBox` was carried
 * the icon_box got no `box_style` either — a Box Preset for that exact skin had been harvested and sat
 * unused.
 *
 * The descent is not unconditional, and that matters as much as the descent itself: an earlier attempt
 * made `read_card_skin()` itself fall through to a lone child, which made the CELL report a skin that the
 * inner panel already wears. Five goldens caught it — a skin read one wrapper down must be painted ONCE,
 * on the panel's own box, never on the grid item's column as well. `cell_card_skin()` only descends into
 * a child holding ≥80% of the cell's text, so it cannot claim one card out of a row of siblings.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/card-skin-one-wrapper-down-test.php"
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
$cell_skin = new ReflectionMethod( 'FW_Site_Converter_Stitch', 'cell_card_skin' );
$cell_skin->setAccessible( true );
$own_skin = new ReflectionMethod( 'FW_Site_Converter_Stitch', 'read_card_skin' );
$own_skin->setAccessible( true );

// The skin as a builder stamps it on its inner wrapper.
$SKIN = 'background-color:rgb(255, 255, 255);border-radius:15px;border-top-width:2px;border-top-style:solid;'
	. 'border-top-color:rgb(140, 153, 171);border-left-width:2px;border-right-width:2px;border-bottom-width:2px;padding:25px';

$dom_of = function ( $html ) use ( $ld ) { return $ld->invoke( null, '<!DOCTYPE html><html><body>' . $html . '</body></html>' ); };
$first  = function ( $dom, $cls ) {
	if ( ! $dom ) { return null; }
	$xp = new DOMXPath( $dom );
	return $xp->query( '//*[contains(concat(" ", normalize-space(@class), " "), " ' . $cls . ' ")]' )->item( 0 );
};

echo "\n== A builder cell whose WRAPPER carries the skin still reports one\n";

// cell -> wrapper (painted) -> the card's content. The shape a page builder emits.
$html = '<div class="cell" data-sc-cs="display:block">'
	. '<div class="wrap" data-sc-cs="' . $SKIN . '">'
	. '<h4 data-sc-cs="font-size:22px">Complex Product Creation</h4>'
	. '<p data-sc-cs="font-size:16px">Products personalization adds value, but it is complicated and legacy systems hold teams back.</p>'
	. '</div></div>';
$dom  = $dom_of( $html );
$cell = $first( $dom, 'cell' );
$wrap = $first( $dom, 'wrap' );

$ok( $cell instanceof DOMElement && $wrap instanceof DOMElement, 'fixture parses' );
$own = $own_skin->invoke( null, $cell );
$ok( null === $own, 'the cell carries NO skin of its own (that is the whole problem)' );
$ok( is_array( $own_skin->invoke( null, $wrap ) ), 'the wrapper does carry it' );

$skin = $cell_skin->invoke( null, $cell );
$ok( is_array( $skin ), 'cell_card_skin() finds the wrapper\'s skin from the cell' );
if ( is_array( $skin ) ) {
	$ok( 'rgb(255, 255, 255)' === ( $skin['bg'] ?? '' ), 'fill carried: ' . ( $skin['bg'] ?? '-' ) );
	$ok( '15px' === ( $skin['radius'] ?? '' ), 'radius carried: ' . ( $skin['radius'] ?? '-' ) );
	$ok( '2px' === ( $skin['borderW'] ?? '' ), 'border width carried: ' . ( $skin['borderW'] ?? '-' ) );
	$ok( '25px' === ( $skin['padding'] ?? '' ), 'padding carried: ' . ( $skin['padding'] ?? '-' ) );
}

echo "\n== NEGATIVE: a ROW of cards must not hand its skin to the row\n";

// The hazard the descent must not create: three painted siblings under one container. If the container
// adopted a child's skin, the whole grid would be drawn as one card.
$card = function ( $t ) use ( $SKIN ) {
	return '<div class="wrap" data-sc-cs="' . $SKIN . '"><h4 data-sc-cs="font-size:22px">' . $t . '</h4>'
		. '<p data-sc-cs="font-size:16px">Body copy for ' . $t . ' that is long enough to carry real weight in the text share.</p></div>';
};
$dom = $dom_of( '<div class="row" data-sc-cs="display:flex">' . $card( 'One' ) . $card( 'Two' ) . $card( 'Three' ) . '</div>' );
$ok( null === $cell_skin->invoke( null, $first( $dom, 'row' ) ), 'a row of three painted cards reports NO skin for the row' );

echo "\n== NEGATIVE: a sibling with real content blocks the descent\n";

// Only a wrapper holding nearly all the text is descended into. A painted aside beside the body is not one.
$dom = $dom_of( '<div class="cell" data-sc-cs="display:block">'
	. '<div class="wrap" data-sc-cs="' . $SKIN . '"><span data-sc-cs="font-size:14px">Tag</span></div>'
	. '<p data-sc-cs="font-size:16px">This paragraph holds the overwhelming majority of the cell\'s text, so the small painted'
	. ' chip beside it is not the cell\'s own card skin and must not be read as one.</p></div>' );
$ok( null === $cell_skin->invoke( null, $first( $dom, 'cell' ) ), 'a small painted chip beside the body is not the cell\'s skin' );

echo "\n== The cell's OWN skin still wins when it has one\n";

$dom = $dom_of( '<div class="cell" data-sc-cs="' . $SKIN . '"><p data-sc-cs="font-size:16px">Self-painted card with its own fill and border.</p></div>' );
$skin = $cell_skin->invoke( null, $first( $dom, 'cell' ) );
$ok( is_array( $skin ) && '15px' === ( $skin['radius'] ?? '' ), 'a self-painted cell reports its own skin, unchanged' );

echo "\n== NEGATIVE: an unpainted cell stays unpainted\n";

$dom = $dom_of( '<div class="cell" data-sc-cs="display:block"><div class="wrap" data-sc-cs="display:block">'
	. '<p data-sc-cs="font-size:16px">Plain text in a plain wrapper, no fill, no border, no radius anywhere.</p></div></div>' );
$ok( null === $cell_skin->invoke( null, $first( $dom, 'cell' ) ), 'no skin anywhere means no skin invented' );

printf( "\n%s\n", $fails ? "{$fails} FAIL(S)" : 'ALL PASS' );
exit( $fails ? 1 : 0 );
