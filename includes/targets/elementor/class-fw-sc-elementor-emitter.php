<?php if ( ! defined( 'FW' ) ) { die( 'Forbidden' ); }

/**
 * Site Model page → Elementor element tree (Flexbox Containers + the free widget set).
 *
 * Every block goes down a fallback ladder and the rung it lands on is counted, because a page that
 * LOOKS right but is mostly raw HTML is not an Elementor page anyone can edit:
 *
 *   native    — a widget that means the same thing (heading → heading, accordion → accordion)
 *   composed  — a container of native widgets (steps, logos, a badge pill)
 *   html      — the block's markup in an HTML widget (looks right, edits as code)
 *
 * Two Elementor defaults are overridden on every container on purpose: the kit's 20px "space between
 * widgets" and 10px container padding. Both would add spacing the source never had; the measured
 * margins and padding carry the real spacing instead.
 *
 * Pure: the only outside lookups go through the callables in $opts, so tests run it without WordPress.
 *
 * $opts:
 *   media    callable( string $url ) : array{ id:int, url:string }  — resolve an image to the media library
 *   html     callable( string $html ) : string  — point the image URLs inside raw markup at the media library
 *   native   callable( string $block_type ) : array{ widget:string, settings:array }|null  — the block's own
 *            Unyson+ element as an Elementor widget, when one is registered (the Elementor
 *            extension's `up-<tag>` widgets). Asked once per block, in document order; null = not available.
 *   colors   array( '#hex' => 'global id' )   — palette colours to bind as Elementor globals
 *   fonts    array( heading, body )            — the site fonts; widgets inherit these instead of repeating them
 *   seed     string                            — makes element ids deterministic per page
 *   container int                              — the site's container width (px) for a section with no measured width
 */
class FW_SC_Elementor_Emitter {

	private $opts;
	private $n     = 0;
	private $k     = 0;
	private $css   = array();
	private $trace = array( 'native' => 0, 'composed' => 0, 'html' => 0, 'skipped' => 0 );
	private $fallbacks = array();

	public function __construct( array $opts = array() ) {
		$this->opts = $opts + array(
			'media'  => null,
			'html'   => null,
			'native' => null,
			'container' => 0,
			'colors' => array(),
			'fonts'  => array( 'heading' => '', 'body' => '' ),
			'seed'   => 'page',
		);
	}

	/**
	 * @param array $page a Site Model page
	 * @return array { elements: array, css: string, trace: array, fallbacks: string[] }
	 */
	public function page( array $page ) {
		$this->n = 0; $this->k = 0; $this->css = array(); $this->fallbacks = array();
		$this->trace = array( 'native' => 0, 'composed' => 0, 'html' => 0, 'skipped' => 0 );
		$elements = array();
		foreach ( (array) ( $page['sections'] ?? array() ) as $s ) {
			$elements[] = $this->section( $s );
		}
		// The page root clips horizontal overflow, as the native page-builder wrapper does (`overflow-x:clip`, which
		// keeps sticky children working): a marquee track or an oversized word must not make a phone scroll sideways.
		$this->css['root'] = '.elementor[data-elementor-type="wp-page"]{overflow-x:clip}';
		// The source <main>'s vertical padding, where the native output puts it on #main: on the page's own
		// Elementor root (this CSS is printed only on this page).
		$mt = (float) ( $page['main']['padding_top'] ?? 0 );
		$mb = (float) ( $page['main']['padding_bottom'] ?? 0 );
		if ( $mt > 0 || $mb > 0 ) {
			$this->css['main'] = '.elementor[data-elementor-type="wp-page"]{' . ( $mt > 0 ? 'padding-top:' . round( $mt ) . 'px;' : '' ) . ( $mb > 0 ? 'padding-bottom:' . round( $mb ) . 'px;' : '' ) . '}';
		}
		return array(
			'elements'  => $elements,
			'css'       => implode( "\n", array_filter( $this->css ) ),
			'trace'     => $this->trace,
			'fallbacks' => array_values( array_unique( $this->fallbacks ) ),
		);
	}

	/* ------------------------------------------------------------------ *
	 * Sections + layout containers
	 * ------------------------------------------------------------------ */

	private function section( array $s ) {
		$st  = (array) ( $s['style'] ?? array() );
		$in  = (array) ( $s['inner_style'] ?? array() );
		$pad = FW_SC_Style::box( $st['padding'] ?? '' ) ?: array( 0, 0, 0, 0 );
		$ipd = FW_SC_Style::box( $in['padding'] ?? '' );
		if ( $ipd ) { $pad[1] += $ipd[1]; $pad[3] += $ipd[3]; $pad[0] += $ipd[0]; $pad[2] += $ipd[2]; }

		$cw  = (int) ( $s['container_width'] ?? 0 );
		$set = $this->container_base( 'column' );
		$set['html_tag'] = 'section';
		// A section with no measured width sits in the SITE container — Elementor's boxed width, which is the
		// kit's container width (synced from Theme Settings) — exactly where the native output puts it. Only a
		// full-bleed band runs edge to edge.
		$set['content_width'] = empty( $s['full_bleed'] ) ? 'boxed' : 'full';
		if ( 'boxed' === $set['content_width'] && $cw <= 0 ) { $cw = (int) $this->opts['container']; }
		if ( 'boxed' === $set['content_width'] && $cw > 0 ) { $set['boxed_width'] = $this->size( $cw ); }
		// Vertical rhythm set by the section's own MARGIN (`mb-24 md:mb-32`). On a transparent band a margin and
		// the same padding render alike, so it folds into padding, as the native output does. A painted band keeps
		// a real margin, so the gap stays outside its background. Horizontal / `auto` margins only centre: ignored.
		$mv = $this->vmargin( (string) ( $st['margin'] ?? '' ) );
		if ( $mv[0] > 0 || $mv[1] > 0 ) {
			$bgc     = (string) ( $st['background-color'] ?? '' );
			$painted = ( '' !== $bgc && ! FW_SC_Style::is_transparent( $bgc ) ) || ! empty( $s['background']['image'] ) || ! empty( $s['background']['video'] )
				|| ( ! empty( $st['background-image'] ) && 'none' !== $st['background-image'] );
			if ( $painted ) {
				$set['margin'] = $this->dims( array( $mv[0], 0, $mv[1], 0 ) );
			} else {
				$pad[0] += $mv[0];
				$pad[2] += $mv[1];
			}
		}
		$set['padding'] = $this->dims( $pad );
		if ( ! empty( $s['id'] ) ) { $set['_element_id'] = sanitize_title( $s['id'] ); }

		$this->background( $set, $st, $s['background'] ?? array() );
		if ( ! empty( $s['min_height'] ) && preg_match( '/^([\d.]+)(vh|px)$/', $s['min_height'], $m ) ) {
			$set['min_height'] = $this->size( (float) $m[1], $m[2] );
			$set['flex_justify_content'] = ( 'top' === ( $s['valign'] ?? '' ) ) ? 'flex-start' : 'center';
		}
		$cls = $this->cls( 'sec' );
		$set['css_classes'] = trim( ( $set['css_classes'] ?? '' ) . ' ' . $cls );
		if ( ! empty( $s['background']['overlay'] ) ) {
			// A gradient overlay over a photo: Elementor's overlay control takes two colour stops; the measured
			// CSS is exact, so it rides the page stylesheet instead.
			$this->css[] = '.' . $cls . '{position:relative}.' . $cls . '::before{content:"";position:absolute;inset:0;pointer-events:none;background:' . $s['background']['overlay'] . '}.' . $cls . '>.e-con-inner{position:relative;z-index:1}';
		}
		$this->trace['native']++;
		return $this->container( $set, $this->children( (array) ( $s['blocks'] ?? array() ) ) );
	}

	private function children( array $blocks ) {
		$out = array();
		$n   = count( $blocks );
		for ( $i = 0; $i < $n; $i++ ) {
			$b = $blocks[ $i ];
			// A run of buttons sharing one flex-row group (a CTA pair) → one row container, as in the source.
			$g = $this->button_group( $b );
			if ( $g ) {
				$run = array( $b );
				while ( $i + 1 < $n && $this->button_group( $blocks[ $i + 1 ] ) === $g ) { $run[] = $blocks[ ++$i ]; }
				if ( count( $run ) > 1 ) { $out[] = $this->button_row( $run ); continue; }
			}
			$el = $this->block( $b );
			if ( $el ) { $out[] = $el; }
		}
		return $out;
	}

	/** A button's flex-row group signature, or '' when it is not in one. */
	private function button_group( array $b ) {
		if ( 'button' !== ( $b['type'] ?? '' ) ) { return ''; }
		$g = (array) ( $b['group_style'] ?? array() );
		if ( ! in_array( $g['display'] ?? '', array( 'flex', 'inline-flex' ), true ) || 'column' === ( $g['flex-direction'] ?? 'row' ) ) { return ''; }
		return md5( wp_json_encode( $g ) );
	}

	/** Buttons in one row: the group's gap and justification; they stack on phones, like a wrapping flex row. */
	private function button_row( array $run ) {
		$g   = (array) $run[0]['group_style'];
		$set = $this->container_base( 'row' );
		$set['flex_wrap']             = 'wrap';
		$set['flex_direction_mobile'] = 'column';
		$set['flex_align_items']      = 'center';
		$gap = FW_SC_Style::px( $g['gap'] ?? ( $g['column-gap'] ?? '' ) );
		$set['flex_gap'] = $this->gap( null !== $gap ? $gap : 16 );
		$jc = array( 'center' => 'center', 'flex-end' => 'flex-end', 'end' => 'flex-end', 'space-between' => 'space-between' );
		if ( isset( $jc[ $g['justify-content'] ?? '' ] ) ) { $set['flex_justify_content'] = $jc[ $g['justify-content'] ]; }
		$kids = array();
		foreach ( $run as $b ) {
			$el = $this->block( $b );
			if ( $el ) { $el['settings']['_element_width'] = 'auto'; $kids[] = $el; }
		}
		$m = FW_SC_Style::box( $g['margin'] ?? '' );
		if ( $m && ( $m[0] || $m[2] ) ) { $set['margin'] = $this->dims( array( $m[0], 0, $m[2], 0 ) ); }
		$this->trace['composed']++;
		return $this->container( $set, $kids );
	}

	/** Defaults every container gets: flex, no inherited kit gap, no inherited kit padding. */
	private function container_base( $direction ) {
		return array(
			'container_type' => 'flex',
			'content_width'  => 'full',
			'flex_direction' => $direction,
			'flex_gap'       => $this->gap( 0 ),
			'padding'        => $this->dims( array( 0, 0, 0, 0 ) ),
		);
	}

	private function container( array $settings, array $children ) {
		return array( 'id' => $this->id(), 'elType' => 'container', 'isInner' => false, 'settings' => $settings, 'elements' => $children );
	}

	private function widget( $type, array $settings, array $b = array() ) {
		$this->apply_box_common( $settings, $b );
		return array( 'id' => $this->id(), 'elType' => 'widget', 'widgetType' => $type, 'isInner' => false, 'settings' => $settings, 'elements' => array() );
	}

