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
		 * Filters the Site Converter's source adapters, letting other extensions register support for new site builders/exports.
		 *
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

	/**
	 * Does the source HTML read as a WooCommerce / e-commerce STORE? Deterministic signal scan — used to
	 * (a) auto-tick the Convert panel's "Map to WooCommerce" option and (b) gate whether the mapper emits
	 * WooCommerce shortcodes (`[wc_products]`, etc.) for product grids instead of static image cards. A
	 * couple of STRONG signals, or several weaker ones, qualify — so a lone "cart" word doesn't false-positive.
	 *
	 * @param string $html
	 * @return bool
	 */
	public static function is_woocommerce_source( $html ) {
		$html = (string) $html;
		if ( $html === '' ) { return false; }
		$score = 0;
		// STRONG signals (each is near-conclusive of a real store).
		$strong = array(
			'/class="[^"]*\bwoocommerce\b/i',                       // the WooCommerce body/wrapper class
			'/\bwoocommerce-Price-amount\b/i',                      // a WC price element
			'/\b(?:ajax_)?add_to_cart_button\b/i',                  // WC add-to-cart button class
			'/\bwp-block-woocommerce\b|\bwc-block-/i',              // WC Blocks
			'/\bdata-product_id\s*=/i',                             // add-to-cart data attr
			'/"@type"\s*:\s*"Product"/i',                           // Product JSON-LD
			'/name="add-to-cart"|\?add-to-cart=/i',                 // classic add-to-cart form/link
		);
		foreach ( $strong as $re ) { if ( preg_match( $re, $html ) ) { $score += 2; } }
		// WEAKER signals (store-shaped URLs / words — corroborating, not conclusive on their own).
		$weak = array(
			'~href="[^"]*/product/[^"]*"~i',                        // a product permalink
			'~href="[^"]*/(?:cart|checkout|my-account)/?[^"]*"~i',  // store pages
			'/\badd to cart\b/i',
			'/\bproduct[_-]cat(?:egory)?\b/i',
		);
		foreach ( $weak as $re ) { if ( preg_match( $re, $html ) ) { $score += 1; } }
		// One strong signal (2) or two+ corroborating weak ones qualify.
		return $score >= 2;
	}
}
