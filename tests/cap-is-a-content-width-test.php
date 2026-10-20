<?php
/**
 * Regression guard: a capped wrapper's cap is emitted as a CONTENT width, not as its box width, when its
 * own side inset is too large to ride along as a gutter.
 *
 * WHY THIS FILE EXISTS. Every element in a converted section sat 48px left of the source — content at
 * x=80 against x=128 — on a section whose data was correct at every level. The section's
 * `container_width` had resolved to `content-1184`, the inner flexbox's `content_width` to the same, and
 * the theme's own container was 1184 with a 48px gutter. Nothing in the mapping was wrong.
 *
 * The loss happened in the rendered CSS, between two rules on the same element:
 *
 *     .fx-…  max-width: min(1184px, …)        ← the container, correct
 *     .u…    max-width: 1280px !important     ← the per-block cap, wins
 *
 * WHERE 1280 CAME FROM. The source wrapper is `max-w-7xl mx-auto px-6 lg:px-12`: a BOX 1280 wide holding
 * 1184 of content, inset 48px each side. `wrapper_pad_x()` deliberately refuses an inset that large —
 * above the 16-32px gutter range an inset is a layout decision the BAND's container already expresses,
 * and re-adding it per block would double it. That reasoning is right and golden [AC] pins the container
 * it produces (1184 + a 48px gutter).
 *
 * What was wrong is what happened to the CAP afterwards: it kept the wrapper's BOX width and was emitted
 * `!important`, so it out-ranked the container it was supposed to agree with. The inset was not merely
 * un-doubled — it was lost.
 *
 * THE FIX subtracts the inset from the cap instead. Nothing is re-added, so the inset still cannot
 * double; and the cap now equals the content width the container already agreed on, instead of
 * overriding it with a wider one. Afterwards the band's container renders 1184 at x=128, the source's own
 * geometry, and golden [AC] is untouched.
 *
 * ONLY THE REJECTED CASE. A small symmetric inset is carried as real padding by `wrapper_pad_x()`, and
 * subtracting it here as well would take it off twice — the first two negatives below.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/cap-is-a-content-width-test.php"
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

$ref  = new ReflectionClass( 'FW_Site_Converter_Stitch' );
$cacw = $ref->getMethod( 'cap_as_content_width' ); $cacw->setAccessible( true );
$wpx  = $ref->getMethod( 'wrapper_pad_x' );        $wpx->setAccessible( true );

$el = function ( $cs, $cls = '' ) {
	$d = new DOMDocument();
	libxml_use_internal_errors( true );
	$d->loadHTML( '<?xml encoding="utf-8" ?><div id="r"><div class="' . $cls . '" data-sc-cs="' . $cs . '">x</div></div>' );
	libxml_clear_errors();
	return $d->getElementById( 'r' )->firstChild;
};

echo "\n== A large symmetric inset comes OUT of the cap\n";

// The real shape: `max-w-7xl mx-auto px-12` — a 1280 box holding 1184 of content.
$big = $el( 'max-width:1280px;padding:0px 48px;margin:0px 80px;display:grid', 'max-w-7xl mx-auto px-6 lg:px-12' );
$ok( 0.0 === (float) $wpx->invoke( null, $big ),
	'wrapper_pad_x still refuses the 48px inset, as it is meant to' );
$ok( '1184px' === (string) $cacw->invoke( null, '1280px', $big ),
	sprintf( 'and the cap becomes the 1184 CONTENT width (got %s)', wp_json_encode( $cacw->invoke( null, '1280px', $big ) ) ) );

echo "\n== NEGATIVE: a GUTTER-SIZED inset is left alone\n";

// A small inset is carried as real padding by wrapper_pad_x(). Subtracting it here too would take it off
// twice — the content would end up narrower than the source's by one whole gutter.
$small = $el( 'max-width:1152px;padding:0px 20px', 'max-w-6xl mx-auto px-5' );
$ok( 20.0 === (float) $wpx->invoke( null, $small ), 'wrapper_pad_x carries a 20px inset as padding' );
$ok( '1152px' === (string) $cacw->invoke( null, '1152px', $small ),
	sprintf( 'so the cap is NOT reduced as well (got %s)', wp_json_encode( $cacw->invoke( null, '1152px', $small ) ) ) );

echo "\n== NEGATIVE: an ASYMMETRIC inset is not a gutter at all\n";

// A one-sided inset is a layout offset, not a container gutter; halving it off both sides of the cap
// would be arithmetic the source never did.
$asym = $el( 'max-width:1200px;padding:0px 48px 0px 96px' );
$ok( '1200px' === (string) $cacw->invoke( null, '1200px', $asym ),
	sprintf( 'an asymmetric inset leaves the cap unchanged (got %s)', wp_json_encode( $cacw->invoke( null, '1200px', $asym ) ) ) );

echo "\n== NEGATIVE: an inset that would eat the cap is refused\n";

// A narrow box with a huge inset is not a container with a gutter — it is something else, and halving it
// would leave a cap too small to hold anything.
$eat = $el( 'max-width:400px;padding:0px 120px' );
$ok( '400px' === (string) $cacw->invoke( null, '400px', $eat ),
	sprintf( 'an inset consuming half the cap is refused (got %s)', wp_json_encode( $cacw->invoke( null, '400px', $eat ) ) ) );

echo "\n== NEGATIVE: no inset, no change\n";

$none = $el( 'max-width:1024px;padding:0px 0px' );
$ok( '1024px' === (string) $cacw->invoke( null, '1024px', $none ), 'a wrapper with no inset is untouched' );
$ok( '80%' === (string) $cacw->invoke( null, '80%', $big ), 'and a non-px cap is passed straight through' );

printf( "\n%s\n", $fails ? "{$fails} FAIL(S)" : 'ALL PASS' );
exit( $fails ? 1 : 0 );
