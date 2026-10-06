<?php
/**
 * Regression guard: a theme that self-hosts a family must not ALSO fetch it from Google.
 *
 * Rehosting exists to remove the CDN dependency: the conversion downloads the source's webfaces into the
 * generated child theme as `fonts/*.woff2`. But the Typography settings still NAME the family, and the
 * parent theme builds its Google Fonts <link> from those settings — so the converted site downloaded the
 * identical typeface twice, from two origins, and kept a runtime dependency on Google that the whole rehost
 * was meant to remove. Measured on a converted page: 3 requests to Google hosts (stylesheet + two
 * preconnects), and LCP fell from ~2470ms to ~1860ms once they were gone.
 *
 * The remote copy is the damaging one. It is fetched `media="print"` so it does not block paint — which
 * means it lands AFTER first paint and re-enters font loading on a page that had already finished.
 *
 * The theme declares what it ships through `unysonplus_self_hosted_font_families`. Two things must agree, or
 * the bug half-survives: the printed <link> AND the preconnect resource hints. Reading the raw option for
 * the hints left a page that never contacts Google still opening connections to two Google hosts.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/self-hosted-fonts-test.php"
 *
 * Exit code 0 = all PASS, 1 = at least one FAIL.
 */

if ( ! class_exists( 'FW_Site_Converter_Theme_Generator' ) ) {
	fwrite( STDERR, "FAIL: site-converter not loaded\n" );
	exit( 1 );
}

$fails = 0;
$ok    = function ( $cond, $msg ) use ( &$fails ) {
	if ( $cond ) { echo "  \xe2\x9c\x93 $msg\n"; } else { $fails++; echo "  \xe2\x9c\x97 FAIL: $msg\n"; }
};

echo "\n== The generated theme declares what it ships\n";

$fnm = new ReflectionMethod( 'FW_Site_Converter_Theme_Generator', 'functions_php' );
$fnm->setAccessible( true );
$cfg = array(
	'theme'          => array( 'slug' => 'demo-child', 'name' => 'Demo', 'mode' => 'child' ),
	'fonts'          => array( 'google' => '', 'heading' => 'Space Grotesk', 'body' => 'Inter', 'icons' => '' ),
	'header'         => array( 'menu_location' => 'primary', 'cta' => array() ),
	'rehosted_fonts' => array(
		'css'      => "@font-face{font-family:'Inter';font-weight:400;src:url(fonts/a.woff2) format('woff2');unicode-range:U+0000-00FF;}",
		'families' => array( 'Inter', 'Space Grotesk' ),
	),
);
$php = (string) $fnm->invoke( null, $cfg );
$ok( false !== strpos( $php, 'unysonplus_self_hosted_font_families' ),
	'functions.php registers the self-hosted families filter' );
$ok( false !== strpos( $php, "'Inter'" ) && false !== strpos( $php, "'Space Grotesk'" ),
	'...naming every family it rehosted' );

$tmp = tempnam( sys_get_temp_dir(), 'scsh' ) . '.php';
file_put_contents( $tmp, $php );
$lint = array();
@exec( 'php -l ' . escapeshellarg( $tmp ) . ' 2>&1', $lint, $rc );
@unlink( $tmp );
$ok( 0 === (int) $rc, '...and the file it writes into the user\'s theme parses' );

echo "\n== NEGATIVE: nothing rehosted, nothing declared\n";

$cfg2 = $cfg;
unset( $cfg2['rehosted_fonts'] );
$php2 = (string) $fnm->invoke( null, $cfg2 );
$ok( false === strpos( $php2, 'unysonplus_self_hosted_font_families' ),
	'NEGATIVE: with no rehost the filter is not registered, so the remote link still works' );

echo "\n== The parent drops exactly the declared families\n";

