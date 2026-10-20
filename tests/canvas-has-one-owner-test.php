<?php
/**
 * Regression guard: the page canvas has ONE owner — the measured Site Background.
 *
 * `page_shell_css()` carries the source <body>'s own rules onto the converted body, scoped to
 * `body:not(.wp-admin)`. That exists for good reason: a layered gradient ground or a fixed pattern layer is
 * design that no single measured colour can express.
 *
 * But a carried rule is a REPLAY, not a measurement. The source's `body{background:…}` may be overridden in
 * the source by something more specific that this conversion never carried, so replaying it paints a colour
 * the source does not actually show. And because the carried rule is scoped to `body:not(.wp-admin)`
 * (specificity 0,2,1) it out-ranks the converter's own `body{background-color:var(--site-bg-color,…)}` on
 * plain `body` — whatever order they load in.
 *
 * Measured on a captured page:
 *
 *     source renders        rgb(242, 243, 245)
 *     carried body rule     background: var(--e-global-color-kadence7)   (= #FFFFFF)
 *     converted rendered    rgb(255, 255, 255)
 *
 * The captured value was correct everywhere it was stored — theme-design.json colors.bg = #f2f3f5, and
 * --site-bg-color resolved to #f2f3f5 on the live page — and the carried rule overrode all of it. Every band
 * that does not paint its own background showed the wrong colour, which on that page is 13 of 20 sections.
 *
 * So a FLAT background colour is dropped from the carried shell rule and the measured option owns the canvas.
 * Anything layered — a gradient, an image, a colour beneath an image — still rides, because that is the
 * design this carrying exists to preserve and a flat measurement cannot express it.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/canvas-has-one-owner-test.php"
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

$strip = new ReflectionMethod( 'FW_Site_Converter_Stitch', 'shell_css_without_flat_bg' );
$strip->setAccessible( true );
$run = function ( $css ) use ( $strip ) { return trim( (string) $strip->invoke( null, $css ) ); };

echo "\n== The measured shape: a carried flat colour behind a private token\n";

$out = $run( 'body{background:var(--e-global-color-kadence7);color:#222}' );
$ok( false === strpos( $out, 'background' ), 'the flat var() colour is dropped: ' . $out );
$ok( false !== strpos( $out, 'color:#222' ), 'and everything else in the rule survives' );

echo "\n== Any flat colour is dropped, wherever it sits in the rule\n";

$ok( false === strpos( $run( 'body{background-color:#ffffff;font-family:Inter}' ), 'background' ),
	'a flat colour as the FIRST declaration is dropped' );
$ok( false === strpos( $run( 'body{color:#222;background-color:#fff}' ), 'background' ),
	'a flat colour mid-rule is dropped' );
$ok( false !== strpos( $run( 'body{background-color:#ffffff;font-family:Inter}' ), 'font-family:Inter' ),
	'…without taking its neighbours with it' );

echo "\n== NEGATIVE: a LAYERED ground still rides — that is what carrying is for\n";

$grad = 'body{background:linear-gradient(180deg,#fff,#eee)}';
$ok( $run( $grad ) === $grad, 'a gradient ground is untouched' );

$img = 'body{background:url(/bg.png) center/cover no-repeat}';
$ok( $run( $img ) === $img, 'an image ground is untouched' );

$both = 'body{background:#f2f3f5 url(/noise.png) repeat}';
$ok( $run( $both ) === $both, 'a colour BENEATH an image is untouched — it is part of the layer' );

echo "\n== NEGATIVE: nothing to strip changes nothing\n";

$plain = 'body{font-family:Inter;color:#222}';
$ok( $run( $plain ) === $plain, 'a rule with no background is returned as-is' );
$ok( '' === $run( '' ), 'empty css stays empty' );

printf( "\n%s\n", $fails ? "{$fails} FAIL(S)" : 'ALL PASS' );
exit( $fails ? 1 : 0 );
