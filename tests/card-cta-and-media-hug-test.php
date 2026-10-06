<?php
/**
 * Regression guard: two defects measured on one captured band of four identical cards.
 *
 * 1. A CTA WAS DROPPED FOR NAMING THE THING IT LEADS TO.
 *
 *    The card builder refuses a CTA label that repeats the card's own heading, because a whole-card link
 *    often carries the heading as its text and echoing it reads as a stutter. That guard tested CONTAINMENT,
 *    which is a much wider net than it looks: most buttons are written to name their destination.
 *
 *    Measured: of four structurally identical cards, "For Event Planners →" and "For Brands & Merchants →"
 *    were dropped because each contains its card's title, while "For Printers →" survived only because its
 *    card happens to be titled "Fulfillment Printers". Two of four buttons vanished on a coin-flip of
 *    wording, and the converter's own coverage audit listed both as missing text (90.2% → 92.2% once fixed).
 *
 *    So the test is EQUALITY after normalising case, arrows and punctuation. A label that is the title
 *    wearing a chevron is still refused; a label that says more than the title is a real CTA and is kept.
 *
 * 2. A FRAME CANNOT BOTH HUG AN IMAGE AND INSET IT.
 *
 *    When the media box is sized to the image's own measured box, the frame is hugging the picture — there
 *    is no frame inset left to apply. The padding read from the source wrapper lands on that same element,
 *    so under `box-sizing:border-box` it is subtracted from the picture instead of sitting around it.
 *
 *    Measured: `width:60px` with `padding:20px` left a 20px content box, and `object-fit` cropped a 300x251
 *    icon to 20x50 — squashed, not merely small, on all four cards of the band.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/card-cta-and-media-hug-test.php"
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

$n_card = new ReflectionMethod( 'FW_Site_Converter_Mapper', 'n_image_box' );
$n_card->setAccessible( true );

// The shape the cell path produces for one of these cards.
$card = function ( $title, $label ) {
	return array(
		'title'    => $title,
		'titleTag' => 'h3',
		'text'     => '<p>Some supporting copy for the card.</p>',
		'button'   => array( 'label' => $label, 'href' => 'https://example.test/x', 'cls' => '', 'icon' => '' ),
		'cls'      => '',
		'cs'       => '',
		// n_image_box() returns nothing without a picture, so every fixture carries one.
		'image'    => array( 'src' => '/u/icon.png', 'alt' => 'icon', 'cls' => '', 'aspect' => '', 'extra' => '', 'frameBg' => '' ),
	);
};
$built = function ( $title, $label ) use ( $n_card, $card ) {
	$n = $n_card->invoke( null, $card( $title, $label ) );
	return ( is_array( $n ) && isset( $n['atts'] ) ) ? $n['atts'] : array();
};

echo "\n== The measured case: a CTA that names its destination survives\n";

$a = $built( 'Event Planners', 'For Event Planners →' );
$ok( 'For Event Planners →' === ( $a['button_label'] ?? '' ), "the label is kept (got '" . ( $a['button_label'] ?? '' ) . "')" );
$ok( 'none' !== ( $a['button_style'] ?? 'none' ), 'and the button is switched on' );

$a = $built( 'Brands & Merchants', 'For Brands & Merchants →' );
$ok( 'For Brands & Merchants →' === ( $a['button_label'] ?? '' ), 'the ampersand label is kept too' );

echo "\n== …and the card that always worked still works\n";

$a = $built( 'Fulfillment Printers', 'For Printers →' );
$ok( 'For Printers →' === ( $a['button_label'] ?? '' ), 'a label that never contained its title is unaffected' );

echo "\n== NEGATIVE: a label that IS the title is still refused\n";

$a = $built( 'Event Planners', 'Event Planners' );
$ok( 'Event Planners' !== ( $a['button_label'] ?? '' ), 'an exact echo of the heading is not made a CTA' );

$a = $built( 'Event Planners', 'Event Planners →' );
$ok( 'Event Planners →' !== ( $a['button_label'] ?? '' ), 'nor the heading wearing an arrow' );

$a = $built( 'Event Planners', 'event planners' );
$ok( 'event planners' !== ( $a['button_label'] ?? '' ), 'nor a case variant of it' );

echo "\n== The media frame hugs OR insets, never both\n";

// An image carrying its own measured box, in a wrapper the source pads.
$n = $n_card->invoke( null, array(
	'title' => 'Event Planners', 'titleTag' => 'h3', 'text' => '<p>Copy.</p>', 'cls' => '', 'cs' => '',
	'image' => array( 'src' => '/u/icon.png', 'alt' => 'icon', 'cls' => '', 'aspect' => '',
		'extra' => 'width:60px;height:50px', 'framePad' => 20, 'frameBg' => '' ),
) );
$css = (string) ( $n['atts']['custom_css'] ?? '' );
$ok( false !== strpos( $css, 'width:60px !important' ), 'the media box is sized to the image' );
$ok( false !== strpos( $css, 'padding:0 !important' ), 'and its frame padding is neutralised, so the inset cannot eat the picture' );
// the two must land in the SAME rule, or the later one still wins
$ok( (bool) preg_match( '/\.imgbox__media\{[^}]*width:60px[^}]*padding:0 !important/', $css ), 'both in one rule on .imgbox__media' );

echo "\n== NEGATIVE: a frame that is NOT hugging keeps its inset\n";

$n = $n_card->invoke( null, array(
	'title' => 'A photo card', 'titleTag' => 'h3', 'text' => '<p>Copy.</p>', 'cls' => '', 'cs' => '',
	'image' => array( 'src' => '/u/photo.jpg', 'alt' => '', 'cls' => '', 'aspect' => '', 'extra' => '', 'framePad' => 20, 'frameBg' => '' ),
) );
$css = (string) ( $n['atts']['custom_css'] ?? '' );
$ok( false !== strpos( $css, 'padding:20' ), 'a frame with no own-sized image keeps the source padding' );
$ok( false === strpos( $css, 'padding:0 !important' ), 'and is not zeroed' );

printf( "\n%s\n", $fails ? "{$fails} FAIL(S)" : 'ALL PASS' );
exit( $fails ? 1 : 0 );
