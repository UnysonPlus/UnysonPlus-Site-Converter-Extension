<?php
/**
 * Guard for the AI Dev Kit cross-check (FW_Site_Converter_Kit).
 *
 * The check exists because a kit can sit several converter versions behind while its docs describe behaviour
 * that has since changed, and the only symptom is an agent confidently following stale guidance. It was
 * written after exactly that drift was found on the authoring machine: converter 1.10.13 against a kit whose
 * recorded trigger still said 1.10.10, because the script meant to update it matched a key spelled
 * differently and silently updated nothing, three times in a row.
 *
 * FW_UPW_KIT_PATH is a constant, so it cannot be redefined per assertion. The tests therefore exercise
 * status() through a temporary kit whose manifest is REWRITTEN between phases, which is also closer to what
 * actually happens in life: the path stays put and the file underneath it changes.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/kit-check-test.php"
 */

if ( ! class_exists( 'FW_Site_Converter_Kit' ) ) {
	fwrite( STDERR, "FAIL: FW_Site_Converter_Kit not loaded (run inside a WP install with the extension active)\n" );
	exit( 1 );
}

$pass = 0;
$fail = 0;
$ok   = function ( $cond, $what, $detail = '' ) use ( &$pass, &$fail ) {
	if ( $cond ) { $pass++; echo "  \xE2\x9C\x93 {$what}\n"; }
	else { $fail++; echo "  \xE2\x9C\x97 FAIL: {$what}" . ( '' !== $detail ? " -- {$detail}" : '' ) . "\n"; }
};

// A runner that wants to exercise the comparison branches must define FW_UPW_KIT_PATH before this file
// loads, and tell us the same directory through $GLOBALS['fw_sc_kit_test_dir'] -- a constant cannot be
// redefined per assertion, so the path stays put and the manifest underneath it is rewritten instead.
$tmp = isset( $GLOBALS['fw_sc_kit_test_dir'] )
	? rtrim( (string) $GLOBALS['fw_sc_kit_test_dir'], '/\\' )
	: rtrim( get_temp_dir(), '/\\' ) . '/fw-sc-kit-test-' . wp_generate_password( 8, false );
@mkdir( $tmp, 0777, true );

$reset = function () {
	$p = new ReflectionProperty( 'FW_Site_Converter_Kit', 'status' );
	$p->setAccessible( true );
	$p->setValue( null, null );
};
$status = function () use ( $reset ) { $reset(); return FW_Site_Converter_Kit::status(); };
$put    = function ( $data ) use ( $tmp ) {
	file_put_contents( $tmp . '/kit-manifest.json', is_string( $data ) ? $data : wp_json_encode( $data ) );
};

$installed = '';
if ( function_exists( 'fw_ext' ) ) {
	$ext = fw_ext( 'site-converter' );
	if ( $ext && isset( $ext->manifest ) ) { $installed = (string) $ext->manifest->get_version(); }
}

echo "\n=== 0. Preconditions ===\n";
$ok( '' !== $installed, 'the installed converter version is readable', $installed );

