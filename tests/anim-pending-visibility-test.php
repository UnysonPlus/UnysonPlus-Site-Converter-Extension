<?php
/**
 * Regression guard: a pending entrance reveal hides the element WITHOUT hiding its text from readers.
 *
 * An element waiting for its scroll trigger must be invisible, and there are two ways to do that. They are
 * not equivalent:
 *
 *   visibility:hidden  -- removes the text from the rendered text layer. document.innerText omits it, and so
 *                         does anything that reads a page without scrolling: search-engine renderers, AI
 *                         crawlers, in-page find. Measured on a converted page with entrance animations on,
 *                         every service name, section heading and body phrase below the fold was absent from
 *                         innerText, returning only when reduced-motion forced the elements visible.
 *   opacity:0          -- hides it visually, keeps the text in the DOM text layer and the accessibility tree.
 *
 * Both are skipped by axe's colour-contrast pass, so the accessibility score is unaffected either way; only
 * the first costs the page its content for any reader that does not scroll. Entrance animations are ON by
 * default for every conversion, which makes that the difference between a page that reads and one that does
 * not.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/anim-pending-visibility-test.php"
 *
 * Exit code 0 = all PASS, 1 = at least one FAIL.
 */

if ( ! function_exists( 'do_shortcode' ) ) {
	fwrite( STDERR, "FAIL: WordPress not loaded\n" );
	exit( 1 );
}

$fails = 0;
$ok    = function ( $cond, $msg ) use ( &$fails ) {
	if ( $cond ) { echo "  \xe2\x9c\x93 $msg\n"; } else { $fails++; echo "  \xe2\x9c\x97 FAIL: $msg\n"; }
};

$file = WP_PLUGIN_DIR . '/unysonplus/framework/extensions/shortcodes/includes/shortcode-animation-helper.php';
$src  = is_readable( $file ) ? (string) file_get_contents( $file ) : '';
if ( '' === $src ) {
	fwrite( STDERR, "FAIL: animation helper not readable at $file\n" );
	exit( 1 );
}

// The inline rules the helper enqueues beside Animate.css.
$css = '';
if ( preg_match( '/\$inline_css\s*=\s*(.*?);\s*\r?\n/s', $src, $m ) ) {
	$css = preg_replace( '/\s+/', '', $m[1] );
}

echo "\n== The pending state uses opacity\n";

$ok( '' !== $css, 'the inline pending CSS is found in the helper' );
$ok( false !== strpos( $css, '.sc-anim-pending{opacity:0' ),
	'a pending element is hidden with opacity:0, so its text stays readable' );
$ok( false === strpos( $css, '.sc-anim-pending{visibility:hidden' ),
	'...and NOT with visibility:hidden, which would drop it from innerText and from crawlers' );

echo "\n== It becomes visible when it plays, and under reduced motion\n";

$ok( false !== strpos( $css, '.sc-anim-pendinganimate__animated{opacity:1' )
	|| false !== strpos( $css, '.sc-anim-pending.animate__animated{opacity:1' ),
	'once the animate class lands the element is opaque' );
$ok( (bool) preg_match( '/prefers-reduced-motion:reduce\)\{.*?\.sc-anim-pending\{opacity:1!important/s', $css ),
	'reduced motion shows it immediately, with no reveal to wait for' );

echo "\n== An invisible element does not swallow clicks\n";

$ok( false !== strpos( $css, 'pointer-events:none' ),
	'a pending element is click-through while invisible' );
$ok( (bool) preg_match( '/animate__animated\{opacity:1;pointer-events:auto/', $css ),
	'...and interactive again once revealed' );

echo $fails ? "\n\xe2\x9c\x97 $fails FAILED\n" : "\n\xe2\x9c\x93 ALL PASS - hidden to the eye, present to the reader\n";
exit( $fails ? 1 : 0 );
