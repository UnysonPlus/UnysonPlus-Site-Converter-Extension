<?php
/**
 * Guard for the two instruments that decide whether a conversion LOST anything:
 *
 *   1. toggle_title()          — one title derivation for all three accordion shapes.
 *   2. build_text_coverage()   — every visible source phrase, checked for presence in what we built.
 *
 * WHY THIS FILE EXISTS. A real conversion reported "seven missing items" in a services band. Six of the
 * seven were not missing at all: the accordion carried them, but their titles had been glued to the
 * ordinal and the +/- toggle glyph ("01Complete home renovations-"), so nothing looking for the source's
 * own phrases could find them. The seventh was a genuine loss that the drop report never mentioned,
 * because a node dropped by a RECOGNIZER claiming its ancestor never reaches the walker that feeds that
 * report. One defect was cosmetic and one was content loss, and the instruments could not tell them apart.
 *
 * So the assertions below pin both halves, and — more importantly — they pin the NEGATIVES, because every
 * bug in this area so far has been an over-broad match rather than a missed one:
 *   - a genuinely inline element must not gain a separator,
 *   - a consent banner must be reported as out-of-scope rather than as a loss,
 *   - and a CMP class on <body> must NOT make the whole page out-of-scope (it did, on the first run:
 *     coverage read 100% with zero findings while a figcaption and a footer email were plainly gone).
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs --allow-root eval-file \
 *     "D:/Web Dev/unysonplus/framework/extensions/site-converter/tests/text-coverage-test.php"
 *
 * Exit code 0 = all PASS, 1 = at least one FAIL (CI-friendly).
 */

if ( ! class_exists( 'FW_Site_Converter_Sources' ) ) {
	fwrite( STDERR, "Site Converter not loaded — activate the extension first.\n" );
	exit( 1 );
}

$fails = 0;
$ok    = function ( $cond, $msg ) use ( &$fails ) {
	echo ( $cond ? '  ✓ ' : '  ✗ FAIL ' ) . $msg . "\n";
	if ( ! $cond ) { $fails++; }
};

/** Build a page from a body fragment and hand back both the pages JSON and the coverage report. */
$build = function ( $body_html, $body_attr = '' ) {
	$html = '<!doctype html><html><head><title>Coverage</title></head><body ' . $body_attr . '>'
		. $body_html . '</body></html>';
	$res   = FW_Site_Converter_Sources::build_from_html( $html, 'Coverage', array( 'source_url' => 'https://example.test/' ) );
	$files = ( is_array( $res ) && isset( $res['files'] ) ) ? $res['files'] : array();
	return array(
		'pages'    => wp_json_encode( isset( $files['pages.json'] ) ? $files['pages.json'] : array() ),
		'coverage' => isset( $files['conversion-drops.json']['text_coverage'] ) ? $files['conversion-drops.json']['text_coverage'] : array(),
	);
};

/* ------------------------------------------------- 1. accordion titles -- */
echo "\n=== ACCORDION: one title derivation, whichever shape the source used ===\n";

// The aria-expanded shape, exactly as the real source wrote it: an ordinal span, a heading, and a
// decorative aria-hidden toggle glyph, all inside one button.
$aria = '<section><div class="service-list">'
	. '<div class="service-row"><button aria-expanded="true" aria-controls="s0">'
	. '<span>01</span><h3>Complete home renovations</h3><span aria-hidden="true">&#8722;</span></button>'
	. '<div id="s0"><p>A coherent plan for the whole home, coordinated around your priorities.</p></div></div>'
	. '<div class="service-row"><button aria-expanded="false" aria-controls="s1">'
	. '<span>02</span><h3>Luxury kitchens and bathrooms</h3><span aria-hidden="true">+</span></button>'
	. '<div id="s1"><p>Beautifully practical spaces, shaped around the way you cook and unwind.</p></div></div>'
	. '</div></section>';
$r = $build( $aria );
$ok( false !== strpos( $r['pages'], 'Complete home renovations' ),
	'the toggle heading survives as its own phrase (not glued to the ordinal)' );
