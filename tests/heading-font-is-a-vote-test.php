<?php
/**
 * Regression guard: the site's heading face is the one MOST headings wear, not the first one found.
 *
 * `detect_computed_fonts()` reads the display face off the primary headings, deliberately ignoring h3+
 * (card and section titles are numerous and usually render in the BODY font, so pooling them lets the
 * body face outvote the real display face). That part is right and stays.
 *
 * What was wrong is that among h1/h2 it took the FIRST heading carrying a real family and stopped, so
 * the whole site's heading font was a sample of one — and one heading is routinely the odd one out.
 * Measured on a captured source: a single Roboto h1 (the hero) set `fonts.heading = Roboto` against ten
 * Inter h2s, while 27 of that page's 29 visible headings were Inter.
 *
 * The result was worse than a merely wrong font. The Google stylesheet is built from the fonts actually
 * in use, so Roboto was never requested; every card title then fell back to the system SERIF on a page
 * with no serif anywhere in it. Two bands of the section audit showed it plainly before anything in the
 * data did.
 *
 * So among h1/h2 it is a vote. A tie still goes to the h1 (h1s are counted first and the sort is stable),
 * which keeps the original intent: the display heading wins when the evidence is balanced, and loses only
 * to a clear majority.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/heading-font-is-a-vote-test.php"
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

$detect = new ReflectionMethod( 'FW_Site_Converter_Stitch', 'detect_computed_fonts' );
$detect->setAccessible( true );

// The capture stamps each element's COMPUTED style in `data-sc-cs`, which is what the detector reads.
$h = function ( $tag, $family, $text = 'Heading' ) {
	return '<' . $tag . ' data-sc-cs="font-family:' . $family . ', sans-serif;font-size:32px">' . $text . '</' . $tag . '>';
};
$page = function ( $inner, $body_family = 'Inter' ) {
	return '<!DOCTYPE html><html><body data-sc-cs="font-family:' . $body_family . ', sans-serif;font-size:18px">'
		. $inner . '</body></html>';
};
$heading_of = function ( $html ) use ( $detect ) {
	$r = (array) $detect->invoke( null, $html );
	return (string) ( $r[0] ?? '' );
};

echo "\n== The measured case: one odd h1 must not outvote the h2s\n";

// The shape of the real capture: a single Roboto hero h1, ten Inter h2s.
$inner = $h( 'h1', 'Roboto', 'We make it simple' );
for ( $i = 0; $i < 10; $i++ ) { $inner .= $h( 'h2', 'Inter', 'Section ' . $i ); }
$got = $heading_of( $page( $inner ) );
$ok( 'Inter' === $got, "ten Inter h2s beat one Roboto h1 (got '{$got}', was 'Roboto')" );

echo "\n== NEGATIVE: a real display face still wins when it is the majority\n";

// The case the h3-exclusion was written for: a serif display face on h1/h2, many Inter h3 card titles.
$inner = $h( 'h1', 'Playfair Display', 'Big statement' );
for ( $i = 0; $i < 4; $i++ ) { $inner .= $h( 'h2', 'Playfair Display', 'Section ' . $i ); }
for ( $i = 0; $i < 11; $i++ ) { $inner .= $h( 'h3', 'Inter', 'Card ' . $i ); }
$got = $heading_of( $page( $inner ) );
$ok( 'Playfair Display' === $got, "5 Playfair h1/h2s beat 11 Inter h3s — h3+ is still excluded (got '{$got}')" );

echo "\n== A TIE resolves to the h1, so the display heading keeps the benefit of the doubt\n";

$inner = $h( 'h1', 'Playfair Display', 'Big statement' ) . $h( 'h2', 'Inter', 'Section' );
$got = $heading_of( $page( $inner ) );
$ok( 'Playfair Display' === $got, "1 h1 vs 1 h2 resolves to the h1's face (got '{$got}')" );

echo "\n== A single heading is still enough when it is all there is\n";

$got = $heading_of( $page( $h( 'h1', 'Playfair Display', 'Only heading' ) ) );
$ok( 'Playfair Display' === $got, "a lone h1 sets the heading face (got '{$got}')" );

echo "\n== Generic keywords never become the heading face\n";

// `font-family: sans-serif` is not a family the theme can load or name.
$inner = '<h1 data-sc-cs="font-family:sans-serif;font-size:40px">Generic</h1>' . $h( 'h2', 'Inter', 'Real' );
$got = $heading_of( $page( $inner ) );
$ok( 'Inter' === $got, "a generic `sans-serif` h1 is skipped, the named h2 face wins (got '{$got}')" );

echo "\n== NEGATIVE: no headings at all leaves the heading face empty\n";

$got = $heading_of( $page( '<p data-sc-cs="font-family:Inter, sans-serif">Just prose</p>' ) );
$ok( '' === $got, "no h1/h2/h3 means no heading face is invented (got '{$got}')" );

echo "\n== The chosen face is one the page actually uses\n";

// Guards the failure mode that made this visible: a heading font nothing requested, so it fell back to a
// system serif. Whatever is returned must be a family that appears on a heading in the source.
$inner = $h( 'h1', 'Roboto', 'Hero' );
for ( $i = 0; $i < 10; $i++ ) { $inner .= $h( 'h2', 'Inter', 'Section ' . $i ); }
$html = $page( $inner );
$got  = $heading_of( $html );
$ok( '' !== $got && false !== strpos( $html, 'font-family:' . $got ), "the returned face '{$got}' is present on a heading in the source" );

printf( "\n%s\n", $fails ? "{$fails} FAIL(S)" : 'ALL PASS' );
exit( $fails ? 1 : 0 );
