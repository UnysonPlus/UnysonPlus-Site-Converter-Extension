<?php
/**
 * Regression guard: the icon POSITION is inferred from the icon the card actually renders.
 *
 * `detect_icon_layout()` reads the source card's flex geometry to pick icon_box's `style` — `top-title` when
 * the icon sits above the heading, `inline-left/right` when it shares a row with it. It looked for the card's
 * icon in this order: a lucide glyph, then any `<svg>`/`<i>`, and only last an `<img>` whose src is an SVG.
 *
 * That order disagrees with what the mapper renders. `n_icon_box()` REPLACES the icon with the card's
 * `imgIcon` whenever there is one, so on a card carrying both an illustration and a lucide glyph the layout
 * was computed for one icon and applied to the other.
 *
 * Measured on a captured card:
 *
 *     <a class="group flex flex-col">
 *       <div class="h-36 flex items-end justify-center mb-4"><img src="…/illustration.svg"></div>
 *       <div class="flex items-center justify-between"><h3>Traffic accident</h3><svg class="lucide lucide-chevron-right"></div>
 *     </a>
 *
 * The chevron won the search. It shares a horizontal row with the heading and follows it in DOM order, so the
 * card was built `inline-right` — and the ILLUSTRATION, which is what actually renders, was placed beside the
 * label instead of above it. Eight cards in the band read as a label with a shrunken picture next to it where
 * the source stacks a 144px illustration over a label row.
 *
 * So the image icon is looked for FIRST: it is the icon the card renders, and a chevron affordance beside the
 * title is not the card's icon at all. A card whose only icon IS a lucide glyph is unaffected.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/card-icon-layout-follows-rendered-icon-test.php"
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

$fn = new ReflectionMethod( 'FW_Site_Converter_Stitch', 'detect_icon_layout' );
$fn->setAccessible( true );

$layout_of = function ( $inner ) use ( $fn ) {
	$dom = new DOMDocument();
	libxml_use_internal_errors( true );
	$dom->loadHTML( '<!DOCTYPE html><html><body><div id="cell">' . $inner . '</div></body></html>' );
	libxml_clear_errors();
	$cell = $dom->getElementById( 'cell' );
	$head = null;
	foreach ( array( 'h3', 'h2', 'h4' ) as $t ) { $n = $dom->getElementsByTagName( $t )->item( 0 ); if ( $n instanceof DOMElement ) { $head = $n; break; } }
	return (string) $fn->invoke( null, $cell, $head );
};

echo "\n== The measured shape: an illustration above a label+chevron row\n";

$stacked =
	'<a class="group flex flex-col" href="/injuries/traffic-accidents" data-sc-cs="display:flex;flex-direction:column;height:184px">'
	. '<div class="h-36 flex items-end justify-center mb-4" data-sc-cs="display:flex;justify-content:center;align-items:flex-end;height:144px">'
		. '<img src="/assets/illustration-a.svg" alt="Traffic accident" class="h-full w-auto object-contain" data-sc-cs="height:144px;width:257px">'
	. '</div>'
	. '<div class="flex items-center justify-between" data-sc-cs="display:flex;align-items:center;justify-content:space-between">'
		. '<h3 data-sc-cs="font-size:16px;font-weight:500">Traffic accident</h3>'
		. '<svg class="lucide lucide-chevron-right w-4 h-4 shrink-0"><path d="m9 18 6-6-6-6"></path></svg>'
	. '</div></a>';

$got = $layout_of( $stacked );
$ok( 'top-title' === $got, "the illustration stays above the title (got '{$got}')" );
$ok( 'inline-right' !== $got, '…and the chevron beside the label does not make it inline-right' );

echo "\n== NEGATIVE: a card whose only icon IS a lucide glyph keeps its inline layout\n";

$inline_left =
	'<div class="flex items-start gap-4" data-sc-cs="display:flex;align-items:flex-start">'
	. '<svg class="lucide lucide-shield w-8 h-8"><path d="M12 2 4 6v6"></path></svg>'
	. '<div><h3 data-sc-cs="font-size:18px">No win, no fee</h3></div></div>';
$got_l = $layout_of( $inline_left );
$ok( 'inline-left' === $got_l, "a lucide glyph left of the title is still inline-left (got '{$got_l}')" );

$inline_right =
	'<div class="flex items-center justify-between" data-sc-cs="display:flex;align-items:center">'
	. '<div><h3 data-sc-cs="font-size:18px">No win, no fee</h3></div>'
	. '<svg class="lucide lucide-shield w-8 h-8"><path d="M12 2 4 6v6"></path></svg></div>';
$got_r = $layout_of( $inline_right );
$ok( 'inline-right' === $got_r, "…and right of the title is still inline-right (got '{$got_r}')" );

echo "\n== NEGATIVE: an image icon that genuinely sits beside the title stays inline\n";

// The image is the rendered icon AND it shares a horizontal row with the heading — that really is inline.
$img_inline =
	'<div class="flex items-center gap-4" data-sc-cs="display:flex;align-items:center">'
	. '<img src="/assets/illustration-b.svg" class="w-12 h-12" data-sc-cs="height:48px;width:48px">'
	. '<div><h3 data-sc-cs="font-size:18px">Medical error</h3></div></div>';
$got_i = $layout_of( $img_inline );
$ok( 'inline-left' === $got_i, "an image icon beside the title is inline-left (got '{$got_i}')" );

echo "\n== NEGATIVE: a card with no icon at all is top-title\n";

$no_icon = '<div><h3 data-sc-cs="font-size:18px">Just a heading</h3><p>Some copy.</p></div>';
$got_n   = $layout_of( $no_icon );
$ok( 'top-title' === $got_n, "no icon yields top-title (got '{$got_n}')" );

printf( "\n%s\n", $fails ? "{$fails} FAIL(S)" : 'ALL PASS' );
exit( $fails ? 1 : 0 );
