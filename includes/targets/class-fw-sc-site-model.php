<?php if ( ! defined( 'FW' ) ) { die( 'Forbidden' ); }

/**
 * THE SITE MODEL — the builder-neutral page description every non-native target reads.
 *
 * The analysis half (Stitch) produces a "mapping": pages → sections → typed blocks, each carrying what
 * the source page MEASURED (the computed-style strings the capture stamps) plus the recognised content.
 * That mapping grew up beside the Unyson+ output, so a few fields arrive in Unyson+ vocabulary — a
 * container-width preset slug, a `{predefined, custom}` colour, a `row-cols-3` class. This class is the
 * one place that vocabulary is translated back into plain facts (pixels, colours, counts), so a target
 * never needs to know how Unyson+ spells anything.
 *
 * Shape (version 1):
 *
 *   { schema: 'unysonplus/site-model', version: 1,
 *     pages: [ { title, slug, front_page, source_url,
 *                sections: [ { id, style{}, style_sm{}, inner_style{}, container_width (px, 0 = full),
 *                              full_bleed, min_height, valign, background: { image, overlay, video },
 *                              blocks: [ Block ] } ] } ] }
 *
 *   Block = { type, …type fields, style{} (parsed computed CSS), style_sm{}, margin: { top, bottom } }
 *
 * Block types: heading, text, button, image, row, stack, box, icon, badge, list, testimonials, accordion,
 * steps, logos, avatars, card, heading_link, decor, html, and `unknown` (the original block under `data`, so
 * nothing is lost when a type is not normalised yet).
 *
 * A row's columns[] = { span (1–12), span_resp, max_width, min_height, hidden{ desktop, tablet, mobile },
 * html, card, counter, box, blocks } — a column is either its blocks, one card, one counter (a stat cell),
 * or its own verbatim markup; `box` is the column's own skin (background, radius, padding, shadow).
 *
 * Pure: no WordPress writes, no globals. Same mapping in → same model out.
 */
final class FW_SC_Site_Model {

	const SCHEMA  = 'unysonplus/site-model';
	const VERSION = 1;

	/**
	 * @param array $mapping { pages: [ … ] } — the analysis output (Stitch::html_to_mapping, merged per page)
	 * @param array $palette optional slug => colour, to resolve colour-preset references left in the mapping
	 * @return array the Site Model
	 */
	public static function from_mapping( array $mapping, array $palette = array() ) {
		$pages = array();
		foreach ( (array) ( $mapping['pages'] ?? array() ) as $p ) {
			if ( ! is_array( $p ) ) { continue; }
			$sections = array();
			foreach ( (array) ( $p['sections'] ?? array() ) as $s ) {
				if ( ! is_array( $s ) || ! empty( $s['omit'] ) ) { continue; }
				$sections[] = self::section( $s, $palette );
			}
			$pages[] = array(
				'title'      => (string) ( $p['title'] ?? '' ),
				'slug'       => (string) ( $p['slug'] ?? '' ),
				'front_page' => ! empty( $p['front_page'] ),
				'source_url' => (string) ( $p['source_url'] ?? '' ),
				'main'       => self::main( (string) ( $p['mainCs'] ?? '' ) ),
				'sections'   => $sections,
			);
		}
		return array( 'schema' => self::SCHEMA, 'version' => self::VERSION, 'pages' => $pages );
	}

	/**
	 * The source <main>'s vertical padding — page padding, often the fixed header's clearance. Horizontal
	 * padding is left out on purpose: on the page root it would inset every section's background, while in the
	 * source that gutter sits inside full-bleed sections (the native output drops it for the same reason).
	 */
	private static function main( $cs ) {
		$st  = FW_SC_Style::parse( $cs );
		$box = FW_SC_Style::box( $st['padding'] ?? '' ) ?: array( 0, 0, 0, 0 );
		$top = isset( $st['padding-top'] ) ? (float) FW_SC_Style::px( $st['padding-top'] ) : (float) $box[0];
		$bot = isset( $st['padding-bottom'] ) ? (float) FW_SC_Style::px( $st['padding-bottom'] ) : (float) $box[2];
		return array( 'padding_top' => max( 0, $top ), 'padding_bottom' => max( 0, $bot ) );
	}

