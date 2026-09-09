<?php
/**
 * Chrome-translation guard for the PHP engine — the twin of the capture service's
 * `header-chrome-parity.test.mjs`.
 *
 * WHY BOTH: `FW_Site_Converter_Bundle::import_dir()` re-runs `build_from_html()` and OVERWRITES the
 * JS-produced theme-settings.json ("so pages + design stay internally consistent — all from the PHP
 * engine"). So for a bundle import the PHP path is AUTHORITATIVE, and a JS-only fixture guards the
 * wrong side. That gap was not theoretical: a saturate rule shipped in PHP only, and a shadow-depth
 * regex bug shipped in BOTH — neither was caught until a fixture existed.
 *
 * Each case pins a NEGATIVE too: with the signal absent the option must NOT be emitted, so a default
 * conversion is unchanged and existing sites cannot drift.
 *
 * Run (against any install with the extension active — testsite is fine):
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs/testsite --allow-root eval-file \
 *     "D:/Web Dev/unysonplus/framework/extensions/site-converter/tests/chrome-parity-test.php"
 *
 * Exit code 0 = all PASS, 1 = at least one FAIL (CI-friendly).
 */

if ( ! class_exists( 'FW_Site_Converter_Sources' ) ) {
	fwrite( STDERR, "Site Converter not loaded — activate the extension first.\n" );
	exit( 1 );
}

$fails = 0;
$ok    = function ( $cond, $msg ) use ( &$fails ) {
	echo ( $cond ? '  ✓ ' : '  ✗ FAIL ' ) . $msg . "\n";
	if ( ! $cond ) { $fails++; }
};

/** Build a minimal page and return the emitted theme-settings values. */
$convert = function ( $header_html, $footer_html ) {
	$html = '<!doctype html><html><head><title>T</title></head><body>'
		. $header_html
		. '<main><section><h1>Hello</h1><p>Body copy for the section.</p></section></main>'
		. $footer_html
		. '</body></html>';
	$res = FW_Site_Converter_Sources::build_from_html( $html, 'Parity', array( 'dynamic_chrome' => true ) );
	$files = ( is_array( $res ) && isset( $res['files'] ) ) ? $res['files'] : array();
	$ts    = isset( $files['theme-settings.json'] ) ? $files['theme-settings.json'] : array();
	if ( is_string( $ts ) ) { $ts = json_decode( $ts, true ); }
	return ( isset( $ts['values'] ) && is_array( $ts['values'] ) ) ? $ts['values'] : (array) $ts;
};

/* ---------------------------------------------------------------- header -- */
echo "\n=== HEADER: two-state numeric translation (PHP path) ===\n";
$hdr_two_state =
	'<header style="position:fixed" data-sc-cs="background-color:rgba(0, 0, 0, 0);position:fixed"'
	. ' data-sc-header="rest-height:88px"'
	. ' data-sc-scrolled="background-color:rgba(10, 10, 10, 0.9);backdrop-filter:blur(12px);height:73px;color:rgb(17,17,17)">'
	. '<div><a href="/">Brand</a></div>'
	. '<nav><a href="#a">One</a><a href="#b">Two</a><a href="#c">Three</a></nav>'
	. '</header>';
$v  = $convert( $hdr_two_state, '<footer><p>&copy; 2026 Parity</p></footer>' );
$hl = isset( $v['header_layout'] ) ? $v['header_layout'] : array();

$ok( isset( $hl['scroll_height']['value'] ) && '73' === (string) $hl['scroll_height']['value'],
	'scroll_height = 73px from the data-sc-scrolled height stamp' );
$ok( isset( $hl['scroll_shrink'] ) && 'yes' === $hl['scroll_shrink'],
	'scroll_shrink enabled with scroll_height (min-height alone loses to taller content)' );
$ok( ! empty( $hl['scroll_link_color'] ),
	'scroll_link_color emitted when the nav restyles once stuck' );
