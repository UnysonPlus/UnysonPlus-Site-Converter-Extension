<?php
/**
 * Regression guard: a looping carousel's CLONE slides are markup, not content — and the near-miss classes
 * next to them are content, not clones.
 *
 * A looping slider scrolls past its own edge without a seam by duplicating slides at each end of the track.
 * The library injects those copies at runtime, so a capture of a RENDERED page contains them and nothing in
 * the markup says they are not real slides.
 *
 * Measured on one captured logo strip: a track of 57 slides, of which 12 were clones and 45 distinct logos.
 * The converter had no way to tell them apart, so it treated all 57 as content and built the section as a
 * wrapped grid of ~40 tiles where the source shows a single row.
 *
 * Two things this test pins down, both of which were got wrong first:
 *
 *  1. The signal is the library's own clone class, and ONLY that. `aria-hidden` is the obvious alternative —
 *     every clone on that strip carried it — and it is measurably wrong: 26 of the strip's REAL slides were
 *     also `aria-hidden="true"`, because a carousel marks whatever is off-screen as hidden from assistive
 *     tech. Pruning on `aria-hidden` deletes 26 genuine logos to remove 12 copies.
 *
 *  2. Swiper's `swiper-slide-duplicate-active` / `-next` / `-prev` read like narrower cases of
 *     `swiper-slide-duplicate` and mean the opposite: they mark a REAL slide that merely has a clone
 *     counterpart elsewhere. Matching them as clones cost exactly one logo on that strip — slide 50 of 57,
 *     the only copy of its image, classed `swiper-slide swiper-slide-duplicate-prev`.
 *
 * The prune therefore has to be LOSSLESS at the document level, which is the strongest assertion here: the
 * set of image srcs in the tree must be identical before and after, while the clone ELEMENTS are gone.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/slider-clones-are-not-content-test.php"
 *
 * Exit code 0 = all PASS, 1 = at least one FAIL.
 */

if ( ! class_exists( 'FW_Site_Converter_Stitch' ) ) {
	fwrite( STDERR, "FAIL: site-converter not loaded\n" );
	exit( 1 );
}

$fails = 0;
$ok    = function ( $cond, $msg ) use ( &$fails ) {
	if ( $cond ) { echo "  \xe2\x9c\x93 $msg\n"; } else { $fails++; echo "  \xe2\x9c\x97 FAIL: $msg\n"; }
};

$ld = new ReflectionMethod( 'FW_Site_Converter_Stitch', 'load_dom' );
$ld->setAccessible( true );

$slides = function ( $dom, $sel ) {
	if ( ! $dom ) { return 0; }
	$xp = new DOMXPath( $dom );
	return $xp->query( '//*[contains(concat(" ", normalize-space(@class), " "), " ' . $sel . ' ")]' )->length;
};
$srcs = function ( $dom ) {
	$o = array();
	if ( ! $dom ) { return $o; }
	foreach ( $dom->getElementsByTagName( 'img' ) as $im ) {
		$s = trim( (string) $im->getAttribute( 'src' ) );
		if ( '' !== $s ) { $o[ $s ] = 1; }
	}
	ksort( $o );
	return $o;
};
$raw_dom = function ( $html ) {
	$d = new DOMDocument();
	$p = libxml_use_internal_errors( true );
	$d->loadHTML( '<?xml encoding="UTF-8">' . $html );
	libxml_clear_errors();
	libxml_use_internal_errors( $p );
	return $d;
};

// ---------------------------------------------------------------------------
// A Swiper loop track, built to the shape the real capture has: leading clones, the real run, trailing
// clones, and — the trap — a REAL slide carrying a `-duplicate-prev` pointer class as its only copy.
// ---------------------------------------------------------------------------
$slide = function ( $cls, $img, $aria = false ) {
	return '<div class="swiper-slide ' . $cls . '"' . ( $aria ? ' aria-hidden="true"' : '' ) . '>'
		. '<figure class="swiper-slide-inner"><img src="/u/' . $img . '.png" alt="' . $img . '" /></figure></div>';
};

// A loop track's LEADING clones are copies of the TAIL and its TRAILING clones are copies of the HEAD —
// that is what makes the seam invisible. So every clone here has a real counterpart, which is the invariant
// the losslessness assertion below depends on. (The first version of this fixture gave its leading clones
// no real counterpart, so it demanded the prune keep images that only a clone carried — a fixture bug that
// cannot happen in a real track.)
$track  = '';
$track .= $slide( 'swiper-slide-duplicate', 'foxtrot', true );  // leading clone of the tail
$track .= $slide( 'swiper-slide-duplicate', 'golf', true );     // leading clone of the tail
$track .= $slide( 'swiper-slide-active', 'charlie' );           // real
$track .= $slide( 'swiper-slide-next', 'delta' );               // real
$track .= $slide( '', 'echo', true );                           // real but OFF-SCREEN: aria-hidden
$track .= $slide( '', 'foxtrot', true );                        // real but off-screen
// The trap: a REAL slide carrying a duplicate-POINTER class, and the only un-cloned copy of its image.
$track .= $slide( 'swiper-slide-duplicate-prev', 'golf' );
$track .= $slide( 'swiper-slide-duplicate', 'charlie', true );  // trailing clone of the head
$html = '<!DOCTYPE html><html><body><section><div class="elementor-image-carousel-wrapper swiper">'
	. '<div class="elementor-image-carousel swiper-wrapper">' . $track . '</div></div></section></body></html>';

