<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * A focused Tailwind (v3) utility compiler — offline, no browser.
 *
 * Stitch exports load the Tailwind CDN, which generates the CSS in the BROWSER at runtime from the
 * utility classes + the inline `tailwind.config`. So the static `code.html` contains the classes and
 * the config but NOT the compiled stylesheet (you only see it in DevTools, never in view-source).
 *
 * This class reproduces that compiled CSS deterministically, in PHP: it scans the classes actually
 * used in the markup, maps each to its declarations (the standard utilities + the config's custom
 * colors / fonts / sizes), handles arbitrary values (`mb-[150px]`), opacity (`bg-surface/80`),
 * responsive (`md:grid-cols-3`) and state (`hover:opacity-90`) variants, and prepends Tailwind's
 * preflight reset. The output matches `cdn.tailwindcss.com`'s for the same input, so a carried mirror
 * of the source markup renders pixel-faithfully with zero runtime CDN.
 *
 * Scope: the utilities Stitch/Tailwind actually emit for landing pages. Unknown classes are skipped
 * (they simply contribute no CSS, exactly as Tailwind would if they matched nothing).
 */
class FW_Site_Converter_Tailwind {

	/**
	 * Compile the CSS for every Tailwind class used in $html.
	 *
	 * @param string $html The source markup (its class attributes are scanned).
	 * @param array  $cfg  Parsed tailwind.config: { colors:{name=>#hex}, fontSize:{name=>[size,extra]}, fonts:{} }.
	 * @return string CSS (preflight + keyframes + the used utilities, base then sm/md/lg).
	 */
	public static function compile( $html, array $cfg = array(), $scope = '' ) {
		$scope   = trim( (string) $scope );
		$classes = self::scan_classes( (string) $html );
		$base    = array();
		$media   = array( 'sm' => array(), 'md' => array(), 'lg' => array() );
		$bp      = array( 'sm' => '640px', 'md' => '768px', 'lg' => '1024px' );
		$need_kf = false;

		foreach ( $classes as $cls ) {
			$r = self::rule( $cls, $cfg, $scope );
			if ( $r === null ) { continue; }
			if ( $r['kf'] ) { $need_kf = true; }
			if ( $r['media'] !== '' && isset( $media[ $r['media'] ] ) ) {
				$media[ $r['media'] ][] = $r['css'];
			} else {
				$base[] = $r['css'];
			}
		}

		$out  = self::preflight( $scope );
		if ( $need_kf ) { $out .= "@keyframes pulse{50%{opacity:.5}}\n"; }
		$out .= implode( "\n", array_unique( $base ) ) . "\n";
		foreach ( array( 'sm', 'md', 'lg' ) as $k ) {
			$rules = array_unique( $media[ $k ] );
			if ( $rules ) { $out .= "@media (min-width:{$bp[$k]}){" . implode( '', $rules ) . "}\n"; }
		}
		return $out;
	}

	/**
	 * CSS CLASS MAPPER: compile a SET of utility classes from ONE element into a de-duplicated map of
	 * declarations, split by state — so a converter can emit ONE clean semantic rule per element
	 * (`.box-1 { … }`, `.box-1:hover { … }`) instead of carrying the raw utilities in the DOM.
	 *
	 * Returns `array( 'base' => [prop=>val,…], 'hover' => […], 'group_hover' => […], 'kf' => bool )`.
	 * De-dup is by property (a later class with the same property wins, like the cascade).
	 *
	 * @param string $classes the element's class="" string
	 * @param array  $cfg     parsed tailwind.config (parse_config())
	 */
	public static function compile_class_set( $classes, array $cfg = array() ) {
		$out = array( 'base' => array(), 'hover' => array(), 'group_hover' => array(), 'kf' => false );
		foreach ( preg_split( '/\s+/', trim( (string) $classes ) ) as $cls ) {
			if ( $cls === '' || $cls === 'group' ) { continue; }
			$target = 'base';
			$b      = $cls;
			$skip   = false;
			while ( preg_match( '/^([a-z0-9-]+):(.+)$/', $b, $mm ) ) {
				$v = $mm[1];
				if ( $v === 'hover' ) { $target = 'hover'; }
				elseif ( $v === 'group-hover' ) { $target = 'group_hover'; }
				elseif ( in_array( $v, array( 'sm', 'md', 'lg', 'xl', '2xl', 'focus', 'active', 'focus-within', 'disabled', 'dark' ), true ) ) { $skip = true; break; }
				$b = $mm[2];
			}
			if ( $skip ) { continue; }
			$kf = false;
			$d  = self::decls( $b, $cfg, $kf );
			if ( $d === '' ) { continue; }
			if ( $kf ) { $out['kf'] = true; }
			foreach ( explode( ';', $d ) as $decl ) {
				$decl = trim( $decl );
				if ( $decl === '' ) { continue; }
				$cp = strpos( $decl, ':' );
				if ( $cp === false ) { continue; }
				$prop = trim( substr( $decl, 0, $cp ) );
				$val  = trim( substr( $decl, $cp + 1 ) );
				if ( $prop !== '' ) { $out[ $target ][ $prop ] = $val; }
			}
		}
		return $out;
	}

