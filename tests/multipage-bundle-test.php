<?php
/**
 * MULTI-PAGE BUNDLE — the site is the unit of conversion.
 *
 * A capture writes one DOM snapshot per page under `pages/<slug>/rendered.html` and lists them in
 * `pages-manifest.json`. The importer runs the SITE phases once and then loops the page snapshots, so a
 * site's theme, chrome, menus and permalinks are derived once instead of being re-derived (and rewritten)
 * by whichever page happened to import last.
 *
 * This guards the decisions that keep that loop safe and predictable:
 *   - the FRONT page entry is skipped by the loop (it already ran as the bundle's own page phase)
 *   - a manifest is DATA: a path in it may not escape the bundle directory
 *   - a missing or style-less snapshot is reported, never silently treated as a converted page
 *   - a bundle with no manifest is a no-op, so every single-page bundle behaves exactly as before
 *   - an INNER-page capture is recognised from its recorded URL, which is what stops it regenerating
 *     and activating a whole site theme named after itself
 *
 * Every case here returns before the importer reaches build_from_html or the database, so the test is
 * side-effect free and safe to run against a live install.
 *
 * Usage (run with wp-cli so the plugin is loaded):
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs --allow-root eval-file \
 *       "D:/Web Dev/unysonplus/framework/extensions/site-converter/tests/multipage-bundle-test.php"
 */

if ( ! class_exists( 'FW_Site_Converter_Bundle' ) ) {
	echo "FAIL: site-converter not loaded (run inside a WP install with the plugin active)\n";
	return;
}

$GLOBALS['__mp_pass'] = 0;
$GLOBALS['__mp_fail'] = 0;

function mp( $label, $cond, $got = null ) {
	if ( $cond ) {
		$GLOBALS['__mp_pass']++;
		echo "  PASS  $label\n";
	} else {
		$GLOBALS['__mp_fail']++;
		echo "  FAIL  $label" . ( null !== $got ? '  (got: ' . ( is_scalar( $got ) ? $got : wp_json_encode( $got ) ) . ')' : '' ) . "\n";
	}
}

/** Call a private static of the bundle class. */
function mp_call( $method, &$out, ...$args ) {
	$m = new ReflectionMethod( 'FW_Site_Converter_Bundle', $method );
	$m->setAccessible( true );
	if ( 'import_page_snapshots' === $method ) {
		return $m->invokeArgs( null, array( $args[0], &$out ) );
	}
	return $m->invokeArgs( null, $args );
}

/** A throwaway bundle directory. */
function mp_bundle( $files ) {
	$dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/upw-mp-' . substr( md5( wp_json_encode( $files ) . microtime( true ) ), 0, 10 );
	@mkdir( $dir, 0777, true );
	foreach ( $files as $rel => $body ) {
		$path = $dir . '/' . $rel;
		@mkdir( dirname( $path ), 0777, true );
		file_put_contents( $path, $body );
	}
	return $dir;
}

function mp_rmdir( $dir ) {
	if ( ! is_dir( $dir ) ) { return; }
	foreach ( array_diff( (array) scandir( $dir ), array( '.', '..' ) ) as $f ) {
		$p = $dir . '/' . $f;
		is_dir( $p ) ? mp_rmdir( $p ) : @unlink( $p );
	}
	@rmdir( $dir );
}

echo "\n=== MULTI-PAGE BUNDLE — the site is the unit ===\n";

/* ---------------------------------------------------------------------------------------------
 * [MP1] The page loop honours the manifest, and refuses anything it should not read.
 * ------------------------------------------------------------------------------------------- */
$stamped = '<!DOCTYPE html><html><body data-sc-cs="display:block"><p data-sc-cs="display:block">Hi</p></body></html>';
$dir = mp_bundle( array(
	'rendered.html'        => $stamped,
	'pages/ok/rendered.html' => $stamped,
	'pages-manifest.json'  => wp_json_encode( array( 'pages' => array(
		array( 'slug' => 'home',     'url' => 'https://example.test/',         'front' => true,  'rendered' => 'rendered.html' ),
		array( 'slug' => 'escape',   'url' => 'https://example.test/escape',   'front' => false, 'rendered' => '../../../secrets.html' ),
		array( 'slug' => 'absolute', 'url' => 'https://example.test/absolute', 'front' => false, 'rendered' => '/etc/passwd' ),
		array( 'slug' => 'drive',    'url' => 'https://example.test/drive',    'front' => false, 'rendered' => 'C:/Windows/win.ini' ),
		array( 'slug' => 'gone',     'url' => 'https://example.test/gone',     'front' => false, 'rendered' => 'pages/gone/rendered.html' ),
		array( 'slug' => 'bare',     'url' => 'https://example.test/bare',     'front' => false, 'rendered' => 'pages/bare/rendered.html' ),
	) ) ),
	'pages/bare/rendered.html' => '<!DOCTYPE html><html><body><p>no captured styles here</p></body></html>',
) );
$out  = array();
$rows = mp_call( 'import_page_snapshots', $out, $dir );
$by   = array();
foreach ( (array) $rows as $r ) { $by[ $r['slug'] ] = $r; }

