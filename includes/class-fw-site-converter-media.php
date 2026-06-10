<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Media engine for the Site Converter — fetch remote images into the WordPress
 * media library (the "media phase" of the AI-site → WordPress importer).
 *
 * The reusable core: download a remote URL with download_url(), insert it with
 * media_handle_sideload() (which also generates the intermediate sizes), and
 * remember the source URL on the attachment so re-running an import de-dupes
 * instead of creating duplicates. Also scans HTML for image references and
 * rewrites them from source URLs to the new media URLs.
 *
 * Pure static helpers — no state, so the admin page, a future bundle importer,
 * and WP-CLI can all call the same methods.
 */
class FW_Site_Converter_Media {

	/** Postmeta key that records the original remote URL each attachment came from. */
	const SOURCE_META = '_unysonplus_source_url';

	/**
	 * Sideload one remote image into the media library, de-duped by source URL.
	 *
	 * @param string $url     Remote image URL (absolute).
	 * @param int    $post_id Optional post to attach the media to (0 = unattached).
	 * @param string $desc    Optional attachment title / description.
	 * @return int|WP_Error   Attachment ID, or WP_Error on failure / skip.
	 */
	public static function sideload( $url, $post_id = 0, $desc = '' ) {
		$url = esc_url_raw( trim( (string) $url ) );

		if ( $url === '' ) {
			return new WP_Error( 'empty_url', __( 'Empty URL.', 'fw' ) );
		}
		// data: URIs (inline SVG/base64) can't be downloaded; skip — they already
		// live inline in the markup and need no media-library entry.
		if ( stripos( $url, 'data:' ) === 0 ) {
			return new WP_Error( 'data_uri', __( 'Skipped inline data: URI (no fetch needed).', 'fw' ) );
		}

		// Already imported on a previous run? Reuse it.
		$existing = self::find_by_source( $url );
		if ( $existing ) {
			return $existing;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp = download_url( $url );
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}

		// Derive a sane filename + extension from the ACTUAL file contents, so
		// extension-less URLs (e.g. /image?id=5) still land with a valid type.
		$ext  = '';
		$info = @getimagesize( $tmp );
		if ( is_array( $info ) && isset( $info[2] ) ) {
			$ext = ltrim( (string) image_type_to_extension( $info[2] ), '.' );
		}
		$file = array(
			'name'     => self::filename_from_url( $url, $ext ),
			'tmp_name' => $tmp,
		);

		$id = media_handle_sideload( $file, (int) $post_id, $desc !== '' ? $desc : null );

		if ( is_wp_error( $id ) ) {
			if ( file_exists( $tmp ) ) {
				@unlink( $tmp );
			}
			return $id;
		}

		update_post_meta( $id, self::SOURCE_META, $url );

		return (int) $id;
	}

	/**
	 * Find an attachment previously imported from the given source URL.
	 *
	 * @param string $url
	 * @return int Attachment ID, or 0.
	 */
	public static function find_by_source( $url ) {
		$ids = get_posts( array(
			'post_type'        => 'attachment',
			'post_status'      => 'inherit',
			'numberposts'      => 1,
			'fields'           => 'ids',
			'no_found_rows'    => true,
			'suppress_filters' => false,
			'meta_key'         => self::SOURCE_META,
			'meta_value'       => esc_url_raw( trim( (string) $url ) ),
		) );

		return $ids ? (int) $ids[0] : 0;
	}

	/**
	 * Build a sanitised filename from a URL, forcing a known extension when given.
	 *
	 * @param string $url
	 * @param string $ext Extension without the dot (from content sniffing).
	 * @return string
	 */
	public static function filename_from_url( $url, $ext = '' ) {
		$path = (string) parse_url( $url, PHP_URL_PATH );
		$name = $path !== '' ? basename( $path ) : '';

		if ( $ext !== '' ) {
			$base = ( $name !== '' && strpos( $name, '.' ) !== false )
				? preg_replace( '/\.[^.]+$/', '', $name )
				: $name;
			if ( $base === '' ) {
				$base = 'image-' . substr( md5( $url ), 0, 8 );
			}
			$name = $base . '.' . $ext;
		} elseif ( $name === '' || strpos( $name, '.' ) === false ) {
			// No extension we can trust and none sniffed — default to .jpg.
			$name = 'image-' . substr( md5( $url ), 0, 8 ) . '.jpg';
		}

		return sanitize_file_name( $name );
	}

