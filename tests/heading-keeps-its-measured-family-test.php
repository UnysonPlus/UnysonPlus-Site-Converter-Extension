<?php
/**
 * Regression guard: a special_heading keeps the SOURCE's measured font-family.
 *
 * WHY THIS FILE EXISTS. Measured on a real conversion, three separate bands rendered their titles in the
 * site's DISPLAY serif where the source used the body sans:
 *
 *   source  Inter, system-ui, -apple-system, sans-serif
 *   build   Cinzel, "Playfair Display", serif
 *
 * Size, weight and line-height all matched — only the family was wrong, which is why no size-based lens
 * caught it and why it reads at a glance as "the card titles look odd".
 *
 * THE TELL: the same page's icon_box titles were CORRECT. `n_icon_box()` already carries the measured family;
 * `heading_weight_css()` carried weight, italic and (for the overline/subtitle) size — and never the family.
 * One question answered in one place and not its sibling, which is the recurring root cause.
 *
 * IT NEEDS MORE THAN !important. The generated child theme emits
 *
 *   :is(h1..h6):not(.hf-heading):not(.elementor-heading-title){font-family:<display face> !important}
 *
 * at specificity (0,2,1), which beats a scoped `.uXXXX .heading-title` (0,2,0) even important-vs-important.
 * So the family goes out at (0,3,0) by repeating the part's own class — exactly as the icon_box fix does.
 *
 * The fixture is a reduced real capture whose headings are stamped with the source's sans.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/heading-keeps-its-measured-family-test.php"
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

// The band that showed the defect: a card whose TITLE is stamped with the source's body sans, while the
// generated theme would paint every hN in its display serif. Reduced from the real capture to 1.5 KB.
$fixture = __DIR__ . '/fixtures/card-title-sans-stamped.html';
if ( ! is_file( $fixture ) ) { fwrite( STDERR, "FAIL: fixture missing\n" ); exit( 1 ); }

$map  = FW_Site_Converter_Stitch::html_to_mapping( file_get_contents( $fixture ), 'Services', 'services', true );
$blob = wp_json_encode( FW_Site_Converter_Mapper::build_pages( $map ) );

echo "\n== The measured family reaches the page\n";

$ok( false !== strpos( $blob, 'heading-title' ), 'the page emits heading parts at all' );
$ok( (bool) preg_match( '/\.heading-title\.heading-title\s*\{[^}]*font-family:/i', $blob ),
	'the title carries a font-family rule' );

echo "\n== ...at a specificity that beats the generated theme\n";

// (0,2,0) loses to the theme's :is(h1..h6):not(...):not(...) at (0,2,1) even important-vs-important, so the
// doubled class is the whole point of the rule and is asserted on its own.
$ok( (bool) preg_match( '/selector\s+\.heading-title\.heading-title\s*\{/i', $blob ),
	'the selector repeats the class, for (0,3,0)' );
$ok( (bool) preg_match( '/\.heading-title\.heading-title\s*\{[^}]*font-family:[^;}]*!important/i', $blob ),
	'and the declaration is !important' );

echo "\n== ...and it is the SOURCE's family, not the theme's\n";

$ok( (bool) preg_match( '/\.heading-title\.heading-title\s*\{[^}]*font-family:\s*Inter/i', $blob ),
	'the family is the source\'s sans (Inter), not a display serif' );
$ok( ! preg_match( '/\.heading-title\.heading-title\s*\{[^}]*font-family:[^;}]*(Cinzel|Playfair)/i', $blob ),
	'no display serif is substituted for it' );

// …and the rule TRACKS the stamp rather than emitting a constant. A title the source really does set in its
// display serif must come out serif. Same code path, opposite input — without this, a naive "always emit the
// body font" would satisfy every assertion above and silently flatten every display heading on the site.
$serif_fx = __DIR__ . '/fixtures/golden-fixture-5-decomposed-stack.html';
if ( is_file( $serif_fx ) ) {
	$smap  = FW_Site_Converter_Stitch::html_to_mapping( file_get_contents( $serif_fx ), 'Pricing', 'pricing', true );
	$sblob = wp_json_encode( FW_Site_Converter_Mapper::build_pages( $smap ) );
	$ok( (bool) preg_match( '/\.heading-title\.heading-title\s*\{[^}]*font-family:[^;}]*Cinzel/i', $sblob ),
		'a genuinely-serif source title stays serif (the rule follows the stamp)' );
}

echo "\n== NEGATIVE: nothing is invented where the source stamped no family\n";

// A heading with no measured family must not acquire one -- that would freeze the theme's own face into the
// page and defeat a later Theme Settings change.
$bare = '<!DOCTYPE html><html><body><main>'
	. '<section data-sc-cs="background-color:rgb(255,255,255);padding:80px 0px;display:block">'
	. '<h2 data-sc-cs="font-size:36px;line-height:40px;font-weight:700">A Heading With No Family Stamp</h2>'
	. '<p data-sc-cs="font-size:16px;line-height:24px">Body copy beneath it.</p>'
	. '</section></main></body></html>';
$bmap  = FW_Site_Converter_Stitch::html_to_mapping( $bare, 'B', 'b', true );
$bblob = wp_json_encode( FW_Site_Converter_Mapper::build_pages( $bmap ) );
$ok( ! preg_match( '/\.heading-title\.heading-title\s*\{[^}]*font-family:/i', $bblob ),
	'an unstamped heading gets no font-family rule' );
$ok( false !== strpos( $bblob, 'A Heading With No Family Stamp' ), 'but the heading itself still converts' );

printf( "\n%s\n", $fails ? "{$fails} FAIL(S)" : 'ALL PASS' );
exit( $fails ? 1 : 0 );
