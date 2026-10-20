<?php
/**
 * Regression guard: the footer's legal row is two LINKS, on the side the source puts them.
 *
 * WHY THIS FILE EXISTS. A converted footer's bottom bar rendered as:
 *
 *     © 2026 NAME. ALL RIGHTS RESERVED.            PRIVACY POLICYTERMS & CONDITIONS
 *
 * against a source that reads:
 *
 *     PRIVACY POLICY   TERMS & CONDITIONS                   © 2026 NAME. ALL RIGHTS RESERVED.
 *
 * Three things were wrong at once, and they had two causes.
 *
 * CAUSE 1 -- A LEGAL LINK NEED NOT BE AN <a>. `detect_footer_legal_links()` walked anchors only. An AI
 * builder routinely marks the legal row as SPANS made clickable by script:
 *
 *     <span class="cursor-pointer hover:text-gray-300">PRIVACY POLICY</span>
 *
 * There were no anchors, so the detector returned nothing, the legal branch never ran, and a LABEL-BAR
 * fallback further up swept the whole row into one escaped string -- which is where the run-together
 * `PRIVACY POLICYTERMS & CONDITIONS` came from. Two labels became one, with no separator, no gap, and no
 * links. The source shows them 24px apart.
 *
 * A leaf styled as a link now counts: `cursor:pointer` (from the class or the computed stamp) TOGETHER
 * with a hover colour, which is what makes a span read as a link to a visitor. It gets href '#' -- the
 * source gives no destination to carry, and the row's SHAPE is what was lost.
 *
 * The guard that had to change with it: the candidate loop rejected anything `band_is_copyright()` was
 * true of, which is every element in the bottom band -- the legal row IS that band. So the first attempt
 * at this fix still returned nothing. What must be excluded is the one element carrying the notice.
 *
 * CAUSE 2 -- WHICH SIDE EACH GROUP IS ON. The notice was always built as column one, so it always
 * rendered at the left edge. The bar is a `justify-between` row, so document order is left-to-right; a
 * source that leads with its legal links converted MIRRORED, both groups on the wrong side of a 1184px
 * row. The order is now read from the band, and `text-end` travels with whichever group ends up on the
 * right -- it is what pushes that group to the row's outer edge, so left behind it would right-align the
 * left group instead.
 *
 * After both: the three x positions match the source exactly (128 / 240 / 1033 at 1440px).
 *
 * The fixture is the real footer, reduced, with the source's own naming and contact details replaced.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/footer-legal-row-is-two-links-test.php"
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
$dll  = $ref->getMethod( 'detect_footer_legal_links' );        $dll->setAccessible( true );
$prec = $ref->getMethod( 'footer_legal_precedes_copyright' );  $prec->setAccessible( true );
$pt   = $ref->getMethod( 'parse_tokens' );                     $pt->setAccessible( true );

$fixture = __DIR__ . '/fixtures/footer-contact-rows-are-not-one-row.html';
if ( ! is_file( $fixture ) ) { fwrite( STDERR, "FAIL: fixture missing\n" ); exit( 1 ); }
$html = file_get_contents( $fixture );

echo "\n== The clickable spans are found as the two legal links\n";

$legal = (array) $dll->invoke( null, $html );
$ok( 2 === count( $legal ), sprintf( 'two legal links, not none (got %d)', count( $legal ) ) );
$labels = array();
foreach ( $legal as $l ) { $labels[] = (string) $l['label']; }
$ok( in_array( 'PRIVACY POLICY', $labels, true ) && in_array( 'TERMS & CONDITIONS', $labels, true ),
	sprintf( 'and they are the source\'s two labels (got %s)', wp_json_encode( $labels ) ) );
$ok( 'PRIVACY POLICY' === ( $labels[0] ?? '' ), 'in the source\'s own order' );

echo "\n== ...and the source leads with them, not with the notice\n";

$ok( true === $prec->invoke( null, $html ), 'the legal group is read as preceding the copyright' );

echo "\n== The emitted bar: two anchors, the source's gap, the source's sides\n";

$c  = FW_Site_Converter_Stitch::tokens_to_theme_settings_chrome( (array) $pt->invoke( null, $html ), $html, 'T' );
$cc = (array) ( $c['values']['copyright_settings']['yes']['copyright_columns']['2'] ?? array() );
$col = function ( $k ) use ( $cc ) {
	$t = '';
	foreach ( (array) ( $cc[ $k ] ?? array() ) as $el ) { $t .= (string) ( $el['element_type']['text']['text_content'] ?? '' ); }
	return $t;
};
$c1 = $col( 'copyright_col_1' );
$c2 = $col( 'copyright_col_2' );

$ok( 2 === preg_match_all( '/<a\s[^>]*href=/i', $c1 ), sprintf( 'column one holds TWO anchors (got %s)', wp_json_encode( substr( $c1, 0, 120 ) ) ) );
$ok( false === strpos( $c1, 'POLICYTERMS' ), 'and the labels are not run together' );
$ok( (bool) preg_match( '/gap:\s*24px/', $c1 ), sprintf( 'with the source\'s own 24px gap (got %s)', wp_json_encode( substr( $c1, 0, 90 ) ) ) );
$ok( (bool) preg_match( '/\x{00A9}|&copy;|all rights reserved/iu', $c2 ), sprintf( 'and the NOTICE is column two, on the right (got %s)', wp_json_encode( substr( $c2, 0, 80 ) ) ) );

echo "\n== ...and text-end moved with the group that is now on the right\n";

$cls_of = function ( $k ) use ( $cc ) {
	$out = array();
	foreach ( (array) ( $cc[ $k ] ?? array() ) as $el ) { $out[] = (string) ( $el['element_css_class'] ?? '' ); }
	return $out;
};
$ok( ! in_array( 'text-end', $cls_of( 'copyright_col_1' ), true ), 'the left column is not right-aligned' );
$ok( in_array( 'text-end', $cls_of( 'copyright_col_2' ), true ), 'and the right column is' );

echo "\n== NEGATIVE: a notice-first source keeps the notice on the left\n";

// The previous order must survive. Most footers DO lead with the notice, and if the swap fired for them
// too this fix would simply have mirrored a different set of sites.
$svg   = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"><path d="M12 2v20"/></svg>';
$first = '<!DOCTYPE html><html><body data-sc-cs="font-family:Inter, sans-serif">'
	. '<main><section data-sc-cs="padding:80px 0px"><h1 data-sc-cs="font-size:56px">Page</h1></section></main>'
	. '<footer data-sc-cs="padding:64px 0px;background-color:rgb(17, 17, 17)">'
	. '<div data-sc-cs="display:grid;grid-template-columns:300px 300px">'
	. '<div><h4 data-sc-cs="font-size:12px">CONTACT</h4><div class="space-y-3">'
	. '<a href="mailto:hello@example.com" class="flex items-center gap-2">' . $svg . 'hello@example.com</a>'
	. '<a href="tel:+15550100100" class="flex items-center gap-2">' . $svg . '+1 555 010 0100</a></div></div>'
	. '<div><h4 data-sc-cs="font-size:12px">LINKS</h4><a href="/a">One</a><a href="/b">Two</a><a href="/c">Three</a></div>'
	. '</div>'
	. '<div class="pt-8 flex items-center justify-between" data-sc-cs="display:flex;justify-content:space-between">'
	. '<div>&copy; 2026 EXAMPLE. ALL RIGHTS RESERVED.</div>'
	. '<div class="flex items-center gap-6" data-sc-cs="display:flex;column-gap:24px">'
	. '<a href="/privacy">PRIVACY POLICY</a><a href="/terms">TERMS &amp; CONDITIONS</a></div>'
	. '</div></footer></body></html>';
$ok( false === $prec->invoke( null, $first ), 'a notice-first bar is not swapped' );
$nl = (array) $dll->invoke( null, $first );
$ok( 2 === count( $nl ), sprintf( 'and its real anchors are still found (got %d)', count( $nl ) ) );

echo "\n== NEGATIVE: cursor-pointer alone is not a link\n";

// `cursor-pointer` also sits on a disclosure toggle and a carousel dot. A hover COLOUR is what a legal
// link has and those do not; without that test the bottom bar would collect stray controls.
$el = function ( $h ) {
	$d = new DOMDocument();
	libxml_use_internal_errors( true );
	$d->loadHTML( '<?xml encoding="utf-8" ?><div id="r">' . $h . '</div>' );
	libxml_clear_errors();
	return $d->getElementById( 'r' )->firstChild;
};
$toggle = str_replace(
	'<a href="/privacy">PRIVACY POLICY</a><a href="/terms">TERMS &amp; CONDITIONS</a>',
	'<span class="cursor-pointer">Toggle</span><span class="cursor-pointer">Dot</span>',
	$first
);
$tl = (array) $dll->invoke( null, $toggle );
$ok( 0 === count( $tl ), sprintf( 'pointer-only controls are not collected as legal links (got %s)', wp_json_encode( $tl ) ) );

printf( "\n%s\n", $fails ? "{$fails} FAIL(S)" : 'ALL PASS' );
exit( $fails ? 1 : 0 );
