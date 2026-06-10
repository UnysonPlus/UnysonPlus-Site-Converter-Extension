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
		$found = array();

		if ( preg_match_all( '/<img\b[^>]*?\bsrc\s*=\s*["\']([^"\']+)["\']/i', $html, $m ) ) {
			$found = array_merge( $found, $m[1] );
		}
		if ( preg_match_all( '/\bsrcset\s*=\s*["\']([^"\']+)["\']/i', $html, $m ) ) {
			foreach ( $m[1] as $srcset ) {
				foreach ( explode( ',', $srcset ) as $cand ) {
					$u = trim( $cand );
					$u = trim( substr( $u, 0, strcspn( $u, " \t" ) ) ); // drop the descriptor
					if ( $u !== '' ) {
						$found[] = $u;
					}
				}
			}
		}
		if ( preg_match_all( '/url\(\s*["\']?([^"\')]+)["\']?\s*\)/i', $html, $m ) ) {
			$found = array_merge( $found, $m[1] );
		}

		$out = array();
		foreach ( $found as $u ) {
			$u = trim( html_entity_decode( $u, ENT_QUOTES ) );
			if ( $u === '' || stripos( $u, 'data:' ) === 0 ) {
				continue;
			}
			$abs = self::absolutize( $u, $base );
			if ( $abs !== '' ) {
				$out[ $abs ] = true;
			}
		}

		return array_keys( $out );
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
