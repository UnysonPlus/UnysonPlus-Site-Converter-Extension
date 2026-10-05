<?php
/**
 * Regression guard: a converted image reserves its box BEFORE the bytes arrive.
 *
 * This has now been got wrong in three different ways, so the rule is worth stating precisely.
 *
 *  1. Width + height filled from the source's intrinsic size. `media_image`'s width/height are a DISPLAY
 *     size: fw_image_tag writes them into `style="width:…;height:…"` and switches to an exact-px server
 *     crop, which turns the responsive srcset off entirely. A 1440x611 file was served into a 1022x434 box.
 *  2. Width + NATURAL height — the two numbers from different sources. The theme's `img{max-width:100%}`
 *     clamped the width to the column while the explicit height stayed, so a 1600x500 logo rendered 672x500
 *     and letterboxed itself inside a 290px band of empty box.
 *  3. Width only, height dropped. That fixed the shape and removed the reservation: width alone says nothing
 *     about height, so the browser reserves none and everything below jumps when the image loads. Measured
 *     on a converted page — a hero logo (1600x500, eager) in a 672px column grew its block by 224px on load,
 *     CLS 0.1426, which was the entire page's score.
 *
 * The answer is a RATIO, not a height: `aspect-ratio` is not a display size, so fw_image_tag keeps the
 * responsive path and the srcset survives; and with the inherited `height:auto` the ratio drives the height
 * instead of fixing it, so nothing is letterboxed. Both numbers must come from the same source or the
 * reserved box is the wrong shape — which is failure (2) all over again.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/image-box-reserved-test.php"
 *
 * Exit code 0 = all PASS, 1 = at least one FAIL.
 */

if ( ! class_exists( 'FW_Site_Converter_Mapper' ) ) {
	fwrite( STDERR, "FAIL: site-converter not loaded\n" );
	exit( 1 );
}

$fails = 0;
$ok    = function ( $cond, $msg ) use ( &$fails ) {
	if ( $cond ) { echo "  \xe2\x9c\x93 $msg\n"; } else { $fails++; echo "  \xe2\x9c\x97 FAIL: $msg\n"; }
};

$node = function ( $html ) {
	$m = new ReflectionMethod( 'FW_Site_Converter_Mapper', 'n_media_image' );
	$m->setAccessible( true );
	return $m->invoke( null, $html );
};
$css_of = function ( $n ) { return (string) ( $n['atts']['custom_css'] ?? '' ); };
$att    = function ( $n, $k ) { return (string) ( $n['atts'][ $k ]['value'] ?? '' ); };

echo "\n== An image WordPress has no metadata for reserves its box by ratio\n";

// An SVG: WordPress stores no width/height for one, so nothing downstream can infer the box.
$svg = '<img src="https://example.invalid/logo.svg" alt="" width="1600" height="500">';
$n   = $node( $svg );
$css = $css_of( $n );

$ok( (bool) preg_match( '/aspect-ratio:\s*1600\s*\/\s*500/', $css ),
	'an aspect-ratio is emitted from the source\'s own intrinsic pair (css: "' . substr( trim( $css ), 0, 70 ) . '")' );
$ok( '1600' === $att( $n, 'width' ),
	'...the width is still pinned, so an SVG with no stored metadata has a size to work from' );
$ok( '' === $att( $n, 'height' ),
	'...and the HEIGHT attribute stays empty — as a display size it letterboxes the artwork (got "'
	. $att( $n, 'height' ) . '")' );

echo "\n== The ratio is the source's, not a guess\n";

$n2 = $node( '<img src="https://example.invalid/wide.svg" alt="" width="1440" height="611">' );
$ok( (bool) preg_match( '/aspect-ratio:\s*1440\s*\/\s*611/', $css_of( $n2 ) ),
	'a different intrinsic pair yields that ratio, not a hardcoded one' );

echo "\n== The missing half may come from the capture's computed stamp\n";

// How the hero logo actually resolves on a real conversion: it arrives as an <img> with a width and no
// height attribute, and img_attr_px() completes the pair from the capture's computed stamp. That is the path
// that reserves its box, so assert it — the both-attributes case above is the easy one.
$n6 = $node( '<img src="https://example.invalid/logo.svg" alt="" width="1600" data-sc-cs="width:1600px;height:500px;">' );
$ok( (bool) preg_match( '/aspect-ratio:\s*1600\s*\/\s*500/', $css_of( $n6 ) ),
	'the ratio is completed from the computed stamp when the attribute is missing (css: "'
	. substr( trim( $css_of( $n6 ) ), 0, 60 ) . '")' );

echo "\n== NEGATIVE: no intrinsic pair, no invented ratio\n";

// Half a pair is the failure that letterboxed a logo — a width with no height must produce nothing.
$n3 = $node( '<img src="https://example.invalid/half.png" alt="" width="1600">' );
$ok( false === strpos( $css_of( $n3 ), 'aspect-ratio' ),
	'NEGATIVE: a width with no height reserves nothing rather than guessing a shape' );

$n4 = $node( '<img src="https://example.invalid/none.png" alt="">' );
$ok( false === strpos( $css_of( $n4 ), 'aspect-ratio' ),
	'NEGATIVE: ...and an image with no dimensions at all emits no rule' );

echo "\n== NEGATIVE: a percentage is a layout instruction, not an intrinsic size\n";

$n5 = $node( '<img src="https://example.invalid/pct.png" alt="" width="100%" height="auto">' );
$ok( false === strpos( $css_of( $n5 ), 'aspect-ratio' ),
	'NEGATIVE: `width="100%" height="auto"` is not an intrinsic pair and is ignored' );

echo $fails ? "\n\xe2\x9c\x97 $fails FAILED\n" : "\n\xe2\x9c\x93 ALL PASS - the box is reserved by ratio, and the srcset survives\n";
exit( $fails ? 1 : 0 );
