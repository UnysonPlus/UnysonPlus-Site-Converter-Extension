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

echo "\n" . $GLOBALS['__pass'] . ' passed, ' . $GLOBALS['__fail'] . " failed\n";
exit( $GLOBALS['__fail'] ? 1 : 0 );