	/** Render a {prop=>val} map as a `prop:val;prop:val` declaration string. */
	public static function decl_string( array $map ) {
		$out = array();
		foreach ( $map as $prop => $val ) { $out[] = $prop . ':' . $val; }
		return implode( ';', $out );
	}

	/** Collect the unique set of classes used in any class="…" attribute. */
	public static function scan_classes( $html ) {
		$set = array();
		if ( preg_match_all( '/class\s*=\s*"([^"]*)"/i', $html, $m ) ) {
			foreach ( $m[1] as $list ) {
				foreach ( preg_split( '/\s+/', trim( $list ) ) as $c ) {
					if ( $c !== '' ) { $set[ $c ] = true; }
				}
			}
		}
		return array_keys( $set );
	}

	/**
	 * One class → a compiled rule (or null if unknown).
	 *
	 * @return array|null { css:'.sel{decls}', media:'sm|md|lg|', kf:bool }
	 */
	private static function rule( $cls, array $cfg, $scope = '' ) {
		$media = '';
		$pseudo = '';
		$prefix = ''; // ancestor selector (group-hover)
		$base   = $cls;

		// Peel variant prefixes (left to right): responsive then state.
		while ( preg_match( '/^([a-z-]+):(.+)$/', $base, $mm ) ) {
			$v = $mm[1];
			if ( in_array( $v, array( 'sm', 'md', 'lg', 'xl', '2xl' ), true ) ) {
				$media = $v;
			} elseif ( in_array( $v, array( 'hover', 'focus', 'active' ), true ) ) {
				$pseudo .= ':' . $v;
			} elseif ( $v === 'focus-within' ) {
				$pseudo .= ':focus-within';
			} elseif ( $v === 'group-hover' ) {
				$prefix = '.group:hover ';
			} else {
				return null; // unsupported variant
			}
			$base = $mm[2];
		}

		$decls = self::decls( $base, $cfg, $kf );
		if ( $decls === '' ) { return null; }
		// The host theme/framework ships its OWN single-class utilities (.px-6, .flex, .grid, .mb-*, …)
		// as `!important`, which collide by name with the source's Tailwind classes and silently win.
		// Emit `!important` here too (like Tailwind's own `important` mode) so the scoped `.sc-tw .cls`
		// (specificity 0,2,0) beats the framework's bare `.cls !important` (0,1,0) on specificity.
		$decls = self::important( $decls );

		// space-x/space-y target the gap between children, not the element itself.
		$sel = '.' . self::esc( $cls );
		if ( strpos( $base, 'space-x-' ) === 0 || strpos( $base, 'space-y-' ) === 0 ) {
			$sel .= ' > :not([hidden]) ~ :not([hidden])';
		} else {
			$sel .= $pseudo;
		}
		// Scope (so the utilities never leak past the converted content): "<scope> .group:hover .cls".
		$scope_pre = $scope !== '' ? $scope . ' ' : '';
		$sel = $scope_pre . $prefix . $sel;

		return array( 'css' => $sel . '{' . $decls . '}', 'media' => $media, 'kf' => ! empty( $kf ) );
	}

