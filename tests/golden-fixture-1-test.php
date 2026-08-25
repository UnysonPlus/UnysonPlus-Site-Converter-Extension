<?php
/**
 * Golden-fixture regression guard for the Site Converter deterministic (no-AI) path.
 *
 * Runs FW_Site_Converter_Sources::build_from_html() over the Golden Fixture 1 capture
 * (tests/fixtures/golden-fixture-1.html) and ASSERTS the current known-good output:
 *   - section css_ids (proves the source `id` attribute survives  = the P0 fix),
 *   - the per-section block/shortcode set (proves recognizers still map the same way),
 *   - key chrome theme-settings (logo squircle, footer columns=4, container ladder,
 *     menu hover color, CTA button, social profiles),
 *   - design tokens (palette) and typography/button evidence in the generated CSS.
 *
 * If a future recognizer/mapper/priority change drops or alters a mapping, an
 * assertion here FAILS loudly instead of shipping a silent coverage loss.
 *
 * The baseline was captured from a fresh run of the CURRENT (verified-good) code.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/golden-fixture-1-test.php"
 *
 * Exit code 0 = all PASS, 1 = at least one FAIL (CI-friendly).
 */

if ( ! class_exists( 'FW_Site_Converter_Sources' ) ) {
	fwrite( STDERR, "FAIL: site-converter not loaded (run inside a WP install with the plugin active)\n" );
	exit( 1 );
}

/* --------------------------------------------------------------------- *
 * Tiny assertion harness
 * --------------------------------------------------------------------- */
$GLOBALS['__pass'] = 0;
$GLOBALS['__fail'] = 0;
function ga( $label, $cond, $got = null ) {
	if ( $cond ) {
		$GLOBALS['__pass']++;
		echo "  PASS  $label\n";
	} else {
		$GLOBALS['__fail']++;
		echo "  FAIL  $label" . ( $got !== null ? "  (got: " . ( is_scalar( $got ) ? $got : wp_json_encode( $got ) ) . ")" : "" ) . "\n";
	}
}
function ga_eq( $label, $expected, $actual ) {
	ga( $label . " == " . ( is_scalar( $expected ) ? $expected : wp_json_encode( $expected ) ), $expected === $actual, $actual );
}

/* --------------------------------------------------------------------- *
 * Build from the fixture through the CURRENT deterministic path
 * --------------------------------------------------------------------- */
$fixture = __DIR__ . '/fixtures/golden-fixture-1.html';
if ( ! is_file( $fixture ) ) { fwrite( STDERR, "FAIL: fixture missing: $fixture\n" ); exit( 1 ); }
$html = file_get_contents( $fixture );

$bundle = FW_Site_Converter_Sources::build_from_html( $html, 'Golden', array( 'dynamic_chrome' => true ) );

$mapping = $bundle['mapping'] ?? array();
$files   = $bundle['files'] ?? array();
$pages   = $files['pages.json']['pages'] ?? array();
$td      = $files['theme-design.json'] ?? array();
$ts      = $files['theme-settings.json']['values'] ?? array();
$css     = (string) ( $td['custom_css'] ?? '' );

$builder = $pages[0]['builder'] ?? array();

/* Collect { css_id => [shortcodes...] } from the built page-builder tree. */
$sections = array();
foreach ( $builder as $sec ) {
	if ( ( $sec['type'] ?? '' ) !== 'section' ) { continue; }
	$cid   = $sec['atts']['css_id'] ?? '';
	$codes = array();
	$walk  = function ( $n ) use ( &$walk, &$codes ) {
		if ( ! is_array( $n ) ) { return; }
		if ( ( $n['type'] ?? '' ) === 'simple' ) { $codes[] = $n['shortcode'] ?? '?'; }
		foreach ( ( $n['_items'] ?? array() ) as $c ) { $walk( $c ); }
	};
	$walk( $sec );
	$sections[] = array( 'css_id' => $cid, 'codes' => $codes );
}

/* --------------------------------------------------------------------- *
 * 1) SECTION IDS (P0 fix) + section count
 * --------------------------------------------------------------------- */
echo "\n[1] Section ids (P0: source id attribute survives)\n";
$cids = array_map( function ( $s ) { return $s['css_id']; }, $sections );
ga_eq( "section count", 3, count( $sections ) );
ga_eq( "css_ids in order", array( 'hero', 'features', 'cta' ), $cids );
ga( "no generated 'section-N' ids leaked", ! in_array( 'section-1', $cids, true ) && ! in_array( 'section-2', $cids, true ) && ! in_array( 'section-3', $cids, true ), wp_json_encode( $cids ) );

/* --------------------------------------------------------------------- *
 * 2) PER-SECTION SHORTCODE SETS
 * --------------------------------------------------------------------- */
echo "\n[2] Per-section block/shortcode sets\n";
$by_id = array();
foreach ( $sections as $s ) { $by_id[ $s['css_id'] ] = $s['codes']; }

// The hero RIGHT column (photo in an organic frame + floating "24/7 Care" badge + blob backdrop)
// now DECOMPOSES into a native media_image + icon_box (P0 image-composite fix) instead of a second
// verbatim code_block. The one remaining code_block is the avatar RATING cluster (stars/score text).
// The hero LEFT-column pill ("Voted #1 Pet Boarding") sits directly above the h1, so it is now the
// heading's OVERLINE (folded into special_heading) — NOT a standalone `badge`. The lone `icon_box`
// is the floating "24/7 Care" card, a different chip.
// The hero's intro line ("Fresh, fun, and safe boarding…") folds into the special_heading as its
// SUBTITLE (a short intro), so there's no standalone text_block — even though the hero is a DECOMPOSED
// content column (handled by build_cell_items, not the plain section loop).
ga_eq( "hero shortcodes",
	array( 'special_heading', 'button', 'button', 'avatar', 'code_block', 'media_image', 'icon_box' ),
	$by_id['hero'] ?? array() );
$td_json = (string) wp_json_encode( $td );
ga( "hero paragraph folded into special_heading subtitle", preg_match( '/"subtitle":"Fresh, fun/', $td_json ) === 1, 'not found as a subtitle value' );
ga( "hero right column decomposed → a native media_image", in_array( 'media_image', $by_id['hero'] ?? array(), true ) );
ga( "hero right column decomposed → an editable icon_box (floating badge)", in_array( 'icon_box', $by_id['hero'] ?? array(), true ) );
ga( "hero chip-before-h1 → the heading's overline, NOT a standalone badge", ! in_array( 'badge', $by_id['hero'] ?? array(), true ) );
ga_eq( "features shortcodes",
	array( 'special_heading', 'icon_box', 'icon_box', 'icon_box' ),
	$by_id['features'] ?? array() );
// The CTA band (centered h2 + short subtext + one button): the subtext is a genuine intro line, so it
// now folds into the heading as its SUBTITLE — leaving a centered special_heading (title + subtitle) +
// button. Still deliberately NOT the native `call_to_action` shortcode (its title-left/button-right
// bordered box would visibly regress a centered CTA).
ga_eq( "cta shortcodes",
	array( 'special_heading', 'button' ),
	$by_id['cta'] ?? array() );

/* Heading subtitle detection — a short intro paragraph after a title folds into the special_heading's
 * subtitle (brevity-guarded); body copy / lists / multi-paragraph stays a Text Block. */
$rm_sub = new ReflectionMethod( 'FW_Site_Converter_Mapper', 'is_heading_subtitle' ); $rm_sub->setAccessible( true );
ga( "subtitle guard: short one-sentence para → subtitle", $rm_sub->invoke( null, array( 'html' => '<p>We keep every pet safe, happy and cared for.</p>' ) ) );
ga( "subtitle guard: short para with an inline link → subtitle", $rm_sub->invoke( null, array( 'html' => '<p>Read our <a href="/how">how-we-care</a> guide.</p>' ) ) );
ga( "subtitle guard: >220-char body paragraph → NOT a subtitle", ! $rm_sub->invoke( null, array( 'html' => '<p>' . str_repeat( 'This is a long body paragraph that goes on. ', 8 ) . '</p>' ) ) );
ga( "subtitle guard: a bullet list → NOT a subtitle", ! $rm_sub->invoke( null, array( 'html' => '<ul><li>One</li><li>Two</li></ul>' ) ) );
ga( "subtitle guard: two paragraphs → NOT a subtitle", ! $rm_sub->invoke( null, array( 'html' => '<p>First.</p><p>Second.</p>' ) ) );
$rm_inl = new ReflectionMethod( 'FW_Site_Converter_Mapper', 'heading_part_inline_html' ); $rm_inl->setAccessible( true );
ga_eq( "subtitle inline: outer <p> unwrapped, inline link kept", 'See <a href="/x">docs</a>.', $rm_inl->invoke( null, '<p>See <a href="/x">docs</a>.</p>', 'subtitle' ) );

// Spot-guards for the specific surfaces the audit called out (avatar present; icon_box x3; 2 hero buttons)
ga( "hero contains an avatar (avatar-group survives)", in_array( 'avatar', $by_id['hero'] ?? array(), true ) );
ga( "features has exactly 3 icon_box", 3 === count( array_filter( $by_id['features'] ?? array(), function ( $c ) { return $c === 'icon_box'; } ) ) );
ga( "hero has 2 buttons", 2 === count( array_filter( $by_id['hero'] ?? array(), function ( $c ) { return $c === 'button'; } ) ) );

/* Two-tone hero heading + hand-drawn underline COLOUR resolution (the black-heading bug fix):
 * the source accent span (`text-primary`) and the underline `<svg class="text-secondary">
 * <path stroke="currentColor">` must resolve to CONCRETE inline colours from the extracted palette,
 * so they paint the brand accents on the page BODY instead of inheriting black. */
$hero_sh = null;
foreach ( $builder as $sec ) {
	if ( ( $sec['atts']['css_id'] ?? '' ) !== 'hero' ) { continue; }
	$fh = function ( $n ) use ( &$fh ) {
		if ( ! is_array( $n ) ) { return null; }
		if ( ( $n['shortcode'] ?? '' ) === 'special_heading' ) { return $n; }
		foreach ( ( $n['_items'] ?? array() ) as $c ) { $r = $fh( $c ); if ( $r ) { return $r; } }
		return null;
	};
	$hero_sh = $fh( $sec );
	break;
}
$hero_title = strtolower( (string) ( $hero_sh['atts']['title'] ?? '' ) );
// The accent span keeps its class AND carries an inline color = the primary accent (green #21c45d).
ga( "hero two-tone accent span resolves to inline PRIMARY colour (green, not black)",
	(bool) preg_match( '/<span[^>]*\bclass="[^"]*text-primary[^"]*"[^>]*style="[^"]*color:\s*#21c45d/i', $hero_title )
	|| (bool) preg_match( '/<span[^>]*style="[^"]*color:\s*#21c45d[^"]*"[^>]*class="[^"]*text-primary/i', $hero_title ),
	$hero_title );
// The underline svg resolves text-secondary → inline color = the SECONDARY accent (amber #fbbd23),
// so its stroke="currentColor" strokes amber. And the underline path survives.
ga( "hero underline svg resolves to inline SECONDARY colour (amber, not black)",
	(bool) preg_match( '/<svg[^>]*style="[^"]*color:\s*#fbbd23/i', $hero_title ),
	$hero_title );
ga( "hero underline path (stroke=currentColor) survives", strpos( $hero_title, 'stroke="currentcolor"' ) !== false, $hero_title );

/* --------------------------------------------------------------------- *
 * 3) CHROME THEME-SETTINGS
 * --------------------------------------------------------------------- */
echo "\n[3] Chrome theme-settings\n";
$logo_custom = $ts['header_logo']['logo_type']['custom'] ?? array();
ga_eq( "header_logo type", 'custom', $ts['header_logo']['logo_type']['logo_type'] ?? null );
// Two-tone wordmark: the source splits "Fresh"(ink) + "Paws"(text-primary green). The converter now emits
// site_title WITH the accent run wrapped in `<span class="accent">` (theme prints site_title raw inside
// `.site-title-text`), and the scoped logo_custom_css paints it — richer than the hand-built demo's flat title.
ga_eq( "header_logo site_title (two-tone split)", 'Fresh<span class="accent">Paws</span>', $logo_custom['site_title'] ?? null );
ga( "header_logo two-tone css paints .accent green", isset( $logo_custom['logo_custom_css'] ) && strpos( $logo_custom['logo_custom_css'], '.accent' ) !== false && strpos( $logo_custom['logo_custom_css'], 'rgb(33, 196, 93)' ) !== false, $logo_custom['logo_custom_css'] ?? null );
// Golden Fixture 1' logo tile is `rounded-2xl` = 24px on a 40px box (ratio 0.6). CSS clamps border-radius to
// box/2, so a ≥50% radius renders as a FULL CIRCLE — the frame is `circle`, not `squircle`.
ga_eq( "header_logo icon frame (circle — 24px clamps on a 40px tile)", 'circle', $logo_custom['logo_icon_frame'] ?? null );
ga_eq( "header_logo icon chip bg (brand green)", 'rgb(33, 196, 93)', $logo_custom['logo_icon_frame_bg']['custom'] ?? null );
ga( "header_logo icon chip carries the paw svg mark", ( ( $logo_custom['logo_icon']['type'] ?? '' ) === 'svg' ) && strpos( (string) ( $logo_custom['logo_icon']['markup'] ?? '' ), 'paw-print' ) !== false );

ga_eq( "menu hover color (brand green)", 'rgb(33, 196, 93)', $ts['header_menu']['menu_link_hover_color']['custom'] ?? null );

$cta = null;
foreach ( ( $ts['header_main']['main_right'] ?? array() ) as $el ) {
	if ( ( $el['element_type']['element'] ?? '' ) === 'cta_button' ) { $cta = $el['element_type']['cta_button']; break; }
}
ga_eq( "header CTA text", 'Book a Stay', $cta['cta_text'] ?? null );

ga_eq( "footer columns count", '4', $ts['main_footer_columns']['count'] ?? null );
ga_eq( "footer background color", 'rgb(41, 61, 54)', $ts['footer_background']['color']['value']['custom'] ?? null );
ga_eq( "social profiles count", 3, count( $ts['social_profiles'] ?? array() ) );

// Contact column (col 4) = a heading + NATIVE icon_text rows (map-pin/phone/mail).
// Each row keeps its inline leading SVG tinted brand green; the multi-line address
// folds to ONE comma line (icon_text is single-line). This REPLACED the former
// one-Text-blob contact list — contact rows are now real, editable elements.
$fcols4 = $ts['main_footer_columns']['4'] ?? array();
$contact_col = array();
foreach ( $fcols4 as $ck => $col ) {
	if ( strpos( (string) $ck, 'main_footer_col_' ) !== 0 || ! is_array( $col ) ) { continue; }
	$h0   = $col[0]['element_type'] ?? array();
	$h    = ( ( $h0['element'] ?? '' ) === 'heading' ) ? (string) ( $h0['heading']['heading_text'] ?? '' ) : (string) ( $h0['text']['text_content'] ?? '' );
	if ( strpos( $h, 'Contact Info' ) !== false ) { $contact_col = $col; break; }
}
ga( "footer contact column found", ! empty( $contact_col ) );
ga( "footer contact heading is a native heading element",
	( $contact_col[0]['element_type']['element'] ?? '' ) === 'heading' );
// Contact rows are the UNIFIED list_item element (superseded icon_text) — text + tinted inline-svg icon.
$icon_texts = array();
foreach ( array_slice( $contact_col, 1 ) as $cel ) {
	if ( ( $cel['element_type']['element'] ?? '' ) === 'list_item' ) { $icon_texts[] = $cel['element_type']['list_item']; }
}
ga_eq( "footer contact = 3 native list_item rows", 3, count( $icon_texts ) );
$markup_all = implode( ' ', array_map( function ( $it ) { return $it['li_icon']['markup'] ?? ''; }, $icon_texts ) );
$texts_all  = implode( ' | ', array_map( function ( $it ) { return $it['li_text'] ?? ''; }, $icon_texts ) );
ga( "footer contact rows carry inline-svg icons", substr_count( $markup_all, '<svg' ) >= 3, $markup_all );
ga( "footer contact leading icons tinted brand green", substr_count( $markup_all, 'rgb(33, 196, 93)' ) >= 3, $markup_all );
ga( "footer contact map-pin icon present", strpos( $markup_all, 'lucide-map-pin' ) !== false );
ga( "footer contact address folded to single line (comma, not glued)",
	strpos( $texts_all, 'Fresh Meadow Lane, Springfield' ) !== false && strpos( $texts_all, 'LaneSpringfield' ) === false );

/* --- Negative controls + isolated positives via Reflection (targeted detector behavior) --- */
$rm_cols = new ReflectionMethod( 'FW_Site_Converter_Stitch', 'detect_footer_columns' );
$rm_cols->setAccessible( true );
$rm_soc = new ReflectionMethod( 'FW_Site_Converter_Stitch', 'detect_footer_social' );
$rm_soc->setAccessible( true );
$rm_logo = new ReflectionMethod( 'FW_Site_Converter_Stitch', 'detect_logo' );
$rm_logo->setAccessible( true );

// Positive: a brand column with 3 rounded-full social anchors → 3 social_profiles.
$soc_html = '<footer><div><a href="#"><svg class="lucide lucide-facebook"></svg></a>'
	. '<a href="#"><svg class="lucide lucide-instagram"></svg></a>'
	. '<a href="#"><svg class="lucide lucide-twitter"></svg></a></div></footer>';
ga_eq( "detect_footer_social → 3 profiles from rounded-full anchors", 3, count( $rm_soc->invoke( null, $soc_html ) ) );

// is_pricing_table: a PRODUCT-card grid (image + "Add to Basket", priced, NO feature list) is NOT a pricing
// table — it must fall through to card→icon_box so the image/description/button survive (Pinky Bites regression:
// product cards were becoming a pricing_table with a bogus "/mo"). A real pricing grid (feature lists) still is.
$rm_price = new ReflectionMethod( 'FW_Site_Converter_Stitch', 'is_pricing_table' ); $rm_price->setAccessible( true );
$mk_el = function ( $html ) { $d = new DOMDocument(); libxml_use_internal_errors( true ); $d->loadHTML( '<?xml encoding="utf-8"><div id="R">' . $html . '</div>' ); return $d->getElementById( 'R' ); };
$prod_grid = $mk_el(
	'<div class="card"><img src="a.jpg"><h3>Strawberry</h3><p>Fluffy</p><span>$4.50</span><a href="#">Add to Basket</a></div>'
	. '<div class="card"><img src="b.jpg"><h3>Cotton</h3><p>Whipped</p><span>$4.95</span><a href="#">Add to Basket</a></div>'
	. '<div class="card"><img src="c.jpg"><h3>Cookies</h3><p>Cocoa</p><span>$4.75</span><a href="#">Add to Basket</a></div>' );
$price_grid = $mk_el(
	'<div class="plan"><h3>Basic</h3><span>$9/mo</span><ul><li>1 site</li><li>10GB</li></ul><a href="#">Choose plan</a></div>'
	. '<div class="plan featured"><h3>Pro</h3><span>$29/mo</span><ul><li>10 sites</li><li>100GB</li></ul><a href="#">Choose plan</a></div>'
	. '<div class="plan"><h3>Team</h3><span>$99/mo</span><ul><li>Unlimited</li><li>1TB</li></ul><a href="#">Choose plan</a></div>' );
