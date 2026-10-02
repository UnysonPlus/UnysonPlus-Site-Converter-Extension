<?php
/**
 * Regression guard: the source's content column is a SECTION width, never a clamp on the page shell.
 *
 * A source `<main>` often carries a content column — `max-width:720px` with a 24px gutter. The converter
 * reproduced it by pinning that onto the theme's `#main.site-main` with `!important`. A section lives INSIDE
 * that shell, so this clamped every section's own background with it: a `bg-white/5` wash the source spreads
 * edge to edge rendered as a 720px panel floating in the middle of the page, and an image box got a width its
 * height had not been computed for, leaving an empty band under the photo.
 *
 * It was also `!important`, so it beat the full-bleed page width the importer sets — two converter behaviours
 * fighting, the wrong one winning. Reported twice from a live conversion, and it survived a plugin update
 * because the rule is baked into the conversion, not read at render time.
 *
 * The source does the opposite of a clamp: the `<section>` spans the viewport and only the container inside
 * it is narrow. `content_width` on each band expresses exactly that, so the measured width is handed to the
 * band fallback instead. Vertical padding still belongs to #main — it is page padding and clamps nothing.
 * The HORIZONTAL padding does not: on #main it insets every section background by the gutter, when in the
 * source that gutter sits inside the full-bleed section.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/main-shell-not-a-clamp-test.php"
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

// main_style() is gated on $style_on — the flag the build sets when it is emitting scoped CSS. Without it
// the method returns immediately and every assertion below passes against a function that never ran.
$on = new ReflectionProperty( 'FW_Site_Converter_Mapper', 'style_on' );
$on->setAccessible( true );
$on->setValue( null, true );

$call = function ( $cls, $cs ) {
	// Reset the two statics this path touches, so each case starts from the fresh-request state.
	$scw = new ReflectionProperty( 'FW_Site_Converter_Mapper', 'site_container_px' );
	$scw->setAccessible( true );
	$scw->setValue( null, 0 );
	$sty = new ReflectionProperty( 'FW_Site_Converter_Mapper', 'style_css' );
	$sty->setAccessible( true );
	$keep = (array) $sty->getValue();
	unset( $keep['sc-main'] );
	$sty->setValue( null, $keep );

	$m = new ReflectionMethod( 'FW_Site_Converter_Mapper', 'main_style' );
	$m->setAccessible( true );
	$m->invoke( null, $cls, $cs );

	$css = (array) $sty->getValue();
	return array(
		'rule'      => (string) ( $css['sc-main'] ?? '' ),
		'container' => (int) $scw->getValue(),
	);
};

/* The exact shape that caused it: a <main> with a 720px column, 24px side gutter and 48px vertical padding. */
$CS = 'display:block;max-width:720px;padding:48px 24px;margin-left:auto;margin-right:auto;';

echo "\n== The column becomes a SECTION width, not a page clamp\n";

$r = $call( 'px-6 py-12', $CS );

$ok( false === strpos( $r['rule'], 'max-width' ),
	'no max-width is pinned on #main (rule: "' . ( '' === $r['rule'] ? '(none)' : substr( $r['rule'], 0, 90 ) ) . '")' );
$ok( 720 === $r['container'],
	'...the measured 720px column is handed to the band content-width fallback instead (got ' . $r['container'] . ')' );

echo "\n== ...so a section background can still reach the viewport edge\n";

$ok( false === stripos( $r['rule'], 'padding-left' ) && false === stripos( $r['rule'], 'padding-right' ),
	'no horizontal padding on #main either — on the shell it insets every section background by the gutter' );
// Only a CLAMPING property matters here. The vertical padding keeps its !important (it predates this and
// constrains nothing); what must never ship is a width/inset that outranks the page width the importer sets.
$ok( ! preg_match( '/(max-width|width|padding-left|padding-right|margin-left|margin-right)\s*:[^;}]*!important/i', $r['rule'] ),
	'no clamping or insetting property is emitted !important, so nothing can outrank the page width the importer sets' );

echo "\n== Vertical page padding is still carried\n";

$r2 = $call( 'py-12', 'display:block;padding:48px 0px;' );
$ok( false !== strpos( $r2['rule'], 'padding-top' ) || false !== strpos( $r2['rule'], 'padding-bottom' ),
	'a source <main> with vertical padding still sets it on #main (it is page padding and clamps nothing)' );

echo "\n== NEGATIVE: an explicit site container width wins\n";

// A conversion that already resolved the site's container must not have it silently replaced.
$scw = new ReflectionProperty( 'FW_Site_Converter_Mapper', 'site_container_px' );
$scw->setAccessible( true );
$sty = new ReflectionProperty( 'FW_Site_Converter_Mapper', 'style_css' );
$sty->setAccessible( true );
$keep = (array) $sty->getValue(); unset( $keep['sc-main'] ); $sty->setValue( null, $keep );
FW_Site_Converter_Mapper::set_site_container_width( 1140 );
$m = new ReflectionMethod( 'FW_Site_Converter_Mapper', 'main_style' );
$m->setAccessible( true );
$m->invoke( null, 'px-6', $CS );
$ok( 1140 === (int) $scw->getValue(),
	'NEGATIVE: an already-resolved site container width is not overwritten by the shell measurement (got '
	. (int) $scw->getValue() . ')' );

echo "\n== NEGATIVE: an implausible width is still ignored\n";

$r3 = $call( '', 'display:block;max-width:120px;' );
$ok( 0 === $r3['container'], 'NEGATIVE: a 120px max-width is not a content column and is ignored' );
$r4 = $call( '', 'display:block;max-width:3000px;' );
$ok( 0 === $r4['container'], 'NEGATIVE: ...nor is 3000px' );

FW_Site_Converter_Mapper::set_site_container_width( 0 );

echo $fails ? "\n\xe2\x9c\x97 $fails FAILED\n" : "\n\xe2\x9c\x93 ALL PASS - the shell does not clamp the sections\n";
exit( $fails ? 1 : 0 );