	/** Append `!important` to every declaration in a `prop:val;prop:val` string (Tailwind important mode). */
	private static function important( $decls ) {
		$parts = array_filter( array_map( 'trim', explode( ';', (string) $decls ) ), 'strlen' );
		foreach ( $parts as &$p ) {
			if ( stripos( $p, '!important' ) === false ) { $p .= ' !important'; }
		}
		unset( $p );
		return implode( ';', $parts );
	}

	/** Escape a class name for use in a selector (Tailwind escapes :, /, [, ], ., %, etc.). */
	private static function esc( $cls ) {
		return preg_replace( '/([:\/\[\]\.%#\(\)!,])/', '\\\\$1', $cls );
	}

	/**
	 * The declarations for a BASE (variant-stripped) utility, or '' if unknown.
	 *
	 * @param string $u    utility
	 * @param array  $cfg  config
	 * @param bool   $kf   (out) set true if it needs @keyframes
	 * @return string
	 */
	private static function decls( $u, array $cfg, &$kf = false ) {
		$kf = false;

		// --- fixed map (no parameters) ---
		static $fixed = null;
		if ( $fixed === null ) {
			$fixed = array(
				'flex' => 'display:flex', 'inline-flex' => 'display:inline-flex', 'grid' => 'display:grid',
				'block' => 'display:block', 'inline-block' => 'display:inline-block', 'inline' => 'display:inline',
				'hidden' => 'display:none', 'flex-col' => 'flex-direction:column', 'flex-row' => 'flex-direction:row',
				'flex-wrap' => 'flex-wrap:wrap', 'flex-grow' => 'flex-grow:1', 'flex-shrink-0' => 'flex-shrink:0',
				'items-center' => 'align-items:center', 'items-start' => 'align-items:flex-start', 'items-end' => 'align-items:flex-end',
				'justify-center' => 'justify-content:center', 'justify-between' => 'justify-content:space-between',
				'justify-start' => 'justify-content:flex-start', 'justify-end' => 'justify-content:flex-end',
				'relative' => 'position:relative', 'absolute' => 'position:absolute', 'fixed' => 'position:fixed', 'sticky' => 'position:sticky',
				'overflow-hidden' => 'overflow:hidden', 'overflow-auto' => 'overflow:auto',
				'text-center' => 'text-align:center', 'text-left' => 'text-align:left', 'text-right' => 'text-align:right',
				'uppercase' => 'text-transform:uppercase', 'lowercase' => 'text-transform:lowercase', 'capitalize' => 'text-transform:capitalize',
				'normal-case' => 'text-transform:none', 'italic' => 'font-style:italic', 'underline' => 'text-decoration-line:underline',
				'font-bold' => 'font-weight:700', 'font-semibold' => 'font-weight:600', 'font-medium' => 'font-weight:500',
				'font-normal' => 'font-weight:400', 'font-light' => 'font-weight:300',
				'object-cover' => 'object-fit:cover', 'object-contain' => 'object-fit:contain',
				'w-full' => 'width:100%', 'w-auto' => 'width:auto', 'h-full' => 'height:100%', 'h-auto' => 'height:auto',
				'min-h-screen' => 'min-height:100vh', 'mx-auto' => 'margin-left:auto;margin-right:auto',
				'leading-relaxed' => 'line-height:1.625', 'leading-tight' => 'line-height:1.25', 'leading-none' => 'line-height:1',
				'tracking-tight' => 'letter-spacing:-0.025em', 'tracking-tighter' => 'letter-spacing:-0.05em',
				'tracking-wide' => 'letter-spacing:0.025em', 'tracking-wider' => 'letter-spacing:0.05em', 'tracking-widest' => 'letter-spacing:0.1em',
				'rounded-full' => 'border-radius:9999px', 'rounded-none' => 'border-radius:0',
				'rounded' => 'border-radius:0.25rem', 'rounded-sm' => 'border-radius:0.125rem', 'rounded-md' => 'border-radius:0.375rem',
				'rounded-lg' => 'border-radius:0.5rem', 'rounded-xl' => 'border-radius:0.75rem', 'rounded-2xl' => 'border-radius:1rem', 'rounded-3xl' => 'border-radius:1.5rem',
				'rounded-l-md' => 'border-top-left-radius:0.375rem;border-bottom-left-radius:0.375rem',
				'rounded-r-md' => 'border-top-right-radius:0.375rem;border-bottom-right-radius:0.375rem',
				'border' => 'border-width:1px', 'border-0' => 'border-width:0', 'border-2' => 'border-width:2px',
				'border-t' => 'border-top-width:1px', 'border-b' => 'border-bottom-width:1px',
				'border-b-2' => 'border-bottom-width:2px', 'border-y' => 'border-top-width:1px;border-bottom-width:1px',
				'bg-transparent' => 'background-color:transparent', 'bg-gradient-to-t' => 'background-image:linear-gradient(to top, var(--tw-gradient-stops))',
				'bg-gradient-to-b' => 'background-image:linear-gradient(to bottom, var(--tw-gradient-stops))',
				'bg-gradient-to-r' => 'background-image:linear-gradient(to right, var(--tw-gradient-stops))',
				'to-transparent' => '--tw-gradient-to:transparent',
				'opacity-0' => 'opacity:0', 'opacity-50' => 'opacity:0.5', 'opacity-60' => 'opacity:0.6', 'opacity-80' => 'opacity:0.8', 'opacity-100' => 'opacity:1',
				'mix-blend-multiply' => 'mix-blend-mode:multiply',
				'shadow-sm' => 'box-shadow:0 1px 2px 0 rgb(0 0 0 / 0.05)', 'shadow' => 'box-shadow:0 1px 3px 0 rgb(0 0 0 / 0.1)',
				'shadow-md' => 'box-shadow:0 4px 6px -1px rgb(0 0 0 / 0.1)', 'shadow-lg' => 'box-shadow:0 10px 15px -3px rgb(0 0 0 / 0.1)',
				'shadow-none' => 'box-shadow:0 0 #0000',
				'grayscale' => 'filter:grayscale(100%)', 'backdrop-blur-md' => 'backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px)',
				'backdrop-blur-xl' => 'backdrop-filter:blur(24px);-webkit-backdrop-filter:blur(24px)',
				'backdrop-blur-sm' => 'backdrop-filter:blur(4px);-webkit-backdrop-filter:blur(4px)',
				'transition-all' => 'transition-property:all;transition-timing-function:cubic-bezier(0.4,0,0.2,1);transition-duration:150ms',
				'transition-colors' => 'transition-property:color,background-color,border-color,fill,stroke;transition-timing-function:cubic-bezier(0.4,0,0.2,1);transition-duration:150ms',
				'transition-opacity' => 'transition-property:opacity;transition-timing-function:cubic-bezier(0.4,0,0.2,1);transition-duration:150ms',
				'transition-shadow' => 'transition-property:box-shadow;transition-timing-function:cubic-bezier(0.4,0,0.2,1);transition-duration:150ms',
				'transition-transform' => 'transition-property:transform;transition-timing-function:cubic-bezier(0.4,0,0.2,1);transition-duration:150ms',
				'ease-in-out' => 'transition-timing-function:cubic-bezier(0.4,0,0.2,1)', 'ease-out' => 'transition-timing-function:cubic-bezier(0,0,0.2,1)',
				'duration-150' => 'transition-duration:150ms', 'duration-200' => 'transition-duration:200ms', 'duration-300' => 'transition-duration:300ms', 'duration-500' => 'transition-duration:500ms',
				'delay-75' => 'transition-delay:75ms', 'delay-100' => 'transition-delay:100ms', 'delay-150' => 'transition-delay:150ms', 'delay-200' => 'transition-delay:200ms',
				'active:scale-95' => 'transform:scale(.95)', 'scale-95' => 'transform:scale(.95)',
			);
		}
		if ( isset( $fixed[ $u ] ) ) { return $fixed[ $u ]; }

		// --- border-radius with an arbitrary value: rounded-[24px], rounded-t-[..], rounded-l-[..] ---
		if ( preg_match( '/^rounded(?:-(t|b|l|r|tl|tr|bl|br))?-(\[[^\]]+\])$/', $u, $m ) ) {
			$v    = self::len( $m[2] );
			$side = $m[1];
			$map  = array(
				't'  => array( 'border-top-left-radius', 'border-top-right-radius' ),
				'b'  => array( 'border-bottom-left-radius', 'border-bottom-right-radius' ),
				'l'  => array( 'border-top-left-radius', 'border-bottom-left-radius' ),
				'r'  => array( 'border-top-right-radius', 'border-bottom-right-radius' ),
				'tl' => array( 'border-top-left-radius' ), 'tr' => array( 'border-top-right-radius' ),
				'bl' => array( 'border-bottom-left-radius' ), 'br' => array( 'border-bottom-right-radius' ),
			);
			if ( $side === '' ) { return 'border-radius:' . $v; }
			$props = isset( $map[ $side ] ) ? $map[ $side ] : array( 'border-radius' );
			return implode( ';', array_map( function ( $p ) use ( $v ) { return $p . ':' . $v; }, $props ) );
		}

		if ( $u === 'animate-pulse' ) { $kf = true; return 'animation:pulse 2s cubic-bezier(0.4,0,0.6,1) infinite'; }

		// --- font-family (config fontFamily: font-body, font-h1, …) — checked AFTER the weight map above ---
		if ( preg_match( '/^font-(.+)$/', $u, $m ) && isset( $cfg['fontFamily'][ $m[1] ] ) ) {
			$f = $cfg['fontFamily'][ $m[1] ];
			return 'font-family:' . ( is_array( $f ) ? implode( ',', $f ) : $f );
		}

		// --- text size (default scale + config fontSize + arbitrary) ---
		if ( preg_match( '/^text-(.+)$/', $u, $m ) ) {
			$d = self::text_size( $m[1], $cfg );
			if ( $d !== '' ) { return $d; }
			// otherwise a text color (handled below).
		}

		// --- colors: bg / text / border / ring / from / to ---
		if ( preg_match( '/^(bg|text|border|ring|from|to)-(.+)$/', $u, $m ) ) {
			$d = self::color( $m[1], $m[2], $cfg );
			if ( $d !== '' ) { return $d; }
		}

		// --- spacing: m/p {t,b,l,r,x,y}? - value ---
		if ( preg_match( '/^([mp])([trblxy]?)-(\[[^\]]+\]|\d+(?:\.\d+)?)$/', $u, $m ) ) {
			$prop = $m[1] === 'm' ? 'margin' : 'padding';
			$val  = self::len( $m[3] );
			return self::side_decls( $prop, $m[2], $val );
		}
		// negative margins
		if ( preg_match( '/^-m([trblxy]?)-(\d+(?:\.\d+)?)$/', $u, $m ) ) {
			return self::side_decls( 'margin', $m[1], '-' . self::rem( $m[2] ) );
		}

		// --- gap / space ---
		if ( preg_match( '/^gap-(\[[^\]]+\]|\d+(?:\.\d+)?)$/', $u, $m ) ) { return 'gap:' . self::len( $m[1] ); }
		if ( preg_match( '/^gap-x-(\d+(?:\.\d+)?)$/', $u, $m ) ) { return 'column-gap:' . self::rem( $m[1] ); }
		if ( preg_match( '/^gap-y-(\d+(?:\.\d+)?)$/', $u, $m ) ) { return 'row-gap:' . self::rem( $m[1] ); }
		if ( preg_match( '/^space-x-(\d+(?:\.\d+)?)$/', $u, $m ) ) { $v = self::rem( $m[1] ); return "margin-right:0;margin-left:{$v}"; }
		if ( preg_match( '/^space-y-(\d+(?:\.\d+)?)$/', $u, $m ) ) { $v = self::rem( $m[1] ); return "margin-top:{$v};margin-bottom:0"; }

		// --- sizing: w / h / max-w / min-w / max-h ---
		if ( preg_match( '/^(w|h|max-w|min-w|max-h|min-h)-(\[[^\]]+\]|\d+(?:\.\d+)?|full|screen|auto)$/', $u, $m ) ) {
			$prop = array( 'w' => 'width', 'h' => 'height', 'max-w' => 'max-width', 'min-w' => 'min-width', 'max-h' => 'max-height', 'min-h' => 'min-height' )[ $m[1] ];
			$v    = $m[2] === 'full' ? '100%' : ( $m[2] === 'screen' ? ( $m[1][0] === 'w' ? '100vw' : '100vh' ) : ( $m[2] === 'auto' ? 'auto' : self::len( $m[2] ) ) );
			return "{$prop}:{$v}";
		}

		// --- position offsets: top/bottom/left/right/inset, z-index ---
		if ( preg_match( '/^(top|bottom|left|right)-(\[[^\]]+\]|\d+(?:\.\d+)?|full|auto)$/', $u, $m ) ) {
			$v = $m[2] === 'full' ? '100%' : ( $m[2] === 'auto' ? 'auto' : self::len( $m[2] ) );
			return "{$m[1]}:{$v}";
		}
		if ( preg_match( '/^z-(\d+)$/', $u, $m ) ) { return 'z-index:' . $m[1]; }

		// --- grid / col-span ---
		if ( preg_match( '/^grid-cols-(\d+)$/', $u, $m ) ) { return "grid-template-columns:repeat({$m[1]}, minmax(0, 1fr))"; }
		if ( preg_match( '/^col-span-(\d+)$/', $u, $m ) ) { return "grid-column:span {$m[1]} / span {$m[1]}"; }
		if ( preg_match( '/^row-span-(\d+)$/', $u, $m ) ) { return "grid-row:span {$m[1]} / span {$m[1]}"; }

		// --- aspect ratio (arbitrary) ---
		if ( preg_match( '/^aspect-\[([0-9.]+)\/([0-9.]+)\]$/', $u, $m ) ) { return "aspect-ratio:{$m[1]}/{$m[2]}"; }
		if ( $u === 'aspect-video' ) { return 'aspect-ratio:16/9'; }
		if ( $u === 'aspect-square' ) { return 'aspect-ratio:1/1'; }

		return '';
	}

