<?php
/**
 * Regression guard: a capped wrapper's SIDE GUTTER rides with its cap.
 *
 * A cap is a BOX width, not a content width. The commonest container on the web is
 * `max-w-[1152px] px-5` — a 1152 box holding 1112 of content — and stamp_cap carried only the 1152. The
 * converted row therefore handed its content 40px it never had.
 *
 * Measured on a real two-column band: the source's grid tracks read `532px 532px` with a 48px gap (1112
 * total), the conversion's columns 552 each (1152 total). Twenty pixels per column does not sound like much
 * until it changes the wrap: the source's heading runs over THREE lines at 532px wide and 180px tall, the
 * conversion's fitted on TWO at 552px and 120px. The band came back 60px short with 11.5% of its pixels
 * differing; after the fix the heading measures 532x180 exactly and the band passes.
 *
 * `box-sizing:border-box` is already emitted with the cap, so the padding comes OUT of the cap rather than
 * adding to it — which is what the source does.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/cap-gutter-test.php"
 *
 * Exit code 0 = all PASS, 1 = at least one FAIL.
 */

if ( ! class_exists( 'FW_Site_Converter_Stitch' ) ) {
	fwrite( STDERR, "FAIL: site-converter not loaded (run inside a WP install with the plugin active)\n" );
	exit( 1 );
}

$fails = 0;
$ok    = function ( $cond, $msg ) use ( &$fails ) {
	if ( $cond ) { echo "  \xe2\x9c\x93 $msg\n"; } else { $fails++; echo "  \xe2\x9c\x97 FAIL: $msg\n"; }
};

$pad_of = function ( $cs, $cls = '' ) {
	$dom = new DOMDocument();
	libxml_use_internal_errors( true );
	$dom->loadHTML( '<!DOCTYPE html><html><body><div class="' . $cls . '" data-sc-cs="' . $cs . '"><p data-sc-cs="font-size:16px">Copy.</p></div></body></html>', LIBXML_NOERROR | LIBXML_NOWARNING );
	libxml_clear_errors();
	$m = new ReflectionMethod( 'FW_Site_Converter_Stitch', 'wrapper_pad_x' );
	$m->setAccessible( true );
	return (float) $m->invoke( null, $dom->getElementsByTagName( 'div' )->item( 0 ) );
};

$BASE = 'display:block;max-width:1152px;';

echo "\n== A symmetric side gutter is read\n";

$ok( 20.0 === $pad_of( $BASE . 'padding:0px 20px' ),
	'`max-width:1152px; padding:0 20px` reports a 20px gutter (got ' . $pad_of( $BASE . 'padding:0px 20px' ) . ')' );
$ok( 24.0 === $pad_of( $BASE . 'padding-left:24px;padding-right:24px' ),
	'...from longhands too (got ' . $pad_of( $BASE . 'padding-left:24px;padding-right:24px' ) . ')' );

echo "\n== NEGATIVE: what is not a gutter\n";

$ok( 0.0 === $pad_of( $BASE . 'padding:0px' ),
	'NEGATIVE: no padding is no gutter' );
$ok( 0.0 === $pad_of( $BASE . 'padding:0px 48px 0px 12px' ),
	'NEGATIVE: an ASYMMETRIC inset is a layout decision, not a gutter — halving it would misplace the content, not merely widen it' );
$ok( 0.0 === $pad_of( $BASE . 'padding:0px 96px' ),
	'NEGATIVE: a 96px inset is a LAYOUT inset the band container already expresses, not a page gutter — re-adding it pushed a hero title 48px off the source edge (golden [AC])' );
$ok( 32.0 === $pad_of( $BASE . 'padding:0px 32px' ),
	'...while 32px, the top of the px-4..px-8 gutter range, still counts (got ' . $pad_of( $BASE . 'padding:0px 32px' ) . ')' );
$ok( 0.0 === $pad_of( 'display:block;max-width:1152px' ),
	'NEGATIVE: a wrapper with no padding at all reports none' );

echo "\n== The emitted cap CSS carries the gutter\n";

// apply_block_cap() is called directly: reproducing the full pipeline needs the source's exact wrapper
// nesting, and this asserts the thing that was actually wrong — what the cap emits.
$cap_css = function ( $block, $shortcode = 'flexbox' ) {
	$node = array( 'type' => 'simple', 'shortcode' => $shortcode, 'atts' => array( 'custom_css' => '' ), '_items' => array() );
	$m = new ReflectionMethod( 'FW_Site_Converter_Mapper', 'apply_block_cap' );
	$m->setAccessible( true );
	$m->invokeArgs( null, array( &$node, $block ) );
	return (string) ( $node['atts']['custom_css'] ?? '' );
};

$with = $cap_css( array( 'capW' => '1152px', 'capCenter' => true, 'capPadX' => 20.0 ) );
$ok( false !== strpos( $with, 'max-width:1152px' ),
	'the cap is emitted' );
$ok( (bool) preg_match( '/padding-left:\s*20px/', $with ) && (bool) preg_match( '/padding-right:\s*20px/', $with ),
	'...carrying the wrapper\'s 20px gutter with it (got "' . mb_substr( preg_replace( '/\s+/', ' ', $with ), 0, 130 ) . '")' );
$ok( false !== strpos( $with, 'box-sizing:border-box' ),
	'...with border-box, so the gutter comes OUT of the cap rather than adding to it' );

echo "\n== NEGATIVE: a cap with no gutter emits none\n";

$without = $cap_css( array( 'capW' => '1152px', 'capCenter' => true ) );
$ok( false !== strpos( $without, 'max-width:1152px' ) && false === strpos( $without, 'padding-left' ),
	'NEGATIVE: a wrapper with no gutter emits the cap alone, exactly as before (got "' . mb_substr( preg_replace( '/\s+/', ' ', $without ), 0, 110 ) . '")' );

echo "\n== NEGATIVE: an intrinsic control takes neither width nor gutter\n";

$btn = $cap_css( array( 'capW' => '448px', 'capCenter' => true, 'capPadX' => 20.0 ), 'button' );
$ok( false === strpos( $btn, 'padding-left' ) && false === strpos( $btn, 'width:100%' ),
	'NEGATIVE: a button gets the max-width only — a cap on a control means "do not exceed", not "fill and inset" (got "' . mb_substr( preg_replace( '/\s+/', ' ', $btn ), 0, 110 ) . '")' );

// The real-site shape this came from, kept as documentation rather than as an assertion: reproducing it end
// to end needs the source's exact wrapper nesting, and the wiring was verified on the conversion itself —
// the heading measured 532x180 against the source's 532x180 (it had been 552x120) and the band went
// WARN to PASS, pixel 11.5% to 2.5%.
//   <section class="py-20">
//     <div class="container-prose grid lg:grid-cols-2 gap-12">   max-width:1152px; padding:0 20px
//       <div>heading + copy</div><div>...</div>                   tracks: 532px 532px, gap 48px

echo $fails ? "\n\xe2\x9c\x97 $fails FAILED\n" : "\n\xe2\x9c\x93 ALL PASS - a cap is a box width, and its gutter travels with it\n";
exit( $fails ? 1 : 0 );
