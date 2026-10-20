<?php
/**
 * Regression guard: a footer link column laid out as a GRID stays a grid, and an address keeps its line break.
 *
 * Two losses in the same captured footer column pair.
 *
 * 1. THE LINK GRID. The source's Menu column holds its four links in `<div class="grid grid-cols-2 gap-2">`,
 *    so they read two-across in two rows. `detect_footer_columns()` recorded the links but nothing about the
 *    container holding them, so the column emitted one stack of four and the footer ran taller than its
 *    source. The theme's column renderer puts each row in an `<li>` that carries the element's own custom CSS
 *    class, so the two-up layout rides on the ITEMS — no `:has()`, no `nth-child`, and no dependence on the
 *    column's position in the bar. The source grid is row-major and so is an inline-block run, so the reading
 *    order is preserved.
 *
 * 2. THE ADDRESS LINE BREAK. The source writes
 *    `<span>1250 Market Street, Suite 400<br>San Francisco, CA 94102</span>`; the row text was flattened with
 *    the `<br>` collapsed to a space, so a two-line address rendered as one long line that widened the column.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/footer-column-keeps-its-link-grid-test.php"
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

$cols = new ReflectionMethod( 'FW_Site_Converter_Stitch', 'detect_footer_columns' );
$cols->setAccessible( true );
$g2c = new ReflectionMethod( 'FW_Site_Converter_Stitch', 'footer_group_to_column' );
$g2c->setAccessible( true );

$html = '<!DOCTYPE html><html><body><main><section data-sc-cs="background-color:rgb(255,255,255)"><h1>Home</h1></section></main>'
	. '<footer><div data-sc-cs="background-color:rgb(247,245,255);padding-top:80px">'
	. '<div data-sc-cs="display:grid;grid-template-columns:400px 400px">'
	. '<div><h3 data-sc-cs="font-size:12px">Office</h3>'
		. '<div data-sc-cs="display:block">'
		. '<div data-sc-cs="display:flex;align-items:flex-start">'
		. '<svg class="lucide lucide-map-pin"><path d="M20 10"></path></svg>'
		. '<span data-sc-cs="display:inline">1250 Market Street, Suite 400<br>San Francisco, CA 94102</span>'
		. '</div>'
		. '<div data-sc-cs="display:flex;align-items:center">'
		. '<svg class="lucide lucide-phone"><path d="M22 16"></path></svg>'
		. '<a href="tel:4157892200" data-sc-cs="font-weight:600">415-789-2200</a>'
		. '</div>'
		. '<div data-sc-cs="display:flex;align-items:center">'
		. '<svg class="lucide lucide-mail"><rect width="20"></rect></svg>'
		. '<a href="mailto:hello@example-domain.test" data-sc-cs="font-weight:400">hello@example-domain.test</a>'
		. '</div></div></div>'
	. '<div><h3 data-sc-cs="font-size:12px">Menu</h3>'
		. '<div data-sc-cs="display:grid;grid-template-columns:140px 140px;gap:8px 8px">'
		. '<a href="/about-us" data-sc-cs="font-weight:400">About Us</a>'
		. '<a href="/contact" data-sc-cs="font-weight:400">Contact</a>'
		. '<a href="/costs" data-sc-cs="font-weight:400">Costs &amp; Fees</a>'
		. '<a href="/faq" data-sc-cs="font-weight:400">FAQ</a>'
		. '</div></div>'
	. '</div></div></footer></body></html>';

$groups = (array) $cols->invoke( null, $html );
$menu = null; $office = null;
foreach ( $groups as $g ) {
	$t = trim( (string) ( $g['title'] ?? '' ) );
	if ( 'Menu' === $t )   { $menu = $g; }
	if ( 'Office' === $t ) { $office = $g; }
}

echo "\n== The link column's grid is recorded\n";

$ok( is_array( $menu ), 'the Menu column is detected' );
if ( is_array( $menu ) ) {
	$ok( 2 === (int) ( $menu['links_grid'] ?? 0 ), 'its two source tracks are recorded (got ' . (int) ( $menu['links_grid'] ?? 0 ) . ')' );
}

echo "\n== …and each emitted row carries the two-up class\n";

if ( is_array( $menu ) ) {
	$els = (array) $g2c->invoke( null, $menu );
	$items = array_values( array_filter( $els, function ( $e ) { return 'list_item' === ( $e['element_type']['element'] ?? '' ); } ) );
	$ok( count( $items ) >= 4, 'all four links are emitted as rows (' . count( $items ) . ')' );
	$classed = 0;
	foreach ( $items as $it ) { if ( false !== strpos( (string) ( $it['element_css_class'] ?? '' ), 'fcol-2up' ) ) { $classed++; } }
	$ok( $classed === count( $items ) && $classed > 0, "every row carries fcol-2up ({$classed}/" . count( $items ) . ')' );
}

echo "\n== The CSS for that layout is emitted\n";

$css_fn = new ReflectionMethod( 'FW_Site_Converter_Stitch', 'footer_link_grid_css' );
$css_fn->setAccessible( true );
$css = (string) $css_fn->invoke( null );
$ok( false !== strpos( $css, 'fcol-2up' ), 'a rule for the two-up run exists: ' . mb_substr( trim( $css ), 0, 140 ) );
$ok( false !== strpos( $css, 'inline-block' ) || false !== strpos( $css, 'width:' ), '…laying the rows out two-across' );

echo "\n== The address keeps its line break\n";

$ok( is_array( $office ), 'the Office column is detected' );
if ( is_array( $office ) ) {
	// The ROW carries both forms: `text` folded to one line (written when these rows emitted the single-line
	// icon_text element) and `html` with the break intact. The EMITTER is what must pick the right one.
	$els3 = (array) $g2c->invoke( null, $office );
	$addr = '';
	foreach ( $els3 as $e ) {
		$t = (string) ( $e['element_type']['list_item']['li_text'] ?? '' );
		if ( false !== strpos( $t, '1250 Market' ) ) { $addr = $t; break; }
	}
	$ok( '' !== $addr, 'the address row is emitted' );
	$ok( false !== stripos( $addr, '<br' ), 'and it keeps the break: "' . mb_substr( $addr, 0, 70 ) . '"' );
	$ok( false === strpos( $addr, 'Suite 400, San Francisco' ), 'rather than the comma-folded single line' );
}

echo "\n== NEGATIVE: a single-column link list gets no grid class\n";

$stacked = str_replace( 'display:grid;grid-template-columns:140px 140px;gap:8px 8px', 'display:flex;flex-direction:column;gap:8px', $html );
$g2 = (array) $cols->invoke( null, $stacked );
foreach ( $g2 as $g ) {
	if ( 'Menu' !== trim( (string) ( $g['title'] ?? '' ) ) ) { continue; }
	$ok( (int) ( $g['links_grid'] ?? 0 ) < 2, 'a stacked column records no grid (got ' . (int) ( $g['links_grid'] ?? 0 ) . ')' );
	$els2 = (array) $g2c->invoke( null, $g );
	$any = false;
	foreach ( $els2 as $e ) { if ( false !== strpos( (string) ( $e['element_css_class'] ?? '' ), 'fcol-' ) ) { $any = true; } }
	$ok( ! $any, '…and its rows carry no two-up class' );
}

printf( "\n%s\n", $fails ? "{$fails} FAIL(S)" : 'ALL PASS' );
exit( $fails ? 1 : 0 );
