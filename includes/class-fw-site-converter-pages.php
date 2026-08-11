<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Site Converter — Pages importer (engine).
 *
 * Creates WordPress pages from page-builder content (the conversion contract §2 —
 * the builder-tree JSON). It does NOT hand-author the encoded shortcode string
 * (contract rule #1): it sets the post's `page-builder` option via
 * `fw_set_db_post_option()`, and the page-builder extension's own
 * `fw_post_options_update` hook (`_action_fw_post_options_update`) regenerates
 * `post_content` from the tree with the plugin's encoder. Setting the option this
 * way is side-effect-safe — it never reads `$_POST`, so it can't wipe other
 * options the way a programmatic `save_post` would.
 *
 * Payload (forgiving):
 *
 *   { "pages": [
 *       { "title": "Home", "slug": "home", "status": "publish", "front_page": true,
 *         "builder": [ { "type": "section", … }, … ] },   // §2.1 tree (array of sections)
 *       { "title": "About", "json": "[ {\"type\":\"section\", …} ]" }  // or a stringified tree
 *   ] }
 *
 * A single page object or a bare list of page specs is accepted too. Re-running
 * is idempotent: a page is matched by slug and updated, never duplicated.
 *
 * Static so the Convert bundle / WP-CLI can reuse it (mirrors the other engines).
 */
class FW_Site_Converter_Pages {

	/** The page-builder post-option id. */
	const OPTION_KEY = 'page-builder';

	/**
	 * Import one or more pages.
	 *
	 * @param array $data `{ pages: [ … ] }`, a bare list, or a single page object.
	 * @return array{pages: array<int,array>, error: string}
	 */
	public static function import( $data ) {
		$out = array( 'pages' => array(), 'error' => '' );

		if ( ! is_array( $data ) ) {
			$out['error'] = __( 'Invalid pages payload — expected a JSON object.', 'fw' );
			return $out;
		}
		if ( ! function_exists( 'fw_set_db_post_option' ) ) {
			$out['error'] = __( 'The page-builder is unavailable (Unyson framework not active).', 'fw' );
			return $out;
		}

		if ( isset( $data['pages'] ) && is_array( $data['pages'] ) ) {
			$specs = $data['pages'];
		} elseif ( self::is_list( $data ) ) {
			$specs = $data;
		} else {
			$specs = array( $data );
		}

		foreach ( $specs as $spec ) {
			if ( ! is_array( $spec ) ) {
				continue;
			}
			$out['pages'][] = self::import_one( $spec );
		}

		return $out;
	}

	/**
	 * Convenience: parse a raw JSON string then import.
	 *
	 * @param string $json
	 * @return array{pages: array, error: string}
	 */
	public static function import_json( $json ) {
		$json = trim( (string) $json );
		if ( $json === '' ) {
			return array( 'pages' => array(), 'error' => __( 'Paste a pages JSON to import.', 'fw' ) );
		}
		$decoded = json_decode( $json, true );
		if ( ! is_array( $decoded ) ) {
			return array( 'pages' => array(), 'error' => __( 'That is not valid JSON.', 'fw' ) );
		}
		return self::import( $decoded );
	}

	/* ---------------------------------------------------------------------- *
	 * Internals
	 * ---------------------------------------------------------------------- */

	/**
	 * Create or update one page from its spec.
	 *
	 * @param array $spec
	 * @return array{title: string, slug: string, id: int, created: bool, front_page: bool, error: string}
	 */
	private static function import_one( array $spec ) {
		$title  = trim( (string) self::pluck( $spec, array( 'title', 'name', 'label' ), '' ) );
		$slug   = trim( (string) self::pluck( $spec, array( 'slug', 'post_name' ), '' ) );
		$status = sanitize_key( (string) self::pluck( $spec, array( 'status', 'post_status' ), 'publish' ) );
		$front  = (bool) self::pluck( $spec, array( 'front_page', 'is_front_page', 'front' ), false );

		// Builder tree: 'builder' (array) or 'json' (string/array), or a template
		// envelope's 'json' field.
		$tree = self::pluck( $spec, array( 'builder', 'json', 'tree', '_items' ), null );

		$row = array( 'title' => $title, 'slug' => '', 'id' => 0, 'created' => false, 'front_page' => false, 'error' => '' );

		if ( $title === '' && empty( $tree ) ) {
			$row['error'] = __( 'A page has no title and no builder content — skipped.', 'fw' );
			return $row;
		}
		if ( $title === '' ) {
			$title         = __( 'Imported Page', 'fw' );
			$row['title']  = $title;
		}

		// Normalize the tree to a JSON STRING (the page-builder option stores a string).
		if ( is_array( $tree ) ) {
			$json = wp_json_encode( $tree );
		} elseif ( is_string( $tree ) && $tree !== '' ) {
			$json = $tree;
		} else {
			$json = '[]'; // empty page (no builder content) — still create the post
		}

		// Re-point any source image URLs in the tree at the imported Media Library
		// attachments (the media phase runs first, so they exist + carry a source-URL
		// postmeta). Falls through harmlessly when an image wasn't imported.
		$json = self::resolve_media_urls( $json );

		$status   = in_array( $status, array( 'publish', 'draft', 'pending', 'private' ), true ) ? $status : 'publish';
		$slug_eff = sanitize_title( $slug !== '' ? $slug : $title );

		// Idempotent: match an existing page by slug → update, else create.
		$existing = get_page_by_path( $slug_eff, OBJECT, 'page' );

		$postarr = array(
			'post_type'   => 'page',
			'post_title'  => $title,
			'post_name'   => $slug_eff,
			'post_status' => $status,
		);

		if ( $existing ) {
			$postarr['ID'] = (int) $existing->ID;
			$post_id       = wp_update_post( $postarr, true );
		} else {
			$post_id          = wp_insert_post( $postarr, true );
			$row['created']   = true;
		}

		if ( is_wp_error( $post_id ) || ! $post_id ) {
			$row['error']   = is_wp_error( $post_id ) ? $post_id->get_error_message() : __( 'Could not create the page.', 'fw' );
			$row['created'] = false;
			return $row;
		}

		$post_id     = (int) $post_id;
		$row['id']   = $post_id;
		$row['slug'] = (string) get_post_field( 'post_name', $post_id );

		// TARGETED RE-IMPORT (region scope): merge the reconverted sections INTO the existing page by
		// their original index, so the sections you did NOT reconvert stay exactly as they were, instead
		// of the whole page being replaced. Only when the page already exists (a re-import) and the bundle
		// flagged it partial with a section-index map.
		if ( ! empty( $spec['partial'] ) && ! empty( $existing ) && isset( $spec['scope_sections'] ) && is_array( $spec['scope_sections'] ) ) {
			$merged = self::merge_partial_tree( $post_id, $json, $spec['scope_sections'] );
			if ( $merged !== null ) { $json = $merged; $row['merged_sections'] = array_map( 'intval', $spec['scope_sections'] ); }
		}

		// Set the page-builder option. This fires fw_post_options_update, and the
		// page-builder extension regenerates post_content from the tree (its own
		// encoder) — we never touch post_content ourselves.
		fw_set_db_post_option( $post_id, self::OPTION_KEY, array(
			'json'           => (string) $json,
			'builder_active' => true,
		) );

		// Register any Tailwind-style arbitrary spacing values the converted page uses (e.g.
		// pt-[40px]) as named Spacing-Scale presets, so they surface in Theme Settings → Components →
		// Spacing AND show as the selected option in each section's spacing dropdown (durable on a
		// manual re-save). The tokens render via the per-page dynamic CSS regardless.
		if ( function_exists( 'unysonplus_register_arbitrary_spacing_scale' ) ) {
			$added = unysonplus_register_arbitrary_spacing_scale( (string) $json );
			if ( $added > 0 ) { $row['spacing_presets_added'] = $added; }
		}

		// Optional: set as the site's front page.
		if ( $front ) {
			update_option( 'show_on_front', 'page' );
			update_option( 'page_on_front', $post_id );
			$row['front_page'] = true;
		}

		return $row;
	}

	/**
	 * Rewrite source image URLs in a builder-tree JSON string to the local
	 * attachment URLs of the imported media (matched by the `_unysonplus_source_url`
	 * postmeta the media engine sets). Re-encodes with unescaped slashes first so a
	 * single URL form is matched, then delegates to the media engine's rewriter.
	 *
	 * @param string $json
	 * @return string
	 */
	/**
	 * Merge a targeted re-import's sections INTO the existing page's builder tree by original index.
	 * `$scope_sections[k]` is the original s_index of incoming section `k`, so incoming[k] replaces the
	 * existing top-level section at that position; every other existing section is left untouched. Returns
	 * the merged JSON string, or null if there's no usable existing tree (caller then imports normally).
	 *
	 * @param int    $post_id        the existing page
	 * @param string $incoming_json  the reconverted (partial) tree, JSON string
	 * @param array  $scope_sections original indices, parallel to the incoming sections
	 * @return string|null
	 */
	private static function merge_partial_tree( $post_id, $incoming_json, array $scope_sections ) {
		if ( ! function_exists( 'fw_get_db_post_option' ) ) { return null; }
		$existing_opt  = fw_get_db_post_option( (int) $post_id, self::OPTION_KEY, null );
		$existing_json = ( is_array( $existing_opt ) && isset( $existing_opt['json'] ) ) ? (string) $existing_opt['json'] : '';
		if ( trim( $existing_json ) === '' || trim( $existing_json ) === '[]' ) { return null; }
		$existing = json_decode( $existing_json, true );
		$incoming = json_decode( (string) $incoming_json, true );
		if ( ! is_array( $existing ) || ! is_array( $incoming ) ) { return null; }
		$existing = array_values( $existing );
		$incoming = array_values( $incoming );
		foreach ( $incoming as $k => $node ) {
			$idx = isset( $scope_sections[ $k ] ) ? (int) $scope_sections[ $k ] : -1;
			if ( $idx < 0 ) { continue; }
			if ( $idx < count( $existing ) ) { $existing[ $idx ] = $node; } // replace that section in place
			else { $existing[] = $node; }                                    // beyond the end → append
		}
		return wp_json_encode( array_values( $existing ) );
	}

	private static function resolve_media_urls( $json ) {
		if ( ! class_exists( 'FW_Site_Converter_Media' ) || ! function_exists( 'wp_get_attachment_url' ) ) {
			return $json;
		}

		// Re-encode with unescaped slashes so a single URL form is matched, then delegate to
		// the media engine's localizer (handles <img src>, srcset and CSS url(...), incl. svg
		// + query strings — so code-block HTML, carousel slide atts and section custom_css all
		// get their images re-pointed to the imported attachments).
		$decoded = json_decode( $json, true );
		if ( is_array( $decoded ) ) {
			// Fill the attachment_id of every upload-shaped media value ({ attachment_id, url }) —
			// hero background VIDEO (source_mp4 / source_webm / poster) and section image src — from the
			// sideloaded copy. localize() below only rewrites image URL *strings* (never the id, and its
			// regex is image-extensions-only), so a video imported url-only rendered as a blank "Upload"
			// field in the backend even though the frontend played it. This makes the media picker SHOW it.
			$decoded = self::resolve_upload_ids( $decoded );
			$work    = (string) wp_json_encode( $decoded, JSON_UNESCAPED_SLASHES );
		} else {
			$work = (string) $json;
		}

		return FW_Site_Converter_Media::localize( $work );
	}

	/**
	 * Recursively resolve upload-shaped media values ({ attachment_id, url }) whose id is empty: look up
	 * the sideloaded attachment by its ORIGINAL source URL (SOURCE_META) — or the local URL — and set
	 * BOTH the attachment_id and the (protocol-relative local) url. No-op for values that already carry
	 * an id or have no url, so it never disturbs already-resolved media.
	 *
	 * @param mixed $node
	 * @return mixed
	 */
	private static function resolve_upload_ids( $node ) {
		if ( ! is_array( $node ) ) {
			return $node;
		}

		if ( array_key_exists( 'url', $node ) && array_key_exists( 'attachment_id', $node )
			&& empty( $node['attachment_id'] ) && is_string( $node['url'] ) && trim( $node['url'] ) !== '' ) {
			$url        = trim( $node['url'] );
			$candidates = array( $url );
			if ( strpos( $url, '//' ) === 0 ) {                 // protocol-relative → try both schemes
				$candidates[] = 'https:' . $url;
				$candidates[] = 'http:' . $url;
			}
			$id = 0;
			foreach ( $candidates as $c ) {
				$id = FW_Site_Converter_Media::find_by_source( $c );
				if ( ! $id ) {
					$bare = preg_replace( '/\?.*$/', '', $c );
					if ( $bare !== $c ) { $id = FW_Site_Converter_Media::find_by_source( $bare ); }
				}
				if ( $id ) { break; }
			}
			if ( ! $id && function_exists( 'attachment_url_to_postid' ) ) {
				foreach ( $candidates as $c ) { $id = attachment_url_to_postid( $c ); if ( $id ) { break; } }
			}
			if ( $id ) {
				$node['attachment_id'] = (string) $id;
				$local = wp_get_attachment_url( $id );
				if ( $local ) { $node['url'] = preg_replace( '#^https?://#', '//', $local ); }
			}
		}

		foreach ( $node as $k => $v ) {
			$node[ $k ] = self::resolve_upload_ids( $v );
		}
		return $node;
	}

	/**
	 * First non-empty value among $keys in $arr, else $default.
	 *
	 * @param array $arr
	 * @param array $keys
	 * @param mixed $default
	 * @return mixed
	 */
	private static function pluck( array $arr, array $keys, $default ) {
		foreach ( $keys as $k ) {
			if ( isset( $arr[ $k ] ) && $arr[ $k ] !== '' ) {
				return $arr[ $k ];
			}
		}
		return $default;
	}

	/**
	 * @param array $arr
	 * @return bool Whether $arr is a sequential list (vs an associative map).
	 */
	private static function is_list( array $arr ) {
		if ( $arr === array() ) {
			return false;
		}
		return array_keys( $arr ) === range( 0, count( $arr ) - 1 );
	}
}
