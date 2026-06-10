<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Site Converter extension.
 *
 * The admin home for the AI-site → WordPress importer (roadmap #2). It ingests
 * the artifacts an agent produces — per the conversion contract — and applies
 * them to the site. This first slice ships the **Media** tool: fetch the source
 * site's images into the WordPress media library (de-duped), either from a pasted
 * list of URLs or by scanning a source page. Later slices add the presets
 * importer, menu importer, and a one-shot "Convert bundle" that orchestrates all
 * phases (media → presets → theme settings → pages → menus).
 *
 * Admin page lives under the Unyson+ menu (Unyson+ → Convert). The reusable media
 * logic is in includes/class-fw-site-converter-media.php so a bundle importer /
 * WP-CLI can reuse it.
 */
class FW_Extension_Site_Converter extends FW_Extension {

	const PARENT_SLUG = 'fw-extensions';
	const PAGE_SLUG   = 'fw-site-converter';
	const CAPABILITY  = 'manage_options';
	const NONCE       = 'fw_ext_site_converter_run';

	/** @var string|null Hook suffix from add_submenu_page(). */
	private $hook_suffix = null;

	/**
	 * @internal
	 */
	public function _init() {
		require_once $this->get_declared_path( '/includes/class-fw-site-converter-media.php' );

		if ( is_admin() ) {
			add_action( 'admin_menu', array( $this, '_action_admin_menu' ), 30 );
			// Slot our page into the shared Unyson+ submenu order (the Post Types
			// extension owns the sort; we just declare where we want to sit).
			add_filter( 'fw_unysonplus_admin_submenu_order', array( $this, '_filter_submenu_order' ) );
		}
	}

	/**
	 * @internal
	 * @param string[] $order
	 * @return string[]
	 */
	public function _filter_submenu_order( $order ) {
		if ( is_array( $order ) && ! in_array( self::PAGE_SLUG, $order, true ) ) {
			$order[] = self::PAGE_SLUG; // append after the existing pages
		}
		return $order;
	}

	public static function get_page_url() {
		return admin_url( 'admin.php?page=' . self::PAGE_SLUG );
	}

	/**
	 * @internal
	 */
	public function _action_admin_menu() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$this->hook_suffix = add_submenu_page(
			self::PARENT_SLUG,
			__( 'Convert — AI Site Importer', 'fw' ),
			__( 'Convert', 'fw' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);

		if ( $this->hook_suffix ) {
			add_action( 'load-' . $this->hook_suffix, array( $this, '_maybe_run' ) );
		}
	}

	/* ---------------------------------------------------------------------- *
	 * Run handler (load- hook, before output → PRG redirect)
	 * ---------------------------------------------------------------------- */

	private function results_transient_key() {
		return 'fw_site_converter_media_' . get_current_user_id();
	}

	/**
	 * @internal
	 */
	public function _maybe_run() {
		if ( 'POST' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : '' ) ) {
			return;
		}
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		check_admin_referer( self::NONCE );

		$mode    = isset( $_POST['fw_sc_mode'] ) ? sanitize_key( wp_unslash( $_POST['fw_sc_mode'] ) ) : 'urls';
		$post_id = isset( $_POST['fw_sc_attach_post'] ) ? absint( $_POST['fw_sc_attach_post'] ) : 0;

		$urls   = array();
		$source = '';

		if ( $mode === 'scan' ) {
			$source = isset( $_POST['fw_sc_page_url'] ) ? esc_url_raw( trim( wp_unslash( $_POST['fw_sc_page_url'] ) ) ) : '';
			if ( $source !== '' ) {
				$resp = wp_remote_get( $source, array(
					'timeout'    => 20,
					'user-agent' => 'UnysonPlus-SiteConverter/1.0; ' . home_url( '/' ),
				) );
				if ( ! is_wp_error( $resp ) && (int) wp_remote_retrieve_response_code( $resp ) < 400 ) {
					$urls = FW_Site_Converter_Media::scan_html( (string) wp_remote_retrieve_body( $resp ), $source );
				} else {
					$msg = is_wp_error( $resp ) ? $resp->get_error_message() : __( 'HTTP error fetching the page.', 'fw' );
					set_transient( $this->results_transient_key(), array( 'error' => $msg ), 5 * MINUTE_IN_SECONDS );
					$this->redirect_back();
				}
			}
		} else {
			$raw  = isset( $_POST['fw_sc_urls'] ) ? wp_unslash( $_POST['fw_sc_urls'] ) : '';
			$urls = preg_split( '/\r\n|\r|\n/', (string) $raw );
		}

		$urls    = array_values( array_filter( array_map( 'trim', (array) $urls ) ) );
		$results = $urls ? FW_Site_Converter_Media::import_urls( $urls, $post_id ) : array();

		set_transient( $this->results_transient_key(), array(
			'mode'    => $mode,
			'source'  => $source,
			'results' => $results,
		), 5 * MINUTE_IN_SECONDS );

		$this->redirect_back();
	}

	private function redirect_back() {
		wp_safe_redirect( add_query_arg(
			array( 'page' => self::PAGE_SLUG, 'fw-sc-done' => '1' ),
			admin_url( 'admin.php' )
		) );
		exit;
	}

	/* ---------------------------------------------------------------------- *
	 * Render
	 * ---------------------------------------------------------------------- */

	/**
	 * @internal
	 */
	public function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$data = false;
		if ( isset( $_GET['fw-sc-done'] ) ) {
			$data = get_transient( $this->results_transient_key() );
			delete_transient( $this->results_transient_key() );
		}
		?>
		<div class="wrap fw-ext-site-converter">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Convert — AI Site Importer', 'fw' ); ?></h1>
			<p class="description" style="max-width:54em">
				<?php esc_html_e( 'Bring an AI-generated site into WordPress. This tool fetches the source site\'s images into your Media Library (de-duped by source URL, so re-running is safe). Presets, menus and a one-shot bundle import are coming next.', 'fw' ); ?>
			</p>

			<?php if ( is_array( $data ) && isset( $data['error'] ) ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php echo esc_html( $data['error'] ); ?></p></div>
			<?php elseif ( is_array( $data ) && isset( $data['results'] ) ) : ?>
				<?php $this->render_results( $data ); ?>
			<?php endif; ?>

			<h2 style="margin-top:1.5em"><?php esc_html_e( 'Fetch images', 'fw' ); ?></h2>
			<form method="post" action="">
				<?php wp_nonce_field( self::NONCE ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Source', 'fw' ); ?></th>
						<td>
							<label style="display:block;margin-bottom:.4em">
								<input type="radio" name="fw_sc_mode" value="scan" checked>
								<?php esc_html_e( 'Scan a page URL for images', 'fw' ); ?>
							</label>
							<input type="url" name="fw_sc_page_url" class="regular-text" placeholder="https://example.netlify.app/" style="width:32em;max-width:100%">
							<p class="description"><?php esc_html_e( 'Fetches the page and collects every <img>, srcset, and CSS url() reference.', 'fw' ); ?></p>

							<label style="display:block;margin:1em 0 .4em">
								<input type="radio" name="fw_sc_mode" value="urls">
								<?php esc_html_e( 'Or paste image URLs (one per line)', 'fw' ); ?>
							</label>
							<textarea name="fw_sc_urls" rows="6" class="large-text code" placeholder="https://example.com/hero.jpg&#10;https://example.com/logo.png"></textarea>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Attach to post', 'fw' ); ?></th>
						<td>
							<input type="number" name="fw_sc_attach_post" class="small-text" min="0" value="0">
							<p class="description"><?php esc_html_e( 'Optional post/page ID to attach the media to. 0 = unattached (in the Library only).', 'fw' ); ?></p>
						</td>
					</tr>
				</table>
				<p class="submit">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Fetch into Media Library', 'fw' ); ?></button>
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * Render the results table from a completed run.
	 *
	 * @param array $data
	 */
	private function render_results( array $data ) {
		$results = isset( $data['results'] ) && is_array( $data['results'] ) ? $data['results'] : array();
		$ok      = 0;
		$reused  = 0;
		$failed  = 0;
		foreach ( $results as $r ) {
			if ( ! empty( $r['ok'] ) ) {
				$ok++;
				if ( ! empty( $r['reused'] ) ) {
					$reused++;
				}
			} else {
				$failed++;
			}
		}

		$summary = sprintf(
			/* translators: 1: imported count, 2: reused count, 3: failed count */
			__( 'Imported %1$d image(s) — %2$d reused (already in library), %3$d failed.', 'fw' ),
			$ok, $reused, $failed
		);
		if ( ! empty( $data['source'] ) ) {
			$summary .= ' ' . sprintf( __( 'Source: %s', 'fw' ), esc_url( $data['source'] ) );
		}
		?>
		<div class="notice <?php echo $failed ? 'notice-warning' : 'notice-success'; ?> is-dismissible">
			<p><?php echo esc_html( $summary ); ?></p>
		</div>

		<?php if ( $results ) : ?>
			<table class="widefat striped" style="margin-bottom:1.5em">
				<thead><tr>
					<th><?php esc_html_e( 'Source URL', 'fw' ); ?></th>
					<th style="width:90px"><?php esc_html_e( 'Status', 'fw' ); ?></th>
					<th style="width:80px"><?php esc_html_e( 'Media', 'fw' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $results as $r ) : ?>
					<tr>
						<td><code style="word-break:break-all"><?php echo esc_html( $r['source'] ); ?></code></td>
						<td>
							<?php if ( ! empty( $r['ok'] ) ) : ?>
								<span style="color:#1a7f37">&#10003; <?php echo ! empty( $r['reused'] ) ? esc_html__( 'reused', 'fw' ) : esc_html__( 'imported', 'fw' ); ?></span>
							<?php else : ?>
								<span style="color:#b32d2e" title="<?php echo esc_attr( isset( $r['message'] ) ? $r['message'] : '' ); ?>">&#10007; <?php esc_html_e( 'failed', 'fw' ); ?></span>
							<?php endif; ?>
						</td>
						<td>
							<?php if ( ! empty( $r['id'] ) ) : ?>
								<a href="<?php echo esc_url( get_edit_post_link( $r['id'] ) ); ?>">#<?php echo (int) $r['id']; ?></a>
							<?php else : ?>
								&mdash;
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
	}
}
