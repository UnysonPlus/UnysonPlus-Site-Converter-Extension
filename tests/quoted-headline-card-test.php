<?php
/**
 * Regression guard: a QUOTED HEADLINE over labelled sections is not a testimonial.
 *
 * A problem/solution card quotes the customer's complaint as its TITLE ("My competitors show up on Google.
 * I don't.") and then explains it in labelled groups — a short all-caps label over a paragraph, twice — with
 * no author, no role, no avatar and no rating anywhere. The testimonials recognizer claimed such a card on
 * the quotation marks alone, and a testimonial holds only a quote: every labelled group went with the claim.
 * Measured on the page that prompted this, the claim kept 0 of 11 body phrases; the fallback keeps 11 of 11.
 *
 * The exclusion is deliberately narrow, because a NOTE in looks_quote_card() records that an earlier, broader
 * tightening made a corpus page WORSE — a rejected card can fall to a path that keeps less than a wrong but
 * partial claim did. Measured across 138 captured pages and 268 claimable cards, this rule excludes 8 cards,
 * all on the one capture that motivated it. Re-measure before widening it.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/quoted-headline-card-test.php"
 *
 * Exit code 0 = all PASS, 1 = at least one FAIL.
 */

if ( ! class_exists( 'FW_Site_Converter_Sources' ) ) {
	fwrite( STDERR, "FAIL: site-converter not loaded (run inside a WP install with the plugin active)\n" );
	exit( 1 );
}

$fails = 0;
$ok    = function ( $cond, $msg ) use ( &$fails ) {
	if ( $cond ) { echo "  \xe2\x9c\x93 $msg\n"; } else { $fails++; echo "  \xe2\x9c\x97 FAIL: $msg\n"; }
};

$ink   = 'color:rgb(255, 255, 255);font-family:Inter, sans-serif;font-size:16px;line-height:24px;';
$label = 'font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:1.5px;color:rgba(255,255,255,0.4);margin:0px 0px 6px';
$body  = 'font-size:14px;line-height:22px;color:rgb(220,220,230)';

/** One problem/solution card: a quoted HEADING, then two label-over-paragraph groups. No attribution. */
$prob_card = function ( $quote, $p1, $p2 ) use ( $ink, $label, $body ) {
	return '<div data-sc-cs="' . $ink . 'display:block;padding:24px">'
		. '<div data-sc-cs="display:flex;gap:12px"><h3 data-sc-cs="font-size:20px;font-weight:700">"' . $quote . '"</h3></div>'
		. '<div data-sc-cs="display:block;margin:0px 0px 16px"><p data-sc-cs="' . $label . '">The problem</p>'
		. '<p data-sc-cs="' . $body . '">' . $p1 . '</p></div>'
		. '<div data-sc-cs="display:block;margin:0px 0px 16px"><p data-sc-cs="' . $label . '">How we help</p>'
		. '<p data-sc-cs="' . $body . '">' . $p2 . '</p></div>'
		. '</div>';
};

$page = function ( $cards ) use ( $ink ) {
	return '<!DOCTYPE html><html><head><title>Problems</title></head><body><main>'
		. '<section data-sc-cs="' . $ink . 'padding:96px 24px;display:block">'
		. '<h2 data-sc-cs="font-size:36px;font-weight:700">Sound Familiar?</h2>'
		. '<div data-sc-cs="display:grid;grid-template-columns:600px 600px;gap:24px">' . $cards . '</div>'
		. '</section></main></body></html>';
};

$P1 = 'A strong business can still be hard to discover online when nearby competitors appear more clearly.';
$P2 = 'We improve your local search presence through practical work on the profile, the site and its content.';
$P3 = 'Visits alone do not pay the bills if the page is confusing or unclear about the next step to take.';
$P4 = 'We review the path from landing page to contact and improve the words, layout, forms and next steps.';

$html = $page( $prob_card( 'My competitors show up on search. I do not.', $P1, $P2 )
	. $prob_card( 'I am getting traffic, but nobody contacts me.', $P3, $P4 ) );

$res   = FW_Site_Converter_Sources::build_from_html( $html, 'Problems', array( 'dynamic_chrome' => true ) );
$built = (string) wp_json_encode( $res['files']['pages.json']['pages'][0]['builder'] ?? array() );

$kinds = array();
$walk  = function ( $nodes ) use ( &$walk, &$kinds ) {
	foreach ( (array) $nodes as $n ) {
		if ( ! is_array( $n ) ) { continue; }
		$kinds[] = (string) ( $n['shortcode'] ?? $n['type'] ?? '?' );
		$walk( $n['_items'] ?? array() );
	}
};
$walk( $res['files']['pages.json']['pages'][0]['builder'] ?? array() );