	/** Margin + max width shared by every widget. */
	private function apply_box_common( array &$set, array $b ) {
		$top = (float) ( $b['margin']['top'] ?? 0 );
		$bot = (float) ( $b['margin']['bottom'] ?? 0 );
		$m   = FW_SC_Style::box( $b['style']['margin'] ?? '' );
		if ( $m ) { $top = $top ?: $m[0]; $bot = $bot ?: $m[2]; }
		if ( $top || $bot ) { $set['_margin'] = $this->dims( array( $top, 0, $bot, 0 ) ); }
		$mw = FW_SC_Style::px( $b['max_width'] ?? '' );
		if ( $mw && $mw > 0 ) {
			$set['_element_width']        = 'initial';
			$set['_element_custom_width'] = $this->size( $mw );
			if ( 'center' === $this->text_align( $b ) ) { $set['_flex_align_self'] = 'center'; }
		}
	}

	/* ------------------------------------------------------------------ *
	 * Blocks
	 * ------------------------------------------------------------------ */

	private function block( array $b ) {
		// FIRST RUNG: the Unyson+ element itself, as an Elementor widget. It renders through the same view as
		// the page builder, so it is exact — and it is still a native, editable Elementor widget.
		$key = ( 'unknown' === ( $b['type'] ?? '' ) ) ? (string) ( $b['source_type'] ?? '' ) : (string) ( $b['type'] ?? '' );
		$w   = $this->native( $key );
		if ( $w ) {
			$this->trace['native']++;
			return $this->widget( (string) $w['widget'], (array) $w['settings'], $b );
		}
		switch ( $b['type'] ?? '' ) {
			case 'heading':      return $this->heading( $b );
			case 'text':         return $this->text( $b );
			case 'button':       return $this->button( $b );
			case 'image':        return $this->image( $b );
			case 'row':          return $this->row( $b );
			case 'stack':        return $this->stack( $b );
			case 'box':          return $this->box( $b );
			case 'icon':         return $this->html_widget( $b['svg'] ?? '', $b, 'icon' );
			case 'badge':        return $this->badge( $b );
			case 'chips':        return $this->chips( $b );
			case 'video':        return $this->video( $b );
			case 'image_box':    return $this->image_box( $b );
			case 'list':         return $this->icon_list( $b );
			case 'testimonials': return $this->testimonials( $b );
			case 'accordion':    return $this->accordion( $b );
			case 'steps':        return $this->steps( $b );
			case 'logos':        return $this->logos( $b );
			case 'avatars':      return $this->avatars( $b );
			case 'card':         return $this->card( $b );
			case 'heading_link': return $this->heading_link( $b );
			case 'decor':        $this->trace['skipped']++; return null; // a decorative paint layer
			case 'html':         return $this->leaf_row( (string) ( $b['html'] ?? '' ), $b ) ?: $this->html_widget( $b['html'] ?? '', $b, 'html' );
		}
		return $this->unknown( $b );
	}

	private function heading( array $b ) {
		$set = array(
			'title'       => '' !== (string) $b['html'] ? (string) $b['html'] : esc_html( (string) $b['text'] ),
			'header_size' => 'h' . (int) $b['level'],
		);
		$this->typography( $set, 'typography', $b['style'], $b['style_sm'], 'heading' );
		$this->color( $set, 'title_color', $b['style']['color'] ?? '' );
		$this->align( $set, 'align', $this->text_align( $b ) );
		$this->gradtext( $set['title'] );
		$this->clip_gradient( $set, (array) $b['style'], '.elementor-heading-title' );
		$this->trace['native']++;
		return $this->widget( 'heading', $set, $b );
	}

	/**
	 * A block that is ITSELF gradient-clipped text (a transparent colour over a gradient background clipped to
	 * the glyphs). Elementor's colour control cannot express it, so the widget gets a class whose page rule
	 * carries the gradient and the clip; without it the widget fell to the kit's global heading colour.
	 */
	private function clip_gradient( array &$set, array $st, $inner ) {
		$img  = (string) ( $st['background-image'] ?? '' );
		$clip = (string) ( $st['-webkit-background-clip'] ?? ( $st['background-clip'] ?? '' ) );
		if ( false === stripos( $img, 'gradient' ) || 'text' !== trim( $clip ) ) { return; }
		$cls = $this->cls( 'gtext' );
		$set['_css_classes'] = trim( ( $set['_css_classes'] ?? '' ) . ' ' . $cls );
		$this->css[] = '.' . $cls . ' ' . $inner . '{background-image:' . $img . ';-webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent;color:transparent}';
	}

	/**
	 * Gradient-filled text arrives as `<span class="sc-gradtext" style="background-image:…">`; the clip that
	 * turns the gradient into a text fill is the global `.sc-gradtext` rule, which ships in a page-builder
	 * stylesheet an Elementor page never loads. Carry the rule on the page once, when it is used.
	 */
	private function gradtext( $html ) {
		if ( false === strpos( (string) $html, 'sc-gradtext' ) || isset( $this->css['gradtext'] ) ) { return; }
		$this->css['gradtext'] = '.sc-gradtext{-webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent;color:transparent}';
	}

	private function text( array $b ) {
		$html = '' !== (string) $b['html'] ? (string) $b['html'] : '<p>' . esc_html( (string) $b['text'] ) . '</p>';
		$set  = array( 'editor' => $html );
		$this->gradtext( $html );
		$this->typography( $set, 'typography', $b['style'], $b['style_sm'], 'body' );
		$this->color( $set, 'text_color', $b['style']['color'] ?? '' );
		$this->color( $set, 'link_color', $b['link_style']['color'] ?? '' );
		$this->align( $set, 'align', $this->text_align( $b ) );
		$cls = $this->cls( 'txt' );
		$set['_css_classes'] = trim( ( $set['_css_classes'] ?? '' ) . ' ' . $cls );
		$this->clip_gradient( $set, (array) $b['style'], '.elementor-widget-container' );
		$this->css[] = '.' . $cls . ' p:last-child{margin-bottom:0}';
		$this->trace['native']++;
		return $this->widget( 'text-editor', $set, $b );
	}

	private function button( array $b ) {
		$st  = (array) $b['style'];
		$set = array(
			'text' => (string) $b['label'],
			'link' => array( 'url' => (string) $b['href'], 'is_external' => '', 'nofollow' => '', 'custom_attributes' => '' ),
		);
		$this->typography( $set, 'typography', $st, array(), 'body' );
		$this->color( $set, 'button_text_color', $st['color'] ?? '' );
		// Every property is SET, never left to default: an unset button property falls through to the Site
		// Kit's global button style, which the design sync fills from the theme's primary button — so an
		// outline button would come out filled.
		$bg = $st['background-color'] ?? '';
		$set['background_background'] = 'classic';
		if ( '' !== $bg && ! FW_SC_Style::is_transparent( $bg ) ) {
			$this->color( $set, 'background_color', $bg );
		} else {
			$set['background_color'] = 'transparent';
		}
		if ( ! empty( $st['background-image'] ) && false !== stripos( $st['background-image'], 'gradient' ) ) {
			$set['_css_classes'] = $cls = $this->cls( 'btn' );
			$this->css[] = '.' . $cls . ' .elementor-button{background-image:' . $st['background-image'] . '}';
		}
		$pad = FW_SC_Style::box( $st['padding'] ?? '' );
		if ( $pad ) { $set['text_padding'] = $this->dims( $pad ); }
		// A measured radius is always written, a zero one included: an unset button radius falls through to the kit's.
		$rv = (string) ( $st['border-radius'] ?? ( $st['border-top-left-radius'] ?? '' ) );
		if ( '' !== $rv ) { $set['border_radius'] = $this->radius( $rv ) ?: $this->dims( array( 0, 0, 0, 0 ) ); }
		$bw = FW_SC_Style::px( $st['border-width'] ?? ( $st['border-top-width'] ?? '' ) );
		$bs = strtolower( (string) ( $st['border-style'] ?? ( $st['border-top-style'] ?? 'solid' ) ) );
		if ( $bw && 'none' !== $bs ) {
			$set['border_border'] = in_array( $bs, array( 'solid', 'dashed', 'dotted', 'double' ), true ) ? $bs : 'solid';
			$set['border_width']  = $this->dims( array( $bw, $bw, $bw, $bw ) );
			$this->color( $set, 'border_color', $st['border-color'] ?? ( $st['border-top-color'] ?? '' ) );
		} else {
			$set['border_border'] = 'none';
		}
		if ( ! isset( $set['border_radius'] ) ) { $set['border_radius'] = $this->dims( array( 0, 0, 0, 0 ) ); } // unmeasured → explicit 0, never the kit's
		$hover = FW_SC_Style::parse( preg_replace( '/^[^{]*\{|\}[^}]*$/', '', (string) $b['hover_css'] ) );
		if ( ! empty( $hover['background-color'] ) ) {
			$set['button_background_hover_background'] = 'classic';
			$this->color( $set, 'button_background_hover_color', $hover['background-color'] );
		}
		if ( ! empty( $hover['color'] ) ) { $this->color( $set, 'hover_color', $hover['color'] ); }
		$a = $this->text_align( $b );
		if ( 'center' === $a ) { $set['align'] = 'center'; } elseif ( 'end' === $a ) { $set['align'] = 'right'; }
		if ( '' !== (string) $b['icon_svg'] ) { $this->fallbacks[] = 'button-icon'; } // an inline SVG icon needs a media upload; v1 drops it
		$this->trace['native']++;
		return $this->widget( 'button', $set, $b );
	}

	private function image( array $b ) {
		$src = (string) ( $b['src'] ?? '' );
		if ( '' === $src ) { return $this->html_widget( $b['html'] ?? '', $b, 'image' ); }
		$m   = $this->media( $src, (string) $b['alt'] );
		$set = array( 'image' => array( 'url' => $m['url'], 'id' => $m['id'], 'alt' => (string) $b['alt'], 'source' => 'library' ), 'image_size' => 'full' );
		if ( '' !== (string) $b['css'] ) {
			$cls = $this->cls( 'img' );
			$set['_css_classes'] = trim( ( $set['_css_classes'] ?? '' ) . ' ' . $cls );
			$this->css[] = str_replace( 'selector', '.' . $cls, (string) $b['css'] );
		}
		$this->trace['native']++;
		return $this->widget( 'image', $set, $b );
	}