	/**
	 * The block's PHONE style. The capture runs at one viewport, so `csSm` is usually empty; the source's own
	 * responsive Tailwind classes still say how the text shrinks: an unprefixed `text-7xl` beside an `md:` / `lg:`
	 * size is the size below that breakpoint (72px), where the desktop 112px overflowed a phone.
	 */
	private static function phone_style( array $sm, $cls ) {
		if ( ! empty( $sm['font-size'] ) || '' === trim( $cls ) ) { return $sm; }
		$tokens = preg_split( '/\s+/', trim( $cls ) );
		$base = null; $responsive = false;
		foreach ( $tokens as $t ) {
			if ( preg_match( '/^(sm|md|lg|xl|2xl):text-(xs|sm|base|lg|[2-9]?xl|\[[\d.]+(?:px|rem)\])$/', $t ) ) { $responsive = true; continue; }
			if ( null === $base && preg_match( '/^text-(xs|sm|base|lg|[2-9]?xl|\[[\d.]+(?:px|rem)\])$/', $t, $m ) ) { $base = $m[1]; }
		}
		if ( ! $responsive || null === $base ) { return $sm; }
		$scale = array( 'xs' => 12, 'sm' => 14, 'base' => 16, 'lg' => 18, 'xl' => 20, '2xl' => 24, '3xl' => 30, '4xl' => 36, '5xl' => 48, '6xl' => 60, '7xl' => 72, '8xl' => 96, '9xl' => 128 );
		$px = isset( $scale[ $base ] ) ? $scale[ $base ] : 0;
		if ( ! $px && preg_match( '/^\[([\d.]+)(px|rem)\]$/', $base, $m ) ) { $px = 'rem' === $m[2] ? (float) $m[1] * 16 : (float) $m[1]; }
		if ( $px > 0 ) { $sm['font-size'] = round( $px, 2 ) . 'px'; }
		return $sm;
	}

	/* ------------------------------------------------------------------ *
	 * Sections
	 * ------------------------------------------------------------------ */

	private static function section( array $s, array $pal ) {
		$layers = isset( $s['sectionLayers'] ) && is_array( $s['sectionLayers'] ) ? $s['sectionLayers'] : array();
		$inner  = ( $layers && is_array( $layers[0] ) ) ? FW_SC_Style::parse( $layers[0]['cs'] ?? '' ) : array();

		$bg  = array( 'image' => '', 'overlay' => '', 'video' => '' );
		$img = $s['sectionBgImage'] ?? array();
		if ( is_array( $img ) && ! empty( $img['src'] ) ) {
			$bg['image']   = (string) $img['src'];
			$bg['overlay'] = (string) ( $img['overlay'] ?? '' );
		}
		$vid = $s['sectionBgVideo'] ?? array();
		if ( is_array( $vid ) && ! empty( $vid['src'] ) ) { $bg['video'] = (string) $vid['src']; }

		$id = (string) ( $s['css_id'] ?? ( $s['sectionId'] ?? '' ) );

		$blocks     = self::blocks( (array) ( $s['blocks'] ?? array() ), $pal );
		$min_height = (string) ( $s['sectionHeroHeight'] ?? '' );
		$valign     = (string) ( $s['sectionValign'] ?? '' );
		// A background video recorded on the section brings its hero height, centring and overlay with it.
		if ( is_array( $vid ) && ! empty( $vid['src'] ) ) {
			if ( '' === $min_height && ! empty( $vid['hero_height'] ) ) { $min_height = (string) $vid['hero_height']; }
			if ( '' === $valign && ! empty( $vid['valign'] ) ) { $valign = (string) $vid['valign']; }
			if ( '' === $bg['overlay'] && ! empty( $vid['overlay'] ) ) { $bg['overlay'] = (string) $vid['overlay']; }
		}
		// A hero's BACKGROUND video arrives as a video block (`bg:true`, absolute inset-0 behind the copy): it is the
		// section's background video, with its overlay, height and centring — the native build does the same.
		foreach ( $blocks as $i => $b ) {
			if ( 'video' !== $b['type'] || empty( $b['bg'] ) || '' !== $bg['video'] ) { continue; }
			$bg['video'] = $b['src'];
			if ( '' === $bg['overlay'] ) { $bg['overlay'] = $b['overlay']; }
			if ( '' === $min_height && '' !== $b['hero_height'] ) { $min_height = $b['hero_height']; }
			if ( '' === $valign && '' !== $b['valign'] ) { $valign = $b['valign']; }
			unset( $blocks[ $i ] );
			$blocks = array_values( $blocks );
			break;
		}

		return array(
			'id'              => $id,
			'style'           => FW_SC_Style::parse( $s['sectionCs'] ?? '' ),
			'style_sm'        => FW_SC_Style::parse( $s['sectionCsSm'] ?? '' ),
			'inner_style'     => $inner,
			'container_width' => self::container_px( $s['sectionContainerW'] ?? null ),
			'full_bleed'      => ! empty( $s['sectionFullBleed'] ),
			'min_height'      => $min_height,
			'valign'          => $valign,
			'align'           => (string) ( $s['align'] ?? '' ),
			'verbatim'        => ! empty( $s['verbatim'] ),
			'background'      => $bg,
			'blocks'          => $blocks,
		);
	}

