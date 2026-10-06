<?php
/**
 * Regression guard: a stat cell's caption and its qualifier are TWO lines, not one sentence.
 *
 * `counter_cell_parse()` splits a stat cell's text around the number: whatever precedes the digits is
 * `$before`, whatever follows is `$after`. Both were concatenated into a single `label`, which is right for
 * a cell that carries only one of them and wrong for a cell that carries both.
 *
 * Measured on a captured stats band — four cards, each `[icon] 4499 / Orders / last 24 hours`:
 *
 *     source     "Orders"        DIV  font-weight 700  16px  #ffffff   (above the number)
 *                "4499"          SPAN font-weight 700  50px  #1b8fd1
 *                "last 24 hours" P    font-weight 400  16px  #ffffff   (below the number)
 *
 *     converted  "Orders last 24 hours"   one 700-weight run, one line
 *
 * All four cards read that way. The two runs differ in WEIGHT, so merging them does not merely lose a line
 * break — it promotes the qualifier to the caption's weight, which is why the converted band reads as one
 * heavy sentence where the source has a bold caption over a light sub-line.
 *
 * The contract:
 *
 *   - `label` keeps its merged value, untouched. Every existing reader of it is unaffected, and a cell that
 *     carries only one side is unchanged in every respect.
 *   - `labelCap` / `labelSub` ride alongside it, carrying the two runs separately. Only a reader that knows
 *     about them can tell the lines apart.
 *   - `labelSubWeight` carries the qualifier's MEASURED weight. It is not assumed: a source whose sub-line
 *     is the same weight as its caption must not have a lighter one invented for it, so when the capture
 *     stamps no weight, none is emitted and the mapper renders the break alone.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/counter-label-is-two-lines-test.php"
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

$ld    = new ReflectionMethod( 'FW_Site_Converter_Stitch', 'load_dom' );
$ld->setAccessible( true );
$parse = new ReflectionMethod( 'FW_Site_Converter_Stitch', 'counter_cell_parse' );
$parse->setAccessible( true );
$lnode = new ReflectionMethod( 'FW_Site_Converter_Mapper', 'counter_label_node' );
$lnode->setAccessible( true );

/** Build a stat cell and parse it: $cap sits above the number, $sub below, each with its own stamp. */
$make = function ( $cap, $sub, $sub_cs = 'font-weight:400;font-size:16px;color:rgb(255,255,255)' ) use ( $ld, $parse ) {
	$html = '<!DOCTYPE html><html><body><div class="cell" data-sc-cs="display:block;text-align:center">'
		. ( '' !== $cap ? '<div data-sc-cs="font-weight:700;font-size:16px;color:rgb(255,255,255)">' . $cap . '</div>' . "
" : '' )
		. '<span data-sc-cs="font-weight:700;font-size:50px;color:rgb(27,143,209)">4499</span>' . "
" . ''
		. ( '' !== $sub ? '<p data-sc-cs="' . $sub_cs . '">' . $sub . '</p>' : '' )
		. '</div></body></html>';
	$dom = $ld->invoke( null, $html );
	if ( ! $dom ) { return null; }
	foreach ( $dom->getElementsByTagName( 'div' ) as $d ) {
		if ( false !== strpos( (string) $d->getAttribute( 'class' ), 'cell' ) ) { return $parse->invoke( null, $d ); }
	}
	return null;
};

echo "\n== The measured shape: a caption above and a qualifier below\n";

$c = (array) $make( 'Orders', 'last 24 hours' );
$ok( ! empty( $c ), 'the cell parses as a stat at all' );
$ok( 'Orders' === ( $c['labelCap'] ?? null ), 'the caption is carried on its own: ' . var_export( $c['labelCap'] ?? null, true ) );
$ok( 'last 24 hours' === ( $c['labelSub'] ?? null ), 'the qualifier is carried on its own: ' . var_export( $c['labelSub'] ?? null, true ) );

echo "\n== The merged label is UNCHANGED, so no existing reader moves\n";

$ok( 'Orders last 24 hours' === ( $c['label'] ?? '' ), 'label still reads "Orders last 24 hours"' );
$ok( '4499' === ( $c['number'] ?? '' ), 'and the number is untouched' );

echo "\n== The qualifier's weight is MEASURED, never assumed\n";

$ok( '400' === (string) ( $c['labelSubWeight'] ?? '' ), 'the stamped 400 is carried: ' . var_export( $c['labelSubWeight'] ?? null, true ) );
$same = (array) $make( 'Orders', 'last 24 hours', 'font-weight:700;font-size:16px' );
$ok( '700' === (string) ( $same['labelSubWeight'] ?? '' ), 'a sub-line that is genuinely bold stays 700 — no lighter weight is invented' );
$bare = (array) $make( 'Orders', 'last 24 hours', 'font-size:16px' );
$ok( '' === (string) ( $bare['labelSubWeight'] ?? '' ), 'an unstamped sub-line yields no weight at all' );

echo "\n== NEGATIVE: a cell with only ONE run is unchanged in every respect\n";

$cap_only = (array) $make( 'Happy pets', '' );
$ok( 'Happy pets' === ( $cap_only['label'] ?? '' ), 'a caption-only cell keeps its label' );
$ok( '' === (string) ( $cap_only['labelSub'] ?? '' ), 'and carries no qualifier' );
$ok( ! empty( $cap_only['labelFirst'] ), 'and still reports labelFirst, so the caption keeps its place above the number' );

$sub_only = (array) $make( '', 'last 24 hours' );
$ok( 'last 24 hours' === ( $sub_only['label'] ?? '' ), 'a trailing-only cell keeps its label' );
$ok( '' === (string) ( $sub_only['labelCap'] ?? '' ), 'and carries no caption' );
$ok( empty( $sub_only['labelFirst'] ), 'and does not claim the label sits first' );

echo "\n== The mapper renders two lines only when there ARE two\n";

$two = (array) $lnode->invoke( null, array( 'label' => 'Orders last 24 hours', 'labelCap' => 'Orders',
	'labelSub' => 'last 24 hours', 'labelSubWeight' => '400' ) );
$j = json_encode( $two );
$ok( false !== strpos( $j, '<br>' ), 'a two-run label breaks the line' );
$ok( false !== strpos( $j, 'Orders' ) && false !== strpos( $j, 'last 24 hours' ), 'and both runs survive' );
$ok( false !== strpos( $j, 'font-weight:400' ), 'and the qualifier carries its measured lighter weight' );

$one = (array) $lnode->invoke( null, array( 'label' => 'Happy pets' ) );
$ok( false === strpos( json_encode( $one ), '<br>' ), 'a one-run label is left exactly as it was' );

$noweight = (array) $lnode->invoke( null, array( 'label' => 'Orders last 24 hours', 'labelCap' => 'Orders',
	'labelSub' => 'last 24 hours' ) );
$jn = json_encode( $noweight );
$ok( false !== strpos( $jn, '<br>' ), 'an unweighted two-run label still breaks the line' );
$ok( false === strpos( $jn, 'font-weight' ), '…but no weight is invented for it' );

echo "\n== NEGATIVE: the split never fabricates a second line\n";

$ok( null === $make( '', '' ), 'a bare number with neither run is not a stat at all' );

printf( "\n%s\n", $fails ? "{$fails} FAIL(S)" : 'ALL PASS' );
exit( $fails ? 1 : 0 );
