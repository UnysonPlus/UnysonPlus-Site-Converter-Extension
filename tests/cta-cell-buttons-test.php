<?php
/**
 * Regression guard: an icon button inside a GRID CELL stays a button.
 *
 * A CTA band is routinely written as a two-column grid — copy on the left, the actions on the right:
 *
 *     <div class="grid grid-cols-2">
 *       <div><h2>…</h2><p>…</p></div>
 *       <div class="flex gap-3"><a class="btn btn-primary"><svg/>089 …</a><a class="btn"><svg/>WhatsApp</a></div>
 *     </div>
 *
 * The right-hand CELL took the VERBATIM path and each button was rebuilt as a flexbox of an icon and a text
 * block, with the anchor dropped — two things that still looked like buttons and linked nowhere.
 *
 * The cause is one line in cell_is_decomposable(): a guard that returns early when the cell contains any
 * `img|video|picture|iframe|svg|canvas`, so that a lone image tile keeps its verbatim path. It counted the
 * `<svg>` inside the CTA's own label, so the cell bailed BEFORE reaching the "cell is ONLY buttons" branch
 * written for exactly this shape. Media inside a button is an ICON.
 *
 * The capability was never missing: `buttons_from_cell()` returns both buttons when called directly, and
 * `collect_blocks()` on the same cell returns `button button`. Only the gate was wrong — which is why the same
 * button converts correctly when it is bare, or in a plain flex row, and only fails inside a grid cell. Those
 * two shapes are the NEGATIVES below.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/cta-cell-buttons-test.php"
 *
 * Exit code 0 = all PASS, 1 = at least one FAIL.
 */

if ( ! class_exists( 'FW_Site_Converter_Sources' ) ) {
	fwrite( STDERR, "FAIL: site-converter not loaded (run inside a WP install with the plugin active)\n" );
	exit( 1 );
}

$fails = 0;
$ok    = function ( $cond, $msg ) use ( &$fails ) {
	if ( $cond ) { echo "  \xe2\x9c\x93 $msg\n"; } else { $fails++; echo "  \xe2\x9c\x97 FAIL: $msg\n"; }
};

$INK = 'color:lab(100 0 0);font-family:Inter, sans-serif;font-size:15.2px;font-weight:600;line-height:22.8px;text-align:start;';
$SVG = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" class="lucide lucide-phone"><path d="M13 16a1 1 0 0 0 1 0"></path></svg>';

$btn = function ( $href, $label, $skin ) use ( $INK, $SVG ) {
	return '<a href="' . $href . '" class="btn" data-sc-cs="' . $INK . $skin
		. 'display:inline-flex;align-items:center;gap:8px;padding:0px 20px;height:46px;border-radius:8px">'
		. $SVG . '<span data-sc-cs="' . $INK . 'display:inline">' . $label . '</span></a>';
};
$PAIR = $btn( 'tel:08954244434', '089 542 444 34', 'background-color:lab(27.79 -4.5 -26.68);' )
	. $btn( 'https://wa.me/498954244434', 'WhatsApp', 'border-top-width:1px;' );

/** Every button node of the converted page, as label => link. */
$buttons = function ( $body ) use ( $INK ) {
	$html = '<!DOCTYPE html><html><head><title>CTA</title></head><body><main>'
		. '<section data-sc-cs="' . $INK . 'display:block;padding:56px 24px">' . $body . '</section></main></body></html>';
	$res  = FW_Site_Converter_Sources::build_from_html( $html, 'CTA', array( 'dynamic_chrome' => true ) );
	$out  = array();
	$walk = function ( $nodes ) use ( &$walk, &$out ) {
		foreach ( (array) $nodes as $n ) {
			if ( ! is_array( $n ) ) { continue; }
			if ( 'button' === ( $n['shortcode'] ?? '' ) ) { $out[ (string) ( $n['atts']['label'] ?? '' ) ] = (string) ( $n['atts']['link'] ?? '' ); }
			$walk( $n['_items'] ?? array() );
		}
	};
	$walk( $res['files']['pages.json']['pages'][0]['builder'] ?? array() );
	return $out;
};

$COPY = '<h2 data-sc-cs="font-size:30px;font-weight:700">Laptop oder PC defekt?</h2>'
	. '<p data-sc-cs="font-size:16px;line-height:26px">Kommen Sie ohne Termin in unserer Werkstatt vorbei, wir pruefen das Geraet gern.</p>';

echo "\n== Icon buttons in a grid CELL stay buttons\n";

$grid = '<div data-sc-cs="' . $INK . 'display:grid;grid-template-columns:1fr 1fr;gap:24px">'
	. '<div data-sc-cs="' . $INK . 'display:block">' . $COPY . '</div>'
	. '<div class="flex flex-wrap gap-3" data-sc-cs="' . $INK . 'display:flex;gap:12px;justify-content:flex-end;height:46px">' . $PAIR . '</div>'
	. '</div>';
$g = $buttons( $grid );

$ok( count( $g ) >= 2,
	'both actions convert to button nodes (got ' . count( $g ) . ': ' . implode( ', ', array_keys( $g ) ) . ')' );
$ok( 'tel:08954244434' === ( $g['089 542 444 34'] ?? '' ),
	'the phone button keeps its tel: link (got "' . ( $g['089 542 444 34'] ?? '' ) . '")' );
$ok( 'https://wa.me/498954244434' === ( $g['WhatsApp'] ?? '' ),
	'the WhatsApp button keeps its href (got "' . ( $g['WhatsApp'] ?? '' ) . '")' );

echo "\n== NEGATIVE: the shapes that always worked still work\n";

$bare = $buttons( $COPY . $PAIR );
$ok( 'tel:08954244434' === ( $bare['089 542 444 34'] ?? '' ) && 'https://wa.me/498954244434' === ( $bare['WhatsApp'] ?? '' ),
	'NEGATIVE: buttons directly in the section are unaffected' );

$row = $buttons( $COPY . '<div data-sc-cs="' . $INK . 'display:flex;gap:12px;height:46px">' . $PAIR . '</div>' );
$ok( 'tel:08954244434' === ( $row['089 542 444 34'] ?? '' ) && 'https://wa.me/498954244434' === ( $row['WhatsApp'] ?? '' ),
	'NEGATIVE: buttons in a plain flex row are unaffected' );

echo "\n== NEGATIVE: a real MEDIA cell still keeps its verbatim path\n";

// The guard exists for this: a lone photo tile beside the copy must NOT be decomposed. Its <img> is not
// inside a button, so the early return still fires and no button is invented.
$media = '<div data-sc-cs="' . $INK . 'display:grid;grid-template-columns:1fr 1fr;gap:24px">'
	. '<div data-sc-cs="' . $INK . 'display:block">' . $COPY . '</div>'
	. '<div data-sc-cs="' . $INK . 'display:block;height:320px"><img src="/shop.jpg" alt="" data-sc-cs="display:block;width:540px;height:320px;object-fit:cover"></div>'
	. '</div>';
$m = $buttons( $media );
$ok( empty( $m ),
	'NEGATIVE: an image-only cell yields no buttons and is left alone (got ' . count( $m ) . ')' );

echo $fails ? "\n\xe2\x9c\x97 $fails FAILED\n" : "\n\xe2\x9c\x93 ALL PASS - an icon button is a button wherever the grid puts it\n";
exit( $fails ? 1 : 0 );
