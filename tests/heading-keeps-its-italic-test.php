<?php
/**
 * Regression guard: a heading the source sets in ITALIC stays italic.
 *
 * `heading_weight_css()` re-asserts a heading part's source typography as a scoped `.heading-<part>` rule —
 * the never-drop channel that already carries weight, family and casing, because the shortcode and the
 * generated theme both style `hN` and would otherwise win. `font-style` was not among them.
 *
 * Measured on a captured contact page, three headings in a row:
 *
 *     <h1 class="font-display … italic">What can we do for you?</h1>   font-style:italic
 *     <h2 class="font-display … italic">Visit us</h2>                  font-style:italic
 *     <h3 class="font-display … italic">On the map</h3>                font-style:italic
 *
 * All three rendered upright. The stamp carried `font-style:italic` the whole time; nothing read it. (A
 * heading with an italic SPAN inside it was unaffected — that italic rides in the title HTML — so this only
 * ever showed up on a heading italic as a whole.)
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/heading-keeps-its-italic-test.php"
 *
 * Exit code 0 = all PASS, 1 = at least one FAIL.
 */

if ( ! class_exists( 'FW_Site_Converter_Mapper' ) ) {
	fwrite( STDERR, "FAIL: site-converter mapper not loaded\n" );
	exit( 1 );
}

$fails = 0;
$ok    = function ( $cond, $msg ) use ( &$fails ) {
	if ( $cond ) { echo "  \xe2\x9c\x93 $msg\n"; } else { $fails++; echo "  \xe2\x9c\x97 FAIL: $msg\n"; }
};

$fn = new ReflectionMethod( 'FW_Site_Converter_Mapper', 'heading_weight_css' );
$fn->setAccessible( true );

$BASE = 'color:rgb(153, 145, 243);font-family:"Playfair Display", Georgia, serif;font-size:48px;font-weight:700;line-height:48px';

echo "\n== The measured shape: a wholly italic display heading\n";

$css = (string) $fn->invoke( null, array(
	'title'       => 'What can we do for you?',
	'title_cs'    => $BASE . ';font-style:italic',
	'title_class' => 'font-display text-5xl font-bold italic',
) );
$ok( '' !== trim( $css ), 'the heading produces scoped CSS at all' );
$ok( false !== strpos( $css, 'font-style:italic' ), 'the measured italic is emitted: ' . mb_substr( trim( $css ), 0, 150 ) );
$ok( false !== strpos( $css, '.heading-title' ), '…scoped to the title part' );
$ok( false !== strpos( $css, 'font-weight:700' ), '…and its siblings still ride along (weight)' );

echo "\n== The subtitle and overline carry their own italic too\n";

$css2 = (string) $fn->invoke( null, array(
	'title'       => 'Visit us',
	'title_cs'    => $BASE,
	'subtitle'    => 'Come and say hello',
	'subtitle_cs' => 'font-size:18px;font-weight:400;font-style:italic',
) );
$i = strpos( $css2, '.heading-subtitle' );
$ok( false !== $i, 'the subtitle gets its own rule' );
$ok( false !== strpos( substr( $css2, $i ), 'font-style:italic' ), '…carrying its italic' );
$ok( false === strpos( substr( $css2, 0, false !== $i ? $i : strlen( $css2 ) ), 'font-style:italic' ),
	'…while the upright title does NOT gain one' );

echo "\n== The subtitle also keeps its measured SIZE and line box\n";

// The size normally rides a Text Style preset class, but `.heading-subtitle` is more specific than
// `.font-small`, so the theme's 16px subtitle beat the preset. Measured on a captured contact page: the
// address lines are a heading's subtitle, stamped 14px / 20px and rendered 16px / 26.08px.
$css3 = (string) $fn->invoke( null, array(
	'title'       => 'Visit us',
	'title_cs'    => $BASE,
	'subtitle'    => '1250 Market Street, Suite 400',
	'subtitle_cs' => 'color:rgb(101, 117, 139);font-size:14px;line-height:20px',
) );
$k = strpos( $css3, '.heading-subtitle' );
$ok( false !== $k, 'the subtitle gets a rule' );
if ( false !== $k ) {
	$sub_rules = substr( $css3, $k );
	$ok( false !== strpos( $sub_rules, 'font-size:14px' ), 'carrying its measured size: ' . mb_substr( $sub_rules, 0, 120 ) );
	$ok( false !== strpos( $sub_rules, 'line-height:20px' ), 'and its measured line box' );
}
$ok( false === strpos( substr( $css3, 0, false !== $k ? $k : strlen( $css3 ) ), 'font-size' ),
	'while the TITLE size is left to the heading level and the theme scale' );

echo "\n== NEGATIVE: an upright heading is untouched\n";

$up = (string) $fn->invoke( null, array(
	'title'       => 'We fight for your future',
	'title_cs'    => $BASE . ';font-style:normal',
	'title_class' => 'font-display text-5xl font-bold',
) );
$ok( false === strpos( $up, 'font-style' ), '`font-style:normal` is not emitted as a choice' );

echo "\n== NEGATIVE: a heading with no stamp invents nothing\n";

$none = (string) $fn->invoke( null, array( 'title' => 'Plain', 'title_cs' => '', 'title_class' => '' ) );
$ok( false === strpos( $none, 'font-style' ), 'no italic is invented' );

echo "\n== NEGATIVE: a part that does not render gets no rule\n";

$empty = (string) $fn->invoke( null, array(
	'title'       => 'Only a title',
	'title_cs'    => $BASE,
	'subtitle'    => '',
	'subtitle_cs' => 'font-style:italic',
) );
$ok( false === strpos( $empty, '.heading-subtitle' ), 'an empty subtitle is not styled' );

printf( "\n%s\n", $fails ? "{$fails} FAIL(S)" : 'ALL PASS' );
exit( $fails ? 1 : 0 );
