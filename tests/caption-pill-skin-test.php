<?php
/**
 * Regression guard: an overlay caption is often a CHIP, not bare text — carry the chip, and its dot.
 *
 * A badge centred on a photo is drawn as a pill: a translucent fill, a hairline ring, a full radius, a
 * backdrop blur, and very often a small painted dot leading the label. Only the LABEL's own stamp was being
 * carried, so the words arrived correctly sized, uppercased and tracked while everything around them
 * vanished — the badge rendered as loose text lying on the picture.
 *
 * The dot is the half that nothing would have reported. It holds no text, so a text-matching parity lens has
 * nothing to miss: drop it and every comparison still passes while the label sits slightly too far left in a
 * pill that looks subtly empty. It is detected by `painted_dot_in()`, which is now ONE detector shared by the
 * badge path and this caption path — they meet the identical chip, and a second copy would have drifted from
 * the scientific-notation radius fix the first one carries (a `rounded-full` radius stamps as `3.35544e+07px`,
 * which a naive `[0-9]+px` test does not match).
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/caption-pill-skin-test.php"
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

/* The fixture is cut from a real capture, brand-scrubbed — the same frame the priority golden uses. A
   hand-written chip would not reproduce the scientific-notation radius or the modern colour functions, which
   are exactly the two things that have broken this path before. */
$frame = @file_get_contents( __DIR__ . '/fixtures/image-overlay-frame.html' );
if ( false === $frame || '' === trim( (string) $frame ) ) {
	fwrite( STDERR, "FAIL: fixtures/image-overlay-frame.html missing\n" );
	exit( 1 );
}

$ld = new ReflectionMethod( 'FW_Site_Converter_Stitch', 'load_dom' );
$ld->setAccessible( true );
$dom = $ld->invoke( null, '<!DOCTYPE html><html><body>' . $frame . '</body></html>' );
$el  = $dom ? $dom->getElementsByTagName( 'div' )->item( 0 ) : null;
if ( ! $el instanceof DOMElement ) {
	fwrite( STDERR, "FAIL: fixture did not parse\n" );
	exit( 1 );
}

$ct = new ReflectionMethod( 'FW_Site_Converter_Stitch', 'caption_tile_block' );
$ct->setAccessible( true );
$blk = $ct->invoke( null, $el );
$ov  = is_array( $blk ) ? ( $blk['overlay'] ?? array() ) : array();

echo "\n== The chip the label sits in is carried\n";

$pill = (string) ( $ov['titlePillCs'] ?? '' );
$ok( '' !== $pill, 'the caption reports the pill it sits in, not only the words' );
$ok( (bool) preg_match( '/background-color:\s*oklab\(/i', $pill ),
	'...with the translucent fill, in the modern colour function the source wrote (a private rgb-only '
	. 'whitelist has rejected oklab/oklch here before)' );
$ok( (bool) preg_match( '/border-top-width:\s*1px/i', $pill ), '...the hairline ring' );
$ok( (bool) preg_match( '/backdrop-filter:\s*blur\(/i', $pill ), '...and the backdrop blur' );

echo "\n== ...and so is the dot nothing would have reported\n";

$dot = ( isset( $ov['titlePillDot'] ) && is_array( $ov['titlePillDot'] ) ) ? $ov['titlePillDot'] : null;
$ok( null !== $dot, 'the chip\'s leading dot is detected' );
$ok( $dot && 8 === (int) ( $dot['size'] ?? 0 ),
	'...at its measured size (got ' . ( $dot ? (int) ( $dot['size'] ?? 0 ) : 'none' ) . 'px)' );
$ok( $dot && (bool) preg_match( '/^oklch\(/i', (string) ( $dot['color'] ?? '' ) ),
	'...in the source\'s own accent colour' );
$ok( $dot && ! empty( $dot['anim'] ),
	'...and its pulse, so a still dot is a reduced-motion choice rather than a dropped one' );

echo "\n== ONE detector, not two copies\n";

$shared = new ReflectionMethod( 'FW_Site_Converter_Stitch', 'painted_dot_in' );
$shared->setAccessible( true );
$ok( true, 'painted_dot_in() exists as a shared helper' );
// The badge path must reach the SAME answer for the same chip — that is the whole point of sharing it.
$pillnode = null;
foreach ( $el->getElementsByTagName( 'div' ) as $d ) {
	$cs = (string) $d->getAttribute( 'data-sc-cs' );
	if ( preg_match( '/background-color:\s*oklab\(/i', $cs ) ) { $pillnode = $d; break; }
}
$direct = $pillnode ? $shared->invoke( null, $pillnode ) : null;
$ok( is_array( $direct ) && $dot && (int) $direct['size'] === (int) $dot['size']
		&& (string) $direct['color'] === (string) $dot['color'],
	'...and the badge path and the caption path read the identical chip identically' );

echo "\n== NEGATIVE: a chip with no dot reports none\n";

$nodot = preg_replace( '#<span class="size-2[^>]*></span>#i', '', $frame, 1 );
$ok( $nodot !== $frame, '(the fixture\'s dot was removed for this case)' );
$dom2 = $ld->invoke( null, '<!DOCTYPE html><html><body>' . $nodot . '</body></html>' );
$el2  = $dom2 ? $dom2->getElementsByTagName( 'div' )->item( 0 ) : null;
$blk2 = $el2 instanceof DOMElement ? $ct->invoke( null, $el2 ) : null;
$ov2  = is_array( $blk2 ) ? ( $blk2['overlay'] ?? array() ) : array();
$ok( empty( $ov2['titlePillDot'] ),
	'NEGATIVE: no dot in the source, no dot invented in the conversion' );
$ok( '' !== (string) ( $ov2['titlePillCs'] ?? '' ),
	'NEGATIVE: ...while the pill around it is still carried' );

echo "\n== NEGATIVE: a bare caption is not given a chip it never had\n";

$bare = preg_replace( '/background-color:oklab\([^)]*\);?/i', '', $frame );
$bare = preg_replace( '/border-top-width:1px;?/i', '', $bare );
$bare = preg_replace( '/border-(?:right|bottom|left)-width:1px;?/i', '', $bare );
$dom3 = $ld->invoke( null, '<!DOCTYPE html><html><body>' . $bare . '</body></html>' );
$el3  = $dom3 ? $dom3->getElementsByTagName( 'div' )->item( 0 ) : null;
$blk3 = $el3 instanceof DOMElement ? $ct->invoke( null, $el3 ) : null;
$ov3  = is_array( $blk3 ) ? ( $blk3['overlay'] ?? array() ) : array();
$ok( '' === (string) ( $ov3['titlePillCs'] ?? '' ),
	'NEGATIVE: an unpainted wrapper is not reported as a pill' );

echo $fails ? "\n\xe2\x9c\x97 $fails FAILED\n" : "\n\xe2\x9c\x93 ALL PASS - the badge arrives as the badge the source drew\n";
exit( $fails ? 1 : 0 );
