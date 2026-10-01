<?php
/**
 * Regression guard: a converted accordion wears the SOURCE's skin, not the shortcode's light default.
 *
 * Three separate drops, one visible symptom — a FAQ whose source draws translucent dark panels came back as
 * near-white slabs with dark headings and grey-on-white body copy, the largest perceptual error on its page:
 *
 * 1. Every colour read in accordion_design() matched `rgb()` only, so a source that computes its colours to
 *    `oklab()` / `oklch()` — which is what current utility CSS does — yielded nothing at all and every colour
 *    option came back empty.
 * 2. `title_bg_color` skins only the HEADER BAR, while the design's own CSS paints the ITEM. A translucent
 *    panel therefore landed on top of an opaque white item, which is what the eye actually sees.
 * 3. An inline `<svg>` toggle icon carries no computed-style stamp on many captures, and on the page that
 *    prompted this EVERY wearer of its colour token was one of those unstamped icons. The token is a
 *    site-wide name, so one stamped instance on ANY captured route resolves it.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/accordion-skin-test.php"
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

$find_acc = function ( $res ) {
	$walk = function ( $nodes ) use ( &$walk ) {
		foreach ( (array) $nodes as $n ) {
			if ( ! is_array( $n ) ) { continue; }
			if ( 'accordion' === ( $n['shortcode'] ?? '' ) ) { return $n; }
			$hit = $walk( $n['_items'] ?? array() );
			if ( $hit ) { return $hit; }
		}
		return null;
	};
	return $walk( $res['files']['pages.json']['pages'][0]['builder'] ?? array() );
};

/* The source's own colour syntax — every value below is what a browser computes for modern utility CSS. */
$ink       = 'color:oklch(0.95413 0.01612 293.75);font-family:Inter, sans-serif;font-size:16px;line-height:24px;';
$item_cs   = 'display:block;background-color:oklab(0.999994 0.0000455678 0.0000200868 / 0.05);'
	. 'border-top-width:1px;border-top-style:solid;border-top-color:oklab(0.57599 0.0663667 -0.180282 / 0.4);border-radius:18px';
$bar_cs    = 'display:flex;align-items:center;justify-content:space-between;background-color:rgba(0, 0, 0, 0);color:oklch(0.95413 0.01612 293.75);padding:16px 20px';
$panel_cs  = 'display:block;background-color:rgba(0, 0, 0, 0);color:oklch(0.95413 0.01612 293.75);font-size:14px;padding:0px 20px 20px';

$item = function ( $q, $a, $open ) use ( $item_cs, $bar_cs, $panel_cs ) {
	return '<div data-sc-cs="' . $item_cs . ';margin:0px 0px 8px">'
		. '<button aria-expanded="' . ( $open ? 'true' : 'false' ) . '" data-sc-cs="' . $bar_cs . '">'
		. '<h2 data-sc-cs="font-size:16px;font-weight:600;color:rgb(255, 255, 255)">' . $q . '</h2>'
		// An inline svg with NO stamp of its own — only a colour token on its class.
		. '<svg class="lucide lucide-chevron-down shrink-0 text-brand-accent" width="18" height="18" viewBox="0 0 24 24"><path d="m6 9 6 6 6-6"></path></svg>'
		. '</button>'
		. '<div data-sc-cs="' . $panel_cs . '"><p data-sc-cs="font-size:14px;line-height:22px">' . $a . '</p></div>'
		. '</div>';
};

$html = '<!DOCTYPE html><html><head><title>FAQ</title></head><body><main>'
	. '<section data-sc-cs="' . $ink . 'padding:96px 24px;display:block">'
	. '<h2 data-sc-cs="font-size:36px;font-weight:700">Questions</h2>'
	// A BLOCK list with per-item margin, the way a `space-y-3` toggle list really comes out of a capture.
	. '<div data-sc-cs="display:block">'
	. $item( 'Can you help set up online booking?', 'Yes, booking and calendar connections can be set up along with reminders.', true )
	. $item( 'Why are you offering free work?', 'Early clients help build the case studies that the rest of the work rests on.', false )
	. $item( 'What is local search?', 'It is how nearby customers find a business when they search for what it offers.', false )
	. '</div></section></main></body></html>';

