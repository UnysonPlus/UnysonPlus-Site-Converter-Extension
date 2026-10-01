<?php
/**
 * Regression guard: a badge's LABEL stamp is captured, so a hero eyebrow keeps the source's own type.
 *
 * A badge is a pill wrapping a dot and a label. `pill_parts()` claims a short, uppercase text span as the
 * badge's TAG CHIP rather than its message — right for a two-part badge ("NEW · We shipped it"), but on the
 * commoner one-label shape it meant the label's computed stamp was written to a field nobody kept. `msgCs`
 * came back EMPTY, every reader of the kicker's measured type found nothing, and the theme's type won: a 10px
 * uppercase 2px-tracked brand-coloured label rendered as 14px sentence case in the body ink, on every hero of
 * that shape across a site.
 *
 * The fix is in two places and both are needed — the chip span's stamp is captured as `tagCs`, and the badge
 * node's type reader falls back to it when `msgCs` is empty. Asserted here at `pill_parts()`, which is where
 * the stamp was being dropped; the end-to-end result is verified by rendering the converted page.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/overline-label-type-test.php"
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

$parts = function ( $html ) {
	$dom = new DOMDocument();
	libxml_use_internal_errors( true );
	$dom->loadHTML( '<!DOCTYPE html><html><body>' . $html . '</body></html>', LIBXML_NOERROR | LIBXML_NOWARNING );
	libxml_clear_errors();
	$el = $dom->getElementsByTagName( 'div' )->item( 0 );
	$m  = new ReflectionMethod( 'FW_Site_Converter_Stitch', 'pill_parts' );
	$m->setAccessible( true );
	return (array) $m->invoke( null, $el );
};

/* The label's own computed stamp — what the pill's stamp does NOT say. */
$LABEL = 'color:oklch(0.57599 0.19211 290.21);font-size:10px;font-weight:700;line-height:15px;'
	. 'letter-spacing:2px;text-transform:uppercase;display:block';
$PILL  = 'display:inline-flex;align-items:center;gap:8px;color:oklch(0.95413 0.01612 293.75);'
	. 'font-size:16px;line-height:24px;border-radius:9999px;padding:4px 12px';

$one_label = '<div class="inline-flex items-center gap-2 rounded-full px-3 py-1" data-sc-cs="' . $PILL . '">'
	. '<span class="size-2 bg-brand-accent rounded-full" data-sc-cs="display:block;width:8px;height:8px;border-radius:9999px"></span>'
	. '<span class="text-[10px] font-bold uppercase tracking-[0.2em]" data-sc-cs="' . $LABEL . '">About Us</span>'
	. '</div>';

$p = $parts( $one_label );

echo "\n== The label's own stamp is captured\n";

$ok( 'About Us' === trim( (string) ( $p['tag_text'] ?? '' ) ) || 'About Us' === trim( (string) ( $p['message'] ?? '' ) ),
	'the label text is read (as the chip or the message)' );

$label_cs = (string) ( $p['msgCs'] ?? '' );
if ( '' === $label_cs ) { $label_cs = (string) ( $p['tagCs'] ?? '' ); }

$ok( '' !== $label_cs,
	'its computed stamp is kept — as msgCs, or as tagCs when the span reads as a chip' );
$ok( false !== strpos( $label_cs, 'font-size:10px' ),
	'...carrying the label\'s 10px size, not the pill\'s 16px' );
$ok( false !== strpos( $label_cs, 'letter-spacing:2px' ),
	'...its 2px tracking, which only the stamp has (`tracking-[0.2em]` is arbitrary)' );
$ok( false !== strpos( $label_cs, 'text-transform:uppercase' ),
	'...its case' );
$ok( false !== strpos( $label_cs, 'oklch(0.57599 0.19211 290.21)' ),
	'...and its own brand ink, which the pill\'s stamp does not carry' );

echo "\n== NEGATIVE: the pill's stamp is still the pill's\n";

$ok( false !== strpos( (string) ( $p['pillCs'] ?? '' ), 'font-size:16px' ),
	'NEGATIVE: the pill keeps its own 16px — the two stamps are not merged into one' );
$ok( false === strpos( $label_cs, 'font-size:16px' ),
	'NEGATIVE: ...and the label\'s stamp is not the pill\'s' );

echo "\n== NEGATIVE: a two-part badge still separates chip from message\n";

$two = '<div class="inline-flex items-center gap-2 rounded-full px-3 py-1" data-sc-cs="' . $PILL . '">'
	. '<span class="uppercase rounded-full bg-brand px-2" data-sc-cs="' . $LABEL . '">New</span>'
	. '<span data-sc-cs="font-size:14px;color:rgb(230,230,235)">We shipped the reporting view this week</span>'
	. '</div>';
$p2 = $parts( $two );
$ok( 'New' === trim( (string) ( $p2['tag_text'] ?? '' ) ),
	'NEGATIVE: the chip is still read as the chip (got "' . ( $p2['tag_text'] ?? '' ) . '")' );
$ok( false !== stripos( (string) ( $p2['message'] ?? '' ), 'shipped the reporting' ),
	'NEGATIVE: ...and the message as the message' );
$ok( false !== strpos( (string) ( $p2['msgCs'] ?? '' ), 'font-size:14px' ),
	'NEGATIVE: ...whose own stamp is still what msgCs carries' );

echo "\n== NEGATIVE: nothing is invented\n";

$bare = '<div class="inline-flex items-center rounded-full px-3 py-1" data-sc-cs="' . $PILL . '">'
	. '<span class="uppercase">About Us</span></div>';
$p3 = $parts( $bare );
$cs3 = (string) ( $p3['msgCs'] ?? '' );
if ( '' === $cs3 ) { $cs3 = (string) ( $p3['tagCs'] ?? '' ); }
$ok( '' === trim( $cs3 ),
	'NEGATIVE: an unstamped label yields no stamp rather than the pill\'s (got "' . mb_substr( $cs3, 0, 40 ) . '")' );

echo $fails ? "\n\xe2\x9c\x97 $fails FAILED\n" : "\n\xe2\x9c\x93 ALL PASS - the eyebrow's label keeps the type its source gives it\n";
exit( $fails ? 1 : 0 );
