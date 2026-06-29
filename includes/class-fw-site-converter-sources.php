<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Site Converter — source detection + adapter registry (the "From a file" auto-detect).
 *
 * The unified Convert flow has two input methods: a live URL (the capture service) and a FILE upload
 * (an export `.zip` / `.html` from an AI website builder). This registry inspects an uploaded export,
 * AUTO-DETECTS which builder produced it, and routes to the matching adapter — so the system converts
 * "as many website-creation tools as possible" without a tab per tool.
 *
 * Adding a new builder = register one adapter here (a `detect_*` confidence scorer + a `build` that
 * returns the standard bundle). Anything unrecognized falls back to the **generic HTML** path (the
 * Stitch engine doubles as a plain-HTML converter — it walks semantic sections regardless of source).
 *
 * Today: Google Stitch (specialized) + generic HTML (fallback). Future: Lovable, Bolt, v0, Framer,
 * Webflow exports, … — each just adds an entry to adapters().
 */
class FW_Site_Converter_Sources {

	/** Minimum confidence to claim a specialized source (else → generic HTML). */
	const MIN_CONFIDENCE = 0.5;

	/**
	 * Registered adapters, keyed by source slug. Each: label, a folder + html detector (0..1
	 * confidence), and a `build` callback returning the standard bundle (build_bundle() shape).
	 *
	 * @return array
	 */
	public static function adapters() {
		$adapters = array(
			'stitch' => array(
				'label'       => __( 'Google Stitch', 'fw' ),
				'detect_dir'  => array( 'FW_Site_Converter_Stitch', 'detect_dir' ),
				'detect_html' => array( 'FW_Site_Converter_Stitch', 'detect_html' ),
				'build'       => array( 'FW_Site_Converter_Stitch', 'build_bundle' ),
			),
			// Future builders register here, e.g.:
			// 'lovable' => array( 'label' => 'Lovable', 'detect_dir' => …, 'build' => … ),
		);
		/**
		 * Filter the converter's source adapters so other extensions can teach it new builders.
		 *
		 * @param array $adapters
		 */
		return apply_filters( 'fw_site_converter_sources', $adapters );
	}

	/** The fallback identity (an unrecognized export → treat as plain HTML). */
	private static function generic( $confidence = 0.0 ) {
		return array( 'key' => 'generic', 'label' => __( 'HTML export', 'fw' ), 'confidence' => (float) $confidence );
	}

	/**
	 * Identify the best-matching source for an unzipped export folder.
	 *
	 * @param string $dir
	 * @return array{ key:string, label:string, confidence:float }
	 */
	public static function identify_dir( $dir ) {
		$best = self::generic();
		foreach ( self::adapters() as $key => $a ) {
			if ( empty( $a['detect_dir'] ) || ! is_callable( $a['detect_dir'] ) ) { continue; }
			$c = (float) call_user_func( $a['detect_dir'], $dir );
			if ( $c > $best['confidence'] ) { $best = array( 'key' => $key, 'label' => $a['label'], 'confidence' => $c ); }
		}
		return $best['confidence'] >= self::MIN_CONFIDENCE ? $best : self::generic( $best['confidence'] );
	}

	/**
	 * Identify the best-matching source for a single pasted `code.html`.
	 *
	 * @param string $html
	 * @return array{ key:string, label:string, confidence:float }
	 */
	public static function identify_html( $html ) {
		$best = self::generic();
		foreach ( self::adapters() as $key => $a ) {
			if ( empty( $a['detect_html'] ) || ! is_callable( $a['detect_html'] ) ) { continue; }
			$c = (float) call_user_func( $a['detect_html'], $html, false );
			if ( $c > $best['confidence'] ) { $best = array( 'key' => $key, 'label' => $a['label'], 'confidence' => $c ); }
		}
		return $best['confidence'] >= self::MIN_CONFIDENCE ? $best : self::generic( $best['confidence'] );
	}

	/** The build callback for a source key (the generic fallback is the Stitch engine — it also parses plain HTML). */
	private static function builder_for( $key ) {
		$adapters = self::adapters();
		if ( isset( $adapters[ $key ]['build'] ) && is_callable( $adapters[ $key ]['build'] ) ) {
			return $adapters[ $key ]['build'];
		}
		return array( 'FW_Site_Converter_Stitch', 'build_bundle' );
	}

	/**
	 * Auto-detect + build the standard bundle from an unzipped export folder.
	 *
	 * @param string $dir
	 * @return array bundle (build_bundle() shape) with `source` => the identity.
	 */
	public static function build_from_dir( $dir, array $opts = array() ) {
		$id     = self::identify_dir( $dir );
		$bundle = call_user_func( self::builder_for( $id['key'] ), array_merge( array( 'folder' => $dir ), $opts ) );
		if ( is_array( $bundle ) ) { $bundle['source'] = $id; }
		return $bundle;
	}

	/**
	 * Auto-detect + build the standard bundle from a single pasted `code.html`.
	 *
	 * @param string $html
	 * @param string $title
	 * @return array bundle with `source` => the identity.
	 */
	public static function build_from_html( $html, $title = 'Home', array $opts = array() ) {
		$id     = self::identify_html( $html );
		$bundle = call_user_func( self::builder_for( $id['key'] ), array_merge( array( 'html' => $html, 'title' => $title ), $opts ) );
		if ( is_array( $bundle ) ) { $bundle['source'] = $id; }
		return $bundle;
	}
}