if ( ! function_exists( 'unysonplus_drop_self_hosted_families' ) ) {
	echo "  \xe2\x9a\xa0 parent theme helper not loaded (theme inactive) - skipping the link assertions\n";
} else {
	$mk = function ( $families ) {
		$p = array();
		foreach ( (array) $families as $f ) { $p[] = 'family=' . str_replace( ' ', '+', $f ) . ':wght@400;700'; }
		return '<link href="https://fonts.googleapis.com/css2?' . implode( '&', $p ) . '&display=swap" rel="stylesheet" type="text/css">';
	};

	$claim = function ( $list ) {
		return function () use ( $list ) { return $list; };
	};

	// Both families self-hosted → nothing left to ask Google for.
	$f1 = $claim( array( 'Inter', 'Space Grotesk' ) );
	add_filter( 'unysonplus_self_hosted_font_families', $f1 );
	$got = unysonplus_drop_self_hosted_families( $mk( array( 'Inter', 'Space Grotesk' ) ) );
	$ok( '' === trim( (string) $got ), 'every family self-hosted → no link at all (got "' . substr( (string) $got, 0, 60 ) . '")' );
	remove_filter( 'unysonplus_self_hosted_font_families', $f1 );

	// One self-hosted, one not → the other MUST survive, or a real font silently stops loading.
	$f2 = $claim( array( 'Inter' ) );
	add_filter( 'unysonplus_self_hosted_font_families', $f2 );
	$got2 = (string) unysonplus_drop_self_hosted_families( $mk( array( 'Inter', 'Lora' ) ) );
	$ok( false === strpos( $got2, 'family=Inter' ), 'the self-hosted family is dropped from a mixed link' );
	$ok( false !== strpos( $got2, 'family=Lora' ),
		'...and the family that is NOT self-hosted still loads (dropping it would blank a real typeface)' );
	remove_filter( 'unysonplus_self_hosted_font_families', $f2 );

	// A family name whose URL spelling differs from the settings spelling.
	$f3 = $claim( array( 'space grotesk' ) );
	add_filter( 'unysonplus_self_hosted_font_families', $f3 );
	$got3 = (string) unysonplus_drop_self_hosted_families( $mk( array( 'Space Grotesk' ) ) );
	$ok( '' === trim( $got3 ), 'matching ignores case and the space/plus spelling difference' );
	remove_filter( 'unysonplus_self_hosted_font_families', $f3 );

	echo "\n== NEGATIVE: no claim, no change\n";

	// "Nothing declared" has to MEAN nothing declared. This install's own converted child theme registers its
	// families on the same hook, so the assertion silently depended on which typefaces that site happened to
	// use: it passed against a site using Plus Jakarta Sans and failed the moment a converted site used Inter,
	// which is in the sample link below. Detach the hook for this case, then put it back.
	$hook_ns  = 'unysonplus_self_hosted_font_families';
	$saved_ns = isset( $GLOBALS['wp_filter'][ $hook_ns ] ) ? $GLOBALS['wp_filter'][ $hook_ns ] : null;
	unset( $GLOBALS['wp_filter'][ $hook_ns ] );

	$link = $mk( array( 'Inter', 'Lora' ) );
	$ok( $link === unysonplus_drop_self_hosted_families( $link ),
		'NEGATIVE: with nothing declared the link is returned untouched' );

	if ( null !== $saved_ns ) { $GLOBALS['wp_filter'][ $hook_ns ] = $saved_ns; }
	$ok( '' === unysonplus_drop_self_hosted_families( '' ),
		'NEGATIVE: an empty link stays empty' );

	$f4 = $claim( array( 'Nonesuch' ) );
	add_filter( 'unysonplus_self_hosted_font_families', $f4 );
	$ok( false !== strpos( (string) unysonplus_drop_self_hosted_families( $link ), 'family=Inter' ),
		'NEGATIVE: declaring a family that is not in the link changes nothing' );
	remove_filter( 'unysonplus_self_hosted_font_families', $f4 );

	echo "\n== The PRINTER is actually wired to it\n";

	// Asserting the helper alone proves nothing about the page: with the call removed from
	// _action_theme_print_google_fonts_link() every assertion above still passed while the converted site
	// went on loading the font from Google. Exercise the printer, which is what the browser sees.
	if ( ! function_exists( '_action_theme_print_google_fonts_link' ) ) {
		echo "  \xe2\x9a\xa0 printer not loaded - skipping\n";
	} else {
		// The printer REBUILDS a cache it considers stale from the live Typography settings, so a seeded
		// option is overwritten and the assertion has to be about whatever this install really sets. That is
		// the honest test anyway: it exercises the self-heal path the converted site actually takes.
		$prev_opt = get_option( 'fw_theme_google_fonts_link', '' );

		// This install's own converted theme already declares its families, so the baseline would print
		// nothing and the test would skip itself into a green that proves nothing. Detach every callback on
		// the hook for the baseline, then restore them.
		$hook  = 'unysonplus_self_hosted_font_families';
		$saved = isset( $GLOBALS['wp_filter'][ $hook ] ) ? $GLOBALS['wp_filter'][ $hook ] : null;
		unset( $GLOBALS['wp_filter'][ $hook ] );

		ob_start();
		_action_theme_print_google_fonts_link();
		$base = (string) ob_get_clean();
		$live = array();
		if ( preg_match_all( '#family=([^&:"\']+)#i', $base, $lm ) ) {
			foreach ( $lm[1] as $f ) { $live[] = str_replace( '+', ' ', $f ); }
		}

		if ( ! $live ) {
			echo "  \xe2\x9a\xa0 this install prints no Google link at all - nothing to suppress, skipping\n";
		} else {
			$ok( false !== strpos( $base, 'fonts.googleapis.com' ),
				'baseline: with nothing declared the printer DOES emit the link (families: ' . implode( ', ', $live ) . ')' );
			$f6 = $claim( $live );
			add_filter( 'unysonplus_self_hosted_font_families', $f6 );
			ob_start();
			_action_theme_print_google_fonts_link();
			$printed = (string) ob_get_clean();
			remove_filter( 'unysonplus_self_hosted_font_families', $f6 );
			$ok( false === strpos( $printed, 'fonts.googleapis.com' ),
				'...and emits NOTHING once those same families are declared self-hosted (got "'
				. substr( trim( $printed ), 0, 60 ) . '")' );
		}

		if ( null !== $saved ) { $GLOBALS['wp_filter'][ $hook ] = $saved; }
		if ( '' !== (string) $prev_opt ) { update_option( 'fw_theme_google_fonts_link', $prev_opt ); }
	}

	echo "\n== The preconnect hints read the same answer as the printer\n";

	$f5 = $claim( array( 'Inter' ) );
	add_filter( 'unysonplus_self_hosted_font_families', $f5 );
	update_option( 'fw_theme_google_fonts_link', $mk( array( 'Inter' ) ) );
	$hints = apply_filters( 'wp_resource_hints', array(), 'preconnect' );
	$has_g = false;
	foreach ( (array) $hints as $h ) {
		$u = is_array( $h ) ? ( $h['href'] ?? '' ) : $h;
		if ( false !== strpos( (string) $u, 'google' ) || false !== strpos( (string) $u, 'gstatic' ) ) { $has_g = true; }
	}
	$ok( ! $has_g,
		'a page that never contacts Google does not preconnect to it either (a DNS + TLS round trip for nothing)' );
	remove_filter( 'unysonplus_self_hosted_font_families', $f5 );
	delete_option( 'fw_theme_google_fonts_link' );
}

echo $fails ? "\n\xe2\x9c\x97 $fails FAILED\n" : "\n\xe2\x9c\x93 ALL PASS - self-hosted means self-hosted\n";
exit( $fails ? 1 : 0 );
