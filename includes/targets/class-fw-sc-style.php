<?php if ( ! defined( 'FW' ) ) { die( 'Forbidden' ); }

/**
 * Small, dependency-free helpers for the measured CSS the capture stamps on every element
 * (`data-sc-cs` → the mapping's `cs` / `srcCs` / `sectionCs` strings).
 *
 * These are the builder-neutral facts a target translates into its own style settings, so they
 * are parsed once here rather than re-parsed by every target.
 */
final class FW_SC_Style {

	/**
	 * "color:rgb(1, 2, 3);font-family:Inter, sans-serif;padding:80px 0px" → array( prop => value ).
	 * Splits on `;` outside parentheses and quotes, so url(…;…) and quoted font names survive.
	 *
	 * @param string $css
	 * @return array<string,string>
	 */
	public static function parse( $css ) {
		$css = trim( (string) $css );
		if ( '' === $css ) { return array(); }
		$out   = array();
		$depth = 0;
		$quote = '';
		$buf   = '';
		$len   = strlen( $css );
		for ( $i = 0; $i <= $len; $i++ ) {
			$ch = $i < $len ? $css[ $i ] : ';';
			if ( '' !== $quote ) {
				if ( $ch === $quote ) { $quote = ''; }
				$buf .= $ch;
				continue;
			}
			if ( '"' === $ch || "'" === $ch ) { $quote = $ch; $buf .= $ch; continue; }
			if ( '(' === $ch ) { $depth++; }
			if ( ')' === $ch ) { $depth = max( 0, $depth - 1 ); }
			if ( ';' === $ch && 0 === $depth ) {
				$pos = strpos( $buf, ':' );
				if ( false !== $pos ) {
					$prop = strtolower( trim( substr( $buf, 0, $pos ) ) );
					$val  = trim( substr( $buf, $pos + 1 ) );
					if ( '' !== $prop && '' !== $val ) { $out[ $prop ] = $val; }
				}
				$buf = '';
				continue;
			}
			$buf .= $ch;
		}
		return $out;
	}

	/** array( prop => value ) → "prop:value;prop:value" */
	public static function to_css( array $props ) {
		$parts = array();
		foreach ( $props as $p => $v ) {
			if ( '' === (string) $v ) { continue; }
			$parts[] = $p . ':' . $v;
		}
		return implode( ';', $parts );
	}

	/** "24px" → 24.0, "1.5rem" → 24.0 (16px root), "" / "auto" / "normal" → null. */
	public static function px( $v, $root = 16.0 ) {
		$v = strtolower( trim( (string) $v ) );
		if ( preg_match( '/^(-?[\d.]+)px$/', $v, $m ) ) { return (float) $m[1]; }
		if ( preg_match( '/^(-?[\d.]+)r?em$/', $v, $m ) ) { return (float) $m[1] * $root; }
		if ( preg_match( '/^-?[\d.]+$/', $v ) ) { return (float) $v; }
		return null;
	}

	/**
	 * A 1–4 value box shorthand ("80px 0px") → array( top, right, bottom, left ) in px, or null when any
	 * side is not a px-resolvable length.
	 */
	public static function box( $v ) {
		$v = trim( (string) $v );
		if ( '' === $v ) { return null; }
		$parts = preg_split( '/\s+/', $v );
		$px    = array();
		foreach ( $parts as $p ) {
			$n = self::px( $p );
			if ( null === $n ) { return null; }
			$px[] = $n;
		}
		switch ( count( $px ) ) {
			case 1: return array( $px[0], $px[0], $px[0], $px[0] );
			case 2: return array( $px[0], $px[1], $px[0], $px[1] );
			case 3: return array( $px[0], $px[1], $px[2], $px[1] );
			case 4: return $px;
		}
		return null;
	}

	/**
	 * Normalise a CSS colour for comparison and storage: rgb() → #rrggbb, rgba() with alpha 1 → #rrggbb,
	 * other rgba() kept as rgba(r,g,b,a). Hex is lower-cased and expanded. Anything else (oklch, gradients,
	 * keywords) is returned trimmed — still valid CSS, just not comparable.
	 */
	public static function color( $v ) {
		$v = trim( (string) $v );
		if ( '' === $v ) { return ''; }
		if ( preg_match( '/^rgba?\(\s*([\d.]+)[,\s]+([\d.]+)[,\s]+([\d.]+)(?:\s*[,\/]\s*([\d.]+%?))?\s*\)$/i', $v, $m ) ) {
			$a = isset( $m[4] ) && '' !== $m[4] ? $m[4] : '1';
			$a = ( substr( $a, -1 ) === '%' ) ? ( (float) $a ) / 100 : (float) $a;
			$hex = sprintf( '#%02x%02x%02x', (int) round( $m[1] ), (int) round( $m[2] ), (int) round( $m[3] ) );
			if ( $a >= 0.999 ) { return $hex; }
			if ( $a <= 0.001 ) { return 'transparent'; }
			return sprintf( 'rgba(%d,%d,%d,%s)', (int) round( $m[1] ), (int) round( $m[2] ), (int) round( $m[3] ), rtrim( rtrim( number_format( $a, 3, '.', '' ), '0' ), '.' ) );
		}
		if ( preg_match( '/^#([0-9a-f]{3})$/i', $v, $m ) ) {
			$h = strtolower( $m[1] );
			return '#' . $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
		}
		if ( preg_match( '/^#[0-9a-f]{6}$/i', $v ) ) { return strtolower( $v ); }
		return $v;
	}

	/** Is this colour fully transparent (so it paints nothing)? */
	public static function is_transparent( $v ) {
		$c = self::color( $v );
		return '' === $c || 'transparent' === $c || 'rgba(0,0,0,0)' === $c;
	}

	/** First family of a font-family stack, unquoted: "\"DM Sans\", system-ui" → "DM Sans". */
	public static function first_family( $v ) {
		$v = trim( (string) $v );
		if ( '' === $v ) { return ''; }
		$first = trim( explode( ',', $v )[0] );
		return trim( $first, "\"' " );
	}
}
