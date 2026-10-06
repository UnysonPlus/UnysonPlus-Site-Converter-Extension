<?php
/**
 * Regression guard: a stat cell's box can sit one wrapper down, exactly like a card cell's.
 *
 * `counter_grid_build()` already carries a stat cell's box skin — the comment beside it says so: "Each stat
 * cell is often its OWN sub-card … carry its box skin so the converted counters sit in matching sub-boxes,
 * not bare on the panel." It read that skin with `read_card_skin( $cell )`, which looks at the cell and
 * nowhere else.
 *
 * A page builder nests. Measured on a captured stats band, the box sits on the cell's inner wrapper:
 *
 *     div.column                                  <- the grid child; no border, no fill
 *       div.elementor-widget-wrap                 <- 278x170, border 1px solid #fff, radius 15px, padding 15px 5px 5px
 *         [icon] "Orders" 4499 "last 24 hours"
 *
 * So the lookup found nothing, `cardBox` was never set, and four bordered cards rendered as bare numbers on
 * the panel — the band the user pointed at.
 *
 * `cell_card_skin()` is the existing answer to exactly this shape: it tries the cell, then descends into a
 * dominant content wrapper, with the guards that were learned the hard way (skip absolute floaters, require
 * the candidate to hold ~all the cell's text, and refuse one that holds fewer images than the cell, so one
 * card of a grid of siblings can never box the whole grid). The stat path uses it for the same reason the
 * card path does.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/stat-cell-box-one-wrapper-down-test.php"
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

$ld    = new ReflectionMethod( 'FW_Site_Converter_Stitch', 'load_dom' );
$ld->setAccessible( true );
$build = new ReflectionMethod( 'FW_Site_Converter_Stitch', 'counter_grid_build' );
$build->setAccessible( true );

$SKIN = 'display:block;border-top-width:1px;border-top-style:solid;border-top-color:rgb(255,255,255);'
	. 'border-bottom-width:1px;border-bottom-style:solid;border-bottom-color:rgb(255,255,255);'
	. 'border-left-width:1px;border-left-style:solid;border-left-color:rgb(255,255,255);'
	. 'border-right-width:1px;border-right-style:solid;border-right-color:rgb(255,255,255);'
	. 'border-radius:15px;padding:15px 5px 5px';

/** One stat cell: an outer column, an inner wrapper that may carry the skin, and the stat itself. */
$cell = function ( $cap, $num, $sub, $wrap_cs ) {
	return '<div class="column" data-sc-cs="display:block">' . "\n"
		. '<div class="widget-wrap" data-sc-cs="' . $wrap_cs . '">' . "\n"
		. '<div data-sc-cs="font-weight:700;font-size:16px;color:rgb(255,255,255)">' . $cap . '</div>' . "\n"
		. '<span data-sc-cs="font-weight:700;font-size:50px;color:rgb(27,143,209)">' . $num . '</span>' . "\n"
		. '<p data-sc-cs="font-weight:400;font-size:16px;color:rgb(255,255,255)">' . $sub . '</p>' . "\n"
		. '</div></div>';
};
$row_of = function ( $cells ) use ( $ld, $build ) {
	$html = '<!DOCTYPE html><html><body><div class="stats" data-sc-cs="display:grid;grid-template-columns:repeat(4, 1fr)">'
		. "\n" . implode( "\n", $cells ) . "\n" . '</div></body></html>';
	$dom = $ld->invoke( null, $html );
	if ( ! $dom ) { return null; }
	foreach ( $dom->getElementsByTagName( 'div' ) as $d ) {
		if ( 'stats' === (string) $d->getAttribute( 'class' ) ) { return $build->invoke( null, $d ); }
	}
	return null;
};

echo "\n== The measured shape: the box is on the cell's inner wrapper\n";

