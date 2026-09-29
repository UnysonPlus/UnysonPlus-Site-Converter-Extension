<?php
/**
 * A BUNDLE MAY SHIP MEDIA THE CAPTURE ALREADY NORMALISED.
 *
 * The capture service transcodes an undecodable video and cuts its poster, because it runs on a machine
 * that has ffmpeg — which a shared WordPress host usually does not. `media.json` therefore gained a `local`
 * map from source URL to a file inside the bundle, and the import prefers it over re-fetching the original.
 *
 * Why this exists as its own suite: the first version of this feature worked perfectly in development and
 * did nothing at all where it mattered, because the work was on the host. "It works here" is not evidence
 * about a machine with different software on it, so the no-ffmpeg case is asserted explicitly below.
 *
 * The `local` map arrives inside an UPLOADED ZIP. It is untrusted input, and it names files to read off
 * disk — so the traversal guard is the most important thing in this file.
 *
 * Run: wp eval-file bundle-media-test.php
 */

if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "Run through wp-cli: wp eval-file " . basename( __FILE__ ) . "\n" ); exit( 1 ); }
if ( ! class_exists( 'FW_Site_Converter_Media' ) ) { fwrite( STDERR, "Site Converter not active.\n" ); exit( 1 ); }

$pass = 0; $fail = 0;
$ok = function ( $cond, $msg, $detail = '' ) use ( &$pass, &$fail ) {
	if ( $cond ) { $pass++; echo "  ✓ $msg\n"; }
	else { $fail++; echo "  ✗ FAIL: $msg" . ( '' !== $detail ? " — $detail" : '' ) . "\n"; }
};

/* ---------------------------------------------------------------- a throwaway bundle on disk -- */
$dir = trailingslashit( get_temp_dir() ) . 'sc-bundle-test-' . wp_generate_password( 8, false );
wp_mkdir_p( $dir . '/media' );
$vid = $dir . '/media/clip.jpg';
$pos = $dir . '/media/clip-poster.jpg';
// REAL images, because WordPress verifies a sideloaded file's true type and rejects a fake one — which is
// exactly what happened to the first version of this suite: the fallback fired, the local path was never
// exercised, and the failure looked like a bug in the code under test rather than in the fixture.
//
// They stand in for the video because everything asserted here -- WHICH file was used, which URL was
// recorded, which poster was attached -- is media-type agnostic. The transcode itself is the capture
// service's job and is tested there.
$mk = function ( $path, $w, $h, $rgb ) {
	if ( ! function_exists( 'imagecreatetruecolor' ) ) { return false; }
	$im = imagecreatetruecolor( $w, $h );
	imagefill( $im, 0, 0, imagecolorallocate( $im, $rgb[0], $rgb[1], $rgb[2] ) );
	$r = imagejpeg( $im, $path, 90 );
	imagedestroy( $im );
	return $r;
};
// distinct sizes, so "which file is this?" is answered by a measurement rather than by its name
$made = $mk( $vid, 120, 90, array( 10, 20, 30 ) ) && $mk( $pos, 64, 48, array( 200, 180, 160 ) );
$outside = trailingslashit( get_temp_dir() ) . 'sc-outside-' . wp_generate_password( 6, false ) . '.txt';
file_put_contents( $outside, 'THIS FILE IS NOT IN THE BUNDLE' );

$m = new ReflectionMethod( 'FW_Site_Converter_Media', 'bundle_path' );
$m->setAccessible( true );

echo "\n=== The traversal guard (the map comes from an uploaded zip) ===\n";

$ok( '' !== (string) $m->invoke( null, $dir, 'media/clip.jpg' ), 'a path inside the bundle resolves' );
$ok( '' === (string) $m->invoke( null, $dir, '../' . basename( $outside ) ),
	'NEGATIVE: a path climbing OUT of the bundle is refused',
	(string) $m->invoke( null, $dir, '../' . basename( $outside ) ) );
$ok( '' === (string) $m->invoke( null, $dir, '/etc/passwd' ), 'NEGATIVE: an absolute path outside is refused' );
$ok( '' === (string) $m->invoke( null, $dir, 'media/not-here.jpg' ), 'NEGATIVE: a missing file is refused' );
$ok( '' === (string) $m->invoke( null, $dir, '' ), 'NEGATIVE: an empty path is refused' );

