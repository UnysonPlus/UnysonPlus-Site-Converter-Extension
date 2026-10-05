<?php
/**
 * Regression guard: editing a converted button preset's Background Color actually changes the button.
 *
 * A gradient-border button paints TWO background layers: an inner solid clipped to the padding box, and the
 * gradient clipped to the border box so it shows through a deliberately transparent edge. The inner solid is
 * the button's fill, and it is already parsed into the preset's `bg_color` — so re-emitting it in the
 * preset's Custom CSS as a colour literal put an opaque layer directly on top of the value the Background
 * Color control edits. The control worked perfectly and was invisible: the colour changed, the page
 * repainted, and the layer still covered it. Reported from a converted site as "it doesn't get reflected".
 *
 * It is the quietest class of bug in this converter — nothing errors, nothing looks wrong on the page, and
 * the only symptom is a control that does nothing. The same shape would recur for any layered background
 * that repeats a value the UI also owns.
 *
 * The inner layer now reads `var(--btn-bg, C)`: `--btn-bg` is published beside `background-color` by the
 * preset CSS generator, and C is the measured colour kept as a fallback so a preset without a bg_color still
 * paints exactly what the source did.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/button-preset-editable-test.php"
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

$rewrite = function ( $css ) {
	$m = new ReflectionMethod( 'FW_Site_Converter_Stitch', 'btn_inner_layer_follows_bg' );
	$m->setAccessible( true );
	return (string) $m->invoke( null, $css );
};

echo "\n== The inner fill follows the preset's own colour\n";

// The exact value measured on the converted site.
$real = 'linear-gradient(oklch(0.08 0.01 285), oklch(0.08 0.01 285)), '
	. 'linear-gradient(110deg, oklch(0.57599 0.19211 290.21), oklch(0.67795 0.14906 251.88))';
$out  = $rewrite( $real );

$ok( false !== strpos( $out, 'var(--btn-bg,' ),
	'the inner solid layer is rewritten to var(--btn-bg, …) so a preset edit moves it' );
$ok( false !== strpos( $out, 'oklch(0.08 0.01 285)' ),
	'...with the measured colour kept as the fallback, so nothing changes when the variable is absent' );
$ok( false !== strpos( $out, 'linear-gradient(110deg, oklch(0.57599 0.19211 290.21), oklch(0.67795 0.14906 251.88))' ),
	'...and the gradient RING is untouched — it is the edge, not the fill' );
$ok( 2 === preg_match_all( '/linear-gradient\s*\(/', $out ),
	'...still exactly two layers (got ' . preg_match_all( '/linear-gradient\s*\(/', $out ) . ')' );

echo "\n== The generator publishes the variable the layer depends on\n";

// A var nothing defines is the same bug with extra steps, so assert the other half of the contract.
$src = @file_get_contents( WP_PLUGIN_DIR . '/unysonplus/framework/includes/css-tokens.php' );
$ok( is_string( $src ) && false !== strpos( $src, '--btn-bg:' ),
	'css-tokens.php emits --btn-bg beside the preset background-color' );

echo "\n== NEGATIVE: a real gradient fill is never rewritten\n";

// Only a SOLID first layer is the fill. A genuine gradient there is the design and must survive verbatim.
$two_grads = 'linear-gradient(90deg, #ff0000, #00ff00), linear-gradient(110deg, #112233, #445566)';
$ok( $two_grads === $rewrite( $two_grads ),
	'NEGATIVE: a first layer with two DIFFERENT stops is a real gradient and is returned unchanged' );

echo "\n== NEGATIVE: nothing to follow, nothing rewritten\n";

$one = 'linear-gradient(#020203, #020203)';
$ok( $one === $rewrite( $one ),
	'NEGATIVE: a single-layer background is not a gradient border and is left alone' );
$ok( '' === $rewrite( '' ), 'NEGATIVE: an empty value stays empty' );
$ok( 'none' === $rewrite( 'none' ), 'NEGATIVE: `none` is returned as-is' );

// A first layer that is not a gradient at all (an image) is not the fill idiom — leave the whole value be.
$img_first = 'url("http://example.invalid/a.png"), linear-gradient(110deg, #112233, #445566)';
$ok( $img_first === $rewrite( $img_first ),
	'NEGATIVE: a non-gradient first layer is not the fill idiom and is returned untouched' );

// Three identical stops is not the two-stop solid this idiom emits; do not guess at it.
$three = 'linear-gradient(#020203, #020203, #020203), linear-gradient(110deg, #112233, #445566)';
$ok( $three === $rewrite( $three ),
	'NEGATIVE: a shape this does not recognise is left alone rather than rewritten on a guess' );

// A var-driven solid IS a fill, and following the preset is the right answer for it — asserted as positive
// behaviour so the intent is on the record rather than discovered later as a surprise.
$var_solid = 'linear-gradient(var(--x), var(--x)), linear-gradient(110deg, #112233, #445566)';
$ok( false !== strpos( $rewrite( $var_solid ), 'var(--btn-bg, var(--x))' ),
	'a solid fill driven by a variable still follows the preset, with that variable as the fallback' );

echo $fails ? "\n\xe2\x9c\x97 $fails FAILED\n" : "\n\xe2\x9c\x93 ALL PASS - the Background Color control moves the button\n";
exit( $fails ? 1 : 0 );
