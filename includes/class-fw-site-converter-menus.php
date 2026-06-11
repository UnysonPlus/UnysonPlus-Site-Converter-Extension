<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Site Converter — Menus importer (engine).
 *
 * Builds WordPress nav menus from a source site's navigation and assigns them to
 * the theme's menu locations (the conversion contract's recommended low-risk
 * chrome path, §3: "create the two WP menus from the source's nav/footer links
 * and assign them to primary/footer"). So a converted site's header + footer
 * navigation land in one step, using the theme's existing chrome.
 *
 * Static + side-effect-light so a future "Convert bundle" importer and a WP-CLI
 * command can reuse it (mirrors FW_Site_Converter_Media / FW_Site_Converter_Presets).
 *
 * Payload (forgiving — an agent can emit either shape):
 *
 *   { "menus": [
 *       { "name": "Primary", "location": "primary", "items": [
 *           { "label": "Home",    "url": "/" },
 *           { "label": "About",   "url": "/about" },
 *           { "label": "Services","url": "#", "children": [
 *               { "label": "Design", "url": "/services/design" }
 *           ] }
 *       ] },
 *       { "name": "Footer", "location": "footer", "items": [ … ] }
 *   ] }
 *
 * A single menu object (no `menus` wrapper) is accepted too. Item fields are
 * looked up leniently (label|title|text, url|href|link, children|items|sub).
 * Re-running is idempotent: a menu is matched by name and its items are rebuilt,
 * never duplicated.
 */
class FW_Site_Converter_Menus {

	/**
	 * Import one or more menus.
	 *
	 * @param array $data `{ menus: [ … ] }`, a bare `[ … ]` list of menu specs, or
	 *                    a single menu object. Keys starting with `_` are metadata.
	 * @return array{menus: array<int,array>, locations: array<string,string>, error: string}
	 */
	public static function import( $data ) {
		$out = array( 'menus' => array(), 'locations' => self::registered_locations(), 'error' => '' );

		if ( ! is_array( $data ) ) {
			$out['error'] = __( 'Invalid menus payload — expected a JSON object.', 'fw' );
			return $out;
		}
		if ( ! function_exists( 'wp_create_nav_menu' ) ) {
			$out['error'] = __( 'Nav-menu API is unavailable.', 'fw' );
			return $out;
		}

		// Normalize to a flat list of menu specs.
		if ( isset( $data['menus'] ) && is_array( $data['menus'] ) ) {
			$specs = $data['menus'];
		} elseif ( self::is_list( $data ) ) {
			$specs = $data; // already a list of menu specs
		} else {
			$specs = array( $data ); // a single menu object
		}

		foreach ( $specs as $spec ) {
			if ( ! is_array( $spec ) ) {
				continue;
			}
			$out['menus'][] = self::import_menu( $spec );
		}

		return $out;
	}

	/**
	 * Convenience: parse a raw JSON string then import.
	 *
	 * @param string $json
	 * @return array{menus: array, locations: array<string,string>, error: string}
	 */
	public static function import_json( $json ) {
		$json = trim( (string) $json );
		if ( $json === '' ) {
			return array( 'menus' => array(), 'locations' => self::registered_locations(), 'error' => __( 'Paste a menus JSON to import.', 'fw' ) );
		}
		$decoded = json_decode( $json, true );
		if ( ! is_array( $decoded ) ) {
			return array( 'menus' => array(), 'locations' => self::registered_locations(), 'error' => __( 'That is not valid JSON.', 'fw' ) );
		}
		return self::import( $decoded );
	}

	/**
	 * The theme's registered nav-menu locations (slug => human label).
	 *
	 * @return array<string,string>
	 */
	public static function registered_locations() {
		$locations = get_registered_nav_menus();
		return is_array( $locations ) ? $locations : array();
	}

	/* ---------------------------------------------------------------------- *
	 * Scanner — extract menus from a source page's HTML
	 * ---------------------------------------------------------------------- */