echo "\n=== The import prefers the bundled file ===\n";

$url = 'https://fixture-01.example/assets/clip.jpg';
$res = FW_Site_Converter_Media::import_urls(
	array( $url ), 0,
	array( $url => array( 'file' => 'media/clip.jpg', 'poster' => 'media/clip-poster.jpg' ) ),
	$dir
);
$row = isset( $res[0] ) ? (array) $res[0] : array();
$id  = (int) ( $row['id'] ?? 0 );

$ok( ! empty( $row['ok'] ) && $id > 0, 'the bundled asset imported', wp_json_encode( $row ) );

if ( $id > 0 ) {
	$file = (string) get_attached_file( $id );
	$sz = @getimagesize( $file );
	$ok( is_array( $sz ) && 120 === (int) $sz[0] && 90 === (int) $sz[1],
		'…and it is the BUNDLE copy, not something fetched from the URL',
		basename( $file ) . ' ' . ( is_array( $sz ) ? $sz[0] . 'x' . $sz[1] : 'unreadable' ) );

	// the original URL must ride along, or nothing on the page gets rewritten to point at it
	$srcs = (array) get_post_meta( $id, '_unysonplus_source_url' );
	$ok( in_array( $url, $srcs, true ),
		'the attachment records the ORIGINAL url, so de-dup and URL rewriting still work',
		implode( ' | ', $srcs ) );

	$pid = (int) get_post_meta( $id, '_sc_video_poster', true );
	$ok( $pid > 0, 'the bundled poster is attached to it', 'poster id=' . $pid );
	if ( $pid > 0 ) {
		$psz = @getimagesize( (string) get_attached_file( $pid ) );
		$ok( is_array( $psz ) && 64 === (int) $psz[0] && 48 === (int) $psz[1],
			'…and it too is the bundle copy', is_array( $psz ) ? $psz[0] . 'x' . $psz[1] : 'unreadable' );
	}
	wp_delete_attachment( $id, true );
	if ( $pid > 0 ) { wp_delete_attachment( $pid, true ); }
}

echo "\n=== A host with no ffmpeg gets the same result (the entire point) ===\n";

add_filter( 'fw_sc_video_normalize', '__return_false', 99 );
$res2 = FW_Site_Converter_Media::import_urls(
	array( $url ), 0,
	array( $url => array( 'file' => 'media/clip.jpg', 'poster' => 'media/clip-poster.jpg' ) ),
	$dir
);
remove_filter( 'fw_sc_video_normalize', '__return_false', 99 );
$row2 = isset( $res2[0] ) ? (array) $res2[0] : array();
$id2  = (int) ( $row2['id'] ?? 0 );
$ok( $id2 > 0 && (int) get_post_meta( $id2, '_sc_video_poster', true ) > 0,
	'with host-side normalisation disabled, the file AND its poster still arrive',
	wp_json_encode( $row2 ) );
if ( $id2 > 0 ) {
	$p2 = (int) get_post_meta( $id2, '_sc_video_poster', true );
	wp_delete_attachment( $id2, true );
	if ( $p2 > 0 ) { wp_delete_attachment( $p2, true ); }
}

echo "\n=== An older bundle, with no `local` map at all, is unaffected ===\n";
// No map → the call is exactly what it always was. Asserted with an unreachable host so a PASS cannot
// come from a real download: it must fail as a fetch, not silently read something local.
$res3 = FW_Site_Converter_Media::import_urls( array( 'https://fixture-01.invalid/nothing.mp4' ), 0, array(), '' );
$row3 = isset( $res3[0] ) ? (array) $res3[0] : array();
$ok( empty( $row3['ok'] ), 'no map → it falls through to the URL path as before', wp_json_encode( $row3 ) );

/* ---------------------------------------------------------------------------------- cleanup -- */
@unlink( $vid ); @unlink( $pos ); @unlink( $outside );
@rmdir( $dir . '/media' ); @rmdir( $dir );

echo "\nBUNDLE MEDIA RESULT: " . ( 0 === $fail ? 'PASS' : 'FAIL' ) . "   ($pass passed, $fail failed)\n";
exit( 0 === $fail ? 0 : 1 );
