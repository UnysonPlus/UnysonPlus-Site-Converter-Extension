<?php
/**
 * Regression guard: a footer CONTACT column's rows stay separate rows.
 *
 * WHY THIS FILE EXISTS. A converted footer lost its phone number and its street address outright. Both
 * are in the source, both are captured, and both reached the stitch -- they were destroyed on the way
 * out. The contact column came back as:
 *
 *     rows  : [ { text: "hello@...", link: "mailto:..." }, { text: "Inquire Now", link: "/..." } ]
 *     items : [ "hello@... +1 212 555 0198 <street address>", "Inquire Now" ]
 *
 * -- three separate lines glued into one string, of which only the first survived as a row.
 *
 * HOW IT GOT THERE. The row scan DESCENDED into the column before reading rows, and only when the column
 * held exactly one non-heading child div. The source column is
 *
 *     <h4>CONTACT</h4>
 *     <div class="space-y-3">  <a mailto>  <a tel>  <div>address</div>  </div>
 *     <a href="/...">Inquire Now</a>
 *
 * which has TWO non-heading children, so the descent never ran. The wrapper was then read as a single
 * row, and a single row has a single text -- so `text_no_icons()` returned all three lines at once.
 *
 * WHY FLATTENING, NOT A DEEPER DESCENT. Descending is the wrong shape of answer: only one subtree can be
 * descended into, and a column may hold several row groups and loose rows side by side (this one holds a
 * group plus a CTA). The scan now walks the column and expands any ROW GROUP in place, preserving
 * document order.
 *
 * TELLING A GROUP FROM A ROW is the whole difficulty, because from outside they are identical -- both are
 * divs with an svg somewhere beneath. It is decided by the CHILDREN: a group's own children are each
 * row-like (lead with an icon, link tel:/mailto:, or are an `items-*` flex line); an ordinary row's
 * children are an svg and a span, which are not. The NEGATIVES below are the real content of this guard:
 * a single icon+text row must never be taken apart, or one contact line would scatter into several.
 *
 * The fixture is the real footer, reduced, with the source's own naming and contact details replaced.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/footer-contact-rows-are-not-one-row-test.php"
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
$dfc = $ref->getMethod( 'detect_footer_columns' );
$dfc->setAccessible( true );

/** The contact column of a footer, or null. */
$contact = function ( $html ) use ( $dfc ) {
	foreach ( (array) $dfc->invoke( null, $html ) as $c ) {
		if ( 'contact' === ( $c['kind'] ?? '' ) ) { return $c; }
	}
	return null;
};

echo "\n== The real footer's contact column keeps every row\n";

$fixture = __DIR__ . '/fixtures/footer-contact-rows-are-not-one-row.html';
if ( ! is_file( $fixture ) ) { fwrite( STDERR, "FAIL: fixture missing\n" ); exit( 1 ); }
$c = $contact( file_get_contents( $fixture ) );
$ok( null !== $c, 'the footer yields a contact column' );
if ( null === $c ) { printf( "\n%d FAIL(S)\n", ++$fails ); exit( 1 ); }

$rows = (array) $c['rows'];
$ok( count( $rows ) >= 4, sprintf( 'it has four rows, not two (got %d)', count( $rows ) ) );

$texts = array();
$links = array();
foreach ( $rows as $r ) {
	$texts[] = (string) ( $r['text'] ?? '' );
	$links[] = (string) ( $r['link'] ?? '' );
}
$blob = implode( ' | ', $texts );

echo "\n== ...the phone and the address are among them, each on its own row\n";

$one = function ( $needle ) use ( $texts ) {
	$n = 0;
	foreach ( $texts as $t ) { if ( false !== strpos( $t, $needle ) ) { $n++; } }
	return 1 === $n;
};
$ok( $one( '+1 555 010 0100' ), sprintf( 'the phone number is exactly one row (rows: %s)', $blob ) );
$ok( $one( 'Example Street' ), sprintf( 'the street address is exactly one row (rows: %s)', $blob ) );
$ok( $one( 'hello@example.com' ), 'the email is exactly one row' );

echo "\n== ...and nothing is glued: no row carries two of them\n";

// This is the defect stated precisely. Counting rows alone would pass on four rows that each held the
// whole blob; what was wrong is that ONE row carried three lines.
$glued = 0;
foreach ( $texts as $t ) {
	$hits = (int) ( false !== strpos( $t, '@' ) )
		+ (int) ( false !== strpos( $t, '+1 555' ) )
		+ (int) ( false !== strpos( $t, 'Example Street' ) );
	if ( $hits > 1 ) { $glued++; }
}
$ok( 0 === $glued, sprintf( 'no row holds more than one contact line (got %d glued)', $glued ) );

