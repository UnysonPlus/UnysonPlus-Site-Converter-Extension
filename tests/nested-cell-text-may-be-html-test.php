<?php
/**
 * Regression guard: a nested grid cell whose `text` is HTML must not crash the converter.
 *
 * WHY THIS FILE EXISTS. A real multi-page conversion died with a FATAL on its pricing page:
 *
 *   TypeError: FW_Site_Converter_Mapper::n_text_cell(): Argument #1 ($t) must be of type array,
 *   string given, called in class-fw-site-converter-mapper.php on line 15185
 *
 * Not a drift — the whole import stopped, so every page after it was never converted.
 *
 * THE CAUSE. Two producers write the key `text` on a cell, with two different value shapes:
 *
 *   1. a structured TEXT CELL  — `array( overline, title, subtitle, titleTag, … )`, which `n_text_cell()`
 *      takes and turns into a special_heading;
 *   2. a role-tagged TEXT BLOCK — `role => 'text'` plus `text => '<div>…</div>'`, a raw HTML string. The
 *      producer's own comment says it: "A text-only cell → an editable text_block (role 'text'), NOT an
 *      opaque code_block."
 *
 * Four call sites consume it, and they did not agree. Two already guarded the shape
 * (`is_array( $c['text'] ) ? text_cell_to_html(…) : (string) $c['text']`). Two tested only that the key
 * was non-empty and handed the string straight to an `array`-typed parameter. The same question, asked in
 * four places with three private answers — which is the recurring root cause this protocol names.
 *
 * The shape reached that consumer through the DECOMPOSED-COLUMN path: a panel with an eyebrow label and a
 * vertical list of items is deliberately decomposed into a single-track nested grid (`gridCols: 1`) so the
 * label and each item survive. The cells of that grid are built by the same producer that emits
 * `role => 'text'`, so a text-only item in a decomposed panel is exactly the combination nothing handled.
 *
 * The fixture is a REDUCED REAL CAPTURE, not hand-written: the band was cut from a captured pricing page
 * and trimmed while it still reproduced (75 KB → 12.7 KB). A first attempt at a synthetic fixture did NOT
 * reproduce, because the decomposition needs a lead overline AND an inner list of two or more items — the
 * reason 5d says to reduce a capture instead of inventing one.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/nested-cell-text-may-be-html-test.php"
 *
 * Exit code 0 = all PASS, 1 = at least one FAIL.
 */

if ( ! class_exists( 'FW_Site_Converter_Stitch' ) || ! class_exists( 'FW_Site_Converter_Mapper' ) ) {
	fwrite( STDERR, "FAIL: site-converter not loaded\n" );
	exit( 1 );
}

$fails = 0;
$ok    = function ( $cond, $msg ) use ( &$fails ) {
	if ( $cond ) { echo "  \xe2\x9c\x93 $msg\n"; } else { $fails++; echo "  \xe2\x9c\x97 FAIL: $msg\n"; }
};

$fixture = __DIR__ . '/fixtures/golden-fixture-5-decomposed-stack.html';
if ( ! is_file( $fixture ) ) {
	fwrite( STDERR, "FAIL: fixture missing: $fixture\n" );
	exit( 1 );
}

$map = FW_Site_Converter_Stitch::html_to_mapping( file_get_contents( $fixture ), 'Pricing', 'pricing', true );

echo "\n== The capture really does produce the shape that crashed\n";

// If this stops being true the fixture has drifted and the rest of the file proves nothing — so assert it
// rather than assume it.
$string_text_cells = 0;
$walk = function ( $v ) use ( &$walk, &$string_text_cells ) {
	if ( ! is_array( $v ) ) { return; }
	if ( isset( $v['cells'] ) && is_array( $v['cells'] ) ) {
		foreach ( $v['cells'] as $c ) {
			if ( is_array( $c ) && array_key_exists( 'text', $c ) && ! is_array( $c['text'] ) && '' !== trim( (string) $c['text'] ) ) {
				$string_text_cells++;
			}
		}
	}
	foreach ( $v as $x ) { $walk( $x ); }
};
$walk( $map );
$ok( $string_text_cells > 0, "a nested grid cell carries `text` as an HTML STRING ($string_text_cells found)" );

echo "\n== …and the build survives it\n";

$pages = null;
$err   = '';
try {
	$pages = FW_Site_Converter_Mapper::build_pages( $map );
} catch ( \TypeError $e ) {
	$err = $e->getMessage();
} catch ( \Throwable $e ) {
	$err = get_class( $e ) . ': ' . $e->getMessage();
}
$ok( '' === $err, 'build_pages() does not throw' . ( '' !== $err ? ' — ' . $err : '' ) );
$ok( is_array( $pages ) && ! empty( $pages ), 'and it returns pages' );

echo "\n== …carrying that cell's content, as an editable text block\n";

$blob = is_array( $pages ) ? wp_json_encode( $pages ) : '';
// The producer's intent, in its own words: an editable text_block, never an opaque code_block.
$ok( false !== strpos( $blob, 'text_block' ), 'the page contains a text_block' );
// The band's own copy must survive — a crash-fix that silently drops the cell is not a fix.
$ok( false !== strpos( $blob, 'Included with package' ) || false !== strpos( $blob, 'billed monthly' ),
	'the text-only cell\'s copy survives into the page' );

echo "\n== NEGATIVE: the ARRAY shape keeps its own meaning\n";

// The two shapes must stay distinguishable, or a guard that is too eager turns every structured text cell
// into a plain text block and the page loses its headings.
//
// Asserted at the unit level, deliberately. No fixture in the suite produces an ARRAY-shaped `text` on a
// NESTED grid cell -- measured across all eight -- so an integration assertion for it would be exercising
// an unreachable path and its result would mean nothing. What guards the array path end to end is
// `output-snapshot-test.php`, which diffs the whole built output of every fixture; this asserts the
// contract that guard depends on.
$ntc = new ReflectionMethod( 'FW_Site_Converter_Mapper', 'n_text_cell' );
$ntc->setAccessible( true );
$nodes = (array) $ntc->invoke( null, array(
	'overline' => 'EYEBROW',
	'title'    => 'A Structured Title',
	'titleTag' => 'h2',
	'subtitle' => 'And its subtitle.',
) );
$njson = wp_json_encode( $nodes );
$ok( ! empty( $nodes ), 'an array-shaped text cell still yields nodes' );
$ok( false !== strpos( $njson, 'special_heading' ), 'a special_heading, not a text_block' );
$ok( false !== strpos( $njson, 'A Structured Title' ), 'carrying its title' );
$ok( false !== strpos( $njson, 'EYEBROW' ), 'and its overline' );

printf( "\n%s\n", $fails ? "{$fails} FAIL(S)" : 'ALL PASS' );
exit( $fails ? 1 : 0 );
