<?php
/**
 * Regression guard: things that are SHARED across a converted site must not be keyed by something
 * page-relative, and a preset must not carry a trait its members disagree on.
 *
 * 1. SECTION IDS. page_css() writes every page's `#<css_id>` rules into ONE site-wide stylesheet, while the
 *    positional fallback id means only "the Nth section of whatever page you are on". So `#section-2` was the
 *    home page's second section AND the about page's second section at once, and whichever page's measured
 *    fill and type reached the sheet painted both. Measured: an about section the source draws transparent came
 *    back tinted with the home page's panel fill.
 *
 * 2. BUTTON SIZES. A button stretched to its container (`w-full`) has no horizontal padding to speak of — its
 *    width comes from whatever holds it. Letting it vote invented a "size" whose defining trait was
 *    `padding-x: 0`; where full-width CTAs are the commonest button that became the DEFAULT size and every
 *    ordinary button rendered with no side padding.
 *
 * 3. BUTTON TYPE. Colour presets were keyed on colour alone, so an uppercase tracked pill and a sentence-case
 *    CTA sharing one fill collapsed into one preset and the first seen imposed its casing and tracking on the
 *    other. They are keyed apart now, and a button's own stamp chooses between them.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/cross-page-scope-test.php"
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

echo "\n== A positional section id is scoped to its page\n";

$mapping = array(
	'pages' => array(
		array( 'slug' => 'home',  'sections' => array(
			array( 'css_id' => 'section-1' ), array( 'css_id' => 'section-2' ), array( 'css_id' => 'pricing' ),
		) ),
		array( 'slug' => 'about', 'sections' => array(
			array( 'css_id' => 'section-1' ), array( 'css_id' => 'section-2' ), array( 'css_id' => 'pricing' ),
		) ),
	),
);
$scoped = FW_Site_Converter_Mapper::scope_section_ids( $mapping );
$ids = function ( $m, $i ) {
	$out = array();
	foreach ( (array) ( $m['pages'][ $i ]['sections'] ?? array() ) as $s ) { $out[] = (string) ( $s['css_id'] ?? '' ); }
	return $out;
};
$home  = $ids( $scoped, 0 );
$about = $ids( $scoped, 1 );

$ok( array( 'section-1', 'section-2', 'pricing' ) === $home,
	'the home page keeps the bare ids it already ships with (got: ' . implode( ',', $home ) . ')' );
$ok( in_array( 'about-section-2', $about, true ),
	'an inner page\'s positional id is scoped to that page (got: ' . implode( ',', $about ) . ')' );
$ok( ! array_intersect( array( 'section-1', 'section-2' ), $about ),
	'...so no positional id is shared between two pages' );
$ok( in_array( 'pricing', $about, true ),
	'NEGATIVE: a SOURCE-given id is left alone — in-page anchors point at it' );

$twice = FW_Site_Converter_Mapper::scope_section_ids( $scoped );
$ok( $about === $ids( $twice, 1 ),
	'NEGATIVE: scoping twice changes nothing (build_pages and page_css both call it)' );

echo "\n== A stretched button is not a size, and type splits a preset\n";

$ink  = 'color:rgb(255, 255, 255);font-family:Inter, sans-serif;line-height:24px;';
$grad = 'background-image:linear-gradient(110deg, rgb(90, 60, 230), rgb(60, 140, 220));';

// A masthead pill: small, UPPERCASE, tracked. A page CTA: larger, sentence case, no tracking, real side
// padding. And two full-width CTAs, which are the commonest button here — exactly the shape that used to
// define the default size as `padding-x: 0`.
$pill = '<a href="/quote" class="inline-flex px-4 py-2 rounded-full uppercase tracking-wider" data-sc-cs="' . $ink . $grad
	. 'display:inline-flex;font-size:12px;font-weight:700;letter-spacing:0.6px;text-transform:uppercase;padding:8px 16px;border-radius:9999px">Free Quote</a>';
$cta  = '<a href="/quote" class="inline-block px-8 py-4 rounded-xl font-bold" data-sc-cs="' . $ink . $grad
	. 'display:inline-block;font-size:16px;font-weight:700;padding:16px 32px;border-radius:14px">Get Your Free Quote</a>';
$wide = '<a href="/start" class="block w-full py-4 rounded-xl font-bold" data-sc-cs="' . $ink . $grad
	. 'display:block;width:100%;font-size:16px;font-weight:700;padding:16px 0px;border-radius:14px">Start Now</a>';

$html = '<!DOCTYPE html><html><head><title>Quote</title></head><body>'
	. '<header data-sc-cs="' . $ink . 'display:flex;padding:12px 24px">'
	. '<a href="/" data-sc-cs="display:flex">Brand</a>'
	. '<a href="/about" data-sc-cs="' . $ink . 'display:block">About</a>' . $pill
	. '</header><main>'
	. '<section data-sc-cs="' . $ink . 'padding:80px 24px;display:block"><h1>Quote</h1>'
	. '<p data-sc-cs="font-size:16px;line-height:26px">Body copy long enough to count as a real paragraph here.</p>'
	. $wide . '</section>'
	. '<section data-sc-cs="' . $ink . 'padding:80px 24px;display:block"><h2>Ready?</h2>'
	. '<p data-sc-cs="font-size:16px;line-height:26px">More copy, again long enough to read as a real paragraph.</p>'
	. $wide . $cta . '</section>'
	. '</main></body></html>';

$v     = FW_Site_Converter_Sources::build_from_html( $html, 'Quote', array( 'dynamic_chrome' => true ) )['files']['theme-settings.json']['values'] ?? array();
$sizes = (array) ( $v['button_sizes'] ?? array() );
$cols  = (array) ( $v['button_colors'] ?? array() );

$px_of = function ( $sizes, $fs ) {
	foreach ( $sizes as $s ) {
		if ( (string) ( $s['font_size']['value'] ?? '' ) === (string) $fs ) { return (string) ( $s['padding_x']['value'] ?? '' ); }
	}
	return null;
};
$px16 = $px_of( $sizes, '16' );
$ok( '0' !== (string) $px16,
	'the 16px size does not assert padding-x: 0 from the full-width buttons (got: ' . var_export( $px16, true ) . ')' );
$ok( '32' === (string) $px16,
	'...it takes the 32px inset the only normal button of that size actually has' );
$ok( '16' === (string) $px_of( $sizes, '12' ),
	'the pill keeps its own 16px inset' );

$tts = array();
foreach ( $cols as $c ) { $tts[] = strtolower( trim( (string) ( $c['states']['default']['text_transform'] ?? 'none' ) ) ); }
$ok( count( $cols ) >= 2,
	'one fill with two type treatments yields two presets (got ' . count( $cols ) . ')' );
$ok( in_array( 'uppercase', $tts, true ),
	'...one of them carries the pill\'s uppercase' );
$ok( in_array( '', $tts, true ) || in_array( 'none', $tts, true ),
	'...and the other leaves casing off, so the sentence-case CTA is not made to shout' );

echo "\n== A button's own stamp chooses between presets that differ only in type\n";

$upper = 'display:inline-flex;font-size:12px;letter-spacing:0.6px;text-transform:uppercase;' . $grad;
$plain = 'display:inline-block;font-size:16px;' . $grad;
$pick  = function ( $cs ) use ( $cols ) {
	$slug = '';
	foreach ( $cols as $c ) { if ( '' === $slug ) { $slug = 'btn-' . ( $c['slug'] ?? '' ); } }
	return FW_Site_Converter_Mapper::button_style_for_type( $slug, $cs );
};
$a = $pick( $upper );
$b = $pick( $plain );
$ok( '' !== $a && '' !== $b,
	'both resolve to a preset' );
$ok( $a !== $b,
	'an uppercase tracked button and a plain one do NOT land on the same preset (got ' . $a . ' / ' . $b . ')' );

echo "\n== NEGATIVE: type never changes what a button paints\n";

$other = FW_Site_Converter_Mapper::button_style_for_type( 'btn-does-not-exist', $upper );
$ok( 'btn-does-not-exist' === $other,
	'NEGATIVE: an unknown preset slug is returned untouched' );
$ok( FW_Site_Converter_Mapper::button_style_for_type( 'btn-fill', '' ) === 'btn-fill',
	'NEGATIVE: with no stamp to read, the chosen preset stands' );

echo "\n== A stretched button abstains only when its inset is ABSENT\n";

// The abstention is about an ABSENT measurement, not about the button being stretched. Treating every
// stretched sample as silent left a site whose buttons are all `w-full` with NO padding-x at all, and every
// button collapsed or stretched to its container — found by converting a second source, and measured there:
// px NULL against the 32px its buttons actually carry.
$only_wide = function ( $pad ) use ( $ink ) {
	$one = '<a href=\'/go\' class=\'block w-full rounded-xl font-bold\' data-sc-cs=\'' . $ink
		. 'display:block;width:100%;font-size:16px;font-weight:700;height:56px;padding:' . $pad . ';border-radius:10px;'
		. 'background-color:rgb(39, 104, 77);color:rgb(253, 253, 252)\'>Start now</a>';
	return '<!DOCTYPE html><html><head><title>B</title></head><body><main>'
		. '<section data-sc-cs=\'' . $ink . 'padding:80px 24px;display:block\'><h1>One</h1>'
		. '<p data-sc-cs=\'font-size:16px;line-height:26px\'>Body copy long enough to count as a real paragraph here.</p>'
		. $one . '</section>'
		. '<section data-sc-cs=\'' . $ink . 'padding:80px 24px;display:block\'><h2>Two</h2>'
		. '<p data-sc-cs=\'font-size:16px;line-height:26px\'>More copy, again long enough to read as a real paragraph.</p>'
		. $one . '</section></main></body></html>';
};
$wide_px = function ( $pad ) use ( $only_wide, $px_of ) {
	$v = FW_Site_Converter_Sources::build_from_html( $only_wide( $pad ), 'B', array( 'dynamic_chrome' => true ) )['files']['theme-settings.json']['values'] ?? array();
	return $px_of( (array) ( $v['button_sizes'] ?? array() ), '16' );
};

$ok( '32' === (string) $wide_px( '0px 32px' ),
	'every sample stretched, but each carries a real 32px inset — the size keeps it (got ' . var_export( $wide_px( '0px 32px' ), true ) . ')' );
$bare_px = $wide_px( '0px 0px' );
$ok( '' === (string) $bare_px || null === $bare_px,
	'NEGATIVE: stretched with a genuinely zero inset still says nothing, so no padding-x is fabricated (got ' . var_export( $bare_px, true ) . ')' );

echo $fails ? "\n\xe2\x9c\x97 $fails FAILED\n" : "\n\xe2\x9c\x93 ALL PASS - shared ids are page-scoped and a preset carries only what its members agree on\n";
exit( $fails ? 1 : 0 );
