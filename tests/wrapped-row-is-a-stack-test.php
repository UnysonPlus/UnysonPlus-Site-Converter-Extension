<?php
/**
 * Regression guard: `flex-direction:row` is a declaration, not a layout.
 *
 * A WRAPPING flex row whose children are each the full width of their container renders as a vertical
 * STACK — the children wrap, one per line. Every page builder that nests wrapper divs emits exactly that
 * shape, and Elementor's `elementor-widget-wrap` is the common case: it computes `flex-direction:row` on a
 * container whose widgets are 100% wide.
 *
 * Reading the declaration instead of the measurement turned every such section into a row of narrow columns.
 * Measured on one converted page: a section that reads "heading, then a card grid" was built as
 * "heading column | card column | card column" — the heading squeezed into a 377px strip beside the cards
 * (against 1130px in the source) and each card a column of wrapped text. 587 of that page's 1105 stamped
 * children were ≥90% of their container's width; the converted page came out 8718px against the source's
 * 6992px (+25%), and band drift reached 45%.
 *
 * After the rule: the heading measures 1132x37 against the source's 1130x37, the page 6876px against 6992px,
 * and the worst bands roughly halve.
 *
 * SCOPED, deliberately: the rule only runs on a builder's structural WRAPPER markup. Applied everywhere
 * the same geometric test regressed corpus sites (heroes lost their background image, mechanism not yet
 * understood), so it is confined to the markup it was built for until that is explained. The test below
 * therefore gives its synthetic containers a wrapper class too.
 *
 * The test itself is physical: two children at ≥90% of the container
 * cannot share a line, because their widths sum past it. `track-frac` is the capture's measured
 * width / container width, so it asks the layout rather than the stylesheet.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/wrapped-row-is-a-stack-test.php"
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
$is_row = new ReflectionMethod( 'FW_Site_Converter_Stitch', 'is_layout_row' );
$is_row->setAccessible( true );
$stacks = new ReflectionMethod( 'FW_Site_Converter_Stitch', 'flex_children_stack' );
$stacks->setAccessible( true );
$kids_of = new ReflectionMethod( 'FW_Site_Converter_Stitch', 'el_children' );
$kids_of->setAccessible( true );

$first_div = function ( $html ) use ( $ld ) {
	$dom = $ld->invoke( null, '<!DOCTYPE html><html><body>' . $html . '</body></html>' );
	return $dom ? $dom->getElementsByTagName( 'div' )->item( 0 ) : null;
};

echo "\n== A real captured builder wrapper is a stack, not a row\n";

/* Cut from a real capture of an Elementor page and brand-scrubbed. A hand-written container would not
   reproduce the thing that matters — a computed `flex-direction:row` sitting on children the capture
   measured at full width — which is exactly the combination that was being misread. */
$frag = @file_get_contents( __DIR__ . '/fixtures/builder-widget-wrap.html' );
if ( false === $frag || '' === trim( (string) $frag ) ) {
	fwrite( STDERR, "FAIL: fixtures/builder-widget-wrap.html missing\n" );
	exit( 1 );
}
$el = $first_div( $frag );
$ok( $el instanceof DOMElement, 'the fixture parses' );

$cs = $el instanceof DOMElement ? (string) $el->getAttribute( 'data-sc-cs' ) : '';
$ok( (bool) preg_match( '/flex-direction:\s*row/i', $cs ),
	'...and it really does declare flex-direction:row (otherwise this guards nothing)' );

$kids = $el instanceof DOMElement ? (array) $kids_of->invoke( null, $el ) : array();
$ok( (bool) $stacks->invoke( null, $kids, $el instanceof DOMElement ? $el->getAttribute( 'class' ) : '' ),
	'its measured children stack: two or more at >=90% of the container cannot share a line' );
$ok( false === (bool) $is_row->invoke( null, $el ),
	'...so it is NOT claimed as a layout row' );

echo "\n== NEGATIVE: a genuine multi-column row is untouched\n";

