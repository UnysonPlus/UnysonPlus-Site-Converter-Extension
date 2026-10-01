<?php
/**
 * Regression guard: a RULE is not a BOX — a box skin is only claimed for a real four-sided border.
 *
 * read_card_skin() read `border-top-width` / `border-top-color` and nothing else, and the Box Preset it feeds
 * renders a `border:` SHORTHAND — all four sides. So any source that merely RULES something off got a
 * complete rectangle drawn around it:
 *
 *     <div class="divide-y border-y">   →   .boxp-box-xxxx { border: 1px solid #e1e1e1 !important }
 *
 * Measured on an editorial FAQ: the source draws hairlines on the page background, the conversion drew a
 * bordered panel around the whole list. The same mistake fires for a `border-t` section divider, a
 * `border-b` tab strip — anywhere a single edge means "rule", not "box".
 *
 * The SIDE edges are what decide. Without them this is a rule, and the box preset must not claim it; the
 * element's own block still carries it (the accordion's `flush` family draws exactly these dividers).
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/box-skin-border-test.php"
 *
 * Exit code 0 = all PASS, 1 = at least one FAIL.
 */

if ( ! class_exists( 'FW_Site_Converter_Stitch' ) ) {
	fwrite( STDERR, "FAIL: site-converter not loaded (run inside a WP install with the plugin active)\n" );
	exit( 1 );
}

$fails = 0;
$ok    = function ( $cond, $msg ) use ( &$fails ) {
	if ( $cond ) { echo "  \xe2\x9c\x93 $msg\n"; } else { $fails++; echo "  \xe2\x9c\x97 FAIL: $msg\n"; }
};

/** read_card_skin() over one element carrying $cs, with enough content to read as a card. */
$skin = function ( $cs ) {
	$dom = new DOMDocument();
	libxml_use_internal_errors( true );
	$dom->loadHTML( '<!DOCTYPE html><html><body><div data-sc-cs="' . $cs . '">'
		. '<h3 data-sc-cs="font-size:20px;font-weight:600">Was kostet die Pruefung?</h3>'
		. '<p data-sc-cs="font-size:15px;line-height:24px">Die Pruefung kostet 40 Euro und wird verrechnet.</p>'
		. '</div></body></html>', LIBXML_NOERROR | LIBXML_NOWARNING );
	libxml_clear_errors();
	$el = $dom->getElementsByTagName( 'div' )->item( 0 );
	$m  = new ReflectionMethod( 'FW_Site_Converter_Stitch', 'read_card_skin' );
	$m->setAccessible( true );
	return (array) $m->invoke( null, $el );
};
$bw_of = function ( $s ) { return trim( (string) ( $s['borderW'] ?? $s['border_width'] ?? $s['bw'] ?? '' ) ); };

$INK  = 'color:rgb(20, 20, 24);font-family:Inter, sans-serif;font-size:16px;line-height:24px;display:block;';
$FILL = 'background-color:rgb(243, 240, 235);'; // a real TINT: a white card on a white page is not a skin

echo "\n== A `border-y` RULE does not become a box border\n";

$y = $skin( $INK . $FILL . 'border-top-width:1px;border-top-color:rgb(225, 225, 225);border-bottom-width:1px;border-left-width:0px;border-right-width:0px;border-radius:0px' );
$ok( '' === $bw_of( $y ),
	'a rule above and below yields NO box border (got "' . $bw_of( $y ) . '")' );

$t = $skin( $INK . $FILL . 'border-top-width:1px;border-top-color:rgb(225, 225, 225);border-left-width:0px;border-right-width:0px' );
$ok( '' === $bw_of( $t ),
	'...and so does a lone `border-t` divider (got "' . $bw_of( $t ) . '")' );

echo "\n== NEGATIVE: a real four-sided border is still a box\n";

$box = $skin( $INK . $FILL . 'border-top-width:1px;border-top-color:rgb(225, 225, 225);border-bottom-width:1px;border-left-width:1px;border-right-width:1px;border-radius:12px' );
$ok( '' !== $bw_of( $box ),
	'NEGATIVE: borders on all four sides still give the box its border (got "' . $bw_of( $box ) . '")' );

echo "\n== NEGATIVE: the rest of the skin is untouched\n";

// Withholding the border withholds ONLY the border. A tinted panel that happens to carry a top rule keeps
// its tint and its radius — it is still a card, just not a bordered one. (A SQUARE tinted div with no border
// is not a card at all and yields nothing; that is existing behaviour and not what this change touches.)
$panel = $skin( $INK . $FILL . 'border-top-width:1px;border-top-color:rgb(225, 225, 225);border-left-width:0px;border-right-width:0px;border-radius:12px' );
$ok( ! empty( $panel['bg'] ),
	'NEGATIVE: a tinted panel with a top rule keeps its tint (got "' . ( $panel['bg'] ?? '' ) . '")' );
$ok( '12px' === trim( (string) ( $panel['radius'] ?? '' ) ) && '' === $bw_of( $panel ),
	'NEGATIVE: ...and its radius, while only the BORDER is withheld (radius "' . ( $panel['radius'] ?? '' ) . '", border "' . $bw_of( $panel ) . '")' );

echo "\n== NEGATIVE: an unborded card is unaffected\n";

$plain = $skin( $INK . $FILL . 'border-radius:12px' );
$ok( '' === $bw_of( $plain ),
	'NEGATIVE: a card with no border at all reports none, as before' );

echo $fails ? "\n\xe2\x9c\x97 $fails FAILED\n" : "\n\xe2\x9c\x93 ALL PASS - only a four-sided border is a box border\n";
exit( $fails ? 1 : 0 );
