<?php
/**
 * Guard for the conversion SANDBOX — the site's own corrections, in `wp-content/unysonplus-sandbox/`.
 *
 * Exercises the whole lifecycle against a REAL temporary directory (the `fw_site_converter_sandbox_dir`
 * filter points the loader at it), not a mock: scaffold, load, reject bad entries, apply, retire by probe,
 * revive, report.
 *
 * The assertions that matter most are the negatives, because the failure modes here are all silent:
 *   - one broken entry must not stop the others (or the site),
 *   - a probe that THROWS must NOT retire its entry (silently un-fixing a site is the worst outcome),
 *   - an entry with NO probe must never be retired automatically,
 *   - a retired entry must stop applying.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/sandbox-test.php"
 */

if ( ! class_exists( 'FW_Site_Converter_Sandbox' ) ) {
	fwrite( STDERR, "FAIL: site-converter sandbox not loaded (run inside a WP install with the extension active)\n" );
	exit( 1 );
}

$pass = 0;
$fail = 0;
$ok   = function ( $cond, $what, $detail = '' ) use ( &$pass, &$fail ) {
	if ( $cond ) { $pass++; echo "  \xE2\x9C\x93 {$what}\n"; }
	else { $fail++; echo "  \xE2\x9C\x97 FAIL: {$what}" . ( '' !== $detail ? " -- {$detail}" : '' ) . "\n"; }
};

/* ---------------------------------------------------------------- an isolated sandbox */

$tmp = rtrim( get_temp_dir(), '/\\' ) . '/fw-sc-sandbox-test-' . wp_generate_password( 8, false );
add_filter( 'fw_site_converter_sandbox_dir', function () use ( $tmp ) { return $tmp; } );

// The loader caches entries and the booted flag in statics; reset them between phases.
$reset = function () {
	$r = new ReflectionClass( 'FW_Site_Converter_Sandbox' );
	foreach ( array( 'entries' => null, 'errors' => array(), 'booted' => false ) as $prop => $val ) {
		$p = $r->getProperty( $prop );
		$p->setAccessible( true );
		$p->setValue( null, $val );
	}
};
$cleanup = function () use ( $tmp ) {
	// scandir(), not glob('*'): the scaffold writes a .htaccess, and glob does not match dotfiles — so a
	// glob-based clean left the directory non-empty, rmdir() failed silently, and the run leaked a temp
	// folder while reporting success. The final assertion below is what caught it.
	foreach ( array( $tmp . '/entries', $tmp ) as $d ) {
		if ( ! is_dir( $d ) ) { continue; }
		foreach ( (array) scandir( $d ) as $f ) {
			if ( '.' === $f || '..' === $f ) { continue; }
			if ( is_file( $d . '/' . $f ) ) { @unlink( $d . '/' . $f ); }
		}
	}
	@rmdir( $tmp . '/entries' );
	@rmdir( $tmp );
};
$write = function ( $name, $php ) use ( $tmp ) { file_put_contents( $tmp . '/entries/' . $name, $php ); };

$retired_before = get_option( FW_Site_Converter_Sandbox::OPT_RETIRED, array() );
$probed_before  = get_option( FW_Site_Converter_Sandbox::OPT_PROBED, '' );
delete_option( FW_Site_Converter_Sandbox::OPT_RETIRED );
delete_option( FW_Site_Converter_Sandbox::OPT_PROBED );

echo "\n=== 1. Scaffold ===\n";

$ok( ! FW_Site_Converter_Sandbox::exists(), 'a fresh site has no sandbox' );
$ok( true === FW_Site_Converter_Sandbox::scaffold(), 'scaffold() creates it' );
$ok( FW_Site_Converter_Sandbox::exists(), 'exists() reports it afterwards' );
$ok( file_exists( $tmp . '/README.md' ), 'a README is written' );
$ok( file_exists( $tmp . '/index.php' ) && file_exists( $tmp . '/.htaccess' ), 'the folder is closed to direct web requests' );
$ok( file_exists( $tmp . '/entries/example.php' ), 'an example entry is written' );
$ok( true === FW_Site_Converter_Sandbox::scaffold(), 'scaffold() is idempotent' );