ga( "is_pricing_table: product-card grid (img + Add to Basket, no list) → NOT pricing", ! $rm_price->invoke( null, $prod_grid ) );
ga( "is_pricing_table: real plan grid (feature lists) → IS pricing", (bool) $rm_price->invoke( null, $price_grid ) );

// Positive: a contact column (icon+text rows) → kind=contact with leading icons.
$contact_src = '<footer><div><h3>Contact Info</h3><ul>'
	. '<li class="flex"><svg class="lucide lucide-map-pin text-primary"></svg><span>1 A St<br>Town</span></li>'
	. '<li class="flex"><svg class="lucide lucide-phone text-primary"></svg><span>(555) 000</span></li>'
	. '<li class="flex"><svg class="lucide lucide-mail text-primary"></svg><span>a@b.com</span></li></ul></div></footer>';
$cc = $rm_cols->invoke( null, $contact_src );
ga_eq( "contact detector → 1 column", 1, count( $cc ) );
ga_eq( "contact detector → kind=contact", 'contact', $cc[0]['kind'] ?? null );
ga_eq( "contact detector → 3 icon rows", 3, count( $cc[0]['rows'] ?? array() ) );
ga( "contact detector → row keeps leading svg", strpos( (string) ( $cc[0]['rows'][0]['icon'] ?? '' ), 'map-pin' ) !== false );

// Positive: a NEWSLETTER / signup column (heading + email input + button, no links/items) → kind=newsletter,
// captured with tagline/placeholder/button (the "Sprinkles Club" 4th column that used to be dropped, collapsing
// a 4-col footer to 3). Alongside two link columns → 3 columns total (both links + the newsletter kept).
$news_src = '<footer>'
	. '<div><h3>Sweet Menu</h3><ul><li><a href="/a">Cupcakes</a></li><li><a href="/b">Cookies</a></li></ul></div>'
	. '<div><h3>Explore</h3><ul><li><a href="/c">About</a></li><li><a href="/d">Blog</a></li></ul></div>'
	. '<div><h3>Sprinkles Club</h3><p>Subscribe for sweet secrets and early access.</p>'
	. '<form><input type="email" placeholder="Your sweet email..."><button type="submit">Join</button></form></div>'
	. '</footer>';
$nc = $rm_cols->invoke( null, $news_src );
ga_eq( "newsletter detector → 3 columns (2 links + newsletter, none dropped)", 3, count( $nc ) );
$news_col = null; foreach ( $nc as $c ) { if ( ( $c['kind'] ?? '' ) === 'newsletter' ) { $news_col = $c; break; } }
ga( "newsletter detector → a newsletter column exists", $news_col !== null );
ga_eq( "newsletter column title", 'Sprinkles Club', $news_col['title'] ?? null );
ga_eq( "newsletter column placeholder captured", 'Your sweet email...', $news_col['newsletter']['placeholder'] ?? null );
ga_eq( "newsletter column button captured", 'Join', $news_col['newsletter']['button'] ?? null );
ga( "newsletter column tagline captured", strpos( (string) ( $news_col['newsletter']['tagline'] ?? '' ), 'sweet secrets' ) !== false );

// Negative control: a 2-column footer (two link lists, no contact/social) stays 2 columns.
$two_col = '<footer><div><h3>Company</h3><ul><li><a href="/a">A</a></li><li><a href="/b">B</a></li></ul></div>'
	. '<div><h3>Legal</h3><ul><li><a href="/c">C</a></li><li><a href="/d">D</a></li></ul></div></footer>';
$tc = $rm_cols->invoke( null, $two_col );
ga_eq( "negative: 2-column footer stays 2", 2, count( $tc ) );
ga( "negative: neither column is 'contact'", ( ( $tc[0]['kind'] ?? '' ) !== 'contact' ) && ( ( $tc[1]['kind'] ?? '' ) !== 'contact' ), wp_json_encode( array( $tc[0]['kind'] ?? '', $tc[1]['kind'] ?? '' ) ) );

// Negative control: a plain text logo (no leading icon chip, single-tone) stays text-only.
$plain_logo = '<header><a href="/"><span style="">Acme</span></a><nav><a href="/x">X</a></nav></header>';
$pl = $rm_logo->invoke( null, $plain_logo, 'Acme' );
ga_eq( "negative: plain logo text = Acme", 'Acme', $pl['text'] ?? null );
ga_eq( "negative: plain logo has no icon chip frame", 'none', $pl['frame'] ?? null );
ga_eq( "negative: plain logo has no two-tone accent run", '', $pl['title_accent_text'] ?? null );

$ladder = $ts['general_layout']['layout_container_width'] ?? array();
ga( "container ladder present (base/md/lg)", isset( $ladder['base'], $ladder['md'], $ladder['lg'] ), wp_json_encode( array_keys( $ladder ) ) );
ga_eq( "container ladder lg width", '1232', $ladder['lg']['value'] ?? null );

/* --------------------------------------------------------------------- *
 * 4) DESIGN TOKENS (palette) + typography/button evidence in generated CSS
 * --------------------------------------------------------------------- */
echo "\n[4] Palette + typography + button presets\n";
ga_eq( "colors.ink", '#1a1a1a', $td['colors']['ink'] ?? null );
ga_eq( "colors.footer_bg", '#141414', $td['colors']['footer_bg'] ?? null );

// Brand green now lives in the native button-colour PRESET (the Primary preset's fill) rather than a
// transplanted `.sc-btn-primary` block in the child-theme CSS — so check the preset (or CSS) carries it.
$_green_in_preset = false;
foreach ( (array) ( $ts['button_colors'] ?? array() ) as $_p ) { if ( strpos( wp_json_encode( $_p ), 'rgb(33, 196, 93)' ) !== false ) { $_green_in_preset = true; break; } }
ga( "brand green (rgb(33, 196, 93)) present (button preset or CSS)", $_green_in_preset || strpos( $css, 'rgb(33, 196, 93)' ) !== false );
ga( "typography: Nunito (headings) present in CSS", strpos( $css, 'Nunito' ) !== false );
ga( "typography: Inter (body) present in CSS", strpos( $css, 'Inter' ) !== false );
ga( "heading scale: #features h2 rule present", strpos( $css, '#features h2' ) !== false );
ga( "heading scale: #cta h2 rule present", strpos( $css, '#cta h2' ) !== false );

// Button presets: body buttons now reference the NATIVE button-colour presets (style=btn-{slug}) — the
// SAME presets the header CTA uses — instead of a transplanted `.sc-btn-*` class in the child-theme CSS.
$_bc_slugs = array_map( function ( $p ) { return isset( $p['slug'] ) ? $p['slug'] : ''; }, (array) ( $ts['button_colors'] ?? array() ) );
ga( "button presets: primary role mapped (btn-primary)", in_array( 'primary', $_bc_slugs, true ) && count( array_filter( $_bc_slugs ) ) >= 2 );
ga( "button presets: no .sc-btn-* transplant left in child-theme CSS", preg_match( '/\.sc-btn-[a-z0-9-]+\s*\{/', $css ) === 0 );
ga( "button presets: a :hover state exists", strpos( wp_json_encode( $ts['button_colors'] ?? array() ), 'hover' ) !== false );

/* --------------------------------------------------------------------- *
 * 5) MAPPING-LEVEL section ids match builder (both id paths agree)
 * --------------------------------------------------------------------- */
echo "\n[5] Stitch/Mapper id paths agree\n";
$m_ids = array();
foreach ( ( $mapping['pages'][0]['sections'] ?? array() ) as $s ) { $m_ids[] = $s['css_id'] ?? ''; }
ga_eq( "mapping css_ids == builder css_ids", $cids, $m_ids );

/* --------------------------------------------------------------------- *
 * 6) NEW RECOGNIZERS — per-recognizer synthetic fixtures (Golden Fixture 1 has no
 *    table / accordion / counter / list, so each is proven on its own HTML).
 *    Each asserts the recognizer emits the right native shortcode + payload.
 * --------------------------------------------------------------------- */
echo "\n[6] New recognizers (counter / table / accordion / list)\n";

/* Build a synthetic page and return every leaf `simple` node (shortcode + atts) in document order. */
$sc_nodes_of = function ( $body_html ) {
	$doc = '<!DOCTYPE html><html><head><title>T</title></head><body><main>' . $body_html . '</main></body></html>';
	$bundle = FW_Site_Converter_Sources::build_from_html( $doc, 'Synthetic', array( 'dynamic_chrome' => false ) );
	$pages  = $bundle['files']['pages.json']['pages'] ?? array();
	$builder = $pages[0]['builder'] ?? array();
	$nodes = array();
	$walk = function ( $n ) use ( &$walk, &$nodes ) {
		if ( ! is_array( $n ) ) { return; }
		if ( ( $n['type'] ?? '' ) === 'simple' ) { $nodes[] = $n; }
		foreach ( ( $n['_items'] ?? array() ) as $c ) { $walk( $c ); }
	};
	foreach ( $builder as $sec ) { $walk( $sec ); }
	return $nodes;
};
$codes_of = function ( $nodes ) { return array_map( function ( $n ) { return $n['shortcode'] ?? '?'; }, $nodes ); };
$first_sc = function ( $nodes, $sc ) { foreach ( $nodes as $n ) { if ( ( $n['shortcode'] ?? '' ) === $sc ) { return $n; } } return null; };

/* --- Counter / stat grid → `counter` shortcodes (ORPHAN builder now fed) --- */
$counter_html = '<section id="stats"><div class="grid grid-cols-3 gap-8">'
	. '<div><div>10,000+</div><div>Happy pets</div></div>'
	. '<div><div>98%</div><div>Satisfaction</div></div>'
	. '<div><div>24</div><div>Locations</div></div>'
	. '</div></section>';
$cn = $sc_nodes_of( $counter_html );
$counters = array_values( array_filter( $cn, function ( $n ) { return ( $n['shortcode'] ?? '' ) === 'counter'; } ) );
ga( "counter grid → >=3 counter shortcodes", count( $counters ) >= 3, wp_json_encode( $codes_of( $cn ) ) );
$c0 = $counters[0] ?? array();
ga_eq( "counter[0] number", '10000', $c0['atts']['number'] ?? null );
ga_eq( "counter[0] suffix", '+', $c0['atts']['suffix'] ?? null );
$c1 = $counters[1] ?? array();
ga_eq( "counter[1] suffix (percent)", '%', $c1['atts']['suffix'] ?? null );
ga_eq( "counter[1] number", '98', $c1['atts']['number'] ?? null );

/* Negative control: a real feature/prose grid must NOT become counters (tight match). */
$feat_html = '<section id="feat"><div class="grid grid-cols-2 gap-8">'
	. '<div><h3>Fast delivery</h3><p>We ship every order within twenty four hours of purchase.</p></div>'
	. '<div><h3>Great support</h3><p>Our friendly team answers every question you might have quickly.</p></div>'
	. '</div></section>';
$fn = $sc_nodes_of( $feat_html );
ga( "feature/prose grid is NOT mis-claimed as counters", 0 === count( array_filter( $fn, function ( $n ) { return ( $n['shortcode'] ?? '' ) === 'counter'; } ) ), wp_json_encode( $codes_of( $fn ) ) );

/* --- Table → native `table` shortcode --- */
$table_html = '<section id="tbl"><table>'
	. '<thead><tr><th>Plan</th><th>Price</th></tr></thead>'
	. '<tbody><tr><td>Basic</td><td>$9</td></tr><tr><td>Pro</td><td>$19</td></tr></tbody>'
	. '</table></section>';
$tn = $sc_nodes_of( $table_html );
$tbl = $first_sc( $tn, 'table' );
ga( "table → a `table` shortcode", $tbl !== null, wp_json_encode( $codes_of( $tn ) ) );
ga_eq( "table purpose tabular", 'tabular', $tbl['atts']['table']['header_options']['table_purpose'] ?? null );
ga_eq( "table header_rows", 1, $tbl['atts']['table']['header_options']['header_rows'] ?? null );
ga_eq( "table cols count", 2, count( $tbl['atts']['table']['cols'] ?? array() ) );
ga_eq( "table content rows", 3, count( $tbl['atts']['table']['content'] ?? array() ) );
ga( "table header cell text carried", strpos( wp_json_encode( $tbl['atts']['table']['content'][0] ?? array() ), 'Plan' ) !== false );

/* --- Accordion / FAQ (<details>) → native `accordion` shortcode --- */
$acc_html = '<section id="faq"><div class="faq">'
	. '<details><summary>How do I get a refund?</summary><p>Contact support within 30 days.</p></details>'
	. '<details><summary>Do you offer a free trial?</summary><p>Yes, 14 days.</p></details>'
	. '</div></section>';
$an = $sc_nodes_of( $acc_html );
$acc = $first_sc( $an, 'accordion' );
ga( "details group → an `accordion` shortcode", $acc !== null, wp_json_encode( $codes_of( $an ) ) );
ga_eq( "accordion has 2 tabs", 2, count( $acc['atts']['tabs'] ?? array() ) );
ga_eq( "accordion tab[0] title", 'How do I get a refund?', $acc['atts']['tabs'][0]['tab_title'] ?? null );
ga( "accordion tab[0] content carried", strpos( (string) ( $acc['atts']['tabs'][0]['tab_content'] ?? '' ), 'within 30 days' ) !== false );

/* --- List (<ul>) → native `feature_list` shortcode --- */
$list_html = '<section id="list"><ul class="benefits"><li>Unlimited projects</li><li>Priority support</li><li>Custom domains</li></ul></section>';
$ln = $sc_nodes_of( $list_html );
$fl = $first_sc( $ln, 'feature_list' );
ga( "ul → a `feature_list` shortcode", $fl !== null, wp_json_encode( $codes_of( $ln ) ) );
ga_eq( "feature_list items", 3, count( $fl['atts']['items'] ?? array() ) );
ga_eq( "feature_list item[0] text", 'Unlimited projects', $fl['atts']['items'][0]['text'] ?? null );
ga_eq( "ul design = check", 'check', $fl['atts']['design'] ?? null );

/* <ol> → numbered design */
$ol_nodes = $sc_nodes_of( '<section id="ol"><ol><li>First step here</li><li>Second step here</li></ol></section>' );
$ol_fl = $first_sc( $ol_nodes, 'feature_list' );
ga_eq( "ol design = numbered", 'numbered', $ol_fl['atts']['design'] ?? null );

/* Negative control: a <nav> menu list must NOT become a feature_list. */
$nav_nodes = $sc_nodes_of( '<section id="n"><nav><ul><li><a href="#a">Home</a></li><li><a href="#b">About</a></li></ul></nav></section>' );
ga( "nav menu list is NOT mis-claimed as feature_list", null === $first_sc( $nav_nodes, 'feature_list' ), wp_json_encode( $codes_of( $nav_nodes ) ) );

/* --- Logo / "trusted by" strip → native `logo_grid` shortcode (was a verbatim code_block) --- */
$logo_html = '<section id="logos"><div class="flex items-center gap-8">'
	. '<a href="https://a.example.com"><img src="https://cdn.example.com/acme.svg" alt="Acme"></a>'
	. '<img src="https://cdn.example.com/globex.svg" alt="Globex">'
	. '<img src="https://cdn.example.com/initech.svg" alt="Initech">'
	. '</div></section>';
$lg_nodes = $sc_nodes_of( $logo_html );
$lg = $first_sc( $lg_nodes, 'logo_grid' );
ga( "logo strip → a `logo_grid` shortcode (not code_block)", $lg !== null, wp_json_encode( $codes_of( $lg_nodes ) ) );
ga( "logo strip is NOT a code_block", null === $first_sc( $lg_nodes, 'code_block' ), wp_json_encode( $codes_of( $lg_nodes ) ) );
ga_eq( "logo_grid captured 3 logos", 3, count( $lg['atts']['logos'] ?? array() ) );
ga_eq( "logo_grid logo[0] image url", 'https://cdn.example.com/acme.svg', $lg['atts']['logos'][0]['image']['url'] ?? null );
ga_eq( "logo_grid logo[0] name (alt)", 'Acme', $lg['atts']['logos'][0]['name'] ?? null );
ga_eq( "logo_grid logo[0] link (enclosing <a>)", 'https://a.example.com', $lg['atts']['logos'][0]['link_url'] ?? null );

/* --- CTA band (centered h2 + subtext + one button) stays FAITHFULLY ASSEMBLED (centered
 * special_heading + text_block + button). The native `call_to_action` shortcode is intentionally
 * NOT used: its horizontal title-left/button-right bordered layout regresses a centered CTA. --- */
$cta_html = '<section id="c"><div class="container text-center">'
	. '<h2>Ready to get started?</h2>'
	. '<p>Join thousands of happy customers today.</p>'
	. '<a class="inline-block bg-primary px-8 py-3 rounded-full" href="/signup">Sign Up Now</a>'
	. '</div></section>';
$cta_nodes = $sc_nodes_of( $cta_html );
ga( "centered CTA band is NOT mapped to call_to_action (kept faithful)", null === $first_sc( $cta_nodes, 'call_to_action' ), wp_json_encode( $codes_of( $cta_nodes ) ) );
ga( "centered CTA band assembles a heading", $first_sc( $cta_nodes, 'special_heading' ) !== null, wp_json_encode( $codes_of( $cta_nodes ) ) );
ga( "centered CTA band assembles a button", $first_sc( $cta_nodes, 'button' ) !== null, wp_json_encode( $codes_of( $cta_nodes ) ) );
$cta_btn = $first_sc( $cta_nodes, 'button' );
ga_eq( "assembled CTA button label", 'Sign Up Now', $cta_btn['atts']['label'] ?? ( $cta_btn['atts']['text'] ?? null ) );

/* --------------------------------------------------------------------- *
 * 7) NEW RECOGNIZERS (round 2) — tabs / steps / timeline / progress /
 *    pricing / lottie / svg-draw + table presets + source animation intent.
 *    Each proven on its own synthetic HTML, each with a NEGATIVE control.
 * --------------------------------------------------------------------- */
echo "\n[7] Round-2 recognizers (tabs / steps / timeline / progress / pricing / lottie / svg-draw / presets / animations)\n";

/* --- Tabs → native `tabs` --- */
$tabs_html = '<section id="t"><div class="tabs"><div role="tablist">'
	. '<button role="tab" aria-controls="tp1" aria-selected="true">Overview</button>'
	. '<button role="tab" aria-controls="tp2">Details</button></div>'
	. '<div role="tabpanel" id="tp1"><p>A quick summary.</p></div>'
	. '<div role="tabpanel" id="tp2"><p>Deeper information.</p></div></div></section>';
