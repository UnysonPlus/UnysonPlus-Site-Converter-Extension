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
		$angle = 90;
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
		return $out;
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
				$gap = ( $grp && preg_match( '/gap:\s*([0-9.]+)px/', (string) ( $grp['cs'] ?? '' ), $gm ) ) ? self::gap_slug( $gm[1] ) : '';
				$col['atts']['content_gap'] = array( 'base' => ( $gap !== '' ? $gap : '3' ), 'md' => '', 'lg' => '' );
				$col['atts']['content_h'] = 'center';
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
					if ( $role === 'text' && $after_title && ! $used_subtitle ) {
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
		);
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
				'content_v' => 'default', 'content_h' => 'default', 'position' => '', 'z_index' => 0,
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
	private static function n_text( $html, $max_width = '', $align = '' ) {
		$html      = (string) $html;
		$centered  = in_array( $align, array( 'center', 'right' ), true );
		$mw        = self::max_width_att( (string) $max_width );
		$use_att   = ( $mw !== null );
		// A centered band (source `text-center`) carries onto its sub-text paragraph. Wrap the content so the
		// text lines centre too (the column's flex-centering only centres the block, not its inline text). When
		// the paragraph ALSO has a source max-width (`max-w-2xl mx-auto`), bake that width + `margin:auto` into
		// the SAME wrapper so the constrained box is CENTERED (mx-auto), not pinned to the column's left edge —
		// then drop the max_width att (it would left-pin the box). Otherwise keep the native att.
		if ( $centered ) {
			$style = 'text-align:' . $align . ';';
			if ( $mw !== null ) {
				$m = $mw['custom']['custom_width'] ?? array();
				if ( ! empty( $m['value'] ) ) { $style .= 'max-width:' . $m['value'] . ( $m['unit'] ?? 'px' ) . ';margin-left:auto;margin-right:auto;'; $use_att = false; }
			}
			$html = '<div style="' . $style . '">' . $html . '</div>';
		}
		$atts = array(
			'text' => self::map_accent_classes( $html ), 'animation' => self::def_animation(),
			'unique_id' => self::uid(), 'css_id' => '', 'css_class' => '', 'custom_css' => '', 'responsive_hide' => array(), 'custom_attrs' => array(),
		);
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

	/** Build the badge shortcode node (recognizer/role token stays announcement_pill) from a recognized hero pill block (sub-tag + message +
	 *  optional trailing icon + optional link). Full att shape so the editor opens it cleanly; colours stay
	 *  neutral (user themes them). The trailing icon reuses icon_value() like buttons. */
	private static function n_announcement_pill( array $b ) {
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
	private static function n_testimonials( array $rows ) {
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
		return array( 'type' => 'simple', 'shortcode' => 'testimonials', '_items' => array(), 'atts' => array(
			'title'           => '',
			'testimonials'    => $items,
			'design_settings' => self::testimonials_design_default(),
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
			'unique_id' => self::uid(), 'css_id' => '', 'css_class' => '', 'custom_css' => '', 'responsive_hide' => array(), 'custom_attrs' => array(),
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
		return self::finalize_widget( 'tabs', array( 'tabs' => $tabs ) );
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
		return self::finalize_widget( 'steps', array( 'steps' => $steps ) );
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
		return self::finalize_widget( 'timeline', array( 'items' => $items ) );
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
		return self::finalize_widget( 'progress', array( 'layout' => array( 'type' => 'bar' ), 'bars' => $bars ) );
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
		$cols = (string) max( 2, min( 5, count( $plans ) ) );
		return self::finalize_widget( 'pricing_table', array( 'plans' => $plans, 'columns' => $cols ) );
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

	private static function n_button( $label, $link, $cls = '', $icon = '', $icon_pos = 'after', $cs = '', $group_cls = '', $group_cs = '' ) {
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
		$preset = self::button_preset_for( (string) $cls, (string) $cs );
		if ( '' !== $preset['style'] ) { $atts['style'] = $preset['style']; }
		if ( '' !== $preset['size'] )  { $atts['size']  = $preset['size']; }
		$icon = trim( (string) $icon );
		if ( $icon !== '' ) {
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
		if ( ! empty( $card['lucide'] ) && is_array( $atts['icon'] ) ) {
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
		if ( $ic !== '' && preg_match( '/^#[0-9a-f]{3,8}$/i', $ic ) ) {
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

		// css_class = source ALIGNMENT (sc-ib-left unless the card is centered) + the gray icon "image" box
		// (if the source had one). The cell's own box classes are NOT carried here — box styling lives on the
		// column (with-button card) or is added separately (simple card), so this stays clean (no double border).
		$ibcls = empty( $card['center'] ) ? self::ib_left_class() : '';
		if ( ! empty( $card['iconBoxCls'] ) ) {
			$ibx = self::ib_iconbox_class( (string) $card['iconBoxCls'], (string) ( $card['iconBoxCs'] ?? '' ) );
			if ( '' !== $ibx ) { $ibcls = trim( $ibcls . ' ' . $ibx ); }
		}
		$atts['css_class'] = $ibcls;
		$atts['unique_id'] = self::uid();

		return array( 'type' => 'simple', 'shortcode' => 'icon_box', 'atts' => $atts, '_items' => array() );
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
		if ( $html === '' || stripos( $html, 'class' ) === false ) { return $html; }
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
		return $html;
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
	private static function build_cell_items( array $blocks ) {
		$items = array();
		$head  = null;
		$flush_head = function () use ( &$head, &$items ) {
			if ( $head !== null ) { $items[] = self::n_heading( $head ); $head = null; }
		};
		foreach ( $blocks as $b ) {
			if ( ! is_array( $b ) ) { continue; }
			if ( isset( $b['include'] ) && ! $b['include'] ) { continue; }
			$role = isset( $b['role'] ) ? (string) $b['role'] : 'code';
			if ( $role === 'skip' ) { continue; }

			if ( in_array( $role, array( 'overline', 'title', 'subtitle' ), true ) ) {
				$val   = $role === 'title' ? (string) ( $b['html'] ?? $b['text'] ?? '' ) : trim( (string) ( $b['text'] ?? '' ) );
				$fresh = array( 'overline' => '', 'title' => '', 'subtitle' => '', 'overline_class' => '', 'title_class' => '', 'subtitle_class' => '', 'css_class' => '', 'level' => (int) ( $b['level'] ?? 2 ), 'align' => $b['align'] ?? '' );
				if ( $head === null ) { $head = $fresh; }
				if ( $head[ $role ] !== '' ) { $flush_head(); $head = $fresh; }
				$head[ $role ]            = $val;
				$head[ $role . '_class' ] = (string) ( $b['cls'] ?? '' );
				if ( isset( $b['cs'] ) && '' !== (string) $b['cs'] ) {
					$cw = self::cs_decls( (string) $b['cs'], array( 'font-weight' ) );
					if ( isset( $cw['font-weight'] ) ) { $head[ $role . '_weight' ] = $cw['font-weight']; }
				}
				if ( $role === 'title' ) {
					$head['level'] = (int) ( $b['level'] ?? 2 );
					$head['align'] = $b['align'] ?? $head['align'];
					if ( ! empty( $b['wrapCls'] ) ) { $head['css_class'] = (string) $b['wrapCls']; }
				}
				continue;
			}
			$flush_head();

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
	 * Translate a heading-group wrapper's Tailwind LAYOUT/SPACING classes into NATIVE special_heading
	 * options (parity with the JS to-pages headingNode) — otherwise they sit dead on css_class (no
	 * Tailwind runtime in the builder) and the heading renders with the wrong spacing. Returns the
	 * native atts + the leftover (unmapped) class string.
	 */
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

	private static function n_heading( $h ) {
		$lvl = isset( $h['level'] ) && $h['level'] >= 1 && $h['level'] <= 6 ? (int) $h['level'] : 2;
		// Translate the wrapper's Tailwind layout/spacing classes into native options (parity w/ JS).
		$layout = self::heading_layout( (string) ( $h['css_class'] ?? '' ) );
		// Default to inherit ('') — `left`/`start` is the computed default for almost all content, so
		// treat it as inherit (no `text-start`) and only force a class for an explicit center/right.
		$align = $layout['alignment'] !== '' ? $layout['alignment']
			: ( isset( $h['align'] ) && in_array( $h['align'], array( 'center', 'right' ), true ) ? $h['align'] : '' );
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
		return array(
			'type' => 'simple', 'shortcode' => 'special_heading', '_items' => array(),
			'atts' => array(
				'unique_id' => self::uid(), 'css_id' => '',
				// Only UNMAPPED wrapper classes remain on css_class; keep_classes drops animation noise.
				'css_class' => self::keep_classes( $layout['css_class'] ),
				// Re-assert each part's SOURCE font-weight on its own element (wins over the theme's
				// hN.heading-title tag rule when no heading-weight token is set) — parity with JS to-pages.
				'custom_css' => self::heading_weight_css( $h ),
				'overline' => self::map_accent_classes( $ov_raw ),
				'overline_uppercase' => $ol_upper ? 'yes' : 'no',
				'overline_icon' => $ov_icon,
				'overline_icon_position' => $ov_icon_pos,
				'title'    => self::map_accent_classes( (string) ( $h['title'] ?? '' ) ),
				'subtitle' => self::map_accent_classes( (string) ( $h['subtitle'] ?? '' ) ),
				'heading'  => 'h' . $lvl,
				'alignment' => $align,
				'element_spacing' => $layout['element_spacing'],
				'block_max_width' => $layout['block_max_width'],
				'spacing' => $layout['spacing'] !== null ? $layout['spacing'] : self::empty_spacing(),
				// Per-part source classes mapped onto the special heading's class inputs (the
				// source's overline/title/subtitle carried their own utility classes).
				'overline_class' => self::keep_classes( $h['overline_class'] ?? '' ),
				'title_class'    => self::keep_classes( $h['title_class'] ?? '' ),
				'subtitle_class' => self::keep_classes( $h['subtitle_class'] ?? '' ),
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
			return self::n_heading( array( 'title' => (string) ( $b['html'] ?? $b['text'] ?? '' ), 'level' => (int) ( $b['level'] ?? 3 ), 'align' => $b['align'] ?? '', 'title_class' => (string) ( $b['cls'] ?? '' ), 'title_weight' => isset( $cw['font-weight'] ) ? $cw['font-weight'] : '', 'css_class' => (string) ( $b['wrapCls'] ?? '' ) ) );
		} );
		self::register_builder( 'text', function ( $b ) {
			return self::n_text( (string) ( $b['html'] ?? $b['text'] ?? '' ), (string) ( $b['maxWidth'] ?? '' ), (string) ( $b['align'] ?? '' ) );
		} );
		self::register_builder( 'button', function ( $b ) {
			return self::n_button( (string) ( $b['label'] ?? $b['text'] ?? 'Button' ), (string) ( $b['href'] ?? '#' ), (string) ( $b['srcCls'] ?? $b['cls'] ?? '' ), (string) ( $b['icon'] ?? '' ), (string) ( $b['iconPos'] ?? 'after' ), (string) ( $b['srcCs'] ?? $b['cs'] ?? '' ), (string) ( $b['groupCls'] ?? '' ), (string) ( $b['groupCs'] ?? '' ) );
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
		self::register_builder( 'announcement_pill', function ( $b ) {
			return self::n_announcement_pill( $b );
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
			'text'     => array( 'sel' => '.text-block',                'props' => array( 'font-family', 'font-size', 'line-height', 'color', 'max-width', 'margin-top', 'margin-bottom' ) ),
			'announcement_pill' => array( 'sel' => '.fw-announce',       'props' => array( 'margin-top', 'margin-bottom' ) ),
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

		// A full-screen background <video> (extractor-flagged `bg` = absolute + object-cover behind the
		// content) becomes the SECTION's background video — not a content media_video block. Pull it out
		// here; it's wired onto the section node after the node is built. The actual file comes from the
		// captured <source> URL, or the matching "Attach media" upload (upload_val handles the swap).
		$bg_video = null;
		foreach ( $blocks as $bi => $bb ) {
			$bt = isset( $bb['t'] ) ? $bb['t'] : ( isset( $bb['role'] ) ? $bb['role'] : '' );
			if ( $bt === 'video' && ! empty( $bb['bg'] ) ) { $bg_video = $bb; unset( $blocks[ $bi ] ); break; }
		}
		$blocks = array_values( $blocks );

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
			if ( $head !== null ) { $buf[] = self::n_heading( $head ); $head = null; }
		};
		$flush_buf = function () use ( &$buf, &$items, &$flush_head, $col_lay, $inner_wrap, $center ) {
			$flush_head();
			if ( $buf ) {
				$buf = self::group_buttons( $buf ); // consecutive buttons → one side-by-side flex-row group
				$w = ( $col_lay !== null ) ? $col_lay['width'] : '1_1';
				$col = self::n_column( $w, $buf, '', $col_lay !== null ? $col_lay : array() );
				if ( $inner_wrap !== '' ) { $col['atts']['inner_class'] = $inner_wrap; }
				if ( $center ) { $col['atts']['content_h'] = 'center'; }
				$items[] = $col;
				$buf = array();
			}
		};

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
				$node = self::n_testimonials( $b['items'] );
				self::apply_block_anim( $node, $b );
				$items[] = self::n_column( '1_1', array( $node ) );
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
				'tabs'     => 'n_tabs',
				'steps'    => 'n_steps',
				'timeline' => 'n_timeline',
				'progress' => 'n_progress',
				'pricing'  => 'n_pricing',
				'lottie'   => 'n_lottie',
				'svg_draw' => 'n_svg_draw',
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

			if ( in_array( $role, array( 'overline', 'title', 'subtitle' ), true ) ) {
				$val = $role === 'title' ? (string) ( $b['html'] ?? $b['text'] ?? '' ) : trim( (string) ( $b['text'] ?? '' ) );
				$fresh = array( 'overline' => '', 'title' => '', 'subtitle' => '', 'overline_class' => '', 'title_class' => '', 'subtitle_class' => '', 'css_class' => '', 'level' => (int) ( $b['level'] ?? 2 ), 'align' => $b['align'] ?? '' );
				if ( $head === null ) { $head = $fresh; }
				// A second value for an already-filled slot starts a fresh heading.
				if ( $head[ $role ] !== '' ) { $flush_head(); $head = $fresh; }
				$head[ $role ] = $val;
				// Carry the source part's own classes onto the matching class input.
				$head[ $role . '_class' ] = (string) ( $b['cls'] ?? '' );
				// Carry the part's RESOLVED computed font-weight (data-sc-cs) so the heading re-asserts its
				// real weight even on a NON-Tailwind source (where the class carries no font-* utility).
				if ( isset( $b['cs'] ) && '' !== (string) $b['cs'] ) {
					$cw = self::cs_decls( (string) $b['cs'], array( 'font-weight' ) );
					if ( isset( $cw['font-weight'] ) ) { $head[ $role . '_weight' ] = $cw['font-weight']; }
				}
				// The heading-group wrapper class (source `<div class="heading">`) → the special
				// heading's own wrapper (css_class). Carried from the title block (the wrapper holds
				// the whole group). keep_classes runs in n_heading.
				if ( $role === 'title' ) {
					$head['level'] = (int) ( $b['level'] ?? 2 );
					$head['align'] = $b['align'] ?? $head['align'];
					if ( ! empty( $b['wrapCls'] ) ) { $head['css_class'] = (string) $b['wrapCls']; }
				}
				continue;
			}
			$flush_head();

			if ( $role === 'columns' && isset( $b['cols'] ) && is_array( $b['cols'] ) ) {
				$flush_buf();
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
				foreach ( $b['cols'] as $c ) {
					$box_on_column = ''; // box class for this column's Inner Wrapper Class (box card WITH a button)
						$btn_row_on_column = ''; // .btn-row class for a CTA button-group cell (side-by-side buttons)
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
						$lay = self::col_layout( (string) ( $c['cls'] ?? '' ) );
						if ( $lay === null ) { $lay = self::geom_layout( isset( $c['wResp'] ) ? $c['wResp'] : null ); }
						$width = ( $lay !== null ) ? $lay['width'] : self::cell_width( $c['width'] ?? '1_3' );
					}
					$col   = self::n_column( $width, $inner_items );
					// Box card WITH a button → the box styling is on the COLUMN's Inner Wrapper Class so it
					// wraps the icon_box AND the button (a simple box card put it on the icon_box instead).
					if ( $box_on_column !== '' ) { $col['atts']['inner_class'] = trim( $col['atts']['inner_class'] . ' ' . $box_on_column ); }
					// CTA button group → the column's Inner Wrapper Class is `.btn-row` (side-by-side).
					if ( $btn_row_on_column !== '' ) { // CTA button group → side-by-side via native content_direction (not a .btn-row wrapper), matching JS
						$col['atts']['content_direction'] = 'row';
						if ( empty( $col['atts']['content_gap']['base'] ) ) { $col['atts']['content_gap'] = array( 'base' => '3', 'md' => '', 'lg' => '' ); }
						$col['atts']['content_h'] = 'center';
					}
					if ( $row_valign !== '' && empty( $c['grid'] ) ) { $col['atts']['content_v'] = $row_valign; }
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
		if ( $bg_video !== null ) { self::apply_bg_video( $sec_node, $bg_video ); } // full-screen <video> → section background
		// Reproduce the source section's FULL container styling — not just vertical rhythm. Its Tailwind
		// classes (`max-w-[..] mx-auto px-.. py-.. border-y border-<color> rounded-.. bg-..`) describe a
		// centered, bordered, padded BOX, which maps onto the builder's centered `.fw-container`; the
		// section's vertical MARGIN (the gap between sections) maps onto the section element. Without this
		// the decompose path dropped borders / max-width / horizontal padding (only py/mb survived) — e.g.
		// the Stitch "trusted by" strip lost its `border-y` + `max-w-[1280px]`.
		$raw_cls = (string) ( isset( $sec['sectionRawClass'] ) ? $sec['sectionRawClass'] : '' );
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

		if ( $cont || $secd ) {
			if ( $secd ) { self::register_section_rule( $css_id, '', $secd ); }
			if ( $cont ) { self::register_section_rule( $css_id, '.fw-container', $cont ); }
		} else {
			// Fallback (non-Tailwind source / mapper off): vertical-rhythm only, as before.
			$vs = self::section_vspace( $raw_cls );
			$sv = array( 'padding-top' => round( $vs['pt'] ) . 'px', 'padding-bottom' => round( $vs['pb'] ) . 'px' );
			if ( $vs['mt'] > 0 ) { $sv['margin-top']    = round( $vs['mt'] ) . 'px'; }
			if ( $vs['mb'] > 0 ) { $sv['margin-bottom'] = round( $vs['mb'] ) . 'px'; }
			self::register_section_rule( $css_id, '', $sv );
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