echo "\n=== 2. Loading and validation ===\n";

@unlink( $tmp . '/entries/example.php' );
$reset();

$write( '10-good.php', '<?php return array( "id" => "good", "summary" => "s", "fragment" => "<b>f</b>", "expected" => "e", "apply" => function () { $GLOBALS["fw_sb_applied"][] = "good"; } );' );
$write( '20-no-id.php', '<?php return array( "apply" => function () {} );' );
$write( '30-no-apply.php', '<?php return array( "id" => "no_apply" );' );
$write( '40-not-array.php', '<?php return "nope";' );
$write( '50-throws.php', '<?php throw new RuntimeException( "boom" );' );
$write( '60-dupe.php', '<?php return array( "id" => "good", "apply" => function () {} );' );

$entries = FW_Site_Converter_Sandbox::entries();
$errors  = FW_Site_Converter_Sandbox::errors();

$ok( array_keys( $entries ) === array( 'good' ), 'only the valid entry loads', 'loaded: ' . implode( ',', array_keys( $entries ) ) );
$ok( count( $errors ) === 5, 'every invalid entry is reported', count( $errors ) . ' errors: ' . wp_json_encode( array_keys( $errors ) ) );
$ok( isset( $errors['50-throws.php'] ), 'NEGATIVE: a file that THROWS is caught, not fatal' );
$ok( isset( $errors['60-dupe.php'] ), 'a duplicate id is rejected (first one wins)' );
$ok( '<b>f</b>' === $entries['good']['fragment'], 'the fragment is carried for the report' );

echo "\n=== 3. Applying ===\n";

$GLOBALS['fw_sb_applied'] = array();
FW_Site_Converter_Sandbox::boot();
$ok( array( 'good' ) === $GLOBALS['fw_sb_applied'], 'boot() applies the active entry', wp_json_encode( $GLOBALS['fw_sb_applied'] ) );

FW_Site_Converter_Sandbox::boot();
$ok( array( 'good' ) === $GLOBALS['fw_sb_applied'], 'NEGATIVE: boot() twice applies once (no double-registered filters)' );

// An apply() that throws must be recorded and must not stop the entry after it.
$reset();
$write( '05-bad-apply.php', '<?php return array( "id" => "bad_apply", "apply" => function () { throw new RuntimeException( "apply boom" ); } );' );
$GLOBALS['fw_sb_applied'] = array();
FW_Site_Converter_Sandbox::boot();
$ok( in_array( 'good', $GLOBALS['fw_sb_applied'], true ), 'NEGATIVE: an entry that throws does not stop the others' );
$ok( isset( FW_Site_Converter_Sandbox::errors()['05-bad-apply.php'] ), 'the throwing apply() is reported' );
@unlink( $tmp . '/entries/05-bad-apply.php' );

echo "\n=== 4. Retirement by probe ===\n";

$reset();
$write( '70-fixed.php', '<?php return array( "id" => "fixed_upstream", "probe" => function () { return false; }, "apply" => function () { $GLOBALS["fw_sb_applied"][] = "fixed_upstream"; } );' );
$write( '80-still.php', '<?php return array( "id" => "still_broken", "probe" => function () { return true; }, "apply" => function () {} );' );
$write( '90-throws.php', '<?php return array( "id" => "probe_throws", "probe" => function () { throw new RuntimeException( "probe boom" ); }, "apply" => function () {} );' );

$res = FW_Site_Converter_Sandbox::probe_all();

$ok( array( 'fixed_upstream' ) === $res['retired'], 'a probe returning FALSE retires its entry', wp_json_encode( $res['retired'] ) );
$ok( in_array( 'still_broken', $res['kept'], true ), 'a probe returning TRUE keeps its entry' );
$ok( in_array( 'good', $res['unknown'], true ), 'NEGATIVE: an entry with NO probe is left alone, not retired' );
$ok( in_array( 'probe_throws', $res['failed'], true ), 'a probe that throws is reported as failed' );
$ok( ! FW_Site_Converter_Sandbox::is_retired( 'probe_throws' ), 'NEGATIVE: a THROWING probe does not retire its entry' );
$ok( FW_Site_Converter_Sandbox::is_retired( 'fixed_upstream' ), 'the retirement is persisted' );

