<?php
/**
 * Regression guard: a form's submit is the button that DECLARES it submits, and a labelled row of
 * `type="button"` toggles is a single-choice field rather than four runs to throw away.
 *
 * WHY THIS FILE EXISTS. `contact_form_build()` claims the whole `<form>`, so it answers for every run
 * inside one. It took the submit to be the FIRST button carrying text, and read the button SKIN off the
 * same element. A form that ends
 *
 *     <span>Matter Urgency:</span>
 *     <button type="button" class="bg-black text-white">Standard</button>
 *     <button type="button">Urgent (Trial / Injunction)</button>
 *     <button type="button">Cross-Border Arbitration</button>
 *     ...
 *     <button type="submit">SUBMIT CONFIDENTIALLY</button>
 *
 * therefore published a submit button labelled "Standard", wearing the CHIP's 70px skin where the source
 * has a full-bleed black bar -- and the words SUBMIT CONFIDENTIALLY, the urgency label and two of the
 * three choices never appeared at all. Four visible runs gone, and a choice the visitor could no longer
 * make.
 *
 * `type="button"` exists precisely to say "this one does not submit", so honouring it is translation, not
 * a heuristic. 2 of the 24 forms in the corpus put their submit after another button. A bare `<button>`
 * still defaults to submit, which the fallback below pins.
 *
 * NOTHING NEW WAS ADDED TO THE PRODUCT FOR THE CHIPS. The form builder already has a `radio` field with
 * `choices` -- which is what a row of mutually exclusive toggles is -- so this only translates the source
 * onto a field that was already there. The negatives are the real content of this file: an unlabelled chip
 * row is a toolbar, a lone button is an action, and a `submit` among the chips disqualifies the group.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/form-submit-is-not-the-first-button-test.php"
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

$ref  = new ReflectionClass( 'FW_Site_Converter_Stitch' );
$meth = function ( $n ) use ( $ref ) { $m = $ref->getMethod( $n ); $m->setAccessible( true ); return $m; };
$chip = $meth( 'form_chip_group' );

$el = function ( $html, $id ) {
	$d = new DOMDocument();
	libxml_use_internal_errors( true );
	$d->loadHTML( '<?xml encoding="utf-8" ?>' . $html );
	libxml_clear_errors();
	return $d->getElementById( $id );
};

$fixture = __DIR__ . '/fixtures/form-submit-is-not-the-first-button.html';
if ( ! is_file( $fixture ) ) { fwrite( STDERR, "FAIL: fixture missing\n" ); exit( 1 ); }
$html = (string) file_get_contents( $fixture );

/** The built contact_form node for this fixture. */
FW_Site_Converter_Mapper::set_style_config( array() );
$pages = FW_Site_Converter_Mapper::build_pages( FW_Site_Converter_Stitch::html_to_mapping( $html, 'I', 'i', true ) );
$form  = null;
$walk  = function ( $x ) use ( &$walk, &$form ) {
	if ( ! is_array( $x ) ) { return; }
	foreach ( $x as $v ) {
		if ( ! is_array( $v ) ) { continue; }
		if ( 'contact_form' === ( $v['shortcode'] ?? '' ) && null === $form ) { $form = $v; }
		$walk( $v['_items'] ?? $v );
	}
};
$walk( $pages );

echo "\n== The submit is the button that says it submits\n";

$ok( is_array( $form ), 'the form converts to a contact_form' );
$submit = is_array( $form ) ? (string) ( $form['atts']['submit_button_text'] ?? '' ) : '';
$ok( 'SUBMIT CONFIDENTIALLY' === $submit,
	sprintf( 'its label is the submit\'s own (got %s)', wp_json_encode( $submit ) ) );
$ok( 'Standard' !== $submit, 'and NOT the first chip\'s label, which is what it used to be' );

echo "\n== The chip row is one single-choice field, in document order\n";

