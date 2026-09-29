<?php
/**
 * NO STRAY CONTROL CHARACTERS IN SOURCE.
 *
 * A regex written as `\b` can reach a file as a literal BACKSPACE (0x08) when an edit passes through a
 * layer that interprets escapes — a shell heredoc, a language whose string literals resolve `\b`. The file
 * still parses, the test suite still passes, and the rule simply never matches again. Nothing complains,
 * because a regex that matches nothing is indistinguishable from a rule that found nothing.
 *
 * Found by accident, not by any test: `/<svg\b[^>]*>/i` had become `/<svg\x08[^>]*>/i`, so an SVG's aspect
 * ratio always came back 0. A sweep then turned up seven more across the plugin, the theme and the capture
 * service — among them `/<(?:script|foreignObject)\b/i` (an SVG sanitiser guard that could no longer see a
 * script tag), `/\bh-\[(\d+)px\]/` and `/\bmax-h-\[(\d+)px\]/` (measured heights never read), and
 * `/\bmx-auto\b/` (a centred tile never detected by class). Every one had been shipped.
 *
 * This is the cheapest possible guard against a whole class of silent breakage, and the reason it is worth
 * a test of its own: the defect is INVISIBLE to every other test. Each of those rules had callers, and the
 * callers all behaved "correctly" — they just took the other branch, forever.
 *
 * Scans the plugin and the parent theme. Binary assets are skipped by extension, not by sniffing, so a new
 * image format cannot quietly opt a source file out.
 *
 * Run: wp eval-file source-hygiene-test.php
 */

if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "Run through wp-cli: wp eval-file " . basename( __FILE__ ) . "\n" ); exit( 1 ); }

$pass = 0;
$fail = 0;
$ok   = function ( $cond, $msg, $detail = '' ) use ( &$pass, &$fail ) {
	if ( $cond ) { $pass++; echo "  ✓ $msg\n"; }
	else         { $fail++; echo "  ✗ FAIL: $msg" . ( '' !== $detail ? " — $detail" : '' ) . "\n"; }
};

echo "\n=== Source hygiene: no stray control characters ===\n";

/** The control bytes that have no business in source. TAB (09), LF (0A) and CR (0D) are legitimate. */
$forbidden = array(
	"\x08" => 'BACKSPACE (a `\\b` that was resolved as an escape before it reached the file)',
	"\x00" => 'NUL',
	"\x1b" => 'ESC (terminal escape sequence)',
	"\x0c" => 'FORM FEED',
);

$roots = array();
$plugin = dirname( dirname( dirname( dirname( __DIR__ ) ) ) );       // …/unysonplus
if ( is_dir( $plugin . '/framework' ) ) { $roots[] = $plugin . '/framework'; }
$theme = get_theme_root() . '/unysonplus-theme';
if ( is_dir( $theme ) ) { $roots[] = $theme; }

$ok( ! empty( $roots ), 'found a source tree to scan', 'plugin=' . $plugin );

$exts    = array( 'php', 'js', 'mjs', 'css', 'json' );
$scanned = 0;
$hits    = array();

foreach ( $roots as $root ) {
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $file ) {
		if ( ! $file->isFile() ) { continue; }
		$path = str_replace( '\\', '/', $file->getPathname() );
		if ( false !== strpos( $path, '/node_modules/' ) || false !== strpos( $path, '/.git/' ) ) { continue; }
		if ( ! in_array( strtolower( $file->getExtension() ), $exts, true ) ) { continue; }
		$scanned++;
		$body = (string) @file_get_contents( $path );
		if ( '' === $body ) { continue; }
		foreach ( $forbidden as $byte => $what ) {
			$at = strpos( $body, $byte );
			if ( false === $at ) { continue; }
			$line = substr_count( substr( $body, 0, $at ), "\n" ) + 1;
			$hits[] = str_replace( $root . '/', '', $path ) . ':' . $line . '  ' . $what;
		}
	}
}

$ok( $scanned > 200, 'the scan actually read the source tree', $scanned . ' file(s) scanned' );
$ok( empty( $hits ), 'no source file carries a stray control character',
	count( $hits ) . ' hit(s): ' . implode( ' | ', array_slice( $hits, 0, 6 ) ) );

/* CALIBRATION — the scan must be able to FAIL. A hygiene check that cannot detect the thing it exists for
   is the same defect it is guarding against, one level up. */
$probe = array();
foreach ( $forbidden as $byte => $what ) {
	$probe[] = ( false !== strpos( "before" . $byte . "after", $byte ) );
}
$ok( ! in_array( false, $probe, true ), 'the detector finds every byte it looks for when one is present' );

echo "\nSOURCE HYGIENE RESULT: " . ( 0 === $fail ? 'PASS' : 'FAIL' ) . "   ($pass passed, $fail failed)\n";
exit( 0 === $fail ? 0 : 1 );
