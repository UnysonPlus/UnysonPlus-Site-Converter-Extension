<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Site Converter — Theme Settings importer (engine).
 *
 * Applies a theme-settings "design file" (the conversion contract §4 export —
 * `{ "_fw_settings_export": {…}, "values": { id: value, … } }`) to the theme's
 * settings store: the single `fw_theme_settings_options:{theme-id}` wp_option,
 * read/written via `fw_get_db_settings_option()` / `fw_set_db_settings_option()`.
 * So a converted site's global chrome + typography defaults + bespoke CSS
 * (`misc_custom_css`) land in one step.
 *
 * Mirrors the theme's own Misc → Import (unysonplus-theme
 * inc/includes/settings-export-import.php) and reuses its helpers when the active
 * theme provides them: each top-level id is a whole container, so imported keys
 * are *overlaid* onto current values (settings the file doesn't carry are kept).
 * Operational keys (analytics / scripts / maintenance) are never imported, and
 * source-site media refs (`attachment_id`) are blanked — the media engine
 * re-attaches on the target (contract §4.2).
 *
 * Static so the Convert bundle / WP-CLI can reuse it (mirrors the other engines).
 */
class FW_Site_Converter_Theme_Settings {

	/**
	 * Operational keys never imported — design scope only; also blocks tracking /
	 * script injection sneaking in via a design file. Used as the fallback when the
	 * active theme doesn't expose `unysonplus_settings_io_exclude_keys()`.
	 */
	const DEFAULT_EXCLUDE = array(
		'misc_analytics',
		'misc_performance',
		'misc_maintenance',
		'misc_404',
		'misc_custom_scripts',
	);

	/**
	 * Apply a design file to the theme settings.
	 *
	 * @param array $data `{ values: { id: value, … } }` (optionally with a leading
	 *                    `_fw_settings_export` env block), or a raw `id => value`
	 *                    map. Keys starting with `_` are treated as metadata.
	 * @return array{imported: string[], skipped: string[], cross_theme: bool, error: string}
	 */
	public static function import( $data ) {
		$out = array( 'imported' => array(), 'skipped' => array(), 'cross_theme' => false, 'error' => '' );

		if ( ! is_array( $data ) ) {
			$out['error'] = __( 'Invalid theme-settings payload — expected a JSON object.', 'fw' );
			return $out;
		}
		if ( ! function_exists( 'fw_get_db_settings_option' ) || ! function_exists( 'fw_set_db_settings_option' ) ) {
			$out['error'] = __( 'Theme settings are unavailable (Unyson framework / a compatible theme is not active).', 'fw' );
			return $out;
		}

		$incoming = ( isset( $data['values'] ) && is_array( $data['values'] ) ) ? $data['values'] : $data;

		// Drop metadata keys (when given a raw map).
		foreach ( array_keys( $incoming ) as $k ) {
			if ( ! is_string( $k ) || $k === '' || $k[0] === '_' ) {
				unset( $incoming[ $k ] );
			}
		}

		// Never import operational / script keys.
		$exclude = function_exists( 'unysonplus_settings_io_exclude_keys' )
			? (array) unysonplus_settings_io_exclude_keys()
			: self::DEFAULT_EXCLUDE;
		foreach ( $exclude as $k ) {
			if ( array_key_exists( $k, $incoming ) ) {
				$out['skipped'][] = $k;
				unset( $incoming[ $k ] );
			}
		}

		// Blank source-site media refs (attachment_id) — re-attached via the media engine.
		$incoming = self::strip_media( $incoming );

		if ( empty( $incoming ) ) {
			$out['error'] = __( 'No importable theme-settings keys in the payload.', 'fw' );
			return $out;
		}

		// Apply each imported design key ON ITS OWN (the single-option set path),
		// so we only ever touch the keys the file carries. Writing the WHOLE map
		// via fw_set_db_settings_option(null, …) would re-run every registered
		// option's storage_save() on its already-stored value — which expects fresh
		// form input, not the stored shape, and corrupts unrelated settings.
		foreach ( $incoming as $k => $v ) {
			fw_set_db_settings_option( $k, $v );
			$out['imported'][] = $k;
		}

		// IMPORTANT: do NOT fire `fw_settings_form_saved` here. Its hooks
		// (identity-sync, google-fonts regen) re-write / re-process settings as a
		// side effect — re-running storage_save on stored values — which is what
		// corrupted Theme Settings before. The design keys are stored; saving the
		// Theme Settings page once will regenerate any derived assets if needed.

		// Warn if the design file came from a different theme.
		if ( isset( $data['_fw_settings_export']['theme_id'] ) && function_exists( 'fw' ) && fw()->theme && fw()->theme->manifest ) {
			$tid                = (string) fw()->theme->manifest->get_id();
			$out['cross_theme'] = ( $tid !== '' && $data['_fw_settings_export']['theme_id'] !== $tid );
		}

		return $out;
	}

	/**
	 * Convenience: parse a raw JSON string then import.
	 *
	 * @param string $json
	 * @return array{imported: string[], skipped: string[], cross_theme: bool, error: string}
	 */
	public static function import_json( $json ) {
		$json = trim( (string) $json );
		if ( $json === '' ) {
			return array( 'imported' => array(), 'skipped' => array(), 'cross_theme' => false, 'error' => __( 'Paste a theme-settings design JSON to import.', 'fw' ) );
		}
		$decoded = json_decode( $json, true );
		if ( ! is_array( $decoded ) ) {
			return array( 'imported' => array(), 'skipped' => array(), 'cross_theme' => false, 'error' => __( 'That is not valid JSON.', 'fw' ) );
		}
		return self::import( $decoded );
	}

	/**
	 * Inspect the current theme-settings state — for diagnosing a broken
	 * (blank / non-rendering) Theme Settings page. Reads the raw wp_option
	 * directly (truth), the framework's processed view, and how many settings
	 * options the active theme registers. All risky calls are guarded so the
	 * diagnostic itself never fatals.
	 *
	 * @return array
	 */
	public static function diagnose() {
		$d = array(
			'theme_id'    => '',
			'theme_name'  => '',
			'option_name' => '',
			'exists'      => false,
			'is_array'    => false,
			'size'        => 0,
			'key_count'   => 0,
			'keys'        => array(),
			'nonarray'    => array(), // top-level keys whose value isn't an array (suspicious)
			'get_ok'      => false,
			'get_count'   => 0,
			'get_error'   => '',
			'registered'  => 0,
			'reg_error'   => '',
		);

		if ( function_exists( 'fw' ) && fw()->theme && fw()->theme->manifest ) {
			$d['theme_id']   = (string) fw()->theme->manifest->get_id();
			$d['theme_name'] = (string) fw()->theme->manifest->get_name();
		}
		$d['option_name'] = 'fw_theme_settings_options:' . $d['theme_id'];

		$raw = get_option( $d['option_name'], null );
		if ( $raw !== null ) {
			$d['exists']   = true;
			$d['is_array'] = is_array( $raw );
			$d['size']     = strlen( (string) maybe_serialize( $raw ) );
			if ( is_array( $raw ) ) {
				$d['keys']      = array_keys( $raw );
				$d['key_count'] = count( $raw );
				foreach ( $raw as $k => $v ) {
					if ( ! is_array( $v ) ) {
						$d['nonarray'][] = $k . ' (' . gettype( $v ) . ')';
					}
				}
			}
		}

		if ( function_exists( 'fw_get_db_settings_option' ) ) {
			try {
				$got            = fw_get_db_settings_option();
				$d['get_ok']    = is_array( $got );
				$d['get_count'] = is_array( $got ) ? count( $got ) : 0;
			} catch ( \Throwable $e ) {
				$d['get_error'] = $e->getMessage();
			}
		}

		if ( function_exists( 'fw' ) && fw()->theme && method_exists( fw()->theme, 'get_settings_options' ) ) {
			try {
				$opts            = fw()->theme->get_settings_options();
				$d['registered'] = is_array( $opts ) ? count( $opts ) : 0;
			} catch ( \Throwable $e ) {
				$d['reg_error'] = $e->getMessage();
			}
		}

		return $d;
	}

	/**
	 * Reset the theme settings by deleting the `fw_theme_settings_options:{id}`
	 * wp_option (a plain option — `FW_WP_Option` get/set map straight to
	 * get_option/update_option). Absent → the framework falls back to option
	 * defaults, so a corrupted / non-rendering Theme Settings page comes back.
	 *
	 * @return array{option_name: string, existed: bool}
	 */
	public static function reset() {
		$theme_id = ( function_exists( 'fw' ) && fw()->theme && fw()->theme->manifest )
			? (string) fw()->theme->manifest->get_id()
			: '';
		$name    = 'fw_theme_settings_options:' . $theme_id;
		$existed = ( get_option( $name, null ) !== null );
		delete_option( $name );
		return array( 'option_name' => $name, 'existed' => $existed );
	}

	/**
	 * Recursively blank media fields (any array carrying an `attachment_id`).
	 * Reuses the theme's stripper when available so the two stay in lockstep.
	 *
	 * @param mixed $value
	 * @return mixed
	 */
	private static function strip_media( $value ) {
		if ( function_exists( 'unysonplus_settings_io_strip_media' ) ) {
			return unysonplus_settings_io_strip_media( $value );
		}
		if ( is_array( $value ) ) {
			if ( array_key_exists( 'attachment_id', $value ) ) {
				return array();
			}
			foreach ( $value as $k => $v ) {
				$value[ $k ] = self::strip_media( $v );
			}
		}
		return $value;
	}
}