	/**
	 * Import a list of image URLs. De-dupes the input and per-source.
	 *
	 * @param string[] $urls
	 * @param int      $post_id
	 * @return array[] One row per URL: { source, ok, id?, url?, reused?, message? }.
	 */
	public static function import_urls( array $urls, $post_id = 0 ) {
		$seen = array();
		$out  = array();

		foreach ( $urls as $url ) {
			$url = trim( (string) $url );
			if ( $url === '' || isset( $seen[ $url ] ) ) {
				continue;
			}
			$seen[ $url ] = true;

			$reused = self::find_by_source( $url ) > 0;
			$id     = self::sideload( $url, $post_id );

			if ( is_wp_error( $id ) ) {
				$out[] = array( 'source' => $url, 'ok' => false, 'message' => $id->get_error_message() );
			} else {
				$out[] = array(
					'source' => $url,
					'ok'     => true,
					'id'     => $id,
					'url'    => wp_get_attachment_url( $id ),
					'reused' => $reused,
				);
			}
		}

		return $out;
	}

	/**
	 * Scan an HTML document for image references: <img src>, srcset candidates,
	 * and url(...) in inline styles / <style> blocks. Returns absolute URLs,
	 * de-duped, excluding data: URIs.
	 *
	 * @param string $html
	 * @param string $base Base URL used to absolutise relative references.
	 * @return string[]
	 */
	public static function scan_html( $html, $base = '' ) {
		$declared = array(); // <img>/srcset — trusted images
		$other    = array(); // url() — could be a font/anything, must look like an image

		if ( preg_match_all( '/<img\b[^>]*?\bsrc\s*=\s*["\']([^"\']+)["\']/i', $html, $m ) ) {
			$declared = array_merge( $declared, $m[1] );
		}
		if ( preg_match_all( '/\bsrcset\s*=\s*["\']([^"\']+)["\']/i', $html, $m ) ) {
			foreach ( $m[1] as $srcset ) {
				foreach ( explode( ',', $srcset ) as $cand ) {
					$cand = trim( $cand );
					$u    = trim( substr( $cand, 0, strcspn( $cand, " \t" ) ) ); // drop the descriptor
					if ( $u !== '' ) {
						$declared[] = $u;
					}
				}
			}
		}
		if ( preg_match_all( '/url\(\s*["\']?([^"\')]+)["\']?\s*\)/i', $html, $m ) ) {
			$other = $m[1];
		}

		return array_values( array_unique( array_merge(
			self::collect( $declared, $base, true ),
			self::collect( $other, $base, false )
		) ) );
	}

	/**
	 * Resolve a possibly-relative URL against a base URL.
	 *
	 * @param string $url
	 * @param string $base
	 * @return string Absolute URL, or '' if it can't be resolved.
	 */
	public static function absolutize( $url, $base ) {
		if ( preg_match( '#^https?://#i', $url ) ) {
			return $url;
		}
		if ( strpos( $url, '//' ) === 0 ) {
			return 'https:' . $url;
		}
		if ( $base === '' ) {
			return ''; // relative ref but no base to resolve against
		}

		$b = wp_parse_url( $base );
		if ( ! $b || empty( $b['scheme'] ) || empty( $b['host'] ) ) {
			return '';
		}
		$root = $b['scheme'] . '://' . $b['host'] . ( isset( $b['port'] ) ? ':' . $b['port'] : '' );

		if ( strpos( $url, '/' ) === 0 ) {
			return $root . $url;
		}
		$dir = isset( $b['path'] ) ? preg_replace( '#/[^/]*$#', '/', $b['path'] ) : '/';
		if ( $dir === '' ) {
			$dir = '/';
		}

		return $root . $dir . $url;
	}

	/**
	 * Count the inline graphics on a page that CAN'T be sideloaded — inline
	 * <svg> elements and data: image URIs. AI-generated sites often draw every
	 * icon/logo this way, so a scan can legitimately find zero fetchable files;
	 * this lets the UI explain "nothing to fetch" instead of looking broken.
	 *
	 * @param string $html
	 * @return array{ inline_svg:int, data_uri:int }
	 */
	public static function count_inline_graphics( $html ) {
		$svg  = preg_match_all( '/<svg\b/i', $html, $m ) ? count( $m[0] ) : 0;
		$data = preg_match_all( '/\bdata:image\//i', $html, $m ) ? count( $m[0] ) : 0;

		return array( 'inline_svg' => (int) $svg, 'data_uri' => (int) $data );
	}

