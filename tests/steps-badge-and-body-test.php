<?php
/**
 * Regression guard for two silent losses in a captured process/steps list.
 *
 * 1. THE NUMERAL CHIP'S SKIN. A source that nests the numeral inside the step heading
 *    (`<h3 class="flex"><span class="rounded-full">1</span>Title</h3>`) paints exactly the same round chip
 *    as one that puts it in a leading cell, but only the leading-cell shape carried the chip's measured skin
 *    forward. The nested shape reached the page with the design's DEFAULT marker fill, and — because a current
 *    utility-CSS source computes its colours to `oklab()` / `oklch()`, which the rgb()-shaped colour reads
 *    never matched — the fill stayed near-white under white text: a numeral invisible on the page.
 *
 * 2. THE BODY COPY. A step (or card) whose body the source writes as several paragraphs kept only the FIRST
 *    and dropped the rest, with nothing to report it: a four-step section lost roughly a thousand characters
 *    and rendered 44% shorter than its source.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/steps-badge-and-body-test.php"
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

/** Walk a builder tree and return the first node for shortcode $type (builder nodes are `simple` + `shortcode`). */
$find = function ( $nodes, $type ) use ( &$find ) {
	foreach ( (array) $nodes as $n ) {
		if ( ! is_array( $n ) ) { continue; }
		if ( ( $n['shortcode'] ?? '' ) === $type || ( $n['type'] ?? '' ) === $type ) { return $n; }
		$hit = $find( $n['_items'] ?? array(), $type );
		if ( $hit ) { return $hit; }
	}
	return null;
};

/* The chip's measured stamp, in the colour syntax a current utility-CSS source actually computes to. */
$chip_cs = 'display:flex;width:28px;height:28px;border-radius:3.35544e+07px;'
	. 'background-color:oklab(0.57599 0.0663667 -0.180282 / 0.2);color:oklch(0.73906 0.12063 294.19);'
	. 'font-size:12px;font-weight:700;line-height:16px';

$step = function ( $n, $title, $paras ) use ( $chip_cs ) {
	$body = '';
	foreach ( $paras as $p ) { $body .= '<p data-sc-cs="font-size:16px;line-height:26px">' . $p . '</p>'; }
	return '<li data-sc-cs="display:block">'
		. '<h3 data-sc-cs="display:flex;align-items:center;gap:12px;font-size:20px;font-weight:700">'
		. '<span data-sc-cs="' . $chip_cs . '">' . $n . '</span>' . $title . '</h3>'
		. $body . '</li>';
};

$html = '<!doctype html><html><head><title>Process</title></head><body>'
	. '<section id="process" data-sc-cs="padding-top:96px;padding-bottom:96px">'
	. '<h2 data-sc-cs="font-size:40px;font-weight:700">Your plan, step by step</h2>'
	. '<ol data-sc-cs="display:block">'
	. $step( '1', 'Where are you now', array(
		'We start by looking at where your business stands today.',
		'That may include your site, your local visibility, your reviews and the way enquiries reach you.',
		'The goal is to see what already works and what does not.',
	) )
	. $step( '2', 'Where do you want to go', array(
		'More calls are not useful if what you need is more booked work.',
		'So we talk about the outcome you are actually after before anything is recommended.',
	) )
	. $step( '3', 'Build the roadmap', array(
		'Once the start and the end are clear, we map the steps between them.',
		'We keep to the parts that make sense for the business rather than adding everything available.',
	) )
	. '</ol></section></body></html>';

$res  = FW_Site_Converter_Sources::build_from_html( $html, 'Process', array( 'dynamic_chrome' => true ) );
$page = $res['files']['pages.json']['pages'][0] ?? array();
$node = $find( $page['builder'] ?? array(), 'steps' );

echo "\n== A numeral chip nested in the step heading\n";

$ok( is_array( $node ), 'the process list converts to a native steps node' );
$atts  = is_array( $node ) ? ( $node['atts'] ?? array() ) : array();
$css   = (string) ( $atts['custom_css'] ?? '' );
$items = (array) ( $atts['steps'] ?? array() );

$ok( 'number' === ( $atts['marker'] ?? '' ),
	'the chip is recognised as the step MARKER, not a bare inline digit (got "' . ( $atts['marker'] ?? '' ) . '")' );
$ok( 'circle' === ( $atts['marker_shape'] ?? '' ),
	'...and keeps its measured round shape (got "' . ( $atts['marker_shape'] ?? '' ) . '")' );
$ok( false !== strpos( $css, '.fw-steps__marker' ),
	'the chip measured skin is scoped onto .fw-steps__marker' );
$ok( false !== strpos( $css, 'oklab(0.57599 0.0663667 -0.180282 / 0.2)' ),
	'...carrying the source FILL verbatim, in the colour syntax the source computed it to' );
$ok( false !== strpos( $css, 'oklch(0.73906 0.12063 294.19)' ),
	'...and the numeral own INK, so it cannot render white-on-near-white' );
$ok( false !== strpos( $css, 'font-size:12px' ),
	'...and the chip 12px numeral, not the design larger default' );

echo "\n== Every paragraph of the step body, not just the first\n";

$first = is_array( $items ) ? ( $items[0]['content'] ?? '' ) : '';
$ok( false !== strpos( $first, 'where your business stands today' ),
	'paragraph 1 survives' );
$ok( false !== strpos( $first, 'the way enquiries reach you' ),
	'paragraph 2 survives (this is what was silently dropped)' );
$ok( false !== strpos( $first, 'what already works and what does not' ),
	'paragraph 3 survives' );
$ok( (bool) preg_match( '/\S(?:\r?\n){2}\s*\S/', $first ),
	'...joined by a blank line, which wpautop() turns back into separate paragraphs' );

$second = is_array( $items ) && isset( $items[1] ) ? ( $items[1]['content'] ?? '' ) : '';
$ok( false !== strpos( $second, 'more booked work' ) && false !== strpos( $second, 'actually after' ),
	'a two-paragraph step keeps both' );

echo "\n== NEGATIVE: the join is body copy only\n";

$ok( false === strpos( $first, 'More calls are not useful' ),
	'NEGATIVE: a paragraph from the NEXT step is never glued onto this one' );

// A caption nested deeper inside the item is not body copy and must not join the run.
$html2 = str_replace(
	'<p data-sc-cs="font-size:16px;line-height:26px">The goal is to see what already works and what does not.</p>',
	'<figure data-sc-cs="display:block"><img src="/a.png" width="600" height="400" alt="A chart"><figcaption><p data-sc-cs="font-size:13px">Figure one, a caption that is not body copy.</p></figcaption></figure>',
	$html
);
$res2  = FW_Site_Converter_Sources::build_from_html( $html2, 'Process', array( 'dynamic_chrome' => true ) );
$page2 = $res2['files']['pages.json']['pages'][0] ?? array();
$node2 = $find( $page2['builder'] ?? array(), 'steps' );
$body2 = is_array( $node2 ) ? (string) ( $node2['atts']['steps'][0]['content'] ?? '' ) : '';
$ok( '' !== $body2 && false === strpos( $body2, 'a caption that is not body copy' ),
	'NEGATIVE: a caption nested deeper than the body run is not joined on' );

echo $fails ? "\n\xe2\x9c\x97 $fails FAILED\n" : "\n\xe2\x9c\x93 ALL PASS - the step chip keeps its measured skin and the body keeps every paragraph\n";
exit( $fails ? 1 : 0 );
