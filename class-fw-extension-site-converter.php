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
		require_once $this->get_declared_path( '/includes/class-fw-site-converter-theme-generator.php' );
		require_once $this->get_declared_path( '/includes/class-fw-site-converter-mapper.php' );

		if ( is_admin() ) {
			add_action( 'admin_menu', array( $this, '_action_admin_menu' ), 30 );
			// Slot our page into the shared Unyson+ submenu order (the Post Types
			// extension owns the sort; we just declare where we want to sit).
			add_filter( 'fw_unysonplus_admin_submenu_order', array( $this, '_filter_submenu_order' ) );
			// We have no settings-options.php (our admin home is a custom page),
			// so expose a "Settings" link on the Extensions-manager card that
			// points at the Convert page.
			add_filter( 'fw_ext_manager_settings_url', array( $this, '_filter_manager_settings_url' ), 10, 2 );
			// One-image-at-a-time import endpoint (drives the progress bar).
			add_action( 'wp_ajax_fw_sc_import', array( $this, '_ajax_import' ) );
			// Site Analyzer: apply a bundle the local capture service produced.
			add_action( 'wp_ajax_fw_sc_analyze_apply', array( $this, '_ajax_analyze_apply' ) );
			// Mapper: build the page from the user's corrected element→role mapping.
			add_action( 'wp_ajax_fw_sc_build_mapping', array( $this, '_ajax_build_mapping' ) );
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
	 * Expose a custom "Settings" link on this extension's Extensions-manager
	 * card (it has no settings-options.php, so the built-in link is absent).
	 *
	 * @param string $url   Current settings URL ('' when none).
	 * @param string $name  Extension name the card is being rendered for.
	 * @return string
	 */
	public function _filter_manager_settings_url( $url, $name ) {
		if ( $name === $this->get_name() ) {
			return self::get_page_url();
		}
		return $url;
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
			add_action( 'admin_enqueue_scripts', array( $this, '_enqueue_assets' ) );
		}
	}

	/**
	 * Enqueue WordPress's bundled CodeMirror for the JSON paste areas on this page.
	 *
	 * `wp_enqueue_code_editor()` ships with core (it's the same editor the plugin's
	 * own code-editor option type uses), so this adds NO new dependency. The inline
	 * boot script (printed in render_page) turns every <textarea class="fw-sc-json">
	 * into a JSON editor and wires up the per-editor "Import from file…" buttons.
	 *
	 * @internal
	 * @param string $hook Current admin page hook suffix.
	 */
	public function _enqueue_assets( $hook ) {
		if ( $hook !== $this->hook_suffix ) {
			return;
		}
		// Registers wp.codeEditor + the JSON syntax-mode bundle (returns false if the
		// user disabled syntax highlighting in their profile — the boot script copes).
		wp_enqueue_code_editor( array( 'type' => 'application/json' ) );
	}

	/* ---------------------------------------------------------------------- *
	 * Run handler (load- hook, before output → PRG redirect)
	 * ---------------------------------------------------------------------- */

	private function results_transient_key() {
		return 'fw_site_converter_media_' . get_current_user_id();
	}

	/**
	 * Where the 'design' phase stashes its per-phase summary (media / presets / theme settings /
	 * theme / style guide) so the later 'pages' build can fold it into the final "Imported bundle"
	 * notice — otherwise that notice would list only Pages, hiding everything design did.
	 */
	private function design_summary_key() {
		return 'fw_site_converter_design_' . get_current_user_id();
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
		} elseif ( $step === 'generate_theme' ) {
			$this->run_generate_theme();
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
	 * Theme generator — the header/footer conversion. Takes a design config (chrome
	 * layout, CTA, fonts, colors, footer, carried CSS) and produces a real theme
	 * that reproduces the source's header/footer DESIGN (never its content). Two
	 * actions: "install" writes it into wp-content/themes (PRG → result), "download"
	 * streams a .zip (handled here on the load- hook, before any output).
	 */
	private function run_generate_theme() {
		$raw    = isset( $_POST['fw_sc_theme_config'] ) ? (string) wp_unslash( $_POST['fw_sc_theme_config'] ) : '';
		$mode   = ( isset( $_POST['fw_sc_theme_mode'] ) && $_POST['fw_sc_theme_mode'] === 'standalone' ) ? 'standalone' : 'child';
		$action = ( isset( $_POST['fw_sc_theme_action'] ) && $_POST['fw_sc_theme_action'] === 'download' ) ? 'download' : 'install';

		// Blank textarea → fall back to the LAST applied bundle's design-config, so this button is a
		// one-click "re-install the theme" without re-pasting (the empty-field error was just confusing).
		if ( trim( $raw ) === '' ) {
			$raw = (string) get_option( 'fw_sc_last_theme_design', '' );
		}

		$config = json_decode( $raw, true );
		if ( ! is_array( $config ) ) {
			set_transient( $this->results_transient_key(), array( 'error' => __( 'No theme config to install. Apply a converted bundle first (that installs the theme automatically), or paste a design-config JSON here.', 'fw' ) ), 5 * MINUTE_IN_SECONDS );
			$this->redirect_back();
		}

		// Remember the config so the panel pre-fills + re-installs work next time.
		update_option( 'fw_sc_last_theme_design', wp_json_encode( $config ), false );

		// The radio is the source of truth for mode; fold it into the config.
		if ( ! isset( $config['theme'] ) || ! is_array( $config['theme'] ) ) {
			$config['theme'] = array();
		}
		$config['theme']['mode'] = $mode;

		if ( $action === 'download' ) {
			$zip = FW_Site_Converter_Theme_Generator::build_zip( $config );
			if ( $zip['error'] !== '' || empty( $zip['path'] ) || ! is_file( $zip['path'] ) ) {
				set_transient( $this->results_transient_key(), array( 'error' => $zip['error'] !== '' ? $zip['error'] : __( 'Could not build the theme zip.', 'fw' ) ), 5 * MINUTE_IN_SECONDS );
				$this->redirect_back();
			}
			// Stream the zip (we're on the load- hook — nothing has been output yet).
			nocache_headers();
			header( 'Content-Type: application/zip' );
			header( 'Content-Disposition: attachment; filename="' . $zip['filename'] . '"' );
			header( 'Content-Length: ' . filesize( $zip['path'] ) );
			readfile( $zip['path'] );
			@unlink( $zip['path'] );
			exit;
		}

		$res = FW_Site_Converter_Theme_Generator::install( $config );
		if ( $res['error'] !== '' ) {
			set_transient( $this->results_transient_key(), array( 'error' => $res['error'] ), 5 * MINUTE_IN_SECONDS );
			$this->redirect_back();
		}

		$res['stage'] = 'theme_generated';
		set_transient( $this->results_transient_key(), $res, 5 * MINUTE_IN_SECONDS );
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

	/**
	 * @internal
	 * Site Analyzer endpoint: the admin page fetches a Convert bundle from the local
	 * capture service (in the user's browser) and posts the .zip here; we apply it via
	 * the bundle importer and hand back the results-page URL (so the existing
	 * bundle_result view shows the per-phase summary).
	 */
	public function _ajax_analyze_apply() {
		check_ajax_referer( self::NONCE );
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'fw' ) ), 403 );
		}

		$file = isset( $_FILES['bundle'] ) ? $_FILES['bundle'] : null;
		if ( ! $file || empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) || ! empty( $file['error'] ) ) {
			wp_send_json_error( array( 'message' => __( 'No bundle was received from the capture service.', 'fw' ) ) );
		}

		$phase  = isset( $_POST['phase'] ) ? sanitize_key( wp_unslash( $_POST['phase'] ) ) : '';
		// Apply-time options (checkboxes on the Convert panel). Each posted as '1'/'0'; absent = on
		// (back-compat full convert). The JS sends them as opt_theme / opt_media / opt_header / opt_footer.
		$opt_on = function ( $k ) { return ! isset( $_POST[ $k ] ) || $_POST[ $k ] === '1' || $_POST[ $k ] === 'true'; };
		$opts   = array(
			'theme'  => $opt_on( 'opt_theme' ),
			'media'  => $opt_on( 'opt_media' ),
			'header' => $opt_on( 'opt_header' ),
			'footer' => $opt_on( 'opt_footer' ),
		);
		$result = FW_Site_Converter_Bundle::import_zip( $file['tmp_name'], $phase, $opts );

		if ( $result['error'] !== '' && empty( $result['sections'] ) ) {
			wp_send_json_error( array( 'message' => $result['error'] ) );
		}

		// Review-first: the 'design' phase builds the style guide + theme; return its link
		// (no redirect) so the user can review before the separate 'pages' step.
		if ( $phase === 'design' ) {
			// Stash the per-phase summary so the later 'pages' build can report it in the final
			// "Imported bundle" notice (media / presets / theme settings / theme / style guide).
			$design_sections = array();
			foreach ( array( 'media', 'presets', 'theme_settings', 'theme', 'styleguide' ) as $sk ) {
				if ( isset( $result[ $sk ] ) ) { $design_sections[] = $sk; }
			}
			set_transient( $this->design_summary_key(), array(
				'sections'       => $design_sections,
				'media'          => isset( $result['media'] ) ? $result['media'] : null,
				'presets'        => isset( $result['presets'] ) ? $result['presets'] : null,
				'theme_settings' => isset( $result['theme_settings'] ) ? $result['theme_settings'] : null,
				'theme'          => isset( $result['theme'] ) ? $result['theme'] : null,
				'styleguide'     => isset( $result['styleguide'] ) ? $result['styleguide'] : null,
				'styleguide_url' => isset( $result['styleguide_url'] ) ? $result['styleguide_url'] : '',
			), 30 * MINUTE_IN_SECONDS );

			wp_send_json_success( array(
				'phase'           => 'design',
				'styleguide_url'  => isset( $result['styleguide_url'] ) ? $result['styleguide_url'] : '',
				'theme_activated' => ! empty( $result['theme']['activated'] ),
				// Surface media import results so a silent failure (e.g. a rejected file type)
				// is visible in the convert panel instead of leaving images hotlinked.
				'media'           => isset( $result['media'] ) ? $result['media'] : null,
				// The role-suggested mapping for the review editor (null if the bundle has none).
				'mapping'         => isset( $result['mapping'] ) ? $result['mapping'] : null,
				'roles'           => FW_Site_Converter_Mapper::roles(),
			) );
		}

		$result['stage'] = 'bundle_result';
		set_transient( $this->results_transient_key(), $result, 5 * MINUTE_IN_SECONDS );

		wp_send_json_success( array(
			'redirect' => add_query_arg(
				array( 'page' => self::PAGE_SLUG, 'fw-sc-done' => '1' ),
				admin_url( 'admin.php' )
			),
		) );
	}

	/**
	 * Build the converted page(s) from the user's corrected element→role mapping (the review
	 * editor posts it here). Builds the page-builder tree via the Mapper, imports it, and learns
	 * the corrections as rules. PRG-redirects to the per-phase results view.
	 *
	 * @internal
	 */
	public function _ajax_build_mapping() {
		check_ajax_referer( self::NONCE );
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'fw' ) ), 403 );
		}

		$raw     = isset( $_POST['mapping'] ) ? wp_unslash( $_POST['mapping'] ) : '';
		$mapping = is_string( $raw ) ? json_decode( $raw, true ) : ( is_array( $raw ) ? $raw : null );
		if ( ! is_array( $mapping ) || empty( $mapping['pages'] ) ) {
			wp_send_json_error( array( 'message' => __( 'No mapping was received.', 'fw' ) ) );
		}

		// "Grab content only" (Create child theme unchecked): import the converted sections as a
		// NEW page — homepage untouched — and write NO section CSS (the dev just wants the content/
		// structure into their existing site, then styles it themselves).
		$grab_only = isset( $_POST['opt_theme'] ) && $_POST['opt_theme'] === '0';

		$pages = FW_Site_Converter_Mapper::build_pages( $mapping );
		if ( $grab_only ) {
			foreach ( $pages as $i => $pg ) {
				$pages[ $i ]['front_page'] = false; // don't override the homepage
				$base = isset( $pg['title'] ) && trim( (string) $pg['title'] ) !== '' ? trim( (string) $pg['title'] ) : 'Converted content';
				$pages[ $i ]['title'] = $base . ' (Converted)';
				$pages[ $i ]['slug']  = ''; // derive a fresh, unique slug from the title → a new page
			}
		}
		$res   = FW_Site_Converter_Pages::import( array( 'pages' => $pages ) );
		$learned = FW_Site_Converter_Mapper::learn( $mapping );

		// Merge the mapped per-section CSS (flattened to the rebuilt elements, animations stripped
		// unless opted in) INTO the child theme's style.css — between the generator's SC:SECTIONS
		// markers — so the converted site loads ONE clean child stylesheet (no separate
		// converted-page.css). Plain file_put_contents (the theme generator writes the theme the
		// same way) — NOT gated on wp_is_writable(), which is false on WP Engine though writes work.
		// Skipped in "grab content only" mode — we don't touch the active theme's stylesheet.
		$page_css = $grab_only ? '' : FW_Site_Converter_Mapper::page_css( $mapping );
		$child_css = false;
		if ( ! $grab_only && function_exists( 'get_stylesheet_directory' ) && class_exists( 'FW_Site_Converter_Theme_Generator' ) ) {
			$dir = get_stylesheet_directory();
			$legacy = $dir . '/converted-page.css'; // remove the old separate file if present
			if ( file_exists( $legacy ) ) { @unlink( $legacy ); } // phpcs:ignore
			$style = $dir . '/style.css';
			$css   = @file_get_contents( $style ); // phpcs:ignore
			if ( is_string( $css ) && $css !== '' ) {
				$start = FW_Site_Converter_Theme_Generator::SECTIONS_START;
				$end   = FW_Site_Converter_Theme_Generator::SECTIONS_END;
				// Pretty-print the section CSS so the child stylesheet stays readable (not minified).
				$body  = trim( FW_Site_Converter_Theme_Generator::pretty_css( $page_css ) );
				$block = $start . "\n" . ( $body !== '' ? $body . "\n" : '' ) . $end;
				$s = strpos( $css, $start );
				$e = strpos( $css, $end );
				if ( false !== $s && false !== $e && $e >= $s ) {
					$new = rtrim( substr( $css, 0, $s ) ) . "\n\n" . $block . substr( $css, $e + strlen( $end ) );
				} else {
					$new = rtrim( $css ) . "\n\n" . $block . "\n"; // older theme without markers — append
				}
				$child_css = ( false !== @file_put_contents( $style, $new ) ); // phpcs:ignore
			}
		}

		$result_data = array(
			'stage'        => 'bundle_result',
			'sections'     => array(),
			'child_css'    => $child_css,
			'pages'        => $res,
			'learned'      => $learned,
		);

		// Fold in what the earlier 'design' phase did (theme, presets, media, style guide) so the
		// final "Imported bundle" notice reports the whole conversion — not just the pages step.
		$design = get_transient( $this->design_summary_key() );
		if ( is_array( $design ) ) {
			foreach ( array( 'media', 'presets', 'theme_settings', 'theme', 'styleguide' ) as $sk ) {
				if ( ! empty( $design[ $sk ] ) ) {
					$result_data[ $sk ]      = $design[ $sk ];
					$result_data['sections'][] = $sk;
				}
			}
			if ( ! empty( $design['styleguide_url'] ) ) { $result_data['styleguide_url'] = $design['styleguide_url']; }
			delete_transient( $this->design_summary_key() );
		}
		$result_data['sections'][] = 'pages';

		set_transient( $this->results_transient_key(), $result_data, 5 * MINUTE_IN_SECONDS );

		wp_send_json_success( array(
			'redirect' => add_query_arg( array( 'page' => self::PAGE_SLUG, 'fw-sc-done' => '1' ), admin_url( 'admin.php' ) ),
			'learned'  => $learned,
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
	 * A JSON paste field wrapped for the page: an "Import from file…" button (reads
	 * a file from disk straight into the box via FileReader) above a <textarea> that
	 * the page's boot script upgrades to a CodeMirror JSON editor. Falls back to a
	 * plain textarea when CodeMirror is unavailable.
	 *
	 * @param string $name        Form field name.
	 * @param string $placeholder Placeholder JSON shown when empty.
	 * @param string $value       Pre-filled value (already raw, not escaped).
	 * @param int    $rows        Textarea fallback height.
	 * @param string $accept      File-input accept filter.
	 * @return string HTML.
	 */
	private function json_editor_field( $name, $placeholder, $value = '', $rows = 10, $id = '', $accept = '.json,application/json,text/plain' ) {
		ob_start();
		?>
		<div class="fw-sc-editor">
			<p class="fw-sc-filerow">
				<label class="button fw-sc-file-btn"><span class="dashicons dashicons-upload"></span> <?php esc_html_e( 'Import from file…', 'fw' ); ?>
					<input type="file" accept="<?php echo esc_attr( $accept ); ?>" class="fw-sc-file" hidden>
				</label>
				<span class="fw-sc-fname description"></span>
				<span class="description fw-sc-orpaste"><?php esc_html_e( '— or paste / edit below.', 'fw' ); ?></span>
			</p>
			<textarea <?php if ( $id !== '' ) { echo 'id="' . esc_attr( $id ) . '" '; } ?>name="<?php echo esc_attr( $name ); ?>" rows="<?php echo (int) $rows; ?>" class="large-text code fw-sc-json" spellcheck="false" placeholder='<?php echo esc_attr( $placeholder ); ?>'><?php echo esc_textarea( $value ); ?></textarea>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * The "Theme Settings Doctor" — inspects the stored theme-settings option and
	 * offers a reset when the Theme Settings page renders blank. Lives in the
	 * Diagnostics tab; pulled into its own method so render_page() stays readable.
	 *
	 * @param string     $stage Current page stage (for the post-reset notice).
	 * @param array|bool $data  Transient result payload.
	 */
	private function render_theme_settings_doctor( $stage, $data ) {
		$diag = FW_Site_Converter_Theme_Settings::diagnose();
		?>
		<p class="description" style="margin-top:.4em">
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
		<table class="widefat striped" style="margin:1em 0;max-width:60em">
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
		<?php
	}

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
			<p class="description">
				<?php esc_html_e( 'Bring an AI-generated site into WordPress. The Convert tab renders a site URL and converts it in one step. Manual tools holds the piece-by-piece importers (bundle .zip, header/footer theme, images, styling presets, theme settings, pages, menus) for when you want to run a single phase by hand. Diagnostics has the capture-service health check and the Theme Settings doctor.', 'fw' ); ?>
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
			} elseif ( $stage === 'theme_generated' ) {
				$this->render_theme_generated( $data );
			}
			?>

			<h2 class="nav-tab-wrapper fw-sc-tabs" style="margin:.4em 0 1.4em">
				<a href="#convert"     class="nav-tab nav-tab-active" data-tab="convert"><?php esc_html_e( 'Convert', 'fw' ); ?></a>
				<a href="#tools"       class="nav-tab"               data-tab="tools"><?php esc_html_e( 'Manual tools', 'fw' ); ?></a>
				<a href="#diagnostics" class="nav-tab"               data-tab="diagnostics"><?php esc_html_e( 'Diagnostics', 'fw' ); ?></a>
			</h2>

			<div class="fw-sc-panel is-active" id="panel-convert">

			<details class="fw-sc-setup" open>
				<summary><span class="dashicons dashicons-admin-tools"></span> <?php esc_html_e( 'Capture service — set up once (3 steps)', 'fw' ); ?></summary>
				<div class="fw-sc-setup-body">
					<div class="fw-sc-why">
						<p style="margin:.2em 0 .5em"><strong><?php esc_html_e( 'Why does this need a small program running on my computer?', 'fw' ); ?></strong></p>
						<p style="margin:.2em 0">
							<?php esc_html_e( 'AI website builders (Lovable, v0, Bolt, and React / Vite apps) ship a nearly-empty HTML page and then build the real content in the browser with JavaScript. WordPress runs on PHP on the server — it can\'t open a browser — so on its own it would only ever see that empty shell, with no text, images, or styling to convert.', 'fw' ); ?>
						</p>
						<p style="margin:.4em 0 .2em">
							<?php esc_html_e( 'The capture service is a tiny helper that opens the page in real Google Chrome on your own machine, waits for it to finish rendering, then hands the finished HTML + styles back to this page. It uses Node.js simply because that\'s what can drive Chrome locally.', 'fw' ); ?>
						</p>
						<p style="margin:.4em 0 .2em;color:#1a7f37">
							<span class="dashicons dashicons-lock" style="vertical-align:text-bottom"></span>
							<?php esc_html_e( 'It runs entirely on your computer: your admin browser talks to it directly at localhost, and nothing about your site is ever sent to a third party.', 'fw' ); ?>
						</p>
					</div>
					<style>.fw-sc-code{position:relative}.fw-sc-copy{position:absolute;top:.35em;right:.4em;border:0;background:transparent;cursor:pointer;color:#646970;padding:2px;line-height:1}.fw-sc-copy:hover{color:#2271b1}.fw-sc-copy .dashicons{font-size:18px;width:18px;height:18px}</style>
					<ol style="margin:.6em 0 .4em 1.4em;padding:0">
						<li style="margin-bottom:.9em">
							<strong><?php esc_html_e( 'Install the prerequisites.', 'fw' ); ?></strong><br>
							<span class="description"><?php echo wp_kses_post( __( 'You need <a href="https://nodejs.org/" target="_blank" rel="noopener">Node.js 20 or newer</a> and <a href="https://www.google.com/chrome/" target="_blank" rel="noopener">Google Chrome</a> (the service uses your system Chrome to render). Confirm Node is installed:', 'fw' ) ); ?></span>
							<div class="fw-sc-code"><pre style="background:#f6f7f7;padding:.5em .8em;border-radius:4px;overflow:auto;margin:.4em 0;padding-right:2.6em">node -v</pre><button type="button" class="fw-sc-copy" title="Copy"><span class="dashicons dashicons-admin-page"></span></button></div>
						</li>
						<li style="margin-bottom:.9em">
							<strong><?php esc_html_e( 'Go to the service folder.', 'fw' ); ?></strong><br>
							<span class="description"><?php echo wp_kses_post( __( 'In the <code>unysonplus-html-to-wordpress-conversion</code> folder, open a terminal and go to the service folder:', 'fw' ) ); ?></span>
							<div class="fw-sc-code"><pre style="background:#f6f7f7;padding:.5em .8em;border-radius:4px;overflow:auto;margin:.4em 0;padding-right:2.6em">cd "tools\design-capture"</pre><button type="button" class="fw-sc-copy" title="Copy"><span class="dashicons dashicons-admin-page"></span></button></div>
							<span class="description"><?php echo wp_kses_post( __( '<strong>First time only</strong> — install its dependencies (run this once, in the same folder):', 'fw' ) ); ?></span>
							<div class="fw-sc-code"><pre style="background:#f6f7f7;padding:.5em .8em;border-radius:4px;overflow:auto;margin:.4em 0;padding-right:2.6em">npm install</pre><button type="button" class="fw-sc-copy" title="Copy"><span class="dashicons dashicons-admin-page"></span></button></div>
						</li>
						<li style="margin-bottom:.4em">
							<strong><?php esc_html_e( 'Start the service.', 'fw' ); ?></strong><br>
							<span class="description"><?php esc_html_e( 'Start it and keep the terminal window open while you convert sites:', 'fw' ); ?></span>
							<div class="fw-sc-code"><pre style="background:#f6f7f7;padding:.5em .8em;border-radius:4px;overflow:auto;margin:.4em 0;padding-right:2.6em">node serve.mjs</pre><button type="button" class="fw-sc-copy" title="Copy"><span class="dashicons dashicons-admin-page"></span></button></div>
							<span class="description"><?php echo wp_kses_post( __( 'It serves <code>http://localhost:8787</code> and the status next to the Analyze button turns green once it is detected. Need a different port? Set a <code>PORT</code> environment variable before starting.', 'fw' ) ); ?></span>
						</li>
					</ol>
					<script>(function(){document.addEventListener("click",function(e){var b=e.target.closest&&e.target.closest(".fw-sc-copy");if(!b)return;e.preventDefault();var pre=b.parentNode.querySelector("pre");if(!pre)return;var ok=function(){var i=b.querySelector(".dashicons");if(!i)return;var o=i.className;i.className="dashicons dashicons-yes";setTimeout(function(){i.className=o;},1200);};if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(pre.textContent.trim()).then(ok).catch(function(){});}else{var r=document.createRange();r.selectNode(pre);var sel=window.getSelection();sel.removeAllRanges();sel.addRange(r);try{document.execCommand("copy");ok();}catch(x){}sel.removeAllRanges();}});})();</script>
					<p style="margin:.6em 0 .2em"><label><strong><?php esc_html_e( 'Service URL', 'fw' ); ?></strong> <input type="url" id="fw-sc-an-svcurl" class="regular-text" value="http://localhost:8787" style="width:18em"></label></p>
					<p class="description"><?php esc_html_e( 'Tip: if the status shows “service not detected”, make sure the service from step 3 is still running and this URL matches its port. No Node? Use the manual .zip upload under the Manual tools tab.', 'fw' ); ?></p>
				</div>
			</details>

			<h2><?php esc_html_e( 'Convert a site by URL (Site Analyzer)', 'fw' ); ?> <span style="font-size:11px;background:#2271b1;color:#fff;border-radius:9px;padding:1px 7px;vertical-align:middle">beta</span></h2>
			<p class="description">
				<?php esc_html_e( 'Enter a site URL and it is rendered and converted in one step — theme, pages, menus and media. This uses the capture service you started above (your admin browser reaches it directly). Start it once and leave it running.', 'fw' ); ?>
			</p>
			<div class="fw-sc-analyze" style="margin:0 0 1em" data-nonce="<?php echo esc_attr( wp_create_nonce( self::NONCE ) ); ?>">
				<input type="url" id="fw-sc-an-url" class="regular-text" style="width:30em;max-width:100%" placeholder="https://your-site.lovable.app/">
				<button type="button" class="button button-primary" id="fw-sc-an-go"><?php esc_html_e( 'Analyze &amp; convert', 'fw' ); ?></button>
				<span id="fw-sc-an-svc" class="description" style="margin-left:.6em"></span>
				<p style="margin:.6em 0 0;font-size:12px;color:#3c434a">
					<label style="margin-right:1.2em"><input type="checkbox" id="fw-sc-opt-theme" checked> <?php esc_html_e( 'Create child theme', 'fw' ); ?> <span style="color:#646970">(<?php esc_html_e( 'off = grab content only', 'fw' ); ?>)</span></label>
					<label style="margin-right:1.2em"><input type="checkbox" id="fw-sc-opt-header" checked> <?php esc_html_e( 'Capture header', 'fw' ); ?></label>
					<label style="margin-right:1.2em"><input type="checkbox" id="fw-sc-opt-footer" checked> <?php esc_html_e( 'Capture footer', 'fw' ); ?></label>
					<label><input type="checkbox" id="fw-sc-opt-media" checked> <?php esc_html_e( 'Import images', 'fw' ); ?></label>
					<span class="description" style="display:block;margin-top:.2em"><?php esc_html_e( 'Grab content only (Create child theme off): the converted sections become a NEW page — your homepage and active theme are left untouched, and no section CSS is written. For quickly pulling a site\'s content/structure to build on. Choose which sections to keep in the review step.', 'fw' ); ?></span>
				</p>
				<div id="fw-sc-an-status" style="margin-top:.7em"></div>
			</div>
			<script>
			( function () {
				var nonce = document.querySelector( '.fw-sc-analyze' ).getAttribute( 'data-nonce' );
				var ajaxurl = window.ajaxurl || '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';
				var $url = document.getElementById( 'fw-sc-an-url' );
				var $go = document.getElementById( 'fw-sc-an-go' );
				var $svc = document.getElementById( 'fw-sc-an-svc' );
				var $svcUrl = document.getElementById( 'fw-sc-an-svcurl' );
				var $status = document.getElementById( 'fw-sc-an-status' );
				var LS = 'fw_sc_capture_service';
				if ( window.localStorage && localStorage.getItem( LS ) ) { $svcUrl.value = localStorage.getItem( LS ); }
				function svc() { return ( $svcUrl.value || 'http://localhost:8787' ).replace( /\/+$/, '' ); }
				function note( html, color ) { clearProg(); $status.innerHTML = '<div class="notice notice-' + ( color || 'info' ) + '" style="margin:.3em 0;padding:.5em .8em"><p style="margin:.2em 0">' + html + '</p></div>'; }

				// Progress bar. The capture is one blocking request (no server-side
				// progress stream), so the bar is TIME-ESTIMATED: it eases toward a
				// ceiling over the expected duration (asymptotic, so it never claims
				// to finish early), then snaps forward when the request actually
				// returns. Honest "still working" feedback, not a fake 0→100.
				var progTimer = null;
				function clearProg() { if ( progTimer ) { clearInterval( progTimer ); progTimer = null; } }
				function bar( label ) {
					$status.innerHTML =
						'<div class="notice notice-info" style="margin:.3em 0;padding:.6em .8em">' +
							'<p id="fw-sc-an-barlabel" style="margin:.2em 0 .55em">' + label + '</p>' +
							'<div style="height:8px;background:#e2e4e7;border-radius:6px;overflow:hidden">' +
								'<div id="fw-sc-an-bar" style="height:100%;width:3%;background:#2271b1;border-radius:6px;transition:width .35s ease"></div>' +
							'</div>' +
						'</div>';
				}
				function setBar( pct ) { var b = document.getElementById( 'fw-sc-an-bar' ); if ( b ) { b.style.width = Math.max( 0, Math.min( 100, pct ) ) + '%'; } }
				function barLabel( text ) { var l = document.getElementById( 'fw-sc-an-barlabel' ); if ( l ) { l.innerHTML = text; } }
				// Ease the fill from → to over estMs, approaching `to` but never passing it.
				function animateBar( from, to, estMs ) {
					clearProg();
					var start = Date.now();
					setBar( from );
					progTimer = setInterval( function () {
						var t = ( Date.now() - start ) / estMs;
						setBar( from + ( to - from ) * ( 1 - Math.exp( -1.8 * t ) ) );
					}, 150 );
				}

				function ping() {
					$svc.textContent = '<?php echo esc_js( __( 'checking service…', 'fw' ) ); ?>';
					fetch( svc() + '/health', { mode: 'cors' } ).then( function ( r ) { return r.json(); } )
						.then( function ( d ) { $svc.innerHTML = d && d.ok ? '<span style="color:#1a7f37">&#10003; <?php echo esc_js( __( 'capture service detected', 'fw' ) ); ?></span>' : '<span style="color:#b32d2e"><?php echo esc_js( __( 'service not detected', 'fw' ) ); ?></span>'; } )
						.catch( function () { $svc.innerHTML = '<span style="color:#b32d2e"><?php echo esc_js( __( 'service not detected — start node serve.mjs', 'fw' ) ); ?></span>'; } );
				}
				$svcUrl.addEventListener( 'change', function () { if ( window.localStorage ) { localStorage.setItem( LS, svc() ); } ping(); } );
				ping();

				// Held between the two steps so "Convert the pages" reuses the same bundle.
				var lastBlob = null;
				function applyPhase( blob, phase, label ) {
					clearProg();
					bar( label );
					animateBar( 90, 99, 8000 );
					var fd = new FormData();
					fd.append( 'action', 'fw_sc_analyze_apply' );
					fd.append( '_wpnonce', nonce );
					fd.append( 'phase', phase );
					// Apply-time options (default on). The design phase honors theme/media/header/footer.
					var optEl = function ( id ) { var e = document.getElementById( id ); return ( !e || e.checked ) ? '1' : '0'; };
					fd.append( 'opt_theme',  optEl( 'fw-sc-opt-theme' ) );
					fd.append( 'opt_media',  optEl( 'fw-sc-opt-media' ) );
					fd.append( 'opt_header', optEl( 'fw-sc-opt-header' ) );
					fd.append( 'opt_footer', optEl( 'fw-sc-opt-footer' ) );
					fd.append( 'bundle', blob, 'convert-bundle.zip' );
					return fetch( ajaxurl, { method: 'POST', credentials: 'same-origin', body: fd } ).then( function ( r ) { return r.json(); } );
				}
				function fail( err ) {
					note( '<?php echo esc_js( __( 'Failed:', 'fw' ) ); ?> ' + ( err && err.message ? err.message : err ) + '. <?php echo esc_js( __( 'Is the capture service running? You can also use the manual .zip upload below.', 'fw' ) ); ?>', 'error' );
					$go.disabled = false;
				}

				$go.addEventListener( 'click', function () {
					var target = ( $url.value || '' ).trim();
					if ( ! /^https?:\/\//i.test( target ) ) { note( '<?php echo esc_js( __( 'Enter a full URL (https://…).', 'fw' ) ); ?>', 'warning' ); return; }
					$go.disabled = true;
					bar( '<?php echo esc_js( __( 'Rendering the site in headless Chrome… this takes ~15s.', 'fw' ) ); ?>' );
					animateBar( 3, 90, 15000 );
					fetch( svc() + '/capture?url=' + encodeURIComponent( target ), { mode: 'cors' } )
						.then( function ( r ) {
							if ( ! r.ok ) { return r.json().then( function ( e ) { throw new Error( ( e && e.error ) || ( 'HTTP ' + r.status ) ); } ); }
							return r.blob();
						} )
						.then( function ( blob ) {
							// Step 1 — apply the DESIGN system + build the Style Guide page (no pages yet).
							lastBlob = blob;
							return applyPhase( blob, 'design', '<?php echo esc_js( __( 'Building the design system + style guide…', 'fw' ) ); ?>' );
						} )
						.then( function ( res ) {
							if ( ! ( res && res.success ) ) { throw new Error( ( res && res.data && res.data.message ) || '<?php echo esc_js( __( 'Could not build the style guide.', 'fw' ) ); ?>' ); }
							clearProg(); setBar( 100 );
							var url = ( res.data && res.data.styleguide_url ) ? res.data.styleguide_url : '';
							var link = url ? ' <a href="' + url + '" target="_blank" rel="noopener"><?php echo esc_js( __( 'Open the Style Guide ↗', 'fw' ) ); ?></a>' : '';
							// Media import summary — makes a silent image failure (e.g. rejected file type) visible.
							var md = res.data && res.data.media, mediaNote = '';
							if ( md ) {
								mediaNote = '<p style="margin:.2em 0;font-size:12px;color:#555"><?php echo esc_js( __( 'Images:', 'fw' ) ); ?> '
									+ ( md.imported || 0 ) + ' imported, ' + ( md.reused || 0 ) + ' reused, ' + ( md.failed || 0 ) + ' failed.</p>';
								if ( md.errors && md.errors.length ) {
									mediaNote += '<details style="margin:.2em 0"><summary style="cursor:pointer;color:#b32d2e">'
										+ md.failed + ' <?php echo esc_js( __( 'image(s) failed to import — details', 'fw' ) ); ?></summary>'
										+ '<ul style="font-size:11px;margin:.4em 0 0 1.2em;list-style:disc">'
										+ md.errors.map( function ( e ) { return '<li>' + String( e ).replace( /</g, '&lt;' ) + '</li>'; } ).join( '' )
										+ '</ul></details>';
								}
							}
							var mapping = res.data && res.data.mapping;
							var roles = ( res.data && res.data.roles ) || {};
							function escH( s ) { return String( s == null ? '' : s ).replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' ); }
							function preview( b ) {
								if ( b.t === 'row' ) { return '<em>' + ( b.cols ? b.cols.length : 0 ) + ' <?php echo esc_js( __( 'columns', 'fw' ) ); ?></em>'; }
								if ( b.t === 'button' ) { return '▭ ' + escH( b.label || '' ); }
								if ( b.t === 'html' ) { return '<code style="font-size:11px;color:#777">' + escH( ( b.html || '' ).replace( /\s+/g, ' ' ).slice( 0, 70 ) ) + '…</code>'; }
								return ( b.tag ? '<span style="color:#999">&lt;' + escH( b.tag ) + '&gt;</span> ' : '' ) + escH( ( b.text || '' ).slice( 0, 90 ) );
							}
							function sel( p, s, bi, cur ) {
								var o = ''; for ( var k in roles ) { o += '<option value="' + k + '"' + ( k === cur ? ' selected' : '' ) + '>' + escH( roles[ k ] ) + '</option>'; }
								return '<select data-p="' + p + '" data-s="' + s + '" data-b="' + bi + '" style="max-width:210px">' + o + '</select>';
							}
							function secCtl( cls, p, s, on, label, color ) {
								return '<label style="font-size:12px;white-space:nowrap' + ( color ? ';color:' + color : '' ) + '"><input type="checkbox" class="' + cls + '" data-p="' + p + '" data-s="' + s + '"' + ( on ? ' checked' : '' ) + '> ' + label + '</label>';
							}
							var editor = '';
							if ( mapping && mapping.pages ) {
								editor = '<p style="margin:.7em 0 .3em"><strong><?php echo esc_js( __( 'Review the content mapping', 'fw' ) ); ?></strong> — <?php echo esc_js( __( 'set each element\'s role (or uncheck it), give the section a CSS ID, or drop the whole section to a code-block / omit it. Then build.', 'fw' ) ); ?></p>'
									+ '<div id="fw-sc-map" style="max-height:560px;overflow:auto;border:1px solid #dcdcde;border-radius:6px;background:#fff">';
								mapping.pages.forEach( function ( pg, pi ) {
									( pg.sections || [] ).forEach( function ( sc, si ) {
										editor += '<div class="fw-sc-sec" data-p="' + pi + '" data-s="' + si + '" style="border-top:2px solid #c3c4c7">'
											+ '<div style="display:flex;flex-wrap:wrap;gap:.4em .9em;align-items:center;padding:.45em .7em;background:#eef0f2">'
												+ '<strong><?php echo esc_js( __( 'Section', 'fw' ) ); ?> ' + ( si + 1 ) + '</strong>'
												+ '<span style="color:#888;font-size:12px;flex:1;min-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + escH( sc.sectionClass || '' ) + '</span>'
												+ '<label style="font-size:12px;white-space:nowrap"><?php echo esc_js( __( 'CSS ID', 'fw' ) ); ?> <input type="text" class="fw-sc-cssid" data-p="' + pi + '" data-s="' + si + '" value="' + escH( sc.css_id || '' ) + '" style="width:11em"></label>'
												+ secCtl( 'fw-sc-all', pi, si, true, '<?php echo esc_js( __( 'All', 'fw' ) ); ?>', '' )
												+ secCtl( 'fw-sc-verbatim', pi, si, !! sc.verbatim, '<?php echo esc_js( __( 'Code-block', 'fw' ) ); ?>', '' )
												+ secCtl( 'fw-sc-omit', pi, si, !! sc.omit, '<?php echo esc_js( __( 'Omit', 'fw' ) ); ?>', '#b32d2e' )
											+ '</div>';
										// Spec line — the section's captured look + asset count.
										var c = sc.computed || {}, look = [];
										if ( c.background ) { look.push( 'bg ' + c.background ); }
										if ( c.backgroundImage ) { look.push( 'bg-image' ); }
										if ( c.padding ) { look.push( 'pad ' + c.padding ); }
										if ( c.color ) { look.push( 'text ' + c.color ); }
										if ( c.fontFamily ) { look.push( c.fontFamily.split( ',' )[ 0 ].replace( /["\x27]/g, '' ) ); }
										var an = ( sc.assets || [] ).length; if ( an ) { look.push( an + ' image' + ( an > 1 ? 's' : '' ) ); }
										if ( look.length ) { editor += '<div style="padding:.2em .7em;font-size:11px;color:#777;background:#fafafa;border-top:1px solid #f0f0f1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + escH( look.join( '  ·  ' ) ) + '</div>'; }
										editor += '<div class="fw-sc-rows">';
										( sc.blocks || [] ).forEach( function ( b, bi ) {
											editor += '<div style="display:flex;gap:.6em;align-items:center;padding:.3em .7em;border-top:1px solid #f3f3f4">'
												+ '<input type="checkbox" class="fw-sc-inc" data-p="' + pi + '" data-s="' + si + '" data-b="' + bi + '"' + ( b.include === false ? '' : ' checked' ) + '>'
												+ '<div style="flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + preview( b ) + '</div>'
												+ '<div style="flex:0 0 auto">' + sel( pi, si, bi, b.role || 'code' ) + '</div></div>';
										} );
										editor += '</div></div>';
									} );
								} );
								editor += '</div>'
									+ '<p style="margin:.7em 0 .2em"><label style="font-size:12px;color:#3c434a"><input type="checkbox" id="fw-sc-anim" style="margin:0 .4em 0 0"><?php echo esc_js( __( 'Include source animations (AOS / wow.js / @keyframes) in the page CSS — off keeps the child stylesheet clean.', 'fw' ) ); ?></label></p>'
									+ '<p style="margin:.3em 0 0"><button type="button" class="button button-primary" id="fw-sc-build"><?php echo esc_js( __( 'Build the page from this mapping', 'fw' ) ); ?></button></p>';
							} else {
								editor = '<button type="button" class="button button-primary" id="fw-sc-an-pages"><?php echo esc_js( __( 'Convert the pages now', 'fw' ) ); ?></button>';
							}
							$status.innerHTML = '<div class="notice notice-success" style="margin:.3em 0;padding:.7em .9em">'
								+ '<p style="margin:.2em 0 .6em"><strong><?php echo esc_js( __( 'Design system applied & theme activated.', 'fw' ) ); ?></strong>'
								+ '<?php echo esc_js( __( ' Review the extracted colors / type / buttons:', 'fw' ) ); ?>' + link + '</p>'
								+ mediaNote + editor + '</div>';
							$go.disabled = false;
							var $map = document.getElementById( 'fw-sc-map' );
							if ( $map && mapping ) {
								function dimSec( el, on ) {
									var rows = el.closest( '.fw-sc-sec' ).querySelector( '.fw-sc-rows' );
									if ( rows ) { rows.style.opacity = on ? '.4' : '1'; rows.style.pointerEvents = on ? 'none' : ''; }
								}
								$map.addEventListener( 'change', function ( e ) {
									var t = e.target, p = +t.dataset.p, s = +t.dataset.s;
									if ( isNaN( p ) || isNaN( s ) ) { return; }
									var sec = mapping.pages[ p ].sections[ s ];
									if ( t.classList.contains( 'fw-sc-cssid' ) ) { sec.css_id = t.value; return; }
									if ( t.classList.contains( 'fw-sc-verbatim' ) ) { sec.verbatim = t.checked; dimSec( t, t.checked || !! sec.omit ); return; }
									if ( t.classList.contains( 'fw-sc-omit' ) ) { sec.omit = t.checked; dimSec( t, t.checked || !! sec.verbatim ); return; }
									if ( t.classList.contains( 'fw-sc-all' ) ) {
										var incs = t.closest( '.fw-sc-sec' ).querySelectorAll( '.fw-sc-inc' );
										[].forEach.call( incs, function ( c, i ) { c.checked = t.checked; if ( sec.blocks[ i ] ) { sec.blocks[ i ].include = t.checked; } } );
										return;
									}
									if ( t.classList.contains( 'fw-sc-inc' ) ) { sec.blocks[ +t.dataset.b ].include = t.checked; return; }
									if ( t.tagName === 'SELECT' ) { sec.blocks[ +t.dataset.b ].role = t.value; return; }
								} );
								var $build = document.getElementById( 'fw-sc-build' );
								$build.addEventListener( 'click', function () {
									$build.disabled = true; clearProg(); bar( '<?php echo esc_js( __( 'Building the page…', 'fw' ) ); ?>' ); animateBar( 20, 95, 6000 );
									var animEl = document.getElementById( 'fw-sc-anim' );
									mapping.include_animations = !! ( animEl && animEl.checked );
									var themeEl = document.getElementById( 'fw-sc-opt-theme' );
									var fd = new FormData(); fd.append( 'action', 'fw_sc_build_mapping' ); fd.append( '_wpnonce', nonce ); fd.append( 'mapping', JSON.stringify( mapping ) );
									// "Create child theme" off → grab-content-only build (new page, no section CSS).
									fd.append( 'opt_theme', ( !themeEl || themeEl.checked ) ? '1' : '0' );
									fetch( ajaxurl, { method: 'POST', credentials: 'same-origin', body: fd } ).then( function ( r ) { return r.json(); } )
										.then( function ( r2 ) {
											if ( r2 && r2.success && r2.data && r2.data.redirect ) { clearProg(); setBar( 100 ); window.location.href = r2.data.redirect; return; }
											throw new Error( ( r2 && r2.data && r2.data.message ) || '<?php echo esc_js( __( 'Could not build the page.', 'fw' ) ); ?>' );
										} ).catch( fail );
								} );
							}
							var $pages = document.getElementById( 'fw-sc-an-pages' );
							if ( $pages ) {
								$pages.addEventListener( 'click', function () {
									$pages.disabled = true;
									applyPhase( lastBlob, 'pages', '<?php echo esc_js( __( 'Converting the pages…', 'fw' ) ); ?>' )
										.then( function ( r2 ) {
											if ( r2 && r2.success && r2.data && r2.data.redirect ) { clearProg(); setBar( 100 ); window.location.href = r2.data.redirect; return; }
											throw new Error( ( r2 && r2.data && r2.data.message ) || '<?php echo esc_js( __( 'Could not convert the pages.', 'fw' ) ); ?>' );
										} )
										.catch( fail );
								} );
							}
						} )
						.catch( fail );
				} );
			} )();
			</script>

			</div><!-- /#panel-convert -->

			<div class="fw-sc-panel" id="panel-tools">
			<p class="description" style="margin:0 0 1.2em">
				<?php esc_html_e( 'Piece-by-piece and fallback tools. Each runs independently of the one-shot Convert flow — use them to re-run a single phase, import a bundle by hand, or apply an export your agent produced. Click a card to open it.', 'fw' ); ?>
			</p>

			<details class="fw-sc-card">
				<summary><span class="dashicons dashicons-media-archive"></span> <?php esc_html_e( 'Convert from a bundle (.zip)', 'fw' ); ?></summary>
				<div class="fw-sc-card-body">
			<p class="description">
				<?php esc_html_e( 'The one-shot path. Upload the .zip your agent produced and every phase it contains is applied in order: media (media.json) → styling presets (presets.json) → theme settings (theme-settings.json) → pages (pages.json) → menus (menus.json). Prefer to go piece by piece? Use the individual tools below.', 'fw' ); ?>
			</p>
			<form method="post" action="" enctype="multipart/form-data">
				<?php wp_nonce_field( self::NONCE ); ?>
				<input type="hidden" name="fw_sc_step" value="import_bundle">
				<input type="file" name="fw_sc_bundle" accept=".zip,application/zip">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Import bundle', 'fw' ); ?></button>
			</form>
				</div>
			</details>

			<details class="fw-sc-card">
				<summary><span class="dashicons dashicons-admin-appearance"></span> <?php esc_html_e( 'Generate header &amp; footer theme', 'fw' ); ?></summary>
				<div class="fw-sc-card-body">
			<p class="description">
				<?php esc_html_e( 'Reproduce the source site\'s header and footer DESIGN as a real WordPress theme — logo placement, nav, CTA, fonts, colors, footer layout and any carried CSS. Only stylings are copied: the logo is always your own (Site Logo → Site Title) and the footer brand is your Site Title, never the source\'s wording. The page builder + Unyson+ plugin still power everything else.', 'fw' ); ?>
			</p>
			<form method="post" action="">
				<?php wp_nonce_field( self::NONCE ); ?>
				<input type="hidden" name="fw_sc_step" value="generate_theme">
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Theme type', 'fw' ); ?></th>
						<td>
							<fieldset>
								<label style="display:block;margin-bottom:.5em">
									<input type="radio" name="fw_sc_theme_mode" value="child" checked>
									<strong><?php esc_html_e( 'Unyson+ Theme &amp; a Child Theme', 'fw' ); ?></strong>
									— <?php esc_html_e( 'a lightweight child of unysonplus-theme (just the header/footer overrides). Recommended.', 'fw' ); ?>
								</label>
								<label style="display:block">
									<input type="radio" name="fw_sc_theme_mode" value="standalone">
									<strong><?php esc_html_e( 'Standalone WordPress theme', 'fw' ); ?></strong>
									— <?php esc_html_e( 'a self-contained copy of the theme files (no parent dependency). Still uses the Unyson+ plugin + page builder.', 'fw' ); ?>
								</label>
							</fieldset>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="fw_sc_theme_config"><?php esc_html_e( 'Design config (JSON)', 'fw' ); ?></label></th>
						<td>
							<?php $sc_last_design = (string) get_option( 'fw_sc_last_theme_design', '' ); ?>
								<?php if ( $sc_last_design !== '' ) : ?>
								<p class="description" style="margin:0 0 .4em">✓ <strong><?php esc_html_e( 'Pre-filled from your last applied bundle.', 'fw' ); ?></strong> <?php esc_html_e( 'Click “Install into themes” to re-install / refresh the generated theme — no pasting, no re-uploading the zip.', 'fw' ); ?></p>
								<?php else : ?>
								<p class="description" style="margin:0 0 .4em"><?php esc_html_e( 'Leave empty — applying a converted bundle (above) installs the theme automatically. This box pre-fills here after your first bundle apply, for one-click re-installs.', 'fw' ); ?></p>
								<?php endif; ?>
								<?php
								$sc_cfg_val = '';
								if ( $sc_last_design !== '' ) {
									$sc_p       = json_decode( $sc_last_design, true );
									$sc_cfg_val = is_array( $sc_p ) ? wp_json_encode( $sc_p, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) : $sc_last_design;
								}
								echo $this->json_editor_field( 'fw_sc_theme_config', self::theme_config_placeholder(), (string) $sc_cfg_val, 10, 'fw_sc_theme_config' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
								?>
							<p class="description"><?php esc_html_e( 'The chrome half of a design capture. Keys: theme (name, slug), fonts (heading, body, google url), colors (ink, accent, bg, footer_bg…), header (style: pill|bar|minimal, menu_location, cta), footer, background (dotted), custom_css. Anything omitted uses a sensible default.', 'fw' ); ?></p>
						<p class="description"><strong><?php esc_html_e( 'Capture → generate:', 'fw' ); ?></strong> <?php esc_html_e( 'you can paste the raw design-capture.json the capture tool (tools/design-capture) produced straight in here — it is auto-detected and mapped to the config above (stylings only; the logo stays your Site Logo / Site Title).', 'fw' ); ?></p>
						</td>
					</tr>
				</table>
				<p class="submit" style="display:flex;gap:.6em;align-items:center;flex-wrap:wrap">
					<button type="submit" class="button button-primary" name="fw_sc_theme_action" value="install"><?php esc_html_e( 'Install into themes', 'fw' ); ?></button>
					<button type="submit" class="button" name="fw_sc_theme_action" value="download"><?php esc_html_e( 'Download .zip', 'fw' ); ?></button>
					<span class="description"><?php esc_html_e( 'Install writes it straight to wp-content/themes; download gives you a .zip for Appearance → Themes → Add New → Upload.', 'fw' ); ?></span>
				</p>
			</form>
				</div>
			</details>

			<details class="fw-sc-card"<?php echo $stage === 'preview' ? ' open' : ''; ?>>
				<summary><span class="dashicons dashicons-format-image"></span> <?php echo $stage === 'preview' ? esc_html__( 'Scan another source', 'fw' ) : esc_html__( 'Find images', 'fw' ); ?></summary>
				<div class="fw-sc-card-body">
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
			</details>

			<details class="fw-sc-card">
				<summary><span class="dashicons dashicons-art"></span> <?php esc_html_e( 'Import Styling Presets', 'fw' ); ?></summary>
				<div class="fw-sc-card-body">
			<p class="description">
				<?php esc_html_e( 'Paste the presets JSON your agent produced (the presets.json / _fw_presets_export payload from the conversion contract). It writes the palette, font sizes, button colors, and spacing / gap scales into your site\'s Styling Presets in one step. Only known preset keys are applied — anything else is skipped.', 'fw' ); ?>
			</p>
			<form method="post" action="">
				<?php wp_nonce_field( self::NONCE ); ?>
				<input type="hidden" name="fw_sc_step" value="import_presets">
				<?php echo $this->json_editor_field( 'fw_sc_presets_json', '{ "values": { "theme_colors": [ ... ], "font_sizes": [ ... ] } }' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<p class="description"><?php echo esc_html( sprintf( __( 'Importable keys: %s.', 'fw' ), implode( ', ', FW_Site_Converter_Presets::allowed_keys() ) ) ); ?></p>
				<p class="submit">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Import presets', 'fw' ); ?></button>
				</p>
			</form>
				</div>
			</details>

			<details class="fw-sc-card">
				<summary><span class="dashicons dashicons-admin-settings"></span> <?php esc_html_e( 'Import Theme Settings', 'fw' ); ?></summary>
				<div class="fw-sc-card-body">
			<p class="description">
				<?php esc_html_e( 'Paste the theme-settings design file your agent produced (the _fw_settings_export / theme-settings .json). It applies global chrome, typography defaults, header / footer slot config and any bespoke CSS (misc_custom_css) to your Theme Settings — overlaying only the keys the file carries. Operational keys (analytics, custom scripts, maintenance) are never imported, and source-site media references are dropped (the media tool re-attaches them).', 'fw' ); ?>
			</p>
			<form method="post" action="">
				<?php wp_nonce_field( self::NONCE ); ?>
				<input type="hidden" name="fw_sc_step" value="import_theme_settings">
				<?php echo $this->json_editor_field( 'fw_sc_theme_json', '{ "_fw_settings_export": { "theme_id": "unysonplus" }, "values": { "misc_custom_css": "…", "header_layout": "…" } }' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<p class="description"><?php esc_html_e( 'Applies to fw_theme_settings_options. The theme also has its own Appearance → Theme Settings → Miscellaneous → Import for the same file.', 'fw' ); ?></p>
				<p class="submit">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Apply theme settings', 'fw' ); ?></button>
				</p>
			</form>
				</div>
			</details>

			<details class="fw-sc-card">
				<summary><span class="dashicons dashicons-media-document"></span> <?php esc_html_e( 'Import Pages', 'fw' ); ?></summary>
				<div class="fw-sc-card-body">
			<p class="description">
				<?php esc_html_e( 'Paste the page bodies as JSON — each is created as a WordPress page from its page-builder tree. The plugin generates the content with its own encoder (nothing is hand-coded), so each page stays fully editable in the builder. Pages are matched by slug, so re-running updates rather than duplicates. Tip: start with a single page to confirm it renders and opens in the builder before importing many.', 'fw' ); ?>
			</p>
			<form method="post" action="">
				<?php wp_nonce_field( self::NONCE ); ?>
				<input type="hidden" name="fw_sc_step" value="import_pages">
				<?php echo $this->json_editor_field( 'fw_sc_pages_json', '{ "pages": [ { "title": "Home", "slug": "home", "front_page": true, "builder": [ { "type": "section", "atts": {}, "_items": [] } ] } ] }', '', 12 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<p class="description"><?php esc_html_e( 'Each page: title, optional slug / status / front_page, and a "builder" tree (array of sections) or a stringified "json".', 'fw' ); ?></p>
				<p class="submit">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Import pages', 'fw' ); ?></button>
				</p>
			</form>
				</div>
			</details>

			<details class="fw-sc-card"<?php echo $stage === 'menus_scanned' ? ' open' : ''; ?>>
				<summary><span class="dashicons dashicons-menu"></span> <?php esc_html_e( 'Import Menus', 'fw' ); ?></summary>
				<div class="fw-sc-card-body">
			<p class="description">
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
				<?php echo $this->json_editor_field( 'fw_sc_menus_json', '{ "menus": [ { "name": "Primary", "location": "primary", "items": [ { "label": "Home", "url": "/" }, { "label": "About", "url": "/about" }, { "label": "Services", "url": "#", "children": [ { "label": "Design", "url": "/services/design" } ] } ] }, { "name": "Footer", "location": "footer", "items": [ { "label": "Privacy", "url": "/privacy" } ] } ] }', $menus_prefill, 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
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
			</details>

			</div><!-- /#panel-tools -->

			<div class="fw-sc-panel" id="panel-diagnostics">

			<details class="fw-sc-card" open>
				<summary><span class="dashicons dashicons-cloud"></span> <?php esc_html_e( 'Capture service', 'fw' ); ?></summary>
				<div class="fw-sc-card-body">
					<p class="description"><?php esc_html_e( 'Check whether the local capture service is reachable from this browser. The service is what renders JavaScript sites in real Chrome on your machine — see the Convert tab for setup.', 'fw' ); ?></p>
					<p>
						<label><strong><?php esc_html_e( 'Service URL', 'fw' ); ?></strong>
							<input type="url" id="fw-sc-diag-url" class="regular-text" value="http://localhost:8787" style="width:18em">
						</label>
						<button type="button" class="button" id="fw-sc-diag-check"><?php esc_html_e( 'Check now', 'fw' ); ?></button>
						<span id="fw-sc-diag-result" class="description" style="margin-left:.5em"></span>
					</p>
				</div>
			</details>

			<details class="fw-sc-card"<?php echo $stage === 'theme_reset' ? ' open' : ''; ?>>
				<summary><span class="dashicons dashicons-sos"></span> <?php esc_html_e( 'Theme Settings Doctor', 'fw' ); ?></summary>
				<div class="fw-sc-card-body">
					<?php $this->render_theme_settings_doctor( $stage, $data ); ?>
				</div>
			</details>

			</div><!-- /#panel-diagnostics -->

			<style>
			.fw-ext-site-converter .fw-sc-tabs{margin-top:.4em;border-bottom:1px solid #c3c4c7}
			.fw-ext-site-converter .fw-sc-tabs .nav-tab{border-radius:.25rem .25rem 0 0}
			.fw-ext-site-converter .fw-sc-panel{display:none}
			.fw-ext-site-converter .fw-sc-panel.is-active{display:block}
			/* Setup box (Convert tab) */
			.fw-ext-site-converter .fw-sc-setup{border:1px solid #c3dcf0;border-radius:6px;background:#f3f9ff;margin:0 0 1.6em;max-width:62em}
			.fw-ext-site-converter .fw-sc-setup>summary,.fw-ext-site-converter .fw-sc-card>summary{cursor:pointer;font-weight:600;font-size:14px;line-height:1.4;padding:.85em 1em;list-style:none;display:flex;align-items:center;gap:.5em}
			.fw-ext-site-converter .fw-sc-setup>summary::-webkit-details-marker,.fw-ext-site-converter .fw-sc-card>summary::-webkit-details-marker{display:none}
			.fw-ext-site-converter .fw-sc-setup>summary::after,.fw-ext-site-converter .fw-sc-card>summary::after{content:"\f140";font-family:dashicons;margin-left:auto;color:#787c82;transition:transform .15s ease}
			.fw-ext-site-converter .fw-sc-setup[open]>summary::after,.fw-ext-site-converter .fw-sc-card[open]>summary::after{transform:rotate(180deg)}
			.fw-ext-site-converter .fw-sc-setup>summary:hover,.fw-ext-site-converter .fw-sc-card>summary:hover{color:#2271b1}
			.fw-ext-site-converter .fw-sc-setup-body{padding:.2em 1.2em 1.1em}
			.fw-ext-site-converter .fw-sc-why{background:#fff;border:1px solid #e0e0e0;border-left:4px solid #2271b1;border-radius:4px;padding:.7em 1em;margin:.2em 0 1em;max-width:56em}
			/* Manual-tool / diagnostics cards */
			.fw-ext-site-converter .fw-sc-card{border:1px solid #dcdcde;border-radius:6px;background:#fff;margin:0 0 .8em;box-shadow:0 1px 1px rgba(0,0,0,.04)}
			.fw-ext-site-converter .fw-sc-card[open]>summary{border-bottom:1px solid #f0f0f1}
			.fw-ext-site-converter .fw-sc-card-body{padding:.6em 1.2em 1.1em}
			.fw-ext-site-converter .fw-sc-card-body>p:first-child{margin-top:.4em}
			/* Per-editor file-import row */
			.fw-ext-site-converter .fw-sc-filerow{display:flex;align-items:center;gap:.6em;margin:.2em 0 .45em;flex-wrap:wrap}
			.fw-ext-site-converter .fw-sc-file-btn{display:inline-flex;align-items:center;gap:.3em;cursor:pointer}
			.fw-ext-site-converter .fw-sc-file-btn .dashicons{font-size:16px;width:16px;height:16px}
			.fw-ext-site-converter .fw-sc-fname{font-style:italic;color:#1a7f37}
			.fw-ext-site-converter .fw-sc-editor .CodeMirror{border:1px solid #8c8f94;border-radius:4px;height:auto;min-height:140px}
			</style>
			<script>
			( function () {
				var wrap = document.querySelector( '.fw-ext-site-converter' );
				if ( ! wrap ) { return; }
				function each( list, fn ) { Array.prototype.forEach.call( list, fn ); }

				/* --- Top-level tabs (mirrors the Component Presets page) --- */
				function refreshCM( scope ) {
					if ( ! scope ) { return; }
					each( scope.querySelectorAll( '.CodeMirror' ), function ( el ) { if ( el.CodeMirror ) { el.CodeMirror.refresh(); } } );
				}
				function activate( tab ) {
					each( wrap.querySelectorAll( '.fw-sc-tabs .nav-tab' ), function ( a ) {
						a.classList.toggle( 'nav-tab-active', a.getAttribute( 'data-tab' ) === tab );
					} );
					each( wrap.querySelectorAll( '.fw-sc-panel' ), function ( p ) {
						p.classList.toggle( 'is-active', p.id === 'panel-' + tab );
					} );
					refreshCM( wrap.querySelector( '#panel-' + tab ) );
				}
				each( wrap.querySelectorAll( '.fw-sc-tabs .nav-tab' ), function ( a ) {
					a.addEventListener( 'click', function ( e ) {
						e.preventDefault();
						var tab = a.getAttribute( 'data-tab' );
						activate( tab );
						if ( window.history && window.history.replaceState ) { window.history.replaceState( null, '', '#' + tab ); }
					} );
				} );
				var hash = ( window.location.hash || '' ).replace( /^#/, '' );
				if ( hash && wrap.querySelector( '#panel-' + hash ) ) { activate( hash ); }

				/* --- JSON editors: CodeMirror + "Import from file…" --- */
				each( wrap.querySelectorAll( '.fw-sc-json' ), function ( ta ) {
					if ( ta.dataset.fwInit ) { return; }
					ta.dataset.fwInit = '1';
					if ( window.wp && wp.codeEditor && wp.codeEditor.initialize ) {
						try {
							var ed = wp.codeEditor.initialize( ta, ( wp.codeEditor.defaultSettings || {} ) );
							ta._fwcm = ed && ed.codemirror ? ed.codemirror : null;
						} catch ( err ) { ta._fwcm = null; }
					}
				} );
				// Refresh an editor when its card opens (CodeMirror measures 0 while hidden).
				each( wrap.querySelectorAll( 'details.fw-sc-card' ), function ( d ) {
					d.addEventListener( 'toggle', function () { if ( d.open ) { refreshCM( d ); } } );
				} );

				wrap.addEventListener( 'change', function ( e ) {
					var input = e.target;
					if ( ! input.classList || ! input.classList.contains( 'fw-sc-file' ) ) { return; }
					var ed = input.closest( '.fw-sc-editor' );
					if ( ! ed ) { return; }
					var ta = ed.querySelector( '.fw-sc-json' ), fname = ed.querySelector( '.fw-sc-fname' );
					var f = input.files && input.files[ 0 ];
					if ( ! f || ! ta ) { return; }
					var rd = new FileReader();
					rd.onload = function () {
						var txt = String( rd.result || '' );
						if ( ta._fwcm ) { ta._fwcm.setValue( txt ); ta._fwcm.refresh(); } else { ta.value = txt; }
						if ( fname ) { fname.textContent = f.name; }
					};
					rd.readAsText( f );
				} );

				/* --- Diagnostics: capture-service health check --- */
				var du = document.getElementById( 'fw-sc-diag-url' ),
					dc = document.getElementById( 'fw-sc-diag-check' ),
					dr = document.getElementById( 'fw-sc-diag-result' );
				var LS = 'fw_sc_capture_service';
				if ( du && window.localStorage && localStorage.getItem( LS ) ) { du.value = localStorage.getItem( LS ); }
				function diagCheck() {
					if ( ! du ) { return; }
					var url = ( du.value || 'http://localhost:8787' ).replace( /\/+$/, '' );
					if ( window.localStorage ) { localStorage.setItem( LS, url ); }
					dr.innerHTML = '<?php echo esc_js( __( 'checking…', 'fw' ) ); ?>';
					fetch( url + '/health', { mode: 'cors' } ).then( function ( r ) { return r.json(); } )
						.then( function ( d ) { dr.innerHTML = d && d.ok ? '<span style="color:#1a7f37">&#10003; <?php echo esc_js( __( 'service detected and ready', 'fw' ) ); ?></span>' : '<span style="color:#b32d2e"><?php echo esc_js( __( 'reachable, but not reporting ready', 'fw' ) ); ?></span>'; } )
						.catch( function () { dr.innerHTML = '<span style="color:#b32d2e"><?php echo esc_js( __( 'not detected — start it with node serve.mjs (see the Convert tab)', 'fw' ) ); ?></span>'; } );
				}
				if ( dc ) { dc.addEventListener( 'click', diagCheck ); }
			} )();
			</script>
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
			$titles = array();
			foreach ( $ok as $p ) {
				$titles[] = sprintf(
					'%1$s (%2$s)',
					isset( $p['title'] ) && $p['title'] !== '' ? $p['title'] : __( 'Untitled', 'fw' ),
					! empty( $p['created'] ) ? __( 'created', 'fw' ) : __( 'updated', 'fw' )
				);
			}
			echo '<div class="notice notice-success is-dismissible"><p><strong>'
				. esc_html( sprintf( __( 'Imported %1$d page(s): %2$d created, %3$d updated.', 'fw' ), count( $ok ), $created, $updated ) )
				. '</strong> ' . esc_html( implode( ', ', $titles ) ) . '</p></div>';
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
			$line    = sprintf(
				/* translators: 1: imported, 2: reused, 3: failed */
				__( 'Media — %1$d imported, %2$d reused, %3$d failed.', 'fw' ),
				(int) $m['imported'], (int) $m['reused'], (int) $m['failed']
			);
			$names = isset( $m['names'] ) && is_array( $m['names'] ) ? $m['names'] : array();
			if ( $names ) {
				$shown = array_slice( $names, 0, 12 );
				$more  = count( $names ) - count( $shown );
				$line .= ' ' . sprintf(
					/* translators: %s: comma-separated filenames */
					__( 'Added: %s', 'fw' ),
					implode( ', ', $shown ) . ( $more > 0 ? sprintf( __( ' +%d more', 'fw' ), $more ) : '' )
				);
			}
			$lines[] = $line;
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

		if ( isset( $data['theme'] ) && is_array( $data['theme'] ) ) {
			$th = $data['theme'];
			if ( ! empty( $th['error'] ) ) {
				$lines[] = sprintf( __( 'Theme — not generated: %s', 'fw' ), $th['error'] );
			} else {
				$lines[] = sprintf(
					/* translators: 1: theme name, 2: child/standalone, 3: created/updated */
					__( 'Theme — %1$s (%2$s) %3$s. Activate it under Appearance → Themes.', 'fw' ),
					isset( $th['name'] ) ? $th['name'] : ( isset( $th['slug'] ) ? $th['slug'] : '' ),
					( isset( $th['mode'] ) && $th['mode'] === 'standalone' ) ? __( 'standalone', 'fw' ) : __( 'child', 'fw' ),
					! empty( $th['exists'] ) ? __( 'updated', 'fw' ) : __( 'created', 'fw' )
				);
			}
		}

		if ( isset( $data['styleguide']['pages'] ) && is_array( $data['styleguide']['pages'] ) ) {
			$sgp = array_filter( $data['styleguide']['pages'], function ( $p ) {
				return empty( $p['error'] ) && ! empty( $p['id'] );
			} );
			if ( $sgp ) {
				$sg = reset( $sgp );
				$lines[] = sprintf(
					/* translators: 1: created/updated */
					__( 'Style guide — page %s. Review the captured design system there.', 'fw' ),
					! empty( $sg['created'] ) ? __( 'created', 'fw' ) : __( 'updated', 'fw' )
				);
			}
		}

		if ( ! empty( $data['child_css'] ) ) {
			$lines[] = __( 'Child theme CSS — mapped per-section styles merged into the child theme style.css (one clean stylesheet).', 'fw' );
		}

		if ( isset( $data['pages']['pages'] ) && is_array( $data['pages']['pages'] ) ) {
			$pp = array_filter( $data['pages']['pages'], function ( $p ) {
				return empty( $p['error'] ) && ! empty( $p['id'] );
			} );
			$created = count( array_filter( $pp, function ( $p ) { return ! empty( $p['created'] ); } ) );
			if ( $pp ) {
				$titles = array();
				foreach ( $pp as $p ) {
					$titles[] = sprintf(
						'%1$s (%2$s)',
						isset( $p['title'] ) && $p['title'] !== '' ? $p['title'] : __( 'Untitled', 'fw' ),
						! empty( $p['created'] ) ? __( 'created', 'fw' ) : __( 'updated', 'fw' )
					);
				}
				$lines[] = sprintf(
					/* translators: 1: total, 2: created, 3: updated, 4: page titles */
					__( 'Pages — %1$d (%2$d created, %3$d updated): %4$s.', 'fw' ),
					count( $pp ), $created, count( $pp ) - $created, implode( ', ', $titles )
				);
			} else {
				$lines[] = __( 'Pages — none created.', 'fw' );
			}
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
	 * Success panel after a theme is generated — where it landed + how to activate.
	 *
	 * @param array $data
	 */
	private function render_theme_generated( array $data ) {
		$name = isset( $data['name'] ) ? $data['name'] : ( isset( $data['slug'] ) ? $data['slug'] : '' );
		$slug = isset( $data['slug'] ) ? $data['slug'] : '';
		$mode = isset( $data['mode'] ) && $data['mode'] === 'standalone' ? __( 'standalone theme', 'fw' ) : __( 'child theme', 'fw' );
		$verb = ! empty( $data['exists'] ) ? __( 'updated', 'fw' ) : __( 'created', 'fw' );

		echo '<div class="notice notice-success is-dismissible"><p><strong>'
			. esc_html( sprintf(
				/* translators: 1: theme name, 2: child/standalone, 3: created/updated, 4: file count */
				__( '%1$s (%2$s) %3$s — %4$d file(s) written.', 'fw' ),
				$name, $mode, $verb, isset( $data['files'] ) ? (int) $data['files'] : 0
			) ) . '</strong> '
			. ' <code style="font-size:11px">' . esc_html( isset( $data['dir'] ) ? $data['dir'] : '' ) . '</code></p>';

		$themes_url = admin_url( 'themes.php' );
		echo '<p>' . esc_html__( 'Next:', 'fw' ) . ' '
			. '<a href="' . esc_url( $themes_url ) . '">' . esc_html__( 'Appearance → Themes', 'fw' ) . '</a> '
			. esc_html__( 'to activate it. After switching themes, re-import your menus (WordPress resets menu-location assignments on theme change), then check the front end.', 'fw' )
			. '</p></div>';
	}

	/**
	 * A minimal-but-complete example design config for the generator textarea.
	 *
	 * @return string
	 */
	private static function theme_config_placeholder() {
		return wp_json_encode( array(
			'theme'  => array( 'name' => 'My Site', 'slug' => 'my-site' ),
			'fonts'  => array( 'heading' => 'Fraunces', 'body' => 'Manrope', 'google' => 'https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400..700&family=Manrope:wght@400..800&display=swap' ),
			'colors' => array( 'ink' => '#34251f', 'accent' => '#994920', 'bg' => '#fbf9f0', 'footer_bg' => '#34251f', 'footer_text' => '#fbf9f0' ),
			'header' => array( 'style' => 'pill', 'menu_location' => 'primary', 'cta' => array( 'label' => 'Get started', 'href' => '/#get-started', 'dedupe_from_menu' => true ) ),
			'footer' => array( 'widget_area' => true, 'copyright' => 'All rights reserved.' ),
			'background' => array( 'dotted' => true, 'dot_color' => '#e7e1d4' ),
		), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
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
