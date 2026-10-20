<?php
/**
 * Regression guard: a card's affordance row must not repeat the card's own title.
 *
 * When the WHOLE card is a link, the destination goes on the box and the link's label is appended as an
 * `icon-box__more` row, so the affordance (label + arrow) still reads as one. That is right when the label is
 * its own phrase — "Mehr erfahren", "Learn more".
 *
 * It is wrong when the source's clickable row IS the title. Measured on a captured band of eight cards, each
 * source card is an illustration above `<h3>Traffic accident</h3>` with a chevron at the row's end — one
 * occurrence of the label. The conversion emitted two:
 *
 *     h3.icon-box__title   Traffic accident
 *     p.icon-box__more     Traffic accident
 *
 * every card, twice, in a band of eight. So when the label echoes the title there is no second phrase to
 * show, and the row is not emitted. The box still carries the link, so the card is still clickable.
 *
 * Equality is compared after normalising what does not change the words: case, surrounding whitespace, a
 * trailing arrow or chevron, and trailing punctuation. "Traffic accident →" is the title, not a new label.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/card-more-row-does-not-echo-title-test.php"
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

$card_of = function ( $label ) {
	return array(
		'title' => 'Traffic accident',
		'text'  => '',
		'image' => array(),
		'link'  => array( 'href' => '/traffic-accident', 'label' => $label, 'whole' => true, 'glyph' => '<svg viewBox="0 0 24 24"><path d="M9 6l6 6-6 6"/></svg>' ),
	);
};
$content_of = function ( $card ) use ( $box ) {
	$n = $box->invoke( null, $card );
	return is_array( $n ) ? (string) ( $n['atts']['content'] ?? '' ) : '';
};
$link_of = function ( $card ) use ( $box ) {
	$n = $box->invoke( null, $card );
	return is_array( $n ) ? (string) ( $n['atts']['box_link'] ?? '' ) : '';
};

echo "\n== The measured shape: the clickable row IS the title\n";

$echo_card = $card_of( 'Traffic accident' );
$c = $content_of( $echo_card );
$ok( false === strpos( $c, 'icon-box__more' ), 'no affordance row is emitted: ' . substr( $c, 0, 70 ) );
$ok( 0 === substr_count( $c, 'Traffic accident' ), 'the label is not repeated in the content' );
$ok( '/traffic-accident' === $link_of( $echo_card ), 'and the card is still a link' );

echo "\n== …equality ignores what does not change the words\n";

foreach ( array( 'traffic accident', '  Traffic Accident  ', 'Traffic accident →', 'Traffic accident ›', 'Traffic accident.' ) as $variant ) {
	$ok( false === strpos( $content_of( $card_of( $variant ) ), 'icon-box__more' ),
		'"' . trim( $variant ) . '" still reads as the title' );
}

echo "\n== NEGATIVE: a label that IS its own phrase still shows\n";

$learn = $content_of( $card_of( 'Learn more' ) );
$ok( false !== strpos( $learn, 'icon-box__more' ), 'a distinct label keeps its affordance row' );
$ok( false !== strpos( $learn, 'Learn more' ), 'and its text' );

$mehr = $content_of( $card_of( 'Mehr erfahren' ) );
$ok( false !== strpos( $mehr, 'icon-box__more' ), 'so does a distinct label in another language' );

echo "\n== NEGATIVE: no label at all invents nothing\n";

$ok( false === strpos( $content_of( $card_of( '' ) ), 'icon-box__more' ), 'an empty label emits no row' );

printf( "\n%s\n", $fails ? "{$fails} FAIL(S)" : 'ALL PASS' );
exit( $fails ? 1 : 0 );
