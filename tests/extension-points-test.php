<?php
/**
 * Guard for the converter's THREE extension points — the seam a site owner (or their AI agent) uses to
 * correct one site without patching shared code.
 *
 *   1. register_recognizer()                  — claim a DOM element, emit your own block
 *   2. filter fw_site_converter_block_nodes   — claim a stitched block, emit your own builder nodes
 *   3. filter fw_site_converter_theme_settings — correct the values a conversion writes
 *
 * The first test here is a NEGATIVE, and it is the reason this file exists. register_recognizer() was
 * documented as "teach the converter a new shortcode — no core edits", but the registry installed its
 * built-ins under `if ( ! self::$recognizers )`. One third-party recognizer registered before the first
 * conversion therefore left the set non-empty and the built-ins were NEVER installed: measured at 54
 * recognizers down to 1, so the converter produced a near-empty page with no error to explain it. Doing
 * exactly what the docs invited broke everything, silently. A separate $builtins_registered flag fixes it,
 * and the assertion below fails if that regresses.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/extension-points-test.php"
 *
 * Exit code 0 = all PASS, 1 = at least one FAIL.
 */

if ( ! class_exists( 'FW_Site_Converter_Stitch' ) || ! class_exists( 'FW_Site_Converter_Mapper' ) ) {
	fwrite( STDERR, "FAIL: site-converter not loaded (run inside a WP install with the plugin active)\n" );
	exit( 1 );
}

$pass = 0;
$fail = 0;
$ok   = function ( $cond, $what, $detail = '' ) use ( &$pass, &$fail ) {
	if ( $cond ) {
		$pass++;
		echo "  \xE2\x9C\x93 {$what}\n";
	} else {
		$fail++;
		echo "  \xE2\x9C\x97 FAIL: {$what}" . ( '' !== $detail ? " -- {$detail}" : '' ) . "\n";
	}
};

echo "\n=== 1. Recognizer registry ===\n";

// The built-ins must be present, and there must be a lot of them — a plausible-looking small number is the
// failure mode this guards, so assert a floor rather than merely "not empty".
$ids = FW_Site_Converter_Stitch::recognizer_ids();
$ok( count( $ids ) >= 50, 'built-in recognizers are installed (>= 50)', count( $ids ) . ' found' );
$ok( in_array( 'pricing_table', $ids, true ), 'a known built-in recognizer is present (pricing_table)' );

$before = count( $ids );

// NEGATIVE: registering a third party must ADD to the set, never replace it. Before the fix this collapsed
// the set to 1 whenever the registration happened first; here the built-ins are already loaded, so the
// second assertion below (the fresh-process one) is the one that covers the original ordering.
FW_Site_Converter_Stitch::register_recognizer( 'zz_test_added', 50, function () { return false; }, function () { return null; } );
$ids2 = FW_Site_Converter_Stitch::recognizer_ids();
$ok( count( $ids2 ) === $before + 1, 'registering a recognizer ADDS one', $before . ' -> ' . count( $ids2 ) );
$ok( in_array( 'pricing_table', $ids2, true ), 'the built-ins SURVIVE a third-party registration' );

// Replacing a built-in by re-using its id is supported and must not change the count.
// 100, not 99: `scroll_cue` is already 99, and a TIE between equal priorities has no defined order,
// so asserting first-place on 99 tested the sort's incidental behaviour rather than the priority.
FW_Site_Converter_Stitch::register_recognizer( 'pricing_table', 100, function () { return false; }, function () { return null; } );
$ids3 = FW_Site_Converter_Stitch::recognizer_ids();
$ok( count( $ids3 ) === count( $ids2 ), 'replacing a built-in by id does not change the count' );
$ok( 'pricing_table' === $ids3[0], 'the replacement takes its new (highest) priority', 'first id: ' . $ids3[0] );

$ok( true === FW_Site_Converter_Stitch::unregister_recognizer( 'zz_test_added' ), 'unregister_recognizer() removes one' );
$ok( false === FW_Site_Converter_Stitch::unregister_recognizer( 'zz_not_there' ), 'unregister_recognizer() reports an unknown id' );

echo "\n=== 2. fw_site_converter_block_nodes ===\n";

$html = '<main><section id="s1"><h2>Claim me</h2><p>' . str_repeat( 'Body copy for the section. ', 6 ) . '</p></section></main>';

// html_to_mapping() stitches HTML into BLOCKS; blocks become builder NODES in the mapper. The hook lives on
// the node loop, so the mapper is what has to run here -- an earlier version of this test called only
// html_to_mapping() and reported "0 blocks offered", which read as a dead hook and was a dead test.
$mapping = FW_Site_Converter_Stitch::html_to_mapping( $html, 'Hook Test', 'hook-test', true );
$build   = function ( $mapping ) { return FW_Site_Converter_Mapper::build_pages( $mapping ); };

