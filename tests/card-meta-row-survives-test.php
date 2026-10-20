<?php
/**
 * Regression guard: an article card's META ROW survives to the page.
 *
 * WHY THIS FILE EXISTS. The worst-covered page of a real conversion lost six strings to three article
 * cards — a category chip and a read time on each:
 *
 *     Home Care · 5 min read      Eco-Living · 6 min read      (and a third)
 *
 * The source writes them as a ROW of two spans ABOVE the title. Two separate losses put them on the floor:
 *
 * 1. **`card_eyebrow()` kept only the LAST qualifying leaf.** It assigns `$best` on every match, so the
 *    chip was overwritten by the read time and never reached the mapping at all.
 *
 * 2. **The overline never reached the page.** `card_from_cell()` captured one — measured, the mapping
 *    carried `overline: "6 min read"` on all three cards — and `n_icon_box()` has no overline handling,
 *    because the icon_box shortcode has no eyebrow slot. The value was captured, carried, and dropped at
 *    the last step.
 *
 * `card_meta_lift()` already exists for exactly this and already emits a block ABOVE the card, keeping the
 * source's order. But it only fires when the overline is ALSO repeated as a leading `<p>` in the body — a
 * post card's author/date shape. An article card's meta is a row of spans that appears nowhere in the body,
 * so the lift found nothing and returned null.
 *
 * The fixture is a reduced real capture (21.8 KB → 7.9 KB), trimmed under a predicate requiring both meta
 * strings to stay in the SOURCE and both to still be missing from the BUILD. Image srcs neutralised.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/card-meta-row-survives-test.php"
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

$fixture = __DIR__ . '/fixtures/article-card-meta-row.html';
if ( ! is_file( $fixture ) ) { fwrite( STDERR, "FAIL: fixture missing\n" ); exit( 1 ); }
$html = file_get_contents( $fixture );

echo "\n== The fixture still poses the question\n";

$ok( false !== strpos( $html, 'Eco-Living' ) && false !== strpos( $html, '6 min read' ),
	'the source carries both meta parts' );

$map = FW_Site_Converter_Stitch::html_to_mapping( $html, 'R', 'r', true );
$mb  = wp_json_encode( $map );
$ok( false !== strpos( $mb, '"card"' ), 'and the band converts to a card' );

echo "\n== Both meta parts reach the mapping\n";

// The chip was lost because card_eyebrow() overwrote it with the read time.
$ok( false !== strpos( $mb, 'Eco-Living' ), 'the category chip is captured' );
$ok( false !== strpos( $mb, '6 min read' ), 'and the read time' );

echo "\n== …and both reach the built page\n";

// The read time WAS captured before this fix and still vanished here, which is the more serious half: a
// value measured, carried through the mapping, and dropped at the last step.
$pb = wp_json_encode( FW_Site_Converter_Mapper::build_pages( $map ) );
$ok( false !== strpos( $pb, '6 min read' ), 'the read time survives into the page' );
$ok( false !== strpos( $pb, 'Eco-Living' ), 'and the category chip' );

echo "\n== …above the title, where the source put them\n";

// Order is part of the content. icon_box has no eyebrow slot, so the meta becomes its own block BEFORE the
// card — the same placement card_meta_lift() already uses for a post card's author/date.
$title_at = strpos( $pb, 'The Science Behind Botanical' );
$meta_at  = strpos( $pb, '6 min read' );
$ok( false !== $title_at && false !== $meta_at && $meta_at < $title_at,
	'the meta block precedes the card title' );

echo "\n== NEGATIVE: a card with no meta row gains nothing\n";

// Nothing is invented for a plain card — an empty meta block above every card would be a visible defect of
// its own.
$plain = '<!DOCTYPE html><html><body><main>'
	. '<section data-sc-cs="background-color:rgb(255,255,255);padding:80px 0px;display:block">'
	. '<div data-sc-cs="display:grid;grid-template-columns:384px 384px;gap:32px">'
	. '<div data-sc-cs="padding:24px;display:block"><h3 data-sc-cs="font-size:20px;font-weight:700">Trained Staff</h3>'
		. '<p data-sc-cs="font-size:14px;line-height:20px">Every member is background checked.</p></div>'
	. '<div data-sc-cs="padding:24px;display:block"><h3 data-sc-cs="font-size:20px;font-weight:700">Eco Products</h3>'
		. '<p data-sc-cs="font-size:14px;line-height:20px">Non-toxic supplies as standard.</p></div>'
	. '</div></section></main></body></html>';
$plain_pb = wp_json_encode( FW_Site_Converter_Mapper::build_pages(
	FW_Site_Converter_Stitch::html_to_mapping( $plain, 'P', 'p', true )
) );
$ok( false !== strpos( $plain_pb, 'Trained Staff' ), 'a plain card still converts' );
$ok( 1 === preg_match_all( '/Trained Staff/', $plain_pb ), '…once, with no duplicated meta block' );

printf( "\n%s\n", $fails ? "{$fails} FAIL(S)" : 'ALL PASS' );
exit( $fails ? 1 : 0 );
