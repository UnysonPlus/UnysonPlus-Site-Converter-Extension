<?php
/**
 * Regression guard: a stat cell's icon is part of the stat, not decoration to drop.
 *
 * `counter_grid_build()` reduced each cell to its number and label. Anything else in the cell was discarded,
 * including the glyph above the number. Measured on a captured stats band, every one of the four cards opens
 * with a 35x35 inline SVG — a cart, a clipboard, a printer, a truck — and the converted band had none of
 * them: four bordered boxes whose top third was empty.
 *
 * The cell already knows how to answer this question. `step_icon()` is the general reader used by the steps
 * recognizer: it returns a named library glyph (`{lucide}`) when the source names one, the raw markup
 * (`{svg}`) when it does not, or an image source (`{img}`), and null when the cell carries no glyph at all.
 * Reusing it keeps one definition of "what counts as this cell's icon" instead of inventing a second.
 *
 * What this guards, beyond "the icon survives":
 *
 *   - The icon is carried only when the cell HAS one. A stat band of bare numbers must not gain a glyph.
 *   - An empty <svg> shell is not an icon. The capture sometimes keeps the element and drops its geometry;
 *     adopting it would draw nothing while claiming the cell is iconned.
 *   - The icon's measured SIZE rides along, so a 35px glyph is not rendered at the shortcode's default.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/stat-cell-keeps-its-icon-test.php"
 *
 * Exit code 0 = all PASS, 1 = at least one FAIL.
 */