if ( ! defined( 'FW_UPW_KIT_PATH' ) ) {
	echo "\n  FW_UPW_KIT_PATH is not defined in this install.\n";
	$ok( false === FW_Site_Converter_Kit::configured(), 'NEGATIVE: an install with no constant reports unconfigured' );
	$ok( null === $status(), 'NEGATIVE: with no constant, status() says nothing at all' );
	ob_start(); FW_Site_Converter_Kit::render_notice(); $out = ob_get_clean();
	$ok( '' === $out, 'NEGATIVE: with no constant, render_notice() prints NOTHING (every normal install)' );
	echo "\n  To exercise the comparisons, add to wp-config.php and re-run:\n";
	echo "    define( 'FW_UPW_KIT_PATH', '" . str_replace( '\\', '/', $tmp ) . "' );\n";
} else {
	echo '  FW_UPW_KIT_PATH = ' . FW_UPW_KIT_PATH . "\n";
	$ok( true === FW_Site_Converter_Kit::configured(), 'the constant is seen' );

	$is_tmp = ( realpath( (string) FW_UPW_KIT_PATH ) === realpath( $tmp ) );

	if ( ! $is_tmp ) {
		// Pointed at the real kit: assert what can be asserted without touching the user's file.
		echo "  (pointed at the real kit, not the test dir -- asserting on it read-only)\n";
		$s = $status();
		$ok( is_array( $s ), 'status() reads the configured kit' );
		if ( is_array( $s ) ) {
			$ok( in_array( $s['verdict'], array( 'current', 'kit_behind', 'plugin_behind' ), true ), 'verdict is one of the three', $s['verdict'] );
			$ok( $s['installed']['site_converter'] === $installed, 'it compares against the INSTALLED converter version' );
			$ok( '' !== $s['expects']['site_converter'], 'it read the kit\'s recorded converter version', $s['expects']['site_converter'] );
			$ok( 0 === strpos( $s['kit_path'], realpath( (string) FW_UPW_KIT_PATH ) ), 'the reported path is the resolved root' );
			ob_start(); FW_Site_Converter_Kit::render_notice(); $out = ob_get_clean();
			$ok( '' !== $out, 'render_notice() prints something for a configured kit' );
			$ok( 'current' !== $s['verdict'] || false === strpos( $out, 'notice-warning' ), 'an agreeing kit does NOT warn' );
			$ok( 'current' === $s['verdict'] || false !== strpos( $out, 'notice-warning' ), 'a drifted kit DOES warn' );
		}
	} else {
		// Pointed at the test dir: drive every verdict.
		echo "\n=== 1. Verdicts ===\n";

		$put( array( 'kit_version' => '9.9.9', 'bump_triggers' => array( 'site_converter_extension' => $installed, 'capture_service' => '1.2.3' ) ) );
		$s = $status();
		$ok( is_array( $s ) && 'current' === $s['verdict'], 'matching versions -> current', is_array( $s ) ? $s['verdict'] : 'null' );
		$ok( is_array( $s ) && '' === $s['message'], 'the agreeing case carries no complaint' );
		ob_start(); FW_Site_Converter_Kit::render_notice(); $out = ob_get_clean();
		$ok( false === strpos( $out, 'notice-warning' ), 'NEGATIVE: the agreeing case does not warn' );
		$ok( false !== strpos( $out, '1.2.3' ), 'it still states the Capture Service version the kit expects' );

		$put( array( 'kit_version' => '1.0.0', 'bump_triggers' => array( 'site_converter_extension' => '0.0.1' ) ) );
		$s = $status();
		$ok( is_array( $s ) && 'kit_behind' === $s['verdict'], 'kit older than the converter -> kit_behind', is_array( $s ) ? $s['verdict'] : 'null' );
		ob_start(); FW_Site_Converter_Kit::render_notice(); $out = ob_get_clean();
		$ok( false !== strpos( $out, 'notice-warning' ), 'kit_behind warns' );
		$ok( false !== strpos( $out, 'Pull the kit' ) || false !== strpos( $out, 'pull the kit' ), 'it says what to do about it' );

		$put( array( 'kit_version' => '9.9.9', 'bump_triggers' => array( 'site_converter_extension' => '999.0.0' ) ) );
		$s = $status();
		$ok( is_array( $s ) && 'plugin_behind' === $s['verdict'], 'kit newer than the converter -> plugin_behind', is_array( $s ) ? $s['verdict'] : 'null' );

		echo "\n=== 2. It degrades to silence, never to noise ===\n";

		$put( '{ this is not json' );
		$ok( null === $status(), 'NEGATIVE: unparseable manifest -> nothing said' );

		$put( array( 'kit_version' => '1.0.0' ) );
		$ok( null === $status(), 'NEGATIVE: no bump_triggers -> nothing said' );

		$put( array( 'kit_version' => '1.0.0', 'bump_triggers' => array( 'capture_service' => '1.2.3' ) ) );
		$ok( null === $status(), 'NEGATIVE: no recorded converter version -> nothing said' );

		@unlink( $tmp . '/kit-manifest.json' );
		$ok( null === $status(), 'NEGATIVE: missing manifest -> nothing said' );
		ob_start(); FW_Site_Converter_Kit::render_notice(); $out = ob_get_clean();
		$ok( '' === $out, 'NEGATIVE: and render_notice() prints nothing' );

		$big = str_repeat( ' ', 70000 );
		$put( $big . wp_json_encode( array( 'kit_version' => '1.0.0', 'bump_triggers' => array( 'site_converter_extension' => '0.0.1' ) ) ) );
		$ok( null === $status(), 'NEGATIVE: an implausibly large manifest is refused, not parsed' );

		echo "\n=== 3. It reads ONE file, inside the root ===\n";

		// A sibling secret next to the kit must be unreachable: the class names its own filename and
		// confines the resolved path to the root, so there is no input that redirects it.
		$secret = dirname( $tmp ) . '/fw-sc-kit-secret-' . wp_generate_password( 6, false ) . '.json';
		file_put_contents( $secret, wp_json_encode( array( 'kit_version' => 'LEAKED', 'bump_triggers' => array( 'site_converter_extension' => '0.0.1' ) ) ) );
		$put( array( 'kit_version' => '1.0.0', 'bump_triggers' => array( 'site_converter_extension' => '0.0.1' ) ) );
		$s = $status();
		$ok( is_array( $s ) && 'LEAKED' !== $s['kit_version'], 'NEGATIVE: a manifest-shaped file OUTSIDE the root is never read' );
		$ok( is_array( $s ) && 0 === strpos( $s['kit_path'], realpath( $tmp ) ), 'the resolved path stays inside the configured root' );
		@unlink( $secret );
	}
}

foreach ( (array) glob( $tmp . '/*' ) as $f ) { @unlink( $f ); }
@rmdir( $tmp );

echo "\n========================================\n";
echo 'KIT CHECK RESULT: ' . ( 0 === $fail ? 'PASS' : 'FAIL' ) . "   ({$pass} passed, {$fail} failed)\n";
echo "========================================\n";
exit( 0 === $fail ? 0 : 1 );
