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

	/** A real browser UA — some hosts (Wix, etc.) serve a stripped page to unknown agents. */
	const BROWSER_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

	/** Postmeta key recording the MD5 of the file bytes — for content-level de-dup. */
	const HASH_META = '_unysonplus_source_hash';

	/** Set by sideload(): true if the last call reused an existing attachment (same URL or identical bytes). */
	public static $last_reused = false;

	/**
	 * Sideload one remote image into the media library, de-duped by source URL.
	 *
	 * @param string $url     Remote image URL (absolute).
	 * @param int    $post_id Optional post to attach the media to (0 = unattached).
	 * @param string $desc    Optional attachment title / description.
	 * @return int|WP_Error   Attachment ID, or WP_Error on failure / skip.
	 */
	public static function sideload( $url, $post_id = 0, $desc = '' ) {
		self::$last_reused = false;
		$url = esc_url_raw( trim( (string) $url ) );

		if ( $url === '' ) {
			return new WP_Error( 'empty_url', __( 'Empty URL.', 'fw' ) );
		}
		// data: URIs (inline SVG/base64) can't be downloaded; skip — they already
		// live inline in the markup and need no media-library entry.
		if ( stripos( $url, 'data:' ) === 0 ) {
			return new WP_Error( 'data_uri', __( 'Skipped inline data: URI (no fetch needed).', 'fw' ) );
		}

		// De-dup #1 (no download): same source URL imported before → reuse it.
		$existing = self::find_by_source( $url );
		if ( $existing ) {
			self::$last_reused = true;
			return $existing;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp = self::download_to_tmp( $url );
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}

		// De-dup #2 (content): identical bytes already imported under a DIFFERENT
		// URL (e.g. the same photo on two sites) → reuse it, don't duplicate the file.
		$hash = @md5_file( $tmp );
		if ( $hash ) {
			$dupe = self::find_by_hash( $hash );
			if ( $dupe ) {
				if ( file_exists( $tmp ) ) {
					@unlink( $tmp );
				}
				// Record this URL too, so a later scan of THIS url short-circuits at de-dup #1.
				add_post_meta( $dupe, self::SOURCE_META, $url ); // multi-value; find_by_source matches any
				self::$last_reused = true;
				return $dupe;
			}
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

		// WebP / AVIF guard. On many hosts PHP's getimagesize()/finfo can't identify webp/avif,
		// so WP's strict real-mime check in media_handle_sideload() blanks the ext+type and
		// rejects the file ("not permitted for security reasons"). Trust the extension for these
		// known raster image types (scoped to THIS sideload only) and ensure they're allowed.
		$accept_modern = static function ( $data, $f, $filename, $mimes, $real_mime = '' ) {
			if ( ! empty( $data['ext'] ) && ! empty( $data['type'] ) ) {
				return $data;
			}
			$e     = strtolower( (string) pathinfo( $filename, PATHINFO_EXTENSION ) );
			$known = array( 'webp' => 'image/webp', 'avif' => 'image/avif' );
			if ( isset( $known[ $e ] ) ) {
				$data['ext']  = $e;
				$data['type'] = $known[ $e ];
			}
			return $data;
		};
		$allow_modern = static function ( $m ) {
			$m['webp'] = 'image/webp';
			$m['avif'] = 'image/avif';
			return $m;
		};
		add_filter( 'wp_check_filetype_and_ext', $accept_modern, 99, 5 );
		add_filter( 'upload_mimes', $allow_modern, 99 );

		$id = media_handle_sideload( $file, (int) $post_id, $desc !== '' ? $desc : null );

		remove_filter( 'wp_check_filetype_and_ext', $accept_modern, 99 );
		remove_filter( 'upload_mimes', $allow_modern, 99 );

		if ( is_wp_error( $id ) ) {
			if ( file_exists( $tmp ) ) {
				@unlink( $tmp );
			}
			return $id;
		}

		update_post_meta( $id, self::SOURCE_META, $url );
		if ( $hash ) {
			update_post_meta( $id, self::HASH_META, $hash );
		}

		return (int) $id;
	}

	/**
	 * Sideload an ALREADY-LOCAL uploaded file (from $_FILES) into the Media Library — used by the
	 * Convert box "Attach media" uploader so a real hero video / poster the source references via an
	 * external CDN can be provided directly instead of downloaded. Keyed by basename (source meta
	 * "upload:<name>") so the mapper can match a captured <source src="…/video.mp4"> to it.
	 *
	 * @param string $name     Original filename (e.g. "video.mp4").
	 * @param string $tmp_path The uploaded temp path ($_FILES[..]['tmp_name']).
	 * @param int    $post_id  Attach-to post (0 = unattached).
	 * @param string $desc     Optional description/title.
	 * @return int|WP_Error Attachment ID, or WP_Error.
	 */
	public static function sideload_upload( $name, $tmp_path, $post_id = 0, $desc = '' ) {
		self::$last_reused = false;
		$name = sanitize_file_name( (string) $name );
		if ( $name === '' || ! is_string( $tmp_path ) || ! is_readable( $tmp_path ) ) {
			return new WP_Error( 'bad_upload', __( 'Unreadable upload.', 'fw' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		// Content de-dup: identical bytes already imported → reuse it.
		$hash = @md5_file( $tmp_path ); // phpcs:ignore
		if ( $hash ) {
			$dupe = self::find_by_hash( $hash );
			if ( $dupe ) {
				self::$last_reused = true;
				update_post_meta( (int) $dupe, self::SOURCE_META, 'upload:' . $name );
				return (int) $dupe;
			}
		}

		// Copy to a fresh temp so media_handle_sideload can move it without touching the $_FILES tmp
		// (and to sidestep is_uploaded_file() constraints on the original).
		$tmp = wp_tempnam( $name );
		if ( ! $tmp || false === @copy( $tmp_path, $tmp ) ) { // phpcs:ignore
			return new WP_Error( 'stage_failed', __( 'Could not stage the upload.', 'fw' ) );
		}
		$file = array( 'name' => $name, 'tmp_name' => $tmp );

		// Ensure common video/image types are allowed (mp4/webm/ogg are core-allowed, but be explicit).
		$allow_av = static function ( $m ) {
			$m['mp4']  = 'video/mp4';
			$m['m4v']  = 'video/mp4';
			$m['webm'] = 'video/webm';
			$m['ogv']  = 'video/ogg';
			$m['webp'] = 'image/webp';
			$m['avif'] = 'image/avif';
			return $m;
		};
		add_filter( 'upload_mimes', $allow_av, 99 );
		$id = media_handle_sideload( $file, (int) $post_id, $desc !== '' ? $desc : null );
		remove_filter( 'upload_mimes', $allow_av, 99 );

		if ( is_wp_error( $id ) ) {
			if ( file_exists( $tmp ) ) { @unlink( $tmp ); } // phpcs:ignore
			return $id;
		}
		update_post_meta( (int) $id, self::SOURCE_META, 'upload:' . $name );
		if ( $hash ) { update_post_meta( (int) $id, self::HASH_META, $hash ); }
		return (int) $id;
	}

	/**
	 * Download a URL to a temp file. Tries WP's streamed download_url() first (memory-light);
	 * on failure — common when a demo/CDN host (e.g. Cloudflare bot rules) 403s a datacenter
	 * request that carries a library User-Agent and no Referer — retries with a browser-like
	 * User-Agent + a same-origin Referer, which clears most hotlink / bot guards.
	 *
	 * @param string $url
	 * @return string|WP_Error Temp file path, or WP_Error.
	 */
	private static function download_to_tmp( $url ) {
		$tmp = download_url( $url );
		if ( ! is_wp_error( $tmp ) ) {
			return $tmp;
		}

		$pu      = wp_parse_url( $url );
		$referer = ( $pu && ! empty( $pu['scheme'] ) && ! empty( $pu['host'] ) ) ? $pu['scheme'] . '://' . $pu['host'] . '/' : '';
		$resp = wp_remote_get( $url, array(
			'timeout'     => 30,
			'redirection' => 5,
			'user-agent'  => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
			'headers'     => array(
				'Referer' => $referer,
				'Accept'  => 'image/avif,image/webp,image/apng,image/png,image/*,*/*;q=0.8',
			),
		) );
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		if ( 200 !== $code ) {
			$reason = wp_remote_retrieve_response_message( $resp );
			return new WP_Error( 'http_' . $code, $reason !== '' ? $reason : sprintf( 'HTTP %d', $code ) );
		}
		$body = wp_remote_retrieve_body( $resp );
		if ( '' === $body ) {
			return new WP_Error( 'empty_body', __( 'Empty response body.', 'fw' ) );
		}
		$tmp2 = wp_tempnam( $url );
		if ( ! $tmp2 ) {
			return new WP_Error( 'no_tmp', __( 'Could not create a temp file.', 'fw' ) );
		}
		if ( false === file_put_contents( $tmp2, $body ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			@unlink( $tmp2 );
			return new WP_Error( 'write_failed', __( 'Could not write the downloaded file.', 'fw' ) );
		}
		return $tmp2;
	}

	/**
	 * Find an attachment previously imported with identical file bytes (content
	 * de-dup — catches the same image fetched from a different URL).
	 *
	 * @param string $hash MD5 of the file.
	 * @return int Attachment ID, or 0.
	 */
	public static function find_by_hash( $hash ) {
		if ( $hash === '' ) {
			return 0;
		}
		$ids = get_posts( array(
			'post_type'        => 'attachment',
			'post_status'      => 'inherit',
			'numberposts'      => 1,
			'fields'           => 'ids',
			'no_found_rows'    => true,
			'suppress_filters' => false,
			'meta_key'         => self::HASH_META,
			'meta_value'       => $hash,
		) );

		return $ids ? (int) $ids[0] : 0;
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

			$id = self::sideload( $url, $post_id );

			if ( is_wp_error( $id ) ) {
				$out[] = array( 'source' => $url, 'ok' => false, 'message' => $id->get_error_message() );
			} else {
				$out[] = array(
					'source' => $url,
					'ok'     => true,
					'id'     => $id,
					'url'    => wp_get_attachment_url( $id ),
					'reused' => self::$last_reused,
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
			'urls' => array(), 'html' => 0, 'meta' => 0, 'embedded' => 0, 'js' => 0,
			'scripts' => 0, 'inline_svg' => 0, 'data_uri' => 0, 'error' => '',
		);

		$resp = wp_remote_get( $page_url, array(
			'timeout'    => 20,
			'user-agent' => self::BROWSER_UA,
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
		// Also mine the page HTML itself for absolute image URLs embedded in JSON /
		// data-attrs / inline scripts (Wix, Next.js, Nuxt … inline their media URLs
		// rather than emitting <img src>). These are HTML-entity-decoded in collect().
		foreach ( self::extract_asset_urls( $html, $page_url ) as $u ) {
			if ( ! isset( $set[ $u ] ) ) { $report['embedded']++; }
			$set[ $u ] = true;
		}

		if ( $deep ) {
			$n = 0;
			foreach ( self::script_srcs( $html, $page_url ) as $src ) {
				if ( $n >= $max_scripts ) { break; }
				$jr = wp_remote_get( $src, array( 'timeout' => 25, 'user-agent' => self::BROWSER_UA ) );
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

	/**
	 * Localize every importable image URL in a content string (HTML or CSS) to its local
	 * attachment URL — matched by the source-URL postmeta the importer set. Covers <img src>,
	 * srcset and CSS url(...), including .svg and query-stringed URLs. A no-op when nothing
	 * matches (e.g. media not imported yet), so it's safe to call in any context.
	 *
	 * @param string $content
	 * @return string
	 */
	public static function localize( $content ) {
		if ( ! is_string( $content ) || $content === '' || ! function_exists( 'wp_get_attachment_url' ) ) {
			return $content;
		}
		if ( ! preg_match_all( '#https?://[^"\'\\\\\s)]+?\.(?:jpe?g|png|gif|webp|avif|svg)(?:\?[^"\'\\\\\s)]*)?#i', $content, $m ) ) {
			return $content;
		}
		$map = array();
		foreach ( array_unique( $m[0] ) as $url ) {
			$id = self::find_by_source( $url );
			if ( ! $id ) {
				// Some references carry a cache-busting query the import stored without — retry bare.
				$bare = preg_replace( '/\?.*$/', '', $url );
				if ( $bare !== $url ) {
					$id = self::find_by_source( $bare );
				}
			}
			if ( $id ) {
				$local = wp_get_attachment_url( $id );
				if ( $local ) {
					$map[ $url ] = $local;
				}
			}
		}

		return $map ? self::rewrite( $content, $map ) : $content;
	}
}
