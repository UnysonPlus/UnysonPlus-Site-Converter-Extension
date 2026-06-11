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
		require_once $this->get_declared_path( '/includes/class-fw-site-converter-presets.php' );
		require_once $this->get_declared_path( '/includes/class-fw-site-converter-menus.php' );
		require_once $this->get_declared_path( '/includes/class-fw-site-converter-theme-settings.php' );
		require_once $this->get_declared_path( '/includes/class-fw-site-converter-pages.php' );
		require_once $this->get_declared_path( '/includes/class-fw-site-converter-bundle.php' );

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
		} elseif ( $step === 'import_presets' ) {
			$this->run_import_presets();
		} elseif ( $step === 'import_theme_settings' ) {
			$this->run_import_theme_settings();
		} elseif ( $step === 'reset_theme_settings' ) {
			$this->run_reset_theme_settings();
		} elseif ( $step === 'import_pages' ) {
			$this->run_import_pages();
		} elseif ( $step === 'import_menus' ) {
			$this->run_import_menus();
		} elseif ( $step === 'scan_menus' ) {
			$this->run_scan_menus();
		} elseif ( $step === 'import_bundle' ) {
			$this->run_import_bundle();
		} else {
			$this->run_scan();
		}
	}

	/**
	 * Styling-presets tool — apply a pasted `_fw_presets_export` / `presets.json`
	 * to the theme-independent preset store (palette, font sizes, button colors,
	 * spacing/gap scales, …). Reuses the FW_Site_Converter_Presets engine.
	 */
	private function run_import_presets() {
		$raw    = isset( $_POST['fw_sc_presets_json'] ) ? (string) wp_unslash( $_POST['fw_sc_presets_json'] ) : '';
		$result = FW_Site_Converter_Presets::import_json( $raw );

		if ( $result['error'] !== '' ) {
			set_transient( $this->results_transient_key(), array( 'error' => $result['error'] ), 5 * MINUTE_IN_SECONDS );
			$this->redirect_back();
		}

		set_transient( $this->results_transient_key(), array(
			'stage'    => 'presets_result',
			'imported' => $result['imported'],
			'skipped'  => $result['skipped'],
		), 5 * MINUTE_IN_SECONDS );

		$this->redirect_back();
	}

	/**
	 * Theme-settings tool — apply a pasted design file (the `_fw_settings_export`
	 * / theme-settings `.json`) to the theme's settings store. Reuses the
	 * FW_Site_Converter_Theme_Settings engine.
	 */
	private function run_import_theme_settings() {
		$raw    = isset( $_POST['fw_sc_theme_json'] ) ? (string) wp_unslash( $_POST['fw_sc_theme_json'] ) : '';
		$result = FW_Site_Converter_Theme_Settings::import_json( $raw );

		if ( $result['error'] !== '' ) {
			set_transient( $this->results_transient_key(), array( 'error' => $result['error'] ), 5 * MINUTE_IN_SECONDS );
			$this->redirect_back();
		}

		set_transient( $this->results_transient_key(), array(
			'stage'       => 'theme_result',
			'imported'    => $result['imported'],
			'skipped'     => $result['skipped'],
			'cross_theme' => ! empty( $result['cross_theme'] ),
		), 5 * MINUTE_IN_SECONDS );

		$this->redirect_back();
	}

	/**
	 * Recovery — delete the theme-settings option so a corrupted / blank Theme
	 * Settings page falls back to defaults and renders again.
	 */
	private function run_reset_theme_settings() {
		$res = FW_Site_Converter_Theme_Settings::reset();

		set_transient( $this->results_transient_key(), array(
			'stage'       => 'theme_reset',
			'option_name' => $res['option_name'],
			'existed'     => ! empty( $res['existed'] ),
		), 5 * MINUTE_IN_SECONDS );

		$this->redirect_back();
	}

	/**
	 * Pages tool — create WordPress pages from a pasted pages JSON (page-builder
	 * trees). Reuses the FW_Site_Converter_Pages engine.
	 */
	private function run_import_pages() {
		$raw    = isset( $_POST['fw_sc_pages_json'] ) ? (string) wp_unslash( $_POST['fw_sc_pages_json'] ) : '';
		$result = FW_Site_Converter_Pages::import_json( $raw );

		if ( $result['error'] !== '' && ! $result['pages'] ) {
			set_transient( $this->results_transient_key(), array( 'error' => $result['error'] ), 5 * MINUTE_IN_SECONDS );
			$this->redirect_back();
		}

		set_transient( $this->results_transient_key(), array(
			'stage' => 'pages_result',
			'pages' => $result['pages'],
		), 5 * MINUTE_IN_SECONDS );

		$this->redirect_back();
	}

	/**
	 * Menus tool — build WordPress nav menus from a pasted menus JSON and assign
	 * them to the theme's menu locations (primary / footer). Reuses the
	 * FW_Site_Converter_Menus engine.
	 */
	private function run_import_menus() {
		$raw    = isset( $_POST['fw_sc_menus_json'] ) ? (string) wp_unslash( $_POST['fw_sc_menus_json'] ) : '';
		$result = FW_Site_Converter_Menus::import_json( $raw );

		if ( $result['error'] !== '' ) {
			set_transient( $this->results_transient_key(), array( 'error' => $result['error'] ), 5 * MINUTE_IN_SECONDS );
			$this->redirect_back();
		}

		set_transient( $this->results_transient_key(), array(
			'stage'     => 'menus_result',
			'menus'     => $result['menus'],
			'locations' => $result['locations'],
		), 5 * MINUTE_IN_SECONDS );

		$this->redirect_back();
	}

	/**
	 * Menu scanner — fetch a source page, extract its header/footer navigation,
	 * and prefill the import box with the resulting JSON for review. Reuses the
	 * FW_Site_Converter_Menus::scan_page engine (and the media engine's fetch).
	 */
	private function run_scan_menus() {
		$url  = isset( $_POST['fw_sc_menus_url'] ) ? esc_url_raw( trim( wp_unslash( $_POST['fw_sc_menus_url'] ) ) ) : '';
		$scan = FW_Site_Converter_Menus::scan_page( $url );

		// Hard error (couldn't fetch / nothing at all) → message only.
		if ( $scan['error'] !== '' && ! $scan['menus'] ) {
			set_transient( $this->results_transient_key(), array( 'error' => $scan['error'] ), 5 * MINUTE_IN_SECONDS );
			$this->redirect_back();
		}

		$json = wp_json_encode( array( 'menus' => $scan['menus'] ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		set_transient( $this->results_transient_key(), array(
			'stage'   => 'menus_scanned',
			'source'  => $scan['source'],
			'menus'   => $scan['menus'],
			'prefill' => (string) $json,
		), 30 * MINUTE_IN_SECONDS );

		$this->redirect_back();
	}

	/**
	 * Convert bundle — one-shot: unzip an uploaded `.zip` and apply every phase we
	 * have an engine for (media → presets → … → menus). Reuses
	 * FW_Site_Converter_Bundle, which orchestrates the individual engines.
	 */
	private function run_import_bundle() {
		$file = isset( $_FILES['fw_sc_bundle'] ) ? $_FILES['fw_sc_bundle'] : null;

		if ( ! $file || ! isset( $file['tmp_name'] ) || $file['tmp_name'] === '' || ! empty( $file['error'] ) ) {
			set_transient( $this->results_transient_key(), array( 'error' => __( 'Choose a .zip bundle to upload.', 'fw' ) ), 5 * MINUTE_IN_SECONDS );
			$this->redirect_back();
		}

		$tmp_name = $file['tmp_name'];
		$orig     = isset( $file['name'] ) ? sanitize_file_name( $file['name'] ) : '';

		// Must be a real upload, a .zip, and within a sane size.
		if ( ! is_uploaded_file( $tmp_name ) ) {
			set_transient( $this->results_transient_key(), array( 'error' => __( 'Invalid upload.', 'fw' ) ), 5 * MINUTE_IN_SECONDS );
			$this->redirect_back();
		}
		$ft = wp_check_filetype( $orig, array( 'zip' => 'application/zip' ) );
		if ( strtolower( (string) pathinfo( $orig, PATHINFO_EXTENSION ) ) !== 'zip' && $ft['ext'] !== 'zip' ) {
			set_transient( $this->results_transient_key(), array( 'error' => __( 'The bundle must be a .zip file.', 'fw' ) ), 5 * MINUTE_IN_SECONDS );
			$this->redirect_back();
		}

		$result = FW_Site_Converter_Bundle::import_zip( $tmp_name );

		if ( $result['error'] !== '' && ! $result['sections'] ) {
			set_transient( $this->results_transient_key(), array( 'error' => $result['error'] ), 5 * MINUTE_IN_SECONDS );
			$this->redirect_back();
		}

		$result['stage'] = 'bundle_result';
		set_transient( $this->results_transient_key(), $result, 5 * MINUTE_IN_SECONDS );

		$this->redirect_back();
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
		// When a menu scan just ran, prefill the import box with its JSON.
		$menus_prefill = ( $stage === 'menus_scanned' && isset( $data['prefill'] ) ) ? (string) $data['prefill'] : '';
		?>
		<div class="wrap fw-ext-site-converter">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Convert — AI Site Importer', 'fw' ); ?></h1>
			<p class="description" style="max-width:54em">
				<?php esc_html_e( 'Bring an AI-generated site into WordPress. Use the one-shot bundle import to apply media, styling presets, and menus from a single .zip — or run the individual tools below: scan a source page (or paste URLs) to preview and import images (de-duped by source URL), import a Styling Presets export, and import the site navigation (header / footer menus).', 'fw' ); ?>
			</p>

			<?php
			if ( is_array( $data ) && ! empty( $data['error'] ) ) {
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $data['error'] ) . '</p></div>';
			} elseif ( $stage === 'preview' ) {
				$this->render_preview( $data );
			} elseif ( $stage === 'results' ) {
				$this->render_results( $data );
			} elseif ( $stage === 'presets_result' ) {
				$this->render_presets_result( $data );
			} elseif ( $stage === 'theme_result' ) {
				$this->render_theme_result( $data );
			} elseif ( $stage === 'pages_result' ) {
				$this->render_pages_result( $data );
			} elseif ( $stage === 'menus_result' ) {
				$this->render_menus_result( $data );
			} elseif ( $stage === 'menus_scanned' ) {
				$this->render_menus_scanned( $data );
			} elseif ( $stage === 'bundle_result' ) {
				$this->render_bundle_result( $data );
			}
			?>

			<h2><?php esc_html_e( 'Convert from a bundle (.zip)', 'fw' ); ?></h2>
			<p class="description" style="max-width:54em">
				<?php esc_html_e( 'The one-shot path. Upload the .zip your agent produced and every phase it contains is applied in order: media (media.json) → styling presets (presets.json) → theme settings (theme-settings.json) → pages (pages.json) → menus (menus.json). Prefer to go piece by piece? Use the individual tools below.', 'fw' ); ?>
			</p>
			<form method="post" action="" enctype="multipart/form-data">
				<?php wp_nonce_field( self::NONCE ); ?>
				<input type="hidden" name="fw_sc_step" value="import_bundle">
				<input type="file" name="fw_sc_bundle" accept=".zip,application/zip">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Import bundle', 'fw' ); ?></button>
			</form>

			<hr style="margin:2.5em 0">

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

			<hr style="margin:2.5em 0">

			<h2><?php esc_html_e( 'Import Styling Presets', 'fw' ); ?></h2>
			<p class="description" style="max-width:54em">
				<?php esc_html_e( 'Paste the presets JSON your agent produced (the presets.json / _fw_presets_export payload from the conversion contract). It writes the palette, font sizes, button colors, and spacing / gap scales into your site\'s Styling Presets in one step. Only known preset keys are applied — anything else is skipped.', 'fw' ); ?>
			</p>
			<form method="post" action="">
				<?php wp_nonce_field( self::NONCE ); ?>
				<input type="hidden" name="fw_sc_step" value="import_presets">
				<textarea name="fw_sc_presets_json" rows="10" class="large-text code" spellcheck="false" placeholder='{ "values": { "theme_colors": [ ... ], "font_sizes": [ ... ] } }'></textarea>
				<p class="description"><?php echo esc_html( sprintf( __( 'Importable keys: %s.', 'fw' ), implode( ', ', FW_Site_Converter_Presets::allowed_keys() ) ) ); ?></p>
				<p class="submit">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Import presets', 'fw' ); ?></button>
				</p>
			</form>

			<hr style="margin:2.5em 0">

			<h2><?php esc_html_e( 'Import Theme Settings', 'fw' ); ?></h2>
			<p class="description" style="max-width:54em">
				<?php esc_html_e( 'Paste the theme-settings design file your agent produced (the _fw_settings_export / theme-settings .json). It applies global chrome, typography defaults, header / footer slot config and any bespoke CSS (misc_custom_css) to your Theme Settings — overlaying only the keys the file carries. Operational keys (analytics, custom scripts, maintenance) are never imported, and source-site media references are dropped (the media tool re-attaches them).', 'fw' ); ?>
			</p>
			<form method="post" action="">
				<?php wp_nonce_field( self::NONCE ); ?>
				<input type="hidden" name="fw_sc_step" value="import_theme_settings">
				<textarea name="fw_sc_theme_json" rows="10" class="large-text code" spellcheck="false" placeholder='{ "_fw_settings_export": { "theme_id": "unysonplus" }, "values": { "misc_custom_css": "…", "header_layout": "…" } }'></textarea>
				<p class="description"><?php esc_html_e( 'Applies to fw_theme_settings_options. The theme also has its own Appearance → Theme Settings → Miscellaneous → Import for the same file.', 'fw' ); ?></p>
				<p class="submit">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Apply theme settings', 'fw' ); ?></button>
				</p>
			</form>

			<?php $diag = FW_Site_Converter_Theme_Settings::diagnose(); ?>
			<p class="description" style="max-width:54em;margin-top:1em">
				<strong><?php esc_html_e( 'Theme Settings page blank or not loading?', 'fw' ); ?></strong>
				<?php esc_html_e( 'Use the doctor below to inspect the stored settings and, if needed, reset them to defaults so the page renders again.', 'fw' ); ?>
			</p>
			<?php if ( $stage === 'theme_reset' ) : ?>
				<div class="notice notice-success is-dismissible"><p>
					<?php echo esc_html( ! empty( $data['existed'] )
						? sprintf( __( 'Theme settings were reset — deleted %s. Reload Appearance → Theme Settings; it now uses defaults.', 'fw' ), $data['option_name'] )
						: sprintf( __( 'Nothing to reset — %s did not exist.', 'fw' ), $data['option_name'] ) ); ?>
				</p></div>
			<?php endif; ?>
			<details style="max-width:54em;border:1px solid #dcdcde;border-radius:4px;padding:.5em 1em;background:#fff"<?php echo ( $stage === 'theme_reset' ? ' open' : '' ); ?>>
				<summary style="cursor:pointer;font-weight:600"><?php esc_html_e( 'Theme Settings Doctor', 'fw' ); ?></summary>
				<table class="widefat striped" style="margin:1em 0">
					<tbody>
						<tr><td style="width:220px"><?php esc_html_e( 'Active theme', 'fw' ); ?></td><td><code><?php echo esc_html( $diag['theme_name'] . ' (' . $diag['theme_id'] . ')' ); ?></code></td></tr>
						<tr><td><?php esc_html_e( 'Settings option', 'fw' ); ?></td><td><code><?php echo esc_html( $diag['option_name'] ); ?></code> — <?php echo $diag['exists']
							? esc_html( sprintf( __( 'exists, %1$s bytes, %2$d top-level key(s)', 'fw' ), number_format_i18n( $diag['size'] ), $diag['key_count'] ) )
							: esc_html__( 'not set (using defaults)', 'fw' ); ?></td></tr>
						<?php if ( $diag['nonarray'] ) : ?>
						<tr><td><?php esc_html_e( 'Suspicious values', 'fw' ); ?></td><td style="color:#b32d2e"><?php echo esc_html( implode( ', ', $diag['nonarray'] ) ); ?> — <?php esc_html_e( 'scalar where a container is expected', 'fw' ); ?></td></tr>
						<?php endif; ?>
						<tr><td><code>fw_get_db_settings_option()</code></td><td><?php
							if ( $diag['get_error'] !== '' ) {
								echo '<span style="color:#b32d2e">ERROR: ' . esc_html( $diag['get_error'] ) . '</span>';
							} else {
								echo $diag['get_ok'] ? esc_html( sprintf( __( 'ok — %d key(s)', 'fw' ), $diag['get_count'] ) ) : esc_html__( 'returned a non-array', 'fw' );
							}
						?></td></tr>
						<tr><td><?php esc_html_e( 'Registered settings options', 'fw' ); ?></td><td><?php
							if ( $diag['reg_error'] !== '' ) {
								echo '<span style="color:#b32d2e">ERROR: ' . esc_html( $diag['reg_error'] ) . '</span>';
							} else {
								echo esc_html( (string) $diag['registered'] );
								if ( $diag['registered'] === 0 ) {
									echo ' — <span style="color:#b32d2e">' . esc_html__( 'none collected (this alone makes the page blank)', 'fw' ) . '</span>';
								}
							}
						?></td></tr>
						<?php if ( $diag['keys'] ) : ?>
						<tr><td><?php esc_html_e( 'Top-level keys', 'fw' ); ?></td><td><code style="word-break:break-all;font-size:11px"><?php echo esc_html( implode( ', ', $diag['keys'] ) ); ?></code></td></tr>
						<?php endif; ?>
					</tbody>
				</table>
				<form method="post" action="" onsubmit="return confirm('<?php echo esc_js( __( 'Reset ALL theme settings to defaults? This deletes the stored settings option. You can re-import a design file afterwards.', 'fw' ) ); ?>');">
					<?php wp_nonce_field( self::NONCE ); ?>
					<input type="hidden" name="fw_sc_step" value="reset_theme_settings">
					<button type="submit" class="button button-secondary" style="color:#b32d2e;border-color:#b32d2e"><?php esc_html_e( 'Reset theme settings to defaults', 'fw' ); ?></button>
					<span class="description" style="margin-left:.5em"><?php esc_html_e( 'Deletes the stored option; the page falls back to defaults.', 'fw' ); ?></span>
				</form>
			</details>

			<hr style="margin:2.5em 0">

			<h2><?php esc_html_e( 'Import Pages', 'fw' ); ?></h2>
			<p class="description" style="max-width:54em">
				<?php esc_html_e( 'Paste the page bodies as JSON — each is created as a WordPress page from its page-builder tree. The plugin generates the content with its own encoder (nothing is hand-coded), so each page stays fully editable in the builder. Pages are matched by slug, so re-running updates rather than duplicates. Tip: start with a single page to confirm it renders and opens in the builder before importing many.', 'fw' ); ?>
			</p>
			<form method="post" action="">
				<?php wp_nonce_field( self::NONCE ); ?>
				<input type="hidden" name="fw_sc_step" value="import_pages">
				<textarea name="fw_sc_pages_json" rows="12" class="large-text code" spellcheck="false" placeholder='{ "pages": [ { "title": "Home", "slug": "home", "front_page": true, "builder": [ { "type": "section", "atts": {}, "_items": [] } ] } ] }'></textarea>
				<p class="description"><?php esc_html_e( 'Each page: title, optional slug / status / front_page, and a "builder" tree (array of sections) or a stringified "json".', 'fw' ); ?></p>
				<p class="submit">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Import pages', 'fw' ); ?></button>
				</p>
			</form>

			<hr style="margin:2.5em 0">

			<h2><?php esc_html_e( 'Import Menus', 'fw' ); ?></h2>
			<p class="description" style="max-width:54em">
				<?php esc_html_e( 'Scan a source page to extract its header / footer navigation automatically, or paste the menus JSON yourself. Each menu is created (or rebuilt if it already exists) from its items and assigned to a theme menu location. Internal links that match an existing page become real page menu items; everything else becomes a custom link. Re-running rebuilds the same menus — it never duplicates them.', 'fw' ); ?>
			</p>

			<form method="post" action="" style="margin:0 0 1em">
				<?php wp_nonce_field( self::NONCE ); ?>
				<input type="hidden" name="fw_sc_step" value="scan_menus">
				<input type="url" name="fw_sc_menus_url" class="regular-text" style="width:28em;max-width:100%" placeholder="https://source-site.com/" value="<?php echo esc_attr( $stage === 'menus_scanned' && isset( $data['source'] ) ? $data['source'] : '' ); ?>">
				<button type="submit" class="button"><?php esc_html_e( 'Scan navigation', 'fw' ); ?></button>
				<span class="description" style="margin-left:.5em"><?php esc_html_e( 'Fetches the page and fills the box below for review.', 'fw' ); ?></span>
			</form>
			<?php
			$loc_slugs = array_keys( FW_Site_Converter_Menus::registered_locations() );
			?>
			<form method="post" action="">
				<?php wp_nonce_field( self::NONCE ); ?>
				<input type="hidden" name="fw_sc_step" value="import_menus">
				<textarea name="fw_sc_menus_json" rows="14" class="large-text code" spellcheck="false" placeholder='{ "menus": [ { "name": "Primary", "location": "primary", "items": [ { "label": "Home", "url": "/" }, { "label": "About", "url": "/about" }, { "label": "Services", "url": "#", "children": [ { "label": "Design", "url": "/services/design" } ] } ] }, { "name": "Footer", "location": "footer", "items": [ { "label": "Privacy", "url": "/privacy" } ] } ] }'><?php echo esc_textarea( $menus_prefill ); ?></textarea>
				<p class="description">
					<?php
					if ( $loc_slugs ) {
						echo esc_html( sprintf( __( 'This theme\'s menu locations: %s. (If you omit "location", Primary / Footer names are matched to those automatically.)', 'fw' ), implode( ', ', $loc_slugs ) ) );
					} else {
						esc_html_e( 'The active theme registers no menu locations — menus will be created but you\'ll assign them under Appearance → Menus.', 'fw' );
					}
					?>
				</p>
				<p class="submit">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Import menus', 'fw' ); ?></button>
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * Success / skip notice after a presets import.
	 *
	 * @param array $data
	 */
	private function render_presets_result( array $data ) {
		$imported = isset( $data['imported'] ) && is_array( $data['imported'] ) ? $data['imported'] : array();
		$skipped  = isset( $data['skipped'] ) && is_array( $data['skipped'] ) ? $data['skipped'] : array();

		if ( empty( $imported ) ) {
			echo '<div class="notice notice-warning is-dismissible"><p>'
				. esc_html__( 'No known preset keys were found in that payload — nothing was imported.', 'fw' )
				. '</p></div>';
		} else {
			$parts = array();
			foreach ( $imported as $key => $count ) {
				$parts[] = $key . ' (' . (int) $count . ')';
			}
			echo '<div class="notice notice-success is-dismissible"><p><strong>'
				. esc_html( sprintf(
					_n( 'Imported %d preset group:', 'Imported %d preset groups:', count( $imported ), 'fw' ),
					count( $imported )
				) ) . '</strong> ' . esc_html( implode( ', ', $parts ) ) . '</p></div>';
		}

		if ( ! empty( $skipped ) ) {
			echo '<div class="notice notice-info is-dismissible"><p>'
				. esc_html( sprintf( __( 'Skipped unknown keys: %s', 'fw' ), implode( ', ', $skipped ) ) )
				. '</p></div>';
		}
	}

	/**
	 * Success / detail notice after a theme-settings import.
	 *
	 * @param array $data
	 */
	private function render_theme_result( array $data ) {
		$imported = isset( $data['imported'] ) && is_array( $data['imported'] ) ? $data['imported'] : array();
		$skipped  = isset( $data['skipped'] ) && is_array( $data['skipped'] ) ? $data['skipped'] : array();

		if ( empty( $imported ) ) {
			echo '<div class="notice notice-warning is-dismissible"><p>'
				. esc_html__( 'No theme-settings keys were applied from that design file.', 'fw' )
				. '</p></div>';
		} else {
			echo '<div class="notice notice-success is-dismissible"><p><strong>'
				. esc_html( sprintf(
					_n( 'Applied %d theme-settings key:', 'Applied %d theme-settings keys:', count( $imported ), 'fw' ),
					count( $imported )
				) ) . '</strong> ' . esc_html( implode( ', ', $imported ) ) . '</p></div>';
		}

		if ( ! empty( $data['cross_theme'] ) ) {
			echo '<div class="notice notice-warning is-dismissible"><p>'
				. esc_html__( 'Heads-up: that design file was exported from a different theme — some settings may not map exactly.', 'fw' )
				. '</p></div>';
		}

		if ( ! empty( $skipped ) ) {
			echo '<div class="notice notice-info is-dismissible"><p>'
				. esc_html( sprintf( __( 'Skipped operational keys (analytics / scripts / maintenance): %s', 'fw' ), implode( ', ', $skipped ) ) )
				. '</p></div>';
		}
	}

	/**
	 * Result table after a pages import (created / updated, with edit + view links).
	 *
	 * @param array $data
	 */
	private function render_pages_result( array $data ) {
		$pages = isset( $data['pages'] ) && is_array( $data['pages'] ) ? $data['pages'] : array();
		$ok    = array_filter( $pages, function ( $p ) {
			return empty( $p['error'] ) && ! empty( $p['id'] );
		} );

		$created = 0;
		$updated = 0;
		foreach ( $ok as $p ) {
			if ( ! empty( $p['created'] ) ) {
				$created++;
			} else {
				$updated++;
			}
		}

		if ( empty( $ok ) ) {
			echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'No pages were created.', 'fw' ) . '</p></div>';
		} else {
			echo '<div class="notice notice-success is-dismissible"><p><strong>'
				. esc_html( sprintf( __( 'Imported %1$d page(s): %2$d created, %3$d updated.', 'fw' ), count( $ok ), $created, $updated ) )
				. '</strong></p></div>';
		}

		foreach ( $pages as $p ) {
			if ( ! empty( $p['error'] ) ) {
				echo '<div class="notice notice-error is-dismissible"><p>'
					. esc_html( sprintf( __( 'Page "%1$s": %2$s', 'fw' ), isset( $p['title'] ) ? $p['title'] : '', $p['error'] ) )
					. '</p></div>';
			}
		}

		if ( $ok ) {
			echo '<table class="widefat striped" style="margin-bottom:1.5em"><thead><tr><th>'
				. esc_html__( 'Page', 'fw' ) . '</th><th style="width:90px">' . esc_html__( 'Status', 'fw' )
				. '</th><th style="width:150px">' . esc_html__( 'Links', 'fw' ) . '</th></tr></thead><tbody>';
			foreach ( $ok as $p ) {
				$edit = get_edit_post_link( $p['id'], 'raw' );
				$view = get_permalink( $p['id'] );
				echo '<tr><td>' . esc_html( $p['title'] ) . ' <code style="font-size:11px">/' . esc_html( $p['slug'] ) . '</code>'
					. ( ! empty( $p['front_page'] ) ? ' <span style="color:#1a7f37">' . esc_html__( '(front page)', 'fw' ) . '</span>' : '' ) . '</td>';
				echo '<td>' . ( ! empty( $p['created'] ) ? esc_html__( 'created', 'fw' ) : esc_html__( 'updated', 'fw' ) ) . '</td>';
				echo '<td>';
				if ( $edit ) {
					echo '<a href="' . esc_url( $edit ) . '">' . esc_html__( 'Edit', 'fw' ) . '</a> ';
				}
				if ( $view ) {
					echo '&middot; <a href="' . esc_url( $view ) . '" target="_blank" rel="noopener">' . esc_html__( 'View', 'fw' ) . '</a>';
				}
				echo '</td></tr>';
			}
			echo '</tbody></table>';
		}
	}

	/**
	 * Success / detail notice after a menus import.
	 *
	 * @param array $data
	 */
	private function render_menus_result( array $data ) {
		$menus     = isset( $data['menus'] ) && is_array( $data['menus'] ) ? $data['menus'] : array();
		$locations = isset( $data['locations'] ) && is_array( $data['locations'] ) ? $data['locations'] : array();

		$built = array_filter( $menus, function ( $m ) {
			return empty( $m['error'] );
		} );

		if ( empty( $built ) ) {
			echo '<div class="notice notice-warning is-dismissible"><p>'
				. esc_html__( 'No menus were imported — check the JSON shape (a { "menus": [ … ] } object, each menu with a name and items).', 'fw' )
				. '</p></div>';
		} else {
			$parts = array();
			foreach ( $built as $m ) {
				$where    = ! empty( $m['assigned'] )
					? sprintf( /* translators: %s: location slug */ __( '→ %s', 'fw' ), $m['location'] )
					: __( '(not assigned)', 'fw' );
				$parts[] = sprintf( '%1$s: %2$d item(s) %3$s', $m['name'], (int) $m['items'], $where );
			}
			echo '<div class="notice notice-success is-dismissible"><p><strong>'
				. esc_html( sprintf(
					_n( 'Imported %d menu:', 'Imported %d menus:', count( $built ), 'fw' ),
					count( $built )
				) ) . '</strong> ' . esc_html( implode( '; ', $parts ) ) . '</p></div>';
		}

		// Per-menu errors / unassigned warnings.
		foreach ( $menus as $m ) {
			if ( ! empty( $m['error'] ) ) {
				echo '<div class="notice notice-error is-dismissible"><p>'
					. esc_html( sprintf( __( 'Menu "%1$s": %2$s', 'fw' ), isset( $m['name'] ) ? $m['name'] : '', $m['error'] ) )
					. '</p></div>';
			} elseif ( empty( $m['assigned'] ) ) {
				$loc = isset( $m['location'] ) && $m['location'] !== '' ? $m['location'] : '';
				$msg = $loc !== ''
					? sprintf( __( 'Menu "%1$s" was created but its location "%2$s" is not registered by the active theme — assign it under Appearance → Menus.', 'fw' ), $m['name'], $loc )
					: sprintf( __( 'Menu "%1$s" was created but not assigned to a location (none given) — assign it under Appearance → Menus.', 'fw' ), $m['name'] );
				echo '<div class="notice notice-info is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
			}
		}

		if ( $locations ) {
			echo '<p class="description">'
				. esc_html( sprintf( __( 'Theme menu locations: %s', 'fw' ), implode( ', ', array_keys( $locations ) ) ) )
				. '</p>';
		}
	}

	/**
	 * Notice after a nav scan — what was extracted (the JSON is prefilled below).
	 *
	 * @param array $data
	 */
	private function render_menus_scanned( array $data ) {
		$menus  = isset( $data['menus'] ) && is_array( $data['menus'] ) ? $data['menus'] : array();
		$source = isset( $data['source'] ) ? $data['source'] : '';

		$parts = array();
		foreach ( $menus as $m ) {
			$count   = isset( $m['items'] ) && is_array( $m['items'] ) ? count( $m['items'] ) : 0;
			$parts[] = sprintf( '%1$s (%2$d)', isset( $m['name'] ) ? $m['name'] : '?', $count );
		}

		if ( $parts ) {
			echo '<div class="notice notice-info is-dismissible"><p>'
				. esc_html( sprintf(
					/* translators: 1: source URL, 2: list like "Primary (5), Footer (8)" */
					__( 'Scanned %1$s — extracted %2$s top-level item(s). Review the JSON below (edit if needed) and click “Import menus”.', 'fw' ),
					esc_url( $source ), implode( ', ', $parts )
				) ) . '</p></div>';
		} else {
			echo '<div class="notice notice-warning is-dismissible"><p>'
				. esc_html__( 'No navigation was found in that page. If it is a JavaScript app, paste the menus JSON manually.', 'fw' )
				. '</p></div>';
		}
	}

	/**
	 * Combined summary after a one-shot bundle import (media + presets + menus).
	 *
	 * @param array $data
	 */
	private function render_bundle_result( array $data ) {
		$manifest = isset( $data['manifest'] ) && is_array( $data['manifest'] ) ? $data['manifest'] : array();
		$deferred = isset( $data['deferred'] ) && is_array( $data['deferred'] ) ? $data['deferred'] : array();
		$source   = isset( $manifest['source'] ) ? $manifest['source'] : ( isset( $manifest['name'] ) ? $manifest['name'] : '' );

		$lines = array();

		if ( isset( $data['media'] ) && is_array( $data['media'] ) ) {
			$m       = $data['media'];
			$lines[] = sprintf(
				/* translators: 1: imported, 2: reused, 3: failed */
				__( 'Media — %1$d imported, %2$d reused, %3$d failed.', 'fw' ),
				(int) $m['imported'], (int) $m['reused'], (int) $m['failed']
			);
		}

		if ( isset( $data['presets'] ) && is_array( $data['presets'] ) ) {
			$imp  = isset( $data['presets']['imported'] ) && is_array( $data['presets']['imported'] ) ? $data['presets']['imported'] : array();
			$keys = array();
			foreach ( $imp as $k => $c ) {
				$keys[] = $k . ' (' . (int) $c . ')';
			}
			$lines[] = $keys
				? sprintf( __( 'Presets — %s.', 'fw' ), implode( ', ', $keys ) )
				: __( 'Presets — nothing applied.', 'fw' );
		}

		if ( isset( $data['theme_settings'] ) && is_array( $data['theme_settings'] ) ) {
			$ti      = isset( $data['theme_settings']['imported'] ) && is_array( $data['theme_settings']['imported'] ) ? $data['theme_settings']['imported'] : array();
			$lines[] = $ti
				? sprintf( __( 'Theme settings — %1$d key(s): %2$s.', 'fw' ), count( $ti ), implode( ', ', $ti ) )
				: __( 'Theme settings — nothing applied.', 'fw' );
		}

		if ( isset( $data['pages']['pages'] ) && is_array( $data['pages']['pages'] ) ) {
			$pp = array_filter( $data['pages']['pages'], function ( $p ) {
				return empty( $p['error'] ) && ! empty( $p['id'] );
			} );
			$created = count( array_filter( $pp, function ( $p ) { return ! empty( $p['created'] ); } ) );
			$lines[] = $pp
				? sprintf( __( 'Pages — %1$d (%2$d created, %3$d updated).', 'fw' ), count( $pp ), $created, count( $pp ) - $created )
				: __( 'Pages — none created.', 'fw' );
		}

		if ( isset( $data['menus']['menus'] ) && is_array( $data['menus']['menus'] ) ) {
			$parts = array();
			foreach ( $data['menus']['menus'] as $mm ) {
				if ( ! empty( $mm['error'] ) ) {
					continue;
				}
				$where   = ! empty( $mm['assigned'] ) ? '→ ' . $mm['location'] : __( '(unassigned)', 'fw' );
				$parts[] = sprintf( '%1$s: %2$d %3$s', $mm['name'], (int) $mm['items'], $where );
			}
			$lines[] = $parts
				? sprintf( __( 'Menus — %s.', 'fw' ), implode( '; ', $parts ) )
				: __( 'Menus — nothing built.', 'fw' );
		}

		$head = $source !== ''
			? sprintf( __( 'Imported bundle (%s).', 'fw' ), $source )
			: __( 'Imported bundle.', 'fw' );

		echo '<div class="notice notice-success is-dismissible"><p><strong>' . esc_html( $head ) . '</strong></p>';
		if ( $lines ) {
			echo '<ul style="margin:.2em 0 .4em 1.5em;list-style:disc">';
			foreach ( $lines as $l ) {
				echo '<li>' . esc_html( $l ) . '</li>';
			}
			echo '</ul>';
		}
		echo '</div>';

		if ( $deferred ) {
			echo '<div class="notice notice-info is-dismissible"><p>'
				. esc_html( sprintf( __( 'The bundle also contained sections not applied yet (coming soon): %s.', 'fw' ), implode( ', ', $deferred ) ) )
				. '</p></div>';
		}
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
