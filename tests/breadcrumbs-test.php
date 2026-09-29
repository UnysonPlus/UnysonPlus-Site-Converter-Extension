<?php
/**
 * Guard for BREADCRUMB detection.
 *
 * Until this existed the converter only ever REJECTED breadcrumbs: every list and menu recognizer carries a
 * `NOT a … breadcrumb` guard so it does not misread one as a bullet list. Rejected but never claimed, a
 * trail fell through to the generic decompose and came out as its pieces — on a real conversion the
 * source's single inline `<nav aria-label="Breadcrumb">Home › About</nav>` became an `<a>Home</a>` and a
 * separate `<p>About</p>` stacked 21px apart, separator gone. The text survived; the thing it formed did not.
 *
 * Two findings from building it are pinned here, because both were invisible:
 *
 *   1. MASTHEAD DETECTION ATE IT. Any body `<nav>` with `flex` + `items-center` was claimed as the site
 *      header and removed from the body wholesale. That is a shape test, and a trail has exactly that shape.
 *      Measured: `class="flex"` converted, `class="flex items-center"` vanished — not a distinction any
 *      author intends.
 *   2. AN INNER PAGE'S DEPENDENCIES WERE DISCARDED. Extension activation read the FRONT page's build only,
 *      so a feature living on inner pages (a trail on every page but the home page; a contact form on
 *      /contact) emitted its require_extension() into a result that was read for pages and thrown away. The
 *      shortcode landed in the page and the extension that renders it stayed inactive.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs --allow-root eval-file \
 *     "D:/Web Dev/unysonplus/framework/extensions/site-converter/tests/breadcrumbs-test.php"
 */

if ( ! class_exists( 'FW_Site_Converter_Sources' ) ) {
	fwrite( STDERR, "Site Converter not loaded — activate the extension first.\n" );
	exit( 1 );
}

$pass = 0; $fail = 0;
$ok = function ( $c, $what, $d = '' ) use ( &$pass, &$fail ) {
	if ( $c ) { $pass++; echo "  \xE2\x9C\x93 {$what}\n"; }
	else { $fail++; echo "  \xE2\x9C\x97 FAIL: {$what}" . ( '' !== $d ? " -- {$d}" : '' ) . "\n"; }
};

/** Build a page whose first section starts with $frag, and report what came out. */
$build = function ( $frag ) {
	$html = '<!doctype html><html><head><title>T</title></head><body><main><section data-sc-cs="display:block">'
		. $frag
		. '<h1>About us and what we do</h1><p>Copy that the conversion carries across intact.</p>'
		. '</section></main></body></html>';
	$res = FW_Site_Converter_Sources::build_from_html( $html, 'About', array( 'source_url' => 'https://example.test/about' ) );
	return array(
		'pages' => wp_json_encode( $res['files']['pages.json'] ?? array() ),
		'needs' => (array) ( $res['files']['theme-design.json']['needs_extensions'] ?? array() ),
	);
};

/* --------------------------------------------- the shapes that count -- */
echo "\n=== A declared breadcrumb trail becomes the native shortcode ===\n";

$shapes = array(
	'<nav aria-label="Breadcrumb">' => '<nav aria-label="Breadcrumb" class="bc" data-sc-cs="display:flex"><a href="/">Home</a><span>&rsaquo;</span>About</nav>',
	'<ol class="breadcrumb">'       => '<ol class="breadcrumb" data-sc-cs="display:flex"><li><a href="/">Home</a></li><li>About</li></ol>',
	'<div class="breadcrumbs">'     => '<div class="breadcrumbs" data-sc-cs="display:flex"><a href="/">Home</a><span>&rsaquo;</span>About</div>',
	'schema.org BreadcrumbList'     => '<div itemtype="https://schema.org/BreadcrumbList" data-sc-cs="display:flex"><a href="/">Home</a><span>&rsaquo;</span>About</div>',
);
foreach ( $shapes as $label => $frag ) {
	$r = $build( $frag );
	$ok( false !== strpos( $r['pages'], '[breadcrumbs]' ), 'detected: ' . $label );
}

/* --------------------------------------------- the masthead trap ------- */
echo "\n=== The masthead shape test must not eat a trail ===\n";

// `flex items-center` is the signature masthead detection keys on, and it is also how most trails are laid
// out. The element declares what it is; that declaration has to win over a guess from utility classes.
foreach ( array( 'flex', 'flex items-center', 'flex items-center gap-2 text-sm text-muted' ) as $cls ) {
	$r = $build( '<nav aria-label="Breadcrumb" class="' . $cls . '" data-sc-cs="display:flex"><a href="/">Home</a><span>&rsaquo;</span>About</nav>' );
	$ok( false !== strpos( $r['pages'], '[breadcrumbs]' ), 'survives class="' . $cls . '"' );
}

/* --------------------------------------------- activation -------------- */
echo "\n=== The page declares the extension it needs ===\n";

$r = $build( '<nav aria-label="Breadcrumb" class="bc" data-sc-cs="display:flex"><a href="/">Home</a><span>&rsaquo;</span>About</nav>' );
// Without this the shortcode lands in the page and the extension that renders it stays inactive, so the
// page shows nothing and the cause is three files away.
$ok( in_array( 'breadcrumbs', $r['needs'], true ), 'needs_extensions carries `breadcrumbs`',
	'needs=' . wp_json_encode( $r['needs'] ) );

/* --------------------------------------------- the negatives ----------- */
echo "\n=== NEGATIVES: a row of links is not a trail ===\n";

// Converting a site's main nav into a breadcrumb would be far worse than leaving a trail undetected, so
// detection requires an explicit declaration and never infers one from shape alone.
$nav = $build( '<nav class="main-nav flex items-center" data-sc-cs="display:flex"><a href="/a">Financing</a><a href="/b">Resources</a><a href="/c">FAQ</a></nav>' );
$ok( false === strpos( $nav['pages'], '[breadcrumbs]' ), 'NEGATIVE: an undeclared nav of 3 links is NOT a trail' );

$tags = $build( '<div class="tag-list flex items-center" data-sc-cs="display:flex"><a href="/t/a">Design</a><a href="/t/b">Build</a></div>' );
$ok( false === strpos( $tags['pages'], '[breadcrumbs]' ), 'NEGATIVE: a tag row is NOT a trail' );

$empty = $build( '<nav aria-label="Breadcrumb" class="bc" data-sc-cs="display:flex"></nav>' );
$ok( false === strpos( $empty['pages'], '[breadcrumbs]' ), 'NEGATIVE: a declared but EMPTY nav is not a trail' );

$single = $build( '<nav aria-label="Breadcrumb" class="bc" data-sc-cs="display:flex"><a href="/">Home</a></nav>' );
$ok( false === strpos( $single['pages'], '[breadcrumbs]' ), 'NEGATIVE: one lone crumb is not a trail (two is the minimum)' );

echo "\n========================================\n";
echo 'BREADCRUMBS RESULT: ' . ( $fail ? 'FAIL' : 'PASS' ) . "   ({$pass} passed, {$fail} failed)\n";
echo "========================================\n";
exit( $fail ? 1 : 0 );