	/** margin/padding side declarations. $side ∈ '', t,b,l,r,x,y. */
	private static function side_decls( $prop, $side, $val ) {
		$p = $prop === 'margin' ? 'margin' : 'padding';
		switch ( $side ) {
			case 't': return "{$p}-top:{$val}";
			case 'b': return "{$p}-bottom:{$val}";
			case 'l': return "{$p}-left:{$val}";
			case 'r': return "{$p}-right:{$val}";
			case 'x': return "{$p}-left:{$val};{$p}-right:{$val}";
			case 'y': return "{$p}-top:{$val};{$p}-bottom:{$val}";
			default:  return "{$p}:{$val}";
		}
	}

	/** A length token: arbitrary `[150px]` → 150px; bare number N → N*0.25rem. */
	private static function len( $tok ) {
		if ( preg_match( '/^\[(.+)\]$/', $tok, $m ) ) { return $m[1]; }
		return self::rem( $tok );
	}

	/** Bare spacing number → rem (Tailwind scale: n × 0.25rem; 0 → 0px). */
	private static function rem( $n ) {
		$n = (float) $n;
		if ( $n === 0.0 ) { return '0px'; }
		$r = $n * 0.25;
		return rtrim( rtrim( sprintf( '%.4f', $r ), '0' ), '.' ) . 'rem';
	}

