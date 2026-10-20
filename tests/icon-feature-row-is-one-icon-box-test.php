<?php
/**
 * Regression guard: an icon tile beside a heading and its copy is ONE icon_box, not a two-column row.
 *
 * WHY THIS FILE EXISTS. A source writes a feature row as
 *
 *     <div class="flex items-start gap-5">
 *       <div class="w-12 h-12 rounded-lg border">[svg]</div>
 *       <div><h3>BESPOKE EXPERIENCES</h3><p>Every trip is tailored…</p></div>
 *     </div>
 *
 * and the capture stamps `data-sc-col` on BOTH children. `layout_row` therefore read it as a two-column
 * layout and decomposed each side. Measured on one band, three such rows produced
 *
 *     4 special_heading + 3 icon + 4 text_block      (in nested flexboxes)
 *
 * where the source has three feature items. Nothing was LOST — which is why no coverage or text audit
 * ever reported it — but the structure was: no icon position / size / gap options, nine unrelated nodes
 * instead of three, and no row editable as a unit.
 *
 * THE PART THAT MADE THIS TAKE TWO ATTEMPTS. A first attempt registered the rule at priority 80 and sat
 * completely INERT, while its matcher returned true whenever it was called by hand. `layout_row` sits at
 * 82 and claimed the row first: a more specific shape has to OUT-RANK the general one, and nothing else
 * was wrong.
 *
 * (The first diagnosis of that was also wrong, and the negative below exists because of it. Calling
 * `claim_element()` on the row directly showed it claiming one, so the allow-list of ids that function
 * consults looked like the gate, and the rule was added to it as well. Disabling each half separately
 * showed the list entry changed nothing — priority alone is the fix — so it was reverted rather than
 * left in with a comment misexplaining why the fix worked.)
 *
 * `card_grid` sits above `layout_row` for the identical reason: a card grid "otherwise claimed by
 * layout_row and decomposed to heading + text per cell, its icons and box skins lost". This is that bug
 * a second time, in a different shape.
 *
 * NOT is_icon_text_row(), which is the feature-list CHIP: an icon beside a SHORT label, explicitly
 * without a heading. The heading is what separates the two, so they cannot claim the same element.
 *
 * The fixture is the real band, reduced, with the source's own naming replaced.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/icon-feature-row-is-one-icon-box-test.php"
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

$ref = new ReflectionClass( 'FW_Site_Converter_Stitch' );
$ifr = $ref->getMethod( 'is_icon_feature_row' ); $ifr->setAccessible( true );

/** Every shortcode in a built page, counted by slug. */
$counts = function ( $html ) {
	FW_Site_Converter_Mapper::set_style_config( array() );
	$pages = FW_Site_Converter_Mapper::build_pages( FW_Site_Converter_Stitch::html_to_mapping( $html, 'I', 'i', true ) );
	$n     = array();
	$walk  = function ( $x ) use ( &$walk, &$n ) {
		if ( ! is_array( $x ) ) { return; }
		foreach ( $x as $v ) {
			if ( ! is_array( $v ) ) { continue; }
			$sc = (string) ( $v['shortcode'] ?? '' );
			if ( '' !== $sc ) { $n[ $sc ] = ( $n[ $sc ] ?? 0 ) + 1; }
			$walk( $v['_items'] ?? $v );
		}
	};
	$walk( $pages );
	return $n;
};
$el = function ( $html ) {
	$d = new DOMDocument();
	libxml_use_internal_errors( true );
	$d->loadHTML( '<?xml encoding="utf-8" ?><div id="r">' . $html . '</div>' );
	libxml_clear_errors();
	return $d->getElementById( 'r' )->firstChild;
};
$svg  = '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path d="M2 12h20"/></svg>';
$tile = '<div class="w-12 h-12 rounded-lg border" data-sc-cs="display:flex;width:48px;height:48px">' . $svg . '</div>';
$row  = function ( $body ) use ( $tile ) {
	return '<div class="flex items-start gap-5" data-sc-cs="display:flex;flex-direction:row;column-gap:20px;align-items:flex-start">'
		. $tile . $body . '</div>';
};

echo "\n== The real band's three feature rows become three icon boxes\n";

$fixture = __DIR__ . '/fixtures/icon-feature-row-is-one-icon-box.html';
if ( ! is_file( $fixture ) ) { fwrite( STDERR, "FAIL: fixture missing\n" ); exit( 1 ); }
$n = $counts( file_get_contents( $fixture ) );

