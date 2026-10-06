<?php if ( ! defined( 'FW' ) ) { die( 'Forbidden' ); }

/**
 * Elementor output — the converted pages as Elementor documents (Flexbox Containers + free widgets),
 * rendered inside the Unyson+ parent theme, which keeps the header, footer and Theme Settings.
 *
 *   design   Theme Settings are imported as for every target, then mirrored into the Elementor Site Kit
 *            through the Builder Sync extension (colours, fonts, headings, links, container, button).
 *   pages    Site Model → FW_SC_Elementor_Emitter → Document::save(), on the "Elementor Full Width"
 *            template so the theme's header and footer frame the page.
 *   CSS      what Elementor's free controls cannot express (gradients, shadows, absolute positioning)
 *            is stored per page and printed in that page's <head>.
 */
class FW_SC_Target_Elementor extends FW_SC_Target {

	/** Post meta: which target wrote this page (shared by every non-native target). */
	const META_TARGET = '_fw_sc_target';
	/** Post meta: the page's companion CSS. */
	const META_CSS = '_fw_sc_elementor_css';

	public function slug() { return 'elementor'; }

	public function label() { return __( 'Elementor', 'fw' ); }

	public function status() { return self::STATUS_PRE_ALPHA; }

	public function note() { return __( 'Unyson+ theme header & footer', 'fw' ); }

	public function description() {
		return __( 'Pages as Elementor documents — Flexbox Containers and the free widget set — inside the Unyson+ theme, which keeps the converted header, footer and Theme Settings. Colors and fonts are mirrored into the Elementor Site Kit and stay in sync.', 'fw' );
	}

	public function verify_profile() {
		return array( 'section' => '.elementor > .e-con, .elementor-section' );
	}

	/** Print each converted page's companion CSS. Called once when the extension loads. */
	public static function boot() {
		add_action( 'wp_head', array( __CLASS__, '_print_page_css' ), 99 );
	}

	/** @internal */
	public static function _print_page_css() {
		if ( ! is_singular() ) { return; }
		$css = (string) get_post_meta( get_queried_object_id(), self::META_CSS, true );
		if ( '' === trim( $css ) ) { return; }
		echo '<style id="upw-elementor-page-css">' . wp_strip_all_tags( $css ) . "</style>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSS, tags stripped
	}

