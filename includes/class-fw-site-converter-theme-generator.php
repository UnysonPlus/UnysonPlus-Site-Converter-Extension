<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Theme generator — the header/footer conversion engine.
 *
 * Turns a design config (the chrome half of a capture: header layout, CTA, fonts,
 * colors, footer, carried CSS) into a real WordPress theme that reproduces the
 * source site's header + footer **design** — never its content. The logo is always
 * the site's own (custom_logo → Site Title), the footer brand is the Site Title;
 * only stylings, structure (logo-left / nav-center / CTA-right …) and the carried
 * CSS are copied.
 *
 * Two modes, both still driven by the Unyson+ plugin + page builder:
 *
 *   - 'child'      — a child of unysonplus-theme. Ships 4 files (style.css with a
 *                    `Template:` header, functions.php, and the two overridden
 *                    template-parts). Depends on the parent for everything else.
 *   - 'standalone' — a self-contained theme: a copy of the parent tree, de-parented
 *                    (no `Template:` line), with the generated chrome overlaid and a
 *                    single generated include appended to its functions.php. "Like
 *                    the child but with the other files needed to be a full theme."
 *
 * Output is either installed straight into wp-content/themes/<slug> or assembled
 * into a downloadable .zip. The admin page (Unyson+ → Convert) drives both.
 */
class FW_Site_Converter_Theme_Generator {

	/** Parent theme this converter targets (the Unyson+ parent). */
	const PARENT_TEMPLATE = 'unysonplus-theme';

	/**
	 * Normalize a (possibly sparse) design config to the full shape, applying
	 * sensible defaults. Lenient on purpose — an agent or the capture tool can hand
	 * us just the parts it knows.
	 *
	 * @param array $c Raw config.
	 * @return array Normalized config.
	 */
	public static function normalize( array $c ) {
		// Raw-chrome mirror travels under `chrome` on a raw capture, `raw_chrome` on a
		// design-config — grab it before from_capture() rewrites $c.
		$raw_chrome_src = isset( $c['chrome'] ) && is_array( $c['chrome'] )
			? $c['chrome']
			: ( isset( $c['raw_chrome'] ) && is_array( $c['raw_chrome'] ) ? $c['raw_chrome'] : array() );

		// Accept a raw design-capture payload (tools/design-capture/capture.mjs)
		// directly — map it to a design-config first, copying stylings only.
		if ( self::is_capture( $c ) ) {
			$c = self::from_capture( $c );
		}

		$theme = isset( $c['theme'] ) && is_array( $c['theme'] ) ? $c['theme'] : array();
		$name  = isset( $theme['name'] ) && $theme['name'] !== '' ? (string) $theme['name'] : 'Converted Site';
		$slug  = isset( $theme['slug'] ) && $theme['slug'] !== '' ? sanitize_title( $theme['slug'] ) : sanitize_title( $name );
		if ( $slug === '' ) {
			$slug = 'converted-site';
		}
		$mode = isset( $theme['mode'] ) && $theme['mode'] === 'standalone' ? 'standalone' : 'child';

		$fonts  = isset( $c['fonts'] ) && is_array( $c['fonts'] ) ? $c['fonts'] : array();
		$colors = isset( $c['colors'] ) && is_array( $c['colors'] ) ? $c['colors'] : array();
		$layout = isset( $c['layout'] ) && is_array( $c['layout'] ) ? $c['layout'] : array();
		$header = isset( $c['header'] ) && is_array( $c['header'] ) ? $c['header'] : array();
		$footer = isset( $c['footer'] ) && is_array( $c['footer'] ) ? $c['footer'] : array();
		$bg     = isset( $c['background'] ) && is_array( $c['background'] ) ? $c['background'] : array();
		$cta    = isset( $header['cta'] ) && is_array( $header['cta'] ) ? $header['cta'] : array();
		$logo   = isset( $header['logo'] ) && is_array( $header['logo'] ) ? $header['logo'] : array();
		$cstyle = isset( $cta['style'] ) && is_array( $cta['style'] ) ? $cta['style'] : array();
		$hero   = isset( $c['hero'] ) && is_array( $c['hero'] ) ? $c['hero'] : array();
		$hpat   = isset( $hero['pattern'] ) && is_array( $hero['pattern'] ) ? $hero['pattern'] : array();

		return array(
			'theme' => array(
				'name'        => $name,
				'slug'        => $slug,
				'mode'        => $mode,
				'author'      => isset( $theme['author'] ) && $theme['author'] !== '' ? (string) $theme['author'] : 'Site Converter',
				'description' => isset( $theme['description'] ) && $theme['description'] !== ''
					? (string) $theme['description']
					: sprintf( 'Converted theme for %s — header/footer design reproduced by the Unyson+ Site Converter.', $name ),
				'version'     => isset( $theme['version'] ) && $theme['version'] !== '' ? (string) $theme['version'] : '1.0.0',
			),
			'layout' => array(
				// Source content-container width (Bootstrap .container / Tailwind max-w-* / any
				// centered wrapper) → applied to .fw-container. Validated to a CSS length.
				'container_max' => ( isset( $layout['container_max'] ) && preg_match( '/^\d+(\.\d+)?(px|rem|em|vw|%)$/', (string) $layout['container_max'] ) ) ? (string) $layout['container_max'] : '',
			),
			'fonts' => array(
				'heading'        => isset( $fonts['heading'] ) && $fonts['heading'] !== '' ? (string) $fonts['heading'] : '',
				'heading_weight' => self::css_weight( isset( $fonts['heading_weight'] ) ? $fonts['heading_weight'] : '' ),
				'body'    => isset( $fonts['body'] ) && $fonts['body'] !== '' ? (string) $fonts['body'] : '',
				'google'  => isset( $fonts['google'] ) && $fonts['google'] !== '' ? esc_url_raw( (string) $fonts['google'] ) : '',
				// The source's icon webfont (Material Symbols) — loaded when the converted
				// page uses icon-ligature cards.
				'icons'   => isset( $fonts['icons'] ) && $fonts['icons'] !== '' ? esc_url_raw( (string) $fonts['icons'] ) : '',
			),
			'colors' => array(
				'ink'         => self::color( $colors, 'ink', '#1a1a1a' ),
				'accent'      => self::color( $colors, 'accent', '#2563eb' ),
				'bg'          => self::color( $colors, 'bg', '#ffffff' ),
				'heading'     => self::color( $colors, 'heading', '' ),
				'header_bg'   => self::color( $colors, 'header_bg', 'rgba(255,255,255,.85)' ),
				'header_brd'  => self::color( $colors, 'header_border', '#ececec' ),
				'footer_bg'   => self::color( $colors, 'footer_bg', '#111111' ),
				'footer_text' => self::color( $colors, 'footer_text', '#f5f5f5' ),
			),
			'header' => array(
				'layout'        => isset( $header['layout'] ) ? sanitize_text_field( (string) $header['layout'] ) : 'logo-left-nav-center-cta-right',
				'style'         => in_array( isset( $header['style'] ) ? $header['style'] : '', array( 'pill', 'bar', 'minimal' ), true ) ? $header['style'] : 'bar',
				'menu_location' => isset( $header['menu_location'] ) && $header['menu_location'] !== '' ? sanitize_key( $header['menu_location'] ) : 'primary',
				'sticky'        => ! empty( $header['sticky'] ),
				// Captured top nav → an editable menu (auto-built on activation).
				'menu'          => self::norm_menu_items( isset( $header['menu'] ) ? $header['menu'] : array() ),
				// Logo styling copied from the source's logo (applied to the site's OWN
				// text logo). Empty members fall back to the heading font / ink defaults.
				'logo'          => array(
					'font'           => isset( $logo['font'] ) && $logo['font'] !== '' ? (string) $logo['font'] : '',
					'size'           => self::css_len( isset( $logo['size'] ) ? $logo['size'] : '' ),
					'weight'         => self::css_weight( isset( $logo['weight'] ) ? $logo['weight'] : '' ),
					'color'          => self::color( $logo, 'color', '' ),
					'letter_spacing' => self::css_len( isset( $logo['letter_spacing'] ) ? $logo['letter_spacing'] : '' ),
				),
				'cta'           => array(
					'enabled'          => isset( $cta['enabled'] ) ? ! empty( $cta['enabled'] ) : ( isset( $cta['label'] ) && $cta['label'] !== '' ),
					'label'            => isset( $cta['label'] ) && $cta['label'] !== '' ? sanitize_text_field( (string) $cta['label'] ) : 'Get started',
					'href'             => isset( $cta['href'] ) && $cta['href'] !== '' ? (string) $cta['href'] : '/#get-started',
					'dedupe_from_menu' => ! empty( $cta['dedupe_from_menu'] ),
					// Button styling copied faithfully from the source button.
					'style'            => array(
						'bg'          => self::color( $cstyle, 'bg', '' ),
						'color'       => self::color( $cstyle, 'color', '' ),
						'radius'      => self::clamp_radius( isset( $cstyle['radius'] ) ? $cstyle['radius'] : '' ),
						'padding'     => self::css_box( isset( $cstyle['padding'] ) ? $cstyle['padding'] : '' ),
						'font_weight' => self::css_weight( isset( $cstyle['font_weight'] ) ? $cstyle['font_weight'] : '' ),
					),
				),
			),
			'footer' => array(
				'widget_area' => isset( $footer['widget_area'] ) ? ! empty( $footer['widget_area'] ) : true,
				'brand'       => isset( $footer['brand'] ) ? ! empty( $footer['brand'] ) : true, // brand = Site Title
				'copyright'   => isset( $footer['copyright'] ) && $footer['copyright'] !== '' ? sanitize_text_field( (string) $footer['copyright'] ) : 'All rights reserved.',
				// Footer link columns → an editable WordPress menu (auto-built on activation).
				'menu'        => self::norm_menu_items( isset( $footer['menu'] ) ? $footer['menu'] : array() ),
				// Social links (rendered in the footer template from config).
				'social'      => self::norm_links( isset( $footer['social'] ) ? $footer['social'] : array() ),
			),
			'background' => array(
				'dotted'    => ! empty( $bg['dotted'] ),
				'dot_color' => self::color( $bg, 'dot_color', '#e7e1d4' ),
				'canvas'    => self::color( $bg, 'canvas', '' ),
			),
			// Hero decorative pattern overlay (the source's faint "+" grid, etc.).
			'hero' => array(
				'pattern' => array(
					'image'   => self::css_pattern( isset( $hpat['image'] ) ? $hpat['image'] : '' ),
					'repeat'  => self::css_repeat( isset( $hpat['repeat'] ) ? $hpat['repeat'] : 'repeat' ),
					'opacity' => self::css_opacity( isset( $hpat['opacity'] ) ? $hpat['opacity'] : 1 ),
				),
			),
			'custom_css' => isset( $c['custom_css'] ) ? (string) $c['custom_css'] : '',
			// AI-authored design: a complete self-contained stylesheet (lands in `custom_css`) PLUS the
			// header/footer markup with placeholders. When `ai_authored`, the generator drops its own
			// deterministic palette/font/header CSS and lets the AI stylesheet own the whole look, and
			// builds the chrome parts from `ai_header_html` / `ai_footer_html`.
			'ai_authored'    => ! empty( $c['ai_authored'] ),
			'ai_header_html' => isset( $c['ai_header_html'] ) ? (string) $c['ai_header_html'] : '',
			'ai_footer_html' => isset( $c['ai_footer_html'] ) ? (string) $c['ai_footer_html'] : '',
			// "Capture header/footer" off → the generated child theme renders no chrome there.
			'skip_header'    => ! empty( $c['skip_header'] ),
			'skip_footer'    => ! empty( $c['skip_footer'] ),
			// Faithful-mirror theme: the page content carries the source's WHOLE body verbatim (header +
			// sections + footer) + its reproduced CSS, so the theme renders only the document shell.
			'mirror'         => ! empty( $c['mirror'] ),
			'font_links'     => ( isset( $c['font_links'] ) && is_array( $c['font_links'] ) ) ? array_values( array_filter( array_map( 'strval', $c['font_links'] ) ) ) : array(),
			// Faithful-mirror chrome: the source's <header>/<footer> markup verbatim, rendered STATIC in
			// header.php/footer.php (wrapped in .sc-tw so the reproduced CSS styles them exactly).
			'mirror_header_html' => isset( $c['mirror_header_html'] ) ? self::localize_media( (string) $c['mirror_header_html'] ) : '',
			'mirror_footer_html' => isset( $c['mirror_footer_html'] ) ? self::localize_media( (string) $c['mirror_footer_html'] ) : '',
			// Verbatim header/footer HTML + the matching CSS captured from the source. When
			// present these override the rebuilt template parts so the chrome is reproduced
			// pixel-for-pixel (the "grab the static HTML + CSS" path).
			'raw_chrome' => array(
				// Localize images to the imported Media Library attachments (chrome header logo,
				// footer graphics, CSS background-images). A no-op until media is imported, so
				// the standalone "Generate theme" path is unaffected.
				'header_html' => self::localize_media( isset( $raw_chrome_src['header_html'] ) ? (string) $raw_chrome_src['header_html'] : '' ),
				'footer_html' => self::localize_media( isset( $raw_chrome_src['footer_html'] ) ? (string) $raw_chrome_src['footer_html'] : '' ),
				// Categorized CSS groups — written into style.css in a clean, labeled order
				// (base → utilities → header → sections → footer). `css` kept for older bundles.
				'base_css'    => self::localize_media( isset( $raw_chrome_src['base_css'] ) ? (string) $raw_chrome_src['base_css'] : '' ),
				'util_css'    => self::localize_media( isset( $raw_chrome_src['util_css'] ) ? (string) $raw_chrome_src['util_css'] : '' ),
				'header_css'  => self::localize_media( isset( $raw_chrome_src['header_css'] ) ? (string) $raw_chrome_src['header_css'] : '' ),
				'footer_css'  => self::localize_media( isset( $raw_chrome_src['footer_css'] ) ? (string) $raw_chrome_src['footer_css'] : '' ),
				'css'         => self::localize_media( isset( $raw_chrome_src['css'] ) ? (string) $raw_chrome_src['css'] : '' ),
				'linked_css'  => self::norm_linked_css( isset( $raw_chrome_src['linked_css'] ) ? $raw_chrome_src['linked_css'] : array() ),
				// Navigation mapper output: the source menu as a portable tree (built into a real WP
				// menu) + the source nav's computed look (drives the .sc-menu CSS). The header HTML
				// carries an <!--SC_NAV--> marker where the live wp_nav_menu is dropped in.
				'nav_tree'    => ( isset( $raw_chrome_src['nav_tree'] ) && is_array( $raw_chrome_src['nav_tree'] ) ) ? $raw_chrome_src['nav_tree'] : array(),
				'nav_style'   => ( isset( $raw_chrome_src['nav_style'] ) && is_array( $raw_chrome_src['nav_style'] ) ) ? $raw_chrome_src['nav_style'] : array(),
				// Footer mapper output: each column's .widget HTML (→ footer-N widget areas as Custom
				// HTML placeholders) + the copyright HTML (→ a child Footer Copyright widget area).
				'footer_cols' => self::localize_media_list( isset( $raw_chrome_src['footer_cols'] ) ? $raw_chrome_src['footer_cols'] : array() ),
				'footer_copyright' => self::localize_media( isset( $raw_chrome_src['footer_copyright'] ) ? (string) $raw_chrome_src['footer_copyright'] : '' ),
			),
		);
	}

	/** Re-point source image URLs to imported Media Library attachments (HTML or CSS). */
	private static function localize_media( $content ) {
		return class_exists( 'FW_Site_Converter_Media' )
			? FW_Site_Converter_Media::localize( $content )
			: $content;
	}

	/** localize_media() over a list of HTML strings (footer column placeholders). */
	private static function localize_media_list( $list ) {
		if ( ! is_array( $list ) ) { return array(); }
		$out = array();
		foreach ( $list as $html ) { $out[] = self::localize_media( (string) $html ); }
		return $out;
	}

	/** Sanitize a list of stylesheet URLs (cross-origin sheets to re-link in the theme). */
	private static function norm_linked_css( $list ) {
		$out = array();
		if ( is_array( $list ) ) {
			foreach ( $list as $u ) {
				$u = esc_url_raw( (string) $u );
				if ( '' !== $u && ! in_array( $u, $out, true ) ) {
					$out[] = $u;
				}
			}
		}
		return $out;
	}

	/* ---------------------------------------------------------------------- *
	 * Capture → design-config mapping (the capture → generator path)
	 * ---------------------------------------------------------------------- */

	/**
	 * Is this a raw design-capture payload (from tools/design-capture/capture.mjs)
	 * rather than a ready design-config? A capture carries `tokens` / `assets`, or a
	 * `header.nav` array (a design-config's header has no `nav`).
	 *
	 * @param array $c
	 * @return bool
	 */
	public static function is_capture( array $c ) {
		if ( isset( $c['tokens'] ) || isset( $c['assets'] ) ) {
			return true;
		}
		return isset( $c['header']['nav'] ) && is_array( $c['header']['nav'] );
	}

