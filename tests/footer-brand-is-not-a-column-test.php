<?php
/**
 * Regression guard: the footer's BRAND WORDMARK is not a column heading.
 *
 * WHY THIS FILE EXISTS. A converted footer came out 526px tall against the source's 280px — 88% taller —
 * and the screenshots showed why. Measured on both pages:
 *
 *                       source   build
 *     footer height     280      526
 *     <svg> (icons)       3        7
 *     <a> links          13       14
 *     brand tagline      x1       x2
 *     column headings   STUDIO / SERVICES / LEGAL      +  one more, the BRAND NAME
 *
 * The source footer is ONE row of five columns: a brand block (wordmark + tagline + three social icons),
 * three link columns, and the copyright. The build stacked it into three rows because it had invented a
 * FOURTH column, headed by the brand wordmark, holding a second copy of the social icons.
 *
 * `detect_footer_columns()` returned it, and its shape is the tell — it has no content of any kind:
 *
 *     { title: <the brand wordmark>, tag: 'h4', links: [], seq: [], items: [], rows: [],
 *       newsletter: null, kind: 'social', append_social: true }
 *
 * It exists only to say "put the social row here", under a heading the source does not have. The source's
 * own footer carries exactly three <h4> elements; the fourth title came from `footer_label_heads()`, which
 * looks for a short uppercase leaf that opens a group of links so a column headed by a styled <div> is not
 * missed. The brand wordmark is exactly that shape, and the social icons beside it are exactly that group.
 *
 * THE GUARD IS ON CONTENT, NOT ON THE BRAND. Testing "is this text the brand?" would need the site name and
 * would fail on any footer that words its wordmark differently. A nav column is defined by having something
 * IN it: links, sequence rows, items, text rows, or a newsletter form. A column with none of those is not a
 * column — whatever its heading says. That also leaves a genuine "FOLLOW US" column alone, because such a
 * column's social links ARE its content.
 *
 * The fixture is the real footer, reduced from the capture under a predicate requiring the contentless
 * column to survive the trim.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/footer-brand-is-not-a-column-test.php"
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

$ref = new ReflectionClass( 'FW_Site_Converter_Stitch' );
$m   = $ref->getMethod( 'detect_footer_columns' );
$m->setAccessible( true );

/** How much content a detected column actually carries. */
$payload = function ( array $c ) {
	return count( (array) ( $c['links'] ?? array() ) )
		+ count( (array) ( $c['rows'] ?? array() ) )
		+ count( (array) ( $c['items'] ?? array() ) )
		+ count( (array) ( $c['seq'] ?? array() ) )
		+ ( empty( $c['newsletter'] ) ? 0 : 1 );
};

$fixture = __DIR__ . '/fixtures/footer-brand-is-not-a-column.html';
if ( ! is_file( $fixture ) ) { fwrite( STDERR, "FAIL: fixture missing\n" ); exit( 1 ); }

echo "\n== A column with nothing in it is not a column\n";

$cols  = (array) $m->invoke( null, file_get_contents( $fixture ) );
$empty = array();
foreach ( $cols as $c ) { if ( 0 === $payload( $c ) ) { $empty[] = (string) ( $c['title'] ?? '' ); } }

$ok( 0 === count( $empty ), sprintf( 'no contentless column survives (got %d: %s)',
	count( $empty ), implode( ', ', array_map( function ( $t ) { return '"' . substr( $t, 0, 18 ) . '"'; }, $empty ) ) ) );

echo "\n== …and the real columns are untouched\n";

$ok( 3 === count( $cols ), sprintf( 'the footer yields its three link columns (got %d)', count( $cols ) ) );

$titles = array();
foreach ( $cols as $c ) { $titles[] = strtoupper( trim( (string) ( $c['title'] ?? '' ) ) ); }
foreach ( array( 'STUDIO', 'SERVICES', 'LEGAL' ) as $want ) {
	$ok( in_array( $want, $titles, true ), "the \"$want\" column is still detected" );
}
$counts = array();
foreach ( $cols as $c ) { $counts[] = count( (array) ( $c['links'] ?? array() ) ); }
$ok( array_sum( $counts ) >= 13, sprintf( 'and they keep all their links (%d)', array_sum( $counts ) ) );

