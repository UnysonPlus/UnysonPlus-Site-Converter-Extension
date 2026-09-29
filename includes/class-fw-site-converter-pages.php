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

		// THE SOURCE'S PAGE HIERARCHY IS PART OF THE PAGE.
		//
		// Slugs came from the LAST path segment only, so /modular-home-financing/manufacturers converted to
		// /manufacturers and the source's structure was thrown away. Two costs, and the second is the one that
		// bit: every URL changed (so an inbound link or a bookmark to the source path lands nowhere), and two
		// pages under different parents collapsed onto one slug -- /construction-loans/fha and
		// /modular-home-financing/loan-options/fha both became 'fha', and one silently overwrote the other.
		//
		// WordPress pages are hierarchical natively, so the faithful conversion is a real post_parent chain:
		// the page keeps its leaf slug, its ancestors supply the rest of the path, and the converted permalink
		// matches the source's. Same-named pages under different parents stop colliding by construction, which
		// is a better answer than renaming one of them.
		$spec_src  = trim( (string) self::pluck( $spec, array( 'source_url', 'src_url' ), '' ) );
		$src_path  = '';
		if ( '' !== $spec_src && preg_match( '#^https?://#i', $spec_src ) ) {
			$src_path = trim( (string) wp_parse_url( $spec_src, PHP_URL_PATH ), '/' );
		}
		$parent_id = 0;
		if ( ! $front && '' !== $src_path && false !== strpos( $src_path, '/' ) ) {
			$segs      = array_values( array_filter( explode( '/', $src_path ) ) );
			$leaf      = array_pop( $segs );
			$parent_id = self::ensure_ancestors( $segs );
			// the leaf names the page; the ancestors carry the path
			if ( $parent_id > 0 ) { $slug_eff = sanitize_title( $leaf ); }
		}

		// Idempotent: match the page at this FULL path (a bare slug when there is no hierarchy) -> update,
		// else create. Matching on the full path is what makes two same-named pages under different parents
		// distinct: get_page_by_path() walks the parent chain, so 'construction-loans/fha' cannot resolve to
		// the page under loan-options.
		$lookup   = ( $parent_id > 0 && '' !== $src_path ) ? $src_path : $slug_eff;
		$existing = get_page_by_path( $lookup, OBJECT, 'page' );

		// Belt and braces for a page with NO hierarchy to fall back on (no source URL recorded, or a
		// single-segment path): a different source page must still never overwrite this one.
		if ( $existing && 0 === $parent_id && '' !== $spec_src ) {
			$prev_src = (string) get_post_meta( (int) $existing->ID, '_upw_source_url', true );
			if ( '' !== $prev_src && untrailingslashit( $prev_src ) !== untrailingslashit( $spec_src ) ) {
				$slug_eff = self::unique_page_slug( $slug_eff, $spec_src );
				$existing = get_page_by_path( $slug_eff, OBJECT, 'page' );
			}
		}

		$postarr = array(
			'post_type'   => 'page',
			'post_title'  => $title,
			'post_name'   => $slug_eff,
			'post_status' => $status,
			'post_parent' => (int) $parent_id,
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

		// THE WAY BACK TO THE SOURCE. A converted page used to carry no record of the URL it was built
		// from, so re-running one page meant remembering its source URL and pasting it in by hand — which
		// is why the results panel could only ever offer "convert the whole site again". Stored here, a
		// page can be re-run on its own from the results list.
		// The source's own SHORT crumb label for this page, when its trail supplied one. A trail renders page
		// TITLES, and a title is not a crumb label ('Prefab Home Manufacturers' vs 'Manufacturers').
		$crumb = trim( (string) self::pluck( $spec, array( 'crumb_label' ), '' ) );
		if ( '' !== $crumb && mb_strlen( $crumb ) <= 60 ) { update_post_meta( $post_id, '_upw_crumb_label', sanitize_text_field( $crumb ) ); }

		$src_url = trim( (string) self::pluck( $spec, array( 'source_url', 'src_url' ), '' ) );
		if ( '' !== $src_url && preg_match( '#^https?://#i', $src_url ) ) {
			update_post_meta( $post_id, '_upw_source_url', esc_url_raw( $src_url ) );
			$row['source_url'] = esc_url_raw( $src_url );
		}

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

		// Per-page layout options the build asked for (e.g. hide_site_footer = 'yes' when the source has no footer) — the
		// theme's own page switches, so the editor shows them and the user can flip them back.
		$popts = isset( $spec['page_options'] ) && is_array( $spec['page_options'] ) ? $spec['page_options'] : array();
		foreach ( $popts as $pk => $pv ) {
			if ( preg_match( '/^[a-z0-9_]+$/', (string) $pk ) && is_scalar( $pv ) ) { fw_set_db_post_option( $post_id, (string) $pk, $pv ); }
		}
		// The theme's header visibility is the per-page `page_header` select (Global / Transparent / Hidden = 'd-none'); its
		// `hide_site_header` switch is legacy and no longer read. A header-less source must hide the header through the select, else
		// the theme paints its default masthead over a page the source drew without one.
		if ( 'yes' === (string) ( $popts['hide_site_header'] ?? '' ) ) { fw_set_db_post_option( $post_id, 'page_header', 'd-none' ); }
		if ( $popts ) { $row['page_options'] = array_keys( $popts ); }
		// …and the chrome Hide switches this build did NOT ask for are reset: a re-imported page (same slug → the post is
		// updated, not recreated) otherwise keeps a previous conversion's `hide_site_footer = yes` — a source WITH a footer
		// rendered none because an earlier footer-less source had flipped the switch on this very post.
		foreach ( array( 'hide_site_footer', 'hide_site_header' ) as $hk ) {
			if ( ! array_key_exists( $hk, $popts ) && 'yes' === (string) fw_get_db_post_option( $post_id, $hk, '' ) ) { fw_set_db_post_option( $post_id, $hk, 'no' ); }
		}
		if ( ! array_key_exists( 'hide_site_header', $popts ) && 'd-none' === (string) fw_get_db_post_option( $post_id, 'page_header', '' ) ) { fw_set_db_post_option( $post_id, 'page_header', '' ); } // …and the select

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

	/**
	 * Record the SOURCE's own breadcrumb labels onto the pages they name.
	 *
	 * A trail renders page TITLES, and a title is not a crumb label: the source titled a page 'Prefab Home
	 * Manufacturers' and crumbed it 'Manufacturers'. Every captured trail hands over the labels for its own
	 * ancestors, so converting any deep page teaches the labels for everything above it; this writes each one
	 * onto the page at that path, where the trail filter picks it up.
	 *
	 * Idempotent, and it only ever writes a page that already exists -- it creates nothing.
	 *
	 * @param array  $map       path => label, plus an optional '@self' for the page just imported
	 * @param string $self_path the path '@self' belongs to ('' to ignore it)
	 * @return int how many labels were stored
	 */
	public static function apply_crumb_labels( $map, $self_path = '' ) {
		if ( ! is_array( $map ) || ! $map ) { return 0; }
		$n = 0;
		foreach ( $map as $path => $label ) {
			$label = trim( (string) $label );
			if ( '' === $label || mb_strlen( $label ) > 60 ) { continue; }
			$path = ( '@self' === $path ) ? trim( (string) $self_path, '/' ) : trim( (string) $path, '/' );
			if ( '' === $path ) { continue; }
			$page = get_page_by_path( $path, OBJECT, 'page' );
			if ( ! $page ) { continue; }
			update_post_meta( (int) $page->ID, '_upw_crumb_label', sanitize_text_field( $label ) );
			$n++;
		}
		return $n;
	}

	/**
	 * The page id that should parent a page at this ancestor path, creating placeholder ancestors as needed.
	 *
	 * A source's deep page (/modular-home-financing/manufacturers/unity-homes) needs every level above it to
	 * exist as a page, or WordPress cannot build the nested permalink. Ancestors that the conversion also
	 * captured get filled in with their real content whenever they are imported -- order does not matter,
	 * because this reuses an existing page at that path instead of making a second one.
	 *
	 * A level the capture never saw becomes a DRAFT placeholder: it holds the path open for its children
	 * without publishing an empty page into the site's navigation or sitemap. (On the source that prompted
	 * this, both such levels -- /authors and /compare -- turned out to be real pages that discovery had
	 * missed, so a placeholder is usually a signal that discovery came up short, not that the page is fake.)
	 *
	 * @param string[] $segs ancestor path segments, outermost first
	 * @return int parent page id, or 0 when there is no hierarchy to build
	 */
	private static function ensure_ancestors( array $segs ) {
		$parent = 0;
		$path   = '';
		foreach ( $segs as $seg ) {
			$seg = sanitize_title( $seg );
			if ( '' === $seg ) { continue; }
			$path = ( '' === $path ) ? $seg : $path . '/' . $seg;
			$page = get_page_by_path( $path, OBJECT, 'page' );
			if ( $page ) { $parent = (int) $page->ID; continue; }
			$id = wp_insert_post( array(
				'post_type'   => 'page',
				'post_title'  => ucwords( str_replace( '-', ' ', $seg ) ),
				'post_name'   => $seg,
				'post_status' => 'draft',
				'post_parent' => $parent,
			), true );
			if ( is_wp_error( $id ) || ! $id ) { return $parent; }
			update_post_meta( (int) $id, '_upw_path_placeholder', 1 );
			$parent = (int) $id;
		}
		return $parent;
	}

	/**
	 * A page slug not already taken by a DIFFERENT source page, disambiguated by the source path's ancestry.
	 *
	 * Climbs the source URL one ancestor at a time ('loan-options-fha', then 'modular-home-financing-...'), so
	 * the page that claimed the readable slug keeps it and the newcomer says where it came from. A numeric
	 * suffix is the last resort. Never returns a slug held by a page from a different source.
	 *
	 * @param string $base    the colliding slug
	 * @param string $src_url this page's source URL
	 * @return string
	 */
	private static function unique_page_slug( $base, $src_url ) {
		$taken = static function ( $slug ) use ( $src_url ) {
			$p = get_page_by_path( $slug, OBJECT, 'page' );
			if ( ! $p ) { return false; }
			$prev = (string) get_post_meta( (int) $p->ID, '_upw_source_url', true );
			// the same source may reuse its own slug (a re-convert updates in place)
			return ! ( '' !== $prev && untrailingslashit( $prev ) === untrailingslashit( $src_url ) );
		};
		$path = (string) wp_parse_url( $src_url, PHP_URL_PATH );
		$segs = array_values( array_filter( explode( '/', (string) $path ) ) );
		$n    = count( $segs );
		for ( $take = 2; $take <= $n; $take++ ) {
			$cand = sanitize_title( implode( '-', array_slice( $segs, -$take ) ) );
			if ( '' !== $cand && ! $taken( $cand ) ) { return $cand; }
		}
		for ( $i = 2; $i < 200; $i++ ) {
			$cand = sanitize_title( $base . '-' . $i );
			if ( ! $taken( $cand ) ) { return $cand; }
		}
		return $base;
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

		// Media shape { attachment_id, url } — hero video, section image src, gallery, etc.
		if ( array_key_exists( 'url', $node ) && array_key_exists( 'attachment_id', $node )
			&& empty( $node['attachment_id'] ) && is_string( $node['url'] ) && trim( $node['url'] ) !== '' ) {
			$id = self::attachment_id_for_url( $node['url'] );
			if ( $id ) {
				$node['attachment_id'] = (string) $id;
				$local = wp_get_attachment_url( $id );
				if ( $local ) { $node['url'] = preg_replace( '#^https?://#', '//', $local ); }
			}
		}

		// icon-v2 custom-upload shape { type:'custom-upload', url, 'attachment-id' } — an image icon
		// (e.g. a source SVG icon in a converted card grid). Different keys than the media shape above,
		// so resolve it explicitly: fill attachment-id + re-point url to the sideloaded local copy.
		if ( isset( $node['type'] ) && $node['type'] === 'custom-upload'
			&& array_key_exists( 'url', $node ) && is_string( $node['url'] ) && trim( $node['url'] ) !== ''
			&& empty( $node['attachment-id'] ) ) {
			$id = self::attachment_id_for_url( $node['url'] );
			if ( $id ) {
				$node['attachment-id'] = (int) $id;
				$local = wp_get_attachment_url( $id );
				if ( $local ) { $node['url'] = preg_replace( '#^https?://#', '//', $local ); }
			}
		}

		foreach ( $node as $k => $v ) {
			$node[ $k ] = self::resolve_upload_ids( $v );
		}

		// A BACKGROUND VIDEO GETS THE POSTER ITS SIDELOAD CUT FOR IT.
		//
		// The mapper runs long before any media exists, so it cannot set a poster the source never had --
		// and a converted backdrop with no poster shows nothing at all while the file downloads. The media
		// layer cuts one frame per video and links it by `_sc_video_poster`; this is where that becomes the
		// option value. Only fills an EMPTY poster, so a source that supplied its own always wins.
		self::fill_video_poster( $node );

		return $node;
	}

	/**
	 * Give a video option value the poster its sideload produced, when it has none of its own.
	 *
	 * Handles both shapes the converter emits: the section/site Background-Pro video
	 * ({ source_mp4, source_webm, poster }) and the media_video shortcode's self-hosted source
	 * ({ video_mp4, video_webm, poster }).
	 *
	 * @param array $node option subtree, by reference
	 * @return void
	 */
	private static function fill_video_poster( array &$node ) {
		if ( ! array_key_exists( 'poster', $node ) ) { return; }
		$have = is_array( $node['poster'] ) ? trim( (string) ( $node['poster']['url'] ?? '' ) ) : trim( (string) $node['poster'] );
		if ( '' !== $have ) { return; }                                   // the source had one — leave it
		$vid = 0;
		foreach ( array( 'source_mp4', 'video_file', 'video_mp4', 'source_webm', 'video_webm' ) as $k ) {
			if ( ! empty( $node[ $k ]['attachment_id'] ) ) { $vid = (int) $node[ $k ]['attachment_id']; break; }
		}
		if ( $vid <= 0 ) { return; }
		$pid = (int) get_post_meta( $vid, '_sc_video_poster', true );
		if ( $pid <= 0 ) { return; }
		$url = wp_get_attachment_url( $pid );
		if ( ! $url ) { return; }
		$node['poster'] = array( 'attachment_id' => (string) $pid, 'url' => preg_replace( '#^https?://#', '//', $url ) );
	}

	/**
	 * Find the sideloaded attachment id for a builder media URL — matches by original source URL
	 * (SOURCE_META, which now also indexes the root-relative path form) or, failing that, by the
	 * local attachment URL. Handles protocol-relative and cache-busting query variants. 0 if none.
	 *
	 * @param string $url
	 * @return int
	 */
	private static function attachment_id_for_url( $url ) {
		$url = trim( (string) $url );
		if ( $url === '' ) {
			return 0;
		}
		$candidates = array( $url );
		if ( strpos( $url, '//' ) === 0 ) {                 // protocol-relative → try both schemes
			$candidates[] = 'https:' . $url;
			$candidates[] = 'http:' . $url;
		}
		foreach ( $candidates as $c ) {
			$id = FW_Site_Converter_Media::find_by_source( $c );
			if ( ! $id ) {
				$bare = preg_replace( '/\?.*$/', '', $c );
				if ( $bare !== $c ) { $id = FW_Site_Converter_Media::find_by_source( $bare ); }
			}
			if ( $id ) { return (int) $id; }
		}
		if ( function_exists( 'attachment_url_to_postid' ) ) {
			foreach ( $candidates as $c ) {
				$id = attachment_url_to_postid( $c );
				if ( $id ) { return (int) $id; }
			}
		}
		return 0;
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
