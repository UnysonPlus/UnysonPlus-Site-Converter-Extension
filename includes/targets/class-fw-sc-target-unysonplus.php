<?php if ( ! defined( 'FW' ) ) { die( 'Forbidden' ); }

/**
 * The native output: Unyson+ page-builder pages in a child theme of the Unyson+ parent.
 *
 * A thin wrapper on purpose. The Mapper already turned the mapping into page-builder trees (pages.json)
 * before the import starts, and FW_Site_Converter_Pages writes them — this class only routes to that, so
 * the native output stays byte-identical while it moves behind the target seam.
 */
class FW_SC_Target_Unysonplus extends FW_SC_Target {

	public function slug() { return 'page-builder'; }

	public function label() { return __( 'Unyson+ Page Builder', 'fw' ); }

	public function status() { return self::STATUS_AVAILABLE; }

	public function note() { return __( 'child theme', 'fw' ); }

	public function description() {
		return __( 'A child theme of the UnysonPlus parent theme, with the body as editable page-builder sections and the full framework — Theme Settings, presets, shortcodes.', 'fw' );
	}

	public function verify_profile() {
		return array( 'section' => '.fw-section' );
	}

	/**
	 * @param array $mapping unused — the native pages are already built
	 * @param array $built   the pages.json document: { pages: [ … ] }
	 */
	public function import_pages( array $mapping, array $built, array $ctx = array() ) {
		if ( ! class_exists( 'FW_Site_Converter_Pages' ) ) {
			return array( 'pages' => array(), 'errors' => array( 'The page importer is not loaded.' ) );
		}
		return FW_Site_Converter_Pages::import( $built );
	}
}

/**
 * The standalone block theme (FSE). Its whole pipeline is the capture service's JS emitter plus
 * FW_Site_Converter_Blocks::install_block_theme(), which import_dir() runs before any page phase — so
 * this class exists for the picker and for routing, not for writing pages.
 */
class FW_SC_Target_Block_Theme extends FW_SC_Target {

	public function slug() { return 'block-theme'; }

	public function label() { return __( 'Block Theme', 'fw' ); }

	public function status() { return self::STATUS_EXPERIMENTAL; }

	public function note() { return __( 'standalone FSE, no plugin', 'fw' ); }

	public function needs_parent_theme() { return false; }

	public function description() {
		return __( 'Generate a standalone WordPress block theme (FSE): theme.json, editable header/footer parts, templates and section patterns, in core blocks — it renders with no plugin dependency. Requires the local capture service, and converts from a URL.', 'fw' );
	}

	public function unmet_requirements() {
		$out = array();
		if ( function_exists( 'fw_ext' ) && fw_ext( 'page-builder' ) ) {
			$out[] = array( 'code' => 'page_builder_active', 'message' => __( 'Block Theme is a standalone, plugin-free output — deactivate the Page Builder extension (Unyson+ → Extensions) to use it.', 'fw' ) );
		} elseif ( class_exists( 'Classic_Editor' ) || 'classic' === get_option( 'classic-editor-replace' ) ) {
			$out[] = array( 'code' => 'classic_editor', 'message' => __( 'Block Theme needs the block editor — it is disabled while the Classic Editor is enforced.', 'fw' ) );
		}
		return $out;
	}

	public function verify_profile() {
		return array( 'section' => '.wp-block-group.alignfull, section' );
	}

	public function import_pages( array $mapping, array $built, array $ctx = array() ) {
		return array( 'pages' => array(), 'errors' => array( 'The block theme installs from block-bundle.json, not from page trees.' ) );
	}
}
