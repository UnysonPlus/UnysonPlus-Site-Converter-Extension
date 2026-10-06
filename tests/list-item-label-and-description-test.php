<?php
/**
 * Regression guard: a list item with a bold lead-in LABEL and a description under it is two things, not
 * one sentence.
 *
 * The common source shape is `<li><b>Label</b><br><span>the description…</span></li>` — a bold lead-in on
 * its own line, the explanation beneath it. Only the FLATTENED text reached the mapper, so the two ran
 * together into a single grey paragraph: "Works beyond the preview Focuses on what happens after the
 * customer personalizes a product." The line break, the weight and the shape of the list were all lost,
 * and the band measured 115px shorter than its source.
 *
 * Nothing new was needed to fix it: the `feature_list` shortcode already models exactly this as
 * `text` + `subtext`. The split was simply never made, so this is a mapping that was missing rather than a
 * capability that was absent — which is why the fix is in the stitch, where the DOM is still available,
 * and not in the shortcode.
 *
 * The rule is deliberately narrow, because "starts with bold" is not the same as "has a label":
 *
 *   - the bold element must be the item's FIRST element, and
 *   - it must be followed by a `<br>` before any other content.
 *
 * A bold word mid-sentence is emphasis and must stay in the prose; a bold opening with no break is a
 * sentence that happens to start bold. Both stay whole.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/list-item-label-and-description-test.php"
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
$tb = new ReflectionMethod( 'FW_Site_Converter_Stitch', 'text_list_block' );
$tb->setAccessible( true );

// `display:list-item` keeps the marker detection on its normal path; it is irrelevant to the split itself.
$LI = 'data-sc-cs="display:list-item;font-size:18px"';
$rows_of = function ( $inner ) use ( $ld, $tb ) {
	$dom = $ld->invoke( null, '<!DOCTYPE html><html><body><ul>' . $inner . '</ul></body></html>' );
	if ( ! $dom ) { return array(); }
	$ul = $dom->getElementsByTagName( 'ul' )->item( 0 );
	$b  = $tb->invoke( null, $ul );
	return ( is_array( $b ) && isset( $b['items'] ) ) ? $b['items'] : array();
};

echo "\n== The measured shape: <b>Label</b><br><span>description</span>\n";

$rows = $rows_of(
	'<li ' . $LI . '><b>Works beyond the preview</b><br><span>Focuses on what happens after the customer personalizes a product.</span></li>'
	. '<li ' . $LI . '><b>Supports multiple business models</b><br><span>Printers, distributors and ecommerce teams share one workflow.</span></li>'
);
$ok( 2 === count( $rows ), 'both items parse' );
if ( 2 === count( $rows ) ) {
	$ok( 'Works beyond the preview' === ( $rows[0]['text'] ?? '' ), "item 1 text is the LABEL alone (got '" . ( $rows[0]['text'] ?? '' ) . "')" );
	$ok( 'Focuses on what happens after the customer personalizes a product.' === ( $rows[0]['sub'] ?? '' ), 'item 1 sub is the description' );
	$ok( 'Supports multiple business models' === ( $rows[1]['text'] ?? '' ), 'item 2 text is its label' );
	$ok( false === strpos( (string) ( $rows[0]['text'] ?? '' ), 'Focuses on' ), 'the description is NOT glued onto the label' );
}

echo "\n== <strong> is the same shape as <b>\n";

$rows = $rows_of(
	'<li ' . $LI . '><strong>Service-backed</strong><br>Designed for practical onboarding and support.</li>'
	. '<li ' . $LI . '><strong>Second item</strong><br>So the list has two rows.</li>'
);
$ok( 'Service-backed' === ( $rows[0]['text'] ?? '' ), 'a <strong> label splits too' );
$ok( 'Designed for practical onboarding and support.' === ( $rows[0]['sub'] ?? '' ), 'and a bare text description is carried' );

echo "\n== NEGATIVE: bold with NO <br> is a sentence that happens to start bold\n";

$rows = $rows_of(
	'<li ' . $LI . '><b>Fast</b> delivery on every order we take.</li>'
	. '<li ' . $LI . '><b>Simple</b> pricing with no hidden fees.</li>'
);
$ok( empty( $rows[0]['sub'] ), 'no sub is produced without a line break' );
$ok( false !== strpos( (string) ( $rows[0]['text'] ?? '' ), 'delivery on every order' ), 'the whole sentence stays in text' );

echo "\n== NEGATIVE: bold MID-sentence is emphasis, not a label\n";

$rows = $rows_of(
	'<li ' . $LI . '>We ship <b>everywhere</b><br>and we do it quickly.</li>'
	. '<li ' . $LI . '>Another plain item for the list.</li>'
);
$ok( empty( $rows[0]['sub'] ), 'a bold that is not the FIRST element does not create a label' );
$ok( false !== strpos( (string) ( $rows[0]['text'] ?? '' ), 'We ship' ), 'the leading prose is kept' );

echo "\n== NEGATIVE: a label with no description is left alone\n";

$rows = $rows_of(
	'<li ' . $LI . '><b>Just a bold line</b><br></li>'
	. '<li ' . $LI . '><b>Another bold line</b><br></li>'
);
$ok( empty( $rows[0]['sub'] ), 'an empty remainder produces no sub' );
$ok( '' !== (string) ( $rows[0]['text'] ?? '' ), 'and the label survives as the item text' );

echo "\n== NEGATIVE: an ordinary list is untouched\n";

$rows = $rows_of( '<li ' . $LI . '>First plain item.</li><li ' . $LI . '>Second plain item.</li>' );
$ok( 2 === count( $rows ) && empty( $rows[0]['sub'] ) && empty( $rows[1]['sub'] ), 'plain items carry no sub' );
$ok( 'First plain item.' === ( $rows[0]['text'] ?? '' ), 'and their text is unchanged' );

printf( "\n%s\n", $fails ? "{$fails} FAIL(S)" : 'ALL PASS' );
exit( $fails ? 1 : 0 );
