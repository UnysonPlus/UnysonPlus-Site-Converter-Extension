<?php
/**
 * Regression guard: a band's SHARED prose selector must not be claimed twice with different type.
 *
 * `collect_section_style()` styles prose SECTION-SCOPED (`#sec .text-block`) so the tag itself stays
 * class-free. That selector matches EVERY text block in the band, so two blocks with different measured
 * type both registered under it at the same specificity and the LAST one silently won for all of them —
 * a hero paragraph rendered its sibling caption's 16px leading over its own 29.25px.
 *
 * The rule under test (claim_or_own): the FIRST block keeps the shared rule; a later block whose
 * declarations DIFFER carries its own `selector:is(.text-block)` rule (a compound on the block's unique
 * class — strictly more specific, so emission order cannot decide it). Blocks that measure the SAME keep
 * sharing one rule, so the clean-DOM default is not traded away for the common case.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/prose-selector-clash-test.php"
 *
 * Exit code 0 = all PASS, 1 = at least one FAIL.
 */

if ( ! class_exists( 'FW_Site_Converter_Sources' ) ) {
	fwrite( STDERR, "FAIL: site-converter not loaded (run inside a WP install with the plugin active)\n" );
	exit( 1 );
}

$fails = 0;
$ok    = function ( $cond, $msg ) use ( &$fails ) {
	if ( $cond ) { echo "  ✓ $msg\n"; } else { $fails++; echo "  ✗ FAIL: $msg\n"; }
};

/** Build one section of paragraphs, each with its own measured line-height stamp. */
$convert = function ( array $leadings ) {
	$ps = '';
	foreach ( $leadings as $i => $lh ) {
		$ps .= '<p data-sc-cs="font-size:18px;line-height:' . $lh . ';color:rgb(20, 20, 20)">'
			. 'Paragraph number ' . ( $i + 1 ) . ' with enough words in it to read as prose rather than a label.</p>';
	}
	$html = '<!doctype html><html><head><title>Clash</title></head><body>'
		. '<section id="band"><div>' . $ps . '</div></section>'
		. '</body></html>';
	// The section rules land in the child-theme stylesheet via registered_css(), which ACCUMULATES across
	// conversions in one process — so take the delta this build added, never the running total (the fixture
	// has no phone tier, so registered_css() appends in insertion order and the delta is a clean suffix).
	$before = FW_Site_Converter_Mapper::registered_css();
	$res    = FW_Site_Converter_Sources::build_from_html( $html, 'Clash', array() );
	$page   = $res['files']['pages.json']['pages'][0] ?? array();
	$css    = substr( FW_Site_Converter_Mapper::registered_css(), strlen( $before ) );
	// Every node's own Custom CSS, in tree order.
	$own  = array();
	$walk = function ( $n ) use ( &$walk, &$own ) {
		if ( ! is_array( $n ) ) { return; }
		$sc = (string) ( $n['shortcode'] ?? ( $n['atts']['shortcode'] ?? '' ) );
		if ( 'text_block' === $sc ) { $own[] = (string) ( $n['atts']['custom_css'] ?? '' ); }
		foreach ( $n as $v ) { $walk( $v ); }
	};
	$walk( $page );
	return array( 'css' => $css, 'own' => $own );
};

echo "\n== Prose selector clash (band-shared `.text-block`)\n";

// THREE paragraphs, three different measured leadings — the shape that silently collapsed.
$r      = $convert( array( '29.25px', '22.75px', '16px' ) );
$shared = preg_match_all( '/#band\s+\.text-block\s*\{/', $r['css'] );
$ok( 3 === count( $r['own'] ), 'three text blocks were built (got ' . count( $r['own'] ) . ')' );
$ok( 1 === $shared, 'the shared `#band .text-block` rule is registered exactly ONCE (got ' . $shared . ')' );

$narrowed = array_values( array_filter( $r['own'], function ( $c ) {
	return false !== strpos( $c, 'selector:is(.text-block)' );
} ) );
$ok( 2 === count( $narrowed ), 'the two LATER blocks carry their own narrowed rule (got ' . count( $narrowed ) . ')' );
$ok( isset( $narrowed[0] ) && false !== strpos( $narrowed[0], '#band selector:is(.text-block)' ),
	'a narrowed rule is section-scoped AND compounded on the block (beats `#band .text-block`)' );

// The cascade OUTCOME, not just the presence of three values: before the fix all three leadings were
// still emitted — they simply sat under one selector and overwrote each other. So assert WHERE each
// one landed: the first block's leading on the shared rule, the other two on their own blocks.
$ok( (bool) preg_match( '/#band\s+\.text-block\s*\{[^}]*line-height:29\.25px/', $r['css'] ),
	"the FIRST block's leading is what the shared rule carries (29.25px)" );
$ok( isset( $r['own'][1] ) && false !== strpos( $r['own'][1], 'line-height:22.75px' ),
	"the second block carries its OWN leading (22.75px), not a sibling's" );
$ok( isset( $r['own'][2] ) && false !== strpos( $r['own'][2], 'line-height:16px' ),
	"the third block carries its OWN leading (16px), not a sibling's" );
$ok( ! preg_match( '/#band\s+\.text-block\s*\{[^}]*line-height:(?:22\.75|16)px/', $r['css'] ),
	'NEGATIVE: no sibling leading is left on the shared rule to overwrite the first block' );

// NEGATIVE: paragraphs that measure the SAME must keep sharing one rule — no per-node duplication,
// so the clean-DOM default is not paid for by every band.
$same = $convert( array( '22.75px', '22.75px', '22.75px' ) );
$ok( 1 === preg_match_all( '/#band\s+\.text-block\s*\{/', $same['css'] ),
	'NEGATIVE: identical leadings still share ONE section rule' );
$ok( 0 === count( array_filter( $same['own'], function ( $c ) {
	return false !== strpos( $c, 'selector:is(.text-block)' );
} ) ), 'NEGATIVE: identical leadings emit NO narrowed per-node rule' );

echo $fails ? "\n✗ $fails FAILED\n" : "\n✓ ALL PASS — band-shared prose selector cannot be silently overwritten\n";
exit( $fails ? 1 : 0 );