	/** Text-size utility: default scale, config fontSize, or arbitrary `[10px]`. */
	private static function text_size( $name, array $cfg ) {
		static $scale = array(
			'xs' => '0.75rem;line-height:1rem', 'sm' => '0.875rem;line-height:1.25rem', 'base' => '1rem;line-height:1.5rem',
			'lg' => '1.125rem;line-height:1.75rem', 'xl' => '1.25rem;line-height:1.75rem', '2xl' => '1.5rem;line-height:2rem',
			'3xl' => '1.875rem;line-height:2.25rem', '4xl' => '2.25rem;line-height:2.5rem', '5xl' => '3rem;line-height:1',
			'6xl' => '3.75rem;line-height:1', '7xl' => '4.5rem;line-height:1',
		);
		if ( isset( $scale[ $name ] ) ) { return 'font-size:' . $scale[ $name ]; }
		if ( preg_match( '/^\[(.+)\]$/', $name, $m ) ) { return 'font-size:' . $m[1]; }
		// config fontSize: { name => [size, {lineHeight,letterSpacing,fontWeight}] }
		if ( isset( $cfg['fontSize'][ $name ] ) ) {
			$fs = $cfg['fontSize'][ $name ];
			if ( is_array( $fs ) ) {
				$size = isset( $fs[0] ) ? $fs[0] : '';
				$ex   = isset( $fs[1] ) && is_array( $fs[1] ) ? $fs[1] : array();
				$d    = 'font-size:' . $size;
				if ( ! empty( $ex['lineHeight'] ) )    { $d .= ';line-height:' . $ex['lineHeight']; }
				if ( ! empty( $ex['letterSpacing'] ) ) { $d .= ';letter-spacing:' . $ex['letterSpacing']; }
				if ( ! empty( $ex['fontWeight'] ) )    { $d .= ';font-weight:' . $ex['fontWeight']; }
				return $d;
			}
			return 'font-size:' . $fs;
		}
		return '';
	}