	private function row( array $b ) {
		$set = $this->container_base( 'row' );
		$set['flex_gap']            = $this->gap( (float) $b['gap'] );
		$set['flex_wrap']           = 'nowrap';
		$set['flex_direction_mobile'] = 'column';
		if ( 'lg' === ( $b['stack_bp'] ?? '' ) ) { $set['flex_direction_tablet'] = 'column'; }
		$va = array( 'center' => 'center', 'middle' => 'center', 'bottom' => 'flex-end', 'end' => 'flex-end', 'top' => 'flex-start', 'start' => 'flex-start', 'stretch' => 'stretch' );
		if ( isset( $va[ $b['valign'] ?? '' ] ) ) { $set['flex_align_items'] = $va[ $b['valign'] ]; }
		$ju = array( 'space-between' => 'space-between', 'between' => 'space-between', 'center' => 'center', 'end' => 'flex-end', 'flex-end' => 'flex-end', 'right' => 'flex-end', 'space-around' => 'space-around', 'space-evenly' => 'space-evenly' );
		if ( isset( $ju[ $b['justify'] ?? '' ] ) ) { $set['flex_justify_content'] = $ju[ $b['justify'] ]; }
		$cols = array();
		$gap  = (float) $b['gap'];
		// Spans that add up past 12 are a grid that wraps (eight span-3 cards = two rows of four), or a
		// device-specific twin that the source hides on desktop. Either way the row must wrap, and each
		// column's gap share is counted per visual row, not across all of them.
		$sum = 0;
		foreach ( (array) $b['columns'] as $c ) { if ( empty( $c['hidden']['desktop'] ) ) { $sum += max( 1, min( 12, (int) $c['span'] ) ); } }
		if ( $sum > 12 ) { $set['flex_wrap'] = 'wrap'; }
		$tracks = (int) ( $b['tracks'] ?? 0 );
		if ( $tracks > 0 ) { $set['flex_wrap'] = 'nowrap'; }
		foreach ( (array) $b['columns'] as $c ) {
			$cs = $this->container_base( 'column' );
			$span = max( 1, min( 12, (int) $c['span'] ) );
			// Each column takes its share of the row minus its share of the gaps, so a row of columns + gaps fits.
			$per_row = max( 1, (int) round( 12 / $span ) );
			$share   = $span / 12;
			$cs['width']        = array( 'unit' => 'custom', 'size' => sprintf( 'calc(%s%% - %spx)', round( $share * 100, 4 ), round( $gap * ( $per_row - 1 ) * $share, 2 ) ), 'sizes' => array() );
			if ( $tracks > 0 ) {
				// N equal tracks on one line: a 1/N share minus its share of the N-1 gaps.
				$cs['width'] = array( 'unit' => 'custom', 'size' => sprintf( 'calc(%s%% - %spx)', round( 100 / $tracks, 4 ), round( $gap * ( $tracks - 1 ) / $tracks, 2 ) ), 'sizes' => array() );
			}
			if ( ! empty( $b['content_sized'] ) ) {
				// Content-sized items (a CTA beside a stat, a heading beside an intro): each hugs its content and may
				// shrink so a long heading wraps; a cell with a width cap holds it (the uncapped cells absorb the shrink).
				$cs['width']      = array( 'unit' => 'custom', 'size' => 'auto', 'sizes' => array() );
				$cs['_flex_size'] = 'shrink';
				$cap = FW_SC_Style::px( $c['max_width'] ?? '' );
				if ( $cap ) {
					// The cap IS the cell's width here (a boxed inner cap left the cell at its text's one-line width,
					// which squeezed the heading beside it to a sliver).
					$cs['width']      = array( 'unit' => 'custom', 'size' => 'min(100%, ' . round( $cap ) . 'px)', 'sizes' => array() );
					$cs['_flex_size'] = 'none';
					$c['max_width']   = '';
				}
			}
			$cs['width_tablet'] = ( 'lg' === ( $b['stack_bp'] ?? '' ) ) ? $this->size( 100, '%' ) : $cs['width'];
			$cs['width_mobile'] = $this->size( 100, '%' );
			$mw = FW_SC_Style::px( $c['max_width'] ?? '' );
			if ( $mw ) { $cs['content_width'] = 'boxed'; $cs['boxed_width'] = $this->size( $mw ); }
			foreach ( array( 'desktop', 'tablet', 'mobile' ) as $dev ) {
				if ( ! empty( $c['hidden'][ $dev ] ) ) { $cs[ 'hide_' . $dev ] = 'hidden-' . $dev; }
			}
			$cols[] = $this->column( $cs, $c );
		}
		$this->apply_container_margin( $set, $b );
		$this->trace['native']++;
		return $this->container( $set, $cols );
	}

	/** The block's own Unyson+ element as a widget, when the resolver has one: { widget, settings } or null. */
	private function native( $key ) {
		if ( '' === (string) $key || ! is_callable( $this->opts['native'] ) ) { return null; }
		$w = call_user_func( $this->opts['native'], (string) $key );
		return ( is_array( $w ) && ! empty( $w['widget'] ) ) ? $w : null;
	}

	/** A column's contents: one counter, one card, or its own verbatim markup, or its blocks. */
	private function column( array $cs, array $c ) {
		// A Unyson+ widget draws its own card (its box preset), so the column's skin applies only to the
		// fallback rungs — otherwise the card would be boxed twice.
		$kind = ! empty( $c['counter'] ) ? 'counter' : ( ! empty( $c['card'] ) ? 'card' : '' );
		if ( '' !== $kind ) {
			$w = $this->native( $kind );
			if ( $w ) {
				$this->trace['native']++;
				$ws = (array) $w['settings'];
				// The widget paints a card only through its own box style / background; when the native build put
				// the skin on the surrounding column instead, the column keeps it.
				if ( ! empty( $c['box'] ) && '' === (string) ( $ws['box_style'] ?? '' ) && '' === (string) ( $ws['bg_color'] ?? '' ) && '' === (string) ( $ws['bg_color__custom'] ?? '' ) ) {
					$this->card_skin( $cs, (array) $c['box'] );
				}
				$kids = array( $this->widget( (string) $w['widget'], $ws ) );
				$cta  = 'card' === $kind ? $this->card_button( (array) $c['card'] ) : null;
				if ( $cta ) { $kids[] = $cta; }
				return $this->container( $cs, $kids );
			}
		}
		// A raw-HTML column that converts to a card wearing its OWN skin (leaf_row) takes no column skin on top —
		// the mapping's card box describes the same element, and painting both boxed the card twice.
		$leaf = ( '' === $kind && '' !== trim( (string) ( $c['html'] ?? '' ) ) && empty( $c['blocks'] ) ) ? $this->leaf_row( (string) $c['html'], array() ) : null;
		$self_skinned = $leaf && false !== strpos( (string) ( $leaf['settings']['css_classes'] ?? '' ), 'upw-leafbox' );
		if ( ! empty( $c['box'] ) && ! $self_skinned ) { $this->card_skin( $cs, (array) $c['box'] ); }
		if ( $leaf ) { return $this->container( $cs, array( $leaf ) ); }
		if ( 'counter' === $kind ) { return $this->counter_column( $cs, (array) $c['counter'] ); }
		if ( 'card' === $kind ) { return $this->card_column( $cs, $c['card'] ); }
		if ( '' !== trim( (string) ( $c['html'] ?? '' ) ) && empty( $c['blocks'] ) ) {
			return $this->container( $cs, array_values( array_filter( array( $this->html_widget( $c['html'], array(), 'column-html' ) ) ) ) );
		}
		return $this->container( $cs, $this->children( (array) $c['blocks'] ) );
	}

	/** A stat cell as Elementor's own counter widget (counts up on scroll, like the source's). */
	private function counter_column( array $cs, array $k ) {
		$num = (string) $k['number'];
		$set = array(
			'starting_number'   => 0,
			'ending_number'     => is_numeric( $num ) ? 0 + $num : 0,
			'prefix'            => (string) $k['prefix'],
			'suffix'            => (string) $k['suffix'],
			'title'             => (string) $k['label'],
			'thousand_separator' => '',
		);
		if ( ! is_numeric( $num ) ) { $set['prefix'] = (string) $k['prefix'] . $num; } // a non-numeric figure ("24/7") stays text
		$this->typography( $set, 'typography_number', (array) $k['number_style'], array(), 'label' );
		$this->color( $set, 'number_color', $k['number_style']['color'] ?? '' );
		$this->typography( $set, 'typography_title', (array) $k['label_style'], array(), 'label' );
		$this->color( $set, 'title_color', $k['label_style']['color'] ?? '' );
		if ( in_array( $k['align'], array( 'center', 'left', 'right' ), true ) ) { $set['title_position'] = ! empty( $k['label_first'] ) ? 'before' : 'after'; }
		$this->trace['native']++;
		return $this->container( $cs, array( $this->widget( 'counter', $set ) ) );
	}

	/** A column that IS a card: image / icon, overline, title, text, link — over the card's measured box. */
	private function card_column( array $cs, array $k ) {
		$st = (array) $k['style'];
		$bg = $st['background-color'] ?? '';
		if ( '' !== $bg && ! FW_SC_Style::is_transparent( $bg ) ) { $cs['background_background'] = 'classic'; $this->color( $cs, 'background_color', $bg ); }
		$pad = FW_SC_Style::box( $st['padding'] ?? '' );
		if ( $pad ) { $cs['padding'] = $this->dims( $pad ); }
		$rad = $this->radius( $st['border-radius'] ?? '' );
		if ( $rad ) { $cs['border_radius'] = $rad; }
		if ( ! empty( $st['box-shadow'] ) && 'none' !== $st['box-shadow'] ) {
			$cls = $this->cls( 'card' );
			$cs['css_classes'] = trim( ( $cs['css_classes'] ?? '' ) . ' ' . $cls );
			$this->css[] = '.' . $cls . '{box-shadow:' . $st['box-shadow'] . '}';
		}
		if ( ! empty( $k['center'] ) ) { $cs['flex_align_items'] = 'center'; }
		$kids  = array();
		$align = ! empty( $k['center'] ) ? 'center' : '';
		if ( '' !== (string) $k['image'] ) {
			$m  = $this->media( (string) $k['image'], wp_strip_all_tags( (string) $k['title'] ) );
			$is = array( 'image' => array( 'url' => $m['url'], 'id' => $m['id'], 'alt' => wp_strip_all_tags( (string) $k['title'] ), 'source' => 'library' ), 'image_size' => 'full' );
			if ( $k['image_height'] ) { $is['height'] = $this->size( (float) $k['image_height'] ); $is['object-fit'] = 'contain'; }
			if ( '' !== $align ) { $is['align'] = 'center'; }
			$is['_margin'] = $this->dims( array( 0, 0, 16, 0 ) );
			$kids[] = $this->widget( 'image', $is );
		} elseif ( '' !== (string) $k['icon_svg'] ) {
			$kids[] = $this->html_widget( $k['icon_svg'], array(), 'card-icon', false );
		}
		if ( '' !== (string) $k['overline']['text'] ) {
			$os = array( 'title' => esc_html( (string) $k['overline']['text'] ), 'header_size' => 'div' );
			$this->typography( $os, 'typography', (array) $k['overline']['style'], array(), 'label' );
			$this->color( $os, 'title_color', $k['overline']['style']['color'] ?? '' );
			$this->align( $os, 'align', $align );
			$kids[] = $this->widget( 'heading', $os );
		}
		if ( '' !== (string) $k['title'] ) {
			$ts = array( 'title' => wp_kses_post( (string) $k['title'] ), 'header_size' => $k['title_tag'] );
			$this->typography( $ts, 'typography', (array) $k['title_style'], array(), 'heading' );
			$this->color( $ts, 'title_color', $k['title_style']['color'] ?? '' );
			$this->align( $ts, 'align', $align );
			if ( '' !== (string) $k['link']['href'] ) { $ts['link'] = array( 'url' => (string) $k['link']['href'], 'is_external' => '', 'nofollow' => '' ); }
			$kids[] = $this->widget( 'heading', $ts );
		}
		if ( '' !== trim( wp_strip_all_tags( (string) $k['text'] ) ) ) {
			$xs = array( 'editor' => wp_kses_post( (string) $k['text'] ) );
			$this->typography( $xs, 'typography', (array) $k['text_style'], array(), 'body' );
			$this->color( $xs, 'text_color', $k['text_style']['color'] ?? '' );
			$this->align( $xs, 'align', $align );
			$kids[] = $this->widget( 'text-editor', $xs );
		}
		// The card's call to action: a styled button when the card has one, else its link — unless the label is
		// just the title again (the title already carries the link).
		$cta = $this->card_button( $k );
		$lbl = trim( wp_strip_all_tags( (string) $k['link']['label'] ) );
		if ( $cta ) {
			$kids[] = $cta;
		} elseif ( '' !== $lbl && '' !== (string) $k['link']['href'] && 0 !== strcasecmp( $lbl, trim( wp_strip_all_tags( (string) $k['title'] ) ) ) ) {
			$kids[] = $this->widget( 'text-editor', array( 'editor' => '<p><a href="' . esc_url( $k['link']['href'] ) . '">' . esc_html( $k['link']['label'] ) . '</a></p>' ) );
		}
		$this->trace['composed']++;
		return $this->container( $cs, $kids );
	}

