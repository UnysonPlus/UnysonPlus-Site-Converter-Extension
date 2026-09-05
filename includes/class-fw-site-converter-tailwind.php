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
		// Marquee / auto-scroll ticker keyframes — emitted only when the class is used. A -50% translate loops
		// seamlessly against a duplicated 2× track (the standard marquee build). Names match the decls above.
		$allcls = ' ' . implode( ' ', $classes ) . ' ';
		if ( strpos( $allcls, ' animate-marquee ' ) !== false )      { $out .= "@keyframes sc-marquee{from{transform:translateX(0)}to{transform:translateX(-50%)}}\n"; }
		if ( strpos( $allcls, ' animate-scroll-left ' ) !== false )  { $out .= "@keyframes sc-scroll-left{from{transform:translateX(0)}to{transform:translateX(-50%)}}\n"; }
		if ( strpos( $allcls, ' animate-scroll-right ' ) !== false ) { $out .= "@keyframes sc-scroll-right{from{transform:translateX(-50%)}to{transform:translateX(0)}}\n"; }
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
				'shadow-xl' => 'box-shadow:0 20px 25px -5px rgb(0 0 0 / 0.1)', 'shadow-2xl' => 'box-shadow:0 25px 50px -12px rgb(0 0 0 / 0.25)',
				'shadow-inner' => 'box-shadow:inset 0 2px 4px 0 rgb(0 0 0 / 0.05)',
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

		// --- scale-N transform (scale-0 … scale-150) — the general Tailwind scale utility. Covers the common
		// button micro-interaction `hover:scale-105` / `active:scale-95` (the fixed map above only had scale-95),
		// so a source button's grow-on-hover survives (compile_class_set routes the `hover:` variant to :hover).
		if ( preg_match( '/^scale-(\d{1,3})$/', $u, $m ) ) {
			$s = rtrim( rtrim( sprintf( '%.2f', (int) $m[1] / 100 ), '0' ), '.' );
			return 'transform:scale(' . $s . ')';
		}

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
		// Marquee / auto-scroll tickers (custom source animations). The @keyframes are emitted by compile()
		// when the class is present. A seamless loop assumes a duplicated 2× track (the standard technique),
		// so the track translates by -50%. Kept as a linear infinite scroll; hover-pause is the source's own.
		if ( $u === 'animate-marquee' )      { return 'animation:sc-marquee 25s linear infinite'; }
		if ( $u === 'animate-scroll-left' )  { return 'animation:sc-scroll-left 30s linear infinite'; }
		if ( $u === 'animate-scroll-right' ) { return 'animation:sc-scroll-right 30s linear infinite'; }

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

		// --- max-width NAMED scale (max-w-xs … max-w-7xl, prose, screen-*) ---
		// Tailwind's container measures live on a NAMED scale, not the numeric spacing scale, so
		// `max-w-3xl` (48rem) never matched the numeric rule below and a capped card (the locations
		// "Visit Us" card, `max-w-3xl mx-auto`) rendered at the full content width. Reproduce the scale.
		if ( preg_match( '/^max-w-(xs|sm|md|lg|xl|[2-7]xl|prose|screen-(?:sm|md|lg|xl|2xl))$/', $u, $m ) ) {
			$scale = array(
				'xs' => '20rem', 'sm' => '24rem', 'md' => '28rem', 'lg' => '32rem', 'xl' => '36rem',
				'2xl' => '42rem', '3xl' => '48rem', '4xl' => '56rem', '5xl' => '64rem', '6xl' => '72rem',
				'7xl' => '80rem', 'prose' => '65ch',
				'screen-sm' => '640px', 'screen-md' => '768px', 'screen-lg' => '1024px',
				'screen-xl' => '1280px', 'screen-2xl' => '1536px',
			);
			if ( isset( $scale[ $m[1] ] ) ) { return 'max-width:' . $scale[ $m[1] ]; }
		}

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
		// inset-* — the full-bleed overlay utility (`inset-0` = all four offsets 0). Without this a
		// `absolute inset-0` overlay collapses to a zero-size top-left box, so a text-over-image hero's
		// heading/CTA never lays over the photo. inset-x/y map to the L/R and T/B pairs.
		if ( preg_match( '/^inset-(x|y)-(\[[^\]]+\]|\d+(?:\.\d+)?|full|auto)$/', $u, $m ) ) {
			$v = $m[2] === 'full' ? '100%' : ( $m[2] === 'auto' ? 'auto' : self::len( $m[2] ) );
			return 'x' === $m[1] ? "left:{$v};right:{$v}" : "top:{$v};bottom:{$v}";
		}
		if ( preg_match( '/^inset-(\[[^\]]+\]|\d+(?:\.\d+)?|full|auto)$/', $u, $m ) ) {
			$v = $m[1] === 'full' ? '100%' : ( $m[1] === 'auto' ? 'auto' : self::len( $m[1] ) );
			return "top:{$v};right:{$v};bottom:{$v};left:{$v}";
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
		if ( preg_match( '/^\[(.+)\]$/', $name, $m ) ) {
			$v = trim( $m[1] );
			// A COLOUR value in `text-[…]` (e.g. `text-[hsl(var(--brand-yellow))]`) is a text colour, not a
			// font-size — return '' so the caller's colour handler emits `color:` instead of `font-size:`.
			if ( preg_match( '/^(#[0-9a-fA-F]{3,8}$|rgba?\(|hsla?\(|var\()/', $v ) ) { return ''; }
			return 'font-size:' . $v;
		}
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
		// Arbitrary value: bg-[#ff6b8b] / text-[rgb(...)] / border-[#fff] — emit the literal colour.
		if ( preg_match( '/^\[(.+)\]$/', $name, $am ) ) {
			$raw  = trim( $am[1] );
			$prop = array( 'bg' => 'background-color', 'text' => 'color', 'border' => 'border-color', 'ring' => '--tw-ring-color', 'from' => '--tw-gradient-from', 'to' => '--tw-gradient-to' );
			if ( isset( $prop[ $kind ] ) && preg_match( '/^(#[0-9a-f]{3,8}|rgba?\(|hsla?\(|var\()/i', $raw ) ) {
				return $prop[ $kind ] . ':' . $raw;
			}
			return '';
		}
		if ( preg_match( '#^(.+)/(\d+)$#', $name, $m ) ) { $name = $m[1]; $alpha = $m[2] / 100; }
		$hex = isset( $cfg['colors'][ $name ] ) ? $cfg['colors'][ $name ] : '';
		if ( $hex === '' ) {
			// a few built-ins Stitch uses without the config
			static $builtin = array( 'white' => '#ffffff', 'black' => '#000000', 'transparent' => 'transparent' );
			$hex = isset( $builtin[ $name ] ) ? $builtin[ $name ] : '';
		}
		if ( $hex === '' ) { $hex = self::palette( $name ); } // default Tailwind palette (pink-200, slate-800, …)
		if ( $hex === '' ) { return ''; }
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

	/** Default Tailwind v3 colour palette (family-shade → hex). Fallback when the site config doesn't
	 *  supply the colour, so `bg-pink-200` / `text-slate-800` / `border-pink-200` resolve on the
	 *  extension (PHP) path exactly as they do from getComputedStyle on the capture-service path. */
	private static function palette( $name ) {
		static $p = null;
		if ( $p === null ) {
			$fam = array(
				'slate'   => array( '#f8fafc', '#f1f5f9', '#e2e8f0', '#cbd5e1', '#94a3b8', '#64748b', '#475569', '#334155', '#1e293b', '#0f172a', '#020617' ),
				'gray'    => array( '#f9fafb', '#f3f4f6', '#e5e7eb', '#d1d5db', '#9ca3af', '#6b7280', '#4b5563', '#374151', '#1f2937', '#111827', '#030712' ),
				'zinc'    => array( '#fafafa', '#f4f4f5', '#e4e4e7', '#d4d4d8', '#a1a1aa', '#71717a', '#52525b', '#3f3f46', '#27272a', '#18181b', '#09090b' ),
				'neutral' => array( '#fafafa', '#f5f5f5', '#e5e5e5', '#d4d4d4', '#a3a3a3', '#737373', '#525252', '#404040', '#262626', '#171717', '#0a0a0a' ),
				'stone'   => array( '#fafaf9', '#f5f5f4', '#e7e5e4', '#d6d3d1', '#a8a29e', '#78716c', '#57534e', '#44403c', '#292524', '#1c1917', '#0c0a09' ),
				'red'     => array( '#fef2f2', '#fee2e2', '#fecaca', '#fca5a5', '#f87171', '#ef4444', '#dc2626', '#b91c1c', '#991b1b', '#7f1d1d', '#450a0a' ),
				'orange'  => array( '#fff7ed', '#ffedd5', '#fed7aa', '#fdba74', '#fb923c', '#f97316', '#ea580c', '#c2410c', '#9a3412', '#7c2d12', '#431407' ),
				'amber'   => array( '#fffbeb', '#fef3c7', '#fde68a', '#fcd34d', '#fbbf24', '#f59e0b', '#d97706', '#b45309', '#92400e', '#78350f', '#451a03' ),
				'yellow'  => array( '#fefce8', '#fef9c3', '#fef08a', '#fde047', '#facc15', '#eab308', '#ca8a04', '#a16207', '#854d0e', '#713f12', '#422006' ),
				'lime'    => array( '#f7fee7', '#ecfccb', '#d9f99d', '#bef264', '#a3e635', '#84cc16', '#65a30d', '#4d7c0f', '#3f6212', '#365314', '#1a2e05' ),
				'green'   => array( '#f0fdf4', '#dcfce7', '#bbf7d0', '#86efac', '#4ade80', '#22c55e', '#16a34a', '#15803d', '#166534', '#14532d', '#052e16' ),
				'emerald' => array( '#ecfdf5', '#d1fae5', '#a7f3d0', '#6ee7b7', '#34d399', '#10b981', '#059669', '#047857', '#065f46', '#064e3b', '#022c22' ),
				'teal'    => array( '#f0fdfa', '#ccfbf1', '#99f6e4', '#5eead4', '#2dd4bf', '#14b8a6', '#0d9488', '#0f766e', '#115e59', '#134e4a', '#042f2e' ),
				'cyan'    => array( '#ecfeff', '#cffafe', '#a5f3fc', '#67e8f9', '#22d3ee', '#06b6d4', '#0891b2', '#0e7490', '#155e75', '#164e63', '#083344' ),
				'sky'     => array( '#f0f9ff', '#e0f2fe', '#bae6fd', '#7dd3fc', '#38bdf8', '#0ea5e9', '#0284c7', '#0369a1', '#075985', '#0c4a6e', '#082f49' ),
				'blue'    => array( '#eff6ff', '#dbeafe', '#bfdbfe', '#93c5fd', '#60a5fa', '#3b82f6', '#2563eb', '#1d4ed8', '#1e40af', '#1e3a8a', '#172554' ),
				'indigo'  => array( '#eef2ff', '#e0e7ff', '#c7d2fe', '#a5b4fc', '#818cf8', '#6366f1', '#4f46e5', '#4338ca', '#3730a3', '#312e81', '#1e1b4b' ),
				'violet'  => array( '#f5f3ff', '#ede9fe', '#ddd6fe', '#c4b5fd', '#a78bfa', '#8b5cf6', '#7c3aed', '#6d28d9', '#5b21b6', '#4c1d95', '#2e1065' ),
				'purple'  => array( '#faf5ff', '#f3e8ff', '#e9d5ff', '#d8b4fe', '#c084fc', '#a855f7', '#9333ea', '#7e22ce', '#6b21a8', '#581c87', '#3b0764' ),
				'fuchsia' => array( '#fdf4ff', '#fae8ff', '#f5d0fe', '#f0abfc', '#e879f9', '#d946ef', '#c026d3', '#a21caf', '#86198f', '#701a75', '#4a044e' ),
				'pink'    => array( '#fdf2f8', '#fce7f3', '#fbcfe8', '#f9a8d4', '#f472b6', '#ec4899', '#db2777', '#be185d', '#9d174d', '#831843', '#500724' ),
				'rose'    => array( '#fff1f2', '#ffe4e6', '#fecdd3', '#fda4af', '#fb7185', '#f43f5e', '#e11d48', '#be123c', '#9f1239', '#881337', '#4c0519' ),
			);
			$shades = array( '50', '100', '200', '300', '400', '500', '600', '700', '800', '900', '950' );
			$p      = array();
			foreach ( $fam as $f => $hexes ) {
				foreach ( $shades as $i => $sh ) { $p[ "$f-$sh" ] = $hexes[ $i ]; }
			}
		}
		return isset( $p[ $name ] ) ? $p[ $name ] : '';
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

	/**
	 * Extract the source's CUSTOM semantic colours (foreground, background, muted, card, accent,
	 * border, …) from the captured computed styles, so their `bg-`/`text-`/`border-` utilities can be
	 * emitted even when the site defines them via a Tailwind config / CSS vars we can't see in
	 * view-source (React/shadcn sites — no inline `tailwind.config`, no `:root{--foreground}` in the
	 * rendered HTML). The capture service stamps each element's getComputedStyle onto `data-sc-cs`;
	 * we pair a base colour utility (`bg-foreground`, `text-muted`) with the matching computed value
	 * on the SAME element and record name => #hex. Opacity variants (`bg-foreground/70`) are ignored
	 * here (they resolve off the base colour via the normal /opacity path).
	 *
	 * Returns array( name => '#rrggbb', … ) — merge as a FALLBACK under any real config colours.
	 *
	 * @param string $html rendered markup carrying data-sc-cs computed-style attributes
	 * @return array
	 */
	public static function extract_semantic_colors( $html ) {
		$out = array();
		if ( ! preg_match_all( '/<[a-zA-Z][^>]*\bdata-sc-cs\s*=\s*"[^"]*"[^>]*>/', (string) $html, $tags ) ) { return $out; }
		$kind_prop = array( 'bg' => array( 'background-color' ), 'text' => array( 'color' ), 'border' => array( 'border-color', 'border-top-color', 'border-bottom-color' ) );
		foreach ( $tags[0] as $tag ) {
			if ( ! preg_match( '/\bclass\s*=\s*"([^"]*)"/', $tag, $cm ) ) { continue; }
			if ( ! preg_match( '/\bdata-sc-cs\s*=\s*"([^"]*)"/', $tag, $sm ) ) { continue; }
			$props = array();
			foreach ( explode( ';', $sm[1] ) as $decl ) {
				$cp = strpos( $decl, ':' );
				if ( $cp === false ) { continue; }
				$props[ strtolower( trim( substr( $decl, 0, $cp ) ) ) ] = trim( substr( $decl, $cp + 1 ) );
			}
			foreach ( preg_split( '/\s+/', trim( $cm[1] ) ) as $cls ) {
				// Only BASE utilities reflect the element's computed colour (variant / opacity forms don't).
				if ( $cls === '' || strpos( $cls, ':' ) !== false || strpos( $cls, '/' ) !== false ) { continue; }
				if ( ! preg_match( '/^(bg|text|border)-([a-z][a-z0-9-]*)$/', $cls, $km ) ) { continue; }
				$kind = $km[1];
				$name = $km[2];
				if ( isset( $out[ $name ] ) ) { continue; }
				// Skip anything the standard palette / built-ins already cover — ADD custom colours only.
				if ( in_array( $name, array( 'white', 'black', 'transparent', 'current', 'inherit' ), true ) ) { continue; }
				if ( self::palette( $name ) !== '' ) { continue; }
				$val = '';
				foreach ( $kind_prop[ $kind ] as $p ) { if ( ! empty( $props[ $p ] ) ) { $val = $props[ $p ]; break; } }
				$hex = self::rgb_to_hex( $val );
				if ( $hex !== '' ) { $out[ $name ] = $hex; }
			}
		}
		return $out;
	}

	/** "rgb(41, 61, 54)" / fully-opaque "rgba(r,g,b,1)" → "#rrggbb". Transparent / partial-alpha → ''. */
	private static function rgb_to_hex( $val ) {
		$val = trim( (string) $val );
		if ( $val === '' ) { return ''; }
		if ( preg_match( '/^#[0-9a-fA-F]{3,8}$/', $val ) ) { return $val; }
		if ( ! preg_match( '/^rgba?\(\s*([\d.]+)\s*,\s*([\d.]+)\s*,\s*([\d.]+)\s*(?:,\s*([\d.]+)\s*)?\)$/i', $val, $m ) ) { return ''; }
		if ( isset( $m[4] ) && $m[4] !== '' && (float) $m[4] < 0.999 ) { return ''; } // needs a solid colour for a utility
		return sprintf( '#%02x%02x%02x', min( 255, (int) round( $m[1] ) ), min( 255, (int) round( $m[2] ) ), min( 255, (int) round( $m[3] ) ) );
	}
}
