<?php
/**
 * Regression guard: a source form's FIELD and LABEL skin becomes a Form Styles preset, not element CSS.
 *
 * WHY THIS FILE EXISTS. The converter measured a contact form's inputs and labels correctly (an underline field on
 * a transparent ground, a 10px uppercase label) and then wrote all of it into the contact_form element's own
 * Custom CSS with !important. That is the per-element copy the house rule forbids for buttons and boxes: a second
 * form on the same converted site did not match the first, the Newsletter element could not share it, and editing
 * the site's form style in Theme Settings → Components → Form Styles reached none of them, because the element's
 * !important outranked the preset.
 *
 * THE RULE: the skin registers a Form Styles preset (Mapper::register_form_style → captured('form_style')), the
 * theme-settings pass emits it into `form_style_presets` (Stitch::build_form_style_presets), and the form picks it
 * with `form_style = formp-form-<hash>`. Nothing of the field or label skin stays on the element. The same skin on
 * two forms is ONE preset; a form whose source stamped no skin gets none (and keeps the site default).
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/form-skin-is-a-form-style-preset-test.php"
 *
 * Exit code 0 = all PASS, 1 = at least one FAIL.
 */

if ( ! class_exists( 'FW_Site_Converter_Mapper' ) || ! class_exists( 'FW_Site_Converter_Stitch' ) ) {
	fwrite( STDERR, "FAIL: site-converter not loaded\n" );
	exit( 1 );
}

$fails = 0;
$ok    = function ( $cond, $msg ) use ( &$fails ) {
	if ( $cond ) { echo "  \xe2\x9c\x93 $msg\n"; } else { $fails++; echo "  \xe2\x9c\x97 FAIL: $msg\n"; }
};

FW_Site_Converter_Mapper::$registry = array();
$cf = new ReflectionMethod( 'FW_Site_Converter_Mapper', 'n_contact_form' );
$cf->setAccessible( true );
$fields = array(
	array( 'type' => 'text',  'label' => 'Name',  'required' => true ),
	array( 'type' => 'email', 'label' => 'Email', 'required' => true ),
);
$skin = array(
	'fields'    => $fields,
	'submit'    => 'Send',
	'field_cs'  => 'border-bottom-width:1px;border-bottom-style:solid;border-bottom-color:rgba(12, 31, 64, 0.2);padding:8px 0px;font-size:15px;color:rgb(12, 31, 64)',
	'field_cls' => 'w-full border-0 border-b bg-transparent px-0 py-2',
	'label_cs'  => 'font-size:10px;text-transform:uppercase;letter-spacing:2px;color:rgb(90, 98, 110);font-weight:600',
);

echo "\n== A measured field skin points the form at a Form Styles preset\n";

$node  = $cf->invoke( null, $skin );
$atts  = is_array( $node ) ? (array) $node['atts'] : array();
$class = (string) ( $atts['form_style'] ?? '' );
$css   = (string) ( $atts['custom_css'] ?? '' );
$ok( 1 === preg_match( '/^formp-form-[0-9a-f]{8}$/', $class ), 'the form wears a converted preset: ' . ( '' !== $class ? $class : '(none)' ) );
$ok( false === strpos( $css, 'selector input' ) && false === strpos( $css, 'selector label' ), 'no field or label skin is left on the element (' . strlen( $css ) . ' bytes of custom_css)' );
$ok( false === strpos( $css, 'background-color:transparent' ), 'the transparent ground moved too' );

$reg = FW_Site_Converter_Mapper::captured( 'form_style' );
$ok( 1 === count( $reg ), 'one preset registered (' . count( $reg ) . ')' );

echo "\n== The theme-settings pass emits it, named so its class resolves\n";

$lib  = FW_Site_Converter_Stitch::build_form_style_presets();
$list = (array) ( $lib['form_style_presets'] ?? array() );
$mine = null;
foreach ( $list as $p ) {
	$slug = trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) ( $p['style_name'] ?? '' ) ) ), '-' );
	if ( 'formp-' . $slug === $class ) { $mine = $p; }
}
$ok( is_array( $mine ), 'a preset in form_style_presets slugifies to the class the form wears' );
if ( is_array( $mine ) ) {
	$ok( 'bottom' === $mine['border_sides'], 'the underline field keeps one bottom edge (' . $mine['border_sides'] . ')' );
	$ok( 'transparent' === $mine['field_bg']['custom'], 'on a transparent ground (' . $mine['field_bg']['custom'] . ')' );
	$ok( '1' === (string) $mine['border_width']['value'], 'a 1px rule (' . $mine['border_width']['value'] . $mine['border_width']['unit'] . ')' );
	$ok( false !== strpos( $mine['field_border']['custom'], '0.2' ), 'in the measured colour (' . $mine['field_border']['custom'] . ')' );
	$ok( '10' === (string) $mine['label_size']['value'] && 'upper' === $mine['label_case'] && '600' === $mine['label_weight'], 'the 10px uppercase 600 label' );
	$ok( false !== strpos( $mine['custom_css'], 'letter-spacing:2px' ), 'what the fields cannot hold rides the preset\'s Custom CSS' );
	$ok( false !== strpos( $mine['custom_css'], '{{SELECTOR}}' ), '…scoped to the preset' );
}
$ok( count( $list ) > 1, 'the shipped presets ride along (' . count( $list ) . ' in the library)' );