	private function elementor_active() {
		return did_action( 'elementor/loaded' ) && class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->documents );
	}

	public function unmet_requirements() {
		$out = array();
		if ( ! $this->elementor_active() ) {
			$out[] = array( 'code' => 'elementor_inactive', 'message' => __( 'Install and activate the Elementor plugin to convert into Elementor.', 'fw' ) );
			return $out;
		}
		$exp = \Elementor\Plugin::$instance->experiments ?? null;
		if ( $exp && method_exists( $exp, 'is_feature_active' ) && ! $exp->is_feature_active( 'container' ) ) {
			$out[] = array( 'code' => 'containers_off', 'message' => __( 'Turn on Flexbox Container in Elementor → Settings → Features — the converted pages are built from containers.', 'fw' ) );
		}
		return $out;
	}

	/**
	 * Mirror the just-imported Theme Settings into the Elementor Site Kit, so the converted pages' global
	 * colours resolve and the kit starts in step with the theme.
	 */
	public function after_design_import( array $ctx = array() ) {
		if ( ! $this->elementor_active() ) { return array(); }
		$sync = self::sync_extension();
		if ( ! $sync ) { return array( 'kit' => 'builder-sync unavailable' ); }
		$res = $sync->push( 'elementor' );
		return array( 'kit' => isset( $res['elementor'] ) ? count( (array) $res['elementor'] ) . ' kit settings updated' : 'unchanged' );
	}

	/**
	 * The Builder Sync extension: activated on first use, because an Elementor conversion is exactly the
	 * situation it exists for. In the request that activates it the extension is not loaded yet, so its
	 * classes are loaded directly for this one push.
	 *
	 * @return object|null something with push( $slug )
	 */
	private static function sync_extension() {
		if ( function_exists( 'fw_ext' ) && fw_ext( 'builder-sync' ) ) { return fw_ext( 'builder-sync' ); }
		if ( function_exists( 'fw' ) && fw()->extensions->manager->can_activate() ) {
			fw()->extensions->manager->activate_extensions( array( 'builder-sync' => array() ) );
		}
		$dir = dirname( __FILE__, 5 ) . '/builder-sync/includes';
		foreach ( array( '/class-fw-builder-sync-design.php', '/class-fw-builder-sync-provider.php', '/providers/class-fw-builder-sync-elementor.php' ) as $f ) {
			if ( ! is_file( $dir . $f ) ) { return null; }
			require_once $dir . $f;
		}
		return new class() {
			public function push( $only ) {
				$p = new FW_Builder_Sync_Elementor();
				return array( 'elementor' => $p->is_available() ? $p->push( FW_Builder_Sync_Design::read() ) : array() );
			}
		};
	}

	/* ------------------------------------------------------------------ *
	 * Pages
	 * ------------------------------------------------------------------ */

	public function import_pages( array $mapping, array $built, array $ctx = array() ) {
		if ( ! $this->elementor_active() ) {
			return array( 'pages' => array(), 'error' => __( 'Elementor is not active.', 'fw' ) );
		}
		$palette = function_exists( 'unysonplus_color_preset_slug_map' ) ? (array) unysonplus_color_preset_slug_map() : array();
		$model   = FW_SC_Site_Model::from_mapping( $mapping, $palette );

		// The native build's per-page options (a source with no footer hides the theme footer) apply to
		// every target — they are theme switches, not page-builder content.
		$popts = array();
		foreach ( (array) ( $built['pages'] ?? array() ) as $bp ) {
			if ( is_array( $bp ) && ! empty( $bp['page_options'] ) ) { $popts[ (string) ( $bp['slug'] ?? '' ) ] = $bp['page_options']; }
		}

		$opts = array(
			'colors'    => $this->global_colors( $palette ),
			'fonts'     => $this->site_fonts(),
			'container' => $this->site_container( $model ),
		);

		$specs = array();
		foreach ( $model['pages'] as $i => $page ) {
			$base           = '' !== $page['source_url'] ? $page['source_url'] : (string) ( $ctx['source_url'] ?? '' );
			$opts['media']  = function ( $url ) use ( $base ) { return self::media( $url, $base ); };
			$opts['html']   = function ( $html ) use ( $base ) { return self::localize_html( $html, $base ); };
			$opts['native'] = $this->native_resolver( $page, $this->built_page( $built, $page, $i ) );
			$opts['seed']   = ( '' !== $page['slug'] ? $page['slug'] : 'home' ) . '-' . $i;
			$out            = ( new FW_SC_Elementor_Emitter( $opts ) )->page( $page );
			$spec = array(
				'title'      => $page['title'],
				'slug'       => $page['slug'],
				'front_page' => $page['front_page'],
				'source_url' => $page['source_url'],
				'elementor'  => $out,
			);
			if ( isset( $popts[ $page['slug'] ] ) ) { $spec['page_options'] = $popts[ $page['slug'] ]; }
			$specs[] = $spec;
		}

		$res = FW_Site_Converter_Pages::import( array( 'pages' => $specs ), array(
			'replace' => (array) ( $ctx['replace'] ?? array() ),
			'writer'  => array( $this, 'write_page' ),
		) );
		$res['target'] = $this->slug();
		return $res;
	}

	/**
	 * Store one page as an Elementor document. Runs through Document::save() — Elementor's own path, which
	 * validates every element against its registered widgets, stamps versions and invalidates the CSS.
	 *
	 * @internal (FW_Site_Converter_Pages writer callback)
	 */
	public function write_page( $post_id, array $spec, $existed ) {
		$out = (array) ( $spec['elementor'] ?? array() );
		$els = (array) ( $out['elements'] ?? array() );

		// The page builder must not also claim this page, or the theme renders its (empty) tree.
		if ( function_exists( 'fw_set_db_post_option' ) && '' !== (string) get_post_meta( $post_id, 'fw:opt:ext:pb:page-builder:json', true ) ) {
			fw_set_db_post_option( $post_id, 'page-builder', array( 'json' => '[]', 'builder_active' => false ) );
		}

		$restore = self::ensure_editor( $post_id );
		$doc     = \Elementor\Plugin::$instance->documents->get( $post_id, false );
		if ( ! $doc ) { self::restore_user( $restore ); return array( 'error' => 'Elementor could not open this page.' ); }
		$doc->set_is_built_with_elementor( true );
		$saved = $doc->save( array(
			'elements' => $els,
			'settings' => array( 'template' => 'elementor_header_footer' ),
		) );
		self::restore_user( $restore );

		update_post_meta( $post_id, self::META_TARGET, $this->slug() );
		update_post_meta( $post_id, self::META_CSS, wp_slash( (string) ( $out['css'] ?? '' ) ) );
		$stored = (string) get_post_meta( $post_id, '_elementor_data', true );
		if ( '' !== $stored ) { update_post_meta( $post_id, '_upw_import_hash', md5( $stored ) ); }

		// Read back: Elementor drops elements it does not recognise without an error, so count what landed.
		$want = self::count_elements( $els );
		$got  = self::count_elements( json_decode( $stored, true ) ?: array() );
		$row  = array( 'target' => $this->slug(), 'elementor' => array( 'trace' => $out['trace'] ?? array(), 'fallbacks' => $out['fallbacks'] ?? array(), 'elements' => $got ) );
		if ( ! $saved ) { $row['error'] = __( 'Elementor refused to save this page.', 'fw' ); }
		elseif ( $got < $want ) { $row['warning'] = sprintf( 'Elementor kept %d of %d elements.', $got, $want ); }
		return $row;
	}

	/* ------------------------------------------------------------------ *
	 * Unyson+ elements as Elementor widgets
	 * ------------------------------------------------------------------ */

	/** Site Model block type → the Unyson+ shortcode the native build makes of it. */
	private static $native_tags = array(
		'testimonials' => 'testimonials',
		'accordion'    => 'accordion',
		'steps'        => 'steps',
		'logos'        => 'logo_grid',
		'list'         => 'feature_list',
		'avatars'      => 'avatar',
		'pricing'      => 'pricing_table', // an `unknown` block, keyed by its source type
		'card'         => 'icon_box',      // a column that IS a card
		'counter'      => 'counter',       // a column that IS a stat cell
		'newsletter'   => 'newsletter',    // an `unknown` block, keyed by its source type
		'badge'        => 'badge',
		'gallery'      => 'gallery',       // `unknown` blocks from here down, keyed by their source type
		'tabs'         => 'tabs',
		'timeline'     => 'timeline',
		'progress'     => 'progress',
		'table'        => 'table',
		'chips'        => 'tag_list',
		'cta'          => 'call_to_action',
		'rating'       => 'star_rating',
		'lottie'       => 'lottie',
		'blockquote'   => 'blockquote',
		'carousel'     => 'carousel',
		'countdown'    => 'countdown',
		'flip_box'     => 'flip_box',
		'team_member'  => 'team_member',
		'comparison'   => 'comparison_table',
		'before_after' => 'before_after',
		'video_popup'  => 'video_popup',
		'social_share' => 'social_share',
		'animated_heading' => 'animated_heading',
		'form'         => 'contact_form',
	);

	/** The native build's page for this model page: same slug, else the same position. */
	private function built_page( array $built, array $page, $i ) {
		$list = (array) ( $built['pages'] ?? array() );
		foreach ( $list as $bp ) {
			if ( is_array( $bp ) && (string) ( $bp['slug'] ?? '' ) === (string) $page['slug'] ) { return $bp; }
		}
		return isset( $list[ $i ] ) && is_array( $list[ $i ] ) ? $list[ $i ] : array();
	}

	/**
	 * A per-page resolver: hands the emitter, block by block, the native build's own element for that block
	 * as an `up-<tag>` widget (FW_Elementor_Option_Bridge::settings_from_atts). The native build made the
	 * same blocks in the same document order, so the Nth testimonials block IS the Nth [testimonials]
	 * node — but only when the counts agree; a page where they do not falls back to the emitter's own rungs
	 * for that type rather than risk pairing a block with another block's settings.
	 */
	private function native_resolver( array $page, array $built_page ) {
		$ext = function_exists( 'fw_ext' ) ? fw_ext( 'elementor' ) : null;
		if ( ! $ext || ! method_exists( $ext, 'widget_for_shortcode' ) || ! class_exists( 'FW_Elementor_Option_Bridge' ) ) { return null; }

		$nodes = array(); // tag => [ atts, … ] in document order
		$walk  = function ( $list ) use ( &$walk, &$nodes ) {
			foreach ( (array) $list as $n ) {
				if ( ! is_array( $n ) ) { continue; }
				if ( ! empty( $n['shortcode'] ) ) { $nodes[ (string) $n['shortcode'] ][] = (array) ( $n['atts'] ?? array() ); }
				if ( ! empty( $n['_items'] ) ) { $walk( $n['_items'] ); }
			}
		};
		$walk( $built_page['builder'] ?? array() );

		$count = array(); // model type => blocks on this page
		$cwalk = function ( $blocks ) use ( &$cwalk, &$count ) {
			foreach ( (array) $blocks as $b ) {
				$t = (string) ( $b['type'] ?? '' );
				if ( 'unknown' === $t ) { $t = (string) ( $b['source_type'] ?? '' ); }
				$count[ $t ] = ( $count[ $t ] ?? 0 ) + 1;
				foreach ( (array) ( $b['columns'] ?? array() ) as $c ) {
					if ( ! empty( $c['counter'] ) ) { $count['counter'] = ( $count['counter'] ?? 0 ) + 1; }
					elseif ( ! empty( $c['card'] ) ) { $count['card'] = ( $count['card'] ?? 0 ) + 1; }
					$cwalk( $c['blocks'] ?? array() );
				}
				if ( ! empty( $b['blocks'] ) ) { $cwalk( $b['blocks'] ); }
			}
		};
		foreach ( (array) $page['sections'] as $s ) { $cwalk( $s['blocks'] ?? array() ); }

		$widgets = array();
		foreach ( self::$native_tags as $type => $tag ) {
			$name = (string) $ext->widget_for_shortcode( $tag );
			if ( '' !== $name && isset( $nodes[ $tag ] ) && count( $nodes[ $tag ] ) === (int) ( $count[ $type ] ?? 0 ) ) {
				$widgets[ $type ] = array( 'tag' => $tag, 'name' => $name );
			}
		}
		if ( ! $widgets ) { return null; }

		$seen = array();
		return function ( $type ) use ( $widgets, $nodes, &$seen ) {
			if ( ! isset( $widgets[ $type ] ) ) { return null; }
			$tag = $widgets[ $type ]['tag'];
			$i   = $seen[ $type ] = isset( $seen[ $type ] ) ? $seen[ $type ] + 1 : 0;
			if ( ! isset( $nodes[ $tag ][ $i ] ) ) { return null; }
			$settings = FW_Elementor_Option_Bridge::settings_from_atts( $tag, $nodes[ $tag ][ $i ] );
			return $settings ? array( 'widget' => $widgets[ $type ]['name'], 'settings' => $settings ) : null;
		};
	}

	/* ------------------------------------------------------------------ *
	 * Helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Palette colour → Elementor global id, for the colours Builder Sync put in the kit. Primary, secondary
	 * and accent are Elementor's system colours; the body text colour is its system "text".
	 */
	private function global_colors( array $palette ) {
		$ids = get_option( 'fw_builder_sync_ids_elementor', array() );
		$ids = is_array( $ids ) && isset( $ids['colors'] ) && is_array( $ids['colors'] ) ? $ids['colors'] : array();
		$map = array();
		foreach ( $palette as $slug => $hex ) {
			$c = FW_SC_Style::color( (string) $hex );
			if ( '' === $c || isset( $map[ $c ] ) ) { continue; }
			if ( in_array( $slug, array( 'primary', 'secondary', 'accent' ), true ) ) { $map[ $c ] = $slug; }
			elseif ( isset( $ids[ $slug ] ) ) { $map[ $c ] = $ids[ $slug ]; }
		}
		$typo = class_exists( 'FW_WP_Option' ) && function_exists( 'fw' ) ? FW_WP_Option::get( 'fw_theme_settings_options:' . fw()->theme->manifest->get_id(), 'typography', array() ) : array();
		$ink  = FW_SC_Style::color( (string) ( $typo['body']['color'] ?? '' ) );
		if ( '' !== $ink && ! isset( $map[ $ink ] ) ) { $map[ $ink ] = 'text'; }
		return $map;
	}

	/**
	 * The site's container width, for a section the analysis measured no width for: Theme Settings' desktop
	 * container when set, else the width most of the site's sections measure (a site is built on one
	 * container). Elementor's own kit default (1140px) is never the right answer for a converted site.
	 */
	private function site_container( array $model ) {
		// A multi-page run imports the front page first and each inner page on its own afterwards; an inner
		// page alone is a poor vote (one narrow band), so the run keeps the first answer it found.
		if ( self::$site_cw ) { return self::$site_cw; }
		self::$site_cw = $this->site_container_vote( $model );
		return self::$site_cw;
	}

	/** @var int the site container width found earlier in this request */
	private static $site_cw = 0;

	private function site_container_vote( array $model ) {
		$cw = class_exists( 'FW_WP_Option' ) && function_exists( 'fw' ) ? FW_WP_Option::get( 'fw_theme_settings_options:' . fw()->theme->manifest->get_id(), 'layout_container_width', array() ) : array();
		if ( isset( $cw['lg']['value'] ) && 'px' === ( $cw['lg']['unit'] ?? 'px' ) && (float) $cw['lg']['value'] > 0 ) { return (int) $cw['lg']['value']; }
		$votes = array();
		foreach ( (array) $model['pages'] as $p ) {
			foreach ( (array) $p['sections'] as $s ) {
				$w = (int) ( $s['container_width'] ?? 0 );
				if ( $w > 0 ) { $votes[ $w ] = ( $votes[ $w ] ?? 0 ) + 1; }
			}
		}
		if ( ! $votes ) { return 0; }
		arsort( $votes );
		return (int) key( $votes );
	}

	/** The site's heading and body font families (widgets that use them inherit instead of repeating them). */
	private function site_fonts() {
		$typo = class_exists( 'FW_WP_Option' ) && function_exists( 'fw' ) ? FW_WP_Option::get( 'fw_theme_settings_options:' . fw()->theme->manifest->get_id(), 'typography', array() ) : array();
		$body = trim( (string) ( $typo['body']['family'] ?? '' ) );
		$head = trim( (string) ( $typo['heading_font']['family'] ?? '' ) );
		return array( 'heading' => '' !== $head ? $head : $body, 'body' => $body );
	}

	/** A source image URL → its imported attachment (the media phase ran first), else the absolute URL. */
	private static function media( $url, $base ) {
		$url = trim( (string) $url );
		if ( '' === $url || 0 === strpos( $url, 'data:' ) ) { return array( 'id' => 0, 'url' => $url ); }
		$abs = ( class_exists( 'FW_Site_Converter_Media' ) && '' !== $base ) ? FW_Site_Converter_Media::absolutize( $url, $base ) : $url;
		if ( class_exists( 'FW_Site_Converter_Media' ) ) {
			foreach ( array_unique( array( $abs, preg_replace( '/\?.*$/', '', $abs ), $url ) ) as $try ) {
				$id = FW_Site_Converter_Media::find_by_source( $try );
				if ( $id ) {
					$local = wp_get_attachment_url( $id );
					if ( $local ) { return array( 'id' => (int) $id, 'url' => $local ); }
				}
			}
		}
		return array( 'id' => 0, 'url' => $abs );
	}

	/**
	 * Raw markup carried into an HTML widget: make its root-relative image / link URLs absolute against the
	 * source, then swap every imported image for its media-library copy — the same localisation the native
	 * page-builder import gives its code blocks.
	 */
	private static function localize_html( $html, $base ) {
		$html = (string) $html;
		if ( '' !== $base && class_exists( 'FW_Site_Converter_Media' ) ) {
			$html = preg_replace_callback( '#\b(src|href|poster)=(["\'])(/[^/"\'][^"\']*)\2#i', function ( $m ) use ( $base ) {
				return $m[1] . '=' . $m[2] . FW_Site_Converter_Media::absolutize( $m[3], $base ) . $m[2];
			}, $html );
			$html = preg_replace_callback( '#url\(\s*(["\']?)(/[^/)"\'][^)"\']*)\1\s*\)#i', function ( $m ) use ( $base ) {
				return 'url(' . $m[1] . FW_Site_Converter_Media::absolutize( $m[2], $base ) . $m[1] . ')';
			}, $html );
		}
		return class_exists( 'FW_Site_Converter_Media' ) ? FW_Site_Converter_Media::localize( $html ) : $html;
	}

	/** Document::save() checks edit_post for the current user; a CLI / cron import has none. */
	private static function ensure_editor( $post_id ) {
		if ( current_user_can( 'edit_post', $post_id ) ) { return null; }
		$prev   = get_current_user_id();
		$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
		if ( $admins ) { wp_set_current_user( (int) $admins[0] ); }
		return $prev;
	}

	private static function restore_user( $prev ) {
		if ( null !== $prev ) { wp_set_current_user( (int) $prev ); }
	}

	private static function count_elements( array $els ) {
		$n = 0;
		foreach ( $els as $e ) {
			if ( ! is_array( $e ) ) { continue; }
			$n++;
			if ( ! empty( $e['elements'] ) && is_array( $e['elements'] ) ) { $n += self::count_elements( $e['elements'] ); }
		}
		return $n;
	}
}
