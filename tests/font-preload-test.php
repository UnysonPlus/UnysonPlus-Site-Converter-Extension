<?php
/**
 * Regression guard: preload the few faces first paint needs — and only those.
 *
 * `font-display:swap` is the right value (see normalize_font_display: `optional` was tried and reverted
 * because a cold load rendered the headings in a fallback serif, which for a conversion tool is worse than
 * the shift). But `swap` does shift: the page paints in the fallback and relays every line when the real
 * face lands. Measured on a conversion: CLS 0.195, of which 0.194 was one hero block moving under a single
 * rehosted face. A preloaded face normally arrives before first paint, so there is nothing to swap.
 *
 * The whole difficulty is RESTRAINT, and this is where it went wrong twice while being written:
 *
 *  1. A loose "does this subset cover latin?" test matched `U+2DE0-2DFF` and preloaded the CYRILLIC subset
 *     instead of the latin one. A rule that matches everything fails exactly as badly as one that matches
 *     nothing, and here it costs a wasted font fetch on the critical path. Basic latin is the range that
 *     STARTS AT ZERO: `U+0000-00FF`, or `U+0-FF` in the shortened form some sources ship.
 *  2. Two different things duplicate. A family can arrive twice — once from the source's Google stylesheet
 *     and once from an inline @font-face the source also carried — which only a family+weight key collapses.
 *     And a VARIABLE font serves every weight from ONE file, which only a url check collapses. With either
 *     guard alone the same <head> preloaded the identical woff2 twice.
 *
 * The real capture behind this test rehosts 65 @font-face blocks across two families. Only 15 cover basic
 * latin and most of those are italic; preloading them all would fetch close to a megabyte of Cyrillic, Greek
 * and Vietnamese before anything rendered — far worse than the shift it set out to fix. The correct answer
 * is two files.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/font-preload-test.php"
 *
 * Exit code 0 = all PASS, 1 = at least one FAIL.
 */

if ( ! class_exists( 'FW_Site_Converter_Theme_Generator' ) ) {
	fwrite( STDERR, "FAIL: site-converter not loaded\n" );
	exit( 1 );
}

$fails = 0;
$ok    = function ( $cond, $msg ) use ( &$fails ) {
	if ( $cond ) { echo "  \xe2\x9c\x93 $msg\n"; } else { $fails++; echo "  \xe2\x9c\x97 FAIL: $msg\n"; }
};

$m = new ReflectionMethod( 'FW_Site_Converter_Theme_Generator', 'fonts_preload_tags' );
$m->setAccessible( true );
$pick = function ( array $fonts, $css ) use ( $m ) {
	return (array) $m->invoke( null, array( 'fonts' => $fonts ), $css );
};

/* Faces written as the rehoster emits them. `cyr` is the trap from bug (1): its range contains `U+2DE0`,
   which a loose latin test reads as a match. `var400`/`var700` are the same FILE at two weights — a variable
   font, the trap from bug (2a). `dupe` is the same family+weight arriving a second time from an inline
   face, bug (2b), and deliberately uses the shortened `U+0-FF` spelling of basic latin. */
$face = function ( $fam, $w, $file, $range, $style = 'normal' ) {
	return "@font-face{font-family:'$fam';font-style:$style;font-weight:$w;font-display:swap;"
		. "src:url(fonts/$file) format('woff2');unicode-range:$range;}";
};
$LATIN = 'U+0000-00FF, U+0131, U+0152-0153';
$SHORT = 'U+0-FF, U+131, U+152-153';
$CYR   = 'U+0460-052F, U+1C80-1C8A, U+20B4, U+2DE0-2DFF, U+A640-A69F';
$GREEK = 'U+0370-0377, U+037A-037F, U+0384-038A';

$css = implode( "\n", array(
	$face( 'Inter', 400, 'cyr.woff2', $CYR ),
	$face( 'Inter', 400, 'greek.woff2', $GREEK ),
	$face( 'Inter', 400, 'inter-latin.woff2', $LATIN ),
	$face( 'Inter', 700, 'inter-latin.woff2', $LATIN ),           // variable: same file, second weight
	$face( 'Inter', 400, 'inter-again.woff2', $SHORT ),            // same family+weight, second source
	$face( 'Inter', 400, 'inter-italic.woff2', $LATIN, 'italic' ),
	$face( 'Space Grotesk', 700, 'grotesk-latin.woff2', $LATIN ),
	$face( 'Space Grotesk', 700, 'grotesk-cyr.woff2', $CYR ),
) );

$fonts = array( 'heading' => 'Space Grotesk', 'body' => 'Inter', 'heading_weight' => 700 );

echo "\n== Only the faces first paint needs\n";