	/** A section heading with a link beside it: one row, heading left, link right, bottom-aligned. */
	private function heading_link( array $b ) {
		$row = $this->container_base( 'row' );
		$row['flex_justify_content'] = 'space-between';
		$row['flex_align_items']     = 'flex-end';
		$row['flex_wrap']            = 'wrap';
		$row['flex_gap']             = $this->gap( 16 );
		$h  = (array) $b['heading'];
		$hs = array( 'title' => wp_kses_post( (string) $h['html'] ), 'header_size' => 'h' . (int) $h['level'] );
		$this->typography( $hs, 'typography', (array) $h['style'], array(), 'heading' );
		$this->color( $hs, 'title_color', $h['style']['color'] ?? '' );
		$this->gradtext( $hs['title'] );
		$kids = array( $this->widget( 'heading', $hs ) );
		// An overline / subtitle belongs with the heading, on the left, as one column.
		if ( '' !== trim( wp_strip_all_tags( (string) $h['overline'] . (string) $h['subtitle'] ) ) ) {
			$col = array();
			if ( '' !== trim( (string) $h['overline'] ) ) { $col[] = $this->widget( 'text-editor', array( 'editor' => '<p>' . wp_kses_post( (string) $h['overline'] ) . '</p>' ) ); }
			$col[] = $kids[0];
			if ( '' !== trim( (string) $h['subtitle'] ) ) {
				$ss = array( 'editor' => '<p>' . wp_kses_post( (string) $h['subtitle'] ) . '</p>', '_margin' => $this->dims( array( 12, 0, 0, 0 ) ) );
				$sst = (array) ( $h['subtitle_style'] ?? array() );
				$this->typography( $ss, 'typography', $sst, array(), 'body' );
				$this->color( $ss, 'text_color', $sst['color'] ?? '' );
				$smw = FW_SC_Style::px( $sst['max-width'] ?? '' );
				if ( $smw ) { $ss['_element_width'] = 'initial'; $ss['_element_custom_width'] = $this->size( $smw ); }
				$col[] = $this->widget( 'text-editor', $ss );
			}
			$cs = $this->container_base( 'column' );
			$cs['_element_width'] = 'initial';
			$kids = array( $this->container( $cs, $col ) );
		}
		$l = (array) $b['link'];
		if ( '' !== (string) $l['label'] ) {
			$ls = array( 'title' => esc_html( (string) $l['label'] ), 'header_size' => 'div' );
			if ( '' !== (string) $l['href'] ) { $ls['link'] = array( 'url' => (string) $l['href'], 'is_external' => '', 'nofollow' => '' ); }
			$this->typography( $ls, 'typography', (array) $l['style'], array(), 'label' );
			$this->color( $ls, 'title_color', $l['style']['color'] ?? '' );
			$kids[] = $this->widget( 'heading', $ls );
		}
		$this->apply_container_margin( $row, $b );
		$this->trace['composed']++;
		return $this->container( $row, $kids );
	}

	private function stack( array $b ) {
		$set = $this->container_base( 'column' );
		$set['flex_gap'] = $this->gap( (float) $b['gap'] );
		$this->apply_container_margin( $set, $b );
		$this->trace['native']++;
		return $this->container( $set, $this->children( (array) $b['blocks'] ) );
	}

	private function box( array $b ) {
		$set = $this->container_base( 'column' );
		$box = (array) $b['box'];
		if ( '' !== $box['background'] && ! FW_SC_Style::is_transparent( $box['background'] ) ) {
			$set['background_background'] = 'classic';
			$this->color( $set, 'background_color', $box['background'] );
		}
		$rad = $this->radius( $box['radius'] );
		if ( $rad ) { $set['border_radius'] = $rad; }
		$bw = FW_SC_Style::px( $box['border_width'] );
		if ( $bw ) {
			$set['border_border'] = 'solid';
			$set['border_width']  = $this->dims( array( $bw, $bw, $bw, $bw ) );
			$this->color( $set, 'border_color', $box['border_color'] );
		}
		$pad = (array) $b['padding'];
		foreach ( array( '' => 'lg', '_tablet' => 'md', '_mobile' => 'base' ) as $sfx => $bp ) {
			if ( isset( $pad[ $bp ] ) && is_array( $pad[ $bp ] ) ) {
				$p = $pad[ $bp ];
				$set[ 'padding' . $sfx ] = $this->dims( array( (float) ( $p['top'] ?? 0 ), (float) ( $p['right'] ?? 0 ), (float) ( $p['bottom'] ?? 0 ), (float) ( $p['left'] ?? 0 ) ) );
			}
		}
		$extra = array();
		if ( '' !== $box['gradient'] ) { $extra[] = 'background-image:' . $box['gradient']; }
		if ( '' !== $box['shadow'] ) { $extra[] = 'box-shadow:' . $box['shadow']; }
		if ( '' !== $box['backdrop'] ) { $extra[] = 'backdrop-filter:' . $box['backdrop'] . ';-webkit-backdrop-filter:' . $box['backdrop']; }
		if ( '' !== $box['extra_css'] ) { $extra[] = rtrim( $box['extra_css'], ';' ); }
		if ( $extra ) {
			$cls = $this->cls( 'box' );
			$set['css_classes'] = trim( ( $set['css_classes'] ?? '' ) . ' ' . $cls );
			$this->css[] = '.' . $cls . '{' . implode( ';', $extra ) . '}';
		}
		if ( 'center' === ( $b['align'] ?? '' ) ) { $set['flex_align_items'] = 'center'; }
		$mw = FW_SC_Style::px( $b['max_width'] ?? '' );
		if ( $mw ) { $set['content_width'] = 'boxed'; $set['boxed_width'] = $this->size( $mw ); }
		$this->apply_container_margin( $set, $b );
		$this->trace['native']++;
		return $this->container( $set, $this->children( (array) $b['blocks'] ) );
	}

	private function apply_container_margin( array &$set, array $b ) {
		$top = (float) ( $b['margin']['top'] ?? 0 );
		$bot = (float) ( $b['margin']['bottom'] ?? 0 );
		if ( $top || $bot ) { $set['margin'] = $this->dims( array( $top, 0, $bot, 0 ) ); }
	}

	/** A pill label: a heading-widget span with its own background and padding. */
	private function badge( array $b ) {
		$st   = (array) $b['style'];
		$text = (string) $b['text'];
		$icon = (string) $b['icon_svg'];
		$set  = array( 'title' => ( '' !== $icon ? $this->svg_inline( $icon, '1em' ) . ' ' : '' ) . esc_html( $text ), 'header_size' => 'span' );
		$this->typography( $set, 'typography', (array) $b['text_style'] ?: $st, array(), 'label' );
		$this->color( $set, 'title_color', ( $b['text_style']['color'] ?? '' ) ?: ( $st['color'] ?? '' ) );
		$bg = $st['background-color'] ?? '';
		if ( '' !== $bg && ! FW_SC_Style::is_transparent( $bg ) ) {
			$set['_background_background'] = 'classic';
			$this->color( $set, '_background_color', $bg );
		}
		$pad = FW_SC_Style::box( $st['padding'] ?? '' );
		if ( $pad ) { $set['_padding'] = $this->dims( $pad ); }
		$rad = $this->radius( $st['border-radius'] ?? '' );
		if ( $rad ) { $set['_border_radius'] = $rad; }
		// A pill's hairline: the capture records one side (`border-top-*`) for a uniform border.
		$bw = FW_SC_Style::px( $st['border-width'] ?? ( $st['border-top-width'] ?? '' ) );
		$bc = (string) ( $st['border-color'] ?? ( $st['border-top-color'] ?? '' ) );
		if ( $bw && 'none' !== ( $st['border-top-style'] ?? 'solid' ) && '' !== $bc && ! FW_SC_Style::is_transparent( $bc ) ) {
			$set['_border_border'] = 'solid';
			$set['_border_width']  = $this->dims( array( $bw, $bw, $bw, $bw ) );
			$this->color( $set, '_border_color', $bc );
		}
		$set['_element_width'] = 'auto';
		$a = $this->text_align( $b );
		if ( 'center' === $a ) { $set['_flex_align_self'] = 'center'; }
		$cls = $this->cls( 'badge' );
		$set['_css_classes'] = trim( ( $set['_css_classes'] ?? '' ) . ' ' . $cls );
		$this->css[] = '.' . $cls . ' .elementor-heading-title{display:inline-flex;align-items:center;gap:.5em}';
		$this->trace['composed']++;
		return $this->widget( 'heading', $set, $b );
	}