if ( ! class_exists( 'FW_Site_Converter_Stitch' ) || ! class_exists( 'FW_Site_Converter_Mapper' ) ) {
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
$lone  = new ReflectionMethod( 'FW_Site_Converter_Mapper', 'n_lone_icon' );
$lone->setAccessible( true );

// The capture does NOT stamp an inline <svg> — measured, it carries no data-sc-cs at all. The glyph's size and
// colour sit on the element WRAPPING it, which is where an icon font's treatment lives. The fixture mirrors
// that, because a fixture that stamps the svg directly cannot catch the bug this guards.
$GLYPH = '<div class="icon-box" data-sc-cs="color:rgb(255,255,255);font-size:35px;height:35px">'
	. '<svg class="e-font-icon-svg e-fas-cart-arrow-down" viewBox="0 0 576 512"><path d="M504 12h-34l-3 27"></path></svg>'
	. '</div>';

$GLYPH_RAW = '<svg class="e-font-icon-svg e-fas-cart-arrow-down" viewBox="0 0 576 512"><path d="M504 12h-34l-3 27"></path></svg>';

$cell = function ( $glyph, $cap, $num, $sub ) {
	return '<div class="column" data-sc-cs="display:block">' . "\n"
		. '<div class="widget-wrap" data-sc-cs="display:block;border-radius:15px">' . "\n"
		. ( '' !== $glyph ? $glyph . "\n" : '' )
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

echo "\n== The measured shape: a glyph above the number\n";

$row  = (array) $row_of( array(
	$cell( $GLYPH, 'Orders', '4499', 'last 24 hours' ),
	$cell( $GLYPH, 'Orders Awaiting', '919', 'last 7 days' ),
) );
$cols = isset( $row['cols'] ) && is_array( $row['cols'] ) ? $row['cols'] : array();
$ok( 2 === count( $cols ), 'the stats row still builds its cells (got ' . count( $cols ) . ')' );
$ok( ! empty( $cols[0]['counter'] ), 'the cell is still a counter — the glyph does not displace the stat' );
$icon = isset( $cols[0]['icon'] ) && is_array( $cols[0]['icon'] ) ? $cols[0]['icon'] : array();
$ok( ! empty( $icon ), 'and the cell carries its icon' );
$ok( ! empty( $icon['svg'] ) || ! empty( $icon['lucide'] ) || ! empty( $icon['img'] ),
	'in one of the shapes step_icon speaks: ' . implode( ',', array_keys( $icon ) ) );
$ok( ! empty( $cols[1]['icon'] ), 'every cell keeps its own glyph, not just the first' );

echo "\n== The glyph's measured size rides along\n";

$ok( '35' === (string) ( $cols[0]['iconSize'] ?? '' ), 'the stamped 35px is carried: ' . var_export( $cols[0]['iconSize'] ?? null, true ) );
$ok( '#ffffff' === strtolower( (string) ( $cols[0]['iconColor'] ?? '' ) ), 'as is its colour: ' . var_export( $cols[0]['iconColor'] ?? null, true ) );

echo "\n== NEGATIVE: the glyph's colour is its OWN, not the panel's inherited ink\n";

// One box beyond the glyph's is the panel's inherited dark ink (`color:rgb(34,34,34)` on a dark section, at the
// panel's own 18px). The read takes the FIRST stamped box out from the glyph and stops: climbing past it to the
// inherited value rendered four white icons black on the live page, at the wrong size.
$inherited = '<div class="column" data-sc-cs="display:block;color:rgb(34,34,34);font-size:18px">' . "\n"
	. '<div class="widget-wrap" data-sc-cs="display:block;color:rgb(34,34,34);font-size:18px">' . "\n"
	. $GLYPH . "\n"
	. '<div data-sc-cs="font-weight:700;font-size:16px">Orders</div>' . "\n"
	. '<span data-sc-cs="font-weight:700;font-size:50px">4499</span>' . "\n"
	. '<p data-sc-cs="font-weight:400;font-size:16px">last 24 hours</p>' . "\n"
	. '</div></div>';
$inh   = (array) $row_of( array( $inherited, $cell( $GLYPH, 'Orders Awaiting', '919', 'last 7 days' ) ) );
$icol  = isset( $inh['cols'] ) && is_array( $inh['cols'] ) ? $inh['cols'] : array();
$ok( '#ffffff' === strtolower( (string) ( $icol[0]['iconColor'] ?? '' ) ),
	'the glyph keeps its own white, not the panel\'s #222222: ' . var_export( $icol[0]['iconColor'] ?? null, true ) );
$ok( '35' === (string) ( $icol[0]['iconSize'] ?? '' ), 'and its own 35px, not the panel\'s 18px' );

echo "\n== NEGATIVE: a cell with no glyph gains none\n";

$bare  = (array) $row_of( array(
	$cell( '', 'Orders', '4499', 'last 24 hours' ),
	$cell( '', 'Orders Awaiting', '919', 'last 7 days' ),
) );
$bcols = isset( $bare['cols'] ) && is_array( $bare['cols'] ) ? $bare['cols'] : array();
$ok( 2 === count( $bcols ), 'the glyphless row still builds' );
$ok( empty( $bcols[0]['icon'] ), 'and no icon is invented for it' );
$ok( ! empty( $bcols[0]['counter'] ), 'while the stat itself is untouched' );

echo "\n== The mapper turns the carried shape into a real icon node\n";

$node = $lone->invoke( null, array( 'svg' => $GLYPH, 'size' => 35, 'color' => '#ffffff', 'center' => true ) );
$ok( is_array( $node ), 'an icon node is produced' );
$j = json_encode( $node );
$ok( is_array( $node ) && 'icon' === (string) ( $node['shortcode'] ?? '' ), 'and it is the native icon widget' );
$ok( false !== strpos( (string) $j, 'cart-arrow-down' ), 'carrying the source glyph' );
$ok( false !== strpos( (string) $j, '35' ), 'at the measured size' );


echo "
== An inline glyph INHERITS its colour, or it renders black
";

// An <svg> path with no fill of its own defaults to BLACK — SVG's own rule, not the theme's. The source page
// escapes this because the builder's stylesheet sets `fill: currentColor` on its icon svgs; the converted page
// has no such rule, so four white glyphs rendered black while their computed `color` was correctly white.
// Measured on the live page: svg color rgb(255,255,255), svg fill rgb(0,0,0).
$inh_node = $lone->invoke( null, array( 'svg' => $GLYPH_RAW, 'size' => 35, 'color' => '#ffffff' ) );
$ij = json_encode( $inh_node );
$ok( false !== strpos( (string) $ij, 'currentColor' ), 'a fill-less glyph is made to inherit the icon colour' );

// NEGATIVE: a glyph that states its OWN fill is left exactly as the source drew it.
$painted  = '<svg viewBox="0 0 24 24" fill="#e4572e"><path d="M4 4h16v16H4z"></path></svg>';
$pnode    = $lone->invoke( null, array( 'svg' => $painted, 'size' => 35 ) );
$pj       = json_encode( $pnode );
$ok( false !== strpos( (string) $pj, 'e4572e' ), 'a glyph with its own fill keeps it' );
$ok( false === strpos( (string) $pj, 'currentColor' ), '…and is not overridden' );

// NEGATIVE: the fill may sit on the PATH rather than the svg — equally the source's own choice.
$pathfill = '<svg viewBox="0 0 24 24"><path fill="#1b8fd1" d="M4 4h16v16H4z"></path></svg>';
$fnode    = $lone->invoke( null, array( 'svg' => $pathfill, 'size' => 35 ) );
$ok( false === strpos( (string) json_encode( $fnode ), 'currentColor' ), 'a path-level fill is respected too' );

$none = $lone->invoke( null, array( 'svg' => '', 'fa' => '' ) );
$ok( null === $none, 'and nothing at all yields no node' );

printf( "\n%s\n", $fails ? "{$fails} FAIL(S)" : 'ALL PASS' );
exit( $fails ? 1 : 0 );