$ok( false === strpos( $r['pages'], '01Complete' ),
	'NEGATIVE: the ordinal is not glued to the heading' );
$ok( false === strpos( $r['pages'], 'renovations\u2212' ) && false === strpos( $r['pages'], 'renovations-' ),
	'the decorative aria-hidden toggle glyph is not part of the title' );
$ok( false !== strpos( $r['pages'], '01 Complete home renovations' ),
	'the ordinal is KEPT, separated — it is text the source renders, so dropping it would be loss' );

// The <details> shape must reach the same derivation — it took raw text before.
$det = '<section><div>'
	. '<details open><summary><span>01</span><h3>Scope and budget</h3><span aria-hidden="true">+</span></summary>'
	. '<p>We work through the proposed changes and the level of finish with you.</p></details>'
	. '<details><summary><span>02</span><h3>Project timing</h3><span aria-hidden="true">+</span></summary>'
	. '<p>Timing depends on the condition of the property and on specialist availability.</p></details>'
	. '</div></section>';
$r2 = $build( $det );
$ok( false !== strpos( $r2['pages'], 'Scope and budget' ) && false === strpos( $r2['pages'], '01Scope' ),
	'the <details> branch derives its title the same way' );

/* ------------------------------------------------- 2. coverage audit ----- */
echo "\n=== COVERAGE: what the drop log structurally cannot see ===\n";

$r3  = $build( $aria );
$cov = $r3['coverage'];
$ok( isset( $cov['checked'] ) && $cov['checked'] > 0, 'the audit ran and checked phrases' );
$ok( isset( $cov['coverage_pct'] ), 'coverage_pct reported' );

// A phrase in a source the converter has no place to put must be REPORTED, not silently absent. A
// figure caption beside a claimed accordion is the exact shape that went missing unreported.
$withfig = '<section><div class="services-layout"><div class="service-list">'
	. '<div class="service-row"><button aria-expanded="true" aria-controls="t0">'
	. '<span>01</span><h3>Complete home renovations</h3><span aria-hidden="true">+</span></button>'
	. '<div id="t0"><p>A coherent plan for the whole home, coordinated around your priorities.</p></div></div>'
	. '<div class="service-row"><button aria-expanded="false" aria-controls="t1">'
	. '<span>02</span><h3>Luxury kitchens and bathrooms</h3><span aria-hidden="true">+</span></button>'
	. '<div id="t1"><p>Beautifully practical spaces, shaped around the way you cook.</p></div></div>'
	. '</div><figure class="service-preview"><figcaption>Considered spaces. Thoughtful details.</figcaption></figure>'
	. '</div></section>';
$r4       = $build( $withfig );
$carried  = false !== strpos( (string) $r4['pages'], 'Considered spaces' );
$reported = false !== strpos( wp_json_encode( isset( $r4['coverage']['items'] ) ? $r4['coverage']['items'] : array() ), 'Considered spaces' );
$ok( $carried || $reported,
	'a caption beside a claimed container is either CARRIED or REPORTED — never both absent and unmentioned'
	. ( $carried ? ' [carried]' : ' [reported]' ) );

/* ------------------------------------------------- 3. out-of-scope ------- */
echo "\n=== OUT OF SCOPE: reported as a choice, not counted as a loss ===\n";

$consent = '<main><section><h1>Homepage heading</h1>'
	. '<p>Body copy that the conversion is expected to carry across intact.</p></section></main>'
	. '<div id="cmplz-cookiebanner-container"><div class="cmplz-title">Your privacy, your choice</div>'
	. '<button class="cmplz-btn cmplz-accept">Accept all</button></div>';
$c5 = $build( $consent )['coverage'];
$oos = wp_json_encode( isset( $c5['out_of_scope'] ) ? $c5['out_of_scope'] : array() );
$mis = wp_json_encode( isset( $c5['items'] ) ? $c5['items'] : array() );
$ok( false !== strpos( $oos, 'Your privacy' ), 'consent-banner text lands in out_of_scope' );
$ok( false === strpos( $mis, 'Your privacy' ), 'NEGATIVE: consent-banner text is not counted as missing' );

