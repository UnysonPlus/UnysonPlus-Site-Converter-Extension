<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Site Converter — Styling Presets importer (engine).
 *
 * Applies a presets export (the `_fw_presets_export` / `presets.json` an agent emits
 * per the conversion contract) to the plugin-owned, theme-independent preset store —
 * the Shortcodes extension settings `wp_option` `fw_ext_settings_options:shortcodes`,
 * the same place `unysonplus_preset_store_get()` reads from. So a converted site's
 * palette, font sizes, button colors, spacing/gap scales, etc. land in one step.
 *
 * Static + side-effect-light so a future "Convert bundle" importer and a WP-CLI
 * command can reuse it (mirrors FW_Site_Converter_Media).
 */
class FW_Site_Converter_Presets {

	/** The theme-independent preset store option (read by unysonplus_preset_store_get). */
	const OPTION = 'fw_ext_settings_options:shortcodes';

	/**
	 * Importable preset keys — the whitelist. Anything else in the payload is
	 * reported as skipped (never written), so a stray/typo'd key can't pollute the
	 * settings option. Mirrors the keys read across framework/includes/presets/*.php.
	 */
	const ALLOWED_KEYS = array(
		'theme_colors',
		'font_sizes',
		'button_colors',
		'button_sizes',
		'button_animations',
		'border_presets',
		'table_presets',
		'spacing_scale',
		'gap_scale',
		'default_gap',
		'default_gap_x',
		'default_gap_y',
	);

	/**
	 * @return string[]
	 */
	public static function allowed_keys() {
		return self::ALLOWED_KEYS;
	}

	/**
	 * Apply a presets payload to the store.
	 *
	 * @param array $data Parsed JSON. Either a `{ "values": { key: value, … } }`
	 *                    envelope (also tolerates a leading `_fw_presets_export`
	 *                    metadata block alongside `values`) or a raw `key => value`
	 *                    map. Keys starting with `_` are treated as metadata/notes
	 *                    and ignored.
	 * @return array{imported: array<string,int>, skipped: string[], error: string}
	 *               `imported` maps each written key to its item count (arrays) or 1.
	 */
	public static function import( $data ) {
		$out = array( 'imported' => array(), 'skipped' => array(), 'error' => '' );

		if ( ! is_array( $data ) ) {
			$out['error'] = __( 'Invalid presets payload — expected a JSON object.', 'fw' );
			return $out;
		}
		if ( ! class_exists( 'FW_WP_Option' ) ) {
			$out['error'] = __( 'Option storage is unavailable.', 'fw' );
			return $out;
		}

		$values = ( isset( $data['values'] ) && is_array( $data['values'] ) ) ? $data['values'] : $data;

		foreach ( $values as $key => $val ) {
			if ( ! is_string( $key ) || $key === '' || $key[0] === '_' ) {
				continue; // metadata / notes (_note, _apply_to_option, _strategy, …)
			}
			if ( ! in_array( $key, self::ALLOWED_KEYS, true ) ) {
				$out['skipped'][] = $key;
				continue;
			}
			// json_decode gives only scalars/arrays, so the value is plain data.
			FW_WP_Option::set( self::OPTION, $key, $val );
			$out['imported'][ $key ] = is_array( $val ) ? count( $val ) : 1;
		}

		return $out;
	}

	/**
	 * Convenience: parse a raw JSON string then import.
	 *
	 * @param string $json
	 * @return array{imported: array<string,int>, skipped: string[], error: string}
	 */
	public static function import_json( $json ) {
		$json = trim( (string) $json );
		if ( $json === '' ) {
			return array( 'imported' => array(), 'skipped' => array(), 'error' => __( 'Paste a presets JSON to import.', 'fw' ) );
		}
		$decoded = json_decode( $json, true );
		if ( ! is_array( $decoded ) ) {
			return array( 'imported' => array(), 'skipped' => array(), 'error' => __( 'That is not valid JSON.', 'fw' ) );
		}
		return self::import( $decoded );
	}
}