	/** Color utility (bg/text/border/ring/from/to) for a config color (+ optional /opacity). */
	private static function color( $kind, $name, array $cfg ) {
		$alpha = '';
		if ( preg_match( '#^(.+)/(\d+)$#', $name, $m ) ) { $name = $m[1]; $alpha = $m[2] / 100; }
		$hex = isset( $cfg['colors'][ $name ] ) ? $cfg['colors'][ $name ] : '';
		if ( $hex === '' ) {
			// a few built-ins Stitch uses without the config
			static $builtin = array( 'white' => '#ffffff', 'black' => '#000000', 'transparent' => 'transparent' );
			$hex = isset( $builtin[ $name ] ) ? $builtin[ $name ] : '';
			if ( $hex === '' ) { return ''; }
		}
		if ( $hex === 'transparent' ) { $rgb = 'transparent'; }
		else {
			$c = self::hex_rgb( $hex );
			if ( $c === '' ) { return ''; }
			$rgb = $alpha !== '' ? "rgb($c / $alpha)" : "rgb($c / var(--tw-bg-opacity, 1))";
		}
		switch ( $kind ) {
			case 'bg':     return $alpha !== '' ? "background-color:$rgb" : "--tw-bg-opacity:1;background-color:" . str_replace( 'tw-bg-opacity', 'tw-bg-opacity', $rgb );
			case 'text':   return $alpha !== '' ? "color:" . str_replace( 'tw-bg-opacity', 'tw-text-opacity', $rgb ) : "--tw-text-opacity:1;color:" . str_replace( 'tw-bg-opacity', 'tw-text-opacity', $rgb );
			case 'border': return $alpha !== '' ? "border-color:" . str_replace( 'tw-bg-opacity', 'tw-border-opacity', $rgb ) : "--tw-border-opacity:1;border-color:" . str_replace( 'tw-bg-opacity', 'tw-border-opacity', $rgb );
			case 'ring':   return "--tw-ring-color:" . ( $alpha !== '' ? "rgb($c / $alpha)" : "rgb($c)" );
			case 'from':   return "--tw-gradient-from:$hex;--tw-gradient-stops:var(--tw-gradient-from), var(--tw-gradient-to, rgb(255 255 255 / 0))";
			case 'to':     return "--tw-gradient-to:$hex";
		}
		return '';
	}

