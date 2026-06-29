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
			$lines[] = 'body:not(.wp-admin) .btn{' . $btn . '}';
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
		$name = trim( (string) $title ) !== '' ? trim( (string) $title ) : 'Stitch Site';

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
		if ( $header ) { return $header; }
		$body = $dom->getElementsByTagName( 'body' )->item( 0 );
		if ( ! $body ) { return null; }
		$first = null;
		foreach ( $body->getElementsByTagName( 'nav' ) as $nav ) {
			if ( self::has_ancestor_tag( $nav, 'main', $body )
				|| self::has_ancestor_tag( $nav, 'section', $body )
				|| self::has_ancestor_tag( $nav, 'footer', $body ) ) {
				continue;
			}
			$c = self::cls( $nav );
			if ( strpos( $c, 'fixed' ) !== false || strpos( $c, 'sticky' ) !== false || strpos( $c, 'top-0' ) !== false ) {
				return $nav;
			}
			if ( $first === null ) { $first = $nav; }
		}
		return $first;
	}

	private static function detect_header( $html ) {
		$out = array( 'style' => 'bar', 'sticky' => false, 'dark' => false, 'cta' => array( 'label' => '', 'href' => '' ) );
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
		// CTA = the header's button (or a button-styled link).
		foreach ( $header->getElementsByTagName( 'button' ) as $b ) { $out['cta']['label'] = self::text_no_icons( $b ); break; }
		if ( $out['cta']['label'] === '' ) {
			foreach ( $header->getElementsByTagName( 'a' ) as $a ) {
				if ( self::is_button( $a ) ) { $out['cta']['label'] = self::text_no_icons( $a ); $out['cta']['href'] = $a->getAttribute( 'href' ); break; }
			}
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
					foreach ( $blocks as &$bk ) {
						if ( in_array( $bk['role'] ?? '', array( 'title', 'heading', 'overline', 'subtitle', 'announcement_pill' ), true ) && empty( $bk['align'] ) ) {
							$bk['align'] = 'center';
						}
					}
					unset( $bk );
				}
				$sections[] = array(
					'sectionClass' => '',
					'sectionRawClass' => self::cls( $node ), // the section's RAW classes (mt/mb/py …) → vertical-spacing carry, without polluting css_class
					'css_id'       => self::section_id( $node, $idx ),
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
		// one level down (a content wrapper inside the section)
		foreach ( $node->childNodes as $ch ) {
			if ( $ch->nodeType !== XML_ELEMENT_NODE ) { continue; }
			$cc = self::cls( $ch );
			if ( strpos( $cc, 'text-center' ) !== false || ( strpos( $cc, 'items-center' ) !== false && strpos( $cc, 'flex' ) !== false ) ) { return true; }
		}
		return false;
	}

	private static function section_roots( $body ) {
		$out = array();
		$main = null;
		foreach ( $body->getElementsByTagName( 'main' ) as $m ) { $main = $m; break; }
		$scope = $main ? $main : $body;
		foreach ( $scope->getElementsByTagName( 'section' ) as $s ) {
			// Only top-level sections (not a <section> nested inside another we'll already emit).
			if ( ! self::has_ancestor_tag( $s, 'section', $scope ) ) { $out[] = $s; }
		}
		return $out;
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
			function ( $el ) { $p = self::pill_parts( $el ); return array( 't' => 'pill', 'role' => 'announcement_pill', 'tag_text' => $p['tag_text'], 'message' => $p['message'], 'icon' => $p['icon'], 'link' => $p['link'], 'align' => '' ); }
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
		// An image with an OVERLAID UI (player/caption/controls) → whole widget verbatim in a code block.
		self::register_recognizer( 'image_overlay', 30,
			function ( $el ) { return self::is_image_with_overlay( $el ); },
			function ( $el ) { $doc = $el->ownerDocument; $v = $doc ? self::strip_cs( trim( (string) $doc->saveHTML( $el ) ) ) : ''; return '' !== $v ? array( 't' => 'html', 'role' => 'code', 'html' => '<div class="sc-tw">' . $v . '</div>' ) : null; }
		);
		// A logo / "trusted by" strip (several images, no headings) → whole flex row verbatim in a code block.
		self::register_recognizer( 'logo_strip', 25,
			function ( $el ) { return self::is_logo_strip( $el ); },
			function ( $el ) { $doc = $el->ownerDocument; $v = $doc ? self::strip_cs( trim( (string) $doc->saveHTML( $el ) ) ) : ''; return '' !== $v ? array( 't' => 'html', 'role' => 'code', 'html' => '<div class="sc-tw">' . $v . '</div>' ) : null; }
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
	}

	/**
	 * Walk a section, emitting role-annotated blocks in document order. Each child is offered to the
	 * recognizer registry (highest priority first); the FIRST match claims it (no descent), and its build()
	 * appends a block / list / nothing. Unclaimed elements are descended into (hero text wrappers, button
	 * rows, intro blocks, …) — exactly the original behavior, now table-driven.
	 */
	private static function collect_blocks( $node, array &$blocks, array $rules ) {
		$recognizers = self::recognizers();
		foreach ( $node->childNodes as $child ) {
			if ( XML_ELEMENT_NODE !== $child->nodeType ) { continue; }
			$tag     = strtolower( $child->tagName );
			$claimed = false;
			foreach ( $recognizers as $r ) {
				if ( ! call_user_func( $r['match'], $child, $tag, $rules ) ) { continue; }
				$out = call_user_func( $r['build'], $child, $tag, $rules );
				if ( is_array( $out ) ) {
					if ( isset( $out['t'] ) ) { $blocks[] = $out; }                                                 // one block
					else { foreach ( $out as $blk ) { if ( is_array( $blk ) && isset( $blk['t'] ) ) { $blocks[] = $blk; } } } // a list
				}
				$claimed = true;
				break;
			}
			if ( ! $claimed ) { self::collect_blocks( $child, $blocks, $rules ); }
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
	 * Is a grid child a feature/bento CARD (vs. a CTA button in a flex row)? A card is a non-button
	 * container that holds its own heading — that's what separates a feature card from a button row
	 * (the #1 false positive: a hero/CTA's `flex` button group looks grid-ish but has no headings).
	 *
	 * @param DOMElement $k
	 * @return bool
	 */
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
				$col['html'] = self::clean_block_html( $cell );
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
		$icon = ''; $custom_icon = ''; $icon_box_cls = ''; $icon_box_cs = '';
		foreach ( $cell->getElementsByTagName( 'span' ) as $sp ) {
			if ( strpos( self::cls( $sp ), 'material-symbols' ) !== false ) {
				$icon = self::material_to_fa( trim( $sp->textContent ) );
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
		if ( $icon === '' ) {
			$svg = $cell->getElementsByTagName( 'svg' )->item( 0 );
			if ( $svg && $cell->ownerDocument ) { $custom_icon = (string) $cell->ownerDocument->saveHTML( $svg ); }
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
			'customIcon' => $custom_icon,
			'title'      => self::text( $heading ),
			'titleTag'   => $htag,
			'text'       => $body,
			'button'     => $button, // a CTA inside the card (null when none) — see the column-build rule
			'iconBoxCls'  => $icon_box_cls, // the source's gray icon container classes (if any) → reproduced as a box
			'iconBoxCs'   => $icon_box_cs,
			// Is the source card CENTERED? (a text-center class, or computed text-align:center.) Drives the
			// icon_box left/center alignment so it matches the source instead of the shortcode's centered default.
			'center'     => ( strpos( self::cls( $cell ), 'text-center' ) !== false ) || ( strpos( (string) $cell->getAttribute( 'data-sc-cs' ), 'text-align:center' ) !== false ),
			'cls'        => self::cls( $cell ),               // the card container's classes → CSS Class Mapper (box styling)
			'cs'         => $cell->getAttribute( 'data-sc-cs' ), // its RESOLVED computed style → styling for non-Tailwind sites
			'iconLayout' => 'top-title',
		);
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
		if ( $mirror && class_exists( 'FW_Site_Converter_Tailwind' ) && class_exists( 'FW_Site_Converter_Mapper' ) ) {
			FW_Site_Converter_Mapper::set_style_config( FW_Site_Converter_Tailwind::parse_config( $screens[0]['html'] ) );
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
		if ( $mirror ) {
			$files['theme-design.json'] = self::mirror_design( $files['theme-design.json'], $screens[0]['html'] );
			// Append the box-container semantic rules to the child theme stylesheet.
			if ( class_exists( 'FW_Site_Converter_Mapper' ) ) {
				$boxcss = FW_Site_Converter_Mapper::registered_css();
				if ( $boxcss !== '' ) {
					$cc = isset( $files['theme-design.json']['custom_css'] ) ? (string) $files['theme-design.json']['custom_css'] : '';
					$files['theme-design.json']['custom_css'] = trim( $cc . "\n\n" . $boxcss );
				}
			}
		}
		if ( $pages ) { $files['pages.json'] = array( 'pages' => $pages ); }

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
		$design['custom_css'] = trim( $inline . "\n" . $ms . $adminbar . "/* ---- reproduced Tailwind CSS (offline) ---- */\n" . $tw );
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
		$map = array( 'special_heading' => 'title', 'text_block' => 'text', 'button' => 'button', 'code_block' => 'code', 'media_image' => 'image' );
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
			if ( strpos( $ccls, 'material-symbols' ) !== false ) { if ( $icon === '' ) { $icon = self::material_to_fa( $ctxt ); } continue; }
			if ( $ctag === 'i' ) { if ( $icon === '' ) { $icon = trim( $ccls ); } continue; }
			if ( $tag_text === '' && $ctxt !== '' && mb_strlen( $ctxt ) <= 18 && $nspan >= 2
				&& ( strpos( $ccls, 'rounded-full' ) !== false || strpos( $ccls, 'uppercase' ) !== false || strpos( $ccls, 'bg-' ) !== false ) ) {
				$tag_text = $ctxt; continue;
			}
			if ( $ctxt !== '' ) { $msg[] = $ctxt; }
		}
		$message = trim( implode( ' ', $msg ) );
		if ( $message === '' ) {
			$message = trim( self::text( $el ) );
			if ( $message !== '' && $tag_text !== '' && strpos( $message, $tag_text ) === 0 ) { $message = trim( substr( $message, strlen( $tag_text ) ) ); }
		}
		if ( $message === '' && $tag_text !== '' ) { $message = $tag_text; $tag_text = ''; }
		return array( 'tag_text' => $tag_text, 'message' => $message, 'icon' => $icon, 'link' => $link );
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

	/** A stable section id from the section's first descriptive class, else section-N. */
	private static function section_id( $node, $idx ) {
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
