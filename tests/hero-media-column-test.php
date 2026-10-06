<?php
/**
 * Regression guard: a media column must not borrow a sibling's skin, and must keep the inset that sizes it.
 *
 * Two defects met in one captured hero, both of which made the picture wrong in a way the data did not show.
 *
 * 1. A FRAME THE SOURCE NEVER HAD.
 *
 *    `cell_card_skin()` descends into the child holding at least 80% of a cell's text, on the reasoning that
 *    such a child is the cell's wrapper rather than one card among siblings. That reasoning silently inverts
 *    when the cell is MEDIA: a hero's image column holds a picture and one small captioned pill, and the
 *    picture carries no text at all — so the pill holds 100% of it and reads as the wrapper. It is not; it is
 *    a sibling. Measured: the pill's 3px white border and 15px radius were drawn around the whole 576x564
 *    column, framing an image the source shows bare.
 *
 *    A real wrapper contains everything the cell contains, media included, so a candidate holding fewer
 *    images than the cell ends the descent. The source of that hero does carry 3px white borders — on its
 *    buttons and its pill — which is why "is there a border in here?" was never the right question.
 *
 * 2. THE INSET THAT DECIDES HOW WIDE THE PICTURE RENDERS.
 *
 *    The image recognizers drop the wrapper and emit just the picture, because the wrapper is usually chrome.
 *    Its horizontal padding is not chrome: with the near-universal `max-width:100%` on the image, that padding
 *    is the only thing setting the rendered width. `<div style="padding:5px 30px"><img></div>` in a 516px
 *    column renders the image at 456px; dropping the padding let it fill the column at 576px.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/hero-media-column-test.php"
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
$inset = new ReflectionMethod( 'FW_Site_Converter_Stitch', 'img_wrapper_inset_css' );
$inset->setAccessible( true );

$dom_of = function ( $html ) use ( $ld ) { return $ld->invoke( null, '<!DOCTYPE html><html><body>' . $html . '</body></html>' ); };
$first  = function ( $dom, $cls ) {
	if ( ! $dom ) { return null; }
	$xp = new DOMXPath( $dom );
	return $xp->query( '//*[contains(concat(" ", normalize-space(@class), " "), " ' . $cls . ' ")]' )->item( 0 );
};

// The pill's skin, as the captured hero stamps it.
$PILL = 'border-top-width:3px;border-top-style:solid;border-top-color:rgb(255, 255, 255);'
	. 'border-left-width:3px;border-right-width:3px;border-bottom-width:3px;border-radius:15px;padding:5px';

echo "\n== A media column does NOT take the skin of a small captioned sibling\n";

// The hero shape: a picture (no text) beside a bordered pill that holds all of the column's words.
$html = '<div class="col" data-sc-cs="display:block">'
	. '<div class="shot" data-sc-cs="display:block"><img src="/u/hero.png" alt="hero" /></div>'
	. '<div class="pill" data-sc-cs="' . $PILL . '">WE UNTANGLE PERSONALIZATION WORKFLOWS</div>'
	. '</div>';
$dom  = $dom_of( $html );
$col  = $first( $dom, 'col' );
$ok( $col instanceof DOMElement, 'fixture parses' );
$skin = $cell_skin->invoke( null, $col );
$ok( null === $skin, 'the column reports NO card skin (was: the pill\'s 3px white border around the whole column)' );

echo "\n== …and the pill itself still has one, read directly\n";

$ok( is_array( $cell_skin->invoke( null, $first( $dom, 'pill' ) ) ), 'the pill is still a skinned box in its own right' );

echo "\n== NEGATIVE: a genuine wrapper around the media IS still descended into\n";

// Same skin, but now the painted element CONTAINS the picture — that is a real card, not a sibling.
$html = '<div class="col" data-sc-cs="display:block">'
	. '<div class="card" data-sc-cs="' . $PILL . ';background-color:rgb(255, 255, 255)">'
	. '<img src="/u/hero.png" alt="hero" /><p data-sc-cs="font-size:16px">A caption under the picture.</p>'
	. '</div></div>';
$skin = $cell_skin->invoke( null, $first( $dom_of( $html ), 'col' ) );
$ok( is_array( $skin ), 'a painted wrapper that contains the image is still found' );
$ok( is_array( $skin ) && '15px' === ( $skin['radius'] ?? '' ), 'with its radius carried' );

echo "\n== A flattened image wrapper's side inset is carried\n";

$dom = $dom_of( '<div class="frame" data-sc-cs="padding:5px 30px"><img src="/u/hero.png" alt="hero" data-sc-cs="width:456px" /></div>' );
$img = $dom->getElementsByTagName( 'img' )->item( 0 );
$css = (string) $inset->invoke( null, $img );
$ok( false !== strpos( $css, 'padding-left:30px' ), 'left inset carried: ' . $css );
$ok( false !== strpos( $css, 'padding-right:30px' ), 'right inset carried' );
$ok( false !== strpos( $css, 'box-sizing:border-box' ), 'and it is border-box, so the inset narrows rather than widens' );

echo "\n== NEGATIVE: a wrapper that is not just a frame keeps its inset to itself\n";

// Text alongside the picture means this is a card, not a frame around the image.
$dom = $dom_of( '<div class="frame" data-sc-cs="padding:5px 30px"><img src="/u/a.png" /><p data-sc-cs="font-size:16px">Words.</p></div>' );
$ok( '' === (string) $inset->invoke( null, $dom->getElementsByTagName( 'img' )->item( 0 ) ), 'a wrapper holding text is not treated as a frame' );

$dom = $dom_of( '<div class="frame" data-sc-cs="padding:5px 30px"><img src="/u/a.png" /><img src="/u/b.png" /></div>' );
$ok( '' === (string) $inset->invoke( null, $dom->getElementsByTagName( 'img' )->item( 0 ) ), 'a wrapper holding two images is not one picture\'s frame' );

echo "\n== NEGATIVE: a nudge is not a constraint, and a huge inset is the column's own layout\n";

$dom = $dom_of( '<div class="frame" data-sc-cs="padding:4px 4px"><img src="/u/a.png" /></div>' );
$ok( '' === (string) $inset->invoke( null, $dom->getElementsByTagName( 'img' )->item( 0 ) ), 'under 8px is optical nudging, ignored' );

$dom = $dom_of( '<div class="frame" data-sc-cs="padding:5px 320px"><img src="/u/a.png" /></div>' );
$ok( '' === (string) $inset->invoke( null, $dom->getElementsByTagName( 'img' )->item( 0 ) ), 'past 240px a side it is a layout, not a frame' );

$dom = $dom_of( '<div class="frame" data-sc-cs="padding:0px"><img src="/u/a.png" /></div>' );
$ok( '' === (string) $inset->invoke( null, $dom->getElementsByTagName( 'img' )->item( 0 ) ), 'no padding means no rule emitted' );

echo "\n== NEGATIVE: a small MARK is not a constrained picture\n";

// A card's icon has its box pinned from the measurement elsewhere, so adding the wrapper's padding on top
// double-counts. Measured: `width:60px` plus `padding:20px` under border-box left a 20px content box, and
// object-fit cropped a 300x251 icon to 20x50 -- squashed, not merely small.
$dom = $dom_of( '<div class="frame" data-sc-cs="padding:20px"><img src="/u/icon.png" data-sc-cs="width:60px" /></div>' );
$ok( '' === (string) $inset->invoke( null, $dom->getElementsByTagName( 'img' )->item( 0 ) ), 'a 60px card icon gets no wrapper inset' );

$dom = $dom_of( '<div class="frame" data-sc-cs="padding:30px"><img src="/u/photo.png" data-sc-cs="width:456px" /></div>' );
$ok( '' !== (string) $inset->invoke( null, $dom->getElementsByTagName( 'img' )->item( 0 ) ), '...while a 456px picture still does' );

printf( "\n%s\n", $fails ? "{$fails} FAIL(S)" : 'ALL PASS' );
exit( $fails ? 1 : 0 );
