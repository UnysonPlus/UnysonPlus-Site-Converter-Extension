<?php
/**
 * Regression guard: a DIVIDER-SEPARATED chip strip keeps its native shape and its measurements.
 *
 * An icon-led chip row converts to a `feature_list` (orientation: horizontal) instead of a run of
 * text_blocks with each glyph frozen into a WYSIWYG. Four defects sat on that routing, all the same
 * mistake in different clothes -- the recognizer carried the chip's TEXT and nothing else about it:
 *
 *  1. A SEPARATOR VETOED THE ROW. A strip written `chip - chip - chip` carries a lone punctuation leaf
 *     between each pair. The gate demanded that EVERY child be iconed, so one divider disqualified the
 *     whole row: a five-chip strip stayed NINE text_blocks, while the identical strip one section up
 *     converted correctly because it happened to carry no dividers.
 *  2. THE LABEL'S SIZE NEVER ARRIVED. n_feature_list resolves it from `label_cs`, falling back to
 *     `label_cls`; the routing supplied neither, so strips rendered at the theme's 16px body against a
 *     source's 14px -- widening the row by ~114px and moving every glyph off its source position.
 *  3. THE MARK'S SIZE NEVER ARRIVED EITHER, for a subtler reason: a lucide glyph carries its size as an
 *     ATTRIBUTE (width="16"), not a `w-4` class and not reliably a stamped width, so BOTH of the existing
 *     size paths came back empty and the marks fell to the font-relative default (measured 18.75px).
 *  4. THE ROW'S LINE BOX. Pinning the measured size on `.fw-fl__text` alone leaves the ITEM on the
 *     preset's size, and the item is what sets the band height: a 70px source band came out 72.8px.
 *
 * THE FIXTURE IS CUT FROM A REAL CAPTURE (fixtures/chip-strip-divided.html), not hand-authored. An
 * earlier hand-built row did not satisfy is_chip_row() at all, so the test would have asserted nothing --
 * which is why the first assertion below proves the recognizer actually ran.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/strip-chip-routing-test.php"
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

$frag = @file_get_contents( __DIR__ . '/fixtures/chip-strip-divided.html' );
if ( false === $frag || '' === trim( (string) $frag ) ) {
	fwrite( STDERR, "FAIL: fixtures/chip-strip-divided.html missing\n" );
	exit( 1 );
}

$MIDDOT = "\xc2\xb7";

/** Run the registered `chip_row` recognizer over a fragment: false = no match, array = the built block. */
$run = function ( $html ) {
	$ld = new ReflectionMethod( 'FW_Site_Converter_Stitch', 'load_dom' );
	$ld->setAccessible( true );
	$dom = $ld->invoke( null, '<!DOCTYPE html><html><body>' . $html . '</body></html>' );
	if ( ! $dom ) { return null; }
	$row = $dom->getElementsByTagName( 'div' )->item( 0 );
	if ( ! ( $row instanceof DOMElement ) ) { return null; }
	$init = new ReflectionMethod( 'FW_Site_Converter_Stitch', 'register_builtin_recognizers' );
	$init->setAccessible( true );
	$init->invoke( null );
	$prop = new ReflectionProperty( 'FW_Site_Converter_Stitch', 'recognizers' );
	$prop->setAccessible( true );
	$reg = (array) $prop->getValue();
	if ( ! isset( $reg['chip_row'] ) ) { return null; }
	if ( ! call_user_func( $reg['chip_row']['match'], $row ) ) { return false; }
	return call_user_func( $reg['chip_row']['build'], $row );
};

echo "\n== The recognizer really runs on this fixture\n";

$b = $run( $frag );
$ok( is_array( $b ), 'the captured row matches is_chip_row() and builds (got ' . ( is_array( $b ) ? 'array' : var_export( $b, true ) ) . ')' );

echo "\n== A divider-separated chip row is still a chip row\n";

$ok( is_array( $b ) && 'feature_list' === (string) ( $b['t'] ?? '' ),
	'five chips with middots between them -> ONE feature_list, not nine text_blocks (got "' . ( is_array( $b ) ? ( $b['t'] ?? '?' ) : '-' ) . '")' );
$n_items = is_array( $b ) ? count( (array) ( $b['items'] ?? array() ) ) : 0;
$ok( 5 === $n_items,
	'...with FIVE items -- the separators are skipped, not carried as rows (got ' . $n_items . ')' );
$ok( is_array( $b ) && 'horizontal' === (string) ( $b['orientation'] ?? '' ),
	'...laid out as a horizontal strip' );
$joined = is_array( $b ) ? wp_json_encode( $b['items'] ?? array() ) : '';
$ok( '' !== $joined && false === strpos( $joined, $MIDDOT ),
	'...and no middot survives as an item label' );

echo "\n== The chip's own measurements ride along\n";

$ok( is_array( $b ) && false !== strpos( (string) ( $b['label_cs'] ?? '' ), 'font-size:14px' ),
	'the label stamp carries the source 14px, so n_feature_list can size the text' );
$ok( is_array( $b ) && '' !== (string) ( $b['item_cs'] ?? '' ),
	'the item stamp rides along, carrying the icon-to-label gap and the row line box' );
$it0 = is_array( $b ) ? ( ( (array) ( $b['items'] ?? array() ) )[0] ?? array() ) : array();
$ok( 16.0 === (float) ( $it0['icon_px'] ?? 0 ),
	'the mark width ATTRIBUTE is read -- a lucide glyph has no `w-4` class (got ' . var_export( $it0['icon_px'] ?? null, true ) . ')' );
$ok( '' !== trim( (string) ( $it0['icon_svg'] ?? '' ) ),
	'...and the glyph itself is carried as the item icon' );

echo "\n== NEGATIVE: strip the dividers and nothing changes\n";

// Drop the dividers byte-agnostically: a separator span holds ONE short run of text and no markup,
// whereas every chip span holds an <svg> plus its label. (The captured fragment is not UTF-8 clean, so
// matching the middot codepoint itself does not fire.)
$undiv = preg_replace( '#<span[^>]*>[^<]{1,3}</span>#', '', $frag );
$u = $run( $undiv );
$ok( is_array( $u ) && 'feature_list' === (string) ( $u['t'] ?? '' ) && 5 === count( (array) ( $u['items'] ?? array() ) ),
	'NEGATIVE: the same five-item feature_list without separators -- the fix changes nothing for a plain strip' );

echo "\n== NEGATIVE: take the marks away and it is NOT a feature_list\n";

$nosvg = preg_replace( '#<svg.*?</svg>#s', '', $frag );
$ns = $run( $nosvg );
$ok( ! ( is_array( $ns ) && 'feature_list' === (string) ( $ns['t'] ?? '' ) ),
	'NEGATIVE: unmarked chips are not promoted -- the icon is what makes it a feature list' );

echo $fails ? "\n\xe2\x9c\x97 $fails FAILED\n" : "\n\xe2\x9c\x93 ALL PASS - a divided chip strip keeps its shape, type, marks and line box\n";
exit( $fails ? 1 : 0 );
