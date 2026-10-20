<?php
/**
 * Regression guard: a footer column of PLAIN TEXT is a column, and the copyright band is the band that
 * holds the notice's peers -- not the wrapper the notice happens to share with its mark.
 *
 * WHY THIS FILE EXISTS. A footer listed three offices as three grid tracks:
 *
 *     <div class="grid grid-cols-3">
 *       <div><p>New York</p><p>+1 (212) 550-4800</p><p>540 Madison Ave, 32nd Fl</p></div>
 *       ...
 *
 * Nothing in those columns is an anchor -- the phone number is text, not a `tel:` -- and every route into
 * `footer_label_heads()` ended at a link, so all three were rejected before they were considered.
 * `detect_footer_columns()` then returned NO columns for a band `footer_bands_html()` had itself measured
 * at three, and the single-band fallback invented a two-column bar out of the BOTTOM strip's links
 * instead. Measured against the source: nine text runs -- three cities, three numbers, three street
 * addresses -- simply absent from the page, with nothing in any coverage report to say so, because the
 * runs were never claimed by anything that could have reported losing them.
 *
 * The bottom bar failed for an unrelated reason in the same footer, which is why both live here. The
 * copyright band was taken to be the notice's own PARENT. That holds while the notice is a direct child
 * of the bar, and breaks the moment the source pairs it with a mark -- `<div class="flex items-center
 * gap-2"><svg><span>(c) ...` -- because the band then resolved to that two-child wrapper, which holds no
 * links at all. Of the bar's four items, the two anchors leaked upward into the MAIN bar as a column of
 * their own and the two click-styled spans were lost outright.
 *
 * THE DISCRIMINATOR IS THE TRACK, NOT THE TEXT, and the negatives below are the real content of this
 * file. A footer's BRAND block reads identically from outside -- a short wordmark over two lines of
 * prose -- and must stay the lead block. Asking what the words mean would need the site name and would
 * miss any footer that words its wordmark differently; what actually separates them is that an office
 * column is one of SEVERAL SIBLING TRACKS of an explicit grid, which the capture stamps. And the band
 * climb stops before the <footer>, so a link-free copyright bar can never widen into the main
 * navigation and turn the site menu into legal links.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/footer-text-column-is-a-column-test.php"
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

$ref  = new ReflectionClass( 'FW_Site_Converter_Stitch' );
$meth = function ( $n ) use ( $ref ) { $m = $ref->getMethod( $n ); $m->setAccessible( true ); return $m; };
$cols = $meth( 'detect_footer_columns' );
$legal = $meth( 'detect_footer_legal_links' );
$head  = $meth( 'footer_text_column_head' );
$pt    = $meth( 'parse_tokens' );

$fixture = __DIR__ . '/fixtures/footer-text-column-is-a-column.html';
if ( ! is_file( $fixture ) ) { fwrite( STDERR, "FAIL: fixture missing\n" ); exit( 1 ); }
$html = (string) file_get_contents( $fixture );

echo "\n== The three text columns are three columns\n";

$groups = (array) $cols->invoke( null, $html );
$titles = array();
foreach ( $groups as $g ) { $titles[] = (string) ( $g['title'] ?? '' ); }
$ok( 3 === count( $groups ), sprintf( 'detect_footer_columns returns three groups (got %d)', count( $groups ) ) );
$ok( array( 'New York', 'London', 'Singapore' ) === $titles,
	'titled by the three city labels, in source order (got ' . implode( ' | ', $titles ) . ')' );

// The title must not ALSO arrive as the column's first line. A text column is headed by a `<p>`, so the
// heading-tag skip in the collection loop does not cover it, and the city name rendered twice.
$dupe = false;
foreach ( $groups as $g ) {
	foreach ( (array) ( $g['items'] ?? array() ) as $it ) {
		if ( trim( mb_strtolower( (string) $it ) ) === trim( mb_strtolower( (string) $g['title'] ) ) ) { $dupe = true; }
	}
}
$ok( ! $dupe, 'and the title is not repeated as the column\'s first line' );
$counts = array();
foreach ( $groups as $g ) { $counts[] = count( (array) ( $g['items'] ?? array() ) ); }
$ok( array( 2, 2, 2 ) === $counts, 'each carries its two lines (got ' . implode( ',', $counts ) . ')' );

echo "\n== Every run survives into the footer bar the theme will render\n";

$chrome = FW_Site_Converter_Stitch::tokens_to_theme_settings_chrome( (array) $pt->invoke( null, $html ), $html, 'NORTHGATE' );
$values = (array) ( $chrome['values'] ?? array() );
$mfc    = (array) ( $values['main_footer_columns'] ?? array() );
$ok( '4' === (string) ( $mfc['count'] ?? '' ),
	sprintf( 'the main bar is four columns -- brand plus three offices (got %s)', var_export( $mfc['count'] ?? null, true ) ) );

// Counting columns is not enough: a column can be present and empty. These are the exact runs the page lost.
$bar = wp_json_encode( $mfc );
foreach ( array( 'New York', 'London', 'Singapore', '+1 (212) 550-4800', '+44 20 3828 2200',
	'+65 6909 5700', '540 Madison Ave, 32nd Fl', '100 Bishopsgate, Level 19', 'Marina Bay Financial Tower 1' ) as $run ) {
	$ok( false !== strpos( $bar, $run ), sprintf( 'carries %s', wp_json_encode( $run ) ) );
}

echo "\n== The copyright bar is the band that holds the notice's PEERS\n";

$links = (array) $legal->invoke( null, $html );
$lbls  = array();
foreach ( $links as $l ) { $lbls[] = (string) $l['label']; }
$ok( array( 'CONFIDENTIAL INTAKE', 'PRACTICE AREAS', 'PRIVACY POLICY', 'LEGAL NOTICE' ) === $lbls,
	'all four bottom-bar items are read, anchors and click-styled spans alike (got ' . implode( ' | ', $lbls ) . ')' );
$cr = wp_json_encode( $values['copyright_settings'] ?? array() );
$ok( false !== strpos( $cr, '"count":"2"' ), 'and the copyright bar is two columns: the notice and the row' );
$ok( false !== strpos( $cr, 'PRIVACY POLICY' ) && false !== strpos( $cr, 'LEGAL NOTICE' ),
	'with the two span-only items in it' );
// ...and they must not ALSO appear in the main bar, which is where they leaked to before.
$ok( false === strpos( $bar, 'CONFIDENTIAL INTAKE' ),
	'the bottom bar\'s links no longer leak upward into the main bar' );

echo "\n== NEGATIVE: the shapes footer_text_column_head() must NOT claim\n";

$el = function ( $html, $id ) {
	$d = new DOMDocument();
	libxml_use_internal_errors( true );
	$d->loadHTML( '<?xml encoding="utf-8" ?>' . $html );
	libxml_clear_errors();
	return $d->getElementById( $id );
};

// A BRAND BLOCK: a short label over two lines of prose, but NOT a track of a grid. This is the shape the
// brand guard exists for, and two of them live in the nocturnal fixture.
$brand = '<div class="footer-bottom" data-sc-cs="display:flex"><div data-sc-cs="display:block;track-frac:0.088">'
	. '<p id="L" data-sc-cs="text-transform:uppercase;display:block">AETHER HOUSE</p>'
	. '<p data-sc-cs="display:block">A studio for nocturnal interfaces.</p>'
	. '<p data-sc-cs="display:block">Built after dark.</p></div></div>';
$ok( false === $head->invoke( null, $el( $brand, 'L' ) ),
	'a brand block in a FLEX row is not a column (its parent declares no tracks)' );

// A label over ONE line is a caption, not a column.
$one = '<div data-sc-cs="display:grid;grid-template-columns:200px 200px"><div data-sc-cs="display:block">'
	. '<p id="L" data-sc-cs="text-transform:uppercase;display:block">HOURS</p>'
	. '<p data-sc-cs="display:block">Mon-Fri</p></div></div>';
$ok( false === $head->invoke( null, $el( $one, 'L' ) ), 'a label over a single line is not a column' );

// A column holding a LINK belongs to the nav reader, which carries links and their hrefs properly.
$withlink = '<div data-sc-cs="display:grid;grid-template-columns:200px 200px"><div data-sc-cs="display:block">'
	. '<p id="L" data-sc-cs="text-transform:uppercase;display:block">COMPANY</p>'
	. '<a href="/about" data-sc-cs="display:block">About</a><a href="/jobs" data-sc-cs="display:block">Jobs</a></div></div>';
$ok( false === $head->invoke( null, $el( $withlink, 'L' ) ),
	'a column holding links is left to the nav reader' );

// A single-track grid is a stack, not a row of columns.
$single = '<div data-sc-cs="display:grid;grid-template-columns:342px"><div data-sc-cs="display:block">'
	. '<p id="L" data-sc-cs="text-transform:uppercase;display:block">NEW YORK</p>'
	. '<p data-sc-cs="display:block">+1 212 550 4800</p><p data-sc-cs="display:block">540 Madison Ave</p></div></div>';
$ok( false === $head->invoke( null, $el( $single, 'L' ) ), 'a ONE-track grid is a stack, not columns' );

echo "\n== NEGATIVE: the band climb must never reach the navigation\n";

// A footer whose bottom bar carries NO peer of the notice. The climb must stop rather than widen until it
// finds links -- the only links here are the main nav's, and harvesting them would publish the site menu
// as legal links.
$nolinks = '<html><head><title>Northgate Chambers</title></head><body><footer data-sc-cs="display:block">'
	. '<div data-sc-cs="display:flex"><h4 data-sc-cs="display:block">COMPANY</h4>'
	. '<a href="/about" data-sc-cs="display:block">About</a><a href="/jobs" data-sc-cs="display:block">Jobs</a>'
	. '<a href="/press" data-sc-cs="display:block">Press</a></div>'
	. '<div data-sc-cs="display:flex;padding:32px 0px 0px"><div data-sc-cs="display:flex">'
	. '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14"><path d="M2 2h4"></path></svg>'
	. '<span data-sc-cs="display:inline">&copy; 2026 Northgate Chambers. All rights reserved.</span>'
	. '</div></div></footer></body></html>';
$nl = (array) $legal->invoke( null, $nolinks );
$got = array();
foreach ( $nl as $l ) { $got[] = (string) $l['label']; }
$ok( array() === $got, 'a link-free copyright bar harvests nothing (got ' . implode( ' | ', $got ) . ')' );

echo "\n== NEGATIVE: a WRAPPED footer must not hand the nav to the copyright bar\n";

// The case a corpus A/B caught, and the reason the climb stops at a column heading. Stopping only at the
// <footer> was not enough: this footer wraps BOTH bands in one container, and its bottom strip is a single
// centred line with no link in it, so the climb reached the wrapper -- where the site's own menu links read
// as peers of the notice. Twelve nav links were published as legal links on a footer that has none.
//
// A copyright strip does not contain a column heading; a container that also holds the columns does.
$wrapped = '<html><head><title>Northgate Chambers</title></head><body><footer data-sc-cs="display:block">'
	. '<div class="max-w-7xl" data-sc-cs="display:block">'
	. '<div data-sc-cs="display:grid;grid-template-columns:300px 300px">'
	. '<div data-sc-cs="display:block"><h3 data-sc-cs="display:block">Quick Links</h3>'
	. '<a href="/about" data-sc-cs="display:block">About</a><a href="/jobs" data-sc-cs="display:block">Careers</a></div>'
	. '<div data-sc-cs="display:block"><h3 data-sc-cs="display:block">Programs</h3>'
	. '<a href="/a" data-sc-cs="display:block">Litigation</a><a href="/b" data-sc-cs="display:block">Arbitration</a></div>'
	. '</div>'
	. '<div class="border-t pt-8 text-center" data-sc-cs="display:block;padding:32px 0px 0px">'
	. '<p data-sc-cs="display:block">&copy; 2026 Northgate Chambers. All rights reserved.</p></div>'
	. '</div></footer></body></html>';
$wg = array();
foreach ( (array) $legal->invoke( null, $wrapped ) as $l ) { $wg[] = (string) $l['label']; }
$ok( array() === $wg,
	'a centred, link-free bottom strip inside a wrapper harvests nothing (got ' . implode( ' | ', $wg ) . ')' );

printf( "\n%s\n", $fails ? "{$fails} FAIL(S)" : 'ALL PASS' );
exit( $fails ? 1 : 0 );