	/**
	 * Fetch a page and extract its header + footer navigation as a menus payload
	 * (the same shape import() consumes) so the user can review/edit it instead of
	 * hand-authoring JSON — the Media tool's scan-first UX, for navigation.
	 *
	 * Works on static / server-rendered sites; a pure client-rendered SPA exposes
	 * no nav in the static HTML (paste the JSON instead). Reuses the media engine's
	 * browser-UA fetch + URL absolutizer.
	 *
	 * @param string $url
	 * @return array{menus: array, source: string, error: string}
	 */
	public static function scan_page( $url ) {
		$url = esc_url_raw( trim( (string) $url ) );
		$out = array( 'menus' => array(), 'source' => $url, 'error' => '' );

		if ( $url === '' || ! preg_match( '#^https?://#i', $url ) ) {
			$out['error'] = __( 'Enter a valid http(s) page URL to scan.', 'fw' );
			return $out;
		}

		$resp = wp_remote_get( $url, array(
			'timeout'    => 20,
			'user-agent' => FW_Site_Converter_Media::BROWSER_UA,
		) );
		if ( is_wp_error( $resp ) ) {
			$out['error'] = $resp->get_error_message();
			return $out;
		}
		if ( (int) wp_remote_retrieve_response_code( $resp ) >= 400 ) {
			$out['error'] = sprintf( __( 'HTTP %d fetching the page.', 'fw' ), (int) wp_remote_retrieve_response_code( $resp ) );
			return $out;
		}

		$html = (string) wp_remote_retrieve_body( $resp );
		if ( trim( $html ) === '' ) {
			$out['error'] = __( 'The page returned no HTML.', 'fw' );
			return $out;
		}

		$out['menus'] = self::extract_menus( $html, $url );
		if ( ! $out['menus'] ) {
			$out['error'] = __( 'No navigation found in the page HTML. If this is a JavaScript app, the nav is rendered at runtime — paste the menus JSON instead.', 'fw' );
		}
		return $out;
	}

	/**
	 * Parse a page's HTML into menu specs: a Primary menu (the richest nav-ish
	 * container) and, when present, a Footer menu (the footer's links, flattened).
	 *
	 * @param string $html
	 * @param string $base Absolute base URL for resolving relative hrefs.
	 * @return array<int,array>
	 */
	public static function extract_menus( $html, $base = '' ) {
		if ( ! class_exists( 'DOMDocument' ) ) {
			return array();
		}

		$prev = libxml_use_internal_errors( true );
		$dom  = new DOMDocument();
		// The XML PI hints UTF-8 so labels don't mojibake.
		$dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		$xpath = new DOMXPath( $dom );
		$menus = array();

		// Primary: among header-nav / nav / [role=navigation] / header, keep the
		// container that yields the most top-level items.
		$best = array();
		foreach ( array( '//header//nav', '//nav', '//*[@role="navigation"]', '//header' ) as $q ) {
			$nodes = $xpath->query( $q );
			if ( ! $nodes ) {
				continue;
			}
			foreach ( $nodes as $node ) {
				$items = self::parse_nav( $node, $base );
				if ( count( $items ) > count( $best ) ) {
					$best = $items;
				}
			}
		}
		if ( $best ) {
			$loc     = array_key_exists( 'primary', self::registered_locations() ) ? 'primary' : '';
			$menus[] = array( 'name' => 'Primary', 'location' => $loc, 'items' => $best );
		}

		// Footer: flatten the footer's links (footers rarely map to nesting).
		$footers = $xpath->query( '//footer' );
		if ( $footers && $footers->length ) {
			$footer_items = self::flat_links( $footers->item( 0 ), $base, 40 );
			if ( $footer_items ) {
				$loc     = array_key_exists( 'footer', self::registered_locations() ) ? 'footer' : '';
				$menus[] = array( 'name' => 'Footer', 'location' => $loc, 'items' => $footer_items );
			}
		}

		return $menus;
	}

