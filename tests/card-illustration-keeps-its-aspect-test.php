<?php
/**
 * Regression guard: a WIDE illustration icon keeps the width the source gave it.
 *
 * A card whose icon is an `<img src=*.svg>` is a full illustration, not a glyph, so `n_icon_box()` sizes it
 * from the source icon container's height via `icon_size` — which drives `font-size`, and the inline SVG
 * tracks that. One number is enough for a square glyph and wrong for a landscape illustration: the icon box
 * comes out SQUARE (h x h), so a wide drawing is scaled down to fit the width and renders far shorter than
 * the source.
 *
 * Measured on a captured band of eight cards: the illustration is stamped `width:257px; height:144px` inside
 * an `h-36` (144px) container. The conversion built a 144x144 icon box, so a 257x144 drawing fitted to 144
 * wide rendered about 81px tall — a little over half the source's height, on every card in the band.
 *
 * So the measured WIDTH rides along, and the icon box is given the source's box. An icon that is square (or
 * taller than wide) is untouched: one number already describes it.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/card-illustration-keeps-its-aspect-test.php"
 *
 * Exit code 0 = all PASS, 1 = at least one FAIL.
 */

if ( ! class_exists( 'FW_Site_Converter_Mapper' ) || ! class_exists( 'FW_Site_Converter_Stitch' ) ) {
	fwrite( STDERR, "FAIL: site-converter not loaded\n" );
	exit( 1 );
}

$fails = 0;
$ok    = function ( $cond, $msg ) use ( &$fails ) {
	if ( $cond ) { echo "  \xe2\x9c\x93 $msg\n"; } else { $fails++; echo "  \xe2\x9c\x97 FAIL: $msg\n"; }
};

$box = new ReflectionMethod( 'FW_Site_Converter_Mapper', 'n_icon_box' );
$box->setAccessible( true );

$css_of = function ( $w, $h ) use ( $box ) {
	$node = $box->invoke( null, array(
		'title'    => 'Traffic accident',
		'titleTag' => 'h3',
		'titleCs'  => 'font-size:16px;font-weight:500',
		'text'     => '',
		'image'    => array(),
		'imgIcon'  => '/assets/illustration-a.svg',
		'imgIconH' => $h,
		'imgIconW' => $w,
	) );
	return is_array( $node ) ? (string) ( $node['atts']['custom_css'] ?? '' ) : '';
};

echo "\n== The measured shape: a 257x144 landscape illustration\n";

$css = $css_of( 257, 144 );
$ok( false !== strpos( $css, 'icon-box__icon' ), 'the icon box is scoped at all: ' . mb_substr( trim( $css ), 0, 150 ) );
$ok( false !== strpos( $css, 'width:257px' ), 'the measured width is emitted, so the drawing is not squeezed into a square' );
$ok( false !== strpos( $css, 'height:144px' ), '…alongside the measured height' );

echo "\n== NEGATIVE: a square icon needs no width\n";

$sq = $css_of( 96, 96 );
$ok( false === strpos( $sq, 'width:96px' ), 'a square illustration is left to icon_size alone' );

echo "\n== NEGATIVE: a PORTRAIT icon needs no width either\n";

$tall = $css_of( 80, 144 );
$ok( false === strpos( $tall, 'width:80px' ), 'a taller-than-wide illustration is untouched' );

echo "\n== NEGATIVE: no measured width invents none\n";

$node_nw = $box->invoke( null, array(
	'title' => 'Traffic accident', 'titleTag' => 'h3', 'text' => '', 'image' => array(),
	'imgIcon' => '/assets/illustration-a.svg', 'imgIconH' => 144,
) );
$css_nw = is_array( $node_nw ) ? (string) ( $node_nw['atts']['custom_css'] ?? '' ) : '';
$ok( false === strpos( $css_nw, 'icon-box__icon{width' ), 'no icon width is emitted when the source did not state one' );

echo "\n== The capture records the illustration's measured width\n";

$cell_fn = new ReflectionMethod( 'FW_Site_Converter_Stitch', 'card_from_cell' );
$cell_fn->setAccessible( true );
$dom = new DOMDocument();
libxml_use_internal_errors( true );
$dom->loadHTML( '<!DOCTYPE html><html><body><a id="cell" class="group flex flex-col" href="/x" data-sc-cs="display:flex;flex-direction:column">'
	. '<div class="h-36 flex items-end justify-center mb-4" data-sc-cs="display:flex;height:144px">'
	. '<img src="/assets/illustration-a.svg" alt="Traffic accident" class="h-full w-auto object-contain" data-sc-cs="height:144px;width:257px">'
	. '</div><div class="flex items-center justify-between" data-sc-cs="display:flex">'
	. '<h3 data-sc-cs="font-size:16px;font-weight:500">Traffic accident</h3></div></a></body></html>' );
libxml_clear_errors();
$card = $cell_fn->invoke( null, $dom->getElementById( 'cell' ) );
if ( is_array( $card ) ) {
	$ok( 144 === (int) ( $card['imgIconH'] ?? 0 ), 'the container height is captured (' . (int) ( $card['imgIconH'] ?? 0 ) . 'px)' );
	$ok( 257 === (int) ( $card['imgIconW'] ?? 0 ), 'and the illustration width (' . (int) ( $card['imgIconW'] ?? 0 ) . 'px)' );
} else {
	$ok( false, 'card_from_cell returned a card' );
}

printf( "\n%s\n", $fails ? "{$fails} FAIL(S)" : 'ALL PASS' );
exit( $fails ? 1 : 0 );