// A second route where the SAME colour token IS stamped — this is how an unstamped icon's colour is resolved.
$other = '<!DOCTYPE html><html><head><title>About</title></head><body><main>'
	. '<section data-sc-cs="' . $ink . 'padding:96px 24px;display:block">'
	. '<p class="text-brand-accent" data-sc-cs="color:oklch(0.73906 0.12063 294.19);font-size:10px;text-transform:uppercase">Our approach</p>'
	. '<h2 data-sc-cs="font-size:36px;font-weight:700">How it works</h2>'
	. '<p data-sc-cs="font-size:16px;line-height:26px">Body copy long enough to count as a real paragraph on this page.</p>'
	. '</section></main></body></html>';

FW_Site_Converter_Stitch::set_button_scan_html( $html . $other );
$node = $find_acc( FW_Site_Converter_Sources::build_from_html( $html, 'FAQ', array( 'dynamic_chrome' => true ) ) );
$atts = is_array( $node ) ? (array) ( $node['atts'] ?? array() ) : array();
$css  = (string) ( $atts['custom_css'] ?? '' );
$cv   = function ( $k ) use ( $atts ) { return strtolower( (string) ( $atts[ $k ]['custom'] ?? '' ) ); };

echo "\n== The source's colours survive their own syntax\n";

$ok( is_array( $node ), 'the toggles convert to a native accordion node' );
$ok( '' !== $cv( 'tab_title_color' ),
	'the heading ink is read despite being an oklch() value (got "' . $cv( 'tab_title_color' ) . '")' );
$ok( '' !== $cv( 'tab_content_color' ),
	'...and the panel ink too (got "' . $cv( 'tab_content_color' ) . '")' );
$ok( false !== strpos( $cv( 'tab_title_color' ), '#' ) || false !== strpos( $cv( 'tab_title_color' ), 'rgb' ),
	'...resolved to a colour the option can actually store' );

echo "\n== The ITEM carries the fill, so no white slab shows under it\n";

$ok( false !== strpos( $css, '.accordion-item' ) && false !== strpos( $css, 'background:rgba(255, 255, 255, 0.05)' ),
	'the measured translucent fill lands on the ITEM' );
$ok( false !== strpos( $css, 'selector .accordion-title,selector .accordion-content{background:transparent' ),
	'...and the bar and panel are cleared so it is what shows through' );
$ok( false !== strpos( $css, 'border:1px solid rgba(124, 92, 224, 0.4)' ),
	'the item keeps its measured hairline, which no native option carries' );
$ok( false !== strpos( $css, 'border-radius:18px' ),
	'...and its measured corner' );

echo "\n== An unstamped icon resolves its colour token from another route\n";

$ok( '' !== $cv( 'icon_closed_color' ),
	'the toggle icon gets a colour at all (got "' . $cv( 'icon_closed_color' ) . '")' );
$ok( '#af9bef' === $cv( 'icon_closed_color' ),
	'...the one the token resolves to where it IS stamped (got "' . $cv( 'icon_closed_color' ) . '")' );

echo "\n== NEGATIVE: nothing is invented\n";

FW_Site_Converter_Stitch::set_button_scan_html( $html );   // the token is now stamped nowhere
$n2 = $find_acc( FW_Site_Converter_Sources::build_from_html( $html, 'FAQ', array( 'dynamic_chrome' => true ) ) );
$c2 = is_array( $n2 ) ? strtolower( (string) ( $n2['atts']['icon_closed_color']['custom'] ?? '' ) ) : 'x';
$ok( '' === $c2,
	'NEGATIVE: with no stamped instance anywhere, the icon colour stays empty rather than guessed (got "' . $c2 . '")' );

// A fully TRANSPARENT fill says nothing and must not be written as a colour.
$clear = str_replace( 'background-color:oklab(0.999994 0.0000455678 0.0000200868 / 0.05)', 'background-color:rgba(0, 0, 0, 0)', $html );
$n3    = $find_acc( FW_Site_Converter_Sources::build_from_html( $clear, 'FAQ', array( 'dynamic_chrome' => true ) ) );
$c3    = is_array( $n3 ) ? (string) ( $n3['atts']['custom_css'] ?? '' ) : '';
$ok( false === strpos( $c3, 'selector .accordion-item{background:' ),
	'NEGATIVE: a transparent item fill is not written out as a background' );

FW_Site_Converter_Stitch::set_button_scan_html( '' );   // leave no cross-route state behind

echo $fails ? "\n\xe2\x9c\x97 $fails FAILED\n" : "\n\xe2\x9c\x93 ALL PASS - the accordion wears the source's own skin\n";
exit( $fails ? 1 : 0 );