echo "\n== The same skin on a second form is the same preset\n";

$node2 = $cf->invoke( null, $skin );
$ok( (string) ( $node2['atts']['form_style'] ?? '' ) === $class, 'a second identical form wears the same class' );
$ok( 1 === count( FW_Site_Converter_Mapper::captured( 'form_style' ) ), 'and no second preset is made' );

$other = $skin;
$other['field_cs'] = 'border-top-width:1px;border-right-width:1px;border-bottom-width:1px;border-left-width:1px;border-top-style:solid;border-top-color:rgb(200, 200, 200);border-radius:12px;background-color:rgb(250, 250, 250)';
$node3 = $cf->invoke( null, $other );
$ok( (string) ( $node3['atts']['form_style'] ?? '' ) !== $class && '' !== (string) ( $node3['atts']['form_style'] ?? '' ), 'a different skin is a different preset' );

echo "\n== A CLASS-ONLY underline (no width stamped) keeps its rule and its vertical padding\n";

// Measured on a captured contact form: the inputs are `border-b border-border py-3 bg-transparent`. The capture stamps
// only a preflight `border-top-style:solid;border-top-color:…` (no width anywhere), so the stamp alone said "no
// border" and the converted field lost its underline; the vertical padding was dropped because only an underline
// skin carried it. The class names the edge, the stamped colour is its colour.
FW_Site_Converter_Mapper::$registry = array();
$cls  = $cf->invoke( null, array(
	'fields'    => $fields,
	'submit'    => 'Send',
	'field_cs'  => 'color:rgb(250, 250, 250);font-size:18px;padding:12px 0px;border-top-style:solid;border-top-color:rgb(38, 38, 38);border-radius:0px',
	'field_cls' => 'w-full bg-transparent border-b border-border py-3 text-lg',
) );
$cp = null;
foreach ( (array) ( FW_Site_Converter_Stitch::build_form_style_presets()['form_style_presets'] ?? array() ) as $p ) {
	if ( 'formp-' . trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) ( $p['style_name'] ?? '' ) ) ), '-' ) === (string) ( $cls['atts']['form_style'] ?? '' ) ) { $cp = $p; }
}
$ok( is_array( $cp ) && 'bottom' === $cp['border_sides'] && '1' === (string) $cp['border_width']['value'], 'the `border-b` class draws one 1px bottom edge' );
$ok( is_array( $cp ) && 'rgb(38, 38, 38)' === (string) $cp['field_border']['custom'], '…in the stamped border colour (' . ( is_array( $cp ) ? $cp['field_border']['custom'] : '' ) . ')' );
$ok( is_array( $cp ) && false !== strpos( (string) $cp['custom_css'], 'padding-top:12px' ), 'the 12px vertical padding is carried' );

FW_Site_Converter_Mapper::$registry = array();
$boxed = $cf->invoke( null, array(
	'fields'    => $fields,
	'submit'    => 'Send',
	'field_cs'  => 'padding:10px 16px;border-top-style:solid;border-top-color:rgb(200, 200, 200)',
	'field_cls' => 'w-full rounded border px-4 py-2.5',
) );
$bp = null;
foreach ( (array) ( FW_Site_Converter_Stitch::build_form_style_presets()['form_style_presets'] ?? array() ) as $p ) {
	if ( 'formp-' . trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) ( $p['style_name'] ?? '' ) ) ), '-' ) === (string) ( $boxed['atts']['form_style'] ?? '' ) ) { $bp = $p; }
}
$ok( is_array( $bp ) && 'bottom' !== $bp['border_sides'], 'NEGATIVE: a boxed `border` field is not turned into an underline' );
$ok( is_array( $bp ) && false !== strpos( (string) $bp['custom_css'], 'padding-top:10px' ), '…and keeps its own vertical padding too' );

echo "\n== NEGATIVE: a form whose source stamped no skin gets no preset\n";

FW_Site_Converter_Mapper::$registry = array();
$bare = $cf->invoke( null, array( 'fields' => $fields, 'submit' => 'Send' ) );
$ok( '' === (string) ( $bare['atts']['form_style'] ?? '' ) || 0 === strpos( (string) $bare['atts']['form_style'], 'formp-standard' ), 'no converted preset for a skinless form' );
$ok( array() === FW_Site_Converter_Mapper::captured( 'form_style' ), 'and nothing is registered' );
$ok( array() === FW_Site_Converter_Stitch::build_form_style_presets(), 'so the library is left alone' );

echo "\n" . ( $fails ? "FAILED: $fails\n" : "ALL PASS\n" );
exit( $fails ? 1 : 0 );
