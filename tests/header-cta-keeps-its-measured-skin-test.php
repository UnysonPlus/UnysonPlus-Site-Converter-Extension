<?php
/**
 * Regression guard: a header CTA with no matching preset keeps its MEASURED skin.
 *
 * The header CTA's button style is inferred from the source's class names — `bg-primary`, `bg-brand`,
 * `bg-secondary`, `bg-accent`. A source that names its palette differently matches none of them, the style
 * resolves to '', and the theme renders its own default button.
 *
 * Measured on a captured header, the CTA is `class="… bg-navy-700 text-white px-5 py-2 rounded-full"`:
 *
 *     source     background rgb(46,64,92)   white text   font-weight 600   padding 8px 20px   radius 9999px
 *     converted  transparent                dark text    font-weight 400   padding 10px 32px  1px grey border
 *
 * So the pill became an outline button on every page. Nothing was missing from the capture: the node carries
 * all of it in its computed stamp. Only the class-name guess failed.
 *
 * When no preset matches, the measured skin rides as scoped CSS keyed by the element's own CSS class — the
 * same pattern the header chips already use. Only properties the source actually set are emitted; no
 * invented defaults, and a transparent fill is not a fill.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/header-cta-keeps-its-measured-skin-test.php"
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

$fn = new ReflectionMethod( 'FW_Site_Converter_Stitch', 'header_cta_css' );
$fn->setAccessible( true );

$MEASURED = 'background-color:rgb(46, 64, 92);color:rgb(255, 255, 255);font-size:14px;'
	. 'font-weight:600;padding:8px 20px;border-radius:9999px';

echo "\n== The measured shape: a pill the preset guess could not name\n";

$css = (string) $fn->invoke( null, $MEASURED, 'sc-hdr-cta' );
$ok( '' !== trim( $css ), 'a rule is produced at all' );
$ok( false !== strpos( $css, 'sc-hdr-cta' ), 'scoped to the element class: ' . substr( $css, 0, 80 ) );

echo "\n== …carrying every property the source set\n";

$ok( false !== strpos( $css, 'background-color:rgb(46, 64, 92)' ), 'the navy fill' );
$ok( false !== strpos( $css, 'color:rgb(255, 255, 255)' ), 'the white ink' );
$ok( false !== strpos( $css, 'font-weight:600' ), 'the 600 weight' );
$ok( false !== strpos( $css, 'padding:8px 20px' ), 'the measured padding' );
$ok( false !== strpos( $css, 'border-radius:9999px' ), 'the pill radius' );

echo "\n== NEGATIVE: nothing measured, nothing emitted\n";

$ok( '' === trim( (string) $fn->invoke( null, '', 'sc-hdr-cta' ) ), 'an empty stamp yields no rule' );
$ok( '' === trim( (string) $fn->invoke( null, 'display:inline-flex;align-items:center', 'sc-hdr-cta' ) ),
	'a stamp with no skin properties yields no rule' );

echo "\n== NEGATIVE: a transparent fill is not a fill\n";

$clear = (string) $fn->invoke( null, 'background-color:rgba(0, 0, 0, 0);color:rgb(20,20,20)', 'sc-hdr-cta' );
$ok( false === strpos( $clear, 'background-color' ), 'a transparent background is not carried' );
$ok( false !== strpos( $clear, 'color:rgb(20,20,20)' ), '…while the ink beside it still is' );

echo "\n== NEGATIVE: the class name is used as given\n";

$other = (string) $fn->invoke( null, $MEASURED, 'sc-hdr-cta-2' );
$ok( false !== strpos( $other, 'sc-hdr-cta-2' ), 'a second CTA scopes to its own class' );

printf( "\n%s\n", $fails ? "{$fails} FAIL(S)" : 'ALL PASS' );
exit( $fails ? 1 : 0 );