	/** Parse a nav container: its richest <ul> menu, else its flat links. */
	private static function parse_nav( $container, $base ) {
		$best_ul = null;
		$best    = 0;
		foreach ( $container->getElementsByTagName( 'ul' ) as $ul ) {
			$n = count( self::direct_children( $ul, 'li' ) );
			if ( $n > $best ) {
				$best    = $n;
				$best_ul = $ul;
			}
		}
		return $best_ul ? self::parse_ul( $best_ul, $base, 0 ) : self::flat_links( $container, $base, 30 );
	}

	/** Walk a <ul>'s direct <li> children into items, recursing into submenus. */
	private static function parse_ul( $ul, $base, $depth ) {
		if ( $depth > 4 ) {
			return array();
		}
		$items = array();
		foreach ( self::direct_children( $ul, 'li' ) as $li ) {
			$a     = self::own_anchor( $li );
			$label = self::clean_text( $a ? $a->textContent : self::direct_text( $li ) );
			$url   = $a ? self::href( $a, $base ) : '';
			if ( $label === '' && ( $url === '' || $url === '#' ) ) {
				continue; // icon-only / decorative
			}
			if ( $label === '' ) {
				$label = $url;
			}
			$item = array( 'label' => $label, 'url' => $url !== '' ? $url : '#' );

			$sub = self::own_sub_ul( $li );
			if ( $sub ) {
				$children = self::parse_ul( $sub, $base, $depth + 1 );
				if ( $children ) {
					$item['children'] = $children;
				}
			}
			$items[] = $item;
			if ( count( $items ) >= 50 ) {
				break;
			}
		}
		return $items;
	}

	/** Collect a container's <a> links as a flat, de-duped item list. */
	private static function flat_links( $container, $base, $cap ) {
		$items = array();
		$seen  = array();
		foreach ( $container->getElementsByTagName( 'a' ) as $a ) {
			$label = self::clean_text( $a->textContent );
			if ( $label === '' ) {
				continue; // skip icon-only links
			}
			$url = self::href( $a, $base );
			$key = strtolower( $label ) . '|' . $url;
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$items[]      = array( 'label' => $label, 'url' => $url !== '' ? $url : '#' );
			if ( count( $items ) >= $cap ) {
				break;
			}
		}
		return $items;
	}

	/** Direct element children of $node named $tag. */
	private static function direct_children( $node, $tag ) {
		$out = array();
		foreach ( $node->childNodes as $c ) {
			if ( $c->nodeType === XML_ELEMENT_NODE && strtolower( $c->nodeName ) === $tag ) {
				$out[] = $c;
			}
		}
		return $out;
	}

	/** The <li>'s own label anchor — the first <a> not inside a submenu <ul>. */
	private static function own_anchor( $li ) {
		foreach ( $li->getElementsByTagName( 'a' ) as $a ) {
			if ( ! self::within( $a, 'ul', $li ) ) {
				return $a;
			}
		}
		return null;
	}

	/** The <li>'s immediate submenu <ul> (not one nested deeper in a child li). */
	private static function own_sub_ul( $li ) {
		foreach ( $li->getElementsByTagName( 'ul' ) as $ul ) {
			if ( ! self::within( $ul, 'ul', $li ) ) {
				return $ul;
			}
		}
		return null;
	}

	/** Whether $node has an ancestor named $tag below (exclusive of) $stop. */
	private static function within( $node, $tag, $stop ) {
		$p = $node->parentNode;
		while ( $p && $p !== $stop ) {
			if ( strtolower( $p->nodeName ) === $tag ) {
				return true;
			}
			$p = $p->parentNode;
		}
		return false;
	}

	/** Absolute href for an <a>, or '#' for empty / javascript: / pure anchors. */
	private static function href( $a, $base ) {
		$href = trim( (string) $a->getAttribute( 'href' ) );
		if ( $href === '' || $href === '#' || stripos( $href, 'javascript:' ) === 0 ) {
			return '#';
		}
		return FW_Site_Converter_Media::absolutize( $href, $base );
	}

