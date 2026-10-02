<?php
/**
 * Regression guard: a card reveals as ONE unit, not as contents arriving into a static frame.
 *
 * The entrance pass collected only leaf WIDGETS, so a container carrying a Box Preset -- which is the card:
 * its fill, border, radius and shadow all live there -- was never a target. The frame sat still while its own
 * heading, icon and copy faded in inside it, which reads as the card being painted on and its contents
 * arriving in someone else's box. Measured on a conversion: 9 boxed containers, 0 animated, against 18
 * animated widgets.
 *
 * Taking the container also means NOT descending into it. A card that rises while its contents separately
 * rise inside it double-reveals and reads as jitter. One box, one reveal -- the same ownership rule that
 * decides which node PAINTS the skin decides which node ANIMATES it.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/entrance-boxed-card-test.php"
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

/** Collect entrance targets for a tree and report what got an effect. */
$targets_of = function ( array $tree ) {
	$m = new ReflectionMethod( 'FW_Site_Converter_Mapper', 'collect_anim_targets' );
	$m->setAccessible( true );
	$targets = array();
	$m->invokeArgs( null, array( &$tree, 1, &$targets ) );
	$out = array();
	foreach ( $targets as $t ) {
		$n = $t['ref'];
		$out[] = ! empty( $n['shortcode'] ) ? (string) $n['shortcode'] : ( 'container:' . (string) ( $n['type'] ?? '?' ) );
	}
	return $out;
};

$widget = function ( $sc ) {
	return array( 'type' => 'simple', 'shortcode' => $sc, 'atts' => array( 'animation' => array( 'effect' => 'none' ) ) );
};

echo "\n== A boxed container is the target; its contents are not\n";

$card = array(
	'type'  => 'flexbox',
	'atts'  => array( 'border_preset' => 'boxp-card', 'animation' => array( 'effect' => 'none' ) ),
	'_items' => array( $widget( 'icon_box' ), $widget( 'text_block' ) ),
);
$t = $targets_of( array( $card ) );

$ok( array( 'container:flexbox' ) === $t,
	'the boxed card itself is the only target (got ' . wp_json_encode( $t ) . ')' );

echo "\n== NEGATIVE: an UNboxed container still animates its contents\n";

$plain = array(
	'type'  => 'flexbox',
	'atts'  => array( 'animation' => array( 'effect' => 'none' ) ),
	'_items' => array( $widget( 'icon_box' ), $widget( 'text_block' ) ),
);
$t2 = $targets_of( array( $plain ) );
$ok( array( 'icon_box', 'text_block' ) === $t2,
	'NEGATIVE: with no Box Preset there is no card, so the widgets are the targets (got ' . wp_json_encode( $t2 ) . ')' );

echo "\n== NEGATIVE: a container that already has a real effect is left alone\n";

$already = array(
	'type'  => 'flexbox',
	'atts'  => array( 'border_preset' => 'boxp-card', 'animation' => array( 'effect' => 'animate__zoomIn' ) ),
	'_items' => array( $widget( 'icon_box' ) ),
);
$t3 = $targets_of( array( $already ) );
$ok( array() === $t3,
	'NEGATIVE: a detected or hand-set effect is not overwritten, and its contents stay out of it (got ' . wp_json_encode( $t3 ) . ')' );

echo "\n== NEGATIVE: a boxed card nested in a plain row still wins over its own children\n";

$row = array(
	'type'  => 'column',
	'atts'  => array( 'animation' => array( 'effect' => 'none' ) ),
	'_items' => array( $card, $widget( 'button' ) ),
);
$t4 = $targets_of( array( $row ) );
$ok( array( 'container:flexbox', 'button' ) === $t4,
	'NEGATIVE: the card reveals as one unit, the sibling button on its own (got ' . wp_json_encode( $t4 ) . ')' );

echo $fails ? "\n\xe2\x9c\x97 $fails FAILED\n" : "\n\xe2\x9c\x93 ALL PASS - one box, one reveal\n";
exit( $fails ? 1 : 0 );