// The regression that made the whole audit useless: Complianz stamps its own class on <body>, so an
// ancestor climb that includes <body> matches every phrase on the page.
$c6 = $build( $consent, 'class="cmplz-document cmplz-optin"' )['coverage'];
$oos6 = wp_json_encode( isset( $c6['out_of_scope'] ) ? $c6['out_of_scope'] : array() );
$ok( false === strpos( $oos6, 'Body copy that the conversion' ),
	'NEGATIVE: a CMP class on <body> does NOT make ordinary page text out-of-scope' );
$ok( isset( $c6['checked'] ) && $c6['checked'] > 0,
	'NEGATIVE: a CMP class on <body> does not empty the checked set (100% coverage by vacuum)' );

/* ------------------------------------------- 4. EVERY page, not just the first -- */
echo "\n=== MULTI-PAGE: the audit must not stop at the home page ===\n";

// The bug this pins: wired into the bundle, the audit was handed `$screens[0]` — the home page — while
// the haystack held every page. An inner page could lose its entire body and the report would still say
// 100%. The instrument built to catch silent content loss was itself silent about every page but one.
$mk = function ( $body ) {
	return '<!doctype html><html><head><title>T</title></head><body><main>' . $body . '</main></body></html>';
};
$home  = $mk( '<section><h1>The home page headline</h1><p>Copy that the conversion is expected to carry.</p></section>' );
$inner = $mk( '<section><h1>Pricing for every team</h1>'
	. '<p>A distinctive inner-page sentence that exists nowhere on the home page at all.</p></section>' );

$bundle = FW_Site_Converter_Stitch::build_bundle( array( 'screens' => array(
	array( 'html' => $home,  'slug' => 'home',    'front' => true ),
	array( 'html' => $inner, 'slug' => 'pricing', 'front' => false ),
) ) );
$mc = $bundle['files']['conversion-drops.json']['text_coverage'] ?? array();

$ok( ( $mc['pages'] ?? 0 ) >= 2, 'both pages were audited', 'pages=' . ( $mc['pages'] ?? 0 ) );
$ok( isset( $mc['by_page']['pricing'] ), 'the inner page has its own coverage row',
	'rows: ' . implode( ',', array_keys( (array) ( $mc['by_page'] ?? array() ) ) ) );
// Per-page rows are the point: "we lost the pricing table" and "we lost it on /pricing" are different
// amounts of information, and only the second is actionable.
$ok( ( $mc['checked'] ?? 0 ) > ( $mc['by_page']['home']['checked'] ?? PHP_INT_MAX ),
	'the total counts MORE phrases than the home page alone' );
foreach ( (array) ( $mc['items'] ?? array() ) as $it ) {
	$ok( isset( $it['page'] ) && '' !== $it['page'], 'every reported item names the page it came from' );
	break;
}

/* ------------------------------------------- 5. CALIBRATION -------------------- */
echo "\n=== CALIBRATION: a perfect input must score 100% ===\n";

// Every reading this audit produces sits on top of its own error, so that error has to be known. Give it a
// haystack that literally CONTAINS the source's visible text: anything it still calls missing is a
// normalisation bug in the audit — a phrase it cannot match against itself — not content loss. Without
// this number, a report of "6 missing" is an opinion.
$cal_src = $mk(
	'<section><h1>Calibration heading with several words</h1>'
	. '<p>A sentence carrying punctuation, a comma and an apostrophe\'s curl.</p>'
	. '<p>UPPER CASE COPY THAT A THEME MIGHT RE-CASE.</p>'
	. '<p>Spacing   with    irregular     gaps between words.</p>'
	. '<blockquote>A quoted line &mdash; with an em dash &amp; an entity.</blockquote>'
	. '</section>'
);
$doc = new DOMDocument();
$prev = libxml_use_internal_errors( true );
$doc->loadHTML( '<?xml encoding="utf-8"?>' . $cal_src );
libxml_clear_errors();
libxml_use_internal_errors( $prev );
$body_el  = $doc->getElementsByTagName( 'body' )->item( 0 );
$own_text = $body_el ? $body_el->textContent : '';

