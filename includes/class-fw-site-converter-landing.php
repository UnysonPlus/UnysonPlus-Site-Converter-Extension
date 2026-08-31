<?php
if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * "Duplicate as landing page" — import a VERBATIM SITE MIRROR (produced by the capture service's
 * mirror.mjs: a self-contained index.html + assets/ where every asset the page loaded was recorded
 * by the real browser and re-linked to relative paths) onto this WordPress site.
 *
 * It copies the mirror's assets into uploads/unysonplus/landing/<slug>/, rewrites the HTML's asset
 * links to the site URLs, and creates a Page whose builder content is a single
 *   section → column → code_block
 * carrying the source's own markup + styles + scripts VERBATIM — so the WebGL/three.js landing page
 * runs exactly as the original. The page template is set to Landing Page (no theme chrome) so a
 * full-viewport, scroll-hijacked experience isn't boxed by the header/footer.
 *
 * This is a FROZEN mirror — deliberately NOT decomposed into editable shortcodes. It's the right tool
 * for "grab this WebGL landing page as-is and host it on WP", with the rest of the site staying a
 * normal UnysonPlus build.
 */
class FW_Site_Converter_Landing {

	/**
	 * One-shot: ask the capture service to MIRROR a url (GET /mirror), then import the resulting folder
	 * as a landing Page. The service runs on the same machine (localhost:4600 by default), so it hands
	 * back a local dir the importer reads directly — no upload round-trip.
	 *
	 * @param string $url     the source landing page to duplicate
	 * @param string $title   page title (derived from the url path when empty)
	 * @param string $service capture-service base url (default http://localhost:8787, filterable)
	 * @return array same shape as import()
	 */
	public static function from_url( $url, $title = '', $service = '' ) {
		$url = trim( (string) $url );
		if ( ! preg_match( '#^https?://#i', $url ) ) { return array( 'ok' => false, 'error' => 'Provide a valid http(s) URL.' ); }
		$service = '' !== $service ? $service : apply_filters( 'fw_sc_capture_service_url', 'http://localhost:8787' );
		$resp = wp_remote_get( trailingslashit( $service ) . 'mirror?url=' . rawurlencode( $url ), array( 'timeout' => 240 ) );
		if ( is_wp_error( $resp ) ) { return array( 'ok' => false, 'error' => 'Capture service unreachable: ' . $resp->get_error_message() ); }
		$data = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
		if ( empty( $data['ok'] ) || empty( $data['dir'] ) ) { return array( 'ok' => false, 'error' => isset( $data['error'] ) ? (string) $data['error'] : 'The mirror service returned no folder.' ); }
		if ( '' === trim( (string) $title ) ) {
			$path  = (string) wp_parse_url( $url, PHP_URL_PATH );
			$stem  = sanitize_title( preg_replace( '/\.[a-z0-9]+$/i', '', basename( $path ) ) );
			$title = $stem !== '' ? ucwords( str_replace( array( '-', '_' ), ' ', $stem ) ) : 'Landing';
		}
		$dir = (string) $data['dir'];
		// Mode: 'auto' (default) inlines a native hero unless the source hijacks the window scroll;
		// 'inline' / 'sandbox' force one. Filterable so callers/devs can override.
		$mode  = apply_filters( 'fw_sc_landing_mode', 'auto', $url, $dir );
		$index = rtrim( $dir, "/\\" ) . '/index.html';
		$html  = is_file( $index ) ? (string) file_get_contents( $index ) : '';
		if ( 'sandbox' === $mode || ( 'auto' === $mode && '' !== $html && self::is_scroll_hijacked( $html ) ) ) {
			return self::import( $dir, $title );          // iframe — for genuinely scroll-jacked pages
		}
		return self::import_inline( $dir, $title );        // native inline hero (SEO + belongs to the page)
	}

