<?php
/**
 * Regression guard for three silent losses around a converted signup form and the footer lockup.
 *
 * 1. A FORM WITH NO CARD OF ITS OWN must not adopt the section's. The card reader walked up from the form and
 *    took the first painted ancestor, even when that ancestor also holds the section's heading and copy — a
 *    panel that is already reproduced as the column box. The converted signup then sat inside two identical
 *    insets, and a source form with `padding: 0` came back with 32px of it.
 *
 * 2. THE FIELD'S OWN SKIN. With no wrapper element around the input, the input itself is the field, and its
 *    measured inset / height / corner were read from nowhere — so a `px-4 py-3 rounded-xl` field converted at
 *    the shortcode's default.
 *
 * 3. THE FOOTER'S OWN LOCKUP. The theme renders the header logo in the footer, which is wrong whenever the
 *    source draws a different one there (a compact mark in the nav, the full wordmark below). Reading only the
 *    header's put a 40px glyph where the source draws a 205px wordmark.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/newsletter-and-footer-brand-test.php"
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

$find = function ( $nodes, $type ) use ( &$find ) {
	foreach ( (array) $nodes as $n ) {
		if ( ! is_array( $n ) ) { continue; }
		if ( ( $n['shortcode'] ?? '' ) === $type || ( $n['type'] ?? '' ) === $type ) { return $n; }
		$hit = $find( $n['_items'] ?? array(), $type );
		if ( $hit ) { return $hit; }
	}
	return null;
};

$ink = 'color:rgb(255, 255, 255);font-family:Inter, sans-serif;font-size:16px;line-height:24px;';

/* --------------------------------------------------------------------- *
 * A signup form inside the SECTION's card — the form itself paints nothing.
 * --------------------------------------------------------------------- */
$label_cs = 'display:block;font-size:10px;font-weight:700;letter-spacing:1px;text-transform:uppercase;margin-bottom:6px;color:rgba(255,255,255,0.4)';
$input_cs = 'display:block;width:100%;height:46px;padding:12px 16px;border-radius:12px;background-color:rgba(255,255,255,0.05);border-top-width:1px;border-top-style:solid;border-top-color:rgba(255,255,255,0.1);font-size:14px;color:rgb(255,255,255)';

$nl_html = '<!DOCTYPE html><html><head><title>Signup</title></head><body><main>'
	. '<section data-sc-cs="' . $ink . 'padding:96px 24px;display:block">'
	// The SECTION's card: a painted, rounded, padded panel that holds the heading, the copy AND the form.
	. '<div data-sc-cs="' . $ink . 'background-color:rgb(13, 22, 44);border-radius:23px;padding:32px;display:block">'
	. '<h2 data-sc-cs="font-size:32px;font-weight:700">Get a free plan for your business</h2>'
	. '<p data-sc-cs="font-size:16px;line-height:26px">Tell me a little about the business and I will answer with what would help most.</p>'
	// …and the form, which paints nothing of its own.
	. '<form data-sc-cs="display:block;padding:0px">'
	. '<div data-sc-cs="display:block;margin-bottom:16px">'
	. '<label for="n" data-sc-cs="' . $label_cs . '">Business Name</label>'
	. '<input id="n" type="text" name="name" placeholder="A business name" data-sc-cs="' . $input_cs . '">'
	. '</div>'
	. '<div data-sc-cs="display:block;margin-bottom:16px">'
	. '<label for="e" data-sc-cs="' . $label_cs . '">Email Address</label>'
	. '<input id="e" type="email" name="email" placeholder="name@company.com" data-sc-cs="' . $input_cs . '">'
	. '</div>'
	. '<button type="submit" data-sc-cs="display:block;width:100%;height:58px;font-size:16px;font-weight:700;background-color:rgb(60, 90, 220);color:rgb(255,255,255);margin-top:16px;margin-bottom:16px">Get My Free Plan</button>'
	. '<p data-sc-cs="font-size:11px;line-height:16.5px;text-align:center;color:rgba(255,255,255,0.35)">No cost, no obligation. I reply within one business day.</p>'
	. '</form></div></section></main></body></html>';

$nl_res  = FW_Site_Converter_Sources::build_from_html( $nl_html, 'Signup', array( 'dynamic_chrome' => true ) );
$nl_page = $nl_res['files']['pages.json']['pages'][0] ?? array();
$nl      = $find( $nl_page['builder'] ?? array(), 'newsletter' );
$nl_css  = is_array( $nl ) ? (string) ( $nl['atts']['custom_css'] ?? '' ) : '';

echo "\n== A form with no card of its own\n";

