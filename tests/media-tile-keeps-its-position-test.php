<?php
/**
 * Regression guard: an out-of-flow media tile keeps its measured position.
 *
 * `element_position_from()` turns a measured absolute/fixed placement into the theme's native Position
 * option. It is applied to a watermark heading and to a pinned icon — but never to a media tile, so a
 * photo collage loses its arrangement and the tiles fall into normal flow.
 *
 * Measured on a captured hero: three 240x300 tiles inside a `relative h-[480px]` host, each
 * `class="absolute"` at its own offset —
 *
 *     source     240x300 @ (1148, 334) · 240x300 @ (852, 434) · a third offset again
 *     converted  240x300 @ (752, 245)  · 240x300 @ (752, 545) · 240x300 @ (752, 845)
 *
 * The SIZES were already exact. Only the placement was lost, so a staggered collage rendered as a flush
 * vertical stack — three photos in a column where the source has them overlapping across the hero.
 *
 * So a media tile asks the same question the watermark and the pinned icon already ask. Nothing is invented:
 * a tile in normal flow gets no Position, because `element_position_from` returns nothing for it.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/media-tile-keeps-its-position-test.php"
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

$builders = new ReflectionMethod( 'FW_Site_Converter_Mapper', 'builders' );
$builders->setAccessible( true );
$set = (array) $builders->invoke( null );
$ok( isset( $set['image']['build'] ), 'the image builder is registered' );
$build = $set['image']['build'];

$img = '<img src="/photo.jpg" width="240" height="300" alt="">';

echo "\n== The measured shape: an absolutely placed tile\n";

$abs = call_user_func( $build, array(
	't'    => 'image',
	'role' => 'image',
	'html' => $img,
	'cls'  => 'absolute',
	'cs'   => 'position:absolute;top:334px;left:1148px;width:240px;height:300px;z-index:2',
) );
$ep = is_array( $abs ) ? ( $abs['atts']['element_position'] ?? null ) : null;
$ok( ! empty( $ep ), 'the tile carries a native Position: ' . substr( json_encode( $ep ), 0, 110 ) );

echo "\n== NEGATIVE: a tile in normal flow gets none\n";

$flow = call_user_func( $build, array(
	't'    => 'image',
	'role' => 'image',
	'html' => $img,
	'cls'  => 'w-full rounded-lg',
	'cs'   => 'position:static;width:240px;height:300px',
) );
$ok( empty( $flow['atts']['element_position'] ), 'an in-flow image is left alone' );

$bare = call_user_func( $build, array( 't' => 'image', 'role' => 'image', 'html' => $img ) );
$ok( empty( $bare['atts']['element_position'] ), 'an unstamped image is left alone too' );

echo "\n== NEGATIVE: the image itself is unchanged either way\n";

$ok( is_array( $abs ) && 'media_image' === (string) ( $abs['shortcode'] ?? '' ), 'still a media_image node' );
$ok( false !== strpos( json_encode( $abs ), 'photo.jpg' ), 'and it still carries its source' );

printf( "\n%s\n", $fails ? "{$fails} FAIL(S)" : 'ALL PASS' );
exit( $fails ? 1 : 0 );
