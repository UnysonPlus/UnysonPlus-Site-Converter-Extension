<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Site Converter — Bundle importer (orchestrator).
 *
 * Ingests one `.zip` an agent produces — a "convert bundle" — and applies every
 * phase we have an engine for, in order, so a whole site converts in one shot
 * instead of running each tool by hand. Reuses the media / presets / menu
 * engines (it owns no import logic of its own).
 *
 * Bundle layout (every file optional — only what's present is applied):
 *
 *   bundle.zip
 *   ├── bundle.json         (optional metadata: { name, source, generated })
 *   ├── media.json          ({ "urls": [ "https://…/hero.jpg", … ] })  → media engine
 *   ├── presets.json        ({ "values": { theme_colors:[…], … } })    → presets engine
 *   ├── theme-settings.json ({ "values": { id: value } })              → theme-settings engine
 *   ├── design-config.json  (the chrome design config / a raw capture) → theme generator
 *   ├── pages.json          ({ "pages": [ … ] })                       → pages engine
 *   └── menus.json          ({ "menus": [ { name, location, items }, … ] }) → menu engine
 *
 * Phases run in contract order: media → presets → theme settings → theme (generate the
 * child/standalone theme from the design config) → pages → menus. With a design-config
 * the bundle becomes a true one-shot: upload one .zip and the theme + Home page are
 * built; the user just activates the generated theme.
 *
 * Static so a WP-CLI command can reuse it (mirrors the other engines).
 */
class FW_Site_Converter_Bundle {

	/** Filenames we read from the bundle (first match wins). */
	const FILE_MANIFEST = array( 'bundle.json', 'manifest.json' );
	const FILE_MEDIA          = array( 'media.json' );
	const FILE_PRESETS        = array( 'presets.json' );
	const FILE_THEME_SETTINGS = array( 'theme-settings.json', 'theme_settings.json' );
	const FILE_THEME_DESIGN   = array( 'theme-design.json', 'design-config.json' );
	const FILE_STYLEGUIDE     = array( 'styleguide.json' );
	const FILE_MAPPING        = array( 'mapping.json' );
	const FILE_PAGES          = array( 'pages.json' );
	const FILE_MENUS          = array( 'menus.json' );

	/** Sections we recognize but can't apply yet (reported as deferred). */
	const DEFERRED = array();

	/**
	 * Unzip a bundle to a temp dir, apply it, then clean up.
	 *
	 * @param string $zip_path Path to the uploaded .zip (e.g. $_FILES tmp_name).
	 * @return array Combined result (see import_dir), with `error` set on unzip failure.
	 */
	public static function import_zip( $zip_path, $phase = '', $opts = array() ) {
		$out = self::blank_result();

		if ( ! is_string( $zip_path ) || ! is_file( $zip_path ) ) {
			$out['error'] = __( 'No bundle file to read.', 'fw' );
			return $out;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		if ( ! WP_Filesystem() ) {
			$out['error'] = __( 'Could not access the filesystem to unzip the bundle.', 'fw' );
			return $out;
		}

		$tmp = trailingslashit( get_temp_dir() ) . 'fw-sc-bundle-' . wp_generate_password( 12, false );
		if ( ! wp_mkdir_p( $tmp ) ) {
			$out['error'] = __( 'Could not create a temp folder to unzip the bundle.', 'fw' );
			return $out;
		}

		$unzip = unzip_file( $zip_path, $tmp );
		if ( is_wp_error( $unzip ) ) {
			global $wp_filesystem;
			$wp_filesystem->delete( $tmp, true );
			$out['error'] = sprintf( __( 'Could not unzip the bundle: %s', 'fw' ), $unzip->get_error_message() );
			return $out;
		}

		$out = self::import_dir( $tmp, $phase, $opts );

		global $wp_filesystem;
		$wp_filesystem->delete( $tmp, true );

		return $out;
	}

	/**
	 * Apply an already-unzipped bundle directory.
	 *
	 * @param string $dir
	 * @return array{
	 *   manifest: ?array, media: ?array, presets: ?array, menus: ?array,
	 *   sections: string[], deferred: string[], error: string
	 * }
	 */
	public static function import_dir( $dir, $phase = '', $opts = array() ) {
		$out = self::blank_result();

		if ( ! is_string( $dir ) || ! is_dir( $dir ) ) {
			$out['error'] = __( 'Bundle folder not found.', 'fw' );
			return $out;
		}

		$dir = self::locate_root( $dir );

		// Phase gating for the review-first flow: 'design' applies the design system
		// (media, presets, theme settings, theme + the Style Guide page) and defers the
		// content pages; 'pages' applies only pages + menus. '' (default) = everything.
		$do_design = ( $phase === '' || $phase === 'design' );
		$do_pages  = ( $phase === '' || $phase === 'pages' );

		// Per-convert options (apply-time toggles): each defaults ON, so omitting them = full
		// convert (back-compat). `theme` = generate + activate the child theme; `media` = import
		// images; `header`/`footer` = mirror that part of the source chrome into the theme.
		$opt = function ( $k ) use ( $opts ) { return ! isset( $opts[ $k ] ) || ! empty( $opts[ $k ] ); };
		$do_media  = $opt( 'media' );
		$do_theme  = $opt( 'theme' );
		$do_header = $opt( 'header' );
		$do_footer = $opt( 'footer' );

		$out['manifest'] = self::read_json( $dir, self::FILE_MANIFEST );

		// --- Phase 1: media (URL list → sideload, de-duped) ---
		$media = self::read_json( $dir, self::FILE_MEDIA );
		if ( $do_design && $do_media && $media !== null && class_exists( 'FW_Site_Converter_Media' ) ) {
			$urls = isset( $media['urls'] ) && is_array( $media['urls'] )
				? $media['urls']
				: ( self::is_list( $media ) ? $media : array() );
			$urls    = array_slice( array_values( array_filter( array_map( 'trim', array_map( 'strval', (array) $urls ) ) ) ), 0, 500 );
			$results = $urls ? FW_Site_Converter_Media::import_urls( $urls ) : array();
			$out['media']      = self::summarize_media( $results );
			$out['sections'][] = 'media';
		}

		// --- Phase 2: styling presets ---
		$presets = self::read_json( $dir, self::FILE_PRESETS );
		if ( $do_design && $presets !== null && class_exists( 'FW_Site_Converter_Presets' ) ) {
			$out['presets']    = FW_Site_Converter_Presets::import( $presets );
			$out['sections'][] = 'presets';
		}

		// --- Phase 3: theme settings (design file → fw_theme_settings_options) ---
		$theme = self::read_json( $dir, self::FILE_THEME_SETTINGS );
		if ( $do_design && $theme !== null && class_exists( 'FW_Site_Converter_Theme_Settings' ) ) {
			$out['theme_settings'] = FW_Site_Converter_Theme_Settings::import( $theme );
			$out['sections'][]     = 'theme-settings';
		}

		// --- Phase 3b: theme generation (design config → child/standalone theme) ---
		// Skipped when "create child theme" is off — the converted sections are then imported as
		// page content into the ACTIVE theme (dev wants to grab structure into an existing site).
		$theme_design = self::read_json( $dir, self::FILE_THEME_DESIGN );
		if ( $do_design && $do_theme && $theme_design !== null && class_exists( 'FW_Site_Converter_Theme_Generator' ) ) {
			// Honor the header/footer toggles: drop the mirrored chrome the user didn't want, so the
			// generated theme reproduces only the requested parts (the rest fall back to the theme's).
			if ( ! $do_header || ! $do_footer ) {
				foreach ( array( 'chrome', 'raw_chrome' ) as $ck ) {
					if ( isset( $theme_design[ $ck ] ) && is_array( $theme_design[ $ck ] ) ) {
						if ( ! $do_header ) { $theme_design[ $ck ]['header_html'] = ''; $theme_design[ $ck ]['header_css'] = ''; }
						if ( ! $do_footer ) { $theme_design[ $ck ]['footer_html'] = ''; $theme_design[ $ck ]['footer_css'] = ''; }
					}
				}
			}
			$res               = FW_Site_Converter_Theme_Generator::install( $theme_design );
			$out['theme']      = $res;
			$out['sections'][] = 'theme';
			// Remember this design-config so the standalone "Install into themes" panel can
			// pre-fill + one-click re-install the same theme later (no re-uploading the zip).
			update_option( 'fw_sc_last_theme_design', wp_json_encode( $theme_design ), false );
			// Activate the freshly generated theme so the converted header/footer goes live
			// in one step (the whole point of a convert). Only on a clean install (no error),
			// and only if it isn't already the active theme.
			if ( empty( $res['error'] ) && ! empty( $res['slug'] ) && function_exists( 'switch_theme' ) ) {
				if ( get_stylesheet() !== $res['slug'] && wp_get_theme( $res['slug'] )->exists() ) {
					switch_theme( $res['slug'] );
					$out['theme']['activated'] = true;
				}
			}
		}

		// --- Phase 3c: Style Guide page (review artifact from the captured design tokens) ---
		$styleguide = self::read_json( $dir, self::FILE_STYLEGUIDE );
		if ( $do_design && $styleguide !== null && class_exists( 'FW_Site_Converter_Pages' ) ) {
			$out['styleguide'] = FW_Site_Converter_Pages::import( $styleguide );
			$out['sections'][] = 'styleguide';
			$first = isset( $out['styleguide']['pages'][0] ) ? $out['styleguide']['pages'][0] : null;
			if ( $first && ! empty( $first['id'] ) ) {
				$out['styleguide_url'] = get_permalink( (int) $first['id'] );
			}
		}

		// Mapping document — returned (with suggested roles) during the design phase so the admin
		// can show the review editor; the pages are then built from the user's corrected roles
		// (NOT from pages.json) via FW_Site_Converter_Mapper.
		if ( $do_design ) {
			$mapping = self::read_json( $dir, self::FILE_MAPPING );
			if ( $mapping !== null && class_exists( 'FW_Site_Converter_Mapper' ) ) {
				$out['mapping'] = FW_Site_Converter_Mapper::suggest_mapping( $mapping );
			}
		}

		// --- Phase 4: pages (page-builder trees → WordPress pages) ---
		// Skipped when the review editor is driving the build (it posts a corrected mapping to
		// the dedicated build action); kept as the no-mapping fallback.
		$pages = self::read_json( $dir, self::FILE_PAGES );
		if ( $do_pages && $pages !== null && class_exists( 'FW_Site_Converter_Pages' ) ) {
			$out['pages']      = FW_Site_Converter_Pages::import( $pages );
			$out['sections'][] = 'pages';
		}

		// --- Any sections recognized but not yet applied ---
		foreach ( self::DEFERRED as $name => $file ) {
			$present = is_file( trailingslashit( $dir ) . $file )
				|| ( $name === 'pages' && is_dir( trailingslashit( $dir ) . 'pages' ) );
			if ( $present ) {
				$out['deferred'][] = $name;
			}
		}

		// --- Phase 5: menus ---
		$menus = self::read_json( $dir, self::FILE_MENUS );
		if ( $do_pages && $menus !== null && class_exists( 'FW_Site_Converter_Menus' ) ) {
			$out['menus']      = FW_Site_Converter_Menus::import( $menus );
			$out['sections'][] = 'menus';
		}

		if ( ! $out['sections'] ) {
			$out['error'] = __( 'The bundle had no recognized sections (media.json, presets.json, theme-settings.json, pages.json, menus.json).', 'fw' );
		}

		return $out;
	}

	/* ---------------------------------------------------------------------- *
	 * Internals
	 * ---------------------------------------------------------------------- */

	private static function blank_result() {
		return array(
			'manifest'       => null,
			'media'          => null,
			'presets'        => null,
			'theme_settings' => null,
			'theme'          => null,
			'pages'          => null,
			'menus'          => null,
			'sections'       => array(),
			'deferred'       => array(),
			'error'          => '',
		);
	}

	/**
	 * Roll up media import rows into counts (+ the filenames newly added).
	 *
	 * @param array $results Rows from FW_Site_Converter_Media::import_urls().
	 * @return array{imported: int, reused: int, failed: int, total: int, names: string[]}
	 */
	private static function summarize_media( array $results ) {
		$imported = 0;
		$reused   = 0;
		$failed   = 0;
		$names    = array();
		$errors   = array();
		foreach ( $results as $r ) {
			if ( ! empty( $r['ok'] ) ) {
				if ( ! empty( $r['reused'] ) ) {
					$reused++;
				} else {
					$imported++;
					$names[] = self::media_name( $r );
				}
			} else {
				$failed++;
				// Surface why an image didn't import (e.g. a rejected file type) so it's visible
				// in the convert result instead of silently hotlinking — capped to keep it small.
				if ( count( $errors ) < 20 ) {
					$src = isset( $r['source'] ) ? (string) $r['source'] : '';
					$msg = isset( $r['message'] ) ? (string) $r['message'] : '';
					$errors[] = trim( $src . ( $msg !== '' ? ' — ' . $msg : '' ) );
				}
			}
		}
		return array(
			'imported' => $imported,
			'reused'   => $reused,
			'failed'   => $failed,
			'total'    => count( $results ),
			'names'    => array_values( array_filter( $names ) ),
			'errors'   => array_values( array_filter( $errors ) ),
		);
	}

	/** The stored filename of an imported attachment row (from its URL, else its source). */
	private static function media_name( array $r ) {
		$src = ! empty( $r['url'] ) ? $r['url'] : ( isset( $r['source'] ) ? $r['source'] : '' );
		$path = (string) wp_parse_url( (string) $src, PHP_URL_PATH );
		return $path !== '' ? wp_basename( $path ) : '';
	}

	/**
	 * Read + JSON-decode the first existing file among $names in $dir.
	 *
	 * @param string $dir
	 * @param array  $names
	 * @return array|null Decoded array, or null if none/invalid.
	 */
	private static function read_json( $dir, array $names ) {
		foreach ( $names as $n ) {
			$path = trailingslashit( $dir ) . $n;
			if ( is_file( $path ) ) {
				$raw  = (string) file_get_contents( $path ); // local temp file we just extracted
				$data = json_decode( $raw, true );
				if ( is_array( $data ) ) {
					return $data;
				}
			}
		}
		return null;
	}

	/**
	 * Find the bundle root: $dir if the section files sit at its top, else its
	 * single subdirectory (zips often wrap everything in one folder).
	 *
	 * @param string $dir
	 * @return string
	 */
	/**
	 * Is this unzipped folder a pre-built CONVERT BUNDLE (the capture service's convert-bundle.zip, or a
	 * "Download bundle" output: bundle.json + pages.json + theme-design.json …) rather than a RAW builder
	 * export (a Stitch code.html)? Lets the upload flow import it directly via import_dir() instead of
	 * routing it through the raw-source auto-detect — which would treat it as a Stitch export, find no
	 * code.html, and fail with "No Stitch code.html found to convert."
	 *
	 * @param string $dir
	 * @return bool
	 */
	public static function looks_like_bundle( $dir ) {
		if ( ! is_string( $dir ) || ! is_dir( $dir ) ) {
			return false;
		}
		$root    = self::locate_root( $dir );
		$markers = array_merge( self::FILE_MANIFEST, self::FILE_PAGES, self::FILE_THEME_DESIGN );
		foreach ( $markers as $m ) {
			if ( is_file( trailingslashit( $root ) . $m ) ) {
				return true;
			}
		}
		return false;
	}

	private static function locate_root( $dir ) {
		$markers = array_merge( self::FILE_MANIFEST, self::FILE_MEDIA, self::FILE_PRESETS, self::FILE_THEME_SETTINGS, self::FILE_THEME_DESIGN, self::FILE_PAGES, self::FILE_MENUS );
		foreach ( $markers as $m ) {
			if ( is_file( trailingslashit( $dir ) . $m ) ) {
				return $dir;
			}
		}
		$subs = glob( trailingslashit( $dir ) . '*', GLOB_ONLYDIR );
		if ( is_array( $subs ) && count( $subs ) === 1 ) {
			return $subs[0];
		}
		return $dir;
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
