<?php if ( ! defined( 'FW' ) ) { die( 'Forbidden' ); }

/**
 * THE CONVERSION SANDBOX — a site's own corrections to its own conversion, kept where nothing deletes them.
 *
 * A conversion that is 95% right leaves a handful of things only the site owner can judge. Until now their
 * only options were to hand-edit the result (lost on the next reconversion) or patch the converter (lost on
 * the next plugin update, and wrong for every other site). The three extension points added alongside this
 * file give them a third way; this is where those corrections LIVE, with three properties that make them
 * worth writing:
 *
 *   1. They SURVIVE. Not in the plugin (an update replaces it) and deliberately not in
 *      `framework-customizations/` either, because that sits inside the theme and a new conversion DELETES
 *      the previous conversion's generated child theme (cleanup_previous_conversion()). A sandbox stored
 *      there would be destroyed by the next conversion — exactly when the corrections matter most. So the
 *      sandbox lives in `wp-content/unysonplus-sandbox/`, which neither a plugin update nor a theme swap
 *      touches.
 *   2. They RETIRE THEMSELVES. Each entry may carry a `probe` that answers one question: is the defect I
 *      exist for still present? When a converter update makes the answer "no", the entry stops applying and
 *      says so. Without this, a site accumulates corrections that fight fixes it has already received —
 *      a hook that "fixes" an already-correct value is now the bug.
 *   3. They are REPORTABLE. Every entry carries the source fragment and what was expected, which is exactly
 *      the shape a maintainer can turn into a permanent converter fix. report() renders the set as text to
 *      share upstream.
 *
 * An entry is one PHP file in `entries/` returning an array:
 *
 *   return array(
 *       'id'       => 'price-list-rows',                  // stable, unique, used for retirement tracking
 *       'summary'  => 'A price LIST converted as a card grid.',
 *       'fragment' => '<div class="flex justify-between">…</div>',  // the source shape (for the report)
 *       'expected' => 'Full-width rows, price right.',    // what the converter should have produced
 *       'probe'    => function () { return true; },       // true = defect still present; omit if unknown
 *       'apply'    => function () { add_filter( … ); },   // register the hooks that correct it
 *   );
 *
 * Nothing here can break the site or a conversion: every entry file, probe and apply runs inside its own
 * try/catch, and a failing entry is disabled and reported rather than fatal.
 */
class FW_Site_Converter_Sandbox {

	/** Retirement ledger: id => array( at, converter_version ). */
	const OPT_RETIRED = 'fw_sc_sandbox_retired';
	/** The converter version the probes were last run against, so a bump re-probes exactly once. */
	const OPT_PROBED  = 'fw_sc_sandbox_probed_version';

	/** @var array|null lazily loaded entries, keyed by id */
	private static $entries = null;
	/** @var array id => human-readable load/apply error */
	private static $errors = array();
	/** @var bool */
	private static $booted = false;

	/* ------------------------------------------------------------------ location */

	/**
	 * The sandbox directory (no trailing slash). Not created as a side effect of asking — a read-only
	 * caller must not litter wp-content just by rendering an admin panel.
	 */
	public static function dir() {
		/**
		 * Relocate the sandbox (a multisite that wants one per site, a read-only deployment, a test).
		 *
		 * @since 1.10.13
		 * @param string $dir absolute path, no trailing slash.
		 */
		return (string) apply_filters( 'fw_site_converter_sandbox_dir', WP_CONTENT_DIR . '/unysonplus-sandbox' );
	}

	public static function exists() {
		return is_dir( self::dir() . '/entries' );
	}

