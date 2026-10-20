<?php
/**
 * Regression guard: a card's measured body size is emitted, never assumed to match the theme.
 *
 * `n_icon_box()` carried the description's font-size only when it differed from 16px by more than half a
 * pixel:
 *
 *     if ( preg_match( '/^([0-9.]+)px$/', $bfs, $bm ) && abs( (float) $bm[1] - 16 ) > 0.5 && … )
 *
 * The reasoning was that 16px is the theme's body size, so a 16px source needs no rule. That is an assumption
 * about the theme, and on a converted site it is wrong: the generated theme renders card content at 18px.
 *
 * Measured on a captured page — three bands of cards, source against converted:
 *
 *     source     16px        converted  18px
 *
 * The stitch had it right all along. card_from_cell reads the description <p> itself and exports
 * bodyFontSize '16px' (its bodyCs carries font-size:16px too, while the cell's own `cs` carries the
 * inherited 18px). The value reached the mapper intact and was then dropped by this guard, so every card on
 * the page rendered a size too large — which wraps text differently, grows the card, and shifts the rhythm
 * of the whole band. That is most of what reads as "the spacing is off" on a converted page.
 *
 * A rule that merely restates the theme's own value is harmless; a missing one is visible. So the measured
 * size is emitted whenever it is known and plausible, and only the implausible is rejected.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/card-body-size-is-measured-not-assumed-test.php"
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

$card_of = function ( $fs, $lh = '27.52px' ) {
	return array(
		'title'          => 'Customer Input',
		'titleCs'        => 'font-size:22px;font-weight:700;color:rgb(13,42,92)',
		'body'           => '<p>Personalization details, product choices, quantities, and delivery needs are captured automatically at the point of sale.</p>',
		'text'           => 'Personalization details, product choices, quantities, and delivery needs are captured automatically at the point of sale.',
		'bodyFontSize'   => $fs,
		'bodyLineHeight' => $lh,
		'bodyCs'         => 'color:rgb(85,85,85);font-size:' . $fs . ';line-height:' . $lh,
		'cs'             => 'color:rgb(34,34,34);font-size:18px;line-height:30.96px',
		'image'          => array(),
	);
};
$css_of = function ( $card ) use ( $box ) {
	$node = $box->invoke( null, $card );
	return is_array( $node ) ? (string) ( $node['atts']['custom_css'] ?? '' ) : '';
};

echo "\n== The measured shape: a 16px body, which the theme renders at 18px\n";

$css16 = $css_of( $card_of( '16px' ) );
$ok( '' !== trim( $css16 ), 'the card produces scoped CSS at all' );
$ok( false !== strpos( $css16, 'font-size:16px' ),
	'the measured 16px is emitted rather than assumed to match the theme' );

echo "\n== NEGATIVE: a size that already differed is unchanged\n";

$ok( false !== strpos( $css_of( $card_of( '14px' ) ), 'font-size:14px' ), 'a 14px body still carries its size' );
$ok( false !== strpos( $css_of( $card_of( '20px' ) ), 'font-size:20px' ), 'a 20px body still carries its size' );

echo "\n== NEGATIVE: an implausible or absent size is still rejected\n";

$ok( false === strpos( $css_of( $card_of( '4px' ) ), 'font-size:4px' ), 'an implausibly small size is refused' );
$ok( false === strpos( $css_of( $card_of( '96px' ) ), 'font-size:96px' ), 'an implausibly large size is refused' );

$none = $card_of( '16px' );
unset( $none['bodyFontSize'] );
$ok( false === strpos( $css_of( $none ), 'font-size:16px' ), 'no measurement means no size rule is invented' );

echo "\n== The leading still rides along independently\n";

$ok( false !== strpos( $css16, '27.52px' ), 'the measured line-height is carried beside the size' );

printf( "\n%s\n", $fails ? "{$fails} FAIL(S)" : 'ALL PASS' );
exit( $fails ? 1 : 0 );
