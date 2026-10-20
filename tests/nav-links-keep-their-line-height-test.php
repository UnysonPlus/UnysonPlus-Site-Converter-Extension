<?php
/**
 * Regression guard: the header nav keeps the LINE-HEIGHT the source gave its links.
 *
 * `detect_menu_styles()` tallies the nav links' typography and hands it to the native Header -> Menu options:
 * size, weight, case, tracking, family, colour. Line-height was never among them, and the theme has no
 * `menu_link_line_height` field for it to land in either -- so every converted nav took the theme's own line
 * box.
 *
 * Measured on a captured header, repeated on every page of the site: the source links are stamped
 * `line-height:24px` and rendered at 26.08px (the theme's 1.63 ratio on a 16px link). Three property deltas
 * per page, and the nav row measures a little taller than the source everywhere.
 *
 * The two-row masthead path already pins `.primary-menu a{line-height:…}` for exactly this reason; this is
 * the same rule for an ordinary single-row header, carried through the scoped-CSS channel the menu already
 * uses for its gap and link padding (both likewise have no native field).
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/nav-links-keep-their-line-height-test.php"
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

$LINK_CS = 'color:rgb(26, 33, 45);font-family:Inter, system-ui, sans-serif;font-size:16px;font-weight:500;line-height:24px';

$page = function ( $link_cs ) {
	$a = function ( $href, $label ) use ( $link_cs ) {
		return '<a href="' . $href . '" data-sc-cs="' . $link_cs . '">' . $label . '</a>';
	};
	return '<!DOCTYPE html><html><head><title>Apex</title></head><body>'
		. '<header data-sc-cs="background-color:rgb(255, 255, 255);height:96px">'
			. '<div data-sc-cs="display:flex;align-items:center;justify-content:space-between">'
			. '<a href="/" data-sc-cs="font-size:24px;font-weight:700;line-height:32px">Apex Advocates</a>'
			. '<nav data-sc-cs="display:flex;gap:32px">'
				. $a( '/about-us', 'About Us' ) . $a( '/costs', 'Costs' ) . $a( '/contact', 'Contact' )
			. '</nav>'
			. '</div>'
		. '</header>'
		. '<main><section data-sc-cs="background-color:rgb(255,255,255);padding:80px 0px"><h1 data-sc-cs="font-size:48px">Hello</h1></section></main>'
		. '</body></html>';
};

$ms = new ReflectionMethod( 'FW_Site_Converter_Stitch', 'detect_menu_styles' );
$ms->setAccessible( true );

echo "\n== The measured shape: nav links on a 24px line box\n";

$style = (array) $ms->invoke( null, $page( $LINK_CS ) );
$ok( ! empty( $style ), 'menu styles are detected at all' );
$ok( isset( $style['line_height'] ), 'the links\' line-height is recorded' );
if ( isset( $style['line_height'] ) ) {
	$lh = $style['line_height'];
	$lh = is_array( $lh ) ? (string) ( $lh['value'] ?? '' ) : (string) $lh;
	$ok( false !== strpos( $lh, '24' ), 'and it is the measured 24 (' . $lh . ')' );
}
$ok( isset( $style['font_size'] ), 'its siblings are still detected (font-size)' );

echo "\n== …and it reaches the page as a scoped rule\n";

$chrome = FW_Site_Converter_Stitch::tokens_to_theme_settings_chrome( array(), $page( $LINK_CS ), 'Home' );
$css    = (string) ( $chrome['values']['misc_custom_css']['custom_css'] ?? '' );
$ok( false !== strpos( $css, 'primary-menu' ), 'the menu gets scoped CSS' );
$ok( (bool) preg_match( '/primary-menu[^{}]*\{[^}]*line-height:\s*24px/', $css ), 'carrying the measured line box: '
	. mb_substr( (string) ( preg_match( '/[^\n]*primary-menu[^\n]*line-height[^\n]*/', $css, $m ) ? $m[0] : '(none)' ), 0, 130 ) );

echo "\n== NEGATIVE: an unstamped nav invents nothing\n";

$bare = (array) $ms->invoke( null, $page( 'color:rgb(26, 33, 45);font-size:16px;font-weight:500' ) );
$ok( ! isset( $bare['line_height'] ), 'no line-height is recorded when the source states none' );

$chrome2 = FW_Site_Converter_Stitch::tokens_to_theme_settings_chrome( array(), $page( 'color:rgb(26, 33, 45);font-size:16px;font-weight:500' ), 'Home' );
$css2    = (string) ( $chrome2['values']['misc_custom_css']['custom_css'] ?? '' );
$ok( ! preg_match( '/primary-menu[^{}]*\{[^}]*line-height/', $css2 ), '…and none is emitted' );

echo "\n== NEGATIVE: a `normal` line-height is not a measurement\n";

$norm = (array) $ms->invoke( null, $page( 'color:rgb(26, 33, 45);font-size:16px;line-height:normal' ) );
$ok( ! isset( $norm['line_height'] ), '`normal` is not recorded as a chosen line box' );

printf( "\n%s\n", $fails ? "{$fails} FAIL(S)" : 'ALL PASS' );
exit( $fails ? 1 : 0 );
