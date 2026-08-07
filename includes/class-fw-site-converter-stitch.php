<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Site Converter — Google Stitch ingest engine.
 *
 * Turns a Google Stitch export (the `.zip` / "Code to Clipboard" output) into the
 * SAME bundle shape the rest of the converter already imports — so a Stitch screen
 * becomes a native UnysonPlus child theme + page-builder Full Page WITHOUT any LLM.
 *
 * Why Stitch is a first-class, deterministic input (vs. a generic scraped site):
 *  - Design tokens are HANDED to us in an inline `tailwind.config` JSON (colors,
 *    fontFamily, fontSize, spacing, borderRadius) — and/or a sibling `DESIGN.md`
 *    YAML frontmatter — so there's nothing to reverse-engineer from a render.
 *  - Sections are explicitly labelled (`<!-- Hero Section -->`) and use clean
 *    semantic tags (`<section>`/`<header>`/`<footer>`/`<h1..6>`/`<p>`/`<button>`/`<img>`).
 *  - Intent lives in the utility classes: `md:col-span-8` → a column width, a
 *    `rounded-full … uppercase` pill → an overline, `material-symbols-outlined` →
 *    an icon, `<ul><li>` with check icons → an icon list.
 *
 * Pipeline (all offline):
 *    code.html (+ DESIGN.md)
 *      → parse_tokens()          design tokens
 *      → tokens_to_design_config()  → theme-design.json — the bundle's theme phase generates a
 *                                     CHILD THEME (palette, fonts, header/footer chrome) which the
 *                                     admin then ACTIVATES, so it's a one-step "upload .zip → done".
 *      → scan_images()           → media.json
 *      → html_to_mapping()       → the Mapper's role-annotated mapping → build_pages() → pages.json
 *    → build_bundle() assembles the files; the admin imports them via
 *      FW_Site_Converter_Bundle::import_dir() (Tier 1, no AI) and activates the generated theme, or
 *      streams the bundle as a `.zip` for the user to refine pages.json with Claude (Tier 2, advanced).
 *      (Menus are NOT a separate file — the design-config's header.menu / footer.menu are built into
 *      real WP menus by the generated theme's activation bootstrap.)
 *
 * Self-learning (Tier 3, privacy-safe — NO telemetry, nothing leaves the machine):
 *  - rules_get()/rules_put() persist a LOCAL `class-pattern → role/shortcode` store
 *    (wp_option) consulted before the built-in mapping tables, so corrections and
 *    Claude-assisted runs make the no-AI path better on THIS install.
 *  - distill_from_ai() diffs a Claude-authored pages.json against the deterministic
 *    draft and records the deltas as rules. Improvements ship to other installs only
 *    via the maintainer's curated GitHub release — never by harvesting user data.
 *
 * Static helpers (mirrors the other engines) so a WP-CLI command / the bundle path
 * can reuse it.
 */
class FW_Site_Converter_Stitch {

	/** Local, per-install learned rules (signature/class-pattern → role). NOT transmitted anywhere. */
	const RULES_OPTION = 'fw_site_converter_stitch_rules';

	/* ---------------------------------------------------------------------- *
	 * Source detection (the file-upload "auto-detect" — is this a Stitch export?)
	 * ---------------------------------------------------------------------- */

	/**
	 * Confidence (0..1) that an export FOLDER is a Google Stitch export. The unified Convert flow's
	 * detector (`FW_Site_Converter_Sources`) calls this; the highest-scoring adapter wins.
	 *
	 * @param string $dir
	 * @return float
	 */
	public static function detect_dir( $dir ) {
		if ( ! is_string( $dir ) || ! is_dir( $dir ) ) { return 0.0; }
		$dir  = rtrim( $dir, '/\\' );
		$html = '';
		if ( is_file( $dir . '/code.html' ) ) {
			$html = (string) file_get_contents( $dir . '/code.html' );
		} else {
			$g = glob( $dir . '/*/code.html' );
			if ( $g ) { $html = (string) file_get_contents( $g[0] ); }
		}
		$has_design_md = is_file( $dir . '/DESIGN.md' ) || glob( $dir . '/*/DESIGN.md' ) || glob( $dir . '/DESIGN.md' );
		return self::detect_html( $html, (bool) $has_design_md );
	}

	/**
	 * Confidence (0..1) that a single `code.html` is a Google Stitch screen. Stitch fingerprints: an
	 * inline `tailwind.config`, Google's `aida-public` image CDN, the Material Symbols icon font, and
	 * (for a folder) a sibling DESIGN.md. Two or more → almost certainly Stitch.
	 *
	 * @param string $html
	 * @param bool   $has_design_md
	 * @return float
	 */
	public static function detect_html( $html, $has_design_md = false ) {
		$html = (string) $html;
		if ( trim( $html ) === '' ) { return 0.0; }
		$score = 0;
		if ( stripos( $html, 'tailwind.config' ) !== false ) { $score += 2; } // the strongest signal
		if ( stripos( $html, 'lh3.googleusercontent.com/aida-public' ) !== false ) { $score += 2; }
		if ( stripos( $html, 'Material+Symbols' ) !== false ) { $score += 1; }
		if ( stripos( $html, 'cdn.tailwindcss.com' ) !== false ) { $score += 1; }
		if ( $has_design_md ) { $score += 2; }
		return min( 1.0, $score / 4 );
	}

	/* ---------------------------------------------------------------------- *
	 * Design tokens
	 * ---------------------------------------------------------------------- */

	/**
	 * Extract Stitch design tokens from a `code.html`. Reads the inline
	 * `tailwind.config = { … }` object (the `<script id="tailwind-config">` block).
	 *
	 * @param string $html
	 * @return array{ colors:array, fontFamily:array, fontSize:array, spacing:array, rounded:array, fonts:string[] }
	 */
	public static function parse_tokens( $html ) {
		$out = array( 'colors' => array(), 'fontFamily' => array(), 'fontSize' => array(), 'spacing' => array(), 'rounded' => array(), 'fonts' => array() );
		$html = (string) $html;

		// Pull the `theme.extend` object out of `tailwind.config = { … }`.
		if ( preg_match( '/tailwind\.config\s*=\s*(\{)/s', $html, $m, PREG_OFFSET_CAPTURE ) ) {
			$start = $m[1][1];
			$obj   = self::balanced_braces( $html, $start );
			if ( $obj !== '' ) {
				$json = self::loose_json_to_array( $obj );
				$ext  = isset( $json['theme']['extend'] ) && is_array( $json['theme']['extend'] ) ? $json['theme']['extend'] : array();
				if ( isset( $ext['colors'] ) && is_array( $ext['colors'] ) )       { $out['colors']     = $ext['colors']; }
				if ( isset( $ext['fontFamily'] ) && is_array( $ext['fontFamily'] ) ){ $out['fontFamily'] = $ext['fontFamily']; }
				if ( isset( $ext['fontSize'] ) && is_array( $ext['fontSize'] ) )    { $out['fontSize']   = $ext['fontSize']; }
				if ( isset( $ext['spacing'] ) && is_array( $ext['spacing'] ) )      { $out['spacing']    = $ext['spacing']; }
				if ( isset( $ext['borderRadius'] ) && is_array( $ext['borderRadius'] ) ) { $out['rounded'] = $ext['borderRadius']; }
			}
		}

		// Google Fonts <link> hrefs → the font families to @import on the target. Skip the icon
		// font (Material Symbols) — its glyphs are converted to Font Awesome, so it isn't needed.
		// Decode HTML entities (`&amp;` → `&`) so the @import URL is valid.
		if ( preg_match_all( '#https://fonts\.googleapis\.com/css2\?[^"\']+#', $html, $fm ) ) {
			$fonts = array();
			foreach ( array_unique( $fm[0] ) as $href ) {
				if ( stripos( $href, 'Material+Symbols' ) !== false ) { continue; }
				$fonts[] = html_entity_decode( $href, ENT_QUOTES | ENT_HTML5 );
			}
			$out['fonts'] = array_values( $fonts );
		}

		return $out;
	}

	/**
	 * Merge a sibling `DESIGN.md` (the stitch-skill spec) frontmatter into a tokens array.
	 * The YAML frontmatter carries `colors:`, `typography:`, `rounded:`, `spacing:` — handy when
	 * the HTML's tailwind.config is absent or thin. HTML tokens win on conflict (they're authoritative).
	 *
	 * @param array  $tokens tokens from parse_tokens()
	 * @param string $md      DESIGN.md contents
	 * @return array tokens
	 */
	public static function merge_design_md( array $tokens, $md ) {
		$md = (string) $md;
		if ( trim( $md ) === '' ) { return $tokens; }
		if ( ! preg_match( '/^---\s*\n(.*?)\n---/s', $md, $m ) ) { return $tokens; }
		$fm = self::tiny_yaml( $m[1] );
		if ( isset( $fm['colors'] ) && is_array( $fm['colors'] ) ) {
			$tokens['colors'] = array_merge( $fm['colors'], $tokens['colors'] ); // HTML wins
		}
		if ( isset( $fm['rounded'] ) && is_array( $fm['rounded'] ) && ! $tokens['rounded'] ) {
			$tokens['rounded'] = $fm['rounded'];
		}
		if ( isset( $fm['spacing'] ) && is_array( $fm['spacing'] ) && ! $tokens['spacing'] ) {
			$tokens['spacing'] = $fm['spacing'];
		}
		return $tokens;
	}

	/**
	 * Build the carried design CSS from tokens: a `:root` block of CSS variables, the Google-Fonts
	 * @import, and a few base/component rules so the converted page reads like the Stitch screen
	 * (dark canvas, the two type families, primary button). EVERYTHING is scoped to
	 * `body` — `misc_custom_css` is absorbed into a combined bundle that also loads in
	 * wp-admin, so bare globals would restyle the dashboard (bundle gotcha #3).
	 *
	 * @param array $tokens
	 * @return string CSS
	 */
	public static function tokens_to_css_vars( array $tokens ) {
		$lines = array();

		// Font @imports first (valid only at the top of a stylesheet, but the optimizer hoists them).
		foreach ( ( $tokens['fonts'] ?? array() ) as $href ) {
			$lines[] = "@import url('" . esc_url_raw( $href ) . "');";
		}

		// :root custom properties from the palette + spacing + radius.
		$vars = array();
		foreach ( ( $tokens['colors'] ?? array() ) as $name => $hex ) {
			$n = self::css_var_name( $name );
			$h = self::norm_hex( is_array( $hex ) ? '' : (string) $hex );
			if ( $n !== '' && $h !== '' ) { $vars[] = '--' . $n . ':' . $h . ';'; }
		}
		foreach ( ( $tokens['spacing'] ?? array() ) as $name => $val ) {
			$n = self::css_var_name( $name );
			$v = is_array( $val ) ? '' : trim( (string) $val );
			if ( $n !== '' && $v !== '' ) { $vars[] = '--space-' . $n . ':' . $v . ';'; }
		}
		foreach ( ( $tokens['rounded'] ?? array() ) as $name => $val ) {
			$n = self::css_var_name( $name );
			$v = is_array( $val ) ? '' : trim( (string) $val );
			if ( $n !== '' && $v !== '' ) { $vars[] = '--radius-' . $n . ':' . $v . ';'; }
		}
		if ( $vars ) {
			$lines[] = 'body:not(.wp-admin){' . implode( '', $vars ) . '}';
		}

		// Base canvas + typography from the palette + fontFamily.
		list( $head_font, $body_font ) = self::pick_fonts( $tokens );
		$bg   = self::token_color( $tokens, array( 'background', 'surface', 'surface-container-lowest' ) );
		$fg   = self::token_color( $tokens, array( 'on-background', 'on-surface' ) );
		$base = array();
		if ( $body_font !== '' ) { $base[] = 'font-family:' . $body_font . ';'; }
		if ( $bg !== '' )        { $base[] = 'background-color:' . $bg . ';'; }
		if ( $fg !== '' )        { $base[] = 'color:' . $fg . ';'; }
		if ( $base ) {
			$lines[] = 'body:not(.wp-admin){' . implode( '', $base ) . '}';
		}
		if ( $head_font !== '' ) {
			$lines[] = 'body:not(.wp-admin) :is(h1,h2,h3,h4,h5,h6){font-family:' . $head_font . ';}';
		}

		// Primary button → the source's solid-fill look (Style: Default = bare `.btn`).
		$primary    = self::token_color( $tokens, array( 'primary' ) );
		$on_primary = self::token_color( $tokens, array( 'on-primary' ) );
		if ( $primary !== '' ) {
			$btn = 'background-color:' . $primary . ';';
			if ( $on_primary !== '' ) { $btn .= 'color:' . $on_primary . ';'; }
			$btn .= 'border-color:' . $primary . ';';
			// Exclude preset-classed buttons (`.btn-{slug}`) so a CTA on a Color Preset keeps its fill
			// (the preset token CSS is equal-specificity + no !important — see button_layer()).
			$lines[] = 'body:not(.wp-admin) .btn:not([class*="btn-"]){' . $btn . '}';
		}

		return implode( "\n", $lines );
	}

	/**
	 * The theme-settings.json payload: the carried design CSS in `misc_custom_css`. That key is a
	 * `multi` option, so its value MUST be the object `{ custom_css: "…" }`, never a raw string
	 * (bundle gotcha #2 — a string fatals the Theme Settings page). Kept for the "apply to the active
	 * theme" path; the default bundle emits `theme-design.json` instead (a child theme — see below).
	 *
	 * @param array $tokens
	 * @return array{ values: array }
	 */
	public static function tokens_to_theme_settings( array $tokens ) {
		$css = self::tokens_to_css_vars( $tokens );
		return array( 'values' => array( 'misc_custom_css' => array( 'custom_css' => $css ) ) );
	}

	/**
	 * CHROME → parent-theme Theme Settings (the playbook's "chrome = theme, not page content"
	 * approach — see site-converter/docs/site-conversion-playbook.md → Site chrome). Emits the
	 * source header/footer as native Header/Footer Theme-Settings values so the converted site
	 * runs on a NEAR-EMPTY child theme (Template: unysonplus-theme, no header.php/footer.php)
	 * instead of a baked one. Consumed by FW_Site_Converter_Theme_Settings::import() (writes each
	 * id via fw_set_db_settings_option); the importer accepts arbitrary keys and overlays them.
	 *
	 * Value shapes mirror the gold reference (Senkei's anime-header-footer.php):
	 *   header_logo   = { site_title, title_weight, color:{predefined,custom}, tagline,
	 *                     logo_icon:{type,svg-source,svg-id}, logo_icon_position, logo_icon_color }
	 *   header_main   = { main_left|center|right:[ element_type nodes ], main_custom_styling }
	 *   header_menu   = { menu_link_color, menu_link_hover_color, menu_link_font_size:{value,unit} }
	 *   header_layout = { header_mode, header_behavior, header_glass, bg_color, min_height, … }
	 *   footer_background = background-pro { color:{ value:{predefined,custom} } }  (NOT compact color)
	 *   copyright_settings = { enabled, yes:{ copyright_columns:{ count, '2':{ split, cols } } } }
	 *
	 * @param array  $tokens design tokens (palette/fonts)
	 * @param string $html   home-screen markup (for header/footer/menu/logo detection)
	 * @param string $title  site name (logo fallback)
	 * @return array{ values: array } theme-settings.json payload
	 */
	/**
	 * Derive Button Colour + Size Presets from the source's real button skin — the PHP MIRROR of the
	 * capture service's buildButtonPresets() (to-theme-settings.mjs). Finds <a>/<button> buttons, resolves
	 * their Tailwind classes to CSS via FW_Site_Converter_Tailwind, and emits button_colors (Primary filled
	 * + Secondary bordered) + a Large button_sizes preset — so the converted .btn-primary/.btn-secondary/
	 * .btn-lg match the source instead of Bootstrap defaults. KEEP IN SYNC with buildButtonPresets() (JS).
	 *
	 * @return array array('button_colors'=>…,'button_sizes'=>…) or empty.
	 */
	public static function build_button_presets( $html ) {
		$html = (string) $html;
		if ( $html === '' || ! class_exists( 'FW_Site_Converter_Tailwind' ) ) { return array(); }
		$dom = self::load_dom( $html );
		if ( ! $dom ) { return array(); }

		$hex     = function ( $h ) { return array( 'predefined' => '', 'custom' => (string) $h ); };
		$clean   = function ( $c ) { // rgb(R G B / var(…)) → rgb(R, G, B); hex / clean rgb pass through
			$c = trim( (string) $c );
			if ( preg_match( '/rgba?\(\s*(\d+)\s+(\d+)\s+(\d+)(?:\s*\/\s*([0-9.]+))?/', $c, $m ) ) {
				return ( isset( $m[4] ) && $m[4] !== '' && $m[4] !== '1' ) ? "rgba({$m[1]}, {$m[2]}, {$m[3]}, {$m[4]})" : "rgb({$m[1]}, {$m[2]}, {$m[3]})";
			}
			return $c;
		};
		$unit    = function ( $v ) { $v = trim( (string) $v ); return preg_match( '/^(-?[0-9.]+)\s*(px|rem|em|%)?$/', $v, $m ) ? array( 'value' => $m[1], 'unit' => ( isset( $m[2] ) && $m[2] !== '' ? $m[2] : 'px' ) ) : null; };
		$filledp = function ( $bg ) { $bg = strtolower( str_replace( ' ', '', (string) $bg ) ); return $bg !== '' && $bg !== 'transparent' && strpos( $bg, 'rgba(0,0,0,0' ) === false; };
		$whitish = function ( $bg ) { if ( preg_match( '/(\d+)[,\s]+(\d+)[,\s]+(\d+)/', (string) $bg, $m ) ) { return $m[1] > 240 && $m[2] > 240 && $m[3] > 240; } return in_array( strtolower( trim( (string) $bg ) ), array( '#fff', '#ffffff', 'white' ), true ); };
		$shbox   = function ( $css ) { $css = trim( (string) $css ); if ( $css === '' || $css === 'none' || strpos( $css, '#0000' ) !== false ) { return null; } $first = explode( ',', $css )[0]; preg_match( '/(rgba?\([^)]*\)|#[0-9a-f]{3,8})/i', $first, $cm ); $off = preg_replace( '/(rgba?\([^)]*\)|#[0-9a-f]{3,8}|inset)/i', '', $first ); if ( ! preg_match_all( '/-?\d+(?:\.\d+)?/', $off, $mm ) ) { return null; } $n = array_map( 'intval', $mm[0] ); return array( 'x' => isset( $n[0] ) ? $n[0] : 0, 'y' => isset( $n[1] ) ? $n[1] : 0, 'blur' => isset( $n[2] ) ? $n[2] : 0, 'spread' => isset( $n[3] ) ? $n[3] : 0, 'color' => ( isset( $cm[1] ) ? $cm[1] : 'rgba(0,0,0,0.1)' ), 'inset' => false ); };

		// Normalize a colour value (rgb()/rgba()/hex, possibly with `R G B / a` spacing) → clean rgb()/rgba()/hex,
		// or '' for transparent / fully-transparent. Preferred over $clean because data-sc-cs stamps standard
		// rgb()/rgba(), but keeps working for compiled Tailwind too.
		$normc = function ( $c ) {
			$c = strtolower( trim( (string) $c ) );
			if ( $c === '' || $c === 'transparent' || $c === 'none' ) { return ''; }
			if ( preg_match( '/rgba?\(\s*(\d+)[,\s]+(\d+)[,\s]+(\d+)(?:[,\s\/]+([0-9.]+))?/', $c, $m ) ) {
				$a = ( isset( $m[4] ) && $m[4] !== '' ) ? (float) $m[4] : 1.0;
				if ( $a <= 0.02 ) { return ''; }
				return $a < 1 ? "rgba({$m[1]}, {$m[2]}, {$m[3]}, {$a})" : "rgb({$m[1]}, {$m[2]}, {$m[3]})";
			}
			if ( preg_match( '/^#[0-9a-f]{3,8}$/', $c ) ) { return $c; }
			return '';
		};
		// Pick the FIRST visible (non-transparent) layer of a (possibly multi-layer) box-shadow → the preset
		// { x, y, blur, spread, color, inset } shape. data-sc-cs stamps several layers, most `rgba(0,0,0,0)`.
		$shadow1 = function ( $css ) use ( $normc ) {
			$css = trim( (string) $css );
			if ( $css === '' || strtolower( $css ) === 'none' ) { return null; }
			// Split on TOP-LEVEL commas (not the ones inside rgba(...)).
			$layers = array(); $depth = 0; $cur = '';
			for ( $i = 0, $n = strlen( $css ); $i < $n; $i++ ) {
				$ch = $css[ $i ];
				if ( $ch === '(' ) { $depth++; } elseif ( $ch === ')' ) { $depth--; }
				if ( $ch === ',' && $depth === 0 ) { $layers[] = $cur; $cur = ''; } else { $cur .= $ch; }
			}
			if ( $cur !== '' ) { $layers[] = $cur; }
			foreach ( $layers as $layer ) {
				$layer = trim( $layer );
				$inset = ( stripos( $layer, 'inset' ) !== false );
				$rest  = preg_replace( '/inset/i', '', $layer );
				$color = '';
				if ( preg_match( '/(rgba?\([^)]*\)|#[0-9a-f]{3,8})/i', $rest, $cm ) ) { $color = $normc( $cm[1] ); $rest = str_replace( $cm[1], '', $rest ); }
				if ( $color === '' ) { continue; } // transparent layer → skip
				$L = array();
				foreach ( preg_split( '/\s+/', trim( $rest ) ) as $tok ) { if ( preg_match( '/^(-?[0-9.]+)(?:px)?$/', $tok, $tm ) ) { $L[] = (int) round( (float) $tm[1] ); } }
				return array( 'x' => $L[0] ?? 0, 'y' => $L[1] ?? 0, 'blur' => $L[2] ?? 0, 'spread' => $L[3] ?? 0, 'color' => $color, 'inset' => $inset );
			}
			return null;
		};

		$skins = array();
		foreach ( array( 'a', 'button' ) as $tag ) {
			foreach ( $dom->getElementsByTagName( $tag ) as $node ) {
				$txt = trim( preg_replace( '/\s+/', ' ', (string) $node->textContent ) );
				if ( $txt === '' || mb_strlen( $txt ) > 40 ) { continue; }
				$cls = self::cls( $node );

				// PRIMARY source of truth: the button's RESOLVED computed style (data-sc-cs). The source's fills
				// are SEMANTIC classes (bg-primary / bg-secondary) that the Tailwind compiler can't resolve to a
				// hex — only the computed style carries the real green / amber / outline. Fall back to the
				// compiled Tailwind classes when there's no data-sc-cs (a static Stitch export).
				$cs = ( $node instanceof DOMElement ) ? (string) $node->getAttribute( 'data-sc-cs' ) : '';
				$props = array();
				if ( $cs !== '' ) {
					foreach ( explode( ';', $cs ) as $decl ) {
						$cp = strpos( $decl, ':' );
						if ( $cp === false ) { continue; }
						$props[ strtolower( trim( substr( $decl, 0, $cp ) ) ) ] = trim( substr( $decl, $cp + 1 ) );
					}
				}
				if ( ! $props ) {
					$c = FW_Site_Converter_Tailwind::compile_class_set( $cls );
					$props = isset( $c['base'] ) ? $c['base'] : array();
				}

				$bg = $normc( isset( $props['background-color'] ) ? $props['background-color'] : '' );
				$fg = $normc( isset( $props['color'] ) ? $props['color'] : '' );
				// Border: data-sc-cs stamps per-edge longhands; the compiled path stamps border-width/-color.
				$bw = '';
				if ( isset( $props['border-top-width'] ) && ! in_array( $props['border-top-width'], array( '', '0', '0px' ), true ) ) { $bw = $props['border-top-width']; }
				elseif ( isset( $props['border-width'] ) && ! in_array( $props['border-width'], array( '', '0', '0px' ), true ) ) { $bw = $props['border-width']; }
				$bdcol = $normc( isset( $props['border-top-color'] ) ? $props['border-top-color'] : ( isset( $props['border-color'] ) ? $props['border-color'] : '' ) );
				$radius = isset( $props['border-radius'] ) ? trim( $props['border-radius'] ) : '';
				$pad    = isset( $props['padding'] ) ? trim( $props['padding'] ) : '';
				$px = ''; $py = '';
				if ( isset( $props['padding-left'] ) ) { $px = $props['padding-left']; } elseif ( $pad !== '' ) { $pp = preg_split( '/\s+/', $pad ); $px = ( count( $pp ) >= 2 ? $pp[1] : $pp[0] ); }
				if ( isset( $props['padding-top'] ) )  { $py = $props['padding-top']; }  elseif ( $pad !== '' ) { $pp = preg_split( '/\s+/', $pad ); $py = $pp[0]; }

				// A real button skin = an opaque fill, OR a border (ghost/outline), OR an explicit btn/cta class.
				if ( $bg === '' && $bw === '' && ! preg_match( '/\b(btn|button|cta)\b/', $cls ) ) { continue; }

				// ROLE from the SEMANTIC class first (bg-primary → Primary, bg-secondary → Secondary), so the
				// converted button's style class (btn-primary / btn-secondary set by the mapper/chrome) resolves
				// to the matching preset. A white/transparent fill WITH a border → the Outline skin. Otherwise a
				// generic fill (first distinct → Primary, next → Secondary) for non-semantic sources.
				$lc = ' ' . strtolower( $cls ) . ' ';
				if ( preg_match( '/\sbg-(primary|brand)\b/', $lc ) )   { $role = 'Primary'; }
				elseif ( preg_match( '/\sbg-(secondary|accent|cta)\b/', $lc ) ) { $role = 'Secondary'; }
				elseif ( ( $bg === '' || $whitish( $bg ) ) && $bw !== '' ) { $role = 'Outline'; }
				elseif ( $filledp( $bg ) && ! $whitish( $bg ) )          { $role = 'Fill'; }
				else { $role = 'Outline'; }

				$skins[] = array(
					'role' => $role, 'bg' => $bg, 'fg' => $fg, 'bd' => $bdcol, 'bw' => $bw,
					'shadow' => isset( $props['box-shadow'] ) ? $props['box-shadow'] : '',
					'radius' => $radius, 'px' => $px, 'py' => $py,
					'fs' => isset( $props['font-size'] ) ? $props['font-size'] : '',
					'lh' => isset( $props['line-height'] ) ? $props['line-height'] : '',
					'cls' => $cls, // raw classes → parse `hover:*` for the real hover state
				);
			}
		}
		if ( empty( $skins ) ) { return array(); }

		// Build a SEMANTIC colour map from every button's NON-hover utility classes → the real fill each
		// `bg-<name>` / `text-<name>` / `border-<name>` token resolves to (taken from that button's computed
		// colours). This lets us resolve a `hover:bg-<name>` token that names a DIFFERENT semantic than the
		// default (e.g. a primary button whose hover is `hover:bg-secondary`) back to a real colour.
		$sem_bg = array(); $sem_fg = array(); $sem_bd = array();
		foreach ( $skins as $s ) {
			$lc = ' ' . strtolower( (string) $s['cls'] ) . ' ';
			if ( $s['bg'] !== '' && preg_match( '/\sbg-([a-z][a-z0-9-]*)(?:\/\d+)?\s/', $lc, $m ) && strpos( $m[0], 'hover:' ) === false ) { $sem_bg[ $m[1] ] = $s['bg']; }
			if ( $s['fg'] !== '' && preg_match( '/\stext-([a-z][a-z0-9-]*)(?:\/\d+)?\s/', $lc, $m ) ) { $sem_fg[ $m[1] ] = $s['fg']; }
			if ( $s['bd'] !== '' && preg_match( '/\sborder-([a-z][a-z0-9-]*)(?:\/\d+)?\s/', $lc, $m ) ) { $sem_bd[ $m[1] ] = $s['bd']; }
		}
		// Apply an alpha (0..1) to a clean rgb()/rgba()/hex colour → rgba(). Used for Tailwind's `/<pct>`
		// colour-opacity modifier and the `opacity-<n>` utility.
		$apply_alpha = function ( $col, $a ) use ( $normc ) {
			$col = $normc( $col );
			if ( $col === '' ) { return ''; }
			$a = max( 0, min( 1, (float) $a ));
			if ( preg_match( '/rgba?\(\s*(\d+)[,\s]+(\d+)[,\s]+(\d+)/', $col, $m ) ) { return "rgba({$m[1]}, {$m[2]}, {$m[3]}, {$a})"; }
			if ( preg_match( '/^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i', $col, $m ) ) { return 'rgba(' . hexdec( $m[1] ) . ', ' . hexdec( $m[2] ) . ', ' . hexdec( $m[3] ) . ", $a)"; }
			return $col;
		};
		// Resolve a Tailwind colour token (`primary`, `secondary/90`, `white`, `green-500`) → a real colour,
		// honouring the `/<pct>` opacity modifier. Prefers the semantic map (built from the source's own
		// computed fills); falls back to literal white/black/transparent and the Tailwind compiler. $fallback
		// is the button's own default colour (so `bg-primary/90` on a primary button resolves to its own fill).
		$resolve_token = function ( $token, $map, $fallback ) use ( $apply_alpha, $normc ) {
			$token = strtolower( trim( (string) $token ) );
			if ( $token === '' ) { return ''; }
			$alpha = 1.0; $name = $token;
			if ( strpos( $token, '/' ) !== false ) { list( $name, $pct ) = explode( '/', $token, 2 ); $alpha = ( is_numeric( $pct ) ? (float) $pct / 100 : 1.0 ); }
			$base = '';
			if ( isset( $map[ $name ] ) )                 { $base = $map[ $name ]; }
			elseif ( $name === 'white' )                  { $base = '#ffffff'; }
			elseif ( $name === 'black' )                  { $base = '#000000'; }
			elseif ( $name === 'transparent' )            { return ''; }
			elseif ( $fallback !== '' )                   { $base = $fallback; } // e.g. bg-primary/90 on the primary button
			if ( $base === '' && class_exists( 'FW_Site_Converter_Tailwind' ) ) {
				$c = FW_Site_Converter_Tailwind::compile_class_set( 'bg-' . $name );
				if ( isset( $c['base']['background-color'] ) ) { $base = $c['base']['background-color']; }
			}
			$base = $normc( $base );
			return ( $alpha < 1 && $base !== '' ) ? $apply_alpha( $base, $alpha ) : $base;
		};
		// Parse a button's `hover:*` classes → the real hover { bg, fg, bd } (empty where the source sets none).
		$hover_from_cls = function ( $cls, $def_bg, $def_fg, $def_bd ) use ( $resolve_token, $sem_bg, $sem_fg, $sem_bd, $apply_alpha ) {
			$out = array( 'bg' => '', 'fg' => '', 'bd' => '' );
			$cls = ' ' . strtolower( (string) $cls ) . ' ';
			if ( preg_match( '/\shover:bg-([a-z0-9-]+(?:\/\d+)?)\s/', $cls, $m ) )     { $out['bg'] = $resolve_token( $m[1], $sem_bg, $def_bg ); }
			if ( preg_match( '/\shover:text-([a-z0-9-]+(?:\/\d+)?)\s/', $cls, $m ) )   { $out['fg'] = $resolve_token( $m[1], $sem_fg, $def_fg ); }
			if ( preg_match( '/\shover:border-([a-z0-9-]+(?:\/\d+)?)\s/', $cls, $m ) ) { $out['bd'] = $resolve_token( $m[1], $sem_bd, $def_bd ); }
			// `hover:opacity-<n>` (no explicit fill change) → the default fill at that opacity.
			if ( $out['bg'] === '' && preg_match( '/\shover:opacity-(\d+)\s/', $cls, $m ) && $def_bg !== '' ) { $out['bg'] = $apply_alpha( $def_bg, (float) $m[1] / 100 ); }
			return $out;
		};

		// Cluster distinct skins by role + colours; the FIRST-seen wins each role (source order = prominence).
		$groups = array();
		foreach ( $skins as $s ) {
			$key = $s['role'] . '|' . $s['bg'] . '|' . $s['bw'] . $s['bd'];
			if ( ! isset( $groups[ $key ] ) ) { $groups[ $key ] = array_merge( $s, array( 'count' => 0 ) ); }
			$groups[ $key ]['count']++;
		}
		// One preset per ROLE (keep the most common skin for each role); order Primary, Secondary, Outline, Fill.
		$order = array( 'Primary' => 0, 'Secondary' => 1, 'Outline' => 2, 'Fill' => 3 );
		$by_role = array();
		foreach ( $groups as $g ) {
			$r = $g['role'];
			if ( ! isset( $by_role[ $r ] ) || $g['count'] > $by_role[ $r ]['count'] ) { $by_role[ $r ] = $g; }
		}
		uasort( $by_role, function ( $a, $b ) use ( $order ) { return ( $order[ $a['role'] ] ?? 9 ) - ( $order[ $b['role'] ] ?? 9 ); } );

		$state = function ( $fg, $bg, $bd, $bw, $bstyle, $sh ) use ( $hex, $unit, $filledp, $shadow1 ) {
			$st = array( 'text_color' => ( $fg !== '' ? $hex( $fg ) : array( 'predefined' => '', 'custom' => '' ) ), 'bg_color' => ( $filledp( $bg ) ? $hex( $bg ) : array( 'predefined' => '', 'custom' => '' ) ) );
			if ( $bd !== '' ) { $st['border_color'] = $hex( $bd ); }
			if ( $bstyle !== '' ) { $st['border_style'] = $bstyle; }
			if ( $bw !== '' ) { $u = $unit( $bw ); if ( $u ) { $st['border_width'] = $u; } }
			if ( $sh !== '' ) { $s = $shadow1( $sh ); if ( $s ) { $st['box_shadow'] = $s; } }
			return $st;
		};

		// Stable ids by role so a converted button referencing btn-{slug} always maps back.
		$role_id = array( 'Primary' => '0000000001', 'Secondary' => '0000000002', 'Outline' => '0000000003', 'Fill' => '0000000004' );
		$colors = array(); $seen_name = array();
		foreach ( $by_role as $g ) {
			$name = $g['role'];
			if ( isset( $seen_name[ $name ] ) ) { continue; }
			$seen_name[ $name ] = true;
			$is_outline = ( $name === 'Outline' ) || ( ! $filledp( $g['bg'] ) && $g['bw'] !== '' );
			$def_bd = ( $g['bw'] !== '' ? ( $g['bd'] !== '' ? $g['bd'] : $g['fg'] ) : '' );
			// HOVER — derive from the source button's own `hover:*` classes (real fill/text/border), NOT a
			// blind /90. A `hover:bg-primary/90` resolves via the semantic map to the primary fill at 90%
			// alpha; a `hover:bg-secondary` resolves to a genuinely different colour; `hover:opacity-90` →
			// the default fill at 90%. Only keys the source actually sets are written; if the source declares
			// no hover class at all, fall back to the classic /90 darken so filled buttons still lift.
			$hov = $hover_from_cls( isset( $g['cls'] ) ? $g['cls'] : '', $g['bg'], $g['fg'], $def_bd );
			$hover = array();
			if ( $hov['bg'] !== '' ) { $hover['bg_color'] = $hex( $hov['bg'] ); }
			if ( $hov['fg'] !== '' ) { $hover['text_color'] = $hex( $hov['fg'] ); }
			if ( $hov['bd'] !== '' ) { $hover['border_color'] = $hex( $hov['bd'] ); }
			if ( empty( $hover ) && $filledp( $g['bg'] ) ) {
				$hover['bg_color'] = $hex( preg_replace( '/^rgb\((.+)\)$/', 'rgba($1, 0.9)', $g['bg'] ) );
			}
			$colors[] = array(
				'id'         => $role_id[ $name ] ?? ( '00000000' . ( count( $colors ) + 1 ) ),
				'color_name' => $name,
				'states'     => array(
					'default' => $state( $g['fg'], $g['bg'], $def_bd, $g['bw'], ( $g['bw'] !== '' ? 'solid' : ( $is_outline ? 'solid' : 'none' ) ), $g['shadow'] ),
					'hover'   => $hover,
					'active'  => array(), 'focus' => array(), 'disabled' => array(),
				),
			);
		}

		// SIZE presets: one per distinct size (font-size + padding + PILL/rounded radius). The pill radius lives
		// on the size preset (border_radius) per the button-presets data model. Keep the largest as "Large".
		$sizes = array(); $seen_sz = array();
		$size_defs = array(); // collect distinct (fs,px,py,radius)
		foreach ( $skins as $s ) {
			if ( $s['fs'] === '' && $s['px'] === '' && $s['radius'] === '' ) { continue; }
			$k = $s['fs'] . '|' . $s['px'] . '|' . $s['py'] . '|' . $s['radius'];
			if ( isset( $seen_sz[ $k ] ) ) { continue; }
			$seen_sz[ $k ] = true;
			$size_defs[] = $s;
		}
		// Sort by font-size desc so the biggest is "Large".
		usort( $size_defs, function ( $a, $b ) { return (float) preg_replace( '/[^0-9.]/', '', (string) $b['fs'] ) <=> (float) preg_replace( '/[^0-9.]/', '', (string) $a['fs'] ); } );
		$size_names = array( array( 'Large', 'lg', '0000010004' ), array( 'Medium', 'md', '0000010003' ), array( 'Small', 'sm', '0000010002' ) );
		foreach ( array_slice( $size_defs, 0, 3 ) as $i => $s ) {
			list( $nm, $slug, $sid ) = $size_names[ $i ];
			$sz = array( 'id' => $sid, 'size_name' => $nm, 'slug' => $slug );
			if ( $s['fs'] !== '' )     { $u = $unit( $s['fs'] );     if ( $u ) { $sz['font_size']     = $u; } }
			if ( $s['lh'] !== '' && $s['lh'] !== 'normal' ) { $sz['line_height'] = $s['lh']; }
			if ( $s['py'] !== '' )     { $u = $unit( $s['py'] );     if ( $u ) { $sz['padding_y']     = $u; } }
			if ( $s['px'] !== '' )     { $u = $unit( $s['px'] );     if ( $u ) { $sz['padding_x']     = $u; } }
			if ( $s['radius'] !== '' ) { $u = $unit( $s['radius'] ); if ( $u ) { $sz['border_radius'] = $u; } } // 9999px = pill
			$sizes[] = $sz;
		}

		$out = array();
		if ( ! empty( $colors ) ) { $out['button_colors'] = $colors; }
		if ( ! empty( $sizes ) )  { $out['button_sizes']  = $sizes; }
		return $out;
	}

	/**
	 * Section Styles — cluster the source's distinctive section BANDS into reusable presets.
	 * PHP mirror of the capture service's sectionStyles() (to-presets.mjs): a section style is
	 * the reusable band SKIN (background + text/heading/link colours + border + radius). Only a
	 * band that DEVIATES from the page base (its own background fill, border, radius or shadow)
	 * becomes a preset; near-identical bands cluster into one; a text/heading colour is carried
	 * only when it differs from the base. Per-section PADDING stays a native section option, so
	 * presets leave padding empty (matching unysonplus_default_section_style_presets()).
	 *
	 * @param string $html
	 * @return array array('section_style_presets'=>…) or empty.
	 */
	public static function build_section_style_presets( $html ) {
		$html = (string) $html;
		if ( $html === '' || ! class_exists( 'FW_Site_Converter_Tailwind' ) ) { return array(); }
		$dom = self::load_dom( $html );
		if ( ! $dom ) { return array(); }
		$body = null;
		foreach ( $dom->getElementsByTagName( 'body' ) as $b ) { $body = $b; break; }
		if ( ! $body ) { return array(); }
		$roots = self::section_roots( $body );
		if ( empty( $roots ) ) { return array(); }

		$parse = function ( $c ) {
			$c = strtolower( trim( (string) $c ) );
			if ( preg_match( '/rgba?\(\s*(\d+)[,\s]+(\d+)[,\s]+(\d+)(?:[,\s\/]+([0-9.]+))?/', $c, $m ) ) {
				return array( (int) $m[1], (int) $m[2], (int) $m[3], ( isset( $m[4] ) && $m[4] !== '' ? (float) $m[4] : 1.0 ) );
			}
			if ( preg_match( '/^#([0-9a-f]{3}|[0-9a-f]{6})$/', $c, $m ) ) {
				$h = $m[1]; if ( strlen( $h ) === 3 ) { $h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2]; }
				return array( hexdec( substr( $h, 0, 2 ) ), hexdec( substr( $h, 2, 2 ) ), hexdec( substr( $h, 4, 2 ) ), 1.0 );
			}
			return null;
		};
		$norm = function ( $c ) use ( $parse ) {
			$p = $parse( $c );
			if ( ! $p || (float) $p[3] === 0.0 ) { return ''; }                 // transparent / none = no fill
			return $p[3] < 1 ? "rgba({$p[0]}, {$p[1]}, {$p[2]}, {$p[3]})" : "rgb({$p[0]}, {$p[1]}, {$p[2]})";
		};
		$lum  = function ( $c ) use ( $parse ) { $p = $parse( $c ); return $p ? ( 0.2126 * $p[0] + 0.7152 * $p[1] + 0.0722 * $p[2] ) : null; };
		$unit = function ( $v ) { $v = trim( (string) $v ); return preg_match( '/^(-?[0-9.]+)\s*(px|rem|em|%)?$/', $v, $m ) ? array( 'value' => $m[1], 'unit' => ( isset( $m[2] ) && $m[2] !== '' ? $m[2] : 'px' ) ) : null; };
		// Prefer the MEASURED heading color (data-sc-cs), falling back to the compiled Tailwind color — the
		// source's headings on a green CTA band are `text-white` etc. that only the computed style carries.
		$headOf = function ( $node ) use ( $norm ) {
			foreach ( array( 'h1', 'h2', 'h3' ) as $ht ) {
				foreach ( $node->getElementsByTagName( $ht ) as $hn ) {
					$cc = self::sc_css( $hn, 'color' );
					if ( $cc !== '' ) { return $norm( $cc ); }
					$hc = FW_Site_Converter_Tailwind::compile_class_set( self::cls( $hn ) );
					return isset( $hc['base']['color'] ) ? $norm( $hc['base']['color'] ) : '';
				}
			}
			return '';
		};

		// Pass 1 — read each section's skin from the MEASURED computed style (data-sc-cs) first, Tailwind
		// compile as fallback. Semantic band fills (`bg-primary` green CTA, `bg-background` tint) and full-bleed
		// background layers (`absolute inset-0 bg-primary`) only resolve via the computed style, so the earlier
		// compile-only pass collapsed every band to one "Light" preset. Also tally base text/heading.
		$secs = array(); $textTally = array(); $headTally = array();
		foreach ( $roots as $node ) {
			$c = FW_Site_Converter_Tailwind::compile_class_set( self::cls( $node ) );
			$b = isset( $c['base'] ) ? $c['base'] : array();
			// Effective background: the node's own opaque computed fill, else a full-bleed (`inset-0`) child
			// background layer's opaque fill, else the compiled Tailwind value. Low-alpha tints are ignored.
			$bg_raw = self::el_bg_opaque( $node );
			if ( $bg_raw === '' ) { $bg_raw = self::fullbleed_bg( $node ); }
			$bg     = $bg_raw !== '' ? $norm( $bg_raw ) : ( isset( $b['background-color'] ) ? $norm( $b['background-color'] ) : '' );
			$bw     = ( isset( $b['border-width'] ) && $b['border-width'] !== '0' && $b['border-width'] !== '0px' ) ? $b['border-width'] : '';
			$radius = ( isset( $b['border-radius'] ) && $b['border-radius'] !== '0' && $b['border-radius'] !== '0px' ) ? $b['border-radius'] : '';
			$shadow = ( isset( $b['box-shadow'] ) && $b['box-shadow'] !== 'none' ) ? $b['box-shadow'] : '';
			$tcs    = self::sc_css( $node, 'color' );
			$text   = $tcs !== '' ? $norm( $tcs ) : ( isset( $b['color'] ) ? $norm( $b['color'] ) : '' );
			$head   = $headOf( $node );
			$bdcol  = isset( $b['border-color'] ) ? $norm( $b['border-color'] ) : '';
			if ( $text !== '' ) { $textTally[ $text ] = ( isset( $textTally[ $text ] ) ? $textTally[ $text ] : 0 ) + 1; }
			if ( $head !== '' ) { $headTally[ $head ] = ( isset( $headTally[ $head ] ) ? $headTally[ $head ] : 0 ) + 1; }
			$secs[] = compact( 'bg', 'bw', 'radius', 'shadow', 'text', 'head', 'bdcol' );
		}
		arsort( $textTally ); arsort( $headTally );
		$baseText = $textTally ? (string) array_key_first( $textTally ) : '';
		$baseHead = $headTally ? (string) array_key_first( $headTally ) : '';

		// Pass 2 — cluster the distinctive bands.
		$groups = array();
		foreach ( $secs as $s ) {
			if ( $s['bg'] === '' && $s['bw'] === '' && $s['radius'] === '' && $s['shadow'] === '' ) { continue; } // plain band = default
			$key = $s['bg'] . '|' . $s['radius'] . '|' . $s['bw'] . $s['bdcol'];
			if ( ! isset( $groups[ $key ] ) ) { $groups[ $key ] = array_merge( $s, array( 'count' => 0 ) ); }
			$groups[ $key ]['count']++;
		}
		if ( empty( $groups ) ) { return array(); }
		uasort( $groups, function ( $a, $b ) { return $b['count'] - $a['count']; } );

		$empty = array( 'predefined' => '', 'custom' => '' );
		$u0    = array( 'value' => '', 'unit' => 'px' );
		$pad0  = array(
			'margin'  => array( 'all' => '', 'top' => '', 'right' => '', 'bottom' => '', 'left' => '' ),
			'padding' => array( 'all' => '', 'top' => '', 'right' => '', 'bottom' => '', 'left' => '' ),
		);
		$used = array(); $n = 0; $presets = array();
		foreach ( $groups as $g ) {
			$L      = $lum( $g['bg'] );
			$base   = ( $L === null ) ? 'Band' : ( $L < 90 ? 'Dark' : ( $L > 245 ? 'Light' : 'Alt' ) );
			$used[ $base ] = ( isset( $used[ $base ] ) ? $used[ $base ] : 0 ) + 1;
			$name   = $used[ $base ] > 1 ? $base . ' ' . $used[ $base ] : $base;
			$isDark = ( $L !== null && $L < 90 );
			$rad    = $g['radius'] !== '' ? $unit( $g['radius'] ) : null;
			$bwu    = $g['bw'] !== '' ? $unit( $g['bw'] ) : null;
			$presets[] = array(
				'id'            => 's' . str_pad( (string) ( ++$n ), 9, '0', STR_PAD_LEFT ),
				'style_name'    => $name,
				'background'    => $g['bg'] !== '' ? array( 'color' => array( 'value' => array( 'predefined' => '', 'custom' => $g['bg'] ) ) ) : array( 'color' => array( 'value' => $empty ) ),
				'text_color'    => ( $g['text'] !== '' && ( $isDark || $g['text'] !== $baseText ) ) ? array( 'predefined' => '', 'custom' => $g['text'] ) : $empty,
				'heading_color' => ( $g['head'] !== '' && ( $isDark || $g['head'] !== $baseHead ) ) ? array( 'predefined' => '', 'custom' => $g['head'] ) : $empty,
				'link_color'    => $empty,
				'border'        => $bwu ? array( 'width' => $bwu, 'style' => 'solid', 'color' => ( $g['bdcol'] !== '' ? array( 'predefined' => '', 'custom' => $g['bdcol'] ) : $empty ) ) : array( 'width' => $u0, 'style' => '', 'color' => $empty ),
				'border_sides'  => array( 'top', 'right', 'bottom', 'left' ),
				'border_extent' => array( 'mode' => 'full' ),
				'border_radius' => $rad ? $rad : $u0,
				'padding'       => $pad0,
			);
		}
		return empty( $presets ) ? array() : array( 'section_style_presets' => $presets );
	}

	/**
	 * SPACING SCALE (Theme Settings → Components → Spacing — the `spacing_scale` addable-box: {name,size} rows).
	 * The source is Tailwind, so its spacing steps ARE the Bootstrap-aligned base scale (the plugin default,
	 * 0–12); we emit that as the editable scale (instead of the converter leaving it empty → silent theme
	 * fallback), then APPEND any arbitrary off-scale spacing the source genuinely uses — a Tailwind arbitrary
	 * token like `pt-[192px]` / `py-[3.5rem]` — as a `[value]` entry (the exact class the converter emits and
	 * the per-page CSS renders). Only spacing-prefixed arbitrary tokens are harvested (not decorative `w-[800px]`
	 * blobs), and only when they aren't already on the base scale. Deterministic: the base is the source's own
	 * Tailwind scale; the extras are values literally present in the markup.
	 *
	 * @param array  $tokens
	 * @param string $html
	 * @return array {name,size} rows (base scale + arbitraries), or [] when nothing to emit.
	 */
	public static function build_spacing_scale( array $tokens, $html ) {
		// Base = the Bootstrap-aligned scale (= the theme's unysonplus_default_spacing_scale()). Kept in sync
		// here so the converter is self-contained (the theme default isn't loaded in the capture/bundle path).
		$base = array(
			array( 'name' => '0',  'size' => '0' ),
			array( 'name' => '1',  'size' => '0.25rem' ),
			array( 'name' => '2',  'size' => '0.5rem' ),
			array( 'name' => '3',  'size' => '1rem' ),
			array( 'name' => '4',  'size' => '1.5rem' ),
			array( 'name' => '5',  'size' => '3rem' ),
			array( 'name' => '6',  'size' => '3.5rem' ),
			array( 'name' => '7',  'size' => '4rem' ),
			array( 'name' => '8',  'size' => '4.5rem' ),
			array( 'name' => '9',  'size' => '5rem' ),
			array( 'name' => '10', 'size' => '6rem' ),
			array( 'name' => '11', 'size' => '7rem' ),
			array( 'name' => '12', 'size' => '8rem' ),
		);
		$have = array();
		foreach ( $base as $e ) { $have[ trim( strtolower( $e['size'] ) ) ] = true; }

		// Harvest arbitrary SPACING tokens actually present in the markup: p/px/py/pt/pb/pl/pr, the m-* family,
		// gap-*, space-x/y-* with a bracketed length — e.g. `pt-[192px]`, `mb-[3.5rem]`. Skip w-/h-/inset (sizes).
		$extras = array();
		if ( preg_match_all( '/\b(?:p[trblxy]?|m[trblxy]?|gap(?:-[xy])?|space-[xy])-\[([0-9.]+(?:px|rem|em))\]/', (string) $html, $mm ) ) {
			foreach ( array_unique( $mm[1] ) as $val ) {
				$k = trim( strtolower( $val ) );
				if ( isset( $have[ $k ] ) ) { continue; }
				// Only meaningful, larger off-scale values (avoid a flood of tiny 1px odds); keep ≥ 2.5rem / 40px.
				$px = self::spacing_to_px( $val );
				if ( $px < 40 ) { continue; }
				$have[ $k ] = true;
				$extras[ $px ] = array( 'name' => '[' . $val . ']', 'size' => $val );
			}
		}
		ksort( $extras );
		return array_merge( $base, array_values( $extras ) );
	}

	/** A spacing length ('192px' | '3.5rem' | '2em') → approximate pixels (rem/em = ×16). 0 when unparseable. */
	private static function spacing_to_px( $v ) {
		$v = trim( (string) $v );
		if ( preg_match( '/^([0-9.]+)px$/', $v, $m ) )        { return (float) $m[1]; }
		if ( preg_match( '/^([0-9.]+)(rem|em)$/', $v, $m ) )  { return (float) $m[1] * 16; }
		return 0.0;
	}

	/**
	 * Derive BOX PRESETS (Theme Settings → Components → Box Presets — the border_presets data model,
	 * the boxp-{slug} card skins) from the source's cards / containers / image frames. Walks every
	 * box-like element, compiles its Tailwind classes to CSS (border / corner radius / shadow + the
	 * hover shadow & lift), clusters the DISTINCT designs across the page, and appends the top few as
	 * named presets on top of the plugin defaults. Same DOM + Tailwind-compile pattern as
	 * build_section_style_presets()/build_button_presets(). Returns array( 'border_presets' => [...] ).
	 */
	public static function build_box_presets( $html ) {
		$html = (string) $html;
		if ( $html === '' || ! class_exists( 'FW_Site_Converter_Tailwind' ) ) { return array(); }
		$dom = self::load_dom( $html );
		if ( ! $dom ) { return array(); }
		$body = null;
		foreach ( $dom->getElementsByTagName( 'body' ) as $b ) { $body = $b; break; }
		if ( ! $body ) { return array(); }

		$unit = function ( $v ) {
			$v = trim( (string) $v );
			return preg_match( '/^(-?[0-9.]+)\s*(px|rem|em|%)?$/', $v, $m )
				? array( 'value' => $m[1], 'unit' => ( isset( $m[2] ) && $m[2] !== '' ? $m[2] : 'px' ) )
				: array( 'value' => '', 'unit' => 'px' );
		};
		// Normalize a compiled Tailwind color (which may carry a `/ var(--tw-*-opacity)`) to a clean
		// rgb()/rgba() — or '' for transparent. Mirrors the section-preset color handling.
		$norm = function ( $c ) {
			$c = strtolower( trim( (string) $c ) );
			$has_var = ( strpos( $c, 'var(' ) !== false );
			if ( preg_match( '/rgba?\(\s*([0-9.]+)[,\s]+([0-9.]+)[,\s]+([0-9.]+)(?:[,\s\/]+([0-9.]+))?/', $c, $m ) ) {
				$a = ( ! $has_var && isset( $m[4] ) && $m[4] !== '' ) ? (float) $m[4] : 1.0;
				if ( $a === 0.0 ) { return ''; }
				return $a < 1 ? "rgba({$m[1]}, {$m[2]}, {$m[3]}, {$a})" : "rgb({$m[1]}, {$m[2]}, {$m[3]})";
			}
			if ( preg_match( '/^#([0-9a-f]{3}|[0-9a-f]{6})$/', $c, $m ) ) {
				$h = $m[1]; if ( strlen( $h ) === 3 ) { $h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2]; }
				return 'rgb(' . hexdec( substr( $h, 0, 2 ) ) . ', ' . hexdec( substr( $h, 2, 2 ) ) . ', ' . hexdec( substr( $h, 4, 2 ) ) . ')';
			}
			return '';
		};
		// Parse the FIRST box-shadow layer into the preset's { x, y, blur, spread, color, inset } shape.
		$parse_shadow = function ( $s ) use ( $norm ) {
			$s = trim( (string) $s );
			if ( $s === '' || strtolower( $s ) === 'none' ) { return null; }
			$depth = 0; $first = '';
			for ( $i = 0, $len = strlen( $s ); $i < $len; $i++ ) {
				$ch = $s[ $i ];
				if ( $ch === '(' ) { $depth++; } elseif ( $ch === ')' ) { $depth--; } elseif ( $ch === ',' && $depth === 0 ) { break; }
				$first .= $ch;
			}
			$first = trim( $first );
			$inset = ( stripos( $first, 'inset' ) !== false );
			$first = preg_replace( '/inset/i', '', $first );
			$color = 'rgba(0, 0, 0, 0.1)';
			if ( preg_match( '/(rgba?\([^)]*\)|#[0-9a-fA-F]{3,8})/', $first, $cm ) ) {
				$nc = $norm( $cm[1] );
				if ( $nc !== '' ) { $color = $nc; }
				$first = str_replace( $cm[1], '', $first );
			}
			// Lengths x/y/blur/spread — split on whitespace so a UNITLESS 0 isn't dropped.
			$L = array();
			foreach ( preg_split( '/\s+/', trim( $first ) ) as $tok ) {
				if ( preg_match( '/^(-?[0-9.]+)(?:px)?$/', $tok, $tm ) ) { $L[] = $tm[1]; }
			}
			return array(
				'x'      => isset( $L[0] ) ? (int) round( (float) $L[0] ) : 0,
				'y'      => isset( $L[1] ) ? (int) round( (float) $L[1] ) : 0,
				'blur'   => isset( $L[2] ) ? (int) round( (float) $L[2] ) : 0,
				'spread' => isset( $L[3] ) ? (int) round( (float) $L[3] ) : 0,
				'color'  => $color,
				'inset'  => $inset,
			);
		};

		$skip  = array( 'html', 'head', 'body', 'section', 'nav', 'header', 'footer', 'main', 'script', 'style', 'svg', 'path', 'button', 'a' );
		$boxes = array();
		foreach ( $body->getElementsByTagName( '*' ) as $node ) {
			$cls = self::cls( $node );
			if ( $cls === '' || ! preg_match( '/(?:^|\s)(rounded|shadow|border)/i', $cls ) ) { continue; } // cheap pre-filter
			if ( in_array( strtolower( $node->tagName ), $skip, true ) ) { continue; }
			$c = FW_Site_Converter_Tailwind::compile_class_set( $cls );
			$b = isset( $c['base'] ) ? $c['base'] : array();
			$radius = ( isset( $b['border-radius'] ) && ! in_array( (string) $b['border-radius'], array( '', '0', '0px' ), true ) ) ? (string) $b['border-radius'] : '';
			$shadow = ( isset( $b['box-shadow'] ) && strtolower( (string) $b['box-shadow'] ) !== 'none' ) ? (string) $b['box-shadow'] : '';
			$bw     = ( isset( $b['border-width'] ) && ! in_array( (string) $b['border-width'], array( '', '0', '0px' ), true ) ) ? (string) $b['border-width'] : '';
			if ( $radius === '' && $shadow === '' && $bw === '' ) { continue; }
			$hv      = isset( $c['hover'] ) ? $c['hover'] : array();
			$boxes[] = array(
				'radius'  => $radius,
				'shadow'  => $shadow,
				'bw'      => $bw,
				'bdcol'   => isset( $b['border-color'] ) ? $norm( (string) $b['border-color'] ) : '',
				'hshadow' => ( isset( $hv['box-shadow'] ) && strtolower( (string) $hv['box-shadow'] ) !== 'none' ) ? (string) $hv['box-shadow'] : '',
				'hlift'   => (bool) preg_match( '/hover:-translate-y-[0-9.]/', $cls ),
			);
		}
		if ( empty( $boxes ) ) { return array(); }

		// Cluster the distinct box designs; keep the most common few.
		$groups = array();
		foreach ( $boxes as $bx ) {
			$key = $bx['radius'] . '|' . preg_replace( '/\s+/', '', $bx['shadow'] ) . '|' . $bx['bw'] . '|' . $bx['bdcol'];
			if ( ! isset( $groups[ $key ] ) ) { $groups[ $key ] = array_merge( $bx, array( 'count' => 0 ) ); }
			$groups[ $key ]['count']++;
		}
		uasort( $groups, function ( $a, $b ) { return $b['count'] - $a['count']; } );
		$groups = array_slice( $groups, 0, 5, true );

		$empty = array( 'predefined' => '', 'custom' => '' );
		$u0    = array( 'value' => '', 'unit' => 'px' );
		$pad0  = array(
			'margin'  => array( 'all' => '', 'top' => '', 'right' => '', 'bottom' => '', 'left' => '' ),
			'padding' => array( 'all' => '', 'top' => '', 'right' => '', 'bottom' => '', 'left' => '' ),
		);
		$used = array(); $n = 0; $derived = array();
		foreach ( $groups as $g ) {
			$has_shadow = ( $g['shadow'] !== '' );
			$has_border = ( $g['bw'] !== '' );
			$base = ( $has_shadow && $has_border ) ? 'Card' : ( $has_shadow ? 'Elevated' : ( $has_border ? 'Outline' : 'Rounded' ) );
			$used[ $base ] = ( isset( $used[ $base ] ) ? $used[ $base ] : 0 ) + 1;
			$name = $used[ $base ] > 1 ? $base . ' ' . $used[ $base ] : $base;

			$default = array();
			if ( $has_border ) {
				$default['border_style'] = 'solid';
				$default['border_width'] = $unit( $g['bw'] );
				$default['border_color'] = $g['bdcol'] !== '' ? array( 'predefined' => '', 'custom' => $g['bdcol'] ) : $empty;
			}
			$sh = $parse_shadow( $g['shadow'] );
			if ( $sh ) { $default['box_shadow'] = $sh; }
			$hover = array();
			$hsh   = $parse_shadow( $g['hshadow'] );
			if ( $hsh ) { $hover['box_shadow'] = $hsh; }

			$derived[] = array(
				'id'            => 'b' . str_pad( (string) ( 100 + ( ++$n ) ), 9, '0', STR_PAD_LEFT ),
				'preset_name'   => $name,
				'border_sides'  => 'all',
				'border_radius' => $g['radius'] !== '' ? $unit( $g['radius'] ) : $u0,
				'padding'       => $pad0,
				'transition'    => '200',
				'hover_fx'      => $g['hlift'] ? array( 'lift' ) : array(),
				'custom_css'    => '',
				'states'        => $hover ? array( 'default' => $default, 'hover' => $hover ) : array( 'default' => $default ),
			);
		}
		if ( empty( $derived ) ) { return array(); }

		// Append the site-specific presets to the plugin defaults (keeps the built-in Card/Outline/… library).
		$base_presets = function_exists( 'unysonplus_default_border_presets' ) ? unysonplus_default_border_presets() : array();
		return array( 'border_presets' => array_merge( $base_presets, $derived ) );
	}

	/**
	 * Derive TEXT STYLES (Theme Settings → Components → Text Styles — the `font_sizes` key: named
	 * size + weight + line-height + letter-spacing + transform utilities). Reads the source's headings
	 * (h1–h6) for the DISPLAY size scale (largest rendered size per level, across breakpoints) and
	 * detects the distinctive EYEBROW/overline treatment (uppercase + tracking). Emits a faithful
	 * `font_sizes` scale: Display 1..N (class display-N) + Lead + Eyebrow. Same DOM+Tailwind pattern
	 * as build_box_presets(). Returns array( 'font_sizes' => [...] ).
	 */
	public static function build_text_styles( $html ) {
		$html = (string) $html;
		if ( $html === '' || ! class_exists( 'FW_Site_Converter_Tailwind' ) ) { return array(); }
		$dom = self::load_dom( $html );
		if ( ! $dom ) { return array(); }
		$body = null;
		foreach ( $dom->getElementsByTagName( 'body' ) as $b ) { $body = $b; break; }
		if ( ! $body ) { return array(); }

		$to_px = function ( $v ) {
			$v = trim( (string) $v );
			if ( $v === '' ) { return null; }
			if ( preg_match( '/^(-?[0-9.]+)(rem|em)$/', $v, $m ) ) { return (float) $m[1] * 16; }
			if ( preg_match( '/^(-?[0-9.]+)px$/', $v, $m ) ) { return (float) $m[1]; }
			if ( preg_match( '/^(-?[0-9.]+)$/', $v ) ) { return (float) $v; }
			return null;
		};

		// --- Display scale from headings: the largest rendered size (across base/md/lg) per heading. ---
		$disp = array();
		foreach ( array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ) as $ht ) {
			foreach ( $body->getElementsByTagName( $ht ) as $hn ) {
				$c = FW_Site_Converter_Tailwind::compile_class_set( self::cls( $hn ) );
				$size = null; $weight = ''; $lh = '';
				foreach ( array( 'base', 'md', 'lg' ) as $bp ) {
					if ( ! isset( $c[ $bp ] ) || ! is_array( $c[ $bp ] ) ) { continue; }
					$bk = $c[ $bp ];
					if ( isset( $bk['font-size'] ) ) {
						$px = $to_px( $bk['font-size'] );
						if ( $px !== null && ( $size === null || $px > $size ) ) {
							$size   = $px;
							$weight = isset( $bk['font-weight'] ) ? (string) $bk['font-weight'] : $weight;
							$lh     = isset( $bk['line-height'] ) ? (string) $bk['line-height'] : $lh;
						}
					}
				}
				$bb = isset( $c['base'] ) ? $c['base'] : array();
				if ( $weight === '' && isset( $bb['font-weight'] ) ) { $weight = (string) $bb['font-weight']; }
				if ( $lh === '' && isset( $bb['line-height'] ) ) { $lh = (string) $bb['line-height']; }
				if ( $size === null || $size < 20 ) { continue; } // ignore tiny / unstyled headings
				$key = (string) (int) round( $size );
				if ( ! isset( $disp[ $key ] ) ) { $disp[ $key ] = array( 'size' => (int) round( $size ), 'weight' => $weight, 'lh' => $lh, 'count' => 0 ); }
				$disp[ $key ]['count']++;
			}
		}
		uasort( $disp, function ( $a, $b ) { return $b['size'] - $a['size']; } ); // largest first

		// --- Eyebrow / overline: uppercase + letter-spacing (tracking), most common instance. ---
		$eye = null; $eye_best = -1;
		foreach ( $body->getElementsByTagName( '*' ) as $node ) {
			$cls = self::cls( $node );
			if ( $cls === '' || ! preg_match( '/uppercase/i', $cls ) || ! preg_match( '/tracking|letter/i', $cls ) ) { continue; }
			$c = FW_Site_Converter_Tailwind::compile_class_set( $cls );
			$b = isset( $c['base'] ) ? $c['base'] : array();
			if ( ( isset( $b['text-transform'] ) ? $b['text-transform'] : '' ) !== 'uppercase' ) { continue; }
			$ls = isset( $b['letter-spacing'] ) ? (string) $b['letter-spacing'] : '';
			if ( $ls === '' ) { continue; }
			$sz = isset( $b['font-size'] ) ? $to_px( $b['font-size'] ) : null;
			// Prefer a SMALL eyebrow (overlines are small); keep the smallest-sized candidate.
			$score = ( $sz !== null ) ? ( 1000 - $sz ) : 0;
			if ( $score > $eye_best ) {
				$eye_best = $score;
				$eye = array(
					'size'   => $sz !== null ? (string) (int) round( $sz ) : '',
					'weight' => isset( $b['font-weight'] ) ? (string) $b['font-weight'] : '',
					'ls'     => $ls,
				);
			}
		}

		if ( empty( $disp ) && ! $eye ) { return array(); }

		$mk = function ( $name, $size, $weight, $lh, $ls, $transform, $class ) {
			return array(
				'name'           => $name,
				'size'           => (string) $size,
				'weight'         => (string) $weight,
				'line_height'    => (string) $lh,
				'letter_spacing' => (string) $ls,
				'transform'      => (string) $transform,
				'class'          => (string) $class,
			);
		};

		$presets = array();
		$dv = array_values( $disp );
		$n  = min( 6, count( $dv ) );
		for ( $i = 0; $i < $n; $i++ ) {
			$presets[] = $mk( 'Display ' . ( $i + 1 ), $dv[ $i ]['size'], $dv[ $i ]['weight'], $dv[ $i ]['lh'], '', '', 'display-' . ( $i + 1 ) );
		}
		$presets[] = $mk( 'Lead', 22, '', '', '', '', 'lead' ); // keep a sensible Lead
		if ( $eye ) {
			$presets[] = $mk( 'Eyebrow', $eye['size'], $eye['weight'], '', $eye['ls'], 'uppercase', '' ); // → .font-eyebrow
		}
		return array( 'font_sizes' => $presets );
	}

	/**
	 * Derive IMAGE STYLES (Theme Settings → Components → Image Styles — the `image_styles` key, the
	 * .imgs-{slug} treatments). Walks each <img> (and its immediate wrapper) for corner radius / circle,
	 * aspect-ratio and colour filter, clusters the distinct treatments, and appends them to the default
	 * library. Same DOM+Tailwind pattern as build_box_presets(). Returns array( 'image_styles' => [...] ).
	 */
	public static function build_image_styles( $html ) {
		$html = (string) $html;
		if ( $html === '' || ! class_exists( 'FW_Site_Converter_Tailwind' ) ) { return array(); }
		$dom = self::load_dom( $html );
		if ( ! $dom ) { return array(); }
		$body = null;
		foreach ( $dom->getElementsByTagName( 'body' ) as $b ) { $body = $b; break; }
		if ( ! $body ) { return array(); }

		$aspect_slug = function ( $ar ) {
			$ar = str_replace( ' ', '', strtolower( (string) $ar ) );
			$map = array( '1/1' => '1-1', '4/3' => '4-3', '3/4' => '3-4', '16/9' => '16-9', '3/2' => '3-2' );
			return isset( $map[ $ar ] ) ? $map[ $ar ] : 'auto';
		};
		$filter_slug = function ( $f ) {
			$f = strtolower( (string) $f );
			foreach ( array( 'grayscale', 'sepia', 'blur', 'contrast', 'saturate' ) as $k ) {
				if ( strpos( $f, $k ) !== false ) { return $k; }
			}
			return 'none';
		};
		// A radius counts as a "circle" if it's rounded-full (9999/50%) or a very large px.
		$radius_of = function ( $props ) {
			$r = isset( $props['border-radius'] ) ? trim( (string) $props['border-radius'] ) : '';
			if ( $r === '' || $r === '0' || $r === '0px' ) { return array( '', false ); }
			if ( strpos( $r, '9999' ) !== false || strpos( $r, '50%' ) !== false || ( preg_match( '/^([0-9.]+)px$/', $r, $m ) && (float) $m[1] >= 500 ) ) {
				return array( '', true ); // circle
			}
			return array( $r, false );
		};

		$imgs = array();
		foreach ( $body->getElementsByTagName( 'img' ) as $img ) {
			$ci = FW_Site_Converter_Tailwind::compile_class_set( self::cls( $img ) );
			$bi = isset( $ci['base'] ) ? $ci['base'] : array();
			list( $radius, $circle ) = $radius_of( $bi );
			$aspect = isset( $bi['aspect-ratio'] ) ? $aspect_slug( $bi['aspect-ratio'] ) : 'auto';
			$filter = isset( $bi['filter'] ) ? $filter_slug( $bi['filter'] ) : 'none';
			// Radius / aspect are often on the wrapping element (rounded overflow-hidden frame).
			if ( ( $radius === '' && ! $circle ) || $aspect === 'auto' ) {
				$p = $img->parentNode;
				if ( $p instanceof DOMElement ) {
					$cp = FW_Site_Converter_Tailwind::compile_class_set( self::cls( $p ) );
					$bp = isset( $cp['base'] ) ? $cp['base'] : array();
					if ( $radius === '' && ! $circle ) { list( $radius, $circle ) = $radius_of( $bp ); }
					if ( $aspect === 'auto' && isset( $bp['aspect-ratio'] ) ) { $aspect = $aspect_slug( $bp['aspect-ratio'] ); }
				}
			}
			if ( $radius === '' && ! $circle && $aspect === 'auto' && $filter === 'none' ) { continue; } // untreated
			$key = ( $circle ? 'circle' : $radius ) . '|' . $aspect . '|' . $filter;
			if ( ! isset( $imgs[ $key ] ) ) { $imgs[ $key ] = array( 'radius' => $radius, 'circle' => $circle, 'aspect' => $aspect, 'filter' => $filter, 'count' => 0 ); }
			$imgs[ $key ]['count']++;
		}
		if ( empty( $imgs ) ) { return array(); }
		uasort( $imgs, function ( $a, $b ) { return $b['count'] - $a['count']; } );
		$imgs = array_slice( $imgs, 0, 5, true );

		$mask = function ( $key ) { return array( 'mask' => $key, 'custom' => array( 'custom_svg' => '', 'custom_clip' => '' ) ); };
		$col0 = array( 'predefined' => '', 'custom' => '' );
		$used = array(); $n = 0; $derived = array();
		foreach ( $imgs as $s ) {
			$base_name = $s['circle'] ? 'Circle' : ( $s['filter'] === 'grayscale' ? 'Monochrome' : ( $s['filter'] !== 'none' ? ucfirst( $s['filter'] ) : ( $s['aspect'] === '1-1' ? 'Square' : ( in_array( $s['aspect'], array( '16-9', '3-2', '4-3' ), true ) ? 'Wide' : ( $s['aspect'] === '3-4' ? 'Portrait' : 'Rounded' ) ) ) ) );
			$used[ $base_name ] = ( isset( $used[ $base_name ] ) ? $used[ $base_name ] : 0 ) + 1;
			$name = $used[ $base_name ] > 1 ? $base_name . ' ' . $used[ $base_name ] : $base_name;
			$derived[] = array(
				'id'          => 'img' . str_pad( (string) ( 100 + ( ++$n ) ), 6, '0', STR_PAD_LEFT ),
				'style_name'  => $name,
				'aspect'      => $s['aspect'],
				'radius'      => $s['circle'] ? '' : $s['radius'],
				'mask'        => $s['circle'] ? $mask( 'circle' ) : $mask( 'none' ),
				'filter'      => $s['filter'],
				'duo_color'   => $col0,
				'scrim'       => 'none',
				'scrim_color' => $col0,
			);
		}
		if ( empty( $derived ) ) { return array(); }
		$base_lib = function_exists( 'unysonplus_default_image_style_presets' ) ? unysonplus_default_image_style_presets() : array();
		return array( 'image_styles' => array_merge( $base_lib, $derived ) );
	}

	/**
	 * Derive COLOR PRESETS (Theme Settings → Components → Color Presets — the `theme_colors` key, shape
	 * `[ { name, color:#hex }, … ]` read by unysonplus_get_color_presets()) from the source's BRAND palette.
	 * The brand colours are pulled from the captured design tokens (accent/primary → Primary, dark → Dark,
	 * text → Ink, muted → Muted, background → Light, secondary/tertiary → Secondary) — the same lookups the
	 * chrome/theme-design derivation uses — with a markup scan as the accent fallback. The brand entries are
	 * PREPENDED to the plugin default palette (so the referenced default slugs — light-gray / indigo / white,
	 * used by the bundled Box/Icon-Badge presets — stay resolvable), de-duped by hex. Only emitted when a real
	 * brand colour was found (never an empty / default-only palette). A brand "Primary" prepended before the
	 * default "Primary" wins the `primary` slug, so the default button/box presets that reference `primary`
	 * pick up the brand colour. Returns array( 'theme_colors' => [...] ).
	 *
	 * @param array  $tokens design tokens (palette)
	 * @param string $html   source markup (accent scan fallback)
	 * @return array array('theme_colors'=>…) or empty.
	 */
	public static function build_color_presets( array $tokens, $html = '' ) {
		$normc = function ( $h ) {
			$h = strtolower( trim( (string) $h ) );
			// Expand #abc → #aabbcc so short/long forms de-dupe against each other.
			if ( preg_match( '/^#([0-9a-f]{3})$/', $h, $m ) ) {
				$h = '#' . $m[1][0] . $m[1][0] . $m[1][1] . $m[1][1] . $m[1][2] . $m[1][2];
			}
			return $h;
		};
		$brand = array();
		$seen  = array();
		$add   = function ( $name, $hex ) use ( &$brand, &$seen, $normc ) {
			$hex = trim( (string) $hex );
			if ( $hex === '' ) { return; }
			$key = $normc( $hex );
			if ( $key === '' || isset( $seen[ $key ] ) ) { return; }
			$seen[ $key ]  = true;
			$brand[]       = array( 'name' => $name, 'color' => $hex );
		};

		// COMPUTED-STYLE semantic colours — the real brand palette on a rendered SPA (React/shadcn/Wegic:
		// no inline tailwind.config, so parse_tokens() sees nothing, but the capture stamps each element's
		// getComputedStyle onto data-sc-cs). FW_Site_Converter_Tailwind::extract_semantic_colors() pairs a
		// base colour utility (bg-primary / text-foreground / …) with that computed value → name => #hex. We
		// harvest only KNOWN colour ROLES (primary / secondary / accent / foreground / background / muted /
		// dark) so typography/layout utility names that leak through (text-sm, text-center, text-xl → sm /
		// center / xl) are ignored. This is what recovers the FreshPaws green (#21c45d) + dark-green ink
		// (#293d36) that the neutral fallback used to miss.
		$sem = ( $html !== '' && class_exists( 'FW_Site_Converter_Tailwind' ) && method_exists( 'FW_Site_Converter_Tailwind', 'extract_semantic_colors' ) )
			? (array) FW_Site_Converter_Tailwind::extract_semantic_colors( (string) $html )
			: array();
		$sem_of = function ( $names ) use ( $sem ) {
			foreach ( (array) $names as $n ) { if ( ! empty( $sem[ $n ] ) ) { return (string) $sem[ $n ]; } }
			return '';
		};
		// Prefer a real tailwind.config token; fall back to the computed-style semantic colour.
		$pick = function ( $token_keys, $sem_keys ) use ( $tokens, $sem_of ) {
			$v = self::token_color( $tokens, (array) $token_keys );
			return $v !== '' ? $v : $sem_of( $sem_keys );
		};

		// Brand ACTION colour, in priority order: (1) real tailwind.config accent/primary token; (2) the
		// computed-style semantic primary/accent/brand; (3) a vivid markup accent scan; (4) the primary
		// button's real fill (build_button_presets — the CTA bg IS the brand colour); (5) token tertiary/
		// secondary/ink; (6) the neutral fallback ONLY if nothing vivid was found.
		$ink    = $pick( array( 'text', 'on-background', 'on-surface' ), array( 'foreground', 'ink', 'text' ) );
		$accent = $pick( array( 'accent', 'primary', 'brand', 'cta' ), array( 'primary', 'accent', 'brand', 'cta' ) );
		if ( $accent === '' && $html !== '' && method_exists( __CLASS__, 'scan_accent' ) ) { $accent = self::scan_accent( (string) $html ); }
		if ( $accent === '' && $html !== '' && method_exists( __CLASS__, 'build_button_presets' ) ) {
			$bp = self::build_button_presets( (string) $html );
			if ( ! empty( $bp['button_colors'] ) ) {
				foreach ( $bp['button_colors'] as $b ) {
					$bg = isset( $b['states']['default']['bg_color']['custom'] ) ? (string) $b['states']['default']['bg_color']['custom'] : '';
					if ( isset( $b['color_name'] ) && $b['color_name'] === 'Primary' && $bg !== '' ) { $accent = $bg; break; }
				}
			}
		}
		if ( $accent === '' ) { $accent = self::token_color( $tokens, array( 'tertiary', 'secondary', 'on-surface', 'on-background' ) ); }
		if ( $accent === '' ) { $accent = $ink !== '' ? $ink : '#141414'; }

		$add( 'Primary',   $accent );
		$add( 'Ink',       $ink !== '' ? $ink : '#1a1a1a' );
		$add( 'Secondary', $pick( array( 'secondary', 'tertiary' ), array( 'secondary' ) ) );
		$add( 'Accent',    $sem_of( array( 'accent' ) ) );
		$add( 'Dark',      $pick( array( 'deep-black', 'black', 'surface-container-lowest' ), array( 'dark' ) ) );
		$add( 'Muted',     $pick( array( 'muted', 'on-surface-variant' ), array( 'muted' ) ) );
		$add( 'Light',     $pick( array( 'page-bg', 'background', 'surface', 'white-soft' ), array( 'background', 'surface' ) ) );

		// Nothing resolved at all (should never happen — Primary/Ink always fall back) → leave defaults.
		if ( empty( $brand ) ) { return array(); }

		// Slug from a preset name the SAME way css-tokens.php / unysonplus_color_preset_slug_map() derive it.
		$slugify = function ( $name ) {
			return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) $name ) ), '-' );
		};
		// The brand entries OWN their role names/slugs (Primary, Secondary, Accent, Ink, Dark, Light). When
		// prepending the default palette, DROP any default whose slug collides with a brand role — so there's
		// exactly ONE "Primary" (brand), ONE "Secondary" (brand), etc. Defaults with a non-colliding slug the
		// Box/Badge presets reference (light-gray / indigo / white / …) stay so those references keep resolving.
		$brand_slugs = array();
		foreach ( $brand as $b ) { $brand_slugs[ $slugify( $b['name'] ) ] = true; }

		$defaults = function_exists( 'unysonplus_default_color_presets' ) ? unysonplus_default_color_presets() : array();
		$merged   = $brand;
		foreach ( $defaults as $d ) {
			if ( empty( $d['color'] ) ) { continue; }
			if ( isset( $brand_slugs[ $slugify( isset( $d['name'] ) ? $d['name'] : '' ) ] ) ) { continue; } // name/slug collides with a brand role
			$hx  = self::norm_hex( (string) $d['color'] );
			$key = $normc( $hx !== '' ? $hx : $d['color'] );
			if ( isset( $seen[ $key ] ) ) { continue; } // same hex already present under another name
			$seen[ $key ] = true;
			$merged[]     = $d;
		}
		return array( 'theme_colors' => $merged );
	}

	/**
	 * Derive ICON BADGE PRESETS (Theme Settings → Components → Icon Badges — the `icon_badge_presets` key,
	 * the `.iconb-{slug}` tiles read by unysonplus_get_icon_badge_presets()) from the source's icon-in-a-tile
	 * pattern: an icon (`<svg>` / `<i>`) inside a small, roughly-square container that has a background fill
	 * (or a ring) and a corner radius — e.g. FreshPaws' feature-card heart/shield/clock in rounded tiles.
	 * Walks candidate tiles, compiles their Tailwind → fill / radius / size / border + the inner glyph colour,
	 * clusters the distinct designs, and appends the top few (named by shape) to the plugin defaults. Same
	 * DOM→Tailwind-compile→cluster pattern as build_box_presets(). Shape mirrors unysonplus_default_icon_badge_presets():
	 * badge_shape / badge_size{value,unit} / icon_size / border_radius / states{ default:{ background (background-pro),
	 * icon_color (compact), border_* }, hover }. Only emitted when the source actually HAS an icon tile.
	 * Returns array( 'icon_badge_presets' => [...] ).
	 *
	 * @param string $html
	 * @return array array('icon_badge_presets'=>…) or empty.
	 */
	public static function build_icon_badge_presets( $html ) {
		$html = (string) $html;
		if ( $html === '' || ! class_exists( 'FW_Site_Converter_Tailwind' ) ) { return array(); }
		$dom = self::load_dom( $html );
		if ( ! $dom ) { return array(); }
		$body = null;
		foreach ( $dom->getElementsByTagName( 'body' ) as $b ) { $body = $b; break; }
		if ( ! $body ) { return array(); }

		$unit = function ( $v ) {
			$v = trim( (string) $v );
			return preg_match( '/^(-?[0-9.]+)\s*(px|rem|em|%)?$/', $v, $m )
				? array( 'value' => $m[1], 'unit' => ( isset( $m[2] ) && $m[2] !== '' ? $m[2] : 'px' ) )
				: null;
		};
		$px = function ( $v ) { // rough px magnitude of a length (px/rem) for size/shape thresholds
			$v = trim( (string) $v );
			if ( preg_match( '/^(-?[0-9.]+)\s*(px)?$/', $v, $m ) ) { return (float) $m[1]; }
			if ( preg_match( '/^(-?[0-9.]+)\s*rem$/', $v, $m ) ) { return (float) $m[1] * 16; }
			if ( preg_match( '/^(-?[0-9.]+)\s*em$/', $v, $m ) )  { return (float) $m[1] * 16; }
			return null;
		};
		$norm = function ( $c ) {
			$c = strtolower( trim( (string) $c ) );
			$has_var = ( strpos( $c, 'var(' ) !== false );
			if ( preg_match( '/rgba?\(\s*([0-9.]+)[,\s]+([0-9.]+)[,\s]+([0-9.]+)(?:[,\s\/]+([0-9.]+))?/', $c, $m ) ) {
				$a = ( ! $has_var && isset( $m[4] ) && $m[4] !== '' ) ? (float) $m[4] : 1.0;
				if ( $a === 0.0 ) { return ''; }
				return $a < 1 ? "rgba({$m[1]}, {$m[2]}, {$m[3]}, {$a})" : "rgb({$m[1]}, {$m[2]}, {$m[3]})";
			}
			if ( preg_match( '/^#([0-9a-f]{3}|[0-9a-f]{6})$/', $c, $m ) ) {
				$h = $m[1]; if ( strlen( $h ) === 3 ) { $h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2]; }
				return 'rgb(' . hexdec( substr( $h, 0, 2 ) ) . ', ' . hexdec( substr( $h, 2, 2 ) ) . ', ' . hexdec( substr( $h, 4, 2 ) ) . ')';
			}
			return '';
		};

		$tiles = array();
		foreach ( $body->getElementsByTagName( '*' ) as $node ) {
			$tag = strtolower( $node->tagName );
			if ( in_array( $tag, array( 'a', 'button', 'svg', 'path', 'i', 'body', 'section', 'nav', 'header', 'footer', 'main' ), true ) ) { continue; }
			$cls = self::cls( $node );
			if ( $cls === '' ) { continue; }
			// Cheap pre-filter: must read like a tile (rounded / fill / ring).
			if ( ! preg_match( '/(?:^|\s)(rounded|bg-|border|ring)/i', $cls ) ) { continue; }
			// The tile's DIRECT content is essentially just an icon (svg or <i>), no real text.
			$icon = null; $text = '';
			foreach ( $node->childNodes as $ch ) {
				if ( $ch instanceof DOMElement ) {
					$t = strtolower( $ch->tagName );
					if ( $t === 'svg' || $t === 'i' ) { if ( ! $icon ) { $icon = $ch; } }
				} elseif ( $ch instanceof DOMText ) {
					$text .= $ch->wholeText;
				}
			}
			if ( ! $icon ) {
				// One-level-deep single icon wrapper still counts (a <span><svg/></span> inside the tile).
				foreach ( $node->getElementsByTagName( 'svg' ) as $sv ) { $icon = $sv; break; }
				if ( ! $icon ) { foreach ( $node->getElementsByTagName( 'i' ) as $ii ) { $icon = $ii; break; } }
			}
			if ( ! $icon ) { continue; }
			if ( trim( preg_replace( '/\s+/', '', $text ) ) !== '' ) { continue; } // has real text → it's a card, not a badge tile

			$c  = FW_Site_Converter_Tailwind::compile_class_set( $cls );
			$b  = isset( $c['base'] ) ? $c['base'] : array();
			$bg = isset( $b['background-color'] ) ? $norm( (string) $b['background-color'] ) : '';
			$bw = ( isset( $b['border-width'] ) && ! in_array( (string) $b['border-width'], array( '', '0', '0px' ), true ) ) ? (string) $b['border-width'] : '';
			$radius = ( isset( $b['border-radius'] ) && ! in_array( (string) $b['border-radius'], array( '', '0', '0px' ), true ) ) ? (string) $b['border-radius'] : '';
			// A badge tile must have a visible surface (fill or ring). A bare radius on a big card isn't one.
			if ( $bg === '' && $bw === '' ) { continue; }

			$w = isset( $b['width'] ) ? $px( (string) $b['width'] ) : null;
			$h = isset( $b['height'] ) ? $px( (string) $b['height'] ) : null;
			$size = null;
			if ( $w !== null && $h !== null ) { if ( abs( $w - $h ) > max( 6, 0.35 * max( $w, $h ) ) ) { continue; } $size = ( $w + $h ) / 2; }
			elseif ( $w !== null ) { $size = $w; }
			elseif ( $h !== null ) { $size = $h; }
			// Badge tiles are small squares — skip anything that's clearly a full card / section.
			if ( $size !== null && ( $size < 24 || $size > 120 ) ) { continue; }

			// Shape from the corner radius vs the tile size (rounded-full / >=50% → circle).
			$rpx = $radius !== '' ? $px( $radius ) : 0;
			$is_full = ( stripos( $cls, 'rounded-full' ) !== false ) || ( $radius !== '' && ( strpos( $radius, '9999' ) !== false || ( $size !== null && $rpx !== null && $rpx >= $size * 0.5 - 1 ) ) );
			$shape = $is_full ? 'circle' : ( $radius !== '' ? 'rounded' : 'square' );

			// Inner glyph colour: the icon's own color, else the tile's text color.
			$icls = self::cls( $icon );
			$ic   = $icls !== '' ? FW_Site_Converter_Tailwind::compile_class_set( $icls ) : array();
			$icol = isset( $ic['base']['color'] ) ? $norm( (string) $ic['base']['color'] ) : '';
			if ( $icol === '' && isset( $b['color'] ) ) { $icol = $norm( (string) $b['color'] ); }

			$tiles[] = array(
				'shape'  => $shape,
				'size'   => $size !== null ? (int) round( $size ) : 0,
				'radius' => ( $shape === 'rounded' && $radius !== '' ) ? $radius : '',
				'bg'     => $bg,
				'bw'     => $bw,
				'bdcol'  => isset( $b['border-color'] ) ? $norm( (string) $b['border-color'] ) : '',
				'icol'   => $icol,
			);
		}
		if ( empty( $tiles ) ) { return array(); }

		// Cluster the distinct tile designs; keep the most common few.
		$groups = array();
		foreach ( $tiles as $t ) {
			$key = $t['shape'] . '|' . $t['bg'] . '|' . preg_replace( '/\s+/', '', $t['radius'] ) . '|' . $t['bw'] . $t['bdcol'];
			if ( ! isset( $groups[ $key ] ) ) { $groups[ $key ] = array_merge( $t, array( 'count' => 0 ) ); }
			$groups[ $key ]['count']++;
		}
		uasort( $groups, function ( $a, $b ) { return $b['count'] - $a['count']; } );
		$groups = array_slice( $groups, 0, 4, true );

		$empty  = array( 'predefined' => '', 'custom' => '' );
		$fill   = function ( $val ) { return array( 'color' => array( 'value' => array( 'predefined' => '', 'custom' => (string) $val ) ) ); };
		$nofill = array( 'color' => array( 'value' => array( 'predefined' => '', 'custom' => '' ) ) );
		$used   = array(); $n = 0; $derived = array();
		foreach ( $groups as $g ) {
			$base = ( $g['shape'] === 'circle' ) ? 'Circle' : ( $g['shape'] === 'rounded' ? 'Rounded Tile' : 'Square' );
			if ( $g['bg'] === '' && $g['bw'] !== '' ) { $base = ( $g['shape'] === 'circle' ) ? 'Outline Ring' : 'Outline Tile'; }
			$used[ $base ] = ( isset( $used[ $base ] ) ? $used[ $base ] : 0 ) + 1;
			$name = $used[ $base ] > 1 ? $base . ' ' . $used[ $base ] : $base;

			$size = $g['size'] > 0 ? $g['size'] : 48;
			$icon_size = (int) round( $size * 0.5 );
			$default = array(
				'background'   => $g['bg'] !== '' ? $fill( $g['bg'] ) : $nofill,
				'icon_color'   => $g['icol'] !== '' ? array( 'predefined' => '', 'custom' => $g['icol'] ) : $empty,
				'border_style' => $g['bw'] !== '' ? 'solid' : '',
				'border_color' => $g['bw'] !== '' && $g['bdcol'] !== '' ? array( 'predefined' => '', 'custom' => $g['bdcol'] ) : $empty,
			);
			if ( $g['bw'] !== '' ) { $bwu = $unit( $g['bw'] ); if ( $bwu ) { $default['border_width'] = $bwu; } }

			$derived[] = array(
				'id'            => 'i' . str_pad( (string) ( 100 + ( ++$n ) ), 9, '0', STR_PAD_LEFT ),
				'preset_name'   => $name,
				'badge_shape'   => $g['shape'],
				'badge_size'    => array( 'value' => (string) $size, 'unit' => 'px' ),
				'icon_size'     => array( 'value' => (string) $icon_size, 'unit' => 'px' ),
				'border_radius' => ( $g['shape'] === 'rounded' && $g['radius'] !== '' && $unit( $g['radius'] ) ) ? $unit( $g['radius'] ) : array( 'value' => '', 'unit' => 'px' ),
				'transition'    => '200',
				'hover_fx'      => array(),
				'custom_css'    => '',
				'states'        => array( 'default' => $default, 'hover' => array() ),
			);
		}
		if ( empty( $derived ) ) { return array(); }
		$base_lib = function_exists( 'unysonplus_default_icon_badge_presets' ) ? unysonplus_default_icon_badge_presets() : array();
		return array( 'icon_badge_presets' => array_merge( $base_lib, $derived ) );
	}

	public static function tokens_to_theme_settings_chrome( array $tokens, $html, $title ) {
		$el  = function ( $type, $settings = null ) {
			$et = array( 'element' => $type );
			if ( is_array( $settings ) ) { $et[ $type ] = $settings; }
			return array( 'element_type' => $et );
		};
		$hex = function ( $h ) { return array( 'predefined' => '', 'custom' => (string) $h ); };

		$hdr   = self::detect_header( (string) $html );
		$logo  = self::detect_logo( (string) $html, (string) $title );
		$menu  = self::design_menu( (string) $html, 'primary' );

		// Palette-derived chrome colors (fall back to sensible neutrals).
		$ink        = self::token_color( $tokens, array( 'text', 'on-background', 'on-surface' ) );
		$accent     = self::token_color( $tokens, array( 'accent', 'primary', 'brand', 'cta' ) );
		$dark       = self::token_color( $tokens, array( 'deep-black', 'black', 'surface-container-lowest' ) );
		if ( $dark === '' ) { $dark = '#141414'; }
		$muted      = self::token_color( $tokens, array( 'muted', 'on-surface-variant' ) );
		$link_col   = $muted !== '' ? $muted : ( $ink !== '' ? $ink : '#94a3b8' );
		$header_bg  = $hdr['dark'] ? $dark : ( $ink !== '' ? '#ffffff' : '#ffffff' );

		$values = array();

		/* --- header_logo — faithful to the SOURCE brand. An icon+wordmark lockup → logo_type 'custom' (the
		   real inline SVG mark + wordmark, in their source colors + optional colored frame tile). A pure
		   image logo → logo_type 'simple' (portable via the upload shape; the media pipeline resolves it).
		   Emitted in the NESTED multi-picker shape so it BOTH renders (unysonplus_header_logo_cfg flattens
		   it) AND pre-populates the Theme Settings → Header → Identity UI. --- */
		$site_title = $logo['text'] !== '' ? $logo['text'] : ( trim( (string) $title ) !== '' ? trim( (string) $title ) : 'Site' );
		$title_color = $logo['title_color'] !== '' ? $logo['title_color'] : ( $hdr['dark'] ? '#ffffff' : ( $ink !== '' ? $ink : '#111111' ) );

		$logo_custom = array(
			'site_title'   => $site_title,
			'logo_layout'  => 'inline-left',
			// Wordmark weight/size from the MEASURED span (fall back to a bold default when unmeasured).
			'title_weight' => ( $logo['title_weight'] !== '' ? $logo['title_weight'] : '700' ),
			'color'        => $hex( $title_color ),
		);
		if ( $logo['title_size'] !== '' ) {
			$ts = self::css_len_to_unit( $logo['title_size'] );
			if ( $ts ) { $logo_custom['title_size'] = $ts; }
		}
		// The icon mark: inline SVG (verbatim) preferred, else a Lucide library id.
		if ( $logo['svg'] !== '' ) {
			$logo_custom['logo_icon'] = array( 'type' => 'svg', 'svg-source' => 'inline', 'markup' => $logo['svg'] );
		} elseif ( $logo['icon'] !== '' ) {
			$logo_custom['logo_icon'] = array( 'type' => 'svg', 'svg-source' => 'library', 'svg-id' => $logo['icon'] );
		}
		if ( isset( $logo_custom['logo_icon'] ) ) {
			$icon_col = $logo['icon_color'] !== '' ? $logo['icon_color'] : ( $accent !== '' ? $accent : $title_color );
			$logo_custom['logo_icon_color'] = $hex( $icon_col );
			// The measured icon glyph size (e.g. `w-6` = 24px). Drives the mark + (scaled) frame tile.
			if ( $logo['icon_size'] !== '' ) {
				$is = self::css_len_to_unit( $logo['icon_size'] );
				if ( $is ) { $logo_custom['logo_icon_size'] = $is; }
			}
			// A colored tile behind the mark (e.g. FreshPaws' green paw tile) — shape inferred from its radius.
			if ( $logo['frame'] !== 'none' && $logo['frame_bg'] !== '' ) {
				$logo_custom['logo_icon_frame']    = in_array( $logo['frame'], array( 'circle', 'squircle', 'rounded', 'square' ), true ) ? $logo['frame'] : 'rounded';
				$logo_custom['logo_icon_frame_bg'] = $hex( $logo['frame_bg'] );
			}
		}
		// TWO-TONE wordmark residual: a single `color` field can't split "Fresh"(dark)+"Paws"(accent). The base
		// tone maps natively above; emit the accent as scoped CSS on the wordmark's trailing text (documented
		// residual path — header.md logo_custom_css hooks). Best-effort: colors the emphasized run if the theme
		// wraps it; otherwise it is a no-op (never wrong, never double-applied to a native field).
		if ( $logo['title_accent_color'] !== '' && $logo['title_accent_color'] !== $title_color ) {
			$logo_custom['logo_custom_css'] = '.site-title-text .accent,.site-title-text b,.site-title-text strong{color:' . $logo['title_accent_color'] . '}';
			// SPLIT the wordmark so the scoped CSS above has something to paint: wrap the measured accent run
			// (e.g. "Paws") in `<span class="accent">`, leaving the ink part bare. The theme prints site_title
			// RAW inside `.site-title-text` (helpers.php), so this two-tone lockup renders natively — richer than
			// a single-color `site_title`. Falls back to the plain wordmark when no accent run was measured.
			$acc = $logo['title_accent_text'];
			if ( $acc !== '' && $site_title !== '' && ( $pos = strpos( $site_title, $acc ) ) !== false ) {
				$ink_part = substr( $site_title, 0, $pos );
				$tail     = substr( $site_title, $pos + strlen( $acc ) );
				$logo_custom['site_title'] = esc_html( $ink_part ) . '<span class="accent">' . esc_html( $acc ) . '</span>' . esc_html( $tail );
			}
		}

		$logo_type = ( $logo['text'] === '' && $logo['image'] !== '' ) ? 'simple' : 'custom';
		$logo_simple = array();
		if ( $logo['image'] !== '' ) {
			// Portable upload shape; attachment_id is resolved when the media pipeline sideloads it.
			$logo_simple['image'] = array( 'url' => $logo['image'], 'attachment_id' => 0 );
			$logo_simple['alt']   = $site_title;
		}
		$values['header_logo'] = array(
			'logo_type' => array(
				'logo_type' => $logo_type,
				'custom'    => $logo_custom,
				'simple'    => $logo_simple,
			),
		);

		/* --- header_main: logo · menu · CTA --- */
		$right = array();
		if ( $hdr['cta']['label'] !== '' ) {
			$right[] = array( 'element_type' => array(
				'element'    => 'cta_button',
				'cta_button' => array(
					'cta_text'  => $hdr['cta']['label'],
					'cta_link'  => $hdr['cta']['href'] !== '' ? $hdr['cta']['href'] : '#',
					'cta_style' => ( ! empty( $hdr['cta']['style'] ) ? $hdr['cta']['style'] : 'btn-primary' ),
					'cta_size'  => 'btn-md',
				),
			) );
		}
		$values['header_main'] = array(
			'main_left'   => array( $el( 'logo' ) ),
			'main_center' => array( $el( 'menu_area', array( 'menu_location' => 'primary' ) ) ),
			'main_right'  => $right,
		);

		/* --- header chrome + nav styles read from the captured computed styles (data-sc-cs) --- */
		$hstyle = self::detect_header_chrome_styles( (string) $html );
		$mstyle = self::detect_menu_styles( (string) $html );

		/* --- header_menu --- default colors, overridden by the REAL nav link styles when detected. --- */
		$menu = array(
			'menu_link_color'       => $hex( isset( $mstyle['link_color'] ) ? $mstyle['link_color'] : $link_col ),
			'menu_link_hover_color' => $hex( isset( $mstyle['hover_color'] ) ? $mstyle['hover_color'] : ( $hdr['dark'] ? '#ffffff' : ( $accent !== '' ? $accent : $ink ) ) ),
		);
		if ( isset( $mstyle['font_size'] ) )   { $menu['menu_link_font_size'] = $mstyle['font_size']; }
		if ( isset( $mstyle['font_weight'] ) ) { $menu['menu_link_font_weight'] = $mstyle['font_weight']; }
		$values['header_menu'] = $menu;

		/* --- header_layout — switches/bg driven by the detected header chrome (only override defaults on a
		   real signal, so we never write a false 'yes'). --- */
		$header_bg_val = isset( $hstyle['bg'] ) ? $hstyle['bg'] : $header_bg;
		$values['header_layout'] = array(
			'header_mode'          => array( 'mode' => 'top', 'top' => array( 'header_design' => array( 'design' => 'classic' ) ) ),
			'header_behavior'      => $hdr['sticky'] ? 'sticky' : 'static',
			'header_glass'         => ! empty( $hstyle['glass'] ) ? 'yes' : 'no',
			'header_shadow'        => ! empty( $hstyle['shadow'] ) ? 'yes' : 'no',
			'header_border'        => ! empty( $hstyle['border'] ) ? 'yes' : 'no',
			'header_uppercase_nav' => ! empty( $mstyle['uppercase'] ) ? 'yes' : 'no',
			'bg_color'             => $hex( $header_bg_val ),
		);
		// Mobile breakpoint — the width at which the inline nav collapses to a drawer (only on a real signal).
		if ( ! empty( $hstyle['mobile_breakpoint'] ) ) {
			$values['header_layout']['mobile_breakpoint'] = $hstyle['mobile_breakpoint'];
		}
		// HEADER container width — from the header's INNER content wrapper (.container / mx-auto / max-w-*)
		// computed max-width, NOT the <header> element. A real capped px → Fixed Width (the header then
		// INHERITS the SITE-WIDE Container Width set below, so header/footer/body all share ONE width and
		// stay consistent — we no longer stamp a per-header numeric container_width, which used to render
		// 2×gutter too WIDE); a full-bleed inner wrapper → Full Width. Only set on a real signal.
		// See theme-settings/header.md → container / container_width.
		if ( isset( $hstyle['container'] ) && $hstyle['container'] !== '' ) {
			if ( $hstyle['container'] === 'fluid' ) {
				$values['header_layout']['container'] = 'container-fluid';
			} elseif ( is_numeric( $hstyle['container'] ) ) {
				$values['header_layout']['container'] = 'container';
				// container_width intentionally left UNSET → inherits --container-max-desktop (the site width).
			}
		}

		/* --- footer colors (background-pro shape for the fill) --- read the SOURCE footer's real colors
		   from its captured computed style, falling back to palette-dark only when unknown. --- */
		$fstyle      = self::detect_footer_style( (string) $html );
		$footer_bg   = $fstyle['bg'] !== '' ? $fstyle['bg'] : $dark;
		$footer_text = $fstyle['text'] !== '' ? $fstyle['text'] : '#94a3b8';
		$values['footer_background'] = array( 'color' => array( 'value' => array( 'predefined' => '', 'custom' => $footer_bg ) ) );
		$values['footer_text_color'] = $hex( $footer_text );
		$values['footer_link_color'] = $hex( $footer_text );

		/* --- footer chrome (padding / top border) → native footer_layout options, from computed styles. --- */
		$fchrome = self::detect_footer_chrome_styles( (string) $html );
		if ( isset( $fchrome['pad_top'] ) )    { $r = self::css_len_to_rem( $fchrome['pad_top'] );    if ( $r !== '' ) { $values['footer_padding_top'] = $r; } }
		if ( isset( $fchrome['pad_bottom'] ) ) { $r = self::css_len_to_rem( $fchrome['pad_bottom'] ); if ( $r !== '' ) { $values['footer_padding_bottom'] = $r; } }
		if ( isset( $fchrome['border'] ) && is_array( $fchrome['border'] ) ) {
			$bw = self::css_len_to_unit( $fchrome['border']['width'] );
			if ( $bw ) {
				$values['footer_border_top']       = array( 'width' => $bw, 'style' => ( $fchrome['border']['style'] !== '' ? $fchrome['border']['style'] : 'solid' ), 'color' => $hex( $fchrome['border']['color'] ) );
				$values['footer_border_sides']     = array( 'top' );
				$values['footer_border_top_extent'] = array( 'mode' => 'full' );
			}
		}

		// Footer LOGO legibility: the footer `logo` element renders the SAME lockup as the header, which
		// carries the header's (often DARK) wordmark/mark color — invisible on a dark footer. Scope the footer
		// brand to the footer text color so it reads LIGHT. The framed icon tile (its own bg + mark) is left
		// alone (it's designed to contrast). This is the native model's footer-scoped-CSS escape hatch.
		$residual = array();
		$residual[] = ".footer .site-title-text,.footer .site-logo__eyebrow,.footer .site-logo__sub{color:{$footer_text} !important}";
		$residual[] = ".footer .site-logo__mark:not(.site-logo__mark--framed){color:{$footer_text} !important}";
		// RESIDUAL (no native option): a rounded footer top (e.g. `rounded-t-[3rem]`) → scoped CSS. Not
		// double-applied (there is no native footer border-radius option). Header chrome with no option can
		// join here too; for now only the footer top-radius is emitted when the source has one.
		if ( isset( $fchrome['radius'] ) && $fchrome['radius'] !== '' ) {
			// Keep only the TOP corners (a footer rounds its top edge onto the page above it).
			$parts = preg_split( '/\s+/', trim( (string) $fchrome['radius'] ) );
			$tl = isset( $parts[0] ) ? $parts[0] : '0';
			$tr = isset( $parts[1] ) ? $parts[1] : $tl;
			if ( self::css_len_present( $tl ) || self::css_len_present( $tr ) ) {
				$residual[] = "#colophon .footer,#colophon.footer,.footer{border-top-left-radius:{$tl};border-top-right-radius:{$tr}}";
			}
		}
		// FOOTER container width — from the footer's INNER content wrapper computed max-width (same detection as
		// the header). The footer's native content-width control is the per-bar Custom Styling `container`
		// (Fixed Width / Full Width) — footer.md documents no per-bar NUMERIC width: a Fixed-Width footer bar
		// caps at the SITE-WIDE Container Width. So: full-bleed → container-fluid; a real capped width →
		// 'container' (Fixed Width, centered) on the Main Footer + Copyright bars, which then INHERIT the
		// site-wide Container Width set below (header/footer/body all share ONE width). We no longer emit a
		// footer-scoped numeric cap — the old `calc(px + 2*gutter)` rule rendered 2×gutter too wide vs source.
		if ( isset( $fchrome['container'] ) && $fchrome['container'] !== '' ) {
			$fluid = ( $fchrome['container'] === 'fluid' );
			$fcontainer = $fluid ? 'container-fluid' : 'container';
			foreach ( array( 'main_footer_custom_styling', 'copyright_custom_styling' ) as $ck ) {
				$prefix = ( $ck === 'main_footer_custom_styling' ) ? 'main_footer' : 'copyright';
				$values[ $ck ] = array( 'enabled' => 'yes', 'yes' => array( $prefix . '_container' => $fcontainer ) );
			}
		}

		/* --- SITE-WIDE Container Width — the ONE width header, footer AND body sections all share ---
		   The source's header/footer inner content wrapper caps at the source `.container` (e.g. 1280px), and
		   the body sections use that SAME source container. Map it to the theme's GLOBAL Container Width
		   (general_layout → layout_container_width → --container-max-desktop) so BODY sections finally match the
		   chrome — previously never set, so body fell back to the theme's 1170px default (rendered ~1218px).
		   The detected value is the source container BOX (its max-width, which INCLUDES the source's own
		   gutters); UnysonPlus's Container Width is a CONTENT width the theme ADDS gutters OUTSIDE of (rendered
		   .fw-container box = content + 2 × --container-gutter). Convert box→content so the rendered box equals
		   the source box across header, footer AND sections — all inheriting this single value. */
		$site_box = 0;
		foreach ( array( isset( $hstyle['container'] ) ? $hstyle['container'] : '', isset( $fchrome['container'] ) ? $fchrome['container'] : '' ) as $cwv ) {
			if ( is_numeric( $cwv ) ) { $site_box = max( $site_box, (int) $cwv ); }
		}
		$container_ladder_css = '';
		if ( $site_box > 0 ) {
			$content_w = self::chrome_box_to_content_px( $site_box );
			$values['general_layout']['layout_container_width'] = array(
				'base' => array( 'value' => '100', 'unit' => '%' ),
				'md'   => array( 'value' => '720', 'unit' => 'px' ),
				'lg'   => array( 'value' => (string) $content_w, 'unit' => 'px' ),
			);
			/* RESPONSIVE container LADDER. Tailwind's `.container` isn't a single max-width — it steps UP at
			   each breakpoint (sm 640 / md 768 / lg 1024 / xl 1280 / 2xl 1536). The capture runs at ONE
			   viewport (1440), so `.container` computes to its xl step (1280) and $site_box captures only that
			   — at ≥1536 the source expands to 1536 while a single-width map stays capped at 1280. The native
			   Container Width option only has base/md/lg tiers (no xl/2xl), so we emit the tiers ABOVE lg as
			   scoped @media CSS. We target the .fw-container BOX directly (not `:root{--container-max-desktop}`):
			   the theme emits an UNCONDITIONAL `:root{--container-max-desktop:<lg>}` from Theme Settings
			   (theme-vars.php) into wp_head. A `@media{:root{--container-max-desktop:…}}` override has the SAME
			   selector specificity (0,1,0) and media queries add NO specificity, so whichever `:root` rule comes
			   LATER in source order wins — the theme's unconditional block lands after this misc_custom_css, so the
			   var override silently LOST and the container stayed at the lg width (1280) even at 1920. Instead we
			   emit the SAME calc the theme's `body .fw-container` rule uses (content + 2*gutter), scoped to the real
			   container selectors with !important, so the RENDERED box wins at ≥xl regardless of the var cascade.
			   Only when the source genuinely uses the literal Tailwind `container` class (a responsive ladder) AND
			   its measured cap sits ON a Tailwind step — a fixed `max-w-[..]` cap stays a single width. */
			if ( self::site_uses_tw_container( (string) $html ) ) {
				$tw_steps = array( 640, 768, 1024, 1280, 1536 );
				if ( in_array( $site_box, $tw_steps, true ) ) {
					$gutter = 'var(--container-gutter, clamp(1.25rem, 3vw, 2rem))';
					$lines  = array();
					foreach ( $tw_steps as $bp ) {
						if ( $bp <= $site_box ) { continue; } // tiers up to $site_box are covered by the lg map
						$content = self::chrome_box_to_content_px( $bp );
						$lines[] = '@media (min-width:' . $bp . 'px){body .fw-container,body .container,body .site-header .fw-container{max-width:calc(' . $content . 'px + 2 * ' . $gutter . ') !important;}}';
					}
					if ( $lines ) { $container_ladder_css = "\n/* Tailwind .container responsive ladder (tiers above lg) */\n" . implode( "\n", $lines ); }
				}
			}
		}
		$values['misc_custom_css'] = array( 'custom_css' => "/* converted header/footer styles */\n" . implode( "\n", $residual ) . $container_ladder_css );

		/* --- social_profiles (brand column) — footer social links → Lucide icons --- */
		$social = self::detect_footer_social( (string) $html );
		if ( $social ) { $values['social_profiles'] = $social; }

		/* --- main_footer_columns: brand col + link columns (source footer grid) --- */
		$fcols = self::detect_footer_columns( (string) $html );
		if ( $fcols ) {
			// A brand column (logo + description + social) leads, then the link columns.
			$brand_col = array( $el( 'logo' ) );
			$fdesc     = self::detect_footer_tagline( (string) $html );
			if ( $fdesc !== '' ) {
				$brand_col[] = array( 'element_type' => array( 'element' => 'text', 'text' => array( 'text_content' => '<p>' . esc_html( $fdesc ) . '</p>' ) ) );
			}
			if ( $social ) { $brand_col[] = $el( 'social_icons' ); }

			$cols  = array( $brand_col );
			foreach ( $fcols as $group ) {
				if ( ( $group['kind'] ?? '' ) === 'contact' && ! empty( $group['rows'] ) ) {
					// A CONTACT column → an icon-list: each row keeps its leading svg (map-pin/phone/mail) tinted
					// its source color (the brand-green `text-primary`) + the value with line breaks preserved.
					// This BEATS the hand-built demo (which dropped these rows) — a native leading-icon contact list.
					$html_col = '<h4>' . esc_html( $group['title'] ) . '</h4><ul class="fw-footer-contact">';
					foreach ( $group['rows'] as $r ) {
						$mark = '';
						if ( $r['icon'] !== '' ) {
							$style = $r['color'] !== '' ? ' style="color:' . esc_attr( $r['color'] ) . '"' : '';
							$mark  = '<span class="fw-ci-icon"' . $style . '>' . $r['icon'] . '</span> ';
						}
						$html_col .= '<li class="fw-ci-row">' . $mark . '<span class="fw-ci-text">' . $r['html'] . '</span></li>';
					}
					$html_col .= '</ul>';
					$cols[] = array( array( 'element_type' => array( 'element' => 'text', 'text' => array( 'text_content' => $html_col ) ) ) );
					continue;
				}
				$html_col = '<h4>' . esc_html( $group['title'] ) . '</h4><ul>';
				if ( ! empty( $group['links'] ) ) {
					// A nav column → a real link list (heading + <a>s).
					foreach ( $group['links'] as $l ) { $html_col .= '<li><a href="' . esc_url( $l['href'] !== '' ? $l['href'] : '#' ) . '">' . esc_html( $l['label'] ) . '</a></li>'; }
				} else {
					// A text column (Services list) → a plain item list.
					foreach ( $group['items'] as $it ) { $html_col .= '<li>' . esc_html( $it ) . '</li>'; }
				}
				$html_col .= '</ul>';
				$cols[] = array( array( 'element_type' => array( 'element' => 'text', 'text' => array( 'text_content' => $html_col ) ) ) );
			}
			$cols  = array_slice( $cols, 0, 6 ); // footer builder supports up to a 6-col row
			$count = count( $cols );
			$mfc   = array();
			for ( $i = 0; $i < $count; $i++ ) { $mfc[ 'main_footer_col_' . ( $i + 1 ) ] = $cols[ $i ]; }
			// Column count = the REAL number of columns (never a fifths grid with an empty trailing slot).
			// Ratio from the source grid's true widths: EQUAL by default (a `grid-cols-N` / equal-track
			// footer), only weighting the first column when the brand is genuinely ~1.5-2x wider.
			$wide = self::detect_footer_wide_brand( (string) $html, $count );
			if ( $count >= 2 && 5 !== $count ) {
				// 2/3/4/6 columns carry the ratio on the twelfths split-slider (segments sum to 100).
				$mfc['main_footer_split'] = self::footer_split_segments( $count, $wide );
			} elseif ( 5 === $count ) {
				// 5 real columns use the fifths image-picker, equal (a spanning fifths comp would render
				// FEWER than 5 physical columns and orphan col_5 — the very bug we're fixing).
				$mfc['main_footer_layout'] = '5-equal';
			}
			$count_key = (string) $count;
			$values['main_footer_columns'] = array( 'count' => $count_key, $count_key => $mfc );
		}

		/* --- copyright bar --- */
		$copy = self::detect_footer_copyright( (string) $html );
		if ( $copy === '' ) { $copy = '&copy; {{current_year}} ' . esc_html( $site_title ) . '. All rights reserved.'; }
		$copyright_col_1 = array(
			array( 'element_type' => array( 'element' => 'text', 'text' => array( 'text_content' => $copy ) ) ),
		);
		// Legal links (Privacy / Terms …) beside the © line → the copyright bar's 2nd column (auto-aligns
		// left © + right links). Without this they were swallowed into the © text as one run-on blob.
		$legal = self::detect_footer_legal_links( (string) $html );
		if ( count( $legal ) >= 1 ) {
			// Inline links (no <ul> — its bullets would float in the copyright bar); a middot separates them.
			$parts = array();
			foreach ( $legal as $l ) { $parts[] = '<a href="' . esc_url( $l['href'] !== '' ? $l['href'] : '#' ) . '">' . esc_html( $l['label'] ) . '</a>'; }
			$legal_html = '<p>' . implode( ' &middot; ', $parts ) . '</p>';
			$copyright_columns = array(
				'count' => '2',
				'2'     => array(
					'copyright_col_1' => $copyright_col_1,
					'copyright_col_2' => array(
						array( 'element_type' => array( 'element' => 'text', 'text' => array( 'text_content' => $legal_html ) ), 'element_css_class' => 'text-end' ),
					),
				),
			);
		} else {
			$copyright_columns = array(
				'count' => '1',
				'1'     => array( 'copyright_col_1' => $copyright_col_1 ),
			);
		}
		$values['copyright_settings'] = array(
			'enabled' => 'yes',
			'yes'     => array( 'copyright_columns' => $copyright_columns ),
		);

		/* --- button_colors / button_sizes: presets derived from the source's real button skin --- */
		$btn_presets = self::build_button_presets( (string) $html );
		if ( ! empty( $btn_presets ) ) {
			$values = array_merge( $values, $btn_presets );
			// A Large size preset exists → point the header CTA at it (matches the source's chunky CTA).
			if ( ! empty( $btn_presets['button_sizes'] ) && ! empty( $values['header_main']['main_right'] ) ) {
				foreach ( $values['header_main']['main_right'] as &$node ) {
					if ( isset( $node['element_type']['cta_button']['cta_size'] ) && $node['element_type']['cta_button']['cta_size'] === 'btn-md' ) {
						$node['element_type']['cta_button']['cta_size'] = 'btn-lg';
					}
				}
				unset( $node );
			}
		}

		/* --- section_style_presets: reusable band skins clustered from the source's sections --- */
		$sec_presets = self::build_section_style_presets( (string) $html );
		if ( ! empty( $sec_presets ) ) {
			$values = array_merge( $values, $sec_presets );
		}

		/* --- border_presets (Box Presets): reusable card/box skins clustered from the source --- */
		$box_presets = self::build_box_presets( (string) $html );
		if ( ! empty( $box_presets ) ) {
			$values = array_merge( $values, $box_presets );
		}

		/* --- font_sizes (Text Styles): display scale + eyebrow derived from the source's headings --- */
		$text_styles = self::build_text_styles( (string) $html );
		if ( ! empty( $text_styles ) ) {
			$values = array_merge( $values, $text_styles );
		}

		/* --- image_styles (Image Styles): radius / circle / aspect / filter from the source's images --- */
		$img_styles = self::build_image_styles( (string) $html );
		if ( ! empty( $img_styles ) ) {
			$values = array_merge( $values, $img_styles );
		}

		/* --- theme_colors (Color Presets): the source's brand palette → editable Components colours --- */
		$color_presets = self::build_color_presets( $tokens, (string) $html );
		if ( ! empty( $color_presets ) ) {
			$values = array_merge( $values, $color_presets );
		}

		/* --- icon_badge_presets (Icon Badges): the source's icon-in-a-tile pattern → editable badge presets --- */
		$badge_presets = self::build_icon_badge_presets( (string) $html );
		if ( ! empty( $badge_presets ) ) {
			$values = array_merge( $values, $badge_presets );
		}

		/* --- spacing_scale (Components → Spacing): the source's spacing steps → editable scale (so the
		   converted site carries a real scale instead of falling back to the theme default). --- */
		$spacing = self::build_spacing_scale( $tokens, (string) $html );
		if ( ! empty( $spacing ) ) {
			$values['spacing_scale'] = $spacing;
		}

		/* --- TYPOGRAPHY (General → Typography): map the SOURCE type system into native Theme-Settings so the
		   converted site carries real fonts + a heading scale (not just the child-theme --font-* tokens). The
		   families come from the same source-of-truth tokens_to_design_config() uses (tailwind fontFamily +
		   the Google-Fonts URL); the sizes/weights/line-heights/tracking come from the MEASURED computed styles
		   (data-sc-cs) of the source's real <p> and h1–h6. Only levels the source actually renders are set —
		   an undetected heading keeps the theme default. Shapes per theme-settings/typography.md: heading_font
		   = { family }, body/h1–h6 = { family, variation, size:{value,unit}, line-height, letter-spacing, color }. */
		list( $ty_head, $ty_body ) = self::pick_fonts_raw( $tokens );
		$ty_google = '';
		foreach ( ( $tokens['fonts'] ?? array() ) as $href ) { $ty_google = $href; break; }
		$ty_gf = self::fonts_from_google( $ty_google );
		if ( $ty_head === '' && isset( $ty_gf[0] ) ) { $ty_head = $ty_gf[0]; }
		if ( $ty_body === '' ) { $ty_body = isset( $ty_gf[1] ) ? $ty_gf[1] : ( isset( $ty_gf[0] ) ? $ty_gf[0] : '' ); }
		$ty = self::detect_typography( (string) $html );
		// Fallback to the MEASURED families when the token/Google-URL path yielded none (a captured DOM carries
		// the resolved font-family on data-sc-cs even when no tailwind.config / Google <link> was extracted).
		if ( $ty_head === '' ) {
			foreach ( array( 'h1', 'h2', 'h3' ) as $lvl ) { if ( ! empty( $ty[ $lvl ]['family'] ) ) { $ty_head = $ty[ $lvl ]['family']; break; } }
		}
		if ( $ty_body === '' && ! empty( $ty['body']['family'] ) ) { $ty_body = $ty['body']['family']; }
		/* The Typography options live INSIDE the `typography` multi container (General → Typography). css-tokens
		   reads fw_get_db_settings_option('typography') and looks up ['heading_font'] / ['body'] / ['h1'..] on
		   THAT array — so the values MUST be nested under $values['typography'][...], not stored flat at the
		   settings root (a flat store reads back via fw_get_db_settings_option('heading_font') but is invisible
		   to unysonplus_typography_config, so --font-heading / the h1–h6 scale would never emit). */
		$typo = array();
		// Heading Font — family only (empty inherits body). Loads via css-tokens `google` list.
		if ( $ty_head !== '' ) { $typo['heading_font'] = array( 'family' => $ty_head ); }
		// Body Font & Text — family + measured base size/line-height/letter-spacing.
		if ( $ty_body !== '' || ! empty( $ty['body'] ) ) {
			$body_val = array( 'family' => $ty_body, 'variation' => 'regular', 'color' => '' );
			if ( isset( $ty['body']['size'] ) )          { $body_val['size'] = array( 'value' => (string) $ty['body']['size'], 'unit' => 'px' ); }
			if ( isset( $ty['body']['line-height'] ) )    { $body_val['line-height'] = $ty['body']['line-height']; }
			if ( isset( $ty['body']['letter-spacing'] ) ) { $body_val['letter-spacing'] = $ty['body']['letter-spacing']; }
			$typo['body'] = $body_val;
		}
		// Per-heading scale H1–H6 — only levels the source actually uses. `variation` carries the weight
		// (the typography option stores weight/style in variation); family left '' to inherit the Heading Font
		// unless the source heading uses a DIFFERENT family than the heading font.
		foreach ( array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ) as $lvl ) {
			if ( empty( $ty[ $lvl ] ) ) { continue; }
			$h = $ty[ $lvl ];
			$hv = array( 'family' => '', 'variation' => ( isset( $h['weight'] ) && $h['weight'] !== '' && (int) $h['weight'] !== 400 ? (string) $h['weight'] : 'regular' ), 'color' => '' );
			if ( isset( $h['family'] ) && $h['family'] !== '' && strcasecmp( $h['family'], $ty_head ) !== 0 ) { $hv['family'] = $h['family']; }
			if ( isset( $h['size'] ) )            { $hv['size'] = array( 'value' => (string) $h['size'], 'unit' => 'px' ); }
			if ( isset( $h['line-height'] ) )     { $hv['line-height'] = $h['line-height']; }
			if ( isset( $h['letter-spacing'] ) )  { $hv['letter-spacing'] = $h['letter-spacing']; }
			$typo[ $lvl ] = $hv;
		}
		if ( ! empty( $typo ) ) { $values['typography'] = $typo; }

		return array( 'values' => $values );
	}

	/**
	 * Read the SOURCE type system from captured computed styles (data-sc-cs): the base body run (the densest
	 * <p>) and each heading level h1–h6 actually present. Returns { body: {size,line-height,letter-spacing,
	 * family}, h1..h6: {size,weight,line-height,letter-spacing,family} } with ONLY the keys measured — an
	 * unmeasured level/prop is absent so the caller leaves the theme default. Sizes = px int; line-height =
	 * unitless ratio (computed px ÷ font-size, rounded); letter-spacing = px number ('normal' → 0, dropped);
	 * family = the first family name in the computed stack (deals with `'Nunito', sans-serif`).
	 */
	private static function detect_typography( $html ) {
		$out = array();
		$dom = self::load_dom( (string) $html );
		if ( ! $dom ) { return $out; }
		$first_family = function ( $stack ) {
			$stack = trim( (string) $stack );
			if ( $stack === '' ) { return ''; }
			$one = trim( (string) preg_split( '/\s*,\s*/', $stack )[0] );
			$one = trim( $one, "\"' " );
			// Drop generic keywords — they carry no web font.
			if ( $one === '' || in_array( strtolower( $one ), array( 'inherit', 'initial', 'sans-serif', 'serif', 'monospace', 'system-ui', '-apple-system', 'ui-sans-serif', 'ui-serif' ), true ) ) { return ''; }
			return $one;
		};
		$lh_ratio = function ( $lh, $size ) {
			$lh = trim( (string) $lh ); $size = (float) $size;
			if ( $lh === '' || strtolower( $lh ) === 'normal' || $size <= 0 ) { return ''; }
			if ( preg_match( '/^([0-9.]+)px$/', $lh, $m ) ) { return (string) round( ( (float) $m[1] ) / $size, 2 ); }
			if ( preg_match( '/^([0-9.]+)$/', $lh, $m ) ) { return (string) round( (float) $m[1], 2 ); } // already unitless
			return '';
		};
		$ls_px = function ( $ls ) {
			$ls = trim( (string) $ls );
			if ( $ls === '' || strtolower( $ls ) === 'normal' ) { return ''; }
			if ( preg_match( '/^(-?[0-9.]+)px$/', $ls, $m ) ) { $v = round( (float) $m[1], 2 ); return abs( $v ) < 0.01 ? '' : (string) $v; }
			return '';
		};

		// BODY — the densest paragraph (most text), a real content run rather than a caption.
		$best_p = null; $best_len = 0;
		foreach ( $dom->getElementsByTagName( 'p' ) as $p ) {
			$cs = ( $p instanceof DOMElement ) ? (string) $p->getAttribute( 'data-sc-cs' ) : '';
			if ( $cs === '' ) { continue; }
			$len = strlen( trim( self::text( $p ) ) );
			if ( $len > $best_len ) { $best_len = $len; $best_p = $p; }
		}
		if ( $best_p ) {
			$sz = self::sc_css( $best_p, 'font-size' );
			$body = array();
			if ( preg_match( '/^([0-9.]+)px$/', trim( (string) $sz ), $m ) ) { $body['size'] = (int) round( (float) $m[1] ); }
			$fam = $first_family( self::sc_css( $best_p, 'font-family' ) );
			if ( $fam !== '' ) { $body['family'] = $fam; }
			$lh = $lh_ratio( self::sc_css( $best_p, 'line-height' ), isset( $body['size'] ) ? $body['size'] : 0 );
			if ( $lh !== '' ) { $body['line-height'] = $lh; }
			$ls = $ls_px( self::sc_css( $best_p, 'letter-spacing' ) );
			if ( $ls !== '' ) { $body['letter-spacing'] = $ls; }
			if ( $body ) { $out['body'] = $body; }
		}

		// HEADINGS h1–h6 — the FIRST occurrence of each level with a computed style + text.
		foreach ( array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ) as $lvl ) {
			foreach ( $dom->getElementsByTagName( $lvl ) as $h ) {
				$cs = ( $h instanceof DOMElement ) ? (string) $h->getAttribute( 'data-sc-cs' ) : '';
				if ( $cs === '' || trim( self::text( $h ) ) === '' ) { continue; }
				$rec = array();
				$sz  = self::sc_css( $h, 'font-size' );
				if ( preg_match( '/^([0-9.]+)px$/', trim( (string) $sz ), $m ) ) { $rec['size'] = (int) round( (float) $m[1] ); }
				$w = trim( (string) self::sc_css( $h, 'font-weight' ) );
				if ( preg_match( '/^[1-9]00$/', $w ) ) { $rec['weight'] = $w; }
				elseif ( strtolower( $w ) === 'bold' ) { $rec['weight'] = '700'; }
				elseif ( strtolower( $w ) === 'normal' ) { $rec['weight'] = '400'; }
				$fam = $first_family( self::sc_css( $h, 'font-family' ) );
				if ( $fam !== '' ) { $rec['family'] = $fam; }
				$lh = $lh_ratio( self::sc_css( $h, 'line-height' ), isset( $rec['size'] ) ? $rec['size'] : 0 );
				if ( $lh !== '' ) { $rec['line-height'] = $lh; }
				$ls = $ls_px( self::sc_css( $h, 'letter-spacing' ) );
				if ( $ls !== '' ) { $rec['letter-spacing'] = $ls; }
				if ( $rec ) { $out[ $lvl ] = $rec; }
				break; // first real occurrence of this level is representative
			}
		}
		return $out;
	}

	/**
	 * Is a deterministic chrome mapping FAITHFUL enough to drive the native (Theme-Settings) chrome path,
	 * versus falling back to the raw-chrome bake? The header is generally safe (always has a wordmark +
	 * menu-area + layout), so the gate is on the FOOTER — the part that used to collapse: require a detected
	 * multi-column grid (≥2 columns, i.e. brand + at least one content column) OR a copyright bar. When the
	 * footer structure wasn't confidently detected (a messy source), $dyn bakes the source footer verbatim
	 * instead so nothing renders broken.
	 *
	 * @param array $chrome tokens_to_theme_settings_chrome() result
	 * @return bool
	 */
	public static function chrome_mapping_faithful( $chrome ) {
		if ( empty( $chrome['values'] ) || ! is_array( $chrome['values'] ) ) { return false; }
		$v = $chrome['values'];
		// Header sanity: a logo wordmark/image and a header_main with element zones.
		$has_header = ! empty( $v['header_logo'] ) && ! empty( $v['header_main'] );
		// Footer sanity: a real multi-column grid, or at least a copyright bar.
		$has_grid = false;
		if ( ! empty( $v['main_footer_columns']['count'] ) ) {
			$cnt = $v['main_footer_columns']['count'];
			$blk = isset( $v['main_footer_columns'][ $cnt ] ) ? $v['main_footer_columns'][ $cnt ] : array();
			$ncols = 0;
			foreach ( (array) $blk as $k => $col ) { if ( strpos( (string) $k, 'main_footer_col_' ) === 0 && ! empty( $col ) ) { $ncols++; } }
			$has_grid = $ncols >= 2;
		}
		$has_copyright = ! empty( $v['copyright_settings']['enabled'] ) && $v['copyright_settings']['enabled'] === 'yes';
		return $has_header && ( $has_grid || $has_copyright );
	}

	/**
	 * AI-REFINEMENT applier: merge an AI header/footer mapping (from the capture service's /translate-chrome
	 * → translateHeader / translateFooter) OVER the deterministic native chrome, correcting the ambiguous
	 * judgment calls the heuristics can get wrong (which band = which bar, CTA label/style, transparent-overlay
	 * behavior, column ratios). The AI JSON follows the header/footer translation guides; this converts each
	 * guide shape into the REAL Theme-Settings shapes (matching tokens_to_theme_settings_chrome) and overlays
	 * only the keys it can confidently set — so a partial/weak AI result never regresses the deterministic base.
	 *
	 * @param array  $chrome tokens_to_theme_settings_chrome() result (the deterministic base) — { values: … }
	 * @param array  $ai_header header guide JSON: { left, center, right, layout, identity, menu } (or null)
	 * @param array  $ai_footer footer guide JSON: { background, text_color, link_color, bars:{…} } (or null)
	 * @param string $title site name (fallbacks)
	 * @return array{ values: array, refined_header: bool, refined_footer: bool }
	 */
	public static function apply_ai_chrome( $chrome, $ai_header, $ai_footer, $title = '' ) {
		$values = ( isset( $chrome['values'] ) && is_array( $chrome['values'] ) ) ? $chrome['values'] : array();
		$hex = function ( $h ) { $h = trim( (string) $h ); return array( 'predefined' => '', 'custom' => $h ); };
		$el  = function ( $type, $settings = null ) {
			$et = array( 'element' => $type );
			if ( is_array( $settings ) ) { $et[ $type ] = $settings; }
			return array( 'element_type' => $et );
		};
		$refined_header = false;
		$refined_footer = false;

		/* ---------- HEADER ---------- */
		if ( is_array( $ai_header ) ) {
			$zones = array( 'left' => 'main_left', 'center' => 'main_center', 'right' => 'main_right' );
			$hm    = isset( $values['header_main'] ) && is_array( $values['header_main'] ) ? $values['header_main'] : array();
			$built_any = false;
			foreach ( $zones as $zk => $mk ) {
				if ( ! isset( $ai_header[ $zk ] ) || ! is_array( $ai_header[ $zk ] ) ) { continue; }
				$out_zone = array();
				foreach ( $ai_header[ $zk ] as $item ) {
					if ( ! is_array( $item ) || empty( $item['type'] ) ) { continue; }
					switch ( $item['type'] ) {
						case 'logo':
							$out_zone[] = $el( 'logo' );
							break;
						case 'menu_area':
							$out_zone[] = $el( 'menu_area', array( 'menu_location' => 'primary' ) );
							break;
						case 'cta_button':
							$style = isset( $item['style'] ) ? (string) $item['style'] : 'filled';
							$map   = array( 'filled' => 'btn-primary', 'outline' => 'btn-secondary', 'pill' => 'btn-primary' );
							$out_zone[] = $el( 'cta_button', array(
								'cta_text'  => isset( $item['text'] ) ? (string) $item['text'] : 'Get Started',
								'cta_link'  => ( isset( $item['link'] ) && $item['link'] !== '' ) ? (string) $item['link'] : '#',
								'cta_style' => isset( $map[ $style ] ) ? $map[ $style ] : 'btn-primary',
								'cta_size'  => 'btn-md',
							) );
							break;
						case 'icon_text':
							$out_zone[] = $el( 'icon_text', array(
								'icontext_text'      => isset( $item['text'] ) ? (string) $item['text'] : '',
								'icontext_link'      => isset( $item['link'] ) ? (string) $item['link'] : '',
								'icontext_link_type' => ( isset( $item['link'] ) && strpos( (string) $item['link'], 'tel:' ) === 0 ) ? 'phone' : ( ( isset( $item['link'] ) && strpos( (string) $item['link'], 'mailto:' ) === 0 ) ? 'email' : 'url' ),
							) );
							break;
						case 'social_icons':
							$out_zone[] = $el( 'social_icons' );
							break;
						case 'search':
							$out_zone[] = $el( 'search' );
							break;
					}
				}
				if ( $out_zone ) { $hm[ $mk ] = $out_zone; $built_any = true; }
			}
			// Only overwrite header_main if the AI produced a logo + a menu somewhere (else keep deterministic).
			$flat = wp_json_encode( $hm );
			if ( $built_any && strpos( (string) $flat, '"logo"' ) !== false && strpos( (string) $flat, 'menu_area' ) !== false ) {
				$values['header_main'] = $hm;
				$refined_header = true;
			}

			// identity → header_logo (nested multi-picker) custom branch. Only refine colors/title/layout —
			// do NOT clobber a good deterministic inline-SVG icon + frame the AI can't reproduce from text.
			if ( ! empty( $ai_header['identity'] ) && is_array( $ai_header['identity'] ) ) {
				$id = $ai_header['identity'];
				$hl = isset( $values['header_logo'] ) && is_array( $values['header_logo'] ) ? $values['header_logo'] : array();
				if ( ! isset( $hl['logo_type'] ) || ! is_array( $hl['logo_type'] ) ) { $hl['logo_type'] = array( 'logo_type' => 'custom', 'custom' => array(), 'simple' => array() ); }
				$cu = isset( $hl['logo_type']['custom'] ) && is_array( $hl['logo_type']['custom'] ) ? $hl['logo_type']['custom'] : array();
				if ( ! empty( $id['site_title'] ) )  { $cu['site_title'] = (string) $id['site_title']; }
				if ( ! empty( $id['title_color'] ) ) { $cu['color'] = $hex( $id['title_color'] ); }
				if ( ! empty( $id['layout'] ) )      { $cu['logo_layout'] = (string) $id['layout']; }
				if ( ! empty( $id['icon_color'] ) && empty( $cu['logo_icon_frame_bg'] ) ) { $cu['logo_icon_color'] = $hex( $id['icon_color'] ); }
				$hl['logo_type']['custom'] = $cu;
				$values['header_logo'] = $hl;
				$refined_header = true;
			}

			// menu → header_menu colors + item style + uppercase.
			if ( ! empty( $ai_header['menu'] ) && is_array( $ai_header['menu'] ) ) {
				$mn = $ai_header['menu'];
				$hmenu = isset( $values['header_menu'] ) && is_array( $values['header_menu'] ) ? $values['header_menu'] : array();
				if ( ! empty( $mn['link_color'] ) )       { $hmenu['menu_link_color'] = $hex( $mn['link_color'] ); }
				if ( ! empty( $mn['link_hover_color'] ) ) { $hmenu['menu_link_hover_color'] = $hex( $mn['link_hover_color'] ); }
				if ( ! empty( $mn['item_style'] ) )       { $hmenu['menu_item_style'] = ( $mn['item_style'] === 'underline' ) ? 'underline-grow' : (string) $mn['item_style']; }
				$values['header_menu'] = $hmenu;
				if ( isset( $mn['uppercase'] ) && ! empty( $values['header_layout'] ) && is_array( $values['header_layout'] ) ) {
					$values['header_layout']['header_uppercase_nav'] = $mn['uppercase'] ? 'yes' : 'no';
				}
				$refined_header = true;
			}

			// layout → behavior / design / container / bg.
			if ( ! empty( $ai_header['layout'] ) && is_array( $ai_header['layout'] ) ) {
				$ly = $ai_header['layout'];
				$hlay = isset( $values['header_layout'] ) && is_array( $values['header_layout'] ) ? $values['header_layout'] : array();
				$beh_map = array( 'static' => 'static', 'sticky' => 'sticky', 'sticky-shrink' => 'sticky-shrink', 'hide-on-scroll' => 'hide-on-scroll', 'transparent-overlay' => 'transparent-overlay' );
				if ( ! empty( $ly['behavior'] ) && isset( $beh_map[ $ly['behavior'] ] ) ) { $hlay['header_behavior'] = $beh_map[ $ly['behavior'] ]; }
				if ( ! empty( $ly['design'] ) && in_array( $ly['design'], array( 'classic', 'pill', 'card', 'centered' ), true ) ) {
					$hlay['header_mode'] = array( 'mode' => 'top', 'top' => array( 'header_design' => array( 'design' => (string) $ly['design'] ) ) );
				}
				if ( ! empty( $ly['container'] ) && in_array( $ly['container'], array( 'container', 'container-fluid' ), true ) ) { $hlay['container'] = (string) $ly['container']; }
				if ( isset( $ly['bg_color'] ) && $ly['bg_color'] !== '' ) { $hlay['bg_color'] = $hex( $ly['bg_color'] ); }
				$values['header_layout'] = $hlay;
				$refined_header = true;
			}
		}

		/* ---------- FOOTER ---------- */
		if ( is_array( $ai_footer ) ) {
			if ( ! empty( $ai_footer['background'] ) ) { $values['footer_background'] = array( 'color' => array( 'value' => array( 'predefined' => '', 'custom' => (string) $ai_footer['background'] ) ) ); }
			if ( ! empty( $ai_footer['text_color'] ) ) { $values['footer_text_color'] = $hex( $ai_footer['text_color'] ); }
			if ( ! empty( $ai_footer['link_color'] ) ) { $values['footer_link_color'] = $hex( $ai_footer['link_color'] ); }

			$bars = isset( $ai_footer['bars'] ) && is_array( $ai_footer['bars'] ) ? $ai_footer['bars'] : array();

			// Build a set of footer-column elements from a guide "column".
			$build_col = function ( $col ) use ( $el ) {
				$out = array();
				$elements = ( is_array( $col ) && isset( $col['elements'] ) && is_array( $col['elements'] ) ) ? $col['elements'] : array();
				foreach ( $elements as $e ) {
					if ( ! is_array( $e ) || empty( $e['type'] ) ) { continue; }
					switch ( $e['type'] ) {
						case 'logo':
							$out[] = $el( 'footer_logo' );
							break;
						case 'social_icons':
							$out[] = $el( 'social_icons' );
							break;
						case 'text':
							$out[] = array( 'element_type' => array( 'element' => 'text', 'text' => array( 'text_content' => '<p>' . esc_html( isset( $e['content'] ) ? (string) $e['content'] : '' ) . '</p>' ) ) );
							break;
						case 'heading':
							$out[] = array( 'element_type' => array( 'element' => 'text', 'text' => array( 'text_content' => '<h4>' . esc_html( isset( $e['text'] ) ? (string) $e['text'] : '' ) . '</h4>' ) ) );
							break;
						case 'menu':
							$links = ( isset( $e['links'] ) && is_array( $e['links'] ) ) ? $e['links'] : array();
							$h = '<ul>';
							foreach ( $links as $l ) { if ( is_array( $l ) ) { $h .= '<li><a href="' . esc_url( ! empty( $l['href'] ) ? (string) $l['href'] : '#' ) . '">' . esc_html( isset( $l['text'] ) ? (string) $l['text'] : '' ) . '</a></li>'; } }
							$h .= '</ul>';
							$out[] = array( 'element_type' => array( 'element' => 'text', 'text' => array( 'text_content' => $h ) ) );
							break;
						case 'icon_text':
							$out[] = $el( 'icon_text', array(
								'icontext_text'      => isset( $e['text'] ) ? (string) $e['text'] : '',
								'icontext_link'      => isset( $e['link'] ) ? (string) $e['link'] : '',
								'icontext_link_type' => ( isset( $e['link'] ) && strpos( (string) $e['link'], 'tel:' ) === 0 ) ? 'phone' : ( ( isset( $e['link'] ) && strpos( (string) $e['link'], 'mailto:' ) === 0 ) ? 'email' : 'url' ),
							) );
							break;
					}
				}
				return $out;
			};

			// main bar → main_footer_columns (its columns become the grid). REFINEMENT rule: only replace the
			// deterministic grid when the AI found AT LEAST as many columns — a truncated/partial AI footer
			// (common when the footer markup is large and gets trimmed) must never REGRESS a faithful 4-col
			// deterministic grid down to 2. The deterministic base already captured the full grid structure.
			if ( ! empty( $bars['main']['columns'] ) && is_array( $bars['main']['columns'] ) ) {
				$cols = array();
				foreach ( $bars['main']['columns'] as $col ) { $built = $build_col( $col ); if ( $built ) { $cols[] = $built; } }
				$cols = array_slice( $cols, 0, 6 );
				$n = count( $cols );
				// Count the deterministic base's columns to compare.
				$base_n = 0;
				if ( ! empty( $values['main_footer_columns']['count'] ) ) {
					$bk = $values['main_footer_columns']['count'];
					foreach ( (array) ( $values['main_footer_columns'][ $bk ] ?? array() ) as $kk => $cc ) { if ( strpos( (string) $kk, 'main_footer_col_' ) === 0 && ! empty( $cc ) ) { $base_n++; } }
				}
				if ( $n >= 1 && $n >= $base_n ) {
					$mfc = array();
					for ( $i = 0; $i < $n; $i++ ) { $mfc[ 'main_footer_col_' . ( $i + 1 ) ] = $cols[ $i ]; }
					// A wider brand column (auto-width space-between) → fifths brand-spans-2 when 4 cols.
					if ( 4 === $n ) { $mfc['main_footer_layout'] = 'f5-2-1-1-1'; $ck = '5'; }
					else { $ck = (string) $n; }
					if ( ! empty( $bars['main']['auto_width'] ) ) { $mfc[ 'main_footer_auto' ] = 'yes'; $mfc['main_footer_justify'] = ! empty( $bars['main']['justify'] ) ? (string) $bars['main']['justify'] : 'between'; }
					$values['main_footer_columns'] = array( 'count' => $ck, $ck => $mfc );
					$refined_footer = true;
				}
			}

			// copyright bar → copyright_settings. Same refinement rule: don't regress a 2-col deterministic
			// copyright (© + legal menu) to a 1-col AI copyright.
			if ( ! empty( $bars['copyright']['columns'] ) && is_array( $bars['copyright']['columns'] ) ) {
				$ccols = array();
				foreach ( $bars['copyright']['columns'] as $col ) { $built = $build_col( $col ); if ( $built ) { $ccols[] = $built; } }
				$ccols = array_slice( $ccols, 0, 3 );
				$n = count( $ccols );
				$base_cn = 0;
				if ( ! empty( $values['copyright_settings']['yes']['copyright_columns']['count'] ) ) {
					$bck = $values['copyright_settings']['yes']['copyright_columns']['count'];
					foreach ( (array) ( $values['copyright_settings']['yes']['copyright_columns'][ $bck ] ?? array() ) as $kk => $cc ) { if ( strpos( (string) $kk, 'copyright_col_' ) === 0 && ! empty( $cc ) ) { $base_cn++; } }
				}
				if ( $n >= 1 && $n >= $base_cn ) {
					$cc = array();
					for ( $i = 0; $i < $n; $i++ ) { $cc[ 'copyright_col_' . ( $i + 1 ) ] = $ccols[ $i ]; }
					$values['copyright_settings'] = array( 'enabled' => 'yes', 'yes' => array( 'copyright_columns' => array( 'count' => (string) $n, (string) $n => $cc ) ) );
					$refined_footer = true;
				}
			}
		}

		return array( 'values' => $values, 'refined_header' => $refined_header, 'refined_footer' => $refined_footer );
	}

	/**
	 * Detect the brand LOGO: its wordmark text and (if an inline icon precedes the text) a Lucide
	 * icon id to reproduce as the native Logo Icon. Returns { text, icon, image }.
	 *   - text  = the wordmark string (empty for a pure image logo)
	 *   - icon  = a 'lucide/<name>' id when an <svg>/icon element sits before the text, else ''
	 *   - image = the logo <img> src for an image logo, else ''
	 * Heuristic + conservative: only emits an icon id we can confidently map (data-lucide / a
	 * lucide-* class / an iconify lucide:<name>); otherwise leaves icon empty (wordmark only).
	 */
	private static function detect_logo( $html, $title ) {
		$out = array( 'text' => '', 'icon' => '', 'image' => '', 'svg' => '', 'icon_color' => '', 'frame' => 'none', 'frame_bg' => '', 'title_color' => '', 'title_size' => '', 'title_weight' => '', 'icon_size' => '', 'title_accent_color' => '', 'title_accent_text' => '' );
		$dom = self::load_dom( $html );
		if ( ! $dom ) { return $out; }
		$header = self::header_root( $dom );
		if ( ! $header ) { return $out; }

		// Prefer an explicit brand link/element: the first <a> whose href is '/', '#', or home.
		$brand = null;
		foreach ( $header->getElementsByTagName( 'a' ) as $a ) {
			$href = trim( (string) $a->getAttribute( 'href' ) );
			if ( $href === '' || $href === '#' || $href === '/' || preg_match( '~^https?://[^/]+/?$~', $href ) ) {
				if ( ! self::is_button( $a ) ) { $brand = $a; break; }
			}
		}
		if ( $brand === null ) { $brand = $header; }

		// Image logo?
		foreach ( $brand->getElementsByTagName( 'img' ) as $img ) {
			$src = trim( (string) $img->getAttribute( 'src' ) );
			if ( $src !== '' && strpos( $src, 'data:' ) !== 0 ) { $out['image'] = $src; }
			break;
		}

		// Wordmark text (icons stripped) + its computed color.
		$txt = self::text_no_icons( $brand );
		$txt = trim( preg_replace( '/\s+/', ' ', (string) $txt ) );
		// Guard against grabbing the whole nav: a brand wordmark is short (<= 4 words).
		if ( $txt !== '' && str_word_count( $txt ) <= 4 ) { $out['text'] = $txt; }
		// The wordmark text color + SIZE + WEIGHT: the deepest element whose text STARTS the wordmark (the first
		// <span>), plus — for a TWO-TONE wordmark (FreshPaws = "Fresh" dark + "Paws" green) — a later sibling span
		// whose color differs = the accent tone. The base `color` maps natively; the accent is a documented
		// residual (a single color field can't split a wordmark), surfaced as title_accent_color for logo_custom_css.
		$saw_base = false;
		foreach ( $brand->getElementsByTagName( 'span' ) as $sp ) {
			$st = trim( preg_replace( '/\s+/', ' ', self::text_no_icons( $sp ) ) );
			if ( $st === '' || $out['text'] === '' || strpos( $out['text'], $st ) === false ) { continue; }
			$c = self::sc_css( $sp, 'color' );
			if ( ! $saw_base ) {
				if ( $c !== '' ) { $out['title_color'] = $c; }
				$fsz = self::sc_css( $sp, 'font-size' );   if ( $fsz !== '' ) { $out['title_size'] = $fsz; }
				$fwt = self::sc_css( $sp, 'font-weight' );  if ( preg_match( '/^(300|400|500|600|700|800|900)$/', trim( $fwt ) ) ) { $out['title_weight'] = trim( $fwt ); }
				$saw_base = true;
			} elseif ( $c !== '' && $out['title_color'] !== '' && $c !== $out['title_color'] && $out['title_accent_color'] === '' ) {
				$out['title_accent_color'] = $c;  // the second-tone (accent) span color
				// The accent RUN text (e.g. "Paws" of "FreshPaws"): a proper trailing/embedded substring of the
				// wordmark — so the emit can SPLIT the wordmark markup (ink part + <span class="accent">) and the
				// scoped logo_custom_css actually paints it. Only keep it when it's a real sub-run (not the whole
				// wordmark and not empty), so a single-tone wordmark never gets a spurious split.
				if ( $st !== '' && $st !== $out['text'] && strpos( $out['text'], $st ) !== false ) { $out['title_accent_text'] = $st; }
			}
		}
		if ( $out['title_color'] === '' ) { $out['title_color'] = self::sc_css( $brand, 'color' ); }
		if ( $out['title_size'] === '' )  { $out['title_size']  = self::sc_css( $brand, 'font-size' ); }

		// Icon: an inline <svg> mark (kept VERBATIM — icon-v2 renders inline SVG via currentColor), plus its
		// color + an optional colored frame TILE (a wrapper div with a background + rounded corners — the
		// FreshPaws "green rounded paw tile" pattern). Fall back to a Lucide library id when no inline svg.
		$svg = null;
		foreach ( $brand->getElementsByTagName( 'svg' ) as $s ) { $svg = $s; break; }
		if ( $svg instanceof DOMElement ) {
			$markup = $dom->saveHTML( $svg );
			if ( is_string( $markup ) && $markup !== '' && strlen( $markup ) < 12000 ) { $out['svg'] = $markup; }
			// Icon color: a `text-white`/`text-*` class or the svg's computed color; else the wordmark color.
			$scls = self::cls( $svg );
			if ( strpos( $scls, 'text-white' ) !== false ) { $out['icon_color'] = '#ffffff'; }
			else { $out['icon_color'] = self::sc_css( $svg, 'color' ); }
			// Icon glyph size: computed width, else a Tailwind `w-N`/`w-[..]` class (N × 4px). e.g. `w-6` = 24px.
			$isz = self::sc_css( $svg, 'width' );
			if ( $isz === '' ) { $isz = self::tw_size_px( $scls ); }
			if ( $isz !== '' && preg_match( '/^[0-9.]+(px|rem|em)?$/', trim( $isz ) ) ) { $out['icon_size'] = trim( $isz ); }
			// Frame tile: the nearest ancestor (within the brand) that has a real background + rounding. Infer the
			// frame SHAPE from the tile's corner-radius vs its size: a radius ≥ ~48% of the box → circle (fully
			// rounded), a smaller-but-present radius → rounded box, none → square.
			$anc = $svg->parentNode;
			while ( $anc instanceof DOMElement && $anc !== $brand ) {
				$bg = self::sc_css( $anc, 'background-color' );
				$br = self::sc_css( $anc, 'border-radius' );
				if ( $bg !== '' && stripos( $bg, 'transparent' ) === false && ! preg_match( '/rgba\([^)]*,\s*0\s*\)/i', $bg ) ) {
					$out['frame_bg'] = $bg;
					$tile_px = self::sc_css( $anc, 'width' );
					if ( $tile_px === '' ) { $tile_px = self::tw_size_px( self::cls( $anc ) ); }
					$out['frame'] = self::infer_frame_shape( $br, $tile_px );
					if ( $out['icon_color'] === '' ) { $out['icon_color'] = '#ffffff'; } // colored tile ⇒ white mark
					break;
				}
				$anc = $anc->parentNode;
			}
		}
		if ( $out['svg'] === '' ) { $out['icon'] = self::detect_lucide_in( $brand ); }
		return $out;
	}

	/**
	 * A pixel size (as a `NNpx` string) from a Tailwind sizing class: `w-6`/`h-6` (N × 4px = 24px),
	 * `size-10` (40px), or an arbitrary `w-[40px]`/`w-[2.5rem]`. Returns '' when no sizing class is present.
	 */
	private static function tw_size_px( $cls ) {
		$cls = ' ' . (string) $cls . ' ';
		if ( preg_match( '/\s(?:w|h|size)-\[([0-9.]+)(px|rem|em)\]/', $cls, $m ) ) {
			$v = (float) $m[1]; return ( $m[2] === 'px' ) ? ( rtrim( rtrim( (string) $v, '0' ), '.' ) . 'px' ) : ( (string) $v . $m[2] );
		}
		if ( preg_match( '/\s(?:w|h|size)-([0-9]+(?:\.[0-9]+)?)\s/', $cls, $m ) ) { return (string) ( (float) $m[1] * 4 ) . 'px'; }
		return '';
	}

	/**
	 * Infer the logo frame SHAPE (`circle` | `squircle` | `rounded` | `square`) from a tile's border-radius vs
	 * its box size. Only a `rounded-full` idiom is a true CIRCLE — a `%`/`50%` radius, a pill `9999px`, or a
	 * FINITE radius that nearly reaches the box (≥ ~90%, i.e. fully rounded). A large-but-finite corner
	 * (`rounded-xl`/`2xl`/`3xl`, ~12–28px on a typical tile — ratio ≳0.22) is the app-icon **squircle** look
	 * (FreshPaws' `rounded-2xl` = 24px on a 40px tile → squircle, NOT circle). A small finite radius → rounded;
	 * `0`/absent → square. Box unknown: a moderate px radius (≥10px) reads as squircle, a small one as rounded.
	 */
	private static function infer_frame_shape( $radius, $box_px ) {
		$radius = trim( (string) $radius );
		if ( $radius === '' || preg_match( '/^0(px|rem|em)?(\s+0(px|rem|em)?)*$/', $radius ) ) { return 'square'; }
		// A percent radius or a huge pill radius = a full CIRCLE regardless of size (the `rounded-full` idiom).
		if ( strpos( $radius, '%' ) !== false || preg_match( '/(?:^|\s)(?:99\d\d|[1-9]\d{4,})px/', $radius ) ) { return 'circle'; }
		$r = 0.0; if ( preg_match( '/([0-9.]+)px/', $radius, $rm ) ) { $r = (float) $rm[1]; }
		$b = 0.0; if ( preg_match( '/([0-9.]+)px/', (string) $box_px, $bm ) ) { $b = (float) $bm[1]; }
		if ( $r > 0 && $b > 0 ) {
			$ratio = $r / $b;
			if ( $ratio >= 0.90 ) { return 'circle'; }    // a finite radius that fully rounds a square → circle
			if ( $ratio >= 0.22 ) { return 'squircle'; }  // rounded-xl/2xl/3xl app-icon tile
			return 'rounded';                              // gentle corner
		}
		// Box size unknown — judge by the raw radius: a moderate finite corner is the squircle look.
		if ( $r >= 10 ) { return 'squircle'; }
		return ( $r > 0 ) ? 'rounded' : 'square';
	}

	/** An element's OPAQUE computed background-color (data-sc-cs), or '' when transparent / absent / low-alpha (≤0.25). */
	private static function el_bg_opaque( $el ) {
		$bg = self::sc_css( $el, 'background-color' );
		if ( $bg === '' || stripos( $bg, 'transparent' ) !== false ) { return ''; }
		if ( preg_match( '/rgba\([^)]*,\s*([0-9.]+)\s*\)/i', $bg, $m ) && (float) $m[1] <= 0.25 ) { return ''; }
		return $bg;
	}

	/** The opaque fill of a full-bleed (`absolute inset-0`) background LAYER inside a band (the overlay pattern
	 *  a `<div class="absolute inset-0 bg-primary">` behind a CTA section), or '' when none. Skips decorative
	 *  low-alpha blobs (handled by el_bg_opaque's alpha gate). */
	private static function fullbleed_bg( $node ) {
		if ( ! ( $node instanceof DOMElement ) ) { return ''; }
		foreach ( $node->getElementsByTagName( 'div' ) as $d ) {
			$c = ' ' . self::cls( $d ) . ' ';
			if ( strpos( $c, ' inset-0' ) === false && strpos( $c, 'inset-0 ' ) === false ) { continue; }
			$r = self::el_bg_opaque( $d );
			if ( $r !== '' ) { return $r; }
		}
		return '';
	}

	/** Read a single CSS property value from an element's captured `data-sc-cs` computed-style attribute. '' if absent. */
	private static function sc_css( $el, $prop ) {
		if ( ! ( $el instanceof DOMElement ) ) { return ''; }
		$cs = (string) $el->getAttribute( 'data-sc-cs' );
		if ( $cs === '' ) { return ''; }
		if ( preg_match( '/(?:^|;)\s*' . preg_quote( $prop, '/' ) . '\s*:\s*([^;]+)/i', $cs, $m ) ) { return trim( $m[1] ); }
		return '';
	}

	/** Sniff a Lucide icon id ('lucide/<name>') from data-lucide / class lucide-<name> / iconify lucide:<name>. */
	private static function detect_lucide_in( $node ) {
		// data-lucide="wind" (Lucide's own web-component / data attr)
		foreach ( $node->getElementsByTagName( 'i' ) as $i ) {
			$dl = trim( (string) $i->getAttribute( 'data-lucide' ) );
			if ( $dl !== '' ) { return 'lucide/' . sanitize_title( $dl ); }
			$cls = self::cls( $i );
			if ( preg_match( '/\blucide-([a-z0-9-]+)/', $cls, $m ) ) { return 'lucide/' . $m[1]; }
		}
		// iconify-icon icon="lucide:wind"
		foreach ( $node->getElementsByTagName( '*' ) as $any ) {
			$ic = trim( (string) $any->getAttribute( 'icon' ) );
			if ( preg_match( '/^lucide:([a-z0-9-]+)$/', $ic, $m ) ) { return 'lucide/' . $m[1]; }
			$dl = trim( (string) $any->getAttribute( 'data-lucide' ) );
			if ( $dl !== '' ) { return 'lucide/' . sanitize_title( $dl ); }
		}
		return '';
	}

	/** The footer's copyright line (a © / "rights reserved" text node), or '' if none. */
	private static function detect_footer_copyright( $html ) {
		$dom = self::load_dom( $html );
		if ( ! $dom ) { return ''; }
		$footer = $dom->getElementsByTagName( 'footer' )->item( 0 );
		if ( ! $footer ) { return ''; }
		$best = '';
		foreach ( $footer->getElementsByTagName( 'p' ) as $p ) {
			$t = trim( (string) $p->textContent );
			if ( $t !== '' && ( strpos( $t, '©' ) !== false || stripos( $t, 'rights reserved' ) !== false || stripos( $t, 'copyright' ) !== false ) ) {
				$best = $t;
				break;
			}
		}
		if ( $best !== '' ) {
			// Normalize a year to the live token so it stays current.
			$best = preg_replace( '/\b(19|20)\d{2}\b/', '{{current_year}}', $best, 1 );
			$best = esc_html( $best );
			// Normalize the copyright glyph to the entity — DOMDocument's textContent can hand back the
			// UTF-8 © as the mojibake pair `Â©` (bytes C2 A9 read as Latin-1); collapse both forms so the
			// stored copyright line never carries a stray `Â`.
			$best = str_replace( array( "\xc3\x82\xc2\xa9", 'Â©', "\xc2\xa9", '©' ), '&copy;', $best );
		}
		return $best;
	}

	/** Map a social URL's host to a Lucide icon id ('lucide/<name>'), or '' if not a known network. */
	private static function social_lucide( $url ) {
		$host = strtolower( (string) parse_url( (string) $url, PHP_URL_HOST ) );
		$map  = array(
			'twitter'   => 'lucide/twitter',  'x.com'      => 'lucide/twitter',
			'facebook'  => 'lucide/facebook', 'instagram'  => 'lucide/instagram',
			'linkedin'  => 'lucide/linkedin', 'youtube'    => 'lucide/youtube',
			'github'    => 'lucide/github',   'discord'    => 'lucide/message-circle',
			'dribbble'  => 'lucide/dribbble', 'twitch'     => 'lucide/twitch',
			'tiktok'    => 'lucide/music',    'pinterest'  => 'lucide/image',
			'telegram'  => 'lucide/send',     't.me'       => 'lucide/send',
			'whatsapp'  => 'lucide/message-circle', 'slack'  => 'lucide/slack',
			'mastodon'  => 'lucide/at-sign',
		);
		foreach ( $map as $needle => $id ) { if ( $host !== '' && strpos( $host, $needle ) !== false ) { return $id; } }
		return '';
	}

	/**
	 * Network → Lucide-library icon id, for social detection by ICON (not just href). Keys are the network
	 * name as it appears in an icon class / aria-label (`lucide-facebook`, `fab fa-instagram`, `aria-label="Twitter"`).
	 */
	private static function social_network_map() {
		return array(
			'facebook'  => 'lucide/facebook',  'instagram' => 'lucide/instagram',
			'twitter'   => 'lucide/twitter',   'x-twitter' => 'lucide/twitter',
			'youtube'   => 'lucide/youtube',   'linkedin'  => 'lucide/linkedin',
			'github'    => 'lucide/github',    'tiktok'    => 'lucide/music',
			'dribbble'  => 'lucide/dribbble',  'twitch'    => 'lucide/twitch',
			'pinterest' => 'lucide/image',     'discord'   => 'lucide/message-circle',
			'telegram'  => 'lucide/send',      'whatsapp'  => 'lucide/message-circle',
			'slack'     => 'lucide/slack',     'mastodon'  => 'lucide/at-sign',
		);
	}

	/**
	 * Identify the social network of a link by its ICON — the source often uses a placeholder `href="#"`
	 * (so host-based detection misses it). Reads the network from the icon SVG/`<i>`/`<use>` class
	 * (`lucide-<net>`, `fa-<net>`/`fab fa-<net>`, `bi-<net>`, `icon-<net>`), the link's `aria-label`/`title`,
	 * then falls back to the href host. Returns array( 'key' => <net>, 'icon' => 'lucide/<name>' ) or null.
	 */
	private static function social_network_of( $a, $href ) {
		$map = self::social_network_map();
		// Haystack: the <a>'s own class/aria/title + every icon descendant's class + <use> hrefs.
		$hay = ' ' . self::cls( $a ) . ' ' . strtolower( (string) $a->getAttribute( 'aria-label' ) )
			. ' ' . strtolower( (string) $a->getAttribute( 'title' ) ) . ' ';
		foreach ( array( 'svg', 'i', 'span', 'use' ) as $t ) {
			foreach ( $a->getElementsByTagName( $t ) as $node ) {
				$hay .= ' ' . self::cls( $node );
				if ( $t === 'use' ) { $hay .= ' ' . strtolower( (string) $node->getAttribute( 'href' ) ) . ' ' . strtolower( (string) $node->getAttribute( 'xlink:href' ) ); }
			}
		}
		$hay = ' ' . preg_replace( '/\s+/', ' ', $hay ) . ' ';
		foreach ( $map as $key => $icon ) {
			$word = preg_quote( $key, '/' );
			// A network icon class prefix (lucide-/fa-/fab-/bi-/icon-/ion-/social-), OR the network as a
			// standalone word in a class/aria-label (only for names ≥4 chars, so a stray "x" can't match).
			if ( preg_match( '/(?:lucide-|fa-|fab-|fa-brands.|bi-|icon-|ion-|social-)' . $word . '\b/', $hay )
				|| ( strlen( $key ) >= 4 && preg_match( '/\b' . $word . '\b/', $hay ) ) ) {
				$k = ( $key === 'x-twitter' ) ? 'twitter' : $key;
				return array( 'key' => $k, 'icon' => $icon );
			}
		}
		// Fallback: the href host (a real social URL).
		$h = self::social_lucide( $href );
		if ( $h !== '' ) {
			foreach ( $map as $key => $icon ) { if ( $icon === $h ) { return array( 'key' => ( $key === 'x-twitter' ? 'twitter' : $key ), 'icon' => $icon ); } }
			return array( 'key' => str_replace( 'lucide/', '', $h ), 'icon' => $h );
		}
		return null;
	}

	/**
	 * Footer social links → social_profiles [{ name, link, new_tab, icon }], deduped by network. Detects each
	 * link's network by its ICON (social_network_of), so placeholder `href="#"`/empty/missing hrefs still map
	 * (the FreshPaws footer's `<a href="#"><svg class="lucide lucide-facebook">` pattern). The profile `link`
	 * is the real URL when present, else `#` — the theme's social element SKIPS an EMPTY link, so `#` keeps
	 * the icon rendered.
	 */
	private static function detect_footer_social( $html ) {
		$dom = self::load_dom( $html );
		if ( ! $dom ) { return array(); }
		$footer = $dom->getElementsByTagName( 'footer' )->item( 0 );
		if ( ! $footer ) { return array(); }
		$out  = array();
		$seen = array();
		foreach ( $footer->getElementsByTagName( 'a' ) as $a ) {
			$href = trim( (string) $a->getAttribute( 'href' ) );
			$net  = self::social_network_of( $a, $href );
			if ( $net === null || isset( $seen[ $net['key'] ] ) ) { continue; }
			$seen[ $net['key'] ] = true;
			$link = preg_match( '~^https?://~i', $href ) ? $href : '#'; // real URL, else '#' (non-empty ⇒ renders)
			$out[] = array(
				'name'    => ucfirst( $net['key'] ),
				'link'    => $link,
				'new_tab' => 'yes',
				'icon'    => array( 'type' => 'svg', 'svg-source' => 'library', 'svg-id' => $net['icon'] ),
			);
			if ( count( $out ) >= 8 ) { break; }
		}
		return $out;
	}

	/**
	 * Footer content COLUMNS → [{ title, links:[{label,href}], items:[text] }]. A heading (h3-h6) plus its
	 * following content, which is EITHER a link list (Quick Links) OR a plain text list (Services: bare
	 * `<li>`s) OR icon+text lines (Contact Info: address/phone/email). Earlier this required ≥2 real `<a>`
	 * links, which silently DROPPED the text-only "Services"/"Contact Info" columns and collapsed a faithful
	 * 4-column footer down to 2 (brand + one link column) — the fidelity gap that made the $dyn path bake the
	 * footer verbatim instead of using native Theme Settings. Now a column is kept when it has ≥2 links OR ≥2
	 * text items, so the whole grid maps into `main_footer_columns`. `links` and `items` are mutually
	 * preferred (links win when present); the builder renders links as an `<a>` list, items as a plain list.
	 */
	private static function detect_footer_columns( $html ) {
		$dom = self::load_dom( $html );
		if ( ! $dom ) { return array(); }
		$footer = $dom->getElementsByTagName( 'footer' )->item( 0 );
		if ( ! $footer ) { return array(); }
		$out  = array();
		$seen = array(); // dedupe headings shared across h-levels
		foreach ( array( 'h2', 'h3', 'h4', 'h5', 'h6' ) as $htag ) {
			foreach ( $footer->getElementsByTagName( $htag ) as $h ) {
				$title = trim( preg_replace( '/\s+/', ' ', (string) $h->textContent ) );
				if ( $title === '' || str_word_count( $title ) > 4 || isset( $seen[ $title ] ) ) { continue; }
				$parent = $h->parentNode;
				if ( ! ( $parent instanceof DOMElement ) ) { continue; }
				// Prefer real links (a nav column). Skip the copyright band's legal links (they live in the
				// same footer but belong to the copyright bar) — those are detected separately.
				$links = array();
				foreach ( $parent->getElementsByTagName( 'a' ) as $a ) {
					$lbl = trim( preg_replace( '/\s+/', ' ', self::text_no_icons( $a ) ) );
					$hrf = trim( (string) $a->getAttribute( 'href' ) );
					if ( $lbl !== '' && self::social_lucide( $hrf ) === '' ) { $links[] = array( 'label' => $lbl, 'href' => $hrf ); }
					if ( count( $links ) >= 10 ) { break; }
				}
				// No link list? Fall back to the column's text items (`<li>` rows — plain text or icon+text).
				$items = array();
				$rows  = array(); // structured icon+text rows (a CONTACT column: address/phone/email)
				if ( count( $links ) < 2 ) {
					$links = array();
					foreach ( $parent->getElementsByTagName( 'li' ) as $li ) {
						$row = self::footer_contact_row( $li );
						$t   = trim( preg_replace( '/\s+/', ' ', self::text_no_icons( $li ) ) );
						if ( $t !== '' ) { $items[] = $t; }
						if ( $row !== null ) { $rows[] = $row; }
						if ( count( $items ) >= 10 ) { break; }
					}
				}
				// A "contact" column = its rows are predominantly icon+text (a leading svg per row): keep the
				// structured rows so the emit can reproduce each leading icon (map-pin/phone/mail) + its color,
				// instead of flattening to a text blob that also glued the address lines together.
				// Backfill a missing per-row icon color from a sibling row (some inline svgs carry no computed
				// style of their own, but the column's icons share one tint — the brand-green `text-primary`).
				$ccolor = ''; foreach ( $rows as $r ) { if ( $r['color'] !== '' ) { $ccolor = $r['color']; break; } }
				if ( $ccolor !== '' ) { foreach ( $rows as &$r ) { if ( $r['icon'] !== '' && $r['color'] === '' ) { $r['color'] = $ccolor; } } unset( $r ); }
				$kind = 'text';
				$with_icon = 0; foreach ( $rows as $r ) { if ( $r['icon'] !== '' ) { $with_icon++; } }
				if ( count( $rows ) >= 2 && $with_icon >= 2 && $with_icon >= (int) ceil( count( $rows ) / 2 ) ) { $kind = 'contact'; }
				if ( count( $links ) >= 2 || count( $items ) >= 2 || ( $kind === 'contact' && count( $rows ) >= 2 ) ) {
					$out[] = array( 'title' => $title, 'links' => $links, 'items' => $items, 'kind' => $kind, 'rows' => ( $kind === 'contact' ? $rows : array() ) );
					$seen[ $title ] = true;
				}
				if ( count( $out ) >= 5 ) { break 2; }
			}
		}
		return $out;
	}

	/**
	 * A single footer CONTACT row from an `<li>`: its leading inline-icon (verbatim svg markup), the icon's
	 * color (the `text-primary` green — from data-sc-cs / a text-* class), and the row VALUE as HTML with line
	 * breaks preserved (a `<br>` inside the address `<span>` survives as `<br>`, so "123 Fresh Meadow Lane" and
	 * "Springfield, SP 12345" don't glue together). Returns null when the `<li>` has no meaningful text.
	 * Shape: array( 'icon' => <svg markup|''>, 'color' => <css color|''>, 'html' => <value html> ).
	 */
	private static function footer_contact_row( $li ) {
		if ( ! ( $li instanceof DOMElement ) ) { return null; }
		$dom = $li->ownerDocument;
		// Leading icon: the first descendant <svg> (Lucide inline mark). Keep it verbatim (small guard).
		$icon = ''; $color = '';
		foreach ( $li->getElementsByTagName( 'svg' ) as $s ) {
			$m = $dom ? $dom->saveHTML( $s ) : '';
			if ( is_string( $m ) && $m !== '' && strlen( $m ) < 8000 ) { $icon = $m; }
			$color = self::sc_css( $s, 'color' );
			// The root <svg> often carries no computed style — read the tint off a painted descendant
			// (its `currentColor` stroke resolves there, e.g. the green `text-primary` on a child path/circle).
			if ( $color === '' ) {
				foreach ( $s->getElementsByTagName( '*' ) as $ch ) { $cc = self::sc_css( $ch, 'color' ); if ( $cc !== '' ) { $color = $cc; break; } }
			}
			break;
		}
		// Row value: prefer the text-bearing <span>/<p>/<a>; keep <br>s as line breaks. Strip icon svgs.
		$val = null;
		foreach ( array( 'span', 'p', 'a' ) as $t ) { $n = $li->getElementsByTagName( $t )->item( 0 ); if ( $n ) { $val = $n; break; } }
		$src = $val instanceof DOMElement ? $val : $li;
		$clone = $src->cloneNode( true );
		foreach ( iterator_to_array( $clone->getElementsByTagName( 'svg' ) ) as $sv ) { if ( $sv->parentNode ) { $sv->parentNode->removeChild( $sv ); } }
		$inner = $dom ? $dom->saveHTML( $clone ) : '';
		// Keep <br>, drop every other tag, collapse whitespace → clean value HTML with line breaks intact.
		$inner = preg_replace( '~<br\s*/?>~i', "\n", (string) $inner );
		$inner = trim( preg_replace( '/[ \t]*\n[ \t]*/', "\n", preg_replace( '/[ \t]+/', ' ', wp_strip_all_tags( $inner ) ) ) );
		if ( $inner === '' ) { return null; }
		$html = str_replace( "\n", '<br>', esc_html( $inner ) );
		return array( 'icon' => $icon, 'color' => $color, 'html' => $html );
	}

	/**
	 * The footer's copyright-bar LEGAL links (Privacy / Terms / Cookies / Sitemap …) → [{label,href}]. These
	 * sit in the last footer band beside the © line and belong to the Copyright bar's SECOND column (a policy
	 * menu), not the main grid. Detected as the footer `<a>`s that are NOT social and NOT inside the main
	 * column grid — i.e. links that live in the same band as the © text. Empty when the footer has none.
	 */
	private static function detect_footer_legal_links( $html ) {
		$dom = self::load_dom( $html );
		if ( ! $dom ) { return array(); }
		$footer = $dom->getElementsByTagName( 'footer' )->item( 0 );
		if ( ! $footer ) { return array(); }
		// Find the copyright © node, then collect the sibling links within its band.
		$band = null;
		foreach ( $footer->getElementsByTagName( 'p' ) as $p ) {
			$t = (string) $p->textContent;
			if ( strpos( $t, '©' ) !== false || stripos( $t, 'rights reserved' ) !== false || stripos( $t, 'copyright' ) !== false ) {
				// Walk up a couple of levels to the band that also holds the legal links.
				$band = $p->parentNode instanceof DOMElement ? $p->parentNode : null;
				break;
			}
		}
		if ( ! ( $band instanceof DOMElement ) ) { return array(); }
		$out = array();
		foreach ( $band->getElementsByTagName( 'a' ) as $a ) {
			$lbl = trim( preg_replace( '/\s+/', ' ', self::text_no_icons( $a ) ) );
			$hrf = trim( (string) $a->getAttribute( 'href' ) );
			if ( $lbl !== '' && self::social_lucide( $hrf ) === '' ) { $out[] = array( 'label' => $lbl, 'href' => $hrf ); }
			if ( count( $out ) >= 6 ) { break; }
		}
		return $out;
	}

	/**
	 * The footer's REAL background + text color, read from the captured `data-sc-cs` computed-style attribute
	 * on the <footer> element (the capture service records the resolved color there, e.g. a Tailwind
	 * `bg-foreground` → `background-color:rgb(41, 61, 54)`). Returns [ 'bg' => hex/rgb|'', 'text' => …|'' ].
	 * This fixes the "footer lost its dark background" regression: without it the mapping fell back to a
	 * generic `#141414`, dropping the source's actual (often brand-tinted) dark footer color.
	 */
	private static function detect_footer_style( $html ) {
		$out = array( 'bg' => '', 'text' => '' );
		$dom = self::load_dom( $html );
		if ( ! $dom ) { return $out; }
		$footer = $dom->getElementsByTagName( 'footer' )->item( 0 );
		if ( ! ( $footer instanceof DOMElement ) ) { return $out; }
		$cs = (string) $footer->getAttribute( 'data-sc-cs' );
		if ( $cs === '' ) { return $out; }
		if ( preg_match( '/(?:^|;)\s*background-color\s*:\s*([^;]+)/i', $cs, $m ) ) {
			$c = trim( $m[1] );
			// Ignore a transparent / fully-see-through footer (no real fill to reproduce).
			if ( $c !== '' && stripos( $c, 'transparent' ) === false && ! preg_match( '/rgba\([^)]*,\s*0\s*\)/i', $c ) ) { $out['bg'] = $c; }
		}
		if ( preg_match( '/(?:^|;)\s*color\s*:\s*([^;]+)/i', $cs, $m ) ) { $out['text'] = trim( $m[1] ); }
		return $out;
	}

	/**
	 * Read a padding side ('top'|'bottom') from an element's computed style, handling BOTH the `padding-top`
	 * longhand AND the `padding` shorthand (`padding:T R B L` / `T V` / `T RL B` — the capture often emits
	 * the shorthand, e.g. FreshPaws footer `padding:64px 0px 32px`). Returns the length string or ''.
	 */
	private static function sc_pad( $el, $side ) {
		$long = self::sc_css( $el, 'padding-' . $side );
		if ( $long !== '' ) { return $long; }
		$sh = self::sc_css( $el, 'padding' );
		if ( $sh === '' ) { return ''; }
		$p = preg_split( '/\s+/', trim( $sh ) );
		$n = count( $p );
		if ( $n === 1 ) { return $p[0]; }
		if ( $n === 2 ) { return $p[0]; }              // T/B = first
		if ( $n >= 3 )  { return ( $side === 'top' ) ? $p[0] : $p[2]; } // T … B
		return '';
	}

	/** A CSS length is "present" (a non-zero px/rem/em value). '' / '0' / '0px' / 'none' are absent. */
	private static function css_len_present( $v ) {
		$v = trim( (string) $v );
		return $v !== '' && $v !== 'none' && ! preg_match( '/^0(?:px|rem|em|%)?$/', $v );
	}

	/** A CSS length → a spacing string in rem (px÷16), e.g. `64px` → `4rem`, `1.5rem` → `1.5rem`. '' if unparseable. */
	private static function css_len_to_rem( $v ) {
		$v = trim( (string) $v );
		if ( preg_match( '/^(-?[0-9.]+)px$/', $v, $m ) ) { $r = round( ( (float) $m[1] ) / 16, 3 ); return rtrim( rtrim( (string) $r, '0' ), '.' ) . 'rem'; }
		if ( preg_match( '/^(-?[0-9.]+)(rem|em)$/', $v, $m ) ) { return $m[1] . 'rem'; }
		return '';
	}

	/**
	 * Convert a source chrome/content container BOX width (its computed max-width, which INCLUDES the
	 * source's own horizontal gutters) into the UnysonPlus CONTENT width to store as Container Width.
	 * UnysonPlus's Container Width is a CONTENT width and the theme ADDS gutters OUTSIDE it (rendered
	 * .fw-container box = content + 2 x --container-gutter, default 1.5rem = 24px each side, with
	 * box-sizing:border-box). So to make the RENDERED box equal the source box, store box - 2*24 = box-48.
	 * Falls back to the raw box when the subtraction would drop below a sane floor.
	 */
	private static function chrome_box_to_content_px( $box_px ) {
		$box     = (int) round( (float) $box_px );
		$content = $box - 48; // theme default gutter 1.5rem (24px) each side; box = content + 2*gutter
		return ( $content >= 320 ) ? $content : $box;
	}

	/** A CSS length → a unit-input value array `{ value, unit }` (px|rem|em). null if unparseable. */
	private static function css_len_to_unit( $v ) {
		$v = trim( (string) $v );
		if ( preg_match( '/^(-?[0-9.]+)\s*(px|rem|em)?$/', $v, $m ) ) { return array( 'value' => $m[1], 'unit' => ( isset( $m[2] ) && $m[2] !== '' ? $m[2] : 'px' ) ); }
		return null;
	}

	/**
	 * The HEADER's chrome styles read from the <header> computed `data-sc-cs`:
	 *   bg (background-color, non-transparent), border (bottom hairline present), shadow (box-shadow present),
	 *   glass (backdrop-filter blur), pad_top/pad_bottom, radius (border-radius when rounded).
	 * Only keys we actually detected are present (callers must not write defaults).
	 */
	/**
	 * The header/footer INNER CONTENT container width, read from computed styles (data-sc-cs). The cap lives
	 * on the wrapper that centers the content (a `.container` / `.mx-auto` / `max-w-*`), NOT the
	 * <header>/<footer> element itself (that's why the earlier style pass — which only looked at the element
	 * — left it unset). Returns the LARGEST capped `max-width` in px (the OUTERMOST content container; inner
	 * text columns cap smaller, so the max is the real content width), 'fluid' when a wrapper spans full width
	 * (container-fluid / max-w-full / max-w-none with no numeric cap), or '' when no content wrapper is found.
	 * Mirrors the mirror-path container algorithm (the largest matching cap wins).
	 */
	/**
	 * Does the source site use the literal Tailwind `container` class for its content wrapper? True when a
	 * `<header>`/`<footer>`/`<section>`/`<main>` descendant carries the exact `container` token (NOT
	 * `container-fluid`, NOT a fixed `max-w-[..]`). Signals a RESPONSIVE breakpoint ladder (sm/md/lg/xl/2xl)
	 * rather than a single fixed max-width, so the container-width mapping emits the upper tiers as scoped CSS.
	 */
	private static function site_uses_tw_container( $html ) {
		$dom = self::load_dom( (string) $html );
		if ( ! $dom ) { return false; }
		foreach ( array( 'header', 'footer', 'section', 'main' ) as $tag ) {
			foreach ( $dom->getElementsByTagName( $tag ) as $root ) {
				foreach ( $root->getElementsByTagName( '*' ) as $el ) {
					$cls = ' ' . strtolower( self::cls( $el ) ) . ' ';
					if ( strpos( $cls, ' container ' ) !== false ) { return true; } // exact token, not container-fluid
				}
			}
		}
		return false;
	}

	private static function detect_chrome_container( $root ) {
		if ( ! ( $root instanceof DOMElement ) ) { return ''; }
		$best = 0.0; $saw_wrap = false; $saw_fluid = false;
		foreach ( $root->getElementsByTagName( '*' ) as $el ) {
			$cls = ' ' . strtolower( self::cls( $el ) ) . ' ';
			$is_wrap = ( strpos( $cls, ' container ' ) !== false || strpos( $cls, ' container-fluid ' ) !== false
				|| strpos( $cls, ' mx-auto ' ) !== false || (bool) preg_match( '/\smax-w-/', $cls ) );
			if ( ! $is_wrap ) { continue; }
			$saw_wrap = true;
			if ( strpos( $cls, ' container-fluid ' ) !== false || preg_match( '/\smax-w-(full|none)\b/', $cls ) ) { $saw_fluid = true; }
			$mw = trim( (string) self::sc_css( $el, 'max-width' ) );
			if ( $mw === '' || strtolower( $mw ) === 'none' ) { continue; }
			if ( preg_match( '/^([0-9.]+)px$/', $mw, $m ) ) {
				$px = (float) $m[1];
				if ( $px >= 320 && $px <= 2200 && $px > $best ) { $best = $px; }
			}
		}
		if ( $best > 0 ) { return (string) (int) round( $best ); }
		if ( $saw_wrap && $saw_fluid ) { return 'fluid'; }
		return '';
	}

	private static function detect_header_chrome_styles( $html ) {
		$out = array();
		$dom = self::load_dom( $html );
		if ( ! $dom ) { return $out; }
		$header = self::header_root( $dom );
		if ( ! ( $header instanceof DOMElement ) ) { return $out; }
		$cw = self::detect_chrome_container( $header );
		if ( $cw !== '' ) { $out['container'] = $cw; }
		$bg = self::sc_css( $header, 'background-color' );
		if ( $bg !== '' && stripos( $bg, 'transparent' ) === false && ! preg_match( '/rgba\([^)]*,\s*0\s*\)/i', $bg ) ) { $out['bg'] = $bg; }
		if ( self::css_len_present( self::sc_css( $header, 'border-bottom-width' ) ) ) {
			$st = self::sc_css( $header, 'border-bottom-style' );
			if ( $st !== '' && $st !== 'none' ) { $out['border'] = true; }
		}
		$sh = self::sc_css( $header, 'box-shadow' );
		if ( $sh !== '' && $sh !== 'none' ) { $out['shadow'] = true; }
		if ( stripos( self::sc_css( $header, 'backdrop-filter' ), 'blur' ) !== false
			|| stripos( self::sc_css( $header, '-webkit-backdrop-filter' ), 'blur' ) !== false ) { $out['glass'] = true; }
		$pt = self::sc_pad( $header, 'top' );  if ( $pt !== '' ) { $out['pad_top'] = $pt; }
		$pb = self::sc_pad( $header, 'bottom' ); if ( $pb !== '' ) { $out['pad_bottom'] = $pb; }
		// Mobile breakpoint: the responsive class that swaps the inline nav for the hamburger — a desktop nav
		// `hidden md:flex` / a `md:hidden` toggle ⇒ mobile below 768px (`md`); the `lg:` variants ⇒ below 992px
		// (`lg`, the theme default). Only emit on a real signal so we never write a false default.
		$bp = '';
		foreach ( $header->getElementsByTagName( '*' ) as $el ) {
			$c = ' ' . self::cls( $el ) . ' ';
			if ( preg_match( '/\shidden\s+(md|lg):flex\b/', $c, $m ) || preg_match( '/\s(md|lg):hidden\b/', $c, $m ) ) { $bp = $m[1]; break; }
		}
		if ( $bp === 'md' || $bp === 'lg' ) { $out['mobile_breakpoint'] = $bp; }
		return $out;
	}

	/**
	 * The primary NAV MENU link styling, read from the header nav <a> computed styles. Returns any of:
	 *   link_color (the NORMAL link color = the most common), hover_color (a different/active link color),
	 *   font_size ({value,unit}), font_weight (string), uppercase (bool). Excludes the brand link (has an
	 *   svg/img) and CTA buttons, so only real nav links are sampled.
	 */
	private static function detect_menu_styles( $html ) {
		$out = array();
		$dom = self::load_dom( $html );
		if ( ! $dom ) { return $out; }
		$header = self::header_root( $dom );
		if ( ! ( $header instanceof DOMElement ) ) { return $out; }
		$colors = array(); $fs = ''; $fw = ''; $tt = '';
		foreach ( $header->getElementsByTagName( 'a' ) as $a ) {
			if ( self::is_button( $a ) ) { continue; }                                   // skip the CTA
			if ( $a->getElementsByTagName( 'svg' )->length || $a->getElementsByTagName( 'img' )->length ) { continue; } // skip brand/icon links
			$txt = trim( self::text_no_icons( $a ) );
			if ( $txt === '' || str_word_count( $txt ) > 4 ) { continue; }               // a nav label is short
			$c = self::sc_css( $a, 'color' );
			if ( $c !== '' ) { $colors[] = $c; }
			if ( $fs === '' ) { $fs = self::sc_css( $a, 'font-size' ); }
			if ( $fw === '' ) { $fw = self::sc_css( $a, 'font-weight' ); }
			if ( $tt === '' ) { $tt = self::sc_css( $a, 'text-transform' ); }
		}
		if ( $colors ) {
			$freq = array_count_values( $colors );
			arsort( $freq );
			$normal = (string) array_key_first( $freq );
			$out['link_color'] = $normal;
			foreach ( $colors as $c ) { if ( $c !== $normal ) { $out['hover_color'] = $c; break; } } // the active/odd one = hover
		}
		if ( $fs !== '' ) { $u = self::css_len_to_unit( $fs ); if ( $u ) { $out['font_size'] = $u; } }
		if ( preg_match( '/^(300|400|500|600|700|800)$/', trim( $fw ) ) ) { $out['font_weight'] = trim( $fw ); }
		if ( $tt !== '' ) { $out['uppercase'] = ( stripos( $tt, 'uppercase' ) !== false ); }
		return $out;
	}

	/**
	 * FOOTER chrome styles from the <footer> computed `data-sc-cs`: pad_top/pad_bottom (lengths), border
	 * (top hairline {width,style,color} when present), radius (top border-radius when the footer is rounded,
	 * e.g. `rounded-t-[3rem]`). Only detected keys present.
	 */
	private static function detect_footer_chrome_styles( $html ) {
		$out = array();
		$dom = self::load_dom( $html );
		if ( ! $dom ) { return $out; }
		$footer = $dom->getElementsByTagName( 'footer' )->item( 0 );
		if ( ! ( $footer instanceof DOMElement ) ) { return $out; }
		$cw = self::detect_chrome_container( $footer );
		if ( $cw !== '' ) { $out['container'] = $cw; }
		$pt = self::sc_pad( $footer, 'top' );    if ( $pt !== '' ) { $out['pad_top'] = $pt; }
		$pb = self::sc_pad( $footer, 'bottom' ); if ( $pb !== '' ) { $out['pad_bottom'] = $pb; }
		if ( self::css_len_present( self::sc_css( $footer, 'border-top-width' ) ) ) {
			$st = self::sc_css( $footer, 'border-top-style' );
			if ( $st !== '' && $st !== 'none' ) {
				$out['border'] = array(
					'width' => self::sc_css( $footer, 'border-top-width' ),
					'style' => $st,
					'color' => self::sc_css( $footer, 'border-top-color' ),
				);
			}
		}
		// Top border-radius (a rounded footer top) — no native footer option ⇒ callers emit scoped CSS.
		$br = self::sc_css( $footer, 'border-radius' );
		if ( $br !== '' && preg_match( '/(?:^|\s)([1-9][0-9.]*(?:px|rem|em))/', $br, $m ) ) { $out['radius'] = trim( $br ); }
		return $out;
	}

	/**
	 * A split-slider ratio value: a list of `{ w:int, name:'' }` segments summing to 100 (the twelfths
	 * split-slider snaps them to the 12-grid). EQUAL by default; when $wide, the first (brand) column gets
	 * 2 units and the rest 1 unit each (e.g. 4 cols → 2/5+1/5+1/5+1/5 ≈ 40/20/20/20). Mirrors the theme's
	 * unysonplus_footer_equal_split() shape so the footer builder resolves it to `fw-col-md-N` correctly.
	 */
	private static function footer_split_segments( $n, $wide = false ) {
		$n = max( 1, (int) $n );
		if ( $wide && $n >= 3 ) {
			$units = 2 + ( $n - 1 );          // brand = 2 units, others = 1 unit each
			$first = (int) round( 2 / $units * 100 );
			$rest  = (int) round( 1 / $units * 100 );
			$segs  = array( array( 'w' => $first, 'name' => '' ) );
			for ( $i = 1; $i < $n; $i++ ) { $segs[] = array( 'w' => $rest, 'name' => '' ); }
			$segs[0]['w'] += 100 - array_sum( wp_list_pluck( $segs, 'w' ) ); // absorb rounding into the brand col
			return $segs;
		}
		$each = (int) floor( 100 / $n );
		$segs = array();
		for ( $i = 0; $i < $n; $i++ ) { $segs[] = array( 'w' => $each, 'name' => '' ); }
		$segs[0]['w'] += 100 - ( $each * $n ); // first column absorbs the remainder → segments sum to 100
		return $segs;
	}

	/**
	 * Is the footer's FIRST (brand) column genuinely wider than the rest (~1.5-2x), so the ratio should
	 * weight it? Conservative — returns false (⇒ equal columns) unless there's a clear signal, so we NEVER
	 * default to a wide first column. Signals: the brand column carries a Tailwind `col-span-{2..}` (it spans
	 * multiple grid tracks), or the footer grid's captured `grid-template-columns` first track is ≥1.4x the
	 * median of the others. FreshPaws is `grid-cols-4` (equal, no span) ⇒ false ⇒ equal split.
	 */
	private static function detect_footer_wide_brand( $html, $count ) {
		if ( (int) $count < 3 ) { return false; }
		$dom = self::load_dom( $html );
		if ( ! $dom ) { return false; }
		$footer = $dom->getElementsByTagName( 'footer' )->item( 0 );
		if ( ! ( $footer instanceof DOMElement ) ) { return false; }
		// Find the footer's primary column grid: a container whose class names a multi-column grid.
		$grid = null;
		foreach ( $footer->getElementsByTagName( 'div' ) as $d ) {
			$cls = self::cls( $d );
			if ( preg_match( '/\bgrid-cols-[2-9]\b|\bgrid-template-columns\b/', $cls ) || ( strpos( $cls, 'grid' ) !== false && preg_match( '/(?:sm|md|lg|xl):grid-cols-[2-9]/', $cls ) ) ) { $grid = $d; break; }
		}
		if ( ! ( $grid instanceof DOMElement ) ) { return false; }
		// 1) grid-template-columns tracks (from computed style): first ≥ 1.4x the median of the rest.
		$gtc = self::sc_css( $grid, 'grid-template-columns' );
		if ( $gtc !== '' && preg_match_all( '/([0-9.]+)px/', $gtc, $mm ) && count( $mm[1] ) >= 3 ) {
			$tracks = array_map( 'floatval', $mm[1] );
			$first  = $tracks[0];
			$others = array_slice( $tracks, 1 );
			sort( $others );
			$mid = $others[ (int) floor( count( $others ) / 2 ) ];
			if ( $mid > 0 && $first >= 1.4 * $mid ) { return true; }
		}
		// 2) The first column child carries a col-span-{2..} (spans multiple tracks).
		$kids = self::el_children( $grid );
		if ( ! empty( $kids ) ) {
			$fc = self::cls( $kids[0] );
			if ( preg_match( '/(?:^|\s|:)col-span-([2-9])/', $fc ) ) { return true; }
		}
		return false;
	}

	/** The footer brand description (a substantial <p> that is NOT the copyright line), or ''. */
	private static function detect_footer_tagline( $html ) {
		$dom = self::load_dom( $html );
		if ( ! $dom ) { return ''; }
		$footer = $dom->getElementsByTagName( 'footer' )->item( 0 );
		if ( ! $footer ) { return ''; }
		foreach ( $footer->getElementsByTagName( 'p' ) as $p ) {
			$t = trim( preg_replace( '/\s+/', ' ', (string) $p->textContent ) );
			if ( $t !== '' && strlen( $t ) >= 40
				&& strpos( $t, '©' ) === false && stripos( $t, 'rights reserved' ) === false && stripos( $t, 'copyright' ) === false ) {
				return $t;
			}
		}
		return '';
	}

	/**
	 * The theme-design.json payload — the **design-config** the bundle's theme phase feeds to
	 * `FW_Site_Converter_Theme_Generator::install()` to generate a **child theme** (the plan's target).
	 * Maps the Stitch tokens + the screen's chrome to the generator's config shape: fonts, colors,
	 * header (pill/bar, CTA, nav), footer (links), and component CSS (cards) under `custom_css`. The
	 * generator bakes the palette/fonts into the child theme's own style.css, so the converted site
	 * loads ONE clean child stylesheet — no `misc_custom_css` on the active theme.
	 *
	 * @param array  $tokens
	 * @param string $html  the home screen markup (for header/footer detection)
	 * @param string $title page/theme name
	 * @return array design-config
	 */
	public static function tokens_to_design_config( array $tokens, $html, $title ) {
		list( $head_font, $body_font ) = self::pick_fonts_raw( $tokens );
		$google = '';
		foreach ( ( $tokens['fonts'] ?? array() ) as $href ) { $google = $href; break; }
		// Fall back to the families named in the Google-Fonts URL when the tailwind.config had no fontFamily.
		$gfonts = self::fonts_from_google( $google );
		if ( $head_font === '' && isset( $gfonts[0] ) ) { $head_font = $gfonts[0]; }
		if ( $body_font === '' ) { $body_font = isset( $gfonts[1] ) ? $gfonts[1] : ( isset( $gfonts[0] ) ? $gfonts[0] : '' ); }

		$ink    = self::token_color( $tokens, array( 'text', 'on-background', 'on-surface' ) );
		$bg     = self::token_color( $tokens, array( 'page-bg', 'background', 'surface', 'white-soft' ) );
		// The brand / ACTION color (the button fill, nav-hover, heading accent). Both Stitch and
		// Material-3 put it in `primary`; the `*-container` fills and the `error` red are NOT it.
		$accent = self::token_color( $tokens, array( 'accent', 'primary', 'brand', 'cta' ) );
		// No explicit brand color? Look for a vivid one in the markup (Stitch sometimes hides the
		// accent in a gradient like `from-[#FF416C]`); failing that, fall to the design's own dark
		// tone. A MONOCHROME design (e.g. a neutral Material export with black CTAs) is valid — never
		// force a vivid accent onto it the way the old `#FF4B2B` fallback did.
		if ( $accent === '' ) {
			$accent = self::scan_accent( (string) $html );
			if ( $accent === '' ) { $accent = self::token_color( $tokens, array( 'tertiary', 'secondary', 'on-surface', 'on-background' ) ); }
			if ( $accent === '' ) { $accent = $ink !== '' ? $ink : '#141414'; }
		}
		$line   = self::token_color( $tokens, array( 'line', 'outline-variant', 'outline' ) );
		$dark   = self::token_color( $tokens, array( 'deep-black', 'black', 'surface-container-lowest' ) );
		if ( $dark === '' ) { $dark = '#141414'; }
		$ftext  = self::token_color( $tokens, array( 'page-bg', 'on-background', 'white-soft' ) );

		$hdr  = self::detect_header( (string) $html );
		// Theme NAME = the site's BRAND, not the page role. The URL flow passes the generic page title
		// 'Home', which produced a "Home (Child)" theme. When the passed title is empty or the 'Home'
		// default, derive the brand from the source <title> (the segment before a  |  –  —  ·  separator)
		// so the theme is named e.g. "FreshPaws" instead of "Home".
		$passed = trim( (string) $title );
		if ( $passed === '' || 0 === strcasecmp( $passed, 'Home' ) ) {
			$raw   = trim( (string) self::title_from_html( (string) $html, '' ) );
			$brand = $raw !== '' ? trim( (string) preg_split( '/\s*[|\x{2013}\x{2014}\x{00B7}]\s*/u', $raw, 2 )[0] ) : '';
			$name  = $brand !== '' ? $brand : ( $passed !== '' ? $passed : 'Converted Site' );
		} else {
			$name = $passed;
		}

		return array(
			'theme'  => array( 'name' => $name, 'slug' => sanitize_title( $name ), 'mode' => 'child' ),
			'fonts'  => array( 'heading' => $head_font, 'body' => $body_font, 'google' => $google ),
			'colors' => array(
				'ink'          => $ink !== '' ? $ink : '#1a1a1a',
				'accent'       => $accent,
				'bg'           => $bg !== '' ? $bg : '#ffffff',
				'header_bg'    => $hdr['dark'] ? $dark : '',
				'header_border'=> $line !== '' ? $line : '#ececec',
				'footer_bg'    => $dark,
				'footer_text'  => $ftext !== '' ? $ftext : '#f5f5f5',
			),
			'header' => array(
				'style'         => $hdr['style'],
				'sticky'        => $hdr['sticky'],
				'menu_location' => 'primary',
				'menu'          => self::design_menu( (string) $html, 'primary' ),
				'cta'           => array(
					'enabled' => $hdr['cta']['label'] !== '',
					'label'   => $hdr['cta']['label'] !== '' ? $hdr['cta']['label'] : 'Get started',
					'href'    => $hdr['cta']['href'] !== '' ? $hdr['cta']['href'] : '#',
				),
			),
			'footer' => array(
				'brand'       => true,
				'widget_area' => false,
				'copyright'   => 'All rights reserved.',
				'menu'        => self::design_menu( (string) $html, 'footer' ),
			),
			'background' => array( 'dotted' => false, 'canvas' => $bg !== '' ? $bg : '#ffffff' ),
			'custom_css' => self::tokens_to_component_css( $tokens ),
		);
	}

	/** Detect the Stitch header's chrome: pill vs bar, sticky, dark fill, and the CTA button. */
	/**
	 * The header root element: a real <header>, or — for the many modern landing pages (Stitch
	 * outputs included) that use a bare top-level sticky/fixed <nav> as the site bar instead of a
	 * <header> — that <nav>. A <nav> nested inside <main>/<section>/<footer> is in-content, not the
	 * header, so it's skipped. Falls back to the first top-level <nav> when none is explicitly sticky.
	 *
	 * @param DOMDocument $dom
	 * @return DOMElement|null
	 */
	private static function header_root( $dom ) {
		$header = $dom->getElementsByTagName( 'header' )->item( 0 );
		$body   = $dom->getElementsByTagName( 'body' )->item( 0 );
		// A full-viewport <header> that holds the H1 + CTA (openhero-style: `<header class="min-h-screen">`,
		// with the real nav in a SEPARATE <nav>) is a HERO band, not the masthead — don't let it become
		// chrome. When the first <header> is a hero, prefer a distinct top-level sticky/fixed <nav>. Mirrors
		// the capture service's isHeroHeader() split.
		$header_is_hero = self::is_hero_header( $header );
		if ( $header && ! $header_is_hero ) { return $header; }
		if ( ! $body ) { return $header; }
		$first = null;
		foreach ( $body->getElementsByTagName( 'nav' ) as $nav ) {
			if ( self::has_ancestor_tag( $nav, 'main', $body )
				|| self::has_ancestor_tag( $nav, 'section', $body )
				|| self::has_ancestor_tag( $nav, 'footer', $body ) ) {
				continue;
			}
			if ( $header_is_hero && self::node_within( $nav, $header ) ) { continue; } // a nav INSIDE the hero isn't the masthead
			$c = self::cls( $nav );
			if ( strpos( $c, 'fixed' ) !== false || strpos( $c, 'sticky' ) !== false || strpos( $c, 'top-0' ) !== false ) {
				return $nav;
			}
			if ( $first === null ) { $first = $nav; }
		}
		if ( $first !== null ) { return $first; }
		return $header; // no separate nav found → keep the header (hero stays chrome, as before the split)
	}

	/**
	 * Is this <header> actually a full-viewport HERO (not the site masthead)? A hero is tall
	 * (min-h-screen / h-screen / min-h-[NNvh], or a data-sc-cs min-height ≥ 60vh) AND leads with a big
	 * heading. A masthead is short and nav-like. Class-based (this path has no layout engine), with a
	 * computed-style fallback when the capture annotated data-sc-cs. Mirrors the JS isHeroHeader().
	 *
	 * @param DOMElement|null $el
	 * @return bool
	 */
	private static function is_hero_header( $el ) {
		if ( ! $el ) { return false; }
		$c    = ' ' . self::cls( $el ) . ' ';
		$tall = ( strpos( $c, ' min-h-screen ' ) !== false ) || ( strpos( $c, ' h-screen ' ) !== false )
			|| preg_match( '/\b(?:min-)?h-\[\s*(?:[6-9]\d|1\d\d)(?:vh|dvh|svh)/', $c );
		if ( ! $tall ) {
			$cs = (string) $el->getAttribute( 'data-sc-cs' );
			if ( $cs !== '' && preg_match( '/min-height:\s*([\d.]+)(px|vh|dvh|svh)/', $cs, $m ) ) {
				$v    = (float) $m[1];
				$tall = ( 'px' === $m[2] ) ? ( $v >= 480 ) : ( $v >= 60 ); // ~60% of an 800px viewport, or 60vh
			}
		}
		if ( ! $tall ) { return false; }
		return $el->getElementsByTagName( 'h1' )->length > 0 || $el->getElementsByTagName( 'h2' )->length > 0;
	}

	/** True when $node is a descendant of $ancestor (node-path prefix — robust against PHP DOM wrapper identity). */
	private static function node_within( $node, $ancestor ) {
		if ( ! $node || ! $ancestor ) { return false; }
		$ap = $ancestor->getNodePath();
		return $ap !== '' && strpos( (string) $node->getNodePath(), $ap . '/' ) === 0;
	}

	private static function detect_header( $html ) {
		$out = array( 'style' => 'bar', 'sticky' => false, 'dark' => false, 'cta' => array( 'label' => '', 'href' => '', 'style' => '' ) );
		$dom = self::load_dom( $html );
		if ( ! $dom ) { return $out; }
		$header = self::header_root( $dom );
		if ( ! $header ) { return $out; }
		$hcls = self::cls( $header );
		if ( strpos( $hcls, 'sticky' ) !== false || strpos( $hcls, 'fixed' ) !== false ) { $out['sticky'] = true; }
		// A pill nav: a container with rounded-full. Dark fill if it carries a near-black bg.
		foreach ( $header->getElementsByTagName( 'div' ) as $d ) {
			$c = self::cls( $d );
			if ( strpos( $c, 'rounded-full' ) !== false ) {
				$out['style'] = 'pill';
				if ( preg_match( '/bg-(?:black|zinc-9|neutral-9|gray-9|slate-9|stone-9|\[#0|\[#1[0-9a-f]{2}\b)/', $c ) || strpos( $c, 'bg-deep' ) !== false || strpos( $c, 'bg-[#000' ) !== false ) {
					$out['dark'] = true;
				}
				break;
			}
		}
		// CTA = the header's button (or a button-styled link). Capture its FILL class too so the converted
		// CTA references the MATCHING button preset (a `bg-secondary` amber header button → btn-secondary, not
		// the hardcoded btn-primary), mirroring build_button_presets()' role → slug assignment.
		$cta_node = null;
		foreach ( $header->getElementsByTagName( 'button' ) as $b ) { $out['cta']['label'] = self::text_no_icons( $b ); $cta_node = $b; break; }
		if ( $out['cta']['label'] === '' ) {
			foreach ( $header->getElementsByTagName( 'a' ) as $a ) {
				if ( self::is_button( $a ) ) { $out['cta']['label'] = self::text_no_icons( $a ); $out['cta']['href'] = $a->getAttribute( 'href' ); $cta_node = $a; break; }
			}
		}
		if ( $cta_node instanceof DOMElement ) {
			$cc = ' ' . strtolower( self::cls( $cta_node ) ) . ' ';
			if ( preg_match( '/\sbg-(primary|brand)\b/', $cc ) )   { $out['cta']['style'] = 'btn-primary'; }
			elseif ( preg_match( '/\sbg-(secondary|accent|cta)\b/', $cc ) ) { $out['cta']['style'] = 'btn-secondary'; }
			elseif ( ( strpos( $cc, ' border' ) !== false ) && ( strpos( $cc, ' bg-white' ) !== false || ! preg_match( '/\sbg-(?!transparent)/', $cc ) ) ) { $out['cta']['style'] = 'btn-outline'; }
		}
		return $out;
	}

	/** The design-config menu items (label/url list) for a location, from the page chrome. */
	private static function design_menu( $html, $location ) {
		$m = self::extract_menus( $html );
		foreach ( $m['menus'] as $menu ) {
			if ( ( $menu['location'] ?? '' ) === $location ) { return $menu['items']; }
		}
		return array();
	}

	/** Component CSS baked into the child theme (cards / image rounding) — palette/fonts the generator does. */
	private static function tokens_to_component_css( array $tokens ) {
		$surface = self::token_color( $tokens, array( 'white-card', 'soft-card-2', 'surface-container-low', 'panel-bg', 'surface-container' ) );
		$line    = self::token_color( $tokens, array( 'line', 'outline-variant' ) );
		$muted   = self::token_color( $tokens, array( 'muted', 'on-surface-variant' ) );
		$radius  = '';
		foreach ( array( '3xl', 'xl', 'lg', 'full' ) as $k ) { if ( ! empty( $tokens['rounded'][ $k ] ) && ! is_array( $tokens['rounded'][ $k ] ) ) { $radius = (string) $tokens['rounded'][ $k ]; break; } }
		if ( $radius === '' ) { $radius = '20px'; }
		$surface = $surface !== '' ? $surface : '#ffffff';
		$line    = $line !== '' ? $line : '#ececec';
		$muted   = $muted !== '' ? $muted : '#8a8a8a';
		$out = array();
		$out[] = ".icon-box{background:$surface;border:1px solid $line;border-radius:$radius;padding:32px;height:100%;box-shadow:0 16px 38px -26px rgba(20,20,20,.18);}";
		$out[] = ".icon-box__title{font-weight:700;margin-bottom:8px;}";
		$out[] = ".icon-box__content{color:$muted;line-height:1.6;}";
		// Content images render block + centered so a converted <img> never flows INLINE next to
		// inline-block buttons (which baseline-aligned them to the bottom of a tall hero image).
		$out[] = "section img,.fw-main-row img{border-radius:$radius;max-width:100%;display:block;margin-left:auto;margin-right:auto;height:auto;}";
		return implode( "\n", $out );
	}

	/** (headline, body) RAW font family names from the fontFamily tokens (the generator wraps them). */
	private static function pick_fonts_raw( array $tokens ) {
		$ff = $tokens['fontFamily'] ?? array();
		$first = function ( $keys ) use ( $ff ) {
			foreach ( $keys as $k ) {
				if ( isset( $ff[ $k ] ) ) {
					$v = is_array( $ff[ $k ] ) ? reset( $ff[ $k ] ) : $ff[ $k ];
					$v = trim( (string) $v );
					if ( $v !== '' ) { return $v; }
				}
			}
			return '';
		};
		return array(
			$first( array( 'headline-xl', 'headline-lg', 'headline-md', 'display', 'h1', 'h2', 'heading' ) ),
			$first( array( 'body-md', 'body-lg', 'body', 'label', 'label-sm' ) ),
		);
	}

	/* ---------------------------------------------------------------------- *
	 * Media
	 * ---------------------------------------------------------------------- */

	/** Collect raster image URLs from the Stitch HTML (reuses the media engine's scanner). */
	public static function scan_images( $html ) {
		if ( class_exists( 'FW_Site_Converter_Media' ) && method_exists( 'FW_Site_Converter_Media', 'scan_html' ) ) {
			$urls = FW_Site_Converter_Media::scan_html( (string) $html, '' );
			return is_array( $urls ) ? array_values( array_unique( $urls ) ) : array();
		}
		// Minimal fallback: <img src> only.
		$out = array();
		if ( preg_match_all( '/<img[^>]+src=["\']([^"\']+)["\']/i', (string) $html, $m ) ) {
			foreach ( $m[1] as $u ) { if ( strpos( $u, 'data:' ) !== 0 ) { $out[] = $u; } }
		}
		return array_values( array_unique( $out ) );
	}

	/* ---------------------------------------------------------------------- *
	 * HTML → Mapper mapping (the section/block tree the Mapper builds pages from)
	 * ---------------------------------------------------------------------- */

	/**
	 * Parse a Stitch `code.html` body into the Mapper's role-annotated mapping
	 * (`{ pages: [ { title, slug, front_page, sections: [ { sectionClass, css_id, blocks:[…role] } ] } ] }`),
	 * which `FW_Site_Converter_Mapper::build_pages()` turns into a page-builder tree.
	 *
	 * @param string $html
	 * @param string $title page title
	 * @param string $slug  page slug ('' → derived)
	 * @param bool   $front front page?
	 * @return array mapping with exactly one page
	 */
	/** Parsed `<style>` rules that set max-width: [ { selector, value }, … ] — for non-Tailwind sources. */
	private static $mw_rules = array();

	/** Section skip flags — mirror the capture service's --skip-sections / --only-sections. 0-based
	 *  s_index (the section number shown in the conversion report). Set by the Convert handler. */
	public static $skip_sections = array();
	public static $only_sections = array();

	/** Collect `selector { … max-width:VAL … }` rules from the source's <style> blocks. */
	private static function parse_style_max_width( $html ) {
		$out = array();
		if ( ! preg_match_all( '/<style[^>]*>(.*?)<\/style>/is', (string) $html, $sm ) ) { return $out; }
		$css = implode( "\n", $sm[1] );
		$css = preg_replace( '#/\*.*?\*/#s', '', $css ); // strip comments
		if ( preg_match_all( '/([^{}]+)\{([^{}]*)\}/s', $css, $rm, PREG_SET_ORDER ) ) {
			foreach ( $rm as $r ) {
				if ( ! preg_match( '/(?:^|;|\s)max-width\s*:\s*([0-9.]+(?:px|rem|em|%|ch|vw))/i', $r[2], $mw ) ) { continue; }
				foreach ( explode( ',', $r[1] ) as $sel ) {
					$sel = trim( $sel );
					if ( $sel !== '' && strpos( $sel, '@' ) === false ) { $out[] = array( 'selector' => $sel, 'value' => $mw[1] ); }
				}
			}
		}
		return $out;
	}

	/** Tailwind named max-w-* → its CSS length (so max-w-2xl etc. resolve like an arbitrary value). */
	private static function tw_max_w_named( $name ) {
		static $scale = array( 'xs' => '20rem', 'sm' => '24rem', 'md' => '28rem', 'lg' => '32rem', 'xl' => '36rem', '2xl' => '42rem', '3xl' => '48rem', '4xl' => '56rem', '5xl' => '64rem', '6xl' => '72rem', '7xl' => '80rem', 'prose' => '65ch' );
		return isset( $scale[ $name ] ) ? $scale[ $name ] : '';
	}

	/**
	 * The max-width applied to an element, from ANY source (not just a Tailwind class): an inline
	 * `style="max-width:…"`, a Tailwind `max-w-[…]` / `max-w-2xl`, or a matching `<style>` rule. Returns
	 * a CSS length ("620px", "42rem") or '' when none.
	 *
	 * @param DOMElement $el
	 */
	private static function element_max_width( $el ) {
		if ( ! ( $el instanceof DOMElement ) ) { return ''; }
		// 1. Inline style.
		$style = $el->getAttribute( 'style' );
		if ( $style !== '' && preg_match( '/(?:^|;|\s)max-width\s*:\s*([0-9.]+(?:px|rem|em|%|ch|vw))/i', $style, $m ) ) { return $m[1]; }
		// 2. Tailwind class (arbitrary, then named).
		$cls = self::cls( $el );
		if ( preg_match( '/(?:^|\s)max-w-\[(\d+(?:\.\d+)?(?:px|rem|em|%|ch|vw))\]/', $cls, $m ) ) { return $m[1]; }
		if ( preg_match( '/(?:^|\s)max-w-([a-z0-9]+)(?:\s|$)/', $cls, $m ) ) { $v = self::tw_max_w_named( $m[1] ); if ( $v !== '' ) { return $v; } }
		// 3. A <style> rule whose selector matches this element.
		foreach ( self::$mw_rules as $rule ) {
			if ( self::selector_matches_el( (string) $rule['selector'], $el, $cls ) ) { return (string) $rule['value']; }
		}
		return '';
	}

	/** Does a CSS selector's RIGHTMOST compound (tag / .class / #id) match this element? (No ancestors.) */
	private static function selector_matches_el( $selector, $el, $cls ) {
		$parts = preg_split( '/\s*[>+~]\s*|\s+/', trim( $selector ) );
		$last  = end( $parts );
		if ( $last === '' || strpos( $last, ':' ) !== false ) { return false; } // skip pseudo-classes
		$classes = array_filter( preg_split( '/\s+/', strtolower( (string) $cls ) ) );
		$id      = $el->getAttribute( 'id' );
		$tag     = strtolower( $el->tagName );
		if ( ! preg_match_all( '/[.#]?[\w-]+/', $last, $tok ) ) { return false; }
		foreach ( $tok[0] as $t ) {
			if ( $t[0] === '.' ) { if ( ! in_array( strtolower( substr( $t, 1 ) ), $classes, true ) ) { return false; } }
			elseif ( $t[0] === '#' ) { if ( $id !== substr( $t, 1 ) ) { return false; } }
			else { if ( $t !== '*' && $tag !== strtolower( $t ) ) { return false; } }
		}
		return true;
	}

	public static function html_to_mapping( $html, $title = 'Home', $slug = '', $front = true ) {
		$rules = self::rules_get();
		$dom   = self::load_dom( (string) $html );
		$sections = array();
		$main_cls = '';
		// Parse any `<style>` rules that set max-width (a non-Tailwind source may set it in CSS, not a
		// class) so element_max_width() can match an element against them.
		self::$mw_rules = self::parse_style_max_width( (string) $html );

		if ( $dom ) {
			$body = $dom->getElementsByTagName( 'body' )->item( 0 );
			$roots = $body ? self::section_roots( $body ) : array();
			// Section skip flags (parity with the capture service): drop skipped body bands / keep only
			// the chosen ones, by 0-based position (= the s_index in the conversion report), so a
			// re-convert preserves the bands already accepted.
			if ( $roots && ( self::$skip_sections || self::$only_sections ) ) {
				$kept = array();
				foreach ( $roots as $ri => $node ) {
					if ( self::$only_sections ) {
						if ( in_array( $ri, self::$only_sections, true ) ) { $kept[] = $node; }
					} elseif ( ! in_array( $ri, self::$skip_sections, true ) ) {
						$kept[] = $node;
					}
				}
				$roots = $kept;
			}
			if ( $body ) { foreach ( $body->getElementsByTagName( 'main' ) as $mm ) { $main_cls = self::cls( $mm ); break; } }
			$idx = 0;
			foreach ( $roots as $node ) {
				$blocks = array();
				self::collect_blocks( $node, $blocks, $rules );
				$blocks = array_values( array_filter( $blocks ) );
				if ( ! $blocks ) { continue; }
				// NOTE on fidelity / verbatim sections: the capture-service (JS) path keeps media-bearing
				// sections VERBATIM because it CARRIES the page's real used CSS, so the source markup
				// renders pixel-faithfully. This upload path instead REPRODUCES Tailwind offline (under
				// .sc-tw), which is incomplete (missing h-*/object-fit/aspect/arbitrary values), so a
				// whole-section verbatim would render wrong (giant un-sized images, ballooned height).
				// Here, DECOMPOSING into shortcodes (which render reliably without the reproducer) is the
				// safer choice — so we deliberately do NOT mirror the JS `preferVerbatim` guard. The
				// existing per-element recognizers (image_overlay / logo_strip) cover the critical media.
				// For high-fidelity design-heavy conversions, the capture service is the recommended path.
				// Section centered (flex items-center / text-center) → center its heading/text/buttons.
				$align = self::section_center( $node ) ? 'center' : '';
				if ( $align === 'center' ) {
					// Carry the band's centering to ALL text-bearing blocks — headings, the sub-text
					// paragraph AND the button row — so a centered source band renders fully centered
					// (previously only the heading family was centered, leaving the subtext left-aligned).
					foreach ( $blocks as &$bk ) {
						if ( in_array( $bk['role'] ?? '', array( 'title', 'heading', 'overline', 'subtitle', 'announcement_pill', 'text' ), true ) && empty( $bk['align'] ) ) {
							$bk['align'] = 'center';
						}
					}
					unset( $bk );
				}
				$sections[] = array(
					'sectionClass' => '',
					'sectionRawClass' => self::cls( $node ), // the section's RAW classes (mt/mb/py …) → vertical-spacing carry, without polluting css_class
					'sectionCs'    => (string) $node->getAttribute( 'data-sc-cs' ), // the section's COMPUTED style → faithful full-width band background (mapper reproduces bg the class parse misses)
					'sectionLayers' => self::section_layers( $node ), // direct-child "full-bleed" layers (class/style/computed) → mapper hoists a solid band fill that lives on an INNER layer, not the section root

					'css_id'       => self::section_id( $node, $idx ),
					'sectionId'    => ( $node instanceof DOMElement && $node->hasAttribute( 'id' ) ) ? self::slug_from_id( $node->getAttribute( 'id' ) ) : '', // raw source id (anchor target) → Mapper::auto_id fallback agrees with section_id()
					'omit'         => false,
					'verbatim'     => false,
					'align'        => $align,
					'blocks'       => $blocks,
				);
				$idx++;
			}
		}

		return array(
			'include_animations' => false,
			'pages' => array(
				array(
					'title'      => (string) $title,
					'slug'       => (string) $slug,
					'front_page' => (bool) $front,
					'sections'   => $sections,
					'mainClass'  => $main_cls, // the source <main>'s padding (pt-32 …) → carried onto #main
				),
			),
		);
	}

	/**
	 * The top-level "section" nodes to convert: every <section>. The <header> AND <footer> are site
	 * CHROME — reproduced by the generated child theme (header/footer template parts + the menus from
	 * the design-config), NOT page content — so they are deliberately excluded here. (Putting the
	 * footer in the page body duplicated it with the theme footer and looked wrong inside the builder.)
	 *
	 * @param DOMElement $body
	 * @return DOMElement[]
	 */
	/**
	 * Does this section center its content? True when the section root (or an immediate content wrapper)
	 * uses `text-center` or `flex … items-center` — the source's way of centering a hero / centered band.
	 *
	 * @param DOMElement $node
	 */
	private static function section_center( $node ) {
		$c = self::cls( $node );
		if ( strpos( $c, 'text-center' ) !== false ) { return true; }
		if ( strpos( $c, 'items-center' ) !== false && ( strpos( $c, 'flex-col' ) !== false || strpos( $c, 'flex' ) !== false ) ) { return true; }
		// COMPUTED text-align:center on the section root (data-sc-cs) — a source that centres its band via
		// CSS rather than a `text-center` class. Guard against the inherited `text-align:start` default.
		if ( $node instanceof DOMElement && preg_match( '/text-align:\s*center/i', (string) $node->getAttribute( 'data-sc-cs' ) ) ) { return true; }
		// one level down (a content wrapper inside the section) — class OR computed text-align:center
		foreach ( $node->childNodes as $ch ) {
			if ( $ch->nodeType !== XML_ELEMENT_NODE ) { continue; }
			$cc = self::cls( $ch );
			if ( strpos( $cc, 'text-center' ) !== false || ( strpos( $cc, 'items-center' ) !== false && strpos( $cc, 'flex' ) !== false ) ) { return true; }
			if ( $ch instanceof DOMElement && preg_match( '/text-align:\s*center/i', (string) $ch->getAttribute( 'data-sc-cs' ) ) ) { return true; }
		}
		return false;
	}

	/**
	 * Direct-child "full-bleed layer" candidates of a section — the absolutely-positioned inset-0 /
	 * w-full h-full overlays (and the section's immediate wrapper child). AI page builders paint a
	 * section's solid band colour on such an inner layer, NOT on the section root, so the root's own
	 * computed background reads transparent. The mapper inspects these to HOIST the dominant solid
	 * fill to the section element (skipping decorative gradient/opacity overlays). We pass the raw
	 * class + inline style + computed style for each; discrimination lives in the mapper.
	 *
	 * @param DOMElement $node
	 * @return array<int,array{cls:string,style:string,cs:string}>
	 */
	private static function section_layers( $node ) {
		$out = array();
		foreach ( $node->childNodes as $ch ) {
			if ( $ch->nodeType !== XML_ELEMENT_NODE ) { continue; }
			$out[] = array(
				'cls'   => self::cls( $ch ),
				'style' => (string) $ch->getAttribute( 'style' ),
				'cs'    => (string) $ch->getAttribute( 'data-sc-cs' ),
			);
			if ( count( $out ) >= 8 ) { break; } // a section's band layers are the first few children; cap the scan
		}
		return $out;
	}

	private static function section_roots( $body ) {
		$main = null;
		foreach ( $body->getElementsByTagName( 'main' ) as $m ) { $main = $m; break; }
		$scope = $main ? $main : $body;
		// The masthead header (so a hero <header> that ISN'T the masthead can be folded in as a body band).
		$masthead      = $body->ownerDocument ? self::header_root( $body->ownerDocument ) : null;
		$masthead_path = $masthead ? (string) $masthead->getNodePath() : '';
		$out = array();
		self::walk_section_roots( $scope, $masthead_path, $out );
		return $out;
	}

	/**
	 * Pre-order DFS (= document order) that claims each top-level <section> AND each hero <header>
	 * (a full-viewport <header> that is NOT the masthead — openhero-style), diving through plain
	 * wrappers but never into a claimed band or chrome (nav/footer/masthead). Mirrors the capture
	 * service folding hero headers into its section list.
	 *
	 * @param DOMNode $node
	 * @param string  $masthead_path node-path of the masthead header (skip it), '' if none
	 * @param array   $out
	 */
	private static function walk_section_roots( $node, $masthead_path, array &$out ) {
		foreach ( $node->childNodes as $ch ) {
			if ( XML_ELEMENT_NODE !== $ch->nodeType ) { continue; }
			$tag = strtolower( $ch->tagName );
			if ( 'section' === $tag ) { $out[] = $ch; continue; }                 // claim; don't descend (nested sections ignored)
			if ( 'header' === $tag ) {
				if ( ( '' === $masthead_path || (string) $ch->getNodePath() !== $masthead_path ) && self::is_hero_header( $ch ) ) {
					$out[] = $ch;                                                  // a hero header = body content
				}
				continue;                                                          // never descend into a <header>
			}
			if ( 'footer' === $tag || 'nav' === $tag ) { continue; }              // chrome — never a body band
			self::walk_section_roots( $ch, $masthead_path, $out );                // dive through wrappers to reach sections
		}
	}

	/**
	 * Element-recognizer REGISTRY — the expandable heart of the converter. Each recognizer CLAIMS a DOM
	 * element (so the walker won't descend into it) and turns it into a block. Built-ins cover headings,
	 * paragraphs, buttons, images, card grids and custom widgets; teach the converter a NEW UnysonPlus
	 * shortcode by calling register_recognizer() with a `match` + `build` callable — no core edits. Highest
	 * priority runs first; build() may return one block, a list of blocks, or null (claimed, nothing emitted).
	 *
	 *   match( DOMElement $el, string $tag, array $rules ) : bool
	 *   build( DOMElement $el, string $tag, array $rules ) : array|null   // a {t,role,…} block, a list, or null
	 */
	private static $recognizers        = array();
	private static $recognizers_sorted = false;

	/** Register an element recognizer (priority: higher runs first; the built-ins span 25–90). */
	public static function register_recognizer( $id, $priority, $match, $build ) {
		self::$recognizers[ $id ]   = array( 'id' => $id, 'priority' => (int) $priority, 'match' => $match, 'build' => $build );
		self::$recognizers_sorted   = false;
	}

	/** The recognizer set, highest-priority first (registers the built-ins on first use). */
	private static function recognizers() {
		if ( ! self::$recognizers ) { self::register_builtin_recognizers(); }
		if ( ! self::$recognizers_sorted ) {
			uasort( self::$recognizers, function ( $a, $b ) { return $b['priority'] - $a['priority']; } );
			self::$recognizers_sorted = true;
		}
		return self::$recognizers;
	}

	/** The built-in recognizers (the original hardcoded chain, now table-driven + extensible). */
	private static function register_builtin_recognizers() {
		// A grid of QUOTE-cards → the `testimonials` shortcode (checked BEFORE card_grid so a testimonial
		// grid isn't flattened into a generic columns row). Content only; source design not preserved.
		// Mirrors the JS testimonialsOf() structural fallback.
		self::register_recognizer( 'testimonials', 92,
			function ( $el ) { return self::is_testimonials_grid( $el ); },
			function ( $el ) { $rows = self::testimonials_items( $el ); return count( $rows ) >= 2 ? array( 't' => 'testimonials', 'items' => $rows ) : null; }
		);
		// A grid/flex of uniform cards → one "columns" row (each cell → icon_box / text / code).
		self::register_recognizer( 'card_grid', 90,
			function ( $el ) { return self::is_card_grid( $el ); },
			function ( $el ) { $cols = self::grid_cols( $el ); return $cols ? array( 't' => 'row', 'role' => 'columns', 'valign' => '', 'cols' => $cols ) : null; }
		);
		// Headings h1–h6.
		self::register_recognizer( 'heading', 80,
			function ( $el, $tag ) { return (bool) preg_match( '/^h[1-6]$/', $tag ); },
			function ( $el, $tag, $rules ) {
				$level = (int) substr( $tag, 1 );
				return array( 't' => 'heading', 'role' => self::rule_role( $rules, $el, $level <= 2 ? 'title' : 'heading' ), 'level' => $level, 'cls' => self::cls( $el ), 'cs' => (string) $el->getAttribute( 'data-sc-cs' ), 'text' => self::text( $el ), 'html' => self::clean_inline_html( $el ) );
			}
		);
		// Pill / eyebrow label → overline.
		// A hero badge PILL (rounded chip with a "New" tag) — carry verbatim so the pill look survives.
		self::register_recognizer( 'announcement_pill', 76,
			function ( $el ) { return self::is_announcement_pill( $el ); },
			function ( $el ) { $p = self::pill_parts( $el ); return array( 't' => 'pill', 'role' => 'announcement_pill', 'tag_text' => $p['tag_text'], 'message' => $p['message'], 'icon' => $p['icon'], 'link' => $p['link'], 'align' => '', 'pillCls' => $p['pillCls'], 'tagCls' => $p['tagCls'], 'msgCls' => $p['msgCls'], 'pillCs' => $p['pillCs'], 'leadingSvg' => $p['leadingSvg'] ); }
		);
		self::register_recognizer( 'badge', 75,
			function ( $el ) { return self::is_badge( $el ); },
			function ( $el ) { $doc = $el->ownerDocument; $v = $doc ? self::strip_cs( trim( (string) $doc->saveHTML( $el ) ) ) : ''; return '' !== $v ? array( 't' => 'html', 'role' => 'code', 'html' => '<div class="sc-tw">' . $v . '</div>' ) : null; }
		);
		self::register_recognizer( 'pill', 70,
			function ( $el ) { return self::is_pill( $el ); },
			function ( $el, $tag, $rules ) { return array( 't' => 'text', 'role' => self::rule_role( $rules, $el, 'overline' ), 'cls' => 'text-uppercase', 'text' => self::text( $el ) ); }
		);
		// Buttons / CTA links.
		self::register_recognizer( 'button', 60,
			function ( $el ) { return self::is_button( $el ); },
			function ( $el, $tag, $rules ) { return self::button_block( $el, $rules ); }
		);
		// Paragraph.
		self::register_recognizer( 'paragraph', 50,
			function ( $el, $tag ) { return 'p' === $tag; },
			function ( $el, $tag, $rules ) {
				$txt = self::text( $el );
				if ( '' === $txt ) { return null; }
				return array( 't' => 'text', 'role' => self::rule_role( $rules, $el, 'text' ), 'cls' => self::cls( $el ), 'cs' => (string) $el->getAttribute( 'data-sc-cs' ), 'maxWidth' => self::element_max_width( $el ), 'text' => $txt, 'html' => '<p>' . self::clean_inline_html( $el ) . '</p>' );
			}
		);
		// A self-hosted <video> OR a provider <iframe> (YouTube/Vimeo/…) → the native media_video
		// shortcode (self-hosted file OR oEmbed URL) — NOT a raw <video> in a text/code block. Muted /
		// looping / autoplaying background clips are only reproducible via the self-hosted branch, so
		// carry those flags through. Sits above <img> so a lone <video> wins over any image fallback.
		self::register_recognizer( 'video', 45,
			function ( $el, $tag ) {
				if ( 'video' === $tag ) { return true; }
				if ( 'iframe' === $tag ) {
					$src = strtolower( (string) $el->getAttribute( 'src' ) );
					return (bool) preg_match( '#(youtube\.com|youtu\.be|youtube-nocookie\.com|player\.vimeo\.com|vimeo\.com/\d|dailymotion\.com/embed|wistia\.(net|com)|player\.twitch\.tv)#', $src );
				}
				return false;
			},
			function ( $el, $tag ) {
				if ( 'iframe' === $tag ) {
					return array( 't' => 'video', 'role' => 'video', 'mode' => 'embed', 'embedUrl' => (string) $el->getAttribute( 'src' ) );
				}
				// <video>: prefer a direct src, else the first mp4/webm <source>.
				$src  = (string) $el->getAttribute( 'src' );
				$webm = '';
				foreach ( $el->getElementsByTagName( 'source' ) as $s ) {
					$ssrc  = (string) $s->getAttribute( 'src' );
					$stype = strtolower( (string) $s->getAttribute( 'type' ) );
					if ( $ssrc === '' ) { continue; }
					if ( $webm === '' && ( $stype === 'video/webm' || preg_match( '/\.webm(\?|$)/i', $ssrc ) ) ) { $webm = $ssrc; }
					if ( $src === ''  && ( $stype === 'video/mp4'  || preg_match( '/\.mp4(\?|$)/i',  $ssrc ) ) ) { $src  = $ssrc; }
				}
				if ( $src === '' && $webm === '' ) { return null; }
				// Full-screen BACKGROUND <video> (absolute/fixed + object-cover) → flagged `bg` so the
				// mapper wires it into the SECTION background. No computed styles here (static parse), so
				// read the class (Tailwind object-cover/inset-0/absolute) + inline style. Mirrors the JS
				// extractor's videoBlockOf `bg` flag.
				$vcls   = strtolower( (string) $el->getAttribute( 'class' ) );
				$vstyle = strtolower( (string) $el->getAttribute( 'style' ) );
				$is_bg  = ( preg_match( '/\b(absolute|fixed)\b/', $vcls ) || preg_match( '/position\s*:\s*(absolute|fixed)/', $vstyle ) )
					&& ( preg_match( '/\b(object-cover|inset-0)\b/', $vcls ) || preg_match( '/object-fit\s*:\s*cover/', $vstyle ) );
				return array(
					't' => 'video', 'role' => 'video', 'mode' => 'self_hosted', 'bg' => (bool) $is_bg,
					'src' => $src, 'webm' => $webm, 'poster' => (string) $el->getAttribute( 'poster' ),
					'autoplay'    => $el->hasAttribute( 'autoplay' )    ? 'yes' : 'no',
					'muted'       => $el->hasAttribute( 'muted' )       ? 'yes' : 'no',
					'loop'        => $el->hasAttribute( 'loop' )        ? 'yes' : 'no',
					'controls'    => $el->hasAttribute( 'controls' )    ? 'yes' : 'no',
					'playsinline' => $el->hasAttribute( 'playsinline' ) ? 'yes' : 'no',
				);
			}
		);
		// Standalone <img>.
		self::register_recognizer( 'image', 40,
			function ( $el, $tag ) { return 'img' === $tag; },
			function ( $el ) { return array( 't' => 'image', 'role' => 'image', 'html' => self::img_html( $el ) ); }
		);
		// A wrapper holding a lone image → emit just the image (skip the chrome).
		self::register_recognizer( 'image_wrapper', 35,
			function ( $el ) { return self::is_image_wrapper( $el ); },
			function ( $el ) { $img = $el->getElementsByTagName( 'img' )->item( 0 ); return $img ? array( 't' => 'image', 'role' => 'image', 'html' => self::img_html( $img ) ) : null; }
		);
		// An image with an OVERLAID UI (player/caption/controls, a floating badge, a decorative blob) →
		// whole widget verbatim in a code block. saveHTML($el) carries the OUTER element, so the widget's
		// own wrapper (e.g. `div.relative.lg:h-[600px]`) rides along and its absolute overlays anchor to
		// it — but we ALSO force `position:relative` inline on the clone (mirror of the JS to-pages
		// composite wrapper), so an `inset-0` blob / `top-10 -left-6` badge still anchors to the image
		// even if the carried `.relative` class doesn't resolve (else the overlays fly to the section
		// corner / balloon full-bleed). Cloned first so the live DOM isn't mutated for other recognizers.
		self::register_recognizer( 'image_overlay', 30,
			function ( $el ) { return self::is_image_with_overlay( $el ); },
			function ( $el ) {
				// P0 fidelity fix: when the composite cleanly matches the "photo + floating badge / blob"
				// shape, DECOMPOSE it into native, editable elements (media_image + icon_box) rather than
				// freezing it in one opaque code_block. Only a genuinely un-decomposable overlay
				// (a player/caption/controls layer) falls through to the verbatim block below.
				if ( self::is_decomposable_image_composite( $el ) ) {
					$cb = self::image_composite_decompose( $el );
					if ( $cb ) { return $cb; }
				}
				$doc = $el->ownerDocument;
				if ( ! $doc ) { return null; }
				$clone = $el->cloneNode( true );
				$st    = trim( (string) $clone->getAttribute( 'style' ) );
				$clone->setAttribute( 'style', ( '' !== $st ? rtrim( $st, ';' ) . ';' : '' ) . 'position:relative' );
				$v = self::strip_cs( trim( (string) $doc->saveHTML( $clone ) ) );
				return '' !== $v ? array( 't' => 'html', 'role' => 'code', 'wide' => true, 'html' => '<div class="sc-tw">' . $v . '</div>' ) : null;
			}
		);
		// A logo / "trusted by" strip (several images, no headings) → whole flex row verbatim in a code block.
		self::register_recognizer( 'logo_strip', 25,
			function ( $el ) { return self::is_logo_strip( $el ); },
			function ( $el ) { $doc = $el->ownerDocument; $v = $doc ? self::strip_cs( trim( (string) $doc->saveHTML( $el ) ) ) : ''; return '' !== $v ? array( 't' => 'html', 'role' => 'code', 'html' => '<div class="sc-tw">' . $v . '</div>' ) : null; }
		);

			// A REVIEWER-AVATAR STACK -- an overlapping row of small round avatar <img> (Tailwind -space-x-* /
			// negative-margin overlap), optionally next to a rating/label ("4.9/5 from 500+ happy pet parents")
			// -> the native `avatar` GROUP element (+ the rating text kept verbatim as a small code block), NOT
			// one opaque code_block. Mirrors the JS to-pages avatarGroupNode + ratingRowNode. Sits ABOVE
			// layout_row (82) so it claims the COMPACT stack wrapper before it can be split into a generic
			// 2-column row — but is_avatar_group only matches a tight wrapper (no heading, short caption), so a
			// hero's text+CTA column (h1 + prose) is NOT swallowed: the band still decomposes to special_heading
			// + buttons, and the stack is claimed as a tight child inside that decomposed cell. Above logo_strip
			// (25) too, so the round-overlapping stack isn't mistaken for a plain logo/image row.
			self::register_recognizer( 'avatar_group', 84,
				function ( $el ) { return self::is_avatar_group( $el ); },
				function ( $el ) { return self::avatar_group_build( $el ); }
			);

		// Framework-agnostic (computed-style) recognizers — for sites with NO Tailwind classes (Bootstrap,
		// plain CSS, CSS-in-JS, …). They sit just below the Tailwind-specific ones, so a Tailwind site keeps
		// using the precise class-based recognizers and any-other-framework site falls through to these.
		self::register_recognizer( 'card_grid_cs', 85,
			function ( $el ) { return self::cs_is_card_grid( $el ); },
			function ( $el ) { $cols = self::grid_cols( $el ); return $cols ? array( 't' => 'row', 'role' => 'columns', 'valign' => '', 'cols' => $cols ) : null; }
		);
		self::register_recognizer( 'button_cs', 55,
			function ( $el ) { return self::cs_is_button( $el ); },
			function ( $el, $tag, $rules ) { return self::button_block( $el, $rules ); }
		);
		// A genuine horizontal MULTI-COLUMN band that ISN'T a card grid — e.g. a hero's `grid lg:grid-cols-2`
		// with TEXT on the left and an IMAGE on the right, or any `grid-cols-N` / desktop flex-row of >=2
		// substantial (non-card) cells. Without this the container isn't claimed by card_grid (its cells carry
		// no h2–h6 headings), so collect_blocks descends and FLATTENS the columns into stacked blocks (heading,
		// buttons, then the image full-width below). Sits BELOW the card grids (90/85) so a card grid still
		// becomes icon_boxes, and ABOVE headings (80) so it claims the whole band before descent. Each cell is
		// kept verbatim WITH its classes (.sc-tw wrapper, so Tailwind styling survives) at its measured width.
		self::register_recognizer( 'layout_row', 82,
			function ( $el ) { return self::is_layout_row( $el ); },
			function ( $el ) { $cols = self::layout_cols( $el ); return $cols ? array( 't' => 'row', 'role' => 'columns', 'valign' => self::row_valign( $el ), 'cols' => $cols ) : null; }
		);

		// A STAT / COUNTER grid — a row/grid whose EVERY cell is a big NUMBER (optionally a `+`/`%`/`k`
		// prefix/suffix) + a short label (e.g. "10,000+ Happy pets", "98% Satisfaction"). Routed to the
		// (previously ORPHAN) counter builder: each cell → an animated `counter` shortcode + its label in a
		// text_block below, reusing the existing columns→counter cell path in the mapper. Priority 91 sits
		// BELOW testimonials (92) but ABOVE card_grid (90) so a stat band is claimed as counters before a
		// generic card grid can turn it into icon_boxes. TIGHT match (is_counter_grid): >=2 cells AND every
		// substantial cell parses to a numeric stat with <=80 chars of text — so a feature/prose grid (long
		// cell text) or a mixed band never qualifies.
		self::register_recognizer( 'counter_grid', 91,
			function ( $el ) { return self::is_counter_grid( $el ); },
			function ( $el ) { return self::counter_grid_build( $el ); }
		);

		// A real data `<table>` (>=1 row with cells) → the native `table` shortcode (tabular render mode),
		// headers derived from leading all-`<th>` rows. Priority 88: the match is tag-scoped (`table`), the
		// tightest possible signal, so it can't over-claim; it just needs to beat descent so the table isn't
		// flattened into loose cell text.
		self::register_recognizer( 'table', 88,
			function ( $el, $tag ) { return 'table' === $tag && self::table_has_rows( $el ); },
			function ( $el ) { return self::table_block( $el ); }
		);

		// An ACCORDION / FAQ — a container holding >=2 `<details><summary>` items, or >=2 `[aria-expanded]`
		// toggles each with a panel → the native `accordion` shortcode (items = title + content). Priority 89
		// (below card_grid 90). TIGHT match (is_accordion_group): requires >=2 genuine toggle/detail pairs, so
		// a lone <details> or a plain content stack never qualifies.
		self::register_recognizer( 'accordion', 89,
			function ( $el ) { return self::is_accordion_group( $el ); },
			function ( $el ) { return self::accordion_block( $el ); }
		);

		// A standalone `<ul>`/`<ol>` of >=2 text items (NOT a nav / menu / pagination / breadcrumb / tab list)
		// → the native `feature_list` shortcode (checklist for <ul>, numbered for <ol>). Priority 79 sits just
		// below headings (80): a plain list carries no grid/flex signal so it never trips card_grid (90/85) or
		// layout_row (82); this claims it before descent flattens it into loose text. TIGHT match
		// (is_text_list): real ul/ol, >=2 non-empty <li>, and role/class/ancestor excludes navigation lists.
		self::register_recognizer( 'text_list', 79,
			function ( $el ) { return self::is_text_list( $el ); },
			function ( $el ) { return self::text_list_block( $el ); }
		);

		// --- Interactive / structured native widgets (all TIGHT, so a high priority is safe: each claims
		//     ONLY its exact structural pattern and never a generic card/feature grid). They sit ABOVE
		//     card_grid(90)/counter_grid(91)/testimonials(92) so a real pricing/steps/timeline/progress/tabs
		//     band wins over the generic grid recognizers that would otherwise flatten it. ---

		// A PRICING TABLE — >=2 plan columns each carrying a price token (currency + number) + a plan name,
		// usually a feature list + CTA → the native `pricing_table` shortcode. TIGHT: the price token must
		// appear in MOST columns, so a plain feature card grid (no currency) stays icon_box.
		self::register_recognizer( 'pricing_table', 99,
			function ( $el ) { return self::is_pricing_table( $el ); },
			function ( $el ) { return self::pricing_table_block( $el ); }
		);

		// A numbered STEPS / PROCESS flow (Step 1 → 2 → 3, numbered markers, `.steps`/`.process`) → `steps`.
		self::register_recognizer( 'steps', 98,
			function ( $el ) { return self::is_steps_flow( $el ); },
			function ( $el ) { return self::steps_block( $el ); }
		);

		// A dated TIMELINE — chronological entries (date + title + body) along a line → `timeline`.
		self::register_recognizer( 'timeline', 97,
			function ( $el ) { return self::is_timeline( $el ); },
			function ( $el ) { return self::timeline_block( $el ); }
		);

		// PROGRESS / SKILL bars — >=2 labelled bars each with a percent (role=progressbar, width:%, or "NN%")
		// → the native `progress` shortcode. Above counter_grid(91) so "Design 80%" reads as a bar, not a stat.
		self::register_recognizer( 'progress', 96,
			function ( $el ) { return self::is_progress_bars( $el ); },
			function ( $el ) { return self::progress_block( $el ); }
		);

		// A TABS widget — a tablist (role=tablist/[role=tab], or `.tabs`/`.nav-tabs` with [data-tab]/aria-controls
		// panels) bound to switchable panels → the native `tabs` shortcode. TIGHT: >=2 tabs each resolving to a
		// panel; a plain <ul> nav (no tabs/panels) never qualifies.
		self::register_recognizer( 'tabs', 95,
			function ( $el ) { return self::is_tabs_widget( $el ); },
			function ( $el ) { return self::tabs_block( $el ); }
		);

		// A LOTTIE embed — <lottie-player>/<dotlottie-player>, or a container with a `.json`/`.lottie` src /
		// bodymovin init → the native `lottie` shortcode (carries the animation src).
		self::register_recognizer( 'lottie', 94,
			function ( $el ) { return self::is_lottie_embed( $el ); },
			function ( $el ) { return self::lottie_block( $el ); }
		);

		// A self-drawing SVG — an inline <svg> whose paths are stroke-animated (stroke-dasharray/dashoffset)
		// or explicitly marked for draw-on-scroll → the native `svg_draw` shortcode. TIGHT: a real draw signal,
		// never a plain decorative icon <svg>.
		self::register_recognizer( 'svg_draw', 93,
			function ( $el, $tag ) { return self::is_svg_draw( $el, $tag ); },
			function ( $el ) { return self::svg_draw_block( $el ); }
		);
	}

	/* --------------------------------------------------------------------- *
	 * Source-animation INTENT — detect a reveal/scroll animation on a source
	 * element (AOS, animate.css/WOW, generic reveal hooks) and map it to a
	 * native animate.css effect string for the node's Animations tab. Absent a
	 * clear signal, returns '' (no false motion — the node stays un-animated).
	 * --------------------------------------------------------------------- */
	private static function anim_intent( $el ) {
		if ( ! ( $el instanceof DOMElement ) ) { return ''; }
		$dir = array( 'up' => 'animate__fadeInUp', 'down' => 'animate__fadeInDown', 'left' => 'animate__fadeInLeft', 'right' => 'animate__fadeInRight' );
		// AOS: data-aos="fade-up" / "zoom-in" / "slide-left" / "flip-up" / "fade".
		$aos = strtolower( trim( (string) $el->getAttribute( 'data-aos' ) ) );
		if ( '' !== $aos ) {
			if ( preg_match( '/(up|down|left|right)/', $aos, $m ) && strpos( $aos, 'zoom' ) === false ) { return $dir[ $m[1] ]; }
			if ( strpos( $aos, 'zoom-out' ) === 0 ) { return 'animate__zoomOut'; }
			if ( strpos( $aos, 'zoom' ) === 0 )     { return 'animate__zoomIn'; }
			if ( strpos( $aos, 'flip' ) === 0 )     { return 'animate__flipInX'; }
			return 'animate__fadeIn';
		}
		// animate.css v4 (`animate__fadeInUp`) or WOW v3 (`wow fadeInUp`).
		$class = (string) $el->getAttribute( 'class' );
		if ( preg_match( '/\banimate__([A-Za-z]+)\b/', $class, $m ) ) { return 'animate__' . $m[1]; }
		if ( preg_match( '/\bwow\b/i', $class ) && preg_match( '/\b(fadeIn[A-Za-z]*|zoomIn|zoomOut|slideIn[A-Za-z]*|bounceIn[A-Za-z]*|flipIn[A-Za-z]*)\b/', $class, $m ) ) { return 'animate__' . $m[1]; }
		// Generic reveal hooks (Framer/GSAP/custom) carrying a direction.
		foreach ( array( 'data-animate', 'data-scroll', 'data-reveal', 'data-motion' ) as $at ) {
			if ( $el->hasAttribute( $at ) ) {
				$v = strtolower( (string) $el->getAttribute( $at ) );
				foreach ( $dir as $k => $eff ) { if ( strpos( $v, $k ) !== false ) { return $eff; } }
				return 'animate__fadeInUp';
			}
		}
		return '';
	}

	/* --------------------------------------------------------------------- *
	 * Counter / stat-grid recognizer helpers
	 * --------------------------------------------------------------------- */

	/**
	 * Parse ONE stat cell into a counter payload `{ number, prefix, suffix, decimals, label }`, or null if the
	 * cell isn't a numeric stat. A stat cell is short (<=80 chars) and dominated by a number, optionally with a
	 * leading currency/`+`/`~` symbol and a trailing `%`/`+`/`k`/`m`/`b`/`x` unit; the leftover text is the label.
	 */
	private static function counter_cell_parse( $cell ) {
		if ( ! ( $cell instanceof DOMElement ) ) { return null; }
		$txt = self::text( $cell );
		if ( '' === $txt || mb_strlen( $txt ) > 80 ) { return null; }
		if ( ! preg_match( '/(\d[\d,]*(?:\.\d+)?)/', $txt, $m, PREG_OFFSET_CAPTURE ) ) { return null; }
		$num_raw = $m[1][0];
		$pos     = (int) $m[1][1];
		$len     = strlen( $num_raw );
		$before  = substr( $txt, 0, $pos );
		$after   = substr( $txt, $pos + $len );
		$prefix  = '';
		if ( preg_match( '/([$€£¥+~])\s*$/u', $before, $pm ) ) { $prefix = $pm[1]; $before = preg_replace( '/([$€£¥+~])\s*$/u', '', $before ); }
		$suffix  = '';
		if ( preg_match( '/^\s*(%|\+|k|K|m|M|b|B|x|X)/u', $after, $sm ) ) { $suffix = $sm[1]; $after = preg_replace( '/^\s*(%|\+|k|K|m|M|b|B|x|X)/u', '', $after, 1 ); }
		$label   = trim( preg_replace( '/\s+/', ' ', $before . ' ' . $after ) );
		// A STAT cell is DOMINATED by its number: the leftover label is a short caption ("Happy pets",
		// "Satisfaction"), NOT prose. Reject a long remainder so a paragraph that merely contains a number
		// (e.g. an FAQ answer "…within 30 days…") never reads as a counter.
		if ( mb_strlen( $label ) > 32 ) { return null; }
		$decimals = 0;
		if ( strpos( $num_raw, '.' ) !== false ) { $decimals = strlen( substr( strrchr( $num_raw, '.' ), 1 ) ); }
		$number = str_replace( ',', '', $num_raw );
		return array( 'number' => $number, 'prefix' => $prefix, 'suffix' => $suffix, 'decimals' => (string) $decimals, 'label' => $label );
	}

	/** TIGHT: a grid/row of >=2 cells where EVERY substantial cell is a numeric stat (counter_cell_parse). */
	private static function is_counter_grid( $el ) {
		if ( ! ( $el instanceof DOMElement ) ) { return false; }
		$tag = strtolower( $el->tagName );
		if ( in_array( $tag, array( 'table', 'thead', 'tbody', 'tr', 'ul', 'ol', 'nav', 'dl' ), true ) ) { return false; }
		// Never claim a structural toggle/list/table band as a stat grid (accordion/list/table own those).
		if ( $el->getElementsByTagName( 'details' )->length || $el->getElementsByTagName( 'summary' )->length
			|| $el->getElementsByTagName( 'table' )->length || $el->getElementsByTagName( 'ul' )->length
			|| $el->getElementsByTagName( 'ol' )->length ) { return false; }
		$cells = 0; $numeric = 0;
		foreach ( self::el_children( $el ) as $k ) {
			$kt = strtolower( $k->tagName );
			if ( in_array( $kt, array( 'script', 'style', 'br', 'hr' ), true ) ) { continue; }
			if ( '' === self::text( $k ) && ! $k->getElementsByTagName( 'img' )->length ) { continue; } // skip empty/decorative
			$cells++;
			if ( self::counter_cell_parse( $k ) !== null ) { $numeric++; }
		}
		return $cells >= 2 && $numeric >= 2 && $numeric === $cells;
	}

	/** Build a `row` block whose cells are counter payloads (reuses the mapper's columns→counter cell path). */
	private static function counter_grid_build( $el ) {
		$kids = array();
		foreach ( self::el_children( $el ) as $k ) {
			if ( in_array( strtolower( $k->tagName ), array( 'script', 'style', 'br', 'hr' ), true ) ) { continue; }
			if ( '' === self::text( $k ) && ! $k->getElementsByTagName( 'img' )->length ) { continue; }
			$kids[] = $k;
		}
		$n    = count( $kids );
		$desk = $n > 0 ? max( 1, min( 12, (int) round( 12 / $n ) ) ) : 4;
		$cols = array();
		foreach ( $kids as $k ) {
			$p = self::counter_cell_parse( $k );
			if ( null === $p ) { continue; }
			$cols[] = array( 'counter' => $p, 'wResp' => array( 'desktop' => $desk ) );
		}
		return count( $cols ) >= 2 ? array( 't' => 'row', 'role' => 'columns', 'valign' => '', 'cols' => $cols ) : null;
	}

	/* --------------------------------------------------------------------- *
	 * Table recognizer helpers
	 * --------------------------------------------------------------------- */

	/** A `<table>` that has at least one row. */
	private static function table_has_rows( $el ) {
		return ( $el instanceof DOMElement ) && $el->getElementsByTagName( 'tr' )->length >= 1;
	}

	/**
	 * Extract a `<table>` into a `{ t:'table', rows:[[ {html,header}, … ], …], caption }` block. Each `<tr>`
	 * becomes a row of cells; a `<th>` cell is flagged `header` so the mapper can derive `<thead>`.
	 */
	private static function table_block( $el ) {
		$rows = array();
		foreach ( $el->getElementsByTagName( 'tr' ) as $tr ) {
			$cells = array();
			foreach ( self::el_children( $tr ) as $c ) {
				$ct = strtolower( $c->tagName );
				if ( 'td' !== $ct && 'th' !== $ct ) { continue; }
				$cells[] = array( 'html' => self::strip_cs( trim( self::inner_html( $c ) ) ), 'header' => ( 'th' === $ct ) );
			}
			if ( $cells ) { $rows[] = $cells; }
		}
		if ( ! $rows ) { return null; }
		$caption = '';
		$cap = $el->getElementsByTagName( 'caption' )->item( 0 );
		if ( $cap ) { $caption = self::text( $cap ); }
		// Capture the table's styling evidence (the computed styles the capture stamps as data-sc-cs) so the
		// mapper can pick the best-matching Table Preset skin: the first header cell's fill/text, the table
		// border, and whether the body rows are zebra-striped (>=2 distinct row backgrounds).
		$th = $el->getElementsByTagName( 'th' )->item( 0 );
		$style = array(
			'header_cs' => $th ? (string) $th->getAttribute( 'data-sc-cs' ) : '',
			'table_cs'  => (string) $el->getAttribute( 'data-sc-cs' ),
			'striped'   => self::table_is_striped( $el ),
		);
		return array( 't' => 'table', 'rows' => $rows, 'caption' => $caption, 'style' => $style );
	}

	/** Are the body rows zebra-striped? True when the `<tr>` computed backgrounds show >=2 distinct fills. */
	private static function table_is_striped( $el ) {
		$bgs = array();
		foreach ( $el->getElementsByTagName( 'tr' ) as $tr ) {
			$cs = (string) $tr->getAttribute( 'data-sc-cs' );
			if ( '' === $cs ) { continue; }
			if ( preg_match( '/background-color\s*:\s*([^;]+)/i', $cs, $m ) ) {
				$v = trim( $m[1] );
				if ( '' !== $v && 'transparent' !== $v && ! preg_match( '/,\s*0\s*\)\s*$/', $v ) ) { $bgs[ $v ] = true; }
			}
		}
		return count( $bgs ) >= 2;
	}

	/* --------------------------------------------------------------------- *
	 * Accordion / FAQ recognizer helpers
	 * --------------------------------------------------------------------- */

	/** TIGHT: a container with >=2 `<details><summary>` items, or >=2 `[aria-expanded]` toggles. */
	private static function is_accordion_group( $el ) {
		if ( ! ( $el instanceof DOMElement ) ) { return false; }
		$tag = strtolower( $el->tagName );
		if ( 'details' === $tag || 'summary' === $tag ) { return false; } // claim the GROUP, not one item
		$details = 0;
		foreach ( $el->getElementsByTagName( 'details' ) as $d ) {
			if ( $d->getElementsByTagName( 'summary' )->length > 0 ) { $details++; }
		}
		if ( $details >= 2 ) { return true; }
		// aria toggles: >=2 elements carrying aria-expanded, each with a panel (aria-controls or a sibling).
		$toggles = 0;
		foreach ( $el->getElementsByTagName( '*' ) as $t ) {
			if ( $t->hasAttribute( 'aria-expanded' ) && self::text( $t ) !== '' ) { $toggles++; }
		}
		return $toggles >= 2;
	}

	/** Extract accordion items `{ title, content }` from a `<details>` group or an aria-toggle group. */
	private static function accordion_block( $el ) {
		$items = array();
		$dets  = $el->getElementsByTagName( 'details' );
		if ( $dets->length > 0 ) {
			foreach ( $dets as $d ) {
				$sum = $d->getElementsByTagName( 'summary' )->item( 0 );
				if ( ! $sum ) { continue; }
				$title = self::text( $sum );
				// Content = the details' inner HTML minus the summary.
				$clone = $d->cloneNode( true );
				foreach ( iterator_to_array( $clone->getElementsByTagName( 'summary' ) ) as $s ) { $s->parentNode->removeChild( $s ); }
				$content = self::strip_cs( trim( self::inner_html( $clone ) ) );
				if ( '' === $content ) { $content = ''; }
				if ( '' !== $title ) { $items[] = array( 'title' => $title, 'content' => $content ); }
			}
		} else {
			// aria-expanded toggles: title = toggle text; content = aria-controls target or next sibling.
			$doc = $el->ownerDocument;
			foreach ( self::el_all_with_aria( $el ) as $t ) {
				$title = self::text( $t );
				if ( '' === $title ) { continue; }
				$panel_html = '';
				$ctrl = trim( (string) $t->getAttribute( 'aria-controls' ) );
				if ( '' !== $ctrl && $doc ) {
					$p = $doc->getElementById( $ctrl );
					if ( $p ) { $panel_html = self::strip_cs( trim( self::inner_html( $p ) ) ); }
				}
				if ( '' === $panel_html ) {
					$sib = $t->nextSibling;
					while ( $sib && $sib->nodeType !== XML_ELEMENT_NODE ) { $sib = $sib->nextSibling; }
					if ( $sib instanceof DOMElement ) { $panel_html = self::strip_cs( trim( self::inner_html( $sib ) ) ); }
				}
				$items[] = array( 'title' => $title, 'content' => $panel_html );
			}
		}
		return count( $items ) >= 2 ? array( 't' => 'accordion', 'items' => $items ) : null;
	}

	/** All descendant elements carrying a non-empty `aria-expanded` toggle (helper for accordion_block). */
	private static function el_all_with_aria( $el ) {
		$out = array();
		foreach ( $el->getElementsByTagName( '*' ) as $t ) {
			if ( $t->hasAttribute( 'aria-expanded' ) && self::text( $t ) !== '' ) { $out[] = $t; }
		}
		return $out;
	}

	/* --------------------------------------------------------------------- *
	 * List (ul/ol) recognizer helpers
	 * --------------------------------------------------------------------- */

	/** TIGHT: a real `<ul>`/`<ol>` with >=2 non-empty `<li>`, that is NOT a navigation/menu/tab/pagination list. */
	private static function is_text_list( $el ) {
		if ( ! ( $el instanceof DOMElement ) ) { return false; }
		$tag = strtolower( $el->tagName );
		if ( 'ul' !== $tag && 'ol' !== $tag ) { return false; }
		$cls  = self::cls( $el );
		$role = strtolower( (string) $el->getAttribute( 'role' ) );
		if ( preg_match( '/\b(menu|nav|navbar|pagination|breadcrumb|tabs?|tab-list|tablist|social|slider|carousel|steps|dropdown)\b/', $cls ) ) { return false; }
		if ( in_array( $role, array( 'menu', 'menubar', 'tablist', 'navigation' ), true ) ) { return false; }
		if ( self::has_ancestor_tag( $el, 'nav', null ) ) { return false; }
		$lis = 0;
		foreach ( self::el_children( $el ) as $li ) {
			if ( 'li' === strtolower( $li->tagName ) && self::text( $li ) !== '' ) { $lis++; }
		}
		return $lis >= 2;
	}

	/** Extract a `<ul>`/`<ol>` into a `{ t:'feature_list', ordered, items:[{text,html},…] }` block. */
	private static function text_list_block( $el ) {
		$ordered = ( 'ol' === strtolower( $el->tagName ) );
		$rows = array();
		foreach ( self::el_children( $el ) as $li ) {
			if ( 'li' !== strtolower( $li->tagName ) ) { continue; }
			$t = self::text( $li );
			if ( '' === $t ) { continue; }
			$rows[] = array( 'text' => $t, 'html' => self::clean_inline_html( $li ) );
		}
		return count( $rows ) >= 2 ? array( 't' => 'feature_list', 'ordered' => $ordered, 'items' => $rows ) : null;
	}

	/* --------------------------------------------------------------------- *
	 * Shared helpers for the interactive/structured recognizers below.
	 * --------------------------------------------------------------------- */

	/** Substantial (non-empty, non-decorative) direct element children of $el. */
	private static function widget_children( $el ) {
		$out = array();
		foreach ( self::el_children( $el ) as $k ) {
			$kt = strtolower( $k->tagName );
			if ( in_array( $kt, array( 'script', 'style', 'br', 'hr', 'template' ), true ) ) { continue; }
			if ( '' === self::text( $k ) && ! $k->getElementsByTagName( 'img' )->length && ! $k->getElementsByTagName( 'svg' )->length ) { continue; }
			$out[] = $k;
		}
		return $out;
	}

	/** The first heading / strong / `.title`-ish label text inside $el (the item's title), or ''. */
	private static function item_title_text( $el ) {
		foreach ( array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ) as $h ) {
			$n = $el->getElementsByTagName( $h )->item( 0 );
			if ( $n && self::text( $n ) !== '' ) { return self::text( $n ); }
		}
		foreach ( $el->getElementsByTagName( '*' ) as $c ) {
			$cc = strtolower( (string) $c->getAttribute( 'class' ) );
			if ( preg_match( '/\b(title|name|heading|plan-?name|step-?title)\b/', $cc ) && self::text( $c ) !== '' ) { return self::text( $c ); }
		}
		$strong = $el->getElementsByTagName( 'strong' )->item( 0 );
		if ( $strong && self::text( $strong ) !== '' ) { return self::text( $strong ); }
		return '';
	}

	/** The item's body text = full text minus a leading title, first `<p>` preferred. */
	private static function item_body_text( $el, $title ) {
		$p = $el->getElementsByTagName( 'p' )->item( 0 );
		if ( $p && self::text( $p ) !== '' ) { return self::text( $p ); }
		$all = self::text( $el );
		if ( $title !== '' && strpos( $all, $title ) === 0 ) { $all = trim( substr( $all, strlen( $title ) ) ); }
		return $all;
	}

	/* --------------------------------------------------------------------- *
	 * Pricing-table recognizer
	 * --------------------------------------------------------------------- */

	/** A currency+number price token inside a cell's text (e.g. `$29`, `€ 19`, `£9/mo`). */
	private static function cell_price_parts( $el ) {
		$txt = self::text( $el );
		if ( '' === $txt ) { return null; }
		if ( ! preg_match( '/([$€£¥₹])\s?([\d][\d.,]*)/u', $txt, $m ) ) { return null; }
		$cur   = $m[1];
		$num   = str_replace( ',', '', $m[2] );
		$period = '';
		if ( preg_match( '#/\s*(mo|month|yr|year|wk|week|day|user|seat)s?\b#i', $txt, $pm ) ) { $period = '/' . strtolower( $pm[1] ); }
		return array( 'currency' => $cur, 'price' => $num, 'period' => $period );
	}

	/** TIGHT: >=2 plan columns and a price token in MOST columns (so a plain feature grid is not claimed). */
	private static function is_pricing_table( $el ) {
		if ( ! ( $el instanceof DOMElement ) ) { return false; }
		$tag = strtolower( $el->tagName );
		if ( in_array( $tag, array( 'table', 'thead', 'tbody', 'tr', 'ul', 'ol', 'nav', 'dl', 'details', 'summary' ), true ) ) { return false; }
		if ( $el->getElementsByTagName( 'details' )->length || $el->getElementsByTagName( 'table' )->length ) { return false; }
		$kids = self::widget_children( $el );
		$n    = count( $kids );
		if ( $n < 2 ) { return false; }
		$priced = 0;
		foreach ( $kids as $k ) { if ( self::cell_price_parts( $k ) !== null ) { $priced++; } }
		return $priced >= max( 2, (int) ceil( $n * 0.6 ) );
	}

	/** Build a `{ t:'pricing', plans:[…] }` block from a pricing grid. */
	private static function pricing_table_block( $el ) {
		$plans = array();
		foreach ( self::widget_children( $el ) as $k ) {
			$price = self::cell_price_parts( $k );
			$title = self::item_title_text( $k );
			if ( '' === $title && null === $price ) { continue; }
			$features = array();
			$ul = $k->getElementsByTagName( 'ul' )->item( 0 );
			if ( ! $ul ) { $ul = $k->getElementsByTagName( 'ol' )->item( 0 ); }
			if ( $ul ) {
				foreach ( $ul->getElementsByTagName( 'li' ) as $li ) { $t = self::text( $li ); if ( '' !== $t ) { $features[] = $t; } }
			}
			$btn_label = ''; $btn_url = '';
			foreach ( array( 'a', 'button' ) as $bt ) {
				$b = $k->getElementsByTagName( $bt )->item( 0 );
				if ( $b && self::text( $b ) !== '' ) { $btn_label = self::text( $b ); $btn_url = (string) $b->getAttribute( 'href' ); break; }
			}
			$kcls     = strtolower( (string) $k->getAttribute( 'class' ) );
			$featured = preg_match( '/\b(featured|popular|recommended|highlight(ed)?|best|pro)\b/', $kcls ) ? 'yes' : 'no';
			$ribbon   = '';
			if ( 'yes' === $featured ) {
				foreach ( $k->getElementsByTagName( '*' ) as $c ) {
					$cc = strtolower( (string) $c->getAttribute( 'class' ) );
					if ( preg_match( '/\b(ribbon|badge|popular|tag|label)\b/', $cc ) && self::text( $c ) !== '' && mb_strlen( self::text( $c ) ) <= 24 ) { $ribbon = self::text( $c ); break; }
				}
			}
			$plans[] = array(
				'title'    => $title,
				'currency' => $price ? $price['currency'] : '$',
				'price'    => $price ? $price['price'] : '',
				'period'   => $price ? $price['period'] : '',
				'features' => implode( "\n", $features ),
				'featured' => $featured,
				'ribbon'   => $ribbon,
				'btn_label' => $btn_label,
				'btn_url'   => $btn_url,
			);
		}
		return count( $plans ) >= 2 ? array( 't' => 'pricing', 'plans' => $plans ) : null;
	}

	/* --------------------------------------------------------------------- *
	 * Steps / process recognizer
	 * --------------------------------------------------------------------- */

	/** Does this child carry a numeric step marker (leading "Step N"/"N", or a `.step-number`/`.circle`)? */
	private static function step_marker( $el ) {
		$t = self::text( $el );
		if ( preg_match( '/^\s*(?:step\s*)?(\d{1,2})\b/i', $t ) ) { return true; }
		foreach ( $el->getElementsByTagName( '*' ) as $c ) {
			$cc = strtolower( (string) $c->getAttribute( 'class' ) );
			if ( preg_match( '/step-?(number|index|num|count)|\b(number|circle|marker|count)\b/', $cc ) && preg_match( '/\d/', self::text( $c ) ) ) { return true; }
		}
		return false;
	}

	/** TIGHT: a `.steps`/`.process` flow OR >=2 numbered step cards, each with a title. */
	private static function is_steps_flow( $el ) {
		if ( ! ( $el instanceof DOMElement ) ) { return false; }
		$tag = strtolower( $el->tagName );
		if ( in_array( $tag, array( 'table', 'thead', 'tbody', 'tr', 'nav', 'dl', 'details', 'summary' ), true ) ) { return false; }
		if ( $el->getElementsByTagName( 'details' )->length ) { return false; }
		$kids = self::widget_children( $el );
		$n    = count( $kids );
		if ( $n < 2 ) { return false; }
		$cls = self::cls( $el );
		foreach ( $kids as $k ) { $cls .= ' ' . strtolower( (string) $k->getAttribute( 'class' ) ); }
		$class_signal = (bool) preg_match( '/\b(steps?|process|how-?it-?works|process-?flow)\b/', $cls );
		$titled = 0; $numbered = 0;
		foreach ( $kids as $k ) {
			if ( self::item_title_text( $k ) !== '' ) { $titled++; }
			if ( self::step_marker( $k ) ) { $numbered++; }
		}
		if ( $titled < 2 ) { return false; }
		return $class_signal ? true : ( $numbered >= $n );
	}

	/** Build a `{ t:'steps', items:[{title,content,number}] }` block. */
	private static function steps_block( $el ) {
		$items = array();
		foreach ( self::widget_children( $el ) as $k ) {
			$title = self::item_title_text( $k );
			if ( '' === $title ) { continue; }
			$num = '';
			if ( preg_match( '/^\s*(?:step\s*)?(\d{1,2})\b/i', self::text( $k ), $m ) ) { $num = $m[1]; }
			$items[] = array( 'title' => $title, 'content' => self::item_body_text( $k, $title ), 'number' => $num );
		}
		return count( $items ) >= 2 ? array( 't' => 'steps', 'items' => $items ) : null;
	}

	/* --------------------------------------------------------------------- *
	 * Timeline recognizer
	 * --------------------------------------------------------------------- */

	/** A date token from a child: a `<time>` element or a year / "Mon YYYY" / "MM/YYYY" in its text. */
	private static function timeline_date( $el ) {
		$time = $el->getElementsByTagName( 'time' )->item( 0 );
		if ( $time && self::text( $time ) !== '' ) { return self::text( $time ); }
		$t = self::text( $el );
		if ( preg_match( '/\b((?:19|20)\d{2})\b/', $t, $m ) ) { return $m[1]; }
		if ( preg_match( '/\b(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]*\.?\s+\d{4}\b/i', $t, $m ) ) { return $m[0]; }
		if ( preg_match( '#\b\d{1,2}/\d{4}\b#', $t, $m ) ) { return $m[0]; }
		return '';
	}

	/** TIGHT: a `.timeline` container OR >=2 dated entries, each with a title. */
	private static function is_timeline( $el ) {
		if ( ! ( $el instanceof DOMElement ) ) { return false; }
		$tag = strtolower( $el->tagName );
		if ( in_array( $tag, array( 'table', 'thead', 'tbody', 'tr', 'nav', 'dl', 'details', 'summary' ), true ) ) { return false; }
		if ( $el->getElementsByTagName( 'details' )->length ) { return false; }
		$kids = self::widget_children( $el );
		$n    = count( $kids );
		if ( $n < 2 ) { return false; }
		$cls = self::cls( $el );
		foreach ( $kids as $k ) { $cls .= ' ' . strtolower( (string) $k->getAttribute( 'class' ) ); }
		$class_signal = (bool) preg_match( '/\btimeline\b/', $cls );
		$dated = 0; $titled = 0;
		foreach ( $kids as $k ) {
			if ( self::timeline_date( $k ) !== '' ) { $dated++; }
			if ( self::item_title_text( $k ) !== '' ) { $titled++; }
		}
		if ( $titled < 2 ) { return false; }
		return $class_signal ? ( $dated >= 1 ) : ( $dated >= $n );
	}

	/** Build a `{ t:'timeline', items:[{date,title,text}] }` block. */
	private static function timeline_block( $el ) {
		$items = array();
		foreach ( self::widget_children( $el ) as $k ) {
			$title = self::item_title_text( $k );
			$date  = self::timeline_date( $k );
			if ( '' === $title && '' === $date ) { continue; }
			$body  = self::item_body_text( $k, $title );
			if ( $date !== '' && strpos( $body, $date ) === 0 ) { $body = trim( substr( $body, strlen( $date ) ) ); }
			$items[] = array( 'date' => $date, 'title' => $title, 'text' => $body );
		}
		return count( $items ) >= 2 ? array( 't' => 'timeline', 'items' => $items ) : null;
	}

	/* --------------------------------------------------------------------- *
	 * Progress / skill-bars recognizer
	 * --------------------------------------------------------------------- */

	/** A STRUCTURAL percent for a bar item (role=progressbar[aria-valuenow] or an inner `width:NN%`), or null. */
	private static function bar_percent( $el ) {
		// role=progressbar + aria-valuenow (self or descendant)
		$cands = array();
		if ( 'progressbar' === strtolower( (string) $el->getAttribute( 'role' ) ) ) { $cands[] = $el; }
		foreach ( $el->getElementsByTagName( '*' ) as $c ) { if ( 'progressbar' === strtolower( (string) $c->getAttribute( 'role' ) ) ) { $cands[] = $c; } }
		foreach ( $cands as $c ) {
			$v = $c->getAttribute( 'aria-valuenow' );
			if ( $v !== '' && is_numeric( $v ) ) { return max( 0, min( 100, (int) round( (float) $v ) ) ); }
		}
		// An inner element with inline style width:NN%
		$nodes = array( $el );
		foreach ( $el->getElementsByTagName( '*' ) as $c ) { $nodes[] = $c; }
		foreach ( $nodes as $c ) {
			$st = (string) $c->getAttribute( 'style' );
			if ( $st !== '' && preg_match( '/width\s*:\s*([\d.]+)\s*%/i', $st, $m ) ) {
				$cc = strtolower( (string) $c->getAttribute( 'class' ) );
				// Only treat as a bar fill if the element reads like a bar (or its parent does).
				if ( preg_match( '/\b(bar|fill|progress|meter|value|inner)\b/', $cc ) || $c !== $el ) { return max( 0, min( 100, (int) round( (float) $m[1] ) ) ); }
			}
		}
		return null;
	}

	/** TIGHT: >=2 items, EVERY item a structural bar (progressbar / width:%). Text-only "%" (a stat) is excluded. */
	private static function is_progress_bars( $el ) {
		if ( ! ( $el instanceof DOMElement ) ) { return false; }
		$tag = strtolower( $el->tagName );
		if ( in_array( $tag, array( 'table', 'thead', 'tbody', 'tr', 'nav', 'dl', 'details', 'summary' ), true ) ) { return false; }
		if ( $el->getElementsByTagName( 'details' )->length ) { return false; }
		$kids = self::widget_children( $el );
		$n    = count( $kids );
		if ( $n < 2 ) { return false; }
		$bars = 0;
		foreach ( $kids as $k ) { if ( self::bar_percent( $k ) !== null ) { $bars++; } }
		return $bars >= 2 && $bars === $n;
	}

	/** Build a `{ t:'progress', bars:[{label,percent}] }` block. */
	private static function progress_block( $el ) {
		$bars = array();
		foreach ( self::widget_children( $el ) as $k ) {
			$pct = self::bar_percent( $k );
			if ( null === $pct ) { continue; }
			// Label = a dedicated label element, else the item text with the percent stripped.
			$label = '';
			foreach ( $k->getElementsByTagName( '*' ) as $c ) {
				$cc = strtolower( (string) $c->getAttribute( 'class' ) );
				if ( preg_match( '/\b(label|skill-?name|title|name)\b/', $cc ) && self::text( $c ) !== '' ) { $label = self::text( $c ); break; }
			}
			if ( '' === $label ) { $label = trim( preg_replace( '/\b\d{1,3}\s*%/', '', self::text( $k ) ) ); }
			$bars[] = array( 'label' => $label, 'percent' => $pct );
		}
		return count( $bars ) >= 2 ? array( 't' => 'progress', 'bars' => $bars ) : null;
	}

	/* --------------------------------------------------------------------- *
	 * Tabs recognizer
	 * --------------------------------------------------------------------- */

	/** All descendants (optionally including $el) with a given role. */
	private static function els_with_role( $el, $role ) {
		$out = array();
		foreach ( $el->getElementsByTagName( '*' ) as $c ) { if ( $role === strtolower( (string) $c->getAttribute( 'role' ) ) ) { $out[] = $c; } }
		return $out;
	}

	/** TIGHT: a real tab widget — >=2 tabs each resolving to a panel. A plain <ul> nav never qualifies. */
	private static function is_tabs_widget( $el ) {
		if ( ! ( $el instanceof DOMElement ) ) { return false; }
		$tag = strtolower( $el->tagName );
		if ( in_array( $tag, array( 'table', 'nav', 'details', 'summary' ), true ) ) { return false; }
		// An accordion (>=2 <details>) is not a tabs widget.
		$dets = 0;
		foreach ( $el->getElementsByTagName( 'details' ) as $d ) { if ( $d->getElementsByTagName( 'summary' )->length ) { $dets++; } }
		if ( $dets >= 2 ) { return false; }
		// ARIA tabs.
		$tabs   = self::els_with_role( $el, 'tab' );
		$panels = self::els_with_role( $el, 'tabpanel' );
		if ( count( $tabs ) >= 2 ) {
			if ( count( $panels ) >= 2 ) { return true; }
			$resolved = 0; $doc = $el->ownerDocument;
			foreach ( $tabs as $t ) { $id = trim( (string) $t->getAttribute( 'aria-controls' ) ); if ( $id !== '' && $doc && $doc->getElementById( $id ) ) { $resolved++; } }
			if ( $resolved >= 2 ) { return true; }
		}
		// Class-based (`.tabs`/`.nav-tabs` + [data-tab]/aria-controls labels + `.tab-pane`/[data-tab-content] panels).
		$cls = self::cls( $el );
		if ( preg_match( '/\b(tabs|nav-tabs|tab-group|tabbed|tabset)\b/', $cls ) ) {
			$labels = 0;
			foreach ( $el->getElementsByTagName( '*' ) as $c ) {
				$ct = strtolower( $c->tagName );
				if ( ! in_array( $ct, array( 'a', 'button', 'li', 'span' ), true ) ) { continue; }
				$cc = strtolower( (string) $c->getAttribute( 'class' ) );
				if ( $c->hasAttribute( 'data-tab' ) || $c->hasAttribute( 'aria-controls' ) || preg_match( '/\b(tab-link|nav-link|tab-title|tab-btn)\b/', $cc ) ) { $labels++; }
			}
			$panels2 = 0;
			foreach ( $el->getElementsByTagName( '*' ) as $c ) {
				$cc = strtolower( (string) $c->getAttribute( 'class' ) );
				if ( $c->hasAttribute( 'data-tab-content' ) || preg_match( '/\b(tab-pane|tab-panel|tab-content-item)\b/', $cc ) ) { $panels2++; }
			}
			if ( $labels >= 2 && $panels2 >= 2 ) { return true; }
		}
		return false;
	}

	/** Build a `{ t:'tabs', items:[{title,content,active}] }` block. */
	private static function tabs_block( $el ) {
		$doc    = $el->ownerDocument;
		$labels = self::els_with_role( $el, 'tab' );
		$panels = self::els_with_role( $el, 'tabpanel' );
		if ( count( $labels ) < 2 ) {
			// Class-based fallback.
			$labels = array(); $panels = array();
			foreach ( $el->getElementsByTagName( '*' ) as $c ) {
				$ct = strtolower( $c->tagName );
				$cc = strtolower( (string) $c->getAttribute( 'class' ) );
				if ( in_array( $ct, array( 'a', 'button', 'li', 'span' ), true ) && ( $c->hasAttribute( 'data-tab' ) || $c->hasAttribute( 'aria-controls' ) || preg_match( '/\b(tab-link|nav-link|tab-title|tab-btn)\b/', $cc ) ) ) { $labels[] = $c; }
				if ( $c->hasAttribute( 'data-tab-content' ) || preg_match( '/\b(tab-pane|tab-panel|tab-content-item)\b/', $cc ) ) { $panels[] = $c; }
			}
		}
		$items = array();
		foreach ( $labels as $i => $lab ) {
			$title = self::text( $lab );
			if ( '' === $title ) { continue; }
			$panel = null;
			$ctrl  = trim( (string) $lab->getAttribute( 'aria-controls' ) );
			if ( '' === $ctrl ) { $ctrl = trim( (string) $lab->getAttribute( 'data-tab' ) ); }
			if ( '' !== $ctrl && $doc ) {
				$p = $doc->getElementById( $ctrl );
				if ( ! $p ) {
					foreach ( $panels as $pp ) { if ( trim( (string) $pp->getAttribute( 'data-tab-content' ) ) === $ctrl || trim( (string) $pp->getAttribute( 'id' ) ) === $ctrl ) { $p = $pp; break; } }
				}
				$panel = $p;
			}
			if ( ! $panel && isset( $panels[ $i ] ) ) { $panel = $panels[ $i ]; }
			$content = $panel ? self::strip_cs( trim( self::inner_html( $panel ) ) ) : '';
			$active  = ( 'true' === strtolower( (string) $lab->getAttribute( 'aria-selected' ) ) || preg_match( '/\bactive\b/', strtolower( (string) $lab->getAttribute( 'class' ) ) ) ) ? 'yes' : 'no';
			$items[] = array( 'title' => $title, 'content' => $content, 'active' => $active );
		}
		return count( $items ) >= 2 ? array( 't' => 'tabs', 'items' => $items ) : null;
	}

	/* --------------------------------------------------------------------- *
	 * Lottie / SVG-draw recognizers
	 * --------------------------------------------------------------------- */

	/** A Lottie src (`.json`/`.lottie`) carried on an element's known src attributes, or ''. */
	private static function lottie_src_of( $el ) {
		foreach ( array( 'src', 'data-src', 'data-animation-path', 'data-lottie', 'data-json', 'href' ) as $a ) {
			$v = trim( (string) $el->getAttribute( $a ) );
			if ( $v !== '' && preg_match( '/\.(json|lottie)(\?|#|$)/i', $v ) ) { return $v; }
		}
		return '';
	}

	/** A Lottie/Bodymovin embed: <lottie-player>/<dotlottie-player>, or a container carrying a `.json`/`.lottie` src. */
	private static function is_lottie_embed( $el, $tag = '' ) {
		if ( ! ( $el instanceof DOMElement ) ) { return false; }
		$tag = $tag !== '' ? strtolower( $tag ) : strtolower( $el->tagName );
		if ( 'lottie-player' === $tag || 'dotlottie-player' === $tag ) { return true; }
		if ( $el->getElementsByTagName( 'lottie-player' )->length || $el->getElementsByTagName( 'dotlottie-player' )->length ) { return true; }
		if ( self::lottie_src_of( $el ) !== '' ) {
			$cls = self::cls( $el );
			if ( preg_match( '/\b(lottie|bodymovin|dotlottie)\b/', $cls ) || $el->hasAttribute( 'data-animation-path' ) || $el->hasAttribute( 'data-lottie' ) ) { return true; }
		}
		return false;
	}

	/** Build a `{ t:'lottie', src }` block (src from the player element or the container). */
	private static function lottie_block( $el ) {
		$src = self::lottie_src_of( $el );
		if ( '' === $src ) {
			foreach ( array( 'lottie-player', 'dotlottie-player' ) as $pt ) {
				$p = $el->getElementsByTagName( $pt )->item( 0 );
				if ( $p ) { $src = self::lottie_src_of( $p ); if ( $src !== '' ) { break; } }
			}
		}
		if ( '' === $src ) { return null; }
		return array( 't' => 'lottie', 'src' => $src );
	}

	/** An inline <svg> whose strokes are draw-animated (dasharray/dashoffset) or explicitly flagged for drawing. */
	private static function is_svg_draw( $el, $tag = '' ) {
		if ( ! ( $el instanceof DOMElement ) ) { return false; }
		$tag = $tag !== '' ? strtolower( $tag ) : strtolower( $el->tagName );
		if ( 'svg' !== $tag ) { return false; }
		$cls = strtolower( (string) $el->getAttribute( 'class' ) );
		if ( preg_match( '/\b(svg-?draw|line-?draw|draw-?svg|animate-?draw|self-?draw)\b/', $cls ) ) { return true; }
		if ( $el->hasAttribute( 'data-draw' ) || $el->hasAttribute( 'data-svg-draw' ) ) { return true; }
		// A path/line/polyline carrying stroke-dasharray or stroke-dashoffset (the draw-on technique).
		foreach ( array( 'path', 'line', 'polyline', 'circle', 'rect' ) as $st ) {
			foreach ( $el->getElementsByTagName( $st ) as $p ) {
				if ( $p->hasAttribute( 'stroke-dasharray' ) || $p->hasAttribute( 'stroke-dashoffset' ) ) { return true; }
				$style = strtolower( (string) $p->getAttribute( 'style' ) );
				if ( strpos( $style, 'stroke-dasharray' ) !== false || strpos( $style, 'stroke-dashoffset' ) !== false ) { return true; }
			}
		}
		return false;
	}

	/** Build a `{ t:'svg_draw', code }` block carrying the outer SVG markup (scripts/handlers stripped). */
	private static function svg_draw_block( $el ) {
		$doc = $el->ownerDocument;
		if ( ! $doc ) { return null; }
		$markup = (string) $doc->saveHTML( $el );
		$markup = preg_replace( '#<script\b[^>]*>.*?</script>#is', '', $markup );
		$markup = preg_replace( '/\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $markup );
		$markup = self::strip_cs( trim( $markup ) );
		return '' !== $markup ? array( 't' => 'svg_draw', 'code' => $markup ) : null;
	}

	/**
	 * A grid child that carries real, sizeable content (a heading, paragraph, image/media, list, or >=20 chars
	 * of text) — as opposed to a bare button, link, spacer, or empty decorative div. Used to tell a genuine
	 * multi-column layout band from a button/pill row (whose children are NOT substantial).
	 */
	private static function is_substantial_cell( $k ) {
		if ( ! ( $k instanceof DOMElement ) ) { return false; }
		$tag = strtolower( $k->tagName );
		if ( in_array( $tag, array( 'button', 'a', 'input', 'br', 'hr', 'script', 'style' ), true ) ) { return false; }
		foreach ( array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'img', 'svg', 'video', 'iframe', 'picture', 'ul', 'ol' ) as $t ) {
			if ( $k->getElementsByTagName( $t )->length > 0 ) { return true; }
		}
		return mb_strlen( self::text( $k ) ) >= 20;
	}

	/**
	 * Does this element read as a genuine horizontal MULTI-COLUMN band at desktop (NOT a card grid, which is
	 * claimed earlier)? True when it's a `grid-cols-N` (N>=2, incl. responsive `lg:grid-cols-N`), a computed
	 * `grid-template-columns` with >=2 real tracks, or a desktop flex-ROW — AND it has >=2 substantial cells
	 * (so a button/pill row, whose children aren't substantial, never qualifies). Mobile stacking is fine — we
	 * key off the DESKTOP layout only.
	 */
	private static function is_layout_row( $el ) {
		if ( ! ( $el instanceof DOMElement ) ) { return false; }
		$kids = self::el_children( $el );
		if ( count( $kids ) < 2 ) { return false; }
		$subs = 0;
		foreach ( $kids as $k ) { if ( self::is_substantial_cell( $k ) ) { $subs++; } }
		if ( $subs < 2 ) { return false; }

		$cls = self::cls( $el );
		$cs  = (string) $el->getAttribute( 'data-sc-cs' );
		$pad = ' ' . $cls . ' ';

		// (A) Tailwind grid. A DESKTOP column count >=2 (explicit `grid-cols-N` or a responsive
		//     `lg:grid-cols-N`) is a genuine multi-column band. HARDENING: an explicit single-column
		//     `grid-cols-1` with NO wider responsive override is a vertical STACK, not a row — reject it
		//     outright instead of letting grid_col_count() fall through to its card-cell fallback (which would
		//     mis-count stacked card-like children as columns and over-claim a single-column heading/content band).
		$has_grid = ( strpos( $cls, 'grid' ) !== false ) || (bool) preg_match( '/display:\s*grid/i', $cs );
		if ( $has_grid ) {
			$multi_bp = false;
			foreach ( array( '2xl', 'xl', 'lg', 'md', 'sm' ) as $bp ) {
				if ( preg_match( '/' . $bp . ':grid-cols-(\d{1,2})/', $cls, $mm ) && (int) $mm[1] >= 2 ) { $multi_bp = true; break; }
			}
			if ( $multi_bp ) { return true; }
			$base_cols = preg_match( '/(?:^|\s)grid-cols-(\d{1,2})/', $cls, $bm ) ? (int) $bm[1] : -1;
			if ( $base_cols >= 2 ) { return true; }
			if ( 1 === $base_cols ) { return false; } // explicit single column, not widened responsively → stack
			// A grid with NO explicit grid-cols (pure computed grid or auto-flow): fall through to (B)/(C).
		}

		// (B) Computed grid-template-columns with >=2 non-trivial tracks (non-Tailwind / plain CSS grid).
		if ( preg_match( '/grid-template-columns:\s*([^;]+)/i', $cs, $m ) ) {
			$tracks = array_values( array_filter( preg_split( '/\s+/', trim( $m[1] ) ), function ( $t ) { return '' !== $t && 'none' !== strtolower( $t ); } ) );
			if ( count( $tracks ) >= 2 ) { return true; }
		}

		// (C) Desktop flex-ROW. HARDENING: require a REAL flex-container token (`flex` / `inline-flex` as a
		//     whole class, NOT the substring inside `flex-col` / `flex-wrap`) AND a row direction AT DESKTOP. A
		//     base `flex-col` that ISN'T overridden by a responsive `*:flex-row` is a vertical stack → reject.
		//     A bare `flex` with no direction class is NOT claimed here (matching the original strict intent):
		//     an explicit row signal is required so a centered single-column flex band never becomes a row.
		$is_flex = (bool) preg_match( '/(?:^|\s)(?:inline-)?flex(?:\s|$)/', $pad ) || (bool) preg_match( '/display:\s*(?:inline-)?flex/i', $cs );
		if ( $is_flex ) {
			$has_col   = (bool) preg_match( '/(?:^|\s)(?:(?:sm|md|lg|xl|2xl):)?flex-col(?:\s|$)/', $pad );
			$resp_row  = (bool) preg_match( '/(?:^|\s)(?:sm|md|lg|xl|2xl):flex-row(?:\s|$)/', $pad );
			$base_row  = (bool) preg_match( '/(?:^|\s)flex-row(?:\s|$)/', $pad );
			if ( $resp_row ) { return true; }                          // desktop override to row wins
			if ( $base_row && ! $has_col ) { return true; }            // explicit row, not also declared column
			if ( ! $has_col && preg_match( '/flex-direction:\s*row/i', $cs ) ) { return true; } // computed row, no col class
		}

		return false;
	}

	/** A row's desktop vertical alignment (source `items-center` / computed `align-items:center`) → start|center|end. */
	private static function row_valign( $el ) {
		$cls = self::cls( $el );
		$cs  = (string) ( $el instanceof DOMElement ? $el->getAttribute( 'data-sc-cs' ) : '' );
		if ( strpos( $cls, 'items-center' ) !== false || preg_match( '/align-items:\s*center/i', $cs ) ) { return 'center'; }
		if ( strpos( $cls, 'items-end' ) !== false || preg_match( '/align-items:\s*(?:flex-)?end/i', $cs ) ) { return 'end'; }
		if ( strpos( $cls, 'items-start' ) !== false || preg_match( '/align-items:\s*(?:flex-)?start/i', $cs ) ) { return 'start'; }
		return '';
	}

	/**
	 * Build the Mapper "cols" array for a LAYOUT row (is_layout_row). Unlike grid_cols (which maps card cells
	 * to icon_boxes), a layout band's cells hold mixed content (a hero's text+CTA column, an image column),
	 * so each cell is kept VERBATIM with its classes inside a `.sc-tw` wrapper — the Tailwind compiler then
	 * styles it, matching the source. Widths come from an explicit `col-span-N`, else even division across the
	 * band. Empty / purely-decorative cells are dropped. Returns null if fewer than 2 real cells survive.
	 */
	private static function layout_cols( $grid ) {
		$children = self::el_children( $grid );
		$n        = count( $children );
		$grid_n   = self::grid_col_count( $grid );
		if ( $grid_n < 2 ) { $grid_n = $n; } // flex row / computed grid: even division across the children
		$rules = self::rules_get();
		$out = array();
		foreach ( $children as $cell ) {
			if ( ! ( $cell instanceof DOMElement ) ) { continue; }
			// Drop a truly empty / decorative cell (no text AND no media).
			$has_media = $cell->getElementsByTagName( 'img' )->length || $cell->getElementsByTagName( 'svg' )->length
				|| $cell->getElementsByTagName( 'video' )->length || $cell->getElementsByTagName( 'iframe' )->length;
			if ( '' === self::text( $cell ) && ! $has_media ) { continue; }

			$desk = self::col_span( self::cls( $cell ) );
			if ( $desk < 1 ) { $desk = $grid_n > 0 ? (int) round( 12 / $grid_n ) : 0; }
			$wResp = ( $desk >= 1 && $desk <= 12 ) ? array( 'desktop' => $desk ) : null;

			// A CONTENT cell (a hero's text+CTA column: heading / prose / buttons) is DECOMPOSED into normal
			// shortcode blocks — the same recognizer decomposition the single-column section path uses — so a
			// heading becomes special_heading, buttons become button, etc., instead of one opaque code_block.
			// A MEDIA-only cell (the hero's image / organic blob / floating badge, no heading and no real
			// prose) stays VERBATIM (`.sc-tw`), where the Tailwind compiler reproduces its look.
			if ( self::cell_is_decomposable( $cell ) ) {
				$cblocks = array();
				self::collect_blocks( $cell, $cblocks, $rules );
				$cblocks = array_values( array_filter( $cblocks ) );
				if ( $cblocks ) {
					$out[] = array( 'cls' => '', 'wResp' => $wResp, 'blocks' => $cblocks );
					continue;
				}
			}

			// A MEDIA cell that is an image COMPOSITE (photo + floating badge / blob backdrop) — no
			// heading / prose, so cell_is_decomposable() said no — is decomposed into NATIVE elements
			// (media_image + icon_box) instead of one verbatim code_block (P0 fidelity fix). The image's
			// organic radius / border / shadow + the blob ride on scoped Custom CSS; the badge's
			// icon/title/subtitle become editable. Parity with the JS to-pages imgComposite path.
			if ( self::is_decomposable_image_composite( $cell ) ) {
				$cb = self::image_composite_decompose( $cell );
				if ( $cb ) {
					$out[] = array( 'cls' => '', 'wResp' => $wResp, 'blocks' => $cb );
					continue;
				}
			}

			$doc = $cell->ownerDocument;
			$v   = $doc ? self::strip_cs( trim( (string) $doc->saveHTML( $cell ) ) ) : '';
			if ( '' === $v ) { continue; }
			$out[] = array( 'cls' => '', 'wResp' => $wResp, 'html' => '<div class="sc-tw">' . $v . '</div>' );
		}
		return count( $out ) >= 2 ? $out : null;
	}

	/**
	 * Should a layout-row cell be DECOMPOSED into shortcode blocks (vs kept verbatim)? True when it carries a
	 * heading (h1–h6) or real prose (a <p> with >=20 chars) — i.e. a content column (hero text + CTA). A cell
	 * that is only an image / SVG blob / decorative badge (no heading, no substantial paragraph) stays verbatim.
	 */
	private static function cell_is_decomposable( $cell ) {
		if ( ! ( $cell instanceof DOMElement ) ) { return false; }
		for ( $i = 1; $i <= 6; $i++ ) { if ( $cell->getElementsByTagName( 'h' . $i )->length > 0 ) { return true; } }
		foreach ( $cell->getElementsByTagName( 'p' ) as $p ) { if ( mb_strlen( trim( self::text( $p ) ) ) >= 20 ) { return true; } }
		return false;
	}

	/**
	 * Walk a section, emitting role-annotated blocks in document order. Each child is offered to the
	 * recognizer registry (highest priority first); the FIRST match claims it (no descent), and its build()
	 * appends a block / list / nothing. Unclaimed elements are descended into (hero text wrappers, button
	 * rows, intro blocks, …) — exactly the original behavior, now table-driven.
	 */
	/** Parse a CSS length ('64px' / '4rem') to a px float. */
	private static function px_num( $v ) {
		$v = trim( (string) $v );
		if ( preg_match( '/^(-?\d+(?:\.\d+)?)rem$/', $v, $m ) ) { return (float) $m[1] * 16; }
		if ( preg_match( '/^(-?\d+(?:\.\d+)?)px$/', $v, $m ) ) { return (float) $m[1]; }
		return 0.0;
	}

	/** An element's own vertical margin in px, from Tailwind classes (mt/mb/my, incl. arbitrary
	 *  `mb-[64px]`) with a data-sc-cs `margin` shorthand fallback. */
	private static function el_margin( $el ) {
		$r   = array( 'top' => 0.0, 'bottom' => 0.0 );
		$cls = self::cls( $el );
		if ( preg_match_all( '/(?:^|\s)m([tby])-(\[[^\]]+\]|-?\d+(?:\.\d+)?)/', ' ' . $cls . ' ', $ms, PREG_SET_ORDER ) ) {
			foreach ( $ms as $m ) {
				$tok = $m[2];
				$v   = ( '[' === $tok[0] ) ? self::px_num( trim( $tok, '[]' ) ) : ( (float) $tok * 4 );
				if ( 't' === $m[1] || 'y' === $m[1] ) { $r['top']    += $v; }
				if ( 'b' === $m[1] || 'y' === $m[1] ) { $r['bottom'] += $v; }
			}
		}
		if ( $r['top'] <= 0 || $r['bottom'] <= 0 ) {
			$cs = (string) $el->getAttribute( 'data-sc-cs' );
			if ( '' !== $cs && preg_match( '/(?:^|;)\s*margin:\s*([^;]+)/', $cs, $mm ) ) {
				$pp = preg_split( '/\s+/', trim( $mm[1] ) ); $n = count( $pp );
				$top = self::px_num( $pp[0] ); $bot = $n >= 3 ? self::px_num( $pp[2] ) : $top;
				if ( $r['top'] <= 0 )    { $r['top'] = $top; }
				if ( $r['bottom'] <= 0 ) { $r['bottom'] = $bot; }
			}
		}
		return $r;
	}

	private static function collect_blocks( $node, array &$blocks, array $rules ) {
		$recognizers = self::recognizers();
		foreach ( $node->childNodes as $child ) {
			if ( XML_ELEMENT_NODE !== $child->nodeType ) { continue; }
			$tag     = strtolower( $child->tagName );
			$claimed = false;
			foreach ( $recognizers as $r ) {
				if ( ! call_user_func( $r['match'], $child, $tag, $rules ) ) { continue; }
				$out = call_user_func( $r['build'], $child, $tag, $rules );
				$ai  = self::anim_intent( $child ); // source reveal/scroll-animation intent (AOS / animate.css / hooks)
				if ( is_array( $out ) ) {
					if ( isset( $out['t'] ) ) { if ( '' !== $ai && empty( $out['anim'] ) ) { $out['anim'] = $ai; } $blocks[] = $out; } // one block
					else { foreach ( $out as $blk ) { if ( is_array( $blk ) && isset( $blk['t'] ) ) { if ( '' !== $ai && empty( $blk['anim'] ) ) { $blk['anim'] = $ai; } $blocks[] = $blk; } } } // a list
				}
				$claimed = true;
				break;
			}
			if ( ! $claimed ) {
				$before = count( $blocks );
				self::collect_blocks( $child, $blocks, $rules );
				// A plain grouping wrapper (e.g. <div class="text-center mb-16">) is flattened; carry its OWN
				// vertical margin onto the boundary blocks it produced so the group's spacing survives.
				if ( count( $blocks ) > $before ) {
					$wm = self::el_margin( $child );
					if ( $wm['bottom'] > 0 ) { $li = count( $blocks ) - 1; $blocks[ $li ]['mbAdd'] = max( (float) ( isset( $blocks[ $li ]['mbAdd'] ) ? $blocks[ $li ]['mbAdd'] : 0 ), $wm['bottom'] ); }
					if ( $wm['top'] > 0 )    { $blocks[ $before ]['mtAdd'] = max( (float) ( isset( $blocks[ $before ]['mtAdd'] ) ? $blocks[ $before ]['mtAdd'] : 0 ), $wm['top'] ); }
					// Heading-group SUBTITLE fold: a wrapper that produced ONLY a heading (title/heading) plus
					// overline/text — and NO button, media, or columns — is a tight heading group (source
					// `<div class="heading"><span>overline</span><h1>…</h1><p>…</p></div>`). Its first paragraph
					// after the title is the heading's SUBTITLE, so retag it 'subtitle' — the mapper then folds
					// it INTO the special_heading instead of emitting a stray text_block. Mirrors the hand-built
					// demo (hero + features fold the subtitle into the special_heading; a CTA whose descriptive
					// text sits in the same wrapper as its button stays a separate text_block — button present →
					// not a pure heading group → no fold).
					self::fold_group_subtitle( $blocks, $before );
					// A reveal-animated WRAPPER (`<div data-aos="fade-up">…`) that flattened into leaf blocks →
					// carry its animation intent onto the produced blocks that don't already carry their own.
					$wai = self::anim_intent( $child );
					if ( '' !== $wai ) { for ( $wi = $before, $wt = count( $blocks ); $wi < $wt; $wi++ ) { if ( empty( $blocks[ $wi ]['anim'] ) ) { $blocks[ $wi ]['anim'] = $wai; } } }
				}
			}
		}
	}

	/**
	 * Fold a tight heading group's first paragraph into the heading SUBTITLE. Given the run of blocks a
	 * single wrapper just produced (indices $before..end of $blocks), if that run is a PURE heading group
	 * — exactly one title/heading block, the rest only overline/subtitle/text, and NO button / image /
	 * video / columns / card — retag the first `text` block AFTER the title as role 'subtitle'. The mapper
	 * merges overline+title+subtitle into ONE special_heading, so the subtitle stops rendering as a
	 * separate text_block. No-op when the run mixes in interactive/media/grid content (not a heading group).
	 *
	 * @param array $blocks  by-ref flat block list
	 * @param int   $before  index of the first block this wrapper produced
	 */
	private static function fold_group_subtitle( array &$blocks, $before ) {
		$total = count( $blocks );
		$added = $total - $before;
		if ( $added < 2 ) { return; }
		$title_at = -1; $n_titles = 0;
		for ( $i = $before; $i < $total; $i++ ) {
			$role = isset( $blocks[ $i ]['role'] ) ? (string) $blocks[ $i ]['role'] : '';
			$t    = isset( $blocks[ $i ]['t'] ) ? (string) $blocks[ $i ]['t'] : '';
			if ( 'title' === $role || 'heading' === $role ) { $n_titles++; if ( $title_at < 0 ) { $title_at = $i; } }
			elseif ( 'overline' === $role || 'subtitle' === $role || 'announcement_pill' === $role ) { continue; } // heading parts (incl. eyebrow pill) — allowed
			elseif ( 'text' === $t || 'pill' === $t ) { continue; }               // a paragraph / eyebrow pill — allowed
			else { return; }                                                      // button / image / columns / card → not a pure heading group
		}
		if ( 1 !== $n_titles || $title_at < 0 ) { return; }
		for ( $j = $title_at + 1; $j < $total; $j++ ) {
			if ( 'text' === ( isset( $blocks[ $j ]['t'] ) ? $blocks[ $j ]['t'] : '' )
				&& 'subtitle' !== ( isset( $blocks[ $j ]['role'] ) ? $blocks[ $j ]['role'] : '' ) ) {
				$blocks[ $j ]['role'] = 'subtitle';
				return;
			}
		}
	}

	/** Is this element a grid whose children are cards (→ a columns row)? */
	private static function is_card_grid( $el ) {
		$cls = self::cls( $el );
		if ( strpos( $cls, 'grid' ) === false && strpos( $cls, 'flex' ) === false ) { return false; }
		$kids = self::el_children( $el );
		if ( count( $kids ) < 2 ) { return false; }
		$cards = 0;
		foreach ( $kids as $k ) {
			if ( self::is_card_cell( $k ) ) { $cards++; }
		}
		return $cards >= 2;
	}

	/** Computed-style card-grid test — framework-agnostic (data-sc-cs): a flex/grid container with >=2 card
	 *  cells (each carrying a heading). Catches plain-CSS / Bootstrap card rows that have no Tailwind class. */
	private static function cs_is_card_grid( $el ) {
		$cs = $el->getAttribute( 'data-sc-cs' );
		if ( strpos( $cs, 'display:flex' ) === false && strpos( $cs, 'display:grid' ) === false && strpos( $cs, 'display:inline-flex' ) === false ) { return false; }
		$kids = self::el_children( $el );
		if ( count( $kids ) < 2 ) { return false; }
		$cards = 0;
		foreach ( $kids as $k ) { if ( self::is_card_cell( $k ) ) { $cards++; } }
		return $cards >= 2;
	}

	/** A container that's just a strip of images (≥2 imgs, no headings/cards) — a logo / "trusted by" row. */
	private static function is_logo_strip( $el ) {
		if ( ! ( $el instanceof DOMElement ) ) { return false; }
		if ( $el->getElementsByTagName( 'img' )->length < 2 ) { return false; }
		foreach ( array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ) as $h ) {
			if ( $el->getElementsByTagName( $h )->length > 0 ) { return false; }
		}
		return true;
	}

	/**
	 * If $el (or a descendant div) is an OVERLAPPING ROUND-AVATAR STACK, return the avatar image URLs in
	 * document order; else an empty array. The signal that separates a reviewer stack from a normal image
	 * row is (a) OVERLAP — a Tailwind `-space-x-*` on the stack, or a negative `margin-left` on the avatar
	 * items/container (computed `data-sc-cs`) — AND (b) ROUND avatar imgs (>=2 imgs that are, or whose
	 * wrapper is, a circle: `rounded-full` / `border-radius:9999px|50%`). Both are required so a plain row
	 * of rectangular thumbnails can't misfire. Mirrors the JS capture-extract avatar detection.
	 *
	 * @param DOMElement $el
	 * @return array<string>
	 */
	/**
	 * Does $el read as a TIGHT reviewer-avatar wrapper (the stack itself, or the small "avatars + rating
	 * caption" cluster) — as opposed to a whole content COLUMN that merely contains a stack somewhere deep?
	 * Requires the overlapping round stack AND rejects any element that carries a heading (h1–h6) or a long
	 * body of text (> 160 chars) — so a hero's text+CTA column (h1 + prose) is never swallowed. This keeps
	 * the hero band decomposing normally (special_heading + buttons) while the compact stack is claimed.
	 *
	 * @param DOMElement $el
	 * @return bool
	 */
	private static function is_avatar_group( $el ) {
		if ( count( self::avatar_stack_urls( $el ) ) < 2 ) { return false; }
		if ( ! ( $el instanceof DOMElement ) ) { return false; }
		for ( $i = 1; $i <= 6; $i++ ) { if ( $el->getElementsByTagName( 'h' . $i )->length > 0 ) { return false; } }
		$t = trim( (string) preg_replace( '/\s+/', ' ', self::text( $el ) ) );
		if ( mb_strlen( $t ) > 160 ) { return false; } // a rating caption is short; a content column is long
		return true;
	}

	private static function avatar_stack_urls( $el ) {
		if ( ! ( $el instanceof DOMElement ) ) { return array(); }
		$cands = array( $el );
		foreach ( $el->getElementsByTagName( 'div' ) as $d ) { $cands[] = $d; }
		foreach ( $cands as $c ) {
			$imgs = $c->getElementsByTagName( 'img' );
			if ( $imgs->length < 2 ) { continue; }
			$cls     = self::cls( $c );
			$ccs     = (string) $c->getAttribute( 'data-sc-cs' );
			$overlap = (bool) preg_match( '/-space-x-\d/', $cls );
			if ( ! $overlap && '' !== $ccs && preg_match( '/margin-left:\s*-/', $ccs ) ) { $overlap = true; }
			$urls  = array();
			$round = 0;
			$small = 0;
			foreach ( $imgs as $im ) {
				$icls = self::cls( $im );
				$wrap = ( $im->parentNode instanceof DOMElement ) ? $im->parentNode : null;
				$wcls = $wrap ? self::cls( $wrap ) : '';
				$wcs  = $wrap ? (string) $wrap->getAttribute( 'data-sc-cs' ) : '';
				$ics  = (string) $im->getAttribute( 'data-sc-cs' );
				if ( preg_match( '/rounded-full/', $icls . ' ' . $wcls ) || preg_match( '/border-radius:\s*(?:9999px|50%)/', $ics . ' ' . $wcs ) ) { $round++; }
				// Small: a low Tailwind sizing step (w-6..w-16 / h-6..h-16) on the img or its wrapper, or a
				// computed width/height <= 72px. Keeps genuine ~24-64px avatars in, big media rows out.
				if ( preg_match( '/\b[wh]-(?:[4-9]|1[0-6])\b/', $icls . ' ' . $wcls ) ) { $small++; }
				elseif ( preg_match( '/(?:^|;)\s*(?:width|height):\s*(\d+(?:\.\d+)?)px/', $ics . ' ' . $wcs, $sm ) && (float) $sm[1] <= 72 ) { $small++; }
				// Overlap can also surface as a negative margin on the avatar ITEM (space-x compiles to child margins).
				if ( ! $overlap && preg_match( '/margin-left:\s*-/', $ics . ' ' . $wcs ) ) { $overlap = true; }
				$src = trim( (string) $im->getAttribute( 'src' ) );
				if ( '' === $src ) { $src = trim( (string) $im->getAttribute( 'data-src' ) ); }
				if ( '' !== $src ) { $urls[] = $src; }
			}
			if ( $overlap && $round >= 2 && $small >= 2 && count( $urls ) >= 2 ) { return $urls; }
		}
		return array();
	}

	/**
	 * Build the block(s) for an avatar stack: a native `avatar` GROUP block (the images + a "+N" counter
	 * parsed from the adjacent text) PLUS, when a rating/label survives, a verbatim code block for the
	 * stars + score text (avatar stack stripped out). Mirrors the JS ratingRowNode (avatar group + the
	 * verbatim stars/score). Returns null if it isn't a genuine stack.
	 *
	 * @param DOMElement $el
	 * @return array|null
	 */
	private static function avatar_group_build( $el ) {
		$urls = self::avatar_stack_urls( $el );
		if ( count( $urls ) < 2 ) { return null; }
		$urls  = array_slice( $urls, 0, 8 );
		$label = trim( (string) preg_replace( '/\s+/', ' ', self::text( $el ) ) );
		$extra = '';
		// A "+N / 500+ / 2K+" social-proof counter for the stack, from the adjacent caption.
		if ( preg_match( '/(\d[\d,.]*\s*[kKmM]?\s*\+)/', $label, $m ) ) { $extra = (string) preg_replace( '/\s+/', '', $m[1] ); }
		$blocks   = array();
		$blocks[] = array( 't' => 'avatar', 'role' => 'avatar', 'avatars' => $urls, 'extra_count' => $extra, 'label' => $label );
		$rating = self::avatar_group_rating_html( $el );
		if ( '' !== $rating ) {
			$blocks[] = array( 't' => 'html', 'role' => 'code', 'html' => '<div class="sc-tw">' . $rating . '</div>' );
		}
		return $blocks;
	}

	/**
	 * The adjacent rating/label markup (stars glyphs + "4.9/5 from 500+ …") as verbatim HTML, with the
	 * overlapping avatar stack removed (those become the `avatar` shortcode). Empty when nothing textual
	 * remains beside the faces. Mirrors the JS capture-extract, which strips the avatar imgs and keeps the
	 * stars/score verbatim.
	 *
	 * @param DOMElement $el
	 * @return string
	 */
	private static function avatar_group_rating_html( $el ) {
		$doc = $el->ownerDocument;
		if ( ! $doc ) { return ''; }
		$clone   = $el->cloneNode( true );
		$removed = false;
		// Remove any overlapping avatar-stack container (`-space-x-*`) wholesale.
		foreach ( iterator_to_array( $clone->getElementsByTagName( '*' ) ) as $node ) {
			if ( ! ( $node instanceof DOMElement ) || ! $node->parentNode ) { continue; }
			if ( preg_match( '/-space-x-\d/', self::cls( $node ) ) ) { $node->parentNode->removeChild( $node ); $removed = true; }
		}
		// Fallback (no Tailwind class): strip each avatar img up to its bare (text-less) wrapper.
		if ( ! $removed ) {
			foreach ( iterator_to_array( $clone->getElementsByTagName( 'img' ) ) as $im ) {
				$n = $im;
				while ( $n->parentNode && $n->parentNode !== $clone && '' === trim( self::text( $n->parentNode ) ) ) { $n = $n->parentNode; }
				if ( $n->parentNode ) { $n->parentNode->removeChild( $n ); }
			}
		}
		if ( '' === trim( self::text( $clone ) ) ) { return ''; } // nothing but the faces — no rating text
		return self::strip_cs( trim( (string) $doc->saveHTML( $clone ) ) );
	}

	/**
	 * Is a grid child a feature/bento CARD (vs. a CTA button in a flex row)? A card is a non-button
	 * container that holds its own heading — that's what separates a feature card from a button row
	 * (the #1 false positive: a hero/CTA's `flex` button group looks grid-ish but has no headings).
	 *
	 * @param DOMElement $k
	 * @return bool
	 */
	/** Structural testimonials grid (utility-class / Tailwind): a flex/grid whose >=2 sibling cards each
	 *  read like a quote — quote marks, a star rating, or a "— Name" attribution. Quote/rating signals keep
	 *  it from matching plain feature/pricing grids. Mirror of the JS testimonialsOf() structural fallback. */
	private static function is_testimonials_grid( $el ) {
		$cls = self::cls( $el );
		$cs  = (string) $el->getAttribute( 'data-sc-cs' );
		$is_grid = strpos( $cls, 'grid' ) !== false || strpos( $cls, 'flex' ) !== false
			|| strpos( $cs, 'display:flex' ) !== false || strpos( $cs, 'display:grid' ) !== false || strpos( $cs, 'display:inline-flex' ) !== false;
		if ( ! $is_grid ) { return false; }
		$kids = self::el_children( $el );
		if ( count( $kids ) < 2 ) { return false; }
		$cards = 0;
		foreach ( $kids as $k ) { if ( self::looks_quote_card( $k ) ) { $cards++; } }
		return $cards >= 2 && $cards >= count( $kids ) - 1;
	}
	/** A card that reads like a testimonial (has a paragraph AND a quote/rating/attribution signal). */
	private static function looks_quote_card( $k ) {
		if ( ! ( $k instanceof DOMElement ) ) { return false; }
		if ( $k->getElementsByTagName( 'p' )->length === 0 && $k->getElementsByTagName( 'blockquote' )->length === 0 ) { return false; }
		$t = self::text( $k );
		if ( mb_strlen( $t ) < 30 ) { return false; }
		if ( preg_match( '/["“”«»‘’]/u', $t ) ) { return true; }
		if ( self::testimonial_rating( $k ) !== null ) { return true; }
		return (bool) preg_match( '/(^|\s)[—–-]\s*[A-Z][a-z]+/u', $t );
	}
	/** Star-rating glyph count in a card (svg/i with a `star` class), or null. */
	private static function testimonial_rating( $k ) {
		$n = 0;
		foreach ( array( 'svg', 'i' ) as $tg ) {
			foreach ( $k->getElementsByTagName( $tg ) as $g ) { if ( strpos( self::cls( $g ), 'star' ) !== false ) { $n++; } }
		}
		return $n > 0 ? min( 5, $n ) : null;
	}
	/** Extract testimonial rows {quote,image,name,position,rating} from a grid's quote-cards. */
	private static function testimonials_items( $el ) {
		$rows = array();
		foreach ( self::el_children( $el ) as $k ) {
			if ( ! self::looks_quote_card( $k ) ) { continue; }
			$quote = ''; $qlen = 0;
			foreach ( array( 'blockquote', 'p' ) as $tg ) {
				foreach ( $k->getElementsByTagName( $tg ) as $p ) { $t = self::text( $p ); if ( mb_strlen( $t ) > $qlen ) { $qlen = mb_strlen( $t ); $quote = $t; } }
			}
			$image = ''; $imgs = $k->getElementsByTagName( 'img' ); if ( $imgs->length ) { $image = (string) $imgs->item( 0 )->getAttribute( 'src' ); }
			$cands = array();
			foreach ( array( 'h3', 'h4', 'h5', 'h6', 'cite', 'strong', 'span', 'p', 'div' ) as $tg ) {
				foreach ( $k->getElementsByTagName( $tg ) as $e ) {
					$t = trim( self::text( $e ) );
					if ( $t !== '' && $t !== $quote && mb_strlen( $t ) <= 40 && $e->getElementsByTagName( '*' )->length <= 1 ) { $cands[] = $t; }
				}
			}
			$cands    = array_values( array_unique( $cands ) );
			$rows[]   = array(
				'quote' => $quote, 'image' => $image,
				'name'  => isset( $cands[0] ) ? $cands[0] : '', 'position' => isset( $cands[1] ) ? $cands[1] : '',
				'rating' => self::testimonial_rating( $k ),
			);
		}
		return $rows;
	}

	private static function is_card_cell( $k ) {
		$tag = strtolower( $k->tagName );
		if ( $tag === 'button' || $tag === 'input' ) { return false; }
		foreach ( array( 'h2', 'h3', 'h4', 'h5', 'h6' ) as $h ) {
			if ( $k->getElementsByTagName( $h )->length > 0 ) { return true; }
		}
		return false;
	}

	/**
	 * Build the Mapper "cols" array from a grid's child cells. Each cell → an icon_box card when it
	 * carries an icon + heading; else a text cell; else verbatim HTML.
	 *
	 * @param DOMElement $grid
	 * @return array[]
	 */
	private static function grid_cols( $grid ) {
		$grid_cols = self::grid_col_count( $grid );
		$out = array();
		foreach ( self::el_children( $grid ) as $cell ) {
			$cls   = self::cls( $cell );
			$desk  = self::col_span( $cls );                 // explicit col-span-N (12-grid)
			if ( $desk < 1 ) { $desk = $grid_cols > 0 ? (int) round( 12 / $grid_cols ) : 0; }
			$wResp = $desk >= 1 && $desk <= 12 ? array( 'desktop' => $desk ) : null;

			$card = self::card_from_cell( $cell );
			$col  = array( 'cls' => '', 'wResp' => $wResp );
			if ( $card ) {
				$col['card'] = $card;
			} else {
				$btns = self::buttons_from_cell( $cell ); // a CTA button-group cell?
				if ( $btns ) {
					$col['buttons'] = $btns;
				} else {
					// A text-only cell → an editable text_block (role 'text'), NOT an opaque code_block.
					// A truly EMPTY / decorative cell is DROPPED. Only a media/structural blob stays verbatim.
					$html  = self::clean_block_html( $cell );
					$plain = trim( preg_replace( '/\s+/', ' ', strip_tags( $html ) ) );
					$media = (bool) preg_match( '/<(img|svg|video|iframe|picture|canvas|input|button|select|textarea)\b/i', $html );
					if ( $plain === '' && ! $media ) { continue; }                                // drop empty / decorative cell
					$has_img = $cell->getElementsByTagName( 'img' )->length > 0;
					$has_txt = false;
					foreach ( array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p' ) as $tg ) { if ( $cell->getElementsByTagName( $tg )->length > 0 ) { $has_txt = true; break; } }
					if ( $plain !== '' && ! $media ) { $col['role'] = 'text'; $col['text'] = $html; }  // editable text_block
					elseif ( $has_img && ! $has_txt ) { $col['role'] = 'image'; $col['html'] = $html; } // image-dominant cell → media_image
					else { $col['html'] = $html; }                                                     // media / structural → verbatim
				}
			}
			// The cell's OWN flex layout (from data-sc-cs) → replay via the column's native
			// content_direction / gap (a flex-ROW cell lays its children side-by-side).
			$cs = (string) $cell->getAttribute( 'data-sc-cs' );
			if ( preg_match( '/display:\s*(?:inline-)?flex/', $cs ) && count( self::el_children( $cell ) ) >= 2 ) {
				$dir = preg_match( '/flex-direction:\s*([a-z-]+)/', $cs, $dm ) ? $dm[1] : 'row';
				$gap = preg_match( '/(?:^|;)\s*gap:\s*([0-9.]+)px/', $cs, $gm ) ? $gm[1] : '';
				$col['flex'] = array( 'dir' => $dir, 'gap' => $gap );
			}
			// PRODUCT-CARD wrapper skin + hover + ribbon (image-bearing cells only) → the wc_products
			// mapper reproduces the card look via scoped CSS. REST skin from data-sc-cs (computed); HOVER
			// (shadow / lift) from the wrapper's `hover:*` classes (computed style can't see :hover).
			// Mirrors the JS capture-extract rowCols product-card capture. Was the gap that dropped the
			// source card's `hover:shadow-xl hover:-translate-y-2` + its badge.
			if ( $cell->getElementsByTagName( 'img' )->length > 0 ) {
				$wcls = self::cls( $cell );
				$cprop = static function ( $s, $p ) { return preg_match( '/(?:^|;)\s*' . preg_quote( $p, '/' ) . ':\s*([^;]+)/i', (string) $s, $m ) ? trim( $m[1] ) : ''; };
				if ( preg_match( '/border|shadow|rounded/i', $wcls ) || $cprop( $cs, 'border-radius' ) || $cprop( $cs, 'box-shadow' ) ) {
					$shadow = $cprop( $cs, 'box-shadow' );
					$col['wrap'] = array(
						'bg'          => $cprop( $cs, 'background-color' ),
						'radius'      => $cprop( $cs, 'border-radius' ),
						'borderW'     => $cprop( $cs, 'border-top-width' ),
						'borderStyle' => $cprop( $cs, 'border-top-style' ),
						'borderColor' => $cprop( $cs, 'border-top-color' ),
						'shadow'      => ( $shadow && 'none' !== $shadow ) ? $shadow : '',
						'hoverShadow' => preg_match( '/(?:^|\s)hover:shadow-(2xl|xl|lg|md|sm)(?:\s|$)/', $wcls, $hm ) ? $hm[1] : '',
						'hoverLift'   => preg_match( '/(?:^|\s)hover:-translate-y-([0-9.]+)(?:\s|$)/', $wcls, $lm ) ? $lm[1] : '',
					);
				}
				// A small uppercase pill inside the card → the ribbon/badge (e.g. "Best Seller").
				foreach ( $cell->getElementsByTagName( 'span' ) as $sp ) {
					$t = trim( self::text( $sp ) );
					if ( '' === $t || strlen( $t ) > 24 || $sp->getElementsByTagName( '*' )->length > 0 ) { continue; }
					$scs = (string) $sp->getAttribute( 'data-sc-cs' );
					if ( preg_match( '/text-transform:\s*uppercase/i', $scs ) && (float) $cprop( $scs, 'border-radius' ) >= 8 ) {
						$col['ribbon'] = array(
							'text' => $t, 'bg' => $cprop( $scs, 'background-color' ), 'color' => $cprop( $scs, 'color' ),
							'radius' => $cprop( $scs, 'border-radius' ), 'padding' => $cprop( $scs, 'padding' ),
							'fontSize' => $cprop( $scs, 'font-size' ), 'fontWeight' => $cprop( $scs, 'font-weight' ),
							'letterSpacing' => $cprop( $scs, 'letter-spacing' ),
							'borderW' => $cprop( $scs, 'border-top-width' ), 'borderColor' => $cprop( $scs, 'border-top-color' ),
						);
						break;
					}
				}
			}
			$out[] = $col;
		}
		return $out;
	}

	/**
	 * Extract an icon-card from a grid cell: an icon (material-symbol → FA class, or inline SVG),
	 * a heading, and the remaining prose (paragraph + any list, cleaned).
	 *
	 * @param DOMElement $cell
	 * @return array|null { icon, customIcon, title, titleTag, text, cls, iconLayout }
	 */
	private static function card_from_cell( $cell ) {
		// Heading.
		$heading = null; $htag = 'h3';
		foreach ( array( 'h3', 'h4', 'h5', 'h2', 'h6' ) as $h ) {
			$n = $cell->getElementsByTagName( $h )->item( 0 );
			if ( $n ) { $heading = $n; $htag = $h; break; }
		}
		if ( ! $heading ) { return null; } // no title → not an icon-card

		// Icon: first material-symbol span, else first <svg>.
		$icon = ''; $custom_icon = ''; $icon_box_cls = ''; $icon_box_cs = ''; $icon_cls = ''; $icon_chip_cls = '';
		foreach ( $cell->getElementsByTagName( 'span' ) as $sp ) {
			if ( strpos( self::cls( $sp ), 'material-symbols' ) !== false ) {
				$icon     = self::material_to_fa( trim( $sp->textContent ) );
				$icon_cls = self::cls( $sp ); // the icon's source classes (text-<token>…) → resolve its REST color in n_icon_box
				// The icon's WRAPPER is often a gray "image" placeholder box (a fill + a fixed height + rounded).
				// Capture it so the icon_box reproduces that box instead of showing a bare icon.
				$par = $sp->parentNode;
				if ( $par && XML_ELEMENT_NODE === $par->nodeType ) {
					$pcls = self::cls( $par );
					if ( strpos( $pcls, 'bg-' ) !== false && preg_match( '/(?:^|\s)(h-\d|min-h-|aspect-)/', $pcls ) ) {
						$icon_box_cls = $pcls;
						$icon_box_cs  = (string) $par->getAttribute( 'data-sc-cs' );
					}
				}
				break;
			}
		}
		// Native Lucide (data-lucide / lucide-<name> class / <iconify-icon icon="lucide:zap">) → library icon id.
		$lucide = '';
		if ( $icon === '' ) { $lucide = self::detect_lucide_in( $cell ); }
		if ( $icon === '' && $lucide === '' ) {
			$svg = $cell->getElementsByTagName( 'svg' )->item( 0 );
			if ( $svg && $cell->ownerDocument ) {
				$custom_icon = (string) $cell->ownerDocument->saveHTML( $svg );
				// Carry the icon's OWN color classes (e.g. `w-8 h-8 text-primary` / `text-secondary`) so
				// n_icon_box resolves the icon color from the source token instead of leaving it inherit-dark
				// — the FreshPaws feature chips are green / amber / green per card, not the card's body color.
				if ( '' === $icon_cls ) { $icon_cls = self::cls( $svg ); }
				// Capture the icon's CHIP wrapper — a filled, fixed-size container (`bg-*` + a w/h/aspect/pad
				// sizing step, e.g. `w-16 h-16 bg-white rounded-2xl`) — as iconChipCls, so n_icon_box reproduces
				// the badge/bg behind the icon (icon_badge shape + fill) rather than an unstyled bare icon.
				$sp = ( $svg->parentNode instanceof DOMElement ) ? $svg->parentNode : null;
				if ( $sp ) {
					$pcls = self::cls( $sp );
					if ( strpos( $pcls, 'bg-' ) !== false && preg_match( '/(?:^|\s)(?:w-\d|h-\d|min-h-|aspect-|p-\d)/', $pcls ) ) {
						$icon_chip_cls = $pcls;
					}
				}
			}
		}

		// Body = paragraphs + lists after the heading (cleaned of material-symbol noise + classes).
		$body = '';
		foreach ( $cell->getElementsByTagName( 'p' ) as $p ) { $body .= '<p>' . self::clean_inline_html( $p ) . '</p>'; }
		$ul = $cell->getElementsByTagName( 'ul' )->item( 0 );
		if ( $ul ) { $body .= self::clean_list_html( $ul ); }

		// A CTA link/button inside the card (e.g. "Explore TTS →"). The icon_box shortcode has no button
		// slot, so it's captured separately: a box card WITH a button is rendered as icon_box + button in
		// the column, and the box styling moves to the column's Inner Wrapper Class.
		$button = null;
		foreach ( $cell->getElementsByTagName( 'a' ) as $a ) {
			$label = self::text_no_icons( $a );
			if ( $label === '' ) { continue; }
			$bicon = '';
			foreach ( $a->getElementsByTagName( 'span' ) as $sp ) {
				if ( strpos( self::cls( $sp ), 'material-symbols' ) !== false ) { $bicon = self::material_to_fa( trim( $sp->textContent ) ); break; }
			}
			$href = $a->getAttribute( 'href' );
			$button = array( 'label' => $label, 'href' => $href !== '' ? $href : '#', 'icon' => $bicon, 'cls' => self::cls( $a ), 'cs' => $a->getAttribute( 'data-sc-cs' ) );
			break;
		}

		return array(
			'icon'       => $icon,
			'iconCls'    => $icon_cls,
			'customIcon' => $custom_icon,
			'lucide'     => $lucide, // 'lucide/<name>' when the card icon is a native Lucide glyph → n_icon_box library icon
			'title'      => self::text( $heading ),
			'titleTag'   => $htag,
			'text'       => $body,
			'button'     => $button, // a CTA inside the card (null when none) — see the column-build rule
			'iconBoxCls'  => $icon_box_cls, // the source's gray icon container classes (if any) → reproduced as a box
			'iconBoxCs'   => $icon_box_cs,
			'iconChipCls' => $icon_chip_cls, // the icon's filled chip wrapper (bg-* + sizing) → n_icon_box icon_badge/bg
			// Is the source card CENTERED? (a text-center class, or computed text-align:center.) Drives the
			// icon_box left/center alignment so it matches the source instead of the shortcode's centered default.
			'center'     => ( strpos( self::cls( $cell ), 'text-center' ) !== false ) || ( strpos( (string) $cell->getAttribute( 'data-sc-cs' ), 'text-align:center' ) !== false ),
			'cls'        => self::cls( $cell ),               // the card container's classes → CSS Class Mapper (box styling)
			'cs'         => $cell->getAttribute( 'data-sc-cs' ), // its RESOLVED computed style → styling for non-Tailwind sites
			'iconLayout' => 'top-title',
		);
	}

	/**
	 * A grid cell that is ONLY CTA buttons (no heading/prose) → an array of button descriptors
	 * { label, href, cls, cs, icon }, else null. Mirrors the capture service's buttonsOf() so the
	 * URL and file-upload paths agree — a button group maps to real button shortcodes (side-by-side
	 * via the mapper's .btn-row Inner Wrapper Class), not a frozen code_block.
	 *
	 * @param DOMElement $cell
	 * @return array[]|null
	 */
	private static function buttons_from_cell( $cell ) {
		foreach ( array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ) as $h ) {
			if ( $cell->getElementsByTagName( $h )->length > 0 ) { return null; } // a heading → it's a card, not a button group
		}
		$btns = array();
		foreach ( $cell->getElementsByTagName( '*' ) as $el ) {
			$tag = strtolower( $el->tagName );
			if ( $tag !== 'a' && $tag !== 'button' ) { continue; }
			if ( ! ( self::is_button( $el ) || self::cs_is_button( $el ) ) ) { continue; }
			if ( trim( self::text_no_icons( $el ) ) === '' ) { continue; }
			$btns[] = $el;
		}
		// Keep only the OUTERMOST matches (an <a> wrapping a button-like <span>).
		$outer = array();
		foreach ( $btns as $b ) {
			$nested = false;
			foreach ( $btns as $o ) {
				if ( $o === $b ) { continue; }
				for ( $p = $b->parentNode; $p !== null; $p = $p->parentNode ) { if ( $p === $o ) { $nested = true; break; } }
				if ( $nested ) { break; }
			}
			if ( ! $nested ) { $outer[] = $b; }
		}
		if ( ! $outer ) { return null; }
		// Require the cell be dominated by button labels (no substantial prose beside them).
		$prose  = strlen( trim( preg_replace( '/\s+/', ' ', $cell->textContent ) ) );
		$btnlen = 0;
		foreach ( $outer as $b ) { $btnlen += strlen( trim( self::text_no_icons( $b ) ) ); }
		if ( $prose > $btnlen + 24 ) { return null; }
		$out = array();
		foreach ( $outer as $b ) {
			$href  = strtolower( $b->tagName ) === 'a' ? (string) $b->getAttribute( 'href' ) : '#';
			$bicon = '';
			foreach ( $b->getElementsByTagName( 'span' ) as $sp ) {
				if ( strpos( self::cls( $sp ), 'material-symbols' ) !== false ) { $bicon = self::material_to_fa( trim( $sp->textContent ) ); break; }
			}
			$out[] = array(
				'label' => self::text_no_icons( $b ),
				'href'  => $href !== '' ? $href : '#',
				'cls'   => self::cls( $b ),
				'cs'    => (string) $b->getAttribute( 'data-sc-cs' ),
				'icon'  => $bicon,
			);
		}
		return $out;
	}

	/** Build a button block from a <button>/<a> CTA. */
	private static function button_block( $el, array $rules ) {
		$label = self::text_no_icons( $el );
		$href  = '#';
		if ( strtolower( $el->tagName ) === 'a' && $el->getAttribute( 'href' ) !== '' ) {
			$href = $el->getAttribute( 'href' );
		}
		// A material-symbol inside the button → an icon (mapped to Font Awesome so it renders).
		$icon = ''; $icon_pos = 'after';
		foreach ( $el->getElementsByTagName( 'span' ) as $sp ) {
			if ( strpos( self::cls( $sp ), 'material-symbols' ) !== false ) {
				$icon = self::material_to_fa( trim( $sp->textContent ) );
				// Icon before the label? (its node precedes the text)
				$icon_pos = self::icon_is_leading( $el, $sp ) ? 'before' : 'after';
				break;
			}
		}
		// Primary (solid) vs ghost — recorded as a brand class so page_css can carry the look later.
		$cls = ( strpos( self::cls( $el ), 'bg-primary' ) !== false || strpos( self::cls( $el ), 'bg-white' ) !== false ) ? 'btn-primary' : '';
		// The button's CONTAINER (a flex row of 2+ buttons): its flex styling is carried onto the button row.
		$grp_cls = ''; $grp_cs = '';
		$par = $el->parentNode;
		if ( $par && XML_ELEMENT_NODE === $par->nodeType && ( strpos( self::cls( $par ), 'flex' ) !== false ) ) {
			$nb = 0;
			foreach ( self::el_children( $par ) as $k ) { if ( self::is_button( $k ) || self::cs_is_button( $k ) ) { $nb++; } }
			if ( $nb >= 2 ) { $grp_cls = self::cls( $par ); $grp_cs = (string) $par->getAttribute( 'data-sc-cs' ); }
		}
		return array(
			't'      => 'button',
			'role'   => self::rule_role( $rules, $el, 'button' ),
			'label'  => $label !== '' ? $label : 'Button',
			'href'   => $href,
			'cls'    => $cls,
			'srcCls' => self::cls( $el ),                  // the button's full source classes → CSS Class Mapper (fill/text/radius)
			'srcCs'  => $el->getAttribute( 'data-sc-cs' ), // its RESOLVED computed style → styling for non-Tailwind sites
			'groupCls' => $grp_cls, // the source button-row container's flex classes
			'groupCs'  => $grp_cs,
			'icon'   => $icon,
			'iconPos'=> $icon_pos,
		);
	}

	/* ---------------------------------------------------------------------- *
	 * Menus
	 * ---------------------------------------------------------------------- */

	/**
	 * Build the menus.json payload from the page's <header> nav (→ primary) and <footer> nav
	 * (→ footer). Icon-only links are skipped; labels are whitespace-collapsed.
	 *
	 * @param string $html
	 * @return array{ menus: array[] }
	 */
	public static function extract_menus( $html ) {
		$dom = self::load_dom( (string) $html );
		$menus = array();
		if ( ! $dom ) { return array( 'menus' => $menus ); }

		$header = self::header_root( $dom );
		$primary = $header ? self::links_in( $header, true ) : array();
		// Drop CTA-ish trailing links (Sign In / Get Started) — keep the real nav anchors.
		if ( $primary ) {
			$menus[] = array( 'name' => 'Primary', 'location' => 'primary', 'items' => $primary );
		}

		$footer = $dom->getElementsByTagName( 'footer' )->item( 0 );
		$foot = $footer ? self::links_in( $footer ) : array();
		if ( $foot ) {
			$menus[] = array( 'name' => 'Footer', 'location' => 'footer', 'items' => $foot );
		}
		return array( 'menus' => $menus );
	}

	/**
	 * Flat list of {label,url} for the <nav> (or all anchors) inside $scope. When $drop_buttons is
	 * set (header nav), button-styled anchors are skipped — they're the CTA ("Get Started"), captured
	 * separately by detect_header(), not real nav links.
	 */
	private static function links_in( $scope, $drop_buttons = false ) {
		$nav = $scope->getElementsByTagName( 'nav' )->item( 0 );
		$host = $nav ? $nav : $scope;
		if ( $drop_buttons ) {
			// Prefer the nav's links GROUP (the densest <ul>/<div> of anchors), so a standalone
			// brand/logo link sitting outside it (e.g. Stitch's `<!-- Brand --><a>Auralis</a>`) is
			// excluded — the brand becomes the site's own logo, not a menu item.
			$group = self::densest_link_group( $host );
			if ( $group ) { $host = $group; }
		}
		$out = array();
		$seen = array();
		foreach ( $host->getElementsByTagName( 'a' ) as $a ) {
			if ( $drop_buttons && self::is_button( $a ) ) { continue; } // CTA / button-styled, not a nav link
			$label = trim( preg_replace( '/\s+/', ' ', $a->textContent ) );
			if ( $label === '' ) { continue; } // icon-only
			$url = $a->getAttribute( 'href' );
			if ( $url === '' ) { $url = '#'; }
			$key = strtolower( $label );
			if ( isset( $seen[ $key ] ) ) { continue; }
			$seen[ $key ] = true;
			$out[] = array( 'label' => $label, 'url' => $url );
		}
		return $out;
	}

	/** The descendant <ul>/<div> with the most DIRECT anchor children (≥2) — the nav's links group. */
	private static function densest_link_group( $host ) {
		$best = null; $best_n = 1;
		foreach ( array( 'ul', 'div' ) as $tag ) {
			foreach ( $host->getElementsByTagName( $tag ) as $el ) {
				$n = 0;
				foreach ( $el->childNodes as $ch ) {
					if ( $ch instanceof DOMElement && strtolower( $ch->nodeName ) === 'a' ) { $n++; }
				}
				if ( $n > $best_n ) { $best_n = $n; $best = $el; }
			}
		}
		return $best;
	}

	/* ---------------------------------------------------------------------- *
	 * Bundle assembly + import
	 * ---------------------------------------------------------------------- */

	/**
	 * Assemble a full convert-bundle from a Stitch input. Accepts BOTH export layouts:
	 *  - flat single-frame: a folder with `code.html` (+ `DESIGN.md`), OR a direct ['html'=>…] payload;
	 *  - multi-screen: a parent folder with one subfolder per screen (`<screen>/code.html`) + top-level
	 *    `<system>/DESIGN.md`. Each screen becomes one page in pages.json (the first is the front page).
	 *
	 * @param array $input { folder?:string, html?:string, design_md?:string, title?:string }
	 * @return array{ files: array<string,array>, mapping: array, tokens: array, screens:int, error:string }
	 */
	public static function build_bundle( array $input ) {
		$out = array( 'files' => array(), 'mapping' => array(), 'tokens' => array(), 'screens' => 0, 'error' => '' );

		$screens = array(); // each: { html, title, slug, front }
		$design_md = isset( $input['design_md'] ) ? (string) $input['design_md'] : '';

		if ( ! empty( $input['html'] ) ) {
			$screens[] = array( 'html' => (string) $input['html'], 'title' => (string) ( $input['title'] ?? 'Home' ), 'slug' => '', 'front' => true );
		} elseif ( ! empty( $input['folder'] ) && is_dir( $input['folder'] ) ) {
			list( $screens, $md2 ) = self::screens_from_folder( $input['folder'] );
			if ( $design_md === '' ) { $design_md = $md2; }
		}

		if ( ! $screens ) {
			$out['error'] = __( 'No Stitch code.html found to convert.', 'fw' );
			return $out;
		}

		// Tokens come from the FIRST screen (a Stitch project shares one design system) + DESIGN.md.
		$tokens = self::parse_tokens( $screens[0]['html'] );
		$tokens = self::merge_design_md( $tokens, $design_md );
		$out['tokens'] = $tokens;
		// The home screen's markup, for the optional AI companion (it refines the mapping + writes CSS
		// against the original design). Capped so a huge page doesn't bloat the AJAX payload.
		$out['html'] = mb_substr( (string) $screens[0]['html'], 0, 120000 );

		// Media + pages from across all screens (menus are carried inside the design-config and built
		// by the generated theme on activation, so they're not assembled here).
		$urls = array();
		$pages = array();
		$mapping_all = array( 'include_animations' => false, 'pages' => array() );

		// MIRROR mode (the "grab the source's real CSS" path): carry each screen's body VERBATIM and
		// reproduce its compiled Tailwind CSS offline, instead of decomposing into shortcodes. Pixel-
		// faithful, no AI, no capture service.
		$mirror = ! empty( $input['mirror'] );
		$dyn    = ! empty( $input['dynamic_chrome'] ); // faithful + EDITABLE chrome (raw-chrome swaps)
		foreach ( $screens as $sc ) {
			$urls = array_merge( $urls, self::scan_images( $sc['html'] ) );
			// Always DECOMPOSE the body into real page-builder elements (special_heading / text_block /
			// button / icon_box / columns / media_image), with code_block as the fallback for custom or
			// unmapped blocks — the converter's original design. In mirror mode the <header>/<footer> are
			// carried verbatim into the theme (mirror_design) and the reproduced Tailwind CSS keeps the
			// decomposed elements looking like the source.
			$map = self::html_to_mapping( $sc['html'], $sc['title'], $sc['slug'], $sc['front'] );
			$mapping_all['pages'] = array_merge( $mapping_all['pages'], $map['pages'] );
		}
		$urls = array_values( array_unique( array_filter( $urls ) ) );

		$out['mapping'] = $mapping_all;
		// Enable the box CSS Class Mapper: card/box columns get their border/bg/shadow/rounded compiled
		// into one clean `.box` class on the column's Inner Wrapper Class (populated during build_pages,
		// emitted into the child stylesheet below).
		if ( ( $mirror || $dyn ) && class_exists( 'FW_Site_Converter_Tailwind' ) && class_exists( 'FW_Site_Converter_Mapper' ) ) {
			$map_cfg = FW_Site_Converter_Tailwind::parse_config( $screens[0]['html'] );
			// Recover custom SEMANTIC colours (primary / secondary / foreground / accent / …) that a
			// React/shadcn/Tailwind-config source defines via CSS vars we can't see in view-source, from the
			// captured computed styles — the SAME enrichment mirror_design() does for the `.sc-tw` CSS. Without
			// this the mapper's config carries no `primary`/`secondary`, so a card icon's `text-primary` /
			// `text-secondary` (and any box `bg-primary`) resolves to nothing = an inherit-dark, colourless icon.
			if ( method_exists( 'FW_Site_Converter_Tailwind', 'extract_semantic_colors' ) ) {
				$map_sem = FW_Site_Converter_Tailwind::extract_semantic_colors( (string) $screens[0]['html'] );
				if ( $map_sem ) { $map_cfg['colors'] = ( isset( $map_cfg['colors'] ) && is_array( $map_cfg['colors'] ) ? $map_cfg['colors'] : array() ) + $map_sem; }
			}
			FW_Site_Converter_Mapper::set_style_config( $map_cfg );
			// Give the mapper the SAME Section Style presets that theme-settings.json carries, so a detected
			// full-bleed band fill can LINK to an existing preset (`variant` = slug) — the CTA green → "Alt"
			// — instead of only being hardcoded. Recomputed deterministically from the same source html.
			if ( method_exists( 'FW_Site_Converter_Mapper', 'set_section_presets' ) ) {
				$sec_presets = self::build_section_style_presets( (string) $screens[0]['html'] );
				FW_Site_Converter_Mapper::set_section_presets( isset( $sec_presets['section_style_presets'] ) ? $sec_presets['section_style_presets'] : array() );
			}
			// Give the mapper the SAME button_colors / button_sizes presets theme-settings.json carries, so a
			// converted BODY button can attach the matching color+size preset slug (the header CTA already
			// does this) instead of falling to the shortcode default. Recomputed from the same source html.
			if ( method_exists( 'FW_Site_Converter_Mapper', 'set_button_presets' ) ) {
				$btn_presets = self::build_button_presets( (string) $screens[0]['html'] );
				FW_Site_Converter_Mapper::set_button_presets(
					isset( $btn_presets['button_colors'] ) ? $btn_presets['button_colors'] : array(),
					isset( $btn_presets['button_sizes'] ) ? $btn_presets['button_sizes'] : array()
				);
			}
		}
		$pages = class_exists( 'FW_Site_Converter_Mapper' ) ? FW_Site_Converter_Mapper::build_pages( $mapping_all ) : array();

		// Assemble the bundle files (only non-empty ones).
		$files = array();
		$files['bundle.json'] = array( 'name' => 'Google Stitch import', 'source' => 'stitch', 'generated' => '' );
		if ( $urls )  { $files['media.json'] = array( 'urls' => $urls ); }
		// theme-design.json → the bundle's theme phase generates a CHILD THEME carrying the Stitch
		// palette/fonts/header+footer chrome (the plan's target), instead of dumping CSS on the active
		// theme. The design-config's header.menu / footer.menu are built into real WP menus by the
		// generated theme's activation bootstrap — so we DON'T also emit menus.json (that would create
		// duplicate Header/Primary menus).
		$files['theme-design.json'] = self::tokens_to_design_config( $tokens, $screens[0]['html'], $screens[0]['title'] );
		if ( $dyn ) {
			$files['theme-design.json'] = self::raw_chrome_design( $files['theme-design.json'], $screens[0]['html'] );
		} elseif ( $mirror ) {
			$files['theme-design.json'] = self::mirror_design( $files['theme-design.json'], $screens[0]['html'] );
		}
		// Append the box-container semantic rules to the child theme stylesheet (both faithful modes).
		if ( ( $mirror || $dyn ) && class_exists( 'FW_Site_Converter_Mapper' ) ) {
			$boxcss = FW_Site_Converter_Mapper::registered_css();
			if ( $boxcss !== '' ) {
				$cc = isset( $files['theme-design.json']['custom_css'] ) ? (string) $files['theme-design.json']['custom_css'] : '';
				// Sentinel-wrap the registered block: the corrections/build handler re-derives this same
				// CSS after re-running build_pages() and folds it in again — without a marker it appended a
				// SECOND copy, emitting every #section-N rule twice into style.css. The handler strips this
				// region by sentinel before re-appending, so the fold stays idempotent.
				$files['theme-design.json']['custom_css'] = trim( $cc . "\n\n/* SC:REGCSS:START */\n" . $boxcss . "\n/* SC:REGCSS:END */" );
			}
		}
		if ( $pages ) { $files['pages.json'] = array( 'pages' => $pages ); }

		// CHROME → parent-theme Theme Settings (playbook: chrome = theme, not page content). Emit the
		// source header/footer as native Header/Footer Theme-Settings values so the converted site runs
		// on a NEAR-EMPTY child theme instead of a baked header.php/footer.php. The flag tells the
		// theme-generator to skip baking chrome (Theme Settings drives it), avoiding a double header.
		// NOT for the raw-chrome dynamic mirror ($dyn): that path bakes the source's OWN header.php /
		// footer.php verbatim (4-column footer, source-styled nav, re-hydrated interactions). Deferring its
		// chrome to the parent's Theme Settings collapsed the footer to a generic 2-column parent render and
		// dropped the source styling (regression). Theme-Settings chrome is for the near-empty conversion only.
		// The deterministic native mapping (header logo/menu/CTA + footer grid + copyright as Theme Settings).
		// It's now the BASE path for BOTH modes: the $dyn raw-chrome bake used to be preferred only because
		// the earlier footer mapping collapsed a 4-col footer to 2 (fixed above — detect_footer_columns now
		// keeps text-only columns, and the copyright bar splits out legal links). We take the native path
		// whenever the mapping is FAITHFUL; otherwise (a messy/undetected structure) $dyn falls back to baking
		// the source's own header.php/footer.php verbatim so chrome is never broken. AI refinement (when on)
		// layers over this same theme-settings.json in the build handler.
		$chrome = self::tokens_to_theme_settings_chrome( $tokens, $screens[0]['html'], $screens[0]['title'] );
		$native_ok = self::chrome_mapping_faithful( $chrome );
		if ( ! empty( $chrome['values'] ) && ( ! $dyn || $native_ok ) ) {
			$files['theme-settings.json'] = $chrome;
			if ( isset( $files['theme-design.json'] ) && is_array( $files['theme-design.json'] ) ) {
				$files['theme-design.json']['chrome_via_settings'] = true;
			}
		}

		$out['files']   = $files;
		$out['screens'] = count( $screens );
		return $out;
	}

	/* ---------------------------------------------------------------------- *
	 * Faithful mirror (carry source markup + reproduce its Tailwind CSS)
	 * ---------------------------------------------------------------------- */

	/**
	 * Split the source body into verbatim parts: the <header> and <footer> (→ the child theme's
	 * header.php / footer.php, STATIC, exactly as the source) and the body content blocks in between
	 * (→ one page-builder section each, so the homepage is laid out as editable builder sections).
	 * Scripts are dropped (the reproduced CSS replaces the Tailwind runtime). Nothing is lost: every
	 * top-level content child of <main> (or <body>) becomes a block, whether a <section> or a loose div.
	 *
	 * @return array{header:string,footer:string,sections:string[]}
	 */
	/** The descendant container (div/ul/section) with the most DIRECT element children (>=2) — e.g. a
	 *  footer's column ROW. */
	private static function densest_child_group( $host ) {
		$best = null; $best_n = 1;
		foreach ( array( 'div', 'ul', 'section' ) as $tag ) {
			foreach ( $host->getElementsByTagName( $tag ) as $el ) {
				$n = 0;
				foreach ( $el->childNodes as $ch ) { if ( $ch instanceof DOMElement ) { $n++; } }
				if ( $n > $best_n ) { $best_n = $n; $best = $el; }
			}
		}
		return $best;
	}

	/** Inner HTML of an element (its children serialized). */
	private static function inner_html( $el ) {
		$doc = $el->ownerDocument; $html = '';
		foreach ( $el->childNodes as $ch ) { $html .= $doc->saveHTML( $ch ); }
		return $html;
	}

	/** The tightest footer element holding the copyright line (a year / "copyright" / the (c) glyph),
	 *  preferring the wrapper with the FEWEST child elements so social icons etc. stay out of it. */
	private static function footer_copyright_el( $footer ) {
		$best = null; $best_kids = PHP_INT_MAX; $copy = chr( 0xC2 ) . chr( 0xA9 );
		foreach ( array( 'div', 'p', 'span' ) as $tag ) {
			foreach ( $footer->getElementsByTagName( $tag ) as $el ) {
				$t = trim( $el->textContent );
				if ( $t === '' || mb_strlen( $t ) > 200 ) { continue; }
				if ( ! preg_match( '/(?:19|20)\d{2}/', $t ) && stripos( $t, 'copyright' ) === false && strpos( $t, $copy ) === false ) { continue; }
				$kids = $el->getElementsByTagName( '*' )->length;
				if ( $kids < $best_kids ) { $best_kids = $kids; $best = $el; }
			}
		}
		return $best;
	}

	/** Faithful-DYNAMIC chrome: keep the source header/footer markup but inject the swap markers
	 *  (<!--SC_NAV-->, <!--SC_FCOL_i-->, <!--SC_FCOPY-->) + extract the nav tree + footer columns, so the
	 *  generator renders wp_nav_menu / the_custom_logo / footer widgets over the EXACT source look. This
	 *  is the PHP twin of the capture service's chrome extraction (keeps the two paths in sync). */
	/** Derive the .sc-menu look (color / size / weight / gap) from a source nav's link classes, so the
	 *  injected wp_nav_menu matches the source instead of falling back to bare bulleted links. */
	private static function nav_style_from( $group, $html ) {
		$ns = array();
		$a  = null;
		foreach ( $group->getElementsByTagName( 'a' ) as $el ) { if ( trim( $el->textContent ) !== '' ) { $a = $el; break; } }
		if ( $a && class_exists( 'FW_Site_Converter_Tailwind' ) ) {
			$cfg  = FW_Site_Converter_Tailwind::parse_config( (string) $html );
			$cm   = FW_Site_Converter_Tailwind::compile_class_set( self::cls( $a ), $cfg );
			$base = ( is_array( $cm ) && isset( $cm['base'] ) && is_array( $cm['base'] ) ) ? $cm['base'] : array();
			$map  = array( 'color' => 'color', 'font-size' => 'fontSize', 'font-weight' => 'fontWeight', 'letter-spacing' => 'letterSpacing', 'text-transform' => 'textTransform', 'font-family' => 'fontFamily' );
			foreach ( $map as $prop => $key ) { if ( ! empty( $base[ $prop ] ) ) { $ns[ $key ] = $base[ $prop ]; } }
		}
		$gcls = self::cls( $group );
		if ( preg_match( '/(?:^|\s)(?:gap|space)-x?-(\d+(?:\.\d+)?)/', ' ' . $gcls . ' ', $m ) ) { $ns['gap'] = ( (float) $m[1] * 0.25 ) . 'rem'; }
		if ( empty( $ns['gap'] ) ) { $ns['gap'] = '2rem'; } // ensure non-empty so sc_menu_css emits the flex layout
		return $ns;
	}

	private static function raw_chrome_split( $html ) {
		$res = array( 'header_html' => '', 'footer_html' => '', 'nav_tree' => array(), 'nav_style' => array(), 'footer_cols' => array(), 'footer_copyright' => '' );
		$dom = self::load_dom( (string) $html );
		if ( ! $dom ) { return $res; }
		$body = $dom->getElementsByTagName( 'body' )->item( 0 );
		if ( ! $body ) { return $res; }
		$drop = array();
		foreach ( $body->getElementsByTagName( 'script' ) as $sc ) { $drop[] = $sc; }
		foreach ( $drop as $sc ) { if ( $sc->parentNode ) { $sc->parentNode->removeChild( $sc ); } }

		// HEADER: the nav's link group -> <!--SC_NAV-->; the nav tree feeds the WP-menu bootstrap.
		$header = self::header_root( $dom );
		if ( $header ) {
			$nav   = $header->getElementsByTagName( 'nav' )->item( 0 );
			$scope = $nav ? $nav : $header;
			$group = self::densest_link_group( $scope );
			if ( $group ) {
				$res['nav_style'] = self::nav_style_from( $group, (string) $html ); // capture the look BEFORE removing the group
				if ( $group->parentNode ) { $group->parentNode->replaceChild( $dom->createComment( 'SC_NAV' ), $group ); }
			}
			// LOGO: with no <img> brand, make the source's TEXT brand link editable (Customizer logo /
			// Site Title) by marking its inner content -> header_part swaps it. Runs AFTER the nav group is
			// removed so a nav link is never mistaken for the brand.
			if ( 0 === $header->getElementsByTagName( 'img' )->length ) {
				foreach ( $header->getElementsByTagName( 'a' ) as $ba ) {
					if ( trim( $ba->textContent ) === '' || self::is_button( $ba ) ) { continue; }
					while ( $ba->firstChild ) { $ba->removeChild( $ba->firstChild ); }
					$ba->appendChild( $dom->createComment( 'SC_LOGO' ) );
					break;
				}
			}
			$res['header_html'] = self::mirror_minify( $dom->saveHTML( $header ) );
			$nav_items = array();
			foreach ( self::design_menu( (string) $html, 'primary' ) as $it ) {
				$nav_items[] = array( 'label' => $it['label'], 'url' => $it['url'], 'href' => $it['url'], 'children' => array() );
			}
			$res['nav_tree'] = $nav_items;
		}

		// FOOTER: each column of the densest row -> <!--SC_FCOL_i-->; the copyright -> <!--SC_FCOPY-->.
		$footer = $dom->getElementsByTagName( 'footer' )->item( 0 );
		if ( $footer ) {
			$copy      = self::footer_copyright_el( $footer ); // grab BEFORE column marking
			$copy_html = $copy ? self::mirror_minify( self::inner_html( $copy ) ) : '';
			$row  = self::densest_child_group( $footer );
			$cols = array();
			if ( $row ) {
				$children = array();
				foreach ( $row->childNodes as $ch ) { if ( $ch instanceof DOMElement ) { $children[] = $ch; } }
				foreach ( $children as $i => $col ) {
					$cols[] = self::mirror_minify( self::inner_html( $col ) );
					if ( $col->parentNode ) { $col->parentNode->replaceChild( $dom->createComment( 'SC_FCOL_' . $i ), $col ); }
				}
			}
			if ( $copy && $copy->parentNode ) {
				$res['footer_copyright'] = $copy_html;
				$copy->parentNode->replaceChild( $dom->createComment( 'SC_FCOPY' ), $copy );
			}
			$res['footer_cols'] = $cols;
			$res['footer_html'] = self::mirror_minify( $dom->saveHTML( $footer ) );
		}
		return $res;
	}

	/** Like mirror_design (reproduced offline CSS + fonts) but the header/footer are DYNAMIC + faithful
	 *  (raw-chrome swaps) instead of static verbatim. */
	private static function raw_chrome_design( array $design, $html ) {
		$design = self::mirror_design( $design, $html );
		unset( $design['mirror'], $design['mirror_header_html'], $design['mirror_footer_html'] );
		$design['raw_chrome'] = self::raw_chrome_split( $html );
		return $design;
	}

	private static function mirror_split( $html ) {
		$res = array( 'header' => '', 'footer' => '', 'sections' => array() );
		$dom = self::load_dom( (string) $html );
		if ( ! $dom ) { return $res; }
		$body = $dom->getElementsByTagName( 'body' )->item( 0 );
		if ( ! $body ) { return $res; }

		// Drop runtime scripts.
		$drop = array();
		foreach ( $body->getElementsByTagName( 'script' ) as $s ) { $drop[] = $s; }
		foreach ( $drop as $s ) { if ( $s->parentNode ) { $s->parentNode->removeChild( $s ); } }

		// Capture + REMOVE the chrome so it can't also land in a body block (no duplication, no loss).
		$header = self::header_root( $dom );
		if ( $header ) {
			$res['header'] = self::mirror_minify( $dom->saveHTML( $header ) );
			if ( $header->parentNode ) { $header->parentNode->removeChild( $header ); }
		}
		$footer = $dom->getElementsByTagName( 'footer' )->item( 0 );
		if ( $footer ) {
			$res['footer'] = self::mirror_minify( $dom->saveHTML( $footer ) );
			if ( $footer->parentNode ) { $footer->parentNode->removeChild( $footer ); }
		}

		// Content scope = <main> if present, else <body>. Each top-level element child → one block.
		$main = null;
		foreach ( $body->getElementsByTagName( 'main' ) as $m ) { $main = $m; break; }
		$scope = $main ? $main : $body;
		foreach ( $scope->childNodes as $ch ) {
			if ( $ch->nodeType !== XML_ELEMENT_NODE ) { continue; }
			if ( strtolower( $ch->tagName ) === 'script' ) { continue; }
			$piece = self::mirror_minify( $dom->saveHTML( $ch ) );
			if ( $piece !== '' ) { $res['sections'][] = $piece; }
		}
		return $res;
	}

	/**
	 * Collapse the insignificant whitespace BETWEEN tags so WordPress's wpautop can't turn the source's
	 * pretty-printed newlines into stray <br>/<p> (which, inside a CSS grid/flex row, become extra grid
	 * items and shatter the layout — the #1 cause of a "messed up" mirror). Text inside elements is
	 * preserved; only whitespace-only gaps between tags are removed. <pre>/<textarea> are protected.
	 */
	private static function mirror_minify( $html ) {
		$html = (string) $html;
		// Protect <pre>/<textarea> content from collapsing.
		$keep = array();
		$html = preg_replace_callback( '#<(pre|textarea)\b[^>]*>.*?</\1>#is', function ( $m ) use ( &$keep ) {
			$k = '%%SCKEEP' . count( $keep ) . '%%'; $keep[ $k ] = $m[0]; return $k;
		}, $html );
		$html = preg_replace( '/>\s+</', '><', $html );          // drop whitespace-only gaps between tags
		$html = str_replace( array( "\r\n", "\r", "\n", "\t" ), ' ', $html ); // any remaining newlines → space
		$html = preg_replace( '/ {2,}/', ' ', $html );           // collapse runs of spaces
		if ( $keep ) { $html = strtr( $html, $keep ); }
		return self::strip_cs( trim( $html ) );
	}

	/**
	 * Faithful-split mapping: the homepage body becomes ONE page-builder section per source content
	 * block (each carried verbatim in a code_block, wrapped in `.sc-tw` so the reproduced CSS styles it).
	 * The <header>/<footer> are handled separately (mirror_design → the theme's header.php/footer.php).
	 */
	private static function mirror_mapping( $html, $title, $slug, $front ) {
		$split    = self::mirror_split( $html );
		$sections = array();
		foreach ( $split['sections'] as $i => $shtml ) {
			$sections[] = array(
				'css_id' => 'mirror-' . ( $i + 1 ),
				'omit'   => false,
				'blocks' => array(
					array( 't' => 'html', 'role' => 'code', 'html' => '<div class="sc-tw">' . $shtml . '</div>' ),
				),
			);
		}
		if ( ! $sections ) { // nothing recognized — carry the whole body as one block
			$sections[] = array( 'css_id' => 'mirror', 'omit' => false, 'blocks' => array(
				array( 't' => 'html', 'role' => 'code', 'html' => '<div class="sc-tw">' . self::strip_cs( (string) $html ) . '</div>' ),
			) );
		}
		return array( 'pages' => array( array(
			'title'      => $title,
			'slug'       => $slug,
			'front_page' => (bool) $front,
			'sections'   => $sections,
		) ) );
	}

	/** Swap the design CSS for the reproduced Tailwind CSS + the source's fonts/inline style; flag the theme. */
	private static function mirror_design( array $design, $html ) {
		$tw = '';
		$base_font = 'Inter';
		if ( class_exists( 'FW_Site_Converter_Tailwind' ) ) {
			$cfg = FW_Site_Converter_Tailwind::parse_config( $html );
			// Custom semantic colours (foreground, background, muted, card, accent, border, …) that
			// React/shadcn sources define via a Tailwind config / CSS vars we can't see in view-source.
			// Recover them from the captured computed styles so `.bg-foreground` / `.text-muted` / etc.
			// actually emit (else e.g. a raw-chrome footer's `bg-foreground` stays undefined = white).
			// Real config colours (if any) win; the computed values only fill the gaps.
			if ( method_exists( 'FW_Site_Converter_Tailwind', 'extract_semantic_colors' ) ) {
				$computed = FW_Site_Converter_Tailwind::extract_semantic_colors( $html );
				if ( $computed ) { $cfg['colors'] = $cfg['colors'] + $computed; }
			}
			$tw  = FW_Site_Converter_Tailwind::compile( $html, $cfg, '.sc-tw' );
			if ( ! empty( $cfg['fontFamily']['body'] ) ) { $base_font = $cfg['fontFamily']['body']; }
			elseif ( ! empty( $cfg['fontFamily']['sans'] ) ) { $base_font = $cfg['fontFamily']['sans']; }
		}
		// Base font on the wrapper so any element without its own font class still reads right.
		$base = ".sc-tw{font-family:'" . trim( $base_font, "'\"" ) . "',system-ui,-apple-system,sans-serif;}\n";
		$tw   = $base . $tw;
		$design['font_links'] = self::mirror_font_links( $html ); // loaded via <link> in <head> (NOT @import —
		// an @import-loaded icon font renders its ligatures too late, so the icon NAME stays as text).
		$inline = self::mirror_inline_css( $html );
		// Google's css2 ships only the @font-face for Material Symbols, not the helper class that turns
		// the icon NAME text into a glyph (font-family + the `liga` feature). Add it so icons render.
		$ms = ".sc-tw .material-symbols-outlined{font-family:'Material Symbols Outlined';font-weight:normal;"
			. "font-style:normal;line-height:1;letter-spacing:normal;text-transform:none;display:inline-block;"
			. "white-space:nowrap;word-wrap:normal;direction:ltr;-webkit-font-feature-settings:'liga';font-feature-settings:'liga';-webkit-font-smoothing:antialiased;}\n";
		// A source sticky/fixed header sits at top:0 — nudge it below the WP admin bar for logged-in users
		// (the public site is unaffected; the admin bar isn't rendered there).
		$adminbar = ".admin-bar .sc-tw nav.fixed,.admin-bar .sc-tw nav.sticky,.admin-bar .sc-tw header.fixed,.admin-bar .sc-tw header.sticky{top:32px !important;}\n"
			. "@media screen and (max-width:782px){.admin-bar .sc-tw nav.fixed,.admin-bar .sc-tw nav.sticky,.admin-bar .sc-tw header.fixed,.admin-bar .sc-tw header.sticky{top:46px !important;}}\n";
		// Each body block sits in a page-builder section whose .fw-container caps content at ~1140px and
		// adds gutters. The source sections carry their OWN width (max-w-[…] mx-auto) + padding (px-…), so
		// neutralize the builder container/row/column for the mirror — let the source control its width.
		$fullwidth = ".sc-mirror{padding-top:0 !important;padding-bottom:0 !important;}\n"
			. ".sc-mirror .fw-container{max-width:none !important;width:100% !important;padding-left:0 !important;padding-right:0 !important;}\n"
			. ".sc-mirror .fw-row{margin-left:0 !important;margin-right:0 !important;}\n"
			. ".sc-mirror .fw-row > .fw-col-12{padding-left:0 !important;padding-right:0 !important;}\n";
		$adminbar .= $fullwidth;
		$logo_css = ".sc-tw .custom-logo{max-height:2.4rem;width:auto;height:auto;display:inline-block;vertical-align:middle;}
";
		$design['custom_css'] = trim( $inline . "\n" . $ms . $logo_css . $adminbar . "/* ---- reproduced Tailwind CSS (offline) ---- */\n" . $tw );
		$design['mirror']     = true;
		// The source's <header>/<footer> verbatim → the theme's header.php/footer.php (STATIC, exact).
		// The body sections are page content (mirror_mapping); the chrome is theme files.
		$split = self::mirror_split( $html );
		$design['mirror_header_html'] = $split['header'];
		$design['mirror_footer_html'] = $split['footer'];
		return $design;
	}

	/** The source's Google-Fonts URLs (deduped) — loaded via <link> in <head> so icon ligatures render. */
	private static function mirror_font_links( $html ) {
		$out = array(); $seen = array();
		if ( preg_match_all( '/<link[^>]+href="(https:\/\/fonts\.googleapis\.com\/[^"]+)"/i', (string) $html, $m ) ) {
			foreach ( $m[1] as $href ) {
				$href = html_entity_decode( $href );
				// An icon font on display:swap flashes its ligature NAME as text and can stay stuck on it;
				// force display:block so it's hidden until the glyph is ready.
				if ( stripos( $href, 'Material+Symbols' ) !== false || stripos( $href, 'Material+Icons' ) !== false ) {
					$href = preg_replace( '/([?&])display=swap/', '$1display=block', $href );
					if ( strpos( $href, 'display=' ) === false ) { $href .= '&display=block'; }
				}
				if ( isset( $seen[ $href ] ) ) { continue; }
				$seen[ $href ] = true;
				$out[] = $href;
			}
		}
		return $out;
	}

	/** The source's own inline <style> (font-smoothing, material-symbol settings) — scoped to `.sc-tw`. */
	private static function mirror_inline_css( $html ) {
		if ( ! preg_match_all( '/<style[^>]*>(.*?)<\/style>/s', (string) $html, $m ) ) { return ''; }
		$css = implode( "\n", $m[1] );
		return (string) preg_replace_callback( '/([^{}@]+)\{/', function ( $mm ) {
			$sels = array_map( function ( $s ) {
				$s = trim( $s );
				if ( $s === '' ) { return ''; }
				return $s === 'body' ? '.sc-tw' : '.sc-tw ' . $s;
			}, explode( ',', $mm[1] ) );
			return implode( ',', array_filter( $sels ) ) . '{';
		}, $css );
	}

	/**
	 * Import a built bundle straight into WordPress (Tier 1 — no AI): write the files to a temp dir
	 * and run them through the existing bundle orchestrator (media → theme-settings → pages → menus).
	 *
	 * @param array $bundle build_bundle() result
	 * @return array import result (FW_Site_Converter_Bundle::import_dir shape) with `error` on failure
	 */
	public static function import_bundle( array $bundle ) {
		if ( ! empty( $bundle['error'] ) ) { return array( 'error' => $bundle['error'], 'sections' => array() ); }
		if ( empty( $bundle['files'] ) || ! class_exists( 'FW_Site_Converter_Bundle' ) ) {
			return array( 'error' => __( 'Nothing to import from the Stitch screen.', 'fw' ), 'sections' => array() );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		if ( ! WP_Filesystem() ) { return array( 'error' => __( 'Could not access the filesystem.', 'fw' ), 'sections' => array() ); }

		$tmp = trailingslashit( get_temp_dir() ) . 'fw-sc-stitch-' . wp_generate_password( 12, false );
		if ( ! wp_mkdir_p( $tmp ) ) { return array( 'error' => __( 'Could not create a temp folder.', 'fw' ), 'sections' => array() ); }

		foreach ( $bundle['files'] as $name => $data ) {
			file_put_contents( trailingslashit( $tmp ) . $name, wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ); // phpcs:ignore
		}
		$result = FW_Site_Converter_Bundle::import_dir( $tmp );

		global $wp_filesystem;
		if ( $wp_filesystem ) { $wp_filesystem->delete( $tmp, true ); }
		return $result;
	}

	/**
	 * Stream the built bundle as a downloadable `.zip` (Tier 2 — for refining pages.json with Claude,
	 * then re-uploading through Convert Bundle). Writes a temp zip and returns its path/filename.
	 *
	 * @param array  $bundle build_bundle() result
	 * @param string $name   base filename (no extension)
	 * @return array{ path:string, filename:string, error:string }
	 */
	public static function build_zip( array $bundle, $name = 'stitch-bundle' ) {
		$out = array( 'path' => '', 'filename' => sanitize_file_name( $name ) . '.zip', 'error' => '' );
		if ( empty( $bundle['files'] ) ) { $out['error'] = __( 'Nothing to package.', 'fw' ); return $out; }
		if ( ! class_exists( 'ZipArchive' ) ) { $out['error'] = __( 'ZipArchive is not available on this server.', 'fw' ); return $out; }

		$path = trailingslashit( get_temp_dir() ) . 'fw-sc-stitch-' . wp_generate_password( 8, false ) . '.zip';
		$zip  = new ZipArchive();
		if ( $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) !== true ) {
			$out['error'] = __( 'Could not create the zip.', 'fw' );
			return $out;
		}
		foreach ( $bundle['files'] as $fname => $data ) {
			$zip->addFromString( $fname, wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		}
		$zip->close();
		$out['path'] = $path;
		return $out;
	}

	/* ---------------------------------------------------------------------- *
	 * Self-learning (LOCAL, privacy-safe — no telemetry)
	 * ---------------------------------------------------------------------- */

	/** Local learned rules: `signature => role`. Stored only in this install's wp_options. */
	public static function rules_get() {
		$r = get_option( self::RULES_OPTION, array() );
		$r = is_array( $r ) ? $r : array();
		// One-time migration: an earlier build kept the review-editor's corrections in a SEPARATE option
		// ('fw_site_converter_map_rules') that the converter never read — so those corrections were silently
		// ignored. Fold them into THIS canonical store (used by decompose + AI distillation) so they finally
		// take effect, then drop the legacy option.
		$legacy = get_option( 'fw_site_converter_map_rules', null );
		if ( is_array( $legacy ) && $legacy ) {
			$r = array_merge( $legacy, $r ); // the canonical store wins on a conflicting signature
			update_option( self::RULES_OPTION, $r, false );
			delete_option( 'fw_site_converter_map_rules' );
		}
		return $r;
	}

	/** Persist the local rules map (never transmitted). */
	public static function rules_put( array $rules ) {
		update_option( self::RULES_OPTION, $rules, false );
	}

	/** Export the local rules as a JSON string (so the maintainer can fold them into a release). */
	public static function rules_export() {
		return wp_json_encode( self::rules_get(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
	}

	/**
	 * Merge an exported rules map back into the local store (import from another install, or a curated set
	 * the maintainer ships). Accepts a bare `{signature: role}` map. Returns the number of NEW/changed rules.
	 */
	public static function rules_import( $map ) {
		if ( is_string( $map ) ) { $map = json_decode( $map, true ); }
		if ( ! is_array( $map ) ) { return 0; }
		$rules = self::rules_get();
		$n     = 0;
		foreach ( $map as $sig => $role ) {
			if ( ! is_string( $sig ) || ! is_string( $role ) || '' === $sig || '' === $role ) { continue; }
			if ( ! isset( $rules[ $sig ] ) || $rules[ $sig ] !== $role ) { $rules[ $sig ] = $role; $n++; }
		}
		if ( $n ) { self::rules_put( $rules ); }
		return $n;
	}

	/** How many element recognizers are registered (proves the recognizer registry loaded). */
	public static function recognizer_count() {
		return count( self::recognizers() );
	}

	/**
	 * Server-side self-test of the deterministic engine: convert a built-in sample and report which
	 * capabilities are ACTIVE in the currently-loaded code. Lets a diagnostic tell "the engine is fine, your
	 * browser/opcache is stale" apart from "the engine is genuinely broken" — no browser involved.
	 */
	public static function self_test() {
		$html = '<!DOCTYPE html><html><head>'
			. "<script>tailwind.config = { theme: { extend: { colors: { primary: '#000000', 'on-primary': '#ffffff', surface: '#ffffff', 'outline-variant': '#cccccc' } } } }</script>"
			. '</head><body><section class="max-w-[1100px] mx-auto px-6 py-16 text-center">'
			. '<h1 class="text-4xl">Self Test</h1>'
			. '<p class="max-w-[620px] mx-auto">Diagnostic sample.</p>'
			. '<a href="#" class="bg-primary text-on-primary px-8 py-4 rounded-full">Primary</a>'
			. '<a href="#" class="bg-transparent border border-outline-variant px-8 py-4 rounded-full">Outline</a>'
			. '<div class="grid grid-cols-3 gap-6">'
			. '<div class="bg-surface border rounded-2xl p-8"><h3>One</h3><p>a</p></div>'
			. '<div class="bg-surface border rounded-2xl p-8"><h3>Two</h3><p>b</p></div>'
			. '<div class="bg-surface border rounded-2xl p-8"><h3>Three</h3><p>c</p></div>'
			. '</div></section></body></html>';
		$bundle = self::build_bundle( array( 'html' => $html, 'title' => 'Self Test', 'mirror' => true ) );
		$secs   = isset( $bundle['mapping']['pages'][0]['sections'] ) ? $bundle['mapping']['pages'][0]['sections'] : array();
		$btns   = 0; $rows = 0;
		foreach ( $secs as $s ) {
			foreach ( ( isset( $s['blocks'] ) ? $s['blocks'] : array() ) as $b ) {
				if ( isset( $b['t'] ) && 'button' === $b['t'] ) { $btns++; }
				if ( isset( $b['role'] ) && 'columns' === $b['role'] ) { $rows++; }
			}
		}
		$css = class_exists( 'FW_Site_Converter_Mapper' ) ? FW_Site_Converter_Mapper::registered_css() : '';
		return array(
			'sections'    => count( $secs ),
			'buttons'     => $btns,                                   // expect 2 → recognizers working
			'card_rows'   => $rows,                                   // expect 1 → card-grid recognizer working
			'sc_btn_css'  => ( false !== strpos( $css, 'sc-btn-' ) ), // button-styling mapper present
			'box_css'     => ( false !== strpos( $css, '.box' ) ),    // box mapper present
			'recognizers' => self::recognizer_count(),
			'builders'    => class_exists( 'FW_Site_Converter_Mapper' ) ? FW_Site_Converter_Mapper::builder_count() : 0,
			'rules'       => count( self::rules_get() ),
		);
	}

	/**
	 * A learned rule wins over the built-in default: look the element's signature up in the local
	 * rules and return the stored role if present, else the deterministic default.
	 */
	private static function rule_role( array $rules, $el, $default ) {
		$sig = self::el_signature( $el );
		if ( $sig !== '' && isset( $rules[ $sig ] ) && is_string( $rules[ $sig ] ) ) {
			return $rules[ $sig ];
		}
		return $default;
	}

	/**
	 * Distil a Claude-authored pages.json against the deterministic draft and record the deltas as
	 * local rules — so the NEXT no-AI run benefits from how Claude mapped this screen. Compares the
	 * two trees section-by-section / leaf-by-leaf and stores `signature => role` where Claude differs.
	 * Nothing is sent anywhere; the maintainer later distils accumulated rules into a code release.
	 *
	 * @param array $draft  deterministic build_bundle()['files']['pages.json'] (or its 'pages')
	 * @param array $claude Claude's refined pages.json (or its 'pages')
	 * @return int rules added/updated
	 */
	public static function distill_from_ai( array $draft, array $claude ) {
		$d = self::pages_leaf_shortcodes( isset( $draft['pages'] ) ? $draft['pages'] : $draft );
		$c = self::pages_leaf_shortcodes( isset( $claude['pages'] ) ? $claude['pages'] : $claude );
		$rules = self::rules_get();
		$n = 0;
		// Map differing leaf shortcodes positionally; record the role Claude chose keyed by a stable
		// content signature (text-prefix) so a future deterministic run can prefer it.
		$len = min( count( $d ), count( $c ) );
		for ( $i = 0; $i < $len; $i++ ) {
			if ( $d[ $i ]['shortcode'] === $c[ $i ]['shortcode'] ) { continue; }
			$sig = 'sc:' . $c[ $i ]['sig'];
			$role = self::shortcode_to_role( $c[ $i ]['shortcode'] );
			if ( $role !== '' && ( ! isset( $rules[ $sig ] ) || $rules[ $sig ] !== $role ) ) {
				$rules[ $sig ] = $role;
				$n++;
			}
		}
		if ( $n ) { self::rules_put( $rules ); }
		return $n;
	}

	/** Flatten a pages tree to an ordered list of leaf shortcodes with a short content signature. */
	private static function pages_leaf_shortcodes( $pages ) {
		$out = array();
		$walk = function ( $items ) use ( &$walk, &$out ) {
			foreach ( (array) $items as $it ) {
				if ( ! is_array( $it ) ) { continue; }
				if ( ( $it['type'] ?? '' ) === 'simple' && ! empty( $it['shortcode'] ) ) {
					$txt = '';
					foreach ( array( 'title', 'text', 'label', 'overline' ) as $k ) {
						if ( ! empty( $it['atts'][ $k ] ) && is_string( $it['atts'][ $k ] ) ) { $txt = $it['atts'][ $k ]; break; }
					}
					$out[] = array( 'shortcode' => (string) $it['shortcode'], 'sig' => substr( md5( wp_strip_all_tags( $txt ) ), 0, 12 ) );
				}
				if ( ! empty( $it['_items'] ) ) { $walk( $it['_items'] ); }
			}
		};
		foreach ( (array) $pages as $pg ) {
			$builder = isset( $pg['builder'] ) ? $pg['builder'] : ( isset( $pg['json'] ) ? json_decode( $pg['json'], true ) : array() );
			$walk( $builder );
		}
		return $out;
	}

	/** Map a shortcode tag back to an editor role (for the rules store). */
	private static function shortcode_to_role( $tag ) {
		$map = array( 'special_heading' => 'title', 'text_block' => 'text', 'button' => 'button', 'code_block' => 'code', 'media_image' => 'image', 'media_video' => 'video' );
		return isset( $map[ $tag ] ) ? $map[ $tag ] : '';
	}

	/* ---------------------------------------------------------------------- *
	 * DOM + token helpers
	 * ---------------------------------------------------------------------- */

	/** Load HTML into a DOMDocument (UTF-8 safe, errors suppressed). */
	private static function load_dom( $html ) {
		if ( $html === '' || ! class_exists( 'DOMDocument' ) ) { return null; }
		$dom = new DOMDocument();
		$prev = libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="UTF-8">' . $html );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		return $dom;
	}

	/** Element children (skip text/comment nodes). */
	private static function el_children( $el ) {
		$out = array();
		foreach ( $el->childNodes as $c ) { if ( $c->nodeType === XML_ELEMENT_NODE ) { $out[] = $c; } }
		return $out;
	}

	private static function cls( $el ) {
		return ( $el instanceof DOMElement ) ? strtolower( (string) $el->getAttribute( 'class' ) ) : '';
	}

	private static function text( $el ) {
		return trim( preg_replace( '/\s+/', ' ', (string) $el->textContent ) );
	}

	/** Visible text with material-symbol icon glyphs removed (so a button label isn't "Go arrow_forward"). */
	private static function text_no_icons( $el ) {
		if ( ! $el->ownerDocument ) { return self::text( $el ); }
		$clone = $el->cloneNode( true );
		self::scrub( $clone );
		return trim( preg_replace( '/\s+/', ' ', (string) $clone->textContent ) );
	}

	private static function has_ancestor_tag( $el, $tag, $stop ) {
		$p = $el->parentNode;
		while ( $p && $p !== $stop ) {
			if ( $p->nodeType === XML_ELEMENT_NODE && strtolower( $p->tagName ) === $tag ) { return true; }
			$p = $p->parentNode;
		}
		return false;
	}

	/** A pill/eyebrow chip: small uppercase label, often `rounded-full`. */
	private static function is_pill( $el ) {
		$tag = strtolower( $el->tagName );
		if ( ! in_array( $tag, array( 'span', 'div', 'p' ), true ) ) { return false; }
		$cls = self::cls( $el );
		$txt = self::text( $el );
		if ( $txt === '' || mb_strlen( $txt ) > 48 ) { return false; }
		$looks_pill = ( strpos( $cls, 'rounded-full' ) !== false && strpos( $cls, 'uppercase' ) !== false )
			|| ( strpos( $cls, 'uppercase' ) !== false && strpos( $cls, 'tracking' ) !== false );
		return (bool) $looks_pill;
	}

	/** A small inline rounded-full CHIP with a border/fill + a sub-tag (e.g. "New · v2.0 is now live") — a
	 *  hero badge. Carried VERBATIM so its pill look (colored tag, border, arrow) survives, unlike an overline. */
	private static function is_badge( $el ) {
		$tag = strtolower( $el->tagName );
		if ( ! in_array( $tag, array( 'div', 'span' ), true ) ) { return false; }
		$cls = self::cls( $el );
		if ( strpos( $cls, 'rounded-full' ) === false ) { return false; }
		if ( strpos( $cls, 'inline-flex' ) === false && strpos( $cls, 'inline-block' ) === false ) { return false; }
		if ( strpos( $cls, 'border' ) === false && strpos( $cls, 'bg-' ) === false ) { return false; }
		$txt = self::text( $el );
		if ( $txt === '' || mb_strlen( $txt ) > 60 ) { return false; }
		return $el->getElementsByTagName( 'span' )->length >= 1; // has the inner "New" tag
	}

	/** A hero "announcement pill": a rounded-full inline chip (sub-tag + message + optional icon) that maps
	 *  to the announcement_pill shortcode. Stricter than is_badge (rejects images, which would be lost);
	 *  also accepts a linked <a> pill. When this returns false the verbatim `badge` recognizer takes over. */
	private static function is_announcement_pill( $el ) {
		$tag = strtolower( $el->tagName );
		if ( ! in_array( $tag, array( 'div', 'span', 'a' ), true ) ) { return false; }
		$cls = self::cls( $el );
		if ( strpos( $cls, 'rounded-full' ) === false ) { return false; }
		if ( strpos( $cls, 'inline-flex' ) === false && strpos( $cls, 'inline-block' ) === false && strpos( $cls, 'inline' ) === false ) { return false; }
		if ( strpos( $cls, 'border' ) === false && strpos( $cls, 'bg-' ) === false ) { return false; }
		if ( $el->getElementsByTagName( 'img' )->length > 0 ) { return false; }
		$txt = self::text( $el );
		if ( $txt === '' || mb_strlen( $txt ) > 70 ) { return false; }
		return $el->getElementsByTagName( 'span' )->length >= 1;
	}

	/** Pull a pill apart into { tag_text, message, icon (fa class), link }. The first short, badge-like inner
	 *  span (rounded-full / uppercase / bg-*) is the sub-tag; a material-symbols / <i> span is the icon; the
	 *  remaining text is the message. */
	private static function pill_parts( $el ) {
		$tag_text = ''; $icon = ''; $link = ''; $msg = array();
		$pill_cls = self::cls( $el ); $tag_cls = ''; $msg_cls = ''; // source classes → color reproduction in n_announcement_pill
		$pill_cs  = ( $el instanceof DOMElement ) ? (string) $el->getAttribute( 'data-sc-cs' ) : ''; // container computed style → real fill (bg-primary/10)
		$lead_svg = ''; // an inline <svg> BEFORE the text → the pill's leading_icon (parity with heading overline_icon)
		$doc      = $el->ownerDocument;
		if ( strtolower( $el->tagName ) === 'a' ) { $link = (string) $el->getAttribute( 'href' ); }
		$nspan = $el->getElementsByTagName( 'span' )->length;
		foreach ( $el->childNodes as $ch ) {
			if ( XML_ELEMENT_NODE !== $ch->nodeType ) {
				$t = trim( (string) $ch->textContent );
				if ( $t !== '' ) { $msg[] = $t; }
				continue;
			}
			$ctag = strtolower( $ch->tagName );
			$ccls = self::cls( $ch );
			$ctxt = trim( self::text( $ch ) );
			if ( $ctag === 'a' && $link === '' ) { $link = (string) $ch->getAttribute( 'href' ); }
			// An inline <svg> (a lucide heart etc.) BEFORE the message → the leading icon (verbatim markup,
			// data-sc-* stripped). Only the FIRST, and only when no message text has been seen yet = leading.
			if ( $ctag === 'svg' ) {
				if ( $lead_svg === '' && empty( $msg ) && $tag_text === '' && $doc ) {
					$mk = (string) $doc->saveHTML( $ch );
					if ( is_string( $mk ) && $mk !== '' && strlen( $mk ) < 12000 ) {
						$lead_svg = self::strip_cs( preg_replace( '/\s+data-sc-col="[^"]*"/i', '', $mk ) );
					}
				}
				continue;
			}
			if ( strpos( $ccls, 'material-symbols' ) !== false ) { if ( $icon === '' ) { $icon = self::material_to_fa( $ctxt ); } continue; }
			if ( $ctag === 'i' ) { if ( $icon === '' ) { $icon = trim( $ccls ); } continue; }
			if ( $tag_text === '' && $ctxt !== '' && mb_strlen( $ctxt ) <= 18 && $nspan >= 2
				&& ( strpos( $ccls, 'rounded-full' ) !== false || strpos( $ccls, 'uppercase' ) !== false || strpos( $ccls, 'bg-' ) !== false ) ) {
				$tag_text = $ctxt; $tag_cls = $ccls; continue;
			}
			if ( $ctxt !== '' ) { if ( $msg_cls === '' ) { $msg_cls = $ccls; } $msg[] = $ctxt; }
		}
		$message = trim( implode( ' ', $msg ) );
		if ( $message === '' ) {
			$message = trim( self::text( $el ) );
			if ( $message !== '' && $tag_text !== '' && strpos( $message, $tag_text ) === 0 ) { $message = trim( substr( $message, strlen( $tag_text ) ) ); }
		}
		if ( $message === '' && $tag_text !== '' ) { $message = $tag_text; $tag_text = ''; }
		return array( 'tag_text' => $tag_text, 'message' => $message, 'icon' => $icon, 'link' => $link,
			'pillCls' => $pill_cls, 'tagCls' => $tag_cls, 'msgCls' => $msg_cls, 'pillCs' => $pill_cs, 'leadingSvg' => $lead_svg );
	}

	/** A button or a button-styled link. */
	private static function is_button( $el ) {
		$tag = strtolower( $el->tagName );
		if ( $tag === 'button' ) { return true; }
		if ( $tag !== 'a' ) { return false; }
		$cls = self::cls( $el );
		// A CTA link: pill/box padding + a fill/border that reads as a button (not a nav link).
		return ( strpos( $cls, 'rounded' ) !== false && ( strpos( $cls, 'bg-' ) !== false || strpos( $cls, 'border' ) !== false ) && strpos( $cls, 'px-' ) !== false );
	}

	/** Computed-style button test — framework-agnostic (data-sc-cs): a <button>, or an <a> whose RESOLVED
	 *  style reads like a button (padding + a fill / border / rounded), not a bare nav/text link. Lets the
	 *  converter recognize buttons on Bootstrap / plain-CSS / any-framework sites, not just Tailwind. */
	private static function cs_is_button( $el ) {
		$tag = strtolower( $el->tagName );
		if ( 'button' === $tag ) { return true; }
		if ( 'a' !== $tag ) { return false; }
		$cs = $el->getAttribute( 'data-sc-cs' );
		if ( '' === $cs || strpos( $cs, 'padding:' ) === false ) { return false; } // capture omits 0 padding → a link has none
		return ( strpos( $cs, 'background-color:' ) !== false || strpos( $cs, 'border-top-width:' ) !== false || strpos( $cs, 'border-radius:' ) !== false );
	}

	/** A thin wrapper whose only meaningful content is one image. */
	private static function is_image_wrapper( $el ) {
		$imgs = $el->getElementsByTagName( 'img' );
		if ( $imgs->length !== 1 ) { return false; }
		// No headings/paragraphs/buttons inside → it's just an image frame.
		foreach ( array( 'h1','h2','h3','h4','h5','h6','p','button','ul' ) as $t ) {
			if ( $el->getElementsByTagName( $t )->length > 0 ) { return false; }
		}
		return true;
	}

	/** One image with an absolute-positioned OVERLAY on top (a player/caption/controls layer over a frame). */
	private static function is_image_with_overlay( $el ) {
		if ( ! ( $el instanceof DOMElement ) ) { return false; }
		if ( $el->getElementsByTagName( 'img' )->length !== 1 ) { return false; }
		foreach ( $el->getElementsByTagName( 'div' ) as $d ) {
			if ( strpos( self::cls( $d ), 'absolute' ) !== false ) { return true; }
		}
		return false;
	}

	private static function img_html( $img ) {
		$src = $img->getAttribute( 'src' );
		$alt = $img->getAttribute( 'alt' );
		if ( $src === '' ) { return ''; }
		return '<img src="' . esc_url( $src ) . '" alt="' . esc_attr( $alt ) . '" />';
	}

	/* --------------------------------------------------------------------- *
	 *  IMAGE-COMPOSITE DECOMPOSITION (P0 fidelity fix)
	 *
	 *  A hero's "photo in an organic frame + floating badge + blob backdrop" composite used to land
	 *  as ONE opaque, un-editable code_block (whole widget verbatim). Decompose it instead into
	 *  NATIVE, editable elements: a `media_image` (the photo, with its organic radius / white border /
	 *  shadow AND the blob layer reproduced via the node's scoped Custom CSS) + one `icon_box` per
	 *  floating badge (icon + title + subtitle, positioned/skinned via scoped CSS). Anything that
	 *  doesn't cleanly match this shape (a plain image + caption, an un-decomposable overlay) is left
	 *  to the existing verbatim code_block fallback. Parity with the JS to-pages imgComposite path.
	 * --------------------------------------------------------------------- */

	/**
	 * TIGHT guard: is this a DECOMPOSABLE image composite? Exactly ONE <img> plus at least one
	 * ABSOLUTE overlay that is either a floating CARD/BADGE (real text/icon content + a visible skin)
	 * or a decorative BLOB layer (organic radius / bg tint, no content). A plain image with a simple
	 * caption (no absolute card/blob) does NOT qualify — it stays a bare media_image.
	 */
	private static function is_decomposable_image_composite( $el ) {
		if ( ! ( $el instanceof DOMElement ) ) { return false; }
		if ( $el->getElementsByTagName( 'img' )->length !== 1 ) { return false; }
		$ov = self::image_composite_overlays( $el );
		return ! empty( $ov['cards'] ) || ! empty( $ov['blobs'] );
	}

	/** Classify an image composite's ABSOLUTE overlay children into floating CARDS and decorative BLOBS. */
	private static function image_composite_overlays( $el ) {
		$out = array( 'cards' => array(), 'blobs' => array() );
		if ( ! ( $el instanceof DOMElement ) ) { return $out; }
		foreach ( $el->getElementsByTagName( 'div' ) as $d ) {
			if ( ! ( $d instanceof DOMElement ) ) { continue; }
			$cls = self::cls( $d );
			$ccs = (string) $d->getAttribute( 'data-sc-cs' );
			$is_abs = ( strpos( $cls, 'absolute' ) !== false ) || (bool) preg_match( '/(?:^|;)\s*position:\s*(?:absolute|fixed)/', $ccs );
			if ( ! $is_abs ) { continue; }
			// An absolute wrapper AROUND the image is the frame, not an overlay — skip it.
			if ( $d->getElementsByTagName( 'img' )->length > 0 ) { continue; }
			$txt      = trim( self::text( $d ) );
			$has_icon = $d->getElementsByTagName( 'svg' )->length > 0 || $d->getElementsByTagName( 'i' )->length > 0;
			if ( '' === $txt && ! $has_icon ) {
				// A decorative BLOB: no content. Require a rounded / tinted layer (not just any empty div).
				if ( preg_match( '/\b(blob|rounded|bg-)/', $cls ) || preg_match( '/border-radius:|background-color:\s*rgb/', $ccs ) ) {
					$out['blobs'][] = $d;
				}
				continue;
			}
			// A floating CARD/BADGE: content + a visible skin (fill / rounded / shadow).
			$has_skin = (bool) preg_match( '/\b(bg-|rounded|shadow)/', $cls )
				|| (bool) preg_match( '/background-color:\s*rgb|border-radius:\s*[1-9]|box-shadow:\s*rgb/', $ccs );
			if ( $has_skin ) { $out['cards'][] = $d; }
		}
		return $out;
	}

	/**
	 * Decompose an image composite into a LIST of native blocks:
	 *   1) `image` (media_image) — clean <img>, with the organic radius + white border + shadow, and
	 *      the blob backdrop, carried on `skinCss` (scoped `selector`-rules).
	 *   2) one `floating_card` (icon_box) per absolute badge — icon + title + subtitle, positioned +
	 *      skinned via `posCss`.
	 * Returns null if there's no image.
	 */
	private static function image_composite_decompose( $el ) {
		if ( ! ( $el instanceof DOMElement ) ) { return null; }
		$img = $el->getElementsByTagName( 'img' )->item( 0 );
		if ( ! $img ) { return null; }
		$ov     = self::image_composite_overlays( $el );
		$blocks = array();

		$blocks[] = array(
			't'       => 'image',
			'role'    => 'image',
			'html'    => self::img_html( $img ), // CLEAN <img src alt> → native media_image (skin travels via skinCss)
			'skinCss' => self::img_composite_skin_css( $img, ! empty( $ov['blobs'] ) ? $ov['blobs'][0] : null ),
		);
		foreach ( $ov['cards'] as $card ) {
			$b = self::floating_card_block( $card );
			if ( $b ) { $blocks[] = $b; }
		}
		return $blocks;
	}

	/**
	 * The image's own SKIN (organic border-radius, white border, shadow) + the decorative BLOB layer
	 * behind it, as scoped Custom CSS for the media_image node. `selector` resolves to the element's
	 * generated scope class, so `selector img` targets the rendered <img> and `selector::before`
	 * paints the blob behind it (no code_block, no extra element).
	 */
	private static function img_composite_skin_css( $img, $blob = null ) {
		$img_decl = array( 'position:relative', 'z-index:1' );
		$radius   = self::sc_css( $img, 'border-radius' );
		if ( '' !== $radius && ! preg_match( '/^(?:0px)(?:\s+0px)*$/', trim( $radius ) ) ) { $img_decl[] = 'border-radius:' . $radius; }
		$bw = self::sc_css( $img, 'border-top-width' );
		$bc = self::sc_css( $img, 'border-top-color' );
		if ( '' !== $bw && (float) $bw > 0 && '' !== $bc ) { $img_decl[] = 'border:' . $bw . ' solid ' . $bc; }
		$shadow = self::sc_css( $img, 'box-shadow' );
		if ( '' !== $shadow && 'none' !== $shadow ) { $img_decl[] = 'box-shadow:' . $shadow; }
		$fit = self::sc_css( $img, 'object-fit' );
		if ( '' !== $fit && 'fill' !== $fit ) { $img_decl[] = 'object-fit:' . $fit; }

		$css  = 'selector{position:relative;}';
		$css .= 'selector img{' . implode( ';', $img_decl ) . ';}';

		if ( $blob instanceof DOMElement ) {
			$b_decl = array( 'content:""', 'position:absolute', 'inset:0', 'z-index:0', 'pointer-events:none' );
			$bg     = self::sc_css( $blob, 'background-color' );
			if ( '' !== $bg && ! preg_match( '/rgba?\(\s*0[,\s]+0[,\s]+0[,\s]+0\s*\)|transparent/', $bg ) ) { $b_decl[] = 'background:' . $bg; }
			$brad = self::sc_css( $blob, 'border-radius' );
			if ( '' !== $brad ) { $b_decl[] = 'border-radius:' . $brad; }
			// `scale-95` (or an inline transform) on the blob → a matching transform on the pseudo-element.
			if ( preg_match( '/\bscale-(\d{1,3})\b/', self::cls( $blob ), $sm ) ) { $b_decl[] = 'transform:scale(' . ( (int) $sm[1] / 100 ) . ')'; }
			$css .= 'selector::before{' . implode( ';', $b_decl ) . ';}';
		}
		return $css;
	}

	/**
	 * Parse a floating badge/card overlay into an `floating_card` block: the icon (inline SVG or a
	 * Lucide id + its chip wrapper), a bold title, a muted subtitle, and the positioning/skin as
	 * scoped `posCss`. Mirrors the icon-chip capture used by the card grid path.
	 */
	private static function floating_card_block( $card ) {
		if ( ! ( $card instanceof DOMElement ) ) { return null; }
		$title = '';
		$text  = '';
		foreach ( $card->getElementsByTagName( 'p' ) as $p ) {
			$t = trim( self::text( $p ) );
			if ( '' === $t ) { continue; }
			if ( '' === $title ) { $title = $t; }
			elseif ( '' === $text ) { $text = $t; }
		}
		if ( '' === $title ) { $title = trim( self::text( $card ) ); }

		$data = array(
			'title'      => $title,
			'titleTag'   => 'h4',
			'text'       => '' !== $text ? '<p>' . esc_html( $text ) . '</p>' : '',
			'iconLayout' => 'inline-left', // icon beside the text (source flex items-center gap-*)
			'center'     => false,
		);
		// Icon: a Lucide glyph (library icon) when detectable, else the inline SVG verbatim.
		$lucide = self::detect_lucide_in( $card );
		$svg    = $card->getElementsByTagName( 'svg' )->item( 0 );
		if ( '' !== $lucide ) {
			$data['lucide'] = preg_replace( '#^lucide/#', '', $lucide );
		} elseif ( $svg instanceof DOMElement && $card->ownerDocument ) {
			$data['customIcon'] = self::strip_cs( trim( (string) $card->ownerDocument->saveHTML( $svg ) ) );
		}
		if ( $svg instanceof DOMElement ) {
			$data['iconCls'] = self::cls( $svg );
			// The svg's own captured colour → icon_color (resolves the source `text-secondary` token
			// without needing the Tailwind config — the capture always carries the computed style).
			$icol = self::sc_css( $svg, 'color' );
			if ( '' !== $icol ) { $data['iconColor'] = $icol; }
			// The filled ICON CHIP wrapper (e.g. `w-12 h-12 bg-secondary/20 rounded-full`) → icon_badge.
			// Prefer the chip's CAPTURED background/radius (robust) and keep the class for the fallback.
			$chip = $svg->parentNode;
			if ( $chip instanceof DOMElement && $chip !== $card ) {
				$data['iconChipCls'] = self::cls( $chip );
				$cbg = self::sc_css( $chip, 'background-color' );
				if ( '' !== $cbg && ! preg_match( '/rgba?\(\s*0[,\s]+0[,\s]+0[,\s]+0\s*\)|transparent/', $cbg ) ) {
					$data['iconBadgeColor'] = $cbg;
					$crad = self::sc_css( $chip, 'border-radius' );
					$rn   = (float) $crad; // '9999px' / '50%' → circle, >0 → rounded, else square
					$data['iconBadge'] = ( $rn >= 9999 || strpos( $crad, '50%' ) !== false ) ? 'solid-circle' : ( $rn > 0 ? 'solid-rounded' : 'solid-square' );
				}
			}
		}

		return array(
			't'      => 'floating_card',
			'role'   => 'floating_card',
			'card'   => $data,
			'posCss' => self::floating_card_pos_css( $card ),
		);
	}

	/** Position + skin the floating card over the image via scoped Custom CSS (absolute top/left, bg,
	 *  radius, shadow, padding — from the source's Tailwind classes + captured computed styles). */
	private static function floating_card_pos_css( $card ) {
		$decl = array( 'position:absolute', 'z-index:20', 'max-width:16rem' );
		$cls  = self::cls( $card );
		// Tailwind top-N / -left-N (etc.) → rem offsets (N * 0.25rem); a negative token flips the sign.
		foreach ( array( 'top', 'left', 'right', 'bottom' ) as $side ) {
			if ( preg_match( '/(^|\s)(-?)' . $side . '-(\d{1,3})(\s|$)/', ' ' . $cls . ' ', $m ) ) {
				$val = ( '-' === $m[2] ? -1 : 1 ) * ( (int) $m[3] * 0.25 );
				$decl[] = $side . ':' . rtrim( rtrim( sprintf( '%.3f', $val ), '0' ), '.' ) . 'rem';
			}
		}
		$bg = self::sc_css( $card, 'background-color' );
		if ( '' !== $bg && ! preg_match( '/rgba?\(\s*0[,\s]+0[,\s]+0[,\s]+0\s*\)|transparent/', $bg ) ) { $decl[] = 'background:' . $bg; }
		$rad = self::sc_css( $card, 'border-radius' );
		if ( '' !== $rad && ! preg_match( '/^(?:0px)(?:\s+0px)*$/', trim( $rad ) ) ) { $decl[] = 'border-radius:' . $rad; }
		$sh = self::sc_css( $card, 'box-shadow' );
		if ( '' !== $sh && 'none' !== $sh ) { $decl[] = 'box-shadow:' . $sh; }
		$pad = self::sc_css( $card, 'padding' );
		if ( '' !== $pad && ! preg_match( '/^(?:0px)(?:\s+0px)*$/', trim( $pad ) ) ) { $decl[] = 'padding:' . $pad; }
		return 'selector{' . implode( ';', $decl ) . ';}';
	}

	/** Inner HTML with class/style attrs and material-symbol spans stripped (clean DOM). */
	private static function clean_inline_html( $el ) {
		if ( ! $el->ownerDocument ) { return self::text( $el ); }
		$clone = $el->cloneNode( true );
		self::scrub( $clone );
		$h = '';
		foreach ( $clone->childNodes as $c ) { $h .= $clone->ownerDocument->saveHTML( $c ); }
		return self::strip_cs( trim( $h ) );
	}

	/** Remove the Phase-2 `data-sc-cs` computed-style attribute from any HTML carried verbatim into output. */
	private static function strip_cs( $html ) {
		return preg_replace( '/\s+data-sc-cs="[^"]*"/i', '', (string) $html );
	}

	/** Whole-element clean HTML (a cell that didn't match a card → keep its markup, scrubbed). */
	private static function clean_block_html( $el ) {
		if ( ! $el->ownerDocument ) { return self::text( $el ); }
		$clone = $el->cloneNode( true );
		self::scrub( $clone );
		return trim( (string) $el->ownerDocument->saveHTML( $clone ) );
	}

	/** A <ul> rebuilt as clean `<ul><li>text</li></ul>` (no classes, no icon spans). */
	private static function clean_list_html( $ul ) {
		$items = array();
		foreach ( $ul->getElementsByTagName( 'li' ) as $li ) {
			$t = self::text( $li );
			if ( $t !== '' ) { $items[] = '<li>' . esc_html( $t ) . '</li>'; }
		}
		return $items ? '<ul>' . implode( '', $items ) . '</ul>' : '';
	}

	/** Recursively drop class/style attrs and material-symbol spans from a cloned node. */
	private static function scrub( $node ) {
		if ( $node->nodeType !== XML_ELEMENT_NODE ) { return; }
		// Remove material-symbol icon spans entirely.
		$remove = array();
		foreach ( $node->childNodes as $c ) {
			if ( $c->nodeType === XML_ELEMENT_NODE && strtolower( $c->tagName ) === 'span' && strpos( strtolower( $c->getAttribute( 'class' ) ), 'material-symbols' ) !== false ) {
				$remove[] = $c;
			}
		}
		foreach ( $remove as $r ) { $node->removeChild( $r ); }
		if ( $node->hasAttribute( 'class' ) ) { $node->removeAttribute( 'class' ); }
		if ( $node->hasAttribute( 'style' ) ) { $node->removeAttribute( 'style' ); }
		foreach ( self::el_children( $node ) as $c ) { self::scrub( $c ); }
	}

	/** col-span-N → N (desktop span on a 12-grid); 0 when absent. */
	private static function col_span( $cls ) {
		if ( preg_match( '/(?:^|\s|:)col-span-(\d{1,2})/', $cls, $m ) ) { return (int) $m[1]; }
		return 0;
	}

	/** grid-cols-N → N (count of columns); 0 when absent. */
	private static function grid_col_count( $grid ) {
		$cls = self::cls( $grid );
		// The DESKTOP layout uses the LARGEST breakpoint present (lg:grid-cols-3 beats md:grid-cols-2), so
		// check widest → narrowest (the old code matched the first of md|lg, wrongly returning 2 → 1/2 cols).
		foreach ( array( '2xl', 'xl', 'lg', 'md', 'sm' ) as $bp ) {
			if ( preg_match( '/' . $bp . ':grid-cols-(\d{1,2})/', $cls, $m ) ) { return (int) $m[1]; }
		}
		if ( preg_match( '/(?:^|\s)grid-cols-(\d{1,2})/', $cls, $m ) ) { return (int) $m[1]; }
		// Non-Tailwind fallback: a plain flex/grid row of N card cells → N columns (each gets 12/N width).
		$n = 0;
		foreach ( self::el_children( $grid ) as $k ) { if ( self::is_card_cell( $k ) ) { $n++; } }
		return $n >= 2 ? $n : 0;
	}

	/** Does the icon span lead the button (appears before the label text)? */
	private static function icon_is_leading( $btn, $span ) {
		foreach ( $btn->childNodes as $c ) {
			if ( $c === $span ) { return true; }
			if ( $c->nodeType === XML_TEXT_NODE && trim( $c->textContent ) !== '' ) { return false; }
		}
		return false;
	}

	/** A stable signature for a captured element (tag + semantic, non-utility classes). */
	private static function el_signature( $el ) {
		$tag = strtolower( $el->tagName );
		$keep = array();
		foreach ( preg_split( '/\s+/', self::cls( $el ) ) as $c ) {
			if ( $c === '' ) { continue; }
			// Drop Tailwind utility noise; keep descriptive tokens (rare in Stitch, but stable when present).
			if ( preg_match( '#^(?:[a-z]+:)?(?:m[xytrbl]?-|p[xytrbl]?-|gap-|grid|flex|col-|row|w-|h-|max-|min-|text-|font-|bg-|border|rounded|tracking|leading|items-|justify-|self-|order-|hidden|block|inline|relative|absolute|sticky|fixed|z-|overflow|opacity|shadow|backdrop|hover:|focus:|active:|transition|transform|space-|aspect-|object-|top-|left-|right-|bottom-)#', $c ) ) { continue; }
			$keep[] = $c;
		}
		sort( $keep );
		return $tag . '|' . implode( ' ', $keep );
	}

	/** Map a Material Symbols glyph name to a renderable Font Awesome class (neutral fallback). */
	private static function material_to_fa( $name ) {
		$name = strtolower( trim( (string) $name ) );
		if ( $name === '' ) { return ''; }
		$map = array(
			'bolt' => 'bolt', 'security' => 'shield', 'lock' => 'lock', 'verified' => 'check-circle',
			'check_circle' => 'check-circle', 'check' => 'check', 'done' => 'check', 'task_alt' => 'check-circle',
			'arrow_forward' => 'arrow-right', 'arrow_back' => 'arrow-left', 'chevron_right' => 'chevron-right',
			'play_circle' => 'play-circle', 'play_arrow' => 'play', 'search' => 'search', 'rocket_launch' => 'rocket',
			'rocket' => 'rocket', 'speed' => 'tachometer', 'insights' => 'line-chart', 'analytics' => 'bar-chart',
			'trending_up' => 'line-chart', 'cloud' => 'cloud', 'code' => 'code', 'settings' => 'cog', 'tune' => 'sliders',
			'group' => 'users', 'groups' => 'users', 'person' => 'user', 'support_agent' => 'headphones',
			'star' => 'star', 'favorite' => 'heart', 'shield' => 'shield', 'bookmark' => 'bookmark',
			'palette' => 'paint-brush', 'dashboard' => 'th-large', 'layers' => 'clone', 'hub' => 'sitemap',
			'mail' => 'envelope', 'email' => 'envelope', 'schedule' => 'clock-o', 'timer' => 'clock-o',
			'visibility' => 'eye', 'auto_awesome' => 'magic', 'workspace_premium' => 'trophy',
			'record_voice_over' => 'microphone', 'mic' => 'microphone', 'microphone' => 'microphone', 'keyboard_voice' => 'microphone',
			'graphic_eq' => 'signal', 'equalizer' => 'signal', 'waveform' => 'signal', 'volume_up' => 'volume-up', 'headphones' => 'headphones', 'headset' => 'headphones',
			'content_copy' => 'clone', 'copy' => 'clone', 'file_copy' => 'clone', 'difference' => 'clone',
			'language' => 'globe', 'translate' => 'globe', 'public' => 'globe', 'chat' => 'comment', 'forum' => 'comments', 'sms' => 'comment', 'menu' => 'bars',
			'edit' => 'pencil', 'description' => 'file-text-o', 'article' => 'file-text-o', 'psychology' => 'lightbulb-o', 'lightbulb' => 'lightbulb-o', 'auto_fix_high' => 'magic',
		);
		$fa = isset( $map[ $name ] ) ? $map[ $name ] : 'star';
		return 'fa fa-' . $fa;
	}

	/* --- token → css helpers --- */

	/** A CSS-safe variable name from a token key. */
	private static function css_var_name( $name ) {
		$n = strtolower( preg_replace( '/[^a-z0-9-]+/i', '-', (string) $name ) );
		return trim( $n, '-' );
	}

	/** Normalize a hex color ('#abc'/'#aabbcc'/'aabbcc' → '#aabbcc'); '' if not a hex. */
	private static function norm_hex( $v ) {
		$v = trim( (string) $v );
		if ( $v === '' ) { return ''; }
		if ( $v[0] !== '#' ) { $v = '#' . $v; }
		return preg_match( '/^#(?:[0-9a-f]{3}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $v ) ? $v : '';
	}

	/** First defined color among $keys → its hex. */
	private static function token_color( array $tokens, array $keys ) {
		foreach ( $keys as $k ) {
			if ( isset( $tokens['colors'][ $k ] ) ) {
				$h = self::norm_hex( (string) $tokens['colors'][ $k ] );
				if ( $h !== '' ) { return $h; }
			}
		}
		return '';
	}

	/** Pick (headline, body) font stacks from the fontFamily tokens. */
	private static function pick_fonts( array $tokens ) {
		$ff = $tokens['fontFamily'] ?? array();
		$first = function ( $keys ) use ( $ff ) {
			foreach ( $keys as $k ) {
				if ( isset( $ff[ $k ] ) ) {
					$v = is_array( $ff[ $k ] ) ? reset( $ff[ $k ] ) : $ff[ $k ];
					$v = trim( (string) $v );
					if ( $v !== '' ) { return $v; }
				}
			}
			return '';
		};
		$head = $first( array( 'headline-xl', 'headline-lg', 'headline-md', 'display', 'heading' ) );
		$body = $first( array( 'body-md', 'body-lg', 'body', 'label-sm' ) );
		$wrap = function ( $f, $fallback ) { return $f !== '' ? ( "'" . $f . "'," . $fallback ) : ''; };
		return array(
			$wrap( $head, 'system-ui,-apple-system,Segoe UI,Roboto,sans-serif' ),
			$wrap( $body, 'system-ui,-apple-system,Segoe UI,Roboto,sans-serif' ),
		);
	}

	/* --- folder / parsing helpers --- */

	/**
	 * Resolve screens from a Stitch export folder (both layouts).
	 *
	 * @param string $dir
	 * @return array{0: array[], 1: string} [ screens, design_md ]
	 */
	private static function screens_from_folder( $dir ) {
		$dir = rtrim( $dir, '/\\' );
		$screens = array();
		$design_md = '';

		// Flat single-frame: code.html at the root.
		if ( is_file( $dir . '/code.html' ) ) {
			$html = (string) file_get_contents( $dir . '/code.html' );
			$screens[] = array( 'html' => $html, 'title' => self::title_from_html( $html, self::title_from_dir( $dir ) ), 'slug' => '', 'front' => true );
			if ( is_file( $dir . '/DESIGN.md' ) ) { $design_md = (string) file_get_contents( $dir . '/DESIGN.md' ); }
			return array( $screens, $design_md );
		}

		// Multi-screen: one subfolder per screen + top-level <system>/DESIGN.md.
		$subs = glob( $dir . '/*', GLOB_ONLYDIR );
		$first = true;
		foreach ( (array) $subs as $sub ) {
			if ( is_file( $sub . '/code.html' ) ) {
				$html = (string) file_get_contents( $sub . '/code.html' );
				$screens[] = array(
					'html'  => $html,
					'title' => self::title_from_html( $html, self::title_from_dir( $sub ) ),
					'slug'  => $first ? '' : sanitize_title( basename( $sub ) ),
					'front' => $first,
				);
				$first = false;
			} elseif ( $design_md === '' && is_file( $sub . '/DESIGN.md' ) ) {
				$design_md = (string) file_get_contents( $sub . '/DESIGN.md' );
			}
		}
		if ( $design_md === '' && is_file( $dir . '/DESIGN.md' ) ) { $design_md = (string) file_get_contents( $dir . '/DESIGN.md' ); }
		return array( $screens, $design_md );
	}

	private static function title_from_dir( $dir ) {
		$name = basename( rtrim( $dir, '/\\' ) );
		$name = preg_replace( '/^stitch[_-]/i', '', $name );
		$name = trim( preg_replace( '/[_-]+/', ' ', $name ) );
		// A ZIP unzips into a random temp dir (fw-sc-stitch-in-XXXX) — never a usable title.
		if ( $name === '' || preg_match( '/^fw[ -]sc[ -]stitch[ -]in[ -]/i', $name ) ) { return 'Home'; }
		return ucwords( $name );
	}

	/** The page title from the HTML `<title>` (the best source for a Stitch screen), else $fallback. */
	private static function title_from_html( $html, $fallback ) {
		if ( preg_match( '/<title[^>]*>(.*?)<\/title>/is', (string) $html, $m ) ) {
			$t = trim( html_entity_decode( wp_strip_all_tags( $m[1] ), ENT_QUOTES | ENT_HTML5 ) );
			// Stitch titles are "Brand - Tagline" / "Brand | Tagline" — keep just the brand half.
			if ( $t !== '' && preg_match( '/^(.{2,40}?)\s*[-–—|:]\s+\S/u', $t, $mm ) ) {
				$t = trim( $mm[1] );
			}
			if ( $t !== '' ) { return $t; }
		}
		return $fallback;
	}

	/** Font family names parsed from a Google-Fonts css2 URL (family=Inter&family=Manrope → [Inter, Manrope]). */
	private static function fonts_from_google( $href ) {
		$out = array();
		if ( preg_match_all( '/family=([^:&]+)/', (string) $href, $m ) ) {
			foreach ( $m[1] as $f ) { $out[] = trim( str_replace( '+', ' ', urldecode( $f ) ) ); }
		}
		return array_values( array_filter( $out ) );
	}

	/** Scan inline colors (`[#rrggbb]`, `from-[#..]`) for the most saturated one — the brand accent. */
	private static function scan_accent( $html ) {
		if ( ! preg_match_all( '/#([0-9a-fA-F]{6})\b/', (string) $html, $m ) ) { return ''; }
		$best = ''; $bestScore = 0;
		foreach ( array_unique( $m[1] ) as $hex ) {
			$r = hexdec( substr( $hex, 0, 2 ) ); $g = hexdec( substr( $hex, 2, 2 ) ); $b = hexdec( substr( $hex, 4, 2 ) );
			$max = max( $r, $g, $b ); $min = min( $r, $g, $b );
			$sat = $max ? ( $max - $min ) / $max : 0;          // HSV saturation
			$score = $sat * ( $max / 255 );                     // favor vivid + bright
			if ( $sat > 0.45 && $score > $bestScore ) { $bestScore = $score; $best = '#' . strtolower( $hex ); }
		}
		return $best;
	}

	/** Is a hex color a near-neutral (grey/near-white/near-black) — i.e. a poor accent? */
	private static function is_neutral_hex( $hex ) {
		if ( ! preg_match( '/^#([0-9a-f]{6})$/i', (string) $hex, $m ) ) { return true; }
		$r = hexdec( substr( $m[1], 0, 2 ) ); $g = hexdec( substr( $m[1], 2, 2 ) ); $b = hexdec( substr( $m[1], 4, 2 ) );
		$max = max( $r, $g, $b ); $min = min( $r, $g, $b );
		$sat = $max ? ( $max - $min ) / $max : 0;
		return $sat < 0.25; // low saturation → neutral
	}

	/**
	 * Sanitize a raw id/anchor string into a valid slug (lowercase, [a-z0-9-], trimmed).
	 * Shared precedence helper so Stitch::section_id() and Mapper::auto_id() agree.
	 */
	public static function slug_from_id( $raw ) {
		$s = strtolower( trim( (string) $raw ) );
		$s = preg_replace( '/[^a-z0-9-]+/', '-', $s );
		$s = preg_replace( '/-+/', '-', $s );
		return trim( $s, '-' );
	}

	/** A stable section id: the source node's own id attribute first, then descriptive class/comment, else section-N. */
	private static function section_id( $node, $idx ) {
		// 1) Prefer the source node's real id attribute (what in-page anchors / scroll-spy target).
		if ( $node instanceof DOMElement && $node->hasAttribute( 'id' ) ) {
			$sid = self::slug_from_id( $node->getAttribute( 'id' ) );
			if ( $sid !== '' ) { return $sid; }
		}
		// Stitch comments (<!-- Hero Section -->) precede the section; use the preceding comment if any.
		$prev = $node->previousSibling;
		while ( $prev && $prev->nodeType !== XML_COMMENT_NODE && trim( (string) $prev->textContent ) === '' ) {
			$prev = $prev->previousSibling;
		}
		if ( $prev && $prev->nodeType === XML_COMMENT_NODE ) {
			$id = sanitize_title( trim( $prev->textContent ) );
			if ( $id !== '' ) { return $id; }
		}
		if ( strtolower( $node->tagName ) === 'footer' ) { return 'footer'; }
		return 'section-' . ( (int) $idx + 1 );
	}

	/* --- tiny parsers (no deps) --- */

	/** Read a balanced { … } object starting at $start (the position of the opening brace). */
	private static function balanced_braces( $s, $start ) {
		$n = strlen( $s );
		if ( $start >= $n || $s[ $start ] !== '{' ) { return ''; }
		$depth = 0; $in_str = false; $q = '';
		for ( $i = $start; $i < $n; $i++ ) {
			$ch = $s[ $i ];
			if ( $in_str ) {
				if ( $ch === '\\' ) { $i++; continue; }
				if ( $ch === $q ) { $in_str = false; }
				continue;
			}
			if ( $ch === '"' || $ch === "'" ) { $in_str = true; $q = $ch; continue; }
			if ( $ch === '{' ) { $depth++; }
			elseif ( $ch === '}' ) { $depth--; if ( $depth === 0 ) { return substr( $s, $start, $i - $start + 1 ); } }
		}
		return '';
	}

	/**
	 * Decode a loose JS object literal (the tailwind.config block) into a PHP array. Tailwind's
	 * config is already valid-ish JSON (double-quoted keys + values, arrays); we tolerate trailing
	 * commas and single quotes, then json_decode. Returns array() on failure.
	 */
	private static function loose_json_to_array( $obj ) {
		$obj = (string) $obj;
		// Single-quoted strings → double-quoted (the Stitch config uses double quotes, but be safe).
		// Strip trailing commas before } or ].
		$clean = preg_replace( '/,(\s*[}\]])/', '$1', $obj );
		$data = json_decode( $clean, true );
		if ( is_array( $data ) ) { return $data; }
		// Last resort: quote bare keys (identifier:) then retry.
		$clean2 = preg_replace( '/([{,]\s*)([A-Za-z_][A-Za-z0-9_-]*)(\s*:)/', '$1"$2"$3', $clean );
		$clean2 = str_replace( "'", '"', $clean2 );
		$data = json_decode( $clean2, true );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Minimal YAML reader for the DESIGN.md frontmatter (the subset Stitch emits: nested two-level
	 * `key:` / `  child: value` maps). Not a general YAML parser.
	 *
	 * @param string $yaml
	 * @return array
	 */
	private static function tiny_yaml( $yaml ) {
		$out = array();
		$cur = null;
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $yaml ) as $line ) {
			if ( trim( $line ) === '' || ltrim( $line )[0] === '#' ) { continue; }
			if ( preg_match( '/^(\S[^:]*):\s*$/', $line, $m ) ) {
				$cur = trim( $m[1] );
				$out[ $cur ] = array();
				continue;
			}
			if ( preg_match( '/^\s+(\S[^:]*):\s*(.+?)\s*$/', $line, $m ) && $cur !== null ) {
				$out[ $cur ][ trim( $m[1] ) ] = self::yaml_scalar( $m[2] );
				continue;
			}
			if ( preg_match( '/^(\S[^:]*):\s*(.+?)\s*$/', $line, $m ) ) {
				$out[ trim( $m[1] ) ] = self::yaml_scalar( $m[2] );
				$cur = null;
			}
		}
		return $out;
	}

	private static function yaml_scalar( $v ) {
		$v = trim( (string) $v );
		if ( ( $v[0] ?? '' ) === "'" || ( $v[0] ?? '' ) === '"' ) { $v = trim( $v, "'\"" ); }
		return $v;
	}
}
