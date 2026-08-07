<?php
/**
 * Golden-fixture regression guard for the Site Converter deterministic (no-AI) path.
 *
 * Runs FW_Site_Converter_Sources::build_from_html() over the FreshPaws capture
 * (tests/fixtures/freshpaws.html) and ASSERTS the current known-good output:
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
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/freshpaws-golden-test.php"
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
$fixture = __DIR__ . '/fixtures/freshpaws.html';
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
ga_eq( "hero shortcodes",
	array( 'badge', 'special_heading', 'text_block', 'button', 'button', 'avatar', 'code_block', 'media_image', 'icon_box' ),
	$by_id['hero'] ?? array() );
ga( "hero right column decomposed → a native media_image", in_array( 'media_image', $by_id['hero'] ?? array(), true ) );
ga( "hero right column decomposed → an editable icon_box (floating badge)", in_array( 'icon_box', $by_id['hero'] ?? array(), true ) );
ga_eq( "features shortcodes",
	array( 'special_heading', 'icon_box', 'icon_box', 'icon_box' ),
	$by_id['features'] ?? array() );
ga_eq( "cta shortcodes",
	array( 'special_heading', 'text_block', 'button' ),
	$by_id['cta'] ?? array() );

// Spot-guards for the specific surfaces the audit called out (avatar present; icon_box x3; 2 hero buttons)
ga( "hero contains an avatar (avatar-group survives)", in_array( 'avatar', $by_id['hero'] ?? array(), true ) );
ga( "features has exactly 3 icon_box", 3 === count( array_filter( $by_id['features'] ?? array(), function ( $c ) { return $c === 'icon_box'; } ) ) );
ga( "hero has 2 buttons", 2 === count( array_filter( $by_id['hero'] ?? array(), function ( $c ) { return $c === 'button'; } ) ) );

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
ga_eq( "header_logo icon frame (squircle)", 'squircle', $logo_custom['logo_icon_frame'] ?? null );
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

// Contact column (col 4) = a native leading-icon list (map-pin/phone/mail), each tinted the brand green and
// with the address line-break preserved — NOT the flattened text blob that glued "Lane" to "Springfield".
$fcols4 = $ts['main_footer_columns']['4'] ?? array();
$contact_html = '';
foreach ( $fcols4 as $ck => $col ) {
	if ( strpos( (string) $ck, 'main_footer_col_' ) !== 0 || ! is_array( $col ) ) { continue; }
	$h = $col[0]['element_type']['text']['text_content'] ?? '';
	if ( strpos( $h, 'Contact Info' ) !== false ) { $contact_html = $h; break; }
}
ga( "footer contact column found", $contact_html !== '' );
ga( "footer contact = native icon list (fw-footer-contact)", strpos( $contact_html, 'fw-footer-contact' ) !== false );
ga_eq( "footer contact carries 3 leading icon rows", 3, substr_count( $contact_html, 'fw-ci-icon' ) );
ga( "footer contact leading icons tinted brand green", substr_count( $contact_html, 'color:rgb(33, 196, 93)' ) >= 3, $contact_html );
ga( "footer contact map-pin icon present", strpos( $contact_html, 'lucide-map-pin' ) !== false );
ga( "footer contact address line-break preserved (not glued)", strpos( $contact_html, 'Fresh Meadow Lane<br>Springfield' ) !== false && strpos( $contact_html, 'LaneSpringfield' ) === false );

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

ga( "brand green (rgb(33, 196, 93)) present in CSS", strpos( $css, 'rgb(33, 196, 93)' ) !== false );
ga( "typography: Nunito (headings) present in CSS", strpos( $css, 'Nunito' ) !== false );
ga( "typography: Inter (body) present in CSS", strpos( $css, 'Inter' ) !== false );
ga( "heading scale: #features h2 rule present", strpos( $css, '#features h2' ) !== false );
ga( "heading scale: #cta h2 rule present", strpos( $css, '#cta h2' ) !== false );

// Button presets: 3 mapped button classes, at least one with a :hover state.
$btn_classes = array( '.sc-btn-primary', '.sc-btn-primary-2', '.sc-btn-primary-3' );
$btn_ok = true;
foreach ( $btn_classes as $bc ) { if ( strpos( $css, $bc ) === false ) { $btn_ok = false; } }
ga( "button presets: 3 mapped button classes present", $btn_ok );
ga( "button presets: a :hover state exists", strpos( $css, ':hover' ) !== false );

/* --------------------------------------------------------------------- *
 * 5) MAPPING-LEVEL section ids match builder (both id paths agree)
 * --------------------------------------------------------------------- */