$got = $pick( $fonts, $css );
$ok( 2 === count( $got ), 'two families, two files (got ' . count( $got ) . ': ' . implode( ', ', $got ) . ')' );
$ok( in_array( 'fonts/inter-latin.woff2', $got, true ), '...the body family\'s latin face' );
$ok( in_array( 'fonts/grotesk-latin.woff2', $got, true ), '...and the heading family\'s' );

echo "\n== The subset test is exact, not merely present\n";

$ok( ! in_array( 'fonts/cyr.woff2', $got, true ),
	'a Cyrillic subset is NOT preloaded -- its range contains U+2DE0, which a loose latin test matches' );
$ok( ! in_array( 'fonts/greek.woff2', $got, true ), '...nor a Greek one' );
$ok( ! in_array( 'fonts/grotesk-cyr.woff2', $got, true ),
	'...and the same holds for the heading family, not just the first one scanned' );

echo "\n== Nothing is preloaded twice, for either reason it could be\n";

$ok( count( $got ) === count( array_unique( $got ) ), 'no file appears twice in the list' );
$ok( ! in_array( 'fonts/inter-again.woff2', $got, true ),
	'a family carried twice (Google stylesheet + inline @font-face) yields ONE preload' );
$ok( 1 === count( array_filter( $got, function ( $u ) { return false !== strpos( $u, 'inter' ); } ) ),
	'...and a variable font serving two weights from one file yields ONE preload, not one per weight' );

echo "\n== Italic is never on the first-paint path\n";

$ok( ! in_array( 'fonts/inter-italic.woff2', $got, true ), 'an italic face is not preloaded' );

echo "\n== Only families the page actually sets\n";

$ok( array() === $pick( array( 'heading' => 'Nonesuch', 'body' => 'Nonesuch' ), $css ),
	'a family the theme never sets is not preloaded because it happens to be in the CSS' );
$body_only = $pick( array( 'heading' => '', 'body' => 'Inter', 'heading_weight' => 700 ), $css );
$ok( array( 'fonts/inter-latin.woff2' ) === $body_only,
	'with no heading family, only the body face (got ' . implode( ', ', $body_only ) . ')' );

echo "\n== NEGATIVE: nothing to preload, nothing emitted\n";

$ok( array() === $pick( $fonts, '' ), 'NEGATIVE: no rehosted CSS → no preloads (the remote path is unchanged)' );
$ok( array() === $pick( $fonts, 'body{color:red}' ), 'NEGATIVE: CSS with no @font-face → no preloads' );

echo "\n== The cap holds\n";

$many = '';
foreach ( array( 'A', 'B', 'C', 'D', 'E', 'F' ) as $i => $f ) {
	$many .= $face( 'Fam' . $f, 400, 'f' . $i . '.woff2', $LATIN ) . "\n";
}
$capped = $m->invoke( null, array( 'fonts' => array( 'body' => 'FamA', 'heading' => 'FamB' ) ), $many );
$ok( count( (array) $capped ) <= 4,
	'at most four links reach the head, however many faces qualify (got ' . count( (array) $capped ) . ')' );

echo "\n== The generated theme code is valid PHP\n";

$fnm = new ReflectionMethod( 'FW_Site_Converter_Theme_Generator', 'functions_php' );
$fnm->setAccessible( true );
$php = (string) $fnm->invoke( null, array(
	'theme'          => array( 'slug' => 'demo-child', 'name' => 'Demo', 'mode' => 'child' ),
	'fonts'          => $fonts + array( 'google' => '', 'icons' => '' ),
	'header'         => array( 'menu_location' => 'primary', 'cta' => array() ),
	'rehosted_fonts' => array( 'css' => $css, 'families' => array() ),
) );
$ok( false !== strpos( $php, '_preload_fonts' ), 'functions.php carries the preload emitter' );
$ok( (bool) preg_match( '/add_action\(\s*\'wp_head\',\s*\'[a-z_]*_preload_fonts\',\s*1\s*\)/', $php ),
	'...on wp_head at priority 1, ahead of the stylesheet that references the face' );
$ok( false !== strpos( $php, 'crossorigin' ),
	'...with crossorigin, without which the browser fetches the face a SECOND time' );

$tmp = tempnam( sys_get_temp_dir(), 'scfn' ) . '.php';
file_put_contents( $tmp, $php );
$lint = array();
@exec( 'php -l ' . escapeshellarg( $tmp ) . ' 2>&1', $lint, $rc );
@unlink( $tmp );
$ok( 0 === (int) $rc, 'the generated functions.php parses (' . trim( implode( ' ', $lint ) ) . ')' );

echo $fails ? "\n\xe2\x9c\x97 $fails FAILED\n" : "\n\xe2\x9c\x93 ALL PASS - two files preloaded, not sixty-five\n";
exit( $fails ? 1 : 0 );
