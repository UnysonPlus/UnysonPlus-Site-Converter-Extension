<?php
/**
 * Regression guard: a testimonial avatar's RING comes from the photo, not from a divider above it.
 *
 * WHY THIS FILE EXISTS. Converted testimonial avatars were drawn with a SQUARE border around a circular
 * photo — immediately visible, and the first thing anyone notices about that band.
 *
 * Measured on the source:
 *
 *   img     class "w-12 h-12 rounded-full object-cover ring-2 ring-blue-500/40 shadow"
 *           border-radius 9999px · border 0px
 *           box-shadow    rgba(59,130,246,0.4) 0 0 0 2px     <- the ring, a Tailwind `ring-2`
 *   parent  class "pt-6 border-t border-white/10 mt-6 flex items-center gap-4"
 *           border widths 1px / 0px / 0px / 0px              <- a DIVIDER above the author row
 *
 * The detector walked up from the photo looking for "a frame with a border" and tested `border-top-width`
 * ALONE. The author row's `border-t` answered, so a horizontal rule was adopted as the avatar's frame; its
 * radius is 0, so the border drew as a square around a circular photo. The code even anticipated the
 * symptom — "the frame's ROUNDING, or the ring draws as a square" — and carried a radius when it found one.
 * The radius was 0 because the element was never a frame.
 *
 * TWO distinct defects, one cause:
 *   1. a one-sided border is a RULE, not a frame — a ring has four sides;
 *   2. the real ring was invisible to the detector, because a Tailwind `ring-*` is a BOX-SHADOW on the
 *      photo, not a border on anything.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/avatar-ring-is-a-ring-not-a-divider-test.php"
 *
 * Exit code 0 = all PASS, 1 = at least one FAIL.
 */

if ( ! class_exists( 'FW_Site_Converter_Stitch' ) || ! class_exists( 'FW_Site_Converter_Mapper' ) ) {
	fwrite( STDERR, "FAIL: site-converter not loaded\n" );
	exit( 1 );
}

$fails = 0;
$ok    = function ( $cond, $msg ) use ( &$fails ) {
	if ( $cond ) { echo "  \xe2\x9c\x93 $msg\n"; } else { $fails++; echo "  \xe2\x9c\x97 FAIL: $msg\n"; }
};

// A REDUCED REAL CAPTURE (32 KB -> 9.9 KB), not a hand-built one. The first attempt at a synthetic band did
// NOT reproduce the square at all: the detector's walk depends on the exact nesting the builder emits, and a
// plausible-looking reconstruction simply missed it. Trimmed under a predicate requiring BOTH the ringed
// photo to survive and the square frame to still be emitted. Image srcs neutralised; no source-site names.
$fixture = __DIR__ . '/fixtures/avatar-ring-vs-divider.html';
if ( ! is_file( $fixture ) ) { fwrite( STDERR, "FAIL: fixture missing
" ); exit( 1 ); }
$html = file_get_contents( $fixture );

$map  = FW_Site_Converter_Stitch::html_to_mapping( $html, 'T', 't', true );
$blob = wp_json_encode( FW_Site_Converter_Mapper::build_pages( $map ) );

echo "\n== The fixture is a testimonials band\n";

$ok( false !== strpos( $blob, 'testimonials' ), 'it converts to a testimonials element' );
$ok( false !== strpos( $blob, 'Marcus Reed' ), '…carrying its authors' );

echo "\n== No square frame is drawn from the author-row divider\n";

// The exact shape of the defect: a `border:` on the avatar FRAME with no rounding.
$framed = preg_match( '/\.testimonial-avatar\s*\{[^}]*border:[^}]*1px[^}]*\}/i', $blob, $fm );
$ok( ! $framed, 'no bordered frame is emitted around the avatar' . ( $framed ? ' — got: ' . $fm[0] : '' ) );
// …and the shape that made it visible: a border with no rounding at all.
$ok( ! preg_match( '/\.testimonial-avatar\s*\{(?:(?!border-radius)[^}])*border:(?:(?!border-radius)[^}])*\}/i', $blob ),
	'…and certainly none without a radius' );

echo "\n== The photo's own ring IS carried\n";

// `ring-2` is a box-shadow on the photo. Emitted on the IMG, where the source puts it, so it follows the
// circular crop and cannot draw a square.
$ok( (bool) preg_match( '/\.testimonial-avatar\s+img\s*\{[^}]*box-shadow:/i', $blob ),
	'a ring is emitted on the photo' );
$ok( (bool) preg_match( '/\.testimonial-avatar\s+img\s*\{[^}]*box-shadow:[^;}]*59,\s*130,\s*246/i', $blob ),
	'…in the source\'s own colour' );
$ok( (bool) preg_match( '/\.testimonial-avatar\s+img\s*\{[^}]*box-shadow:[^;}]*2px/i', $blob ),
	'…and its 2px width' );

echo "\n== NEGATIVE: a REAL four-sided frame is still read as a ring\n";

// The case the frame detector exists for: a photo sitting inside a bordered, rounded frame.
// A REAL frame wraps ONLY the photo — that is what distinguishes it from the row above it and from the
// card around it, both of which carry words. Built by wrapping the fixture's own <img> in a dedicated
// bordered, rounded div, which is how a source that really does frame its portraits writes it.
$framed_html = preg_replace(
	'/(<img[^>]*ring-2[^>]*>)/i',
	'<div data-sc-cs="border-top-width:3px;border-right-width:3px;border-bottom-width:3px;border-left-width:3px;'
		. 'border-top-style:solid;border-top-color:rgb(234, 179, 8);border-radius:9999px;display:block">$1</div>',
	$html
);
$ok( $framed_html !== $html, 'the framed variant was built' );
$fblob = wp_json_encode( FW_Site_Converter_Mapper::build_pages(
	FW_Site_Converter_Stitch::html_to_mapping( $framed_html, 'T', 't', true )
) );
$ok( (bool) preg_match( '/\.testimonial-avatar\s*\{[^}]*border:[^;}]*3px/i', $fblob ),
	'a four-sided frame is still carried as a ring' );
$ok( (bool) preg_match( '/\.testimonial-avatar\s*\{[^}]*border-radius:/i', $fblob ),
	'…with its rounding, so it cannot draw square' );

printf( "\n%s\n", $fails ? "{$fails} FAIL(S)" : 'ALL PASS' );
exit( $fails ? 1 : 0 );