	/**
	 * Import a mirror directory (containing index.html + assets/ + manifest.json) as a landing Page.
	 *
	 * @param string $mirror_dir absolute path to the mirror output folder
	 * @param string $title      page title
	 * @param array  $opts       { 'template' => 'page-landing.php', 'status' => 'publish', 'post_id' => 0 (update) }
	 * @return array { ok:bool, post_id:int, url:string, assets:int, error:string }
	 */
	public static function import( $mirror_dir, $title = 'Landing', $opts = array() ) {
		$out = array( 'ok' => false, 'post_id' => 0, 'url' => '', 'assets' => 0, 'error' => '' );

		$mirror_dir = rtrim( (string) $mirror_dir, "/\\" );
		$index      = $mirror_dir . '/index.html';
		if ( ! is_dir( $mirror_dir ) || ! is_file( $index ) ) {
			$out['error'] = 'Mirror folder or index.html not found.';
			return $out;
		}
		$title = trim( (string) $title ) !== '' ? (string) $title : 'Landing';
		$slug  = sanitize_title( $title );
		if ( '' === $slug ) { $slug = 'landing-' . substr( md5( $mirror_dir ), 0, 8 ); }

		// --- 1) uploads/unysonplus/landing/<slug>/ (shared helper → single unysonplus/ parent). ---
		if ( ! function_exists( 'fw_upw_uploads_dir' ) ) { $out['error'] = 'uploads helper unavailable.'; return $out; }
		$dest = fw_upw_uploads_dir( 'landing/' . $slug );
		$dest_path = rtrim( (string) $dest['path'], '/' );
		$dest_url  = rtrim( (string) $dest['url'], '/' );
		if ( ! wp_mkdir_p( $dest_path ) ) { $out['error'] = 'Could not create the landing uploads folder.'; return $out; }

		// --- 2) copy the WHOLE self-contained mirror (index.html + assets/) into uploads verbatim. ---
		// We serve it through an <iframe>, so it runs in its OWN document context — every CSS selector
		// (incl. body-rooted positioning), the scroll-hijack, and the WebGL work EXACTLY as the standalone
		// source, with zero theme-CSS conflicts. index.html's relative `assets/…` refs resolve against its
		// own URL, so NOTHING needs rewriting — a true verbatim copy.
		$copied = self::copy_tree( $mirror_dir, $dest_path );
		$out['assets'] = $copied;

		// --- 3) the code_block payload: the mirror's OWN HTML, kept RAW & EDITABLE in the block, rendered in
		// "Sandbox" mode (an <iframe> pointing at the pristine index.html we just copied). It runs in its own
		// document context (body-rooted CSS, scroll-hijack, WebGL all work) AND the text stays editable here in
		// the builder. The iframe serves the FILE (not the render-time att — wpautop would corrupt the inline
		// CSS/JS); on_save() re-writes that file from the edited code, so edits take effect. No <base> needed —
		// the file lives beside its assets/, so relative `assets/…` refs resolve.
		$code        = (string) file_get_contents( $index );
		$sandbox_src = $dest_url . '/index.html';
		$live_file   = $dest_path . '/index.html';

		// --- 5) build the page-builder tree: section → column → code_block (frozen mirror). ---
		$uid = function () { return md5( uniqid( '', true ) ); };
		$tree = array(
			array(
				'type' => 'section',
				'atts' => array(
					'variant' => '', 'is_fullwidth' => true,
					'min_height' => array( 'preset' => 'custom', 'custom' => array( 'custom_height' => array( 'value' => '100', 'unit' => 'vh' ) ) ),
					'content_valign' => 'top', 'text_align' => '',
					'padding_top' => '', 'padding_bottom' => '', 'gap' => '',
					'unique_id' => $uid(), 'css_id' => 'landing-mirror', 'css_class' => 'upw-landing-mirror',
					// Edge-to-edge: kill the container cap + all padding so the iframe fills the viewport.
					// (The section's own Min Height option above sets the 100vh height — so the user can
					// SHORTEN the mirror in the Section options to make room for content below it. We no
					// longer hard-force min-height here, which would override that option.)
					// A full-width section renders `.fw-container-FLUID` (not `.fw-container`), and that fluid
				// container keeps a 24px left/right padding — so match `[class*="fw-container"]` to reset BOTH
				// and take the hero truly edge-to-edge.
				'custom_css' => 'selector,selector [class*="fw-container"],selector .fw-row,selector [class*="fw-col-"]{max-width:none!important;padding:0!important;margin:0!important;}',
					'responsive_hide' => array(), 'custom_attrs' => array(),
				),
				'_items' => array(
					array(
						'type' => 'column', 'width' => '1_1',
						'atts' => array( 'unique_id' => $uid(), 'css_id' => '', 'css_class' => '', 'custom_css' => '', 'responsive_hide' => array(), 'custom_attrs' => array() ),
						'_items' => array(
							array(
								'type' => 'simple', 'shortcode' => 'code_block', '_items' => array(),
								'atts' => array(
									'code' => $code, 'render_mode' => array( 'mode' => 'sandbox', 'sandbox' => array( 'sandbox_src' => $sandbox_src ) ), 'render_as_code' => '', 'code_language' => 'html',
									// css_class lands on the sandbox <iframe> → the front-end lock CSS targets it.
									'unique_id' => $uid(), 'css_id' => '', 'css_class' => 'upw-landing-frame', 'custom_css' => '',
									'responsive_hide' => array(), 'custom_attrs' => array(),
								),
							),
						),
					),
				),
			),
		);
		$json = wp_json_encode( $tree );

		// --- 6) create (or update) the Page + wire the builder + the Landing Page template. ---
		$template = isset( $opts['template'] ) && '' !== $opts['template'] ? (string) $opts['template'] : self::pick_template();
		$status   = isset( $opts['status'] ) ? (string) $opts['status'] : 'publish';
		$post_id  = isset( $opts['post_id'] ) ? (int) $opts['post_id'] : 0;

		$postarr = array( 'post_title' => $title, 'post_type' => 'page', 'post_status' => $status, 'post_name' => $slug, 'post_content' => '' );
		if ( $post_id > 0 && get_post( $post_id ) ) { $postarr['ID'] = $post_id; $post_id = wp_update_post( $postarr, true ); }
		else { $post_id = wp_insert_post( $postarr, true ); }
		if ( is_wp_error( $post_id ) || ! $post_id ) { $out['error'] = is_wp_error( $post_id ) ? $post_id->get_error_message() : 'Could not create the page.'; return $out; }

		update_post_meta( $post_id, 'fw:opt:ext:pb:page-builder:json', wp_slash( $json ) );
		update_post_meta( $post_id, '_upw_import_hash', md5( $json ) );
		update_post_meta( $post_id, '_upw_landing_mirror', 1 ); // → frontend_head() emits the viewport-lock CSS (front-end only)
		update_post_meta( $post_id, '_upw_landing_file', $live_file ); // → on_save() re-writes this pristine file from the edited code
		update_post_meta( $post_id, '_wp_page_template', $template );
		// fw_options: the builder_active flag is what makes the theme render builder output (per page-builder AGENTS).
		$fw_options = get_post_meta( $post_id, 'fw_options', true );
		if ( ! is_array( $fw_options ) ) { $fw_options = array(); }
		$fw_options['page-builder'] = array( 'json' => $json, 'builder_active' => true );
		update_post_meta( $post_id, 'fw_options', $fw_options );

		$out['ok']      = true;
		$out['post_id'] = (int) $post_id;
		$out['url']     = get_permalink( $post_id );
		return $out;
	}

