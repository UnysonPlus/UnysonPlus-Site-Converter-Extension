<?php
/**
 * Regression guard: a heading|link row that also carries PROSE is not claimed by the heading_cta
 * recognizer, because that widget cannot carry prose.
 *
 * WHY THIS FILE EXISTS. Two bands on one converted page lost their paragraph outright — the largest
 * and third-largest band drifts on the page (36.9% and 32.9%). Both are the same shape: a section
 * header or CTA band laid out as two columns, a heading on one side and, on the other, a line of copy
 * ABOVE its link:
 *
 *     <div class="flex justify-between">
 *       <div><h2>WHERE WILL YOUR TRIP TAKE YOU?</h2></div>
 *       <div><p>Whether you seek adventure…</p><a>PLAN YOUR TRIP</a></div>
 *     </div>
 *
 * `is_heading_cta_row()` matched: a flex/justify-between row, exactly two children, exactly one
 * heading side, exactly one link on the other. `heading_cta_row_build()` then emits a heading and a
 * link AND NOTHING ELSE, so the paragraph went nowhere — and the two-column layout flattened with it,
 * which is why the converted heading sat on one line with its button beside it.
 *
 * THE RULE, which is the point of this guard and is not specific to this widget:
 *
 *     A RECOGNIZER MUST NOT CLAIM A CONTAINER WHOSE CONTENT IT CANNOT CARRY.
 *
 * The recognizer already said exactly this about IMAGES — it declines a row containing a photo,
 * because claiming one drops it. The same was simply never said about text. Declining costs a
 * heading_cta: the generic columns path then keeps every part, laid out generically. Claiming costs
 * the paragraph. Generic and complete beats tailored and lossy.
 *
 * MEASURED ON THE CLONE, not by subtracting the link's text from the side's text: a label that also
 * appears inside the prose would subtract twice and hide real leftover text. A couple of characters
 * are tolerated so a stray arrow or bullet beside the link does not veto an otherwise clean row.
 *
 * THE DEFECT WAS ALREADY REPORTED AND NOT ACTED ON. Both paragraphs appeared in the text-coverage
 * audit's missing list for this page. The instrument worked; the finding sat unread. The positive
 * case below therefore asserts the recovered TEXT, not merely that the recognizer declined.
 *
 * The fixture is the real band, reduced, with the source's own naming replaced.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/heading-cta-keeps-its-standfirst-test.php"
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

$ref = new ReflectionClass( 'FW_Site_Converter_Stitch' );
$isr = $ref->getMethod( 'is_heading_cta_row' );
$isr->setAccessible( true );

$built = function ( $html ) {
	FW_Site_Converter_Mapper::set_style_config( array() );
	return (string) wp_json_encode( FW_Site_Converter_Mapper::build_pages(
		FW_Site_Converter_Stitch::html_to_mapping( $html, 'C', 'c', true ) ) );
};
/** The first element matching a selector-ish class, for the unit assertions. */
$row_of = function ( $html, $needle ) {
	$d = new DOMDocument();
	libxml_use_internal_errors( true );
	$d->loadHTML( '<?xml encoding="utf-8" ?>' . $html );
	libxml_clear_errors();
	foreach ( $d->getElementsByTagName( 'div' ) as $e ) {
		$cs = (string) $e->getAttribute( 'data-sc-cs' );
		if ( false === strpos( $cs, 'space-between' ) ) { continue; }
		if ( false === strpos( $e->textContent, $needle ) ) { continue; }
		return $e;
	}
	return null;
};

echo "\n== The real band keeps its paragraph\n";

$fixture = __DIR__ . '/fixtures/heading-cta-keeps-its-standfirst.html';
if ( ! is_file( $fixture ) ) { fwrite( STDERR, "FAIL: fixture missing\n" ); exit( 1 ); }
$html = file_get_contents( $fixture );
$out  = $built( $html );

$ok( false !== strpos( $out, 'Whether you seek adventure' ),
	'the standfirst reaches the built page' );
$ok( false !== stripos( $out, 'PLAN YOUR TRIP' ),
	'and so does the button beside it' );
$ok( false !== stripos( $out, 'WHERE WILL YOUR TRIP TAKE YOU' ),
	'and the heading, which was never the part at risk' );