	/**
	 * A container-width value → its pixel cap (0 = full width / not a fixed cap). Mirrors the Mapper's
	 * container_width_px(), which is the source of truth for what the presets mean.
	 */
	public static function container_px( $cw ) {
		if ( ! is_array( $cw ) ) { return 0; }
		$named = array( 'small' => 640, 'prose' => 672, 'narrow' => 768, 'medium' => 896, 'wide' => 1024, 'wide-l' => 1152, 'wide-xl' => 1280, 'wide-xxl' => 1440 );
		$preset = (string) ( $cw['preset'] ?? '' );
		if ( isset( $named[ $preset ] ) ) { return $named[ $preset ]; }
		if ( 'custom' === $preset ) {
			$c = ( isset( $cw['custom'] ) && is_array( $cw['custom'] ) ) ? $cw['custom'] : $cw;
			$v = (float) ( $c['value'] ?? 0 );
			$u = (string) ( $c['unit'] ?? 'px' );
			if ( $v <= 0 ) { return 0; }
			if ( 'rem' === $u ) { return (int) round( $v * 16 ); }
			return 'px' === $u ? (int) round( $v ) : 0;
		}
		if ( preg_match( '/(\d{3,4})/', $preset, $m ) ) { return (int) $m[1]; }
		return 0;
	}

	/* ------------------------------------------------------------------ *
	 * Blocks
	 * ------------------------------------------------------------------ */

	private static function blocks( array $list, array $pal ) {
		$out = array();
		foreach ( $list as $b ) {
			if ( ! is_array( $b ) || empty( $b['t'] ) ) { continue; }
			$n = self::block( $b, $pal );
			if ( $n ) { $out[] = $n; }
		}
		return $out;
	}