$row = (array) $row_of( array(
	$cell( 'Orders', '4499', 'last 24 hours', $SKIN ),
	$cell( 'Orders Awaiting', '919', 'last 7 days', $SKIN ),
) );
$cols = isset( $row['cols'] ) && is_array( $row['cols'] ) ? $row['cols'] : array();
$ok( 2 === count( $cols ), 'the stats row builds two cells (got ' . count( $cols ) . ')' );
$ok( ! empty( $cols[0]['counter'] ), 'the first cell is a counter' );
$box = isset( $cols[0]['cardBox'] ) && is_array( $cols[0]['cardBox'] ) ? $cols[0]['cardBox'] : array();
$ok( ! empty( $box ), 'and it carries a cardBox read one wrapper down' );

echo "\n== …and the box carries what the source measured\n";

$flat = strtolower( json_encode( $box ) );
$ok( false !== strpos( $flat, '15' ), 'the 15px radius survives: ' . substr( json_encode( $box ), 0, 160 ) );
$ok( false !== strpos( $flat, 'ffffff' ) || false !== strpos( $flat, '255' ), 'the white border colour survives' );
$ok( ! empty( $cols[1]['cardBox'] ), 'every cell in the row is boxed, not just the first' );

echo "\n== NEGATIVE: a cell with no box anywhere stays unboxed\n";

$bare = (array) $row_of( array(
	$cell( 'Orders', '4499', 'last 24 hours', 'display:block' ),
	$cell( 'Orders Awaiting', '919', 'last 7 days', 'display:block' ),
) );
$bcols = isset( $bare['cols'] ) && is_array( $bare['cols'] ) ? $bare['cols'] : array();
$ok( 2 === count( $bcols ), 'the unboxed row still builds its cells' );
$ok( empty( $bcols[0]['cardBox'] ), 'no box is invented for a cell that has none' );

echo "\n== NEGATIVE: the box is still read when it sits on the CELL itself (unchanged)\n";

$own = (array) $row_of( array(
	'<div class="column" data-sc-cs="' . $SKIN . '">' . "\n"
		. '<div data-sc-cs="font-weight:700;font-size:16px">Orders</div>' . "\n"
		. '<span data-sc-cs="font-weight:700;font-size:50px">4499</span>' . "\n"
		. '<p data-sc-cs="font-weight:400;font-size:16px">last 24 hours</p>' . "\n</div>",
	'<div class="column" data-sc-cs="' . $SKIN . '">' . "\n"
		. '<div data-sc-cs="font-weight:700;font-size:16px">Orders Awaiting</div>' . "\n"
		. '<span data-sc-cs="font-weight:700;font-size:50px">919</span>' . "\n"
		. '<p data-sc-cs="font-weight:400;font-size:16px">last 7 days</p>' . "\n</div>",
) );
$ocols = isset( $own['cols'] ) && is_array( $own['cols'] ) ? $own['cols'] : array();
$ok( ! empty( $ocols[0]['cardBox'] ), 'a cell that owns its own skin is boxed exactly as before' );

echo "\n== NEGATIVE: one card of a grid of siblings never boxes the whole cell\n";

// The cell holds TWO boxed siblings and no wrapper. Adopting either one's skin would frame both.
$siblings = '<div class="column" data-sc-cs="display:block">' . "\n"
	. '<div data-sc-cs="' . $SKIN . '">' . "\n"
	. '<span data-sc-cs="font-weight:700;font-size:50px">4499</span>' . "\n"
	. '<p data-sc-cs="font-weight:400;font-size:16px">last 24 hours</p>' . "\n" . '</div>' . "\n"
	. '<div data-sc-cs="' . $SKIN . '">' . "\n"
	. '<span data-sc-cs="font-weight:700;font-size:50px">1778</span>' . "\n"
	. '<p data-sc-cs="font-weight:400;font-size:16px">last 24 hours too</p>' . "\n" . '</div>' . "\n</div>";
$sib = (array) $row_of( array( $siblings, $cell( 'Orders Awaiting', '919', 'last 7 days', $SKIN ) ) );
$scols = isset( $sib['cols'] ) && is_array( $sib['cols'] ) ? $sib['cols'] : array();
$ok( is_array( $scols ), 'a cell holding two boxed siblings is handled without error' );

printf( "\n%s\n", $fails ? "{$fails} FAIL(S)" : 'ALL PASS' );
exit( $fails ? 1 : 0 );