	/** Direct text-node content of $node (not descendants). */
	private static function direct_text( $node ) {
		$t = '';
		foreach ( $node->childNodes as $c ) {
			if ( $c->nodeType === XML_TEXT_NODE ) {
				$t .= $c->nodeValue;
			}
		}
		return $t;
	}

	/** Collapse whitespace, decode entities, trim, cap length. */
	private static function clean_text( $text ) {
		$text = preg_replace( '/\s+/', ' ', (string) $text );
		$text = trim( html_entity_decode( $text, ENT_QUOTES, 'UTF-8' ) );
		if ( function_exists( 'mb_strlen' ) && mb_strlen( $text ) > 80 ) {
			$text = rtrim( mb_substr( $text, 0, 80 ) );
		}
		return $text;
	}

	/* ---------------------------------------------------------------------- *
	 * Internals
	 * ---------------------------------------------------------------------- */

	/**
	 * Create/refresh one menu and (when possible) assign it to a location.
	 *
	 * @param array $spec One menu spec.
	 * @return array{name: string, location: string, assigned: bool, items: int, created: bool, error: string}
	 */
	private static function import_menu( array $spec ) {
		$name     = trim( (string) self::pluck( $spec, array( 'name', 'title', 'label' ), '' ) );
		$location = sanitize_key( (string) self::pluck( $spec, array( 'location', 'slot', 'theme_location' ), '' ) );
		$items    = self::pluck( $spec, array( 'items', 'links', 'children' ), array() );

		$row = array( 'name' => $name, 'location' => $location, 'assigned' => false, 'items' => 0, 'created' => false, 'error' => '' );

		if ( $name === '' ) {
			$row['error'] = __( 'A menu has no name — skipped.', 'fw' );
			return $row;
		}

		// Get-or-create the menu (matched by name), then clear its items so a
		// re-run rebuilds rather than duplicates.
		$existing = wp_get_nav_menu_object( $name );
		if ( $existing ) {
			$menu_id = (int) $existing->term_id;
			$old     = wp_get_nav_menu_items( $menu_id, array( 'post_status' => 'any' ) );
			if ( is_array( $old ) ) {
				foreach ( $old as $it ) {
					wp_delete_post( $it->ID, true );
				}
			}
		} else {
			$menu_id = wp_create_nav_menu( $name );
			if ( is_wp_error( $menu_id ) ) {
				$row['error'] = $menu_id->get_error_message();
				return $row;
			}
			$menu_id        = (int) $menu_id;
			$row['created'] = true;
		}

		$row['items'] = self::add_items( $menu_id, is_array( $items ) ? $items : array(), 0 );

		// Assign to a location. Infer from the name when not given (Primary→primary,
		// Footer→footer) but only if the theme actually registers that location.
		if ( $location === '' ) {
			$location          = self::infer_location( $name );
			$row['location']   = $location;
		}
		if ( $location !== '' && array_key_exists( $location, self::registered_locations() ) ) {
			$locations = get_theme_mod( 'nav_menu_locations', array() );
			if ( ! is_array( $locations ) ) {
				$locations = array();
			}
			$locations[ $location ] = $menu_id;
			set_theme_mod( 'nav_menu_locations', $locations );
			$row['assigned'] = true;
		}

		return $row;
	}

