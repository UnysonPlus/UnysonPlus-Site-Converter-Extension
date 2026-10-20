<?php
/**
 * Regression guard: a cropping image keeps the width CAP the source gave it.
 *
 * `media_box_css_el()` recovers a cropping image's frame — its pinned height, radius and shadow — and emits
 * `width:100%` so the photo fills its column. That is right for a full-bleed banner and wrong for an image
 * the source deliberately capped.
 *
 * Measured on a captured band: the photo is `class="w-full max-w-md … object-cover"`, and its stamp reads
 * `max-width:448px; width:448px; height:672px`. The conversion emitted the height but no cap, so
 *
 *     source     448 x 672
 *     converted  636 x 954      (fills the column, height follows the natural ratio)
 *
 * — 42% oversized, which pushed the band from 896px to 1178px.
 *
 * So the measured cap rides along. `width:100%` stays, so the image still shrinks on a narrow screen; the cap
 * only stops it growing past the size the source chose. An image with no cap is untouched.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/media-box-keeps-its-measured-cap-test.php"
 *
 * Exit code 0 = all PASS, 1 = at least one FAIL.
 */

if ( ! class_exists( 'FW_Site_Converter_Mapper' ) ) {
	fwrite( STDERR, "FAIL: site-converter mapper not loaded\n" );
	exit( 1 );
}

$fails = 0;
$ok    = function ( $cond, $msg ) use ( &$fails ) {
	if ( $cond ) { echo "  \xe2\x9c\x93 $msg\n"; } else { $fails++; echo "  \xe2\x9c\x97 FAIL: $msg\n"; }
};

$fn = new ReflectionMethod( 'FW_Site_Converter_Mapper', 'media_box_css_el' );
$fn->setAccessible( true );

$img_of = function ( $cls, $cs ) {
	$dom = new DOMDocument();
	libxml_use_internal_errors( true );
	$dom->loadHTML( '<!DOCTYPE html><html><body><div><img src="/p.jpg" class="' . $cls . '" data-sc-cs="' . $cs . '"></div></body></html>' );
	libxml_clear_errors();
	return $dom->getElementsByTagName( 'img' )->item( 0 );
};

echo "\n== The measured shape: a capped cropping image\n";

$css = (string) $fn->invoke( null, $img_of(
	'w-full max-w-md rounded-2xl object-cover shadow-lg',
	'border-radius:16px;max-width:448px;height:672px;object-fit:cover;width:448px'
) );
$ok( '' !== trim( $css ), 'a frame is recovered at all' );
$ok( false !== strpos( $css, 'height:672px' ), 'the pinned height is carried' );
$ok( false !== strpos( $css, 'max-width:448px' ), 'and the measured cap: ' . substr( $css, 0, 100 ) );
$ok( false !== strpos( $css, 'width:100%' ), 'while width:100% stays, so it still shrinks on a narrow screen' );

echo "\n== NEGATIVE: an uncapped image is untouched\n";

$full = (string) $fn->invoke( null, $img_of(
	'w-full h-[480px] object-cover',
	'height:480px;object-fit:cover;width:1200px'
) );
$ok( '' !== trim( $full ), 'a full-bleed frame is still recovered' );
$ok( false === strpos( $full, 'max-width' ), 'no cap is invented for it' );

echo "\n== NEGATIVE: a cap of none is not a cap\n";

$none = (string) $fn->invoke( null, $img_of(
	'w-full h-[480px] object-cover',
	'height:480px;object-fit:cover;max-width:none'
) );
$ok( false === strpos( $none, 'max-width:none' ), '`max-width:none` is not emitted' );

echo "\n== NEGATIVE: max-width:100% is the DEFAULT, not a cap\n";

// `max-w-full` stamps max-width:100%, which equals width:100% and says nothing about a chosen size.
// Emitting it broke a golden asserting that a full-width 256px cover crop carries no cap.
$full100 = (string) $fn->invoke( null, $img_of(
	'w-full h-64 object-cover',
	'height:256px;object-fit:cover;max-width:100%'
) );
$ok( false === strpos( $full100, 'max-width' ), 'max-width:100% is not emitted as a cap' );
$ok( false !== strpos( $full100, 'height:256px' ), '…while the frame it does have is still carried' );

echo "\n== NEGATIVE: a contained image is still left alone entirely\n";

$contain = (string) $fn->invoke( null, $img_of( 'w-full rounded-lg', 'max-width:448px;height:300px' ) );
$ok( '' === trim( $contain ), 'without object-cover there is no frame to recover' );

printf( "\n%s\n", $fails ? "{$fails} FAIL(S)" : 'ALL PASS' );
exit( $fails ? 1 : 0 );
