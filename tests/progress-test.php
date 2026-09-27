<?php
/**
 * Guard for the build-progress reporter (FW_Site_Converter_Progress).
 *
 * "Build the site from this mapping" runs for seconds on a small page and close to a minute on a large one,
 * and used to show nothing but a disabled button reading "Building..." -- which reads as a hang. The panel
 * that replaced it is only as honest as this state machine, so the assertions below are mostly NEGATIVES:
 * a step that never ran must not be left spinning, a failure must stop the panel implying work continues,
 * and a stray step() must not be able to start a phantom run.
 *
 * Cross-process visibility (the browser polls while the build POST is still in flight) is the other half of
 * the design and cannot be asserted in one process; it was verified by running a writer and a reader as
 * separate wp-cli processes and watching the reader observe each step advance live.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file  *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/progress-test.php"
 */

if ( ! class_exists( 'FW_Site_Converter_Progress' ) ) {
	fwrite( STDERR, "FAIL: FW_Site_Converter_Progress not loaded (run inside a WP install with the extension active)
" );
	exit( 1 );
}


$pass = 0; $fail = 0;
$ok = function ( $c, $what, $d = '' ) use ( &$pass, &$fail ) {
	if ( $c ) { $pass++; echo "  \xE2\x9C\x93 {$what}\n"; }
	else { $fail++; echo "  \xE2\x9C\x97 FAIL: {$what}" . ( '' !== $d ? " -- {$d}" : '' ) . "\n"; }
};

$steps = array( 'pages' => 'Pages', 'media' => 'Media', 'presets' => 'Presets', 'theme' => 'Theme', 'finish' => 'Finish' );

echo "\n=== nothing running ===\n";
FW_Site_Converter_Progress::clear();
$ok( null === FW_Site_Converter_Progress::read(), 'NEGATIVE: with no run, read() says nothing (the poller shows no panel)' );

echo "\n=== a run reports its steps ===\n";
FW_Site_Converter_Progress::start( $steps );
$p = FW_Site_Converter_Progress::read();
$ok( is_array( $p ) && count( $p['steps'] ) === 5, 'the WHOLE list is visible from the first poll', is_array( $p ) ? count( $p['steps'] ) . ' steps' : 'null' );
$ok( $p['steps'][0]['state'] === 'pending', 'before any step runs, nothing claims to be running' );

FW_Site_Converter_Progress::step( 'pages' );
$p = FW_Site_Converter_Progress::read();
$ok( $p['steps'][0]['state'] === 'running', 'the current step reads as running' );
$ok( $p['steps'][1]['state'] === 'pending', 'later steps stay pending' );

FW_Site_Converter_Progress::step( 'presets' );
$p = FW_Site_Converter_Progress::read();
$st = array();
foreach ( $p['steps'] as $s ) { $st[ $s['key'] ] = $s['state']; }
$ok( 'done' === $st['pages'], 'an earlier step is marked done' );
$ok( 'done' === $st['media'], 'NEGATIVE: a SKIPPED step is done, not stuck spinning (media never ran)', wp_json_encode( $st ) );
$ok( 'running' === $st['presets'], 'the new step is running' );

echo "\n=== elapsed ===\n";
$ok( isset( $p['elapsed'] ) && $p['elapsed'] >= 0, 'elapsed seconds are reported for the "still working" line' );
$ok( isset( $p['step_secs'] ), 'time in the current step is reported' );

echo "\n=== finishing ===\n";
FW_Site_Converter_Progress::finish();
$p = FW_Site_Converter_Progress::read();
$done = array_filter( $p['steps'], function ( $s ) { return 'done' === $s['state']; } );
$ok( count( $done ) === 5, 'finish() completes every step', count( $done ) . '/5' );
$ok( ! empty( $p['complete'] ), 'the run reports complete' );
$ok( '' === $p['current'], 'NEGATIVE: nothing is left running' );

echo "\n=== failing ===\n";
FW_Site_Converter_Progress::start( $steps );
FW_Site_Converter_Progress::step( 'media' );
FW_Site_Converter_Progress::fail( 'the bundle had no sections' );
$p = FW_Site_Converter_Progress::read();
$ok( ! empty( $p['failed'] ), 'a failed run says so' );
$ok( false !== strpos( $p['message'], 'no sections' ), 'the failure message is carried' );

echo "\n=== a stray step cannot start a run ===\n";
FW_Site_Converter_Progress::clear();
FW_Site_Converter_Progress::step( 'media' );
$ok( null === FW_Site_Converter_Progress::read(), 'NEGATIVE: step() without start() reports nothing' );

FW_Site_Converter_Progress::clear();

echo "\n========================================\n";
echo 'PROGRESS RESULT: ' . ( 0 === $fail ? 'PASS' : 'FAIL' ) . "   ({$pass} passed, {$fail} failed)\n";
echo "========================================\n";
exit( 0 === $fail ? 0 : 1 );