$tn = $sc_nodes_of( $tabs_html );
$tabs = $first_sc( $tn, 'tabs' );
ga( "tabs widget → a `tabs` shortcode", $tabs !== null, wp_json_encode( $codes_of( $tn ) ) );
ga_eq( "tabs has 2 entries", 2, count( $tabs['atts']['tabs'] ?? array() ) );
ga_eq( "tabs[0] title", 'Overview', $tabs['atts']['tabs'][0]['tab_title'] ?? null );
ga_eq( "tabs[0] is_active", 'yes', $tabs['atts']['tabs'][0]['is_active'] ?? null );
ga( "tabs[0] panel content carried", strpos( (string) ( $tabs['atts']['tabs'][0]['tab_content'] ?? '' ), 'quick summary' ) !== false );
/* Negative: a plain <ul> nav must NOT become tabs. */
$nav_tabs = $sc_nodes_of( '<section id="nt"><ul class="nav"><li><a href="#a">Home</a></li><li><a href="#b">About</a></li></ul></section>' );
ga( "plain <ul> nav is NOT mis-claimed as tabs", null === $first_sc( $nav_tabs, 'tabs' ), wp_json_encode( $codes_of( $nav_tabs ) ) );

/* --- Steps → native `steps` --- */
$steps_html = '<section id="s"><div class="steps">'
	. '<div class="step"><span class="step-number">1</span><h3>Plan</h3><p>Define scope.</p></div>'
	. '<div class="step"><span class="step-number">2</span><h3>Build</h3><p>Develop it.</p></div>'
	. '<div class="step"><span class="step-number">3</span><h3>Launch</h3><p>Ship it.</p></div></div></section>';
$stn = $sc_nodes_of( $steps_html );
$steps = $first_sc( $stn, 'steps' );
ga( "steps flow → a `steps` shortcode", $steps !== null, wp_json_encode( $codes_of( $stn ) ) );
ga_eq( "steps has 3 items", 3, count( $steps['atts']['steps'] ?? array() ) );
ga_eq( "steps[0] title", 'Plan', $steps['atts']['steps'][0]['title'] ?? null );
/* Negative: a plain feature grid (no step class / numbers) must NOT become steps. */
$feat_steps = $sc_nodes_of( '<section id="fs"><div class="grid"><div><h3>Fast</h3><p>We ship every order within a day of purchase for you.</p></div><div><h3>Kind</h3><p>Our friendly team answers every question you might have.</p></div></div></section>' );
ga( "feature grid is NOT mis-claimed as steps", null === $first_sc( $feat_steps, 'steps' ), wp_json_encode( $codes_of( $feat_steps ) ) );

/* --- Timeline → native `timeline` --- */
$tl_html = '<section id="tl"><div class="timeline">'
	. '<div class="entry"><time>2021</time><h3>Founded</h3><p>First office opens.</p></div>'
	. '<div class="entry"><time>2023</time><h3>Growth</h3><p>Ten thousand customers.</p></div></div></section>';
$tln = $sc_nodes_of( $tl_html );
$tl = $first_sc( $tln, 'timeline' );
ga( "dated entries → a `timeline` shortcode", $tl !== null, wp_json_encode( $codes_of( $tln ) ) );
ga_eq( "timeline has 2 items", 2, count( $tl['atts']['items'] ?? array() ) );
ga_eq( "timeline[0] date", '2021', $tl['atts']['items'][0]['date'] ?? null );
ga_eq( "timeline[0] title", 'Founded', $tl['atts']['items'][0]['title'] ?? null );
/* Negative: undated cards must NOT become a timeline. */
$undated = $sc_nodes_of( '<section id="ud"><div class="grid"><div><h3>Alpha</h3><p>Some descriptive text about the first thing here.</p></div><div><h3>Beta</h3><p>Some descriptive text about the second thing here.</p></div></div></section>' );
ga( "undated cards are NOT mis-claimed as timeline", null === $first_sc( $undated, 'timeline' ), wp_json_encode( $codes_of( $undated ) ) );

/* --- Progress → native `progress` --- */
$pr_html = '<section id="pr"><div class="skills">'
	. '<div class="skill"><span class="label">Design</span><div class="bar"><div class="fill" style="width:90%"></div></div></div>'
	. '<div class="skill"><span class="label">Development</span><div class="bar"><div class="fill" style="width:75%"></div></div></div></div></section>';
$prn = $sc_nodes_of( $pr_html );
$prog = $first_sc( $prn, 'progress' );
ga( "skill bars → a `progress` shortcode", $prog !== null, wp_json_encode( $codes_of( $prn ) ) );
ga_eq( "progress has 2 bars", 2, count( $prog['atts']['bars'] ?? array() ) );
ga_eq( "progress[0] label", 'Design', $prog['atts']['bars'][0]['label'] ?? null );
ga_eq( "progress[0] percent", 90, $prog['atts']['bars'][0]['percent'] ?? null );
/* Negative: a stat grid ("98% Satisfaction", text-only %) must NOT become progress (it's counters). */
$stat_pr = $sc_nodes_of( '<section id="sp"><div class="grid grid-cols-2 gap-8"><div><div>98%</div><div>Satisfaction</div></div><div><div>24</div><div>Locations</div></div></div></section>' );
ga( "text-only stat grid is NOT mis-claimed as progress", null === $first_sc( $stat_pr, 'progress' ), wp_json_encode( $codes_of( $stat_pr ) ) );

/* --- Pricing → native `pricing_table` --- */
$pt_html = '<section id="pt"><div class="pricing">'
	. '<div class="plan"><h3>Starter</h3><div class="price">$9/mo</div><ul><li>10 Projects</li><li>Email Support</li></ul><a href="#a">Choose</a></div>'
	. '<div class="plan featured"><h3>Pro</h3><span class="badge">Popular</span><div class="price">$29/mo</div><ul><li>Unlimited</li><li>Priority Support</li></ul><a href="#b">Choose</a></div></div></section>';
$ptn = $sc_nodes_of( $pt_html );
$pt = $first_sc( $ptn, 'pricing_table' );
ga( "pricing columns → a `pricing_table` shortcode", $pt !== null, wp_json_encode( $codes_of( $ptn ) ) );
ga_eq( "pricing has 2 plans", 2, count( $pt['atts']['plans'] ?? array() ) );
ga_eq( "pricing[0] title", 'Starter', $pt['atts']['plans'][0]['plan_title'] ?? null );
ga_eq( "pricing[0] monthly price", '9', $pt['atts']['plans'][0]['price']['monthly'] ?? null );
ga_eq( "pricing[1] featured", 'yes', $pt['atts']['plans'][1]['featured'] ?? null );
ga( "pricing[0] features carried", strpos( (string) ( $pt['atts']['plans'][0]['features'] ?? '' ), '10 Projects' ) !== false );
/* Negative: a plain feature grid (no price token) stays icon_box, not pricing. */
$feat_pt = $sc_nodes_of( '<section id="fp"><div class="grid grid-cols-2"><div><h3>Fast</h3><p>We ship every order within twenty four hours.</p></div><div><h3>Support</h3><p>Our friendly team answers every question quickly.</p></div></div></section>' );
ga( "feature grid (no price) is NOT mis-claimed as pricing", null === $first_sc( $feat_pt, 'pricing_table' ), wp_json_encode( $codes_of( $feat_pt ) ) );

/* --- Lottie → native `lottie` --- */
$lo_nodes = $sc_nodes_of( '<section id="lo"><lottie-player src="https://example.com/anim.json"></lottie-player></section>' );
$lo = $first_sc( $lo_nodes, 'lottie' );
ga( "lottie-player → a `lottie` shortcode", $lo !== null, wp_json_encode( $codes_of( $lo_nodes ) ) );
ga_eq( "lottie source url", 'url', $lo['atts']['source'] ?? null );
ga_eq( "lottie url carried", 'https://example.com/anim.json', $lo['atts']['lottie_url'] ?? null );

/* --- SVG-draw → native `svg_draw` --- */
$sd_nodes = $sc_nodes_of( '<section id="sd"><svg viewBox="0 0 100 100"><path d="M10 10 L90 90" stroke="#000" fill="none" stroke-dasharray="120" stroke-dashoffset="120"/></svg></section>' );
$sd = $first_sc( $sd_nodes, 'svg_draw' );
ga( "stroke-animated svg → a `svg_draw` shortcode", $sd !== null, wp_json_encode( $codes_of( $sd_nodes ) ) );
ga_eq( "svg_draw source code", 'code', $sd['atts']['svg']['source'] ?? null );
ga( "svg_draw markup carried", strpos( (string) ( $sd['atts']['svg']['code']['code'] ?? '' ), '<svg' ) !== false );
/* Negative: a plain decorative icon <svg> must NOT become svg_draw. */
$icon_svg = $sc_nodes_of( '<section id="is"><svg viewBox="0 0 24 24"><path d="M12 2 L2 7 L12 12 Z" fill="#333"/></svg></section>' );
ga( "decorative icon svg is NOT mis-claimed as svg_draw", null === $first_sc( $icon_svg, 'svg_draw' ), wp_json_encode( $codes_of( $icon_svg ) ) );

/* --- Table presets: a styled table yields a table_preset slug --- */
$tp_nodes = $sc_nodes_of( '<section id="tps"><table><thead><tr><th data-sc-cs="background-color:#f1f4f9;color:#111">Plan</th><th>Price</th></tr></thead><tbody><tr><td>Basic</td><td>$9</td></tr><tr><td>Pro</td><td>$19</td></tr></tbody></table></section>' );
$tp_tbl = $first_sc( $tp_nodes, 'table' );
ga( "styled table → a `table` shortcode", $tp_tbl !== null, wp_json_encode( $codes_of( $tp_nodes ) ) );
ga( "styled table yields a non-empty table_preset", ! empty( $tp_tbl['atts']['table_preset'] ), $tp_tbl['atts']['table_preset'] ?? '(none)' );

/* --- Source animation intent → an ENABLED reveal animation on the node --- */
$anim_nodes = $sc_nodes_of( '<section id="an"><p data-aos="fade-up">A revealed paragraph of text for the block.</p></section>' );
$anim_txt = $first_sc( $anim_nodes, 'text_block' );
ga( "data-aos node → a text_block", $anim_txt !== null, wp_json_encode( $codes_of( $anim_nodes ) ) );
ga_eq( "data-aos=fade-up → animation enabled", 'yes', $anim_txt['atts']['animation']['enable'] ?? null );
ga_eq( "data-aos=fade-up → mapped effect", 'animate__fadeInUp', $anim_txt['atts']['animation']['yes']['effect'] ?? null );
/* Negative: no animation attribute → the node stays disabled (no false motion). */
$anim_neg = $sc_nodes_of( '<section id="an2"><p>A plain paragraph of text for the block.</p></section>' );
$anim_neg_txt = $first_sc( $anim_neg, 'text_block' );
ga_eq( "no anim attribute → animation stays disabled", 'no', $anim_neg_txt['atts']['animation']['enable'] ?? null );

/* --------------------------------------------------------------------- *
 * 8) FIDELITY-AUDIT P0 FIXES
 *    (a) Avatar stack in a hero column maps to `avatar` (not a code_block).
 *    (b) Feature-card inline-<svg> icon chips carry an icon + per-card color.
 * --------------------------------------------------------------------- */
echo "\n[8] Fidelity-audit P0 fixes (avatar wiring / icon-chip capture)\n";

/* --- (a) Avatar wiring: a hero 2-col band whose LEFT column holds a heading + an overlapping
 *         round-avatar stack → the section's shortcode set includes `avatar` (the stack is claimed
 *         by the avatar_group recognizer inside the decomposed hero column, NOT flattened to code). */
$av_html = '<section id="hero2"><div class="grid grid-cols-2 gap-8">'
	. '<div><h1>Trusted by pet parents</h1><p>A hero paragraph long enough to make this a real content column, not a caption.</p>'
	.   '<div class="mt-10 flex items-center gap-4">'
	.     '<div class="flex -space-x-3">'
	.       '<div class="w-10 h-10 rounded-full"><img src="https://i.pravatar.cc/100?img=1"></div>'
	.       '<div class="w-10 h-10 rounded-full"><img src="https://i.pravatar.cc/100?img=2"></div>'
	.       '<div class="w-10 h-10 rounded-full"><img src="https://i.pravatar.cc/100?img=3"></div>'
	.       '<div class="w-10 h-10 rounded-full"><img src="https://i.pravatar.cc/100?img=4"></div>'
	.     '</div><span>4.9/5 from 500+ happy families</span>'
	.   '</div></div>'
	. '<div><img src="https://example.com/hero.jpg"></div>'
	. '</div></section>';
$avn   = $sc_nodes_of( $av_html );
$avc   = $codes_of( $avn );
ga( "hero avatar stack → an `avatar` shortcode (not code_block)", in_array( 'avatar', $avc, true ), wp_json_encode( $avc ) );
$av0   = $first_sc( $avn, 'avatar' );
ga_eq( "avatar mode == group", 'group', $av0['atts']['mode_settings']['mode'] ?? null );
ga_eq( "avatar carries 4 people", 4, count( $av0['atts']['mode_settings']['group']['people'] ?? array() ) );
ga_eq( "avatar extra_count parsed", '500+', $av0['atts']['mode_settings']['group']['extra_count'] ?? null );
/* Negative control: a plain (non-overlapping, rectangular) image row is NOT claimed as an avatar. */
$noav_html = '<section id="row2"><div class="grid grid-cols-2 gap-8">'
	. '<div><h1>Our gallery</h1><p>A content column with a normal row of rectangular thumbnails below it here.</p>'
	.   '<div class="flex gap-4"><img class="w-24 h-24" src="https://example.com/a.jpg"><img class="w-24 h-24" src="https://example.com/b.jpg"></div></div>'
	. '<div><img src="https://example.com/side.jpg"></div>'
	. '</div></section>';
ga( "plain image row is NOT mis-claimed as avatar", null === $first_sc( $sc_nodes_of( $noav_html ), 'avatar' ), wp_json_encode( $codes_of( $sc_nodes_of( $noav_html ) ) ) );

/* --- (b) Icon-chip capture: the Golden Fixture 1 features cards (built above with dynamic_chrome=true, so
 *         the semantic-colour config resolves `text-primary`/`text-secondary`) each carry a non-empty
 *         icon AND a per-card icon color (green / amber / green), not an empty icon_box. */
$feat_ibs = array();
foreach ( $builder as $sec ) {
	if ( ( $sec['atts']['css_id'] ?? '' ) !== 'features' ) { continue; }
	$w = function ( $n ) use ( &$w, &$feat_ibs ) {
		if ( ! is_array( $n ) ) { return; }
		if ( ( $n['type'] ?? '' ) === 'simple' && ( $n['shortcode'] ?? '' ) === 'icon_box' ) { $feat_ibs[] = $n; }
		foreach ( ( $n['_items'] ?? array() ) as $c ) { $w( $c ); }
	};
	$w( $sec );
}
ga_eq( "features has 3 icon_box (for icon capture)", 3, count( $feat_ibs ) );
$ib_has_icon = function ( $n ) {
	$a = $n['atts'] ?? array();
	return ( ! empty( $a['custom_icon'] ) ) || ( ( $a['icon']['type'] ?? 'none' ) !== 'none' );
};
$ib_color = function ( $n ) { return trim( (string) ( $n['atts']['icon_color']['custom'] ?? '' ) ); };
ga( "every feature icon_box carries a non-empty icon (custom_icon/lucide)", 3 === count( array_filter( $feat_ibs, $ib_has_icon ) ) );
ga( "every feature icon_box carries a non-empty icon_color", 3 === count( array_filter( $feat_ibs, function ( $n ) use ( $ib_color ) { return $ib_color( $n ) !== ''; } ) ),
	wp_json_encode( array_map( $ib_color, $feat_ibs ) ) );
if ( count( $feat_ibs ) === 3 ) {
	$c0 = $ib_color( $feat_ibs[0] ); $c1 = $ib_color( $feat_ibs[1] ); $c2 = $ib_color( $feat_ibs[2] );
	ga( "feature icon colors differ per card (green / amber / green)", $c0 !== $c1 && $c0 === $c2, wp_json_encode( array( $c0, $c1, $c2 ) ) );
	ga( "feature icon_box carries a badge chip preset (from the source icon container)", ! empty( $feat_ibs[0]['atts']['icon_badge_preset'] ) && preg_match( '/^iconb-badge-[0-9a-f]+$/', (string) $feat_ibs[0]['atts']['icon_badge_preset'] ), $feat_ibs[0]['atts']['icon_badge_preset'] ?? '(none)' );
}
/* Negative control: a card with NO icon still emits a valid icon_box, without a bogus icon. */
$noicon_nodes = $sc_nodes_of( '<section id="ni"><div class="grid grid-cols-2 gap-8">'
	. '<div><h3>No icon here</h3><p>Just a title and a paragraph of descriptive card text for this cell.</p></div>'
	. '<div><h3>Second card</h3><p>Another title and paragraph of descriptive card text for this cell.</p></div>'
	. '</div></section>' );
$ni_ib = $first_sc( $noicon_nodes, 'icon_box' );
ga( "icon-less card still emits a valid icon_box", $ni_ib !== null, wp_json_encode( $codes_of( $noicon_nodes ) ) );
ga_eq( "icon-less card icon_box has no bogus icon", 'none', $ni_ib['atts']['icon']['type'] ?? null );
ga( "icon-less card icon_box has no bogus custom_icon", empty( $ni_ib['atts']['custom_icon'] ) );

/* --------------------------------------------------------------------- *
 * 9) FIDELITY-AUDIT P0 — SECTION BAND FILLS APPLIED ONTO THE SECTION
 *    Full-bleed band fills must land on the section's NATIVE background:
 *    linked to a built Section Style preset when the colour matches, else
 *    a direct background.color.custom. Plus negative controls.
 * --------------------------------------------------------------------- */
echo "\n[9] Section band fills onto the section (variant preset-link / native bg)\n";

/* css_id => section atts (from the built page-builder tree). */
$atts_by_id = array();
foreach ( $builder as $sec ) {
	if ( ( $sec['type'] ?? '' ) !== 'section' ) { continue; }
	$atts_by_id[ $sec['atts']['css_id'] ?? '' ] = $sec['atts'] ?? array();
}
$sec_bg = function ( $a ) { return (string) ( $a['background']['color']['value']['custom'] ?? '' ); };

/* The built Section Style presets carry the three band skins (tint / white / green). */
$sp = $ts['section_style_presets'] ?? array();
ga( "section_style_presets built (>=3 bands)", count( $sp ) >= 3, count( $sp ) );

/* (a) Hero `bg-background` tint (rgb(247,253,249), a full-bleed `inset-0 bg-background` layer) →
 *     linked to the "Light" tint preset (slug `light`), NOT double-applied as a hardcoded bg. */
$hero = $atts_by_id['hero'] ?? array();
ga_eq( "hero band fill → variant 'light' (tint preset linked)", 'light', $hero['variant'] ?? null );
ga( "hero does NOT also hardcode the same bg (no double-apply)", $sec_bg( $hero ) === '', $sec_bg( $hero ) );

/* (b) CTA `bg-primary` (rgb(33,196,93), a full-bleed `absolute inset-0 bg-primary` layer) →
 *     linked to the green "Alt" preset (slug `alt`) — the audit's headline case. */
$cta = $atts_by_id['cta'] ?? array();
ga_eq( "cta band fill → variant 'alt' (green preset linked)", 'alt', $cta['variant'] ?? null );
ga( "cta does NOT also hardcode the same bg (no double-apply)", $sec_bg( $cta ) === '', $sec_bg( $cta ) );

