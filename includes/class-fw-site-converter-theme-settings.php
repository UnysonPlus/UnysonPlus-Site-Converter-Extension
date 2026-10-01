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
	/**
	 * Chrome containers a full-design conversion OWNS. On a non-scoped import these are REPLACED, not
	 * merged: a conversion that emits no footer columns (a flat link row, or a footer with no links —
	 * ~14% of a 172-footer corpus) previously left the PREVIOUS conversion's columns standing, so the
	 * new site rendered another site's footer. Same failure class the menus already guard against
	 * ("Phase 5-guard: STALE-MENU CONTAMINATION" in the bundle) — footer/header columns had no guard.
	 */
	const CHROME_KEYS = array(
		'header_main', 'header_topbar', 'header_bottombar',
		'pre_footer_columns', 'main_footer_columns', 'post_footer_columns',
		// The list is the FULL set of chrome containers the theme stores, derived by comparing against
		// the option files — not just the one that was reported broken. A footer-only fix would have
		// left the identical bug in the copyright bar and the mega-menu panels: a conversion that emits
		// neither keeps the PREVIOUS site's copyright line and mega menus. Same class, same guard.
		'copyright_settings', 'mega_menu',
		// The logo too: if a source has no detectable mark, the previous conversion's logo image is the
		// most visible thing that could survive into the new site.
		'header_logo',
	);

	/**
	 * Every design key the deterministic engines CAN emit (the union of Stitch build_theme_settings and the capture service's
	 * to-theme-settings). On a full conversion each one the payload does NOT carry is reset to the theme's DECLARED default —
	 * a key the new source has no signal for (a drawer colour, a mobile bar fill, a scroll-progress accent, a footer border)
	 * otherwise kept the PREVIOUS conversion's value, so successive conversions drifted toward "the last site". The element
	 * containers in CHROME_KEYS keep their clear-to-empty behaviour; everything else resets to the option's default.
	 */
	const OWNED_KEYS = array(
		'header_layout', 'header_menu', 'header_logo', 'header_main', 'header_topbar', 'header_bottombar', 'mega_menu',
		'drawer_bg', 'drawer_link_color', 'drawer_link_active_color', 'mobile_bar_bg', 'mobile_breakpoint', 'mobile_hide_topbar',
		'copyright_settings', 'copyright_custom_styling', 'pre_footer_columns', 'pre_footer_custom_styling', 'main_footer_columns', 'post_footer_columns', 'post_footer_custom_styling',
		'footer_background', 'footer_border_sides', 'footer_border_top', 'footer_border_top_extent', 'footer_col_gap', 'footer_columns', 'footer_link_color', 'footer_link_hover_color',
		'footer_mobile_columns', 'footer_padding_bottom', 'footer_padding_bottom_custom', 'footer_padding_top', 'footer_padding_top_custom', 'footer_text_color',
		// the BOXED-footer group: a previous conversion's boxed footer (`footer_body_box` + its skin) survived into a site whose
		// bundle carries no such key and rendered its footer as a box (a finding from the feed) — reset with the rest
		'footer_body_box', 'footer_box_background', 'footer_box_copyright_inside', 'footer_box_gutter', 'footer_box_max_width', 'footer_box_padding_x', 'footer_box_padding_y', 'footer_box_radius', 'footer_box_shadow', 'footer_border_top_extent_width', 'footer_overlay_last_section', 'footer_css_class',
		'general_layout', 'general_typography', 'typography', 'font_sizes', 'spacing_scale', 'gap_scale', 'social_profiles', 'social_style',
		'animation_cursor', 'animation_preloader', 'animation_scrollprog', 'preloader_style', 'scrollprog', 'nav_scrollspy', 'misc_custom_css',
	);

	/** The theme's DECLARED default for a settings option id (the option's own `value`, nested containers included); null when unknown. */
	private static function declared_default( $id ) {
		if ( ! function_exists( 'fw' ) || ! fw()->theme || ! function_exists( 'fw_extract_only_options' ) || ! function_exists( 'fw_get_options_values_from_input' ) ) { return null; }
		static $flat = null;
		if ( null === $flat ) { try { $flat = fw_extract_only_options( (array) fw()->theme->get_settings_options() ); } catch ( Throwable $e ) { $flat = array(); } }
		if ( ! isset( $flat[ $id ] ) || ! is_array( $flat[ $id ] ) ) { return null; }
		try { $v = fw_get_options_values_from_input( array( $id => $flat[ $id ] ), array() ); } catch ( Throwable $e ) { return null; }
		return array_key_exists( $id, $v ) ? $v[ $id ] : null;
	}

	/** Option holding the fingerprint of what the LAST conversion wrote, per settings key. */
	const FINGERPRINT_OPTION = 'fw_sc_settings_fingerprint';

	/** Stable hash of a settings value, for comparing "what we wrote" against "what is stored now". */
	private static function value_fingerprint( $value ) {
		return md5( (string) wp_json_encode( $value ) );
	}

	/**
	 * Settings keys the USER has changed since the last conversion wrote them.
	 *
	 * A conversion may overwrite a value it wrote ITSELF; it must not overwrite one the user has since
	 * edited. Converting a second page of the same site re-ran the full design import, which rewrote every
	 * chrome key from the fresh capture — so header and footer text the user had corrected after the first
	 * conversion silently reverted. The page importers already work this way (`_upw_import_hash`); this is
	 * the same guard for theme settings.
	 *
	 * Comparing the stored value against the fingerprint of what we last wrote distinguishes the two
	 * cases exactly: unchanged since our write → ours to replace; different → the user's, leave it.
	 * With no fingerprint recorded (a first conversion, or an older install) nothing is protected, which
	 * is the correct default — there is no user edit to lose yet.
	 *
	 * @return array [ key => true ] for keys that differ from what the last conversion wrote
	 */
	private static function user_edited_keys() {
		$prints = get_option( self::FINGERPRINT_OPTION, array() );
		if ( ! is_array( $prints ) || ! $prints ) { return array(); }

		$edited = array();
		foreach ( $prints as $key => $print ) {
			$stored = fw_get_db_settings_option( $key, null );
			if ( null === $stored ) { continue; }
			if ( self::value_fingerprint( $stored ) !== $print ) { $edited[ $key ] = true; }
		}
		return $edited;
	}

	/**
	 * Re-fingerprint keys AFTER the whole import has run.
	 *
	 * Later phases (theme generation, presets, the page pass) can legitimately rewrite a settings value
	 * that this class already fingerprinted. Without this the next conversion would read that as a user
	 * edit and protect it forever — a key the converter itself changed would never update again. Called
	 * once at the end of a full import, over the keys it imported.
	 *
	 * @param string[] $keys settings keys to re-read and re-stamp
	 */
	public static function refresh_fingerprints( array $keys ) {
		// BASELINE THE WHOLE STATE, not just the keys this phase wrote.
		//
		// Re-stamping only the imported keys left two holes, and both of them lock the converter out of a
		// key permanently: a key CLEARED by the replace-chrome pass is never re-stamped, and a key a LATER
		// phase rewrites (theme generation, presets, the page pass) keeps the stamp taken before that
		// rewrite. Either way the next conversion reads the converter's own work as a user edit, skips the
		// key — and because a skipped key is never re-stamped either, it stays skipped forever. Measured:
		// a run that imported cleanly (0 skipped) was followed by one reporting 26 keys "user-edited",
		// after which the footer padding, columns and border could not be written again at all.
		//
		// At the end of a FULL import the settings state IS the converter's work, by definition — so every
		// key it owns is baselined here. A user edit can then only happen after this point, which is
		// exactly what the guard is meant to notice.
		$all = array_unique( array_merge( $keys, self::CHROME_KEYS, self::OWNED_KEYS ) );
		self::record_fingerprints( $all );
	}

	/** Record what this conversion wrote, so the NEXT one can tell our values from the user's. */
	private static function record_fingerprints( array $written ) {
		$prints = get_option( self::FINGERPRINT_OPTION, array() );
		if ( ! is_array( $prints ) ) { $prints = array(); }
		foreach ( $written as $key ) {
			$v = fw_get_db_settings_option( $key, null );
			// A key with NO stored value has nothing to protect: keeping a stamp for it would make the next
			// read (null, skipped by user_edited_keys) and any later write disagree for no reason.
			if ( null === $v ) { unset( $prints[ $key ] ); continue; }
			$prints[ $key ] = self::value_fingerprint( $v );
		}
		update_option( self::FINGERPRINT_OPTION, $prints, false );
	}

	/**
	 * @param array $data           the theme-settings payload
	 * @param bool  $replace_chrome clear chrome the payload does not carry (a full, non-scoped import)
	 * @param bool  $force          overwrite even values the user edited since the last conversion
	 */
	public static function import( $data, $replace_chrome = false, $force = false ) {
		$out = array( 'imported' => array(), 'skipped' => array(), 'cleared' => array(), 'cross_theme' => false, 'error' => '' );

		if ( ! is_array( $data ) ) {
			$out['error'] = __( 'Invalid theme-settings payload — expected a JSON object.', 'fw' );
			return $out;
		}
		if ( ! function_exists( 'fw_get_db_settings_option' ) || ! function_exists( 'fw_set_db_settings_option' ) ) {
			$out['error'] = __( 'Theme settings are unavailable (Unyson framework / a compatible theme is not active).', 'fw' );
			return $out;
		}

		$incoming = ( isset( $data['values'] ) && is_array( $data['values'] ) ) ? $data['values'] : $data;

		/**
		 * The Theme Settings values a conversion is about to write — the last point at which they can be
		 * corrected without editing the converter.
		 *
		 * This is where a SITE-SPECIFIC correction belongs: the converter measured a value it could not
		 * express, or expressed it as the wrong option, and you want THIS site right without a core patch
		 * that would be wrong for every other source. Add, change or unset keys; the fingerprinting and the
		 * user-edit guard below both apply to the result, so a value set here is still never written over one
		 * the user has since edited by hand.
		 *
		 * @since 1.10.12
		 * @param array $incoming       option id => value.
		 * @param bool  $replace_chrome whether this conversion is replacing the header/footer chrome.
		 * @param bool  $force          whether it is overwriting values the user edited.
		 */
		$incoming = (array) apply_filters( 'fw_site_converter_theme_settings', $incoming, $replace_chrome, $force );

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

		// LOCALIZE external media URLs that survive strip_media — chiefly the Custom-Logo-Layout logo icon
		// (`{ type:'custom-upload', url:<source CDN>, attachment-id:false }`), which strip_media leaves alone
		// (its key is `attachment-id`, not `attachment_id`). Without this the header logo HOTLINKS to the
		// source CDN even though Phase-1 media already downloaded a local copy. The media engine dedupes by
		// URL, so this reuses that copy (or fetches on demand for a direct import) and points the field at the
		// local attachment.
		$incoming = self::localize_media( $incoming );

		if ( empty( $incoming ) ) {
			$out['error'] = __( 'No importable theme-settings keys in the payload.', 'fw' );
			return $out;
		}

		// Apply each imported design key ON ITS OWN (the single-option set path),
		// so we only ever touch the keys the file carries. Writing the WHOLE map
		// via fw_set_db_settings_option(null, …) would re-run every registered
		// option's storage_save() on its already-stored value — which expects fresh
		// form input, not the stored shape, and corrupts unrelated settings.
		// REPLACE (not merge) the chrome this conversion owns: any chrome container the payload does
		// NOT carry is cleared first, so nothing from a previous conversion survives into this one.
		// Scoped/partial imports skip this — they are deliberately additive.
		$user_edited = $force ? array() : self::user_edited_keys();

		if ( $replace_chrome ) {
			foreach ( self::CHROME_KEYS as $ck ) {
				if ( isset( $user_edited[ $ck ] ) ) { $out['skipped'][] = $ck; continue; } // the user's, not ours
				if ( ! array_key_exists( $ck, $incoming ) ) {
					$existing = fw_get_db_settings_option( $ck, null );
					if ( ! empty( $existing ) ) {
						fw_set_db_settings_option( $ck, array() );
						$out['cleared'][] = $ck;
					}
				}
			}
			// …and every other key the engines own but this payload does not carry → the theme's declared default, so a
			// site with no signal for it never inherits the previous conversion's value ("the last header wins").
			foreach ( self::OWNED_KEYS as $ok ) {
				if ( array_key_exists( $ok, $incoming ) || in_array( $ok, self::CHROME_KEYS, true ) || in_array( $ok, $exclude, true ) ) { continue; }
				if ( isset( $user_edited[ $ok ] ) ) { $out['skipped'][] = $ok; continue; } // the user's, not ours
				$existing = fw_get_db_settings_option( $ok, null );
				if ( null === $existing ) { continue; }
				$def = self::declared_default( $ok );
				if ( null === $def ) { continue; }
				if ( wp_json_encode( $existing ) === wp_json_encode( $def ) ) { continue; }
				fw_set_db_settings_option( $ok, $def );
				$out['cleared'][] = $ok;
			}
		}
		foreach ( $incoming as $k => $v ) {
			if ( isset( $user_edited[ $k ] ) ) { $out['skipped'][] = $k; continue; } // edited since we wrote it
			fw_set_db_settings_option( $k, $v );
			$out['imported'][] = $k;
		}

		// Fingerprint everything this conversion actually wrote, so a LATER conversion can tell our values
		// from the user's. Keys we skipped keep their previous fingerprint, which is what still marks them
		// as edited next time.
		if ( $out['imported'] ) { self::record_fingerprints( $out['imported'] ); }
		$out['skipped'] = array_values( array_unique( $out['skipped'] ) );

		// IMPORTANT: do NOT fire `fw_settings_form_saved` here. Its hooks
		// (identity-sync, google-fonts regen) re-write / re-process settings as a
		// side effect — re-running storage_save on stored values — which is what
		// corrupted Theme Settings before. The design keys are stored; saving the
		// Theme Settings page once will regenerate any derived assets if needed.
		//
		// BUT the theme's cached front-end CSS (`unysonplus-generated.css`, the `:root`
		// token block that carries `--site-bg-color`, fonts, etc.) IS normally rebuilt by
		// `fw_settings_form_saved` — which we just skipped. Without a rebuild the cache
		// keeps the OLD defaults, so a converted dark site stores its `#010102` site
		// background but the body still renders white (the exact "dark background dropped"
		// bug). Rebuild the cache DIRECTLY: `unysonplus_hf_regenerate_css()` only recompiles
		// the CSS file from the current stored option values — it does NOT re-run any
		// option's storage_save(), so it's safe here (unlike firing the settings-saved hook).
		if ( ! empty( $out['imported'] ) && function_exists( 'unysonplus_hf_regenerate_css' ) ) {
			unysonplus_hf_regenerate_css();
		}

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
		if ( is_array( $value ) ) {
			// A media value carrying an attachment id (either key style). Blank the SOURCE-site id (meaningless
			// locally) but KEEP the `url` — localize_media() runs next and needs the url to sideload the file and
			// re-attach a LOCAL id. The old behaviour (and the theme's stripper) blanked the WHOLE value to [],
			// which DROPPED the url → the header logo image was lost and the theme fell back to the site-title
			// text ("NOIR"). We no longer delegate to unysonplus_settings_io_strip_media for that reason.
			if ( array_key_exists( 'attachment_id', $value ) || array_key_exists( 'attachment-id', $value ) ) {
				if ( array_key_exists( 'attachment_id', $value ) ) { $value['attachment_id'] = ''; }
				if ( array_key_exists( 'attachment-id', $value ) ) { $value['attachment-id'] = false; }
				return $value;
			}
			foreach ( $value as $k => $v ) {
				$value[ $k ] = self::strip_media( $v );
			}
		}
		return $value;
	}

	/**
	 * Recursively sideload EXTERNAL image URLs referenced by settings (a `custom-upload` logo icon, or any
	 * media value carrying a `url` + an `attachment(-|_)id`) into the media library, so nothing hotlinks to
	 * the source site. Reuses the already-downloaded copy (the media engine dedupes by URL). A local URL, a
	 * data: URI, or a non-media value passes through untouched.
	 *
	 * @param mixed $value
	 * @return mixed
	 */
	private static function localize_media( $value ) {
		if ( ! is_array( $value ) ) { return $value; }
		$url      = isset( $value['url'] ) ? (string) $value['url'] : '';
		$is_media = $url !== '' && ( ( isset( $value['type'] ) && $value['type'] === 'custom-upload' )
			|| array_key_exists( 'attachment_id', $value ) || array_key_exists( 'attachment-id', $value ) );
		// An INLINE image (`data:image/svg+xml;base64,…`) is a real asset, not a non-media value: a wordmark
		// embedded that way is what a build tool does with a small SVG, and passing it through left the setting
		// holding a 3 KB string that the theme's upload-option resolver cannot turn back into an attachment —
		// so the logo fell back to whatever else was around. The media engine already decodes and sideloads
		// these; it just was never asked to here.
		if ( $is_media && 0 === stripos( $url, 'data:image/' ) && class_exists( 'FW_Site_Converter_Media' ) ) {
			$id = FW_Site_Converter_Media::sideload( $url );
			if ( $id && ! is_wp_error( $id ) ) {
				$local = function_exists( 'wp_get_attachment_url' ) ? wp_get_attachment_url( (int) $id ) : '';
				if ( $local ) {
					$value['url'] = $local;
					if ( array_key_exists( 'attachment-id', $value ) ) { $value['attachment-id'] = (string) $id; }
					if ( array_key_exists( 'attachment_id', $value ) )  { $value['attachment_id']  = (string) $id; }
				}
			}
			return $value;
		}
		if ( $is_media && preg_match( '#^https?://#i', $url ) && ! self::is_local_url( $url ) && class_exists( 'FW_Site_Converter_Media' ) ) {
			$id = FW_Site_Converter_Media::sideload( $url );
			if ( $id && ! is_wp_error( $id ) ) {
				$local = function_exists( 'wp_get_attachment_url' ) ? wp_get_attachment_url( (int) $id ) : '';
				if ( $local ) {
					$value['url'] = $local;
					if ( array_key_exists( 'attachment-id', $value ) ) { $value['attachment-id'] = (string) $id; }
					if ( array_key_exists( 'attachment_id', $value ) )  { $value['attachment_id']  = (string) $id; }
				}
			}
			return $value; // resolved media value — don't recurse into it
		}
		// ROOT-RELATIVE media (a header/footer LOGO stored as `/assets/logo.png`) — the http branch above
		// misses it, so it would 404 as `localhost/assets/logo.png`. Basename-match it to the imported copy
		// (else absolutise to the source origin for a working hotlink) via the mapper's shared resolver.
		if ( $is_media && '/' === $url[0] && '//' !== substr( $url, 0, 2 ) && 0 !== strpos( $url, '/wp-content/' )
			&& class_exists( 'FW_Site_Converter_Mapper' ) ) {
			$v = FW_Site_Converter_Mapper::upload_val( $url );
			if ( ! empty( $v['url'] ) ) {
				$value['url'] = (string) $v['url'];
				if ( ! empty( $v['attachment_id'] ) ) {
					if ( array_key_exists( 'attachment-id', $value ) ) { $value['attachment-id'] = (string) $v['attachment_id']; }
					if ( array_key_exists( 'attachment_id', $value ) )  { $value['attachment_id']  = (string) $v['attachment_id']; }
				}
			}
			return $value;
		}
		foreach ( $value as $k => $v ) { $value[ $k ] = self::localize_media( $v ); }
		return $value;
	}

	/** True when a URL points at THIS site (its uploads) — already local, nothing to sideload. */
	private static function is_local_url( $url ) {
		if ( ! function_exists( 'wp_parse_url' ) || ! function_exists( 'home_url' ) ) { return false; }
		$h    = wp_parse_url( $url, PHP_URL_HOST );
		$site = wp_parse_url( home_url(), PHP_URL_HOST );
		return $h && $site && strcasecmp( $h, $site ) === 0;
	}
}
