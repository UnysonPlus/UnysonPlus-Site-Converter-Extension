<?php
/**
 * Regression guard: a box CLASS that paints nothing must not suppress the box PRESET.
 *
 * A card cell can be boxed two ways. Either its own classes/computed style compile into a skin class
 * (`box_style_class`), or the measured skin is registered as a Box Preset (`register_box_preset`) from the
 * cell's `cardBox`. The second is the fallback, and it is gated on the first not having happened — otherwise
 * a card wears two boxes: two borders, twice the padding.
 *
 * The gate asked "was a class applied?", which is not the same as "was a box drawn". When a page builder
 * paints the card on a WRAPPER inside the cell, the compile still succeeds — on the leftovers. Measured on a
 * captured band it produced:
 *
 *     .box{border-radius:0px;box-sizing:border-box}
 *
 * A rule with no fill, no border and no shadow, whose class name is nonetheless non-empty. That was read as
 * "this card is boxed", the Box Preset built from the wrapper's real 2px/15px/white skin was suppressed, and
 * three bands of bordered cards rendered with no box at all — while the preset for them sat registered and
 * unused.
 *
 * So the fallback gates on whether the class PAINTS. Radius and box-sizing are shape, not paint: they
 * describe a box that something else has to draw.
 *
 * Two things this deliberately does NOT do, both learned by breaking them:
 *
 *   - It does not change what `box_style_class()` returns. Making it return '' for a shape-only class
 *     changed routing for every caller and broke 20 golden assertions.
 *   - It does not change `$box_via_class`, which still means "a class was applied" and still drives the
 *     routing that depends on it. The painted question is asked separately, by the one consumer that needs
 *     a stricter answer.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/box-class-must-paint-test.php"
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

$paints = new ReflectionMethod( 'FW_Site_Converter_Mapper', 'box_class_paints' );
$paints->setAccessible( true );
$css_prop = new ReflectionProperty( 'FW_Site_Converter_Mapper', 'style_css' );
$css_prop->setAccessible( true );

$with = function ( $name, $rule ) use ( $css_prop, $paints ) {
	$cur = (array) $css_prop->getValue( null );
	$cur[ $name ] = $rule;
	$css_prop->setValue( null, $cur );
	return (bool) $paints->invoke( null, $name );
};
$snapshot = (array) $css_prop->getValue( null );
register_shutdown_function( function () use ( $css_prop, $snapshot ) { $css_prop->setValue( null, $snapshot ); } );

echo "\n== The measured empty rule does NOT count as a box\n";

$ok( false === $with( 'box', '.box{border-radius:0px;box-sizing:border-box}' ),
	'`.box{border-radius:0px;box-sizing:border-box}` paints nothing' );
$ok( false === $with( 'b2', '.b2{border-radius:15px}' ), 'radius alone is shape, not paint' );
$ok( false === $with( 'b3', '.b3{box-sizing:border-box}' ), 'box-sizing alone is not paint' );

echo "\n== A rule that actually draws a box DOES count\n";

$ok( true === $with( 'b4', '.b4{border-width:2px;border-style:solid;border-color:#1b3a6b;border-radius:15px}' ), 'a border counts' );
$ok( true === $with( 'b5', '.b5{background-color:#fff;border-radius:15px}' ), 'a fill counts' );
$ok( true === $with( 'b6', '.b6{box-shadow:0 2px 8px rgba(0,0,0,.2)}' ), 'a shadow counts' );
$ok( true === $with( 'b7', '.b7{background-image:linear-gradient(180deg,#fff,#eee)}' ), 'a gradient fill counts' );
$ok( true === $with( 'b8', '.b8{backdrop-filter:blur(12px);border-radius:16px}' ), 'a frosted-glass backdrop counts' );

echo "\n== NEGATIVE: nothing to look at is not a box either\n";

$ok( false === (bool) $paints->invoke( null, '' ), 'an empty class name is not a box' );
$ok( false === (bool) $paints->invoke( null, 'never-registered-xyz' ), 'a class with no registered rule is not a box' );
$ok( false === $with( 'b9', '' ), 'an empty rule is not a box' );

echo "\n== The question is about PAINT, not about how much CSS there is\n";

// A long shape-only rule still paints nothing; a short painting one still counts. Length is not the test.
$ok( false === $with( 'b10', '.b10{border-radius:15px;box-sizing:border-box;border-radius:15px;box-sizing:content-box}' ),
	'a long shape-only rule still paints nothing' );
$ok( true === $with( 'b11', '.b11{border:1px solid red}' ), 'a one-declaration border still counts' );

printf( "\n%s\n", $fails ? "{$fails} FAIL(S)" : 'ALL PASS' );
exit( $fails ? 1 : 0 );