	/**
	 * Image refs from <meta og:image / twitter:image> and <link rel=icon /
	 * apple-touch-icon>. Catches the social/share image + favicons that aren't
	 * <img> tags. Returns absolute URLs.
	 *
	 * @param string $html
	 * @param string $base
	 * @return string[]
	 */
	public static function scan_meta( $html, $base = '' ) {
		$found = array();

		if ( preg_match_all( '/<meta\b[^>]*\b(?:property|name)\s*=\s*["\'](?:og:image(?::secure_url)?|twitter:image(?::src)?)["\'][^>]*>/i', $html, $tags ) ) {
			foreach ( $tags[0] as $tag ) {
				if ( preg_match( '/\bcontent\s*=\s*["\']([^"\']+)["\']/i', $tag, $m ) ) {
					$found[] = $m[1];
				}
			}
		}
		if ( preg_match_all( '/<link\b[^>]*\brel\s*=\s*["\'][^"\']*(?:icon|apple-touch-icon)[^"\']*["\'][^>]*>/i', $html, $tags ) ) {
			foreach ( $tags[0] as $tag ) {
				if ( preg_match( '/\bhref\s*=\s*["\']([^"\']+)["\']/i', $tag, $m ) ) {
					$found[] = $m[1];
				}
			}
		}

		return self::collect( $found, $base, false );
	}

	/**
	 * Same-origin <script src> URLs — the bundles to mine for image assets on a
	 * JS-rendered (SPA) site, where the real images never appear in the static
	 * HTML. Restricted to the page's own host (never fetches third-party JS).
	 *
	 * @param string $html
	 * @param string $base
	 * @return string[]
	 */
	public static function script_srcs( $html, $base = '' ) {
		$out = array();
		if ( preg_match_all( '/<script\b[^>]*\bsrc\s*=\s*["\']([^"\']+)["\']/i', $html, $m ) ) {
			foreach ( $m[1] as $u ) {
				$abs = self::absolutize( trim( $u ), $base );
				if ( $abs !== '' && self::same_host( $abs, $base ) ) {
					$out[ $abs ] = true;
				}
			}
		}

		return array_keys( $out );
	}

	/**
	 * Pull image-file URLs out of arbitrary text (a JS/CSS bundle): absolute
	 * URLs and root-relative `/assets/foo.webp` references. Raster formats only
	 * (SVG is excluded — WordPress blocks SVG upload and bundles reference many
	 * inline icon SVGs that would just fail).
	 *
	 * @param string $text
	 * @param string $base
	 * @return string[]
	 */
	public static function extract_asset_urls( $text, $base = '' ) {
		$found = array();
		// Absolute or protocol-relative image URLs.
		if ( preg_match_all( '#(?:https?:)?//[^"\'\\\\\s()]+?\.(?:png|jpe?g|webp|gif|avif)#i', $text, $m ) ) {
			$found = array_merge( $found, $m[0] );
		}
		// Root-relative refs in quotes/parens, e.g. "/assets/hero-abc.webp".
		if ( preg_match_all( '#["\'(]\s*(/[A-Za-z0-9._/-]+?\.(?:png|jpe?g|webp|gif|avif))#i', $text, $m ) ) {
			$found = array_merge( $found, $m[1] );
		}

		return self::collect( $found, $base, false );
	}

	/**
	 * Orchestrated page scan: fetch the page, collect <img>/srcset/url() +
	 * meta/favicon refs, and (when $deep) fetch up to $max_scripts same-origin
	 * script bundles and mine them for image assets — so JS-rendered apps work.
	 *
	 * @param string $page_url
	 * @param bool   $deep        Also fetch + scan same-origin JS bundles.
	 * @param int    $max_scripts Cap on bundles fetched.
	 * @return array  Report: { urls[], html, meta, js, scripts, inline_svg, data_uri, error }.
	 */
	public static function scan_page( $page_url, $deep = true, $max_scripts = 4 ) {
		$report = array(
			'urls' => array(), 'html' => 0, 'meta' => 0, 'js' => 0,
			'scripts' => 0, 'inline_svg' => 0, 'data_uri' => 0, 'error' => '',
		);

		$resp = wp_remote_get( $page_url, array(
			'timeout'    => 20,
			'user-agent' => 'UnysonPlus-SiteConverter/1.0; ' . home_url( '/' ),
		) );
		if ( is_wp_error( $resp ) ) {
			$report['error'] = $resp->get_error_message();
			return $report;
		}
		if ( (int) wp_remote_retrieve_response_code( $resp ) >= 400 ) {
			$report['error'] = sprintf( 'HTTP %d fetching the page.', (int) wp_remote_retrieve_response_code( $resp ) );
			return $report;
		}

		$html = (string) wp_remote_retrieve_body( $resp );

		$g = self::count_inline_graphics( $html );
		$report['inline_svg'] = $g['inline_svg'];
		$report['data_uri']   = $g['data_uri'];

		$set = array();
		foreach ( self::scan_html( $html, $page_url ) as $u ) {
			if ( ! isset( $set[ $u ] ) ) { $report['html']++; }
			$set[ $u ] = true;
		}
		foreach ( self::scan_meta( $html, $page_url ) as $u ) {
			if ( ! isset( $set[ $u ] ) ) { $report['meta']++; }
			$set[ $u ] = true;
		}

		if ( $deep ) {
			$n = 0;
			foreach ( self::script_srcs( $html, $page_url ) as $src ) {
				if ( $n >= $max_scripts ) { break; }
				$jr = wp_remote_get( $src, array( 'timeout' => 25 ) );
				if ( is_wp_error( $jr ) || (int) wp_remote_retrieve_response_code( $jr ) >= 400 ) {
					continue;
				}
				$n++;
				foreach ( self::extract_asset_urls( (string) wp_remote_retrieve_body( $jr ), $page_url ) as $u ) {
					if ( ! isset( $set[ $u ] ) ) { $report['js']++; }
					$set[ $u ] = true;
				}
			}
			$report['scripts'] = $n;
		}

		$report['urls'] = array_keys( $set );

		return $report;
	}