	/**
	 * Create the sandbox with its README and an inert example entry. Returns true when the directory is
	 * usable afterwards (already existing counts).
	 */
	public static function scaffold() {
		$dir = self::dir();
		if ( ! wp_mkdir_p( $dir . '/entries' ) ) { return false; }

		// An .htaccess + index.php so the folder can't be browsed or its files requested directly on
		// Apache. These files are INCLUDED by PHP, never served, so denying web access costs nothing.
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			@file_put_contents( $dir . '/.htaccess', "<FilesMatch \".*\">\n\tRequire all denied\n</FilesMatch>\n" );
		}
		if ( ! file_exists( $dir . '/index.php' ) ) {
			@file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" );
		}
		if ( ! file_exists( $dir . '/README.md' ) ) {
			@file_put_contents( $dir . '/README.md', self::readme() );
		}
		if ( ! file_exists( $dir . '/entries/example.php' ) ) {
			@file_put_contents( $dir . '/entries/example.php', self::example() );
		}
		return self::exists();
	}

	/* ------------------------------------------------------------------ loading */

	/**
	 * Load and validate every entry. A file that throws, returns the wrong shape, or reuses an id is
	 * recorded in errors() and skipped — one bad entry must not take the others (or the site) down.
	 */
	public static function entries() {
		if ( null !== self::$entries ) { return self::$entries; }
		self::$entries = array();
		self::$errors  = array();

		$files = self::exists() ? glob( self::dir() . '/entries/*.php' ) : array();
		foreach ( (array) $files as $file ) {
			$name = basename( $file );
			try {
				$e = include $file;
			} catch ( Throwable $t ) {
				self::$errors[ $name ] = 'threw on load: ' . $t->getMessage();
				continue;
			}
			if ( ! is_array( $e ) ) {
				self::$errors[ $name ] = 'did not return an array';
				continue;
			}
			$id = isset( $e['id'] ) ? sanitize_key( (string) $e['id'] ) : '';
			if ( '' === $id ) {
				self::$errors[ $name ] = 'has no id';
				continue;
			}
			if ( isset( self::$entries[ $id ] ) ) {
				self::$errors[ $name ] = sprintf( 'duplicate id "%s" (already loaded from %s)', $id, self::$entries[ $id ]['file'] );
				continue;
			}
			if ( empty( $e['apply'] ) || ! is_callable( $e['apply'] ) ) {
				self::$errors[ $name ] = 'has no callable apply()';
				continue;
			}
			self::$entries[ $id ] = array(
				'id'       => $id,
				'file'     => $name,
				'summary'  => isset( $e['summary'] ) ? (string) $e['summary'] : '',
				'fragment' => isset( $e['fragment'] ) ? (string) $e['fragment'] : '',
				'expected' => isset( $e['expected'] ) ? (string) $e['expected'] : '',
				'probe'    => ( isset( $e['probe'] ) && is_callable( $e['probe'] ) ) ? $e['probe'] : null,
				'apply'    => $e['apply'],
			);
		}
		ksort( self::$entries );
		return self::$entries;
	}

	/** Load/validation problems, as file => reason. */
	public static function errors() {
		self::entries();
		return self::$errors;
	}

	/** The retirement ledger. */
	public static function retired() {
		$v = get_option( self::OPT_RETIRED, array() );
		return is_array( $v ) ? $v : array();
	}

	public static function is_retired( $id ) {
		return isset( self::retired()[ $id ] );
	}

	/* ------------------------------------------------------------------ running */

	/**
	 * Apply every active entry. Idempotent: a second call does nothing, so being wired from _init() and
	 * called again before a conversion cannot double-register a filter.
	 */
	public static function boot() {
		if ( self::$booted ) { return; }
		self::$booted = true;

		$retired = self::retired();
		foreach ( self::entries() as $id => $e ) {
			if ( isset( $retired[ $id ] ) ) { continue; }
			try {
				call_user_func( $e['apply'] );
			} catch ( Throwable $t ) {
				// A correction that throws is worse than no correction: record it and carry on unmodified.
				self::$errors[ $e['file'] ] = 'apply() threw: ' . $t->getMessage();
			}
		}
	}

	/**
	 * Run every probe and retire the entries whose defect is gone.
	 *
	 * A probe returns TRUE while the defect is still present. FALSE means the converter now handles it, so
	 * the entry is retired: it stops applying and is reported as safe to delete. An entry with no probe is
	 * left alone — "I don't know" must not read as "still needed" or as "safe to drop".
	 *
	 * @return array array( retired => id[], kept => id[], unknown => id[], failed => id[] )
	 */
	public static function probe_all() {
		$out     = array( 'retired' => array(), 'kept' => array(), 'unknown' => array(), 'failed' => array() );
		$retired = self::retired();
		$version = self::converter_version();

		foreach ( self::entries() as $id => $e ) {
			if ( isset( $retired[ $id ] ) ) { continue; }
			if ( null === $e['probe'] ) { $out['unknown'][] = $id; continue; }
			try {
				$still = (bool) call_user_func( $e['probe'] );
			} catch ( Throwable $t ) {
				// An inconclusive probe must NOT retire an entry — a correction removed in error silently
				// un-fixes the site, which is far worse than one kept a version too long.
				self::$errors[ $e['file'] ] = 'probe() threw: ' . $t->getMessage();
				$out['failed'][] = $id;
				continue;
			}
			if ( $still ) {
				$out['kept'][] = $id;
			} else {
				$retired[ $id ] = array( 'at' => time(), 'converter_version' => $version );
				$out['retired'][] = $id;
			}
		}

		update_option( self::OPT_RETIRED, $retired, false );
		update_option( self::OPT_PROBED, $version, false );
		return $out;
	}

	/**
	 * Re-probe once per converter version. Called on admin_init: the trigger for "has upstream fixed this
	 * yet?" is a converter update, so probing on every request would be waste and probing never would
	 * leave the sandbox stale forever.
	 */
	public static function maybe_probe() {
		if ( ! self::exists() ) { return null; }
		$version = self::converter_version();
		if ( (string) get_option( self::OPT_PROBED, '' ) === $version ) { return null; }
		return self::probe_all();
	}

	/** Un-retire an entry (the probe was wrong, or the regression came back). */
	public static function revive( $id ) {
		$retired = self::retired();
		if ( ! isset( $retired[ $id ] ) ) { return false; }
		unset( $retired[ $id ] );
		update_option( self::OPT_RETIRED, $retired, false );
		return true;
	}

	/* ------------------------------------------------------------------ reporting */

	/**
	 * The shareable report: every entry as a reproducible case (fragment + what was expected), which is
	 * what a maintainer can turn into a permanent fix. Retired entries are included and marked, because
	 * "this one is now fixed upstream" is useful information about the set, not noise.
	 */
	public static function report() {
		$entries = self::entries();
		if ( ! $entries ) { return ''; }

		$retired = self::retired();
		$out     = array();
		$out[]   = 'UnysonPlus Site Converter — sandbox corrections';
		$out[]   = 'Converter version: ' . self::converter_version();
		$out[]   = 'Entries: ' . count( $entries ) . ' (' . count( array_intersect_key( $retired, $entries ) ) . ' retired)';
		$out[]   = '';
		$out[]   = 'Each entry below is a shape this converter did not map as expected on a real source.';
		$out[]   = 'The fragment plus the expected result is a reproducible case, not a patch.';
		$out[]   = '';

		foreach ( $entries as $id => $e ) {
			$out[] = str_repeat( '-', 78 );
			$out[] = '[' . ( isset( $retired[ $id ] ) ? 'RETIRED — now handled upstream' : 'ACTIVE' ) . '] ' . $id;
			if ( '' !== $e['summary'] ) { $out[] = 'What went wrong : ' . $e['summary']; }
			if ( '' !== $e['expected'] ) { $out[] = 'Expected        : ' . $e['expected']; }
			if ( '' !== $e['fragment'] ) {
				$out[] = 'Source fragment :';
				$out[] = self::indent( $e['fragment'] );
			}
			$out[] = '';
		}

		$out[] = str_repeat( '-', 78 );
		$out[] = 'Shared so the next conversion of a source like this needs no manual pass.';
		$out[] = 'Contains only what the entries declare — check it for anything you would not publish';
		$out[] = '(client names, private URLs) before sending it.';
		return implode( "\n", $out ) . "\n";
	}

	/* ------------------------------------------------------------------ internals */

	private static function indent( $text ) {
		$text = trim( (string) $text );
		if ( strlen( $text ) > 2000 ) { $text = substr( $text, 0, 2000 ) . "\n… (truncated)"; }
		$lines = preg_split( '/\r\n|\r|\n/', $text );
		foreach ( $lines as &$l ) { $l = '    ' . $l; }
		return implode( "\n", $lines );
	}

	/**
	 * The converter extension's version, which is what a probe result is valid for.
	 *
	 * `manifest` is a PROPERTY on FW_Extension, not a method — an earlier guard here used
	 * method_exists( $ext, 'manifest' ), which is always false, so this silently returned 'unknown' every
	 * time. That is a quiet but total failure: maybe_probe() compares the stored version against this one,
	 * so a constant 'unknown' means the probes run exactly once and then NEVER again, and every entry
	 * outlives its defect forever. Guard the property, and the method on the object it holds.
	 */
	public static function converter_version() {
		if ( function_exists( 'fw_ext' ) ) {
			$ext = fw_ext( 'site-converter' );
			if ( $ext && isset( $ext->manifest ) && method_exists( $ext->manifest, 'get_version' ) ) {
				$v = $ext->manifest->get_version();
				if ( $v ) { return (string) $v; }
			}
		}
		return 'unknown';
	}

	private static function readme() {
		return <<<'MD'
# UnysonPlus conversion sandbox

Your corrections to YOUR conversion. Nothing here ships with the plugin, and nothing here is overwritten
by a plugin update, a theme change, or a reconversion — which is why it is here rather than in the plugin
or in the theme (a new conversion deletes the previous conversion's generated theme).

## Why not just edit the page, or the converter?

- **Editing the converted page** is undone the next time you reconvert.
- **Editing the converter** is undone the next time the plugin updates, and a fix tuned to your one source
  is wrong for every other site that plugin touches.
- An entry here survives both, and applies every time you reconvert.

## Adding an entry

One file in `entries/`, returning an array. `id` and `apply` are required; the rest make the entry
self-explaining and shareable.

```php
<?php
return array(
    'id'       => 'price-list-rows',
    'summary'  => 'A price LIST (name left, price right) converted as a 4-across card grid.',
    'fragment' => '<div class="flex justify-between border-b py-4">…</div>',
    'expected' => 'Full-width rows, price on the right, rule between.',

    // TRUE while the defect is still present. Return FALSE once the converter handles it and this
    // entry retires itself. Omit it if you cannot test cheaply — an absent probe is left alone.
    'probe'    => function () {
        return true;
    },

    // Register the correction. Runs once per request, before any conversion.
    'apply'    => function () {
        add_filter( 'fw_site_converter_theme_settings', function ( $values ) {
            // $values['some_option'] = 'the value the converter could not measure';
            return $values;
        } );
    },
);
```

### The three hooks available to `apply`

| Hook | Use it to |
|---|---|
| action `fw_site_converter_recognizers` | then `FW_Site_Converter_Stitch::register_recognizer( $id, $priority, $match, $build )` — claim a DOM element and emit your own block. Priority 100+ outranks every built-in; re-use a built-in's id to replace it. |
| filter `fw_site_converter_block_nodes` ( null, $block, $css_id ) | return builder nodes to claim a block, `array()` to drop it, `null` to leave it to the built-ins. |
| filter `fw_site_converter_theme_settings` ( $values, $replace_chrome, $force ) | add, change or unset the Theme Settings values a conversion writes. |

## Order of preference — a hook is the LAST resort

1. A native Theme Settings option.
2. Scoped custom CSS.
3. An entry here.
4. Never the plugin or the parent theme.

A hook is last because it is the least visible: nothing changes until something reconverts, so always
reconvert and look at the page before believing an entry works.

## Retirement

Each converter update re-runs every `probe` once. An entry whose probe returns `false` is retired: it
stops applying and is listed as safe to delete. An entry with no probe is never retired automatically —
"I don't know" is not "safe to drop". A probe that throws also leaves the entry active, because silently
un-fixing a site is worse than keeping a correction one version too long.

## Sharing

The converter's screen can render this set as a report: each entry's source fragment plus what you
expected is a reproducible case a maintainer can turn into a permanent fix, so the next person converting
a source like yours needs no manual pass — and neither do you, next time. Send the case, not a patch, and
check it for anything you would not publish first.
MD;
	}

	private static function example() {
		return <<<'PHP'
<?php
/**
 * EXAMPLE entry — inert on purpose. Copy it, rename it, and make it real.
 *
 * It registers nothing and its probe reports the defect as already fixed, so it retires itself on the
 * first probe run and never affects a conversion. See ../README.md for the hooks available to apply().
 */
return array(
	'id'       => 'example',
	'summary'  => 'Example entry — does nothing. Copy this file to start a real correction.',
	'fragment' => '',
	'expected' => '',
	'probe'    => function () {
		return false; // nothing to fix, so this entry retires itself
	},
	'apply'    => function () {
		// Nothing. A real entry registers one of the three converter hooks here.
	},
);
PHP;
	}
}
