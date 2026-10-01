<?php
/**
 * Regression guard: a card that IS a link converts to a card that IS a link.
 *
 * `<a class="card-surface" href="/laptop-repair"> …icon… <h3/> <p/> <div>Mehr erfahren →</div> </a>` is one of
 * the commonest card shapes on the web, and the whole box is the control. Its call to action has to be a
 * <div> rather than an <a>, because HTML forbids nesting an anchor in an anchor.
 *
 * Both halves were lost. The card's own CTA detector only accepts a <div> that declares `cursor-pointer` or
 * stamps a computed `cursor:pointer` — and neither is present here, because the pointer is INHERITED from the
 * anchor and the capture does not stamp it. Nothing else looked at the cell's own `href`. So a converted
 * services grid rendered three cards that went nowhere and showed no affordance at all: -3 links and -3 arrow
 * glyphs against the source, measured.
 *
 * Being an anchor is the whole signal — no cursor heuristic is needed, because the box IS the control. The
 * destination then belongs on `box_link` and the label must NOT be re-wrapped in an anchor: a nested link
 * inside a clickable box is invalid HTML, as the option's own help text says.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/card-whole-link-test.php"
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

$INK = 'color:rgb(20, 20, 24);font-family:Inter, sans-serif;font-size:16px;line-height:24px;text-align:start;';
$ARROW = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" class="lucide lucide-arrow-right"><path d="M5 12h14"></path><path d="m12 5 7 7-7 7"></path></svg>';

/** The first icon_box node of a converted grid. */
$box = function ( $cards ) use ( $INK ) {
	$html = '<!DOCTYPE html><html><head><title>Services</title></head><body><main>'
		. '<section data-sc-cs="' . $INK . 'display:block;padding:96px 24px"><h2 data-sc-cs="font-size:36px;font-weight:700">Our services</h2>'
		. '<p data-sc-cs="font-size:16px;line-height:26px">A short run of copy before the grid, long enough to read as real.</p>'
		. '<div data-sc-cs="' . $INK . 'display:grid;grid-template-columns:1fr 1fr 1fr;gap:24px">' . $cards . '</div>'
		. '</section></main></body></html>';
	$res  = FW_Site_Converter_Sources::build_from_html( $html, 'Services', array( 'dynamic_chrome' => true ) );
	$walk = function ( $nodes ) use ( &$walk ) {
		foreach ( (array) $nodes as $n ) {
			if ( ! is_array( $n ) ) { continue; }
			if ( 'icon_box' === ( $n['shortcode'] ?? '' ) ) { return $n; }
			$hit = $walk( $n['_items'] ?? array() );
			if ( $hit ) { return $hit; }
		}
		return null;
	};
	$n = $walk( $res['files']['pages.json']['pages'][0]['builder'] ?? array() );
	return is_array( $n ) ? (array) $n['atts'] : array();
};

/** One card. $href empty = a plain <div> card. */
$card = function ( $href, $title, $cta ) use ( $INK, $ARROW ) {
	$inner = '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" class="lucide lucide-laptop"><path d="M3 5h18v10H3z"></path></svg>'
		. '<h3 data-sc-cs="font-size:20px;font-weight:600">' . $title . '</h3>'
		. '<p data-sc-cs="font-size:14px;line-height:22px">Display, Akku, Tastatur, Wasserschaden, Mainboard.</p>'
		. ( '' !== $cta ? '<div data-sc-cs="' . $INK . 'display:inline-flex;font-size:14px;font-weight:600;margin:24px 0px 0px;align-items:center;gap:6px">' . $cta . ' ' . $ARROW . '</div>' : '' );
	$cs = $INK . 'display:block;background-color:rgb(255, 255, 255);padding:28px;border-radius:12px';
	return '' !== $href
		? '<a href="' . $href . '" class="card-surface group" data-sc-cs="' . $cs . '">' . $inner . '</a>'
		: '<div class="card-surface" data-sc-cs="' . $cs . '">' . $inner . '</div>';
};

$THREE = $card( '/laptop-reparatur', 'Laptop Reparatur', 'Mehr erfahren' )
	. $card( '/pc-reparatur', 'PC Reparatur', 'Mehr erfahren' )
	. $card( '/chiplevel', 'Microsoldering', 'Mehr erfahren' );

echo "\n== The card's own href becomes the box's link\n";

$a = $box( $THREE );
$ok( ! empty( $a ), 'the grid converts to icon_box cards' );
$ok( '/laptop-reparatur' === (string) ( $a['box_link'] ?? '' ),
	'the card\'s destination lands on box_link (got "' . ( $a['box_link'] ?? '' ) . '")' );

echo "\n== ...and its affordance row survives\n";

$content = (string) ( $a['content'] ?? '' );
$ok( false !== strpos( $content, 'Mehr erfahren' ),
	'the "Mehr erfahren" row is kept (content: "' . mb_substr( wp_strip_all_tags( $content ), 0, 70 ) . '")' );
$ok( false !== strpos( $content, 'Display, Akku' ),
	'...alongside the card\'s own description, not instead of it' );
$ok( false !== stripos( $content, '<svg' ) && false !== stripos( $content, 'arrow-right' ),
	'...and the ARROW rides with it — the glyph IS the affordance, and three cards had lost three images' );

echo "\n== NEGATIVE: no anchor is nested inside a clickable box\n";

$ok( false === stripos( $content, '<a ' ) && false === stripos( $content, '<a>' ),
	'NEGATIVE: the affordance is NOT re-wrapped in an <a> — a nested link inside a clickable box is invalid HTML (content: "' . esc_html( mb_substr( $content, 0, 90 ) ) . '")' );

echo "\n== NEGATIVE: a card that is NOT a link gets no link\n";

$plain = $box( $card( '', 'Laptop Reparatur', 'Mehr erfahren' ) . $card( '', 'PC Reparatur', 'Mehr erfahren' ) . $card( '', 'Microsoldering', 'Mehr erfahren' ) );
$ok( '' === (string) ( $plain['box_link'] ?? '' ),
	'NEGATIVE: a plain <div> card has no box_link (got "' . ( $plain['box_link'] ?? '' ) . '")' );

echo "\n== NEGATIVE: a placeholder href is not a destination\n";

foreach ( array( '#', 'javascript:void(0)' ) as $dead ) {
	$d = $box( $card( $dead, 'Laptop Reparatur', 'Mehr erfahren' ) . $card( $dead, 'PC Reparatur', 'Mehr erfahren' ) . $card( $dead, 'Microsoldering', 'Mehr erfahren' ) );
	$ok( '' === (string) ( $d['box_link'] ?? '' ),
		'NEGATIVE: `' . $dead . '` is not carried as a link (got "' . ( $d['box_link'] ?? '' ) . '")' );
}

echo "\n== NEGATIVE: a linked card with no affordance row invents none\n";

$noc = $box( $card( '/laptop-reparatur', 'Laptop Reparatur', '' ) . $card( '/pc-reparatur', 'PC Reparatur', '' ) . $card( '/chiplevel', 'Microsoldering', '' ) );
$ok( '/laptop-reparatur' === (string) ( $noc['box_link'] ?? '' ),
	'NEGATIVE: the link is still carried without a CTA row (got "' . ( $noc['box_link'] ?? '' ) . '")' );
$ok( false === strpos( (string) ( $noc['content'] ?? '' ), 'icon-box__more' ),
	'NEGATIVE: ...and no affordance text is fabricated' );

echo $fails ? "\n\xe2\x9c\x97 $fails FAILED\n" : "\n\xe2\x9c\x93 ALL PASS - a card that is a link stays a link\n";
exit( $fails ? 1 : 0 );
