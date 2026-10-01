<?php
/**
 * Regression guard: a converted site wears the SOURCE's favicon, whatever file type it really is.
 *
 * Two failures, one visible symptom — the browser tab kept the PREVIOUS conversion's mark:
 *
 * 1. The extension came from the URL, which is a claim, not a fact. Plenty of sites answer
 *    `/favicon.ico` with an SVG (a catch-all route, a CDN rewrite), and `svg` was not even in the
 *    accepted list, so the bytes were written as `favicon.png`. WP's sideload checks the real type,
 *    refused it, and `site_icon` was left holding whatever the last conversion had set.
 * 2. The self-contained <head> link then stood down because `has_site_icon()` was true — of that
 *    stale icon. So neither mechanism drew the source's favicon, and nothing reported it.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/favicon-type-test.php"
 *
 * Exit code 0 = all PASS, 1 = at least one FAIL.
 */

if ( ! class_exists( 'FW_Site_Converter_Theme_Generator' ) ) {
	fwrite( STDERR, "FAIL: site-converter not loaded (run inside a WP install with the plugin active)\n" );
	exit( 1 );
}

$fails = 0;
$ok    = function ( $cond, $msg ) use ( &$fails ) {
	if ( $cond ) { echo "  \xe2\x9c\x93 $msg\n"; } else { $fails++; echo "  \xe2\x9c\x97 FAIL: $msg\n"; }
};

$call = function ( $method, $args ) {
	$m = new ReflectionMethod( 'FW_Site_Converter_Theme_Generator', $method );
	$m->setAccessible( true );
	return $m->invokeArgs( null, $args );
};

echo "\n== The BYTES decide the type, not the URL\n";

$png  = "\x89PNG\r\n\x1a\n" . str_repeat( "\x00", 32 );
$jpg  = "\xff\xd8\xff\xe0" . str_repeat( "\x00", 32 );
$gif  = 'GIF89a' . str_repeat( "\x00", 32 );
$webp = 'RIFF' . "\x00\x00\x00\x00" . 'WEBP' . str_repeat( "\x00", 16 );
$ico  = "\x00\x00\x01\x00" . str_repeat( "\x00", 32 );
$svg  = '<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64"><path d="M0 0h64v64H0z"/></svg>';
$svgx = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . $svg;

$ok( 'png'  === $call( 'image_ext_of_bytes', array( $png ) ),  'PNG magic reads as png' );
$ok( 'jpg'  === $call( 'image_ext_of_bytes', array( $jpg ) ),  'JPEG magic reads as jpg' );
$ok( 'gif'  === $call( 'image_ext_of_bytes', array( $gif ) ),  'GIF magic reads as gif' );
$ok( 'webp' === $call( 'image_ext_of_bytes', array( $webp ) ), 'WEBP magic reads as webp' );
$ok( 'ico'  === $call( 'image_ext_of_bytes', array( $ico ) ),  'ICO magic reads as ico' );
$ok( 'svg'  === $call( 'image_ext_of_bytes', array( $svg ) ),  'an SVG served as /favicon.ico still reads as svg' );
$ok( 'svg'  === $call( 'image_ext_of_bytes', array( $svgx ) ), '...including one with an XML prolog in front of it' );
$ok( ''     === $call( 'image_ext_of_bytes', array( 'not an image at all' ) ),
	'NEGATIVE: unrecognised bytes claim nothing, so the URL extension still decides' );

echo "\n== A VECTOR favicon rides the <head> link, and clears a stale converter Site Icon\n";

// A config shaped the way normalize_config() hands one to functions_php — enough of it that the
// generator's own reads are satisfied and the test's output is only about the favicon.
$base = array(
	'slug'     => 'vector-brand',
	'name'     => 'Vector Brand',
	'fn'       => 'vector_brand',
	'template' => 'unysonplus-theme',
	'header'   => array( 'layout' => 'classic', 'menu' => array(), 'links' => array(), 'sticky' => false, 'cta' => array() ),
	'footer'   => array( 'columns' => array(), 'copyright' => '', 'links' => array(), 'social' => array() ),
	'fonts'    => array(),
	'colors'   => array(),
	'hero'     => array( 'pattern' => array( 'image' => '', 'repeat' => 'repeat', 'opacity' => 1 ) ),
);
$vec = $call( 'functions_php', array( array_merge( $base, array( 'favicon_file' => 'favicon.svg', 'favicon_raster' => false ) ) ) );

$ok( false !== strpos( $vec, "get_theme_file_uri( 'favicon.svg' )" ),
	'the link points at the file as it was really written' );
$ok( false !== strpos( $vec, 'type="image/svg+xml"' ),
	'...declaring its type, which a browser needs to accept an SVG icon' );
$ok( false !== strpos( $vec, '$sc_cur !== $sc_own' ),
	'...and it stands down only for a Site Icon the USER chose, never one a past conversion set' );
$ok( false !== strpos( $vec, '_clear_stale_site_icon' ),
	'a stale converter-owned Site Icon is cleared, since a vector cannot replace it' );
$ok( false === strpos( $vec, '_seed_favicon' ),
	'NEGATIVE: no Site Icon seeding is attempted for a vector (WP crops rasters)' );

echo "\n== A RASTER favicon still seeds the Site Icon\n";

$ras = $call( 'functions_php', array( array_merge( $base, array( 'favicon_file' => 'favicon.png', 'favicon_raster' => true ) ) ) );
$ok( false !== strpos( $ras, '_seed_favicon' ),
	'the raster path still seeds the WP Site Icon' );
$ok( false === strpos( $ras, '_clear_stale_site_icon' ),
	'NEGATIVE: ...and does not also clear it out from under itself' );
$ok( false === strpos( $ras, 'type="image/svg+xml"' ),
	'NEGATIVE: a raster link carries no SVG type' );

echo $fails ? "\n\xe2\x9c\x97 $fails FAILED\n" : "\n\xe2\x9c\x93 ALL PASS - the converted site wears the source favicon, whatever type it really is\n";
exit( $fails ? 1 : 0 );
