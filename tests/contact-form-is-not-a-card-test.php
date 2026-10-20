<?php
/**
 * Regression guard: a cell holding a CONTACT FORM is not claimed as a card.
 *
 * WHY THIS FILE EXISTS. Measured on a real six-page conversion, one page scored 70.9% text coverage — the
 * worst of the six — and ten of its sixteen missing strings were a single contact form:
 *
 *   Your Name · Email Address · Topic / Service Line · Your Message      (the field labels)
 *   General Service Inquiry · Residential Recurring Service · …          (the select's options)
 *   Get in Touch with Our Concierge Team · its intro paragraph           (the band's own heading)
 *
 * The form was NOT unrecognised. `is_contact_form()` claims it correctly. What happened is that the cell
 * holding it was claimed FIRST by `card_from_cell()`, which read the form panel's `<h3>Send an Inquiry</h3>`
 * as a card title and produced an icon_box carrying that title and nothing else. Every field, label, option
 * and the submit button went with it, and because a recognizer that claims a container never reaches the
 * walker underneath, nothing downstream could notice.
 *
 * This is the failure the code comments elsewhere already name — "card_from_cell() would claim it by the
 * first item's heading, dropping the label AND the other items" — met one layer further out.
 *
 * THE RULE: a card is a title plus some prose. A form is an interactive thing the form recognizer owns. When
 * a cell contains a form that `is_contact_form()` claims, `card_from_cell()` stands down.
 *
 * Deliberately narrow: the test is the RECOGNIZER's own verdict, not "does this cell contain an input". A
 * newsletter card — a heading, a line of copy and one email field — is still a card, and must stay one.
 *
 * The fixture is a reduced real capture (57 KB → 9.7 KB), trimmed under a predicate that required
 * `is_contact_form()` to keep claiming the form. Two earlier trims passed a weaker predicate by deleting the
 * form's controls, which made the fixture prove nothing — the reason the invariant belongs in the predicate.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/contact-form-is-not-a-card-test.php"
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

$fixture = __DIR__ . '/fixtures/contact-form-in-a-card-cell.html';
if ( ! is_file( $fixture ) ) { fwrite( STDERR, "FAIL: fixture missing\n" ); exit( 1 ); }
$html = file_get_contents( $fixture );

echo "\n== The fixture still poses the question\n";

// If the recognizer stops claiming this form the fixture has drifted and everything below proves nothing,
// so it is asserted rather than assumed.
$dom = new DOMDocument();
libxml_use_internal_errors( true );
$dom->loadHTML( '<?xml encoding="utf-8"?>' . $html );
libxml_clear_errors();
$form = ( new DOMXPath( $dom ) )->query( '//form' )->item( 0 );
$icf  = new ReflectionMethod( 'FW_Site_Converter_Stitch', 'is_contact_form' );
$icf->setAccessible( true );
$ok( $form instanceof DOMElement, 'the fixture contains a form' );
$ok( $form instanceof DOMElement && $icf->invoke( null, $form, 'form' ), 'and is_contact_form() claims it' );

echo "\n== The form reaches the form recognizer\n";

$map  = FW_Site_Converter_Stitch::html_to_mapping( $html, 'Contact', 'contact', true );
$blob = wp_json_encode( $map );

// The two invariants of the defect, asserted directly. (Not "does the label `Your Name` survive": reducing
// the capture far enough to isolate the swallow also removes that input, and an assertion that passes for
// the wrong reason is worse than none. The fixture was trimmed under a predicate requiring BOTH of these.)
$ok( false !== strpos( $blob, 'contact_form' ), 'a contact_form block is produced' );

$swallowed = false;
$walk = function ( $v ) use ( &$walk, &$swallowed ) {
	if ( ! is_array( $v ) ) { return; }
	if ( isset( $v['card'] ) && is_array( $v['card'] ) ) {
		$t = trim( (string) ( $v['card']['title'] ?? '' ) );
		if ( 'Send an Inquiry' === $t ) { $swallowed = true; }
	}
	foreach ( $v as $x ) { $walk( $x ); }
};
$walk( $map );
$ok( ! $swallowed, 'and the panel did NOT become a card titled after the form\'s own heading' );

echo "\n== …and the form it builds actually works\n";

// Found by LOOKING at the rendered page, which is the only way it could have been found: the form converted,
// and then printed four PHP warnings on the front end —
//
//   Warning: Array to string conversion in …/form-builder/items/select/…-item-select.php on line 210
//
// — one of them rendered INSIDE the select's first option, where a visitor reads it. The select item casts
// each choice with `(string) $choice`, because a choice is a plain string: its option is an `addable-option`
// of `type => text`, and the item's own default is `$choices[] = __( 'Dropdown' )`. The mapper was wrapping
// each one as `array( 'label' => $c, 'value' => $c )`, so every choice tripped the cast.
//
// The converter's own block was already right — it emits a flat list of strings — so this is purely the
// mapper inventing a richer shape than the option type has.
// Asserted on its own small form, because the reduced fixture above has no <select> left — the trim that
// isolated the swallow removed it, and an assertion that cannot find its subject proves nothing.
$selform = '<!DOCTYPE html><html><body><main>'
	. '<section data-sc-cs="background-color:rgb(7,30,61);padding:80px 0px;display:block">'
	. '<div data-sc-cs="padding:24px;display:block"><h3 data-sc-cs="font-size:18px;font-weight:700">Send an Inquiry</h3>'
	. '<form data-sc-cs="display:block">'
	. '<label data-sc-cs="font-size:12px;display:block">Your Name</label><input type="text" placeholder="Jane Doe">'
	. '<label data-sc-cs="font-size:12px;display:block">Email Address</label><input type="email" placeholder="jane@company.com">'
	. '<label data-sc-cs="font-size:12px;display:block">Topic / Service Line</label>'
	. '<select><option>General Service Inquiry</option><option>Residential Recurring Service</option></select>'
	. '<label data-sc-cs="font-size:12px;display:block">Your Message</label><textarea placeholder="Tell us more..."></textarea>'
	. '<button type="submit">Submit Inquiry</button>'
	. '</form></div></section></main></body></html>';
$pages = FW_Site_Converter_Mapper::build_pages(
	FW_Site_Converter_Stitch::html_to_mapping( $selform, 'S', 's', true )
);
$sel_choices = null;
$fwalk = function ( $v ) use ( &$fwalk, &$sel_choices ) {
	if ( ! is_array( $v ) ) { return; }
	if ( 'select' === ( $v['type'] ?? '' ) && isset( $v['options']['choices'] ) ) {
		$sel_choices = $v['options']['choices'];
	}
	foreach ( $v as $x ) { $fwalk( $x ); }
};
// The form's items ride as a JSON string inside the shortcode's atts, so decode any that look like one.
$jwalk = function ( $v ) use ( &$jwalk, &$fwalk ) {
	if ( is_string( $v ) && '' !== $v && ( '[' === $v[0] || '{' === $v[0] ) ) {
		$d = json_decode( $v, true );
		if ( is_array( $d ) ) { $fwalk( $d ); }
		return;
	}
	if ( ! is_array( $v ) ) { return; }
	$fwalk( $v );
	foreach ( $v as $x ) { $jwalk( $x ); }
};
$jwalk( $pages );

$ok( is_array( $sel_choices ) && ! empty( $sel_choices ), 'the select field carries choices' );
if ( is_array( $sel_choices ) && ! empty( $sel_choices ) ) {
	$all_scalar = true;
	foreach ( $sel_choices as $c ) { if ( ! is_scalar( $c ) ) { $all_scalar = false; break; } }
	$ok( $all_scalar, 'every choice is a plain string, as the option type requires (got: '
		. gettype( reset( $sel_choices ) ) . ')' );
}

echo "\n== NEGATIVE: an ordinary card is still a card\n";

// The guard keys on the RECOGNIZER's verdict, so a panel with a heading and prose — and no form — must be
// unaffected. Without this, "stand down near anything form-shaped" would quietly delete real cards.
$plain = '<!DOCTYPE html><html><body><main>'
	. '<section data-sc-cs="background-color:rgb(255,255,255);padding:80px 0px;display:block">'
	. '<div data-sc-cs="display:grid;grid-template-columns:384px 384px;gap:32px">'
	. '<div data-sc-cs="padding:24px;border-radius:12px;display:block">'
		. '<h3 data-sc-cs="font-size:20px;font-weight:700;line-height:28px">Trained Staff</h3>'
		. '<p data-sc-cs="font-size:14px;line-height:20px">Every member is background checked and insured.</p></div>'
	. '<div data-sc-cs="padding:24px;border-radius:12px;display:block">'
		. '<h3 data-sc-cs="font-size:20px;font-weight:700;line-height:28px">Eco Products</h3>'
		. '<p data-sc-cs="font-size:14px;line-height:20px">Non-toxic supplies that care for your space.</p></div>'
	. '</div></section></main></body></html>';
$pblob = wp_json_encode( FW_Site_Converter_Stitch::html_to_mapping( $plain, 'P', 'p', true ) );
$ok( false !== strpos( $pblob, '"card"' ), 'a heading-plus-prose panel is still a card' );
$ok( false !== strpos( $pblob, 'Trained Staff' ), '…carrying its title' );

echo "\n== NEGATIVE: a newsletter card keeps its card\n";

// One email field, a heading and a line of copy: is_contact_form() does NOT claim this (it wants a message
// textarea, or three-plus text inputs, plus a submit), so the card must survive.
$news = '<!DOCTYPE html><html><body><main>'
	. '<section data-sc-cs="background-color:rgb(255,255,255);padding:80px 0px;display:block">'
	. '<div data-sc-cs="display:grid;grid-template-columns:384px 384px;gap:32px">'
	. '<div data-sc-cs="padding:24px;display:block"><h3 data-sc-cs="font-size:20px;font-weight:700">Stay in Touch</h3>'
		. '<p data-sc-cs="font-size:14px;line-height:20px">Monthly tips, no noise.</p>'
		. '<form><input type="email" placeholder="Enter your email"><button type="submit">Join</button></form></div>'
	. '<div data-sc-cs="padding:24px;display:block"><h3 data-sc-cs="font-size:20px;font-weight:700">Our Promise</h3>'
		. '<p data-sc-cs="font-size:14px;line-height:20px">We never share your address.</p></div>'
	. '</div></section></main></body></html>';
$ndom = new DOMDocument();
libxml_use_internal_errors( true );
$ndom->loadHTML( '<?xml encoding="utf-8"?>' . $news );
libxml_clear_errors();
$nform = ( new DOMXPath( $ndom ) )->query( '//form' )->item( 0 );
$ok( ! $icf->invoke( null, $nform, 'form' ), 'is_contact_form() does NOT claim a newsletter signup' );
$nblob = wp_json_encode( FW_Site_Converter_Stitch::html_to_mapping( $news, 'N', 'n', true ) );
$ok( false !== strpos( $nblob, 'Stay in Touch' ), '…so its panel keeps its heading' );

printf( "\n%s\n", $fails ? "{$fails} FAIL(S)" : 'ALL PASS' );
exit( $fails ? 1 : 0 );
