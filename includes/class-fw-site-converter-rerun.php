<?php if ( ! defined( 'FW' ) ) { die( 'Forbidden' ); }

/**
 * RE-RUN ONE PAGE — redo a single converted page without touching the rest of the site.
 *
 * WHY THIS EXISTS. A conversion was all-or-nothing. If one page came out wrong — or the converter itself
 * improved afterwards — the only options were to fix that page by hand (lost on the next conversion) or
 * reconvert the whole site (which re-derives the design system and discards every other page's state).
 * Neither is proportionate to "this one page is wrong".
 *
 * It could not be offered earlier for a plain reason: a converted page carried no record of the URL it was
 * built from. The bundle knew, and threw it away. Pages now store `_upw_source_url`, so a page can point
 * back at its own source and be rebuilt from it.
 *
 * WHAT IT DOES, AND DOES NOT, DO. This is the DETERMINISTIC converter, not an AI pass: the same engine
 * that produced the page, run again on a fresh capture of the same URL. That is what you want after a
 * converter fix, and it is repeatable — the same source and the same converter give the same page.
 *
 *   - It rebuilds ONE page's content.
 *   - It does NOT re-derive the design system, theme, header, footer, menus or Theme Settings. Re-deriving
 *     those per page is exactly what made "the last page converted decides how the whole site looks".
 *   - It DOES overwrite that page's builder content. Hand edits to that page are lost, which is why the
 *     caller is told so before it runs; corrections that must survive belong in the sandbox.
 *
 * The AI refine pass (`/refine-visual`) is a different tool and lives elsewhere: it writes CSS on top of a
 * converted page and keeps it only when the measured drift improves. Re-run fixes what the converter got
 * wrong; refine fixes what the converter cannot express.
 */
class FW_Site_Converter_Rerun {

	/** Post meta holding the source URL a converted page was built from. */
	const META_SOURCE = '_upw_source_url';

	/**
	 * Every converted page that knows where it came from, newest first.
	 *
	 * @return array[] { id, title, slug, url (permalink), source_url, edit }
	 */
	public static function pages() {
		$ids = get_posts( array(
			'post_type'      => 'page',
			'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
			'numberposts'    => 200,
			'fields'         => 'ids',
			'meta_key'       => self::META_SOURCE,
			'orderby'        => 'menu_order title',
			'order'          => 'ASC',
			'suppress_filters' => false,
		) );
		$out = array();
		foreach ( (array) $ids as $id ) {
			$src = (string) get_post_meta( $id, self::META_SOURCE, true );
			if ( '' === $src ) { continue; }
			$out[] = array(
				'id'         => (int) $id,
				'title'      => (string) get_the_title( $id ),
				'slug'       => (string) get_post_field( 'post_name', $id ),
				'url'        => (string) get_permalink( $id ),
				'source_url' => $src,
				'is_front'   => ( (int) get_option( 'page_on_front' ) === (int) $id ),
			);
		}
		return $out;
	}

