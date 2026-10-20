<?php
/**
 * Regression guard: a footer title sized in VIEWPORT units keeps them.
 *
 * `footer_heading_css()` carries the sampled footer title's computed font-size onto the scoped `.footer-links-title`
 * rule. A footer wordmark sized `text-[18vw]` computes to 259.2px at the 1440px capture width; carried as px it
 * stayed 259.2px on a 390px phone and pushed the page 580px sideways (seen on several captured footers). The
 * source's own class says the size is a share of the viewport, so the rule keeps `18vw` — 70px on a phone,
 * 259px on the capture's desktop, exactly as the source behaves.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/footer-heading-keeps-viewport-size-test.php"
 *
 * Exit code 0 = all PASS, 1 = at least one FAIL.
 */

if ( ! class_exists( 'FW_Site_Converter_Stitch' ) ) {
	fwrite( STDERR, "FAIL: site-converter stitch not loaded\n" );
	exit( 1 );
}

$fails = 0;
$ok    = function ( $cond, $msg ) use ( &$fails ) {
	if ( $cond ) { echo "  \xe2\x9c\x93 $msg\n"; } else { $fails++; echo "  \xe2\x9c\x97 FAIL: $msg\n"; }
};

$fn = new ReflectionMethod( 'FW_Site_Converter_Stitch', 'footer_heading_css' );
$fn->setAccessible( true );

$page = function ( $cls, $cs ) {
	return '<!DOCTYPE html><html><body><main><section data-sc-cs="background-color:rgb(255,255,255)"><h1>Home</h1></section></main>'
		. '<footer><div data-sc-cs="background-color:rgb(10,10,10);padding-top:80px">'
		. '<h2 class="' . $cls . '" data-sc-cs="' . $cs . '">NOVA</h2>'
		. '</div></footer></body></html>';
};

echo "\n== A footer wordmark sized in vw keeps its viewport unit\n";
$css = (string) $fn->invoke( null, $page( 'font-syne text-[18vw] leading-none uppercase', 'color:rgb(255,255,255);font-size:259.2px;font-weight:700;text-transform:uppercase' ) );
$ok( false !== strpos( $css, 'font-size:18vw' ), 'the size is 18vw, not the capture-width px: ' . mb_substr( trim( $css ), 0, 140 ) );
$ok( false === strpos( $css, '259.2px' ), 'the frozen 259.2px is gone' );

echo "\n== NEGATIVE: a px-sized title keeps its px size\n";
$px = (string) $fn->invoke( null, $page( 'text-sm uppercase', 'color:rgb(255,255,255);font-size:14px;font-weight:600' ) );
$ok( false !== strpos( $px, 'font-size:14px' ), 'a fixed size is carried as measured' );

echo "\n== A display-size title with no viewport class scales below the capture width\n";
$big = (string) $fn->invoke( null, $page( 'mega uppercase', 'color:rgb(255,255,255);font-size:216px;font-weight:700' ) );
$ok( false !== strpos( $big, 'font-size:min(216px,15vw)' ), 'a 216px display title is min(216px,15vw): ' . mb_substr( trim( $big ), 0, 140 ) );

echo "\n== The footer LEAD title (a giant wordmark lockup) keeps its viewport size too\n";
$lead = new ReflectionMethod( 'FW_Site_Converter_Stitch', 'footer_lead_css' );
$lead->setAccessible( true );
$lp = '<!DOCTYPE html><html><body><main><section><h1>Home</h1></section></main><footer><div data-sc-cs="padding-top:80px">'
	. '<div><h2 class="text-[15vw] leading-none" data-sc-cs="color:rgb(10,10,10);font-size:216px;font-weight:700;line-height:216px">Studio Denim</h2>'
	. '<p data-sc-cs="font-size:14px">Built in the open.</p><a href="#" data-sc-cs="padding:12px 20px;background-color:rgb(0,0,0);color:rgb(255,255,255)">Get in touch</a></div>'
	. '<div><h4>Links</h4><a href="/a">A</a><a href="/b">B</a></div></div></footer></body></html>';
$lcss = (string) $lead->invoke( null, $lp );
$ok( '' !== $lcss, 'a lead rule is produced for the fixture' );
$ok( false === strpos( $lcss, 'font-size:216px' ), 'the lead title is not frozen at 216px: ' . mb_substr( $lcss, 0, 160 ) );
$ok( false !== strpos( $lcss, 'font-size:15vw' ), '…it is 15vw' );

printf( "\n%s\n", $fails ? "{$fails} FAIL(S)" : 'ALL PASS' );
exit( $fails ? 1 : 0 );
