<?php
/**
 * Regression guard: a band's vertical inset can sit deeper than one wrapper, and a zero side is a value.
 *
 * `band_pad_container_of()` finds the element that actually carries a band's top/bottom padding when the
 * `<section>` itself declares none. It only ever looked at the section's DIRECT child, which is where a
 * hand-written band puts it. A page builder nests — section > container > column > widget-wrap — and the
 * padding lands on the innermost of those.
 *
 * Measured on a captured band: `padding: 54px 0px 0px` sat three levels down, the lookup returned null, and
 * the converted section opened flush. That missing 54px of space above the eyebrow was the whole of that
 * band's height deficit (323px source vs 253px converted).
 *
 * Descending is safe under the guards this function already establishes, so the walk keeps them at every
 * level: exactly ONE in-flow child (two means the padding is not the band's), and that child must span the
 * band's full height (otherwise it is a part of the band, not its container). The walk stops at the first
 * level that declares an inset.
 *
 * The second half is the subtler one. The carrier here declares a top inset and leaves the bottom at 0.
 * Emitting only the non-zero side left the other undeclared, and the mapper's "zero is a value" rule could
 * no longer see an explicit 0 — so the band fell back to the theme's default. Carrying the 54px top
 * correctly then added 48px of theme padding at the bottom the source does not have, turning a 70px deficit
 * into a 50px surplus. Since the section itself is known to have no vertical inset, a side this container
 * leaves at 0 is a real 0, and both sides are now always emitted.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/band-inset-nested-wrapper-test.php"
 *
 * Exit code 0 = all PASS, 1 = at least one FAIL.
 */

if ( ! class_exists( 'FW_Site_Converter_Stitch' ) ) {
	fwrite( STDERR, "FAIL: site-converter not loaded\n" );
	exit( 1 );
}

$fails = 0;
$ok    = function ( $cond, $msg ) use ( &$fails ) {
	if ( $cond ) { echo "  \xe2\x9c\x93 $msg\n"; } else { $fails++; echo "  \xe2\x9c\x97 FAIL: $msg\n"; }
};

$ld = new ReflectionMethod( 'FW_Site_Converter_Stitch', 'load_dom' );
$ld->setAccessible( true );
$finder = new ReflectionMethod( 'FW_Site_Converter_Stitch', 'band_pad_container_of' );
$finder->setAccessible( true );
$reader = new ReflectionMethod( 'FW_Site_Converter_Stitch', 'band_pad_from_inner' );
$reader->setAccessible( true );

// Every level must declare its measured height, which is what the span guard reads.
$H = 323;
$sec_of = function ( $inner ) use ( $ld, $H ) {
	$html = '<!DOCTYPE html><html><body><section data-sc-cs="display:block;height:' . $H . 'px">'
		. $inner . '</section></body></html>';
	$dom = $ld->invoke( null, $html );
	return $dom ? $dom->getElementsByTagName( 'section' )->item( 0 ) : null;
};
$wrap = function ( $cls, $pad, $kids ) use ( $H ) {
	$cs = 'display:block;height:' . $H . 'px' . ( '' !== $pad ? ';padding:' . $pad : '' );
	return '<div class="' . $cls . '" data-sc-cs="' . $cs . '">' . $kids . '</div>';
};
$leaf = '<h2 data-sc-cs="font-size:31px">A band heading</h2>';

echo "\n== The measured shape: the inset is three wrappers down\n";

$sec = $sec_of( $wrap( 'container', '', $wrap( 'column', '', $wrap( 'widget-wrap', '54px 0px 0px', $leaf ) ) ) );
$found = $finder->invoke( null, $sec );
$ok( $found instanceof DOMElement, 'the carrier is found at all (was: null)' );
$ok( $found instanceof DOMElement && 'widget-wrap' === $found->getAttribute( 'class' ), 'and it is the innermost wrapper, not the first child' );

$pad = (array) $reader->invoke( null, $sec );
$ok( false !== strpos( (string) ( $pad['cs'] ?? '' ), 'padding-top:54px' ), 'the 54px top is carried: ' . ( $pad['cs'] ?? '(none)' ) );

echo "\n== A zero side is emitted as a real zero\n";

$ok( false !== strpos( (string) ( $pad['cs'] ?? '' ), 'padding-bottom:0px' ),
	'the untouched bottom is declared 0, so the band cannot fall back to the theme default' );

echo "\n== The inset is still found when it sits on the DIRECT child (unchanged)\n";

$sec = $sec_of( $wrap( 'inner', '40px 0px 50px', $leaf ) );
$found = $finder->invoke( null, $sec );
$ok( $found instanceof DOMElement && 'inner' === $found->getAttribute( 'class' ), 'a one-level band still resolves to its own wrapper' );
$pad = (array) $reader->invoke( null, $sec );
$ok( false !== strpos( (string) ( $pad['cs'] ?? '' ), 'padding-top:40px' ) && false !== strpos( (string) ( $pad['cs'] ?? '' ), 'padding-bottom:50px' ),
	'both of its sides are carried' );

echo "\n== NEGATIVE: a section that already has its own inset is left alone\n";

$dom = $ld->invoke( null, '<!DOCTYPE html><html><body><section data-sc-cs="display:block;height:' . $H . 'px;padding:60px 0px">'
	. $wrap( 'inner', '54px 0px 0px', $leaf ) . '</section></body></html>' );
$ok( null === $finder->invoke( null, $dom->getElementsByTagName( 'section' )->item( 0 ) ),
	'the section owns the rhythm, so no inner container is adopted' );

echo "\n== NEGATIVE: two content children mean the padding is not the band's\n";

$sec = $sec_of( $wrap( 'a', '54px 0px 0px', $leaf ) . $wrap( 'b', '', $leaf ) );
$ok( null === $finder->invoke( null, $sec ), 'a section with two in-flow children is refused' );

// …and the same rule applies DEEPER, which the walk must not lose.
$sec = $sec_of( $wrap( 'container', '', $wrap( 'x', '', $leaf ) . $wrap( 'y', '54px 0px 0px', $leaf ) ) );
$ok( null === $finder->invoke( null, $sec ), 'two children one level down is refused too' );

echo "\n== NEGATIVE: a child that does not span the band is a part of it, not its container\n";

$short = '<div class="part" data-sc-cs="display:block;height:80px;padding:54px 0px 0px">' . $leaf . '</div>';
$ok( null === $finder->invoke( null, $sec_of( $short ) ), 'a short child is refused' );

echo "\n== NEGATIVE: no inset anywhere means nothing to carry\n";

$sec = $sec_of( $wrap( 'container', '', $wrap( 'column', '', $wrap( 'widget-wrap', '', $leaf ) ) ) );
$ok( null === $finder->invoke( null, $sec ), 'a chain with no padding yields no carrier' );
$ok( array() === (array) $reader->invoke( null, $sec ), 'and no declarations' );

printf( "\n%s\n", $fails ? "{$fails} FAIL(S)" : 'ALL PASS' );
exit( $fails ? 1 : 0 );