mp( '[MP1] the FRONT page entry is not re-imported by the page loop', ! isset( $by['home'] ), wp_json_encode( array_keys( $by ) ) );
mp( '[MP1] a manifest path that climbs out of the bundle is refused', isset( $by['escape'] ) && empty( $by['escape']['ok'] ) && false !== stripos( (string) $by['escape']['error'], 'unsafe' ), wp_json_encode( $by['escape'] ?? null ) );
mp( '[MP1] an absolute POSIX path is refused', isset( $by['absolute'] ) && false !== stripos( (string) ( $by['absolute']['error'] ?? '' ), 'unsafe' ), wp_json_encode( $by['absolute'] ?? null ) );
mp( '[MP1] a Windows drive path is refused', isset( $by['drive'] ) && false !== stripos( (string) ( $by['drive']['error'] ?? '' ), 'unsafe' ), wp_json_encode( $by['drive'] ?? null ) );
mp( '[MP1] a snapshot the bundle lists but does not carry is REPORTED, not silently skipped', isset( $by['gone'] ) && false !== stripos( (string) ( $by['gone']['error'] ?? '' ), 'missing' ), wp_json_encode( $by['gone'] ?? null ) );
mp( '[MP1] a snapshot with no captured styles is reported rather than converted blind', isset( $by['bare'] ) && false !== stripos( (string) ( $by['bare']['error'] ?? '' ), 'captured styles' ), wp_json_encode( $by['bare'] ?? null ) );
mp( '[MP1] nothing was added to the import result for a page that never converted', empty( $out['pages']['pages'] ), wp_json_encode( $out['pages'] ?? null ) );
mp_rmdir( $dir );

/* ---------------------------------------------------------------------------------------------
 * [MP2] NEGATIVE: a single-page bundle is untouched by any of this.
 * ------------------------------------------------------------------------------------------- */
$dir1 = mp_bundle( array( 'rendered.html' => $stamped ) );
$out1 = array();
$rows1 = mp_call( 'import_page_snapshots', $out1, $dir1 );
mp( '[MP2] NEGATIVE: a bundle with no pages-manifest.json is a no-op — every existing single-page bundle converts exactly as before', array() === $rows1, wp_json_encode( $rows1 ) );
mp_rmdir( $dir1 );

/* ---------------------------------------------------------------------------------------------
 * [MP3] An INNER page is recognised from the URL the capture recorded.
 * This is what stops an inner page generating and ACTIVATING a site theme named after itself —
 * three inner-page conversions once produced three child themes and switched the site to each.
 * ------------------------------------------------------------------------------------------- */
$mk = function ( $url ) use ( $stamped ) {
	return mp_bundle( array(
		'rendered.html'        => $stamped,
		'design-capture.json'  => wp_json_encode( array( 'url' => $url ) ),
	) );
};
$cases = array(
	array( 'https://example.test/',            false, 'the site root is the front page' ),
	array( 'https://example.test',             false, 'a root URL with no trailing slash is still the front page' ),
	array( 'https://example.test/index.html',  false, 'an index file is the front page' ),
	array( 'https://example.test/services',    true,  'a path segment is an inner page' ),
	array( 'https://example.test/shop/',       true,  'a trailing slash does not make an inner page a front page' ),
	array( 'https://example.test/a/b',         true,  'a nested path is an inner page' ),
);
foreach ( $cases as $c ) {
	list( $url, $expect, $why ) = $c;
	$d   = $mk( $url );
	$got = mp_call( 'capture_is_inner_page', $noop, $d );
	mp( "[MP3] $why — " . $url, $expect === $got, wp_json_encode( $got ) );
	mp_rmdir( $d );
}

