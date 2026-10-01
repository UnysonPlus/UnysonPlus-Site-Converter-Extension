<?php
/**
 * Regression guard: a capped image keeps the object-fit the capture MEASURED.
 *
 * `img_self_cap_css()` read the fit from one class only — `object-cover` — and sent everything else to
 * `contain`. A source that sets its fit from a stylesheet, or uses any other keyword, was therefore
 * letterboxed whatever it actually said. The value is emitted into scoped CSS, so any legal keyword can be
 * carried verbatim.
 *
 * Where the capture records NOTHING the historic `contain` stands: under a max-height cap with
 * `width:auto;height:auto` the image keeps its own aspect regardless, so that is the conservative reading and
 * the helper's own comment documents it as deliberate. This test pins both halves so neither drifts.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/image-fit-test.php"
 *
 * Exit code 0 = all PASS, 1 = at least one FAIL.
 */

if ( ! class_exists( 'FW_Site_Converter_Stitch' ) ) {
	fwrite( STDERR, "FAIL: site-converter not loaded (run inside a WP install with the plugin active)\n" );
	exit( 1 );
}

$fails = 0;
$ok    = function ( $cond, $msg ) use ( &$fails ) {
	if ( $cond ) { echo "  \xe2\x9c\x93 $msg\n"; } else { $fails++; echo "  \xe2\x9c\x97 FAIL: $msg\n"; }
};

/** Run img_self_cap_css() over one <img>. The cap only applies when the rendered height IS the cap. */
$cap_css = function ( $cls, $cs ) {
	$dom = new DOMDocument();
	libxml_use_internal_errors( true );
	$dom->loadHTML( '<!DOCTYPE html><html><body><img class="' . $cls . '" data-sc-cs="' . $cs . '" src="/a.jpg" alt=""></body></html>', LIBXML_NOERROR | LIBXML_NOWARNING );
	libxml_clear_errors();
	$img = $dom->getElementsByTagName( 'img' )->item( 0 );
	$m   = new ReflectionMethod( 'FW_Site_Converter_Stitch', 'img_self_cap_css' );
	$m->setAccessible( true );
	return (string) $m->invoke( null, $img );
};

// A capped image: max-height 540, rendered AT the cap, width auto.
$BASE = 'max-height:540px;height:540px;';

echo "\n== The measured fit is carried verbatim\n";

foreach ( array( 'fill', 'cover', 'contain', 'none', 'scale-down' ) as $fit ) {
	$css = $cap_css( 'w-auto', $BASE . 'object-fit:' . $fit );
	$ok( false !== strpos( $css, 'object-fit:' . $fit . ';' ),
		'a measured `' . $fit . '` is emitted as `' . $fit . '` (got "' . ( preg_match( '/object-fit:([a-z-]+)/', $css, $m ) ? $m[1] : 'none' ) . '")' );
}

echo "\n== NEGATIVE: the class remains the fallback\n";

$cov = $cap_css( 'w-auto object-cover', $BASE );
$ok( false !== strpos( $cov, 'object-fit:cover;' ),
	'NEGATIVE: with no measured fit, `object-cover` still gives cover' );

$bare = $cap_css( 'w-auto', $BASE );
$ok( false !== strpos( $bare, 'object-fit:contain;' ),
	'NEGATIVE: with neither, the documented `contain` stands rather than a guess' );

echo "\n== NEGATIVE: the rule still only fires for a genuinely capped image\n";

$under = $cap_css( 'w-auto', 'max-height:540px;height:300px;' );
$ok( '' === $under,
	'NEGATIVE: an image well under its cap is not being capped, so no CSS is emitted' );

$nocap = $cap_css( 'w-auto', 'height:540px;' );
$ok( '' === $nocap,
	'NEGATIVE: ...and an image with no cap at all emits none' );

echo $fails ? "\n\xe2\x9c\x97 $fails FAILED\n" : "\n\xe2\x9c\x93 ALL PASS - a capped image keeps the fit its source measured\n";
exit( $fails ? 1 : 0 );
