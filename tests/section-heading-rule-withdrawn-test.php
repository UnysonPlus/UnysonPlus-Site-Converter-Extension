<?php
/**
 * Regression guard: when two headings of the same level in one band disagree, the SHARED section rule is
 * withdrawn rather than left to overrule one of them.
 *
 * The unified element styler emits a section-scoped rule per heading — `#section-2 h2:not([class*="boxp-"] *)`
 * — carrying the measured type. It is emitted per BLOCK but selected per SECTION, so a band holding two h2s
 * produces two rules with the same selector and different bodies, and one heading is always described by the
 * other's rule. It wins loudly: an id selector with `!important` outranks the heading's own per-element
 * `.uXXXX .heading-title{…}` rule.
 *
 * Measured on a captured source: a band whose eyebrow h2 is 16px uppercase and whose headline h2 is 31px
 * mixed-case rendered the HEADLINE at 16px and uppercase — two lines where the source has five. It was the
 * page's single largest spacing drift at -178px, which is why this reads as a spacing bug as much as a
 * typography one; fixing it took that band to -70px.
 *
 * Why WITHDRAW rather than narrow, which is what prose does:
 *
 *   - Narrowing hands the losing block a more specific rule to carry on its own node. That works for prose
 *     because the node is built in the same pass. A heading's is not: a heading and its subtitle merge into
 *     ONE special_heading cluster flushed later, so by the time a node exists the rule has nowhere to go.
 *     Attaching it to whichever cluster was pending put the headline's 31px rule on the EYEBROW's node —
 *     one node early, and just as wrong as losing it. Both failure modes were measured here.
 *   - Withdrawing costs nothing, because every heading already carries its own complete
 *     `selector .heading-title{…}` rule (face, size, weight, line-height, tracking, case) from
 *     apply_hifi_base. The shared rule is a convenience, never the carrier.
 *
 * Withdrawal is also SELECTIVE: a band whose headings agree keeps its shared rule. On the measured page 7 of
 * the 8 section heading rules survived; only the band with two disagreeing h2s lost its own.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/section-heading-rule-withdrawn-test.php"
 *
 * Exit code 0 = all PASS, 1 = at least one FAIL.
 */

if ( ! class_exists( 'FW_Site_Converter_Mapper' ) ) {
	fwrite( STDERR, "FAIL: site-converter mapper not loaded\n" );
	exit( 1 );
}

$fails = 0;
$ok    = function ( $cond, $msg ) use ( &$fails ) {
	if ( $cond ) { echo "  \xe2\x9c\x93 $msg\n"; } else { $fails++; echo "  \xe2\x9c\x97 FAIL: $msg\n"; }
};

$claim = new ReflectionMethod( 'FW_Site_Converter_Mapper', 'claim_or_own' );
$claim->setAccessible( true );
$reset = new ReflectionProperty( 'FW_Site_Converter_Mapper', 'style_css' );
$reset->setAccessible( true );
$keys = new ReflectionProperty( 'FW_Site_Converter_Mapper', 'style_key' );
$keys->setAccessible( true );
$cl = new ReflectionProperty( 'FW_Site_Converter_Mapper', 'style_claim' );
$cl->setAccessible( true );
$reg = new ReflectionProperty( 'FW_Site_Converter_Mapper', 'sec_reg' );
$reg->setAccessible( true );
$own = new ReflectionProperty( 'FW_Site_Converter_Mapper', 'style_own' );
$own->setAccessible( true );

$clear = function () use ( $reset, $keys, $cl, $reg, $own ) {
	$reset->setValue( null, array() );
	$keys->setValue( null, array() );
	$cl->setValue( null, array() );
	$reg->setValue( null, array() );
	$own->setValue( null, '' );
};
$css_of = function () use ( $reset ) { return implode( "\n", array_filter( (array) $reset->getValue( null ) ) ); };

$H   = 'h2:not([class*="boxp-"] *)';
$big = array( 'font-size' => '31px', 'text-transform' => 'none' );
$sml = array( 'font-size' => '16px', 'text-transform' => 'uppercase' );

echo "\n== Two headings that DISAGREE: the shared rule is withdrawn\n";

$clear();
$claim->invoke( null, 'section-2', $H, $sml, true );  // the eyebrow claims first
$css = $css_of();
$ok( false !== strpos( $css, '#section-2 ' . $H ), 'the first heading registers the shared rule' );

$claim->invoke( null, 'section-2', $H, $big, true );  // the headline disagrees
$css = $css_of();
$ok( false === strpos( $css, '#section-2 ' . $H ), 'the shared rule is GONE once a second heading disagrees' );
$ok( false === strpos( $css, '16px' ), "and it does not survive carrying the eyebrow's 16px" );
$ok( '' === $own->getValue( null ), 'no narrowed rule is produced for a heading (it would have nowhere to attach)' );

echo "\n== SELECTIVE: a band whose headings AGREE keeps its rule\n";

$clear();
$claim->invoke( null, 'section-5', $H, $big, true );
$claim->invoke( null, 'section-5', $H, $big, true ); // identical — not a disagreement
$css = $css_of();
$ok( false !== strpos( $css, '#section-5 ' . $H ), 'two identical headings keep the shared rule' );
$ok( false !== strpos( $css, '31px' ), 'and it still carries the measured type' );

echo "\n== One band's clash must not withdraw another band's rule\n";

$clear();
$claim->invoke( null, 'section-5', $H, $big, true );   // a well-behaved band
$claim->invoke( null, 'section-2', $H, $sml, true );   // another band, first heading
$claim->invoke( null, 'section-2', $H, $big, true );   // …which then clashes
$css = $css_of();
$ok( false !== strpos( $css, '#section-5 ' . $H ), "the untouched band's rule survives" );
$ok( false === strpos( $css, '#section-2 ' . $H ), 'only the clashing band loses its rule' );

echo "\n== NEGATIVE: PROSE still narrows — withdrawal is for headings only\n";

// Prose builds its node in the same pass, so it can carry a narrowed rule, and losing the shared rule
// would strip the first block instead. prose-selector-clash-test covers the behaviour; this pins the split.
$clear();
$claim->invoke( null, 'section-3', '.text-block', array( 'line-height' => '29.25px' ), false );
$claim->invoke( null, 'section-3', '.text-block', array( 'line-height' => '22.75px' ), false );
$css = $css_of();
$ok( false !== strpos( $css, '#section-3 .text-block' ), "prose KEEPS the first claimant's shared rule" );
$ok( false !== strpos( $css, '29.25px' ), 'carrying the first block\'s measurement' );
$narrow = (string) $own->getValue( null );
$ok( '' !== $narrow && false !== strpos( $narrow, '22.75px' ), 'and the second prose block gets a narrowed rule to carry itself' );

$clear();
printf( "\n%s\n", $fails ? "{$fails} FAIL(S)" : 'ALL PASS' );
exit( $fails ? 1 : 0 );
