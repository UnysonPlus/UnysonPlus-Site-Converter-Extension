<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Recorded motion → native Interactions, the catch-all pass (roadmap 10b).
 *
 * The capture records each moving element's entrance frame by frame (motion-record.mjs) and stamps it on the element
 * as data-sc-motion. Stitch / Mapper carry it on the common routes (a recognised block, a grid cell, a section head:
 * motion_of → apply_motion). Elements built by other routes (a card a grid recogniser turned into an icon box, a wrapper
 * the converter flattened) would lose it, so this pass runs on the finished page: every stamped element whose motion is
 * not on the page yet is matched to the builder node with the same text (whitespace ignored) and gets the Interaction
 * there. A wrapper (div, li, article…) lands on the outermost layout node with that text, a text element on the
 * innermost element node. What cannot be placed (its text was merged into another element, or it has none) is
 * reported. JS twin: applyRecordedMotion (motion-record.mjs).
 */
class FW_Site_Converter_Motion {

	const WRAPPER_TAGS = array( 'div', 'section', 'article', 'li', 'figure', 'aside', 'header', 'footer', 'main', 'nav', 'ul', 'ol', 'form' );

	/** data-sc-motion → array, or null. */
	public static function parse( $v ) {
		$v = trim( (string) $v );
		if ( '' === $v ) { return null; }
		$o = array();
		foreach ( explode( ';', $v ) as $part ) { $i = strpos( $part, '=' ); if ( $i > 0 ) { $o[ substr( $part, 0, $i ) ] = substr( $part, $i + 1 ); } }
		if ( ! isset( $o['ph'] ) || ! in_array( $o['ph'], array( 'load', 'view' ), true ) ) { return null; }
		$e = isset( $o['e'] ) ? (string) $o['e'] : '';
		if ( ! preg_match( '/^(?:[a-z0-9_]+|cubic-bezier\([0-9.,\s-]+\))$/i', $e ) ) { $e = 'soft_land'; }
		$n = function ( $k, $d ) use ( $o ) { return isset( $o[ $k ] ) && is_numeric( $o[ $k ] ) ? (float) $o[ $k ] : $d; };
		return array( 'ph' => $o['ph'], 'd' => $n( 'd', 0 ), 'du' => $n( 'du', 0.6 ), 'e' => $e, 'o' => $n( 'o', 1 ), 'x' => $n( 'x', 0 ), 'y' => $n( 'y', 0 ), 's' => $n( 's', 1 ), 'r' => $n( 'r', 0 ), 'b' => $n( 'b', 0 ), 'src' => preg_replace( '/[^a-z-]/', '', (string) ( $o['src'] ?? '' ) ) );
	}

	/** Text for matching: tags gone, entities decoded, ALL whitespace removed (a card's textContent runs "One" + "First…" together). */
	public static function key( $s ) {
		$s = html_entity_decode( wp_strip_all_tags( (string) $s ), ENT_QUOTES, 'UTF-8' );
		return mb_substr( preg_replace( '/\s+/u', '', $s ), 0, 80 );
	}

	/** Every stamped element of a page's HTML: [ { tag, key, motion } ]. */
	public static function collect( $html ) {
		$out = array();
		if ( false === stripos( (string) $html, 'data-sc-motion' ) ) { return $out; }
		$doc = new DOMDocument();
		libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="utf-8"?>' . $html );
		libxml_clear_errors();
		$xp = new DOMXPath( $doc );
		foreach ( $xp->query( '//*[@data-sc-motion]' ) as $el ) {
			$m = self::parse( $el->getAttribute( 'data-sc-motion' ) );
			if ( ! $m ) { continue; }
			$out[] = array( 'tag' => strtolower( $el->nodeName ), 'key' => self::key( $el->textContent ), 'text' => mb_substr( trim( preg_replace( '/\s+/u', ' ', $el->textContent ) ), 0, 60 ), 'motion' => $m );
		}
		return $out;
	}

	/** The visible text of a builder node and everything inside it (its text-like atts, in order). */
	private static function node_text( array $n ) {
		$t = '';
		foreach ( (array) ( $n['atts'] ?? array() ) as $k => $v ) {
			if ( is_string( $v ) && preg_match( '/^(title|subtitle|text|content|label|desc|description|caption|button_label|quote)$/', (string) $k ) ) { $t .= $v; }
		}
		foreach ( (array) ( $n['_items'] ?? array() ) as $c ) { if ( is_array( $c ) ) { $t .= self::node_text( $c ); } }
		return $t;
	}

	private static function has_motion( array $n ) {
		$ix = $n['atts']['upw_interactions'] ?? null;
		return is_array( $ix ) && in_array( (string) ( $ix['mode'] ?? '' ), array( 'load', 'view' ), true );
	}

	/** Same-signature check: this node already carries this exact recorded motion. */
	private static function same( array $n, array $m ) {
		$ix = $n['atts']['upw_interactions'] ?? null;
		if ( ! is_array( $ix ) || empty( $ix['mode'] ) ) { return false; }
		$s = $ix[ $ix['mode'] ]['steps']['s1'] ?? array();
		return $ix['mode'] === ( 'view' === $m['ph'] ? 'view' : 'load' ) && abs( (float) ( $s['delay'] ?? -1 ) - (float) $m['d'] ) < 0.002 && abs( (float) ( $s['duration'] ?? -1 ) - (float) $m['du'] ) < 0.002;
	}