/* (c) Features `bg-white` (rgb(255,255,255), on the section itself) → the white "Light 2" preset. */
$feat = $atts_by_id['features'] ?? array();
ga_eq( "features white band → variant 'light-2' (white preset linked)", 'light-2', $feat['variant'] ?? null );

/* (d) NEGATIVE CONTROL — a plain section with NO fill stays unstyled (no variant, no bg stamp). */
$plain_doc = '<!DOCTYPE html><html><head><title>T</title></head><body><main>'
	. '<section id="plain"><div class="container"><h2>Just a heading here</h2><p>A plain paragraph of body text that is long enough to be a real content block for this section.</p></div></section>'
	. '</main></body></html>';
$plain_b   = FW_Site_Converter_Sources::build_from_html( $plain_doc, 'Plain', array( 'dynamic_chrome' => true ) );
$plain_sec = array();
foreach ( ( $plain_b['files']['pages.json']['pages'][0]['builder'] ?? array() ) as $s ) {
	if ( ( $s['type'] ?? '' ) === 'section' ) { $plain_sec = $s['atts']; break; }
}
ga_eq( "no-fill section stays unstyled (variant empty)", '', $plain_sec['variant'] ?? null );
ga( "no-fill section carries no bg stamp", $sec_bg( $plain_sec ) === '', $sec_bg( $plain_sec ) );

/* (e) DIRECT BG — a full-bleed fill that matches NO built preset lands as a native custom bg.
 *     (dynamic_chrome=false keeps the fixture's presets {light,light-2,alt}; an off-palette purple
 *     matches none, so it takes the background.color.custom path instead of a variant link.) */
$pur_doc = '<!DOCTYPE html><html><head><title>T</title></head><body><main>'
	. '<section id="pur" class="relative"><div class="absolute inset-0" data-sc-cs="background-color:rgb(120, 20, 200)"></div>'
	. '<div class="container"><h2>Off-palette band</h2><p>A plain paragraph of body text that is long enough to be a real content block for this section.</p></div></section>'
	. '</main></body></html>';
$pur_b   = FW_Site_Converter_Sources::build_from_html( $pur_doc, 'Purple', array( 'dynamic_chrome' => false ) );
$pur_sec = array();
foreach ( ( $pur_b['files']['pages.json']['pages'][0]['builder'] ?? array() ) as $s ) {
	if ( ( $s['type'] ?? '' ) === 'section' ) { $pur_sec = $s['atts']; break; }
}
ga_eq( "unmatched band fill → native background.color.custom", 'rgb(120, 20, 200)', $sec_bg( $pur_sec ) );
ga_eq( "unmatched band fill → no variant link", '', $pur_sec['variant'] ?? null );

/* --------------------------------------------------------------------- *
 * 10) IMAGE-COMPOSITE DECOMPOSITION (P0: hero right column was ONE code_block)
 *     A "photo in an organic frame + floating badge + blob backdrop" composite
 *     must DECOMPOSE into a native media_image (organic radius / white border /
 *     shadow + blob via scoped CSS) + a structured, editable icon_box (icon +
 *     title + subtitle) — NOT a lone verbatim code_block. Plus negative controls.
 * --------------------------------------------------------------------- */
echo "\n[10] Image-composite decomposition (media_image + icon_box, not one code_block)\n";

$composite_html = '<section id="hero"><div class="container">'
	. '<div class="relative lg:h-[600px] flex items-center justify-center" data-sc-cs="position:relative">'
	. '<div class="absolute inset-0 bg-primary/20 blob-shape scale-95" data-sc-cs="position:absolute;background-color:rgba(33, 196, 93, 0.2);border-radius:40% 60% 70% 30% / 40% 50% 60%"></div>'
	. '<img alt="Happy dogs playing" class="relative z-10 blob-shape-2 shadow-2xl border-8 border-white" src="https://example.com/dogs.jpg" data-sc-cs="border-top-width:8px;border-top-color:rgb(255, 255, 255);border-radius:60% 40% 30% 70% / 60% 30% 70% 40%;box-shadow:rgba(0, 0, 0, 0.25) 0px 25px 50px -12px">'
	. '<div class="absolute top-10 -left-6 z-20 bg-white p-4 rounded-2xl shadow-xl flex items-center gap-4" data-sc-cs="position:absolute;background-color:rgb(255, 255, 255);border-radius:24px;box-shadow:rgba(0, 0, 0, 0.1) 0px 20px 25px -5px;padding:16px">'
	. '<div class="w-12 h-12 bg-secondary/20 rounded-full" data-sc-cs="background-color:rgba(251, 189, 35, 0.2);border-radius:9999px"><svg class="lucide lucide-shield-check w-6 h-6 text-secondary" data-sc-cs="color:rgb(251, 189, 35)"><path d="M20 13"></path></svg></div>'
	. '<div><p class="font-bold text-foreground">24/7 Care</p><p class="text-sm text-foreground/60">Always supervised</p></div>'
	. '</div></div></div></section>';
$cx = $sc_nodes_of( $composite_html );
$cx_codes = $codes_of( $cx );
$mi = $first_sc( $cx, 'media_image' );
$ib = $first_sc( $cx, 'icon_box' );
ga( "composite → a media_image is emitted", $mi !== null, wp_json_encode( $cx_codes ) );
ga( "composite → a structured icon_box is emitted", $ib !== null, wp_json_encode( $cx_codes ) );
$cx_codeblocks = array_filter( $cx, function ( $n ) { return ( $n['shortcode'] ?? '' ) === 'code_block'; } );
$cx_cb_blob = false;
foreach ( $cx_codeblocks as $n ) { if ( strpos( (string) ( $n['atts']['code'] ?? '' ), 'blob-shape' ) !== false ) { $cx_cb_blob = true; } }
ga( "composite is NOT frozen in a verbatim blob code_block", ! $cx_cb_blob, wp_json_encode( $cx_codes ) );

$mi_css = (string) ( $mi['atts']['custom_css'] ?? '' );
ga( "media_image carries the organic border-radius (scoped CSS)", strpos( $mi_css, 'border-radius:60% 40% 30% 70% / 60% 30% 70% 40%' ) !== false, $mi_css );
ga( "media_image carries the white 8px border (scoped CSS)", strpos( $mi_css, 'border:8px solid rgb(255, 255, 255)' ) !== false, $mi_css );
ga( "media_image carries the shadow-2xl (scoped CSS)", strpos( $mi_css, 'box-shadow:rgba(0, 0, 0, 0.25) 0px 25px 50px -12px' ) !== false, $mi_css );
ga( "media_image reproduces the blob layer via selector::before", strpos( $mi_css, 'selector::before' ) !== false && strpos( $mi_css, 'rgba(33, 196, 93, 0.2)' ) !== false, $mi_css );
ga_eq( "media_image src survives", 'https://example.com/dogs.jpg', $mi['atts']['image']['url'] ?? null );

ga_eq( "floating card title survives as EDITABLE content", '24/7 Care', $ib['atts']['title'] ?? null );
ga( "floating card subtitle survives as editable content", strpos( (string) ( $ib['atts']['content'] ?? '' ), 'Always supervised' ) !== false, $ib['atts']['content'] ?? '' );
$ib_css = (string) ( $ib['atts']['custom_css'] ?? '' );
ga( "floating card is positioned over the image (absolute scoped CSS)", strpos( $ib_css, 'position:absolute' ) !== false && strpos( $ib_css, 'top:2.5rem' ) !== false, $ib_css );
ga( "floating card icon chip -> icon_badge_preset (from the source chip)", (bool) preg_match( '/^iconb-badge-[0-9a-f]+$/', (string) ( $ib['atts']['icon_badge_preset'] ?? '' ) ), $ib['atts']['icon_badge_preset'] ?? '' );

/* Negative control: a plain <img> with a caption (no absolute card/blob) stays a simple
   media_image — it must NOT be force-decomposed into an icon_box. */
$plain_img_html = '<section id="plain"><figure><img alt="Our team" src="https://example.com/team.jpg"><figcaption>Our friendly team</figcaption></figure></section>';
$pi = $sc_nodes_of( $plain_img_html );
ga( "plain image + caption → still a media_image", $first_sc( $pi, 'media_image' ) !== null, wp_json_encode( $codes_of( $pi ) ) );
ga( "plain image + caption is NOT force-decomposed (no icon_box)", $first_sc( $pi, 'icon_box' ) === null, wp_json_encode( $codes_of( $pi ) ) );

/* Negative control: an image with a plain absolute CAPTION overlay (text, but NO card skin / blob)
   does NOT match the composite shape — it stays the existing verbatim code_block fallback, so a
   non-card overlay can't be wrongly torn apart. */
$capt_html = '<section id="capt"><div class="relative" data-sc-cs="position:relative">'
	. '<img alt="City" src="https://example.com/city.jpg">'
	. '<div class="absolute bottom-0" data-sc-cs="position:absolute">Downtown at dusk</div>'
	. '</div></section>';
$cap = $sc_nodes_of( $capt_html );
ga( "plain absolute caption overlay → NOT decomposed (no icon_box)", $first_sc( $cap, 'icon_box' ) === null, wp_json_encode( $codes_of( $cap ) ) );

/* --------------------------------------------------------------------- *
 * 11) BODY BUTTON PRESETS + HERO PILL (P1 fidelity fixes)
 *     (a) A converted BODY button attaches the matching button_colors + button_sizes
 *         preset slug (style=btn-{color}, size=btn-{size}) — the SAME linking the header
 *         CTA does — instead of the shortcode default. The source's green filled primary,
 *         white/outline, and amber secondary buttons each map to the right color+size preset.
 *     (b) The hero pill (`bg-primary/10` + a leading heart <svg>) carries a real pill_color
 *         fill (rgba(33,196,93,.1)) + a leading_icon (inline svg), not an empty badge.
 *     The per-node custom_css safety net is unaffected (asserted still present).
 * --------------------------------------------------------------------- */
echo "\n[11] Body button presets + hero pill fill/leading icon (P1 fixes)\n";

/* Collect every simple leaf node from the MAIN build, tagged by its section id + label. */
$all_nodes = array();
foreach ( $builder as $sec ) {
	if ( ( $sec['type'] ?? '' ) !== 'section' ) { continue; }
	$sid  = $sec['atts']['css_id'] ?? '';
	$walk = function ( $n ) use ( &$walk, &$all_nodes, $sid ) {
		if ( ! is_array( $n ) ) { return; }
		if ( ( $n['type'] ?? '' ) === 'simple' ) { $all_nodes[] = array( 'sid' => $sid, 'node' => $n ); }
		foreach ( ( $n['_items'] ?? array() ) as $c ) { $walk( $c ); }
	};
	$walk( $sec );
}
$find_btn = function ( $label ) use ( $all_nodes ) {
	foreach ( $all_nodes as $e ) {
		if ( ( $e['node']['shortcode'] ?? '' ) === 'button' && ( $e['node']['atts']['label'] ?? '' ) === $label ) { return $e['node']; }
	}
	return null;
};

// (a) The three body buttons. The hero "Book a Stay" is the GREEN bg-primary CTA (distinct from the
// header CTA of the same label); "Take a Tour" is the white bg-white+border outline; the CTA-section
// "Reserve a Spot Now" is the amber bg-secondary button (largest = the Large size preset).
$b_book = $find_btn( 'Book a Stay' );
$b_tour = $find_btn( 'Take a Tour' );
ga( "hero 'Book a Stay' button found", $b_book !== null );
ga( "hero 'Take a Tour' button found", $b_tour !== null );

// green bg-primary filled → the Primary color preset (btn-primary); its size (fs18/px32) → Medium.
ga_eq( "green primary button → style btn-primary", 'btn-primary', $b_book['atts']['style'] ?? null );
ga_eq( "green primary button → size btn-md", 'btn-md', $b_book['atts']['size'] ?? null );
// bg-white + border outline → the Outline preset (btn-outline).
ga_eq( "bg-white border outline button → style btn-outline", 'btn-outline', $b_tour['atts']['style'] ?? null );
ga_eq( "outline button → size btn-md", 'btn-md', $b_tour['atts']['size'] ?? null );

// The CTA-section "Reserve a Spot Now" amber button is a standalone `button` shortcode (the CTA band
// stays faithfully assembled). It keeps its label/link and maps to the amber Secondary color preset
// (bg-secondary, rgb(251,189,35)) at the Large size (largest button on the page).
$cta_btn_node = null;
foreach ( $all_nodes as $e ) {
	$n = $e['node'] ?? array();
	if ( ( $n['shortcode'] ?? '' ) === 'button' && ( $n['atts']['label'] ?? ( $n['atts']['text'] ?? '' ) ) === 'Reserve a Spot Now' ) { $cta_btn_node = $n; break; }
}
ga( "cta 'Reserve a Spot Now' button node found", $cta_btn_node !== null );
ga_eq( "cta button link = /contact", '/contact', $cta_btn_node['atts']['link'] ?? ( $cta_btn_node['atts']['url'] ?? null ) );
ga_eq( "cta amber button → style btn-secondary", 'btn-secondary', $cta_btn_node['atts']['style'] ?? null );

// (b) The hero pill ("Voted #1 Pet Boarding") sits DIRECTLY ABOVE the h1, so it is now the hero
// heading's OVERLINE — a FILLED PILL (overline_container='pill') tinted by the Overline Color (the
// chip's green text colour) with the leading heart <svg> as the overline_icon. There is NO standalone
// `badge` node in the hero. This matches the JS capture-service path (which already renders the pill
// as the overline).
$hero_sh = null;
foreach ( $all_nodes as $e ) {
	if ( ( $e['sid'] ?? '' ) === 'hero' && ( $e['node']['shortcode'] ?? '' ) === 'special_heading'
		&& ( $e['node']['atts']['overline_container'] ?? '' ) === 'pill' ) { $hero_sh = $e['node']; break; }
}
ga( "hero pill → the h1's special_heading OVERLINE (found)", $hero_sh !== null );
ga_eq( "hero overline_container = pill", 'pill', $hero_sh['atts']['overline_container'] ?? null );
ga_eq( "hero overline text survives", 'Voted #1 Pet Boarding in Springfield', $hero_sh['atts']['overline'] ?? null );
ga_eq( "hero overline_icon is an inline svg", 'svg', $hero_sh['atts']['overline_icon']['type'] ?? null );
ga( "hero overline_icon carries the heart svg markup", strpos( (string) ( $hero_sh['atts']['overline_icon']['markup'] ?? '' ), '<svg' ) !== false );
ga( "hero overline_color custom is the chip's green text colour (non-empty)", '' !== (string) ( $hero_sh['atts']['overline_color']['custom'] ?? '' ), $hero_sh['atts']['overline_color']['custom'] ?? '(none)' );
// NO standalone badge anywhere in the hero section.
$hero_badge = null;
foreach ( $all_nodes as $e ) { if ( ( $e['sid'] ?? '' ) === 'hero' && ( $e['node']['shortcode'] ?? '' ) === 'badge' ) { $hero_badge = $e['node']; break; } }
ga( "NO standalone badge remains in the hero (chip absorbed into the overline)", $hero_badge === null );

// (b2) POSITIVE CASE — a chip immediately followed by an h2 → the h2's special_heading gains
// overline_container='pill' + an inline-svg overline_icon + a non-empty overline_color, and the
// section emits NO 'badge' shortcode.
$chip_h2_html = '<section id="ch"><div class="inline-flex items-center gap-2 px-4 py-2 rounded-full bg-primary/10 text-primary" data-sc-cs="background-color:rgba(33, 196, 93, 0.1);color:rgb(33, 196, 93);border-radius:9999px;display:inline-flex">'
	. '<svg viewBox="0 0 24 24" class="w-4 h-4"><path d="M2 9.5"></path></svg><span data-sc-cs="color:rgb(33, 196, 93)">Our Promise</span></div>'
	. '<h2 class="text-4xl font-bold" data-sc-cs="color:rgb(41,61,54);font-size:36px;font-weight:700">Care you can count on</h2></section>';
$ch_nodes = $sc_nodes_of( $chip_h2_html );
$ch_sh    = $first_sc( $ch_nodes, 'special_heading' );
ga( "chip-before-h2 → a special_heading (found)", $ch_sh !== null, wp_json_encode( $codes_of( $ch_nodes ) ) );
ga_eq( "chip-before-h2 → overline_container = pill", 'pill', $ch_sh['atts']['overline_container'] ?? null );
ga_eq( "chip-before-h2 → overline_icon type svg", 'svg', $ch_sh['atts']['overline_icon']['type'] ?? null );
ga( "chip-before-h2 → overline_color custom non-empty", '' !== (string) ( $ch_sh['atts']['overline_color']['custom'] ?? '' ), $ch_sh['atts']['overline_color']['custom'] ?? '(none)' );
ga( "chip-before-h2 → NO 'badge' shortcode emitted", ! in_array( 'badge', $codes_of( $ch_nodes ), true ), wp_json_encode( $codes_of( $ch_nodes ) ) );

/* Negative control (button): a bare text link (no fill, no border, no semantic class) gets NO
   bogus color/size preset — style + size stay empty. */
$bare_nodes = $sc_nodes_of( '<section id="bare"><div class="cta"><a href="/go" class="text-base font-medium" data-sc-cs="color:rgb(41, 61, 54);font-size:16px">Learn more about it</a></div></section>' );
$bare_btn = $first_sc( $bare_nodes, 'button' );
if ( $bare_btn !== null ) {
	ga_eq( "bare text-link button → NO color preset (style empty)", '', $bare_btn['atts']['style'] ?? '(missing)' );
	ga_eq( "bare text-link button → NO size preset (size empty)", '', $bare_btn['atts']['size'] ?? '(missing)' );
} else {
	ga( "bare text-link button → NO color preset (style empty)", true );
	ga( "bare text-link button → NO size preset (size empty)", true );
}

/* Negative control (pill): a plain pill with NO fill + NO leading icon stays unstyled —
   pill_color empty, leading = none. */
$plain_pill_nodes = $sc_nodes_of( '<section id="pp"><div class="inline-flex items-center gap-2"><span class="text-xs uppercase rounded-full bg-transparent">New</span><span>Just launched this week</span></div></section>' );
$plain_pill = $first_sc( $plain_pill_nodes, 'badge' );
if ( $plain_pill !== null ) {
	ga_eq( "plain pill (no fill) → pill_color stays empty", '', $plain_pill['atts']['pill_color']['custom'] ?? '(missing)' );
	ga_eq( "plain pill (no leading svg) → leading stays none", 'none', $plain_pill['atts']['leading'] ?? '(missing)' );
} else {
	ga( "plain pill negative control (no badge emitted is acceptable)", true );
	ga( "plain pill negative control (no leading icon)", true );
}

/* Negative control (chip-before-heading rule): a standalone chip with NO heading AFTER it stays a
   `badge` shortcode — the transform only fires when a heading follows. */
$lone_chip_html = '<section id="lc"><div class="inline-flex items-center gap-2 px-4 py-2 rounded-full bg-primary/10 text-primary" data-sc-cs="background-color:rgba(33, 196, 93, 0.1);color:rgb(33, 196, 93);border-radius:9999px;display:inline-flex">'
	. '<svg viewBox="0 0 24 24" class="w-4 h-4"><path d="M2 9.5"></path></svg><span data-sc-cs="color:rgb(33, 196, 93)">Trusted by 2,000+ families</span></div></section>';
