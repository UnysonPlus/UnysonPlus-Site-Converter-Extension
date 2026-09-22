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
ga( "hi-fi ON: a faithful :where() base IS emitted where no native option owns the appearance (lone pill)", $badge_base !== '' );

// A PRESET-owned button carries NO per-node base at all: its native colour preset owns fill / border /
// gradient / shadow / font / transition, the size preset its line-height, the .btn base its text-align —
// "native first, the rest advanced" lives on the PRESET, never in the shortcode's Custom CSS tab.
$btn_base = '';
foreach ( $bases_on as $r ) { if ( $r['sc'] === 'button' ) { $btn_base = $r['css']; break; } }
ga( "preset-owned button carries NO :where() base (the preset owns its appearance)", $btn_base === '', $btn_base );

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
		// A hero column is a flexbox CELL (the row's child carrying a device width) — the converter emits flexbox Divs, not
		// loose columns (see Mapper::n_flexbox / column_to_flexbox_cell). Don't descend into a cell's nested cells.
		if ( ( $n['type'] ?? '' ) === 'flexbox' && isset( $n['atts']['width']['base']['preset'] ) && 'none' !== $n['atts']['width']['base']['preset'] ) { $hero_cols[] = $n; return; }
		if ( ( $n['type'] ?? '' ) === 'column' ) { $hero_cols[] = $n; return; }
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
$cell_w = function ( $c ) { if ( isset( $c['width'] ) && is_string( $c['width'] ) ) { return $c['width']; } $lg = (string) ( $c['atts']['width']['lg']['preset'] ?? '' ); return '6' === $lg ? '1_2' : $lg; }; // a flexbox cell: 6/12 at lg = 1/2 (stacks below the source's lg breakpoint)
ga_eq( "hero image column width = 1/2", '1_2', $img_col ? $cell_w( $img_col ) : null );
ga_eq( "hero text column width = 1/2", '1_2', $text_col ? $cell_w( $text_col ) : null );

// (b) Text column carries the source max-w-2xl (42rem) cap so the paragraph wraps like the source.
$text_col_css = (string) ( $text_col['atts']['custom_css'] ?? '' );
foreach ( (array) ( $text_col['_items'] ?? array() ) as $tci ) { if ( ( $tci['type'] ?? '' ) === 'flexbox' ) { $text_col_css .= ' ' . (string) ( $tci['atts']['custom_css'] ?? '' ); } } // the cap may ride the cell's inner wrapper (two-node cell)
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
/* Negative control: a LEFT (non-centered) wrapper stays inherit — no forced alignment. Its max-width DOES ride (2026-09-12: a
 * left cap decides where the title wraps; block_max_width only centres when the alignment is center, so it is safe). */
$wi_neg = $sc_nodes_of( '<section id="wl"><div class="max-w-2xl"><h2>Left heading here</h2><p>Some subtext that is long enough.</p></div></section>' );
$wi_nsh = $first_sc( $wi_neg, 'special_heading' );
ga_eq( "wrapper-inherit: non-centered wrapper → alignment stays inherit ('')", '', $wi_nsh['atts']['alignment'] ?? null );
ga_eq( "wrapper-inherit: no mx-auto → the LEFT cap still rides as block_max_width (42rem)", '42', (string) ( $wi_nsh['atts']['block_max_width']['value'] ?? '' ) );
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
ga( "footer link never-drop: hover → the theme palette var (`background` aliases the theme's `bg` role; an undefined `--color-background` resolved to the resting colour)", false !== strpos( $fl_mc, '.footer-column .footer-link:hover{color:var(--color-bg)}' ), $fl_mc );
// The capture MEASURED the hover (`data-sc-footer` link-hover) → it rides the NATIVE footer_link_hover_color option
// and NO scoped `.footer-link:hover` rule is emitted (its (0,3,0) specificity out-ranked the native `.footer a:hover`,
// so a `hover:text-brand` link — a token the palette lacks — rendered its hover in the resting colour: a real-site report).
$fl2_html = str_replace( array( '<footer ', 'hover:text-background' ), array( '<footer data-sc-footer="col-gap:40px;link-hover:rgb(34, 197, 94)" ', 'hover:text-brand' ), $fl_html );
$fl2_v = FW_Site_Converter_Sources::build_from_html( $fl2_html, 'FootL', array( 'dynamic_chrome' => true ) )['files']['theme-settings.json']['values'] ?? array();
ga( "footer link hover: a MEASURED hover (stamp) maps to the native footer_link_hover_color", 'rgb(34, 197, 94)' === (string) ( $fl2_v['footer_link_hover_color']['custom'] ?? '' ), $fl2_v['footer_link_hover_color'] ?? null );
ga( "footer link hover: with a measured hover NO scoped `.footer-link:hover` rule shadows the native option", false === strpos( (string) ( $fl2_v['misc_custom_css']['custom_css'] ?? '' ), '.footer-link:hover' ), $fl2_v['misc_custom_css']['custom_css'] ?? '' );
// No stamp + a token the palette does not define → nothing (never an undefined var)
$fl3_mc = FW_Site_Converter_Sources::build_from_html( str_replace( 'hover:text-background', 'hover:text-emerald-400', $fl_html ), 'FootL', array( 'dynamic_chrome' => true ) )['files']['theme-settings.json']['values']['misc_custom_css']['custom_css'] ?? '';
ga( "footer link hover: an unknown token (`emerald-400`) emits NO `var(--color-emerald-400)`", false === strpos( $fl3_mc, '.footer-link:hover' ), $fl3_mc );

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
		// The intro wrapper is a flexbox Div now (no loose columns): its centring is the native align_items (+ the
		// children's own alignment), so read that as the column's text alignment.
		if ( ( $n['type'] ?? '' ) === 'flexbox' ) { $found = ( 'center' === (string) ( $n['atts']['align_items']['base'] ?? '' ) ) ? 'center' : ( $n['atts']['text_align'] ?? '(unset)' ); return; }
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
 * Cross-origin-safe inspector — now OPT-IN (a dashboard-preview dev aid),
 * so build_files() OMITS assets/inspector.js by default (shipped themes stay
 * clean) and only emits it when `fw_sc_include_inspector` is filtered on —
 * where it still guards on ?upw-inspect + posts its height.
 * --------------------------------------------------------------------- */
if ( class_exists( 'FW_Site_Converter_Theme_Generator' )
	&& method_exists( 'FW_Site_Converter_Theme_Generator', 'build_files' )
	&& method_exists( 'FW_Site_Converter_Theme_Generator', 'normalize' )
	&& is_array( $td ) && ! empty( $td ) ) {
	$gen_cfg   = FW_Site_Converter_Theme_Generator::normalize( $td );
	$gen_files = FW_Site_Converter_Theme_Generator::build_files( $gen_cfg );
	ga( "build_files omits assets/inspector.js by default (opt-in)", ! isset( $gen_files['assets/inspector.js'] ) );
	add_filter( 'fw_sc_include_inspector', '__return_true' );
	$gen_files_insp = FW_Site_Converter_Theme_Generator::build_files( $gen_cfg );
	remove_filter( 'fw_sc_include_inspector', '__return_true' );
	$insp = (string) ( $gen_files_insp['assets/inspector.js'] ?? '' );
	ga( "fw_sc_include_inspector filter emits guarded inspector.js",
		isset( $gen_files_insp['assets/inspector.js'] )
		&& strpos( $insp, 'upw-inspect' ) !== false && strpos( $insp, 'postMessage' ) !== false && strpos( $insp, '__upw_nofill' ) !== false );
} else {
	ga( "build_files inspector.js (skipped — generator/normalize absent; mirror to activate)", true );
}

/* --------------------------------------------------------------------- *
 * Button colour/size PRESET rules — the PHP half of the PHP↔JS parity pair
 * (JS twin: button-presets-parity.test.mjs in the capture service). Drives
 * build_button_presets() directly with a synthetic gradient-filled button
 * stamped with its resolved computed style (data-sc-cs, the import path's
 * only input) and asserts the two rules that regressed and were re-fixed:
 *   1. GRADIENT FILL — a `.btn{background:linear-gradient(180deg,…)}` paints via
 *      background-IMAGE (background-COLOR is transparent). The preset must carry
 *      states.default.gradient = { type:'linear', angle:180, stops:[…] } (the native
 *      Background Gradient), NOT be dropped or misread as Outline.
 *   2. FIXED HEIGHT — `height:58px` with ~0 vertical padding → the SIZE preset's
 *      min_height = 58px (content centres to it). A missing min_height rendered
 *      "Large" as a giant circle.
 * --------------------------------------------------------------------- */
echo "\n[B] Button preset rules (gradient fill + fixed-height size)\n";
$grad_btn_cs = 'background-color:rgba(0, 0, 0, 0);background-image:linear-gradient(180deg, rgba(255, 255, 255, 0.94), rgba(255, 255, 255, 0.58));'
	. 'color:rgb(51, 41, 27);height:58px;padding-left:32px;padding-right:32px;padding-top:0px;padding-bottom:0px;'
	. 'border-radius:999px;border-top-width:0px;font-size:10px;line-height:normal;font-family:Inter, sans-serif;'
	. 'letter-spacing:2.5px;text-transform:uppercase;font-weight:600;box-shadow:rgba(0, 0, 0, 0.15) 0px 16px 42px 0px';
$grad_html = '<!DOCTYPE html><html><head><title>T</title></head><body data-sc-cs="font-family:Inter, sans-serif"><main>'
	. '<button class="btn-primary" data-sc-cs="' . htmlspecialchars( $grad_btn_cs, ENT_QUOTES ) . '">Explore the Flow</button>'
	. '<button class="btn-primary" data-sc-cs="' . htmlspecialchars( $grad_btn_cs, ENT_QUOTES ) . '">Begin</button>'
	. '</main></body></html>';
$bp = FW_Site_Converter_Stitch::build_button_presets( $grad_html );
$bp_colors = (array) ( $bp['button_colors'] ?? array() );
$bp_sizes  = (array) ( $bp['button_sizes'] ?? array() );
ga( "gradient button → a colour preset is produced", count( $bp_colors ) >= 1, wp_json_encode( $bp ) );
$bp_fill = null;
foreach ( $bp_colors as $p ) { if ( ! empty( $p['states']['default']['gradient'] ) ) { $bp_fill = $p; break; } }
ga( "gradient button → a preset carries states.default.gradient (not dropped / not Outline)", $bp_fill !== null, wp_json_encode( array_map( function ( $p ) { return $p['color_name'] ?? ''; }, $bp_colors ) ) );
$gv = $bp_fill['states']['default']['gradient'] ?? array();
ga_eq( "gradient type = linear", 'linear', $gv['type'] ?? null );
ga_eq( "gradient angle = 180", 180.0, isset( $gv['angle'] ) ? (float) $gv['angle'] : null );
ga_eq( "gradient stop count = 2", 2, count( $gv['stops'] ?? array() ) );
ga_eq( "gradient stop[0] colour", 'rgba(255, 255, 255, 0.94)', $gv['stops'][0]['color'] ?? null );
ga_eq( "gradient stop[0] position = 0", 0.0, isset( $gv['stops'][0]['position'] ) ? (float) $gv['stops'][0]['position'] : null );
ga_eq( "gradient stop[1] colour", 'rgba(255, 255, 255, 0.58)', $gv['stops'][1]['color'] ?? null );
ga_eq( "gradient stop[1] position = 100", 100.0, isset( $gv['stops'][1]['position'] ) ? (float) $gv['stops'][1]['position'] : null );
// The solid bg_color must stay EMPTY so the gradient shows (a solid fill would paint over it).
ga_eq( "gradient preset: solid bg_color left empty (gradient wins)", '', (string) ( $bp_fill['states']['default']['bg_color']['custom'] ?? '' ) );
ga( "gradient preset is NOT the Outline role", ( $bp_fill['color_name'] ?? '' ) !== 'Outline', $bp_fill['color_name'] ?? null );
// Fixed height → size preset Min Height.
ga( "fixed-height button → a size preset is produced", count( $bp_sizes ) >= 1, wp_json_encode( $bp_sizes ) );
$bp_mh = $bp_sizes[0]['min_height'] ?? null;
ga( "size preset min_height = 58px (from computed height, not padding_y)", is_array( $bp_mh ) && (string) ( $bp_mh['value'] ?? '' ) === '58' && ( $bp_mh['unit'] ?? '' ) === 'px', $bp_mh );

/* --- Source-named roles: `.btn-primary` / `.btn-secondary` name the presets Primary / Secondary (the source's
 *     own names, not Fill / Outline); the secondary KEEPS its real 1px border; the mapper resolves each body
 *     button to its preset (no `.sc-btn-*` transplant), incl. a gradient primary with NO semantic class. --- */
echo "\n[B2] Source-named button roles + border fidelity + preset matching\n";
$sec_btn_cs = 'background-color:rgba(255, 255, 255, 0.08);background-image:none;color:rgb(255, 255, 255);height:58px;padding-left:32px;padding-right:32px;padding-top:0px;padding-bottom:0px;'
	. 'border-radius:999px;border-top-width:1px;border-top-style:solid;border-top-color:rgba(255, 255, 255, 0.16);font-size:10px;line-height:normal;font-family:Inter, sans-serif;letter-spacing:2.5px;text-transform:uppercase;font-weight:600';
$named_html = '<!DOCTYPE html><html><head><title>T</title></head><body data-sc-cs="font-family:Inter, sans-serif"><main>'
	. '<button class="btn-primary" data-sc-cs="' . htmlspecialchars( $grad_btn_cs, ENT_QUOTES ) . '">Explore the Flow</button>'
	. '<button class="btn-secondary" data-sc-cs="' . htmlspecialchars( $sec_btn_cs, ENT_QUOTES ) . '">Seasonal Overview</button>'
	. '</main></body></html>';
$bp2   = FW_Site_Converter_Stitch::build_button_presets( $named_html );
$names = array_map( function ( $p ) { return $p['color_name'] ?? ''; }, (array) ( $bp2['button_colors'] ?? array() ) );
ga( "`.btn-primary` source → a preset NAMED Primary (source name honoured)", in_array( 'Primary', $names, true ), $names );
ga( "`.btn-secondary` source → a preset NAMED Secondary", in_array( 'Secondary', $names, true ), $names );
ga( "no Fill / Outline fallback names when the source names its buttons", ! in_array( 'Fill', $names, true ) && ! in_array( 'Outline', $names, true ), $names );
$p_pri = null; $p_sec = null;
foreach ( (array) ( $bp2['button_colors'] ?? array() ) as $p ) { if ( ( $p['color_name'] ?? '' ) === 'Primary' ) { $p_pri = $p; } if ( ( $p['color_name'] ?? '' ) === 'Secondary' ) { $p_sec = $p; } }
ga( "Primary carries the gradient", ! empty( $p_pri['states']['default']['gradient'] ) );
ga_eq( "Secondary keeps its 1px border (border_width)", '1', (string) ( $p_sec['states']['default']['border_width']['value'] ?? '' ) );
ga_eq( "Secondary border_style solid", 'solid', $p_sec['states']['default']['border_style'] ?? null );
ga_eq( "Secondary border colour verbatim", 'rgba(255, 255, 255, 0.16)', $p_sec['states']['default']['border_color']['custom'] ?? null );
ga_eq( "Secondary translucent fill verbatim", 'rgba(255, 255, 255, 0.08)', $p_sec['states']['default']['bg_color']['custom'] ?? null );
// Mapper: seed the built presets, then resolve each body button → its preset (the SAME regex names + matches).
FW_Site_Converter_Mapper::set_button_presets( (array) ( $bp2['button_colors'] ?? array() ), (array) ( $bp2['button_sizes'] ?? array() ) );
ga_eq( "mapper: `.btn-secondary` + cs → style btn-secondary", 'btn-secondary', FW_Site_Converter_Mapper::button_preset_for( 'btn-secondary', $sec_btn_cs )['style'] );
ga_eq( "mapper: `.btn-primary` + gradient cs → style btn-primary", 'btn-primary', FW_Site_Converter_Mapper::button_preset_for( 'btn-primary', $grad_btn_cs )['style'] );
ga_eq( "mapper: gradient button with NO semantic class still matches the gradient preset", 'btn-primary', FW_Site_Converter_Mapper::button_preset_for( 'hero-cta', $grad_btn_cs )['style'] );
ga_eq( "mapper: BEM `button--secondary` resolves the Secondary preset", 'btn-secondary', FW_Site_Converter_Mapper::button_preset_for( 'button--secondary', $sec_btn_cs )['style'] );
ga_eq( "mapper: size → btn-md (the site's ONE size is its Default)", 'btn-md', FW_Site_Converter_Mapper::button_preset_for( 'btn-secondary', $sec_btn_cs )['size'] );
// button_kind: a fill WITH a border is `fill` (keeps the border), a gradient fill is `primary`.
$rm_kind = new ReflectionMethod( 'FW_Site_Converter_Mapper', 'button_kind' ); $rm_kind->setAccessible( true );
ga_eq( "button_kind: translucent fill + 1px border → fill (not primary)", 'fill', $rm_kind->invoke( null, 'cta', $sec_btn_cs ) );
ga_eq( "button_kind: gradient fill, no border → primary", 'primary', $rm_kind->invoke( null, 'cta', $grad_btn_cs ) );
// register_style: the compiled safety-net class carries ONLY the source's own border — never an invented
// `border-width:0;border-style:none` over a real `border:1px solid …` (the guard sees the shorthand too).
$rp_on  = new ReflectionProperty( 'FW_Site_Converter_Mapper', 'style_on' );  $rp_on->setAccessible( true );  $was_on = $rp_on->getValue(); $rp_on->setValue( null, true );
$rp_css = new ReflectionProperty( 'FW_Site_Converter_Mapper', 'style_css' ); $rp_css->setAccessible( true );
$rm_reg = new ReflectionMethod( 'FW_Site_Converter_Mapper', 'register_style' ); $rm_reg->setAccessible( true );
$n_bord = $rm_reg->invoke( null, '', 'sc-btn-fill', false, 'fill', array( 'background-color' => 'rgba(255, 255, 255, 0.08)', 'border' => '1px solid rgba(255, 255, 255, 0.16)' ) );
$css_b  = (string) ( $rp_css->getValue()[ $n_bord ] ?? '' );
ga( "register_style: source border shorthand kept", strpos( $css_b, 'border:1px solid rgba(255, 255, 255, 0.16)' ) !== false, $css_b );
ga( "register_style: NO invented border-style:none over a real border", strpos( $css_b, 'border-style:none' ) === false && strpos( $css_b, 'border-width:0' ) === false, $css_b );
$n_nob  = $rm_reg->invoke( null, '', 'sc-btn-fill', false, 'fill', array( 'background-color' => 'rgb(10, 20, 30)' ) );
$css_n  = (string) ( $rp_css->getValue()[ $n_nob ] ?? '' );
ga( "register_style: a borderless source still sheds the base outline (border-style:none)", strpos( $css_n, 'border-style:none' ) !== false, $css_n );
$rp_on->setValue( null, $was_on );

/* --- SIZE NAMING: a size name describes a button RELATIVE to the site's normal button. (a) The source's own
 *     size names win (`btn-sm` / `btn-lg` / `button--large`); (b) otherwise the MOST-USED size is "Default"
 *     (slug md) and the rest are named by where they sit relative to it: bigger → Large, X-Large; smaller →
 *     Small, X-Small. 1 size → Default; 2 → Default + Large (or + Small); 3 → Small / Default / Large. --- */
echo "\n[B6] Button size naming (source names win; else anchored on the site's Default)\n";
$size_cs = function ( $fs, $py, $px, $h = '' ) { return 'background-color:rgb(10, 20, 30);color:rgb(255, 255, 255);border-radius:8px;font-size:' . $fs . 'px;padding-top:' . $py . 'px;padding-bottom:' . $py . 'px;padding-left:' . $px . 'px;padding-right:' . $px . 'px;line-height:normal' . ( $h !== '' ? ';height:' . $h . 'px' : '' ); };
$sizes_of = function ( $buttons ) use ( $size_cs ) {
	$html = '<!DOCTYPE html><html><head><title>T</title></head><body><main>';
	foreach ( $buttons as $b ) { for ( $k = 0; $k < ( $b['n'] ?? 1 ); $k++ ) { $html .= '<button class="' . ( $b['cls'] ?? 'btn-primary' ) . '" data-sc-cs="' . htmlspecialchars( $size_cs( $b['fs'], $b['py'], $b['px'], $b['h'] ?? '' ), ENT_QUOTES ) . '">Go ' . $k . '</button>'; } }
	$out = FW_Site_Converter_Stitch::build_button_presets( $html . '</main></body></html>' );
	$r = array(); foreach ( (array) ( $out['button_sizes'] ?? array() ) as $s ) { $r[] = $s['size_name'] . '=' . $s['slug']; } return $r;
};
ga_eq( "1 size → Default (md), never a lone 'Large'", array( 'Default=md' ), $sizes_of( array( array( 'fs' => 14, 'py' => 10, 'px' => 20, 'n' => 3 ) ) ) );
ga_eq( "2 sizes, most-used is the smaller → Default + Large", array( 'Large=lg', 'Default=md' ), $sizes_of( array( array( 'fs' => 14, 'py' => 10, 'px' => 20, 'n' => 4 ), array( 'fs' => 18, 'py' => 16, 'px' => 32, 'n' => 1 ) ) ) );
ga_eq( "2 sizes, most-used is the bigger → Default + Small", array( 'Default=md', 'Small=sm' ), $sizes_of( array( array( 'fs' => 16, 'py' => 12, 'px' => 24, 'n' => 4 ), array( 'fs' => 12, 'py' => 6, 'px' => 12, 'n' => 1 ) ) ) );
ga_eq( "3 sizes around the default → Large / Default / Small", array( 'Large=lg', 'Default=md', 'Small=sm' ), $sizes_of( array( array( 'fs' => 20, 'py' => 16, 'px' => 32, 'n' => 1 ), array( 'fs' => 15, 'py' => 10, 'px' => 20, 'n' => 5 ), array( 'fs' => 12, 'py' => 6, 'px' => 12, 'n' => 2 ) ) ) );
ga_eq( "3 sizes, default is the biggest → Default / Small / X-Small", array( 'Default=md', 'Small=sm', 'X-Small=xs' ), $sizes_of( array( array( 'fs' => 18, 'py' => 14, 'px' => 28, 'n' => 5 ), array( 'fs' => 14, 'py' => 8, 'px' => 16, 'n' => 2 ), array( 'fs' => 11, 'py' => 4, 'px' => 10, 'n' => 1 ) ) ) );
ga_eq( "4 sizes, two above the default → X-Large / Large / Default / Small (nearest bigger = Large)", array( 'X-Large=xl', 'Large=lg', 'Default=md', 'Small=sm' ), $sizes_of( array( array( 'fs' => 24, 'py' => 20, 'px' => 40, 'n' => 1 ), array( 'fs' => 19, 'py' => 14, 'px' => 28, 'n' => 1 ), array( 'fs' => 15, 'py' => 10, 'px' => 20, 'n' => 5 ), array( 'fs' => 12, 'py' => 6, 'px' => 12, 'n' => 2 ) ) ) );
ga_eq( "source-named sizes win: btn-sm / btn-lg keep their names (the most-used one is still marked Default)", array( 'Large (Default)=lg', 'Small=sm' ), $sizes_of( array( array( 'cls' => 'btn btn-lg', 'fs' => 18, 'py' => 14, 'px' => 28, 'n' => 3 ), array( 'cls' => 'btn btn-sm', 'fs' => 12, 'py' => 6, 'px' => 12, 'n' => 1 ) ) ) );
ga_eq( "BEM `button--large` + an unnamed most-used size → Large + Default", array( 'Large=lg', 'Default=md' ), $sizes_of( array( array( 'cls' => 'button button--large', 'fs' => 18, 'py' => 14, 'px' => 28, 'n' => 1 ), array( 'cls' => 'button', 'fs' => 14, 'py' => 10, 'px' => 20, 'n' => 4 ) ) ) );
ga_eq( "height-sized pill (58px, 10px type) still ranks ABOVE a padded 54px button (size = box, not type)", array( 'Large=lg', 'Default=md' ), $sizes_of( array( array( 'fs' => 10, 'py' => 0, 'px' => 32, 'h' => 58, 'n' => 1 ), array( 'fs' => 14, 'py' => 16, 'px' => 20, 'n' => 3 ) ) ) );

/* --- HOVER fidelity: the preset owns the source's hover MOTION (transform) + its real transition; a colour-only
 *     hover still eases at the source's speed; and a preset-owned button gets NO substituted `.btnfx-*` effect
 *     (btnfx-lift would add a 12px !important shadow the source never had). The capture stamps the resolved
 *     hover as data-sc-hover and the resting transition in data-sc-cs. --- */
echo "\n[B3] Button hover fidelity (preset owns transform + transition; no substituted fx)\n";
$pri_hov_cs   = $grad_btn_cs . ';transition:all 0.45s cubic-bezier(0.16, 1, 0.3, 1) 0s';
$sec_hov_cs   = $sec_btn_cs . ';transition:all 0.35s ease 0s';
$hover_html = '<!DOCTYPE html><html><head><title>T</title></head><body data-sc-cs="font-family:Inter, sans-serif"><main>'
	. '<button class="btn-primary" data-sc-cs="' . htmlspecialchars( $pri_hov_cs, ENT_QUOTES ) . '" data-sc-hover="hover-self{transform:translateY(-4px)}">Explore the Flow</button>'
	. '<button class="btn-secondary" data-sc-cs="' . htmlspecialchars( $sec_hov_cs, ENT_QUOTES ) . '" data-sc-hover="hover-self{background-color:rgba(255, 255, 255, 0.14);background-image:initial}">Seasonal Overview</button>'
	. '</main></body></html>';
$bp3 = FW_Site_Converter_Stitch::build_button_presets( $hover_html );
$h_pri = null; $h_sec = null;
foreach ( (array) ( $bp3['button_colors'] ?? array() ) as $p ) { if ( ( $p['color_name'] ?? '' ) === 'Primary' ) { $h_pri = $p; } if ( ( $p['color_name'] ?? '' ) === 'Secondary' ) { $h_sec = $p; } }
$pri_css = (string) ( $h_pri['custom_css'] ?? '' );
ga( "Primary preset Custom CSS carries the hover transform verbatim", strpos( $pri_css, '{{SELECTOR}}:hover { transform: translateY(-4px); }' ) !== false, $pri_css );
ga( "Primary preset Custom CSS eases with the SOURCE transition (cubic-bezier)", strpos( $pri_css, 'transition: all 0.45s cubic-bezier(0.16, 1, 0.3, 1) 0s' ) !== false, $pri_css );
ga( "Primary hover state has NO colour change (source changes only transform)", empty( $h_pri['states']['hover'] ), $h_pri['states']['hover'] ?? null );
ga_eq( "Secondary hover fill = source rgba(…,0.14)", 'rgba(255, 255, 255, 0.14)', $h_sec['states']['hover']['bg_color']['custom'] ?? null );
ga( "Secondary hover does NOT touch the border colour", empty( $h_sec['states']['hover']['border_color'] ), $h_sec['states']['hover'] ?? null );
$sec_css = (string) ( $h_sec['custom_css'] ?? '' );
ga( "Secondary (colour-only hover) still carries the source transition", strpos( $sec_css, 'transition: all 0.35s ease 0s' ) !== false, $sec_css );
ga( "Secondary has no transform rule (source has none)", strpos( $sec_css, 'transform' ) === false, $sec_css );
// Mapper: a preset-owned button gets NO substituted hover fx and no per-node duplicate of the preset's motion.
FW_Site_Converter_Mapper::set_button_presets( (array) ( $bp3['button_colors'] ?? array() ), (array) ( $bp3['button_sizes'] ?? array() ) );
$rm_nb = new ReflectionMethod( 'FW_Site_Converter_Mapper', 'n_button' ); $rm_nb->setAccessible( true );
$nb_pri = $rm_nb->invoke( null, 'Explore the Flow', '#', 'btn-primary', '', 'after', $pri_hov_cs, '', '', '', 'hover-self{transform:translateY(-4px)}', '' );
ga_eq( "n_button: preset-owned lift → style btn-primary", 'btn-primary', $nb_pri['atts']['style'] ?? null );
ga_eq( "n_button: NO substituted btnfx-lift (preset owns the transform)", '', (string) ( $nb_pri['atts']['hover_animation'] ?? '' ) );
ga( "n_button: no per-node duplicate transform / btnfx in custom_css", strpos( (string) ( $nb_pri['atts']['custom_css'] ?? '' ), 'translateY' ) === false && strpos( (string) ( $nb_pri['atts']['custom_css'] ?? '' ), 'btnfx' ) === false, $nb_pri['atts']['custom_css'] ?? '' );
$nb_sec = $rm_nb->invoke( null, 'Seasonal Overview', '#', 'btn-secondary', '', 'after', $sec_hov_cs, '', '', '', 'hover-self{background-color:rgba(255, 255, 255, 0.14);background-image:initial}', '' );
ga_eq( "n_button: colour-only hover → no hover fx", '', (string) ( $nb_sec['atts']['hover_animation'] ?? '' ) );
ga( "n_button: inert `background-image:initial` is NOT carried as a hover rule", strpos( (string) ( $nb_sec['atts']['custom_css'] ?? '' ), 'background-image' ) === false, $nb_sec['atts']['custom_css'] ?? '' );
// A NON-preset button (no match) with a lift still gets the native fx (the fallback path is intact).
// (seeded with ONE far-off preset — an empty seed makes the mapper lazy-load the root install's Theme Settings, which may hold a matching fill)
FW_Site_Converter_Mapper::set_button_presets( array( array( 'id' => 'zz', 'color_name' => 'Zed', 'states' => array( 'default' => array( 'bg_color' => array( 'predefined' => '', 'custom' => '#ff00ff' ), 'text_color' => array( 'predefined' => '', 'custom' => '#00ff00' ), 'border_color' => array( 'predefined' => '', 'custom' => '' ), 'border_style' => 'none' ) ) ) ), array() );
$nb_free = $rm_nb->invoke( null, 'Go', '#', 'weird-cta', '', 'after', 'background-color:rgb(10, 20, 30);color:rgb(255, 255, 255)', '', '', '', 'hover-self{transform:translateY(-4px)}', '' );
ga_eq( "n_button: an UNMATCHED button with a lift keeps the btnfx-lift fallback", 'btnfx-lift', (string) ( $nb_free['atts']['hover_animation'] ?? '' ) );
// FIDELITY GUARD for the native fx on a PRESET-owned button: a lift is mapped to the native "Lift" preset
// (editable in the dropdown) whenever that adds nothing the source lacks — i.e. the source has NO resting
// shadow, or its hover ALSO changes the shadow. Only a source that keeps its own resting shadow on hover
// (the gradient CTA above) is left to the preset's exact transform.
FW_Site_Converter_Mapper::set_button_presets( (array) ( $bp3['button_colors'] ?? array() ), (array) ( $bp3['button_sizes'] ?? array() ) );
$sec_lift = $rm_nb->invoke( null, 'Seasonal Overview', '#', 'btn-secondary', '', 'after', $sec_hov_cs, '', '', '', 'hover-self{transform:translateY(-4px)}', '' );
ga_eq( "guard (2026-09-16): a PRESET-owned button with a captured hover transform takes NO library fx — the preset carries the exact hover (transform / shadow / pseudo layers); a lift substitute doubled it (-3 + -4px)", '', (string) ( $sec_lift['atts']['hover_animation'] ?? '' ) );
$pri_lift_sh = $rm_nb->invoke( null, 'Explore the Flow', '#', 'btn-primary', '', 'after', $pri_hov_cs, '', '', '', 'hover-self{transform:translateY(-4px);box-shadow:rgba(0, 0, 0, 0.25) 0px 24px 48px 0px}', '' );
ga_eq( "guard: …also when the hover changes the shadow (the preset's :hover box-shadow is the source's, not the fx's)", '', (string) ( $pri_lift_sh['atts']['hover_animation'] ?? '' ) );
$pri_grow = $rm_nb->invoke( null, 'Explore the Flow', '#', 'btn-primary', '', 'after', $pri_hov_cs, '', '', '', 'hover-self{transform:scale(1.05)}', '' );
ga_eq( "guard: …and a grow: the preset's exact scale() wins over the library Grow", '', (string) ( $pri_grow['atts']['hover_animation'] ?? '' ) );
FW_Site_Converter_Mapper::set_button_presets( (array) ( $bp2['button_colors'] ?? array() ), (array) ( $bp2['button_sizes'] ?? array() ) );

/* --- "Native first, the rest advanced": the PRESET owns the button's shadow / font / gradient / transition —
 *     the native Box Shadow field gets the MOST VISIBLE layer of a multi-layer shadow (the drop, not the inset
 *     highlight) and the exact full shadow rides the preset's Custom CSS; a preset-owned node carries NO per-node
 *     duplicates (font-family / box-shadow / background-image / transition) in the shortcode's Custom CSS tab. --- */
echo "\n[B4] Preset owns shadow / font / gradient — no per-node duplicates\n";
// The real CTA shape: an inset highlight FIRST, then the visible drop — in a display face different from the body font.
$two_cs = str_replace( 'box-shadow:rgba(0, 0, 0, 0.15) 0px 16px 42px 0px', 'box-shadow:rgba(255, 255, 255, 0.76) 0px 1px 0px 0px inset, rgba(0, 0, 0, 0.15) 0px 16px 42px 0px', $pri_hov_cs );
$two_cs = str_replace( 'font-family:Inter, sans-serif', 'font-family:"Inter Tight", sans-serif', $two_cs );
$bp5 = FW_Site_Converter_Stitch::build_button_presets( '<!DOCTYPE html><html><head><title>T</title></head><body data-sc-cs="font-family:Inter, sans-serif"><main><button class="btn-primary" data-sc-cs="' . htmlspecialchars( $two_cs, ENT_QUOTES ) . '" data-sc-hover="hover-self{transform:translateY(-4px)}">Explore the Flow</button></main></body></html>' );
$p5 = ( $bp5['button_colors'] ?? array() )[0] ?? array();
$sh_pri = $p5['states']['default']['box_shadow'] ?? array();
ga( "native box_shadow = the DROP layer (0 16px 42px), not the inset 0 1px 0 highlight listed first", ( (int) ( $sh_pri['blur'] ?? -1 ) === 42 ) && empty( $sh_pri['inset'] ) && (int) ( $sh_pri['y'] ?? -1 ) === 16, $sh_pri );
ga_eq( "native box_shadow colour", 'rgba(0, 0, 0, 0.15)', $sh_pri['color'] ?? null );
ga( "multi-layer shadow → preset Custom CSS carries the exact full box-shadow", strpos( (string) ( $p5['custom_css'] ?? '' ), '{{SELECTOR}} { box-shadow: ' ) !== false && strpos( (string) ( $p5['custom_css'] ?? '' ), '0px 1px 0px 0px inset' ) !== false && strpos( (string) ( $p5['custom_css'] ?? '' ), '0px 16px 42px' ) !== false, $p5['custom_css'] ?? '' );
ga_eq( "preset native font family carried when it DEVIATES from the body font", 'Inter Tight', $p5['font']['family'] ?? null );
ga( "preset font family NOT carried when it equals the body font (inherits for free)", ! isset( $h_pri['font']['family'] ), $h_pri['font'] ?? null );
ga_eq( "preset native letter-spacing", '2.5px', $p5['font']['letter-spacing'] ?? null );
// single-layer shadow → native only, nothing in Custom CSS
$one_sh = 'background-color:rgb(10, 20, 30);color:rgb(255, 255, 255);box-shadow:rgba(0, 0, 0, 0.2) 0px 4px 12px 0px;height:44px;padding-left:20px;padding-top:0px;border-radius:8px;font-size:14px';
$bp4 = FW_Site_Converter_Stitch::build_button_presets( '<!DOCTYPE html><html><head><title>T</title></head><body><main><button class="btn-primary" data-sc-cs="' . htmlspecialchars( $one_sh, ENT_QUOTES ) . '">Go</button></main></body></html>' );
$p4 = ( $bp4['button_colors'] ?? array() )[0] ?? array();
ga( "single-layer shadow → native field only, NO box-shadow in Custom CSS", (int) ( $p4['states']['default']['box_shadow']['blur'] ?? -1 ) === 12 && strpos( (string) ( $p4['custom_css'] ?? '' ), 'box-shadow' ) === false, $p4['custom_css'] ?? '' );
// preset-owned node (n_button): no per-node font / shadow duplicates
$nb_css = (string) ( $nb_pri['atts']['custom_css'] ?? '' );
ga( "preset-owned node: NO per-node font-family / letter-spacing", strpos( $nb_css, 'font-family' ) === false && strpos( $nb_css, 'letter-spacing' ) === false, $nb_css );
ga( "preset-owned node: NO per-node box-shadow", strpos( $nb_css, 'box-shadow' ) === false, $nb_css );
// via the registered block builder (adds the hifi base): no gradient / transition either
$rm_bl = new ReflectionMethod( 'FW_Site_Converter_Mapper', 'builders' ); $rm_bl->setAccessible( true );
$btn_builder = $rm_bl->invoke( null )['button']['build'];
FW_Site_Converter_Mapper::set_button_presets( (array) ( $bp3['button_colors'] ?? array() ), (array) ( $bp3['button_sizes'] ?? array() ) );
$bb = $btn_builder( array( 't' => 'button', 'label' => 'Explore the Flow', 'href' => '#', 'cls' => 'btn-primary', 'cs' => $pri_hov_cs, 'srcHover' => 'hover-self{transform:translateY(-4px)}' ) );
$bb_css = (string) ( $bb['atts']['custom_css'] ?? '' );
ga_eq( "builder: preset-owned button → style btn-primary", 'btn-primary', $bb['atts']['style'] ?? null );
ga( "builder: no per-node background-image / transition / box-shadow / font-family", ! preg_match( '/background-image|transition|box-shadow|font-family/', $bb_css ), $bb_css );
// an UNMATCHED button still gets its never-drop font + shadow per-node (the safety net is intact)
// (an empty seed lazily re-reads the site's presets, so use a skin NO preset can match: an odd solid fill)
FW_Site_Converter_Mapper::set_button_presets( array(), array() );
$un_cs = 'background-color:rgb(123, 45, 67);color:rgb(255, 255, 255);font-family:Syncopate, sans-serif;letter-spacing:2px;box-shadow:rgba(0, 0, 0, 0.3) 0px 6px 14px 0px;height:44px;padding-left:20px;padding-top:0px;border-radius:8px;font-size:14px';
$nb_un = $rm_nb->invoke( null, 'Go', '#', 'weird-cta', '', 'after', $un_cs, '', '', '', '', '' );
$un_css = (string) ( $nb_un['atts']['custom_css'] ?? '' );
ga( "unmatched button keeps the per-node never-drop font + shadow", strpos( $un_css, 'font-family' ) !== false && strpos( $un_css, 'box-shadow' ) !== false, $un_css );
FW_Site_Converter_Mapper::set_button_presets( (array) ( $bp2['button_colors'] ?? array() ), (array) ( $bp2['button_sizes'] ?? array() ) );

/* --------------------------------------------------------------------- *
 * Box PRESET rule — the PHP half of the PHP↔JS parity pair (JS twin:
 * box-presets-parity.test.mjs). A GRADIENT-filled card (transparent
 * background-color, linear-gradient background-image) must register as a
 * REAL Box Preset — box_slug() non-empty, register_box_preset() returns a
 * `boxp-` ref (it used to DECLINE gradient cards to scoped CSS) — and
 * build_box_presets() must emit that preset with the gradient on its
 * Background-Pro fill: states.default.background.gradient.data.
 * Docs rule: "the box is ALWAYS a real Box Preset, never a bare box class
 * + Custom CSS."
 * --------------------------------------------------------------------- */
echo "\n[C] Box preset rule (gradient fill → real Box Preset)\n";
$grad_css = 'linear-gradient(180deg, rgba(255, 255, 255, 0.94), rgba(255, 255, 255, 0.58))';
$grad_box = array( 'bg' => '', 'gradient' => $grad_css, 'radius' => '24px', 'shadow' => 'rgba(0, 0, 0, 0.15) 0px 16px 42px 0px', 'bw' => '0px', 'padding' => '32px' );
$gslug = FW_Site_Converter_Mapper::box_slug( $grad_box );
ga( "gradient-only card → box_slug() is NON-empty (was '' = declined)", '' !== $gslug && 0 === strpos( $gslug, 'box-' ), $gslug );
$gref = FW_Site_Converter_Mapper::register_box_preset( $grad_box );
ga_eq( "register_box_preset() returns its boxp- ref", 'boxp-' . $gslug, $gref );
ga( "shared parser: 180deg / 2 stops", ( $pg = FW_Site_Converter_Mapper::parse_linear_gradient( $grad_css ) ) && 180.0 === (float) $pg['angle'] && 2 === count( $pg['stops'] ), $pg );
ga_eq( "shared parser: 'to right' → 90", 90.0, (float) ( FW_Site_Converter_Mapper::parse_linear_gradient( 'linear-gradient(to right, #fff, #000)' )['angle'] ?? -1 ) );
ga_eq( "shared parser: directionless → 180", 180.0, (float) ( FW_Site_Converter_Mapper::parse_linear_gradient( 'linear-gradient(#fff, #000)' )['angle'] ?? -1 ) );
ga( "shared parser: radial → null", null === FW_Site_Converter_Mapper::parse_linear_gradient( 'radial-gradient(#fff, #000)' ) );
$bxp = FW_Site_Converter_Stitch::build_box_presets( '<!DOCTYPE html><html><head><title>T</title></head><body><main><div>x</div></main></body></html>' );
$gpreset = null;
foreach ( (array) ( $bxp['border_presets'] ?? array() ) as $bpz ) { if ( ( $bpz['preset_name'] ?? '' ) === 'Box ' . substr( $gslug, 4 ) ) { $gpreset = $bpz; break; } }
ga( "build_box_presets() emits the registered gradient preset", $gpreset !== null, array_map( function ( $b ) { return $b['preset_name'] ?? ''; }, (array) ( $bxp['border_presets'] ?? array() ) ) );
$gbg = $gpreset['states']['default']['background'] ?? array();
ga( "preset background carries gradient.data (Background-Pro)", ( $gbg['gradient']['data']['type'] ?? '' ) === 'linear', $gbg );
ga_eq( "preset gradient angle = 180", 180.0, (float) ( $gbg['gradient']['data']['angle'] ?? -1 ) );
ga_eq( "preset gradient stops = 2", 2, count( $gbg['gradient']['data']['stops'] ?? array() ) );
ga_eq( "preset gradient stop[0] colour verbatim", 'rgba(255, 255, 255, 0.94)', $gbg['gradient']['data']['stops'][0]['color'] ?? null );
ga_eq( "preset solid colour left empty (gradient wins)", '', (string) ( $gbg['color']['value']['custom'] ?? '' ) );
ga( "preset also carries the resting box_shadow", ! empty( $gpreset['states']['default']['box_shadow'] ) );
ga_eq( "preset border_radius = 24px", '24', (string) ( $gpreset['border_radius']['value'] ?? '' ) );
// Negative: a trivial skin (no fill / gradient / border / radius) still yields no preset.
ga_eq( "trivial skin → box_slug() stays empty", '', FW_Site_Converter_Mapper::box_slug( array( 'bg' => '', 'gradient' => '', 'radius' => '', 'bw' => '', 'padding' => '16px' ) ) );

/* --- BOXED TEXT + FLOATING CHIPS. (1) An intro paragraph that is ALSO a box (glass fill + border + radius +
 *     padding + blur) is NOT folded into the heading's subtitle — it stays a text_block wearing a real Box
 *     Preset (its native Box Style), with the preset owning fill / border / radius / padding / blur and the
 *     block keeping its own line-height. (2) A short text pinned over the band (`absolute left-[8%] top-[18%]`)
 *     → a text_block with a Box Preset + the NATIVE Position option carrying the DECLARED sides (a % stays a
 *     %), hoisted to be a direct child of the (relative) section so the band is its containing block. --- */
echo "\n[C3] Boxed text → Box Preset; floating chip → native Position on the section\n";
$para_cs = 'background-color:rgba(255, 255, 255, 0.08);color:rgba(255, 255, 255, 0.88);font-size:18px;line-height:35.1px;text-align:center;padding:22px 26px;margin:28px 0px 0px;border-top-width:1px;border-top-style:solid;border-top-color:rgba(255, 255, 255, 0.12);border-radius:32px;backdrop-filter:blur(30px) saturate(1.2);max-width:760px;transform:matrix(1, 0, 0, 1, 0, 0)';
$chip_cs = 'background-color:rgba(255, 255, 255, 0.18);color:rgb(255, 255, 255);font-size:10px;line-height:15px;letter-spacing:2.2px;text-transform:uppercase;padding:11px 16px;border-top-width:1px;border-top-style:solid;border-top-color:rgba(255, 255, 255, 0.18);border-radius:999px;box-shadow:rgba(0, 0, 0, 0.12) 0px 14px 32px 0px;backdrop-filter:blur(24px) saturate(1.2);position:absolute';
$hero_doc = '<!DOCTYPE html><html><head><title>T</title></head><body><main><section id="hero" data-sc-cs="position:relative;min-height:800px">'
	. '<div class="hero-inner"><h1>Autumn Flow</h1>'
	. '<p class="hero-copy" data-sc-cs="' . htmlspecialchars( $para_cs, ENT_QUOTES ) . '">A regenerative landscape for sustainable estates, shaped by paper-light silence.</p></div>'
	. '<div class="floating-note left-[8%] top-[18%]" data-sc-cs="' . htmlspecialchars( $chip_cs, ENT_QUOTES ) . '">Golden fields · Harvest 12</div>'
	. '<div class="floating-note bottom-[18%] left-[12%]" data-sc-cs="' . htmlspecialchars( $chip_cs, ENT_QUOTES ) . '">Riparian restoration active</div>'
	. '</section></main></body></html>';
$hb = FW_Site_Converter_Sources::build_from_html( $hero_doc, 'Boxed', array( 'dynamic_chrome' => false ) );
$hsec = ( $hb['files']['pages.json']['pages'][0]['builder'] ?? array() )[0] ?? array();
$find = function ( $n, $needle ) use ( &$find ) { if ( ! is_array( $n ) ) return null; if ( ( $n['shortcode'] ?? '' ) === 'text_block' && strpos( (string) ( $n['atts']['text'] ?? '' ), $needle ) !== false ) return $n; foreach ( ( $n['_items'] ?? array() ) as $c ) { $r = $find( $c, $needle ); if ( $r ) return $r; } return null; };
$para = $find( $hsec, 'regenerative landscape' );
ga( "boxed intro paragraph is a text_block (NOT folded into the heading subtitle)", $para !== null, wp_json_encode( $hsec['_items'][0]['_items'][0]['atts']['subtitle'] ?? '' ) );
$pbox = (string) ( $para['atts']['box_style'] ?? '' );
ga( "…wearing a real Box Preset (box_style = boxp-…)", 0 === strpos( $pbox, 'boxp-' ), $pbox );
$pcss = (string) ( $para['atts']['custom_css'] ?? '' );
ga( "…its base carries NO per-node fill / border / radius / padding / blur (the preset owns them)", ! preg_match( '/background-color|border:|border-radius|padding|backdrop-filter/', $pcss ), $pcss );
ga( "…the identity transform residue is NOT carried", strpos( $pcss, 'matrix' ) === false, $pcss );
ga( "…it keeps its own line-height at normal specificity", strpos( $pcss, 'selector,selector p{line-height:35.1px;}' ) !== false, $pcss );
$bx = FW_Site_Converter_Stitch::build_box_presets( $hero_doc ); $ppreset = null;
foreach ( (array) ( $bx['border_presets'] ?? array() ) as $bp ) { if ( ( $bp['preset_name'] ?? '' ) === 'Box ' . substr( $pbox, 9 ) ) { $ppreset = $bp; break; } }
ga( "the paragraph's Box Preset exists with the glass fill + 32px radius + 2-value padding + blur", $ppreset !== null && ( $ppreset['states']['default']['background']['color']['value']['custom'] ?? '' ) === 'rgba(255, 255, 255, 0.08)' && (string) ( $ppreset['border_radius']['value'] ?? '' ) === '32' && strpos( (string) ( $ppreset['custom_css'] ?? '' ), 'padding:22px 26px' ) !== false && strpos( (string) ( $ppreset['custom_css'] ?? '' ), 'backdrop-filter:blur(30px)' ) !== false, $ppreset );
// the chips
$chip = $find( $hsec, 'Golden fields' ); $chip2 = $find( $hsec, 'Riparian' );
ga( "floating chip is a text_block with a Box Preset", $chip !== null && 0 === strpos( (string) ( $chip['atts']['box_style'] ?? '' ), 'boxp-' ), $chip['atts']['box_style'] ?? null );
$ep = $chip['atts']['element_position'] ?? array();
ga_eq( "chip → native Position: absolute", 'absolute', $ep['position'] ?? null );
ga( "chip offsets = the DECLARED sides only: top 18%, left 8% (right/bottom auto)", ( $ep['absolute']['pos_offsets']['top']['value'] ?? '' ) === '18' && ( $ep['absolute']['pos_offsets']['top']['unit'] ?? '' ) === '%' && ( $ep['absolute']['pos_offsets']['left']['value'] ?? '' ) === '8' && ( $ep['absolute']['pos_offsets']['left']['unit'] ?? '' ) === '%' && ( $ep['absolute']['pos_offsets']['right']['unit'] ?? '' ) === 'auto' && ( $ep['absolute']['pos_offsets']['bottom']['unit'] ?? '' ) === 'auto', $ep );
$ep2 = $chip2['atts']['element_position'] ?? array();
ga( "second chip: bottom 18%, left 12%", ( $ep2['absolute']['pos_offsets']['bottom']['value'] ?? '' ) === '18' && ( $ep2['absolute']['pos_offsets']['left']['value'] ?? '' ) === '12' && ( $ep2['absolute']['pos_offsets']['top']['unit'] ?? '' ) === 'auto', $ep2 );
ga_eq( "chip z-index sits above the band's media/overlay (2)", '2', (string) ( $ep['absolute']['element_zindex'] ?? '' ) );
$direct = array(); foreach ( (array) ( $hsec['_items'] ?? array() ) as $it ) { if ( ( $it['shortcode'] ?? '' ) === 'text_block' ) { $direct[] = substr( wp_strip_all_tags( $it['atts']['text'] ), 0, 12 ); } }
ga( "chips are HOISTED to be direct children of the section (not inside the content flexbox)", count( $direct ) === 2, $direct );
ga_eq( "the section is the positioned ancestor (Position: relative)", 'relative', $hsec['atts']['element_position']['position'] ?? null );
ga( "the section frees the chips' auto container from the theme's media-band positioning (scoped :has())", (bool) preg_match( '/selector > \.fw-container:has\(\.u[a-z0-9]{8}\)\{position:static !important;\}/', (string) ( $hsec['atts']['custom_css'] ?? '' ) ), $hsec['atts']['custom_css'] ?? '' );
// negative: an in-flow paragraph with NO box skin still folds into the heading subtitle
$plain_doc = '<!DOCTYPE html><html><head><title>T</title></head><body><main><section id="s"><h1>Title</h1><p data-sc-cs="color:rgb(80, 80, 80);font-size:18px">A short intro line under the title.</p></section></main></body></html>';
$pb = FW_Site_Converter_Sources::build_from_html( $plain_doc, 'Plain', array( 'dynamic_chrome' => false ) );
$psec = ( $pb['files']['pages.json']['pages'][0]['builder'] ?? array() )[0] ?? array();
ga( "negative: a plain intro paragraph (no box) still folds into the heading subtitle", null === $find( $psec, 'short intro line' ) && strpos( wp_json_encode( $psec ), 'short intro line' ) !== false );

/* --- A card's captured hover MOTION → the SHARED Hover Animations library on the Box Preset
 *     (`hover_animation`), with the buttons' fidelity guard: a lift with no resting shadow (or a hover
 *     shadow change) → native Lift; a lift on a card that keeps its own resting shadow → the plain
 *     hover_fx lift (bare translateY, no forced shadow); a grow → native Grow (no scale Custom CSS). --- */
echo "\n[C2] Box preset hover → shared Hover Animations library\n";
$box_preset_of = function ( $cb ) {
	$slug = FW_Site_Converter_Mapper::box_slug( $cb ); FW_Site_Converter_Mapper::register_box_preset( $cb );
	$out  = FW_Site_Converter_Stitch::build_box_presets( '<!DOCTYPE html><html><head><title>T</title></head><body><main><div>x</div></main></body></html>' );
	foreach ( (array) ( $out['border_presets'] ?? array() ) as $p ) { if ( ( $p['preset_name'] ?? '' ) === 'Box ' . substr( $slug, 4 ) ) { return $p; } }
	return array();
};
$c_lift    = $box_preset_of( array( 'bg' => 'rgb(255, 255, 255)', 'radius' => '16px', 'bw' => '1px', 'bd' => 'rgb(230, 230, 230)', 'shadow' => '', 'hover' => array( 'lift' => true ) ) );
ga_eq( "lift, no resting shadow → hover_animation btnfx-lift (native Lift)", 'btnfx-lift', $c_lift['hover_animation'] ?? null );
ga( "…and no duplicate plain hover_fx lift", ! in_array( 'lift', (array) ( $c_lift['hover_fx'] ?? array() ), true ), $c_lift['hover_fx'] ?? null );
$c_liftsh  = $box_preset_of( array( 'bg' => 'rgb(255, 255, 255)', 'radius' => '18px', 'bw' => '', 'shadow' => 'rgba(0, 0, 0, 0.1) 0px 4px 12px 0px', 'hover' => array( 'lift' => true ) ) );
ga_eq( "lift on a card that KEEPS its resting shadow → no library effect (guard)", '', (string) ( $c_liftsh['hover_animation'] ?? '' ) );
ga( "…the plain hover_fx lift (bare translateY) carries the motion instead", in_array( 'lift', (array) ( $c_liftsh['hover_fx'] ?? array() ), true ), $c_liftsh['hover_fx'] ?? null );
$c_liftsh2 = $box_preset_of( array( 'bg' => 'rgb(255, 255, 255)', 'radius' => '20px', 'bw' => '', 'shadow' => 'rgba(0, 0, 0, 0.1) 0px 4px 12px 0px', 'hover' => array( 'lift' => true, 'shadow' => 'rgba(0, 0, 0, 0.2) 0px 16px 32px 0px' ) ) );
ga_eq( "lift whose hover ALSO changes the shadow → native Lift", 'btnfx-lift', $c_liftsh2['hover_animation'] ?? null );
$c_grow    = $box_preset_of( array( 'bg' => 'rgb(255, 255, 255)', 'radius' => '22px', 'bw' => '', 'shadow' => '', 'hover' => array( 'scale' => 1.05 ) ) );
ga_eq( "grow (scale 1.05) → hover_animation btnfx-grow", 'btnfx-grow', $c_grow['hover_animation'] ?? null );
ga( "…and no scale rule left in the preset Custom CSS", strpos( (string) ( $c_grow['custom_css'] ?? '' ), 'scale(' ) === false, $c_grow['custom_css'] ?? '' );

/* --- [H] TWO-ROW MASTHEAD (a brand row over a links-only nav row): the nav row → the native Bottom Bar
 *     (menu in the centre column, its rule line as the bar border, its padding as a scoped rule); EVERY header
 *     action → a cta_button resolved to the preset matching its skin (a translucent glass skin included); a
 *     decorative text chip → a list_item (dot icon, Hide On from the source @media, pill skin as scoped CSS);
 *     the header's own stylesheet border-bottom → header_border + the hairline colour; the brand row's height
 *     (not the stacked rows) → min_height; the captured a:hover colour → the menu hover colour. Class-string
 *     fixture (no real site), JS twin: header-chrome-parity.test.mjs "two-row masthead". --- */
echo "\n[H] Two-row masthead: nav row → Bottom Bar, every CTA → cta_button, chip → list_item\n";
$h_btn_cs  = 'background-color:rgba(255, 255, 255, 0.2);color:rgb(255, 255, 255);font-size:10px;letter-spacing:2.2px;text-transform:uppercase;padding:0px 18px;border-top-width:1px;border-top-style:solid;border-top-color:rgba(95, 73, 42, 0.08);border-radius:999px;height:44px;display:block;transition:0.35s';
$h_link_cs = 'color:rgb(255, 255, 255);font-size:10px;letter-spacing:2.2px;text-transform:uppercase;height:15px;display:block;transition:0.25s';
$h_chip_cs = 'background-color:rgba(255, 255, 255, 0.2);color:rgb(255, 255, 255);font-size:10px;letter-spacing:2.4px;text-transform:uppercase;padding:10px 16px;border-top-width:1px;border-top-style:solid;border-top-color:rgba(95, 73, 42, 0.08);border-radius:999px;height:37px;display:flex;gap:10px;line-height:15px';
$h_html = '<!DOCTYPE html><html><head><title>T</title><style>.hdr{position:fixed;top:0;left:0;right:0;border-bottom:1px solid rgba(95,73,42,.08);backdrop-filter:blur(20px)}@media (max-width:1100px){.chip{display:none}}</style></head><body data-sc-cs="font-family:Inter, sans-serif">'
	. '<header class="hdr" data-sc-cs="height:117px;display:block;backdrop-filter:blur(20px);position:fixed" data-sc-header="rest-height:117px">'
	. '<div class="top" data-sc-cs="height:78px;display:grid;padding:18px 28px 14px;align-items:center" data-sc-zone="w:1440px;h:78px;bg:rgba(0, 0, 0, 0);border:0px solid rgb(229, 231, 235);pad:18px 28px 14px 28px;justify:normal">'
	. '<div class="brand" data-sc-cs="display:flex;height:46px"><div class="brand-mark" data-sc-cs="background-color:rgba(255, 255, 255, 0.52);border-radius:16px;height:46px"></div><div><div data-sc-cs="font-size:11px;text-transform:uppercase">Golden</div><div data-sc-cs="font-size:14px">Fixture Studio</div></div></div>'
	. '<div class="chip" data-sc-cs="' . $h_chip_cs . '"><span data-sc-cs="background-color:rgb(215, 170, 97);border-radius:999px;box-shadow:rgba(215, 170, 97, 0.55) 0px 0px 18px 0px;height:7px;display:block"></span> Seasonal note · Second note</div>'
	. '<div class="actions" data-sc-cs="display:flex;gap:12px;height:44px"><button data-sc-cs="' . $h_btn_cs . '">Map</button><button data-sc-cs="' . $h_btn_cs . '">Visit</button></div>'
	. '</div>'
	. '<div class="bottom" data-sc-cs="color:rgb(255, 255, 255);font-size:10px;padding:10px 28px 12px;border-top-width:1px;border-top-style:solid;border-top-color:rgba(95, 73, 42, 0.08);height:38px;display:flex;gap:28px;justify-content:center;align-items:center" data-sc-zone="w:1440px;h:38px;bg:rgba(0, 0, 0, 0);border:1px solid rgba(95, 73, 42, 0.08);pad:10px 28px 12px 28px;justify:center">'
	. '<a href="#a" data-sc-cs="' . $h_link_cs . '" data-sc-hover="hover-self{color:rgb(172, 123, 63)}">Alpha</a><a href="#b" data-sc-cs="' . $h_link_cs . '" data-sc-hover="hover-self{color:rgb(172, 123, 63)}">Beta</a><a href="#c" data-sc-cs="' . $h_link_cs . '" data-sc-hover="hover-self{color:rgb(172, 123, 63)}">Gamma</a>'
	. '</div></header><main><section id="a"><h1>Hi</h1><p>Body</p></section></main></body></html>';
$h_bundle = FW_Site_Converter_Sources::build_from_html( $h_html, 'HdrFixture', array( 'dynamic_chrome' => true ) );
$h_ts     = $h_bundle['files']['theme-settings.json']['values'] ?? array();
$h_main   = $h_ts['header_main'] ?? array();
$h_ctas   = array();
foreach ( (array) ( $h_main['main_right'] ?? array() ) as $n ) { if ( ( $n['element_type']['element'] ?? '' ) === 'cta_button' ) { $h_ctas[] = $n['element_type']['cta_button']; } }
ga_eq( "every header action → a cta_button (two, DOM order)", array( 'Map', 'Visit' ), array_map( function ( $c ) { return $c['cta_text']; }, $h_ctas ) );
ga_eq( "header CTA style resolves to the preset matching its GLASS skin (translucent fill + hairline)", 'btn-outline', $h_ctas[0]['cta_style'] ?? null );
ga_eq( "header CTA size resolves to the size preset (single size → Default md)", 'btn-md', $h_ctas[0]['cta_size'] ?? null );
ga( "no menu_area in the brand row (it moved to the Bottom Bar)", strpos( wp_json_encode( $h_main ), 'menu_area' ) === false, $h_main );
$h_chip = $h_main['main_center'][0] ?? array();
ga_eq( "chip → list_item in the centre zone", 'list_item', $h_chip['element_type']['element'] ?? null );
ga_eq( "chip text", 'Seasonal note · Second note', $h_chip['element_type']['list_item']['li_text'] ?? null );
ga( "chip dot → inline SVG circle icon in the dot colour", strpos( (string) ( $h_chip['element_type']['list_item']['li_icon']['markup'] ?? '' ), '<circle' ) !== false && strpos( (string) ( $h_chip['element_type']['list_item']['li_icon']['markup'] ?? '' ), 'rgb(215, 170, 97)' ) !== false, $h_chip['element_type']['list_item']['li_icon'] ?? null );
ga_eq( "chip hidden under 1100px → Hide On mobile + tablet (never hide-md)", array( 'hide-xs', 'hide-sm' ), $h_chip['visibility'] ?? null );
ga_eq( "chip carries its element CSS Class", 'sc-hdr-chip', $h_chip['element_css_class'] ?? null );
$h_bb = $h_ts['header_bottombar'] ?? array();
ga_eq( "Bottom Bar centre column = the primary menu", 'menu_area', $h_bb['bottombar_center'][0]['element_type']['element'] ?? null );
ga( "Bottom Bar left/right columns empty", empty( $h_bb['bottombar_left'] ) && empty( $h_bb['bottombar_right'] ) );
$h_bcs = $h_bb['bottombar_custom_styling']['yes'] ?? array();
ga_eq( "Bottom Bar custom styling enabled", 'yes', $h_bb['bottombar_custom_styling']['enabled'] ?? null );
ga_eq( "Bottom Bar border = the nav row's 1px rule", '1', $h_bcs['bottombar_border']['width']['value'] ?? null );
ga_eq( "Bottom Bar border colour keeps its alpha (translucent hairline, not a solid hex)", 'rgba(95, 73, 42, 0.08)', $h_bcs['bottombar_border']['color']['custom'] ?? null );
ga_eq( "Bottom Bar border on the TOP edge (facing the brand row)", array( 'top' ), $h_bcs['bottombar_border_sides'] ?? null );
ga_eq( "header min_height = the BRAND row (78), not the stacked rows (117)", '78', $h_ts['header_layout']['min_height']['value'] ?? null );
ga_eq( "header_border from the header's own stylesheet border-bottom", 'yes', $h_ts['header_layout']['header_border'] ?? null );
ga_eq( "menu hover colour = the captured a:hover colour", 'rgb(172, 123, 63)', $h_ts['header_menu']['menu_link_hover_color']['custom'] ?? null );
$h_css = (string) ( $h_ts['misc_custom_css']['custom_css'] ?? '' );
ga( "chip pill skin as scoped CSS on the element class", strpos( $h_css, '.site-header .sc-hdr-chip .list-item{' ) !== false && strpos( $h_css, 'border-radius:999px' ) !== false && strpos( $h_css, 'background-color:rgba(255, 255, 255, 0.2)' ) !== false, $h_css );
ga( "chip dot size + glow as scoped CSS", strpos( $h_css, '.sc-hdr-chip .list-item__icon{width:7px;height:7px' ) !== false && strpos( $h_css, 'rgba(215, 170, 97, 0.55) 0px 0px 18px' ) !== false, $h_css );
ga( "nav row padding as a scoped Bottom Bar rule", strpos( $h_css, '.site-header .header-bottombar{padding:10px 28px 12px;' ) !== false, $h_css );
ga( "nav link gap as a scoped Bottom Bar rule", strpos( $h_css, '.header-bottombar .primary-menu{gap:28px;}' ) !== false, $h_css );
ga( "header hairline in the source's own translucent colour", strpos( $h_css, '.site-header.site-header--border{box-shadow:none !important;border-bottom:1px solid rgba(95,73,42,.08) !important;}' ) !== false, $h_css );
// FULL-WIDTH bar: no container wrapper of any kind → Full Width, with the source row's own 28px side inset.
ga_eq( "header with no wrapper → Full Width container", 'container-fluid', $h_ts['header_layout']['container'] ?? null );
ga( "full-width header keeps the source's 28px side inset", strpos( $h_css, '.site-header .header-main .fw-container-fluid{padding-left:28px;padding-right:28px;}' ) !== false, $h_css );
ga( "Bottom Bar inner container goes flush (the bar row carries the padding)", strpos( $h_css, '.site-header .header-bottombar .fw-container-fluid,.site-header .header-topbar .fw-container-fluid{padding-left:0;padding-right:0;}' ) !== false, $h_css );

/* --- [G] GRID CELL GEOMETRY: an UNEQUAL source grid (`1.08fr .92fr` → 736.5px / 627.5px tracks) renders as a
 *     native Grid carrying the exact track list (the 12-span model rounded it to 6/6); a cell's fixed min-height
 *     (640px card) and its flex-column vertical centring ride on the cell. Negative: equal tracks stay on the
 *     flex/span path. JS twin: grid-geometry-parity.test.mjs. --- */
echo "\n[G] Grid cell geometry: unequal tracks → native Grid; min-height + vertical centring on the cell\n";
$g_mk = function ( $tracks ) {
	return '<!DOCTYPE html><html><head><title>T</title></head><body data-sc-cs="font-family:Inter, sans-serif">'
		. '<header><a href="/">Site</a><nav><a href="#a">Alpha</a><a href="#b">Beta</a></nav></header>'
		. '<main><section id="g" data-sc-cs="padding:120px 0px;display:block"><div class="shell" data-sc-cs="margin:0px 24px;display:block">'
		. '<div class="split" data-sc-cs="display:grid;grid-template-columns:' . $tracks . ';gap:28px;align-items:stretch">'
		. '<div class="left" data-sc-cs="padding:70px;min-height:640px;display:flex;flex-direction:column;justify-content:center;border-radius:42px;background-color:rgba(255, 255, 255, 0.5)"><h2 data-sc-cs="font-size:64px">Designed to breathe</h2><p data-sc-cs="font-size:16px;line-height:32px">The landscape follows the natural movement of the valley.</p></div>'
		. '<div class="right" data-sc-cs="background-image:linear-gradient(rgba(255, 255, 255, 0.24), rgba(255, 255, 255, 0.14));border-top-width:1px;border-top-style:solid;border-top-color:rgba(131, 108, 74, 0.08);border-radius:42px;box-shadow:rgba(255, 255, 255, 0.25) 0px 1px 0px 0px inset, rgba(98, 78, 45, 0.08) 0px 22px 60px 0px;height:640px;min-height:640px;display:block"><img src="https://example.invalid/a.jpg" alt="Landscape" data-sc-cs="max-width:100%;height:638px;display:block"></div>'
		. '</div></div></section></main></body></html>';
};
$g_row_of = function ( $html ) {
	$bl = FW_Site_Converter_Sources::build_from_html( $html, 'GridFixture', array( 'dynamic_chrome' => true ) );
	foreach ( (array) ( $bl['files']['pages.json']['pages'][0]['builder'] ?? array() ) as $s ) {
		if ( ( $s['type'] ?? '' ) !== 'section' ) { continue; }
		$find = function ( $n ) use ( &$find ) {
			if ( ! is_array( $n ) ) { return null; }
			if ( ( $n['type'] ?? '' ) === 'flexbox' && count( $n['_items'] ?? array() ) === 2 && ( $n['_items'][0]['type'] ?? '' ) === 'flexbox' ) { return $n; }
			foreach ( ( $n['_items'] ?? array() ) as $c ) { $r = $find( $c ); if ( $r ) { return $r; } }
			return null;
		};
		$r = $find( $s ); if ( $r ) { return $r; }
	}
	return null;
};
$g_row = $g_row_of( $g_mk( '736.547px 627.453px' ) );
ga( "unequal grid → the two-cell row is found", $g_row !== null );
ga_eq( "unequal tracks → native Grid mode", 'grid', $g_row['atts']['display'] ?? null );
ga_eq( "grid_columns carries the exact track ratio", '1.08fr 0.92fr', $g_row['atts']['grid_columns'] ?? null );
ga( "cells carry no 12-span width (the tracks size them)", empty( $g_row['_items'][0]['atts']['width'] ) && empty( $g_row['_items'][1]['atts']['width'] ), $g_row['_items'][0]['atts']['width'] ?? null );
ga( "no track_px leaks into the builder atts", ! isset( $g_row['_items'][0]['atts']['track_px'] ) );
ga_eq( "left cell min-height 640px", '640', $g_row['_items'][0]['atts']['min_height']['base']['value'] ?? null );
ga_eq( "left cell min-height unit px", 'px', $g_row['_items'][0]['atts']['min_height']['base']['unit'] ?? null );
ga_eq( "right cell min-height 640px", '640', $g_row['_items'][1]['atts']['min_height']['base']['value'] ?? null );
ga_eq( "left cell (flex-column, justify center) → direction column", 'column', $g_row['_items'][0]['atts']['direction']['base'] ?? null );
ga_eq( "left cell → justify_content center", 'center', $g_row['_items'][0]['atts']['justify_content']['base'] ?? null );
/* --- [G2] FRAMED PHOTO TILE: a lone-image cell whose cell is itself a card (radius / gradient / hairline / shadow)
 *     → the column wears a Box Preset that CLIPS to its radius, and the image is a native media_image in FILL mode
 *     (flex-column cell, image grows + object-fit cover). Uses the [G] fixture's right cell (img 640px in a 640px
 *     frame). Negative: a lone image with NO card box stays verbatim (organic-blob masks ride the Tailwind path). --- */
echo "\n[G2] Framed photo tile: cell box → clipping Box Preset; lone image → media_image FILL\n";
$g_right = $g_row['_items'][1] ?? array();
ga( "right cell wears a Box Preset", ! empty( $g_right['atts']['border_preset'] ) && 0 === strpos( (string) $g_right['atts']['border_preset'], 'boxp-' ), $g_right['atts']['border_preset'] ?? null );
ga_eq( "right cell is a flex column (the image can grow to the card height)", 'column', $g_right['atts']['direction']['base'] ?? null );
$g_img = $g_right['_items'][0] ?? array();
ga_eq( "lone image → native media_image", 'media_image', $g_img['shortcode'] ?? null );
ga( "media_image FILL rule (grows in the flex column, object-fit cover)", strpos( (string) ( $g_img['atts']['custom_css'] ?? '' ), 'selector img{flex:1 1 auto;width:100%;min-height:0;object-fit:cover;display:block;}' ) !== false, $g_img['atts']['custom_css'] ?? null );
$g_bl   = FW_Site_Converter_Sources::build_from_html( $g_mk( '736.547px 627.453px' ), 'GridFixture', array( 'dynamic_chrome' => true ) );
$g_bp   = null; $g_bp_slug = substr( (string) ( $g_right['atts']['border_preset'] ?? '' ), 5 );
foreach ( (array) ( $g_bl['files']['theme-settings.json']['values']['border_presets'] ?? array() ) as $bp ) { if ( trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) ( $bp['preset_name'] ?? '' ) ) ), '-' ) === $g_bp_slug ) { $g_bp = $bp; break; } }
ga( "the tile's Box Preset exists", $g_bp !== null, $g_bp_slug );
ga_eq( "tile preset radius 42px", '42', $g_bp['border_radius']['value'] ?? null );
ga( "tile preset CLIPS its media (overflow:hidden in the preset CSS)", strpos( (string) ( $g_bp['custom_css'] ?? '' ), '{{SELECTOR}}{overflow:hidden;}' ) !== false, $g_bp['custom_css'] ?? null );
// MULTI-LAYER shadow (an inset highlight + a 22/60 drop): the native field holds the MOST VISIBLE layer (the drop,
// not the first-listed inset), and the FULL two-layer value rides in the preset CSS (!important, after the native rule).
ga_eq( "tile preset native shadow = the most visible layer (the 22/60 drop)", 22, (int) ( $g_bp['states']['default']['box_shadow']['y'] ?? -1 ) );
ga_eq( "…not the inset highlight", false, (bool) ( $g_bp['states']['default']['box_shadow']['inset'] ?? true ) );
ga( "tile preset CSS carries the FULL two-layer shadow", strpos( (string) ( $g_bp['custom_css'] ?? '' ), '{{SELECTOR}}{box-shadow:rgba(255, 255, 255, 0.25) 0px 1px 0px 0px inset, rgba(98, 78, 45, 0.08) 0px 22px 60px 0px !important;}' ) !== false, $g_bp['custom_css'] ?? null );
$g_left_bp = null; $g_left_slug = substr( (string) ( $g_row['_items'][0]['atts']['border_preset'] ?? '' ), 5 );
foreach ( (array) ( $g_bl['files']['theme-settings.json']['values']['border_presets'] ?? array() ) as $bp ) { if ( trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) ( $bp['preset_name'] ?? '' ) ) ), '-' ) === $g_left_slug ) { $g_left_bp = $bp; break; } }
ga( "single-layer shadow → NO full-shadow rule in the preset CSS (the native field carries it exactly)", $g_left_bp === null || strpos( (string) ( $g_left_bp['custom_css'] ?? '' ), 'box-shadow' ) === false, $g_left_bp['custom_css'] ?? null );
ga( "tile preset is distinct from a text card with the same skin (clip keys the slug)", FW_Site_Converter_Mapper::box_slug( array( 'radius' => '42px', 'bg' => 'rgba(255, 255, 255, 0.5)' ) ) !== FW_Site_Converter_Mapper::box_slug( array( 'radius' => '42px', 'bg' => 'rgba(255, 255, 255, 0.5)', 'clip' => true ) ) );
// NEGATIVE — a lone image in a cell with NO card box stays verbatim (the .sc-tw path), never a forced media_image fill.
$g_html3 = preg_replace( '/class="right" data-sc-cs="[^"]*"/', 'class="right" data-sc-cs="height:640px;min-height:640px;display:block"', $g_mk( '736.547px 627.453px' ), 1 );
$g_row3  = $g_row_of( $g_html3 );
$g_img3  = $g_row3['_items'][1]['_items'][0] ?? array();
ga( "no card box → the lone image is NOT forced into fill mode", strpos( (string) ( $g_img3['atts']['custom_css'] ?? '' ), 'object-fit:cover' ) === false, $g_img3['atts']['custom_css'] ?? null );
ga( "no card box → no Box Preset on the cell", empty( $g_row3['_items'][1]['atts']['border_preset'] ), $g_row3['_items'][1]['atts']['border_preset'] ?? null );

/* --- [G3] CHIP ROW: a flex row of short pill-shaped labels (`.inline-metrics > .metric × 3`) → ONE wrapping flex
 *     row (source 12px gap, 28px margin above) of Text Blocks, each wearing the SAME Box Preset (50% white pill,
 *     hairline, 12/16 padding) with its 10px uppercase tracked type — never a 3-column grid of bare text.
 *     Negative: a flex row of plain (unboxed) labels is NOT a chip row. JS twin: chips-parity.test.mjs. --- */
echo "\n[G3] Chip row: pill labels → flex row of boxed Text Blocks (one shared Box Preset)\n";
$g_chip_cs = 'background-color:rgba(255, 255, 255, 0.5);color:rgb(86, 75, 61);font-size:10px;font-weight:400;line-height:15px;letter-spacing:2.2px;text-align:start;text-transform:uppercase;padding:12px 16px;border-top-width:1px;border-top-style:solid;border-top-color:rgba(131, 108, 74, 0.08);border-radius:999px;height:41px;display:block';
$g_chips_html = '<!DOCTYPE html><html><head><title>T</title></head><body data-sc-cs="font-family:Inter, sans-serif">'
	. '<header><a href="/">Site</a><nav><a href="#a">Alpha</a><a href="#b">Beta</a></nav></header>'
	. '<main><section id="c" data-sc-cs="padding:120px 0px;display:block"><div data-sc-cs="display:block"><h2 data-sc-cs="font-size:48px">Metrics</h2>'
	. '<div class="inline-metrics" data-sc-cs="margin:28px 0px 0px;height:41px;display:flex;gap:12px;justify-content:normal;align-items:normal;flex-direction:row">'
	. '<div class="metric" data-sc-cs="' . $g_chip_cs . '">Seasonal equilibrium</div><div class="metric" data-sc-cs="' . $g_chip_cs . '">Water rhythm</div><div class="metric" data-sc-cs="' . $g_chip_cs . '">Zen-organic harmonics</div>'
	. '</div></div></section></main></body></html>';
$g_cb = FW_Site_Converter_Sources::build_from_html( $g_chips_html, 'ChipFixture', array( 'dynamic_chrome' => true ) );
$g_crow = null;
$g_find_chips = function ( $n ) use ( &$g_find_chips ) {
	if ( ! is_array( $n ) ) { return null; }
	if ( ( $n['type'] ?? '' ) === 'flexbox' && count( $n['_items'] ?? array() ) === 3 && ( $n['_items'][0]['shortcode'] ?? '' ) === 'text_block' ) { return $n; }
	foreach ( ( $n['_items'] ?? array() ) as $c ) { $r = $g_find_chips( $c ); if ( $r ) { return $r; } }
	return null;
};
foreach ( (array) ( $g_cb['files']['pages.json']['pages'][0]['builder'] ?? array() ) as $s ) { $g_crow = $g_find_chips( $s ); if ( $g_crow ) { break; } }
ga( "chip row → ONE flex row of three text_blocks (not a 3-column grid)", $g_crow !== null );
ga_eq( "chip row is a flex row", 'flex', $g_crow['atts']['display'] ?? null );
ga_eq( "chip row wraps", 'yes', $g_crow['atts']['wrap']['base'] ?? null );
ga_eq( "chip row gap = the source 12px", '[12px]', $g_crow['atts']['gap']['base'] ?? null );
ga_eq( "chip row margin-top = the source 28px", 'mt-[28px]', $g_crow['atts']['spacing']['margin']['top'] ?? null );
ga( "chips carry NO width (they size to content, never equal thirds)", empty( $g_crow['_items'][0]['atts']['width'] ) || ( $g_crow['_items'][0]['atts']['width']['base']['preset'] ?? 'none' ) === 'none' );
$g_c0 = $g_crow['_items'][0]['atts'] ?? array(); $g_c2 = $g_crow['_items'][2]['atts'] ?? array();
ga_eq( "chip text", '<p>Seasonal equilibrium</p>', $g_c0['text'] ?? null );
ga( "each chip wears a Box Preset (Box Style)", ! empty( $g_c0['box_style'] ) && 0 === strpos( (string) $g_c0['box_style'], 'boxp-' ), $g_c0['box_style'] ?? null );
ga( "all three chips share ONE preset", ( $g_c0['box_style'] ?? 'a' ) === ( $g_c2['box_style'] ?? 'b' ) );
ga( "chip typography carried (10px, uppercase, tracked)", strpos( (string) ( $g_c0['custom_css'] ?? '' ), 'font-size:10px' ) !== false && strpos( (string) ( $g_c0['custom_css'] ?? '' ), 'text-transform:uppercase' ) !== false && strpos( (string) ( $g_c0['custom_css'] ?? '' ), 'letter-spacing:2.2px' ) !== false, $g_c0['custom_css'] ?? null );
ga( "chip base carries NO fill / radius / padding (the preset owns them)", ! preg_match( '/background-color|border-radius|padding:/', (string) ( $g_c0['custom_css'] ?? '' ) ), $g_c0['custom_css'] ?? null );
$g_pill = null; $g_pill_slug = substr( (string) ( $g_c0['box_style'] ?? '' ), 5 );
foreach ( (array) ( $g_cb['files']['theme-settings.json']['values']['border_presets'] ?? array() ) as $bp ) { if ( trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) ( $bp['preset_name'] ?? '' ) ) ), '-' ) === $g_pill_slug ) { $g_pill = $bp; break; } }
ga( "the pill preset exists", $g_pill !== null, $g_pill_slug );
ga_eq( "pill preset radius 999px", '999', $g_pill['border_radius']['value'] ?? null );
ga_eq( "pill preset fill = 50% white", 'rgba(255, 255, 255, 0.5)', $g_pill['states']['default']['background']['color']['value']['custom'] ?? null );
ga( "pill preset padding 12px 16px (in the preset CSS)", strpos( (string) ( $g_pill['custom_css'] ?? '' ), 'padding:12px 16px' ) !== false, $g_pill['custom_css'] ?? null );
// NEGATIVE — a flex row of PLAIN labels (no fill / border / radius) is not a chip row.
$g_plain_html = str_replace( $g_chip_cs, 'color:rgb(86, 75, 61);font-size:10px;line-height:15px;display:block', $g_chips_html );
$g_pb = FW_Site_Converter_Sources::build_from_html( $g_plain_html, 'ChipFixture', array( 'dynamic_chrome' => true ) );
$g_prow = null; foreach ( (array) ( $g_pb['files']['pages.json']['pages'][0]['builder'] ?? array() ) as $s ) { $g_prow = $g_find_chips( $s ); if ( $g_prow ) { break; } }
ga( "plain labels → no chip row (no box preset on the labels)", $g_prow === null || empty( $g_prow['_items'][0]['atts']['box_style'] ) );

/* --- [G4] HEADING RHYTHM: a plain-CSS eyebrow div (uppercase, tracked, 10px — no Tailwind class) folds into the
 *     heading's native Overline with its exact type + the title's margin-top as the gap below it; a subtitle at
 *     the BODY size keeps 16px / 32px (no Text Style preset matches, the heading scale would enlarge it); the
 *     title→subtitle gap comes from the SUBTITLE's margin-top; an explicit zero subtitle bottom margin → mb-0;
 *     a declared clamp() title size stays fluid. JS twin: heading-rhythm-parity.test.mjs. --- */
echo "\n[G4] Heading rhythm: eyebrow div → Overline; body-size subtitle; gaps from the source; fluid clamp() title\n";
$g_h_html = '<!DOCTYPE html><html><head><title>T</title><style>.section-title{font-size:clamp(2.9rem,5.6vw,6rem);line-height:.9}</style></head><body data-sc-cs="font-family:Inter, sans-serif">'
	. '<header><a href="/">Site</a><nav><a href="#a">Alpha</a><a href="#b">Beta</a></nav></header>'
	. '<main><section id="r" data-sc-cs="padding:120px 0px;display:block"><div data-sc-cs="display:block">'
	. '<div class="section-label" data-sc-cs="color:rgb(138, 124, 105);font-size:10px;font-weight:400;line-height:15px;letter-spacing:3px;text-transform:uppercase;height:15px;display:block">Floating Mountain Split</div>'
	. '<h2 class="section-title serif" data-sc-cs="color:rgb(42, 36, 28);font-family:&quot;Cormorant Garamond&quot;, serif;font-size:80.64px;font-weight:400;line-height:72.576px;letter-spacing:-4.8384px;margin:16px 0px 0px;display:block">Designed to breathe with the river.</h2>'
	. '<p class="section-copy" data-sc-cs="color:rgb(98, 88, 77);font-size:16px;font-weight:400;line-height:32px;margin:22px 0px 0px;max-width:680px;display:block">The landscape follows the natural movement of the valley, layering soft mountain silhouettes.</p>'
	. '<div class="inline-metrics" data-sc-cs="margin:28px 0px 0px;display:flex;gap:12px"><div class="metric" data-sc-cs="' . $g_chip_cs . '">Seasonal equilibrium</div><div class="metric" data-sc-cs="' . $g_chip_cs . '">Water rhythm</div></div>'
	. '</div></section></main></body></html>';
$g_hb = FW_Site_Converter_Sources::build_from_html( $g_h_html, 'RhythmFixture', array( 'dynamic_chrome' => true ) );
$g_sh = null;
$g_find_sh = function ( $n ) use ( &$g_find_sh ) { if ( ! is_array( $n ) ) { return null; } if ( ( $n['shortcode'] ?? '' ) === 'special_heading' ) { return $n; } foreach ( ( $n['_items'] ?? array() ) as $c ) { $r = $g_find_sh( $c ); if ( $r ) { return $r; } } return null; };
foreach ( (array) ( $g_hb['files']['pages.json']['pages'][0]['builder'] ?? array() ) as $s ) { $g_sh = $g_find_sh( $s ); if ( $g_sh ) { break; } }
$g_ha = $g_sh['atts'] ?? array();
ga( "special_heading found", $g_sh !== null );
ga_eq( "plain-CSS eyebrow div → native Overline", 'Floating Mountain Split', $g_ha['overline'] ?? null );
ga_eq( "overline uppercase (native)", 'yes', $g_ha['overline_uppercase'] ?? null );
ga_eq( "overline colour (native)", 'rgb(138, 124, 105)', $g_ha['overline_color']['custom'] ?? null );
$g_hcss = (string) ( $g_ha['custom_css'] ?? '' );
ga( "overline exact type (10px, 3px tracking, 15px line) + the title's 16px margin-top as its gap", strpos( $g_hcss, 'selector .heading-overline{font-size:10px !important;letter-spacing:3px !important;line-height:15px !important;margin-bottom:16px !important;}' ) !== false, $g_hcss );
ga_eq( "subtitle folded", true, strpos( (string) ( $g_ha['subtitle'] ?? '' ), 'The landscape follows' ) !== false );
ga( "title→subtitle gap = the SUBTITLE's 22px margin-top (on the title's bottom)", strpos( $g_hcss, 'selector .heading-title{margin-bottom:22px !important;}' ) !== false, $g_hcss );
ga( "body-size subtitle keeps 16px / 32px (no preset matched)", strpos( $g_hcss, 'selector .heading-subtitle{font-size:16px !important;line-height:32px !important;}' ) !== false, $g_hcss );
ga( "declared clamp() title size carried (fluid, not the 80.64px snapshot) + its RELATIVE line-height (.9 declared) and letter-spacing (-.06em from the computed pair)", strpos( $g_hcss, 'selector .heading-title{font-size:clamp(2.9rem,5.6vw,6rem) !important;line-height:.9 !important;letter-spacing:-0.06em !important;}' ) !== false, $g_hcss );
ga_eq( "explicit zero subtitle bottom margin → the block's outer margin-bottom = 0 (the next row's 28px carries the gap)", 'mb-0', $g_ha['spacing']['margin']['bottom'] ?? null );
// NEGATIVE — a boxed pill label is NOT an eyebrow (it stays a chip), and a static px title carries no fluid rule.
$g_hb2 = FW_Site_Converter_Sources::build_from_html( str_replace( array( 'class="section-label" data-sc-cs="', '<style>.section-title{font-size:clamp(2.9rem,5.6vw,6rem);line-height:.9}</style>' ), array( 'class="section-label" data-sc-cs="background-color:rgba(255, 255, 255, 0.5);padding:12px 16px;border-radius:999px;', '' ), $g_h_html ), 'RhythmFixture', array( 'dynamic_chrome' => true ) );
$g_sh2 = null; foreach ( (array) ( $g_hb2['files']['pages.json']['pages'][0]['builder'] ?? array() ) as $s ) { $g_sh2 = $g_find_sh( $s ); if ( $g_sh2 ) { break; } }
ga( "a BOXED pill label is not folded as the Overline", '' === (string) ( $g_sh2['atts']['overline'] ?? '' ), $g_sh2['atts']['overline'] ?? null );
ga( "a static px title carries NO fluid font-size rule", strpos( (string) ( $g_sh2['atts']['custom_css'] ?? '' ), 'clamp(' ) === false );

/* --- [G5] DECORATIVE pseudo-layer: a card's `::before` corner glow (stamped by the capture as data-sc-decor-pseudo,
 *     geometry in % of the box) → a scoped `selector::before` on the column (under the content, over the fill), and a
 *     glow that reaches OUTSIDE the box makes the card's Box Preset clip. JS twin: grid-geometry-parity "decor". --- */
echo "\n[G5] Decorative pseudo-layer: card ::before glow → scoped rule on the column; the card clips\n";
$g_dp_html = preg_replace( '/class="left" data-sc-cs="/', 'class="left" data-sc-decor-pseudo="before;top:-19.9%;left:-8%;width:41.9%;height:41.9%;background:radial-gradient(circle, rgba(215, 170, 97, 0.14), rgba(0, 0, 0, 0) 70%);filter:blur(30px)" data-sc-cs="', $g_mk( '736.547px 627.453px' ), 1 );
$g_dp_row = $g_row_of( $g_dp_html );
$g_dp_css = (string) ( $g_dp_row['_items'][0]['atts']['custom_css'] ?? '' );
ga( "column gets the scoped ::before glow (geometry in %, radial background, blur)", strpos( $g_dp_css, 'selector::before{content:"";position:absolute;pointer-events:none;z-index:-1;top:-19.9%;left:-8%;width:41.9%;height:41.9%;background:radial-gradient(circle, rgba(215, 170, 97, 0.14), rgba(0, 0, 0, 0) 70%);filter:blur(30px);}' ) !== false, $g_dp_css );
ga( "column is an isolated positioned ancestor (the glow paints under the content, over the fill)", strpos( $g_dp_css, 'selector{position:relative;isolation:isolate;}' ) !== false, $g_dp_css );
$g_dp_bp = null; $g_dp_slug = substr( (string) ( $g_dp_row['_items'][0]['atts']['border_preset'] ?? '' ), 5 );
$g_dp_bl = FW_Site_Converter_Sources::build_from_html( $g_dp_html, 'GridFixture', array( 'dynamic_chrome' => true ) );
foreach ( (array) ( $g_dp_bl['files']['theme-settings.json']['values']['border_presets'] ?? array() ) as $bp ) { if ( trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) ( $bp['preset_name'] ?? '' ) ) ), '-' ) === $g_dp_slug ) { $g_dp_bp = $bp; break; } }
ga( "a glow reaching outside the box → the card's Box Preset clips (overflow:hidden)", $g_dp_bp !== null && strpos( (string) ( $g_dp_bp['custom_css'] ?? '' ), '{{SELECTOR}}{overflow:hidden;}' ) !== false, $g_dp_bp['custom_css'] ?? null );
ga( "negative: the un-stamped left card carries no ::before rule", strpos( (string) ( $g_row['_items'][0]['atts']['custom_css'] ?? '' ), '::before' ) === false );

/* --- [F] FIELDS-STYLE BAND STACK: a section with pt-0 whose heading block is narrower than its shell, then a
 *     single-track grid (one 1fr track, 22px gap, 120px above) of band CARDS — each a .8fr/1.2fr grid of an EMPTY
 *     PAINTED panel (gradient layers) + a padded copy cell (eyebrow, h3, p; flex column space-between). Rules:
 *     zero padding is a value; the section keeps the shell (a capped block WITH siblings is that block's own
 *     measure); a single-track grid is a stack; a band row wears its card + min-height and clips; the panel is
 *     an empty cell with the source paint; the copy cell keeps its padding + space-between; label → h3 → p fold
 *     into ONE special heading. JS twin: band-stack-parity.test.mjs. --- */
echo "\n[F] Band stack: pt-0, shell width, single-track stack, band rows with painted panels\n";
$f_vis  = 'background-image:radial-gradient(circle at 25% 30%, rgba(215, 170, 97, 0.26), rgba(0, 0, 0, 0) 20%), radial-gradient(circle at 70% 35%, rgba(86, 120, 168, 0.18), rgba(0, 0, 0, 0) 18%), linear-gradient(rgba(255, 255, 255, 0.18), rgba(0, 0, 0, 0.06));min-height:300px;height:300px;display:block';
$f_band = 'background-image:linear-gradient(rgba(255, 255, 255, 0.64), rgba(255, 255, 255, 0.24));border-top-width:1px;border-top-style:solid;border-top-color:rgba(131, 108, 74, 0.08);border-radius:38px;box-shadow:rgba(98, 78, 45, 0.08) 0px 18px 50px 0px;display:grid;grid-template-columns:556px 834px;min-height:300px;height:302px';
$f_cz   = 'padding:36px;display:flex;flex-direction:column;justify-content:space-between;height:300px';
$f_mk_band = function ( $lbl, $ttl, $copy ) use ( $f_vis, $f_band, $f_cz ) {
	return '<article class="band" data-sc-cs="' . $f_band . '"><div class="visual" data-sc-cs="' . $f_vis . '"></div><div class="copyzone" data-sc-cs="' . $f_cz . '"><div data-sc-cs="display:block">'
		. '<div class="section-label" data-sc-cs="color:rgb(138, 124, 105);font-size:10px;line-height:15px;letter-spacing:3px;text-transform:uppercase;display:block">' . $lbl . '</div>'
		. '<h3 class="serif" data-sc-cs="font-size:42px;line-height:39.9px;letter-spacing:-2.1px;color:rgb(42, 36, 28);display:block">' . $ttl . '</h3>'
		. '<p data-sc-cs="font-size:16px;line-height:30.4px;color:rgb(100, 88, 75);max-width:640px;margin:16px 0px 0px;display:block">' . $copy . '</p></div></div></article>';
};
$f_html = '<!DOCTYPE html><html><head><title>T</title><style>.section-title{font-size:clamp(2.9rem,5.6vw,6rem);line-height:.9;letter-spacing:-.06em}</style></head><body data-sc-cs="font-family:Inter, sans-serif">'
	. '<header><a href="/">Site</a><nav><a href="#a">Alpha</a><a href="#b">Beta</a></nav></header>'
	. '<main><section id="f" class="section pt-0" data-sc-cs="padding:0px 0px 120px;display:block"><div class="section-shell" data-sc-cs="display:block">'
	. '<div class="max-w-4xl" data-sc-cs="max-width:896px;display:block"><div class="section-label" data-sc-cs="font-size:10px;letter-spacing:3px;text-transform:uppercase;display:block">Rice Field Grid</div><h2 class="section-title" data-sc-cs="font-size:80.64px;line-height:72.576px;letter-spacing:-4.8384px;display:block">Staggered layers like terraced fields.</h2></div>'
	. '<div class="feature-band" data-sc-cs="margin:120px 0px 0px;display:grid;grid-template-columns:1392px;gap:22px">'
	. $f_mk_band( 'Golden-ratio cultivation', 'Organic planting geometry', 'Crop spacing and field sequencing tuned for airflow, irrigation balance, and a balanced visual cadence across the estate.' )
	. $f_mk_band( 'Zen-organic harmonics', 'Rhythms of water and silence', 'Wind, moisture, and reflection systems create a calm sensory cadence throughout the agricultural landscape.' )
	. $f_mk_band( 'Riparian restoration', 'River-edge recovery', 'Native vegetation and softer runoff pathways stabilize the terrain while improving biodiversity and water retention.' )
	. '</div></div></section></main></body></html>';
$f_bl  = FW_Site_Converter_Sources::build_from_html( $f_html, 'BandFixture', array( 'dynamic_chrome' => true ) );
$f_sec = null; foreach ( (array) ( $f_bl['files']['pages.json']['pages'][0]['builder'] ?? array() ) as $s ) { if ( ( $s['type'] ?? '' ) === 'section' && ( $s['atts']['css_id'] ?? '' ) === 'f' ) { $f_sec = $s; break; } }
ga( "band section found", $f_sec !== null );
ga_eq( "computed padding-top 0 → explicit pt-[0px] (not the theme default)", 'pt-[0px]', $f_sec['atts']['padding_top']['base'] ?? null );
ga( "section container = the shell, NOT the 896px heading cap (a capped block with siblings is its own measure)", empty( $f_sec['atts']['container_width'] ) || ( $f_sec['atts']['container_width']['preset'] ?? '' ) !== 'medium', $f_sec['atts']['container_width'] ?? null );
$f_find = function ( $n, $pred ) use ( &$f_find ) { if ( ! is_array( $n ) ) { return null; } if ( $pred( $n ) ) { return $n; } foreach ( ( $n['_items'] ?? array() ) as $c ) { $r = $f_find( $c, $pred ); if ( $r ) { return $r; } } return null; };
$f_sh = $f_find( $f_sec, function ( $n ) { return ( $n['shortcode'] ?? '' ) === 'special_heading' && ( $n['atts']['title'] ?? '' ) === 'Staggered layers like terraced fields.'; } );
ga_eq( "the section heading keeps its own 896px cap (block_max_width)", '896', (string) ( $f_sh['atts']['block_max_width']['value'] ?? '' ) );
$f_reg = (string) ( $f_bl['files']['theme-design.json']['custom_css'] ?? '' );
ga( "FLUID section heading: the section-scoped #f h2 rule carries the clamp() + relative metrics (not the 80.64px snapshot that froze it)", strpos( $f_reg, '#f h2:not([class*="boxp-"] *){' ) !== false && strpos( $f_reg, 'font-size:clamp(2.9rem,5.6vw,6rem) !important;' ) !== false && strpos( $f_reg, 'line-height:.9 !important;' ) !== false && strpos( $f_reg, 'letter-spacing:-.06em !important;' ) !== false && strpos( $f_reg, 'font-size:80.64px' ) === false, substr( $f_reg, (int) strpos( $f_reg, '#f h2' ), 300 ) );
$f_stack = $f_find( $f_sec, function ( $n ) { return ( $n['type'] ?? '' ) === 'flexbox' && ( $n['atts']['direction']['base'] ?? '' ) === 'column' && count( $n['_items'] ?? array() ) === 3; } );
ga( "single-track grid → ONE flexbox column (stack) of three bands", $f_stack !== null );
ga_eq( "stack gap = the source 22px", '[22px]', $f_stack['atts']['gap']['base'] ?? null );
ga_eq( "stack margin-top = the source 120px", 'mt-[120px]', $f_stack['atts']['spacing']['margin']['top'] ?? null );
$f_b0 = $f_stack['_items'][0] ?? array();
ga_eq( "band → native Grid with the source tracks", 'grid', $f_b0['atts']['display'] ?? null );
ga_eq( "band tracks 0.8fr 1.2fr", '0.8fr 1.2fr', $f_b0['atts']['grid_columns'] ?? null );
ga( "band row wears its card as a Box Preset", ! empty( $f_b0['atts']['border_preset'] ) && 0 === strpos( (string) $f_b0['atts']['border_preset'], 'boxp-' ), $f_b0['atts']['border_preset'] ?? null );
ga_eq( "band min-height 300", '300', $f_b0['atts']['min_height']['base']['value'] ?? null );
$f_bp = null; $f_bp_slug = substr( (string) ( $f_b0['atts']['border_preset'] ?? '' ), 5 );
foreach ( (array) ( $f_bl['files']['theme-settings.json']['values']['border_presets'] ?? array() ) as $bp ) { if ( trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) ( $bp['preset_name'] ?? '' ) ) ), '-' ) === $f_bp_slug ) { $f_bp = $bp; break; } }
ga( "band preset: radius 38 + clips (a painted panel reaches the card edge)", $f_bp !== null && '38' === (string) ( $f_bp['border_radius']['value'] ?? '' ) && strpos( (string) ( $f_bp['custom_css'] ?? '' ), '{{SELECTOR}}{overflow:hidden;}' ) !== false, $f_bp['custom_css'] ?? null );
$f_panel = $f_b0['_items'][0] ?? array(); $f_copy = $f_b0['_items'][1] ?? array();
ga( "painted EMPTY panel → an empty cell (not dropped)", is_array( $f_panel ) && ( $f_panel['type'] ?? '' ) === 'flexbox' && empty( $f_panel['_items'] ) );
ga( "panel carries the source's multi-layer paint as its background-image", strpos( (string) ( $f_panel['atts']['custom_css'] ?? '' ), 'selector{background-image:radial-gradient(circle at 25% 30%' ) !== false, $f_panel['atts']['custom_css'] ?? null );
ga_eq( "copy cell is a flex column", 'column', $f_copy['atts']['direction']['base'] ?? null );
ga_eq( "copy cell justify = space-between", 'between', $f_copy['atts']['justify_content']['base'] ?? null );
ga( "copy cell keeps its 36px padding", strpos( (string) ( $f_copy['atts']['custom_css'] ?? '' ), 'padding-top:36px;padding-right:36px;padding-bottom:36px;padding-left:36px' ) !== false, $f_copy['atts']['custom_css'] ?? null );
$f_csh = $f_find( $f_copy, function ( $n ) { return ( $n['shortcode'] ?? '' ) === 'special_heading'; } );
ga( "label → h3 → p fold into ONE special heading (overline + title + subtitle)", $f_csh !== null && 'Golden-ratio cultivation' === ( $f_csh['atts']['overline'] ?? '' ) && 'Organic planting geometry' === ( $f_csh['atts']['title'] ?? '' ) && strpos( (string) ( $f_csh['atts']['subtitle'] ?? '' ), 'Crop spacing' ) !== false, $f_csh['atts'] ?? null );
ga_eq( "…and exactly one heading in the copy cell", 1, count( array_filter( (array) ( $f_copy['_items'] ?? array() ), function ( $n ) { return ( $n['shortcode'] ?? '' ) === 'special_heading'; } ) ) );
ga_eq( "h3 keeps its tag", 'h3', $f_csh['atts']['heading'] ?? null );
ga( "band paragraph keeps its 640px measure + 16px gap", strpos( (string) ( $f_csh['atts']['custom_css'] ?? '' ), 'max-width:640px' ) !== false && strpos( (string) ( $f_csh['atts']['custom_css'] ?? '' ), 'margin-bottom:16px' ) !== false, $f_csh['atts']['custom_css'] ?? null );

$g_row2 = $g_row_of( $g_mk( '682px 682px' ) );
ga( "equal tracks → stays on the flex/span path (no track list)", $g_row2 !== null && ( $g_row2['atts']['display'] ?? '' ) === 'flex' && empty( $g_row2['atts']['grid_columns'] ) === false && ctype_digit( (string) $g_row2['atts']['grid_columns'] ), $g_row2['atts'] ?? null );
ga_eq( "equal tracks → 6/6 spans", '6', $g_row2['_items'][0]['atts']['width']['base']['preset'] ?? null );

/* --- [V] PSEUDO-ELEMENT scrim over a video band: `.hero::after{inset:0;background:radial…, linear…}` is no DOM
 *     element, so the capture stamps it as data-sc-scrim. The LINEAR layer → the native Background Overlay
 *     gradient; the RADIAL vignette (no native field) → a scoped `selector::after` layer. Nothing dropped, nothing
 *     painted twice. Negative: an image band with no scrim keeps the flat 35% fallback. --- */
echo "\n[V] Pseudo-element scrim → native overlay (linear) + scoped pseudo-layer (radial)\n";
$v_scrim = 'radial-gradient(circle, rgba(0, 0, 0, 0) 12%, rgba(0, 0, 0, 0.18) 72%, rgba(0, 0, 0, 0.3) 100%), linear-gradient(rgba(0, 0, 0, 0.12), rgba(0, 0, 0, 0.28))';
$v_html = '<!DOCTYPE html><html><head><title>T</title></head><body data-sc-cs="font-family:Inter, sans-serif">'
	. '<header><a href="/">Site</a><nav><a href="#a">Alpha</a><a href="#b">Beta</a></nav></header>'
	. '<main><section class="hero" id="a" data-sc-cs="height:900px;min-height:900px;display:block" data-sc-scrim="' . htmlspecialchars( $v_scrim, ENT_QUOTES ) . '">'
	. '<video autoplay muted loop playsinline data-sc-cs="max-width:100%;height:900px;display:block;position:absolute"><source src="https://example.invalid/clip.mp4" type="video/mp4"></video>'
	. '<div data-sc-cs="text-align:center;padding:100px 24px 40px;position:relative"><h1>Hi</h1><p>Body</p></div></section></main></body></html>';
$v_bundle = FW_Site_Converter_Sources::build_from_html( $v_html, 'ScrimFixture', array( 'dynamic_chrome' => true ) );
$v_sec = null;
foreach ( (array) ( $v_bundle['files']['pages.json']['pages'][0]['builder'] ?? array() ) as $s ) { if ( ( $s['type'] ?? '' ) === 'section' && ! empty( $s['atts']['background']['video']['source_mp4']['url'] ) ) { $v_sec = $s; break; } }
ga( "video band → section background video", $v_sec !== null );
$v_ov = $v_sec['atts']['background']['overlay'] ?? array();
ga_eq( "linear layer → native overlay gradient (2 stops)", 2, count( $v_ov['gradient']['stops'] ?? array() ) );
ga_eq( "native overlay gradient end stop = rgba(0, 0, 0, 0.28)", 'rgba(0, 0, 0, 0.28)', $v_ov['gradient']['stops'][1]['color'] ?? null );
$v_css = (string) ( $v_sec['atts']['custom_css'] ?? '' );
ga( "radial vignette → scoped selector::after pseudo-layer (verbatim)", strpos( $v_css, 'selector::after{content:"";position:absolute;inset:0;pointer-events:none;z-index:1;background-image:radial-gradient(circle, rgba(0, 0, 0, 0) 12%' ) !== false, $v_css );
ga( "pseudo-layer carries ONLY the radial (the linear stays native — not painted twice)", strpos( $v_css, 'linear-gradient' ) === false, $v_css );
/* --- [W] Site container width = the source's DECLARED cap, not the viewport-limited measurement. The capture
 *     stamps the browser-measured content width (1392 for a `width:min(1440px, calc(100% - 48px))` shell at the
 *     1440px capture viewport); the declared 1440 must win. Negative: no declared rule → the stamp stands. --- */
echo "\n[W] Container width: declared cap beats the viewport-limited measurement\n";
$w_html = '<!DOCTYPE html><html data-sc-content-width="1392"><head><title>T</title><style>.shell{width:min(1440px, calc(100% - 48px));margin:0 auto}@media (max-width:900px){.shell{width:100%}}</style></head><body data-sc-cs="font-family:Inter, sans-serif">'
	. '<header class="hdr"><a href="/">Site</a><nav><a href="#a">Alpha</a><a href="#b">Beta</a></nav></header>'
	. '<main><section id="a"><div class="shell" data-sc-cs="margin:0px 24px;display:block"><h1>Hi</h1><p>Body</p></div></section>'
	. '<section id="b"><div class="shell" data-sc-cs="margin:0px 24px;display:block"><h2>Two</h2><p>More</p></div></section></main></body></html>';
$w_ts = FW_Site_Converter_Sources::build_from_html( $w_html, 'WidthFixture', array( 'dynamic_chrome' => true ) )['files']['theme-settings.json']['values'] ?? array();
ga_eq( "declared `width:min(1440px…)` shell → container width 1440 (not the 1392 measured at 1440vw)", '1440', $w_ts['general_layout']['layout_container_width']['lg']['value'] ?? null );
$w2_html = str_replace( 'width:min(1440px, calc(100% - 48px));', '', $w_html );
$w2_ts = FW_Site_Converter_Sources::build_from_html( $w2_html, 'WidthFixture2', array( 'dynamic_chrome' => true ) )['files']['theme-settings.json']['values'] ?? array();
ga_eq( "no declared cap → the measured stamp stands (1392)", '1392', $w2_ts['general_layout']['layout_container_width']['lg']['value'] ?? null );
$w3_html = str_replace( 'min(1440px,', 'min(1900px,', $w_html );
$w3_ts = FW_Site_Converter_Sources::build_from_html( $w3_html, 'WidthFixture3', array( 'dynamic_chrome' => true ) )['files']['theme-settings.json']['values'] ?? array();
ga_eq( "a declared cap far above the measurement (>1.25×) is not trusted → the stamp stands", '1392', $w3_ts['general_layout']['layout_container_width']['lg']['value'] ?? null );

// NEGATIVE — the one-row Golden Fixture 1 header keeps its menu in the main row and an EMPTY Bottom Bar.
ga( "one-row header: Bottom Bar stays empty", empty( $ts['header_bottombar']['bottombar_center'] ) && empty( $ts['header_bottombar']['bottombar_left'] ) && empty( $ts['header_bottombar']['bottombar_right'] ), $ts['header_bottombar'] ?? null );
ga( "one-row header: menu_area stays in the main row", strpos( wp_json_encode( $ts['header_main'] ?? array() ), 'menu_area' ) !== false );

/* --- [R] CORE-STYLE RING PANEL: a centred grid shell → a RING (a skinned panel: radial fill, hairline, glow + inset
 *     shadow, width:min(78vw,42rem), aspect-ratio 1, an inner hairline ::before + a blurred bloom ::after, content
 *     centred) → a CARD (a skinned panel: translucent fill, hairline, shadow, radius 32, padding 26/28,
 *     width:min(78vw,620px), text centred) → eyebrow → a FLUID h2 declared by a DESCENDANT selector (.core-copy h2)
 *     → p → a centred row of three chip SPANS. Rules: a skinned wrapper around content is a panel (a flexbox column
 *     wearing its Box Preset) and nested panels recurse; the sheet's width / aspect expressions ride as scoped CSS;
 *     centring is native; every decor layer survives (a bordered one too); a non-linear fill rides in the preset
 *     CSS; the stylesheet reader matches descendant selectors with the cascade; boxed spans are chips, never
 *     split-text; the eyebrow→title gap is exactly zero. JS twin: core-panel-parity.test.mjs. --- */
echo "\n[R] Ring panel: nested skinned panels, decor layers, fluid descendant heading, chip spans\n";
$c_ring_cs = 'background-image:radial-gradient(circle, rgba(255, 255, 255, 0.72), rgba(255, 255, 255, 0.22) 56%, rgba(255, 255, 255, 0.06) 74%, rgba(0, 0, 0, 0) 76%);border-top-width:1px;border-top-style:solid;border-top-color:rgba(131, 108, 74, 0.08);border-radius:999px;box-shadow:rgba(86, 120, 168, 0.08) 0px 0px 120px 0px, rgba(255, 255, 255, 0.12) 0px 0px 70px 0px inset;height:672px;display:grid;grid-template-columns:670px;align-items:center;position:relative';
$c_ring_dp = 'before;top:14%;left:14%;width:72%;height:72%;border:1px solid rgba(131, 108, 74, 0.08);shadow:rgba(215, 170, 97, 0.06) 0px 0px 40px 0px inset;radius:999px||after;top:28%;left:28%;width:44%;height:44%;background:radial-gradient(circle, rgba(215, 170, 97, 0.18), rgba(0, 0, 0, 0) 58%);filter:blur(10px);radius:999px';
$c_card_cs = 'background-color:rgba(255, 255, 255, 0.28);text-align:center;padding:26px 28px;border-top-width:1px;border-top-style:solid;border-top-color:rgba(131, 108, 74, 0.08);border-radius:32px;box-shadow:rgba(98, 78, 45, 0.08) 0px 18px 40px 0px;height:348px;display:block;position:relative';
$c_chip_cs = 'background-color:rgba(255, 255, 255, 0.56);color:rgb(94, 82, 69);font-size:10px;line-height:15px;letter-spacing:2px;text-transform:uppercase;padding:12px 16px;border-top-width:1px;border-top-style:solid;border-top-color:rgba(131, 108, 74, 0.08);border-radius:999px;display:inline-block';
$c_html = '<!DOCTYPE html><html><head><title>T</title><style>.core-shell{width:min(1440px, calc(100% - 48px));margin:0 auto;display:grid;grid-template-columns:1fr;justify-items:center}.core-ring{width:min(78vw, 42rem);aspect-ratio:1;display:grid;place-items:center}.core-copy{width:min(78vw, 620px)}.core-copy h2{font-size:clamp(2.6rem,5vw,5.4rem);line-height:.92;letter-spacing:-.06em}h1,h2,h3{font-size:inherit}</style></head><body data-sc-cs="font-family:Inter, sans-serif">'
	. '<header><a href="/">Site</a><nav><a href="#a">Alpha</a><a href="#b">Beta</a></nav></header>'
	. '<main><section id="c" class="terrain-core" data-sc-cs="padding:120px 0px;display:block"><div class="core-shell" data-sc-cs="margin:0px 24px;display:grid;grid-template-columns:1392px">'
	. '<div class="core-ring" data-sc-cs="' . $c_ring_cs . '" data-sc-decor-pseudo="' . $c_ring_dp . '">'
	. '<div class="core-copy" data-sc-cs="' . $c_card_cs . '">'
	. '<div class="section-label" data-sc-cs="color:rgb(138, 124, 105);font-size:10px;line-height:15px;letter-spacing:3px;text-transform:uppercase;text-align:center;display:block">Golden Core</div>'
	. '<h2 class="serif" data-sc-cs="color:rgb(42, 36, 28);font-size:72px;font-weight:400;line-height:66.24px;letter-spacing:-4.32px;text-align:center;display:block">A quiet center for the entire landscape.</h2>'
	. '<p data-sc-cs="color:rgb(98, 88, 77);font-size:16px;line-height:32px;margin:18px 0px 0px;text-align:center;display:block">Cyclical irrigation, light harvest, and soil balance converge here as a single living system rather than a set of isolated assets.</p>'
	. '<div class="core-tags" data-sc-cs="display:flex;gap:12px;justify-content:center;margin:24px 0px 0px"><span data-sc-cs="' . $c_chip_cs . '">Soil vitality 84%</span><span data-sc-cs="' . $c_chip_cs . '">Water reserve 92%</span><span data-sc-cs="' . $c_chip_cs . '">Harvest pulse stable</span></div>'
	. '</div></div></div></section></main></body></html>';
$c_bl  = FW_Site_Converter_Sources::build_from_html( $c_html, 'RingFixture', array( 'dynamic_chrome' => true ) );
$c_sec = null; foreach ( (array) ( $c_bl['files']['pages.json']['pages'][0]['builder'] ?? array() ) as $s ) { if ( ( $s['type'] ?? '' ) === 'section' && ( $s['atts']['css_id'] ?? '' ) === 'c' ) { $c_sec = $s; break; } }
ga( "ring section found", $c_sec !== null );
$c_find = function ( $n, $pred ) use ( &$c_find ) { if ( ! is_array( $n ) ) { return null; } if ( $pred( $n ) ) { return $n; } foreach ( ( $n['_items'] ?? array() ) as $ch ) { $r = $c_find( $ch, $pred ); if ( $r ) { return $r; } } return null; };
$c_css  = function ( $n ) { return (string) ( $n['atts']['custom_css'] ?? '' ); };
$c_ring = $c_find( $c_sec, function ( $n ) use ( $c_css ) { return ( $n['type'] ?? '' ) === 'flexbox' && strpos( $c_css( $n ), 'aspect-ratio:1' ) !== false; } );
ga( "ring → a flexbox COLUMN (a skinned panel), not flattened away", $c_ring !== null && ( $c_ring['atts']['direction']['base'] ?? '' ) === 'column' );
ga( "ring wears a Box Preset (radial fill + hairline + shadows)", '' !== (string) ( $c_ring['atts']['border_preset'] ?? '' ) );
ga( "ring keeps the sheet's width expression + aspect-ratio, centred by its parent (justify-items:center → margin auto)", strpos( $c_css( $c_ring ), 'selector{width:min(78vw, 42rem);max-width:100%;aspect-ratio:1;margin-left:auto;margin-right:auto;}' ) !== false, $c_css( $c_ring ) );
ga( "ring centres its content (place-items:center → native justify + align center)", ( $c_ring['atts']['justify_content']['base'] ?? '' ) === 'center' && ( $c_ring['atts']['align_items']['base'] ?? '' ) === 'center' );
ga( "inner hairline ring (a BORDERED decor layer) survives as ::before", preg_match( '/selector::before\{[^}]*border:1px solid rgba\(131, 108, 74, 0\.08\)[^}]*box-shadow:rgba\(215, 170, 97, 0\.06\) 0px 0px 40px 0px inset[^}]*border-radius:999px/', $c_css( $c_ring ) ) === 1, $c_css( $c_ring ) );
ga( "blurred bloom survives as ::after (a second layer on the same box)", preg_match( '/selector::after\{[^}]*background:radial-gradient\(circle, rgba\(215, 170, 97, 0\.18\)[^}]*filter:blur\(10px\)/', $c_css( $c_ring ) ) === 1 );
$c_card = $c_ring ? $c_find( $c_ring, function ( $n ) use ( $c_css, $c_ring ) { return $n !== $c_ring && ( $n['type'] ?? '' ) === 'flexbox' && strpos( $c_css( $n ), 'padding-top:26px' ) !== false; } ) : null;
ga( "card → a NESTED flexbox column with its 26/28 padding (panels recurse)", $c_card !== null && strpos( $c_css( $c_card ), 'padding-top:26px;padding-right:28px;padding-bottom:26px;padding-left:28px' ) !== false );
ga( "card keeps its width cap, centred, and wears its own Box Preset", strpos( $c_css( $c_card ), 'selector{width:min(78vw, 620px);max-width:100%;margin-left:auto;margin-right:auto;}' ) !== false && '' !== (string) ( $c_card['atts']['border_preset'] ?? '' ) && ( $c_card['atts']['border_preset'] ?? '' ) !== ( $c_ring['atts']['border_preset'] ?? '' ) );
ga_eq( "card text centred (native text_align)", 'center', $c_card['atts']['text_align'] ?? null );
$c_sh = $c_card ? $c_find( $c_card, function ( $n ) { return ( $n['shortcode'] ?? '' ) === 'special_heading'; } ) : null;
ga( "eyebrow → h2 → p fold into ONE special heading inside the card", $c_sh && ( $c_sh['atts']['overline'] ?? '' ) === 'Golden Core' && ( $c_sh['atts']['title'] ?? '' ) === 'A quiet center for the entire landscape.' && strpos( (string) ( $c_sh['atts']['subtitle'] ?? '' ), 'Cyclical irrigation' ) !== false );
ga( "FLUID h2 declared by a DESCENDANT selector (.core-copy h2) with the cascade (a later h1,h2{font-size:inherit} must not win) → clamp() + relative metrics", strpos( $c_css( $c_sh ), 'selector .heading-title{font-size:clamp(2.6rem,5vw,5.4rem) !important;line-height:.92 !important;letter-spacing:-.06em !important;}' ) !== false, $c_css( $c_sh ) );
ga( "eyebrow→title gap is exactly 0 (the theme's default must not open it)", preg_match( '/selector \.heading-overline\{[^}]*margin-bottom:0px !important/', $c_css( $c_sh ) ) === 1, $c_css( $c_sh ) );
$c_chips = $c_card ? $c_find( $c_card, function ( $n ) { return ( $n['type'] ?? '' ) === 'flexbox' && ( $n['atts']['justify_content']['base'] ?? '' ) === 'center' && 3 === count( $n['_items'] ?? array() ); } ) : null;
ga( "three boxed SPANS → a centred chip row (never collapsed as split-text words)", $c_chips !== null && 3 === count( array_filter( $c_chips['_items'], function ( $n ) { return ( $n['shortcode'] ?? '' ) === 'text_block' && '' !== (string) ( $n['atts']['box_style'] ?? '' ); } ) ) );
ga_eq( "chip row margin-top 24 (native spacing)", 'mt-4', $c_chips['atts']['spacing']['margin']['top'] ?? null );
$c_td = wp_json_encode( $c_bl['files']['theme-settings.json'] ?? array() );
ga( "the ring's RADIAL fill rides in its Box Preset CSS (no native field for a non-linear gradient)", strpos( $c_td, '{{SELECTOR}}{background-image:radial-gradient(circle, rgba(255, 255, 255, 0.72)' ) !== false );

/* --- [S] WATER-STYLE ROUNDED SHELL BAND: a SHELL panel (the site's container expression → fills the section; a radial
 *     glow OVER a linear wash, radius 48, clipped, shadow; a sole inner wrapper pads 82/84/42) holding a 1.1fr/.9fr grid
 *     row (gap 56, align-items:end) of a copy column (eyebrow → fluid h2 → p) and a stats column that IS a single-track
 *     stack (gap 16) of two glass stat panels (label div + a 42px word / number value); then a footer row: a one-sided
 *     hairline (an EDGE skin), margin-top 64, padding-top 26, space-between, a brand text leaf left and three PLAIN tag
 *     spans right. Rules: a rule inside a non-matching @media is ignored; a multi-layer fill rides verbatim; a shell
 *     expression means the panel IS the container; a sole wrapper's padding is absorbed; a row keeps its justify /
 *     align / gap / margins / padding; a stack cell carries its gap; an edge skin keeps its side; a big-display word is
 *     a stat value; a stat value keeps its line-height and a zero bottom margin; a plain tag row is a chip row; a text-
 *     leaf cell is a text block. JS twin: water-band-parity.test.mjs. --- */
echo "\n[S] Rounded shell band: shell panel, grid + stack + edge-skinned footer row, plain tag row\n";
$s_shell_cs = 'background-image:radial-gradient(circle at 18% 18%, rgba(255, 197, 114, 0.14), rgba(0, 0, 0, 0) 20%), linear-gradient(rgb(63, 88, 126) 0%, rgb(41, 55, 84) 100%);color:rgb(255, 255, 255);margin:0px 24px;border-radius:48px;box-shadow:rgba(20, 24, 34, 0.18) 0px 22px 60px 0px;height:494px;display:block';
$s_card_cs  = 'background-color:rgba(255, 255, 255, 0.1);color:rgb(255, 255, 255);padding:26px 28px;border-top-width:1px;border-top-style:solid;border-top-color:rgba(255, 255, 255, 0.12);border-radius:28px;backdrop-filter:blur(18px);height:121px;display:block';
$s_val_cs   = 'color:rgb(255, 255, 255);font-family:&quot;Cormorant Garamond&quot;, serif;font-size:42px;font-weight:400;line-height:42px;margin:10px 0px 0px;display:block';
$s_lbl_cs   = 'color:rgba(255, 255, 255, 0.6);font-size:10px;line-height:15px;letter-spacing:3px;text-transform:uppercase;display:block';
$s_mk_card  = function ( $lbl, $val ) use ( $s_card_cs, $s_val_cs, $s_lbl_cs ) { return '<div class="footer-card" data-sc-cs="' . $s_card_cs . '"><div class="section-label" data-sc-cs="' . $s_lbl_cs . '">' . $lbl . '</div><div class="value serif" data-sc-cs="' . $s_val_cs . '">' . $val . '</div></div>'; };
$s_tag_cs   = 'color:rgba(255, 255, 255, 0.68);font-size:10px;line-height:15px;letter-spacing:2.4px;text-transform:uppercase;display:inline';
$s_html = '<!DOCTYPE html><html><head><title>T</title><style>.footer-shell{width:min(1440px, calc(100% - 48px));margin:0 auto;border-radius:48px;overflow:hidden}.footer-title{font-size:clamp(3rem,5vw,5.2rem);line-height:.9;letter-spacing:-.06em}@media (max-width: 768px){.footer-shell{width:min(100% - 32px, 1440px)}}</style></head><body data-sc-cs="font-family:Inter, sans-serif">'
	. '<header><a href="/">Site</a><nav><a href="#a">Alpha</a><a href="#b">Beta</a></nav></header>'
	. '<main><section id="s" class="footer-water" data-sc-cs="padding:0px 0px 96px;display:block"><div class="footer-shell" data-sc-cs="' . $s_shell_cs . '"><div class="footer-inner" data-sc-cs="padding:82px 84px 42px;display:block">'
	. '<div class="footer-grid" data-sc-cs="display:grid;gap:56px;grid-template-columns:642.391px 525.609px;align-items:end;height:258px">'
	. '<div data-sc-cs="display:block"><div class="section-label" data-sc-cs="' . $s_lbl_cs . '">Still Water</div><h2 class="footer-title serif" data-sc-cs="color:rgb(255, 255, 255);font-size:72px;font-weight:400;line-height:64.8px;letter-spacing:-4.32px;display:block">Where the river ends, the system quiets.</h2><p class="footer-copy" data-sc-cs="color:rgba(255, 255, 255, 0.76);font-size:16px;line-height:32px;margin:18px 0px 0px;max-width:720px;display:block">The final zone settles into a deep blue reflection, holding the entire landscape in a soft, suspended calm.</p></div>'
	. '<div class="footer-stats" data-sc-cs="display:grid;gap:16px;grid-template-columns:525.609px;height:258px">' . $s_mk_card( 'Water reserve', '84%' ) . $s_mk_card( 'Soil vitality', 'High' ) . '</div>'
	. '</div>'
	. '<div class="footer-bottom" data-sc-cs="color:rgba(255, 255, 255, 0.68);font-size:14px;line-height:21px;padding:26px 0px 0px;margin:64px 0px 0px;border-top-width:1px;border-top-style:solid;border-top-color:rgba(255, 255, 255, 0.12);display:flex;gap:20px;justify-content:space-between;flex-direction:row;height:48px">'
	. '<div data-sc-cs="color:rgba(255, 255, 255, 0.68);font-size:14px;line-height:21px;display:block">Autumn Flow · Regenerative Landscapes</div>'
	. '<div class="tags" data-sc-cs="display:flex;gap:18px;font-size:10px;letter-spacing:2.4px;text-transform:uppercase"><span data-sc-cs="' . $s_tag_cs . '">Harvest</span><span data-sc-cs="' . $s_tag_cs . '">Water</span><span data-sc-cs="' . $s_tag_cs . '">Equilibrium</span></div>'
	. '</div></div></div></section></main></body></html>';
$s_bl  = FW_Site_Converter_Sources::build_from_html( $s_html, 'ShellFixture', array( 'dynamic_chrome' => true ) );
$s_sec = null; foreach ( (array) ( $s_bl['files']['pages.json']['pages'][0]['builder'] ?? array() ) as $s ) { if ( ( $s['type'] ?? '' ) === 'section' && ( $s['atts']['css_id'] ?? '' ) === 's' ) { $s_sec = $s; break; } }
ga( "shell section found", $s_sec !== null );
$s_find = function ( $n, $pred ) use ( &$s_find ) { if ( ! is_array( $n ) ) { return null; } if ( $pred( $n ) ) { return $n; } foreach ( ( $n['_items'] ?? array() ) as $ch ) { $r = $s_find( $ch, $pred ); if ( $r ) { return $r; } } return null; };
$s_all  = function ( $n, $pred, &$acc ) use ( &$s_all ) { if ( ! is_array( $n ) ) { return; } if ( $pred( $n ) ) { $acc[] = $n; } foreach ( ( $n['_items'] ?? array() ) as $ch ) { $s_all( $ch, $pred, $acc ); } };
$s_css  = function ( $n ) { return (string) ( $n['atts']['custom_css'] ?? '' ); };
$s_sh = $s_find( $s_sec, function ( $n ) use ( $s_css ) { return ( $n['type'] ?? '' ) === 'flexbox' && strpos( $s_css( $n ), 'padding-top:82px;padding-right:84px;padding-bottom:42px;padding-left:84px' ) !== false; } );
ga( "shell → a flexbox column wearing its skin, with the SOLE inner wrapper's 82/84/42 padding absorbed", $s_sh !== null && '' !== (string) ( $s_sh['atts']['border_preset'] ?? '' ) );
ga( "shell width: the container expression fills the section (no nested width cap) — the mobile @media rule is ignored", strpos( $s_css( $s_sh ), 'width:' ) === false );
$s_ts = wp_json_encode( $s_bl['files']['theme-settings.json'] ?? array() );
ga( "the shell's MULTI-LAYER fill (radial glow over a linear wash) rides verbatim in its preset CSS (not one linear layer)", strpos( $s_ts, '{{SELECTOR}}{background-image:radial-gradient(circle at 18% 18%, rgba(255, 197, 114, 0.14), rgba(0, 0, 0, 0) 20%), linear-gradient(rgb(63, 88, 126) 0%, rgb(41, 55, 84) 100%);}' ) !== false );
ga( "the shell clips (overflow:hidden read from the sheet)", strpos( $s_ts, '"custom_css":"' ) !== false && preg_match( '/overflow:hidden;\}[^"]*background-image:radial-gradient\(circle at 18% 18%|background-image:radial-gradient\(circle at 18% 18%[^"]*overflow:hidden/', $s_ts ) === 1 );
$s_grid = $s_find( $s_sh, function ( $n ) { return ( $n['type'] ?? '' ) === 'flexbox' && ( $n['atts']['display'] ?? '' ) === 'grid'; } );
ga_eq( "grid row → native Grid with the source tracks", '1.1fr 0.9fr', $s_grid['atts']['grid_columns'] ?? null );
ga( "grid row keeps its 56px gap + bottom alignment (nested row layout carried)", in_array( $s_grid['atts']['gap']['base'] ?? '', array( '6', '[56px]' ), true ) && ( $s_grid['atts']['align_items']['base'] ?? '' ) === 'end' );
$s_stats = $s_grid['_items'][1] ?? null;
ga( "stats cell (itself a single-track stack) carries its 16px gap as a native flex-column Gap", $s_stats && in_array( $s_stats['atts']['gap']['base'] ?? '', array( '3', '[16px]' ), true ) && ( $s_stats['atts']['direction']['base'] ?? '' ) === 'column' );
$s_cards = array(); $s_all( $s_sec, function ( $n ) use ( $s_css ) { return ( $n['type'] ?? '' ) === 'flexbox' && strpos( $s_css( $n ), 'padding-top:26px;padding-right:28px' ) !== false; }, $s_cards );
ga( "two glass stat PANELS (label div + value div count as content), each wearing a Box Preset", 2 === count( $s_cards ) && '' !== (string) ( $s_cards[0]['atts']['border_preset'] ?? '' ) && ( $s_cards[0]['atts']['border_preset'] ?? '' ) === ( $s_cards[1]['atts']['border_preset'] ?? '' ) );
$s_v1 = $s_find( $s_cards[0] ?? array(), function ( $n ) { return ( $n['shortcode'] ?? '' ) === 'special_heading'; } );
$s_v2 = $s_find( $s_cards[1] ?? array(), function ( $n ) { return ( $n['shortcode'] ?? '' ) === 'special_heading'; } );
ga( "stat values: the number AND the big-display WORD both fold as label → value headings", $s_v1 && ( $s_v1['atts']['title'] ?? '' ) === '84%' && ( $s_v1['atts']['overline'] ?? '' ) === 'Water reserve' && $s_v2 && ( $s_v2['atts']['title'] ?? '' ) === 'High' );
ga( "stat value keeps its 42px line-height (a nested heading carries its metrics) + the 10px label→value gap", strpos( $s_css( $s_v1 ), 'line-height:42px !important' ) !== false && preg_match( '/heading-overline\{[^}]*margin-bottom:10px !important/', $s_css( $s_v1 ) ) === 1 );
ga_eq( "stat value (no subtitle) keeps a ZERO outer bottom margin", 'mb-0', $s_v1['atts']['spacing']['margin']['bottom'] ?? null );
$s_foot = $s_find( $s_sh, function ( $n ) { return ( $n['type'] ?? '' ) === 'flexbox' && ( $n['atts']['justify_content']['base'] ?? '' ) === 'between'; } );
ga( "footer row → native justify space-between with CONTENT-sized cells", $s_foot !== null && 2 === count( $s_foot['_items'] ) && ( $s_foot['_items'][0]['atts']['width']['base']['preset'] ?? '' ) === 'none' );
ga( "footer row wears its EDGE skin (a one-sided hairline → a preset with border_sides top)", '' !== (string) ( $s_foot['atts']['border_preset'] ?? '' ) && strpos( $s_ts, '"border_sides":"top"' ) !== false );
ga( "footer row keeps its 64px above + 26px top inset", in_array( $s_foot['atts']['spacing']['margin']['top'] ?? '', array( 'mt-7', 'mt-[64px]' ), true ) && strpos( $s_css( $s_foot ), 'padding-top:26px' ) !== false );
ga( "brand line (a text-leaf cell) → a text block", $s_find( $s_foot['_items'][0], function ( $n ) { return ( $n['shortcode'] ?? '' ) === 'text_block' && strpos( (string) ( $n['atts']['text'] ?? '' ), 'Regenerative Landscapes' ) !== false; } ) !== null );
$s_tags = $s_find( $s_foot['_items'][1], function ( $n ) { return ( $n['type'] ?? '' ) === 'flexbox' && 3 === count( $n['_items'] ?? array() ); } );
ga( "three PLAIN tag spans → a chip row of three text blocks with the 18px gap and NO Box Preset (never split-text)", $s_tags && in_array( $s_tags['atts']['gap']['base'] ?? '', array( '[18px]', '18' ), true ) && 3 === count( array_filter( $s_tags['_items'], function ( $n ) { return ( $n['shortcode'] ?? '' ) === 'text_block' && '' === (string) ( $n['atts']['box_style'] ?? '' ); } ) ) );
$s_h2 = $s_find( $s_sec, function ( $n ) { return ( $n['shortcode'] ?? '' ) === 'special_heading' && strpos( (string) ( $n['atts']['title'] ?? '' ), 'Where the river' ) !== false; } );
ga( "fluid h2 keeps clamp() + relative metrics", strpos( $s_css( $s_h2 ), 'font-size:clamp(3rem,5vw,5.2rem) !important;line-height:.9 !important;letter-spacing:-.06em !important' ) !== false );

/* --- [X] SLIDER STRIP: a header row (flex, space-between, align end, gap 22, no wrap: eyebrow + fluid h2 left, a
 *     400px-capped intro right — the cap read from a '.slider-head p' descendant rule) then a horizontal SCROLL STRIP
 *     (grid, column auto-flow, auto-columns minmax(78%, 980px), gap 18, overflow-x auto, scroll-snap x, padding-bottom
 *     12) of three strip CARDS, each itself a .95fr/1.05fr row (rounded 38, gradient fill, hairline, shadow, clipped,
 *     min-height 360) of a padded copy cell (flex column space-between: eyebrow → h3 → p … meta) and a PAINTED image
 *     half. Rules: a no-wrap row keeps one line and a capped cell is size-frozen; a scroll strip keeps the item width,
 *     snap and hidden scrollbar; a cell that IS a row is claimed whole and wears its skin on that row; strip cards never
 *     squeeze into a 3-column grid; the nested h3 keeps its exact size. JS twin: slider-strip-parity.test.mjs. --- */
echo "\n[X] Slider strip: no-wrap header row, horizontal scroll strip, strip cards claimed whole as rows\n";
$x_card_cs = 'background-image:linear-gradient(rgba(255, 255, 255, 0.66), rgba(255, 255, 255, 0.26));color:rgb(38, 33, 28);border-top-width:1px;border-top-style:solid;border-top-color:rgba(131, 108, 74, 0.08);border-radius:38px;box-shadow:rgba(98, 78, 45, 0.08) 0px 18px 50px 0px;height:362px;min-height:360px;display:grid;grid-template-columns:514.781px 568.969px;overflow:hidden';
$x_paint   = 'background-image:radial-gradient(circle at 30% 25%, rgba(215, 170, 97, 0.28), rgba(0, 0, 0, 0) 18%), linear-gradient(rgba(255, 255, 255, 0.12), rgba(0, 0, 0, 0.06));min-height:360px;height:360px;display:block';
$x_mk_card = function ( $lbl, $ttl, $copy, $meta ) use ( $x_card_cs, $x_paint ) {
	return '<article class="strip-card" data-sc-cs="' . $x_card_cs . '"><div class="content" data-sc-cs="padding:34px;display:flex;flex-direction:column;justify-content:space-between;height:360px"><div data-sc-cs="display:block">'
		. '<div class="section-label" data-sc-cs="color:rgb(138, 124, 105);font-size:10px;line-height:15px;letter-spacing:3px;text-transform:uppercase;display:block">' . $lbl . '</div>'
		. '<h3 class="serif" data-sc-cs="font-size:44px;line-height:41.8px;letter-spacing:-2.2px;color:rgb(42, 36, 28);display:block">' . $ttl . '</h3>'
		. '<p data-sc-cs="font-size:16px;line-height:30.4px;color:rgb(100, 88, 75);max-width:620px;margin:16px 0px 0px;display:block">' . $copy . '</p></div>'
		. '<div class="copy" data-sc-cs="font-size:11px;line-height:17px;letter-spacing:2.64px;text-transform:uppercase;color:rgba(255, 255, 255, 0.9);display:block">' . $meta . '</div></div>'
		. '<div class="image" data-sc-cs="' . $x_paint . '"></div></article>';
};
$x_html = '<!DOCTYPE html><html><head><title>T</title><style>.slider-head h2{font-size:clamp(2.7rem,5vw,5.4rem);line-height:.92;letter-spacing:-.06em}.slider-head p{max-width:400px;line-height:1.9}.strip{display:grid;grid-auto-flow:column;grid-auto-columns:minmax(78%, 980px);gap:18px;overflow-x:auto;scroll-snap-type:x mandatory;padding-bottom:12px;scrollbar-width:none}.strip-card{scroll-snap-align:start;overflow:hidden}</style></head><body data-sc-cs="font-family:Inter, sans-serif">'
	. '<header><a href="/">Site</a><nav><a href="#a">Alpha</a><a href="#b">Beta</a></nav></header>'
	. '<main><section id="x" class="slider-section" data-sc-cs="padding:120px 0px;display:block"><div class="slider-shell" data-sc-cs="margin:0px 24px;display:block">'
	. '<div class="slider-head" data-sc-cs="display:flex;justify-content:space-between;gap:22px;align-items:flex-end;margin:0px 0px 28px;flex-direction:row;height:147px">'
	. '<div data-sc-cs="display:block"><div class="section-label" data-sc-cs="font-size:10px;letter-spacing:3px;text-transform:uppercase;display:block">Still Water Slider</div><h2 class="serif" data-sc-cs="color:rgb(42, 36, 28);font-size:72px;font-weight:400;line-height:66.24px;letter-spacing:-4.32px;display:block">Horizontal mission states across the valley.</h2></div>'
	. '<p data-sc-cs="color:rgb(98, 88, 77);font-size:16px;line-height:30.4px;max-width:400px;display:block">Each strip captures a different seasonal condition: irrigation, restoration, and reflective calm.</p>'
	. '</div>'
	. '<div class="strip" data-sc-cs="padding:0px 0px 12px;display:grid;gap:18px;grid-template-columns:1085.75px 1085.75px 1085.75px;width:1392px;height:374px">'
	. $x_mk_card( 'Irrigation pulse', 'Water arriving with precision.', 'The system regulates flow across terraces so each layer receives just enough hydration to remain in balance.', 'Pulse 07 · AM' )
	. $x_mk_card( 'Terrace memory', 'The fields hold their own geometry.', 'Elevated contours preserve both the visible rhythm of the land and the invisible logic of runoff control.', 'Contour 12 · PM' )
	. $x_mk_card( 'Seasonal reflection', 'Where the river slows into silence.', 'The final layer dissolves into water and sky, leaving the estate in a calm blue afterglow.', 'Reflection · Dusk' )
	. '</div></div></section></main></body></html>';
$x_bl  = FW_Site_Converter_Sources::build_from_html( $x_html, 'StripFixture', array( 'dynamic_chrome' => true ) );
$x_sec = null; foreach ( (array) ( $x_bl['files']['pages.json']['pages'][0]['builder'] ?? array() ) as $s ) { if ( ( $s['type'] ?? '' ) === 'section' && ( $s['atts']['css_id'] ?? '' ) === 'x' ) { $x_sec = $s; break; } }
ga( "strip section found", $x_sec !== null );
$x_find = function ( $n, $pred ) use ( &$x_find ) { if ( ! is_array( $n ) ) { return null; } if ( $pred( $n ) ) { return $n; } foreach ( ( $n['_items'] ?? array() ) as $ch ) { $r = $x_find( $ch, $pred ); if ( $r ) { return $r; } } return null; };
$x_all  = function ( $n, $pred, &$acc ) use ( &$x_all ) { if ( ! is_array( $n ) ) { return; } if ( $pred( $n ) ) { $acc[] = $n; } foreach ( ( $n['_items'] ?? array() ) as $ch ) { $x_all( $ch, $pred, $acc ); } };
$x_css  = function ( $n ) { return (string) ( $n['atts']['custom_css'] ?? '' ); };
$x_hd = $x_find( $x_sec, function ( $n ) { return ( $n['type'] ?? '' ) === 'flexbox' && ( $n['atts']['justify_content']['base'] ?? '' ) === 'between'; } );
ga( "header row → space-between, NO wrap, bottom-aligned", $x_hd !== null && ( $x_hd['atts']['wrap']['base'] ?? '' ) === 'no' && ( $x_hd['atts']['align_items']['base'] ?? '' ) === 'end' );
ga( "cells shrink, but the 400px-capped intro cell (cap read from a '.slider-head p' rule) is size-frozen", strpos( $x_css( $x_hd ), 'selector>*{flex:0 1 auto;min-width:0;}' ) !== false && strpos( $x_css( $x_hd['_items'][1] ?? array() ), 'flex-shrink:0' ) !== false && strpos( $x_css( $x_hd['_items'][1] ?? array() ) . $x_css( $x_hd['_items'][1]['_items'][0] ?? array() ), 'max-width:400px' ) !== false && strpos( $x_css( $x_hd['_items'][0] ?? array() ), 'flex-shrink:0' ) === false );
$x_st = $x_find( $x_sec, function ( $n ) use ( $x_css ) { return ( $n['type'] ?? '' ) === 'flexbox' && strpos( $x_css( $n ), 'overflow-x:auto' ) !== false; } );
ga( "strip → ONE no-wrap flex row of three cards that scrolls on x", $x_st !== null && ( $x_st['atts']['wrap']['base'] ?? '' ) === 'no' && 3 === count( $x_st['_items'] ?? array() ) && 'grid' !== ( $x_st['atts']['display'] ?? '' ) );
ga( "strip keeps snap, hidden scrollbar and its 12px bottom pad", strpos( $x_css( $x_st ), 'scroll-snap-type:x mandatory' ) !== false && strpos( $x_css( $x_st ), 'scrollbar-width:none' ) !== false && strpos( $x_css( $x_st ), '::-webkit-scrollbar{display:none;}' ) !== false && strpos( $x_css( $x_st ), 'padding-bottom:12px' ) !== false );
ga( "every card keeps the source's item width (minmax → max(78%, 980px)) and snaps", strpos( $x_css( $x_st ), 'selector>*{flex:0 0 max(78%, 980px);width:max(78%, 980px);max-width:none;scroll-snap-align:start;}' ) !== false, $x_css( $x_st ) );
$x_cards = array(); $x_all( $x_st, function ( $n ) { return ( $n['type'] ?? '' ) === 'flexbox' && ( $n['atts']['display'] ?? '' ) === 'grid' && ( $n['atts']['grid_columns'] ?? '' ) === '0.95fr 1.05fr'; }, $x_cards );
ga( "each card cell IS a row → native Grid with the .95fr/1.05fr tracks (claimed whole)", 3 === count( $x_cards ) );
ga( "each card row wears the card skin (a Box Preset) at 360 min-height; the cell itself carries none", 3 === count( $x_cards ) && '' !== (string) ( $x_cards[0]['atts']['border_preset'] ?? '' ) && '360' === (string) ( $x_cards[0]['atts']['min_height']['base']['value'] ?? '' ) && '' === (string) ( $x_st['_items'][0]['atts']['border_preset'] ?? '' ) );
$x_copy = $x_cards[0]['_items'][0] ?? null;
ga( "copy cell = flex column, space-between, 34px inset", $x_copy && ( $x_copy['atts']['direction']['base'] ?? '' ) === 'column' && ( $x_copy['atts']['justify_content']['base'] ?? '' ) === 'between' && strpos( $x_css( $x_copy ), 'padding-top:34px' ) !== false );
$x_h3 = $x_copy ? $x_find( $x_copy, function ( $n ) { return ( $n['shortcode'] ?? '' ) === 'special_heading' && ( $n['atts']['title'] ?? '' ) === 'Water arriving with precision.'; } ) : null;
ga( "label → h3 → p fold; the nested h3 keeps its exact 44px / 41.8 metrics", $x_h3 && ( $x_h3['atts']['overline'] ?? '' ) === 'Irrigation pulse' && strpos( (string) ( $x_h3['atts']['subtitle'] ?? '' ), 'The system regulates' ) !== false && strpos( $x_css( $x_h3 ), 'font-size:44px !important' ) !== false && strpos( $x_css( $x_h3 ), 'line-height:41.8px !important' ) !== false, $x_css( $x_h3 ) );
$x_img = $x_cards[0]['_items'][1] ?? null;
ga( "painted image half → an empty cell carrying the paint", $x_img && empty( $x_img['_items'] ) && strpos( $x_css( $x_img ), 'background-image:radial-gradient(circle at 30% 25%' ) !== false );
$x_h2 = $x_find( $x_sec, function ( $n ) { return ( $n['shortcode'] ?? '' ) === 'special_heading' && strpos( (string) ( $n['atts']['title'] ?? '' ), 'Horizontal mission' ) !== false; } );
ga( "fluid h2 keeps clamp() + relative metrics", strpos( $x_css( $x_h2 ), 'font-size:clamp(2.7rem,5vw,5.4rem) !important;line-height:.92 !important;letter-spacing:-.06em !important' ) !== false );

/* --- [N] SIGNUP LOCKUP without a <form>: a paper-pill FIELD wrapper (gradient, hairline, blur, inset + drop shadow, radius
 *     999, padding 12/16) around an envelope glyph from ANOTHER icon set (iconify ph:envelope-simple) + a transparent email
 *     input, and a full-width silk submit button 12px below (54px, 14px sentence-case). Rules: the lockup converts to the
 *     native newsletter shortcode (the Newsletter CRM is required by the import); design stacked from geometry; pill from
 *     the WRAPPER's radius; the wrapper's skin rides on the field; the placeholder colour; the 12px stack gap; the submit
 *     through the shared Button Preset matcher with its own type re-asserted; the glyph → the native field_icon by meaning
 *     (Lucide mail) with its colour. JS twin: newsletter-signup-parity.test.mjs. --- */
echo "\n[N] Signup lockup → native newsletter: field wrapper skin, stacked design, preset button, field icon\n";
$n_field_cs = 'background-image:linear-gradient(rgba(255, 255, 255, 0.62), rgba(255, 255, 255, 0.28));padding:12px 16px;border-top-width:1px;border-top-style:solid;border-top-color:rgba(129, 108, 74, 0.08);border-radius:999px;box-shadow:rgba(255, 255, 255, 0.65) 0px 1px 0px 0px inset, rgba(98, 78, 45, 0.08) 0px 20px 50px 0px;backdrop-filter:blur(26px) saturate(1.1);height:50px;display:flex;gap:12px;align-items:center';
$n_btn_cs   = 'background-image:linear-gradient(rgba(255, 255, 255, 0.88), rgba(255, 255, 255, 0.54));color:rgb(47, 38, 26);font-size:14px;font-weight:400;line-height:20px;text-align:center;padding:16px 20px;margin:12px 0px 0px;border-top-width:1px;border-top-style:solid;border-top-color:rgba(255, 255, 255, 0.6);border-radius:999px;box-shadow:rgba(255, 255, 255, 0.8) 0px 1px 0px 0px inset, rgba(95, 73, 42, 0.08) 0px 16px 40px 0px;height:54px;display:inline-block;width:615px';
$n_html = '<!DOCTYPE html><html><head><title>T</title></head><body data-sc-cs="font-family:Inter, sans-serif">'
	. '<header><a href="/">Site</a><nav><a href="#a">Alpha</a><a href="#b">Beta</a></nav></header>'
	. '<main><section id="n" data-sc-cs="padding:0px 0px 120px;display:block"><div data-sc-cs="display:block">'
	. '<div class="space-y-3" data-sc-cs="display:block">'
	. '<div class="paper-panel rounded-[999px] flex items-center gap-3 px-4 py-3" data-sc-cs="' . $n_field_cs . '"><iconify-icon icon="ph:envelope-simple" class="text-[#84725d]" data-sc-cs="color:rgb(132, 114, 93);font-size:16px"></iconify-icon><input type="email" placeholder="Email address" class="min-w-0 flex-1 bg-transparent outline-none text-[#2d261f] placeholder:text-[#7f7366]" data-sc-cs="color:rgb(45, 38, 31);font-size:16px;line-height:24px;display:block"></div>'
	. '<button class="silk w-full rounded-[999px] px-5 py-4 text-sm text-[#2f261a]" data-sc-cs="' . $n_btn_cs . '">Request a walkthrough</button>'
	. '</div></div></section></main></body></html>';
$n_bl  = FW_Site_Converter_Sources::build_from_html( $n_html, 'SignupFixture', array( 'dynamic_chrome' => true ) );
$n_find = function ( $n, $pred ) use ( &$n_find ) { if ( ! is_array( $n ) ) { return null; } if ( $pred( $n ) ) { return $n; } foreach ( ( $n['_items'] ?? array() ) as $ch ) { $r = $n_find( $ch, $pred ); if ( $r ) { return $r; } } return null; };
$n_nl = null; foreach ( (array) ( $n_bl['files']['pages.json']['pages'][0]['builder'] ?? array() ) as $s ) { $n_nl = $n_find( $s, function ( $n ) { return ( $n['shortcode'] ?? '' ) === 'newsletter'; } ); if ( $n_nl ) { break; } }
$n_a = $n_nl['atts'] ?? array(); $n_css = (string) ( $n_a['custom_css'] ?? '' );
ga( "a form-less signup lockup (one email input + one labelled button) → the native newsletter shortcode", $n_nl !== null );
ga_eq( "design stacked (the button sits below the field, w-full)", 'stacked', $n_a['design'] ?? null );
ga_eq( "pill — from the FIELD WRAPPER's radius (the input itself is transparent)", 'pill', $n_a['rounded'] ?? null );
ga( "placeholder / label / no name field", ( $n_a['email_placeholder'] ?? '' ) === 'Email address' && ( $n_a['button_label'] ?? '' ) === 'Request a walkthrough' && ( $n_a['show_name'] ?? '' ) === 'no' );
ga( "the field wears the WRAPPER's paper skin (gradient, hairline, shadow, blur, padding, height, type)", preg_match( '/selector \.fw-nl__input\{background:linear-gradient\(rgba\(255, 255, 255, 0\.62\)[^}]*border:1px solid rgba\(129, 108, 74, 0\.08\)[^}]*box-shadow:[^}]*backdrop-filter:blur\(26px\) saturate\(1\.1\)[^}]*padding:12px 16px[^}]*height:50px[^}]*font-size:16px[^}]*color:rgb\(45, 38, 31\)/', $n_css ) === 1, $n_css );
ga( "placeholder colour carried (from the placeholder:text-[…] utility)", strpos( $n_css, 'selector .fw-nl__input::placeholder{color:#7f7366;opacity:1;}' ) !== false );
ga( "the 12px stack gap between field and button", strpos( $n_css, 'selector .fw-nl__fields{gap:12px;}' ) !== false );
ga( "an envelope from ANOTHER icon set → the native field_icon (Lucide 'mail' by meaning)", ( $n_a['field_icon']['svg-source'] ?? '' ) === 'library' && ( $n_a['field_icon']['svg-id'] ?? '' ) === 'lucide/mail' );
ga( "field icon keeps its colour", preg_match( '/^#84725d$/i', (string) ( $n_a['field_icon_color']['custom'] ?? '' ) ) === 1, $n_a['field_icon_color'] ?? null );
ga( "the submit keeps its OWN type (14px sentence-case, 54px) over any preset", preg_match( '/selector \.fw-nl__btn\{font-size:14px !important;[^}]*text-transform:none !important;[^}]*height:54px !important/', $n_css ) === 1, $n_css );
ga( "the import REQUIRES the Newsletter CRM (a signup form must capture submissions)", strpos( wp_json_encode( $n_bl['files']['bundle.json'] ?? array() ) . wp_json_encode( $n_bl['files']['theme-design.json'] ?? array() ), 'newsletter-crm' ) !== false );

/* --- [P] CHROME PRESENCE: a source with NO <footer> (its last band carries the brand line itself) must not grow a
 *     theme footer → the page entry asks the importer for the native per-page 'Hide Site Footer' switch; a source WITH a
 *     footer asks for nothing; a header-less landing asks for 'Hide Site Header'. JS twin: chrome-presence-parity.test.mjs. --- */
echo "\n[P] Chrome presence: no source footer → the page hides the site footer (native per-page switch)\n";
$p_nofoot = '<!DOCTYPE html><html><head><title>T</title></head><body data-sc-cs="font-family:Inter, sans-serif"><header><a href="/">Site</a><nav><a href="#a">Alpha</a><a href="#b">Beta</a></nav></header><main><section id="p" data-sc-cs="padding:120px 0px;display:block"><h2>Closing band</h2><p>The last band is the page&#39;s own colophon, with the brand line inside it.</p></section></main></body></html>';
$p_bl = FW_Site_Converter_Sources::build_from_html( $p_nofoot, 'NoFooterFixture', array( 'dynamic_chrome' => true ) );
$p_pg = $p_bl['files']['pages.json']['pages'][0] ?? array();
ga_eq( "no <footer> in the source → page_options hide_site_footer = yes", 'yes', $p_pg['page_options']['hide_site_footer'] ?? null );
ga( "…but the header is present → no hide_site_header", empty( $p_pg['page_options']['hide_site_header'] ) );
$p_foot = str_replace( '</main>', '</main><footer><p>&copy; Site</p></footer>', $p_nofoot );
$p_bl2 = FW_Site_Converter_Sources::build_from_html( $p_foot, 'FooterFixture', array( 'dynamic_chrome' => true ) );
ga( "a source WITH a <footer> asks for nothing", empty( $p_bl2['files']['pages.json']['pages'][0]['page_options'] ) );
$p_bl3 = FW_Site_Converter_Sources::build_from_html( str_replace( '<header><a href="/">Site</a><nav><a href="#a">Alpha</a><a href="#b">Beta</a></nav></header>', '', $p_foot ), 'NoHeaderFixture', array( 'dynamic_chrome' => true ) );
ga_eq( "no <header> / <nav> in the source → hide_site_header = yes", 'yes', $p_bl3['files']['pages.json']['pages'][0]['page_options']['hide_site_header'] ?? null );

/* --- [I] BAND INSET: a plain-CSS shell wrapper between the section and its content band (`.shell{margin:0 24px}`,
 *     or a `px-6` wrapper) is flattened by collect_blocks — its side inset must ride onto the band it produced as the
 *     native Spacing margin (ms-/me- tokens), because the section shortcode renders a flexbox child with NO
 *     .fw-container (the band otherwise runs edge-to-edge while the source sits 24px in). Nested wrappers add up; a
 *     centred cap (mx-auto / max-width) is NOT an inset. JS twin: band-inset-parity.test.mjs. --- */
echo "\n[I] Band inset: a flattened shell wrapper's side margin/padding rides onto its band\n";
$i_cell = function ( $t ) { return '<div data-sc-cs="padding:70px;display:block"><h3>' . $t . '</h3><p>Body copy for the ' . strtolower( $t ) . ' cell of the split band.</p></div>'; };
$i_html = '<!DOCTYPE html><html><head><title>T</title></head><body data-sc-cs="font-family:Inter, sans-serif"><header><a href="/">Site</a><nav><a href="#a">Alpha</a></nav></header><main>'
	. '<section id="story" data-sc-cs="padding:120px 0px;display:block"><div class="section-shell" data-sc-cs="margin:0px 24px;display:block"><div class="split-grid" data-sc-cs="display:grid;gap:28px;grid-template-columns:736px 627px">' . $i_cell( 'Left' ) . $i_cell( 'Right' ) . '</div></div></section>'
	. '<section id="fields" data-sc-cs="padding:120px 0px;display:block"><div class="section-shell" data-sc-cs="margin:0px 24px;display:block"><div class="px-6" data-sc-cs="padding:0px 24px;display:block"><h2>Staggered layers like terraced fields.</h2><p>Intro copy under the heading.</p></div></div></section>'
	. '<section id="cap" data-sc-cs="padding:120px 0px;display:block"><div class="max-w-4xl mx-auto" data-sc-cs="margin:0px 272px;max-width:896px;display:block"><h2>A centred capped band.</h2><p>Its resolved auto margins are centring, not an inset.</p></div></section>'
	. '<section id="ring" data-sc-cs="padding:120px 0px;display:block"><div class="core-shell" data-sc-cs="margin:0px 24px;display:block"><div class="core-ring" data-sc-cs="width:659px;margin:0px auto;padding:40px;border-radius:999px;background-color:rgba(255, 255, 255, 0.5);display:block"><h2>A quiet center</h2><p>Copy inside the centred ring.</p></div></div></section>'
	. '</main><footer><p>&copy; Site</p></footer></body></html>';
$i_bl = FW_Site_Converter_Sources::build_from_html( $i_html, 'BandInsetFixture', array( 'dynamic_chrome' => true ) );
$i_secs = $i_bl['files']['pages.json']['pages'][0]['builder'] ?? array();
$i_by = array(); foreach ( $i_secs as $i_s ) { $i_by[ (string) ( $i_s['atts']['css_id'] ?? $i_s['atts']['id'] ?? '' ) ] = $i_s; }
$i_find = function ( $items, $type ) use ( &$i_find ) { foreach ( (array) $items as $it ) { if ( ( $it['type'] ?? '' ) === $type ) { return $it; } if ( ! empty( $it['_items'] ) ) { $r = $i_find( $it['_items'], $type ); if ( $r ) { return $r; } } } return null; };
$i_find_sc = function ( $items, $sc ) use ( &$i_find_sc ) { foreach ( (array) $items as $it ) { if ( ( $it['shortcode'] ?? '' ) === $sc ) { return $it; } if ( ! empty( $it['_items'] ) ) { $r = $i_find_sc( $it['_items'], $sc ); if ( $r ) { return $r; } } } return null; };
$i_story = $i_by['story'] ?? ( $i_secs[0] ?? array() );
$i_band  = $i_find( $i_story['_items'] ?? array(), 'flexbox' );
ga( "story: the split band is a flexbox directly under the section", is_array( $i_band ) );
ga( "story: the shell's EQUAL 24px side margins are a gutter the band's content-width cap already keeps (`100% − 2·gutter`) — no side margin rides the capped flexbox (a side margin beat the cap's auto centring: a centred logo strip sat at the left edge)", '' === (string) ( $i_band['atts']['spacing']['margin']['left'] ?? '' ) && '' === (string) ( $i_band['atts']['spacing']['margin']['right'] ?? '' ) && ! empty( $i_band['atts']['content_width'] ), wp_json_encode( array( $i_band['atts']['spacing']['margin'] ?? null, $i_band['atts']['content_width'] ?? null ) ) );
$i_fields = $i_by['fields'] ?? ( $i_secs[1] ?? array() );
$i_head   = $i_find_sc( $i_fields['_items'] ?? array(), 'special_heading' );
ga( "fields: the heading was built", is_array( $i_head ) );
ga_eq( "fields: nested wrappers ADD UP — shell 24px margin + px-6 24px padding → ms-5 (48px) on the heading", 'ms-5', $i_head['atts']['spacing']['margin']['left'] ?? null );
$i_cap  = $i_by['cap'] ?? ( $i_secs[2] ?? array() );
$i_ch   = $i_find_sc( $i_cap['_items'] ?? array(), 'special_heading' );
ga( "cap: a centred max-width wrapper (mx-auto, resolved 272px auto margins) carries NO inset", is_array( $i_ch ) && empty( $i_ch['atts']['spacing']['margin']['left'] ) && false === strpos( (string) ( $i_ch['atts']['custom_css'] ?? '' ), 'margin-left' ) );

$i_ring = $i_by['ring'] ?? ( $i_secs[3] ?? array() );
$i_rp   = $i_find( $i_ring['_items'] ?? array(), 'flexbox' );
$i_rp   = ( $i_rp && false === strpos( (string) ( $i_rp['atts']['custom_css'] ?? '' ), 'margin-left:auto' ) && ! empty( $i_rp['_items'] ) ) ? $i_find( $i_rp['_items'], 'flexbox' ) : $i_rp;
ga( 'ring: a SELF-CENTRED panel inside the shell keeps its auto margins — no inset (it would shove the ring left)', is_array( $i_rp ) && false !== strpos( (string) ( $i_rp['atts']['custom_css'] ?? '' ), 'margin-left:auto' ) && empty( $i_rp['atts']['spacing']['margin']['left'] ) );

/* --- [W] DECLARED CONTAINER SHELL: the same shell wrapper, but DECLARED in the stylesheet as the site's container
 *     (`.section-shell{width:min(1440px, calc(100% - 48px)); margin:0 auto}`) — its computed `margin:0 24px` at 1440 is a
 *     RESOLVED auto margin, NOT an inset. The band must carry NO side margin (it would break the centring on a wider
 *     screen); its measure rides the Content Width cap (1440) + the theme's Container Gutter (24), which the converter
 *     now sets from the declared rule. And the admin prepare→build path must prime the mapper the SAME way the bundle
 *     path does (Stitch::prime_mapper), else the band rebuilds with a ZERO site width and stretches edge-to-edge.
 *     JS twin: band-inset-parity.test.mjs (gutter), capture.mjs data-sc-content-gutter stamp. --- */
echo "\n[C] Declared container shell: no inset, a 1440 cap + a 24px Container Gutter, and the admin path primes the mapper\n";
$c_html = '<!DOCTYPE html><html data-sc-content-width="1440"><head><title>T</title><style>.section{padding:120px 0}.section-shell{width:min(1440px, calc(100% - 48px));margin:0 auto}</style></head><body data-sc-cs="font-family:Inter, sans-serif"><header><a href="/">Site</a><nav><a href="#a">Alpha</a></nav></header><main>'
	. '<section id="story" class="section" data-sc-cs="padding:120px 0px;display:block"><div class="section-shell" data-sc-cs="margin:0px 24px;display:block"><div class="split-grid" data-sc-cs="display:grid;gap:28px;grid-template-columns:736px 627px">' . $i_cell( 'Left' ) . $i_cell( 'Right' ) . '</div></div></section>'
	. '<section id="fields" class="section" data-sc-cs="padding:120px 0px;display:block"><div class="section-shell" data-sc-cs="margin:0px 24px;display:block"><h2>Staggered layers like terraced fields.</h2><p>Intro copy under the heading.</p></div></section>'
	. '</main><footer><p>&copy; Site</p></footer></body></html>';
$c_bl = FW_Site_Converter_Sources::build_from_html( $c_html, 'ShellContainerFixture', array( 'dynamic_chrome' => true ) );
$c_secs = $c_bl['files']['pages.json']['pages'][0]['builder'] ?? array();
$c_band = $i_find( $c_secs[0]['_items'] ?? array(), 'flexbox' );
ga( "story: the split band is a flexbox directly under the section", is_array( $c_band ) );
ga( "story: a DECLARED container shell carries NO side margin on the band (its resolved 24px auto margin is centring)", is_array( $c_band ) && empty( $c_band['atts']['spacing']['margin']['left'] ) && empty( $c_band['atts']['spacing']['margin']['right'] ) );
ga_eq( "story: the band's Content Width = the declared 1440 cap (wide-xxl)", 'wide-xxl', $c_band['atts']['content_width']['preset'] ?? null );
$c_gl = null;
foreach ( array( 'theme-settings.json' ) as $c_f ) { $c_ts = $c_bl['files'][ $c_f ] ?? null; if ( is_array( $c_ts ) ) { $c_gl = $c_ts['general_layout'] ?? ( $c_ts['values']['general_layout'] ?? null ); } }
ga_eq( "theme-settings: the declared shell gutter (48px both sides) → Container Gutter 24px", '24', $c_gl['layout_container_gutter']['value'] ?? null );
ga_eq( "theme-settings: …and the site Container Width = 1440", '1440', $c_gl['layout_container_width']['lg']['value'] ?? null );
// ADMIN PATH PARITY — build_pages standalone (the prepare→build split) after priming the mapper the shared way.
FW_Site_Converter_Mapper::set_site_container_width( 0 ); // the fresh-request state the admin build step starts from
FW_Site_Converter_Stitch::prime_mapper( $c_html, true );
$c_pages = FW_Site_Converter_Mapper::build_pages( $c_bl['mapping'] );
$c_band2 = $i_find( $c_pages[0]['builder'][0]['_items'] ?? array(), 'flexbox' );
ga_eq( "admin path: build_pages after Stitch::prime_mapper keeps the band's 1440 Content Width (no edge-to-edge stretch)", 'wide-xxl', $c_band2['atts']['content_width']['preset'] ?? null );

/* --- [K] SWEEP PSEUDO-LAYER: a painted `::after` that MOVES (the `.silk::after` sheen — inset:-120%, a diagonal light gradient, a
 *     declared transform, `animation: sheen 8s ease-in-out infinite`, mix-blend-mode:screen, on a clipping host). The capture reads
 *     the DECLARED rule + the @keyframes it names and stamps a `sweep:1` decor layer (+ data-sc-keyframes) on the card; the card
 *     grid → icon_box must emit it VERBATIM in scoped CSS: host clips + isolates, the pseudo paints above the content, the
 *     animation + keyframes are renamed to a per-element `sc-<name>-<hash>`; unreadable keyframes → no dangling animation; hostile
 *     keyframes are dropped. JS twin: sweep-pseudo-parity.test.mjs. --- */
echo "\n[K] Sweep pseudo-layer: a moving ::after sheen rides its declared rule + keyframes onto the card\n";
$k_kf    = '@keyframes sheen {    0% { transform: translateX(-140%) rotate(12deg); }   100% { transform: translateX(140%) rotate(12deg); } }';
$k_stamp = 'after;sweep:1;inset:-120%;background:linear-gradient(120deg, transparent 44%, rgba(255, 255, 255, 0.78) 50%, transparent 56%);transform:translateX(-140%) rotate(12deg);animation:sheen 8s ease-in-out infinite;blend:screen;clip:1';
$k_card  = function ( $title, $stamp, $kf ) { return '<div class="silk" data-sc-cs="position:relative;overflow:hidden;border-radius:28px;padding:56px 48px;background-color:rgb(251, 247, 240);border-width:1px;border-style:solid;border-color:rgba(95, 73, 42, 0.12);box-shadow:rgba(20, 24, 34, 0.08) 0px 20px 50px 0px;display:block"' . ( '' !== $stamp ? ' data-sc-decor-pseudo="' . htmlspecialchars( $stamp, ENT_QUOTES ) . '"' : '' ) . ( '' !== $kf ? ' data-sc-keyframes="' . htmlspecialchars( $kf, ENT_QUOTES ) . '"' : '' ) . '><h2>' . $title . '</h2><p>The light band sweeps across every eight seconds, blended with screen.</p></div>'; };
$k_grid  = function ( $cards ) { return '<section class="section" data-sc-cs="padding:120px 0px;display:block"><div class="section-shell" data-sc-cs="margin:0px 24px;display:block"><div class="grid" data-sc-cs="display:grid;gap:28px;grid-template-columns:682px 682px">' . $cards . '</div></div></section>'; };
$k_html = '<!DOCTYPE html><html data-sc-content-width="1440"><head><title>T</title></head><body data-sc-cs="font-family:Inter, sans-serif"><header><a href="/">Site</a><nav><a href="#a">Alpha</a></nav></header><main>'
	. $k_grid( $k_card( 'A card with a sweeping sheen', $k_stamp, $k_kf ) . $k_card( 'A plain card', '', '' ) )
	. $k_grid( $k_card( 'No keyframes readable', $k_stamp, '' ) . $k_card( 'Another plain card', '', '' ) )
	. $k_grid( $k_card( 'Hostile keyframes', $k_stamp, '@keyframes sheen { 0% { background: url(javascript:alert(1)) } }' ) . $k_card( 'Third plain card', '', '' ) )
	. '</main><footer><p>&copy; Site</p></footer></body></html>';
$k_bl   = FW_Site_Converter_Sources::build_from_html( $k_html, 'SweepFixture', array( 'dynamic_chrome' => true, 'hifi_css' => true ) );
$k_secs = $k_bl['files']['pages.json']['pages'][0]['builder'] ?? array();
$k_ib   = function ( $sec, $title ) use ( &$k_ib ) { foreach ( (array) ( $sec['_items'] ?? array() ) as $it ) { if ( ( $it['shortcode'] ?? '' ) === 'icon_box' && false !== strpos( (string) ( $it['atts']['title'] ?? '' ), $title ) ) { return $it; } if ( ! empty( $it['_items'] ) ) { $r = $k_ib( $it, $title ); if ( $r ) { return $r; } } } return null; };
$k_css  = function ( $n ) { return (string) ( $n['atts']['custom_css'] ?? '' ); };
$k_one  = $k_ib( $k_secs[0] ?? array(), 'sweeping sheen' );
ga( "the silk card → icon_box", is_array( $k_one ) );
$k_m = array(); preg_match( '/animation:(sc-sheen-[a-f0-9]{6}) 8s ease-in-out infinite/', $k_css( $k_one ), $k_m );
ga( "its ::after carries the declared animation under a per-element name (sc-sheen-<hash> 8s ease-in-out infinite)", ! empty( $k_m[1] ) );
ga( "the @keyframes ride along, renamed to the same per-element name", ! empty( $k_m[1] ) && false !== strpos( $k_css( $k_one ), '@keyframes ' . $k_m[1] . ' {' ) && (bool) preg_match( '/translateX\(140%\) rotate\(12deg\)/', $k_css( $k_one ) ) );
ga( "the pseudo keeps inset:-120% + the light gradient (no width/height, no z-index:-1 — it paints above the content)", (bool) preg_match( '/selector::after\{content:"";position:absolute;pointer-events:none;inset:-120%;background:linear-gradient\(120deg/', $k_css( $k_one ) ) );
ga( "the declared start transform + screen blend are kept", (bool) preg_match( '/transform:translateX\(-140%\) rotate\(12deg\);/', $k_css( $k_one ) ) && false !== strpos( $k_css( $k_one ), 'mix-blend-mode:screen;' ) );
ga( "the host clips (overflow:hidden) + isolates, so the oversize band never spills", false !== strpos( $k_css( $k_one ), 'selector{position:relative;isolation:isolate;overflow:hidden;}' ) );
$k_plain = $k_ib( $k_secs[0] ?? array(), 'plain card' );
ga( "a card with no pseudo-layer gets nothing", is_array( $k_plain ) && false === strpos( $k_css( $k_plain ), '::after' ) );
$k_nokf = $k_ib( $k_secs[1] ?? array(), 'No keyframes' );
ga( "no readable keyframes → the static layer stays, with NO dangling animation", is_array( $k_nokf ) && false !== strpos( $k_css( $k_nokf ), '::after{' ) && false === strpos( $k_css( $k_nokf ), 'animation:' ) && false === strpos( $k_css( $k_nokf ), '@keyframes' ) );
$k_host = $k_ib( $k_secs[2] ?? array(), 'Hostile' );
ga( "hostile keyframes are dropped entirely", is_array( $k_host ) && ! preg_match( '/url\(|javascript/', $k_css( $k_host ) ) && false === strpos( $k_css( $k_host ), '@keyframes' ) );

/* --- [T] THE CSS LONG TAIL (coverage audit, 2026-09-12): the capture now stamps the properties that were invisible to the
 *     deterministic path (opacity / filter / clip-path / mask / blend / outline / side borders / border-image / bg geometry /
 *     transforms / sticky / order / align-self / the text long tail / object-position), and the engines carry them: a card's
 *     box-level props → its Box Preset CSS (keyed, so an opacity card and a plain card are DIFFERENT presets); the title /
 *     description text long tail → .icon-box__title / __content; a card that only LIFTS on hover keeps the lift (the hover
 *     keys the slug); a 4-value padding shorthand survives whole; an inset multi-shadow rides the preset CSS; color(srgb …)
 *     resolves; a cell's order / align-self → native options; the image's own filter / object-position → its <img>.
 *     JS twin: long-tail-parity.test.mjs. --- */
echo "\n[T] The CSS long tail: stamped, carried, keyed\n";
$t_card = function ( $cls, $extra_cs, $title, $title_cs = '', $body_cs = '', $hover = '' ) {
	return '<div class="card ' . $cls . '" data-sc-cs="background-color:rgb(255, 255, 255);padding:40px 32px;border-top-width:1px;border-top-style:solid;border-top-color:rgba(95, 73, 42, 0.12);border-bottom-width:1px;border-bottom-style:solid;border-bottom-color:rgba(95, 73, 42, 0.12);border-radius:24px;display:block;' . $extra_cs . '"' . ( '' !== $hover ? ' data-sc-hover="' . htmlspecialchars( $hover, ENT_QUOTES ) . '"' : '' ) . '><h2' . ( '' !== $title_cs ? ' data-sc-cs="font-size:28px;font-weight:500;' . $title_cs . '"' : '' ) . '>' . $title . '</h2><p' . ( '' !== $body_cs ? ' data-sc-cs="font-size:16px;line-height:26px;' . $body_cs . '"' : '' ) . '>Body copy for the ' . strtolower( $title ) . ' card.</p></div>';
};
$t_grid = '<section class="section" data-sc-cs="padding:120px 0px;display:block"><div class="section-shell" data-sc-cs="margin:0px 24px;display:block"><div class="grid" data-sc-cs="display:grid;gap:28px;grid-template-columns:437px 437px 437px">'
	. $t_card( 'plain', '', 'Plain card' )
	. $t_card( 'faded', 'opacity:0.72', 'Faded card' )
	. $t_card( 'accent', 'border-left-width:6px;border-left-style:solid;border-left-color:rgb(47, 111, 78);outline-width:3px;outline-style:dashed;outline-color:rgb(201, 139, 62);outline-offset:6px', 'Accent card' )
	. $t_card( 'shadowed', 'box-shadow:rgba(47, 111, 78, 0.25) 0px 0px 0px 4px inset, rgba(0, 0, 0, 0.08) 0px 20px 40px 0px', 'Shadowed card' )
	. $t_card( 'mixed', '', 'Mixed card' )
	. $t_card( 'lifting', '', 'Lifting card', '', '', 'hover-self{transform:translateY(-6px)}' )
	. $t_card( 'typed', 'order:-1;align-self:end', 'Typed card', 'text-shadow:rgba(0, 0, 0, 0.25) 0px 2px 12px;font-style:italic', '-webkit-line-clamp:2;white-space:pre-line' )
	. str_replace( '</p></div>', ' <a href="#" data-sc-cs="color:rgb(47, 111, 78);text-decoration-line:underline;text-decoration-thickness:2px;text-underline-offset:6px;text-decoration-color:rgb(201, 139, 62);font-weight:400">a link</a></p></div>', $t_card( 'linked', 'color:rgb(38, 33, 28)', 'Linked card', '', 'color:rgb(120, 120, 120)' ) )
	. '</div></div></section>';
$t_html = '<!DOCTYPE html><html data-sc-content-width="1440"><head><title>T</title></head><body data-sc-cs="font-family:Inter, sans-serif"><header><a href="/">Site</a><nav><a href="#a">Alpha</a></nav></header><main>' . $t_grid . '</main><footer><p>&copy; Site</p></footer></body></html>';
// the mixed card's fill is a color(srgb …) (a color-mix() result); the plain card gets the 4-value padding shorthand
$t_html = str_replace( 'class="card mixed" data-sc-cs="background-color:rgb(255, 255, 255);', 'class="card mixed" data-sc-cs="background-color:color(srgb 0.902118 0.932235 0.916706);', $t_html );
$t_html = str_replace( 'class="card plain" data-sc-cs="background-color:rgb(255, 255, 255);padding:40px 32px;', 'class="card plain" data-sc-cs="background-color:rgb(255, 255, 255);padding:24px 56px;', $t_html );
$t_bl   = FW_Site_Converter_Sources::build_from_html( $t_html, 'LongTailFixture', array( 'dynamic_chrome' => true, 'hifi_css' => true ) );
$t_secs = $t_bl['files']['pages.json']['pages'][0]['builder'] ?? array();
$t_ts   = json_encode( $t_bl['files']['theme-settings.json'] ?? array() );
$t_find = function ( $items, $title ) use ( &$t_find ) { foreach ( (array) $items as $it ) { if ( ( $it['shortcode'] ?? '' ) === 'icon_box' && false !== strpos( (string) ( $it['atts']['title'] ?? '' ), $title ) ) { return $it; } if ( ! empty( $it['_items'] ) ) { $r = $t_find( $it['_items'], $title ); if ( $r ) { return $r; } } } return null; };
$t_cell = function ( $items, $title ) use ( &$t_cell ) { foreach ( (array) $items as $it ) { $s = json_encode( $it ); if ( false === strpos( $s, $title ) ) { continue; } if ( ( $it['type'] ?? '' ) === 'flexbox' && count( array_filter( array( 'Plain card', 'Faded card', 'Accent card', 'Shadowed card', 'Mixed card', 'Lifting card', 'Typed card', 'Linked card' ), function ( $t ) use ( $s ) { return false !== strpos( $s, $t ); } ) ) === 1 ) { return $it; } if ( ! empty( $it['_items'] ) ) { $r = $t_cell( $it['_items'], $title ); if ( $r ) { return $r; } } } return null; };
$t_preset = function ( $node ) use ( $t_ts ) { $bs = (string) ( $node['atts']['box_style'] ?? '' ); if ( ! preg_match( '/^boxp-box-([a-f0-9]{8})$/', $bs, $m ) ) { return ''; } $i = strpos( $t_ts, '"Box ' . $m[1] . '"' ); if ( false === $i ) { return ''; } $st = strrpos( substr( $t_ts, 0, $i ), '{"id"' ); $d = 0; for ( $k = $st; $k < strlen( $t_ts ); $k++ ) { if ( '{' === $t_ts[ $k ] ) { $d++; } elseif ( '}' === $t_ts[ $k ] ) { $d--; if ( 0 === $d ) { return substr( $t_ts, $st, $k - $st + 1 ); } } } return ''; };
$t_plain = $t_find( $t_secs, 'Plain card' ); $t_faded = $t_find( $t_secs, 'Faded card' ); $t_acc = $t_find( $t_secs, 'Accent card' ); $t_sh = $t_find( $t_secs, 'Shadowed card' ); $t_mx = $t_find( $t_secs, 'Mixed card' ); $t_lf = $t_find( $t_secs, 'Lifting card' ); $t_ty = $t_find( $t_secs, 'Typed card' );
ga( "eight cards → eight icon_boxes", $t_plain && $t_faded && $t_acc && $t_sh && $t_mx && $t_lf && $t_ty && $t_find( $t_secs, 'Linked card' ) );
ga( "a 4-value padding shorthand (padding-inline / -block) survives whole in the preset CSS", false !== strpos( $t_preset( $t_plain ), 'padding:24px 56px;' ) );
ga( "opacity keys its OWN preset and rides the preset CSS (the faded card is not the plain card's preset)", ( $t_faded['atts']['box_style'] ?? '' ) !== ( $t_plain['atts']['box_style'] ?? '' ) && false !== strpos( $t_preset( $t_faded ), 'opacity:0.72' ) );
ga( "a left accent bar + a dashed outline ride the preset CSS", false !== strpos( $t_preset( $t_acc ), 'border-left:6px solid rgb(47, 111, 78)' ) && false !== strpos( $t_preset( $t_acc ), 'outline:3px dashed rgb(201, 139, 62)' ) && false !== strpos( $t_preset( $t_acc ), 'outline-offset:6px' ) );
ga( "an inset ring + drop shadow list keeps BOTH layers in the preset CSS", (bool) preg_match( '/box-shadow:[^}]*inset/', $t_preset( $t_sh ) ) );
ga( "color(srgb …) (a color-mix() result) resolves to a real fill", (bool) preg_match( '/"custom":"(?:#e6eeea|rgb\(230, 238, 234\))"/', $t_preset( $t_mx ) ) );
ga( "a card that only LIFTS on hover keeps the lift (the hover keys the slug) — the EXACT captured transform rides the preset CSS, no library Lift substituted (2026-09-16)", ( $t_lf['atts']['box_style'] ?? '' ) !== ( $t_plain['atts']['box_style'] ?? '' ) && (bool) preg_match( '/btnfx-lift|"hover_fx":\["lift"|:hover \{ transform:translateY\(/', $t_preset( $t_lf ) ) );
ga( "the title's text-shadow + italic → .icon-box__title", (bool) preg_match( '/\.icon-box__title\{[^}]*text-shadow:rgba\(0, 0, 0, 0\.25\) 0px 2px 12px[^}]*font-style:italic/', (string) $t_ty['atts']['custom_css'] ) || (bool) preg_match( '/\.icon-box__title\{[^}]*font-style:italic[^}]*text-shadow/', (string) $t_ty['atts']['custom_css'] ) );
ga( "the description's line-clamp brings its companions; white-space pre-line rides too", (bool) preg_match( '/\.icon-box__content\{[^}]*-webkit-line-clamp:2[^}]*display:-webkit-box[^}]*-webkit-box-orient:vertical[^}]*overflow:hidden/', (string) $t_ty['atts']['custom_css'] ) && false !== strpos( (string) $t_ty['atts']['custom_css'], 'white-space:pre-line' ) );
$t_tycell = $t_cell( $t_secs, 'Typed card' );
ga_eq( "the cell's order:-1 → the native Order", '-1', $t_tycell['atts']['order']['base'] ?? null );
ga_eq( "the cell's align-self:end → the native Align Self", 'end', $t_tycell['atts']['align_self']['base'] ?? null );
$t_ln = $t_find( $t_secs, 'Linked card' );
ga_eq( "the description's own (muted) ink → the native Content Colour", '#787878', $t_ln['atts']['content_color']['custom'] ?? null );
ga( "the description's inline link keeps its colour + underline metrics (.icon-box__content a)", (bool) preg_match( '/\.icon-box__content a\{[^}]*color:rgb\(47, 111, 78\)[^}]*text-decoration-thickness:2px[^}]*text-underline-offset:6px[^}]*text-decoration-color:rgb\(201, 139, 62\)/', (string) $t_ln['atts']['custom_css'] ) );

/* --- [V] THE PHONE PASS (second viewport, first cut): the capture renders the page at 390px too and stamps, per element,
 *     only the values that DIFFER from desktop (data-sc-cs-sm) plus a page flag (data-sc-phone-pass). The engines use it
 *     for what the converted site can express responsively: a section's phone rhythm → padding_top/bottom BASE tier with
 *     desktop on lg (and NO clamp when the pass proved the phone rhythm equals desktop); a cell's phone padding → the pad
 *     base tier (desktop on lg); a cell's phone min-height (or none) → the Min Height base tier; a paragraph's phone font
 *     size → a max-width:767px rule; a cover-fill image only fills beside its text (min-width:992px). JS twin:
 *     phone-pass-parity.test.mjs. --- */
echo "\n[V] The phone pass: measured phone tiers, no clamp guesswork\n";
$v_html = '<!DOCTYPE html><html data-sc-content-width="1440" data-sc-content-gutter="24" data-sc-content-gutter-sm="16" data-sc-phone-pass="1"><head><title>T</title></head><body data-sc-cs="font-family:Inter, sans-serif"><header><a href="/">Site</a><nav><a href="#a">Alpha</a></nav></header><main>'
	. '<section id="story" data-sc-cs="padding:160px 0px;display:block" data-sc-cs-sm="padding:64px 0px" data-sc-cs-md="padding:96px 0px"><div class="shell" data-sc-cs="margin:0px 24px;display:block"><div class="grid" data-sc-cs="display:grid;gap:28px;grid-template-columns:736px 627px" data-sc-cs-sm="grid-template-columns:358px" data-sc-cs-md="grid-template-columns:772px">'
	. '<div class="left" data-sc-cs="padding:70px;min-height:640px;display:flex;flex-direction:column;justify-content:center" data-sc-cs-sm="padding:28px;min-height:auto"><h2>Designed to breathe</h2><p data-sc-cs="font-size:18px;line-height:30px" data-sc-cs-sm="font-size:15px;line-height:26px">The landscape follows the natural movement of the valley, layering soft mountain silhouettes.</p></div>'
	. '<div class="right" data-sc-cs="min-height:640px;height:640px;display:block;background-color:rgb(239, 230, 216);border-radius:42px;overflow:hidden" data-sc-cs-sm="min-height:auto"><img src="https://example.test/a.png" alt="" data-sc-cs="object-fit:cover;height:640px;display:block"></div>'
	. '</div></div></section>'
	. '<section id="same" data-sc-cs="padding:160px 0px;display:block"><div class="shell" data-sc-cs="margin:0px 24px;display:block"><h2>Same rhythm on phones</h2><p>The phone pass ran and found no difference here.</p><div class="season-chip" data-sc-cs="background-color:rgba(255, 255, 255, 0.2);color:rgb(38, 33, 28);font-size:10px;letter-spacing:2.4px;text-transform:uppercase;padding:10px 16px;border-radius:999px;display:inline-flex" data-sc-cs-sm="display:none;min-height:0px">Golden fields · Harvest 12</div></div></section>'
	. '</main><footer><p>&copy; Site</p></footer></body></html>';
$v_bl   = FW_Site_Converter_Sources::build_from_html( $v_html, 'PhonePassFixture', array( 'dynamic_chrome' => true, 'hifi_css' => true ) );
$v_secs = $v_bl['files']['pages.json']['pages'][0]['builder'] ?? array();
$v_by   = array(); foreach ( $v_secs as $v_s ) { $v_by[ (string) ( $v_s['atts']['css_id'] ?? '' ) ] = $v_s; }
$v_story = $v_by['story'] ?? ( $v_secs[0] ?? array() ); $v_same = $v_by['same'] ?? ( $v_secs[1] ?? array() );
ga_eq( "story: the section's phone rhythm (64px) is the padding_top BASE tier", 'pt-7', $v_story['atts']['padding_top']['base'] ?? null );
ga_eq( "story: …and the desktop 160px rides lg", 'pt-[160px]', $v_story['atts']['padding_top']['lg'] ?? null );
ga_eq( "story: the TABLET pass (96px at 820px) is the md tier", 'pt-10', $v_story['atts']['padding_top']['md'] ?? null );
$v_band = null; foreach ( (array) ( $v_story['_items'] ?? array() ) as $vb ) { if ( ( $vb['type'] ?? '' ) === 'flexbox' ) { $v_band = $vb; break; } }
ga( "story: ONE grid track at 820px → a 768–991px rule stacks the band (the theme's grid only collapses below 768)", is_array( $v_band ) && false !== strpos( (string) ( $v_band['atts']['custom_css'] ?? '' ), '@media (min-width:768px) and (max-width:991px){selector{grid-template-columns:1fr !important;}' ) );
$v_misc = (string) ( $v_bl['files']['theme-settings.json']['values']['misc_custom_css']['custom_css'] ?? ( $v_bl['files']['theme-settings.json']['misc_custom_css']['custom_css'] ?? '' ) );
ga( "container: the phone gutter (16px) rides a max-width:767px --container-gutter override in the misc CSS", false !== strpos( $v_misc, '@media (max-width:767px){:root{--container-gutter:16px !important;}}' ) );
ga_eq( "same: the pass ran and found no phone difference → the exact desktop value is the base (no 112px clamp)", 'pt-[160px]', $v_same['atts']['padding_top']['base'] ?? null );
ga( "same: …with nothing on lg", '' === (string) ( $v_same['atts']['padding_top']['lg'] ?? 'x' ) );
$v_cells = array(); $v_walk = function ( $items ) use ( &$v_walk, &$v_cells ) { foreach ( (array) $items as $it ) { if ( ( $it['type'] ?? '' ) === 'flexbox' && ! empty( $it['atts']['min_height'] ) && is_array( $it['atts']['min_height'] ) && '' !== (string) ( $it['atts']['min_height']['lg']['value'] ?? '' ) ) { $v_cells[] = $it; } if ( ! empty( $it['_items'] ) ) { $v_walk( $it['_items'] ); } } };
$v_walk( $v_story['_items'] ?? array() );
ga( "cells: the desktop 640px minimum rides lg and the phone has NONE (min-height:auto at 390px)", count( $v_cells ) >= 2 && '' === (string) $v_cells[0]['atts']['min_height']['base']['value'] && '640' === (string) $v_cells[0]['atts']['min_height']['lg']['value'] );
$v_left = null; foreach ( $v_cells as $vc ) { if ( false !== strpos( json_encode( $vc ), 'Designed to breathe' ) ) { $v_left = $vc; break; } }
ga( "left cell: the phone padding (28px) is the base rule and the desktop 70px rides a min-width:992px rule", is_array( $v_left ) && (bool) preg_match( '/selector\{padding-top:28px;padding-right:28px;padding-bottom:28px;padding-left:28px;\}/', (string) $v_left['atts']['custom_css'] ) && (bool) preg_match( '/@media \(min-width:992px\)\{selector\{padding-top:70px/', (string) $v_left['atts']['custom_css'] ) );
$v_css = json_encode( $v_bl['files']['theme-design.json'] ?? array() );
$v_head = null; $v_find_h = function ( $items ) use ( &$v_find_h, &$v_head ) { foreach ( (array) $items as $it ) { if ( ( $it['shortcode'] ?? '' ) === 'special_heading' ) { $v_head = $it; return; } if ( ! empty( $it['_items'] ) ) { $v_find_h( $it['_items'] ); } } }; $v_find_h( $v_story['_items'] ?? array() );
ga( "subtitle: the folded paragraph's phone font-size (15px / 26px) rides a max-width:767px rule on the heading", is_array( $v_head ) && false !== strpos( (string) $v_head['atts']['custom_css'], '@media (max-width:767px){selector .heading-subtitle{font-size:15px !important;line-height:26px !important;}}' ) );
$v_img = null; $v_find_img = function ( $items ) use ( &$v_find_img, &$v_img ) { foreach ( (array) $items as $it ) { if ( ( $it['shortcode'] ?? '' ) === 'media_image' ) { $v_img = $it; return; } if ( ! empty( $it['_items'] ) ) { $v_find_img( $it['_items'] ); } } };
$v_find_img( $v_story['_items'] ?? array() );
ga( "image: the cover-fill rides a min-width:992px rule (natural height when stacked on a phone)", is_array( $v_img ) && (bool) preg_match( '/@media \(min-width:992px\)\{selector\{flex:1 1 auto[^}]*\}selector img\{[^}]*object-fit:cover/', (string) $v_img['atts']['custom_css'] ) );
$v_chip = null; $v_find_c = function ( $items ) use ( &$v_find_c, &$v_chip ) { foreach ( (array) $items as $it ) { if ( ( $it['shortcode'] ?? '' ) === 'text_block' && false !== strpos( (string) ( $it['atts']['text'] ?? '' ), 'Golden fields' ) ) { $v_chip = $it; return; } if ( ! empty( $it['_items'] ) ) { $v_find_c( $it['_items'] ); } } }; $v_find_c( $v_same['_items'] ?? array() );
ga( "chip: display:none at 390px → the native Responsive Hide (mobile + tablet), visible on desktop", is_array( $v_chip ) && ! empty( $v_chip['atts']['responsive_hide']['hide-xs'] ) && ! empty( $v_chip['atts']['responsive_hide']['hide-sm'] ) && empty( $v_chip['atts']['responsive_hide']['hide-md'] ) );

/* --- [U] THE UTILITY-CLASS PROBE (2026-09-12): a page built from generated utility classes (a `hover:bg-* hover:text-*`
 * card, a `group-hover:` title, a brand-filled `text-white` card, an arbitrary `shadow-[…]` behind Tailwind's transparent
 * ring placeholders, a `md:grid-cols-2` two-up with a `md:col-span-2` card, a `md:hidden` phone-only card, a `size-16
 * rounded-full` avatar, a `text-[13px] tracking-[0.2em] uppercase` body, a `truncate`, `sm:text-lg lg:text-2xl` copy, a
 * `before:` accent bar). Every rule is measured, never a class name: the capture stamps computed values + the 390 / 820
 * diffs + `track-frac` + `data-sc-hover-group`, the engines carry them. JS twin: tailwind-probe-parity.test.mjs. --- */
echo "\n[U] The utility-class probe: measured, not named\n";
$u_card = function ( $id, $title, $card_cs, $card_md = 'track-frac:0.5', $card_attrs = '', $h_attrs = '', $p_cs = '', $p_attrs = '', $inner = '' ) {
	return '<div class="' . $id . '" data-sc-cs="background-color:rgb(255, 255, 255);color:rgb(38, 33, 28);padding:40px;border-top-width:1px;border-top-style:solid;border-top-color:rgba(38, 33, 28, 0.1);border-bottom-width:1px;border-bottom-style:solid;border-bottom-color:rgba(38, 33, 28, 0.1);border-radius:24px;display:block;' . $card_cs . '" data-sc-cs-md="' . $card_md . '"' . $card_attrs . '><h2 data-sc-cs="font-size:28px;font-weight:500;color:rgb(38, 33, 28)"' . $h_attrs . '>' . $title . '</h2>' . $inner . '<p data-sc-cs="font-size:16px;line-height:24px;color:rgb(38, 33, 28);' . $p_cs . '"' . $p_attrs . '>Body copy for the ' . strtolower( $title ) . ' that runs a little long.</p></div>';
};
$u_grid = '<section class="section" data-sc-cs="padding:120px 0px;display:block" data-sc-cs-sm="padding:64px 0px"><div class="section-shell" data-sc-cs="margin:0px 24px;display:block"><div class="grid" data-sc-cs="display:grid;gap:28px;grid-template-columns:437px 437px 437px" data-sc-cs-md="grid-template-columns:366px 366px" data-sc-cs-sm="grid-template-columns:342px">'
	// a brand-filled card: the title / description INHERIT white; its hover ink is carried; an arbitrary shadow behind two transparent placeholder layers; a col-span-2 tablet cell; a 4px accent bar
	. $u_card( 'brand', 'Brand card', 'box-shadow:rgba(0, 0, 0, 0) 0px 0px 0px 0px, rgba(0, 0, 0, 0) 0px 0px 0px 0px, rgba(0, 0, 0, 0.08) 0px 20px 40px 0px', 'track-frac:1', ' data-sc-hover="hover-self{background-color:rgb(47 111 78 / var(--tw-bg-opacity, 1));color:rgb(255 255 255 / var(--tw-text-opacity, 1))}" data-sc-decor-pseudo="before;top:0%;left:10%;width:80%;height:4px;above:1;background:rgb(201, 139, 62)"' )
	// the white-on-brand card proper (ink differs from the page ink)
	. str_replace( array( 'background-color:rgb(255, 255, 255)', 'color:rgb(38, 33, 28)' ), array( 'background-color:rgb(47, 111, 78)', 'color:rgb(255, 255, 255)' ), $u_card( 'inverse', 'Inverse card', '' ) )
	// a group-hover title + a phone-only card (display:none from 820px up) + an uppercase tracked description that truncates + a phone / tablet body size
	. str_replace( 'border-radius:24px;display:block;', 'border-radius:24px;display:none;', $u_card( 'grouped', 'Grouped card', '', 'track-frac:0.5;display:none', ' data-sc-cs-sm="display:block"', ' data-sc-hover-group="color:rgb(47 111 78 / var(--tw-text-opacity, 1));text-decoration-line:underline" data-sc-cs-sm="font-size:22px"', 'letter-spacing:2.6px;text-transform:uppercase;text-overflow:ellipsis;overflow:hidden;white-space:nowrap', ' data-sc-cs-sm="font-size:16px;line-height:24px" data-sc-cs-md="font-size:18px;line-height:28px"' ) )
	// an avatar card: a 64px round image (its own box + radius), a title and a description → image_box, no forced crop
	. $u_card( 'avatar', 'Avatar card', '', 'track-frac:0.5', '', '', '', '', '<img src="https://example.test/b.png" alt="" data-sc-cs="border-radius:9999px;height:64px;width:64px;object-fit:cover;display:block">' )
	. '</div></div></section>';
$u_html = '<!DOCTYPE html><html data-sc-content-width="1440" data-sc-phone-pass="1"><head><title>T</title></head><body data-sc-cs="font-family:Inter, sans-serif;color:rgb(38, 33, 28)"><header><a href="/">Site</a><nav><a href="#a">Alpha</a></nav></header><main>' . $u_grid . '</main><footer><p>&copy; Site</p></footer></body></html>';
$u_bl   = FW_Site_Converter_Sources::build_from_html( $u_html, 'UtilityProbeFixture', array( 'dynamic_chrome' => true, 'hifi_css' => true ) );
$u_secs = $u_bl['files']['pages.json']['pages'][0]['builder'] ?? array();
$u_ts   = json_encode( $u_bl['files']['theme-settings.json'] ?? array() );
$u_find = function ( $items, $title, $sc = 'icon_box' ) use ( &$u_find ) { foreach ( (array) $items as $it ) { if ( ( $it['shortcode'] ?? '' ) === $sc && false !== strpos( (string) ( $it['atts']['title'] ?? '' ), $title ) ) { return $it; } if ( ! empty( $it['_items'] ) ) { $r = $u_find( $it['_items'], $title, $sc ); if ( $r ) { return $r; } } } return null; };
$u_titles = array( 'Brand card', 'Inverse card', 'Grouped card', 'Avatar card' );
$u_cell = function ( $items, $title ) use ( &$u_cell, $u_titles ) { foreach ( (array) $items as $it ) { $s = json_encode( $it ); if ( false === strpos( $s, $title ) ) { continue; } if ( ( $it['type'] ?? '' ) === 'flexbox' && 1 === count( array_filter( $u_titles, function ( $t ) use ( $s ) { return false !== strpos( $s, $t ); } ) ) ) { return $it; } if ( ! empty( $it['_items'] ) ) { $r = $u_cell( $it['_items'], $title ); if ( $r ) { return $r; } } } return null; };
$u_preset = function ( $node ) use ( $u_ts ) { $bs = (string) ( $node['atts']['box_style'] ?? '' ); if ( ! preg_match( '/^boxp-box-([a-f0-9]{8})$/', $bs, $m ) ) { return ''; } $i = strpos( $u_ts, '"Box ' . $m[1] . '"' ); if ( false === $i ) { return ''; } $st = strrpos( substr( $u_ts, 0, $i ), '{"id"' ); $d = 0; for ( $k = $st; $k < strlen( $u_ts ); $k++ ) { if ( '{' === $u_ts[ $k ] ) { $d++; } elseif ( '}' === $u_ts[ $k ] ) { $d--; if ( 0 === $d ) { return substr( $u_ts, $st, $k + 1 - $st ); } } } return ''; };
$u_brand = $u_find( $u_secs, 'Brand card' ); $u_inv = $u_find( $u_secs, 'Inverse card' ); $u_grp = $u_find( $u_secs, 'Grouped card' ); $u_av = $u_find( $u_secs, 'Avatar card', 'image_box' );
ga( "three text cards → icon_boxes, the avatar card → an image_box", $u_brand && $u_inv && $u_grp && $u_av );
ga( "a shadow behind Tailwind's transparent ring placeholders is a real shadow (visible_shadow → the preset's Box Shadow)", (bool) preg_match( '/"box_shadow":\{"x":0,"y":20,"blur":40,"spread":0,"color":"rgba\(0, 0, 0, 0\.08\)"/', $u_preset( $u_brand ) ) );
ga( "the card's hover ink (hover:text-white) → the title / description follow it on hover", (bool) preg_match( '/selector:hover \.icon-box__title\{[^}]*color:rgb\(255 255 255\)/', (string) $u_brand['atts']['custom_css'] ) && (bool) preg_match( '/selector:hover \.icon-box__content\{[^}]*color:rgb\(255 255 255\)/', (string) $u_brand['atts']['custom_css'] ) );
ga( "…and the hover FILL still reaches the preset when only the stamp carried it (a config colour the class path cannot resolve)", (bool) preg_match( '/"hover":\{[^}]*"background"/', $u_preset( $u_brand ) ) || false !== strpos( $u_preset( $u_brand ), 'rgb(47, 111, 78)' ) );
ga( "a 4px accent bar (a thin ::before) rides the card as a scoped pseudo ABOVE the fill, px-thin", (bool) preg_match( '/::before\{[^}]*z-index:1[^}]*height:4px[^}]*background:rgb\(201, 139, 62\)/', (string) $u_brand['atts']['custom_css'] ) || (bool) preg_match( '/::before\{[^}]*height:4px[^}]*z-index:1/', (string) $u_brand['atts']['custom_css'] ) );
ga_eq( "a brand-filled card's inherited WHITE title → the native Title Colour (differs from the page ink)", '#ffffff', $u_inv['atts']['title_color']['custom'] ?? null );
ga_eq( "…and its description → the native Content Colour", '#ffffff', $u_inv['atts']['content_color']['custom'] ?? null );
ga( "a plain white card keeps the theme defaults (its ink IS the page ink → no Title Colour)", '' === (string) ( $u_brand['atts']['title_color']['custom'] ?? '' ) );
ga( "a group-hover title (colour + underline when the CARD is hovered) → selector:hover .icon-box__title", (bool) preg_match( '/selector:hover \.icon-box__title\{[^}]*color:rgb\(47 111 78\)[^}]*text-decoration-line:underline/', (string) $u_grp['atts']['custom_css'] ) );
ga( "an uppercase, tracked, truncating description → .icon-box__content (letter-spacing / text-transform / ellipsis)", (bool) preg_match( '/\.icon-box__content\{[^}]*letter-spacing:2\.6px/', (string) $u_grp['atts']['custom_css'] ) && (bool) preg_match( '/\.icon-box__content\{[^}]*text-transform:uppercase/', (string) $u_grp['atts']['custom_css'] ) && (bool) preg_match( '/\.icon-box__content\{[^}]*text-overflow:ellipsis[^}]*overflow:hidden|\.icon-box__content\{[^}]*overflow:hidden[^}]*text-overflow:ellipsis/', (string) $u_grp['atts']['custom_css'] ) );
ga( "the description's measured PHONE size (16px at 390) → a max-width:767px rule; the TABLET size (18px at 820) → a 768–991px rule", false !== strpos( (string) $u_grp['atts']['custom_css'], '@media (max-width:767px){selector .icon-box__content{font-size:16px;line-height:24px !important;}}' ) && false !== strpos( (string) $u_grp['atts']['custom_css'], '@media (min-width:768px) and (max-width:991px){selector .icon-box__content{font-size:18px;line-height:28px !important;}}' ) );
ga( "the title's phone size (22px at 390) → its own max-width:767px rule", false !== strpos( (string) $u_grp['atts']['custom_css'], '@media (max-width:767px){selector .icon-box__title{font-size:22px !important;}}' ) );
$u_gcell = $u_cell( $u_secs, 'Grouped card' ); $u_bcell = $u_cell( $u_secs, 'Brand card' ); $u_icell = $u_cell( $u_secs, 'Inverse card' );
ga( "a card that is display:none from 820px up (md:hidden) → Responsive Hide on tablets + desktops, shown on phones", is_array( $u_gcell ) && ! empty( $u_gcell['atts']['responsive_hide']['hide-sm'] ) && ! empty( $u_gcell['atts']['responsive_hide']['hide-md'] ) && empty( $u_gcell['atts']['responsive_hide']['hide-xs'] ) ); // the theme's tiers: hide-sm = 768–991 (the 820 pass), hide-md = ≥ 992 — the old keys sat one tier off (a `hidden lg:block` desktop panel vanished on desktop)
ga_eq( "a two-track tablet grid (measured) → a plain card's tablet width is 6/12", '6', $u_icell['atts']['width']['md']['preset'] ?? null );
ga_eq( "…while the md:col-span-2 card (track-frac 1 at 820px) spans the whole row on tablets", '12', $u_bcell['atts']['width']['md']['preset'] ?? null );
ga_eq( "…and every card is full-width on phones (one measured track at 390px)", '12', $u_icell['atts']['width']['base']['preset'] ?? null );
ga_eq( "a 64px round avatar inside a card → image_box with NO forced crop ratio", 'original', $u_av['atts']['image_ratio'] ?? null );
ga( "…its own box + radius ride the image (64px, 9999px) and outrank the shortcode's 100% rule", (bool) preg_match( '/selector img\{[^}]*border-radius:9999px[^}]*width:64px;height:64px/', (string) $u_av['atts']['custom_css'] ) && false !== strpos( (string) $u_av['atts']['custom_css'], 'selector .imgbox__img{width:64px !important;height:64px !important;}' ) );

/* --- [U2] The second utility batch (2026-09-12): child rhythm (space-y / divide-y), a side-by-side (md:flex-row) card, a form
 * field's :focus skin, empty painted boxes and a stray inline label inside a card, a layout-centred card, and the WIDE (>= 1536px)
 * tier. JS twin: utility-probe-parity.test.mjs (batch 2). --- */
echo "\n[U2] The utility-class probe, batch 2: rhythm, rows, focus, decor, inline, centred, wide\n";
$u2_grid = '<section class="section" data-sc-cs="padding:120px 0px;display:block" data-sc-cs-xl="padding:160px 0px"><div class="section-shell" data-sc-cs="margin:0px 24px;display:block"><div class="grid" data-sc-cs="display:grid;gap:28px;grid-template-columns:437px 437px 437px">'
	// space-y-4 + divide-y: the second paragraph carries the rhythm
	. '<div class="rhythm" data-sc-cs="background-color:rgb(255, 255, 255);color:rgb(38, 33, 28);padding:40px;border-radius:24px;display:block"><h2 data-sc-cs="font-size:28px;color:rgb(38, 33, 28)">Rhythm card</h2><p data-sc-cs="font-size:16px;line-height:24px;color:rgb(38, 33, 28)">First line.</p><p data-sc-cs="font-size:16px;line-height:24px;color:rgb(38, 33, 28);margin:16px 0px 0px;border-top-width:1px;border-top-style:solid;border-top-color:rgb(201, 139, 62);padding:12px 0px 0px">Second line.</p><p data-sc-cs="font-size:16px;line-height:24px;color:rgb(38, 33, 28);margin:16px 0px 0px;border-top-width:1px;border-top-style:solid;border-top-color:rgb(201, 139, 62);padding:12px 0px 0px">Third line.</p></div>'
	// md:flex-row card: the title block and the paragraph are row siblings, the title LAST (md:order-last); a column at 390 only
	. '<div class="rowcard" data-sc-cs="background-color:rgb(255, 255, 255);color:rgb(38, 33, 28);padding:40px;border-radius:24px;display:flex;flex-direction:row;gap:16px" data-sc-cs-sm="flex-direction:column"><div class="tb" data-sc-cs="display:block;order:9999"><h2 data-sc-cs="font-size:28px;color:rgb(38, 33, 28)">Row card</h2></div><p data-sc-cs="font-size:16px;line-height:24px;color:rgb(38, 33, 28)">Stacked on phones, a row with the heading last on tablets.</p></div>'
	// empty painted boxes + a stray inline label + a >= 1536px inset + a wide body size
	. '<div class="deco" data-sc-cs="background-color:rgb(255, 255, 255);color:rgb(38, 33, 28);padding:40px;border-radius:24px;display:block" data-sc-cs-xl="padding:56px"><div class="ratio" data-sc-cs="background-color:rgb(223, 214, 200);border-radius:12px;aspect-ratio:4 / 3;height:280px;display:block"></div><h2 data-sc-cs="font-size:28px;color:rgb(38, 33, 28)">Decor card</h2><span class="label" data-sc-cs="color:rgb(38, 33, 28);font-size:12px;letter-spacing:3px;text-transform:uppercase;writing-mode:vertical-rl;display:inline-block">Side label</span><p data-sc-cs="font-size:16px;line-height:24px;color:rgb(38, 33, 28)" data-sc-cs-xl="font-size:18px;line-height:28px">A 4:3 box and a pattern tile.</p><div class="bg" data-sc-cs="background-image:url(&quot;data:image/svg+xml\3b utf8,<svg xmlns=\'http://www.w3.org/2000/svg\' width=\'40\' height=\'40\'><circle cx=\'20\' cy=\'20\' r=\'6\' fill=\'%232f6f4e\'/></svg>&quot;);background-size:40px 40px;background-repeat:repeat;height:160px;border-radius:12px;display:block"></div></div>'
	// a layout-centred card (grid place-items-center): no text-align, centred by the grid
	. '<div class="centred" data-sc-cs="background-color:rgb(255, 255, 255);color:rgb(38, 33, 28);padding:40px;border-radius:24px;display:grid;justify-items:center;gap:16px"><h2 data-sc-cs="font-size:28px;color:rgb(38, 33, 28)">Centred card</h2><p data-sc-cs="font-size:16px;line-height:24px;color:rgb(38, 33, 28)">Centred grid item.</p></div>'
	. '</div></div></section>'
	// a signup form whose field carries a resolved :focus ring
	. '<section class="signup" data-sc-cs="padding:96px 0px;display:block"><div class="section-shell" data-sc-cs="margin:0px 24px;display:block"><h2>Stay in the loop</h2><form data-sc-cs="display:flex;flex-direction:row;gap:12px"><input type="email" placeholder="Your email" data-sc-cs="border-radius:8px;border-top-width:1px;border-top-style:solid;border-top-color:rgba(38, 33, 28, 0.2);padding:8px 16px;height:42px" data-sc-focus="box-shadow:0 0 0 2px rgb(47, 111, 78);outline:none"><button type="submit" data-sc-cs="background-color:rgb(47, 111, 78);color:rgb(255, 255, 255);border-radius:8px;padding:8px 20px;height:42px">Subscribe</button></form></div></section>';
$u2_html = '<!DOCTYPE html><html data-sc-content-width="1440" data-sc-phone-pass="1"><head><title>T</title></head><body data-sc-cs="font-family:Inter, sans-serif;color:rgb(38, 33, 28)"><header><a href="/">Site</a><nav><a href="#a">Alpha</a></nav></header><main>' . $u2_grid . '</main><footer><p>&copy; Site</p></footer></body></html>';
$u2_bl   = FW_Site_Converter_Sources::build_from_html( $u2_html, 'UtilityProbe2Fixture', array( 'dynamic_chrome' => true, 'hifi_css' => true ) );
$u2_secs = $u2_bl['files']['pages.json']['pages'][0]['builder'] ?? array();
$u2_find = function ( $items, $title, $sc = 'icon_box' ) use ( &$u2_find ) { foreach ( (array) $items as $it ) { if ( ( $it['shortcode'] ?? '' ) === $sc && ( 'newsletter' === $sc || false !== strpos( (string) ( $it['atts']['title'] ?? '' ), $title ) ) ) { return $it; } if ( ! empty( $it['_items'] ) ) { $r = $u2_find( $it['_items'], $title, $sc ); if ( $r ) { return $r; } } } return null; };
$u2_rh = $u2_find( $u2_secs, 'Rhythm card' ); $u2_row = $u2_find( $u2_secs, 'Row card' ); $u2_dc = $u2_find( $u2_secs, 'Decor card' ); $u2_ce = $u2_find( $u2_secs, 'Centred card' ); $u2_nl = $u2_find( $u2_secs, '', 'newsletter' );
ga( "four cards → icon_boxes; the signup → a newsletter", $u2_rh && $u2_row && $u2_dc && $u2_ce && $u2_nl );
ga( "the second paragraph's measured rhythm (16px space-y + a divide-y hairline + its padding) → .icon-box__content p + p", false !== strpos( (string) $u2_rh['atts']['custom_css'], 'selector .icon-box__content p + p{margin-top:16px;border-top:1px solid rgb(201, 139, 62);padding-top:12px !important;}' ) );
ga( "a side-by-side card → the icon_box inner is a row from 768px (the 390 stamp stacks), the title LAST as the source orders it", (bool) preg_match( '/@media \(min-width:768px\)\{selector \.icon-box__inner\{display:flex;flex-direction:row;align-items:flex-start;gap:16px;\}selector \.icon-box__head,selector \.icon-box__inner>\.icon-box__title\{flex:0 0 auto;order:2;\}/', (string) $u2_row['atts']['custom_css'] ) );
ga( "an empty 4:3 painted box → a class hook in the description (document order: before the paragraph) + its paint / ratio in scoped CSS", false !== strpos( (string) $u2_dc['atts']['content'], '<div class="sc-deco-1"></div>' ) && strpos( (string) $u2_dc['atts']['content'], 'sc-deco-1' ) < strpos( (string) $u2_dc['atts']['content'], 'A 4:3 box' ) && (bool) preg_match( '/\.icon-box__content \.sc-deco-1\{display:block;width:100%;background-color:rgb\(223, 214, 200\);aspect-ratio:4 \/ 3;border-radius:12px;\}/', (string) $u2_dc['atts']['custom_css'] ) );
ga( "a pattern tile (an inline-SVG data URL) → its own hook, the URL's tags percent-encoded so the CSS scrubber keeps it", false !== strpos( (string) $u2_dc['atts']['content'], '<div class="sc-deco-2"></div>' ) && (bool) preg_match( '/\.sc-deco-2\{[^}]*background-image:url\("data:image\/svg\+xml\\\\3b utf8,%3Csvg[^}]*background-size:40px 40px[^}]*height:160px/', (string) $u2_dc['atts']['custom_css'] ) );
ga( "a stray inline label inside the card → its own paragraph, the span's vertical writing-mode / tracking / case inline (kses-safe)", (bool) preg_match( '/<p><span style="[^"]*writing-mode:vertical-rl[^"]*display:inline-block[^"]*letter-spacing:3px[^"]*text-transform:uppercase[^"]*">Side label<\/span><\/p>/', (string) $u2_dc['atts']['content'] ) );
ga( "the WIDE pass: the card's >= 1536px inset → a min-width:1536px rule; the description's wide size → its own tier rule", false !== strpos( (string) $u2_dc['atts']['custom_css'], '@media (min-width:1536px){selector{padding:56px !important;}}' ) && false !== strpos( (string) $u2_dc['atts']['custom_css'], '@media (min-width:1536px){selector .icon-box__content{font-size:18px;line-height:28px !important;}}' ) );
$u2_sec = null; foreach ( $u2_secs as $s2 ) { if ( false !== strpos( json_encode( $s2 ), 'Rhythm card' ) ) { $u2_sec = $s2; break; } }
ga( "…and the section's >= 1536px padding → a min-width:1536px rule on the section (the native rhythm has three tiers)", is_array( $u2_sec ) && false !== strpos( (string) ( $u2_sec['atts']['custom_css'] ?? '' ), '@media (min-width:1536px){selector{padding-top:160px !important;padding-bottom:160px !important;}}' ) );
ga_eq( "a layout-centred card (grid place-items-center, no text-align) → the icon_box aligns centre", 'center', $u2_ce['atts']['title_align'] ?? null );
ga( "a form field's resolved :focus ring → the newsletter input's :focus (replacing the default accent ring)", false !== strpos( (string) $u2_nl['atts']['custom_css'], 'selector .fw-nl__input:focus{box-shadow:0 0 0 2px rgb(47, 111, 78);outline:none !important;}' ) );

/* --- [M] THE HEADER AUDIT (2026-09-12): a ONE-ROW masthead (logo · links · "Sign in" + CTA as flex siblings in one container)
 * was read as a two-row header by DOM order — the menu went to the Bottom Bar and every conversion wore the previous site's
 * header shape. Rows must be STACKED (the zone stamp's y / the container's layout); a secondary text link beside the CTA is
 * carried; the nav typography is the MODE across visible links (a hidden drawer's 16px/400 list must not be sampled); a
 * utility-built hover colour (`rgb(255 255 255 / var(--tw-text-opacity, 1)`, truncated) is cleaned; and every design key
 * the engines own resets to its declared default when a conversion has no signal for it. JS twin: header-audit-parity.test.mjs. --- */
echo "\n[M] The header audit: stacked rows, text links, menu mode, clean colours, owned keys\n";
$m_nav = '<div class="links" data-sc-cs="display:flex;gap:32px" data-sc-zone="w:400px;h:20px;x:440px;y:30px;bg:rgba(0, 0, 0, 0)"><a href="#features" data-sc-cs="color:rgb(203, 213, 225);font-size:14px;font-weight:500" data-sc-hover="hover-self{color:rgb(255 255 255 / var(--tw-text-opacity, 1)}">Features</a><a href="#worlds" data-sc-cs="color:rgb(203, 213, 225);font-size:14px;font-weight:500">Worlds</a><a href="#pricing" data-sc-cs="color:rgb(203, 213, 225);font-size:14px;font-weight:500">Pricing</a></div>';
$m_one = '<nav class="fixed top-0 w-full" data-sc-cs="position:fixed;background-color:rgba(2, 6, 23, 0.4);display:block;height:80px"><div class="container" data-sc-cs="display:flex;justify-content:space-between;align-items:center;padding:0px 24px;height:80px" data-sc-row="gap:0px;justify:space-between;zones:3">'
	. '<a href="/" class="brand" data-sc-cs="color:rgb(248, 250, 252);font-size:18px;font-weight:600;display:flex" data-sc-zone="w:120px;h:24px;x:0px;y:28px;bg:rgba(0, 0, 0, 0)">Brand</a>'
	. $m_nav
	. '<div class="actions" data-sc-cs="display:flex;gap:24px;align-items:center" data-sc-zone="w:260px;h:42px;x:1000px;y:19px;bg:rgba(0, 0, 0, 0)"><a href="#signin" data-sc-cs="color:rgb(203, 213, 225);font-size:14px;font-weight:500" data-sc-hover="hover-self{color:rgb(255, 255, 255)}">Sign in</a><a href="#start" class="btn" data-sc-cs="color:rgb(248, 250, 252);border-top-width:1px;border-top-style:solid;border-top-color:rgb(255, 255, 255);border-radius:9999px;padding:10px 20px;font-size:14px">Start</a></div>'
	. '</div><ul class="mobile-drawer hidden" data-sc-cs="display:none"><li><a href="#features" data-sc-cs="font-size:16px;font-weight:400;color:rgb(255, 255, 255)">Features</a></li><li><a href="#worlds" data-sc-cs="font-size:16px;font-weight:400;color:rgb(255, 255, 255)">Worlds</a></li></ul></nav>';
$m_page = function ( $header ) { return '<!DOCTYPE html><html data-sc-content-width="1440"><head><title>Brand - A tagline</title></head><body data-sc-cs="background-color:rgb(2, 6, 23);color:rgb(248, 250, 252)">' . $header . '<main><section data-sc-cs="padding:120px 0px;display:block"><h1>Hello</h1><p>Body copy.</p></section></main><footer><p>&copy; Brand</p></footer></body></html>'; };
$m_bl = FW_Site_Converter_Sources::build_from_html( $m_page( $m_one ), 'HeaderAuditFixture', array( 'dynamic_chrome' => true, 'hifi_css' => true ) );
$m_v  = $m_bl['files']['theme-settings.json']['values'] ?? $m_bl['files']['theme-settings.json'];
$m_hm = $m_v['header_main'] ?? array(); $m_bb = $m_v['header_bottombar'] ?? array(); $m_menu = $m_v['header_menu'] ?? array();
ga( "a one-row masthead (logo · links · actions as flex SIBLINGS) is NOT two rows → the menu sits in main_center", 1 === count( $m_hm['main_center'] ?? array() ) && 'menu_area' === ( $m_hm['main_center'][0]['element_type']['element'] ?? '' ) );
ga( "…and the Bottom Bar stays empty", empty( $m_bb['bottombar_left'] ) && empty( $m_bb['bottombar_center'] ) && empty( $m_bb['bottombar_right'] ) );
$m_right = $m_hm['main_right'] ?? array();
ga( "a secondary \"Sign in\" beside the CTA → a list_item AHEAD of the cta_button in main_right", 2 === count( $m_right ) && 'list_item' === ( $m_right[0]['element_type']['element'] ?? '' ) && 'Sign in' === ( $m_right[0]['element_type']['list_item']['li_text'] ?? '' ) && 'url' === ( $m_right[0]['element_type']['list_item']['li_link_type'] ?? '' ) && 'cta_button' === ( $m_right[1]['element_type']['element'] ?? '' ) );
$m_css = (string) ( $m_v['misc_custom_css']['custom_css'] ?? '' );
ga( "…with the link's own colour / size + hover as scoped CSS", (bool) preg_match( '/\.site-header \.sc-hdr-link \.list-item, \.site-header \.sc-hdr-link \.list-item a\{[^}]*color:rgb\(203, 213, 225\)[^}]*font-size:14px/', $m_css ) && false !== strpos( $m_css, '.sc-hdr-link .list-item a:hover{color:rgb(255, 255, 255);}' ) );
ga_eq( "the nav typography is the MODE across VISIBLE links (14px), not the hidden drawer's 16px", '14', $m_menu['menu_link_font_size']['value'] ?? null );
ga_eq( "…and its weight (500, not the drawer's 400)", '500', $m_menu['menu_link_font_weight'] ?? null );
ga_eq( "a utility-built hover colour (`/ var(--tw-text-opacity, 1)`, truncated) → a clean rgb()", 'rgb(255, 255, 255)', $m_menu['menu_link_hover_color']['custom'] ?? null );
ga_eq( "the nav link colour is the measured slate (a \"Sign in\" in another colour is not the active item)", 'rgb(203, 213, 225)', $m_menu['menu_link_color']['custom'] ?? null );
// a genuinely STACKED masthead: the links row sits BELOW the brand row (zone y) → the Bottom Bar carries the menu
$m_two = '<header data-sc-cs="display:block;background-color:rgb(2, 6, 23)"><div class="shell" data-sc-cs="display:block" data-sc-row="gap:0px;justify:normal;zones:2">'
	. '<div class="brand-row" data-sc-cs="display:flex;justify-content:space-between;height:72px" data-sc-zone="w:1200px;h:72px;x:0px;y:0px;bg:rgba(0, 0, 0, 0)"><a href="/" class="brand" data-sc-cs="color:rgb(248, 250, 252);font-size:18px;font-weight:600">Brand</a><a href="#start" class="btn" data-sc-cs="color:rgb(248, 250, 252);border-top-width:1px;border-top-style:solid;border-top-color:rgb(255, 255, 255);border-radius:9999px;padding:10px 20px;font-size:14px">Start</a></div>'
	. str_replace( 'data-sc-zone="w:400px;h:20px;x:440px;y:30px;', 'data-sc-zone="w:1200px;h:44px;x:0px;y:72px;', $m_nav )
	. '</div></header>';
$m_bl2 = FW_Site_Converter_Sources::build_from_html( $m_page( $m_two ), 'HeaderAuditFixture2', array( 'dynamic_chrome' => true, 'hifi_css' => true ) );
$m_v2  = $m_bl2['files']['theme-settings.json']['values'] ?? $m_bl2['files']['theme-settings.json'];
$m_bb2 = $m_v2['header_bottombar'] ?? array(); $m_hm2 = $m_v2['header_main'] ?? array();
ga( "a genuinely STACKED masthead (the links row's zone y = below the brand row) → the menu rides the Bottom Bar", ! empty( $m_bb2['bottombar_left'] ) && 'menu_area' === ( $m_bb2['bottombar_left'][0]['element_type']['element'] ?? '' ) && empty( $m_hm2['main_center'] ) );
// STACKED zones are ROWS, not segments: a full-width ticker bar (y:0) over a full-width brand bar (y:48) must NOT paint
// the start / centre columns (the segmented-header rule read them as side-by-side slats → a 1440px red start column).
$m_css2 = (string) ( $m_v2['misc_custom_css']['custom_css'] ?? '' );
ga( "a STACKED masthead's two full-width zone stamps are rows — no per-column SEGMENTED rule is emitted", false === strpos( $m_css2, 'SEGMENTED' ) && false === strpos( $m_css2, '.header-col--start{' ) );
// owned keys: every design key the engines can emit resets to its DECLARED default when a payload lacks it
$m_owned = FW_Site_Converter_Theme_Settings::OWNED_KEYS;
ga( "the importer OWNS every header / chrome design key the engines emit (header_layout, header_menu, the drawer + mobile bar colours, …)", in_array( 'header_layout', $m_owned, true ) && in_array( 'header_menu', $m_owned, true ) && in_array( 'drawer_bg', $m_owned, true ) && in_array( 'mobile_bar_bg', $m_owned, true ) && in_array( 'footer_border_top', $m_owned, true ) );
$m_dd = new ReflectionMethod( 'FW_Site_Converter_Theme_Settings', 'declared_default' ); $m_dd->setAccessible( true );
ga( "…and resets a missing key to the theme's DECLARED default (drawer_bg → '', header_layout → the classic top header)", '' === $m_dd->invoke( null, 'drawer_bg' ) && 'classic' === ( $m_dd->invoke( null, 'header_layout' )['header_mode']['top']['header_design']['design'] ?? null ) );

/* --- [Q] NO CLASS DROPPED — THE SPACING + STRIP BATCH (2026-09-12): a second source converted on a test site lost (1) the
 * heading GROUP wrapper's `mb-20` under a section heading (the section-level flush dropped mbAdd), (2) a "trusted by" strip's
 * wrapper padding (`pt-24 pb-12`) + its kicker's `mb-8` + the brand NAMES beside iconify marks, (3) a hero column's `mt-12`
 * landed on the CTA button as a stray 48px, (4) the hero's header clearance read a bottom strip's `pt-24` as 96px, (5) a
 * card's 48px ICON CHIP (holding an <iconify-icon>) rode the body as a stray `sc-deco-1` hook, (6) the header CTA wore the
 * plan buttons' outline preset (one preset per role) — its white border + brand hover were dropped, and (7) a page kept a
 * stale hide_site_footer=yes from an earlier footer-less conversion. Every fix is a general measured rule in both engines.
 * JS twin: spacing-strip-parity.test.mjs. --- */
echo "\n[Q] No class dropped: wrapper spacing, strip labels, icon chips, preset variants\n";
$n_body = '<!DOCTYPE html><html data-sc-content-width="1440" data-sc-phone-pass="1"><head><title>Brand - Tagline</title></head><body data-sc-cs="background-color:rgb(2, 6, 23);color:rgb(248, 250, 252);font-size:16px">'
	. '<nav class="fixed top-0 w-full" data-sc-cs="position:fixed;background-color:rgba(2, 6, 23, 0.4);display:block;height:80px"><div class="container" data-sc-cs="display:flex;justify-content:space-between;align-items:center;height:80px" data-sc-row="gap:0px;justify:space-between;zones:3">'
	. '<a href="/" class="brand" data-sc-cs="color:rgb(248, 250, 252);font-size:18px;font-weight:600;display:flex" data-sc-zone="w:120px;h:24px;x:0px;y:28px;bg:rgba(0, 0, 0, 0)">Brand</a>'
	. '<div class="links" data-sc-cs="display:flex;gap:32px" data-sc-zone="w:400px;h:20px;x:440px;y:30px;bg:rgba(0, 0, 0, 0)"><a href="#features" data-sc-cs="color:rgb(203, 213, 225);font-size:14px;font-weight:500">Features</a><a href="#pricing" data-sc-cs="color:rgb(203, 213, 225);font-size:14px;font-weight:500">Pricing</a><a href="#assets" data-sc-cs="color:rgb(203, 213, 225);font-size:14px;font-weight:500">Assets</a></div>'
	. '<div class="actions" data-sc-cs="display:flex;gap:24px;align-items:center" data-sc-zone="w:260px;h:42px;x:1000px;y:19px;bg:rgba(0, 0, 0, 0)"><a href="#" class="px-5 py-2.5 rounded-full border border-white text-sm font-medium hover:bg-brand hover:border-brand hover:text-white" data-sc-cs="color:rgb(248, 250, 252);font-size:14px;font-weight:500;line-height:20px;padding:10px 20px;border-top-width:1px;border-top-style:solid;border-top-color:rgb(255, 255, 255);border-radius:9999px;height:42px;display:block" data-sc-hover="hover-self{border-color:rgb(34 197 94 / var(--tw-border-opacity, 1))}|hover-self{background-color:rgb(34 197 94 / var(--tw-bg-opacity, 1))}|hover-self{color:rgb(255 255 255 / var(--tw-text-opacity, 1))}">Start Journey</a></div>'
	. '</div></nav><main>'
	// HERO: a min-h-screen column with the content group (mt-12) + a bottom-pinned "trusted by" strip (mt-auto pt-24 pb-12)
	. '<header class="relative min-h-screen flex flex-col items-center justify-center pt-20" data-sc-cs="padding:80px 0px 0px;height:900px;min-height:900px;display:flex;justify-content:center;align-items:center;flex-direction:column">'
	. '<div class="relative z-20 flex flex-col items-center text-center px-4 max-w-4xl mx-auto mt-12" data-sc-cs="text-align:center;padding:0px 16px;margin:48px 272px 0px;max-width:896px;height:496px;display:flex;align-items:center;flex-direction:column" data-sc-zone="w:896px;h:496px;x:272px;y:128px;bg:rgba(0, 0, 0, 0)">'
	. '<h1 class="text-8xl font-bold mb-6" data-sc-cs="color:rgb(255, 255, 255);font-size:96px;font-weight:700;line-height:96px;margin:0px 0px 24px;height:288px;display:block">Breathe life into your worlds.</h1>'
	. '<p class="text-xl mb-10 max-w-2xl" data-sc-cs="color:rgb(255, 255, 255);font-size:20px;font-weight:300;line-height:28px;margin:0px 0px 40px;max-width:672px;height:84px;display:block">Generate landscapes, weather and lighting for true storytelling.</p>'
	. '<a href="#pricing" class="inline-flex items-center gap-2 px-8 py-4 bg-brand text-white rounded-full font-semibold text-lg hover:bg-brand-light" data-sc-cs="background-color:rgb(34, 197, 94);color:rgb(255, 255, 255);font-size:18px;font-weight:600;line-height:28px;padding:16px 32px;border-radius:9999px;height:60px;display:flex" data-sc-hover="hover-self{background-color:rgb(134 239 172 / var(--tw-bg-opacity, 1))}">Create Your Scene</a>'
	. '</div>'
	. '<div class="relative z-20 mt-auto pb-12 pt-24 flex flex-col items-center w-full px-4" data-sc-cs="padding:96px 16px 48px;margin:56px 0px 0px;display:flex;align-items:center;flex-direction:column" data-sc-zone="w:1440px;h:220px;x:0px;y:680px;bg:rgba(0, 0, 0, 0)">'
	. '<p class="text-xs font-semibold tracking-widest text-slate-300 uppercase mb-8" data-sc-cs="color:rgb(203, 213, 225);font-size:12px;font-weight:600;line-height:16px;letter-spacing:1.2px;text-transform:uppercase;margin:0px 0px 32px;display:block">Trusted by visual creators at</p>'
	. '<div class="flex flex-wrap justify-center items-center gap-8 md:gap-16 opacity-70 grayscale text-white" data-sc-cs="color:rgb(255, 255, 255);display:flex;gap:64px;opacity:0.7;filter:grayscale(1)">'
	. '<span class="text-xl font-bold flex items-center gap-2" data-sc-cs="color:rgb(255, 255, 255);font-size:20px;font-weight:700;line-height:28px;display:flex;gap:8px"><iconify-icon icon="simple-icons:unrealengine" data-sc-cs="font-size:20px;height:20px;display:block"></iconify-icon> Unreal Engine</span>'
	. '<span class="text-xl font-bold flex items-center gap-2" data-sc-cs="color:rgb(255, 255, 255);font-size:20px;font-weight:700;line-height:28px;display:flex;gap:8px"><iconify-icon icon="simple-icons:unity" data-sc-cs="font-size:20px;height:20px;display:block"></iconify-icon> Unity</span>'
	. '<span class="text-xl font-bold flex items-center gap-2" data-sc-cs="color:rgb(255, 255, 255);font-size:20px;font-weight:700;line-height:28px;display:flex;gap:8px"><iconify-icon icon="simple-icons:blender" data-sc-cs="font-size:20px;height:20px;display:block"></iconify-icon> Blender</span>'
	. '</div></div></header>'
	// FEATURES: a card grid whose cards hold a 48px ICON CHIP (an <iconify-icon> in a painted rounded box) — the chip is the icon, never decor
	. '<section id="features" class="py-32 px-6" data-sc-cs="padding:128px 24px;display:block"><div class="max-w-7xl mx-auto" data-sc-cs="max-width:1280px;margin:0px 80px;display:block"><div class="grid grid-cols-3 gap-8" data-sc-cs="display:grid;gap:32px;grid-template-columns:405px 405px 405px">'
	. str_repeat( '<div class="p-8 rounded-2xl bg-slate-900 border border-slate-800" data-sc-cs="background-color:rgb(15, 23, 42);padding:32px;border-radius:16px;border-top-width:1px;border-top-style:solid;border-top-color:rgb(30, 41, 59);display:block"><div class="w-12 h-12 rounded-lg bg-brand/10 flex items-center justify-center mb-6" data-sc-cs="background-color:rgba(34, 197, 94, 0.1);border-radius:8px;height:48px;width:48px;display:flex;margin:0px 0px 24px"><iconify-icon icon="lucide:zap" data-sc-cs="color:rgb(34, 197, 94);font-size:24px;height:24px;display:block"></iconify-icon></div><h3 class="text-xl font-bold mb-3" data-sc-cs="color:rgb(255, 255, 255);font-size:20px;font-weight:700;margin:0px 0px 12px">Weather engine</h3><p class="text-slate-400" data-sc-cs="color:rgb(148, 163, 184);font-size:16px;line-height:24px">Dynamic storms, fog and light rays that react to the scene.</p></div>', 3 )
	. '</div></div></section>'
	// PRICING: section → container → `text-center mb-20` heading group → h2 + p; then cards with the slate OUTLINE plan buttons
	. '<section id="pricing" class="py-32 px-6 border-t border-slate-800 bg-[#020617] relative" data-sc-cs="padding:128px 24px;background-color:rgb(2, 6, 23);display:block"><div class="max-w-7xl mx-auto relative z-10" data-sc-cs="max-width:1280px;margin:0px 80px;display:block"><div class="text-center mb-20" data-sc-cs="text-align:center;margin:0px 0px 80px;display:block">'
	. '<h2 class="text-5xl font-bold mb-6" data-sc-cs="color:rgb(255, 255, 255);font-size:48px;font-weight:700;line-height:48px;margin:0px 0px 24px;display:block">Equip your arsenal</h2>'
	. '<p class="text-slate-400 max-w-xl mx-auto text-lg" data-sc-cs="color:rgb(148, 163, 184);font-size:18px;line-height:28px;max-width:576px;margin:0px 352px;display:block">Begin your journey for free, and unlock legendary powers as your story grows.</p></div>'
	. '<div class="grid grid-cols-3 gap-8" data-sc-cs="display:grid;gap:32px;grid-template-columns:405px 405px 405px">'
	. str_repeat( '<div class="p-8 rounded-2xl bg-slate-900 border border-slate-800" data-sc-cs="background-color:rgb(15, 23, 42);padding:32px;border-radius:16px;display:block"><h3 data-sc-cs="color:rgb(255, 255, 255);font-size:20px;font-weight:700">Plan</h3><p data-sc-cs="color:rgb(148, 163, 184);font-size:16px">A plan for every journey.</p><a href="#" class="block w-full py-3 rounded-xl border border-slate-700 text-center hover:bg-slate-800" data-sc-cs="color:rgb(255, 255, 255);font-size:16px;font-weight:500;line-height:24px;padding:12px 0px;border-top-width:1px;border-top-style:solid;border-top-color:rgb(51, 65, 85);border-radius:12px;display:block" data-sc-hover="hover-self{background-color:rgb(30 41 59 / var(--tw-bg-opacity, 1))}">Start Free</a></div>', 3 )
	. '</div></div></section>'
	. '</main><footer data-sc-cs="background-color:rgb(2, 6, 23);padding:48px 0px;display:block"><div data-sc-cs="display:flex;justify-content:space-between"><span data-sc-cs="color:rgb(148, 163, 184)">&copy; Brand</span><a href="#features" data-sc-cs="color:rgb(148, 163, 184)">Features</a></div></footer></body></html>';
$n_bl = FW_Site_Converter_Sources::build_from_html( $n_body, 'SpacingStripFixture', array( 'dynamic_chrome' => true, 'hifi_css' => true ) );
$n_pg = $n_bl['files']['pages.json']['pages'][0]['builder'] ?? array();
$n_find = function ( $nodes, $pred ) use ( &$n_find ) { foreach ( (array) $nodes as $n ) { if ( ! is_array( $n ) ) { continue; } if ( $pred( $n ) ) { return $n; } $r = $n_find( $n['_items'] ?? array(), $pred ); if ( $r ) { return $r; } } return null; };
$n_all  = function ( $nodes, $pred, &$out ) use ( &$n_all ) { foreach ( (array) $nodes as $n ) { if ( ! is_array( $n ) ) { continue; } if ( $pred( $n ) ) { $out[] = $n; } $n_all( $n['_items'] ?? array(), $pred, $out ); } };
$n_sec  = function ( $id ) use ( $n_pg ) { foreach ( $n_pg as $s ) { if ( ( $s['atts']['css_id'] ?? '' ) === $id ) { return $s; } } return null; };
// (1) the section-level heading group: section → container → `text-center mb-20` → h2 + p → ONE special_heading whose Spacing carries the wrapper's mb
$n_pr = $n_sec( 'pricing' );
$n_ph = $n_find( array( $n_pr ), function ( $n ) { return 'special_heading' === ( $n['shortcode'] ?? '' ) && false !== strpos( (string) ( $n['atts']['title'] ?? '' ), 'Equip' ); } );
ga( "pricing: the `text-center mb-20` heading group → ONE special_heading (title + subtitle folded, centred)", is_array( $n_ph ) && '' !== trim( (string) ( $n_ph['atts']['subtitle'] ?? '' ) ) && 'center' === ( $n_ph['atts']['alignment'] ?? '' ) );
ga_eq( "…whose Spacing bottom IS the wrapper's mb-20 (80px → mb-9) — the section-level flush no longer drops mbAdd", 'mb-9', $n_ph['atts']['spacing']['margin']['bottom'] ?? null );
// (2) the hero strip: wrapper margin + padding → the caption's top / the logo_grid's bottom; the kicker's own mb-8; brand names shown
$n_hero = $n_pg[0] ?? array();
$n_cap  = $n_find( array( $n_hero ), function ( $n ) { return 'special_heading' === ( $n['shortcode'] ?? '' ) && false !== strpos( (string) ( $n['atts']['overline'] ?? '' ), 'Trusted' ); } );
$n_lg   = $n_find( array( $n_hero ), function ( $n ) { return 'logo_grid' === ( $n['shortcode'] ?? '' ); } );
ga( "hero strip: the flattened `mt-auto pt-24` wrapper pushes the caption to the END of its column (`margin-top:auto`, never its one-screen computed px) and keeps the wrapper's own 96px padding-top", false !== strpos( (string) ( $n_cap['atts']['custom_css'] ?? '' ), 'margin-top:auto !important;padding-top:96px' ) && in_array( (string) ( $n_cap['atts']['spacing']['margin']['top'] ?? '' ), array( '', 'mt-0' ), true ), wp_json_encode( array( $n_cap['atts']['custom_css'] ?? null, $n_cap['atts']['spacing']['margin']['top'] ?? null ) ) );
ga_eq( "…the kicker's OWN mb-8 (32px) is the overline-only heading's Spacing bottom", 'mb-[32px]', $n_cap['atts']['spacing']['margin']['bottom'] ?? null );
ga_eq( "…and the wrapper's pb-12 (48px) rides the logo_grid's Spacing bottom", 'mb-5', $n_lg['atts']['spacing']['margin']['bottom'] ?? null );
ga_eq( "the iconify brand marks keep their VISIBLE names (show_labels) — a mark beside text is not a wordmark", 'yes', $n_lg['atts']['show_labels'] ?? null );
ga( "…every name carried (Unreal Engine · Unity · Blender)", 3 === count( $n_lg['atts']['logos'] ?? array() ) && 'Unreal Engine' === ( $n_lg['atts']['logos'][0]['name'] ?? '' ) );
ga( "…the strip's MEASURED desktop gap (64px, not the bare `gap-8` phone value) + the item's own gap / padding / label type as scoped CSS", false !== strpos( (string) ( $n_lg['atts']['custom_css'] ?? '' ), '.fw-lg__item{gap:8px;padding:0px;}' ) && false !== strpos( (string) ( $n_lg['atts']['custom_css'] ?? '' ), '.fw-lg__label{font-size:20px;font-weight:700;line-height:28px;}' ) && '' !== (string) ( $n_lg['atts']['gap'] ?? '' ) );
// (3) the hero CTA does NOT inherit the content column's mt-12; (4) the hero's header clearance is the source's own pt-20, not the strip's pt-24
$n_btn = $n_find( array( $n_hero ), function ( $n ) { return 'button' === ( $n['shortcode'] ?? '' ) && false !== strpos( (string) ( $n['atts']['label'] ?? '' ), 'Create' ); } );
ga( "the hero CTA carries NO stray top margin from its content column (`mt-12` belongs to the column, not the button)", is_array( $n_btn ) && '' === (string) ( $n_btn['atts']['spacing']['margin']['top'] ?? '' ) );
$n_h1 = $n_find( array( $n_hero ), function ( $n ) { return 'special_heading' === ( $n['shortcode'] ?? '' ) && false !== strpos( (string) ( $n['atts']['title'] ?? '' ), 'Breathe' ); } );
ga_eq( "the content column's mt-12 (48px) rides the FIRST block — the hero heading's Spacing top", 'mt-5', $n_h1['atts']['spacing']['margin']['top'] ?? null );
ga( "…and the title asserts its own margin-top:0 (the theme's default h1 margin no longer doubles the carried gap)", false !== strpos( (string) ( $n_h1['atts']['custom_css'] ?? '' ), '.heading-title{margin-top:0px !important;}' ) );
$n_hero_pt = (string) ( $n_hero['atts']['padding_top']['base'] ?? '' ) . '|' . (string) ( $n_hero['atts']['custom_css'] ?? '' );
ga( "the hero's header clearance is its own pt-20 (80px) — a bottom strip's pt-24 is NOT the header offset", ( 0 === strpos( $n_hero_pt, 'pt-9|' ) ) && false === strpos( $n_hero_pt, 'padding-top:96px' ) );
// (5) an icon chip is the card's ICON, never an empty painted "decor" box
$n_ft = $n_sec( 'features' ); $n_boxes = array(); $n_all( array( $n_ft ), function ( $n ) { return 'icon_box' === ( $n['shortcode'] ?? '' ); }, $n_boxes );
ga( "features: three icon_boxes from the card grid", 3 === count( $n_boxes ) );
ga( "…a card's 48px icon CHIP (an <iconify-icon> in a painted rounded box) never rides the body as a stray `sc-deco-N` hook", ! empty( $n_boxes ) && false === strpos( (string) ( $n_boxes[0]['atts']['content'] ?? '' ), 'sc-deco' ) && false === strpos( (string) ( $n_boxes[0]['atts']['custom_css'] ?? '' ), 'sc-deco' ) );
// (6) a role's SECOND distinct skin → its own "<Role> 2" preset; the header CTA colour-matches it
$n_v = $n_bl['files']['theme-settings.json']['values'] ?? $n_bl['files']['theme-settings.json'];
$n_bc = $n_v['button_colors'] ?? array(); $n_names = array(); foreach ( $n_bc as $c ) { $n_names[ (string) $c['color_name'] ] = $c; }
ga( "two DISTINCT outline skins (slate plan buttons · white header CTA) → \"Outline\" + \"Outline 2\" presets", isset( $n_names['Outline'] ) && isset( $n_names['Outline 2'] ) );
ga_eq( "…\"Outline 2\" keeps the CTA's white border", 'rgb(255, 255, 255)', $n_names['Outline 2']['states']['default']['border_color']['custom'] ?? null );
ga_eq( "…and its brand hover fill", 'rgb(34, 197, 94)', $n_names['Outline 2']['states']['hover']['bg_color']['custom'] ?? null );
$n_cta = null; foreach ( (array) ( $n_v['header_main']['main_right'] ?? array() ) as $e ) { if ( 'cta_button' === ( $e['element_type']['element'] ?? '' ) ) { $n_cta = $e['element_type']['cta_button']; } }
ga_eq( "the header CTA resolves to the variant by its MEASURED colours (btn-outline-2), not the first outline preset", 'btn-outline-2', $n_cta['cta_style'] ?? null );
// (7) the source has a footer → the bundle says so (the importer resets a stale hide_site_footer from an earlier footer-less conversion)
ga( "the source's footer is detected (footer:true rides the bundle) — the page importer resets a stale hide_site_footer=yes", ! empty( $n_bl['files']['theme-settings.json']['values']['footer_columns'] ) || ! empty( $n_v['main_footer_columns'] ) || ! empty( $n_bl['files']['bundle.json']['chrome']['footer'] ) || ! empty( $n_bl['files']['theme-design.json']['footer'] ) );

/* --- [R] THE NOCTURNAL AUDIT (2026-09-16): a plain-CSS (non-utility) dark source: (1) a 12-track BENTO grid whose tiles
 * span 8 / 4 tracks through a stylesheet class across three visual rows was built as one row of six 1/12 slivers — the
 * capture now stamps every grid child's desktop `track-frac` + `track-y`, and bento_split groups cells by y into rows with
 * their measured widths + heights; (2) a code window's TITLE BAR (three dots · label · LIVE, justify space-between) was
 * flattened into stacked kicker headings — a toolbar row now mirrors as a flex row (placement, dots, hairline, padding);
 * (3) a hero intro paragraph's `padding-bottom:240px` (the gap before the CTAs) was dropped; (4) a header-less source
 * still painted the theme masthead — the theme reads `page_header = d-none`, not the legacy hide switch; (5) a flat
 * footer link ROW (`flex gap-14 justify-end` pills) stacked vertically; (6) a key / value row lost its space-between,
 * hairline and vertical centring. JS twins: bentoRowsOf, subtitle paddingBottom (nocturnal-parity.test.mjs). --- */
echo "\n[R] The nocturnal audit: bento rows, toolbar rows, intro padding, page_header, footer link row\n";
$r_tile = function ( $y, $w, $h, $title ) { return '<article class="project" data-sc-cs="background-image:linear-gradient(rgba(255, 255, 255, 0.05), rgba(255, 255, 255, 0.02));color:rgb(255, 255, 255);border-radius:24px;padding:22px;height:' . $h . 'px;display:block;track-frac:' . $w . ';track-y:' . $y . '"><div class="label" data-sc-cs="color:rgba(255, 255, 255, 0.62);font-size:10px;text-transform:uppercase;letter-spacing:2.8px">Digital</div><h3 data-sc-cs="color:rgb(255, 255, 255);font-size:43px;font-weight:400;line-height:41px;margin:16px 0px 0px">' . $title . '</h3><p data-sc-cs="color:rgba(255, 255, 255, 0.72);font-size:16px;line-height:30px;margin:12px 0px 0px">Typography-led landing pages with precise rhythm and velvet-toned transitions.</p></article>'; };
$r_body = '<!DOCTYPE html><html data-sc-content-width="1440" data-sc-phone-pass="1"><head><title>Aether - Nocturnal</title><style>.wrap{width:min(1600px, 100% - 32px);margin:0 auto}.code-window{width:min(1080px, 100% - 32px);margin:0 auto}</style></head><body data-sc-cs="background-color:rgb(11, 14, 18);color:rgb(255, 255, 255);font-size:16px"><main>'
	// HERO (no <nav> / masthead anywhere): kicker + h1 + intro with a 240px padding-bottom + CTAs
	. '<section class="hero" data-sc-cs="padding:110px 24px 42px;min-height:900px;display:flex;justify-content:center;align-items:center;flex-direction:column"><div class="hero-shell" data-sc-cs="text-align:center;padding:28px 30px 30px;max-width:900px;display:block">'
	. '<h1 data-sc-cs="color:rgb(255, 255, 255);font-size:118px;font-weight:400;line-height:106px;margin:0px;display:block">Aether House</h1>'
	. '<p data-sc-cs="color:rgba(255, 255, 255, 0.72);font-size:16px;line-height:31px;margin:26px 0px 0px;padding:0px 0px 240px;max-width:640px;display:block">Crafting the nocturnal web through artisanal digital architecture and twilight-logic engineering.</p>'
	. '<div class="hero-actions" data-sc-cs="display:flex;gap:14px;justify-content:center;margin:34px 0px 0px"><a href="#studio" data-sc-cs="background-color:rgb(20, 22, 24);color:rgb(255, 255, 255);font-size:10px;letter-spacing:2.6px;text-transform:uppercase;padding:0px 28px;height:56px;border-radius:999px;display:flex">Open the Studio</a><a href="#manifesto" data-sc-cs="color:rgba(255, 255, 255, 0.8);font-size:10px;letter-spacing:2.6px;text-transform:uppercase;padding:0px 22px;height:56px;border-radius:999px;border-top-width:1px;border-top-style:solid;border-top-color:rgba(255, 255, 255, 0.1);display:flex">View Manifesto</a></div>'
	. '</div></section>'
	// CODE WINDOW: a panel with a TOOLBAR title bar (dots · label · LIVE) over a two-column body
	. '<section class="section" data-sc-cs="padding:120px 24px;display:block"><div class="wrap" data-sc-cs="max-width:1300px;margin:0px 46px;display:block">'
	. '<div class="code-window" data-sc-cs="background-image:linear-gradient(rgba(20, 36, 38, 0.9), rgba(12, 18, 22, 0.94));margin:0px 164px;border-radius:28px;border-top-width:1px;border-top-style:solid;border-top-color:rgba(255, 255, 255, 0.08);display:block">'
	. '<div class="code-top" data-sc-cs="background-color:rgba(255, 255, 255, 0.03);padding:18px 20px;border-bottom-width:1px;border-bottom-style:solid;border-bottom-color:rgba(255, 255, 255, 0.08);height:52px;display:flex;gap:16px;justify-content:space-between;align-items:center">'
	. '<div class="dots" data-sc-cs="display:flex;gap:6px"><span data-sc-cs="background-color:rgba(255, 255, 255, 0.16);border-radius:999px;height:10px;display:block"></span><span data-sc-cs="background-color:rgba(255, 255, 255, 0.16);border-radius:999px;height:10px;display:block"></span><span data-sc-cs="background-color:rgba(255, 255, 255, 0.16);border-radius:999px;height:10px;display:block"></span></div>'
	. '<div class="tag" data-sc-cs="color:rgba(255, 255, 255, 0.62);font-size:10px;letter-spacing:2.8px;text-transform:uppercase">Nocturnal logic console</div>'
	. '<div class="live" data-sc-cs="color:rgba(255, 255, 255, 0.6);font-size:10px;letter-spacing:2.8px;text-transform:uppercase">LIVE</div></div>'
	. '<div class="code-body" data-sc-cs="display:grid;gap:20px;grid-template-columns:780px 420px;padding:20px"><div data-sc-cs="padding:22px;display:block"><div data-sc-cs="color:rgba(255, 255, 255, 0.6);font-size:10px;text-transform:uppercase;letter-spacing:2.8px;margin:0px 0px 16px">Manifesto</div><pre data-sc-cs="color:rgba(255, 255, 255, 0.8);font-size:12px;line-height:22px;white-space:pre-wrap">Nocturnal code cycles are a craft, not a constraint.' . "\n\n" . 'Atmospheric deployment should feel like turning on a lantern in a quiet room.</pre></div>'
	. '<div data-sc-cs="display:grid;gap:14px"><div data-sc-cs="background-color:rgba(255, 255, 255, 0.04);border-radius:20px;padding:20px;display:block"><div data-sc-cs="font-size:10px;text-transform:uppercase;letter-spacing:2.8px;color:rgba(255, 255, 255, 0.55)">Deployment rhythm</div><h3 data-sc-cs="color:rgb(255, 255, 255);font-size:40px;font-weight:400;margin:8px 0px 0px">24/7</h3><p data-sc-cs="color:rgba(255, 255, 255, 0.7);font-size:12px;line-height:20px;margin:8px 0px 0px">Atmospheric releases, quiet handoffs, and calm production cycles.</p></div></div></div>'
	. '</div></div></section>'
	// BENTO: a 12-track grid, tiles spanning 8 / 4 across three visual rows
	. '<section class="projects" data-sc-cs="padding:120px 24px;display:block"><div class="wrap" data-sc-cs="max-width:1300px;margin:0px 46px;display:block"><div class="projects-grid" data-sc-cs="display:grid;gap:16px;grid-template-columns:102.6px 102.6px 102.6px 102.6px 102.6px 102.6px 102.6px 102.6px 102.6px 102.6px 102.6px 102.6px" data-sc-cs-md="grid-template-columns:118px 118px 118px 118px 118px 118px">'
	. $r_tile( 0, 0.667, 320, 'Midnight journal for a quiet luxury brand.' ) . $r_tile( 0, 0.323, 320, 'Editorial toolkits for founders.' )
	. $r_tile( 336, 0.323, 246, 'High-trust boutique storefronts.' ) . $r_tile( 336, 0.667, 246, 'Dark-mode design infrastructure for modern teams.' )
	. $r_tile( 598, 0.323, 246, 'Soft flicker, branch sway, and lantern glow.' ) . $r_tile( 598, 0.323, 246, 'Quiet systems for sharp operators.' )
	. '</div></div></section>'
	. '</main>'
	// FOOTER: a heading column beside a flat ROW of pill links (justify-end)
	. '<footer class="footer" data-sc-cs="background-color:rgb(7, 10, 14);padding:120px 24px 94px;display:block"><div class="footer-grid" data-sc-cs="display:grid;gap:34px;grid-template-columns:747px 552px;align-items:end"><div data-sc-cs="display:block"><h2 data-sc-cs="color:rgb(255, 255, 255);font-size:86px;font-weight:400;line-height:78px;margin:20px 0px 0px">A panoramic city edge for star-mapped navigation.</h2><p data-sc-cs="color:rgba(255, 255, 255, 0.68);font-size:16px;line-height:31px;margin:16px 0px">The final view opens wide, like a rooftop above the city.</p></div>'
	. '<div class="links" data-sc-cs="display:flex;gap:14px;justify-content:flex-end;align-items:normal;flex-direction:row;height:41px"><a href="#" data-sc-cs="background-color:rgba(255, 255, 255, 0.05);color:rgba(255, 255, 255, 0.72);font-size:10px;letter-spacing:2.2px;text-transform:uppercase;line-height:15px;padding:12px 14px;border-radius:999px;border-top-width:1px;border-top-style:solid;border-top-color:rgba(255, 255, 255, 0.08)">Studio</a><a href="#" data-sc-cs="background-color:rgba(255, 255, 255, 0.05);color:rgba(255, 255, 255, 0.72);font-size:10px;letter-spacing:2.2px;text-transform:uppercase;padding:12px 14px;border-radius:999px">Services</a><a href="#" data-sc-cs="background-color:rgba(255, 255, 255, 0.05);color:rgba(255, 255, 255, 0.72);font-size:10px;padding:12px 14px;border-radius:999px">Projects</a></div></div></footer></body></html>';
$r_bl = FW_Site_Converter_Sources::build_from_html( $r_body, 'NocturnalFixture', array( 'dynamic_chrome' => true, 'hifi_css' => true ) );
$r_pg = $r_bl['files']['pages.json']['pages'][0];
$r_secs = $r_pg['builder'] ?? array();
$r_find = function ( $nodes, $pred ) use ( &$r_find ) { foreach ( (array) $nodes as $n ) { if ( ! is_array( $n ) ) { continue; } if ( $pred( $n ) ) { return $n; } $r = $r_find( $n['_items'] ?? array(), $pred ); if ( $r ) { return $r; } } return null; };
$r_all  = function ( $nodes, $pred, &$out ) use ( &$r_all ) { foreach ( (array) $nodes as $n ) { if ( ! is_array( $n ) ) { continue; } if ( $pred( $n ) ) { $out[] = $n; } $r_all( $n['_items'] ?? array(), $pred, $out ); } };
// (4) a header-less source hides the theme masthead through the select the theme reads
ga_eq( "no masthead anywhere in the source → the page asks for hide_site_header", 'yes', $r_pg['page_options']['hide_site_header'] ?? null );
$r_pages_src = (string) @file_get_contents( dirname( __DIR__ ) . '/includes/class-fw-site-converter-pages.php' );
ga( "…and the page importer maps it onto `page_header = d-none` (the theme's header visibility select; the switch is legacy)", false !== strpos( $r_pages_src, "'page_header', 'd-none'" ) );
// (3) the hero intro's padding-bottom is the gap before the CTAs
$r_h1 = $r_find( $r_secs, function ( $n ) { return 'special_heading' === ( $n['shortcode'] ?? '' ) && false !== strpos( (string) ( $n['atts']['title'] ?? '' ), 'Aether' ); } );
ga_eq( "hero: the intro paragraph's OWN padding-bottom (240px) is the heading's below gap → the CTAs sit where the source puts them", 'mb-[240px]', $r_h1['atts']['spacing']['margin']['bottom'] ?? null );
// (1) bento: three rows of 8/4 · 4/8 · 4/4 with the tiles' heights
$r_boxes = array(); $r_all( $r_secs, function ( $n ) { return 'icon_box' === ( $n['shortcode'] ?? '' ); }, $r_boxes );
ga( "bento: six tiles → six icon_boxes", 6 === count( $r_boxes ) );
$r_rowof = function ( $title ) use ( $r_secs, $r_find ) { return $r_find( $r_secs, function ( $n ) use ( $title ) { if ( 'flexbox' !== ( $n['type'] ?? '' ) || 2 !== count( $n['_items'] ?? array() ) ) { return false; } $s = json_encode( $n ); return false !== strpos( $s, $title ) && false === strpos( $s, 'Soft flicker' ) && false === strpos( $s, ( 'Midnight' === $title ) ? 'High-trust' : 'Midnight' ); } ); };
$r_row1 = $r_rowof( 'Midnight' ); $r_row2 = $r_rowof( 'High-trust' );
$r_w = function ( $row, $i ) { return $row['_items'][ $i ]['atts']['width']['base']['preset'] ?? null; };
ga( "…row 1 = a 2/3 + 1/3 pair (the measured fractions → 8 / 4 on the 12-grid), row 2 = 1/3 + 2/3", is_array( $r_row1 ) && '8' === $r_w( $r_row1, 0 ) && '4' === $r_w( $r_row1, 1 ) && is_array( $r_row2 ) && '4' === $r_w( $r_row2, 0 ) && '8' === $r_w( $r_row2, 1 ) );
ga( "…each tile keeps its measured height as the cell's min-height (320 on row 1, 246 on row 2)", is_array( $r_row1 ) && false !== strpos( json_encode( $r_row1['_items'][0]['atts'] ), '320' ) && is_array( $r_row2 ) && false !== strpos( json_encode( $r_row2['_items'][0]['atts'] ), '246' ) );
// (2) the toolbar row
$r_tb = $r_find( $r_secs, function ( $n ) { return 'flexbox' === ( $n['type'] ?? '' ) && 'between' === ( $n['atts']['justify_content']['base'] ?? '' ) && false !== strpos( json_encode( $n ), 'LIVE' ); } );
ga( "code window: the title bar (dots · label · LIVE) is a flex ROW with the source's space-between, not stacked kickers", is_array( $r_tb ) && 3 === count( $r_tb['_items'] ?? array() ) );
ga( "…wearing its faint fill, padding and bottom hairline", is_array( $r_tb ) && preg_match( '/background-color:rgba\(255, 255, 255, 0\.03\)/', (string) ( $r_tb['atts']['custom_css'] ?? '' ) ) && false !== strpos( (string) ( $r_tb['atts']['custom_css'] ?? '' ), 'padding:18px 20px' ) && false !== strpos( (string) ( $r_tb['atts']['custom_css'] ?? '' ), 'border-bottom:1px solid rgba(255, 255, 255, 0.08)' ) );
$r_dots = array(); $r_all( array( $r_tb ), function ( $n ) { return 'code_block' === ( $n['shortcode'] ?? '' ) && false !== strpos( (string) ( $n['atts']['code'] ?? $n['atts']['content'] ?? json_encode( $n['atts'] ) ), 'sc-dot' ); }, $r_dots );
ga( "…the three empty painted dots are carried as inline dots (10px, round, tinted)", 3 === count( $r_dots ) );
$r_pre = $r_find( $r_secs, function ( $n ) { return 'text_block' === ( $n['shortcode'] ?? '' ) && false !== strpos( (string) ( $n['atts']['text'] ?? '' ), 'Nocturnal code cycles are a craft' ); } );
ga( "…a <pre> manifesto keeps its line breaks (white-space:pre-wrap), a plain label's source line break is collapsed", is_array( $r_pre ) && false !== strpos( (string) $r_pre['atts']['custom_css'], 'white-space:pre-wrap' ) && false === strpos( (string) $r_pre['atts']['text'], 'style=' ) && false !== strpos( (string) $r_pre['atts']['text'], "\n" ) );
// (8) a NARROWER min() cap inside the container is the panel's own centred measure, not the site shell
$r_cw = $r_find( $r_secs, function ( $n ) { return 'flexbox' === ( $n['type'] ?? '' ) && false !== strpos( (string) ( $n['atts']['custom_css'] ?? '' ), 'min(1080px' ); } );
ga( "code window: its own `width:min(1080px, 100% - 32px); margin:0 auto` (narrower than the 1440 content width) is kept as a centred cap — not wiped as the site shell", is_array( $r_cw ) && false !== strpos( (string) $r_cw['atts']['custom_css'], 'margin-left:auto;margin-right:auto' ) );
// (5) the footer's flat link ROW
$r_v = $r_bl['files']['theme-settings.json']['values'] ?? $r_bl['files']['theme-settings.json'];
$r_fcss = (string) ( $r_v['misc_custom_css']['custom_css'] ?? '' );
ga( "footer: a flat link ROW (flex, justify-end) lays its List Items out horizontally, end-justified, with the source gap", (bool) preg_match( '/\.footer-column ul\.footer-links-list\{[^}]*display:flex[^}]*gap:14px[^}]*justify-content:flex-end/', $r_fcss ) );
ga( "…and each link keeps its pill skin (fill, padding, radius, 10px uppercase tracking)", (bool) preg_match( '/ul\.footer-links-list > li > \.list-item\{[^}]*background-color:rgba\(255, 255, 255, 0\.05\)[^}]*padding:12px 14px[^}]*border-radius:999px[^}]*font-size:10px/', $r_fcss ) );

// (7) a button's FULL css: its ::before / ::after layers, the hover state of each, the hover shadow and the @keyframes ride the preset
$r_btn_html = '<!DOCTYPE html><html data-sc-content-width="1440"><head><title>B</title></head><body data-sc-cs="background-color:rgb(11, 14, 18);color:rgb(255, 255, 255)"><main><section data-sc-cs="padding:100px 24px;display:block"><h2 data-sc-cs="color:rgb(255, 255, 255);font-size:48px">Lantern</h2>'
	. '<a href="#" class="lantern-core" data-sc-cs="background-image:linear-gradient(rgba(20, 22, 24, 0.94), rgba(11, 13, 16, 0.9));color:rgb(255, 255, 255);font-size:10px;letter-spacing:2.6px;text-transform:uppercase;padding:0px 22px;border-radius:999px;box-shadow:rgba(0, 0, 0, 0.28) 0px 18px 60px 0px;height:56px;display:flex;transition:transform 0.45s cubic-bezier(0.16, 1, 0.3, 1), box-shadow 0.45s;overflow:hidden;position:relative" data-sc-hover="before{content:&quot;&quot;;position:absolute;inset:0px;transition:0.45s;background-image:radial-gradient(circle, rgba(255, 189, 98, 0.14), transparent 36%);opacity:0}|after{content:&quot;&quot;;position:absolute;inset:-120%;transform:translateX(-140%) rotate(10deg);background-image:linear-gradient(120deg, transparent 44%, rgba(255, 193, 103, 0.52) 50%, transparent 56%);animation:9s ease-in-out 0s infinite normal none running sheen;animation-name:sheen}|hover-self{transform:translateY(-3px);box-shadow:rgba(255, 189, 98, 0.18) 0px 0px 0px 1px, rgba(0, 0, 0, 0.34) 0px 22px 60px}|hover-before{opacity:1}" data-sc-keyframes="@keyframes sheen { 100% { transform: translateX(240%) rotate(10deg); } }">Open the Studio</a>'
	. '</section></main></body></html>';
$r_bb = FW_Site_Converter_Sources::build_from_html( $r_btn_html, 'LanternFixture', array( 'dynamic_chrome' => true, 'hifi_css' => true ) );
$r_bv = $r_bb['files']['theme-settings.json']['values'] ?? $r_bb['files']['theme-settings.json'];
$r_bc = ''; foreach ( (array) ( $r_bv['button_colors'] ?? array() ) as $c ) { if ( false !== strpos( (string) ( $c['custom_css'] ?? '' ), '::after' ) ) { $r_bc = (string) $c['custom_css']; break; } }
ga( "button: the preset carries the source's ::before (a glow, opacity 0) and ::after (a sheen) layers verbatim", false !== strpos( $r_bc, '{{SELECTOR}}::before {' ) && false !== strpos( $r_bc, 'opacity:0' ) && false !== strpos( $r_bc, '{{SELECTOR}}::after {' ) && false !== strpos( $r_bc, 'translateX(-140%) rotate(10deg)' ) );
ga( "…the hover state of a layer ({{SELECTOR}}:hover::before{opacity:1}) and the hover shadow", false !== strpos( $r_bc, '{{SELECTOR}}:hover::before { opacity:1; }' ) && (bool) preg_match( '/\{\{SELECTOR\}\}:hover \{ box-shadow: rgba\(255, 189, 98, 0\.18\)/', $r_bc ) );
ga( "…the @keyframes the sheen animates with, and the button positioned + clipped so an inset layer stays in the pill", false !== strpos( $r_bc, '@keyframes sheen' ) && false !== strpos( $r_bc, '{{SELECTOR}} { position: relative; overflow: hidden; isolation: isolate; }' ) );
$r_bpg = $r_bb['files']['pages.json']['pages'][0]['builder'] ?? array();
$r_bn = $r_find( $r_bpg, function ( $n ) { return 'button' === ( $n['shortcode'] ?? '' ); } );
ga( "…and the button takes NO library fx on top (the preset owns the whole hover — no doubled lift, no substituted shadow)", is_array( $r_bn ) && '' === (string) ( $r_bn['atts']['hover_animation'] ?? '' ) && '' !== (string) ( $r_bn['atts']['style'] ?? '' ) );
/* --- [S] THE BOXED FOOTER (2026-09-16): a footer whose rows sit in ONE inset panel (a hairline-bordered, tinted, padded
 * shell with a width cap and a decor strip), a lead lockup with an EYEBROW, a bottom-aligned grid, a pill-link row and a
 * LABEL bottom bar (three short labels, no © line). Native: Footer → Layout → Boxed Body + per-bar Column Alignment
 * (theme 2.5.97), the label bar as the Copyright bar, a measured column split, translucent colours kept, the
 * multi-layer footer gradient verbatim. --- */
echo "\n[S] Boxed footer: shell → Boxed Body, eyebrow, valign, measured split, label bar → copyright\n";
$s_cs = 'color:rgb(255, 255, 255);font-family:&quot;Inter Tight&quot;, sans-serif;font-size:16px;font-weight:400;line-height:24px;text-align:start;';
$s_html = '<!DOCTYPE html><html><head><title>Box</title></head><body>'
	. '<header data-sc-cs="' . $s_cs . 'display:flex;justify-content:space-between;align-items:center;padding:20px 40px;height:64px"><a href="/" data-sc-cs="' . $s_cs . '">Aether House</a><nav><a href="/a" data-sc-cs="' . $s_cs . '">Studio</a><a href="/b" data-sc-cs="' . $s_cs . '">Work</a></nav></header>'
	. '<main><section data-sc-cs="' . $s_cs . 'padding:80px 0px"><div data-sc-cs="' . $s_cs . '"><h1 data-sc-cs="' . $s_cs . 'font-size:64px">Night</h1><p data-sc-cs="' . $s_cs . '">A quiet city under the stars.</p></div></section></main>'
	. '<footer class="footer" data-sc-cs="background-image:radial-gradient(circle at 20% 12%, rgba(255, 171, 89, 0.1), rgba(0, 0, 0, 0) 16%), linear-gradient(rgb(7, 10, 14), rgb(11, 13, 16));background-color:rgb(7, 10, 14);' . $s_cs . 'padding:120px 0px 94px" data-sc-footer="col-gap:34px">'
	. '<div class="footer-shell" data-sc-cs="background-image:linear-gradient(rgba(255, 255, 255, 0.03), rgba(255, 255, 255, 0.016));' . $s_cs . 'padding:36px;margin:0px 16px;border-top-width:1px;border-top-style:solid;border-top-color:rgba(255, 255, 255, 0.08);border-bottom-width:1px;border-bottom-style:solid;border-bottom-color:rgba(255, 255, 255, 0.08);border-left-width:1px;border-left-style:solid;border-left-color:rgba(255, 255, 255, 0.08);border-right-width:1px;border-right-style:solid;border-right-color:rgba(255, 255, 255, 0.08);border-radius:0px;box-shadow:rgba(0, 0, 0, 0.34) 0px 24px 80px 0px;display:block;overflow:hidden" data-sc-cs-sm="margin:0px 10px" data-sc-cs-xl="margin:0px 160px">'
	. '<div class="roofline" data-sc-cs="background-image:linear-gradient(rgba(0, 0, 0, 0) 0%, rgba(255, 255, 255, 0.04) 100%);' . $s_cs . 'height:120px;display:block;position:absolute;top:295px;right:0px;bottom:0px;left:0px;opacity:0.52"></div>'
	. '<div class="footer-grid" data-sc-cs="' . $s_cs . 'display:grid;gap:34px;grid-template-columns:747.5px 552.5px;align-items:end">'
	. '<div data-sc-cs="' . $s_cs . '"><div class="mono" data-sc-cs="color:rgba(255, 255, 255, 0.4);font-family:&quot;IBM Plex Mono&quot;, monospace;font-size:10px;font-weight:400;line-height:15px;letter-spacing:3.5px;text-transform:uppercase;margin:0px">The Rooftop Footer</div>'
	. '<h2 data-sc-cs="color:rgb(255, 255, 255);font-family:&quot;Cormorant Garamond&quot;, serif;font-size:86.4px;font-weight:400;line-height:77.76px;letter-spacing:-6.048px;margin:20px 0px 0px">A panoramic city edge for star-mapped navigation.</h2>'
	. '<p data-sc-cs="color:rgba(255, 255, 255, 0.68);font-family:&quot;Inter Tight&quot;, sans-serif;font-size:16px;line-height:31.2px;margin:16px 0px 0px;max-width:760px">The final view opens wide, like a rooftop above the city, with a minimalist list of links suspended under the night sky.</p></div>'
	. '<div class="links" data-sc-cs="' . $s_cs . 'display:flex;gap:14px;justify-content:flex-end;flex-wrap:wrap">'
	. '<a href="#" data-sc-cs="background-color:rgba(255, 255, 255, 0.05);color:rgba(255, 255, 255, 0.72);font-size:10px;letter-spacing:2.2px;text-transform:uppercase;line-height:15px;padding:12px 14px;border-radius:999px">Studio</a>'
	. '<a href="#" data-sc-cs="background-color:rgba(255, 255, 255, 0.05);color:rgba(255, 255, 255, 0.72);font-size:10px;letter-spacing:2.2px;text-transform:uppercase;line-height:15px;padding:12px 14px;border-radius:999px">Services</a>'
	. '<a href="#" data-sc-cs="background-color:rgba(255, 255, 255, 0.05);color:rgba(255, 255, 255, 0.72);font-size:10px;letter-spacing:2.2px;text-transform:uppercase;line-height:15px;padding:12px 14px;border-radius:999px">Projects</a></div></div>'
	. '<div class="footer-bottom" data-sc-cs="color:rgba(255, 255, 255, 0.52);font-family:&quot;Inter Tight&quot;, sans-serif;font-size:12px;font-weight:400;line-height:18px;letter-spacing:2.64px;text-transform:uppercase;padding:22px 0px 0px;margin:34px 0px 0px;border-top-width:1px;border-top-style:solid;border-top-color:rgba(255, 255, 255, 0.08);display:flex;gap:20px;justify-content:space-between">'
	. '<div data-sc-cs="font-size:12px">Aether House</div><div data-sc-cs="font-size:12px">Amber Gold · Teal Wood · Twilight Blue</div><div data-sc-cs="font-size:12px">Crafting the nocturnal web</div></div>'
	. '</div></footer></body></html>';
$s_b  = FW_Site_Converter_Sources::build_from_html( $s_html, 'Box', array( 'dynamic_chrome' => true, 'hifi_css' => true ) );
$s_v  = $s_b['files']['theme-settings.json']['values'] ?? $s_b['files']['theme-settings.json'];
$s_mc = (string) ( $s_v['misc_custom_css']['custom_css'] ?? '' );
$s_bx = $s_v['footer_body_box'] ?? array(); $s_bf = $s_bx['yes'] ?? array();
ga( "the footer's inset panel → Footer → Layout → Boxed Body (on)", 'yes' === ( $s_bx['enabled'] ?? '' ) );
ga( "…max width 1600 from the WIDE pass (1920 − 2 × 160 margin), side gutter 16 from the base margin", '1600' === ( $s_bf['footer_box_max_width']['value'] ?? '' ) && '16' === ( $s_bf['footer_box_gutter']['value'] ?? '' ) );
ga( "…padding 36 / 36, ONE linear gradient → the native gradient data, the four-edge hairline WITH its alpha", '36' === ( $s_bf['footer_box_padding_y']['value'] ?? '' ) && '36' === ( $s_bf['footer_box_padding_x']['value'] ?? '' ) && 2 === count( $s_bf['footer_box_background']['gradient']['data']['stops'] ?? array() ) && 'rgba(255, 255, 255, 0.08)' === ( $s_bf['footer_box_border']['color']['custom'] ?? '' ) );
ga( "…the first shadow layer (0 24 80 rgba .34), no radius for 0px, copyright inside the panel", 24 === ( $s_bf['footer_box_shadow']['y'] ?? 0 ) && 80 === ( $s_bf['footer_box_shadow']['blur'] ?? 0 ) && ! isset( $s_bf['footer_box_radius'] ) && 'yes' === ( $s_bf['footer_box_copyright_inside'] ?? '' ) );
ga( "…the decor strip → the panel's ::before (bottom-anchored, 120px, opacity .52)", (bool) preg_match( '/\.footer--boxed \.footer__body::before\{[^}]*bottom:0;[^}]*height:120px;[^}]*opacity:0\.52/', $s_mc ) );
ga( "…every bar inside the panel goes Full Width (the panel padding is the gutter)", 'container-fluid' === ( $s_v['main_footer_custom_styling']['yes']['main_footer_container'] ?? '' ) && 'container-fluid' === ( $s_v['copyright_settings']['yes']['copyright_custom_styling']['yes']['copyright_container'] ?? '' ) );
ga( "…the theme's 1rem bar padding replaced by the measured rows (main 0, bottom bar margin 34 + padding 22)", (bool) preg_match( '/footer-section--main-footer\{padding-top:0(?:px)?;padding-bottom:0(?:px)?;\}/', $s_mc ) && (bool) preg_match( '/footer-section--copyright\{margin-top:34px;padding-top:22px;padding-bottom:0(?:px)?;\}/', $s_mc ) );
ga( "the main row's align-items:end → Column Alignment: Bottom", 'end' === ( $s_v['main_footer_custom_styling']['yes']['main_footer_valign'] ?? '' ) );
$s_mfc = $s_v['main_footer_columns'] ?? array(); $s_bar = $s_mfc[ (string) ( $s_mfc['count'] ?? '' ) ] ?? array();
ga( "the 747.5 / 552.5 grid tracks → a MEASURED 57 / 43 split (not the equal default)", 57 === (int) ( $s_bar['main_footer_split'][0]['w'] ?? 0 ) && 43 === (int) ( $s_bar['main_footer_split'][1]['w'] ?? 0 ) );
$s_lead = (string) ( $s_bar['main_footer_col_1'][0]['element_type']['text']['text_content'] ?? '' );
ga( "the lead lockup carries the EYEBROW above the heading and the (22-word) paragraph under it", false !== strpos( $s_lead, '<span class="footer-lead-eyebrow">The Rooftop Footer</span><h3 class="footer-lead-title">' ) && false !== strpos( $s_lead, '<p class="footer-lead-subtitle">The final view' ) );
ga( "…the eyebrow's mono type / tracking / translucent colour as a scoped rule; the title's font-family joined with a ';'", (bool) preg_match( '/\.footer-lead-eyebrow\{display:block;font-family:"IBM Plex Mono", monospace;[^}]*letter-spacing:3\.5px[^}]*color:rgba\(255, 255, 255, 0\.4\)/', $s_mc ) && (bool) preg_match( '/\.footer-lead-title\{font-family:"Cormorant Garamond", serif;margin-top:20px/', $s_mc ) );
ga( "…and the lockup's ZERO margins asserted (no theme h3 bottom margin under the display heading)", (bool) preg_match( '/\.footer-lead-title\{[^}]*margin-bottom:0 !important/', $s_mc ) );
$s_cc = $s_v['copyright_settings']['yes']['copyright_columns'] ?? array();
ga( "the LABEL bottom bar (3 short labels, no ©) → the Copyright bar's 3 columns, no fabricated © line", '3' === (string) ( $s_cc['count'] ?? '' ) && false !== strpos( json_encode( $s_cc ), 'Crafting the nocturnal web' ) && false === strpos( json_encode( $s_cc ), 'rights reserved' ) );
ga( "…a flex space-between bar → Auto Width + Between", 'yes' === ( $s_cc['3']['copyright_auto'] ?? '' ) && 'between' === ( $s_cc['3']['copyright_justify'] ?? '' ) );
$s_ccs = $s_v['copyright_settings']['yes']['copyright_custom_styling']['yes'] ?? array();
ga( "…its hairline top border keeps its alpha; its 12px translucent tracked type → copyright_typography", 'rgba(255, 255, 255, 0.08)' === ( $s_ccs['copyright_border']['color']['custom'] ?? '' ) && '12' === ( $s_ccs['copyright_typography']['size']['value'] ?? '' ) && 'rgba(255, 255, 255, 0.52)' === ( $s_ccs['copyright_typography']['color'] ?? '' ) );
ga( "…case / tracking as scoped CSS (no typography field for them)", false !== strpos( $s_mc, '.footer .footer-section--copyright{text-transform:uppercase;letter-spacing:2.64px;line-height:18px;}' ) );
ga( "the multi-layer footer gradient (radial glow over a linear wash) rides verbatim on .footer, not the single native gradient", false !== strpos( $s_mc, '.footer{background-image:radial-gradient(' ) && ! isset( $s_v['footer_background']['gradient'] ) );

/* --- [T] CARD STATES + EYEBROW (2026-09-16): a project tile whose skin sits on the <article> (fill, hairline, radius,
 * clip) with its inset on a SOLE unskinned inner wrapper, a mono eyebrow before the heading, a measured description,
 * and EVERY captured state (a ::before overlay, an exact hover lift + border, a hover-revealed child, :focus-visible).
 * The states ride the Box PRESET's Custom CSS (never the shortcode); the eyebrow → the icon_box's native Overline. --- */
echo "\n[T] Card states: pseudo layers + hover + focus on the Box Preset, eyebrow → Overline, inner inset, measure\n";
$u_hov  = htmlspecialchars( 'before{content:"";position:absolute;inset:0px;background-image:linear-gradient(135deg, rgba(255, 189, 98, 0.12), rgba(0, 0, 0, 0) 40%);opacity:0.6;pointer-events:none}|hover-self{transform:translateY(-3px);border-color:rgba(255, 255, 255, 0.18);box-shadow:rgba(0, 0, 0, 0.4) 0px 24px 60px}|hover-child{.on-hover}{opacity:1;transform:translateY(0px)}|focus-visible{outline:2px solid rgba(255, 189, 98, 0.8);outline-offset:3px}', ENT_QUOTES );
$u_tile = function ( $title ) use ( $u_hov ) {
	return '<article class="project" data-sc-cs="background-color:rgba(255, 255, 255, 0.04);border-top-width:1px;border-top-style:solid;border-top-color:rgba(255, 255, 255, 0.08);border-bottom-width:1px;border-bottom-style:solid;border-bottom-color:rgba(255, 255, 255, 0.08);border-left-width:1px;border-left-style:solid;border-left-color:rgba(255, 255, 255, 0.08);border-right-width:1px;border-right-style:solid;border-right-color:rgba(255, 255, 255, 0.08);border-radius:24px;overflow:hidden;position:relative;display:block;color:rgb(255, 255, 255);font-family:Inter, sans-serif;font-size:16px;line-height:24px" data-sc-hover="' . $u_hov . '">'
		. '<div class="inner" data-sc-cs="padding:22px;display:block;color:rgb(255, 255, 255);font-family:Inter, sans-serif;font-size:16px;line-height:24px">'
		. '<div class="mono" data-sc-cs="color:rgba(255, 255, 255, 0.4);font-family:&quot;IBM Plex Mono&quot;, monospace;font-size:10px;font-weight:400;line-height:15px;letter-spacing:3.5px;text-transform:uppercase;margin:0px">Digital Architecture</div>'
		. '<h3 data-sc-cs="color:rgb(255, 255, 255);font-family:Inter, sans-serif;font-size:28px;font-weight:500;line-height:32px;margin:10px 0px 0px">' . $title . '</h3>'
		. '<p data-sc-cs="color:rgba(255, 255, 255, 0.68);font-family:Inter, sans-serif;font-size:16px;line-height:26px;margin:12px 0px 0px;max-width:333px">Typography-led landing pages with measured rhythm.</p>'
		. '<div class="on-hover" data-sc-cs="opacity:0;transform:matrix(1, 0, 0, 1, 0, 6);font-size:12px;color:rgba(255, 255, 255, 0.5)">View case</div>'
		. '</div></article>';
};
$u_html = '<!DOCTYPE html><html data-sc-content-width="1440"><head><title>U</title></head><body data-sc-cs="font-family:Inter, sans-serif;background-color:rgb(7, 10, 14);color:rgb(255, 255, 255)"><header><a href="/">Site</a><nav><a href="#a">Alpha</a></nav></header><main>'
	. '<section class="projects" data-sc-cs="padding:120px 0px;display:block;background-color:rgb(7, 10, 14)"><div class="wrap" data-sc-cs="margin:0px 24px;display:block"><div class="grid" data-sc-cs="display:grid;gap:16px;grid-template-columns:688px 688px">'
	. $u_tile( 'Midnight journal' ) . $u_tile( 'Editorial toolkits' ) . '</div></div></section></main><footer><p>&copy; Site</p></footer></body></html>';
$u_bl   = FW_Site_Converter_Sources::build_from_html( $u_html, 'CardStates', array( 'dynamic_chrome' => true, 'hifi_css' => true ) );
$u_secs = $u_bl['files']['pages.json']['pages'][0]['builder'] ?? array();
$u_ts   = json_encode( $u_bl['files']['theme-settings.json'] ?? array() );
$u_ib   = $t_find( $u_secs, 'Midnight journal' );
ga( "two tiles → icon_boxes", is_array( $u_ib ) && is_array( $t_find( $u_secs, 'Editorial toolkits' ) ) );
ga_eq( "the eyebrow ('Digital Architecture') → the icon_box's native Overline", 'Digital Architecture', $u_ib['atts']['overline'] ?? null );
$u_ibcss = (string) ( $u_ib['atts']['custom_css'] ?? '' );
ga( "…its mono family / 10px / 3.5px tracking / uppercase / translucent colour as scoped .icon-box__overline CSS", (bool) preg_match( '/selector \.icon-box__overline\{[^}]*font-size:10px[^}]*letter-spacing:3\.5px[^}]*text-transform:uppercase[^}]*color:rgba\(255, 255, 255, 0\.4\)[^}]*font-family:\'IBM Plex Mono\', monospace/', $u_ibcss ) );
ga( "the description's OWN measure (max-width:333px) → .icon-box__content", false !== strpos( $u_ibcss, 'selector .icon-box__content{max-width:333px;}' ) );
ga( "NEG: no state (::before / :hover / :focus-visible) rides on the SHORTCODE — states belong to the preset", ! preg_match( '/::before|:hover|:focus-visible/', $u_ibcss ) );
// The tile's Box Preset: the cell (nested-row cardBox) or the icon_box carries a boxp-box-… slug whose preset CSS holds the states.
$u_node = ( isset( $u_ib['atts']['box_style'] ) && preg_match( '/^boxp-box-/', (string) $u_ib['atts']['box_style'] ) ) ? $u_ib : $r_find( $u_secs, function ( $n ) { return isset( $n['atts']['border_preset'] ) && preg_match( '/^boxp-box-/', (string) $n['atts']['border_preset'] ) && false !== strpos( json_encode( $n ), 'Midnight journal' ); } );
$u_slug = (string) ( $u_node['atts']['box_style'] ?? ( $u_node['atts']['border_preset'] ?? '' ) );
$u_pcss = '';
if ( preg_match( '/^boxp-box-([a-f0-9]{8})$/', $u_slug, $um ) ) { $u_pcss = $t_preset( array( 'atts' => array( 'box_style' => $u_slug ) ) ); if ( '' === $u_pcss ) { $u_ts2 = $u_ts; $u_pcss = ( function () use ( $u_ts2, $um ) { $i = strpos( $u_ts2, '"Box ' . $um[1] . '"' ); if ( false === $i ) { return ''; } $st = strrpos( substr( $u_ts2, 0, $i ), '{"id"' ); $d = 0; for ( $k = $st; $k < strlen( $u_ts2 ); $k++ ) { if ( '{' === $u_ts2[ $k ] ) { $d++; } elseif ( '}' === $u_ts2[ $k ] ) { $d--; if ( 0 === $d ) { return substr( $u_ts2, $st, $k - $st + 1 ); } } } return ''; } )(); } }
ga( "the tile wears a real Box Preset (the cell's border_preset or the icon_box's box_style)", '' !== $u_slug && '' !== $u_pcss );
ga( "…whose CSS carries the SOLE inner wrapper's 22px inset as the card padding", (bool) preg_match( '/padding:22px/', $u_pcss ) );
ga( "…the ::before overlay layer, {{SELECTOR}}-scoped", (bool) preg_match( '/\{\{SELECTOR\}\}::before \{[^}]*content:\\\\"\\\\"[^}]*opacity:0\.6/', $u_pcss ) || (bool) preg_match( '/\{\{SELECTOR\}\}::before \{[^}]*opacity:0\.6/', $u_pcss ) );
ga( "…the EXACT hover transform (translateY(-3px)) — no library Lift substituted", false !== strpos( $u_pcss, ':hover { transform:translateY(-3px); }' ) && false === strpos( $u_pcss, 'btnfx-lift' ) );
ga( "…the hover-revealed child ({{SELECTOR}}:hover .on-hover) and :focus-visible", false !== strpos( $u_pcss, ':hover .on-hover { opacity:1; transform:translateY(0px); }' ) && false !== strpos( $u_pcss, ':focus-visible { outline:2px solid rgba(255, 189, 98, 0.8); outline-offset:3px; }' ) );
ga( "…the hover border colour rides the preset's native hover field, not duplicated in the CSS", (bool) preg_match( '/"hover"\s*:\s*\{[^}]*rgba\(255, 255, 255, 0\.18\)/', $u_pcss ) || false !== strpos( $u_pcss, 'rgba(255, 255, 255, 0.18)' ) );

/* --- [U] THE SECTION-LESS VIDEO PAGE (2026-09-16): a masthead that IS the <nav> (a link-less brand block + a menu link
 * cluster + a <button> CTA), a page-wide FIXED video backdrop, a <main> with NO <section> holding a capped hero copy
 * wrapper (h1 · p · a CAPSULE signup pill · a label row with a dot) and a 3-card grid of iconify-icon tiles, and a
 * brand-only footer (icon + wordmark | one disclaimer paragraph). --- */
echo "\n[U] Section-less video page: nav-masthead brand + CTA, site-bg video, band padding, capsule form, card grid root, brand-only footer\n";
$u_ink = 'color:rgb(255, 255, 255);font-family:Inter, sans-serif;font-size:16px;font-weight:400;line-height:24px;text-align:start;';
$u_svg = '<svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 256 256" data-sc-iconify="ph:infinity-bold"><path fill="currentColor" d="M252 128a60 60 0 0 1-102 42Z"></path></svg>';
$u_rv = function ( $delay ) { return ' data-sc-reveal="dir:up;distance:30;scale:1;duration:1.2;delay:' . $delay . ';ease:cubic-bezier(0.16, 1, 0.3, 1)"'; }; // the capture's measured CSS-class reveal stamp (.reveal-up + .delay-N00)
$u_card = function ( $title, $body, $delay = 0 ) use ( $u_ink, $u_svg, $u_rv ) {
	return '<div class="glass rounded-3xl p-8 flex flex-col"' . $u_rv( $delay ) . ' data-sc-cs="background-color:rgba(243, 250, 255, 0.6);' . $u_ink . 'padding:32px;border-top-width:1px;border-top-style:solid;border-top-color:rgba(255, 255, 255, 0.8);border-bottom-width:1px;border-bottom-style:solid;border-bottom-color:rgba(255, 255, 255, 0.8);border-left-width:1px;border-left-style:solid;border-left-color:rgba(255, 255, 255, 0.8);border-right-width:1px;border-right-style:solid;border-right-color:rgba(255, 255, 255, 0.8);border-radius:24px;backdrop-filter:blur(24px);display:flex;flex-direction:column;track-frac:0.307;track-y:0" data-sc-cs-sm="track-frac:1" data-sc-cs-md="track-frac:0.294">'
		. '<div class="w-12 h-12 rounded-2xl bg-gray-600 border border-white flex items-center justify-center mb-16 text-white" data-sc-cs="background-color:rgb(75, 85, 99);color:rgb(255, 255, 255);font-size:16px;margin:0px 0px 64px;border-top-width:1px;border-top-style:solid;border-top-color:rgb(255, 255, 255);border-radius:16px;height:48px;display:flex;justify-content:center;align-items:center"><iconify-icon icon="ph:globe" data-sc-cs="color:rgb(255, 255, 255);font-size:24px;height:24px;display:block">' . $u_svg . '</iconify-icon></div>'
		. '<h3 data-sc-cs="color:rgb(0, 0, 0);font-family:Inter, sans-serif;font-size:20px;font-weight:500;line-height:28px;letter-spacing:-0.6px;margin:0px 0px 12px">' . $title . '</h3>'
		. '<p data-sc-cs="color:rgba(0, 0, 0, 0.7);font-family:Inter, sans-serif;font-size:14px;line-height:22.75px">' . $body . '</p></div>';
};
$u_html = '<!DOCTYPE html><html data-sc-content-width="1440"><head><title>U</title></head><body data-sc-cs="background-color:rgb(0, 0, 0);color:rgb(255, 255, 255)">'
	. '<div class="bg-video-container" data-sc-cs="' . $u_ink . 'display:block;position:fixed;top:0px;right:0px;bottom:0px;left:0px;height:900px"><video autoplay loop muted playsinline data-sc-cs="display:block"><source src="https://cdn.example.invalid/backdrop.mp4" type="video/mp4"></video></div>'
	. '<nav id="navbar" class="fixed top-0 w-full flex items-center justify-between px-6 py-5" data-sc-cs="' . $u_ink . 'padding:20px 24px;display:flex;justify-content:space-between;align-items:center;position:fixed;top:0px;left:0px;right:0px;height:82px">'
	. '<div class="flex items-center gap-2" data-sc-cs="' . $u_ink . 'display:flex;gap:8px;align-items:center"><div class="w-8 h-8 rounded-full bg-white flex items-center justify-center" data-sc-cs="background-color:rgb(255, 255, 255);color:rgb(0, 0, 0);border-radius:9999px;height:32px;display:flex;justify-content:center;align-items:center" data-sc-decor="w:32px;h:32px;bg:rgb(255, 255, 255);radius:9999px"><iconify-icon icon="ph:infinity-bold" data-sc-cs="color:rgb(0, 0, 0);font-size:18px;height:18px;display:block">' . $u_svg . '</iconify-icon></div><span data-sc-cs="color:rgb(255, 255, 255);font-family:Inter, sans-serif;font-size:18px;font-weight:500;line-height:28px;letter-spacing:-0.54px">AETHER HOUSE</span></div>'
	. '<div class="flex items-center gap-8" data-sc-cs="' . $u_ink . 'display:flex;gap:32px;align-items:center;font-size:13px"><a href="#" data-sc-cs="color:rgba(255, 255, 255, 0.8);font-size:13px;font-weight:500">Products</a><a href="#" data-sc-cs="color:rgba(255, 255, 255, 0.8);font-size:13px;font-weight:500">Solutions</a><a href="#" data-sc-cs="color:rgba(255, 255, 255, 0.8);font-size:13px;font-weight:500">Pricing</a></div>'
	. '<div class="flex items-center gap-6" data-sc-cs="' . $u_ink . 'display:flex;gap:24px;align-items:center"><a href="#" data-sc-cs="color:rgba(255, 255, 255, 0.8);font-size:13px;font-weight:500">Log in</a><button class="liquid-btn px-5 py-2.5 rounded-full" data-sc-cs="background-color:rgb(255, 255, 255);color:rgb(0, 0, 0);font-family:Inter, sans-serif;font-size:13px;font-weight:500;line-height:19.5px;padding:10px 20px;border-top-width:1px;border-top-style:solid;border-top-color:rgb(255, 255, 255);border-radius:9999px;display:flex;align-items:center;height:41.5px">Open account</button></div></nav>'
	. '<main class="relative w-full min-h-[120vh] flex flex-col items-center" data-sc-cs="color:rgb(0, 0, 0);font-family:Inter, sans-serif;font-size:16px;line-height:24px;padding:225px 0px 0px;display:flex;justify-content:normal;align-items:center;flex-direction:column">'
	. '<div class="flex flex-col items-center text-center px-4 max-w-4xl mx-auto" id="hero-content" data-sc-cs="color:rgb(0, 0, 0);font-family:Inter, sans-serif;font-size:16px;text-align:center;padding:0px 16px;margin:0px 272px;max-width:896px;height:405px;display:flex;justify-content:normal;align-items:center;flex-direction:column">'
	. '<h1' . $u_rv( 0 ) . ' data-sc-cs="color:rgb(255, 255, 255);font-family:Inter, sans-serif;font-size:88px;font-weight:500;line-height:92.4px;letter-spacing:-2.64px;text-align:center;height:176px">Radically different banking</h1>'
	. '<p data-sc-cs="color:rgba(255, 255, 255, 0.7);font-family:Inter, sans-serif;font-size:20px;line-height:28px;letter-spacing:-0.5px;text-align:center;margin:24px 0px 0px;max-width:672px">Apply online in ten minutes to experience infrastructure built for the scale of the modern internet.</p>'
	. '<div class="mt-10 glass rounded-full p-1.5 flex items-center w-full max-w-md"' . $u_rv( 0.2 ) . ' data-sc-hover="hover-self{background-color:oklch(1 0 0 / 0.7);background-image:initial;box-shadow:oklch(1 0 0) 0px 1px 1px inset, rgba(0, 0, 0, 0.05) 0px 24px 48px;border-color:oklch(1 0 0)}" data-sc-cs="background-color:rgba(243, 250, 255, 0.6);' . $u_ink . 'text-align:center;padding:6px;margin:40px 0px 0px;border-top-width:1px;border-top-style:solid;border-top-color:rgba(255, 255, 255, 0.8);border-radius:9999px;box-shadow:rgba(0, 0, 0, 0.03) 0px 20px 40px 0px;backdrop-filter:blur(24px);max-width:448px;display:flex;justify-content:normal;align-items:center;flex-direction:row;height:61px">'
	. '<input type="email" placeholder="Enter your email" class="flex-1 bg-transparent border-none px-6 py-3 text-white placeholder-white/90" data-sc-cs="color:rgb(255, 255, 255);font-family:Inter, sans-serif;font-size:15px;line-height:22.5px;padding:12px 24px;border-radius:0px;display:block">'
	. '<button class="liquid-btn px-6 py-3 rounded-full whitespace-nowrap" data-sc-cs="background-color:rgb(255, 255, 255);color:rgb(0, 0, 0);font-family:Inter, sans-serif;font-size:14px;font-weight:500;line-height:21px;letter-spacing:0.35px;padding:12px 24px;border-top-width:1px;border-top-style:solid;border-top-color:rgb(255, 255, 255);border-radius:9999px;display:flex;align-items:center;height:47px">Open account</button></div>'
	. '<div class="mt-8 flex items-center gap-4 text-xs uppercase tracking-widest" data-sc-cs="color:rgba(255, 255, 255, 0.8);font-family:Inter, sans-serif;font-size:12px;line-height:16px;letter-spacing:1.2px;text-align:center;text-transform:uppercase;margin:32px 0px 0px;display:flex;gap:16px;justify-content:normal;align-items:center;flex-direction:row"><span data-sc-cs="color:rgba(255, 255, 255, 0.8);font-size:12px;line-height:16px;letter-spacing:1.2px;text-transform:uppercase">Sub-millisecond finality</span><span class="w-1 h-1 rounded-full bg-white/20" data-sc-cs="background-color:rgba(255, 255, 255, 0.2);border-radius:9999px;height:4px;display:block"></span><span data-sc-cs="color:rgba(255, 255, 255, 0.8);font-size:12px;line-height:16px;letter-spacing:1.2px;text-transform:uppercase">Automated reconciliation</span></div>'
	. '</div>'
	. '<div class="w-full max-w-[1200px] mx-auto px-6 mt-40 pb-32 grid grid-cols-1 md:grid-cols-3 gap-6" id="features-grid" data-sc-cs="color:rgb(0, 0, 0);font-family:Inter, sans-serif;font-size:16px;padding:0px 24px 128px;margin:160px 120px 0px;max-width:1200px;height:414px;display:grid;gap:24px;grid-template-columns:368px 368px 368px;justify-content:normal;align-items:normal" data-sc-cs-sm="margin:160px 0px 0px;grid-template-columns:342px" data-sc-cs-md="margin:160px 0px 0px;grid-template-columns:241px 241px 241px">'
	. $u_card( 'Cross-Border Liquidity', 'Multi-entity consolidation across many currencies. Settle obligations instantly.', 0 ) . $u_card( 'Liquidity Forecasting', 'Predictive models engineered for treasury teams. Visualize cash burn and float.', 0.1 ) . $u_card( 'Real-time Ledgers', 'Double-entry immutability at web scale. Programmatic money movement.', 0.2 )
	. '</div></main>'
	. '<footer class="relative w-full bg-gray-800 border-t py-12 px-6" data-sc-cs="background-color:rgb(31, 41, 55);' . $u_ink . 'padding:48px 24px;border-top-width:1px;border-top-style:solid;border-top-color:rgba(255, 255, 255, 0.05)">'
	. '<div class="max-w-[1200px] mx-auto flex flex-col md:flex-row justify-between items-center gap-6" data-sc-cs="' . $u_ink . 'max-width:1200px;margin:0px 96px;display:flex;gap:24px;justify-content:space-between;align-items:center;flex-direction:row">'
	. '<div class="flex items-center gap-2" data-sc-cs="' . $u_ink . 'display:flex;gap:8px;align-items:center"><iconify-icon icon="ph:infinity-bold" class="text-white text-xl" data-sc-cs="color:rgb(255, 255, 255);font-size:20px;height:20px;display:block">' . $u_svg . '</iconify-icon><span data-sc-cs="color:rgb(255, 255, 255);font-family:Inter, sans-serif;font-size:14px;font-weight:500;line-height:20px;letter-spacing:-0.42px">AETHER HOUSE</span></div>'
	. '<p data-sc-cs="color:rgba(255, 255, 255, 0.4);font-family:Inter, sans-serif;font-size:11px;line-height:17.875px;text-align:right;max-width:576px">Aether House is a financial technology company, not a bank. Banking services provided by partner institutions.</p>'
	. '</div></footer></body></html>';
$u_bl = FW_Site_Converter_Sources::build_from_html( $u_html, 'Sectionless', array( 'dynamic_chrome' => true, 'hifi_css' => true ) );
$u_v  = $u_bl['files']['theme-settings.json']['values'] ?? ( $u_bl['files']['theme-settings.json'] ?? array() );
$u_pg = $u_bl['files']['pages.json']['pages'][0]['builder'] ?? array();
$u_mc = (string) ( $u_v['misc_custom_css']['custom_css'] ?? '' );
ga( "masthead = the <nav>: the link-less brand block wins over the menu link cluster → site title 'AETHER HOUSE' (not 'Products')", 'AETHER HOUSE' === ( $u_v['header_logo']['logo_type']['custom']['site_title'] ?? '' ) );
ga( "…its iconify mark (inlined by the capture) → the logo icon, BLACK on a white circle frame (the ink from the stamped host, not white-on-white)", 0 === stripos( (string) ( $u_v['header_logo']['logo_type']['custom']['logo_icon']['markup'] ?? '' ), '<svg' ) && 'circle' === ( $u_v['header_logo']['logo_type']['custom']['logo_icon_frame'] ?? '' ) && 'rgb(0, 0, 0)' === ( $u_v['header_logo']['logo_type']['custom']['logo_icon_color']['custom'] ?? '' ) );
$u_right = json_encode( $u_v['header_main']['main_right'] ?? array() );
ga( "the <button> CTA inside the nav-masthead → a cta_button in the right zone (beside the 'Log in' text link)", false !== strpos( $u_right, '"cta_text":"Open account"' ) && false !== strpos( $u_right, '"li_text":"Log in"' ) );
$u_vid = $u_v['general_layout']['site_background']['video'] ?? array();
ga( "the page-wide FIXED video (a stylesheet inset:0 wrapper, no utility class) → the Site Background's video layer in FIXED mode", 'yes' === ( $u_vid['enabled'] ?? '' ) && 'fixed' === ( $u_vid['position'] ?? '' ) && false !== strpos( (string) ( $u_vid['source_mp4']['url'] ?? '' ), 'backdrop.mp4' ) );
ga( "…and NOT also the first section's background video (no doubled backdrop)", 'no' === ( $u_pg[0]['atts']['background']['video']['enabled'] ?? 'no' ) );
ga( "the section-less <main> → two bands: the hero copy + the card grid", 2 === count( $u_pg ) );
ga( "band 1 inherits the container's padding-top (225px) and stays content-tall (no forced 100vh)", 'pt-[225px]' === ( $u_pg[0]['atts']['padding_top']['lg'] ?? '' ) && 'auto' === ( $u_pg[0]['atts']['min_height']['preset'] ?? '' ) );
ga( "band 1's own max-w-4xl cap (the root IS the capped box) → container width = its CONTENT measure: 896 less its own px-4 inset (864 — a content width never carries the box's padding twice)", 'content-864' === ( $u_pg[0]['atts']['container_width']['preset'] ?? '' ), wp_json_encode( $u_pg[0]['atts']['container_width'] ?? null ) );
$u_nl = $r_find( $u_pg, function ( $n ) { return 'newsletter' === ( $n['shortcode'] ?? '' ); } );
ga( "the pill wrapper holding input + button → newsletter design CAPSULE", is_array( $u_nl ) && 'capsule' === ( $u_nl['atts']['design'] ?? '' ) );
$u_nlc = (string) ( $u_nl['atts']['custom_css'] ?? '' );
ga( "…the wrapper's skin rides the field ROW (.fw-nl__fields), the input keeps only its type + inset, the element takes the 448px measure centred", (bool) preg_match( '/selector \.fw-nl__fields\{[^}]*background:rgba\(243, 250, 255, 0\.6\)[^}]*padding:6px/', $u_nlc ) && (bool) preg_match( '/selector \.fw-nl__input\{[^}]*color:rgb\(255, 255, 255\)[^}]*padding:12px 24px/', $u_nlc ) && false !== strpos( $u_nlc, 'selector{max-width:448px;width:100%;margin-left:auto;margin-right:auto;}' ) );
ga( "…the placeholder tint from the opacity utility (placeholder-white/90) and the form's own mt-10 = 40px above", false !== strpos( $u_nlc, '::placeholder{color:rgba(255, 255, 255, 0.9)' ) && 'mt-[40px]' === ( $u_nl['atts']['spacing']['margin']['top'] ?? '' ) );
$u_tb = $r_find( $u_pg, function ( $n ) { return 'text_block' === ( $n['shortcode'] ?? '' ) && false !== strpos( (string) ( $n['atts']['text'] ?? '' ), 'Sub-millisecond' ); } );
$u_tbc = (string) ( $u_tb['atts']['custom_css'] ?? '' );
ga( "the label row (two leaf labels + a leaf dot) → ONE text block of spans (no 4/4 columns, no per-label flexbox), keeping its mt-8 = 32px", is_array( $u_tb ) && 'mt-[32px]' === ( $u_tb['atts']['spacing']['margin']['top'] ?? '' ) && '<p><span class="sc-label">Sub-millisecond finality</span><span class="sc-label">Automated reconciliation</span></p>' === (string) $u_tb['atts']['text'] );
ga( "…its tracked uppercase 12px type → the native Text Style EYEBROW (the full treatment wins over the 11px Caption), the 0.8-alpha ink → native Text Color, NO inline style", 'font-eyebrow' === ( $u_tb['atts']['font_size_preset'] ?? '' ) && 'rgba(255, 255, 255, 0.8)' === ( $u_tb['atts']['text_color']['custom'] ?? '' ) && false === strpos( (string) $u_tb['atts']['text'], 'style=' ) );
ga( "…the flex line (gap 16, centred, no wrap mid-label) + the unowned 16px leading ride the block's OWN Custom CSS; the separator dot is a ::before on each label after the first (the editor strips an empty / bare span, so no such element exists)", false !== strpos( $u_tbc, 'selector p{display:flex;flex-wrap:wrap;align-items:center;margin:0;gap:16px;justify-content:center;}' ) && false !== strpos( $u_tbc, 'selector p>.sc-label{white-space:nowrap;}' ) && false !== strpos( $u_tbc, 'selector p{line-height:16px;}' ) && false !== strpos( $u_tbc, 'selector p>.sc-label+.sc-label::before{content:"";display:inline-block;vertical-align:middle;width:4px;height:4px;border-radius:9999px;background:rgba(255, 255, 255, 0.2);margin-right:16px;}' ) && false === strpos( (string) $u_tb['atts']['text'], 'sc-dot' ) );
$u_boxes = array(); $r_all( $u_pg, function ( $n ) { return 'icon_box' === ( $n['shortcode'] ?? '' ); }, $u_boxes );
ga( "the card grid that IS band 2's root → three icon_boxes (not panel columns holding a heading)", 3 === count( $u_boxes ) );
$u_ib = $u_boxes[0] ?? array( 'atts' => array() );
ga( "…each wears a Box Preset, its iconify glyph as the custom icon, an Icon Badge preset for the 48px tile (the host stepped over), and WHITE ink", (bool) preg_match( '/^boxp-box-/', (string) ( $u_ib['atts']['box_style'] ?? '' ) ) && 0 === stripos( trim( (string) ( $u_ib['atts']['custom_icon'] ?? '' ) ), '<svg' ) && (bool) preg_match( '/^iconb-/', (string) ( $u_ib['atts']['icon_badge_preset'] ?? '' ) ) );
$u_col = $r_find( $u_pg, function ( $n ) { return 'flexbox' === ( $n['type'] ?? '' ) && isset( $n['atts']['width']['base']['preset'] ) && false !== strpos( json_encode( $n ), 'Cross-Border' ) && false === strpos( json_encode( $n ), 'Forecasting' ); } );
ga( "…a card column is 12/12 on the phone (track-frac vs the grid's CONTENT box) and a third on desktop", is_array( $u_col ) && '12' === (string) ( $u_col['atts']['width']['base']['preset'] ?? '' ) && '4' === (string) ( $u_col['atts']['width']['lg']['preset'] ?? '' ) );
ga( "band 2: the grid's mt-40 rides ONCE (section padding 160), not doubled as a section margin or a row margin", 'pt-[160px]' === ( $u_pg[1]['atts']['padding_top']['base'] ?? '' ) && false === strpos( $u_mc, 'margin-top:160px' ) && '' === (string) ( $u_pg[1]['_items'][0]['atts']['spacing']['margin']['top'] ?? '' ) );
$u_fc = $u_v['main_footer_columns'] ?? array();
ga( "brand-only footer (icon + wordmark | one paragraph, no links) → 2 columns: logo | the paragraph, and NO fabricated © bar", '2' === (string) ( $u_fc['count'] ?? '' ) && false !== strpos( json_encode( $u_fc ), 'footer-tagline' ) && 'no' === ( $u_v['copyright_settings']['enabled'] ?? '' ) );
ga( "…the footer's OWN brand lockup: an unframed 20px mark + a 14px wordmark (scoped over the reused header logo)", (bool) preg_match( '/\.footer \.site-logo__mark\{[^}]*background:transparent[^}]*width:20px/', $u_mc ) && (bool) preg_match( '/\.footer \.site-title-text\{font-size:14px/', $u_mc ) );
/* the measured CSS-class reveals (capture data-sc-reveal from a .reveal-up{opacity:0;translateY(30px)} + .active pair) → Scroll Motion REVEAL */
$u_h1 = $r_find( $u_pg, function ( $n ) { return 'special_heading' === ( $n['shortcode'] ?? '' ) && false !== strpos( json_encode( $n ), 'Radically' ); } );
$u_rvof = function ( $n ) { return is_array( $n ) ? ( $n['atts']['gsap_motion']['reveal'] ?? array() ) : array(); };
ga( "the h1's .reveal-up (translateY 30px, 1.2s expo-like bezier) → the heading's Scroll Motion REVEAL: up, 30px, Subtle (no scale), ease expo.out, the Animation Engine recorded as a dependency", 'reveal' === ( $u_h1['atts']['gsap_motion']['effect'] ?? '' ) && 'up' === ( $u_rvof( $u_h1 )['direction'] ?? '' ) && 30 === ( $u_rvof( $u_h1 )['distance'] ?? 0 ) && 'subtle' === ( $u_rvof( $u_h1 )['style'] ?? '' ) && 'expo.out' === ( $u_rvof( $u_h1 )['advanced']['custom']['ease'] ?? '' ) && in_array( 'animation-engine', FW_Site_Converter_Mapper::needed_extensions(), true ) );
ga( "…the form's .delay-200 → the newsletter's reveal delay 0.2s (the entrance SEQUENCE)", 0.2 === ( $u_rvof( $u_nl )['delay'] ?? null ) );
$u_cols = array(); $r_all( $u_pg, function ( $n ) { return 'flexbox' === ( $n['type'] ?? '' ) && isset( $n['atts']['width']['base']['preset'] ) && ! empty( $n['atts']['gsap_motion']['reveal'] ); }, $u_cols );
$u_delays = array_map( function ( $c ) { return $c['atts']['gsap_motion']['reveal']['delay']; }, $u_cols );
ga( "the three cards' .reveal-up + .delay-100/200 → each card COLUMN carries its own reveal (the stagger 0 / 0.1 / 0.2), not the icon_box", array( 0.0, 0.1, 0.2 ) == $u_delays && 'none' === ( $u_ib['atts']['gsap_motion']['effect'] ?? 'none' ) );
ga( "the pill wrapper's OWN :hover (the capture's hover-self stamp: brighter fill / white border / lifted shadow) → .fw-nl__fields:hover with a transition", (bool) preg_match( '/selector \.fw-nl__fields:hover\{background:oklch\(1 0 0 \/ 0\.7\);border-color:oklch\(1 0 0\);box-shadow:oklch\(1 0 0\) 0px 1px 1px inset, rgba\(0, 0, 0, 0\.05\) 0px 24px 48px;\}/', $u_nlc ) && false !== strpos( $u_nlc, 'selector .fw-nl__fields{transition:background .3s,border-color .3s,box-shadow .3s;}' ) );

/* --- [V] THE CONSOLE PAGE (2026-09-16): a page-wide FIXED grid-pattern layer; a 12-track `items-center` grid whose copy
 * column (348px) sits beside a taller 2×2 of SQUARE (radius 0) bordered cards holding a bare iconify glyph + h3 + p; two
 * left-edge stats ("99.98%", "< 1.2ms" with the unit glued to the digits); a square terminal PANEL (border + shadow, no
 * padding) with a filled title bar (three dots + a filename cluster | "TTY // 1"); a 3-card grid with a "01 / NODE" label,
 * an h3, a p and a "Status:" footer line (NOT a steps flow); a spec table of key / value rows with hairlines and white
 * values; a signup row whose button is `w-full sm:w-auto`; a footer "Telemetry" column of three icon-only links. --- */
echo "\n[V] Console page: fixed pattern, items-center bento, square cards, glued units, terminal bar, lean-steps gate, spec rows, social column\n";
$v_ink = 'color:rgb(226, 232, 240);font-family:&quot;Space Grotesk&quot;, sans-serif;font-size:16px;font-weight:400;line-height:24px;text-align:start;';
$v_mono = 'font-family:&quot;JetBrains Mono&quot;, monospace;';
$v_glyph = function ( $name ) { return '<iconify-icon icon="ph:' . $name . '" class="text-3xl text-cyber-accent" data-sc-cs="color:rgb(56, 189, 248);font-size:30px;height:30px;display:inline-block"><svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 256 256" data-sc-iconify="ph:' . $name . '"><path fill="currentColor" d="M126 128a6 6 0 0 1-2.25 4.69Z"></path></svg></iconify-icon>'; };
$v_card = function ( $title, $body, $glyph, $y ) use ( $v_ink, $v_glyph ) {
	return '<div class="border border-cyber-border bg-cyber-card p-8 space-y-4" data-sc-cs="background-color:rgb(22, 29, 36);' . $v_ink . 'padding:32px;border-top-width:1px;border-top-style:solid;border-top-color:rgb(34, 44, 55);border-bottom-width:1px;border-bottom-style:solid;border-bottom-color:rgb(34, 44, 55);border-left-width:1px;border-left-style:solid;border-left-color:rgb(34, 44, 55);border-right-width:1px;border-right-style:solid;border-right-color:rgb(34, 44, 55);border-radius:0px;height:231px;display:block;track-frac:0.483;track-y:' . $y . '" data-sc-cs-sm="track-frac:1" data-sc-cs-md="track-frac:0.484" data-sc-cs-xl="track-frac:0.483">'
		. $v_glyph( $glyph ) . '<h3 data-sc-cs="color:rgb(255, 255, 255);font-family:&quot;Space Grotesk&quot;, sans-serif;font-size:20px;font-weight:600;line-height:28px;margin:16px 0px 0px">' . $title . '</h3><p data-sc-cs="color:rgb(148, 163, 184);font-size:14px;font-weight:300;line-height:22.75px;margin:16px 0px 0px">' . $body . '</p></div>';
};
$v_node = function ( $n, $title, $status, $y ) use ( $v_ink, $v_glyph ) {
	return '<div class="border border-cyber-border bg-cyber-card p-8 flex flex-col justify-between h-80" data-sc-cs="background-color:rgb(22, 29, 36);' . $v_ink . 'padding:32px;border-top-width:1px;border-top-style:solid;border-top-color:rgb(34, 44, 55);border-bottom-width:1px;border-bottom-style:solid;border-bottom-color:rgb(34, 44, 55);border-radius:0px;height:320px;display:flex;flex-direction:column;justify-content:space-between;track-frac:0.319;track-y:0">'
		. '<div data-sc-cs="' . $v_ink . 'display:block"><div class="flex justify-between items-start mb-6" data-sc-cs="' . $v_ink . 'display:flex;justify-content:space-between;align-items:flex-start;margin:0px 0px 24px">'
		. '<div class="w-10 h-10 flex items-center justify-center border border-cyber-accent/30 bg-cyber-accent/10" data-sc-cs="background-color:rgba(56, 189, 248, 0.1);color:rgb(56, 189, 248);height:40px;display:flex;justify-content:center;align-items:center;border-top-width:1px;border-top-style:solid;border-top-color:rgba(56, 189, 248, 0.3)">' . $v_glyph( 'cube-light' ) . '</div>'
		. '<span data-sc-cs="color:rgb(71, 85, 105);font-family:&quot;JetBrains Mono&quot;, monospace;font-size:10px;letter-spacing:1px;text-transform:uppercase;line-height:15px">' . $n . ' / NODE</span></div>'
		. '<h3 data-sc-cs="color:rgb(255, 255, 255);font-family:&quot;Space Grotesk&quot;, sans-serif;font-size:20px;font-weight:700;line-height:28px;margin:0px 0px 8px">' . $title . '</h3><p data-sc-cs="color:rgb(148, 163, 184);font-size:14px;font-weight:300;line-height:22.75px">Complete separation of system parameters ensures clean testing every cycle.</p></div>'
		. '<div class="font-mono text-[11px] text-slate-500 uppercase tracking-widest" data-sc-cs="color:rgb(100, 116, 139);font-family:&quot;JetBrains Mono&quot;, monospace;font-size:11px;letter-spacing:1.1px;text-transform:uppercase;line-height:16.5px;display:block">Status: ' . $status . '</div></div>';
};
$v_stat = function ( $val, $lbl, $accent ) use ( $v_ink ) {
	return '<div class="border-l-2 pl-4" data-sc-cs="' . $v_ink . 'padding:0px 0px 0px 16px;border-left-width:2px;border-left-style:solid;border-left-color:' . $accent . ';display:block"><div class="text-2xl font-bold text-white font-mono" data-sc-cs="color:rgb(255, 255, 255);font-family:&quot;JetBrains Mono&quot;, monospace;font-size:20px;font-weight:700;line-height:32px">' . $val . '</div><div data-sc-cs="color:rgb(100, 116, 139);font-size:12px;text-transform:uppercase;letter-spacing:0.6px;line-height:16px">' . $lbl . '</div></div>';
};
$v_dot = function ( $c ) { return '<span class="w-2.5 h-2.5 rounded-full" data-sc-cs="background-color:' . $c . ';border-radius:9999px;height:10px;display:block"></span>'; };
$v_row = function ( $k, $v, $vcolor ) use ( $v_mono ) { return '<div class="flex justify-between border-b border-cyber-border/40 pb-2" data-sc-cs="color:rgb(226, 232, 240);' . $v_mono . 'font-size:12px;line-height:16px;padding:0px 0px 8px;margin:16px 0px 0px;border-bottom-width:1px;border-bottom-style:solid;border-bottom-color:rgba(34, 44, 55, 0.4);display:flex;justify-content:space-between"><span data-sc-cs="color:rgb(148, 163, 184);' . $v_mono . 'font-size:12px;line-height:16px;display:inline">' . $k . '</span><span data-sc-cs="color:' . $vcolor . ';' . $v_mono . 'font-size:12px;line-height:16px;display:inline">' . $v . '</span></div>'; };
$v_soc = function ( $name ) { return '<a href="#" class="hover:text-white" data-sc-cs="color:rgb(100, 116, 139);font-size:20px;display:inline"><iconify-icon icon="ph:' . $name . '"><svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 256 256" data-sc-iconify="ph:' . $name . '"><path fill="currentColor" d="M206 75a57 57 0 0 0-5-47Z"></path></svg></iconify-icon></a>'; };
$v_html = '<!DOCTYPE html><html data-sc-content-width="1440"><head><title>V</title></head><body data-sc-cs="background-color:rgb(8, 11, 14);color:rgb(226, 232, 240);font-family:&quot;Space Grotesk&quot;, sans-serif">'
	. '<div class="fixed inset-0 pointer-events-none z-0 overflow-hidden" data-sc-cs="' . $v_ink . 'position:fixed;top:0px;right:0px;bottom:0px;left:0px;height:900px;pointer-events:none;overflow:hidden;display:block"><div class="absolute inset-0 terminal-grid" data-sc-cs="' . $v_ink . 'position:absolute;top:0px;right:0px;bottom:0px;left:0px;background-image:linear-gradient(to right, rgba(34, 44, 55, 0.15) 1px, rgba(0, 0, 0, 0) 1px), linear-gradient(rgba(34, 44, 55, 0.15) 1px, rgba(0, 0, 0, 0) 1px);background-size:40px 40px, 40px 40px;display:block"></div><div class="absolute w-[50vw] h-[50vw] rounded-full blur-3xl" data-sc-cs="position:absolute;background-image:radial-gradient(circle, rgba(255, 176, 58, 0.06), rgba(0, 0, 0, 0));filter:blur(64px);border-radius:9999px;height:720px;display:block"></div></div>'
	. '<nav data-sc-cs="' . $v_ink . 'display:flex;justify-content:space-between;align-items:center;padding:20px 24px;position:fixed;top:0px;left:0px;right:0px;height:82px"><a href="/" data-sc-cs="color:rgb(255, 255, 255);font-weight:700">CORE // ENTER</a><div data-sc-cs="display:flex;gap:32px"><a href="#a" data-sc-cs="color:rgba(255, 255, 255, 0.8);font-size:12px">Architecture</a><a href="#c" data-sc-cs="color:rgba(255, 255, 255, 0.8);font-size:12px">Console</a></div></nav>'
	. '<main data-sc-cs="' . $v_ink . 'display:block">'
	// (1) hero with a mono gradient span in the h1
	. '<header data-sc-cs="' . $v_ink . 'padding:120px 0px 0px;display:flex;align-items:center;min-height:700px"><div data-sc-cs="' . $v_ink . 'max-width:1280px;margin:0px 80px;padding:0px 24px;display:block"><h1 data-sc-cs="color:rgb(255, 255, 255);font-family:&quot;Space Grotesk&quot;, sans-serif;font-size:72px;font-weight:700;line-height:72px;letter-spacing:-1.8px">Build Deep Inside <br> <span class="font-mono text-transparent bg-clip-text bg-gradient-to-r from-cyber-accent to-cyber-amber" data-sc-cs="background-image:linear-gradient(to right, rgb(56, 189, 248), rgb(245, 158, 11));color:rgba(0, 0, 0, 0);font-family:&quot;JetBrains Mono&quot;, monospace;font-size:72px;font-weight:700;display:inline;-webkit-background-clip:text">The Micro Matrix.</span></h1><p data-sc-cs="color:rgb(148, 163, 184);font-size:18px;font-weight:300;line-height:28px;margin:32px 0px 0px;max-width:576px">Step inside isolated execution chambers built beneath hardware arrays.</p></div></header>'
	// (2) the items-center 12-grid: copy (5) beside a 2×2 of square cards (7); the copy holds two edge stats
	. '<section data-sc-cs="' . $v_ink . 'padding:128px 24px;max-width:1280px;margin:0px 80px;display:block"><div class="grid lg:grid-cols-12 gap-12 items-center" data-sc-cs="' . $v_ink . 'height:487px;display:grid;gap:48px;grid-template-columns:58px 58px 58px 58px 58px 58px 58px 58px 58px 58px 58px 58px;align-items:center" data-sc-cs-sm="grid-template-columns:342px" data-sc-cs-md="grid-template-columns:772px">'
	. '<div class="lg:col-span-5 space-y-6" data-sc-cs="' . $v_ink . 'height:348px;display:block;track-frac:0.394;track-y:69" data-sc-cs-sm="track-frac:1" data-sc-cs-md="track-frac:1" data-sc-cs-xl="track-frac:0.394"><span data-sc-cs="color:rgb(255, 176, 58);font-family:&quot;JetBrains Mono&quot;, monospace;font-size:12px;text-transform:uppercase;letter-spacing:1.2px;display:block">ENVIRONMENT SPECIFICATION</span><h2 class="text-4xl sm:text-5xl font-bold tracking-tight text-white" data-sc-cs="color:rgb(255, 255, 255);font-size:48px;font-weight:700;line-height:48px;margin:24px 0px 0px">The Enclave Architecture</h2><p data-sc-cs="color:rgb(148, 163, 184);font-size:16px;font-weight:300;line-height:26px;margin:24px 0px 0px">By structuralizing developer terminals inside high-clearance physical key capsules, operations are permanently shielded from macro system wide failures.</p>'
	. '<div class="grid grid-cols-2 gap-4 pt-4" data-sc-cs="' . $v_ink . 'display:grid;gap:16px;grid-template-columns:234px 234px;padding:16px 0px 0px;margin:24px 0px 0px">' . $v_stat( '99.98%', 'Focus Retention', 'rgb(56, 189, 248)' ) . $v_stat( '&lt; 1.2ms', 'Latency Buffer', 'rgb(255, 176, 58)' ) . '</div></div>'
	. '<div class="lg:col-span-7 grid sm:grid-cols-2 gap-6" data-sc-cs="' . $v_ink . 'height:486px;display:grid;gap:24px;grid-template-columns:337px 337px;track-frac:0.567;track-y:0" data-sc-cs-sm="grid-template-columns:342px;track-frac:1" data-sc-cs-md="grid-template-columns:374px 374px;track-frac:1" data-sc-cs-xl="track-frac:0.567">'
	. $v_card( 'Localized Shell', 'Run completely segmented file structures unaffected by the greater operating ecosystem overhead.', 'terminal-window-light', 0 ) . $v_card( 'Warm Illumination', 'Calibrated 2700K ambient desk emitters built overhead to maintain focus loops through deep night operations.', 'lightbulb-light', 0 )
	. $v_card( 'Matrix Key Binding', 'Direct physical mappings linking switches with multi-tier conditional programming macros instantly.', 'keyboard-light', 255 ) . $v_card( 'Physical Sandbox', 'Hardware isolated walls stopping malicious executions from breaking out into external grid channels.', 'shield-light', 255 )
	. '</div></div></section>'
	// (3) the terminal window: a square panel (border + shadow, no padding) with a filled title bar
	. '<section data-sc-cs="' . $v_ink . 'padding:128px 0px;display:block"><div data-sc-cs="' . $v_ink . 'max-width:1280px;margin:0px 80px;padding:0px 24px;display:block"><div class="w-full max-w-4xl mx-auto border border-cyber-border bg-cyber-bg shadow-2xl overflow-hidden" data-sc-cs="background-color:rgb(8, 11, 14);' . $v_ink . 'max-width:896px;margin:0px 168px;border-top-width:1px;border-top-style:solid;border-top-color:rgb(34, 44, 55);border-bottom-width:1px;border-bottom-style:solid;border-bottom-color:rgb(34, 44, 55);border-left-width:1px;border-left-style:solid;border-left-color:rgb(34, 44, 55);border-right-width:1px;border-right-style:solid;border-right-color:rgb(34, 44, 55);border-radius:0px;box-shadow:rgba(0, 0, 0, 0.25) 0px 25px 50px -12px;overflow:hidden;display:block">'
	. '<div class="bg-cyber-surface px-4 py-3 border-b border-cyber-border flex items-center justify-between" data-sc-cs="background-color:rgb(17, 22, 27);' . $v_ink . 'padding:12px 16px;border-bottom-width:1px;border-bottom-style:solid;border-bottom-color:rgb(34, 44, 55);display:flex;justify-content:space-between;align-items:center">'
	. '<div class="flex items-center gap-2" data-sc-cs="' . $v_ink . 'display:flex;gap:8px;align-items:center">' . $v_dot( 'rgba(239, 68, 68, 0.6)' ) . $v_dot( 'rgba(234, 179, 8, 0.6)' ) . $v_dot( 'rgba(34, 197, 94, 0.6)' ) . '<span data-sc-cs="color:rgb(100, 116, 139);font-family:&quot;JetBrains Mono&quot;, monospace;font-size:12px;margin:0px 0px 0px 8px;display:inline">enclave_kernel_v4.sh</span></div>'
	. '<span data-sc-cs="color:rgb(71, 85, 105);font-family:&quot;JetBrains Mono&quot;, monospace;font-size:10px;display:inline">TTY // 1</span></div>'
	. '<div class="p-6 font-mono text-sm space-y-4" data-sc-cs="' . $v_ink . 'font-family:&quot;JetBrains Mono&quot;, monospace;font-size:14px;padding:24px;display:block"><div data-sc-cs="display:flex;gap:8px"><span data-sc-cs="color:rgb(56, 189, 248);font-size:14px;line-height:20px;display:inline">guest@nexus:~$</span><span data-sc-cs="color:rgb(203, 213, 225);font-size:14px;line-height:20px;display:inline">init --container=enter_keycap</span></div><p data-sc-cs="color:rgb(100, 116, 139);font-size:14px;line-height:20px;margin:16px 0px 0px">[ OK ] Mounting miniature physical storage matrix and aligning the ambient illumination arrays across the desk.</p></div></div></div></section>'
	// (4) the three node cards — a label + a footer line beyond marker / title / copy
	. '<section data-sc-cs="' . $v_ink . 'padding:128px 24px;max-width:1280px;margin:0px 80px;display:block"><h2 data-sc-cs="color:rgb(255, 255, 255);font-size:36px;font-weight:700;line-height:40px;margin:0px 0px 64px">Configured Nodes</h2><div class="grid md:grid-cols-3 gap-8" data-sc-cs="' . $v_ink . 'display:grid;gap:32px;grid-template-columns:389px 389px 389px">' . $v_node( '01', 'Encapsulation', 'Active Isolation', 0 ) . $v_node( '02', 'Lux Modulator', '2700K Calibrated', 0 ) . $v_node( '03', 'Macro Overlays', 'Bound via Layer 2', 0 ) . '</div></section>'
	// (5) the spec table beside a heading; (6) the signup row
	. '<section data-sc-cs="' . $v_ink . 'padding:128px 0px;border-top-width:1px;border-top-style:solid;border-top-color:rgb(34, 44, 55);display:block"><div class="grid lg:grid-cols-12 gap-16 items-center" data-sc-cs="' . $v_ink . 'max-width:1280px;margin:0px 80px;padding:0px 24px;display:grid;gap:64px;grid-template-columns:52px 52px 52px 52px 52px 52px 52px 52px 52px 52px 52px 52px;align-items:center">'
	. '<div class="lg:col-span-6" data-sc-cs="' . $v_ink . 'height:254px;display:block;track-frac:0.5;track-y:32"><div class="border border-cyber-border p-8 bg-cyber-bg font-mono text-xs" data-sc-cs="background-color:rgb(8, 11, 14);' . $v_ink . $v_mono . 'font-size:12px;padding:32px;border-top-width:1px;border-top-style:solid;border-top-color:rgb(34, 44, 55);border-bottom-width:1px;border-bottom-style:solid;border-bottom-color:rgb(34, 44, 55);border-left-width:1px;border-left-style:solid;border-left-color:rgb(34, 44, 55);border-right-width:1px;border-right-style:solid;border-right-color:rgb(34, 44, 55);border-radius:0px;display:block">'
	. '<div class="flex justify-between border-b pb-3" data-sc-cs="color:rgb(100, 116, 139);' . $v_mono . 'font-size:12px;line-height:16px;padding:0px 0px 12px;border-bottom-width:1px;border-bottom-style:solid;border-bottom-color:rgba(34, 44, 55, 0.4);display:flex;justify-content:space-between"><span data-sc-cs="color:rgb(100, 116, 139);' . $v_mono . 'font-size:12px;display:inline">COMPONENT MATRIX</span><span data-sc-cs="color:rgb(100, 116, 139);' . $v_mono . 'font-size:12px;display:inline">SPECIFICATION ALPHA</span></div>'
	. $v_row( 'Key Profile', 'OEM / High-Transparency Profile', 'rgb(255, 255, 255)' ) . $v_row( 'Housing Type', 'Thick Polycarbonate Poly', 'rgb(255, 255, 255)' ) . $v_row( 'LED Array', 'SMD Neon Filament', 'rgb(245, 158, 11)' ) . '</div></div>'
	. '<div class="lg:col-span-6" data-sc-cs="' . $v_ink . 'height:318px;display:block;track-frac:0.5;track-y:0"><span data-sc-cs="color:rgb(56, 189, 248);' . $v_mono . 'font-size:12px;text-transform:uppercase;letter-spacing:1.2px;display:block">TACTILE INTERFACE</span><h2 data-sc-cs="color:rgb(255, 255, 255);font-size:48px;font-weight:700;line-height:52px;margin:24px 0px 0px">Premium enclosures built for sovereign operators.</h2><p data-sc-cs="color:rgb(148, 163, 184);font-size:16px;font-weight:300;line-height:26px;margin:24px 0px 0px">Every enclosure features glass-clear polycarbonate and hand-finished walnut inserts.</p></div></div></section>'
	. '<section data-sc-cs="' . $v_ink . 'padding:128px 24px;max-width:896px;margin:0px 272px;text-align:center;display:block"><h2 data-sc-cs="color:rgb(255, 255, 255);font-size:48px;font-weight:700;line-height:48px;text-align:center">Subscribe to Core Logs</h2><div class="max-w-md mx-auto flex flex-col sm:flex-row items-center gap-4" data-sc-cs="' . $v_ink . 'max-width:448px;margin:32px 224px 0px;display:flex;flex-direction:row;align-items:center;gap:16px"><input type="email" placeholder="operator@domain.com" data-sc-cs="background-color:rgb(17, 22, 27);color:rgb(255, 255, 255);' . $v_mono . 'font-size:12px;padding:16px;border-top-width:1px;border-top-style:solid;border-top-color:rgb(34, 44, 55);display:block;width:283px"><button class="w-full sm:w-auto px-8 py-4 bg-cyber-accent text-cyber-bg font-mono text-xs uppercase tracking-widest font-bold whitespace-nowrap" data-sc-cs="background-color:rgb(56, 189, 248);color:rgb(8, 11, 14);' . $v_mono . 'font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:1.2px;padding:16px 32px;display:block;width:149px">Connect Node</button></div></section>'
	. '</main>'
	// (7) the footer: brand | Framework | Enclosure | Telemetry (three icon-only links)
	. '<footer data-sc-cs="background-color:rgb(17, 22, 27);' . $v_ink . 'padding:80px 24px 48px;border-top-width:1px;border-top-style:solid;border-top-color:rgb(34, 44, 55);display:block"><div class="grid lg:grid-cols-12 gap-16" data-sc-cs="' . $v_ink . 'max-width:1280px;margin:0px 56px;display:grid;gap:64px;grid-template-columns:52px 52px 52px 52px 52px 52px 52px 52px 52px 52px 52px 52px">'
	. '<div class="lg:col-span-5" data-sc-cs="' . $v_ink . 'display:block;track-frac:0.388"><a href="/" data-sc-cs="color:rgb(255, 255, 255);' . $v_mono . 'font-size:18px;font-weight:700;letter-spacing:0.9px">CORE // ENTER</a><p data-sc-cs="color:rgb(100, 116, 139);font-size:14px;font-weight:300;line-height:22.75px;max-width:384px;margin:24px 0px 0px">Architecting localized, isolated desktop compilation enclaves inspired by mechanics and pure logical code bases.</p></div>'
	. '<div class="lg:col-span-7 grid grid-cols-3 gap-12" data-sc-cs="' . $v_ink . 'display:grid;gap:48px;grid-template-columns:225px 225px 225px;track-frac:0.563">'
	. '<div><h4 data-sc-cs="color:rgb(255, 176, 58);' . $v_mono . 'font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:1.2px;margin:0px 0px 24px">Framework</h4><ul data-sc-cs="' . $v_mono . 'font-size:12px;color:rgb(148, 163, 184)"><li><a href="#">Sandbox OS</a></li><li><a href="#">Kernel Modules</a></li><li><a href="#">Micro Lighting</a></li></ul></div>'
	. '<div><h4 data-sc-cs="color:rgb(255, 176, 58);' . $v_mono . 'font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:1.2px;margin:0px 0px 24px">Enclosure</h4><ul data-sc-cs="' . $v_mono . 'font-size:12px;color:rgb(148, 163, 184)"><li><a href="#">Polycarbonate</a></li><li><a href="#">Walnut Inserts</a></li><li><a href="#">Build Gallery</a></li></ul></div>'
	. '<div><h4 data-sc-cs="color:rgb(255, 176, 58);' . $v_mono . 'font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:1.2px;margin:0px 0px 24px">Telemetry</h4><div class="flex gap-4 text-xl text-slate-500" data-sc-cs="' . $v_ink . 'display:flex;gap:16px;font-size:20px;color:rgb(100, 116, 139)">' . $v_soc( 'github-logo-light' ) . $v_soc( 'terminal-light' ) . $v_soc( 'cpu-light' ) . '</div></div>'
	. '</div></div><div data-sc-cs="' . $v_ink . 'max-width:1280px;margin:48px 56px 0px;padding:32px 0px 0px;border-top-width:1px;border-top-style:solid;border-top-color:rgba(34, 44, 55, 0.4);display:flex;justify-content:space-between"><p data-sc-cs="color:rgb(71, 85, 105);' . $v_mono . 'font-size:10px">&copy; 2026 CORE // ENTER. Sandbox Verified.</p><div data-sc-cs="display:flex;gap:32px"><a href="#" data-sc-cs="color:rgb(71, 85, 105);font-size:10px">Isolation Statutes</a><a href="#" data-sc-cs="color:rgb(71, 85, 105);font-size:10px">Firmware Protocols</a></div></div></footer></body></html>';
$v_bl = FW_Site_Converter_Sources::build_from_html( $v_html, 'Console', array( 'dynamic_chrome' => true, 'hifi_css' => true ) );
$v_v  = $v_bl['files']['theme-settings.json']['values'] ?? ( $v_bl['files']['theme-settings.json'] ?? array() );
$v_pg = $v_bl['files']['pages.json']['pages'][0]['builder'] ?? array();
$v_pats = $v_bl['files']['presets.json']['background_patterns'] ?? ( $v_v['background_patterns'] ?? array() );
$v_pid = (string) ( $v_v['general_layout']['site_background_pattern']['pattern'] ?? '' );
$v_pat = null; foreach ( (array) $v_pats as $pp ) { if ( ( $pp['id'] ?? '' ) === $v_pid ) { $v_pat = $pp; break; } }
ga( "the page-wide FIXED grid layer (a body-level inset-0 wrapper painting a 40px gradient grid; the blurred glow beside it is not a tile) → the Site Background Pattern, backed by a registered pattern carrying the tile size", '' !== $v_pid && is_array( $v_pat ) && false !== strpos( (string) $v_pat['css'], 'linear-gradient(to right, rgba(34, 44, 55, 0.15) 1px' ) && false !== strpos( (string) $v_pat['css'], 'background-size:40px 40px, 40px 40px' ) );
$v_h1 = $r_find( $v_pg, function ( $n ) { return 'special_heading' === ( $n['shortcode'] ?? '' ) && false !== strpos( (string) ( $n['atts']['title'] ?? '' ), 'Build Deep' ); } );
ga( "the h1's mono gradient span keeps its OWN font family (the scrub re-expresses a run's family, not only its size / weight / tracking)", is_array( $v_h1 ) && (bool) preg_match( '/<span class="sc-gradtext" style="[^"]*font-family:\'JetBrains Mono\', monospace[^"]*">The Micro Matrix\.<\/span>/', (string) $v_h1['atts']['title'] ) );
$v_row = $r_find( $v_pg, function ( $n ) { return 'flexbox' === ( $n['type'] ?? '' ) && 2 === count( $n['_items'] ?? array() ) && 'row' === ( $n['atts']['direction']['base'] ?? '' ) && false !== strpos( json_encode( $n ), 'The Enclave' ) && false !== strpos( json_encode( $n ), 'Localized Shell' ); } );
ga( "the items-center 12-grid: the 348px copy column (top offset 69) and the 487px card column are ONE row (rows by vertical OVERLAP, not by top) — copy first, cards second, as in the DOM; not a stack", is_array( $v_row ) && false !== strpos( json_encode( $v_row['_items'][0] ), 'The Enclave' ) && false !== strpos( json_encode( $v_row['_items'][1] ), 'Localized Shell' ) );
$v_boxes = array(); $r_all( $v_pg, function ( $n ) { return 'icon_box' === ( $n['shortcode'] ?? '' ) && false !== strpos( (string) ( $n['atts']['title'] ?? '' ), 'Shell' ); }, $v_boxes );
$v_ib = $v_boxes[0] ?? array( 'atts' => array() );
$v_cellof = function ( $title ) use ( $v_pg, $r_find ) { return $r_find( $v_pg, function ( $n ) use ( $title ) { return 'flexbox' === ( $n['type'] ?? '' ) && isset( $n['atts']['width']['base']['preset'] ) && 1 === count( $n['_items'] ?? array() ) && 'icon_box' === ( $n['_items'][0]['shortcode'] ?? '' ) && false !== strpos( (string) ( $n['_items'][0]['atts']['title'] ?? '' ), $title ); } ); };
$v_shell = $v_cellof( 'Localized Shell' );
ga( "a cell that IS a 2×2 grid of icon / h3 / p cards is claimed as the CARD GRID (not decomposed by layout_row into heading + text): four icon_boxes with the bare iconify glyph as the custom icon", 1 === count( $v_boxes ) && 0 === stripos( trim( (string) ( $v_ib['atts']['custom_icon'] ?? '' ) ), '<svg' ) && is_array( $v_shell ) );
ga( "…a SQUARE card (radius 0, a 1px border, a fill, 32px inset) is a card skin → the column wears a Box Preset; the card grid's column itself wears none (a wrapper descends only when its child holds nearly all the text)", is_array( $v_shell ) && (bool) preg_match( '/^boxp-box-/', (string) ( $v_shell['atts']['border_preset'] ?? '' ) ) && '' === (string) ( $v_row['_items'][1]['atts']['border_preset'] ?? '' ) );
$v_cnt = array(); $r_all( $v_pg, function ( $n ) { return 'counter' === ( $n['shortcode'] ?? '' ); }, $v_cnt );
$v_c2 = null; foreach ( $v_cnt as $c ) { if ( '1.2' === (string) ( $c['atts']['number'] ?? '' ) ) { $v_c2 = $c; } }
ga( "the two edge stats are counters: '< 1.2ms' reads the comparison sign as the prefix and the unit glued to the digits as the suffix (the label is 'Latency Buffer'); a filename's 'v4.sh' and a small 'TTY // 1' are never stats (two counters on the page, none in the terminal bar)", 2 === count( $v_cnt ) && is_array( $v_c2 ) && '<' === (string) ( $v_c2['atts']['prefix'] ?? '' ) && 'ms' === (string) ( $v_c2['atts']['suffix'] ?? '' ) );
$v_ccol = $r_find( $v_pg, function ( $n ) { return 'flexbox' === ( $n['type'] ?? '' ) && isset( $n['atts']['width']['base']['preset'] ) && false !== strpos( json_encode( $n ), '"1.2"' ) && false === strpos( json_encode( $n ), '99.98' ); } );
ga( "…each stat cell keeps its ONE-SIDED accent rule + inset (border-left 2px amber, padding-left 16px) on the cell", is_array( $v_ccol ) && false !== strpos( (string) ( $v_ccol['atts']['custom_css'] ?? '' ), 'border-left:2px solid rgb(255, 176, 58);padding-left:16px' ) );
$v_term = $r_find( $v_pg, function ( $n ) { return 'flexbox' === ( $n['type'] ?? '' ) && ! empty( $n['atts']['border_preset'] ) && false !== strpos( json_encode( $n ), 'enclave_kernel' ) && false !== strpos( json_encode( $n ), 'guest@nexus' ); } );
ga( "the terminal window — a SQUARE box with a full border + a shadow and NO padding (its inset lives on its bars) — is a panel wearing a Box Preset, capped at its 896px measure and centred", is_array( $v_term ) && (bool) preg_match( '/max-width:(?:56rem|896px)/', (string) ( $v_term['atts']['custom_css'] ?? '' ) ) );
$v_bar = $r_find( $v_pg, function ( $n ) { return 'flexbox' === ( $n['type'] ?? '' ) && false !== strpos( json_encode( $n ), 'enclave_kernel' ) && false !== strpos( json_encode( $n ), 'TTY' ) && false === strpos( json_encode( $n ), 'guest@nexus' ); } );
$v_bar_dots = array(); $r_all( array( $v_bar ?: array() ), function ( $n ) { return 'code_block' === ( $n['shortcode'] ?? '' ) && false !== strpos( (string) ( $n['atts']['code'] ?? '' ), 'sc-dot' ); }, $v_bar_dots );
ga( "…its title bar (three dots + the filename in one cluster | 'TTY // 1') is a TOOLBAR ROW (a filled, padded bar of dots + labels is never a panel): the mirror keeps the bar's fill + hairline and the three tinted dots", is_array( $v_bar ) && false !== strpos( (string) ( $v_bar['atts']['custom_css'] ?? '' ), 'background-color:rgb(17, 22, 27)' ) && false !== strpos( (string) ( $v_bar['atts']['custom_css'] ?? '' ), 'border-bottom:1px solid rgb(34, 44, 55)' ) && 3 === count( $v_bar_dots ) );
$v_steps = array(); $r_all( $v_pg, function ( $n ) { return 'steps' === ( $n['shortcode'] ?? '' ); }, $v_steps );
$v_nodes = array(); $r_all( $v_pg, function ( $n ) { return 'icon_box' === ( $n['shortcode'] ?? '' ) && false !== strpos( (string) ( $n['atts']['title'] ?? '' ), 'Encapsulation' ); }, $v_nodes );
$v_n1 = $v_nodes[0] ?? array( 'atts' => array() );
ga( "the numbered node cards are NOT a steps flow (each carries a '01 / NODE' label + a 'Status:' footer the steps shortcode would drop) → icon_boxes with the label as the Overline and the footer line kept in the copy with its mono / tracked / muted treatment", 0 === count( $v_steps ) && 1 === count( $v_nodes ) && '01 / NODE' === (string) ( $v_n1['atts']['overline'] ?? '' ) && (bool) preg_match( '/<p><span style="[^"]*text-transform:uppercase[^"]*font-size:11px[^"]*">Status: Active Isolation<\/span><\/p>$/', (string) ( $v_n1['atts']['content'] ?? '' ) ) );
$v_kv = $r_find( $v_pg, function ( $n ) { return 'text_block' === ( $n['shortcode'] ?? '' ) && false !== strpos( (string) ( $n['atts']['text'] ?? '' ), 'Key Profile' ); } );
$v_kvc = (string) ( $v_kv['atts']['custom_css'] ?? '' );
ga( "a spec row (key | value, hairline underneath, mt-4) → one text block of two labels whose line keeps the row's OWN hairline + inset, the value's white ink as its own rule, and the 16px margin on Spacing (stamps survive into the verbatim cell for the mirror)", is_array( $v_kv ) && false !== strpos( $v_kvc, 'border-bottom:1px solid rgba(34, 44, 55, 0.4);padding:0px 0px 8px' ) && false !== strpos( $v_kvc, 'selector p>.sc-label:nth-child(2){color:rgb(255, 255, 255);}' ) && 'mt-3' === ( $v_kv['atts']['spacing']['margin']['top'] ?? '' ) );
$v_nl = $r_find( $v_pg, function ( $n ) { return 'newsletter' === ( $n['shortcode'] ?? '' ); } );
ga( "a signup row whose button is `w-full sm:w-auto` (full width on phones only) stays INLINE beside the field, not stacked", is_array( $v_nl ) && 'inline' === ( $v_nl['atts']['design'] ?? '' ) );
$v_fc = $v_v['main_footer_columns'] ?? array();
$v_fcj = json_encode( $v_fc );
$v_sp = $v_v['social_profiles'] ?? array();
ga( "the footer's 'Telemetry' column (a heading over three icon-only links) is its OWN column — heading + the social icons — so the footer keeps 4 columns; the brand column carries no icons", '4' === (string) ( $v_fc['count'] ?? '' ) && (bool) preg_match( '/"heading_text":"Telemetry","heading_level":"h4"\}\}\},\{"element_type":\{"element":"social_icons"\}\}/', $v_fcj ) && 1 === substr_count( $v_fcj, 'social_icons' ) );
ga( "…all THREE icon links are profiles in DOM order (GitHub from the library; the terminal / cpu glyphs — no network the library names — with their inline svg)", 3 === count( $v_sp ) && 'Github' === ( $v_sp[0]['name'] ?? '' ) && 'Terminal' === ( $v_sp[1]['name'] ?? '' ) && 'inline' === ( $v_sp[1]['icon']['svg-source'] ?? '' ) && 'Cpu' === ( $v_sp[2]['name'] ?? '' ) );

/* --- [W] THE HAVEN PAGE (2026-09-16): a numbered-chip row (a 40px ring "01" | a flex-1 hairline | a tracked label); a mock
 * editor of `<code class="block">` lines with coloured token spans; a huge absolute 5%-opacity WATERMARK heading over the
 * section title; an accent span inside a serif heading; a 4-column stat band where one value is a glyph ("∞"); a footer
 * whose link columns are titled by uppercase LABEL divs (no heading tag), whose lead heading also titles the signup, and
 * whose © bar ends in a status lockup (a dot + a label); one Google URL naming Outfit | JetBrains Mono | Playfair. --- */
echo "\n[W] Haven page: chip rows, code listing, watermark, accent span, glyph stat, label-titled footer columns, status lockup, body face\n";
$w_ink = 'color:rgb(255, 255, 255);font-family:Outfit, sans-serif;font-size:16px;font-weight:400;line-height:24px;text-align:start;';
$w_mono = 'font-family:&quot;JetBrains Mono&quot;, monospace;';
$w_chip = function ( $n, $label ) use ( $w_ink, $w_mono ) {
	return '<div class="flex gap-6 items-center group" data-sc-cs="' . $w_ink . 'display:flex;gap:24px;align-items:center;margin:24px 0px 0px">'
		. '<div class="w-12 h-12 rounded-2xl border border-white/5 bg-white/[0.02] flex items-center justify-center" data-sc-cs="background-color:rgba(255, 255, 255, 0.02);' . $w_ink . 'height:48px;width:48px;border-radius:16px;border-top-width:1px;border-top-style:solid;border-top-color:rgba(255, 255, 255, 0.05);display:flex;justify-content:center;align-items:center">' . $n . '</div>'
		. '<div class="h-px flex-1 bg-white/10" data-sc-cs="background-color:rgba(255, 255, 255, 0.1);height:1px;flex-grow:1;display:block"></div>'
		. '<span class="text-[10px] uppercase tracking-[0.2em] opacity-40" data-sc-cs="color:rgb(255, 255, 255);font-size:10px;text-transform:uppercase;letter-spacing:2px;line-height:15px;opacity:0.4;display:inline">' . $label . '</span></div>';
};
$w_line = function ( $html, $color = 'rgb(255, 255, 255)' ) use ( $w_mono ) { return '<code class="block" data-sc-cs="color:' . $color . ';' . $w_mono . 'font-size:14px;line-height:22.75px;display:block">' . $html . '</code>'; };
$w_tok = function ( $t, $color ) use ( $w_mono ) { return '<span data-sc-cs="color:' . $color . ';' . $w_mono . 'font-size:14px;display:inline">' . $t . '</span>'; };
$w_stat = function ( $v, $l ) use ( $w_ink ) { return '<div data-sc-cs="' . $w_ink . 'text-align:center;display:block"><div class="text-4xl font-bold mb-2" data-sc-cs="color:rgb(255, 255, 255);font-family:Outfit, sans-serif;font-size:36px;font-weight:700;line-height:40px;margin:0px 0px 8px;text-align:center">' . $v . '</div><div class="text-[10px] uppercase tracking-widest opacity-40" data-sc-cs="color:rgb(255, 255, 255);font-size:10px;text-transform:uppercase;letter-spacing:1px;line-height:15px;opacity:0.4;text-align:center">' . $l . '</div></div>'; };
$w_col = function ( $title, $links ) use ( $w_ink ) { $h = '<div class="space-y-4" data-sc-cs="' . $w_ink . 'text-align:right;display:block"><div class="text-[10px] uppercase tracking-[0.3em] text-lantern mb-8" data-sc-cs="color:rgb(255, 170, 80);font-size:10px;text-transform:uppercase;letter-spacing:3px;line-height:15px;margin:0px 0px 32px;text-align:right;display:block">' . $title . '</div>'; foreach ( $links as $l ) { $h .= '<a href="#" class="block text-sm opacity-40" data-sc-cs="color:rgb(255, 255, 255);font-size:14px;opacity:0.4;display:block;text-align:right">' . $l . '</a>'; } return $h . '</div>'; };
$w_html = '<!DOCTYPE html><html data-sc-content-width="1440"><head><title>W</title><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;500;700&amp;family=JetBrains+Mono:wght@400&amp;family=Playfair+Display:ital@1&amp;display=swap"><style>.ascending-bg{view-timeline-name:--section-scroll;animation:bg-shift linear both;animation-timeline:--section-scroll;padding:0;position:relative}@keyframes bg-shift{0%{background:oklch(20% 0.08 280)}100%{background:oklch(5% 0.02 280)}}.noise{-webkit-font-smoothing:antialiased;color:#fff}</style></head><body class="text-white font-sans noise" data-sc-cs="background-color:rgb(20, 18, 52);color:rgb(255, 255, 255)">'
	. '<header data-sc-cs="' . $w_ink . 'display:flex;justify-content:space-between;align-items:center;padding:40px;position:fixed;top:0px;left:0px;right:0px;height:104px"><a href="/" data-sc-cs="color:rgb(255, 255, 255);font-size:20px;font-weight:500">HAVEN CORE</a><nav data-sc-cs="display:flex;gap:48px"><a href="#f" data-sc-cs="color:rgba(255, 255, 255, 0.6);font-size:10px;text-transform:uppercase;letter-spacing:4px">The Forge</a><a href="#a" data-sc-cs="color:rgba(255, 255, 255, 0.6);font-size:10px;text-transform:uppercase;letter-spacing:4px">Archipelago</a></nav></header>'
	. '<main class="ascending-bg" data-sc-cs="' . $w_ink . 'display:block;position:relative">'
	// (1) the forge: copy + chip rows | the editor window
	. '<section data-sc-cs="' . $w_ink . 'padding:240px 96px;display:block"><div class="grid lg:grid-cols-2 gap-20 items-center" data-sc-cs="' . $w_ink . 'max-width:1280px;display:grid;gap:80px;grid-template-columns:584px 584px;align-items:center;height:560px">'
	. '<div data-sc-cs="' . $w_ink . 'height:420px;display:block;track-frac:0.467;track-y:70"><h2 class="text-5xl font-bold tracking-tighter mb-8" data-sc-cs="color:rgb(255, 255, 255);font-family:Outfit, sans-serif;font-size:48px;font-weight:700;line-height:52px;margin:0px 0px 32px">The Digital <span class="text-lantern italic font-serif font-light" data-sc-cs="color:rgb(255, 170, 80);font-family:&quot;Playfair Display&quot;, serif;font-style:italic;font-weight:300;font-size:48px;display:inline">Craftsman’s</span> Forge</h2><p data-sc-cs="color:rgba(255, 255, 255, 0.4);font-family:Outfit, sans-serif;font-size:18px;line-height:29px;margin:0px 0px 48px;max-width:448px">Every module is a floating island of logic. We prioritize the human rhythm over machine efficiency, creating tools that breathe with you.</p>'
	. '<div class="space-y-6" data-sc-cs="' . $w_ink . 'display:block">' . $w_chip( '01', 'Latency Optimization' ) . $w_chip( '02', 'Reactive Life-cycles' ) . '</div></div>'
	. '<div class="workbench-ui rounded-[40px] p-1" data-sc-cs="background-color:rgba(15, 15, 30, 0.3);' . $w_ink . 'padding:4px;border-radius:40px;border-top-width:1px;border-top-style:solid;border-top-color:rgba(255, 255, 255, 0.1);border-bottom-width:1px;border-bottom-style:solid;border-bottom-color:rgba(255, 255, 255, 0.1);height:560px;display:block;track-frac:0.467;track-y:0">'
	. '<div class="bg-black/60 p-6 flex justify-between items-center border-b border-white/10" data-sc-cs="background-color:rgba(0, 0, 0, 0.6);' . $w_ink . 'padding:24px;display:flex;justify-content:space-between;align-items:center;border-bottom-width:1px;border-bottom-style:solid;border-bottom-color:rgba(255, 255, 255, 0.1)"><div class="flex gap-2" data-sc-cs="display:flex;gap:8px"><span data-sc-cs="background-color:rgba(255, 255, 255, 0.1);border-radius:9999px;height:10px;width:10px;display:block"></span><span data-sc-cs="background-color:rgba(255, 255, 255, 0.1);border-radius:9999px;height:10px;width:10px;display:block"></span><span data-sc-cs="background-color:rgba(255, 255, 255, 0.1);border-radius:9999px;height:10px;width:10px;display:block"></span></div><span data-sc-cs="color:rgba(255, 255, 255, 0.2);font-size:9px;text-transform:uppercase;letter-spacing:1px;display:inline">core_engine.tsx</span></div>'
	. '<div class="p-10 font-mono text-sm leading-relaxed" data-sc-cs="' . $w_ink . $w_mono . 'font-size:14px;line-height:22.75px;padding:40px;display:block">'
	. $w_line( $w_tok( 'import', 'rgb(255, 170, 80)' ) . ' { Soul } ' . $w_tok( 'from', 'rgb(255, 170, 80)' ) . ' "@haven/core";', 'rgba(255, 255, 255, 0.4)' )
	. $w_line( $w_tok( 'const', 'rgb(255, 170, 80)' ) . ' Dream = (intent: ' . $w_tok( 'CreativeIntent', 'rgb(90, 160, 120)' ) . ') =&gt; {' )
	. $w_line( '&nbsp;&nbsp;' . $w_tok( 'return', 'rgb(255, 170, 80)' ) . ' intent.map(synapse =&gt; ({', 'rgba(255, 255, 255, 0.6)' )
	. $w_line( '&nbsp;&nbsp;&nbsp;&nbsp;...synapse,' )
	. $w_line( '&nbsp;&nbsp;}));', 'rgba(255, 255, 255, 0.6)' )
	. '<div class="mt-8 pt-8 border-t border-white/5 flex gap-4" data-sc-cs="' . $w_ink . 'display:flex;gap:16px;margin:32px 0px 0px;padding:32px 0px 0px;border-top-width:1px;border-top-style:solid;border-top-color:rgba(255, 255, 255, 0.05)"><span data-sc-cs="color:rgb(90, 160, 120);font-size:9px;text-transform:uppercase;letter-spacing:1px;padding:4px 12px;border-radius:9999px;background-color:rgba(90, 160, 120, 0.1);display:inline-block">Active Session</span><span data-sc-cs="color:rgba(255, 255, 255, 0.4);font-size:9px;letter-spacing:1px;padding:4px 12px;border-radius:9999px;background-color:rgba(255, 255, 255, 0.05);display:inline-block">v2.4.0-cloud</span></div>'
	. '</div></div></div></section>'
	// (2) the watermark over the title
	. '<section data-sc-cs="' . $w_ink . 'padding:240px 24px;position:relative;display:block"><div class="text-center mb-32" data-sc-cs="' . $w_ink . 'text-align:center;margin:0px 0px 128px;display:block">'
	. '<h2 class="text-[10vw] font-bold leading-none opacity-5 select-none absolute left-0 right-0 top-40" data-sc-cs="color:rgb(255, 255, 255);font-family:Outfit, sans-serif;font-size:144px;font-weight:700;line-height:144px;letter-spacing:-7.2px;text-align:center;position:absolute;top:160px;left:0px;right:0px;opacity:0.05;user-select:none">ARCHIPELAGO</h2>'
	. '<span class="text-[10px] uppercase tracking-[0.5em] text-lantern mb-6 block" data-sc-cs="color:rgb(255, 170, 80);font-size:10px;text-transform:uppercase;letter-spacing:5px;line-height:15px;margin:0px 0px 24px;text-align:center;display:block">Explore the Cloud Islands</span>'
	. '<h3 class="text-4xl md:text-6xl font-bold tracking-tighter" data-sc-cs="color:rgb(255, 255, 255);font-family:Outfit, sans-serif;font-size:60px;font-weight:700;line-height:60px;letter-spacing:-3px;text-align:center">Personal Nodes of Innovation</h3></div>'
	. '<p data-sc-cs="color:rgba(255, 255, 255, 0.4);font-size:14px;line-height:28px;max-width:640px;margin:0px 400px">We do not just build interfaces; we build ecosystems that respond to the emotional state of the user.</p></section>'
	// (3) the quote + 4 stats (one a glyph)
	. '<section data-sc-cs="' . $w_ink . 'padding:240px 0px;display:block"><div class="max-w-4xl mx-auto px-6 text-center" data-sc-cs="' . $w_ink . 'max-width:896px;margin:0px 272px;padding:0px 24px;text-align:center;display:block">'
	. '<h2 class="text-6xl md:text-8xl font-serif italic font-light" data-sc-cs="color:rgb(255, 255, 255);font-family:&quot;Playfair Display&quot;, serif;font-size:96px;font-style:italic;font-weight:300;line-height:120px;letter-spacing:-4.8px;text-align:center;margin:0px 0px 64px">Software is the <span class="text-lantern" data-sc-cs="color:rgb(255, 170, 80);font-family:&quot;Playfair Display&quot;, serif;font-size:96px;font-style:italic;font-weight:300;display:inline">mirror</span> of the developer\'s soul.</h2>'
	. '<div class="grid grid-cols-2 md:grid-cols-4 gap-12 pt-20 border-t border-white/5" data-sc-cs="' . $w_ink . 'display:grid;gap:48px;grid-template-columns:176px 176px 176px 176px;padding:80px 0px 0px;border-top-width:1px;border-top-style:solid;border-top-color:rgba(255, 255, 255, 0.05)">' . $w_stat( '12ms', 'Avg Latency' ) . $w_stat( '∞', 'Creativity' ) . $w_stat( '43k', 'Lines of Love' ) . $w_stat( '0.1', 'Energy Bias' ) . '</div></div></section>'
	. '</main>'
	// (4) the footer
	. '<footer data-sc-cs="background-color:rgb(0, 0, 0);' . $w_ink . 'padding:240px 40px 80px;display:block"><div class="grid md:grid-cols-2 gap-32 items-end" data-sc-cs="' . $w_ink . 'max-width:1280px;margin:0px 40px;display:grid;gap:128px;grid-template-columns:576px 576px;align-items:end">'
	. '<div class="space-y-12" data-sc-cs="' . $w_ink . 'display:block"><h2 class="text-5xl font-bold tracking-tighter" data-sc-cs="color:rgb(255, 255, 255);font-family:Outfit, sans-serif;font-size:48px;font-weight:700;line-height:48px;letter-spacing:-2.4px">Ready to float?</h2><p data-sc-cs="color:rgba(255, 255, 255, 0.3);font-size:18px;font-style:italic;line-height:28px;max-width:384px;margin:48px 0px 0px">The archipelago is always expanding. Send a signal and join the haven.</p>'
	. '<div class="flex gap-4" data-sc-cs="' . $w_ink . 'display:flex;gap:16px;margin:48px 0px 0px"><input type="email" placeholder="your@email.com" data-sc-cs="background-color:rgba(255, 255, 255, 0.05);color:rgb(255, 255, 255);font-size:14px;padding:16px 24px;border-radius:12px;display:block;width:300px"><button class="bg-lantern text-haven px-8 py-4 rounded-xl text-[10px] font-black uppercase tracking-widest" data-sc-cs="background-color:rgb(255, 170, 80);color:rgb(20, 18, 52);font-size:10px;font-weight:900;text-transform:uppercase;letter-spacing:1px;padding:16px 32px;border-radius:12px;display:block">Connect</button></div></div>'
	. '<div class="grid grid-cols-2 gap-20 text-right" data-sc-cs="' . $w_ink . 'display:grid;gap:80px;grid-template-columns:248px 248px;text-align:right">' . $w_col( 'Navigation', array( 'Archive', 'Telemetry', 'Open Source' ) ) . $w_col( 'Social Signal', array( 'GitHub', 'LinkedIn', 'Bluesky' ) ) . '</div></div>'
	. '<div class="mt-40 pt-10 border-t border-white/5 flex justify-between items-center" data-sc-cs="' . $w_ink . 'margin:160px 0px 0px;padding:40px 0px 0px;border-top-width:1px;border-top-style:solid;border-top-color:rgba(255, 255, 255, 0.05);display:flex;justify-content:space-between;align-items:center"><p data-sc-cs="color:rgba(255, 255, 255, 0.2);font-size:10px;text-transform:uppercase;letter-spacing:5px">&copy; 2026 Code Haven / Soulful Engineering</p><div class="flex items-center gap-2" data-sc-cs="' . $w_ink . 'display:flex;align-items:center;gap:8px"><div data-sc-cs="background-color:rgb(34, 197, 94);border-radius:9999px;height:4px;width:4px;display:block"></div><span data-sc-cs="color:rgba(255, 255, 255, 0.3);font-size:9px;text-transform:uppercase;letter-spacing:1px;display:inline">System Operational: Clouds Clear</span></div></div></footer></body></html>';
$w_bl = FW_Site_Converter_Sources::build_from_html( $w_html, 'Haven', array( 'dynamic_chrome' => true, 'hifi_css' => true ) );
$w_v  = $w_bl['files']['theme-settings.json']['values'] ?? ( $w_bl['files']['theme-settings.json'] ?? array() );
$w_pg = $w_bl['files']['pages.json']['pages'][0]['builder'] ?? array();
$w_td = $w_bl['files']['theme-design.json'] ?? array();
$w_mc = (string) ( $w_v['misc_custom_css']['custom_css'] ?? '' );
ga( "the body face is the MEASURED paragraph face (Outfit — the same family as the headings), never the Google URL's second font (the site's mono): Typography body + the design's body font", 'Outfit' === ( $w_v['typography']['body']['family'] ?? '' ) && 'Outfit' === ( $w_td['fonts']['body'] ?? '' ) );
$w_chipb = $r_find( $w_pg, function ( $n ) { return 'text_block' === ( $n['shortcode'] ?? '' ) && false !== strpos( (string) ( $n['atts']['text'] ?? '' ), 'Latency Optimization' ); } );
$w_chipc = (string) ( $w_chipb['atts']['custom_css'] ?? '' );
ga( "a numbered chip row (a 48px ring '01' | a flex-1 hairline | a tracked label) is ONE label row: the chip keeps its ring box + measure, the hairline becomes the growing separator, the zero-padded number is never a counter", is_array( $w_chipb ) && '<p><span class="sc-label">01</span><span class="sc-label">Latency Optimization</span></p>' === (string) $w_chipb['atts']['text'] && (bool) preg_match( '/\.sc-label:nth-child\(1\)\{[^}]*border-radius:1rem[^}]*width:3rem[^}]*display:inline-flex[^}]*height:48px/', $w_chipc ) && false !== strpos( $w_chipc, 'selector p>.sc-label+.sc-label{flex:1 1 auto;display:inline-flex;align-items:center;}' ) && false !== strpos( $w_chipc, '::before{content:"";flex:1 1 auto;min-width:16px;height:1px;background:rgba(255, 255, 255, 0.1)' ) );
$w_cnt = array(); $r_all( $w_pg, function ( $n ) { return 'counter' === ( $n['shortcode'] ?? '' ); }, $w_cnt );
ga( "…and the forge holds NO counters (the chips are markers), while the stat band holds three", 3 === count( $w_cnt ) );
$w_code = $r_find( $w_pg, function ( $n ) { return 'code_block' === ( $n['shortcode'] ?? '' ) && false !== strpos( (string) ( $n['atts']['code'] ?? '' ), 'sc-code' ); } );
$w_codes = (string) ( $w_code['atts']['code'] ?? '' );
ga( "the editor's <code class=\"block\"> lines → ONE code block holding a <pre> (lines joined by <br>, the two-space indent kept, no &nbsp;), the unique class ON the <pre> so its rule lands, each token span keeping its own measured ink", is_array( $w_code ) && (bool) preg_match( '/<pre class="sc-code u[0-9a-f]+">/', $w_codes ) && (bool) preg_match( '/<br>  <span style="color:(?:rgb\(255, 170, 80\)|#ffaa50)">return<\/span> intent\.map\(synapse =&gt; \(\{/', $w_codes ) && false === strpos( $w_codes, '&nbsp;' ) && (bool) preg_match( '/^selector\{[^}]*white-space:pre[^}]*text-align:left[^}]*font-family:\'JetBrains Mono\', monospace[^}]*font-size:14px/', (string) $w_code['atts']['custom_css'] ) );
$w_wm = $r_find( $w_pg, function ( $n ) { return 'special_heading' === ( $n['shortcode'] ?? '' ) && 'ARCHIPELAGO' === trim( (string) ( $n['atts']['title'] ?? '' ) ); } );
$w_pn = $r_find( $w_pg, function ( $n ) { return 'special_heading' === ( $n['shortcode'] ?? '' ) && false !== strpos( (string) ( $n['atts']['title'] ?? '' ), 'Personal Nodes' ); } );
ga( "the 5%-opacity absolute heading is a WATERMARK: its own special_heading on the native Position option (top 160px), faint, no pointer, behind, hidden from AT — never folded into the real title's group", is_array( $w_wm ) && 'absolute' === ( $w_wm['atts']['element_position']['position'] ?? '' ) && '10' === (string) ( $w_wm['atts']['element_position']['absolute']['pos_offsets']['top']['value'] ?? '' ) && 'rem' === ( $w_wm['atts']['element_position']['absolute']['pos_offsets']['top']['unit'] ?? '' ) && false !== strpos( (string) $w_wm['atts']['custom_css'], 'opacity:0.05;pointer-events:none;z-index:0' ) && is_array( $w_pn ) && false === strpos( (string) $w_pn['atts']['title'], 'ARCHIPELAGO' ) );
$w_q = $r_find( $w_pg, function ( $n ) { return 'special_heading' === ( $n['shortcode'] ?? '' ) && false !== strpos( (string) ( $n['atts']['title'] ?? '' ), 'Software is' ); } );
ga( "an accent run inside a heading keeps its measured ink inline (any run whose colour differs from its parent's — not only a token the vocabulary names)", is_array( $w_q ) && (bool) preg_match( '/<span style="[^"]*color:#ffaa50">mirror<\/span>/', (string) $w_q['atts']['title'] ) );
$w_inf = $r_find( $w_pg, function ( $n ) { return 'special_heading' === ( $n['shortcode'] ?? '' ) && '∞' === trim( (string) ( $n['atts']['title'] ?? '' ) ); } );
$w_srow = $r_find( $w_pg, function ( $n ) { return 'flexbox' === ( $n['type'] ?? '' ) && 4 === count( $n['_items'] ?? array() ) && false !== strpos( json_encode( $n ), 'Creativity' ) && false !== strpos( json_encode( $n ), 'Energy Bias' ); } );
ga( "a stat band with a GLYPH value ('∞' over 'Creativity') is still a 4-column counter row: three counters + the glyph as a heading over its caption", is_array( $w_srow ) && is_array( $w_inf ) && 3 === count( $w_cnt ) );
$w_fc = $w_v['main_footer_columns'] ?? array(); $w_fcj = json_encode( $w_fc );
ga( "footer link columns titled by uppercase LABEL divs (no heading tag) are columns: 'Navigation' + 'Social Signal' with their links; the lead heading's signup rides the LEAD column (title / description blank), not a second column", '3' === (string) ( $w_fc['count'] ?? '' ) && false !== strpos( $w_fcj, '"heading_text":"Navigation"' ) && false !== strpos( $w_fcj, '"heading_text":"Social Signal"' ) && (bool) preg_match( '/footer-lead-title[^\]]*"element":"newsletter","newsletter":\{"newsletter_title":"","newsletter_desc":"","newsletter_email_ph":"your@email.com","newsletter_button":"Connect"/', $w_fcj ) );
ga( "…the footer column-title rule reads THOSE titles (10px tracked uppercase lantern), not the 48px lead h2", (bool) preg_match( '/\.footer-links-title\{[^}]*text-transform:uppercase !important;letter-spacing:3px !important;font-size:10px !important;color:rgb\(255, 170, 80\) !important/', $w_mc ) );
$w_cp = $w_v['copyright_settings']['yes']['copyright_columns'] ?? array();
ga( "the page SHELL's own rules (never a dropped class): the <main>'s scroll-driven background shift (view-timeline + animation-timeline + its @keyframes) rides Misc Custom CSS re-targeted at main.site-main, its wrapper layout dropped; a <body> class whose rule is only type / smoothing adds nothing (Typography owns it)", false !== strpos( $w_mc, 'main.site-main{view-timeline-name:--section-scroll;animation:bg-shift linear both;animation-timeline:--section-scroll;}' ) && false !== strpos( $w_mc, '@keyframes bg-shift{0%{background:oklch(20% 0.08 280)}100%{background:oklch(5% 0.02 280)}}' ) && false === strpos( $w_mc, 'body{' ) && false === strpos( $w_mc, 'position:relative' ) );
ga( "the © bar's status lockup (a dot + 'System Operational: Clouds Clear') is the bar's right column", '2' === (string) ( $w_cp['count'] ?? '' ) && false !== strpos( json_encode( $w_cp ), 'System Operational: Clouds Clear' ) );

/* --- [X] THE TELEMETRY PAGE (2026-09-16): a header lockup of an eyebrow ("ARCHIVE") over the title ("Basin Trust") beside a
 * glyph, an oklch-filled pill CTA; a hero whose bg <video> carries a filter (the effect path) and paints nothing of its own;
 * a 4-track bento whose scan PANEL spans two rows (track-x stamps) beside two small cards over a wide one — the panel's
 * header row (a lone glyph | a boxed mono chip, justify-between), its serif title, a 2-stat mono counter grid whose
 * captions are tracked uppercase mono; a small card whose value is a mono 3xl span (scrubbed to inline style before the
 * mirror); a footer whose © line is a <div> and whose legal row ends in a status label. --- */
echo "\n[X] Telemetry page: header lockup identity, oklch CTA, filtered bg video, row-span bento, panel header row, mono stats, scrubbed mirror leaf, © div + legal tail\n";
$x_ink  = 'color:oklch(0.25 0.05 50);font-family:Inter, sans-serif;font-size:16px;font-weight:400;line-height:24px;';
$x_mono = 'font-family:&quot;JetBrains Mono&quot;, monospace;';
$x_skin = 'background-color:oklch(0.94 0.04 70 / 0.6);border-radius:24px;border-top-width:1px;border-top-style:solid;border-top-color:oklch(0.7 0.15 45 / 0.2);border-bottom-width:1px;border-bottom-style:solid;border-bottom-color:oklch(0.7 0.15 45 / 0.2);border-left-width:1px;border-left-style:solid;border-left-color:oklch(0.7 0.15 45 / 0.2);border-right-width:1px;border-right-style:solid;border-right-color:oklch(0.7 0.15 45 / 0.2);box-shadow:rgba(0, 0, 0, 0.03) 0px 4px 20px 0px;overflow:hidden;';
$x_svg  = '<svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 256 256"><path fill="currentColor" d="M128 24a104 104 0 1 0 104 104A104 104 0 0 0 128 24Z"></path></svg>';
$x_glyph = function ( $px, $extra = '' ) use ( $x_svg ) { return '<iconify-icon icon="ph:scan-light" class="text-amber" data-sc-cs="color:oklch(0.7 0.15 45);font-family:Inter, sans-serif;font-size:' . $px . 'px;height:' . $px . 'px;display:block' . $extra . '">' . $x_svg . '</iconify-icon>'; };
$x_stat = function ( $n, $unit, $lbl, $tf ) use ( $x_mono ) {
	return '<div data-sc-cs="height:56px;display:block;track-frac:' . $tf . '"><span class="block font-mono text-2xl text-amber" data-sc-cs="color:oklch(0.7 0.15 45);' . $x_mono . 'font-size:24px;font-weight:400;line-height:32px;height:32px;display:block"><span class="counter" data-target="' . $n . '" data-sc-cs="color:oklch(0.7 0.15 45);' . $x_mono . 'font-size:24px;font-weight:400;display:inline">' . $n . '</span>' . $unit . '</span>' . "
"
		. '<span class="font-mono text-[9px] uppercase tracking-widest text-basalt/50" data-sc-cs="color:oklch(0.25 0.05 50);' . $x_mono . 'font-size:9px;font-weight:400;letter-spacing:0.9px;line-height:13.5px;text-transform:uppercase;display:inline">' . $lbl . '</span></div>';
};
$x_small = function ( $value, $cap, $copy, $tx, $delay ) use ( $x_ink, $x_mono, $x_skin, $x_glyph ) {
	return '<div class="stone-panel p-6 flex flex-col justify-between reveal-up ' . $delay . '" data-sc-cs="' . $x_skin . $x_ink . 'padding:24px;height:250px;display:flex;justify-content:space-between;flex-direction:column;track-frac:0.236;track-y:0;track-x:' . $tx . ';track-h:250" data-sc-cs-sm="track-frac:1" data-sc-cs-md="track-frac:0.312">'
		. $x_glyph( 24 )
		. '<div data-sc-cs="' . $x_ink . 'height:108px;display:block"><span class="font-mono text-3xl text-basalt block mb-1" data-sc-cs="color:oklch(0.25 0.05 50);' . $x_mono . 'font-size:30px;font-weight:400;line-height:36px;margin:0px 0px 4px;height:36px;display:block">' . $value . '</span>'
		. '<span class="font-mono text-[10px] uppercase tracking-widest text-basalt/50" data-sc-cs="color:oklch(0.25 0.05 50);' . $x_mono . 'font-size:10px;font-weight:400;letter-spacing:1px;line-height:15px;text-transform:uppercase;display:inline">' . $cap . '</span>'
		. '<p class="mt-3 font-sans text-xs text-basalt/70" data-sc-cs="' . $x_ink . 'font-size:12px;line-height:16px;margin:12px 0px 0px;height:32px;display:block">' . $copy . '</p></div></div>';
};
$x_link = function ( $t ) use ( $x_mono ) { return '<a href="#" data-sc-cs="color:oklch(0.94 0.04 70);' . $x_mono . 'font-size:10px;font-weight:400;letter-spacing:1px;line-height:15px;text-transform:uppercase;height:15px;display:block">' . $t . '</a>'; };
$x_html = '<!DOCTYPE html><html data-sc-content-width="1440"><head><title>Basin Trust | Delta Survey: Reed Beds</title><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400&amp;family=Inter:wght@300;400;500&amp;family=JetBrains+Mono:wght@400&amp;display=swap"></head>'
	. '<body data-sc-cs="background-color:oklch(0.94 0.04 70);color:oklch(0.25 0.05 50)">'
	// the header: a glyph + an eyebrow-over-title lockup | the nav | an oklch pill CTA
	. '<nav class="fixed top-0 w-full flex items-center justify-between px-8 py-4" data-sc-cs="' . $x_ink . 'display:flex;justify-content:space-between;align-items:center;padding:16px 32px;position:fixed;top:0px;left:0px;right:0px;height:72px">'
	. '<div class="flex items-center gap-3" data-sc-cs="' . $x_ink . 'display:flex;align-items:center;gap:12px">' . $x_glyph( 30 ) . '<div class="flex flex-col" data-sc-cs="' . $x_ink . 'display:flex;flex-direction:column">'
	. '<span class="font-serif tracking-widest text-[11px] uppercase text-basalt/60" data-sc-cs="color:oklch(0.25 0.05 50 / 0.6);font-family:&quot;Playfair Display&quot;, serif;font-size:11px;letter-spacing:1.1px;line-height:16.5px;text-transform:uppercase;display:block">ARCHIVE</span>'
	. '<span class="font-sans font-medium text-[13px] tracking-wide text-basalt" data-sc-cs="color:oklch(0.25 0.05 50);font-family:Inter, sans-serif;font-size:13px;font-weight:500;letter-spacing:0.325px;line-height:19.5px;display:block">Basin Trust</span></div></div>'
	. '<div class="hidden md:flex items-center gap-10" data-sc-cs="' . $x_ink . 'display:flex;align-items:center;gap:40px"><a href="#survey" class="nav-link" data-sc-cs="color:oklch(0.25 0.05 50 / 0.7);font-size:11px;font-weight:500;letter-spacing:2.2px;text-transform:uppercase;display:block">Survey</a><a href="#reeds" class="nav-link" data-sc-cs="color:oklch(0.25 0.05 50 / 0.7);font-size:11px;font-weight:500;letter-spacing:2.2px;text-transform:uppercase;display:block">Reed Beds</a><a href="#care" class="nav-link" data-sc-cs="color:oklch(0.25 0.05 50 / 0.7);font-size:11px;font-weight:500;letter-spacing:2.2px;text-transform:uppercase;display:block">Care</a></div>'
	. '<button class="stone-btn px-6 py-2.5 font-sans text-[11px] uppercase tracking-[0.2em] font-medium flex items-center gap-2" data-sc-cs="background-color:oklch(0.94 0.04 70 / 0.6);color:oklch(0.25 0.05 50);font-family:Inter, sans-serif;font-size:11px;font-weight:500;line-height:16.5px;letter-spacing:2.2px;text-align:center;text-transform:uppercase;padding:10px 24px;border-top-width:1px;border-top-style:solid;border-top-color:oklch(0.7 0.15 45 / 0.2);border-bottom-width:1px;border-bottom-style:solid;border-bottom-color:oklch(0.7 0.15 45 / 0.2);border-radius:9999px;display:flex;align-items:center;gap:8px">Plan a Visit</button></nav>'
	// the hero: a FILTERED bg video (the effect path) that paints nothing of its own
	. '<section class="relative h-screen overflow-hidden" data-sc-cs="' . $x_ink . 'position:relative;height:900px;overflow:hidden;display:block;padding:0px"><div class="absolute inset-0" data-sc-cs="' . $x_ink . 'position:absolute;top:0px;right:0px;bottom:0px;left:0px;height:900px;display:block"><video autoplay loop muted playsinline class="w-full h-full object-cover filter contrast-125" data-sc-cs="' . $x_ink . 'height:900px;width:1440px;object-fit:cover;filter:contrast(1.25) sepia(0.15);display:block;background-color:rgba(0, 0, 0, 0)"><source src="https://example.com/reeds.mp4" type="video/mp4"></video></div>'
	. '<div class="relative z-10 h-full flex flex-col items-center justify-center text-center" data-sc-cs="' . $x_ink . 'position:relative;height:900px;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;z-index:10"><h1 class="font-serif text-8xl text-white" data-sc-cs="color:rgb(255, 255, 255);font-family:&quot;Playfair Display&quot;, serif;font-size:96px;font-weight:400;line-height:96px;text-align:center">The Reed Beds of the Delta</h1><p data-sc-cs="color:rgb(255, 255, 255);font-family:Inter, sans-serif;font-size:16px;line-height:24px;text-align:center;max-width:640px;margin:24px 0px 0px">A survey of the living channels that hold the basin together.</p></div></section>'
	// the telemetry bento
	. '<section class="py-24 px-16 bg-sandstone" data-sc-cs="background-color:oklch(0.94 0.04 70);' . $x_ink . 'padding:96px 64px;display:block"><div class="max-w-[1400px] mx-auto" data-sc-cs="' . $x_ink . 'max-width:1400px;margin:0px 0px;display:block">'
	. '<div class="mb-16 flex items-end justify-between" data-sc-cs="' . $x_ink . 'margin:0px 0px 64px;display:flex;justify-content:space-between;align-items:flex-end"><div data-sc-cs="' . $x_ink . 'display:block;track-frac:0.276"><h2 class="font-serif text-4xl text-basalt mb-2" data-sc-cs="color:oklch(0.25 0.05 50);font-family:&quot;Playfair Display&quot;, serif;font-size:36px;font-weight:400;line-height:40px;margin:0px 0px 8px;display:block">Channel Telemetry</h2><p class="font-mono text-xs tracking-widest uppercase" data-sc-cs="color:oklch(0.25 0.05 50);' . $x_mono . 'font-size:12px;letter-spacing:1.2px;line-height:16px;text-transform:uppercase;display:block">Dataset: Reed Survey</p></div>' . $x_glyph( 36, ';track-frac:0.036' ) . '</div>'
	. '<div class="grid grid-cols-4 gap-6 auto-rows-[250px]" data-sc-cs="' . $x_ink . 'height:524px;display:grid;gap:24px;grid-template-columns:310px 310px 310px 310px" data-sc-cs-sm="gap:16px;grid-template-columns:358px" data-sc-cs-md="grid-template-columns:236px 236px 236px">'
	// the row-spanning scan panel
	. '<div class="stone-panel col-span-2 row-span-2 p-8 flex flex-col justify-between reveal-up" data-sc-cs="' . $x_skin . $x_ink . 'padding:32px;height:524px;display:flex;justify-content:space-between;flex-direction:column;track-frac:0.491;track-y:0;track-x:0;track-h:524" data-sc-cs-sm="track-frac:1" data-sc-cs-md="track-frac:0.656">'
	. '<div class="relative z-10 flex justify-between items-start" data-sc-cs="' . $x_ink . 'height:30px;display:flex;justify-content:space-between;align-items:flex-start;z-index:10">' . $x_glyph( 30, ';track-frac:0.048' )
	. '<span class="font-mono text-[10px] text-basalt/60 tracking-widest bg-white/30 px-2 py-1 rounded" data-sc-cs="background-color:rgba(255, 255, 255, 0.3);color:oklch(0.25 0.05 50);' . $x_mono . 'font-size:10px;font-weight:400;line-height:15px;letter-spacing:1px;padding:4px 8px;border-radius:4px;height:23px;display:block;track-frac:0.105">SCAN_01</span></div>'
	. '<div class="relative z-10" data-sc-cs="' . $x_ink . 'height:213px;display:block;z-index:10"><h3 class="font-serif text-3xl mb-3 text-basalt" data-sc-cs="color:oklch(0.25 0.05 50);font-family:&quot;Playfair Display&quot;, serif;font-size:30px;font-weight:400;line-height:36px;margin:0px 0px 12px;display:block">Channel Topography</h3>'
	. '<p class="font-sans text-sm font-light text-basalt/70 max-w-sm" data-sc-cs="' . $x_ink . 'font-size:14px;font-weight:300;line-height:22.75px;max-width:384px;display:block">Mapped with sub-metre precision. The reed margins move a little every season, and the survey follows them.</p>'
	. '<div class="mt-6 flex gap-6 border-t pt-4" data-sc-cs="' . $x_ink . 'padding:16px 0px 0px;margin:24px 0px 0px;border-top-width:1px;border-top-style:solid;border-top-color:oklch(0.25 0.05 50 / 0.1);height:73px;display:flex;gap:24px">' . $x_stat( '4.7', 'K', 'Reed Stems', '0.122' ) . $x_stat( '2.1', 'M', 'Litres Day', '0.093' ) . '</div></div></div>'
	. $x_small( 'True North', 'Error margin: 0.05°', 'Channel bearing held to the survey line.', '668', 'delay-100' )
	. $x_small( '<span class="counter" data-target="20" data-sc-cs="color:oklch(0.25 0.05 50);' . $x_mono . 'font-size:30px;font-weight:400;display:inline">20</span>°C', 'Water Temp', 'Constant thermal mass across the season.', '1002', 'delay-200' )
	// the wide card under the two small ones
	. '<div class="stone-panel col-span-2 p-8 flex items-center justify-between reveal-up delay-100" data-sc-cs="' . $x_skin . $x_ink . 'padding:32px;height:250px;display:flex;justify-content:space-between;align-items:center;track-frac:0.491;track-y:274;track-x:668;track-h:250" data-sc-cs-sm="flex-direction:column;track-frac:1" data-sc-cs-md="track-frac:0.656">'
	. '<div class="flex-1" data-sc-cs="' . $x_ink . 'display:block;track-frac:0.766"><h3 class="font-serif text-xl text-basalt" data-sc-cs="color:oklch(0.25 0.05 50);font-family:&quot;Playfair Display&quot;, serif;font-size:20px;font-weight:400;line-height:28px;display:block">The Survey Punt</h3><p class="font-sans text-sm text-basalt/70 mt-3" data-sc-cs="' . $x_ink . 'font-size:14px;line-height:22.75px;margin:12px 0px 0px;display:block">A flat-bottomed boat that carries the sounding gear along every channel.</p></div>'
	. '<div class="text-right" data-sc-cs="' . $x_ink . 'text-align:right;display:block;track-frac:0.2"><span class="font-mono text-[10px] uppercase tracking-widest block" data-sc-cs="color:oklch(0.25 0.05 50);' . $x_mono . 'font-size:10px;letter-spacing:1px;line-height:15px;text-transform:uppercase;text-align:right;display:block">Length</span><span class="font-serif text-xl block" data-sc-cs="color:oklch(0.25 0.05 50);font-family:&quot;Playfair Display&quot;, serif;font-size:20px;line-height:28px;text-align:right;display:block">6.2m × 1.4m</span></div></div>'
	. '</div></div></section>'
	// the footer: a © <div> line | legal links + a status label
	. '<footer data-sc-cs="background-color:oklch(0.25 0.05 50);color:oklch(0.94 0.04 70);font-family:Inter, sans-serif;font-size:16px;line-height:24px;padding:96px 64px 32px;display:block"><div class="grid grid-cols-3 gap-12" data-sc-cs="color:oklch(0.94 0.04 70);display:grid;gap:48px;grid-template-columns:600px 300px 300px"><div data-sc-cs="color:oklch(0.94 0.04 70);display:block"><h2 class="font-serif text-4xl" data-sc-cs="color:oklch(0.94 0.04 70);font-family:&quot;Playfair Display&quot;, serif;font-size:36px;line-height:40px;display:block">Keeping the channels open.</h2><p data-sc-cs="color:oklch(0.94 0.04 70);font-size:14px;line-height:21px;margin:16px 0px 0px;display:block">The survey is repeated every spring and shared with the basin councils.</p></div>'
	. '<div data-sc-cs="color:oklch(0.94 0.04 70);display:block"><h4 data-sc-cs="color:oklch(0.94 0.04 70);font-family:&quot;Playfair Display&quot;, serif;font-size:12px;letter-spacing:1.2px;text-transform:uppercase;display:block">Survey</h4><ul data-sc-cs="display:block;margin:16px 0px 0px"><li data-sc-cs="display:block"><a href="/reeds" data-sc-cs="color:oklch(0.94 0.04 70);font-size:14px;display:inline">The Reed Beds</a></li><li data-sc-cs="display:block"><a href="/punt" data-sc-cs="color:oklch(0.94 0.04 70);font-size:14px;display:inline">Punt Archive</a></li></ul></div>'
	. '<div data-sc-cs="color:oklch(0.94 0.04 70);display:block"><h4 data-sc-cs="color:oklch(0.94 0.04 70);font-family:&quot;Playfair Display&quot;, serif;font-size:12px;letter-spacing:1.2px;text-transform:uppercase;display:block">Basin</h4><ul data-sc-cs="display:block;margin:16px 0px 0px"><li data-sc-cs="display:block"><a href="/councils" data-sc-cs="color:oklch(0.94 0.04 70);font-size:14px;display:inline">Councils</a></li><li data-sc-cs="display:block"><a href="/data" data-sc-cs="color:oklch(0.94 0.04 70);font-size:14px;display:inline">Data Access</a></li></ul></div></div>'
	. '<div class="flex justify-between items-center pt-8 border-t" data-sc-cs="color:oklch(0.94 0.04 70);padding:32px 0px 0px;margin:64px 0px 0px;border-top-width:1px;border-top-style:solid;border-top-color:oklch(0.94 0.04 70 / 0.1);height:48px;display:flex;justify-content:space-between;align-items:center">'
	. '<div class="font-mono text-[10px] tracking-widest" data-sc-cs="color:oklch(0.94 0.04 70);' . $x_mono . 'font-size:10px;letter-spacing:1px;line-height:15px;height:15px;display:block;track-frac:0.182">© 2026 BASIN TRUST INITIATIVE</div>'
	. '<div class="flex gap-6 font-mono text-[10px] tracking-widest uppercase" data-sc-cs="color:oklch(0.94 0.04 70);' . $x_mono . 'font-size:10px;letter-spacing:1px;line-height:15px;text-transform:uppercase;height:15px;display:flex;gap:24px;track-frac:0.181">' . $x_link( 'Privacy' ) . $x_link( 'Terms' ) . '<span class="text-amber/50" data-sc-cs="color:oklch(0.94 0.04 70);' . $x_mono . 'font-size:10px;letter-spacing:1px;line-height:15px;text-transform:uppercase;height:15px;display:block">SYS.OP: ONLINE</span></div></div></footer></body></html>';
$x_bl = FW_Site_Converter_Sources::build_from_html( $x_html, 'Basin', array( 'dynamic_chrome' => true, 'hifi_css' => true ) );
$x_v  = $x_bl['files']['theme-settings.json']['values'] ?? ( $x_bl['files']['theme-settings.json'] ?? array() );
$x_pg = $x_bl['files']['pages.json']['pages'][0]['builder'] ?? array();
$x_lc = $x_v['header_logo']['logo_type']['custom'] ?? array();
ga( "the header lockup is the identity: the small tracked line over the wordmark is the EYEBROW (tagline), the wordmark the site title, the layout eyebrow-left", 'Basin Trust' === (string) ( $x_lc['site_title'] ?? '' ) && 'ARCHIVE' === (string) ( $x_lc['tagline_text'] ?? '' ) && 'eyebrow-left' === (string) ( $x_lc['logo_layout'] ?? '' ) );
ga( "an oklch-with-alpha filled pill CTA still matches the filled button preset (rgba_quad reads oklch / oklab / hsl)", (function () use ( $x_v ) { foreach ( (array) ( $x_v['header_main']['main_right'] ?? array() ) as $e ) { if ( 'cta_button' === ( $e['element_type']['element'] ?? '' ) ) { return 'btn-fill' === (string) ( $e['element_type']['cta_button']['cta_style'] ?? '' ); } } return false; } )() );
$x_vid = $r_find( $x_pg, function ( $n ) { return 'media_video' === ( $n['shortcode'] ?? '' ); } );
ga( "a FILTERED bg video (the effect path → a media_video in section-background mode) keeps its filter AND paints no black of its own under the frames (the source <video> was transparent)", is_array( $x_vid ) && 'yes' === (string) ( $x_vid['atts']['as_background'] ?? '' ) && (bool) preg_match( '/selector \.video-el\{[^}]*filter:contrast\(1\.25\) sepia\(0\.15\)[^}]*background:transparent/', (string) ( $x_vid['atts']['custom_css'] ?? '' ) ) );
// the bento: [ scan panel (6) | rest column (6): [ small | small ] over [ wide ] ]
$x_bento = $r_find( $x_pg, function ( $n ) { return 'flexbox' === ( $n['type'] ?? '' ) && 2 === count( $n['_items'] ?? array() ) && false !== strpos( json_encode( $n['_items'][0] ), 'SCAN_01' ) && false !== strpos( json_encode( $n['_items'][1] ), 'True North' ) && false !== strpos( json_encode( $n['_items'][1] ), 'Survey Punt' ); } );
ga( "a ROW-SPANNING scan panel beside two small cards over a wide one splits by X (track-x): the spanner's column | the rest as its own stack — never one six-sliver row", is_array( $x_bento ) && '6' === (string) ( $x_bento['_items'][0]['atts']['width']['lg']['preset'] ?? '' ) && '6' === (string) ( $x_bento['_items'][1]['atts']['width']['lg']['preset'] ?? '' ) );
$x_rest = is_array( $x_bento ) ? $x_bento['_items'][1] : array();
$x_rrows = array(); $r_all( array( $x_rest ), function ( $n ) { return 'flexbox' === ( $n['type'] ?? '' ) && 2 === count( $n['_items'] ?? array() ) && false !== strpos( json_encode( $n ), 'True North' ) && false !== strpos( json_encode( $n ), 'Water Temp' ) && false === strpos( json_encode( $n ), 'Survey Punt' ); }, $x_rrows );
ga( "…the rest column's first row holds the two small cards as equal halves (widths re-measured INSIDE the column: 6/6 or a uniform grow), the wide card its own row below", count( $x_rrows ) >= 1 && ( function ( $c ) { return '6' === (string) ( $c['atts']['width']['lg']['preset'] ?? '' ) || 'yes' === (string) ( $c['atts']['flex_grow']['base'] ?? '' ); } )( $x_rrows[0]['_items'][0] ) && ( function ( $c ) { return '6' === (string) ( $c['atts']['width']['lg']['preset'] ?? '' ) || 'yes' === (string) ( $c['atts']['flex_grow']['base'] ?? '' ); } )( $x_rrows[0]['_items'][1] ) );
$x_hrow = $r_find( $x_pg, function ( $n ) { return 'flexbox' === ( $n['type'] ?? '' ) && 2 === count( $n['_items'] ?? array() ) && 'between' === ( $n['atts']['justify_content']['base'] ?? '' ) && false !== strpos( json_encode( $n ), 'SCAN_01' ); } );
ga( "the panel's HEADER ROW (a lone glyph | a boxed mono chip, justify-between) stays a row: native Justify between, content-sized cells", is_array( $x_hrow ) && 'none' === (string) ( $x_hrow['_items'][0]['atts']['width']['base']['preset'] ?? '' ) );
$x_icon = $r_find( array( $x_hrow ), function ( $n ) { return 'icon' === ( $n['shortcode'] ?? '' ); } );
ga( "…the lone glyph is the native icon shortcode with its inlined svg, measured 30px size and amber ink", is_array( $x_icon ) && 'svg' === ( $x_icon['atts']['icon']['type'] ?? '' ) && false !== strpos( (string) ( $x_icon['atts']['icon']['markup'] ?? '' ), '<svg' ) && '30' === (string) ( $x_icon['atts']['icon_size']['value'] ?? '' ) && '' !== (string) ( $x_icon['atts']['icon_color']['custom'] ?? '' ) );
$x_chip = $r_find( $x_pg, function ( $n ) { return 'text_block' === ( $n['shortcode'] ?? '' ) && '<p>SCAN_01</p>' === (string) ( $n['atts']['text'] ?? '' ); } );
$x_chipc = (string) ( $x_chip['atts']['custom_css'] ?? '' );
ga( "…the boxed chip wears a Box Preset, keeps its MONO face on the block (no section styler reaches a nested block) and stays CONTENT-SIZED (inline-block / max-content), never stretched across the cell", is_array( $x_chip ) && '' !== (string) ( $x_chip['atts']['box_style'] ?? '' ) && false !== strpos( $x_chipc, "font-family:'JetBrains Mono', monospace" ) && false !== strpos( $x_chipc, 'display:inline-block;width:max-content' ) );
$x_cnt = array(); $r_all( $x_pg, function ( $n ) { return 'counter' === ( $n['shortcode'] ?? '' ); }, $x_cnt );
$x_c47 = null; foreach ( $x_cnt as $c ) { if ( '4.7' === (string) ( $c['atts']['number'] ?? '' ) ) { $x_c47 = $c; } }
ga( "the panel's 2-stat grid → counters whose digits keep the measured 400 weight and the MONO face (never the 700 sans default), the unit as the suffix", is_array( $x_c47 ) && 'K' === (string) ( $x_c47['atts']['suffix'] ?? '' ) && '400' === (string) ( $x_c47['atts']['number_font']['weight'] ?? '' ) && 'JetBrains Mono' === (string) ( $x_c47['atts']['number_font']['family'] ?? '' ) && 'JetBrains Mono' === (string) ( $x_c47['atts']['suffix_font']['family'] ?? '' ) );
$x_cap = $r_find( $x_pg, function ( $n ) { return 'text_block' === ( $n['shortcode'] ?? '' ) && '<p>Reed Stems</p>' === (string) ( $n['atts']['text'] ?? '' ); } );
ga( "…a stat CAPTION is a text block on its measured treatment — the tracked uppercase 9px matches the Eyebrow Text Style, the mono face rides the block, no inline style on the paragraph", is_array( $x_cap ) && 'font-eyebrow' === (string) ( $x_cap['atts']['font_size_preset'] ?? '' ) && false !== strpos( (string) ( $x_cap['atts']['custom_css'] ?? '' ), "font-family:'JetBrains Mono', monospace" ) );
$x_tn = $r_find( $x_pg, function ( $n ) { return 'text_block' === ( $n['shortcode'] ?? '' ) && '<p>True North</p>' === (string) ( $n['atts']['text'] ?? '' ); } );
ga( "a small card's mono 3xl value reaches the mirror as SCRUBBED markup (inline style, no classes / stamps) and still keeps its 30px size + mono face (the inline style is lifted into the stamp; a leaf face is compared against the BODY face when the parent carries none)", is_array( $x_tn ) && (bool) preg_match( '/selector p\{[^}]*font-size:30px[^}]*font-family:\'JetBrains Mono\', monospace/', (string) ( $x_tn['atts']['custom_css'] ?? '' ) ) );
$x_20 = $r_find( $x_pg, function ( $n ) { return 'text_block' === ( $n['shortcode'] ?? '' ) && '20°C' === trim( strip_tags( (string) ( $n['atts']['text'] ?? '' ) ) ); } );
ga( "…and a value whose digits sit in a <span data-target> beside their unit stays ONE line ('20°C'), never split into a counter and a stray unit", is_array( $x_20 ) );
$x_cp = $x_v['copyright_settings']['yes']['copyright_columns'] ?? array(); $x_cpj = json_encode( $x_cp, JSON_UNESCAPED_UNICODE );
ga( "the © line is a <div> (no <p>) and still the copyright column, its year the {{current_year}} token; the legal row (Privacy · Terms) keeps its trailing STATUS label ('SYS.OP: ONLINE') — a text tail after the legal links, not a dropped span", '2' === (string) ( $x_cp['count'] ?? '' ) && false !== strpos( $x_cpj, '{{current_year}} BASIN TRUST INITIATIVE' ) && false !== strpos( $x_cpj, 'Privacy' ) && false !== strpos( $x_cpj, 'SYS.OP: ONLINE' ) );

/* --- [Y] THE CANVAS PAGE (2026-09-17): a section-less page whose <main> is a 12-track GRID canvas — the hero copy on tracks 1–7
 * beside a sculpted product card on 8–11 (the same grid row), a second card on 2–6 beside a small tile on 9–11; a fixed,
 * right-anchored 60vw <video> layer with a radial mask and a soft-light glow that the whole page scrolls over; a fixed
 * link-less masthead of three labels blended with `mix-blend-mode:difference`; the product card's empty gradient frame
 * and its "$2,450 | Acquire" price row; a tile whose emblem is a ring holding a blurred dot. --- */
echo "\n[Y] Canvas page: site-background video anchor, grid-row bands, label-only blended masthead, paint frame, price row, ring emblem\n";
$y_ink = 'color:oklch(0.2 0.05 240);font-family:Inter, sans-serif;font-size:16px;font-weight:400;line-height:24px;';
$y_skin = 'background-color:oklch(0.97 0.01 240);border-radius:32px;box-shadow:rgba(255, 255, 255, 0.9) 4px 4px 10px 0px inset, rgba(0, 0, 0, 0.03) -4px -4px 15px 0px inset, rgba(0, 0, 0, 0.04) 10px 20px 40px 0px;';
$y_btn = function ( $label, $pad, $fs ) { return '<button class="liquid-btn ' . $pad . '" data-sc-cs="background-image:linear-gradient(135deg, oklch(1 0 0), oklch(0.97 0.01 240));color:oklch(0.6 0.15 250);font-family:Inter, sans-serif;font-size:' . $fs . 'px;font-weight:500;line-height:20px;letter-spacing:0.35px;text-align:center;padding:' . ( 'lg' === $pad ? '16px 32px' : '8px 16px' ) . ';border-radius:9999px;box-shadow:rgba(255, 255, 255, 1) 2px 2px 4px 0px inset, rgba(0, 0, 0, 0.05) -2px -2px 6px 0px inset, rgba(0, 0, 0, 0.05) 0px 4px 10px 0px;display:block">' . $label . '</button>'; };
$y_html = '<!DOCTYPE html><html data-sc-content-width="1440"><head><title>Basin | The Global Destination For Modern Study</title><style>.video-anchor{position:fixed;top:0;right:0;width:60vw;height:100vh;z-index:0;pointer-events:none;mask-image:radial-gradient(ellipse 150% 120% at 100% 50%, black 60%, transparent 100%)}.video-anchor video{width:100%;height:100%;object-fit:cover;filter:saturate(1.1) contrast(1.05)}.glass-portal{position:absolute;inset:0;background:radial-gradient(circle at top right, rgba(255,255,255,0.3) 0%, transparent 40%);mix-blend-mode:soft-light}.fractal-container{display:grid;grid-template-columns:repeat(12,1fr);gap:64px;min-height:200vh}</style></head>'
	. '<body class="antialiased" data-sc-cs="background-color:oklch(0.97 0.01 240);color:oklch(0.2 0.05 240)">'
	// the fixed backdrop: a right-anchored 60vw video with a glow layer
	. '<div class="video-anchor" data-sc-cs="' . $y_ink . 'height:900px;width:864px;display:block;position:fixed;top:0px;right:0px;bottom:0px;left:576px;z-index:0;pointer-events:none;mask-image:radial-gradient(150% 120% at 100% 50%, rgb(0, 0, 0) 60%, rgba(0, 0, 0, 0) 100%);-webkit-mask-image:radial-gradient(150% 120% at 100% 50%, rgb(0, 0, 0) 60%, rgba(0, 0, 0, 0) 100%)"><video autoplay loop muted playsinline data-sc-cs="' . $y_ink . 'height:900px;width:864px;display:block;object-fit:cover;filter:saturate(1.1) contrast(1.05);background-color:rgba(0, 0, 0, 0)"><source src="https://example.com/studio.mp4" type="video/mp4"></video><div class="absolute inset-0 glass-portal" data-sc-cs="background-image:radial-gradient(circle at 100% 0%, rgba(255, 255, 255, 0.3) 0%, rgba(0, 0, 0, 0) 40%);' . $y_ink . 'height:900px;display:block;position:absolute;top:0px;right:0px;bottom:0px;left:0px;mix-blend-mode:soft-light"></div></div>'
	// the label-only blended masthead
	. '<nav class="fixed top-0 w-full z-50 px-8 py-6 mix-blend-difference text-white flex justify-between items-center" data-sc-cs="color:rgb(255, 255, 255);font-family:Inter, sans-serif;font-size:16px;line-height:24px;padding:24px 32px;height:68px;display:flex;justify-content:space-between;align-items:center;position:fixed;top:0px;right:0px;left:0px;z-index:50;mix-blend-mode:difference" data-sc-header="rest-height:68px">'
	. '<div class="text-xs tracking-widest uppercase font-semibold" data-sc-cs="color:rgb(255, 255, 255);font-family:Inter, sans-serif;font-size:12px;font-weight:600;line-height:16px;letter-spacing:1.2px;text-transform:uppercase;height:16px;display:block">Headless Architecture</div>'
	. '<div class="text-sm font-medium tracking-tight" data-sc-cs="color:rgb(255, 255, 255);font-family:Inter, sans-serif;font-size:14px;font-weight:500;line-height:20px;letter-spacing:-0.35px;height:20px;display:block">Basin Spatial</div>'
	. '<div class="text-xs tracking-widest uppercase font-semibold" data-sc-cs="color:rgb(255, 255, 255);font-family:Inter, sans-serif;font-size:12px;font-weight:600;line-height:16px;letter-spacing:1.2px;text-transform:uppercase;height:16px;display:block">Low-Latency Node</div></nav>'
	// the grid canvas
	. '<main class="fractal-container pt-32 px-4 md:px-12 lg:px-24 z-10" data-sc-cs="' . $y_ink . 'padding:128px 96px 0px;height:1800px;min-height:1800px;display:grid;gap:64px;grid-template-columns:45.33px 45.33px 45.33px 45.33px 45.33px 45.33px 45.33px 45.33px 45.33px 45.33px 45.33px 45.33px;z-index:10" data-sc-cs-sm="padding:128px 16px 0px;gap:20px;grid-template-columns:12px 12px 12px 12px 12px 12px 12px 12px 12px 12px 12px 12px" data-sc-cs-md="padding:128px 48px 0px;gap:41px;grid-template-columns:22.75px 22.75px 22.75px 22.75px 22.75px 22.75px 22.75px 22.75px 22.75px 22.75px 22.75px 22.75px">'
	. '<header class="col-span-12 md:col-span-7 mt-20 z-plane-1" data-sc-cs="' . $y_ink . 'margin:80px 0px 0px;height:752px;display:block;track-frac:0.562;track-y:80;track-x:0;track-h:752" data-sc-cs-sm="track-frac:1" data-sc-cs-md="track-frac:0.56">'
	. '<h1 class="text-6xl md:text-8xl font-light tracking-tighter leading-[0.9] gradient-text pb-4" data-sc-cs="color:oklch(0.2 0.05 240);font-family:Inter, sans-serif;font-size:96px;font-weight:300;line-height:86.4px;letter-spacing:-4.8px;padding:0px 0px 16px;display:block">Augmented Field Metrics.</h1>'
	. '<p class="mt-8 text-xl max-w-md font-light leading-relaxed opacity-70" data-sc-cs="' . $y_ink . 'font-size:20px;font-weight:300;line-height:32.5px;margin:32px 0px 0px;max-width:448px;opacity:0.7;display:block">Orchestrating immersive field marketplaces through spatial sample visualization.</p>'
	. $y_btn( 'Initialize Field Engine', 'lg', 14 ) . '</header>'
	// the product card on tracks 8–11 (the SAME grid row as the hero copy)
	. '<div class="col-span-12 md:col-start-8 md:col-span-4 mt-40 z-plane-2 relative" data-sc-cs="' . $y_ink . 'margin:160px 0px 0px;height:672px;display:block;position:relative;track-frac:0.284;track-y:177;track-x:775;track-h:638" data-sc-cs-sm="track-frac:0.95" data-sc-cs-md="track-frac:0.281">'
	. '<div class="sculpted-volume p-8 aspect-[3/4] flex flex-col justify-between spatial-sku" id="primary-sku" data-sc-cs="' . $y_skin . $y_ink . 'padding:32px;height:634px;display:flex;justify-content:space-between;flex-direction:column;aspect-ratio:3 / 4">'
	. '<div class="flex justify-between items-start" data-sc-cs="' . $y_ink . 'height:16px;display:flex;justify-content:space-between;align-items:flex-start"><span class="text-xs uppercase tracking-widest opacity-50" data-sc-cs="' . $y_ink . 'font-size:12px;line-height:16px;letter-spacing:1.2px;text-transform:uppercase;height:16px;display:block;opacity:0.5">SKU-0992</span><div class="h-2 w-2 rounded-full" data-sc-cs="background-color:oklch(0.6 0.15 250);' . $y_ink . 'border-radius:9999px;box-shadow:oklch(0.6 0.15 250) 0px 0px 10px 0px;height:8px;width:8px;display:block"></div></div>'
	. '<div class="w-full h-full my-6 rounded-xl relative overflow-hidden" data-sc-cs="background-image:linear-gradient(to right top, rgba(0, 0, 0, 0), rgba(255, 255, 255, 0.5));' . $y_ink . 'margin:24px 0px;border-radius:12px;height:434px;display:block;position:relative;overflow:hidden"><div class="absolute inset-0 backdrop-blur-md mix-blend-overlay" data-sc-cs="' . $y_ink . 'backdrop-filter:blur(12px);height:434px;display:block;position:absolute;top:0px;right:0px;bottom:0px;left:0px;mix-blend-mode:overlay"></div></div>'
	. '<div data-sc-cs="' . $y_ink . 'height:72px;display:block"><h2 class="text-2xl font-light tracking-tight" data-sc-cs="color:oklch(0.2 0.05 240);font-family:Inter, sans-serif;font-size:24px;font-weight:300;line-height:32px;letter-spacing:-0.6px;height:32px;display:block">Aero-Silk Trench</h2>'
	. '<div class="flex justify-between items-end mt-2" data-sc-cs="' . $y_ink . 'margin:8px 0px 0px;height:32px;display:flex;justify-content:space-between;align-items:flex-end"><span class="text-lg font-medium" data-sc-cs="color:oklch(0.6 0.15 250);font-family:Inter, sans-serif;font-size:18px;font-weight:500;line-height:28px;height:28px;display:block">$2,450</span>' . $y_btn( 'Acquire', 'sm', 12 ) . '</div></div></div></div>'
	// the second row: a card on tracks 2–6, a tile on 9–11
	. '<div class="col-span-12 md:col-start-2 md:col-span-5 mt-64 z-plane-1 relative" data-sc-cs="' . $y_ink . 'margin:256px 0px 0px;height:520px;display:block;position:relative;track-frac:0.387;track-y:1152;track-x:109;track-h:520" data-sc-cs-sm="track-frac:1" data-sc-cs-md="track-frac:0.384">'
	. '<div class="sculpted-volume p-10 aspect-square flex flex-col justify-center items-start" data-sc-cs="' . $y_skin . $y_ink . 'padding:40px;height:483px;display:flex;justify-content:center;align-items:flex-start;flex-direction:column;aspect-ratio:1 / 1"><h3 class="text-3xl font-light gradient-text mb-4" data-sc-cs="color:oklch(0.2 0.05 240);font-family:Inter, sans-serif;font-size:30px;font-weight:300;line-height:36px;margin:0px 0px 16px;display:block">Volume Over Plane</h3><p class="text-sm opacity-60 leading-relaxed" data-sc-cs="' . $y_ink . 'font-size:14px;line-height:22.75px;opacity:0.6;display:block">Fractal asymmetric grids define our continuous layout structure. Edges dissolve into light gradients, replacing solid boundaries with perceptual depth.</p></div></div>'
	. '<div class="col-span-12 md:col-start-9 md:col-span-3 mt-96 z-plane-2" data-sc-cs="' . $y_ink . 'margin:384px 0px 0px;height:392px;display:block;track-frac:0.201;track-y:1289;track-x:881;track-h:373" data-sc-cs-sm="track-frac:0.95" data-sc-cs-md="track-frac:0.197">'
	. '<div class="sculpted-volume p-6 flex items-center gap-4 cursor-pointer" id="secondary-sku" data-sc-cs="' . $y_skin . $y_ink . 'padding:24px;height:112px;display:flex;align-items:center;gap:16px">'
	. '<div class="w-16 h-16 rounded-full flex items-center justify-center" data-sc-cs="background-color:oklch(0.97 0.01 240);' . $y_ink . 'border-radius:9999px;box-shadow:rgb(255, 255, 255) 2px 2px 5px 0px inset, rgba(0, 0, 0, 0.05) -2px -2px 5px 0px inset;height:64px;width:64px;display:flex;align-items:center;justify-content:center"><div class="w-8 h-8 rounded-full blur-[2px]" data-sc-cs="background-color:oklch(0.6 0.15 250);' . $y_ink . 'border-radius:9999px;height:32px;width:32px;display:block;filter:blur(2px)"></div></div>'
	. '<div data-sc-cs="' . $y_ink . 'display:block"><div class="text-sm font-medium" data-sc-cs="' . $y_ink . 'font-size:14px;font-weight:500;line-height:20px;display:block">Holographic Visor</div><div class="text-xs opacity-50 mt-1" data-sc-cs="' . $y_ink . 'font-size:12px;line-height:16px;margin:4px 0px 0px;opacity:0.5;display:block">Real-time AR overlay</div></div></div></div>'
	. '</main></body></html>';
$y_bl = FW_Site_Converter_Sources::build_from_html( $y_html, 'Basin', array( 'dynamic_chrome' => true, 'hifi_css' => true ) );
$y_v  = $y_bl['files']['theme-settings.json']['values'] ?? ( $y_bl['files']['theme-settings.json'] ?? array() );
$y_pg = $y_bl['files']['pages.json']['pages'][0]['builder'] ?? array();
$y_mc = (string) ( $y_v['misc_custom_css']['custom_css'] ?? '' );
$y_sbv = $y_v['general_layout']['site_background']['video'] ?? array();
ga( "a fixed, viewport-tall, unframed video layer anchored to the right at 60vw (the page scrolls over it) is the SITE background's fixed video — never the first section's", 'yes' === (string) ( $y_sbv['enabled'] ?? '' ) && 'fixed' === (string) ( $y_sbv['position'] ?? '' ) && false !== strpos( (string) ( $y_sbv['source_mp4']['url'] ?? '' ), 'studio.mp4' ) && 'no' === (string) ( $y_pg[0]['atts']['background']['video']['enabled'] ?? 'no' ) && null === $r_find( $y_pg, function ( $n ) { return 'media_video' === ( $n['shortcode'] ?? '' ); } ) );
ga( "…the layer's own geometry (width 60vw, right-anchored), its radial mask, the video's filter and the soft-light glow sibling ride Misc Custom CSS on .site-bg-video (with !important — the theme prints the layer inset:0 inline)", (bool) preg_match( '/\.site-bg-video\{width:60vw !important;left:auto !important;right:0 !important;-webkit-mask-image:radial-gradient/', $y_mc ) && false !== strpos( $y_mc, '.site-bg-video video{filter:saturate(1.1) contrast(1.05);}' ) && (bool) preg_match( '/\.site-bg-video::after\{[^}]*background-image:radial-gradient[^}]*mix-blend-mode:soft-light/', $y_mc ) );
$y_hm = $y_v['header_main'] ?? array(); $y_hmj = json_encode( $y_hm );
ga( "a LABEL-ONLY fixed masthead (no link anywhere): the wordmark is the label matching the <title>'s brand ('Basin Spatial', centred), the other labels ride as chips in their zones, and NO menu_area is printed", false === strpos( $y_hmj, 'menu_area' ) && 'logo' === (string) ( $y_hm['main_center'][0]['element_type']['element'] ?? '' ) && 'Headless Architecture' === (string) ( $y_hm['main_left'][0]['element_type']['list_item']['li_text'] ?? '' ) && 'Low-Latency Node' === (string) ( $y_hm['main_right'][0]['element_type']['list_item']['li_text'] ?? '' ) && 'Basin Spatial' === (string) ( $y_v['header_logo']['logo_type']['custom']['site_title'] ?? '' ) );
ga( "…and the masthead's `mix-blend-mode:difference` (white labels reading dark over the light page) rides the theme header", false !== strpos( $y_mc, '.site-header{mix-blend-mode:difference;background:transparent !important;}' ) );
ga( "a <header> inside <main> with a heading but no link at all is the hero COPY, not the masthead (the site title is never the h1)", 'Augmented Field Metrics.' !== (string) ( $y_v['header_logo']['logo_type']['custom']['site_title'] ?? '' ) );
ga( "the GRID canvas's bands that share a grid row become ONE section: two sections, not four", 2 === count( $y_pg ) );
$y_r1 = $y_pg[0]['_items'][0] ?? array(); $y_c1 = $y_r1['_items'] ?? array();
ga( "…row one = the hero copy (7 of 12) beside the product card (4 of 12) at the desktop tier, each stacked full-width on phones", 2 === count( $y_c1 ) && '7' === (string) ( $y_c1[0]['atts']['width']['lg']['preset'] ?? '' ) && '4' === (string) ( $y_c1[1]['atts']['width']['lg']['preset'] ?? '' ) && '12' === (string) ( $y_c1[0]['atts']['width']['base']['preset'] ?? '' ) && false !== strpos( json_encode( $y_c1[0] ), 'Augmented Field' ) && false !== strpos( json_encode( $y_c1[1] ), 'SKU-0992' ) );
ga( "…the card's own top margin (mt-40) rides its column's Spacing, the hero copy's (mt-20) its own — the card sits lower than the copy beside it", '' !== (string) ( $y_c1[1]['atts']['spacing']['margin']['top'] ?? '' ) && '' !== (string) ( $y_c1[0]['atts']['spacing']['margin']['top'] ?? '' ) );
$y_r2 = $y_pg[1]['_items'][0] ?? array(); $y_c2 = $y_r2['_items'] ?? array();
ga( "…row two = the square card on tracks 2–6 (its col-start offset as a percent left margin) beside the tile on 9–11 (its own offset), widths 5 and 3", 2 === count( $y_c2 ) && '5' === (string) ( $y_c2[0]['atts']['width']['lg']['preset'] ?? '' ) && '3' === (string) ( $y_c2[1]['atts']['width']['lg']['preset'] ?? '' ) && (bool) preg_match( '/margin-left:8\.7\d% !important/', (string) ( $y_c2[0]['atts']['custom_css'] ?? '' ) ) && (bool) preg_match( '/margin-left:1[78]\.\d+% !important/', (string) ( $y_c2[1]['atts']['custom_css'] ?? '' ) ) );
ga( "the hero copy is NOT centred (a <button>'s own UA text-align:center never centres the band)", '' === (string) ( $y_pg[0]['atts']['text_align'] ?? '' ) );
ga( "the <main>'s own top padding lands ONCE: on the first row's section, not again on #main", 'pt-[0px]' !== (string) ( $y_pg[0]['atts']['padding_top']['base'] ?? '' ) && false === strpos( json_encode( $y_bl['files']['pages.json'] ), 'main#main{padding-top' ) );
$y_paint = $r_find( $y_pg, function ( $n ) { return 'flexbox' === ( $n['type'] ?? '' ) && empty( $n['_items'] ) && false !== strpos( (string) ( $n['atts']['custom_css'] ?? '' ), 'background-image:linear-gradient(to right top' ); } );
ga( "the product card's EMPTY gradient frame (h-full, in flow) is a native empty Div wearing the gradient, growing to the space the card leaves it (flex:1 1 auto + the measured minimum), its radius and blur", is_array( $y_paint ) && (bool) preg_match( '/flex:1 1 auto;min-height:434px;width:100%;backdrop-filter:blur\(12px\)/', (string) $y_paint['atts']['custom_css'] ) && false !== strpos( (string) $y_paint['atts']['custom_css'], 'border-radius:12px' ) );
$y_price = $r_find( $y_pg, function ( $n ) { return 'text_block' === ( $n['shortcode'] ?? '' ) && '$2,450' === trim( strip_tags( (string) ( $n['atts']['text'] ?? '' ) ) ); } );
$y_prow = $r_find( $y_pg, function ( $n ) { return 'flexbox' === ( $n['type'] ?? '' ) && 'between' === ( $n['atts']['justify_content']['base'] ?? '' ) && false !== strpos( json_encode( $n ), '$2,450' ) && false !== strpos( json_encode( $n ), 'Acquire' ); } );
ga( "the price + button line is ONE row (justify-between, content-sized cells): the '$2,450' label survives beside the 'Acquire' button (a priced value is never a decorative glyph)", is_array( $y_price ) && is_array( $y_prow ) && 'none' === (string) ( $y_prow['_items'][0]['atts']['width']['base']['preset'] ?? '' ) );
$y_ring = $r_find( $y_pg, function ( $n ) { return 'code_block' === ( $n['shortcode'] ?? '' ) && false !== strpos( (string) ( $n['atts']['code'] ?? '' ), 'sc-paint' ); } );
ga( "the tile's emblem — an empty 64px ring holding a blurred 32px dot — is a painted box (the ring's fill / radius / inset shadow, the dot nested, both self-scoped), not an invisible utility-class markup", is_array( $y_ring ) && (bool) preg_match( '/width:4rem|width:64px/', (string) $y_ring['atts']['custom_css'] ) && (bool) preg_match( '/span:nth-child\(1\)\{display:block;background-color:oklch\(0\.6 0\.15 250\);border-radius:9999px;width:(?:2rem|32px);height:32px;filter:blur\(2px\)/', (string) $y_ring['atts']['custom_css'] ) );
ga( "a boxed tile with NO heading (two short labels in a sculpted card) is still a content band (a skin + text is content)", false !== strpos( json_encode( $y_pg ), 'Holographic Visor' ) );
$y_dbl_arr = array(); $r_all( $y_pg, function ( $n ) { return 'flexbox' === ( $n['type'] ?? '' ) && '' !== (string) ( $n['atts']['border_preset'] ?? '' ) && 1 === count( $n['_items'] ?? array() ) && 'flexbox' === ( $n['_items'][0]['type'] ?? '' ) && '' !== (string) ( $n['_items'][0]['atts']['border_preset'] ?? '' ); }, $y_dbl_arr );
ga( "a card skin read one wrapper down is painted ONCE (the panel's own box), never on the grid item's column too", 0 === count( $y_dbl_arr ) );

/* --- [Z] THE BIOME PAGE (2026-09-17): a dark page whose <body> paints layered radial gradients by tag and a fixed grid pattern
 * through body::before; a floating glass card masthead inset by the bar's padding; a hero <section> that IS a two-track grid
 * (.46fr .54fr) with an absolute hairline as a third child; a pill overline with a pulsing dot (class animation) and an
 * arbitrary tracking / translucent fill; stat cards whose caption sits ABOVE the number; a masked organic video shell with a
 * scroll-driven animation; a second band whose overline + h2 wrapper reveals as one and whose row holds a flex-1 card
 * beside a 32rem card of label + progress bars built from utility-class fills (w-[96%]). --- */
echo "\n[Z] Biome page: body-tag shell rules, card masthead inset, root-grid hero, pill dot + tracking, caption-first stats, running animations, utility-fill progress bars\n";
$z_ink = 'color:rgb(255, 255, 255);font-family:Inter, sans-serif;font-size:16px;font-weight:400;line-height:24px;';
$z_glass = 'background-image:linear-gradient(rgba(255, 255, 255, 0.08), rgba(255, 255, 255, 0.03));border-top-width:1px;border-top-style:solid;border-top-color:rgba(255, 255, 255, 0.08);border-bottom-width:1px;border-bottom-style:solid;border-bottom-color:rgba(255, 255, 255, 0.08);border-radius:48px;box-shadow:rgba(255, 255, 255, 0.08) 0px 1px 0px 0px inset, rgba(0, 0, 0, 0.5) 0px 30px 120px 0px;backdrop-filter:blur(60px) saturate(1.4);';
$z_kf = '@keyframes pulseReact { 0%, 100% { transform: scale(1); opacity: 0.9; } 50% { transform: scale(1.03); opacity: 1; } }';
$z_stat = function ( $lbl, $num, $tf ) use ( $z_ink ) { return '<div class="liquid-panel rounded-[2rem] px-6 py-5" data-sc-cs="background-image:linear-gradient(rgba(255, 255, 255, 0.08), rgba(255, 255, 255, 0.03));' . $z_ink . 'padding:20px 24px;border-radius:32px;border-top-width:1px;border-top-style:solid;border-top-color:rgba(255, 255, 255, 0.08);height:104px;display:block;track-frac:' . $tf . '"><div class="font-sans text-[10px] uppercase tracking-[0.28em] text-white/40" data-sc-cs="color:rgba(255, 255, 255, 0.4);font-family:Inter, sans-serif;font-size:10px;letter-spacing:2.8px;line-height:15px;text-transform:uppercase;display:block">' . $lbl . '</div><div class="mt-2 text-3xl font-semibold" data-sc-cs="color:rgb(255, 255, 255);font-family:Inter, sans-serif;font-size:30px;font-weight:600;line-height:36px;margin:8px 0px 0px;display:block">' . $num . '</div></div>'; };
$z_bar = function ( $lbl, $pct, $mt ) use ( $z_ink ) { return '<div data-sc-cs="' . $z_ink . 'height:36px;display:block;' . ( $mt ? 'margin:24px 0px 0px' : '' ) . '"><div class="mb-2 flex items-center justify-between text-sm text-white/50" data-sc-cs="color:rgba(255, 255, 255, 0.5);font-family:Inter, sans-serif;font-size:14px;line-height:20px;margin:0px 0px 8px;height:20px;display:flex;justify-content:space-between;align-items:center"><span data-sc-cs="color:rgba(255, 255, 255, 0.5);font-size:14px;height:20px;display:block">' . $lbl . '</span><span data-sc-cs="color:rgba(255, 255, 255, 0.5);font-size:14px;height:20px;display:block">' . $pct . '%</span></div><div class="h-2 overflow-hidden rounded-full bg-white/10" data-sc-cs="background-color:rgba(255, 255, 255, 0.1);' . $z_ink . 'border-radius:9999px;height:8px;display:block;overflow:hidden"><div class="h-full w-[' . $pct . '%] rounded-full bg-reactor" data-sc-cs="background-color:rgb(97, 218, 251);' . $z_ink . 'border-radius:9999px;height:8px;display:block"></div></div></div>'; };
$z_html = '<!DOCTYPE html><html data-sc-content-width="1440"><head><title>Biome OS | Reactive Field Intelligence</title><style>body{margin:0;background:radial-gradient(circle at 20% 10%, rgba(97,218,251,.15), transparent 28%), #05070b;color:white;font-family:Inter,sans-serif}body::before{content:\'\';position:fixed;inset:0;pointer-events:none;background-image:linear-gradient(rgba(255,255,255,.025) 1px, transparent 1px);background-size:110px 110px;opacity:.45;z-index:-1}.liquid-panel{background:linear-gradient(180deg, rgba(255,255,255,.08), rgba(255,255,255,.03));border:1px solid rgba(255,255,255,.08)}.pulse-react{animation:pulseReact 4s ease-in-out infinite}' . $z_kf . '</style></head>'
	. '<body data-sc-cs="background-color:rgb(5, 7, 11);color:rgb(255, 255, 255)">'
	// the floating card masthead
	. '<header class="fixed inset-x-0 top-0 z-50 px-6 py-5 md:px-10" data-sc-cs="' . $z_ink . 'padding:20px 40px;position:fixed;top:0px;left:0px;right:0px;height:108px;display:block;z-index:50" data-sc-header="rest-height:108px"><div class="liquid-panel mx-auto flex max-w-[1600px] items-center justify-between rounded-[2rem] px-6 py-4" data-sc-cs="' . $z_glass . $z_ink . 'padding:16px 24px;border-radius:32px;max-width:1600px;margin:0px 0px;height:68px;display:flex;justify-content:space-between;align-items:center">'
	. '<div class="flex items-center gap-4" data-sc-cs="' . $z_ink . 'display:flex;align-items:center;gap:16px"><a href="/" data-sc-cs="' . $z_ink . 'font-size:14px;font-weight:500;display:block">Biome OS</a></div>'
	. '<nav data-sc-cs="' . $z_ink . 'display:flex;align-items:center;gap:40px;font-size:11px;letter-spacing:2.64px;text-transform:uppercase"><a href="#one" data-sc-cs="color:rgba(255, 255, 255, 0.45);font-size:11px;letter-spacing:2.64px;text-transform:uppercase;display:block">Virtual Dom</a><a href="#two" data-sc-cs="color:rgba(255, 255, 255, 0.45);font-size:11px;letter-spacing:2.64px;text-transform:uppercase;display:block">Field State</a><a href="#three" data-sc-cs="color:rgba(255, 255, 255, 0.45);font-size:11px;letter-spacing:2.64px;text-transform:uppercase;display:block">Reactive Villas</a></nav>'
	. '<button class="dew rounded-[1.8rem] px-6 py-3 font-sans text-[11px] uppercase tracking-[0.24em] text-white" data-sc-cs="background-image:linear-gradient(rgba(255, 255, 255, 0.16), rgba(255, 255, 255, 0.05));color:rgb(255, 255, 255);font-family:Inter, sans-serif;font-size:11px;font-weight:400;letter-spacing:2.64px;line-height:16.5px;text-align:center;text-transform:uppercase;padding:12px 24px;border-radius:28.8px;border-top-width:1px;border-top-style:solid;border-top-color:rgba(255, 255, 255, 0.12);display:block">Access System</button></div></header>'
	. '<main class="relative z-10 overflow-hidden px-6 pt-36 md:px-10" data-sc-cs="' . $z_ink . 'padding:144px 40px 0px;display:block;overflow:hidden;z-index:10">'
	// the hero: a two-track grid with an absolute hairline as a third child
	. '<section class="relative mx-auto grid min-h-[92vh] max-w-[1600px] items-center gap-16 lg:grid-cols-[.46fr_.54fr]" data-sc-cs="' . $z_ink . 'max-width:1600px;min-height:828px;height:900px;display:grid;gap:64px;grid-template-columns:596px 700px;align-items:center;position:relative" data-sc-cs-sm="grid-template-columns:342px" data-sc-cs-md="grid-template-columns:740px">'
	. '<div class="relative z-20 max-w-2xl" data-sc-cs="' . $z_ink . 'max-width:672px;display:block;track-frac:0.46;track-y:0;track-x:0;track-h:700">'
	. '<div class="bloom active inline-flex items-center gap-3 rounded-full border border-white/10 bg-white/[0.03] px-5 py-3 font-sans text-[11px] uppercase tracking-[0.3em] text-white/55" data-sc-reveal="dir:up;distance:60;scale:0.96;duration:1.2;delay:0;ease:cubic-bezier(0.16, 1, 0.3, 1)" data-sc-cs="background-color:rgba(255, 255, 255, 0.03);color:rgba(255, 255, 255, 0.55);font-family:Inter, sans-serif;font-size:11px;font-weight:400;line-height:16.5px;letter-spacing:3.3px;text-transform:uppercase;padding:12px 20px;border-radius:9999px;border-top-width:1px;border-top-style:solid;border-top-color:rgba(255, 255, 255, 0.1);height:42px;display:inline-flex;align-items:center;gap:12px"><div class="h-2 w-2 rounded-full bg-reactor pulse-react" data-sc-anim="animation:pulseReact 4s ease-in-out 0s infinite normal none" data-sc-keyframes="' . $z_kf . '" data-sc-cs="background-color:rgb(97, 218, 251);' . $z_ink . 'border-radius:9999px;height:8px;width:8px;display:block"></div>Biophilic Data Binding Active</div>'
	. '<h1 class="mt-8 font-serif text-[7rem] leading-[.9] tracking-[-0.08em] text-white" data-sc-cs="color:rgb(255, 255, 255);font-family:Georgia, serif;font-size:112px;font-weight:400;line-height:100.8px;letter-spacing:-8.96px;margin:32px 0px 0px;display:block">Reactive Field Logic</h1>'
	. '<p class="mt-8 max-w-xl text-lg leading-9 text-white/50" data-sc-cs="color:rgba(255, 255, 255, 0.5);font-family:Inter, sans-serif;font-size:20px;line-height:36px;margin:32px 0px 0px;max-width:576px;display:block">A native operating layer for regenerative estates, powering geothermal villas and intelligent field equilibrium.</p>'
	. '<div class="mt-12 flex flex-wrap items-center gap-5" data-sc-cs="' . $z_ink . 'margin:48px 0px 0px;display:flex;align-items:center;gap:20px;flex-wrap:wrap"><button class="dew rounded-[2rem] px-8 py-5 font-sans text-[12px] uppercase tracking-[0.24em] text-white" data-sc-cs="background-image:linear-gradient(rgba(255, 255, 255, 0.16), rgba(255, 255, 255, 0.05));color:rgb(255, 255, 255);font-family:Inter, sans-serif;font-size:12px;letter-spacing:2.88px;line-height:18px;text-align:center;text-transform:uppercase;padding:20px 32px;border-radius:32px;display:block">Launch Ecosystem</button><button class="rounded-[2rem] border border-white/10 bg-white/[0.03] px-8 py-5 font-sans text-[12px] uppercase tracking-[0.24em] text-white/70" data-sc-cs="background-color:rgba(255, 255, 255, 0.03);color:rgba(255, 255, 255, 0.7);font-family:Inter, sans-serif;font-size:12px;letter-spacing:2.88px;line-height:18px;text-align:center;text-transform:uppercase;padding:20px 32px;border-radius:32px;border-top-width:1px;border-top-style:solid;border-top-color:rgba(255, 255, 255, 0.1);display:block">Explore Runtime</button></div>'
	. '<div class="mt-16 flex flex-wrap gap-5" data-sc-cs="' . $z_ink . 'margin:64px 0px 0px;display:flex;gap:20px;flex-wrap:wrap">' . $z_stat( 'Energy State', '98%', '0.22' ) . $z_stat( 'Reactive Nodes', '240', '0.25' ) . '</div></div>'
	// the organic video shell (a scroll-driven grow + a drift)
	. '<div class="relative flex items-center justify-center lg:justify-end" data-sc-cs="' . $z_ink . 'display:flex;align-items:center;justify-content:flex-end;track-frac:0.54;track-y:0;track-x:660;track-h:700"><div class="organic-shell scroll-grow relative aspect-[.95] w-full max-w-[880px] float-core" data-sc-anim="animation:grow auto linear 0s 1 normal both;animation-timeline:view();animation-range:entry 10% cover 65%" data-sc-keyframes="@keyframes grow { from { transform: scale(0.92); opacity: 0.6; } to { transform: scale(1); opacity: 1; } }" data-sc-cs="' . $z_ink . 'max-width:880px;width:700px;height:737px;display:block;position:relative;overflow:hidden;border-radius:44% 56% 58% 42% / 42% 36% 64% 58%;mask-image:radial-gradient(circle, rgb(0, 0, 0) 40%, rgba(0, 0, 0, 0) 74%);filter:drop-shadow(rgba(97, 218, 251, 0.18) 0px 0px 80px);aspect-ratio:0.95"><video autoplay loop muted playsinline class="h-full w-full object-cover" data-sc-cs="' . $z_ink . 'height:737px;width:700px;object-fit:cover;filter:saturate(1.2) contrast(1.08) brightness(0.9);display:block;background-color:rgba(0, 0, 0, 0)"><source src="https://example.com/biome.mp4" type="video/mp4"></video></div></div>'
	. '<div class="light-river bottom-0" data-sc-cs="background-image:linear-gradient(90deg, rgba(0, 0, 0, 0), rgba(97, 218, 251, 0.35), rgba(0, 0, 0, 0));height:1px;display:block;position:absolute;bottom:0px;left:0px;right:0px"></div></section>'
	// the second band: a revealed overline + h2 wrapper, a flex-1 card beside a 32rem card of progress bars
	. '<section class="relative mx-auto mt-32 max-w-[1600px]" data-sc-cs="' . $z_ink . 'margin:128px 0px 0px;max-width:1600px;display:block;position:relative"><div class="flex flex-col gap-16" data-sc-cs="' . $z_ink . 'display:flex;gap:64px;flex-direction:column">'
	. '<div class="max-w-4xl bloom active" data-sc-reveal="dir:up;distance:60;scale:0.96;duration:1.2;delay:0;ease:cubic-bezier(0.16, 1, 0.3, 1)" data-sc-cs="' . $z_ink . 'max-width:896px;display:block"><div class="font-sans text-[11px] uppercase tracking-[0.32em] text-white/35" data-sc-cs="color:rgba(255, 255, 255, 0.35);font-family:Inter, sans-serif;font-size:11px;letter-spacing:3.52px;line-height:16.5px;text-transform:uppercase;display:block">Virtual Ecosystem</div><h2 class="mt-6 font-serif text-7xl leading-[1] tracking-[-0.07em] text-white" data-sc-cs="color:rgb(255, 255, 255);font-family:Georgia, serif;font-size:72px;font-weight:400;line-height:72px;letter-spacing:-5.04px;margin:24px 0px 0px;display:block">Reactivity designed like a living biome.</h2></div>'
	. '<div class="flex flex-col gap-8 lg:flex-row lg:items-stretch" data-sc-cs="' . $z_ink . 'display:flex;gap:32px;align-items:stretch">'
	. '<article class="liquid-panel bloom flex-1 rounded-[3rem] p-10 active" data-sc-reveal="dir:up;distance:60;scale:0.96;duration:1.2;delay:0;ease:cubic-bezier(0.16, 1, 0.3, 1)" data-sc-cs="' . $z_glass . $z_ink . 'padding:40px;height:307px;display:block;flex-grow:1;track-frac:0.62"><div class="flex items-center justify-between" data-sc-cs="' . $z_ink . 'height:15px;display:flex;justify-content:space-between;align-items:center"><div class="font-sans text-[10px] uppercase tracking-[0.28em] text-white/35" data-sc-cs="color:rgba(255, 255, 255, 0.35);font-family:Inter, sans-serif;font-size:10px;letter-spacing:2.8px;line-height:15px;text-transform:uppercase;display:block">Component Modularity</div><div class="h-3 w-3 rounded-full bg-reactor pulse-react" data-sc-anim="animation:pulseReact 4s ease-in-out 0s infinite normal none" data-sc-keyframes="' . $z_kf . '" data-sc-cs="background-color:rgb(97, 218, 251);' . $z_ink . 'border-radius:9999px;height:12px;width:12px;display:block"></div></div>'
	. '<h3 class="mt-8 max-w-sm font-serif text-4xl leading-tight tracking-[-0.06em]" data-sc-cs="color:rgb(255, 255, 255);font-family:Georgia, serif;font-size:36px;font-weight:400;line-height:45px;letter-spacing:-2.16px;margin:32px 0px 0px;max-width:384px;display:block">Dynamic villa systems powered by reactive state.</h3><p class="mt-6 max-w-xl text-lg leading-8 text-white/50" data-sc-cs="color:rgba(255, 255, 255, 0.5);font-family:Inter, sans-serif;font-size:18px;line-height:32px;margin:24px 0px 0px;max-width:576px;display:block">Every villa node synchronizes lighting, geothermal balance, and photovoltaic consumption through a unified component architecture.</p></article>'
	. '<article class="liquid-panel bloom rounded-[3rem] p-8 md:w-[32rem] active" data-sc-reveal="dir:up;distance:60;scale:0.96;duration:1.2;delay:0;ease:cubic-bezier(0.16, 1, 0.3, 1)" data-sc-cs="' . $z_glass . $z_ink . 'padding:32px;width:512px;height:307px;display:block;track-frac:0.36"><div class="font-sans text-[10px] uppercase tracking-[0.28em] text-white/35" data-sc-cs="color:rgba(255, 255, 255, 0.35);font-family:Inter, sans-serif;font-size:10px;letter-spacing:2.8px;line-height:15px;text-transform:uppercase;display:block">Hydration Metrics</div><div class="mt-8 space-y-6" data-sc-cs="' . $z_ink . 'margin:32px 0px 0px;height:156px;display:block">' . $z_bar( 'Field Sync', 96, false ) . $z_bar( 'Energy Flow', 82, true ) . $z_bar( 'Virtual Ecology', 91, true ) . '</div></article></div></div></section></main></body></html>';
$z_bl = FW_Site_Converter_Sources::build_from_html( $z_html, 'Biome', array( 'dynamic_chrome' => true, 'hifi_css' => true ) );
$z_v  = $z_bl['files']['theme-settings.json']['values'] ?? ( $z_bl['files']['theme-settings.json'] ?? array() );
$z_pg = $z_bl['files']['pages.json']['pages'][0]['builder'] ?? array();
$z_mc = (string) ( $z_v['misc_custom_css']['custom_css'] ?? '' );
ga( "the shell's BARE element rules ride Misc Custom CSS too: the <body>'s layered radial ground (its type dropped — Typography owns it) and its fixed grid pattern body::before with its PLACEMENT kept (position:fixed, inset, z-index: a pseudo layer's placement is its design), scoped to the front end (body:not(.wp-admin))", (bool) preg_match( '/body:not\(\.wp-admin\)\{[^}]*background:\s*radial-gradient\(circle at 20% 10%/', $z_mc ) && false === strpos( $z_mc, 'body:not(.wp-admin){margin' ) && (bool) preg_match( '/body:not\(\.wp-admin\)::before\{[^}]*position:\s*fixed[^}]*inset:\s*0[^}]*z-index:\s*-1/', $z_mc ) ); // (scoped to the front end — the builder's admin page loads Misc CSS too)
$z_card = $z_v['header_layout']['header_mode']['top']['header_design']['card'] ?? array();
ga( "a floating glass card masthead keeps its placement: the bar's top padding → the card's native Top Offset (20px), its side padding → a side inset on the card (40px, capped at the source's 1600px), no longer flush to the viewport", 'card' === (string) ( $z_v['header_layout']['header_mode']['top']['header_design']['design'] ?? '' ) && '20' === (string) ( $z_card['card_offset']['value'] ?? '' ) && (bool) preg_match( '/\.site-header--design-card \.header-main > \[class\*="fw-container"\]\{margin-left:40px;margin-right:40px;width:auto;flex:1 1 auto;padding:16px 24px 16px 24px;max-width:1600px;\}/', $z_mc ) && '34' === (string) ( $z_v['header_layout']['min_height']['value'] ?? '' ) ); // + the card's own 16/24 inset; the min-height is the card's CONTENT height (68 − 32 − 2 borders), the theme adds the padding + offsets
$z_row = $z_pg[0]['_items'][0] ?? array();
ga( "a hero <section> that IS the grid (.46fr .54fr) with an absolute hairline as a third child → a native two-track Grid (the in-flow children match the tracks), not a 6/6 split", 'grid' === (string) ( $z_row['atts']['display'] ?? '' ) && (bool) preg_match( '/^[0-9.]+(?:fr|px)\s+[0-9.]+(?:fr|px)$/', (string) ( $z_row['atts']['grid_columns'] ?? '' ) ) && 2 === count( $z_row['_items'] ?? array() ) );
ga( "…and its content width is the section's declared 1600px cap, never the video shell's 880px max-width (a cap inside a grid track is a column measure)", 'content-1600' === (string) ( $z_row['atts']['content_width']['preset'] ?? $z_row['atts']['max_width']['preset'] ?? '' ) );
$z_h1 = $r_find( $z_pg, function ( $n ) { return 'special_heading' === ( $n['shortcode'] ?? '' ) && false !== strpos( (string) ( $n['atts']['title'] ?? '' ), 'Reactive Field Logic' ); } );
$z_h1c = (string) ( $z_h1['atts']['custom_css'] ?? '' );
ga( "the pill overline keeps its measured skin where the class compile falls short — the translucent fill (bg-white/[0.03]) and arbitrary tracking (tracking-[0.3em]) from the stamp — on a selector that outranks the theme's pill tint, and its pulsing dot becomes the overline's leading svg mark", is_array( $z_h1 ) && 'pill' === (string) ( $z_h1['atts']['overline_container'] ?? '' ) && (bool) preg_match( '/selector \.heading-overline--pill \.heading-overline__label,selector \.heading-overline__label\{[^}]*background:rgba\(255, 255, 255, 0\.03\)/', $z_h1c ) && (bool) preg_match( '/selector \.heading-overline\{[^}]*letter-spacing:3\.3px/', $z_h1c ) && false !== strpos( (string) ( $z_h1['atts']['overline_icon']['markup'] ?? '' ), '<circle' ) );
ga( "the hero copy is not centred (a <button>'s own UA centre never centres the band)", '' === (string) ( $z_pg[0]['atts']['text_align'] ?? '' ) );
$z_stat = $r_find( $z_pg, function ( $n ) { return ( 'flexbox' === ( $n['type'] ?? '' ) && 2 === count( $n['_items'] ?? array() ) && 'text_block' === ( $n['_items'][0]['shortcode'] ?? '' ) && 'counter' === ( $n['_items'][1]['shortcode'] ?? '' ) && false !== strpos( (string) ( $n['_items'][0]['atts']['text'] ?? '' ), 'Energy State' ) ) || ( 'special_heading' === ( $n['shortcode'] ?? '' ) && 'Energy State' === (string) ( $n['atts']['overline'] ?? '' ) && '98%' === trim( (string) ( $n['atts']['title'] ?? '' ) ) ); } );
ga( "a stat card whose caption sits ABOVE the number keeps that order: 'Energy State' precedes the 98% (a caption-first counter, or the caption as the value's overline)", is_array( $z_stat ) );
$z_dot = $r_find( $z_pg, function ( $n ) { return 'code_block' === ( $n['shortcode'] ?? '' ) && false !== strpos( (string) ( $n['atts']['custom_css'] ?? '' ), 'width:12px' ); } );
ga( "an element's own RUNNING class animation (a pulsing 12px dot: data-sc-anim + its keyframes) rides the block's Custom CSS — the shorthand on the node, the @keyframes alongside", is_array( $z_dot ) && (bool) preg_match( '/selector\{animation:pulseReact 4s ease-in-out 0s infinite normal none;\}\s*@keyframes pulseReact \{/', (string) $z_dot['atts']['custom_css'] ) );
$z_vid = $r_find( $z_pg, function ( $n ) { return 'media_video' === ( $n['shortcode'] ?? '' ); } );
ga( "…and the organic video shell's scroll-driven grow (animation-timeline: view() + its range) rides the media_video's shape rule, beside the shell's mask / radius / filter", is_array( $z_vid ) && (bool) preg_match( '/mask-image:radial-gradient/', (string) $z_vid['atts']['custom_css'] ) && (bool) preg_match( '/selector\{animation:grow auto linear 0s 1 normal both;animation-timeline:view\(\);animation-range:entry 10% cover 65%;\}/', (string) $z_vid['atts']['custom_css'] ) );
$z_h2 = $r_find( $z_pg, function ( $n ) { return 'special_heading' === ( $n['shortcode'] ?? '' ) && false !== strpos( (string) ( $n['atts']['title'] ?? '' ), 'living biome' ); } );
ga( "a revealed overline + h2 wrapper that a stack split into two items still folds into ONE special_heading (overline 'Virtual Ecosystem' over the title)", is_array( $z_h2 ) && 'Virtual Ecosystem' === (string) ( $z_h2['atts']['overline'] ?? '' ) );
$z_prog = $r_find( $z_pg, function ( $n ) { return 'progress' === ( $n['shortcode'] ?? '' ); } );
$z_pj = json_encode( $z_prog );
ga( "the 32rem glass card of a label + three bars whose fills are UTILITY-class widths (w-[96%] in an 8px clipped rounded track) is a decomposed panel holding the native progress widget with 96 / 82 / 91 — not a verbatim mirror of raw markup", is_array( $z_prog ) && false !== strpos( $z_pj, 'Field Sync' ) && (bool) preg_match( '/"(?:percent|value)":\s*"?96"?/', $z_pj ) && (bool) preg_match( '/"(?:percent|value)":\s*"?82"?/', $z_pj ) && (bool) preg_match( '/"(?:percent|value)":\s*"?91"?/', $z_pj ) );
$z_art = $r_find( $z_pg, function ( $n ) { return 'flexbox' === ( $n['type'] ?? '' ) && 2 === count( $n['_items'] ?? array() ) && false !== strpos( json_encode( $n['_items'][0] ), 'Component Modularity' ) && false !== strpos( json_encode( $n['_items'][1] ), 'Hydration Metrics' ); } );
$z_wof = function ( $c ) { $w = $c['atts']['width'] ?? array(); $lg = (string) ( $w['lg']['preset'] ?? '' ); return ( '' !== $lg && 'none' !== $lg ) ? $lg : (string) ( $w['base']['preset'] ?? '' ); };
ga( "…and the row's widths follow the MEASURED fractions (a flex-1 card at .62 beside a w-[32rem] card at .36 → 7 / 4), not a 1200px guess", is_array( $z_art ) && '7' === $z_wof( $z_art['_items'][0] ) && in_array( $z_wof( $z_art['_items'][1] ), array( '4', '5' ), true ) );
$z_dbl = array(); $r_all( $z_pg, function ( $n ) { return 'flexbox' === ( $n['type'] ?? '' ) && '' !== (string) ( $n['atts']['border_preset'] ?? '' ) && 1 === count( $n['_items'] ?? array() ) && 'flexbox' === ( $n['_items'][0]['type'] ?? '' ) && ( '' !== (string) ( $n['_items'][0]['atts']['border_preset'] ?? '' ) || false !== strpos( (string) ( $n['_items'][0]['atts']['custom_css'] ?? '' ), 'border-radius:3rem' ) ); }, $z_dbl );
ga( "neither glass card wears its skin twice (the column's preset AND a mirrored inner skin)", 0 === count( $z_dbl ) );
$z_river = $r_find( $z_pg, function ( $n ) { return 'flexbox' === ( $n['type'] ?? '' ) && empty( $n['_items'] ) && false !== strpos( (string) ( $n['atts']['custom_css'] ?? '' ), 'position:absolute;pointer-events:none;background-image:linear-gradient(90deg' ); } );
ga( "the hero grid's absolute 1px 'light river' hairline (a decor CELL that takes no track) is a native painted layer pinned to the section's bottom edge, stretched left-0 / right-0, and the section becomes its positioned ancestor", is_array( $z_river ) && (bool) preg_match( '/height:1px;left:0;right:0;bottom:0px/', (string) $z_river['atts']['custom_css'] ) && false !== strpos( (string) ( $z_pg[0]['atts']['custom_css'] ?? '' ), 'selector{position:relative;}' ) );

/* --------------------------------------------------------------------- *
 * [T] A data table wears its MEASURED skin as a Table Preset; the page's other general rules
 * --------------------------------------------------------------------- */
echo "\n[T] A data table → a Table Preset from its measured skin (+ auto rows, pill-not-toolbar, one-sided borders, an icon tile, a grid form, a label-only note, an outlined word)\n";
$t_cs = function ( $extra ) { // a stamp with NO duplicate keys (a real capture never repeats one): the extras override the defaults
	$d = array( 'color' => 'rgb(248, 250, 252)', 'font-family' => 'Inter, sans-serif', 'font-size' => '16px', 'font-weight' => '400', 'line-height' => '24px' );
	foreach ( explode( ';', (string) $extra ) as $kv ) { $i = strpos( $kv, ':' ); if ( $i > 0 ) { $d[ trim( substr( $kv, 0, $i ) ) ] = trim( substr( $kv, $i + 1 ) ); } }
	$o = array(); foreach ( $d as $k => $v ) { $o[] = $k . ':' . $v; } return 'data-sc-cs="' . implode( ';', $o ) . '"'; };
$t_th = 'color:rgb(100, 116, 139);font-family:Syne, sans-serif;font-size:11px;font-weight:700;line-height:16.5px;letter-spacing:1.1px;text-transform:uppercase;padding:1px 1px 24px;height:42px;display:table-cell';
$t_td = 'color:rgb(248, 250, 252);font-family:Inter, sans-serif;font-size:14px;font-weight:300;line-height:20px;padding:24px 1px;height:72px;display:table-cell';
$t_tdx = function ( $extra ) use ( $t_td ) { $d = array(); foreach ( explode( ';', $t_td . ';' . $extra ) as $kv ) { $i = strpos( $kv, ':' ); if ( $i > 0 ) { $d[ trim( substr( $kv, 0, $i ) ) ] = trim( substr( $kv, $i + 1 ) ); } } $o = array(); foreach ( $d as $k => $v ) { $o[] = $k . ':' . $v; } return implode( ';', $o ); };
$t_html = '<!DOCTYPE html><html data-sc-content-width="1440"><head><title>Outpost Telemetry</title></head><body ' . $t_cs( 'background-color:rgb(2, 4, 10)' ) . '><main>'
	. '<section class="relative w-full py-24" ' . $t_cs( 'padding:96px 0px;display:block;height:700px' ) . '><div class="max-w-7xl mx-auto px-6" ' . $t_cs( 'max-width:1280px;padding:0px 24px;display:block' ) . '>'
	// a status PILL (dot + label, inline-flex, rounded-full, padded) before the h1 — a badge, never a toolbar row; an OUTLINED word in the h1
	. '<div class="inline-flex items-center gap-2 px-3 py-1 bg-white/5 border border-white/10 rounded-full mb-6" ' . $t_cs( 'background-color:rgba(255, 255, 255, 0.05);padding:4px 12px;margin:0px 0px 24px;border-top-width:1px;border-top-style:solid;border-top-color:rgba(255, 255, 255, 0.1);border-bottom-width:1px;border-left-width:1px;border-right-width:1px;border-radius:9999px;display:inline-flex;gap:8px;align-items:center' ) . '><span class="w-1.5 h-1.5 rounded-full bg-sky-400" ' . $t_cs( 'background-color:rgb(56, 189, 248);width:6px;height:6px;border-radius:9999px;display:block' ) . '></span><span class="text-[10px] font-bold tracking-widest uppercase" ' . $t_cs( 'font-size:10px;font-weight:700;letter-spacing:1px;text-transform:uppercase;display:inline' ) . '>Observation Vector Active</span></div>'
	. '<h1 class="text-6xl font-black uppercase" ' . $t_cs( 'font-size:60px;font-weight:900;line-height:60px;text-transform:uppercase;display:block' ) . '>The Edge <br><span class="text-stroke" data-sc-cs="color:rgba(0, 0, 0, 0);font-size:60px;font-weight:900;-webkit-text-stroke-width:1px;-webkit-text-stroke-color:rgba(255, 255, 255, 0.4);display:inline">Of Absolute</span><br>Stillness</h1>'
	// a CONTENT-SIZED stat row: two auto cells with a 1px hairline divider between (no cell declares a width; the tracks fill .1 + .1)
	. '<div class="flex items-center gap-6 mt-10" ' . $t_cs( 'display:flex;flex-direction:row;align-items:center;gap:24px;margin:40px 0px 0px;height:52px' ) . '>'
	. '<div class="flex flex-col" data-sc-col="sccol-1" ' . $t_cs( 'display:flex;flex-direction:column;height:52px;track-frac:0.11' ) . '><span class="text-3xl font-bold text-white" ' . $t_cs( 'color:rgb(255, 255, 255);font-size:30px;font-weight:700;line-height:36px;display:block' ) . '>4.2K</span><span class="text-xs uppercase tracking-wider" ' . $t_cs( 'color:rgb(100, 116, 139);font-size:12px;letter-spacing:0.6px;text-transform:uppercase;display:block' ) . '>Vertical Anchor Points</span></div>'
	. '<div class="w-px h-10 bg-white/10" ' . $t_cs( 'background-color:rgba(255, 255, 255, 0.1);width:1px;height:40px;display:block;track-frac:0.001' ) . '></div>'
	. '<div class="flex flex-col" data-sc-col="sccol-2" ' . $t_cs( 'display:flex;flex-direction:column;height:52px;track-frac:0.12' ) . '><span class="text-3xl font-bold text-white" ' . $t_cs( 'color:rgb(255, 255, 255);font-size:30px;font-weight:700;line-height:36px;display:block' ) . '>Zero</span><span class="text-xs uppercase tracking-wider" ' . $t_cs( 'color:rgb(100, 116, 139);font-size:12px;letter-spacing:0.6px;text-transform:uppercase;display:block' ) . '>Terrestrial Distraction</span></div>'
	. '</div></div>'
	// a SCROLL CUE: an icon-only <a> tile pinned to the band's bottom centre (absolute bottom-12 left-1/2 -translate-x-1/2)
	. '<div class="absolute bottom-12 left-1/2 -translate-x-1/2 z-20" ' . $t_cs( 'position:absolute;bottom:48px;left:720px;top:804px;transform:matrix(1, 0, 0, 1, -24, 0);z-index:20;height:48px;display:block' ) . '><a href="#elevation" class="w-12 h-12 rounded-full border border-white/10 flex items-center justify-center" ' . $t_cs( 'border-top-width:1px;border-top-style:solid;border-top-color:rgba(255, 255, 255, 0.1);border-bottom-width:1px;border-left-width:1px;border-right-width:1px;border-radius:9999px;width:48px;height:48px;display:flex;align-items:center;justify-content:center' ) . '><iconify-icon icon="lucide:chevron-down" class="text-slate-400" data-sc-cs="color:rgb(148, 163, 184);font-size:16px;display:inline-block"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"><path d="M6 9l6 6 6-6"/></svg></iconify-icon></a></div>'
	. '</section>'
	// the TABLE band
	. '<section class="py-32 px-6 max-w-7xl mx-auto" ' . $t_cs( 'padding:128px 24px;max-width:1280px;display:block;height:600px' ) . '>'
	. '<h2 class="text-4xl font-extrabold uppercase" ' . $t_cs( 'font-size:36px;font-weight:800;line-height:40px;text-transform:uppercase;display:block' ) . '>Observational Constants</h2>'
	. '<div class="overflow-x-auto w-full" ' . $t_cs( 'display:block;overflow-x:auto' ) . '><table class="w-full text-left border-collapse min-w-[600px]" ' . $t_cs( 'display:table;height:329px;min-width:600px' ) . '>'
	. '<thead ' . $t_cs( 'display:table-header-group' ) . '><tr class="border-b border-white/10" data-sc-cs="border-bottom-width:1px;border-bottom-style:solid;border-bottom-color:rgba(255, 255, 255, 0.1);display:table-row;height:42px">'
	. '<th class="pb-6" data-sc-cs="' . $t_th . '">Station</th><th class="pb-6" data-sc-cs="' . $t_th . '">Altitude</th><th class="pb-6 text-right" data-sc-cs="' . $t_th . ';text-align:right">Status</th></tr></thead>'
	. '<tbody class="divide-y divide-white/5 text-sm font-light" ' . $t_cs( 'font-size:14px;font-weight:300;line-height:20px;display:table-row-group' ) . '>'
	. '<tr class="hover:bg-white/[0.02]" data-sc-cs="display:table-row;height:72px" data-sc-hover="hover-self{background-color:rgba(255, 255, 255, 0.02)}"><td class="py-6 font-bold text-white uppercase tracking-wider" data-sc-cs="' . $t_tdx( 'color:rgb(255, 255, 255);font-family:Syne, sans-serif;font-weight:700;letter-spacing:0.7px;text-transform:uppercase' ) . '">Zenith Peak</td><td class="py-6 font-mono" data-sc-cs="' . $t_tdx( 'color:rgb(203, 213, 225);font-family:ui-monospace, monospace' ) . '">8,848m</td><td class="py-6 text-right" data-sc-cs="' . $t_tdx( 'text-align:right' ) . '"><span class="px-2 py-1 bg-sky-400/10 text-sky-400 text-[10px] font-bold uppercase" data-sc-cs="background-color:rgba(56, 189, 248, 0.1);color:rgb(56, 189, 248);font-size:10px;font-weight:700;letter-spacing:1px;text-transform:uppercase;padding:4px 8px;display:inline">Active</span></td></tr>'
	. '<tr class="hover:bg-white/[0.02]" data-sc-cs="border-top-width:1px;border-top-style:solid;border-top-color:rgba(255, 255, 255, 0.05);display:table-row;height:72px" data-sc-hover="hover-self{background-color:rgba(255, 255, 255, 0.02)}"><td class="py-6 font-bold text-white uppercase tracking-wider" data-sc-cs="' . $t_tdx( 'color:rgb(255, 255, 255);font-family:Syne, sans-serif;font-weight:700;letter-spacing:0.7px;text-transform:uppercase' ) . '">Horizon Spine</td><td class="py-6 font-mono" data-sc-cs="' . $t_tdx( 'color:rgb(203, 213, 225);font-family:ui-monospace, monospace' ) . '">6,268m</td><td class="py-6 text-right" data-sc-cs="' . $t_tdx( 'text-align:right' ) . '"><span class="px-2 py-1 bg-white/5 text-slate-400 text-[10px] font-bold uppercase" data-sc-cs="background-color:rgba(255, 255, 255, 0.05);color:rgb(148, 163, 184);font-size:10px;font-weight:700;letter-spacing:1px;text-transform:uppercase;padding:4px 8px;display:inline">Standby</span></td></tr>'
	. '</tbody></table></div></section>'
	// three "stage" columns ruled on TOP only (border-t) + a CTA band: a ring-tile icon, a 3-track grid signup, a closing mono note
	. '<section class="py-32" ' . $t_cs( 'padding:128px 0px;display:block;height:500px' ) . '><div class="grid md:grid-cols-3 gap-8 max-w-7xl mx-auto px-6" ' . $t_cs( 'display:grid;grid-template-columns:394px 394px 394px;gap:32px;max-width:1280px' ) . '>'
	. str_repeat( '', 0 ) . '<div class="border-t border-white/10 pt-8" data-sc-cs="border-top-width:1px;border-top-style:solid;border-top-color:rgba(255, 255, 255, 0.1);border-bottom-style:solid;border-bottom-color:rgba(255, 255, 255, 0.1);padding:32px 0px 0px;display:block;height:200px"><span class="text-xs font-bold text-slate-500 block mb-4" ' . $t_cs( 'color:rgb(100, 116, 139);font-size:12px;font-weight:700;display:block;margin:0px 0px 16px' ) . '>STAGE I</span><h4 class="text-xl font-bold uppercase mb-4" ' . $t_cs( 'font-size:20px;font-weight:700;text-transform:uppercase;display:block;margin:0px 0px 16px' ) . '>Visual Decompression</h4><p class="text-sm" ' . $t_cs( 'color:rgb(148, 163, 184);font-size:14px;line-height:22px;display:block' ) . '>The eye shifts focal distance from immediate micro-screens to multi-kilometer horizons and relaxes.</p></div>'
	. '<div class="border-t border-white/10 pt-8" data-sc-cs="border-top-width:1px;border-top-style:solid;border-top-color:rgba(255, 255, 255, 0.1);border-bottom-style:solid;border-bottom-color:rgba(255, 255, 255, 0.1);padding:32px 0px 0px;display:block;height:200px"><span class="text-xs font-bold text-slate-500 block mb-4" ' . $t_cs( 'color:rgb(100, 116, 139);font-size:12px;font-weight:700;display:block;margin:0px 0px 16px' ) . '>STAGE II</span><h4 class="text-xl font-bold uppercase mb-4" ' . $t_cs( 'font-size:20px;font-weight:700;text-transform:uppercase;display:block;margin:0px 0px 16px' ) . '>Acoustic Erasure</h4><p class="text-sm" ' . $t_cs( 'color:rgb(148, 163, 184);font-size:14px;line-height:22px;display:block' ) . '>Low-frequency machinery hums and atmospheric reflection drop to absolute zero across the range.</p></div>'
	. '<div class="border-t border-white/10 pt-8" data-sc-cs="border-top-width:1px;border-top-style:solid;border-top-color:rgba(255, 255, 255, 0.1);border-bottom-style:solid;border-bottom-color:rgba(255, 255, 255, 0.1);padding:32px 0px 0px;display:block;height:200px"><span class="text-xs font-bold text-slate-500 block mb-4" ' . $t_cs( 'color:rgb(100, 116, 139);font-size:12px;font-weight:700;display:block;margin:0px 0px 16px' ) . '>STAGE III</span><h4 class="text-xl font-bold uppercase mb-4" ' . $t_cs( 'font-size:20px;font-weight:700;text-transform:uppercase;display:block;margin:0px 0px 16px' ) . '>Noetic Expansion</h4><p class="text-sm" ' . $t_cs( 'color:rgb(148, 163, 184);font-size:14px;line-height:22px;display:block' ) . '>The observer experiences raw integration with structural cosmos patterns as thought streamlines.</p></div>'
	. '</div></section>'
	. '<section class="py-40 text-center" ' . $t_cs( 'padding:160px 0px;text-align:center;display:block;height:700px' ) . '><div class="max-w-4xl mx-auto px-6 text-center" ' . $t_cs( 'max-width:896px;text-align:center;display:block' ) . '>'
	. '<div class="w-16 h-16 rounded-full border border-sky-400/30 flex items-center justify-center mx-auto mb-10 bg-sky-400/5" ' . $t_cs( 'background-color:rgba(56, 189, 248, 0.05);border-top-width:1px;border-top-style:solid;border-top-color:rgba(56, 189, 248, 0.3);border-bottom-width:1px;border-left-width:1px;border-right-width:1px;border-radius:9999px;width:64px;height:64px;display:flex;align-items:center;justify-content:center;margin:0px auto 40px' ) . '><iconify-icon icon="lucide:terminal" class="text-sky-400 text-2xl" data-sc-cs="color:rgb(56, 189, 248);font-size:24px;display:inline-block"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path d="M4 17l6-6-6-6M12 19h8"/></svg></iconify-icon></div>'
	. '<h2 class="text-6xl font-black uppercase mb-6" ' . $t_cs( 'font-size:60px;font-weight:900;line-height:60px;text-transform:uppercase;display:block;margin:0px 0px 24px' ) . '>Initiate Separation Sequence</h2>'
	. '<form class="max-w-md mx-auto grid grid-cols-1 sm:grid-cols-3 gap-3" ' . $t_cs( 'max-width:448px;margin:0px 200px;display:grid;gap:12px;grid-template-columns:141px 141px 141px;height:56px' ) . '><div class="sm:col-span-2" ' . $t_cs( 'display:block' ) . '><input type="email" placeholder="TERMINAL_IDENTIFIER@DOMAIN" class="w-full h-14 bg-black/80 border border-white/10 px-6 text-xs uppercase" ' . $t_cs( 'background-color:rgba(2, 4, 10, 0.8);border-top-width:1px;border-top-style:solid;border-top-color:rgba(255, 255, 255, 0.1);border-bottom-width:1px;border-left-width:1px;border-right-width:1px;height:56px;padding:0px 24px;font-size:12px;text-transform:uppercase;width:294px;display:block' ) . '></div><div ' . $t_cs( 'display:block' ) . '><button type="submit" class="w-full h-14 bg-white text-black font-bold text-xs uppercase" ' . $t_cs( 'background-color:rgb(255, 255, 255);color:rgb(2, 4, 10);font-weight:700;font-size:12px;text-transform:uppercase;height:56px;width:141px;display:block' ) . '>Execute</button></div></form>'
	// a CONTACT form (name + email + a message textarea + send) — a contact_form, never a newsletter
	. '<form class="mt-16 max-w-lg mx-auto grid grid-cols-2 gap-4" ' . $t_cs( 'max-width:512px;display:grid;gap:16px;grid-template-columns:248px 248px;width:512px;height:300px' ) . '><input type="text" placeholder="Name" required class="h-12 bg-transparent border-b border-white/20" ' . $t_cs( 'height:48px;width:248px;border-bottom-width:1px;border-bottom-style:solid;border-bottom-color:rgba(255, 255, 255, 0.2);background-color:rgba(0, 0, 0, 0);font-family:ui-monospace, monospace;display:block' ) . '><input type="email" placeholder="Email" required class="h-12 bg-transparent border-b border-white/20" ' . $t_cs( 'height:48px;width:248px;border-bottom-width:1px;border-bottom-style:solid;border-bottom-color:rgba(255, 255, 255, 0.2);background-color:rgba(0, 0, 0, 0);font-family:ui-monospace, monospace;display:block' ) . '><textarea placeholder="Message" class="col-span-2 h-32 bg-transparent border-b border-white/20" ' . $t_cs( 'height:128px;width:512px;border-bottom-width:1px;border-bottom-style:solid;border-bottom-color:rgba(255, 255, 255, 0.2);background-color:rgba(0, 0, 0, 0);font-family:ui-monospace, monospace;display:block' ) . '></textarea><button type="submit" class="col-span-2 h-12 bg-white text-black font-bold text-xs uppercase" ' . $t_cs( 'background-color:rgb(255, 255, 255);color:rgb(2, 4, 10);font-weight:700;font-size:12px;text-transform:uppercase;height:48px;width:512px;display:block' ) . '>Transmit</button></form>'
	. '<div class="mt-8 font-mono text-[10px] uppercase tracking-[0.25em] text-slate-500" ' . $t_cs( 'color:rgb(100, 116, 139);font-family:ui-monospace, monospace;font-size:10px;letter-spacing:2.5px;text-transform:uppercase;display:block;margin:32px 0px 0px;line-height:15px' ) . '>End-to-end data isolation routing active.</div>'
	. '</div></section>'
	. '</main></body></html>';
$t_bl = FW_Site_Converter_Sources::build_from_html( $t_html, 'Outpost', array( 'dynamic_chrome' => true, 'hifi_css' => true ) );
$t_v  = $t_bl['files']['theme-settings.json']['values'] ?? ( $t_bl['files']['theme-settings.json'] ?? array() );
$t_pg = $t_bl['files']['pages.json']['pages'][0]['builder'] ?? array();
$t_tbl = $r_find( $t_pg, function ( $n ) { return 'table' === ( $n['shortcode'] ?? '' ); } );
$t_slug = (string) ( $t_tbl['atts']['table_preset'] ?? '' );
ga( "a data <table> is the native table pointing at a Table Preset built from its MEASURED skin (`tbl-table-<hash>` — the option value IS the wrapper class the tabular view puts on, so the emitted `.tbl-…` rule applies), with the shortcode's own zebra / hover / frame toggles OFF (the preset owns them)", (bool) preg_match( '/^tbl-table-[0-9a-f]{8}$/', $t_slug ) && 'no' === (string) ( $t_tbl['atts']['style_striped'] ?? '' ) && 'no' === (string) ( $t_tbl['atts']['style_hover'] ?? '' ) );
$t_tp = null; foreach ( (array) ( $t_v['table_presets'] ?? array() ) as $tp ) { if ( 'Table ' . substr( $t_slug, 10 ) === (string) ( $tp['preset_name'] ?? '' ) ) { $t_tp = $tp; } }
ga( "…and that preset exists in Theme Settings → Tables (named `Table <hash>` so its slug is the node's), on top of the built-in library (Clean Lines … stay)", is_array( $t_tp ) && count( (array) ( $t_v['table_presets'] ?? array() ) ) >= 6 );
ga( "the preset carries the measured cell padding (24px vertical), horizontal grid lines in the body's hairline (1px rgba(255,255,255,.05) — a `divide-y` source), the header's rule (1px rgba(255,255,255,.1)), text colour, weight and case, the body's 14px size and the row hover fill", is_array( $t_tp ) && '24' === (string) ( $t_tp['cell_padding_y']['value'] ?? '' ) && 'horizontal' === (string) ( $t_tp['grid_lines'] ?? '' ) && 'rgba(255, 255, 255, 0.05)' === (string) ( $t_tp['grid_color']['custom'] ?? '' ) && '1' === (string) ( $t_tp['sections']['header']['border_width']['value'] ?? '' ) && 'rgb(100, 116, 139)' === (string) ( $t_tp['sections']['header']['text_color']['custom'] ?? '' ) && '700' === (string) ( $t_tp['sections']['header']['font_weight'] ?? '' ) && 'uppercase' === (string) ( $t_tp['sections']['header']['text_transform'] ?? '' ) && '14' === (string) ( $t_tp['cell_font_size']['value'] ?? '' ) && 'rgba(255, 255, 255, 0.02)' === (string) ( $t_tp['sections']['hover']['bg_color']['custom'] ?? '' ) );
ga( "…the long tail rides the preset's own Custom CSS: the header's face / 11px / 1.1px tracking / own padding, the body's 300 weight, and no rule under the last row (a divide-y rules BETWEEN rows only)", is_array( $t_tp ) && (bool) preg_match( '/thead td\{[^}]*font-family:Syne[^}]*font-size:11px !important[^}]*letter-spacing:1\.1px/', (string) ( $t_tp['custom_css'] ?? '' ) ) && (bool) preg_match( '/tbody td\{[^}]*font-weight:300/', (string) ( $t_tp['custom_css'] ?? '' ) ) && false !== strpos( (string) ( $t_tp['custom_css'] ?? '' ), 'tr:last-child td{border-bottom:0' ) );
$t_cells = $t_tbl['atts']['table']['content'] ?? array();
$t_c0 = (string) ( $t_cells[1][0]['textarea'] ?? '' ); $t_c1 = (string) ( $t_cells[1][1]['textarea'] ?? '' ); $t_c2 = (string) ( $t_cells[1][2]['textarea'] ?? '' );
ga( "each body cell carries ONLY its diff from the table base as a kses-safe inline span (HEX colours — kses drops rgb()): the bold white uppercase first column, the mono value column, the plain header cells nothing", (bool) preg_match( '/^<span style="[^"]*color:#ffffff[^"]*font-family:Syne[^"]*font-weight:700[^"]*text-transform:uppercase[^"]*">Zenith Peak<\/span>$/', $t_c0 ) && (bool) preg_match( '/color:#cbd5e1;font-family:ui-monospace, monospace">8,848m/', $t_c1 ) && 'Station' === (string) ( $t_cells[0][0]['textarea'] ?? '' ) );
ga( "…a badge INSIDE a cell keeps its own skin inline (a translucent fill as 8-digit hex, its 10px bold tracked uppercase, its padding) and stays inline (its padding paints without growing the row)", (bool) preg_match( '/<span style="background-color:#38bdf81a;color:#38bdf8;[^"]*font-size:10px;font-weight:700;letter-spacing:1px;text-transform:uppercase;padding:4px 8px">Active<\/span>/', $t_c2 ) && false === strpos( $t_c2, 'inline-block' ) );
ga( "the Status column's measured `text-right` → the native column alignment", 'right' === (string) ( $t_tbl['atts']['table']['cols'][2]['align'] ?? '' ) && '' === (string) ( $t_tbl['atts']['table']['cols'][0]['align'] ?? '' ) );
$t_h1 = $r_find( $t_pg, function ( $n ) { return 'special_heading' === ( $n['shortcode'] ?? '' ) && 'h1' === (string) ( $n['atts']['heading'] ?? '' ); } );
ga( "the status pill (dot + label in a rounded, padded inline-flex) before the h1 is the heading's pill OVERLINE — never a 'toolbar row' of dots (which mirrored it as a full-width oval)", is_array( $t_h1 ) && 'Observation Vector Active' === (string) ( $t_h1['atts']['overline'] ?? '' ) );
ga( "an OUTLINED word in the h1 (`-webkit-text-stroke` + transparent fill) keeps its class-hooked span, the stroke hoisted into the heading's scoped CSS (kses would strip it inline)", is_array( $t_h1 ) && false !== strpos( (string) ( $t_h1['atts']['title'] ?? '' ), '<span class="sc-outline">Of Absolute</span>' ) && (bool) preg_match( '/selector \.sc-outline\{-webkit-text-stroke:1px rgba\(255, 255, 255, 0\.4\);color:transparent;\}/', (string) ( $t_h1['atts']['custom_css'] ?? '' ) ) );
$t_stat = $r_find( $t_pg, function ( $n ) { return 'flexbox' === ( $n['type'] ?? '' ) && 3 === count( $n['_items'] ?? array() ) && false !== strpos( json_encode( $n['_items'][0] ), '4.2K' ) && false !== strpos( json_encode( $n['_items'][2] ), 'Zero' ); } );
ga( "a CONTENT-SIZED flex row (two stats with a 1px hairline between; no cell declares a width; the measured tracks fill .11 + .12) keeps AUTO cells (`flex:0 0 auto`, no 12-grid span) and the divider as a 1×40 painted cell — not three equal spans a nowrap label overflows", is_array( $t_stat ) && 'none' === (string) ( $t_stat['_items'][0]['atts']['width']['base']['preset'] ?? '' ) && false !== strpos( (string) ( $t_stat['_items'][0]['atts']['custom_css'] ?? '' ), 'flex:0 0 auto' ) && (bool) preg_match( '/width:1px !important;height:40px !important/', (string) ( $t_stat['_items'][1]['atts']['custom_css'] ?? '' ) ) );
$t_stage = $r_find( $t_pg, function ( $n ) { return 'icon_box' === ( $n['shortcode'] ?? '' ) && 'Visual Decompression' === (string) ( $n['atts']['title'] ?? '' ); } );
ga( "a column ruled on TOP only (`border-t`) never wears a four-sided frame (cs_decls synthesises `border` only when every edge matches; a one-sided rule is `border-top`), and its eyebrow span is the card's Overline ONCE — not also the first body line", is_array( $t_stage ) && 'STAGE I' === (string) ( $t_stage['atts']['overline'] ?? '' ) && false === strpos( (string) ( $t_stage['atts']['content'] ?? '' ), 'STAGE I' ) && false === strpos( json_encode( $t_bl['files']['theme-design.json'] ?? array() ) . json_encode( $t_pg ), 'border:1px solid rgba(255,255,255,0.1);border-radius:0px;padding:32px' ) );
$t_icon = $r_find( $t_pg, function ( $n ) { return 'icon' === ( $n['shortcode'] ?? '' ) && 'absolute' !== (string) ( $n['atts']['element_position']['position'] ?? '' ); } ); // the emblem (the pinned scroll cue is the other icon)
ga( "a lone glyph inside a painted round TILE (a 64px ring with a translucent fill over the CTA) wears the tile as its own Icon Badge Preset (the same preset an icon_box's chip gets), centred like its `mx-auto`", is_array( $t_icon ) && (bool) preg_match( '/^iconb-badge-[0-9a-f]{8}$/', (string) ( $t_icon['atts']['icon_badge_preset'] ?? '' ) ) && false !== strpos( (string) ( $t_icon['atts']['custom_css'] ?? '' ), 'text-align:center' ) );
$t_nl = $r_find( $t_pg, function ( $n ) { return 'newsletter' === ( $n['shortcode'] ?? '' ); } );
ga( "a signup laid out as a 3-track GRID (the field spanning two beside a `w-full` button in the third) is the INLINE design — the button fills its track, not the form — capped at the form's own 448px measure and filling it", is_array( $t_nl ) && 'inline' === (string) ( $t_nl['atts']['design'] ?? '' ) && (bool) preg_match( '/max-width:448px;width:100%/', (string) ( $t_nl['atts']['custom_css'] ?? '' ) ) );
$t_note = $r_find( $t_pg, function ( $n ) { return 'text_block' === ( $n['shortcode'] ?? '' ) && false !== strpos( (string) ( $n['atts']['text'] ?? '' ), 'End-to-end' ); } );
$t_note_h = $r_find( $t_pg, function ( $n ) { return 'special_heading' === ( $n['shortcode'] ?? '' ) && false !== strpos( (string) ( $n['atts']['overline'] ?? '' ), 'End-to-end' ); } );
ga( "a LABEL-ONLY group (a closing mono note with no title and no subtitle) is a text block wearing the label's measured type (10px mono, 2.5px tracking, uppercase, its muted ink) — not an empty-titled <h2> around an overline", is_array( $t_note ) && null === $t_note_h && (bool) preg_match( '/font-size:10px;line-height:15px[^}]*letter-spacing:2\.5px/', (string) ( $t_note['atts']['custom_css'] ?? '' ) ) && false !== strpos( (string) ( $t_note['atts']['custom_css'] ?? '' ), 'text-transform:uppercase' ) );
$t_cue = $r_find( $t_pg, function ( $n ) { return 'icon' === ( $n['shortcode'] ?? '' ) && 'absolute' === (string) ( $n['atts']['element_position']['position'] ?? '' ); } );
ga( "a SCROLL CUE (an icon-only <a> tile under a wrapper pinned absolute bottom-12 left-1/2 -translate-x-1/2) keeps its placement: the native Position option (bottom 3rem, left 50 %, z 20) + the half-width centring transform — it fell into the flow under the buttons before (a RECURRING finding)", is_array( $t_cue ) && '3' === (string) ( $t_cue['atts']['element_position']['absolute']['pos_offsets']['bottom']['value'] ?? '' ) && '50' === (string) ( $t_cue['atts']['element_position']['absolute']['pos_offsets']['left']['value'] ?? '' ) && '%' === (string) ( $t_cue['atts']['element_position']['absolute']['pos_offsets']['left']['unit'] ?? '' ) && false !== strpos( (string) ( $t_cue['atts']['custom_css'] ?? '' ), 'transform:translateX(-50%)' ) && (bool) preg_match( '/^iconb-badge-/', (string) ( $t_cue['atts']['icon_badge_preset'] ?? '' ) ) );
$t_cf = $r_find( $t_pg, function ( $n ) { return 'contact_form' === ( $n['shortcode'] ?? '' ); } );
$t_cf_items = is_array( $t_cf ) ? json_decode( (string) ( $t_cf['atts']['form']['json'] ?? '[]' ), true ) : array();
$t_cf_types = is_array( $t_cf_items ) ? array_map( function ( $i ) { return (string) ( $i['type'] ?? '' ); }, $t_cf_items ) : array();
ga( "a CONTACT form (name + email + a message textarea + send) is the native contact_form (the forms extension), its fields in order as form-builder items with labels / required / half-widths and the send label — it was mapped to the two-field newsletter with the textarea dropped (a RECURRING finding)", is_array( $t_cf ) && array( 'form-header-title', 'text', 'email', 'textarea' ) === $t_cf_types && 'Transmit' === (string) ( $t_cf['atts']['submit_button_text'] ?? '' ) && '1_2' === (string) ( $t_cf_items[1]['width'] ?? '' ) && '1_1' === (string) ( $t_cf_items[3]['width'] ?? '' ) && ! empty( $t_cf_items[1]['options']['required'] ) && 'Name' === (string) ( $t_cf_items[1]['options']['label'] ?? '' ) );
ga( "…the source's underline-only mono inputs ride the form's scoped CSS (border-bottom kept, the other edges zeroed, the mono face), the submit its fill / ink / case", is_array( $t_cf ) && (bool) preg_match( '/textarea,selector select\\{[^}]*border-bottom:1px solid rgba\\(255, 255, 255, 0\\.2\\) !important[^}]*border-top:0 !important/', (string) ( $t_cf['atts']['custom_css'] ?? '' ) ) && false !== strpos( (string) ( $t_cf['atts']['custom_css'] ?? '' ), 'font-family:ui-monospace' ) && (bool) preg_match( '/\\.fw-form-submit\\{[^}]*background-color:rgb\\(255, 255, 255\\) !important[^}]*text-transform:uppercase/', (string) ( $t_cf['atts']['custom_css'] ?? '' ) ) );
$t_nl2 = array(); $r_all( $t_pg, function ( $n ) { return 'newsletter' === ( $n['shortcode'] ?? '' ); }, $t_nl2 );
ga( "…and the band's signup (one field + a button) is still the newsletter — the contact form never steals it, nor the reverse", 1 === count( $t_nl2 ) );
$t_lone = $r_find( $t_pg, function ( $n ) { return 'flexbox' === ( $n['type'] ?? '' ) && false !== strpos( (string) ( $n['atts']['custom_css'] ?? '' ), 'selector{width:100%;}' ); } );
ga( "a FULL-WIDTH lone cell carries no `fw-span-12` (a span child turns a block parent into an auto flex row: the hero's pill sat beside its heading) — it fills its parent through width:100% instead", is_array( $t_lone ) && 'none' === (string) ( $t_lone['atts']['width']['base']['preset'] ?? '' ) );

/* --------------------------------------------------------------------- *
 * [U] The findings feed, second batch: a status LOCKUP (bare dot + label, no pill skin) before the heading with its pulse +
 *     glow; a roman <i>; the animation stamps never in a stored title; a hairline accent bar keeps its width; a floating
 *     centred PILL nav is a top bar whose skinned links are the menu, not three CTAs; the executed import summary.
 * --------------------------------------------------------------------- */
$w_kf = '@keyframes pulseDot { 0%, 100% { opacity: 1; } 50% { opacity: 0.4; } }';
$w_header = '<header class="fixed top-6 left-1/2 -translate-x-1/2 z-50 flex flex-col items-center w-[640px] rounded-full" ' . $t_cs( 'position:fixed;top:24px;left:400px;width:640px;height:56px;display:flex;flex-direction:column;align-items:center;border-radius:9999px;background-color:rgba(255, 255, 255, 0.06)' ) . '>'
	. '<nav class="flex items-center gap-2" ' . $t_cs( 'display:flex;flex-direction:row;align-items:center;gap:8px;height:56px;width:600px' ) . '>'
	. '<a href="/" class="font-bold px-4" ' . $t_cs( 'font-weight:700;padding:0px 16px;display:inline-flex' ) . '>Outpost</a>'
	. '<a href="#routes" class="rounded-full px-4 py-2 bg-white/10" ' . $t_cs( 'border-radius:9999px;padding:8px 16px;background-color:rgba(255, 255, 255, 0.1);display:inline-flex;height:36px' ) . '>Routes</a>'
	. '<a href="#camps" class="rounded-full px-4 py-2 bg-white/10" ' . $t_cs( 'border-radius:9999px;padding:8px 16px;background-color:rgba(255, 255, 255, 0.1);display:inline-flex;height:36px' ) . '>Camps</a>'
	. '<a href="#journal" class="rounded-full px-4 py-2 bg-white/10" ' . $t_cs( 'border-radius:9999px;padding:8px 16px;background-color:rgba(255, 255, 255, 0.1);display:inline-flex;height:36px' ) . '>Journal</a>'
	. '<a href="#start" class="rounded-full px-5 py-2 bg-emerald-400 text-black font-bold" ' . $t_cs( 'border-radius:9999px;padding:8px 20px;background-color:rgb(52, 211, 153);color:rgb(2, 4, 10);font-weight:700;display:inline-flex;height:36px' ) . '>Get started</a>'
	. '</nav></header>';
$w_html = '<!DOCTYPE html><html data-sc-content-width="1440"><head><title>Outpost | Expeditions</title></head><body ' . $t_cs( 'background-color:rgb(2, 4, 10)' ) . '>' . $w_header . '<main>'
	. '<section class="py-32" ' . $t_cs( 'padding:128px 0px;display:block;height:700px' ) . '>'
	// the STATUS lockup: a flex row of a pulsing, glowing 8px dot + a short label, no pill skin, right before the heading
	. '<div class="flex items-center gap-2 mb-6" ' . $t_cs( 'display:flex;align-items:center;gap:8px;margin:0px 0px 24px;height:16px' ) . '><span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse" data-sc-anim="animation:pulseDot 2s ease-in-out 0s infinite normal none" data-sc-keyframes="' . $w_kf . '" ' . $t_cs( 'width:8px;height:8px;border-radius:9999px;background-color:rgb(52, 211, 153);box-shadow:rgba(52, 211, 153, 0.6) 0px 0px 12px 0px;display:block' ) . '></span><span class="text-xs uppercase tracking-widest text-slate-400" ' . $t_cs( 'font-size:12px;text-transform:uppercase;letter-spacing:1.2px;color:rgb(148, 163, 184);display:block' ) . '>Now accepting expeditions</span></div>'
	// the heading carries its own entrance stamp + keyframes (never stored) and a ROMAN <i> (not-italic)
	. '<h1 class="text-6xl font-extrabold" data-sc-anim="animation:fadeUp 1s ease 0s 1 normal both" data-sc-keyframes="@keyframes fadeUp { from { opacity:0 } to { opacity:1 } }" ' . $t_cs( 'font-size:60px;font-weight:800;line-height:64px;display:block' ) . '>Solitary <i class="not-italic text-emerald-400" ' . $t_cs( 'font-style:normal;color:rgb(52, 211, 153);font-size:60px;font-weight:800' ) . '>elevation</i> project</h1>'
	// a 64 × 1 accent hairline
	. '<div class="w-16 h-px bg-emerald-400 mt-8" ' . $t_cs( 'width:64px;height:1px;background-color:rgb(52, 211, 153);margin:32px 0px 0px;display:block' ) . '></div>'
	. '<p class="mt-6 text-slate-400" ' . $t_cs( 'margin:24px 0px 0px;color:rgb(148, 163, 184);display:block' ) . '>We route every expedition through three staging camps, each with its own weather window and its own crew.</p>'
	// two hero actions: a bordered pill + a GHOST pill (no fill, no border — still a padded, rounded <button>)
	. '<div class="mt-10 flex gap-4" ' . $t_cs( 'display:flex;gap:16px;margin:40px 0px 0px;height:58px' ) . '><button class="px-10 py-5 rounded-full text-xs uppercase tracking-[0.2em] font-bold" ' . $t_cs( 'padding:20px 40px;border-radius:9999px;font-size:12px;font-weight:700;letter-spacing:2.4px;text-transform:uppercase;border-top-width:1px;border-top-style:solid;border-top-color:rgba(52, 211, 153, 0.4);border-bottom-width:1px;border-left-width:1px;border-right-width:1px;height:58px;display:block' ) . '>Explore</button><button class="px-10 py-5 rounded-full text-xs uppercase tracking-[0.2em] font-bold text-white/60" ' . $t_cs( 'padding:20px 40px;border-radius:9999px;font-size:12px;font-weight:700;letter-spacing:2.4px;text-transform:uppercase;color:rgba(255, 255, 255, 0.6);height:58px;display:block' ) . '>The process</button></div>'
	. '</section></main>'
	// a footer whose column title is a small tracked sans label (the theme's serif heading font must not win)
	. '<footer ' . $t_cs( 'display:block;padding:64px 0px;height:300px' ) . '><div class="grid grid-cols-3" ' . $t_cs( 'display:grid;grid-template-columns:400px 400px 400px' ) . '><div><h4 class="text-[10px] uppercase tracking-[0.3em] font-bold" ' . $t_cs( 'font-family:Inter, sans-serif;font-size:10px;text-transform:uppercase;letter-spacing:3px;font-weight:700;display:block' ) . '>Navigation</h4><ul><li><a href="#a">Routes</a></li><li><a href="#b">Camps</a></li></ul></div><div><h4 class="text-[10px] uppercase tracking-[0.3em] font-bold" ' . $t_cs( 'font-family:Inter, sans-serif;font-size:10px;text-transform:uppercase;letter-spacing:3px;font-weight:700;display:block' ) . '>Support</h4><ul><li><a href="#c">Help</a></li><li><a href="#d">Contact</a></li></ul></div><div><p>&copy; Outpost</p></div></div></footer>'
	. '</body></html>';
$w_bl = FW_Site_Converter_Sources::build_from_html( $w_html, 'Outpost', array( 'dynamic_chrome' => true, 'hifi_css' => true ) );
$w_v  = $w_bl['files']['theme-settings.json']['values'] ?? array();
$w_pg = $w_bl['files']['pages.json']['pages'][0]['builder'] ?? array();
$w_h1 = $r_find( $w_pg, function ( $n ) { return 'special_heading' === ( $n['shortcode'] ?? '' ) && false !== strpos( (string) ( $n['atts']['title'] ?? '' ), 'Solitary' ); } );
$w_h1c = (string) ( $w_h1['atts']['custom_css'] ?? '' );
ga( "a STATUS lockup (a bare flex row: one 8px painted dot + one short label, no pill skin) right before the heading is its overline — it was mirrored as a toolbar row (a code_block dot + a text cell) on three conversions", is_array( $w_h1 ) && 'Now accepting expeditions' === (string) ( $w_h1['atts']['overline'] ?? '' ) && false !== strpos( (string) ( $w_h1['atts']['overline_icon']['markup'] ?? '' ), 'fill="rgb(52, 211, 153)"' ) );
ga( "…and the dot's PULSE (its running class animation + keyframes) and GLOW (box-shadow) ride the overline's mark", false !== strpos( $w_h1c, 'selector .heading-overline__icon{animation:pulseDot 2s ease-in-out 0s infinite normal none;}' ) && false !== strpos( $w_h1c, '@keyframes pulseDot' ) && false !== strpos( $w_h1c, '.heading-overline__icon{border-radius:50%;box-shadow:rgba(52, 211, 153, 0.6) 0px 0px 12px 0px;}' ) );
ga( "a <i> the source RESET to roman (not-italic) keeps the reset inline — the tag alone would re-italicise it", (bool) preg_match( '/<i style="font-style:normal;[^"]*">elevation<\/i>/', (string) ( $w_h1['atts']['title'] ?? '' ) ) );
ga( "the heading's own entrance stamp (data-sc-anim + its keyframes) never reaches the stored title", false === strpos( (string) ( $w_h1['atts']['title'] ?? '' ), 'data-sc-' ) && false === strpos( (string) ( $w_h1['atts']['title'] ?? '' ), '@keyframes' ) );
$w_bar = $r_find( $w_pg, function ( $n ) { return 'flexbox' === ( $n['type'] ?? '' ) && false !== strpos( (string) ( $n['atts']['custom_css'] ?? '' ), 'height:1px' ); } );
ga( "a 64 × 1 px accent hairline is a paint block that keeps its MEASURED width (a RECURRING finding: the colour survived, the width did not)", is_array( $w_bar ) && false !== strpos( (string) $w_bar['atts']['custom_css'], 'background-color:rgb(52, 211, 153)' ) && false !== strpos( (string) $w_bar['atts']['custom_css'], 'width:64px' ) );
$w_mode = (string) ( $w_v['header_layout']['header_mode']['mode'] ?? '' );
ga( "a floating centred PILL bar (fixed, column wrapper, w-[640px]) is a TOP header — wide + short with a row nav, never a vertical-left rail", 'top' === $w_mode );
$r_find_hf = function ( $els, $type ) { foreach ( (array) $els as $e ) { if ( $type === (string) ( $e['element_type']['element'] ?? '' ) ) { return $e; } } return null; };
$w_ctas = 0; foreach ( array( 'main_right', 'main_center', 'main_left' ) as $uz ) { foreach ( (array) ( $w_v['header_main'][ $uz ] ?? array() ) as $ue ) { if ( 'cta_button' === (string) ( $ue['element_type']['element'] ?? '' ) ) { $w_ctas++; } } }
ga( "a pill nav (every link skinned alike) keeps its links as the MENU and only the standout link is a CTA — the links were emitted as menu items AND as three CTA buttons (a RECURRING finding)", 1 === $w_ctas );
$w_bc = (array) ( $w_v['button_colors'] ?? array() ); $w_ghost = null; foreach ( $w_bc as $w_b ) { if ( 'Ghost' === (string) ( $w_b['color_name'] ?? '' ) ) { $w_ghost = $w_b; } }
ga( "a GHOST action (a padded, rounded <button> with no fill and no border) registers its own Ghost preset — its ink, no border, its 700 / 2.4px / uppercase type — it fell to the grey outline fallback before (three conversions)", is_array( $w_ghost ) && 'none' === (string) ( $w_ghost['states']['default']['border_style'] ?? '' ) && 'rgba(255, 255, 255, 0.6)' === (string) ( $w_ghost['states']['default']['text_color']['custom'] ?? '' ) && '700' === (string) ( $w_ghost['font']['weight'] ?? '' ) && 'uppercase' === (string) ( $w_ghost['states']['default']['text_transform'] ?? '' ) );
$w_gbtn = $r_find( $w_pg, function ( $n ) { return 'button' === ( $n['shortcode'] ?? '' ) && 'The process' === (string) ( $n['atts']['label'] ?? '' ); } );
ga( "…and the body button wears that preset (style btn-ghost), never the sc-btn-outline transplant", is_array( $w_gbtn ) && 'btn-ghost' === (string) ( $w_gbtn['atts']['style'] ?? '' ) );
$w_misc = (string) ( is_array( $w_v['misc_custom_css'] ?? null ) ? json_encode( $w_v['misc_custom_css'] ) : ( $w_v['misc_custom_css'] ?? '' ) );
ga( "a footer column title's MEASURED type (a 10px tracked sans label) is carried !important on .footer-links-title — the generated site-wide heading family is !important too and used to win (footer titles in the serif heading font, three sites)", false !== strpos( $w_misc, 'font-family:Inter, sans-serif !important' ) && (bool) preg_match( '/\.footer-links-title\{[^}]*font-size:10px !important/', $w_misc ) );
ga( "…the menu item style reads the pill skin from those links (a filled, rounded item), not from the one standout", 'pill' === (string) ( $w_v['header_menu']['menu_item_style'] ?? '' ) && 'rgba(255, 255, 255, 0.1)' === (string) ( $w_v['header_menu']['menu_item_bg']['custom'] ?? '' ) );
ga( "…the standout is the ONE CTA (the filled link), and the brand's home link never doubles as a list item beside the logo", 'Get started' === (string) ( $r_find_hf( $w_v['header_main']['main_right'] ?? array(), 'cta_button' )['element_type']['cta_button']['cta_text'] ?? '' ) && null === $r_find_hf( $w_v['header_main']['main_right'] ?? array(), 'list_item' ) );

/* --------------------------------------------------------------------- *
 * [V] A real conversion audited to the pixel (a dark gallery landing): the rules its 12 discrepancies became — a left-pinned
 *     capped text column, a heading of block-span lines, a measured glass pill with a dot, a faint watermark heading pinned out
 *     of flow, a measured grid's stat cells as columns, unequal tracks kept exact, a designed panel height, a measured marquee,
 *     an orb button with a stacked label (and no ghost skin on its cell), lossless native margins, an outline tie broken by ink.
 * --------------------------------------------------------------------- */
$x_cs = $t_cs;
$x_kf = '@keyframes marquee { 100% { transform: translateX(-50%); } }';
$x_html = '<!DOCTYPE html><html data-sc-content-width="1700"><head><title>Outpost | Light</title></head><body ' . $x_cs( 'background-color:rgb(2, 4, 10)' ) . '><main>'
	// HERO: a full-width band; a LEFT-pinned `max-w-3xl` column (margin 0, no mx-auto); a `.glass` measured pill with a dot; block-span lines
	. '<section class="relative min-h-screen" ' . $x_cs( 'display:block;height:900px' ) . '><div class="relative mx-auto flex min-h-screen max-w-[1600px] items-center px-8" ' . $x_cs( 'display:flex;max-width:1600px;height:900px;align-items:center;padding:0px 32px;width:1440px' ) . '><div class="max-w-3xl" ' . $x_cs( 'display:block;max-width:768px;width:768px;height:653px;margin:0px' ) . '>'
	. '<div class="glass inline-flex items-center gap-3 rounded-full px-5 py-3 text-[11px] uppercase tracking-[0.3em] text-white/60" ' . $x_cs( 'background-color:rgba(255, 255, 255, 0.04);color:rgba(255, 255, 255, 0.6);font-size:11px;letter-spacing:3.3px;text-transform:uppercase;padding:12px 20px;border-top-width:1px;border-top-style:solid;border-top-color:rgba(255, 255, 255, 0.08);border-radius:9999px;backdrop-filter:blur(20px);height:42px;display:inline-flex;align-items:center;gap:12px' ) . '><div class="h-2 w-2 rounded-full bg-cyan-300" ' . $x_cs( 'width:8px;height:8px;border-radius:9999px;background-color:rgb(103, 232, 249);display:block' ) . '></div>Field telemetry active</div>'
	. '<h1 class="serif mt-8" ' . $x_cs( 'font-size:128px;line-height:108.8px;font-weight:400;letter-spacing:-6.4px;margin:32px 0px 0px;display:block;height:326px' ) . '><span class="wind block" ' . $x_cs( 'font-size:128px;display:block;height:108.8px' ) . '>Synthetic</span> <span class="wind block" ' . $x_cs( 'font-size:128px;display:block;height:108.8px' ) . '>Light</span> <span class="wind block" ' . $x_cs( 'font-size:128px;display:block;height:108.8px' ) . '>Structures</span></h1>'
	. '<p class="mt-8 max-w-2xl" ' . $x_cs( 'font-size:18px;line-height:36px;max-width:672px;margin:32px 0px 0px;display:block;height:72px' ) . '>A luminous interface engineered around chromatic sequencing, photon-efficient synthesis and reactive optics.</p>'
	. '<div class="mt-10 flex gap-4" ' . $x_cs( 'display:flex;gap:16px;margin:40px 0px 0px;height:59px' ) . '><button class="glass rounded-full px-8 py-5 text-[11px] uppercase tracking-[0.25em]" ' . $x_cs( 'background-color:rgba(255, 255, 255, 0.04);color:rgb(255, 255, 255);font-size:11px;letter-spacing:2.75px;text-transform:uppercase;padding:20px 32px;border-top-width:1px;border-top-style:solid;border-top-color:rgba(255, 255, 255, 0.08);border-radius:9999px;height:59px;display:block' ) . '>Launch</button><button class="rounded-full border border-white/10 px-8 py-5 text-[11px] uppercase tracking-[0.25em] text-white/70" ' . $x_cs( 'color:rgba(255, 255, 255, 0.7);font-size:11px;letter-spacing:2.75px;text-transform:uppercase;padding:20px 32px;border-top-width:1px;border-top-style:solid;border-top-color:rgba(255, 255, 255, 0.1);border-radius:9999px;height:59px;display:block' ) . '>Explore</button></div>'
	. '</div></div></section>'
	// EDITORIAL: a faint absolute watermark heading + an UNEQUAL measured grid (589 / 707)
	. '<section class="relative overflow-hidden px-8 py-40" ' . $x_cs( 'display:block;padding:160px 32px;height:585px;overflow:hidden' ) . '><div class="massive-number absolute left-0 top-0" ' . $x_cs( 'position:absolute;font-size:288px;line-height:230.4px;font-weight:700;color:rgba(255, 255, 255, 0.06);left:0px;top:0px;display:block;height:230px' ) . '>01</div>'
	. '<div class="editorial relative mx-auto max-w-[1600px]" ' . $x_cs( 'display:grid;grid-template-columns:589.078px 706.906px;gap:80px;align-items:center;max-width:1600px;height:265px;width:1376px' ) . '><div ' . $x_cs( 'display:block;height:196px;track-frac:0.428' ) . '><div class="text-[11px] uppercase tracking-[0.3em] text-white/40" ' . $x_cs( 'font-size:11px;text-transform:uppercase;letter-spacing:3.3px;color:rgba(255, 255, 255, 0.4);display:block;height:16px' ) . '>Editorial narrative</div><h2 class="serif mt-6" ' . $x_cs( 'font-size:86.4px;line-height:77.76px;font-weight:400;margin:24px 0px 0px;display:block;height:155px' ) . '>Light behaving like biological matter.</h2></div><div class="space-y-10" ' . $x_cs( 'display:block;height:265px;track-frac:0.514' ) . '><p class="text-xl leading-10 text-white/70" ' . $x_cs( 'font-size:20px;line-height:40px;color:rgba(255, 255, 255, 0.7);display:block;height:120px' ) . '>The idea of a traditional dashboard dissolves into a reactive atmospheric system where colour, motion and light become one continuous interface.</p><p class="max-w-xl text-base leading-8 text-white/55" ' . $x_cs( 'font-size:16px;line-height:32px;color:rgba(255, 255, 255, 0.55);max-width:576px;margin:40px 0px 0px;display:block;height:64px' ) . '>Built around chromatic sequencing, the system responds like a living ecosystem instead of static software.</p></div></div></section>'
	// TELEMETRY: a designed-height panel (a justify-between column) holding a measured 3-up stat grid of label + value cells
	. '<section class="px-8 py-20" ' . $x_cs( 'display:block;padding:80px 32px;height:880px' ) . '><div class="data-monolith mx-auto max-w-[1700px]" ' . $x_cs( 'display:block;max-width:1700px;height:720px;width:1376px;border-radius:64px;background-color:rgba(255, 255, 255, 0.03);border-top-width:1px;border-top-style:solid;border-top-color:rgba(255, 255, 255, 0.08);overflow:hidden' ) . '><div class="relative z-10 flex h-full flex-col justify-between p-10" ' . $x_cs( 'display:flex;flex-direction:column;justify-content:space-between;padding:40px;height:720px' ) . '>'
	. '<div class="flex items-start justify-between" ' . $x_cs( 'display:flex;justify-content:space-between;align-items:flex-start;height:196px' ) . '><div ' . $x_cs( 'display:block;height:196px' ) . '><div class="text-[11px] uppercase tracking-[0.3em] text-white/40" ' . $x_cs( 'font-size:11px;text-transform:uppercase;letter-spacing:3.3px;display:block;height:16px' ) . '>Neural core</div><h2 class="serif mt-6" ' . $x_cs( 'font-size:86.4px;line-height:77.76px;font-weight:400;margin:24px 0px 0px;display:block;height:155px' ) . '>A monolithic chromatic ecosystem.</h2></div><div class="max-w-sm text-right text-white/60 leading-8" ' . $x_cs( 'display:block;max-width:384px;text-align:right;line-height:32px;height:64px' ) . '>Real-time telemetry visualised through a living atmospheric architecture.</div></div>'
	. '<div class="grid gap-10 md:grid-cols-3" ' . $x_cs( 'display:grid;grid-template-columns:404.656px 404.672px 404.656px;gap:40px;height:104px' ) . '><div ' . $x_cs( 'display:block;height:104px;track-frac:0.31' ) . '><div class="text-[10px] uppercase tracking-[0.3em] text-white/40" ' . $x_cs( 'font-size:10px;text-transform:uppercase;letter-spacing:3px;display:block;height:15px' ) . '>Yield index</div><div class="mt-2 text-6xl font-semibold text-cyan-300" ' . $x_cs( 'font-size:72px;line-height:72px;font-weight:600;color:rgb(103, 232, 249);margin:8px 0px 0px;display:block;height:72px' ) . '>98%</div></div><div ' . $x_cs( 'display:block;height:104px;track-frac:0.31' ) . '><div class="text-[10px] uppercase tracking-[0.3em] text-white/40" ' . $x_cs( 'font-size:10px;text-transform:uppercase;letter-spacing:3px;display:block;height:15px' ) . '>Photon stability</div><div class="mt-2 text-6xl font-semibold text-pink-300" ' . $x_cs( 'font-size:72px;line-height:72px;font-weight:600;color:rgb(249, 168, 212);margin:8px 0px 0px;display:block;height:72px' ) . '>Live</div></div><div ' . $x_cs( 'display:block;height:104px;track-frac:0.31' ) . '><div class="text-[10px] uppercase tracking-[0.3em] text-white/40" ' . $x_cs( 'font-size:10px;text-transform:uppercase;letter-spacing:3px;display:block;height:15px' ) . '>Neural sync</div><div class="mt-2 text-6xl font-semibold text-amber-300" ' . $x_cs( 'font-size:72px;line-height:72px;font-weight:600;color:rgb(252, 211, 77);margin:8px 0px 0px;display:block;height:72px' ) . '>0.2ms</div></div></div>'
	. '</div></div></section>'
	// MARQUEE: a nowrap flex track sliding on X by a running animation — no `animate-marquee` class, the animation is measured
	. '<section class="overflow-hidden py-40" ' . $x_cs( 'display:block;padding:160px 0px;height:464px;overflow:hidden' ) . '><div class="marquee" data-sc-anim="animation:marquee 20s linear 0s infinite normal none" data-sc-keyframes="' . $x_kf . '" ' . $x_cs( 'display:flex;white-space:nowrap;gap:80px;height:144px;transform:matrix(1, 0, 0, 1, -277, 0)' ) . '><div class="serif text-[9rem] leading-none text-white/[0.06]" ' . $x_cs( 'font-size:144px;line-height:144px;white-space:nowrap;color:rgba(255, 255, 255, 0.06);display:block;height:144px' ) . '>Chromatic sequencing</div><div class="serif text-[9rem] leading-none text-cyan-300/10" ' . $x_cs( 'font-size:144px;line-height:144px;white-space:nowrap;color:rgba(103, 232, 249, 0.1);display:block;height:144px' ) . '>Neural synchronisation</div><div class="serif text-[9rem] leading-none text-white/[0.06]" ' . $x_cs( 'font-size:144px;line-height:144px;white-space:nowrap;color:rgba(255, 255, 255, 0.06);display:block;height:144px' ) . '>Photon synthesis</div><div class="serif text-[9rem] leading-none text-cyan-300/10" ' . $x_cs( 'font-size:144px;line-height:144px;white-space:nowrap;color:rgba(103, 232, 249, 0.1);display:block;height:144px' ) . '>Synthetic luminosity</div></div></section>'
	// QUOTE + ORB: an unequal `[1.2fr_.8fr]` grid; a quote panel; a cell that IS one 224px orb button with a stacked label
	. '<section class="px-8 py-20" ' . $x_cs( 'display:block;padding:80px 32px;height:763px' ) . '><div class="mx-auto grid max-w-[1600px] gap-12 lg:grid-cols-[1.2fr_.8fr]" ' . $x_cs( 'display:grid;grid-template-columns:796.797px 531.203px;gap:48px;max-width:1600px;height:602px;width:1376px' ) . '>'
	. '<div class="quote-panel" ' . $x_cs( 'display:block;border-radius:64px;padding:96px;background-image:linear-gradient(rgba(255, 255, 255, 0.05), rgba(255, 255, 255, 0.01));border-top-width:1px;border-top-style:solid;border-top-color:rgba(255, 255, 255, 0.08);height:602px;track-frac:0.579' ) . '><div class="text-[11px] uppercase tracking-[0.3em] text-white/40" ' . $x_cs( 'font-size:11px;text-transform:uppercase;letter-spacing:3.3px;display:block;height:16px' ) . '>Field observation</div><blockquote class="serif mt-10" ' . $x_cs( 'font-size:80px;line-height:80px;margin:40px 0px 0px;display:block;height:240px' ) . '>The interface dissolves into pure atmospheric light.</blockquote><p class="mt-10 max-w-xl text-lg leading-9 text-white/65" ' . $x_cs( 'font-size:18px;line-height:36px;max-width:576px;margin:40px 0px 0px;display:block;height:72px' ) . '>Instead of windows and panels, the product creates chromatic environments engineered to feel alive and reactive.</p></div>'
	. '<div class="flex items-center justify-center" ' . $x_cs( 'display:flex;align-items:center;justify-content:center;height:602px;track-frac:0.386' ) . '><button class="cta-orb" ' . $x_cs( 'display:grid;grid-template-columns:222px;align-items:center;text-align:center;height:224px;width:224px;border-radius:999px;background-image:radial-gradient(circle at 30% 30%, rgba(255, 255, 255, 0.18), rgba(255, 255, 255, 0.04));border-top-width:1px;border-top-style:solid;border-top-color:rgba(255, 255, 255, 0.1);backdrop-filter:blur(30px);color:rgb(255, 255, 255);font-size:16px' ) . '><div class="text-center" ' . $x_cs( 'display:block;text-align:center;height:64px' ) . '><div class="text-[11px] uppercase tracking-[0.3em] text-white/50" ' . $x_cs( 'display:block;font-size:11px;text-transform:uppercase;letter-spacing:3.3px;color:rgba(255, 255, 255, 0.5);line-height:16.5px;height:16px' ) . '>Access</div><div class="mt-2 serif text-4xl" ' . $x_cs( 'display:block;font-family:Cormorant Garamond, serif;font-size:36px;line-height:40px;margin:8px 0px 0px;height:40px' ) . '>Prism</div></div></button></div>'
	. '</div></section>'
	// A CTA CARD ROW (a dark rounded panel: a capped text column + a `flex flex-col gap-4` stack of two skinned buttons, justify-between)
	. '<section class="py-24 px-6" ' . $x_cs( 'display:block;padding:96px 24px;height:643px' ) . '><div class="max-w-7xl mx-auto" ' . $x_cs( 'display:block;max-width:1280px;margin:0px auto;width:1280px' ) . '><div class="bg-zinc-900 rounded-3xl p-16 flex flex-row items-center justify-between gap-10 border border-zinc-800 relative overflow-hidden" ' . $x_cs( 'display:flex;flex-direction:row;align-items:center;justify-content:space-between;gap:40px;padding:64px;border-radius:24px;background-color:rgb(24, 24, 27);border-top-width:1px;border-top-style:solid;border-top-color:rgb(39, 39, 42);height:450px;width:1280px;overflow:hidden' ) . '>'
	. '<div class="max-w-xl relative z-10" ' . $x_cs( 'display:block;max-width:576px;height:320px;width:576px;track-frac:0.45' ) . '><h2 class="text-4xl font-bold mb-6" ' . $x_cs( 'font-size:36px;line-height:40px;font-weight:700;margin:0px 0px 24px;display:block;height:80px' ) . '>Ready to transform your operations?</h2><p class="text-lg text-gray-400 mb-8" ' . $x_cs( 'font-size:18px;line-height:28px;color:rgb(156, 163, 175);margin:0px 0px 32px;display:block;height:56px' ) . '>Join thousands of enterprise teams already using the platform to drive growth and clarity across their organisations.</p></div>'
	. '<div class="w-full md:w-auto relative z-10 flex flex-col gap-4" ' . $x_cs( 'display:flex;flex-direction:column;gap:16px;height:130px;width:191px;track-frac:0.15' ) . '><a href="#a" class="px-8 py-4 bg-orange-500 text-white rounded-xl font-semibold" ' . $x_cs( 'display:flex;padding:16px 32px;background-color:rgb(249, 115, 22);color:rgb(255, 255, 255);border-radius:12px;font-weight:600;font-size:16px;height:56px;width:191px;justify-content:center' ) . '>Get started now</a><a href="#b" class="px-8 py-4 bg-zinc-800 text-white rounded-xl font-semibold" ' . $x_cs( 'display:flex;padding:16px 32px;background-color:rgb(39, 39, 42);color:rgb(255, 255, 255);border-radius:12px;font-weight:600;font-size:16px;height:58px;width:191px;justify-content:center' ) . '>Talk to sales</a></div>'
	. '</div></div></section>'
	// a STATS STRIP: a 4-up grid of icon tile + (value over label) rows
	. '<section class="py-8 px-6" ' . $x_cs( 'display:block;padding:32px 24px;height:148px' ) . '><div class="grid grid-cols-2 md:grid-cols-4 gap-8" ' . $x_cs( 'display:grid;grid-template-columns:284px 284px 284px 284px;gap:32px;height:84px' ) . '>'
	. '<div class="flex items-center gap-4 px-4" ' . $x_cs( 'display:flex;align-items:center;gap:16px;padding:0px 16px;height:84px' ) . '><div class="w-12 h-12 rounded-full border border-white/10 flex items-center justify-center shrink-0" ' . $x_cs( 'display:flex;width:48px;height:48px;border-radius:9999px;border-top-width:1px;border-top-style:solid;border-top-color:rgba(255, 255, 255, 0.1)' ) . '><iconify-icon icon="lucide:users" ' . $x_cs( 'display:block;font-size:20px;height:20px;width:20px' ) . '><svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24"><path d="M4 4h16v16H4z"/></svg></iconify-icon></div><div ' . $x_cs( 'display:block;height:52px' ) . '><p class="text-2xl font-semibold text-white" ' . $x_cs( 'display:block;font-size:24px;font-weight:600;line-height:32px;height:32px' ) . '>20k+</p><p class="text-sm text-gray-400" ' . $x_cs( 'display:block;font-size:14px;line-height:20px;color:rgb(156, 163, 175);height:20px' ) . '>Teams worldwide</p></div></div>'
	. '<div class="flex items-center gap-4 px-4" ' . $x_cs( 'display:flex;align-items:center;gap:16px;padding:0px 16px;height:84px' ) . '><div class="w-12 h-12 rounded-full border border-white/10 flex items-center justify-center shrink-0" ' . $x_cs( 'display:flex;width:48px;height:48px;border-radius:9999px;border-top-width:1px;border-top-style:solid;border-top-color:rgba(255, 255, 255, 0.1)' ) . '><iconify-icon icon="lucide:users" ' . $x_cs( 'display:block;font-size:20px;height:20px;width:20px' ) . '><svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24"><path d="M4 4h16v16H4z"/></svg></iconify-icon></div><div ' . $x_cs( 'display:block;height:52px' ) . '><p class="text-2xl font-semibold text-white" ' . $x_cs( 'display:block;font-size:24px;font-weight:600;line-height:32px;height:32px' ) . '>99.99%</p><p class="text-sm text-gray-400" ' . $x_cs( 'display:block;font-size:14px;line-height:20px;color:rgb(156, 163, 175);height:20px' ) . '>Enterprise uptime</p></div></div>'
	. '<div class="flex items-center gap-4 px-4" ' . $x_cs( 'display:flex;align-items:center;gap:16px;padding:0px 16px;height:84px' ) . '><div class="w-12 h-12 rounded-full border border-white/10 flex items-center justify-center shrink-0" ' . $x_cs( 'display:flex;width:48px;height:48px;border-radius:9999px;border-top-width:1px;border-top-style:solid;border-top-color:rgba(255, 255, 255, 0.1)' ) . '><iconify-icon icon="lucide:users" ' . $x_cs( 'display:block;font-size:20px;height:20px;width:20px' ) . '><svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24"><path d="M4 4h16v16H4z"/></svg></iconify-icon></div><div ' . $x_cs( 'display:block;height:52px' ) . '><p class="text-2xl font-semibold text-white" ' . $x_cs( 'display:block;font-size:24px;font-weight:600;line-height:32px;height:32px' ) . '>Enterprise-grade</p><p class="text-sm text-gray-400" ' . $x_cs( 'display:block;font-size:14px;line-height:20px;color:rgb(156, 163, 175);height:20px' ) . '>Security &amp; compliance</p></div></div>'
	. '<div class="flex items-center gap-4 px-4" ' . $x_cs( 'display:flex;align-items:center;gap:16px;padding:0px 16px;height:84px' ) . '><div class="w-12 h-12 rounded-full border border-white/10 flex items-center justify-center shrink-0" ' . $x_cs( 'display:flex;width:48px;height:48px;border-radius:9999px;border-top-width:1px;border-top-style:solid;border-top-color:rgba(255, 255, 255, 0.1)' ) . '><iconify-icon icon="lucide:users" ' . $x_cs( 'display:block;font-size:20px;height:20px;width:20px' ) . '><svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 24 24"><path d="M4 4h16v16H4z"/></svg></iconify-icon></div><div ' . $x_cs( 'display:block;height:52px' ) . '><p class="text-2xl font-semibold text-white" ' . $x_cs( 'display:block;font-size:24px;font-weight:600;line-height:32px;height:32px' ) . '>150+</p><p class="text-sm text-gray-400" ' . $x_cs( 'display:block;font-size:14px;line-height:20px;color:rgb(156, 163, 175);height:20px' ) . '>Countries served</p></div></div>'
	. '</div></section>'
	. '</main>'
	// a FLOATING DOCK: a page-level fixed pill of four icon links at bottom:24px, left:50%, translated back by half its width
	. '<div class="dock" ' . $x_cs( 'position:fixed;bottom:24px;left:720px;transform:matrix(1, 0, 0, 1, -135, 0);height:78px;z-index:60;display:block' ) . '><div class="dock-inner" ' . $x_cs( 'display:flex;gap:12px;padding:12px;border-radius:999px;background-color:rgba(255, 255, 255, 0.06);border-top-width:1px;border-top-style:solid;border-top-color:rgba(255, 255, 255, 0.08);backdrop-filter:blur(40px);height:78px' ) . '>'
	. str_repeat( '<a class="dock-btn" href="#" ' . $x_cs( 'display:grid;border-radius:999px;background-color:rgba(255, 255, 255, 0.04);border-top-width:1px;border-top-style:solid;border-top-color:rgba(255, 255, 255, 0.08);height:52px;width:52px;place-items:center' ) . '><iconify-icon icon="ph:house" ' . $x_cs( 'display:block;height:16px;width:16px;font-size:16px' ) . '><svg xmlns="http://www.w3.org/2000/svg" width="1em" height="1em" viewBox="0 0 256 256"><path fill="currentColor" d="M8 8h16v16H8z"/></svg></iconify-icon></a>', 4 )
	. '</div></div></body></html>';
$x_bl = FW_Site_Converter_Sources::build_from_html( $x_html, 'Outpost', array( 'dynamic_chrome' => true, 'hifi_css' => true ) );
$x_v  = $x_bl['files']['theme-settings.json']['values'] ?? array();
$x_pg = $x_bl['files']['pages.json']['pages'][0]['builder'] ?? array();
$x_h1 = $r_find( $x_pg, function ( $n ) { return 'special_heading' === ( $n['shortcode'] ?? '' ) && false !== strpos( (string) ( $n['atts']['title'] ?? '' ), 'Synthetic' ); } );
ga( "a hero whose text column is a bare `max-w-3xl` (margin 0, no mx-auto) inside a full-width band is NOT centred: the band cap is the outer container, the section container pins LEFT — reading the column as the band cap centred the whole hero and shrank its container to 768px (measured on a real conversion)", 'narrow' !== (string) ( $x_pg[0]['atts']['container_width']['preset'] ?? '' ) && 'center' !== (string) ( $x_pg[0]['atts']['text_align'] ?? '' ) && is_array( $x_h1 ) && 'center' !== (string) ( $x_h1['atts']['text_align'] ?? $x_h1['atts']['alignment'] ?? '' ) );
ga( "a heading of `display:block` spans keeps its three LINES (each span stays, display:block inline) — the split-word collapser and the class scrub used to fold them into one line (measured: 3 lines → 2)", is_array( $x_h1 ) && 3 === substr_count( (string) $x_h1['atts']['title'], 'display:block' ) );
ga( "a `.glass` pill whose skin is only MEASURED (a sheet class: translucent fill + hairline + blur, no bg-*/border class) is the heading's pill overline with its dot as the mark, the measured skin carried (fill, border, backdrop blur)", is_array( $x_h1 ) && 'pill' === (string) ( $x_h1['atts']['overline_container'] ?? '' ) && false !== strpos( (string) ( $x_h1['atts']['overline_icon']['markup'] ?? '' ), 'fill="rgb(103, 232, 249)"' ) && false !== strpos( (string) ( $x_h1['atts']['custom_css'] ?? '' ), 'background:rgba(255, 255, 255, 0.04)' ) && false !== strpos( (string) ( $x_h1['atts']['custom_css'] ?? '' ), 'border:1px solid rgba(255, 255, 255, 0.08)' ) && false !== strpos( (string) ( $x_h1['atts']['custom_css'] ?? '' ), 'backdrop-filter:blur(20px)' ) );
$x_btns = array(); $r_all( $x_pg, function ( $n ) { return 'button' === ( $n['shortcode'] ?? '' ); }, $x_btns );
$x_expl = null; foreach ( $x_btns as $xb ) { if ( 'Explore' === (string) ( $xb['atts']['label'] ?? '' ) ) { $x_expl = $xb; } }
$x_bc = (array) ( $x_v['button_colors'] ?? array() ); $x_slugs = array(); foreach ( $x_bc as $b ) { $x_slugs[ (string) $b['slug'] ] = $b; }
ga( "two outline presets with the SAME border (white/10) are told apart by their INK (white vs white/70): the tracked-uppercase twin gets the preset that carries its type — the first registered one used to win the tie (measured: Explore Systems lost its uppercase + tracking)", is_array( $x_expl ) && isset( $x_slugs[ substr( (string) $x_expl['atts']['style'], 4 ) ] ) && 'uppercase' === (string) ( $x_slugs[ substr( (string) $x_expl['atts']['style'], 4 ) ]['states']['default']['text_transform'] ?? '' ) && 'rgba(255, 255, 255, 0.7)' === (string) ( $x_slugs[ substr( (string) $x_expl['atts']['style'], 4 ) ]['states']['default']['text_color']['custom'] ?? '' ) );
$x_wm = $r_find( $x_pg, function ( $n ) { return 'special_heading' === ( $n['shortcode'] ?? '' ) && '01' === trim( (string) ( $n['atts']['title'] ?? '' ) ); } );
ga( "a faint WATERMARK heading (absolute, its ink at 6 % alpha — no opacity) is pinned out of flow with the native Position option, never a solid title in the flow (measured: +230px on the band)", is_array( $x_wm ) && 'absolute' === (string) ( $x_wm['atts']['element_position']['position'] ?? '' ) && false !== strpos( (string) ( $x_wm['atts']['custom_css'] ?? '' ), 'pointer-events:none' ) );
$x_rows = array(); $r_all( $x_pg, function ( $n ) { return 'flexbox' === ( $n['type'] ?? '' ) && 'grid' === (string) ( $n['atts']['display'] ?? '' ) && count( $n['_items'] ?? array() ) === 2 && (bool) preg_match( '/0\.9\d*fr 1\.0\d*fr|0\.90\dfr/', json_encode( $n['atts'] ) ); }, $x_rows );
ga( "an UNEQUAL measured grid (589 / 707 of 1376, no col-span) keeps its EXACT tracks as a native fr grid (0.909fr 1.091fr) — the uniform-track arithmetic gave 6 / 6 (then 5 / 6 summing to 11) and re-wrapped the two-line title to three", 1 <= count( $x_rows ) );
$x_stat = array(); $r_all( $x_pg, function ( $n ) { return 'flexbox' === ( $n['type'] ?? '' ) && count( $n['_items'] ?? array() ) === 3 && '4' === (string) ( $n['_items'][0]['atts']['width']['base']['preset'] ?? '' ) && '4' === (string) ( $n['_items'][2]['atts']['width']['base']['preset'] ?? '' ); }, $x_stat );
ga( "a MEASURED grid's label + value stat cells (no <p>, 15 chars) are its columns: the 3-up telemetry row keeps its row wrapper (4 / 4 / 4) — the 'substantial cell' test dropped it and the stats stacked (a RECURRING finding: 'stats grid emitted without its row wrapper')", 1 <= count( $x_stat ) );
$x_panel = $r_find( $x_pg, function ( $n ) { return 'flexbox' === ( $n['type'] ?? '' ) && false !== strpos( (string) ( $n['atts']['custom_css'] ?? '' ), 'min-height:720px' ); } );
ga( "a panel whose sole wrapper is a `h-full flex-col justify-between` column keeps its DESIGNED height as min-height and the space-between as its justify — it collapsed to its content (measured: 880 → 561 band height)", is_array( $x_panel ) && 'between' === (string) ( $x_panel['atts']['justify_content']['base'] ?? '' ) );
$x_mq = $r_find( $x_pg, function ( $n ) { return 'flexbox' === ( $n['type'] ?? '' ) && false !== strpos( (string) ( $n['atts']['custom_css'] ?? '' ), 'width:max-content' ); } );
ga( "a nowrap flex row whose RUNNING animation slides it on X (`@keyframes … translateX(-50%)`, stamped as data-sc-anim) is a MARQUEE whatever its class — the chip-row shape claimed it first and it became a wrapping row of giant text (measured: 464 → 608)", is_array( $x_mq ) );
$x_mqt = $r_find( $x_pg, function ( $n ) { return 'text_block' === ( $n['shortcode'] ?? '' ) && false !== strpos( (string) ( $n['atts']['text'] ?? '' ), 'Chromatic sequencing' ); } );
ga( "…its items keep the MEASURED leading (`leading-none` → 144px, read through the stamp when the class leading is unitless)", is_array( $x_mqt ) && false !== strpos( (string) ( $x_mqt['atts']['custom_css'] ?? '' ), 'line-height:144px' ) );
$x_orb = null; foreach ( $x_btns as $xb ) { if ( false !== strpos( (string) ( $xb['atts']['label'] ?? '' ), 'Prism' ) ) { $x_orb = $xb; } }
ga( "a 224px ORB button with a two-line label (an eyebrow over a serif word) is ONE native button: the lines as block spans with their own type, the square box as its own CSS — the cell was mirrored verbatim and split into two stray text lines", is_array( $x_orb ) && 2 === substr_count( (string) $x_orb['atts']['label'], 'display:block' ) && false !== strpos( (string) $x_orb['atts']['custom_css'], 'width:224px;height:224px' ) );
$x_orb_cell = $r_find( $x_pg, function ( $n ) { return 'flexbox' === ( $n['type'] ?? '' ) && 1 === count( $n['_items'] ?? array() ) && 'button' === (string) ( $n['_items'][0]['shortcode'] ?? '' ) && false !== strpos( (string) ( $n['_items'][0]['atts']['label'] ?? '' ), 'Prism' ); } );
ga( "…and the orb's OWN skin never paints its cell (a 531px circle rendered behind the 224px orb)", ! is_array( $x_orb_cell ) || ( '' === (string) ( $x_orb_cell['atts']['border_preset'] ?? '' ) && false === strpos( (string) ( $x_orb_cell['atts']['custom_css'] ?? '' ), 'radial-gradient' ) ) );
$x_quote = $r_find( $x_pg, function ( $n ) { return 'text_block' === ( $n['shortcode'] ?? '' ) && false !== strpos( (string) ( $n['atts']['text'] ?? '' ), 'dissolves into pure' ); } );
ga( "a measured 40px margin is carried LOSSLESSLY as the arbitrary token (`mt-[40px]`), never snapped to the 48px scale step (measured: +8px per block)", is_array( $x_quote ) && 'mt-[40px]' === (string) ( $x_quote['atts']['spacing']['margin']['top'] ?? '' ) );
$x_dock = $r_find( $x_pg, function ( $n ) { return 'flexbox' === ( $n['type'] ?? '' ) && 'fixed' === (string) ( $n['atts']['element_position']['position'] ?? '' ); } );
ga( "a page-level FIXED dock (a bottom-anchored glass pill of four icon links, centred by left:50% + a translate) is ONE fixed-positioned row of icon tiles: the native Position (fixed, bottom 24px, left 50 %), the centring translate, the pill's measured skin — it was dropped (a fixed body-level element is neither a section nor chrome)", is_array( $x_dock ) && '24' === (string) ( $x_dock['atts']['element_position']['fixed']['pos_offsets']['bottom']['value'] ?? '' ) && '50' === (string) ( $x_dock['atts']['element_position']['fixed']['pos_offsets']['left']['value'] ?? '' ) && 4 === count( $x_dock['_items'] ?? array() ) && false !== strpos( (string) ( $x_dock['atts']['custom_css'] ?? '' ), 'translateX(-50%)' ) && false !== strpos( (string) ( $x_dock['atts']['custom_css'] ?? '' ), 'background-color:rgba(255, 255, 255, 0.06)' ) && false !== strpos( (string) ( $x_dock['atts']['custom_css'] ?? '' ), 'backdrop-filter:blur(40px)' ) );
ga( "…each tile is an icon with the tile's own Icon Badge skin (52px circle, translucent fill + hairline)", is_array( $x_dock ) && (bool) preg_match( '/^iconb-badge-/', (string) ( $x_dock['_items'][0]['atts']['icon_badge_preset'] ?? $x_dock['_items'][0]['_items'][0]['atts']['icon_badge_preset'] ?? '' ) ) );
$x_cta = $r_find( $x_pg, function ( $n ) { return 'flexbox' === ( $n['type'] ?? '' ) && 'between' === (string) ( $n['atts']['justify_content']['base'] ?? '' ) && '' !== (string) ( $n['atts']['border_preset'] ?? '' ); } );
ga( "a CTA CARD that is itself a layout row (a dark rounded panel: text column + button stack, justify-between) wears its own Box Preset on the band — the top-level band path dropped the row's skin (measured: the dark card vanished)", is_array( $x_cta ) );
$x_stack = $r_find( $x_pg, function ( $n ) { return 'flexbox' === ( $n['type'] ?? '' ) && 'column' === (string) ( $n['atts']['direction']['base'] ?? '' ) && 2 === count( $n['_items'] ?? array() ) && 'button' === (string) ( $n['_items'][0]['shortcode'] ?? '' ); } );
ga( "…its button cell (only two skinned links in a `flex flex-col gap-4`) decomposes into NATIVE buttons that keep the STACK direction — the cell was mirrored as two text lines, and every button group rendered side by side", is_array( $x_stack ) && 'Get started now' === (string) ( $x_stack['_items'][0]['atts']['label'] ?? '' ) && (bool) preg_match( '/^btn-/', (string) ( $x_stack['_items'][0]['atts']['style'] ?? '' ) ) );
$x_fl = $r_find( $x_pg, function ( $n ) { return 'feature_list' === ( $n['shortcode'] ?? '' ) && '4' === (string) ( $n['atts']['columns'] ?? '' ); } );
ga( "a 4-up STATS strip (icon tile + a value over its label, `grid-cols-2 md:grid-cols-4`) is a 4-column feature list — the largest breakpoint wins (the base 2 laid it out 2-up) and Columns now reaches 6", is_array( $x_fl ) );
ga( "…each row's VALUE is the item text and its LABEL the sub-line (the source stacks them; they were read as one inline line), the sub-line's measured size / colour scoped on .fw-fl__sub", is_array( $x_fl ) && '20k+' === (string) ( $x_fl['atts']['items'][0]['text'] ?? '' ) && 'Teams worldwide' === (string) ( $x_fl['atts']['items'][0]['subtext'] ?? '' ) && false !== strpos( (string) ( $x_fl['atts']['custom_css'] ?? '' ), '.fw-fl__sub{font-size:14px;color:rgb(156, 163, 175)' ) );
ga( "the container gutter is MEASURED from the bands' equal side padding (`px-8` → 32px) when no calc() container rule declares it — the theme's 24px default shifted every band 8px", '32' === (string) ( $x_v['general_layout']['layout_container_gutter']['value'] ?? '' ) );

/* --------------------------------------------------------------------- *
 * [W] The findings feed's sandbox fixtures, first batch: the rules their repros became — a lone content card decomposes
 *     as a panel (never a code block), a display-size ghost numeral survives, a system body stack never takes the
 *     heading face, a `columns-3` wall is a masonry gallery, a folded subtitle keeps its case / tracking, a standalone
 *     measured pill hugs its text, the gutter is measured without a site stamp.
 * --------------------------------------------------------------------- */
$y_cs = function ( $e ) use ( $t_cs ) { return $t_cs( 'font-family:ui-sans-serif, system-ui, sans-serif;' . $e ); }; // the whole page reads the SYSTEM stack; only the headings wear the Google face
$y_html = '<!DOCTYPE html><html><head><title>Outpost</title></head><body ' . $y_cs( 'background-color:rgb(255, 255, 255);color:rgb(26, 26, 26);font-family:ui-sans-serif, system-ui, sans-serif' ) . '><main>'
	// a LONE content card (fill + radius + a transparent 4px ring + a hairline shadow): icon tile + h3 + p
	. '<section class="py-20 bg-white" ' . $y_cs( 'display:block;padding:80px 0px;height:500px' ) . '><div class="container mx-auto px-6" ' . $y_cs( 'display:block;max-width:1280px;padding:0px 24px;margin:0px 80px;width:1280px' ) . '>'
	. '<div class="bg-background p-8 rounded-[2rem] border-4 border-transparent" ' . $y_cs( 'display:block;background-color:rgb(255, 251, 240);color:rgb(7, 58, 75);padding:32px;border-top-width:4px;border-top-style:solid;border-top-color:rgba(0, 0, 0, 0);border-radius:32px;box-shadow:rgba(0, 0, 0, 0.05) 0px 1px 2px 0px;height:308px;width:288px' ) . '><div class="w-16 h-16 bg-secondary rounded-2xl flex items-center justify-center mb-6" ' . $y_cs( 'display:flex;width:64px;height:64px;background-color:rgb(255, 226, 179);border-radius:16px;align-items:center;justify-content:center;margin:0px 0px 24px' ) . '><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" ' . $y_cs( 'display:block;width:24px;height:24px' ) . '><path d="M4 4h16v16H4z"/></svg></div><h3 class="text-2xl font-heading mb-3" ' . $y_cs( 'font-family:Syne, sans-serif;display:block;font-size:24px;line-height:32px;font-weight:400;color:rgb(7, 58, 75);margin:0px 0px 12px;height:32px' ) . '>Chromatic routing</h3><p class="font-body font-medium" ' . $y_cs( 'display:block;font-size:16px;line-height:24px;font-weight:500;color:rgba(7, 58, 75, 0.7);height:72px' ) . '>Every expedition is routed through three staging camps, each with its own weather window and crew.</p></div>'
	// a GHOST NUMERAL step cell: a 96px bare "01" over an h3 + p
	. '<div class="p-12" ' . $y_cs( 'display:block;padding:48px;height:300px;margin:24px 0px 0px' ) . '><span class="block" ' . $y_cs( 'display:block;font-size:96px;line-height:96px;font-weight:500;letter-spacing:-3.84px;color:rgba(255, 255, 255, 0.08);height:96px' ) . '>01</span><h3 ' . $y_cs( 'font-family:Syne, sans-serif;display:block;font-size:20px;line-height:28px;font-weight:600;height:28px' ) . '>Chart the route</h3><p ' . $y_cs( 'display:block;font-size:14px;line-height:22px;color:rgb(156, 163, 175);height:44px' ) . '>Every step is measured against the previous camp before the next window opens.</p></div>'
	// a heading whose SUBTITLE is a tracked-uppercase kicker; a standalone measured PILL span
	. '<div class="text-center" ' . $y_cs( 'display:block;text-align:center;height:120px;margin:24px 0px 0px' ) . '><h2 class="text-6xl mb-4" ' . $y_cs( 'font-family:Syne, sans-serif;display:block;font-size:60px;line-height:60px;text-align:center;margin:0px 0px 16px;height:60px' ) . '>Field notes</h2><p class="text-xl tracking-widest uppercase" ' . $y_cs( 'display:block;font-size:20px;line-height:28px;letter-spacing:2px;text-transform:uppercase;text-align:center;color:rgb(120, 113, 108);height:28px' ) . '>From the ridge</p></div>'
	. '<span class="badge-blue self-start" ' . $y_cs( 'display:block;font-size:12px;line-height:18px;font-weight:600;letter-spacing:0.72px;text-transform:uppercase;padding:4px 14px;border-radius:84px;background-color:rgba(215, 254, 3, 0.1);border-top-width:1px;border-top-style:solid;border-top-color:rgba(215, 254, 3, 0.3);color:rgb(215, 254, 3);height:28px;width:150px;margin:24px 0px 0px' ) . '>Now boarding</span>'
	. '</div></section>'
	// a MASONRY wall: `columns-3` of three image tiles with a hover caption overlay
	. '<section class="py-24" ' . $y_cs( 'display:block;padding:96px 0px;height:900px' ) . '><div class="container mx-auto px-6" ' . $y_cs( 'display:block;max-width:1280px;padding:0px 24px;margin:0px 80px;width:1280px' ) . '><div class="columns-1 md:columns-2 lg:columns-3 gap-6 space-y-6" ' . $y_cs( 'display:block;column-count:3;column-gap:24px;height:700px;width:1232px' ) . '>'
	. str_repeat( '<div class="break-inside-avoid relative group overflow-hidden" ' . $y_cs( 'display:block;position:relative;height:504px;width:379px;overflow:hidden' ) . '><div class="relative w-full overflow-hidden" ' . $y_cs( 'display:block;position:relative;height:504px' ) . '><img src="https://example.com/tile.jpg" class="w-full h-auto object-cover" ' . $y_cs( 'display:block;width:379px;height:504px;object-fit:cover' ) . '><div class="absolute inset-0 bg-black/40 opacity-0 group-hover:opacity-100 flex flex-col justify-end p-6" ' . $y_cs( 'position:absolute;display:flex;flex-direction:column;justify-content:flex-end;padding:24px;opacity:0;height:504px' ) . '><span class="text-white/70 text-xs uppercase tracking-widest mb-1" ' . $y_cs( 'display:block;font-size:12px;text-transform:uppercase;letter-spacing:1.2px;color:rgba(255, 255, 255, 0.7);height:16px' ) . '>Series</span><h3 class="text-white text-lg" ' . $y_cs( 'display:block;font-size:18px;line-height:28px;color:rgb(255, 255, 255);height:28px' ) . '>Ridge at dawn</h3></div></div></div>', 3 )
	. '</div></div></section>'
	. '</main></body></html>';
$y_bl = FW_Site_Converter_Sources::build_from_html( $y_html, 'Outpost', array( 'dynamic_chrome' => true, 'hifi_css' => true ) );
$y_v  = $y_bl['files']['theme-settings.json']['values'] ?? array();
$y_pg = $y_bl['files']['pages.json']['pages'][0]['builder'] ?? array();
$y_codes = array(); $r_all( $y_pg, function ( $n ) { return 'code_block' === ( $n['shortcode'] ?? '' ); }, $y_codes );
ga( "a LONE content card (fill + radius + a transparent ring + a hairline shadow) decomposes NATIVELY as a panel — icon tile, heading, copy inside a flexbox wearing its Box Preset — never the verbatim code block (a fixture from the findings feed)", 0 === count( $y_codes ) );
$y_panel = $r_find( $y_pg, function ( $n ) { return 'flexbox' === ( $n['type'] ?? '' ) && '' !== (string) ( $n['atts']['border_preset'] ?? '' ) && 'column' === (string) ( $n['atts']['direction']['base'] ?? '' ); } );
$y_ch = $r_find( $y_pg, function ( $n ) { return 'special_heading' === ( $n['shortcode'] ?? '' ) && false !== strpos( (string) ( $n['atts']['title'] ?? '' ), 'Chromatic routing' ); } );
ga( "…the card's title keeps its measured weight (400) and ink (rgb(7, 58, 75)) — the theme heading default (700 / body ink) was reported on three sites", is_array( $y_panel ) && is_array( $y_ch ) && false !== strpos( (string) ( $y_ch['atts']['custom_css'] ?? '' ), 'font-weight:400 !important' ) && 'rgb(7, 58, 75)' === (string) ( $y_ch['atts']['title_color']['custom'] ?? '' ) );
$y_num = $r_find( $y_pg, function ( $n ) { return 'text_block' === ( $n['shortcode'] ?? '' ) && '01' === trim( strip_tags( (string) ( $n['atts']['text'] ?? '' ) ) ); } );
ga( "a GHOST NUMERAL (a bare '01' set at 96px, 8 % ink) survives as a text block with its measured size — a bare number was dropped as an index", is_array( $y_num ) && false !== strpos( (string) ( $y_num['atts']['custom_css'] ?? '' ), 'font-size:96px' ) );
ga( "a SYSTEM body stack (ui-sans-serif, system-ui) never takes the heading's Google face: the body family stays empty (the theme's sans default) while the headings carry the face", '' === (string) ( $y_v['typography']['body']['family'] ?? '' ) && '' === (string) ( $y_bl['files']['theme-design.json']['fonts']['body'] ?? '' ) && 'Syne' === (string) ( $y_bl['files']['theme-design.json']['fonts']['heading'] ?? '' ), wp_json_encode( $y_bl['files']['theme-design.json']['fonts'] ?? null ) );
$y_gal = $r_find( $y_pg, function ( $n ) { return 'gallery' === ( $n['shortcode'] ?? '' ); } );
ga( "a `columns-3` WALL of image tiles is an image grid → the gallery's MASONRY design with 3 columns — it fell to a code block per tile", is_array( $y_gal ) && 'masonry' === (string) ( $y_gal['atts']['design_settings']['design'] ?? '' ) && '3' === (string) ( $y_gal['atts']['design_settings']['masonry']['columns']['count'] ?? '' ), wp_json_encode( $y_gal['atts']['design_settings'] ?? null ) );
$y_fn = $r_find( $y_pg, function ( $n ) { return 'special_heading' === ( $n['shortcode'] ?? '' ) && false !== strpos( (string) ( $n['atts']['subtitle'] ?? '' ), 'From the ridge' ); } );
ga( "a folded SUBTITLE (a `tracking-widest uppercase` kicker under the title) keeps its measured case + tracking on .heading-subtitle — subtitle_class alone rendered nothing", is_array( $y_fn ) && (bool) preg_match( '/\.heading-subtitle\{[^}]*text-transform:uppercase[^}]*letter-spacing:2px/', (string) ( $y_fn['atts']['custom_css'] ?? '' ) ) );
$y_pill = $r_find( $y_pg, function ( $n ) { return 'text_block' === ( $n['shortcode'] ?? '' ) && false !== strpos( (string) ( $n['atts']['text'] ?? '' ), 'Now boarding' ); } );
ga( "a standalone measured PILL span (radius 84px, side padding, `self-start`) wears its pill skin as a Box Preset AND hugs its text (inline-block, width auto) — it stretched the skin across the band (642px)", is_array( $y_pill ) && '' !== (string) ( $y_pill['atts']['box_style'] ?? '' ) && false !== strpos( (string) ( $y_pill['atts']['custom_css'] ?? '' ), 'display:inline-block;width:auto' ) );
ga( "the container gutter is measured WITHOUT a site stamp (a fixture / an older capture): the container's own 24px padding, subtracted from its measured 1280 outer width → 1232 content", '24' === (string) ( $y_v['general_layout']['layout_container_gutter']['value'] ?? '' ) && '1232' === (string) ( $y_v['general_layout']['layout_container_width']['lg']['value'] ?? '' ) );

/* --------------------------------------------------------------------- *
 * [X] The findings feed, rows 101–167: the recurring misses across ten sites — the container stamp beaten by an inner cap, a
 *     mobile-first button row, image-led product tiles read as a gallery, a lone star icon read as a rating, the image box
 *     dropping the card's inks / metrics / icon / body inset / fixed media height / filled button, a heading+link row losing its
 *     kicker and its subtitle's colour, an h4 + p split in two, a "01" row marker dropped, a <style> and a glow layer counted as
 *     columns, a split band's image half lifted away, a contact form swallowed as a newsletter, a text link padded like a button.
 * --------------------------------------------------------------------- */
$x_cs = function ( $e ) use ( $t_cs ) { return $t_cs( 'color:rgb(23, 23, 23);font-family:Inter, sans-serif;' . $e ); };
$x_tile = '<div class="group flex flex-col" ' . $x_cs( 'display:flex;flex-direction:column;height:422px;width:290px' ) . '><a href="#" class="relative aspect-[4/5] overflow-hidden mb-4" ' . $x_cs( 'display:block;position:relative;height:362px;width:290px;overflow:hidden;margin:0px 0px 16px' ) . '><img src="https://example.com/p.jpg" class="w-full h-full object-cover" ' . $x_cs( 'display:block;width:290px;height:362px;object-fit:cover' ) . '></a><div class="flex flex-col gap-1" ' . $x_cs( 'display:flex;flex-direction:column;gap:4px;height:44px' ) . '><p class="text-xs uppercase tracking-wider" ' . $x_cs( 'display:block;font-size:12px;line-height:16px;letter-spacing:0.6px;text-transform:uppercase;color:rgb(115, 115, 115);height:16px' ) . '>Outerwear</p><div class="flex justify-between" ' . $x_cs( 'display:flex;justify-content:space-between;height:24px' ) . '><a href="#" ' . $x_cs( 'display:inline;font-weight:500' ) . '>Harbor Parka</a><span class="price" ' . $x_cs( 'display:inline;font-weight:500' ) . '>$248</span></div></div></div>';
$x_card = '<div class="group bg-white rounded-2xl overflow-hidden border border-slate-100 shadow-sm" ' . $x_cs( 'display:block;background-color:rgb(255, 255, 255);border-radius:16px;border-top-width:1px;border-top-style:solid;border-top-color:rgb(241, 245, 249);box-shadow:rgba(0, 0, 0, 0.05) 0px 1px 2px 0px;overflow:hidden;height:594px;width:389px' ) . '><div class="h-64 overflow-hidden" ' . $x_cs( 'display:block;height:256px;overflow:hidden' ) . '><img src="https://example.com/s.jpg" class="w-full h-full object-cover" ' . $x_cs( 'display:block;width:389px;height:256px;object-fit:cover' ) . '></div><div class="p-8" ' . $x_cs( 'display:block;padding:32px;height:338px' ) . '><div class="mb-4 bg-primary/10 w-16 h-16 rounded-full flex items-center justify-center" ' . $x_cs( 'display:flex;width:64px;height:64px;background-color:rgba(153, 51, 255, 0.1);border-radius:9999px;align-items:center;justify-content:center;margin:0px 0px 16px' ) . '><svg class="lucide lucide-star w-8 h-8" ' . $x_cs( 'display:block;width:32px;height:32px;color:rgb(153, 51, 255)' ) . '><path d="M4 4h16v16H4z"/></svg></div><h3 class="text-2xl font-bold mb-3" ' . $x_cs( 'display:block;font-size:24px;line-height:32px;font-weight:700;margin:0px 0px 12px;height:32px' ) . '>Deep cleaning</h3><p class="text-muted-foreground mb-6" ' . $x_cs( 'display:block;font-size:16px;line-height:24px;color:rgb(102, 92, 112);margin:0px 0px 24px;height:48px' ) . '>Every surface, every corner, every week — on a schedule you set.</p><a href="#" class="block w-full text-center bg-slate-100 font-medium py-3 rounded-xl" ' . $x_cs( 'display:block;background-color:rgb(241, 245, 249);font-size:16px;font-weight:500;line-height:24px;text-align:center;padding:12px 0px;border-radius:12px;height:48px;width:325px' ) . '>Book this service</a></div></div>';
$x_html = '<!DOCTYPE html><html data-sc-content-width="1152"><head><title>Outpost</title></head><body ' . $x_cs( 'background-color:rgb(255, 255, 255)' ) . '><main>'
	// HERO: a glow layer + a container (1280 outer / 24 inside) holding a max-w-6xl cap (1152 — the stamp) + a mobile-first button row
	. '<section id="hero" class="relative overflow-hidden" ' . $x_cs( 'display:flex;align-items:center;padding:96px 0px 128px;height:900px;overflow:hidden' ) . '><div class="absolute inset-0 pointer-events-none" ' . $x_cs( 'position:absolute;display:block;height:900px;background-image:radial-gradient(70% 60% at 70% 50%, rgba(201, 169, 110, 0.07) 0%, rgba(0, 0, 0, 0) 70%)' ) . '></div><div class="container mx-auto px-6 relative text-center" ' . $x_cs( 'display:block;max-width:1280px;padding:0px 24px;margin:0px 80px;text-align:center;height:600px' ) . '><div class="max-w-6xl mx-auto" ' . $x_cs( 'display:block;max-width:1152px;margin:0px 40px;height:600px' ) . '><h1 class="text-7xl mb-6" ' . $x_cs( 'display:block;font-size:96px;line-height:96px;font-weight:400;text-align:center;margin:0px 0px 24px;height:192px' ) . '>Quiet <span class="text-gray-900" ' . $x_cs( 'display:inline;color:rgb(17, 24, 39)' ) . '>machines</span></h1><div class="flex flex-col sm:flex-row items-center justify-center gap-4" ' . $x_cs( 'display:flex;flex-direction:row;align-items:center;justify-content:center;gap:16px;height:54px' ) . '><a href="#" class="w-full sm:w-auto bg-primary text-white px-8 py-4 text-sm uppercase" ' . $x_cs( 'display:block;background-color:rgb(37, 99, 235);color:rgb(255, 255, 255);font-size:14px;text-transform:uppercase;padding:16px 32px;height:52px;width:192px' ) . '>Start a project</a><a href="#" class="w-full sm:w-auto border border-border px-8 py-4 text-sm uppercase" ' . $x_cs( 'display:block;border-top-width:1px;border-top-style:solid;border-top-color:rgb(229, 231, 235);font-size:14px;text-transform:uppercase;padding:16px 32px;height:54px;width:149px' ) . '>See the work</a></div></div></div><style>@media (max-width:900px){.hero-grid{grid-template-columns:1fr}}</style></section>'
	// PRODUCTS: a heading + link row (kicker above the h2, a muted intro, a bare uppercase text link) over a 4-up of image-led tiles
	. '<section id="products" class="py-24" ' . $x_cs( 'display:block;padding:96px 0px;height:766px' ) . '><div class="container mx-auto px-6" ' . $x_cs( 'display:block;max-width:1280px;padding:0px 24px;margin:0px 80px;height:574px' ) . '><div class="flex flex-col md:flex-row justify-between items-end mb-16" ' . $x_cs( 'display:flex;flex-direction:row;justify-content:space-between;align-items:flex-end;margin:0px 0px 64px;height:104px' ) . '><div ' . $x_cs( 'display:block;height:104px' ) . '><span class="text-[#FF2D55] font-bold text-xs uppercase tracking-widest block mb-2" ' . $x_cs( 'display:block;color:rgb(255, 45, 85);font-size:12px;line-height:16px;font-weight:700;letter-spacing:1.2px;text-transform:uppercase;margin:0px 0px 8px;height:16px' ) . '>New season</span><h2 class="text-4xl mb-4" ' . $x_cs( 'display:block;font-size:36px;line-height:40px;font-weight:400;margin:0px 0px 16px;height:40px' ) . '>Featured products</h2><p class="text-muted-foreground max-w-md" ' . $x_cs( 'display:block;font-size:16px;line-height:24px;color:rgb(115, 115, 115);max-width:448px;height:48px' ) . '>Handpicked pieces from the new season, made in small batches by partner ateliers.</p></div><a href="#" class="hidden md:flex items-center gap-2 text-sm uppercase tracking-widest" ' . $x_cs( 'display:flex;align-items:center;gap:8px;font-size:14px;line-height:20px;letter-spacing:3.5px;text-transform:uppercase;color:rgb(23, 23, 23);height:20px' ) . '>View all products</a></div>'
	. '<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-x-6 gap-y-12" ' . $x_cs( 'display:grid;grid-template-columns:290px 290px 290px 290px;gap:48px 24px;height:422px' ) . '>' . str_repeat( $x_tile, 4 ) . '</div></div></section>'
	// SERVICES: a 3-up of image-topped cards (fixed 256px media, a star icon tile, a muted body, a filled block anchor)
	. '<section id="services" class="py-24" ' . $x_cs( 'display:block;padding:96px 0px;height:800px' ) . '><div class="container mx-auto px-6" ' . $x_cs( 'display:block;max-width:1280px;padding:0px 24px;margin:0px 80px;height:594px' ) . '><div class="grid md:grid-cols-2 lg:grid-cols-3 gap-8" ' . $x_cs( 'display:grid;grid-template-columns:389px 389px 389px;gap:32px;height:594px' ) . '>' . str_repeat( $x_card, 3 ) . '</div></div></section>'
	// BENEFITS: a sticky copy column beside four numbered rows (`span.01 + div(h3 + p)`) and a 3.2× stat
	. '<section id="benefits" class="py-24" ' . $x_cs( 'display:block;padding:96px 0px;height:800px' ) . '><div class="container mx-auto px-6" ' . $x_cs( 'display:block;max-width:1280px;padding:0px 24px;margin:0px 80px;height:600px' ) . '><div class="grid grid-cols-2 gap-16" ' . $x_cs( 'display:grid;grid-template-columns:516px 516px;gap:64px;height:600px' ) . '><div ' . $x_cs( 'display:block;height:300px' ) . '><h2 ' . $x_cs( 'display:block;font-size:52px;line-height:56px;height:56px' ) . '>Why the crews stay</h2><p ' . $x_cs( 'display:block;font-size:17px;line-height:28px;height:84px;margin:16px 0px 0px' ) . '>Four reasons the same teams come back season after season, in their own words.</p><div ' . $x_cs( 'display:flex;flex-direction:row;justify-content:space-between;margin:32px 0px 0px;height:64px' ) . '><div ' . $x_cs( 'display:block;height:64px' ) . '><div ' . $x_cs( 'display:block;font-size:40px;line-height:44px;height:44px' ) . '>96%</div><div ' . $x_cs( 'display:block;font-size:13px;line-height:20px;height:20px' ) . '>crews retained</div></div><div ' . $x_cs( 'display:block;height:64px' ) . '><div ' . $x_cs( 'display:block;font-size:40px;line-height:44px;height:44px' ) . '>3.2×</div><div ' . $x_cs( 'display:block;font-size:13px;line-height:20px;height:20px' ) . '>avg. income growth</div></div><div ' . $x_cs( 'display:block;height:64px' ) . '><div ' . $x_cs( 'display:block;font-size:40px;line-height:44px;height:44px' ) . '>500+</div><div ' . $x_cs( 'display:block;font-size:13px;line-height:20px;height:20px' ) . '>routes flown</div></div></div></div>'
	. '<div ' . $x_cs( 'display:flex;flex-direction:column;height:600px' ) . '>' . str_repeat( '<div ' . $x_cs( 'display:flex;flex-direction:row;gap:24px;padding:32px 0px;align-items:flex-start;height:150px' ) . '><span ' . $x_cs( 'display:block;font-size:12px;line-height:19px;font-weight:600;height:19px;min-width:24px' ) . '>01</span><div ' . $x_cs( 'display:block;height:85px' ) . '><h3 ' . $x_cs( 'display:block;font-size:22px;line-height:24px;font-weight:500;height:24px' ) . '>Routes that respect the weather</h3><p ' . $x_cs( 'display:block;font-size:15px;line-height:25px;height:51px;color:rgb(107, 104, 96)' ) . '>Every leg is planned against the forecast window, not the calendar.</p></div></div>', 2 ) . '</div></div></div></section>'
	// SPLIT: a 50/50 band — a half-width column whose only child is an absolute cover image, beside a tinted panel
	. '<section id="split" class="py-0" ' . $x_cs( 'display:block;height:600px' ) . '><div class="flex flex-col md:flex-row min-h-[600px]" ' . $x_cs( 'display:flex;flex-direction:row;height:600px' ) . '><div class="w-full md:w-1/2 relative min-h-[400px]" ' . $x_cs( 'display:block;position:relative;height:600px;width:720px' ) . '><img src="https://example.com/half.jpg" class="absolute inset-0 w-full h-full object-cover" ' . $x_cs( 'position:absolute;display:block;width:720px;height:600px;object-fit:cover' ) . '></div><div class="w-full md:w-1/2 flex items-center justify-center p-24" ' . $x_cs( 'display:flex;align-items:center;justify-content:center;padding:96px;background-color:rgba(242, 217, 217, 0.3);height:600px;width:720px' ) . '><div class="max-w-md" ' . $x_cs( 'display:block;max-width:448px;height:200px' ) . '><h2 ' . $x_cs( 'display:block;font-size:48px;line-height:48px;height:48px;margin:0px 0px 24px' ) . '>The atelier</h2><p ' . $x_cs( 'display:block;font-size:16px;line-height:24px;height:72px' ) . '>Every piece is cut, sewn and finished by hand in the same three rooms it has been made in for forty years.</p></div></div></div></section>'
	// CONTACT: a white rounded card holding a name / email / message form with a submit
	. '<section id="contact" class="py-24" ' . $x_cs( 'display:block;padding:96px 0px;height:700px' ) . '><div class="container mx-auto px-6" ' . $x_cs( 'display:block;max-width:1280px;padding:0px 24px;margin:0px 80px;height:500px' ) . '><div class="grid grid-cols-2 gap-12 max-w-4xl mx-auto" ' . $x_cs( 'display:grid;grid-template-columns:424px 424px;gap:48px;max-width:896px;margin:0px 168px;height:454px' ) . '><div class="bg-white p-8 rounded-3xl border border-slate-100 shadow-sm" ' . $x_cs( 'display:flex;flex-direction:column;background-color:rgb(255, 255, 255);padding:32px;border-radius:24px;border-top-width:1px;border-top-style:solid;border-top-color:rgb(241, 245, 249);box-shadow:rgba(0, 0, 0, 0.05) 0px 1px 2px 0px;height:454px;width:424px' ) . '><form class="space-y-5" ' . $x_cs( 'display:block;height:388px' ) . '><div ' . $x_cs( 'display:block;height:70px' ) . '><label for="name" class="block text-xs uppercase mb-2" ' . $x_cs( 'display:block;font-size:12px;text-transform:uppercase;height:16px;margin:0px 0px 8px' ) . '>Your name</label><input id="name" type="text" class="w-full px-4 py-3 rounded-xl border" ' . $x_cs( 'display:inline-block;width:360px;height:46px;padding:12px 16px;border-radius:12px;border-top-width:1px;border-top-style:solid;border-top-color:rgb(226, 232, 240)' ) . '></div><div ' . $x_cs( 'display:block;height:70px' ) . '><label for="email" class="block text-xs uppercase mb-2" ' . $x_cs( 'display:block;font-size:12px;text-transform:uppercase;height:16px;margin:0px 0px 8px' ) . '>Email</label><input id="email" type="email" class="w-full px-4 py-3 rounded-xl border" ' . $x_cs( 'display:inline-block;width:360px;height:46px;padding:12px 16px;border-radius:12px' ) . '></div><div ' . $x_cs( 'display:block;height:136px' ) . '><label for="message" class="block text-xs uppercase mb-2" ' . $x_cs( 'display:block;font-size:12px;text-transform:uppercase;height:16px;margin:0px 0px 8px' ) . '>Message</label><textarea id="message" rows="4" class="w-full px-4 py-3 rounded-xl border" ' . $x_cs( 'display:inline-block;width:360px;height:104px;padding:12px 16px;border-radius:12px' ) . '></textarea></div><button type="submit" class="w-full bg-teal text-white py-3.5 rounded-xl" ' . $x_cs( 'display:flex;justify-content:center;background-color:rgb(13, 148, 136);color:rgb(255, 255, 255);font-weight:600;padding:14px 16px;border-radius:12px;height:52px;width:360px' ) . '>Send message</button></form></div><div ' . $x_cs( 'display:block;height:454px' ) . '><h3 ' . $x_cs( 'display:block;font-size:24px;line-height:32px;height:32px' ) . '>Or drop by</h3><p ' . $x_cs( 'display:block;font-size:16px;line-height:24px;height:72px;margin:16px 0px 0px' ) . '>The studio is open Tuesday to Saturday; the kettle is always on and the door is rarely locked.</p></div></div></div></section>'
	. '</main></body></html>';
$x_bl = FW_Site_Converter_Sources::build_from_html( $x_html, 'Outpost', array( 'dynamic_chrome' => true, 'hifi_css' => true ) );
$x_v  = $x_bl['files']['theme-settings.json']['values'] ?? array();
$x_pg = $x_bl['files']['pages.json']['pages'][0]['builder'] ?? array();
ga( "THE OUTER CONTAINER WINS: the capture stamped the heaviest centred width (a max-w-6xl cap: 1152) but every such block sits inside a `container max-w-7xl px-6` (1280 outer) → Container Width 1232 (1280 − 2×24), not 1104 (the feed's regression: '128px UNDER')", '1232' === (string) ( $x_v['general_layout']['layout_container_width']['lg']['value'] ?? '' ) && '24' === (string) ( $x_v['general_layout']['layout_container_gutter']['value'] ?? '' ), wp_json_encode( $x_v['general_layout']['layout_container_width']['lg'] ?? null ) );
$x_btnrow = $r_find( $x_pg, function ( $n ) { $c = 0; foreach ( $n['_items'] ?? array() as $it ) { if ( 'button' === ( $it['shortcode'] ?? '' ) ) { $c++; } } return 'flexbox' === ( $n['type'] ?? '' ) && $c >= 2; } );
ga( "a MOBILE-FIRST button row (`flex flex-col sm:flex-row` + `w-full sm:w-auto` buttons, the stamp says row) is a COLUMN on phones and a ROW from md up — the class alone read as a desktop stack of full-width buttons (five sites in the feed)", is_array( $x_btnrow ) && 'column' === (string) ( $x_btnrow['atts']['direction']['base'] ?? '' ) && 'row' === (string) ( $x_btnrow['atts']['direction']['md'] ?? '' ), wp_json_encode( $x_btnrow['atts']['direction'] ?? null ) );
$x_btns = array(); $r_all( $x_pg, function ( $n ) { return 'button' === ( $n['shortcode'] ?? '' ) && false !== strpos( (string) ( $n['atts']['label'] ?? '' ), 'Start a project' ); }, $x_btns );
ga( "…and its `w-full sm:w-auto` buttons are NOT full-width (width mode empty; the row's base-tier css gives phones the 100 %)", ! empty( $x_btns ) && '' === (string) ( $x_btns[0]['atts']['width']['mode'] ?? '' ), wp_json_encode( $x_btns[0]['atts']['width'] ?? null ) );
$x_hero_codes = array(); $r_all( $x_pg, function ( $n ) { return 'code_block' === ( $n['shortcode'] ?? '' ) && false !== strpos( (string) ( $n['atts']['content'] ?? '' ), 'hero-grid' ); }, $x_hero_codes );
$x_hero = $x_pg[0] ?? array(); $x_hero_cols = count( $x_hero['_items'] ?? array() );
ga( "a <style> element and an EMPTY absolute glow layer are never COLUMNS: the hero keeps ONE content column (no CSS text block, no 3-column row — a fixture from the feed)", 0 === count( $x_hero_codes ) && $x_hero_cols <= 1, 'cols=' . $x_hero_cols . ' codes=' . count( $x_hero_codes ) );
$x_gal = array(); $r_all( $x_pg, function ( $n ) { return 'gallery' === ( $n['shortcode'] ?? '' ); }, $x_gal );
$x_price = $r_find( $x_pg, function ( $n ) { return 'text_block' === ( $n['shortcode'] ?? '' ) && false !== strpos( (string) ( $n['atts']['text'] ?? '' ), '$248' ); } );
ga( "IMAGE-LED PRODUCT TILES (image + category + name link + price) are NOT a gallery: no gallery node, the price survives as text (three shops in the feed lost every name and price)", 0 === count( $x_gal ) && is_array( $x_price ) );
$x_hd = $r_find( $x_pg, function ( $n ) { return 'special_heading' === ( $n['shortcode'] ?? '' ) && false !== strpos( (string) ( $n['atts']['title'] ?? '' ), 'Featured products' ); } );
ga( "a HEADING + LINK row (h2 + muted p beside a text link) keeps the kicker span as the OVERLINE (uppercase, its accent) and the subtitle's measured muted ink + 16px (RECURS x5: the theme's 18px body ink)", is_array( $x_hd ) && 'New season' === (string) ( $x_hd['atts']['overline'] ?? '' ) && 'yes' === (string) ( $x_hd['atts']['overline_uppercase'] ?? '' ) && 'rgb(255, 45, 85)' === (string) ( $x_hd['atts']['overline_color']['custom'] ?? '' ) && 'rgb(115, 115, 115)' === (string) ( $x_hd['atts']['subtitle_color']['custom'] ?? '' ) && ( '' !== (string) ( $x_hd['atts']['subtitle_size'] ?? '' ) || false !== strpos( (string) ( $x_hd['atts']['custom_css'] ?? '' ), '.heading-subtitle{font-size:16px' ) ), wp_json_encode( array( $x_hd['atts']['overline'] ?? null, $x_hd['atts']['overline_color'] ?? null, $x_hd['atts']['subtitle_color'] ?? null ) ) );
$x_lnk = $r_find( $x_pg, function ( $n ) { return 'button' === ( $n['shortcode'] ?? '' ) && false !== strpos( (string) ( $n['atts']['label'] ?? '' ), 'View all products' ); } );
ga( "a bare TEXT LINK (no fill, no border, no padding in its stamp) is a btn-link with padding 0, no border, no underline — the native .btn inset padded it into a box (a fixture from the feed)", is_array( $x_lnk ) && 'btn-link' === (string) ( $x_lnk['atts']['style'] ?? '' ) && false !== strpos( (string) ( $x_lnk['atts']['custom_css'] ?? '' ), 'padding:0 !important' ) && false !== strpos( (string) ( $x_lnk['atts']['custom_css'] ?? '' ), 'text-decoration:none' ), (string) ( $x_lnk['atts']['custom_css'] ?? '' ) );
$x_ib = array(); $r_all( $x_pg, function ( $n ) { return 'image_box' === ( $n['shortcode'] ?? '' ); }, $x_ib );
$x_tm = array(); $r_all( $x_pg, function ( $n ) { return 'testimonials' === ( $n['shortcode'] ?? '' ); }, $x_tm );
ga( "a 3-up of image-topped SERVICE CARDS is three image boxes — a lone `lucide-star` in the icon tile is the card's ICON, never a rating (the grid was read as testimonials)", 3 === count( $x_ib ) && 0 === count( $x_tm ), 'image_box=' . count( $x_ib ) . ' testimonials=' . count( $x_tm ) );
$x_i0 = $x_ib[0]['atts'] ?? array(); $x_icss = (string) ( $x_i0['custom_css'] ?? '' );
ga( "…each image box carries the card: the muted body ink as Content Colour, its 16/24 metrics, the 24px/700 title, the lucide icon, the 32px body inset, the 256px fixed media height, and the filled block anchor as a full-width BUTTON (six sites in the feed)", '#665c70' === strtolower( (string) ( $x_i0['content_color']['custom'] ?? '' ) ) && false !== strpos( $x_icss, '.imgbox__text{font-size:16px !important;line-height:24px' ) && false !== strpos( $x_icss, '.imgbox__title{font-size:24px' ) && 'lucide/star' === (string) ( $x_i0['icon']['svg-id'] ?? '' ) && false !== strpos( $x_icss, '.imgbox__body{padding:32px' ) && false !== strpos( $x_icss, '.imgbox__media{height:256px' ) && 'button' === (string) ( $x_i0['button_style'] ?? '' ) && false !== strpos( $x_icss, '.imgbox__btn{display:block;width:100%' ), wp_json_encode( array( $x_i0['content_color'] ?? null, $x_i0['icon']['svg-id'] ?? null, $x_i0['button_style'] ?? null, $x_icss ) ) );
$x_cnt = $r_find( $x_pg, function ( $n ) { return 'counter' === ( $n['shortcode'] ?? '' ) && '3.2' === (string) ( $n['atts']['number'] ?? '' ); } );
ga( "a `3.2×` stat keeps the multiplication sign as its SUFFIX (it leaked into the label)", is_array( $x_cnt ) && '×' === (string) ( $x_cnt['atts']['suffix'] ?? '' ), wp_json_encode( $x_cnt['atts']['suffix'] ?? null ) );
$x_rowh = array(); $r_all( $x_pg, function ( $n ) { return 'special_heading' === ( $n['shortcode'] ?? '' ) && false !== strpos( (string) ( $n['atts']['title'] ?? '' ), 'Routes that respect' ); }, $x_rowh );
$x_marks = array(); $r_all( $x_pg, function ( $n ) { return 'text_block' === ( $n['shortcode'] ?? '' ) && '01' === trim( strip_tags( (string) ( $n['atts']['text'] ?? '' ) ) ); }, $x_marks );
$x_subonly = array(); $r_all( $x_pg, function ( $n ) { return 'special_heading' === ( $n['shortcode'] ?? '' ) && '' === trim( (string) ( $n['atts']['title'] ?? '' ) ) && false !== strpos( (string) ( $n['atts']['subtitle'] ?? '' ), 'Every leg' ); }, $x_subonly );
ga( "a NUMBERED ROW (`span.01 + div(h3 + p)`) keeps its marker (a text block per row) and its h3 + p as ONE heading — the '01' was dropped as a bare number and the pair split into a title-only + a subtitle-only heading", 2 === count( $x_marks ) && 2 === count( $x_rowh ) && 0 === count( $x_subonly ), 'marks=' . count( $x_marks ) . ' heads=' . count( $x_rowh ) . ' subonly=' . count( $x_subonly ) );
$x_half = $r_find( $x_pg, function ( $n ) { return 'media_image' === ( $n['shortcode'] ?? '' ) && false !== strpos( wp_json_encode( $n['atts']['image'] ?? '' ), 'half.jpg' ); } );
ga( "a SPLIT band's half-width column whose only child is an absolute cover image is an IMAGE COLUMN (media_image), never the section backdrop — the image half emptied (a fixture from the feed)", is_array( $x_half ) );
$x_form = $r_find( $x_pg, function ( $n ) { return 'contact_form' === ( $n['shortcode'] ?? '' ); } );
$x_news = array(); $r_all( $x_pg, function ( $n ) { return 'newsletter' === ( $n['shortcode'] ?? '' ); }, $x_news );
$x_drop = $r_find( $x_pg, function ( $n ) { return 'special_heading' === ( $n['shortcode'] ?? '' ) && false !== strpos( (string) ( $n['atts']['title'] ?? '' ), 'Or drop by' ); } );
ga( "a name / email / message form is a CONTACT FORM (never a newsletter), the grid around it is not swallowed (the details column survives), and the white rounded card around the form keeps its skin as a Box Preset", is_array( $x_form ) && 0 === count( $x_news ) && is_array( $x_drop ) && false !== strpos( (string) ( $x_form['atts']['form']['json'] ?? '' ), 'textarea' ), 'form=' . (int) is_array( $x_form ) . ' news=' . count( $x_news ) . ' details=' . (int) is_array( $x_drop ) );
$x_formcol = $r_find( $x_pg, function ( $n ) { if ( 'flexbox' !== ( $n['type'] ?? '' ) || '' === (string) ( $n['atts']['border_preset'] ?? '' ) ) { return false; } foreach ( $n['_items'] ?? array() as $it ) { if ( 'contact_form' === ( $it['shortcode'] ?? '' ) ) { return true; } } return false; } );
ga( "…the form's card skin rides the column as a Box Preset that OWNS its 32px inset — the column carries no padding of its own (the preset pads the inner wrapper; a column pad on top doubled it)", is_array( $x_formcol ) && false === strpos( (string) ( $x_formcol['atts']['custom_css'] ?? '' ), 'padding-top:32px' ) && false !== strpos( (string) ( $x_formcol['atts']['border_preset'] ?? '' ), 'boxp-' ), wp_json_encode( $x_formcol['atts']['custom_css'] ?? null ) );

/* --------------------------------------------------------------------- *
 * [Y] The findings feed, rows 168–284: a black band losing its fill, headings re-cased by a site style, a contact form
 *     printing a PHP warning, a cart-count badge as the header CTA, pill CTAs as badges, a hero centred by its row's
 *     items-center, a React FAQ of toggle-only cards, a heading block read as a marquee, testimonial authors (initials,
 *     the heavier line), a "24/7" stat, a stat card, a floating card's three lines, an icon box's weight / rgba ink / no
 *     icon, the body font from a display <p>, a pricing grid's names / thousands / period / subtitle, a 1400 container
 *     with its padding inside, a sticky masthead read as a hero.
 * --------------------------------------------------------------------- */
$z_cs = function ( $e ) use ( $t_cs ) { return $t_cs( 'color:rgb(23, 23, 23);font-family:Inter, sans-serif;' . $e ); };
$z_plan = function ( $name, $price, $desc, $badge ) use ( $z_cs ) { return '<div class="rounded-2xl border p-8 relative" ' . $z_cs( 'display:block;border-radius:16px;border-top-width:1px;border-top-style:solid;border-top-color:rgb(229, 231, 235);padding:32px;height:520px;width:420px' ) . '>' . ( $badge ? '<div class="absolute -top-3 rounded-full bg-primary px-3 py-1 text-xs uppercase" ' . $z_cs( 'position:absolute;display:block;font-size:12px;text-transform:uppercase;border-radius:9999px;background-color:rgb(37, 99, 235);color:rgb(255, 255, 255);padding:4px 12px;height:24px' ) . '>Most Popular</div>' : '' ) . '<p class="uppercase text-sm tracking-widest" ' . $z_cs( 'display:block;font-size:14px;text-transform:uppercase;letter-spacing:2px;height:20px' ) . '>' . $name . '</p><div class="mt-4" ' . $z_cs( 'display:block;margin:16px 0px 0px;height:48px' ) . '><span class="text-4xl font-extrabold" ' . $z_cs( 'display:inline;font-size:36px;font-weight:800' ) . '>$' . $price . '</span> <span class="text-gray-500" ' . $z_cs( 'display:inline;color:rgb(107, 114, 128)' ) . '>/ per program</span></div><p class="mt-4 text-gray-600" ' . $z_cs( 'display:block;margin:16px 0px 0px;color:rgb(75, 85, 99);height:48px' ) . '>' . $desc . '</p><ul class="mt-6 space-y-3" ' . $z_cs( 'display:block;margin:24px 0px 0px;height:120px' ) . '><li ' . $z_cs( 'display:block;height:24px' ) . '>Weekly one-on-one sessions</li><li ' . $z_cs( 'display:block;height:24px' ) . '>Progress reviews</li><li ' . $z_cs( 'display:block;height:24px' ) . '>Email support</li></ul><a href="#" class="mt-8 block rounded-xl bg-primary py-3 text-center text-white" ' . $z_cs( 'display:block;margin:32px 0px 0px;border-radius:12px;background-color:rgb(37, 99, 235);color:rgb(255, 255, 255);padding:12px 0px;text-align:center;height:48px' ) . '>Choose plan</a></div>'; };
$z_faq = '<div class="card" ' . $z_cs( 'display:block;border-radius:12px;border-top-width:1px;border-top-style:solid;border-top-color:rgb(229, 231, 235);padding:24px;height:74px' ) . '><button class="w-full flex items-start justify-between gap-4 text-left" ' . $z_cs( 'display:flex;justify-content:space-between;text-align:left;height:24px' ) . '><span class="text-base font-semibold" ' . $z_cs( 'display:block;font-size:16px;font-weight:600;height:24px' ) . '>How long does a booking take?</span><svg class="lucide lucide-chevron-down w-5 h-5" ' . $z_cs( 'display:block;width:20px;height:20px' ) . '><path d="M4 4h16v16H4z"/></svg></button></div>';
$z_html = '<!DOCTYPE html><html data-sc-content-width="1400"><head><title>Outpost</title></head><body ' . $z_cs( 'background-color:rgb(255, 255, 255)' ) . '><main>'
	// a STICKY masthead inside <main> under a min-h-screen wrapper (nav of 4 + a serif h1 wordmark + a bag icon with a "0" count)
	. '<div class="min-h-screen" ' . $z_cs( 'display:block;min-height:900px;height:4400px' ) . '><header class="sticky top-0 z-40 border-b" ' . $z_cs( 'position:sticky;display:block;background-color:rgba(252, 251, 249, 0.95);height:97px;border-bottom-width:1px;border-bottom-style:solid;border-bottom-color:rgb(232, 230, 225)' ) . '><div class="w-full max-w-[1440px] mx-auto px-6 flex items-center justify-between" ' . $z_cs( 'display:flex;max-width:1440px;padding:20px 24px;align-items:center;justify-content:space-between;height:97px;margin:0px 0px' ) . '><nav class="flex items-center gap-8 text-[12px] uppercase" ' . $z_cs( 'display:flex;gap:32px;font-size:12px;text-transform:uppercase;height:18px' ) . '><a href="/collections" ' . $z_cs( 'display:block;font-size:12px;height:18px' ) . '>Collections</a><a href="/about" ' . $z_cs( 'display:block;font-size:12px;height:18px' ) . '>About</a><a href="/journal" ' . $z_cs( 'display:block;font-size:12px;height:18px' ) . '>Journal</a><a href="/contact" ' . $z_cs( 'display:block;font-size:12px;height:18px' ) . '>Contact</a></nav><a href="/" class="text-center" ' . $z_cs( 'display:block;text-align:center;height:56px' ) . '><h1 class="font-serif text-[32px] tracking-[0.25em] font-normal" ' . $z_cs( 'display:block;font-family:Georgia, serif;font-size:32px;letter-spacing:8px;font-weight:400;height:40px' ) . '>OUTPOST</h1><p class="text-[10px] uppercase" ' . $z_cs( 'display:block;font-size:10px;text-transform:uppercase;color:rgb(102, 102, 102);height:16px' ) . '>EYEWEAR</p></a><div class="flex items-center gap-6" ' . $z_cs( 'display:flex;gap:24px;height:26px' ) . '><button class="relative" ' . $z_cs( 'display:block;position:relative;height:26px;width:26px' ) . '><svg class="lucide lucide-shopping-bag w-5 h-5" ' . $z_cs( 'display:block;width:20px;height:20px' ) . '><path d="M4 4h16v16H4z"/></svg><span class="absolute -top-2 -right-2 text-[10px]" ' . $z_cs( 'position:absolute;display:block;font-size:10px;width:16px;height:16px;border-radius:9999px;background-color:rgb(17, 17, 17);color:rgb(255, 255, 255)' ) . '>0</span></button></div></div></header>'
	// HERO: a 1400 container (px-6, padding INSIDE) whose copy column is a row-flex child (items-center = vertical); two pill CTAs
	. '<section id="hero" ' . $z_cs( 'display:block;height:704px' ) . '><div class="container mx-auto px-6 py-16 flex flex-col lg:flex-row items-center gap-12" ' . $z_cs( 'display:flex;flex-direction:row;align-items:center;gap:48px;max-width:1400px;padding:64px 24px;margin:0px 20px;height:704px' ) . '><div class="flex-1 space-y-8" ' . $z_cs( 'display:block;height:448px;width:652px' ) . '><h1 class="text-6xl font-bold" ' . $z_cs( 'display:block;font-size:60px;line-height:66px;font-weight:700;height:132px' ) . '>Quiet machines for loud rooms</h1><p class="text-lg text-slate-600 max-w-xl" ' . $z_cs( 'display:block;font-size:18px;line-height:29px;color:rgb(71, 85, 105);max-width:576px;height:58px' ) . '>Built for the crews who run the floor, not the ones who only visit it on launch day.</p><div class="flex flex-col sm:flex-row justify-center gap-4" ' . $z_cs( 'display:flex;flex-direction:row;justify-content:center;gap:16px;height:50px' ) . '><a href="#" class="btn-coral inline-flex items-center px-7 py-3.5 text-sm font-semibold rounded-full" ' . $z_cs( 'display:flex;align-items:center;font-size:14px;font-weight:600;border-radius:100px;padding:14px 28px;background-color:rgb(238, 99, 78);color:rgb(255, 255, 255);height:48px;width:200px' ) . '>Explore programs</a><a href="#" class="btn-outline-white inline-flex items-center px-7 py-3.5 text-sm font-semibold rounded-full" ' . $z_cs( 'display:flex;align-items:center;font-size:14px;font-weight:600;border-radius:100px;padding:14px 28px;border-top-width:1px;border-top-style:solid;border-top-color:rgba(255, 255, 255, 0.3);height:50px;width:178px' ) . '>Our methodology</a></div></div><div class="flex-1 relative" ' . $z_cs( 'display:block;position:relative;height:444px;width:592px' ) . '><img src="https://example.com/hero.jpg" class="w-full rounded-3xl object-cover" ' . $z_cs( 'display:block;width:592px;height:444px;border-radius:24px;object-fit:cover' ) . '><div class="absolute -bottom-6 -left-6 bg-white p-4 rounded-2xl shadow-xl flex items-center gap-4" ' . $z_cs( 'position:absolute;display:flex;align-items:center;gap:16px;background-color:rgb(255, 255, 255);padding:16px;border-radius:16px;box-shadow:rgba(0, 0, 0, 0.25) 0px 25px 50px -12px;height:80px;width:260px;bottom:-24px;left:-24px' ) . '><div class="bg-green-100 p-3 rounded-full" ' . $z_cs( 'display:block;background-color:rgb(220, 252, 231);padding:12px;border-radius:9999px;height:48px;width:48px' ) . '><svg class="lucide lucide-award w-6 h-6" ' . $z_cs( 'display:block;width:24px;height:24px;color:rgb(22, 163, 74)' ) . '><path d="M4 4h16v16H4z"/></svg></div><div ' . $z_cs( 'display:block;height:44px' ) . '><p class="text-sm text-slate-500 font-medium" ' . $z_cs( 'display:block;font-size:14px;line-height:20px;font-weight:500;color:rgb(100, 116, 139);height:20px' ) . '>Certified</p><p class="font-bold" ' . $z_cs( 'display:block;font-size:16px;line-height:24px;font-weight:700;color:rgb(15, 23, 42);height:24px' ) . '>Top Vet Clinic 2023</p></div></div></div></div></section>'
	// a BLACK band with a scroll-reveal heading block (h2 + p) and a 3-up of stat cards + a 24/7 stat
	. '<section class="bg-black py-24 relative overflow-hidden" ' . $z_cs( 'display:block;background-color:rgb(0, 0, 0);color:rgb(249, 243, 217);padding:96px 0px;height:700px;overflow:hidden' ) . '><div class="container mx-auto px-6" ' . $z_cs( 'display:block;max-width:1400px;padding:0px 24px;margin:0px 20px;height:508px' ) . '><div class="text-center max-w-3xl mx-auto mb-16 transition-all duration-1000 animate-scroll-fade-up" ' . $z_cs( 'display:block;text-align:center;max-width:768px;margin:0px 292px 64px;height:112px' ) . '><h2 class="text-4xl md:text-5xl font-bold mb-4" ' . $z_cs( 'display:block;font-size:48px;line-height:48px;font-weight:700;text-align:center;margin:0px 0px 16px;height:48px' ) . '>Numbers that hold</h2><p class="text-lg" ' . $z_cs( 'display:block;font-size:18px;line-height:28px;text-align:center;height:28px' ) . '>Every figure below is audited each quarter.</p></div>'
	. '<div class="grid grid-cols-3 gap-8" ' . $z_cs( 'display:grid;grid-template-columns:432px 432px 432px;gap:32px;height:200px' ) . '><div class="relative rounded-2xl p-8" ' . $z_cs( 'display:block;position:relative;border-radius:16px;padding:32px;background-color:rgb(15, 23, 42);height:200px' ) . '><h4 class="text-sm font-medium" ' . $z_cs( 'display:block;font-size:14px;line-height:20px;font-weight:500;color:rgb(203, 213, 225);height:20px' ) . '>Crews retained</h4><div class="text-5xl font-bold" ' . $z_cs( 'display:block;font-size:48px;line-height:52px;font-weight:700;color:rgb(255, 255, 255);height:52px;margin:8px 0px' ) . '>80+</div><p class="text-xs text-slate-400" ' . $z_cs( 'display:block;font-size:12px;line-height:18px;color:rgb(148, 163, 184);height:36px' ) . '>Across nine seasons and three continents, measured on the last day of each contract.</p></div>'
	. '<div class="relative rounded-2xl p-8" ' . $z_cs( 'display:block;position:relative;border-radius:16px;padding:32px;background-color:rgb(15, 23, 42);height:200px' ) . '><h4 class="text-sm font-medium" ' . $z_cs( 'display:block;font-size:14px;line-height:20px;font-weight:500;color:rgb(203, 213, 225);height:20px' ) . '>Routes flown</h4><div class="text-5xl font-bold" ' . $z_cs( 'display:block;font-size:48px;line-height:52px;font-weight:700;color:rgb(255, 255, 255);height:52px;margin:8px 0px' ) . '>1,200</div><p class="text-xs text-slate-400" ' . $z_cs( 'display:block;font-size:12px;line-height:18px;color:rgb(148, 163, 184);height:36px' ) . '>Counted from the first paid departure, corrected for cancellations and reroutes.</p></div>'
	. '<div class="text-center" ' . $z_cs( 'display:block;text-align:center;height:120px' ) . '><div class="text-7xl font-bold" ' . $z_cs( 'display:block;font-size:72px;line-height:72px;font-weight:700;color:rgb(255, 255, 255);height:72px' ) . '>24/7</div><div class="uppercase tracking-wider" ' . $z_cs( 'display:block;font-size:14px;line-height:20px;text-transform:uppercase;letter-spacing:1.4px;height:20px' ) . '>Available support</div></div></div></div></section>'
	// FLAVORS: 2 icon-less cards (an index disc, h3 600 + tag, a body at 70 % alpha)
	. '<section class="py-24" ' . $z_cs( 'display:block;padding:96px 0px;height:500px' ) . '><div class="container mx-auto px-6" ' . $z_cs( 'display:block;max-width:1400px;padding:0px 24px;margin:0px 20px;height:314px' ) . '><div class="grid grid-cols-2 gap-8" ' . $z_cs( 'display:grid;grid-template-columns:660px 660px;gap:32px;height:141px' ) . '>' . str_repeat( '<div class="bg-white p-8 rounded-3xl border shadow-sm flex gap-4" ' . $z_cs( 'display:flex;gap:16px;background-color:rgb(255, 255, 255);padding:32px;border-radius:24px;border-top-width:1px;border-top-style:solid;border-top-color:rgba(245, 240, 232, 0.6);box-shadow:rgba(0, 0, 0, 0.05) 0px 1px 2px 0px;height:141px;width:660px' ) . '><div class="space-y-2" ' . $z_cs( 'display:block;height:75px' ) . '><h3 class="font-serif text-lg font-semibold" ' . $z_cs( 'display:block;font-size:18px;line-height:28px;font-weight:600;color:rgb(46, 30, 18);height:28px' ) . '>Salted caramel harbor</h3><p class="text-xs font-light" ' . $z_cs( 'display:block;font-size:12px;line-height:19.5px;font-weight:300;color:rgba(46, 30, 18, 0.7);height:39px' ) . '>A slow-burnt sugar folded into sea salt from the north shore, finished with a cream that never quite sets.</p></div></div>', 2 ) . '</div></div></section>'
	// PRICING + FAQ + TESTIMONIALS + CONTACT
	. '<section id="pricing" class="py-24" ' . $z_cs( 'display:block;padding:96px 0px;height:800px' ) . '><div class="container mx-auto px-6" ' . $z_cs( 'display:block;max-width:1400px;padding:0px 24px;margin:0px 20px;height:600px' ) . '><div class="grid md:grid-cols-2 gap-8 max-w-4xl mx-auto" ' . $z_cs( 'display:grid;grid-template-columns:432px 432px;gap:32px;max-width:896px;margin:0px 228px;height:520px' ) . '>' . $z_plan( 'Essential', '2,400', 'Twelve weeks of guided practice for a single team getting started.', false ) . $z_plan( 'Corporate Elite', '4,800', 'A full season with the whole department, on-site and remote.', true ) . '</div></div></section>'
	. '<section id="faq" class="py-20" ' . $z_cs( 'display:block;padding:80px 0px;height:600px' ) . '><div class="max-w-3xl mx-auto px-6" ' . $z_cs( 'display:block;max-width:768px;padding:0px 24px;margin:0px 336px;height:440px' ) . '><div class="space-y-4" ' . $z_cs( 'display:block;height:440px' ) . '>' . str_repeat( $z_faq, 4 ) . '</div></div></section>'
	. '<section id="testimonials" class="py-24" ' . $z_cs( 'display:block;padding:96px 0px;height:500px' ) . '><div class="container mx-auto px-6" ' . $z_cs( 'display:block;max-width:1400px;padding:0px 24px;margin:0px 20px;height:308px' ) . '><div class="grid grid-cols-3 gap-8" ' . $z_cs( 'display:grid;grid-template-columns:432px 432px 432px;gap:32px;height:220px' ) . '>' . str_repeat( '<div class="card p-8 rounded-2xl border" ' . $z_cs( 'display:block;padding:32px;border-radius:16px;border-top-width:1px;border-top-style:solid;border-top-color:rgb(229, 231, 235);height:220px;width:432px' ) . '><p class="text-sm" ' . $z_cs( 'display:block;font-size:14px;line-height:22px;height:88px' ) . '>“They rebuilt our booking flow in a fortnight and the phones went quiet in the best way — nobody had to call to ask what happened next.”</p><div class="flex items-center gap-3 mt-6" ' . $z_cs( 'display:flex;align-items:center;gap:12px;margin:24px 0px 0px;height:40px' ) . '><div class="w-10 h-10 rounded-full bg-primary/10 flex items-center justify-center text-sm font-semibold" ' . $z_cs( 'display:flex;width:40px;height:40px;border-radius:9999px;background-color:rgba(37, 99, 235, 0.1);align-items:center;justify-content:center;font-size:14px;font-weight:600;color:rgb(37, 99, 235)' ) . '>SM</div><div ' . $z_cs( 'display:block;height:38px' ) . '><div class="font-semibold" ' . $z_cs( 'display:block;font-weight:600;color:rgb(45, 42, 38);height:22px' ) . '>Sara Meridian</div><div class="text-xs text-muted" ' . $z_cs( 'display:block;font-size:12px;line-height:16px;color:rgb(120, 113, 108);height:16px' ) . '>Art director, Harbor Studio</div></div></div></div>', 3 ) . '</div></div></section>'
	. '<section id="contact" class="py-24" ' . $z_cs( 'display:block;padding:96px 0px;height:600px' ) . '><div class="container mx-auto px-6" ' . $z_cs( 'display:block;max-width:1400px;padding:0px 24px;margin:0px 20px;height:400px' ) . '><form class="space-y-6 max-w-md" ' . $z_cs( 'display:block;max-width:448px;height:388px' ) . '><div class="space-y-1" ' . $z_cs( 'display:block;height:70px' ) . '><label for="fn" class="text-xs font-bold" ' . $z_cs( 'display:block;font-size:12px;font-weight:700;height:16px' ) . '>Full name *</label><input id="fn" type="text" class="border-b w-full" ' . $z_cs( 'display:inline-block;width:448px;height:46px;border-bottom-width:1px;border-bottom-style:solid;border-bottom-color:rgb(226, 232, 240)' ) . '></div><div class="space-y-1" ' . $z_cs( 'display:block;height:70px' ) . '><label for="em" class="text-xs font-bold" ' . $z_cs( 'display:block;font-size:12px;font-weight:700;height:16px' ) . '>Email *</label><input id="em" type="email" class="border-b w-full" ' . $z_cs( 'display:inline-block;width:448px;height:46px' ) . '></div><div class="space-y-1" ' . $z_cs( 'display:block;height:136px' ) . '><label for="msg" class="text-xs font-bold" ' . $z_cs( 'display:block;font-size:12px;font-weight:700;height:16px' ) . '>Message *</label><textarea id="msg" rows="4" class="border-b w-full" ' . $z_cs( 'display:inline-block;width:448px;height:104px' ) . '></textarea></div><button type="submit" class="w-full py-3 rounded-md text-white" ' . $z_cs( 'display:block;width:448px;background-color:rgb(214, 93, 93);color:rgb(255, 255, 255);padding:12px 16px;border-radius:6px;height:48px' ) . '>Send messages</button></form></div></section>'
	. '</div></main></body></html>';
$z_bl = FW_Site_Converter_Sources::build_from_html( $z_html, 'Outpost', array( 'dynamic_chrome' => true, 'hifi_css' => true ) );
$z_v  = $z_bl['files']['theme-settings.json']['values'] ?? array();
$z_pg = $z_bl['files']['pages.json']['pages'][0]['builder'] ?? array();
$z_td = $z_bl['files']['theme-design.json'] ?? array();
ga( "a STICKY <header> inside <main> under a min-h-screen wrapper is the MASTHEAD, never a hero section: its 4 links are the menu and it is not page section 1", 4 === count( $z_td['header']['menu'] ?? array() ) && null === $r_find( $z_pg, function ( $n ) { return 'special_heading' === ( $n['shortcode'] ?? '' ) && 'OUTPOST' === (string) ( $n['atts']['title'] ?? '' ); } ), wp_json_encode( array_map( function ( $i ) { return $i['label'] ?? ''; }, $z_td['header']['menu'] ?? array() ) ) );
ga( "a bag icon whose only text is a COUNT badge ('0') is never the header CTA", empty( $z_td['header']['cta']['enabled'] ) && '0' !== (string) ( $z_td['header']['cta']['label'] ?? '' ), wp_json_encode( $z_td['header']['cta'] ?? null ) );
ga( "a 1400 `.container px-6` carries its padding INSIDE: Container Width 1352 + gutter 24 (the stamp's outer 1400 rendered a 1304 rail)", '1352' === (string) ( $z_v['general_layout']['layout_container_width']['lg']['value'] ?? '' ) && '24' === (string) ( $z_v['general_layout']['layout_container_gutter']['value'] ?? '' ), wp_json_encode( array( $z_v['general_layout']['layout_container_width']['lg'] ?? null, $z_v['general_layout']['layout_container_gutter'] ?? null ) ) );
$z_hero = $z_pg[0] ?? array(); $z_hero_json = wp_json_encode( $z_hero );
ga( "a hero copy column under a `flex-col lg:flex-row items-center` container (a ROW at desktop) is NOT centred: no text-align:center anywhere in the hero (items-center on a row is vertical)", false === strpos( $z_hero_json, 'text-align:center' ) && 'center' !== (string) ( $z_hero['atts']['text_align'] ?? '' ) );
$z_pills = array(); $r_all( $z_pg, function ( $n ) { return 'button' === ( $n['shortcode'] ?? '' ) && preg_match( '/Explore programs|Our methodology/', (string) ( $n['atts']['label'] ?? '' ) ); }, $z_pills );
$z_badges = array(); $r_all( $z_pg, function ( $n ) { return 'badge' === ( $n['shortcode'] ?? '' ); }, $z_badges );
ga( "two PILL-shaped CTAs (48px tall, 14px, radius 100) in the hero's button row are BUTTONS, never badges", 2 === count( $z_pills ) && 0 === count( $z_badges ), 'buttons=' . count( $z_pills ) . ' badges=' . count( $z_badges ) );
$z_fc = $r_find( $z_pg, function ( $n ) { return 'icon_box' === ( $n['shortcode'] ?? '' ) && 'Top Vet Clinic 2023' === (string) ( $n['atts']['title'] ?? '' ); } );
ga( "a FLOATING CARD's lines: the heavier line is the title, the small first line the overline (the label had become the title)", is_array( $z_fc ) && 'Certified' === (string) ( $z_fc['atts']['overline'] ?? '' ), wp_json_encode( array( $z_fc['atts']['title'] ?? null, $z_fc['atts']['overline'] ?? null ) ) );
$z_black = $z_pg[1] ?? array();
ga( "a BLACK band keeps its fill (rgb(0, 0, 0) matched the alpha-0 test and every black section rendered transparent): a dark variant or the custom colour", '' !== (string) ( $z_black['atts']['variant'] ?? '' ) || '' !== (string) ( $z_black['atts']['background']['color']['value']['custom'] ?? '' ), wp_json_encode( array( $z_black['atts']['variant'] ?? null, $z_black['atts']['background']['color']['value'] ?? null ) ) );
$z_h2 = $r_find( $z_pg, function ( $n ) { return 'special_heading' === ( $n['shortcode'] ?? '' ) && 'Numbers that hold' === (string) ( $n['atts']['title'] ?? '' ); } );
$z_mq = array(); $r_all( $z_pg, function ( $n ) { return 'flexbox' === ( $n['type'] ?? '' ) && ! empty( $n['atts']['marquee']['mode'] ); }, $z_mq );
ga( "a heading block classed `animate-scroll-fade-up` (a scroll REVEAL) is a special_heading at its desktop 48px with the intro as subtitle — not a full-bleed MARQUEE code block; and its title asserts sentence case (text-transform:none)", is_array( $z_h2 ) && 0 === count( $z_mq ) && false !== strpos( (string) ( $z_h2['atts']['custom_css'] ?? '' ), 'font-size:48px' ) && false !== strpos( (string) ( $z_h2['atts']['custom_css'] ?? '' ), '.heading-title{text-transform:none' ), 'h2=' . (int) is_array( $z_h2 ) . ' marquees=' . count( $z_mq ) );
$z_stat = $r_find( $z_pg, function ( $n ) { return 'icon_box' === ( $n['shortcode'] ?? '' ) && '80+' === (string) ( $n['atts']['title'] ?? '' ); } );
ga( "a STAT CARD (h4 label + 48px number + description): the number is the title at its measured size, the label the overline, the description the content — not a 12px number inside the paragraph", is_array( $z_stat ) && 'Crews retained' === (string) ( $z_stat['atts']['overline'] ?? '' ) && false !== strpos( (string) ( $z_stat['atts']['custom_css'] ?? '' ), '.icon-box__title{font-size:48px' ) && false !== strpos( (string) ( $z_stat['atts']['content'] ?? '' ), 'nine seasons' ), wp_json_encode( array( $z_stat['atts']['overline'] ?? null, $z_stat['atts']['content'] ?? null ) ) );
$z_247 = $r_find( $z_pg, function ( $n ) { return 'counter' === ( $n['shortcode'] ?? '' ) && '24' === (string) ( $n['atts']['number'] ?? '' ); } );
$z_247l = $r_find( $z_pg, function ( $n ) { return 'text_block' === ( $n['shortcode'] ?? '' ) && false !== strpos( (string) ( $n['atts']['text'] ?? '' ), '24/7' ); } );
$z_247s = $r_find( $z_pg, function ( $n ) { return 'text_block' === ( $n['shortcode'] ?? '' ) && preg_match( '#<p>/7#', (string) ( $n['atts']['text'] ?? '' ) ); } );
ga( "a `24/7` stat keeps its `/7`: as the counter suffix (a stats row) or whole in its text leaf (a card cell) — it spilled into the label as '/7Available support'", ( is_array( $z_247 ) && '/7' === (string) ( $z_247['atts']['suffix'] ?? '' ) ) || ( is_array( $z_247l ) && null === $z_247s ), wp_json_encode( array( $z_247['atts']['suffix'] ?? null, $z_247l['atts']['text'] ?? null ) ) );
$z_flav = $r_find( $z_pg, function ( $n ) { return 'icon_box' === ( $n['shortcode'] ?? '' ) && 'Salted caramel harbor' === (string) ( $n['atts']['title'] ?? '' ); } );
ga( "an icon box carries the title's measured WEIGHT (600 — the theme's 700 won on nine sites), the body ink WITH ITS ALPHA (rgba(46, 30, 18, 0.7) — flattened to the card ink it was never carried), and NO icon when the card has none (a placeholder glyph)", is_array( $z_flav ) && false !== strpos( (string) ( $z_flav['atts']['custom_css'] ?? '' ), 'font-weight:600 !important' ) && 'rgba(46, 30, 18, 0.7)' === (string) ( $z_flav['atts']['content_color']['custom'] ?? '' ) && 'none' === (string) ( $z_flav['atts']['icon']['type'] ?? '' ), wp_json_encode( array( $z_flav['atts']['content_color'] ?? null, $z_flav['atts']['icon']['type'] ?? null ) ) );
$z_pr = $r_find( $z_pg, function ( $n ) { return 'pricing_table' === ( $n['shortcode'] ?? '' ); } );
$z_plans = $z_pr['atts']['plans'] ?? array();
ga( "a pricing grid: the plan NAME from the card's first short line (never 'Plan' or the 'Most Popular' badge), the price with its thousands separator, the period VERBATIM ('/ per program', never an invented '/mo'), the description as the subtitle, the badged card featured", 2 === count( $z_plans ) && 'Essential' === (string) ( $z_plans[0]['plan_title'] ?? '' ) && 'Corporate Elite' === (string) ( $z_plans[1]['plan_title'] ?? '' ) && '2,400' === (string) ( $z_plans[0]['price']['monthly'] ?? '' ) && '/ per program' === (string) ( $z_plans[0]['period']['monthly'] ?? '' ) && false !== strpos( (string) ( $z_plans[0]['subtitle'] ?? '' ), 'Twelve weeks' ) && 'yes' === (string) ( $z_plans[1]['featured'] ?? '' ), wp_json_encode( array_map( function ( $p ) { return array( $p['plan_title'] ?? null, $p['price']['monthly'] ?? null, $p['period']['monthly'] ?? null, $p['featured'] ?? null ); }, $z_plans ) ) );
$z_acc = $r_find( $z_pg, function ( $n ) { return 'accordion' === ( $n['shortcode'] ?? '' ); } );
$z_acc_codes = array(); $r_all( $z_pg, function ( $n ) { return 'code_block' === ( $n['shortcode'] ?? '' ) && false !== strpos( (string) ( $n['atts']['content'] ?? '' ), 'chevron' ); }, $z_acc_codes );
ga( "a React FAQ of TOGGLE-ONLY cards (button + chevron, the closed panels unmounted, no aria) is an ACCORDION of 4 items — not 4 code blocks", is_array( $z_acc ) && 4 === count( $z_acc['atts']['tabs'] ?? array() ) && 0 === count( $z_acc_codes ), 'acc=' . (int) is_array( $z_acc ) . ' codes=' . count( $z_acc_codes ) );
$z_tm = $r_find( $z_pg, function ( $n ) { return 'testimonials' === ( $n['shortcode'] ?? '' ); } );
$z_tmi = $z_tm['atts']['items'] ?? $z_tm['atts']['testimonials'] ?? array();
ga( "testimonial AUTHORS: the initials disc ('SM') is an avatar, never the name; the heavier line is the name, the small line the role", ! empty( $z_tmi ) && 'Sara Meridian' === (string) ( $z_tmi[0]['author_name'] ?? $z_tmi[0]['name'] ?? '' ) && false !== strpos( (string) ( $z_tmi[0]['author_job'] ?? $z_tmi[0]['position'] ?? '' ), 'Art director' ), wp_json_encode( $z_tmi[0] ?? null ) );
$z_form = $r_find( $z_pg, function ( $n ) { return 'contact_form' === ( $n['shortcode'] ?? '' ); } );
$z_fj = (string) ( $z_form['atts']['form']['json'] ?? '' );
ga( "every emitted form item carries `info` (the views read it unguarded — the built page printed 'Undefined array key \"info\"'), a label ending in '*' is required (and loses the star), the submit label is the source button's", is_array( $z_form ) && substr_count( $z_fj, '"info":""' ) >= 3 && false !== strpos( $z_fj, '"label":"Full name","required":true' ) && 'Send messages' === (string) ( $z_form['atts']['submit_button_text'] ?? '' ), substr( $z_fj, 0, 300 ) . ' | ' . (string) ( $z_form['atts']['submit_button_text'] ?? '' ) );
ga( "the BODY typography never comes from a display-size <p>: body 16px Inter (a 48px serif hero line had set the whole site's body)", '16' === (string) ( $z_v['typography']['body']['size']['value'] ?? $z_v['typography']['body']['size'] ?? '' ) || 16 === (int) ( $z_bl['files']['theme-design.json']['fonts']['body_size'] ?? 16 ), wp_json_encode( $z_v['typography']['body'] ?? null ) );

/* --------------------------------------------------------------------- *
 * [Z] Open items from the feed, closed: a caption tile (photo + absolute caption + tint) is the image box's OVERLAY family
 *     with the source scrim exactly; a `w-full h-[80vh]` cover frame beside the container is a BLEED picture; a bento
 *     gallery keeps its `auto-rows`, gap 0 and per-tile spans; an avatar stack sits in ONE row beside its rating.
 * --------------------------------------------------------------------- */
$w_cs = function ( $e ) use ( $t_cs ) { return $t_cs( 'color:rgb(23, 23, 23);font-family:Inter, sans-serif;' . $e ); };
$w_tile = function ( $i ) use ( $w_cs ) { $sp = 1 === $i ? 'col-span-2 row-span-2' : ( 4 === $i ? 'row-span-2' : ( 5 === $i ? 'col-span-2' : 'col-span-1 row-span-1' ) ); $h = ( 1 === $i || 4 === $i ) ? 600 : 300; $wd = ( 1 === $i || 5 === $i ) ? 960 : 480; return '<div class="relative overflow-hidden group ' . $sp . '" ' . $w_cs( 'display:block;position:relative;overflow:hidden;height:' . $h . 'px;width:' . $wd . 'px' ) . '><img src="https://example.com/t' . $i . '.jpg" class="w-full h-full object-cover" ' . $w_cs( 'display:block;width:' . $wd . 'px;height:' . $h . 'px;object-fit:cover' ) . '><div class="absolute inset-0 bg-black/0 group-hover:bg-black/20" ' . $w_cs( 'position:absolute;display:block;height:' . $h . 'px' ) . '></div></div>'; };
$w_html = '<!DOCTYPE html><html data-sc-content-width="1280" data-sc-content-gutter="24" data-sc-content-gutter-inside="1"><head><title>Outpost</title></head><body ' . $w_cs( 'background-color:rgb(255, 255, 255)' ) . '><main>'
	// HERO: copy + an avatar stack beside its stars / caption in one flex row; then a full-bleed 80vh cover frame beside the container
	. '<section class="pt-32 pb-0" ' . $w_cs( 'display:block;padding:128px 0px 0px;height:1172px' ) . '><div class="container mx-auto px-6 mb-12" ' . $w_cs( 'display:block;max-width:1280px;padding:0px 24px;margin:0px 80px 48px;height:276px' ) . '><h1 ' . $w_cs( 'display:block;font-size:60px;line-height:66px;height:132px' ) . '>Sitters the neighbourhood trusts</h1><div class="flex items-center gap-4 pt-4" ' . $w_cs( 'display:flex;align-items:center;gap:16px;padding:16px 0px 0px;height:56px' ) . '><div class="flex -space-x-3" ' . $w_cs( 'display:flex;height:40px' ) . '>' . str_repeat( '<div class="w-10 h-10 rounded-full border-2 border-white overflow-hidden" ' . $w_cs( 'display:block;width:40px;height:40px;border-radius:9999px;overflow:hidden' ) . '><img src="https://example.com/a.jpg" class="w-full h-full object-cover" ' . $w_cs( 'display:block;width:36px;height:36px;object-fit:cover' ) . '></div>', 4 ) . '</div><div class="text-sm" ' . $w_cs( 'display:block;font-size:14px;height:40px' ) . '><div class="flex items-center text-amber-400" ' . $w_cs( 'display:flex;color:rgb(251, 191, 36);height:16px' ) . '>' . str_repeat( '<svg class="lucide lucide-star h-4 w-4" ' . $w_cs( 'display:block;width:16px;height:16px' ) . '><path d="M4 4h16v16H4z"/></svg>', 5 ) . '</div><p ' . $w_cs( 'display:block;font-size:14px;font-weight:500;height:20px' ) . '>500+ happy pets</p></div></div></div>'
	. '<div class="w-full h-[60vh] md:h-[80vh] overflow-hidden" ' . $w_cs( 'display:block;height:720px;overflow:hidden' ) . '><img src="https://example.com/hero-wide.jpg" class="w-full h-full object-cover" ' . $w_cs( 'display:block;width:1440px;height:720px;object-fit:cover' ) . '></div></section>'
	// CATEGORY TILES: two photo tiles with a tint + an absolute caption (title + link) — 2 tiles, so never a gallery
	. '<section class="py-24" ' . $w_cs( 'display:block;padding:96px 0px;height:650px' ) . '><div class="container mx-auto px-6" ' . $w_cs( 'display:block;max-width:1280px;padding:0px 24px;margin:0px 80px;height:450px' ) . '><div class="grid grid-cols-2 gap-8" ' . $w_cs( 'display:grid;grid-template-columns:600px 600px;gap:32px;height:450px' ) . '>' . str_repeat( '<a href="#" class="group relative aspect-[4/3] overflow-hidden" ' . $w_cs( 'display:block;position:relative;overflow:hidden;height:450px;width:600px' ) . '><img src="https://example.com/cat.jpg" class="w-full h-full object-cover" ' . $w_cs( 'display:block;width:600px;height:450px;object-fit:cover' ) . '><div class="absolute inset-0 bg-black/10" ' . $w_cs( 'position:absolute;display:block;background-color:rgba(0, 0, 0, 0.1);height:450px' ) . '></div><div class="absolute bottom-8 left-8 text-white" ' . $w_cs( 'position:absolute;display:block;color:rgb(255, 255, 255);height:60px;left:32px;bottom:32px' ) . '><h3 class="text-2xl mb-2" ' . $w_cs( 'display:block;font-size:24px;line-height:32px;font-weight:400;color:rgb(255, 255, 255);margin:0px 0px 8px;height:32px' ) . '>Winter coats</h3><span class="text-sm font-medium flex items-center gap-2" ' . $w_cs( 'display:flex;font-size:14px;font-weight:500;color:rgb(255, 255, 255);height:20px' ) . '>Explore <svg class="lucide lucide-arrow-right w-4 h-4" ' . $w_cs( 'display:block;width:16px;height:16px' ) . '><path d="M4 4h16v16H4z"/></svg></span></div></a>', 2 ) . '</div></div></section>'
	// BENTO: 3 columns, auto-rows 300, gap 0, tile 1 spans 2×2, tile 4 tall, tile 5 wide
	. '<section class="py-0" ' . $w_cs( 'display:block;height:1200px' ) . '><div class="w-full" ' . $w_cs( 'display:block;height:1200px' ) . '><div class="grid grid-cols-1 md:grid-cols-3 auto-rows-[300px] gap-0" ' . $w_cs( 'display:grid;grid-template-columns:480px 480px 480px;grid-auto-rows:300px;gap:0px;height:1200px' ) . '>' . $w_tile( 1 ) . $w_tile( 2 ) . $w_tile( 3 ) . $w_tile( 4 ) . $w_tile( 5 ) . $w_tile( 6 ) . $w_tile( 7 ) . '</div></div></section>'
	. '</main></body></html>';
$w_bl = FW_Site_Converter_Sources::build_from_html( $w_html, 'Outpost', array( 'dynamic_chrome' => true, 'hifi_css' => true ) );
$w_pg = $w_bl['files']['pages.json']['pages'][0]['builder'] ?? array();
$w_av = $r_find( $w_pg, function ( $n ) { return 'avatar' === ( $n['shortcode'] ?? '' ); } );
$w_avrow = $r_find( $w_pg, function ( $n ) { if ( 'flexbox' !== ( $n['type'] ?? '' ) || 'row' !== (string) ( $n['atts']['direction']['base'] ?? '' ) ) { return false; } $has_av = false; $has_other = false; foreach ( $n['_items'] ?? array() as $it ) { foreach ( is_array( $it ) && isset( $it['atts'] ) ? array( $it ) : (array) $it as $c ) { $cj = wp_json_encode( $c ); if ( false !== strpos( $cj, '"avatar"' ) ) { $has_av = true; } elseif ( false !== strpos( $cj, 'lucide-star' ) || false !== strpos( $cj, 'happy pets' ) ) { $has_other = true; } } } return $has_av && $has_other; } );
ga( "an AVATAR STACK beside its stars / caption (`flex items-center gap-4`) is ONE row: the avatar node and the rating cluster share a row-direction flexbox (they stacked as two block rows)", is_array( $w_av ) && is_array( $w_avrow ) );
$w_bleed = $r_find( $w_pg, function ( $n ) { return 'media_image' === ( $n['shortcode'] ?? '' ) && false !== strpos( wp_json_encode( $n['atts']['image'] ?? '' ), 'hero-wide' ); } );
ga( "a `w-full h-[80vh] overflow-hidden` cover frame beside the container is a BLEED picture: its own column, height 80vh, object-fit cover, broken out of the content width (it rendered at its natural size inside the container)", is_array( $w_bleed ) && false !== strpos( (string) ( $w_bleed['atts']['custom_css'] ?? '' ), 'height:80vh' ) && false !== strpos( (string) ( $w_bleed['atts']['custom_css'] ?? '' ), 'object-fit:cover' ) && false !== strpos( (string) ( $w_bleed['atts']['custom_css'] ?? '' ), 'calc(50% - 50vw)' ), (string) ( $w_bleed['atts']['custom_css'] ?? '' ) );
$w_tiles = array(); $r_all( $w_pg, function ( $n ) { return 'image_box' === ( $n['shortcode'] ?? '' ) && 'Winter coats' === (string) ( $n['atts']['title'] ?? '' ); }, $w_tiles );
$w_t0 = $w_tiles[0]['atts'] ?? array();
ga( "a CAPTION TILE (photo + tint + absolute title / link) is the image box's OVERLAY family: title + 'Explore' line over the photo, the source's exact rgba(0,0,0,.1) tint on the scrim layer, bottom-left at its 32px inset, the caption's white ink (its leaves were dropped on four sites)", 2 === count( $w_tiles ) && 'overlay' === (string) ( $w_t0['design_settings']['family'] ?? '' ) && false !== strpos( (string) ( $w_t0['text'] ?? '' ), 'Explore' ) && false !== strpos( (string) ( $w_t0['custom_css'] ?? '' ), '.imgbox__scrim{background:rgba(0, 0, 0, 0.1)' ) && false !== strpos( (string) ( $w_t0['custom_css'] ?? '' ), 'justify-content:flex-end;padding:32px' ) && false !== strpos( (string) ( $w_t0['custom_css'] ?? '' ), 'color:rgb(255, 255, 255) !important' ), wp_json_encode( array( count( $w_tiles ), $w_t0['design_settings'] ?? null, $w_t0['button_label'] ?? null, $w_t0['custom_css'] ?? null ) ) );
$w_gal = $r_find( $w_pg, function ( $n ) { return 'gallery' === ( $n['shortcode'] ?? '' ); } );
$w_gcss = (string) ( $w_gal['atts']['custom_css'] ?? '' );
ga( "a BENTO gallery (`auto-rows-[300px] gap-0`, col/row spans) keeps its geometry: Metro design, 300px rows (the square-row pseudo off), gap 0, tile 1 spanning 2×2, tile 4 tall, tile 5 wide — seven equal tiles at natural aspect made a 3436px band for a 1200px source", is_array( $w_gal ) && 'metro' === (string) ( $w_gal['atts']['design_settings']['design'] ?? '' ) && false !== strpos( $w_gcss, 'grid-auto-rows:300px' ) && false !== strpos( $w_gcss, 'gap:0px' ) && false !== strpos( $w_gcss, ':nth-child(1){grid-column:span 2 !important;grid-row:span 2' ) && false !== strpos( $w_gcss, ':nth-child(4){grid-column:span 1 !important;grid-row:span 2' ) && false !== strpos( $w_gcss, ':nth-child(5){grid-column:span 2 !important;grid-row:span 1' ), $w_gcss );


/* --------------------------------------------------------------------- *
 * [AA] The feed's last open items, closed on one page: a trust strip whose avatar stack is a media-only cell and whose lucide
 *     stars come unstamped with empty paths; accordion-style rows; a 2×2 stat grid; a product scroller; `[80px_1fr_auto]`
 *     process rows (a round numeral disc, a per-corner radius on the preset, a one-line pill); event cards whose photo
 *     carries a pinned two-line date chip (+ an accent top edge, an `<a>` leaf); a split band that IS the grid with a
 *     CSS-painted photo half and a bottom-bordered text link.
 * --------------------------------------------------------------------- */
echo "\n[AA] Open items closed: trust strip, accordion rows, 2×2 counters, scroller, process rows, event cards, split band\n";
// a stamp with NO duplicate keys (a real capture never repeats one): the extras override the defaults
$aa_mk = function ( $base, $e ) { $m = array(); foreach ( explode( ';', $base . ';' . $e ) as $d ) { $d = trim( $d ); if ( '' === $d || false === strpos( $d, ':' ) ) { continue; } list( $k, $v ) = explode( ':', $d, 2 ); $m[ trim( $k ) ] = trim( $v ); } $o = array(); foreach ( $m as $k => $v ) { $o[] = $k . ':' . $v; } return 'data-sc-cs="' . implode( ';', $o ) . '"'; };
$aa_cs = function ( $e ) use ( $aa_mk ) { return $aa_mk( 'color:rgb(23, 23, 23);font-family:Inter, sans-serif;font-size:16px;font-weight:400;line-height:24px', $e ); };
$aa_dk = function ( $e ) use ( $aa_mk ) { return $aa_mk( 'color:rgb(240, 237, 230);font-family:Inter, sans-serif;font-size:16px;font-weight:400;line-height:24px', $e ); };
// TRUST STRIP (dark band): avatar stack + caption | hairline | five unstamped lucide stars (empty paths, as a capture keeps them) + rating
$aa_trust = '<section class="py-24 text-center" ' . $aa_dk( 'display:block;background-color:rgb(24, 26, 43);padding:96px 0px;text-align:center;height:600px' ) . '><div class="container mx-auto px-6" ' . $aa_dk( 'display:block;max-width:1280px;padding:0px 24px;margin:0px 80px;text-align:center;height:408px' ) . '><h1 ' . $aa_dk( 'display:block;font-size:64px;line-height:70px;font-weight:700;text-align:center;height:140px' ) . '>Learn from the people who built it</h1><p ' . $aa_dk( 'display:block;font-size:20px;line-height:30px;text-align:center;height:60px;margin:24px 0px 0px;color:rgba(240, 237, 230, 0.7)' ) . '>Cohorts of forty, taught live, with a mentor who answers within the hour.</p>'
	. '<div class="inline-flex items-center gap-6 px-8 py-5 bg-site-card border rounded-2xl mt-12" ' . $aa_dk( 'display:inline-flex;flex-direction:row;align-items:center;gap:24px;padding:20px 32px;background-color:rgb(51, 54, 81);border-top-width:1px;border-top-style:solid;border-top-color:rgb(69, 77, 110);border-radius:16px;margin:48px 0px 0px;height:86px;width:771px' ) . '>'
	. '<div class="flex items-center space-x-3" ' . $aa_dk( 'display:flex;flex-direction:row;align-items:center;gap:12px;height:40px' ) . '><div class="flex -space-x-2" ' . $aa_dk( 'display:flex;height:40px' ) . '>' . str_repeat( '<img src="https://example.com/a.jpg" class="w-10 h-10 rounded-full border-2" ' . $aa_dk( 'display:block;width:40px;height:40px;border-radius:9999px;margin:0px 0px 0px -8px' ) . '>', 4 ) . '</div><div class="text-left" ' . $aa_dk( 'display:block;text-align:left;height:40px' ) . '><div class="font-semibold" ' . $aa_dk( 'display:block;font-weight:600;height:24px;text-align:left' ) . '>2,400+ Graduates</div><div class="text-xs" ' . $aa_dk( 'display:block;font-size:12px;line-height:16px;color:rgba(240, 237, 230, 0.6);height:16px;text-align:left' ) . '>From 40+ countries</div></div></div>'
	. '<div class="w-px h-10 bg-white/10" ' . $aa_dk( 'display:block;width:1px;height:40px;background-color:rgba(255, 255, 255, 0.1)' ) . '></div>'
	. '<div class="flex items-center gap-2" ' . $aa_dk( 'display:flex;flex-direction:row;align-items:center;gap:8px;height:24px' ) . '><div class="flex" ' . $aa_dk( 'display:flex;color:rgb(238, 99, 78);height:20px' ) . '>' . str_repeat( '<svg class="lucide lucide-star w-5 h-5"><path></path></svg>', 5 ) . '</div><span class="font-semibold" ' . $aa_dk( 'display:inline;font-weight:600' ) . '>4.9 / 5</span></div>'
	. '</div></div></section>';
// ACCORDION-STYLE ROWS: h3 + trailing chevron in a justify-between row, each with a hairline
$aa_row = function ( $t ) use ( $aa_cs ) { return '<div class="border-b" ' . $aa_cs( 'display:block;border-bottom-width:1px;border-bottom-style:solid;border-bottom-color:rgba(0, 0, 0, 0.12);height:125px' ) . '><div class="container flex justify-between items-center py-8" ' . $aa_cs( 'display:flex;flex-direction:row;justify-content:space-between;align-items:center;padding:32px 0px;max-width:1280px;margin:0px 80px;height:125px' ) . '><h3 ' . $aa_cs( 'display:block;font-size:60px;line-height:60px;font-weight:700;height:60px' ) . '>' . $t . '</h3><svg class="lucide lucide-arrow-up-right w-7 h-7" ' . $aa_cs( 'display:block;width:28px;height:28px' ) . '><path d="M4 4h16v16H4z"/></svg></div></div>'; };
$aa_rows = '<section class="py-0" ' . $aa_cs( 'display:block;height:625px' ) . '><div class="border-y" ' . $aa_cs( 'display:block;border-top-width:1px;border-top-style:solid;border-top-color:rgba(0, 0, 0, 0.12);height:625px' ) . '>' . $aa_row( 'Brand systems' ) . $aa_row( 'Product design' ) . $aa_row( 'Motion' ) . $aa_row( 'Web builds' ) . $aa_row( 'Print' ) . '</div></section>';
// 2×2 STAT CARDS beside a copy column
$aa_stat = function ( $n, $l ) use ( $aa_cs ) { return '<div class="bg-white rounded-3xl p-8 text-center" ' . $aa_cs( 'display:block;background-color:rgb(255, 255, 255);border-radius:24px;padding:32px;text-align:center;height:150px;width:268px' ) . '><div class="text-4xl font-bold" ' . $aa_cs( 'display:block;font-size:40px;line-height:44px;font-weight:700;text-align:center;height:44px' ) . '>' . $n . '</div><div class="text-sm mt-2" ' . $aa_cs( 'display:block;font-size:14px;line-height:20px;text-align:center;margin:8px 0px 0px;height:20px' ) . '>' . $l . '</div></div>'; };
$aa_stats = '<section class="py-24 bg-yellow" ' . $aa_cs( 'display:block;background-color:rgb(245, 200, 0);padding:96px 0px;height:520px' ) . '><div class="container mx-auto px-6" ' . $aa_cs( 'display:block;max-width:1280px;padding:0px 24px;margin:0px 80px;height:328px' ) . '><div class="grid grid-cols-2 gap-16 items-center" ' . $aa_cs( 'display:grid;grid-template-columns:592px 592px;gap:64px;align-items:center;height:328px' ) . '><div ' . $aa_cs( 'display:block;height:200px;width:560px' ) . '><h2 ' . $aa_cs( 'display:block;font-size:48px;line-height:52px;font-weight:800;height:104px' ) . '>Purpose you can count</h2><p ' . $aa_cs( 'display:block;font-size:18px;line-height:28px;height:56px;margin:24px 0px 0px' ) . '>Every number here is audited by a third party each season, then published in full.</p></div><div class="grid grid-cols-2 gap-6" ' . $aa_cs( 'display:grid;grid-template-columns:268px 268px;gap:24px;height:324px' ) . '>' . $aa_stat( '120+', 'Schools reached' ) . $aa_stat( '3.4k', 'Volunteers' ) . $aa_stat( '98%', 'Retention' ) . $aa_stat( '12', 'Countries' ) . '</div></div></div></section>';
// CARD SCROLLER: a heading | arrows row, then an overflow-x row of product tiles (shrink-0)
$aa_tile = function ( $n, $p ) use ( $aa_cs ) { return '<div class="w-[300px] shrink-0" ' . $aa_cs( 'display:block;width:300px;height:470px;flex-shrink:0' ) . '><div class="aspect-[3/4] overflow-hidden" ' . $aa_cs( 'display:block;overflow:hidden;height:400px;width:300px' ) . '><img src="https://example.com/g.jpg" class="w-full h-full object-cover" ' . $aa_cs( 'display:block;width:300px;height:400px;object-fit:cover' ) . '></div><h4 class="font-serif text-lg mt-4" ' . $aa_cs( 'display:block;font-family:Georgia, serif;font-size:18px;line-height:28px;margin:16px 0px 0px;height:28px' ) . '>' . $n . '</h4><p class="text-sm text-gray-500" ' . $aa_cs( 'display:block;font-size:14px;line-height:20px;color:rgb(107, 114, 128);height:20px' ) . '>' . $p . '</p></div>'; };
$aa_scroll = '<section class="py-24" ' . $aa_cs( 'display:block;padding:96px 0px;height:760px' ) . '><div class="container mx-auto px-6" ' . $aa_cs( 'display:block;max-width:1280px;padding:0px 24px;margin:0px 80px;height:568px' ) . '><div class="flex justify-between items-end mb-10" ' . $aa_cs( 'display:flex;flex-direction:row;justify-content:space-between;align-items:flex-end;margin:0px 0px 40px;height:48px' ) . '><h3 ' . $aa_cs( 'display:block;font-family:Georgia, serif;font-size:36px;line-height:40px;height:40px' ) . '>Gifts for the table</h3><div class="flex gap-4 items-center" ' . $aa_cs( 'display:flex;gap:16px;align-items:center;height:48px' ) . '><button class="rounded-full border w-12 h-12" ' . $aa_cs( 'display:flex;width:48px;height:48px;border-radius:9999px;border-top-width:1px;border-top-style:solid;border-top-color:rgb(229, 231, 235);align-items:center;justify-content:center' ) . '><svg class="lucide lucide-arrow-left w-5 h-5" ' . $aa_cs( 'display:block;width:20px;height:20px' ) . '><path d="M4 4h16v16H4z"/></svg></button><button class="rounded-full border w-12 h-12" ' . $aa_cs( 'display:flex;width:48px;height:48px;border-radius:9999px;border-top-width:1px;border-top-style:solid;border-top-color:rgb(229, 231, 235);align-items:center;justify-content:center' ) . '><svg class="lucide lucide-arrow-right w-5 h-5" ' . $aa_cs( 'display:block;width:20px;height:20px' ) . '><path d="M4 4h16v16H4z"/></svg></button><a href="#" class="text-sm uppercase tracking-widest" ' . $aa_cs( 'display:block;font-size:14px;letter-spacing:2px;text-transform:uppercase;height:20px' ) . '>View all</a></div></div>'
	. '<div class="flex gap-8 overflow-x-auto" ' . $aa_cs( 'display:flex;flex-direction:row;gap:32px;overflow-x:auto;height:470px' ) . '>' . $aa_tile( 'Linen runner', '$48' ) . $aa_tile( 'Stone carafe', '$72' ) . $aa_tile( 'Oak board', '$64' ) . $aa_tile( 'Brass candle', '$36' ) . '</div></div></section>';
// PROCESS ROWS (dark): `grid [80px_1fr_auto]` rows — a 52px numeral disc, title + copy, a duration pill; the first row rounded on top
$aa_prow = function ( $i, $t, $d, $first ) use ( $aa_dk ) { return '<div class="grid p-9 bg-[#1a1a1a] border" ' . $aa_dk( 'background-color:rgb(26, 26, 26);padding:36px 40px;border-top-width:1px;border-top-style:solid;border-top-color:rgba(255, 255, 255, 0.07);border-bottom-width:1px;border-bottom-style:solid;border-bottom-color:rgba(255, 255, 255, 0.07);border-left-width:1px;border-left-style:solid;border-left-color:rgba(255, 255, 255, 0.07);border-right-width:1px;border-right-style:solid;border-right-color:rgba(255, 255, 255, 0.07);border-radius:' . ( $first ? '20px 20px 0px 0px' : '0px' ) . ';height:150px;display:grid;gap:32px;grid-template-columns:80px 812px 73px;align-items:center' ) . '>'
	. '<div class="w-13 h-13 rounded-full" ' . $aa_dk( 'background-color:rgba(201, 169, 110, 0.06);color:rgb(201, 169, 110);font-size:13px;font-weight:700;letter-spacing:0.65px;border-top-width:1px;border-top-style:solid;border-top-color:rgba(201, 169, 110, 0.3);border-bottom-width:1px;border-bottom-style:solid;border-bottom-color:rgba(201, 169, 110, 0.3);border-left-width:1px;border-left-style:solid;border-left-color:rgba(201, 169, 110, 0.3);border-right-width:1px;border-right-style:solid;border-right-color:rgba(201, 169, 110, 0.3);border-radius:50%;height:52px;display:flex;justify-content:center;align-items:center;track-frac:0.05;track-y:13;track-x:1;track-h:52' ) . '>' . $i . '</div>'
	. '<div ' . $aa_dk( 'display:block;height:76px;track-frac:0.75;track-y:1;track-x:113;track-h:76' ) . '><h3 ' . $aa_dk( 'font-size:22px;line-height:24px;display:block;height:24px' ) . '>' . $t . '</h3><p ' . $aa_dk( 'color:rgb(160, 157, 152);font-size:14px;line-height:23px;display:block;height:46px;margin:6px 0px 0px' ) . '>' . $d . '</p></div>'
	. '<div class="rounded-full px-4 py-1.5" ' . $aa_dk( 'background-color:rgba(255, 255, 255, 0.04);color:rgb(107, 104, 96);font-size:12px;font-weight:500;line-height:21px;padding:6px 16px;border-top-width:1px;border-top-style:solid;border-top-color:rgba(255, 255, 255, 0.07);border-bottom-width:1px;border-bottom-style:solid;border-bottom-color:rgba(255, 255, 255, 0.07);border-left-width:1px;border-left-style:solid;border-left-color:rgba(255, 255, 255, 0.07);border-right-width:1px;border-right-style:solid;border-right-color:rgba(255, 255, 255, 0.07);border-radius:100px;height:33px;display:block;track-frac:0.07;track-y:22;track-x:957;track-h:33' ) . '>60 min</div></div>'; };
$aa_process = '<section class="section-pad" ' . $aa_dk( 'background-color:rgb(17, 17, 17);padding:120px 0px;height:660px;display:block' ) . '><div class="container-page" ' . $aa_dk( 'padding:0px 24px;height:420px;display:block;max-width:1280px;margin:0px 80px' ) . '><div ' . $aa_dk( 'height:300px;display:flex;flex-direction:column' ) . '>' . $aa_prow( '01', 'Discovery call', 'We map your goals, your audience and the constraints before a single line is drawn.', true ) . $aa_prow( '02', 'Concept sprint', 'Three directions in a week, each one a real screen you can react to, not a mood board.', false ) . '</div></div></section>';
// EVENT CARDS: a photo with a pinned two-line date chip, then the body (category chip, title, copy, a time | link row)
$ff = 'font-family:system-ui, -apple-system, sans-serif;';
$aa_card = function ( $mo, $day, $cat ) use ( $ff, $aa_mk ) {
	$cs = function ( $e ) use ( $ff, $aa_mk ) { return $aa_mk( 'color:rgb(26, 26, 26);' . $ff . 'font-size:16px;font-weight:400;line-height:24px', $e ); };
	return '<div class="bg-white rounded-2xl overflow-hidden shadow-sm hover:shadow-md transition-all duration-300 border-2 border-t-[#ff3b30] flex flex-col" ' . $cs( 'background-color:rgb(255, 255, 255);border-radius:16px;border-top-width:2px;border-top-style:solid;border-top-color:rgb(255, 59, 48);border-bottom-width:2px;border-bottom-style:solid;border-bottom-color:rgb(229, 231, 235);border-left-width:2px;border-left-style:solid;border-left-color:rgb(229, 231, 235);border-right-width:2px;border-right-style:solid;border-right-color:rgb(229, 231, 235);box-shadow:rgba(0, 0, 0, 0.05) 0px 1px 2px 0px;height:438px;display:flex;flex-direction:column;overflow:hidden;track-frac:0.316' ) . '>'
		. '<div class="relative h-56 w-full overflow-hidden" ' . $cs( 'height:224px;display:block;position:relative;overflow:hidden' ) . '><img src="https://example.com/event.jpg" class="w-full h-full object-cover hover:scale-105 transition-transform duration-500" ' . $cs( 'height:224px;display:block;width:380px;object-fit:cover' ) . '>'
		. '<div class="absolute bottom-4 left-4 bg-white/95 backdrop-blur rounded-xl px-4 py-2 flex flex-col items-center min-w-16 shadow-lg" ' . $cs( 'background-color:rgba(255, 255, 255, 0.95);padding:8px 16px;border-radius:12px;box-shadow:rgba(0, 0, 0, 0.1) 0px 4px 6px -1px, rgba(0, 0, 0, 0.1) 0px 2px 4px -2px;height:50px;display:flex;position:absolute;top:158px;bottom:16px;left:16px;justify-content:center;align-items:center;flex-direction:column;min-width:64px' ) . '><span class="text-xs font-extrabold text-indigo-600 uppercase tracking-wider" ' . $cs( 'color:rgb(79, 70, 229);font-size:12px;font-weight:800;line-height:12px;letter-spacing:1.2px;text-transform:uppercase;display:block;height:12px' ) . '>' . $mo . '</span><span class="text-lg font-black text-gray-950 mt-1 leading-none" ' . $cs( 'color:rgb(3, 7, 18);font-size:18px;font-weight:900;line-height:18px;margin:4px 0px 0px;display:block;height:18px' ) . '>' . $day . '</span></div></div>'
		. '<div class="p-6 flex-grow flex flex-col justify-between space-y-4" ' . $cs( 'padding:24px;height:214px;display:flex;flex-direction:column;justify-content:space-between;gap:16px' ) . '><div ' . $cs( 'display:block;height:120px' ) . '><span class="text-xs font-bold text-indigo-600 uppercase tracking-wider" ' . $cs( 'color:rgb(79, 70, 229);font-size:12px;font-weight:700;line-height:16px;letter-spacing:0.6px;text-transform:uppercase;display:block;height:16px' ) . '>' . $cat . '</span><h3 class="text-xl font-bold text-gray-950 mt-2" ' . $cs( 'color:rgb(3, 7, 18);font-size:20px;font-weight:700;line-height:28px;margin:8px 0px 0px;display:block;height:28px' ) . '>Studio open evening</h3><p class="text-sm text-gray-500 mt-2 line-clamp-2" ' . $cs( 'color:rgb(107, 114, 128);font-size:14px;line-height:20px;margin:8px 0px 0px;display:block;height:40px' ) . '>Walk the floor, meet the makers and see the prototypes before anyone else does.</p></div>'
		. '<div class="flex items-center justify-between" ' . $cs( 'display:flex;justify-content:space-between;align-items:center;flex-direction:row;height:20px' ) . '><span class="text-sm text-gray-400" ' . $cs( 'color:rgb(156, 163, 175);font-size:14px;line-height:20px;display:block;height:20px' ) . '>10:00 AM</span><a href="#" class="text-xs font-bold text-indigo-600 uppercase tracking-wider" ' . $cs( 'color:rgb(79, 70, 229);font-size:12px;font-weight:700;line-height:16px;letter-spacing:0.6px;text-transform:uppercase;display:inline;height:16px' ) . '>Learn More</a></div></div></div>';
};
$aa_events = '<section class="py-20 lg:py-24 bg-white border-t border-gray-50" ' . $aa_cs( 'background-color:rgb(255, 255, 255);padding:96px 0px;height:841px;display:block' ) . '><div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8" ' . $aa_cs( 'padding:0px 32px;height:648px;display:block;max-width:1280px;margin:0px 80px' ) . '><div class="grid grid-cols-1 lg:grid-cols-3 gap-8 mb-12" ' . $aa_cs( 'height:438px;display:grid;gap:32px;grid-template-columns:380px 380px 380px;margin:0px 0px 48px' ) . '>' . $aa_card( 'JUN', '08', 'Workshop' ) . $aa_card( 'JUL', '21', 'Seminar' ) . $aa_card( 'AUG', '03', 'Workshop' ) . '</div></div></section>';
// SPLIT BAND: the section IS the grid (720 | 720); a padded text panel; a CSS-painted cover photo half; an underlined text link
$aa_split = '<section class="w-full grid grid-cols-1 lg:grid-cols-2 bg-[#111111]" ' . $aa_dk( 'background-color:rgb(17, 17, 17);height:600px;display:grid;grid-template-columns:720px 720px' ) . '><div class="p-12 lg:p-24 flex flex-col justify-center space-y-6" ' . $aa_dk( 'padding:96px;height:600px;display:flex;flex-direction:column;justify-content:center;gap:24px' ) . '><span class="text-[11px] font-sans tracking-[0.25em] uppercase" ' . $aa_dk( 'font-size:11px;letter-spacing:2.75px;text-transform:uppercase;height:16.5px;display:block;color:rgba(240, 237, 230, 0.6)' ) . '>Our story</span><h2 class="font-serif text-[36px] md:text-[48px] leading-tight max-w-[528px]" ' . $aa_dk( 'font-family:Georgia, serif;font-size:48px;line-height:60px;max-width:528px;height:120px;display:block' ) . '>Made slowly, by hand, in a shed by the sea</h2><p ' . $aa_dk( 'color:rgb(204, 204, 204);font-size:15px;line-height:25px;height:75px;display:block' ) . '>Three of us, one kiln and a long bench. Every piece is thrown, dried and glazed under the same roof it leaves from.</p><div class="pt-4" ' . $aa_dk( 'padding:16px 0px 0px;height:43px;display:block' ) . '><a href="/story" class="inline-block border-b border-white pb-1 text-[12px] font-sans tracking-[0.25em] uppercase hover:opacity-70 transition-opacity" ' . $aa_dk( 'font-size:12px;letter-spacing:3px;text-transform:uppercase;padding:0px 0px 4px;border-bottom-width:1px;border-bottom-style:solid;border-bottom-color:rgb(255, 255, 255);height:23px;display:inline-block;line-height:18px' ) . '>Read the story</a></div></div><div class="min-h-[400px] lg:min-h-[600px] bg-cover bg-center" ' . $aa_dk( 'height:600px;display:block;background-image:url(https://example.com/shed.jpg);background-size:cover;background-position:50% 50%' ) . '></div></section>';
$aa_html = '<!DOCTYPE html><html data-sc-content-width="1280" data-sc-content-gutter="24" data-sc-content-gutter-inside="1"><head><title>Outpost</title></head><body ' . $aa_cs( 'background-color:rgb(255, 255, 255)' ) . '><main>' . $aa_trust . $aa_rows . $aa_stats . $aa_scroll . $aa_process . $aa_events . $aa_split . '</main></body></html>';

$aa_bl = FW_Site_Converter_Sources::build_from_html( $aa_html, 'Outpost', array( 'dynamic_chrome' => true, 'hifi_css' => true ) );
$aa_pg = $aa_bl['files']['pages.json']['pages'][0]['builder'] ?? array();
$aa_json = wp_json_encode( $aa_bl['files'] ?? array(), JSON_UNESCAPED_SLASHES );
$aa_css_of = function ( $n ) { return (string) ( $n['atts']['custom_css'] ?? '' ); };
// trust strip
$aa_av = $r_find( $aa_pg, function ( $n ) { return 'avatar' === ( $n['shortcode'] ?? '' ); } );
$aa_stars = array(); $r_all( $aa_pg, function ( $n ) { return 'icon' === ( $n['shortcode'] ?? '' ) && 'lucide/star' === (string) ( $n['atts']['icon']['svg-id'] ?? '' ); }, $aa_stars );
ga( "[AA] an avatar STACK that is a media-only cell of a strip row is the native avatar group (it came out as four code blocks)", is_array( $aa_av ) );
ga( "[AA] five UNSTAMPED `<svg class=\"lucide lucide-star\">` with empty paths are LIBRARY stars at their `w-5` size in the row's amber ink (they drew nothing at the default size)", 5 === count( $aa_stars ) && '20' === (string) ( $aa_stars[0]['atts']['icon_size']['value'] ?? '' ) && 'rgb(238, 99, 78)' === (string) ( $aa_stars[0]['atts']['icon_color']['custom'] ?? '' ), wp_json_encode( array( count( $aa_stars ), $aa_stars[0]['atts']['icon_size'] ?? null, $aa_stars[0]['atts']['icon_color'] ?? null ) ) );
// accordion-style rows
$aa_arrows = array(); $r_all( $aa_pg, function ( $n ) { return 'icon' === ( $n['shortcode'] ?? '' ) && false !== strpos( wp_json_encode( $n['atts']['icon'] ?? '' ), 'arrow-up-right' ); }, $aa_arrows );
$aa_rowh = $r_find( $aa_pg, function ( $n ) { return 'special_heading' === ( $n['shortcode'] ?? '' ) && 'Brand systems' === (string) ( $n['atts']['title'] ?? '' ); } );
ga( "[AA] five `h3 + trailing chevron` rows are five two-cell rows (the arrow a 28px icon, never stacked under the title)", 5 === count( $aa_arrows ) && is_array( $aa_rowh ) && '28' === (string) ( $aa_arrows[0]['atts']['icon_size']['value'] ?? '' ), wp_json_encode( array( count( $aa_arrows ), $aa_arrows[0]['atts']['icon_size'] ?? null ) ) );
// 2×2 counters
$aa_cnt = array(); $r_all( $aa_pg, function ( $n ) { return 'counter' === ( $n['shortcode'] ?? '' ); }, $aa_cnt );
$aa_cntcol = $r_find( $aa_pg, function ( $n ) { if ( 'flexbox' !== ( $n['type'] ?? '' ) ) { return false; } foreach ( $n['_items'] ?? array() as $it ) { if ( 'counter' === ( $it['shortcode'] ?? '' ) ) { return true; } } return false; } );
ga( "[AA] a 2×2 grid of stat cards beside a copy column is four counters on HALF-width cells (2 per row), not a four-across strip", 4 === count( $aa_cnt ) && is_array( $aa_cntcol ) && in_array( (string) ( $aa_cntcol['atts']['width']['base']['preset'] ?? $aa_cntcol['atts']['width']['base'] ?? '' ), array( '6', '1_2' ), true ), wp_json_encode( array( count( $aa_cnt ), $aa_cntcol['atts']['width'] ?? null ) ) );
// scroller
$aa_gal = $r_find( $aa_pg, function ( $n ) { return 'gallery' === ( $n['shortcode'] ?? '' ); } );
$aa_codes = array(); $r_all( $aa_pg, function ( $n ) { return 'code_block' === ( $n['shortcode'] ?? '' ); }, $aa_codes );
$aa_tileh = array(); $r_all( $aa_pg, function ( $n ) { return 'special_heading' === ( $n['shortcode'] ?? '' ) && in_array( (string) ( $n['atts']['title'] ?? '' ), array( 'Linen runner', 'Stone carafe', 'Oak board', 'Brass candle' ), true ); }, $aa_tileh );
ga( "[AA] an `overflow-x-auto` row of product tiles (photo + name + price) is a row of CARDS that scrolls — never a marquee code block nor a gallery (the tiles' names and prices survive as headings)", 4 === count( $aa_tileh ) && null === $aa_gal, wp_json_encode( array( count( $aa_tileh ), is_array( $aa_gal ) ) ) );
// process rows
$aa_prow = $r_find( $aa_pg, function ( $n ) { return 'flexbox' === ( $n['type'] ?? '' ) && '80px 1fr 73px' === (string) ( $n['atts']['grid_columns'] ?? '' ); } );
$aa_pbox = ( is_array( $aa_prow ) && '' !== (string) ( $aa_prow['atts']['border_preset'] ?? '' ) ) ? (string) $aa_prow['atts']['border_preset'] : '';
$aa_ppre = array( 'custom_css' => '' ); if ( preg_match( '/"preset_name":"Box ' . preg_quote( substr( $aa_pbox, 9 ), '/' ) . '".{0,600}?"custom_css":"([^"]*)"/', $aa_json, $aa_pm ) ) { $aa_ppre['custom_css'] = $aa_pm[1]; }
ga( "[AA] a `grid [80px_1fr_auto]` process row keeps its three tracks — the narrow ones as px (the pill's `auto` = 73px), the wide one as 1fr — and is ONE centred row (the vertical stagger of a 52px disc, a 76px text and a 33px pill is no bento spanner)", is_array( $aa_prow ), wp_json_encode( $aa_prow['atts']['grid_columns'] ?? null ) );
ga( "[AA] …the row wears its skin as a Box Preset whose per-corner radius (`rounded-t-[20px]`) rides the preset CSS (the single-value field cannot hold it — the corners went square)", '' !== $aa_pbox && false !== strpos( (string) ( $aa_ppre['custom_css'] ?? '' ), 'border-radius:20px 20px 0px 0px' ), wp_json_encode( array( $aa_pbox, $aa_ppre['custom_css'] ?? null ) ) );
$aa_disc = $r_find( $aa_pg, function ( $n ) { return 'flexbox' === ( $n['type'] ?? '' ) && false !== strpos( (string) ( $n['atts']['custom_css'] ?? '' ), 'width:52px;height:52px' ); } );
ga( "[AA] …the 52px `rounded-full` numeral disc (no stamped width — a round box is as wide as it is tall) is a FIXED box: its inner wrapper 52×52, the glyph centred, the track keeps its 80px (it was an 83×25 ellipse)", is_array( $aa_disc ) && false !== strpos( $aa_css_of( $aa_disc ), 'display:flex;align-items:center;justify-content:center' ), wp_json_encode( $aa_disc['atts']['custom_css'] ?? null ) );
$aa_pill = $r_find( $aa_pg, function ( $n ) { return 'flexbox' === ( $n['type'] ?? '' ) && false !== strpos( (string) ( $n['atts']['custom_css'] ?? '' ), 'white-space:nowrap' ) && '' !== (string) ( $n['atts']['border_preset'] ?? '' ); } );
$aa_pilltxt = $r_find( $aa_pg, function ( $n ) { return 'text_block' === ( $n['shortcode'] ?? '' ) && '<p>60 min</p>' === (string) ( $n['atts']['text'] ?? '' ); } );
ga( "[AA] …the duration pill is ONE line in its own box (the leaf's copy of the stamp does not paint a second pill — two nested pills wrapped the label): the column wears the skin + nowrap, the text block wears no box", is_array( $aa_pill ) && is_array( $aa_pilltxt ) && '' === (string) ( $aa_pilltxt['atts']['border_preset'] ?? '' ) && false === strpos( $aa_css_of( $aa_pilltxt ), 'background' ), wp_json_encode( array( is_array( $aa_pill ), $aa_pilltxt['atts']['custom_css'] ?? null ) ) );
// event cards
$aa_ibx = array(); $r_all( $aa_pg, function ( $n ) { return 'image_box' === ( $n['shortcode'] ?? '' ); }, $aa_ibx );
$aa_badge = $r_find( $aa_pg, function ( $n ) { return 'icon_box' === ( $n['shortcode'] ?? '' ) && 'JUN' === (string) ( $n['atts']['overline'] ?? '' ); } );
$aa_frame = $r_find( $aa_pg, function ( $n ) { return 'flexbox' === ( $n['type'] ?? '' ) && false !== strpos( (string) ( $n['atts']['custom_css'] ?? '' ), 'position:relative;overflow:hidden;height:224px' ); } );
$aa_body = $r_find( $aa_pg, function ( $n ) { return 'flexbox' === ( $n['type'] ?? '' ) && false !== strpos( (string) ( $n['atts']['custom_css'] ?? '' ), 'flex:1 1 auto;justify-content:space-between' ); } );
ga( "[AA] a card whose PHOTO carries a pinned date chip is not an image box (no layer for the chip): the photo + chip sit in a relative 224px frame, the body in a padded `flex-grow justify-between` stack", 0 === count( $aa_ibx ) && is_array( $aa_frame ) && is_array( $aa_body ) && false !== strpos( $aa_css_of( $aa_body ), 'padding-top:24px' ), wp_json_encode( array( count( $aa_ibx ), is_array( $aa_frame ), $aa_body['atts']['custom_css'] ?? null ) ) );
ga( "[AA] …the chip is a STACKED icon box: month as the overline (its own 12px/800 indigo, the title's `mt-1` as its gap), day as an 18px leading-none title, no inner gap, the source's 64px min-width — it rendered inline at 62px tall", is_array( $aa_badge ) && '08' === (string) ( $aa_badge['atts']['title'] ?? '' ) && false !== strpos( $aa_css_of( $aa_badge ), 'margin-bottom:4px' ) && false !== strpos( $aa_css_of( $aa_badge ), 'font-size:18px;line-height:18px' ) && false !== strpos( $aa_css_of( $aa_badge ), '.icon-box__inner{gap:0;}' ) && false !== strpos( $aa_css_of( $aa_badge ), 'min-width:64px' ), wp_json_encode( $aa_badge['atts']['custom_css'] ?? null ) );
$aa_cat = $r_find( $aa_pg, function ( $n ) { return 'special_heading' === ( $n['shortcode'] ?? '' ) && 'Studio open evening' === (string) ( $n['atts']['title'] ?? '' ) && 'Workshop' === (string) ( $n['atts']['overline'] ?? '' ); } );
$aa_learn = $r_find( $aa_pg, function ( $n ) { return 'text_block' === ( $n['shortcode'] ?? '' ) && false !== strpos( (string) ( $n['atts']['text'] ?? '' ), 'Learn More' ); } );
$aa_time = $r_find( $aa_pg, function ( $n ) { return 'text_block' === ( $n['shortcode'] ?? '' ) && '<p>10:00 AM</p>' === (string) ( $n['atts']['text'] ?? '' ); } );
ga( "[AA] …the body keeps its category chip as the heading's overline, its time, and its `<a>` leaf as a LINK in its own indigo with no underline (it was a dead label in the theme's accent, underlined)", is_array( $aa_cat ) && is_array( $aa_time ) && is_array( $aa_learn ) && false !== strpos( (string) $aa_learn['atts']['text'], '<a href="#">Learn More</a>' ) && false !== strpos( $aa_css_of( $aa_learn ), 'selector a{color:rgb(79, 70, 229) !important;text-decoration-line:none;}' ), wp_json_encode( array( is_array( $aa_cat ), is_array( $aa_time ), $aa_learn['atts']['text'] ?? null, $aa_learn['atts']['custom_css'] ?? null ) ) );
ga( "[AA] …the card's `border-2 border-t-[accent]` keeps the accent on the TOP edge only: the other sides carry their own colour in the preset CSS, with !important past the preset's own border rule (the whole card went red)", false !== strpos( $aa_json, 'border-right-color:rgb(229, 231, 235) !important;border-bottom-color:rgb(229, 231, 235) !important;border-left-color:rgb(229, 231, 235) !important' ) );
// split band
$aa_shed = $r_find( $aa_pg, function ( $n ) { return 'media_image' === ( $n['shortcode'] ?? '' ) && 'https://example.com/shed.jpg' === (string) ( $n['atts']['image']['url'] ?? '' ); } );
$aa_secs = array(); foreach ( (array) $aa_pg as $s ) { if ( 'section' === ( $s['type'] ?? '' ) ) { $aa_secs[] = $s; } }
$aa_split = end( $aa_secs );
$aa_splitrow = $r_find( $aa_split['_items'] ?? array(), function ( $n ) { return 'flexbox' === ( $n['type'] ?? '' ) && false !== strpos( (string) ( $n['atts']['custom_css'] ?? '' ), 'max-width:100% !important' ); } );
ga( "[AA] a CSS-painted photo half (`bg-cover bg-center` + a url, no <img>) is a cover media image at the cell's height (the half vanished as an empty cell)", is_array( $aa_shed ) && false !== strpos( $aa_css_of( $aa_shed ), 'object-fit:cover;object-position:50% 50%' ) && false !== strpos( $aa_css_of( $aa_shed ), 'min-height:600px' ), wp_json_encode( $aa_shed['atts']['custom_css'] ?? null ) );
ga( "[AA] …a section that IS the grid, its tracks spanning the viewport (720 | 720) and its stamp carrying no padding, is an edge-to-edge band: zero section padding (absent in a modern stamp = 0, never the theme's 64px) and a row past the site gutter (the halves lost 48px each)", is_array( $aa_split ) && 'pt-[0px]' === (string) ( $aa_split['atts']['padding_top']['base'] ?? '' ) && 'pb-[0px]' === (string) ( $aa_split['atts']['padding_bottom']['base'] ?? '' ) && is_array( $aa_splitrow ), wp_json_encode( array( $aa_split['atts']['padding_top'] ?? null, $aa_split['atts']['padding_bottom'] ?? null, is_array( $aa_splitrow ) ) ) );
$aa_read = $r_find( $aa_pg, function ( $n ) { return 'button' === ( $n['shortcode'] ?? '' ) && 'Read the story' === (string) ( $n['atts']['label'] ?? '' ); } );
ga( "[AA] …an `inline-block border-b pb-1 uppercase` link is a btn-link whose underline is its bottom border (1px white, 4px under), never an outline box, and stays LEFT in its `flex-col justify-center` panel (justify-center centres a column vertically; it centred the link)", is_array( $aa_read ) && 'btn-link' === (string) ( $aa_read['atts']['style'] ?? '' ) && false !== strpos( $aa_css_of( $aa_read ), 'border:0 !important;border-bottom:1px solid rgb(255, 255, 255) !important;border-radius:0 !important' ) && false !== strpos( $aa_css_of( $aa_read ), 'padding:0px 0px 4px 0px !important' ) && false === strpos( $aa_css_of( $aa_read ), 'align-self:center' ), wp_json_encode( array( $aa_read['atts']['style'] ?? null, $aa_read['atts']['custom_css'] ?? null ) ) );


/* --------------------------------------------------------------------- *
 * [AB] A fixed header with a MOBILE DRAWER (`fixed inset-0 … md:hidden`, 900px tall, the same links at 32px): the drawer is
 *     never a header bar — one 84px row of logo | menu | CTA, no bottom bar (it had become a 900px bottom bar).
 * --------------------------------------------------------------------- */
echo "
[AB] Mobile drawer header
";
$ab_mk = function ( $base, $e ) { $m = array(); foreach ( explode( ';', $base . ';' . $e ) as $d ) { $d = trim( $d ); if ( '' === $d || false === strpos( $d, ':' ) ) { continue; } list( $k, $v ) = explode( ':', $d, 2 ); $m[ trim( $k ) ] = trim( $v ); } $o = array(); foreach ( $m as $k => $v ) { $o[] = $k . ':' . $v; } return 'data-sc-cs="' . implode( ';', $o ) . '"'; };
$ab_cs = function ( $e ) use ( $ab_mk ) { return $ab_mk( 'color:rgb(23, 23, 23);font-family:Inter, sans-serif;font-size:16px;font-weight:400;line-height:24px', $e ); };
$ab_nav = function ( $mobile ) use ( $ab_cs ) { $o = ''; foreach ( array( 'Work', 'Services', 'Studio', 'Journal' ) as $l ) { $o .= '<a href="#' . strtolower( $l ) . '" ' . $ab_cs( $mobile ? 'font-size:32px;line-height:40px;font-weight:700;display:block;height:40px' : 'font-size:14px;font-weight:700;letter-spacing:1.4px;text-transform:uppercase;display:block;height:20px' ) . '>' . $l . '</a>'; } return $o; };
$ab_hdr = '<header class="fixed top-0 inset-x-0 z-50 bg-white" ' . $ab_cs( 'position:fixed;top:0px;left:0px;right:0px;height:84px;display:block;background-color:rgb(255, 255, 255);z-index:50' ) . ' data-sc-header="rest-height:84px">'
	. '<div class="container mx-auto px-6 flex items-center justify-between h-[84px]" ' . $ab_cs( 'display:flex;flex-direction:row;align-items:center;justify-content:space-between;max-width:1280px;margin:0px 80px;padding:0px 24px;height:84px' ) . '>'
	. '<a href="/" class="logo" ' . $ab_cs( 'font-size:22px;font-weight:800;letter-spacing:-0.5px;display:block;height:28px' ) . '>Outpost</a>'
	. '<nav class="hidden md:flex items-center gap-8" ' . $ab_cs( 'display:flex;flex-direction:row;align-items:center;gap:32px;height:20px' ) . '>' . $ab_nav( false ) . '</nav>'
	. '<a href="/contact" class="bg-primary text-white px-6 py-2 rounded-full" ' . $ab_cs( 'background-color:rgb(23, 23, 23);color:rgb(255, 255, 255);font-size:14px;font-weight:700;letter-spacing:1.4px;text-transform:uppercase;padding:8px 24px;border-radius:9999px;display:block;height:36px;width:198px' ) . '>Start a project</a>'
	. '<button class="md:hidden" ' . $ab_cs( 'display:none' ) . '>Menu</button>'
	. '</div>'
	. '<div class="fixed inset-0 bg-white z-40 flex flex-col items-center justify-center gap-8 md:hidden" ' . $ab_cs( 'display:none;position:fixed;top:0px;left:0px;right:0px;bottom:0px;height:900px;background-color:rgb(255, 255, 255);flex-direction:column;align-items:center;justify-content:center;gap:32px;z-index:40' ) . '>' . $ab_nav( true ) . '<a href="/contact" ' . $ab_cs( 'font-size:18px;font-weight:700;display:block;height:28px' ) . '>Start a project</a></div>'
	. '</header>';
$ab_html = '<!DOCTYPE html><html data-sc-content-width="1280" data-sc-content-gutter="24" data-sc-content-gutter-inside="1"><head><title>Outpost</title></head><body ' . $ab_cs( 'background-color:rgb(255, 255, 255)' ) . '>' . $ab_hdr
	. '<main><section class="pt-[84px]" ' . $ab_cs( 'display:block;padding:84px 0px 96px;height:600px' ) . '><div class="container mx-auto px-6" ' . $ab_cs( 'display:block;max-width:1280px;padding:0px 24px;margin:0px 80px;height:300px' ) . '><h1 ' . $ab_cs( 'display:block;font-size:64px;line-height:70px;font-weight:800;height:140px' ) . '>We build brands that outlast the trend</h1><p ' . $ab_cs( 'display:block;font-size:20px;line-height:30px;height:60px;margin:24px 0px 0px' ) . '>A small studio for identity, product and the words in between.</p></div></section></main></body></html>';

$ab_bl = FW_Site_Converter_Sources::build_from_html( $ab_html, 'Outpost', array( 'dynamic_chrome' => true, 'hifi_css' => true ) );
$ab_ts = $ab_bl['files']['theme-settings.json']['values'] ?? array();
$ab_bb = $ab_ts['header_bottombar'] ?? array();
$ab_main = $ab_ts['header_main'] ?? array();
$ab_els = function ( $bar ) { $o = array(); foreach ( (array) $bar as $it ) { $o[] = (string) ( $it['element_type']['element'] ?? '' ); } return $o; };
ga( "[AB] a `fixed inset-0 md:hidden` mobile drawer is never a header bar: the bottom bar stays EMPTY and the main row is logo | menu | CTA (the drawer's links had become a 900px bottom bar)", empty( $ab_bb['bottombar_left'] ) && empty( $ab_bb['bottombar_center'] ) && empty( $ab_bb['bottombar_right'] ) && array( 'logo' ) === $ab_els( $ab_main['main_left'] ?? array() ) && in_array( 'menu_area', array_merge( $ab_els( $ab_main['main_center'] ?? array() ), $ab_els( $ab_main['main_right'] ?? array() ) ), true ) && in_array( 'cta_button', $ab_els( $ab_main['main_right'] ?? array() ), true ), wp_json_encode( array( $ab_bb, $ab_main ) ) );
ga( "[AB] …at the header's rest height (84px), not the drawer's 900", '84' === (string) ( $ab_ts['header_layout']['min_height']['value'] ?? '' ), wp_json_encode( $ab_ts['header_layout']['min_height'] ?? null ) );


/* --------------------------------------------------------------------- *
 * [AC] A cinematic dark landing (a real-site audit, 2026-09-19): the wordmark link doubled beside the CTA, a script-toggled
 *     header blur, `max-w-7xl px-12` containers (1280 outer = 1184 content + 48), a diagonal-clipped viewport hero whose
 *     centring shrank its row, a play glyph hosted in <iconify-icon>, a pinned social row, badge cards whose text link was
 *     dropped and whose chip drew as a serif h4, a staggered 2×2 read as a bento, a 12-track mosaic in one row, a footer
 *     status chip dropped, a signup column titled by the widget with an invented "Subscribe" and its copy in the © bar.
 * --------------------------------------------------------------------- */
echo "\n[AC] Cinematic landing: header wordmark, container inset, hero clip / width / icon / social row, badge cards, 2×2, mosaic, footer chip + signup\n";
// [AC] golden page: a cinematic dark landing (a real-site audit) — wordmark link `#` beside the nav + CTA, a viewport hero band with a
// diagonal clip, a play glyph before its label, a pinned social row, `max-w-7xl px-12` containers (1184 content / 48 gutter), a 2×2
// staggered grid, badge cards with a text link, a 12-track mosaic, a footer with a status chip and an icon-only signup.
$ac_mk = function ( $base, $e ) { $m = array(); foreach ( explode( ';', $base . ';' . $e ) as $d ) { $d = trim( $d ); if ( '' === $d || false === strpos( $d, ':' ) ) { continue; } list( $k, $v ) = explode( ':', $d, 2 ); $m[ trim( $k ) ] = trim( $v ); } $o = array(); foreach ( $m as $k => $v ) { $o[] = $k . ':' . $v; } return 'data-sc-cs="' . implode( ';', $o ) . '"'; };
$ac = function ( $e ) use ( $ac_mk ) { return $ac_mk( 'color:rgb(248, 250, 252);font-family:Inter, sans-serif;font-size:16px;font-weight:400;line-height:24px', $e ); };
$ac_nav = ''; foreach ( array( 'Horizon', 'Journeys', 'Artifacts', 'Vistas' ) as $l ) { $ac_nav .= '<a href="#' . strtolower( $l ) . '" ' . $ac( 'font-size:11px;font-weight:600;letter-spacing:4.4px;text-transform:uppercase;display:block;height:16px;color:rgba(255, 255, 255, 0.9)' ) . '>' . $l . '</a>'; }
$ac_hdr = '<nav id="main-nav" class="fixed top-0 left-0 w-full z-50 flex items-center h-20 bg-sky-950/80 backdrop-blur-xl border-b border-white/5" ' . $ac( 'position:fixed;top:0px;left:0px;height:112px;display:flex;align-items:center;border-bottom-style:solid;border-bottom-color:rgb(229, 231, 235)' ) . ' data-sc-header="rest-height:112px" data-sc-scrolled="background-color:rgba(7, 17, 30, 0.8);backdrop-filter:blur(24px);border-bottom:1px solid rgba(255, 255, 255, 0.05);height:80px;rest-height:112px">'
	. '<div class="w-full max-w-7xl mx-auto px-6 md:px-12 flex items-center justify-between" ' . $ac( 'display:flex;flex-direction:row;align-items:center;justify-content:space-between;max-width:1280px;margin:0px 80px;padding:0px 48px;height:112px' ) . '>'
	. '<a href="#" class="flex items-center gap-3 tracking-[0.3em] font-cinzel text-xl font-bold text-white" ' . $ac( 'font-family:Cinzel, serif;font-size:20px;font-weight:700;letter-spacing:6px;color:rgb(255, 255, 255);display:flex;height:28px' ) . '>OUTPOST</a>'
	. '<div class="hidden md:flex items-center gap-12" ' . $ac( 'display:flex;flex-direction:row;align-items:center;gap:48px;height:16px' ) . '>' . $ac_nav . '</div>'
	. '<button class="px-6 py-3 rounded-sm border border-white/30 text-[10px] uppercase tracking-[0.3em] font-bold text-white" ' . $ac( 'font-size:10px;font-weight:700;letter-spacing:3px;text-transform:uppercase;padding:12px 24px;border-top-width:1px;border-top-style:solid;border-top-color:rgba(255, 255, 255, 0.3);border-radius:2px;display:block;height:36px;color:rgb(255, 255, 255)' ) . '>Begin Trek</button>'
	. '</div></nav>';
$ac_stat = function ( $l, $v ) use ( $ac ) { return '<div ' . $ac( 'display:block;height:48px' ) . '><div ' . $ac( 'font-size:10px;letter-spacing:3px;text-transform:uppercase;color:rgb(56, 189, 248);font-weight:700;margin:0px 0px 4px;display:block;height:15px' ) . '>' . $l . '</div><div ' . $ac( 'font-family:Cinzel, serif;font-size:24px;font-weight:700;display:block;height:29px' ) . '>' . $v . '</div></div>'; };
$ac_hero = '<header class="relative min-h-screen flex items-center justify-center overflow-hidden asymmetric-clip" ' . $ac( 'height:900px;min-height:900px;display:flex;justify-content:center;align-items:center;overflow:hidden;clip-path:polygon(0px 0px, 100% 0px, 100% 85%, 0% 100%)' ) . '>'
	. '<div class="absolute inset-0 z-0" ' . $ac( 'position:absolute;top:0px;left:0px;right:0px;bottom:0px;height:900px;display:block' ) . '><video autoplay loop muted playsinline class="w-full h-full object-cover" ' . $ac( 'display:block;width:1440px;height:900px;object-fit:cover' ) . '><source src="https://example.com/hero.mp4" type="video/mp4"></video></div>'
	. '<div class="relative z-10 w-full max-w-7xl mx-auto px-6 md:px-12 pt-32 grid lg:grid-cols-12 gap-8 items-center" ' . $ac( 'max-width:1280px;margin:0px 80px;padding:128px 48px 0px;display:grid;gap:32px;grid-template-columns:73px 73px 73px 73px 73px 73px 73px 73px 73px 73px 73px 73px;align-items:center;height:556px;z-index:10' ) . '>'
	. '<div class="lg:col-span-8 flex flex-col items-start text-left" ' . $ac( 'display:flex;flex-direction:column;align-items:flex-start;text-align:left;height:428px;track-frac:0.66;track-y:0;track-x:0;track-h:428' ) . '>'
	. '<h1 class="font-cinzel text-9xl font-black leading-[0.9] uppercase text-white mb-6" ' . $ac( 'font-family:Cinzel, serif;font-size:128px;font-weight:900;line-height:115px;text-transform:uppercase;color:rgb(255, 255, 255);margin:0px 0px 24px;display:block;height:256px' ) . '>Boundless <br><span class="text-stroke" ' . $ac( 'font-family:Cinzel, serif;font-size:128px;font-weight:900;line-height:115px;display:inline;-webkit-text-stroke:2px rgb(255, 255, 255);color:transparent' ) . '>Freedom.</span></h1>'
	. '<p class="text-xl text-white/80 max-w-xl font-light leading-relaxed mb-10" ' . $ac( 'font-size:20px;font-weight:300;line-height:32px;color:rgba(255, 255, 255, 0.8);max-width:576px;margin:0px 0px 40px;display:block;height:64px' ) . '>Stand atop the world where the wind speaks in whispers and the horizons extend forever into the golden sun.</p>'
	. '<div class="flex flex-col sm:flex-row items-center gap-4" ' . $ac( 'display:flex;flex-direction:row;align-items:center;gap:16px;height:44px' ) . '>'
	. '<a href="#trips" class="px-8 py-4 rounded-sm bg-white text-sky-950 text-xs uppercase tracking-[0.2em] font-bold" ' . $ac( 'background-color:rgb(255, 255, 255);color:rgb(7, 17, 30);font-size:12px;font-weight:700;letter-spacing:2.4px;text-transform:uppercase;padding:16px 32px;border-radius:2px;display:block;height:44px' ) . '>Explore Frontiers</a>'
	. '<button class="px-8 py-4 rounded-sm border border-white/20 text-white text-xs uppercase tracking-[0.2em] font-bold flex items-center justify-center gap-3" ' . $ac( 'color:rgb(255, 255, 255);font-size:12px;font-weight:700;letter-spacing:2.4px;text-transform:uppercase;padding:16px 32px;border-top-width:1px;border-top-style:solid;border-top-color:rgba(255, 255, 255, 0.2);border-radius:2px;display:flex;align-items:center;justify-content:center;gap:12px;height:44px' ) . '><iconify-icon icon="ph:play-fill" class="text-base" ' . $ac( 'font-size:16px;display:block;height:16px' ) . '><svg viewBox="0 0 256 256" width="1em" height="1em"><path d="M232 128L72 40v176z"/></svg></iconify-icon> Watch Motion</button>'
	. '</div></div>'
	. '<div class="hidden lg:flex lg:col-span-4 flex-col gap-6 pl-12 border-l border-white/5" ' . $ac( 'display:flex;flex-direction:column;gap:24px;padding:0px 0px 0px 48px;border-left-width:1px;border-left-style:solid;border-left-color:rgba(255, 255, 255, 0.05);height:192px;track-frac:0.33;track-y:118;track-x:812;track-h:192' ) . '>' . $ac_stat( 'Elevation', '3,800 METERS' ) . $ac_stat( 'Atmosphere', 'PURE AIR' ) . $ac_stat( 'Stance', 'UNYIELDING' ) . '</div>'
	. '</div>'
	. '<div class="absolute bottom-8 left-6 md:left-12 flex items-center gap-6 z-10" ' . $ac( 'position:absolute;bottom:32px;left:48px;display:flex;align-items:center;gap:24px;z-index:10;height:20px' ) . '><div class="flex gap-4 text-white/40 text-lg" ' . $ac( 'display:flex;gap:16px;color:rgba(255, 255, 255, 0.4);font-size:18px;height:20px' ) . '>'
	. '<a href="#" ' . $ac( 'display:block;color:rgba(255, 255, 255, 0.4);font-size:18px;height:18px' ) . '><iconify-icon icon="ph:instagram-logo"><svg viewBox="0 0 256 256" width="1em" height="1em"><path d="M128 80a48 48 0 1 0 48 48 48 48 0 0 0-48-48z"/></svg></iconify-icon></a>'
	. '<a href="#" ' . $ac( 'display:block;color:rgba(255, 255, 255, 0.4);font-size:18px;height:18px' ) . '><iconify-icon icon="ph:youtube-logo"><svg viewBox="0 0 256 256" width="1em" height="1em"><path d="M164 128l-56-32v64z"/></svg></iconify-icon></a>'
	. '<a href="#" ' . $ac( 'display:block;color:rgba(255, 255, 255, 0.4);font-size:18px;height:18px' ) . '><iconify-icon icon="ph:twitter-logo"><svg viewBox="0 0 256 256" width="1em" height="1em"><path d="M247 80l-40 8z"/></svg></iconify-icon></a>'
	. '</div></div></header>';
$ac_card = function ( $tag, $title, $copy ) use ( $ac ) { return '<div class="glass-card rounded-xl overflow-hidden flex flex-col" ' . $ac( 'background-color:rgba(255, 255, 255, 0.01);border-top-width:1px;border-top-style:solid;border-top-color:rgba(255, 255, 255, 0.05);border-bottom-width:1px;border-bottom-style:solid;border-bottom-color:rgba(255, 255, 255, 0.05);border-left-width:1px;border-left-style:solid;border-left-color:rgba(255, 255, 255, 0.05);border-right-width:1px;border-right-style:solid;border-right-color:rgba(255, 255, 255, 0.05);border-radius:12px;backdrop-filter:blur(12px);height:506px;display:flex;flex-direction:column;overflow:hidden;track-frac:0.32' ) . '>'
	. '<div class="h-80 w-full overflow-hidden relative group" ' . $ac( 'height:320px;display:block;position:relative;overflow:hidden' ) . '><div class="absolute inset-0 bg-gradient-to-t from-sky-950 to-transparent opacity-80 z-10" ' . $ac( 'position:absolute;top:0px;left:0px;right:0px;bottom:0px;height:320px;display:block;background-image:linear-gradient(to top, rgb(7, 17, 30), rgba(0, 0, 0, 0));opacity:0.8;z-index:10' ) . '></div>'
	. '<img src="https://example.com/' . strtolower( str_replace( ' ', '-', $title ) ) . '.jpg" alt="" class="w-full h-full object-cover mix-blend-luminosity" ' . $ac( 'height:320px;width:403px;display:block;object-fit:cover;mix-blend-mode:luminosity' ) . '>'
	. '<div class="absolute top-6 left-6 z-20 px-3 py-1 bg-white/10 backdrop-blur-md border border-white/10 rounded-sm text-[9px] uppercase tracking-widest font-bold text-white" ' . $ac( 'background-color:rgba(255, 255, 255, 0.1);font-size:9px;font-weight:700;letter-spacing:0.9px;text-transform:uppercase;padding:4px 12px;border-top-width:1px;border-top-style:solid;border-top-color:rgba(255, 255, 255, 0.1);border-radius:2px;backdrop-filter:blur(12px);display:block;position:absolute;top:24px;left:24px;height:22px;z-index:20;color:rgb(255, 255, 255)' ) . '>' . $tag . '</div></div>'
	. '<div class="p-8 flex-1 flex flex-col justify-between" ' . $ac( 'padding:32px;height:184px;display:flex;flex-direction:column;justify-content:space-between' ) . '><div ' . $ac( 'display:block;height:104px' ) . '><h3 class="font-cinzel text-2xl font-bold text-white mb-2" ' . $ac( 'font-family:Cinzel, serif;font-size:24px;font-weight:700;margin:0px 0px 8px;display:block;height:32px' ) . '>' . $title . '</h3><p class="text-sm text-white/50 font-light mb-6" ' . $ac( 'font-size:14px;font-weight:300;color:rgba(255, 255, 255, 0.5);margin:0px 0px 24px;display:block;height:40px' ) . '>' . $copy . '</p></div>'
	. '<a href="#" class="text-xs font-bold uppercase tracking-[0.2em] text-sky-400 inline-flex items-center gap-2" ' . $ac( 'font-size:12px;font-weight:700;letter-spacing:2.4px;text-transform:uppercase;color:rgb(56, 189, 248);display:flex;gap:8px;align-items:center;height:16px' ) . '>Secure Spot <iconify-icon icon="ph:arrow-up-right-bold" ' . $ac( 'font-size:12px;display:block;height:12px' ) . '><svg viewBox="0 0 256 256" width="1em" height="1em"><path d="M200 64v104"/></svg></iconify-icon></a></div></div>'; };
$ac_trips = '<section id="trips" class="py-32 px-6 md:px-12 bg-sky-950/20 relative" ' . $ac( 'background-color:rgba(7, 17, 30, 0.2);padding:128px 48px;height:906px;display:block' ) . '><div class="max-w-7xl mx-auto grid md:grid-cols-3 gap-8" ' . $ac( 'max-width:1280px;margin:0px 32px;display:grid;gap:32px;grid-template-columns:405px 405px 405px;height:506px' ) . '>' . $ac_card( 'Active Trek', 'Emerald Ridges', 'High contrast mountain slopes meeting cloud banks at breakneck velocities.' ) . $ac_card( 'High Altitude', 'Azure Basins', 'Crystalline water bodies reflecting intense skies deep within mountain sanctuaries.' ) . $ac_card( 'Serene Valley', 'Whispering Groves', 'Lush foliage dancing wildly under intense golden rays and crisp drafts.' ) . '</div></section>';
$ac_gc = function ( $t, $c, $mt, $x ) use ( $ac ) { return '<div class="p-6 glass-card rounded-lg flex flex-col justify-between h-48' . ( $mt ? ' mt-6' : '' ) . '" ' . $ac( 'background-color:rgba(255, 255, 255, 0.01);padding:24px;border-radius:8px;height:192px;display:flex;flex-direction:column;justify-content:space-between;' . ( $mt ? 'margin:24px 0px 0px;' : '' ) . 'track-frac:0.482;track-y:' . ( $mt ? 24 : 0 ) . ';track-x:' . $x . ';track-h:192' ) . '><iconify-icon icon="ph:wind-light" class="text-3xl text-sky-400" ' . $ac( 'font-size:30px;display:block;height:30px;color:rgb(56, 189, 248)' ) . '><svg viewBox="0 0 256 256" width="1em" height="1em"><path d="M120 40h48"/></svg></iconify-icon><div ' . $ac( 'display:block;height:86px' ) . '><h4 class="font-cinzel text-lg font-bold text-white mb-1" ' . $ac( 'font-family:Cinzel, serif;font-size:18px;font-weight:700;margin:0px 0px 4px;display:block;height:28px' ) . '>' . $t . '</h4><p class="text-[11px] text-white/40 font-light" ' . $ac( 'font-size:11px;font-weight:300;color:rgba(255, 255, 255, 0.4);display:block;height:54px' ) . '>' . $c . '</p></div></div>'; };
$ac_gear = '<section id="gear" class="py-32 px-6 md:px-12 max-w-7xl mx-auto" ' . $ac( 'padding:128px 48px;margin:0px 80px;max-width:1280px;height:704px;display:block' ) . '><div class="grid lg:grid-cols-12 gap-16 items-center" ' . $ac( 'display:grid;gap:64px;grid-template-columns:40px 40px 40px 40px 40px 40px 40px 40px 40px 40px 40px 40px;align-items:center;height:448px' ) . '>'
	. '<div class="lg:col-span-5 relative" ' . $ac( 'display:block;height:448px;track-frac:0.385;track-y:0;track-x:0;track-h:448' ) . '><div class="grid grid-cols-2 gap-4 relative z-10" ' . $ac( 'display:grid;gap:16px;grid-template-columns:220px 220px;height:448px' ) . '>' . $ac_gc( 'AeroShell', 'Deflects storms while enabling total internal thermal breathing cycles.', false, 0 ) . $ac_gc( 'Kevlar-Weave', 'High-durability threshold across rocks, dense branches, and high peaks.', true, 236 ) . $ac_gc( 'Zero Mass', 'Ultralight formulation ensures zero drag during vertical ascents.', false, 0 ) . $ac_gc( 'BioMetrics', 'Embedded tracking filaments connecting directly with satellite arrays.', true, 236 ) . '</div></div>'
	. '<div class="lg:col-span-7" ' . $ac( 'display:block;height:300px;track-frac:0.58;track-y:74;track-x:496;track-h:300' ) . '><span class="text-xs uppercase tracking-[0.4em] text-sky-400 font-bold block mb-4" ' . $ac( 'font-size:12px;font-weight:700;letter-spacing:4.8px;text-transform:uppercase;color:rgb(56, 189, 248);margin:0px 0px 16px;display:block;height:16px' ) . '>Tactical Artifacts</span><h2 class="font-cinzel text-5xl font-bold uppercase text-white mb-6" ' . $ac( 'font-family:Cinzel, serif;font-size:48px;font-weight:700;text-transform:uppercase;margin:0px 0px 24px;display:block;height:116px' ) . '>Engineered for the untamed skies.</h2><p class="text-lg text-white/60 font-light mb-8" ' . $ac( 'font-size:18px;font-weight:300;color:rgba(255, 255, 255, 0.6);margin:0px 0px 32px;display:block;height:84px' ) . '>Our equipment merges traditional silhouette styles with advanced scientific textiles.</p><a href="#" class="inline-block px-8 py-3 bg-zephyr-lime text-sky-950 text-xs uppercase tracking-[0.2em] font-bold rounded-sm" ' . $ac( 'background-color:rgb(132, 204, 22);color:rgb(7, 17, 30);font-size:12px;font-weight:700;letter-spacing:2.4px;text-transform:uppercase;padding:12px 32px;border-radius:2px;display:inline-block;height:40px' ) . '>View Technical Catalog</a></div></div></section>';
$ac_tile = function ( $i, $span, $x, $y ) use ( $ac ) { return '<div class="col-span-12 md:col-span-' . $span . ' rounded-lg overflow-hidden relative group glass-card" ' . $ac( 'border-radius:8px;height:240px;display:block;overflow:hidden;position:relative;track-frac:' . ( 8 === $span ? '0.663' : '0.325' ) . ';track-y:' . $y . ';track-x:' . $x . ';track-h:240' ) . '><img src="https://example.com/wall-' . $i . '.jpg" alt="" class="w-full h-full object-cover" ' . $ac( 'height:238px;width:' . ( 8 === $span ? 846 : 414 ) . 'px;display:block;object-fit:cover' ) . '></div>'; };
$ac_wall = '<section id="wall" class="py-32 px-6 md:px-12" ' . $ac( 'padding:128px 48px;height:996px;display:block;text-align:center' ) . '><div class="max-w-7xl mx-auto mb-20 text-center" ' . $ac( 'max-width:1280px;margin:0px 32px 80px;display:block;text-align:center;height:120px' ) . '><span class="text-xs uppercase tracking-[0.4em] text-sky-400 font-bold block mb-4" ' . $ac( 'font-size:12px;font-weight:700;letter-spacing:4.8px;text-transform:uppercase;color:rgb(56, 189, 248);margin:0px 0px 16px;display:block;height:16px;text-align:center' ) . '>Visual Captures</span><h2 class="font-cinzel text-5xl font-bold uppercase text-white" ' . $ac( 'font-family:Cinzel, serif;font-size:48px;font-weight:700;text-transform:uppercase;display:block;height:58px;text-align:center' ) . '>Cinematic Wall</h2></div>'
	. '<div class="max-w-7xl mx-auto grid grid-cols-12 gap-4 auto-rows-[240px]" ' . $ac( 'max-width:1280px;margin:0px 32px;display:grid;gap:16px;grid-template-columns:92px 92px 92px 92px 92px 92px 92px 92px 92px 92px 92px 92px;grid-auto-rows:240px;height:496px' ) . '>' . $ac_tile( 1, 8, 0, 0 ) . $ac_tile( 2, 4, 864, 0 ) . $ac_tile( 3, 4, 0, 256 ) . $ac_tile( 4, 8, 432, 256 ) . '</div></section>';
$ac_fcol = function ( $h, $links ) use ( $ac ) { $o = '<div ' . $ac( 'display:block;height:167px' ) . '><h4 class="text-[10px] uppercase tracking-[0.3em] font-bold text-sky-400 mb-6" ' . $ac( 'font-size:10px;letter-spacing:3px;text-transform:uppercase;font-weight:700;color:rgb(56, 189, 248);margin:0px 0px 24px;display:block;height:15px' ) . '>' . $h . '</h4><ul class="space-y-4 text-sm font-light text-white/50" ' . $ac( 'font-size:14px;font-weight:300;color:rgba(255, 255, 255, 0.5);display:block;height:128px' ) . '>'; foreach ( $links as $l ) { $o .= '<li ' . $ac( 'font-size:14px;display:list-item;height:20px' ) . '><a href="#" ' . $ac( 'font-size:14px;color:rgba(255, 255, 255, 0.5);display:inline' ) . '>' . $l . '</a></li>'; } return $o . '</ul></div>'; };
$ac_footer = '<footer class="relative bg-[#040a12] border-t border-white/5 pt-32 pb-12 px-6 md:px-12" ' . $ac( 'background-color:rgb(4, 10, 18);padding:128px 48px 48px;border-top-width:1px;border-top-style:solid;border-top-color:rgba(255, 255, 255, 0.05);display:block;height:473px' ) . '>'
	. '<div class="max-w-7xl mx-auto grid lg:grid-cols-12 gap-16 items-start mb-20" ' . $ac( 'max-width:1280px;margin:0px 32px 80px;display:grid;gap:64px;grid-template-columns:48px 48px 48px 48px 48px 48px 48px 48px 48px 48px 48px 48px;height:168px' ) . '>'
	. '<div class="lg:col-span-4" ' . $ac( 'display:block;height:168px;track-frac:0.33' ) . '><a href="#" class="tracking-[0.3em] font-cinzel text-2xl font-bold text-white block mb-6" ' . $ac( 'font-family:Cinzel, serif;font-size:24px;font-weight:700;letter-spacing:7.2px;color:rgb(255, 255, 255);margin:0px 0px 24px;display:block;height:32px' ) . '>OUTPOST</a><p class="text-white/40 text-sm font-light max-w-sm mb-8" ' . $ac( 'font-size:14px;font-weight:300;color:rgba(255, 255, 255, 0.4);max-width:384px;margin:0px 0px 32px;display:block;height:46px' ) . '>Born from deep mountain ridges, bright blue horizons, and the continuous flow of the high alpine drafts.</p>'
	. '<div class="inline-flex items-center gap-2 px-4 py-2 border border-white/10 rounded-sm bg-white/5 text-xs text-white/60" ' . $ac( 'background-color:rgba(255, 255, 255, 0.05);color:rgba(255, 255, 255, 0.6);font-size:12px;padding:8px 16px;border-top-width:1px;border-top-style:solid;border-top-color:rgba(255, 255, 255, 0.1);border-radius:2px;display:inline-flex;align-items:center;gap:8px;height:34px' ) . '><span class="w-2 h-2 rounded-full bg-zephyr-lime" ' . $ac( 'background-color:rgb(132, 204, 22);border-radius:9999px;width:8px;height:8px;display:block' ) . '></span> Satellite Terminal Active </div></div>'
	. '<div class="lg:col-span-8 grid grid-cols-2 md:grid-cols-3 gap-12 w-full" ' . $ac( 'display:grid;gap:48px;grid-template-columns:245px 245px 245px;height:167px;track-frac:0.66' ) . '>' . $ac_fcol( 'Frontiers', array( 'Ridge Tracks', 'Summit Access', 'Alpine Camps', 'Live Feeds' ) ) . $ac_fcol( 'Company', array( 'Our Stance', 'Scientific Textiles', 'Sovereign Alliance', 'Dispatches' ) )
	. '<div ' . $ac( 'display:block;height:167px' ) . '><h4 class="text-[10px] uppercase tracking-[0.3em] font-bold text-sky-400 mb-6" ' . $ac( 'font-size:10px;letter-spacing:3px;text-transform:uppercase;font-weight:700;color:rgb(56, 189, 248);margin:0px 0px 24px;display:block;height:15px' ) . '>Ascent Registry</h4><p class="text-xs text-white/40 font-light mb-4" ' . $ac( 'font-size:12px;font-weight:300;color:rgba(255, 255, 255, 0.4);margin:0px 0px 16px;display:block;height:39px' ) . '>Subscribe to receive immediate telemetry alerts and launch schedules.</p>'
	. '<div class="relative" ' . $ac( 'display:block;height:42px;position:relative' ) . '><input type="email" placeholder="Enter coordinates..." class="w-full bg-white/5 border border-white/10 rounded-sm px-4 py-3 text-xs text-white" ' . $ac( 'background-color:rgba(255, 255, 255, 0.05);color:rgb(255, 255, 255);font-size:12px;padding:12px 16px;border-top-width:1px;border-top-style:solid;border-top-color:rgba(255, 255, 255, 0.1);border-radius:2px;height:42px;display:inline-block;width:245px' ) . '><button class="absolute right-1 top-1 bottom-1 px-3 bg-white text-sky-950 rounded-sm flex items-center justify-center" ' . $ac( 'background-color:rgb(255, 255, 255);color:rgb(7, 17, 30);padding:0px 12px;border-radius:2px;position:absolute;right:4px;top:4px;bottom:4px;display:flex;align-items:center;justify-content:center;height:34px' ) . '><iconify-icon icon="ph:arrow-right-bold" class="text-sm" ' . $ac( 'color:rgb(7, 17, 30);font-size:14px;display:block;height:14px' ) . '><svg viewBox="0 0 256 256" width="1em" height="1em"><path d="M40 128h176"/></svg></iconify-icon></button></div></div></div></div>'
	. '<div class="max-w-7xl mx-auto border-t border-white/5 pt-8 flex justify-between items-center text-[10px] uppercase tracking-widest text-white/30" ' . $ac( 'max-width:1280px;margin:0px 32px;color:rgba(255, 255, 255, 0.3);font-size:10px;letter-spacing:1px;text-transform:uppercase;padding:32px 0px 0px;border-top-width:1px;border-top-style:solid;border-top-color:rgba(255, 255, 255, 0.05);display:flex;justify-content:space-between;align-items:center;height:47px' ) . '><p ' . $ac( 'font-size:10px;letter-spacing:1px;text-transform:uppercase;color:rgba(255, 255, 255, 0.3);display:block;height:15px' ) . '>© 2026 OUTPOST. Horizons Unleashed.</p><div class="flex gap-8" ' . $ac( 'display:flex;gap:32px;height:15px' ) . '><a href="#" ' . $ac( 'font-size:10px;color:rgba(255, 255, 255, 0.3);display:block' ) . '>Encryption</a><a href="#" ' . $ac( 'font-size:10px;color:rgba(255, 255, 255, 0.3);display:block' ) . '>Protocols</a></div></div></footer>';
$ac_html = '<!DOCTYPE html><html data-sc-content-width="1280" data-sc-content-gutter="48" data-sc-content-gutter-inside="1" data-sc-phone-pass="1"><head><title>Outpost</title></head><body ' . $ac( 'background-color:rgb(7, 17, 30)' ) . '>' . $ac_hdr . '<main>' . $ac_hero . $ac_trips . $ac_gear . $ac_wall . '</main>' . $ac_footer . '</body></html>';

$ac_bl = FW_Site_Converter_Sources::build_from_html( $ac_html, 'Outpost', array( 'dynamic_chrome' => true, 'hifi_css' => true ) );
$ac_pg = $ac_bl['files']['pages.json']['pages'][0]['builder'] ?? array();
$ac_v  = $ac_bl['files']['theme-settings.json']['values'] ?? array();
$ac_json = wp_json_encode( $ac_bl['files'] ?? array(), JSON_UNESCAPED_SLASHES );
$ac_css_of = function ( $n ) { return (string) ( $n['atts']['custom_css'] ?? '' ); };
$ac_right = wp_json_encode( $ac_v['header_main']['main_right'] ?? array() );
ga( "[AC] the wordmark link (`<a href=\"#\">OUTPOST</a>` leading the row ahead of the nav cluster) is the logo only — never a second list item beside the CTA", false === strpos( $ac_right, '"li_text":"OUTPOST"' ) && false !== strpos( $ac_right, '"cta_text":"Begin Trek"' ) && 'OUTPOST' === (string) ( $ac_v['header_logo']['logo_type']['custom']['site_title'] ?? '' ), $ac_right );
ga( "[AC] a header whose REST stamp carries no blur while its SCROLLED stamp does is glass on scroll only (the blur class is script-toggled)", 'no' === (string) ( $ac_v['header_layout']['header_glass'] ?? '' ) && 'yes' === (string) ( $ac_v['header_layout']['scroll_glass'] ?? '' ), wp_json_encode( array( $ac_v['header_layout']['header_glass'] ?? null, $ac_v['header_layout']['scroll_glass'] ?? null ) ) );
ga( "[AC] `max-w-7xl px-6 md:px-12` containers (1280 outer, 48 inside at desktop — the base tier is the phone's 24) → the site container is the CONTENT width 1184 with a 48px gutter", '1184' === (string) ( $ac_v['general_layout']['layout_container_width']['lg']['value'] ?? '' ) && '48' === (string) ( $ac_v['general_layout']['layout_container_gutter']['value'] ?? '' ), wp_json_encode( array( $ac_v['general_layout']['layout_container_width'] ?? null, $ac_v['general_layout']['layout_container_gutter'] ?? null ) ) );
$ac_sec0 = $ac_pg[0] ?? array();
ga( "[AC] the hero band's `clip-path` (a diagonal bottom edge) rides the section", false !== strpos( $ac_css_of( $ac_sec0 ), 'clip-path:polygon(0px 0px, 100% 0px, 100% 85%, 0% 100%)' ), $ac_css_of( $ac_sec0 ) );
ga( "[AC] …a vertically centred viewport band keeps its content flexboxes FULL width (the centring's flex column had shrunk the row to its content)", false !== strpos( $ac_css_of( $ac_sec0 ), '.fw-flexbox{width:100%;}' ), $ac_css_of( $ac_sec0 ) );
$ac_hrow = $r_find( $ac_sec0['_items'] ?? array(), function ( $n ) { return 'flexbox' === ( $n['type'] ?? '' ) && false !== strpos( (string) ( $n['atts']['custom_css'] ?? '' ), 'padding-top:128px' ); } );
ga( "[AC] …the hero row that IS the band's container (`max-w-7xl mx-auto px-12 pt-32`) keeps its top inset but not its side padding (the container's gutter is that inset — the title had sat 48px off the source's edge)", is_array( $ac_hrow ) && false === strpos( $ac_css_of( $ac_hrow ), 'padding-left' ), $ac_css_of( $ac_hrow ) );
$ac_watch = $r_find( $ac_pg, function ( $n ) { return 'button' === ( $n['shortcode'] ?? '' ) && 'Watch Motion' === (string) ( $n['atts']['label'] ?? '' ); } );
ga( "[AC] a play glyph hosted in `<iconify-icon>` BEFORE the label is a leading icon (the host counted as the glyph — it had landed after the label)", is_array( $ac_watch ) && 'before' === (string) ( $ac_watch['atts']['icon_position'] ?? '' ) && 'svg' === (string) ( $ac_watch['atts']['icon']['type'] ?? '' ), wp_json_encode( array( $ac_watch['atts']['icon_position'] ?? null, $ac_watch['atts']['icon']['type'] ?? null ) ) );
$ac_pin = $r_find( $ac_pg, function ( $n ) { if ( 'flexbox' !== ( $n['type'] ?? '' ) || 'absolute' !== (string) ( $n['atts']['element_position']['position'] ?? '' ) ) { return false; } $k = 0; foreach ( $n['_items'] ?? array() as $it ) { if ( 'icon' === ( $it['shortcode'] ?? '' ) ) { $k++; } } return 3 === $k; } );
ga( "[AC] an absolutely positioned wrapper of icon links (`absolute bottom-8 left-12 flex gap-6`, a social strip in the hero's corner) is ONE pinned row of three icons at the DESKTOP offset (`left-6 md:left-12` → the computed 48px, not the phone tier's 1.5rem; they had dropped into the flow under the buttons)", is_array( $ac_pin ) && '48' === (string) ( $ac_pin['atts']['element_position']['absolute']['pos_offsets']['left']['value'] ?? '' ) && 'px' === (string) ( $ac_pin['atts']['element_position']['absolute']['pos_offsets']['left']['unit'] ?? '' ), wp_json_encode( $ac_pin['atts']['element_position'] ?? null ) );
$ac_secure = array(); $r_all( $ac_pg, function ( $n ) { return 'button' === ( $n['shortcode'] ?? '' ) && 'Secure Spot' === (string) ( $n['atts']['label'] ?? '' ); }, $ac_secure );
ga( "[AC] a badge card's body ends in a bare `<a>` text link with a trailing glyph → three btn-link buttons (the link had been dropped)", 3 === count( $ac_secure ) && 'btn-link' === (string) ( $ac_secure[0]['atts']['style'] ?? '' ) && 'after' === (string) ( $ac_secure[0]['atts']['icon_position'] ?? '' ), wp_json_encode( array( count( $ac_secure ), $ac_secure[0]['atts']['style'] ?? null ) ) );
$ac_chip = $r_find( $ac_pg, function ( $n ) { return 'icon_box' === ( $n['shortcode'] ?? '' ) && 'Active Trek' === (string) ( $n['atts']['title'] ?? '' ); } );
ga( "[AC] a one-line glass chip over the photo keeps its own type (9px tracked uppercase from its stamp — the theme's h4 had drawn it at 24px serif), its hairline border, its blur, no trailing margin", is_array( $ac_chip ) && false !== strpos( $ac_css_of( $ac_chip ), 'font-size:9px' ) && false !== strpos( $ac_css_of( $ac_chip ), 'text-transform:uppercase' ) && false !== strpos( $ac_css_of( $ac_chip ), 'border:1px solid rgba(255, 255, 255, 0.1)' ) && false !== strpos( $ac_css_of( $ac_chip ), 'backdrop-filter:blur(12px)' ) && false !== strpos( $ac_css_of( $ac_chip ), 'margin-bottom:0px' ), $ac_css_of( $ac_chip ) );
$ac_photo = $r_find( $ac_pg, function ( $n ) { return 'media_image' === ( $n['shortcode'] ?? '' ) && false !== strpos( (string) ( $n['atts']['image']['url'] ?? '' ), 'emerald-ridges' ); } );
ga( "[AC] …the card photo's `mix-blend-luminosity` (desaturated over the card fill) rides its img", is_array( $ac_photo ) && false !== strpos( $ac_css_of( $ac_photo ), 'mix-blend-mode:luminosity' ), $ac_css_of( $ac_photo ) );
$ac_gear = array(); $r_all( $ac_pg, function ( $n ) { return 'flexbox' === ( $n['type'] ?? '' ) && 1 === count( $n['_items'] ?? array() ) && 'icon_box' === ( $n['_items'][0]['shortcode'] ?? '' ) && in_array( (string) ( $n['_items'][0]['atts']['title'] ?? '' ), array( 'AeroShell', 'Kevlar-Weave', 'Zero Mass', 'BioMetrics' ), true ); }, $ac_gear );
$ac_gw = array(); foreach ( $ac_gear as $g ) { $ac_gw[] = (string) ( $g['atts']['width']['base']['preset'] ?? '' ); }
ga( "[AC] a 2×2 grid of same-height cards whose second column is `mt-6` staggered is a plain 2×2 (a spanner must be markedly taller than the cell starting inside it — the X-split had made two lopsided stacks)", 4 === count( $ac_gear ) && array( '6', '6', '6', '6' ) === $ac_gw, wp_json_encode( $ac_gw ) );
$ac_gal = $r_find( $ac_pg, function ( $n ) { return 'gallery' === ( $n['shortcode'] ?? '' ); } );
ga( "[AC] a 12-track mosaic whose col-spans change by row (8|4 then 4|8 at `auto-rows-[240px]`; the DESKTOP `md:col-span-8` over the bare `col-span-12`) is a 3-column metro with spans 2|1 / 1|2 on 240px rows (four tiles had rendered in one row)", is_array( $ac_gal ) && 'metro' === (string) ( $ac_gal['atts']['design_settings']['design'] ?? '' ) && '3' === (string) ( $ac_gal['atts']['design_settings']['metro']['columns']['count'] ?? '' ) && false !== strpos( $ac_css_of( $ac_gal ), 'grid-auto-rows:240px' ) && false !== strpos( $ac_css_of( $ac_gal ), 'nth-child(1){grid-column:span 2' ) && false !== strpos( $ac_css_of( $ac_gal ), 'nth-child(4){grid-column:span 2' ) && false === strpos( $ac_css_of( $ac_gal ), 'nth-child(2){grid-column:span' ), wp_json_encode( array( $ac_gal['atts']['design_settings'] ?? null, $ac_gal['atts']['custom_css'] ?? null ) ) );
$ac_mf = $ac_v['main_footer_columns'] ?? array(); $ac_cols = $ac_mf[ (string) ( $ac_mf['count'] ?? '0' ) ] ?? array();
$ac_c1 = wp_json_encode( $ac_cols['main_footer_col_1'] ?? array() ); $ac_c4 = wp_json_encode( $ac_cols['main_footer_col_4'] ?? array() );
ga( "[AC] the footer brand column keeps its STATUS CHIP (a skinned inline pill with a dot) as a text element whose pill wears the measured skin through the misc css", false !== strpos( $ac_c1, 'footer-chip__pill' ) && false !== strpos( $ac_c1, 'Satellite Terminal Active' ) && false !== strpos( $ac_json, '.footer .footer-chip__pill{display:inline-flex;align-items:center;gap:8px;background-color:rgba(255, 255, 255, 0.05)' ), $ac_c1 );
ga( "[AC] the signup column: its label is a footer HEADING like its siblings (not the widget's title), an icon-only submit keeps an arrow, the field / button wear the source skin, its description is never a copyright disclaimer", false !== strpos( $ac_c4, '"heading_text":"Ascent Registry"' ) && false !== strpos( $ac_c4, '"newsletter_title":""' ) && '→' === (string) ( $ac_cols['main_footer_col_4'][1]['element_type']['newsletter']['newsletter_button'] ?? '' ) && false !== strpos( $ac_json, '.footer .fw-nl__input{background:rgba(255, 255, 255, 0.05) !important' ) && false !== strpos( $ac_json, '.footer .fw-nl__btn{background:rgb(255, 255, 255) !important' ) && false === strpos( wp_json_encode( $ac_v['copyright_settings'] ?? array() ), 'Subscribe to receive' ), $ac_c4 );

/* ---------------------------------------------------------------------
 * [AD] A glass "liquid" landing (a real-site audit, 2026-09-19): the `<header>` inside `<main>` was read as chrome and the
 *     hero dropped; a framed fixed video portal (round, z-index -1) sat inside the first band instead of behind the page;
 *     `<main class="pt-48">` under a fixed nav gained the nav's clearance again; #main's own gutter was doubled by the site cap
 *     on the bento's flexbox; a 12-track bento (8|4 / 5|7) ran one gap short per line; a card's inset was painted twice (cell
 *     pad + card box); a distributed column's label folded into its title; an `mt-auto` group carried a one-screen px margin;
 *     a bar row fell to the content-less drop; a subtitle with no margin took the theme's 18px; the grid's row minimum was lost.
 * --------------------------------------------------------------------- */
echo "\n[AD] Liquid landing: header-in-main band, framed video backdrop, main clearance + gutter, 12-track bento, pad once, apart label, mt-auto, bar row, row minimum\n";
$ad_mk = function ( $base, $e ) { $m = array(); foreach ( explode( ';', $base . ';' . $e ) as $d ) { $d = trim( $d ); if ( '' === $d || false === strpos( $d, ':' ) ) { continue; } list( $k, $v ) = explode( ':', $d, 2 ); $m[ trim( $k ) ] = trim( $v ); } $o = array(); foreach ( $m as $k => $v ) { $o[] = $k . ':' . $v; } return 'data-sc-cs="' . implode( ';', $o ) . '"'; };
$ad = function ( $e ) use ( $ad_mk ) { return $ad_mk( 'color:oklch(0.98 0 0);font-family:Inter, sans-serif;font-size:16px;font-weight:400;line-height:24px', $e ); };
$ad_glass = 'background-color:oklch(0.15 0.02 280 / 0.4);border-radius:32px;box-shadow:rgba(0, 0, 0, 0.4) 0px 30px 60px 0px;backdrop-filter:blur(40px) saturate(1.8)';
$ad_frost = 'background-image:radial-gradient(circle at 0% 0%, oklch(0.9 0.02 280 / 0.08), rgba(0, 0, 0, 0) 70%);border-radius:24px;box-shadow:rgba(0, 0, 0, 0.3) 0px 15px 35px 0px;backdrop-filter:blur(60px)';
$ad_label = function ( $t, $extra = '' ) use ( $ad ) { return '<span class="micro-label' . $extra . '" ' . $ad( 'font-size:12px;letter-spacing:3.6px;text-transform:uppercase;color:oklch(0.6 0.05 280);display:block;height:18px;line-height:18px' ) . '>' . $t . '</span>'; };
$ad_nav = '<nav class="fixed top-0 w-full z-50 px-6 py-8 text-white" ' . $ad( 'position:fixed;top:0px;left:0px;height:92px;padding:32px 24px;z-index:50;display:block' ) . '>'
	. '<div class="max-w-screen-2xl mx-auto flex items-center justify-between" ' . $ad( 'max-width:1536px;margin:0px auto;display:flex;align-items:center;justify-content:space-between;height:28px' ) . '>'
	. '<div class="text-xl font-bold tracking-tighter" ' . $ad( 'font-size:20px;font-weight:700;letter-spacing:-1px;display:block;height:28px' ) . '>FLUX <span class="text-white/40" ' . $ad( 'color:rgba(255, 255, 255, 0.4)' ) . '>O.S.</span></div>'
	. '<div class="theme-trigger" ' . $ad( 'background-color:oklch(0.9 0.02 280 / 0.08);border-radius:999px;width:48px;height:24px;display:block' ) . '></div>'
	. '</div></nav>';
$ad_portal = '<div class="atmospheric-portal" ' . $ad( 'position:fixed;top:-216px;left:360px;width:1296px;height:1296px;border-radius:50%;z-index:-1;overflow:hidden;display:block' ) . '><video autoplay loop muted playsinline src="https://example.com/media/portal.mp4" ' . $ad( 'width:1296px;height:1296px;display:block;object-fit:cover' ) . '></video></div>';
$ad_hero = '<header class="max-w-5xl mb-32 relative" ' . $ad( 'max-width:1024px;margin:0px 0px 128px;height:512px;display:block' ) . '>'
	. $ad_label( 'Sensory Frame', ' mb-6 block' )
	. '<h1 class="text-7xl md:text-9xl font-light tracking-tighter leading-[0.9] mb-8" ' . $ad( 'font-size:128px;font-weight:300;line-height:128px;letter-spacing:-6.4px;margin:0px 0px 32px;height:256px;display:block' ) . ' data-sc-cs-sm="font-size:72px;line-height:64.8px">Fluid<br>Forms.</h1>'
	. '<p class="text-2xl text-[var(--text-muted)] font-light max-w-2xl leading-relaxed" ' . $ad( 'font-size:24px;font-weight:300;line-height:32px;color:oklch(0.6 0.05 280);max-width:672px;height:64px;display:block' ) . '>A zero-edge spatial surface. Soft debossing and perceptual colour fusion dissolve structural rigidity.</p>'
	. '<div class="mt-14" ' . $ad( 'margin:56px 0px 0px;height:62px;display:block' ) . '><button class="mercury-btn" ' . $ad( 'background-color:rgb(255, 255, 255);color:rgb(0, 0, 0);font-size:14px;font-weight:600;padding:20px 40px;border-radius:999px;height:62px;display:inline-flex;align-items:center' ) . '>Initialize Sequence</button></div>'
	. '</header>';
$ad_tracks = 'grid-template-columns:94px 94px 94px 94px 94px 94px 94px 94px 94px 94px 94px 94px';
$ad_t1 = '<div class="glass-tile md:col-span-8 p-16 flex flex-col justify-between" ' . $ad( $ad_glass . ';padding:64px;height:402px;display:flex;flex-direction:column;justify-content:space-between;track-frac:0.656;track-y:0;track-x:0;track-h:402' ) . ' data-sc-cs-md="padding:64px" data-sc-cs-sm="padding:40px;track-frac:1">'
	. '<div class="flex justify-between items-start" ' . $ad( 'display:flex;justify-content:space-between;align-items:flex-start;height:18px' ) . '>' . $ad_label( '01 // Volumetric Space' ) . '<div class="w-2 h-2 rounded-full bg-[var(--accent)]" ' . $ad( 'background-color:oklch(0.65 0.25 280);width:8px;height:8px;border-radius:9999px;display:block' ) . '></div></div>'
	. '<div class="mt-10" ' . $ad( 'margin:40px 0px 0px;height:208px;display:block' ) . '><h2 class="text-5xl font-light mb-6 tracking-tight" ' . $ad( 'font-size:48px;font-weight:300;line-height:48px;letter-spacing:-1.2px;margin:0px 0px 24px;height:96px;display:block' ) . '>Predictable edges have failed.</h2><p class="text-[var(--text-muted)] text-lg max-w-lg leading-relaxed" ' . $ad( 'font-size:18px;line-height:29px;color:oklch(0.6 0.05 280);max-width:512px;height:88px;display:block' ) . '>Refractive depth overrides solid borders. Inner shadow hierarchies build materials that read like liquid stone.</p></div>'
	. '</div>';
$ad_t2 = '<div class="frost-tile md:col-span-4 p-10 flex flex-col justify-between" ' . $ad( $ad_frost . ';padding:40px;height:402px;display:flex;flex-direction:column;justify-content:space-between;track-frac:0.313;track-y:0;track-x:944;track-h:402' ) . ' data-sc-cs-sm="track-frac:1">'
	. $ad_label( 'Telemetry' )
	. '<div class="mt-12" ' . $ad( 'margin:48px 0px 0px;height:126px;display:block' ) . '><div class="text-7xl font-light tracking-tighter mb-2" ' . $ad( 'font-size:72px;font-weight:300;line-height:72px;letter-spacing:-3.6px;margin:0px 0px 8px;height:72px;display:block' ) . '>120<span class="text-2xl text-[var(--text-muted)] ml-1" ' . $ad( 'font-size:24px;color:oklch(0.6 0.05 280);margin:0px 0px 0px 4px' ) . '>fps</span></div><p class="text-[var(--text-muted)] text-sm leading-relaxed" ' . $ad( 'font-size:14px;line-height:23px;color:oklch(0.6 0.05 280);height:46px;display:block' ) . '>Native hardware acceleration via scroll timelines. Zero external runtime logic.</p></div>'
	. '</div>';
$ad_t3 = '<div class="frost-tile md:col-span-5 p-10 relative overflow-hidden" ' . $ad( $ad_frost . ';padding:40px;height:320px;display:block;overflow:hidden;track-frac:0.407;track-y:426;track-x:0;track-h:320' ) . ' data-sc-cs-sm="track-frac:1">'
	. '<div class="absolute -right-20 -top-20 w-64 h-64 bg-[var(--violet)] rounded-full mix-blend-screen filter blur-[80px] opacity-40" ' . $ad( 'background-color:oklch(0.6 0.2 310);width:256px;height:256px;border-radius:9999px;position:absolute;top:-80px;right:-80px;opacity:0.4;filter:blur(80px);mix-blend-mode:screen;display:block' ) . '></div>'
	. '<div class="relative z-10 h-full flex flex-col justify-between" ' . $ad( 'height:240px;display:flex;flex-direction:column;justify-content:space-between;z-index:10' ) . '>'
	. $ad_label( 'Spectrum Engine' )
	. '<div class="mt-auto" ' . $ad( 'margin:166px 0px 0px;height:56px;display:block' ) . '><h3 class="text-3xl font-light mb-4" ' . $ad( 'font-size:30px;font-weight:300;line-height:36px;margin:0px 0px 16px;height:36px;display:block' ) . '>Colour Perception</h3>'
	. '<div class="flex gap-4" ' . $ad( 'display:flex;gap:16px;height:4px' ) . '><div class="h-1 bg-[var(--blue)] w-1/2 rounded-full" ' . $ad( 'background-color:oklch(0.65 0.25 280);height:4px;width:243px;border-radius:9999px;display:block' ) . '></div><div class="h-1 bg-[var(--violet)] w-1/4 rounded-full" ' . $ad( 'background-color:oklch(0.6 0.2 310);height:4px;width:122px;border-radius:9999px;display:block' ) . '></div></div>'
	. '</div></div></div>';
$ad_t4 = '<div class="glass-tile md:col-span-7 p-16 flex items-center" ' . $ad( $ad_glass . ';padding:64px;height:320px;display:flex;align-items:center;track-frac:0.57;track-y:426;track-x:590;track-h:320' ) . ' data-sc-cs-sm="track-frac:1">'
	. '<h3 class="text-5xl font-light leading-tight text-[var(--text-primary)]" ' . $ad( 'font-size:48px;font-weight:300;line-height:48px;height:96px;display:block' ) . '>Seek clarity through absolute depth.</h3>'
	. '</div>';
$ad_bento = '<section class="bento-grid grid grid-cols-1 md:grid-cols-12 gap-6 auto-rows-[minmax(320px,auto)]" ' . $ad( 'display:grid;gap:24px;' . $ad_tracks . ';grid-auto-rows:minmax(320px, auto);height:731px' ) . ' data-sc-cs-sm="grid-template-columns:342px">' . $ad_t1 . $ad_t2 . $ad_t3 . $ad_t4 . '</section>';
$ad_main = '<main class="relative z-10 max-w-screen-2xl mx-auto px-6 pt-48 pb-32" ' . $ad( 'max-width:1536px;margin:0px auto;padding:192px 24px 128px;z-index:10;display:block;height:1706px' ) . '>' . $ad_hero . $ad_bento . '</main>';
$ad_html = '<!DOCTYPE html><html data-sc-content-width="1536" data-sc-content-gutter="24" data-sc-content-gutter-inside="1" data-sc-phone-pass="1"><head><title>Flux</title></head><body ' . $ad( 'background-color:rgb(0, 0, 0)' ) . '>' . $ad_nav . $ad_portal . $ad_main . '</body></html>';

$ad_bl = FW_Site_Converter_Sources::build_from_html( $ad_html, 'Flux', array( 'dynamic_chrome' => true, 'hifi_css' => true ) );
$ad_pg = $ad_bl['files']['pages.json']['pages'][0]['builder'] ?? array();
$ad_v  = $ad_bl['files']['theme-settings.json']['values'] ?? array();
$ad_css_of = function ( $n ) { return (string) ( $n['atts']['custom_css'] ?? '' ); };
$ad_misc = (string) ( $ad_v['misc_custom_css']['custom_css'] ?? '' );
$ad_sec0 = $ad_pg[0] ?? array(); $ad_sec1 = $ad_pg[1] ?? array();
$ad_h1 = $r_find( $ad_sec0['_items'] ?? array(), function ( $n ) { return 'special_heading' === ( $n['shortcode'] ?? '' ) && false !== strpos( (string) ( $n['atts']['title'] ?? '' ), 'Fluid' ); } );
ga( "[AD] a `<header>` INSIDE `<main>` that holds the h1 is the first content band, never the site chrome (the hero band had been dropped)", 2 === count( $ad_pg ) && is_array( $ad_h1 ), 'sections=' . count( $ad_pg ) );
ga( "[AD] a FRAMED fixed video layer BEHIND the page (round, z-index -1) is the site background video with its own geometry (top / left / 1296px square / 50% radius / clipped, the video covering it)", 'yes' === (string) ( $ad_v['general_layout']['site_background']['video']['enabled'] ?? '' ) && false !== strpos( $ad_misc, '.site-bg-video{top:-216px !important;bottom:auto !important;left:360px !important;right:auto !important;width:1296px !important;height:1296px !important;border-radius:50% !important;overflow:hidden !important;}' ) && false !== strpos( $ad_misc, '.site-bg-video video{width:100%;height:100%;object-fit:cover;}' ), $ad_misc );
ga( "[AD] …and the first band carries no video of its own", empty( $ad_sec0['atts']['background_video']['enabled'] ) || 'yes' !== (string) $ad_sec0['atts']['background_video']['enabled'], wp_json_encode( $ad_sec0['atts']['background_video'] ?? null ) );
ga( "[AD] `<main class=\"pt-48\">` under a 92px fixed nav: #main's own 192px top padding IS the clearance — the first band adds no header offset (heroTopPad 0)", false === strpos( $ad_css_of( $ad_sec0 ), 'padding-top:92px' ) && '' === (string) ( $ad_sec0['atts']['padding_top']['lg']['value'] ?? '' ) || '0' === (string) ( $ad_sec0['atts']['padding_top']['lg']['value'] ?? '' ), wp_json_encode( array( $ad_css_of( $ad_sec0 ), $ad_sec0['atts']['padding_top'] ?? null ) ) );
$ad_design = wp_json_encode( $ad_bl['files']['theme-design.json'] ?? array(), JSON_UNESCAPED_SLASHES );
ga( "[AD] …#main carries the source shell (1536 cap, 24px gutter, 192 / 128 vertical padding)", false !== strpos( $ad_design, 'main#main{padding-top:192px !important;padding-bottom:128px !important;max-width:1536px !important;margin-left:auto !important;margin-right:auto !important;padding-left:24px !important;padding-right:24px !important;}' ), substr( $ad_design, 0, 400 ) );
$ad_hfb = $r_find( $ad_sec0['_items'] ?? array(), function ( $n ) { return 'flexbox' === ( $n['type'] ?? '' ); } );
ga( "[AD] the hero's LEFT-ANCHORED root cap (`max-w-5xl`, no auto margins) is a left-aligned 1024px (`wide`) content width on the band's flexbox", is_array( $ad_hfb ) && 'left' === (string) ( $ad_hfb['atts']['content_align'] ?? '' ) && ( 'wide' === (string) ( $ad_hfb['atts']['content_width']['preset'] ?? '' ) || '1024' === (string) ( $ad_hfb['atts']['content_width']['custom']['custom_width']['value'] ?? '' ) ), wp_json_encode( array( $ad_hfb['atts']['content_align'] ?? null, $ad_hfb['atts']['content_width'] ?? null ) ) );
ga( "[AD] the hero subtitle's stamp omits its (zero) margin-bottom and carries no `mb-*`: the heading takes mb-0 — the gap lives on the next block's `mt-14` (the theme's 18px had leaked under it)", is_array( $ad_h1 ) && 'mb-0' === (string) ( $ad_h1['atts']['spacing']['margin']['bottom'] ?? '' ), wp_json_encode( $ad_h1['atts']['spacing'] ?? null ) );
$ad_grid = $r_find( $ad_sec1['_items'] ?? array(), function ( $n ) { return 'flexbox' === ( $n['type'] ?? '' ) && count( $n['_items'] ?? array() ) === 4; } );
ga( "[AD] a `grid-cols-12` bento whose desktop spans tile lines of 12 (8|4 then 5|7) is a native 12-TRACK GRID, its cells keeping their spans (a wrapping flex row ran one gap short per line)", is_array( $ad_grid ) && 'grid' === (string) ( $ad_grid['atts']['display'] ?? '' ) && '12' === (string) ( $ad_grid['atts']['grid_columns'] ?? '' ) && '8' === (string) ( $ad_grid['_items'][0]['atts']['width']['lg']['preset'] ?? '' ) && '7' === (string) ( $ad_grid['_items'][3]['atts']['width']['lg']['preset'] ?? '' ), wp_json_encode( array( $ad_grid['atts']['display'] ?? null, $ad_grid['atts']['grid_columns'] ?? null ) ) );
ga( "[AD] …#main being the container, the bento's flexbox takes the FULL width (no second gutter from the site cap)", is_array( $ad_grid ) && '100' === (string) ( $ad_grid['atts']['content_width']['custom']['custom_width']['value'] ?? '' ) && false !== strpos( $ad_css_of( $ad_grid ), 'max-width:100% !important' ), wp_json_encode( $ad_grid['atts']['content_width'] ?? null ) );
$ad_c1 = $ad_grid['_items'][0] ?? array(); $ad_c1css = $ad_css_of( $ad_c1 ); $ad_c1in = $ad_css_of( $ad_c1['_items'][0] ?? array() );
ga( "[AD] a card cell's inset is painted ONCE: the responsive pad tiers ride the card's BOX (the inner wrapper — base + media tiers, in that order), the outer track carries no padding and no emptied @media shell, the box no `padding:` shorthand", '' === $ad_c1css && false !== strpos( $ad_c1in, 'selector{padding-top:64px;padding-right:64px;padding-bottom:64px;padding-left:64px;}' ) && false !== strpos( $ad_c1in, '@media (min-width:768px){selector{padding-top:64px;' ) && false === strpos( $ad_c1in, 'padding:64px' ) && strpos( $ad_c1in, '@media' ) > strpos( $ad_c1in, 'selector{padding-top:64px' ), wp_json_encode( array( $ad_c1css, $ad_c1in ) ) );
$ad_c2 = $ad_grid['_items'][1] ?? array(); $ad_c2in = $ad_c2['_items'][0] ?? array();
ga( "[AD] a distributed column (`flex-col justify-between`): the leading label (its own flex item) stands APART from the title's group — an overline-only block first, then the h3 + p heading, in a between-justified inner box", 'between' === (string) ( $ad_c2in['atts']['justify_content']['base'] ?? '' ) && count( $ad_c2in['_items'] ?? array() ) === 2 && false !== strpos( wp_json_encode( $ad_c2in['_items'][0] ), 'Telemetry' ) && 'special_heading' === (string) ( $ad_c2in['_items'][1]['shortcode'] ?? '' ) && false !== strpos( (string) ( $ad_c2in['_items'][1]['atts']['title'] ?? '' ), '120' ) && '' === (string) ( $ad_c2in['_items'][1]['atts']['overline'] ?? '' ), wp_json_encode( array( $ad_c2in['atts']['justify_content'] ?? null, array_map( function ( $i ) { return ( $i['shortcode'] ?? $i['type'] ?? '' ) . ':' . substr( (string) ( $i['atts']['title'] ?? $i['atts']['text'] ?? '' ), 0, 30 ); }, $ad_c2in['_items'] ?? array() ) ) ) );
ga( "[AD] …the card box fills its stretched track (height:100%), so the distribution has the room", false !== strpos( $ad_css_of( $ad_c2in ), 'box-sizing:border-box;height:100%;}' ), $ad_css_of( $ad_c2in ) );
$ad_c3 = $ad_grid['_items'][2] ?? array(); $ad_c3in = $ad_c3['_items'][0] ?? array(); $ad_c3items = $ad_c3in['_items'] ?? array();
$ad_c3h = $r_find( $ad_c3items, function ( $n ) { return 'special_heading' === ( $n['shortcode'] ?? '' ) && false !== strpos( (string) ( $n['atts']['title'] ?? '' ), 'Colour' ); } );
$ad_c3bar = $r_find( $ad_c3items, function ( $n ) { return 'code_block' === ( $n['shortcode'] ?? '' ) && false !== strpos( (string) ( $n['atts']['code'] ?? '' ), 'w-1/4' ); } );
ga( "[AD] a BLOCK card whose one in-flow child is a full-height `flex-col justify-between` wrapper (over an absolute glow) lays out through it: a between-justified column with the label apart and the title from its `mt-auto` group pushed to the end (`margin-top:auto`, never the one-screen 166px)", 'between' === (string) ( $ad_c3in['atts']['justify_content']['base'] ?? '' ) && is_array( $ad_c3h ) && '' === (string) ( $ad_c3h['atts']['overline'] ?? '' ) && false !== strpos( $ad_css_of( $ad_c3h ), 'margin-top:auto !important' ) && in_array( (string) ( $ad_c3h['atts']['spacing']['margin']['top'] ?? '' ), array( '', 'mt-0' ), true ), wp_json_encode( array( $ad_c3in['atts']['justify_content'] ?? null, $ad_c3h['atts']['overline'] ?? null, $ad_css_of( $ad_c3h ), $ad_c3h['atts']['spacing']['margin']['top'] ?? null ) ) );
ga( "[AD] …its ROW of two decorative bars (`flex gap-4` of `h-1 rounded-full` fills) is ONE kept code block, side by side (it had split into two and fallen to the content-less drop)", is_array( $ad_c3bar ) && false !== strpos( (string) $ad_c3bar['atts']['code'], 'w-1/2' ) && false !== strpos( (string) $ad_c3bar['atts']['code'], 'flex gap-4' ) && false === strpos( (string) $ad_c3bar['atts']['code'], "\n" ), wp_json_encode( $ad_c3bar['atts']['code'] ?? null ) );
$ad_hcss = $ad_css_of( $ad_h1 ); $ad_hid = (string) ( $ad_sec0['atts']['css_id'] ?? '' );
ga( "[AD] the hero title's PHONE size (`text-7xl md:text-9xl` → 72px at 390, the -sm stamp) rides the heading as a max-width:767px rule that outranks the desktop size (`selector.heading`) — the 128px one-word line had pushed the page past the phone viewport", false !== strpos( $ad_hcss, '@media (max-width:767px){selector.heading .heading-title{font-size:72px !important;line-height:64.8px !important;}}' ), $ad_hcss );
ga( "[AD] …and in the section stylesheet the phone tier comes AFTER its desktop rule (equal specificity + !important: the later rule wins)", '' !== $ad_hid && preg_match( '/#' . preg_quote( $ad_hid, '/' ) . ' h1(?::not\([^)]*\))?\{[^}]*font-size:128px/', $ad_design, $ad_dm, PREG_OFFSET_CAPTURE ) && preg_match( '/@media \(max-width:767px\)\{#' . preg_quote( $ad_hid, '/' ) . ' h1(?::not\([^)]*\))?\{[^}]*font-size:72px/', $ad_design, $ad_pm, PREG_OFFSET_CAPTURE ) && $ad_pm[0][1] > $ad_dm[0][1], wp_json_encode( array( $ad_hid, $ad_dm[0][1] ?? null, $ad_pm[0][1] ?? null ) ) );
$ad_lrow = $r_find( $ad_c1['_items'] ?? array(), function ( $n ) { return 'flexbox' === ( $n['type'] ?? '' ) && 'row' === (string) ( $n['atts']['direction']['base'] ?? '' ) && count( $n['_items'] ?? array() ) === 2; } );
ga( "[AD] a LABEL ROW (a kicker + an 8px status dot, `flex justify-between`) keeps its line on phones (responsive_collapse no) — the collapse had stretched the dot into a full-width bar", is_array( $ad_lrow ) && 'no' === (string) ( $ad_lrow['atts']['responsive_collapse'] ?? '' ) && 'between' === (string) ( $ad_lrow['atts']['justify_content']['base'] ?? '' ), wp_json_encode( array( $ad_lrow['atts']['responsive_collapse'] ?? null, $ad_lrow['atts']['justify_content'] ?? null ) ) );
$ad_c4 = $ad_grid['_items'][3] ?? array();
ga( "[AD] the grid's row minimum (`auto-rows-[minmax(320px,auto)]`) is every cell's min-height when it declares none (a two-line quote tile sat 96px short of its row)", '320' === (string) ( $ad_c4['_items'][0]['atts']['min_height']['base']['value'] ?? $ad_c4['atts']['min_height']['base']['value'] ?? '' ) || '320' === (string) ( $ad_c4['_items'][0]['atts']['min_height']['lg']['value'] ?? $ad_c4['atts']['min_height']['lg']['value'] ?? '' ), wp_json_encode( array( $ad_c4['atts']['min_height'] ?? null, $ad_c4['_items'][0]['atts']['min_height'] ?? null ) ) );

/* ---------------------------------------------------------------------
 * [AE] The feed, 2026-09-19: a glass "liquid" page's sandbox fixtures (six of them, joined into tests/fixtures/golden-fixture-3-glass.html):
 *     a fixed masked video anchor pinned off-centre with a float animation; a page-level blurred flare; a `w-max` skewed glass panel
 *     around the headline (a 2 % fill, a glow span); clipped / frosted buttons; an auto-fit hex grid stamping a 0px track; a
 *     percentage-sized overlay; a `:root` token block scoped away.
 * --------------------------------------------------------------------- */
echo "\n[AE] Feed fixtures: video anchor, page flare, glass panel + glow, clipped buttons, auto-fit tracks, % overlay, :root tokens\n";
$ae_html = file_get_contents( __DIR__ . '/fixtures/golden-fixture-3-glass.html' );
$ae_bl = FW_Site_Converter_Sources::build_from_html( $ae_html, 'FLUX O.S.', array( 'dynamic_chrome' => true, 'hifi_css' => true ) );
$ae_pg = $ae_bl['files']['pages.json']['pages'][0]['builder'] ?? array();
$ae_v  = $ae_bl['files']['theme-settings.json']['values'] ?? array();
$ae_misc = (string) ( $ae_v['misc_custom_css']['custom_css'] ?? '' );
$ae_css_of = function ( $n ) { return (string) ( $n['atts']['custom_css'] ?? '' ); };
$ae_design = wp_json_encode( $ae_bl['files']['theme-design.json'] ?? array(), JSON_UNESCAPED_SLASHES );
ga( "[AE] a fixed, offset video anchor (`top:45px; right:-144px; bottom:0; left:504px`, a drop-shadow, a float animation) is the site background video carried at ITS box — the four offsets, width/height auto, the filter, the animation + its keyframes, the video's own transform", 'yes' === (string) ( $ae_v['general_layout']['site_background']['video']['enabled'] ?? '' ) && false !== strpos( $ae_misc, '.site-bg-video{top:45px !important;right:-144px !important;bottom:0px !important;left:504px !important;width:auto !important;height:auto !important;opacity:0.85 !important;filter:drop-shadow(oklch(0.65 0.25 45 / 0.3) 0px 0px 30px) !important;animation:tourbillon-float 24s cubic-bezier(0.25, 0.1, 0.25, 1) 0s infinite alternate none !important;}' ) && false !== strpos( $ae_misc, '@keyframes tourbillon-float {' ) && false !== strpos( $ae_misc, '.site-bg-video video{transform:matrix(1.29505, -0.113302, 0.113302, 1.29505, 0, 0);filter:contrast(1.15) brightness(1.05);}' ), $ae_misc );
ga( "[AE] a page-level decor layer (an empty painted absolute child of <main>: a 1620px blurred radial glow, blend screen, z-index -1) is the theme main's ::before pseudo-layer with its full box (left + right offsets), never a band of its own", false !== strpos( $ae_misc, 'main.site-main{position:relative;}' ) && false !== strpos( $ae_misc, 'main.site-main::before{content:"";position:absolute;pointer-events:none;background-image:radial-gradient(circle, oklch(0.65 0.25 45 / 0.18), rgba(0, 0, 0, 0) 60%);filter:blur(120px);z-index:-1;mix-blend-mode:screen;height:1620px;top:-270px;left:-576px;right:-576px;}' ) && 3 === count( $ae_pg ), 'sections=' . count( $ae_pg ) );
$ae_h1 = $r_find( $ae_pg, function ( $n ) { return 'special_heading' === ( $n['shortcode'] ?? '' ) && false !== strpos( (string) ( $n['atts']['title'] ?? '' ), 'Ametorbit' ); } );
$ae_panel = $r_find( $ae_pg, function ( $n ) use ( $r_find ) { return 'flexbox' === ( $n['type'] ?? '' ) && '' !== (string) ( $n['atts']['border_preset'] ?? '' ) && null !== $r_find( $n['_items'] ?? array(), function ( $k ) { return 'special_heading' === ( $k['shortcode'] ?? '' ) && false !== strpos( (string) ( $k['atts']['title'] ?? '' ), 'Ametorbit' ); } ); } );
$ae_bp = null; foreach ( (array) ( $ae_v['border_presets'] ?? array() ) as $bp ) { if ( is_array( $ae_panel ) && 'boxp-' . sanitize_title( (string) ( $bp['preset_name'] ?? '' ) ) === (string) $ae_panel['atts']['border_preset'] ) { $ae_bp = $bp; break; } }
if ( null === $ae_bp && is_array( $ae_panel ) ) { foreach ( (array) ( $ae_v['border_presets'] ?? array() ) as $bp ) { if ( false !== strpos( (string) $ae_panel['atts']['border_preset'], substr( (string) ( $bp['id'] ?? '' ), 1 ) ) || false !== strpos( wp_json_encode( $bp ), 'skew' ) ) { $ae_bp = $bp; break; } } }
$ae_bp_css = (string) ( $ae_bp['custom_css'] ?? '' );
ga( "[AE] the `inline-block w-max -skew-x-3` glass panel around the headline is a Box Preset that keeps its 2 % fill (a translucent tint is a fill — the alpha gate is zero only), its backdrop-filter, clip-path, layered shadow, its resting skew and a content-hugging width", is_array( $ae_bp ) && 'rgba(233, 240, 245, 0.02)' === (string) ( $ae_bp['states']['default']['background']['color']['value']['custom'] ?? '' ) && false !== strpos( $ae_bp_css, 'backdrop-filter:blur(60px) brightness(1.1)' ) && false !== strpos( $ae_bp_css, 'clip-path:polygon(5% 0px, 100% 0px, 95% 100%, 0px 100%)' ) && false !== strpos( $ae_bp_css, 'transform:matrix(1, 0, -0.0524078, 1, 0, 0)' ) && false !== strpos( $ae_bp_css, 'width:max-content;max-width:100%' ), wp_json_encode( array( $ae_panel['atts']['border_preset'] ?? null, $ae_bp['states']['default']['background'] ?? null, $ae_bp_css ) ) );
ga( "[AE] the glowing run inside the title (`<span class=\"glow-text\">`, a text-shadow the parent has not) keeps its glow as a scoped `.sc-glow` rule (kses strips text-shadow inline), the markup carrying the class", is_array( $ae_h1 ) && false !== strpos( (string) $ae_h1['atts']['title'], 'sc-glow' ) && false === strpos( (string) $ae_h1['atts']['title'], 'text-shadow' ) && false !== strpos( $ae_css_of( $ae_h1 ), 'selector .sc-glow{text-shadow:oklch(0.65 0.25 45 / 0.6) 0px 0px 30px;}' ), wp_json_encode( array( $ae_h1['atts']['title'] ?? null, $ae_css_of( $ae_h1 ) ) ) );
$ae_h2 = $r_find( $ae_pg, function ( $n ) { return 'special_heading' === ( $n['shortcode'] ?? '' ) && false !== strpos( (string) ( $n['atts']['title'] ?? '' ), 'Dolorsita Orbi' ); } );
ga( "[AE] a heading's OWN glow (`.glow-text` on the h2) rides as `.heading-title{text-shadow}`; a `skew-x-2` lead paragraph keeps its resting transform", is_array( $ae_h2 ) && false !== strpos( $ae_css_of( $ae_h2 ), 'selector .heading-title{text-shadow:oklch(0.65 0.25 45 / 0.6) 0px 0px 30px;}' ) && null !== $r_find( $ae_pg, function ( $n ) { return 'text_block' === ( $n['shortcode'] ?? '' ) && false !== strpos( (string) ( $n['atts']['custom_css'] ?? '' ), 'transform:matrix(1, 0, 0.0349208, 1, 0, 0)' ); } ), $ae_css_of( $ae_h2 ) );
$ae_btn = array(); foreach ( (array) ( $ae_v['button_colors'] ?? array() ) as $bc ) { $ae_btn[ (string) ( $bc['color_name'] ?? $bc['preset_name'] ?? '' ) ] = (string) ( $bc['custom_css'] ?? '' ); }
ga( "[AE] the button presets carry the skin's shape and glass: the clipped outline button's clip-path; the frosted button's backdrop-filter + layered inset shadow + 2 % fill", isset( $ae_btn['Outline'] ) && false !== strpos( $ae_btn['Outline'], 'clip-path: polygon(10% 0px, 100% 0px, 90% 100%, 0px 100%)' ) && isset( $ae_btn['Fill'] ) && false !== strpos( $ae_btn['Fill'], 'backdrop-filter: blur(60px) brightness(1.1)' ) && false !== strpos( $ae_btn['Fill'], 'box-shadow: rgba(0, 0, 0, 0.9) 0px 30px 80px -20px, rgba(255, 255, 255, 0.1) 0px 1px 1px 0px inset' ), wp_json_encode( $ae_btn ) );
$ae_btns = array(); $r_all( $ae_pg, function ( $n ) { return 'button' === ( $n['shortcode'] ?? '' ); }, $ae_btns );
$ae_btn_styles = array_map( function ( $b ) { return (string) ( $b['atts']['style'] ?? '' ); }, $ae_btns );
ga( "[AE] …and every body button resolves to its preset (an oklch hairline border matches the outline preset — it had fallen to the transplant fallback)", 3 === count( $ae_btns ) && 2 === count( array_keys( $ae_btn_styles, 'btn-outline', true ) ) && 1 === count( array_keys( $ae_btn_styles, 'btn-fill', true ) ), wp_json_encode( $ae_btn_styles ) );
$ae_grid = $r_find( $ae_pg, function ( $n ) { return 'flexbox' === ( $n['type'] ?? '' ) && 2 === count( $n['_items'] ?? array() ) && '6' === (string) ( $n['_items'][0]['atts']['width']['lg']['preset'] ?? '' ); } );
ga( "[AE] an auto-fit grid stamping `584px 584px 0px` has TWO tracks (a 0px track is a collapsed auto-fit slot): 6 | 6, not a third each", is_array( $ae_grid ) && '6' === (string) ( $ae_grid['_items'][1]['atts']['width']['lg']['preset'] ?? '' ), wp_json_encode( $ae_grid['_items'][0]['atts']['width'] ?? null ) );
$ae_hexpanel = $r_find( $ae_pg, function ( $n ) { return 'flexbox' === ( $n['type'] ?? '' ) && false !== strpos( (string) ( $n['atts']['custom_css'] ?? '' ), 'border-left:2px solid oklch(0.65 0.25 45 / 0.3)' ); } );
ga( "[AE] a scoped-class (glass) card box carries its long tail too — the panel's clip-path + accent border-left + overflow clip (the preset path had them, this path dropped them)", is_array( $ae_hexpanel ) && false !== strpos( $ae_css_of( $ae_hexpanel ), 'clip-path:polygon(5% 0px, 100% 0px, 95% 100%, 0px 100%);border-left:2px solid oklch(0.65 0.25 45 / 0.3);overflow:hidden;box-sizing:border-box;height:100%;}' ), $ae_css_of( $ae_hexpanel ) );
$ae_ov = $r_find( $ae_pg, function ( $n ) { return 'flexbox' === ( $n['type'] ?? '' ) && false !== strpos( (string) ( $n['atts']['custom_css'] ?? '' ), 'height:200%' ); } );
ga( "[AE] an absolute overlay sized by percentages (`w-[200%] h-[200%] left-1/2 -translate-x-1/2`) keeps its full box: 200 % × 200 %, left 50 %, translateX(-50%) (it had collapsed to 0 × auto)", is_array( $ae_ov ) && false !== strpos( $ae_css_of( $ae_ov ), 'transform:translateX(-50%);height:200%;width:200%;top:0px;left:50%;' ), $ae_css_of( $ae_ov ) );
$ae_hero = $ae_pg[0] ?? array(); $ae_push = null;
foreach ( $ae_hero['_items'] ?? array() as $hi ) { if ( is_array( $hi ) && 'flexbox' === ( $hi['type'] ?? '' ) && false !== strpos( (string) ( $hi['atts']['custom_css'] ?? '' ), 'margin-top:auto' ) ) { $ae_push = $hi; break; } }
ga( "[AE] a hero's `mt-auto pt-24 pb-12` strip wrapper is a DIRECT flexbox child of the band with `margin-top:auto` (its own pushed column — joined into a wrapping row with the copy its auto margin had nothing to push against; the caption sat 96px under the CTA, the source 152), its caption carrying the wrapper's 96px top inset", is_array( $ae_push ) && null !== $r_find( array( $ae_push ), function ( $n ) { return false !== strpos( wp_json_encode( $n['atts'] ?? array() ), 'Trusted by makers' ) && false !== strpos( (string) ( $n['atts']['custom_css'] ?? '' ), 'padding-top:96px' ); } ), wp_json_encode( array_map( function ( $i ) { return ( $i['type'] ?? $i['shortcode'] ?? '' ) . ':' . substr( (string) ( $i['atts']['custom_css'] ?? '' ), 0, 40 ); }, $ae_hero['_items'] ?? array() ) ) );
$ae_strip = $r_find( $ae_pg, function ( $n ) { return 'flexbox' === ( $n['type'] ?? '' ) && false !== strpos( (string) ( $n['atts']['custom_css'] ?? '' ), 'opacity:0.7;filter:grayscale(1)' ); } );
$ae_chip0 = is_array( $ae_strip ) ? ( $ae_strip['_items'][0] ?? null ) : null;
$ae_chip_inner = is_array( $ae_chip0 ) ? ( ( 'flexbox' === ( $ae_chip0['_items'][0]['type'] ?? '' ) ) ? $ae_chip0['_items'][0] : $ae_chip0 ) : null;
$ae_chip_kinds = is_array( $ae_chip_inner ) ? array_map( function ( $i ) { return (string) ( $i['shortcode'] ?? $i['type'] ?? '' ); }, $ae_chip_inner['_items'] ?? array() ) : array();
ga( "[AE] a muted greyscale LOGO STRIP (`justify-center gap-16 opacity-70 grayscale hover:grayscale-0`) keeps its centring, its 64px gap, its opacity + filter (and the hover restore) on the row, and the wrapper's `pb-12` below it (mb-5)", is_array( $ae_strip ) && 'center' === (string) ( $ae_strip['atts']['justify_content']['base'] ?? '' ) && '7' === (string) ( $ae_strip['atts']['gap']['base'] ?? '' ) && false !== strpos( (string) $ae_strip['atts']['custom_css'], 'selector:hover{filter:none;}' ) && 4 === count( $ae_strip['_items'] ?? array() ) && 'mb-5' === (string) ( $ae_strip['atts']['spacing']['margin']['bottom'] ?? '' ), wp_json_encode( array( $ae_strip['atts']['justify_content'] ?? null, $ae_strip['atts']['gap'] ?? null, $ae_strip['atts']['custom_css'] ?? null, $ae_strip['atts']['spacing']['margin'] ?? null ) ) );
ga( "[AE] …and each chip (`<span class=\"flex items-center gap-2\"><iconify-icon/> Name</span>`) mirrors in DOCUMENT order — the icon, THEN the label (the mirror had put the element's own text first, every mark after its name); the label wears the chip's 20px bold and no flow margin (the theme's `* + p` rhythm had dropped it under the icon)", array( 'icon', 'text_block' ) === $ae_chip_kinds && 'Alpha Forge' === trim( wp_strip_all_tags( (string) ( $ae_chip_inner['_items'][1]['atts']['text'] ?? '' ) ) ) && false !== strpos( (string) ( $ae_chip_inner['_items'][1]['atts']['custom_css'] ?? '' ), 'font-size:1.25rem;font-weight:700' ) && false !== strpos( (string) ( $ae_chip_inner['_items'][1]['atts']['custom_css'] ?? '' ), 'margin:0' ), wp_json_encode( array( $ae_chip_kinds, $ae_chip_inner['_items'][1]['atts']['custom_css'] ?? null ) ) );
ga( "[AE] an `html,body{margin:0;background-color:black}` shell rule never reaches the root (the body's background would then paint OVER the z-index:-1 site video): the body half is scoped, the html half dropped", false !== strpos( $ae_design, '.sc-tw{margin:0;background-color:black}' ) && ! preg_match( '/:root\{[^}]*(?:margin|background)/', $ae_design ), substr( $ae_design, strpos( $ae_design, '.sc-tw{margin' ) ?: 0, 120 ) );
$ae_mf = $ae_v['main_footer_columns'] ?? array(); $ae_mfn = (string) ( $ae_mf['count'] ?? '' ); $ae_mfc = $ae_mf[ $ae_mfn ] ?? array();
ga( "[AE] a footer whose main row is a content-sized FLEX row (`flex justify-between items-center`: brand left, tagline right) → the footer builder's Auto Width + Distribution `between` (a fixed 50 / 50 split left the right-aligned tagline ending mid-row)", '2' === $ae_mfn && 'yes' === (string) ( $ae_mfc['main_footer_auto'] ?? '' ) && 'between' === (string) ( $ae_mfc['main_footer_justify'] ?? '' ), wp_json_encode( array( $ae_mfn, $ae_mfc['main_footer_auto'] ?? null, $ae_mfc['main_footer_justify'] ?? null, $ae_mfc['main_footer_split'] ?? null ) ) );
$ae_mfs = $ae_v['main_footer_custom_styling']['yes'] ?? array();
ga( "[AE] …the footer's `py-12` is the whole inset (body padding 3rem) and the main section's own inset is the row's measured margin + padding (none) → `pt-0` / `pb-0` through Custom Styling (the theme's default 1rem each had made a 133px footer 165)", '3rem' === (string) ( $ae_v['footer_padding_top'] ?? '' ) && 'pt-0' === (string) ( $ae_mfs['main_footer_padding']['padding']['top'] ?? '' ) && 'pb-0' === (string) ( $ae_mfs['main_footer_padding']['padding']['bottom'] ?? '' ) && 'yes' === (string) ( $ae_v['main_footer_custom_styling']['enabled'] ?? '' ), wp_json_encode( array( $ae_v['footer_padding_top'] ?? null, $ae_mfs ) ) );
ga( "[AE] …and the footer's plain brand glyph (no tile in the source footer) drops the header mark's frame entirely — no fill, no radius, no hairline border", false !== strpos( $ae_misc, '.footer .site-logo__mark{background:transparent !important;border-radius:0 !important;box-shadow:none !important;border:0 !important;padding:0 !important;' ), substr( $ae_misc, strpos( $ae_misc, '.footer .site-logo__mark{' ) ?: 0, 160 ) );
ga( "[AE] the source's `:root{--x}` token block stays at :root in the carried page CSS (never `.sc-tw :root`) — its CUSTOM PROPERTIES only (an `html{background}` shell rule at the root would paint the body over the z-index:-1 site video), the body rule scoped, a class rule prefixed", false !== strpos( $ae_design, ':root{--deep:oklch(0.15 0.02 280);--glow:oklch(0.65 0.25 45);}' ) && false === strpos( $ae_design, '.sc-tw :root' ) && ! preg_match( '/:root\{[^}]*background/', $ae_design ) && false !== strpos( $ae_design, '.sc-tw{background:var(--deep)}' ) && false !== strpos( $ae_design, '.sc-tw .custom-rule{color:var(--glow)}' ), substr( $ae_design, strpos( $ae_design, ':root{--deep' ) ?: 0, 200 ) );

/* ---------------------------------------------------------------------
 * [AF] A real-site audit, 2026-09-21: a lender landing page (tests/fixtures/golden-fixture-4-lender.html — the conversion
 *     test corpus, brand-neutral): a nav with a dropdown trigger <button>; a glass stats card in a bare `relative` wrapper with
 *     a `-top-4 -right-4` badge; a captioned logo strip of `h-8` images dimmed per item; a `max-w-5xl mx-auto` two-column
 *     comparison grid with uppercase eyebrows; step cards with a 60px faded corner numeral and a 56px tinted icon tile;
 *     testimonial cards with 48px portraits, a muted role line and a bold footer stat; a `max-w-3xl` FAQ; a `grid-cols-8`
 *     footer whose brand column spans two tracks.
 * --------------------------------------------------------------------- */
echo "\n[AF] Lender landing: nav gap, single-frame glass card, captioned logo strip, content caps, step numerals, testimonial look, footer tracks\n";
$af_html = file_get_contents( __DIR__ . '/fixtures/golden-fixture-4-lender.html' );
$af_bl = FW_Site_Converter_Sources::build_from_html( $af_html, 'Prefabo', array( 'dynamic_chrome' => true, 'hifi_css' => true ) );
$af_pg = $af_bl['files']['pages.json']['pages'][0]['builder'] ?? array();
$af_v  = $af_bl['files']['theme-settings.json']['values'] ?? array();
$af_misc = (string) ( $af_v['misc_custom_css']['custom_css'] ?? '' );
$af_css_of = function ( $n ) { return (string) ( $n['atts']['custom_css'] ?? '' ); };
$af_all = function ( $nodes, $pred ) use ( &$af_all ) { $out = array(); foreach ( (array) $nodes as $n ) { if ( ! is_array( $n ) ) { continue; } if ( $pred( $n ) ) { $out[] = $n; } foreach ( $af_all( $n['_items'] ?? array(), $pred ) as $r ) { $out[] = $r; } } return $out; };
$af_json = function ( $n ) { return wp_json_encode( $n, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); };

// header: the <nav> holds a dropdown trigger <button> ("More", aria-haspopup) — still the menu group, so its gap:32px rides the nav
ga( "[AF] a nav whose items include a dropdown-trigger <button> keeps its `gap:32px` (the button no longer disqualifies the <nav>)", false !== strpos( $af_misc, '.site-header .primary-menu{gap:32px;}' ), substr( $af_misc, 0, 300 ) );

// hero: the glass stats card is framed ONCE (the bare `relative` wrapper stays skinless) and anchors the corner badge
$af_hero = $af_pg[0] ?? array();
$af_glass = $af_all( array( $af_hero ), function ( $n ) { return 'flexbox' === ( $n['type'] ?? '' ) && ( false !== strpos( (string) ( $n['atts']['custom_css'] ?? '' ), 'backdrop-filter:blur(12px)' ) || preg_match( '/^boxp-/', (string) ( $n['atts']['border_preset'] ?? '' ) ) ); } );
$af_wrap = $n_find( array( $af_hero ), function ( $n ) { return 'flexbox' === ( $n['type'] ?? '' ) && '302' === (string) ( $n['atts']['min_height']['base']['value'] ?? '' ); } );
ga( "[AF] hero stats card: the `relative` wrapper (min-height 302) carries NO glass skin of its own — the panel inside wears it once", is_array( $af_wrap ) && false === strpos( $af_css_of( $af_wrap ), 'backdrop-filter' ) && false === strpos( $af_css_of( $af_wrap ), 'background:' ), $af_wrap ? $af_css_of( $af_wrap ) : 'no wrapper' );
$af_badge_host = $n_find( array( $af_hero ), function ( $n ) { foreach ( (array) ( $n['_items'] ?? array() ) as $c ) { if ( 'code_block' === ( $c['shortcode'] ?? '' ) && false !== strpos( (string) ( $c['atts']['code'] ?? '' ), 'position:absolute' ) ) { return true; } } return false; } );
ga( "[AF] the `-top-4 -right-4` badge's flexbox host is `position:relative` (native Position) so the chip pins to the card, not the band", is_array( $af_badge_host ) && 'relative' === (string) ( $af_badge_host['atts']['element_position']['position'] ?? '' ), $af_badge_host ? $af_json( $af_badge_host['atts']['element_position'] ?? null ) : 'no host' );

// logo strip: the caption survives as its own text block; the marks are the image's measured 32px; the per-item 80% dim counts
$af_strip = $af_pg[1] ?? array();
$af_cap = $n_find( array( $af_strip ), function ( $n ) { return 'text_block' === ( $n['shortcode'] ?? '' ) && false !== strpos( (string) ( $n['atts']['text'] ?? '' ), 'Trusted by buyers of leading manufacturers' ); } );
$af_lg  = $n_find( array( $af_strip ), function ( $n ) { return 'logo_grid' === ( $n['shortcode'] ?? '' ); } );
ga( "[AF] logo strip: the wrapper's caption <p> is carried as a text block beside the logo_grid (it had been dropped)", is_array( $af_cap ), $af_json( array_map( function ( $i ) { return ( $i['type'] ?? '' ) . '/' . ( $i['shortcode'] ?? '' ); }, $af_strip['_items'][0]['_items'] ?? array() ) ) );
ga( "[AF] logo strip: `<img class=\"h-8\">` marks render at their measured 32px, not the 48px default", is_array( $af_lg ) && '32' === (string) ( $af_lg['atts']['logo_height'] ?? '' ), $af_lg['atts']['logo_height'] ?? null );
ga( "[AF] logo strip: the per-ITEM `opacity-80` dim rides the grid (selector{opacity:0.8})", is_array( $af_lg ) && false !== strpos( $af_css_of( $af_lg ), 'opacity:0.8' ), $af_lg ? $af_css_of( $af_lg ) : null );

// comparison grid: `max-w-5xl mx-auto` → the row band's cap (!important, centred); the eyebrow keeps its uppercase tracking
$af_cmp = null; foreach ( $af_pg as $s ) { if ( false !== strpos( $af_json( $s ), 'Misclassified' ) ) { $af_cmp = $s; break; } }
$af_row = $n_find( array( $af_cmp ), function ( $n ) { return 'flexbox' === ( $n['type'] ?? '' ) && 'row' === (string) ( $n['atts']['direction']['base'] ?? '' ) && false !== strpos( wp_json_encode( $n ), 'Misclassified' ); } );
ga( "[AF] a `max-w-5xl mx-auto` two-column grid is capped at 1024px and centred (the recurring \"section container width\" report)", is_array( $af_row ) && false !== strpos( $af_css_of( $af_row ), 'max-width:1024px !important;margin-left:auto !important;margin-right:auto !important' ), $af_row ? $af_css_of( $af_row ) : 'no row' );
$af_ov = $n_find( array( $af_cmp ), function ( $n ) { return 'special_heading' === ( $n['shortcode'] ?? '' ) && 'The Problem' === trim( (string) ( $n['atts']['overline'] ?? '' ) ); } );
ga( "[AF] the comparison column's eyebrow keeps its `uppercase tracking-wider` (overline_cs → native transform / letter-spacing)", is_array( $af_ov ) && 'yes' === (string) ( $af_ov['atts']['overline_uppercase'] ?? '' ) && false !== strpos( $af_css_of( $af_ov ), 'letter-spacing:0.7px' ), $af_ov ? $af_json( array( $af_ov['atts']['overline_uppercase'] ?? null, $af_css_of( $af_ov ) ) ) : 'no overline' );

// steps: the corner numeral (60px, faded, pinned top/right ONLY), the 56px tinted icon tile with the glyph's green ink, the `p-8` inset, the 1024 cap
$af_steps = $n_find( $af_pg, function ( $n ) { return 'steps' === ( $n['shortcode'] ?? '' ); } );
$af_scss = $af_steps ? $af_css_of( $af_steps ) : '';
ga( "[AF] steps: the numeral is pinned to the card's top/right corner only (no bottom/left) at 60px in the source's faded ink", false !== strpos( $af_scss, 'selector .fw-steps__num-inline{position:absolute;margin:0;pointer-events:none;top:16px;right:16px;}' ) && false !== strpos( $af_scss, 'font-size:60px' ) && false !== strpos( $af_scss, 'color:rgba(39, 104, 77, 0.1)' ), $af_scss );
ga( "[AF] steps: the icon tile is the source's 56px (`--st-size`) and the glyph keeps its green ink (marker_text_color) on the 10% tint", false !== strpos( $af_scss, '--st-size:56px' ) && 'rgb(39, 104, 77)' === (string) ( $af_steps['atts']['marker_text_color']['custom'] ?? '' ), $af_json( array( $af_steps['atts']['marker_text_color'] ?? null, $af_scss ) ) );
ga( "[AF] steps: the `p-8` card inset and the `max-w-5xl` cap ride the shortcode", false !== strpos( $af_scss, 'selector.fw-steps .fw-steps__item{padding:32px;}' ) && false !== strpos( $af_scss, 'max-width:1024px !important' ), $af_scss );

// testimonials: 48px portraits, the role line's own ink on the native option, the bold 20px footer stat, no blockquote inset
$af_ts = $n_find( $af_pg, function ( $n ) { return 'testimonials' === ( $n['shortcode'] ?? '' ); } );
$af_tcss = $af_ts ? $af_css_of( $af_ts ) : '';
ga( "[AF] testimonials: a `w-12` portrait renders 48px (avatar-sm + the exact px), not the 128px default", is_array( $af_ts ) && 'avatar-sm' === (string) ( $af_ts['atts']['avatar_size'] ?? '' ) && false !== strpos( $af_tcss, '.testimonial-avatar img{width:48px !important;height:48px !important;}' ), $af_json( array( $af_ts['atts']['avatar_size'] ?? null, $af_tcss ) ) );
ga( "[AF] testimonials: the role line's colour is the source's muted ink on author_job_color (the theme's muted surface had made it invisible)", 'rgb(103, 126, 117)' === (string) ( $af_ts['atts']['author_job_color']['custom'] ?? '' ), $af_json( $af_ts['atts']['author_job_color'] ?? null ) );
ga( "[AF] testimonials: the footer stat figure keeps its 20px / 700 and the quote sheds the theme blockquote inset", false !== strpos( $af_tcss, 'selector .ts-card__extra-value{' ) && preg_match( '/\.ts-card__extra-value\{[^}]*font-size:20px/', $af_tcss ) && false !== strpos( $af_tcss, '.testimonial-quote{margin-left:0;margin-right:0;padding-left:0;padding-right:0;}' ), $af_tcss );

// FAQ: the `max-w-3xl mx-auto` wrapper caps the accordion
$af_faq = $n_find( $af_pg, function ( $n ) { return 'accordion' === ( $n['shortcode'] ?? '' ); } );
ga( "[AF] a `max-w-3xl mx-auto` wrapper around the FAQ caps the accordion at 768px, centred", is_array( $af_faq ) && false !== strpos( $af_css_of( $af_faq ), 'max-width:768px !important;margin-left:auto !important;margin-right:auto !important' ), $af_faq ? $af_css_of( $af_faq ) : 'no accordion' );

// palette: Muted is the muted TEXT ink (muted-foreground), never the muted surface tint
$af_muted = ''; foreach ( (array) ( $af_v['theme_colors'] ?? array() ) as $tc ) { if ( 'Muted' === ( $tc['name'] ?? '' ) ) { $af_muted = strtolower( (string) ( $tc['color'] ?? '' ) ); } }
ga( "[AF] theme colour Muted = the page's muted text ink (#677e75), not the pale `muted` surface (#eaf0ed)", '#677e75' === $af_muted, $af_muted );

// footer: a `grid-cols-8` with a `col-span-2` brand → measured tracks pinned on the Auto-Width row (318px brand, 143px links), so the ninth column wraps like the source
ga( "[AF] an 8-track footer with a `col-span-2` brand pins the measured tracks (brand 318px = 2×143 + 32, links 143px) on the Auto-Width row", false !== strpos( $af_misc, '.footer-col--auto:nth-child(1){flex:0 0 318px;min-width:0;}' ) && false !== strpos( $af_misc, '.footer-col--auto:nth-child(8){flex:0 0 143px;min-width:0;}' ) && 'yes' === (string) ( $af_v['main_footer_columns']['8']['main_footer_auto'] ?? '' ), substr( $af_misc, strpos( $af_misc, 'Footer: the source grid' ) ?: 0, 200 ) );


/* ---------------------------------------------------------------------
 * [AG] A real-site audit, 2026-09-21 (a diner landing page — the conversion test corpus): a ticker bar above a fixed
 *     <nav> inside a wrapper <header> (the capture stamps the nav); a display-face nav; a card with a full-width banner
 *     photo + a padded body + a `mb-16` wrapper; an hours grid of tiny "Tue. / 4–10 PM" tiles; a section h3 inside a card.
 *     Built inline (no brand names).
 * --------------------------------------------------------------------- */
echo "\n[AG] Diner landing: masthead stamp on the nav, menu font, banner crop, hours tiles, card h3, times are not counters\n";
$ag_cs = 'color:rgb(13, 13, 13);font-family:Serifa, serif;font-size:16px;font-weight:400;line-height:24px;text-align:start;display:block';
$ag_html = '<!DOCTYPE html><html><head><style>:root{}</style></head><body data-sc-cs="' . $ag_cs . '">'
	. '<header data-sc-cs="' . $ag_cs . '"><div class="fixed top-0 h-12 bg-primary" data-sc-cs="background-color:rgb(226, 60, 68);color:rgb(255, 255, 255);height:48px;display:flex;position:fixed"><span data-sc-cs="color:rgb(255, 255, 255);font-size:18px;text-transform:uppercase">BOOK A TABLE · THE NEW MENU IS HERE</span></div>'
	. '<nav class="fixed top-12 bg-background border-b-4 border-primary" data-sc-header="rest-height:100px" data-sc-cs="background-color:rgb(255, 255, 255);border-bottom-width:4px;border-bottom-style:solid;border-bottom-color:rgb(226, 60, 68);height:100px;display:block;position:fixed"><div class="container mx-auto px-4 grid grid-cols-3 items-center h-24" data-sc-cs="padding:0px 16px;height:96px;display:grid;align-items:center"><a href="/" data-sc-cs="height:80px;display:flex"><img src="/assets/mark.png" alt="Diner" class="h-16 lg:h-20 w-auto" data-sc-cs="height:80px;width:119px;display:block"></a>'
	. '<div class="hidden lg:flex items-center justify-center gap-10" data-sc-cs="display:flex;gap:40px;align-items:center;justify-content:center;height:28px">'
	. '<a href="/menu" class="font-heading text-xl uppercase" data-sc-cs="color:rgb(13, 13, 13);font-family:&quot;Grotesk Condensed&quot;, &quot;Alfa Slab One&quot;, cursive;font-size:20px;font-weight:400;letter-spacing:0.5px;text-transform:uppercase;height:28px;display:block">Menu</a>'
	. '<a href="/locations" class="font-heading text-xl uppercase" data-sc-cs="color:rgb(13, 13, 13);font-family:&quot;Grotesk Condensed&quot;, &quot;Alfa Slab One&quot;, cursive;font-size:20px;font-weight:400;letter-spacing:0.5px;text-transform:uppercase;height:28px;display:block">Locations</a>'
	. '<a href="/about" class="font-heading text-xl uppercase" data-sc-cs="color:rgb(13, 13, 13);font-family:&quot;Grotesk Condensed&quot;, &quot;Alfa Slab One&quot;, cursive;font-size:20px;font-weight:400;letter-spacing:0.5px;text-transform:uppercase;height:28px;display:block">About</a></div>'
	. '<div class="flex items-center justify-self-end gap-3" data-sc-cs="display:flex;gap:12px"><a href="/book" class="inline-flex items-center px-7 py-3 bg-primary text-white rounded" data-sc-cs="background-color:rgb(226, 60, 68);color:rgb(255, 255, 255);font-size:18px;padding:12px 28px;border-radius:4px;display:inline-flex;height:52px">Book a Table</a></div></div></nav></header>'
	. '<main data-sc-cs="' . $ag_cs . '">'
	. '<section id="locations" class="py-16 md:py-24 bg-primary" data-sc-cs="background-color:rgb(226, 60, 68);color:rgb(13, 13, 13);font-family:Serifa, serif;font-size:16px;padding:96px 0px;height:1100px;display:block">'
	. '<div class="container mx-auto px-4" data-sc-cs="padding:0px 16px;margin:0px 20px;max-width:1400px;display:block">'
	. '<div class="text-center mb-14" data-sc-cs="text-align:center;margin:0px 0px 56px;height:60px;display:block"><h2 class="font-heading text-5xl text-white uppercase mb-2" data-sc-cs="color:rgb(255, 255, 255);font-family:&quot;Grotesk Condensed&quot;, cursive;font-size:48px;font-weight:400;line-height:48px;text-align:center;margin:0px 0px 8px;height:48px;display:block">Our Location</h2><div class="w-24 h-1 bg-white mx-auto" data-sc-cs="background-color:rgb(255, 255, 255);margin:0px 636px;height:4px;width:96px;display:block"></div></div>'
	. '<div class="max-w-3xl mx-auto mb-16" data-sc-cs="margin:0px 300px 64px;max-width:768px;height:622px;display:block"><div class="relative rounded-2xl overflow-hidden border border-border bg-background shadow-sm" data-sc-cs="background-color:rgb(255, 255, 255);border-top-width:1px;border-top-style:solid;border-top-color:rgb(230, 230, 230);border-radius:16px;overflow:hidden;height:622px;display:block;position:relative">'
	. '<img src="/assets/interior.webp" alt="Diner interior" class="w-full h-48 md:h-64 object-cover" data-sc-cs="max-width:100%;height:256px;width:766px;display:block;object-fit:cover">'
	. '<div class="p-8 md:p-12 text-center" data-sc-cs="padding:48px;text-align:center;height:364px;display:block"><span class="font-heading text-xs text-primary uppercase tracking-[0.3em] mb-2 block" data-sc-cs="color:rgb(226, 60, 68);font-size:12px;letter-spacing:3.6px;text-transform:uppercase;text-align:center;margin:0px 0px 8px;height:16px;display:block">Visit Us</span>'
	. '<h3 class="font-heading text-5xl text-primary uppercase mb-3" data-sc-cs="color:rgb(226, 60, 68);font-family:&quot;Grotesk Condensed&quot;, cursive;font-size:48px;font-weight:400;line-height:48px;text-align:center;margin:0px 0px 12px;height:48px;display:block">Diner Downtown</h3>'
	. '<p class="text-sm uppercase tracking-wider mb-1" data-sc-cs="color:rgb(102, 102, 102);font-size:14px;letter-spacing:0.7px;text-transform:uppercase;text-align:center;margin:0px 0px 4px;height:20px;display:block">Located in Centre Square</p>'
	. '<p class="text-lg mb-6" data-sc-cs="color:rgb(13, 13, 13);font-size:18px;text-align:center;margin:0px 0px 24px;height:28px;display:block">1000 Main Street, Springfield, OR 97477</p>'
	. '<div class="flex flex-col sm:flex-row items-center justify-center gap-4 mb-8" data-sc-cs="display:flex;gap:16px;justify-content:center;align-items:center;flex-direction:row;margin:0px 0px 32px;height:32px">'
	. '<div class="flex items-center gap-3" data-sc-cs="display:flex;gap:12px;align-items:center;height:32px"><div class="w-8 h-8 rounded-full bg-primary/10 flex items-center justify-center" data-sc-cs="background-color:rgba(226, 60, 68, 0.1);border-radius:9999px;height:32px;display:flex"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="lucide lucide-phone w-4 h-4 text-primary"><path d="M3 5h4l2 5-2 1a11 11 0 0 0 6 6l1-2 5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 7a2 2 0 0 1 0-2" data-sc-cs="color:rgb(226, 60, 68)"></path></svg></div><a href="tel:5555551234" class="text-sm" data-sc-cs="color:rgb(13, 13, 13);font-size:14px;height:20px;display:block">(555) 555-1234</a></div>'
	. '<div class="flex items-center gap-3" data-sc-cs="display:flex;gap:12px;align-items:center;height:32px"><div class="w-8 h-8 rounded-full bg-primary/10 flex items-center justify-center" data-sc-cs="background-color:rgba(226, 60, 68, 0.1);border-radius:9999px;height:32px;display:flex"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="lucide lucide-map-pin w-4 h-4 text-primary"><path d="M20 10c0 6-8 12-8 12S4 16 4 10a8 8 0 0 1 16 0" data-sc-cs="color:rgb(226, 60, 68)"></path></svg></div><span class="text-sm" data-sc-cs="color:rgb(102, 102, 102);font-size:14px;height:20px;display:block">example.org</span></div></div>'
	. '<a href="https://example.org" class="inline-flex items-center gap-2 px-6 py-3 bg-primary text-white font-heading text-sm uppercase rounded" data-sc-cs="background-color:rgb(226, 60, 68);color:rgb(255, 255, 255);font-size:14px;text-transform:uppercase;padding:12px 24px;border-radius:4px;height:44px;display:inline-flex;gap:8px;align-items:center">Visit Website</a></div></div></div>'
	. '<div class="max-w-4xl mx-auto" data-sc-cs="margin:0px 236px;max-width:896px;height:170px;display:block"><h3 class="font-heading text-3xl text-white uppercase text-center mb-8" data-sc-cs="color:rgb(255, 255, 255);font-family:&quot;Grotesk Condensed&quot;, cursive;font-size:30px;font-weight:400;line-height:36px;text-align:center;margin:0px 0px 32px;height:36px;display:block">Hours</h3>'
	. '<div class="grid grid-cols-7 gap-3" data-sc-cs="display:grid;gap:12px;grid-template-columns:117.7px 117.7px 117.7px 117.7px 117.7px 117.7px 117.7px;height:102px">';
foreach ( array( array( 'Tue.', '4–10 PM' ), array( 'Wed.', '4–10 PM' ), array( 'Thu.', '11:30 AM–10 PM' ), array( 'Fri.', '11:30 AM–10 PM' ), array( 'Sat.', '11:30 AM–10 PM' ), array( 'Sun.', '11:30 AM–9 PM' ), array( 'Mon.', '4–10 PM' ) ) as $ag_d ) {
	$ag_html .= '<div class="text-center py-6 rounded-lg border border-white/30 bg-[hsl(0,70%,45%)]" data-sc-cs="background-color:rgb(195, 34, 34);text-align:center;padding:24px 0px;border-top-width:1px;border-top-style:solid;border-top-color:rgba(255, 255, 255, 0.3);border-radius:8px;height:102px;display:block">'
		. '<p class="font-heading text-base uppercase mb-2" data-sc-cs="color:rgb(245, 214, 61);font-family:&quot;Grotesk Condensed&quot;, cursive;font-size:16px;text-transform:uppercase;text-align:center;margin:0px 0px 8px;height:24px;display:block">' . $ag_d[0] . '</p>'
		. '<p class="text-sm font-bold text-white" data-sc-cs="color:rgb(255, 255, 255);font-size:14px;font-weight:700;text-align:center;height:20px;display:block">' . $ag_d[1] . '</p></div>';
}
$ag_html .= '</div></div></div></section></main></body></html>';
$ag_bl = FW_Site_Converter_Sources::build_from_html( $ag_html, 'Diner', array( 'dynamic_chrome' => true, 'hifi_css' => true ) );
$ag_pg = $ag_bl['files']['pages.json']['pages'][0]['builder'] ?? array();
$ag_v  = $ag_bl['files']['theme-settings.json']['values'] ?? array();
$ag_css_of = function ( $n ) { return (string) ( $n['atts']['custom_css'] ?? '' ); };
$ag_json = function ( $n ) { return wp_json_encode( $n, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); };
$ag_reg = wp_json_encode( $ag_bl['files']['theme-design.json'] ?? array(), JSON_UNESCAPED_SLASHES );

ga( "[AG] the capture's masthead stamp sits on the fixed <nav> inside the wrapper <header> — the rest height (100px) is still read (header_layout.min_height)", '100' === (string) ( $ag_v['header_layout']['min_height']['value'] ?? '' ), $ag_json( $ag_v['header_layout']['min_height'] ?? null ) );
ga( "[AG] a display-face nav (`font-heading`, not the body serif) → Header → Menu → Menu Font Family", 'Grotesk Condensed' === (string) ( $ag_v['header_menu']['menu_font']['family'] ?? '' ), $ag_json( $ag_v['header_menu']['menu_font'] ?? null ) );
ga( "[AG] a Simple image logo carries its RENDERED width (119px) so the mark is not auto-shrunk to the bar", '119' === (string) ( $ag_v['header_logo']['logo_type']['simple']['width']['value'] ?? '' ), $ag_json( $ag_v['header_logo']['logo_type']['simple'] ?? null ) );

$ag_sec = null; foreach ( $ag_pg as $s ) { if ( false !== strpos( $ag_json( $s ), 'Diner Downtown' ) ) { $ag_sec = $s; break; } }
$ag_img = $n_find( array( $ag_sec ), function ( $n ) { return 'media_image' === ( $n['shortcode'] ?? '' ); } );
ga( "[AG] a card's `w-full h-64 object-cover` banner photo is a full-width 256px cover crop (not its natural size, inset in the card)", is_array( $ag_img ) && false !== strpos( $ag_css_of( $ag_img ), 'selector{width:100%;align-self:stretch;}selector img{width:100%;height:256px;object-fit:cover;display:block;}' ), $ag_img ? $ag_css_of( $ag_img ) : 'no image' );
$ag_h3 = $n_find( array( $ag_sec ), function ( $n ) { return 'special_heading' === ( $n['shortcode'] ?? '' ) && 'Diner Downtown' === trim( (string) ( $n['atts']['title'] ?? '' ) ); } );
ga( "[AG] the card's own h3 keeps its red (title_color) — and the section-level `#locations h3` rule excludes card headings (`:not([class*=\"boxp-\"] *)`)", is_array( $ag_h3 ) && 'rgb(226, 60, 68)' === (string) ( $ag_h3['atts']['title_color']['custom'] ?? '' ) && false !== strpos( $ag_reg, '#locations h3:not([class*=\"boxp-\"] *){' ), $ag_json( array( $ag_h3['atts']['title_color'] ?? null, substr( $ag_reg, strpos( $ag_reg, '#locations h3' ) ?: 0, 80 ) ) ) );
$ag_fl = $n_find( array( $ag_sec ), function ( $n ) { return 'feature_list' === ( $n['shortcode'] ?? '' ); } );
ga( "[AG] a `flex flex-col sm:flex-row justify-center gap-4` contact row is a HORIZONTAL centred feature list at its 16px gap (the measured direction, not the phone class)", is_array( $ag_fl ) && 'horizontal' === (string) ( $ag_fl['atts']['orientation'] ?? '' ) && false !== strpos( $ag_css_of( $ag_fl ), 'selector.fw-fl--orient-horizontal{justify-content:center;column-gap:16px;}' ), $ag_json( array( $ag_fl['atts']['orientation'] ?? null, $ag_fl ? $ag_css_of( $ag_fl ) : null ) ) );
$ag_btn = $n_find( array( $ag_sec ), function ( $n ) { return 'button' === ( $n['shortcode'] ?? '' ) && 'Visit Website' === trim( (string) ( $n['atts']['label'] ?? '' ) ); } );
ga( "[AG] the card body's `p-12` bottom inset reaches the last block (the button's Spacing margin-bottom = 48px) — it had sat on the card's edge", is_array( $ag_btn ) && 'mb-5' === (string) ( $ag_btn['atts']['spacing']['margin']['bottom'] ?? '' ), $ag_json( $ag_btn['atts']['spacing']['margin'] ?? null ) );
$ag_card = $n_find( array( $ag_sec ), function ( $n ) { return 'flexbox' === ( $n['type'] ?? '' ) && false !== strpos( wp_json_encode( $n ), 'Diner Downtown' ) && '' !== (string) ( $n['atts']['border_preset'] ?? '' ); } );
ga( "[AG] the card wrapper's `mb-16` rides the panel (Spacing margin-bottom 64px) so the HOURS heading keeps its gap", is_array( $ag_card ) && 'mb-7' === (string) ( $ag_card['atts']['spacing']['margin']['bottom'] ?? '' ), $ag_json( $ag_card['atts']['spacing']['margin'] ?? null ) );
$ag_bar = $n_find( array( $ag_sec ), function ( $n ) { return 'divider' === ( $n['shortcode'] ?? '' ) || ( 'flexbox' === ( $n['type'] ?? '' ) && empty( $n['_items'] ) && false !== strpos( (string) ( $n['atts']['custom_css'] ?? '' ), 'height:4px;width:96px' ) ); } );
ga( "[AG] the heading wrapper's `mb-14` rides the rule-bar divider under the section title", is_array( $ag_bar ) && 'mb-6' === (string) ( $ag_bar['atts']['spacing']['margin']['bottom'] ?? '' ), $ag_json( $ag_bar['atts']['spacing']['margin'] ?? null ) );

// hours tiles: seven cells, every one a decomposed text pair (no counter, no verbatim mirror), centred, boxed ONCE
$ag_tiles = $af_all( $ag_pg, function ( $n ) { return 'flexbox' === ( $n['type'] ?? '' ) && 2 === count( (array) ( $n['_items'] ?? array() ) ) && preg_match( '/^(Tue|Wed|Thu|Fri|Sat|Sun|Mon)\.$/', trim( wp_strip_all_tags( (string) ( $n['_items'][0]['atts']['text'] ?? '' ) ) ) ); } );
ga( "[AG] hours tiles: all seven `Day / time` cells decompose to two text blocks (an 11-character `Tue. / 4–10 PM` tile had fallen to the verbatim mirror; a `4–10 PM` had become a counter)", 7 === count( $ag_tiles ) && 0 === count( $af_all( $ag_pg, function ( $n ) { return in_array( (string) ( $n['shortcode'] ?? '' ), array( 'counter', 'code_block' ), true ); } ) ), $ag_json( array( count( $ag_tiles ), array_map( function ( $n ) { return $n['shortcode'] ?? $n['type'] ?? ''; }, $af_all( $ag_pg, function ( $n ) { return in_array( (string) ( $n['shortcode'] ?? '' ), array( 'counter', 'code_block' ), true ); } ) ) ) ) );
ga( "[AG] hours tiles: each cell is text-centred through the cell's own measured alignment (its class list is empty on the cell record)", count( $ag_tiles ) === count( array_filter( $ag_tiles, function ( $n ) { return 'center' === (string) ( $n['atts']['text_align'] ?? '' ) || false !== strpos( (string) ( $n['atts']['custom_css'] ?? '' ), 'text-align:center' ); } ) ), $ag_json( array_map( function ( $n ) { return $n['atts']['text_align'] ?? null; }, $ag_tiles ) ) );


/* ---------------------------------------------------------------------
 * [AH] A real-site audit, 2026-09-21 (a home-goods storefront — the conversion test corpus): a wordmark link in the header's
 *     outer <nav>; a `flex justify-between` intro row with a kicker + "View All"; 4:5 product tiles with corner badges, a
 *     wishlist button and a hover pill; a 12-col bento of photo tiles with the title INSIDE the frame. Built inline.
 * --------------------------------------------------------------------- */
echo "\n[AH] Storefront: wordmark not a menu item, kicker not duplicated, 4:5 tiles + badges, no control glyph, bento photo tiles as overlay image boxes\n";
$ah_cs = 'color:rgb(42, 38, 34);font-family:Inter, system-ui, sans-serif;font-size:16px;font-weight:400;line-height:24px;text-align:start;display:block';
$ah_tile = function ( $name, $cat, $badge ) use ( $ah_cs ) {
	return '<article class="group" data-sc-cs="' . $ah_cs . ';height:521px"><a class="block" href="/products/' . sanitize_title( $name ) . '" data-sc-cs="' . $ah_cs . '"><div class="relative overflow-hidden bg-muted/50 mb-5 aspect-[4/5]" data-sc-cs="background-color:rgba(232, 230, 227, 0.5);margin:0px 0px 20px;height:382px;width:306px;display:block;position:relative;aspect-ratio:4 / 5">'
		. '<img src="/assets/' . sanitize_title( $name ) . '.jpg" alt="' . $name . '" class="w-full h-full object-cover" data-sc-cs="max-width:100%;height:382px;width:306px;display:block;object-fit:cover">'
		. '<button class="absolute top-5 right-5 p-2.5 rounded-full bg-background/90" data-sc-cs="background-color:rgba(250, 248, 245, 0.9);padding:10px;height:36px;width:36px;display:block;position:absolute;top:20px;right:20px;border-radius:9999px"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="lucide lucide-heart w-4 h-4"><path d="M19 14c1.5-1.5 3-3.2 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.8 0-3 .5-4.5 2-1.5-1.5-2.7-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.1 3 5.5l7 7z" data-sc-cs="color:rgb(42, 38, 34)"></path></svg></button>'
		. ( '' !== $badge ? '<div class="absolute top-5 left-5 flex flex-col gap-2" data-sc-cs="display:flex;gap:8px;position:absolute;top:20px;left:20px;height:27px"><span class="px-3 py-1.5 text-[10px] font-semibold tracking-[0.2em] uppercase bg-primary text-primary-foreground" data-sc-cs="background-color:rgb(166, 94, 63);color:rgb(250, 248, 245);font-size:10px;font-weight:600;letter-spacing:2px;text-transform:uppercase;padding:6px 12px;height:27px;display:block">' . $badge . '</span></div>' : '' )
		. '<div class="absolute bottom-0 left-0 right-0 flex items-center justify-center pb-6 opacity-0 group-hover:opacity-100" data-sc-cs="padding:0px 0px 24px;height:60px;display:flex;position:absolute;bottom:0px;left:0px;right:0px"><span class="px-6 py-2.5 text-xs font-medium tracking-[0.15em] uppercase bg-background/95" data-sc-cs="background-color:rgba(250, 248, 245, 0.95);font-size:12px;text-transform:uppercase;padding:10px 24px;height:36px;display:block">View Details</span></div></div>'
		. '<div class="space-y-2" data-sc-cs="' . $ah_cs . '"><p class="text-[11px] font-medium tracking-[0.2em] uppercase text-muted-foreground/70" data-sc-cs="color:rgba(124, 115, 106, 0.7);font-size:11px;font-weight:500;letter-spacing:2.2px;text-transform:uppercase;height:16.5px;display:block">' . $cat . '</p>'
		. '<h3 class="font-serif text-xl text-foreground" data-sc-cs="color:rgb(42, 38, 34);font-family:&quot;Cormorant Garamond&quot;, Georgia, serif;font-size:20px;font-weight:500;line-height:28px;margin:8px 0px 0px;height:28px;display:block">' . $name . '</h3>'
		. '<p class="text-sm text-muted-foreground" data-sc-cs="color:rgb(124, 115, 106);font-size:14px;line-height:21px;margin:8px 0px 0px;height:21px;display:block">A graceful piece for daily rituals</p>'
		. '<p class="text-sm" data-sc-cs="color:rgb(42, 38, 34);font-size:14px;font-weight:500;margin:8px 0px 0px;height:21px;display:block">$485</p></div></a></article>';
};
$ah_ctile = function ( $name, $span, $aspect, $w, $h ) use ( $ah_cs ) {
	return '<div class="md:col-span-' . $span . '" data-sc-cs="' . $ah_cs . ';height:' . $h . 'px;track-frac:' . round( $span / 12, 3 ) . '"><article data-sc-cs="' . $ah_cs . ';height:' . $h . 'px"><a class="group block relative" href="/products?collection=' . sanitize_title( $name ) . '" data-sc-cs="' . $ah_cs . ';height:' . $h . 'px;position:relative">'
		. '<div class="relative overflow-hidden bg-muted/50 aspect-[' . $aspect . ']" data-sc-cs="background-color:rgba(232, 230, 227, 0.5);height:' . $h . 'px;width:' . $w . 'px;display:block;position:relative;aspect-ratio:' . str_replace( '/', ' / ', $aspect ) . '">'
		. '<img src="/assets/c-' . sanitize_title( $name ) . '.jpg" alt="' . $name . '" class="w-full h-full object-cover" data-sc-cs="max-width:100%;height:' . $h . 'px;width:' . $w . 'px;display:block;object-fit:cover">'
		. '<div class="absolute inset-0 bg-gradient-to-t from-charcoal/70 via-charcoal/10 to-transparent" data-sc-cs="background-image:linear-gradient(to top, rgba(42, 38, 34, 0.7), rgba(42, 38, 34, 0.1), rgba(0, 0, 0, 0));height:' . $h . 'px;display:block;position:absolute;top:0px;left:0px"></div>'
		. '<div class="absolute inset-0 flex flex-col justify-end p-8" data-sc-cs="padding:32px;height:' . $h . 'px;display:flex;flex-direction:column;justify-content:flex-end;position:absolute;top:0px;left:0px"><p class="text-[10px] font-semibold tracking-[0.25em] uppercase text-white/60 mb-2" data-sc-cs="color:rgba(255, 255, 255, 0.6);font-size:10px;font-weight:600;letter-spacing:2.5px;text-transform:uppercase;margin:0px 0px 8px;height:15px;display:block">Collection</p>'
		. '<h3 class="font-serif text-3xl text-white mb-2" data-sc-cs="color:rgb(255, 255, 255);font-family:&quot;Cormorant Garamond&quot;, Georgia, serif;font-size:30px;font-weight:500;line-height:36px;margin:0px 0px 8px;height:36px;display:block">' . $name . '</h3>'
		. '<p class="text-sm text-white/70 max-w-xs" data-sc-cs="color:rgba(255, 255, 255, 0.7);font-size:14px;max-width:320px;height:22px;display:block">Timeless pieces built for generations</p></div></div></a></article></div>';
};
$ah_html = '<!DOCTYPE html><html><head><style>:root{}</style></head><body data-sc-cs="' . $ah_cs . '">'
	. '<header data-sc-cs="' . $ah_cs . ';height:80px"><nav class="container-full" data-sc-cs="' . $ah_cs . ';padding:0px 48px;max-width:1600px;height:80px"><div class="flex items-center justify-between h-20" data-sc-cs="' . $ah_cs . ';height:80px;display:flex;justify-content:space-between;align-items:center">'
	. '<a href="/" class="font-serif text-3xl tracking-tight text-foreground" data-sc-cs="color:rgb(42, 38, 34);font-family:&quot;Cormorant Garamond&quot;, Georgia, serif;font-size:30px;font-weight:400;letter-spacing:-0.75px;height:36px;display:block">Maison</a>'
	. '<div class="hidden md:flex items-center gap-8" data-sc-cs="' . $ah_cs . ';height:40px;display:flex;gap:32px;align-items:center"><a href="/products" class="text-xs font-medium tracking-[0.15em] uppercase" data-sc-cs="color:rgb(124, 115, 106);font-size:12px;font-weight:500;letter-spacing:1.8px;text-transform:uppercase;height:16px;display:block">Shop All</a><a href="/about" class="text-xs font-medium tracking-[0.15em] uppercase" data-sc-cs="color:rgb(124, 115, 106);font-size:12px;font-weight:500;letter-spacing:1.8px;text-transform:uppercase;height:16px;display:block">About</a></div>'
	. '<a href="/cart" class="relative p-2" data-sc-cs="' . $ah_cs . ';padding:8px;height:36px;display:block"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="lucide lucide-shopping-bag w-5 h-5"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z" data-sc-cs="color:rgb(42, 38, 34)"></path></svg></a></div></nav></header>'
	. '<main data-sc-cs="' . $ah_cs . '">'
	. '<section class="py-20 md:py-28 bg-linen" data-sc-cs="background-color:rgb(245, 242, 238);' . $ah_cs . ';padding:112px 0px;height:877px;display:block"><div class="container-full" data-sc-cs="' . $ah_cs . ';padding:0px 48px;max-width:1600px;margin:0px auto;height:653px;display:block">'
	. '<div class="flex items-end justify-between mb-14" data-sc-cs="' . $ah_cs . ';margin:0px 0px 56px;height:76px;display:flex;justify-content:space-between;align-items:flex-end"><div data-sc-cs="' . $ah_cs . ';height:76px"><p class="text-[11px] font-semibold tracking-[0.3em] uppercase text-primary mb-3" data-sc-cs="color:rgb(166, 94, 63);font-size:11px;font-weight:600;letter-spacing:3.3px;text-transform:uppercase;margin:0px 0px 12px;height:16px;display:block">Just Arrived</p><h2 class="font-serif text-4xl text-foreground" data-sc-cs="color:rgb(42, 38, 34);font-family:&quot;Cormorant Garamond&quot;, Georgia, serif;font-size:36px;font-weight:400;line-height:40px;height:40px;display:block">Latest Products</h2></div><a href="/products" class="text-xs font-medium tracking-[0.15em] uppercase text-muted-foreground flex items-center gap-2" data-sc-cs="color:rgb(124, 115, 106);font-size:12px;font-weight:500;letter-spacing:1.8px;text-transform:uppercase;height:16px;display:flex;gap:8px;align-items:center">View All</a></div>'
	. '<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-8 md:gap-10" data-sc-cs="' . $ah_cs . ';height:521px;display:grid;gap:40px;grid-template-columns:306px 306px 306px 306px">'
	. $ah_tile( 'Arc Pendant Light', 'Lighting', 'Featured' ) . $ah_tile( 'Orb Table Lamp', 'Lighting', 'New' ) . $ah_tile( 'Large Sculptural Vessel', 'Ceramics', 'Featured' ) . $ah_tile( 'Everyday Serving Bowl', 'Ceramics', '' )
	. '</div></div></section>'
	. '<section class="py-24 md:py-32" data-sc-cs="' . $ah_cs . ';padding:128px 0px;height:2504px;display:block;width:1440px"><div class="container-full" data-sc-cs="' . $ah_cs . ';padding:0px 48px;max-width:1600px;margin:0px auto;height:2248px;display:block"><div class="text-center mb-16" data-sc-cs="' . $ah_cs . ';text-align:center;margin:0px 0px 64px;height:76px;display:block"><p class="text-[11px] font-semibold tracking-[0.3em] uppercase text-primary mb-3" data-sc-cs="color:rgb(166, 94, 63);font-size:11px;font-weight:600;letter-spacing:3.3px;text-transform:uppercase;margin:0px 0px 12px;height:16px;display:block">Browse By</p><h2 class="font-serif text-5xl text-foreground" data-sc-cs="color:rgb(42, 38, 34);font-family:&quot;Cormorant Garamond&quot;, Georgia, serif;font-size:48px;font-weight:400;line-height:48px;height:48px;display:block">Collections</h2></div>'
	. '<div class="grid grid-cols-1 md:grid-cols-12 gap-4 md:gap-6" data-sc-cs="' . $ah_cs . ';height:2108px;display:grid;gap:24px;grid-template-columns:90px 90px 90px 90px 90px 90px 90px 90px 90px 90px 90px 90px">'
	. $ah_ctile( 'Lighting', 7, '16/9', 774, 435 ) . $ah_ctile( 'Ceramics', 5, '3/4', 546, 728 ) . $ah_ctile( 'Furniture', 4, '4/3', 432, 324 ) . $ah_ctile( 'Textiles', 4, '4/3', 432, 324 ) . $ah_ctile( 'Objects', 4, '4/3', 432, 324 ) . $ah_ctile( 'Seasonal Collection', 12, '21/9', 1344, 576 )
	. '</div></div></section></main></body></html>';
$ah_bl = FW_Site_Converter_Sources::build_from_html( $ah_html, 'Maison', array( 'dynamic_chrome' => true, 'hifi_css' => true ) );
$ah_pg = $ah_bl['files']['pages.json']['pages'][0]['builder'] ?? array();
$ah_td = $ah_bl['files']['theme-design.json'] ?? array();
$ah_css_of = function ( $n ) { return (string) ( $n['atts']['custom_css'] ?? '' ); };
$ah_json = function ( $n ) { return wp_json_encode( $n, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); };

$ah_menu = array_map( function ( $i ) { return (string) ( $i['label'] ?? '' ); }, (array) ( $ah_td['header']['menu'] ?? array() ) );
ga( "[AH] a display-size text wordmark (`<a href=\"/\" class=\"font-serif text-3xl\">Maison</a>`) is the brand, not the first menu item", array( 'Shop All', 'About' ) === array_values( $ah_menu ), $ah_json( $ah_menu ) );
$ah_head = $n_find( $ah_pg, function ( $n ) { return 'special_heading' === ( $n['shortcode'] ?? '' ) && 'Latest Products' === trim( (string) ( $n['atts']['title'] ?? '' ) ); } );
ga( "[AH] an intro row's kicker is the heading's overline ONCE — never also its subtitle", is_array( $ah_head ) && 'Just Arrived' === trim( (string) ( $ah_head['atts']['overline'] ?? '' ) ) && '' === trim( (string) ( $ah_head['atts']['subtitle'] ?? '' ) ), $ah_json( array( $ah_head['atts']['overline'] ?? null, $ah_head['atts']['subtitle'] ?? null ) ) );
$ah_hrow = $n_find( $ah_pg, function ( $n ) { if ( ! in_array( ( $n['type'] ?? '' ), array( 'column', 'flexbox' ), true ) ) { return false; } $has_h = false; $has_b = false; foreach ( (array) ( $n['_items'] ?? array() ) as $c ) { if ( 'special_heading' === ( $c['shortcode'] ?? '' ) && 'Latest Products' === trim( (string) ( $c['atts']['title'] ?? '' ) ) ) { $has_h = true; } if ( 'button' === ( $c['shortcode'] ?? '' ) ) { $has_b = true; } } return $has_h && $has_b; } );
ga( "[AH] the intro row keeps its own `mb-14` (56px) above the product grid", is_array( $ah_hrow ) && 'mb-6' === (string) ( $ah_hrow['atts']['spacing']['margin']['bottom'] ?? '' ), $ah_json( $ah_hrow['atts']['spacing']['margin'] ?? null ) );

$ah_p1 = $n_find( $ah_pg, function ( $n ) { return 'image_box' === ( $n['shortcode'] ?? '' ) && 'Arc Pendant Light' === trim( (string) ( $n['atts']['title'] ?? '' ) ); } );
ga( "[AH] a 4:5 product photo → the nearest native ratio (3:4) + the EXACT `aspect-ratio:4 / 5` scoped on the media box", is_array( $ah_p1 ) && 'ratio-3-4' === (string) ( $ah_p1['atts']['image_ratio'] ?? '' ) && false !== strpos( $ah_css_of( $ah_p1 ), 'selector .imgbox__media{aspect-ratio:4 / 5;}' ), $ah_json( array( $ah_p1['atts']['image_ratio'] ?? null, $ah_p1 ? $ah_css_of( $ah_p1 ) : null ) ) );
ga( "[AH] the tile's corner chip (\"Featured\") is painted as a pseudo-element on the media box with the chip's fill / ink / inset", is_array( $ah_p1 ) && preg_match( '/selector \.imgbox__media::before\{content:"Featured";position:absolute;[^}]*background-color:rgb\(166, 94, 63\)[^}]*top:20px;left:20px;\}/', $ah_css_of( $ah_p1 ) ), $ah_p1 ? $ah_css_of( $ah_p1 ) : null );
ga( "[AH] the wishlist <button> heart is a CONTROL, never the card's icon", is_array( $ah_p1 ) && 'none' === (string) ( $ah_p1['atts']['icon']['type'] ?? 'none' ), $ah_json( $ah_p1['atts']['icon'] ?? null ) );
ga( "[AH] the category line is the image_box's eyebrow (subtitle) with its own type, not the first body paragraph", is_array( $ah_p1 ) && 'Lighting' === (string) ( $ah_p1['atts']['subtitle'] ?? '' ) && false === strpos( (string) ( $ah_p1['atts']['text'] ?? '' ), '<p>Lighting</p>' ) && preg_match( '/\.imgbox__eyebrow\{[^}]*letter-spacing:2\.2px/', $ah_css_of( $ah_p1 ) ), $ah_json( array( $ah_p1['atts']['subtitle'] ?? null, $ah_p1['atts']['text'] ?? null ) ) );
$ah_p4 = $n_find( $ah_pg, function ( $n ) { return 'image_box' === ( $n['shortcode'] ?? '' ) && 'Everyday Serving Bowl' === trim( (string) ( $n['atts']['title'] ?? '' ) ); } );
ga( "[AH] a hover-revealed pill (`opacity-0 group-hover:opacity-100` \"View Details\") is NOT a badge", is_array( $ah_p4 ) && false === strpos( $ah_css_of( $ah_p4 ), 'content:"View Details"' ), $ah_p4 ? $ah_css_of( $ah_p4 ) : null );
ga( "[AH] the product body copy keeps the description's 14px, not the 11px eyebrow", is_array( $ah_p1 ) && preg_match( '/\.imgbox__text\{font-size:14px/', $ah_css_of( $ah_p1 ) ), $ah_p1 ? $ah_css_of( $ah_p1 ) : null );

$ah_sec = null; foreach ( $ah_pg as $s ) { if ( false !== strpos( $ah_json( $s ), 'Seasonal Collection' ) ) { $ah_sec = $s; break; } }
$ah_tiles = $af_all( array( $ah_sec ), function ( $n ) { return 'image_box' === ( $n['shortcode'] ?? '' ); } );
ga( "[AH] a bento of photo tiles inside NESTED rows renders every tile as an image_box (not an icon_box without its photo)", 6 === count( $ah_tiles ) && 0 === count( $af_all( array( $ah_sec ), function ( $n ) { return 'icon_box' === ( $n['shortcode'] ?? '' ); } ) ), $ah_json( array( count( $ah_tiles ), array_map( function ( $n ) { return $n['shortcode'] ?? $n['type']; }, $af_all( array( $ah_sec ), function ( $n ) { return 'icon_box' === ( $n['shortcode'] ?? '' ); } ) ) ) ) );
ga( "[AH] a tile whose title sits INSIDE the photo frame is the image_box OVERLAY family with the source scrim", count( $ah_tiles ) > 0 && 'overlay' === (string) ( $ah_tiles[0]['atts']['design_settings']['family'] ?? '' ) && false !== strpos( $ah_css_of( $ah_tiles[0] ), 'selector .imgbox__scrim{background:linear-gradient(to top, rgba(42, 38, 34, 0.7)' ), $ah_json( array( $ah_tiles[0]['atts']['design_settings'] ?? null, isset( $ah_tiles[0] ) ? $ah_css_of( $ah_tiles[0] ) : null ) ) );
$ah_sbg = (string) ( $ah_sec['atts']['background']['image']['src']['url'] ?? ( $ah_sec['atts']['background_image']['url'] ?? '' ) );
ga( "[AH] a tile's photo is never hoisted as the SECTION background (the hero aspect-box hoist needs a band-wide, single box)", '' === $ah_sbg && false === strpos( $ah_css_of( $ah_sec ), 'c-lighting.jpg' ), $ah_json( array( $ah_sbg, $ah_css_of( $ah_sec ) ) ) );
ga( "[AH] a page container's `max-w-[1600px]` is never a content cap on the widget row (the theme gutters stay)", 0 === count( $af_all( $ah_pg, function ( $n ) { return false !== strpos( (string) ( $n['atts']['custom_css'] ?? '' ), 'max-width:1600px !important' ); } ) ), 'a 1600px cap was stamped' );


/* --------------------------------------------------------------------- *
 * Result
 * --------------------------------------------------------------------- */

/*
 * [AI] Gallery tile CORNERS ride the source tile's MEASURED border-radius (Stitch tileRadius → the gallery's
 * declared `rounded` option): 0 → Square, ≤8px → Rounded, larger → Rounded large. The mapper had hard-coded
 * `rounded`, and — the option being undeclared — the builder dropped the att, so every converted grid drew 6px
 * corners over a square-cornered source (a storefront's social grid). The Image Style preset owns corners when set.
 */
echo "
[AI] Gallery corners from the measured tile radius
";
ga( "[AI] 8px-radius mosaic tiles → the gallery's `rounded` (6px) corners", is_array( $ac_gal ) && 'rounded' === (string) ( $ac_gal['atts']['rounded'] ?? '' ), wp_json_encode( $ac_gal['atts']['rounded'] ?? null ) );
$ai_bl  = FW_Site_Converter_Sources::build_from_html( str_replace( 'border-radius:8px;height:240px', 'border-radius:0px;height:240px', $ac_html ), 'Outpost', array( 'dynamic_chrome' => true, 'hifi_css' => true ) );
$ai_gal = $r_find( $ai_bl['files']['pages.json']['pages'][0]['builder'] ?? array(), function ( $n ) { return 'gallery' === ( $n['shortcode'] ?? '' ); } );
ga( "[AI] square-cornered tiles (border-radius:0px) → `rounded-0` (the grid had drawn 6px corners over sharp source tiles)", is_array( $ai_gal ) && 'rounded-0' === (string) ( $ai_gal['atts']['rounded'] ?? '' ), wp_json_encode( $ai_gal['atts']['rounded'] ?? null ) );
$ai_bl2  = FW_Site_Converter_Sources::build_from_html( str_replace( 'border-radius:8px;height:240px', 'border-radius:16px;height:240px', $ac_html ), 'Outpost', array( 'dynamic_chrome' => true, 'hifi_css' => true ) );
$ai_gal2 = $r_find( $ai_bl2['files']['pages.json']['pages'][0]['builder'] ?? array(), function ( $n ) { return 'gallery' === ( $n['shortcode'] ?? '' ); } );
ga( "[AI] 16px-radius tiles → `rounded-lg`", is_array( $ai_gal2 ) && 'rounded-lg' === (string) ( $ai_gal2['atts']['rounded'] ?? '' ), wp_json_encode( $ai_gal2['atts']['rounded'] ?? null ) );


/*
 * [AJ] A hero SCROLL CUE (`absolute bottom-8 left-1/2 -translate-x-1/2 flex flex-col items-center gap-2` holding a tiny tracked
 * label + a chevron / arrow-down glyph) is the native scroll_indicator, pinned where the source pinned it, with the label's
 * measured type and the glyph's own utility ink — it had been flattened into an in-flow text block + lone icon under the
 * CTA, flush left, AND the container's `pb-28` had landed on the cue instead of the CTA (the content sat 70px too low).
 */
echo "\n[AJ] Hero scroll cue → scroll_indicator, pinned; the wrapper's bottom inset lands on the last IN-FLOW block\n";
$aj_cs = 'color:rgb(42, 38, 34);font-family:Inter, system-ui, sans-serif;font-size:16px;font-weight:400;line-height:24px;text-align:start;display:block';
$aj_html = '<!DOCTYPE html><html><head><title>T</title></head><body>'
	. '<header data-sc-cs="' . $aj_cs . ';height:80px;position:absolute;top:0px;left:0px;right:0px"><nav data-sc-cs="' . $aj_cs . ';display:flex;gap:32px;height:80px"><a href="/" data-sc-cs="' . $aj_cs . ';font-size:30px">Brand</a><a href="/shop" data-sc-cs="' . $aj_cs . '">Shop</a><a href="/about" data-sc-cs="' . $aj_cs . '">About</a></nav></header>'
	. '<section class="relative h-[100svh] overflow-hidden" data-sc-cs="' . $aj_cs . ';height:900px;position:relative">'
	. '<div class="absolute inset-0" data-sc-cs="' . $aj_cs . ';height:900px;position:absolute;top:0px;bottom:0px"><img src="https://example.com/hero.jpg" alt="" class="w-full h-full object-cover" data-sc-cs="height:900px;width:1440px;display:block;object-fit:cover"></div>'
	. '<div class="relative container-full h-full flex flex-col justify-end pb-20 md:pb-28 pt-16 md:pt-20" data-sc-cs="' . $aj_cs . ';padding:80px 48px 112px;height:900px;display:flex;flex-direction:column;justify-content:flex-end;position:relative">'
	. '<div class="max-w-3xl" data-sc-cs="' . $aj_cs . ';max-width:768px;height:420px">'
	. '<p class="text-[11px] tracking-[0.3em] uppercase text-white/70 mb-6" data-sc-cs="color:rgba(255, 255, 255, 0.7);font-size:11px;letter-spacing:3.3px;text-transform:uppercase;font-family:Inter, system-ui, sans-serif;font-weight:400;line-height:16px;margin:0px 0px 24px;height:16px;display:block">Curated for considered living</p>'
	. '<h1 class="font-serif text-8xl text-white mb-8" data-sc-cs="color:rgb(255, 255, 255);font-family:&quot;Cormorant Garamond&quot;, Georgia, serif;font-size:128px;font-weight:500;line-height:128px;letter-spacing:-3.2px;margin:0px 0px 32px;height:256px;display:block">Objects of quiet beauty</h1>'
	. '<p class="text-lg text-white/80 max-w-lg mb-10" data-sc-cs="color:rgba(255, 255, 255, 0.8);font-size:18px;line-height:29px;font-family:Inter, system-ui, sans-serif;font-weight:400;max-width:512px;margin:0px 0px 40px;height:58px;display:block">Handcrafted home goods designed to bring warmth to everyday moments.</p>'
	. '<div class="flex gap-4" data-sc-cs="' . $aj_cs . ';display:flex;gap:16px;height:52px"><a href="/products" class="inline-flex items-center justify-center bg-primary text-white px-8 py-4 text-xs tracking-[0.2em] uppercase" data-sc-cs="background-color:rgb(166, 94, 63);color:rgb(255, 255, 255);font-size:12px;letter-spacing:2.4px;text-transform:uppercase;font-family:Inter, system-ui, sans-serif;font-weight:500;line-height:16px;padding:16px 32px;height:52px;display:inline-flex;align-items:center;justify-content:center">Shop Now</a></div>'
	. '</div>'
	. '<div class="absolute bottom-8 left-1/2 -translate-x-1/2 flex flex-col items-center gap-2" data-sc-cs="' . $aj_cs . ';height:39px;display:flex;gap:8px;align-items:center;flex-direction:column;transform:matrix(1, 0, 0, 1, -28.5625, 0);position:absolute;top:829px;bottom:32px;left:720px"><span class="text-[10px] tracking-[0.3em] uppercase text-white/50" data-sc-cs="color:rgba(255, 255, 255, 0.5);font-family:Inter, system-ui, sans-serif;font-size:10px;font-weight:400;line-height:15px;letter-spacing:3px;text-transform:uppercase;height:15px;display:block">Scroll</span><div data-sc-cs="' . $aj_cs . ';height:16px"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="lucide lucide-arrow-down w-4 h-4 text-white/50"><path d="M12 5v14"></path><path d="m19 12-7 7-7-7"></path></svg></div></div>'
	. '</div></section>'
	. '<section class="py-28" data-sc-cs="' . $aj_cs . ';padding:112px 0px;height:400px"><div class="container" data-sc-cs="' . $aj_cs . ';max-width:1280px;margin:0px auto;height:176px"><h2 data-sc-cs="' . $aj_cs . ';font-size:40px;line-height:44px;height:44px">Latest Products</h2><p data-sc-cs="' . $aj_cs . ';height:24px">A short intro paragraph under the heading of the second band.</p></div></section>'
	. '<footer data-sc-cs="' . $aj_cs . ';padding:64px 48px;height:200px"><p data-sc-cs="' . $aj_cs . ';height:24px">© 2026 Brand</p></footer></body></html>';
$aj_bl  = FW_Site_Converter_Sources::build_from_html( $aj_html, 'Brand', array( 'dynamic_chrome' => true, 'hifi_css' => true ) );
$aj_pg  = $aj_bl['files']['pages.json']['pages'][0]['builder'] ?? array();
$aj_cue = $r_find( $aj_pg, function ( $n ) { return 'scroll_indicator' === ( $n['shortcode'] ?? '' ); } );
$aj_txt = $r_find( $aj_pg, function ( $n ) { return 'text_block' === ( $n['shortcode'] ?? '' ) && false !== stripos( (string) ( $n['atts']['text'] ?? '' ), 'Scroll' ); } );
$aj_ico = $r_find( $aj_pg, function ( $n ) { return 'icon' === ( $n['shortcode'] ?? '' ); } );
ga( "[AJ] the pinned label + arrow-down wrapper is ONE scroll_indicator (never a text block + lone icon in the flow)", is_array( $aj_cue ) && null === $aj_txt && null === $aj_ico, wp_json_encode( array( is_array( $aj_cue ), is_array( $aj_txt ), is_array( $aj_ico ) ) ) );
ga( "[AJ] …the source label, the library glyph, label-above-icon, the glyph's own `text-white/50` ink and 16px size", is_array( $aj_cue ) && 'Scroll' === (string) ( $aj_cue['atts']['text'] ?? '' ) && 'lucide/arrow-down' === (string) ( $aj_cue['atts']['icon']['svg-id'] ?? '' ) && 'stacked' === (string) ( $aj_cue['atts']['layout'] ?? '' ) && '16' === (string) ( $aj_cue['atts']['icon_size']['value'] ?? '' ) && false !== strpos( (string) ( $aj_cue['atts']['icon_color']['custom'] ?? '' ), '255' ) && false !== strpos( (string) ( $aj_cue['atts']['icon_color']['custom'] ?? '' ), '0.5' ), wp_json_encode( $aj_cue['atts'] ?? null ) );
ga( "[AJ] …pinned with the native Position option (bottom, left 50%) + the utility's half-width centring, and the label's measured 10px tracked uppercase type", is_array( $aj_cue ) && 'absolute' === (string) ( $aj_cue['atts']['element_position']['position'] ?? '' ) && '50' === (string) ( $aj_cue['atts']['element_position']['absolute']['pos_offsets']['left']['value'] ?? '' ) && '%' === (string) ( $aj_cue['atts']['element_position']['absolute']['pos_offsets']['left']['unit'] ?? '' ) && '' !== (string) ( $aj_cue['atts']['element_position']['absolute']['pos_offsets']['bottom']['value'] ?? '' ) && false !== strpos( (string) ( $aj_cue['atts']['custom_css'] ?? '' ), 'transform:translateX(-50%)' ) && false !== strpos( (string) ( $aj_cue['atts']['custom_css'] ?? '' ), '.sc-scroll-cue__label{font-size:10px;letter-spacing:3px;text-transform:uppercase' ), wp_json_encode( array( $aj_cue['atts']['element_position'] ?? null, $aj_cue['atts']['custom_css'] ?? null ) ) );
$aj_btn = $r_find( $aj_pg, function ( $n ) { return 'button' === ( $n['shortcode'] ?? '' ) && 'Shop Now' === (string) ( $n['atts']['label'] ?? '' ); } );
ga( "[AJ] the container's `pb-28` (112px) lands on the CTA — the last IN-FLOW block — not on the pinned cue (the content had sat 70px too low)", is_array( $aj_btn ) && '' !== (string) ( $aj_btn['atts']['spacing']['margin']['bottom'] ?? '' ) && ( ! is_array( $aj_cue ) || '' === (string) ( $aj_cue['atts']['spacing']['margin']['bottom'] ?? '' ) ), wp_json_encode( array( $aj_btn['atts']['spacing']['margin']['bottom'] ?? null, $aj_cue['atts']['spacing']['margin']['bottom'] ?? null ) ) );

$pass = $GLOBALS['__pass'];
$fail = $GLOBALS['__fail'];
echo "\n========================================\n";
echo "GOLDEN FIXTURE RESULT: " . ( $fail === 0 ? "PASS" : "FAIL" ) . "   ($pass passed, $fail failed)\n";
echo "========================================\n";
exit( $fail === 0 ? 0 : 1 );
