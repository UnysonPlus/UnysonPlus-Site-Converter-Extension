<?php
/**
 * Regression guard: a card title keeps the FONT FAMILY the source gave it.
 *
 * `n_icon_box()` scopes a card title's measured metrics onto `.icon-box__title` — font-size, line-height,
 * bottom margin and weight — all read from the title's `titleCs` stamp, on the stated principle that "the
 * source is the truth and the theme default is the guess". `font-family` was never among them.
 *
 * That matters because the theme styles every heading with its display face. A source that uses a serif for
 * its display headings and a SANS for its small card labels — an extremely common pairing — has the sans
 * silently replaced by the serif on every card, since the card title is an `<h3>`.
 *
 * Measured on a captured band of eight cards: the labels are stamped
 * `font-family:Inter, system-ui, sans-serif` and rendered as `"Playfair Display", Georgia, serif`. The
 * property diff reported the same delta on all eight.
 *
 * The measured family rides the channel its siblings already use, but `!important` alone was not enough:
 * the generated child theme emits
 *
 *     :is(h1,...,h6):not(.hf-heading):not(.elementor-heading-title){font-family:<display face> !important}
 *
 * to outrank the PARENT theme's heading rules, and its specificity (0,2,1) beats a scoped
 * `.uXXXXXXXX .icon-box__title` (0,2,0). CDP's getMatchedStylesForNode named it as the winner. The theme
 * generator already exempts `.hf-heading` because it carries measured type; same principle here, so the
 * family goes out at (0,3,0) by repeating the title's own class.
 *
 * `!important` is still needed for the same reason the
 * weight needs it: the shortcode's own `hN` rule outranks a plain scoped rule. A card whose stamp carries no
 * family is untouched, and the theme default stands.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/card-title-keeps-its-measured-font-test.php"
 *
 * Exit code 0 = all PASS, 1 = at least one FAIL.
 */

if ( ! class_exists( 'FW_Site_Converter_Mapper' ) ) {
	fwrite( STDERR, "FAIL: site-converter mapper not loaded\n" );
	exit( 1 );
}

$fails = 0;
$ok    = function ( $cond, $msg ) use ( &$fails ) {
	if ( $cond ) { echo "  \xe2\x9c\x93 $msg\n"; } else { $fails++; echo "  \xe2\x9c\x97 FAIL: $msg\n"; }
};

$box = new ReflectionMethod( 'FW_Site_Converter_Mapper', 'n_icon_box' );
$box->setAccessible( true );

$css_of = function ( $title_cs ) use ( $box ) {
	$node = $box->invoke( null, array(
		'title'    => 'Traffic accident',
		'titleTag' => 'h3',
		'titleCs'  => $title_cs,
		'text'     => '',
		'image'    => array(),
	) );
	return is_array( $node ) ? (string) ( $node['atts']['custom_css'] ?? '' ) : '';
};

echo "\n== The measured shape: a sans label under a serif-heading theme\n";

$css = $css_of( 'color:rgb(26, 33, 45);font-family:Inter, system-ui, sans-serif;font-size:16px;font-weight:500;line-height:24px' );
$ok( '' !== trim( $css ), 'the card produces scoped CSS at all' );
$ok( false !== stripos( $css, 'font-family:Inter' ), 'the measured family is emitted: ' . mb_substr( trim( $css ), 0, 110 ) );
$ok( false !== strpos( $css, '.icon-box__title.icon-box__title' ),
	'…at a specificity that clears the blanket `:is(h1..h6)` heading rule the generated theme emits, which
	 carries (0,2,1) plus !important and beat the plain scoped rule' );
$ok( false !== strpos( $css, '!important' ), '…with !important, so the shortcode\'s own hN rule cannot outrank it' );
$ok( false !== strpos( $css, 'font-size:16px' ), 'and its siblings still ride along (size)' );

echo "\n== A quoted family survives intact\n";

$cssq = $css_of( 'font-family:"Playfair Display", Georgia, serif;font-size:18px' );
$ok( false !== stripos( $cssq, 'Playfair Display' ), 'a quoted family name is carried: ' . mb_substr( trim( $cssq ), 0, 100 ) );

echo "\n== NEGATIVE: no family in the stamp invents none\n";

$cssn = $css_of( 'color:rgb(26, 33, 45);font-size:16px;font-weight:500' );
$ok( false === stripos( $cssn, 'font-family' ), 'no font-family is emitted when the source did not state one' );
$ok( false !== strpos( $cssn, 'font-size:16px' ), '…while the metrics it did state are still carried' );

echo "\n== NEGATIVE: an `inherit` family is not a measurement\n";

$cssi = $css_of( 'font-family:inherit;font-size:16px' );
$ok( false === stripos( $cssi, 'font-family' ), '`inherit` is not emitted as a family' );

printf( "\n%s\n", $fails ? "{$fails} FAIL(S)" : 'ALL PASS' );
exit( $fails ? 1 : 0 );
