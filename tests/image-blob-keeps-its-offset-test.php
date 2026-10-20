<?php
/**
 * Regression guard: a decorative BLOB behind an image keeps the offset the source gave it.
 *
 * `img_composite_skin_css()` turns an empty painted sibling behind a photo into a `::before` layer on the
 * image node. It already distinguishes the two kinds — a full-bleed `inset-0` tint is a SCRIM and paints ON
 * TOP (z-index 2); an offset, rounded shape is a decorative backdrop and stays BEHIND (z-index 0) — but it
 * pinned BOTH with `inset:0`.
 *
 * For a backdrop that is the whole point: the source offsets it so it peeks out from behind the photo. Pinned
 * at `inset:0` it sits exactly behind an opaque image and is completely invisible.
 *
 * Measured on a captured band: `<div class="absolute -bottom-4 -right-4 w-full h-full bg-purple-500
 * rounded-2xl">` stamps `top:16px; right:-16px; bottom:-16px; left:16px` — a 16px down-and-right shift that
 * reads as a coloured card behind the photo. The conversion emitted the right colour and radius and then hid
 * the whole layer under the image.
 *
 * So a non-scrim blob carries its measured offsets. A scrim still pins `inset:0`, which is what a full-bleed
 * tint means, and a backdrop that really is flush (all four at 0) is unchanged.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/image-blob-keeps-its-offset-test.php"
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

$fn = new ReflectionMethod( 'FW_Site_Converter_Stitch', 'img_composite_skin_css' );
$fn->setAccessible( true );

$build = function ( $blob_cls, $blob_cs ) use ( $fn ) {
	$dom = new DOMDocument();
	libxml_use_internal_errors( true );
	$dom->loadHTML( '<!DOCTYPE html><html><body><div class="relative" data-sc-cs="position:relative">'
		. '<div id="blob" class="' . $blob_cls . '" data-sc-cs="' . $blob_cs . '"></div>'
		. '<img id="photo" src="/p.jpg" class="relative w-full max-w-md rounded-2xl object-cover" '
		. 'data-sc-cs="border-radius:16px;max-width:448px;height:672px;object-fit:cover;width:448px;position:relative;z-index:10">'
		. '</div></body></html>' );
	libxml_clear_errors();
	return (string) $fn->invoke( null, $dom->getElementById( 'photo' ), $dom->getElementById( 'blob' ) );
};

echo "\n== The measured shape: a blob offset 16px down and right\n";

$css = $build(
	'absolute -bottom-4 -right-4 w-full h-full bg-purple-500 rounded-2xl',
	'background-color:rgb(130, 121, 230);border-radius:16px;height:672px;position:absolute;top:16px;right:-16px;bottom:-16px;left:16px'
);
$ok( false !== strpos( $css, '::before' ), 'a backdrop layer is emitted at all' );
$ok( false !== strpos( $css, 'rgb(130, 121, 230)' ), 'it carries the source colour' );
$ok( false === strpos( $css, 'inset:0' ), 'it is NOT pinned flush behind the photo: ' . mb_substr( substr( $css, strpos( $css, '::before' ) ), 0, 150 ) );
$ok( false !== strpos( $css, 'top:16px' ) && false !== strpos( $css, 'left:16px' ), 'the measured down-and-right shift is carried' );
$ok( false !== strpos( $css, 'right:-16px' ) && false !== strpos( $css, 'bottom:-16px' ), '…including the negative edges that make it peek out' );
$ok( false !== strpos( $css, 'z-index:0' ), 'and it still sits behind the image' );

echo "\n== NEGATIVE: a full-bleed SCRIM still pins inset:0 and paints on top\n";

$scrim = $build(
	'absolute inset-0 bg-black/40',
	'background-color:rgba(0, 0, 0, 0.4);position:absolute;top:0px;right:0px;bottom:0px;left:0px;inset:0px'
);
$ok( false !== strpos( $scrim, 'inset:0' ), 'a scrim is still pinned flush' );
$ok( false !== strpos( $scrim, 'z-index:2' ), '…and still paints above the photo' );

echo "\n== NEGATIVE: a flush backdrop (all four at 0) is unchanged\n";

$flush = $build(
	'absolute bg-purple-500 rounded-2xl',
	'background-color:rgb(130, 121, 230);border-radius:16px;position:absolute;top:0px;right:0px;bottom:0px;left:0px'
);
$ok( false !== strpos( $flush, 'inset:0' ), 'a genuinely flush backdrop keeps inset:0' );

printf( "\n%s\n", $fails ? "{$fails} FAIL(S)" : 'ALL PASS' );
exit( $fails ? 1 : 0 );