echo "\n[5] Stitch/Mapper id paths agree\n";
$m_ids = array();
foreach ( ( $mapping['pages'][0]['sections'] ?? array() ) as $s ) { $m_ids[] = $s['css_id'] ?? ''; }
ga_eq( "mapping css_ids == builder css_ids", $cids, $m_ids );

/* --------------------------------------------------------------------- *
 * 6) NEW RECOGNIZERS — per-recognizer synthetic fixtures (FreshPaws has no
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

/* --- (b) Icon-chip capture: the FreshPaws features cards (built above with dynamic_chrome=true, so
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
	ga( "feature icon_box carries a badge chip (from the source icon container)", ! empty( $feat_ibs[0]['atts']['icon_badge'] ), $feat_ibs[0]['atts']['icon_badge'] ?? '(none)' );
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
ga( "floating card icon chip → icon_badge (from the source chip)", ( $ib['atts']['icon_badge'] ?? '' ) === 'solid-circle', $ib['atts']['icon_badge'] ?? '' );

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
$b_res  = $find_btn( 'Reserve a Spot Now' );
ga( "hero 'Book a Stay' button found", $b_book !== null );
ga( "hero 'Take a Tour' button found", $b_tour !== null );
ga( "cta 'Reserve a Spot Now' button found", $b_res !== null );

// green bg-primary filled → the Primary color preset (btn-primary); its size (fs18/px32) → Medium.
ga_eq( "green primary button → style btn-primary", 'btn-primary', $b_book['atts']['style'] ?? null );
ga_eq( "green primary button → size btn-md", 'btn-md', $b_book['atts']['size'] ?? null );
// bg-white + border outline → the Outline preset (btn-outline).
ga_eq( "bg-white border outline button → style btn-outline", 'btn-outline', $b_tour['atts']['style'] ?? null );
ga_eq( "outline button → size btn-md", 'btn-md', $b_tour['atts']['size'] ?? null );
// amber bg-secondary → the Secondary preset (btn-secondary); largest (fs20/px40) → the Large size preset.
ga_eq( "amber secondary button → style btn-secondary", 'btn-secondary', $b_res['atts']['style'] ?? null );
ga_eq( "amber secondary (largest) button → size btn-lg", 'btn-lg', $b_res['atts']['size'] ?? null );
// The exact per-node safety net is still present (preset is ADDITIVE, not a replacement): the source's
// fill/padding is compiled into a scoped `.sc-btn-*` css_class alongside the newly-attached preset slug.
ga( "button preset is additive — per-node sc-btn-* css_class safety net retained", strpos( (string) ( $b_res['atts']['css_class'] ?? '' ), 'sc-btn' ) !== false, $b_res['atts']['css_class'] ?? '' );

// (b) The hero pill: bg-primary/10 tint (rgba) + a leading inline heart svg.
$pill = null;
foreach ( $all_nodes as $e ) { if ( ( $e['node']['shortcode'] ?? '' ) === 'badge' ) { $pill = $e['node']; break; } }
ga( "hero pill (badge) found", $pill !== null );
ga_eq( "hero pill message survives", 'Voted #1 Pet Boarding in Springfield', $pill['atts']['message'] ?? null );
ga_eq( "hero pill fill from bg-primary/10 → pill_color rgba(33,196,93,.1)", 'rgba(33, 196, 93, 0.1)', $pill['atts']['pill_color']['custom'] ?? null );
ga_eq( "hero pill leading marker = icon", 'icon', $pill['atts']['leading'] ?? null );
ga_eq( "hero pill leading_icon is an inline svg", 'svg', $pill['atts']['leading_icon']['type'] ?? null );
ga( "hero pill leading_icon carries the heart svg markup", strpos( (string) ( $pill['atts']['leading_icon']['markup'] ?? '' ), '<svg' ) !== false );

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

/* --------------------------------------------------------------------- *
 * Result
 * --------------------------------------------------------------------- */
$pass = $GLOBALS['__pass'];
$fail = $GLOBALS['__fail'];
echo "\n========================================\n";
echo "GOLDEN FIXTURE RESULT: " . ( $fail === 0 ? "PASS" : "FAIL" ) . "   ($pass passed, $fail failed)\n";
echo "========================================\n";
exit( $fail === 0 ? 0 : 1 );