$m = new ReflectionMethod( 'FW_Site_Converter_Stitch', 'build_text_coverage' );
$m->setAccessible( true );
$cal = $m->invokeArgs( null, array( $cal_src, array(), array( 'pages' => array( array( 'blob' => $own_text ) ) ) ) );

$ok( ( $cal['checked'] ?? 0 ) > 0, 'the calibration actually checked phrases', 'checked=' . ( $cal['checked'] ?? 0 ) );
$ok( 0 === (int) ( $cal['missing'] ?? -1 ) && 100.0 === (float) ( $cal['coverage_pct'] ?? 0 ),
	'a haystack containing the source text scores 100% — the audit has no false misses',
	'coverage=' . ( $cal['coverage_pct'] ?? '?' ) . '% missing=' . ( $cal['missing'] ?? '?' )
		. ( ! empty( $cal['items'] ) ? ' first=' . wp_json_encode( $cal['items'][0] ) : '' ) );


/* ==========================================================================================
 * [SN] A RICH TAB PANEL IS NOT IN THE PAGE — IT IS A SNIPPET THE PAGE REFERENCES.
 *  n_tabs() moves a large panel into a `snippet` CPT and leaves `[snippet id="N"]` behind. The audit read
 *  only the page tree, so every word of that panel counted as LOST. Measured on a captured menu page with
 *  two tab panels: 120 of 216 phrases "missing", 44.4% coverage — and all 120 sat intact in the snippet the
 *  page pointed at. It ranked worst in the whole corpus on a defect that did not exist. After: 91.2%.
 *  A false miss costs more than a missed one, because it is the one that gets acted on.
 * ========================================================================================== */
if ( function_exists( 'post_type_exists' ) && post_type_exists( 'snippet' ) && function_exists( 'fw_set_db_post_option' ) ) {
	$sn_src = '<body><section><h2>Dessert Menu</h2>'
		. '<p>Molten Chocolate Cake served warm with ice cream.</p>'
		. '<p>A phrase that was carried nowhere at all.</p></section></body>';
	$sn_id  = wp_insert_post( array( 'post_type' => 'snippet', 'post_status' => 'publish', 'post_title' => 'SC coverage fixture' ) );
	fw_set_db_post_option( $sn_id, null, array( 'blob' => 'Molten Chocolate Cake served warm with ice cream.' ) );

	/* The page carries the heading itself and REFERENCES the snippet for the panel. */
	$sn_cov = $m->invokeArgs( null, array( $sn_src, array(), array( 'pages' => array( array(
		'blob' => 'Dessert Menu [snippet id="' . (int) $sn_id . '"]',
	) ) ) ) );
	$sn_lost = wp_json_encode( $sn_cov['items'] ?? array() );

	$ok( false === strpos( $sn_lost, 'Molten Chocolate' ),
		'[SN] text carried inside a referenced snippet is NOT reported missing',
		'coverage=' . ( $sn_cov['coverage_pct'] ?? '?' ) . '% lost=' . mb_substr( $sn_lost, 0, 160 ) );

	/* NEGATIVE: following the reference must not blind the audit. A phrase in NO snippet is still a miss —
	   the way to get this wrong is a haystack so wide that nothing can ever be reported lost. */
	$ok( false !== strpos( $sn_lost, 'carried nowhere' ),
		'[SN] NEGATIVE: a phrase in no snippet is still reported missing',
		'lost=' . mb_substr( $sn_lost, 0, 160 ) );

	wp_delete_post( $sn_id, true );
} else {
	$ok( false, '[SN] the snippet post type is available to test against', 'snippet CPT or fw_set_db_post_option missing — the case could not be exercised' );
}

echo "\n" . ( $fails ? "✗ {$fails} FAIL" : '✓ ALL PASS — title derivation + coverage audit guarded' ) . "\n";
exit( $fails ? 1 : 0 );