	/**
	 * Apply the stamped motions the page does not carry yet. Returns { applied, already, unplaced: [ { tag, text, why } ] }.
	 *
	 * @param array $tree   The page's builder items (by reference).
	 * @param array $stamps collect() output.
	 */
	public static function apply( array &$tree, array $stamps ) {
		$rep = array( 'applied' => 0, 'already' => 0, 'unplaced' => array() );
		if ( ! $stamps ) { return $rep; }
		// Index every node: its path, depth, text key, kind.
		$index = array();
		$walk  = function ( array &$items, $depth ) use ( &$walk, &$index ) {
			foreach ( $items as $i => &$n ) {
				if ( ! is_array( $n ) ) { continue; }
				$own = array(); // each of the node's own text fields: a heading's title alone matches its h1 (the subtitle came from a <p>)
				foreach ( (array) ( $n['atts'] ?? array() ) as $ak => $av ) { if ( is_string( $av ) && preg_match( '/^(title|subtitle|text|content|label|caption|button_label|quote)$/', (string) $ak ) ) { $kk = self::key( $av ); if ( '' !== $kk ) { $own[] = $kk; } } }
				$index[] = array( 'node' => &$n, 'own' => $own, 'depth' => $depth, 'key' => self::key( self::node_text( $n ) ), 'layout' => 'simple' !== ( $n['type'] ?? '' ), 'section' => 'section' === ( $n['type'] ?? '' ) || ( 'flexbox' === ( $n['type'] ?? '' ) && 'section' === ( $n['atts']['html_tag'] ?? '' ) ) );
				if ( ! empty( $n['_items'] ) && is_array( $n['_items'] ) ) { $walk( $n['_items'], $depth + 1 ); }
			}
			unset( $n );
		};
		$walk( $tree, 0 );
		foreach ( $stamps as $st ) {
			if ( '' === $st['key'] ) { $rep['unplaced'][] = array( 'tag' => $st['tag'], 'text' => $st['text'], 'why' => 'no text to match it by' ); continue; }
			$cands = array();
			foreach ( $index as $ix => $e ) {
				if ( ! $e['section'] && ( $e['key'] === $st['key'] || ( ! $e['layout'] && in_array( $st['key'], $e['own'], true ) ) ) ) { $cands[] = $ix; }
			}
			if ( ! $cands ) {
				$why = 'its text is not one element on the page (it was merged into another element)';
				foreach ( $index as $e ) { if ( '' !== $e['key'] && false !== strpos( $e['key'], $st['key'] ) && ! $e['section'] ) { $why = 'its text became part of a larger element (merged), so it has no element of its own to move'; break; } }
				$rep['unplaced'][] = array( 'tag' => $st['tag'], 'text' => $st['text'], 'why' => $why );
				continue;
			}
			$done = false;
			foreach ( $cands as $ix ) { if ( self::same( $index[ $ix ]['node'], $st['motion'] ) ) { $done = true; break; } }
			if ( $done ) { $rep['already']++; continue; }
			// A wrapper → the outermost layout node with that text; a text element → the innermost element node.
			$wrapper = in_array( $st['tag'], self::WRAPPER_TAGS, true );
			usort( $cands, function ( $a, $b ) use ( $index, $wrapper ) { return $wrapper ? $index[ $a ]['depth'] - $index[ $b ]['depth'] : $index[ $b ]['depth'] - $index[ $a ]['depth']; } );
			$pick = null;
			foreach ( $cands as $ix ) {
				if ( $wrapper && ! $index[ $ix ]['layout'] && count( $cands ) > 1 ) { continue; }
				if ( self::has_motion( $index[ $ix ]['node'] ) ) { continue; }
				$pick = $ix; break;
			}
			if ( null === $pick ) { $rep['unplaced'][] = array( 'tag' => $st['tag'], 'text' => $st['text'], 'why' => 'the element it matches already moves with its own recorded motion (its text was merged into that element)' ); continue; }
			$ixv = FW_Site_Converter_Mapper::motion_interaction( $st['motion'] );
			if ( $ixv ) { $index[ $pick ]['node']['atts']['upw_interactions'] = $ixv; $rep['applied']++; }
		}
		return $rep;
	}

	/** On a build bundle: apply to every page in files['pages.json'], record the report in files['motion-report.json']. */
	public static function apply_to_bundle( array &$bundle, $html ) {
		if ( empty( $bundle['files']['pages.json'] ) ) { return; }
		$stamps = self::collect( $html );
		if ( ! $stamps ) { return; }
		$raw = $bundle['files']['pages.json'];
		$pj  = is_array( $raw ) ? $raw : json_decode( (string) $raw, true );
		if ( ! is_array( $pj ) || empty( $pj['pages'] ) ) { return; }
		$report = array( 'stamped' => count( $stamps ), 'pages' => array() );
		foreach ( $pj['pages'] as $i => $pg ) {
			if ( empty( $pg['builder'] ) || ! is_array( $pg['builder'] ) ) { continue; }
			$tree = $pg['builder'];
			$r    = self::apply( $tree, $stamps );
			$pj['pages'][ $i ]['builder'] = $tree;
			$report['pages'][] = array( 'slug' => $pg['slug'] ?? '', 'applied' => $r['applied'], 'already' => $r['already'], 'unplaced' => $r['unplaced'] );
			break; // the recording is of the page the HTML is; one page per build
		}
		$bundle['files']['pages.json']         = is_array( $raw ) ? $pj : wp_json_encode( $pj );
		$bundle['files']['motion-report.json'] = is_array( $raw ) ? $report : wp_json_encode( $report );
	}
}
