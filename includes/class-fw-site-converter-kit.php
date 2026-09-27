<?php if ( ! defined( 'FW' ) ) { die( 'Forbidden' ); }

/**
 * THE AI DEV KIT CROSS-CHECK — "is the kit beside this install actually current?"
 *
 * The kit carries `kit-manifest.json`, whose `bump_triggers` record which Site Converter and Capture Service
 * versions its documentation was written against. Nothing verified that pairing, so a kit could sit several
 * converter versions behind while its docs described behaviour that had since changed — and the only symptom
 * was an agent confidently following stale guidance. (Measured on the authoring machine the moment this was
 * written: the converter was at 1.10.13 and the kit's trigger still said 1.10.10, because the script meant to
 * update it matched a key spelled differently and silently updated nothing. Three times.)
 *
 * Deliberately gated on a CONSTANT, not a setting:
 *
 *     define( 'FW_UPW_KIT_PATH', 'D:/Web Dev/UnysonPlus-AI-Dev-Kit' );
 *
 * The kit is a developer's local download; on a normal host it is not on the WordPress filesystem at all, so
 * a settings field would be blank or wrong nearly everywhere and would invite an admin to point the plugin at
 * an arbitrary directory. A constant lives in wp-config.php, is out of reach of a compromised admin account,
 * and says "developer machine only" without documentation. Undefined constant = this class does nothing.
 *
 * Read-only and narrow by construction: ONE known filename, resolved with realpath() and required to sit
 * inside the configured root, size-capped, and every failure degrades to "no notice" rather than to a warning
 * about a path the reader cannot act on.
 */
class FW_Site_Converter_Kit {

	/** The one file this class will ever read. */
	const MANIFEST = 'kit-manifest.json';
	/** A version marker file has no business being large; anything bigger is not what we think it is. */
	const MAX_BYTES = 64000;

	/** @var array|null memoised status for the request */
	private static $status = null;

	/** Whether a kit path is configured at all. */
	public static function configured() {
		return defined( 'FW_UPW_KIT_PATH' ) && '' !== trim( (string) FW_UPW_KIT_PATH );
	}

	/**
	 * The kit's recorded state, or null when there is nothing to say.
	 *
	 * @return array|null array(
	 *     kit_version, kit_path,
	 *     expects   => array( site_converter, capture_service ),
	 *     installed => array( site_converter ),
	 *     verdict   => 'current' | 'kit_behind' | 'plugin_behind',
	 *     message   => string,
	 * )
	 */
	public static function status() {
		if ( null !== self::$status ) { return self::$status ?: null; }
		self::$status = false;

		if ( ! self::configured() ) { return null; }

		$root = realpath( (string) FW_UPW_KIT_PATH );
		if ( false === $root || ! is_dir( $root ) ) { return null; }

		$file = realpath( $root . DIRECTORY_SEPARATOR . self::MANIFEST );
		// Confine to the configured root: realpath() has resolved any traversal, so a file that does not
		// begin with the root after resolution is not the file we were asked for.
		if ( false === $file || 0 !== strpos( $file, $root ) || ! is_readable( $file ) ) { return null; }
		if ( filesize( $file ) > self::MAX_BYTES ) { return null; }

		$data = json_decode( (string) file_get_contents( $file ), true );
		if ( ! is_array( $data ) ) { return null; }

		$triggers = ( isset( $data['bump_triggers'] ) && is_array( $data['bump_triggers'] ) ) ? $data['bump_triggers'] : array();
		$expect_c = isset( $triggers['site_converter_extension'] ) ? (string) $triggers['site_converter_extension'] : '';
		$expect_s = isset( $triggers['capture_service'] ) ? (string) $triggers['capture_service'] : '';
		$kit_ver  = isset( $data['kit_version'] ) ? (string) $data['kit_version'] : '';

		$installed = self::installed_converter_version();
		if ( '' === $expect_c || '' === $installed ) { return null; }

		$cmp = version_compare( $expect_c, $installed );
		if ( 0 === $cmp ) {
			$verdict = 'current';
			$message = '';
		} elseif ( $cmp < 0 ) {
			// The kit was written against an OLDER converter: its docs may describe behaviour that changed.
			$verdict = 'kit_behind';
			$message = sprintf(
				/* translators: 1: kit's recorded converter version, 2: installed converter version */
				__( 'Its docs were written against Site Converter %1$s; this install runs %2$s. Pull the kit before trusting its converter pages, or an agent reading them will follow guidance that has since changed.', 'fw' ),
				$expect_c,
				$installed
			);
		} else {
			// The kit is AHEAD: it documents a converter newer than the one installed here.
			$verdict = 'plugin_behind';
			$message = sprintf(
				/* translators: 1: kit's recorded converter version, 2: installed converter version */
				__( 'It documents Site Converter %1$s, but this install runs %2$s — the kit describes behaviour this copy of the plugin does not have yet. Update the plugin.', 'fw' ),
				$expect_c,
				$installed
			);
		}

		self::$status = array(
			'kit_version' => $kit_ver,
			'kit_path'    => $root,
			'expects'     => array( 'site_converter' => $expect_c, 'capture_service' => $expect_s ),
			'installed'   => array( 'site_converter' => $installed ),
			'verdict'     => $verdict,
			'message'     => $message,
		);
		return self::$status;
	}

	/**
	 * Render the cross-check where the converter's own screens are.
	 *
	 * Nothing is shown when no kit is configured, which is every normal install. When one IS configured the
	 * agreeing case still prints a single quiet line: a check that only ever speaks up to complain leaves the
	 * reader unable to tell "verified current" from "not checked at all".
	 */
	public static function render_notice() {
		$s = self::status();
		if ( ! $s ) { return; }

		if ( 'current' === $s['verdict'] ) {
			echo '<p class="description" style="margin:.4em 0">'
				. esc_html( sprintf(
					/* translators: 1: kit version, 2: converter version, 3: capture service version */
					__( 'AI Dev Kit %1$s is current for Site Converter %2$s (it expects Capture Service %3$s — the Diagnostics health check reports the version actually running).', 'fw' ),
					$s['kit_version'],
					$s['installed']['site_converter'],
					'' !== $s['expects']['capture_service'] ? $s['expects']['capture_service'] : __( 'unrecorded', 'fw' )
				) )
				. '</p>';
			return;
		}

		echo '<div class="notice notice-warning inline" style="margin:.6em 0"><p><strong>'
			. esc_html( sprintf( __( 'The AI Dev Kit beside this install is out of step (kit %s).', 'fw' ), $s['kit_version'] ) )
			. '</strong> ' . esc_html( $s['message'] ) . '</p>'
			. '<p class="description" style="margin:0">' . esc_html( $s['kit_path'] ) . '</p></div>';
	}

	/** The installed Site Converter extension's version, or '' when it cannot be read. */
	private static function installed_converter_version() {
		if ( function_exists( 'fw_ext' ) ) {
			$ext = fw_ext( 'site-converter' );
			// `manifest` is a PROPERTY on FW_Extension, not a method — guarding it with method_exists( $ext,
			// 'manifest' ) is always false, which is how a sibling class silently reported 'unknown' forever.
			if ( $ext && isset( $ext->manifest ) && method_exists( $ext->manifest, 'get_version' ) ) {
				$v = (string) $ext->manifest->get_version();
				if ( '' !== $v ) { return $v; }
			}
		}
		return '';
	}
}
