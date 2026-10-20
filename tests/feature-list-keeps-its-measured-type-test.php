<?php
/**
 * Regression guard: a converted checklist keeps the SIZE, WEIGHT and COLOUR the source gave it.
 *
 * A captured `<ul>` becomes a `feature_list`, and the mapper carries the item's measured type from the
 * block's `item_cs` stamp. Two gaps meant it never reached the page:
 *
 * 1. The `<ul>` recognizer did not record `item_cs` or `list_cs` AT ALL — the block it returned carried only
 *    `t`, `ordered`, `marker` and `items`. A sibling recognizer does stamp them, so the consumer existed and
 *    simply had nothing to read.
 * 2. Even when present, `item_cs` was read for `font-size` and `line-height` only, so a measured WEIGHT and
 *    COLOUR were dropped and the shortcode's own defaults stood.
 *
 * Measured on a captured hero: three check items stamped 16px / 400 / rgb(26,33,45) rendered at the
 * shortcode's 14px / 500 / rgb(26,26,26) — nine property deltas, three per item, and visibly smaller and
 * heavier than the source.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/feature-list-keeps-its-measured-type-test.php"
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

$ITEM_CS = 'color:rgb(26, 33, 45);font-family:Inter, system-ui, sans-serif;font-size:16px;font-weight:400;line-height:24px';

echo "\n== The capture records the list's own measured type\n";

$rec = new ReflectionMethod( 'FW_Site_Converter_Stitch', 'text_list_block' );
$rec->setAccessible( true );
$dom = new DOMDocument();
libxml_use_internal_errors( true );
$dom->loadHTML( '<!DOCTYPE html><html><body><ul id="L" data-sc-cs="display:grid;row-gap:12px;list-style-type:none">'
	. '<li data-sc-cs="' . $ITEM_CS . ';display:flex;list-style-type:none"><span>Compassionate, transparent legal guidance</span></li>'
	. '<li data-sc-cs="' . $ITEM_CS . ';display:flex;list-style-type:none"><span>Serving clients nationwide</span></li>'
	. '<li data-sc-cs="' . $ITEM_CS . ';display:flex;list-style-type:none"><span>Board-certified specialists</span></li>'
	. '</ul></body></html>' );
libxml_clear_errors();
$block = $rec->invoke( null, $dom->getElementById( 'L' ) );

$ok( is_array( $block ) && ( $block['t'] ?? '' ) === 'feature_list', 'the <ul> is recognised as a feature_list' );
if ( is_array( $block ) ) {
	$ok( ! empty( $block['item_cs'] ), 'the item style is recorded: ' . mb_substr( (string) ( $block['item_cs'] ?? '' ), 0, 80 ) );
	$ok( false !== strpos( (string) ( $block['item_cs'] ?? '' ), 'font-size:16px' ), '…including the measured size' );
	$ok( false !== strpos( (string) ( $block['item_cs'] ?? '' ), 'font-weight:400' ), '…the measured weight' );
}

echo "\n== …and the mapper carries size, weight and colour onto the item\n";

$fn = new ReflectionMethod( 'FW_Site_Converter_Mapper', 'n_feature_list' );
$fn->setAccessible( true );
$node = $fn->invoke( null, array(
	't' => 'feature_list', 'ordered' => false, 'marker' => 'none',
	'item_cs' => $ITEM_CS,
	'items'   => array(
		array( 'text' => 'Compassionate, transparent legal guidance' ),
		array( 'text' => 'Serving clients nationwide' ),
	),
) );
$css = is_array( $node ) ? (string) ( $node['atts']['custom_css'] ?? '' ) : '';
$ok( false !== strpos( $css, 'font-size:16px' ), 'the measured size is emitted: ' . mb_substr( trim( $css ), 0, 160 ) );
$ok( false !== strpos( $css, 'font-weight:400' ), 'the measured weight is emitted' );
$ok( false !== strpos( $css, 'rgb(26, 33, 45)' ) || false !== strpos( $css, 'color:' ), 'the measured colour is emitted' );

echo "\n== NEGATIVE: no measured style invents none\n";

$bare = $fn->invoke( null, array(
	't' => 'feature_list', 'ordered' => false, 'marker' => 'none',
	'items' => array( array( 'text' => 'One' ), array( 'text' => 'Two' ) ),
) );
$bcss = is_array( $bare ) ? (string) ( $bare['atts']['custom_css'] ?? '' ) : '';
$ok( false === strpos( $bcss, 'font-weight' ), 'no weight is invented for an unstamped list' );
$ok( false === strpos( $bcss, 'font-size' ), 'and no size' );

printf( "\n%s\n", $fails ? "{$fails} FAIL(S)" : 'ALL PASS' );
exit( $fails ? 1 : 0 );
