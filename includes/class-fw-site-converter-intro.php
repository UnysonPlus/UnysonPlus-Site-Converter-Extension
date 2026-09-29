<?php if ( ! defined( 'FW' ) ) { die( 'Forbidden' ); }

/**
 * THE FIRST-RUN BRIEFING — what this tool does well, and what it will leave you to finish.
 *
 * The page already carried a beta notice, and it did nothing: it sat among four other notices on a screen
 * that opens with a wall of options, so the first-time user scrolled straight past their own warning,
 * converted a real site, opened the front end and was disappointed. A warning nobody reads has not warned
 * anybody.
 *
 * So this is a modal, shown ONCE per user, and it is deliberately specific rather than reassuring. "Results
 * vary by source" tells a reader nothing they can act on; "the content comes across reliably, the layout
 * usually needs a pass, run it somewhere you can throw away" sets an expectation they can actually hold.
 * Naming the weak spot up front costs a little enthusiasm and buys the difference between "this is rough"
 * and "this lied to me".
 *
 * Per USER, not per site: the person who needs the briefing is the one who has not seen it, and on a
 * multi-author site a second editor has not read what the first one dismissed.
 */
class FW_Site_Converter_Intro {

	/** Bump the suffix to re-show the briefing to everyone (a substantive change in what to expect). */
	const META = 'fw_sc_intro_seen_v1';

	/**
	 * Whether to brief this user.
	 *
	 * `FW_SITE_CONVERTER_DEV` suppresses it: the person developing the converter runs it many times a day
	 * and does not need to dismiss a briefing on a fresh profile each time. That constant is the honest
	 * place for "I am not the audience", rather than quietly never showing it to anyone.
	 */
	public static function should_show() {
		if ( defined( 'FW_SITE_CONVERTER_DEV' ) && FW_SITE_CONVERTER_DEV ) { return false; }
		$uid = get_current_user_id();
		return $uid && ! get_user_meta( $uid, self::META, true );
	}

	public static function mark_seen() {
		$uid = get_current_user_id();
		if ( $uid ) { update_user_meta( $uid, self::META, time() ); }
	}

