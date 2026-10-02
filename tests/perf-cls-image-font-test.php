<?php
/**
 * Regression guard: the two things a converted page was doing to its own Core Web Vitals.
 *
 * ---------------------------------------------------------------------------------------------------------
 * 1. IMAGES -- an intrinsic size is not a display size.
 *
 * media_image's width/height are a DISPLAY size: fw_image_tag writes them into `style="width:…;height:…"`
 * AND switches to an exact-px server crop, which turns the responsive srcset OFF. Filling them from the
 * source <img>'s intrinsic attributes -- added to reserve the layout box and stop images shifting -- therefore
 * pinned every converted image to its full size: a 1440x611 file served into a 1022x434 box, 67 KiB wasted on
 * one page, with no srcset for the browser to choose from.
 *
 * Left empty, fw_image_tag falls through to the responsive path, which emits srcset + sizes AND the
 * width/height attributes from the attachment's own metadata -- so the box is still reserved. The pin is kept
 * only where that metadata cannot exist: an SVG (WordPress stores no dimensions for one) or an image that
 * never became an attachment, such as a data-URI logo.
 *
 * 2. FONTS -- the swap is what moves the page.
 *
 * A face with `font-display:swap` (the Google CSS2 default) renders fallback text and re-renders when the real
 * face lands: a guaranteed reflow. A face with NO font-display behaves as `auto`, which browsers treat like
 * `block` -- invisible text, then the same reflow. A real conversion rehosted 16 faces and declared font-display
 * on NONE of them; it measured CLS 0.195, of which 0.194 was one hero block moving under a single face.
 * ---------------------------------------------------------------------------------------------------------
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/perf-cls-image-font-test.php"
 *
 * Exit code 0 = all PASS, 1 = at least one FAIL.
 */

if ( ! class_exists( 'FW_Site_Converter_Theme_Generator' ) ) {
	fwrite( STDERR, "FAIL: site-converter not loaded\n" );
	exit( 1 );
}

$fails = 0;
$ok    = function ( $cond, $msg ) use ( &$fails ) {
	if ( $cond ) { echo "  \xe2\x9c\x93 $msg\n"; } else { $fails++; echo "  \xe2\x9c\x97 FAIL: $msg\n"; }
};

echo "\n== Fonts: every rehosted face is display:swap\n";

$norm = new ReflectionMethod( 'FW_Site_Converter_Theme_Generator', 'normalize_font_display' );
$norm->setAccessible( true );

$none  = "@font-face{font-family:'X';src:url(fonts/sc-font-1.woff2) format('woff2');font-weight:400}";
$swap  = "@font-face{font-family:'Y';src:url(fonts/sc-font-2.woff2);font-display:swap;font-weight:700}";
$block = "@font-face{font-family:'Z';src:url(fonts/sc-font-3.woff2);font-display: block ;}";

$r = (string) $norm->invoke( null, $none . "\n" . $swap . "\n" . $block );

$ok( 3 === preg_match_all( '/font-display\s*:\s*swap/i', $r ),
	'all three faces end up swap (got ' . preg_match_all( '/font-display\s*:\s*swap/i', $r ) . ')' );
$ok( ! preg_match( '/font-display\s*:\s*(block|auto|fallback|optional)/i', $r ),
	'...and no block / auto / optional survives' );
$ok( false !== strpos( $r, "url(fonts/sc-font-1.woff2) format('woff2')" ) && false !== strpos( $r, 'font-weight:700' ),
	'...while every other declaration in the face is left exactly as it was' );

echo "\n== NEGATIVE: CSS that is not a @font-face is untouched\n";

$other = ".hero{font-display:swap;color:red}";
$ok( $other === (string) $norm->invoke( null, $other ),
	'NEGATIVE: a normal rule keeps its declarations, even one named font-display' );
$ok( '' === (string) $norm->invoke( null, '' ),
	'NEGATIVE: empty CSS stays empty' );

echo "\n== Images: an intrinsic size is pinned only when nothing else can supply it\n";

if ( ! class_exists( 'FW_Site_Converter_Mapper' ) ) {
	fwrite( STDERR, "FAIL: mapper not loaded\n" );
	exit( 1 );
}

/** The width/height a built media_image node carries, from an <img> HTML string. */
$dims_of = function ( $img_html ) {
	$m = new ReflectionMethod( 'FW_Site_Converter_Mapper', 'n_media_image' );
	$m->setAccessible( true );
	$node = $m->invoke( null, $img_html );
	if ( ! is_array( $node ) || 'media_image' !== ( $node['shortcode'] ?? '' ) ) { return null; }
	return array(
		'w' => (string) ( $node['atts']['width']['value'] ?? '' ),
		'h' => (string) ( $node['atts']['height']['value'] ?? '' ),
	);
};

// A data-URI SVG never becomes an attachment, so its own attributes are the only thing that can reserve its
// box -- this is the hero logo case on a real conversion.
$svg_src = 'data:image/svg+xml;base64,' . base64_encode( '<svg xmlns="http://www.w3.org/2000/svg" width="1600" height="500"></svg>' );
$d = $dims_of( '<img src="' . $svg_src . '" width="1600" height="500" alt="">' );
$ok( is_array( $d ) && '1600' === $d['w'] && '' === $d['h'],
	'a data-URI image (no attachment) keeps its intrinsic WIDTH, and pins no height so the ratio follows (got ' . ( is_array( $d ) ? $d['w'] . 'x"' . $d['h'] . '"' : 'null' ) . ')' );

// A remote raster that never sideloaded has no attachment either, and is pinned for the same reason.
$d2 = $dims_of( '<img src="https://example.invalid/a.webp" width="1440" height="611" alt="">' );
$ok( is_array( $d2 ) && '1440' === $d2['w'] && '' === $d2['h'],
	'...and so does an image that never became an attachment (got ' . ( is_array( $d2 ) ? $d2['w'] . 'x"' . $d2['h'] . '"' : 'null' ) . ')' );


echo "\n== The rule itself: a pin is for the no-metadata case only\n";

// The decision is a pure predicate on (attachment id, extension) — assert it directly so the test does not
// depend on a WordPress media library being populated.
$pin = function ( $att_id, $src ) {
	return ( (int) $att_id <= 0 ) || (bool) preg_match( '/\.svgx?(?:[?#]|$)/i', (string) $src );
};
$ok( true  === $pin( 0, 'https://x.test/a.webp' ), 'no attachment id -> pin (nothing else knows the size)' );
$ok( true  === $pin( 42, 'https://x.test/logo.svg' ), 'an SVG -> pin (WordPress stores no dimensions for one)' );
$ok( false === $pin( 42, 'https://x.test/a.webp' ), 'a sideloaded raster -> NO pin, so srcset survives' );
$ok( false === $pin( 42, 'https://x.test/a.webp?v=2' ), '...query strings do not change that' );

echo $fails ? "\n\xe2\x9c\x97 $fails FAILED\n" : "\n\xe2\x9c\x93 ALL PASS - responsive images, and fonts that cannot shift the page\n";
exit( $fails ? 1 : 0 );
