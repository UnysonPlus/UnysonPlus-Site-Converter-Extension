<?php
/**
 * Regression guard: a form inside a GRID CELL is decomposed, not mirrored verbatim.
 *
 * The converter already recognises a contact form (`is_contact_form`) and already builds a complete native
 * block from it (`contact_form_build` -> the forms extension's `contact_form`, fields, labels, submit, field
 * and button skins). Neither ran on a two-column contact section, because the cell walker never reached the
 * form: a cell no earlier branch claims falls through to the verbatim `.sc-tw` mirror, and WordPress then
 * strips `<form>`, `<input>`, `<textarea>` and `<button>` out of builder content.
 *
 * Measured on a captured contact page: a `grid md:grid-cols-2` whose left cell is the form and whose right
 * cell is the address block. The right cell decomposed into six blocks; the left came back as 10,919 bytes of
 * `html`, and the page rendered the four LABELS as a bare stack of text with no fields and no submit.
 * `is_contact_form()` returned true for that very form the whole time, and `contact_form_build()` produced
 * four fields and the "Send" label when called directly.
 *
 * The file already states the principle for media: "a MEDIA cell that a native recognizer owns -> its native
 * block, never the verbatim mirror". A form is the same case, and the more costly one to get wrong: a contact
 * page without a working form has lost the only thing it is for.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/source-form-becomes-a-contact-form-test.php"
 *
 * Exit code 0 = all PASS, 1 = at least one FAIL.
 */

if ( ! class_exists( 'FW_Site_Converter_Stitch' ) ) {
	fwrite( STDERR, "FAIL: site-converter stitch not loaded\n" );
	exit( 1 );
}

$fails = 0;
$ok    = function ( $cond, $msg ) use ( &$fails ) {
	if ( $cond ) { echo "  \xe2\x9c\x93 $msg\n"; } else { $fails++; echo "  \xe2\x9c\x97 FAIL: $msg\n"; }
};

$FORM = '<form class="space-y-8">'
	. '<div><label class="block text-sm" data-sc-cs="font-size:14px">Name</label>'
		. '<input required type="text" placeholder="Type your name here" data-sc-cs="font-size:14px"></div>'
	. '<div><label class="block text-sm" data-sc-cs="font-size:14px">E-mail address</label>'
		. '<input required type="email" placeholder="Type your emailaddress here" data-sc-cs="font-size:14px"></div>'
	. '<div><label class="block text-sm" data-sc-cs="font-size:14px">Phone number</label>'
		. '<input required type="tel" placeholder="Type your phone number here" data-sc-cs="font-size:14px"></div>'
	. '<div><label class="block text-sm" data-sc-cs="font-size:14px">Message</label>'
		. '<textarea required rows="3" placeholder="Type your message here" data-sc-cs="font-size:14px"></textarea></div>'
	. '<button type="submit" data-sc-cs="background-color:rgb(130, 121, 230)">Send</button>'
	. '</form>';

$ASIDE = '<div data-sc-cs="padding:64px 0px"><h2 data-sc-cs="font-size:24px">Visit us</h2>'
	. '<p data-sc-cs="font-size:14px">1250 Market Street, Suite 400</p>'
	. '<p data-sc-cs="font-size:14px">San Francisco, CA 94102</p></div>';

$page = '<!DOCTYPE html><html><body><main>'
	. '<section data-sc-cs="background-color:rgb(255,255,255);padding:80px 0px">'
	. '<div data-sc-cs="display:block"><div data-sc-cs="display:grid;grid-template-columns:636px 636px;gap:0px">'
	. '<div data-sc-cs="padding:64px 0px">' . $FORM . '</div>'
	. $ASIDE
	. '</div></div></section></main></body></html>';

$map = FW_Site_Converter_Stitch::html_to_mapping( $page, 'Contact', 'contact', true );
$json = json_encode( $map );

$find = function ( $n ) use ( &$find ) {
	if ( ! is_array( $n ) ) { return null; }
	if ( 'contact_form' === ( $n['t'] ?? '' ) ) { return $n; }
	foreach ( $n as $v ) {
		if ( is_array( $v ) ) {
			$r = $find( $v );
			if ( $r ) { return $r; }
		}
	}
	return null;
};

echo "\n== The measured shape: a two-column section, form on the left\n";

$blk = $find( $map );
$ok( is_array( $blk ), 'the form cell is decomposed into a native contact_form block' );