$ok( is_array( $nl ), 'the signup converts to a native newsletter node' );
$ok( false === strpos( $nl_css, 'padding:32px' ),
	'the SECTION card inset is not copied onto the form (it already rides the column box)' );
$ok( false === strpos( $nl_css, 'background-color:rgb(13, 22, 44)' ),
	'...nor its fill, which would paint a second identical slab' );

echo "\n== The input IS the field when no wrapper skins it\n";

$ok( false !== strpos( $nl_css, '.fw-nl__input' ),
	'the field carries measured declarations at all' );
$ok( false !== strpos( $nl_css, 'padding:12px 16px' ),
	'...the source inset, not the shortcode default' );
$ok( false !== strpos( $nl_css, 'height:46px' ),
	'...the source field height' );
$ok( false !== strpos( $nl_css, 'border-radius:12px' ),
	'...and the source corner, which the coarse Roundness option can only approximate' );

echo "\n== The label's distance from its input is its own measurement\n";

$ok( false !== strpos( $nl_css, '--nl-label-gap:6px' ),
	'the 6px caption gap is carried separately from the field-to-field gap' );
$ok( false !== strpos( $nl_css, 'gap:16px' ),
	'...and the field-to-field gap is still the source 16px' );

echo "\n== The small print under the submit is the form's own Consent / Fine Print\n";

$nl_atts = is_array( $nl ) ? (array) ( $nl['atts'] ?? array() ) : array();
$ok( false !== strpos( (string) ( $nl_atts['consent_text'] ?? '' ), 'No cost, no obligation' ),
	'the line under the submit lands in consent_text, on the element it qualifies' );
$ok( false !== strpos( $nl_css, 'font-size:11px' ) && false !== strpos( $nl_css, '.fw-nl__consent' ),
	'...at its measured 11px, not the element default' );
$ok( false !== strpos( $nl_css, 'text-align:center' ),
	'...centred as the source draws it' );
$ok( false !== strpos( $nl_css, 'margin-top:16px' ),
	'...and 16px under the button, the collapsed distance the source actually shows' );

// It must not ALSO ride out as a loose text block beside the form, or the line reads twice.
$count_text = function ( $nodes ) use ( &$count_text ) {
	$n = 0;
	foreach ( (array) $nodes as $x ) {
		if ( ! is_array( $x ) ) { continue; }
		$blob = (string) wp_json_encode( $x['atts'] ?? array() );
		if ( 'newsletter' !== ( $x['shortcode'] ?? '' ) && false !== strpos( $blob, 'No cost, no obligation' ) ) { $n++; }
		$n += $count_text( $x['_items'] ?? array() );
	}
	return $n;
};
$nl_dupes = $count_text( $nl_page['builder'] ?? array() );
$ok( 0 === $nl_dupes,
	'NEGATIVE: it is not ALSO emitted as a loose text block (got ' . $nl_dupes . ' copies)' );

echo "\n== The footer draws its OWN lockup\n";

$ft_html = '<!DOCTYPE html><html><head><title>Beacon</title></head><body>'
	. '<header data-sc-cs="' . $ink . 'display:flex;padding:12px 24px">'
	. '<a href="/" data-sc-cs="display:flex"><img src="/mark.png" alt="Beacon" data-sc-cs="width:40px;height:40px;display:block"></a>'
	. '<a href="/about" data-sc-cs="' . $ink . 'display:block">About</a><a href="/work" data-sc-cs="' . $ink . 'display:block">Work</a>'
	. '</header><main>'
	. '<section data-sc-cs="' . $ink . 'padding:80px 24px;display:block"><h1>Beacon</h1><p>Body copy long enough to count as a real paragraph here.</p></section></main>'
	. '<footer data-sc-cs="' . $ink . 'padding:48px 24px;display:flex;flex-direction:column;align-items:center;gap:16px">'
	. '<img src="/wordmark.svg" alt="Beacon" data-sc-cs="width:205px;height:64px;display:block">'
	. '<div data-sc-cs="' . $ink . 'display:flex;flex-wrap:wrap;justify-content:center;gap:24px">'
	. '<a href="/about" data-sc-cs="' . $ink . 'display:block">About</a><a href="/work" data-sc-cs="' . $ink . 'display:block">Work</a>'
	. '<a href="/pricing" data-sc-cs="' . $ink . 'display:block">Pricing</a><a href="/contact" data-sc-cs="' . $ink . 'display:block">Contact</a>'
	. '</div>'
	. '<p data-sc-cs="color:rgba(255,255,255,0.4);font-size:10px;text-transform:uppercase">Find More. Do More.</p>'
	. '<p data-sc-cs="color:rgba(255,255,255,0.3);font-size:10px;text-transform:uppercase">&copy; 2026 Beacon Labs. All Rights Reserved.</p>'
	. '</footer></body></html>';

