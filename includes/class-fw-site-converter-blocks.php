<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}
/**
 * The PHP twin of the capture service's `to-blocks.mjs` — emit WordPress CORE block markup
 * from the converter's section/block intermediate, so the plugin's own conversion path
 * (`run_url_conversion`) can produce a portable block theme's page body, in parity with the
 * capture-service (JS) path. Tier C1 of the block-theme output roadmap.
 *
 * Input shape mirrors the JS `capture.sections`: an array of sections, each either
 *   array( 'blocks' => array( <block>, … ) )   — decomposed
 *   array( 'html'   => '<verbatim html>' )      — kept verbatim
 * where a <block> is the same intermediate the Stitch recognizers emit
 * (`array( 't' => 'heading'|'text'|'button'|'image'|'video'|'row'|…, 'html' => …, 'level' => …,
 * 'label' => …, 'href' => …, 'src' => …, 'alt' => …, 'cols' => array( array( 'blocks' => … ) ) )`).
 *
 * Core-first (plugin-independent); anything not yet mapped degrades to a scoped `core/html`
 * block, the block-world twin of the page builder's verbatim `code_block`.
 *
 * @package unysonplus
 */
class FW_Site_Converter_Blocks {

	/* ---------------------------------------------------------------- *
	 * Serialization helpers
	 * ---------------------------------------------------------------- */

	private static function s( $v ) {
		return null === $v ? '' : (string) $v;
	}

	/** Escape a value used inside an HTML attribute (src/href/alt) — matches the JS escAttr. */
	private static function esc_attr_val( $v ) {
		return str_replace( array( '&', '"', '<' ), array( '&amp;', '&quot;', '&lt;' ), self::s( $v ) );
	}