echo "\n== A quoted headline over labelled sections keeps its body\n";

$ok( ! in_array( 'testimonials', $kinds, true ),
	'the grid is NOT claimed as testimonials (got: ' . implode( ' ', array_unique( $kinds ) ) . ')' );
$ok( false !== strpos( $built, 'hard to discover online' ),
	'card 1, group 1 body survives' );
$ok( false !== strpos( $built, 'improve your local search presence' ),
	'card 1, group 2 body survives — the part a testimonial cannot hold' );
$ok( false !== strpos( $built, 'Visits alone do not pay the bills' ) && false !== strpos( $built, 'review the path from landing page' ),
	'card 2 keeps both of its groups too' );
$ok( false !== strpos( $built, 'The problem' ) && false !== strpos( $built, 'How we help' ),
	'...and the labels that introduce them' );

echo "\n== NEGATIVE: a real testimonial grid is still claimed\n";

$t_card = function ( $quote, $name, $role ) use ( $ink ) {
	return '<div data-sc-cs="' . $ink . 'display:block;padding:24px">'
		. '<p data-sc-cs="font-size:16px;line-height:26px">"' . $quote . '"</p>'
		. '<div data-sc-cs="display:flex;align-items:center;gap:12px">'
		. '<img src="/a.png" width="48" height="48" alt="" data-sc-cs="width:48px;height:48px;border-radius:9999px;display:block">'
		. '<div data-sc-cs="display:block">'
		. '<p data-sc-cs="font-size:15px;font-weight:700">' . $name . '</p>'
		. '<p data-sc-cs="font-size:13px;font-weight:400;color:rgba(255,255,255,0.6)">' . $role . '</p>'
		. '</div></div></div>';
};
$t_html = $page( $t_card( 'They turned our enquiries into booked work within a month of starting.', 'Dana Reyes', 'Operations lead' )
	. $t_card( 'The reporting finally explains what the numbers mean for the business.', 'Sam Okafor', 'Owner' ) );
$t_kinds = array();
$t_res   = FW_Site_Converter_Sources::build_from_html( $t_html, 'Quotes', array( 'dynamic_chrome' => true ) );
$t_walk  = function ( $nodes ) use ( &$t_walk, &$t_kinds ) {
	foreach ( (array) $nodes as $n ) {
		if ( ! is_array( $n ) ) { continue; }
		$t_kinds[] = (string) ( $n['shortcode'] ?? $n['type'] ?? '?' );
		$t_walk( $n['_items'] ?? array() );
	}
};
$t_walk( $t_res['files']['pages.json']['pages'][0]['builder'] ?? array() );
$ok( in_array( 'testimonials', $t_kinds, true ),
	'NEGATIVE: quotes WITH an author block are still testimonials (got: ' . implode( ' ', array_unique( $t_kinds ) ) . ')' );

echo "\n== NEGATIVE: the rule needs the full shape\n";

$one_group = function ( $quote, $p1 ) use ( $ink, $label, $body ) {
	return '<div data-sc-cs="' . $ink . 'display:block;padding:24px">'
		. '<h3 data-sc-cs="font-size:20px;font-weight:700">"' . $quote . '"</h3>'
		. '<div data-sc-cs="display:block"><p data-sc-cs="' . $label . '">The problem</p>'
		. '<p data-sc-cs="' . $body . '">' . $p1 . '</p></div></div>';
};
$o_kinds = array();
$o_res   = FW_Site_Converter_Sources::build_from_html(
	$page( $one_group( 'My competitors show up on search. I do not.', $P1 ) . $one_group( 'I am getting traffic, but nobody contacts me.', $P3 ) ),
	'One', array( 'dynamic_chrome' => true ) );
$o_walk  = function ( $nodes ) use ( &$o_walk, &$o_kinds ) {
	foreach ( (array) $nodes as $n ) {
		if ( ! is_array( $n ) ) { continue; }
		$o_kinds[] = (string) ( $n['shortcode'] ?? $n['type'] ?? '?' );
		$o_walk( $n['_items'] ?? array() );
	}
};
$o_walk( $o_res['files']['pages.json']['pages'][0]['builder'] ?? array() );
$ok( in_array( 'testimonials', $o_kinds, true ),
	'NEGATIVE: ONE labelled group is not enough to reject — the rule needs two (got: ' . implode( ' ', array_unique( $o_kinds ) ) . ')' );

echo $fails ? "\n\xe2\x9c\x97 $fails FAILED\n" : "\n\xe2\x9c\x93 ALL PASS - a quoted headline over labelled sections keeps everything under it\n";
exit( $fails ? 1 : 0 );