$CS = 'display:flex;flex-direction:row;';
$row = '<div class="elementor-widget-wrap" data-sc-cs="' . $CS . '">'
	. '<div data-sc-cs="track-frac:0.32;"><h3>One</h3><p>Some body copy for the first cell.</p></div>'
	. '<div data-sc-cs="track-frac:0.32;"><h3>Two</h3><p>Some body copy for the second cell.</p></div>'
	. '<div data-sc-cs="track-frac:0.32;"><h3>Three</h3><p>Some body copy for the third cell.</p></div>'
	. '</div>';
$rel = $first_div( $row );
$ok( false === (bool) $stacks->invoke( null, (array) $kids_of->invoke( null, $rel ), $rel->getAttribute( 'class' ) ),
	'NEGATIVE: three cells at a third of the width each sit side by side' );
$ok( true === (bool) $is_row->invoke( null, $rel ),
	'NEGATIVE: ...and the row is still recognised as a row' );

echo "\n== NEGATIVE: a WRAPPING grid that wraps to two lines is still a row\n";

// Four cells at ~48% wrap onto two lines — that is a wrapped ROW, not a stack, and must keep its columns.
$wrap = '<div class="elementor-widget-wrap" data-sc-cs="' . $CS . 'flex-wrap:wrap;">'
	. str_repeat( '<div data-sc-cs="track-frac:0.48;"><h3>Cell</h3><p>Body copy.</p></div>', 4 )
	. '</div>';
$wel = $first_div( $wrap );
$ok( false === (bool) $stacks->invoke( null, (array) $kids_of->invoke( null, $wel ), $wel->getAttribute( 'class' ) ),
	'NEGATIVE: cells at 48% share a line two at a time, so this is a wrapped row' );

echo "\n== NEGATIVE: evidence is required, never assumed\n";

$nostamp = '<div class="elementor-widget-wrap" data-sc-cs="' . $CS . '">'
	. '<div><h3>One</h3><p>Body copy here.</p></div><div><h3>Two</h3><p>Body copy here.</p></div>'
	. '</div>';
$nel = $first_div( $nostamp );
$ok( false === (bool) $stacks->invoke( null, (array) $kids_of->invoke( null, $nel ), $nel->getAttribute( 'class' ) ),
	'NEGATIVE: an unmeasured capture (no track-frac) is not evidence of stacking' );

// A full-width child beside a narrow one CANNOT share a line either — 0.97 + 0.3 overflows the container —
// so this is a stack too. An earlier version of this test asserted the opposite; the arithmetic says
// otherwise, and the measured markup agreed: a wrapper holding widgets at 1, 0.529, 1, 1 stacks, and it was
// the strict "every child full-width" reading that turned such a wrapper into a four-column row on a page.
$one = '<div class="elementor-widget-wrap" data-sc-cs="' . $CS . '">'
	. '<div data-sc-cs="track-frac:0.97;"><h3>Only</h3><p>Body copy.</p></div>'
	. '<div data-sc-cs="track-frac:0.3;"><h3>Other</h3><p>Body copy.</p></div>'
	. '</div>';
$oel = $first_div( $one );
$ok( true === (bool) $stacks->invoke( null, (array) $kids_of->invoke( null, $oel ), $oel->getAttribute( 'class' ) ),
	'a full-width child beside a narrow one still stacks: together they overflow the line' );

echo "
== NEGATIVE: a pair that DOES fit keeps its row
";

$fits = '<div class="elementor-widget-wrap" data-sc-cs="' . $CS . '">'
	. '<div data-sc-cs="track-frac:0.6;"><h3>Wide</h3><p>Body copy.</p></div>'
	. '<div data-sc-cs="track-frac:0.38;"><h3>Narrow</h3><p>Body copy.</p></div>'
	. '</div>';
$fel = $first_div( $fits );
$ok( false === (bool) $stacks->invoke( null, (array) $kids_of->invoke( null, $fel ), $fel->getAttribute( 'class' ) ),
	'NEGATIVE: 0.6 + 0.38 fits within the container, so those two really are side by side' );

echo $fails ? "\n\xe2\x9c\x97 $fails FAILED\n" : "\n\xe2\x9c\x93 ALL PASS - the measured layout outranks the declared direction\n";
exit( $fails ? 1 : 0 );