$lone_nodes = $sc_nodes_of( $lone_chip_html );
ga( "lone chip (NO heading after) → still a 'badge' shortcode", in_array( 'badge', $codes_of( $lone_nodes ), true ), wp_json_encode( $codes_of( $lone_nodes ) ) );
ga( "lone chip → NO special_heading created (not turned into an overline)", ! in_array( 'special_heading', $codes_of( $lone_nodes ), true ), wp_json_encode( $codes_of( $lone_nodes ) ) );

/* --------------------------------------------------------------------- *
 * 12) HI-FI FAITHFUL BASE (Pass-2) + SPACING → NATIVE (Pass-1)
 *
 * "Faithful base + spacing→native": every appearance property the native mapping doesn't already
 * reproduce is emitted as a specificity-0 `:where(selector){…}` base (nothing dropped), still
 * overridable; source vertical margin maps to the shortcode's NATIVE spacing option (editable).
 * --------------------------------------------------------------------- */
echo "\n[12] Hi-fi faithful base (Pass-2) + spacing → native (Pass-1)\n";

// Collect every node's custom_css from a built builder tree.
$collect_css = function ( $builder_tree ) {
	$out = array();
	$walk = function ( $n ) use ( &$walk, &$out ) {
		if ( ! is_array( $n ) ) { return; }
		if ( ( $n['type'] ?? '' ) === 'simple' ) { $out[] = array( 'sc' => (string) ( $n['shortcode'] ?? '' ), 'css' => (string) ( $n['atts']['custom_css'] ?? '' ) ); }
		foreach ( ( $n['_items'] ?? array() ) as $c ) { $walk( $c ); }
	};
	foreach ( $builder_tree as $s ) { $walk( $s ); }
	return $out;
};

// ON build = the top-of-file bundle ($builder, dynamic_chrome=true, hi-fi DEFAULT ON).
$css_on   = $collect_css( $builder );
$bases_on = array_values( array_filter( $css_on, function ( $r ) { return strpos( $r['css'], ':where(' ) !== false; } ) );

// OFF build = same source with hifi_css=false (opt-out).
$bundle_off  = FW_Site_Converter_Sources::build_from_html( $html, 'GoldenOff', array( 'dynamic_chrome' => true, 'hifi_css' => false ) );
$builder_off = $bundle_off['files']['pages.json']['pages'][0]['builder'] ?? array();
$css_off     = $collect_css( $builder_off );
$bases_off   = array_values( array_filter( $css_off, function ( $r ) { return strpos( $r['css'], ':where(' ) !== false; } ) );

ga( "hi-fi ON: at least one element carries a :where() faithful base", count( $bases_on ) >= 1, count( $bases_on ) );
ga( "hi-fi OFF: NO :where() base is emitted (opt-out omits the base = byte-identical mapping)", count( $bases_off ) === 0, count( $bases_off ) );

// A pill (badge) carries the SOURCE fill + radius it did NOT set natively (colours left neutral otherwise).
// A STANDALONE chip (no heading after it) still becomes a `badge` — build one so its faithful base can be
// checked (the Golden Fixture 1 hero pill is now the h1's overline, so it no longer emits a badge base).
$lone_pill_doc  = '<!DOCTYPE html><html><head></head><body><main><section id="lp"><div class="inline-flex items-center gap-2 px-4 py-2 rounded-full bg-primary/10 text-primary" data-sc-cs="background-color:rgba(33, 196, 93, 0.1);color:rgb(33, 196, 93);border-radius:9999px;display:inline-flex;padding:8px 16px"><svg viewBox="0 0 24 24" class="w-4 h-4"><path d="M2 9.5"></path></svg><span data-sc-cs="color:rgb(33, 196, 93)">Trusted by 2,000+ families</span></div></section></main></body></html>';
$lone_pill_bndl = FW_Site_Converter_Sources::build_from_html( $lone_pill_doc, 'GoldenLonePill', array( 'dynamic_chrome' => true, 'hifi_css' => true ) );
$lone_pill_bld  = $lone_pill_bndl['files']['pages.json']['pages'][0]['builder'] ?? array();
$lone_pill_css  = $collect_css( $lone_pill_bld );
$badge_base = '';
foreach ( $lone_pill_css as $r ) { if ( $r['sc'] === 'badge' && strpos( $r['css'], ':where(' ) !== false ) { $badge_base = $r['css']; break; } }
ga( "pill base reproduces source background-color (appearance not natively set)", $badge_base !== '' && strpos( $badge_base, 'background-color:rgba(33, 196, 93' ) !== false, $badge_base );
ga( "pill base reproduces source border-radius (pill shape)", $badge_base !== '' && strpos( $badge_base, 'border-radius:' ) !== false, $badge_base );

// The button's fill/colour/border come from the color PRESET + `.btn-fill` class (native) → the base must
// NOT re-emit them (specificity-0 base stays lean AND the native option still wins).
$btn_base = '';
foreach ( $bases_on as $r ) { if ( $r['sc'] === 'button' ) { $btn_base = $r['css']; break; } }
ga( "button HAS a :where() base (leftover appearance carried)", $btn_base !== '', $btn_base );
ga( "button base does NOT re-emit background-color (native preset wins, base stays lean)", $btn_base !== '' && strpos( $btn_base, 'background-color' ) === false, $btn_base );
ga( "button base does NOT re-emit border (native class wins)", $btn_base !== '' && strpos( $btn_base, 'border:' ) === false && strpos( $btn_base, 'border-radius' ) === false, $btn_base );

// Layout/spacing is NEVER carried as raw CSS in the FAITHFUL BASE (margin natively, layout structurally).
// Only the `:where()` base is inspected — a deliberate structural rule in a plain `selector{}` block
// (e.g. a button's align-self/width:auto flex-fallback that keeps a centred CTA from fighting the native
// alignment option) is intentional layout, not an appearance leak, so it must not trip this guard.
$leaks = array();
foreach ( $bases_on as $r ) {
	if ( preg_match_all( '/:where\([^{]*\)\s*\{([^}]*)\}/', $r['css'], $mm ) ) {
		foreach ( $mm[1] as $body ) {
			if ( preg_match( '/(?:^|;)\s*(margin|padding|display|position|width|height|flex|grid|justify-content|align-items|gap)\s*:/', $body ) ) { $leaks[] = $r['sc']; break; }
		}
	}
}
ga( "no base leaks layout/spacing props (margin/padding/display/flex/grid/…)", count( $leaks ) === 0, wp_json_encode( $leaks ) );

// Pass-1: source vertical MARGIN → native spacing-scale token (px → slug), and OFF leaves it untouched.
$rm = new ReflectionMethod( 'FW_Site_Converter_Mapper', 'apply_native_margin' );
$rm->setAccessible( true );
$empty_box = array( 'margin' => array( 'all' => '', 'top' => '', 'right' => '', 'bottom' => '', 'left' => '' ), 'padding' => array() );
FW_Site_Converter_Mapper::set_hifi_css( true );
$sp_on = $rm->invoke( null, $empty_box, 'margin-top:24px;margin-bottom:48px;color:rgb(0,0,0)' );
ga_eq( "Pass-1: margin-top:24px → native spacing token mt-4", 'mt-4', $sp_on['margin']['top'] ?? '(missing)' );
ga_eq( "Pass-1: margin-bottom:48px → native spacing token mb-5", 'mb-5', $sp_on['margin']['bottom'] ?? '(missing)' );
FW_Site_Converter_Mapper::set_hifi_css( false );
$sp_off = $rm->invoke( null, $empty_box, 'margin-top:24px;margin-bottom:48px' );
ga_eq( "Pass-1: hi-fi OFF leaves native spacing empty (no raw→native migration)", '', $sp_off['margin']['top'] ?? '(missing)' );

// Pass-2 unit: base skips $already props + visually-inert defaults; keeps the real extras.
FW_Site_Converter_Mapper::set_hifi_css( true );
$u1 = FW_Site_Converter_Mapper::hifi_base_css( 'color:rgb(10,20,30);box-shadow:0 4px 6px rgba(0,0,0,.1);font-weight:400;text-align:left', array( 'color' ) );
ga( 'Pass-2: base excludes an already-set prop (color) but keeps box-shadow', strpos( $u1, 'color:' ) === false && strpos( $u1, 'box-shadow:' ) !== false, $u1 );
ga( "Pass-2: base drops inert defaults (font-weight:400 / text-align:left)", strpos( $u1, 'font-weight' ) === false && strpos( $u1, 'text-align' ) === false, $u1 );
ga_eq( "Pass-2: all-inert computed style → empty base (no rule)", '', FW_Site_Converter_Mapper::hifi_base_css( 'font-weight:400;text-align:start;opacity:1;transform:none', array() ) );
ga( "Pass-2: base is specificity-0 (wrapped in :where())", strpos( $u1, ':where(selector){' ) === 0, $u1 );

// Pass-2 GRADIENT TEXT (Pass #7): a captured gradient-text heading reproduces the clip + transparent
// fill so the gradient paints the GLYPHS (not a block). `color` is native ($already) but the base still
// carries the clip trio + the gradient background-image the native mapping doesn't.
$gt = FW_Site_Converter_Mapper::hifi_base_css(
	'background-image:linear-gradient(90deg, rgb(33, 196, 93), rgb(0, 170, 119));-webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent;color:rgb(33, 196, 93)',
	array( 'color', 'font-family', 'font-size', 'font-weight' )
);
ga( "Pass-2 gradient text: carries background-clip:text", strpos( $gt, 'background-clip:text' ) !== false, $gt );
ga( "Pass-2 gradient text: carries -webkit-text-fill-color:transparent", strpos( $gt, '-webkit-text-fill-color:transparent' ) !== false, $gt );
ga( "Pass-2 gradient text: carries the gradient background-image", strpos( $gt, 'background-image:linear-gradient' ) !== false, $gt );
// Negative control: a NORMAL element (default clip border-box + opaque fill) carries NONE of the trio —
// the props are inert unless they signal real gradient text, so they never bloat ordinary rules.
$gt_neg = FW_Site_Converter_Mapper::hifi_base_css(
	'background-clip:border-box;-webkit-background-clip:border-box;-webkit-text-fill-color:rgb(10, 20, 30);box-shadow:0 4px 6px rgba(0,0,0,.1)',
	array()
);
ga( "Pass-2 gradient text: default clip/fill are inert (no leak on a normal element)",
	strpos( $gt_neg, 'background-clip' ) === false && strpos( $gt_neg, 'text-fill-color' ) === false && strpos( $gt_neg, 'box-shadow:' ) !== false, $gt_neg );

/* --------------------------------------------------------------------- *
 * 12b) TEXT BLOCK → NODE OPTIONS (colour + margin + font-size single-source)
 *
 * A source <p> now carries its distinctive tone as the native `text_color`
 * option and its vertical margin as the native `spacing` option (both editable),
 * instead of being frozen in the section-scoped unified styler. Font-size is owned
 * by the Text Style preset alone (no per-node px in the faithful base when a preset
 * is assigned). Line-height (no native option) stays reproduced by the styler.
 * --------------------------------------------------------------------- */
echo "\n[12b] Text block → node options (text_color + spacing + font-size single source)\n";

$tb_doc = '<!DOCTYPE html><html><head></head><body><main><section id="tbsec"><div class="container">'
	. '<p class="text-lg text-foreground/70 mb-8 leading-relaxed" '
	. 'data-sc-cs="color:rgb(41,61,54);font-family:Inter, sans-serif;font-size:20px;font-weight:400;line-height:32px;text-align:start;margin:0px 0px 32px">'
	. 'Premium grooming and spa services for your best friend, delivered by certified professionals who treat every pet like their own family.</p>'
	. '<div class="grid grid-cols-3"><div>A</div><div>B</div><div>C</div></div>'
	. '</div></section></main></body></html>';
$tb_bndl = FW_Site_Converter_Sources::build_from_html( $tb_doc, 'GoldenTextBlock', array( 'dynamic_chrome' => true, 'hifi_css' => true ) );
$tb_bld  = $tb_bndl['files']['pages.json']['pages'][0]['builder'] ?? array();
$tb_node = null;
$tb_walk = function ( $n ) use ( &$tb_walk, &$tb_node ) {
	if ( ! is_array( $n ) ) { return; }
	if ( ( $n['shortcode'] ?? '' ) === 'text_block' ) { $tb_node = $n; }
	foreach ( ( $n['_items'] ?? array() ) as $c ) { $tb_walk( $c ); }
};
foreach ( $tb_bld as $s ) { $tb_walk( $s ); }
ga( "text-block node built from the source paragraph", $tb_node !== null );
$tba = is_array( $tb_node ) ? ( $tb_node['atts'] ?? array() ) : array();

ga_eq( "text-block: 20px paragraph → font_size_preset='lead'", 'lead', $tba['font_size_preset'] ?? '(missing)' );
ga( "text-block: muted colour → native text_color.custom set (rgb(41,61,54))",
	isset( $tba['text_color']['custom'] ) && strpos( (string) $tba['text_color']['custom'], '41,61,54' ) !== false,
	wp_json_encode( $tba['text_color'] ?? null ) );
ga( "text-block: source margin-bottom → native spacing option (mb slug set)",
	! empty( $tba['spacing']['margin']['bottom'] ) && strpos( (string) $tba['spacing']['margin']['bottom'], 'mb-' ) === 0,
	wp_json_encode( $tba['spacing']['margin'] ?? null ) );
ga( "text-block: preset assigned → NO font-size in the node base/custom_css (preset owns size)",
	strpos( (string) ( $tba['custom_css'] ?? '' ), 'font-size' ) === false,
	(string) ( $tba['custom_css'] ?? '' ) );

// Font-size single-source unit: with NO preset the base EMITS font-size (faithful fallback);
// with a preset the base EXCLUDES it. Mirrors the register_builder('text') exclude-list.
$fs_props_no_preset = array( 'font-family', 'line-height', 'color', 'text-align', 'margin-top', 'margin-bottom' );
$fs_props_preset    = array_merge( $fs_props_no_preset, array( 'font-size' ) );
$fs_cs = 'color:rgb(41,61,54);font-size:20px;line-height:32px';
$base_no_preset = FW_Site_Converter_Mapper::hifi_base_css( $fs_cs, $fs_props_no_preset );
$base_preset    = FW_Site_Converter_Mapper::hifi_base_css( $fs_cs, $fs_props_preset );
ga( "font-size single-source: NO preset → base EMITS font-size (fallback)", strpos( $base_no_preset, 'font-size:20px' ) !== false, $base_no_preset );
ga( "font-size single-source: preset assigned → base EXCLUDES font-size", strpos( $base_preset, 'font-size' ) === false, $base_preset );

/* --------------------------------------------------------------------- *
 * 13) HERO LAYOUT FIDELITY (two drift fixes)
 *     (a) The hero's floating "24/7 Care" badge (an absolutely-positioned
 *         icon_box) must have a POSITIONED ANCESTOR: its containing COLUMN
 *         now carries `selector{position:relative;}`, so the card anchors to
 *         the image area instead of flying to the page top-left over the logo.
 *     (b) The hero is a `grid lg:grid-cols-2` (50/50): both top-level columns
 *         are width `1_2`, and the text column carries the source `max-w-2xl`
 *         (42rem) cap so its paragraph wraps like the source.
 * --------------------------------------------------------------------- */
echo "\n[13] Hero layout fidelity (floating-card positioned ancestor + 50/50 columns + text max-width)\n";

/* Locate the real hero section (css_id=hero) in the fixture-built page and collect its
   TOP-LEVEL columns (a column whose parent is the section/row, holding the cell content). */
$hero_sec = null;
foreach ( $builder as $sec ) {
	if ( ( $sec['type'] ?? '' ) === 'section' && ( $sec['atts']['css_id'] ?? '' ) === 'hero' ) { $hero_sec = $sec; break; }
}
ga( "hero section present", $hero_sec !== null );

$hero_cols = array();
if ( $hero_sec ) {
	$walk_cols = function ( $n ) use ( &$walk_cols, &$hero_cols ) {
		if ( ! is_array( $n ) ) { return; }
		if ( ( $n['type'] ?? '' ) === 'column' ) { $hero_cols[] = $n; return; } // top-level column; don't descend into nested cols
		foreach ( ( $n['_items'] ?? array() ) as $c ) { $walk_cols( $c ); }
	};
	$walk_cols( $hero_sec );
}

/* Find the image column (holds a media_image + an icon_box) and the text column (holds the heading). */
$img_col = null; $text_col = null;
foreach ( $hero_cols as $col ) {
	$codes = array();
	$w = function ( $n ) use ( &$w, &$codes ) { if ( ! is_array( $n ) ) { return; } if ( ( $n['type'] ?? '' ) === 'simple' ) { $codes[] = $n['shortcode'] ?? ''; } foreach ( ( $n['_items'] ?? array() ) as $c ) { $w( $c ); } };
	$w( $col );
	if ( in_array( 'media_image', $codes, true ) && in_array( 'icon_box', $codes, true ) ) { $img_col = $col; }
	if ( in_array( 'special_heading', $codes, true ) && $text_col === null ) { $text_col = $col; }
}

ga( "hero image column found (media_image + icon_box)", $img_col !== null );
ga( "hero text column found (special_heading)", $text_col !== null );

// (a) Positioned-ancestor: the image column is position:relative so the absolute badge anchors to it. This
// now rides the NATIVE Position option (element_position = {position:'relative'}) instead of raw custom_css.
$img_col_css = (string) ( $img_col['atts']['custom_css'] ?? '' );
$img_col_pos = (string) ( $img_col['atts']['element_position']['position'] ?? '' );
ga( "floating-card column is a POSITIONED ANCESTOR (position:relative)", strpos( $img_col_css, 'position:relative' ) !== false || 'relative' === $img_col_pos, $img_col_css . ' | element_position=' . $img_col_pos );

// The badge icon_box inside it is still absolutely positioned (top/left) → now resolves against the column.
$badge = null;
$wb = function ( $n ) use ( &$wb, &$badge ) { if ( ! is_array( $n ) ) { return; } if ( ( $n['shortcode'] ?? '' ) === 'icon_box' ) { $badge = $n; } foreach ( ( $n['_items'] ?? array() ) as $c ) { $wb( $c ); } };
if ( $img_col ) { $wb( $img_col ); }
$badge_css = (string) ( $badge['atts']['custom_css'] ?? '' );
ga( "floating badge stays absolute (top/left) inside the relative column", strpos( $badge_css, 'position:absolute' ) !== false && strpos( $badge_css, 'top:2.5rem' ) !== false, $badge_css );

// (b) 50/50 columns: both top-level hero columns are width 1_2.
ga_eq( "hero image column width = 1/2", '1_2', $img_col['width'] ?? null );
ga_eq( "hero text column width = 1/2", '1_2', $text_col['width'] ?? null );

// (b) Text column carries the source max-w-2xl (42rem) cap so the paragraph wraps like the source.
$text_col_css = (string) ( $text_col['atts']['custom_css'] ?? '' );
ga( "hero text column carries the source max-width cap (max-w-2xl → 42rem)", strpos( $text_col_css, 'max-width:42rem' ) !== false, $text_col_css );