$reset();
$GLOBALS['fw_sb_applied'] = array();
FW_Site_Converter_Sandbox::boot();
$ok( ! in_array( 'fixed_upstream', $GLOBALS['fw_sb_applied'], true ), 'a retired entry stops applying', wp_json_encode( $GLOBALS['fw_sb_applied'] ) );

$ok( true === FW_Site_Converter_Sandbox::revive( 'fixed_upstream' ), 'revive() un-retires an entry' );
$ok( false === FW_Site_Converter_Sandbox::revive( 'fixed_upstream' ), 'revive() reports an entry that was not retired' );
$reset();
$GLOBALS['fw_sb_applied'] = array();
FW_Site_Converter_Sandbox::boot();
$ok( in_array( 'fixed_upstream', $GLOBALS['fw_sb_applied'], true ), 'a revived entry applies again' );

echo "\n=== 5. Probing is once per converter version ===\n";

$reset();
$ok( null === FW_Site_Converter_Sandbox::maybe_probe(), 'maybe_probe() does nothing on the version already probed' );
update_option( FW_Site_Converter_Sandbox::OPT_PROBED, 'some-older-version', false );
$reset();
$ok( is_array( FW_Site_Converter_Sandbox::maybe_probe() ), 'maybe_probe() runs again after a converter version change' );
$ok( FW_Site_Converter_Sandbox::converter_version() === (string) get_option( FW_Site_Converter_Sandbox::OPT_PROBED, '' ), 'the probed version is recorded' );

// The version is what gates re-probing, and it degraded to 'unknown' once already (the guard tested
// method_exists() on what is a PROPERTY), which would have frozen every entry as permanently needed.
$ok( 'unknown' !== FW_Site_Converter_Sandbox::converter_version(), 'the real converter version is read, not "unknown"', FW_Site_Converter_Sandbox::converter_version() );
$ok( (bool) preg_match( '/^\\d+\\.\\d+\\.\\d+$/', FW_Site_Converter_Sandbox::converter_version() ), 'NEGATIVE: it LOOKS like a version, so a version change can actually be detected' );

echo "\n=== 6. Report ===\n";

$report = FW_Site_Converter_Sandbox::report();
$ok( false !== strpos( $report, 'good' ), 'the report lists an entry by id' );
$ok( false !== strpos( $report, '<b>f</b>' ), 'the report carries the source fragment' );
$ok( false !== strpos( $report, 'RETIRED' ) || false !== strpos( $report, 'ACTIVE' ), 'each entry is marked active or retired' );
$ok( false !== strpos( $report, 'not a patch' ), 'the report says to send the case, not a patch' );
$ok( false !== strpos( $report, 'would not publish' ), 'the report carries the privacy caution' );

/* ---------------------------------------------------------------- leave nothing behind */

$cleanup();
delete_option( FW_Site_Converter_Sandbox::OPT_RETIRED );
delete_option( FW_Site_Converter_Sandbox::OPT_PROBED );
if ( $retired_before ) { update_option( FW_Site_Converter_Sandbox::OPT_RETIRED, $retired_before, false ); }
if ( '' !== $probed_before ) { update_option( FW_Site_Converter_Sandbox::OPT_PROBED, $probed_before, false ); }
unset( $GLOBALS['fw_sb_applied'] );

$ok( ! is_dir( $tmp ), 'the temporary sandbox is removed' );

echo "\n========================================\n";
echo 'SANDBOX RESULT: ' . ( 0 === $fail ? 'PASS' : 'FAIL' ) . "   ({$pass} passed, {$fail} failed)\n";
echo "========================================\n";
exit( 0 === $fail ? 0 : 1 );