	/** #rrggbb (or #rgb) → "R G B". */
	private static function hex_rgb( $hex ) {
		$hex = ltrim( trim( $hex ), '#' );
		if ( strlen( $hex ) === 3 ) { $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2]; }
		if ( ! preg_match( '/^[0-9a-fA-F]{6}$/', $hex ) ) { return ''; }
		return hexdec( substr( $hex, 0, 2 ) ) . ' ' . hexdec( substr( $hex, 2, 2 ) ) . ' ' . hexdec( substr( $hex, 4, 2 ) );
	}

	/** Tailwind v3 preflight (the reset), trimmed to what affects rendered content, scoped to $scope. */
	private static function preflight( $scope = '' ) {
		$rules = array(
			array( '*,::before,::after', "box-sizing:border-box;border-width:0;border-style:solid;border-color:#e5e7eb" ),
			array( '::before,::after', "--tw-content:''" ),
			array( 'h1,h2,h3,h4,h5,h6', 'font-size:inherit;font-weight:inherit;margin:0' ),
			array( 'p,blockquote,figure,pre,dl,dd', 'margin:0' ),
			array( 'a', 'color:inherit;text-decoration:inherit' ),
			array( 'b,strong', 'font-weight:bolder' ),
			array( 'ul,ol,menu', 'list-style:none;margin:0;padding:0' ),
			array( 'button,input,select,textarea', 'font-family:inherit;font-size:100%;font-weight:inherit;line-height:inherit;color:inherit;margin:0;padding:0' ),
			array( 'button,[role=button]', 'cursor:pointer;background-color:transparent;background-image:none' ),
			array( 'img,svg,video,canvas,audio,iframe,embed,object', 'display:block;vertical-align:middle' ),
			array( 'img,video', 'max-width:100%;height:auto' ),
		);
		$out = '';
		foreach ( $rules as $r ) {
			$sel = $scope === ''
				? $r[0]
				: implode( ',', array_map( function ( $s ) use ( $scope ) { return $scope . ' ' . trim( $s ); }, explode( ',', $r[0] ) ) );
			$out .= $sel . '{' . $r[1] . "}\n";
		}
		return $out;
	}

	/**
	 * Parse the inline `tailwind.config = {…}` object from a Stitch code.html into the shape compile()
	 * wants: { colors, fontSize, fontFamily }. Tolerant of trailing commas / single quotes (JSON5-ish).
	 *
	 * @param string $html
	 * @return array
	 */
	public static function parse_config( $html ) {
		$out = array( 'colors' => array(), 'fontSize' => array(), 'fontFamily' => array() );
		if ( ! preg_match( '/tailwind\.config\s*=\s*(\{.*?\})\s*;?\s*<\/script>/s', $html, $m ) ) { return $out; }
		$obj = $m[1];
		// colors{ "name":"#hex", … }
		if ( preg_match( '/"colors"\s*:\s*\{(.*?)\}/s', $obj, $cm ) ) {
			if ( preg_match_all( '/"([^"]+)"\s*:\s*"(#[0-9a-fA-F]{3,8})"/', $cm[1], $pp, PREG_SET_ORDER ) ) {
				foreach ( $pp as $p ) { $out['colors'][ $p[1] ] = $p[2]; }
			}
		}
		// fontFamily{ "name":"Inter" | ["Inter", …] }
		if ( preg_match( '/"fontFamily"\s*:\s*\{(.*?)\}(?=\s*,\s*"[a-zA-Z]|\s*\})/s', $obj, $fm ) ) {
			if ( preg_match_all( '/"([a-z0-9_-]+)"\s*:\s*(?:\[([^\]]*)\]|"([^"]+)")/i', $fm[1], $pp, PREG_SET_ORDER ) ) {
				foreach ( $pp as $p ) {
					if ( isset( $p[3] ) && $p[3] !== '' ) {
						$out['fontFamily'][ $p[1] ] = $p[3];
					} elseif ( preg_match( '/"([^"]+)"/', $p[2], $q ) ) {
						$out['fontFamily'][ $p[1] ] = $q[1];
					}
				}
			}
		}
		// fontSize{ "name":["size",{lineHeight,letterSpacing,fontWeight}] }
		if ( preg_match( '/"fontSize"\s*:\s*\{(.*?)\}\s*,\s*"[a-z]/is', $obj . ',"x', $fm )
			|| preg_match( '/"fontSize"\s*:\s*(\{(?:[^{}]|\{[^{}]*\})*\})/s', $obj, $fm ) ) {
			$blk = isset( $fm[1] ) ? $fm[1] : '';
			if ( preg_match_all( '/"([a-z0-9-]+)"\s*:\s*\[\s*"([^"]+)"\s*,\s*\{([^}]*)\}\s*\]/i', $blk, $ff, PREG_SET_ORDER ) ) {
				foreach ( $ff as $f ) {
					$ex = array();
					if ( preg_match( '/"lineHeight"\s*:\s*"?([^",}]+)"?/', $f[3], $x ) )    { $ex['lineHeight'] = trim( $x[1] ); }
					if ( preg_match( '/"letterSpacing"\s*:\s*"?([^",}]+)"?/', $f[3], $x ) ) { $ex['letterSpacing'] = trim( $x[1] ); }
					if ( preg_match( '/"fontWeight"\s*:\s*"?([^",}]+)"?/', $f[3], $x ) )    { $ex['fontWeight'] = trim( $x[1] ); }
					$out['fontSize'][ $f[1] ] = array( $f[2], $ex );
				}
			}
		}
		return $out;
	}
}
