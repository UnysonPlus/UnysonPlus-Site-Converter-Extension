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

		// Set the page-builder option. This fires fw_post_options_update, and the
		// page-builder extension regenerates post_content from the tree (its own
		// encoder) — we never touch post_content ourselves.
		fw_set_db_post_option( $post_id, self::OPTION_KEY, array(
			'json'           => (string) $json,
			'builder_active' => true,
		) );

		// Optional: set as the site's front page.
		if ( $front ) {
			update_option( 'show_on_front', 'page' );
			update_option( 'page_on_front', $post_id );
			$row['front_page'] = true;
		}

		return $row;
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
