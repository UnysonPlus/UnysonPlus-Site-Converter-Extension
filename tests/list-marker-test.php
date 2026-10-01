<?php
/**
 * Regression guard: a converted list keeps the SOURCE's marker, and never invents one.
 *
 * Every unordered `<ul>` was mapped to the feature list's CHECKLIST design, which draws a check glyph per
 * item. On a page of caveats ("SEO is not a one-time task…") that turned a prose list into a list of things
 * ticked off, and added 4 and 5 icons to two sections that have none in the source.
 *
 * The `<li>`'s own computed style says which kind of list it is: a native `display: list-item` with a marker
 * is a BULLET list, a `list-style: none` run has no marker of its own, and a source that draws a glyph per
 * item still renders that glyph through the per-item icon path regardless of the design.
 *
 * Measured over 138 captured pages: 440 unordered lists, 353 of them bullet lists and 87 marker-less. Of the
 * marker-less, 69% draw their own glyph (which still renders) and 31% show no marker in the source either —
 * so nothing loses a marker it actually had.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/list-marker-test.php"
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

$design_of = function ( $html ) {
	$res  = FW_Site_Converter_Sources::build_from_html( $html, 'List', array( 'dynamic_chrome' => true ) );
	$walk = function ( $nodes ) use ( &$walk ) {
		foreach ( (array) $nodes as $n ) {
			if ( ! is_array( $n ) ) { continue; }
			if ( 'feature_list' === ( $n['shortcode'] ?? '' ) ) { return $n; }
			$hit = $walk( $n['_items'] ?? array() );
			if ( $hit ) { return $hit; }
		}
		return null;
	};
	$n = $walk( $res['files']['pages.json']['pages'][0]['builder'] ?? array() );
	return is_array( $n ) ? (array) $n['atts'] : array();
};

$ink  = 'color:rgb(255, 255, 255);font-family:Inter, sans-serif;font-size:16px;line-height:24px;';
$page = function ( $list ) use ( $ink ) {
	return '<!DOCTYPE html><html><head><title>List</title></head><body><main>'
		. '<section data-sc-cs="' . $ink . 'padding:96px 24px;display:block">'
		. '<h2 data-sc-cs="font-size:36px;font-weight:700">What this is not</h2>'
		. '<p data-sc-cs="font-size:16px;line-height:26px">A short run of copy before the list, long enough to read as real.</p>'
		. $list . '</section></main></body></html>';
};

$ITEMS = array(
	'It is not a one-time task, because the ground keeps shifting under everyone who works on it.',
	'It does not guarantee a rank or a timeline, and anyone promising one is overpromising.',
	'It is not just keywords, and writing for them alone reads badly and tends to backfire.',
);

echo "\n== A native bulleted list keeps its bullets\n";

$li_bullet = '';
foreach ( $ITEMS as $t ) { $li_bullet .= '<li data-sc-cs="display:list-item;font-size:15px;line-height:24px">' . $t . '</li>'; }
$a = $design_of( $page( '<ul data-sc-cs="display:block;padding-left:24px">' . $li_bullet . '</ul>' ) );
$ok( ! empty( $a ), 'the list converts to a feature_list node' );
$ok( 'bullet' === (string) ( $a['design'] ?? '' ),
	'a `display:list-item` source maps to the BULLET design (got "' . ( $a['design'] ?? '' ) . '")' );
$ok( 'check' !== (string) ( $a['design'] ?? '' ),
	'...and never to the checklist, which would draw a glyph the source has not got' );

echo "\n== A marker-less list gets no marker\n";

$li_none = '';
foreach ( $ITEMS as $t ) { $li_none .= '<li data-sc-cs="display:block;list-style-type:none;font-size:15px;line-height:24px">' . $t . '</li>'; }
$b = $design_of( $page( '<ul data-sc-cs="display:block;list-style-type:none;padding-left:0px">' . $li_none . '</ul>' ) );
$ok( 'none' === (string) ( $b['design'] ?? '' ),
	'a `list-style:none` source maps to the NONE design (got "' . ( $b['design'] ?? '' ) . '")' );

echo "\n== NEGATIVE: the source's own glyph still renders\n";

$li_icon = '';
foreach ( $ITEMS as $t ) {
	$li_icon .= '<li data-sc-cs="display:block;list-style-type:none;font-size:15px;line-height:24px">'
		. '<svg class="lucide lucide-circle-check" width="18" height="18" viewBox="0 0 24 24"><path d="m9 12 2 2 4-4"></path></svg>'
		. '<span data-sc-cs="font-size:15px">' . $t . '</span></li>';
}
$c = $design_of( $page( '<ul data-sc-cs="display:block;list-style-type:none;padding-left:0px">' . $li_icon . '</ul>' ) );
$c_items = (array) ( $c['items'] ?? array() );
$svg_kept = 0;
foreach ( $c_items as $it ) { if ( 'svg' === ( $it['icon']['type'] ?? '' ) ) { $svg_kept++; } }
$ok( $svg_kept >= 1,
	'NEGATIVE: a list whose items draw their OWN glyph keeps it, whatever the design (got ' . $svg_kept . ' of ' . count( $c_items ) . ')' );

echo "\n== NEGATIVE: an ordered list is still numbered\n";

$li_ol = '';
foreach ( $ITEMS as $t ) { $li_ol .= '<li data-sc-cs="display:list-item;font-size:15px;line-height:24px">' . $t . '</li>'; }
$d = $design_of( $page( '<ol data-sc-cs="display:block;padding-left:24px">' . $li_ol . '</ol>' ) );
$ok( 'numbered' === (string) ( $d['design'] ?? '' ),
	'NEGATIVE: an <ol> still maps to numbered (got "' . ( $d['design'] ?? '' ) . '")' );

echo "\n== NEGATIVE: an unmeasured list keeps the historic default\n";

$li_bare = '';
foreach ( $ITEMS as $t ) { $li_bare .= '<li>' . $t . '</li>'; }
$e = $design_of( $page( '<ul>' . $li_bare . '</ul>' ) );
$ok( 'check' === (string) ( $e['design'] ?? '' ),
	'NEGATIVE: with no stamp to read, the historic `check` default stands rather than a guess (got "' . ( $e['design'] ?? '' ) . '")' );

echo $fails ? "\n\xe2\x9c\x97 $fails FAILED\n" : "\n\xe2\x9c\x93 ALL PASS - a converted list keeps the source's marker and invents none\n";
exit( $fails ? 1 : 0 );
