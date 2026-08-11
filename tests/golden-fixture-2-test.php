<?php
/**
 * Golden-fixture-2 regression guard for the Site Converter deterministic (no-AI) path.
 *
 * A SECOND, structurally different fixture (a bakery / cupcake landing page — testimonials, a badge,
 * meaningful section ids, a pink palette) so detector changes are guarded by more than one site type.
 * Runs FW_Site_Converter_Sources::build_from_html() over tests/fixtures/golden-fixture-2.html and ASSERTS
 * the current known-good output: per-section css_id + shortcode set, the palette, the footer fill, and the
 * structural-drop budget. If a recognizer/mapper/priority change alters a mapping, an assertion FAILS
 * loudly instead of shipping a silent coverage change.
 *
 * Baseline captured from a fresh run of the CURRENT (verified-good) code. When an INTENTIONAL improvement
 * changes the output (e.g. a code_block becomes a native shortcode), update the matching baseline here.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/golden-fixture-2-test.php"
 *
 * Exit code 0 = all PASS, 1 = at least one FAIL.
 */

if ( ! class_exists( 'FW_Site_Converter_Sources' ) ) {
	fwrite( STDERR, "FAIL: site-converter not loaded (run inside a WP install with the plugin active)\n" );
	exit( 1 );
}

$GLOBALS['__pass'] = 0;
$GLOBALS['__fail'] = 0;
function g2( $label, $cond, $got = null ) {
	if ( $cond ) { $GLOBALS['__pass']++; echo "  PASS  $label\n"; }
	else { $GLOBALS['__fail']++; echo "  FAIL  $label" . ( $got !== null ? "  (got: " . ( is_scalar( $got ) ? $got : wp_json_encode( $got ) ) . ")" : "" ) . "\n"; }
}
function g2_eq( $label, $expected, $actual ) {
	g2( $label . " == " . ( is_scalar( $expected ) ? $expected : wp_json_encode( $expected ) ), $expected === $actual, $actual );
}

$fixture = __DIR__ . '/fixtures/golden-fixture-2.html';
if ( ! is_file( $fixture ) ) { fwrite( STDERR, "FAIL: fixture missing: $fixture\n" ); exit( 1 ); }
$html = file_get_contents( $fixture );

$bundle = FW_Site_Converter_Sources::build_from_html( $html, 'GF2', array( 'dynamic_chrome' => true ) );
$files   = $bundle['files'] ?? array();
$builder = $files['pages.json']['pages'][0]['builder'] ?? array();
$ts      = $files['theme-settings.json']['values'] ?? array();

/* Collect [ { css_id, codes[] } ] from the built page-builder tree in document order. */
$sections = array();
foreach ( $builder as $sec ) {
	if ( ( $sec['type'] ?? '' ) !== 'section' ) { continue; }
	$codes = array();
	$walk  = function ( $n ) use ( &$walk, &$codes ) {
		if ( ! is_array( $n ) ) { return; }
		if ( ( $n['type'] ?? '' ) === 'simple' ) { $codes[] = $n['shortcode'] ?? '?'; }
		foreach ( ( $n['_items'] ?? array() ) as $c ) { $walk( $c ); }
	};
	$walk( $sec );
	$sections[] = array( 'css_id' => (string) ( $sec['atts']['css_id'] ?? '' ), 'codes' => implode( ',', $codes ) );
}

echo "\n[1] Section structure (css_id survives + recognizers map the same set)\n";
g2_eq( 'section count', 6, count( $sections ) );

$expect = array(
	array( 'section-1', 'special_heading,button,button,special_heading,text_block,special_heading,text_block,special_heading,media_image' ),
	array( 'builder',   'special_heading,code_block,code_block' ),
	array( 'flavors',   'special_heading,testimonials' ),
	array( 'story',     'media_image,special_heading,text_block,code_block' ),
	array( 'reviews',   'special_heading,testimonials' ),
	array( 'section-6', 'special_heading,special_heading,badge,special_heading' ),
);
foreach ( $expect as $i => $e ) {
	$s = $sections[ $i ] ?? array( 'css_id' => '(missing)', 'codes' => '(missing)' );
	g2_eq( "section " . ( $i + 1 ) . " css_id", $e[0], $s['css_id'] );
	g2_eq( "section " . ( $i + 1 ) . " ({$e[0]}) shortcode set", $e[1], $s['codes'] );
}

echo "\n[2] Recognizer coverage (testimonials + badge + media)\n";
$all_codes = implode( ',', array_map( fn( $s ) => $s['codes'], $sections ) );
g2_eq( 'testimonials blocks (flavors + reviews)', 2, substr_count( $all_codes, 'testimonials' ) );
g2( 'a badge block is present (section-6)', strpos( $all_codes, 'badge' ) !== false );
g2( 'a media_image block is present', strpos( $all_codes, 'media_image' ) !== false );

echo "\n[3] Palette (the bakery pink is detected, not defaulted)\n";
$tc = $ts['theme_colors'] ?? array();
$by = array();
foreach ( $tc as $c ) { if ( isset( $c['name'] ) ) { $by[ $c['name'] ] = $c['color'] ?? ''; } }
g2_eq( 'Primary (brand pink)', '#ff6b8b', $by['Primary'] ?? null );
g2_eq( 'Ink (deep pink)',      '#9d174d', $by['Ink'] ?? null );

echo "\n[4] Footer fill (pink-tinted, from the source)\n";
$footer_bg = (string) ( $ts['footer_background']['color']['value']['custom'] ?? '' );
g2( 'footer background carries the source pink (252, 231, 243)', strpos( $footer_bg, '252, 231, 243' ) !== false, $footer_bg );

echo "\n[5] Structural-drop budget (nothing gets WORSE than the baseline)\n";
$rep = $files['conversion-drops.json'] ?? array( 'rescued' => 999, 'decorative' => 999 );
g2( 'rescued (real content the net had to rescue) <= 3', (int) ( $rep['rescued'] ?? 999 ) <= 3, $rep['rescued'] ?? null );

/* --------------------------------------------------------------------- */
$P = $GLOBALS['__pass']; $F = $GLOBALS['__fail'];
echo "\n========================================\n";
echo "GOLDEN FIXTURE 2 RESULT: " . ( $F === 0 ? 'PASS' : 'FAIL' ) . "   ($P passed, $F failed)\n";
echo "========================================\n";
exit( $F === 0 ? 0 : 1 );
