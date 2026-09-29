<?php
/**
 * Guards for PAGE HIERARCHY and BREADCRUMB recognition.
 *
 * Two defects, both reported by a user looking at a converted site:
 *
 *   1. /modular-home-financing/manufacturers converted to /manufacturers. Slugs came from the LAST path
 *      segment, so the source's structure was discarded: every URL changed, and two pages under different
 *      parents collapsed onto one slug (/construction-loans/fha and .../loan-options/fha both became 'fha'),
 *      one silently overwriting the other.
 *   2. Breadcrumbs came out broken. The recognizer required the author to DECLARE the trail (aria-label, a
 *      'breadcrumb' class, schema.org). The source declared none of that and drew its separators as <svg>
 *      chevrons, so the trail was not recognised, decomposed into loose links, and -- the chevrons carrying
 *      no text -- rendered as "Home Modular Home Financing Manufacturers Unity Homes": every word present,
 *      run together, unreadable. The separators were never the signal; the STRUCTURE is.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs --allow-root eval-file \
 *     "D:/Web Dev/unysonplus/framework/extensions/site-converter/tests/hierarchy-test.php"
 */

if ( ! class_exists( 'FW_Site_Converter_Sources' ) ) {
	fwrite( STDERR, "Site Converter not loaded -- activate the extension first.\n" );
	exit( 1 );
}

$GLOBALS['__h_pass'] = 0;
$GLOBALS['__h_fail'] = 0;
function ht( $label, $cond, $got = '' ) {
	if ( $cond ) {
		$GLOBALS['__h_pass']++;
		echo "  PASS  $label\n";
	} else {
		$GLOBALS['__h_fail']++;
		echo "  FAIL  $label" . ( '' !== $got ? "  (got: $got)" : '' ) . "\n";
	}
}

$cs = function ( $e = '' ) {
	$e = trim( (string) $e, ';' );
	return 'color:rgb(20,20,20);font-family:Inter, sans-serif;font-size:16px;font-weight:400;'
		. 'line-height:24px;text-align:start;display:block' . ( '' !== $e ? ';' . $e : '' );
};

// A trail exactly as the real source published it: no aria-label, no breadcrumb class, SVG chevrons.
$chev  = '<svg width="16" height="16" viewBox="0 0 24 24" data-sc-cs="display:inline-block"><path d="M9 18l6-6-6-6"></path></svg>';
$trail = '<nav class="flex items-center gap-2 text-sm" data-sc-cs="' . $cs( 'display:flex;height:20px' ) . '">'
	. '<a href="/" data-sc-cs="' . $cs( 'height:20px' ) . '">Home</a>' . $chev
	. '<a href="/parent-section" data-sc-cs="' . $cs( 'height:20px' ) . '">Parent Section</a>' . $chev
	. '<a href="/parent-section/child-group" data-sc-cs="' . $cs( 'height:20px' ) . '">Child Group</a>' . $chev
	. '<span data-sc-cs="' . $cs( 'height:20px' ) . '">Leaf Item</span></nav>';

// Shaped like a real inner page: a masthead, the trail in its own band, then the content section. The
// first shape tried here put the trail as the first child of the page's ONLY section, which is not how a
// page is laid out and let chrome detection claim the whole band -- a fixture artifact, not a converter bug.
$mk = function ( $inner ) use ( $cs ) {
	return '<!DOCTYPE html><html data-sc-content-width="1280"><head><title>Leaf Item Long Title | Fixture</title></head>'
		. '<body data-sc-cs="' . $cs( 'background-color:rgb(255,255,255)' ) . '">'
		. '<header data-sc-cs="' . $cs( 'height:64px;display:flex' ) . '">'
		. '<a href="/" data-sc-cs="' . $cs( 'font-size:22px;font-weight:700;height:28px' ) . '">Brandmark</a>'
		. '<nav data-sc-cs="' . $cs( 'display:flex;height:20px' ) . '">'
		. '<a href="/about" data-sc-cs="' . $cs( 'height:20px' ) . '">About</a>'
		. '<a href="/pricing" data-sc-cs="' . $cs( 'height:20px' ) . '">Pricing</a></nav></header>'
		. '<section id="crumbs" data-sc-cs="' . $cs( 'padding:16px 0px;height:52px' ) . '">'
		. '<div data-sc-cs="' . $cs( 'max-width:1280px;margin:0px auto;height:20px' ) . '">' . $inner . '</div></section>'
		. '<section id="top" data-sc-cs="' . $cs( 'padding:48px 0px;height:300px' ) . '">'
		. '<div data-sc-cs="' . $cs( 'max-width:1280px;margin:0px auto;height:200px' ) . '">'
		. '<h1 data-sc-cs="' . $cs( 'font-size:44px;height:52px' ) . '">Leaf Item Long Heading</h1>'
		. '<p data-sc-cs="' . $cs( 'height:24px' ) . '">Body copy so the section is not empty, so the converter has a real band to keep.</p>'
		. '</div></section></body></html>';
};

$srcurl = 'https://fixture-01.example/parent-section/child-group/leaf-item';
$html   = $mk( $trail );
$opts   = array( 'dynamic_chrome' => true, 'hifi_css' => true, 'source_url' => $srcurl );