echo "\n== NEGATIVE: a genuine social column keeps its place\n";

// A column whose content IS its social links must survive — that is the case the guard must not catch.
// Here the links are real anchors with labels, which is what makes the column a column.
$follow = '<!DOCTYPE html><html><body data-sc-cs="font-family:Inter, sans-serif"><main>'
	. '<section data-sc-cs="padding:40px 0px;display:block"><p data-sc-cs="font-size:16px">Body copy.</p></section></main>'
	. '<footer data-sc-cs="padding:48px 0px;display:block">'
	. '<div data-sc-cs="display:grid;grid-template-columns:300px 300px;gap:32px">'
	. '<div data-sc-cs="display:block"><h4 data-sc-cs="font-size:14px;text-transform:uppercase">Company</h4>'
		. '<a href="/about" data-sc-cs="display:block">About Us</a><a href="/jobs" data-sc-cs="display:block">Careers</a></div>'
	. '<div data-sc-cs="display:block"><h4 data-sc-cs="font-size:14px;text-transform:uppercase">Follow Us</h4>'
		. '<a href="https://example.org/a" data-sc-cs="display:block">Instagram</a>'
		. '<a href="https://example.org/b" data-sc-cs="display:block">Facebook</a></div>'
	. '</div></footer></body></html>';
$fcols = (array) $m->invoke( null, $follow );
$ftitles = array();
foreach ( $fcols as $c ) { $ftitles[] = strtoupper( trim( (string) ( $c['title'] ?? '' ) ) ); }
$ok( in_array( 'FOLLOW US', $ftitles, true ), 'a column of named social links is kept' );
$ok( in_array( 'COMPANY', $ftitles, true ), '…alongside the ordinary link column' );

echo "\n== NEGATIVE: a REAL heading over ICON-ONLY social links is still a column\n";

// The case the first version of this guard got wrong, caught by another golden rather than by this one.
// Icon-only social links carry a glyph AND a social href, so they are filtered out of $links upstream —
// the column therefore arrives with links:[] and append_social:true, which is exactly the brand block's
// shape. The negative above does NOT exercise it, because NAMED social links survive that filter and give
// the column content. What separates the two is the element the title came from: here the source marked
// its heading up as a heading; the brand block's wordmark is a styled <div> picked up by
// footer_label_heads(). Only the latter may be dropped.
$icons = '<!DOCTYPE html><html><body data-sc-cs="font-family:Inter, sans-serif"><main>'
	. '<section data-sc-cs="padding:40px 0px;display:block"><p data-sc-cs="font-size:16px">Body copy.</p></section></main>'
	. '<footer data-sc-cs="padding:48px 0px;display:block">'
	. '<div data-sc-cs="display:grid;grid-template-columns:300px 300px;gap:32px">'
	. '<div data-sc-cs="display:block"><h4 data-sc-cs="font-size:14px;text-transform:uppercase">Company</h4>'
		. '<a href="/about" data-sc-cs="display:block">About Us</a><a href="/jobs" data-sc-cs="display:block">Careers</a></div>'
	. '<div data-sc-cs="display:block"><h4 data-sc-cs="font-size:14px;text-transform:uppercase">Telemetry</h4>'
		. '<a href="https://instagram.com/x" data-sc-cs="display:block"><svg viewBox="0 0 24 24"><path d="M0 0"/></svg></a>'
		. '<a href="https://facebook.com/x" data-sc-cs="display:block"><svg viewBox="0 0 24 24"><path d="M0 0"/></svg></a>'
		. '<a href="https://twitter.com/x" data-sc-cs="display:block"><svg viewBox="0 0 24 24"><path d="M0 0"/></svg></a></div>'
	. '</div></footer></body></html>';
$icols   = (array) $m->invoke( null, $icons );
$ititles = array();
foreach ( $icols as $c ) { $ititles[] = strtoupper( trim( (string) ( $c['title'] ?? '' ) ) ); }
$ok( in_array( 'TELEMETRY', $ititles, true ), 'a real heading over icon-only social links keeps its column' );
$ok( in_array( 'COMPANY', $ititles, true ), '…and the ordinary column beside it' );

printf( "\n%s\n", $fails ? "{$fails} FAIL(S)" : 'ALL PASS' );
exit( $fails ? 1 : 0 );