	/**
	 * Absolutise + filter + de-dupe raw refs into real image URLs. Drops empties,
	 * data: URIs, and — crucially — non-images: a CSS `url()` or a JS-bundle string
	 * is only kept if it actually points at a raster image. `<img>`/`srcset` refs
	 * ($declared = true) are trusted as images (still rejecting obvious non-image
	 * file types like fonts). This is what stops `.woff2` fonts, `.ico` favicons,
	 * and junk like `window.location.href` from being queued for import.
	 *
	 * @param string[] $raw
	 * @param string   $base
	 * @param bool     $declared Came from an <img>/srcset (trusted) vs url()/JS.
	 * @return string[]
	 */
	private static function collect( array $raw, $base, $declared ) {
		$out = array();
		foreach ( $raw as $u ) {
			$u = trim( html_entity_decode( (string) $u, ENT_QUOTES ) );
			if ( $u === '' || stripos( $u, 'data:' ) === 0 ) {
				continue;
			}
			$abs = self::absolutize( $u, $base );
			if ( $abs === '' || ! self::accept_image_url( $abs, $declared ) ) {
				continue;
			}
			$out[ $abs ] = true;
		}

		return array_keys( $out );
	}

	/**
	 * Whether a URL should be treated as a fetchable raster image.
	 *
	 * @param string $url
	 * @param bool   $declared <img>/srcset source (trusted) vs url()/JS (must look like an image).
	 * @return bool
	 */
	private static function accept_image_url( $url, $declared ) {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );

		// Always reject known non-image file types — fonts, code, docs, video,
		// vector/icon (WP blocks SVG/ICO upload). Catches the @font-face url()s.
		if ( preg_match( '/\.(?:woff2?|ttf|otf|eot|css|m?js|json|html?|xml|svg|ico|cur|mp4|webm|mov|avi|map|txt|php)(?:$|[?#])/i', $path ) ) {
			return false;
		}

		if ( $declared ) {
			return true; // an <img>/srcset URL — trust it's an image
		}

		// A url()/JS-bundle ref: keep only if the path carries a raster image extension.
		return (bool) preg_match( '~\.(?:png|jpe?g|gif|webp|avif|bmp)(?:$|[/?#])~i', $path );
	}

	/**
	 * Whether two URLs share the same host.
	 *
	 * @param string $url
	 * @param string $base
	 * @return bool
	 */
	private static function same_host( $url, $base ) {
		$a = wp_parse_url( $url );
		$b = wp_parse_url( $base );

		return $a && $b && ! empty( $a['host'] ) && ! empty( $b['host'] )
			&& strcasecmp( $a['host'], $b['host'] ) === 0;
	}

	/**
	 * Rewrite source image URLs to their new media URLs in a content string.
	 * Replaces longest keys first so a shorter URL can't clobber a longer one.
	 *
	 * @param string $content
	 * @param array  $map source_url => new_url
	 * @return string
	 */
	public static function rewrite( $content, array $map ) {
		$clean = array();
		foreach ( $map as $from => $to ) {
			if ( is_string( $from ) && $from !== '' && is_string( $to ) && $to !== '' ) {
				$clean[ $from ] = $to;
			}
		}
		if ( ! $clean ) {
			return $content;
		}
		uksort( $clean, static function ( $a, $b ) {
			return strlen( $b ) - strlen( $a );
		} );

		return strtr( $content, $clean );
	}
}
