<?php
/**
 * Output targets — the seam between the analysis and the page builders it writes into.
 *
 *   1. The registry: the native, block-theme and Elementor targets are registered, a roadmap slug still
 *      converts to the native output, and a third party can register a target through the filter.
 *   2. The Site Model is builder-NEUTRAL: run over every fixture, it carries no Unyson+ option vocabulary
 *      (`{predefined,custom}` colours, `row-cols-N`, container preset slugs) and every row's columns have
 *      a real 1–12 span.
 *   3. The Elementor emitter writes a valid tree and LOSES NO TEXT: every element has a unique 7-char id,
 *      only known element / widget types appear, every container overrides the kit's default gap and
 *      padding, and every heading / paragraph / button label in the model reaches the output.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file <this file>
 */

if ( ! class_exists( 'FW_SC_Targets' ) || ! class_exists( 'FW_SC_Site_Model' ) || ! class_exists( 'FW_SC_Elementor_Emitter' ) ) {
	fwrite( STDERR, "FAIL: output targets not loaded (run inside a WP install with the site-converter active)\n" );
	exit( 1 );
}

$GLOBALS['__pass'] = 0;
$GLOBALS['__fail'] = 0;
function ot( $label, $cond, $got = null ) {
	if ( $cond ) { $GLOBALS['__pass']++; echo "  PASS  $label\n"; return; }
	$GLOBALS['__fail']++;
	echo "  FAIL  $label" . ( null !== $got ? '  (got: ' . ( is_scalar( $got ) ? $got : wp_json_encode( $got ) ) . ')' : '' ) . "\n";
}

/* ---------------------------------------------------------------- 1. Registry */
echo "Registry\n";
FW_SC_Targets::reset();
$all = FW_SC_Targets::all();
ot( 'native target registered', isset( $all['page-builder'] ) && $all['page-builder']->is_selectable() );
ot( 'block theme registered', isset( $all['block-theme'] ) );
ot( 'elementor registered + selectable', isset( $all['elementor'] ) && $all['elementor']->is_selectable() );
ot( 'roadmap slug listed but not selectable', isset( $all['divi'] ) && ! $all['divi']->is_selectable() );
ot( 'roadmap slug resolves to the native output', 'page-builder' === FW_SC_Targets::resolve( 'divi' )->slug() );
ot( 'unknown slug resolves to the native output', 'page-builder' === FW_SC_Targets::resolve( 'nope' )->slug() );
ot( 'elementor resolves to itself', 'elementor' === FW_SC_Targets::resolve( 'elementor' )->slug() );

class OT_Test_Target extends FW_SC_Target {
	public function slug() { return 'divi'; }
	public function label() { return 'Divi (test)'; }
	public function status() { return self::STATUS_PRE_ALPHA; }
	public function import_pages( array $m, array $b, array $c = array() ) { return array( 'pages' => array() ); }
}
$f = function ( $t ) { $t['divi'] = new OT_Test_Target(); return $t; };
add_filter( 'fw_site_converter_targets', $f );
FW_SC_Targets::reset();
ot( 'a registered class replaces the roadmap placeholder', 'divi' === FW_SC_Targets::resolve( 'divi' )->slug() );
remove_filter( 'fw_site_converter_targets', $f );
FW_SC_Targets::reset();

/* ---------------------------------------------------------------- 2 + 3. Model + emitter, per fixture */
$known_widgets = array( 'heading', 'text-editor', 'button', 'image', 'html', 'icon-list', 'testimonial', 'accordion', 'counter' );