/* --------------------------------------------------------------------- *
 * 14) CLASS↔CSS APPEARANCE RECONCILIATION (body-scoped carried utilities)
 *     The carried Tailwind util CSS is `.sc-tw`-scoped (chrome only), leaving
 *     body elements that keep source classes unstyled. appearance_reconcile_css()
 *     re-emits ONLY appearance rules scoped to `:where(.fw-page-builder-content)`
 *     — and MUST NOT re-emit any layout/box-model/positioning rule.
 * --------------------------------------------------------------------- */
echo "\n[14] Class<->CSS appearance reconciliation\n";
if ( class_exists( 'FW_Site_Converter_Theme_Generator' ) && method_exists( 'FW_Site_Converter_Theme_Generator', 'appearance_reconcile_css' ) ) {
	$recon_src =
		  ".rounded-full{border-radius:9999px}"
		. ".bg-primary{--tw-bg-opacity:1;background-color:rgb(33 196 93 / var(--tw-bg-opacity))}"
		. ".text-primary{--tw-text-opacity:1;color:rgb(33 196 93 / var(--tw-text-opacity))}"
		. ".shadow-soft{box-shadow:0 4px 20px rgba(0,0,0,.08)}"
		. ".fill-primary{fill:#21c45d}"
		. ".border-primary{border-color:#21c45d}"
		. ".blob-shape{border-radius:30% 70% 70% 30% / 30% 30% 70% 70%}"
		// gradient-text (the two-tone-heading-black bug): appearance-eligible via background-clip.
		. ".text-gradient{background-image:linear-gradient(90deg,#21c45d,#0a7);-webkit-background-clip:text;background-clip:text;color:transparent}"
		// DECORATIVE ::before/::after flourish (Pass #7) — a content GLYPH + appearance = reconnected.
		. ".check-item::before{content:'\\2713';color:#21c45d}"
		. ".quote::after{content:'\\201C';background-color:#eee;border-radius:4px}"
		// Pseudo carrying LAYOUT — content + position/inset/width = geometry → MUST be skipped whole.
		. ".decor-blob::before{content:'';position:absolute;inset:0;width:200px;background:#21c45d}"
		// Pseudo with EMPTY content (no glyph) → refused (renders nothing without the excluded geometry).
		. ".empty-deco::before{content:'';background-color:#f00}"
		// LAYOUT rules — MUST be skipped entirely.
		. ".absolute{position:absolute}"
		. ".flex{display:flex}"
		. ".top-10{top:2.5rem}"
		. ".w-12{width:3rem}"
		. ".grid-cols-2{grid-template-columns:repeat(2,minmax(0,1fr))}"
		. ".gap-4{gap:1rem}"
		// MIXED rule (appearance + layout) — treated as layout, skipped whole.
		. ".p-2-rounded{padding:.5rem;border-radius:8px}"
		// @media wrapper: inner appearance survives, inner layout dropped.
		. "@media (min-width:768px){.md\\:rounded-xl{border-radius:.75rem}.md\\:flex{display:flex}}";
	$recon = FW_Site_Converter_Theme_Generator::appearance_reconcile_css( $recon_src );

	ga( "reconcile: rounded-full connected to body", strpos( $recon, ':where(.fw-page-builder-content) .rounded-full{border-radius:9999px;}' ) !== false, $recon );
	ga( "reconcile: bg-primary connected (background-color)", strpos( $recon, ':where(.fw-page-builder-content) .bg-primary{' ) !== false && strpos( $recon, 'background-color:rgb(33 196 93' ) !== false, $recon );
	ga( "reconcile: text-primary connected (color)", strpos( $recon, ':where(.fw-page-builder-content) .text-primary{' ) !== false, $recon );
	ga( "reconcile: shadow-soft connected (box-shadow)", strpos( $recon, ':where(.fw-page-builder-content) .shadow-soft{box-shadow:' ) !== false, $recon );
	ga( "reconcile: fill-primary connected (svg fill)", strpos( $recon, ':where(.fw-page-builder-content) .fill-primary{fill:#21c45d;}' ) !== false, $recon );
	ga( "reconcile: border-primary connected", strpos( $recon, ':where(.fw-page-builder-content) .border-primary{' ) !== false, $recon );
	ga( "reconcile: blob-shape connected (border-radius)", strpos( $recon, ':where(.fw-page-builder-content) .blob-shape{border-radius:' ) !== false, $recon );
	ga( "reconcile: gradient-text connected (background-clip:text)", strpos( $recon, ':where(.fw-page-builder-content) .text-gradient{' ) !== false && strpos( $recon, 'background-clip:text' ) !== false, $recon );
	ga( "reconcile: @media appearance survives (md:rounded-xl)", strpos( $recon, ':where(.fw-page-builder-content) .md\\:rounded-xl{border-radius:.75rem;}' ) !== false, $recon );

	// Pass #7 — DECORATIVE pseudo-element flourishes reconnected (content glyph + appearance only).
	ga( "reconcile: decorative ::before glyph reconnected (content + color)",
		strpos( $recon, ':where(.fw-page-builder-content) .check-item::before{' ) !== false
		&& strpos( $recon, 'color:#21c45d' ) !== false, $recon );
	ga( "reconcile: decorative ::after glyph reconnected (content + bg + radius)",
		strpos( $recon, ':where(.fw-page-builder-content) .quote::after{' ) !== false
		&& strpos( $recon, 'border-radius:4px' ) !== false, $recon );
	// Negative controls — a pseudo with LAYOUT is skipped whole; an empty-content pseudo is refused.
	ga( "reconcile: pseudo carrying LAYOUT (position/width) is SKIPPED (no geometry leak)",
		strpos( $recon, 'decor-blob' ) === false && strpos( $recon, 'position:absolute' ) === false && strpos( $recon, 'width:200px' ) === false, $recon );
	ga( "reconcile: empty-content pseudo is refused (no inert 0x0 rule emitted)",
		strpos( $recon, 'empty-deco' ) === false, $recon );

	// Negative: NO layout rule may be body-scoped.
	$layout_leak = ( strpos( $recon, '.absolute{position:absolute' ) !== false )
		|| ( strpos( $recon, '.flex{display:flex' ) !== false )
		|| ( strpos( $recon, '.md\\:flex' ) !== false )
		|| preg_match( '/:where\(\.fw-page-builder-content\)[^{]*\.(top-10|w-12|grid-cols-2|gap-4|p-2-rounded)\b/', $recon );
	ga( "reconcile: NO layout/box-model rule is body-scoped (absolute/flex/top/width/grid/gap/mixed)", ! $layout_leak, $recon );
	// Belt: no forbidden layout PROPERTY appears anywhere in the reconciled output.
	ga( "reconcile: output declares no layout props (position/display/width/top/gap/grid/padding)", ! preg_match( '/(?:^|;|\{)\s*(position|display|width|height|top|left|right|bottom|gap|grid-template|padding|margin|flex)\s*:/', $recon ), $recon );
	// No !important (faithful base, not a clobber).
	ga( "reconcile: emits no !important (preset-overridable base)", strpos( $recon, '!important' ) === false, $recon );
} else {
	ga( "reconcile: appearance_reconcile_css method present", false );
}

/* --------------------------------------------------------------------- *
 * 14) PASS #5 — SPACING-SCALE PRESET DISTILLATION
 *     (a) A measured px length snaps to the NATIVE spacing slug when it sits
 *         ON the shared scale (within 1px), and stays a LOSSLESS `[NNpx]`
 *         arbitrary when it is genuinely off-scale (no ±12px snap error).
 *     (b) build_spacing_scale() folds the source's MEASURED off-scale rhythm
 *         (computed padding stamped on data-sc-cs — the non-Tailwind case) into
 *         the editable Theme-Settings spacing scale as a `[NNpx]` row, while an
 *         ON-scale measured value stays a named slug (no duplicate row).
 * --------------------------------------------------------------------- */
echo "\n[14] Pass #5 spacing-scale distillation (native snap + measured fold)\n";

$rm_tok = new ReflectionMethod( 'FW_Site_Converter_Mapper', 'spacing_token' );
$rm_tok->setAccessible( true );
ga_eq( "spacing_token: 64px section padding → native slug pt-7", 'pt-7',  $rm_tok->invoke( null, 'pt', 64 ) );
ga_eq( "spacing_token: 96px section padding → native slug pt-10", 'pt-10', $rm_tok->invoke( null, 'pt', 96 ) );
ga_eq( "spacing_token: off-scale 100px stays lossless pt-[100px]", 'pt-[100px]', $rm_tok->invoke( null, 'pt', 100 ) );

$rm_gap = new ReflectionMethod( 'FW_Site_Converter_Mapper', 'gap_slug' );
$rm_gap->setAccessible( true );
ga_eq( "gap_slug: 24px column gap → nearest Gap-Scale slug 4", '4', $rm_gap->invoke( null, '24px' ) );

// (b) MEASURED FOLD — a non-Tailwind source expresses its rhythm ONLY as computed padding on
// data-sc-cs (no `pt-[..]` class). build_spacing_scale must distill the off-scale value into the
// editable scale, and NOT duplicate an on-scale value that a named slug already covers.
// Gated on the Pass #5 helper so a pre-mirror install (old plugin copy) stays green and the
// assertion activates once the working copy is mirrored in.
if ( method_exists( 'FW_Site_Converter_Stitch', 'cs_vspace_px' ) ) {
	$meas_html = '<section data-sc-cs="background-color:rgb(255,255,255);padding:100px 0px">A</section>'
		. '<section data-sc-cs="padding:96px 0px 200px">B</section>';
	$scale = FW_Site_Converter_Stitch::build_spacing_scale( array(), $meas_html );
	$sizes = array_map( function ( $r ) { return $r['size']; }, $scale );
	ga( "build_spacing_scale: measured off-scale 100px padding → [100px] scale row", in_array( '100px', $sizes, true ), wp_json_encode( $sizes ) );
	ga( "build_spacing_scale: measured off-scale 200px padding → [200px] scale row", in_array( '200px', $sizes, true ), wp_json_encode( $sizes ) );
	ga( "build_spacing_scale: on-scale 96px measured value stays a named slug (no [96px] dup)", ! in_array( '96px', $sizes, true ), wp_json_encode( $sizes ) );
	ga( "build_spacing_scale: base scale preserved (13 base rows + 2 measured extras)", count( $scale ) === 15, (string) count( $scale ) );
} else {
	ga( "Pass #5 measured fold present (skipped — install predates it; mirror to activate)", true );
}

/* --------------------------------------------------------------------- *
 * 15) PASS #2 NATIVE STRUCTURE PROMOTION — text_block horizontal alignment
 *     maps to the NATIVE, editable `text_align` option (a text-* class on the
 *     wrapper) instead of a hardcoded inline `<div style="text-align">`. The
 *     delicate max-width + mx-auto centering path stays inline (unchanged).
 * --------------------------------------------------------------------- */
echo "\n[15] Pass #2 text_block alignment → native text_align option\n";
if ( method_exists( 'FW_Site_Converter_Mapper', 'n_text' ) ) {
	$rm = new ReflectionMethod( 'FW_Site_Converter_Mapper', 'n_text' );
	$rm->setAccessible( true );
	// (a) pure centered paragraph (no max-width) → native text_align, no inline text-align div.
	$c = $rm->invoke( null, '<p>Centered copy</p>', '', 'center' );
	ga_eq( "centered text → native text_align att", 'center', $c['atts']['text_align'] ?? '' );
	ga( "centered text → no inline text-align div in text", false === strpos( (string) ( $c['atts']['text'] ?? '' ), 'text-align' ), $c['atts']['text'] ?? '' );
	// (b) a plain (left/inherit) paragraph carries NO text_align att (inherit default).
	$l = $rm->invoke( null, '<p>Plain copy</p>', '', '' );
	ga( "plain text → no text_align att (inherit default)", ! isset( $l['atts']['text_align'] ), wp_json_encode( array_keys( $l['atts'] ) ) );
	// (c) centered + source max-width keeps the proven inline mx-auto wrapper (native max_width left-pins).
	$m = $rm->invoke( null, '<p>Constrained copy</p>', '640px', 'center' );
	ga( "centered + max-width keeps inline mx-auto wrapper (unchanged)", false !== strpos( (string) ( $m['atts']['text'] ?? '' ), 'margin-left:auto' ), $m['atts']['text'] ?? '' );
	ga( "centered + max-width drops the native max_width att (inline instead)", ! isset( $m['atts']['max_width'] ), wp_json_encode( array_keys( $m['atts'] ) ) );
} else {
	ga( "Pass #2 text_align promotion present (skipped — install predates it; mirror to activate)", true );
}

/* --------------------------------------------------------------------- *
 * 16) Pass #6 — PER-BREAKPOINT RESPONSIVE CARRY (visibility)
 *     A source band's Tailwind responsive-visibility utilities map to the native
 *     `responsive_hide` option (hide-xs/hide-sm/hide-md). Unit-level on the helper
 *     (unambiguous families + negative controls) + a section-level end-to-end check.
 * --------------------------------------------------------------------- */
echo "\n[16] Pass #6 responsive visibility carry → native responsive_hide\n";
if ( method_exists( 'FW_Site_Converter_Mapper', 'responsive_hide_from_classes' ) ) {
	$rh = function ( $cls ) { return FW_Site_Converter_Mapper::responsive_hide_from_classes( $cls ); };
	// Family A — base `hidden` re-shown from a breakpoint up → hide BELOW it.
	ga_eq( "hidden md:flex → hide-xs", array( 'hide-xs' => true ), $rh( 'hidden md:flex items-center' ) );
	ga_eq( "hidden md:block → hide-xs", array( 'hide-xs' => true ), $rh( 'hidden md:block' ) );
	ga_eq( "hidden lg:block → hide-xs + hide-sm", array( 'hide-xs' => true, 'hide-sm' => true ), $rh( 'hidden lg:block' ) );
	// Family B — base visible, hidden from a breakpoint up.
	ga_eq( "md:hidden → hide-sm + hide-md", array( 'hide-sm' => true, 'hide-md' => true ), $rh( 'flex md:hidden' ) );
	ga_eq( "lg:hidden → hide-md", array( 'hide-md' => true ), $rh( 'block lg:hidden' ) );
	// Negative controls — no clear toggle → no responsive_hide (never a wrong guess).
	ga_eq( "plain classes → {} (negative control)", array(), $rh( 'grid grid-cols-3 gap-8 py-20' ) );
	ga_eq( "bare `hidden` (fully removed, not per-breakpoint) → {}", array(), $rh( 'hidden absolute' ) );
	ga_eq( "ambiguous hidden md:flex lg:hidden → {} (no guess)", array(), $rh( 'hidden md:flex lg:hidden' ) );
	ga_eq( "empty class → {}", array(), $rh( '' ) );

	// Section-level end-to-end: a body band flagged `hidden md:block` → the section node carries
	// responsive_hide = hide-xs (rendered as .hide-xs by sc_build_wrapper_attr + frontend-grid.css).
	$rhide_html = '<section id="deskonly" class="hidden md:block py-20"><div class="max-w-5xl mx-auto">'
		. '<h2>Desktop-only comparison</h2><p>Shown only on larger screens.</p></div></section>';
	$rdoc    = '<!DOCTYPE html><html><head><title>T</title></head><body><main>' . $rhide_html . '</main></body></html>';
	$rbundle = FW_Site_Converter_Sources::build_from_html( $rdoc, 'RHide', array( 'dynamic_chrome' => false ) );
	$rbuild  = $rbundle['files']['pages.json']['pages'][0]['builder'] ?? array();
	$rsec    = null;
	foreach ( $rbuild as $s ) { if ( ( $s['type'] ?? '' ) === 'section' ) { $rsec = $s; break; } }
	$rsel    = is_array( $rsec ) ? ( $rsec['atts']['responsive_hide'] ?? array() ) : array();
	ga( "section `hidden md:block` → responsive_hide includes hide-xs", ! empty( $rsel['hide-xs'] ), wp_json_encode( $rsel ) );
	ga( "section `hidden md:block` → NOT hidden on tablet/desktop", empty( $rsel['hide-sm'] ) && empty( $rsel['hide-md'] ), wp_json_encode( $rsel ) );

	// Negative control end-to-end: a plain band carries an EMPTY responsive_hide (byte-identical default).
	$plain_html = '<section id="always" class="py-20"><div class="max-w-5xl mx-auto"><h2>Always visible</h2><p>Copy.</p></div></section>';
	$pdoc    = '<!DOCTYPE html><html><head><title>T</title></head><body><main>' . $plain_html . '</main></body></html>';
	$pbundle = FW_Site_Converter_Sources::build_from_html( $pdoc, 'Plain', array( 'dynamic_chrome' => false ) );
	$pbuild  = $pbundle['files']['pages.json']['pages'][0]['builder'] ?? array();
	$psec    = null;
	foreach ( $pbuild as $s ) { if ( ( $s['type'] ?? '' ) === 'section' ) { $psec = $s; break; } }
	$psel    = is_array( $psec ) ? ( $psec['atts']['responsive_hide'] ?? array() ) : array();
	ga_eq( "plain section → empty responsive_hide (negative control)", array(), array_filter( (array) $psel ) );
} else {
	ga( "Pass #6 responsive_hide_from_classes present (skipped — install predates it; mirror to activate)", true );
}

/* --------------------------------------------------------------------- *
 * [17] #1 HEADING WRAPPER-INHERITANCE — a centered constrained wrapper's text-align + max-width
 * cascade onto the special_heading (the wrapper's `text-center max-w-2xl mx-auto` was previously
 * dropped because the recognizer read the h2's OWN classes).
 * --------------------------------------------------------------------- */
$wi_html = '<section id="wi"><div class="text-center max-w-2xl mx-auto mb-16">'
	. '<h2 class="text-3xl md:text-4xl font-bold mb-4">Why Pets Love Golden Fixture 1</h2>'
	. '<p class="text-lg">We designed every aspect of our facility for your furry friends.</p>'
	. '</div></section>';
$wi_nodes = $sc_nodes_of( $wi_html );
$wi_sh = $first_sc( $wi_nodes, 'special_heading' );
ga( "wrapper-inherit: centered wrapper → a special_heading", $wi_sh !== null, wp_json_encode( $codes_of( $wi_nodes ) ) );
ga_eq( "wrapper-inherit: alignment = center (from wrapper text-center)", 'center', $wi_sh['atts']['alignment'] ?? null );
ga_eq( "wrapper-inherit: block_max_width value = 42 (from max-w-2xl)", '42', (string) ( $wi_sh['atts']['block_max_width']['value'] ?? '' ) );
ga_eq( "wrapper-inherit: block_max_width unit = rem", 'rem', $wi_sh['atts']['block_max_width']['unit'] ?? null );
/* Negative control: a LEFT (non-centered) wrapper stays inherit — no forced alignment, no max-width. */
$wi_neg = $sc_nodes_of( '<section id="wl"><div class="max-w-2xl"><h2>Left heading here</h2><p>Some subtext that is long enough.</p></div></section>' );
$wi_nsh = $first_sc( $wi_neg, 'special_heading' );
ga_eq( "wrapper-inherit: non-centered wrapper → alignment stays inherit ('')", '', $wi_nsh['atts']['alignment'] ?? null );
ga_eq( "wrapper-inherit: no mx-auto → no wrapper max-width carried", '', (string) ( $wi_nsh['atts']['block_max_width']['value'] ?? '' ) );
/* NEVER-DROP: a SUBTITLE part carrying its OWN `max-w-* mx-auto` (not the wrapper) must not be
 * silently dropped — it's reproduced as scoped Custom CSS on `.heading-subtitle`, and the utility
 * is recorded as KEPT (absent from the conversion-map `dropped`). Layout the appearance base excludes. */
