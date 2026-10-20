<?php
/**
 * Regression guard: a press / accolade strip is not a statistics band, and the real statistics band
 * beside it still is.
 *
 * WHY THIS FILE EXISTS. A strip of four cells -- a publication's NAME over its citation --
 *
 *     CHAMBERS / Band 1 Litigation 2025   LEGAL 500 / Top Tier Dispute Firm
 *     GAR 100  / Global Elite Arbitration BENCHMARK / Litigation Firm of the Year
 *
 * was claimed by `is_counter_grid()`, because two of the four names END IN A NUMBER and
 * `counter_cell_parse()` reads that as a value with a prefix: "LEGAL 500" became the prefix LEGAL and
 * the number 500. The counter element then ANIMATED them, so the rendered page showed the strip
 * counting upward -- a screenshot caught it mid-run at 228 and 45 -- and "LEGAL 500" / "GAR 100" never
 * appeared anywhere as written.
 *
 * THE EVIDENCE IS THE OTHER TWO CELLS. CHAMBERS and BENCHMARK carry no number at all. A statistics band
 * is homogeneous, because its cells are the same measurement in different units; a band where half the
 * values are words is a list of names. `stat_word_cell()` was added so a stat band could carry a glyph
 * value among its numbers (an infinity sign over "Creativity"), and allowing ANY number of those is what
 * let this strip in.
 *
 * Measured before changing the gate: of 17 containers `is_counter_grid()` claims across the capture
 * corpus, 16 have NO word-valued cell and exactly one has two -- this strip. So "at most one word cell"
 * refuses the defect and leaves every genuine stat band it already claimed untouched. The second half of
 * the fixture is that claim, pinned: refusing the strip must not cost the real band beside it.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/press-strip-is-not-a-stat-band-test.php"
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

$ref = new ReflectionClass( 'FW_Site_Converter_Stitch' );
$icg = $ref->getMethod( 'is_counter_grid' ); $icg->setAccessible( true );

$fixture = __DIR__ . '/fixtures/press-strip-is-not-a-stat-band.html';
if ( ! is_file( $fixture ) ) { fwrite( STDERR, "FAIL: fixture missing\n" ); exit( 1 ); }
$html = (string) file_get_contents( $fixture );

$dom = new DOMDocument();
libxml_use_internal_errors( true );
$dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html );
libxml_clear_errors();

/** The two grids, by the text they contain. */
$grid_with = function ( $needle ) use ( $dom ) {
	foreach ( $dom->getElementsByTagName( 'div' ) as $d ) {
		$cs = (string) $d->getAttribute( 'data-sc-cs' );
		if ( false === strpos( $cs, 'display:grid' ) ) { continue; }
		if ( false !== strpos( (string) $d->textContent, $needle ) ) { return $d; }
	}
	return null;
};
$strip = $grid_with( 'CHAMBERS' );
$stats = $grid_with( '150+' );

echo "\n== The strip is refused\n";

$ok( $strip instanceof DOMElement, 'the press strip is in the fixture' );
$ok( false === $icg->invoke( null, $strip ), 'is_counter_grid does NOT claim it' );

echo "\n== ...and the real stat band is still claimed\n";

$ok( $stats instanceof DOMElement, 'the stat band is in the fixture' );
$ok( true === $icg->invoke( null, $stats ), 'is_counter_grid still claims 150+ / $10B+ / 40+' );

echo "\n== Every one of the strip's eight runs survives the conversion\n";

// Refusing a recognizer is only half an answer: whatever claims the band instead has to carry the text.
// This is the assertion that would have caught a refusal that merely moved the loss somewhere else.
FW_Site_Converter_Mapper::set_style_config( array() );
$pages = FW_Site_Converter_Mapper::build_pages( FW_Site_Converter_Stitch::html_to_mapping( $html, 'I', 'i', true ) );
$built = wp_json_encode( $pages );
foreach ( array( 'CHAMBERS', 'Band 1 Litigation 2025', 'LEGAL 500', 'Top Tier Dispute Firm',
	'GAR 100', 'Global Elite Arbitration', 'BENCHMARK', 'Litigation Firm of the Year' ) as $run ) {
	$ok( false !== strpos( $built, $run ), sprintf( 'carries %s', wp_json_encode( $run ) ) );
}
// ...and the numbers must NOT have been torn out of the names into counter values.
$ok( false === strpos( $built, '"LEGAL"' ), 'and "LEGAL" is not left as a counter prefix with 500 split off it' );

echo "\n== NEGATIVE: one word-valued cell among numbers is still a stat band\n";

// stat_word_cell() exists for exactly this: a glyph value sitting among real numbers. The new bar is
// "at most one", not "none", so that case keeps working.
$mixed = '<div data-sc-cs="display:grid;grid-template-columns:300px 300px 300px">'
	. '<div data-sc-cs="display:block"><p data-sc-cs="font-size:44px;display:block">150+</p><p data-sc-cs="font-size:11px;display:block">Cases</p></div>'
	. '<div data-sc-cs="display:block"><p data-sc-cs="font-size:44px;display:block">40+</p><p data-sc-cs="font-size:11px;display:block">Jurisdictions</p></div>'
	. '<div data-sc-cs="display:block"><p data-sc-cs="font-size:44px;display:block">&#8734;</p><p data-sc-cs="font-size:11px;display:block">Creativity</p></div></div>';
$d2 = new DOMDocument();
libxml_use_internal_errors( true );
$d2->loadHTML( '<?xml encoding="utf-8" ?><div id="r">' . $mixed . '</div>' );
libxml_clear_errors();
$ok( true === $icg->invoke( null, $d2->getElementById( 'r' )->firstChild ),
	'two numbers and one glyph value is still claimed' );

printf( "\n%s\n", $fails ? "{$fails} FAIL(S)" : 'ALL PASS' );
exit( $fails ? 1 : 0 );