	/**
	 * Map a design-capture payload → the generator's design-config. Copies STYLINGS
	 * only — fonts, colors, header/footer structure. The logo and brand are never
	 * taken from the capture (they stay the site's own Site Logo / Site Title at
	 * render time). Any `theme.*` (mode / name / slug) already on the payload — e.g.
	 * the mode the admin radio folded in — is preserved.
	 *
	 * @param array $cap
	 * @return array design-config
	 */
	public static function from_capture( array $cap ) {
		$tokens = isset( $cap['tokens'] ) && is_array( $cap['tokens'] ) ? $cap['tokens'] : array();
		$vars   = isset( $tokens['vars'] ) && is_array( $tokens['vars'] ) ? $tokens['vars'] : array();
		$body   = isset( $tokens['body'] ) && is_array( $tokens['body'] ) ? $tokens['body'] : array();
		$head   = isset( $cap['header'] ) && is_array( $cap['header'] ) ? $cap['header'] : array();
		$foot   = isset( $cap['footer'] ) && is_array( $cap['footer'] ) ? $cap['footer'] : array();
		$assets = isset( $cap['assets'] ) && is_array( $cap['assets'] ) ? $cap['assets'] : array();
		$origin = self::origin( isset( $cap['url'] ) ? (string) $cap['url'] : '' );

		// Fonts — heading from the logo / section headings, body from <body>.
		$logo         = isset( $head['logo'] ) && is_array( $head['logo'] ) ? $head['logo'] : array();
		$heading_face = isset( $logo['computed']['fontFamily'] ) ? $logo['computed']['fontFamily'] : self::section_heading_face( $cap );
		$heading_font = self::first_family( $heading_face );
		$body_font    = self::first_family( isset( $body['fontFamily'] ) ? $body['fontFamily'] : '' );
		$google       = self::pick_google_fonts(
			isset( $assets['fonts'] ) ? (array) $assets['fonts'] : array(),
			array( $heading_font, $body_font )
		);

		// Icon webfont (Material Symbols) — only carried when the page uses icon-ligature
		// cards, so a non-icon site doesn't load it for nothing.
		$icons_url  = '';
		$uses_icons = false;
		if ( isset( $cap['sections'] ) && is_array( $cap['sections'] ) ) {
			foreach ( $cap['sections'] as $s ) {
				if ( ! isset( $s['cards'] ) || ! is_array( $s['cards'] ) ) {
					continue;
				}
				foreach ( $s['cards'] as $c ) {
					$ic = isset( $c['icon'] ) ? (string) $c['icon'] : '';
					if ( $ic !== '' && $ic !== 'svg' && preg_match( '/^[a-z][a-z_]+$/', $ic ) ) {
						$uses_icons = true;
						break 2;
					}
				}
			}
		}
		if ( $uses_icons ) {
			foreach ( ( isset( $assets['fonts'] ) ? (array) $assets['fonts'] : array() ) as $u ) {
				if ( stripos( (string) $u, 'Material+Symbols' ) !== false ) {
					$icons_url = esc_url_raw( (string) $u );
					break;
				}
			}
		}

		// Colors — pass captured values through (oklch/oklab accepted by color()).
		$ink       = self::nz( isset( $body['color'] ) ? $body['color'] : '' );
		$bg        = self::nz( isset( $body['backgroundColor'] ) ? $body['backgroundColor'] : '' );
		// Accent / brand color. Sites that bundle Bootstrap ship `--primary:#007bff` (BS4) /
		// `#0d6efd` (BS5) as the DEFAULT and override the real brand color only on custom classes
		// (e.g. a gold CTA button). When `--primary` is exactly a Bootstrap default AND the CTA
		// carries a non-neutral color, trust the CTA. (Mirror of to-design-config.mjs.)
		$css_primary = self::nz( isset( $vars['--primary'] ) ? $vars['--primary'] : '' );
		$brand       = self::nz( isset( $tokens['brandColor'] ) ? $tokens['brandColor'] : '' );
		$cta_bg      = self::nz( isset( $head['cta']['computed']['backgroundColor'] ) ? $head['cta']['computed']['backgroundColor'] : '' );
		$nav_color   = self::nz( isset( $head['nav'][0]['computed']['color'] ) ? $head['nav'][0]['computed']['color'] : '' );
		// Page-wide brand color (dominant non-neutral button fill) is the most reliable signal;
		// fall back to the header CTA (which may be a transparent text link), then nav color.
		$brand_pick = ( '' !== $brand && ! self::is_neutral( $brand ) )
			? $brand
			: ( ( '' !== $cta_bg && ! self::is_neutral( $cta_bg ) ) ? $cta_bg : '' );
		$bs_default = in_array( strtolower( self::to_hex( $css_primary ) ), array( '#007bff', '#0d6efd' ), true );
		if ( $bs_default && '' !== $brand_pick ) {
			$accent = $brand_pick;
		} elseif ( '' !== $css_primary ) {
			$accent = $css_primary;
		} elseif ( '' !== $brand_pick ) {
			$accent = $brand_pick;
		} elseif ( '' !== $nav_color ) {
			$accent = $nav_color;
		} else {
			$accent = $cta_bg;
		}
		$header_bg = self::nz( isset( $head['bar']['backgroundColor'] ) ? $head['bar']['backgroundColor'] : '' );
		$footer_bg = self::nz( isset( $foot['computed']['backgroundColor'] ) ? $foot['computed']['backgroundColor'] : '' );
		$footer_tx = self::nz( isset( $foot['computed']['color'] ) ? $foot['computed']['color'] : '' );

		// Header structure.
		$pos    = isset( $head['element']['position'] ) ? $head['element']['position'] : '';
		$sticky = in_array( $pos, array( 'fixed', 'sticky' ), true );
		$cta    = isset( $head['cta'] ) && is_array( $head['cta'] ) ? $head['cta'] : array();
		$label  = isset( $cta['label'] ) ? trim( (string) $cta['label'] ) : '';

		$nav_labels = array();
		if ( isset( $head['nav'] ) && is_array( $head['nav'] ) ) {
			foreach ( $head['nav'] as $n ) {
				if ( isset( $n['label'] ) ) {
					$nav_labels[] = strtolower( trim( (string) $n['label'] ) );
				}
			}
		}
		$dedupe = $label !== '' && in_array( strtolower( $label ), $nav_labels, true );

		// Header nav → menu items (de-branded hrefs). Drop the CTA label (it's the button).
		$header_menu = array();
		if ( isset( $head['nav'] ) && is_array( $head['nav'] ) ) {
			foreach ( $head['nav'] as $n ) {
				$lbl = isset( $n['label'] ) ? trim( (string) $n['label'] ) : '';
				if ( $lbl === '' ) {
					continue;
				}
				if ( $label !== '' && strcasecmp( $lbl, $label ) === 0 ) {
					continue;
				}
				$header_menu[] = array( 'label' => $lbl, 'url' => self::localize_href( isset( $n['href'] ) ? (string) $n['href'] : '', $origin ) );
			}
		}

		$nonempty_fn = function ( $v ) {
			return $v !== '' && $v !== null;
		};

		// Logo styling — text logos only (image logos keep defaults + the Site Logo).
		$logo_style = array();
		if ( ( ! isset( $logo['type'] ) || $logo['type'] === 'text' ) && isset( $logo['computed'] ) && is_array( $logo['computed'] ) ) {
			$lc         = $logo['computed'];
			$logo_style = array_filter( array(
				'font'           => self::first_family( isset( $lc['fontFamily'] ) ? $lc['fontFamily'] : '' ),
				'size'           => isset( $lc['fontSize'] ) ? (string) $lc['fontSize'] : '',
				'weight'         => isset( $lc['fontWeight'] ) ? (string) $lc['fontWeight'] : '',
				'color'          => self::nz( isset( $lc['color'] ) ? $lc['color'] : '' ),
				'letter_spacing' => ( isset( $lc['letterSpacing'] ) && $lc['letterSpacing'] !== 'normal' ) ? (string) $lc['letterSpacing'] : '',
			), $nonempty_fn );
		}

		// Button styling — copied from the source CTA button's computed style.
		$cta_style = array();
		if ( isset( $cta['computed'] ) && is_array( $cta['computed'] ) ) {
			$cc        = $cta['computed'];
			$cta_style = array_filter( array(
				'bg'          => self::nz( isset( $cc['backgroundColor'] ) ? $cc['backgroundColor'] : '' ),
				'color'       => self::nz( isset( $cc['color'] ) ? $cc['color'] : '' ),
				'radius'      => isset( $cc['borderRadius'] ) ? (string) $cc['borderRadius'] : '', // clamped in normalize()
				'padding'     => isset( $cc['padding'] ) ? (string) $cc['padding'] : '',
				'font_weight' => isset( $cc['fontWeight'] ) ? (string) $cc['fontWeight'] : '',
			), $nonempty_fn );
		}

		// Footer content — copied as an editable starting point (the user refines it).
		// Link columns → menu items (group title → top-level item with the links as
		// children; un-titled groups / flat links → top-level items). Hrefs de-branded.
		$footer_menu = array();
		if ( isset( $foot['groups'] ) && is_array( $foot['groups'] ) && $foot['groups'] ) {
			foreach ( $foot['groups'] as $g ) {
				$title = isset( $g['title'] ) ? trim( (string) $g['title'] ) : '';
				$links = array();
				if ( isset( $g['links'] ) && is_array( $g['links'] ) ) {
					foreach ( $g['links'] as $l ) {
						$links[] = array(
							'label' => isset( $l['label'] ) ? (string) $l['label'] : '',
							'url'   => self::localize_href( isset( $l['href'] ) ? (string) $l['href'] : '', $origin ),
						);
					}
				}
				if ( $title !== '' ) {
					$footer_menu[] = array( 'label' => $title, 'url' => '#', 'children' => $links );
				} else {
					$footer_menu = array_merge( $footer_menu, $links );
				}
			}
		} elseif ( isset( $foot['links'] ) && is_array( $foot['links'] ) ) {
			foreach ( $foot['links'] as $l ) {
				$footer_menu[] = array(
					'label' => isset( $l['label'] ) ? (string) $l['label'] : '',
					'url'   => self::localize_href( isset( $l['href'] ) ? (string) $l['href'] : '', $origin ),
				);
			}
		}

		$footer_social = array();
		if ( isset( $foot['social'] ) && is_array( $foot['social'] ) ) {
			foreach ( $foot['social'] as $s ) {
				$lbl = isset( $s['label'] ) ? trim( (string) $s['label'] ) : '';
				if ( $lbl === '' ) {
					continue;
				}
				$footer_social[] = array( 'label' => $lbl, 'url' => self::localize_href( isset( $s['href'] ) ? (string) $s['href'] : '', $origin ) );
			}
		}

		$footer_copy = self::tagline_from_copyright( isset( $foot['copyright'] ) ? (string) $foot['copyright'] : '' );

		// Hero decorative pattern — the first section that carries one (the hero).
		$hero_pattern = null;
		if ( isset( $cap['sections'] ) && is_array( $cap['sections'] ) ) {
			foreach ( $cap['sections'] as $s ) {
				if ( isset( $s['bgPattern'] ) && is_array( $s['bgPattern'] ) && ! empty( $s['bgPattern']['image'] ) ) {
					$hero_pattern = $s['bgPattern'];
					break;
				}
			}
		}

		// Theme meta — provided values win; otherwise default the name from the page
		// title (just the theme's display name; the rendered logo is still the site's).
		$provided = isset( $cap['theme'] ) && is_array( $cap['theme'] ) ? $cap['theme'] : array();
		$def_name = self::title_to_name( isset( $cap['title'] ) ? (string) $cap['title'] : '' );

		$nonempty = function ( $v ) {
			return $v !== '' && $v !== null;
		};

		return array(
			'theme' => array(
				'name' => isset( $provided['name'] ) && $provided['name'] !== '' ? (string) $provided['name'] : $def_name,
				'slug' => isset( $provided['slug'] ) && $provided['slug'] !== '' ? (string) $provided['slug'] : sanitize_title( $def_name ),
				'mode' => isset( $provided['mode'] ) && $provided['mode'] === 'standalone' ? 'standalone' : 'child',
			),
			'fonts' => array_filter( array(
				'heading' => $heading_font,
				'body'    => $body_font,
				'google'  => $google,
				'icons'   => $icons_url,
			), $nonempty ),
			'colors' => array_filter( array(
				'ink'         => $ink,
				'accent'      => $accent,
				'bg'          => $bg,
				'header_bg'   => $header_bg,
				'footer_bg'   => $footer_bg,
				'footer_text' => $footer_tx,
			), $nonempty ),
			'header' => array(
				'style'         => 'bar',
				'menu_location' => 'primary',
				'sticky'        => $sticky,
				'menu'          => $header_menu,
				'logo'          => $logo_style,
				// Source logo IMAGE url (text logos have none) → set as the custom logo on
				// activation, matched to the imported attachment by its source-url postmeta.
				'logo_src'      => ( isset( $logo['type'] ) && $logo['type'] === 'image' && ! empty( $logo['src'] ) ) ? (string) $logo['src'] : '',
				'cta'           => array(
					'enabled'          => $label !== '',
					'label'            => $label !== '' ? $label : 'Get started',
					'href'             => self::localize_href( isset( $cta['href'] ) ? (string) $cta['href'] : '', $origin ),
					'dedupe_from_menu' => $dedupe,
					'style'            => $cta_style,
				),
			),
			'footer'     => array(
				'widget_area' => true,
				'brand'       => true,
				'copyright'   => $footer_copy, // '' → normalize defaults to "All rights reserved."
				'menu'        => $footer_menu,
				'social'      => $footer_social,
			),
			'hero'       => $hero_pattern ? array( 'pattern' => $hero_pattern ) : array(),
			'background' => array( 'dotted' => false ),
		);
	}

	/** First (primary) family from a CSS font-family stack, quotes stripped. */
	private static function first_family( $stack ) {
		$stack = (string) $stack;
		if ( trim( $stack ) === '' ) {
			return '';
		}
		$first = explode( ',', $stack );
		$first = trim( $first[0] );
		$first = trim( $first, "\"'" );
		// Skip a bare generic (the source had only a generic primary).
		if ( in_array( strtolower( $first ), array( 'serif', 'sans-serif', 'monospace', 'system-ui', 'ui-sans-serif', 'ui-serif', 'inherit' ), true ) ) {
			return '';
		}
		return $first;
	}

	/** The display font from the first section that has a heading, if any. */
	private static function section_heading_face( array $cap ) {
		if ( ! isset( $cap['sections'] ) || ! is_array( $cap['sections'] ) ) {
			return '';
		}
		foreach ( $cap['sections'] as $s ) {
			if ( isset( $s['headingComputed']['fontFamily'] ) && $s['headingComputed']['fontFamily'] !== '' ) {
				return (string) $s['headingComputed']['fontFamily'];
			}
		}
		return '';
	}

	/**
	 * Choose the source's Google Fonts stylesheet that serves the heading/body
	 * families (skipping icon fonts like Material Symbols). Loading the source's own
	 * css2 URL verbatim reproduces the exact weights/axes it used.
	 *
	 * @param string[] $fonts   Captured <link href> font URLs.
	 * @param string[] $families Family names to match (e.g. Fraunces, Manrope).
	 * @return string
	 */
	private static function pick_google_fonts( array $fonts, array $families ) {
		$families = array_filter( array_map( 'trim', $families ) );
		foreach ( $fonts as $url ) {
			$url = (string) $url;
			if ( stripos( $url, 'fonts.googleapis.com/css' ) === false ) {
				continue;
			}
			if ( stripos( $url, 'Material+Symbols' ) !== false || stripos( $url, 'Material+Icons' ) !== false ) {
				continue;
			}
			foreach ( $families as $fam ) {
				if ( $fam !== '' && stripos( $url, str_replace( ' ', '+', $fam ) ) !== false ) {
					return esc_url_raw( $url );
				}
			}
		}
		// No family match but a non-icon css URL exists → use the first one.
		foreach ( $fonts as $url ) {
			$url = (string) $url;
			if ( stripos( $url, 'fonts.googleapis.com/css' ) !== false
				&& stripos( $url, 'Material+Symbols' ) === false
				&& stripos( $url, 'Material+Icons' ) === false ) {
				return esc_url_raw( $url );
			}
		}
		return '';
	}

	/** Origin (scheme://host[:port]) of a URL, or '' if unparseable. */
	private static function origin( $url ) {
		$p = wp_parse_url( (string) $url );
		if ( empty( $p['scheme'] ) || empty( $p['host'] ) ) {
			return '';
		}
		$port = isset( $p['port'] ) ? ':' . $p['port'] : '';
		return $p['scheme'] . '://' . $p['host'] . $port;
	}

	/**
	 * Localize a captured CTA href: strip the source origin so it points at the new
	 * site (e.g. https://src.app/signup → /signup); leave genuinely external links
	 * and already-relative hrefs as-is. Empty → a sensible default.
	 */
	private static function localize_href( $href, $origin ) {
		$href = trim( (string) $href );
		if ( $href === '' || $href === '#' ) {
			return '/#get-started';
		}
		if ( $origin !== '' && stripos( $href, $origin ) === 0 ) {
			$rest = substr( $href, strlen( $origin ) );
			$rest = $rest === '' ? '/' : $rest;
			return '/' === $rest[0] ? $rest : '/' . $rest;
		}
		return $href;
	}

	/**
	 * Extract the editable tail from a captured copyright line. The "© {year} {brand}."
	 * sentence is reproduced dynamically (Site Title + year) by the footer template, so
	 * we keep only what follows it as a starter tagline (e.g. "Crafted for the sunlit
	 * path."). Returns '' when there's nothing after the copyright sentence.
	 */
	private static function tagline_from_copyright( $copy ) {
		$copy = trim( (string) $copy );
		if ( $copy === '' ) {
			return '';
		}
		$parts = preg_split( '/\.\s+/', $copy, 2 );
		if ( count( $parts ) === 2 && trim( $parts[1] ) !== '' ) {
			return sanitize_text_field( rtrim( trim( $parts[1] ), '.' ) . '.' );
		}
		return '';
	}

	/** Default theme display-name from a page <title> (before any dash/pipe separator). */
	private static function title_to_name( $title ) {
		$title = trim( (string) $title );
		if ( $title === '' ) {
			return 'Converted Site';
		}
		$title = preg_split( '/\s+[—–\-|·:]\s+/u', $title );
		$name  = trim( $title[0] );
		return $name !== '' ? $name : 'Converted Site';
	}

	/** Normalize a captured color: drop transparent / empty; trim otherwise. */
	private static function nz( $v ) {
		$v = trim( (string) $v );
		if ( $v === '' || $v === 'transparent' || preg_match( '/^rgba\(\s*0\s*,\s*0\s*,\s*0\s*,\s*0\s*\)$/i', $v ) ) {
			return '';
		}
		return $v;
	}