	/**
	 * Confine a block of CSS to ONE page by prefixing every selector with that page's body class.
	 *
	 * The AI refine pass measures a single page and returns CSS that reduces THAT page's drift. Writing it
	 * to the child theme unscoped would apply it to every page on the site, where it was never measured and
	 * can only make things worse — a refine that improves /about by 4% and quietly degrades eight other
	 * pages is not an improvement, and nothing downstream would notice.
	 *
	 * The prefixing is deliberately conservative:
	 *   - `@media` / `@supports` and other block at-rules are recursed INTO, so their inner selectors get
	 *     scoped while the at-rule itself stays at the top level (prefixing `@media` produces dead CSS).
	 *   - `@keyframes` / `@font-face` and friends are passed through UNTOUCHED: their contents are not
	 *     selectors, and scoping `0%`/`from` would silently destroy the animation.
	 *   - `body` / `html` / `:root` selectors are REWRITTEN rather than descended from, because
	 *     `body.page-id-7 body` matches nothing — a whole-page background rule would vanish.
	 *
	 * @param string $css   the CSS to confine
	 * @param string $scope a selector, e.g. `body.page-id-7`
	 * @return string
	 */
	public static function scope_css( $css, $scope ) {
		$css   = (string) $css;
		$scope = trim( (string) $scope );
		if ( '' === trim( $css ) || '' === $scope ) { return ''; }

		$out = '';
		$i   = 0;
		$len = strlen( $css );
		while ( $i < $len ) {
			// Read the prelude: everything up to the next '{' (a selector list, or an at-rule header).
			$brace = strpos( $css, '{', $i );
			if ( false === $brace ) { break; }                 // trailing junk — drop it rather than guess
			$prelude = trim( substr( $css, $i, $brace - $i ) );

			// Find this rule's matching close brace, counting nesting.
			$depth = 0;
			$j     = $brace;
			for ( ; $j < $len; $j++ ) {
				if ( '{' === $css[ $j ] ) { $depth++; }
				elseif ( '}' === $css[ $j ] ) { $depth--; if ( 0 === $depth ) { break; } }
			}
			if ( $j >= $len ) { break; }                        // unbalanced — stop instead of emitting rubbish
			$body = substr( $css, $brace + 1, $j - $brace - 1 );
			$i    = $j + 1;

			if ( '' === $prelude ) { continue; }

			if ( '@' === $prelude[0] ) {
				$name = strtolower( preg_replace( '/^@([a-z-]+).*$/is', '$1', $prelude ) );
				// Contents are not selectors — pass straight through.
				if ( in_array( $name, array( 'keyframes', '-webkit-keyframes', 'font-face', 'page', 'counter-style', 'property' ), true ) ) {
					$out .= $prelude . '{' . $body . '}' . "\n";
					continue;
				}
				// A conditional group: keep the at-rule where it is and scope what is inside it.
				$out .= $prelude . '{' . "\n" . self::scope_css( $body, $scope ) . '}' . "\n";
				continue;
			}

			$sels = array();
			foreach ( explode( ',', $prelude ) as $sel ) {
				$sel = trim( preg_replace( '/\s+/', ' ', $sel ) );
				if ( '' === $sel ) { continue; }
				// Rewrite rather than descend: `body.page-id-7 body` matches nothing, so a whole-page
				// background rule would silently vanish.
				//
				// What follows the tag decides how it rejoins: WHITESPACE is a descendant combinator
				// (`body .x` → `<scope> .x`), while `.` / `:` / `#` / `[` is a compound on the same element
				// (`html.dark` → `<scope>.dark`). Reading only the first character of the remainder
				// conflates the two, which turned `body .x` into `<scope>.x` — a rule matching nothing.
				if ( preg_match( '/^(?:html|body|:root)((?:[.:#\[][^\s]*)?)(?:\s+(.*))?$/i', $sel, $m ) ) {
					$compound = isset( $m[1] ) ? $m[1] : '';
					$desc     = isset( $m[2] ) ? trim( $m[2] ) : '';
					$sels[]   = $scope . $compound . ( '' !== $desc ? ' ' . $desc : '' );
					continue;
				}
				$sels[] = $scope . ' ' . $sel;
			}
			if ( ! $sels ) { continue; }
			$out .= implode( ', ', $sels ) . '{' . $body . '}' . "\n";
		}
		return $out;
	}