/* ---------------------------------------------------------------------------------------------
 * [MP4] A capture with no recorded URL keeps the old behaviour (treated as a front page), so an
 * older bundle can never be demoted to an inner page and lose its theme.
 * ------------------------------------------------------------------------------------------- */
$d = mp_bundle( array( 'rendered.html' => $stamped ) );
mp( '[MP4] NEGATIVE: a bundle that records no URL is treated as a FRONT page (older bundles keep their theme)', false === mp_call( 'capture_is_inner_page', $noop2, $d ), null );
mp_rmdir( $d );

/* ---------------------------------------------------------------------------------------------
 * [MP5] A SITE IS ONE CONVERSION: many screens in, one mapping holding many pages out.
 * The review path (URL / paste) used to accept a single HTML string, so converting a site through
 * the Convert panel produced only its homepage however many pages the capture had found. Screens
 * now go in together and share ONE design system, header and footer.
 * ------------------------------------------------------------------------------------------- */
$mp_cs = function ( $e = '' ) { return 'color:rgb(20,20,20);font-family:Inter, sans-serif;font-size:16px;font-weight:400;line-height:24px;text-align:start;display:block' . $e; };
$mp_page = function ( $heading ) use ( $mp_cs ) {
	return '<!DOCTYPE html><html><head><title>' . $heading . '</title></head><body data-sc-cs="' . $mp_cs( ';background-color:rgb(255,255,255)' ) . '">'
		. '<header data-sc-cs="' . $mp_cs( ';height:80px' ) . '"><nav data-sc-cs="' . $mp_cs( ';display:flex;gap:32px;height:80px' ) . '"><a href="/" data-sc-cs="' . $mp_cs( ';font-size:22px' ) . '">Brandmark</a><a href="/services" data-sc-cs="' . $mp_cs() . '">Services</a></nav></header>'
		. '<section data-sc-cs="' . $mp_cs( ';padding:96px 0px;height:220px' ) . '"><div data-sc-cs="' . $mp_cs( ';max-width:1280px;margin:0px auto;height:60px' ) . '">'
		. '<h1 data-sc-cs="' . $mp_cs( ';font-size:44px;height:52px' ) . '">' . $heading . '</h1>'
		. '</div></section>'
		. '<footer data-sc-cs="' . $mp_cs( ';padding:48px 0px;height:120px' ) . '"><p data-sc-cs="' . $mp_cs( ';height:24px' ) . '">&copy; 2026 Brandmark</p></footer></body></html>';
};
$mp_build = function ( array $screens ) use ( $mp_page ) {
	$prev = error_reporting( 0 );
	$out  = FW_Site_Converter_Stitch::build_bundle( array( 'screens' => $screens ) );
	error_reporting( $prev );
	return $out;
};
$mp_screens = array(
	array( 'html' => $mp_page( 'Home' ),     'url' => 'https://example.test/',         'slug' => '',         'front' => true ),
	array( 'html' => $mp_page( 'Services' ), 'url' => 'https://example.test/services', 'slug' => 'services', 'front' => false ),
	array( 'html' => $mp_page( 'About' ),    'url' => 'https://example.test/about',    'slug' => 'about',    'front' => false ),
);
$mp_out   = $mp_build( $mp_screens );
$mp_pages = ( is_array( $mp_out ) && isset( $mp_out['mapping']['pages'] ) ) ? $mp_out['mapping']['pages'] : array();
mp( '[MP5] three screens produce a mapping with three pages', 3 === count( $mp_pages ), count( $mp_pages ) );
$mp_slugs = array_map( function ( $p ) { return (string) $p['slug']; }, $mp_pages );
mp( '[MP5] each page keeps its own slug, taken from the URL it was captured from', array( '', 'services', 'about' ) === $mp_slugs, wp_json_encode( $mp_slugs ) );
$mp_fronts = array_filter( $mp_pages, function ( $p ) { return ! empty( $p['front_page'] ); } );
mp( '[MP5] exactly ONE page is the front page', 1 === count( $mp_fronts ), count( $mp_fronts ) );
mp( '[MP5] …and it is the site root, not whichever page arrived first', '' === (string) ( reset( $mp_fronts )['slug'] ?? 'x' ), wp_json_encode( reset( $mp_fronts )['slug'] ?? null ) );

/* ---------------------------------------------------------------------------------------------
 * [MP6] The front page is decided by PATH, never by arrival order or a bad payload.
 * ------------------------------------------------------------------------------------------- */