echo "\n== ...and each row keeps the link the source gave it\n";

$ok( in_array( 'tel:+15550100100', $links, true ), sprintf( 'the phone row keeps its tel: link (links: %s)', implode( ', ', $links ) ) );
$ok( in_array( 'mailto:hello@example.com', $links, true ), 'the email row keeps its mailto: link' );

echo "\n== The GROUP test itself: what may be taken apart, and what may not\n";

// Asserted on the rule directly rather than through a built column, because what must not misfire is one
// predicate, and routing it through `detect_footer_columns` would test the column CLASSIFIER instead --
// a lone plain-text address is not a contact column at all (no tel:/mailto:), so such a column answers a
// different question than the one this guard is about.
$frg = $ref->getMethod( 'footer_row_group' );
$frg->setAccessible( true );
$el  = function ( $html ) {
	$d = new DOMDocument();
	libxml_use_internal_errors( true );
	$d->loadHTML( '<?xml encoding="utf-8" ?><div id="r">' . $html . '</div>' );
	libxml_clear_errors();
	return $d->getElementById( 'r' )->firstChild;
};
$svg = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"><path d="M12 2v20"/></svg>';

// POSITIVE: the wrapper that holds three contact lines IS a group -- the case the fix turns on.
$group = '<div class="space-y-3">'
	. '<a href="mailto:hello@example.com" class="flex items-center gap-2">' . $svg . 'hello@example.com</a>'
	. '<a href="tel:+15550100100" class="flex items-center gap-2">' . $svg . '+1 555 010 0100</a>'
	. '<div class="flex items-center gap-2">' . $svg . '12 Example Street, Springfield</div>'
	. '</div>';
$ok( true === $frg->invoke( null, $el( $group ) ), 'a space-y wrapper of three contact lines is a row group' );

// NEGATIVE: an ordinary row is a flex line of an svg and a span. If that counted as a group, one address
// would scatter across two rows and lose its icon.
$ok( false === $frg->invoke( null, $el( '<div class="flex items-center gap-2" data-sc-cs="display:flex">' . $svg . '<span>12 Example Street, Springfield</span></div>' ) ),
	'a single icon+text row is NOT a group' );

// NEGATIVE: a wrapper holding ONE row stays a row, so a lone line is never split.
$ok( false === $frg->invoke( null, $el( '<div class="space-y-3"><a href="tel:+15550100100" class="flex items-center gap-2">' . $svg . '+1 555 010 0100</a></div>' ) ),
	'a wrapper around ONE row is NOT a group' );

// NEGATIVE: an <a> is one contact line however it is marked up inside -- several spans, an icon, a
// nested flex. Descending into one would split a phone number from its own icon.
$ok( false === $frg->invoke( null, $el( '<a href="tel:+15550100100" class="flex items-center gap-2">' . $svg . '<span class="items-center">+1 555</span><span class="items-center"> 010 0100</span></a>' ) ),
	'an <a> is never a group, even with row-like children' );

echo "\n== ...and an <a> row survives the real scan intact\n";

$a_col = '<!DOCTYPE html><html><body data-sc-cs="font-family:Inter, sans-serif">'
	. '<main><section data-sc-cs="padding:80px 0px"><h1 data-sc-cs="font-size:56px">Page</h1></section></main>'
	. '<footer data-sc-cs="padding:64px 0px;background-color:rgb(17, 17, 17)">'
	. '<div data-sc-cs="display:grid;grid-template-columns:300px 300px">'
	. '<div><h4 data-sc-cs="font-size:12px">CONTACT</h4><div class="space-y-3">'
	. '<a href="tel:+15550100100" class="flex items-center gap-2" data-sc-cs="display:flex">' . $svg . '<span>+1 555</span><span> 010 0100</span></a>'
	. '</div></div>'
	. '<div><h4 data-sc-cs="font-size:12px">LINKS</h4><a href="/a">One</a><a href="/b">Two</a><a href="/c">Three</a></div>'
	. '</div></footer></body></html>';
$ac = $contact( $a_col );
if ( null === $ac ) {
	$ok( false, 'the link-only contact column is still detected' );
} else {
	$arows = (array) $ac['rows'];
	$ok( 1 === count( $arows ), sprintf( 'a link with several inner spans stays ONE row (got %d)', count( $arows ) ) );
	$ok( 'tel:+15550100100' === (string) ( $arows[0]['link'] ?? '' ),
		sprintf( 'and keeps its tel: link (got %s)', wp_json_encode( $arows[0]['link'] ?? null ) ) );
}

printf( "\n%s\n", $fails ? "{$fails} FAIL(S)" : 'ALL PASS' );
exit( $fails ? 1 : 0 );
