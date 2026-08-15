<?php
/**
 * reproduce-conversion.php — TOKEN-CHEAP verify harness for the deterministic Site Converter.
 * ---------------------------------------------------------------------------------------------
 * Reproduces the EXACT pipeline the wp-admin "Site Converter" button drives (capture → build),
 * but writes the big built JSON to DISK and prints only a COMPACT summary — so the megabyte-sized
 * rendered HTML and theme-settings.json NEVER flood an AI agent's context window. Any agent (here
 * or another developer's) can use this for converter debugging at a fraction of the token cost of
 * cat-ing raw output.
 *
 * WHY THIS SAVES TOKENS: the cost is not the *running* (that's a shell command) — it's the OUTPUT
 * landing in context. This keeps the blobs on disk; you pull only tiny targeted slices (via the
 * SC_QUERY dot-path below, or a follow-up Grep of the dumped file) into context.
 *
 * USAGE (via wp-cli so WordPress + the extension are loaded):
 *   SC_HTML=C:/path/rendered.html \
 *   SC_URL=https://example.com \
 *   php wp-cli.phar --path='D:\xampp\htdocs' eval-file <this-file>
 *
 * ENV VARS:
 *   SC_HTML   (required) absolute path to the FRESH rendered HTML (from the capture service /capture
 *             or `node capture.mjs <url> <out>` — rendered.html). Use WINDOWS paths (C:/…, D:/…);
 *             Git-Bash /d/… paths FAIL in Windows PHP.
 *   SC_URL    (required) the pasted source URL — passed as `source_url` (the #1 flow bug when omitted:
 *             SVG icons/illustrations break without it).
 *   SC_OUT    (optional) where to dump the FULL built theme-settings.json + pages JSON for targeted
 *             Grep. Default: alongside SC_HTML as <SC_HTML>.built.json
 *   SC_QUERY  (optional) a dot-path into the built theme-settings values to print in full, e.g.
 *             SC_QUERY=main_footer_columns  — prints just that subtree (still small), instead of all.
 *   SC_TITLE  (optional) page title. Default "Home".
 *
 * OUTPUT: a compact one-screen summary (section count, footer columns, icon inline count, leftover
 * code_blocks, heading font, any obvious red flags) + the on-disk path of the full dump. Then
 * Grep/Read only what you need from that file.
 */

if ( ! class_exists( 'FW_Site_Converter_Sources' ) ) {
	fwrite( STDERR, "FATAL: FW_Site_Converter_Sources not loaded — is the site-converter extension active on this install?\n" );
	exit( 1 );
}

$html_path = getenv( 'SC_HTML' );
$src_url   = getenv( 'SC_URL' );
$title     = getenv( 'SC_TITLE' ) ?: 'Home';
$out_path  = getenv( 'SC_OUT' ) ?: ( $html_path ? $html_path . '.built.json' : '' );
$query     = getenv( 'SC_QUERY' );

if ( ! $html_path || ! is_file( $html_path ) ) {
	fwrite( STDERR, "FATAL: SC_HTML missing or not a file: '" . $html_path . "' (use a Windows path like C:/… or D:/…)\n" );
	exit( 1 );
}
if ( ! $src_url ) {
	fwrite( STDERR, "FATAL: SC_URL is required (passed as source_url — omitting it breaks SVG icon/illustration inlining).\n" );
	exit( 1 );
}

$html = file_get_contents( $html_path );
$res  = FW_Site_Converter_Sources::build_from_html(
	$html,
	$title,
	array( 'dynamic_chrome' => true, 'hifi_css' => true, 'source_url' => rtrim( $src_url, '/' ) )
);

if ( empty( $res['files'] ) ) {
	fwrite( STDERR, "FATAL: build_from_html returned no files.\n" );
	exit( 1 );
}
$files = $res['files'];
$ts    = isset( $files['theme-settings.json'] ) ? $files['theme-settings.json'] : array();
$vals  = isset( $ts['values'] ) ? $ts['values'] : $ts;

