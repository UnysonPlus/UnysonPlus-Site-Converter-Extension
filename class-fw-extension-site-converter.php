<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Site Converter extension.
 *
 * The admin home for the AI-site → WordPress importer (roadmap #2). It ingests
 * the artifacts an agent produces — per the conversion contract — and applies
 * them to the site. This first slice ships the **Media** tool: fetch the source
 * site's images into the WordPress media library (de-duped), either from a pasted
 * list of URLs or by scanning a source page. Later slices add the presets
 * importer, menu importer, and a one-shot "Convert bundle" that orchestrates all
 * phases (media → presets → theme settings → pages → menus).
 *
 * Admin page lives under the Unyson+ menu (Unyson+ → Convert). The reusable media
 * logic is in includes/class-fw-site-converter-media.php so a bundle importer /
 * WP-CLI can reuse it.
 */
class FW_Extension_Site_Converter extends FW_Extension {

	const PARENT_SLUG = 'fw-extensions';
	const PAGE_SLUG   = 'fw-site-converter';
	const CAPABILITY  = 'manage_options';
	const NONCE       = 'fw_ext_site_converter_run';

	/** @var string|null Hook suffix from add_submenu_page(). */
	private $hook_suffix = null;

	/**
	 * @internal
	 */
	public function _init() {
		require_once $this->get_declared_path( '/includes/class-fw-site-converter-media.php' );

		if ( is_admin() ) {
			add_action( 'admin_menu', array( $this, '_action_admin_menu' ), 30 );
			// Slot our page into the shared Unyson+ submenu order (the Post Types
			// extension owns the sort; we just declare where we want to sit).
			add_filter( 'fw_unysonplus_admin_submenu_order', array( $this, '_filter_submenu_order' ) );
			// One-image-at-a-time import endpoint (drives the progress bar).
			add_action( 'wp_ajax_fw_sc_import', array( $this, '_ajax_import' ) );
		}
	}

	/**
	 * @internal
	 * @param string[] $order
	 * @return string[]
	 */
	public function _filter_submenu_order( $order ) {
		if ( is_array( $order ) && ! in_array( self::PAGE_SLUG, $order, true ) ) {
			$order[] = self::PAGE_SLUG; // append after the existing pages
		}
		return $order;
	}

	public static function get_page_url() {
		return admin_url( 'admin.php?page=' . self::PAGE_SLUG );
	}

	/**
	 * @internal
	 */
	public function _action_admin_menu() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$this->hook_suffix = add_submenu_page(
			self::PARENT_SLUG,
			__( 'Convert — AI Site Importer', 'fw' ),
			__( 'Convert', 'fw' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);

		if ( $this->hook_suffix ) {
			add_action( 'load-' . $this->hook_suffix, array( $this, '_maybe_run' ) );
		}
	}

	/* ---------------------------------------------------------------------- *
	 * Run handler (load- hook, before output → PRG redirect)
	 * ---------------------------------------------------------------------- */

	private function results_transient_key() {
		return 'fw_site_converter_media_' . get_current_user_id();
	}

	/**
	 * @internal
	 * Two steps: 'scan' collects candidates and shows a preview/picker; 'import'
	 * sideloads the images the user selected. Both PRG-redirect to the page.
	 */
	public function _maybe_run() {
		if ( 'POST' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : '' ) ) {
			return;
		}
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		check_admin_referer( self::NONCE );

		$step = isset( $_POST['fw_sc_step'] ) ? sanitize_key( wp_unslash( $_POST['fw_sc_step'] ) ) : 'scan';

		if ( $step === 'import' ) {
			$this->run_import();
		} else {
			$this->run_scan();
		}
	}

	/**
	 * Step 1 — collect candidate image URLs (no fetching yet) and stash them for
	 * the preview/picker.
	 */
	private function run_scan() {
		$mode     = isset( $_POST['fw_sc_mode'] ) ? sanitize_key( wp_unslash( $_POST['fw_sc_mode'] ) ) : 'scan';
		$deep     = ! empty( $_POST['fw_sc_deep'] );
		$page_url = isset( $_POST['fw_sc_page_url'] ) ? esc_url_raw( trim( wp_unslash( $_POST['fw_sc_page_url'] ) ) ) : '';
		$raw_urls = isset( $_POST['fw_sc_urls'] ) ? trim( (string) wp_unslash( $_POST['fw_sc_urls'] ) ) : '';

		// Forgiving: use whichever field has content if the radio disagrees.
		if ( $mode === 'scan' && $page_url === '' && $raw_urls !== '' ) {
			$mode = 'urls';
		}
		if ( $mode === 'urls' && $raw_urls === '' && $page_url !== '' ) {
			$mode = 'scan';
		}

		$report = null;
		$urls   = array();

		if ( $mode === 'scan' ) {
			if ( $page_url === '' ) {
				set_transient( $this->results_transient_key(), array( 'error' => __( 'Enter a page URL to scan, or paste image URLs in the list box.', 'fw' ) ), 5 * MINUTE_IN_SECONDS );
				$this->redirect_back();
			}
			$report = FW_Site_Converter_Media::scan_page( $page_url, $deep );
			if ( $report['error'] !== '' ) {
				set_transient( $this->results_transient_key(), array( 'error' => $report['error'] ), 5 * MINUTE_IN_SECONDS );
				$this->redirect_back();
			}
			$urls = $report['urls'];
		} else {
			$urls = preg_split( '/\r\n|\r|\n/', $raw_urls );
		}

		$urls = array_values( array_unique( array_filter( array_map( 'trim', (array) $urls ) ) ) );

		$candidates = array();
		foreach ( $urls as $u ) {
			$candidates[] = array(
				'url'    => $u,
				'name'   => FW_Site_Converter_Media::filename_from_url( $u ),
				'exists' => FW_Site_Converter_Media::find_by_source( $u ) > 0,
			);
		}

		set_transient( $this->results_transient_key(), array(
			'stage'      => 'preview',
			'source'     => $mode === 'scan' ? $page_url : '',
			'report'     => $report,
			'candidates' => $candidates,
		), 30 * MINUTE_IN_SECONDS );

		$this->redirect_back();
	}

	/**
	 * Step 2 — sideload exactly the images the user ticked in the preview.
	 */
	private function run_import() {
		$post_id = isset( $_POST['fw_sc_attach_post'] ) ? absint( $_POST['fw_sc_attach_post'] ) : 0;
		$source  = isset( $_POST['fw_sc_source'] ) ? esc_url_raw( wp_unslash( $_POST['fw_sc_source'] ) ) : '';
		$picks   = isset( $_POST['fw_sc_pick'] ) ? (array) wp_unslash( $_POST['fw_sc_pick'] ) : array();

		$urls = array();
		foreach ( $picks as $u ) {
			$u = esc_url_raw( trim( (string) $u ) );
			if ( $u !== '' && preg_match( '#^https?://#i', $u ) ) {
				$urls[] = $u;
			}
		}
		$urls    = array_slice( array_values( array_unique( $urls ) ), 0, 300 ); // safety cap
		$results = $urls ? FW_Site_Converter_Media::import_urls( $urls, $post_id ) : array();

		set_transient( $this->results_transient_key(), array(
			'stage'   => 'results',
			'source'  => $source,
			'results' => $results,
		), 5 * MINUTE_IN_SECONDS );

		$this->redirect_back();
	}

	/**
	 * @internal
	 * AJAX: sideload ONE image and return its result, so the picker can import
	 * the selection incrementally with a live progress bar (and never time out
	 * on a big batch).
	 */
	public function _ajax_import() {
		check_ajax_referer( self::NONCE );
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'fw' ) ), 403 );
		}

		$url     = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$name    = FW_Site_Converter_Media::filename_from_url( $url );

		if ( $url === '' || ! preg_match( '#^https?://#i', $url ) ) {
			wp_send_json_error( array( 'url' => $url, 'name' => $name, 'message' => __( 'Invalid URL.', 'fw' ) ) );
		}

		$id = FW_Site_Converter_Media::sideload( $url, $post_id );

		if ( is_wp_error( $id ) ) {
			wp_send_json_error( array( 'url' => $url, 'name' => $name, 'message' => $id->get_error_message() ) );
		}

		wp_send_json_success( array(
			'url'    => $url,
			'name'   => $name,
			'id'     => (int) $id,
			'reused' => FW_Site_Converter_Media::$last_reused,
			'edit'   => get_edit_post_link( $id, 'raw' ),
		) );
	}

	private function redirect_back() {
		wp_safe_redirect( add_query_arg(
			array( 'page' => self::PAGE_SLUG, 'fw-sc-done' => '1' ),
			admin_url( 'admin.php' )
		) );
		exit;
	}

	/* ---------------------------------------------------------------------- *
	 * Render
	 * ---------------------------------------------------------------------- */

	/**
	 * @internal
	 */
	public function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$data = false;
		if ( isset( $_GET['fw-sc-done'] ) ) {
			$data = get_transient( $this->results_transient_key() );
			delete_transient( $this->results_transient_key() );
		}
		$stage = is_array( $data ) && isset( $data['stage'] ) ? $data['stage'] : '';
		?>
		<div class="wrap fw-ext-site-converter">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Convert — AI Site Importer', 'fw' ); ?></h1>
			<p class="description" style="max-width:54em">
				<?php esc_html_e( 'Bring an AI-generated site into WordPress. Scan a source page (or paste URLs) to PREVIEW its images, pick the ones you want, then import them into your Media Library (de-duped by source URL). Presets, menus and a one-shot bundle import are coming next.', 'fw' ); ?>
			</p>

			<?php
			if ( is_array( $data ) && isset( $data['error'] ) ) {
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $data['error'] ) . '</p></div>';
			} elseif ( $stage === 'preview' ) {
				$this->render_preview( $data );
			} elseif ( $stage === 'results' ) {
				$this->render_results( $data );
			}
			?>

			<h2 style="margin-top:1.5em"><?php echo $stage === 'preview' ? esc_html__( 'Scan another source', 'fw' ) : esc_html__( 'Find images', 'fw' ); ?></h2>
			<form method="post" action="">
				<?php wp_nonce_field( self::NONCE ); ?>
				<input type="hidden" name="fw_sc_step" value="scan">
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Source', 'fw' ); ?></th>
						<td>
							<label style="display:block;margin-bottom:.4em">
								<input type="radio" name="fw_sc_mode" value="scan" checked>
								<?php esc_html_e( 'Scan a page URL for images', 'fw' ); ?>
							</label>
							<input type="url" name="fw_sc_page_url" class="regular-text" placeholder="https://example.lovable.app/" style="width:32em;max-width:100%">
							<p class="description"><?php esc_html_e( 'Collects images from <img>, srcset, CSS url(), and the page\'s social/favicon meta tags.', 'fw' ); ?></p>
							<label style="display:block;margin:.5em 0 0">
								<input type="checkbox" name="fw_sc_deep" value="1" checked>
								<?php esc_html_e( 'Also mine the site\'s JavaScript bundle for images', 'fw' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Needed for JS apps (React / Vite / Lovable / v0) where images are injected at runtime and never appear in the static HTML. Fetches the page\'s own script bundles and extracts image assets from them. Slightly slower.', 'fw' ); ?></p>

							<label style="display:block;margin:1em 0 .4em">
								<input type="radio" name="fw_sc_mode" value="urls">
								<?php esc_html_e( 'Or paste image URLs (one per line)', 'fw' ); ?>
							</label>
							<textarea name="fw_sc_urls" rows="6" class="large-text code" placeholder="https://example.com/hero.jpg&#10;https://example.com/logo.png"></textarea>
						</td>
					</tr>
				</table>
				<p class="submit">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Scan for images', 'fw' ); ?></button>
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * The scan-source breakdown notice (where the candidates came from).
	 *
	 * @param array|null $report
	 * @param string     $source
	 */
	private function render_scan_report( $report, $source ) {
		if ( ! is_array( $report ) ) {
			return;
		}
		$scan = sprintf(
			/* translators: 1: source, 2: total, 3: html, 4: meta, 5: embedded, 6: js, 7: scripts */
			__( 'Scanned %1$s — found %2$d image reference(s): %3$d in HTML, %4$d in meta tags, %5$d embedded in page JSON/scripts, %6$d in JS bundle(s) (%7$d script(s) mined).', 'fw' ),
			esc_url( $source ), count( $report['urls'] ), $report['html'], $report['meta'],
			isset( $report['embedded'] ) ? $report['embedded'] : 0, $report['js'], $report['scripts']
		);
		if ( $report['inline_svg'] || $report['data_uri'] ) {
			$scan .= ' ' . sprintf(
				/* translators: 1: inline svg count, 2: data uri count */
				__( '%1$d inline SVG + %2$d data-URI graphic(s) skipped (they live in the markup — nothing to fetch).', 'fw' ),
				$report['inline_svg'], $report['data_uri']
			);
		}
		echo '<div class="notice notice-info is-dismissible"><p>' . esc_html( $scan ) . '</p></div>';
	}

	/**
	 * Step 1 result — a thumbnail picker of the candidate images. Nothing has
	 * been fetched yet; the user ticks what they want and submits "Import".
	 *
	 * @param array $data
	 */
	private function render_preview( array $data ) {
		$candidates = isset( $data['candidates'] ) && is_array( $data['candidates'] ) ? $data['candidates'] : array();
		$source     = isset( $data['source'] ) ? $data['source'] : '';

		$this->render_scan_report( isset( $data['report'] ) ? $data['report'] : null, $source );

		if ( ! $candidates ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'No fetchable images found. If this is a JavaScript app, make sure "mine the JS bundle" is enabled below — or paste the image URLs directly (the agent that built the site can supply them).', 'fw' ) . '</p></div>';
			return;
		}

		$new_count = 0;
		foreach ( $candidates as $c ) {
			if ( empty( $c['exists'] ) ) {
				$new_count++;
			}
		}
		?>
		<style>
			.fw-sc-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:12px;margin:1em 0}
			.fw-sc-card{border:1px solid #dcdcde;border-radius:6px;overflow:hidden;background:#fff;position:relative;cursor:pointer;margin:0}
			.fw-sc-card.is-off{opacity:.4}
			.fw-sc-thumb{display:block;width:100%;height:120px;object-fit:cover;background:#f0f0f1}
			.fw-sc-meta{display:flex;align-items:center;gap:6px;padding:6px 8px;font-size:11px;line-height:1.3}
			.fw-sc-meta input{margin:0;flex:0 0 auto}
			.fw-sc-name{word-break:break-all;color:#50575e}
			.fw-sc-badge{position:absolute;top:6px;right:6px;background:#2271b1;color:#fff;font-size:10px;padding:1px 6px;border-radius:10px}
			.fw-sc-card.is-done{outline:2px solid #1a7f37;outline-offset:-2px}
			.fw-sc-card.is-dup{outline:2px solid #bd8600;outline-offset:-2px}
			.fw-sc-card.is-fail{outline:2px solid #b32d2e;outline-offset:-2px}
			.fw-sc-toolbar{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin:.5em 0}
			.fw-sc-progress{display:none;margin:1em 0;padding:12px 14px;border:1px solid #dcdcde;border-radius:6px;background:#fff;max-width:520px}
			.fw-sc-bar{height:14px;border-radius:7px;background:#f0f0f1;overflow:hidden}
			.fw-sc-bar-fill{height:100%;width:0;background:#2271b1;transition:width .15s ease}
			.fw-sc-prog-text{margin:.5em 0 0;font-weight:600}
		</style>
		<form method="post" action="" class="fw-sc-preview" data-nonce="<?php echo esc_attr( wp_create_nonce( self::NONCE ) ); ?>">
			<?php wp_nonce_field( self::NONCE ); ?>
			<input type="hidden" name="fw_sc_step" value="import">
			<input type="hidden" name="fw_sc_source" value="<?php echo esc_attr( $source ); ?>">

			<div class="fw-sc-toolbar">
				<strong><?php printf( esc_html__( '%d image(s) found', 'fw' ), count( $candidates ) ); ?></strong>
				<button type="button" class="button" data-fw-sc="all"><?php esc_html_e( 'Select all', 'fw' ); ?></button>
				<button type="button" class="button" data-fw-sc="none"><?php esc_html_e( 'Select none', 'fw' ); ?></button>
				<button type="button" class="button" data-fw-sc="new"><?php esc_html_e( 'Only new', 'fw' ); ?></button>
				<label style="margin-left:auto"><?php esc_html_e( 'Attach to post ID', 'fw' ); ?>
					<input type="number" name="fw_sc_attach_post" class="small-text" min="0" value="0"></label>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Import selected', 'fw' ); ?> (<span class="fw-sc-count"><?php echo (int) $new_count; ?></span>)</button>
			</div>

			<div class="fw-sc-progress" aria-live="polite">
				<div class="fw-sc-bar"><div class="fw-sc-bar-fill"></div></div>
				<p class="fw-sc-prog-text"></p>
			</div>

			<div class="fw-sc-grid">
				<?php foreach ( $candidates as $c ) :
					$checked = empty( $c['exists'] ); ?>
					<label class="fw-sc-card<?php echo $checked ? '' : ' is-off'; ?>">
						<?php if ( ! empty( $c['exists'] ) ) : ?><span class="fw-sc-badge"><?php esc_html_e( 'in library', 'fw' ); ?></span><?php endif; ?>
						<img class="fw-sc-thumb" src="<?php echo esc_url( $c['url'] ); ?>" loading="lazy" alt="" referrerpolicy="no-referrer">
						<span class="fw-sc-meta">
							<input type="checkbox" name="fw_sc_pick[]" value="<?php echo esc_attr( $c['url'] ); ?>"<?php checked( $checked ); ?> data-new="<?php echo empty( $c['exists'] ) ? '1' : '0'; ?>">
							<span class="fw-sc-name"><?php echo esc_html( $c['name'] ); ?></span>
						</span>
					</label>
				<?php endforeach; ?>
			</div>

			<p class="submit">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Import selected', 'fw' ); ?></button>
			</p>

			<script>
			( function () {
				var f = document.querySelector( '.fw-sc-preview' ); if ( ! f ) { return; }
				var boxes = Array.prototype.slice.call( f.querySelectorAll( 'input[name="fw_sc_pick[]"]' ) );
				var prog = f.querySelector( '.fw-sc-progress' );
				var fill = f.querySelector( '.fw-sc-bar-fill' );
				var text = f.querySelector( '.fw-sc-prog-text' );
				var ajaxurl = window.ajaxurl || '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';
				var nonce = f.getAttribute( 'data-nonce' );

				function sync() {
					var n = 0;
					boxes.forEach( function ( b ) {
						var card = b.closest( '.fw-sc-card' );
						if ( card ) { card.classList.toggle( 'is-off', ! b.checked ); }
						if ( b.checked ) { n++; }
					} );
					f.querySelectorAll( '.fw-sc-count' ).forEach( function ( s ) { s.textContent = n; } );
				}
				f.querySelectorAll( '[data-fw-sc]' ).forEach( function ( btn ) {
					btn.addEventListener( 'click', function () {
						var m = btn.getAttribute( 'data-fw-sc' );
						boxes.forEach( function ( b ) {
							b.checked = ( m === 'all' ) ? true : ( m === 'none' ) ? false : ( b.getAttribute( 'data-new' ) === '1' );
						} );
						sync();
					} );
				} );
				boxes.forEach( function ( b ) { b.addEventListener( 'change', sync ); } );
				sync();

				// Progressive enhancement: import via AJAX with a live progress bar.
				// No-JS (or no fetch) falls back to the normal POST → server imports
				// the selection and shows the results table.
				if ( ! window.fetch ) { return; }
				f.addEventListener( 'submit', function ( e ) {
					var picks = boxes.filter( function ( b ) { return b.checked; } );
					if ( ! picks.length ) { return; }
					e.preventDefault();
					var postId = ( f.querySelector( '[name="fw_sc_attach_post"]' ) || {} ).value || 0;
					var total = picks.length, done = 0, ok = 0, reused = 0, failed = 0, idx = 0, CONC = 3;
					f.querySelectorAll( 'button' ).forEach( function ( b ) { b.disabled = true; } );
					prog.style.display = 'block';
					function update() {
						fill.style.width = Math.round( done / total * 100 ) + '%';
						text.textContent = done + ' / ' + total + ' — ' + ok + ' imported, ' + reused + ' reused, ' + failed + ' failed';
					}
					update();
					function next() {
						if ( idx >= picks.length ) { return; }
						var box = picks[ idx++ ];
						var card = box.closest( '.fw-sc-card' );
						var body = new URLSearchParams();
						body.set( 'action', 'fw_sc_import' );
						body.set( '_wpnonce', nonce );
						body.set( 'url', box.value );
						body.set( 'post_id', postId );
						fetch( ajaxurl, { method: 'POST', credentials: 'same-origin', body: body } )
							.then( function ( r ) { return r.json(); } )
							.then( function ( res ) {
								done++;
								if ( res && res.success ) {
									if ( res.data && res.data.reused ) { reused++; if ( card ) { card.classList.add( 'is-dup' ); card.title = 'Already in library (#' + res.data.id + ')'; } }
									else { ok++; if ( card ) { card.classList.add( 'is-done' ); } }
								} else { failed++; if ( card ) { card.classList.add( 'is-fail' ); card.title = ( res && res.data && res.data.message ) || 'failed'; } }
							} )
							.catch( function () { done++; failed++; if ( card ) { card.classList.add( 'is-fail' ); } } )
							.then( function () {
								update();
								if ( done >= total ) {
									fill.style.width = '100%';
									text.textContent = '<?php echo esc_js( __( 'Done', 'fw' ) ); ?> — ' + ok + ' imported, ' + reused + ' reused, ' + failed + ' failed of ' + total + '.';
								} else {
									next();
								}
							} );
					}
					for ( var c = 0; c < Math.min( CONC, picks.length ); c++ ) { next(); }
				} );
			} )();
			</script>
		</form>
		<?php
	}

	/**
	 * Render the results table from a completed run.
	 *
	 * @param array $data
	 */
	private function render_results( array $data ) {
		$results = isset( $data['results'] ) && is_array( $data['results'] ) ? $data['results'] : array();
		$ok      = 0;
		$reused  = 0;
		$failed  = 0;
		foreach ( $results as $r ) {
			if ( ! empty( $r['ok'] ) ) {
				$ok++;
				if ( ! empty( $r['reused'] ) ) {
					$reused++;
				}
			} else {
				$failed++;
			}
		}

		$report = isset( $data['report'] ) && is_array( $data['report'] ) ? $data['report'] : null;

		// Scan-source breakdown (scan mode only) — explains WHERE images came from,
		// and why 0 is 0 (inline SVG / JS-only).
		if ( $report ) {
			$found = count( $report['urls'] );
			$scan  = sprintf(
				/* translators: 1: source URL, 2: total, 3: html, 4: meta, 5: js, 6: scripts */
				__( 'Scanned %1$s — found %2$d image reference(s): %3$d in HTML, %4$d in meta tags, %5$d in JS bundle(s) (%6$d script(s) mined).', 'fw' ),
				esc_url( $data['source'] ), $found, $report['html'], $report['meta'], $report['js'], $report['scripts']
			);
			if ( $report['inline_svg'] || $report['data_uri'] ) {
				$scan .= ' ' . sprintf(
					/* translators: 1: inline svg count, 2: data uri count */
					__( '%1$d inline SVG + %2$d data-URI graphic(s) skipped (they live in the markup — nothing to fetch).', 'fw' ),
					$report['inline_svg'], $report['data_uri']
				);
			}
			echo '<div class="notice notice-info is-dismissible"><p>' . esc_html( $scan ) . '</p></div>';

			if ( $found === 0 ) {
				echo '<div class="notice notice-warning"><p>' . esc_html__( 'No fetchable images found. If this is a JavaScript app, make sure "mine the JS bundle" is enabled above — or paste the image URLs directly in the list box (the agent that built the site can supply them).', 'fw' ) . '</p></div>';
			}
		}

		$summary = sprintf(
			/* translators: 1: imported count, 2: reused count, 3: failed count */
			__( 'Imported %1$d image(s) — %2$d reused (already in library), %3$d failed.', 'fw' ),
			$ok, $reused, $failed
		);
		?>
		<div class="notice <?php echo $failed ? 'notice-warning' : 'notice-success'; ?> is-dismissible">
			<p><?php echo esc_html( $summary ); ?></p>
		</div>

		<?php if ( $results ) : ?>
			<table class="widefat striped" style="margin-bottom:1.5em">
				<thead><tr>
					<th><?php esc_html_e( 'Source URL', 'fw' ); ?></th>
					<th style="width:90px"><?php esc_html_e( 'Status', 'fw' ); ?></th>
					<th style="width:80px"><?php esc_html_e( 'Media', 'fw' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $results as $r ) : ?>
					<tr>
						<td><code style="word-break:break-all"><?php echo esc_html( $r['source'] ); ?></code></td>
						<td>
							<?php if ( ! empty( $r['ok'] ) ) : ?>
								<span style="color:#1a7f37">&#10003; <?php echo ! empty( $r['reused'] ) ? esc_html__( 'reused', 'fw' ) : esc_html__( 'imported', 'fw' ); ?></span>
							<?php else : ?>
								<span style="color:#b32d2e" title="<?php echo esc_attr( isset( $r['message'] ) ? $r['message'] : '' ); ?>">&#10007; <?php esc_html_e( 'failed', 'fw' ); ?></span>
							<?php endif; ?>
						</td>
						<td>
							<?php if ( ! empty( $r['id'] ) ) : ?>
								<a href="<?php echo esc_url( get_edit_post_link( $r['id'] ) ); ?>">#<?php echo (int) $r['id']; ?></a>
							<?php else : ?>
								&mdash;
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
	}
}