$res  = FW_Site_Converter_Sources::build_from_html( $html, 'x', $opts );
$json = (string) wp_json_encode( $res['files']['pages.json'] ?? array() );
$td   = $res['files']['theme-design.json'] ?? array();

/* ---- recognition: no declaration, icon separators ------------------------- */
ht(
	'[H1] an undeclared trail with SVG separators is recognised as a breadcrumb',
	false !== strpos( $json, '[breadcrumbs]' ),
	'no [breadcrumbs] emitted'
);
ht(
	'[H2] the extension it needs is requested',
	false !== strpos( (string) wp_json_encode( $td['needs_extensions'] ?? array() ), 'breadcrumbs' ),
	wp_json_encode( $td['needs_extensions'] ?? array() )
);
ht(
	'[H3] the trail is REPLACED, not also left behind as loose links',
	false === strpos( $json, 'Parent Section' ),
	'crumb text is still in the page body'
);
/* NEGATIVE: claiming the trail must not swallow its NEIGHBOURS. getElementsByTagName reaches every
   descendant, so an ancestor of a trail passes a nesting test too -- and claiming the ancestor replaces its
   whole subtree. A recognizer that silently eats a heading is far worse than one that misses a trail. */
ht(
	'[H3b] NEGATIVE: the page heading and body survive alongside the trail',
	false !== strpos( $json, 'Leaf Item Long Heading' ) && false !== strpos( $json, 'so the converter has a real band' ),
	'the trail claim swallowed neighbouring content'
);

/* ---- the source's own labels travel with it ------------------------------- */
$crumbs = (array) ( $td['crumb_labels'] ?? array() );
ht(
	'[H4] each crumb teaches its path a SHORT label',
	'Parent Section' === ( $crumbs['parent-section'] ?? '' )
		&& 'Child Group' === ( $crumbs['parent-section/child-group'] ?? '' ),
	wp_json_encode( $crumbs )
);
ht(
	'[H5] the trailing crumb is recorded as this page own label',
	'Leaf Item' === ( $crumbs['@self'] ?? '' ),
	(string) ( $crumbs['@self'] ?? '(unset)' )
);
ht(
	'[H6] the source ROOT word rides across as an extension setting',
	'Home' === ( $td['ext_settings']['breadcrumbs']['homepage-title'] ?? '' ),
	wp_json_encode( $td['ext_settings'] ?? array() )
);
/* NEGATIVE: the root crumb is the extension's homepage setting, never a path label. */
ht(
	'[H7] NEGATIVE: the root crumb does not become a path label',
	! isset( $crumbs[''] ) && ! isset( $crumbs['/'] ),
	wp_json_encode( array_keys( $crumbs ) )
);

/* ---- a page's title comes from its markup, not its slug ------------------- */
ht(
	'[H8] the page title is read from the markup, not ucwords of the slug',
	'Leaf Item Long Heading' === FW_Site_Converter_Stitch::page_title_from_html( $html, 'Leaf Item' ),
	FW_Site_Converter_Stitch::page_title_from_html( $html, 'Leaf Item' )
);
$fha = '<html><head><title>FHA Loans | Brand</title></head><body></body></html>';
ht(
	'[H9] casing a slug would have destroyed is preserved',
	'FHA Loans' === FW_Site_Converter_Stitch::page_title_from_html( $fha, 'Fha' ),
	FW_Site_Converter_Stitch::page_title_from_html( $fha, 'Fha' )
);

/* NEGATIVE: a nav menu must NOT read as a breadcrumb. Its hrefs are siblings, not a nesting chain, and this
   is the way to get the fix wrong -- a rule loose enough to claim every row of links in a page. */
$menu = '<nav class="flex gap-6" data-sc-cs="' . $cs( 'display:flex;height:20px' ) . '">'
	. '<a href="/about" data-sc-cs="' . $cs( 'height:20px' ) . '">About</a>'
	. '<a href="/pricing" data-sc-cs="' . $cs( 'height:20px' ) . '">Pricing</a>'
	. '<a href="/contact" data-sc-cs="' . $cs( 'height:20px' ) . '">Contact</a>'
	. '<span data-sc-cs="' . $cs( 'height:20px' ) . '">Blog</span></nav>';
$res2  = FW_Site_Converter_Sources::build_from_html( $mk( $menu ), 'x', $opts );
$json2 = (string) wp_json_encode( $res2['files']['pages.json'] ?? array() );
ht(
	'[H10] NEGATIVE: a row of sibling links is not a breadcrumb',
	false === strpos( $json2, '[breadcrumbs]' ),
	'a plain nav row became a breadcrumb trail'
);

$pass = (int) $GLOBALS['__h_pass'];
$fail = (int) $GLOBALS['__h_fail'];
if ( 0 === $pass + $fail ) {
	echo "\nHIERARCHY RESULT: FAIL -- no assertions ran\n";
	exit( 1 );
}
echo "\n========================================\n";
echo 'HIERARCHY RESULT: ' . ( 0 === $fail ? 'PASS' : 'FAIL' ) . "   ($pass passed, $fail failed)\n";
echo "========================================\n";
exit( 0 === $fail ? 0 : 1 );