	/**
	 * Rebuild one page from freshly captured HTML.
	 *
	 * The CAPTURE is not done here — the browser already holds the capture service connection, so the admin
	 * fetches the HTML and posts it in. That keeps this side free of any outbound HTTP, which matters: a
	 * WordPress admin request that reaches out to an arbitrary URL on a user's say-so is an SSRF waiting to
	 * be reported, and the capture service is the component that is supposed to fetch things.
	 *
	 * @param int    $post_id the converted page to rebuild
	 * @param string $html    the freshly captured DOM for its source URL
	 * @return array { ok, error?, sections? }
	 */
	public static function rebuild( $post_id, $html ) {
		$post_id = (int) $post_id;
		$post    = $post_id ? get_post( $post_id ) : null;
		if ( ! $post || 'page' !== $post->post_type ) {
			return array( 'ok' => false, 'error' => __( 'That page no longer exists.', 'fw' ) );
		}
		$src = (string) get_post_meta( $post_id, self::META_SOURCE, true );
		if ( '' === $src ) {
			return array( 'ok' => false, 'error' => __( 'This page has no recorded source URL, so there is nothing to re-run it from. Convert it again from the Convert panel.', 'fw' ) );
		}
		$html = (string) $html;
		if ( '' === trim( $html ) ) {
			return array( 'ok' => false, 'error' => __( 'The capture returned nothing.', 'fw' ) );
		}
		// A capture without computed-style stamps is a raw fetch, not a render. Building from it produces a
		// page that looks nothing like the source, which reads as "the re-run broke my page" — refuse it
		// instead, and say which component is missing.
		if ( false === stripos( $html, 'data-sc-cs' ) ) {
			return array( 'ok' => false, 'error' => __( 'That capture carries no computed styles — it was fetched rather than rendered. Start the capture service and try again.', 'fw' ) );
		}
		if ( ! class_exists( 'FW_Site_Converter_Sources' ) || ! class_exists( 'FW_Site_Converter_Pages' ) ) {
			return array( 'ok' => false, 'error' => __( 'The converter is not available.', 'fw' ) );
		}

		$res   = FW_Site_Converter_Sources::build_from_html( $html, (string) get_the_title( $post_id ), array(
			'dynamic_chrome' => true,
			'hifi_css'       => true,
			'source_url'     => $src,
		) );
		$built = ( is_array( $res ) && isset( $res['files']['pages.json'] ) ) ? $res['files']['pages.json'] : null;
		$list  = is_array( $built ) && isset( $built['pages'] ) ? $built['pages'] : $built;
		if ( ! is_array( $list ) || ! $list ) {
			return array( 'ok' => false, 'error' => __( 'The rebuild produced no page.', 'fw' ) );
		}

		// Pin the rebuilt page to THIS post: the build derives a slug from the source URL, and letting that
		// through would create a second page rather than replacing the one asked for.
		$page             = reset( $list );
		$page['slug']     = (string) get_post_field( 'post_name', $post_id );
		$page['title']    = (string) get_the_title( $post_id );
		$page['front_page'] = ( (int) get_option( 'page_on_front' ) === $post_id );
		$page['source_url'] = $src;

		// `import()` takes the ARRAY; `import_json()` takes a JSON string and quietly stringifies an array
		// to "Array" if handed one — which is exactly what happened here first time, and the failure was
		// invisible because the success check below used to accept an empty result as success.
		// Rebuilt in the builder that wrote it: a page converted into another output target is re-run into that
		// target, from the same rebuild's analysis pinned to this post, never silently turned back into
		// page-builder content.
		$tslug  = (string) get_post_meta( $post_id, '_fw_sc_target', true );
		$target = ( '' !== $tslug && class_exists( 'FW_SC_Targets' ) ) ? FW_SC_Targets::resolve( $tslug ) : null;
		if ( $target && 'page-builder' !== $target->slug() ) {
			$mapping = isset( $res['mapping'] ) && is_array( $res['mapping'] ) ? $res['mapping'] : array( 'pages' => array() );
			$mpage   = isset( $mapping['pages'][0] ) && is_array( $mapping['pages'][0] ) ? $mapping['pages'][0] : array();
			foreach ( array( 'slug', 'title', 'front_page', 'source_url' ) as $k ) { $mpage[ $k ] = $page[ $k ]; }
			$imported = $target->import_pages( array( 'pages' => array( $mpage ) ), array( 'pages' => array( $page ) ), array( 'source_url' => $src ) );
		} else {
			$imported = FW_Site_Converter_Pages::import( array( 'pages' => array( $page ) ) );
		}
		$rows     = ( is_array( $imported ) && ! empty( $imported['pages'] ) && is_array( $imported['pages'] ) )
			? $imported['pages'] : array();
		$row      = $rows ? reset( $rows ) : array();
		if ( ! empty( $imported['error'] ) ) {
			return array( 'ok' => false, 'error' => (string) $imported['error'] );
		}
		if ( ! empty( $row['error'] ) ) {
			return array( 'ok' => false, 'error' => (string) $row['error'] );
		}
		// A page id is the PROOF the import happened. Reporting success because nothing reported an error
		// is how a re-run that silently did nothing gets reported as "Rebuilt".
		$new_id = (int) ( $row['id'] ?? 0 );
		if ( ! $new_id ) {
			return array( 'ok' => false, 'error' => __( 'The rebuild ran but no page was written.', 'fw' ) );
		}
		return array(
			'ok'       => true,
			'id'       => $new_id,
			'sections' => is_array( $page['builder'] ?? null ) ? count( $page['builder'] ) : 0,
		);
	}
}