$ok( isset( $hl['min_height']['value'] ) && '88' === (string) $hl['min_height']['value'],
	'min_height = 88px from the always-present data-sc-header stamp' );
$ok( isset( $hl['header_glass_blur']['value'] ) && '12' === (string) $hl['header_glass_blur']['value'],
	'header_glass_blur = 12px (not the theme default 10)' );
$ok( isset( $hl['header_glass_saturate'] ) && 100 === (int) $hl['header_glass_saturate'],
	'saturate pinned to 100 when the source blurs WITHOUT saturating' );

echo "\n=== HEADER: negatives (a plain header must not invent options) ===\n";
$v2  = $convert( '<header><div><a href="/">Brand</a></div><nav><a href="#a">One</a><a href="#b">Two</a></nav></header>',
	'<footer><p>&copy; 2026 Parity</p></footer>' );
$hl2 = isset( $v2['header_layout'] ) ? $v2['header_layout'] : array();
$invented = array();
foreach ( array( 'scroll_height', 'scroll_link_color', 'header_glass_blur', 'header_glass_saturate', 'header_shadow_depth' ) as $k ) {
	if ( isset( $hl2[ $k ] ) ) { $invented[] = $k; }
}
$ok( empty( $invented ), 'no header chrome options invented from a plain header' . ( $invented ? ' (' . implode( ',', $invented ) . ')' : '' ) );

/* ---------------------------------------------------------------- footer -- */
echo "\n=== FOOTER: flat link row must not vanish ===\n";
$flat_footer =
	'<footer data-sc-footer="col-gap:48px;link-hover:rgb(250,250,250)">'
	. '<div><a href="/notes">Field Notes</a><a href="/data">Open Data</a>'
	. '<a href="/api">API Access</a><a href="/partners">Partners</a></div>'
	. '<p>&copy; 2026 Parity</p></footer>';
$v3 = $convert( '<header><div><a href="/">Brand</a></div><nav><a href="#a">One</a><a href="#b">Two</a></nav></header>', $flat_footer );
$mfc_json = wp_json_encode( isset( $v3['main_footer_columns'] ) ? $v3['main_footer_columns'] : array() );

$ok( ! empty( $v3['main_footer_columns'] ), 'main_footer_columns emitted for a footer with NO column structure' );
foreach ( array( 'Field Notes', 'Open Data', 'API Access', 'Partners' ) as $lbl ) {
	$ok( false !== stripos( (string) $mfc_json, $lbl ), 'flat footer link carried: ' . $lbl );
}
$ok( false === stripos( (string) $mfc_json, 'One' ) || false === stripos( (string) $mfc_json, 'Two' ),
	'HEADER nav did NOT leak into the footer (footer_flat_links is footer-scoped)' );
$ok( isset( $v3['footer_col_gap']['value'] ) && '48' === (string) $v3['footer_col_gap']['value'],
	'footer_col_gap = 48px from the data-sc-footer stamp' );
$ok( ! empty( $v3['footer_link_hover_color'] ), 'footer_link_hover_color emitted' );

/* ------------------------------------------------------------ text joins -- */
echo "\n=== TEXT: block boundaries must not glue words ===\n";
$stacked =
	'<header><div><div>National Geographic</div><div>Conservation Technology</div></div>'
	. '<nav><a href="#a">One</a><a href="#b">Two</a></nav></header>';
$v4 = $convert( $stacked, '<footer><p>&copy; 2026 Parity</p></footer>' );
$logo_json = wp_json_encode( isset( $v4['header_logo'] ) ? $v4['header_logo'] : array() );
$ok( false === stripos( (string) $logo_json, 'GeographicConservation' ),
	'stacked wordmark lines are NOT glued ("GeographicConservation")' );

echo "\n" . ( $fails ? "✗ {$fails} FAIL" : '✓ ALL PASS — PHP chrome translations guarded' ) . "\n";
exit( $fails ? 1 : 0 );