$items = is_array( $form ) ? (array) json_decode( (string) ( $form['atts']['form']['json'] ?? '' ), true ) : array();
$types = array(); $radio = null;
foreach ( $items as $it ) {
	$t = (string) ( $it['type'] ?? '' );
	if ( 'form-header-title' === $t ) { continue; }
	$types[] = $t;
	if ( 'radio' === $t && null === $radio ) { $radio = $it; }
}
$ok( array( 'text', 'email', 'textarea', 'radio' ) === $types,
	'the fields are text, email, textarea, radio -- the radio LAST, where the source puts it (got ' . implode( ',', $types ) . ')' );
$ok( is_array( $radio ) && 'Matter Urgency' === (string) ( $radio['options']['label'] ?? '' ),
	'labelled from the leaf beside the row, with the trailing colon dropped' );
$ok( is_array( $radio ) && array( 'Standard', 'Urgent (Trial / Injunction)', 'Cross-Border Arbitration' )
	=== (array) ( $radio['options']['choices'] ?? array() ),
	'carrying all three choices in source order' );
// The chip the source FILLS is the one already chosen. A plain `rgb(0, 0, 0)` fill reads as a zero alpha
// to a naive trailing-", 0)" test, which is exactly the fill this source uses -- so this pins the value.
$ok( is_array( $radio ) && 'Standard' === (string) ( $radio['options']['default_value'] ?? '' ),
	sprintf( 'and the filled chip is the default (got %s)', wp_json_encode( $radio['options']['default_value'] ?? null ) ) );

echo "\n== NEGATIVE: the chip shapes form_chip_group() must NOT claim\n";

// An UNLABELLED row of chips is a filter bar / toolbar, not a field: there is no question to answer.
$nolabel = '<div id="G" data-sc-cs="display:flex"><button type="button" data-sc-cs="display:block">All</button>'
	. '<button type="button" data-sc-cs="display:block">Litigation</button></div>';
$ok( null === $chip->invoke( null, $el( $nolabel, 'G' ) ), 'an unlabelled chip row is not a field' );

// ONE button is an action, not a choice.
$one = '<div data-sc-cs="display:flex"><span data-sc-cs="display:block">Urgency:</span>'
	. '<div id="G" data-sc-cs="display:flex"><button type="button" data-sc-cs="display:block">Standard</button></div></div>';
$ok( null === $chip->invoke( null, $el( $one, 'G' ) ), 'a single button is an action, not a choice' );

// A SUBMIT among them means the row does something rather than selects something.
$withsubmit = '<div data-sc-cs="display:flex"><span data-sc-cs="display:block">Urgency:</span>'
	. '<div id="G" data-sc-cs="display:flex"><button type="button" data-sc-cs="display:block">Standard</button>'
	. '<button type="submit" data-sc-cs="display:block">Send</button></div></div>';
$ok( null === $chip->invoke( null, $el( $withsubmit, 'G' ) ), 'a submit among the chips disqualifies the row' );

// A container that MIXES chips with other content is not a chip group -- claiming it would eat the rest.
$mixed = '<div data-sc-cs="display:flex"><span data-sc-cs="display:block">Urgency:</span>'
	. '<div id="G" data-sc-cs="display:flex"><button type="button" data-sc-cs="display:block">Standard</button>'
	. '<button type="button" data-sc-cs="display:block">Urgent</button>'
	. '<p data-sc-cs="display:block">Response within 24 hours.</p></div></div>';
$ok( null === $chip->invoke( null, $el( $mixed, 'G' ) ), 'a row mixing chips with other content is not claimed' );

// A bare <button> defaults to SUBMIT, so a row of them is not a choice either.
$bare = '<div data-sc-cs="display:flex"><span data-sc-cs="display:block">Urgency:</span>'
	. '<div id="G" data-sc-cs="display:flex"><button data-sc-cs="display:block">Standard</button>'
	. '<button data-sc-cs="display:block">Urgent</button></div></div>';
$ok( null === $chip->invoke( null, $el( $bare, 'G' ) ), 'bare <button>s default to submit and are not chips' );

printf( "\n%s\n", $fails ? "{$fails} FAIL(S)" : 'ALL PASS' );
exit( $fails ? 1 : 0 );
