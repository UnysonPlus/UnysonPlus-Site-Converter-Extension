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
 *      → tokens_to_theme_settings()  → theme-settings.json (misc_custom_css :root vars + fonts)
 *      → scan_images()           → media.json
 *      → html_to_mapping()       → the Mapper's role-annotated mapping → build_pages() → pages.json
 *      → extract_menus()         → menus.json
 *    → build_bundle() assembles the five files; the admin then either imports them
 *      via FW_Site_Converter_Bundle::import_dir() (Tier 1, no AI) or streams them as a
 *      `.zip` for the user to refine pages.json with Claude (Tier 2) and re-upload.
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
	 * `body:not(.wp-admin)` — `misc_custom_css` is absorbed into a combined bundle that also loads in
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
			$lines[] = 'body:not(.wp-admin) h1,body:not(.wp-admin) h2,body:not(.wp-admin) h3,body:not(.wp-admin) h4,body:not(.wp-admin) h5,body:not(.wp-admin) h6{font-family:' . $head_font . ';}';
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
	 * (bundle gotcha #2 — a string fatals the Theme Settings page).
	 *
	 * @param array $tokens
	 * @return array{ values: array }
	 */
	public static function tokens_to_theme_settings( array $tokens ) {
		$css = self::tokens_to_css_vars( $tokens );
		return array( 'values' => array( 'misc_custom_css' => array( 'custom_css' => $css ) ) );
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
	public static function html_to_mapping( $html, $title = 'Home', $slug = '', $front = true ) {
		$rules = self::rules_get();
		$dom   = self::load_dom( (string) $html );
		$sections = array();

		if ( $dom ) {
			$body = $dom->getElementsByTagName( 'body' )->item( 0 );
			$roots = $body ? self::section_roots( $body ) : array();
			$idx = 0;
			foreach ( $roots as $node ) {
				$blocks = array();
				self::collect_blocks( $node, $blocks, $rules );
				$blocks = array_values( array_filter( $blocks ) );
				if ( ! $blocks ) { continue; }
				$sections[] = array(
					'sectionClass' => '',
					'css_id'       => self::section_id( $node, $idx ),
					'omit'         => false,
					'verbatim'     => false,
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
				),
			),
		);
	}

	/**
	 * The top-level "section" nodes to convert: every <section>, plus a <footer> (rendered as a
	 * trailing section). <header> is handled by the menu extractor, not as content.
	 *
	 * @param DOMElement $body
	 * @return DOMElement[]
	 */
	private static function section_roots( $body ) {
		$out = array();
		$main = null;
		foreach ( $body->getElementsByTagName( 'main' ) as $m ) { $main = $m; break; }
		$scope = $main ? $main : $body;
		foreach ( $scope->getElementsByTagName( 'section' ) as $s ) {
			// Only top-level sections (not a <section> nested inside another we'll already emit).
			if ( ! self::has_ancestor_tag( $s, 'section', $scope ) ) { $out[] = $s; }
		}
		foreach ( $body->getElementsByTagName( 'footer' ) as $f ) { $out[] = $f; break; }
		return $out;
	}

	/**
	 * Walk a section, emitting role-annotated blocks in document order. Recognized composites
	 * (a grid-of-cards, a pill, a button, an icon-card) are emitted as a unit and NOT descended.
	 *
	 * @param DOMElement $node
	 * @param array      $blocks (by ref)
	 * @param array      $rules  learned local rules
	 */
	private static function collect_blocks( $node, array &$blocks, array $rules ) {
		foreach ( $node->childNodes as $child ) {
			if ( $child->nodeType !== XML_ELEMENT_NODE ) { continue; }
			$tag = strtolower( $child->tagName );
			$cls = self::cls( $child );

			// A card-grid → one "columns" block (each cell → an icon_box / text / code cell).
			if ( self::is_card_grid( $child ) ) {
				$cols = self::grid_cols( $child );
				if ( $cols ) { $blocks[] = array( 't' => 'row', 'role' => 'columns', 'valign' => '', 'cols' => $cols ); }
				continue;
			}

			// Headings.
			if ( preg_match( '/^h([1-6])$/', $tag, $hm ) ) {
				$level = (int) $hm[1];
				$blocks[] = array(
					't'     => 'heading',
					'role'  => self::rule_role( $rules, $child, $level <= 2 ? 'title' : 'heading' ),
					'level' => $level,
					'cls'   => '',
					'text'  => self::text( $child ),
					'html'  => self::clean_inline_html( $child ),
				);
				continue;
			}

			// Pill / eyebrow → overline.
			if ( self::is_pill( $child ) ) {
				$blocks[] = array( 't' => 'text', 'role' => self::rule_role( $rules, $child, 'overline' ), 'cls' => 'text-uppercase', 'text' => self::text( $child ) );
				continue;
			}

			// Buttons / CTA links.
			if ( self::is_button( $child ) ) {
				$blocks[] = self::button_block( $child, $rules );
				continue;
			}

			// Paragraph.
			if ( $tag === 'p' ) {
				$txt = self::text( $child );
				if ( $txt !== '' ) {
					$blocks[] = array( 't' => 'text', 'role' => self::rule_role( $rules, $child, 'text' ), 'cls' => '', 'text' => $txt, 'html' => '<p>' . self::clean_inline_html( $child ) . '</p>' );
				}
				continue;
			}

			// Standalone image (a showcase/illustration) → verbatim <img> code block.
			if ( $tag === 'img' ) {
				$blocks[] = array( 't' => 'image', 'role' => 'image', 'html' => self::img_html( $child ) );
				continue;
			}

			// A wrapper that directly holds a lone image → emit the image (skip the Tailwind chrome).
			if ( self::is_image_wrapper( $child ) ) {
				$img = $child->getElementsByTagName( 'img' )->item( 0 );
				if ( $img ) { $blocks[] = array( 't' => 'image', 'role' => 'image', 'html' => self::img_html( $img ) ); }
				continue;
			}

			// Otherwise descend (hero text wrappers, button rows, intro blocks, etc.).
			self::collect_blocks( $child, $blocks, $rules );
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
		$icon = ''; $custom_icon = '';
		foreach ( $cell->getElementsByTagName( 'span' ) as $sp ) {
			if ( strpos( self::cls( $sp ), 'material-symbols' ) !== false ) {
				$icon = self::material_to_fa( trim( $sp->textContent ) );
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

		return array(
			'icon'       => $icon,
			'customIcon' => $custom_icon,
			'title'      => self::text( $heading ),
			'titleTag'   => $htag,
			'text'       => $body,
			'cls'        => '',
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
		return array(
			't'      => 'button',
			'role'   => self::rule_role( $rules, $el, 'button' ),
			'label'  => $label !== '' ? $label : 'Button',
			'href'   => $href,
			'cls'    => $cls,
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

		$header = $dom->getElementsByTagName( 'header' )->item( 0 );
		$primary = $header ? self::links_in( $header ) : array();
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

	/** Flat list of {label,url} for the <nav> (or all anchors) inside $scope. */
	private static function links_in( $scope ) {
		$nav = $scope->getElementsByTagName( 'nav' )->item( 0 );
		$host = $nav ? $nav : $scope;
		$out = array();
		$seen = array();
		foreach ( $host->getElementsByTagName( 'a' ) as $a ) {
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

		// Media + menus from across all screens.
		$urls = array();
		$menus = array();
		$pages = array();
		$mapping_all = array( 'include_animations' => false, 'pages' => array() );

		foreach ( $screens as $i => $sc ) {
			$urls = array_merge( $urls, self::scan_images( $sc['html'] ) );
			$map  = self::html_to_mapping( $sc['html'], $sc['title'], $sc['slug'], $sc['front'] );
			$mapping_all['pages'] = array_merge( $mapping_all['pages'], $map['pages'] );
			if ( $i === 0 ) { // menus from the home screen's chrome
				$menus = self::extract_menus( $sc['html'] );
			}
		}
		$urls = array_values( array_unique( array_filter( $urls ) ) );

		$out['mapping'] = $mapping_all;
		$pages = class_exists( 'FW_Site_Converter_Mapper' ) ? FW_Site_Converter_Mapper::build_pages( $mapping_all ) : array();

		// Assemble the five bundle files (only non-empty ones).
		$files = array();
		$files['bundle.json'] = array( 'name' => 'Google Stitch import', 'source' => 'stitch', 'generated' => '' );
		if ( $urls )  { $files['media.json'] = array( 'urls' => $urls ); }
		$ts = self::tokens_to_theme_settings( $tokens );
		if ( ! empty( $ts['values']['misc_custom_css']['custom_css'] ) ) { $files['theme-settings.json'] = $ts; }
		if ( $pages ) { $files['pages.json'] = array( 'pages' => $pages ); }
		if ( ! empty( $menus['menus'] ) ) { $files['menus.json'] = $menus; }

		$out['files']   = $files;
		$out['screens'] = count( $screens );
		return $out;
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
		return is_array( $r ) ? $r : array();
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

	/** A button or a button-styled link. */
	private static function is_button( $el ) {
		$tag = strtolower( $el->tagName );
		if ( $tag === 'button' ) { return true; }
		if ( $tag !== 'a' ) { return false; }
		$cls = self::cls( $el );
		// A CTA link: pill/box padding + a fill/border that reads as a button (not a nav link).
		return ( strpos( $cls, 'rounded' ) !== false && ( strpos( $cls, 'bg-' ) !== false || strpos( $cls, 'border' ) !== false ) && strpos( $cls, 'px-' ) !== false );
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
		return trim( $h );
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
		// Prefer the responsive (md:) count, else the base.
		if ( preg_match( '/(?:md|lg):grid-cols-(\d{1,2})/', $cls, $m ) ) { return (int) $m[1]; }
		if ( preg_match( '/grid-cols-(\d{1,2})/', $cls, $m ) ) { return (int) $m[1]; }
		return 0;
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
			$screens[] = array( 'html' => (string) file_get_contents( $dir . '/code.html' ), 'title' => self::title_from_dir( $dir ), 'slug' => '', 'front' => true );
			if ( is_file( $dir . '/DESIGN.md' ) ) { $design_md = (string) file_get_contents( $dir . '/DESIGN.md' ); }
			return array( $screens, $design_md );
		}

		// Multi-screen: one subfolder per screen + top-level <system>/DESIGN.md.
		$subs = glob( $dir . '/*', GLOB_ONLYDIR );
		$first = true;
		foreach ( (array) $subs as $sub ) {
			if ( is_file( $sub . '/code.html' ) ) {
				$screens[] = array(
					'html'  => (string) file_get_contents( $sub . '/code.html' ),
					'title' => self::title_from_dir( $sub ),
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
		return $name !== '' ? ucwords( $name ) : 'Home';
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