	/**
	 * A photo card. With an overlay the photo is the card's cover background, the scrim is layered over it and the
	 * caption (title, text, button) is placed where the source puts it; the card fills its column's height. Without
	 * one it is an image above the card's content.
	 */
	private function image_box( array $b ) {
		$k  = (array) $b['card'];
		$ov = $b['overlay'] ?? null;
		if ( ! is_array( $ov ) || '' === (string) $k['image'] ) {
			return $this->card_column( $this->container_base( 'column' ), $k );
		}
		$set = $this->container_base( 'column' );
		$m   = $this->media( (string) $k['image'], wp_strip_all_tags( (string) $k['title'] ) );
		$set['background_background'] = 'classic';
		$set['background_image']      = array( 'url' => $m['url'], 'id' => $m['id'], 'source' => 'library' );
		$set['background_size']       = 'cover';
		$set['background_position']   = 'center center';
		$set['background_repeat']     = 'no-repeat';
		$place = array( 'bottom' => 'flex-end', 'top' => 'flex-start', 'center' => 'center', 'middle' => 'center' );
		$set['flex_justify_content'] = $place[ $ov['place'] ] ?? 'flex-end';
		if ( 'center' === $ov['align'] ) { $set['flex_align_items'] = 'center'; } elseif ( 'right' === $ov['align'] ) { $set['flex_align_items'] = 'flex-end'; }
		$in = (float) $ov['inset'];
		if ( $in > 0 ) { $set['padding'] = $this->dims( array( $in, $in, $in, $in ) ); }
		$set['min_height'] = $this->size( 280 );
		$cls = $this->cls( 'ibox' );
		$set['css_classes'] = trim( ( $set['css_classes'] ?? '' ) . ' ' . $cls . ' e-no-lazyload' );
		$css = '.' . $cls . '{position:relative;overflow:hidden;height:100%}';
		$rad = (string) ( $k['style']['border-radius'] ?? '' );
		if ( '' !== $rad && '0px' !== $rad ) { $css .= '.' . $cls . '{border-radius:' . $rad . '}'; }
		if ( '' !== (string) $ov['scrim'] ) { $css .= '.' . $cls . '::before{content:"";position:absolute;inset:0;pointer-events:none;background:' . $ov['scrim'] . '}.' . $cls . '>*{position:relative;z-index:1}'; }
		$this->css[] = $css;
		$kids = array();
		if ( '' !== (string) $k['title'] ) {
			$ts = array( 'title' => wp_kses_post( (string) $k['title'] ), 'header_size' => $k['title_tag'] );
			$this->typography( $ts, 'typography', (array) $k['title_style'], array(), 'heading' );
			$this->color( $ts, 'title_color', $k['title_style']['color'] ?? '' );
			$kids[] = $this->widget( 'heading', $ts );
		}
		if ( '' !== trim( wp_strip_all_tags( (string) $k['text'] ) ) ) {
			$xs = array( 'editor' => wp_kses_post( (string) $k['text'] ) );
			$this->typography( $xs, 'typography', (array) $k['text_style'], array(), 'body' );
			$this->color( $xs, 'text_color', $k['text_style']['color'] ?? '' );
			$kids[] = $this->widget( 'text-editor', $xs );
		}
		$cta = $this->card_button( $k );
		if ( $cta ) { $cta['settings']['_element_width'] = 'auto'; $kids[] = $cta; }
		$this->apply_container_margin( $set, $b );
		$this->trace['composed']++;
		return $this->container( $set, $kids );
	}

	/** An inline video as Elementor's hosted Video widget, with its card skin (`selector{…}`) and measured height. */
	private function video( array $b ) {
		if ( '' === (string) $b['src'] ) { $this->trace['skipped']++; return null; }
		$set = array(
			'video_type'     => 'hosted',
			'hosted_url'     => array( 'url' => (string) $b['src'], 'id' => '' ),
			'autoplay'       => ! empty( $b['autoplay'] ) ? 'yes' : '',
			'mute'           => ! empty( $b['muted'] ) ? 'yes' : '',
			'loop'           => ! empty( $b['loop'] ) ? 'yes' : '',
			'controls'       => ! empty( $b['controls'] ) ? 'yes' : '',
			'play_on_mobile' => 'yes',
		);
		if ( '' !== (string) $b['poster'] ) { $m = $this->media( (string) $b['poster'] ); $set['image_overlay'] = array( 'url' => $m['url'], 'id' => $m['id'] ); }
		$cls = $this->cls( 'video' );
		$set['_css_classes'] = trim( ( $set['_css_classes'] ?? '' ) . ' ' . $cls );
		$css = '';
		if ( (float) $b['height'] > 0 ) {
			$css .= '.' . $cls . ' .elementor-wrapper{height:' . round( (float) $b['height'] ) . 'px;aspect-ratio:auto}.' . $cls . ' video{width:100%;height:100%;object-fit:' . ( ! empty( $b['cover'] ) ? 'cover' : 'contain' ) . '}';
		}
		// The card skin goes on the player's own wrapper: the widget container is not rendered by every Elementor version.
		if ( preg_match( '/selector\s*\{([^}]*)\}/', (string) $b['card_css'], $cm ) ) { $css .= '.' . $cls . ' .elementor-wrapper{' . trim( $cm[1] ) . '}'; }
		if ( '' !== $css ) { $this->css[] = $css; }
		$this->trace['native']++;
		return $this->widget( 'video', $set, $b );
	}

	/** A row of pills: a wrapping row container of badge widgets, aligned like the source row. */
	private function chips( array $b ) {
		$kids = array();
		foreach ( (array) $b['items'] as $it ) {
			$kids[] = $this->badge( array( 'text' => (string) $it['text'], 'icon_svg' => (string) $it['icon_svg'], 'href' => '', 'align' => '',
				'style' => (array) $it['style'], 'text_style' => array(), 'style_sm' => array(), 'margin' => array() ) );
		}
		if ( ! $kids ) { $this->trace['skipped']++; return null; }
		$set = $this->container_base( 'row' );
		$set['flex_wrap'] = 'wrap';
		$set['flex_gap']  = $this->gap( (float) $b['gap'] );
		$set['flex_align_items'] = 'center';
		$a = (string) $b['align'];
		if ( in_array( $a, array( 'end', 'right', 'flex-end' ), true ) ) { $set['flex_justify_content'] = 'flex-end'; }
		elseif ( 'center' === $a ) { $set['flex_justify_content'] = 'center'; }
		$this->apply_container_margin( $set, $b );
		$this->trace['composed']++;
		return $this->container( $set, $kids );
	}

	private function icon_list( array $b ) {
		$items = array();
		$icol  = '';
		foreach ( (array) $b['items'] as $it ) {
			$items[] = array( '_id' => $this->id(), 'text' => (string) $it['text'],
				'selected_icon' => array( 'value' => ! empty( $b['ordered'] ) ? 'fas fa-angle-right' : 'fas fa-check-circle', 'library' => 'fa-solid' ) );
			if ( '' === $icol && ! empty( $it['icon_style']['color'] ) ) { $icol = $it['icon_style']['color']; }
		}
		$set = array( 'icon_list' => $items );
		if ( $b['item_gap'] ) { $set['space_between'] = $this->size( (float) $b['item_gap'] ); }
		$this->color( $set, 'icon_color', $icol );
		$lab = (array) $b['label_style'] ?: ( (array) ( $b['list_style'] ?? array() ) ?: (array) $b['style'] );
		$tc  = (string) ( $b['label_style']['color'] ?? '' ) ?: (string) ( $b['list_style']['color'] ?? '' ) ?: (string) ( $b['style']['color'] ?? '' );
		// No measured colour: inherit the section's, rather than fall to Elementor's global text colour.
		if ( '' !== $tc ) { $this->color( $set, 'text_color', $tc ); } else { $set['text_color'] = 'inherit'; }
		$this->typography( $set, 'icon_typography', $lab, array(), 'body' );
		$this->trace['native']++;
		return $this->widget( 'icon-list', $set, $b );
	}

	private function testimonials( array $b ) {
		$grid = array(
			'container_type'      => 'grid',
			'content_width'       => 'full',
			'grid_columns_grid'   => array( 'unit' => 'fr', 'size' => max( 1, (int) $b['columns'] ), 'sizes' => array() ),
			'grid_columns_grid_mobile' => array( 'unit' => 'fr', 'size' => 1, 'sizes' => array() ),
			'grid_gaps'           => array( 'column' => (string) (float) $b['gap'], 'row' => (string) (float) $b['gap'], 'unit' => 'px', 'isLinked' => true ),
			'padding'             => $this->dims( array( 0, 0, 0, 0 ) ),
		);
		$cards = array();
		foreach ( (array) $b['items'] as $it ) {
			$set = array(
				'testimonial_content' => wp_kses_post( (string) $it['quote'] ),
				'testimonial_name'    => (string) $it['name'],
				'testimonial_job'     => (string) $it['role'],
				'testimonial_alignment' => 'left',
			);
			if ( '' !== (string) $it['image'] ) {
				$m = $this->media( (string) $it['image'] );
				$set['testimonial_image'] = array( 'url' => $m['url'], 'id' => $m['id'], 'source' => 'library' );
			}
			$this->color( $set, 'content_content_color', $it['quote_style']['color'] ?? '' );
			$this->typography( $set, 'content_typography', (array) $it['quote_style'], array(), 'body' );
			$this->color( $set, 'name_text_color', $it['name_style']['color'] ?? '' );
			$this->typography( $set, 'name_typography', (array) $it['name_style'], array(), 'heading' );
			$this->color( $set, 'job_text_color', $it['role_style']['color'] ?? '' );
			$this->typography( $set, 'job_typography', (array) $it['role_style'], array(), 'body' );
			$card = $this->container_base( 'column' );
			$this->card_skin( $card, (array) $b['card'] );
			$cards[] = $this->container( $card, array( $this->widget( 'testimonial', $set ) ) );
			$this->trace['native']++;
		}
		$this->apply_container_margin( $grid, $b );
		return $this->container( $grid, $cards );
	}

	/** Testimonial / card skin from the recognizer's card box (background, border, radius, padding, shadow). */
	private function card_skin( array &$set, array $card ) {
		$bg = $card['bg'] ?? ( $card['background'] ?? '' );
		if ( '' !== $bg && ! FW_SC_Style::is_transparent( $bg ) ) { $set['background_background'] = 'classic'; $this->color( $set, 'background_color', $bg ); }
		$rad = $this->radius( $card['radius'] ?? '' );
		if ( $rad ) { $set['border_radius'] = $rad; }
		$pad = FW_SC_Style::box( $card['padding'] ?? '' );
		if ( $pad ) { $set['padding'] = $this->dims( $pad ); }
		$extra = array();
		if ( ! empty( $card['gradient'] ) && false !== stripos( (string) $card['gradient'], 'gradient' ) ) { $extra[] = 'background-image:' . $card['gradient']; }
		if ( ! empty( $card['shadow'] ) ) { $extra[] = 'box-shadow:' . $card['shadow']; }
		if ( ! empty( $card['border'] ) ) { $extra[] = 'border:' . $card['border']; }
		if ( ! empty( $card['css'] ) ) { $extra[] = rtrim( (string) $card['css'], ';' ); }
		if ( $extra ) {
			$cls = $this->cls( 'card' );
			$set['css_classes'] = trim( ( $set['css_classes'] ?? '' ) . ' ' . $cls );
			$this->css[] = '.' . $cls . '{' . implode( ';', $extra ) . '}';
		}
	}

	/** A card's call to action as a button widget (its measured skin), unless its label repeats the title. */
	private function card_button( array $k ) {
		$b = $k['button'] ?? null;
		if ( ! is_array( $b ) || '' === trim( (string) $b['label'] ) ) { return null; }
		if ( 0 === strcasecmp( trim( wp_strip_all_tags( (string) $b['label'] ) ), trim( wp_strip_all_tags( (string) ( $k['title'] ?? '' ) ) ) ) ) { return null; }
		$w = $this->button( $b );
		// Inside a card (a block container) a block-level CTA spans the card. A standalone button's computed
		// `display:block` is usual for a flex-row CTA and says nothing about its width, so only here.
		$kd = trim( (string) ( $k['style']['display'] ?? 'block' ) );
		$ka = trim( (string) ( $k['style']['align-items'] ?? 'normal' ) );
		$spans = ( false === strpos( $kd, 'flex' ) && false === strpos( $kd, 'grid' ) ) || in_array( $ka, array( 'normal', 'stretch' ), true );
		if ( $spans && 'block' === trim( (string) ( $b['style']['display'] ?? '' ) ) ) { $w['settings']['align'] = 'justify'; }
		return $w;
	}

