<?php
/**
 * Regression guard: a converted page is FULL-BLEED, so a section's own fill reaches the viewport edge.
 *
 * A converted design puts the width on the SECTION's inner container (the `section--cw-*` classes), exactly
 * as the source does: the <section> spans the viewport and only the container inside it is narrowed. The
 * theme's default for a page is the opposite — a 720px reading column — and a conversion never set
 * otherwise, so every converted page was rendered inside that column.
 *
 * It stayed invisible for as long as sections had no background. Once a section's own fill is carried, the
 * same bug paints: a `bg-white/5` wash the source spreads edge to edge gets clipped to the reading column
 * and reads as a floating panel in the middle of the page. Measured on a conversion, before and after:
 *
 *   section   672px at left 339   ->   1350px at left 0   (source: 1350px at left 0)
 *   container 624px               ->    768px             (source:  768px)
 *   image box 624x436 (171px of empty box under the photo) -> 1024x436
 *
 * One cause, three symptoms — the panel, the too-narrow content, and a grey band under an image whose box
 * height had been computed for the full-width geometry it never got.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/full-bleed-pages-test.php"
 *
 * Exit code 0 = all PASS, 1 = at least one FAIL.
 */

if ( ! class_exists( 'FW_Site_Converter_Theme_Settings' ) ) {
	fwrite( STDERR, "FAIL: site-converter not loaded\n" );
	exit( 1 );
}
if ( ! function_exists( 'fw_get_db_settings_option' ) ) {
	fwrite( STDERR, "FAIL: Unyson settings unavailable\n" );
	exit( 1 );
}

$fails = 0;
$ok    = function ( $cond, $msg ) use ( &$fails ) {
	if ( $cond ) { echo "  \xe2\x9c\x93 $msg\n"; } else { $fails++; echo "  \xe2\x9c\x97 FAIL: $msg\n"; }
};

/*
 * THIS TEST DRIVES THE REAL IMPORTER AGAINST THE LIVE INSTALL, so it must put back everything it touches.
 *
 * It did not, once, and the cost was immediate: the payload used to mean "a conversion that says nothing
 * about width" was `array( 'general_layout' => array() )`, and with $force the importer faithfully wrote
 * that real key as EMPTY. `general_layout` is where the site background lives, so running the test blanked
 * the converted site's dark background and left white body text invisible on a white page. A test that
 * destroys the install it runs on is worse than no test.
 *
 * So: snapshot every key this file can write, restore them at the end, and never use a real settings key as
 * a throwaway payload value.
 */
$TOUCHES = array( 'pages_layout', 'general_layout' );
$restore = array();
foreach ( $TOUCHES as $k ) { $restore[ $k ] = fw_get_db_settings_option( $k, null ); }
$put_back = function () use ( $TOUCHES, $restore ) {
	foreach ( $TOUCHES as $k ) {
		if ( null !== $restore[ $k ] ) { fw_set_db_settings_option( $k, $restore[ $k ] ); }
	}
};
// Also put them back if an assertion throws, so a failure cannot leave the site broken either.
register_shutdown_function( $put_back );

// A payload that carries NO width key, without blanking anything: general_layout is passed back at its
// CURRENT value, so the write is a no-op rather than an erasure.
$inert = array( 'general_layout' => (array) fw_get_db_settings_option( 'general_layout', array() ) );

$apply = function ( array $values ) {
	// Run only the decision under test: the filter point is the documented last-chance hook, and what the
	// importer does with `pages_layout` right after it is what this guards.
	$r = new ReflectionMethod( 'FW_Site_Converter_Theme_Settings', 'import' );
	$r->setAccessible( true );
	return $r->invoke( null, array( 'values' => $values ), false, true );
};

echo "\n== A conversion that says nothing about width gets full-bleed\n";

fw_set_db_settings_option( 'pages_layout', array( 'default_sidebar' => 'inherit', 'default_content_width' => 'default' ) );
$apply( $inert );
$got = (array) fw_get_db_settings_option( 'pages_layout', array() );
$ok( 'full' === ( $got['default_content_width'] ?? '' ),
	'Default Content Width is set to full (got "' . ( $got['default_content_width'] ?? '' ) . '")' );
$ok( 'inherit' === ( $got['default_sidebar'] ?? '' ),
	'...and the rest of the group survives — Default Sidebar is not discarded with it (got "'
	. ( $got['default_sidebar'] ?? '' ) . '")' );

echo "\n== NEGATIVE: a bundle that DOES state a width keeps its own\n";

fw_set_db_settings_option( 'pages_layout', array( 'default_sidebar' => 'inherit', 'default_content_width' => 'default' ) );
$apply( array( 'pages_layout' => array( 'default_sidebar' => 'right', 'default_content_width' => 'narrow' ) ) );
$got2 = (array) fw_get_db_settings_option( 'pages_layout', array() );
$ok( 'narrow' === ( $got2['default_content_width'] ?? '' ),
	'NEGATIVE: an explicit width in the payload is not overwritten (got "' . ( $got2['default_content_width'] ?? '' ) . '")' );
$ok( 'right' === ( $got2['default_sidebar'] ?? '' ), 'NEGATIVE: ...nor its sidebar' );

echo "\n== The theme turns that setting into a full-bleed page\n";

// The setting is only worth writing if the theme acts on it — assert the cascade, not just the option.
if ( ! function_exists( 'unysonplus_pages_get' ) ) {
	echo "  \xe2\x9a\xa0 parent theme not active - skipping the cascade assertions\n";
} else {
	fw_set_db_settings_option( 'pages_layout', array( 'default_sidebar' => 'inherit', 'default_content_width' => 'full' ) );
	// Read it back through the THEME's own reader, not through the option we just wrote — a test that only
	// re-reads its own write would still pass if the key were renamed out from under the theme. The reader
	// memoises on first call, so do it in a clean process, the way the front end does.
	$tmp = tempnam( sys_get_temp_dir(), 'fbp' ) . '.php';
	file_put_contents( $tmp, '<?php require "D:/xampp/htdocs/wp-load.php"; echo "[" . unysonplus_pages_get( "default_content_width", "?" ) . "]";' );
	$read = (string) @shell_exec( 'php ' . escapeshellarg( $tmp ) . ' 2>&1' );
	@unlink( $tmp );
	if ( ! preg_match( '/\[([a-z?]*)\]/', $read, $rm ) ) {
		echo "  \xe2\x9a\xa0 could not run a clean read - skipping\n";
	} else {
		$ok( 'full' === $rm[1], 'the theme reads it back as the page width (got "' . $rm[1] . '")' );
	}
}

echo "\n== The value is a normal written key, so a hand edit still wins\n";

// It must be fingerprinted like any other imported value — otherwise a user who sets a reading column by
// hand would have it silently reset by their next conversion.
fw_set_db_settings_option( 'pages_layout', array( 'default_sidebar' => 'inherit', 'default_content_width' => 'default' ) );
$res = $apply( $inert );
$ok( in_array( 'pages_layout', (array) ( $res['imported'] ?? array() ), true ),
	'pages_layout is reported as imported, so it is fingerprinted and a later hand edit is respected' );

$put_back();

echo $fails ? "\n\xe2\x9c\x97 $fails FAILED\n" : "\n\xe2\x9c\x93 ALL PASS - the section fill reaches the edge, like the source\n";
exit( $fails ? 1 : 0 );
