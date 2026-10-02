<?php
/**
 * Regression guard: a converted image states its own BOX, and the page's first image is the LCP candidate.
 *
 * Found by running Lighthouse against a real conversion: CLS 0.168 against the source's 0.018, with "image
 * elements do not have explicit width and height" among the diagnostics. Measured on the rendered pages, the
 * SOURCE carried width+height on 5 of 5 images; the conversion on 3 of 5 — and the two bare ones were the
 * SVGs, where it matters most, because WordPress stores no dimension metadata for an SVG attachment and there
 * is nothing left to infer the box from.
 *
 * The size was being lost early. img_html() rebuilds every image as `<img src alt>` and everything downstream
 * works from that string, so a width and height omitted there could never be recovered.
 *
 * Separately, every converted image shipped `fetchpriority: auto`, which the media-image view renders as
 * lazy — so the one image the browser most needs early, the hero, was explicitly deprioritised AND deferred.
 * Measured: the above-the-fold hero came back `loading="lazy"` while the source preloads the same image.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/image-dimensions-test.php"
 *
 * Exit code 0 = all PASS, 1 = at least one FAIL.
 */

if ( ! class_exists( 'FW_Site_Converter_Sources' ) ) {
	fwrite( STDERR, "FAIL: site-converter not loaded (run inside a WP install with the plugin active)\n" );
	exit( 1 );
}

$fails = 0;
$ok    = function ( $cond, $msg ) use ( &$fails ) {
	if ( $cond ) { echo "  \xe2\x9c\x93 $msg\n"; } else { $fails++; echo "  \xe2\x9c\x97 FAIL: $msg\n"; }
};

$INK = 'color:rgb(20, 20, 24);font-family:Inter, sans-serif;font-size:16px;line-height:24px;';

/** The image nodes of a converted page, in document order. */
$images = function ( $body ) use ( $INK ) {
	$html = '<!DOCTYPE html><html><head><title>T</title></head><body><main>' . $body . '</main></body></html>';
	$res  = FW_Site_Converter_Sources::build_from_html( $html, 'T', array( 'dynamic_chrome' => true ) );
	$out  = array();
	$walk = function ( $nodes ) use ( &$walk, &$out ) {
		foreach ( (array) $nodes as $n ) {
			if ( ! is_array( $n ) ) { continue; }
			if ( in_array( (string) ( $n['shortcode'] ?? '' ), array( 'media_image', 'single_image', 'image' ), true ) ) {
				$out[] = array(
					'w'  => (string) ( $n['atts']['width']['value'] ?? '' ),
					'h'  => (string) ( $n['atts']['height']['value'] ?? '' ),
					'fp' => (string) ( $n['atts']['fetchpriority'] ?? '' ),
				);
			}
			$walk( $n['_items'] ?? array() );
		}
	};
	$walk( $res['files']['pages.json']['pages'][0]['builder'] ?? array() );
	return $out;
};

$sec = function ( $heading, $img ) use ( $INK ) {
	return '<section data-sc-cs="' . $INK . 'display:block;padding:96px 24px">'
		. '<h2 data-sc-cs="font-size:36px;font-weight:700">' . $heading . '</h2>'
		. '<p data-sc-cs="font-size:16px;line-height:26px">Body copy long enough to count as a real paragraph here.</p>'
		. $img . '</section>';
};

echo "\n== An image states its own width and height\n";

// First image: the author's own attributes. Second: NO attributes, only the computed stamp.
$body = $sec( 'Hero', '<img src="https://example.com/hero.svg" alt="Hero" width="1600" height="500" data-sc-cs="display:block;width:672px;height:224px">' )
	. $sec( 'Below', '<img src="https://example.com/below.png" alt="Below" data-sc-cs="display:block;width:800px;height:600px">' );
$im = $images( $body );

$ok( count( $im ) >= 2, 'both images convert to image nodes (got ' . count( $im ) . ')' );
$ok( '1600' === ( $im[0]['w'] ?? '' ) && '' === ( $im[0]['h'] ?? '' ),
	'the width attribute is carried and NO height is pinned, so the ratio follows the width (got ' . ( $im[0]['w'] ?? '' ) . 'x"' . ( $im[0]['h'] ?? '' ) . '")' );
$ok( '800' === ( $im[1]['w'] ?? '' ) && '' === ( $im[1]['h'] ?? '' ),
	'...and an image with no attributes still falls back to the computed stamp, width only (got ' . ( $im[1]['w'] ?? '' ) . 'x"' . ( $im[1]['h'] ?? '' ) . '")' );

echo "\n== The page's FIRST image is the LCP candidate\n";

$ok( 'high' === ( $im[0]['fp'] ?? '' ),
	'the first image gets fetchpriority:high, which the view renders as eager (got "' . ( $im[0]['fp'] ?? '' ) . '")' );
$ok( 'high' !== ( $im[1]['fp'] ?? '' ),
	'NEGATIVE: a LATER image stays lazy — promoting everything is as useless as promoting nothing (got "' . ( $im[1]['fp'] ?? '' ) . '")' );

echo "\n== NEGATIVE: nothing is invented\n";

$none = $images( $sec( 'Hero', '<img src="https://example.com/x.png" alt="X" data-sc-cs="display:block">' ) );
$ok( '' === ( $none[0]['w'] ?? 'x' ) && '' === ( $none[0]['h'] ?? 'x' ),
	'NEGATIVE: an image whose size the source never states carries no dimensions (got "' . ( $none[0]['w'] ?? '' ) . 'x' . ( $none[0]['h'] ?? '' ) . '")' );

$pct = $images( $sec( 'Hero', '<img src="https://example.com/y.png" alt="Y" width="100%" data-sc-cs="display:block;width:100%">' ) );
$ok( '' === ( $pct[0]['w'] ?? 'x' ),
	'NEGATIVE: a PERCENTAGE is a layout instruction, not an intrinsic size, and is not written into the attribute (got "' . ( $pct[0]['w'] ?? '' ) . '")' );

echo $fails ? "\n\xe2\x9c\x97 $fails FAILED\n" : "\n\xe2\x9c\x93 ALL PASS - a converted image reserves its box, and the hero loads first\n";
exit( $fails ? 1 : 0 );
