<?php if ( ! defined( 'FW' ) ) { die( 'Forbidden' ); }

/**
 * OUTPUT TARGETS — the page builders a conversion can be written into.
 *
 * The converter is two halves joined by one seam:
 *
 *   analysis (Stitch: recognizers, sections, roles, measured styles)  →  the mapping / Site Model
 *   output   (a TARGET: turns that model into one builder's pages)     →  WordPress
 *
 * Everything site-level that is not a page — the design system written to Theme Settings, button / box
 * presets, the child theme, the header and footer, menus, media — is shared: every target runs on the
 * Unyson+ parent theme, which owns the chrome. A target owns only how a PAGE BODY is stored.
 *
 * Adding a builder = one subclass registered through the `fw_site_converter_targets` filter. Nothing in
 * the analysis half changes, which is the test that the seam is real.
 *
 * @see FW_SC_Site_Model  the builder-neutral input every non-native target reads
 * @see FW_SC_Targets     the registry (picker, request parsing, import routing)
 */
abstract class FW_SC_Target {

	/** Shipping: passes the same fixture + real-site checks as the native output. */
	const STATUS_AVAILABLE = 'available';
	/** Usable and selectable, but still being trained — say so in the picker. */
	const STATUS_PRE_ALPHA = 'pre-alpha';
	/** Selectable, deliberately unfinished (the block theme). */
	const STATUS_EXPERIMENTAL = 'experimental';
	/** Listed so the roadmap is visible; not selectable. */
	const STATUS_COMING_SOON = 'coming-soon';

	/** Stable id: the picker value, the REST `target`, the page meta. Never rename one. */
	abstract public function slug();

	/** Human label for the Output picker. */
	abstract public function label();

	/** One of the STATUS_* constants. */
	public function status() {
		return self::STATUS_COMING_SOON;
	}

	/** A short qualifier shown after the label, e.g. "child theme". */
	public function note() {
		return '';
	}

	/** Tooltip text for the picker row. */
	public function description() {
		return '';
	}

	/** Can a user pick this target at all (ignoring what this site has installed)? */
	public function is_selectable() {
		return in_array( $this->status(), array( self::STATUS_AVAILABLE, self::STATUS_PRE_ALPHA, self::STATUS_EXPERIMENTAL ), true );
	}

	/**
	 * What this site is missing before the target can run — plugins, versions, editor mode.
	 * Each row: array( 'code' => string, 'message' => string ). Empty = ready.
	 * The Unyson+ parent theme is NOT listed here: the shared preflight installs it for every target.
	 *
	 * @return array[]
	 */
	public function unmet_requirements() {
		return array();
	}

	/** Does this target render inside the Unyson+ parent theme (header, footer, Theme Settings)? */
	public function needs_parent_theme() {
		return true;
	}

	/**
	 * Write the converted page bodies.
	 *
	 * @param array $mapping the analysis output — { pages: [ { title, slug, front_page, sections: [ … ] } ] }.
	 *                       Non-native targets read it through FW_SC_Site_Model::from_mapping().
	 * @param array $built   the Unyson+ page-builder pages built from the same mapping (pages.json shape).
	 *                       Only the native target needs it; others may ignore it.
	 * @param array $ctx     run options: media map, replace approvals, source URL …
	 * @return array same shape as FW_Site_Converter_Pages::import(): { pages: [ { id, title, slug, … } ], errors: [] }
	 */
	abstract public function import_pages( array $mapping, array $built, array $ctx = array() );

	/**
	 * Called once per conversion after the site-level design (Theme Settings, presets, child theme) has been
	 * imported — the place a target mirrors that design into its builder's own global settings.
	 *
	 * @param array $ctx run options
	 * @return array a small report merged into the import result under `target_design`
	 */
	public function after_design_import( array $ctx = array() ) {
		return array();
	}

	/**
	 * How the render-level verifier finds this target's sections on a built page (verify.mjs bands).
	 *
	 * @return array { section: CSS selector }
	 */
	public function verify_profile() {
		return array( 'section' => 'section' );
	}

