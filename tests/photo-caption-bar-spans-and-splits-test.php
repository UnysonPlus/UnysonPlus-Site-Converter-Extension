<?php
/**
 * Regression guard: a photo's caption BAR spans its photo and splits its two fields apart.
 *
 * WHY THIS FILE EXISTS. A source pins a caption over its photograph:
 *
 *     <div class="absolute bottom-6 left-6 right-6 p-4 bg-black/60 flex items-center justify-between">
 *       <span>PRIVATE LODGE RETREATS</span><span>EXCLUSIVE PARTNER</span>
 *     </div>
 *
 * — a strip spanning the photo's width with a title at one end and a label at the other. Converted, it
 * became a small box in the photo's bottom-left corner with the two fields STACKED and centred: measured,
 * 256px wide against the source's 583px, with the label on a second line.
 *
 * TWO CAUSES, both in `floating_card_pos_css()`, which was written for a corner CHIP:
 *
 * 1. `max-width:16rem` was emitted unconditionally. That is right for a badge and wrong for a bar: 16rem
 *    is 256px, which is exactly the width that was measured. A box pinned to BOTH left and right already
 *    has its width fully determined by those two offsets, so a cap can only contradict them. The cap is
 *    now emitted only when the box is NOT pinned on both sides.
 *
 * 2. The source's own flex layout was carried for a flex COLUMN (its gap) and not at all for a ROW, so a
 *    `justify-between` bar fell back to the element's default stack. The two fields are siblings inside
 *    `.icon-box__inner`, so the source's main-axis layout is restated there.
 *
 *    `flex-direction:row` has to be explicit, and this is the part that looked like the fix had failed:
 *    `.icon-box__inner` is a COLUMN by default, so setting `display:flex` alone left the fields stacked —
 *    and centred on one axis, which reads exactly like a rule that never applied. It had applied; it was
 *    simply making a flex column.
 *
 * AFTER: the bar spans its photo, the title sits at the left padding edge and the label ends at the right
 * one, both on the same baseline — the source's own arrangement.
 *
 * The fixture is the real band, reduced, with the source's own naming replaced.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/photo-caption-bar-spans-and-splits-test.php"
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

/** The scoped CSS of the icon_box whose title contains $needle, or ''. */
$box_css = function ( $html, $needle ) {
	FW_Site_Converter_Mapper::set_style_config( array() );
	$pages = FW_Site_Converter_Mapper::build_pages( FW_Site_Converter_Stitch::html_to_mapping( $html, 'K', 'k', true ) );
	$css   = '';
	$walk  = function ( $x ) use ( &$walk, &$css, $needle ) {
		if ( ! is_array( $x ) ) { return; }
		foreach ( $x as $v ) {
			if ( ! is_array( $v ) ) { continue; }
			if ( 'icon_box' === ( $v['shortcode'] ?? '' ) && false !== strpos( (string) wp_json_encode( $v ), $needle ) ) {
				$css = (string) ( $v['atts']['custom_css'] ?? '' );
			}
			$walk( $v['_items'] ?? $v );
		}
	};
	$walk( $pages );
	return $css;
};

echo "\n== The real caption bar spans its photo\n";

$fixture = __DIR__ . '/fixtures/photo-caption-bar-spans-and-splits.html';
if ( ! is_file( $fixture ) ) { fwrite( STDERR, "FAIL: fixture missing\n" ); exit( 1 ); }
$css = $box_css( file_get_contents( $fixture ), 'PRIVATE LODGE RETREATS' );

$ok( '' !== $css, 'the caption converts to a positioned box' );
$ok( false !== strpos( $css, 'position:absolute' ), 'pinned over the photo' );
$ok( (bool) preg_match( '/(?:^|[{;])left:\s*1\.5rem/', $css ), 'with the source\'s left offset' );
$ok( (bool) preg_match( '/(?:^|[;])right:\s*1\.5rem/', $css ), 'and its right offset' );
$ok( false === strpos( $css, 'max-width:16rem' ),
	sprintf( 'and NO 16rem cap, which would shrink a spanning bar to 256px (css: %s)', mb_substr( $css, 0, 110 ) ) );

