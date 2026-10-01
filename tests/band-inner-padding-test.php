<?php
/**
 * Regression guard: a band whose vertical rhythm lives on its INNER container keeps that rhythm.
 *
 * The section is the band, but it is not always the box that is padded. A very common shape leaves the
 * `<section>` bare and spends the inset on a centred wrapper inside it:
 *
 *     <section class="relative">
 *       <div class="absolute inset-0 bg-cover opacity-20"></div>
 *       <div class="container pt-24 pb-28"> … </div>
 *     </section>
 *
 * The section's computed padding is then genuinely `0`, so the mapper's "ZERO IS A VALUE" rule fired, wrote
 * `pt-[0px] pb-[0px]`, and the converted band lost its inset outright. Measured on a real hero: source 695px
 * against the conversion's 489px — and the missing 206px is exactly the wrapper's `96px + 112px`.
 *
 * The inheritance is deliberately narrow, because padding a band twice is worse than padding it once: the
 * section must declare NO vertical inset of its own, decorative out-of-flow layers are skipped, exactly ONE
 * content child may remain, and it must SPAN the band. The NEGATIVE cases below are the whole point — each
 * one is a shape where inheriting would be wrong.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/band-inner-padding-test.php"
 *
 * Exit code 0 = all PASS, 1 = at least one FAIL.
 */

if ( ! class_exists( 'FW_Site_Converter_Sources' ) ) {
	fwrite( STDERR, "FAIL: site-converter not loaded (run inside a WP install with the plugin active)\n" );
	exit( 1 );
}

$fails = 0;
$ok    = function ( $cond, $msg ) use ( &$fails ) {
	if ( $cond ) { echo "  \xe2\x9c\x93 $msg\n"; } else { $fails++; echo "  \xe2\x9c\x97 FAIL: $msg\n"; }
};

$INK = 'color:rgb(20, 20, 24);font-family:Inter, sans-serif;font-size:16px;line-height:24px;text-align:start;';

/**
 * The converted first section's vertical rhythm, as PIXELS.
 *
 * Assert on the resolved measurement, not on the token's spelling: the rhythm is emitted as a theme
 * SPACING-SCALE slug (96px is `pt-10`, not `pt-24` or `pt-[96px]`), so a string match on the source's own
 * Tailwind class silently fails whatever the converter does. spacing_token_px() is the emitter's own
 * inverse, so this reads back exactly what the band will render.
 */
$pads = function ( $body ) use ( $INK ) {
	$html = '<!DOCTYPE html><html><head><title>Band</title></head><body><main>' . $body . '</main></body></html>';
	$res  = FW_Site_Converter_Sources::build_from_html( $html, 'Band', array( 'dynamic_chrome' => true ) );
	$walk = function ( $nodes ) use ( &$walk ) {
		foreach ( (array) $nodes as $n ) {
			if ( ! is_array( $n ) ) { continue; }
			// The section node keys on `type`; inner shortcode nodes key on `shortcode`. Accept either.
			if ( 'section' === ( $n['type'] ?? $n['shortcode'] ?? '' ) ) { return $n; }
			$hit = $walk( $n['_items'] ?? array() );
			if ( $hit ) { return $hit; }
		}
		return null;
	};
	$sec = $walk( $res['files']['pages.json']['pages'][0]['builder'] ?? array() );
	$a   = is_array( $sec ) ? (array) $sec['atts'] : array();

	$to_px = new ReflectionMethod( 'FW_Site_Converter_Mapper', 'spacing_token_px' );
	$to_px->setAccessible( true );
	$one = function ( $v ) use ( $to_px ) {
		$tok = is_array( $v ) ? (string) ( $v['base'] ?? '' ) : (string) $v;
		if ( '' === $tok ) { return null; }                                       // nothing emitted = theme default
		if ( preg_match( '/-\[([0-9.]+)px\]$/', $tok, $m ) ) { return (float) $m[1]; }  // arbitrary token
		$px = $to_px->invoke( null, $tok );
		return ( null === $px || '' === $px ) ? null : (float) $px;
	};
	return array(
		'top'    => $one( $a['padding_top'] ?? '' ),
		'bottom' => $one( $a['padding_bottom'] ?? '' ),
		'ttok'   => is_array( $a['padding_top'] ?? '' ) ? (string) ( $a['padding_top']['base'] ?? '' ) : (string) ( $a['padding_top'] ?? '' ),
	);
};
$near = function ( $got, $want ) { return null !== $got && abs( (float) $got - $want ) <= 8.0; };

$COPY = '<h1 data-sc-cs="font-size:48px;font-weight:700">Laptop &amp; PC repair</h1>'
	. '<p data-sc-cs="font-size:18px;line-height:28px">A run of body copy long enough to read as a real paragraph on the page.</p>';

echo "\n== A bare section with a padded content wrapper inherits the wrapper's inset\n";

// The real shape: an out-of-flow backdrop, then the padded container. Section height == wrapper height.
$hero = '<section class="relative overflow-hidden" data-sc-cs="' . $INK . 'display:block;height:695px">'
	. '<div aria-hidden="true" class="absolute inset-0 bg-cover" data-sc-cs="display:block;position:absolute;top:0px;right:0px;bottom:0px;left:0px;height:695px;opacity:0.2"></div>'
	. '<div class="container pt-24 pb-28 relative" data-sc-cs="' . $INK . 'display:block;padding:96px 20px 112px;max-width:1152px;height:695px">'
	. $COPY . '</div></section>';

$p = $pads( $hero );
$ok( null !== $p['top'] && $p['top'] > 0,
	'the band does NOT come back flush (got ' . var_export( $p['top'], true ) . 'px, token "' . $p['ttok'] . '")' );
