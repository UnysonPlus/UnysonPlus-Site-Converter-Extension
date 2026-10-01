<?php
/**
 * Regression guard: a wrapping strip of short labels stays ONE row, and a two-value `gap` is read per axis.
 *
 * 1. THE STRIP. `<div class="flex flex-wrap justify-center gap-x-10 gap-y-4">` holding nineteen brand names is
 *    a wrapping row. is_chip_row() rejected it twice over — an `<a>` child was an outright no, and the
 *    12-child cap is well under nineteen — so no recognizer claimed it and collect_blocks flattened it into
 *    NINETEEN separate stacked text blocks, left-aligned, where the source shows two centred rows.
 *    Linking is not the test: measured on a real strip, 1 of 19 children was a link and the rest were spans
 *    (a brand list links the makes it has pages for). The SHAPE is the test.
 *
 * 2. THE GAP. `gap` is row-gap then column-gap. grid_gap_px() took the FIRST number, so a strip stamped
 *    `gap:16px 40px` was laid out with 16px between names instead of 40 — fifteen fitted on a line where the
 *    source fits eleven. The same helper feeds every row and grid, so this was wrong everywhere a source gave
 *    two values: fixing it moved a steps band from 155% off its source's geometry to 8%.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/link-strip-and-gap-test.php"
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

$el_of = function ( $html, $sel_class ) {
	$ld = new ReflectionMethod( 'FW_Site_Converter_Stitch', 'load_dom' );
	$ld->setAccessible( true );
	$dom = $ld->invoke( null, '<!DOCTYPE html><html><body>' . $html . '</body></html>' );
	if ( ! $dom ) { return null; }
	foreach ( $dom->getElementsByTagName( 'div' ) as $d ) {
		if ( false !== strpos( (string) $d->getAttribute( 'class' ), $sel_class ) ) { return $d; }
	}
	return null;
};
$is_chip = function ( $el ) {
	$m = new ReflectionMethod( 'FW_Site_Converter_Stitch', 'is_chip_row' );
	$m->setAccessible( true );
	return (bool) $m->invoke( null, $el );
};
$gap_of = function ( $el ) {
	$m = new ReflectionMethod( 'FW_Site_Converter_Stitch', 'grid_gap_px' );
	$m->setAccessible( true );
	return (float) $m->invoke( null, $el );
};

$INK  = 'color:lab(5.26 0 0);font-family:Inter, sans-serif;font-size:18px;line-height:28px;';
$ROWCS = $INK . 'display:flex;gap:16px 40px;justify-content:center;flex-direction:row';

/* 19 brands: the first links, the rest are spans — exactly the measured shape. */
$BRANDS = array( 'Apple', 'Lenovo', 'HP', 'Dell', 'Asus', 'Acer', 'MSI', 'Samsung', 'Toshiba', 'Sony',
	'Huawei', 'Microsoft Surface', 'Medion', 'Fujitsu', 'XMG', 'Schenker', 'Razer', 'Gigabyte', 'LG' );
$kids = '';
foreach ( $BRANDS as $i => $b ) {
	$kids .= 0 === $i
		? '<a href="/macbook-reparatur" data-sc-cs="' . $INK . 'display:inline">' . $b . '</a>'
		: '<span data-sc-cs="' . $INK . 'display:inline">' . $b . '</span>';
}
$STRIP = '<div class="flex flex-wrap justify-center gap-x-10 gap-y-4" data-sc-cs="' . $ROWCS . '">' . $kids . '</div>';

echo "\n== A wrapping strip of short labels is ONE row\n";

$el = $el_of( '<section data-sc-cs="' . $INK . 'display:block">' . $STRIP . '</section>', 'flex flex-wrap justify-center' );
$ok( null !== $el, 'the strip element is found' );
$ok( $is_chip( $el ), 'nineteen short labels in a wrapping row are claimed as a chip row, not flattened' );

echo "\n== A two-value gap is read per axis\n";

$ok( 40.0 === $gap_of( $el ),
	'`gap:16px 40px` gives the 40px COLUMN gutter for a horizontal row (got ' . $gap_of( $el ) . ')' );

$one = $el_of( '<section data-sc-cs="' . $INK . 'display:block"><div class="flex flex-wrap x1" data-sc-cs="' . $INK . 'display:flex;gap:24px">'
	. '<span data-sc-cs="' . $INK . 'display:inline">One</span><span data-sc-cs="' . $INK . 'display:inline">Two</span></div></section>', 'x1' );
$ok( 24.0 === $gap_of( $one ),
	'NEGATIVE: a SINGLE value still applies to both axes (got ' . $gap_of( $one ) . ')' );

echo "\n== NEGATIVE: a strip must actually WRAP\n";

$nowrap = $el_of( '<section data-sc-cs="' . $INK . 'display:block"><div class="flex justify-center nw1" data-sc-cs="' . $ROWCS . '">' . $kids . '</div></section>', 'nw1' );
$ok( ! $is_chip( $nowrap ),
	'NEGATIVE: a non-wrapping row of the same labels is not a strip — it is a nav or a layout row' );

echo "\n== NEGATIVE: the chrome has its own paths\n";

$in_nav = $el_of( '<nav data-sc-cs="' . $INK . 'display:block">' . $STRIP . '</nav>', 'flex flex-wrap justify-center' );
$ok( ! $is_chip( $in_nav ),
	'NEGATIVE: the same strip inside <nav> is left to the chrome detectors' );

echo "\n== NEGATIVE: a row of CARDS is not a strip\n";

$cards = '<div class="flex flex-wrap cd1" data-sc-cs="' . $ROWCS . '">';
for ( $i = 0; $i < 6; $i++ ) {
	$cards .= '<a href="/p' . $i . '" data-sc-cs="' . $INK . 'display:block"><img src="/p' . $i . '.jpg" alt=""><h3 data-sc-cs="font-size:20px">Product ' . $i . '</h3></a>';
}
$cards .= '</div>';
$cel = $el_of( '<section data-sc-cs="' . $INK . 'display:block">' . $cards . '</section>', 'cd1' );
$ok( ! $is_chip( $cel ),
	'NEGATIVE: links holding an image and a heading are CARDS, not chips' );

echo "\n== NEGATIVE: too few items is not a strip\n";

$few = $el_of( '<section data-sc-cs="' . $INK . 'display:block"><div class="flex flex-wrap fw2" data-sc-cs="' . $ROWCS . '">'
	. '<a href="/a" data-sc-cs="' . $INK . 'display:inline">Alpha</a><a href="/b" data-sc-cs="' . $INK . 'display:inline">Beta</a>'
	. '<a href="/c" data-sc-cs="' . $INK . 'display:inline">Gamma</a></div></section>', 'fw2' );
$ok( ! $is_chip( $few ),
	'NEGATIVE: three links are a button row, not a brand strip — the strip rule needs >= 6' );

echo $fails ? "\n\xe2\x9c\x97 $fails FAILED\n" : "\n\xe2\x9c\x93 ALL PASS - a strip stays a row, and a gap is read per axis\n";
exit( $fails ? 1 : 0 );