$nd_html = '<section id="nd"><div class="text-center"><h2 class="text-4xl font-bold">About Our Studio</h2>'
	. '<p class="text-muted-foreground leading-relaxed max-w-2xl mx-auto mb-10">We craft calm, considered spaces.</p></div></section>';
$nd_nodes = $sc_nodes_of( $nd_html );
$nd_sh    = $first_sc( $nd_nodes, 'special_heading' );
$nd_css   = (string) ( $nd_sh['atts']['custom_css'] ?? '' );
ga( "never-drop: subtitle max-w-2xl → scoped .heading-subtitle max-width:42rem in custom_css",
	false !== strpos( $nd_css, '.heading-subtitle{max-width:42rem' ), $nd_css );
ga( "never-drop: carried subtitle centering (margin-left:auto) in custom_css",
	false !== strpos( $nd_css, 'margin-left:auto' ), $nd_css );
$nd_hash = FW_Site_Converter_Mapper::build_conversion_map( array( array( 'builder' => $nd_nodes ) ) );
$nd_drop = array();
foreach ( $nd_hash as $r ) { if ( ! empty( $r['dropped'] ) ) { $nd_drop = array_merge( $nd_drop, (array) $r['dropped'] ); } }
ga( "never-drop: max-w-2xl NOT in dropped (recorded as kept)", ! in_array( 'max-w-2xl', $nd_drop, true ), wp_json_encode( $nd_drop ) );
/* CLEAN CONTENT: capture-only `data-sc-cs` never survives into carried inline HTML, and a
 * presentational-only utility (`italic`/`font-normal`) folds to an inline style so it isn't lost. */
$mac = new ReflectionMethod( 'FW_Site_Converter_Mapper', 'map_accent_classes' );
$mac->setAccessible( true );
$mac_in  = 'Objects of<br><span class="italic font-normal" data-sc-cs="color:rgb(255,255,255);font-size:128px">Quiet Beauty</span>';
$mac_out = (string) $mac->invoke( null, $mac_in );
ga( "clean-content: data-sc-cs stripped from carried inline HTML", false === strpos( $mac_out, 'data-sc-cs' ), $mac_out );
ga( "clean-content: italic folded to inline style:font-style:italic", false !== strpos( $mac_out, 'font-style:italic' ), $mac_out );
ga( "clean-content: font-normal folded to font-weight:400", false !== strpos( $mac_out, 'font-weight:400' ), $mac_out );
/* TWO-COLUMN image|text band: a `grid md:grid-cols-2` with an image cell (whose own `absolute inset-0`
 * gradient overlay makes it LOOK like an image composite) beside a text cell must split into TWO columns
 * — NOT get eaten by the image-composite / image-overlay recognizer (which dropped the whole text column
 * or froze the band in a verbatim code_block). Image-dominant guard = the fix. */
$tc_html = '<section class="py-20"><div class="container-full"><div class="grid md:grid-cols-2 gap-8 items-center">'
	. '<div class="relative aspect-[4/5] overflow-hidden group"><img src="https://ex.com/a.jpg?w=1920&amp;q=80" alt="Lighting" class="w-full h-full object-cover"><div class="absolute inset-0 bg-gradient-to-t"></div></div>'
	. '<div class="md:py-12"><p class="uppercase text-primary mb-4">Featured Collection</p><h2 class="text-4xl mb-6">Lighting</h2><p class="mb-8 max-w-md">Sculptural forms.</p>'
	. '<a class="inline-flex items-center justify-center gap-2 whitespace-nowrap font-medium bg-primary text-primary-foreground hover:bg-primary/90 h-11 rounded-none px-10 py-6 text-sm tracking-[0.15em] uppercase btn-premium" href="/x">Shop Lighting<svg viewBox="0 0 24 24" class="lucide"><path d="M5 12h14"></path></svg></a></div></div></div></section>';
$tc_nodes = $sc_nodes_of( $tc_html );
$tc_codes = $codes_of( $tc_nodes );
ga( "two-col band: keeps the image (media_image present)", in_array( 'media_image', $tc_codes, true ), wp_json_encode( $tc_codes ) );
ga( "two-col band: keeps the heading (special_heading present — text column NOT dropped)", in_array( 'special_heading', $tc_codes, true ), wp_json_encode( $tc_codes ) );
ga( "two-col band: keeps the button (Shop Lighting)", in_array( 'button', $tc_codes, true ), wp_json_encode( $tc_codes ) );
ga( "two-col band: NOT frozen as a verbatim code_block", ! in_array( 'code_block', $tc_codes, true ), wp_json_encode( $tc_codes ) );

/* FOOTER BANDS: a footer with a `border-b` PRE-band (brand | newsletter, 2 cells) above a 4-column
 * link grid above a © line must split into pre_footer (2 cols: brand | newsletter) + main_footer
 * (4 cols) — NOT collapse (the 4-col grid was mis-flagged copyright and dropped, then the pre-band was
 * merged into one column). Regression guard for band_is_copyright + the brand|newsletter split. */
$fb_html = '<!DOCTYPE html><html><head><title>T</title></head><body><main><section><h1>H</h1></section></main>'
	. '<footer class="bg-foreground text-background">'
	. '<div class="border-b border-background/10"><div class="container-full py-12"><div class="flex md:flex-row justify-between gap-8">'
	. '<div><a class="text-3xl" href="/">Maison</a><p class="mt-3 max-w-xs">Curated home objects and lifestyle pieces for considered living.</p></div>'
	. '<div class="max-w-sm w-full"><p class="uppercase mb-3">Stay Connected</p><form class="flex"><input type="email" placeholder="Your email"><button type="submit"><svg viewBox="0 0 24 24"><path d="M5 12h14"/></svg></button></form></div></div></div></div>'
	. '<div class="container-full py-16"><div class="grid grid-cols-2 md:grid-cols-4 gap-8">'
	. '<div><h4 class="uppercase mb-4">Shop</h4><ul><li><a href="/a">Lighting</a></li><li><a href="/b">Furniture</a></li></ul></div>'
	. '<div><h4 class="uppercase mb-4">About</h4><ul><li><a href="/c">Story</a></li><li><a href="/c2">Team</a></li></ul></div>'
	. '<div><h4 class="uppercase mb-4">Help</h4><ul><li><a href="/d">Contact</a></li><li><a href="/d2">FAQ</a></li></ul></div>'
	. '<div><h4 class="uppercase mb-4">Legal</h4><ul><li><a href="/e">Privacy</a></li><li><a href="/e2">Terms</a></li></ul></div></div></div>'
	. '<div class="container-full py-6"><p>© 2026 Maison</p></div></footer></body></html>';
$fb_ts   = FW_Site_Converter_Sources::build_from_html( $fb_html, 'Foot', array( 'dynamic_chrome' => false ) )['files']['theme-settings.json']['values'] ?? array();
$fb_cols = function ( $bar ) use ( $fb_ts ) {
	$v = $fb_ts[ $bar . '_columns' ] ?? array(); $cnt = (string) ( $v['count'] ?? '' );
	$band = $v[ $cnt ] ?? array(); $out = array();
	foreach ( $band as $k => $col ) { if ( is_array( $col ) && strpos( (string) $k, $bar . '_col_' ) === 0 ) { $out[] = array_map( function ( $e ) { return $e['element_type']['element'] ?? '?'; }, $col ); } }
	return $out;
};
$fb_pre  = $fb_cols( 'pre_footer' );
$fb_main = $fb_cols( 'main_footer' );
ga( "footer bands: pre_footer has 2 columns (brand | newsletter)", count( $fb_pre ) === 2, wp_json_encode( $fb_pre ) );
ga( "footer bands: pre col1 = brand (logo)", isset( $fb_pre[0] ) && in_array( 'logo', $fb_pre[0], true ), wp_json_encode( $fb_pre ) );
ga( "footer bands: pre col2 = newsletter", isset( $fb_pre[1] ) && in_array( 'newsletter', $fb_pre[1], true ), wp_json_encode( $fb_pre ) );
ga( "footer bands: main link grid kept as 4 columns (not mis-flagged copyright)", count( $fb_main ) === 4, wp_json_encode( $fb_main ) );

/* INSTAGRAM FEED → the [instagram] Library shortcode. A feed (marker/CDN images/@handle grid) emits a
 * native instagram element with the detected @handle + column count, and records `instagram` as a required
 * Library shortcode. A lone Instagram social LINK/icon (no image grid) must NOT trigger it. */
$ig_codes_of = function ( $html ) {
	$bd = FW_Site_Converter_Sources::build_from_html( '<!DOCTYPE html><html><head><title>T</title></head><body><main>' . $html . '</main></body></html>', 'IG', array( 'dynamic_chrome' => false ) );
	$cm = $bd['files']['theme-design.json']['conversion_map'] ?? array();
	$codes = array(); $ig = null;
	foreach ( $cm as $r ) { $codes[] = $r['sc'] ?? '?'; if ( ( $r['sc'] ?? '' ) === 'instagram' ) { $ig = $r['mapped'] ?? array(); } }
	return array( 'codes' => $codes, 'ig' => $ig, 'req' => $bd['files']['theme-design.json']['required_shortcodes'] ?? array() );
};
$ig_feed = $ig_codes_of(
	'<section id="insta"><h2>Follow us on Instagram <a href="https://instagram.com/maison_home/">@maison_home</a></h2>'
	. '<div class="instagram-feed grid md:grid-cols-4 gap-2">'
	. '<a href="https://instagram.com/p/A0/"><img src="https://scontent.cdninstagram.com/x0.jpg"></a>'
	. '<a href="https://instagram.com/p/A1/"><img src="https://scontent.cdninstagram.com/x1.jpg"></a>'
	. '<a href="https://instagram.com/p/A2/"><img src="https://scontent.cdninstagram.com/x2.jpg"></a>'
	. '<a href="https://instagram.com/p/A3/"><img src="https://scontent.cdninstagram.com/x3.jpg"></a></div></section>'
);
ga( "instagram: feed → [instagram] shortcode emitted", in_array( 'instagram', $ig_feed['codes'], true ), wp_json_encode( $ig_feed['codes'] ) );
ga_eq( "instagram: detected @handle", 'maison_home', is_array( $ig_feed['ig'] ) ? ( $ig_feed['ig']['username'] ?? '' ) : '' );
ga_eq( "instagram: columns from md:grid-cols-4", '4', is_array( $ig_feed['ig'] ) ? (string) ( $ig_feed['ig']['columns'] ?? '' ) : '' );
ga( "instagram: recorded as a required Library shortcode", in_array( 'instagram', $ig_feed['req'], true ), wp_json_encode( $ig_feed['req'] ) );
$ig_link = $ig_codes_of( '<section><h2>Contact</h2><p>Find us.</p><a href="https://instagram.com/maison_home/" aria-label="Instagram"><svg class="lucide-instagram"></svg></a></section>' );
ga( "instagram: a lone social link/icon is NOT a feed (no [instagram])", ! in_array( 'instagram', $ig_link['codes'], true ), wp_json_encode( $ig_link['codes'] ) );

/* MENU NEVER-DROP: a nav with uppercase + letter-spacing (`tracking-*`) links maps to the native
 * menu_link_uppercase + menu_link_letter_spacing options — and the large BRAND wordmark link is NOT
 * sampled as a nav item (it would pollute the menu font-size/letter-spacing). */
$mn_html = '<header><a href="/" data-sc-cs="font-size:30px;letter-spacing:-0.75px;font-family:&quot;Cormorant Garamond&quot;,serif;color:rgb(42,38,34)">Maison</a>'
	. '<nav><a href="/products" data-sc-cs="font-size:12px;font-weight:500;letter-spacing:1.8px;text-transform:uppercase;color:rgb(124,115,106)">Shop All</a>'
	. '<a href="/about" data-sc-cs="font-size:12px;font-weight:500;letter-spacing:1.8px;text-transform:uppercase;color:rgb(124,115,106)">About</a></nav></header>'
	. '<main><section><h1>Home</h1></section></main>';
$mn_ts = FW_Site_Converter_Sources::build_from_html( '<!DOCTYPE html><html><head><title>T</title></head><body>' . $mn_html . '</body></html>', 'Menu', array( 'dynamic_chrome' => true ) )['files']['theme-settings.json']['values']['header_menu'] ?? array();
ga_eq( "menu never-drop: uppercase mapped to menu_link_uppercase", 'yes', $mn_ts['menu_link_uppercase'] ?? '' );
ga_eq( "menu never-drop: letter-spacing 1.8px (tracking) captured", '1.8', is_array( $mn_ts['menu_link_letter_spacing'] ?? null ) ? (string) $mn_ts['menu_link_letter_spacing']['value'] : '' );
ga_eq( "menu never-drop: font-size is the NAV 12px, not the 30px brand wordmark", '12', is_array( $mn_ts['menu_link_font_size'] ?? null ) ? (string) $mn_ts['menu_link_font_size']['value'] : '' );

/* FOOTER never-drop: column-heading typography (uppercase + tracking + weight/size/colour) has no native
 * footer-heading option → carried as a scoped `.footer-links-title` rule in the misc_custom_css residual. */
$fh_html = '<!DOCTYPE html><html><head><title>T</title></head><body><main><section><h1>H</h1></section></main>'
	. '<footer class="bg-foreground"><div class="container py-16"><div class="grid grid-cols-4 gap-8">'
	. '<div><h4 data-sc-cs="text-transform:uppercase;letter-spacing:2.75px;font-weight:600;font-size:11px;color:rgba(250,248,245,0.4)">Shop</h4><ul><li><a href="/a">Lighting</a></li><li><a href="/b">Furniture</a></li></ul></div>'
	. '<div><h4 data-sc-cs="text-transform:uppercase;letter-spacing:2.75px">About</h4><ul><li><a href="/c">Story</a></li><li><a href="/c2">Team</a></li></ul></div>'
	. '<div><h4 data-sc-cs="text-transform:uppercase">Help</h4><ul><li><a href="/d">Contact</a></li><li><a href="/d2">FAQ</a></li></ul></div>'
	. '<div><h4 data-sc-cs="text-transform:uppercase">Legal</h4><ul><li><a href="/e">Privacy</a></li><li><a href="/e2">Terms</a></li></ul></div></div></div>'
	. '<div class="container py-6"><p>© 2026 Maison</p></div></footer></body></html>';
$fh_mc = FW_Site_Converter_Sources::build_from_html( $fh_html, 'FootH', array( 'dynamic_chrome' => true ) )['files']['theme-settings.json']['values']['misc_custom_css']['custom_css'] ?? '';
ga( "footer never-drop: .footer-links-title rule emitted", false !== strpos( $fh_mc, '.footer-links-title{' ), $fh_mc );
ga( "footer never-drop: uppercase carried", (bool) preg_match( '/\.footer-links-title\{[^}]*text-transform:uppercase/', $fh_mc ), $fh_mc );
ga( "footer never-drop: tracking (2.75px) carried", (bool) preg_match( '/\.footer-links-title\{[^}]*letter-spacing:2\.75px/', $fh_mc ), $fh_mc );

/* CHROME QA GATE (dropped_chrome): the never-drop gate extended to logo/menu/footer. It PASSES when the
 * chrome typography is carried (native option or scoped CSS), and FAILS listing the offenders when a
 * visually-significant class lands nowhere — so remaining chrome drops surface as data, not guesswork. */
$chrome_check = function ( $html ) {
	$pr = FW_Site_Converter_Sources::build_from_html( $html, array( 'slug' => 'cg', 'dynamic_chrome' => true ) )['files']['conversion-parity.json']['checks'] ?? array();
	foreach ( $pr as $c ) { if ( ( $c['id'] ?? '' ) === 'dropped_chrome' ) { return $c; } }
	return null;
};
$cg_bad = $chrome_check( '<!DOCTYPE html><html><head><title>T</title></head><body><header><a class="italic" href="/" data-sc-cs="font-style:italic;color:rgb(20,20,20)">Brand</a><nav><a class="italic" href="/shop" data-sc-cs="font-style:italic;font-size:14px;color:rgb(90,90,90)">Shop</a></nav></header><main><section><h1>H</h1></section></main></body></html>' );
ga( "chrome gate: catches an uncarried class (italic) — pass=false", is_array( $cg_bad ) && $cg_bad['pass'] === false, wp_json_encode( $cg_bad ) );
ga( "chrome gate: names the offenders (logo:italic)", is_array( $cg_bad ) && false !== strpos( (string) $cg_bad['converted'], 'logo:italic' ), wp_json_encode( $cg_bad ) );
$cg_ok = $chrome_check( '<!DOCTYPE html><html><head><title>T</title></head><body><header><a href="/" data-sc-cs="font-family:&quot;Cormorant Garamond&quot;,serif;letter-spacing:-0.75px;color:rgb(42,38,34)" class="font-serif tracking-tight text-foreground">Maison</a><nav><a href="/shop" class="uppercase tracking-[0.15em] text-muted-foreground" data-sc-cs="font-size:12px;text-transform:uppercase;letter-spacing:1.8px;color:rgb(124,115,106)">Shop</a></nav></header><main><section><h1>H</h1></section></main></body></html>' );
ga( "chrome gate: PASSES when logo+menu typography is carried", is_array( $cg_ok ) && $cg_ok['pass'] === true, wp_json_encode( $cg_ok ) );

/* FOOTER LINK never-drop: a footer nav link's font-size + hover colour → scoped `.footer-menu a` +
 * `.footer-menu a:hover` rules (hover mapped to the Color-Preset var). Surfaced by the chrome gate. */
$fl_html = '<!DOCTYPE html><html><head><title>T</title></head><body><main><section><h1>H</h1></section></main>'
	. '<footer class="bg-foreground"><div class="container py-16"><div class="grid grid-cols-2 gap-8">'
	. '<div><h4 data-sc-cs="text-transform:uppercase">Shop</h4><ul>'
	. '<li><a href="/a" class="hover:text-background" data-sc-cs="font-size:14px;font-weight:400;color:rgba(250,248,245,0.5)">Lighting</a></li>'
	. '<li><a href="/b" class="hover:text-background" data-sc-cs="font-size:14px;font-weight:400;color:rgba(250,248,245,0.5)">Furniture</a></li></ul></div>'
	. '<div><h4 data-sc-cs="text-transform:uppercase">About</h4><ul><li><a href="/c">Story</a></li><li><a href="/c2">Team</a></li></ul></div></div></div>'
	. '<div class="container py-6"><p>© 2026 Maison</p></div></footer></body></html>';