if ( is_array( $blk ) ) {
	$fields = (array) ( $blk['fields'] ?? array() );
	$ok( 4 === count( $fields ), 'all four fields survive (' . count( $fields ) . ')' );
	$types  = array();
	$labels = array();
	foreach ( $fields as $f ) {
		$types[]  = (string) ( $f['type'] ?? '' );
		$labels[] = (string) ( $f['label'] ?? '' );
	}
	$ok( in_array( 'email', $types, true ), 'the e-mail input keeps its type: ' . implode( ', ', $types ) );
	$ok( in_array( 'textarea', $types, true ), 'and the message is a textarea' );
	$ok( in_array( 'E-mail address', $labels, true ), 'the labels come from the source: ' . implode( ' | ', $labels ) );
	$ok( 'Send' === trim( (string) ( $blk['submit'] ?? '' ) ), 'the submit label is the one the source used' );
}

echo "\n== …and the form is no longer mirrored as verbatim HTML\n";

$ok( false === strpos( $json, 'Type your name here' ) || is_array( $blk ),
	'the raw placeholder text is not what carries the form' );
$ok( false === strpos( $json, 'sc-tw' ) || false === strpos( $json, '<form' ),
	'no `.sc-tw` cell holds a raw <form> (WordPress would strip its controls)' );

echo "\n== A transparent field keeps its transparency\n";

// The capture stamps no `background-color` at all when it resolves to transparent, so the CLASS is where the
// source states it. Without this the theme's white input sat on the source's tinted band -- measured on a
// captured contact page, `bg-transparent` underline inputs rendered as white boxes.
$cf = new ReflectionMethod( 'FW_Site_Converter_Mapper', 'n_contact_form' );
$cf->setAccessible( true );
$two = array(
	array( 'type' => 'text',  'label' => 'Name',  'required' => true ),
	array( 'type' => 'email', 'label' => 'Email', 'required' => true ),
);

$tnode = $cf->invoke( null, array(
	'fields'    => $two,
	'submit'    => 'Send',
	'field_cs'  => 'border-bottom-width:1px;border-bottom-style:solid;border-bottom-color:rgba(12, 31, 64, 0.2);padding:8px 0px',
	'field_cls' => 'w-full border-0 border-b bg-transparent px-0 py-2',
) );
// The skin now rides a Form Styles preset (form-skin-is-a-form-style-preset-test.php), so the transparency is
// read there, on the preset the form points at.
$tfs   = is_array( $tnode ) ? (string) ( $tnode['atts']['form_style'] ?? '' ) : '';
$tbg   = '';
foreach ( FW_Site_Converter_Mapper::captured( 'form_style' ) as $slug => $sk ) {
	if ( 'formp-' . $slug === $tfs ) { $tbg = (string) ( $sk['field']['background-color'] ?? '' ); }
}
$ok( 'transparent' === $tbg, 'the transparent field background is carried (on its Form Style preset)' );

$onode = $cf->invoke( null, array(
	'fields'    => $two,
	'submit'    => 'Send',
	'field_cs'  => 'border-bottom-width:1px;padding:8px 0px',
	'field_cls' => 'w-full rounded border px-3 py-2',
) );
$ofs = is_array( $onode ) ? (string) ( $onode['atts']['form_style'] ?? '' ) : '';
$obg = '';
foreach ( FW_Site_Converter_Mapper::captured( 'form_style' ) as $slug => $sk ) {
	if ( 'formp-' . $slug === $ofs ) { $obg = (string) ( $sk['field']['background-color'] ?? '' ); }
}
$ok( 'transparent' !== $obg, 'and a boxed field is not made transparent' );

echo "\n== NEGATIVE: the sibling cell is unaffected\n";

$ok( false !== strpos( $json, 'Visit us' ), 'the address column still converts' );
$ok( false !== strpos( $json, '1250 Market Street' ), '…with its address lines' );

echo "\n== NEGATIVE: a SEARCH form is not claimed\n";

$search = '<!DOCTYPE html><html><body><main><section data-sc-cs="padding:40px 0px">'
	. '<div data-sc-cs="display:grid;grid-template-columns:400px 400px">'
	. '<div><form role="search"><input type="search" placeholder="Search"><button type="submit">Go</button></form></div>'
	. '<div><p data-sc-cs="font-size:14px">Some copy</p></div>'
	. '</div></section></main></body></html>';
$ok( null === $find( FW_Site_Converter_Stitch::html_to_mapping( $search, 'S', 's', true ) ),
	'a one-field search form is not converted into a contact form' );

echo "\n== NEGATIVE: a page with no form produces none\n";

$plain = '<!DOCTYPE html><html><body><main><section data-sc-cs="padding:40px 0px">'
	. '<div data-sc-cs="display:grid;grid-template-columns:400px 400px">'
	. '<div><h2 data-sc-cs="font-size:24px">Hello</h2></div><div><p data-sc-cs="font-size:14px">Copy.</p></div>'
	. '</div></section></main></body></html>';
$ok( null === $find( FW_Site_Converter_Stitch::html_to_mapping( $plain, 'P', 'p', true ) ), 'no form is invented' );

printf( "\n%s\n", $fails ? "{$fails} FAIL(S)" : 'ALL PASS' );
exit( $fails ? 1 : 0 );
