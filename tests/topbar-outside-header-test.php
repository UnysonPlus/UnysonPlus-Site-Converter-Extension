<?php
/**
 * Regression guard: a utility bar that sits ABOVE <header> as its sibling is still the top bar.
 *
 * detect_topbar() only ever looked at the header's own direct children, and bailed at its first guard
 * ("a single DESKTOP row → no top bar") when it found one row. But a sticky header is routinely written with
 * the bar OUTSIDE it:
 *
 *     <div class="hidden md:block border-b text-xs"> address · phone · hours </div>
 *     <header class="sticky top-0 z-40"> logo · nav · CTA </header>
 *
 * and that is deliberate — only the header is `position:sticky`, so the utility bar scrolls away while the
 * nav stays pinned. Putting the bar inside the sticky element would pin it too.
 *
 * The whole bar was therefore dropped: a converted site lost its street address, its `tel:` link and its
 * opening hours. Nothing showed it missing, either — the bar is not a <section>, so the visual band audit has
 * no band for it; only `conversion-drops.json → text_coverage` named it, by quoting the hours string.
 *
 * Measured over 114 captures: 91 have a <header>, 68 of those are sticky/fixed, and 47 pair a non-sticky bar
 * above a sticky header — so this is roughly half of every captured site that has a header at all.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/topbar-outside-header-test.php"
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

$bar_of = function ( $html ) {
	$m = new ReflectionMethod( 'FW_Site_Converter_Stitch', 'detect_topbar' );
	$m->setAccessible( true );
	return (array) $m->invoke( null, $html );
};

$INK  = 'color:lab(25.76 0 0);font-family:Inter, sans-serif;font-size:16px;line-height:24px;text-align:start;';
$NAV  = '<header class="sticky top-0 z-40 border-b" data-sc-cs="' . $INK . 'display:block;height:65px;position:sticky;top:0px;z-index:40">'
	. '<div class="container flex items-center justify-between h-16" data-sc-cs="' . $INK . 'display:flex;height:64px;justify-content:space-between;align-items:center">'
	. '<a href="/" data-sc-cs="display:flex"><img src="/logo.png" alt="Brand" width="160" height="40"></a>'
	. '<nav data-sc-cs="' . $INK . 'display:flex;gap:32px"><a href="/a" data-sc-cs="' . $INK . 'display:block">Laptop</a>'
	. '<a href="/b" data-sc-cs="' . $INK . 'display:block">Desktop</a><a href="/c" data-sc-cs="' . $INK . 'display:block">Kontakt</a></nav>'
	. '</div></header>';
$PAGE = function ( $before ) use ( $NAV, $INK ) {
	return '<!DOCTYPE html><html><head><title>Repair</title></head><body>' . $before . $NAV
		. '<main><section data-sc-cs="' . $INK . 'display:block;padding:96px 24px"><h1 data-sc-cs="font-size:48px">Repair</h1>'
		. '<p data-sc-cs="font-size:16px;line-height:26px">Body copy long enough to count as a real paragraph here.</p>'
		. '</section></main><footer data-sc-cs="' . $INK . 'display:block;padding:64px 24px"><p data-sc-cs="font-size:14px">&copy; 2026</p></footer></body></html>';
};

/* The real shape: short, hairline-ruled, small type, desktop-only, no brand. */
$BAR = '<div class="hidden md:block border-b text-xs" data-sc-cs="' . $INK . 'display:block;height:33px;font-size:12px;border-bottom-width:1px;background-color:rgba(255, 255, 255, 0.95)">'
	. '<div class="container flex items-center justify-between py-2" data-sc-cs="' . $INK . 'display:flex;height:32px;padding:8px 20px;justify-content:space-between;align-items:center">'
	. '<span data-sc-cs="' . $INK . 'display:flex;font-size:12px">Erzgie&szlig;ereistra&szlig;e 40, 80335 M&uuml;nchen</span>'
	. '<span data-sc-cs="' . $INK . 'display:flex;font-size:12px"><a href="tel:08954244434" data-sc-cs="' . $INK . 'display:flex;font-size:12px">089 542 444 34</a>'
	. '<span data-sc-cs="font-size:12px">&middot;</span><span data-sc-cs="font-size:12px">Mo&ndash;Fr 11&ndash;18 &middot; Sa 11&ndash;13</span></span>'
	. '</div></div>';

echo "\n== A bar ABOVE the header is detected\n";

$b = $bar_of( $PAGE( $BAR ) );
$ok( ! empty( $b['has'] ), 'the top bar is found at all (has=' . var_export( $b['has'] ?? null, true ) . ')' );
$ok( false !== strpos( (string) ( $b['left'] ?? '' ), 'Erzgie' ),
	'the address lands in the LEFT cell (got "' . mb_substr( (string) ( $b['left'] ?? '' ), 0, 46 ) . '")' );
$ok( false !== strpos( (string) ( $b['right'] ?? '' ), '089 542 444 34' ),
	'the phone lands in the RIGHT cell (got "' . mb_substr( (string) ( $b['right'] ?? '' ), 0, 46 ) . '")' );
$ok( false !== strpos( (string) ( $b['right'] ?? '' ), 'Mo' ),
	'...with the opening hours — the exact string text_coverage reported missing' );

echo "\n== It is desktop-only, as the source says\n";

$ok( ! empty( $b['mobile_hide'] ),
	'`hidden md:block` becomes mobile_hide (got ' . var_export( $b['mobile_hide'] ?? null, true ) . ')' );

