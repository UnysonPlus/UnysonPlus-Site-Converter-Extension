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

	/** A stable, unique CSS ID for a section (from its first meaningful source class, else N). */
	private static function auto_id( array $sec, $idx, array $used ) {
		$base = '';
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
	/** Default spacing att (margin/padding + responsive), shared by columns / buttons. */
	private static function def_spacing() {
		$box = array( 'all' => '', 'top' => '', 'right' => '', 'bottom' => '', 'left' => '' );
		return array( 'margin' => $box, 'padding' => $box, 'advanced' => array( 'md' => array( 'margin' => $box, 'padding' => $box ), 'lg' => array( 'margin' => $box, 'padding' => $box ) ) );
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
	private static function n_text( $html ) {
		return array( 'type' => 'simple', 'shortcode' => 'text_block', '_items' => array(), 'atts' => array(
			'text' => self::map_accent_classes( (string) $html ), 'animation' => self::def_animation(),
			'unique_id' => self::uid(), 'css_id' => '', 'css_class' => '', 'custom_css' => '', 'responsive_hide' => array(), 'custom_attrs' => array(),
		) );
	}
	private static function n_code( $html ) {
		return array( 'type' => 'simple', 'shortcode' => 'code_block', '_items' => array(), 'atts' => array(
			'code' => (string) $html, 'animation' => self::def_animation(),
			'unique_id' => self::uid(), 'css_id' => '', 'css_class' => '', 'custom_css' => '', 'responsive_hide' => array(), 'custom_attrs' => array(),
		) );
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
	private static function n_button( $label, $link, $cls = '', $icon = '', $icon_pos = 'after' ) {
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
			'style'           => '',
			'size'            => '',
			'width'           => array( 'mode' => '', 'custom' => array( 'custom_width' => array( 'value' => '', 'unit' => 'px' ) ) ),
			'alignment'       => '',
			'state'           => '',
			'hover_animation' => '',
			'spacing'         => self::def_spacing(),
			'animation'       => self::def_animation(),
			'unique_id'       => self::uid(),
			'css_id'          => '', 'css_class' => '', 'custom_css' => '', 'responsive_hide' => array(), 'custom_attrs' => array(),
		);
		$icon = trim( (string) $icon );
		if ( $icon !== '' ) {
			$atts['icon']          = self::icon_value( $icon );
			$atts['icon_position'] = in_array( $icon_pos, array( 'before', 'after' ), true ) ? $icon_pos : 'after';
		}
		return array( 'type' => 'simple', 'shortcode' => 'button', 'atts' => $atts, '_items' => array() );
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
			$ot = fw()->backend->option_type( 'icon-v2' );
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
		if ( ! empty( $card['customIcon'] ) ) {
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
		}

		$cls = self::keep_classes( (string) ( $card['cls'] ?? '' ) );
		if ( $cls !== '' ) { $atts['css_class'] = $cls; }
		$atts['unique_id'] = self::uid();

		return array( 'type' => 'simple', 'shortcode' => 'icon_box', 'atts' => $atts, '_items' => array() );
	}
	/** Keep meaningful source classes (utilities like text-uppercase/mb-3), drop animation noise. */
	private static function keep_classes( $cls ) {
		$out = array();
		foreach ( preg_split( '/\s+/', (string) $cls ) as $c ) {
			if ( $c === '' ) { continue; }
			if ( preg_match( '/^(wow|aos|animate|animated|js-|init|fade|slide|zoom)/i', $c ) ) { continue; }
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
		return preg_replace( '/\b(?:text-color-primary|color-primary)\b/', 'text-primary', $html );
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

	private static function n_heading( $h ) {
		$lvl = isset( $h['level'] ) && $h['level'] >= 1 && $h['level'] <= 6 ? (int) $h['level'] : 2;
		// Default to inherit ('') — `left`/`start` is the computed default for almost all content, so
		// treat it as inherit (no `text-start`) and only force a class for an explicit center/right.
		$align = isset( $h['align'] ) && in_array( $h['align'], array( 'center', 'right' ), true ) ? $h['align'] : '';
		return array(
			'type' => 'simple', 'shortcode' => 'special_heading', '_items' => array(),
			'atts' => array(
				'unique_id' => self::uid(), 'css_id' => '',
				// A captured heading-group wrapper class (e.g. "heading") lands on the wrapper;
				// keep_classes drops animation noise. The view renders the wrapper when set.
				'css_class' => self::keep_classes( $h['css_class'] ?? '' ),
				'overline' => self::map_accent_classes( (string) ( $h['overline'] ?? '' ) ),
				'title'    => self::map_accent_classes( (string) ( $h['title'] ?? '' ) ),
				'subtitle' => self::map_accent_classes( (string) ( $h['subtitle'] ?? '' ) ),
				'heading'  => 'h' . $lvl,
				'alignment' => $align,
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
				// Rewrite the source button brand class (.btn-main) → .btn so the source's button
				// rules (background, color AND :hover) apply to the rebuilt buttons (Style: Default).
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

	/** Rewrite each brand button class token (`.btn-main`) to `.btn` (whole-token only). */
	private static function rewrite_btn_classes( $css, array $brands ) {
		foreach ( $brands as $b ) {
			$css = preg_replace( '/\.' . preg_quote( $b, '/' ) . '(?![\w-])/', '.btn', $css );
		}
		return $css;
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

	/** Build one section node from its role-annotated blocks. */
	private static function build_section( array $sec ) {
		if ( ! empty( $sec['omit'] ) ) { return null; } // user dropped the whole section

		$src_cls = preg_split( '/\s+/', (string) ( $sec['sectionClass'] ?? '' ) );
		$src_cls = array_values( array_filter( $src_cls, function ( $c ) {
			return $c !== '' && ! preg_match( '/^(swiper|owl|slick|splide|carousel|aos|init|wow)/i', $c );
		} ) );
		$src_cls_str = implode( ' ', $src_cls );
		$css_id      = isset( $sec['css_id'] ) ? sanitize_html_class( (string) $sec['css_id'] ) : '';
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

		$flush_head = function () use ( &$head, &$buf ) {
			if ( $head !== null ) { $buf[] = self::n_heading( $head ); $head = null; }
		};
		$flush_buf = function () use ( &$buf, &$items, &$flush_head, $col_lay, $inner_wrap ) {
			$flush_head();
			if ( $buf ) {
				$w = ( $col_lay !== null ) ? $col_lay['width'] : '1_1';
				$col = self::n_column( $w, $buf, '', $col_lay !== null ? $col_lay : array() );
				if ( $inner_wrap !== '' ) { $col['atts']['inner_class'] = $inner_wrap; }
				$items[] = $col;
				$buf = array();
			}
		};

		foreach ( $blocks as $b ) {
			if ( isset( $b['include'] ) && ! $b['include'] ) { continue; } // unchecked → omit
			$role = isset( $b['role'] ) ? $b['role'] : 'code';
			if ( $role === 'skip' ) { continue; }

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
				$items[] = self::n_column( '1_1', array( self::n_testimonials( $b['items'] ) ) );
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
				// Row vertical alignment (source `.row.align-items-center` …) → each column's Content
				// Vertical Align. Skipped on the grid column (the height-definer where it's redundant).
				$row_valign = isset( $b['valign'] ) && in_array( $b['valign'], array( 'start', 'center', 'end' ), true ) ? $b['valign'] : '';
				foreach ( $b['cols'] as $c ) {
					// Cell content: a nested card-grid → a CSS-grid column of icon_boxes; a text cell
					// → special_heading (+ text); a single icon card → icon_box; else the verbatim
					// HTML as a code-block.
					if ( isset( $c['grid'] ) && is_array( $c['grid'] ) && ! empty( $c['grid']['cells'] ) ) {
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
						$inner_items = array( self::n_icon_box( $c['card'] ) );
					} else {
						$inner_items = array( self::n_code( (string) ( $c['html'] ?? '' ) ) );
					}
					// Column widths → the column's responsive width controls (outer grid, no nested div):
					// Bootstrap col-* first; else framework-agnostic measured widths (Tailwind/custom);
					// else even division across the row.
					$lay = self::col_layout( (string) ( $c['cls'] ?? '' ) );
					if ( $lay === null ) { $lay = self::geom_layout( isset( $c['wResp'] ) ? $c['wResp'] : null ); }
					$width = ( $lay !== null ) ? $lay['width'] : self::cell_width( $c['width'] ?? '1_3' );
					$col   = self::n_column( $width, $inner_items );
					if ( $row_valign !== '' && empty( $c['grid'] ) ) { $col['atts']['content_v'] = $row_valign; }
					// Counter cells center their content via the column's own alignment (the source
					// `.counter-item text-center`), instead of carrying a text-center wrapper class.
					if ( ! empty( $c['counter'] ) ) { $col['atts']['content_h'] = 'center'; }
					$items[] = $col;
				}
			} elseif ( $role === 'heading' ) {
				$buf[] = self::n_heading( array( 'title' => (string) ( $b['html'] ?? $b['text'] ?? '' ), 'level' => (int) ( $b['level'] ?? 3 ), 'align' => $b['align'] ?? '', 'title_class' => (string) ( $b['cls'] ?? '' ), 'css_class' => (string) ( $b['wrapCls'] ?? '' ) ) );
			} elseif ( $role === 'text' ) {
				$buf[] = self::n_text( (string) ( $b['html'] ?? $b['text'] ?? '' ) );
			} elseif ( $role === 'button' ) {
				$buf[] = self::n_button( (string) ( $b['label'] ?? $b['text'] ?? 'Button' ), (string) ( $b['href'] ?? '#' ), (string) ( $b['cls'] ?? '' ), (string) ( $b['icon'] ?? '' ), (string) ( $b['iconPos'] ?? 'after' ) );
			} else { // image | code | fallback
				$buf[] = self::n_code( (string) ( $b['html'] ?? '' ) );
			}
		}
		$flush_buf();

		if ( ! $items ) { return null; }
		// Mapped section: extracted content has NO source .container, so use the builder's
		// centered `.fw-container` (is_fullwidth = false) to match the source's `.container`.
		// NOT `sc-mirror` (whose reset would nuke the container back to full width). The section
		// element is still full-width, so a section background still spans edge to edge.
		return self::n_section( $src_cls_str, $css_id, $css, $items, false );
	}

	/* ---------------------------------------------------------------------- *
	 * Learning (save corrections → rules)
	 * ---------------------------------------------------------------------- */

	public static function get_rules() {
		$r = get_option( self::RULES_OPTION, array() );
		return is_array( $r ) ? $r : array();
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
		if ( $n ) { update_option( self::RULES_OPTION, $rules, false ); }
		return $n;
	}
}