	/** @internal ajax: the user acknowledged the briefing. */
	public static function _ajax_dismiss() {
		check_ajax_referer( 'fw_sc_intro' );
		if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( array(), 403 ); }
		self::mark_seen();
		wp_send_json_success();
	}

	/**
	 * Render the briefing. Prints nothing when the user has already seen it, so the caller can call it
	 * unconditionally.
	 */
	public static function render() {
		if ( ! self::should_show() ) { return; }
		$nonce = wp_create_nonce( 'fw_sc_intro' );
		?>
		<div class="fw-sc-intro" id="fw-sc-intro" role="dialog" aria-modal="true" aria-labelledby="fw-sc-intro-t">
			<div class="fw-sc-intro__box">
				<h2 id="fw-sc-intro-t"><?php esc_html_e( 'Before you convert a site', 'fw' ); ?></h2>

				<p class="fw-sc-intro__lede"><?php esc_html_e(
					'The Site Converter turns a site you already have into a real, editable WordPress site. It gets you most of the way in about a minute — and it will leave you some finishing to do. Here is what to expect, so nothing about the result is a surprise.',
					'fw'
				); ?></p>

				<ul class="fw-sc-intro__list">
					<li>
						<strong><?php esc_html_e( 'What comes across reliably', 'fw' ); ?></strong>
						<?php esc_html_e( 'Your text, images and page structure, the colour palette and fonts, and the header and footer as native Theme Settings — not as a frozen copy. Everything is editable in the builder afterwards.', 'fw' ); ?>
					</li>
					<li>
						<strong><?php esc_html_e( 'What usually needs a pass', 'fw' ); ?></strong>
						<?php esc_html_e( 'Layout and spacing are where conversions drift: a section may sit wider, tighter or in a different arrangement than the original. Expect to spend a little time on the sections after converting — the result is a starting point, not a finished site.', 'fw' ); ?>
					</li>
					<li>
						<strong><?php esc_html_e( 'Convert somewhere you can throw away', 'fw' ); ?></strong>
						<?php esc_html_e( 'A conversion replaces your pages and activates a new child theme. Run it on a staging or local site first, or on a site whose current contents you do not mind losing. Keep a backup either way.', 'fw' ); ?>
					</li>
					<li>
						<strong><?php esc_html_e( 'You will not be left to guess', 'fw' ); ?></strong>
						<?php esc_html_e( 'When it finishes, it grades its own work and lists what to check — with where to fix each one, and the option of handing the list to an AI agent to finish for you.', 'fw' ); ?>
					</li>
				</ul>

				<p class="fw-sc-intro__beta"><?php esc_html_e(
					'The Site Converter is in beta. Fidelity is actively being improved, and a rough edge is a bug worth reporting rather than something you are stuck with.',
					'fw'
				); ?></p>

				<p class="fw-sc-intro__actions">
					<button type="button" class="button button-primary button-hero" id="fw-sc-intro-ok"><?php esc_html_e( 'Got it — let’s convert', 'fw' ); ?></button>
				</p>
			</div>
		</div>
		<script>
		( function () {
			var box = document.getElementById( 'fw-sc-intro' );
			if ( ! box ) { return; }
			var btn = document.getElementById( 'fw-sc-intro-ok' );
			var prev = document.activeElement;
			document.body.style.overflow = 'hidden';
			btn.focus();
			// A modal that can be escaped only with the mouse traps keyboard users, and this one is
			// purely informational -- there is nothing to lose by leaving it.
			function close() {
				document.body.style.overflow = '';
				box.parentNode.removeChild( box );
				if ( prev && prev.focus ) { prev.focus(); }
				var fd = new FormData();
				fd.append( 'action', 'fw_sc_intro_dismiss' );
				fd.append( '_wpnonce', '<?php echo esc_js( $nonce ); ?>' );
				fetch( ajaxurl, { method: 'POST', credentials: 'same-origin', body: fd } ).catch( function () {} );
			}
			btn.addEventListener( 'click', close );
			document.addEventListener( 'keydown', function ( e ) { if ( e.key === 'Escape' && document.getElementById( 'fw-sc-intro' ) ) { close(); } } );
			// Keep focus inside while it is open.
			box.addEventListener( 'keydown', function ( e ) {
				if ( e.key !== 'Tab' ) { return; }
				e.preventDefault();
				btn.focus();
			} );
		} )();
		</script>
		<style>
		/* Colour comes from tokens so the light/dark matrix is expressed once, below, instead of every rule
		   being written twice. */
		.fw-sc-intro{
			--fwi-bg:#fff; --fwi-ink:#1d2327; --fwi-mut:#3c434a; --fwi-note-bg:#fcf9e8; --fwi-note-ink:#646970;
			position:fixed;inset:0;z-index:160001;background:rgba(15,16,18,.62);display:flex;align-items:center;justify-content:center;padding:24px
		}
		.fw-sc-intro__box{background:var(--fwi-bg);color:var(--fwi-ink);border-radius:8px;box-shadow:0 24px 64px rgba(0,0,0,.35);max-width:660px;width:100%;max-height:calc(100vh - 48px);overflow:auto;padding:28px 32px}
		.fw-sc-intro__box h2{margin:0 0 .4em;font-size:22px;line-height:1.25;color:var(--fwi-ink)}
		.fw-sc-intro__lede{font-size:14px;line-height:1.6;margin:0 0 1.1em;color:var(--fwi-mut)}
		.fw-sc-intro__list{margin:0 0 1.1em;padding:0;list-style:none}
		.fw-sc-intro__list li{margin:0 0 .85em;font-size:13px;line-height:1.6;color:var(--fwi-mut)}
		.fw-sc-intro__list strong{display:block;color:var(--fwi-ink);font-size:13px;margin-bottom:.15em}
		.fw-sc-intro__beta{font-size:12px;line-height:1.55;color:var(--fwi-note-ink);background:var(--fwi-note-bg);border-left:3px solid #dba617;border-radius:3px;padding:.7em .9em;margin:0 0 1.2em}
		.fw-sc-intro__actions{margin:0;text-align:right}

		/* THE MATRIX. The UnysonPlus Admin Skin owns the admin's light/dark state and publishes it as
		   html[data-upa-mode] = light | dark | system -- so keying only off prefers-color-scheme, as this
		   first did, produced a white dialog on a dark admin whenever the OS happened to be set to light.
		   An explicit "light" must also WIN over a dark OS, which is why the light case is stated rather
		   than left to the default. */
		:root[data-upa-mode="dark"] .fw-sc-intro,
		:root:not([data-upa-mode]) .fw-sc-intro{ color-scheme:dark }
		@media (prefers-color-scheme:dark){
			:root:not([data-upa-mode="light"]) .fw-sc-intro{
				--fwi-bg:#1e1f24; --fwi-ink:#e6e7e9; --fwi-mut:#b9bcc1; --fwi-note-bg:#2a2617; --fwi-note-ink:#c9ccd1;
			}
		}
		:root[data-upa-mode="dark"] .fw-sc-intro{
			--fwi-bg:#1e1f24; --fwi-ink:#e6e7e9; --fwi-mut:#b9bcc1; --fwi-note-bg:#2a2617; --fwi-note-ink:#c9ccd1;
		}
		:root[data-upa-mode="light"] .fw-sc-intro{
			--fwi-bg:#fff; --fwi-ink:#1d2327; --fwi-mut:#3c434a; --fwi-note-bg:#fcf9e8; --fwi-note-ink:#646970;
		}
		</style>
		<?php
	}
}