echo "\n== …and the recognizer declined, which is why\n";

$row = $row_of( $html, 'Whether you seek adventure' );
$ok( null !== $row, 'the justify-between row is found in the fixture' );
$ok( null !== $row && false === $isr->invoke( null, $row ),
	'is_heading_cta_row() declines a row whose link side also holds prose' );

echo "\n== POSITIVE: a bare heading|link row is still claimed\n";

// The widget must keep working, or this guard would simply have deleted a recognizer. A section
// header with nothing but a heading and a "View all" link is exactly what it is for.
$bare = '<!DOCTYPE html><html><body data-sc-cs="font-family:Inter, sans-serif"><main>'
	. '<section data-sc-cs="padding:80px 0px;display:block">'
	. '<div data-sc-cs="display:flex;justify-content:space-between;align-items:center">'
	. '<div><h2 data-sc-cs="font-size:40px;font-weight:700">Latest Work</h2></div>'
	. '<div><a href="/work" data-sc-cs="font-size:13px">VIEW ALL</a></div>'
	. '</div></section></main></body></html>';
$brow = $row_of( $bare, 'VIEW ALL' );
$ok( null !== $brow && true === $isr->invoke( null, $brow ),
	'a heading beside a lone link is still a heading_cta row' );

echo "\n== POSITIVE: the link side may be the <a> ITSELF\n";

// When the side IS the anchor there is nowhere for prose to hide, so the check must not run and
// must not reject it by measuring the link's own label as leftover text.
$bare_a = str_replace(
	'<div><a href="/work" data-sc-cs="font-size:13px">VIEW ALL</a></div>',
	'<a href="/work" data-sc-cs="font-size:13px">VIEW ALL</a>',
	$bare
);
$arow = $row_of( $bare_a, 'VIEW ALL' );
$ok( null !== $arow && true === $isr->invoke( null, $arow ),
	'a bare <a> side is claimed, not measured as leftover prose' );

echo "\n== POSITIVE: a stray glyph beside the link does not veto the row\n";

// A source often sets an arrow as its own span next to the label. Rejecting on one or two characters
// would decline most real section headers and hand them all to the generic path.
$arrow = str_replace(
	'<div><a href="/work" data-sc-cs="font-size:13px">VIEW ALL</a></div>',
	'<div><a href="/work" data-sc-cs="font-size:13px">VIEW ALL</a><span>&rarr;</span></div>',
	$bare
);
$rrow = $row_of( $arrow, 'VIEW ALL' );
$ok( null !== $rrow && true === $isr->invoke( null, $rrow ),
	'a one-glyph arrow beside the link is tolerated' );

echo "\n== NEGATIVE: a real sentence beside the link is not\n";

$prose = str_replace(
	'<div><a href="/work" data-sc-cs="font-size:13px">VIEW ALL</a></div>',
	'<div><p data-sc-cs="font-size:14px">A short line of copy that introduces the link.</p>'
	. '<a href="/work" data-sc-cs="font-size:13px">VIEW ALL</a></div>',
	$bare
);
$prow = $row_of( $prose, 'VIEW ALL' );
$ok( null !== $prow && false === $isr->invoke( null, $prow ),
	'a sentence beside the link makes the row decline' );
$ok( false !== strpos( $built( $prose ), 'A short line of copy' ),
	'…and that sentence then reaches the built page' );

echo "\n== NEGATIVE: a label repeated inside the prose is not subtracted twice\n";

// Why the clone is used instead of subtracting the link's text. Here the prose contains the label
// "VIEW ALL" itself; a naive subtraction would remove it from the prose too and could leave little
// enough behind to look like a clean row.
$echoed = str_replace(
	'<div><a href="/work" data-sc-cs="font-size:13px">VIEW ALL</a></div>',
	'<div><p data-sc-cs="font-size:14px">VIEW ALL</p>'
	. '<a href="/work" data-sc-cs="font-size:13px">VIEW ALL</a></div>',
	$bare
);
$erow = $row_of( $echoed, 'VIEW ALL' );
$ok( null !== $erow && false === $isr->invoke( null, $erow ),
	'prose echoing the link label still counts as leftover text' );

printf( "\n%s\n", $fails ? "{$fails} FAIL(S)" : 'ALL PASS' );
exit( $fails ? 1 : 0 );