$ft_v   = FW_Site_Converter_Sources::build_from_html( $ft_html, 'Beacon', array( 'dynamic_chrome' => true ) )['files']['theme-settings.json']['values'] ?? array();
$ft_mfc = $ft_v['main_footer_columns'] ?? array();
$ft_col = (array) ( $ft_mfc[ (string) ( $ft_mfc['count'] ?? '0' ) ]['main_footer_col_1'] ?? array() );
$ft_kinds = array(); foreach ( $ft_col as $e ) { $ft_kinds[] = (string) ( $e['element_type']['element'] ?? '?' ); }
$ft_logo = null; foreach ( $ft_col as $e ) { if ( 'footer_logo' === ( $e['element_type']['element'] ?? '' ) ) { $ft_logo = $e['element_type']['footer_logo']; break; } }

$ok( in_array( 'footer_logo', $ft_kinds, true ),
	'a footer lockup that differs from the header uses the footer_logo element (got: ' . implode( ' ', $ft_kinds ) . ')' );
$ok( is_array( $ft_logo ) && '/wordmark.svg' === (string) ( $ft_logo['footer_logo_image']['url'] ?? '' ),
	'...carrying the footer image, not the header mark' );
$ok( is_array( $ft_logo ) && 205 === (int) ( $ft_logo['footer_logo_width']['value'] ?? 0 ),
	'...at the width the source draws it' );
$ok( 'footer_logo' === (string) ( $ft_kinds[0] ?? '' ),
	'...and it still leads the centred stack, above the links' );

$ft_css = (string) ( $ft_v['misc_custom_css']['custom_css'] ?? '' );
$ok( false !== strpos( $ft_css, '.footer-section--main-footer .footer-column > * + *{margin-top:16px;}' ),
	'the stack keeps the source own 16px rhythm between its rows' );

echo "\n== NEGATIVE: one lockup in both places stays the theme's own logo element\n";

$same = str_replace( '/wordmark.svg', '/mark.png', $ft_html );
$same = str_replace( 'width:205px;height:64px', 'width:120px;height:40px', $same );
$sv   = FW_Site_Converter_Sources::build_from_html( $same, 'Beacon', array( 'dynamic_chrome' => true ) )['files']['theme-settings.json']['values'] ?? array();
$smfc = $sv['main_footer_columns'] ?? array();
$scol = (array) ( $smfc[ (string) ( $smfc['count'] ?? '0' ) ]['main_footer_col_1'] ?? array() );
$skinds = array(); foreach ( $scol as $e ) { $skinds[] = (string) ( $e['element_type']['element'] ?? '?' ); }
$ok( ! in_array( 'footer_logo', $skinds, true ),
	'NEGATIVE: the same image in header and footer keeps the shared `logo` element (got: ' . implode( ' ', $skinds ) . ')' );

echo "\n== NEGATIVE: a badge in the footer chrome is not the brand\n";

$badge = str_replace(
	'<img src="/wordmark.svg" alt="Beacon" data-sc-cs="width:205px;height:64px;display:block">',
	'<div data-sc-cs="display:flex;gap:8px;padding:8px 14px;border-radius:12px"><img src="https://cdn.example.net/built-with.svg" alt="Built with" data-sc-cs="display:block"><span data-sc-cs="font-size:12px">Built with</span></div>',
	$ft_html
);
$bv   = FW_Site_Converter_Sources::build_from_html( $badge, 'Beacon', array( 'dynamic_chrome' => true ) )['files']['theme-settings.json']['values'] ?? array();
$bmfc = $bv['main_footer_columns'] ?? array();
$bcol = (array) ( $bmfc[ (string) ( $bmfc['count'] ?? '0' ) ]['main_footer_col_1'] ?? array() );
$bkinds = array(); foreach ( $bcol as $e ) { $bkinds[] = (string) ( $e['element_type']['element'] ?? '?' ); }
$ok( ! in_array( 'footer_logo', $bkinds, true ),
	'NEGATIVE: an unmeasured badge image is not claimed as the footer lockup (got: ' . implode( ' ', $bkinds ) . ')' );

echo $fails ? "\n\xe2\x9c\x97 $fails FAILED\n" : "\n\xe2\x9c\x93 ALL PASS - the signup keeps its own box and the footer keeps its own lockup\n";
exit( $fails ? 1 : 0 );
