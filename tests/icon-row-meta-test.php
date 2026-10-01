<?php
/**
 * Regression guard: a counter sharing the ICON'S row is not the card's overline.
 *
 * A services card heads itself with one flex row -- `justify-between`, the icon at the left end and a small
 * `01 / 3` counter at the right -- and only then the title and copy. `card_eyebrow()` walks every leaf that
 * precedes the heading and accepts it on SIZE (<= 13px) or uppercase, so the counter qualified: it was filed
 * as the card's overline and rendered on a line ABOVE the title, which is not where the source draws it.
 *
 * The icon box has no slot for a label beside the icon, and the corpus says it should not grow one -- across
 * 117 captures the shape appears on ONE site, twice, both the same string. So the counter is dropped.
 *
 * The test that separates the two cases is POSITION, not content: `justify-between` pushes the icon and the
 * label to OPPOSITE ends, which is an icon row. An icon and a label sitting together in a flex row is a chip,
 * and that label really is the eyebrow -- the NEGATIVE below pins that it still arrives.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/icon-row-meta-test.php"
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

$INK = 'color:lab(25.76 0 0);font-family:Inter, sans-serif;font-size:16px;line-height:24px;text-align:start;';
$SVG = '<svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" class="lucide lucide-laptop"><path d="M18 5a2 2 0 0 1 2 2v8.5"></path></svg>';

/** card_eyebrow() over a services card whose header row carries $row_cs. */
$eyebrow = function ( $row_cs, $label, $label_cls = 'text-xs font-semibold' ) use ( $INK, $SVG ) {
	$html = '<!DOCTYPE html><html><body><div class="card" data-sc-cs="' . $INK . 'display:block">'
		. '<div class="hdr" data-sc-cs="' . $row_cs . '">' . $SVG
		. '<div class="' . $label_cls . '" data-sc-cs="font-size:12px;font-weight:600;line-height:16px;display:block">' . $label . '</div>'
		. '</div>'
		. '<h3 data-sc-cs="font-size:20px;font-weight:600;line-height:28px">Laptop Reparatur</h3>'
		. '<p data-sc-cs="font-size:14px;line-height:22.75px">Display, Akku, Tastatur, Wasserschaden, Mainboard.</p>'
		. '</div></body></html>';
	$dom = new DOMDocument();
	libxml_use_internal_errors( true );
	$dom->loadHTML( $html, LIBXML_NOERROR | LIBXML_NOWARNING );
	libxml_clear_errors();
	$xp   = new DOMXPath( $dom );
	$cell = $xp->query( '//div[@class="card"]' )->item( 0 );
	$h    = $dom->getElementsByTagName( 'h3' )->item( 0 );
	$m    = new ReflectionMethod( 'FW_Site_Converter_Stitch', 'card_eyebrow' );
	$m->setAccessible( true );
	$r = $m->invoke( null, $cell, $h );
	return is_array( $r ) ? (string) ( $r['text'] ?? '' ) : '';
};

$SPREAD = $INK . 'display:flex;justify-content:space-between;align-items:center;margin:0px 0px 16px';
$TOGETHER = $INK . 'display:flex;justify-content:normal;align-items:center;gap:8px;margin:0px 0px 16px';

echo "\n== A counter at the far end of the icon's row is not an eyebrow\n";

$ok( '' === $eyebrow( $SPREAD, '01 / 3' ),
	'`justify-between` icon + `01 / 3` yields NO overline (got "' . $eyebrow( $SPREAD, '01 / 3' ) . '")' );

$ok( '' === $eyebrow( $SPREAD, 'Schritt 2' ),
	'...and so is `Schritt 2` — a word plus an index is the same counter (got "' . $eyebrow( $SPREAD, 'Schritt 2' ) . '")' );

echo "
== NEGATIVE: a label in that slot that SAYS something is kept
";

// Dropping by position alone would one day delete a price. The shape test is what stops it: only a bare
// index / index-over-total is discarded, and anything carrying information still rides the overline.
foreach ( array( 'ab 49 EUR', '15 min', 'NEU' ) as $real ) {
	$ok( $real === $eyebrow( $SPREAD, $real ),
		'NEGATIVE: "' . $real . '" is not a counter, so it survives the icon row (got "' . $eyebrow( $SPREAD, $real ) . '")' );
}

echo "\n== NEGATIVE: an icon+label CHIP still gives its eyebrow\n";

$ok( 'FEATURED' === $eyebrow( $TOGETHER, 'FEATURED' ),
	'NEGATIVE: a flex row with no `justify-between` is a chip, and its label is the eyebrow (got "' . $eyebrow( $TOGETHER, 'FEATURED' ) . '")' );

echo "\n== NEGATIVE: a plain eyebrow above the title is untouched\n";

$ok( 'SERVICE' === $eyebrow( $INK . 'display:block;margin:0px 0px 16px', 'SERVICE' ),
	'NEGATIVE: a non-flex header row keeps its eyebrow, as it always did (got "' . $eyebrow( $INK . 'display:block;margin:0px 0px 16px', 'SERVICE' ) . '")' );

echo $fails ? "\n\xe2\x9c\x97 $fails FAILED\n" : "\n\xe2\x9c\x93 ALL PASS - the icon's row keeps its counter out of the overline\n";
exit( $fails ? 1 : 0 );
