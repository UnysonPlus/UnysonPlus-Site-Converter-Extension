<?php
/**
 * Regression guard: a button with no destination renders `<button>`, not a link to nowhere.
 *
 * The button view always emitted an anchor, so a control with an empty Link came out as `<a href="#">` —
 * announced as a link by assistive tech, and jumping the page to the top when activated. A source that
 * correctly uses `<button>` (a cookie-consent control, a "load the map" trigger, anything driven by script)
 * was converted into exactly that anti-pattern.
 *
 * Found by comparing structure on a real conversion: the source band reported ZERO links and one button, the
 * conversion one link and zero buttons — the `+1l` that kept the band off PASS. The fix is semantic, not
 * cosmetic: `.btn` styles both elements identically, so nothing moves.
 *
 * `type="button"` matters too — without it the control submits any form it happens to sit inside.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/button-element-test.php"
 *
 * Exit code 0 = all PASS, 1 = at least one FAIL.
 */

if ( ! function_exists( 'do_shortcode' ) ) {
	fwrite( STDERR, "FAIL: WordPress not loaded\n" );
	exit( 1 );
}

$fails = 0;
$ok    = function ( $cond, $msg ) use ( &$fails ) {
	if ( $cond ) { echo "  \xe2\x9c\x93 $msg\n"; } else { $fails++; echo "  \xe2\x9c\x97 FAIL: $msg\n"; }
};

/** Render the button shortcode with a given link value. */
$render = function ( $link ) {
	$atts = array( 'label' => 'Karte laden', 'link' => $link );
	$out  = do_shortcode( '[button ' . implode( ' ', array_map(
		function ( $k, $v ) { return $k . '="' . esc_attr( $v ) . '"'; },
		array_keys( $atts ), array_values( $atts )
	) ) . ']' );
	return (string) $out;
};

echo "\n== No destination → a real button\n";

foreach ( array( '' => 'an EMPTY link', '#' => 'a `#` link' ) as $link => $label ) {
	$html = $render( $link );
	$ok( false !== stripos( $html, '<button' ),
		$label . ' renders a <button> element' );
	$ok( false === stripos( $html, '<a href' ),
		'...and NOT an anchor — `<a href="#">` is a link to nowhere' );
	$ok( (bool) preg_match( '/<button[^>]*\btype\s*=\s*["\']button["\']/i', $html ),
		'...carrying type="button", so it cannot submit a surrounding form' );
	$ok( false !== strpos( $html, 'Karte laden' ),
		'...with its label intact' );
}

echo "\n== NEGATIVE: a real destination is still a link\n";

$linked = $render( 'https://example.com/karte' );
$ok( false !== stripos( $linked, '<a href' ),
	'NEGATIVE: a button WITH a URL still renders an anchor' );
$ok( false === stripos( $linked, '<button' ),
	'NEGATIVE: ...and not a button — a destination belongs to a link' );
$ok( false !== strpos( $linked, 'example.com/karte' ),
	'NEGATIVE: ...pointing where it was told' );

echo "\n== NEGATIVE: the skin is unchanged either way\n";

$plain = $render( '' );
$ok( false !== strpos( $plain, 'btn' ),
	'NEGATIVE: the `btn` class is on both elements, so the two render identically' );

echo $fails ? "\n\xe2\x9c\x97 $fails FAILED\n" : "\n\xe2\x9c\x93 ALL PASS - a control with nowhere to go is a button\n";
exit( $fails ? 1 : 0 );