$ok( $near( $p['top'], 96 ),
	'...it carries the wrapper\'s 96px top inset (got ' . var_export( $p['top'], true ) . 'px)' );
$ok( $near( $p['bottom'], 112 ),
	'...and its 112px bottom inset (got ' . var_export( $p['bottom'], true ) . 'px)' );

echo "\n== ...and it is applied ONCE, not twice\n";

// The wrapper is also FLATTENED into its blocks, and that path carries a flattened wrapper's padding onto
// the boundary blocks as mtAdd/mbAdd. Both behaviours are right alone and catastrophic together: the inset
// arrives as the section's padding AND as the first block's top margin, and the band renders ~2x its inset
// too tall (measured on a real hero: 793px against the source's 695px).
$margins = function ( $body ) use ( $INK ) {
	$html = '<!DOCTYPE html><html><head><title>Band</title></head><body><main>' . $body . '</main></body></html>';
	$res  = FW_Site_Converter_Sources::build_from_html( $html, 'Band', array( 'dynamic_chrome' => true ) );
	$out  = array();
	$walk = function ( $nodes ) use ( &$walk, &$out ) {
		foreach ( (array) $nodes as $n ) {
			if ( ! is_array( $n ) ) { continue; }
			$m = $n['atts']['spacing']['margin'] ?? null;
			if ( is_array( $m ) && ( '' !== (string) ( $m['top'] ?? '' ) || '' !== (string) ( $m['bottom'] ?? '' ) ) ) {
				$out[] = (string) ( $m['top'] ?? '' ) . '/' . (string) ( $m['bottom'] ?? '' );
			}
			$walk( $n['_items'] ?? array() );
		}
	};
	$walk( $res['files']['pages.json']['pages'][0]['builder'] ?? array() );
	return $out;
};
$ms = $margins( $hero );
$to_px2 = new ReflectionMethod( 'FW_Site_Converter_Mapper', 'spacing_token_px' );
$to_px2->setAccessible( true );
$dup = false;
foreach ( $ms as $pair ) {
	foreach ( explode( '/', $pair ) as $tok ) {
		if ( '' === $tok ) { continue; }
		$px = (float) $to_px2->invoke( null, $tok );
		if ( abs( $px - 96 ) <= 8 || abs( $px - 112 ) <= 8 ) { $dup = true; }
	}
}
$ok( ! $dup,
	'no block ALSO carries the band\'s 96/112 inset as its own margin (block margins: ' . ( $ms ? implode( ', ', $ms ) : 'none' ) . ')' );

echo "\n== NEGATIVE: a section that pads ITSELF is untouched\n";

$self = '<section data-sc-cs="' . $INK . 'display:block;padding:64px 20px;height:400px">'
	. '<div data-sc-cs="' . $INK . 'display:block;padding:96px 20px 112px;height:400px">' . $COPY . '</div></section>';
$ps = $pads( $self );
$ok( $near( $ps['top'], 64 ),
	'NEGATIVE: the section\'s own 64px wins — the wrapper\'s 96px is NOT added on top (got ' . var_export( $ps['top'], true ) . 'px)' );

echo "\n== NEGATIVE: the inset of a CARD is not the inset of the band\n";

// Two content children: whatever padding they carry is a card's, not the band's.
$cards = '<section data-sc-cs="' . $INK . 'display:block;height:400px">'
	. '<div data-sc-cs="' . $INK . 'display:block;padding:96px 20px 112px;height:400px">' . $COPY . '</div>'
	. '<div data-sc-cs="' . $INK . 'display:block;padding:96px 20px 112px;height:400px">' . $COPY . '</div></section>';
$pc = $pads( $cards );
$ok( ! $near( $pc['top'], 96 ),
	'NEGATIVE: with TWO content children nothing is inherited (got ' . var_export( $pc['top'], true ) . 'px)' );

echo "\n== NEGATIVE: a child that does not SPAN the band is not the band's container\n";

$short = '<section data-sc-cs="' . $INK . 'display:block;height:800px">'
	. '<div data-sc-cs="' . $INK . 'display:block;padding:96px 20px 112px;height:300px">' . $COPY . '</div></section>';
$psh = $pads( $short );
$ok( ! $near( $psh['top'], 96 ),
	'NEGATIVE: a 300px child inside an 800px band is a card, not the container (got ' . var_export( $psh['top'], true ) . 'px)' );

echo "\n== NEGATIVE: a wrapper with no inset of its own adds nothing\n";

$flat = '<section data-sc-cs="' . $INK . 'display:block;height:400px">'
	. '<div data-sc-cs="' . $INK . 'display:block;height:400px">' . $COPY . '</div></section>';
$pf = $pads( $flat );
$ok( null === $pf['top'] || $pf['top'] <= 0,
	'NEGATIVE: a genuinely flush band stays flush — zero is still a value (got ' . var_export( $pf['top'], true ) . 'px)' );

echo "\n== NEGATIVE: an unstamped section is not guessed at\n";

$bare = '<section class="relative"><div class="container pt-24 pb-28">' . $COPY . '</div></section>';
$pb2  = $pads( $bare );
$ok( ! $near( $pb2['top'], 96 ),
	'NEGATIVE: with no height stamp to prove the child spans the band, nothing is inherited (got ' . var_export( $pb2['top'], true ) . 'px)' );

echo $fails ? "\n\xe2\x9c\x97 $fails FAILED\n" : "\n\xe2\x9c\x93 ALL PASS - a band keeps its rhythm wherever the source spends it\n";
exit( $fails ? 1 : 0 );