	private function accordion( array $b ) {
		$tabs = array();
		foreach ( (array) $b['items'] as $it ) {
			$tabs[] = array( '_id' => $this->id(), 'tab_title' => wp_strip_all_tags( (string) $it['title'] ), 'tab_content' => (string) $it['content'] );
		}
		$set  = array( 'tabs' => $tabs, 'title_html_tag' => preg_match( '/^(h[1-6]|div|span|p)$/', $b['item']['title_tag'] ) ? $b['item']['title_tag'] : 'h3' );
		if ( ! empty( $b['faq'] ) ) { $set['faq_schema'] = 'yes'; }
		$this->color( $set, 'title_background', $b['item']['title_background'] ?: $b['item']['background'] );
		$this->color( $set, 'content_background_color', $b['item']['background'] );
		if ( preg_match( '/([\d.]+)px\s+\w+\s+(.+)$/', (string) $b['item']['border'], $m ) ) {
			$set['border_width'] = $this->size( (float) $m[1] );
			$this->color( $set, 'border_color', $m[2] );
		}
		$rad = FW_SC_Style::px( $b['item']['radius'] );
		if ( $rad ) {
			$cls = $this->cls( 'acc' );
			$set['_css_classes'] = trim( ( $set['_css_classes'] ?? '' ) . ' ' . $cls );
			$this->css[] = '.' . $cls . ' .elementor-accordion-item{border-radius:' . $rad . 'px;overflow:hidden;margin-bottom:12px}';
		}
		$this->trace['native']++;
		return $this->widget( 'accordion', $set, $b );
	}

	private function steps( array $b ) {
		$n    = max( 1, count( (array) $b['items'] ) );
		$grid = array(
			'container_type'    => 'grid',
			'content_width'     => 'full',
			'grid_columns_grid' => array( 'unit' => 'fr', 'size' => min( 4, $n ), 'sizes' => array() ),
			'grid_columns_grid_mobile' => array( 'unit' => 'fr', 'size' => 1, 'sizes' => array() ),
			'grid_gaps'         => array( 'column' => '32', 'row' => '32', 'unit' => 'px', 'isLinked' => true ),
			'padding'           => $this->dims( array( 0, 0, 0, 0 ) ),
		);
		$cells = array();
		foreach ( (array) $b['items'] as $it ) {
			$kids = array();
			if ( '' !== (string) $it['number'] ) {
				$ns = array( 'title' => esc_html( (string) $it['number'] ), 'header_size' => 'div' );
				$this->typography( $ns, 'typography', (array) $it['number_style'], array(), 'heading' );
				$this->color( $ns, 'title_color', $it['number_style']['color'] ?? '' );
				$kids[] = $this->widget( 'heading', $ns );
			}
			$ts = array( 'title' => esc_html( (string) $it['title'] ), 'header_size' => 'h3' );
			$this->typography( $ts, 'typography', (array) $it['title_style'], array(), 'heading' );
			$this->color( $ts, 'title_color', $it['title_style']['color'] ?? '' );
			$ts['_margin'] = $this->dims( array( 0, 0, 12, 0 ) );
			$kids[] = $this->widget( 'heading', $ts );
			$xs = array( 'editor' => '<p>' . esc_html( wp_strip_all_tags( (string) $it['content'] ) ) . '</p>' );
			$this->typography( $xs, 'typography', (array) $it['text_style'], array(), 'body' );
			$this->color( $xs, 'text_color', $it['text_style']['color'] ?? '' );
			$kids[] = $this->widget( 'text-editor', $xs );
			$cells[] = $this->container( $this->container_base( 'column' ), $kids );
		}
		$this->apply_container_margin( $grid, $b );
		$this->trace['composed']++;
		return $this->container( $grid, $cells );
	}

	private function logos( array $b ) {
		$set = $this->container_base( 'row' );
		$set['flex_wrap']            = 'wrap';
		$set['flex_justify_content'] = 'center';
		$set['flex_align_items']     = 'center';
		$set['flex_gap']             = $this->gap( (float) $b['gap'] ?: 32 );
		$kids = array();
		foreach ( (array) $b['logos'] as $l ) {
			if ( '' === (string) $l['url'] ) {
				if ( '' !== (string) $l['svg'] ) { $kids[] = $this->html_widget( $l['svg'], array(), 'logo', false ); }
				continue;
			}
			$m  = $this->media( (string) $l['url'], (string) $l['name'] );
			$is = array( 'image' => array( 'url' => $m['url'], 'id' => $m['id'], 'alt' => (string) $l['name'], 'source' => 'library' ), 'image_size' => 'full', '_element_width' => 'auto' );
			if ( $b['height'] ) { $is['height'] = $this->size( (float) $b['height'] ); $is['width'] = array( 'unit' => 'px', 'size' => '', 'sizes' => array() ); }
			if ( '' !== (string) $l['href'] ) { $is['link_to'] = 'custom'; $is['link'] = array( 'url' => (string) $l['href'], 'is_external' => 'on', 'nofollow' => '' ); }
			$kids[] = $this->widget( 'image', $is );
		}
		if ( $b['opacity'] < 1 || $b['grayscale'] ) {
			$cls = $this->cls( 'logos' );
			$set['css_classes'] = trim( ( $set['css_classes'] ?? '' ) . ' ' . $cls );
			$this->css[] = '.' . $cls . ' img{' . ( $b['opacity'] < 1 ? 'opacity:' . (float) $b['opacity'] . ';' : '' ) . ( $b['grayscale'] ? 'filter:grayscale(1);' : '' ) . '}';
		}
		$this->apply_container_margin( $set, $b );
		$this->trace['composed']++;
		return $this->container( $set, $kids );
	}

	private function avatars( array $b ) {
		$set = $this->container_base( 'row' );
		$set['flex_align_items'] = 'center';
		$set['flex_gap'] = $this->gap( 12 );
		$imgs = '';
		foreach ( (array) $b['images'] as $u ) {
			$m = $this->media( (string) $u );
			$imgs .= '<img src="' . esc_url( $m['url'] ) . '" alt="" style="width:40px;height:40px;border-radius:50%;border:2px solid #fff;margin-left:-8px;object-fit:cover">';
		}
		$kids = array( $this->html_widget( '<div style="display:flex;padding-left:8px">' . $imgs . '</div>', array(), 'avatars', false ) );
		if ( '' !== (string) $b['label'] ) {
			$ls = array( 'editor' => '<p>' . esc_html( (string) $b['label'] ) . '</p>' );
			$this->typography( $ls, 'typography', (array) $b['style'], array(), 'body' );
			$kids[] = $this->widget( 'text-editor', $ls );
		}
		$this->apply_container_margin( $set, $b );
		$this->trace['composed']++;
		return $this->container( $set, $kids );
	}

	private function card( array $b ) {
		$set = $this->container_base( 'column' );
		$cls = $this->cls( 'float' );
		$set['css_classes'] = trim( ( $set['css_classes'] ?? '' ) . ' ' . $cls );
		if ( '' !== (string) $b['position_css'] ) { $this->css[] = str_replace( 'selector', '.' . $cls, (string) $b['position_css'] ); }
		$set['padding'] = $this->dims( array( 16, 16, 16, 16 ) );
		$kids = array();
		if ( '' !== (string) $b['title'] ) {
			$ts = array( 'title' => esc_html( (string) $b['title'] ), 'header_size' => 'h4' );
			$this->typography( $ts, 'typography', (array) $b['title_style'], array(), 'heading' );
			$this->color( $ts, 'title_color', $b['title_style']['color'] ?? '' );
			$kids[] = $this->widget( 'heading', $ts );
		}
		if ( '' !== (string) $b['text'] ) {
			$xs = array( 'editor' => wp_kses_post( (string) $b['text'] ) );
			$this->typography( $xs, 'typography', (array) $b['text_style'], array(), 'body' );
			$this->color( $xs, 'text_color', $b['text_style']['color'] ?? '' );
			$kids[] = $this->widget( 'text-editor', $xs );
		}
		$this->trace['composed']++;
		return $this->container( $set, $kids );
	}

