<?php
/**
 * Regression guard: a dropdown TRIGGER is a menu item, even when it is a <button>.
 *
 * The nav sweep walks <a> elements. A React/Tailwind header renders a submenu parent as a <button> inside a
 * positioning wrapper — `<div class="relative"><button>Practice Areas</button><!-- panel --></div>` — and the
 * panel itself is not in the DOM until the user opens it.
 *
 * Measured on a captured header, the source nav reads
 *
 *     About Us | Practice Areas | Costs | Contact
 *
 * and the conversion produced three items, silently dropping Practice Areas. The wrapper holds 0 links, so
 * there was nothing for an anchor-only sweep to find.
 *
 * So a <button> inside the nav counts as an item, and ORDER is preserved — Practice Areas belongs between
 * About Us and Costs, which means walking anchors and buttons together in document order rather than
 * appending one set after the other.
 *
 * What this deliberately does NOT do: invent the submenu. The panel's links are not in the capture, and the
 * practice-area PAGES that exist in the bundle are not evidence that the dropdown pointed at them. The
 * trigger is carried as the source shows it — a parent with no measured children — rather than guessed at.
 *
 * Nor does it sweep up the controls that are not navigation: a hamburger, a search or a close button carry
 * labels like "Menu" or "Toggle navigation", or no text at all.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/nav-keeps-dropdown-triggers-test.php"
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
	foreach ( (array) FW_Site_Converter_Menus::extract_menus( $html, 'https://example.test' ) as $m ) {
		if ( 'Primary' === ( $m['name'] ?? '' ) ) {
			return array_map( function ( $i ) { return (string) ( $i['label'] ?? '' ); }, (array) ( $m['items'] ?? array() ) );
		}
	}
	return array();
};

$header = function ( $nav_inner ) {
	return '<!DOCTYPE html><html><body><header>'
		. '<a href="/" class="animate-logo">Apex Injury Advocates</a>'
		. '<nav class="hidden lg:flex">' . $nav_inner . '</nav>'
		. '<a href="tel:4157892200" class="btn">415-789-2200</a>'
		. '</header></body></html>';
};

echo "\n== The measured shape: a <button> dropdown trigger between two links\n";

// A trigger whose panel WAS captured is a real parent; one with no panel is a dead end (see below).
$got = $labels_of( $header(
	'<a href="/about-us">About Us</a>'
	. '<div class="relative"><button class="font-medium">Practice Areas<svg viewBox="0 0 24 24"><path d="M6 9l6 6 6-6"></path></svg></button>'
	. '<div class="panel"><a href="/medical-malpractice">Medical Malpractice</a></div></div>'
	. '<a href="/costs">Costs</a><a href="/contact">Contact</a>'
) );
$ok( in_array( 'Practice Areas', $got, true ), 'the dropdown trigger is a menu item: ' . implode( ' | ', $got ) );
$ok( in_array( 'Medical Malpractice', $got, true ), 'and its captured panel link survives too' );

echo "\n== …in DOCUMENT ORDER, not appended after the links\n";

$ok( array( 'About Us', 'Practice Areas', 'Medical Malpractice', 'Costs', 'Contact' ) === $got,
	'order matches the source: ' . implode( ' | ', $got ) );

echo "\n== NEGATIVE: a trigger with NO captured panel is a dead end\n";

// This is the measured real-world case: a React dropdown renders its panel only on interaction, so the
// wrapper holds 0 links at capture time. Keeping the parent would add a menu item that goes nowhere, so it
// is dropped — the same rule the capture service applies (capture-extract.mjs, "A DEAD END is not a
// destination"). The honest fix for the missing item is to OPEN the dropdown during capture, not to invent
// a parent here.
$dead = $labels_of( $header(
	'<a href="/about-us">About Us</a>'
	. '<div class="relative"><button class="font-medium">Practice Areas<svg viewBox="0 0 24 24"><path d="M6 9l6 6 6-6"></path></svg></button></div>'
	. '<a href="/costs">Costs</a><a href="/contact">Contact</a>'
) );
$ok( ! in_array( 'Practice Areas', $dead, true ), 'a panel-less trigger is not a menu item: ' . implode( ' | ', $dead ) );
$ok( array( 'About Us', 'Costs', 'Contact' ) === $dead, 'and the rest of the nav is unaffected' );

echo "\n== NEGATIVE: controls that are not navigation stay out\n";

$ctrl = $labels_of( $header(
	'<a href="/about-us">About Us</a>'
	. '<button aria-label="Toggle navigation">Menu</button>'
	. '<button class="search"></button>'
	. '<a href="/costs">Costs</a>'
) );
$ok( ! in_array( 'Menu', $ctrl, true ), 'a hamburger labelled "Menu" is not an item: ' . implode( ' | ', $ctrl ) );
$ok( 2 === count( $ctrl ), 'only the two real links remain' );

echo "\n== NEGATIVE: the brand and CTA exclusions still hold\n";

$ok( ! in_array( 'Apex Injury Advocates', $got, true ), 'the brand is still excluded' );
$ok( ! in_array( '415-789-2200', $got, true ), 'the CTA is still excluded' );

echo "\n== NEGATIVE: a real <ul> menu is untouched by any of this\n";

$ul = '<!DOCTYPE html><html><body><header><nav><ul>'
	. '<li><a href="/about-us">About Us</a></li>'
	. '<li><a href="/costs">Costs</a></li>'
	. '</ul></nav></header></body></html>';
$ok( array( 'About Us', 'Costs' ) === $labels_of( $ul ), 'a <ul> menu parses exactly as before' );

printf( "\n%s\n", $fails ? "{$fails} FAIL(S)" : 'ALL PASS' );
exit( $fails ? 1 : 0 );