/* ---- Dump the FULL build to disk (kept OUT of context) ---- */
if ( $out_path ) {
	@file_put_contents( $out_path, wp_json_encode( $files, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
}

/* ---- Compact metrics only (tiny) ---- */
$json_all = wp_json_encode( $files );

$count = function ( $needle ) use ( $json_all ) { return substr_count( $json_all, $needle ); };

// Footer columns quick read.
$foot = 'n/a';
if ( isset( $vals['main_footer_columns'] ) && is_array( $vals['main_footer_columns'] ) ) {
	$mf  = $vals['main_footer_columns'];
	$cnt = isset( $mf['count'] ) ? (int) $mf['count'] : 0;
	$col_texts = array();
	if ( $cnt && isset( $mf[ $cnt ] ) ) {
		for ( $i = 1; $i <= 6; $i++ ) {
			$k = 'main_footer_col_' . $i;
			if ( ! isset( $mf[ $cnt ][ $k ] ) ) { continue; }
			$first = '';
			array_walk_recursive( $mf[ $cnt ][ $k ], function ( $x, $key ) use ( &$first ) {
				if ( $first === '' && ( $key === 'text_content' || $key === 'title' || $key === 'text' ) && is_string( $x ) && trim( $x ) !== '' ) {
					$first = trim( wp_strip_all_tags( $x ) );
				}
			} );
			if ( $first !== '' ) { $col_texts[] = $i . ':' . mb_substr( $first, 0, 32 ); }
		}
	}
	$foot = $cnt . ' cols [' . implode( ' | ', $col_texts ) . ']';
}

// Section count from pages JSON if present.
$sections = 'n/a';
foreach ( $files as $fname => $fdata ) {
	if ( strpos( $fname, 'pages' ) !== false || strpos( $fname, 'page-' ) !== false ) {
		$sections = substr_count( wp_json_encode( $fdata ), '"section"' );
		break;
	}
}

$heading_font = isset( $vals['typography_headings_font'] ) ? $vals['typography_headings_font']
	: ( isset( $vals['section_heading_face'] ) ? $vals['section_heading_face'] : 'n/a' );
if ( is_array( $heading_font ) ) { $heading_font = isset( $heading_font['family'] ) ? $heading_font['family'] : json_encode( $heading_font ); }

echo "================ SITE-CONVERTER REPRODUCE (compact) ================\n";
echo "URL              : " . $src_url . "\n";
echo "HTML in          : " . $html_path . " (" . number_format( strlen( $html ) ) . " bytes)\n";
echo "Full build dumped: " . ( $out_path ?: '(not written)' ) . "  <-- Grep/Read THIS for details\n";
echo "-------------------------------------------------------------------\n";
echo "sections         : " . $sections . "\n";
echo "footer           : " . $foot . "\n";
echo "heading font     : " . ( is_string( $heading_font ) ? $heading_font : json_encode( $heading_font ) ) . "\n";
echo "inline SVG icons : " . $count( 'svg-source' ) . "  (icon-v2 inline)\n";
echo "inline <svg>     : " . $count( '<svg' ) . "\n";
echo "leftover code_blk: " . $count( '"code_block"' ) . "  (illustrations still un-inlined if > expected)\n";
echo "broken /assets/  : " . $count( '"/assets/' ) . "  (should be 0 — relative asset refs = 404s)\n";
echo "spaced rgb(      : " . $count( 'rgb(' ) . "  (colours that kses may strip — prefer hex)\n";
echo "===================================================================\n";

/* ---- Optional targeted subtree (still small) ---- */
if ( $query ) {
	$node = $vals;
	foreach ( explode( '.', $query ) as $seg ) {
		if ( is_array( $node ) && isset( $node[ $seg ] ) ) { $node = $node[ $seg ]; }
		else { $node = "(path '$query' not found at '$seg')"; break; }
	}
	echo "\n--- SC_QUERY: $query ---\n";
	echo wp_json_encode( $node, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
}
