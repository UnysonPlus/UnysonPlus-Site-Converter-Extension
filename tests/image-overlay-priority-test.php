<?php
/**
 * Regression guard: a photo with something layered on it is a COMPOSITE, not a tile and not a panel.
 *
 * The recognizer registry runs HIGHER PRIORITY FIRST. `image_overlay` -- the one recognizer that knows an
 * overlay is a layer -- sat at 30, below `image_tile` (87) and `panel` (86), both of which match the same
 * frame. So a hero banner with a glass badge centred on it was claimed by a generic container recognizer and
 * flattened: `image_tile` dropped the badge outright, and once that was taught to stand down, `panel` took
 * its place and emitted the photo and the badge as SIBLINGS -- the badge landing under the picture instead
 * of on it.
 *
 * Priority alone could not fix it without hoisting image_overlay above every container recognizer, so the
 * narrow rule lives on the two that were stealing it: a tile and a panel both stand down for a real overlay.
 * is_image_with_overlay() is strictly more specific than either (exactly one <img>, an absolute layer, and
 * no content outside that layer), so deferring to it cannot cost them an ordinary image or panel.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/image-overlay-priority-test.php"
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

function the_guard( $id ) { return 'the ' . $id . ' recognizer declines an image-with-overlay'; }

$INK = 'color:lab(95 0 0);font-family:Inter, sans-serif;font-size:16px;line-height:24px;text-align:start;';

/* THE FIXTURE IS CUT FROM A REAL CAPTURE (fixtures/image-overlay-frame.html), brand-scrubbed. A synthetic
   frame written by hand satisfied is_image_with_overlay() but NOT image_tile_of() or is_panel(), so the two
   guards under test were never exercised and the assertions passed with the guards removed -- a test that
   proves nothing. The captured frame is the one that all three recognizers really compete for. */
$frame = @file_get_contents( __DIR__ . '/fixtures/image-overlay-frame.html' );
if ( false === $frame || '' === trim( (string) $frame ) ) {
	fwrite( STDERR, "FAIL: fixtures/image-overlay-frame.html missing
" );
	exit( 1 );
}
$ld = new ReflectionMethod( 'FW_Site_Converter_Stitch', 'load_dom' );
$ld->setAccessible( true );
$dom = $ld->invoke( null, '<!DOCTYPE html><html><body>' . $frame . '</body></html>' );
$el  = $dom ? $dom->getElementsByTagName( 'div' )->item( 0 ) : null;

echo "\n== The fixture really is an image-with-overlay\n";

$m = new ReflectionMethod( 'FW_Site_Converter_Stitch', 'is_image_with_overlay' );
$m->setAccessible( true );
$ok( $el instanceof DOMElement && true === $m->invoke( null, $el ),
	'is_image_with_overlay() recognises the frame' );

echo "\n== The container recognizers stand down for it\n";

$tile = new ReflectionMethod( 'FW_Site_Converter_Stitch', 'image_tile_of' );
$tile->setAccessible( true );
$panel = new ReflectionMethod( 'FW_Site_Converter_Stitch', 'is_panel' );
$panel->setAccessible( true );

$init = new ReflectionMethod( 'FW_Site_Converter_Stitch', 'register_builtin_recognizers' );
$init->setAccessible( true );
$init->invoke( null );
$rp = new ReflectionMethod( 'FW_Site_Converter_Stitch', 'recognizers' );
$rp->setAccessible( true );
$set = (array) $rp->invoke( null );

$first = '';
foreach ( $set as $r ) {
	if ( call_user_func( $r['match'], $el, 'div', array() ) ) { $first = (string) $r['id']; break; }
}
// Assert the GUARDS directly, not just the resulting order: a fixture that happens not to satisfy is_panel
// would let an ordering check pass with the guard removed, which is no guard at all.
$reg = array();
foreach ( $set as $r ) { $reg[ (string) $r['id'] ] = $r; }
$ok( isset( $reg['image_tile'] ) && false === (bool) call_user_func( $reg['image_tile']['match'], $el, 'div', array() ),
	the_guard( 'image_tile' ) );
$ok( isset( $reg['panel'] ) && false === (bool) call_user_func( $reg['panel']['match'], $el, 'div', array() ),
	the_guard( 'panel' ) );

$ok( 'image_overlay' === $first,
	'the FIRST recognizer to match is image_overlay, not a tile or a panel (got "' . $first . '")' );

echo "\n== NEGATIVE: a plain framed photo is still a tile\n";

$plain = '<div class="relative overflow-hidden rounded-2xl border" data-sc-cs="' . $INK . 'display:block;position:relative;border-radius:18px;overflow:hidden">'
	. '<img src="https://example.invalid/banner.jpg" alt="" width="1440" height="611" data-sc-cs="display:block;width:1440px;height:611px">'
	. '</div>';
$dom2 = $ld->invoke( null, '<!DOCTYPE html><html><body>' . $plain . '</body></html>' );
$el2  = $dom2 ? $dom2->getElementsByTagName( 'div' )->item( 0 ) : null;
$ok( $el2 instanceof DOMElement && false === $m->invoke( null, $el2 ),
	'NEGATIVE: with no overlay layer the frame is not a composite' );
$first2 = '';
foreach ( $set as $r ) {
	if ( call_user_func( $r['match'], $el2, 'div', array() ) ) { $first2 = (string) $r['id']; break; }
}
$ok( 'image_overlay' !== $first2,
	'NEGATIVE: ...so image_overlay does not claim it, and the container recognizers keep it (got "' . $first2 . '")' );

echo "\n== The overlay's own axis decides where the caption sits\n";

$ct = new ReflectionMethod( 'FW_Site_Converter_Stitch', 'caption_tile_block' );
$ct->setAccessible( true );
$blk = $ct->invoke( null, $el );
$ov  = is_array( $blk ) ? ( $blk['overlay'] ?? array() ) : array();

$ok( 'center' === (string) ( $ov['place'] ?? '' ),
	'a layer with align-items:center places the caption vertically centred (got "' . ( $ov['place'] ?? '' ) . '")' );
$ok( 'center' === (string) ( $ov['align'] ?? '' ),
	'...and justify-content:center centres it HORIZONTALLY -- read from the layer axis, not from text-align '
	. '(got "' . ( $ov['align'] ?? '' ) . '")' );

echo "\n== NEGATIVE: a left-aligned caption layer stays left\n";

$left = str_replace( 'justify-content:center', 'justify-content:flex-start', $frame );
$left = str_replace( 'justify-center', 'justify-start', $left );
$dom3 = $ld->invoke( null, '<!DOCTYPE html><html><body>' . $left . '</body></html>' );
$el3  = $dom3 ? $dom3->getElementsByTagName( 'div' )->item( 0 ) : null;
$blk3 = $el3 instanceof DOMElement ? $ct->invoke( null, $el3 ) : null;
$ok( 'center' !== (string) ( ( is_array( $blk3 ) ? ( $blk3['overlay']['align'] ?? '' ) : '' ) ),
	'NEGATIVE: a layer that does not centre on its main axis is not reported centred' );

echo $fails ? "\n\xe2\x9c\x97 $fails FAILED\n" : "\n\xe2\x9c\x93 ALL PASS - the layer stays a layer, where the source put it\n";
exit( $fails ? 1 : 0 );