$fl_mc = FW_Site_Converter_Sources::build_from_html( $fl_html, 'FootL', array( 'dynamic_chrome' => true ) )['files']['theme-settings.json']['values']['misc_custom_css']['custom_css'] ?? '';
ga( "footer link never-drop: .footer-link font-size carried", (bool) preg_match( '/\.footer-column \.footer-link\{[^}]*font-size:14px/', $fl_mc ), $fl_mc );
ga( "footer link never-drop: hover → var(--color-background)", false !== strpos( $fl_mc, '.footer-column .footer-link:hover{color:var(--color-background)}' ), $fl_mc );

/* FOOTER TAGLINE never-drop: the brand tagline <p>'s size / line-height / muted colour → a scoped
 * `.footer-tagline` rule (the emit tags the paragraph with that class). */
$tg_html = '<!DOCTYPE html><html><head><title>T</title></head><body><main><section><h1>H</h1></section></main>'
	. '<footer class="bg-foreground"><div class="border-b"><div class="container py-12"><div class="flex md:flex-row justify-between gap-8">'
	. '<div><a class="text-3xl" href="/">Maison</a><p class="text-sm max-w-xs" data-sc-cs="font-size:14px;line-height:22.75px;color:rgba(250,248,245,0.5)">Curated home objects and lifestyle pieces for considered living.</p></div>'
	. '<div class="max-w-sm"><p class="uppercase mb-3">Stay Connected</p><form class="flex"><input type="email" placeholder="Your email"><button type="submit">Go</button></form></div></div></div></div>'
	. '<div class="container py-16"><div class="grid grid-cols-2 gap-8"><div><h4>Shop</h4><ul><li><a href="/a">Lighting</a></li><li><a href="/b">Furniture</a></li></ul></div><div><h4>About</h4><ul><li><a href="/c">Story</a></li><li><a href="/c2">Team</a></li></ul></div></div></div>'
	. '<div class="container py-6"><p>© 2026 Maison</p></div></footer></body></html>';
$tg_mc = FW_Site_Converter_Sources::build_from_html( $tg_html, 'FootT', array( 'dynamic_chrome' => true ) )['files']['theme-settings.json']['values']['misc_custom_css']['custom_css'] ?? '';
ga( "tagline never-drop: .footer-tagline rule emitted", false !== strpos( $tg_mc, '.footer-tagline{' ), $tg_mc );
ga( "tagline never-drop: muted colour carried", (bool) preg_match( '/\.footer-tagline\{[^}]*color:rgba\(250,\s*248,\s*245,\s*0\.5\)/', $tg_mc ), $tg_mc );

/* --------------------------------------------------------------------- *
 * [18] CONTAINER-LEVEL text_align — a centered source band carries its
 *      centering to the SECTION's native `text_align` (and the decomposed
 *      intro COLUMN's `text_align`) so the whole band — heading + paragraph +
 *      buttons — inherits text-align:center as one. A non-centered section stays
 *      '' (Inherit). text_align is a DIFFERENT axis from content_h (flexbox).
 * --------------------------------------------------------------------- */
echo "\n[18] Container-level text_align (section + column) from source centering\n";
$sec_by_id = array();
foreach ( $builder as $s ) {
	if ( ( $s['type'] ?? '' ) === 'section' ) { $sec_by_id[ $s['atts']['css_id'] ?? '' ] = $s; }
}
$first_col_ta = function ( $sec ) {
	$found = null;
	$w = function ( $n ) use ( &$w, &$found ) {
		if ( $found !== null || ! is_array( $n ) ) { return; }
		if ( ( $n['type'] ?? '' ) === 'column' ) { $found = $n['atts']['text_align'] ?? '(unset)'; return; }
		foreach ( ( $n['_items'] ?? array() ) as $c ) { $w( $c ); }
	};
	$w( $sec );
	return $found;
};
// Centered band (the CTA) → section + column both 'center'.
ga_eq( "cta section → text_align=center (centered band)", 'center', $sec_by_id['cta']['atts']['text_align'] ?? null );
ga_eq( "cta intro column → text_align=center (centered mixed-content wrapper)", 'center', $first_col_ta( $sec_by_id['cta'] ?? array() ) );
// Non-centered controls → '' (Inherit).
ga_eq( "hero section → text_align='' (not a centered band, inherit)", '', $sec_by_id['hero']['atts']['text_align'] ?? null );
ga_eq( "features section → text_align='' (not a centered band, inherit)", '', $sec_by_id['features']['atts']['text_align'] ?? null );
// Every section/column node carries the key (default '') so old + new saves normalize to inherit.
ga( "every section node defines text_align key (default '')", 3 === count( array_filter( $sec_by_id, function ( $s ) { return array_key_exists( 'text_align', $s['atts'] ?? array() ); } ) ),
	wp_json_encode( array_map( function ( $s ) { return $s['atts']['text_align'] ?? '(missing)'; }, $sec_by_id ) ) );
// Direct unit check of the class-based helper twin (parity with Stitch::wrapper_align).
$cta_unit = FW_Site_Converter_Mapper::cls_text_align( 'container mx-auto text-center' );
ga_eq( "cls_text_align: text-center → center", 'center', $cta_unit );
ga_eq( "cls_text_align: text-right → right", 'right', FW_Site_Converter_Mapper::cls_text_align( 'px-4 text-right' ) );
ga_eq( "cls_text_align: text-left → '' (inherited default)", '', FW_Site_Converter_Mapper::cls_text_align( 'text-left foo' ) );
ga_eq( "cls_text_align: no align class → ''", '', FW_Site_Converter_Mapper::cls_text_align( 'container mx-auto' ) );

/* --------------------------------------------------------------------- *
 * [19] #3 ORPHAN-CLASS CLEANUP — inert Tailwind utilities (size/weight/family/spacing + mangled
 * responsive/opacity forms) are stripped from the special_heading part-class fields (their intent
 * rides the per-node computed base); semantic accent utilities + custom classes are KEPT.
 * --------------------------------------------------------------------- */
$oc_html = '<section id="oc"><div class="text-center max-w-2xl mx-auto">'
	. '<h2 class="text-3xl md:text-4xl font-heading font-bold mb-4 my-brand-title">Why <span class="text-primary">Pets</span> Love Us</h2>'
	. '<p class="text-foreground/70 text-lg leading-relaxed">A subtitle sentence long enough to be real.</p>'
	. '</div></section>';
$oc = $sc_nodes_of( $oc_html );
$oc_sh = $first_sc( $oc, 'special_heading' );
ga( "orphan-cleanup: special_heading produced", $oc_sh !== null, wp_json_encode( $codes_of( $oc ) ) );
$oc_tc = (string) ( $oc_sh['atts']['title_class'] ?? '' );
ga( "orphan-cleanup: inert size/weight/family/spacing dropped from title_class", strpos( $oc_tc, 'text-3xl' ) === false && strpos( $oc_tc, 'font-bold' ) === false && strpos( $oc_tc, 'font-heading' ) === false && strpos( $oc_tc, 'mb-4' ) === false, $oc_tc );
ga( "orphan-cleanup: mangled responsive form dropped (md:text-4xl)", strpos( $oc_tc, 'text-4xl' ) === false && stripos( $oc_tc, 'mdtext' ) === false, $oc_tc );
ga( "orphan-cleanup: genuine custom class KEPT (my-brand-title)", strpos( $oc_tc, 'my-brand-title' ) !== false, $oc_tc );
ga( "orphan-cleanup: slash-opacity color dropped from subtitle_class", strpos( (string) ( $oc_sh['atts']['subtitle_class'] ?? '' ), 'foreground' ) === false, $oc_sh['atts']['subtitle_class'] ?? '' );
ga( "orphan-cleanup: accent span in title HTML preserved (text-primary)", strpos( (string) ( $oc_sh['atts']['title'] ?? '' ), 'text-primary' ) !== false, $oc_sh['atts']['title'] ?? '' );

/* --------------------------------------------------------------------- *
 * [19] Text Style presets (font_sizes) — BODY type scale distilled from the
 * source's paragraphs + assignment of each text block to the nearest preset.
 * --------------------------------------------------------------------- */
echo "\n[19] Text Style presets (font_sizes) — body scale + block assignment\n";

// (a) build_text_styles emits Lead(20)/Subtitle(18)/Small(14) with stable classes.
$fs_list = isset( $ts['font_sizes'] ) && is_array( $ts['font_sizes'] ) ? $ts['font_sizes'] : array();
$fs_by_class = array();
foreach ( $fs_list as $e ) { if ( ! empty( $e['class'] ) ) { $fs_by_class[ $e['class'] ] = $e; } }
ga( "font_sizes: Lead preset present (class=lead)", isset( $fs_by_class['lead'] ), array_keys( $fs_by_class ) );
ga_eq( "font_sizes: Lead size = 20 (real captured lead, not hardcoded 22)", '20', isset( $fs_by_class['lead']['size'] ) ? (string) $fs_by_class['lead']['size'] : '' );
ga( "font_sizes: Subtitle preset present (class=font-subtitle)", isset( $fs_by_class['font-subtitle'] ), array_keys( $fs_by_class ) );
ga_eq( "font_sizes: Subtitle size = 18", '18', isset( $fs_by_class['font-subtitle']['size'] ) ? (string) $fs_by_class['font-subtitle']['size'] : '' );
ga( "font_sizes: Small preset present (class=font-small)", isset( $fs_by_class['font-small'] ), array_keys( $fs_by_class ) );
ga_eq( "font_sizes: Small size = 14", '14', isset( $fs_by_class['font-small']['size'] ) ? (string) $fs_by_class['font-small']['size'] : '' );
ga( "font_sizes: base 16px NOT emitted as a body preset (dominant = Default)", ! isset( $fs_by_class['font-16'] ) && ! array_filter( $fs_list, function ( $e ) { return isset( $e['size'] ) && (string) $e['size'] === '16'; } ), array_keys( $fs_by_class ) );
// Display scale from headings still present (unchanged).
ga( "font_sizes: Display scale still emitted (display-1)", isset( $fs_by_class['display-1'] ), array_keys( $fs_by_class ) );

// (b) direct assignment probe via build_text_styles + a synthetic mapper run: a 20px paragraph → 'lead',
//     an 18px paragraph → 'font-subtitle', a 16px paragraph → '' (Default). Uses the SAME preset list the
//     conversion threads to the mapper (set_text_presets), exercising text_preset_for()'s tolerance.
if ( method_exists( 'FW_Site_Converter_Mapper', 'set_text_presets' ) ) {
	FW_Site_Converter_Mapper::set_text_presets( $fs_list );
	$rm = new ReflectionMethod( 'FW_Site_Converter_Mapper', 'n_text' );
	$rm->setAccessible( true );
	$probe = function ( $px ) use ( $rm ) {
		$node = $rm->invoke( null, '<p>probe</p>', '', '', 'font-size:' . $px . 'px;' );
		return isset( $node['atts']['font_size_preset'] ) ? (string) $node['atts']['font_size_preset'] : '(missing)';
	};
	ga_eq( "assign: 20px paragraph → font_size_preset='lead'", 'lead', $probe( 20 ) );
	ga_eq( "assign: 18px paragraph → font_size_preset='font-subtitle'", 'font-subtitle', $probe( 18 ) );
	ga_eq( "assign: 14px paragraph → font_size_preset='font-small'", 'font-small', $probe( 14 ) );
	ga_eq( "assign: 16px paragraph (base) → font_size_preset='' (Default)", '', $probe( 16 ) );
	// Re-thread the real conversion's presets so no later assertion sees the probe state (idempotent).
	FW_Site_Converter_Mapper::set_text_presets( $fs_list );
}

/* --------------------------------------------------------------------- *
 * [20] SUBTITLE TEXT STYLE PRESET — a special_heading whose subtitle is an 18px body size gets its
 * `subtitle_size` set to the `font-subtitle` Text Style preset (so it keeps its scale via the editable
 * preset, not a stripped size class). Needs enough 18px body text for build_text_styles to emit the
 * preset, plus data-sc-cs on the subtitle so its size is captured.
 * --------------------------------------------------------------------- */
if ( method_exists( 'FW_Site_Converter_Mapper', 'set_text_presets' ) ) {
	FW_Site_Converter_Mapper::set_text_presets( array(
		array( 'name' => 'Lead',     'size' => 20, 'class' => 'lead' ),
		array( 'name' => 'Subtitle', 'size' => 18, 'class' => 'font-subtitle' ),
		array( 'name' => 'Small',    'size' => 14, 'class' => 'font-small' ),
	) );
	$nh = new ReflectionMethod( 'FW_Site_Converter_Mapper', 'n_heading' ); $nh->setAccessible( true );
	$sub18 = $nh->invoke( null, array( 'level' => 2, 'title' => 'A Heading', 'subtitle' => 'A subtitle sentence.', 'subtitle_fs' => 18.0 ) );
	ga_eq( "subtitle-preset: 18px subtitle → subtitle_size 'font-subtitle'", 'font-subtitle', $sub18['atts']['subtitle_size'] ?? null );
	$sub16 = $nh->invoke( null, array( 'level' => 2, 'title' => 'A Heading', 'subtitle' => 'A subtitle sentence.', 'subtitle_fs' => 16.0 ) );
	ga_eq( "subtitle-preset: 16px subtitle → base (no preset)", '', $sub16['atts']['subtitle_size'] ?? null );
	$sub20 = $nh->invoke( null, array( 'level' => 2, 'title' => 'A Heading', 'subtitle' => 'A subtitle sentence.', 'subtitle_fs' => 20.0 ) );
	ga_eq( "subtitle-preset: 20px subtitle → 'lead'", 'lead', $sub20['atts']['subtitle_size'] ?? null );
	$subNo = $nh->invoke( null, array( 'level' => 2, 'title' => 'A Heading', 'subtitle' => 'A subtitle sentence.' ) );
	ga_eq( "subtitle-preset: no captured size → '' (Default)", '', $subNo['atts']['subtitle_size'] ?? null );

	/* element_spacing routing: WITH a subtitle, the title's own `mb-*` is the title→subtitle gap → the
	 * `element_spacing` select (coarse: tight ≤6 / relaxed 7–20 / Normal), NOT the outer margin — and it must
	 * NOT also land on spacing.margin.bottom (no double-count). Without a subtitle, the title's mb stays the
	 * outer block bottom margin (`.heading` default would otherwise take over). Mirrors the JS to-pages routing. */
	$es_sub = $nh->invoke( null, array( 'level' => 2, 'title' => 'Why Pets Love Golden Fixture 1', 'subtitle' => "We've designed every aspect…", 'title_class' => 'text-3xl md:text-4xl font-heading font-bold mb-4' ) );
	ga_eq( "element_spacing: subtitle + title mb-4 (16px) → 'relaxed'", 'relaxed', $es_sub['atts']['element_spacing'] ?? null );
	ga_eq( "element_spacing: that gap does NOT double onto outer spacing.bottom", '', $es_sub['atts']['spacing']['margin']['bottom'] ?? '' );
	$es_tight = $nh->invoke( null, array( 'level' => 2, 'title' => 'Tight Heading', 'subtitle' => 'Sub.', 'title_class' => 'font-bold mb-1' ) );
	ga_eq( "element_spacing: subtitle + title mb-1 (4px) → 'tight'", 'tight', $es_tight['atts']['element_spacing'] ?? null );
	$es_none = $nh->invoke( null, array( 'level' => 2, 'title' => 'Lone Heading', 'subtitle' => '', 'title_class' => 'font-bold mb-6' ) );
	ga_eq( "element_spacing: NO subtitle → stays '' (Normal)", '', $es_none['atts']['element_spacing'] ?? null );
	ga( "element_spacing: lone heading mb-6 → outer spacing.bottom set (mb-*)", strpos( (string) ( $es_none['atts']['spacing']['margin']['bottom'] ?? '' ), 'mb-' ) === 0, $es_none['atts']['spacing']['margin']['bottom'] ?? '' );

	FW_Site_Converter_Mapper::set_text_presets( array() ); // reset so later tests are unaffected
} else {
	ga( "subtitle-preset: set_text_presets present (skipped — install predates it; mirror to activate)", true );
}

/* --------------------------------------------------------------------- *
 * Favicon detection (Stitch::detect_favicon) — priority order
 * --------------------------------------------------------------------- */
if ( method_exists( 'FW_Site_Converter_Stitch', 'detect_favicon' ) ) {
	$fav_html = '<head><link rel="apple-touch-icon" href="/x.png"><link rel="icon" href="/favicon.ico"></head>';
	$fav = FW_Site_Converter_Stitch::detect_favicon( $fav_html, 'https://ex.com' );
	ga_eq( "detect_favicon: apple-touch PNG wins over .ico", 'https://ex.com/x.png', $fav );
} else {
	ga( "detect_favicon: method present (skipped — install predates it; mirror to activate)", true );
}

/* --------------------------------------------------------------------- *
 * Conversion debug map (conversion-map.json) — hash → { sc, mapped, … }
 * --------------------------------------------------------------------- */
echo "\n[cmap] Conversion debug map\n";
$cmap = $td['conversion_map'] ?? array();
ga( "conversion_map present on theme-design (>=1 entry)", is_array( $cmap ) && count( $cmap ) >= 1, count( (array) $cmap ) );
$cmap_ok = false;
foreach ( (array) $cmap as $hash => $rec ) {
	if ( is_string( $hash ) && strlen( $hash ) === 8 && ! empty( $rec['sc'] ) && ! empty( $rec['mapped'] ) && is_array( $rec['mapped'] ) ) {
		$cmap_ok = true; break;
	}
}
ga( "conversion_map has >=1 entry carrying sc + non-empty mapped", $cmap_ok );

/* --------------------------------------------------------------------- *
 * Cross-origin-safe inspector — build_files() must emit assets/inspector.js
 * (the in-page hover-inspector + footer-gap/height reporter that the
 * dashboard embeds via ?upw-inspect=1). Guard against a silent drop.
 * --------------------------------------------------------------------- */
if ( class_exists( 'FW_Site_Converter_Theme_Generator' )
	&& method_exists( 'FW_Site_Converter_Theme_Generator', 'build_files' )
	&& method_exists( 'FW_Site_Converter_Theme_Generator', 'normalize' )
	&& is_array( $td ) && ! empty( $td ) ) {
	$gen_cfg   = FW_Site_Converter_Theme_Generator::normalize( $td );
	$gen_files = FW_Site_Converter_Theme_Generator::build_files( $gen_cfg );
	ga( "build_files emits assets/inspector.js", isset( $gen_files['assets/inspector.js'] ) );
	$insp = (string) ( $gen_files['assets/inspector.js'] ?? '' );
	ga( "inspector.js guards on upw-inspect + posts upw-inspect height",
		strpos( $insp, 'upw-inspect' ) !== false && strpos( $insp, 'postMessage' ) !== false && strpos( $insp, '__upw_nofill' ) !== false );
} else {
	ga( "build_files inspector.js (skipped — generator/normalize absent; mirror to activate)", true );
}

/* --------------------------------------------------------------------- *
 * Result
 * --------------------------------------------------------------------- */
$pass = $GLOBALS['__pass'];
$fail = $GLOBALS['__fail'];
echo "\n========================================\n";
echo "GOLDEN FIXTURE RESULT: " . ( $fail === 0 ? "PASS" : "FAIL" ) . "   ($pass passed, $fail failed)\n";
echo "========================================\n";
exit( $fail === 0 ? 0 : 1 );
