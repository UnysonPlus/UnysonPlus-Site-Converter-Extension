<?php
/**
 * Output snapshot guard — the safety net for refactoring the converter into targets.
 *
 * Runs every tests/fixtures/*.html through FW_Site_Converter_Sources::build_from_html() and compares
 * the WHOLE result against a stored snapshot in tests/snapshots/<fixture>.json:
 *   - `mapping`         the analysis output (the Site Model's source) — guards the neutral layer,
 *   - `files`           every bundle file the build writes (pages.json, theme-settings.json, …) —
 *                        guards the Unyson+ output byte for byte.
 *
 * The refactor rule is "Unyson+ output byte-identical": any diff here is a regression unless the
 * change was meant to alter output, in which case re-record with SC_SNAPSHOT=update and say why.
 *
 * Run (the installed copy is what loads, so mirror first):
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file <this file>
 *   SC_SNAPSHOT=update  → (re)record the snapshots instead of comparing.
 *   SC_ONLY=<fixture>   → one fixture only (file name without .html).
 *
 * Exit 0 = all match, 1 = a diff (the first differing JSON path is printed per fixture).
 *
 * SITE STATE IS AN INPUT. The build reads the active theme's current Theme Settings (it emits only what
 * differs from them), so converting something else into the same install between recording and comparing
 * moves the output with no code change at all. Record and compare against the same site state; when a diff
 * appears after an unrelated import, rerun the PREVIOUS code on the current state before blaming the change.
 *
 * PLUGIN STATE IS AN INPUT TOO. Built atts (and the conversion map that summarises them) are shaped by the option
 * schemas of OTHER extensions installed beside the converter — e.g. `interaction_scope` arrives from the Animation
 * Engine's hover settings, not from any converter code. A plugin-wide mirror can therefore move a snapshot. Before
 * attributing a diff to the converter, grep the key in site-converter/includes; record only on a settled install.
 */

if ( ! class_exists( 'FW_Site_Converter_Sources' ) ) {
	fwrite( STDERR, "FAIL: site-converter not loaded (run inside a WP install with the plugin active)\n" );
	exit( 1 );
}

$update = getenv( 'SC_SNAPSHOT' ) === 'update';
$only   = (string) getenv( 'SC_ONLY' );
$dir    = __DIR__ . '/snapshots';
if ( $update && ! is_dir( $dir ) ) { wp_mkdir_p( $dir ); }

/** Strip values that legitimately vary per run (timestamps, temp paths, random ids). */
$normalize = function ( $v ) use ( &$normalize ) {
	if ( is_array( $v ) ) {
		$out = array();
		foreach ( $v as $k => $x ) {
			if ( in_array( $k, array( 'generated_at', 'generated', 'timestamp', 'built_at', 'time' ), true ) ) { continue; }
			$out[ $k ] = $normalize( $x );
		}
		return $out;
	}
	if ( is_string( $v ) ) {
		$v = preg_replace( '#[A-Za-z]:[\\\\/][^"\'\s]*?[\\\\/](?:Temp|tmp)[\\\\/][^"\'\s]*#', '<tmp>', $v );
	}
	return $v;
};

/** First differing path between two decoded JSON values ('' when equal). */
$first_diff = function ( $a, $b, $path = '$' ) use ( &$first_diff ) {
	if ( is_array( $a ) && is_array( $b ) ) {
		$keys = array_unique( array_merge( array_keys( $a ), array_keys( $b ) ) );
		foreach ( $keys as $k ) {
			if ( ! array_key_exists( $k, $a ) ) { return "$path.$k (only in current)"; }
			if ( ! array_key_exists( $k, $b ) ) { return "$path.$k (missing from current)"; }
			$d = $first_diff( $a[ $k ], $b[ $k ], "$path.$k" );
			if ( $d !== '' ) { return $d; }
		}
		return '';
	}
	return $a === $b ? '' : $path . '  snapshot=' . substr( wp_json_encode( $a ), 0, 140 ) . '  current=' . substr( wp_json_encode( $b ), 0, 140 );
};

$fail = 0;
$runs = 0;
foreach ( glob( __DIR__ . '/fixtures/*.html' ) as $fixture ) {
	$name = basename( $fixture, '.html' );
	if ( $only !== '' && $only !== $name ) { continue; }
	$runs++;

	$bundle = FW_Site_Converter_Sources::build_from_html(
		file_get_contents( $fixture ),
		'Snapshot',
		array( 'dynamic_chrome' => true, 'hifi_css' => true, 'source_url' => 'https://fixture.invalid' )
	);
	$current = $normalize( array(
		'mapping' => isset( $bundle['mapping'] ) ? $bundle['mapping'] : null,
		'files'   => isset( $bundle['files'] ) ? $bundle['files'] : null,
	) );
	// Node unique_ids are random_bytes() per run, and their 8-char prefix keys the conversion map / scope
	// classes. Rename each to a stable token in order of first appearance so only REAL changes diff.
	$json = wp_json_encode( $current, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	if ( preg_match_all( '/\b[0-9a-f]{32}\b/', $json, $m ) ) {
		$n = 0;
		$swap = array();
		foreach ( array_unique( $m[0] ) as $uid ) {
			$n++;
			$swap[ $uid ]                 = "uid-$n";
			$swap[ substr( $uid, 0, 8 ) ] = "h-$n";
		}
		$json = strtr( $json, $swap );
	}
	// Round-trip through JSON so the comparison sees exactly what a stored snapshot holds.
	$current = json_decode( $json, true );
	$file    = "$dir/$name.json";

	if ( $update ) {
		file_put_contents( $file, wp_json_encode( $current, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		echo "  REC   $name\n";
		continue;
	}
	if ( ! is_file( $file ) ) { echo "  FAIL  $name — no snapshot (run with SC_SNAPSHOT=update)\n"; $fail++; continue; }
	$snap = json_decode( file_get_contents( $file ), true );
	$diff = $first_diff( $snap, $current );
	if ( $diff === '' ) {
		echo "  PASS  $name\n";
	} else {
		echo "  FAIL  $name  first diff at $diff\n";
		$fail++;
	}
}

echo "\n" . ( $update ? "Recorded $runs snapshot(s).\n" : ( $runs - $fail ) . "/$runs fixture(s) match.\n" );
exit( $fail ? 1 : 0 );
