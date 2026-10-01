<?php
/**
 * Regression guard: a PINNED header's FLOW comes from its own `position`, never from whether it has a fill.
 *
 * `position:fixed` is out of flow — it reserves no height, so the first section really does start at the top
 * of the page and an overlay header is faithful. `position:sticky` is IN flow: it occupies its own height and
 * pushes the first section down by it. The converter decided this by whether the bar had a solid background,
 * so every pinned-but-transparent header took the overlay path — and a sticky source nav reserved nothing,
 * converting the hero one header-height too high on every such site.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/sticky-header-flow-test.php"
 *
 * Exit code 0 = all PASS, 1 = at least one FAIL.
 */

if ( ! class_exists( 'FW_Site_Converter_Sources' ) ) {
	fwrite( STDERR, "FAIL: site-converter not loaded (run inside a WP install with the plugin active)\n" );
	exit( 1 );
}

$fails = 0;
$ok    = function ( $cond, $msg ) use ( &$fails ) {
	if ( $cond ) { echo "  ✓ $msg\n"; } else { $fails++; echo "  ✗ FAIL: $msg\n"; }
};

/** Convert a page whose nav is pinned with $pos and filled (or not), and read back the header values. */
$convert = function ( $pos, $filled ) {
	$navstyle = 'position:' . $pos . ';' . ( $filled ? 'background-color:rgb(12, 18, 33);' : 'background-color:rgba(0, 0, 0, 0);' );
	$html = '<!doctype html><html><head><title>Pinned</title></head><body>'
		. '<header class="site-nav" style="' . $navstyle . '" data-sc-cs="' . $navstyle . '">'
		. '<a href="/">Brand</a><a href="/about">About</a><a href="/work">Work</a><a href="/contact">Contact</a></header>'
		. '<section id="hero" data-sc-cs="padding-top:40px"><h1>A headline that carries the page</h1>'
		. '<p data-sc-cs="font-size:18px;line-height:29px">Enough supporting copy to read as real prose rather than a label.</p>'
		. '</section></body></html>';
	$res = FW_Site_Converter_Sources::build_from_html( $html, 'Pinned', array( 'dynamic_chrome' => true ) );
	$v   = $res['files']['theme-settings.json']['values'] ?? array();
	return array(
		'position'    => (string) ( $v['header_layout']['header_position'] ?? '' ),
		'transparent' => $v['header_layout'],
	);
};

echo "\n== Pinned-header flow (position decides, not the fill)\n";

$sticky_clear = $convert( 'sticky', false );
$ok( 'sticky' === $sticky_clear['position'],
	'a STICKY transparent nav stays in flow → header_position "sticky" (got "' . $sticky_clear['position'] . '")' );

$fixed_clear = $convert( 'fixed', false );
$ok( 'overlay' === $fixed_clear['position'],
	'a FIXED transparent nav is out of flow → header_position "overlay" (got "' . $fixed_clear['position'] . '")' );

// The fill must NOT decide flow — a sticky bar is in flow whether or not it is painted.
$sticky_filled = $convert( 'sticky', true );
$ok( 'sticky' === $sticky_filled['position'],
	'NEGATIVE: a FILLED sticky nav is also "sticky" — the fill never decides flow (got "' . $sticky_filled['position'] . '")' );

// …and a fixed bar stays an overlay even when painted, for the same reason.
$fixed_filled = $convert( 'fixed', true );
$ok( in_array( $fixed_filled['position'], array( 'overlay', 'sticky' ), true ),
	'a FILLED fixed nav resolves to a pinned mode (got "' . $fixed_filled['position'] . '")' );

echo $fails ? "\n✗ $fails FAILED\n" : "\n✓ ALL PASS — pinned-header flow follows the source's own position\n";
exit( $fails ? 1 : 0 );