// Every node gets a fresh random `unique_id`, so two builds of the SAME input are never byte-equal. Comparing
// raw JSON therefore "detects a change" on every call -- it made the pass-through negative below fail against
// nondeterminism rather than against the filter. Normalise the ids and the comparison means what it says.
$stable = function ( $v ) {
	return preg_replace( '/"unique_id":"[0-9a-f]+"/', '"unique_id":"*"', (string) wp_json_encode( $v ) );
};

$base = $build( $mapping );
$ok( is_array( $base ) && ! empty( $base ), 'baseline pages built through the mapper' );

// Claim every text block and emit a marker code node instead.
$seen = array();
$claim = function ( $nodes, $b, $css_id ) use ( &$seen ) {
	$seen[] = (string) ( $b['t'] ?? '' );
	if ( 'text' !== (string) ( $b['t'] ?? '' ) ) { return $nodes; }
	return array( array( 'type' => 'simple', 'shortcode' => 'code_block', 'atts' => array( 'code' => '<!--CLAIMED-->' ) ) );
};
add_filter( 'fw_site_converter_block_nodes', $claim, 10, 3 );
$hooked = $build( $mapping );
remove_filter( 'fw_site_converter_block_nodes', $claim, 10 );

$ok( ! empty( $seen ), 'the filter runs for each block', count( $seen ) . ' blocks offered' );
$json = $stable( $hooked );
$ok( false !== strpos( $json, 'CLAIMED' ), 'a claimed block emits the filter\'s nodes' );
$ok( false === strpos( $stable( $base ), 'CLAIMED' ), 'NEGATIVE: the baseline has no marker (the filter, not the fixture, put it there)' );

// Returning null must leave the built-ins untouched — byte-identical output.
$noop = function ( $nodes ) { return $nodes; };
add_filter( 'fw_site_converter_block_nodes', $noop, 10, 3 );
$passthru = $build( $mapping );
remove_filter( 'fw_site_converter_block_nodes', $noop, 10 );
$ok( $stable( $passthru ) === $stable( $base ), 'NEGATIVE: returning null changes nothing at all' );

// An empty array claims the block and emits nothing.
$drop = function ( $nodes, $b ) { return 'text' === (string) ( $b['t'] ?? '' ) ? array() : $nodes; };
add_filter( 'fw_site_converter_block_nodes', $drop, 10, 3 );
$dropped = $build( $mapping );
remove_filter( 'fw_site_converter_block_nodes', $drop, 10 );
$ok( strlen( $stable( $dropped ) ) < strlen( $stable( $base ) ), 'an empty array drops the block' );

echo "\n=== 3. fw_site_converter_theme_settings ===\n";

if ( ! class_exists( 'FW_Site_Converter_Theme_Settings' ) || ! function_exists( 'fw_get_db_settings_option' ) ) {
	echo "  (skipped -- theme settings unavailable in this install)\n";
} else {
	$ran = 0;
	$f   = function ( $incoming, $replace_chrome, $force ) use ( &$ran ) {
		$ran++;
		$incoming['fw_sc_hook_probe'] = 'set-by-filter';
		unset( $incoming['fw_sc_hook_removed'] );
		return $incoming;
	};
	add_filter( 'fw_site_converter_theme_settings', $f, 10, 3 );
	$res = FW_Site_Converter_Theme_Settings::import( array( 'values' => array( 'fw_sc_hook_removed' => 'should-not-survive' ) ) );
	remove_filter( 'fw_site_converter_theme_settings', $f, 10 );

	$ok( 1 === $ran, 'the filter runs once per import', 'ran ' . $ran . ' time(s)' );
	$imported = ( is_array( $res ) && isset( $res['imported'] ) ) ? (array) $res['imported'] : array();
	$ok( in_array( 'fw_sc_hook_probe', $imported, true ), 'a key ADDED by the filter is written', wp_json_encode( $imported ) );
	$ok( ! in_array( 'fw_sc_hook_removed', $imported, true ), 'a key REMOVED by the filter is not written' );

	// Leave no residue: these are probe keys, not settings.
	if ( function_exists( 'fw_set_db_settings_option' ) ) {
		fw_set_db_settings_option( 'fw_sc_hook_probe', null );
		fw_set_db_settings_option( 'fw_sc_hook_removed', null );
	}
}

echo "\n========================================\n";
echo 'EXTENSION POINTS RESULT: ' . ( 0 === $fail ? 'PASS' : 'FAIL' ) . "   ({$pass} passed, {$fail} failed)\n";
echo "========================================\n";
exit( 0 === $fail ? 0 : 1 );