	/**
	 * Add a list of items (recursively for children) under a parent.
	 *
	 * @param int   $menu_id
	 * @param array $items
	 * @param int   $parent_id Parent menu-item DB id (0 = top level).
	 * @return int Number of items created (including descendants).
	 */
	private static function add_items( $menu_id, array $items, $parent_id ) {
		$count = 0;

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$label = trim( (string) self::pluck( $item, array( 'label', 'title', 'text', 'name' ), '' ) );
			$url   = trim( (string) self::pluck( $item, array( 'url', 'href', 'link' ), '' ) );
			if ( $label === '' && $url === '' ) {
				continue;
			}
			if ( $label === '' ) {
				$label = $url;
			}

			$target = self::resolve_target( $url );

			$args = array(
				'menu-item-title'     => $label,
				'menu-item-status'    => 'publish',
				'menu-item-parent-id' => (int) $parent_id,
			);
			if ( $target['type'] === 'post_type' ) {
				$args['menu-item-type']      = 'post_type';
				$args['menu-item-object']    = 'page';
				$args['menu-item-object-id'] = (int) $target['object_id'];
			} else {
				$args['menu-item-type'] = 'custom';
				$args['menu-item-url']  = $target['url'];
			}

			$item_id = wp_update_nav_menu_item( $menu_id, 0, $args );
			if ( is_wp_error( $item_id ) || ! $item_id ) {
				continue;
			}
			$count++;

			$children = self::pluck( $item, array( 'children', 'items', 'sub', 'submenu' ), array() );
			if ( is_array( $children ) && $children ) {
				$count += self::add_items( $menu_id, $children, (int) $item_id );
			}
		}

		return $count;
	}

	/**
	 * Decide whether a link should become a real Page menu item (internal link
	 * matching an existing page) or a plain custom link.
	 *
	 * @param string $url
	 * @return array{type: string, url?: string, object_id?: int}
	 */
	private static function resolve_target( $url ) {
		$url = trim( (string) $url );
		if ( $url === '' || $url === '#' ) {
			return array( 'type' => 'custom', 'url' => '#' );
		}

		$parts    = wp_parse_url( $url );
		$host      = isset( $parts['host'] ) ? $parts['host'] : '';
		$path      = isset( $parts['path'] ) ? trim( $parts['path'], '/' ) : '';
		$fragment  = isset( $parts['fragment'] ) ? $parts['fragment'] : '';
		$is_internal = $host === '' || self::same_host( $host );

		// Internal path with no on-page anchor → try to match an existing page.
		if ( $is_internal && $path !== '' && $fragment === '' ) {
			$page = get_page_by_path( $path );
			if ( $page ) {
				return array( 'type' => 'post_type', 'object_id' => (int) $page->ID );
			}
			// Internal but no matching page → keep it site-relative so it works on
			// the new domain (drop the source host).
			return array( 'type' => 'custom', 'url' => '/' . $path . ( $fragment !== '' ? '#' . $fragment : '' ) );
		}

		// Internal root or pure anchor.
		if ( $is_internal && $path === '' ) {
			return array( 'type' => 'custom', 'url' => $fragment !== '' ? '#' . $fragment : home_url( '/' ) );
		}

		// External link — leave as-is.
		return array( 'type' => 'custom', 'url' => $url );
	}

	/**
	 * @param string $host
	 * @return bool Whether $host is this site's host.
	 */
	private static function same_host( $host ) {
		$site = wp_parse_url( home_url(), PHP_URL_HOST );
		return $site && strcasecmp( ltrim( $host, 'www.' ), ltrim( (string) $site, 'www.' ) ) === 0;
	}

	/**
	 * Guess a menu location slug from the menu name, for when none is given.
	 *
	 * @param string $name
	 * @return string A registered location slug, or '' if no confident match.
	 */
	private static function infer_location( $name ) {
		$n         = strtolower( $name );
		$locations = self::registered_locations();

		$want = '';
		if ( strpos( $n, 'footer' ) !== false ) {
			$want = 'footer';
		} elseif ( strpos( $n, 'primary' ) !== false || strpos( $n, 'main' ) !== false || strpos( $n, 'header' ) !== false || strpos( $n, 'nav' ) !== false ) {
			$want = 'primary';
		}

		return ( $want !== '' && array_key_exists( $want, $locations ) ) ? $want : '';
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