	/**
	 * A verbatim block that is only a flex row of TEXT LEAVES (`<div class="flex justify-between"><span>Label</span>
	 * <span>01</span></div>`) → a native row container of text widgets, laid out like the source (direction,
	 * justify, align, gap from its computed style) with each leaf's own computed type. Anything richer — a nested
	 * element, an SVG, an image, a form — returns null and stays verbatim.
	 */
	private function leaf_row( $html, array $b ) {
		$html = trim( (string) $html );
		if ( '' === $html || strlen( $html ) > 6000 || ! class_exists( 'DOMDocument' ) ) { return null; }
		$doc  = new DOMDocument();
		$prev = libxml_use_internal_errors( true );
		$ok   = $doc->loadHTML( '<?xml encoding="utf-8"?><div id="upw-leafroot">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors(); libxml_use_internal_errors( $prev );
		if ( ! $ok ) { return null; }
		$wrap = $doc->getElementById( 'upw-leafroot' );
		$root = null;
		foreach ( $wrap ? $wrap->childNodes : array() as $n ) {
			if ( XML_ELEMENT_NODE === $n->nodeType ) { if ( $root ) { return null; } $root = $n; }
			elseif ( XML_TEXT_NODE === $n->nodeType && '' !== trim( $n->textContent ) ) { return null; }
		}
		if ( ! $root ) { return null; }
		$rcs  = FW_SC_Style::parse( (string) $root->getAttribute( 'data-sc-cs' ) );
		$cls  = ' ' . (string) $root->getAttribute( 'class' ) . ' ';
		$flex = false !== strpos( (string) ( $rcs['display'] ?? '' ), 'flex' ) || preg_match( '/\s(inline-)?flex\s/', $cls );
		// A plain wrapper around ONE element (the converter's `sc-tw` shell) is unwrapped; its computed style is
		// inherited by what it wraps.
		for ( $guard = 0; ! $flex && $guard < 3; $guard++ ) {
			$only = null;
			foreach ( $root->childNodes as $n ) {
				if ( XML_ELEMENT_NODE === $n->nodeType ) { if ( $only ) { return null; } $only = $n; }
				elseif ( XML_TEXT_NODE === $n->nodeType && '' !== trim( $n->textContent ) ) { return null; }
			}
			if ( ! $only || ! in_array( strtolower( $only->nodeName ), array( 'div', 'span', 'p', 'li' ), true ) ) { return null; }
			$root = $only;
			$rcs  = FW_SC_Style::parse( (string) $root->getAttribute( 'data-sc-cs' ) ) + $rcs;
			$cls  = ' ' . (string) $root->getAttribute( 'class' ) . ' ';
			$flex = false !== strpos( (string) ( $rcs['display'] ?? '' ), 'flex' ) || preg_match( '/\s(inline-)?flex\s/', $cls );
		}
		if ( ! $flex ) { return null; }
		// MIXED inline content (text beside inline spans: "Atmospheric O₂ <span>+340 ppm</span>") is ONE line of rich
		// text: a single leaf whose inline spans keep their own colour / weight as inline styles.
		$mixed = false;
		foreach ( $root->childNodes as $n ) { if ( XML_TEXT_NODE === $n->nodeType && '' !== trim( $n->textContent ) ) { $mixed = true; break; } }
		if ( $mixed ) {
			$rich = $this->inline_rich( $root );
			if ( null === $rich || '' === trim( wp_strip_all_tags( $rich ) ) ) { return null; }
			$leaves = array( array( 'tag' => 'span', 'text' => '', 'rich' => $rich, 'href' => '', 'style' => array() ) );
		}
		$leaves = $mixed ? $leaves : array();
		foreach ( $mixed ? array() : $root->childNodes as $n ) {
			if ( XML_TEXT_NODE === $n->nodeType ) { if ( '' !== trim( $n->textContent ) ) { return null; } continue; }
			if ( XML_ELEMENT_NODE !== $n->nodeType ) { continue; }
			$tag = strtolower( $n->nodeName );
			if ( 'img' === $tag ) {
				// An image leaf (an avatar): its size from the source's own w-/h- utilities, its skin from its style.
				if ( '' === (string) $n->getAttribute( 'src' ) ) { return null; }
				$leaves[] = array( 'tag' => 'img', 'src' => (string) $n->getAttribute( 'src' ), 'alt' => (string) $n->getAttribute( 'alt' ),
					'class' => ' ' . (string) $n->getAttribute( 'class' ) . ' ', 'style' => FW_SC_Style::parse( (string) $n->getAttribute( 'data-sc-cs' ) ) );
				continue;
			}
			if ( ! in_array( $tag, array( 'span', 'p', 'div', 'a', 'small', 'strong', 'em', 'b', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ), true ) ) { return null; }
			foreach ( $n->childNodes as $k ) { if ( XML_ELEMENT_NODE === $k->nodeType ) { return null; } }
			$text = trim( preg_replace( '/\s+/', ' ', $n->textContent ) );
			if ( '' === $text ) { continue; } // an empty decorative leaf (a status dot): its size is not in the capture, so it is dropped
			$leaves[] = array( 'tag' => $tag, 'text' => $text, 'href' => 'a' === $tag ? (string) $n->getAttribute( 'href' ) : '', 'style' => FW_SC_Style::parse( (string) $n->getAttribute( 'data-sc-cs' ) ) );
		}
		if ( count( $leaves ) < 1 || count( $leaves ) > 6 ) { return null; }
		$imgs = 0; foreach ( $leaves as $l ) { if ( 'img' === $l['tag'] ) { $imgs++; } }
		if ( $imgs > 2 || $imgs === count( $leaves ) ) { return null; } // an image alone, or a gallery, is not this shape

		$set = $this->container_base( false !== strpos( (string) ( $rcs['flex-direction'] ?? '' ), 'column' ) || preg_match( '/\sflex-col\s/', $cls ) ? 'column' : 'row' );
		$ju  = array( 'space-between' => 'space-between', 'center' => 'center', 'flex-end' => 'flex-end', 'end' => 'flex-end', 'space-around' => 'space-around', 'space-evenly' => 'space-evenly' );
		$jv  = (string) ( $rcs['justify-content'] ?? '' );
		if ( '' === $jv && preg_match( '/\sjustify-(between|center|end|around|evenly)\s/', $cls, $jm ) ) { $jv = array( 'between' => 'space-between', 'end' => 'flex-end', 'around' => 'space-around', 'evenly' => 'space-evenly', 'center' => 'center' )[ $jm[1] ]; }
		if ( isset( $ju[ $jv ] ) ) { $set['flex_justify_content'] = $ju[ $jv ]; }
		$av  = (string) ( $rcs['align-items'] ?? '' );
		if ( '' === $av && preg_match( '/\sitems-(center|start|end|baseline)\s/', $cls, $am ) ) { $av = 'center' === $am[1] ? 'center' : ( 'start' === $am[1] ? 'flex-start' : ( 'end' === $am[1] ? 'flex-end' : 'baseline' ) ); }
		if ( in_array( $av, array( 'center', 'flex-start', 'flex-end', 'baseline', 'stretch' ), true ) ) { $set['flex_align_items'] = $av; }
		$g = FW_SC_Style::px( preg_replace( '/\s.*$/', '', (string) ( $rcs['gap'] ?? ( $rcs['column-gap'] ?? '' ) ) ) );
		if ( $g ) { $set['flex_gap'] = $this->gap( $g ); }
		$set['flex_wrap'] = 'nowrap';
		$this->apply_container_margin( $set, $b );
		$this->leaf_skin( $set, $rcs );

		$kids = array();
		foreach ( $leaves as $l ) {
			if ( 'img' === $l['tag'] ) { $kids[] = $this->leaf_image( $l ); continue; }
			$st = $l['style'] + $rcs; // a leaf without its own computed style inherits the row's
			$ws = array( 'title' => ! empty( $l['rich'] ) ? $l['rich'] : esc_html( $l['text'] ), 'header_size' => preg_match( '/^h[1-6]$/', $l['tag'] ) ? $l['tag'] : 'span' );
			$this->typography( $ws, 'typography', $st, array(), 'label' );
			$this->color( $ws, 'title_color', $st['color'] ?? '' );
			if ( '' !== $l['href'] ) { $ws['link'] = array( 'url' => $l['href'], 'is_external' => '', 'nofollow' => '' ); }
			$ws['_element_width'] = 'auto';
			if ( 'center' === trim( (string) ( $st['text-align'] ?? '' ) ) ) { $ws['align'] = 'center'; }
			$this->leaf_margin( $ws, $l['style'] );
			$kids[] = $this->widget( 'heading', $ws );
		}
		$this->trace['composed']++;
		return $this->container( $set, $kids );
	}

	/** A leaf's own measured vertical margin (`mt-1`, `mb-4`) → the widget's margin. */
	private function leaf_margin( array &$ws, array $st ) {
		$m = $this->vmargin( (string) ( $st['margin'] ?? '' ) );
		if ( $m[0] > 0 || $m[1] > 0 ) { $ws['_margin'] = $this->dims( array( $m[0], 0, $m[1], 0 ) ); }
	}

	/** The wrapper's own card skin (fill, gradient, border, radius, shadow, blur, padding) → the leaf container. */
	private function leaf_skin( array &$set, array $st ) {
		$pad = FW_SC_Style::box( $st['padding'] ?? '' );
		if ( $pad && array_filter( $pad ) ) { $set['padding'] = $this->dims( $pad ); }
		$css = array();
		$bg  = (string) ( $st['background-color'] ?? '' );
		if ( '' !== $bg && ! FW_SC_Style::is_transparent( $bg ) ) { $css[] = 'background-color:' . $bg; }
		if ( ! empty( $st['background-image'] ) && 'none' !== $st['background-image'] && false !== stripos( $st['background-image'], 'gradient' ) ) { $css[] = 'background-image:' . $st['background-image']; }
		$bw = FW_SC_Style::px( $st['border-width'] ?? ( $st['border-top-width'] ?? '' ) );
		$bc = (string) ( $st['border-color'] ?? ( $st['border-top-color'] ?? '' ) );
		if ( $bw && 'none' !== ( $st['border-top-style'] ?? 'solid' ) && '' !== $bc && ! FW_SC_Style::is_transparent( $bc ) ) { $css[] = 'border:' . $bw . 'px solid ' . $bc; }
		foreach ( array( 'border-radius', 'box-shadow', 'backdrop-filter' ) as $k ) {
			if ( ! empty( $st[ $k ] ) && 'none' !== $st[ $k ] && '0px' !== $st[ $k ] && ! preg_match( '/[;{}<>]/', $st[ $k ] ) ) { $css[] = $k . ':' . $st[ $k ]; }
		}
		if ( ! $css ) { return; }
		$cls = $this->cls( 'leafbox' );
		$set['css_classes'] = trim( ( $set['css_classes'] ?? '' ) . ' ' . $cls );
		$this->css[] = '.' . $cls . '{' . implode( ';', $css ) . '}';
	}

	/** An image leaf → an image widget at the source's w-/h- size, with its own radius / border / fit. */
	private function leaf_image( array $l ) {
		$m   = $this->media( (string) $l['src'], (string) $l['alt'] );
		$set = array( 'image' => array( 'url' => $m['url'], 'id' => $m['id'], 'alt' => (string) $l['alt'], 'source' => 'library' ), 'image_size' => 'full' );
		$tw  = function ( $axis ) use ( $l ) {
			if ( preg_match( '/\s' . $axis . '-\[([\d.]+)(px|rem)\]\s/', $l['class'], $m ) ) { return 'rem' === $m[2] ? (float) $m[1] * 16 : (float) $m[1]; }
			if ( preg_match( '/\s' . $axis . '-(\d+(?:\.\d+)?)\s/', $l['class'], $m ) ) { return (float) $m[1] * 4; }
			return 0;
		};
		$w = $tw( 'w' ); $h = $tw( 'h' );
		if ( $w > 0 ) { $set['width'] = $this->size( $w ); }
		if ( $h > 0 ) { $set['height'] = $this->size( $h ); $set['object-fit'] = preg_match( '/\sobject-contain\s/', $l['class'] ) ? 'contain' : 'cover'; }
		$st  = (array) $l['style'];
		$css = array();
		if ( ! empty( $st['border-radius'] ) && '0px' !== $st['border-radius'] ) { $css[] = 'border-radius:' . $st['border-radius']; }
		$bw = FW_SC_Style::px( $st['border-width'] ?? ( $st['border-top-width'] ?? '' ) );
		$bc = (string) ( $st['border-color'] ?? ( $st['border-top-color'] ?? '' ) );
		if ( $bw && '' !== $bc && ! FW_SC_Style::is_transparent( $bc ) ) { $css[] = 'border:' . $bw . 'px solid ' . $bc; }
		$cls = $this->cls( 'leafimg' );
		$set['_css_classes'] = $cls;
		if ( $css ) { $this->css[] = '.' . $cls . ' img{' . implode( ';', $css ) . '}'; }
		$this->leaf_margin( $set, $st );
		return $this->widget( 'image', $set );
	}

	/**
	 * An element of inline content → safe inline HTML: text escaped, each inline child (span / strong / em / b / small)
	 * a `<span style>` carrying its computed colour / weight / style / size / tracking / case / opacity. An empty
	 * inline child (a dot) is dropped; a child that nests further elements makes the whole thing non-inline (null).
	 */
	private function inline_rich( DOMNode $el ) {
		$keep = array( 'color', 'font-weight', 'font-style', 'font-size', 'letter-spacing', 'text-transform', 'opacity' );
		$out  = '';
		foreach ( $el->childNodes as $n ) {
			if ( XML_TEXT_NODE === $n->nodeType ) { $out .= esc_html( preg_replace( '/\s+/', ' ', $n->textContent ) ); continue; }
			if ( XML_ELEMENT_NODE !== $n->nodeType ) { continue; }
			if ( ! in_array( strtolower( $n->nodeName ), array( 'span', 'strong', 'em', 'b', 'i', 'small' ), true ) ) { return null; }
			foreach ( $n->childNodes as $k ) { if ( XML_ELEMENT_NODE === $k->nodeType ) { return null; } }
			$t = trim( preg_replace( '/\s+/', ' ', $n->textContent ) );
			if ( '' === $t ) { continue; }
			$st  = FW_SC_Style::parse( (string) $n->getAttribute( 'data-sc-cs' ) );
			$css = '';
			foreach ( $keep as $k ) { if ( isset( $st[ $k ] ) && '' !== $st[ $k ] && ! preg_match( '/[;{}<>"]/', $st[ $k ] ) ) { $css .= $k . ':' . $st[ $k ] . ';'; } }
			$out .= '' !== $css ? '<span style="' . esc_attr( $css ) . '">' . esc_html( $t ) . '</span>' : esc_html( $t );
		}
		return trim( $out );
	}

	/** Last rung: the markup as an HTML widget. */
	private function html_widget( $html, array $b, $why, $count = true ) {
		$html = (string) $html;
		if ( '' === trim( $html ) ) { $this->trace['skipped']++; return null; }
		if ( is_callable( $this->opts['html'] ) ) { $html = (string) call_user_func( $this->opts['html'], $html ); }
		if ( $count ) { $this->trace['html']++; $this->fallbacks[] = $why; }
		return $this->widget( 'html', array( 'html' => $html ), $b );
	}

	/**
	 * A block type the model has not normalised yet. Nothing may be silently lost: use its markup when it
	 * has any, otherwise rescue its text fields so the words still reach the page.
	 */
	private function unknown( array $b ) {
		$data = (array) ( $b['data'] ?? array() );
		$type = (string) ( $b['source_type'] ?? 'unknown' );
		if ( ! empty( $data['html'] ) && is_string( $data['html'] ) ) { return $this->html_widget( $data['html'], $b, $type ); }
		$words = array();
		array_walk_recursive( $data, function ( $v, $k ) use ( &$words ) {
			if ( is_string( $v ) && in_array( $k, array( 'title', 'text', 'label', 'message', 'quote', 'content', 'name', 'button_label', 'placeholder' ), true ) && '' !== trim( wp_strip_all_tags( $v ) ) ) {
				$words[] = '<p>' . esc_html( trim( wp_strip_all_tags( $v ) ) ) . '</p>';
			}
		} );
		return $words ? $this->html_widget( implode( '', array_unique( $words ) ), $b, $type ) : $this->html_widget( '', $b, $type );
	}

	/* ------------------------------------------------------------------ *
	 * Style → settings
	 * ------------------------------------------------------------------ */

	/**
	 * Measured typography → an Elementor typography group. The family is left unset when it is the site's
	 * own heading / body font, so the widget inherits it — and follows it when the global font changes.
	 * $role 'label' is body text set in a HEADING widget (an overline, a link label, a pill): that widget
	 * inherits the heading font, so its family is always written.
	 */
	private function typography( array &$set, $prefix, array $st, array $sm, $role ) {
		$g = array();
		$fam = FW_SC_Style::first_family( $st['font-family'] ?? '' );
		$site = (string) ( $this->opts['fonts'][ $role ] ?? '' );
		if ( '' !== $fam && strcasecmp( $fam, $site ) !== 0 && ! preg_match( '/^(ui-|system-ui|-apple-system|sans-serif|serif|monospace)/i', $fam ) ) {
			$g['font_family'] = $fam;
		}
		$fs = FW_SC_Style::px( $st['font-size'] ?? '' );
		if ( $fs ) { $g['font_size'] = $this->size( $fs ); }
		if ( ! empty( $st['font-weight'] ) && preg_match( '/^\d{3}$/', $st['font-weight'] ) ) { $g['font_weight'] = $st['font-weight']; }
		$lh = FW_SC_Style::px( $st['line-height'] ?? '' );
		if ( $lh && $fs ) { $g['line_height'] = array( 'unit' => 'em', 'size' => round( $lh / $fs, 3 ), 'sizes' => array() ); }
		$ls = FW_SC_Style::px( $st['letter-spacing'] ?? '' );
		if ( null !== $ls && 0.0 !== $ls ) { $g['letter_spacing'] = $this->size( $ls ); }
		if ( ! empty( $st['text-transform'] ) && 'none' !== $st['text-transform'] ) { $g['text_transform'] = $st['text-transform']; }
		if ( ! empty( $st['font-style'] ) && 'italic' === $st['font-style'] ) { $g['font_style'] = 'italic'; }
		$fsm = FW_SC_Style::px( $sm['font-size'] ?? '' );
		if ( $fsm && $fsm !== $fs ) {
			$g['font_size_mobile'] = $this->size( $fsm );
			// Tracking in px was measured at the desktop size (tight display type: -5.6px at 112px); it scales with
			// the type on phones rather than crushing the smaller letters.
			if ( null !== $ls && 0.0 !== $ls && $fs ) { $g['letter_spacing_mobile'] = $this->size( round( $ls * $fsm / $fs, 2 ) ); }
		}
		if ( ! $g ) { return; }
		$set[ $prefix . '_typography' ] = 'custom'; // Elementor group control: <name>_typography = 'custom', then <name>_<prop>
		foreach ( $g as $k => $v ) { $set[ $prefix . '_' . $k ] = $v; }
	}

	/** A colour setting, bound to the matching global when the colour is in the palette. */
	private function color( array &$set, $key, $value ) {
		$c = FW_SC_Style::color( (string) $value );
		if ( '' === $c || 'transparent' === $c ) { return; }
		if ( isset( $this->opts['colors'][ $c ] ) ) {
			$set['__globals__'][ $key ] = 'globals/colors?id=' . $this->opts['colors'][ $c ];
			return;
		}
		$set[ $key ] = $c;
	}

	private function align( array &$set, $key, $a ) {
		if ( in_array( $a, array( 'center', 'end', 'justify' ), true ) ) { $set[ $key ] = $a; }
	}

	/** The block's text alignment as start / center / end / justify. */
	private function text_align( array $b ) {
		$a = strtolower( (string) ( $b['align'] ?? '' ) );
		if ( '' === $a ) { $a = strtolower( (string) ( $b['style']['text-align'] ?? '' ) ); }
		$map = array( 'left' => 'start', 'start' => 'start', 'center' => 'center', 'right' => 'end', 'end' => 'end', 'justify' => 'justify' );
		return $map[ $a ] ?? '';
	}

	private function background( array &$set, array $st, array $bg ) {
		$c = $st['background-color'] ?? '';
		if ( '' !== $c && ! FW_SC_Style::is_transparent( $c ) ) {
			$set['background_background'] = 'classic';
			$this->color( $set, 'background_color', $c );
		}
		if ( ! empty( $bg['image'] ) ) {
			$m = $this->media( (string) $bg['image'] );
			$set['background_background'] = 'classic';
			$set['background_image']      = array( 'url' => $m['url'], 'id' => $m['id'], 'source' => 'library' );
			$set['background_size']       = 'cover';
			$set['background_position']   = 'center center';
			$set['background_repeat']     = 'no-repeat';
		} elseif ( ! empty( $st['background-image'] ) && false !== stripos( $st['background-image'], 'gradient' ) ) {
			$cls = $this->cls( 'grad' );
			// `e-no-lazyload`: Elementor blanks container background images below the fold until they scroll in,
			// gradients included; a CSS gradient downloads nothing, so it paints with the page instead of popping in.
			$set['css_classes'] = trim( ( $set['css_classes'] ?? '' ) . ' ' . $cls . ' e-no-lazyload' );
			$this->css[] = '.' . $cls . '{background-image:' . $st['background-image'] . '}';
		}
		if ( ! empty( $bg['video'] ) ) {
			$set['background_background'] = 'video';
			$set['background_video_link'] = (string) $bg['video'];
			$set['background_play_on_mobile'] = 'yes'; // Elementor hides a background video on phones unless told; the source plays it
		}
	}

	/* ------------------------------------------------------------------ *
	 * Value helpers
	 * ------------------------------------------------------------------ */

	/** A source image → its imported attachment; the source alt text rides along so the attachment can carry it. */
	private function media( $url, $alt = '' ) {
		if ( is_callable( $this->opts['media'] ) ) {
			$r = call_user_func( $this->opts['media'], (string) $url, trim( (string) $alt ) );
			if ( is_array( $r ) && ! empty( $r['url'] ) ) { return array( 'id' => (int) ( $r['id'] ?? 0 ), 'url' => (string) $r['url'] ); }
		}
		return array( 'id' => 0, 'url' => (string) $url );
	}

	private function svg_inline( $svg, $size ) {
		return preg_replace( '/<svg\b/i', '<svg style="width:' . $size . ';height:' . $size . ';flex:none"', (string) $svg, 1 );
	}

	private function id() {
		$this->n++;
		return substr( md5( $this->opts['seed'] . '-' . $this->n ), 0, 7 );
	}

	/** A short, page-unique class for CSS the widget settings cannot express. */
	private function cls( $kind ) {
		$this->k++;
		return 'upw-' . $kind . '-' . substr( md5( $this->opts['seed'] . '-c-' . $this->k ), 0, 6 );
	}

	private function size( $n, $unit = 'px' ) {
		return array( 'unit' => $unit, 'size' => round( (float) $n, 3 ), 'sizes' => array() );
	}

	private function dims( array $px ) {
		list( $t, $r, $b, $l ) = array_map( function ( $v ) { return (string) round( (float) $v, 2 ); }, array_pad( $px, 4, 0 ) );
		return array( 'unit' => 'px', 'top' => $t, 'right' => $r, 'bottom' => $b, 'left' => $l, 'isLinked' => ( $t === $r && $r === $b && $b === $l ) );
	}

	/**
	 * A CSS border-radius (1–4 values: an arch is `160px 160px 40px 40px`) → Elementor's corner dims, whose order
	 * (top-left, top-right, bottom-right, bottom-left) is the shorthand's own. Null when there is no radius.
	 */
	private function radius( $v ) {
		$b = FW_SC_Style::box( (string) $v );
		if ( ! $b || ! array_filter( $b ) ) { return null; }
		return $this->dims( $b );
	}

	/** A `margin` shorthand → its [top, bottom] px; a non-px part (`auto`) counts as 0. */
	private function vmargin( $v ) {
		$p = preg_split( '/\s+/', trim( (string) $v ) );
		if ( ! $p || '' === $p[0] ) { return array( 0, 0 ); }
		$t = FW_SC_Style::px( $p[0] );
		$b = FW_SC_Style::px( $p[2] ?? $p[0] );
		return array( max( 0, (float) $t ), max( 0, (float) $b ) );
	}

	private function gap( $n ) {
		$v = (string) round( (float) $n, 2 );
		return array( 'column' => $v, 'row' => $v, 'unit' => 'px', 'isLinked' => true, 'size' => (float) $v );
	}
}
