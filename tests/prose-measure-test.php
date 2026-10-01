<?php
/**
 * Regression guard: a centred text block keeps its source's READING WIDTH.
 *
 * A source caps its prose on the WRAPPER, not on each paragraph
 * (`<div class="max-w-xl mx-auto space-y-4"><p>…</p><p>…</p></div>`), so the paragraph itself reports no
 * max-width of its own. element_max_width() read only the element, found nothing, and the converted copy ran
 * the full column width — wrapping to fewer lines and leaving the section measurably shorter than its source.
 * Measured on a real page: the source's copy 576px wide over two lines, the conversion's 723px over one, and
 * a section 558px tall against 428px.
 *
 * Only a PROSE measure is inherited — at most 800px, a reading width rather than a layout container. The
 * band's own cap is already applied by the section, and inheriting that here would say nothing while
 * overriding narrower intent further in.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/prose-measure-test.php"
 *
 * Exit code 0 = all PASS, 1 = at least one FAIL.
 */

if ( ! class_exists( 'FW_Site_Converter_Stitch' ) ) {
	fwrite( STDERR, "FAIL: site-converter not loaded (run inside a WP install with the plugin active)\n" );
	exit( 1 );
}

$fails = 0;
$ok    = function ( $cond, $msg ) use ( &$fails ) {
	if ( $cond ) { echo "  \xe2\x9c\x93 $msg\n"; } else { $fails++; echo "  \xe2\x9c\x97 FAIL: $msg\n"; }
};

$mw = function ( $html ) {
	$dom = new DOMDocument();
	libxml_use_internal_errors( true );
	$dom->loadHTML( '<!DOCTYPE html><html><body>' . $html . '</body></html>', LIBXML_NOERROR | LIBXML_NOWARNING );
	libxml_clear_errors();
	$p = $dom->getElementsByTagName( 'p' )->item( 0 );
	$m = new ReflectionMethod( 'FW_Site_Converter_Stitch', 'element_max_width' );
	$m->setAccessible( true );
	return (string) $m->invoke( null, $p );
};

echo "\n== A paragraph inherits its wrapper's reading width\n";

// Named Tailwind caps come back in rem, as tw_max_w_named() has always returned them: 36rem == 576px.
$xl = $mw( '<div class="max-w-xl mx-auto"><p data-sc-cs="font-size:16px">Copy.</p></div>' );
$ok( '36rem' === $xl, 'a `max-w-xl` wrapper gives its paragraph 36rem = 576px (got "' . $xl . '")' );

$two = '<div data-sc-cs="max-width:576px;margin-left:auto;margin-right:auto"><p data-sc-cs="font-size:16px">Copy.</p></div>';
$ok( '576px' === $mw( $two ),
	'...and a measured `max-width` on the wrapper does the same (got "' . $mw( $two ) . '")' );

$nested = '<div class="max-w-xl mx-auto"><div class="space-y-4"><p data-sc-cs="font-size:16px">Copy.</p></div></div>';
$ok( '36rem' === $mw( $nested ),
	'...through a layout wrapper in between (got "' . $mw( $nested ) . '")' );

echo "\n== NEGATIVE: the paragraph's OWN measure still wins\n";

$own = '<div class="max-w-xl mx-auto"><p class="max-w-sm" data-sc-cs="font-size:16px">Copy.</p></div>';
$ok( '24rem' === $mw( $own ),
	'NEGATIVE: a cap on the paragraph itself (24rem = 384px) is not overridden by the wrapper (got "' . $mw( $own ) . '")' );

echo "\n== NEGATIVE: a layout container is not a reading width\n";

$band = '<div class="max-w-7xl mx-auto"><p data-sc-cs="font-size:16px">Copy.</p></div>';
$ok( '' === $mw( $band ),
	'NEGATIVE: a 1280px band cap is not adopted as prose measure (got "' . $mw( $band ) . '")' );

$wide = '<div data-sc-cs="max-width:1100px"><p data-sc-cs="font-size:16px">Copy.</p></div>';
$ok( '' === $mw( $wide ),
	'NEGATIVE: ...nor any measured cap above the 800px reading ceiling (got "' . $mw( $wide ) . '")' );

echo "\n== NEGATIVE: the walk stops at the section\n";

$far = '<section class="max-w-xl"><div><div><div><p data-sc-cs="font-size:16px">Copy.</p></div></div></div></section>';
$ok( '' === $mw( $far ),
	'NEGATIVE: a cap beyond the walk, or on the section itself, is not inherited (got "' . $mw( $far ) . '")' );

$uncapped = '<div class="space-y-4"><p data-sc-cs="font-size:16px">Copy.</p></div>';
$ok( '' === $mw( $uncapped ),
	'NEGATIVE: an uncapped wrapper contributes nothing (got "' . $mw( $uncapped ) . '")' );

echo $fails ? "\n\xe2\x9c\x97 $fails FAILED\n" : "\n\xe2\x9c\x93 ALL PASS - prose keeps the reading width its source gives it\n";
exit( $fails ? 1 : 0 );
