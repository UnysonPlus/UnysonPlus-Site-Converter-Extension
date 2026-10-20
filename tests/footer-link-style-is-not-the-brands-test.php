<?php
/**
 * Regression guard: the footer's house LINK STYLE is sampled from a link, never from the brand lockup.
 *
 * WHY THIS FILE EXISTS. A converted footer rendered every column of links a third too large and stood
 * 183px taller than its source -- the single largest measured defect on that page's chrome:
 *
 *     footer height     435px     ->   618px
 *     each nav link     12px/16px ->   16px/24px
 *     list rhythm       12px      ->   40px
 *
 * HOW IT GOT THERE. `footer_link_css()` styles every footer link from ONE sampled anchor, so the sample
 * decides the whole footer. `footer_link_is_brand()` was there to keep the brand out of that sample, and
 * its entire test was whether the link contained an `svg` or an `img`. This source's brand is a wordmark
 * set in TEXT:
 *
 *     <a href="/"><span>NAME</span><span>tagline</span></a>
 *
 * No image, so it was not recognised, and it is the first anchor in the footer -- so the wordmark's
 * 16px/24px became the rule for links the source sets at 12px/16px. The same anchor's container is the
 * footer GRID, so its 40px column gap was read as the list's vertical rhythm at the same time: one wrong
 * sample, three wrong numbers.
 *
 * HOW IT IS TOLD APART NOW. The brand is the element the SITE TITLE names, which is exactly how
 * `footer_brand_css()` already finds the wordmark -- so the two readers cannot disagree about which
 * element the brand is. `$site_title` is the LOGO text, the wordmark alone, while the link's own text is
 * the wordmark and its tagline run together; so the match is made against a LEAF DESCENDANT of the link,
 * not against the link's whole text. Comparison is on alphanumerics only, because a lockup splits its
 * name across spans and the text arrives with the space missing.
 *
 * WHY NOT MATCH A PREFIX of the link's text, which would be simpler: a nav label that merely opens with
 * the brand name ("Acme Pricing") would be thrown away, and then a footer whose links all begin that way
 * would have no sample left at all. The NEGATIVES below hold that line.
 *
 * WHY NOT just skip the first anchor, or prefer the smallest font: the first anchor is only sometimes the
 * brand, and "smallest" would pick a legal link out of the copyright bar, which is 11px here and is not
 * the column style either.
 *
 * The fixture is the real footer, reduced, with the source's own naming and contact details replaced.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/footer-link-style-is-not-the-brands-test.php"
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
$flc = $ref->getMethod( 'footer_link_css' );    $flc->setAccessible( true );
$fib = $ref->getMethod( 'footer_link_is_brand' ); $fib->setAccessible( true );

/** One declaration out of a rule block, or ''. */
$decl = function ( $css, $sel, $prop ) {
	if ( ! preg_match( '/' . preg_quote( $sel, '/' ) . '\{([^}]*)\}/', $css, $m ) ) { return ''; }
	return preg_match( '/(?:^|;)\s*' . preg_quote( $prop, '/' ) . '\s*:\s*([^;]+)/i', $m[1], $d ) ? trim( $d[1] ) : '';
};

echo "\n== The real footer is styled from its LINKS, not its wordmark\n";

$fixture = __DIR__ . '/fixtures/footer-contact-rows-are-not-one-row.html';
if ( ! is_file( $fixture ) ) { fwrite( STDERR, "FAIL: fixture missing\n" ); exit( 1 ); }
$html = file_get_contents( $fixture );

// 'BRANDMARK' is the fixture's logo text -- what the pipeline passes as $site_title (it is the LOGO text,
// not the <title>, which is a full SEO string and would match nothing).
$css = (string) $flc->invoke( null, $html, 'BRANDMARK' );

$fs = $decl( $css, '.footer-column .footer-link', 'font-size' );
$ok( '12px' === $fs, sprintf( 'the link size is the nav links\' 12px, not the wordmark\'s 16px (got %s)', wp_json_encode( $fs ) ) );

$lh = '';
if ( preg_match( '/\{line-height:([^;}]+)[;}]/', $css, $lm ) ) { $lh = trim( $lm[1] ); }
$ok( '16px' === $lh, sprintf( 'and the line-height is 16px, not 24px (got %s)', wp_json_encode( $lh ) ) );

echo "\n== ...and the list rhythm comes from the LIST, not the grid's column gap\n";

// The brand link's container is the footer grid, so sampling it also read the 40px COLUMN gap as the
// vertical rhythm between list items. Worth its own assertion: it is a separate number with a separate
// cause, and it was wrong for the same single reason.
$mb = $decl( $css, '.footer-column .footer-links-list>li:not(:last-child)', 'margin-bottom' );
$ok( '12px' === $mb, sprintf( 'the item gap is 12px, not the grid\'s 40px (got %s)', wp_json_encode( $mb ) ) );

echo "\n== The guard itself: a text wordmark is the brand\n";

$el = function ( $html ) {
	$d = new DOMDocument();
	libxml_use_internal_errors( true );
	$d->loadHTML( '<?xml encoding="utf-8" ?><div id="r">' . $html . '</div>' );
	libxml_clear_errors();
	return $d->getElementById( 'r' )->firstChild;
};

$lockup = '<a href="/"><span>BRANDMARK</span><span>tagline here</span></a>';
$ok( true === $fib->invoke( null, $el( $lockup ), 'BRANDMARK' ), 'a text lockup naming the site IS the brand' );
$ok( true === $fib->invoke( null, $el( '<a href="/"><img src="about:blank" alt="x"></a>' ), '' ),
	'an image link is still the brand, with no title to compare' );
$ok( true === $fib->invoke( null, $el( '<a href="/"><span>Brand Mark</span></a>' ), 'BRANDMARK' ),
	'and the match ignores case and spacing, as a split wordmark needs' );

echo "\n== NEGATIVE: an ordinary nav link is NOT the brand\n";

// If these were thrown away there would be no sample left and the footer would take the theme's defaults,
// which is the defect this whole reader exists to prevent.
$ok( false === $fib->invoke( null, $el( '<a href="/destinations">AFRICA</a>' ), 'BRANDMARK' ),
	'a plain nav label is not the brand' );
$ok( false === $fib->invoke( null, $el( '<a href="/pricing">BRANDMARK Pricing</a>' ), 'BRANDMARK' ),
	'nor is a label that merely OPENS with the brand name' );
$ok( false === $fib->invoke( null, $el( '<a href="/">Home</a>' ), 'BRANDMARK' ),
	'nor is a link to the site root that does not name it' );
$ok( false === $fib->invoke( null, $el( '<a href="/destinations">AFRICA</a>' ), '' ),
	'and with no title, a plain link is still not the brand' );

echo "\n== NEGATIVE: with no title to compare, the old image-only behaviour stands\n";

// Not every capture yields a logo text. This must degrade to what it did before rather than to nothing.
$ok( false === $fib->invoke( null, $el( $lockup ), '' ), 'a text lockup is not recognised without a title' );
$no_title = (string) $flc->invoke( null, $html, '' );
$ok( '' !== $no_title, 'and a rule is still produced, rather than none at all' );

printf( "\n%s\n", $fails ? "{$fails} FAIL(S)" : 'ALL PASS' );
exit( $fails ? 1 : 0 );
