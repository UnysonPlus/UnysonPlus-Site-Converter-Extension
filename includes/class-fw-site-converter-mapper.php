<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Content Mapper — the human-in-the-loop layer between capture and page build.
 *
 * The capture ships `mapping.json`: every section broken into candidate elements (blocks).
 * This class (1) SUGGESTS a role for each element from heuristics + the user's learned rules,
 * (2) BUILDS the page-builder tree from the (user-corrected) roles, and (3) LEARNS — saving
 * each correction as a rule keyed by the element's signature so future suggestions improve.
 *
 * Roles:
 *   overline | title | subtitle  → merge into ONE special_heading
 *   heading                       → its own special_heading
 *   text                          → text_block
 *   button                        → button
 *   image | code                  → code_block (verbatim)
 *   columns                       → a row of builder columns (one code_block per grid cell)
 *   skip                          → omitted
 */
class FW_Site_Converter_Mapper {

	const RULES_OPTION = 'fw_site_converter_map_rules';

	/* ---------------------------------------------------------------------- *
	 * CSS Class Mapper for BOX columns — when a column's content is a card/box
	 * (border / shadow / background / rounded), compile its source Tailwind
	 * classes into ONE clean semantic class (`.box-1`) carrying the concatenated
	 * CSS, applied via the column's Inner Wrapper Class. De-dup by declaration
	 * set, so all identical cards share one `.box` class.
	 * ---------------------------------------------------------------------- */
	private static $style_cfg   = array();
	private static $style_on    = false;
	private static $style_key   = array(); // declset hash → class name
	private static $style_css   = array(); // class name → CSS rule
	private static $style_count = array(); // base name → count
	private static $sec_presets = array(); // [{ slug, rgb:[r,g,b] }] — Section Style presets for band-fill linking

	/**
	 * Conversion debug map — per-node record of what the deterministic converter DID and DROPPED.
	 * Keyed by the SAME 8-char element hash the renderer stamps as the `u<hash>` scope class (see
	 * sc_element_scope_class), so the dashboard hover-inspector can look a rendered element up by
	 * that class. Populated best-effort where the mapper already discards source utility classes
	 * (headings, text blocks, icon boxes), then merged into the post-pass in build_conversion_map().
	 * Reset at the start of every build_pages() so re-conversions never accumulate.
	 *
	 * @var array<string,array{src_cls?:string,dropped?:string[]}>
	 */
	public static $conv_debug = array();

	/** Library (download-on-demand) shortcodes the conversion emitted and that the target must install
	 *  (e.g. 'instagram'). Slug => true. Written to theme-design.json + required-shortcodes.json by Stitch. */
	public static $required_shortcodes = array();

	/** The 8-char element hash for a unique_id — identical to sc_element_scope_class()'s slug (sans the `u`). */
	private static function conv_hash( $uid ) {
		$uid = (string) $uid;
		if ( $uid === '' ) { return ''; }
		if ( function_exists( 'sanitize_key' ) ) {
			$slug = substr( sanitize_key( strtolower( preg_replace( '/\s+/', '-', trim( $uid ) ) ) ), 0, 8 );
		} else {
			$slug = substr( strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', $uid ) ), 0, 8 );
		}
		return $slug;
	}

	/**
	 * Record what source classes a node carried (src_cls) and which the mapper did NOT turn into an
	 * option (dropped). Best-effort — merges into any existing record for the same hash. $dropped may
	 * be an array or a space string; empties are skipped.
	 */
	private static function conv_debug_record( $uid, $src_cls, $dropped ) {
		$hash = self::conv_hash( $uid );
		if ( $hash === '' ) { return; }
		$src_cls = trim( (string) $src_cls );
		if ( ! is_array( $dropped ) ) { $dropped = preg_split( '/\s+/', (string) $dropped, -1, PREG_SPLIT_NO_EMPTY ); }
		$dropped = array_values( array_unique( array_filter( array_map( 'strval', $dropped ) ) ) );
		if ( $src_cls === '' && ! $dropped ) { return; }
		if ( ! isset( self::$conv_debug[ $hash ] ) ) { self::$conv_debug[ $hash ] = array(); }
		if ( $src_cls !== '' ) {
			$prev = isset( self::$conv_debug[ $hash ]['src_cls'] ) ? self::$conv_debug[ $hash ]['src_cls'] : '';
			// Concatenate distinct source-class strings (a heading has overline/title/subtitle parts).
			$merged = trim( $prev . ' ' . $src_cls );
			$toks   = array_values( array_unique( preg_split( '/\s+/', $merged, -1, PREG_SPLIT_NO_EMPTY ) ) );
			self::$conv_debug[ $hash ]['src_cls'] = implode( ' ', $toks );
		}
		if ( $dropped ) {
			$prev = isset( self::$conv_debug[ $hash ]['dropped'] ) ? self::$conv_debug[ $hash ]['dropped'] : array();
			self::$conv_debug[ $hash ]['dropped'] = array_values( array_unique( array_merge( $prev, $dropped ) ) );
		}
	}

	/** Tokens present in $original but absent from $kept (both space-separated class strings). */
	private static function conv_dropped_diff( $original, $kept ) {
		$o = preg_split( '/\s+/', (string) $original, -1, PREG_SPLIT_NO_EMPTY );
		$k = preg_split( '/\s+/', (string) $kept, -1, PREG_SPLIT_NO_EMPTY );
		return array_values( array_diff( $o, $k ) );
	}

	/** Enable the box CSS Class Mapper for this build + reset its registry. Pass the parsed tailwind config. */
	public static function set_style_config( $cfg ) {
		self::$style_cfg   = is_array( $cfg ) ? $cfg : array();
		self::$style_on    = class_exists( 'FW_Site_Converter_Tailwind' );
		self::$style_key   = array();
		self::$style_css   = array();
		self::$style_count = array();
	}

	/**
	 * The Section Style presets built for THIS conversion (Stitch::build_section_style_presets),
	 * so n_section() can LINK a detected band fill to an existing preset (set the section's
	 * `variant` = the preset slug) instead of only hardcoding the colour. Each entry is normalised
	 * to { slug, rgb:[r,g,b] } — the slug is derived from the preset's `style_name` the SAME way the
	 * theme's unysonplus_section_style_preset_slug_map() does (lowercase, non-alnum → '-', dedupe
	 * with '-2'), so a linked `variant` matches the `.section--{slug}` rule the final theme emits.
	 *
	 * @param array $presets the `section_style_presets` list (each with style_name + background).
	 */
	public static function set_section_presets( $presets ) {
		self::$sec_presets = array();
		if ( ! is_array( $presets ) ) { return; }
		$seen = array();
		foreach ( $presets as $sp ) {
			if ( ! is_array( $sp ) ) { continue; }
			$name = isset( $sp['style_name'] ) ? (string) $sp['style_name'] : '';
			$slug = trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( $name ) ), '-' );
			$bg   = '';
			if ( isset( $sp['background']['color']['value'] ) && is_array( $sp['background']['color']['value'] ) ) {
				$v  = $sp['background']['color']['value'];
				$bg = ( isset( $v['custom'] ) && $v['custom'] !== '' ) ? (string) $v['custom'] : ( isset( $v['predefined'] ) ? (string) $v['predefined'] : '' );
			}
			$rgb = self::rgb_triplet( $bg );
			if ( $rgb === null ) { continue; } // a preset with no solid opaque fill can't be colour-matched
			if ( $slug === '' ) { $slug = isset( $sp['id'] ) ? strtolower( (string) $sp['id'] ) : ''; }
			if ( $slug === '' ) { continue; }
			$base = $slug; $n = 1;
			while ( isset( $seen[ $slug ] ) ) { $n++; $slug = $base . '-' . $n; }
			$seen[ $slug ] = true;
			self::$sec_presets[] = array( 'slug' => $slug, 'rgb' => $rgb );
		}
	}

	/** Parse an opaque colour ("rgb(..)", "rgba(..,1)", "#hex") → [r,g,b], or null (non-opaque / non-colour). */
	private static function rgb_triplet( $c ) {
		$c = strtolower( trim( (string) $c ) );
		if ( $c === '' || $c === 'transparent' ) { return null; }
		if ( stripos( $c, 'gradient' ) !== false ) { return null; }
		if ( preg_match( '/rgba?\(\s*(\d{1,3})[,\s]+(\d{1,3})[,\s]+(\d{1,3})(?:[,\s\/]+([0-9.]+))?/', $c, $m ) ) {
			if ( isset( $m[4] ) && $m[4] !== '' && (float) $m[4] < 0.85 ) { return null; }
			return array( (int) $m[1], (int) $m[2], (int) $m[3] );
		}
		if ( preg_match( '/^#([0-9a-f]{3}|[0-9a-f]{6})$/', $c, $m ) ) {
			$h = $m[1];
			if ( strlen( $h ) === 3 ) { $h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2]; }
			return array( hexdec( substr( $h, 0, 2 ) ), hexdec( substr( $h, 2, 2 ) ), hexdec( substr( $h, 4, 2 ) ) );
		}
		return null;
	}

	/**
	 * A computed colour → a clean `rgb(r, g, b)` / `rgba(r, g, b, a)` string when it is a REAL fill,
	 * or '' when transparent / near-transparent. Unlike rgb_triplet() this KEEPS a low-alpha tint
	 * (a `bg-primary/10` pill is rgba(…, 0.1) — a valid, visible fill), rejecting only alpha <= 0.02.
	 */
	private static function pill_fill_color( $c ) {
		$c = strtolower( trim( (string) $c ) );
		if ( $c === '' || $c === 'transparent' || $c === 'none' ) { return ''; }
		if ( preg_match( '/rgba?\(\s*(\d{1,3})[,\s]+(\d{1,3})[,\s]+(\d{1,3})(?:[,\s\/]+([0-9.]+))?/', $c, $m ) ) {
			$a = ( isset( $m[4] ) && $m[4] !== '' ) ? (float) $m[4] : 1.0;
			if ( $a <= 0.02 ) { return ''; }
			return $a < 1 ? "rgba({$m[1]}, {$m[2]}, {$m[3]}, {$a})" : "rgb({$m[1]}, {$m[2]}, {$m[3]})";
		}
		if ( preg_match( '/^#[0-9a-f]{3,8}$/', $c ) ) { return $c; }
		return '';
	}

	/** A detected band-fill [r,g,b] → the slug of the nearest Section Style preset within tolerance, or ''. */
	private static function match_section_preset( $rgb ) {
		if ( ! is_array( $rgb ) || empty( self::$sec_presets ) ) { return ''; }
		$best = ''; $bestd = PHP_INT_MAX;
		foreach ( self::$sec_presets as $p ) {
			$d = abs( $p['rgb'][0] - $rgb[0] ) + abs( $p['rgb'][1] - $rgb[1] ) + abs( $p['rgb'][2] - $rgb[2] );
			if ( $d < $bestd ) { $bestd = $d; $best = $p['slug']; }
		}
		// Tolerance: total per-channel drift <= 18 (each channel ~<=6). Tight so distinct bands don't collapse.
		return ( $bestd <= 18 ) ? $best : '';
	}

	/* ---------------------------------------------------------------------- *
	 * BUTTON preset linking — the SAME button_colors / button_sizes presets
	 * that theme-settings.json carries (built by Stitch::build_button_presets),
	 * so a converted BODY button can attach the matching color-preset slug
	 * (`style` = btn-{slug}) + size-preset slug (`size` = btn-{slug}) — exactly
	 * what the header CTA already does — instead of falling to the shortcode's
	 * bare default. The per-node exact custom_css (fill/padding) stays as the
	 * safety net; the preset is ADDITIVE (picks up hover + theme consistency).
	 * ---------------------------------------------------------------------- */
	private static $btn_colors = array(); // [{ slug, role, bg:[r,g,b]|null, fg:[r,g,b]|null, bd:[r,g,b]|null, outline:bool }]
	private static $btn_sizes  = array(); // [{ slug, fs:float|null, py:float|null, px:float|null, radius:float|null }]

	/**
	 * Give the mapper the built Button Colour + Size presets for THIS conversion, so
	 * button_preset_for() can attach the matching slug. Colour slug is derived from
	 * `color_name` the SAME way unysonplus_button_preset_slug_map() does (lowercase,
	 * non-alnum → '-', dedupe with '-2'); size slug is the preset's own `slug`.
	 *
	 * @param array $colors the `button_colors` list (each with color_name + states.default).
	 * @param array $sizes  the `button_sizes` list (each with slug + font_size/padding/radius).
	 */
	public static function set_button_presets( $colors, $sizes ) {
		self::$btn_colors = array();
		self::$btn_sizes  = array();
		$seen = array();
		foreach ( (array) $colors as $c ) {
			if ( ! is_array( $c ) ) { continue; }
			$name = isset( $c['color_name'] ) ? (string) $c['color_name'] : '';
			$slug = trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( $name ) ), '-' );
			if ( $slug === '' ) { $slug = isset( $c['id'] ) ? preg_replace( '/[^a-zA-Z0-9_-]/', '', (string) $c['id'] ) : ''; }
			if ( $slug === '' ) { continue; }
			$base = $slug; $n = 1;
			while ( isset( $seen[ $slug ] ) ) { $n++; $slug = $base . '-' . $n; }
			$seen[ $slug ] = true;
			$def  = ( isset( $c['states']['default'] ) && is_array( $c['states']['default'] ) ) ? $c['states']['default'] : array();
			$pick = function ( $field ) use ( $def ) {
				if ( ! isset( $def[ $field ] ) || ! is_array( $def[ $field ] ) ) { return ''; }
				$v = $def[ $field ];
				return ( isset( $v['custom'] ) && $v['custom'] !== '' ) ? (string) $v['custom'] : ( isset( $v['predefined'] ) && $v['predefined'] !== '' ? (string) $v['predefined'] : '' );
			};
			$bg      = self::rgb_triplet( $pick( 'bg_color' ) );
			$fg      = self::rgb_triplet( $pick( 'text_color' ) );
			$bd      = self::rgb_triplet( $pick( 'border_color' ) );
			$bstyle  = isset( $def['border_style'] ) ? (string) $def['border_style'] : '';
			$outline = ( $bg === null && ( $bstyle === 'solid' || $bd !== null ) );
			self::$btn_colors[] = array( 'slug' => $slug, 'role' => strtolower( $name ), 'bg' => $bg, 'fg' => $fg, 'bd' => $bd, 'outline' => $outline );
		}
		foreach ( (array) $sizes as $s ) {
			if ( ! is_array( $s ) || empty( $s['slug'] ) ) { continue; }
			$num = function ( $f ) use ( $s ) { return ( isset( $s[ $f ]['value'] ) && $s[ $f ]['value'] !== '' ) ? (float) $s[ $f ]['value'] : null; };
			self::$btn_sizes[] = array(
				'slug'   => preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $s['slug'] ) ),
				'fs'     => $num( 'font_size' ),
				'py'     => $num( 'padding_y' ),
				'px'     => $num( 'padding_x' ),
				'radius' => $num( 'border_radius' ),
			);
		}
	}

	/* ---------------------------------------------------------------------- *
	 * TEXT STYLE (font_sizes) preset linking — the SAME Text Style presets
	 * theme-settings.json carries (Stitch::build_text_styles), so a converted
	 * BODY text block can reference an editable size preset (`font_size_preset`
	 * = the preset's CLASS: `lead` / `font-subtitle` / `font-small` / …) instead
	 * of freezing its own px in Custom CSS. Only BODY presets participate —
	 * Display N (headings) + the style-only Eyebrow are excluded, so a 20px lead
	 * paragraph maps to `lead`, never to a 20px `display-*`.
	 * ---------------------------------------------------------------------- */
	private static $text_presets = array(); // [{ class, size:float }] — body size presets only

	/**
	 * Give the mapper the built Text Style presets for THIS conversion. Keeps only the BODY size roles
	 * (a real px size + a non-`display-*`, non-empty class), so text_preset_for() matches a paragraph's
	 * computed size to Lead/Subtitle/Small/Caption — never to a Display or the style-only Eyebrow.
	 *
	 * @param array $presets the `font_sizes` list (each with name/size/class).
	 */
	public static function set_text_presets( $presets ) {
		self::$text_presets = array();
		foreach ( (array) $presets as $e ) {
			if ( ! is_array( $e ) ) { continue; }
			$class = isset( $e['class'] ) ? trim( (string) $e['class'] ) : '';
			if ( $class === '' || strpos( $class, 'display-' ) === 0 ) { continue; } // body roles only
			$size = ( isset( $e['size'] ) && is_numeric( $e['size'] ) ) ? (float) $e['size'] : 0;
			if ( $size <= 0 ) { continue; }
			self::$text_presets[] = array( 'class' => $class, 'size' => $size );
		}
	}

	/** Match a text block's computed font-size (px) → the nearest BODY size-preset CLASS within ±1.5px, or ''. */
	private static function text_preset_for( $px ) {
		if ( $px === null || empty( self::$text_presets ) ) { return ''; }
		$px = (float) $px;
		$best = ''; $bestd = PHP_INT_MAX;
		foreach ( self::$text_presets as $p ) {
			$d = abs( $p['size'] - $px );
			if ( $d < $bestd ) { $bestd = $d; $best = $p['class']; }
		}
		return ( $bestd <= 1.5 ) ? $best : '';
	}

	/** Is a computed `color` value the plain DEFAULT text ink (pure black / empty / inherit / currentColor)?
	 *  Such a colour carries no distinctive tone, so a text block keeps INHERITING the theme body colour
	 *  instead of pinning it as an editable custom option. A muted tone (rgb(41,61,54)) or any alpha'd rgba
	 *  is NOT default ink → it becomes a real text_color. */
	private static function is_default_ink( $v ) {
		$v = strtolower( trim( (string) $v ) );
		$v = preg_replace( '/\s+/', '', $v ); // rgb(0, 0, 0) → rgb(0,0,0)
		if ( '' === $v ) { return true; }
		if ( in_array( $v, array( 'inherit', 'initial', 'currentcolor', 'transparent', 'unset', 'black', '#000', '#000000', '#000000ff', 'rgb(0,0,0)', 'rgba(0,0,0,1)', 'rgba(0,0,0,0)' ), true ) ) { return true; }
		return false;
	}

	/** The color-preset slug whose role matches $role ('primary'/'secondary'/'outline'/'fill'), or ''. */
	private static function btn_color_slug_by_role( $role ) {
		foreach ( self::$btn_colors as $p ) {
			if ( $p['role'] === $role ) { return $p['slug']; }
		}
		return '';
	}

	/** Match a button's computed bg/fg/border → the nearest color-preset slug within tolerance, or ''. The
	 *  fill is the primary signal; for a border/no-fill button the border+text carry the match. */
	private static function match_button_color( $bg, $fg, $bd ) {
		if ( empty( self::$btn_colors ) ) { return ''; }
		$dist = function ( $a, $b ) { return ( $a === null || $b === null ) ? null : abs( $a[0] - $b[0] ) + abs( $a[1] - $b[1] ) + abs( $a[2] - $b[2] ); };
		$best = ''; $bestd = PHP_INT_MAX;
		foreach ( self::$btn_colors as $p ) {
			if ( $bg !== null && $p['bg'] !== null ) {
				$d = $dist( $bg, $p['bg'] );
				// bg match: tighten with text when both known.
				$dt = $dist( $fg, $p['fg'] );
				if ( $dt !== null ) { $d += (int) round( $dt / 3 ); }
			} elseif ( $bg === null && $p['bg'] === null ) {
				// both borderless/outline — match on the BORDER colour (a real outline button). A button
				// with no border is a plain text link, not an outline preset — never match on text alone.
				if ( $bd === null ) { continue; }
				$d = $dist( $bd, $p['bd'] );
				if ( $d === null ) { continue; }
			} else {
				continue; // one filled, one not — not comparable
			}
			if ( $d < $bestd ) { $bestd = $d; $best = $p['slug']; }
		}
		return ( $bestd <= 40 ) ? $best : '';
	}

	/** Match a button's computed font-size + padding (+ radius) → the nearest size-preset slug, or ''. */
	private static function match_button_size( $fs, $py, $px ) {
		if ( empty( self::$btn_sizes ) || $fs === null ) { return ''; }
		foreach ( self::$btn_sizes as $s ) {
			if ( $s['fs'] === null ) { continue; }
			if ( abs( $s['fs'] - $fs ) > 1.0 ) { continue; }
			if ( $py !== null && $s['py'] !== null && abs( $s['py'] - $py ) > 3.0 ) { continue; }
			if ( $px !== null && $s['px'] !== null && abs( $s['px'] - $px ) > 4.0 ) { continue; }
			return $s['slug'];
		}
		return '';
	}

	/** Parse a computed "Npx" value → float px (or null). */
	private static function px_num( $v ) {
		$v = trim( (string) $v );
		return preg_match( '/^(-?[0-9.]+)\s*px?$/i', $v, $m ) || preg_match( '/^(-?[0-9.]+)$/', $v, $m ) ? (float) $m[1] : null;
	}

	/**
	 * SHARED button-preset resolver (header CTA + body buttons stay consistent): given a button's
	 * source classes + its computed data-sc-cs, return the matching color-preset + size-preset
	 * slugs as `style` / `size` att values (btn-{slug}), or '' when nothing is confidently detected.
	 * Strategy: (1) the SEMANTIC fill class (bg-primary/btn-primary → the Primary preset, bg-secondary
	 * → Secondary, bg-white/bg-surface + border → Outline) — mirroring build_button_presets()'
	 * role assignment; (2) falling back to matching the computed bg/text/border colours to a built
	 * preset within tolerance. Size: an explicit btn-lg/btn-md/btn-sm class, else the computed
	 * font-size + padding matched to a size preset.
	 *
	 * @param string $cls source classes
	 * @param string $cs  the button's data-sc-cs (computed style), if any
	 * @return array{ style:string, size:string }
	 */
	public static function button_preset_for( $cls, $cs = '' ) {
		$out = array( 'style' => '', 'size' => '' );
		if ( empty( self::$btn_colors ) && empty( self::$btn_sizes ) ) { return $out; }
		$lc    = ' ' . strtolower( (string) $cls ) . ' ';
		$props = ( '' !== (string) $cs ) ? self::cs_decls( $cs, array( 'background-color', 'color', 'border', 'font-size', 'padding', 'padding-top', 'padding-left' ) ) : array();

		/* ---- COLOR ---- */
		$style = '';
		// (1a) an already-slugged btn-{slug} / btn-outline-{slug} class naming a real preset.
		if ( preg_match( '/\sbtn-outline-([a-z0-9-]+)\s/', $lc, $m ) ) {
			foreach ( self::$btn_colors as $p ) { if ( $p['slug'] === $m[1] ) { $style = 'btn-outline-' . $p['slug']; break; } }
		}
		if ( $style === '' && preg_match_all( '/\sbtn-([a-z0-9-]+)\s/', $lc, $mm ) ) {
			foreach ( $mm[1] as $cand ) {
				if ( in_array( $cand, array( 'lg', 'md', 'sm', 'xl', 'xs' ), true ) ) { continue; } // size token
				foreach ( self::$btn_colors as $p ) { if ( $p['slug'] === $cand ) { $style = 'btn-' . $p['slug']; break 2; } }
			}
		}
		// (1b) SEMANTIC role from the fill class → the preset carrying that role.
		if ( $style === '' ) {
			$role = '';
			if ( preg_match( '/\s(?:btn-primary|bg-primary|bg-brand)\b/', $lc ) )        { $role = 'primary'; }
			elseif ( preg_match( '/\s(?:btn-secondary|bg-secondary|bg-accent|bg-cta)\b/', $lc ) ) { $role = 'secondary'; }
			elseif ( ( preg_match( '/\sbg-white\b/', $lc ) || preg_match( '/\sbg-surface\b/', $lc ) ) && preg_match( '/\sborder\b/', $lc ) ) { $role = 'outline'; }
			if ( $role !== '' ) { $slug = self::btn_color_slug_by_role( $role ); if ( $slug !== '' ) { $style = 'btn-' . $slug; } }
		}
		// (2) fall back to matching the computed colours.
		if ( $style === '' ) {
			$bg = self::rgb_triplet( isset( $props['background-color'] ) ? $props['background-color'] : '' );
			$fg = self::rgb_triplet( isset( $props['color'] ) ? $props['color'] : '' );
			// A border only counts when there's a real border WIDTH — cs_decls synthesizes the `border`
			// shorthand only from a non-zero border-top-width, so a plain link's default border-*-color
			// (no width) never registers as an outline.
			$bd = '';
			if ( isset( $props['border'] ) && preg_match( '/(rgba?\([^)]*\)|#[0-9a-fA-F]{3,8})/', $props['border'], $bm ) ) { $bd = $bm[1]; }
			$bd = self::rgb_triplet( $bd );
			// Only match a REAL styled button (a fill or a border) — never a plain text link.
			if ( $bg !== null || $bd !== null ) {
				$slug = self::match_button_color( $bg, $fg, $bd );
				if ( $slug !== '' ) { $style = 'btn-' . $slug; }
			}
		}
		$out['style'] = $style;

		/* ---- SIZE ---- */
		$size = '';
		if ( preg_match( '/\sbtn-(lg|md|sm|xl|xs)\b/', $lc, $m ) ) {
			foreach ( self::$btn_sizes as $s ) { if ( $s['slug'] === $m[1] ) { $size = $s['slug']; break; } }
		}
		if ( $size === '' ) {
			$fs = self::px_num( isset( $props['font-size'] ) ? $props['font-size'] : '' );
			$py = self::px_num( isset( $props['padding-top'] ) ? $props['padding-top'] : '' );
			$px = self::px_num( isset( $props['padding-left'] ) ? $props['padding-left'] : '' );
			$size = self::match_button_size( $fs, $py, $px );
		}
		$out['size'] = $size !== '' ? 'btn-' . $size : '';
		return $out;
	}

	/** Normalise a colour to a compact `rgb(r, g, b)` (or pass a hex through) for a native background field. */
	private static function norm_bg_color( $c ) {
		$rgb = self::rgb_triplet( $c );
		if ( $rgb === null ) { return trim( (string) $c ); }
		return 'rgb(' . $rgb[0] . ', ' . $rgb[1] . ', ' . $rgb[2] . ')';
	}

	/**
	 * Parse a CSS `linear-gradient(...)` → the section background.gradient `data` shape
	 * ({ type:'linear', angle, stops:[{color,position}] }), or null when it isn't a parseable
	 * linear gradient with >= 2 colour stops. Best-effort: radial / conic / var()-driven gradients
	 * are left to the style.css path.
	 */
	private static function parse_linear_gradient( $css ) {
		$css = trim( (string) $css );
		if ( ! preg_match( '/linear-gradient\(\s*(.+)\)\s*$/is', $css, $m ) ) { return null; }
		$inner = $m[1];
		// Split top-level by commas (don't split inside rgb()/rgba() parens).
		$parts = array(); $buf = ''; $depth = 0;
		for ( $i = 0, $len = strlen( $inner ); $i < $len; $i++ ) {
			$ch = $inner[ $i ];
			if ( $ch === '(' ) { $depth++; }
			elseif ( $ch === ')' ) { $depth--; }
			if ( $ch === ',' && $depth === 0 ) { $parts[] = trim( $buf ); $buf = ''; continue; }
			$buf .= $ch;
		}
		if ( $buf !== '' ) { $parts[] = trim( $buf ); }
		$angle = 180; // CSS default for a DIRECTIONLESS linear-gradient is "to bottom" (180deg), not 90 (to right).
		if ( isset( $parts[0] ) && preg_match( '/^(-?[0-9.]+)deg$/', $parts[0], $am ) ) {
			$angle = (float) $am[1]; array_shift( $parts );
		} elseif ( isset( $parts[0] ) && stripos( $parts[0], 'to ' ) === 0 ) {
			$dir = strtolower( trim( substr( $parts[0], 3 ) ) );
			$map = array( 'top' => 0, 'right' => 90, 'bottom' => 180, 'left' => 270, 'top right' => 45, 'bottom right' => 135, 'bottom left' => 225, 'top left' => 315 );
			$angle = isset( $map[ $dir ] ) ? $map[ $dir ] : 90; array_shift( $parts );
		}
		$stops = array(); $count = count( $parts );
		foreach ( $parts as $idx => $part ) {
			if ( ! preg_match( '/^(#[0-9a-fA-F]{3,8}|rgba?\([^)]*\)|hsla?\([^)]*\))\s*([0-9.]+)?%?/', $part, $pm ) ) { continue; }
			$color = $pm[1];
			$pos   = ( isset( $pm[2] ) && $pm[2] !== '' ) ? (float) $pm[2] : ( $count > 1 ? round( $idx * 100 / ( $count - 1 ) ) : 0 );
			$stops[] = array( 'color' => $color, 'position' => $pos );
		}
		return ( count( $stops ) >= 2 ) ? array( 'type' => 'linear', 'angle' => $angle, 'stops' => $stops ) : null;
	}

	/** Does this class string read as a BOX container? (border / shadow / background / rounded.) */
	private static function is_box_class( $cls ) {
		$cls = ' ' . strtolower( (string) $cls ) . ' ';
		if ( ! preg_match( '/\s(border|shadow[\w-]*|rounded[\w\[\]-]*|bg-)/', $cls ) ) { return false; }
		// A bare "border-0 / border-none / shadow-none" alone doesn't make a box.
		if ( preg_match( '/\s(border|shadow|rounded)/', $cls ) && ! preg_match( '/\s(bg-|border(?!-0|-none)|shadow(?!-none)|rounded(?!-none))/', $cls ) ) { return false; }
		return true;
	}

	/** Visual computed properties applied to a converted BUTTON / BOX (from data-sc-cs). */
	private static $cs_btn = array( 'background-color', 'color', 'border', 'border-radius', 'padding', 'font-weight', 'font-size', 'font-family', 'text-transform', 'letter-spacing' );
	private static $cs_box = array( 'background-color', 'border', 'border-radius', 'box-shadow', 'padding' );

	/** Does an element's RESOLVED computed style (data-sc-cs) read as a BOX? (fill / border / shadow / rounded.) */
	private static function cs_is_box( $cs ) {
		if ( '' === (string) $cs ) { return false; }
		$d = self::cs_decls( $cs, array( 'background-color', 'border', 'box-shadow', 'border-radius' ) );
		return ! empty( $d );
	}

	/** Parse a `data-sc-cs` value (prop:val;…) → assoc, synthesizing a `border` shorthand, filtered to $allow. */
	private static function cs_decls( $cs, $allow ) {
		$raw = array();
		foreach ( explode( ';', (string) $cs ) as $d ) {
			$d = trim( $d );
			if ( '' === $d ) { continue; }
			$cp = strpos( $d, ':' );
			if ( false === $cp ) { continue; }
			$raw[ trim( substr( $d, 0, $cp ) ) ] = trim( substr( $d, $cp + 1 ) );
		}
		// Computed styles expose per-edge longhands; synthesize one `border` shorthand from the top edge.
		if ( isset( $raw['border-top-width'] ) && '0px' !== $raw['border-top-width'] ) {
			$bs = ( isset( $raw['border-top-style'] ) && 'none' !== $raw['border-top-style'] ) ? $raw['border-top-style'] : 'solid';
			$raw['border'] = $raw['border-top-width'] . ' ' . $bs . ' ' . ( isset( $raw['border-top-color'] ) ? $raw['border-top-color'] : 'currentColor' );
		}
		// Expand the `margin` / `padding` shorthand (data-sc-cs stamps the shorthand, not per-edge) into
		// longhands so a profile asking for margin-top / margin-bottom (etc.) can match.
		foreach ( array( 'margin', 'padding' ) as $bx ) {
			if ( ! isset( $raw[ $bx ] ) || '' === $raw[ $bx ] || false !== strpos( $raw[ $bx ], 'var(' ) ) { continue; }
			$pp = preg_split( '/\s+/', trim( $raw[ $bx ] ) );
			$n  = count( $pp );
			if ( $n < 1 || $n > 4 ) { continue; }
			$edges = array( '-top' => $pp[0], '-right' => ( $n >= 2 ? $pp[1] : $pp[0] ), '-bottom' => ( $n >= 3 ? $pp[2] : $pp[0] ), '-left' => ( $n >= 4 ? $pp[3] : ( $n >= 2 ? $pp[1] : $pp[0] ) ) );
			foreach ( $edges as $sfx => $vv ) { if ( ! isset( $raw[ $bx . $sfx ] ) ) { $raw[ $bx . $sfx ] = $vv; } }
		}
		$out = array();
		foreach ( $allow as $p ) {
			if ( isset( $raw[ $p ] ) && '' !== $raw[ $p ] ) { $out[ $p ] = $raw[ $p ]; }
		}
		// G1 — LOCKUP FLEX GUARD: `align-items` / `justify-content` / `gap` / `flex-direction` are no-ops
		// unless the box is a flex container. When a caller pulls any of them into a scoped rule but no
		// `display` came along (the common icon+text "lockup" case), synthesize `display:flex` so the
		// captured alignment/gap actually apply. Never added when none of those props are present, and a
		// caller that already set its own `display` (e.g. btn_row_css) wins via array_merge order.
		if ( ! isset( $out['display'] ) ) {
			foreach ( array( 'align-items', 'justify-content', 'gap', 'column-gap', 'flex-direction', 'flex-wrap' ) as $fp ) {
				if ( isset( $out[ $fp ] ) ) { $out = array( 'display' => 'flex' ) + $out; break; }
			}
		}
		return $out;
	}

	/* ---------------------------------------------------------------------- *
	 * HI-FI FAITHFUL BASE (Pass-2) + SPACING → NATIVE (Pass-1)
	 *
	 * "Faithful base + spacing→native" fidelity upgrade. For every APPEARANCE property the native
	 * shortcode/preset mapping does NOT already reproduce, emit a SPECIFICITY-0 `:where(selector){…}`
	 * rule so the element looks EXACTLY like the source, while theme-settings / presets / builder edits
	 * (all specificity >= 0,1,0) still override. Union of (native-mapped) + (faithful base) covers 100%
	 * of the element's appearance = "nothing dropped, no drift". Layout/structure (display/position/
	 * size/flex/grid) is NOT carried — that stays native (row/column mapping); spacing (margin) maps to
	 * the shortcode's NATIVE spacing option instead of raw CSS.
	 * ---------------------------------------------------------------------- */

	/** APPEARANCE properties the faithful base reproduces (Pass-2). Layout/structure + spacing are
	 *  intentionally EXCLUDED (display, position, top/left/width/height, the flex- and grid- families,
	 *  justify, align, gap, margin, padding) — spacing maps natively; layout is handled by structural mapping. */
	private static $cs_appearance = array(
		'background-color', 'background-image', 'color', 'font-family', 'font-size', 'font-weight',
		'line-height', 'letter-spacing', 'text-align', 'text-transform', 'text-decoration-line',
		'border', 'border-radius', 'box-shadow', 'opacity', 'transform', 'transition',
		// Gradient TEXT (background-clip:text) — the clip + transparent fill that make a
		// gradient background paint the TEXT instead of a block. Only present when the source
		// actually paints gradient text (capture harvests them guarded), and cs_value_inert()
		// drops any non-`text` clip / non-transparent fill, so a normal element carries none.
		'-webkit-background-clip', 'background-clip', '-webkit-text-fill-color',
	);

	/** Faithful-base master switch for THIS build. DEFAULT ON; set from build_bundle's `hifi_css` opt. */
	private static $hifi_on = false;

	/** Enable/disable the hi-fi faithful base for this build. */
	public static function set_hifi_css( $on ) { self::$hifi_on = (bool) $on; }

	/** Is the faithful base active? */
	public static function hifi_on() { return self::$hifi_on; }

	/** Absolute source origin for THIS build (from the bundle manifest), so a relative /assets/*.svg can be
	 *  fetched + inlined. '' when unknown (e.g. an HTML upload) → SVG icons degrade to a custom-upload URL. */
	private static $source_url = '';

	/** Set the source site URL for this build (enables SVG icon/illustration inlining). */
	public static function set_source_url( $url ) { self::$source_url = (string) $url; self::$rwd_shim_done = false; }

	/** Emitted-once guard for the responsive-display CSS shim (reset per build via set_source_url). */
	private static $rwd_shim_done = false;

	/** Tailwind responsive display utilities have NO runtime in the builder, so a verbatim `hidden lg:block`
	 *  desktop variant and its `lg:hidden` mobile twin BOTH show (duplicated images). Emit real CSS for those
	 *  utilities — scoped to the converter's `.sc-tw` verbatim wrapper, once per build — so show/hide works. */
	private static function maybe_rwd_shim( $html ) {
		if ( self::$rwd_shim_done ) { return $html; }
		if ( ! preg_match( '/\b(?:hidden|(?:sm|md|lg|xl):(?:hidden|block|flex|grid|inline-block|inline-flex))\b/', $html ) ) { return $html; }
		self::$rwd_shim_done = true;
		$disp = array( 'block' => 'block', 'flex' => 'flex', 'grid' => 'grid', 'inline-block' => 'inline-block', 'inline-flex' => 'inline-flex' );
		$bps  = array( 'sm' => 640, 'md' => 768, 'lg' => 1024, 'xl' => 1280 );
		$css  = '.sc-tw .hidden{display:none !important}';
		foreach ( $bps as $bp => $px ) {
			$rules = '.sc-tw .' . $bp . '\\:hidden{display:none !important}';
			foreach ( $disp as $u => $d ) { $rules .= '.sc-tw .' . $bp . '\\:' . $u . '{display:' . $d . ' !important}'; }
			$css .= '@media(min-width:' . $px . 'px){' . $rules . '}';
		}
		return '<style>' . $css . '</style>' . $html;
	}

	/** Per-build cache of fetched+sanitised SVG markup, keyed by absolute URL (an svg can appear as both an
	 *  icon and an illustration; fetch each once). */
	private static $svg_cache = array();

	/** Absolutise a source-relative asset URL against the source origin, so an un-inlined SVG HOTLINKS on the
	 *  new domain (source returns 200) instead of 404-ing as a bare `/assets/…` path. Unchanged if already
	 *  absolute / a data URI / no source origin is known. This is the reliability fallback for SVG inlining. */
	private static function abs_asset( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url || preg_match( '#^(?:https?:)?//#i', $url ) || 0 === strpos( $url, 'data:' ) ) { return $url; }
		if ( class_exists( 'FW_Site_Converter_Media' ) && '' !== self::$source_url ) {
			$abs = FW_Site_Converter_Media::absolutize( $url, self::$source_url );
			if ( '' !== $abs ) { return $abs; }
		}
		return $url;
	}

	/** Absolutise a (possibly relative) svg src against the source origin, fetch + sanitise it (cached). */
	private static function fetch_svg_cached( $src ) {
		$src = trim( (string) $src );
		if ( $src === '' || ! preg_match( '/\.svg(?:$|\?)/i', $src ) || ! class_exists( 'FW_Site_Converter_Media' ) ) { return ''; }
		$abs = preg_match( '#^https?://#i', $src ) ? $src : FW_Site_Converter_Media::absolutize( $src, self::$source_url );
		if ( $abs === '' || ! preg_match( '#^https?://#i', $abs ) ) { return ''; }
		if ( ! array_key_exists( $abs, self::$svg_cache ) ) { self::$svg_cache[ $abs ] = FW_Site_Converter_Media::fetch_svg_markup( $abs ); }
		return self::$svg_cache[ $abs ];
	}

	/** Inline any `<img src=*.svg>` inside verbatim HTML as sanitised inline <svg> — WordPress can't host SVG
	 *  in the media library, so a relative/sideloaded <img> 404s. Leaves non-SVG / un-fetchable imgs alone. */
	private static function inline_svg_imgs( $html ) {
		if ( stripos( $html, '.svg' ) === false ) { return $html; }
		return preg_replace_callback(
			'/<img\b[^>]*\bsrc\s*=\s*(["\'])([^"\']+\.svg(?:\?[^"\']*)?)\1[^>]*>/i',
			function ( $m ) {
				$svg = self::fetch_svg_cached( $m[2] );
				if ( $svg === '' ) {
					// Couldn't inline — absolutise the src so it HOTLINKS instead of 404-ing as a relative path.
					$abs = self::abs_asset( $m[2] );
					return ( $abs !== $m[2] ) ? str_replace( $m[2], $abs, $m[0] ) : $m[0];
				}
				$svg = preg_replace( '/<svg\b/i', '<svg style="max-width:100%;height:auto"', $svg, 1 );
				return '<span class="sc-illustration" style="display:inline-block;max-width:100%;">' . $svg . '</span>';
			},
			$html
		);
	}

	/**
	 * An `<img src=*.svg>` → an INLINE icon-v2 SVG value. WordPress can't host SVG in the media library,
	 * so a custom-upload URL 404s; inline markup renders on any domain via sc_icon_render and keeps the
	 * illustration's own colours. Fetches the markup (absolutising a relative src against the source
	 * origin). Returns null when it isn't an SVG or can't be fetched → the caller falls back.
	 *
	 * @param string $src an <img> src (absolute or source-relative)
	 * @return array|null icon-v2 svg-inline value, or null
	 */
	private static function svg_inline_icon( $src ) {
		$svg = self::fetch_svg_cached( $src );
		if ( $svg === '' ) { return null; }
		// icon_box's `icon_size` emits font-size on the icon wrapper. A raw <svg> with fixed width/height
		// ignores it (stays glyph-small). Strip the fixed dimensions and let the SVG track font-size
		// (height:1em, width auto — aspect preserved by the viewBox), so icon_size actually resizes it.
		$svg = preg_replace_callback( '/<svg\b[^>]*>/i', function ( $m ) {
			$tag = preg_replace( '/\s(?:width|height)\s*=\s*("[^"]*"|\'[^\']*\')/i', '', $m[0] );
			if ( preg_match( '/\sstyle\s*=\s*"/i', $tag ) ) {
				return preg_replace( '/(\sstyle\s*=\s*")/i', '$1height:1em;width:auto;max-width:100%;', $tag, 1 );
			}
			return preg_replace( '/<svg\b/i', '<svg style="height:1em;width:auto;max-width:100%"', $tag, 1 );
		}, $svg, 1 );
		return array( 'type' => 'svg', 'svg-source' => 'inline', 'markup' => $svg, 'svg-id' => '' );
	}

	/** Is a computed appearance value visually INERT (a browser initial value that carries no look)? So the
	 *  base stays lean — an element at the CSS default for a prop gets no rule for it. */
	private static function cs_value_inert( $prop, $v ) {
		$v = trim( strtolower( (string) $v ) );
		if ( '' === $v ) { return true; }
		switch ( $prop ) {
			case 'background-color':     return in_array( $v, array( 'transparent', 'rgba(0, 0, 0, 0)' ), true );
			case 'background-image':     return 'none' === $v;
			case 'box-shadow':           return 'none' === $v;
			case 'transform':            return 'none' === $v;
			// Only a REAL transition carries motion; the CSS initial (all/none at 0s) is inert.
			case 'transition':           return 'none' === $v || 'all 0s ease 0s' === $v || 'none 0s ease 0s' === $v || (bool) preg_match( '/^all 0s /', $v );
			case 'opacity':              return '1' === $v;
			case 'border':               return 0 === strpos( $v, '0px' ) || false !== strpos( $v, ' none ' ) || preg_match( '/^0(px)?\s/', $v );
			case 'border-radius':        return '0px' === $v || '0' === $v;
			case 'text-transform':       return 'none' === $v;
			case 'text-decoration-line': return 'none' === $v;
			case 'letter-spacing':       return 'normal' === $v;
			case 'line-height':          return 'normal' === $v;
			case 'font-weight':          return '400' === $v || 'normal' === $v;
			case 'text-align':           return 'start' === $v || 'left' === $v;
			// Gradient-text clip: only `text` carries a look; the default `border-box` is inert.
			case '-webkit-background-clip':
			case 'background-clip':        return 'text' !== $v;
			// The transparent fill is what reveals the gradient through the glyphs; any solid fill is
			// just the element's own colour (already carried by `color`) → inert here.
			case '-webkit-text-fill-color': return 'transparent' !== $v && 'rgba(0, 0, 0, 0)' !== $v;
		}
		return false;
	}

	/**
	 * Pass-2 FAITHFUL BASE — the specificity-0 `:where(selector){…}` rule of every appearance property in
	 * the element's computed style ($cs) that the native mapping ($already = props the node already
	 * reproduced) did NOT cover, minus visually-inert defaults. '' when nothing remains (hi-fi off / no
	 * cs / everything already covered).
	 *
	 * @param string $cs      the element's data-sc-cs (computed style) string
	 * @param array  $already property names the node already reproduces natively (skip them → lean base)
	 * @return string a `:where(selector){…}` rule, or ''
	 */
	public static function hifi_base_css( $cs, array $already = array() ) {
		if ( ! self::$hifi_on || '' === (string) $cs ) { return ''; }
		$decls = self::cs_decls( (string) $cs, self::$cs_appearance );
		if ( ! $decls ) { return ''; }
		$flip = array();
		foreach ( $already as $p ) { $flip[ $p ] = true; }
		$body = '';
		foreach ( $decls as $pr => $v ) {
			if ( isset( $flip[ $pr ] ) ) { continue; }
			if ( self::cs_value_inert( $pr, $v ) ) { continue; }
			$body .= $pr . ':' . $v . ';';
		}
		return '' === $body ? '' : ':where(selector){' . $body . '}';
	}

	/** Append a faithful base (built from $cs minus $already) to a node's Custom CSS (additive). Returns the node. */
	private static function apply_hifi_base( $node, $cs, array $already = array() ) {
		if ( ! is_array( $node ) || ! isset( $node['atts'] ) || ! is_array( $node['atts'] ) ) { return $node; }
		$base = self::hifi_base_css( $cs, $already );
		if ( '' === $base ) { return $node; }
		$cur = isset( $node['atts']['custom_css'] ) ? (string) $node['atts']['custom_css'] : '';
		$node['atts']['custom_css'] = trim( $cur . ( '' !== $cur ? "\n" : '' ) . $base );
		return $node;
	}

	/** A px length → the nearest spacing-scale slug (reuses the rem scale; px = rem × 16). */
	private static function spacing_px_to_slug( $px ) {
		return self::rem_to_spacing_slug( (float) $px / 16.0 );
	}

	/**
	 * Pass-1 SPACING → NATIVE — read the element's computed vertical MARGIN from $cs and map each side to
	 * the nearest spacing-scale token, merged into a native `spacing` option box (only sides not already
	 * set from source classes). Returns the updated spacing box, or the input unchanged. Horizontal margin
	 * / padding stay structural (handled by the column / box preset), so they are NOT carried here.
	 *
	 * @param array  $spacing an existing native spacing att (def_spacing()/empty_spacing() shape)
	 * @param string $cs      the element's data-sc-cs
	 * @return array the (possibly updated) spacing att
	 */
	/**
	 * The source element's computed PADDING → the native spacing option's `padding` (editable). Equal
	 * padding on all four sides collapses to the `all` slug (`p-8`); otherwise each side is set. Skips
	 * a side already set by a class map, and hairline/zero values. Mirrors apply_native_margin.
	 */
	/**
	 * Tailwind responsive VISIBILITY classes → the native `responsive_hide` checkboxes (hide-xs = mobile
	 * <768, hide-sm = tablet 768–991, hide-md = desktop ≥992). Handles `md:hidden` / `lg:hidden` (hidden
	 * FROM a breakpoint up) and `hidden md:flex|block|grid|…` (hidden BELOW a breakpoint). Lets a source's
	 * responsive show/hide survive on a NATIVE element — e.g. a `md:hidden` mobile CTA twin stops showing on
	 * desktop beside its `hidden md:flex` desktop version. Returns [] when the class has no visibility rule.
	 */
	private static function responsive_hide_from_class( $cls ) {
		$c    = ' ' . strtolower( (string) $cls ) . ' ';
		$hide = array();   // ASSOCIATIVE: keyed by the hide-* class (the checkboxes option reads array_keys()).
		// `hidden` + a breakpoint-show (md:flex / lg:block / …) → hidden BELOW that breakpoint.
		if ( preg_match( '/\bhidden\b/', $c ) && preg_match( '/\b(sm|md|lg|xl):(?:flex|block|grid|inline|inline-flex|inline-block|table)\b/', $c, $m ) ) {
			$hide['hide-xs'] = 'hide-xs';                             // mobile always hidden here
			if ( 'lg' === $m[1] || 'xl' === $m[1] ) { $hide['hide-sm'] = 'hide-sm'; }   // shown only ≥ lg → tablet hidden too
		}
		// `md:hidden` / `lg:hidden` / `sm:hidden` → hidden FROM that breakpoint up.
		if ( preg_match( '/\b(sm|md|lg|xl):hidden\b/', $c, $m ) ) {
			if ( 'lg' === $m[1] || 'xl' === $m[1] ) { $hide['hide-md'] = 'hide-md'; }   // desktop only
			else { $hide['hide-sm'] = 'hide-sm'; $hide['hide-md'] = 'hide-md'; }        // sm/md → tablet + desktop
		}
		return $hide;
	}

	private static function apply_native_padding( $spacing, $cs ) {
		if ( ! self::$hifi_on || '' === (string) $cs || ! is_array( $spacing ) || ! isset( $spacing['padding'] ) ) { return $spacing; }
		$p = self::cs_decls( (string) $cs, array( 'padding-top', 'padding-right', 'padding-bottom', 'padding-left' ) );
		$slug_of = function ( $v ) {
			if ( ! is_string( $v ) || $v === '' || strpos( $v, 'var(' ) !== false || strpos( $v, 'auto' ) !== false ) { return null; }
			$px = self::px_of( $v );
			if ( $px < 6 ) { return null; }
			$s = self::spacing_px_to_slug( $px );
			return ( '0' === $s ) ? null : $s;
		};
		$sides = array( 'top' => 'padding-top', 'right' => 'padding-right', 'bottom' => 'padding-bottom', 'left' => 'padding-left' );
		$slugs = array();
		foreach ( $sides as $side => $prop ) { $slugs[ $side ] = isset( $p[ $prop ] ) ? $slug_of( $p[ $prop ] ) : null; }
		$vals = array_values( array_unique( array_filter( $slugs, function ( $x ) { return $x !== null; } ) ) );
		// All four sides present + equal → the single `all` control.
		if ( count( array_filter( $slugs, function ( $x ) { return $x !== null; } ) ) === 4 && count( $vals ) === 1 ) {
			if ( empty( $spacing['padding']['all'] ) ) { $spacing['padding']['all'] = 'p-' . $vals[0]; }
			return $spacing;
		}
		$pref = array( 'top' => 'pt', 'right' => 'pr', 'bottom' => 'pb', 'left' => 'pl' );
		foreach ( $slugs as $side => $slug ) {
			if ( $slug === null ) { continue; }
			if ( ! empty( $spacing['padding'][ $side ] ) ) { continue; }
			$spacing['padding'][ $side ] = $pref[ $side ] . '-' . $slug;
		}
		return $spacing;
	}

	private static function apply_native_margin( $spacing, $cs ) {
		if ( ! self::$hifi_on || '' === (string) $cs || ! is_array( $spacing ) || ! isset( $spacing['margin'] ) ) { return $spacing; }
		$m = self::cs_decls( (string) $cs, array( 'margin-top', 'margin-bottom' ) );
		$pairs = array( 'margin-top' => array( 'top', 'mt' ), 'margin-bottom' => array( 'bottom', 'mb' ) );
		foreach ( $pairs as $prop => $meta ) {
			list( $side, $pref ) = $meta;
			if ( ! isset( $m[ $prop ] ) ) { continue; }
			if ( isset( $spacing['margin'][ $side ] ) && '' !== $spacing['margin'][ $side ] ) { continue; } // don't overwrite a class-mapped side
			if ( false !== strpos( $m[ $prop ], 'var(' ) || false !== strpos( $m[ $prop ], 'auto' ) ) { continue; }
			$px = self::px_of( $m[ $prop ] );
			if ( $px < 6 ) { continue; } // ignore hairline/zero margins
			$slug = self::spacing_px_to_slug( $px );
			if ( '0' === $slug ) { continue; }
			$spacing['margin'][ $side ] = $pref . '-' . $slug;
		}
		return $spacing;
	}

	/**
	 * From a section direct-child "layer" (class / inline style / computed style), return its SOLID,
	 * opaque background-COLOR if it qualifies as a full-bleed band fill to hoist onto the section — else ''.
	 * Discrimination (so decorative overlays are NOT hoisted):
	 *   - the layer must be FULL-BLEED (Tailwind `inset-0`, or `w-full`+`h-full`, or a computed `position:absolute`
	 *     with inset:0px) — a tiny child is never the band;
	 *   - SKIP low-opacity decorative overlays (`opacity-10`..`opacity-40`, or computed `opacity` < 0.5);
	 *   - the background must be an OPAQUE / near-opaque solid colour (alpha >= 0.85) — a gradient-only /
	 *     `background-image`-only overlay (the dot pattern) has no `background-color` and is skipped.
	 *
	 * @param array $layer { cls:string, style:string, cs:string }
	 * @return string a `background-color` value to hoist, or ''
	 */
	private static function layer_band_bg( $layer ) {
		$cls   = ' ' . strtolower( (string) ( $layer['cls'] ?? '' ) ) . ' ';
		$cs    = (string) ( $layer['cs'] ?? '' );
		$style = (string) ( $layer['style'] ?? '' );
		$decl  = self::cs_decls( $cs, array( 'background-color', 'position', 'opacity', 'inset' ) );
		// Full-bleed test: a class flag (inset-0 / w-full h-full) OR a computed absolute inset:0.
		$full_bleed = ( strpos( $cls, ' inset-0 ' ) !== false )
			|| ( strpos( $cls, ' w-full ' ) !== false && strpos( $cls, ' h-full ' ) !== false )
			|| ( strpos( $cls, ' absolute ' ) !== false && strpos( $cls, ' inset-0' ) !== false );
		if ( ! $full_bleed ) {
			$pos   = isset( $decl['position'] ) ? $decl['position'] : '';
			$inset = isset( $decl['inset'] ) ? $decl['inset'] : '';
			if ( ( 'absolute' === $pos || 'fixed' === $pos ) && preg_match( '/^0px(\s+0px){0,3}$/', trim( $inset ) ) ) { $full_bleed = true; }
		}
		if ( ! $full_bleed ) { return ''; }
		// Decorative low-opacity overlay → skip. Tailwind opacity-10..40, or a computed opacity < 0.5.
		if ( preg_match( '/\sopacity-(0|5|10|15|20|25|30|35|40|45)\s/', $cls ) ) { return ''; }
		if ( isset( $decl['opacity'] ) && is_numeric( trim( $decl['opacity'] ) ) && (float) $decl['opacity'] < 0.5 ) { return ''; }
		// The solid fill: prefer the computed background-color, else an inline `background-color`/`background`.
		$bc = isset( $decl['background-color'] ) ? trim( $decl['background-color'] ) : '';
		if ( '' === $bc && preg_match( '/background(?:-color)?\s*:\s*([^;]+)/i', $style, $mm ) ) { $bc = trim( $mm[1] ); }
		if ( '' === $bc || 'transparent' === $bc ) { return ''; }
		// Must be an OPAQUE solid colour. An rgba()/hsla() with alpha < 0.85 (or a *-gradient) is not a band fill.
		if ( stripos( $bc, 'gradient' ) !== false ) { return ''; }
		if ( preg_match( '/rgba?\(\s*[\d.]+\s*,\s*[\d.]+\s*,\s*[\d.]+\s*,\s*([\d.]+)\s*\)/i', $bc, $am ) ) {
			if ( (float) $am[1] < 0.85 ) { return ''; }
		} elseif ( preg_match( '/hsla?\([^)]*,\s*([\d.]+)\s*\)/i', $bc, $am ) && substr_count( $bc, ',' ) >= 3 ) {
			if ( (float) $am[1] < 0.85 ) { return ''; }
		}
		return $bc;
	}

	/**
	 * Compile an element's source classes → a de-duplicated semantic class name (registers its CSS rule).
	 * Shared by the box-column mapper and the button-fill mapper.
	 *
	 * @param string $cls        the source class="" string
	 * @param string $hint       base class name ('box', 'btn-fill', …)
	 * @param bool   $border_box add box-sizing:border-box (boxes that combine padding + border + width)
	 */
	private static function register_style( $cls, $hint, $border_box = false, $btn_kind = '', $inline = array() ) {
		if ( ! self::$style_on ) { return ''; }
		$cls = trim( (string) $cls );
		if ( $cls === '' && ! $inline ) { return ''; }
		$cm = ( $cls !== '' ) ? FW_Site_Converter_Tailwind::compile_class_set( $cls, self::$style_cfg ) : array( 'base' => array(), 'hover' => array() );
		// Phase 2: fold in the element's RESOLVED computed styles (from data-sc-cs) — the universal styling
		// source that lets a NON-Tailwind site reproduce faithfully (resolved values win over compiled classes).
		if ( $inline ) { $cm['base'] = array_merge( isset( $cm['base'] ) ? $cm['base'] : array(), $inline ); }
		if ( empty( $cm['base'] ) && empty( $cm['hover'] ) ) { return ''; }
		// Tailwind's `border` only sets border-WIDTH; border-style:solid comes from its preflight (scoped to
		// .sc-tw, which doesn't reach this GLOBAL class). Make it self-contained so a border actually renders.
		if ( isset( $cm['base']['border-width'] ) && ! isset( $cm['base']['border-style'] ) ) { $cm['base']['border-style'] = 'solid'; }
		if ( $border_box && $cm['base'] ) { $cm['base']['box-sizing'] = 'border-box'; }
		// Button self-containment: a converted button keeps the framework `.btn` BASE (display/line-height),
		// so the compiled class must explicitly shed the chrome the SOURCE button doesn't have — otherwise the
		// base `.btn`'s gray fill / border / padding bleed through. A `link` (no fill, no border) must read as
		// a bare text link; an `outline` must have a transparent fill.
		$has_bg = isset( $cm['base']['background-color'] ) || isset( $cm['base']['background'] );
		if ( 'link' === $btn_kind ) {
			if ( ! $has_bg ) { $cm['base']['background-color'] = 'transparent'; }
			if ( ! isset( $cm['base']['border-width'] ) ) { $cm['base']['border-width'] = '0'; $cm['base']['border-style'] = 'none'; }
			if ( ! isset( $cm['base']['padding'] ) && ! isset( $cm['base']['padding-left'] ) && ! isset( $cm['base']['padding-top'] ) ) { $cm['base']['padding'] = '0'; }
		} elseif ( 'outline' === $btn_kind && ! $has_bg ) {
			$cm['base']['background-color'] = 'transparent';
		}
		// A FILLED button (primary/light/fill) whose SOURCE has no border must explicitly SHED the button
		// base's zero-specificity default outline (`:where(.btn){border:1px solid #ced4da}`) — otherwise a
		// borderless source button rendered with a stray grey 1px ring. Mirrors the `link` treatment above.
		if ( in_array( $btn_kind, array( 'primary', 'light', 'fill' ), true ) && ! isset( $cm['base']['border-width'] ) ) {
			$cm['base']['border-width'] = '0';
			$cm['base']['border-style'] = 'none';
		}
		$key = md5( $hint . '|' . wp_json_encode( array( $cm['base'], $cm['hover'] ) ) );
		if ( isset( self::$style_key[ $key ] ) ) { return self::$style_key[ $key ]; }
		if ( isset( self::$style_count[ $hint ] ) ) { self::$style_count[ $hint ]++; $name = $hint . '-' . self::$style_count[ $hint ]; }
		else { self::$style_count[ $hint ] = 1; $name = $hint; }
		$css = '';
		if ( $cm['base'] )  { $css .= '.' . $name . '{' . FW_Site_Converter_Tailwind::decl_string( $cm['base'] ) . '}'; }
		if ( $cm['hover'] ) { $css .= '.' . $name . ':hover{' . FW_Site_Converter_Tailwind::decl_string( $cm['hover'] ) . '}'; }
		self::$style_key[ $key ]  = $name;
		self::$style_css[ $name ] = $css;
		return $name;
	}

	/** A box container's classes (+ optional resolved computed styles) → a `.box` semantic class. */
	private static function box_style_class( $cls, $cs = '' ) {
		$inline = '' !== (string) $cs ? self::cs_decls( $cs, self::$cs_box ) : array();
		return self::register_style( $cls, 'box', true, '', $inline );
	}

	/**
	 * A button's source classes → a SEMANTIC class carrying its fill / text color / radius / padding. The
	 * name is prefixed `sc-btn-` so it NEVER collides with the framework's own `.btn-primary` / `.btn-outline-*`
	 * style presets (the converter leaves the button's `style` option on Default = the bare `.btn` base, and
	 * lets this compiled class do the styling). Kinds: a solid `bg-primary`/`bg-*` → `sc-btn-primary`; a
	 * light/white fill → `sc-btn-light`; a bordered ghost → `sc-btn-outline`; NO fill + NO border (a
	 * `text-primary hover:underline` CTA) → `sc-btn-link` (rendered as a bare text link); else `sc-btn-fill`.
	 */
	private static function button_style_class( $cls, $cs = '' ) {
		$inline = '' !== (string) $cs ? self::cs_decls( $cs, self::$cs_btn ) : array();
		if ( $inline ) {
			// Kind from the RESOLVED computed style (works for ANY site): an opaque fill → primary; a
			// transparent fill with a border → outline; neither → a bare text link.
			if ( isset( $inline['background-color'] ) ) { $kind = 'primary'; }
			elseif ( isset( $inline['border'] ) )       { $kind = 'outline'; }
			else                                        { $kind = 'link'; }
		} else {
			$c          = ' ' . strtolower( (string) $cls ) . ' ';
			$has_bg     = (bool) preg_match( '/\sbg-(?!transparent)/', $c ); // a real (opaque) fill
			$has_border = ( strpos( $c, ' border' ) !== false );
			if ( strpos( $c, ' bg-primary' ) !== false || strpos( $c, ' bg-accent' ) !== false || strpos( $c, ' bg-brand' ) !== false ) {
				$kind = 'primary';
			} elseif ( strpos( $c, ' bg-white' ) !== false || strpos( $c, ' bg-surface' ) !== false ) {
				$kind = 'light';
			} elseif ( $has_border && ! $has_bg ) {
				$kind = 'outline';
			} elseif ( ! $has_bg ) {
				$kind = 'link'; // no fill, no border → a text link, not a button
			} else {
				$kind = 'fill';
			}
		}
		return self::register_style( $cls, 'sc-btn-' . $kind, false, $kind, $inline );
	}

	/** The `.btn-row` flex-row wrapper class (registers its CSS once) — for a side-by-side button group. */
	private static function btn_row_class() {
		if ( ! isset( self::$style_css['btn-row'] ) ) {
			// The buttons render full-width/block inside a column, so without sizing them to content two
			// would wrap and stack — flex:0 0 auto + width:auto keeps each at its content width, side-by-side.
			self::$style_css['btn-row'] = '.btn-row{display:flex;gap:1rem;justify-content:center;align-items:center;flex-wrap:wrap;}'
				. '.btn-row>.btn,.btn-row>a{flex:0 0 auto;width:auto;}';
		}
		return 'btn-row';
	}

	/** Compile the SOURCE button-container's flex styling (direction / gap / alignment / bottom margin) into a
	 *  row class so a converted button row matches the source instead of hardcoded defaults. De-duped. */
	private static function btn_group_class( $cls, $cs ) {
		$c = ' ' . strtolower( (string) $cls ) . ' ';
		$d = array( 'display' => 'flex', 'flex-wrap' => 'wrap', 'align-items' => 'center', 'justify-content' => 'center' );
		if ( preg_match( '/\s(?:sm|md|lg|xl|2xl):flex-row\b/', $c ) || strpos( $c, ' flex-row ' ) !== false ) { $d['flex-direction'] = 'row'; }
		elseif ( strpos( $c, ' flex-col ' ) !== false ) { $d['flex-direction'] = 'column'; }
		if ( preg_match( '/\s(?:[a-z0-9]+:)?space-x-(\d+)/', $c, $m ) ) { $d['gap'] = ( (int) $m[1] * 0.25 ) . 'rem'; }
		elseif ( preg_match( '/\s(?:[a-z0-9]+:)?gap-(\d+)/', $c, $m ) ) { $d['gap'] = ( (int) $m[1] * 0.25 ) . 'rem'; }
		else { $d['gap'] = '1rem'; }
		if ( strpos( $c, ' justify-start ' ) !== false ) { $d['justify-content'] = 'flex-start'; }
		elseif ( strpos( $c, ' justify-between ' ) !== false ) { $d['justify-content'] = 'space-between'; }
		if ( preg_match( '/\smb-(\d+)/', $c, $m ) ) { $d['margin-bottom'] = ( (int) $m[1] * 0.25 ) . 'rem'; }
		if ( '' !== (string) $cs ) { $d = array_merge( $d, self::cs_decls( (string) $cs, array( 'gap', 'justify-content', 'align-items', 'flex-direction', 'margin-bottom' ) ) ); }
		$key = md5( 'btngrp|' . wp_json_encode( $d ) );
		if ( isset( self::$style_key[ $key ] ) ) { return self::$style_key[ $key ]; }
		$name = 'sc-btn-row-' . substr( $key, 0, 6 );
		$body = '';
		foreach ( $d as $pr => $v ) { $body .= $pr . ':' . $v . ';'; }
		self::$style_key[ $key ]  = $name;
		self::$style_css[ $name ] = '.' . $name . '{' . $body . '}.' . $name . '>.btn,.' . $name . '>a{flex:0 0 auto;width:auto;}';
		return $name;
	}

	/** Left-align an icon_box's title/content/icon (the `top-title` style hardcodes center) — for source
	 *  cards whose text is left-aligned. Registers the rule once; returns the class to add. */
	private static function ib_left_class() {
		if ( ! isset( self::$style_css['sc-ib-left'] ) ) {
			self::$style_css['sc-ib-left'] = '.icon-box.sc-ib-left,.icon-box.sc-ib-left .icon-box__title,.icon-box.sc-ib-left .icon-box__content,.icon-box.sc-ib-left .icon-box__icon-align{text-align:left !important;}';
		}
		return 'sc-ib-left';
	}

	/** Reproduce the source's gray icon "image" box (a fill + height + rounded around the icon) as a class
	 *  on the icon_box, so the card matches the source instead of showing a bare icon. Registered + de-duped. */
	private static function ib_iconbox_class( $cls, $cs ) {
		if ( ! self::$style_on ) { return ''; }
		$cm   = FW_Site_Converter_Tailwind::compile_class_set( (string) $cls, self::$style_cfg );
		$base = ( is_array( $cm ) && isset( $cm['base'] ) && is_array( $cm['base'] ) ) ? $cm['base'] : array();
		if ( '' !== (string) $cs ) { $base = array_merge( $base, self::cs_decls( (string) $cs, array( 'background-color', 'border-radius', 'height', 'min-height' ) ) ); }
		$bg     = isset( $base['background-color'] ) ? $base['background-color'] : ( isset( $base['background'] ) ? $base['background'] : 'rgb(238,240,242)' );
		$radius = isset( $base['border-radius'] ) ? $base['border-radius'] : '12px';
		$h      = isset( $base['height'] ) ? $base['height'] : ( isset( $base['min-height'] ) ? $base['min-height'] : '160px' );
		$key    = md5( 'ibx|' . $bg . '|' . $radius . '|' . $h );
		if ( isset( self::$style_key[ $key ] ) ) { return self::$style_key[ $key ]; }
		$name = 'sc-ib-box-' . substr( $key, 0, 6 );
		self::$style_key[ $key ]  = $name;
		self::$style_css[ $name ] = '.icon-box.' . $name . ' .icon-box__icon{background-color:' . $bg . ';border-radius:' . $radius . ';min-height:' . $h . ';width:100%;display:flex;align-items:center;justify-content:center;margin-bottom:1.5rem;}'
			. '.icon-box.' . $name . ' .icon-box__icon i{font-size:2.25rem;}';
		return $name;
	}

	/**
	 * Group runs of 2+ consecutive buttons into a flex-row wrapper so they sit SIDE-BY-SIDE (a page-builder
	 * column stacks its items vertically, so two buttons would otherwise stack). The group is a nested 1_1
	 * column whose Inner Wrapper Class is `.btn-row` (display:flex; gap; justify-content:center).
	 */
	private static function group_buttons( array $items ) {
		if ( ! self::$style_on ) { return $items; }
		$out = array(); $run = array();
		$strip = function ( $b ) { unset( $b['_group'] ); return $b; };
		$flush = function () use ( &$run, &$out, $strip ) {
			if ( count( $run ) >= 2 ) {
				// Side-by-side via the column's NATIVE content_direction + content_gap (not a .btn-row CSS
				// wrapper) — matches the JS to-pages path. Gap from the source button container if captured.
				$grp = isset( $run[0]['_group'] ) ? $run[0]['_group'] : array();
				$col = self::n_column( '1_1', array_map( $strip, $run ) );
				$col['atts']['content_direction'] = 'row';
				// Size the buttons to their CONTENT (not full-width). Converted `.btn`s render block/full-width,
				// so in a flex-row + flex-wrap column two full-width buttons WRAP and stack — the "hero buttons
				// aren't inline" bug. flex:0 0 auto + width:auto keeps each at content width, side-by-side (parity
				// with the old btn_row_class / btn_group_class rule the native content_direction path had dropped).
				$col['atts']['custom_css'] = 'selector .btn{flex:0 0 auto !important;width:auto !important;}';
				$gap = ( $grp && preg_match( '/gap:\s*([0-9.]+)px/', (string) ( $grp['cs'] ?? '' ), $gm ) ) ? self::gap_slug( $gm[1] ) : '';
				$col['atts']['content_gap'] = array( 'base' => ( $gap !== '' ? $gap : '3' ), 'md' => '', 'lg' => '' );
				// Horizontal placement = the SOURCE button container's real flex main-axis alignment (NOT a blind
				// center). Read the captured `justify-content` first, then the `justify-*` utility classes; a flex
				// row's unset/`normal` default is flex-start = LEFT — so a left-aligned hero CTA group stays left.
				$gcs = (string) ( $grp['cs'] ?? '' );
				$gcl = ' ' . strtolower( (string) ( $grp['cls'] ?? '' ) ) . ' ';
				$jc  = '';
				if ( preg_match( '/justify-content:\s*([a-z-]+)/', $gcs, $jm ) ) { $jc = $jm[1]; }
				if ( $jc === '' || $jc === 'normal' ) {
					if ( strpos( $gcl, ' justify-center ' ) !== false )       { $jc = 'center'; }
					elseif ( strpos( $gcl, ' justify-end ' ) !== false )      { $jc = 'flex-end'; }
					elseif ( strpos( $gcl, ' justify-start ' ) !== false )    { $jc = 'flex-start'; }
					elseif ( strpos( $gcl, ' justify-between ' ) !== false )  { $jc = 'space-between'; }
				}
				$jc_map = array( 'center' => 'center', 'flex-end' => 'right', 'end' => 'right', 'right' => 'right', 'flex-start' => 'left', 'start' => 'left', 'left' => 'left', 'normal' => 'left' );
				$col['atts']['content_h'] = isset( $jc_map[ $jc ] ) ? $jc_map[ $jc ] : 'left';
				$out[] = $col;
			} else {
				foreach ( $run as $r ) { $out[] = $strip( $r ); }
			}
			$run = array();
		};
		foreach ( $items as $it ) {
			if ( isset( $it['shortcode'] ) && $it['shortcode'] === 'button' ) { $run[] = $it; }
			else { $flush(); $out[] = $it; }
		}
		$flush();
		return $out;
	}

	/** All registered box-container rules (clean semantic classes), for the child theme stylesheet. */
	public static function registered_css() {
		$rules = array_values( array_filter( self::$style_css ) );
		if ( ! $rules ) { return ''; }
		return "/* ---- mapped box containers (clean semantic CSS from the source) ---- */\n" . implode( "\n", $rules );
	}

	/** Roles selectable in the editor (value => human label). */
	public static function roles() {
		return array(
			'overline' => __( 'Special Heading — Overline', 'fw' ),
			'title'    => __( 'Special Heading — Title', 'fw' ),
			'subtitle' => __( 'Special Heading — Subtitle', 'fw' ),
			'heading'  => __( 'Heading (own)', 'fw' ),
			'text'     => __( 'Text Block', 'fw' ),
			'button'   => __( 'Button', 'fw' ),
			'image'    => __( 'Image / Media', 'fw' ),
			'video'    => __( 'Video (Media)', 'fw' ),
			'columns'  => __( 'Columns (grid)', 'fw' ),
			'code'     => __( 'Code Block (verbatim)', 'fw' ),
			'skip'     => __( 'Skip (remove)', 'fw' ),
		);
	}

	/* ---------------------------------------------------------------------- *
	 * Suggestion (heuristics + learned rules)
	 * ---------------------------------------------------------------------- */

	/** A stable signature for a captured element: tag + its semantic (non-utility) classes. */
	public static function signature( array $b ) {
		$t = isset( $b['t'] ) ? (string) $b['t'] : '';
		if ( $t === 'row' ) { return 'row'; }
		if ( $t === 'html' ) { return 'html'; }
		$tag = isset( $b['tag'] ) ? strtolower( (string) $b['tag'] ) : ( $t === 'heading' ? 'h' . (int) ( $b['level'] ?? 2 ) : 'el' );
		$cls = isset( $b['cls'] ) ? strtolower( (string) $b['cls'] ) : '';
		// Drop Bootstrap/utility spacing & layout classes so the signature is stable across pages.
		$keep = array();
		foreach ( preg_split( '/\s+/', $cls ) as $c ) {
			if ( $c === '' ) { continue; }
			if ( preg_match( '/^(m[xytrbl]?-|p[xytrbl]?-|g-|gx-|gy-|col(-|$)|row$|container|d-|order-|offset-|w-|h-|align-|justify-|text-(start|end|center|left|right)$|float-|position-|top-|bottom-|start-|end-)/', $c ) ) { continue; }
			$keep[] = $c;
		}
		sort( $keep );
		return $tag . '|' . implode( ' ', $keep );
	}

	/** Suggest a role for one block (learned rule wins; else heuristics). */
	public static function suggest( array $b, array $rules ) {
		$sig = self::signature( $b );
		if ( $sig !== '' && isset( $rules[ $sig ] ) && isset( self::roles()[ $rules[ $sig ] ] ) ) {
			return $rules[ $sig ];
		}
		$t   = isset( $b['t'] ) ? $b['t'] : '';
		$cls = strtolower( isset( $b['cls'] ) ? (string) $b['cls'] : '' );
		$txt = trim( isset( $b['text'] ) ? (string) $b['text'] : '' );

		if ( $t === 'button' ) { return 'button'; }
		if ( $t === 'avatar' ) { return 'avatar'; }
		if ( $t === 'video' ) { return 'video'; }
		if ( $t === 'row' ) { return 'columns'; }
		if ( $t === 'html' ) { return 'code'; }
		if ( $t === 'heading' ) {
			return ( (int) ( $b['level'] ?? 2 ) <= 2 ) ? 'title' : 'heading';
		}
		if ( $t === 'text' ) {
			// Overline / eyebrow: small-uppercase-ish class or short ALL-CAPS-y text.
			$is_overline = preg_match( '/\b(overline|eyebrow|kicker|sub-?title-sm|text-uppercase|text-sm|letter-spacing|label|badge|tagline)\b/', $cls )
				|| ( mb_strlen( $txt ) <= 40 && $txt !== '' && mb_strtoupper( $txt ) === $txt );
			return $is_overline ? 'overline' : 'text';
		}
		return 'code';
	}

	/**
	 * Annotate a mapping payload with a suggested role per block. Adds a 'subtitle' refinement:
	 * the first plain Text right after a Title reads as the heading's subtitle.
	 *
	 * @param array $mapping `{ pages: [ { sections: [ { blocks: [...] } ] } ] }`
	 * @return array the same structure with `role` set on each block
	 */
	public static function suggest_mapping( array $mapping ) {
		$rules = self::get_rules();
		$pages = isset( $mapping['pages'] ) && is_array( $mapping['pages'] ) ? $mapping['pages'] : array();
		foreach ( $pages as &$page ) {
			$sections = isset( $page['sections'] ) && is_array( $page['sections'] ) ? $page['sections'] : array();
			$used_ids = array();
			foreach ( $sections as $idx => &$sec ) {
				// Auto CSS ID (editable in the UI) + per-section defaults.
				if ( empty( $sec['css_id'] ) ) { $sec['css_id'] = self::auto_id( $sec, $idx, $used_ids ); }
				$used_ids[ $sec['css_id'] ] = true;
				if ( ! isset( $sec['omit'] ) ) { $sec['omit'] = false; }
				if ( ! isset( $sec['verbatim'] ) ) { $sec['verbatim'] = false; }

				$blocks = isset( $sec['blocks'] ) && is_array( $sec['blocks'] ) ? $sec['blocks'] : array();
				$after_title = false;
				$used_subtitle = false;
				foreach ( $blocks as &$b ) {
					$role = self::suggest( $b, $rules );
					// First short intro paragraph right after a title → subtitle (brevity-guarded so real body
					// copy stays a Text Block; same guard as the decomposed-column path).
					if ( $role === 'text' && $after_title && ! $used_subtitle && self::is_heading_subtitle( $b ) ) {
						$role = 'subtitle';
						$used_subtitle = true;
					}
					$b['role'] = $role;
					if ( ! isset( $b['include'] ) ) { $b['include'] = true; }
					$after_title = in_array( $role, array( 'overline', 'title' ), true );
					if ( $role === 'columns' || $role === 'heading' ) { $used_subtitle = false; }
					unset( $b );
				}
				$sec['blocks'] = $blocks;
				unset( $sec );
			}
			$page['sections'] = $sections;
			unset( $page );
		}
		$mapping['pages'] = $pages;
		return $mapping;
	}

	/**
	 * Sanitize a raw id/anchor into a valid slug (lowercase, [a-z0-9-], trimmed).
	 * Mirrors FW_Site_Converter_Stitch::slug_from_id() so the two id paths agree.
	 */
	private static function slug_from_id( $raw ) {
		if ( class_exists( 'FW_Site_Converter_Stitch' ) && method_exists( 'FW_Site_Converter_Stitch', 'slug_from_id' ) ) {
			return FW_Site_Converter_Stitch::slug_from_id( $raw );
		}
		$s = strtolower( trim( (string) $raw ) );
		$s = preg_replace( '/[^a-z0-9-]+/', '-', $s );
		$s = preg_replace( '/-+/', '-', $s );
		return trim( $s, '-' );
	}

	/** A stable, unique CSS ID for a section: source id attribute first, then first meaningful source class, else N. */
	private static function auto_id( array $sec, $idx, array $used ) {
		$base = '';
		// 1) Prefer a source id carried on the section record (what in-page anchors / scroll-spy target).
		foreach ( array( 'sectionId', 'id' ) as $k ) {
			if ( ! empty( $sec[ $k ] ) ) {
				$sid = self::slug_from_id( $sec[ $k ] );
				if ( $sid !== '' ) { $base = $sid; break; }
			}
		}
		if ( $base === '' )
		foreach ( preg_split( '/\s+/', (string) ( $sec['sectionClass'] ?? '' ) ) as $c ) {
			// Prefer a descriptive class (about/process/cta) over generic structural ones.
			if ( $c !== '' && ! preg_match( '/^(sc-mirror|section|wrapper|block|area|inner|content|main|elementor|d-|align-|justify-|text-|p[xytrbl]?-|m[xytrbl]?-|g-|container|row|col|w-|h-|position-|overflow-|bg-|order-)/', $c ) ) {
				$base = $c; break;
			}
		}
		if ( $base === '' ) { $base = 'section-' . ( (int) $idx + 1 ); }
		$id = sanitize_html_class( $base );
		if ( $id === '' ) { $id = 'section-' . ( (int) $idx + 1 ); }
		$try = $id; $n = 2;
		while ( isset( $used[ $try ] ) ) { $try = $id . '-' . $n; $n++; }
		return $try;
	}

	/* ---------------------------------------------------------------------- *
	 * Build (mapping + roles → page-builder tree)
	 * ---------------------------------------------------------------------- */

	private static function uid() {
		return bin2hex( random_bytes( 16 ) );
	}

	/* ---------------------------------------------------------------------- *
	 * Node builders — produce the SAME full att structure the page-builder
	 * stores for a hand-built item (see button-sample-section export). Missing
	 * nested atts (min_height, background, spacing, animation, icon, …) make the
	 * builder's item migrators/render choke when the page is opened in the editor,
	 * so every node carries the complete default shape.
	 * ---------------------------------------------------------------------- */

	/** Default animation att (the Animations tab), shared by every element. */
	private static function def_animation() {
		return array(
			'enable' => 'no',
			'yes'    => array( 'effect' => 'animate__fadeInUp', 'speed_preset' => '', 'advanced_tweaks_heading' => '', 'delay' => 0, 'custom_duration' => 0, 'repeat_count' => 1, 'loop_forever' => 'no', 'replay_on_scroll' => 'no', 'easing' => '' ),
		);
	}
	/**
	 * The Animations-tab att for a node given a detected source animation intent (an animate.css effect
	 * string from Stitch::anim_intent, e.g. `animate__fadeInUp`). Empty intent → the disabled default
	 * (no false motion). A real intent → the same shape with `enable:'yes'` + the mapped effect.
	 */
	private static function anim_att( $effect ) {
		$a = self::def_animation();
		$effect = (string) $effect;
		if ( '' === $effect ) { return $a; }
		$a['enable']         = 'yes';
		$a['yes']['effect']  = $effect;
		return $a;
	}

	/**
	 * Overlay a node's Animations att from a captured block's `anim` intent (no-op when absent). Shape-aware:
	 * only touches the `{ enable, yes:{effect} }` animation shape (the standard elements — text/heading/button/
	 * image/counter/testimonials/…), whose effect vocabulary is the animate.css set def_animation() already
	 * uses. The interactive widgets (tabs/steps/…) use a different multi-picker `{effect}` whose valid slugs
	 * come from the animation-engine registry, so they're left at their safe default (no invalid value written).
	 */
	private static function apply_block_anim( array &$node, array $b ) {
		if ( empty( $b['anim'] ) || ! isset( $node['atts']['animation'] ) || ! is_array( $node['atts']['animation'] ) ) { return; }
		if ( ! array_key_exists( 'enable', $node['atts']['animation'] ) ) { return; } // multi-picker shape → leave default
		$node['atts']['animation'] = self::anim_att( (string) $b['anim'] );
	}

	/** Default spacing att (margin/padding + responsive), shared by columns / buttons. */
	private static function def_spacing() {
		$box = array( 'all' => '', 'top' => '', 'right' => '', 'bottom' => '', 'left' => '' );
		return array( 'margin' => $box, 'padding' => $box, 'advanced' => array( 'md' => array( 'margin' => $box, 'padding' => $box ), 'lg' => array( 'margin' => $box, 'padding' => $box ) ) );
	}

	/**
	 * "Attach media" uploads for the current conversion: basename(lowercased) => { id, url, mime }.
	 * Set by the build AJAX handlers from the sideloaded map, so captured media whose source URL
	 * matches an uploaded file (by filename) is rewritten to the Media-Library copy — and a
	 * full-screen background <video> can be wired into the section background.
	 *
	 * @var array
	 */
	private static $assets = array();

	/** @param array $map basename => { id, url, mime }. */
	public static function set_assets( $map ) {
		self::$assets = is_array( $map ) ? $map : array();
	}

	/** Match a captured media URL to an uploaded asset by filename. Returns { id, url, mime } or null. */
	private static function asset_for( $url ) {
		if ( empty( self::$assets ) || ! is_string( $url ) || $url === '' ) { return null; }
		$noqs = preg_replace( '/[?#].*$/', '', $url );
		$base = strtolower( basename( (string) $noqs ) );
		return ( $base !== '' && isset( self::$assets[ $base ] ) ) ? self::$assets[ $base ] : null;
	}

	/** An `upload`-type option value ({attachment_id,url}) for a URL — using the sideloaded copy if one matches. */
	private static function upload_val( $url ) {
		$url = (string) $url;
		if ( $url === '' ) { return array(); }
		$a = self::asset_for( $url );
		return $a ? array( 'attachment_id' => (string) $a['id'], 'url' => (string) $a['url'] ) : array( 'attachment_id' => '', 'url' => $url );
	}

	/**
	 * Wire a full-screen background `<video>` block onto a built section node's `background.video`
	 * layer (autoplay/muted/loop). The file is the captured `<source>` URL, or the matching "Attach
	 * media" upload when one was provided (upload_val swaps in the sideloaded copy). No‑op if nothing
	 * plays back, so a section without a resolvable video keeps its default background.
	 *
	 * @param array $node Section node (by ref).
	 * @param array $b    The captured video block { src, webm, poster, bg }.
	 */
	private static function apply_bg_video( array &$node, array $b ) {
		$mp4  = self::upload_val( isset( $b['src'] ) ? (string) $b['src'] : '' );
		$webm = self::upload_val( isset( $b['webm'] ) ? (string) $b['webm'] : '' );
		$post = self::upload_val( isset( $b['poster'] ) ? (string) $b['poster'] : '' );
		if ( empty( $mp4['url'] ) && empty( $webm['url'] ) ) { return; }
		if ( ! isset( $node['atts']['background'] ) || ! is_array( $node['atts']['background'] ) ) { return; }
		$node['atts']['background']['video'] = array(
			'enabled'      => 'yes',
			'external_url' => '',
			'source_mp4'   => empty( $mp4['url'] )  ? array() : $mp4,
			'source_webm'  => empty( $webm['url'] ) ? array() : $webm,
			'poster'       => empty( $post['url'] ) ? array() : $post,
			'fallback'     => array(),
			'loop'         => 'yes', 'autoplay' => 'yes', 'mute' => 'yes', 'playsinline' => 'yes',
			// Match the canonical background-pro video shape (a manually-built section carries this too).
			// Off = the background video is decorative and ignores clicks (no play/pause affordance).
			'allow_interaction' => 'no',
		);
		// A full-bleed background <video> band IS a hero → frame it (tall min-height + vertical content
		// placement) so the content keeps its top/bottom breathing room, exactly like an image-bg hero.
		// Without this a video-bg hero collapsed to zero vertical spacing (its source height came from
		// min-h-screen + flex-centering, which apply_bg_video didn't reproduce).
		self::apply_hero_frame( $node, (string) ( $b['valign'] ?? 'middle' ), (string) ( $b['hero_height'] ?? '' ) );
	}

	/**
	 * Frame a full-bleed-background band as a HERO: a tall min-height + vertical content placement (+ a
	 * left-flush container pin for a non-centered band) so the content sits with real top/bottom breathing
	 * room over the media, instead of collapsing to zero height. Shared by BOTH background paths
	 * (apply_bg_image + apply_bg_video) so an image-bg and a video-bg hero frame identically.
	 *
	 * @param array  $node   Section node (by ref).
	 * @param string $valign top|middle|bottom (default middle).
	 */
	private static function apply_hero_frame( array &$node, $valign = 'middle', $height = '' ) {
		if ( ! isset( $node['atts'] ) || ! is_array( $node['atts'] ) ) { return; }
		// The min-height mirrors the SOURCE hero height (`h-screen` → 100vh, `h-[80vh]` → 80vh, a computed vh
		// height → itself); fall back to 80vh only when the source gave a bare boolean signal. A vh value maps
		// to the preset; anything else (a px height) goes to the custom field.
		$h        = trim( (string) $height );
		$is_preset = ( '' !== $h && preg_match( '/^[0-9.]+vh$/', $h ) );
		$node['atts']['min_height']     = array(
			'preset' => $is_preset ? $h : ( '' === $h ? '80vh' : 'custom' ),
			'custom' => array( 'custom_height' => array( 'value' => ( ! $is_preset && '' !== $h ) ? preg_replace( '/[^0-9.].*$/', '', $h ) : '', 'unit' => 'px' ) ),
		);
		// The SECTION shortcode's vertical-align att is `column_valign` (values stretch/top/center/bottom), and
		// it DEFAULTS to 'stretch' — which OVERRIDES the legacy `content_valign` fallback the view also reads.
		// So set column_valign DIRECTLY, mapping the hero's 'middle' → the section's 'center'. (Without this the
		// hero rendered stretched/top-aligned and its heading overlapped an overlay header.)
		$vmap = array( 'top' => 'top', 'middle' => 'center', 'bottom' => 'bottom' );
		$node['atts']['column_valign'] = isset( $vmap[ (string) $valign ] ) ? $vmap[ (string) $valign ] : 'center';
		// NEVER-DROP hero horizontal alignment: a LEFT-aligned viewport-tall hero should sit LEFT-FLUSH like
		// the source, not in the theme's auto-centered max-width container. Skipped for a CENTERED band.
		if ( 'center' !== (string) ( isset( $node['atts']['text_align'] ) ? $node['atts']['text_align'] : '' ) ) {
			$cur = (string) ( isset( $node['atts']['custom_css'] ) ? $node['atts']['custom_css'] : '' );
			if ( false === strpos( $cur, 'margin-left:0 !important' ) ) {
				$node['atts']['custom_css'] = trim( $cur . ' selector .fw-container{margin-left:0 !important;margin-right:auto !important;}' );
			}
		}
	}

	/**
	 * Wire a HERO full-bleed background <img> onto a built section node's `background.image`, with a dark
	 * overlay scrim so overlaid (typically white) hero text stays legible — the source used a charcoal
	 * gradient over the photo. A viewport-tall source hero (`$bg['hero']`) also gets a tall min-height +
	 * the content's vertical placement, so the band reads as a real hero instead of a short image strip.
	 *
	 * @param array $node Section node (by ref).
	 * @param array $bg   { src, hero, valign }
	 */
	private static function apply_bg_image( array &$node, array $bg ) {
		$src = trim( (string) ( $bg['src'] ?? '' ) );
		if ( $src === '' || ! isset( $node['atts']['background'] ) || ! is_array( $node['atts']['background'] ) ) { return; }
		// Use the SIDELOADED media-library copy when one exists (upload_val matches by filename), so the
		// Background Image picker shows + can edit it — instead of a bare external URL with attachment_id 0
		// that the media widget can't preview (the "background looks empty / uneditable" bug).
		$img_v = self::upload_val( $src );
		$node['atts']['background']['image'] = array(
			'src'        => array(
				'attachment_id' => ( isset( $img_v['attachment_id'] ) && $img_v['attachment_id'] !== '' ) ? $img_v['attachment_id'] : 0,
				'url'           => isset( $img_v['url'] ) && $img_v['url'] !== '' ? $img_v['url'] : $src,
			),
			'position'   => 'center center',
			'size'       => array( 'selected' => 'cover', 'custom' => '' ),
			'repeat'     => 'no-repeat',
			'attachment' => 'scroll',
		);
		// OVERLAY / SCRIM (background-pro `overlay`) → white heading/overline/CTA stay readable over the photo.
		// Carry the SOURCE overlay when detected (section_bg_image): a gradient scrim → overlay/gradient (the
		// gradient-v2 shape), a semi-transparent colour → overlay/color. Fall back to a flat 35% black only
		// when the source hero had NO explicit overlay layer.
		$ov = trim( (string) ( $bg['overlay'] ?? '' ) );
		if ( $ov !== '' && stripos( $ov, 'gradient' ) !== false && ( $grad = self::parse_linear_gradient( $ov ) ) ) {
			$node['atts']['background']['overlay'] = array( 'color' => '', 'gradient' => $grad );
		} elseif ( $ov !== '' && preg_match( '/^rgba?\(|^#|^hsla?\(/i', $ov ) ) {
			$node['atts']['background']['overlay'] = array( 'color' => $ov, 'gradient' => array( 'type' => 'linear', 'angle' => 90, 'stops' => array() ) );
		} else {
			$node['atts']['background']['overlay'] = array( 'color' => 'rgba(0, 0, 0, 0.35)', 'gradient' => array( 'type' => 'linear', 'angle' => 90, 'stops' => array() ) );
		}
		if ( ! empty( $bg['hero'] ) ) {
			self::apply_hero_frame( $node, (string) ( $bg['valign'] ?? 'middle' ), (string) ( $bg['hero_height'] ?? '' ) );
		}
	}

	private static function n_section( $css_class, $css_id, $css, array $items, $fullwidth ) {
		// NOTE: section CSS is NOT written to `custom_css` (that routes through the dynamic-CSS
		// aggregator, defeating the clean-child-theme goal). It's emitted into style.css via
		// page_css(). The `$css` arg is kept for callers.
		return array(
			'type'   => 'section',
			'atts'   => array(
				'variant'        => '',
				'is_fullwidth'   => (bool) $fullwidth,
				'min_height'     => array( 'preset' => 'auto', 'custom' => array( 'custom_height' => array( 'value' => '', 'unit' => 'px' ) ) ),
				'content_valign' => 'top',
				// Section-level CSS text-align ('' = Inherit; 'center'/'right' faithfully carry a
				// centered source band so its heading + paragraph + buttons all read centered as
				// one, since text-align is inherited). Set by the caller from the section's own
				// centering signal (section_center). '' by default so nothing is forced.
				'text_align'     => '',
				'background'     => array(
					'color'    => array( 'value' => array( 'predefined' => '', 'custom' => '' ) ),
					'gradient' => array( 'data' => array( 'type' => 'linear', 'angle' => 90, 'stops' => array() ) ),
					'image'    => array( 'src' => array(), 'position' => 'center center', 'size' => array( 'selected' => 'cover', 'custom' => '' ), 'repeat' => 'no-repeat', 'attachment' => 'scroll' ),
					'video'    => array( 'enabled' => 'no', 'external_url' => '', 'source_mp4' => array(), 'source_webm' => array(), 'poster' => array(), 'fallback' => array(), 'loop' => 'yes', 'autoplay' => 'yes', 'mute' => 'yes', 'playsinline' => 'yes' ),
					'advanced' => array(),
				),
				'padding_top'    => '', 'padding_bottom' => '', 'gap' => '', 'gap_x' => '', 'gap_y' => '',
				'animation'      => self::def_animation(),
				'unique_id'      => self::uid(),
				'css_id'         => (string) $css_id,
				'css_class'      => (string) $css_class,
				'custom_css'     => '',
				'responsive_hide' => array(),
				'custom_attrs'   => array(),
			),
			'_items' => $items,
		);
	}
	/** A CSS gap length (px) -> the nearest UnysonPlus Gap-Scale slug (Bootstrap $spacers:
	 *  1=4px, 2=8px, 3=16px, 4=24px, 5=48px). '' when there's no meaningful gap. Mirror of the JS gapSlug(). */
	private static function gap_slug( $g ) {
		$px = (float) preg_replace( '/[^0-9.].*$/', '', trim( (string) $g ) );
		if ( $px < 2 ) { return ''; }
		$scale = array( 4 => '1', 8 => '2', 16 => '3', 24 => '4', 48 => '5' );
		$best = '1'; $bestd = PHP_INT_MAX;
		foreach ( $scale as $v => $slug ) { $d = abs( $v - $px ); if ( $d < $bestd ) { $bestd = $d; $best = $slug; } }
		return $best;
	}

	private static function n_column( $width, array $items, $css_class = '', $resp = array() ) {
		// Responsive widths land on the column's OUTER grid controls (w_phone→fw-col-{n},
		// w_tablet→fw-col-md-{n}, w_desktop→fw-col-lg-{n}). They must NOT go on css_class —
		// the column view routes css_class to an INNER wrapper div, which would create a
		// redundant nested grid div around the content.
		$pick = function ( $k ) use ( $resp ) {
			return ( isset( $resp[ $k ] ) && $resp[ $k ] !== '' ) ? (string) $resp[ $k ] : 'default';
		};
		return array(
			'type'   => 'column',
			'width'  => $width,
			'atts'   => array(
				'full_height' => 'no', 'mobile_order' => '', 'w_phone' => $pick( 'w_phone' ), 'w_tablet' => $pick( 'w_tablet' ), 'w_desktop' => $pick( 'w_desktop' ),
				'offset_phone' => 'none', 'offset_tablet' => 'none', 'offset_desktop' => 'none', 'align_self' => 'default',
				'content_v' => 'default', 'content_h' => 'default', 'text_align' => '', 'position' => '', 'z_index' => 0,
				'bg_color' => array( 'predefined' => '', 'custom' => '' ), 'border_preset' => '',
				'spacing' => self::def_spacing(), 'animation' => self::def_animation(),
				'unique_id' => self::uid(), 'css_id' => '', 'css_class' => (string) $css_class, 'inner_class' => '',
				'custom_css' => '', 'responsive_hide' => array(), 'custom_attrs' => array(),
			),
			'_items' => $items,
		);
	}
	/**
	 * Parse a source column's `col-*` classes → the builder column's base WIDTH fraction (the
	 * desktop span, so the builder displays the real width — e.g. col-lg-4 → 1/3, col-lg-7 → 7/12 —
	 * instead of "1/1"). The fraction's frontend class is `fw-col-12 fw-col-sm-N`, i.e. full on
	 * phones, the desktop width from small-tablet up. Returns null when there are no `col-*` classes
	 * (caller falls back to even division by the column count).
	 *
	 * @param string $cls
	 * @return array|null
	 */
	/**
	 * Row-level column widths for a flex row that mixes a FIXED-px column (`w-[Npx]`) with flex-1
	 * column(s): the fixed column maps to its px over a ~1200px content container (rounded to /12), and
	 * the flex-1 column(s) share the remainder. Returns [colIndex => slug] or [] when the row isn't a
	 * clean fixed-px + flex-1 mix (then the per-column path runs). (P3 audit fix: unbalanced two-column
	 * bands where a fixed visual + flex body were both mapped to 1_2.)
	 */
	private static function flex_row_widths( $cols ) {
		if ( ! is_array( $cols ) || count( $cols ) < 2 ) { return array(); }
		$ref = 1200.0; $fixed = array(); $flex = array();
		foreach ( $cols as $i => $c ) {
			$cls = is_array( $c ) ? (string) ( $c['cls'] ?? '' ) : '';
			if ( preg_match( '/(?:^|\s|:)w-\[(\d+(?:\.\d+)?)px\]/', $cls, $m ) ) { $fixed[ $i ] = (float) $m[1]; }
			elseif ( preg_match( '/(?:^|\s)(?:flex-1|flex-auto|grow)\b/', $cls ) ) { $flex[ $i ] = true; }
		}
		if ( ! $fixed || ! $flex || ( count( $fixed ) + count( $flex ) ) !== count( $cols ) ) { return array(); }
		$used = 0; $out = array();
		foreach ( $fixed as $i => $px ) { $frac = max( 1, min( 11, (int) round( ( $px / $ref ) * 12 ) ) ); $used += $frac; $out[ $i ] = $frac; }
		$rem = 12 - $used;
		if ( $rem < count( $flex ) ) { return array(); }
		$each = intdiv( $rem, count( $flex ) ); $extra = $rem - $each * count( $flex ); $first = true;
		foreach ( $flex as $i => $_u ) { $out[ $i ] = $each + ( $first ? $extra : 0 ); $first = false; }
		$slugs = array();
		foreach ( $out as $i => $n ) { $slugs[ $i ] = self::frac12( $n ); }
		return $slugs;
	}

	private static function col_layout( $cls ) {
		$lg = ''; $md = ''; $sm = ''; $xs = '';
		foreach ( preg_split( '/\s+/', (string) $cls ) as $c ) {
			if ( preg_match( '/^col-lg-(\d{1,2})$/', $c, $m ) )      { $lg = $m[1]; }
			elseif ( preg_match( '/^col-xl-(\d{1,2})$/', $c, $m ) )  { if ( $lg === '' ) { $lg = $m[1]; } }
			elseif ( preg_match( '/^col-md-(\d{1,2})$/', $c, $m ) )  { $md = $m[1]; }
			elseif ( preg_match( '/^col-sm-(\d{1,2})$/', $c, $m ) )  { $sm = $m[1]; }
			elseif ( preg_match( '/^col-(\d{1,2})$/', $c, $m ) )     { $xs = $m[1]; }
		}
		if ( $lg === '' && $md === '' && $sm === '' && $xs === '' ) { return null; }
		// Desktop span = the largest-breakpoint value present.
		$d = $lg !== '' ? $lg : ( $md !== '' ? $md : ( $sm !== '' ? $sm : $xs ) );
		return array( 'width' => self::frac12( $d ) );
	}
	/**
	 * A source wrapper/cell's OWN explicit horizontal text-alignment → 'center' | 'right' | ''
	 * (left / inherit). PHP twin of Stitch::wrapper_align — reads a Tailwind/Bootstrap
	 * `text-center` / `text-right` / `text-left` class; explicit left → '' (the inherited default,
	 * nothing to force). Used to carry a text-* wrapper's alignment onto the builder column's
	 * native `text_align` option when that wrapper decomposes into the column.
	 *
	 * @param string $cls
	 * @return string
	 */
	public static function cls_text_align( $cls ) {
		$c = ' ' . (string) $cls . ' ';
		if ( strpos( $c, ' text-center ' ) !== false ) { return 'center'; }
		if ( strpos( $c, ' text-right ' ) !== false )  { return 'right'; }
		return ''; // text-left / none = inherited default.
	}
	/** Bootstrap column span (1–12) → the page-builder width fraction (full 12-grid). */
	private static function frac12( $n ) {
		$map = array(
			1 => '1_12', 2 => '1_6', 3 => '1_4', 4 => '1_3', 5 => '5_12', 6 => '1_2',
			7 => '7_12', 8 => '2_3', 9 => '3_4', 10 => '5_6', 11 => '11_12', 12 => '1_1',
		);
		$n = (int) $n;
		return isset( $map[ $n ] ) ? $map[ $n ] : '1_1';
	}

	/**
	 * FRAMEWORK-AGNOSTIC column width from the measured desktop fraction (`wResp.desktop`, captured
	 * by measuring the rendered grid) — used when the cell has no Bootstrap `col-*` classes (Tailwind
	 * `grid-cols-3`, custom flex, …). Sets the base width to that fraction so the builder shows it.
	 *
	 * @param mixed $resp { phone, tablet, desktop }
	 * @return array|null
	 */
	private static function geom_layout( $resp ) {
		if ( ! is_array( $resp ) ) { return null; }
		$d = isset( $resp['desktop'] ) ? (int) $resp['desktop'] : 0;
		if ( $d < 1 || $d > 12 ) { return null; }
		return array( 'width' => self::frac12( $d ) );
	}
	private static function n_text( $html, $max_width = '', $align = '', $cs = '', $cls = '' ) {
		$html      = (string) $html;
		$centered  = in_array( $align, array( 'center', 'right' ), true );
		$mw        = self::max_width_att( (string) $max_width );
		$use_att   = ( $mw !== null );
		// PASS #2 NATIVE STRUCTURE PROMOTION — a centered/right paragraph's horizontal alignment maps to the
		// text_block's NATIVE, editable `text_align` option (which renders as a Bootstrap `text-*` class on the
		// block wrapper — node-scoped, never inline nor body-wide) instead of a hardcoded inline `<div
		// style="text-align">`. EXCEPTION: when the paragraph ALSO carries a source max-width (`max-w-2xl
		// mx-auto`) we keep the proven inline mx-auto wrapper — the constrained box must be CENTERED with
		// `margin:auto` (the native max_width att would left-pin it), and its inline text-align rides along —
		// so that delicate path is unchanged.
		$text_align = '';
		if ( $centered && $mw !== null && ! empty( ( $mw['custom']['custom_width'] ?? array() )['value'] ) ) {
			$m     = $mw['custom']['custom_width'];
			$style = 'text-align:' . $align . ';max-width:' . $m['value'] . ( $m['unit'] ?? 'px' ) . ';margin-left:auto;margin-right:auto;';
			$html  = '<div style="' . $style . '">' . $html . '</div>';
			$use_att = false;
		} elseif ( $centered ) {
			$text_align = $align; // pure alignment (no constrained-width box) → the native, editable option
		}
		// TEXT STYLE preset — match the block's OWN computed font-size (data-sc-cs) to the nearest BODY
		// size preset (Lead/Subtitle/Small/Caption) so the text references an editable preset CLASS instead
		// of a frozen px. Base (16) or no match within tolerance → '' (Default, the theme base).
		$font_size_preset = '';
		if ( $cs !== '' && preg_match( '/font-size:\s*([0-9.]+)px/i', (string) $cs, $fm ) ) {
			$font_size_preset = self::text_preset_for( (float) $fm[1] );
		}
		$uid  = self::uid();
		$atts = array(
			'text' => self::map_accent_classes( $html ), 'animation' => self::def_animation(),
			'font_size_preset' => $font_size_preset,
			'unique_id' => $uid, 'css_id' => '', 'css_class' => '', 'custom_css' => '', 'responsive_hide' => array(), 'custom_attrs' => array(),
		);
		// CONVERSION DEBUG (best-effort) — the paragraph's source classes (from a `<p class="…">` in the
		// html) that became NO option: size/leading/color utilities are now owned by the Text Style preset /
		// text_color / spacing options → "represented"; the rest are "dropped".
		$all_cls = (string) $cls;                                 // the block's source class (survives html cleaning)
		if ( stripos( $html, 'class=' ) !== false && preg_match_all( '/class\s*=\s*"([^"]*)"/i', $html, $cm ) ) {
			$all_cls = trim( $all_cls . ' ' . implode( ' ', $cm[1] ) );
			$first   = trim( (string) ( $cm[1][0] ?? '' ) );
			self::conv_debug_record( $uid, $first, self::conv_dropped_diff( $first, self::strip_inert_utilities( $first ) ) );
		}
		// Responsive visibility (a `md:hidden` mobile twin, `hidden md:flex` desktop-only, …) → the native
		// responsive_hide option, so a source element hidden at a breakpoint stays hidden on that device.
		$rh = self::responsive_hide_from_class( $all_cls );
		if ( $rh ) { $atts['responsive_hide'] = $rh; }
		// Native horizontal alignment (a `text-*` class on the block wrapper) — only for an explicit
		// center/right; left/start is the inherit default, so it stays unset.
		if ( $text_align !== '' ) { $atts['text_align'] = $text_align; }
		// TEXT COLOR → the block's NATIVE `text_color` option (rendered on the wrapper by the global
		// sc_build_wrapper_attr styling wiring). Parse the computed `color` from the block's own $cs and,
		// when it's a REAL non-default tone (a muted body ink like rgb(41,61,54), or any alpha'd rgba),
		// carry it as an editable custom colour — instead of freezing it in the section-scoped unified
		// styler. Plain default ink (pure black / empty / inherit) stays unset so a normal paragraph
		// keeps inheriting the theme body colour.
		if ( $cs !== '' ) {
			$col = self::cs_decls( (string) $cs, array( 'color' ) );
			$cv  = isset( $col['color'] ) ? trim( $col['color'] ) : '';
			if ( $cv !== '' && ! self::is_default_ink( $cv ) ) {
				$atts['text_color'] = array( 'predefined' => '', 'custom' => $cv );
			}
		}
		// MARGIN → the block's NATIVE `spacing` option. Seed an empty spacing box then map the source's
		// computed vertical margins (margin-top / margin-bottom, e.g. the 32px `mb-8`) onto the spacing
		// scale — so the source rhythm lands on the editable native option rather than being frozen in the
		// unified styler. Horizontal margins stay structural.
		if ( $cs !== '' ) {
			$atts['spacing'] = self::apply_native_margin( self::empty_spacing(), (string) $cs );
		}
		// Source max-width on the text (from a class, an inline style, OR a stylesheet rule — detected in
		// the engine) → the Text Block's "Custom Max Width" option, so a constrained paragraph stays
		// constrained + centered (matches the source measure).
		if ( $use_att ) { $atts['max_width'] = $mw; }
		return array( 'type' => 'simple', 'shortcode' => 'text_block', '_items' => array(), 'atts' => $atts );
	}

	/** A CSS length ("620px", "42rem") → the text_block custom-max-width att. */
	private static function max_width_att( $value ) {
		$value = trim( (string) $value );
		if ( $value === '' ) { return null; }
		if ( preg_match( '/^([0-9.]+)(px|rem|em|%|ch|vw)$/', $value, $m ) ) {
			return array( 'preset' => 'custom', 'custom' => array( 'custom_width' => array( 'value' => $m[1], 'unit' => $m[2] ) ) );
		}
		return null;
	}
	private static function n_code( $html ) {
		$html = (string) $html;
		// A verbatim block can carry an `<img src=*.svg>` (e.g. a centered illustration) — WordPress can't
		// host SVG, so inline it as sanitised <svg> here so it renders instead of 404-ing.
		$html = self::inline_svg_imgs( $html );
		// Make Tailwind responsive show/hide (hidden / lg:block / lg:hidden …) actually work in verbatim HTML,
		// so a desktop/mobile variant pair doesn't render duplicated (once-per-build CSS shim, scoped to .sc-tw).
		$html = self::maybe_rwd_shim( $html );
		// PRETEACH tables: a verbatim <table> is wrapped in the default Table Preset skin (.tbl-{slug}).
		// That preset's CSS targets descendant `> table > thead/tbody…`, so the raw source table renders
		// styled (header fill, borders, stripes) instead of bare — no per-site table-style derivation
		// needed. Only when the block is a table and isn't already inside a tbl- wrapper.
		if ( stripos( $html, '<table' ) !== false && strpos( $html, 'tbl-' ) === false ) {
			$slug = self::default_table_slug();
			if ( $slug !== '' ) { $html = '<div class="tbl-' . $slug . '">' . $html . '</div>'; }
		}
		return array( 'type' => 'simple', 'shortcode' => 'code_block', '_items' => array(), 'atts' => array(
			'code' => $html, 'animation' => self::def_animation(),
			'unique_id' => self::uid(), 'css_id' => '', 'css_class' => '', 'custom_css' => '', 'responsive_hide' => array(), 'custom_attrs' => array(),
		) );
	}

	/** Slug of the first (default) Table Preset — the skin the converter applies to verbatim tables. */
	private static function default_table_slug() {
		if ( ! function_exists( 'unysonplus_get_table_presets' ) ) { return ''; }
		$presets = unysonplus_get_table_presets();
		if ( empty( $presets ) || ! is_array( $presets ) || empty( $presets[0] ) || ! is_array( $presets[0] ) ) { return ''; }
		$id = isset( $presets[0]['id'] ) ? preg_replace( '/[^a-zA-Z0-9_-]/', '', (string) $presets[0]['id'] ) : '';
		if ( $id === '' ) { return ''; }
		$map = function_exists( 'unysonplus_table_preset_slug_map' ) ? unysonplus_table_preset_slug_map() : array();
		return isset( $map[ $id ] ) ? (string) $map[ $id ] : '';
	}

	/**
	 * A standalone <img> → the NATIVE media_image shortcode (NOT a gallery — that's for multiple
	 * images — and NOT a code_block). Pulls the src/alt out of the img markup; the importer sideloads
	 * the URL. Falls back to a code_block only when there's no resolvable src.
	 */
	private static function n_media_image( $html, $skin_css = '' ) {
		$html     = (string) $html;
		$skin_css = (string) $skin_css;
		$src      = '';
		$alt      = '';
		if ( preg_match( '/<img\b[^>]*\bsrc\s*=\s*["\']([^"\']+)["\']/i', $html, $m ) ) { $src = trim( $m[1] ); }
		if ( preg_match( '/<img\b[^>]*\balt\s*=\s*["\']([^"\']*)["\']/i', $html, $m ) ) { $alt = trim( $m[1] ); }
		if ( $src === '' ) { return self::n_code( $html ); }

		// A standalone SVG illustration → INLINE it (the media library can't host SVG, so a media_image URL
		// 404s). Emit the sanitised markup verbatim as a responsive code block instead of an <img>.
		if ( preg_match( '/\.svg(?:$|\?)/i', $src ) ) {
			$svg = self::fetch_svg_cached( $src );
			if ( $svg !== '' ) {
				$svg = preg_replace( '/<svg\b/i', '<svg style="max-width:100%;height:auto"', $svg, 1 );
				return self::n_code( '<div class="sc-illustration" style="max-width:100%;">' . $svg . '</div>' );
			}
			// Couldn't inline → hotlink the ABSOLUTE source SVG (200) instead of a relative /assets path (404).
			$src = self::abs_asset( $src );
		}

		// NOTHING DROPPED: the native media_image can carry a src/alt/size but NOT a visual SKIN
		// (border colour + width, shadow, ring, outline, or an organic/rounded radius / blob shape).
		// If the source <img> (or a wrapping element) carries such a skin — as a class OR an inline
		// style — preserve the source VERBATIM as a code block so every class and its CSS survives,
		// instead of emitting a skin-less native image. The src is localized first so it still loads.
		// EXCEPTION: when the caller has already translated the skin into scoped `$skin_css` (the
		// image-composite decomposition passes a CLEAN <img> + a `selector img{…}` rule), emit the
		// NATIVE media_image and carry the skin via Custom CSS — so an organic/bordered/shadowed hero
		// photo becomes an editable element instead of a verbatim code_block.
		if ( '' === $skin_css ) {
			$img_cls = '';
			if ( preg_match( '/<img\b[^>]*\bclass\s*=\s*["\']([^"\']*)["\']/i', $html, $cm ) ) { $img_cls = ' ' . $cm[1] . ' '; }
			$skin_class = (bool) preg_match( '/\s(border(?![-\s]?(box|collapse))|shadow|drop-shadow|ring\b|ring-|rounded-(?!none)|rounded\b|outline\b|outline-|blob)/i', $img_cls );
			$skin_style = (bool) preg_match( '/<img\b[^>]*\bstyle\s*=\s*["\'][^"\']*(border|box-shadow|outline|border-radius|clip-path)/i', $html );
			$wrapped    = (bool) preg_match( '/<(div|figure|span|a|picture)\b[^>]*>\s*<img/i', $html );
			if ( $skin_class || $skin_style || $wrapped ) {
				$iv    = self::upload_val( $src );
				$local = ( '' !== $iv['url'] ) ? $iv['url'] : $src;
				if ( $local !== $src ) { $html = str_replace( $src, $local, $html ); }
				return self::n_code( $html );
			}
		}

		$iv = self::upload_val( $src ); // sideloaded copy if the filename matches an "Attach media" upload
		return array( 'type' => 'simple', 'shortcode' => 'media_image', '_items' => array(), 'atts' => array(
			'image'         => array( 'attachment_id' => $iv['attachment_id'], 'url' => $iv['url'], 'alt' => $alt ),
			'width'         => array( 'value' => '', 'unit' => 'px' ),
			'height'        => array( 'value' => '', 'unit' => 'px' ),
			'fetchpriority' => 'auto',
			'link'          => '',
			'target'        => '_self',
			'bg_color'      => self::empty_color(),
			'spacing'       => self::def_spacing(),
			'animation'     => self::def_animation(),
			'unique_id'     => self::uid(), 'css_id' => '', 'css_class' => '', 'custom_css' => $skin_css, 'responsive_hide' => array(), 'custom_attrs' => array(),
		) );
	}

	/**
	 * A source `<video>` / provider `<iframe>` block → the NATIVE `media_video` shortcode
	 * (self-hosted file or oEmbed URL) — NOT a raw `<video>` in a text_block and NOT a code_block.
	 * A muted/looping/autoplaying background clip is only reproducible with the self-hosted branch,
	 * so we carry those playback flags through. Emits the full `source_type` multi-picker shape
	 * (both branches populated) so the builder corrector accepts the node unchanged.
	 *
	 * @param array $b { mode:'self_hosted'|'embed', src, webm, poster, embedUrl, autoplay, muted, loop, controls, playsinline }
	 */
	private static function n_video( array $b ) {
		$mode   = ( isset( $b['mode'] ) && $b['mode'] === 'embed' ) ? 'embed' : 'self_hosted';
		$src    = isset( $b['src'] ) ? trim( (string) $b['src'] ) : '';
		$webm   = isset( $b['webm'] ) ? trim( (string) $b['webm'] ) : '';
		$poster = isset( $b['poster'] ) ? trim( (string) $b['poster'] ) : '';
		$embed  = isset( $b['embedUrl'] ) ? self::embed_to_page_url( (string) $b['embedUrl'] ) : '';

		// A self-hosted branch with no file at all is really an embed we couldn't classify → flip it.
		if ( $mode === 'self_hosted' && $src === '' && $webm === '' ) {
			$mode = 'embed';
			if ( $embed === '' && $src !== '' ) { $embed = $src; }
		}

		// Uses the sideloaded "Attach media" copy when the source URL's filename matches an upload.
		$up = function ( $url ) { return self::upload_val( $url ); };

		$source_type = array(
			'source' => $mode,
			'embed'  => array(
				'url'              => $embed,
				'youtube_nocookie' => 'no',
				'lazy_facade'      => 'no',
				'poster'           => $up( $mode === 'embed' ? $poster : '' ),
			),
			'self_hosted' => array(
				'video_file'  => $up( $src ),
				'video_webm'  => $up( $webm ),
				'video_url'   => '',
				'poster'      => $up( $mode === 'self_hosted' ? $poster : '' ),
				'autoplay'    => isset( $b['autoplay'] ) ? (string) $b['autoplay'] : 'no',
				'muted'       => isset( $b['muted'] ) ? (string) $b['muted'] : 'no',
				'loop'        => isset( $b['loop'] ) ? (string) $b['loop'] : 'no',
				'controls'    => isset( $b['controls'] ) ? (string) $b['controls'] : 'yes',
				'playsinline' => isset( $b['playsinline'] ) ? (string) $b['playsinline'] : 'yes',
				'preload'     => 'metadata',
				'object_fit'  => 'contain',
			),
		);
		// Autoplay implies muted (browser policy) — mirror view.php so the atts stay coherent.
		if ( $source_type['self_hosted']['autoplay'] === 'yes' ) { $source_type['self_hosted']['muted'] = 'yes'; }

		return array( 'type' => 'simple', 'shortcode' => 'media_video', '_items' => array(), 'atts' => array(
			'source_type' => $source_type,
			'width'       => array( 'value' => 600, 'unit' => 'px' ),
			'ratio'       => '16x9',
			'bg_color'    => self::empty_color(),
			'spacing'     => self::def_spacing(),
			'animation'   => self::def_animation(),
			'unique_id'   => self::uid(), 'css_id' => '', 'css_class' => '', 'custom_css' => '', 'responsive_hide' => array(), 'custom_attrs' => array(),
		) );
	}

	/** Normalize a provider embed iframe src → an oEmbed-friendly PAGE url (WP oEmbed needs the page
	 *  URL, not the `/embed/` iframe src). Unknown hosts pass through unchanged. */
	private static function embed_to_page_url( $src ) {
		$src = trim( (string) $src );
		if ( $src === '' ) { return ''; }
		if ( preg_match( '#youtube(?:-nocookie)?\.com/embed/([\w-]+)#', $src, $m ) ) { return 'https://www.youtube.com/watch?v=' . $m[1]; }
		if ( preg_match( '#player\.vimeo\.com/video/(\d+)#', $src, $m ) )            { return 'https://vimeo.com/' . $m[1]; }
		if ( preg_match( '#dailymotion\.com/embed/video/([\w]+)#', $src, $m ) )      { return 'https://www.dailymotion.com/video/' . $m[1]; }
		return $src;
	}

	/** Build the badge shortcode node (role token now 'badge') from a recognized hero pill block (sub-tag + message +
	 *  optional trailing icon + optional link). Full att shape so the editor opens it cleanly; colours stay
	 *  neutral (user themes them). The trailing icon reuses icon_value() like buttons. */
	private static function n_badge( array $b ) {
		$none_icon  = array( 'type' => 'none', 'icon-class' => '', 'icon-class-without-root' => false, 'pack-name' => false, 'pack-css-uri' => false );
		$none_color = array( 'predefined' => '', 'custom' => '' );
		$trail = trim( (string) ( $b['icon'] ?? '' ) );
		$align = ( ( $b['align'] ?? '' ) === 'center' ) ? 'center' : ( in_array( ( $b['align'] ?? '' ), array( 'start', 'end' ), true ) ? $b['align'] : 'start' );
		$atts = array(
			'tag_text'      => (string) ( $b['tag_text'] ?? '' ),
			'message'       => (string) ( $b['message'] ?? '' ),
			'link'          => (string) ( $b['link'] ?? '' ),
			'leading'       => 'none',
			'leading_icon'  => $none_icon,
			'trailing_icon' => $trail !== '' ? self::icon_value( $trail ) : $none_icon,
			'style'         => 'soft',
			'shape'         => 'pill',
			'size'          => 'md',
			'align'         => $align,
			'tag_style'     => 'filled',
			'hover'         => 'lift',
			'pill_color'    => $none_color,
			'text_color'    => $none_color,
			'tag_color'     => $none_color,
			'gradient_from' => $none_color,
			'gradient_to'   => $none_color,
			'spacing'       => self::def_spacing(),
			'link_target'   => 'auto',
			'rel_nofollow'  => 'no',
			'rel_sponsored' => 'no',
			'rel_ugc'       => 'no',
			'aria_label'    => '',
			'title_attr'    => '',
			'dismissible'   => 'no',
			'dismiss_id'    => '',
			'schema_enable' => 'no',
			'schema_name'   => '',
			'schema_date'   => '',
			'animation'     => self::def_animation(),
			'unique_id'     => self::uid(),
			'css_id'        => '', 'css_class' => '', 'custom_css' => '', 'responsive_hide' => array(), 'custom_attrs' => array(),
		);
		// Leading icon: a source inline <svg> BEFORE the text (e.g. a lucide heart) → the native leading_icon,
		// carried verbatim (icon-v2 renders inline SVG via currentColor) — the SAME mechanism the heading
		// overline_icon uses. Only set when a real leading svg was captured; a plain pill stays icon-less.
		$lead_svg = trim( (string) ( $b['leadingSvg'] ?? '' ) );
		if ( '' !== $lead_svg ) {
			$atts['leading']      = 'icon';
			$atts['leading_icon'] = array( 'type' => 'svg', 'svg-source' => 'inline', 'markup' => $lead_svg );
		}
		// Reproduce the source pill's colors so it's VISIBLE — an empty pill renders as a near-invisible soft
		// tint on a light hero. Resolve the container's fill/border, the message text color and the tag fill
		// from their Tailwind classes (config-aware), and pick the bordered `outline` style when the source
		// pill has a border (its most distinctive, visible feature).
		if ( self::$style_on && class_exists( 'FW_Site_Converter_Tailwind' ) ) {
			$clean = function ( $c ) { return trim( preg_replace( '/\s*\/\s*var\([^)]*\)/', '', (string) $c ) ); };
			$col   = function ( $cls, $prop ) use ( $clean ) {
				if ( '' === (string) $cls ) { return ''; }
				$cm = FW_Site_Converter_Tailwind::compile_class_set( (string) $cls, self::$style_cfg );
				return ! empty( $cm['base'][ $prop ] ) ? $clean( $cm['base'][ $prop ] ) : '';
			};
			$border = $col( isset( $b['pillCls'] ) ? $b['pillCls'] : '', 'border-color' );
			$fill   = $col( isset( $b['pillCls'] ) ? $b['pillCls'] : '', 'background-color' );
			// PREFER the container's RESOLVED computed style (data-sc-cs) — a semantic `bg-primary/10` tint
			// (rgba(33,196,93,.1)) is a CSS-var token the Tailwind compiler can't resolve to a colour, but the
			// captured computed style carries the real rgba fill. Only take a NON-default fill (a real tint,
			// not transparent / near-transparent) so a plain pill with no fill stays unstyled.
			$pill_cs = (string) ( $b['pillCs'] ?? '' );
			if ( '' !== $pill_cs ) {
				$cd = self::cs_decls( $pill_cs, array( 'background-color', 'border', 'border-top-color' ) );
				$cbg = self::pill_fill_color( isset( $cd['background-color'] ) ? $cd['background-color'] : '' );
				if ( '' !== $cbg ) { $fill = $cbg; }
			}
			if ( '' !== $border )   { $atts['style'] = 'outline'; $atts['pill_color'] = array( 'predefined' => '', 'custom' => $border ); }
			elseif ( '' !== $fill ) { $atts['style'] = 'subtle';  $atts['pill_color'] = array( 'predefined' => '', 'custom' => $fill ); }
			$txt = $col( isset( $b['msgCls'] ) ? $b['msgCls'] : '', 'color' );
			if ( '' !== $txt )   { $atts['text_color'] = array( 'predefined' => '', 'custom' => $txt ); }
			// Only override the tag color when it resolves to a REAL color — a pure-black result means the
			// source token (e.g. bg-tertiary) isn't in the parsed config, and black would replace the pill's
			// pleasant default tag color with an ugly black chip.
			$tagbg = $col( isset( $b['tagCls'] ) ? $b['tagCls'] : '', 'background-color' );
			if ( '' !== $tagbg && 'rgb(0 0 0)' !== $tagbg ) { $atts['tag_color'] = array( 'predefined' => '', 'custom' => $tagbg ); }
		}
		return array( 'type' => 'simple', 'shortcode' => 'badge', 'atts' => $atts, '_items' => array() );
	}
	/**
	 * A Container layout band — renders its own `.fw-container` / `.fw-container-fluid` as a
	 * SIBLING after the section's default container (the items-corrector lifts it out). Used for
	 * source `.container-fluid` bands (e.g. a full-bleed portfolio gallery) so they aren't
	 * constrained to the section's boxed width. The given items (columns / simple leaves) are
	 * grouped into rows by the corrector, exactly like a section's own content.
	 */
	private static function n_container( array $items, $fluid = true ) {
		return array(
			'type'   => 'container',
			'atts'   => array(
				'unique_id'    => self::uid(),
				'is_fullwidth' => $fluid ? true : false,
			),
			'_items' => $items,
		);
	}
	/** typography-v2 value shape for a counter font part (weight + px size only; rest defaulted). */
	private static function counter_font( $weight, $size ) {
		return array(
			'google_font' => false, 'subset' => false, 'variation' => false,
			'family' => '', 'style' => 'normal',
			'weight' => $weight !== '' ? (string) $weight : '700',
			'size'   => $size   !== '' ? (string) $size   : '44',
			'line-height' => '', 'letter-spacing' => '0', 'color' => false,
		);
	}
	/** A compact color value: a near-white source color → the `text-white` preset, else custom hex. */
	private static function counter_color( $hex ) {
		$hex = strtolower( trim( (string) $hex ) );
		if ( in_array( $hex, array( '#ffffff', '#fff' ), true ) ) {
			return array( 'predefined' => 'text-white', 'custom' => '' );
		}
		return array( 'predefined' => '', 'custom' => $hex );
	}
	/** An animated `counter` shortcode node (full att shape per the page-builder's counter export). */
	private static function n_counter( array $c ) {
		return array( 'type' => 'simple', 'shortcode' => 'counter', '_items' => array(), 'atts' => array(
			'number'    => (string) ( $c['number'] ?? '100' ),
			'start'     => (string) ( $c['start'] ?? '0' ),
			'prefix'    => (string) ( $c['prefix'] ?? '' ),
			'suffix'    => (string) ( $c['suffix'] ?? '' ),
			'decimals'  => (string) ( $c['decimals'] ?? '0' ),
			'separator' => 'yes',
			'duration'  => '2000',
			'easing'    => 'ease-out',
			'alignment' => '',
			'number_font'  => self::counter_font( $c['numberWeight'] ?? '700', $c['numberSize'] ?? '44' ),
			'number_color' => self::counter_color( $c['numberColor'] ?? '' ),
			'prefix_font'  => self::counter_font( $c['numberWeight'] ?? '700', '24' ),
			'prefix_color' => array( 'predefined' => '', 'custom' => '' ),
			'suffix_font'  => self::counter_font( $c['suffixWeight'] ?? '700', $c['suffixSize'] ?? '44' ),
			'suffix_color' => self::counter_color( $c['suffixColor'] ?? '' ),
			'animation'    => self::def_animation(),
			'unique_id' => self::uid(), 'css_id' => '', 'css_class' => '', 'custom_css' => '', 'responsive_hide' => array(), 'custom_attrs' => array(),
		) );
	}
	/**
	 * A native `avatar` GROUP node from a captured reviewer stack (parity with the JS to-pages
	 * avatarGroupNode + the demo's stored shape). Starts from the shortcode's real defaults (full option
	 * tree the builder editor expects), then overlays the group mode: the detected images as `people`, a
	 * `+N` extra counter, and the bordered / circle / 40px / 35%-overlap look. Label is off (the source's
	 * stars + "4.9/5 …" text rides alongside as its own code block).
	 *
	 * @param array $b { avatars: string[], extra_count?: string }
	 * @return array
	 */
	private static function n_avatar( array $b ) {
		$urls = ( isset( $b['avatars'] ) && is_array( $b['avatars'] ) ) ? array_values( array_filter( array_map( 'strval', $b['avatars'] ) ) ) : array();
		$urls = array_slice( $urls, 0, 8 );
		$people = array();
		foreach ( $urls as $i => $u ) {
			$people[] = array( 'image' => array( 'attachment_id' => '', 'url' => (string) $u ), 'name' => 'Happy customer ' . ( $i + 1 ), 'initials' => '', 'link' => '', 'status' => '' );
		}
		$extra = trim( (string) ( $b['extra_count'] ?? '' ) );
		$atts  = self::shortcode_default_atts( 'avatar' );
		if ( ! is_array( $atts ) ) { $atts = array(); }
		$atts['mode_settings'] = array(
			'mode'  => 'group',
			'group' => array(
				'people'      => $people,
				'max_visible' => (string) max( 4, count( $people ) ),
				'extra_count' => $extra,
				'overlap'     => 35,
				'stack_order' => 'first-on-top',
			),
		);
		$atts['design']     = 'bordered';
		$atts['shape']      = 'circle';
		$atts['size']       = 40;
		$atts['show_label'] = 'no';
		$atts['unique_id']  = self::uid();
		if ( ! isset( $atts['css_id'] ) ) { $atts['css_id'] = ''; }
		if ( ! isset( $atts['css_class'] ) ) { $atts['css_class'] = ''; }
		return array( 'type' => 'simple', 'shortcode' => 'avatar', '_items' => array(), 'atts' => $atts );
	}
	/** An empty compact-color value `{ predefined:'', custom:'' }`. */
	private static function empty_color() {
		return array( 'predefined' => '', 'custom' => '' );
	}
	/** The testimonials `design_settings` default tree (design: 'default' + every design's defaults). */
	private static function testimonials_design_default() {
		$carousel = array( 'carousel_autoplay' => 'yes', 'carousel_controls' => 'yes', 'carousel_indicator_style' => 'dots', 'carousel_indicators' => 'yes', 'carousel_interval' => '5000', 'carousel_pause_hover' => 'yes', 'carousel_wrap' => 'yes' );
		return array(
			'design'    => 'default',
			'default'   => array_merge( array(
				'layout_type'     => array( 'layout_choice' => 'carousel', 'grid' => array( 'grid_columns' => 'row-cols-3', 'gutter' => '' ) ),
				'items_per_slide' => '1',
				'card_style'      => '',
				'avatar_position' => 'top',
			), $carousel ),
			'marquee'   => array( 'marquee_direction' => 'left', 'marquee_speed' => 'normal' ),
			'masonry'   => array( 'masonry_columns' => '3' ),
			'bubble'    => array( 'bubble_columns' => '3' ),
			'split'     => $carousel,
			'spotlight' => $carousel,
			'thumbnav'  => array( 'carousel_autoplay' => 'yes', 'carousel_controls' => 'yes', 'carousel_interval' => '5000', 'carousel_pause_hover' => 'yes', 'carousel_wrap' => 'yes' ),
			'pullquote' => array( 'carousel_autoplay' => 'yes', 'carousel_controls' => 'yes', 'carousel_indicators' => 'yes', 'carousel_interval' => '5000', 'carousel_pause_hover' => 'yes', 'carousel_wrap' => 'yes' ),
			'zigzag'    => array( 'zigzag_start' => 'left' ),
		);
	}
	/**
	 * A `testimonials` shortcode node — CONTENT mapped to the Classic ('default') design (the
	 * source design is intentionally NOT preserved). Each captured block → one item; a missing
	 * rating defaults to 5 (the shortcode default). The avatar carries the source URL only — the
	 * media phase localizes it to the imported attachment (the view renders from `url`).
	 */
	private static function n_testimonials( array $rows, $design = null ) {
		$items = array();
		foreach ( $rows as $r ) {
			$has_rating = isset( $r['rating'] ) && $r['rating'] !== null && $r['rating'] !== '';
			$items[] = array(
				'content'       => (string) ( $r['quote'] ?? '' ),
				'author_avatar' => array( 'attachment_id' => '', 'url' => (string) ( $r['image'] ?? '' ) ),
				'author_name'   => (string) ( $r['name'] ?? '' ),
				'author_job'    => (string) ( $r['position'] ?? '' ),
				'site_name'     => (string) ( $r['siteName'] ?? '' ),
				'site_url'      => (string) ( $r['siteUrl'] ?? '' ),
				'rating'        => $has_rating ? (float) $r['rating'] : 5,
			);
		}
		// Carry the source's UPPERCASE letter-spaced author kicker onto the rendered name/role — the native
		// view has no per-part transform option, so it rides as scoped custom CSS (`.testimonial-author` = the
		// name, `.testimonial-job` = the role). Only when the source attribution actually used it.
		$custom_css = '';
		$au = false; $als = '';
		foreach ( $rows as $r ) { if ( ! empty( $r['author_upper'] ) ) { $au = true; if ( ! empty( $r['author_ls'] ) ) { $als = (string) $r['author_ls']; } break; } }
		if ( $au ) {
			$decl = 'text-transform:uppercase;' . ( $als !== '' ? 'letter-spacing:' . $als . ';' : '' );
			$custom_css = 'selector .testimonial-author,selector .testimonial-job{' . $decl . '}';
		}
		// DESIGN — mirror the source testimonial's presentation (Classic single/grid/carousel, Marquee, …)
		// when the converter classified it (detect_testimonial_design); else the Classic default. Only the
		// design + its layout sub-choice are overridden — every design's other defaults stay intact.
		$design_settings = self::testimonials_design_default();
		if ( is_array( $design ) && ! empty( $design['design'] ) ) {
			$d = (string) $design['design'];
			$design_settings['design'] = $d;
			if ( 'default' === $d && ! empty( $design['layout_choice'] ) && isset( $design_settings['default']['layout_type'] ) ) {
				$design_settings['default']['layout_type']['layout_choice'] = (string) $design['layout_choice'];
				if ( 'grid' === $design['layout_choice'] && ! empty( $design['grid_columns'] ) && isset( $design_settings['default']['layout_type']['grid'] ) ) {
					$design_settings['default']['layout_type']['grid']['grid_columns'] = (string) $design['grid_columns'];
				}
			}
			// Design-specific sub-options (e.g. masonry_columns / bubble_columns / zigzag_start) — merge onto
			// that design's default sub-tree so its other defaults survive.
			if ( ! empty( $design['sub'] ) && is_array( $design['sub'] ) && isset( $design_settings[ $d ] ) && is_array( $design_settings[ $d ] ) ) {
				$design_settings[ $d ] = array_merge( $design_settings[ $d ], $design['sub'] );
			}
		}
		return array( 'type' => 'simple', 'shortcode' => 'testimonials', '_items' => array(), 'atts' => array(
			'title'           => '',
			'testimonials'    => $items,
			'design_settings' => $design_settings,
			'container_type'  => 'container',
			'text_align'      => 'text-center',
			'avatar_shape'    => 'rounded-circle',
			'avatar_size'     => 'avatar-lg',
			'show_rating'     => 'yes',
			'text_color'      => self::empty_color(), 'bg_color' => self::empty_color(), 'font_size_preset' => '',
			'title_color'     => self::empty_color(), 'quote_color' => self::empty_color(),
			'author_name_color' => self::empty_color(), 'author_job_color' => self::empty_color(), 'site_link_color' => self::empty_color(),
			'spacing'         => self::def_spacing(),
			'animation'       => self::def_animation(),
			'unique_id' => self::uid(), 'css_id' => '', 'css_class' => '', 'custom_css' => $custom_css, 'responsive_hide' => array(), 'custom_attrs' => array(),
		) );
	}

	/**
	 * A native `table` shortcode node from a captured `<table>` (recognizer block `{ rows:[[{html,header}…]…] }`).
	 * Builds the option-type's canonical `{ header_options, cols, rows, content }` value: one `cols` entry per
	 * column (width = max cells in any row), leading all-`<th>` rows counted as `header_rows` (→ real `<thead>`),
	 * each cell → `{ textarea, colspan:1, rowspan:1, merged:false }`. Tabular render mode. See table.md.
	 */
	private static function n_table( array $b ) {
		$rows = ( isset( $b['rows'] ) && is_array( $b['rows'] ) ) ? array_values( $b['rows'] ) : array();
		$ncol = 0;
		foreach ( $rows as $r ) { if ( is_array( $r ) ) { $ncol = max( $ncol, count( $r ) ); } }
		if ( $ncol < 1 || ! $rows ) { return self::n_code( '' ); }

		$cols = array();
		for ( $c = 0; $c < $ncol; $c++ ) { $cols[] = array( 'name' => 'default-col', 'align' => '', 'width' => '' ); }

		// Count leading rows whose every cell is a header → the <thead> block.
		$header_rows = 0; $seen_body = false;
		foreach ( $rows as $r ) {
			$all_th = is_array( $r ) && count( $r ) > 0;
			foreach ( (array) $r as $cell ) { if ( empty( $cell['header'] ) ) { $all_th = false; break; } }
			if ( $all_th && ! $seen_body ) { $header_rows++; } else { $seen_body = true; }
		}

		$content = array(); $rowmeta = array();
		foreach ( $rows as $ri => $r ) {
			$r    = is_array( $r ) ? array_values( $r ) : array();
			$line = array();
			for ( $c = 0; $c < $ncol; $c++ ) {
				$cell = isset( $r[ $c ] ) && is_array( $r[ $c ] ) ? $r[ $c ] : array();
				$line[ $c ] = array( 'textarea' => (string) ( $cell['html'] ?? '' ), 'colspan' => 1, 'rowspan' => 1, 'merged' => false );
			}
			$content[] = $line;
			$rowmeta[] = array( 'name' => ( $ri < $header_rows ) ? 'heading-row' : 'default-row' );
		}

		$atts = self::shortcode_default_atts( 'table' );
		if ( ! is_array( $atts ) ) { $atts = array(); }
		$atts['table'] = array(
			'header_options' => array( 'table_purpose' => 'tabular', 'header_rows' => $header_rows, 'footer_rows' => 0 ),
			'cols'           => $cols,
			'rows'           => $rowmeta,
			'content'        => $content,
		);
		if ( isset( $b['caption'] ) && '' !== trim( (string) $b['caption'] ) ) { $atts['caption'] = trim( (string) $b['caption'] ); }
		// Table Preset: pick the best-matching reusable skin from Theme Settings → Components → Tables using
		// the captured styling (filled header → a bordered/tinted-header preset; zebra rows → a striped preset;
		// else the first/default). The native table then renders with a real skin instead of a bare fallback.
		$slug = self::table_preset_for( isset( $b['style'] ) && is_array( $b['style'] ) ? $b['style'] : array() );
		if ( '' !== $slug ) { $atts['table_preset'] = $slug; }
		$atts['unique_id'] = self::uid();
		if ( ! isset( $atts['css_id'] ) ) { $atts['css_id'] = ''; }
		if ( ! isset( $atts['css_class'] ) ) { $atts['css_class'] = ''; }
		return array( 'type' => 'simple', 'shortcode' => 'table', '_items' => array(), 'atts' => $atts );
	}

	/** The slug of the Table Preset whose name best matches captured table styling, else the default. */
	private static function table_preset_for( array $style ) {
		if ( ! function_exists( 'unysonplus_get_table_presets' ) ) { return ''; }
		$presets = unysonplus_get_table_presets();
		$map     = function_exists( 'unysonplus_table_preset_slug_map' ) ? unysonplus_table_preset_slug_map() : array();
		if ( empty( $presets ) || ! is_array( $presets ) || empty( $map ) ) { return self::default_table_slug(); }

		// Rank preferences by evidence: header fill → "border"/"grid"/"filled"; striped rows → "strip"/"zebra".
		$prefs = array();
		$hdr_cs = (string) ( $style['header_cs'] ?? '' );
		$has_header_fill = false;
		if ( '' !== $hdr_cs ) {
			$d  = self::cs_decls( $hdr_cs, array( 'background-color' ) );
			$bg = isset( $d['background-color'] ) ? trim( $d['background-color'] ) : '';
			$has_header_fill = ( '' !== $bg && 'transparent' !== $bg && ! preg_match( '/,\s*0\s*\)\s*$/', $bg ) );
		}
		if ( ! empty( $style['striped'] ) ) { $prefs[] = '/\b(strip|zebra|row)\b/'; }
		if ( $has_header_fill )              { $prefs[] = '/\b(border|grid|filled|dark|modern)\b/'; }

		foreach ( $prefs as $rx ) {
			foreach ( $presets as $tp ) {
				if ( ! is_array( $tp ) || empty( $tp['id'] ) || empty( $tp['preset_name'] ) ) { continue; }
				$id = preg_replace( '/[^a-zA-Z0-9_-]/', '', (string) $tp['id'] );
				if ( isset( $map[ $id ] ) && preg_match( $rx, strtolower( (string) $tp['preset_name'] ) ) ) { return (string) $map[ $id ]; }
			}
		}
		return self::default_table_slug();
	}

	/**
	 * A native `accordion` shortcode node from a captured toggle group (recognizer block `{ items:[{title,content}…] }`).
	 * Each item → one `tabs` row `{ tab_title, tab_content, is_open:'no' }`. Starts from the shortcode defaults so
	 * the editor gets the full option tree. See accordion.md.
	 */
	private static function n_accordion( array $b ) {
		$src   = ( isset( $b['items'] ) && is_array( $b['items'] ) ) ? $b['items'] : array();
		$tabs  = array();
		foreach ( $src as $it ) {
			$title = trim( (string) ( $it['title'] ?? '' ) );
			if ( '' === $title ) { continue; }
			$tabs[] = array( 'tab_title' => $title, 'tab_content' => (string) ( $it['content'] ?? '' ), 'is_open' => 'no' );
		}
		if ( ! $tabs ) { return self::n_code( '' ); }
		$atts = self::shortcode_default_atts( 'accordion' );
		if ( ! is_array( $atts ) ) { $atts = array(); }
		$atts['tabs']      = $tabs;
		$atts['unique_id'] = self::uid();
		if ( ! isset( $atts['css_id'] ) ) { $atts['css_id'] = ''; }
		if ( ! isset( $atts['css_class'] ) ) { $atts['css_class'] = ''; }
		return array( 'type' => 'simple', 'shortcode' => 'accordion', '_items' => array(), 'atts' => $atts );
	}

	/**
	 * A native `feature_list` shortcode node from a captured `<ul>`/`<ol>` (recognizer block `{ ordered, items:[{text}…] }`).
	 * Each `<li>` → one item (icon-v2 `none` fallback marker); `<ol>` → `design:'numbered'`, `<ul>` → `design:'check'`.
	 * See feature-list.md.
	 */
	private static function n_feature_list( array $b ) {
		$src = ( isset( $b['items'] ) && is_array( $b['items'] ) ) ? $b['items'] : array();
		$icon_none = array( 'type' => 'none', 'icon-class' => '', 'icon-class-without-root' => false, 'pack-name' => false, 'pack-css-uri' => false );
		$items = array();
		foreach ( $src as $r ) {
			$text = trim( (string) ( $r['text'] ?? '' ) );
			if ( '' === $text ) { continue; }
			$items[] = array(
				'text' => $text, 'subtext' => '', 'value_text' => '',
				'icon' => $icon_none, 'marker_color' => array( 'predefined' => '', 'custom' => '' ),
				'state' => 'on', 'link_url' => '', 'link_target' => '_self',
			);
		}
		if ( count( $items ) < 1 ) { return self::n_code( '' ); }
		$atts = self::shortcode_default_atts( 'feature_list' );
		if ( ! is_array( $atts ) ) { $atts = array(); }
		$atts['items']     = $items;
		$atts['design']    = ! empty( $b['ordered'] ) ? 'numbered' : 'check';
		$atts['unique_id'] = self::uid();
		if ( ! isset( $atts['css_id'] ) ) { $atts['css_id'] = ''; }
		if ( ! isset( $atts['css_class'] ) ) { $atts['css_class'] = ''; }
		return array( 'type' => 'simple', 'shortcode' => 'feature_list', '_items' => array(), 'atts' => $atts );
	}

	/**
	 * A native `logo_grid` shortcode node from a captured logo / "trusted by" strip (recognizer block
	 * `{ logos:[{url,name,link_url,link_target,svg}] }`). Each mark → one editable logo (image URL + alt
	 * name + enclosing link); the media phase localizes the URLs to imported attachments. Grid layout,
	 * grayscale→colour-on-hover default. See logo-grid options.php.
	 */
	/**
	 * A native `instagram` shortcode node from a detected Instagram FEED (recognizer block
	 * `{ username, count, columns }`). Instagram is a download-on-demand LIBRARY shortcode, so its atts are
	 * built explicitly here (its defaults can't be read — it isn't installed at convert time) and the slug
	 * is recorded on self::$required_shortcodes so the importer installs it from the Library. The access
	 * token can't be captured from a source, so it's left empty — the shortcode renders its placeholder
	 * until the site owner adds their own token. See the instagram Library shortcode's options.php.
	 */
	private static function n_instagram( array $b ) {
		self::$required_shortcodes['instagram'] = true;
		$user  = ltrim( trim( (string) ( $b['username'] ?? '' ) ), '@' );
		$count = max( 1, min( 18, (int) ( $b['count'] ?? 6 ) ) );
		$cols  = max( 1, min( 6, (int) ( $b['columns'] ?? 3 ) ) );
		return array(
			'type'      => 'simple',
			'shortcode' => 'instagram',
			'_items'    => array(),
			'atts'      => array(
				'unique_id'    => self::uid(),
				'css_id'       => '',
				'css_class'    => '',
				'username'     => $user,
				'access_token' => '',
				'count'        => (string) $count,
				'columns'      => (string) $cols,
				'gap'          => '',
				'max_width'    => array( 'value' => '', 'unit' => 'px' ),
				'align'        => 'center',
				'aspect'       => array( 'ratio' => '1-1' ),
				'rounding'     => 'small',
				'hover_zoom'   => 'yes',
				'show_caption' => 'no',
				'link_to_post' => 'yes',
				'new_tab'      => 'yes',
			),
		);
	}

	private static function n_logo_grid( array $b ) {
		$src   = ( isset( $b['logos'] ) && is_array( $b['logos'] ) ) ? $b['logos'] : array();
		$logos = array();
		foreach ( $src as $l ) {
			$url = trim( (string) ( $l['url'] ?? '' ) );
			$svg = trim( (string) ( $l['svg'] ?? '' ) );
			if ( '' === $url && '' === $svg ) { continue; }
			$logos[] = array(
				'image'       => array( 'attachment_id' => '', 'url' => $url ),
				'svg'         => $svg,
				'name'        => (string) ( $l['name'] ?? '' ),
				'no_label'    => 'no',
				'link_url'    => (string) ( $l['link_url'] ?? '' ),
				'link_target' => in_array( ( $l['link_target'] ?? '' ), array( '_blank', '_self' ), true ) ? $l['link_target'] : '_blank',
			);
		}
		if ( count( $logos ) < 1 ) { return self::n_code( (string) ( $b['html'] ?? '' ) ); }
		return self::finalize_widget( 'logo_grid', array(
			'logos'       => $logos,
			'design'      => 'grid',
			'columns'     => (string) min( 6, max( 2, count( $logos ) ) ),
			'grayscale'   => 'yes',
			'show_labels' => 'no',
		) );
	}

	/**
	 * A native `gallery` shortcode node from a captured image-tile grid (recognizer block
	 * `{ images:[{url,alt,span}] }`). Each image sideloads to a real attachment (upload_val) so the media
	 * picker shows it; the source col-spans become the grid's per-column ratio (a `col-span-2` tile keeps a
	 * double-width slot). Falls back to a verbatim code block if fewer than 3 images resolve.
	 */
	private static function n_gallery( array $b ) {
		$src    = ( isset( $b['images'] ) && is_array( $b['images'] ) ) ? $b['images'] : array();
		$images = array();
		$spans  = array();
		foreach ( $src as $im ) {
			$url = trim( (string) ( $im['url'] ?? '' ) );
			if ( '' === $url ) { continue; }
			$uv = self::upload_val( $url );
			$images[] = array(
				'attachment_id' => ( isset( $uv['attachment_id'] ) && $uv['attachment_id'] !== '' ) ? $uv['attachment_id'] : '',
				'url'           => ( isset( $uv['url'] ) && $uv['url'] !== '' ) ? $uv['url'] : $url,
			);
			$spans[] = max( 1, (int) ( $im['span'] ?? 1 ) );
		}
		if ( count( $images ) < 3 ) { return self::n_code( (string) ( $b['html'] ?? '' ) ); }
		$count = count( $images );
		$total = array_sum( $spans ); if ( $total < 1 ) { $total = $count; }
		$ratio = array();
		foreach ( $spans as $s ) { $ratio[] = array( 'w' => (int) round( $s / $total * 100 ) ); }

		$atts = self::shortcode_default_atts( 'gallery' );
		if ( ! is_array( $atts ) ) { $atts = array(); }
		// The gallery view reads `source/media/images` FIRST and only falls back to the flat `images` key
		// when the former ISN'T an array — but the shortcode defaults leave `source.media.images` as an empty
		// [] (which IS an array), so the fallback never fires and the gallery renders EMPTY. Populate the real
		// render key (source → media → images), keeping the flat key too for back-compat.
		$atts['images'] = $images;
		if ( ! isset( $atts['source'] ) || ! is_array( $atts['source'] ) ) { $atts['source'] = array(); }
		$atts['source']['kind'] = 'media';
		if ( ! isset( $atts['source']['media'] ) || ! is_array( $atts['source']['media'] ) ) { $atts['source']['media'] = array(); }
		$atts['source']['media']['images'] = $images;
		$atts['click_action'] = 'lightbox';
		$atts['rounded']      = 'rounded';
		$atts['hover_zoom']   = 'yes';
		// DESIGN — mirror the source gallery's presentation (Grid / Masonry / Metro / Carousel / Marquee)
		// when the converter classified it (detect_gallery_design); else the uniform Grid. Only the chosen
		// design + its column/per-view count are set — every design's other defaults stay intact.
		$gd   = ( isset( $b['design'] ) && is_array( $b['design'] ) && ! empty( $b['design']['design'] ) ) ? $b['design'] : array( 'design' => 'grid' );
		$dkey = (string) $gd['design'];
		if ( isset( $atts['design_settings'] ) && is_array( $atts['design_settings'] ) ) {
			$atts['design_settings']['design'] = $dkey;
			// The default atts only materialize the 'grid' branch, so CREATE a design's branch when picking a
			// non-grid design (the shortcode fills the rest from its own defaults — only `design` is required).
			if ( ! isset( $atts['design_settings'][ $dkey ] ) || ! is_array( $atts['design_settings'][ $dkey ] ) ) { $atts['design_settings'][ $dkey ] = array(); }
			if ( 'grid' === $dkey ) {
				// Uniform grid — carry the source col-spans as the per-column ratio (a featured tile stays wider).
				$atts['design_settings']['grid']['count']   = (string) $count;
				$atts['design_settings']['grid']['columns'] = array( (string) $count => array( 'col_ratio' => $ratio ) );
			} elseif ( in_array( $dkey, array( 'masonry', 'metro' ), true ) ) {
				// Column-count designs use the nested `columns` shape `{ count:'N', 'N':{} }` + a gap default.
				$n = ! empty( $gd['columns'] ) ? (string) $gd['columns'] : '3';
				$atts['design_settings'][ $dkey ]['columns'] = array( 'count' => $n, $n => array() );
				if ( ! isset( $atts['design_settings'][ $dkey ]['gap'] ) ) { $atts['design_settings'][ $dkey ]['gap'] = '3'; }
			} elseif ( 'carousel' === $dkey ) {
				// Slider — a sensible visible-slide count; the carousel_* switches default on the shortcode side.
				$atts['design_settings']['carousel']['per_view'] = (string) min( 4, max( 1, $count ) );
			}
			// marquee: no required sub-option — the design's speed/direction/width defaults render as-is.
		}
		$atts['unique_id'] = self::uid();
		if ( ! isset( $atts['css_id'] ) )    { $atts['css_id'] = ''; }
		if ( ! isset( $atts['css_class'] ) ) { $atts['css_class'] = ''; }
		return array( 'type' => 'simple', 'shortcode' => 'gallery', '_items' => array(), 'atts' => $atts );
	}

	/**
	 * A native `posts` shortcode node from a detected blog LISTING (recognizer block `{ count, meta:{date,
	 * author}, design:{layout_mode,card_style,columns} }`). The `posts` shortcode is a DYNAMIC WP_Query, so the
	 * source's specific cards are NOT reproduced — instead a live `post` feed is emitted with the closest
	 * layout / card design + the meta bar the source showed, and it fills from the target site's own posts.
	 */
	private static function n_posts( array $b ) {
		$d = ( isset( $b['design'] ) && is_array( $b['design'] ) ) ? $b['design'] : array();
		$meta = ( isset( $b['meta'] ) && is_array( $b['meta'] ) ) ? $b['meta'] : array();
		$atts = self::shortcode_default_atts( 'posts' );
		if ( ! is_array( $atts ) ) { $atts = array(); }
		$atts['post_type']       = 'post';
		$atts['posts_per_page']  = (string) max( 3, (int) ( $b['count'] ?? 6 ) );
		$atts['orderby']         = 'date';
		$atts['order']           = 'DESC';
		$atts['layout_mode']     = (string) ( $d['layout_mode'] ?? 'grid' );
		$atts['card_style']      = (string) ( $d['card_style'] ?? 'standard' );
		$atts['columns_desktop'] = (string) min( 6, max( 1, (int) ( $d['columns'] ?? 3 ) ) );
		// Show only the meta the source actually displayed (date / author).
		$atts['meta_items']      = array( 'date' => ! empty( $meta['date'] ), 'author' => ! empty( $meta['author'] ) );
		$atts['pagination_type'] = 'none';
		$atts['unique_id']       = self::uid();
		if ( ! isset( $atts['css_id'] ) )    { $atts['css_id'] = ''; }
		if ( ! isset( $atts['css_class'] ) ) { $atts['css_class'] = ''; }
		return array( 'type' => 'simple', 'shortcode' => 'posts', '_items' => array(), 'atts' => $atts );
	}

	/**
	 * A native `call_to_action` shortcode node from a captured CTA band (recognizer block `{ title,
	 * message(html), button_label/link/target }`). Title is PLAIN TEXT (the view esc_html's it); message is
	 * HTML (wp_kses_post'd). One button slot (a 2-button CTA never reaches here — it stays assembled). See
	 * call-to-action options.php.
	 */
	private static function n_cta( array $b ) {
		$overlay = array(
			'title'         => trim( (string) ( $b['title'] ?? '' ) ),
			'message'       => (string) ( $b['message'] ?? '' ),
			'button_label'  => trim( (string) ( $b['button_label'] ?? '' ) ),
			'button_link'   => (string) ( $b['button_link'] ?? '#' ),
			'button_target' => in_array( ( $b['button_target'] ?? '' ), array( '_blank', '_self' ), true ) ? $b['button_target'] : '_self',
		);
		// Reproduce the source button's distinctive fill on the CTA's `.btn.btn-1` via scoped Custom CSS,
		// so a non-default (e.g. amber/secondary) button keeps its exact look instead of the shortcode's
		// default. The call_to_action has no button-preset field, so the fill rides on the node's custom_css.
		$btn_css = self::cta_button_css( (string) ( $b['btn_cls'] ?? '' ), (string) ( $b['btn_cs'] ?? '' ) );
		if ( '' !== $btn_css ) { $overlay['custom_css'] = $btn_css; }
		return self::finalize_widget( 'call_to_action', $overlay );
	}

	/**
	 * A section-intro HEADING beside a trailing "View All →" CTA LINK (a source `flex justify-between` row)
	 * → ONE builder column with native content_direction=row (Inline) + content_h=between (Space Between),
	 * so the heading sits LEFT and the link RIGHT — matching the source — instead of the link stacking below.
	 * Input block: { heading:<n_heading input>, link:{label,href,cls,cs,hover,icon_svg} }.
	 */
	private static function n_heading_cta( array $b ) {
		$h    = ( isset( $b['heading'] ) && is_array( $b['heading'] ) ) ? $b['heading'] : array();
		$link = ( isset( $b['link'] ) && is_array( $b['link'] ) ) ? $b['link'] : array();
		$heading_node = self::n_heading( $h );
		$btn = self::n_button(
			(string) ( $link['label'] ?? '' ),
			(string) ( $link['href'] ?? '#' ),
			(string) ( $link['cls'] ?? '' ),
			'',
			'after',
			(string) ( $link['cs'] ?? '' ),
			'',
			'',
			(string) ( $link['icon_svg'] ?? '' ),
			(string) ( $link['hover'] ?? '' )
		);
		// Carry the desktop link's responsive visibility (`hidden md:flex` → hide on mobile) so it matches
		// its `md:hidden` mobile twin and only ONE shows per breakpoint.
		$rh = self::responsive_hide_from_class( (string) ( $link['cls'] ?? '' ) );
		if ( $rh && isset( $btn['atts'] ) && is_array( $btn['atts'] ) ) { $btn['atts']['responsive_hide'] = $rh; }
		$col = self::n_column( '1_1', array( $heading_node, $btn ) );
		if ( isset( $col['atts'] ) && is_array( $col['atts'] ) ) {
			$col['atts']['content_direction'] = 'row';     // Inline
			$col['atts']['content_h']         = 'between';  // Space Between → heading left, link right
			$col['atts']['content_v']         = 'end';      // source `items-end` — align to the baseline
			// Both children must be content-width (the special_heading wrapper + the .btn render block, which
			// would otherwise fill the row and force a wrap). Size them to content so Space-Between works.
			$col['atts']['custom_css'] = 'selector{align-items:flex-end;flex-wrap:nowrap;}selector > *{flex:0 1 auto !important;width:auto !important;max-width:none !important;}selector .special-heading{width:auto !important;}selector .btn{flex:0 0 auto !important;width:auto !important;}';
		}
		return $col;
	}

	/** Scoped Custom CSS painting the CTA button (`selector .btn.btn-1`) from the source button's fill. */
	private static function cta_button_css( $cls, $cs ) {
		$d = array();
		if ( '' !== $cs ) { $d = self::cs_decls( $cs, array( 'background-color', 'color', 'border', 'border-radius', 'padding', 'font-size', 'font-weight' ) ); }
		if ( '' === (string) ( $d['background-color'] ?? '' ) && '' !== (string) $cls ) {
			// No computed style (Tailwind-only capture) — resolve a semantic fill class to a concrete colour.
			$bg = self::resolve_color_classes( '<x class="' . esc_attr( $cls ) . '">' );
			if ( preg_match( '/color:\s*([^"\';]+)/', $bg, $m ) ) { $d['color'] = trim( $m[1] ); }
		}
		if ( empty( $d ) || ( '' === (string) ( $d['background-color'] ?? '' ) && '' === (string) ( $d['border'] ?? '' ) ) ) { return ''; }
		$body = '';
		if ( ! empty( $d['background-color'] ) ) { $body .= 'background-color:' . $d['background-color'] . ' !important;border-color:' . $d['background-color'] . ' !important;'; }
		if ( ! empty( $d['color'] ) )            { $body .= 'color:' . $d['color'] . ' !important;'; }
		if ( ! empty( $d['border'] ) && empty( $d['background-color'] ) ) { $body .= 'border:' . $d['border'] . ' !important;'; }
		if ( ! empty( $d['border-radius'] ) )    { $body .= 'border-radius:' . $d['border-radius'] . ';'; }
		if ( ! empty( $d['padding'] ) )          { $body .= 'padding:' . $d['padding'] . ';'; }
		if ( ! empty( $d['font-size'] ) )        { $body .= 'font-size:' . $d['font-size'] . ';'; }
		if ( ! empty( $d['font-weight'] ) )      { $body .= 'font-weight:' . $d['font-weight'] . ';'; }
		return '' !== $body ? 'selector .btn.btn-1{' . $body . '}' : '';
	}

	/** The icon-v2 "no icon" value shared by the widget item builders. */
	private static function icon_none() {
		return array( 'type' => 'none', 'icon-class' => '', 'icon-class-without-root' => false, 'pack-name' => false, 'pack-css-uri' => false );
	}

	/** Finalize a widget node: real shortcode defaults + a fresh unique_id + guaranteed css_id/css_class. */
	private static function finalize_widget( $tag, array $overlay ) {
		$atts = self::shortcode_default_atts( $tag );
		if ( ! is_array( $atts ) ) { $atts = array(); }
		$atts = array_merge( $atts, $overlay );
		$atts['unique_id'] = self::uid();
		if ( ! isset( $atts['css_id'] ) ) { $atts['css_id'] = ''; }
		if ( ! isset( $atts['css_class'] ) ) { $atts['css_class'] = ''; }
		return array( 'type' => 'simple', 'shortcode' => $tag, '_items' => array(), 'atts' => $atts );
	}

	/**
	 * A native `tabs` shortcode node from a captured tab widget (recognizer block `{ items:[{title,content,active}] }`).
	 * Each item → one `tabs` entry `{ tab_title, tab_content, is_active }` (Content layout). See tabs.md.
	 */
	private static function n_tabs( array $b ) {
		$src  = ( isset( $b['items'] ) && is_array( $b['items'] ) ) ? $b['items'] : array();
		$tabs = array();
		$have_active = false;
		foreach ( $src as $it ) {
			$title = trim( (string) ( $it['title'] ?? '' ) );
			if ( '' === $title ) { continue; }
			$active = ( ! $have_active && 'yes' === ( $it['active'] ?? 'no' ) ) ? 'yes' : 'no';
			if ( 'yes' === $active ) { $have_active = true; }
			$tabs[] = array(
				'tab_title'   => $title,
				'tab_content' => (string) ( $it['content'] ?? '' ),
				'tab_image'   => '',
				'badge'       => '',
				'icon'        => self::icon_none(),
				'disabled'    => 'no',
				'is_active'   => $active,
			);
		}
		if ( count( $tabs ) < 2 ) { return self::n_code( '' ); }
		if ( ! $have_active ) { $tabs[0]['is_active'] = 'yes'; }
		// Mirror the source presentation: `orientation` (horizontal/vertical) + the nav `design`
		// (underline/pills/segmented/boxed) when detect_tabs_design classified them.
		$d = ( isset( $b['design'] ) && is_array( $b['design'] ) ) ? $b['design'] : array();
		$overlay = array( 'tabs' => $tabs );
		if ( ! empty( $d['orientation'] ) ) { $overlay['orientation'] = (string) $d['orientation']; }
		if ( ! empty( $d['tab_style'] ) )   { $overlay['design'] = (string) $d['tab_style']; }
		return self::finalize_widget( 'tabs', $overlay );
	}

	/**
	 * A native `steps` shortcode node from a captured process flow (recognizer block `{ items:[{title,content,number}] }`).
	 * Each item → one step `{ title, content, icon, number }`. See steps.md.
	 */
	private static function n_steps( array $b ) {
		$src   = ( isset( $b['items'] ) && is_array( $b['items'] ) ) ? $b['items'] : array();
		$steps = array();
		foreach ( $src as $it ) {
			$title = trim( (string) ( $it['title'] ?? '' ) );
			if ( '' === $title ) { continue; }
			$steps[] = array(
				'title'   => $title,
				'content' => (string) ( $it['content'] ?? '' ),
				'icon'    => self::icon_none(),
				'number'  => (string) ( $it['number'] ?? '' ),
			);
		}
		if ( count( $steps ) < 2 ) { return self::n_code( '' ); }
		$d = ( isset( $b['design'] ) && is_array( $b['design'] ) && ! empty( $b['design']['design'] ) ) ? array( 'design' => (string) $b['design']['design'] ) : array();
		return self::finalize_widget( 'steps', array( 'steps' => $steps ) + $d );
	}

	/**
	 * A native `timeline` shortcode node from a captured dated sequence (recognizer block `{ items:[{date,title,text}] }`).
	 * Each item → one milestone `{ date, title, text, icon, image, link_* }`. See timeline.md.
	 */
	private static function n_timeline( array $b ) {
		$src   = ( isset( $b['items'] ) && is_array( $b['items'] ) ) ? $b['items'] : array();
		$items = array();
		foreach ( $src as $it ) {
			$title = trim( (string) ( $it['title'] ?? '' ) );
			$date  = trim( (string) ( $it['date'] ?? '' ) );
			if ( '' === $title && '' === $date ) { continue; }
			$items[] = array(
				'date'        => $date,
				'title'       => '' !== $title ? $title : $date,
				'text'        => (string) ( $it['text'] ?? '' ),
				'icon'        => self::icon_none(),
				'image'       => '',
				'link_label'  => '',
				'link_url'    => '',
				'link_target' => '_self',
			);
		}
		if ( count( $items ) < 2 ) { return self::n_code( '' ); }
		$d = ( isset( $b['design'] ) && is_array( $b['design'] ) && ! empty( $b['design']['design'] ) ) ? array( 'design' => (string) $b['design']['design'] ) : array();
		return self::finalize_widget( 'timeline', array( 'items' => $items ) + $d );
	}

	/**
	 * A native `progress` shortcode node from captured skill/progress bars (recognizer block `{ bars:[{label,percent}] }`).
	 * Each item → one bar `{ label, percent, icon, color }`; bar layout. See progress.md.
	 */
	private static function n_progress( array $b ) {
		$src  = ( isset( $b['bars'] ) && is_array( $b['bars'] ) ) ? $b['bars'] : array();
		$bars = array();
		foreach ( $src as $it ) {
			$pct = (int) ( $it['percent'] ?? 0 );
			$bars[] = array(
				'label'   => (string) ( $it['label'] ?? '' ),
				'percent' => max( 0, min( 100, $pct ) ),
				'icon'    => self::icon_none(),
				'color'   => array( 'predefined' => '', 'custom' => '' ),
			);
		}
		if ( count( $bars ) < 2 ) { return self::n_code( '' ); }
		$type = ( isset( $b['design']['type'] ) && in_array( $b['design']['type'], array( 'bar', 'circle', 'gauge' ), true ) ) ? (string) $b['design']['type'] : 'bar';
		return self::finalize_widget( 'progress', array( 'layout' => array( 'type' => $type ), 'bars' => $bars ) );
	}

	/**
	 * A native `pricing_table` node from a captured pricing grid (recognizer block `{ plans:[…] }`). Each plan →
	 * a plan object with the multi-inline `price`/`period`/`original_price` `{monthly,yearly}` shape. See pricing-table.md.
	 */
	private static function n_pricing( array $b ) {
		$src   = ( isset( $b['plans'] ) && is_array( $b['plans'] ) ) ? $b['plans'] : array();
		$plans = array();
		foreach ( $src as $p ) {
			$title = trim( (string) ( $p['title'] ?? '' ) );
			$price = trim( (string) ( $p['price'] ?? '' ) );
			if ( '' === $title && '' === $price ) { continue; }
			$period = trim( (string) ( $p['period'] ?? '' ) );
			$plans[] = array(
				'plan_title'     => '' !== $title ? $title : 'Plan',
				'icon'           => self::icon_none(),
				'subtitle'       => '',
				'currency'       => (string) ( $p['currency'] ?? '$' ),
				'price'          => array( 'monthly' => $price, 'yearly' => '' ),
				'period'         => array( 'monthly' => '' !== $period ? $period : '/mo', 'yearly' => '/yr' ),
				'original_price' => array( 'monthly' => '', 'yearly' => '' ),
				'features'       => (string) ( $p['features'] ?? '' ),
				'featured'       => ( 'yes' === ( $p['featured'] ?? 'no' ) ) ? 'yes' : 'no',
				'ribbon'         => (string) ( $p['ribbon'] ?? '' ),
				'button_label'   => (string) ( $p['btn_label'] ?? '' ),
				'button_url'     => (string) ( $p['btn_url'] ?? '' ),
				'button_target'  => '_self',
			);
		}
		if ( count( $plans ) < 2 ) { return self::n_code( '' ); }
		$cols    = (string) max( 2, min( 5, count( $plans ) ) );
		$overlay = array( 'plans' => $plans, 'columns' => $cols );
		if ( isset( $b['design'] ) && in_array( $b['design'], array( 'classic', 'modern', 'minimal', 'gradient', 'dark', 'outline' ), true ) ) {
			$overlay['design'] = (string) $b['design'];
		}
		return self::finalize_widget( 'pricing_table', $overlay );
	}

	/**
	 * A native `lottie` shortcode node from a captured Lottie/Bodymovin embed (recognizer block `{ src }`).
	 * URL source, viewport trigger. See lottie.md.
	 */
	private static function n_lottie( array $b ) {
		$src = trim( (string) ( $b['src'] ?? '' ) );
		if ( '' === $src ) { return self::n_code( '' ); }
		return self::finalize_widget( 'lottie', array( 'source' => 'url', 'lottie_url' => $src, 'trigger' => 'viewport' ) );
	}

	/**
	 * A native `svg_draw` shortcode node from a captured self-drawing SVG (recognizer block `{ code }`).
	 * Pasted-code source, view trigger. Requires the animation-engine extension active at render. See svg-draw.md.
	 */
	private static function n_svg_draw( array $b ) {
		$code = (string) ( $b['code'] ?? '' );
		if ( '' === trim( $code ) ) { return self::n_code( '' ); }
		return self::finalize_widget( 'svg_draw', array(
			'svg'     => array( 'source' => 'code', 'preset' => array( 'preset' => 'signature' ), 'code' => array( 'code' => $code ), 'upload' => array( 'file' => '' ) ),
			'trigger' => 'view',
		) );
	}

	/**
	 * Classify a button's captured hover/pseudo CSS (data-sc-hover) into the nearest native `.btnfx-*`
	 * hover-animation preset — deterministic fingerprinting against a FINITE effect vocabulary, so a source
	 * button's custom hover motion maps to a real Theme-Settings hover animation instead of being dropped.
	 * Returns a slug like 'btnfx-fill-up' or '' when nothing is close (caller then carries the raw CSS).
	 *
	 * The payload is `state{decl;decl}|state{…}` where state ∈ self|before|after|hover-self|hover-before|
	 * hover-after (from capture.mjs). We look at what CHANGES between rest and hover.
	 *
	 * @param string $hover data-sc-hover payload
	 * @return string btnfx slug or ''
	 */
	private static function classify_hover_animation( $hover ) {
		$hover = (string) $hover;
		if ( $hover === '' ) { return ''; }
		// Parse into state => decls map.
		$states = array();
		foreach ( explode( '|', $hover ) as $chunk ) {
			if ( ! preg_match( '/^([a-z-]+)\{(.*)\}$/s', trim( $chunk ), $m ) ) { continue; }
			$states[ $m[1] ] = strtolower( $m[2] );
		}
		if ( ! $states ) { return ''; }
		$get   = function ( $k ) use ( $states ) { return isset( $states[ $k ] ) ? $states[ $k ] : ''; };
		$decl  = function ( $css, $prop ) { return preg_match( '/(?:^|;)\s*' . preg_quote( $prop, '/' ) . '\s*:\s*([^;]+)/', (string) $css, $mm ) ? trim( $mm[1] ) : ''; };

		// (1) OVERLAY-FILL family — a ::before/::after overlay that is HIDDEN at rest (translate off / scale 0)
		// and REVEALED on hover (translate 0 / scale 1). The reveal DIRECTION picks the preset.
		foreach ( array( 'before', 'after' ) as $ps ) {
			$rest = $get( $ps ); $hov = $get( 'hover-' . $ps );
			if ( $rest === '' && $hov === '' ) { continue; }
			// The at-rest hidden signature.
			$ty = $decl( $rest, '--tw-translate-y' ); $tx = $decl( $rest, '--tw-translate-x' );
			$sy = $decl( $rest, '--tw-scale-y' );      $sx = $decl( $rest, '--tw-scale-x' );
			$origin = $decl( $rest, 'transform-origin' );
			$tf     = $decl( $rest, 'transform' );
			$hidden_down  = strpos( $ty, '100%' ) !== false || preg_match( '/translatey\(\s*100%/', $tf );
			$hidden_up    = strpos( $ty, '-100%' ) !== false || preg_match( '/translatey\(\s*-100%/', $tf );
			$hidden_left  = strpos( $tx, '-100%' ) !== false || preg_match( '/translatex\(\s*-100%/', $tf );
			$hidden_right = strpos( $tx, '100%' ) !== false || preg_match( '/translatex\(\s*100%/', $tf );
			$scaley0 = ( $sy === '0' || strpos( $tf, 'scaley(0' ) !== false );
			$scalex0 = ( $sx === '0' || strpos( $tf, 'scalex(0' ) !== false );
			$scale0  = ( strpos( $tf, 'scale(0' ) !== false );
			// Only treat as a fill overlay when hover actually MOVES it back (a real reveal).
			$reveals = $hov !== '' && ( strpos( $hov, 'translate' ) !== false || strpos( $hov, 'scale' ) !== false || strpos( $decl( $hov, '--tw-translate-y' ), '0' ) !== false || strpos( $decl( $hov, '--tw-scale-y' ), '1' ) !== false );
			if ( ! $reveals ) { continue; }
			if ( $hidden_down || ( $scaley0 && strpos( $origin, 'bottom' ) !== false ) ) { return 'btnfx-fill-up'; }
			if ( $hidden_up   || ( $scaley0 && strpos( $origin, 'top' ) !== false ) )    { return 'btnfx-fill-up'; } // nearest available (fill from vertical edge)
			if ( $hidden_left  || ( $scalex0 && strpos( $origin, 'left' ) !== false ) )  { return 'btnfx-fill-right'; }
			if ( $hidden_right || ( $scalex0 && strpos( $origin, 'right' ) !== false ) ) { return 'btnfx-fill-right'; }
			if ( $scale0 || $scaley0 || $scalex0 ) { return 'btnfx-fill-center'; }
		}

		// (2) ELEMENT-level transforms on :hover (no overlay) — scale/lift/skew/rotate.
		$hs = $get( 'hover-self' );
		if ( $hs !== '' ) {
			$tf = $decl( $hs, 'transform' );
			if ( preg_match( '/scale\(\s*1\.0*[1-9]/', $tf ) )                 { return 'btnfx-grow'; }   // scale up
			if ( preg_match( '/translatey\(\s*-\d/', $tf ) )                    { return 'btnfx-lift'; }   // move up
			if ( strpos( $tf, 'skew' ) !== false )                             { return 'btnfx-skew'; }
			if ( strpos( $tf, 'rotate' ) !== false )                           { return 'btnfx-rotate'; }
			if ( $decl( $hs, 'box-shadow' ) !== '' && strpos( $tf, 'translate' ) !== false ) { return 'btnfx-lift'; }
			if ( $decl( $hs, 'box-shadow' ) !== '' )                           { return 'btnfx-glow'; }   // shadow-only glow
		}
		// (3) An animated underline bar (::after that grows in width/scaleX on hover).
		$ua = $get( 'after' ); $uah = $get( 'hover-after' );
		if ( ( strpos( $ua, 'height:2px' ) !== false || strpos( $ua, 'bottom:0' ) !== false ) && $uah !== '' && ( strpos( $uah, 'width' ) !== false || strpos( $uah, 'scalex' ) !== false ) ) {
			return 'btnfx-underline';
		}
		return '';
	}

	/** Rebuild one hover-state's declarations into portable CSS: reconstruct `transform` from Tailwind's
	 *  `--tw-translate/scale/rotate` vars (useless standalone), drop those vars, keep everything else. */
	/**
	 * Normalize shadcn/Tailwind HSL-triplet color tokens into a form valid in the UnysonPlus theme.
	 * Source frameworks store colours as bare HSL channels in `--primary` and write `hsl(var(--primary) / .9)`.
	 * The converted theme defines `--primary` (etc.) as a FULL colour (aliased to `--color-primary`), so
	 * `hsl(var(--primary) / .9)` is INVALID CSS — the browser drops the declaration. On a button :hover that
	 * meant the source's "primary at 90% opacity" (a LIGHTEN) silently fell back to the shortcode's darken
	 * default — the "hover is the opposite/darker" bug. Rewrite:
	 *   hsl(var(--token) / <a>)  → color-mix(in srgb, var(--token) <a×100>%, transparent)   (opacity-preserving)
	 *   hsl(var(--token))        → var(--token)
	 */
	private static function normalize_shadcn_color( $v ) {
		$v = (string) $v;
		if ( false === stripos( $v, 'hsl(var(' ) ) { return $v; }
		$v = preg_replace_callback( '/hsl\(\s*var\((--[a-z0-9-]+)\)\s*\/\s*([0-9.]+%?)\s*\)/i', function ( $m ) {
			$a = $m[2];
			if ( '%' !== substr( $a, -1 ) ) { $a = rtrim( rtrim( sprintf( '%.2f', (float) $a * 100 ), '0' ), '.' ) . '%'; }
			return 'color-mix(in srgb, var(' . $m[1] . ') ' . $a . ', transparent)';
		}, $v );
		$v = preg_replace( '/hsl\(\s*var\((--[a-z0-9-]+)\)\s*\)/i', 'var($1)', $v );
		return $v;
	}

	private static function hover_rebuild_decls( $css ) {
		$css = (string) $css;
		$g = function ( $p ) use ( $css ) { return preg_match( '/(?:^|;)\s*' . preg_quote( $p, '/' ) . '\s*:\s*([^;]+)/', $css, $m ) ? trim( $m[1] ) : ''; };
		$tx = $g( '--tw-translate-x' ); $ty = $g( '--tw-translate-y' );
		$sx = $g( '--tw-scale-x' );     $sy = $g( '--tw-scale-y' ); $rot = $g( '--tw-rotate' );
		$out = array();
		foreach ( explode( ';', $css ) as $d ) {
			$d = trim( $d ); if ( $d === '' ) { continue; }
			$cp = strpos( $d, ':' ); if ( $cp === false ) { continue; }
			$prop = trim( substr( $d, 0, $cp ) );
			if ( strpos( $prop, '--tw-' ) === 0 ) { continue; }                 // internal Tailwind var — drop
			if ( $prop === 'transform' && ( $tx || $ty || $sx || $sy || $rot ) ) { continue; } // rebuilt below
			$val = self::normalize_shadcn_color( trim( substr( $d, $cp + 1 ) ) );
			// Resolve a source PALETTE var (`var(--secondary)`, `var(--primary)`…) to its concrete hex. The
			// UnysonPlus theme never defines the source framework's Tailwind palette vars, so an unresolved
			// var() leaves the hover a NO-OP (the "outline button hover does nothing" bug). Keep unknown vars.
			$val = preg_replace_callback( '/var\(\s*(--[a-z0-9-]+)\s*\)/i', function ( $m ) {
				$hex = self::color_token_hex( ltrim( $m[1], '-' ) );
				return $hex !== '' ? $hex : $m[0];
			}, $val );
			$out[] = $prop . ':' . $val;
		}
		if ( $tx || $ty || $sx || $sy || $rot ) {
			$t = 'translate(' . ( $tx ?: '0' ) . ',' . ( $ty ?: '0' ) . ')';
			if ( $rot ) { $t .= ' rotate(' . $rot . ')'; }
			if ( $sx || $sy ) { $t .= ' scale(' . ( $sx ?: '1' ) . ',' . ( $sy ?: '1' ) . ')'; }
			$out[] = 'transform:' . $t;
		}
		return implode( ';', $out );
	}

	/** Fallback: reproduce the source button's hover/pseudo rules verbatim as `selector`-scoped Custom CSS
	 *  (used only when classify_hover_animation found no native preset), so a bespoke effect is never dropped. */
	private static function hover_verbatim_css( $hover ) {
		// Scope onto the NODE placeholder `selector` (the builder replaces it with the element's own
		// .u<hash> at render, exactly like the hifi `:where(selector){…}` base on the same node) — NOT the
		// PRESET placeholder `{{SELECTOR}}`, which a node's Custom CSS never resolves. The old `{{SELECTOR}}`
		// was left unresolved and then stripped to a BARE `:hover{…}` = a universal `*:hover` rule, so
		// hovering ANY header / section / footer flashed the button's hover fill. (Node CSS = `selector`.)
		$map = array( 'self' => 'selector', 'before' => 'selector::before', 'after' => 'selector::after',
			'hover-self' => 'selector:hover', 'hover-before' => 'selector:hover::before', 'hover-after' => 'selector:hover::after' );
		$out = array();
		foreach ( explode( '|', (string) $hover ) as $chunk ) {
			if ( ! preg_match( '/^([a-z-]+)\{(.*)\}$/s', trim( $chunk ), $m ) || ! isset( $map[ $m[1] ] ) ) { continue; }
			$decls = self::hover_rebuild_decls( $m[2] );
			if ( $decls !== '' ) { $out[] = $map[ $m[1] ] . ' { ' . $decls . '; }'; }
		}
		return $out ? implode( "\n", $out ) : '';
	}

	private static function n_button( $label, $link, $cls = '', $icon = '', $icon_pos = 'after', $cs = '', $group_cls = '', $group_cs = '', $icon_svg = '', $hover = '' ) {
		// Converted buttons use the 'Default' style (value '') = the bare `.btn` base. The child
		// theme carries the source's primary-button rules (rewritten onto `.btn` in page_css,
		// base + :hover); the user can switch to a Color Preset later. Full att shape per the
		// page-builder's own button export so the editor doesn't choke on missing atts.
		$atts = array(
			'label'           => (string) $label,
			'link'            => (string) $link,
			'target'          => '_self',
			'icon'            => array( 'type' => 'none', 'icon-class' => '', 'icon-class-without-root' => false, 'pack-name' => false, 'pack-css-uri' => false ),
			'icon_position'   => 'after',
			// Color + size PRESET: attach the button_colors / button_sizes slug that matches this source
			// button (semantic fill class → the built preset, or a computed-colour match) — the SAME
			// linking the header CTA does, so body buttons pick up the theme's hover + consistency. The
			// exact per-node custom_css below still reproduces the source's precise fill/padding (safety net).
			'style'           => '',
			'size'            => '',
			'width'           => array( 'mode' => '', 'custom' => array( 'custom_width' => array( 'value' => '', 'unit' => 'px' ) ) ),
			'alignment'       => '',
			'state'           => '',
			'hover_animation' => '',
			'spacing'         => self::def_spacing(),
			'animation'       => self::def_animation(),
			'unique_id'       => self::uid(),
			// Source button classes (bg / text color / radius / padding) → one semantic class on the button,
			// so it gets the source's exact fill instead of the theme's default. The `#page a` link color
			// the AI path emits doesn't exist on this path, so a single class loaded after the theme wins.
			'css_id'          => '', 'css_class' => self::button_style_class( $cls, $cs ), 'custom_css' => '', 'responsive_hide' => array(), 'custom_attrs' => array(),
		);
		// AUTO-WIDTH: a lone button is a flex item in a column whose content wrapper defaults to
		// align-items:stretch, so an inline-block source button gets blockified + stretched to the full
		// column width. Pin it back to its natural width (unless the source button was genuinely full-width),
		// preserving the source's horizontal placement (a centered `mx-auto` button stays centered).
		$bcls  = ' ' . strtolower( (string) $cls . ' ' . (string) $group_cls ) . ' ';
		$bfull = ( strpos( $bcls, ' w-full ' ) !== false || strpos( $bcls, ' w-100 ' ) !== false || strpos( $bcls, ' w-screen ' ) !== false || strpos( $bcls, ' block ' ) !== false );
		if ( ! $bfull && preg_match( '/display\s*:\s*block/i', (string) $cs ) && preg_match( '/width\s*:\s*100%/i', (string) $cs ) ) { $bfull = true; }
		if ( ! $bfull ) {
			$bself = ( strpos( $bcls, ' mx-auto ' ) !== false || strpos( $bcls, ' self-center ' ) !== false || strpos( $bcls, ' text-center ' ) !== false ) ? 'center' : 'flex-start';
			$atts['custom_css'] = trim( $atts['custom_css'] . "\nselector{align-self:" . $bself . ";width:auto;}" );
		}
		$preset = self::button_preset_for( (string) $cls, (string) $cs );
		if ( '' !== $preset['style'] ) { $atts['style'] = $preset['style']; }
		if ( '' !== $preset['size'] )  { $atts['size']  = $preset['size']; }
		// HOVER ANIMATION — classify the source button's captured :hover/::before/::after motion into a native
		// `.btnfx-*` preset (deterministic fingerprint). When nothing matches but the source DID declare a hover
		// effect, carry its rules verbatim as scoped Custom CSS (rewriting the pseudo states onto `selector`),
		// so a bespoke animation is reproduced rather than dropped. Nothing added when the button has no hover fx.
		if ( '' !== (string) $hover ) {
			$fx = self::classify_hover_animation( (string) $hover );
			if ( $fx !== '' ) {
				$atts['hover_animation'] = $fx;
			} else {
				$verbatim = self::hover_verbatim_css( (string) $hover );
				if ( $verbatim !== '' ) { $atts['custom_css'] = trim( $atts['custom_css'] . "\n" . $verbatim ); }
			}
		}
		$icon     = trim( (string) $icon );
		$icon_svg = trim( (string) $icon_svg );
		if ( $icon_svg !== '' ) {
			// An inline <svg> CTA icon (a lucide arrow, etc.) carried verbatim — icon-v2 renders inline SVG via
			// currentColor, so it inherits the button's text colour. Same value shape as the heading overline icon.
			$atts['icon']          = array( 'type' => 'svg', 'svg-source' => 'inline', 'markup' => $icon_svg );
			$atts['icon_position'] = in_array( $icon_pos, array( 'before', 'after' ), true ) ? $icon_pos : 'after';
		} elseif ( $icon !== '' ) {
			$atts['icon']          = self::icon_value( $icon );
			$atts['icon_position'] = in_array( $icon_pos, array( 'before', 'after' ), true ) ? $icon_pos : 'after';
		}
		$item = array( 'type' => 'simple', 'shortcode' => 'button', 'atts' => $atts, '_items' => array() );
		if ( '' !== (string) $group_cls || '' !== (string) $group_cs ) { $item['_group'] = array( 'cls' => (string) $group_cls, 'cs' => (string) $group_cs ); } // the source button container's flex styling
		return $item;
	}

	/**
	 * Build the icon-v2 value for a source icon class (e.g. "fa fa-angle-right", "ti-light-bulb").
	 * Always KEEPS the icon-class — the button / icon_box views render `<i class="…">` from it and
	 * the source's font CSS (carried in the child stylesheet) styles it, even for icon fonts that
	 * aren't registered as icon-v2 packs (Themify, a Font Awesome kit). When the pack IS registered
	 * the loader fills pack-name / pack-css-uri / icon-class-without-root so the picker previews it.
	 *
	 * @param string $icon_class
	 * @return array
	 */
	private static function icon_value( $icon_class ) {
		$icon_class = self::fa_icon( $icon_class ); // normalize to a renderable Font Awesome class
		$val = array( 'type' => 'icon-font', 'icon-class' => (string) $icon_class, 'icon-class-without-root' => false, 'pack-name' => false, 'pack-css-uri' => false );
		if ( function_exists( 'fw' ) ) {
			$ot = fw()->backend->option_type( 'icon' );
			if ( $ot && isset( $ot->packs_loader ) && $ot->packs_loader ) {
				$pl   = $ot->packs_loader;
				$pack = method_exists( $pl, 'pack_name_for' ) ? $pl->pack_name_for( $icon_class ) : null;
				if ( is_array( $pack ) && ! empty( $pack['name'] ) ) {
					$val['pack-name']    = $pack['name'];
					$val['pack-css-uri'] = isset( $pack['css_file_uri'] ) ? $pack['css_file_uri'] : '';
					if ( method_exists( $pl, 'class_without_root_for' ) ) { $val['icon-class-without-root'] = $pl->class_without_root_for( $icon_class ); }
				}
			}
		}
		return $val;
	}

	/**
	 * Normalize a source icon class to a renderable Font Awesome class. Font Awesome is the icon
	 * font bundled + registered with the plugin, so FA classes always render; other icon fonts
	 * (Themify `ti-*`, etc.) may not load on the converted site, showing an empty box. FA classes
	 * pass through unchanged; common Themify icons map to their FA equivalent; anything unknown
	 * falls back to a neutral FA placeholder the user can change. (Clean code over exact glyph.)
	 *
	 * @param string $icon_class
	 * @return string
	 */
	private static function fa_icon( $icon_class ) {
		$icon_class = trim( (string) $icon_class );
		if ( $icon_class === '' ) { return ''; }
		$tokens = preg_split( '/\s+/', strtolower( $icon_class ) );
		foreach ( $tokens as $t ) {
			if ( preg_match( '/^(fa|fas|far|fab|fal|fad)$/', $t ) || strpos( $t, 'fa-' ) === 0 ) { return $icon_class; } // already FA
		}
		$map = array(
			'ti-light-bulb' => 'lightbulb-o', 'ti-idea' => 'lightbulb-o', 'ti-panel' => 'th-list', 'ti-layout' => 'th-large',
			'ti-headphone-alt' => 'headphones', 'ti-headphone' => 'headphones', 'ti-bar-chart' => 'bar-chart', 'ti-stats-up' => 'line-chart',
			'ti-mobile' => 'mobile', 'ti-tablet' => 'tablet', 'ti-desktop' => 'desktop', 'ti-settings' => 'cog', 'ti-cog' => 'cog',
			'ti-pencil' => 'pencil', 'ti-pencil-alt' => 'pencil', 'ti-heart' => 'heart', 'ti-star' => 'star', 'ti-shield' => 'shield',
			'ti-rocket' => 'rocket', 'ti-cloud' => 'cloud', 'ti-camera' => 'camera', 'ti-email' => 'envelope', 'ti-user' => 'user',
			'ti-search' => 'search', 'ti-lock' => 'lock', 'ti-world' => 'globe', 'ti-check' => 'check', 'ti-time' => 'clock-o',
			'ti-comment' => 'comment', 'ti-comments' => 'comments', 'ti-gift' => 'gift', 'ti-target' => 'bullseye', 'ti-wallet' => 'credit-card',
			'ti-bag' => 'shopping-bag', 'ti-shopping-cart' => 'shopping-cart', 'ti-cup' => 'trophy', 'ti-medall' => 'trophy', 'ti-medall-alt' => 'trophy',
			'ti-paint-roller' => 'paint-brush', 'ti-paint-bucket' => 'paint-brush', 'ti-ruler-pencil' => 'pencil-square-o', 'ti-package' => 'cube',
			'ti-support' => 'life-ring', 'ti-thumb-up' => 'thumbs-up', 'ti-bell' => 'bell', 'ti-calendar' => 'calendar', 'ti-map' => 'map-marker',
		);
		foreach ( $tokens as $t ) {
			if ( isset( $map[ $t ] ) ) { return 'fa fa-' . $map[ $t ]; }
		}
		return 'fa fa-star'; // unknown icon → neutral placeholder
	}

	/**
	 * The full default atts for a registered shortcode (every leaf option at its default), pulled
	 * from the framework so a generated node has the EXACT shape the page-builder stores for a
	 * hand-built item — no missing nested atts (which break the builder editor). Empty if the
	 * shortcode/framework isn't available.
	 *
	 * @param string $tag shortcode tag, e.g. 'icon_box'
	 * @return array
	 */
	private static function shortcode_default_atts( $tag ) {
		if ( ! function_exists( 'fw_ext' ) || ! function_exists( 'fw_get_options_values_from_input' ) ) { return array(); }
		$ext = fw_ext( 'shortcodes' );
		if ( ! $ext || ! method_exists( $ext, 'get_shortcode' ) ) { return array(); }
		$sc = $ext->get_shortcode( $tag );
		if ( ! $sc || ! method_exists( $sc, 'get_options' ) ) { return array(); }
		$opts = $sc->get_options();
		if ( ! is_array( $opts ) || ! $opts ) { return array(); }
		$vals = fw_get_options_values_from_input( $opts, array() );
		return is_array( $vals ) ? $vals : array();
	}

	/**
	 * Build an IMAGE CARD (product / collection / any photo-card) as a stacked column of native nodes:
	 * the photo (media_image) + the title (special_heading) + the body text (text_block, e.g. category /
	 * price / description) + an optional CTA button. Used when a captured card carries a real content
	 * <img> — so the source photo actually renders, instead of an icon_box that would drop it. A garbled
	 * wrapper-link "button" (the whole card is an <a>, so its label concatenated all the card text) is
	 * discarded: a real CTA is short and distinct from the title, not the entire card contents.
	 *
	 * @param array $card { image:{src,alt}, title, titleTag, text, button }
	 * @return array list of builder nodes (media_image, special_heading, text_block[, button])
	 */
	private static function n_image_card( array $card ) {
		$items = array();
		$img   = isset( $card['image'] ) && is_array( $card['image'] ) ? $card['image'] : array();
		$src   = trim( (string) ( $img['src'] ?? '' ) );
		if ( $src !== '' ) {
			$alt = esc_attr( (string) ( $img['alt'] ?? '' ) );
			// A card photo must FILL the card width (the source uses `w-full object-cover`), not render at its
			// small natural size. Reproduce the source's aspect ratio when it declares one (`aspect-square`,
			// `aspect-[4/5]`, `aspect-video`) so the product/collection grid stays uniform; else natural height.
			$icls = ' ' . strtolower( (string) ( $img['cls'] ?? '' ) ) . ' ';
			$ar   = '';
			if ( strpos( $icls, ' aspect-square ' ) !== false )     { $ar = '1 / 1'; }
			elseif ( strpos( $icls, ' aspect-video ' ) !== false )  { $ar = '16 / 9'; }
			elseif ( preg_match( '/\saspect-\[([0-9.]+)\/([0-9.]+)\]/', $icls, $am ) ) { $ar = $am[1] . ' / ' . $am[2]; }
			// The ratio often lives on the wrapping FRAME (`<div class="relative aspect-[3/4] overflow-hidden">`),
			// not the <img> itself — captured as $img['aspect'] (img_frame_aspect). Without it a portrait photo
			// renders at natural height and pushes its card's title BELOW its siblings' (the "Brand Alignment
			// sits lower" case); with it every card in the grid crops to the same ratio and the row stays even.
			elseif ( ! empty( $img['aspect'] ) && preg_match( '#^([0-9.]+)/([0-9.]+)$#', (string) $img['aspect'], $fm ) ) { $ar = $fm[1] . ' / ' . $fm[2]; }
			$decl = 'width:100%;display:block;' . ( $ar !== '' ? 'aspect-ratio:' . $ar . ';object-fit:cover;' : 'height:auto;' );
			$items[] = self::n_media_image( '<img src="' . esc_url( $src ) . '" alt="' . $alt . '" />', 'selector img{' . $decl . '}' );
		}
		$title = trim( wp_strip_all_tags( (string) ( $card['title'] ?? '' ) ) );
		if ( $title !== '' ) {
			$tag = strtolower( (string) ( $card['titleTag'] ?? 'h3' ) );
			$lvl = ( preg_match( '/^h([2-6])$/', $tag, $lm ) ) ? (int) $lm[1] : 3;
			$items[] = self::n_heading( array( 'title' => $title, 'level' => $lvl, 'title_class' => (string) ( $card['titleClass'] ?? '' ) ) );
		}
		$text = trim( (string) ( $card['text'] ?? '' ) );
		if ( $text !== '' ) { $items[] = self::n_text( $text ); }
		// Optional CTA — only a REAL button (short label, not the whole-card link whose label is the title
		// or the concatenated card text). A card that is entirely wrapped in one <a> is linked via its image.
		if ( ! empty( $card['button'] ) && is_array( $card['button'] ) ) {
			$bt  = $card['button'];
			$lbl = trim( wp_strip_all_tags( (string) ( $bt['label'] ?? '' ) ) );
			$is_wrapper = ( $lbl === '' || mb_strlen( $lbl ) > 32 || ( $title !== '' && stripos( $lbl, $title ) !== false ) );
			if ( ! $is_wrapper ) {
				$items[] = self::n_button( $lbl, (string) ( $bt['href'] ?? '#' ), (string) ( $bt['cls'] ?? '' ), (string) ( $bt['icon'] ?? '' ), 'after', (string) ( $bt['cs'] ?? '' ) );
			}
		}
		if ( ! $items ) { $items[] = self::n_code( '' ); }
		return $items;
	}

	/** A source frame aspect ("3/4", "16/9") → the image_box `image_ratio` slug, or '' (→ keep default). */
	private static function img_ratio_slug( $ar ) {
		$ar = str_replace( ' ', '', (string) $ar );
		$map = array( '1/1' => 'ratio-1-1', '4/3' => 'ratio-4-3', '3/2' => 'ratio-3-2', '16/9' => 'ratio-16-9', '3/4' => 'ratio-3-4', '2/3' => 'ratio-2-3' );
		return isset( $map[ $ar ] ) ? $map[ $ar ] : '';
	}

	/**
	 * A native `image_box` node from a captured IMAGE TILE — a prominent photo + title + short text (+ a CTA /
	 * hover "explore" link): the portfolio / service-card / feature-tile pattern. Emits ONE cohesive image_box
	 * (image + title + text + button + the source's design family) instead of decomposing to loose media_image
	 * + heading + text blocks — so the tile's hover/overlay + button semantics survive. Returns array() when
	 * there's no image (the caller falls back to n_image_card).
	 *
	 * @param array $card { image:{src,alt,cls,aspect}, title, titleTag, text, button, link }
	 */
	private static function n_image_box( array $card ) {
		$img = isset( $card['image'] ) && is_array( $card['image'] ) ? $card['image'] : array();
		$src = trim( (string) ( $img['src'] ?? '' ) );
		if ( $src === '' ) { return array(); }
		$atts = self::shortcode_default_atts( 'image_box' );
		if ( ! is_array( $atts ) ) { $atts = array(); }
		// Image → the box image (sideloaded upload shape so the picker previews + can edit it).
		$uv = self::upload_val( $src );
		$atts['image'] = array(
			'attachment_id' => ( isset( $uv['attachment_id'] ) && $uv['attachment_id'] !== '' ) ? $uv['attachment_id'] : 0,
			'url'           => ( isset( $uv['url'] ) && $uv['url'] !== '' ) ? $uv['url'] : $src,
		);
		$atts['image_alt'] = (string) ( $img['alt'] ?? '' );
		$title = trim( wp_strip_all_tags( (string) ( $card['title'] ?? '' ) ) );
		$atts['title'] = $title;
		$tag = strtolower( (string) ( $card['titleTag'] ?? 'h3' ) );
		$atts['title_tag'] = preg_match( '/^h[2-6]$/', $tag ) ? $tag : 'h3';
		$atts['text'] = (string) ( $card['text'] ?? '' );
		// Crop the image to the source frame aspect (keeps a tile grid uniform).
		$icls = ' ' . strtolower( (string) ( $img['cls'] ?? '' ) ) . ' '; $ar = '';
		if ( strpos( $icls, ' aspect-square ' ) !== false )    { $ar = '1/1'; }
		elseif ( strpos( $icls, ' aspect-video ' ) !== false ) { $ar = '16/9'; }
		elseif ( preg_match( '/\saspect-\[([0-9.]+)\/([0-9.]+)\]/', $icls, $am ) ) { $ar = $am[1] . '/' . $am[2]; }
		elseif ( ! empty( $img['aspect'] ) ) { $ar = (string) $img['aspect']; }
		$rslug = self::img_ratio_slug( $ar );
		if ( $rslug !== '' ) { $atts['image_ratio'] = $rslug; }
		// CTA — a short explore/CTA label (from a real button, else a whole-card "Discover →" link). An arrow
		// glyph / icon → the `arrow` link style; else a text link. The card's own link becomes the box link.
		$btn  = isset( $card['button'] ) && is_array( $card['button'] ) ? $card['button'] : array();
		$link = isset( $card['link'] ) && is_array( $card['link'] ) ? $card['link'] : array();
		$cta_label = ''; $cta_href = ''; $cta_arrow = false;
		$try = function ( $lbl, $href, $extra ) use ( &$cta_label, &$cta_href, &$cta_arrow, $title ) {
			$lbl = trim( wp_strip_all_tags( (string) $lbl ) );
			if ( $lbl === '' || mb_strlen( $lbl ) > 32 || ( $title !== '' && stripos( $lbl, $title ) !== false ) ) { return; }
			$cta_label = $lbl; $cta_href = (string) $href;
			$cta_arrow = (bool) preg_match( '/→|➔|»|\barrow\b|chevron/i', $lbl . ' ' . (string) $extra );
		};
		if ( $btn )  { $try( $btn['label'] ?? '', $btn['href'] ?? '', ( $btn['icon'] ?? '' ) . ' ' . ( $btn['cls'] ?? '' ) ); }
		if ( $cta_label === '' && $link ) { $try( $link['label'] ?? '', $link['href'] ?? '', '' ); }
		if ( $cta_label !== '' ) {
			$atts['button_style'] = $cta_arrow ? 'arrow' : 'link';
			$atts['button_label'] = $cta_label;
		}
		$box_href = $cta_href !== '' ? $cta_href : (string) ( $link['href'] ?? '' );
		if ( $box_href !== '' && $box_href !== '#' ) {
			$atts['link_behavior'] = 'url';
			$atts['link_url']      = $box_href;
			$atts['link_target']   = '_self';
		}
		// Design family — image-on-top + title/text below = the Stacked family (img → title → text). (Overlay
		// / Side families are not auto-selected yet; Stacked is the faithful default for a photo-topped tile.)
		$atts['design_settings'] = array( 'family' => 'stacked', 'stacked' => array( 'stacking' => 'img-title-text' ) );
		$atts['unique_id'] = self::uid();
		if ( ! isset( $atts['css_id'] ) )    { $atts['css_id'] = ''; }
		if ( ! isset( $atts['css_class'] ) ) { $atts['css_class'] = ''; }
		return array( 'type' => 'simple', 'shortcode' => 'image_box', '_items' => array(), 'atts' => $atts );
	}

	/**
	 * Build an icon_box node from a captured icon-card (.about-item: icon + heading + text [+ link]).
	 * Starts from the shortcode's real defaults (full shape) and overlays the mapped values. The
	 * source card wrapper class (e.g. `about-item`) goes on the icon_box CSS Class, so the carried
	 * `.about-item { … }` rules (border, padding, icon/heading/link styling) target THIS wrapper.
	 *
	 * @param array $card { icon, customIcon, title, titleTag, text, link, cls }
	 * @return array
	 */
	private static function n_icon_box( array $card ) {
		$atts = self::shortcode_default_atts( 'icon_box' );

		$atts['title'] = (string) ( $card['title'] ?? '' );
		$tag = strtolower( (string) ( $card['titleTag'] ?? 'h3' ) );
		$atts['title_tag'] = in_array( $tag, array( 'h3', 'h4', 'h5', 'h6', 'span', 'p' ), true ) ? $tag : 'h3';

		// Body content = the card's paragraph + (the "Read More" link in its own <p>, as decided —
		// a real <p> avoids the stray <br> wpautop inserts after a bare trailing <a>).
		$content = (string) ( $card['text'] ?? '' );
		if ( ! empty( $card['link'] ) && is_array( $card['link'] ) && trim( (string) ( $card['link']['label'] ?? '' ) ) !== '' ) {
			$href     = (string) ( $card['link']['href'] ?? '#' );
			$content .= '<p><a href="' . esc_url( $href ) . '">' . esc_html( $card['link']['label'] ) . '</a></p>';
		}
		$atts['content'] = $content;

		// Icon: a font icon → icon-v2 value (normalized to Font Awesome so it renders); an SVG →
		// custom_icon (icon_box renders inline SVG).
		if ( ! empty( $card['imgIcon'] ) && is_array( $atts['icon'] ) ) {
			// A source `<img src=*.svg>` icon → INLINE it as an icon-v2 svg value. WordPress can't host SVG in
			// the media library, so a custom-upload URL 404s (the icon silently vanishes); inline markup
			// renders cleanly on any domain via sc_icon_render and keeps the illustration's own colours. Fall
			// back to custom-upload only when the SVG can't be fetched — keeping the src for a hotlink.
			$svg_icon = self::svg_inline_icon( (string) $card['imgIcon'] );
			if ( $svg_icon ) {
				$atts['icon'] = array_merge( $atts['icon'], $svg_icon );
				// These SVGs are full illustrations, not glyphs — size the icon to the source icon container's
				// height (icon_size drives font-size, which the inline SVG now tracks). Floor/cap for sanity.
				$ih = isset( $card['imgIconH'] ) ? (int) $card['imgIconH'] : 0;
				if ( $ih < 40 ) { $ih = 88; } elseif ( $ih > 240 ) { $ih = 240; }
				$atts['icon_size'] = array( 'value' => (string) $ih, 'unit' => 'px' );
			} else {
				$atts['icon'] = array_merge( $atts['icon'], array( 'type' => 'custom-upload', 'url' => self::abs_asset( (string) $card['imgIcon'] ), 'attachment-id' => false ) );
			}
		} elseif ( ! empty( $card['lucide'] ) && is_array( $atts['icon'] ) ) {
			// Native Lucide → icon_box library icon (icon-v2 SVG source), preserving the atom's icon shape.
			$atts['icon'] = array_merge( $atts['icon'], array( 'type' => 'svg', 'svg-source' => 'library', 'svg-id' => (string) $card['lucide'] ) );
		} elseif ( ! empty( $card['customIcon'] ) ) {
			$atts['custom_icon'] = (string) $card['customIcon'];
		} elseif ( ! empty( $card['icon'] ) ) {
			$atts['icon'] = self::icon_value( (string) $card['icon'] );
		}
		// Icon position = the source card layout, detected geometrically in the capture (icon above
		// → top-title; icon beside the content → stack-left / stack-right). Field id is `style`.
		$valid_styles = array( 'top-title', 'inline-left', 'inline-right', 'stack-left', 'stack-right', 'between-title-content' );
		$layout = isset( $card['iconLayout'] ) ? (string) $card['iconLayout'] : 'top-title';
		$atts['style'] = in_array( $layout, $valid_styles, true ) ? $layout : 'top-title';

		// Icon color from the source (resolves inheritance) → the icon_box Icon Color, so it
		// matches the source instead of the shortcode's default preset color.
		$ic = isset( $card['iconColor'] ) ? trim( (string) $card['iconColor'] ) : '';
		$ic = preg_replace( '/\s*\/\s*var\([^)]*\)/', '', $ic ); // drop a trailing `/ var(--tw-text-opacity)` alpha channel
		if ( $ic !== '' && preg_match( '/^(#[0-9a-f]{3,8}|rgba?\([^)]*\)|hsla?\([^)]*\)|oklch\([^)]*\))$/i', $ic ) && stripos( $ic, 'transparent' ) === false ) {
			// A captured COMPUTED colour (rgb/hex/…) is authoritative — resolves ANY source token incl. a
			// custom `text-brand` the Tailwind config can't, so each card keeps its real icon colour
			// (green / yellow / green) instead of the shortcode's default preset.
			$atts['icon_color'] = array( 'predefined' => '', 'custom' => $ic );
		} elseif ( ! empty( $card['iconCls'] ) && self::$style_on && class_exists( 'FW_Site_Converter_Tailwind' ) ) {
			// The source icon's color is usually a Tailwind TOKEN class (e.g. `text-outline`), not a hex —
			// resolve it via the config so the icon matches the source instead of the shortcode's default
			// (green) preset. compile_class_set's `base` is the REST state, so a `group-hover:text-primary`
			// hover green is correctly ignored. Strip the opacity var() for a clean color value.
			$cm = FW_Site_Converter_Tailwind::compile_class_set( (string) $card['iconCls'], self::$style_cfg );
			if ( ! empty( $cm['base']['color'] ) ) {
				$col = preg_replace( '/\s*\/\s*var\([^)]*\)/', '', (string) $cm['base']['color'] );
				$atts['icon_color'] = array( 'predefined' => '', 'custom' => trim( $col ) );
			}
		}

		// Icon badge/chip: the source icon's filled container → icon_badge (shape) + icon_badge_color
		// (fill) — parity with the JS mapper. If a chip class is present, resolve its bg + radius via the
		// Tailwind compiler (like icon_color above); otherwise honour a pre-resolved value.
		if ( empty( $card['iconBadge'] ) && ! empty( $card['iconChipCls'] ) && self::$style_on && class_exists( 'FW_Site_Converter_Tailwind' ) ) {
			$chip = FW_Site_Converter_Tailwind::compile_class_set( (string) $card['iconChipCls'], self::$style_cfg );
			$bg   = isset( $chip['base']['background-color'] ) ? (string) $chip['base']['background-color'] : '';
			$bg   = trim( preg_replace( '/\s*\/\s*var\([^)]*\)/', '', $bg ) ); // drop the `/ var(--tw-bg-opacity,1)` alpha channel
			if ( '' !== $bg && 'transparent' !== $bg && ! preg_match( '/rgba\([^)]*,\s*0?\.?0*\)$/', $bg ) ) {
				$card['iconBadgeColor'] = $bg;
				$rad = isset( $chip['base']['border-radius'] ) ? (float) $chip['base']['border-radius'] : 0;
				$card['iconBadge'] = ( $rad >= 9999 ) ? 'solid-circle' : ( $rad > 0 ? 'solid-rounded' : 'solid-square' );
			}
		}
		if ( ! empty( $card['iconBadge'] ) ) {
			$atts['icon_badge'] = (string) $card['iconBadge'];
		}
		$ibc = isset( $card['iconBadgeColor'] ) ? trim( (string) $card['iconBadgeColor'] ) : '';
		if ( $ibc !== '' && preg_match( '/^(#[0-9a-f]{3,8}|rgba?\([^)]*\)|hsla?\([^)]*\))$/i', $ibc ) ) {
			$atts['icon_badge_color'] = array( 'predefined' => '', 'custom' => $ibc );
		}

		// Reproduce the SOURCE icon-chip's full DESIGN on the badge element as scoped CSS: the fill, radius,
		// SHADOW and SIZE. The icon_badge option renders a flat shape, so a source chip like
		// `w-16 h-16 bg-white rounded-2xl shadow-sm` (a white chip lifted off the card by its shadow) would
		// otherwise render as a bare icon — the shadow + dimensions are the "badge design". Uses the chip's
		// computed skin (authoritative) + its Tailwind size class; scoped to `.icon-box__icon` on this node.
		$chip_cs = (string) ( $card['iconChipCs'] ?? '' );
		if ( '' !== $chip_cs ) {
			$cd    = self::cs_decls( $chip_cs, array( 'background-color', 'border-radius', 'box-shadow' ) );
			$decls = array();
			$cbg2  = isset( $cd['background-color'] ) ? (string) $cd['background-color'] : '';
			if ( '' !== $cbg2 && 'transparent' !== $cbg2 && ! preg_match( '/rgba\([^)]*,\s*0?\.?0*\)$/', $cbg2 ) ) { $decls[] = 'background:' . $cbg2; }
			if ( ! empty( $cd['border-radius'] ) && '0px' !== $cd['border-radius'] ) { $decls[] = 'border-radius:' . $cd['border-radius']; }
			if ( ! empty( $cd['box-shadow'] ) && 'none' !== $cd['box-shadow'] ) { $decls[] = 'box-shadow:' . $cd['box-shadow']; }
			// Size from the chip's Tailwind w-N/h-N class (Tailwind unit = N × 0.25rem).
			$chip_cls = (string) ( $card['iconChipCls'] ?? '' );
			if ( preg_match( '/(?:^|\s)w-(\d+)(?:\s|$)/', $chip_cls, $wm ) ) {
				$sz = ( (int) $wm[1] * 0.25 ) . 'rem';
				$decls[] = 'width:' . $sz; $decls[] = 'height:' . $sz; $decls[] = 'min-height:' . $sz; $decls[] = 'flex:0 0 auto';
			}
			if ( $decls ) {
				$badge_css = 'selector .icon-box__icon{' . implode( ';', $decls ) . ';display:flex;align-items:center;justify-content:center;}';
				$atts['custom_css'] = trim( ( isset( $atts['custom_css'] ) ? (string) $atts['custom_css'] : '' ) . ' ' . $badge_css );
			}
		}

		// ALIGNMENT → the NATIVE icon_align / title_align / content_align options (editable controls), not a
		// baked `.sc-ib-left` CSS class. A left-aligned source card maps to 'left'; a centered one to 'center'.
		$align = empty( $card['center'] ) ? 'left' : 'center';
		if ( isset( $atts['icon_align'] ) )    { $atts['icon_align'] = $align; }
		if ( isset( $atts['title_align'] ) )   { $atts['title_align'] = $align; }
		if ( isset( $atts['content_align'] ) ) { $atts['content_align'] = $align; }
		// css_class now only carries the gray icon "image" box (if the source had one) — no alignment class.
		$ibcls = '';
		if ( ! empty( $card['iconBoxCls'] ) ) {
			$ibx = self::ib_iconbox_class( (string) $card['iconBoxCls'], (string) ( $card['iconBoxCs'] ?? '' ) );
			if ( '' !== $ibx ) { $ibcls = trim( $ibcls . ' ' . $ibx ); }
		}
		$atts['css_class'] = $ibcls;
		$atts['unique_id'] = self::uid();
		// CONVERSION DEBUG (best-effort) — the card's source classes that didn't become an option (its box
		// fill/border/radius went to the column Inner Wrapper Class + native icon/typography options).
		if ( ! empty( $card['cls'] ) ) {
			self::conv_debug_record( $atts['unique_id'], (string) $card['cls'], self::conv_dropped_diff( (string) $card['cls'], self::strip_inert_utilities( (string) $card['cls'] ) ) );
		}

		// Pass-1: the card's source vertical margin + INNER PADDING → the icon_box NATIVE spacing option
		// (editable). The card's `p-8` (its inner padding) was being dropped — a converted card looked cramped
		// vs the source's roomy 32px inset. Carry it so the padding is a real, editable control.
		$card_cs = (string) ( $card['cs'] ?? '' );
		if ( isset( $atts['spacing'] ) && is_array( $atts['spacing'] ) ) {
			$atts['spacing'] = self::apply_native_margin( $atts['spacing'], $card_cs );
			$atts['spacing'] = self::apply_native_padding( $atts['spacing'], $card_cs );
		}
		$node = array( 'type' => 'simple', 'shortcode' => 'icon_box', 'atts' => $atts, '_items' => array() );
		// Pass-2: faithful base of the card's REMAINING appearance. The card BOX (fill / border / radius /
		// shadow) is reproduced by the column's Inner Wrapper Class / box_style_class, and the icon colour +
		// title typography are native/themed — those are $already; the base only fills the rest (a decorative
		// background-image, letter-spacing, transform, …), so it never double-draws the card border.
		$node = self::apply_hifi_base( $node, $card_cs, array(
			'background-color', 'border', 'border-radius', 'box-shadow', 'color',
			'font-family', 'font-size', 'font-weight', 'line-height',
		) );
		return $node;
	}

	/** A column's content rebuilt as the reviewer-chosen role (overrides the auto-detected shortcode). */
	/**
	 * A source PRODUCT-CARD grid (each card = image + price [+ add-to-cart]) → the wc_products grid.
	 * WooCommerce owns the products and the converter can't know real product IDs from a static source,
	 * so it emits a placeholder grid (source: recent) to configure to your catalogue. Mirrors the JS
	 * to-pages wcProductsNode. A cell counts as a product when its HTML has an <img> AND a price token.
	 */
	private static function cell_is_product( array $c ) {
		$h = (string) ( $c['html'] ?? '' );
		return (bool) ( preg_match( '/<img/i', $h ) && preg_match( '/(?:\$|€|£)\s?\d+[.,]\d{2}/', $h ) );
	}
	// Tailwind's default box-shadow scale (for hover:shadow-* → CSS). Mirror of the JS TW_SHADOW.
	private static function tw_shadow( $name ) {
		$map = array(
			'sm'  => '0 1px 2px 0 rgba(0,0,0,.05)',
			'md'  => '0 4px 6px -1px rgba(0,0,0,.1), 0 2px 4px -2px rgba(0,0,0,.1)',
			'lg'  => '0 10px 15px -3px rgba(0,0,0,.1), 0 4px 6px -4px rgba(0,0,0,.1)',
			'xl'  => '0 20px 25px -5px rgba(0,0,0,.1), 0 8px 10px -6px rgba(0,0,0,.1)',
			'2xl' => '0 25px 50px -12px rgba(0,0,0,.25)',
		);
		return isset( $map[ $name ] ) ? $map[ $name ] : '';
	}
	/**
	 * Translate a captured product-card wrapper skin + hover + ribbon into scoped CSS for the
	 * wc_products grid (`.upwc-product` = card, `.upwc-product__badge.ribbon` = badge). Registered in
	 * the style aggregator (parity with the JS wcCardCss, which appends to the section custom_css —
	 * the PHP path emits carried CSS via the aggregator instead). Editable Custom CSS, no option bloat.
	 */
	private static function register_wc_card_css( $wrap, $ribbon ) {
		$css = '';
		if ( is_array( $wrap ) ) {
			$rest = array();
			if ( ! empty( $wrap['bg'] ) && ! preg_match( '/rgba?\(0, ?0, ?0, ?0\)|transparent/', $wrap['bg'] ) ) { $rest[] = 'background:' . $wrap['bg']; }
			if ( ! empty( $wrap['radius'] ) && (float) $wrap['radius'] > 0 ) { $rest[] = 'border-radius:' . $wrap['radius']; }
			if ( ! empty( $wrap['borderW'] ) && (float) $wrap['borderW'] > 0 ) { $rest[] = 'border:' . $wrap['borderW'] . ' ' . ( $wrap['borderStyle'] ? $wrap['borderStyle'] : 'solid' ) . ' ' . $wrap['borderColor']; }
			if ( ! empty( $wrap['shadow'] ) ) { $rest[] = 'box-shadow:' . $wrap['shadow']; }
			$has_hover = ! empty( $wrap['hoverShadow'] ) || ! empty( $wrap['hoverLift'] );
			if ( $has_hover ) { $rest[] = 'transition:transform .3s ease, box-shadow .3s ease'; }
			if ( $rest ) { $css .= '.upwc-products .upwc-product{' . implode( ';', $rest ) . '}'; }
			if ( $has_hover ) {
				$hv = array();
				if ( ! empty( $wrap['hoverShadow'] ) && self::tw_shadow( $wrap['hoverShadow'] ) ) { $hv[] = 'box-shadow:' . self::tw_shadow( $wrap['hoverShadow'] ); }
				if ( ! empty( $wrap['hoverLift'] ) ) { $hv[] = 'transform:translateY(-' . (int) round( (float) $wrap['hoverLift'] * 4 ) . 'px)'; }
				if ( $hv ) { $css .= '.upwc-products .upwc-product:hover{' . implode( ';', $hv ) . '}'; }
			}
		}
		if ( is_array( $ribbon ) ) {
			$r = array();
			if ( ! empty( $ribbon['bg'] ) ) { $r[] = 'background:' . $ribbon['bg']; }
			if ( ! empty( $ribbon['color'] ) ) { $r[] = 'color:' . $ribbon['color']; }
			if ( ! empty( $ribbon['radius'] ) && (float) $ribbon['radius'] > 0 ) { $r[] = 'border-radius:' . $ribbon['radius']; }
			if ( ! empty( $ribbon['padding'] ) ) { $r[] = 'padding:' . $ribbon['padding']; }
			if ( ! empty( $ribbon['fontSize'] ) ) { $r[] = 'font-size:' . $ribbon['fontSize']; }
			if ( ! empty( $ribbon['fontWeight'] ) ) { $r[] = 'font-weight:' . $ribbon['fontWeight']; }
			if ( ! empty( $ribbon['letterSpacing'] ) && 'normal' !== $ribbon['letterSpacing'] ) { $r[] = 'letter-spacing:' . $ribbon['letterSpacing']; }
			if ( ! empty( $ribbon['borderW'] ) && (float) $ribbon['borderW'] > 0 ) { $r[] = 'border:' . $ribbon['borderW'] . ' solid ' . $ribbon['borderColor']; }
			$r[] = 'text-transform:uppercase';
			if ( $r ) { $css .= '.upwc-products .upwc-product__badge.ribbon{' . implode( ';', $r ) . '}'; }
		}
		if ( '' !== $css ) { self::$style_css['upwc-card'] = $css; }
	}
	private static function n_wc_products( $cols, $count, $has_ribbon = false ) {
		$atts = self::shortcode_default_atts( 'wc_products' );
		if ( ! is_array( $atts ) ) { $atts = array(); }
		$atts['source']           = 'recent';
		$atts['category']         = '';
		$atts['posts_per_page']   = (string) ( $count ? $count : $cols );
		$atts['orderby']          = 'menu_order';
		$atts['order']            = 'ASC';
		$atts['layout']           = 'grid';
		$atts['columns']          = (string) $cols;
		$atts['gap']              = 'lg';
		$atts['show_price']       = 'yes';
		$atts['show_add_to_cart'] = 'yes';
		$atts['add_to_cart_text'] = 'Add to Cart';
		$atts['show_ribbon']      = $has_ribbon ? 'yes' : 'no';
		$atts['pagination']       = 'none';
		// The card is always assembled from these rows (parity with the JS mapper; the card_layout
		// Classic/Slot toggle was removed). The default four rows mirror the wc_products seed; empty
		// slots/rows collapse.
		$atts['card_rows']   = array(
			array( 'slots' => array( 'badges', 'wishlist' ),        'direction' => 'inline', 'justify' => 'between', 'align' => 'center' ),
			array( 'slots' => array( 'media', 'title', 'excerpt' ), 'direction' => 'stack',  'justify' => 'start',   'align' => 'center' ),
			array( 'slots' => array( 'rating', 'rating_count' ),    'direction' => 'inline', 'justify' => 'center',  'align' => 'center' ),
			array( 'slots' => array( 'price', 'cart' ),             'direction' => 'inline', 'justify' => 'between', 'align' => 'center' ),
		);
		return array( 'type' => 'simple', 'shortcode' => 'wc_products', 'atts' => $atts, '_items' => array() );
	}

	private static function cell_by_role( $role, array $c, $html ) {
		$txt = trim( wp_strip_all_tags( (string) $html ) );
		switch ( $role ) {
			case 'code':
				return array( self::n_code( (string) $html ) );
			case 'image':
				return array( self::n_media_image( (string) $html ) );
			case 'video':
				// Re-roled to video: use the cell's captured video block if present, else an empty
				// media_video the user fills in (never fall back to a raw <video> in a text/code block).
				return array( self::n_video( isset( $c['video'] ) && is_array( $c['video'] ) ? $c['video'] : array( 'mode' => 'embed' ) ) );
			case 'text':
				return array( self::n_text( $html !== '' ? (string) $html : '<p>' . esc_html( $txt ) . '</p>' ) );
			case 'overline':
				return array( self::n_heading( array( 'overline' => $txt, 'level' => 3 ) ) );
			case 'title':
				return array( self::n_heading( array( 'title' => $txt, 'level' => 2 ) ) );
			case 'subtitle':
				return array( self::n_heading( array( 'subtitle' => $txt, 'level' => 3 ) ) );
			case 'heading':
				return array( self::n_heading( array( 'title' => $txt, 'level' => 3 ) ) );
			case 'button':
				return array( self::n_button( $txt !== '' ? $txt : 'Button', '#' ) );
			default:
				if ( ! empty( $c['card'] ) ) { return array( self::n_icon_box( $c['card'] ) ); }
				return array( self::n_code( (string) $html ) );
		}
	}

	/** Flatten an icon-card to plain HTML (icon + heading + body) — used when a card is re-roled. */
	private static function card_to_html( array $card ) {
		$h = '';
		if ( ! empty( $card['customIcon'] ) ) { $h .= (string) $card['customIcon']; }
		elseif ( ! empty( $card['icon'] ) ) { $h .= '<i class="' . esc_attr( $card['icon'] ) . '"></i>'; }
		if ( ! empty( $card['title'] ) ) {
			$tag = ! empty( $card['titleTag'] ) ? preg_replace( '/[^a-z0-9]/', '', strtolower( $card['titleTag'] ) ) : 'h3';
			if ( $tag === '' ) { $tag = 'h3'; }
			$h .= '<' . $tag . '>' . esc_html( $card['title'] ) . '</' . $tag . '>';
		}
		if ( ! empty( $card['text'] ) ) { $h .= (string) $card['text']; }
		return $h;
	}

	/** Flatten a text-cell structure {overline,title,subtitle,paras} to plain HTML — for re-roling. */
	private static function text_cell_to_html( array $t ) {
		$h = '';
		if ( ! empty( $t['overline'] ) ) { $h .= '<p>' . esc_html( $t['overline'] ) . '</p>'; }
		if ( ! empty( $t['title'] ) ) { $h .= '<h2>' . esc_html( $t['title'] ) . '</h2>'; }
		if ( ! empty( $t['subtitle'] ) ) { $h .= '<p>' . esc_html( $t['subtitle'] ) . '</p>'; }
		foreach ( ( isset( $t['paras'] ) && is_array( $t['paras'] ) ? $t['paras'] : array() ) as $p ) { $h .= '<p>' . (string) $p . '</p>'; }
		return $h;
	}
	/** Keep meaningful source classes (utilities like text-uppercase/mb-3), drop animation noise. */
	private static function keep_classes( $cls ) {
		$out = array();
		foreach ( preg_split( '/\s+/', (string) $cls ) as $c ) {
			if ( $c === '' ) { continue; }
			// Drop ONLY genuine scroll-animation-library markers, matched as whole tokens / library
			// hooks — never as loose prefixes (the old /^(…|init|fade|slide|zoom)/ ate semantic names
			// like `slide-title`, `zoom-card`, `initiatives`, `faded-panel`, silently dropping the class).
			if (
				preg_match( '/^(wow|animated)$/i', $c )                        // animate.css base markers
				|| preg_match( '/^(aos|js|animate)-/i', $c )                    // aos-* / js-* / animate-* hooks
				|| preg_match( '/^animate__/i', $c )                           // animate.css v4
				|| preg_match( '/^(fade|slide|zoom|flip|bounce|init)(-(in|out|up|down|left|right|top|bottom|delay|duration))?$/i', $c )
			) { continue; }
			$out[] = $c;
		}
		return implode( ' ', array_unique( $out ) );
	}

	/**
	 * #3 — drop INERT Tailwind utility classes from a carried part-class list.
	 *
	 * A native heading/text element is NOT under the `.sc-tw` scope, so carried source utilities
	 * (`text-3xl`, `font-bold`, `text-lg`, spacing `mb-4`) resolve to nothing — and responsive
	 * variants arrive mangled (`md:text-4xl` → `mdtext-4xl`, `text-foreground/70` → `text-foreground70`)
	 * because `:`/`/` are stripped from class attributes, so they're pure noise. Their VISUAL intent
	 * (size / weight / family / color / spacing) is already reproduced by the per-node computed base
	 * (`:where(selector){…}` from data-sc-cs) and native options. So strip the typographic / spacing /
	 * sizing / generic-color utilities and their mangled responsive forms, but KEEP semantic ACCENT
	 * utilities (`text-primary` / `-secondary` / `-accent`, `fill-*`) — those resolve to the editable
	 * Color-Preset CSS variables — and KEEP any genuinely custom (non-Tailwind) class untouched.
	 *
	 * @param string $cls
	 * @return string
	 */
	private static function strip_inert_utilities( $cls ) {
		$keep = array();
		foreach ( preg_split( '/\s+/', (string) self::keep_classes( $cls ) ) as $c ) {
			if ( $c === '' ) { continue; }
			// KEEP semantic accent utilities (resolve to editable Color-Preset variables).
			if ( preg_match( '/^(text|fill|stroke|bg|border)-(primary|secondary|accent|tertiary)$/i', $c ) ) { $keep[] = $c; continue; }
			// DROP any Tailwind VARIANT-prefixed token (responsive/state) — inert on a native element, and
			// often arrives mangled (`md:text-4xl` → `mdtext-4xl`). Catches the clean colon form here.
			if ( preg_match( '/^(sm|md|lg|xl|2xl|hover|focus|active|group-hover|group-focus|focus-within|focus-visible|disabled|dark|motion-safe|motion-reduce|first|last|odd|even|before|after):/i', $c ) ) { continue; }
			// DROP inert Tailwind typographic / spacing / sizing / generic-color utilities…
			if ( preg_match( '#^text-(xs|sm|base|lg|xl|[0-9]xl|\[.*\]|left|center|right|justify|start|end|nowrap|wrap|balance|pretty|clip|ellipsis|foreground[0-9/]*|muted[0-9/]*|(?:gray|grey|slate|zinc|neutral|stone|white|black)[0-9/]*)$#i', $c )
				|| preg_match( '/^font-(thin|extralight|light|normal|medium|semibold|bold|extrabold|black|sans|serif|mono|heading|body|display)$/i', $c )
				|| preg_match( '/^(leading|tracking|indent)-(\d|\[|none|tight|snug|normal|relaxed|loose|wide|wider|widest|tighter)/i', $c )
				// Spacing/sizing: only when the value is number/auto/px/fraction/keyword — so a custom name
				// like `my-brand-title` (looks like margin-y) or `header-widget` is NOT mistaken for one.
				|| preg_match( '/^-?(m|p)[trblxyse]?-(\d|\[|auto|px|full|screen|min|max|fit|\d*\.?\d+)/i', $c )
				|| preg_match( '/^(max-w|min-w|max-h|min-h|w|h)-(\d|\[|auto|px|full|screen|min|max|fit|prose|none|\d*\.?\d+)/i', $c )
				|| preg_match( '/^(gap|space-[xy])-(\d|\[|px|\d*\.?\d+)/i', $c )
				|| preg_match( '/^opacity-(\d|\[)/i', $c )
				|| preg_match( '/^(antialiased|subpixel-antialiased|italic|not-italic|uppercase|lowercase|capitalize|normal-case|truncate|underline|line-through|no-underline)$/i', $c )
				// …and mangled responsive forms (`md:text-4xl` → `mdtext-4xl`, `lg:font-bold` → `lgfont-bold`).
				|| preg_match( '/^(sm|md|lg|xl|2xl)(text|font|leading|tracking|m[trblxyse]?|p[trblxyse]?|max|min|w|h|flex|grid|block|inline|hidden|gap|space|order|col|row|justify|items|content|self|opacity)\S*$/i', $c )
			) { continue; }
			$keep[] = $c; // unknown / genuinely custom class → keep it.
		}
		return implode( ' ', array_unique( $keep ) );
	}

	/**
	 * Rewrite a source "primary text color" utility inside inline HTML (e.g. the
	 * `<span class="text-color-primary">` highlight in a heading title) to the Primary
	 * COLOR PRESET's own text utility (`text-primary`). The preset engine emits
	 * `:root .text-primary { color: var(--color-primary) !important }` (see
	 * `framework/includes/css-tokens.php`), so accent text is driven by the Color Preset
	 * (= the captured brand accent) and updates automatically if the preset is edited — no
	 * hard-coded hex, no bespoke rule. Idempotent (`text-primary` stays `text-primary`).
	 *
	 * @param string $html
	 * @return string
	 */
	private static function map_accent_classes( $html ) {
		$html = (string) $html;
		if ( $html === '' ) { return $html; }
		// Strip CAPTURE-ONLY attributes first — the capture service stamps `data-sc-cs` / `data-sc-hover`
		// / `data-sc-*` on every element for the converter to READ; they must never survive into rendered
		// content (an inline `<span data-sc-cs="color:…;font-size:128px;…">` inside a heading leaks the
		// whole computed-style blob into the page). Their visual intent is already reproduced by the node's
		// options + faithful base, so once read they're pure noise.
		$html = self::strip_capture_attrs( $html );
		if ( stripos( $html, 'class' ) === false ) { return self::fold_inline_presentational( $html ); }
		$html = preg_replace( '/\b(?:text-color-primary|color-primary)\b/', 'text-primary', $html );
		// An arbitrary Tailwind color class (text-[#hex]) is DEAD in the builder (no Tailwind runtime)
		// → convert it to an inline color so the accent survives (parity with the JS richHeading fix).
		// A semantic/theme class (text-primary) is left alone. Palette classes (text-pink-600) are left
		// for the carried compiled CSS (they'd need the full palette map to inline here).
		$html = preg_replace_callback( '/class="([^"]*)"/', function ( $m ) {
			if ( preg_match( '/text-\[(#[0-9a-fA-F]{3,8})\]/', $m[1], $c ) ) {
				$rest = trim( preg_replace( '/\s*text-\[#[0-9a-fA-F]{3,8}\]\s*/', ' ', $m[1] ) );
				return ( $rest !== '' ? 'class="' . $rest . '" ' : '' ) . 'style="color:' . $c[1] . '"';
			}
			return $m[0];
		}, $html );
		// Resolve the source SEMANTIC colour utilities (text-primary / text-secondary /
		// text-foreground / fill-primary…) to CONCRETE inline colours from the extracted palette, so
		// the two-tone heading span AND the hand-drawn underline `<svg><path stroke="currentColor">`
		// render the brand accents on the PAGE BODY — the `.text-primary { color: … }` CSS the source
		// relies on only lives under the `.sc-tw` chrome scope (not on the body), so on the body those
		// classes would otherwise inherit BLACK. See resolve_color_classes().
		$html = self::resolve_color_classes( $html );
		// Fold PRESENTATIONAL-ONLY utility classes (italic / font-weight / decoration / transform) that are
		// DEAD in the builder (no Tailwind runtime) into an equivalent inline style, so a two-tone heading's
		// `<span class="italic font-normal">` keeps its italic + weight instead of losing them silently.
		$html = self::fold_inline_presentational( $html );
		return $html;
	}

	/** Remove capture-only `data-sc-*` attributes (double/single quoted) from carried inline HTML. */
	private static function strip_capture_attrs( $html ) {
		$html = (string) $html;
		if ( false === stripos( $html, 'data-sc-' ) ) { return $html; }
		$html = preg_replace( '/\s+data-sc-[a-z0-9-]+="[^"]*"/i', '', $html );
		$html = preg_replace( "/\s+data-sc-[a-z0-9-]+='[^']*'/i", '', $html );
		return (string) $html;
	}

	/**
	 * Convert PRESENTATIONAL-ONLY Tailwind utilities on carried inline elements into an equivalent inline
	 * `style` (merged additively into any existing style), then drop the now-inert tokens from `class`
	 * (dropping the attribute entirely when nothing else remains). Only italic / font-weight-name /
	 * text-decoration / text-transform utilities are folded — they have no native builder equivalent and
	 * would otherwise render nothing (no Tailwind runtime on a native element). Anything else is left on
	 * the class untouched. Per-tag + style-merge mirrors resolve_color_classes so two folds never collide.
	 */
	private static function fold_inline_presentational( $html ) {
		$html = (string) $html;
		if ( '' === $html || stripos( $html, 'class="' ) === false ) { return $html; }
		$wmap = array( 'thin' => '100', 'extralight' => '200', 'light' => '300', 'normal' => '400', 'medium' => '500', 'semibold' => '600', 'bold' => '700', 'extrabold' => '800', 'black' => '900' );
		return preg_replace_callback( '/<[a-zA-Z][a-zA-Z0-9]*\b[^>]*\bclass="[^"]*"[^>]*>/', function ( $tag ) use ( $wmap ) {
			$whole = $tag[0];
			if ( ! preg_match( '/\bclass="([^"]*)"/', $whole, $cm ) ) { return $whole; }
			$keep  = array();
			$decls = array();
			foreach ( preg_split( '/\s+/', trim( $cm[1] ) ) as $c ) {
				if ( '' === $c ) { continue; }
				$l = strtolower( $c );
				if ( 'italic' === $l )            { $decls['font-style'] = 'italic'; }
				elseif ( 'not-italic' === $l )    { $decls['font-style'] = 'normal'; }
				elseif ( 'underline' === $l )     { $decls['text-decoration'] = 'underline'; }
				elseif ( 'line-through' === $l )  { $decls['text-decoration'] = 'line-through'; }
				elseif ( 'no-underline' === $l )  { $decls['text-decoration'] = 'none'; }
				elseif ( 'uppercase' === $l )     { $decls['text-transform'] = 'uppercase'; }
				elseif ( 'lowercase' === $l )     { $decls['text-transform'] = 'lowercase'; }
				elseif ( 'capitalize' === $l )    { $decls['text-transform'] = 'capitalize'; }
				elseif ( preg_match( '/^font-(thin|extralight|light|normal|medium|semibold|bold|extrabold|black)$/', $l, $w ) ) { $decls['font-weight'] = $wmap[ $w[1] ]; }
				else { $keep[] = $c; }
			}
			if ( ! $decls ) { return $whole; }
			$decl_str = '';
			foreach ( $decls as $k => $v ) { $decl_str .= $k . ':' . $v . ';'; }
			$new_class = trim( implode( ' ', $keep ) );
			$whole = preg_replace( '/\s*\bclass="[^"]*"/', ( '' !== $new_class ? ' class="' . $new_class . '"' : '' ), $whole, 1 );
			if ( preg_match( '/\bstyle="[^"]*"/', $whole ) ) {
				return preg_replace_callback( '/\bstyle="([^"]*)"/', function ( $sm ) use ( $decl_str ) {
					$ex = rtrim( trim( $sm[1] ), ';' );
					return 'style="' . ( '' !== $ex ? $ex . ';' : '' ) . rtrim( $decl_str, ';' ) . '"';
				}, $whole, 1 );
			}
			return substr( $whole, 0, -1 ) . ' style="' . rtrim( $decl_str, ';' ) . '">';
		}, $html );
	}

	/**
	 * A source semantic colour token ('primary'/'secondary'/'foreground'/'white'/'black') → its
	 * concrete hex from the extracted palette ($style_cfg['colors'], populated via set_style_config()
	 * from FW_Site_Converter_Tailwind::extract_semantic_colors()), or '' when the palette has no real
	 * value for it. Pure/near-pure black is REJECTED for the accent tokens (a black "accent" means the
	 * extraction failed — resolving to black would mask nothing and we'd rather leave the class alone).
	 */
	private static function color_token_hex( $token ) {
		if ( $token === 'white' ) { return '#ffffff'; }
		if ( $token === 'black' ) { return '#000000'; }
		$cols = ( isset( self::$style_cfg['colors'] ) && is_array( self::$style_cfg['colors'] ) ) ? self::$style_cfg['colors'] : array();
		$hex  = isset( $cols[ $token ] ) ? trim( (string) $cols[ $token ] ) : '';
		if ( $hex === '' ) { return ''; }
		// Normalise rgb()/rgba() → #hex so it's usable inline.
		if ( stripos( $hex, 'rgb' ) === 0 && preg_match( '/rgba?\(\s*(\d{1,3})[,\s]+(\d{1,3})[,\s]+(\d{1,3})/', $hex, $mm ) ) {
			$hex = sprintf( '#%02x%02x%02x', (int) $mm[1], (int) $mm[2], (int) $mm[3] );
		}
		if ( ! preg_match( '/^#[0-9a-fA-F]{3,8}$/', $hex ) ) { return ''; }
		// Reject an accent that resolves to (near-)black — extraction miss, not a real accent.
		if ( preg_match( '/^#(000|000000)$/i', $hex ) ) { return ''; }
		return $hex;
	}

	/**
	 * Resolve the source's SEMANTIC colour utility classes (`text-primary`, `text-secondary`,
	 * `text-foreground`, `text-white`/`text-black`, and the SVG `fill-primary`/`fill-secondary`…)
	 * to CONCRETE inline `color:`/`fill:` declarations MERGED additively onto the element carrying
	 * the class. The source class is KEPT (harmless, and preserves golden assertions that check the
	 * class survives) — the inline style is what actually paints on the page body, independent of the
	 * `.sc-tw`-scoped CSS that never lands on the body. Idempotent: unknown / unpalettable classes are
	 * left untouched, and a second pass is a no-op (the classes stay but the palette lookup is stable).
	 *
	 * The key case is the two-tone hero heading: `<span class="text-primary …">Second Home <svg
	 * class="… text-secondary"><path stroke="currentColor" …></svg></span>` — the span gets
	 * `color:<primary>` (green) and the underline svg gets `color:<secondary>` (amber) so its
	 * `stroke="currentColor"` strokes amber instead of inheriting black.
	 *
	 * @param string $html verbatim-carried heading/accent HTML
	 * @return string
	 */
	private static function resolve_color_classes( $html ) {
		$html = (string) $html;
		if ( $html === '' || stripos( $html, 'class' ) === false ) { return $html; }
		return preg_replace_callback( '/<[a-zA-Z][a-zA-Z0-9]*\b[^>]*\bclass="[^"]*"[^>]*>/', function ( $tag ) {
			$whole = $tag[0];
			if ( ! preg_match( '/\bclass="([^"]*)"/', $whole, $cm ) ) { return $whole; }
			$color = ''; $fill = '';
			foreach ( preg_split( '/\s+/', trim( $cm[1] ) ) as $c ) {
				if ( preg_match( '/^text-(primary|secondary|foreground|white|black)$/', $c, $t ) ) {
					$h = self::color_token_hex( $t[1] );
					if ( $h !== '' && $color === '' ) { $color = $h; }
				} elseif ( preg_match( '/^fill-(primary|secondary|foreground|white|black)$/', $c, $t ) ) {
					$h = self::color_token_hex( $t[1] );
					if ( $h !== '' && $fill === '' ) { $fill = $h; }
				}
			}
			if ( $color === '' && $fill === '' ) { return $whole; }
			$decls = '';
			if ( $color !== '' ) { $decls .= 'color:' . $color . ';'; }
			if ( $fill !== '' )  { $decls .= 'fill:' . $fill . ';'; }
			// Merge into an existing style="" additively; else add one before the closing '>'.
			if ( preg_match( '/\bstyle="[^"]*"/', $whole ) ) {
				return preg_replace_callback( '/\bstyle="([^"]*)"/', function ( $sm ) use ( $decls ) {
					$ex = rtrim( trim( $sm[1] ), ';' );
					return 'style="' . ( $ex !== '' ? $ex . ';' : '' ) . $decls . '"';
				}, $whole, 1 );
			}
			return substr( $whole, 0, -1 ) . ' style="' . $decls . '">';
		}, $html );
	}

	/**
	 * Convert Bootstrap grid utility classes in raw HTML (e.g. a captured gallery grid that
	 * lands in a code-block) to the page-builder's OWN grid classes, which the theme styles:
	 * `row`→`fw-row`, `container`→`fw-container`, `container-fluid`→`fw-container-fluid`,
	 * `col-{bp}-{n}`→`fw-col-{bp}-{n}` (xs→sm, xl/xxl→lg — the fw grid tops out at lg). A
	 * phone-base `fw-col-12` is prepended when a cell has breakpoint cols but no base, matching
	 * the builder's own emitted columns (full-width on phone). Class-attribute aware, whole-token.
	 *
	 * @param string $html
	 * @return string
	 */
	private static function fwgrid_classes( $html ) {
		$html = (string) $html;
		if ( $html === '' || stripos( $html, 'class' ) === false ) { return $html; }
		return preg_replace_callback( '/\bclass="([^"]*)"/i', function ( $m ) {
			$out = array();
			$has_col = false;
			$has_base = false;
			foreach ( preg_split( '/\s+/', trim( $m[1] ) ) as $c ) {
				if ( $c === '' ) { continue; }
				if ( $c === 'row' ) { $out[] = 'fw-row'; continue; }
				if ( $c === 'container' ) { $out[] = 'fw-container'; continue; }
				if ( $c === 'container-fluid' ) { $out[] = 'fw-container-fluid'; continue; }
				if ( preg_match( '/^col-(xs|sm|md|lg|xl|xxl)-(\d{1,2})$/', $c, $mm ) ) {
					$bp = $mm[1];
					if ( $bp === 'xs' ) { $bp = 'sm'; }
					if ( $bp === 'xl' || $bp === 'xxl' ) { $bp = 'lg'; }
					$out[] = 'fw-col-' . $bp . '-' . $mm[2];
					$has_col = true;
					continue;
				}
				if ( preg_match( '/^col-(\d{1,2})$/', $c, $mm ) ) { $out[] = 'fw-col-sm-' . $mm[1]; $has_col = true; continue; }
				if ( $c === 'col' ) { $out[] = 'fw-col-sm'; $has_col = true; continue; }
				if ( $c === 'fw-col-12' ) { $has_base = true; }
				$out[] = $c;
			}
			if ( $has_col && ! $has_base ) { array_unshift( $out, 'fw-col-12' ); }
			return 'class="' . implode( ' ', $out ) . '"';
		}, $html );
	}

	/** h1..h6 tag → numeric heading level (default 2). */
	private static function tag_level( $tag ) {
		$tag = strtolower( (string) $tag );
		return ( preg_match( '/^h([1-6])$/', $tag, $m ) ) ? (int) $m[1] : 2;
	}

	/** A text cell (overline + heading + paragraphs) → a special_heading + any extra text blocks. */
	private static function n_text_cell( array $t ) {
		$items = array();
		$items[] = self::n_heading( array(
			'overline' => (string) ( $t['overline'] ?? '' ),
			'title'    => (string) ( $t['title'] ?? '' ),
			'subtitle' => (string) ( $t['subtitle'] ?? '' ),
			'level'    => self::tag_level( $t['titleTag'] ?? 'h2' ),
			'align'    => '', // inherit — source text cells follow the theme/parent, no text-start forced
			// Each source part's classes → the matching Overline/Title/Subtitle Class fields.
			'overline_class' => (string) ( $t['overlineClass'] ?? '' ),
			'title_class'    => (string) ( $t['titleClass'] ?? '' ),
			'subtitle_class' => (string) ( $t['subtitleClass'] ?? '' ),
			// A semantic heading-group wrapper (source `<div class="heading">`) → the
			// special_heading's own wrapper class (css_class), so the wrapper renders + carries it.
			'css_class' => (string) ( $t['wrapClass'] ?? '' ),
		) );
		foreach ( ( isset( $t['paras'] ) && is_array( $t['paras'] ) ? $t['paras'] : array() ) as $p ) {
			if ( trim( (string) $p ) !== '' ) { $items[] = self::n_text( (string) $p ); }
		}
		return $items;
	}

	/**
	 * Build a DECOMPOSED layout-row cell's blocks into stacked page-builder items — the SAME per-block
	 * decomposition the single-column section path uses: overline/title/subtitle fold into ONE
	 * special_heading, everything else routes through the block-builder registry (button / text / image /
	 * code…), and consecutive buttons group into a side-by-side row. Lets a hero's LEFT column render as
	 * special_heading + button×N instead of a single opaque code_block.
	 *
	 * @param array $blocks role-annotated blocks (from stitch collect_blocks on the cell)
	 * @return array page-builder item nodes
	 */
	/**
	 * PRE-PASS — a BADGE chip immediately FOLLOWED BY A HEADING is that heading's eyebrow/OVERLINE, not a
	 * floating component. Walk the blocks; for each `badge`, find the next MEANINGFUL block (skip
	 * include===false / role 'skip'); if it is a 'title'/'heading', transform the badge IN PLACE into an
	 * `overline` block (text = tag_text + message, plus the pill metadata `overline_svg` / `overline_pill` /
	 * `overline_color`) so the existing overline-merge branch folds it into the special_heading — matching
	 * the JS capture-service path, which already renders the hero pill as the overline. A chip with NO
	 * heading after it is left untouched → stays a standalone `badge`.
	 */
	private static function transform_badge_overlines( array $blocks ) {
		$keys = array_keys( $blocks );
		$n    = count( $keys );
		for ( $i = 0; $i < $n; $i++ ) {
			$b = $blocks[ $keys[ $i ] ];
			if ( ! is_array( $b ) || 'badge' !== (string) ( $b['role'] ?? '' ) ) { continue; }
			// Next meaningful block (skip omitted / skip).
			$next = null;
			for ( $j = $i + 1; $j < $n; $j++ ) {
				$nb = $blocks[ $keys[ $j ] ];
				if ( ! is_array( $nb ) ) { continue; }
				if ( isset( $nb['include'] ) && ! $nb['include'] ) { continue; }
				if ( 'skip' === (string) ( $nb['role'] ?? '' ) ) { continue; }
				$next = $nb; break;
			}
			if ( null === $next ) { continue; }
			if ( ! in_array( (string) ( $next['role'] ?? '' ), array( 'title', 'heading' ), true ) ) { continue; }

			$tag_text = trim( (string) ( $b['tag_text'] ?? '' ) );
			$message  = trim( (string) ( $b['message'] ?? '' ) );
			$text     = '' !== $tag_text ? trim( $tag_text . ' ' . $message ) : $message;
			$color    = '';
			$pill_cs  = (string) ( $b['pillCs'] ?? $b['cs'] ?? '' );
			if ( '' !== $pill_cs ) {
				$cd = self::cs_decls( $pill_cs, array( 'color' ) );
				if ( isset( $cd['color'] ) && '' !== $cd['color'] ) { $color = $cd['color']; }
			}
			$b['role']           = 'overline';
			$b['text']           = $text;
			$b['cls']            = (string) ( $b['msgCls'] ?? $b['cls'] ?? '' );
			$b['overline_svg']   = (string) ( $b['leadingSvg'] ?? '' );
			$b['overline_pill']  = true;
			$b['overline_color'] = $color;
			$blocks[ $keys[ $i ] ] = $b;
		}
		return $blocks;
	}

	private static function build_cell_items( array $blocks ) {
		$blocks = self::transform_badge_overlines( $blocks ); // chip-before-heading → overline
		$items = array();
		$head  = null;
		$flush_head = function () use ( &$head, &$items ) {
			if ( $head !== null ) {
				// Skip a head with NO title, NO overline, whose only content is a LINK subtitle — that's a
				// mis-folded CTA (a "View All →" link beside a section heading became an empty-title
				// special_heading whose subtitle = the link, duplicating the real link). A title-less head with
				// a PLAIN-text subtitle is still legitimate, so keep those. (Parity to add in JS to-pages.)
				if ( ! self::head_is_stray_link( $head ) ) { $items[] = self::n_heading( $head ); }
				$head = null;
			}
		};
		foreach ( $blocks as $b ) {
			if ( ! is_array( $b ) ) { continue; }
			if ( isset( $b['include'] ) && ! $b['include'] ) { continue; }
			$role = isset( $b['role'] ) ? (string) $b['role'] : 'code';
			if ( $role === 'skip' ) { continue; }

			// A SHORT intro paragraph right after a title folds into the heading's SUBTITLE (brevity-guarded).
			// build_cell_items() handles DECOMPOSED content columns (e.g. the hero), so this is what makes the
			// hero's "Fresh, fun, and safe boarding…" line become the subtitle instead of a standalone Text
			// Block. Same routing as the section loop; parity mirrored in JS to-pages coalesceHeadingGroups.
			if ( $role === 'text' && $head !== null && '' !== (string) ( $head['title'] ?? '' ) && '' === (string) ( $head['subtitle'] ?? '' )
				&& self::is_heading_subtitle( $b ) ) {
				$role = 'subtitle';
			}
			if ( in_array( $role, array( 'overline', 'title', 'subtitle' ), true ) ) {
				// title + subtitle keep INLINE html (links / <strong> / <em>); a subtitle's outer <p> is stripped.
				$val   = in_array( $role, array( 'title', 'subtitle' ), true )
					? self::heading_part_inline_html( (string) ( $b['html'] ?? $b['text'] ?? '' ), $role )
					: trim( (string) ( $b['text'] ?? '' ) );
				$fresh = array( 'overline' => '', 'title' => '', 'subtitle' => '', 'overline_class' => '', 'title_class' => '', 'subtitle_class' => '', 'css_class' => '', 'level' => (int) ( $b['level'] ?? 2 ), 'align' => $b['align'] ?? '', 'wrapMaxW' => (string) ( $b['wrapMaxW'] ?? '' ) );
				if ( $head === null ) { $head = $fresh; }
				if ( $head[ $role ] !== '' ) { $flush_head(); $head = $fresh; }
				$head[ $role ]            = $val;
				$head[ $role . '_class' ] = (string) ( $b['cls'] ?? '' );
				// Carry chip-before-heading pill metadata onto the head so n_heading emits the filled-pill overline.
				if ( 'overline' === $role ) {
					if ( isset( $b['overline_svg'] ) )   { $head['overline_svg']   = (string) $b['overline_svg']; }
					if ( ! empty( $b['overline_pill'] ) ) { $head['overline_pill']  = true; }
					if ( isset( $b['overline_color'] ) ) { $head['overline_color'] = (string) $b['overline_color']; }
				}
				if ( isset( $b['cs'] ) && '' !== (string) $b['cs'] ) {
					$cw = self::cs_decls( (string) $b['cs'], array( 'font-weight', 'font-size', 'color', 'text-transform' ) );
					if ( isset( $cw['font-weight'] ) ) { $head[ $role . '_weight' ] = $cw['font-weight']; }
					// Capture the part's computed font-size (px) so n_heading can assign the matching Text
					// Style preset (e.g. an 18px subtitle → the `font-subtitle` preset) instead of losing its
					// size when the source utility class is stripped.
					if ( isset( $cw['font-size'] ) && preg_match( '/^([0-9.]+)px$/', (string) $cw['font-size'], $fm ) ) {
						$head[ $role . '_fs' ] = (float) $fm[1];
					}
					// Capture the part's computed COLOR so n_heading can set the native subtitle/title/overline
					// color option — a muted subtitle (e.g. text-foreground/70 → rgba(41,61,54,.7)) was rendering
					// at the theme default because the color was dropped.
					if ( isset( $cw['color'] ) && trim( (string) $cw['color'] ) !== '' ) {
						$head[ $role . '_color_src' ] = trim( (string) $cw['color'] );
					}
					// OVERLINE: restore the gold + UPPERCASE + letter-spaced kicker from the computed style —
					// overline_color (accent) + overline_transform (→ overline_uppercase='yes' in n_heading). See
					// the parallel merge branch for the full rationale.
					if ( 'overline' === $role ) {
						if ( empty( $head['overline_color'] ) && isset( $cw['color'] ) && trim( (string) $cw['color'] ) !== '' && ! self::is_default_ink( (string) $cw['color'] ) ) {
							$head['overline_color'] = trim( (string) $cw['color'] );
						}
						if ( isset( $cw['text-transform'] ) && trim( (string) $cw['text-transform'] ) !== '' ) {
							$head['overline_transform'] = strtolower( trim( (string) $cw['text-transform'] ) );
						}
					}
				}
				if ( $role === 'title' && isset( $b['cs'] ) && '' !== (string) $b['cs'] ) {
					// TITLE computed bottom margin → the title→subtitle gap (never-drop). `mb-8` is stripped
					// from title_class, so read the resolved px from the computed style; n_heading carries it
					// verbatim as scoped `.heading-title{margin-bottom:Npx}`. (Mirror in JS to-pages.)
					$mb = self::cs_margin_bottom_px( (string) $b['cs'] );
					if ( $mb !== null ) { $head['title_mb_px'] = $mb; }
				}
				if ( $role === 'title' ) {
					$head['level'] = (int) ( $b['level'] ?? 2 );
					$head['align'] = $b['align'] ?? $head['align'];
					if ( ! empty( $b['wrapCls'] ) ) { $head['css_class'] = (string) $b['wrapCls']; }
					if ( ! empty( $b['wrapMaxW'] ) ) { $head['wrapMaxW'] = (string) $b['wrapMaxW']; }
				}
				continue;
			}
			$flush_head();

			// Native `$b['t']` blocks (a <ul> checklist → feature_list, a <table>, an accordion, tabs, …) carry
			// NO `role`, so the role-router below would dump them into an EMPTY code block (they have no `html`).
			// Dispatch them here — mirroring the section loop — so e.g. the hero's check-icon list renders as a
			// feature_list instead of vanishing.
			$bt = isset( $b['t'] ) ? (string) $b['t'] : '';
			if ( $bt === 'feature_list' && ! empty( $b['items'] ) && is_array( $b['items'] ) ) {
				$node = self::n_feature_list( $b ); self::apply_block_anim( $node, $b ); $items[] = $node; continue;
			}
			$cell_native = array( 'table' => 'n_table', 'accordion' => 'n_accordion', 'tabs' => 'n_tabs', 'steps' => 'n_steps', 'timeline' => 'n_timeline', 'progress' => 'n_progress', 'pricing' => 'n_pricing', 'gallery' => 'n_gallery' );
			if ( isset( $cell_native[ $bt ] ) && method_exists( __CLASS__, $cell_native[ $bt ] ) ) {
				$node = call_user_func( array( __CLASS__, $cell_native[ $bt ] ), $b );
				if ( is_array( $node ) ) { self::apply_block_anim( $node, $b ); $items[] = $node; }
				continue;
			}

			$builders = self::builders();
			$bld      = isset( $builders[ $role ] ) ? $builders[ $role ] : $builders['code'];
			$item     = call_user_func( $bld['build'], $b );
			if ( $item !== null ) { $items[] = $item; }
		}
		$flush_head();
		return self::group_buttons( $items ); // consecutive buttons → one side-by-side flex-row group
	}

	/** Empty `spacing` composite value (margin + padding subtrees). */
	private static function empty_spacing() {
		return array(
			'margin'  => array( 'all' => '', 'top' => '', 'right' => '', 'bottom' => '', 'left' => '' ),
			'padding' => array( 'all' => '', 'top' => '', 'right' => '', 'bottom' => '', 'left' => '' ),
			'advanced' => array(),
		);
	}

	/** Snap a rem length to the nearest UnysonPlus spacing-scale slug (mirrors the JS to-pages map). */
	private static function rem_to_spacing_slug( $rem ) {
		$scale = array( array( 0, '0' ), array( 0.25, '1' ), array( 0.5, '2' ), array( 1, '3' ), array( 1.5, '4' ), array( 3, '5' ), array( 3.5, '6' ), array( 4, '7' ), array( 4.5, '8' ), array( 5, '9' ), array( 6, '10' ), array( 7, '11' ), array( 8, '12' ) );
		$best = '0'; $bd = INF;
		foreach ( $scale as $e ) { $d = abs( $e[0] - $rem ); if ( $d < $bd ) { $bd = $d; $best = $e[1]; } }
		return $best;
	}

	/**
	 * Pass #5 SPACING-SCALE DISTILLATION — a px length → the spacing-scale utility CLASS for `$prefix`
	 * (pt/pb/mt/mb…). An EXACT scale step (within 1px) becomes the clean, named slug (`pt-3`); an
	 * off-scale value becomes a LOSSLESS Tailwind ARBITRARY value (`pt-[96px]`) which the shortcodes'
	 * per-page dynamic CSS renders exactly and the spacing dropdown lists as an editable option. This is
	 * the DISTILLATION that snaps the source's measured rhythm onto the shared scale without a ±12px
	 * snap error. Byte-for-byte mirror of the JS to-pages spacingToken(); the scale = SPACING_SCALE (JS)
	 * = rem_to_spacing_slug()'s ladder (×16 → px).
	 *
	 * @param string $prefix the utility prefix (pt/pb/mt/mb/pl/pr…)
	 * @param float  $px      the measured length in pixels
	 * @return string the utility class (e.g. `pt-3` or `pt-[96px]`)
	 */
	private static function spacing_token( $prefix, $px ) {
		$px    = (int) round( (float) $px );
		$scale = array( array( 0, '0' ), array( 4, '1' ), array( 8, '2' ), array( 16, '3' ), array( 24, '4' ), array( 48, '5' ), array( 56, '6' ), array( 64, '7' ), array( 72, '8' ), array( 80, '9' ), array( 96, '10' ), array( 112, '11' ), array( 128, '12' ) );
		foreach ( $scale as $e ) { if ( abs( $e[0] - $px ) <= 1 ) { return $prefix . '-' . $e[1]; } }
		return $prefix . '-[' . $px . 'px]';
	}

	/**
	 * Pass #6 — PER-BREAKPOINT RESPONSIVE CARRY (visibility).
	 *
	 * Derive the native `responsive_hide` selection (hide-xs / hide-sm / hide-md — rendered onto the
	 * wrapper by the global sc_build_wrapper_attr filter and backed by builder/static/css/frontend-grid.css)
	 * from a source element's Tailwind responsive VISIBILITY utilities that are ALREADY in the carried
	 * markup. Class-derived only — NO extra capture pass, NO body-wide CSS, NO invented option.
	 *
	 * Native tiers: hide-xs = <768, hide-sm = 768–991, hide-md = ≥992. Tailwind's ladder (sm 640 /
	 * md 768 / lg 1024 / xl 1280) is snapped onto them approximately. Two clean, unambiguous single-toggle
	 * families are recognised; anything else returns array() (no guess):
	 *   A) base `hidden` + `{bp}:{display}`  → visible only from {bp} up → HIDE BELOW {bp}
	 *        sm/md → hide-xs ; lg/xl/2xl → hide-xs + hide-sm
	 *   B) base visible + `{bp}:hidden`      → hidden from {bp} up
	 *        sm/md → hide-sm + hide-md ; lg/xl/2xl → hide-md
	 * A bare `hidden` with NO responsive un-hide is deliberately IGNORED (that's a fully removed element,
	 * not a per-breakpoint change — carrying it would silently drop content). A class set that BOTH hides
	 * and re-shows at different bps (`hidden md:flex lg:hidden`) is ambiguous → skipped.
	 *
	 * @param string $cls a source element's raw class attribute
	 * @return array responsive_hide-shaped map ({ 'hide-xs' => true, … }) or array() when there's no clear toggle
	 */
	public static function responsive_hide_from_classes( $cls ) {
		$cls = ' ' . strtolower( trim( preg_replace( '/\s+/', ' ', (string) $cls ) ) ) . ' ';
		if ( trim( $cls ) === '' ) { return array(); }
		$disp        = 'block|flex|grid|inline|inline-block|inline-flex|table|inline-table|flow-root|contents';
		$base_hidden = (bool) preg_match( '/ hidden /', $cls );
		$has_show    = (bool) preg_match( '/ (sm|md|lg|xl|2xl):(' . $disp . ') /', $cls );
		$has_bphide  = (bool) preg_match( '/ (sm|md|lg|xl|2xl):hidden /', $cls );

		// Family A — hide below the breakpoint (base hidden, re-shown from {bp} up). Skip if it ALSO
		// re-hides at a larger bp (ambiguous mid-band-only visibility).
		if ( $base_hidden && $has_show && ! $has_bphide && preg_match( '/ (sm|md|lg|xl|2xl):(' . $disp . ') /', $cls, $m ) ) {
			return ( 'sm' === $m[1] || 'md' === $m[1] )
				? array( 'hide-xs' => true )
				: array( 'hide-xs' => true, 'hide-sm' => true );
		}
		// Family B — hide from the breakpoint up (base visible). Skip if it ALSO re-shows at a larger bp.
		if ( ! $base_hidden && $has_bphide && ! $has_show && preg_match( '/ (sm|md|lg|xl|2xl):hidden /', $cls, $m ) ) {
			return ( 'sm' === $m[1] || 'md' === $m[1] )
				? array( 'hide-sm' => true, 'hide-md' => true )
				: array( 'hide-md' => true );
		}
		return array();
	}

	/**
	 * Translate a heading-group wrapper's Tailwind LAYOUT/SPACING classes into NATIVE special_heading
	 * options (parity with the JS to-pages headingNode) — otherwise they sit dead on css_class (no
	 * Tailwind runtime in the builder) and the heading renders with the wrong spacing. Returns the
	 * native atts + the leftover (unmapped) class string.
	 */
	/**
	 * Is this text block a heading SUBTITLE (a short intro line) rather than body copy? A subtitle is a
	 * single short paragraph — no block-level structure (lists, sub-headings, tables, multiple paragraphs)
	 * and under a two-sentence length cap. Longer / structured copy stays a Text Block (the design intent:
	 * "keep it to a sentence or two; for longer copy use a Text Block").
	 */
	private static function is_heading_subtitle( $b ) {
		$html = (string) ( $b['html'] ?? $b['text'] ?? '' );
		if ( preg_match( '/<(ul|ol|h[1-6]|table|blockquote|figure|hr|div)\b/i', $html ) ) { return false; }
		if ( preg_match_all( '/<p\b/i', $html ) > 1 ) { return false; } // more than one paragraph = body copy
		$plain = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $html ) ) );
		if ( $plain === '' ) { return false; }
		return mb_strlen( $plain ) <= 220; // a sentence or two; longer reads as body copy
	}

	/**
	 * Inline HTML for a heading part. Title is returned verbatim; a subtitle's single outer <p>…</p> wrapper
	 * is unwrapped (the view re-wraps it in <p class="heading-subtitle">) so only its inline content —
	 * links / <strong> / <em> / <br> — carries into the subtitle field.
	 */
	private static function heading_part_inline_html( $html, $role ) {
		$html = (string) $html;
		if ( $role !== 'subtitle' ) { return $html; }
		if ( preg_match( '/^\s*<p\b[^>]*>([\s\S]*)<\/p>\s*$/i', $html, $m ) ) { return trim( $m[1] ); }
		return trim( $html );
	}

	private static function heading_layout( $cls ) {
		$tw_maxw = array( 'sm' => 24, 'md' => 28, 'lg' => 32, 'xl' => 36, '2xl' => 42, '3xl' => 48, '4xl' => 56, '5xl' => 64, '6xl' => 72, '7xl' => 80 );
		$out = array( 'alignment' => '', 'element_spacing' => '', 'block_max_width' => array( 'value' => '', 'unit' => 'px' ), 'spacing' => null, 'css_class' => '' );
		$kept = array();
		foreach ( preg_split( '/\s+/', trim( (string) $cls ) ) as $c ) {
			if ( $c === '' ) { continue; }
			if ( $c === 'text-center' ) { $out['alignment'] = 'center'; }
			elseif ( $c === 'text-right' ) { $out['alignment'] = 'right'; }
			elseif ( $c === 'text-left' ) { $out['alignment'] = 'left'; }
			elseif ( $c === 'mx-auto' ) { /* centring = block_max_width + centre align */ }
			elseif ( preg_match( '/^space-y-(\d+(?:\.\d+)?)$/', $c, $m ) ) { $px = (float) $m[1] * 4; $out['element_spacing'] = $px <= 8 ? 'tight' : ( $px >= 16 ? 'relaxed' : '' ); }
			elseif ( preg_match( '/^max-w-(?:\[(.+)\]|(sm|md|lg|xl|[2-7]xl))$/', $c, $m ) ) {
				if ( ! empty( $m[2] ) && isset( $tw_maxw[ $m[2] ] ) ) { $out['block_max_width'] = array( 'value' => (string) $tw_maxw[ $m[2] ], 'unit' => 'rem' ); }
				elseif ( ! empty( $m[1] ) && preg_match( '/^(\d*\.?\d+)(px|rem|em|%|vw|ch)$/', $m[1], $u ) ) { $out['block_max_width'] = array( 'value' => $u[1], 'unit' => $u[2] ); }
			}
			elseif ( preg_match( '/^(mb|mt)-(\d+(?:\.\d+)?)$/', $c, $m ) ) {
				if ( $out['spacing'] === null ) { $out['spacing'] = self::empty_spacing(); }
				$side = $m[1] === 'mb' ? 'bottom' : 'top';
				$out['spacing']['margin'][ $side ] = $m[1] . '-' . self::rem_to_spacing_slug( (float) $m[2] * 0.25 );
			}
			else { $kept[] = $c; }
		}
		$out['css_class'] = implode( ' ', $kept );
		return $out;
	}

	/**
	 * Resolve a heading PART's source font-weight → a numeric 100–900 (or '' when unknown). Prefers an
	 * explicit computed weight (from the source element's data-sc-cs), else compiles the part's own utility
	 * classes (e.g. Tailwind `font-extrabold` → 800) for a font-weight. Deterministic; only ever the real
	 * detected weight, never a guess.
	 */
	private static function heading_part_weight( $explicit, $cls ) {
		$norm = function ( $w ) {
			$w = strtolower( trim( (string) $w ) );
			if ( 'bold' === $w )   { return '700'; }
			if ( 'normal' === $w ) { return '400'; }
			return preg_match( '/^[1-9]00$/', $w ) ? $w : '';
		};
		$w = $norm( $explicit );
		if ( '' !== $w ) { return $w; }
		if ( '' !== trim( (string) $cls ) && self::$style_on && class_exists( 'FW_Site_Converter_Tailwind' ) ) {
			$cm = FW_Site_Converter_Tailwind::compile_class_set( (string) $cls, self::$style_cfg );
			if ( is_array( $cm ) && isset( $cm['base']['font-weight'] ) ) { return $norm( $cm['base']['font-weight'] ); }
		}
		return '';
	}

	/**
	 * Per-element Custom CSS that re-asserts each heading part's SOURCE font-weight on its own rendered
	 * element (`.heading-title` / `.heading-overline` / `.heading-subtitle`). Scoped via `selector` (the
	 * element's unique class) so `.uHASH .heading-title` (0,2,0) beats the theme's tag rule
	 * `h1.heading-title { font-weight: var(--h1-font-weight …) }` (0,1,1) — otherwise a converted hero H1
	 * with no heading-weight token in Theme Settings renders at the default (400/500) instead of its real
	 * weight. Mirrors the JS to-pages path, which emits the same `selector .heading-title{font-weight:…}`.
	 */
	private static function heading_weight_css( $h ) {
		$parts = array(
			'title'    => '.heading-title',
			'overline' => '.heading-overline',
			'subtitle' => '.heading-subtitle',
		);
		$css = '';
		foreach ( $parts as $part => $sel ) {
			// Only for parts that actually render (have text).
			if ( '' === trim( (string) ( $h[ $part ] ?? '' ) ) ) { continue; }
			$w = self::heading_part_weight( $h[ $part . '_weight' ] ?? '', $h[ $part . '_class' ] ?? '' );
			if ( '' !== $w ) { $css .= 'selector ' . $sel . '{font-weight:' . $w . ' !important;}'; }
		}
		return $css;
	}

	/**
	 * NEVER-DROP overline typography. The Special Heading overline has native options for casing
	 * (overline_uppercase), colour (overline_color) and alignment, plus its WEIGHT is re-asserted by
	 * heading_weight_css() — but NO native option carries the overline's FONT-SIZE or LETTER-SPACING.
	 * A source eyebrow like `text-[11px] tracking-[0.3em] uppercase` therefore lost its 11px size + 0.3em
	 * tracking (strip_inert_utilities drops them), rendering in the theme's default overline font — the
	 * "overline looks different" bug. Carry those two as scoped Custom CSS on `.heading-overline` (tier-3
	 * of the never-drop rule: no native option, no design token → last-resort scoped CSS). Font-size comes
	 * from the Tailwind compiler (handles arbitrary `text-[11px]` and named `text-xs`); an ARBITRARY
	 * `tracking-[…]` the compiler doesn't resolve is parsed directly. Mirrored in the JS to-pages path.
	 */
	private static function overline_typography_css( $h ) {
		if ( '' === trim( (string) ( $h['overline'] ?? '' ) ) ) { return ''; }
		$cls = trim( (string) ( $h['overline_class'] ?? '' ) );
		if ( '' === $cls ) { return ''; }
		$decls = '';
		if ( class_exists( 'FW_Site_Converter_Tailwind' ) ) {
			$cm   = FW_Site_Converter_Tailwind::compile_class_set( $cls, self::$style_cfg );
			$base = $cm['base'] ?? array();
			if ( ! empty( $base['font-size'] ) )      { $decls .= 'font-size:' . $base['font-size'] . ' !important;'; }
			if ( ! empty( $base['letter-spacing'] ) ) { $decls .= 'letter-spacing:' . $base['letter-spacing'] . ' !important;'; }
		}
		// Arbitrary `tracking-[0.3em]` (the compiler resolves only NAMED tracking-*), parsed directly.
		if ( false === strpos( $decls, 'letter-spacing' ) && preg_match( '/\btracking-\[([^\]]+)\]/', $cls, $tm ) ) {
			$decls .= 'letter-spacing:' . trim( str_replace( '_', ' ', $tm[1] ) ) . ' !important;';
		}
		return '' !== $decls ? 'selector .heading-overline{' . $decls . '}' : '';
	}

	/** The overline class tokens overline_typography_css()/heading_weight_css() carry natively, so the
	 *  dropped-class guard counts them KEPT (size / tracking / weight utilities on the eyebrow). */
	private static function overline_kept_tokens( $h ) {
		$cls = trim( (string) ( $h['overline_class'] ?? '' ) );
		if ( '' === $cls || '' === trim( (string) ( $h['overline'] ?? '' ) ) ) { return array(); }
		preg_match_all( '/\b(?:text-(?:\[[^\]]+\]|xs|sm|base|lg|\dxl)|tracking-(?:\[[^\]]+\]|tighter|tight|normal|wide|wider|widest)|font-(?:thin|extralight|light|normal|medium|semibold|bold|extrabold|black))\b/', $cls, $m );
		return $m[0] ?? array();
	}

	/**
	 * NEVER-DROP rule for a heading part's constrained MEASURE. A merged subtitle/title part often
	 * carries `max-w-* mx-auto` (a centered content measure). Because that part is folded INTO the
	 * special_heading — its standalone block is consumed — the section styler (collect_section_style)
	 * never sees it, so the width would be SILENTLY DROPPED. Instead we reproduce it as scoped Custom
	 * CSS on the part's own rendered element (`.heading-subtitle` / `.heading-title`), specificity kept
	 * low via the `selector ` prefix (→ `.uHASH`) so a native option / user edit still wins. LAYOUT
	 * (max-width/centering) is exactly what the appearance-only faithful base excludes, so this is its
	 * layout counterpart. Returns array( 'css' => string, 'tokens' => string[] ) — `tokens` are the
	 * source utilities it CARRIED, recorded as kept so the dropped-class guard doesn't flag them.
	 * Mirrors heading_weight_css; parity intended in the JS to-pages path.
	 */
	/** A source element's constrained MEASURE → a CSS length. Prefers a Tailwind `max-w-*` (named tier
	 *  from the same table heading_layout uses, or an arbitrary `max-w-[…]`), then a computed max-width
	 *  from the part's data-sc-cs. Returns '' when there's no real constraint. (The Tailwind class
	 *  compiler does NOT emit max-width, so this local table is the resolver — mirrors heading_layout.) */
	private static function part_max_width_val( $cls, $cs = '' ) {
		$tw_maxw = array( 'sm' => 24, 'md' => 28, 'lg' => 32, 'xl' => 36, '2xl' => 42, '3xl' => 48, '4xl' => 56, '5xl' => 64, '6xl' => 72, '7xl' => 80 );
		foreach ( preg_split( '/\s+/', trim( (string) $cls ) ) as $c ) {
			if ( preg_match( '/^max-w-(?:\[(.+)\]|(sm|md|lg|xl|[2-7]xl))$/', $c, $m ) ) {
				if ( ! empty( $m[2] ) && isset( $tw_maxw[ $m[2] ] ) ) { return $tw_maxw[ $m[2] ] . 'rem'; }
				if ( ! empty( $m[1] ) && preg_match( '/^(\d*\.?\d+)(px|rem|em|%|vw|ch)$/', $m[1], $u ) ) { return $u[1] . $u[2]; }
			}
		}
		if ( '' !== (string) $cs ) {
			$d  = self::cs_decls( (string) $cs, array( 'max-width' ) );
			$mw = trim( (string) ( $d['max-width'] ?? '' ) );
			if ( '' !== $mw && 'none' !== $mw && '0px' !== $mw && '0' !== $mw ) { return $mw; }
		}
		return '';
	}

	private static function heading_measures( $h ) {
		$parts = array( 'title' => '.heading-title', 'subtitle' => '.heading-subtitle', 'overline' => '.heading-overline' );
		$css   = '';
		$toks  = array();
		foreach ( $parts as $part => $sel ) {
			if ( '' === trim( (string) ( $h[ $part ] ?? '' ) ) ) { continue; }
			$cls = (string) ( $h[ $part . '_class' ] ?? '' );
			$mw  = self::part_max_width_val( $cls, (string) ( $h[ $part . '_cs' ] ?? '' ) );
			if ( '' === $mw ) { continue; } // only a real content-measure is carried
			$center = (bool) preg_match( '/(?:^|\s)mx-auto(?:\s|$)/', ' ' . $cls . ' ' );
			$body = 'max-width:' . $mw . ' !important;';
			if ( $center ) { $body .= 'margin-left:auto !important;margin-right:auto !important;'; }
			$css .= 'selector ' . $sel . '{' . $body . '}';
			// Record the utilities we honored so conv_dropped_diff() counts them as kept, not dropped.
			if ( preg_match_all( '/(?:^|\s)((?:max-w|min-w)-\S+)/', ' ' . $cls . ' ', $mm ) ) {
				foreach ( $mm[1] as $t ) { $toks[] = $t; }
			}
			if ( $center ) { $toks[] = 'mx-auto'; }
		}
		return array( 'css' => $css, 'tokens' => $toks );
	}

	/**
	 * True when a coalesced heading `head` is NOT a real heading but a stray mis-folded LINK: no title, no
	 * overline, and its only content is a subtitle that is a link (`<a …>`). These come from a "View All →"
	 * CTA link beside a section heading in a `flex justify-between` row; folding it created an empty-title
	 * special_heading duplicating the real link. Kept precise so a legitimate plain-text subtitle-only head
	 * (fixture-2 section 6) is never dropped.
	 */
	private static function head_is_stray_link( $head ) {
		if ( ! is_array( $head ) ) { return false; }
		if ( '' !== trim( (string) ( $head['title'] ?? '' ) ) )    { return false; }
		if ( '' !== trim( (string) ( $head['overline'] ?? '' ) ) ) { return false; }
		$sub = (string) ( $head['subtitle'] ?? '' );
		if ( trim( $sub ) === '' ) { return false; }
		// (a) the subtitle still carries an <a>, or (b) its class signature is a LINK/CTA — a hover
		// `transition`, or a `hidden md:flex` responsive link. A plain descriptive subtitle never carries
		// those, so a legitimate subtitle-only heading (fixture-2 §6) is left intact.
		if ( preg_match( '/<a\b/i', $sub ) ) { return true; }
		$scls = ' ' . strtolower( (string) ( $head['subtitle_class'] ?? '' ) ) . ' ';
		if ( strpos( $scls, 'transition' ) !== false ) { return true; }
		if ( preg_match( '/\bhidden\b/', $scls ) && preg_match( '/\b(?:sm|md|lg|xl):(?:flex|inline-flex)\b/', $scls ) ) { return true; }
		return false;
	}

	private static function n_heading( $h ) {
		$lvl = isset( $h['level'] ) && $h['level'] >= 1 && $h['level'] <= 6 ? (int) $h['level'] : 2;
		// Translate the wrapper's Tailwind layout/spacing classes into native options (parity w/ JS).
		$layout = self::heading_layout( (string) ( $h['css_class'] ?? '' ) );
		// #1: an intermediate wrapper's constrained measure (`max-w-* mx-auto`) carried by the section
		// walk (wrapMaxW) fills block_max_width when the heading's own classes didn't set one — so a
		// centered `<div class="text-center max-w-2xl mx-auto"><h2>…</h2><p>…</p></div>` keeps its width.
		if ( empty( $layout['block_max_width']['value'] ) && ! empty( $h['wrapMaxW'] )
			&& preg_match( '/^(\d*\.?\d+)(px|rem|em|%|vw|ch)$/', (string) $h['wrapMaxW'], $wm ) ) {
			$layout['block_max_width'] = array( 'value' => $wm[1], 'unit' => $wm[2] );
		}
		// The source's vertical margin often lives on the h-tag ITSELF (`<h2 class="… mb-6">`), not a wrapper.
		// WHERE it belongs depends on whether a subtitle renders:
		//  • WITH a subtitle → the title's bottom margin is the TITLE→SUBTITLE gap, so it drives
		//    `element_spacing` (the dedicated control for that internal rhythm). Left at Normal it would use
		//    the theme's font-size-relative default (much larger than the source, e.g. ~30px vs a 16px source).
		//    `element_spacing` is a COARSE select, so snap to the nearest: tight ≤6px, relaxed 7–20px, else Normal.
		//  • WITHOUT a subtitle → the title's margin IS the block's bottom gap → the outer `spacing` option
		//    (Margin & Padding), as before, so `.heading{margin-bottom:1em}` doesn't take over.
		// The px comes from the source title's `mb-*` utility (Tailwind: N × 4px). Parity mirrored in JS to-pages.
		$has_subtitle = '' !== trim( (string) ( $h['subtitle'] ?? '' ) );
		// Prefer the title's COMPUTED bottom margin (from cs) — authoritative and survives class stripping —
		// falling back to an `mb-*` still on title_class.
		$title_mb_px  = ( isset( $h['title_mb_px'] ) && $h['title_mb_px'] !== null )
			? (float) $h['title_mb_px']
			: ( preg_match( '/\bmb-(\d+(?:\.\d+)?)\b/', (string) ( $h['title_class'] ?? '' ), $tmb ) ? (float) $tmb[1] * 4 : null );
		if ( $has_subtitle && $title_mb_px !== null ) {
			if ( '' === $layout['element_spacing'] ) {
				$layout['element_spacing'] = $title_mb_px <= 6 ? 'tight' : ( $title_mb_px <= 20 ? 'relaxed' : '' );
			}
		} elseif ( $layout['spacing'] === null ) {
			$title_layout = self::heading_layout( (string) ( $h['title_class'] ?? '' ) );
			if ( $title_layout['spacing'] !== null ) { $layout['spacing'] = $title_layout['spacing']; }
		}
		// Default to inherit ('') — `left`/`start` is the computed default for almost all content, so
		// treat it as inherit (no `text-start`) and only force a class for an explicit center/right.
		$align = $layout['alignment'] !== '' ? $layout['alignment']
			: ( isset( $h['align'] ) && in_array( $h['align'], array( 'center', 'right' ), true ) ? $h['align'] : '' );
		// #2 — assign the SUBTITLE's Text Style preset from its captured source size (e.g. 18px →
		// `font-subtitle`, 20px → `lead`). The special_heading's `subtitle_size` value IS the preset class,
		// so the subtitle keeps its scale via the editable Text Style preset even though the size utility
		// class was stripped (the fix for section subtitles collapsing to the 16px base).
		$subtitle_size = ( isset( $h['subtitle_fs'] ) && $h['subtitle_fs'] !== '' ) ? self::text_preset_for( (float) $h['subtitle_fs'] ) : '';
		// Part COLORS — set the native subtitle/title color option from the source's captured computed colour
		// (e.g. a muted `text-foreground/70` → rgba(41,61,54,.7) subtitle), so it isn't dropped to the theme
		// default. Skip the plain default ink (a normal-black title stays inherit). Overline colour is owned by
		// the pill/overline path above, so it's left alone here.
		$mk_color = function ( $src ) {
			$src = trim( (string) $src );
			return ( $src !== '' && ! self::is_default_ink( $src ) ) ? array( 'predefined' => '', 'custom' => $src ) : self::empty_color();
		};
		$subtitle_color = $mk_color( $h['subtitle_color_src'] ?? '' );
		$title_color    = $mk_color( $h['title_color_src'] ?? '' );
		// overline_uppercase — reproduce the source kicker casing (parity with the JS mapper): Yes when
		// the source overline is text-transform:uppercase OR its text is literally all-caps; else No.
		$ol_plain = trim( wp_strip_all_tags( (string) ( $h['overline'] ?? '' ) ) );
		$ol_upper = ( isset( $h['overline_transform'] ) && 'uppercase' === $h['overline_transform'] )
			|| ( '' !== $ol_plain && preg_match( '/[a-z]/i', $ol_plain ) && $ol_plain === mb_strtoupper( $ol_plain ) );
		// Overline icon: a source overline SVG → the native overline_icon (kept OUT of the text, so it
		// doesn't double up). Parity with the JS mapper.
		$ov_raw      = (string) ( $h['overline'] ?? '' );
		$ov_icon     = array( 'type' => 'none' );
		$ov_icon_pos = 'before';
		if ( preg_match( '/<svg\b[\s\S]*?<\/svg>/i', $ov_raw, $svg_m ) ) {
			$ov_icon     = array( 'type' => 'svg', 'svg-source' => 'inline', 'markup' => $svg_m[0] );
			$ov_icon_pos = ( strpos( $ov_raw, $svg_m[0] ) + strlen( $svg_m[0] ) >= strlen( rtrim( $ov_raw ) ) ) ? 'after' : 'before';
			$ov_raw      = trim( preg_replace( '/<svg\b[\s\S]*?<\/svg>/i', '', $ov_raw ) );
		}
		// CHIP-BEFORE-HEADING PILL: a badge chip that sat directly above this heading became its overline.
		// Its leading inline <svg> drives overline_icon (takes precedence over any html-extracted svg), it
		// renders as a FILLED PILL, and the pill tint + text follow the Overline Color (the chip's text
		// colour). This reproduces the source chip 100% and mirrors the JS capture-service path.
		if ( isset( $h['overline_svg'] ) && '' !== trim( (string) $h['overline_svg'] ) ) {
			$ov_icon     = array( 'type' => 'svg', 'svg-source' => 'inline', 'markup' => (string) $h['overline_svg'] );
			$ov_icon_pos = 'before';
		}
		$overline_container = ! empty( $h['overline_pill'] ) ? 'pill' : '';
		$overline_color     = ( ! empty( $h['overline_color'] ) )
			? array( 'predefined' => '', 'custom' => (string) $h['overline_color'] )
			: array( 'predefined' => '', 'custom' => '' );
		$overline_class = self::strip_inert_utilities( $h['overline_class'] ?? '' );
		$title_class    = self::strip_inert_utilities( $h['title_class'] ?? '' );
		$subtitle_class = self::strip_inert_utilities( $h['subtitle_class'] ?? '' );
		$uid = self::uid();
		// CONVERSION DEBUG — record the heading's ORIGINAL per-part source classes (src_cls) and the
		// utilities strip_inert_utilities() DID NOT turn into an option (dropped: text-5xl, lg:text-7xl,
		// leading-[1.1], …). The wrapper css_class contributes its keep_classes-dropped tokens too.
		$src_all = trim( implode( ' ', array_filter( array(
			(string) ( $h['overline_class'] ?? '' ), (string) ( $h['title_class'] ?? '' ),
			(string) ( $h['subtitle_class'] ?? '' ), (string) ( $layout['css_class'] ?? '' ),
		) ) ) );
		// NEVER-DROP: reproduce each part's constrained measure (`max-w-* mx-auto`) as scoped Custom CSS,
		// and count the utilities it carried as KEPT so the dropped-class guard doesn't flag them.
		$measures = self::heading_measures( $h );
		// NEVER-DROP overline size/letter-spacing (no native option) → scoped CSS, and count its
		// carried size/tracking/weight utilities as KEPT so the dropped-class guard doesn't flag them.
		$overline_type_css = self::overline_typography_css( $h );
		$kept_all = trim( $overline_class . ' ' . $title_class . ' ' . $subtitle_class . ' ' . self::keep_classes( $layout['css_class'] ?? '' ) . ' ' . implode( ' ', $measures['tokens'] ) . ' ' . implode( ' ', self::overline_kept_tokens( $h ) ) );
		self::conv_debug_record( $uid, $src_all, self::conv_dropped_diff( $src_all, $kept_all ) );
		return array(
			'type' => 'simple', 'shortcode' => 'special_heading', '_items' => array(),
			'atts' => array(
				'unique_id' => $uid, 'css_id' => '',
				// Only UNMAPPED wrapper classes remain on css_class; keep_classes drops animation noise.
				'css_class' => self::keep_classes( $layout['css_class'] ),
				// Re-assert each part's SOURCE font-weight on its own element (wins over the theme's
				// hN.heading-title tag rule when no heading-weight token is set) — parity with JS to-pages.
				'custom_css' => self::heading_weight_css( $h )
				. $overline_type_css // NEVER-DROP: overline font-size + letter-spacing (no native option)
				. $measures['css'] // NEVER-DROP: per-part constrained measure (max-w-* mx-auto → scoped max-width)
				// NO subtitle: the theme's default hN bottom margin leaks as the block's below-gap and DOMINATES
				// the (source-derived) outer Margin & Padding value — e.g. a 48px h1 default over the source's
				// 24px. `.heading-title` is never reset by the shortcode (only its top margin is), so reset the
				// bottom here for lone headings; the outer `spacing` option then IS the faithful gap. (Headings
				// WITH a subtitle keep their title→subtitle rhythm via element_spacing above.) Mirror in JS to-pages.
				// TITLE bottom margin. No subtitle → reset the theme's default hN margin to 0 (the outer Margin &
				// Padding option then IS the faithful below-gap). WITH a subtitle → the title→subtitle gap is the
				// title's own `mb-*` (e.g. mb-8 = 32px); the coarse element_spacing select rounds it to a theme
				// default (e.g. 32px → "Normal" ≈ 64px), so carry the EXACT px here — never-drop, reproduced
				// faithfully. (Mirror in JS to-pages.)
				. ( '' === trim( (string) ( $h['subtitle'] ?? '' ) )
					? 'selector .heading-title{margin-bottom:0 !important;}'
					: ( ( $title_mb_px !== null && $title_mb_px > 0 ) ? 'selector .heading-title{margin-bottom:' . (int) $title_mb_px . 'px !important;}' : '' ) ),
				'overline' => self::map_accent_classes( $ov_raw ),
				'overline_uppercase' => $ol_upper ? 'yes' : 'no',
				'overline_icon' => $ov_icon,
				'overline_icon_position' => $ov_icon_pos,
				// Chip-before-heading pill (empty '' / neutral color for a normal heading, so unaffected).
				'overline_container' => $overline_container,
				'overline_color' => $overline_color,
				'title'    => self::map_accent_classes( (string) ( $h['title'] ?? '' ) ),
				'subtitle' => self::map_accent_classes( (string) ( $h['subtitle'] ?? '' ) ),
				'heading'  => 'h' . $lvl,
				'alignment' => $align,
				'element_spacing' => $layout['element_spacing'],
				'block_max_width' => $layout['block_max_width'],
				'spacing' => $layout['spacing'] !== null ? $layout['spacing'] : self::empty_spacing(),
				// Per-part source classes mapped onto the special heading's class inputs (the
				// source's overline/title/subtitle carried their own utility classes).
				'overline_class' => $overline_class,
				'title_class'    => $title_class,
				'subtitle_class' => $subtitle_class,
				'subtitle_size'  => $subtitle_size, // Text Style preset for the subtitle (from its source size)
				'subtitle_color' => $subtitle_color, // source subtitle colour (e.g. muted text-foreground/70)
				'title_color'    => $title_color,    // source title colour when it's a real non-default tone
			),
		);
	}

	/**
	 * Flatten a section's CSS so wrapper-scoped rules map onto the rebuilt (decomposed) markup.
	 * Collapses descendant chains to "first token + leaf" (`.banner .block h1` → `.banner h1`),
	 * keeping the section anchor for scoping. Recurses into @media/@supports; leaves @font-face/
	 * @keyframes and single/two-token selectors untouched.
	 *
	 * @param string $css
	 * @return string
	 */
	private static function flatten_css( $css ) {
		$css = (string) $css;
		if ( trim( $css ) === '' ) { return ''; }
		$out = '';
		$buf = '';
		$i = 0;
		$n = strlen( $css );
		while ( $i < $n ) {
			$ch = $css[ $i ];
			if ( $ch === '{' ) {
				$prelude = trim( $buf );
				$buf = '';
				// Read the balanced { … } body.
				$depth = 1; $i++; $body = '';
				while ( $i < $n && $depth > 0 ) {
					$c = $css[ $i ];
					if ( $c === '{' ) { $depth++; } elseif ( $c === '}' ) { $depth--; if ( $depth === 0 ) { break; } }
					$body .= $c; $i++;
				}
				$i++; // skip closing }
				if ( $prelude !== '' && $prelude[0] === '@' ) {
					// @media / @supports → recurse; @font-face / @keyframes → leave as-is.
					if ( stripos( $prelude, '@media' ) === 0 || stripos( $prelude, '@supports' ) === 0 ) {
						$out .= $prelude . '{' . self::flatten_css( $body ) . '}';
					} else {
						$out .= $prelude . '{' . $body . '}';
					}
				} else {
					$out .= self::flatten_selectors( $prelude ) . '{' . $body . '}';
				}
			} else {
				$buf .= $ch; $i++;
			}
		}
		return $out;
	}

	/** Flatten each comma-separated selector to first-token + leaf (3+ tokens only). */
	private static function flatten_selectors( $sel ) {
		$parts = explode( ',', $sel );
		$res = array();
		foreach ( $parts as $p ) {
			$p = trim( $p );
			if ( $p === '' ) { continue; }
			$toks = preg_split( '/\s*[>+~]\s*|\s+/', $p, -1, PREG_SPLIT_NO_EMPTY );
			$res[] = ( count( $toks ) <= 2 ) ? $p : ( $toks[0] . ' ' . $toks[ count( $toks ) - 1 ] );
		}
		return implode( ', ', $res );
	}

	/**
	 * Remap a card section's source CSS onto the icon_box markup (instead of flattening). The
	 * source card's inner structure (`.icon`, `.content`, the heading) is REPLACED by the icon_box
	 * structure, not lost — so we translate the source selectors rather than collapse them:
	 *
	 *   .about-item .icon         → .about-item .icon-box__icon
	 *   .about-item .icon i       → .about-item .icon-box__icon i
	 *   .about-item .content h4   → .about-item .icon-box__title   (the title moves out of content)
	 *   .about-item .content      → .about-item .icon-box__content
	 *   .about-item .content a    → .about-item .icon-box__content a
	 *
	 * The rule BODIES are kept verbatim (the source's clean, human-formatted CSS), so the carried
	 * card styling reads as cleanly as the source. The `.about-item` wrapper class itself stays —
	 * it's the icon_box CSS Class.
	 *
	 * @param string $css
	 * @return string
	 */
	private static function remap_icon_box_css( $css ) {
		$css = (string) $css;
		if ( trim( $css ) === '' ) { return ''; }
		$out = ''; $buf = ''; $i = 0; $n = strlen( $css );
		while ( $i < $n ) {
			$ch = $css[ $i ];
			if ( $ch === '{' ) {
				$prelude = trim( $buf ); $buf = '';
				$depth = 1; $i++; $body = '';
				while ( $i < $n && $depth > 0 ) {
					$c = $css[ $i ];
					if ( $c === '{' ) { $depth++; } elseif ( $c === '}' ) { $depth--; if ( $depth === 0 ) { break; } }
					$body .= $c; $i++;
				}
				$i++; // skip closing }
				if ( $prelude !== '' && $prelude[0] === '@' ) {
					if ( stripos( $prelude, '@media' ) === 0 || stripos( $prelude, '@supports' ) === 0 ) {
						$out .= $prelude . " {\n" . self::remap_icon_box_css( $body ) . "}\n";
					} else {
						$out .= $prelude . '{' . $body . "}\n";
					}
				} else {
					$sel  = self::remap_icon_box_selector( $prelude );
					$body = self::clean_card_body( $sel, $body ); // drop float-layout artifacts the icon_box handles
					$out .= $sel . ' {' . rtrim( $body ) . " }\n";
				}
			} else {
				$buf .= $ch; $i++;
			}
		}
		return $out;
	}

	/**
	 * Drop float-layout artifacts from remapped card rules: the source positions the icon with
	 * `float:left` on `.icon` and clears it with horizontal padding/margin on `.content`. The
	 * icon_box's own flex layout (stack-left / top-title) handles that spacing, so the carried
	 * `padding-left:80px` etc. would just add a dead gap. Strip horizontal spacing from the content
	 * wrapper and `float` from the icon wrapper; other declarations are kept verbatim.
	 *
	 * @param string $sel remapped selector
	 * @param string $body rule body
	 * @return string
	 */
	private static function clean_card_body( $sel, $body ) {
		$content = false; $icon = false;
		foreach ( explode( ',', (string) $sel ) as $p ) {
			$base = preg_replace( '/::?[a-z-]+(\([^)]*\))?\s*$/i', '', trim( $p ) ); // drop trailing pseudo
			if ( preg_match( '/\.icon-box__content$/', $base ) ) { $content = true; }
			if ( preg_match( '/\.icon-box__icon$/', $base ) )    { $icon = true; }
		}
		if ( $content ) {
			$body = preg_replace( '/(^|;)\s*(?:padding|margin)-(?:left|right)\s*:[^;}]*;?/i', '$1', $body );
		}
		if ( $icon ) {
			$body = preg_replace( '/(^|;)\s*float\s*:[^;}]*;?/i', '$1', $body );
		}
		return $body;
	}

	/** Translate the source card's inner selectors to the icon_box structure (per comma-part). */
	private static function remap_icon_box_selector( $sel ) {
		$parts = explode( ',', $sel );
		$res = array();
		foreach ( $parts as $p ) {
			$p = trim( $p );
			if ( $p === '' ) { continue; }
			// Title first: `.content h1..h6` → `.icon-box__title` (in the icon_box the title is a
			// sibling of the content, so collapse `.content h4` to the title class).
			$p = preg_replace( '/\.content\s+h[1-6](?![\w-])/i', '.icon-box__title', $p );
			$p = preg_replace( '/\.content(?![\w-])/i', '.icon-box__content', $p );
			$p = preg_replace( '/\.icon(?![\w-])/i', '.icon-box__icon', $p );
			$res[] = $p;
		}
		return implode( ', ', $res );
	}

	/** Does this section contain an icon-card cell (→ use the icon_box CSS remap, not flatten)? */
	private static function section_has_card( array $sec ) {
		foreach ( ( $sec['blocks'] ?? array() ) as $b ) {
			if ( isset( $b['cols'] ) && is_array( $b['cols'] ) ) {
				foreach ( $b['cols'] as $c ) {
					if ( ! empty( $c['card'] ) ) { return true; }
				}
			}
		}
		return false;
	}

	/** A column's content HTML (grid cell). */
	private static function cell_width( $w ) {
		$ok = array( '1_1', '1_2', '1_3', '1_4', '1_5', '1_6', '2_3', '3_4', '2_5', '3_5' );
		return in_array( $w, $ok, true ) ? $w : '1_3';
	}

	/**
	 * Build pages (Pages-importer payload) from a role-annotated mapping.
	 *
	 * @param array $mapping `{ pages: [ { slug, front_page, sections: [ { sectionClass, css, blocks:[...role] } ] } ] }`
	 * @return array[] pages
	 */
	public static function build_pages( array $mapping ) {
		self::$conv_debug = array(); // fresh per build — re-conversions must not accumulate collector records
		self::$required_shortcodes = array(); // fresh per build — the Library shortcodes this conversion needs
		$out = array();
		$pages = isset( $mapping['pages'] ) && is_array( $mapping['pages'] ) ? $mapping['pages'] : array();
		foreach ( $pages as $page ) {
			$builder = array();
			$sections = isset( $page['sections'] ) && is_array( $page['sections'] ) ? $page['sections'] : array();
			foreach ( $sections as $sec ) {
				$node = self::build_section( $sec );
				if ( $node ) { $builder[] = $node; }
			}
			if ( ! empty( $page['mainClass'] ) ) { self::main_style( (string) $page['mainClass'] ); } // carry the source <main>'s vertical padding
			$slug = isset( $page['slug'] ) ? sanitize_title( (string) $page['slug'] ) : '';
			$out[] = array(
				'title'      => isset( $page['title'] ) && $page['title'] !== '' ? (string) $page['title'] : ( $slug !== '' ? ucwords( str_replace( '-', ' ', $slug ) ) : 'Home' ),
				'slug'       => $slug !== '' ? $slug : 'home',
				'status'     => 'publish',
				'front_page' => ! empty( $page['front_page'] ),
				'builder'    => $builder,
			);
		}
		return $out;
	}

	/* ---------------------------------------------------------------------- *
	 * Conversion debug map — powers the dashboard hover-inspector.
	 * ---------------------------------------------------------------------- */

	/**
	 * Build the conversion-map: for every builder node in the built page tree, record what the
	 * deterministic converter DID (`sc` = shortcode, `mapped` = its meaningful non-empty atts,
	 * `custom_css` = its scoped base CSS) and — merged from the per-node collector ($conv_debug) —
	 * what SOURCE classes it carried (`src_cls`) and DROPPED (`dropped`). Keyed by the 8-char element
	 * hash that the renderer stamps as the element's `u<hash>` scope class, so the dashboard can look
	 * an element up by that class.
	 *
	 * Call AFTER build_pages() (so $conv_debug is populated) with its returned $pages array.
	 *
	 * @param array $pages build_pages() output (each page has a nested `builder` node tree)
	 * @return array<string,array> hash → record
	 */
	public static function build_conversion_map( array $pages ) {
		$map = array();
		foreach ( $pages as $page ) {
			$nodes = isset( $page['builder'] ) && is_array( $page['builder'] ) ? $page['builder'] : array();
			self::walk_conv_nodes( $nodes, $map );
		}
		// Merge the per-node collector (src_cls / dropped) recorded during the build.
		foreach ( self::$conv_debug as $hash => $dbg ) {
			if ( ! isset( $map[ $hash ] ) ) { $map[ $hash ] = array(); }
			if ( ! empty( $dbg['src_cls'] ) ) { $map[ $hash ]['src_cls'] = (string) $dbg['src_cls']; }
			if ( ! empty( $dbg['dropped'] ) ) { $map[ $hash ]['dropped'] = array_values( $dbg['dropped'] ); }
		}
		return $map;
	}

	/** Recursively walk a builder node tree, adding an `sc`/`mapped`/`custom_css` record per node with a unique_id. */
	private static function walk_conv_nodes( array $nodes, array &$map ) {
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) ) { continue; }
			$atts = isset( $node['atts'] ) && is_array( $node['atts'] ) ? $node['atts'] : array();
			$uid  = isset( $atts['unique_id'] ) ? (string) $atts['unique_id'] : '';
			$hash = self::conv_hash( $uid );
			if ( $hash !== '' ) {
				// Shortcode name: leaf widgets carry `shortcode`; structural nodes carry `type` (section/column).
				$sc  = isset( $node['shortcode'] ) && $node['shortcode'] !== ''
					? (string) $node['shortcode']
					: ( isset( $node['type'] ) ? (string) $node['type'] : '' );
				$rec = array( 'sc' => $sc, 'mapped' => self::condense_atts( $atts ) );
				$ccss = isset( $atts['custom_css'] ) ? trim( (string) $atts['custom_css'] ) : '';
				if ( $ccss !== '' ) { $rec['custom_css'] = self::conv_cap( preg_replace( '/\s+/', ' ', $ccss ), 600 ); }
				// Keep any earlier collector-recorded keys if this hash somehow repeats.
				$map[ $hash ] = isset( $map[ $hash ] ) ? array_merge( $map[ $hash ], $rec ) : $rec;
			}
			foreach ( array( '_items' ) as $ck ) {
				if ( ! empty( $node[ $ck ] ) && is_array( $node[ $ck ] ) ) {
					self::walk_conv_nodes( $node[ $ck ], $map );
				}
			}
		}
	}

	/** Cap a scalar/string to $max chars for the debug map (never truncate mid-record shape). */
	private static function conv_cap( $v, $max = 120 ) {
		$v = (string) $v;
		return ( strlen( $v ) > $max ) ? ( substr( $v, 0, $max - 1 ) . '…' ) : $v;
	}

	/**
	 * Condense a node's atts into a flat, human-relevant map for the inspector: keeps NON-EMPTY
	 * scalars + short values, flattens the common option shapes ({predefined,custom} color →
	 * effective value, {value,unit} → "24px", {type,...icon} → the icon id/type), and DROPS noise
	 * (unique_id, empty strings/arrays, animation defaults, responsive_hide, huge blobs, custom_css —
	 * surfaced separately).
	 *
	 * @param array $atts
	 * @return array
	 */
	private static function condense_atts( array $atts ) {
		$skip = array(
			'unique_id' => 1, 'css_id' => 1, 'custom_css' => 1, 'responsive_hide' => 1,
			'custom_attrs' => 1, 'animation' => 1, 'spacing' => 1,
		);
		$out = array();
		foreach ( $atts as $k => $v ) {
			if ( isset( $skip[ $k ] ) ) { continue; }
			$flat = self::condense_val( $v );
			if ( $flat === '' || $flat === null || $flat === array() ) { continue; }
			// Drop obvious defaults that carry no signal.
			if ( $flat === 'default' || $flat === 'none' || $flat === 'no' || $flat === 'inherit' ) { continue; }
			$out[ (string) $k ] = is_string( $flat ) ? self::conv_cap( $flat ) : $flat;
		}
		return $out;
	}

	/** Flatten a single att value to a scalar/short representation (or '' when it's empty/noise). */
	private static function condense_val( $v ) {
		if ( is_bool( $v ) ) { return $v ? true : ''; }
		if ( is_int( $v ) || is_float( $v ) ) { return ( (float) $v === 0.0 ) ? '' : $v; }
		if ( is_string( $v ) ) { return trim( $v ); }
		if ( ! is_array( $v ) ) { return ''; }
		// {predefined, custom} color → the effective value.
		if ( array_key_exists( 'predefined', $v ) || array_key_exists( 'custom', $v ) ) {
			$pre = isset( $v['predefined'] ) && $v['predefined'] !== '' ? (string) $v['predefined'] : '';
			$cus = isset( $v['custom'] ) && is_string( $v['custom'] ) && $v['custom'] !== '' ? (string) $v['custom'] : '';
			$val = $pre !== '' ? $pre : $cus;
			if ( $val !== '' ) { return $val; }
			// else fall through (may be a nested color shape)
		}
		// {value, unit} → "24px".
		if ( array_key_exists( 'value', $v ) && array_key_exists( 'unit', $v ) ) {
			$val = is_scalar( $v['value'] ) ? trim( (string) $v['value'] ) : '';
			return $val === '' ? '' : ( $val . (string) $v['unit'] );
		}
		// Icon shape {type, ...}.
		if ( isset( $v['type'] ) && is_string( $v['type'] ) ) {
			$t = $v['type'];
			if ( $t === 'none' || $t === '' ) { return ''; }
			if ( ! empty( $v['icon-class'] ) ) { return (string) $v['icon-class']; }
			if ( ! empty( $v['svg-id'] ) ) { return 'svg:' . (string) $v['svg-id']; }
			if ( $t === 'svg' ) { return 'svg'; }
			return (string) $t;
		}
		// {preset, custom} shape (min_height / max_width) → the preset unless custom carries a value.
		if ( array_key_exists( 'preset', $v ) ) {
			$p = isset( $v['preset'] ) ? (string) $v['preset'] : '';
			if ( $p !== '' && $p !== 'auto' && $p !== 'custom' ) { return $p; }
			if ( isset( $v['custom'] ) && is_array( $v['custom'] ) ) {
				$c = self::condense_val( reset( $v['custom'] ) );
				if ( $c !== '' && $c !== null ) { return $c; }
			}
			return '';
		}
		// Generic small array of scalars → compact CSV; skip anything large/nested.
		$scalars = array();
		foreach ( $v as $iv ) {
			if ( is_scalar( $iv ) && (string) $iv !== '' ) { $scalars[] = (string) $iv; }
			else { return ''; } // nested/complex → not inspector-relevant
		}
		if ( ! $scalars || count( $scalars ) > 6 ) { return ''; }
		return self::conv_cap( implode( ',', $scalars ) );
	}

	/**
	 * The mapped page's CSS — each decomposed section's per-section CSS, flattened so it maps
	 * onto the rebuilt elements (`.banner .block h1` → `.banner h1`). Written into the child
	 * theme as `converted-page.css` (which loads reliably), since per-element Custom CSS via the
	 * dynamic-CSS aggregator isn't applied for importer-set builder pages. Verbatim / omitted
	 * sections are skipped (verbatim keeps its own wrappers in the global theme CSS).
	 *
	 * @param array $mapping role-annotated mapping
	 * @return string
	 */
	public static function page_css( array $mapping ) {
		$include_anim = ! empty( $mapping['include_animations'] );
		$brands       = self::button_brand_classes( $mapping ); // e.g. ['btn-main'] → rewritten to .btn
		$out  = array();
		$seen = array();
		foreach ( ( $mapping['pages'] ?? array() ) as $page ) {
			foreach ( ( $page['sections'] ?? array() ) as $sec ) {
				if ( ! empty( $sec['omit'] ) ) { continue; } // dropped section contributes no CSS
				$css = trim( (string) ( $sec['css'] ?? '' ) );
				if ( $css === '' ) { continue; }
				// Verbatim sections keep their source wrapper chain → used as-is. Card sections
				// (mapped to icon_box) have their inner structure REPLACED, so remap the source
				// selectors to the icon_box markup. Other decomposed sections lost their wrappers,
				// so flatten selectors to anchor + leaf (`.banner .block h1` → `.banner h1`).
				if ( ! empty( $sec['verbatim'] ) ) {
					$flat = $css;
				} elseif ( self::section_has_card( $sec ) ) {
					$flat = self::remap_icon_box_css( $css );
				} else {
					$flat = self::flatten_css( $css );
				}
				// Drop source animation CSS (keyframes, animation-* props, AOS/wow/animate.css
				// rules) unless the user opted to keep it via the mapping editor checkbox.
				if ( ! $include_anim ) { $flat = self::strip_animations( $flat ); }
				$id = isset( $sec['css_id'] ) ? sanitize_html_class( (string) $sec['css_id'] ) : '';
				// Decomposed sections render with id="banner" (no duplicate class), so anchor their
				// CSS on the id (`.banner` → `#banner`) for authority. Verbatim sections keep their
				// source class selectors (their mirrored markup still carries the classes).
				if ( empty( $sec['verbatim'] ) && $id !== '' ) { $flat = self::anchor_css_to_id( $flat, $id ); }
				// Rewrite the source button brand class (.btn-main) → its matching PRESET slug (.btn-primary /
				// .btn-secondary), NOT bare .btn. A bare `.btn{…}` rule would collide with the token baseline
				// `.btn:not([class*="btn-"])` and could reintroduce the black-.btn clobber; a `btn-`-prefixed
				// selector is excluded from that baseline and lands on the same preset class the rebuilt buttons
				// carry, so the source's fill/hover reach them without clobbering. (No-op when there are no brand
				// classes — semantic `bg-primary` sources never trigger it.)
				if ( empty( $sec['verbatim'] ) && $brands ) { $flat = self::rewrite_btn_classes( $flat, $brands ); }
				// Localize source image URLs (backgrounds, etc.) to the imported media — the
				// converted site serves its own images instead of hotlinking the source.
				if ( class_exists( 'FW_Site_Converter_Media' ) ) { $flat = FW_Site_Converter_Media::localize( $flat ); }
				$flat = trim( $flat );
				if ( $flat === '' ) { continue; }
				$key  = md5( $flat );
				if ( isset( $seen[ $key ] ) ) { continue; } // de-dupe identical section CSS
				$seen[ $key ] = true;
				// Label each block by its section id so the merged stylesheet reads section-by-section.
				$label = $id !== '' ? $id : trim( (string) ( $sec['sectionClass'] ?? 'section' ) );
				if ( $label === '' ) { $label = 'section'; }
				$out[] = "/* ---- " . $label . " ---- */\n" . self::tidy_css( $flat );
			}
		}
		return implode( "\n\n", $out );
	}

	/**
	 * Strip source animation CSS for a clean child stylesheet: removes @keyframes blocks,
	 * animation-* declarations (transition/transform are layout, left intact), and rule-sets
	 * targeting the common scroll/entrance libraries (animate.css, AOS, wow.js).
	 *
	 * @param string $css
	 * @return string
	 */
	public static function strip_animations( $css ) {
		$css = (string) $css;
		if ( trim( $css ) === '' ) { return ''; }
		// 1. @keyframes / vendor-prefixed keyframes blocks (brace-matched, may nest one level).
		$css = self::remove_at_blocks( $css, '/@(?:-webkit-|-moz-|-o-|-ms-)?keyframes\b/i' );
		// 2. Rule-sets whose selector references an animation library (drop the whole rule).
		$css = preg_replace( '/[^{}]*(?:animate__|\.animated\b|\.wow\b|\[data-aos|\.aos-)[^{}]*\{[^{}]*\}/i', '', $css );
		// 3. animation / animation-* declarations inside surviving rules (keep transition/transform).
		$css = preg_replace( '/(?<![-\w])(?:-webkit-|-moz-|-o-|-ms-)?animation(?:-[a-z]+)?\s*:[^;{}]*;?/i', '', $css );
		return $css;
	}

	/**
	 * Rewrite a decomposed section's anchor class selector to its id selector for authority
	 * (`.banner` → `#banner`), matching the section element which now renders with id="banner"
	 * and no duplicate `banner` class. Only the exact class token is rewritten — `.banner-foo`
	 * / `.bannerish` are left alone. A no-op when the id never appears as a class in the CSS
	 * (e.g. a user-edited custom id), so the section still matches via its kept classes.
	 *
	 * @param string $css
	 * @param string $id sanitized css id
	 * @return string
	 */
	private static function anchor_css_to_id( $css, $id ) {
		$css = (string) $css;
		if ( $id === '' || trim( $css ) === '' ) { return $css; }
		return preg_replace( '/\.' . preg_quote( $id, '/' ) . '(?![\w-])/', '#' . $id, $css );
	}

	/**
	 * Collect the source buttons' brand style classes (e.g. `btn-main`) across the mapping — the
	 * non-base `btn-*` tokens that aren't size/state modifiers. The rebuilt buttons use the bare
	 * `.btn` (Style: Default), so rewriting `.btn-main` → `.btn` in the page CSS carries the
	 * source's button rules (background, color, AND `:hover`) onto them faithfully.
	 *
	 * @param array $mapping
	 * @return string[] distinct brand classes
	 */
	private static function button_brand_classes( array $mapping ) {
		$set = array();
		foreach ( ( $mapping['pages'] ?? array() ) as $page ) {
			foreach ( ( $page['sections'] ?? array() ) as $sec ) {
				if ( ! empty( $sec['verbatim'] ) ) { continue; } // verbatim keeps its source markup/classes
				foreach ( ( $sec['blocks'] ?? array() ) as $b ) {
					$is_btn = ( ( $b['t'] ?? '' ) === 'button' ) || ( ( $b['role'] ?? '' ) === 'button' );
					if ( ! $is_btn ) { continue; }
					foreach ( preg_split( '/\s+/', (string) ( $b['cls'] ?? '' ) ) as $c ) {
						if ( $c === '' || ! preg_match( '/^btn-/i', $c ) ) { continue; }
						if ( preg_match( '/^btn-(sm|lg|block|link|outline|group)/i', $c ) ) { continue; } // size/state
						$set[ $c ] = true;
					}
				}
			}
		}
		return array_keys( $set );
	}

	/** Rewrite each brand button class token (`.btn-main`) to its matching PRESET slug (`.btn-primary` /
	 *  `.btn-secondary`) — whole-token only. A `btn-`-prefixed target keeps the source's button rules OFF the
	 *  bare `.btn` baseline (which the token CSS scopes as `.btn:not([class*="btn-"])`), so a black source
	 *  `.btn-main` can no longer clobber every rebuilt button; it lands on the preset class instead. */
	private static function rewrite_btn_classes( $css, array $brands ) {
		foreach ( $brands as $b ) {
			$role = self::brand_class_role( $b );
			if ( strtolower( $b ) === $role ) { continue; } // already the preset slug → leave as-is
			$css = preg_replace( '/\.' . preg_quote( $b, '/' ) . '(?![\w-])/', '.' . $role, $css );
		}
		return $css;
	}

	/** Map a source brand button class to a preset role slug: names hinting a secondary/accent/outline/ghost
	 *  treatment → `btn-secondary`, everything else → `btn-primary` (mirrors build_button_presets()' roles). */
	private static function brand_class_role( $cls ) {
		$c = strtolower( (string) $cls );
		if ( preg_match( '/(secondary|accent|cta|alt|ghost|outline|invert|dark|light)/', $c ) ) { return 'btn-secondary'; }
		return 'btn-primary';
	}

	/** Remove every CSS at-block whose at-keyword matches $at_regex, brace-matched. */
	private static function remove_at_blocks( $css, $at_regex ) {
		$guard = 0;
		while ( $guard++ < 1000 && preg_match( $at_regex, $css, $m, PREG_OFFSET_CAPTURE ) ) {
			$start = $m[0][1];
			$brace = strpos( $css, '{', $start );
			if ( $brace === false ) { break; }
			$depth = 1; $j = $brace + 1; $len = strlen( $css );
			while ( $j < $len && $depth > 0 ) {
				$c = $css[ $j ];
				if ( $c === '{' ) { $depth++; }
				elseif ( $c === '}' ) { $depth--; }
				$j++;
			}
			$css = substr( $css, 0, $start ) . substr( $css, $j );
		}
		return $css;
	}

	/**
	 * Drop Bootstrap grid/layout rules (.container, .row, .col-*, offsets, gutters) from carried
	 * CSS. The converted site uses the plugin's frontend-grid (the col-* classes were converted to
	 * the fw-col-* prefix), so the source's grid is redundant bloat. @media blocks left empty after
	 * the drop are removed too.
	 *
	 * @param string $css
	 * @return string
	 */
	public static function strip_grid_css( $css ) {
		$css = (string) $css;
		if ( trim( $css ) === '' ) { return ''; }
		$out = ''; $buf = ''; $i = 0; $n = strlen( $css );
		while ( $i < $n ) {
			$ch = $css[ $i ];
			if ( $ch === '{' ) {
				$prelude = trim( $buf ); $buf = '';
				$depth = 1; $i++; $body = '';
				while ( $i < $n && $depth > 0 ) {
					$c = $css[ $i ];
					if ( $c === '{' ) { $depth++; } elseif ( $c === '}' ) { $depth--; if ( $depth === 0 ) { break; } }
					$body .= $c; $i++;
				}
				$i++; // skip closing }
				if ( $prelude !== '' && $prelude[0] === '@' ) {
					if ( stripos( $prelude, '@media' ) === 0 || stripos( $prelude, '@supports' ) === 0 ) {
						$inner = self::strip_grid_css( $body );
						if ( trim( $inner ) !== '' ) { $out .= $prelude . '{' . $inner . '}'; } // drop emptied @media
					} else {
						$out .= $prelude . '{' . $body . '}'; // keep @font-face / @keyframes
					}
				} elseif ( ! self::is_grid_only_selector( $prelude ) ) {
					$out .= $prelude . '{' . $body . '}';
				}
			} else {
				$buf .= $ch; $i++;
			}
		}
		return $out;
	}

	/** True when EVERY comma-part of the selector is a Bootstrap grid class (so the rule is droppable). */
	private static function is_grid_only_selector( $sel ) {
		$any = false;
		foreach ( explode( ',', (string) $sel ) as $p ) {
			$p = trim( $p );
			if ( $p === '' ) { continue; }
			$any = true;
			if ( ! preg_match( '/^\.(container(-fluid)?|row|no-gutters|col|col-auto|col-(1[0-2]|[1-9])|col-(sm|md|lg|xl|xxl)(-(auto|1[0-2]|[1-9]))?|offset-(sm|md|lg|xl|xxl)-(1[0-1]|[0-9])|offset-(1[0-1]|[0-9])|g[xy]?-[0-5])$/', $p ) ) {
				return false; // a non-grid part → keep the whole rule
			}
		}
		return $any;
	}

	/** Tidy a CSS string: drop empty rule-sets / @blocks and collapse blank lines. */
	public static function tidy_css( $css ) {
		$css = (string) $css;
		if ( $css === '' ) { return ''; }
		// Remove rule-sets whose body is empty (e.g. left behind after stripping animations/grid).
		$css = preg_replace( '/[^{}@]*\{\s*\}/', '', $css );
		// Remove now-empty @media / @supports wrappers.
		$css = preg_replace( '/@(?:media|supports)[^{]*\{\s*\}/i', '', $css );
		$css = preg_replace( '/[ \t]+\n/', "\n", $css );      // trailing whitespace
		$css = preg_replace( '/\n{3,}/', "\n\n", $css );      // collapse blank-line runs
		return trim( $css );
	}

	/**
	 * Block-builder REGISTRY — the second half of the expandable pipeline (the recognizer registry in
	 * class-fw-site-converter-stitch.php is the first). Maps a standalone block's role to the page-builder
	 * shortcode it becomes. Built-ins cover heading / text / button / code; teach the converter a NEW
	 * UnysonPlus shortcode by calling register_builder( $role, build($block)->item, $full_width ) — no core
	 * edits. $full_width = true gives the shortcode its OWN 1/1 column (flush the stack first) instead of
	 * stacking it. `code` is the universal fallback for any unmapped block.
	 */
	private static $builders = array();

	/** Register a block→shortcode builder. */
	public static function register_builder( $role, $build, $full_width = false ) {
		self::$builders[ $role ] = array( 'build' => $build, 'full_width' => (bool) $full_width );
	}

	/** The builder set (registers the built-ins on first use). */
	private static function builders() {
		if ( ! self::$builders ) { self::register_builtin_builders(); }
		return self::$builders;
	}

	/** How many block→shortcode builders are registered (proves the builder registry loaded). */
	public static function builder_count() {
		return count( self::builders() );
	}

	/** The built-in block→shortcode builders (the original elseif chain, now table-driven + extensible). */
	private static function register_builtin_builders() {
		self::register_builder( 'heading', function ( $b ) {
			$cw = ( isset( $b['cs'] ) && '' !== (string) $b['cs'] ) ? self::cs_decls( (string) $b['cs'], array( 'font-weight' ) ) : array();
			$node = self::n_heading( array( 'title' => (string) ( $b['html'] ?? $b['text'] ?? '' ), 'level' => (int) ( $b['level'] ?? 3 ), 'align' => $b['align'] ?? '', 'title_class' => (string) ( $b['cls'] ?? '' ), 'title_weight' => isset( $cw['font-weight'] ) ? $cw['font-weight'] : '', 'css_class' => (string) ( $b['wrapCls'] ?? '' ) ) );
			$cs = (string) ( $b['cs'] ?? '' );
			// Pass-1: source vertical margin → the special_heading's NATIVE spacing option (fill only sides
			// the class mapping left empty). Pass-2: the typography/color the unified styler already re-asserts
			// (`#sec h1{…}`) + native alignment/weight are $already; the base fills the remaining appearance
			// (a heading with a gradient text fill, underline, box-shadow, border, …).
			if ( isset( $node['atts']['spacing'] ) ) { $node['atts']['spacing'] = self::apply_native_margin( $node['atts']['spacing'], $cs ); }
			$node = self::apply_hifi_base( $node, $cs, array( 'font-family', 'font-size', 'font-weight', 'line-height', 'letter-spacing', 'color', 'text-transform', 'text-align' ) );
			return $node;
		} );
		self::register_builder( 'text', function ( $b ) {
			$cs   = (string) ( $b['cs'] ?? '' );
			$node = self::n_text( (string) ( $b['html'] ?? $b['text'] ?? '' ), (string) ( $b['maxWidth'] ?? '' ), (string) ( $b['align'] ?? '' ), $cs, (string) ( $b['cls'] ?? '' ) );
			// SINGLE SOURCE OF TRUTH per property: the Text Style preset owns font-size, the native
			// `text_color` option owns colour, the native `spacing` option owns vertical margins, and the
			// unified styler owns line-height + font-family (no native option). So ALL of those are $already
			// (EXCLUDED from the faithful base). The base fills only what nothing else covers (font-weight,
			// letter-spacing, text-transform, background, border, …).
			// FONT-SIZE de-dup: EXCLUDE font-size from the base ONLY when a preset is assigned (the preset
			// owns the size — no frozen per-node px shadowing it). With NO preset, EMIT font-size in the base
			// as the faithful px fallback.
			$props = array( 'font-family', 'line-height', 'color', 'text-align', 'margin-top', 'margin-bottom' );
			if ( ! empty( $node['atts']['font_size_preset'] ) ) {
				$props[] = 'font-size';
			}
			$node = self::apply_hifi_base( $node, $cs, $props );
			return $node;
		} );
		self::register_builder( 'button', function ( $b ) {
			// A button in a CENTERED band inherits centering from the parent's text-center — which n_button
			// can't see from the button's OWN classes, so it would default to align-self:flex-start (left).
			// section_center marks the block align=center; carry that into the class hint so the button centres.
			$bcls_in = (string) ( $b['srcCls'] ?? $b['cls'] ?? '' );
			if ( ( $b['align'] ?? '' ) === 'center' ) { $bcls_in .= ' text-center'; }
			$node = self::n_button( (string) ( $b['label'] ?? $b['text'] ?? 'Button' ), (string) ( $b['href'] ?? '#' ), $bcls_in, (string) ( $b['icon'] ?? '' ), (string) ( $b['iconPos'] ?? 'after' ), (string) ( $b['srcCs'] ?? $b['cs'] ?? '' ), (string) ( $b['groupCls'] ?? '' ), (string) ( $b['groupCs'] ?? '' ), (string) ( $b['iconSvg'] ?? '' ), (string) ( $b['srcHover'] ?? '' ) );
			// The color/size preset + the `.btn-fill` semantic class already reproduce the button's exact
			// fill / text color / border / radius / padding / typography (safety-net Custom CSS) = $already; the
			// base only fills leftover appearance (background-image gradient, opacity, transform, …).
			$node = self::apply_hifi_base( $node, (string) ( $b['srcCs'] ?? $b['cs'] ?? '' ), array( 'background-color', 'color', 'border', 'border-radius', 'box-shadow', 'font-family', 'font-size', 'font-weight', 'letter-spacing', 'text-transform' ) );
			return $node;
		} );
		self::register_builder( 'code', function ( $b ) {
			return self::n_code( (string) ( $b['html'] ?? '' ) );
		} );
		self::register_builder( 'video', function ( $b ) {
			return self::n_video( $b );
		} );
		self::register_builder( 'image', function ( $b ) {
			// `skinCss` (set by the image-composite decomposition) carries the source image's organic
			// radius / white border / shadow — and its blob backdrop — as scoped Custom CSS, so the
			// native media_image reproduces the look without falling back to a verbatim code_block.
			return self::n_media_image( (string) ( $b['html'] ?? '' ), (string) ( $b['skinCss'] ?? '' ) );
		} );
		self::register_builder( 'badge', function ( $b ) {
			$node = self::n_badge( $b );
			// The pill's colours are left neutral (user themes them); the faithful base re-asserts the
			// SOURCE fill / border / radius / text colour at specificity 0 so it looks right out of the box,
			// still overridable. Margins stay on the unified styler (.fw-announce) = $already.
			$node = self::apply_hifi_base( $node, (string) ( $b['pillCs'] ?? $b['cs'] ?? '' ), array() );
			return $node;
		} );
		self::register_builder( 'avatar', function ( $b ) {
			return self::n_avatar( $b );
		} );
		// A FLOATING BADGE/CARD overlaid on a hero image (e.g. a "24/7 Care" chip with an icon +
		// title + subtitle) → an editable icon_box, positioned + skinned over the image via the
		// node's scoped `posCss` (absolute top/left, bg, rounded, shadow). The icon/title/subtitle
		// stay editable instead of being frozen in a verbatim code_block. Part of the image-composite
		// decomposition (P0 fidelity fix); parity with the JS to-pages imgComposite path.
		self::register_builder( 'floating_card', function ( $b ) {
			$card = ( isset( $b['card'] ) && is_array( $b['card'] ) ) ? $b['card'] : array();
			$node = self::n_icon_box( $card );
			if ( is_array( $node ) && ! empty( $b['posCss'] ) ) {
				$cur = isset( $node['atts']['custom_css'] ) ? (string) $node['atts']['custom_css'] : '';
				$node['atts']['custom_css'] = trim( $cur . ( '' !== $cur ? "\n" : '' ) . (string) $b['posCss'] );
			}
			return $node;
		} );
	}

	/** A Tailwind spacing token → pixels: `[150px]`/`[5rem]` (arbitrary) or a scale step (12 → 48px). */
	private static function tw_len_px( $tok ) {
		if ( '' === $tok ) { return 0.0; }
		if ( '[' === $tok[0] ) {
			$v = trim( $tok, '[]' );
			if ( preg_match( '/^([0-9.]+)px$/', $v, $m ) ) { return (float) $m[1]; }
			if ( preg_match( '/^([0-9.]+)rem$/', $v, $m ) ) { return (float) $m[1] * 16.0; }
			return 0.0;
		}
		return (float) $tok * 4.0; // Tailwind scale: 1 = 0.25rem = 4px
	}

	/** Sum a section's vertical rhythm utilities (pt/pb/py + mt/mb/my) → top/bottom pixels, so the builder
	 *  section can reproduce the source's spacing (decompose sections otherwise have zero vertical spacing). */
	/** Carry the source <main>'s vertical padding (pt-32 pb-32 …) onto the theme's #main wrapper via a
	 *  scoped rule, the same container-styling mechanism as sections. Clean DOM (no class on <main>). */
	private static function main_style( $cls ) {
		if ( ! self::$style_on ) { return; }
		$vs = self::section_vspace( (string) $cls );
		$d = array();
		if ( $vs['pt'] > 0 ) { $d['padding-top']    = round( $vs['pt'] ) . 'px'; }
		if ( $vs['pb'] > 0 ) { $d['padding-bottom'] = round( $vs['pb'] ) . 'px'; }
		if ( ! $d ) { return; }
		$body = '';
		foreach ( $d as $pr => $v ) { $body .= $pr . ':' . $v . ' !important;'; }
		self::$style_css['sc-main'] = '#main.site-main,main#main{' . $body . '}';
	}

	/**
	 * Computed BOTTOM margin (px) from a `data-sc-cs` string — the authoritative title→subtitle gap.
	 * A heading's `mb-8` is stripped from the class before it reaches n_heading (numeric spacing utilities
	 * are dropped from title_class), so we read the resolved value straight from the computed style instead.
	 * Handles the `margin-bottom:32px` longhand AND the `margin:` shorthand (1–4 values → t/r/b/l). Returns
	 * null when absent or zero. (Mirror in JS to-pages.)
	 */
	private static function cs_margin_bottom_px( $cs ) {
		$cs = (string) $cs;
		if ( preg_match( '/(?:^|;)\s*margin-bottom:\s*([0-9.]+)px/i', $cs, $m ) ) {
			$v = (float) $m[1]; return $v > 0 ? $v : null;
		}
		if ( preg_match( '/(?:^|;)\s*margin:\s*([^;]+)/i', $cs, $m ) ) {
			$parts = preg_split( '/\s+/', trim( $m[1] ) );
			$px = function ( $s ) { return preg_match( '/^([0-9.]+)px$/', (string) $s, $p ) ? (float) $p[1] : null; };
			$n = count( $parts );
			$bottom = $n >= 3 ? $px( $parts[2] ) : ( $n === 2 ? $px( $parts[0] ) : ( $n === 1 ? $px( $parts[0] ) : null ) );
			return ( $bottom !== null && $bottom > 0 ) ? $bottom : null;
		}
		return null;
	}

	private static function section_vspace( $cls ) {
		$r = array( 'pt' => 0.0, 'pb' => 0.0, 'mt' => 0.0, 'mb' => 0.0 );
		if ( preg_match_all( '/(?:^|\s)([mp])([tby])-(\[[^\]]+\]|\d+(?:\.\d+)?)/', ' ' . (string) $cls . ' ', $ms, PREG_SET_ORDER ) ) {
			foreach ( $ms as $m ) {
				$v   = self::tw_len_px( $m[3] );
				$pre = ( 'p' === $m[1] ) ? 'p' : 'm';
				if ( 't' === $m[2] || 'y' === $m[2] ) { $r[ $pre . 't' ] += $v; }
				if ( 'b' === $m[2] || 'y' === $m[2] ) { $r[ $pre . 'b' ] += $v; }
			}
		}
		return $r;
	}

	/**
	 * UNIFIED ELEMENT STYLER — per-role STYLE PROFILES. Each entry says WHICH output selector carries the
	 * source style and WHICH curated properties to keep. This is the expandable table: add a role, a tag, a
	 * property, or a new selector here and it is picked up with NO other code change. `{h}` is replaced by the
	 * block's heading level (h1…h6). Prose tags are styled SECTION-SCOPED (`#hero h1`, `#hero .text-block`) so
	 * the tag itself stays class-free (clean DOM); distinctive elements (buttons/cards) keep their own class.
	 */
	private static function style_profiles() {
		return array(
			'title'    => array( 'sel' => '{h}',                        'props' => array( 'font-family', 'font-size', 'font-weight', 'line-height', 'letter-spacing', 'color', 'text-transform', 'max-width' ) ),
			'heading'  => array( 'sel' => '{h}',                        'props' => array( 'font-family', 'font-size', 'font-weight', 'line-height', 'letter-spacing', 'color', 'text-transform' ) ),
			'subtitle' => array( 'sel' => '.special-heading__subtitle', 'props' => array( 'font-family', 'font-size', 'font-weight', 'line-height', 'color', 'max-width' ) ),
			'overline' => array( 'sel' => '.special-heading__overline', 'props' => array( 'font-family', 'font-size', 'font-weight', 'line-height', 'letter-spacing', 'color', 'text-transform' ) ),
			// text_block now carries font-size (Text Style preset), colour (native text_color option) and
			// vertical margins (native spacing option) as EDITABLE node options — so the styler must NOT
			// re-assert them (that would double-apply / override the preset). It keeps only what has no
			// native option: font-family, line-height, and the constrained max-width.
			'text'     => array( 'sel' => '.text-block',                'props' => array( 'font-family', 'line-height', 'max-width' ) ),
			// A call_to_action band carries its heading's scale/colour onto the section-scoped `{h}` (the CTA
			// view renders the title as an <h2>), so a converted CTA keeps the source heading's large type.
			'cta'      => array( 'sel' => '{h}',                        'props' => array( 'font-family', 'font-size', 'font-weight', 'line-height', 'letter-spacing', 'color', 'text-transform', 'max-width' ) ),
			'badge' => array( 'sel' => '.fw-announce',       'props' => array( 'margin-top', 'margin-bottom' ) ),
		);
	}

	/** Compile a source element's classes (+ computed styles) → a declaration map filtered to $props. */
	private static function el_style_decls( $cls, $cs, array $props ) {
		$base = array();
		if ( '' !== (string) $cls && self::$style_on ) {
			$cm = FW_Site_Converter_Tailwind::compile_class_set( (string) $cls, self::$style_cfg );
			if ( is_array( $cm ) && isset( $cm['base'] ) && is_array( $cm['base'] ) ) { $base = $cm['base']; }
		}
		if ( '' !== (string) $cs ) { $base = array_merge( $base, self::cs_decls( (string) $cs, $props ) ); }
		$out = array();
		foreach ( $props as $pr ) { if ( isset( $base[ $pr ] ) && '' !== $base[ $pr ] ) { $out[ $pr ] = $base[ $pr ]; } }
		return $out;
	}

	/** Register a SECTION-SCOPED style rule (`#css_id selector { … }`) into the global stylesheet, de-duped. */
	private static function register_section_rule( $css_id, $selector, array $decls ) {
		if ( '' === (string) $css_id || ! $decls ) { return; }
		$sel  = '#' . $css_id . ( '' !== (string) $selector ? ' ' . $selector : '' );
		$body = '';
		foreach ( $decls as $pr => $v ) { $body .= $pr . ':' . $v . ' !important;'; }
		$key = md5( $sel . '|' . $body );
		if ( isset( self::$style_key[ $key ] ) ) { return; }
		self::$style_key[ $key ] = $sel;
		self::$style_css[ 'sec-' . substr( $key, 0, 8 ) ] = $sel . '{' . $body . '}';
	}

	/** Collect the section-scoped style rule for ONE prose block, driven by the expandable profile table. */
	/** Parse a CSS length ('32px' / '2rem' / '32') to a px float (0 when unparseable). */
	private static function px_of( $v ) {
		$v = trim( (string) $v );
		if ( '' === $v ) { return 0.0; }
		if ( preg_match( '/^(-?\d+(?:\.\d+)?)rem$/', $v, $m ) ) { return (float) $m[1] * 16; }
		if ( preg_match( '/^(-?\d+(?:\.\d+)?)px$/', $v, $m ) ) { return (float) $m[1]; }
		if ( preg_match( '/^(-?\d+(?:\.\d+)?)$/', $v, $m ) ) { return (float) $m[1]; }
		return 0.0;
	}

	private static function collect_section_style( $css_id, array $b ) {
		if ( '' === (string) $css_id ) { return; }
		$role     = isset( $b['role'] ) ? (string) $b['role'] : '';
		$profiles = self::style_profiles();
		if ( ! isset( $profiles[ $role ] ) ) { return; }
		$sel = $profiles[ $role ]['sel'];
		if ( false !== strpos( $sel, '{h}' ) ) {
			$lvl = max( 1, min( 6, (int) ( isset( $b['level'] ) ? $b['level'] : 2 ) ) );
			$sel = str_replace( '{h}', 'h' . $lvl, $sel );
		}
		$cls   = (string) ( isset( $b['cls'] ) ? $b['cls'] : ( isset( $b['pillCls'] ) ? $b['pillCls'] : '' ) );
		$decls = self::el_style_decls( $cls, (string) ( isset( $b['cs'] ) ? $b['cs'] : '' ), $profiles[ $role ]['props'] );
		// Carried wrapper margin: a flattened grouping wrapper's mb/mt lands on its boundary block (px).
		if ( ! empty( $b['mbAdd'] ) ) { $decls['margin-bottom'] = round( max( self::px_of( isset( $decls['margin-bottom'] ) ? $decls['margin-bottom'] : '' ), (float) $b['mbAdd'] ) ) . 'px'; }
		if ( ! empty( $b['mtAdd'] ) ) { $decls['margin-top']    = round( max( self::px_of( isset( $decls['margin-top'] ) ? $decls['margin-top'] : '' ), (float) $b['mtAdd'] ) ) . 'px'; }
		self::register_section_rule( $css_id, $sel, $decls );
	}

	/** Build one section node from its role-annotated blocks. */
	private static function build_section( array $sec ) {
		if ( ! empty( $sec['omit'] ) ) { return null; } // user dropped the whole section

		$src_cls = preg_split( '/\s+/', (string) ( $sec['sectionClass'] ?? '' ) );
		$src_cls = array_values( array_filter( $src_cls, function ( $c ) {
			// Drop slider/animation LIBRARY classes only — library prefixes (unique enough) plus
			// whole-token carousel/init/wow — NOT loose `carousel`/`init` prefixes that would eat
			// semantic section names like `carousel-section` or `initiatives`.
			if ( $c === '' ) { return false; }
			if ( preg_match( '/^(swiper|owl|slick|splide)(-|$)/i', $c ) ) { return false; }
			if ( preg_match( '/^aos(-|$)/i', $c ) ) { return false; }
			if ( preg_match( '/^(carousel|init|wow|animated)$/i', $c ) ) { return false; }
			return true;
		} ) );
		$src_cls_str = implode( ' ', $src_cls );
		$css_id      = isset( $sec['css_id'] ) ? sanitize_html_class( (string) $sec['css_id'] ) : '';
		if ( '' === $css_id ) { $css_id = 'sc-sec-' . substr( md5( wp_json_encode( isset( $sec['blocks'] ) ? $sec['blocks'] : $sec ) ), 0, 6 ); } // generated id so the unified styler can scope rules
		$css         = (string) ( $sec['css'] ?? '' );

		// "As one code-block": keep the section verbatim. The source's own .container is inside
		// the HTML and centers the content, so the builder section is FULL-WIDTH + `sc-mirror`
		// (its container/gutters are reset away so the source markup owns the layout).
		if ( ! empty( $sec['verbatim'] ) ) {
			$html = (string) ( $sec['raw'] ?? '' );
			if ( $html === '' ) { return null; }
			return self::n_section( trim( 'sc-mirror ' . $src_cls_str ), $css_id, $css, array( self::n_column( '1_1', array( self::n_code( $html ) ) ) ), true );
		}

		// Decomposed sections are anchored on their CSS ID (#banner) for authority, so drop the
		// source class that duplicates the id (the section had id="banner" AND class="banner …").
		// The matching `.banner` → `#banner` selector rewrite happens in page_css().
		if ( $css_id !== '' ) {
			$src_cls = array_values( array_filter( $src_cls, function ( $c ) use ( $css_id ) {
				return $c !== $css_id;
			} ) );
			$src_cls_str = implode( ' ', $src_cls );
		}

		// Decomposed: the source's wrapper chain (.container/.row/.col/.block …) is gone, so flatten
		// selectors to section-anchor + leaf (e.g. `.banner .block h1` → `.banner h1`) so the source
		// CSS maps onto our rebuilt elements. Verbatim sections (above) keep their wrappers, so theirs
		// is left untouched.
		$css = self::flatten_css( $css );

		$blocks = isset( $sec['blocks'] ) && is_array( $sec['blocks'] ) ? $sec['blocks'] : array();
		$blocks = self::transform_badge_overlines( $blocks ); // chip-before-heading → the heading's overline

		// A full-screen background <video> (extractor-flagged `bg` = absolute + object-cover behind the
		// content) becomes the SECTION's background video — not a content media_video block. Pull it out
		// here; it's wired onto the section node after the node is built. The actual file comes from the
		// captured <source> URL, or the matching "Attach media" upload (upload_val handles the swap).
		$bg_video = null;
		// RECURSIVE — a HERO background <video> is commonly nested inside the overlay's column/row (source
		// `<div class="absolute inset-0"><video …></div>` beside the heading column), NOT a top-level section
		// block. The old top-level-only scan missed it, so a video-bg hero shipped with no background and no
		// hero framing (it collapsed to a short top-aligned band). Walk the same block tree the bg-image strip
		// uses (column cells → 'blocks'; rows → 'cols'). (P2 audit fix.)
		$find_bgv = function ( array &$list ) use ( &$find_bgv, &$bg_video ) {
			foreach ( $list as $k => &$blk ) {
				if ( ! is_array( $blk ) ) { continue; }
				$bt = isset( $blk['t'] ) ? $blk['t'] : ( isset( $blk['role'] ) ? $blk['role'] : '' );
				if ( $bt === 'video' && ! empty( $blk['bg'] ) ) { $bg_video = $blk; unset( $list[ $k ] ); return true; }
				if ( isset( $blk['blocks'] ) && is_array( $blk['blocks'] ) && $find_bgv( $blk['blocks'] ) ) { return true; }
				if ( isset( $blk['cols'] ) && is_array( $blk['cols'] ) ) {
					foreach ( $blk['cols'] as &$col ) { if ( is_array( $col ) && isset( $col['blocks'] ) && is_array( $col['blocks'] ) && $find_bgv( $col['blocks'] ) ) { return true; } }
					unset( $col );
				}
			}
			unset( $blk );
			return false;
		};
		$find_bgv( $blocks );
		$blocks = array_values( array_filter( $blocks ) );

		// HERO full-bleed BACKGROUND image → the section Background (below), NOT a tiny inline media_image.
		// Drop the duplicate content image block whose src matches the detected background photo.
		$bg_image = ( isset( $sec['sectionBgImage'] ) && is_array( $sec['sectionBgImage'] ) && ! empty( $sec['sectionBgImage']['src'] ) ) ? $sec['sectionBgImage'] : null;
		if ( $bg_image ) {
			// Remove the same <img> from the CONTENT so it isn't ALSO emitted as a media_image (the hero photo
			// is now the section's background). RECURSIVE — the img may be nested inside a column/row, not a
			// top-level block. Slash-insensitive — `wp_json_encode` escapes `/`→`\/`, so a plain `https://…`
			// never matched the escaped form and the duplicate media_image survived.
			$bsrc = (string) $bg_image['src'];
			$needles = array( $bsrc, str_replace( '/', '\/', $bsrc ) );
			$strip = function ( array &$list ) use ( &$strip, $needles ) {
				$done = false;
				foreach ( $list as $k => &$blk ) {
					if ( ! is_array( $blk ) ) { continue; }
					$bt = isset( $blk['t'] ) ? $blk['t'] : ( isset( $blk['role'] ) ? $blk['role'] : '' );
					if ( $bt === 'image' ) {
						$enc = wp_json_encode( $blk );
						foreach ( $needles as $n ) { if ( $n !== '' && strpos( $enc, $n ) !== false ) { unset( $list[ $k ] ); $done = true; break 2; } }
					}
					// Recurse into nested containers (column cells → 'blocks'; rows → 'cols').
					if ( ! $done && isset( $blk['blocks'] ) && is_array( $blk['blocks'] ) ) { if ( $strip( $blk['blocks'] ) ) { $done = true; break; } }
					if ( ! $done && isset( $blk['cols'] ) && is_array( $blk['cols'] ) ) {
						foreach ( $blk['cols'] as &$col ) { if ( is_array( $col ) && isset( $col['blocks'] ) && is_array( $col['blocks'] ) && $strip( $col['blocks'] ) ) { $done = true; break; } }
						unset( $col );
						if ( $done ) { break; }
					}
				}
				unset( $blk );
				return $done;
			};
			$strip( $blocks );
			$blocks = array_values( array_filter( $blocks ) );
		}

		$items = array();   // section's columns
		$buf   = array();   // pending stacked items for a 1_1 column
		$head  = null;      // pending special_heading accumulator

		// The section's content-column responsive widths (col-lg-10 col-md-6 …) → the builder
		// intro column's grid CONTROLS (outer column), not css_class (which would nest a div).
		$col_lay = self::col_layout( isset( $sec['colClass'] ) ? $sec['colClass'] : '' );

		// A styling wrapper inside the content column (source `<div class="cta-content bg-white p-5
		// rounded">`) → the builder column's Inner Wrapper Class. Decomposition drops the wrapper div,
		// so the capture hands us its class as innerWrapClass; replay it onto the column's inner wrapper.
		$inner_wrap = self::keep_classes( (string) ( $sec['innerWrapClass'] ?? '' ) );

		// The source section centers its content (flex items-center / text-center) → the builder column
		// centers its content horizontally, so the heading, text and buttons all sit centered like the source.
		$center = ! empty( $sec['align'] ) && $sec['align'] === 'center';

		$flush_head = function () use ( &$head, &$buf ) {
			if ( $head !== null ) {
				// Skip a title-less, overline-less head whose only content is a LINK subtitle (a mis-folded
				// "View All →" CTA). A plain-text subtitle-only head stays. Same guard as build_cell_items.
				if ( ! self::head_is_stray_link( $head ) ) { $buf[] = self::n_heading( $head ); }
				$head = null;
			}
		};
		$flush_buf = function () use ( &$buf, &$items, &$flush_head, $col_lay, $inner_wrap, $center ) {
			$flush_head();
			if ( $buf ) {
				$buf = self::group_buttons( $buf ); // consecutive buttons → one side-by-side flex-row group
				$w = ( $col_lay !== null ) ? $col_lay['width'] : '1_1';
				$col = self::n_column( $w, $buf, '', $col_lay !== null ? $col_lay : array() );
				// A buffered FLOATING CARD (an image-composite icon_box positioned `absolute` via its scoped
				// posCss) needs a POSITIONED ANCESTOR, or it anchors to the section/page and lands top-left.
				// Make this column the containing block. (P0-C fidelity fix; parity with the cols path + JS.)
				foreach ( $buf as $bn ) {
					if ( is_array( $bn ) && ( $bn['shortcode'] ?? '' ) === 'icon_box'
						&& strpos( (string) ( $bn['atts']['custom_css'] ?? '' ), 'position:absolute' ) !== false ) {
						$cur = isset( $col['atts']['custom_css'] ) ? (string) $col['atts']['custom_css'] : '';
						$col['atts']['custom_css'] = trim( $cur . ( '' !== $cur ? "\n" : '' ) . 'selector{position:relative;}' );
						break;
					}
				}
				if ( $inner_wrap !== '' ) { $col['atts']['inner_class'] = $inner_wrap; }
				if ( $center ) {
					$col['atts']['content_h'] = 'center';
					// The centered source wrapper (its `text-center` class) that decomposes into this
					// intro column holds MIXED children (heading + paragraph + buttons) — set the
					// column's native `text_align='center'` so text-align cascades to all of them
					// (content_h is the flexbox axis, a different concern). Redundant-but-safe with
					// the section text_align; both are the inherited property, so idempotent.
					$col['atts']['text_align'] = 'center';
				}
				$items[] = $col;
				$buf = array();
			}
		};

		$grid_gap_px = 0.0; // Pass #5: the section's first grid/row inter-column gap → section Gap option
		foreach ( $blocks as $b ) {
			if ( isset( $b['include'] ) && ! $b['include'] ) { continue; } // unchecked → omit
			$role = isset( $b['role'] ) ? $b['role'] : 'code';
			if ( $role === 'skip' ) { continue; }
			self::collect_section_style( $css_id, $b ); // unified element styler: section-scoped prose styling

			// A WIDE media widget (image_overlay player / hero graphic — source `w-full max-w-[…]`) → its
			// OWN full-width column, centered. In the shared intro column of a CENTERED section the flex
			// centering shrinks the content row to the buttons' width, which squeezes the media (its
			// max-width can't expand). A dedicated 1_1 column lets the source max-width fill + center.
			if ( ! empty( $b['wide'] ) ) {
				$flush_buf();
				// Center the media (source max-w-[…]) at full available width, in its OWN 1_1 column. Make
				// the sc-tw wrapper a flex-center container so its card is the DIRECT flex child: the card's
				// w-full then fills the column and its max-width caps + centers it. (A plain block leaves the
				// card left-aligned; content_h:center or an extra flex WRAPPER shrinks the intermediate
				// sc-tw div to content width instead — the card stayed ~514px.)
				$html = (string) ( $b['html'] ?? '' );
				$html = preg_replace( '/<div class="sc-tw">/', '<div class="sc-tw" style="display:flex;justify-content:center;width:100%;">', $html, 1 );
				$items[] = self::n_column( '1_1', array( self::n_code( $html ) ) );
				continue;
			}

			// A gallery grid (de-cloned image-card carousel, source `.container-fluid`) is full-width,
			// so it gets its OWN 1_1 column instead of inheriting the intro column's col-* width (which
			// would cramp the 3-up grid). Flush the pending heading column first to keep order.
			if ( ! empty( $b['gallery'] ) ) {
				$flush_buf();
				$gallery_html = self::fwgrid_classes( (string) ( $b['html'] ?? '' ) );
				// Full-width Container band (matches the source's `.container-fluid` wrapping the
				// gallery). The corrector wraps the code leaf into a row/column inside the container
				// and renders it as a sibling after the section's default container — full-bleed,
				// not constrained to the boxed section width.
				$items[] = self::n_container( array( self::n_code( $gallery_html ) ), true );
				continue;
			}

			// A testimonials collection → the `testimonials` shortcode in its own full-width column
			// (the shortcode renders its own container_type). Content only; design not preserved.
			if ( ( $b['t'] ?? '' ) === 'testimonials' && ! empty( $b['items'] ) && is_array( $b['items'] ) ) {
				$flush_buf();
				$node = self::n_testimonials( $b['items'], isset( $b['design'] ) && is_array( $b['design'] ) ? $b['design'] : null );
				self::apply_block_anim( $node, $b );
				$items[] = self::n_column( '1_1', array( $node ) );
				continue;
			}

			// A logo / "trusted by" strip → the native `logo_grid` shortcode in its own full-width column.
			if ( ( $b['t'] ?? '' ) === 'logo_grid' && ! empty( $b['logos'] ) && is_array( $b['logos'] ) ) {
				$flush_buf();
				$node = self::n_logo_grid( $b );
				self::apply_block_anim( $node, $b );
				$items[] = self::n_column( '1_1', array( $node ) );
				continue;
			}

			// A CALL-TO-ACTION band (heading + subtext + one button) → the native `call_to_action` shortcode
			// in its own full-width column. The section's own band fill still lands as variant/native bg.
			if ( ( $b['t'] ?? '' ) === 'cta' ) {
				$flush_buf();
				$node = self::n_cta( $b );
				self::apply_block_anim( $node, $b );
				$items[] = self::n_column( '1_1', array( $node ) );
				continue;
			}

			// A section-intro HEADING + a trailing "View All →" CTA link laid out `flex justify-between`
			// (heading LEFT, link RIGHT) → ONE column using the native Inline direction + Space-Between
			// alignment, so the link sits top-right next to the heading (instead of stacking below).
			if ( ( $b['t'] ?? '' ) === 'heading_cta' ) {
				$flush_buf();
				$node = self::n_heading_cta( $b );
				if ( is_array( $node ) ) { self::apply_block_anim( $node, $b ); $items[] = $node; }
				continue;
			}

			// A data `<table>` → the native `table` shortcode (tabular render mode) in its own full-width column.
			if ( ( $b['t'] ?? '' ) === 'table' && ! empty( $b['rows'] ) && is_array( $b['rows'] ) ) {
				$flush_buf();
				$node = self::n_table( $b );
				self::apply_block_anim( $node, $b );
				$items[] = self::n_column( '1_1', array( $node ) );
				continue;
			}

			// An accordion / FAQ (<details> or aria-toggle group) → the native `accordion` shortcode.
			if ( ( $b['t'] ?? '' ) === 'accordion' && ! empty( $b['items'] ) && is_array( $b['items'] ) ) {
				$flush_buf();
				$node = self::n_accordion( $b );
				self::apply_block_anim( $node, $b );
				$items[] = self::n_column( '1_1', array( $node ) );
				continue;
			}

			// A standalone <ul>/<ol> of text items → the native `feature_list` shortcode.
			if ( ( $b['t'] ?? '' ) === 'feature_list' && ! empty( $b['items'] ) && is_array( $b['items'] ) ) {
				$flush_buf();
				$node = self::n_feature_list( $b );
				self::apply_block_anim( $node, $b );
				$items[] = self::n_column( '1_1', array( $node ) );
				continue;
			}

			// The interactive / structured native widgets — each recognized as its own block type and rendered
			// in its own full-width column: tabs / steps / timeline / progress / pricing-table / lottie / svg-draw.
			$native_own = array(
				'tabs'      => 'n_tabs',
				'steps'     => 'n_steps',
				'timeline'  => 'n_timeline',
				'progress'  => 'n_progress',
				'pricing'   => 'n_pricing',
				'lottie'    => 'n_lottie',
				'svg_draw'  => 'n_svg_draw',
				'instagram' => 'n_instagram', // detected Instagram feed → the [instagram] Library shortcode
				'gallery'   => 'n_gallery',   // detected image-tile grid → the native gallery shortcode
				'posts'     => 'n_posts',     // detected blog listing → the dynamic posts query shortcode
			);
			$bt_native = $b['t'] ?? '';
			if ( isset( $native_own[ $bt_native ] ) ) {
				$flush_buf();
				$node = call_user_func( array( __CLASS__, $native_own[ $bt_native ] ), $b );
				if ( is_array( $node ) ) {
					self::apply_block_anim( $node, $b );
					$items[] = self::n_column( '1_1', array( $node ) );
				}
				continue;
			}

			// Reliable heading intro: a plain paragraph RIGHT AFTER a title (head open, title set, no subtitle
			// yet) reads as the heading's SUBTITLE — but ONLY a short single paragraph (a genuine intro line,
			// not body copy). This also fires on DECOMPOSED content columns (hero / CTA), which never went
			// through suggest_mapping's subtitle pass — the reason the subtitle was "almost never used". Longer
			// / multi-paragraph / block-level copy stays a Text Block. Parity mirrored in JS to-pages.
			if ( $role === 'text' && $head !== null && '' !== (string) ( $head['title'] ?? '' ) && '' === (string) ( $head['subtitle'] ?? '' )
				&& self::is_heading_subtitle( $b ) ) {
				$role = 'subtitle';
			}
			if ( in_array( $role, array( 'overline', 'title', 'subtitle' ), true ) ) {
				// title + subtitle keep INLINE html (links / <strong> / <em> survive into the minimal wp-editor
				// subtitle field, which the view renders via wp_kses_post); a subtitle's outer <p> wrapper is
				// stripped since the view re-wraps it in <p class="heading-subtitle">.
				$val = in_array( $role, array( 'title', 'subtitle' ), true )
					? self::heading_part_inline_html( (string) ( $b['html'] ?? $b['text'] ?? '' ), $role )
					: trim( (string) ( $b['text'] ?? '' ) );
				$fresh = array( 'overline' => '', 'title' => '', 'subtitle' => '', 'overline_class' => '', 'title_class' => '', 'subtitle_class' => '', 'css_class' => '', 'level' => (int) ( $b['level'] ?? 2 ), 'align' => $b['align'] ?? '', 'wrapMaxW' => (string) ( $b['wrapMaxW'] ?? '' ) );
				if ( $head === null ) { $head = $fresh; }
				// A second value for an already-filled slot starts a fresh heading.
				if ( $head[ $role ] !== '' ) { $flush_head(); $head = $fresh; }
				$head[ $role ] = $val;
				// Carry the source part's own classes onto the matching class input.
				$head[ $role . '_class' ] = (string) ( $b['cls'] ?? '' );
				// Carry chip-before-heading pill metadata onto the head so n_heading emits the filled-pill overline.
				if ( 'overline' === $role ) {
					if ( isset( $b['overline_svg'] ) )   { $head['overline_svg']   = (string) $b['overline_svg']; }
					if ( ! empty( $b['overline_pill'] ) ) { $head['overline_pill']  = true; }
					if ( isset( $b['overline_color'] ) ) { $head['overline_color'] = (string) $b['overline_color']; }
				}
				// Carry the part's RESOLVED computed font-weight (data-sc-cs) so the heading re-asserts its
				// real weight even on a NON-Tailwind source (where the class carries no font-* utility).
				if ( isset( $b['cs'] ) && '' !== (string) $b['cs'] ) {
					$cw = self::cs_decls( (string) $b['cs'], array( 'font-weight', 'font-size', 'color', 'text-transform' ) );
					if ( isset( $cw['font-weight'] ) ) { $head[ $role . '_weight' ] = $cw['font-weight']; }
					// Capture the part's computed font-size (px) so n_heading can assign the matching Text
					// Style preset (e.g. an 18px subtitle → the `font-subtitle` preset) instead of losing its
					// size when the source utility class is stripped.
					if ( isset( $cw['font-size'] ) && preg_match( '/^([0-9.]+)px$/', (string) $cw['font-size'], $fm ) ) {
						$head[ $role . '_fs' ] = (float) $fm[1];
					}
					// Capture the part's computed COLOR so n_heading can set the native subtitle/title/overline
					// color option — a muted subtitle (e.g. text-foreground/70 → rgba(41,61,54,.7)) was rendering
					// at the theme default because the color was dropped.
					if ( isset( $cw['color'] ) && trim( (string) $cw['color'] ) !== '' ) {
						$head[ $role . '_color_src' ] = trim( (string) $cw['color'] );
					}
					// OVERLINE: the gold + UPPERCASE + letter-spaced kicker. The source overline
					// (`<span class="text-primary uppercase tracking-[0.3em]">`) drops both its accent colour and
					// its casing when only the text survives, rendering a muted, un-transformed label. Restore the
					// native controls from the computed style: overline_color (accent) + overline_transform (drives
					// overline_uppercase='yes' in n_heading, i.e. the letter-spaced uppercase treatment).
					if ( 'overline' === $role ) {
						if ( empty( $head['overline_color'] ) && isset( $cw['color'] ) && trim( (string) $cw['color'] ) !== '' && ! self::is_default_ink( (string) $cw['color'] ) ) {
							$head['overline_color'] = trim( (string) $cw['color'] );
						}
						if ( isset( $cw['text-transform'] ) && trim( (string) $cw['text-transform'] ) !== '' ) {
							$head['overline_transform'] = strtolower( trim( (string) $cw['text-transform'] ) );
						}
					}
				}
				// The heading-group wrapper class (source `<div class="heading">`) → the special
				// heading's own wrapper (css_class). Carried from the title block (the wrapper holds
				// the whole group). keep_classes runs in n_heading.
				if ( $role === 'title' && isset( $b['cs'] ) && '' !== (string) $b['cs'] ) {
					// TITLE computed bottom margin → the title→subtitle gap (never-drop). `mb-8` is stripped
					// from title_class, so read the resolved px from the computed style; n_heading carries it
					// verbatim as scoped `.heading-title{margin-bottom:Npx}`. (Mirror in JS to-pages.)
					$mb = self::cs_margin_bottom_px( (string) $b['cs'] );
					if ( $mb !== null ) { $head['title_mb_px'] = $mb; }
				}
				if ( $role === 'title' ) {
					$head['level'] = (int) ( $b['level'] ?? 2 );
					$head['align'] = $b['align'] ?? $head['align'];
					if ( ! empty( $b['wrapCls'] ) ) { $head['css_class'] = (string) $b['wrapCls']; }
					if ( ! empty( $b['wrapMaxW'] ) ) { $head['wrapMaxW'] = (string) $b['wrapMaxW']; }
				}
				continue;
			}
			$flush_head();

			if ( $role === 'columns' && isset( $b['cols'] ) && is_array( $b['cols'] ) ) {
				$flush_buf();
				// Pass #5: remember this section's grid gap (source `gap-8`/computed column-gap) so the
				// distillation below can snap it onto the section's NATIVE Gap option (first grid wins).
				if ( $grid_gap_px <= 0 && isset( $b['gap'] ) && (float) $b['gap'] > 0 ) { $grid_gap_px = (float) $b['gap']; }
				// A PRODUCT-CARD grid (≥60% of cells = image + price) → ONE wc_products grid, not N
				// icon_boxes (parity with the JS to-pages recognizer). Configure Source to your products.
				$prod_cells = 0;
				foreach ( $b['cols'] as $pc ) { if ( is_array( $pc ) && self::cell_is_product( $pc ) ) { $prod_cells++; } }
				if ( count( $b['cols'] ) >= 2 && $prod_cells >= (int) ceil( count( $b['cols'] ) * 0.6 ) ) {
					$pcols = max( 2, min( 4, count( $b['cols'] ) ) );
					// Translate the source cards' skin/hover + ribbon (captured on each product cell) →
					// scoped CSS, and turn the Ribbon Badge slot ON when a badge was detected (parity
					// with the JS to-pages recognizer). A placeholder grid can't carry the real per-product
					// ribbon TEXT (product meta), but show_ribbon:'yes' + the skin reproduce the look.
					$skin_wrap = null; $skin_ribbon = null; $has_ribbon = false;
					foreach ( $b['cols'] as $pc ) {
						if ( ! is_array( $pc ) ) { continue; }
						if ( null === $skin_wrap && ! empty( $pc['wrap'] ) ) { $skin_wrap = $pc['wrap']; }
						if ( ! empty( $pc['ribbon'] ) ) { $has_ribbon = true; if ( null === $skin_ribbon ) { $skin_ribbon = $pc['ribbon']; } }
					}
					self::register_wc_card_css( $skin_wrap, $has_ribbon ? $skin_ribbon : null );
					$items[] = self::n_column( '1_1', array( self::n_wc_products( $pcols, count( $b['cols'] ), $has_ribbon ) ) );
					continue;
				}
				// Row vertical alignment (source `.row.align-items-center` …) → each column's Content
				// Vertical Align. Skipped on the grid column (the height-definer where it's redundant).
				$row_valign = isset( $b['valign'] ) && in_array( $b['valign'], array( 'start', 'center', 'end' ), true ) ? $b['valign'] : '';
				// Row-level width pre-pass: a flex row mixing a FIXED-px column (`w-[506px]`) with a flex-1
				// column otherwise falls back to an even split (both 1_2), unbalanced (the fixed visual gets half
				// instead of ~40%). Derive the fixed column's 12-fraction from its px; flex-1 column(s) take the
				// remainder. Empty unless it's a clean fixed-px + flex-1 mix. (P3 audit fix.)
				$row_widths = self::flex_row_widths( $b['cols'] );
				foreach ( $b['cols'] as $ci => $c ) {
					$box_on_column = ''; // box class for this column's Inner Wrapper Class (box card WITH a button)
						$btn_row_on_column = ''; // .btn-row class for a CTA button-group cell (side-by-side buttons)
						// A cell whose decomposed blocks include a FLOATING CARD (image-composite decompose:
						// media_image + an absolutely-positioned icon_box) needs a POSITIONED ANCESTOR, or the
						// card's `position:absolute; top/left` resolves against the section/page and lands at the
						// page's top-left (overlapping the logo). Marking the containing COLUMN position:relative
						// anchors the card to the image area. (P0-C fidelity fix; parity with JS to-pages.)
						$col_relative = false;
						if ( isset( $c['blocks'] ) && is_array( $c['blocks'] ) ) {
							foreach ( $c['blocks'] as $cbk ) {
								if ( is_array( $cbk ) && isset( $cbk['role'] ) && 'floating_card' === $cbk['role'] ) { $col_relative = true; break; }
							}
						}
					// Cell content: a nested card-grid → a CSS-grid column of icon_boxes; a text cell
					// → special_heading (+ text); a single icon card → icon_box; else the verbatim
					// HTML as a code-block.
					// A reviewer-chosen role for this column's content overrides the auto-detected shortcode
					// (e.g. force a card to a plain Text Block or a verbatim Code Block).
					$crole = isset( $c['role'] ) ? (string) $c['role'] : '';
					if ( $crole !== '' && ! in_array( $crole, array( 'columns', 'skip' ), true ) ) {
						$chtml = '';
						if ( ! empty( $c['card'] ) ) { $chtml = self::card_to_html( $c['card'] ); }
						elseif ( isset( $c['text'] ) ) { $chtml = is_array( $c['text'] ) ? self::text_cell_to_html( $c['text'] ) : (string) $c['text']; }
						else { $chtml = (string) ( $c['html'] ?? '' ); }
						$inner_items = self::cell_by_role( $crole, $c, $chtml );
					} elseif ( isset( $c['grid'] ) && is_array( $c['grid'] ) && ! empty( $c['grid']['cells'] ) ) {
						// Nested card-grid -> NESTED COLUMNS (one per card, at the nested cell's width). Unyson+
						// nests columns natively: a column whose _items are columns wraps as a row.
						$inner_items = array();
						foreach ( $c['grid']['cells'] as $gc ) {
							if ( ! empty( $gc['card'] ) )     { $gi = array( self::n_icon_box( $gc['card'] ) ); }
							elseif ( ! empty( $gc['text'] ) ) { $gi = self::n_text_cell( $gc['text'] ); }
							else                              { $gi = array( self::n_code( (string) ( $gc['html'] ?? '' ) ) ); }
							$nlay = self::col_layout( (string) ( $gc['cls'] ?? '' ) );
							if ( $nlay === null ) { $nlay = self::geom_layout( isset( $gc['wResp'] ) ? $gc['wResp'] : null ); }
							$nwidth = ( $nlay !== null ) ? $nlay['width'] : self::frac12( (int) round( 12 / max( 1, (int) ( $c['grid']['gridCols'] ?? 2 ) ) ) );
							$inner_items[] = self::n_column( $nwidth, $gi ); // nested column
						}
					} elseif ( isset( $c['counter'] ) && is_array( $c['counter'] ) ) {
						// Animated stat: a `counter` shortcode + the label as a text_block below.
						$inner_items = array( self::n_counter( $c['counter'] ) );
						$clbl = trim( (string) ( $c['counter']['label'] ?? '' ) );
						if ( $clbl !== '' ) { $inner_items[] = self::n_text( '<p>' . esc_html( $clbl ) . '</p>' ); }
					} elseif ( isset( $c['text'] ) && is_array( $c['text'] ) ) {
						$inner_items = self::n_text_cell( $c['text'] );
					} elseif ( isset( $c['card'] ) && is_array( $c['card'] ) && ! empty( $c['card']['image']['src'] ) ) {
						// IMAGE TILE — a prominent photo + title + text (+ CTA / hover explore). A TITLED tile is the
						// image_box pattern → emit ONE cohesive image_box (image + title + text + button + design
						// family + hover), so the tile semantics survive. An image-only / untitled blob falls back to
						// the decomposed image_card (media_image + heading + text). The box skin (border/radius/shadow)
						// goes on the image_box's own css_class (tile) / the column's Inner Wrapper (decomposed).
						$card = $c['card'];
						$cc   = (string) ( $card['cls'] ?? '' );
						$ccs  = (string) ( $card['cs'] ?? '' );
						$is_box = self::is_box_class( $cc ) || self::cs_is_box( $ccs );
						$titled = '' !== trim( wp_strip_all_tags( (string) ( $card['title'] ?? '' ) ) );
						$ibx    = $titled ? self::n_image_box( $card ) : array();
						if ( ! empty( $ibx ) ) {
							if ( $is_box ) { $ibx['atts']['css_class'] = trim( (string) ( $ibx['atts']['css_class'] ?? '' ) . ' ' . self::box_style_class( $cc, $ccs ) ); }
							$inner_items = array( $ibx );
						} else {
							$inner_items = self::n_image_card( $card );
							if ( $is_box ) { $box_on_column = self::box_style_class( $cc, $ccs ); }
						}
					} elseif ( isset( $c['card'] ) && is_array( $c['card'] ) ) {
						$card = $c['card'];
						$cc   = (string) ( $card['cls'] ?? '' );
						$ccs  = (string) ( $card['cs'] ?? '' ); // the card container's resolved computed style (data-sc-cs)
						$ib   = self::n_icon_box( $card );
						$is_box = self::is_box_class( $cc ) || self::cs_is_box( $ccs );
						if ( $is_box && empty( $card['button'] ) ) {
							// Simple box card (ONLY icon + title + content — fits the icon_box) → the box
							// styling goes on the ICON_BOX itself (css_class).
							$ib['atts']['css_class'] = trim( $ib['atts']['css_class'] . ' ' . self::box_style_class( $cc, $ccs ) );
							$inner_items = array( $ib );
						} elseif ( $is_box && ! empty( $card['button'] ) ) {
							// Box card WITH a button (the icon_box has no button slot) → render icon_box +
							// button in the column, and move the box styling to the COLUMN's Inner Wrapper
							// Class so it wraps BOTH. (See the CSS-class-mapper rule in CLAUDE.md.) n_icon_box already
							// set a CLEAN css_class (alignment + gray icon box, no cell box classes), so nothing to reset.
							$bt = $card['button'];
							$inner_items = array( $ib, self::n_button( (string) ( $bt['label'] ?? 'Button' ), (string) ( $bt['href'] ?? '#' ), (string) ( $bt['cls'] ?? '' ), (string) ( $bt['icon'] ?? '' ), 'after', (string) ( $bt['cs'] ?? '' ) ) );
							$box_on_column = self::box_style_class( $cc, $ccs );
						} else {
							$inner_items = array( $ib );
						}
					} elseif ( ! empty( $c['buttons'] ) && is_array( $c['buttons'] ) ) {
						// A CTA button-group cell (no heading/prose) → real button shortcodes. Two+ buttons
						// would STACK in a builder column, so wrap them in a `.btn-row` flex-row Inner Wrapper
						// Class (mirrors the capture service's btn-row). Matches the JS to-pages path.
						$inner_items = array();
						foreach ( $c['buttons'] as $bt ) {
							$inner_items[] = self::n_button( (string) ( $bt['label'] ?? 'Button' ), (string) ( $bt['href'] ?? '#' ), (string) ( $bt['cls'] ?? '' ), (string) ( $bt['icon'] ?? '' ), 'after', (string) ( $bt['cs'] ?? '' ) );
						}
						if ( count( $inner_items ) > 1 ) { $btn_row_on_column = 'row'; }
					} elseif ( isset( $c['blocks'] ) && is_array( $c['blocks'] ) && $c['blocks'] ) {
						// A DECOMPOSED layout-row cell (a hero's text+CTA column) → build its blocks into the
						// normal stacked shortcodes (special_heading + button… + …), NOT one opaque code_block.
						$inner_items = self::build_cell_items( $c['blocks'] );
						if ( ! $inner_items ) { $inner_items = array( self::n_code( (string) ( $c['html'] ?? '' ) ) ); }
					} else {
						$inner_items = array( self::n_code( (string) ( $c['html'] ?? '' ) ) );
					}
					// Column widths → the column's responsive width controls (outer grid, no nested div):
					// Bootstrap col-* first; else framework-agnostic measured widths (Tailwind/custom);
					// else even division across the row.
					if ( ! empty( $c['width'] ) && preg_match( '/^\d+_\d+$/', (string) $c['width'] ) ) {
						$width = (string) $c['width']; // reviewer-chosen column width wins
					} else {
						if ( isset( $row_widths[ $ci ] ) ) { $width = $row_widths[ $ci ]; } // fixed-px/flex-1 row split
						else {
							$lay = self::col_layout( (string) ( $c['cls'] ?? '' ) );
							if ( $lay === null ) { $lay = self::geom_layout( isset( $c['wResp'] ) ? $c['wResp'] : null ); }
							$width = ( $lay !== null ) ? $lay['width'] : self::cell_width( $c['width'] ?? '1_3' );
						}
					}
					$col   = self::n_column( $width, $inner_items );
					// Responsive VISIBILITY: a source column with Tailwind show/hide utilities (`hidden lg:block`
					// = desktop-only, `lg:hidden` = hide desktop) → the column's native Hide-on-Device option, so
					// the desktop/mobile variant pair each hides on the right breakpoints instead of both showing.
					$col_rhide = self::responsive_hide_from_classes( (string) ( $c['cls'] ?? '' ) );
					if ( ! empty( $col_rhide ) ) { $col['atts']['responsive_hide'] = $col_rhide; }
					// Floating-card cell → make the column the positioned ancestor for the absolute icon_box
					// (its `selector{position:absolute;top/left}` now resolves against this column = the image
					// area, not the page). Without this the badge lands at the page top-left over the logo.
					$col_decl = array();
					if ( $col_relative ) { $col_decl[] = 'position:relative'; }
					// Source cell max-width (e.g. a hero text column's `max-w-2xl`) → constrain the column's
					// content so its paragraph wraps like the source (a full-width 50% track wraps too few lines).
					if ( ! empty( $c['maxw'] ) && preg_match( '/^[0-9.]+(?:px|rem|em|%|ch|vw)$/', (string) $c['maxw'] ) ) {
						$col_decl[] = 'max-width:' . (string) $c['maxw'];
					}
					if ( $col_decl ) {
						$cur = isset( $col['atts']['custom_css'] ) ? (string) $col['atts']['custom_css'] : '';
						$col['atts']['custom_css'] = trim( $cur . ( '' !== $cur ? "\n" : '' ) . 'selector{' . implode( ';', $col_decl ) . ';}' );
					}
					// Box card WITH a button → the box styling is on the COLUMN's Inner Wrapper Class so it
					// wraps the icon_box AND the button (a simple box card put it on the icon_box instead).
					if ( $box_on_column !== '' ) { $col['atts']['inner_class'] = trim( $col['atts']['inner_class'] . ' ' . $box_on_column ); }
					// CTA button group → the column's Inner Wrapper Class is `.btn-row` (side-by-side).
					if ( $btn_row_on_column !== '' ) { // CTA button group → side-by-side via native content_direction (not a .btn-row wrapper), matching JS
						$col['atts']['content_direction'] = 'row';
						if ( empty( $col['atts']['content_gap']['base'] ) ) { $col['atts']['content_gap'] = array( 'base' => '3', 'md' => '', 'lg' => '' ); }
						$col['atts']['content_h'] = 'center';
					}
					if ( $row_valign !== '' && empty( $c['grid'] ) ) {
						// Source `items-center` / `.row.align-items-*` centres each COLUMN against its row siblings
						// on the cross axis — that is the native `align_self` option ("column vertical align vs row
						// siblings": start=Top, center=Middle, end=Bottom), NOT `content_v`. `content_v` aligns the
						// contents WITHIN the column and only shows once the column is stretched — which hits the
						// h-100 / flex-no-explicit-height trap and renders top. `align_self` maps 1:1, emits the
						// native `.align-self-*` class (align-self on the flex item), leaves the column content-
						// height and centred, and — the point — is a real option in the column's Layout tab, so the
						// user sees/edits it there instead of raw CSS in Advanced. (No custom_css, no content_v.)
						$col['atts']['align_self'] = array( 'base' => $row_valign, 'md' => '', 'lg' => '' );
					}
					// A grid CELL that centers/right-aligns its own text (source `text-center` / `text-right`
					// on the cell wrapper) → the column's native `text_align`, so the cell's mixed content
					// (heading + prose + buttons) inherits that alignment as one. '' (text-left / none) forces
					// nothing. PHP twin of the JS to-pages per-column text-align pass.
					$cell_ta = self::cls_text_align( (string) ( $c['cls'] ?? '' ) );
					if ( $cell_ta !== '' ) { $col['atts']['text_align'] = $cell_ta; }
					// Counter cells center their content via the column's own alignment (the source
					// `.counter-item text-center`), instead of carrying a text-center wrapper class.
					if ( ! empty( $c['counter'] ) ) { $col['atts']['content_h'] = 'center'; }
					// Replay the cell's OWN flex layout via the column's NATIVE options: a flex-ROW cell
					// lays its children side-by-side with the source gap (parity with the JS to-pages path).
					if ( ! empty( $c['flex']['dir'] ) && strpos( (string) $c['flex']['dir'], 'row' ) === 0 ) {
						$col['atts']['content_direction'] = 'row';
						$slug = self::gap_slug( isset( $c['flex']['gap'] ) ? $c['flex']['gap'] : '' );
						if ( $slug !== '' ) { $col['atts']['content_gap'] = array( 'base' => $slug, 'md' => '', 'lg' => '' ); }
						if ( strpos( (string) $c['flex']['dir'], 'row-reverse' ) === 0 ) { $col['atts']['content_order'] = 'reverse'; }
					}
					$items[] = $col;
				}
			} else {
				// Standalone block -> the block-builder REGISTRY (heading / text / button / code, plus any
				// shortcode added via register_builder). 'code' is the universal fallback for unmapped roles.
				$builders = self::builders();
				$bld      = isset( $builders[ $role ] ) ? $builders[ $role ] : $builders['code'];
				$item     = call_user_func( $bld['build'], $b );
				if ( $item !== null ) {
					if ( is_array( $item ) ) { self::apply_block_anim( $item, $b ); } // source reveal/scroll-animation intent → node Animations tab
					if ( ! empty( $bld['full_width'] ) ) { $flush_buf(); $items[] = self::n_column( '1_1', array( $item ) ); }
					else { $buf[] = $item; }
				}
			}
		}
		$flush_buf();

		if ( ! $items ) { return null; }
		// Mapped section: extracted content has NO source .container, so use the builder's
		// centered `.fw-container` (is_fullwidth = false) to match the source's `.container`.
		// NOT `sc-mirror` (whose reset would nuke the container back to full width). The section
		// element is still full-width, so a section background still spans edge to edge.
		$sec_node = self::n_section( $src_cls_str, $css_id, $css, $items, false );
		// A CENTERED source band (flex items-center / text-center, detected by Stitch::section_center
		// and carried here as $sec['align']) → the section's native `text_align='center'`. text-align
		// is INHERITED, so this centers the whole band's heading + paragraph + buttons together (the
		// per-heading special_heading `alignment` stays on Inherit and cascades from here). '' otherwise.
		if ( $center && isset( $sec_node['atts'] ) ) { $sec_node['atts']['text_align'] = 'center'; }
		if ( $bg_video !== null ) { self::apply_bg_video( $sec_node, $bg_video ); } // full-screen <video> → section background
		if ( $bg_image !== null ) { self::apply_bg_image( $sec_node, $bg_image ); } // hero full-bleed <img> → section background image + dark scrim
		// OVERLAY-HEADER OFFSET: a fixed/absolute transparent masthead overlays the hero, so the hero needs
		// top padding = the header height, or its heading renders UNDER the nav (the 'hero top spacing' bug).
		if ( ! empty( $sec['heroTopPad'] ) && (int) $sec['heroTopPad'] > 0 && isset( $sec_node['atts'] ) ) {
			$hpad = (int) $sec['heroTopPad'];
			$hcur = (string) ( $sec_node['atts']['custom_css'] ?? '' );
			// selector[class] (specificity 0,2,0) + !important — a full-bleed/verbatim hero carries both
			// `.section--bleed{padding-block:0}` (higher specificity) AND `.sc-mirror{padding-top:0 !important}`
			// (same specificity as a bare `selector`, wins on source order). The [class] attribute selector
			// raises specificity above `.sc-mirror` so the overlay-header offset actually renders.
			$sec_node['atts']['custom_css'] = trim( $hcur . " selector[class]{padding-top:{$hpad}px !important;}" );
		}
		// Container Width — the source's content-band cap (e.g. `container-narrow` = 64rem) → a shared named
		// Container Width preset (Components → Section Styles → Container Widths), so the whole site reuses it.
		if ( isset( $sec['sectionContainerW'] ) && is_array( $sec['sectionContainerW'] ) && isset( $sec_node['atts'] ) ) {
			$sec_node['atts']['container_width'] = $sec['sectionContainerW'];
		}
		// Reproduce the source section's FULL container styling — not just vertical rhythm. Its Tailwind
		// classes (`max-w-[..] mx-auto px-.. py-.. border-y border-<color> rounded-.. bg-..`) describe a
		// centered, bordered, padded BOX, which maps onto the builder's centered `.fw-container`; the
		// section's vertical MARGIN (the gap between sections) maps onto the section element. Without this
		// the decompose path dropped borders / max-width / horizontal padding (only py/mb survived) — e.g.
		// the Stitch "trusted by" strip lost its `border-y` + `max-w-[1280px]`.
		$raw_cls = (string) ( isset( $sec['sectionRawClass'] ) ? $sec['sectionRawClass'] : '' );
		// Pass #6 — carry a source band's responsive VISIBILITY (Tailwind `hidden md:*` / `md:hidden` …)
		// onto the section's native `responsive_hide` option (class-derived; empty for the common case →
		// byte-identical output for sections with no responsive toggle).
		if ( '' !== $raw_cls && isset( $sec_node['atts'] ) ) {
			$rhide = self::responsive_hide_from_classes( $raw_cls );
			if ( ! empty( $rhide ) ) { $sec_node['atts']['responsive_hide'] = $rhide; }
		}
		$base    = array();
		if ( '' !== $raw_cls && self::$style_on && class_exists( 'FW_Site_Converter_Tailwind' ) ) {
			$cm   = FW_Site_Converter_Tailwind::compile_class_set( $raw_cls, self::$style_cfg );
			$base = ( isset( $cm['base'] ) && is_array( $cm['base'] ) ) ? $cm['base'] : array();
		}
		// A border-WIDTH with no border-STYLE renders invisible (Tailwind's preflight is scoped to .sc-tw
		// and isn't carried into these global #id rules) — add solid so the border shows. But add it ONLY
		// for the sides that actually HAVE a width: a bare `border-style:solid` would make the unset sides
		// render at the CSS default `medium` width, so `border-y` (top+bottom only) would draw all four.
		if ( isset( $base['border-width'] ) ) {
			if ( ! isset( $base['border-style'] ) ) { $base['border-style'] = 'solid'; }
		} else {
			foreach ( array( 'top', 'right', 'bottom', 'left' ) as $side ) {
				if ( isset( $base[ 'border-' . $side . '-width' ] ) && ! isset( $base[ 'border-' . $side . '-style' ] ) ) {
					$base[ 'border-' . $side . '-style' ] = 'solid';
				}
			}
		}
		// Split: the BOX props (width / padding / border / bg / radius, + carried --tw-* vars) → the centered
		// `.fw-container`; vertical MARGIN → the section element. Layout utilities (flex/grid/gap/text-align…)
		// are intentionally NOT carried — the builder's row/column structure owns layout.
		$box_props = array(
			'max-width', 'margin-left', 'margin-right',
			'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
			'border-width', 'border-top-width', 'border-right-width', 'border-bottom-width', 'border-left-width',
			'border-style', 'border-top-style', 'border-right-style', 'border-bottom-style', 'border-left-style', 'border-color',
			'border-radius', 'border-top-left-radius', 'border-top-right-radius', 'border-bottom-left-radius', 'border-bottom-right-radius',
		);
		// A section FILL (bg colour / image from a `bg-*` class on the section wrapper) belongs on the
		// full-width section element — NOT the capped, centred `.fw-container`. Hoisting it onto the
		// container painted a white/tinted card over the band and hid the section's real background
		// (regression). Route it to $secd so it renders edge-to-edge like the source; the computed-bg
		// block below then defers to it (its `! isset( $secd[...] )` guards).
		$bg_props  = array( 'background', 'background-color', 'background-image' );
		$cont = array();
		$secd = array();
		foreach ( $base as $p => $v ) {
			if ( in_array( $p, $bg_props, true ) )                                       { $secd[ $p ] = $v; }
			elseif ( in_array( $p, $box_props, true ) || 0 === strpos( (string) $p, '--' ) ) { $cont[ $p ] = $v; }
			elseif ( 'margin-top' === $p || 'margin-bottom' === $p )                      { $secd[ $p ] = $v; }
		}
		if ( $cont ) { $cont['box-sizing'] = 'border-box'; }

		// Reproduce the section's OWN rendered background (edge-to-edge, on the section element) from its
		// COMPUTED style. AI-builder bands often set their fill via computed CSS, not a `bg-*` class (e.g. a
		// white feature strip, a tinted band) — the class parse above misses those, so faithful spacing reads
		// as an empty void. We deliberately take ONLY a background that is on the section element itself: an
		// inner "layer" is ambiguous (decorative blur blobs, gradients, overlays) and hoisting it wholesale
		// paints the wrong colour, so that case is left to a future, discrimination-aware pass.
		if ( self::$style_on ) {
			$cont_has_bg = isset( $cont['background'] ) || isset( $cont['background-color'] ) || isset( $cont['background-image'] );
			$sec_cs = (string) ( $sec['sectionCs'] ?? '' );
			if ( $sec_cs !== '' ) {
				$bgd = self::cs_decls( $sec_cs, array( 'background-color', 'background-image' ) );
				$bc  = isset( $bgd['background-color'] ) ? trim( $bgd['background-color'] ) : '';
				if ( $bc !== '' && $bc !== 'transparent' && ! preg_match( '/,\s*0\s*\)\s*$/', $bc ) && ! isset( $secd['background-color'] ) && ! $cont_has_bg ) {
					$secd['background-color'] = $bc;
				}
				if ( isset( $bgd['background-image'] ) && 'none' !== trim( $bgd['background-image'] ) && ! isset( $secd['background-image'] ) && ! $cont_has_bg ) {
					$secd['background-image'] = trim( $bgd['background-image'] );
				}
			}
			// Discrimination-aware inner-layer pass: a section whose SOLID band colour sits on an inner
			// full-bleed LAYER (e.g. `<div class="absolute inset-0 bg-primary">`) instead of the section
			// root reads as transparent above. Hoist the dominant opaque layer fill to the section element,
			// skipping decorative overlays (dot patterns, low-opacity gradient washes). Never overrides a
			// fill already resolved onto $secd, and never touches the `.fw-container` (kept bg-free by design).
			if ( ! isset( $secd['background-color'] ) && ! $cont_has_bg && ! empty( $sec['sectionLayers'] ) && is_array( $sec['sectionLayers'] ) ) {
				foreach ( $sec['sectionLayers'] as $layer ) {
					$band = self::layer_band_bg( is_array( $layer ) ? $layer : array() );
					if ( '' !== $band ) { $secd['background-color'] = $band; break; }
				}
			}
		}

		// P0 — SECTION BAND FILL onto the section's NATIVE background field. Everything above resolved a
		// detected full-bleed band fill into $secd (the section's own bg-*, its computed background-color,
		// OR a hoisted `absolute inset-0 bg-*` layer). That was only ever written to style.css, so the band
		// never appeared as a section OPTION (variant/Background) — the audit's "band fills not applied".
		// Emit it here: LINK an existing Section Style preset when the colour matches one within tolerance
		// (set `variant` = its slug — the CTA green → the built "Alt" preset), else set the native
		// background.color.custom directly. A GRADIENT background-image → the gradient stops. In every case
		// the value is REMOVED from $secd so style.css doesn't double-paint the same fill.
		if ( self::$style_on ) {
			// Flat colour band → preset link or native custom colour.
			if ( isset( $secd['background-color'] ) ) {
				$rgb = self::rgb_triplet( $secd['background-color'] );
				if ( $rgb !== null ) {
					$slug = self::match_section_preset( $rgb );
					if ( $slug !== '' ) {
						$sec_node['atts']['variant'] = $slug;                          // reuse the built Section Style
					} else {
						$sec_node['atts']['background']['color']['value']['custom'] = self::norm_bg_color( $secd['background-color'] );
					}
					unset( $secd['background-color'] );                                // now on the section option — not style.css
				}
			}
			// Gradient band → the section's native background.gradient stops (flat colour is the priority above).
			if ( isset( $secd['background-image'] ) && stripos( (string) $secd['background-image'], 'linear-gradient' ) !== false ) {
				$grad = self::parse_linear_gradient( $secd['background-image'] );
				if ( $grad !== null ) {
					$sec_node['atts']['background']['gradient']['data'] = $grad;
					unset( $secd['background-image'] );
				}
			}
		}

		// -------------------------------------------------------------------------------------------------
		// PASS #5 - SPACING-SCALE PRESET DISTILLATION. Distill the section's MEASURED vertical rhythm
		// (top/bottom padding + the section's own top/bottom margin, folded in - the Section shortcode has
		// no margin lever) into the NATIVE, editable Top/Bottom Spacing options, snapped onto the shared
		// spacing scale. Before this the rhythm was frozen into `.fw-container` CSS (or lost entirely on
		// non-Tailwind AI-builder sections whose padding lives only in computed style), so section rhythm
		// fell back to theme defaults. Now it lands as a durable, user-tunable option. Byte-for-byte mirror
		// of the JS to-pages sectionLayout() (same BASE_CAP clamp + spacingToken).
		$vspace_native = false;
		if ( self::$style_on ) {
			$pt = $pb = $mt = $mb = 0.0;
			// Priority: the section's OWN computed padding/margin (data-sc-cs) - works for ANY site,
			// including non-Tailwind builders whose spacing never appears as a utility class.
			$scs = (string) ( $sec['sectionCs'] ?? '' );
			if ( '' !== $scs ) {
				$pm = self::cs_decls( $scs, array( 'padding-top', 'padding-bottom', 'margin-top', 'margin-bottom' ) );
				if ( isset( $pm['padding-top'] ) )    { $pt = self::px_of( $pm['padding-top'] ); }
				if ( isset( $pm['padding-bottom'] ) ) { $pb = self::px_of( $pm['padding-bottom'] ); }
				if ( isset( $pm['margin-top'] ) )     { $mt = self::px_of( $pm['margin-top'] ); }
				if ( isset( $pm['margin-bottom'] ) )  { $mb = self::px_of( $pm['margin-bottom'] ); }
			}
			// Fallback: the Tailwind-derived container vertical padding + the section's vertical-margin
			// utilities (py-*/pt-*/pb-*, mt-*/mb-*) - only for the sides the computed pass left at zero.
			if ( $pt <= 0 && isset( $cont['padding-top'] ) )    { $pt = self::px_of( $cont['padding-top'] ); }
			if ( $pb <= 0 && isset( $cont['padding-bottom'] ) ) { $pb = self::px_of( $cont['padding-bottom'] ); }
			if ( $mt <= 0 && $mb <= 0 ) {
				$vs = self::section_vspace( $raw_cls );
				$mt = $vs['mt']; $mb = $vs['mb'];
				if ( $pt <= 0 && $pb <= 0 ) { $pt = $vs['pt']; $pb = $vs['pb']; }
			}
			$top = $pt + $mt; $bottom = $pb + $mb;
			// The capture samples ONE (desktop) viewport, so a source `lg:pt-48` (192px) would otherwise
			// over-space phones. Keep the exact value on `lg` and CLAMP the base (mobile) layer.
			$cap     = 112.0;
			$mklayer = function ( $prefix, $v ) use ( $cap ) {
				$b = min( $v, $cap );
				return array( 'base' => self::spacing_token( $prefix, $b ), 'md' => '', 'lg' => ( $b < $v ? self::spacing_token( $prefix, $v ) : '' ) );
			};
			if ( $top > 0 )    { $sec_node['atts']['padding_top']    = $mklayer( 'pt', $top );    $vspace_native = true; }
			if ( $bottom > 0 ) { $sec_node['atts']['padding_bottom'] = $mklayer( 'pb', $bottom ); $vspace_native = true; }
			// The rhythm now lives on the NATIVE option - drop it from the container CSS so it is not
			// double-applied. Horizontal container padding stays (that is the structural gutter).
			if ( $vspace_native ) { unset( $cont['padding-top'], $cont['padding-bottom'] ); }
			// Inter-element (grid column) gap -> the section's NATIVE Gap option, snapped onto the Gap
			// Scale (Bootstrap $spacers). Empty Gap = inherit the Theme Settings Default Gap, so only set
			// it when the source actually declared a meaningful grid gap.
			if ( $grid_gap_px > 0 ) {
				$gslug = self::gap_slug( $grid_gap_px . 'px' );
				if ( '' !== $gslug ) { $sec_node['atts']['gap'] = array( 'base' => $gslug, 'md' => '', 'lg' => '' ); }
			}
		}

		if ( $cont || $secd ) {
			if ( $secd ) { self::register_section_rule( $css_id, '', $secd ); }
			if ( $cont ) { self::register_section_rule( $css_id, '.fw-container', $cont ); }
		} else {
			// Fallback (non-Tailwind source / mapper off): vertical-rhythm only, as before - but only when
			// Pass #5 did NOT already fold the rhythm into the section's native Top/Bottom Spacing (else the
			// same padding would be applied twice: once natively, once as raw section CSS).
			$vs = self::section_vspace( $raw_cls );
			$sv = array();
			if ( ! $vspace_native ) {
				$sv['padding-top']    = round( $vs['pt'] ) . 'px';
				$sv['padding-bottom'] = round( $vs['pb'] ) . 'px';
				if ( $vs['mt'] > 0 ) { $sv['margin-top']    = round( $vs['mt'] ) . 'px'; }
				if ( $vs['mb'] > 0 ) { $sv['margin-bottom'] = round( $vs['mb'] ) . 'px'; }
			}
			if ( $sv ) { self::register_section_rule( $css_id, '', $sv ); }
		}
		return $sec_node;
	}

	/* ---------------------------------------------------------------------- *
	 * Learning (save corrections → rules)
	 * ---------------------------------------------------------------------- */

	public static function get_rules() {
		// ONE canonical store, shared with the converter's decompose + the AI distillation in
		// FW_Site_Converter_Stitch (previously these corrections went to a separate option the converter
		// never read, so they were silently ignored). Delegating keeps a single source of truth.
		return FW_Site_Converter_Stitch::rules_get();
	}

	/**
	 * Merge user-confirmed roles into the rules map (signature => role). Generic signatures
	 * ('row', 'html', 'el|') aren't learned — too ambiguous to generalize.
	 *
	 * @param array $mapping role-annotated mapping the user confirmed
	 * @return int rules added/updated
	 */
	public static function learn( array $mapping ) {
		$rules = self::get_rules();
		$valid = self::roles();
		$n = 0;
		foreach ( ( $mapping['pages'] ?? array() ) as $page ) {
			foreach ( ( $page['sections'] ?? array() ) as $sec ) {
				foreach ( ( $sec['blocks'] ?? array() ) as $b ) {
					if ( ! isset( $b['role'], $valid[ $b['role'] ] ) ) { continue; }
					$sig = self::signature( $b );
					if ( $sig === '' || $sig === 'row' || $sig === 'html' || substr( $sig, -1 ) === '|' ) { continue; }
					if ( ! isset( $rules[ $sig ] ) || $rules[ $sig ] !== $b['role'] ) {
						$rules[ $sig ] = $b['role'];
						$n++;
					}
				}
			}
		}
		if ( $n ) { FW_Site_Converter_Stitch::rules_put( $rules ); }
		return $n;
	}
}
