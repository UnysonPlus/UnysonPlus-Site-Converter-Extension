<?php
/**
 * Regression guard: a price LIST is detected as a pricing table.
 *
 * WHY THIS FILE EXISTS. The pricing_table shortcode grew a `list` layout precisely for this shape — a
 * full-width row per plan, name and sub-label left, price right, a hairline between rows — because the
 * corpus said list-shaped pricing is **43% of the priced groups a conversion meets**, none of which the
 * card grid can express. The layout shipped: `views/layouts/list.php`, its registry entry, the mapper's
 * `design_settings.layout` plumbing, `pricing_layout()` in the stitch. Every piece.
 *
 * And it never fired on a real price list, because of ONE WORD BOUNDARY.
 *
 * `cell_price_parts()` tests the element's text with `…([\d][\d.,]*)(b|m|k|bn|mn)?\b…`. The text comes from
 * `self::text()`, which concatenates WITHOUT separators, so a row reads:
 *
 *     Residential CleaningRegular & DeepFrom$120Flat Rate
 *
 * `$120` is immediately followed by `F`. Digit-to-letter is not a word boundary, so `\b` fails, the row is
 * not "priced", `is_pricing_table()` counts 0 of 3 priced children against a gate of 2, and the whole band
 * falls through to generic headings and text. Measured on a real conversion: the rendered page showed three
 * stacked headings where the source had a price list.
 *
 * The same `\b`-after-a-number trap had already been found twice elsewhere in the same codebase on the same
 * day (a date gate reading `May 20, 2026Understanding…`). It is worth naming as a class: **`self::text()`
 * has no separators, so any pattern anchored with `\b` after digits will fail on real captured text.**
 *
 * The fixture is the real band, reduced: three rows, a "STARTING PRICES" overline, no prose.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/price-list-is-detected-test.php"
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

$cpp = new ReflectionMethod( 'FW_Site_Converter_Stitch', 'cell_price_parts' );
$cpp->setAccessible( true );

echo "\n== A price reads as a price even with no space after it\n";

// Driven through a DOM element, because that is how the caller reaches it, and because the defect only
// exists once self::text() has run the row's parts together.
$row = function ( $inner ) {
	$d = new DOMDocument();
	libxml_use_internal_errors( true );
	$d->loadHTML( '<?xml encoding="utf-8"?><body><div id="r">' . $inner . '</div></body>' );
	libxml_clear_errors();
	return $d->getElementById( 'r' );
};

$r1 = $cpp->invoke( null, $row( '<div>Residential Cleaning</div><div>Regular &amp; Deep</div><div>From</div><div>$120</div><div>Flat Rate</div>' ) );
$ok( is_array( $r1 ), 'a row whose price runs into the next word is priced' );
$ok( is_array( $r1 ) && '120' === (string) ( $r1['price'] ?? '' ), '…and the price is read correctly (' . ( is_array( $r1 ) ? ( $r1['price'] ?? '?' ) : 'null' ) . ')' );

$r2 = $cpp->invoke( null, $row( '<div>From $200 Flat Rate</div>' ) );
$ok( is_array( $r2 ) && '200' === (string) ( $r2['price'] ?? '' ), 'a spaced row still works (it always did)' );

echo "\n== NEGATIVE: a magnitude suffix is still a STAT, not a price\n";

// The suffix test is what the boundary was there to protect, so it is asserted directly: a headline metric
// must never become a plan price with a fabricated period.
foreach ( array( '$4.2B', '$18M', '$9K', '$4.2Billion' ) as $stat ) {
	$ok( null === $cpp->invoke( null, $row( '<div>' . $stat . '</div><div>processed</div>' ) ),
		"$stat is rejected as a stat" );
}
$ok( null === $cpp->invoke( null, $row( '<div>$18,240,000.00</div>' ) ), 'a seven-figure number is rejected too' );

echo "\n== The band is claimed as a pricing table\n";

$fixture = __DIR__ . '/fixtures/price-list-rows.html';
if ( ! is_file( $fixture ) ) { fwrite( STDERR, "FAIL: fixture missing\n" ); exit( 1 ); }
$html = file_get_contents( $fixture );

$ipt = new ReflectionMethod( 'FW_Site_Converter_Stitch', 'is_pricing_table' );
$ipt->setAccessible( true );
$dom = new DOMDocument();
libxml_use_internal_errors( true );
$dom->loadHTML( '<?xml encoding="utf-8"?>' . $html );
libxml_clear_errors();
$claimed = 0;
foreach ( ( new DOMXPath( $dom ) )->query( '//main//div' ) as $el ) {
	if ( $ipt->invoke( null, $el ) ) { $claimed++; }
}
$ok( $claimed > 0, "a container is claimed as pricing ($claimed)" );

$map  = FW_Site_Converter_Stitch::html_to_mapping( $html, 'P', 'p', true );
$mblob = wp_json_encode( $map );
$ok( false !== strpos( $mblob, '"t":"pricing"' ), 'the mapping carries a pricing block' );

echo "\n== …as a LIST, and it reaches the page\n";

$ok( (bool) preg_match( '/"layout":"list"/', $mblob ), 'the block is tagged layout=list (' .
	( preg_match( '/"layout":"([a-z]+)"/', $mblob, $lm ) ? $lm[1] : 'none' ) . ')' );

$pblob = wp_json_encode( FW_Site_Converter_Mapper::build_pages( $map ) );
$ok( false !== strpos( $pblob, 'pricing_table' ), 'the built page emits a pricing_table' );
$ok( false !== strpos( $pblob, 'list' ), '…carrying the list layout in its design_settings' );
foreach ( array( 'Residential Cleaning', 'Commercial Cleaning', 'Deep Sanitization' ) as $plan ) {
	$ok( false !== strpos( $pblob, $plan ), "the plan \"$plan\" survives" );
}

echo "\n== …and the plan names keep the source's face\n";

// A plan name renders as an <h4>, so with nothing said about it the generated theme paints it in the site's
// DISPLAY face: measured, a price list set in the source's body sans came out in a serif wide enough that
// two-word names wrapped. The class is repeated for (0,3,0) — the theme's own hN rule is (0,2,1) and wins a
// plain scoped selector even important-vs-important. Third element to need this exact answer.
$ok( (bool) preg_match( '/\.fw-pt__name\.fw-pt__name\s*\{[^}]*font-family:/i', $pblob ),
	'the plan name carries a font-family rule at (0,3,0)' );
$ok( (bool) preg_match( '/\.fw-pt__name\.fw-pt__name\s*\{[^}]*font-family:\s*Inter/i', $pblob ),
	'…and it is the source\'s sans, not a display serif' );

echo "\n== NEGATIVE: nothing is invented where the source stamped no family\n";

$bare_pt = str_replace( 'font-family:Inter, system-ui, -apple-system, sans-serif;', '', $html );
$bare_blob = wp_json_encode( FW_Site_Converter_Mapper::build_pages(
	FW_Site_Converter_Stitch::html_to_mapping( $bare_pt, 'P', 'p', true )
) );
$ok( ! preg_match( '/\.fw-pt__name\.fw-pt__name\s*\{[^}]*font-family:/i', $bare_blob ),
	'an unstamped price list gets no font-family rule' );

echo "\n== NEGATIVE: a plain feature grid is NOT a pricing table\n";

// The gate exists to keep an unpriced grid out. Loosening the price match must not loosen that.
$feat = '<!DOCTYPE html><html><body><main>'
	. '<section data-sc-cs="padding:80px 0px;display:block">'
	. '<div data-sc-cs="display:grid;grid-template-columns:384px 384px 384px;gap:32px">'
	. '<div data-sc-cs="padding:24px"><h3>Fast</h3><p>We arrive on time, every time.</p></div>'
	. '<div data-sc-cs="padding:24px"><h3>Safe</h3><p>Fully insured and background checked.</p></div>'
	. '<div data-sc-cs="padding:24px"><h3>Clean</h3><p>Non-toxic supplies as standard.</p></div>'
	. '</div></section></main></body></html>';
$fblob = wp_json_encode( FW_Site_Converter_Stitch::html_to_mapping( $feat, 'F', 'f', true ) );
$ok( false === strpos( $fblob, '"t":"pricing"' ), 'an unpriced feature grid is not claimed' );

printf( "\n%s\n", $fails ? "{$fails} FAIL(S)" : 'ALL PASS' );
exit( $fails ? 1 : 0 );
