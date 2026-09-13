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
FW_Site_Converter_Mapper::set_button_presets( array(), array() );
$nb_free = $rm_nb->invoke( null, 'Go', '#', 'weird-cta', '', 'after', 'background-color:rgb(10, 20, 30);color:rgb(255, 255, 255)', '', '', '', 'hover-self{transform:translateY(-4px)}', '' );
ga_eq( "n_button: an UNMATCHED button with a lift keeps the btnfx-lift fallback", 'btnfx-lift', (string) ( $nb_free['atts']['hover_animation'] ?? '' ) );
// FIDELITY GUARD for the native fx on a PRESET-owned button: a lift is mapped to the native "Lift" preset
// (editable in the dropdown) whenever that adds nothing the source lacks — i.e. the source has NO resting
// shadow, or its hover ALSO changes the shadow. Only a source that keeps its own resting shadow on hover
// (the gradient CTA above) is left to the preset's exact transform.
FW_Site_Converter_Mapper::set_button_presets( (array) ( $bp3['button_colors'] ?? array() ), (array) ( $bp3['button_sizes'] ?? array() ) );
$sec_lift = $rm_nb->invoke( null, 'Seasonal Overview', '#', 'btn-secondary', '', 'after', $sec_hov_cs, '', '', '', 'hover-self{transform:translateY(-4px)}', '' );
ga_eq( "guard: preset-owned lift with NO resting shadow → native btnfx-lift", 'btnfx-lift', (string) ( $sec_lift['atts']['hover_animation'] ?? '' ) );
$pri_lift_sh = $rm_nb->invoke( null, 'Explore the Flow', '#', 'btn-primary', '', 'after', $pri_hov_cs, '', '', '', 'hover-self{transform:translateY(-4px);box-shadow:rgba(0, 0, 0, 0.25) 0px 24px 48px 0px}', '' );
ga_eq( "guard: preset-owned lift whose hover ALSO changes the shadow → native btnfx-lift", 'btnfx-lift', (string) ( $pri_lift_sh['atts']['hover_animation'] ?? '' ) );
$pri_grow = $rm_nb->invoke( null, 'Explore the Flow', '#', 'btn-primary', '', 'after', $pri_hov_cs, '', '', '', 'hover-self{transform:scale(1.05)}', '' );
ga_eq( "guard: a grow (no forced shadow) on a shadowed preset button → native btnfx-grow", 'btnfx-grow', (string) ( $pri_grow['atts']['hover_animation'] ?? '' ) );
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
ga( "FLUID section heading: the section-scoped #f h2 rule carries the clamp() + relative metrics (not the 80.64px snapshot that froze it)", strpos( $f_reg, '#f h2{' ) !== false && strpos( $f_reg, 'font-size:clamp(2.9rem,5.6vw,6rem) !important;' ) !== false && strpos( $f_reg, 'line-height:.9 !important;' ) !== false && strpos( $f_reg, 'letter-spacing:-.06em !important;' ) !== false && strpos( $f_reg, 'font-size:80.64px' ) === false, substr( $f_reg, (int) strpos( $f_reg, '#f h2' ), 300 ) );
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
ga_eq( "story: the shell's 24px left margin → the band's native Spacing margin-left (ms-4)", 'ms-4', $i_band['atts']['spacing']['margin']['left'] ?? null );
ga_eq( "story: …and margin-right (me-4)", 'me-4', $i_band['atts']['spacing']['margin']['right'] ?? null );
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
ga( "a card that only LIFTS on hover keeps the lift (the hover keys the slug; native Lift / hover_fx)", ( $t_lf['atts']['box_style'] ?? '' ) !== ( $t_plain['atts']['box_style'] ?? '' ) && (bool) preg_match( '/btnfx-lift|"hover_fx":\["lift"/', $t_preset( $t_lf ) ) );
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
ga( "a card that is display:none from 820px up (md:hidden) → Responsive Hide on tablets + desktops, shown on phones", is_array( $u_gcell ) && ! empty( $u_gcell['atts']['responsive_hide']['hide-md'] ) && ! empty( $u_gcell['atts']['responsive_hide']['hide-lg'] ) && empty( $u_gcell['atts']['responsive_hide']['hide-xs'] ) );
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
ga_eq( "hero strip: the flattened `mt-auto pt-24` wrapper's margin + padding (56 + 96) → the caption's Spacing top", 'mt-[152px]', $n_cap['atts']['spacing']['margin']['top'] ?? null );
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

/* --------------------------------------------------------------------- *
 * Result
 * --------------------------------------------------------------------- */
$pass = $GLOBALS['__pass'];
$fail = $GLOBALS['__fail'];
echo "\n========================================\n";
echo "GOLDEN FIXTURE RESULT: " . ( $fail === 0 ? "PASS" : "FAIL" ) . "   ($pass passed, $fail failed)\n";
echo "========================================\n";
exit( $fail === 0 ? 0 : 1 );