	private static function block( array $b, array $pal ) {
		$t      = (string) $b['t'];
		$margin = array(
			'top'    => self::num( $b['mt'] ?? ( $b['mtAdd'] ?? 0 ) ),
			'bottom' => self::num( $b['mb'] ?? ( $b['mbAdd'] ?? 0 ) ),
		);
		$base = array(
			'style'    => FW_SC_Style::parse( $b['cs'] ?? '' ),
			'style_sm' => self::phone_style( FW_SC_Style::parse( $b['csSm'] ?? '' ), (string) ( $b['cls'] ?? '' ) ),
			'margin'   => $margin,
		);

		switch ( $t ) {
			case 'heading':
				return array( 'type' => 'heading', 'level' => max( 1, min( 6, (int) ( $b['level'] ?? 2 ) ) ),
					'text' => (string) ( $b['text'] ?? '' ), 'html' => (string) ( $b['html'] ?? '' ),
					'align' => (string) ( $b['align'] ?? '' ), 'max_width' => (string) ( $b['wrapMaxW'] ?? '' ),
					'role' => (string) ( $b['role'] ?? '' ) ) + $base;

			case 'text':
				return array( 'type' => 'text', 'text' => (string) ( $b['text'] ?? '' ), 'html' => (string) ( $b['html'] ?? '' ),
					'align' => (string) ( $b['align'] ?? '' ), 'max_width' => (string) ( $b['maxWidth'] ?? '' ),
					'link_style' => FW_SC_Style::parse( $b['linkCs'] ?? '' ) ) + $base;

			case 'button':
				return array( 'type' => 'button', 'label' => (string) ( $b['label'] ?? '' ), 'href' => (string) ( $b['href'] ?? '' ),
					'icon_svg' => (string) ( $b['iconSvg'] ?? '' ), 'icon_pos' => ( 'before' === ( $b['iconPos'] ?? '' ) ) ? 'before' : 'after',
					'align' => (string) ( $b['align'] ?? '' ), 'hover_css' => (string) ( $b['srcHover'] ?? '' ),
					'group_style' => FW_SC_Style::parse( $b['groupCs'] ?? '' ),
					'style' => FW_SC_Style::parse( $b['srcCs'] ?? '' ), 'style_sm' => array(), 'margin' => $margin );

			case 'image':
				$src = '';
				$alt = '';
				$html = (string) ( $b['html'] ?? '' );
				if ( preg_match( '/<img\b[^>]*\bsrc="([^"]+)"/i', $html, $m ) ) { $src = html_entity_decode( $m[1] ); }
				if ( preg_match( '/<img\b[^>]*\balt="([^"]*)"/i', $html, $m ) ) { $alt = html_entity_decode( $m[1] ); }
				return array( 'type' => 'image', 'src' => $src, 'alt' => $alt, 'html' => $html,
					'css' => (string) ( $b['skinCss'] ?? '' ) ) + $base;

			case 'row':
				$cols = array();
				foreach ( (array) ( $b['cols'] ?? array() ) as $c ) {
					if ( ! is_array( $c ) ) { continue; }
					$resp = isset( $c['wResp'] ) && is_array( $c['wResp'] ) ? $c['wResp'] : array();
					$hide = isset( $c['hide'] ) && is_array( $c['hide'] ) ? $c['hide'] : array();
					$cols[] = array(
						'span'      => isset( $resp['desktop'] ) ? (int) $resp['desktop'] : 0,
						'span_resp' => $resp,
						'max_width' => (string) ( $c['maxw'] ?? '' ),
						'min_height' => (string) ( $c['minh'] ?? '' ),
						// Per-device visibility — the recognizer's hide-xs … hide-xl flags as three devices.
						'hidden'    => array(
							'mobile'  => ! empty( $hide['hide-xs'] ) || ! empty( $hide['hide-sm'] ),
							'tablet'  => ! empty( $hide['hide-md'] ),
							'desktop' => ! empty( $hide['hide-lg'] ) || ! empty( $hide['hide-xl'] ),
						),
						// A column can BE its content: verbatim markup, or one card (image / icon, title, text, link).
						'html'      => (string) ( $c['html'] ?? '' ),
						'card'      => isset( $c['card'] ) && is_array( $c['card'] ) ? self::card( $c['card'] ) : null,
						'counter'   => isset( $c['counter'] ) && is_array( $c['counter'] ) ? self::counter( $c['counter'] ) : null,
						'box'       => self::card_box( $c['cardBox'] ?? null ),
						'blocks'    => self::blocks( (array) ( $c['blocks'] ?? array() ), $pal ),
					);
				}
				$n = count( $cols );
				// A no-wrap row whose columns declare no width (a CTA beside a stat) is a row of content-sized
				// items: each hugs its content, the gap between them. The fallback span below still gives every
				// column a real 1–12 span, but a target lays this row out by content, not by equal shares.
				$declared = false;
				foreach ( $cols as $c ) { if ( $c['span'] > 0 ) { $declared = true; break; } }
				// …and a JUSTIFIED row (space-between / center / end) lays its cells out by content even when they
				// declare spans: a heading beside a capped intro takes its natural width (the native Mapper's rule).
				$justified     = in_array( (string) ( $b['justify'] ?? '' ), array( 'space-between', 'between', 'space-around', 'space-evenly', 'center', 'flex-end', 'end' ), true );
				$content_sized = $n > 1 && empty( $b['srcGrid'] ) && ( ( ! $declared && ! empty( $b['nowrap'] ) ) || $justified );
				foreach ( $cols as $i => $c ) { if ( $c['span'] <= 0 && $n ) { $cols[ $i ]['span'] = (int) max( 1, floor( 12 / $n ) ); } }
				// N EQUAL columns where N does not divide 12 (seven cards, five steps): the 12-span model cannot
				// express them (spans of 2 sum past 12 and wrap 6 + 1), so the row is N equal tracks — the native
				// Mapper renders the same case as an N-track grid.
				$tracks = 0;
				if ( $n >= 3 && $n <= 12 && 0 !== 12 % $n && ! $content_sized ) {
					$spans = array_unique( array_map( function ( $c ) { return (int) $c['span']; }, $cols ) );
					$tracks = ( 1 === count( $spans ) ) ? $n : 0;
				}
				return array( 'type' => 'row', 'gap' => self::num( $b['gap'] ?? 0 ), 'gap_resp' => (array) ( $b['gapResp'] ?? array() ),
					'content_sized' => $content_sized, 'tracks' => $tracks,
					'valign' => (string) ( $b['valign'] ?? '' ), 'justify' => (string) ( $b['justify'] ?? '' ),
					'stack_bp' => (string) ( $b['stackBp'] ?? 'md' ), 'max_width' => (string) ( $b['capW'] ?? '' ),
					'columns' => $cols ) + $base;

			case 'stack':
				return array( 'type' => 'stack', 'gap' => self::num( $b['gap'] ?? 0 ),
					'max_width' => (string) ( $b['capW'] ?? '' ),
					'blocks' => self::blocks( (array) ( $b['items'] ?? array() ), $pal ) ) + $base;

			case 'panel':
				$box = isset( $b['box'] ) && is_array( $b['box'] ) ? $b['box'] : array();
				return array( 'type' => 'box',
					'box' => array(
						'background' => (string) ( $box['bg'] ?? '' ), 'gradient' => (string) ( $box['gradient'] ?? '' ),
						'radius' => (string) ( $box['radius'] ?? '' ), 'border_width' => (string) ( $box['borderW'] ?? '' ),
						'border_color' => (string) ( $box['borderColor'] ?? '' ), 'shadow' => (string) ( $box['shadow'] ?? '' ),
						'backdrop' => (string) ( $box['backdrop'] ?? '' ), 'extra_css' => (string) ( $box['extra'] ?? '' ),
					),
					'padding' => (array) ( $b['pad'] ?? array() ), 'align' => (string) ( $b['align'] ?? '' ),
					'max_width' => (string) ( $b['maxw'] ?? ( $b['capW'] ?? '' ) ), 'min_height' => (string) ( $b['height'] ?? '' ),
					'blocks' => self::blocks( (array) ( $b['blocks'] ?? array() ), $pal ) ) + $base;

			case 'lone_icon':
				return array( 'type' => 'icon', 'svg' => (string) ( $b['svg'] ?? '' ), 'size' => self::num( $b['size'] ?? 0 ),
					'color' => FW_SC_Style::color( (string) ( $b['color'] ?? '' ) ) ) + $base;

			case 'badge':
				return array( 'type' => 'badge', 'text' => trim( (string) ( $b['message'] ?? '' ) . ' ' . (string) ( $b['tag_text'] ?? '' ) ),
					'icon_svg' => (string) ( $b['leadingSvg'] ?? '' ), 'href' => (string) ( $b['link'] ?? '' ),
					'align' => (string) ( $b['align'] ?? '' ),
					'style' => FW_SC_Style::parse( $b['pillCs'] ?? '' ), 'text_style' => FW_SC_Style::parse( $b['msgCs'] ?? '' ),
					'style_sm' => array(), 'margin' => $margin );

			case 'image_box':
				// A photo card: its card (image, title, text, button) and, when the caption sits OVER the photo, the
				// overlay (scrim gradient, placement, inset).
				$ov = isset( $b['overlay'] ) && is_array( $b['overlay'] ) ? $b['overlay'] : array();
				return array( 'type' => 'image_box', 'card' => self::card( (array) ( $b['card'] ?? array() ) ),
					'overlay' => $ov ? array( 'scrim' => (string) ( $ov['scrim'] ?? '' ), 'place' => (string) ( $ov['place'] ?? 'bottom' ),
						'align' => (string) ( $ov['align'] ?? '' ), 'inset' => self::num( $ov['inset'] ?? 0 ) ) : null ) + $base;

			case 'video':
				// A video: the section's background (`bg`, promoted in section()) or an inline player. Its height is
				// the source's own `h-[…]` (the first height utility on the element).
				$h = 0;
				if ( preg_match( '/(?:^|\s)h-\[([\d.]+)(rem|px)\]/', (string) ( $b['rhideCls'] ?? '' ), $hm ) ) { $h = 'rem' === $hm[2] ? (float) $hm[1] * 16 : (float) $hm[1]; }
				$yes = function ( $k, $d ) use ( $b ) { $v = $b[ $k ] ?? $d; return true === $v || 'yes' === $v; };
				return array( 'type' => 'video', 'src' => (string) ( $b['src'] ?? '' ), 'webm' => (string) ( $b['webm'] ?? '' ),
					'poster' => (string) ( $b['poster'] ?? '' ), 'bg' => ! empty( $b['bg'] ), 'overlay' => (string) ( $b['overlay'] ?? '' ),
					'hero_height' => (string) ( $b['hero_height'] ?? '' ), 'valign' => (string) ( $b['valign'] ?? '' ),
					'cover' => ! empty( $b['cover'] ), 'height' => $h, 'card_css' => (string) ( $b['card_css'] ?? '' ),
					'autoplay' => $yes( 'autoplay', 'no' ), 'muted' => $yes( 'muted', 'no' ), 'loop' => $yes( 'loop', 'no' ),
					'controls' => $yes( 'controls', 'yes' ) ) + $base;

			case 'chips':
				// A row of pills (tags, filters, materials): each its own text and pill skin.
				$chips = array();
				foreach ( (array) ( $b['items'] ?? array() ) as $it ) {
					if ( ! is_array( $it ) ) { continue; }
					$text = trim( (string) ( $it['text'] ?? '' ) );
					if ( '' === $text && ! empty( $it['html'] ) ) { $text = trim( wp_strip_all_tags( (string) $it['html'] ) ); }
					if ( '' === $text ) { continue; }
					$chips[] = array( 'text' => $text, 'icon_svg' => (string) ( $it['iconSvg'] ?? '' ),
						'style' => FW_SC_Style::parse( $it['cs'] ?? '' ) );
				}
				return array( 'type' => 'chips', 'items' => $chips, 'gap' => self::num( $b['gap'] ?? 0 ),
					'align' => (string) ( $b['align'] ?? '' ) ) + $base;

			case 'feature_list':
				$items = array();
				foreach ( (array) ( $b['items'] ?? array() ) as $it ) {
					if ( ! is_array( $it ) ) { continue; }
					$items[] = array( 'text' => (string) ( $it['text'] ?? '' ), 'icon_svg' => (string) ( $it['icon_svg'] ?? '' ),
						'icon_style' => FW_SC_Style::parse( $it['icon_cs'] ?? '' ) );
				}
				return array( 'type' => 'list', 'ordered' => ! empty( $b['ordered'] ), 'items' => $items,
					'item_gap' => self::num( $b['item_gap'] ?? 0 ),
					'label_style' => FW_SC_Style::parse( $b['label_cs'] ?? '' ),
					'list_style' => FW_SC_Style::parse( $b['list_cs'] ?? '' ) ) + $base;

			case 'testimonials':
				$items = array();
				foreach ( (array) ( $b['items'] ?? array() ) as $it ) {
					if ( ! is_array( $it ) ) { continue; }
					$look = isset( $it['look'] ) && is_array( $it['look'] ) ? $it['look'] : array();
					$items[] = array( 'quote' => (string) ( $it['quote'] ?? '' ), 'image' => (string) ( $it['image'] ?? '' ),
						'name' => (string) ( $it['name'] ?? '' ), 'role' => (string) ( $it['position'] ?? '' ),
						'rating' => (float) ( $it['rating'] ?? 0 ),
						'quote_style' => FW_SC_Style::parse( $look['quoteCs'] ?? '' ),
						'name_style' => FW_SC_Style::parse( $look['nameCs'] ?? '' ),
						'role_style' => FW_SC_Style::parse( $look['jobCs'] ?? '' ) );
				}
				$design = isset( $b['design'] ) && is_array( $b['design'] ) ? $b['design'] : array();
				$cols   = 0;
				if ( isset( $design['grid_columns'] ) && preg_match( '/(\d+)/', (string) $design['grid_columns'], $m ) ) { $cols = (int) $m[1]; }
				return array( 'type' => 'testimonials', 'items' => $items,
					'columns' => $cols ? $cols : min( 3, max( 1, count( $items ) ) ),
					'layout' => ( 'carousel' === ( $design['layout_choice'] ?? '' ) ) ? 'carousel' : 'grid',
					'gap' => self::num( $b['gridGap'] ?? 0 ), 'card' => self::card_box( $b['cardBox'] ?? null ) ) + $base;

			case 'accordion':
				$items = array();
				foreach ( (array) ( $b['items'] ?? array() ) as $it ) {
					if ( ! is_array( $it ) ) { continue; }
					$items[] = array( 'title' => (string) ( $it['title'] ?? '' ), 'content' => (string) ( $it['content'] ?? '' ), 'open' => ! empty( $it['open'] ) );
				}
				$d = isset( $b['design'] ) && is_array( $b['design'] ) ? $b['design'] : array();
				return array( 'type' => 'accordion', 'items' => $items, 'faq' => ! empty( $b['faq'] ),
					'multiple_open' => 'yes' === ( $d['multiple_open'] ?? '' ),
					'item' => array(
						'background' => FW_SC_Style::color( (string) ( $d['item_bg'] ?? '' ) ),
						'border' => (string) ( $d['item_border'] ?? '' ), 'radius' => (string) ( $d['item_radius'] ?? '' ),
						'title_background' => self::resolve_color( $d['title_bg_color'] ?? '', $pal ),
						'title_tag' => (string) ( $d['title_tag'] ?? 'h3' ),
					),
					'max_width' => (string) ( $b['capW'] ?? '' ) ) + $base;

			case 'steps':
				$items = array();
				foreach ( (array) ( $b['items'] ?? array() ) as $it ) {
					if ( ! is_array( $it ) ) { continue; }
					$items[] = array( 'title' => (string) ( $it['title'] ?? '' ), 'content' => (string) ( $it['content'] ?? '' ),
						'number' => (string) ( $it['number'] ?? '' ),
						'title_style' => FW_SC_Style::parse( $it['titleCs'] ?? '' ), 'text_style' => FW_SC_Style::parse( $it['textCs'] ?? '' ),
						'number_style' => FW_SC_Style::parse( $it['numCs'] ?? '' ) );
				}
				return array( 'type' => 'steps', 'items' => $items, 'max_width' => (string) ( $b['capW'] ?? '' ) ) + $base;

			case 'logo_grid':
				$logos = array();
				foreach ( (array) ( $b['logos'] ?? array() ) as $l ) {
					if ( ! is_array( $l ) ) { continue; }
					$logos[] = array( 'url' => (string) ( $l['url'] ?? '' ), 'name' => (string) ( $l['name'] ?? '' ),
						'href' => (string) ( $l['link_url'] ?? '' ), 'svg' => (string) ( $l['svg'] ?? '' ) );
				}
				return array( 'type' => 'logos', 'logos' => $logos, 'gap' => self::num( $b['gap'] ?? 0 ),
					'opacity' => (float) ( $b['opacity'] ?? 1 ), 'grayscale' => 'yes' === ( $b['grayscale'] ?? '' ),
					'height' => self::num( $b['iconSize'] ?? 0 ) ) + $base;

			case 'avatar':
				return array( 'type' => 'avatars', 'images' => array_values( array_filter( (array) ( $b['avatars'] ?? array() ), 'is_string' ) ),
					'extra' => (string) ( $b['extra_count'] ?? '' ), 'label' => (string) ( $b['label'] ?? '' ) ) + $base;

			case 'floating_card':
				$c = isset( $b['card'] ) && is_array( $b['card'] ) ? $b['card'] : array();
				return array( 'type' => 'card', 'title' => (string) ( $c['title'] ?? '' ), 'text' => (string) ( $c['text'] ?? '' ),
					'title_style' => FW_SC_Style::parse( $c['titleCs'] ?? '' ), 'text_style' => FW_SC_Style::parse( $c['bodyCs'] ?? '' ),
					'position_css' => (string) ( $b['posCss'] ?? '' ) ) + $base;

			case 'paint':
				return array( 'type' => 'decor', 'css' => (string) ( $b['css'] ?? '' ) ) + $base;

			case 'heading_cta':
				// A section heading with a link set beside it ("Latest news … View all →").
				$h = isset( $b['heading'] ) && is_array( $b['heading'] ) ? $b['heading'] : array();
				$l = isset( $b['link'] ) && is_array( $b['link'] ) ? $b['link'] : array();
				return array( 'type' => 'heading_link',
					'heading' => array( 'html' => (string) ( $h['title'] ?? '' ), 'level' => max( 1, min( 6, (int) ( $h['level'] ?? 2 ) ) ),
						'overline' => (string) ( $h['overline'] ?? '' ), 'subtitle' => (string) ( $h['subtitle'] ?? '' ),
						'style' => FW_SC_Style::parse( $h['title_cs'] ?? '' ),
						'subtitle_style' => FW_SC_Style::parse( $h['subtitle_cs'] ?? '' ) ),
					'link' => array( 'label' => (string) ( $l['label'] ?? '' ), 'href' => (string) ( $l['href'] ?? '' ),
						'style' => FW_SC_Style::parse( $l['cs'] ?? '' ) ) ) + $base;

			case 'html':
			case 'code':
				return array( 'type' => 'html', 'html' => (string) ( $b['html'] ?? '' ) ) + $base;
		}

		// Not normalised yet: carry the original so a target can still fall back to its HTML / text.
		return array( 'type' => 'unknown', 'source_type' => $t, 'data' => $b ) + $base;
	}