echo "\n== The clone slides are gone and nothing else is\n";

$before = $raw_dom( $html );
$after  = $ld->invoke( null, $html );

$ok( 8 === $slides( $before, 'swiper-slide' ), 'the fixture track parses as 8 slides before the prune' );
$ok( 3 === $slides( $before, 'swiper-slide-duplicate' ), 'the fixture carries exactly 3 bare-token clone slides' );
$ok( 1 === $slides( $before, 'swiper-slide-duplicate-prev' ), 'and exactly 1 pointer-classed REAL slide' );
$ok( 5 === $slides( $after, 'swiper-slide' ), 'after the prune 5 slides remain (3 clones removed)' );
$ok( 0 === $slides( $after, 'swiper-slide-duplicate' ), 'no bare-token clone slide survives' );

echo "\n== LOSSLESS: the document's image set is unchanged\n";
$b = $srcs( $before );
$a = $srcs( $after );
$ok( array_keys( $b ) === array_keys( $a ), sprintf( 'same image srcs before and after (%d vs %d)', count( $b ), count( $a ) ) );
$lost = array_diff( array_keys( $b ), array_keys( $a ) );
$ok( ! $lost, 'no image src lost: ' . ( $lost ? implode( ', ', $lost ) : 'none' ) );

echo "\n== The Swiper POINTER class marks a real slide, not a clone\n";
$ok( 1 === $slides( $after, 'swiper-slide-duplicate-prev' ), 'the `-duplicate-prev` slide survives (it is real)' );
$ok( isset( $a['/u/golf.png'] ), 'its image survives — the exact logo the first version of this rule deleted' );

echo "\n== NEGATIVE: `aria-hidden` alone must NOT prune — an off-screen slide is real content\n";
$ok( isset( $a['/u/echo.png'] ) && isset( $a['/u/foxtrot.png'] ), 'both off-screen aria-hidden REAL slides survive' );
$ok( 2 === $slides( $after, 'swiper-slide' ) - $slides( $after, 'swiper-slide-active' ) - $slides( $after, 'swiper-slide-next' ) - $slides( $after, 'swiper-slide-duplicate-prev' ),
	'the two unclassed survivors are exactly those off-screen real slides' );

echo "\n== Other libraries' clone tokens, same rule\n";
foreach ( array(
	'slick'  => array( 'slick-slide slick-cloned', 'slick-slide' ),
	'splide' => array( 'splide__slide splide__slide--clone', 'splide__slide' ),
	'glide'  => array( 'glide__slide glide__slide--clone', 'glide__slide' ),
	'tns'    => array( 'tns-item tns-slide-cloned', 'tns-item' ),
	'owl'    => array( 'owl-item cloned', 'owl-item' ),
) as $lib => $pair ) {
	list( $clone_cls, $real_cls ) = $pair;
	$h = '<!DOCTYPE html><html><body><div class="track">'
		. '<div class="' . $clone_cls . '"><img src="/x/dupe.png" /></div>'
		. '<div class="' . $real_cls . '"><img src="/x/dupe.png" /></div>'
		. '<div class="' . $real_cls . '"><img src="/x/only.png" /></div>'
		. '</div></body></html>';
	$d = $ld->invoke( null, $h );
	$n = $d ? ( new DOMXPath( $d ) )->query( '//div[contains(@class,"' . explode( ' ', $real_cls )[0] . '")]' )->length : -1;
	$s = $srcs( $d );
	$ok( 2 === $n, "{$lib}: the clone is dropped, both real slides stay (got {$n})" );
	$ok( isset( $s['/x/dupe.png'] ) && isset( $s['/x/only.png'] ), "{$lib}: no image lost" );
}

echo "\n== NEGATIVE: the bare word `cloned` outside a slider is left alone\n";
// `cloned` is too generic to act on by itself — it has to sit beside a slide/item class.
$h = '<!DOCTYPE html><html><body><div class="cloned-notice panel cloned"><p>Account cloned successfully</p>'
	. '<img src="/x/keep.png" /></div></body></html>';
$d = $ld->invoke( null, $h );
$ok( $d && false !== strpos( (string) $d->saveHTML(), 'Account cloned successfully' ), 'a `cloned` class with no slide class survives' );
$kept = $srcs( $d );
$ok( isset( $kept['/x/keep.png'] ), 'and keeps its image' );

printf( "\n%s\n", $fails ? "{$fails} FAIL(S)" : 'ALL PASS' );
exit( $fails ? 1 : 0 );
