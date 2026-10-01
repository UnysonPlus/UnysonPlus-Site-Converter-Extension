<?php
/**
 * Regression guard: a colour is never rejected by a GATEKEEPER that color_to_hex() could have resolved.
 *
 * color_to_hex() grew to handle CIE `lab()`/`lch()`, and resolved them correctly. It was never asked. Six
 * separate call sites across Stitch and the Mapper each kept their OWN whitelist of colour-function names
 * — `(?:oklch|oklab|hsla?)\(` and four near-variants — written when oklch was the newest space anyone had
 * seen. A source whose buttons are filled `lab(27.7933 -4.50668 -26.6809)` (a dark navy) hit the gate in
 * `$normc` first: the string was discarded unread, the Primary preset was built with an EMPTY fill, and
 * every button on the converted site painted the THEME's default blue over the source's navy.
 *
 * The defect is the duplication, not any one list — a seventh caller with a seventh whitelist reintroduces
 * it. `is_color_func()` / `color_func_re()` are the single list, and this test asserts the GATE and the
 * RESOLVER agree: anything color_to_hex() can resolve, is_color_func() admits, and vice versa.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/color-func-gate-test.php"
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

echo "\n== The gate admits exactly what the resolver resolves\n";

$RESOLVABLE = array(
	'rgb(26, 69, 107)',
	'rgba(26, 69, 107, 0.5)',
	'hsl(207 61% 26%)',
	'oklch(0.35 0.06 250)',
	'oklab(0.35 -0.02 -0.08)',
	'lab(27.7933 -4.50668 -26.6809)',
	'lch(27.79 27.06 260.4)',
	'color(srgb 0.1 0.27 0.42)',
);
foreach ( $RESOLVABLE as $c ) {
	$hex = FW_Site_Converter_Stitch::color_to_hex( $c );
	$ok( '' !== $hex && FW_Site_Converter_Stitch::is_color_func( $c ),
		'`' . $c . '` resolves (' . ( '' === $hex ? 'NOTHING' : $hex ) . ') AND passes the gate' );
}

echo "\n== The navy that shipped the bug converts to the navy, not to nothing\n";

$ok( '#1a456b' === FW_Site_Converter_Stitch::color_to_hex( 'lab(27.7933 -4.50668 -26.6809)' ),
	'the source button\'s CIE lab navy is #1a456b (got "' . FW_Site_Converter_Stitch::color_to_hex( 'lab(27.7933 -4.50668 -26.6809)' ) . '")' );

echo "\n== NEGATIVE: the gate does not admit what the resolver cannot do\n";

$ok( ! FW_Site_Converter_Stitch::is_color_func( 'hwb(210 10% 60%)' ),
	'NEGATIVE: `hwb()` is NOT claimed — color_to_hex cannot parse it, and admitting it would trade a dropped colour for a wrong one' );
$ok( '' === FW_Site_Converter_Stitch::color_to_hex( 'hwb(210 10% 60%)' ),
	'NEGATIVE: ...consistent with the resolver, which returns nothing for it' );
foreach ( array( '', 'transparent', 'none', '#1a456b', 'linear-gradient(90deg, red, blue)', 'currentcolor' ) as $c ) {
	$ok( ! FW_Site_Converter_Stitch::is_color_func( $c ),
		'NEGATIVE: `' . ( '' === $c ? '(empty)' : $c ) . '` is not a colour FUNCTION call' );
}

echo "\n== End to end: a lab()-filled button builds a preset that carries the fill\n";

// A source that paints its primary CTA in CIE lab, exactly as the real one does. Two sections so the
// button clusters have something to agree on.
$ink  = 'color:lab(100 0 0);font-family:Inter, sans-serif;font-size:16px;line-height:24px;';
$btn  = '<a href="/call" class="btn-primary inline-flex rounded-lg" data-sc-cs="' . $ink
	. 'display:inline-flex;background-color:lab(27.7933 -4.50668 -26.6809);color:lab(100 0 0);'
	. 'font-size:16px;font-weight:600;height:48px;padding:0px 20px;border-radius:8px">Jetzt anrufen</a>';
$html = '<!DOCTYPE html><html><head><title>Repair</title></head><body><main>'
	. '<section data-sc-cs="' . $ink . 'padding:96px 24px;display:block"><h1>One</h1>'
	. '<p data-sc-cs="font-size:16px;line-height:26px">Body copy long enough to count as a real paragraph here.</p>'
	. $btn . '</section>'
	. '<section data-sc-cs="' . $ink . 'padding:96px 24px;display:block"><h2>Two</h2>'
	. '<p data-sc-cs="font-size:16px;line-height:26px">More copy, again long enough to read as a real paragraph.</p>'
	. $btn . '</section></main></body></html>';

$v    = FW_Site_Converter_Sources::build_from_html( $html, 'Repair', array( 'dynamic_chrome' => true ) )['files']['theme-settings.json']['values'] ?? array();
$cols = (array) ( $v['button_colors'] ?? array() );

$fills = array();
foreach ( $cols as $c ) {
	$d = (array) ( $c['states']['default'] ?? array() );
	$bgf = $d['bg_color'] ?? $d['background_color'] ?? array();
	$fills[] = strtolower( trim( (string) ( is_array( $bgf ) ? ( $bgf['custom'] ?: $bgf['predefined'] ) : $bgf ) ) );
}
$ok( ! empty( $cols ), 'the lab()-filled button yields a colour preset at all (got ' . count( $cols ) . ')' );
$ok( '' !== implode( '', $fills ),
	'at least one preset carries a NON-EMPTY fill — the gate no longer eats it (got ' . json_encode( $fills ) . ')' );

$hit = false;
foreach ( $fills as $f ) { if ( false !== strpos( $f, '1a456b' ) || false !== strpos( $f, '26, 69, 107' ) ) { $hit = true; } }
$ok( $hit,
	'...and the fill is the source\'s own navy, not a theme default (got ' . json_encode( $fills ) . ')' );

echo $fails ? "\n\xe2\x9c\x97 $fails FAILED\n" : "\n\xe2\x9c\x93 ALL PASS - one colour-function list, and the gate agrees with the resolver\n";
exit( $fails ? 1 : 0 );