	/* ------------------------------------------------------------------ *
	 * Helpers
	 * ------------------------------------------------------------------ */

	private static function num( $v ) {
		if ( is_numeric( $v ) ) { return (float) $v; }
		$px = FW_SC_Style::px( (string) $v );
		return null === $px ? 0.0 : $px;
	}

	/**
	 * A column-card (the recognizer's `card`): its parts as plain content + measured styles.
	 * { image, image_height, icon_svg, overline{ text, style }, title, title_tag, title_style, text, text_style,
	 *   link{ href, label }, center, style (the card's own box) }
	 */
	private static function card( array $c ) {
		$img = '';
		if ( ! empty( $c['image'] ) ) {
			$i   = $c['image'];
			$img = is_string( $i ) ? $i : (string) ( $i['url'] ?? ( $i['src'] ?? ( is_array( $i ) && isset( $i[0] ) && is_string( $i[0] ) ? $i[0] : '' ) ) );
		}
		if ( '' === $img && ! empty( $c['imgIcon'] ) ) { $img = (string) $c['imgIcon']; }
		$ov   = isset( $c['overline'] ) && is_array( $c['overline'] ) ? $c['overline'] : array();
		$link = isset( $c['link'] ) && is_array( $c['link'] ) ? $c['link'] : array();
		$btn  = isset( $c['button'] ) && is_array( $c['button'] ) ? $c['button'] : array();
		return array(
			'image'        => $img,
			'image_height' => self::num( $c['imgIconH'] ?? 0 ),
			'icon_svg'     => (string) ( $c['customIcon'] ?? '' ),
			'overline'     => array( 'text' => (string) ( $ov['text'] ?? '' ), 'style' => FW_SC_Style::parse( $ov['cs'] ?? '' ) ),
			'title'        => (string) ( $c['title'] ?? '' ),
			'title_tag'    => preg_match( '/^h[1-6]$/', (string) ( $c['titleTag'] ?? '' ) ) ? (string) $c['titleTag'] : 'h3',
			'title_style'  => FW_SC_Style::parse( $c['titleCs'] ?? '' ),
			'text'         => (string) ( $c['text'] ?? '' ),
			'text_style'   => FW_SC_Style::parse( $c['bodyCs'] ?? '' ),
			'link'         => array( 'href' => (string) ( $link['href'] ?? ( $link['url'] ?? ( $btn['href'] ?? '' ) ) ), 'label' => (string) ( $link['label'] ?? ( $btn['label'] ?? '' ) ) ),
			'center'       => ! empty( $c['center'] ),
			'style'        => FW_SC_Style::parse( $c['cardCs'] ?? ( $c['cs'] ?? '' ) ),
			// The card's own call to action, as a button block (its measured skin), when it has a label.
			'button'       => ( '' !== trim( (string) ( $btn['label'] ?? '' ) ) ) ? array( 'type' => 'button',
				'label' => (string) $btn['label'], 'href' => (string) ( $btn['href'] ?? '' ), 'icon_svg' => '',
				'icon_pos' => 'after', 'align' => '', 'hover_css' => '', 'group_style' => array(),
				'style' => FW_SC_Style::parse( $btn['cs'] ?? '' ), 'style_sm' => array(), 'margin' => array( 'top' => 16, 'bottom' => 0 ) ) : null,
		);
	}