foreach ( glob( __DIR__ . '/fixtures/*.html' ) as $fixture ) {
	$name   = basename( $fixture, '.html' );
	$bundle = FW_Site_Converter_Sources::build_from_html( file_get_contents( $fixture ), 'Targets', array( 'dynamic_chrome' => true, 'hifi_css' => true, 'source_url' => 'https://fixture.invalid' ) );
	$model  = FW_SC_Site_Model::from_mapping( (array) ( $bundle['mapping'] ?? array() ) );
	echo "\n$name\n";

	ot( "$name: model schema + version", FW_SC_Site_Model::SCHEMA === $model['schema'] && 1 === $model['version'] );
	ot( "$name: model has pages", ! empty( $model['pages'] ) );

	// NEUTRAL — none of the Unyson+ option spellings survive into the model's normalised fields.
	$json = wp_json_encode( $model );
	$leaks = array();
	$walk = function ( $node, $path ) use ( &$walk, &$leaks ) {
		if ( ! is_array( $node ) ) { return; }
		if ( isset( $node['type'] ) && 'unknown' === $node['type'] ) { return; } // carries its original block on purpose
		foreach ( $node as $k => $v ) {
			if ( 'predefined' === $k ) { $leaks[] = "$path.$k"; }
			if ( is_string( $v ) && preg_match( '/^row-cols-\d|^(content|wide|medium|narrow)-?\d*$/', $v ) && 'id' !== $k ) { $leaks[] = "$path.$k=$v"; }
			$walk( $v, "$path.$k" );
		}
	};
	$walk( $model, '$' );
	ot( "$name: no Unyson+ option vocabulary in the model", ! $leaks, array_slice( $leaks, 0, 3 ) );

	$bad_spans = 0;
	$texts     = array();
	$collect = function ( $blocks ) use ( &$collect, &$bad_spans, &$texts ) {
		foreach ( (array) $blocks as $b ) {
			if ( 'row' === $b['type'] ) {
				foreach ( $b['columns'] as $c ) {
					if ( $c['span'] < 1 || $c['span'] > 12 ) { $bad_spans++; }
					if ( ! empty( $c['counter']['label'] ) ) { $texts[] = trim( $c['counter']['label'] ); }
					if ( ! empty( $c['card']['title'] ) ) { $texts[] = trim( wp_strip_all_tags( $c['card']['title'] ) ); }
					$collect( $c['blocks'] );
				}
			}
			if ( in_array( $b['type'], array( 'stack', 'box' ), true ) ) { $collect( $b['blocks'] ); }
			if ( 'heading' === $b['type'] && '' !== trim( $b['text'] ) ) { $texts[] = trim( $b['text'] ); }
			if ( 'button' === $b['type'] && '' !== trim( $b['label'] ) ) { $texts[] = trim( $b['label'] ); }
			if ( 'text' === $b['type'] && '' !== trim( $b['text'] ) ) { $texts[] = trim( $b['text'] ); }
		}
	};
	foreach ( $model['pages'] as $p ) { foreach ( $p['sections'] as $s ) { $collect( $s['blocks'] ); } }
	ot( "$name: every column span is 1–12", 0 === $bad_spans, $bad_spans );

	// EMITTER.
	$colors = array( '#ffffff' => 'c-white' );
	$out    = array();
	foreach ( $model['pages'] as $i => $p ) {
		$out[] = ( new FW_SC_Elementor_Emitter( array( 'seed' => $name . $i, 'colors' => $colors, 'fonts' => array( 'heading' => '', 'body' => '' ) ) ) )->page( $p );
	}
	$ids = array(); $types_ok = true; $gap_ok = true; $bad_type = '';
	$check = function ( $els ) use ( &$check, &$ids, &$types_ok, &$gap_ok, &$bad_type, $known_widgets ) {
		foreach ( $els as $e ) {
			$ids[] = $e['id'];
			if ( 'container' === $e['elType'] ) {
				if ( ! isset( $e['settings']['padding'] ) || ( 'grid' !== ( $e['settings']['container_type'] ?? '' ) && ! isset( $e['settings']['flex_gap'] ) ) ) { $gap_ok = false; }
			} elseif ( 'widget' !== $e['elType'] || ! in_array( $e['widgetType'], $known_widgets, true ) ) {
				$types_ok = false; $bad_type = $e['elType'] . '/' . ( $e['widgetType'] ?? '' );
			}
			$check( $e['elements'] );
		}
	};
	$all_json = '';
	foreach ( $out as $o ) { $check( $o['elements'] ); $all_json .= wp_json_encode( $o['elements'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); }
	$n_sections = 0; $n_top = 0;
	foreach ( $model['pages'] as $i => $p ) { $n_sections += count( $p['sections'] ); $n_top += count( $out[ $i ]['elements'] ); }
	ot( "$name: one top-level container per model section", $n_sections === $n_top, "$n_top vs $n_sections" );
	ot( "$name: element ids are unique 7-char", count( $ids ) === count( array_unique( $ids ) ) && ! array_filter( $ids, function ( $i ) { return 7 !== strlen( $i ); } ) );
	ot( "$name: only known element + widget types", $types_ok, $bad_type );
	ot( "$name: every container overrides the kit gap + padding", $gap_ok );

	// Every string the widgets carry, as plain text.
	$strings = array();
	foreach ( $out as $o ) {
		array_walk_recursive( $o['elements'], function ( $v ) use ( &$strings ) { if ( is_string( $v ) ) { $strings[] = $v; } } );
	}
	$plain   = html_entity_decode( wp_strip_all_tags( implode( ' ', $strings ) ), ENT_QUOTES, 'UTF-8' );
	$missing = array();
	foreach ( array_unique( $texts ) as $t ) {
		$probe = mb_substr( preg_replace( '/\s+/', ' ', html_entity_decode( $t, ENT_QUOTES, 'UTF-8' ) ), 0, 24 );
		if ( '' !== $probe && false === mb_strpos( preg_replace( '/\s+/', ' ', $plain ), $probe ) ) { $missing[] = $probe; }
	}
	ot( "$name: every heading / text / button label reaches the output", ! $missing, array_slice( $missing, 0, 3 ) );
	$tr = array( 'native' => 0, 'composed' => 0, 'html' => 0 );
	foreach ( $out as $o ) { foreach ( $tr as $k => $v ) { $tr[ $k ] += (int) $o['trace'][ $k ]; } }
	$total = max( 1, array_sum( $tr ) );
	echo sprintf( "        trace: native %d · composed %d · html %d  → native-ness %.0f%%\n", $tr['native'], $tr['composed'], $tr['html'], 100 * ( $tr['native'] + $tr['composed'] ) / $total );
	// A colour that is in the palette never appears as a literal — it binds to its global instead.
	ot( "$name: palette colours bind to Elementor globals, never literals", false === stripos( $all_json, '"#ffffff"' ) );
}

// 4. Section rhythm set by MARGINS (`mb-24 md:mb-32`) is kept. A transparent band folds its vertical margin
//    into padding (the same render, and what the native output does); a painted band keeps a real margin so
//    the gap stays outside its background.
$em_sec = function ( $cs ) {
	$model = FW_SC_Site_Model::from_mapping( array( 'pages' => array( array( 'title' => 'M', 'slug' => 'm', 'sections' => array(
		array( 'sectionCs' => $cs, 'blocks' => array( array( 'type' => 'heading', 'tag' => 'h2', 'text' => 'Margin spaced band' ) ) ),
	) ) ) ) );
	$out = ( new FW_SC_Elementor_Emitter( array( 'seed' => 'margin', 'colors' => array(), 'fonts' => array( 'heading' => '', 'body' => '' ) ) ) )->page( $model['pages'][0] );
	return $out['elements'][0]['settings'];
};
$s = $em_sec( 'display:block;padding:32px 0px;margin:0px 0px 128px' );
ot( 'margin: transparent band folds margin-bottom into padding', '160' === (string) ( $s['padding']['bottom'] ?? '' ) && '32' === (string) ( $s['padding']['top'] ?? '' ), $s['padding'] ?? null );
$s = $em_sec( 'display:block;padding:64px 0px;margin:96px 0px;background-color:rgb(10, 10, 10)' );
ot( 'margin: painted band keeps a real vertical margin', '96' === (string) ( $s['margin']['top'] ?? '' ) && '96' === (string) ( $s['margin']['bottom'] ?? '' ) && '64' === (string) ( $s['padding']['top'] ?? '' ), array( $s['margin'] ?? null, $s['padding'] ?? null ) );
$s = $em_sec( 'display:block;margin:0px auto' );
ot( 'margin: an auto (centering) margin adds no spacing', '0' === (string) ( $s['padding']['bottom'] ?? '0' ) && empty( $s['margin'] ), array( $s['margin'] ?? null, $s['padding'] ?? null ) );

// 5. A chip row (`t:chips`) is native: a wrapping row container of pill heading widgets, each keeping its
//    fill, padding, border and text — never an HTML widget of rescued paragraphs.
$chip_cs = 'background-color:rgba(255, 255, 255, 0.1);color:rgb(255, 255, 255);font-size:10px;letter-spacing:2px;text-transform:uppercase;padding:12px 16px;border-top-width:1px;border-top-style:solid;border-top-color:rgba(255, 255, 255, 0.08);border-radius:0px';
$model = FW_SC_Site_Model::from_mapping( array( 'pages' => array( array( 'title' => 'C', 'slug' => 'c', 'sections' => array(
	array( 'sectionCs' => 'display:block', 'blocks' => array( array( 't' => 'chips', 'gap' => 12, 'align' => 'end', 'items' => array(
		array( 't' => 'text', 'cs' => $chip_cs, 'text' => 'Mid-century modernism' ),
		array( 't' => 'text', 'cs' => $chip_cs, 'text' => 'Tactile materiality' ),
	) ) ) ),
) ) ) ) );
$out  = ( new FW_SC_Elementor_Emitter( array( 'seed' => 'chips', 'colors' => array(), 'fonts' => array( 'heading' => '', 'body' => '' ) ) ) )->page( $model['pages'][0] );
$row  = $out['elements'][0]['elements'][0] ?? array();
$kids = (array) ( $row['elements'] ?? array() );
$pill = $kids[0]['settings'] ?? array();
ot( 'chips: a row container of one heading widget per chip', 'container' === ( $row['elType'] ?? '' ) && 2 === count( $kids ) && 'heading' === ( $kids[0]['widgetType'] ?? '' ) && 'heading' === ( $kids[1]['widgetType'] ?? '' ), wp_json_encode( array( $row['elType'] ?? null, $row['widgetType'] ?? null, count( $kids ) ) ) );
ot( 'chips: the row wraps, keeps the gap and the end alignment', 'row' === ( $row['settings']['flex_direction'] ?? '' ) && 'wrap' === ( $row['settings']['flex_wrap'] ?? '' ) && '12' === (string) ( $row['settings']['flex_gap']['column'] ?? '' ) && 'flex-end' === ( $row['settings']['flex_justify_content'] ?? '' ), $row['settings'] ?? null );
ot( 'chips: each pill keeps its text, padding and border', 'Mid-century modernism' === ( $pill['title'] ?? '' ) && '16' === (string) ( $pill['_padding']['left'] ?? '' ) && 'solid' === ( $pill['_border_border'] ?? '' ), $pill );
ot( 'chips: no HTML widget fallback', 0 === (int) $out['trace']['html'], $out['trace'] );

// 6. The source <main>'s vertical padding (often the fixed header's clearance) is page padding, as on the
//    native output's #main: carried as page CSS on the Elementor root. Its horizontal padding is not (on the
//    root it would inset every section background).
$model = FW_SC_Site_Model::from_mapping( array( 'pages' => array( array( 'title' => 'P', 'slug' => 'p',
	'mainCs' => 'display:block;padding:160px 32px 128px;margin:0px',
	'sections' => array( array( 'sectionCs' => 'display:block', 'blocks' => array( array( 't' => 'heading', 'tag' => 'h1', 'text' => 'Main padded page' ) ) ) ),
) ) ) );
ot( 'main: the Site Model carries the main vertical padding', 160.0 === (float) ( $model['pages'][0]['main']['padding_top'] ?? 0 ) && 128.0 === (float) ( $model['pages'][0]['main']['padding_bottom'] ?? 0 ), $model['pages'][0]['main'] ?? null );
$out = ( new FW_SC_Elementor_Emitter( array( 'seed' => 'main', 'colors' => array(), 'fonts' => array( 'heading' => '', 'body' => '' ) ) ) )->page( $model['pages'][0] );
ot( 'main: page CSS pads the Elementor root top and bottom', false !== strpos( $out['css'], 'padding-top:160px' ) && false !== strpos( $out['css'], 'padding-bottom:128px' ) && false !== strpos( $out['css'], 'data-elementor-type' ), $out['css'] );
ot( 'main: no horizontal padding on the root', false === strpos( $out['css'], 'padding-left:32px' ), $out['css'] );

// 7. A heading that is itself gradient-clipped text (transparent colour + a gradient background clipped to
//    the glyphs) keeps its gradient; it must not fall to the kit's global heading colour.
$model = FW_SC_Site_Model::from_mapping( array( 'pages' => array( array( 'title' => 'G', 'slug' => 'g', 'sections' => array(
	array( 'sectionCs' => 'display:block', 'blocks' => array( array( 't' => 'heading', 'tag' => 'h1', 'text' => 'Gradient title',
		'cs' => 'color:rgba(0, 0, 0, 0);background-image:linear-gradient(110deg, rgb(10, 20, 30) 0%, rgb(200, 90, 20) 100%);-webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent' ) ) ),
) ) ) ) );
$out = ( new FW_SC_Elementor_Emitter( array( 'seed' => 'grad', 'colors' => array(), 'fonts' => array( 'heading' => '', 'body' => '' ) ) ) )->page( $model['pages'][0] );
$hw  = $out['elements'][0]['elements'][0]['settings'] ?? array();
$cls = (string) ( $hw['_css_classes'] ?? '' );
ot( 'gradient text: the heading carries a class whose page rule clips the gradient to the text',
	'' !== $cls && false !== strpos( $out['css'], '.' . trim( explode( ' ', $cls )[0] ) ) && false !== strpos( $out['css'], 'linear-gradient(110deg' ) && false !== strpos( $out['css'], 'background-clip:text' ), array( $cls, $out['css'] ) );

// 8. A no-wrap row whose columns declare no width (a CTA beside a stat) sizes each column to its content, with
//    the source gap between them. Splitting it into equal halves pushed the pair apart.
$model = FW_SC_Site_Model::from_mapping( array( 'pages' => array( array( 'title' => 'R', 'slug' => 'r', 'sections' => array(
	array( 'sectionCs' => 'display:block', 'blocks' => array( array( 't' => 'row', 'gap' => 24, 'nowrap' => true, 'valign' => 'center', 'cols' => array(
		array( 'blocks' => array( array( 't' => 'button', 'label' => 'Deploy', 'href' => '#' ) ) ),
		array( 'blocks' => array( array( 't' => 'text', 'text' => '12ms', 'contentSized' => true ) ) ),
	) ) ) ),
) ) ) ) );
$rowm = $model['pages'][0]['sections'][0]['blocks'][0];
ot( 'content row: the Site Model marks it content-sized', ! empty( $rowm['content_sized'] ), $rowm['content_sized'] ?? null );
$out  = ( new FW_SC_Elementor_Emitter( array( 'seed' => 'crow', 'colors' => array(), 'fonts' => array( 'heading' => '', 'body' => '' ) ) ) )->page( $model['pages'][0] );
$col0 = $out['elements'][0]['elements'][0]['elements'][0]['settings'] ?? array();
ot( 'content row: columns hug their content (and may shrink), not a 50% share', 'auto' === (string) ( $col0['width']['size'] ?? '' ) && 'shrink' === ( $col0['_flex_size'] ?? '' ), $col0['width'] ?? null );
// A justified row (`space-between`) lays its cells out by content even when they declare spans — the native
// rule: a heading beside a capped intro takes its natural width, the capped cell holds its cap.
$model4 = FW_SC_Site_Model::from_mapping( array( 'pages' => array( array( 'title' => 'R', 'slug' => 'r', 'sections' => array(
	array( 'sectionCs' => 'display:block', 'blocks' => array( array( 't' => 'row', 'gap' => 20, 'nowrap' => true, 'justify' => 'space-between', 'cols' => array(
		array( 'wResp' => array( 'desktop' => 6 ), 'blocks' => array( array( 't' => 'heading', 'tag' => 'h2', 'text' => 'A heading that takes its natural width' ) ) ),
		array( 'wResp' => array( 'desktop' => 6 ), 'maxw' => '460px', 'blocks' => array( array( 't' => 'text', 'text' => 'A capped intro' ) ) ),
	) ) ) ),
) ) ) ) );
$row4 = $model4['pages'][0]['sections'][0]['blocks'][0];
ot( 'content row: a justified row is content-sized even with declared spans', ! empty( $row4['content_sized'] ), $row4['content_sized'] ?? null );
$out4 = ( new FW_SC_Elementor_Emitter( array( 'seed' => 'jrow4', 'colors' => array(), 'fonts' => array( 'heading' => '', 'body' => '' ) ) ) )->page( $model4['pages'][0] );
$c4   = $out4['elements'][0]['elements'][0]['elements'];
ot( 'content row: the capped cell holds its cap, the other shrinks', 'none' === ( $c4[1]['settings']['_flex_size'] ?? '' ) && 'shrink' === ( $c4[0]['settings']['_flex_size'] ?? '' ), array( $c4[0]['settings']['_flex_size'] ?? null, $c4[1]['settings']['_flex_size'] ?? null ) );
// The cap is the capped cell's own width: a boxed inner cap left the cell at its text's one-line width.
ot( 'content row: the capped cell is its cap wide', 'min(100%, 460px)' === (string) ( $c4[1]['settings']['width']['size'] ?? '' ), $c4[1]['settings']['width'] ?? null );
$model2 = FW_SC_Site_Model::from_mapping( array( 'pages' => array( array( 'title' => 'R', 'slug' => 'r', 'sections' => array(
	array( 'sectionCs' => 'display:block', 'blocks' => array( array( 't' => 'row', 'gap' => 24, 'cols' => array(
		array( 'wResp' => array( 'desktop' => 6 ), 'blocks' => array( array( 't' => 'text', 'text' => 'Left half' ) ) ),
		array( 'wResp' => array( 'desktop' => 6 ), 'blocks' => array( array( 't' => 'text', 'text' => 'Right half' ) ) ),
	) ) ) ),
) ) ) ) );
$model3 = FW_SC_Site_Model::from_mapping( array( 'pages' => array( array( 'title' => 'R', 'slug' => 'r', 'sections' => array(
	array( 'sectionCs' => 'display:block', 'blocks' => array( array( 't' => 'row', 'gap' => 24, 'nowrap' => true, 'justify' => 'space-between', 'cols' => array(
		array( 'blocks' => array( array( 't' => 'text', 'text' => 'Label on the left' ) ) ),
		array( 'blocks' => array( array( 't' => 'button', 'label' => 'Book', 'href' => '#' ) ) ),
	) ) ) ),
) ) ) ) );
$out3 = ( new FW_SC_Elementor_Emitter( array( 'seed' => 'jrow', 'colors' => array(), 'fonts' => array( 'heading' => '', 'body' => '' ) ) ) )->page( $model3['pages'][0] );
ot( 'content row: a space-between row keeps its ends apart', 'space-between' === ( $out3['elements'][0]['elements'][0]['settings']['flex_justify_content'] ?? '' ), $out3['elements'][0]['elements'][0]['settings'] ?? null );
ot( 'content row: a row with declared spans is not content-sized', empty( $model2['pages'][0]['sections'][0]['blocks'][0]['content_sized'] ) );

// 8b. N equal columns where N does not divide 12 (seven cards): equal tracks on one line, never 6 + 1.
$cols7 = array();
for ( $i = 1; $i <= 7; $i++ ) { $cols7[] = array( 'wResp' => array( 'desktop' => 2 ), 'blocks' => array( array( 't' => 'text', 'text' => "Card number $i" ) ) ); }
$model7 = FW_SC_Site_Model::from_mapping( array( 'pages' => array( array( 'title' => 'S', 'slug' => 's', 'sections' => array(
	array( 'sectionCs' => 'display:block', 'blocks' => array( array( 't' => 'row', 'gap' => 14, 'cols' => $cols7 ) ) ),
) ) ) ) );
ot( 'equal tracks: seven equal columns are a 7-track row in the model', 7 === (int) ( $model7['pages'][0]['sections'][0]['blocks'][0]['tracks'] ?? 0 ), $model7['pages'][0]['sections'][0]['blocks'][0]['tracks'] ?? null );
$out7 = ( new FW_SC_Elementor_Emitter( array( 'seed' => 'r7', 'colors' => array(), 'fonts' => array( 'heading' => '', 'body' => '' ) ) ) )->page( $model7['pages'][0] );
$r7   = $out7['elements'][0]['elements'][0];
ot( 'equal tracks: one line, each column a seventh minus its gap share', 'nowrap' === ( $r7['settings']['flex_wrap'] ?? '' ) && 'calc(14.2857% - 12px)' === ( $r7['elements'][0]['settings']['width']['size'] ?? '' ), array( $r7['settings']['flex_wrap'] ?? null, $r7['elements'][0]['settings']['width'] ?? null ) );

// 8c. A card column keeps its BUTTON (a styled button widget, not dropped) and its skin — a gradient box
//     included — whether the card is composed or drawn by the Unyson+ icon-box widget, which paints no card of
//     its own when the native build put the skin on the column.
$card_col = array( 'wResp' => array( 'desktop' => 6 ),
	'cardBox' => array( 'gradient' => 'linear-gradient(rgba(255, 255, 255, 0.04), rgba(255, 255, 255, 0.016))', 'shadow' => 'rgba(0, 0, 0, 0.26) 0px 26px 80px 0px' ),
	'card' => array( 'title' => 'Sunset villa', 'text' => '<p>A regenerative retreat.</p>',
		'button' => array( 'label' => 'Expand monolith', 'href' => '#', 'cs' => 'background-color:rgb(200, 130, 50);color:rgb(29, 19, 10);font-size:10px;padding:12px 20px;border-radius:999px;display:block' ) ) );
$mc = FW_SC_Site_Model::from_mapping( array( 'pages' => array( array( 'title' => 'K', 'slug' => 'k', 'sections' => array(
	array( 'sectionCs' => 'display:block', 'blocks' => array( array( 't' => 'row', 'gap' => 16, 'cols' => array( $card_col, $card_col ) ) ) ),
) ) ) ) );
$find = function ( $els, $type ) use ( &$find ) { foreach ( $els as $e ) { if ( $type === ( $e['widgetType'] ?? '' ) ) { return $e; } if ( ! empty( $e['elements'] ) && ( $r = $find( $e['elements'], $type ) ) ) { return $r; } } return null; };
foreach ( array( 'composed' => null, 'widget' => function ( $k ) { return 'card' === $k ? array( 'widget' => 'up-icon-box', 'settings' => array( 'title' => 'Sunset villa', 'box_style' => '' ) ) : null; } ) as $path => $resolver ) {
	$oc  = ( new FW_SC_Elementor_Emitter( array( 'seed' => 'card-' . $path, 'colors' => array(), 'fonts' => array( 'heading' => '', 'body' => '' ), 'native' => $resolver ) ) )->page( $mc['pages'][0] );
	$btn = $find( $oc['elements'], 'button' );
	ot( "card ($path): the card's button is a button widget", $btn && 'Expand monolith' === ( $btn['settings']['text'] ?? '' ), $btn['settings']['text'] ?? null );
	ot( "card ($path): a block-level card CTA spans the card", 'justify' === ( $btn['settings']['align'] ?? '' ), $btn['settings']['align'] ?? null );
	$col = $oc['elements'][0]['elements'][0]['elements'][0]['settings'] ?? array();
	$cls = trim( explode( ' ', trim( (string) ( $col['css_classes'] ?? '' ) ) )[0] ?? '' );
	ot( "card ($path): the column keeps its gradient skin", '' !== $cls && false !== strpos( $oc['css'], '.' . $cls . '{' ) && false !== strpos( $oc['css'], 'linear-gradient(rgba(255, 255, 255, 0.04)' ), array( $col['css_classes'] ?? null, $oc['css'] ) );
}

// 8d. A standalone button is never stretched: its computed `display:block` is usual for a flex-row CTA and says
//     nothing about its width. Only a card's own CTA, a block inside a block card, spans the card (8c).
$mb = FW_SC_Site_Model::from_mapping( array( 'pages' => array( array( 'title' => 'B', 'slug' => 'b', 'sections' => array(
	array( 'sectionCs' => 'display:block', 'blocks' => array(
		array( 't' => 'button', 'label' => 'Full width', 'href' => '#', 'srcCs' => 'display:block;padding:0px 16px;background-color:rgb(200, 130, 50)' ),
		array( 't' => 'button', 'label' => 'Inline', 'href' => '#', 'srcCs' => 'display:inline-flex;padding:12px 20px;background-color:rgb(200, 130, 50)' ),
	) ),
) ) ) ) );
$ob = ( new FW_SC_Elementor_Emitter( array( 'seed' => 'btnw', 'colors' => array(), 'fonts' => array( 'heading' => '', 'body' => '' ) ) ) )->page( $mb['pages'][0] );
$bw = $ob['elements'][0]['elements'];
$rb = FW_SC_Site_Model::from_mapping( array( 'pages' => array( array( 'title' => 'R', 'slug' => 'r', 'sections' => array(
	array( 'sectionCs' => 'display:block', 'blocks' => array(
		array( 't' => 'button', 'label' => 'Pill', 'href' => '#', 'srcCs' => 'background-color:rgb(10, 10, 10);border-radius:999px' ),
		array( 't' => 'button', 'label' => 'Square', 'href' => '#', 'srcCs' => 'background-color:rgb(10, 10, 10)' ),
	) ),
) ) ) ) );
$orb = ( new FW_SC_Elementor_Emitter( array( 'seed' => 'btnr', 'colors' => array(), 'fonts' => array( 'heading' => '', 'body' => '' ) ) ) )->page( $rb['pages'][0] );
ot( 'button: a measured radius is kept, an unmeasured one is an explicit 0', '999' === (string) ( $orb['elements'][0]['elements'][0]['settings']['border_radius']['top'] ?? '' ) && '0' === (string) ( $orb['elements'][0]['elements'][1]['settings']['border_radius']['top'] ?? '' ), array( $orb['elements'][0]['elements'][0]['settings']['border_radius'] ?? null, $orb['elements'][0]['elements'][1]['settings']['border_radius'] ?? null ) );
ot( 'button: a standalone block button is not stretched', 'justify' !== ( $bw[0]['settings']['align'] ?? '' ) && 'justify' !== ( $bw[1]['settings']['align'] ?? '' ), array( $bw[0]['settings']['align'] ?? null, $bw[1]['settings']['align'] ?? null ) );

// 8e. A section's gradient background survives: its class must stay on the section beside the section's own
//     class (it was overwritten, and the band painted nothing).
$mg = FW_SC_Site_Model::from_mapping( array( 'pages' => array( array( 'title' => 'G', 'slug' => 'g', 'sections' => array(
	array( 'sectionCs' => 'display:block;background-image:linear-gradient(rgba(255, 153, 84, 0.3) 0%, rgba(18, 21, 28, 0.9) 100%)', 'blocks' => array( array( 't' => 'heading', 'tag' => 'h2', 'text' => 'Gradient band' ) ) ),
) ) ) ) );
$og  = ( new FW_SC_Elementor_Emitter( array( 'seed' => 'secgrad', 'colors' => array(), 'fonts' => array( 'heading' => '', 'body' => '' ) ) ) )->page( $mg['pages'][0] );
$gcl = preg_split( '/\s+/', trim( (string) ( $og['elements'][0]['settings']['css_classes'] ?? '' ) ) );
$has = false; foreach ( $gcl as $c ) { if ( '' !== $c && preg_match( '/\.' . preg_quote( $c, '/' ) . '\{background-image:linear-gradient/', $og['css'] ) ) { $has = true; } }
ot( 'section: a gradient background keeps its class on the section', $has, array( $og['elements'][0]['settings']['css_classes'] ?? null, $og['css'] ) );
// Elementor lazy-loads container backgrounds below the fold (`background-image:none !important` until scrolled),
// gradients included; a CSS gradient downloads nothing, so it opts out and paints with the page.
ot( 'section: a gradient section opts out of background lazy-loading', in_array( 'e-no-lazyload', $gcl, true ), $gcl );

// 8f. Video blocks. A hero's background video (`bg:true`) becomes the SECTION's background video (overlay, height,
//     centring kept); an inline video is Elementor's own hosted Video widget, never an HTML widget.
$vid = 'https://example.test/media/clip.mp4';
$mv  = FW_SC_Site_Model::from_mapping( array( 'pages' => array( array( 'title' => 'V', 'slug' => 'v', 'sections' => array(
	array( 'sectionCs' => 'display:block', 'blocks' => array(
		array( 't' => 'heading', 'tag' => 'h1', 'text' => 'Hero over a video' ),
		array( 't' => 'video', 'bg' => true, 'src' => $vid, 'overlay' => 'linear-gradient(rgba(0, 0, 0, 0.1), rgba(0, 0, 0, 0.2))', 'hero_height' => '100vh', 'valign' => 'middle', 'autoplay' => 'yes', 'muted' => 'yes', 'loop' => 'yes', 'controls' => 'no' ),
	) ),
	array( 'sectionCs' => 'display:block', 'blocks' => array(
		array( 't' => 'video', 'bg' => false, 'src' => $vid, 'rhideCls' => ' h-[30rem] w-full object-cover', 'autoplay' => 'yes', 'muted' => 'yes', 'loop' => 'yes', 'controls' => 'no', 'card_css' => 'selector{border-radius:48px;overflow:hidden;}' ),
	) ),
) ) ) ) );
$s0 = $mv['pages'][0]['sections'][0];
ot( 'video: a background video is promoted to the section', $vid === ( $s0['background']['video'] ?? '' ) && '100vh' === ( $s0['min_height'] ?? '' ) && 1 === count( $s0['blocks'] ), array( $s0['background'] ?? null, $s0['min_height'] ?? null, count( $s0['blocks'] ) ) );
$ov  = ( new FW_SC_Elementor_Emitter( array( 'seed' => 'vid', 'colors' => array(), 'fonts' => array( 'heading' => '', 'body' => '' ) ) ) )->page( $mv['pages'][0] );
$vs  = $ov['elements'][0]['settings'];
ot( 'video: the hero section is an Elementor background video with its overlay', 'video' === ( $vs['background_background'] ?? '' ) && $vid === ( $vs['background_video_link'] ?? '' ) && false !== strpos( $ov['css'], 'rgba(0, 0, 0, 0.2)' ), array( $vs['background_background'] ?? null, $ov['css'] ) );
$vw  = $find( $ov['elements'], 'video' );
ot( 'video: an inline video is a hosted, muted, looping Video widget', $vw && 'hosted' === ( $vw['settings']['video_type'] ?? '' ) && $vid === ( $vw['settings']['hosted_url']['url'] ?? '' ) && 'yes' === ( $vw['settings']['mute'] ?? '' ) && 'yes' === ( $vw['settings']['loop'] ?? '' ), $vw['settings'] ?? null );
ot( 'video: the inline video keeps its card skin and height', $vw && false !== strpos( $ov['css'], 'border-radius:48px' ) && false !== strpos( $ov['css'], 'height:480px' ), $ov['css'] );
ot( 'video: no HTML widget fallback', 0 === (int) $ov['trace']['html'], $ov['trace'] );

// 8g. A four-value radius (an arch: `160px 160px 40px 40px`) is kept corner by corner, not dropped; an inline video's
//     own card skin lands on its video wrapper (the widget container is not always rendered).
$ma = FW_SC_Site_Model::from_mapping( array( 'pages' => array( array( 'title' => 'A', 'slug' => 'a', 'sections' => array(
	array( 'sectionCs' => 'display:block', 'blocks' => array( array( 't' => 'panel', 'box' => array( 'bg' => 'rgba(255, 255, 255, 0.5)', 'radius' => '160px 160px 40px 40px' ),
		'blocks' => array( array( 't' => 'video', 'bg' => false, 'src' => 'https://example.test/v.mp4', 'card_css' => 'selector{border-radius:48px;overflow:hidden;}' ) ) ) ) ),
) ) ) ) );
$oa = ( new FW_SC_Elementor_Emitter( array( 'seed' => 'arch', 'colors' => array(), 'fonts' => array( 'heading' => '', 'body' => '' ) ) ) )->page( $ma['pages'][0] );
$pr = $oa['elements'][0]['elements'][0]['settings']['border_radius'] ?? array();
ot( 'radius: an arch keeps each corner', '160' === (string) ( $pr['top'] ?? '' ) && '40' === (string) ( $pr['bottom'] ?? '' ) && '40' === (string) ( $pr['left'] ?? '' ), $pr );
ot( 'video: the card skin is on the video wrapper', (bool) preg_match( '/\.upw-video-[a-z0-9]+ \.elementor-wrapper\{[^}]*border-radius:48px/', $oa['css'] ), $oa['css'] );

// 8h. A verbatim block that is just a flex row of text leaves (`Spatial Ergonomics … 01`) is a native row of text
//     widgets, laid out like the source (justify, align, gap) and keeping each leaf's type — not an HTML widget.
//     Anything richer stays verbatim.
$row_html = '<div class="flex items-center justify-between" data-sc-cs="display:flex;justify-content:space-between;align-items:center;gap:12px;color:rgb(20, 20, 20)">'
	. '<span data-sc-cs="color:rgba(0, 0, 0, 0.55);font-size:14px;letter-spacing:3.5px;text-transform:uppercase">Spatial Ergonomics</span>'
	. '<span data-sc-cs="color:rgba(0, 0, 0, 0.55);font-size:14px">01</span></div>';
$mr = FW_SC_Site_Model::from_mapping( array( 'pages' => array( array( 'title' => 'H', 'slug' => 'h', 'sections' => array(
	array( 'sectionCs' => 'display:block', 'blocks' => array(
		array( 't' => 'code', 'html' => $row_html ),
		array( 't' => 'code', 'html' => '<div class="flex"><svg viewBox="0 0 10 10"><circle cx="5" cy="5" r="4"/></svg><span>Not plain</span></div>' ),
	) ),
) ) ) ) );
$or  = ( new FW_SC_Elementor_Emitter( array( 'seed' => 'leafrow', 'colors' => array(), 'fonts' => array( 'heading' => '', 'body' => '' ) ) ) )->page( $mr['pages'][0] );
$lr  = $or['elements'][0]['elements'][0] ?? array();
ot( 'leaf row: a flex row of text leaves is a row container of text widgets', 'container' === ( $lr['elType'] ?? '' ) && 2 === count( $lr['elements'] ?? array() ) && 'Spatial Ergonomics' === wp_strip_all_tags( (string) ( $lr['elements'][0]['settings']['title'] ?? '' ) ), $lr );
ot( 'leaf row: justify and gap from the source', 'space-between' === ( $lr['settings']['flex_justify_content'] ?? '' ) && '12' === (string) ( $lr['settings']['flex_gap']['column'] ?? '' ), $lr['settings'] ?? null );
ot( 'leaf row: each leaf keeps its type', 'uppercase' === ( $lr['elements'][0]['settings']['typography_text_transform'] ?? '' ), $lr['elements'][0]['settings'] ?? null );
ot( 'leaf row: richer markup (an SVG) stays verbatim', 'html' === ( $or['elements'][0]['elements'][1]['widgetType'] ?? '' ), $or['elements'][0]['elements'][1]['widgetType'] ?? null );

// 8i. A raw-HTML COLUMN holding one ticker item — a plain wrapper around a flex row of an empty dot and a label —
//     is native too: the wrapper is unwrapped, the empty decorative dot dropped, the label a styled text widget.
$tick = '<div class="sc-tw"><div class="ticker-item" data-sc-cs="color:rgb(120, 110, 100);font-size:12px;letter-spacing:2.4px;text-transform:uppercase;display:flex;gap:16px;align-items:center">'
	. '<div class="ticker-dot" data-sc-cs="background-color:rgb(230, 160, 120);border-radius:50%;display:block"></div><span>Atmospheric Equilibrium</span></div></div>';
$mt = FW_SC_Site_Model::from_mapping( array( 'pages' => array( array( 'title' => 'T', 'slug' => 't', 'sections' => array(
	array( 'sectionCs' => 'display:block', 'blocks' => array( array( 't' => 'row', 'gap' => 64, 'nowrap' => true, 'cols' => array(
		array( 'wResp' => array( 'desktop' => 1 ), 'html' => $tick ), array( 'wResp' => array( 'desktop' => 1 ), 'html' => $tick ),
	) ) ) ),
) ) ) ) );
$ot = ( new FW_SC_Elementor_Emitter( array( 'seed' => 'tick', 'colors' => array(), 'fonts' => array( 'heading' => '', 'body' => '' ) ) ) )->page( $mt['pages'][0] );
$th = $find( $ot['elements'], 'heading' );
ot( 'ticker: a raw-HTML ticker column is a styled text widget, not HTML', 0 === (int) $ot['trace']['html'] && $th && 'Atmospheric Equilibrium' === wp_strip_all_tags( (string) $th['settings']['title'] ) && 'uppercase' === ( $th['settings']['typography_text_transform'] ?? '' ), array( $ot['trace'], $th['settings'] ?? null ) );

// 8j. A ticker item that MIXES text and inline spans ("Atmospheric O₂ <span>+340 ppm</span>") is one text widget; the
//     inline span keeps its own colour as an inline style, the empty dot is dropped.
$mix = '<div class="sc-tw"><span class="ticker-item" data-sc-cs="display:inline-flex;color:rgb(90, 90, 90);font-size:11px;text-transform:uppercase">'
	. '<span class="ticker-dot" data-sc-cs="background-color:rgb(0, 200, 120)"></span>Atmospheric O₂ <span class="ticker-val" data-sc-cs="color:rgb(0, 150, 90);font-weight:600">+340 ppm</span></span></div>';
$mm = FW_SC_Site_Model::from_mapping( array( 'pages' => array( array( 'title' => 'M', 'slug' => 'm', 'sections' => array(
	array( 'sectionCs' => 'display:block', 'blocks' => array( array( 't' => 'code', 'html' => $mix ) ) ),
) ) ) ) );
$om  = ( new FW_SC_Elementor_Emitter( array( 'seed' => 'mix', 'colors' => array(), 'fonts' => array( 'heading' => '', 'body' => '' ) ) ) )->page( $mm['pages'][0] );
$mh  = $find( $om['elements'], 'heading' );
$mt_ = (string) ( $mh['settings']['title'] ?? '' );
ot( 'rich leaf: mixed text + inline span is one text widget with the span styled inline', 0 === (int) $om['trace']['html'] && false !== strpos( $mt_, 'Atmospheric O₂' ) && (bool) preg_match( '/<span style="[^"]*color:rgb\(0, 150, 90\)[^"]*">\+340 ppm<\/span>/', $mt_ ), array( $om['trace'], $mt_ ) );

// 8k. A raw-HTML profile card — a skinned flex column of an avatar, a name and a role — is a native composed card:
//     the container wears the card's skin, the avatar is an image widget at its source size, the texts keep their type.
$card_html = '<div class="sc-tw"><div class="glass rounded-[1.35rem] p-5 flex flex-col items-center text-center" data-sc-cs="background-image:linear-gradient(rgba(255, 255, 255, 0.043), rgba(255, 255, 255, 0.02));color:rgb(255, 255, 255);text-align:center;padding:20px;border-top-width:1px;border-top-style:solid;border-top-color:rgba(255, 255, 255, 0.08);border-radius:21.6px;box-shadow:rgba(0, 0, 0, 0.45) 0px 18px 80px 0px;display:flex;align-items:center;flex-direction:column">'
	. '<img src="https://example.test/team/a.png" alt="Ada Example" class="w-20 h-20 rounded-full mb-4 object-cover" data-sc-cs="margin:0px 0px 16px;border-radius:9999px">'
	. '<div class="text-lg font-semibold" data-sc-cs="color:rgb(255, 255, 255);font-size:18px;font-weight:600;text-align:center">Ada Example</div>'
	. '<div class="mt-1 text-sm" data-sc-cs="color:rgb(255, 255, 255);font-size:14px;text-align:center;margin:4px 0px 0px">Design Engineer</div></div></div>';
$mk = FW_SC_Site_Model::from_mapping( array( 'pages' => array( array( 'title' => 'P', 'slug' => 'p', 'sections' => array(
	array( 'sectionCs' => 'display:block', 'blocks' => array( array( 't' => 'row', 'gap' => 16, 'cols' => array( array( 'wResp' => array( 'desktop' => 3 ), 'html' => $card_html ) ) ) ) ),
) ) ) ) );
$ok_ = ( new FW_SC_Elementor_Emitter( array( 'seed' => 'pcard', 'colors' => array(), 'fonts' => array( 'heading' => '', 'body' => '' ) ) ) )->page( $mk['pages'][0] );
$img = $find( $ok_['elements'], 'image' );
ot( 'profile card: no HTML widget, the avatar is an image widget at 80px', 0 === (int) $ok_['trace']['html'] && $img && 'https://example.test/team/a.png' === ( $img['settings']['image']['url'] ?? '' ) && '80' === (string) ( $img['settings']['width']['size'] ?? '' ), array( $ok_['trace'], $img['settings'] ?? null ) );
ot( 'profile card: the card skin rides the container (gradient, radius, shadow, padding)', false !== strpos( $ok_['css'], 'linear-gradient(rgba(255, 255, 255, 0.043)' ) && false !== strpos( $ok_['css'], 'box-shadow:rgba(0, 0, 0, 0.45)' ), $ok_['css'] );
$nm = $find( $ok_['elements'], 'heading' );
ot( 'profile card: the name keeps its type and centring', $nm && 'Ada Example' === wp_strip_all_tags( (string) $nm['settings']['title'] ) && '600' === (string) ( $nm['settings']['typography_font_weight'] ?? '' ) && 'center' === ( $nm['settings']['align'] ?? '' ), $nm['settings'] ?? null );

// The card's skin is painted ONCE: the column does not add the mapping's card box around a card that wears its own.
$mk2 = FW_SC_Site_Model::from_mapping( array( 'pages' => array( array( 'title' => 'P', 'slug' => 'p', 'sections' => array(
	array( 'sectionCs' => 'display:block', 'blocks' => array( array( 't' => 'row', 'gap' => 16, 'cols' => array( array( 'wResp' => array( 'desktop' => 3 ), 'html' => $card_html,
		'cardBox' => array( 'gradient' => 'linear-gradient(rgba(255, 255, 255, 0.043), rgba(255, 255, 255, 0.02))', 'radius' => '21.6px', 'shadow' => 'rgba(0, 0, 0, 0.45) 0px 18px 80px 0px' ) ) ) ) ) ),
) ) ) ) );
$ok2 = ( new FW_SC_Elementor_Emitter( array( 'seed' => 'pcard2', 'colors' => array(), 'fonts' => array( 'heading' => '', 'body' => '' ) ) ) )->page( $mk2['pages'][0] );
ot( 'profile card: the skin is painted once, not boxed twice', 1 === substr_count( $ok2['css'], 'box-shadow:rgba(0, 0, 0, 0.45)' ), $ok2['css'] );

// 8l. An image's source ALT text reaches the media lookup, so the imported attachment carries it (Elementor renders
//     a library image's alt from the attachment, not from the widget).
$alts = array();
$oa2 = ( new FW_SC_Elementor_Emitter( array( 'seed' => 'alt', 'colors' => array(), 'fonts' => array( 'heading' => '', 'body' => '' ),
	'media' => function ( $url, $alt = '' ) use ( &$alts ) { $alts[ $url ] = $alt; return array( 'id' => 0, 'url' => $url ); } ) ) )->page( $mk['pages'][0] );
ot( 'alt: the avatar alt is handed to the media lookup', 'Ada Example' === ( $alts['https://example.test/team/a.png'] ?? '' ), $alts );

// 8m. A section background video recorded on the SECTION (`sectionBgVideo`) brings its hero height, centring and
//     overlay along — the native build makes it a full-viewport, centred hero; the URL alone left a short band.
$mh = FW_SC_Site_Model::from_mapping( array( 'pages' => array( array( 'title' => 'H', 'slug' => 'h', 'sections' => array(
	array( 'sectionCs' => 'display:block', 'sectionValign' => 'middle',
		'sectionBgVideo' => array( 'src' => 'https://example.test/hero.mp4', 'hero_height' => '100vh', 'valign' => 'middle', 'overlay' => 'rgba(0, 0, 0, 0.3)' ),
		'blocks' => array( array( 't' => 'heading', 'tag' => 'h1', 'text' => 'Video hero' ) ) ),
) ) ) ) );
$hs = $mh['pages'][0]['sections'][0];
ot( 'bg video: the section takes the video hero height and overlay', '100vh' === $hs['min_height'] && 'rgba(0, 0, 0, 0.3)' === $hs['background']['overlay'], array( $hs['min_height'], $hs['background'] ) );

// 8n. An image box (a photo card with a scrim and a caption over it) keeps its PHOTO: the card is a container with
//     the photo as a cover background, the scrim layered over it and the caption placed at the bottom; it was an
//     unknown block whose words were rescued and whose image was lost.
$mib = FW_SC_Site_Model::from_mapping( array( 'pages' => array( array( 'title' => 'I', 'slug' => 'i', 'sections' => array(
	array( 'sectionCs' => 'display:block', 'blocks' => array( array( 't' => 'row', 'gap' => 24, 'cols' => array( array( 'wResp' => array( 'desktop' => 8 ), 'minh' => 280, 'blocks' => array(
		array( 't' => 'image_box',
			'card' => array( 'image' => array( 'src' => 'https://example.test/photo.jpg', 'alt' => 'A basin mill' ), 'title' => 'Isolated Basin Mill', 'titleTag' => 'h3',
				'titleCs' => 'color:rgb(255, 255, 255);font-size:30px;font-weight:700', 'text' => '<p>A mill over a resin river.</p>',
				'button' => array( 'label' => 'View Details', 'href' => '#', 'cs' => 'color:rgb(255, 255, 255);border-top-width:1px;border-top-color:rgba(255, 255, 255, 0.2);border-radius:999px' ),
				'cardCs' => 'border-radius:32px' ),
			'overlay' => array( 'reveal' => 'scrim', 'scrim' => 'linear-gradient(to top, rgb(10, 10, 12), rgba(0, 0, 0, 0))', 'place' => 'bottom', 'align' => 'left', 'inset' => 32 ) ),
	) ) ) ) ) ),
) ) ) ) );
ot( 'image box: the Site Model keeps the image box and its photo', 'image_box' === ( $mib['pages'][0]['sections'][0]['blocks'][0]['columns'][0]['blocks'][0]['type'] ?? '' ) && 'https://example.test/photo.jpg' === ( $mib['pages'][0]['sections'][0]['blocks'][0]['columns'][0]['blocks'][0]['card']['image'] ?? '' ) );
$oib = ( new FW_SC_Elementor_Emitter( array( 'seed' => 'ibox', 'colors' => array(), 'fonts' => array( 'heading' => '', 'body' => '' ) ) ) )->page( $mib['pages'][0] );
$ibc = $oib['elements'][0]['elements'][0]['elements'][0]['elements'][0] ?? array();
ot( 'image box: a container with the photo as a cover background', 'container' === ( $ibc['elType'] ?? '' ) && 'https://example.test/photo.jpg' === ( $ibc['settings']['background_image']['url'] ?? '' ) && 'cover' === ( $ibc['settings']['background_size'] ?? '' ), $ibc['settings'] ?? null );
ot( 'image box: the scrim is layered over the photo, the caption sits at the bottom', false !== strpos( $oib['css'], 'linear-gradient(to top, rgb(10, 10, 12)' ) && 'flex-end' === ( $ibc['settings']['flex_justify_content'] ?? '' ), array( $ibc['settings']['flex_justify_content'] ?? null, $oib['css'] ) );
ot( 'image box: title, text and button are native widgets', $find( array( $ibc ), 'heading' ) && $find( array( $ibc ), 'text-editor' ) && $find( array( $ibc ), 'button' ) && 0 === (int) $oib['trace']['html'] );

// 8o. A heading's PHONE size comes from its own responsive Tailwind classes when the capture recorded no phone style:
//     `text-7xl md:text-[7rem]` is 72px below md — at the desktop 112px one long word overflowed a phone.
$mph = FW_SC_Site_Model::from_mapping( array( 'pages' => array( array( 'title' => 'F', 'slug' => 'f', 'sections' => array(
	array( 'sectionCs' => 'display:block', 'blocks' => array(
		array( 't' => 'heading', 'level' => 1, 'text' => 'Autonomous Routing', 'cls' => 'text-7xl md:text-[7rem] font-medium', 'cs' => 'font-size:112px;line-height:95.2px' ),
		array( 't' => 'heading', 'level' => 2, 'text' => 'Fixed size', 'cls' => 'text-4xl font-medium', 'cs' => 'font-size:36px' ),
	) ),
) ) ) ) );
$hb = $mph['pages'][0]['sections'][0]['blocks'];
ot( 'phone size: a responsive text class gives the phone font size', '72px' === ( $hb[0]['style_sm']['font-size'] ?? '' ), $hb[0]['style_sm'] ?? null );
ot( 'phone size: a non-responsive size adds none', empty( $hb[1]['style_sm']['font-size'] ), $hb[1]['style_sm'] ?? null );
$oph = ( new FW_SC_Elementor_Emitter( array( 'seed' => 'phone', 'colors' => array(), 'fonts' => array( 'heading' => '', 'body' => '' ) ) ) )->page( $mph['pages'][0] );
ot( 'page root: horizontal overflow is clipped like the native page-builder wrapper', false !== strpos( $oph['css'], '.elementor[data-elementor-type="wp-page"]{overflow-x:clip}' ), $oph['css'] );
ot( 'phone size: the heading widget carries the mobile font size', '72' === (string) ( $oph['elements'][0]['elements'][0]['settings']['typography_font_size_mobile']['size'] ?? '' ), $oph['elements'][0]['elements'][0]['settings']['typography_font_size_mobile'] ?? null );

// 9. The site container comes from Theme Settings' General → Layout group (`general_layout`), where the theme
//    reads it; reading a top-level key found nothing and every section fell to Elementor's 1140px default.
if ( function_exists( 'fw_get_db_settings_option' ) && class_exists( 'FW_SC_Target_Elementor' ) ) {
	$cw = fw_get_db_settings_option( 'general_layout/layout_container_width', array() );
	if ( isset( $cw['lg']['value'] ) && 'px' === ( $cw['lg']['unit'] ?? 'px' ) ) {
		$rv = new ReflectionMethod( 'FW_SC_Target_Elementor', 'site_container_vote' ); $rv->setAccessible( true );
		$got = $rv->invoke( new FW_SC_Target_Elementor(), array( 'pages' => array() ) );
		ot( 'container: the site container is Theme Settings general_layout width', (int) $cw['lg']['value'] === (int) $got, array( $cw['lg'], $got ) );
	}
}

echo "\n" . $GLOBALS['__pass'] . ' passed, ' . $GLOBALS['__fail'] . " failed\n";
exit( $GLOBALS['__fail'] ? 1 : 0 );