	/** Parse a #rgb / #rrggbb / rgb()/rgba() color → array(r,g,b), or null. */
	private static function to_rgb( $c ) {
		$c = trim( (string) $c );
		if ( preg_match( '/^#([0-9a-f]{3})$/i', $c, $m ) ) {
			return array_map( function ( $h ) { return hexdec( $h . $h ); }, str_split( $m[1] ) );
		}
		if ( preg_match( '/^#([0-9a-f]{6})$/i', $c, $m ) ) {
			return array( hexdec( substr( $m[1], 0, 2 ) ), hexdec( substr( $m[1], 2, 2 ) ), hexdec( substr( $m[1], 4, 2 ) ) );
		}
		if ( preg_match( '/^rgba?\(([^)]+)\)/i', $c, $m ) ) {
			$p = array_map( 'floatval', explode( ',', $m[1] ) );
			return array( $p[0], $p[1], $p[2] );
		}
		return null;
	}

	/** Normalize a color to lowercase #rrggbb, or '' if unparseable. */
	private static function to_hex( $c ) {
		$rgb = self::to_rgb( $c );
		if ( ! $rgb ) {
			return '';
		}
		return sprintf( '#%02x%02x%02x', (int) round( $rgb[0] ), (int) round( $rgb[1] ), (int) round( $rgb[2] ) );
	}

	/** A color with effectively no hue (white / black / gray) — not a brand color. */
	private static function is_neutral( $c ) {
		$rgb = self::to_rgb( $c );
		if ( ! $rgb ) {
			return false;
		}
		return ( max( $rgb ) - min( $rgb ) ) <= 24;
	}