echo "\n== Its colours are OPTION VALUES, not raw colour functions\n";

foreach ( array( 'bg', 'text' ) as $k ) {
	$v = (string) ( $b[ $k ] ?? '' );
	$ok( '' === $v || preg_match( '/^(#[0-9a-f]{3,8}|rgba?\()/i', $v ),
		'`' . $k . '` is a hex / rgb value a colour field can hold, not `lab()` / `oklab()` (got "' . $v . '")' );
}
$ok( false !== strpos( (string) ( $b['bg'] ?? '' ), '0.95' ),
	'...and the bar\'s 0.95 translucency survives normalising (got "' . ( $b['bg'] ?? '' ) . '")' );

echo "\n== A bar outside a PINNED header is marked to scroll away\n";

$ok( ! empty( $b['scrolls_away'] ),
	'the source pins only the header, so the bar is flagged scrolls_away (got ' . var_export( $b['scrolls_away'] ?? null, true ) . ')' );

/* Only when the header actually pins — a static header has nothing to scroll away FROM. */
$static_hdr = str_replace( array( 'position:sticky;top:0px;z-index:40', 'class="sticky top-0 z-40 border-b"' ), array( 'position:static', 'class="border-b"' ), $PAGE( $BAR ) );
$bs = $bar_of( $static_hdr );
$ok( ! empty( $bs['has'] ) && empty( $bs['scrolls_away'] ),
	'NEGATIVE: with a STATIC header the bar is still detected but NOT flagged (has=' . var_export( $bs['has'] ?? null, true ) . ', scrolls_away=' . var_export( $bs['scrolls_away'] ?? null, true ) . ')' );

echo "\n== NEGATIVE: a bar INSIDE the header still works (the original path)\n";

$inside = '<!DOCTYPE html><html><head><title>Repair</title></head><body>'
	. '<header class="sticky top-0" data-sc-cs="' . $INK . 'display:block;height:98px;position:sticky;top:0px">'
	. '<div class="border-b text-xs" data-sc-cs="' . $INK . 'display:block;height:33px;font-size:12px;border-bottom-width:1px;background-color:rgba(255, 255, 255, 0.95)">'
	. '<div class="container flex items-center justify-between" data-sc-cs="' . $INK . 'display:flex;height:32px;padding:8px 20px;justify-content:space-between;align-items:center">'
	. '<span data-sc-cs="' . $INK . 'display:flex;font-size:12px">Erzgie&szlig;ereistra&szlig;e 40, 80335 M&uuml;nchen</span>'
	. '<span data-sc-cs="' . $INK . 'display:flex;font-size:12px">Mo&ndash;Fr 11&ndash;18 &middot; Sa 11&ndash;13</span></div></div>'
	. '<div class="container flex" data-sc-cs="' . $INK . 'display:flex;height:64px"><a href="/" data-sc-cs="display:flex"><img src="/logo.png" alt="Brand" width="160" height="40"></a>'
	. '<nav data-sc-cs="' . $INK . 'display:flex"><a href="/a" data-sc-cs="' . $INK . 'display:block">Laptop</a></nav></div></header>'
	. '<main><section data-sc-cs="' . $INK . 'display:block;padding:96px 24px"><h1 data-sc-cs="font-size:48px">Repair</h1>'
	. '<p data-sc-cs="font-size:16px;line-height:26px">Body copy long enough to count as a real paragraph here.</p></section></main></body></html>';
$bi = $bar_of( $inside );
$ok( ! empty( $bi['has'] ),
	'NEGATIVE: the in-header bar is still detected — the new path did not replace the old one' );
$ok( empty( $bi['scrolls_away'] ),
	'NEGATIVE: ...and a bar INSIDE the sticky header is NOT flagged to scroll away — the source pins it deliberately (got ' . var_export( $bi['scrolls_away'] ?? null, true ) . ')' );

echo "\n== NEGATIVE: what precedes the header is not always a top bar\n";

/* A real content block before the header (height, a heading) must NOT be swallowed into the chrome. */
$content = '<div data-sc-cs="' . $INK . 'display:block;height:420px;padding:48px 24px"><h2 data-sc-cs="font-size:32px">Welcome to the shop</h2>'
	. '<p data-sc-cs="font-size:16px;line-height:26px">A real content block that happens to sit above the header element.</p></div>';
$bc = $bar_of( $PAGE( $content ) );
$ok( empty( $bc['has'] ),
	'NEGATIVE: a 420px block with an <h2> is content, not a utility bar (has=' . var_export( $bc['has'] ?? null, true ) . ')' );

/* A MASTHEAD above a nav bar carries the brand — that is the header's own row, not a utility bar. */
$masthead = '<div data-sc-cs="' . $INK . 'display:block;height:72px"><a href="/" data-sc-cs="display:flex;font-size:28px">Brand</a></div>';
$bm = $bar_of( $PAGE( $masthead ) );
$ok( empty( $bm['has'] ),
	'NEGATIVE: a row carrying the brand lockup is a masthead, not a utility bar (has=' . var_export( $bm['has'] ?? null, true ) . ')' );

$none = $bar_of( $PAGE( '' ) );
$ok( empty( $none['has'] ),
	'NEGATIVE: a header with nothing above it has no top bar (has=' . var_export( $none['has'] ?? null, true ) . ')' );

echo $fails ? "\n\xe2\x9c\x97 $fails FAILED\n" : "\n\xe2\x9c\x93 ALL PASS - a utility bar is the top bar wherever the source puts it\n";
exit( $fails ? 1 : 0 );