	/** A column-counter (a stat cell): the number and its parts, the label, measured styles. */
	private static function counter( array $c ) {
		return array(
			'number'       => (string) ( $c['number'] ?? '' ),
			'prefix'       => (string) ( $c['prefix'] ?? '' ),
			'suffix'       => (string) ( $c['suffix'] ?? '' ),
			'decimals'     => (int) ( $c['decimals'] ?? 0 ),
			'label'        => (string) ( $c['label'] ?? '' ),
			'label_first'  => ! empty( $c['labelFirst'] ),
			'align'        => (string) ( $c['align'] ?? '' ),
			'number_style' => FW_SC_Style::parse( $c['numberCs'] ?? '' ),
			'label_style'  => FW_SC_Style::parse( $c['labelCs'] ?? '' ),
		);
	}

	/** A card / box description, whatever shape the recognizer left it in, as plain CSS facts. */
	private static function card_box( $box ) {
		if ( is_string( $box ) ) { return array( 'css' => $box ); }
		if ( ! is_array( $box ) ) { return array(); }
		$out = array();
		foreach ( $box as $k => $v ) {
			if ( is_scalar( $v ) ) { $out[ $k ] = (string) $v; }
		}
		return $out;
	}

	/**
	 * A colour field that may still be in the Unyson+ compact shape — { predefined: 'bg-ink', custom: '#…' }
	 * or a bare preset slug — resolved to a plain CSS colour. The custom value wins when present.
	 */
	public static function resolve_color( $v, array $pal ) {
		if ( is_array( $v ) ) {
			$custom = trim( (string) ( $v['custom'] ?? '' ) );
			if ( '' !== $custom ) { return FW_SC_Style::color( $custom ); }
			$v = (string) ( $v['predefined'] ?? '' );
		}
		$v = trim( (string) $v );
		if ( '' === $v ) { return ''; }
		if ( preg_match( '/^(#|rgb|hsl|oklch|transparent)/i', $v ) ) { return FW_SC_Style::color( $v ); }
		$slug = preg_replace( '/^(text|bg|border)-/', '', $v );
		return isset( $pal[ $slug ] ) ? FW_SC_Style::color( (string) $pal[ $slug ] ) : '';
	}
}