	/** Copy a directory tree; returns the number of files copied. */
	private static function copy_tree( $src, $dst ) {
		$n = 0;
		if ( ! is_dir( $src ) ) { return 0; }
		wp_mkdir_p( $dst );
		$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $src, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::SELF_FIRST );
		foreach ( $it as $item ) {
			$rel = ltrim( substr( $item->getPathname(), strlen( $src ) ), '/\\' );
			$target = $dst . '/' . $rel;
			if ( $item->isDir() ) { wp_mkdir_p( $target ); }
			else { if ( @copy( $item->getPathname(), $target ) ) { $n++; } }
		}
		return $n;
	}

	/**
	 * On save of a landing-mirror page: re-write the served file from the (pristine, stored) code so edits
	 * made to the Code Block take effect. Reads the code from the builder JSON meta — NOT the render-time
	 * value — so wpautop's <p>/<br> injection never reaches the file. The target path is the one recorded at
	 * import (inside uploads), re-validated to stay within the uploads dir before writing.
	 */
	public static function on_save( $post_id ) {
		$post_id = (int) $post_id;
		if ( ! $post_id || defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE || wp_is_post_revision( $post_id ) ) { return; }
		if ( ! get_post_meta( $post_id, '_upw_landing_mirror', true ) ) { return; }
		$file = (string) get_post_meta( $post_id, '_upw_landing_file', true );
		if ( '' === $file ) { return; }
		// Safety: only ever write inside the uploads dir.
		$uploads = wp_get_upload_dir();
		$base    = isset( $uploads['basedir'] ) ? wp_normalize_path( (string) $uploads['basedir'] ) : '';
		if ( '' === $base || 0 !== strpos( wp_normalize_path( $file ), $base ) ) { return; }
		$json = (string) get_post_meta( $post_id, 'fw:opt:ext:pb:page-builder:json', true );
		$tree = json_decode( $json, true );
		$code = self::first_code_block_code( is_array( $tree ) ? $tree : array() );
		if ( '' !== $code && is_dir( dirname( $file ) ) ) {
			file_put_contents( $file, $code );
		}
	}

	/** Depth-first: the `code` att of the first code_block node in a builder tree ('' if none). */
	private static function first_code_block_code( $nodes ) {
		foreach ( (array) $nodes as $n ) {
			if ( isset( $n['shortcode'] ) && 'code_block' === $n['shortcode'] && isset( $n['atts']['code'] ) ) {
				return (string) $n['atts']['code'];
			}
			if ( ! empty( $n['_items'] ) ) {
				$r = self::first_code_block_code( $n['_items'] );
				if ( '' !== $r ) { return $r; }
			}
		}
		return '';
	}

	/**
	 * FRONT-END ONLY (hooked on wp_head): on a page created by this importer (_upw_landing_mirror meta),
	 * make the mirror iframe a full-VIEWPORT-HEIGHT block that sits IN NORMAL FLOW — so the page still
	 * scrolls and any sections you add BELOW the mirror show up. (An earlier version pinned the iframe
	 * position:fixed + locked body overflow, which made the mirror cover everything and hid anything
	 * else on the page.) The admin-bar height offset avoids a stray ~32px scrollbar when the mirror is
	 * the only content. Kept OUT of the code_block so it never reaches the builder's backend editor.
	 */
	public static function frontend_head() {
		if ( is_admin() || ! is_singular( 'page' ) ) { return; }
		$id = get_queried_object_id();
		if ( ! $id || ! get_post_meta( $id, '_upw_landing_mirror', true ) ) { return; }
		echo '<style id="upw-landing-lock">'
			// Edge-to-edge: no container cap / padding around the mirror.
			. '.upw-landing-mirror,.upw-landing-mirror .fw-container,.upw-landing-mirror .fw-row,.upw-landing-mirror [class*="fw-col-"]{margin:0!important;padding:0!important;max-width:none!important;}'
			// The iframe FILLS its section, so the section's Min Height controls how tall the mirror is —
			// shorten it in the Section options to reveal content below (the scroll-hijacked WebGL iframe
			// otherwise eats the wheel over a full 100vh, making anything under it feel unreachable). The
			// min-height:inherit chain carries the section height down to the frame even if flex is themed
			// away, so the mirror can never collapse to the iframe's default height.
			. '.upw-landing-mirror .fw-container,.upw-landing-mirror .fw-row,.upw-landing-mirror [class*="fw-col-"],.upw-landing-mirror div.upw-landing-frame,.upw-landing-mirror iframe.upw-landing-frame{min-height:inherit!important;display:flex!important;flex-direction:column!important;flex:1 1 auto!important;}'
			. '.upw-landing-mirror iframe.upw-landing-frame{display:block!important;width:100%!important;height:auto!important;border:0!important;margin:0!important;background:transparent!important;}'
			. '</style>' . "\n";
	}

	/**
	 * INLINE "native hero" import — the no-iframe path. Instead of freezing the source in a sandbox
	 * <iframe>, it splits the captured page so the hero renders as REAL DOM in the WordPress page:
	 *   • the <body> markup (with <main>→<div> so it can't clash with the theme's own <main>) goes into
	 *     the Code Block, editable and INDEXABLE (good for SEO, and it visually belongs to the site);
	 *   • the <style> is scoped to the block (the block wrapper is the <body> stand-in / "proxy") and
	 *     stored in the block's Advanced → Custom CSS, so it can't bleed into or out of the theme;
	 *   • three.js + the inline scene scripts are enqueued on the page (classic scripts → no module/
	 *     import-map fuss, and enqueuing sidesteps wpautop mangling), with document.body /
	 *     document.documentElement repointed at the wrapper proxy so the page's class-state machine
	 *     (is-ready / intro-done / hot / press) keeps working.
	 *
	 * Use it for a self-contained hero that does NOT hijack the window scroll (detect first with
	 * is_scroll_hijacked()); genuinely scroll-jacked pages should still use import() (the iframe).
	 *
	 * @param string $mirror_dir absolute path to the mirror output folder (index.html + assets/)
	 * @param string $title      page title
	 * @param array  $opts       { template, status, post_id }
	 * @return array { ok, post_id, url, assets, error, mode:'inline' }
	 */
	public static function import_inline( $mirror_dir, $title = 'Landing', $opts = array() ) {
		$out = array( 'ok' => false, 'post_id' => 0, 'url' => '', 'assets' => 0, 'error' => '', 'mode' => 'inline' );
		$mirror_dir = rtrim( (string) $mirror_dir, "/\\" );
		$index      = $mirror_dir . '/index.html';
		if ( ! is_dir( $mirror_dir ) || ! is_file( $index ) ) { $out['error'] = 'Mirror folder or index.html not found.'; return $out; }
		$title = trim( (string) $title ) !== '' ? (string) $title : 'Landing';
		$slug  = sanitize_title( $title );
		if ( '' === $slug ) { $slug = 'landing-' . substr( md5( $mirror_dir ), 0, 8 ); }

		if ( ! function_exists( 'fw_upw_uploads_dir' ) ) { $out['error'] = 'uploads helper unavailable.'; return $out; }
		$dest      = fw_upw_uploads_dir( 'landing/' . $slug );
		$dest_path = rtrim( (string) $dest['path'], '/' );
		$dest_url  = rtrim( (string) $dest['url'], '/' );
		if ( ! wp_mkdir_p( $dest_path ) ) { $out['error'] = 'Could not create the landing uploads folder.'; return $out; }
		$out['assets'] = self::copy_tree( $mirror_dir, $dest_path );

		$html    = (string) file_get_contents( $index );
		$hero_id = 'upw-hero-' . substr( md5( $slug ), 0, 8 ); // stable per slug → JS proxy anchor + wrapper id

		// 1) <style> → one blob, scoped to the block, asset urls absolutised.
		$css = '';
		if ( preg_match_all( '#<style[^>]*>(.*?)</style>#is', $html, $sm ) ) { $css = implode( "\n", $sm[1] ); }
		// Scope to the wrapper's ID (selector#upw-hero-…), not just the class. Since the hero is now REAL
		// DOM in a themed page, the theme's base rules (e.g. `.btn{background:#141414}`) would otherwise
		// tie with a class-scoped `selector .btn` and win on load order. Anchoring on the id lifts every
		// hero rule to id-level specificity (1,x,0), so the source's own styling always beats theme bleed.
		$css = self::absolutise_assets( self::scope_css( $css, 'selector#' . $hero_id ), $dest_url );
		// Defensive: neutralise any stray empty <p> wpautop might still inject into the hero markup.
		$css = 'selector p:empty{display:none!important;margin:0!important}' . $css;

		// 2) external <script src> → uploads urls (in order); http(s) kept as-is.
		$ext = array();
		if ( preg_match_all( '#<script[^>]*\bsrc=["\']([^"\']+)["\'][^>]*>\s*</script>#is', $html, $xm ) ) {
			foreach ( $xm[1] as $src ) {
				$src = trim( $src );
				if ( '' === $src ) { continue; }
				$ext[] = preg_match( '#^https?://#i', $src ) ? $src : $dest_url . '/' . ltrim( $src, './' );
			}
		}

		// 3) inline scene scripts (no src) → concat, minus the tiny documentElement "js" class-setter
		//    (we bake `js` into the wrapper class instead). Repoint body/documentElement → the proxy.
		$inline_js = array();
		if ( preg_match_all( '#<script(?![^>]*\bsrc=)[^>]*>(.*?)</script>#is', $html, $im ) ) {
			foreach ( $im[1] as $code ) {
				if ( '' === trim( $code ) ) { continue; }
				if ( preg_match( '#^\s*document\.documentElement\.className\s*\+=\s*["\']\s*js#', $code ) ) { continue; }
				$inline_js[] = $code;
			}
		}
		$scene_js  = implode( "\n;\n", $inline_js );
		$scene_js  = preg_replace( '#\bdocument\.(?:body|documentElement)\b#', 'window.__upwRoot', $scene_js );
		$scene_url = '';
		if ( '' !== trim( $scene_js ) ) {
			file_put_contents( $dest_path . '/upw-scene.js', $scene_js );
			$scene_url = $dest_url . '/upw-scene.js';
		}
		$scripts = $ext;
		if ( '' !== $scene_url ) { $scripts[] = $scene_url; }

		// 4) <body> inner → strip scripts (enqueued separately), <main>→<div> (no clash with theme <main>),
		//    absolutise asset urls.
		$body = preg_match( '#<body[^>]*>(.*?)</body>#is', $html, $bm ) ? $bm[1] : $html;
		$body = preg_replace( '#<script[^>]*>.*?</script>#is', '', $body );
		$body = preg_replace( '#<main\b#i', '<div', $body, 1 );
		$body = preg_replace( '#</main\s*>#i', '</div>', $body, 1 );
		$body = trim( self::absolutise_assets( $body, $dest_url ) );
		// Collapse blank lines: wpautop turns a blank line (\n\n) into an empty <p>. Removing them (while
		// keeping indentation) stops those artifacts without hurting the editor's readability.
		$body = preg_replace( "#\n[ \t]*\n+#", "\n", $body );

		// 5) builder tree: section → column → code_block (INLINE). The block wrapper carries id=$hero_id
		//    + `upw-hero js` (the <body> proxy); the scoped CSS lives in its custom_css.
		$uid  = function () { return md5( uniqid( '', true ) ); };
		$tree = array( array(
			'type' => 'section',
			'atts' => array(
				'variant' => '', 'is_fullwidth' => true,
				'min_height' => array( 'preset' => 'custom', 'custom' => array( 'custom_height' => array( 'value' => '100', 'unit' => 'vh' ) ) ),
				'content_valign' => 'top', 'text_align' => '',
				'padding_top' => '', 'padding_bottom' => '', 'gap' => '',
				'unique_id' => $uid(), 'css_id' => 'landing-hero', 'css_class' => 'upw-landing-hero',
				// A full-width section renders `.fw-container-FLUID` (not `.fw-container`), and that fluid
				// container keeps a 24px left/right padding — so match `[class*="fw-container"]` to reset BOTH
				// and take the hero truly edge-to-edge.
				'custom_css' => 'selector,selector [class*="fw-container"],selector .fw-row,selector [class*="fw-col-"]{max-width:none!important;padding:0!important;margin:0!important;}',
				'responsive_hide' => array(), 'custom_attrs' => array(),
			),
			'_items' => array( array(
				'type' => 'column', 'width' => '1_1',
				'atts' => array( 'unique_id' => $uid(), 'css_id' => '', 'css_class' => '', 'custom_css' => '', 'responsive_hide' => array(), 'custom_attrs' => array() ),
				'_items' => array( array(
					'type' => 'simple', 'shortcode' => 'code_block', '_items' => array(),
					'atts' => array(
						'code'         => $body,
						'render_mode'  => array( 'mode' => 'inline' ),
						'render_as_code' => '', 'code_language' => 'html',
						'unique_id'    => $uid(), 'css_id' => $hero_id, 'css_class' => 'upw-hero js',
						'custom_css'   => $css,
						'responsive_hide' => array(), 'custom_attrs' => array(),
					),
				) ),
			) ),
		) );
		$json = wp_json_encode( $tree );

		// 6) create/update the Page + wire the builder + the no-chrome template.
		$template = isset( $opts['template'] ) && '' !== $opts['template'] ? (string) $opts['template'] : self::pick_template();
		$status   = isset( $opts['status'] ) ? (string) $opts['status'] : 'publish';
		$post_id  = isset( $opts['post_id'] ) ? (int) $opts['post_id'] : 0;
		$postarr  = array( 'post_title' => $title, 'post_type' => 'page', 'post_status' => $status, 'post_name' => $slug, 'post_content' => '' );
		if ( $post_id > 0 && get_post( $post_id ) ) { $postarr['ID'] = $post_id; $post_id = wp_update_post( $postarr, true ); }
		else { $post_id = wp_insert_post( $postarr, true ); }
		if ( is_wp_error( $post_id ) || ! $post_id ) { $out['error'] = is_wp_error( $post_id ) ? $post_id->get_error_message() : 'Could not create the page.'; return $out; }

		update_post_meta( $post_id, 'fw:opt:ext:pb:page-builder:json', wp_slash( $json ) );
		update_post_meta( $post_id, '_upw_import_hash', md5( $json ) );
		update_post_meta( $post_id, '_upw_landing_inline', 1 );          // → enqueue_inline() loads the scripts on this page
		update_post_meta( $post_id, '_upw_hero_id', $hero_id );          // → the wrapper/proxy id the scene JS binds to
		update_post_meta( $post_id, '_upw_hero_scripts', wp_json_encode( $scripts ) );
		update_post_meta( $post_id, '_wp_page_template', $template );
		// Clear any prior iframe-mirror markers if this page was previously a sandbox import.
		delete_post_meta( $post_id, '_upw_landing_mirror' );
		delete_post_meta( $post_id, '_upw_landing_file' );
		$fw_options = get_post_meta( $post_id, 'fw_options', true );
		if ( ! is_array( $fw_options ) ) { $fw_options = array(); }
		$fw_options['page-builder'] = array( 'json' => $json, 'builder_active' => true );
		update_post_meta( $post_id, 'fw_options', $fw_options );

		$out['ok'] = true; $out['post_id'] = (int) $post_id; $out['url'] = get_permalink( $post_id );
		return $out;
	}

	/**
	 * FRONT-END (hooked wp_enqueue_scripts): on an inline landing page, define the proxy root
	 * (window.__upwRoot = the code-block wrapper) then enqueue the source scripts in order — every
	 * external one (three.js, …) first, the concatenated scene bundle last, chained by dependency so
	 * order is preserved. Enqueued in the footer, so the hero DOM already exists when they run.
	 */
	public static function enqueue_inline() {
		if ( is_admin() || ! is_singular( 'page' ) ) { return; }
		$id = get_queried_object_id();
		if ( ! $id || ! get_post_meta( $id, '_upw_landing_inline', true ) ) { return; }
		$hero_id = (string) get_post_meta( $id, '_upw_hero_id', true );
		$scripts = json_decode( (string) get_post_meta( $id, '_upw_hero_scripts', true ), true );
		if ( ! is_array( $scripts ) || ! $scripts ) { return; }
		$prev = '';
		foreach ( array_values( $scripts ) as $i => $url ) {
			if ( '' === (string) $url ) { continue; }
			$h    = 'upw-hero-' . $i;
			$deps = '' !== $prev ? array( $prev ) : array();
			wp_enqueue_script( $h, (string) $url, $deps, null, true );
			if ( '' === $prev ) {
				// Define the proxy BEFORE the first script; getElementById resolves (footer = after body).
				wp_add_inline_script( $h, 'window.__UPW_HERO_ID=' . wp_json_encode( $hero_id ) . ';window.__upwRoot=document.getElementById(window.__UPW_HERO_ID)||document.body;', 'before' );
			}
			$prev = $h;
		}
	}

	/**
	 * FRONT-END (hooked wp): on an INLINE landing page, drop wpautop/wptexturize from the_content. The
	 * hero is raw, hand-authored markup output verbatim by the Code Block; wpautop would inject a <br>
	 * for every newline (and fracture inline SVGs), and wptexturize would smart-quote code. The page is a
	 * single self-contained hero, so these filters add nothing — the builder's own shortcodes emit their
	 * own structured HTML and never rely on wpautop.
	 */
	public static function maybe_disable_autop() {
		if ( is_admin() || ! is_singular( 'page' ) ) { return; }
		$id = get_queried_object_id();
		if ( ! $id || ! get_post_meta( $id, '_upw_landing_inline', true ) ) { return; }
		remove_filter( 'the_content', 'wpautop' );
		remove_filter( 'the_content', 'wptexturize' );
	}

	/**
	 * Heuristic: does a captured page HIJACK the window scroll (Lenis/Locomotive, or a wheel/touchmove
	 * listener that preventDefault-s)? Such pages must stay in the iframe (inlining would let the hijack
	 * take over the whole site). A plain requestAnimationFrame render loop + pointer parallax is NOT a
	 * hijack. Returns true only on a real scroll-takeover signal.
	 */
	public static function is_scroll_hijacked( $html ) {
		$html = (string) $html;
		if ( preg_match( '#\b(?:Lenis|LocomotiveScroll|ScrollSmoother|VirtualScroll)\b#', $html ) ) { return true; }
		// a wheel/touchmove/mousewheel listener (the mechanism a scroll-jack uses to seize the wheel)
		if ( preg_match( '#addEventListener\(\s*["\'](?:wheel|mousewheel|touchmove|DOMMouseScroll)["\']#i', $html ) ) { return true; }
		return false;
	}

	/**
	 * Rewrite relative `assets/…` refs (in src/href/url()) to absolute uploads URLs, so they resolve
	 * when the markup/CSS lives in the page (not beside index.html). Only touches `assets/` paths.
	 */
	private static function absolutise_assets( $s, $dest_url ) {
		return preg_replace( '#(["\'(])\s*assets/#', '$1' . $dest_url . '/assets/', (string) $s );
	}

	/**
	 * Scope a whole stylesheet to the framework's `selector` token (→ the element's .u{hash} at render).
	 * Global element rules become the element itself: html/body/:root → `selector`, `*` → `selector,
	 * selector *`, body-level state classes (.js/.is-ready/.intro-done/.hot/.press) attach to `selector`,
	 * everything else becomes a descendant `selector …`. @media/@supports recurse; @keyframes/@font-face
	 * pass through untouched.
	 */
	public static function scope_css( $css, $sel = 'selector' ) {
		$css = preg_replace( '#/\*.*?\*/#s', '', (string) $css ); // strip comments
		return self::scope_css_rules( $css, $sel );
	}

	private static function scope_css_rules( $css, $sel ) {
		$out = ''; $len = strlen( $css ); $i = 0;
		while ( $i < $len ) {
			$brace = strpos( $css, '{', $i );
			if ( false === $brace ) { $out .= substr( $css, $i ); break; }
			$semi = strpos( $css, ';', $i );
			if ( false !== $semi && $semi < $brace ) {                     // @import/@charset; — statement, no block
				$out .= substr( $css, $i, $semi - $i + 1 ); $i = $semi + 1; continue;
			}
			$prelude = trim( substr( $css, $i, $brace - $i ) );
			$depth = 1; $j = $brace + 1;
			while ( $j < $len && $depth > 0 ) { $c = $css[ $j ]; if ( '{' === $c ) { $depth++; } elseif ( '}' === $c ) { $depth--; } $j++; }
			$block = substr( $css, $brace + 1, $j - $brace - 2 );
			$i = $j;
			if ( '' !== $prelude && '@' === $prelude[0] ) {
				$at = strtolower( preg_replace( '/[\s(].*$/s', '', $prelude ) );
				if ( in_array( $at, array( '@media', '@supports', '@document', '@-moz-document', '@layer', '@container' ), true ) ) {
					$out .= $prelude . '{' . self::scope_css_rules( $block, $sel ) . '}';
				} else {                                                    // @keyframes/@font-face/@page/@property → verbatim
					$out .= $prelude . '{' . $block . '}';
				}
			} else {
				$out .= self::scope_selector_list( $prelude, $sel ) . '{' . $block . '}';
			}
		}
		return $out;
	}

	private static function scope_selector_list( $selectors, $sel ) {
		$done = array();
		foreach ( self::split_commas_top( (string) $selectors ) as $s ) {
			$s = trim( $s );
			if ( '' === $s ) { continue; }
			$done[] = self::scope_one_selector( $s, $sel );
		}
		return implode( ',', $done );
	}

	private static function scope_one_selector( $s, $sel ) {
		if ( preg_match( '/^\*(\s*::?[a-z-]+(?:\([^)]*\))?)?$/i', $s, $m ) ) {       // *  /  *::before
			$p = isset( $m[1] ) ? trim( $m[1] ) : '';
			return $sel . $p . ',' . $sel . ' *' . $p;
		}
		if ( preg_match( '/^(?:html|body|:root)((?:[.:#\[][^\s>+~,]*)*)(.*)$/i', $s, $m ) ) { // leading html/body/:root
			return $sel . $m[1] . $m[2];
		}
		if ( preg_match( '/^(\.(?:js|is-ready|intro-done|hot|press)(?:[.:#\[][^\s>+~,]*)*)(.*)$/', $s, $m ) ) { // body-level state class
			return $sel . $m[1] . $m[2];
		}
		return $sel . ' ' . $s;                                                    // ordinary → descendant
	}

	private static function split_commas_top( $s ) {
		$parts = array(); $buf = ''; $depth = 0; $len = strlen( $s );
		for ( $k = 0; $k < $len; $k++ ) {
			$c = $s[ $k ];
			if ( '(' === $c || '[' === $c ) { $depth++; }
			elseif ( ')' === $c || ']' === $c ) { $depth--; }
			if ( ',' === $c && 0 === $depth ) { $parts[] = $buf; $buf = ''; } else { $buf .= $c; }
		}
		if ( '' !== trim( $buf ) ) { $parts[] = $buf; }
		return $parts;
	}

	/** Pick the best "no chrome" page template that exists in the active theme. */
	private static function pick_template() {
		$templates = wp_get_theme()->get_page_templates( null, 'page' );
		foreach ( array( 'page-landing.php', 'page-no-header.php', 'page-full-width.php' ) as $t ) {
			if ( isset( $templates[ $t ] ) ) { return $t; }
		}
		return 'default';
	}
}
