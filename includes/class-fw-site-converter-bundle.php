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
 *   ├── bundle.json        (optional metadata: { name, source, generated })
 *   ├── media.json         ({ "urls": [ "https://…/hero.jpg", … ] })  → media engine
 *   ├── presets.json       ({ "values": { theme_colors:[…], … } })    → presets engine
 *   ├── menus.json         ({ "menus": [ { name, location, items }, … ] }) → menu engine
 *   ├── theme-settings.json (reserved — reported, not yet applied)
 *   └── pages.json / pages/ (reserved — reported, not yet applied)
 *
 * Phases run in contract order: media → presets → (theme settings) → (pages) → menus.
 * Theme-settings + pages have no engine yet, so a bundle carrying them is imported
 * for the parts we support and those sections are reported as deferred.
 *
 * Static so a WP-CLI command can reuse it (mirrors the other engines).
 */
class FW_Site_Converter_Bundle {

	/** Filenames we read from the bundle (first match wins). */
	const FILE_MANIFEST = array( 'bundle.json', 'manifest.json' );
	const FILE_MEDIA          = array( 'media.json' );
	const FILE_PRESETS        = array( 'presets.json' );
	const FILE_THEME_SETTINGS = array( 'theme-settings.json', 'theme_settings.json' );
	const FILE_MENUS          = array( 'menus.json' );

	/** Sections we recognize but can't apply yet (reported as deferred). */
	const DEFERRED = array(
		'pages' => 'pages.json',
	);

	/**
	 * Unzip a bundle to a temp dir, apply it, then clean up.
	 *
	 * @param string $zip_path Path to the uploaded .zip (e.g. $_FILES tmp_name).
	 * @return array Combined result (see import_dir), with `error` set on unzip failure.
	 */
	public static function import_zip( $zip_path ) {
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

		$out = self::import_dir( $tmp );

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
	public static function import_dir( $dir ) {
		$out = self::blank_result();

		if ( ! is_string( $dir ) || ! is_dir( $dir ) ) {
			$out['error'] = __( 'Bundle folder not found.', 'fw' );
			return $out;
		}

		$dir = self::locate_root( $dir );

		$out['manifest'] = self::read_json( $dir, self::FILE_MANIFEST );

		// --- Phase 1: media (URL list → sideload, de-duped) ---
		$media = self::read_json( $dir, self::FILE_MEDIA );
		if ( $media !== null && class_exists( 'FW_Site_Converter_Media' ) ) {
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
		if ( $presets !== null && class_exists( 'FW_Site_Converter_Presets' ) ) {
			$out['presets']    = FW_Site_Converter_Presets::import( $presets );
			$out['sections'][] = 'presets';
		}

		// --- Phase 3: theme settings (design file → fw_theme_settings_options) ---
		$theme = self::read_json( $dir, self::FILE_THEME_SETTINGS );
		if ( $theme !== null && class_exists( 'FW_Site_Converter_Theme_Settings' ) ) {
			$out['theme_settings'] = FW_Site_Converter_Theme_Settings::import( $theme );
			$out['sections'][]     = 'theme-settings';
		}

		// --- Phase 4 (pages): reserved, reported as deferred ---
		foreach ( self::DEFERRED as $name => $file ) {
			$present = is_file( trailingslashit( $dir ) . $file )
				|| ( $name === 'pages' && is_dir( trailingslashit( $dir ) . 'pages' ) );
			if ( $present ) {
				$out['deferred'][] = $name;
			}
		}

		// --- Phase 5: menus ---
		$menus = self::read_json( $dir, self::FILE_MENUS );
		if ( $menus !== null && class_exists( 'FW_Site_Converter_Menus' ) ) {
			$out['menus']      = FW_Site_Converter_Menus::import( $menus );
			$out['sections'][] = 'menus';
		}

		if ( ! $out['sections'] ) {
			$out['error'] = __( 'The bundle had no recognized sections (media.json, presets.json, theme-settings.json, menus.json).', 'fw' );
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
			'menus'          => null,
			'sections'       => array(),
			'deferred'       => array(),
			'error'          => '',
		);
	}

	/**
	 * Roll up media import rows into counts.
	 *
	 * @param array $results Rows from FW_Site_Converter_Media::import_urls().
	 * @return array{imported: int, reused: int, failed: int, total: int}
	 */
	private static function summarize_media( array $results ) {
		$imported = 0;
		$reused   = 0;
		$failed   = 0;
		foreach ( $results as $r ) {
			if ( ! empty( $r['ok'] ) ) {
				if ( ! empty( $r['reused'] ) ) {
					$reused++;
				} else {
					$imported++;
				}
			} else {
				$failed++;
			}
		}
		return array( 'imported' => $imported, 'reused' => $reused, 'failed' => $failed, 'total' => count( $results ) );
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
	private static function locate_root( $dir ) {
		$markers = array_merge( self::FILE_MANIFEST, self::FILE_MEDIA, self::FILE_PRESETS, self::FILE_THEME_SETTINGS, self::FILE_MENUS );
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