	/** Picker row data (also returned by the REST/AJAX requirement check). */
	public function describe() {
		return array(
			'slug'        => $this->slug(),
			'label'       => $this->label(),
			'status'      => $this->status(),
			'note'        => $this->note(),
			'description' => $this->description(),
			'selectable'  => $this->is_selectable(),
			'unmet'       => $this->is_selectable() ? $this->unmet_requirements() : array(),
		);
	}
}

/**
 * A roadmap entry: listed in the picker so the direction is visible, never selectable. Becomes a real
 * target by registering a subclass under the same slug (a registered class replaces the placeholder).
 */
class FW_SC_Target_Placeholder extends FW_SC_Target {
	private $slug;
	private $label;

	public function __construct( $slug, $label ) {
		$this->slug  = (string) $slug;
		$this->label = (string) $label;
	}
	public function slug() { return $this->slug; }
	public function label() { return $this->label; }
	public function description() {
		/* translators: %s: a page builder name */
		return sprintf( __( '%s output is on the roadmap — not available yet.', 'fw' ), $this->label );
	}
	public function import_pages( array $mapping, array $built, array $ctx = array() ) {
		return array( 'pages' => array(), 'errors' => array( sprintf( 'Target "%s" is not available yet.', $this->slug ) ) );
	}
}

/**
 * The target registry.
 */
final class FW_SC_Targets {

	/** The native output; also what an unknown / unavailable request falls back to. */
	const DEFAULT_SLUG = 'page-builder';

	/** @var FW_SC_Target[]|null slug => target, in picker order */
	private static $targets = null;

	/**
	 * Every registered target, keyed by slug, in picker order.
	 *
	 * Third parties add or replace targets with:
	 *   add_filter( 'fw_site_converter_targets', function ( $t ) { $t['my-builder'] = new My_Target(); return $t; } );
	 *
	 * @return FW_SC_Target[]
	 */
	public static function all() {
		if ( null !== self::$targets ) { return self::$targets; }

		$t = array(
			'page-builder' => new FW_SC_Target_Unysonplus(),
			'block-theme'  => new FW_SC_Target_Block_Theme(),
			'elementor'    => new FW_SC_Target_Elementor(),
		);
		// The roadmap — each becomes real by registering a class under its slug.
		$roadmap = array(
			'divi'           => 'Divi',
			'bricks'         => 'Bricks',
			'beaver-builder' => 'Beaver Builder',
			'wpbakery'       => 'WPBakery',
			'oxygen'         => 'Oxygen',
			'breakdance'     => 'Breakdance',
			'kadence-blocks' => 'Kadence Blocks',
			'generateblocks' => 'GenerateBlocks',
			'spectra'        => 'Spectra',
		);
		foreach ( $roadmap as $slug => $label ) { $t[ $slug ] = new FW_SC_Target_Placeholder( $slug, $label ); }

		$t = apply_filters( 'fw_site_converter_targets', $t );

		$clean = array();
		foreach ( (array) $t as $slug => $obj ) {
			if ( $obj instanceof FW_SC_Target ) { $clean[ $obj->slug() ] = $obj; }
		}
		self::$targets = $clean;
		return $clean;
	}

	/** @return FW_SC_Target|null */
	public static function get( $slug ) {
		$all = self::all();
		$slug = sanitize_key( (string) $slug );
		return isset( $all[ $slug ] ) ? $all[ $slug ] : null;
	}

	/**
	 * The target a request asked for, or the default when the slug is unknown or not selectable. A roadmap
	 * slug therefore converts to the native output, exactly as before targets existed.
	 *
	 * @return FW_SC_Target
	 */
	public static function resolve( $slug ) {
		$t = self::get( $slug );
		if ( $t && $t->is_selectable() ) { return $t; }
		return self::get( self::DEFAULT_SLUG );
	}

	/** Forget the registry (tests; a plugin that registers late). */
	public static function reset() {
		self::$targets = null;
	}
}
