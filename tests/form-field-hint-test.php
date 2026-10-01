<?php
/**
 * Regression guard: a field's help line lands ON the field, exactly once.
 *
 * A source writes a field hint as a short leaf right after the control and points the control at it with
 * `aria-describedby`:
 *
 *     <textarea id="goals" aria-describedby="goals-hint"></textarea>
 *     <p id="goals-hint">A sentence or two is plenty.</p>
 *
 * The contact form already HAD the slot: every field type offers an "Instructions for Users" option and all
 * eleven field views render it under the control. Nothing filled it, so the hint fell out of the form and was
 * emitted as a loose text_block AFTER </form> -- left-aligned, outside the card, and with the accessible
 * description the source author deliberately wired up simply gone.
 *
 * TWO halves, and the second is the one that is easy to miss. Carrying the hint onto the field did not stop
 * the generic text sweep reaching the same node, so the page then shipped it TWICE: correctly inside the
 * field, and again as a loose block. A positional counter taught the same lesson earlier in the converter --
 * removing a node from one claimant only moves it to the next -- so the de-duplication runs as a post-pass on
 * the assembled tree, where every path has already had its turn.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/form-field-hint-test.php"
 *
 * Exit code 0 = all PASS, 1 = at least one FAIL.
 */

if ( ! class_exists( 'FW_Site_Converter_Mapper' ) ) {
	fwrite( STDERR, "FAIL: site-converter not loaded\n" );
	exit( 1 );
}

$fails = 0;
$ok    = function ( $cond, $msg ) use ( &$fails ) {
	if ( $cond ) { echo "  \xe2\x9c\x93 $msg\n"; } else { $fails++; echo "  \xe2\x9c\x97 FAIL: $msg\n"; }
};

/** The `info` values a built contact_form carries, by field label. */
$hints_of = function ( array $tree ) {
	$out = array();
	$walk = function ( $n ) use ( &$walk, &$out ) {
		if ( is_array( $n ) && isset( $n[0] ) ) { foreach ( $n as $x ) { $walk( $x ); } return; }
		if ( ! is_array( $n ) ) { return; }
		if ( 'contact_form' === ( $n['shortcode'] ?? '' ) ) {
			$items = json_decode( (string) ( $n['atts']['form']['json'] ?? '' ), true );
			if ( is_array( $items ) ) {
				foreach ( $items as $it ) {
					$i = trim( (string) ( $it['options']['info'] ?? '' ) );
					if ( '' !== $i ) { $out[ (string) ( $it['options']['label'] ?? '' ) ] = $i; }
				}
			}
		}
		foreach ( $n as $v ) { if ( is_array( $v ) ) { $walk( $v ); } }
	};
	$walk( $tree );
	return $out;
};

/** Every text_block's plain text in a built tree. */
$texts_of = function ( array $tree ) {
	$out = array();
	$walk = function ( $n ) use ( &$walk, &$out ) {
		if ( is_array( $n ) && isset( $n[0] ) ) { foreach ( $n as $x ) { $walk( $x ); } return; }
		if ( ! is_array( $n ) ) { return; }
		if ( 'text_block' === ( $n['shortcode'] ?? '' ) ) {
			$out[] = trim( wp_strip_all_tags( (string) ( $n['atts']['text'] ?? '' ) ) );
		}
		foreach ( $n as $v ) { if ( is_array( $v ) ) { $walk( $v ); } }
	};
	$walk( $tree );
	return $out;
};

echo "\n== The post-pass removes a loose copy of a field hint\n";

$dedupe = new ReflectionMethod( 'FW_Site_Converter_Mapper', 'dedupe_form_hints' );
$dedupe->setAccessible( true );

$form_node = function ( $info ) {
	return array(
		'type' => 'simple', 'shortcode' => 'contact_form',
		'atts' => array( 'form' => array( 'json' => wp_json_encode( array(
			array( 'type' => 'textarea', 'options' => array( 'label' => 'What would you like to improve?', 'info' => $info ) ),
		) ) ) ),
	);
};
$tb = function ( $t ) { return array( 'type' => 'simple', 'shortcode' => 'text_block', 'atts' => array( 'text' => '<p>' . $t . '</p>' ) ); };

$tree = array( array( 'type' => 'section', '_items' => array(
	$form_node( 'A sentence or two is plenty.' ),
	$tb( 'A sentence or two is plenty.' ),
	$tb( 'Suggestions are reviewed before anyone replies.' ),
) ) );
$dedupe->invokeArgs( null, array( &$tree ) );
$left = $texts_of( $tree );

$ok( ! in_array( 'A sentence or two is plenty.', $left, true ),
	'the loose text_block repeating the hint is gone' );
$ok( in_array( 'Suggestions are reviewed before anyone replies.', $left, true ),
	'...and the unrelated paragraph beside it is untouched' );
$ok( 'A sentence or two is plenty.' === ( $hints_of( $tree )['What would you like to improve?'] ?? '' ),
	'...while the field keeps the hint' );

echo "\n== NEGATIVE: only an EXACT whole-text match is removed\n";

$tree2 = array( array( 'type' => 'section', '_items' => array(
	$form_node( 'A sentence or two is plenty.' ),
	$tb( 'A sentence or two is plenty. We read every one of them carefully.' ),
) ) );
$dedupe->invokeArgs( null, array( &$tree2 ) );
$ok( 1 === count( $texts_of( $tree2 ) ),
	'NEGATIVE: a paragraph that merely CONTAINS the sentence is kept -- it is not the node the form consumed' );

echo "\n== NEGATIVE: a form with no hint removes nothing\n";

$tree3 = array( array( 'type' => 'section', '_items' => array(
	$form_node( '' ),
	$tb( 'A sentence or two is plenty.' ),
) ) );
$dedupe->invokeArgs( null, array( &$tree3 ) );
$ok( in_array( 'A sentence or two is plenty.', $texts_of( $tree3 ), true ),
	'NEGATIVE: with nothing carried on the field, the block is left where it was' );

echo "\n== NEGATIVE: the remaining items keep their order and indexing\n";

$tree4 = array( array( 'type' => 'section', '_items' => array(
	$tb( 'First.' ), $form_node( 'A sentence or two is plenty.' ), $tb( 'A sentence or two is plenty.' ), $tb( 'Last.' ),
) ) );
$dedupe->invokeArgs( null, array( &$tree4 ) );
$after = $texts_of( $tree4 );
$ok( array( 'First.', 'Last.' ) === $after,
	'NEGATIVE: the list is re-indexed in order, so nothing downstream sees a hole (got ' . wp_json_encode( $after ) . ')' );

echo $fails ? "\n\xe2\x9c\x97 $fails FAILED\n" : "\n\xe2\x9c\x93 ALL PASS - the hint rides the field, exactly once\n";
exit( $fails ? 1 : 0 );