$ok( (int) ( $n['icon_box'] ?? 0 ) >= 3,
	sprintf( 'at least three icon_box elements (got %d)', (int) ( $n['icon_box'] ?? 0 ) ) );
// The decomposed shape is the defect stated exactly: a heading and a text block PER ROW, plus a loose
// icon. Counting icon_box alone would pass even if the decomposed copies were still emitted beside it.
$ok( (int) ( $n['icon'] ?? 0 ) === 0,
	sprintf( 'and NO loose icon elements left over (got %d)', (int) ( $n['icon'] ?? 0 ) ) );
$ok( (int) ( $n['special_heading'] ?? 0 ) <= 1,
	sprintf( 'and the band keeps one heading, not one per row (got %d)', (int) ( $n['special_heading'] ?? 0 ) ) );

echo "\n== The rule itself\n";

$ok( true === $ifr->invoke( null, $el( $row( '<div><h3 data-sc-cs="font-size:18px">TITLE</h3><p data-sc-cs="font-size:12px">Copy.</p></div>' ) ) ),
	'an icon tile beside a heading + copy is a feature row' );

echo "\n== NEGATIVE: the shapes it must NOT claim\n";

// A genuine two-column band: both sides are content. Claiming it would fold a whole column into a card.
$two_col = '<div class="flex" data-sc-cs="display:flex;flex-direction:row">'
	. '<div><h3 data-sc-cs="font-size:18px">Left</h3><p data-sc-cs="font-size:12px">Copy.</p></div>'
	. '<div><h3 data-sc-cs="font-size:18px">Right</h3><p data-sc-cs="font-size:12px">Copy.</p></div></div>';
$ok( false === $ifr->invoke( null, $el( $two_col ) ),
	'a row whose BOTH sides are content is not a feature row' );

// The feature-list CHIP (is_icon_text_row's shape): an icon beside a short label, no heading. Claiming
// it here would turn a one-line chip into a full icon_box.
$ok( false === $ifr->invoke( null, $el( $row( '<span data-sc-cs="font-size:13px">No credit impact</span>' ) ) ),
	'an icon beside a bare label (no heading) is left to the chip reader' );

// A PHOTO makes it an image card, and the icon_box has no image surface — claiming it would drop the photo.
$ok( false === $ifr->invoke( null, $el(
		'<div class="flex" data-sc-cs="display:flex;flex-direction:row">'
		. '<div data-sc-cs="display:block"><img src="about:blank.jpg" alt="p" data-sc-cs="display:block"></div>'
		. '<div><h3 data-sc-cs="font-size:18px">TITLE</h3><p data-sc-cs="font-size:12px">Copy.</p></div></div>' ) ),
	'a row carrying a real photo is not claimed' );

// A COLUMN stack is not a row; its icon sits above the text and the generic walk already handles it.
$ok( false === $ifr->invoke( null, $el(
		'<div class="flex flex-col" data-sc-cs="display:flex;flex-direction:column">' . $tile
		. '<div><h3 data-sc-cs="font-size:18px">TITLE</h3><p data-sc-cs="font-size:12px">Copy.</p></div></div>' ) ),
	'a flex COLUMN is not a feature row' );

echo "\n== It must OUT-RANK layout_row, or it does nothing at all\n";

// This is the assertion that would have caught the inert first attempt. A matcher can be perfectly
// correct and still never run, and every content check above would still pass — the decomposed shape
// keeps all the text. Only the priority relation says whether the rule is reachable.
$rec = $ref->getMethod( 'recognizers' ); $rec->setAccessible( true );
$set = (array) $rec->invoke( null );
$ok( isset( $set['icon_feature_row'] ), 'the recognizer is registered' );
$ok( isset( $set['icon_feature_row'], $set['layout_row'] )
	&& (int) $set['icon_feature_row']['priority'] > (int) $set['layout_row']['priority'],
	sprintf( 'and out-ranks layout_row (%s vs %s)',
		$set['icon_feature_row']['priority'] ?? '?', $set['layout_row']['priority'] ?? '?' ) );

printf( "\n%s\n", $fails ? "{$fails} FAIL(S)" : 'ALL PASS' );
exit( $fails ? 1 : 0 );
