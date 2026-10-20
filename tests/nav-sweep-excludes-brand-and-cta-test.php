<?php
/**
 * Regression guard: sweeping a header for menu items must not swallow the brand or the CTA.
 *
 * `parse_nav()` prefers the richest <ul> in the container and falls back to `flat_links()` when there is
 * none. A hand-written header has a <ul>; a React/Tailwind one often does not — its nav is a row of <a>
 * elements — so the fallback runs, and it took EVERY link in the header.
 *
 * Measured on a captured header, the menu came out as:
 *
 *     Apex Injury Advocates   (the LOGO, linking to /)
 *     About Us
 *     Costs
 *     Contact
 *     415-789-2200            (the phone CTA, href tel:…)
 *
 * so the converted header rendered the site title where the logo belongs, the brand text as the first menu
 * item, and the phone number twice — once in the menu and once as the button it already is. The source's own
 * menu is four items: About Us, Practice Areas, Costs, Contact.
 *
 * Two kinds of link are therefore not menu items, however they are marked up:
 *
 *   - the BRAND link: the header's first link to the site root, carrying the logo image or the site name.
 *     A nav item points somewhere; a brand points home.
 *   - a CTA: `tel:` / `mailto:`, or a link whose classes make it a button. The converter already carries the
 *     header CTA as a native button, so sweeping it in duplicates it.
 *
 * This only touches the no-<ul> fallback. A header with a real <ul> is parsed as before, because there the
 * markup already says which links are the menu.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/nav-sweep-excludes-brand-and-cta-test.php"
 *
 * Exit code 0 = all PASS, 1 = at least one FAIL.
 */

if ( ! class_exists( 'FW_Site_Converter_Menus' ) ) {
	fwrite( STDERR, "FAIL: site-converter menus not loaded\n" );
	exit( 1 );
}

$fails = 0;
$ok    = function ( $cond, $msg ) use ( &$fails ) {
	if ( $cond ) { echo "  \xe2\x9c\x93 $msg\n"; } else { $fails++; echo "  \xe2\x9c\x97 FAIL: $msg\n"; }
};

$labels_of = function ( $html ) {
	$menus = FW_Site_Converter_Menus::extract_menus( $html, 'https://example.test' );
	foreach ( (array) $menus as $m ) {
		if ( 'Primary' === ( $m['name'] ?? '' ) ) {
			return array_map( function ( $i ) { return (string) ( $i['label'] ?? '' ); }, (array) ( $m['items'] ?? array() ) );
		}
	}
	return array();
};

echo "\n== The measured shape: a header with no <ul>\n";

$html = '<!DOCTYPE html><html><body><header>'
	. '<a href="/" class="brand"><span>Apex Injury Advocates</span></a>'
	. '<a href="/about-us">About Us</a>'
	. '<a href="/costs">Costs</a>'
	. '<a href="/contact">Contact</a>'
	. '<a href="tel:4157892200" class="btn btn-primary">415-789-2200</a>'
	. '</header><main><h1>Page</h1></main></body></html>';
$got = $labels_of( $html );
$ok( ! empty( $got ), 'the header still yields a menu (got ' . count( $got ) . ' item(s))' );
$ok( ! in_array( 'Apex Injury Advocates', $got, true ), 'the brand link is not a menu item: ' . implode( ' | ', $got ) );
$ok( ! in_array( '415-789-2200', $got, true ), 'the phone CTA is not a menu item' );
$ok( in_array( 'About Us', $got, true ) && in_array( 'Costs', $got, true ) && in_array( 'Contact', $got, true ),
	'…and the real nav items all survive' );

echo "\n== NEGATIVE: a brand with an IMAGE is excluded the same way\n";

$img = str_replace( '<span>Apex Injury Advocates</span>', '<img src="/logo.svg" alt="Apex Injury Advocates">', $html );
$got2 = $labels_of( $img );
$ok( ! in_array( 'Apex Injury Advocates', $got2, true ), 'an image brand is not swept in either' );

echo "\n== NEGATIVE: a mailto CTA is a CTA too\n";

$mail = str_replace( 'tel:4157892200', 'mailto:hi@example.test', $html );
$got3 = $labels_of( $mail );
$ok( ! in_array( '415-789-2200', $got3, true ), 'a mailto button is not a menu item' );

echo "\n== NEGATIVE: a header WITH a <ul> is parsed exactly as before\n";

$ul = '<!DOCTYPE html><html><body><header>'
	. '<a href="/" class="brand">Apex Injury Advocates</a>'
	. '<nav><ul>'
	. '<li><a href="/about-us">About Us</a></li>'
	. '<li><a href="/practice-areas">Practice Areas</a><ul><li><a href="/medical">Medical</a></li></ul></li>'
	. '<li><a href="/costs">Costs</a></li>'
	. '</ul></nav>'
	. '<a href="tel:4157892200" class="btn">415-789-2200</a>'
	. '</header></body></html>';
$got4 = $labels_of( $ul );
$ok( in_array( 'Practice Areas', $got4, true ), 'a real <ul> menu keeps its dropdown parent' );
$ok( ! in_array( 'Apex Injury Advocates', $got4, true ), 'and the brand is still not in it' );

echo "\n== NEGATIVE: a nav link that merely points home is kept\n";

// Only the BRAND is excluded — a "Home" item is a genuine nav link, not the logo.
$home = '<!DOCTYPE html><html><body><header>'
	. '<a href="/" class="brand"><img src="/logo.svg" alt="Brand"></a>'
	. '<a href="/">Home</a><a href="/costs">Costs</a><a href="/contact">Contact</a>'
	. '</header></body></html>';
$got5 = $labels_of( $home );
$ok( in_array( 'Home', $got5, true ), 'a labelled Home link survives: ' . implode( ' | ', $got5 ) );

printf( "\n%s\n", $fails ? "{$fails} FAIL(S)" : 'ALL PASS' );
exit( $fails ? 1 : 0 );