$mp_many_front = $mp_screens;
$mp_many_front[1]['front'] = true;
$mp_many_front[2]['front'] = true; // three claim it
$mp_mf = $mp_build( $mp_many_front );
$mp_mfp = $mp_mf['mapping']['pages'] ?? array();
mp( '[MP6] a payload marking several front pages yields exactly one (a site cannot have two homepages)',
	1 === count( array_filter( $mp_mfp, function ( $p ) { return ! empty( $p['front_page'] ); } ) ),
	count( array_filter( $mp_mfp, function ( $p ) { return ! empty( $p['front_page'] ); } ) ) );

$mp_no_front = $mp_screens;
foreach ( $mp_no_front as $i => $sc ) { $mp_no_front[ $i ]['front'] = false; } // none claims it
$mp_nf  = $mp_build( $mp_no_front );
$mp_nfp = $mp_nf['mapping']['pages'] ?? array();
$mp_nff = array_filter( $mp_nfp, function ( $p ) { return ! empty( $p['front_page'] ); } );
mp( '[MP6] a payload marking NO front page still produces one, and it is the root', 1 === count( $mp_nff ) && '' === (string) ( reset( $mp_nff )['slug'] ?? 'x' ), wp_json_encode( array_map( function ( $p ) { return $p['slug'] . ( empty( $p['front_page'] ) ? '' : '(front)' ); }, $mp_nfp ) ) );

/* ---------------------------------------------------------------------------------------------
 * [MP7] "Omit page" in the review drops that page and nothing else.
 * Mirrors the section-level omit one level down; the reviewer is the only place a page a user does
 * not want can be vetoed, so it must not disturb its siblings or move the front page.
 * ------------------------------------------------------------------------------------------- */
$mp_omit = $mp_pages;
if ( count( $mp_omit ) === 3 ) {
	$mp_omit[1]['omit'] = true; // drop "services"
	$mp_built = FW_Site_Converter_Mapper::build_pages( array( 'pages' => $mp_omit ) );
	$mp_bs    = array_map( function ( $p ) { return (string) $p['slug']; }, $mp_built );
	mp( '[MP7] an omitted page is not built', ! in_array( 'services', $mp_bs, true ), wp_json_encode( $mp_bs ) );
	mp( '[MP7] …its siblings are untouched', in_array( 'home', $mp_bs, true ) && in_array( 'about', $mp_bs, true ), wp_json_encode( $mp_bs ) );
	mp( '[MP7] …and the front page is still the front page', 1 === count( array_filter( $mp_built, function ( $p ) { return ! empty( $p['front_page'] ); } ) ), wp_json_encode( $mp_bs ) );
	// NEGATIVE: omitting nothing builds everything
	$mp_all = FW_Site_Converter_Mapper::build_pages( array( 'pages' => $mp_pages ) );
	mp( '[MP7] NEGATIVE: with nothing omitted every page is built', 3 === count( $mp_all ), count( $mp_all ) );
} else {
	foreach ( array( 'an omitted page is not built', '…its siblings are untouched', '…and the front page is still the front page', 'NEGATIVE: with nothing omitted every page is built' ) as $mp_l ) {
		mp( '[MP7] ' . $mp_l, false, 'the [MP5] build did not produce three pages' );
	}
}

/* ---------------------------------------------------------------------------------------------
 * [MP8] NEGATIVE: a single screen behaves exactly as the old single-page input did.
 * ------------------------------------------------------------------------------------------- */
$mp_one = $mp_build( array( array( 'html' => $mp_page( 'Only' ), 'url' => 'https://example.test/', 'slug' => '', 'front' => true ) ) );
$mp_op  = $mp_one['mapping']['pages'] ?? array();
mp( '[MP8] NEGATIVE: one screen still produces exactly one page, set as the front page', 1 === count( $mp_op ) && ! empty( $mp_op[0]['front_page'] ), wp_json_encode( array( count( $mp_op ), $mp_op[0]['front_page'] ?? null ) ) );

$pass = $GLOBALS['__mp_pass'];
$fail = $GLOBALS['__mp_fail'];
echo "\n========================================\n";
echo 'MULTI-PAGE BUNDLE RESULT: ' . ( $fail ? 'FAIL' : 'PASS' ) . "   ($pass passed, $fail failed)\n";
echo "========================================\n";