echo "\n== …and lays its two fields out as the source does\n";

$ok( false !== strpos( $css, 'display:flex' ), 'the inner wrapper is a flex container' );
$ok( false !== strpos( $css, 'flex-direction:row' ),
	'EXPLICITLY a row — the element\'s own inner is a column, so display:flex alone still stacks' );
$ok( false !== strpos( $css, 'justify-content:space-between' ),
	'with the source\'s own space-between, pushing the fields to opposite ends' );

echo "\n== NEGATIVE: a CORNER CHIP keeps its cap\n";

// Asserted on floating_card_pos_css() directly, which is the function whose rule changed. Driving these
// through a whole conversion would instead test whether a synthetic page reaches the floating-card path
// at all — a different question, and one a contrived fixture answers by accident rather than by rule.
$fcp = new ReflectionMethod( 'FW_Site_Converter_Stitch', 'floating_card_pos_css' );
$fcp->setAccessible( true );
$el = function ( $html ) {
	$d = new DOMDocument();
	libxml_use_internal_errors( true );
	$d->loadHTML( '<?xml encoding="utf-8" ?><div id="r">' . $html . '</div>' );
	libxml_clear_errors();
	return $d->getElementById( 'r' )->firstChild;
};

// Pinned on ONE side: the width is undetermined, so the cap is the only thing keeping a long label from
// running away across the photo.
$chip = (string) $fcp->invoke( null, $el(
	'<div class="absolute bottom-4 left-4 p-3" data-sc-cs="position:absolute;bottom:16px;left:16px;'
	. 'padding:12px;background-color:rgba(0, 0, 0, 0.6);display:block">'
	. '<h4 data-sc-cs="font-size:14px">AWARD WINNER</h4></div>' ) );
$ok( false !== strpos( $chip, 'max-width:16rem' ),
	sprintf( 'a chip pinned on ONE side keeps its 16rem cap (got %s)', mb_substr( $chip, 0, 120 ) ) );
$ok( false === strpos( $chip, 'flex-direction:row' ),
	'and gains no row layout, since its source is not a flex row' );

// Pinned on BOTH: a bar. The cap would contradict the two offsets that already fix its width.
$bar = (string) $fcp->invoke( null, $el(
	'<div class="absolute bottom-6 left-6 right-6 p-4" data-sc-cs="position:absolute;bottom:24px;left:24px;'
	. 'right:24px;padding:16px;background-color:rgba(0, 0, 0, 0.6);display:flex;'
	. 'justify-content:space-between;align-items:center">'
	. '<span data-sc-cs="font-size:12px">LEFT</span><span data-sc-cs="font-size:12px">RIGHT</span></div>' ) );
$ok( false === strpos( $bar, 'max-width:16rem' ), 'a bar pinned on BOTH sides drops the cap' );
$ok( false !== strpos( $bar, 'flex-direction:row' ), 'and takes the source\'s row layout' );
$ok( false !== strpos( $bar, 'justify-content:space-between' ), 'with its space-between' );

echo "\n== NEGATIVE: a flex COLUMN box is not turned into a row\n";

// The column branch predates this and must keep winning: a stacked chip (an overline over a date) is a
// column, and forcing a row would set its two lines side by side.
$col = (string) $fcp->invoke( null, $el(
	'<div class="absolute top-4 left-4 p-3" data-sc-cs="position:absolute;top:16px;left:16px;padding:12px;'
	. 'display:flex;flex-direction:column;gap:4px">'
	. '<span data-sc-cs="font-size:11px">MAR</span><span data-sc-cs="font-size:20px">14</span></div>' ) );
$ok( false === strpos( $col, 'flex-direction:row' ), 'a flex-column chip stays a column' );

printf( "\n%s\n", $fails ? "{$fails} FAIL(S)" : 'ALL PASS' );
exit( $fails ? 1 : 0 );
