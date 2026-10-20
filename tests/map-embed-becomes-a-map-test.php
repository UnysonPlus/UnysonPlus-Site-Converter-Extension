<?php
/**
 * Regression guard: a MAP embed survives the conversion as a native map.
 *
 * The converter recognises an `<iframe>` only when its src is a VIDEO provider (YouTube, Vimeo, Wistia…),
 * which becomes the native `media_video` shortcode. Every other iframe matched nothing and was dropped by
 * the block walker — it never reached the mapping at all.
 *
 * A contact page is where that shows. Measured on a captured one: under an "On the map" heading sits
 * `<iframe src="https://www.google.com/maps/embed?pb=…!2d-122.4194!3d37.7749…">`, and the converted page
 * rendered the heading with nothing beneath it.
 *
 * A Google Maps embed URL carries its own centre in the `pb` blob — `!2d<lng>` and `!3d<lat>` — so the block
 * can become the native `map` shortcode pointed at the same place, rather than a frozen iframe. That keeps it
 * editable (the Locations option) and avoids embedding third-party markup verbatim.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/map-embed-becomes-a-map-test.php"
 *
 * Exit code 0 = all PASS, 1 = at least one FAIL.
 */

if ( ! class_exists( 'FW_Site_Converter_Stitch' ) || ! class_exists( 'FW_Site_Converter_Mapper' ) ) {
	fwrite( STDERR, "FAIL: site-converter not loaded\n" );
	exit( 1 );
}

$fails = 0;
$ok    = function ( $cond, $msg ) use ( &$fails ) {
	if ( $cond ) { echo "  \xe2\x9c\x93 $msg\n"; } else { $fails++; echo "  \xe2\x9c\x97 FAIL: $msg\n"; }
};

$MAP_SRC = 'https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3153.0!2d-122.4194!3d37.7749!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!5e0!3m2!1sen!2sus!4v1';

$page = function ( $inner ) {
	return '<!DOCTYPE html><html><body><main><section data-sc-cs="background-color:rgb(255,255,255);padding:80px 0px">'
		. '<h3 data-sc-cs="font-size:18px">On the map</h3>' . $inner
		. '</section></main></body></html>';
};

echo "\n== The measured shape: a Google Maps embed under a heading\n";

$map = FW_Site_Converter_Stitch::html_to_mapping(
	$page( '<iframe title="Map showing the office" src="' . $MAP_SRC . '" width="100%" height="100%" loading="lazy" data-sc-cs="height:320px"></iframe>' ),
	'Contact', 'contact', true
);
$j = json_encode( $map );
$ok( false !== strpos( $j, '"map"' ), 'the embed is recognised as a map block' );

$find = function ( $n ) use ( &$find ) {
	if ( ! is_array( $n ) ) { return null; }
	if ( 'map' === ( $n['t'] ?? '' ) ) { return $n; }
	foreach ( $n as $v ) { if ( is_array( $v ) ) { $r = $find( $v ); if ( $r ) { return $r; } } }
	return null;
};
$blk = $find( $map );
if ( is_array( $blk ) ) {
	$ok( abs( (float) ( $blk['lat'] ?? 0 ) - 37.7749 ) < 0.0001, 'its latitude comes from the embed (' . ( $blk['lat'] ?? '?' ) . ')' );
	$ok( abs( (float) ( $blk['lng'] ?? 0 ) + 122.4194 ) < 0.0001, 'and its longitude (' . ( $blk['lng'] ?? '?' ) . ')' );
} else {
	$ok( false, 'a map block is produced' );
	$ok( false, '…with coordinates' );
}

echo "\n== …and the mapper emits a native map pointed at the same place\n";

$fn = new ReflectionMethod( 'FW_Site_Converter_Mapper', 'n_map' );
$fn->setAccessible( true );
$node = $fn->invoke( null, array( 't' => 'map', 'lat' => 37.7749, 'lng' => -122.4194, 'height' => 320, 'title' => 'The office' ) );
$ok( is_array( $node ) && 'map' === ( $node['shortcode'] ?? '' ), 'the native map shortcode is emitted' );
if ( is_array( $node ) ) {
	$blob = json_encode( $node['atts'] ?? array() );
	$ok( false !== strpos( $blob, 'custom' ), 'using the Custom location provider' );
	$ok( false !== strpos( $blob, '37.7749' ) && false !== strpos( $blob, '-122.4194' ), 'with the measured coordinates: ' . mb_substr( $blob, 0, 150 ) );
}

echo "\n== NEGATIVE: a VIDEO iframe is still a video, not a map\n";

$vid = FW_Site_Converter_Stitch::html_to_mapping(
	$page( '<iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ" width="560" height="315" data-sc-cs="height:315px"></iframe>' ),
	'Contact', 'contact', true
);
$vj = json_encode( $vid );
$ok( false !== strpos( $vj, '"video"' ), 'a YouTube embed still maps to video' );
$ok( false === strpos( $vj, '"t":"map"' ), '…and is not claimed as a map' );

echo "\n== NEGATIVE: an unrelated iframe is not turned into a map\n";

$other = FW_Site_Converter_Stitch::html_to_mapping(
	$page( '<iframe src="https://example-domain.test/widget" width="400" height="300" data-sc-cs="height:300px"></iframe>' ),
	'Contact', 'contact', true
);
$ok( false === strpos( json_encode( $other ), '"t":"map"' ), 'a non-map embed is left alone' );

printf( "\n%s\n", $fails ? "{$fails} FAIL(S)" : 'ALL PASS' );
exit( $fails ? 1 : 0 );