	/** A block-comment attribute suffix, dropping empty values. Byte-compatible with the JS. */
	private static function attr_suffix( array $attrs ) {
		$clean = array();
		foreach ( $attrs as $k => $v ) {
			if ( '' === $v || null === $v ) {
				continue;
			}
			if ( is_array( $v ) && empty( $v ) ) {
				continue;
			}
			$clean[ $k ] = $v;
		}
		if ( empty( $clean ) ) {
			return '';
		}
		return ' ' . wp_json_encode( $clean, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	/** One block. `$inner` is the saved HTML (may itself contain nested block comments). */
	private static function block( $name, array $attrs, $inner ) {
		$a = self::attr_suffix( $attrs );
		if ( null === $inner || '' === $inner ) {
			return "<!-- wp:{$name}{$a} /-->";
		}
		return "<!-- wp:{$name}{$a} -->\n{$inner}\n<!-- /wp:{$name} -->";
	}

	private static function text_align( array $b ) {
		$a = isset( $b['align'] ) ? $b['align'] : ( isset( $b['textAlign'] ) ? $b['textAlign'] : '' );
		return ( 'center' === $a || 'right' === $a ) ? $a : '';
	}

	/* ---------------------------------------------------------------- *
	 * Leaf block mappers
	 * ---------------------------------------------------------------- */

	private static function heading_block( array $b ) {
		$level   = ( isset( $b['level'] ) && $b['level'] >= 1 && $b['level'] <= 6 ) ? (int) $b['level'] : 2;
		$content = self::s( isset( $b['html'] ) ? $b['html'] : '' );
		if ( '' === trim( $content ) ) {
			$content = self::s( isset( $b['text'] ) ? $b['text'] : '' );
		}
		if ( '' === trim( $content ) ) {
			return '';
		}
		$ta  = self::text_align( $b );
		$cls = 'wp-block-heading' . ( $ta ? " has-text-align-{$ta}" : '' );
		return self::block( 'heading', array( 'level' => $level, 'textAlign' => $ta ? $ta : null ), "<h{$level} class=\"{$cls}\">{$content}</h{$level}>" );
	}

	private static function paragraph_block( array $b ) {
		$inner = self::s( isset( $b['html'] ) ? $b['html'] : '' );
		if ( preg_match( '/^\s*<p[^>]*>([\s\S]*?)<\/p>\s*$/i', $inner, $m ) ) {
			$content = $m[1];
		} else {
			$content = '' !== $inner ? $inner : self::s( isset( $b['text'] ) ? $b['text'] : '' );
		}
		$content = trim( $content );
		if ( '' === $content ) {
			return '';
		}
		$ta = self::text_align( $b );
		if ( $ta ) {
			return self::block( 'paragraph', array( 'align' => $ta ), "<p class=\"has-text-align-{$ta}\">{$content}</p>" );
		}
		return self::block( 'paragraph', array(), "<p>{$content}</p>" );
	}

	private static function overline_block( array $b ) {
		$content = trim( self::s( isset( $b['html'] ) ? $b['html'] : ( isset( $b['text'] ) ? $b['text'] : '' ) ) );
		if ( '' === $content ) {
			return '';
		}
		return self::block( 'paragraph', array( 'fontSize' => 'small' ), "<p class=\"has-small-font-size\">{$content}</p>" );
	}

	private static function buttons_block( array $b ) {
		$label = trim( self::s( isset( $b['label'] ) ? $b['label'] : ( isset( $b['text'] ) ? $b['text'] : ( isset( $b['html'] ) ? $b['html'] : '' ) ) ) );
		if ( '' === $label ) {
			return '';
		}
		$href      = self::esc_attr_val( isset( $b['href'] ) ? $b['href'] : ( isset( $b['url'] ) ? $b['url'] : '' ) );
		$href_attr = $href ? " href=\"{$href}\"" : '';
		$button    = self::block( 'button', array(), "<div class=\"wp-block-button\"><a class=\"wp-block-button__link wp-element-button\"{$href_attr}>{$label}</a></div>" );
		return self::block( 'buttons', array(), "<div class=\"wp-block-buttons\">\n{$button}\n</div>" );
	}

	private static function image_block( array $b ) {
		// JS-shape (src/alt) → build the figure; PHP-shape (the source <img> html) → wrap it.
		$src = self::esc_attr_val( isset( $b['src'] ) ? $b['src'] : ( isset( $b['url'] ) ? $b['url'] : '' ) );
		if ( '' !== $src ) {
			$alt = self::esc_attr_val( isset( $b['alt'] ) ? $b['alt'] : '' );
			return self::block( 'image', array( 'sizeSlug' => 'large' ), "<figure class=\"wp-block-image size-large\"><img src=\"{$src}\" alt=\"{$alt}\"/></figure>" );
		}
		$html = self::s( isset( $b['html'] ) ? $b['html'] : '' );
		if ( false !== stripos( $html, '<img' ) ) {
			return self::block( 'image', array( 'sizeSlug' => 'large' ), '<figure class="wp-block-image size-large">' . trim( $html ) . '</figure>' );
		}
		return '';
	}

	private static function video_block( array $b ) {
		$mode = isset( $b['mode'] ) ? $b['mode'] : '';
		if ( 'embed' === $mode || ! empty( $b['embedUrl'] ) ) {
			$url = trim( self::s( isset( $b['embedUrl'] ) ? $b['embedUrl'] : ( isset( $b['src'] ) ? $b['src'] : '' ) ) );
			if ( '' === $url ) {
				return '';
			}
			return self::block( 'embed', array( 'url' => $url, 'type' => 'video', 'responsive' => true ), "<figure class=\"wp-block-embed is-type-video\"><div class=\"wp-block-embed__wrapper\">\n{$url}\n</div></figure>" );
		}
		$src = self::esc_attr_val( isset( $b['src'] ) ? $b['src'] : '' );
		if ( '' === $src ) {
			return '';
		}
		$poster = ! empty( $b['poster'] ) ? ' poster="' . self::esc_attr_val( $b['poster'] ) . '"' : '';
		return self::block( 'video', array(), "<figure class=\"wp-block-video\"><video controls src=\"{$src}\"{$poster}></video></figure>" );
	}

	private static function html_block( $html ) {
		$h = trim( self::s( $html ) );
		if ( '' === $h ) {
			return '';
		}
		return self::block( 'html', array(), $h );
	}

	/* ---------------------------------------------------------------- *
	 * Containers
	 * ---------------------------------------------------------------- */

	private static function columns_block( array $b, $vocab = 'core' ) {
		$parts = array();
		foreach ( ( isset( $b['cols'] ) && is_array( $b['cols'] ) ? $b['cols'] : array() ) as $c ) {
			$inner = self::blocks_to_blocks( isset( $c['blocks'] ) && is_array( $c['blocks'] ) ? $c['blocks'] : array(), $vocab );
			if ( '' === $inner ) {
				continue;
			}
			$parts[] = self::block( 'column', array(), "<div class=\"wp-block-column\">\n{$inner}\n</div>" );
		}
		if ( empty( $parts ) ) {
			return '';
		}
		return self::block( 'columns', array(), "<div class=\"wp-block-columns\">\n" . implode( "\n\n", $parts ) . "\n</div>" );
	}

	/* ---------------------------------------------------------------- *
	 * Enriched vocabulary (Tier C6) — byte-identical to the JS enrichers.
	 * Each returns '' when it can't enrich, so block_to_block falls back to
	 * the core mapper (faithful degradation, never core/html).
	 * ---------------------------------------------------------------- */

	// One of '', 'left', 'center', 'right' — mirrors the JS alignOf().
	private static function align_of( array $b ) {
		$a = trim( self::s( isset( $b['align'] ) ? $b['align'] : ( isset( $b['textAlign'] ) ? $b['textAlign'] : '' ) ) );
		return ( 'left' === $a || 'center' === $a || 'right' === $a ) ? $a : '';
	}

	// unysonplus/button → the `button` shortcode's atts.
	private static function enriched_button( array $b ) {
		$label = trim( self::s( isset( $b['label'] ) ? $b['label'] : ( isset( $b['text'] ) ? $b['text'] : ( isset( $b['html'] ) ? $b['html'] : '' ) ) ) );
		if ( '' === $label ) {
			return '';
		}
		$link = trim( self::s( isset( $b['href'] ) ? $b['href'] : ( isset( $b['url'] ) ? $b['url'] : '#' ) ) );
		if ( '' === $link ) {
			$link = '#';
		}
		return self::block( 'unysonplus/button', array( 'upOptions' => array( 'label' => $label, 'link' => $link, 'target' => '_self' ) ), '' );
	}

	// unysonplus/special-heading → the `special_heading` shortcode: { title, heading, [alignment] }.
	private static function enriched_heading( array $b ) {
		$title = trim( self::s( isset( $b['text'] ) ? $b['text'] : '' ) );
		if ( '' === $title ) {
			return '';
		}
		$level = isset( $b['level'] ) ? (int) $b['level'] : 2;
		if ( $level < 1 ) { $level = 1; }
		if ( $level > 6 ) { $level = 6; }
		$up    = array( 'title' => $title, 'heading' => 'h' . $level );
		$align = self::align_of( $b );
		if ( '' !== $align ) {
			$up['alignment'] = $align;
		}
		return self::block( 'unysonplus/special-heading', array( 'upOptions' => $up ), '' );
	}

	// unysonplus/text-block → the `text_block` shortcode: { text, [text_align] }.
	private static function enriched_text( array $b ) {
		$text = trim( self::s( isset( $b['html'] ) ? $b['html'] : '' ) );
		if ( '' === $text ) {
			return '';
		}
		$up    = array( 'text' => $text );
		$align = self::align_of( $b );
		if ( '' !== $align ) {
			$up['text_align'] = $align;
		}
		return self::block( 'unysonplus/text-block', array( 'upOptions' => $up ), '' );
	}

	private static $enrich_map = array(
		'button'  => 'enriched_button',
		'heading' => 'enriched_heading',
		'text'    => 'enriched_text',
	);

	private static function block_to_block( array $b, $vocab = 'core' ) {
		$t = isset( $b['t'] ) ? $b['t'] : '';
		if ( 'enriched' === $vocab && isset( self::$enrich_map[ $t ] ) ) {
			$out = call_user_func( array( __CLASS__, self::$enrich_map[ $t ] ), $b );
			if ( '' !== $out ) {
				return $out; // else fall through to the core mapper (faithful degradation)
			}
		}
		switch ( $t ) {
			case 'heading':  return self::heading_block( $b );
			case 'overline': return self::overline_block( $b );
			case 'text':     return self::paragraph_block( $b );
			case 'button':   return self::buttons_block( $b );
			case 'image':    return self::image_block( $b );
			case 'video':    return self::video_block( $b );
			case 'row':      return self::columns_block( $b, $vocab );
			default:         return self::html_block( isset( $b['html'] ) ? $b['html'] : '' );
		}
	}

	private static function blocks_to_blocks( array $blocks, $vocab = 'core' ) {
		$out = array();
		foreach ( $blocks as $b ) {
			if ( ! is_array( $b ) ) {
				continue;
			}
			$m = self::block_to_block( $b, $vocab );
			if ( '' !== $m ) {
				$out[] = $m;
			}
		}
		return implode( "\n\n", $out );
	}

	private static function section_block( array $sec, $vocab = 'core' ) {
		if ( isset( $sec['blocks'] ) && is_array( $sec['blocks'] ) && $sec['blocks'] ) {
			$inner = self::blocks_to_blocks( $sec['blocks'], $vocab );
		} elseif ( ! empty( $sec['html'] ) ) {
			$inner = self::html_block( $sec['html'] );
		} else {
			$inner = '';
		}
		if ( '' === $inner ) {
			return '';
		}
		// Enriched: wrap the band in unysonplus/section (delegates to the `section` shortcode, rendering
		// its inner blocks as $content) — the framework's own section instead of a core/group.
		if ( 'enriched' === $vocab ) {
			return self::block( 'unysonplus/section', array( 'align' => 'full' ), $inner );
		}
		return self::block(
			'group',
			array( 'align' => 'full', 'layout' => array( 'type' => 'constrained' ) ),
			"<div class=\"wp-block-group alignfull\">\n{$inner}\n</div>"
		);
	}

	/* ---------------------------------------------------------------- *
	 * Public API
	 * ---------------------------------------------------------------- */

	/**
	 * @param array $sections the section/block intermediate (see the class docblock)
	 * @param array $opts     { vocabulary: 'core'|'enriched' } — 'enriched' emits UnysonPlus blocks
	 *                        where mapped (Tier C6), else core-only.
	 * @return string WordPress block markup for the page body
	 */
	public static function to_blocks( array $sections, array $opts = array() ) {
		$vocab = isset( $opts['vocabulary'] ) ? (string) $opts['vocabulary'] : 'core';
		$out   = array();
		foreach ( $sections as $sec ) {
			if ( ! is_array( $sec ) ) {
				continue;
			}
			$m = self::section_block( $sec, $vocab );
			if ( '' !== $m ) {
				$out[] = $m;
			}
		}
		return implode( "\n\n", $out );
	}

	/**
	 * Install a BLOCK-THEME output bundle (the `target: 'block-theme'` result of `to-block-bundle.mjs`):
	 * write the generated block theme's files, create a page from its block markup, then activate the
	 * theme and make that page the front page. This is the block-theme twin of the classic-theme
	 * install path — a NEW generated theme, never a change to `unysonplus-theme`.
	 *
	 * @param array $bundle { theme: { slug, files }, page: { title, content } }
	 * @return array|WP_Error { slug, page_id, files } on success
	 */
	public static function install_block_theme( array $bundle ) {
		$theme = isset( $bundle['theme'] ) && is_array( $bundle['theme'] ) ? $bundle['theme'] : array();
		$slug  = isset( $theme['slug'] ) ? sanitize_key( (string) $theme['slug'] ) : '';
		$files = isset( $theme['files'] ) && is_array( $theme['files'] ) ? $theme['files'] : array();
		if ( '' === $slug || empty( $files ) ) {
			return new WP_Error( 'fw_sc_block_bundle', __( 'The block-theme bundle is missing its theme slug or files.', 'fw' ) );
		}
		if ( ! function_exists( 'get_theme_root' ) ) {
			return new WP_Error( 'fw_sc_block_bundle', 'Themes API unavailable.' );
		}

		// A converted site derives the SAME slug for its classic and its block theme. If a DIFFERENT
		// (classic, non-block) theme already owns this slug, use a distinct `-blocks` slug so we install
		// alongside it instead of overwriting a working classic theme with block-theme files.
		if ( function_exists( 'wp_get_theme' ) ) {
			$existing = wp_get_theme( $slug );
			if ( $existing->exists() && ! $existing->is_block_theme() ) {
				$slug = $slug . '-blocks';
			}
		}

		// The page body — insert FIRST so sideloaded media can be attached to it.
		$page    = isset( $bundle['page'] ) && is_array( $bundle['page'] ) ? $bundle['page'] : array();
		$title   = isset( $page['title'] ) && '' !== trim( (string) $page['title'] ) ? (string) $page['title'] : 'Home';
		$content = isset( $page['content'] ) ? (string) $page['content'] : '';
		$pid     = wp_insert_post( array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => $title,
			'post_content' => $content,
		), true );
		if ( is_wp_error( $pid ) ) {
			return $pid;
		}

		// LOCALIZE MEDIA — the emitters hotlink the source's image URLs (portable, plugin-free
		// output). Before the theme files hit disk, sideload every referenced image into the media
		// library and rewrite the page body AND the theme files (logo/hero in parts + patterns) to
		// the local attachment URLs, so the installed theme carries no external dependencies.
		$media_count = 0;
		if ( ! class_exists( 'FW_Site_Converter_Media' ) ) {
			$media_file = dirname( __FILE__ ) . '/class-fw-site-converter-media.php';
			if ( file_exists( $media_file ) ) {
				require_once $media_file;
			}
		}
		if ( class_exists( 'FW_Site_Converter_Media' ) && function_exists( 'wp_get_attachment_url' ) ) {
			$scan_src = $content;
			foreach ( $files as $c ) {
				$scan_src .= "\n" . (string) $c;
			}
			$urls = FW_Site_Converter_Media::scan_html( $scan_src );
			if ( $urls ) {
				$map = array();
				foreach ( FW_Site_Converter_Media::import_urls( $urls, (int) $pid ) as $res ) {
					if ( ! empty( $res['ok'] ) && ! empty( $res['url'] ) ) {
						$map[ $res['source'] ] = $res['url'];
					}
				}
				if ( $map ) {
					$media_count = count( $map );
					$new_content = FW_Site_Converter_Media::rewrite( $content, $map );
					if ( $new_content !== $content ) {
						$content = $new_content;
						wp_update_post( array( 'ID' => (int) $pid, 'post_content' => $content ) );
					}
					foreach ( $files as $rel => $c ) {
						$files[ $rel ] = FW_Site_Converter_Media::rewrite( (string) $c, $map );
					}
				}
			}
		}

		// SITE IDENTITY — the header part emits core/site-logo (renders from the custom_logo theme
		// mod) and core/site-title (renders blogname). Resolve the captured logo to an attachment now
		// (the theme mod is applied AFTER switch_theme below, since theme mods are per-theme), and set
		// the site title. Both are carried on the bundle's `site` field.
		$site       = isset( $bundle['site'] ) && is_array( $bundle['site'] ) ? $bundle['site'] : array();
		$logo_id    = 0;
		$logo_url   = isset( $site['logo'] ) ? trim( (string) $site['logo'] ) : '';
		if ( '' !== $logo_url && class_exists( 'FW_Site_Converter_Media' ) ) {
			$lid = FW_Site_Converter_Media::find_by_source( $logo_url );
			if ( ! $lid ) {
				$lid = FW_Site_Converter_Media::sideload( $logo_url, (int) $pid );
			}
			if ( $lid && ! is_wp_error( $lid ) ) {
				$logo_id = (int) $lid;
			}
		}
		$site_title = isset( $site['title'] ) ? trim( (string) $site['title'] ) : '';
		if ( '' !== $site_title ) {
			update_option( 'blogname', $site_title );
		}
		// Write custom_logo straight into the GENERATED theme's own mods (keyed by $slug), not the
		// active theme's — deterministic regardless of whether/when switch_theme runs below, and it
		// can never clobber the currently-active theme's logo.
		if ( $logo_id ) {
			$mods = get_option( 'theme_mods_' . $slug, array() );
			if ( ! is_array( $mods ) ) {
				$mods = array();
			}
			$mods['custom_logo'] = $logo_id;
			update_option( 'theme_mods_' . $slug, $mods );
		}

		$dir     = trailingslashit( get_theme_root() ) . $slug;
		$allowed = array( 'php', 'html', 'json', 'css', 'txt' );
		$written = 0;
		foreach ( $files as $rel => $fcontent ) {
			$rel = ltrim( str_replace( '\\', '/', (string) $rel ), '/' );
			// Path safety: no traversal, no absolute path, only the theme's own known file kinds.
			if ( '' === $rel || false !== strpos( $rel, '..' ) || preg_match( '#^[a-zA-Z]:|^/#', $rel ) ) {
				continue;
			}
			$ext = strtolower( (string) pathinfo( $rel, PATHINFO_EXTENSION ) );
			if ( ! in_array( $ext, $allowed, true ) ) {
				continue;
			}
			$path = $dir . '/' . $rel;
			$sub  = dirname( $path );
			if ( ! is_dir( $sub ) && ! wp_mkdir_p( $sub ) ) {
				return new WP_Error( 'fw_sc_block_bundle', sprintf( __( 'Could not create %s.', 'fw' ), $sub ) );
			}
			if ( false === file_put_contents( $path, (string) $fcontent ) ) { // phpcs:ignore
				return new WP_Error( 'fw_sc_block_bundle', sprintf( __( 'Could not write %s.', 'fw' ), $rel ) );
			}
			$written++;
		}
		if ( ! $written ) {
			return new WP_Error( 'fw_sc_block_bundle', __( 'No block-theme files were written.', 'fw' ) );
		}

		// Activate the generated block theme + make the converted page the front page.
		if ( function_exists( 'switch_theme' ) && wp_get_theme( $slug )->exists() ) {
			switch_theme( $slug );
		}
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', (int) $pid );

		return array( 'slug' => $slug, 'page_id' => (int) $pid, 'files' => $written, 'media' => (int) $media_count, 'logo' => (int) $logo_id );
	}
}
