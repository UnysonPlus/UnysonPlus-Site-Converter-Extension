<?php
/**
 * Regression guard: a container and its only child never paint the SAME Box Preset.
 *
 * The box-owner rule already governed the column path: one shortcode in the cell -> the icon_box owns the box
 * (`box_style`); two or more -> the container owns it (`border_preset`), since only the container wraps them
 * all. That branch enforces it with a `$box_via_class` flag, which guards exactly one route to the node.
 *
 * A card reaching the same shape through the PANEL builder is not guarded: the panel registers its skin onto
 * its flexbox, then builds its blocks inside, and a single card block registers the same skin again on the
 * icon_box. Both normalise to one slug, so the page ships `boxp-x` on the flexbox AND `boxp-x` on the icon_box
 * within it -- two borders, two fills, two radii, nested.
 *
 * Measured: 24 of 134 boxed flexboxes in the corpus (18%) wrap exactly one icon_box, over 7 of 22 sites.
 *
 * Only an EXACT duplicate collapses. The negatives below are the whole reason the rule is narrow: a child with
 * no preset is the legitimate inherited case, and two DIFFERENT presets are a card inside a panel, which the
 * source really does draw as two boxes.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/box-owner-flexbox-test.php"
 *
 * Exit code 0 = all PASS, 1 = at least one FAIL.
 */

if ( ! class_exists( 'FW_Site_Converter_Mapper' ) ) {
	fwrite( STDERR, "FAIL: site-converter not loaded (run inside a WP install with the plugin active)\n" );
	exit( 1 );
}

$fails = 0;
$ok    = function ( $cond, $msg ) use ( &$fails ) {
	if ( $cond ) { echo "  \xe2\x9c\x93 $msg\n"; } else { $fails++; echo "  \xe2\x9c\x97 FAIL: $msg\n"; }
};

$collapse = function ( array $tree ) {
	$m = new ReflectionMethod( 'FW_Site_Converter_Mapper', 'collapse_double_box' );
	$m->setAccessible( true );
	$m->invokeArgs( null, array( &$tree ) );
	return $tree;
};

/** A flexbox wearing $outer wrapping ONE icon_box wearing $inner. */
$pair = function ( $outer, $inner ) {
	return array( array(
		'type'  => 'flexbox',
		'atts'  => array( 'border_preset' => $outer ),
		'_items' => array( array(
			'type' => 'simple', 'shortcode' => 'icon_box',
			'atts' => array( 'box_style' => $inner, 'custom_css' => 'selector{padding:24px !important;}' ),
		) ),
	) );
};

echo "\n== The same preset on both is painted once\n";

$r = $collapse( $pair( 'boxp-outline', 'boxp-outline' ) );
$ok( '' === (string) $r[0]['atts']['border_preset'],
	'the CONTAINER drops it (got "' . $r[0]['atts']['border_preset'] . '")' );
$ok( 'boxp-outline' === (string) $r[0]['_items'][0]['atts']['box_style'],
	'...and the single shortcode KEEPS it — one shortcode, the shortcode owns the box' );

echo "\n== NEGATIVE: two DIFFERENT presets are two real boxes\n";

$r = $collapse( $pair( 'boxp-panel', 'boxp-outline' ) );
$ok( 'boxp-panel' === (string) $r[0]['atts']['border_preset'] && 'boxp-outline' === (string) $r[0]['_items'][0]['atts']['box_style'],
	'NEGATIVE: a card inside a panel keeps BOTH — the source draws two boxes (got "' . $r[0]['atts']['border_preset'] . '" / "' . $r[0]['_items'][0]['atts']['box_style'] . '")' );

echo "\n== NEGATIVE: a child with no preset leaves the container owning it\n";

$r = $collapse( $pair( 'boxp-outline', '' ) );
$ok( 'boxp-outline' === (string) $r[0]['atts']['border_preset'],
	'NEGATIVE: nothing to collapse into, so the container still paints (got "' . $r[0]['atts']['border_preset'] . '")' );

echo "\n== NEGATIVE: 2+ children keep the container as the box owner\n";

$two = $pair( 'boxp-outline', 'boxp-outline' );
$two[0]['_items'][] = array( 'type' => 'simple', 'shortcode' => 'button', 'atts' => array() );
$r = $collapse( $two );
$ok( 'boxp-outline' === (string) $r[0]['atts']['border_preset'],
	'NEGATIVE: an icon_box + a button needs a wrapper around BOTH — the container keeps it (got "' . $r[0]['atts']['border_preset'] . '")' );

echo "\n== It reaches NESTED containers too\n";

$nested = array( array(
	'type' => 'flexbox', 'atts' => array( 'border_preset' => '' ),
	'_items' => $pair( 'boxp-card', 'boxp-card' ),
) );
$r = $collapse( $nested );
$ok( '' === (string) $r[0]['_items'][0]['atts']['border_preset'],
	'a doubled box two levels down is collapsed as well (got "' . $r[0]['_items'][0]['atts']['border_preset'] . '")' );

echo $fails ? "\n\xe2\x9c\x97 $fails FAILED\n" : "\n\xe2\x9c\x93 ALL PASS - one box, one owner\n";
exit( $fails ? 1 : 0 );