	/** Pull a CSS color from a config map, falling back to a default. */
	private static function color( array $map, $key, $default ) {
		if ( ! isset( $map[ $key ] ) || ! is_string( $map[ $key ] ) || trim( $map[ $key ] ) === '' ) {
			return $default;
		}
		$v = trim( $map[ $key ] );
		// Allow hex, rgb()/rgba(), hsl()/hsla(), oklch(), and bare CSS keywords.
		if ( preg_match( '/^#([0-9a-f]{3}|[0-9a-f]{4}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $v )
			|| preg_match( '/^(rgb|rgba|hsl|hsla|oklch|oklab|color)\([^;{}<>]+\)$/i', $v )
			|| preg_match( '/^[a-z]{3,20}$/i', $v ) ) {
			return $v;
		}
		return $default;
	}

	/** Light URL sanitizer for baked menu/social hrefs: keep paths, anchors, mailto, http(s). */
	private static function clean_url( $v ) {
		$v = trim( (string) $v );
		if ( $v === '' ) {
			return '';
		}
		// Strip anything that could break out of a single-quoted PHP/HTML attribute.
		$v = str_replace( array( '"', "'", '<', '>', ' ' ), '', $v );
		if ( preg_match( '#^(https?:|mailto:|tel:|/|\#)#i', $v ) ) {
			return $v;
		}
		return '/' . ltrim( $v, '/' ); // treat bare paths as root-relative
	}

	/**
	 * Normalize footer menu items: [ { label, url, children:[{label,url}] } ]. One
	 * level of nesting (column title → links). Caps sizes; drops label-less entries.
	 *
	 * @param mixed $items
	 * @param int   $depth
	 * @return array
	 */
	private static function norm_menu_items( $items, $depth = 0 ) {
		if ( ! is_array( $items ) ) {
			return array();
		}
		$out = array();
		foreach ( array_slice( $items, 0, 12 ) as $it ) {
			if ( ! is_array( $it ) ) {
				continue;
			}
			$label = isset( $it['label'] ) ? sanitize_text_field( (string) $it['label'] ) : '';
			if ( $label === '' || mb_strlen( $label ) > 60 ) {
				continue;
			}
			$node = array( 'label' => $label, 'url' => self::clean_url( isset( $it['url'] ) ? $it['url'] : '' ) );
			if ( $depth === 0 && ! empty( $it['children'] ) ) {
				$kids = self::norm_menu_items( $it['children'], 1 );
				if ( $kids ) {
					$node['children'] = $kids;
				}
			}
			$out[] = $node;
		}
		return $out;
	}

	/** Normalize a flat list of links: [ { label, url } ]. */
	private static function norm_links( $links ) {
		if ( ! is_array( $links ) ) {
			return array();
		}
		$out = array();
		foreach ( array_slice( $links, 0, 12 ) as $l ) {
			if ( ! is_array( $l ) ) {
				continue;
			}
			$label = isset( $l['label'] ) ? sanitize_text_field( (string) $l['label'] ) : '';
			if ( $label === '' ) {
				continue;
			}
			$out[] = array( 'label' => $label, 'url' => self::clean_url( isset( $l['url'] ) ? $l['url'] : '' ) );
		}
		return $out;
	}

	/** Escape a string for embedding inside a single-quoted PHP literal. */
	private static function esc_php( $s ) {
		return str_replace( array( '\\', "'" ), array( '\\\\', "\\'" ), (string) $s );
	}

	/** Render normalized footer menu items as a PHP array literal (for functions.php). */
	private static function php_menu_literal( array $items ) {
		$rows = array();
		foreach ( $items as $it ) {
			$row = "array( 'label' => '" . self::esc_php( $it['label'] ) . "', 'url' => '" . self::esc_php( $it['url'] ) . "'";
			if ( ! empty( $it['children'] ) ) {
				$kids = array();
				foreach ( $it['children'] as $ch ) {
					$kids[] = "array( 'label' => '" . self::esc_php( $ch['label'] ) . "', 'url' => '" . self::esc_php( $ch['url'] ) . "' )";
				}
				$row .= ", 'children' => array( " . implode( ', ', $kids ) . ' )';
			}
			$row .= ' )';
			$rows[] = $row;
		}
		return "array(\n\t\t" . implode( ",\n\t\t", $rows ) . ",\n\t)";
	}

	/**
	 * Generate a self-healing menu bootstrap for the theme's functions.php: an
	 * `after_switch_theme` handler that builds a named menu from the converted links
	 * and assigns it to a location — once, on activation, idempotently. WordPress
	 * resets `nav_menu_locations` on theme switch, so the theme re-creates + re-assigns
	 * its own menus instead of asking the user to re-import.
	 *
	 * @param string $fn        Function prefix.
	 * @param string $suffix    Unique fn suffix (e.g. 'header_menu').
	 * @param string $menu_name Menu name (reused if it already exists).
	 * @param string $location  Theme menu-location slug to assign to (only if empty).
	 * @param array  $items     Normalized menu items.
	 * @return string
	 */
	/** Convert the nav mapper's tree ({label, href, children}) → menu-bootstrap items ({label, url, children}). */
	private static function nav_tree_items( $tree ) {
		$out = array();
		foreach ( (array) $tree as $it ) {
			if ( ! is_array( $it ) || empty( $it['label'] ) ) { continue; }
			$out[] = array(
				'label'    => (string) $it['label'],
				'url'      => isset( $it['href'] ) ? (string) $it['href'] : '',
				'children' => isset( $it['children'] ) ? self::nav_tree_items( $it['children'] ) : array(),
			);
		}
		return $out;
	}

	private static function menu_bootstrap_code( $fn, $suffix, $menu_name, $location, array $items ) {
		$literal = self::php_menu_literal( $items );
		$out  = "/**\n * Build the \"{$menu_name}\" menu from the converted links and assign it to the\n";
		$out .= " * '{$location}' location on activation. Idempotent (reuses an existing menu);\n";
		$out .= " * only assigns when the location is empty, so it never clobbers a user choice.\n */\n";
		$out .= "function {$fn}_bootstrap_{$suffix}() {\n";
		$out .= "\t\$items = {$literal};\n";
		$out .= "\tif ( ! \$items ) { return; }\n";
		$out .= "\t// wp_create_nav_menu()/wp_update_nav_menu_item() live in wp-admin; load on demand.\n";
		$out .= "\tif ( ! function_exists( 'wp_create_nav_menu' ) ) { require_once ABSPATH . 'wp-admin/includes/nav-menu.php'; }\n";
		$out .= "\t\$resolve = function ( \$url ) {\n";
		$out .= "\t\treturn preg_match( '#^(https?:|mailto:|tel:)#i', \$url ) ? \$url : home_url( \$url === '' ? '/' : \$url );\n";
		$out .= "\t};\n";
		$out .= "\t\$menu = wp_get_nav_menu_object( '" . self::esc_php( $menu_name ) . "' );\n";
		$out .= "\tif ( \$menu ) {\n";
		$out .= "\t\t\$menu_id = (int) \$menu->term_id;\n";
		$out .= "\t} else {\n";
		$out .= "\t\t\$menu_id = wp_create_nav_menu( '" . self::esc_php( $menu_name ) . "' );\n";
		$out .= "\t\tif ( is_wp_error( \$menu_id ) ) { return; }\n";
		$out .= "\t\tforeach ( \$items as \$it ) {\n";
		$out .= "\t\t\t\$parent = wp_update_nav_menu_item( \$menu_id, 0, array(\n";
		$out .= "\t\t\t\t'menu-item-title'  => \$it['label'],\n";
		$out .= "\t\t\t\t'menu-item-url'    => \$resolve( \$it['url'] ),\n";
		$out .= "\t\t\t\t'menu-item-status' => 'publish',\n";
		$out .= "\t\t\t) );\n";
		$out .= "\t\t\tif ( ! empty( \$it['children'] ) && ! is_wp_error( \$parent ) ) {\n";
		$out .= "\t\t\t\tforeach ( \$it['children'] as \$ch ) {\n";
		$out .= "\t\t\t\t\twp_update_nav_menu_item( \$menu_id, 0, array(\n";
		$out .= "\t\t\t\t\t\t'menu-item-title'     => \$ch['label'],\n";
		$out .= "\t\t\t\t\t\t'menu-item-url'       => \$resolve( \$ch['url'] ),\n";
		$out .= "\t\t\t\t\t\t'menu-item-parent-id' => (int) \$parent,\n";
		$out .= "\t\t\t\t\t\t'menu-item-status'    => 'publish',\n";
		$out .= "\t\t\t\t\t) );\n";
		$out .= "\t\t\t\t}\n";
		$out .= "\t\t\t}\n";
		$out .= "\t\t}\n";
		$out .= "\t}\n";
		$out .= "\t\$locations = get_theme_mod( 'nav_menu_locations' );\n";
		$out .= "\tif ( ! is_array( \$locations ) ) { \$locations = array(); }\n";
		// Conversion intent: assign the converted menu to its location ONCE — overriding any
		// pre-existing / demo assignment from the parent theme — then never touch it again, so
		// the user's later menu choice sticks. (The old 'only when empty' check left a demo menu
		// stuck on Primary, so the converted menu never showed.)
		$out .= "\tif ( ! get_option( '{$fn}_{$suffix}_assigned' ) ) {\n";
		$out .= "\t\t\$locations['" . self::esc_php( $location ) . "'] = \$menu_id;\n";
		$out .= "\t\tset_theme_mod( 'nav_menu_locations', \$locations );\n";
		$out .= "\t\tupdate_option( '{$fn}_{$suffix}_assigned', 1 );\n";
		$out .= "\t}\n";
		$out .= "}\n";
		// wp_loaded (not after_switch_theme): runs once via the assigned-flag, AND re-applies after a
		// re-convert (which clears the flag) without needing a manual theme re-activation.
		$out .= "add_action( 'wp_loaded', '{$fn}_bootstrap_{$suffix}' );\n\n";
		return $out;
	}

	/**
	 * Validate a decorative-pattern `background-image` value for safe CSS embedding:
	 * only a self-contained SVG data-URI or a CSS gradient (the shapes our capture
	 * emits). Rejects anything with CSS-breaking / injection tokens. Else ''.
	 */
	private static function css_pattern( $v ) {
		$v = trim( (string) $v );
		if ( $v === '' || mb_strlen( $v ) > 4000 ) {
			return '';
		}
		// No literal markup / brace / style-break / script tokens (data-URIs are %-encoded).
		if ( preg_match( '#[<>{}]|</?style|expression\s*\(|javascript:#i', $v ) ) {
			return '';
		}
		if ( preg_match( '#^url\(\s*["\']?data:image/svg\+xml,#i', $v )
			|| preg_match( '#^(repeating-)?(linear|radial|conic)-gradient\(#i', $v ) ) {
			return $v;
		}
		return '';
	}

	/** Validate a CSS background-repeat keyword; else 'repeat'. */
	private static function css_repeat( $v ) {
		$v = strtolower( trim( (string) $v ) );
		// Computed value can be a pair ("repeat repeat"); take the first token.
		$v = preg_split( '/\s+/', $v )[0];
		return in_array( $v, array( 'repeat', 'no-repeat', 'repeat-x', 'repeat-y', 'space', 'round' ), true ) ? $v : 'repeat';
	}

	/** Clamp an opacity to [0,1]; else 1. */
	private static function css_opacity( $v ) {
		if ( ! is_numeric( $v ) ) {
			return 1;
		}
		$f = (float) $v;
		return max( 0, min( 1, $f ) );
	}

	/** Validate a single CSS length (e.g. "24px", "1.4rem", "-.02em"); else ''. */
	private static function css_len( $v ) {
		$v = trim( (string) $v );
		return preg_match( '/^-?(?:[0-9]*\.)?[0-9]+(px|rem|em|%|pt)$/i', $v ) ? $v : '';
	}

	/** Validate a CSS font-weight (100–900 or a keyword); else ''. */
	private static function css_weight( $v ) {
		$v = trim( (string) $v );
		return preg_match( '/^([1-9]00|normal|bold|bolder|lighter)$/i', $v ) ? $v : '';
	}

	/** Validate a CSS box value of 1–4 length tokens (e.g. "8px 24px"); else ''. */
	private static function css_box( $v ) {
		$v = trim( (string) $v );
		return preg_match( '/^(-?(?:[0-9]*\.)?[0-9]+(px|rem|em|%)\s*){1,4}$/i', $v ) ? preg_replace( '/\s+/', ' ', $v ) : '';
	}

	/**
	 * Clamp a captured border-radius: browsers report a fully-rounded ("pill")
	 * button as an absurd px value (e.g. 3.35e7px) — normalize those to 9999px.
	 * Pass small/relative radii through. Invalid → ''.
	 */
	private static function clamp_radius( $v ) {
		$v = trim( (string) $v );
		if ( $v === '' ) {
			return '';
		}
		if ( preg_match( '/^([0-9]*\.?[0-9]+(?:e\+?[0-9]+)?)px$/i', $v, $m ) ) {
			return ( (float) $m[1] > 100 ) ? '9999px' : $v;
		}
		if ( preg_match( '/^(?:[0-9]*\.)?[0-9]+(rem|em|%)$/i', $v ) || preg_match( '/^[0-9]+px(\s+[0-9]+px){1,3}$/i', $v ) ) {
			return $v;
		}
		return '';
	}

	/* ---------------------------------------------------------------------- *
	 * File builders — return the generated file map [ relpath => contents ].
	 * ---------------------------------------------------------------------- */

	/**
	 * Build the generated child-theme file map for a normalized config. For
	 * 'standalone' the same four files are produced but overlaid onto a copy of the
	 * parent tree by install()/build_zip(); the style.css header omits `Template:`.
	 *
	 * @param array $cfg Normalized config.
	 * @return array<string,string> relpath => file contents
	 */
	public static function build_files( array $cfg ) {
		$files = array(
			'style.css'                         => self::style_css( $cfg ),
			'functions.php'                     => self::functions_php( $cfg ),
			// The child owns its OWN header.php / footer.php — overriding the parent's outright. The
			// parent now routes header/footer through the Theme Builder (its presets are reserved for
			// the distributable demos), so a converted site bypasses that entirely: get_header() loads
			// THIS header.php directly, which renders the source chrome (no Theme Builder / Theme
			// Settings indirection). The chrome markup itself lives in the two template parts below.
			'header.php'                        => self::header_php( $cfg ),
			'footer.php'                        => self::footer_php( $cfg ),
			// The child overrides BOTH chrome template parts with a "dynamic mirror": the source's
			// own markup + classes (so the carried source CSS styles it = identical look), with the
			// logo / menu / footer columns swapped to live WordPress output. Theme Settings deferred.
			// "Capture header/footer" off (the convert panel option) → keep header.php/footer.php as the
			// document wrappers but render NO chrome there, so the page is just the converted body.
			'template-parts/header-builder.php' => empty( $cfg['skip_header'] ) ? self::header_part( $cfg ) : "<?php if ( ! defined( 'ABSPATH' ) ) { die; } /* header capture disabled */\n",
			'template-parts/footer-builder.php' => empty( $cfg['skip_footer'] ) ? self::footer_part( $cfg ) : "<?php if ( ! defined( 'ABSPATH' ) ) { die; } /* footer capture disabled */\n",
		);
		// Raw-chrome (verbatim mirror) sites ship a small interactivity script that re-hydrates
		// the captured markup — mobile nav toggle, smooth-scroll, animated counters & progress
		// bars, AOS reveal — since the source's own JS was stripped at capture.
		if ( self::has_raw_chrome( $cfg ) ) {
			$files['assets/js/interactivity.js'] = self::interactivity_js();
		}
		return $files;
	}

	/**
	 * The re-hydration script for verbatim-mirrored sites. Operates on the source markup that
	 * survived capture (its data-attributes / classes are intact), so the converted page behaves
	 * like the source without decomposing sections into shortcodes. Pure vanilla JS, no deps.
	 *
	 * @return string
	 */
	private static function interactivity_js() {
		return <<<'JS'
/* Site Converter interactivity — re-hydrate the verbatim-mirrored markup (the source's own JS
   was stripped at capture). Mobile nav toggle, tab/pill switching, smooth-scroll, animated
   counters + progress bars, AOS reveal. Heuristic: targets the common Bootstrap patterns. */
( function () {
	'use strict';
	var doc = document, body = doc.body;
	var reduce = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
	var TAB_SEL = '[data-bs-toggle="tab"],[data-bs-toggle="pill"],[data-toggle="tab"],[data-toggle="pill"]';

	// Mobile nav toggle: BootstrapMade (.mobile-nav-toggle → body.mobile-nav-active) and
	// Bootstrap collapse (.navbar-toggler[data-bs-target]).
	doc.addEventListener( 'click', function ( e ) {
		var t = e.target.closest ? e.target.closest( '.mobile-nav-toggle, .navbar-toggler, [data-bs-toggle="collapse"]' ) : null;
		if ( ! t ) { return; }
		if ( t.matches( '.mobile-nav-toggle' ) ) {
			body.classList.toggle( 'mobile-nav-active' );
			t.classList.toggle( 'bi-list' ); t.classList.toggle( 'bi-x' );
			e.preventDefault(); return;
		}
		var sel = t.getAttribute( 'data-bs-target' ) || t.getAttribute( 'href' ) || '';
		var target = ( sel && sel.charAt( 0 ) === '#' && sel.length > 1 ) ? doc.querySelector( sel ) : null;
		if ( target ) { target.classList.toggle( 'show' ); e.preventDefault(); }
	} );

	// Tab / pill switching (Bootstrap 4 data-toggle + 5 data-bs-toggle). Activates the clicked
	// nav item and its target pane, deactivating siblings.
	doc.addEventListener( 'click', function ( e ) {
		var tab = e.target.closest ? e.target.closest( TAB_SEL ) : null;
		if ( ! tab ) { return; }
		e.preventDefault();
		var sel  = tab.getAttribute( 'data-bs-target' ) || tab.getAttribute( 'href' ) || '';
		var pane = ( sel && sel.charAt( 0 ) === '#' && sel.length > 1 ) ? doc.querySelector( sel ) : null;
		var nav  = tab.closest( '.nav, .nav-tabs, .nav-pills, ul' );
		if ( nav ) { [].forEach.call( nav.querySelectorAll( '.active' ), function ( a ) { a.classList.remove( 'active', 'show' ); } ); }
		tab.classList.add( 'active', 'show' );
		var li = tab.closest( 'li' ); if ( li ) { li.classList.add( 'active' ); }
		if ( pane && pane.parentElement ) {
			[].forEach.call( pane.parentElement.children, function ( c ) {
				if ( c.classList && c.classList.contains( 'tab-pane' ) ) { c.classList.remove( 'active', 'show' ); }
			} );
			pane.classList.add( 'active', 'show' );
		}
	} );

	// Smooth-scroll in-page anchors (but not tab / collapse toggles, handled above).
	doc.addEventListener( 'click', function ( e ) {
		var a = e.target.closest ? e.target.closest( 'a[href^="#"]' ) : null;
		if ( ! a || a.matches( TAB_SEL ) || a.matches( '[data-bs-toggle],[data-toggle]' ) ) { return; }
		var id = a.getAttribute( 'href' );
		if ( ! id || id.length < 2 ) { return; }
		var el; try { el = doc.querySelector( id ); } catch ( err ) { return; }
		if ( ! el ) { return; }
		e.preventDefault();
		body.classList.remove( 'mobile-nav-active' );
		el.scrollIntoView( { behavior: 'smooth', block: 'start' } );
	} );

	// ---- Counters --------------------------------------------------------
	var COUNTER_ATTR = '[data-purecounter-end],[data-count],[data-to],[data-number]';
	var COUNTER_CLASS = '.purecounter,.counter,.count-up,.countup,.counting,.odometer';
	function cleanNumber( el ) { return /^[\d.,]+\s*[+%kKmM]?$/.test( ( el.textContent || '' ).trim() ); }
	function isCounter( el ) {
		if ( el.matches( COUNTER_ATTR ) ) { return true; }
		return el.matches( COUNTER_CLASS ) && cleanNumber( el );
	}
	function counterEnd( el ) {
		var a = [ 'data-purecounter-end', 'data-count', 'data-to', 'data-number' ];
		for ( var i = 0; i < a.length; i++ ) { var v = el.getAttribute( a[ i ] ); if ( v && ! isNaN( parseFloat( v ) ) ) { return parseFloat( v ); } }
		var t = ( el.textContent || '' ).replace( /[^\d.]/g, '' );
		return t !== '' ? parseFloat( t ) : NaN;
	}
	function affix( el ) {
		var m = ( el.textContent || '' ).trim().match( /^([^\d-]*)[\d.,\s]*(.*)$/ );
		return { pre: m ? m[ 1 ] : '', suf: m ? m[ 2 ] : '' };
	}
	function fmt( v, dec ) { return dec ? v.toFixed( dec ) : Math.round( v ).toLocaleString(); }
	function countUp( el ) {
		var end = counterEnd( el ); if ( isNaN( end ) ) { return; }
		var start = parseFloat( el.getAttribute( 'data-purecounter-start' ) || '0' ) || 0;
		var da = parseFloat( el.getAttribute( 'data-purecounter-duration' ) );
		var dur = ( da ? da : 1.6 ) * 1000;
		var dec = parseInt( el.getAttribute( 'data-purecounter-decimals' ), 10 );
		if ( isNaN( dec ) ) { dec = ( end % 1 !== 0 ) ? ( String( end ).split( '.' )[ 1 ] || '' ).length : 0; }
		var af = affix( el );
		if ( reduce ) { el.textContent = af.pre + fmt( end, dec ) + af.suf; return; }
		var t0 = null;
		function step( ts ) {
			if ( ! t0 ) { t0 = ts; }
			var p = Math.min( ( ts - t0 ) / dur, 1 );
			el.textContent = af.pre + fmt( start + ( end - start ) * p, dec ) + af.suf;
			if ( p < 1 ) { requestAnimationFrame( step ); }
		}
		requestAnimationFrame( step );
	}

	function fillBar( bar ) {
		var w = bar.getAttribute( 'aria-valuenow' );
		var target = w ? ( w + '%' ) : ( bar.style.width || bar.getAttribute( 'data-width' ) || '' );
		if ( ! target ) { return; }
		if ( reduce ) { bar.style.width = target; return; }
		if ( ! bar.style.transition ) { bar.style.transition = 'width 1s ease'; }
		bar.style.width = '0';
		requestAnimationFrame( function () { requestAnimationFrame( function () { bar.style.width = target; } ); } );
	}

	function reveal( el ) {
		if ( el.hasAttribute( 'data-aos' ) ) { el.classList.add( 'aos-init', 'aos-animate' ); }
		if ( isCounter( el ) ) { countUp( el ); }
		if ( el.classList.contains( 'progress-bar' ) ) { fillBar( el ); }
	}

	function init() {
		var targets = [].slice.call( doc.querySelectorAll( '[data-aos],' + COUNTER_ATTR + ',' + COUNTER_CLASS + ',.progress-bar' ) );
		if ( ! ( 'IntersectionObserver' in window ) ) { targets.forEach( reveal ); return; }
		var io = new IntersectionObserver( function ( entries, obs ) {
			entries.forEach( function ( en ) { if ( en.isIntersecting ) { reveal( en.target ); obs.unobserve( en.target ); } } );
		}, { threshold: 0.2 } );
		targets.forEach( function ( t ) { io.observe( t ); } );
	}

	if ( doc.readyState !== 'loading' ) { init(); }
	else { doc.addEventListener( 'DOMContentLoaded', init ); }
} )();
JS;
	}

	/** style.css — the theme header block + the generated chrome CSS. */
	private static function style_css( array $cfg ) {
		$t        = $cfg['theme'];
		$standalone = $t['mode'] === 'standalone';
		$tpl      = $standalone ? '' : "Template:    " . self::PARENT_TEMPLATE . "\n";
		$textdom  = $t['slug'];

		$head = "/*\n"
			. "Theme Name:  {$t['name']}" . ( $standalone ? '' : ' (Child)' ) . "\n"
			. $tpl
			. "Description: {$t['description']}\n"
			. "Author:      {$t['author']}\n"
			. "Version:     {$t['version']}\n"
			. "Text Domain: {$textdom}\n"
			. "*/\n\n";

		// Order: theme header → authored + base + utilities + header CSS (chrome_css) → WP admin-bar
		// compatibility → the per-section styles (SECTIONS block, filled on Build) → footer CSS, last.
		$body = self::chrome_css( $cfg ) . self::admin_bar_css() . self::sections_block( '' ) . self::footer_block( $cfg );
		// `@import` rules are only valid at the TOP of a stylesheet — hoist any (Google-Fonts imports
		// folded into custom_css) to right after the theme header so the fonts actually load.
		$imports = '';
		// Match the WHOLE @import url(...) — the font URL itself can contain ';' (e.g. Inter:wght@400;500;600).
		$body = preg_replace_callback( '/@import\s+url\([^)]*\)\s*;[\t ]*\n?/', function ( $m ) use ( &$imports ) {
			$imports .= trim( $m[0] ) . "\n";
			return '';
		}, $body );
		return $head . $imports . $body;
	}

	/**
	 * When logged in, WordPress shows a fixed 32px admin bar at the top of the page. A fixed/sticky
	 * site header would sit underneath it — so push the header down to clear it (46px on the ≤782px
	 * mobile bar). Just spacing; nothing else needed (the theme stylesheet doesn't load in wp-admin,
	 * and element resets are scoped so they don't touch the admin bar).
	 */
	private static function admin_bar_css() {
		return "\n/* Keep a fixed header below the logged-in admin bar */\n"
			. ".admin-bar .sc-header,.admin-bar #masthead,.admin-bar header[role=\"banner\"]{top:32px;}\n"
			. "@media screen and (max-width:782px){.admin-bar .sc-header,.admin-bar #masthead,.admin-bar header[role=\"banner\"]{top:46px;}}\n";
	}

	/** The merge markers + (optional) per-section CSS. The Build step replaces the body. */
	const SECTIONS_START = '/* SC:SECTIONS:START — converted page sections (auto, regenerated on each Build) */';
	const SECTIONS_END   = '/* SC:SECTIONS:END */';

	/**
	 * The "converted page sections" block appended to style.css. The design phase writes it
	 * empty; the content-mapper Build replaces everything between the START/END markers with the
	 * flattened, animation-stripped per-section CSS — so the converted site loads ONE child
	 * stylesheet (no separate converted-page.css).
	 *
	 * @param string $sections_css
	 * @return string
	 */
	public static function sections_block( $sections_css ) {
		$body = trim( (string) $sections_css );
		return "\n\n" . self::SECTIONS_START . "\n" . ( $body !== '' ? $body . "\n" : '' ) . self::SECTIONS_END . "\n";
	}

	/**
	 * Clean carried source CSS for the header/footer MIRROR: strip animations, collapse empties,
	 * then pretty-print to a readable format. We deliberately KEEP the Bootstrap grid
	 * (.container / .row / .col-*) — the mirror's navbar + multi-column footer lay out with the
	 * source's own grid. (Section CSS still strips the grid via the mapper's page_css, because
	 * the page-builder sections use the fw- grid instead.)
	 */
	private static function clean_carried( $css ) {
		if ( ! class_exists( 'FW_Site_Converter_Mapper' ) ) { return self::pretty_css( (string) $css ); }
		$css = FW_Site_Converter_Mapper::strip_animations( (string) $css );
		$css = FW_Site_Converter_Mapper::tidy_css( $css );
		return self::pretty_css( $css );
	}

	/**
	 * Pretty-print CSS into a readable, indented format (one declaration per line, nested @media
	 * indented). Brace/quote/paren-aware so `url(data:…;base64,…)` and `content:";"` survive.
	 *
	 * @param string $css
	 * @return string
	 */
	public static function pretty_css( $css ) {
		$css = trim( (string) $css );
		if ( $css === '' ) { return ''; }
		$out = '';
		$buf = '';
		$depth = 0;
		$paren = 0;
		$q = '';
		$len = strlen( $css );
		for ( $i = 0; $i < $len; $i++ ) {
			$ch = $css[ $i ];
			if ( $q !== '' ) { // inside a string
				$buf .= $ch;
				if ( $ch === $q && ( $i === 0 || $css[ $i - 1 ] !== '\\' ) ) { $q = ''; }
				continue;
			}
			if ( $ch === '"' || $ch === "'" ) { $q = $ch; $buf .= $ch; continue; }
			if ( $ch === '(' ) { $paren++; $buf .= $ch; continue; }
			if ( $ch === ')' ) { $paren = max( 0, $paren - 1 ); $buf .= $ch; continue; }
			if ( $paren > 0 ) { $buf .= $ch; continue; } // don't split inside url()/calc()
			if ( $ch === '{' ) {
				$out .= str_repeat( "\t", $depth ) . trim( $buf ) . " {\n";
				$buf = '';
				$depth++;
			} elseif ( $ch === '}' ) {
				if ( trim( $buf ) !== '' ) { $out .= str_repeat( "\t", $depth ) . trim( $buf ) . ";\n"; $buf = ''; }
				$depth = max( 0, $depth - 1 );
				$out .= str_repeat( "\t", $depth ) . "}\n";
			} elseif ( $ch === ';' ) {
				$out .= str_repeat( "\t", $depth ) . trim( $buf ) . ";\n";
				$buf = '';
			} else {
				$buf .= $ch;
			}
		}
		if ( trim( $buf ) !== '' ) { $out .= trim( $buf ) . "\n"; }
		return $out;
	}

	/** The footer CSS group — written LAST (after the section styles) for a clean, readable order. */
	private static function footer_block( array $cfg ) {
		if ( ! self::has_raw_chrome( $cfg ) ) { return ''; }
		$f = isset( $cfg['raw_chrome']['footer_css'] ) ? self::clean_carried( (string) $cfg['raw_chrome']['footer_css'] ) : '';
		return $f !== '' ? "\n\n/* ============ Footer ============ */\n" . $f . "\n" : '';
	}

	/**
	 * The clean authored design layer (CSS custom-property tokens + typography + page
	 * background), written into the CHILD theme so the converted site's fonts/colors are
	 * self-defined and do NOT depend on the parent theme's Theme Settings (which default to
	 * Raleway). Emitted for BOTH the clean and raw-chrome paths. The parent style.css applies
	 * `var(--font-heading)`/`var(--font-body)`; overriding those variables cascades site-wide,
	 * and the explicit !important rules beat any harder selector (incl. carried Bootstrap).
	 *
	 * @param array $cfg
	 * @return string
	 */
	private static function typography_layer( array $cfg ) {
		$col = isset( $cfg['colors'] ) && is_array( $cfg['colors'] ) ? $cfg['colors'] : array();
		$fh  = isset( $cfg['fonts']['heading'] ) ? $cfg['fonts']['heading'] : '';
		$fb  = isset( $cfg['fonts']['body'] ) ? $cfg['fonts']['body'] : '';
		$head_stack = $fh !== '' ? "'" . $fh . "',Georgia,serif" : 'Georgia,serif';
		$body_stack = $fb !== '' ? "'" . $fb . "',system-ui,sans-serif" : 'system-ui,sans-serif';

		$out = "/* ---- Design tokens & typography (self-contained — independent of the parent\n"
			. "   theme's Theme Settings typography) ---- */\n";
		if ( ! empty( $col['bg'] ) ) {
			$out .= "body { background-color:{$col['bg']} !important; }\n";
		}
		// Content container width from the source (.container / max-w-* / centered wrapper) → the
		// builder's .fw-container, overriding the frontend-grid default.
		$cmax = isset( $cfg['layout']['container_max'] ) ? trim( (string) $cfg['layout']['container_max'] ) : '';
		if ( $cmax !== '' ) {
			$out .= ".fw-container { max-width:{$cmax} !important; }\n";
		}
		// Clip horizontal bleed from full-bleed source sections (sliders, negative-margin rows). The
		// source relies on `html { overflow-x:hidden }`; we scope it to the converted content so it
		// can't widen the page (which would push the header/banner and leave a white gap). `clip`
		// keeps vertical flow intact and — unlike `hidden` — doesn't create a scroll container.
		$out .= ".fw-page-builder-content { overflow-x:clip; }\n";
		if ( $fh !== '' || $fb !== '' ) {
			$out .= ":root, body {\n";
			if ( $fb !== '' ) { $out .= "\t--font-body:{$body_stack};\n"; }
			if ( $fh !== '' ) { $out .= "\t--font-heading:{$head_stack};\n"; }
			$out .= "}\n";
			if ( $fb !== '' ) { $out .= "body { font-family:{$body_stack} !important; }\n"; }
			// Headings: font-family forced + base font-weight re-asserted at a higher specificity
			// than component classes (e.g. .icon-box__title) — but WITHOUT !important on weight, so
			// ID-based section overrides (#banner h1, #counter h2…) still win.
			$hwt = isset( $cfg['fonts']['heading_weight'] ) ? (string) $cfg['fonts']['heading_weight'] : '';
			$hcl = isset( $cfg['colors']['heading'] ) ? (string) $cfg['colors']['heading'] : '';
			if ( $fh !== '' || $hwt !== '' ) {
				$hd = '';
				if ( $fh !== '' )  { $hd .= "font-family:{$head_stack} !important;"; }
				if ( $hwt !== '' ) { $hd .= "font-weight:{$hwt};"; }
				$out .= ":is(h1,h2,h3,h4,h5,h6) { {$hd} }\n";
			}
			// Heading color is emitted as a PLAIN element-selector rule (specificity 0,0,1) so it acts
			// only as the default — any component/section rule that sets a heading color (e.g. a dark
			// footer's `.widget h4 { color:#fff }`, `.logo h3`) wins. A high-specificity color here
			// (the old `:is(h…)`, 0,1,2) wrongly forced footer headings dark.
			if ( $hcl !== '' ) {
				$out .= "h1,h2,h3,h4,h5,h6 { color:{$hcl}; }\n";
			}
		}
		return $out . "\n";
	}

	/**
	 * Map the source's primary button onto the base `.btn` class so converted buttons — which the
	 * Site Converter sets to the button shortcode's 'Default' style (a bare `.btn`) — look like the
	 * source right after conversion, with NO component-preset dependency baked in.
	 *
	 * This is a FALLBACK baseline: it uses a plain `.btn` selector (no !important) and sits in the
	 * authored shell, so the source's own button rules — carried into the SECTIONS block later in
	 * the file as `.btn` / `.btn:hover` (rewritten from `.btn-main` by the mapper) — override it
	 * with the faithful base + hover. It also loses to a Color Preset's `.btn-{slug} !important`,
	 * so switching a button to a preset still takes over. Colors come from the CTA / brand accent.
	 *
	 * @param array $cfg
	 * @return string
	 */
	private static function button_layer( array $cfg ) {
		$col = isset( $cfg['colors'] ) && is_array( $cfg['colors'] ) ? $cfg['colors'] : array();
		$cta = isset( $cfg['header']['cta']['style'] ) && is_array( $cfg['header']['cta']['style'] ) ? $cfg['header']['cta']['style'] : array();
		$bg  = ! empty( $cta['bg'] ) ? $cta['bg'] : ( ! empty( $col['accent'] ) ? $col['accent'] : '' );
		if ( $bg === '' ) { return ''; } // no captured button color → keep the shortcode's basic .btn

		$color = ! empty( $cta['color'] ) ? $cta['color'] : '#fff';
		$decl  = "background-color:{$bg};color:{$color};border-color:{$bg};";
		if ( ! empty( $cta['radius'] ) )      { $decl .= "border-radius:{$cta['radius']};"; }
		if ( ! empty( $cta['padding'] ) )     { $decl .= "padding:{$cta['padding']};"; }
		if ( ! empty( $cta['font_weight'] ) ) { $decl .= "font-weight:{$cta['font_weight']};"; }

		return "/* ---- Buttons — baseline for the bare .btn (Style: Default). The source's own\n"
			. "   button rules (base + :hover) are carried into the sections block below and\n"
			. "   override this; switching a button to a Color Preset overrides both. ---- */\n"
			. ".btn { {$decl} }\n\n";
	}

	/**
	 * The chrome CSS — parameterized version of the proven SmartRoute stylesheet.
	 * All global rules are scoped `body` so the asset optimizer can't
	 * bleed them into wp-admin (a lesson learned the hard way with misc_custom_css).
	 *
	 * @param array $cfg
	 * @return string
	 */
	/**
	 * CSS for the dynamic header menu (`wp_nav_menu` with class `.sc-menu`), derived from the
	 * source nav's CAPTURED computed look (color, size, weight, spacing, dropdown) so the live
	 * WordPress menu matches the source. Hover/active uses the brand accent (computed hover isn't
	 * readable at capture). Framework-agnostic — no Bootstrap/Tailwind classes involved.
	 */
	private static function sc_menu_css( array $cfg ) {
		$ns = isset( $cfg['raw_chrome']['nav_style'] ) && is_array( $cfg['raw_chrome']['nav_style'] ) ? $cfg['raw_chrome']['nav_style'] : array();
		if ( empty( $ns ) ) { return ''; }
		$g = function ( $k ) use ( $ns ) { return isset( $ns[ $k ] ) && $ns[ $k ] !== '' ? (string) $ns[ $k ] : ''; };
		$accent = ! empty( $cfg['colors']['accent'] ) ? $cfg['colors']['accent'] : '';
		$gap   = $g( 'gap' ) !== '' ? $g( 'gap' ) : '1.5rem';
		$color = $g( 'color' );
		$ddbg  = $g( 'ddBg' ) !== '' ? $g( 'ddBg' ) : '#fff';
		$ddsh  = $g( 'ddShadow' ) !== '' ? $g( 'ddShadow' ) : '0 10px 30px rgba(0,0,0,.12)';
		$ddrad = $g( 'ddRadius' );
		$ddcol = $g( 'ddColor' ) !== '' ? $g( 'ddColor' ) : ( $color !== '' ? $color : '#222' );
		$hover = $accent !== '' ? $accent : 'inherit';

		$link = 'display:block;text-decoration:none;padding:.4rem 0;';
		if ( $color !== '' )       { $link .= "color:{$color};"; }
		if ( $g( 'fontSize' ) )     { $link .= 'font-size:' . $g( 'fontSize' ) . ';'; }
		if ( $g( 'fontWeight' ) )   { $link .= 'font-weight:' . $g( 'fontWeight' ) . ';'; }
		if ( $g( 'letterSpacing' ) ){ $link .= 'letter-spacing:' . $g( 'letterSpacing' ) . ';'; }
		if ( $g( 'textTransform' ) && $g( 'textTransform' ) !== 'none' ) { $link .= 'text-transform:' . $g( 'textTransform' ) . ';'; }
		if ( $g( 'fontFamily' ) )   { $link .= 'font-family:' . $g( 'fontFamily' ) . ';'; }

		$css  = ".sc-menu { display:flex; flex-wrap:wrap; align-items:center; gap:{$gap}; margin:0 auto; padding:0; list-style:none; }\n";
		$css .= ".sc-menu li { position:relative; }\n";
		$css .= ".sc-menu a { {$link} }\n";
		$css .= ".sc-menu > li > a:hover, .sc-menu .current-menu-item > a, .sc-menu .current-menu-ancestor > a { color:{$hover}; }\n";
		$css .= ".sc-menu .sub-menu { position:absolute; top:100%; left:0; min-width:200px; background:{$ddbg}; box-shadow:{$ddsh}; " . ( $ddrad !== '' ? "border-radius:{$ddrad}; " : '' ) . "list-style:none; margin:0; padding:.5rem 0; display:none; z-index:1000; }\n";
		$css .= ".sc-menu li:hover > .sub-menu { display:block; }\n";
		$css .= ".sc-menu .sub-menu a { color:{$ddcol}; padding:.45rem 1.1rem; white-space:nowrap; }\n";
		$css .= ".sc-menu .sub-menu a:hover { color:{$hover}; }\n";
		return self::pretty_css( $css );
	}

	/**
	 * The design layer for an AI-authored theme: a tiny structural reset (so the parent's #page /
	 * #content / #colophon wrappers don't box the authored chrome) + the AI's complete stylesheet.
	 * NO deterministic palette/font/header CSS — the AI stylesheet owns the entire look, so nothing
	 * fights it (the old generic base, with its !important colors/fonts, is exactly what made every
	 * conversion look the same).
	 */
	private static function ai_chrome_css( array $cfg ) {
		$reset = "/* Site Converter — AI-authored design. Structural reset so the parent wrappers don't\n"
			. "   box the authored chrome; the stylesheet below owns the entire look. */\n"
			. "#page,#content,.site-content{max-width:none;margin:0;padding:0;}\n"
			. "#colophon{margin:0;padding:0;background:none;border:0;max-width:none;}\n\n";
		$css = self::clean_carried( (string) $cfg['custom_css'] );
		return $reset . "/* ============ AI-authored stylesheet ============ */\n" . $css . "\n";
	}

	public static function chrome_css( array $cfg ) {
		// AI-authored design, or a faithful MIRROR (reproduced Tailwind CSS) — emit only the structural
		// reset + the carried stylesheet; no deterministic .sc-header/.sc-footer chrome CSS to fight it.
		if ( ! empty( $cfg['ai_authored'] ) || ! empty( $cfg['mirror'] ) ) {
			return self::ai_chrome_css( $cfg );
		}
		// Raw-chrome mirror: serve the CSS captured from the source verbatim. We only add a
		// tiny reset so the parent theme's wrappers (#page / #content / the #colophon that
		// wraps the footer template part) don't add their own padding/background around the
		// mirrored markup — the source CSS then owns the chrome's look entirely.
		if ( self::has_raw_chrome( $cfg ) ) {
			$reset = "/* Site Converter raw-chrome reset — let the mirrored markup own its layout. */\n"
				. "#page,#content,#primary,.site-content{max-width:none;margin:0;padding:0;}\n"
				. "#colophon{margin:0;padding:0;background:none;border:0;max-width:none;}\n"
				// Body sections are mirrored verbatim inside a .sc-mirror builder section; zero the
				// builder's container/column gutters AND its own vertical padding so the source
				// markup renders edge-to-edge and the SOURCE section owns 100% of the spacing (the
				// builder section's default 64px top/bottom otherwise stacks on the source's own
				// margins → the whole page grows too tall).
				. ".sc-mirror{padding-top:0 !important;padding-bottom:0 !important;}\n"
				. ".sc-mirror>.fw-container,.sc-mirror>.fw-container-fluid{max-width:none;margin:0;padding:0;width:100%;}\n"
				. ".sc-mirror .fw-row{margin:0;}\n"
				. ".sc-mirror [class*=\"fw-col-\"],.sc-mirror .fw-col{padding:0;}\n"
				. ".sc-mirror .fw-col-inner{padding:0;margin:0;}\n\n";
			// Authored layer FIRST (design tokens + typography) so the converted fonts win over the
			// source's carried `body { font-family }`. Then the source CSS in a clean, labeled order:
			// Base & typography → Globals & utilities → Header. (Footer is written after the section
			// styles, by style_css().) Each group is cleaned: animations + Bootstrap grid stripped,
			// empty rules / blank-line runs collapsed.
			$rc   = $cfg['raw_chrome'];
			$base = self::clean_carried( isset( $rc['base_css'] ) ? $rc['base_css'] : '' );
			$util = self::clean_carried( isset( $rc['util_css'] ) ? $rc['util_css'] : '' );
			$head = self::clean_carried( isset( $rc['header_css'] ) ? $rc['header_css'] : '' );
			// Back-compat: older bundles only carried a single `css` blob (uncategorized).
			if ( $base === '' && $util === '' && $head === '' && ! empty( $rc['css'] ) ) {
				$util = self::clean_carried( (string) $rc['css'] );
			}
			$out  = self::typography_layer( $cfg );
			$out .= self::button_layer( $cfg );
			$out .= $reset;
			if ( $base !== '' ) { $out .= "/* ============ Base & typography (source) ============ */\n" . $base . "\n\n"; }
			if ( $util !== '' ) { $out .= "/* ============ Globals & utilities (source) ============ */\n" . $util . "\n\n"; }
			if ( $head !== '' ) { $out .= "/* ============ Header ============ */\n" . $head . "\n\n"; }
			$sc_menu = self::sc_menu_css( $cfg );
			if ( $sc_menu !== '' ) { $out .= "/* ============ Dynamic menu (wp_nav_menu .sc-menu) ============ */\n" . $sc_menu . "\n\n"; }
			$page_mirror = self::clean_carried( (string) $cfg['custom_css'] );
			if ( $page_mirror !== '' ) { $out .= "/* ---- Carried page CSS ---- */\n" . $page_mirror . "\n"; }
			return $out;
		}

		$col   = $cfg['colors'];
		$hd    = $cfg['header'];
		$bg    = $cfg['background'];
		$fh    = $cfg['fonts']['heading'];
		$fb    = $cfg['fonts']['body'];
		$head_stack = $fh !== '' ? "'" . $fh . "',Georgia,serif" : 'Georgia,serif';
		$body_stack = $fb !== '' ? "'" . $fb . "',system-ui,sans-serif" : 'system-ui,sans-serif';

		$css  = "/* ============================================================\n"
			. "   " . $cfg['theme']['name'] . " — generated by the Unyson+ Site Converter.\n"
			. "   Header/footer chrome + the authored design layer. Converted page\n"
			. "   section styles are merged into the SC:SECTIONS block at the bottom.\n"
			. "   ============================================================ */\n\n";

		// Clean authored layer (design tokens + typography + background), shared with the
		// raw-chrome path. Self-contained so the site doesn't lean on the parent's settings.
		$css .= self::typography_layer( $cfg );
		$css .= self::button_layer( $cfg );

		// Dotted canvas backdrop (optional) — a texture on top of the base color.
		if ( ! empty( $bg['dotted'] ) ) {
			$css .= "/* Canvas texture */\n"
				. "body {\n"
				. "\tbackground-image:radial-gradient({$bg['dot_color']} 1.2px, transparent 1.2px) !important;\n"
				. "\tbackground-size:26px 26px !important;\n}\n\n";
		}

		// Header shell + inner bar (pill / bar / minimal).
		$style   = $hd['style'];
		$is_pill = $style === 'pill';
		$radius  = $is_pill ? '9999px' : ( $style === 'minimal' ? '0' : '14px' );
		$inner_bg = $is_pill || $style === 'bar' ? $col['header_bg'] : 'transparent';
		$inner_brd = $style === 'minimal' ? 'none' : "1px solid {$col['header_brd']}";
		$blur     = $is_pill ? "\n\tbackdrop-filter:blur(8px); -webkit-backdrop-filter:blur(8px);" : '';
		$sticky   = ! empty( $hd['sticky'] ) ? "\n#masthead.sc-header { position:sticky; top:0; z-index:100; }" : '';

		// Logo styling — copied from the source logo where captured, else heading defaults.
		$lg          = isset( $hd['logo'] ) && is_array( $hd['logo'] ) ? $hd['logo'] : array();
		$logo_stack  = ! empty( $lg['font'] ) ? "'" . $lg['font'] . "',Georgia,serif" : $head_stack;
		$logo_weight = ! empty( $lg['weight'] ) ? $lg['weight'] : '700';
		$logo_size   = ! empty( $lg['size'] ) ? $lg['size'] : '1.4rem';
		$logo_color  = ! empty( $lg['color'] ) ? $lg['color'] : $col['ink'];
		$logo_ls     = ! empty( $lg['letter_spacing'] ) ? $lg['letter_spacing'] : '-.02em';

		$css .= "/* Header: {$hd['layout']} ({$style}) */\n"
			. "#masthead.sc-header { background:transparent; border:none; padding:1.1rem 1rem 0; }{$sticky}\n"
			. ".sc-header-inner {\n"
			. "\tmax-width:1140px; margin:0 auto;\n"
			. "\tpadding:.55rem 1.4rem .55rem 1.7rem;\n"
			. "\tdisplay:flex; align-items:center; gap:1.5rem;\n"
			. "\tbackground:{$inner_bg};\n"
			. "\tborder:{$inner_brd}; border-radius:{$radius};{$blur}\n"
			. "\tbox-shadow:0 1px 2px rgba(0,0,0,.04);\n}\n"
			. ".sc-logo-text { font-family:{$logo_stack} !important; font-weight:{$logo_weight}; font-size:{$logo_size}; color:{$logo_color} !important; text-decoration:none; letter-spacing:{$logo_ls}; }\n"
			. ".sc-logo img, .sc-logo .custom-logo { max-height:34px; width:auto; display:block; }\n"
			. ".sc-header .primary-menu { flex:1; display:flex; justify-content:center; }\n"
			. ".sc-menu { display:flex !important; gap:1.9rem; list-style:none; margin:0; padding:0; }\n"
			. ".sc-menu li { margin:0; }\n"
			// Reset the parent theme's nav chrome (gray hover box) — clean underline only.
			. ".sc-menu a {\n"
			. "\tcolor:{$col['ink']} !important; text-decoration:none !important; font-weight:500; font-size:.95rem;\n"
			. "\tfont-family:{$body_stack} !important;\n"
			. "\tpadding:0 0 3px !important; margin:0 !important;\n"
			. "\tborder:0; border-bottom:1.5px solid transparent !important; border-radius:0 !important;\n"
			. "\tbackground:none !important; box-shadow:none !important; transition:color .15s, border-color .15s;\n}\n"
			. ".sc-menu a::before, .sc-menu a::after { content:none !important; display:none !important; }\n"
			. ".sc-menu a:hover, .sc-menu a:focus, .sc-menu .current-menu-item > a, .sc-menu .current-menu-ancestor > a {\n"
			. "\tcolor:{$col['accent']} !important; border-bottom-color:{$col['accent']} !important; background:none !important;\n}\n";

		// CTA button — copied faithfully from the source button where captured.
		if ( ! empty( $hd['cta']['enabled'] ) ) {
			$cs       = isset( $hd['cta']['style'] ) && is_array( $hd['cta']['style'] ) ? $hd['cta']['style'] : array();
			$btn_bg   = ! empty( $cs['bg'] ) ? $cs['bg'] : $col['ink'];
			$btn_fg   = ! empty( $cs['color'] ) ? $cs['color'] : $col['bg'];
			$btn_rad  = ! empty( $cs['radius'] ) ? $cs['radius'] : '9999px';
			$btn_pad  = ! empty( $cs['padding'] ) ? $cs['padding'] : '.62rem 1.35rem';
			$btn_wt   = ! empty( $cs['font_weight'] ) ? $cs['font_weight'] : '600';
			$css .= ".sc-header-btn {\n"
				. "\tbackground:{$btn_bg}; color:{$btn_fg}; padding:{$btn_pad}; border-radius:{$btn_rad};\n"
				. "\ttext-decoration:none; font-weight:{$btn_wt}; font-size:.9rem;\n"
				. "\tfont-family:{$body_stack}; white-space:nowrap; transition:filter .15s, background .15s, color .15s;\n}\n"
				. ".sc-header-btn:hover { filter:brightness(1.08); }\n";
		}

		// Page-body buttons — the hero / CTA links carried into the Home page
		// (`<a class="sc-page-btn">` emitted by the body mapper). First button reads as
		// the primary (filled), the rest as outline, matching a typical hero CTA pair.
		$css .= "\n/* Content buttons (page body) */\n"
			. ".sc-page-buttons { display:flex; gap:.8rem; flex-wrap:wrap; align-items:center; margin:1rem 0 0; }\n"
			. ".sc-page-btn { display:inline-block; background:{$col['ink']}; color:{$col['bg']}; padding:.7rem 1.5rem; border-radius:9999px; text-decoration:none; font-weight:600; font-family:{$body_stack}; transition:filter .15s; }\n"
			. ".sc-page-btn:hover { filter:brightness(1.08); }\n"
			. ".sc-page-buttons .sc-page-btn:not(:first-child) { background:transparent; color:{$col['ink']}; border:1.5px solid {$col['ink']}; }\n";

		// NOTE: accent/highlight text inside headings is mapped to the Primary Color Preset's
		// `.text-primary` utility (in FW_Site_Converter_Mapper::map_accent_classes), which the
		// preset engine paints via `:root .text-primary{color:var(--color-primary)!important}` —
		// so no bespoke accent-text rule is emitted here.

		// Page-body images (carried into the Home page from the source).
		$css .= "\n/* Content images (page body) */\n"
			. ".sc-page-figure { margin:1.6rem 0 0; }\n"
			. ".sc-page-figure img, img.sc-page-img { display:block; max-width:100%; height:auto; border-radius:14px; box-shadow:0 8px 30px rgba(0,0,0,.08); }\n";

		// Hero section — the converter tags the hero `section` with `.sc-hero`; the title
		// already enlarges via the special_heading's display-size. Add breathing room and
		// turn the overline pill into a clean accent outline (vs the theme's grey default).
		$css .= "\n/* Hero section */\n"
			. ".sc-hero .sc-page-buttons { margin-top:1.4rem; }\n"
			. ".sc-hero .sc-page-figure { margin-top:0; }\n"
			. ".sc-hero img.sc-page-img { width:100%; border-radius:24px; }\n"
			// Filled pill kicker matching the source's dark uppercase overline. The theme
			// styles the pill on the inner .heading-overline__label (which shrink-wraps) and
			// tints it from currentColor — so we fill the LABEL directly for real contrast.
			. ".sc-hero .heading-overline--pill .heading-overline__label { background:{$col['ink']}; color:{$col['bg']}; letter-spacing:.08em; }\n";

		// Hero decorative pattern overlay (the captured "+" grid, etc.) — a `::before`
		// behind the content, so it tiles across the hero exactly like the source.
		$hpat = isset( $cfg['hero']['pattern'] ) && is_array( $cfg['hero']['pattern'] ) ? $cfg['hero']['pattern'] : array();
		if ( ! empty( $hpat['image'] ) ) {
			$css .= ".sc-hero { position:relative; overflow:hidden; }\n"
				. ".sc-hero > * { position:relative; z-index:1; }\n"
				. ".sc-hero::before { content:''; position:absolute; inset:0; z-index:0; pointer-events:none;\n"
				. "\tbackground-image:{$hpat['image']};\n"
				. "\tbackground-repeat:{$hpat['repeat']};\n"
				. "\topacity:{$hpat['opacity']};\n}\n";
		}

		// Process / steps section — the step NUMBER lives in the icon_box icon slot
		// (.icon-box__icon); render it as a big, faint accent step marker like the source.
		$css .= "\n/* Process / steps section */\n"
			. ".sc-steps .icon-box__icon { font-family:{$head_stack}; font-size:2.4rem; font-weight:700; line-height:1; color:{$col['accent']}; opacity:.35; margin-bottom:.4rem; }\n"
			. ".sc-steps .icon-box__head h3, .sc-steps .icon-box__title { margin-top:.2rem; }\n"
			// Stat tiles in a feature grid — the animated counter number in the accent
			// color + display font (.sc-stat kept for any older icon_box-based stats).
			. ".sc-features .fw-counter__value, .sc-stat .icon-box__icon { font-family:{$head_stack}; color:{$col['accent']}; }\n"
			// Stat caption is now a sibling text block (.sc-stat-label), not a counter label.
			. ".sc-features .sc-stat-label { margin:.2rem 0 0; font-size:.85rem; text-transform:uppercase; letter-spacing:.05em; opacity:.7; }\n"
			. ".sc-stat .icon-box__icon { font-size:2.6rem; font-weight:700; line-height:1; margin-bottom:.2rem; }\n"
			. ".sc-stat .icon-box__title { font-size:.85rem; text-transform:uppercase; letter-spacing:.05em; opacity:.7; }\n";

		// Card icons — the Material Symbols class (the Google css2 URL only ships the
		// @font-face; the consuming page must define the class), plus the card-icon
		// modifier used on the converted icon_box cards.
		if ( ! empty( $cfg['fonts']['icons'] ) ) {
			$css .= "\n/* Card icons (Material Symbols) */\n"
				. ".material-symbols-outlined {\n"
				. "\tfont-family:'Material Symbols Outlined'; font-weight:normal; font-style:normal;\n"
				. "\tfont-size:24px; line-height:1; letter-spacing:normal; text-transform:none;\n"
				. "\tdisplay:inline-block; white-space:nowrap; word-wrap:normal; direction:ltr;\n"
				. "\t-webkit-font-feature-settings:'liga'; -webkit-font-smoothing:antialiased;\n"
				. "\tfont-variation-settings:'FILL' 0,'wght' 400,'GRAD' 0,'opsz' 24;\n}\n"
				. ".sc-card-icon { font-size:2.1rem; color:{$col['accent']}; margin:0 0 .5rem; }\n";
		}

		// Footer.
		$css .= "\n/* Footer */\n"
			. "#colophon.sc-footer { background:{$col['footer_bg']}; color:{$col['footer_text']}; padding:2.6rem 0; }\n"
			. ".sc-footer-inner { max-width:1140px; margin:0 auto; padding:0 1.5rem; display:flex; flex-direction:column; gap:1.5rem; }\n"
			. ".sc-footer-top { display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:1.5rem 2.5rem; }\n"
			. ".sc-footer-brand { font-family:{$head_stack}; font-weight:700; font-size:1.2rem; }\n"
			// Footer menu: top-level items are columns; their sub-menus are the link lists.
			. ".sc-footer-nav .sc-footer-menu { display:flex; gap:2.5rem; flex-wrap:wrap; list-style:none; margin:0; padding:0; }\n"
			. ".sc-footer-menu > li { margin:0; }\n"
			. ".sc-footer-menu > li > a { font-weight:600; font-size:.82rem; text-transform:uppercase; letter-spacing:.06em; opacity:.7; text-decoration:none; }\n"
			. ".sc-footer-menu > li:not(.menu-item-has-children) > a { text-transform:none; font-weight:500; font-size:.9rem; opacity:.9; }\n"
			. ".sc-footer-menu .sub-menu { list-style:none; margin:.6rem 0 0; padding:0; display:flex; flex-direction:column; gap:.4rem; }\n"
			. ".sc-footer-menu .sub-menu a { font-size:.9rem; text-decoration:none; opacity:.9; }\n"
			. ".sc-footer-menu a:hover { opacity:1; text-decoration:underline; }\n"
			. ".sc-footer-widgets { display:flex; gap:2rem; flex-wrap:wrap; }\n"
			. ".sc-footer-widgets .sc-widget h4 { font-size:.8rem; text-transform:uppercase; letter-spacing:.08em; opacity:.7; margin:0 0 .5rem; }\n"
			. ".sc-footer-social { display:flex; gap:1.1rem; flex-wrap:wrap; }\n"
			. ".sc-footer-social a { font-size:.85rem; text-decoration:none; opacity:.85; }\n"
			. ".sc-footer-social a:hover { opacity:1; text-decoration:underline; }\n"
			. ".sc-footer-copy { font-size:.85rem; opacity:.75; border-top:1px solid rgba(128,128,128,.25); padding-top:1.1rem; }\n"
			. "#colophon.sc-footer a { color:{$col['footer_text']}; }\n\n"
			// Responsive.
			. "@media (max-width:782px){\n"
			. "\t.sc-header-inner { flex-wrap:wrap; border-radius:" . ( $is_pill ? '22px' : $radius ) . "; }\n"
			. "\t.sc-header .primary-menu { order:3; flex-basis:100%; justify-content:flex-start; }\n"
			. "\t.sc-menu { flex-wrap:wrap; gap:1rem; }\n"
			. "\t.sc-footer-nav .sc-footer-menu { gap:1.5rem; }\n}\n";

		// Carried extra CSS from the source (already scoped by the emitter, appended verbatim).
		if ( trim( $cfg['custom_css'] ) !== '' ) {
			$css .= "\n/* ---- Carried CSS from the source ---- */\n" . $cfg['custom_css'] . "\n";
		}

		return $css;
	}

	/** functions.php (child) — font + stylesheet enqueue, footer widget area, CTA dedupe. */
	private static function functions_php( array $cfg ) {
		$t       = $cfg['theme'];
		$slug    = $t['slug'];
		$fn      = self::fn_prefix( $slug );
		$google  = $cfg['fonts']['google'];
		$loc     = $cfg['header']['menu_location'];
		$cta     = $cfg['header']['cta'];
		$standalone = $t['mode'] === 'standalone';
		$raw     = self::has_raw_chrome( $cfg );
		$linked  = isset( $cfg['raw_chrome']['linked_css'] ) && is_array( $cfg['raw_chrome']['linked_css'] ) ? $cfg['raw_chrome']['linked_css'] : array();

		$out  = "<?php\n";
		$out .= "/**\n * " . $t['name'] . " — generated by the Unyson+ Site Converter.\n";
		$out .= " *\n * Enqueues the source webfonts + the chrome stylesheet (late, so it wins the\n";
		$out .= " * cascade over the parent + Unyson shortcode CSS) and registers a footer widget\n";
		$out .= " * area. The header/footer markup lives in the overridden template-parts.\n */\n";
		$out .= "if ( ! defined( 'ABSPATH' ) ) { exit; }\n\n";

		// In standalone mode this file is appended-included from the copied parent
		// functions.php, so it must be self-guarding and not redeclare parent funcs.
		$out .= "/** Webfonts + chrome stylesheet (priority 20 → loads after the parent). */\n";
		$out .= "function {$fn}_assets() {\n";
		if ( $google !== '' ) {
			$out .= "\twp_enqueue_style( '{$slug}-fonts', '" . esc_url_raw( $google ) . "', array(), null );\n";
		}
		$icons = isset( $cfg['fonts']['icons'] ) ? $cfg['fonts']['icons'] : '';
		if ( $icons !== '' ) {
			$out .= "\twp_enqueue_style( '{$slug}-icons', '" . esc_url_raw( $icons ) . "', array(), null );\n";
		}
		// Raw-chrome mode: re-link the source's cross-origin stylesheets (CDN Bootstrap,
		// Font Awesome, Google Fonts CSS) so the mirrored markup is fully styled. The chrome
		// CSS then depends on these handles so it loads last and wins the cascade.
		$dep_handles = '';
		if ( $raw && $linked ) {
			$handles = array();
			foreach ( array_values( $linked ) as $i => $u ) {
				$h = "{$slug}-lib-{$i}";
				$handles[] = "'{$h}'";
				$out .= "\twp_enqueue_style( '{$h}', '" . esc_url_raw( $u ) . "', array(), null );\n";
			}
			$dep_handles = 'array( ' . implode( ', ', $handles ) . ' )';
		}
		if ( $standalone ) {
			// Standalone: our generated CSS is appended to the theme's own style.css,
			// which the parent's functions already enqueues — so only fonts here.
			$out .= "\t// Standalone: chrome CSS lives in this theme's style.css (already enqueued).\n";
		} else {
			// Child mode: the parent theme ALREADY enqueues this child's style.css as the
			// 'child-style' handle and orders it to load dead-last (after parent-style, which
			// depends on every other queued sheet — including the re-linked source libraries
			// above). We do NOT enqueue it again — that produced a duplicate <link>. We only
			// re-version it by file mtime so a rebuilt stylesheet busts the browser cache (the
			// parent versions child-style by the static theme version, which never changes).
			$out .= "\t\$sc_style = get_stylesheet_directory() . '/style.css';\n";
			$out .= "\t\$sc_styles = wp_styles();\n";
			$out .= "\tif ( file_exists( \$sc_style ) && isset( \$sc_styles->registered['child-style'] ) ) {\n";
			$out .= "\t\t\$sc_styles->registered['child-style']->ver = (string) filemtime( \$sc_style );\n";
			$out .= "\t}\n";
		}
		if ( $raw ) {
			// Re-hydration script for the verbatim mirror (footer, no deps). The mapped per-section
			// CSS is merged into style.css (one child stylesheet), so there's nothing extra here.
			$out .= "\twp_enqueue_script( '{$slug}-interactivity', get_stylesheet_directory_uri() . '/assets/js/interactivity.js', array(), wp_get_theme()->get( 'Version' ), true );\n";
		}
		$out .= "}\n";
		$out .= "add_action( 'wp_enqueue_scripts', '{$fn}_assets', 20 );\n\n";

		// Raw-chrome mode now drives the DYNAMIC header + footer the mirror relies on: the nav
		// mapper's tree → a real WP menu (the <!--SC_NAV--> spot renders it via wp_nav_menu); the
		// custom logo (the mirror's brand <img> → the_custom_logo); and the footer columns →
		// the parent's footer-N widget areas + a child "Footer Copyright" area, each seeded with a
		// Custom HTML placeholder (the <!--SC_FCOL_i-->/<!--SC_FCOPY--> spots render dynamic_sidebar).
		if ( $raw ) {
			$nav_items = self::nav_tree_items( isset( $cfg['raw_chrome']['nav_tree'] ) ? $cfg['raw_chrome']['nav_tree'] : array() );
			if ( $nav_items ) {
				$out .= self::menu_bootstrap_code( $fn, 'header_menu', 'Header', $loc, $nav_items );
			}
			$logo_src = isset( $cfg['header']['logo_src'] ) ? (string) $cfg['header']['logo_src'] : '';
			if ( $cfg['theme']['mode'] === 'child' && $logo_src !== '' ) {
				$out .= "/** Set the custom logo from the source logo image (once). */\n";
				$out .= "function {$fn}_seed_logo() {\n";
				$out .= "\tif ( get_option( '{$fn}_logo_seeded' ) ) { return; }\n";
				$out .= "\t\$sc_logo = get_posts( array( 'post_type' => 'attachment', 'posts_per_page' => 1, 'fields' => 'ids', 'meta_query' => array( array( 'key' => '_unysonplus_source_url', 'value' => '" . self::esc_php( $logo_src ) . "' ) ) ) );\n";
				$out .= "\tif ( ! empty( \$sc_logo ) ) { set_theme_mod( 'custom_logo', (int) \$sc_logo[0] ); }\n";
				$out .= "\tupdate_option( '{$fn}_logo_seeded', 1 );\n";
				$out .= "}\n";
				$out .= "add_action( 'wp_loaded', '{$fn}_seed_logo', 20 );\n\n";
			}
			$out .= self::footer_widgets_code( $fn, $slug, $cfg );
			return $out;
		}

		if ( ! empty( $cfg['footer']['widget_area'] ) ) {
			$out .= "/** Footer widget area (rendered by the footer template part when populated). */\n";
			$out .= "function {$fn}_widgets() {\n";
			$out .= "\tregister_sidebar( array(\n";
			$out .= "\t\t'name'          => __( 'Footer', '{$slug}' ),\n";
			$out .= "\t\t'id'            => 'sc-footer-widgets',\n";
			$out .= "\t\t'description'   => __( 'Footer columns shown in the converted footer.', '{$slug}' ),\n";
			$out .= "\t\t'before_widget' => '<div class=\"sc-widget %2\$s\">',\n";
			$out .= "\t\t'after_widget'  => '</div>',\n";
			$out .= "\t\t'before_title'  => '<h4>',\n";
			$out .= "\t\t'after_title'   => '</h4>',\n";
			$out .= "\t) );\n";
			$out .= "}\n";
			$out .= "add_action( 'widgets_init', '{$fn}_widgets' );\n\n";
		}

		// Footer menu location — always registered (so the user can assign a menu),
		// and auto-built from the captured footer links on activation when present.
		$footer_menu = isset( $cfg['footer']['menu'] ) && is_array( $cfg['footer']['menu'] ) ? $cfg['footer']['menu'] : array();
		$out .= "/** Footer menu location (the converted footer's link columns). */\n";
		$out .= "function {$fn}_menus() {\n";
		$out .= "\tregister_nav_menus( array( 'sc_footer' => __( 'Footer', '{$slug}' ) ) );\n";
		$out .= "}\n";
		$out .= "add_action( 'after_setup_theme', '{$fn}_menus' );\n\n";

		// Header menu — the captured top nav, bootstrapped to the header location so the
		// header is populated the moment the theme is activated (same self-healing trick
		// as the footer, which sidesteps the theme-switch nav_menu_locations reset).
		$header_menu = isset( $cfg['header']['menu'] ) && is_array( $cfg['header']['menu'] ) ? $cfg['header']['menu'] : array();
		if ( $header_menu ) {
			$out .= self::menu_bootstrap_code( $fn, 'header_menu', 'Header', $loc, $header_menu );
		}
		if ( $footer_menu ) {
			$out .= self::menu_bootstrap_code( $fn, 'footer_menu', 'Footer', 'sc_footer', $footer_menu );
		}

		// Seed the editable header layout (Appearance → Theme Settings → Header) once on activation:
		// Logo (→ custom logo / Site Title) + the Primary menu + the source's CTA button. The header
		// itself is the PARENT's Theme-Settings header-builder template part (we don't override it in
		// child mode), so the user can rearrange logo / menu / button right in Theme Settings.

		if ( ! empty( $cta['enabled'] ) && ! empty( $cta['dedupe_from_menu'] ) ) {
			$label = $cta['label'];
			$out .= "/**\n * Drop the CTA item from the " . strtoupper( $loc ) . " nav — it's rendered as the header\n";
			$out .= " * button, so keeping it in the menu too would duplicate it.\n */\n";
			$out .= "function {$fn}_dedupe_cta( \$items, \$args ) {\n";
			$out .= "\tif ( isset( \$args->theme_location ) && '{$loc}' === \$args->theme_location ) {\n";
			$out .= "\t\t\$items = array_filter( \$items, function ( \$item ) {\n";
			$out .= "\t\t\treturn 0 !== strcasecmp( trim( wp_strip_all_tags( \$item->title ) ), '" . str_replace( "'", "\\'", $label ) . "' );\n";
			$out .= "\t\t} );\n";
			$out .= "\t}\n";
			$out .= "\treturn \$items;\n";
			$out .= "}\n";
			$out .= "add_filter( 'wp_nav_menu_objects', '{$fn}_dedupe_cta', 10, 2 );\n";
		}

		return $out;
	}

	/** Is the raw-chrome (verbatim HTML mirror) path active for this config? */
	private static function has_raw_chrome( array $cfg ) {
		return ! empty( $cfg['raw_chrome']['header_html'] ) || ! empty( $cfg['raw_chrome']['footer_html'] );
	}

	/** A template part that emits captured HTML verbatim (raw-chrome mirror). */
	private static function raw_part( $which, $html ) {
		$label = 'header' === $which ? 'header' : 'footer';
		return "<?php if ( ! defined( 'ABSPATH' ) ) { die( 'Direct access forbidden.' ); } ?>\n"
			. "<?php /* Converted {$label} — verbatim HTML mirror of the source (Site Converter raw-chrome).\n"
			. "   Styled by the captured CSS in this theme's style.css + any re-linked libraries. */ ?>\n"
			. $html . "\n";
	}

	/** template-parts/header-builder.php — logo (site) | menu | optional CTA. */
	/**
	 * header.php — the child theme's OWN document head + opening chrome, overriding the parent's. It
	 * mirrors the parent's wrapper structure (#page → header → #content) so page templates and the
	 * footer line up, but it renders the converted header directly via the child's header-builder
	 * template part — never routing through the parent's Theme Builder header resolver.
	 */
	private static function header_php( array $cfg ) {
		$slug = $cfg['theme']['slug'];
		$out  = "<?php\n";
		$out .= "/**\n";
		$out .= " * Converted site header — generated by the Unyson+ Site Converter.\n";
		$out .= " * The child theme owns header.php outright so the source's chrome renders directly,\n";
		$out .= " * with no Theme Builder / Theme Settings indirection. Edit the markup in\n";
		$out .= " * template-parts/header-builder.php.\n";
		$out .= " */\n";
		$out .= "if ( ! defined( 'ABSPATH' ) ) { die( 'Direct access forbidden.' ); }\n";
		$out .= "?><!DOCTYPE html>\n";
		$out .= "<html <?php language_attributes(); ?>>\n";
		$out .= "<head>\n";
		$out .= "\t<meta charset=\"<?php bloginfo( 'charset' ); ?>\">\n";
		$out .= "\t<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n";
		// Mirror theme: load the source's web fonts via <link> in the head (an @import in style.css loads
		// an icon font too late, leaving its ligature NAME showing as text).
		if ( ! empty( $cfg['mirror'] ) && ! empty( $cfg['font_links'] ) ) {
			$out .= "\t<link rel=\"preconnect\" href=\"https://fonts.googleapis.com\">\n";
			$out .= "\t<link rel=\"preconnect\" href=\"https://fonts.gstatic.com\" crossorigin>\n";
			foreach ( $cfg['font_links'] as $href ) {
				$out .= "\t<link rel=\"stylesheet\" href=\"" . esc_url( $href ) . "\">\n";
			}
		}
		$out .= "\t<?php wp_head(); ?>\n";
		$out .= "</head>\n\n";
		$out .= "<body <?php body_class(); ?>>\n";
		$out .= "<?php wp_body_open(); ?>\n";
		$out .= "<div id=\"page\" class=\"site\">\n";
		if ( ! empty( $cfg['mirror'] ) ) {
			// Mirror theme: render the source's <header> verbatim (STATIC) inside a .sc-tw wrapper so the
			// reproduced Tailwind CSS styles it exactly, then open the content area for the body sections.
			if ( ! empty( $cfg['mirror_header_html'] ) ) {
				$out .= "\t<div class=\"sc-tw\">\n" . $cfg['mirror_header_html'] . "\n\t</div>\n";
			}
			$out .= "\t<div id=\"content\" class=\"site-content\">\n";
			return $out;
		}
		$out .= "\t<a class=\"skip-link screen-reader-text\" href=\"#content\"><?php esc_html_e( 'Skip to content', '{$slug}' ); ?></a>\n";
		$out .= "\t<?php do_action( 'unysonplus_before_header' ); ?>\n";
		$out .= "\t<?php get_template_part( 'template-parts/header', 'builder' ); ?>\n";
		$out .= "\t<?php do_action( 'unysonplus_after_header' ); ?>\n";
		$out .= "\t<div id=\"content\" class=\"site-content\">\n";
		return $out;
	}

	/**
	 * footer.php — the child theme's OWN closing chrome, overriding the parent's. Closes the wrappers
	 * header.php opened (#content → #page), renders the converted footer via the child's footer-builder
	 * template part inside <footer id="colophon">, then wp_footer(). Bypasses the Theme Builder footer
	 * resolver entirely.
	 */
	private static function footer_php( array $cfg ) {
		$out  = "<?php if ( ! defined( 'ABSPATH' ) ) { die( 'Direct access forbidden.' ); } ?>\n";
		$out .= "\t</div><!-- #content -->\n";
		if ( empty( $cfg['mirror'] ) ) {
			$out .= "\t<?php do_action( 'unysonplus_before_footer' ); ?>\n";
			// A MIRRORED source footer (raw-chrome capture) owns its OWN colors/layout via the carried
			// source CSS, so DON'T tag the wrapper `.footer` — the theme's `.footer a { color:white }`
			// rule would clobber the mirrored links (they're white-on-light = invisible). Use a neutral
			// id-only wrapper class for raw chrome; keep `.footer` for the theme's own builder footer.
			$raw_footer = self::has_raw_chrome( $cfg ) && ! empty( $cfg['raw_chrome']['footer_html'] );
			$colo_cls   = $raw_footer ? 'sc-raw-footer' : 'footer';
			$out .= "\t<footer id=\"colophon\" class=\"" . $colo_cls . "\" role=\"contentinfo\">\n";
			$out .= "\t\t<?php get_template_part( 'template-parts/footer', 'builder' ); ?>\n";
			$out .= "\t</footer><!-- #colophon -->\n";
			$out .= "\t<?php do_action( 'unysonplus_after_footer' ); ?>\n";
		} elseif ( ! empty( $cfg['mirror_footer_html'] ) ) {
			// Mirror theme: the source's <footer> verbatim (STATIC), in a .sc-tw wrapper for the CSS.
			$out .= "\t<div class=\"sc-tw\">\n" . $cfg['mirror_footer_html'] . "\n\t</div>\n";
		}
		$out .= "</div><!-- #page -->\n";
		$out .= "<?php do_action( 'unysonplus_after' ); ?>\n";
		$out .= "<?php wp_footer(); ?>\n";
		$out .= "</body>\n";
		$out .= "</html>\n";
		return $out;
	}

	/**
	 * Build a chrome template part from AI-authored markup. The markup uses semantic classes (styled by
	 * the AI stylesheet) + placeholders we swap for live WordPress output. The model markup is sanitized
	 * (no <script>/<style>/<?php/on* can ride in) BEFORE the swaps inject their PHP, so nothing the
	 * model wrote is ever executed.
	 *
	 * @param string $which 'header' | 'footer'
	 */
	private static function ai_chrome_part( $which, $html, array $cfg ) {
		$loc = $cfg['header']['menu_location'];

		$logo = '<a class="sc-logo" href="<?php echo esc_url( home_url( \'/\' ) ); ?>">'
			. '<?php if ( function_exists( "has_custom_logo" ) && has_custom_logo() ) { the_custom_logo(); } else { echo esc_html( get_bloginfo( "name" ) ); } ?></a>';
		$nav  = '<?php wp_nav_menu( array( "theme_location" => "' . $loc . '", "container" => false, "menu_class" => "sc-nav-menu", "depth" => 3, "fallback_cb" => false ) ); ?>';
		$cta  = '';
		if ( ! empty( $cfg['header']['cta']['enabled'] ) ) {
			$c    = $cfg['header']['cta'];
			$href = preg_match( '#^https?://#i', $c['href'] )
				? "'" . esc_url_raw( $c['href'] ) . "'"
				: "home_url( '" . self::esc_php( $c['href'] === '' ? '/' : $c['href'] ) . "' )";
			$cta  = '<a class="sc-cta" href="<?php echo esc_url( ' . $href . ' ); ?>"><?php esc_html_e( \'' . self::esc_php( $c['label'] ) . "', '{$cfg['theme']['slug']}' ); ?></a>";
		}
		$copy = '&copy; <?php echo esc_html( gmdate( "Y" ) ); ?> <?php echo esc_html( get_bloginfo( "name" ) ); ?>';

		$safe = self::kses_chrome( $html );
		$safe = str_replace(
			array( '{{LOGO}}', '{{NAV}}', '{{CTA}}', '{{COPYRIGHT}}' ),
			array( $logo, $nav, $cta, $copy ),
			$safe
		);

		$out  = "<?php if ( ! defined( 'ABSPATH' ) ) { die( 'Direct access forbidden.' ); } ?>\n";
		$out .= "<?php /* Converted {$which} — AI-authored markup; styled by the theme's style.css. */ ?>\n";
		$out .= $safe . "\n";
		return $out;
	}

	/** Whitelist chrome markup from the model: strip executable content, then wp_kses to safe tags/attrs. */
	private static function kses_chrome( $html ) {
		$html = (string) $html;
		$html = preg_replace( '#<\?.*?\?>#s', '', $html );                       // any PHP
		$html = preg_replace( '#<(script|style)\b[^>]*>.*?</\1>#is', '', $html ); // script/style blocks
		$attr = array( 'class' => true, 'id' => true, 'href' => true, 'src' => true, 'alt' => true,
			'role' => true, 'aria-label' => true, 'aria-hidden' => true, 'target' => true, 'rel' => true, 'title' => true );
		$allowed = array();
		foreach ( array( 'header','footer','nav','div','section','span','a','ul','ol','li','p',
			'h1','h2','h3','h4','h5','h6','img','button','strong','em','b','i','br','small','figure','figcaption' ) as $t ) {
			$allowed[ $t ] = $attr;
		}
		return function_exists( 'wp_kses' ) ? wp_kses( $html, $allowed ) : strip_tags( $html, '<header><footer><nav><div><section><span><a><ul><ol><li><p><h1><h2><h3><h4><h5><h6><img><button><strong><em><b><i><br><small><figure><figcaption>' );
	}

	private static function header_part( array $cfg ) {
		// AI-authored header markup (semantic classes styled by the AI stylesheet).
		if ( ! empty( $cfg['ai_header_html'] ) ) {
			return self::ai_chrome_part( 'header', (string) $cfg['ai_header_html'], $cfg );
		}
		$loc  = $cfg['header']['menu_location'];

		// Source-header clone with the two dynamic swaps: the brand logo → the site's own logo
		// (custom logo → Site Title), and the <!--SC_NAV--> marker → a live WordPress menu
		// (wp_nav_menu, styled by the generated .sc-menu rules to match the source nav). Everything
		// else stays the source's exact markup so the carried CSS clones the layout.
		if ( ! empty( $cfg['raw_chrome']['header_html'] ) ) {
			$html = (string) $cfg['raw_chrome']['header_html'];
			$logo = '<?php if ( function_exists( "has_custom_logo" ) && has_custom_logo() ) { the_custom_logo(); } else { echo esc_html( get_bloginfo( "name" ) ); } ?>';
			$html = preg_replace( '/<img\b[^>]*>/i', $logo, $html, 1 );
			$menu = '<?php wp_nav_menu( array( "theme_location" => "' . $loc . '", "container" => false, "menu_class" => "sc-menu", "depth" => 3, "fallback_cb" => false ) ); ?>';
			$html = str_replace( '<!--SC_NAV-->', $menu, $html );
			return self::raw_part( 'header', $html );
		}

		$slug = $cfg['theme']['slug'];
		$cta  = $cfg['header']['cta'];

		$out  = "<?php if ( ! defined( 'ABSPATH' ) ) { die( 'Direct access forbidden.' ); }\n";
		$out .= "/**\n * Converted header — overrides the parent's header-builder template part.\n";
		$out .= " * Layout: " . $cfg['header']['layout'] . ". Logo is the site's own (custom_logo →\n";
		$out .= " * Site Title) — never the source's brand text. Keeps #masthead so carried CSS applies.\n */\n?>\n";
		$out .= "<header id=\"masthead\" class=\"site-header sc-header\" role=\"banner\">\n";
		$out .= "\t<div class=\"sc-header-inner\">\n";
		$out .= "\t\t<div class=\"sc-logo\">\n";
		$out .= "\t\t\t<?php if ( function_exists( 'has_custom_logo' ) && has_custom_logo() ) : ?>\n";
		$out .= "\t\t\t\t<?php the_custom_logo(); ?>\n";
		$out .= "\t\t\t<?php else : ?>\n";
		$out .= "\t\t\t\t<a href=\"<?php echo esc_url( home_url( '/' ) ); ?>\" class=\"sc-logo-text\"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></a>\n";
		$out .= "\t\t\t<?php endif; ?>\n";
		$out .= "\t\t</div>\n";
		$out .= "\t\t<nav class=\"primary-menu\" aria-label=\"<?php esc_attr_e( 'Primary', '{$slug}' ); ?>\">\n";
		$out .= "\t\t\t<?php\n";
		$out .= "\t\t\twp_nav_menu( array(\n";
		$out .= "\t\t\t\t'theme_location' => '{$loc}',\n";
		$out .= "\t\t\t\t'container'      => false,\n";
		$out .= "\t\t\t\t'menu_class'     => 'sc-menu',\n";
		$out .= "\t\t\t\t'depth'          => 2,\n";
		$out .= "\t\t\t\t'fallback_cb'    => false,\n";
		$out .= "\t\t\t) );\n";
		$out .= "\t\t\t?>\n";
		$out .= "\t\t</nav>\n";
		if ( ! empty( $cta['enabled'] ) ) {
			$href = $cta['href'];
			// Render an internal "/..." href via home_url(); leave absolute URLs as-is.
			$href_php = preg_match( '#^https?://#i', $href )
				? "'" . esc_url_raw( $href ) . "'"
				: "home_url( '" . str_replace( "'", "\\'", ltrim( $href, '/' ) === $href ? '/' . $href : $href ) . "' )";
			$out .= "\t\t<div class=\"sc-header-cta\">\n";
			$out .= "\t\t\t<a class=\"sc-header-btn\" href=\"<?php echo esc_url( {$href_php} ); ?>\"><?php esc_html_e( '" . str_replace( "'", "\\'", $cta['label'] ) . "', '{$slug}' ); ?></a>\n";
			$out .= "\t\t</div>\n";
		}
		$out .= "\t</div>\n";
		$out .= "</header>\n";

		return $out;
	}

	/** template-parts/footer-builder.php — brand + link columns (menu) + widgets + social + copyright. */
	/**
	 * functions.php block for the dynamic footer: the source's footer columns map to footer-1..N
	 * widget areas. The parent registers footer-1..5; any beyond that (e.g. a 3-row × 4-col footer
	 * = 12) are registered HERE, matching the parent's widget wrapper. A child "Footer Copyright"
	 * area (no wrapper) holds the copyright. Each area is seeded once with a Custom HTML placeholder
	 * (the captured column/copyright HTML) the user then swaps for menus / social icons / text.
	 */
	private static function footer_widgets_code( $fn, $slug, array $cfg ) {
		$cols = isset( $cfg['raw_chrome']['footer_cols'] ) && is_array( $cfg['raw_chrome']['footer_cols'] ) ? $cfg['raw_chrome']['footer_cols'] : array();
		$copy = isset( $cfg['raw_chrome']['footer_copyright'] ) ? (string) $cfg['raw_chrome']['footer_copyright'] : '';
		$n    = count( $cols );
		if ( $n === 0 && $copy === '' ) { return ''; }

		$out  = "/** Footer widget areas (columns → footer-1..{$n}; extras beyond the parent's 5 added here) + copyright. */\n";
		$out .= "function {$fn}_footer_widgets() {\n";
		if ( $n > 5 ) {
			$out .= "\t\$bw = '<aside id=\"%1\$s\" class=\"widget %2\$s pb-3 pb-md-0\">'; \$aw = '</aside>';\n";
			$out .= "\tfor ( \$i = 6; \$i <= {$n}; \$i++ ) {\n";
			$out .= "\t\tregister_sidebar( array( 'name' => sprintf( __( 'Footer Column %d', '{$slug}' ), \$i ), 'id' => 'footer-' . \$i, 'before_widget' => \$bw, 'after_widget' => \$aw, 'before_title' => '<div class=\"widget-title\"><span>', 'after_title' => '</span></div>', 'description' => '' ) );\n";
			$out .= "\t}\n";
		}
		if ( $copy !== '' ) {
			$out .= "\tregister_sidebar( array( 'name' => __( 'Footer Copyright', '{$slug}' ), 'id' => 'sc-footer-copyright', 'before_widget' => '', 'after_widget' => '', 'before_title' => '', 'after_title' => '', 'description' => '' ) );\n";
		}
		$out .= "}\n";
		$out .= "add_action( 'widgets_init', '{$fn}_footer_widgets' );\n\n";

		$out .= "/** Seed the footer Custom HTML placeholders once (cleared on re-convert so it re-applies). */\n";
		$out .= "function {$fn}_seed_footer_widgets() {\n";
		$out .= "\tif ( get_option( '{$fn}_footer_widgets_seeded' ) ) { return; }\n";
		$out .= "\t\$cols = array(\n";
		foreach ( $cols as $h ) { $out .= "\t\tbase64_decode( '" . base64_encode( (string) $h ) . "' ),\n"; }
		$out .= "\t);\n";
		$out .= "\t\$copy = base64_decode( '" . base64_encode( $copy ) . "' );\n";
		$out .= "\t\$insts = get_option( 'widget_custom_html', array() ); if ( ! is_array( \$insts ) ) { \$insts = array(); }\n";
		$out .= "\t\$sb = get_option( 'sidebars_widgets', array() ); if ( ! is_array( \$sb ) ) { \$sb = array(); }\n";
		$out .= "\t\$next = 1; foreach ( array_keys( \$insts ) as \$k ) { if ( is_numeric( \$k ) && (int) \$k >= \$next ) { \$next = (int) \$k + 1; } }\n";
		$out .= "\t\$targets = array( 'sc-footer-copyright' ); foreach ( \$cols as \$i => \$h ) { \$targets[] = 'footer-' . ( \$i + 1 ); }\n";
		$out .= "\tforeach ( \$targets as \$sid ) { if ( isset( \$sb[ \$sid ] ) && is_array( \$sb[ \$sid ] ) ) { \$sb[ \$sid ] = array(); } }\n"; // clear so re-convert replaces (no dup)
		$out .= "\t\$add = function ( \$sid, \$html ) use ( &\$insts, &\$sb, &\$next ) {\n";
		$out .= "\t\tif ( trim( (string) \$html ) === '' ) { return; }\n";
		$out .= "\t\t\$idx = \$next++;\n";
		$out .= "\t\t\$insts[ \$idx ] = array( 'content' => (string) \$html );\n";
		$out .= "\t\tif ( ! isset( \$sb[ \$sid ] ) || ! is_array( \$sb[ \$sid ] ) ) { \$sb[ \$sid ] = array(); }\n";
		$out .= "\t\t\$sb[ \$sid ][] = 'custom_html-' . \$idx;\n";
		$out .= "\t};\n";
		$out .= "\tforeach ( \$cols as \$i => \$h ) { \$add( 'footer-' . ( \$i + 1 ), \$h ); }\n";
		$out .= "\t\$add( 'sc-footer-copyright', \$copy );\n";
		$out .= "\t\$insts['_multiwidget'] = 1;\n";
		$out .= "\tupdate_option( 'widget_custom_html', \$insts );\n";
		$out .= "\tupdate_option( 'sidebars_widgets', \$sb );\n";
		$out .= "\tupdate_option( '{$fn}_footer_widgets_seeded', 1 );\n";
		$out .= "}\n";
		$out .= "add_action( 'wp_loaded', '{$fn}_seed_footer_widgets', 25 );\n\n";
		return $out;
	}

	private static function footer_part( array $cfg ) {
		// AI-authored footer markup (semantic classes styled by the AI stylesheet).
		if ( ! empty( $cfg['ai_footer_html'] ) ) {
			return self::ai_chrome_part( 'footer', (string) $cfg['ai_footer_html'], $cfg );
		}
		// Source-footer clone with the columns + copyright made DYNAMIC: each column's content was
		// marked <!--SC_FCOL_i--> and the copyright <!--SC_FCOPY-->; swap them for dynamic_sidebar()
		// so the content comes from the parent's footer-N widget areas + the child copyright area
		// (Custom HTML placeholders the user edits in Appearance → Widgets). Layout stays the source.
		if ( ! empty( $cfg['raw_chrome']['footer_html'] ) ) {
			$html = (string) $cfg['raw_chrome']['footer_html'];
			$cols = isset( $cfg['raw_chrome']['footer_cols'] ) && is_array( $cfg['raw_chrome']['footer_cols'] ) ? $cfg['raw_chrome']['footer_cols'] : array();
			foreach ( array_keys( $cols ) as $i ) {
				$html = str_replace( '<!--SC_FCOL_' . $i . '-->', '<?php if ( is_active_sidebar( "footer-' . ( (int) $i + 1 ) . '" ) ) { dynamic_sidebar( "footer-' . ( (int) $i + 1 ) . '" ); } ?>', $html );
			}
			$html = str_replace( '<!--SC_FCOPY-->', '<?php if ( is_active_sidebar( "sc-footer-copyright" ) ) { dynamic_sidebar( "sc-footer-copyright" ); } ?>', $html );
			return self::raw_part( 'footer', $html );
		}

		$slug   = $cfg['theme']['slug'];
		$ft     = $cfg['footer'];
		$social = isset( $ft['social'] ) && is_array( $ft['social'] ) ? $ft['social'] : array();

		$out  = "<?php if ( ! defined( 'ABSPATH' ) ) { die( 'Direct access forbidden.' ); }\n";
		$out .= "/**\n * Converted footer — overrides the parent's footer-builder template part.\n";
		$out .= " * Site-Title brand + link columns (the editable \"Footer\" menu) + widget area +\n";
		$out .= " * social + dynamic copyright. Rendered inside the parent's <footer id=\"colophon\">.\n */\n?>\n";
		$out .= "<div class=\"sc-footer-inner\">\n";
		$out .= "\t<div class=\"sc-footer-top\">\n";
		if ( ! empty( $ft['brand'] ) ) {
			$out .= "\t\t<div class=\"sc-footer-brand\"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></div>\n";
		}
		// Link columns — the editable Footer menu (built on activation from the source).
		$out .= "\t\t<?php if ( has_nav_menu( 'sc_footer' ) ) : ?>\n";
		$out .= "\t\t\t<nav class=\"sc-footer-nav\" aria-label=\"<?php esc_attr_e( 'Footer', '{$slug}' ); ?>\">\n";
		$out .= "\t\t\t\t<?php wp_nav_menu( array(\n";
		$out .= "\t\t\t\t\t'theme_location' => 'sc_footer',\n";
		$out .= "\t\t\t\t\t'container'      => false,\n";
		$out .= "\t\t\t\t\t'menu_class'     => 'sc-footer-menu',\n";
		$out .= "\t\t\t\t\t'depth'          => 2,\n";
		$out .= "\t\t\t\t\t'fallback_cb'    => false,\n";
		$out .= "\t\t\t\t) ); ?>\n";
		$out .= "\t\t\t</nav>\n";
		$out .= "\t\t<?php endif; ?>\n";
		if ( ! empty( $ft['widget_area'] ) ) {
			$out .= "\t\t<?php if ( is_active_sidebar( 'sc-footer-widgets' ) ) : ?>\n";
			$out .= "\t\t\t<div class=\"sc-footer-widgets\"><?php dynamic_sidebar( 'sc-footer-widgets' ); ?></div>\n";
			$out .= "\t\t<?php endif; ?>\n";
		}
		$out .= "\t</div>\n";

		// Social links (a starter set from the source — edit in this template).
		if ( $social ) {
			$out .= "\n\t<div class=\"sc-footer-social\">\n";
			foreach ( $social as $s ) {
				$href = preg_match( '#^(https?:|mailto:|tel:)#i', $s['url'] )
					? "'" . self::esc_php( $s['url'] ) . "'"
					: "home_url( '" . self::esc_php( $s['url'] === '' ? '/' : $s['url'] ) . "' )";
				$out .= "\t\t<a href=\"<?php echo esc_url( {$href} ); ?>\" rel=\"noopener\"><?php esc_html_e( '" . self::esc_php( $s['label'] ) . "', '{$slug}' ); ?></a>\n";
			}
			$out .= "\t</div>\n";
		}

		// Copyright — "© {year} {Site Title}." is always dynamic; the tail is editable.
		$tail = $ft['copyright'] !== '' ? ' <?php esc_html_e( \'' . self::esc_php( $ft['copyright'] ) . "', '{$slug}' ); ?>" : '';
		$out .= "\n\t<div class=\"sc-footer-copy\">\n";
		$out .= "\t\t&copy; <?php echo esc_html( gmdate( 'Y' ) ); ?> <?php echo esc_html( get_bloginfo( 'name' ) ); ?>.{$tail}\n";
		$out .= "\t</div>\n";
		$out .= "</div>\n";

		return $out;
	}

	/** Safe PHP function-name prefix from a theme slug. */
	private static function fn_prefix( $slug ) {
		$p = preg_replace( '/[^a-z0-9_]/', '_', strtolower( $slug ) );
		$p = preg_replace( '/_+/', '_', trim( $p, '_' ) );
		if ( $p === '' || is_numeric( $p[0] ) ) {
			$p = 'sc_' . $p;
		}
		return $p;
	}

	/* ---------------------------------------------------------------------- *
	 * Install / zip
	 * ---------------------------------------------------------------------- */

	/**
	 * Install the generated theme into wp-content/themes/<slug>.
	 *
	 * child       — writes the 4 generated files.
	 * standalone  — copies the parent tree, de-parents style.css, overlays the
	 *               generated chrome, and appends a generated include to functions.php.
	 *
	 * @param array $raw_config
	 * @return array { error, slug, dir, mode, files:int, exists:bool }
	 */
	public static function install( array $raw_config ) {
		$cfg  = self::normalize( $raw_config );
		$slug = $cfg['theme']['slug'];
		$mode = $cfg['theme']['mode'];

		$root = get_theme_root(); // usually wp-content/themes
		$dir  = trailingslashit( $root ) . $slug;

		$err = self::guard( $cfg, $root );
		if ( $err !== '' ) {
			return array( 'error' => $err, 'slug' => $slug, 'mode' => $mode );
		}

		$existed = is_dir( $dir );

		if ( $mode === 'standalone' ) {
			$copy_err = self::copy_parent_tree( $dir );
			if ( $copy_err !== '' ) {
				return array( 'error' => $copy_err, 'slug' => $slug, 'mode' => $mode );
			}
		} else {
			if ( ! wp_mkdir_p( $dir ) ) {
				return array( 'error' => sprintf( __( 'Could not create %s — check themes-folder permissions.', 'fw' ), $dir ), 'slug' => $slug, 'mode' => $mode );
			}
		}

		$written = self::write_files( $cfg, $dir );
		if ( is_string( $written ) ) {
			return array( 'error' => $written, 'slug' => $slug, 'mode' => $mode );
		}

		// Re-installing re-applies the conversion: clear the one-time seeding flags so the next page
		// load (wp_loaded) re-seeds the header layout, re-assigns the converted menus to their
		// locations, and re-sets the custom logo with the latest captured data — overriding any
		// leftover demo header/menu/logo without needing a manual theme re-activation.
		if ( function_exists( 'delete_option' ) ) {
			$fnp = self::fn_prefix( $slug );
			foreach ( array( '_logo_seeded', '_header_menu_assigned', '_footer_menu_assigned', '_footer_widgets_seeded' ) as $flag ) {
				delete_option( $fnp . $flag );
			}
		}

		return array(
			'error'  => '',
			'slug'   => $slug,
			'name'   => $cfg['theme']['name'],
			'dir'    => $dir,
			'mode'   => $mode,
			'files'  => (int) $written,
			'exists' => $existed,
		);
	}

	/**
	 * Build a downloadable .zip of the generated theme and return its temp path.
	 *
	 * @param array $raw_config
	 * @return array { error, path, filename, slug, mode }
	 */
	public static function build_zip( array $raw_config ) {
		$cfg  = self::normalize( $raw_config );
		$slug = $cfg['theme']['slug'];
		$mode = $cfg['theme']['mode'];

		if ( ! class_exists( 'ZipArchive' ) ) {
			return array( 'error' => __( 'PHP ZipArchive is not available on this server — use “Install into themes” instead.', 'fw' ), 'mode' => $mode );
		}

		$tmp = wp_tempnam( $slug . '.zip' );
		if ( ! $tmp ) {
			return array( 'error' => __( 'Could not create a temporary file for the zip.', 'fw' ), 'mode' => $mode );
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			return array( 'error' => __( 'Could not open the zip for writing.', 'fw' ), 'mode' => $mode );
		}

		$zip->addEmptyDir( $slug );

		if ( $mode === 'standalone' ) {
			$src = get_template_directory();
			if ( ! is_dir( $src ) ) {
				$zip->close();
				return array( 'error' => __( 'Parent theme not found — cannot build a standalone copy.', 'fw' ), 'mode' => $mode );
			}
			self::zip_add_dir( $zip, $src, $slug, self::generated_relpaths() );
		}

		// Overlay generated files (also the only files in child mode).
		$files = self::assemble_overlay( $cfg, $mode === 'standalone' );
		foreach ( $files as $rel => $contents ) {
			$zip->addFromString( $slug . '/' . $rel, $contents );
		}

		$zip->close();

		return array(
			'error'    => '',
			'path'     => $tmp,
			'filename' => $slug . '.zip',
			'slug'     => $slug,
			'name'     => $cfg['theme']['name'],
			'mode'     => $mode,
		);
	}

	/** Relpaths the generator owns (so the standalone parent-copy skips them and we overlay). */
	private static function generated_relpaths() {
		return array(
			'style.css',
			'template-parts/header-builder.php',
			'template-parts/footer-builder.php',
			'assets/js/interactivity.js',
		);
	}

	/**
	 * Assemble the file map actually written/zipped, accounting for mode.
	 *
	 * In standalone the generated functions.php becomes inc/site-converter-chrome.php
	 * (included from the copied parent functions.php) and style.css is the parent's
	 * style.css de-parented + generated chrome appended.
	 *
	 * @param array $cfg
	 * @param bool  $standalone
	 * @return array<string,string>
	 */
	private static function assemble_overlay( array $cfg, $standalone ) {
		$files = self::build_files( $cfg );

		if ( ! $standalone ) {
			return $files;
		}

		// Standalone: keep the parent's structural style.css but re-head it and
		// append our chrome; ship our functions as an include + an appended require.
		$parent_style = @file_get_contents( get_template_directory() . '/style.css' );
		$files['style.css'] = self::standalone_style_css( $cfg, is_string( $parent_style ) ? $parent_style : '' );

		// Move the generated functions to an include; the copied parent functions.php
		// gets a require appended (handled in write_files / copy path).
		$files['inc/site-converter-chrome.php'] = $files['functions.php'];
		unset( $files['functions.php'] );

		return $files;
	}

	/**
	 * Standalone style.css = parent style body, with the theme header rewritten
	 * (Template: removed, new Theme Name) and the generated chrome appended.
	 */
	private static function standalone_style_css( array $cfg, $parent_style ) {
		// Strip the parent's leading /* ... */ header block; keep its CSS body.
		$body = preg_replace( '/^\s*\/\*.*?\*\/\s*/s', '', $parent_style, 1 );
		return self::style_css( $cfg ) . "\n\n/* ---- Parent theme styles (de-parented) ---- */\n" . $body;
	}

	/* ---------------------------------------------------------------------- *
	 * Helpers
	 * ---------------------------------------------------------------------- */

	/** Validate slug + writability before touching the filesystem. */
	private static function guard( array $cfg, $root ) {
		$slug = $cfg['theme']['slug'];
		if ( ! preg_match( '/^[a-z0-9][a-z0-9-]{1,62}$/', $slug ) ) {
			return __( 'Invalid theme slug — use lowercase letters, numbers and hyphens.', 'fw' );
		}
		if ( $cfg['theme']['mode'] === 'standalone' && ! is_dir( get_template_directory() ) ) {
			return __( 'Parent theme (unysonplus-theme) not found — required to build a standalone copy.', 'fw' );
		}
		if ( ! wp_is_writable( $root ) ) {
			return sprintf( __( 'The themes folder (%s) is not writable — download the .zip and install it via Appearance → Themes instead.', 'fw' ), $root );
		}
		return '';
	}

	/**
	 * Write the assembled file map under $dir. For standalone, also append the
	 * chrome include to the copied functions.php.
	 *
	 * @return int|string files written, or an error string.
	 */
	private static function write_files( array $cfg, $dir ) {
		$standalone = $cfg['theme']['mode'] === 'standalone';
		$files      = self::assemble_overlay( $cfg, $standalone );
		$count      = 0;

		foreach ( $files as $rel => $contents ) {
			$path = trailingslashit( $dir ) . $rel;
			$sub  = dirname( $path );
			if ( ! is_dir( $sub ) && ! wp_mkdir_p( $sub ) ) {
				return sprintf( __( 'Could not create %s.', 'fw' ), $sub );
			}
			if ( false === file_put_contents( $path, $contents ) ) {
				return sprintf( __( 'Could not write %s.', 'fw' ), $rel );
			}
			$count++;
		}

		// Remove STALE generated files we no longer emit, so a previous install's leftover doesn't
		// shadow the parent. Critical for the dynamic header: once we stop emitting
		// template-parts/header-builder.php (child mode), the old mirror copy must be deleted or the
		// parent's editable Theme-Settings header can never take over. Child mode only (standalone
		// owns a full parent copy, so its "missing" overlays are intentional parent files).
		if ( ! $standalone ) {
			foreach ( self::generated_relpaths() as $rel ) {
				if ( ! isset( $files[ $rel ] ) ) {
					$stale = trailingslashit( $dir ) . $rel;
					if ( is_file( $stale ) ) { @unlink( $stale ); }
				}
			}
		}

		if ( $standalone ) {
			$fnphp = trailingslashit( $dir ) . 'functions.php';
			$append = "\n\n/* Site Converter — generated chrome (fonts, footer widgets, header CTA). */\n"
				. "require get_theme_file_path( 'inc/site-converter-chrome.php' );\n";
			if ( is_file( $fnphp ) && false === strpos( (string) @file_get_contents( $fnphp ), 'inc/site-converter-chrome.php' ) ) {
				file_put_contents( $fnphp, $append, FILE_APPEND );
			} elseif ( ! is_file( $fnphp ) ) {
				file_put_contents( $fnphp, "<?php\nif ( ! defined( 'ABSPATH' ) ) { exit; }" . $append );
			}
		}

		return $count;
	}

	/**
	 * Recursively copy the parent theme into $dst, skipping VCS/build noise and the
	 * relpaths the generator will overlay.
	 *
	 * @return string '' on success, else an error message.
	 */
	private static function copy_parent_tree( $dst ) {
		$src  = get_template_directory();
		$skip = array_flip( self::generated_relpaths() );

		if ( ! wp_mkdir_p( $dst ) ) {
			return sprintf( __( 'Could not create %s.', 'fw' ), $dst );
		}

		$it = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $src, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ( $it as $item ) {
			$rel = ltrim( str_replace( $src, '', $item->getPathname() ), '/\\' );
			$rel = str_replace( '\\', '/', $rel );

			// Skip VCS / dependency / build noise and our overlaid files.
			if ( preg_match( '#(^|/)(\.git|node_modules|\.github|\.idea|\.vscode)(/|$)#', $rel ) ) {
				continue;
			}
			if ( isset( $skip[ $rel ] ) ) {
				continue;
			}

			$target = trailingslashit( $dst ) . $rel;
			if ( $item->isDir() ) {
				if ( ! is_dir( $target ) ) {
					wp_mkdir_p( $target );
				}
			} else {
				$sub = dirname( $target );
				if ( ! is_dir( $sub ) ) {
					wp_mkdir_p( $sub );
				}
				@copy( $item->getPathname(), $target );
			}
		}

		return '';
	}

	/**
	 * Add a directory tree to a zip under $base, skipping VCS noise and $skip relpaths.
	 *
	 * @param ZipArchive $zip
	 * @param string     $src
	 * @param string     $base  In-zip prefix (the theme slug).
	 * @param array      $skip  Relpaths to omit (overlaid later).
	 */
	private static function zip_add_dir( ZipArchive $zip, $src, $base, array $skip ) {
		$skip = array_flip( $skip );
		$it   = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $src, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ( $it as $item ) {
			$rel = ltrim( str_replace( $src, '', $item->getPathname() ), '/\\' );
			$rel = str_replace( '\\', '/', $rel );

			if ( preg_match( '#(^|/)(\.git|node_modules|\.github|\.idea|\.vscode)(/|$)#', $rel ) ) {
				continue;
			}
			if ( isset( $skip[ $rel ] ) ) {
				continue;
			}

			if ( $item->isDir() ) {
				$zip->addEmptyDir( $base . '/' . $rel );
			} else {
				$zip->addFile( $item->getPathname(), $base . '/' . $rel );
			}
		}
	}
}
