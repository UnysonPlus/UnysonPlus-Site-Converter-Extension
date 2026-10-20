<?php
/**
 * Regression guard: a chip that the TOPBAR already shows is not repeated in the main row.
 *
 * The header is assembled in two passes. Text chips detected on the header (`detect_header` → chips) become
 * `list_item` elements in `header_main.main_right`; the topbar row is built afterwards from its own left /
 * centre / right cells as `custom_html`.
 *
 * When the SAME element is both — a badge that lives in the topbar — it is emitted twice. Measured on a
 * captured header, "State Bar Certified Personal Injury Specialists" rendered once in the topbar at x=52 and
 * again in the main row at x=992, beside the phone button the source has there alone.
 *
 * The topbar is the authority for its own content, so a main-row chip whose text the topbar already carries
 * is dropped. Only an exact text match counts: two chips that merely share a word are different chips.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/header-chip-not-duplicated-in-topbar-test.php"
 *
 * Exit code 0 = all PASS, 1 = at least one FAIL.
 */

if ( ! class_exists( 'FW_Site_Converter_Stitch' ) ) {
	fwrite( STDERR, "FAIL: site-converter not loaded\n" );
	exit( 1 );
}

$fails = 0;
$ok    = function ( $cond, $msg ) use ( &$fails ) {
	if ( $cond ) { echo "  \xe2\x9c\x93 $msg\n"; } else { $fails++; echo "  \xe2\x9c\x97 FAIL: $msg\n"; }
};

$fn = new ReflectionMethod( 'FW_Site_Converter_Stitch', 'drop_chips_shown_in_topbar' );
$fn->setAccessible( true );

$chip = function ( $text ) {
	return array(
		'element_type'      => array( 'element' => 'list_item', 'list_item' => array( 'li_text' => $text, 'li_link_type' => 'none', 'li_link' => '' ) ),
		'element_css_class' => 'sc-hdr-chip',
	);
};
$cta = array( 'element_type' => array( 'element' => 'cta_button', 'cta_button' => array( 'cta_text' => '415-789-2200', 'cta_link' => 'tel:4157892200' ) ) );
$labels = function ( $rows ) {
	$out = array();
	foreach ( (array) $rows as $r ) {
		$e = (string) ( $r['element_type']['element'] ?? '' );
		$out[] = 'list_item' === $e
			? (string) ( $r['element_type']['list_item']['li_text'] ?? '' )
			: ( 'cta_button' === $e ? 'CTA:' . (string) ( $r['element_type']['cta_button']['cta_text'] ?? '' ) : $e );
	}
	return $out;
};

$BADGE = 'State Bar Certified Personal Injury Specialists';
$topbar_html = '<span style="color:#fff"><span style="background:#0c1f40;padding:2px 12px">' . $BADGE . '</span></span>';

echo "\n== The measured shape: the topbar badge repeated in the main row\n";

$rows = $fn->invoke( null, array( $chip( $BADGE ), $cta ), $topbar_html );
$got  = $labels( $rows );
$ok( ! in_array( $BADGE, $got, true ), 'the duplicate chip is dropped: ' . implode( ' | ', $got ) );
$ok( in_array( 'CTA:415-789-2200', $got, true ), 'and the CTA the source really has there survives' );
$ok( 1 === count( $rows ), 'the row holds exactly the one element (got ' . count( $rows ) . ')' );

echo "\n== NEGATIVE: a chip the topbar does NOT show is kept\n";

$other = $fn->invoke( null, array( $chip( 'Free consultation' ), $cta ), $topbar_html );
$ok( in_array( 'Free consultation', $labels( $other ), true ), 'an unrelated chip stays in the main row' );

echo "\n== NEGATIVE: a shared WORD is not a duplicate\n";

$near = $fn->invoke( null, array( $chip( 'Certified specialists' ), $cta ), $topbar_html );
$ok( in_array( 'Certified specialists', $labels( $near ), true ), 'overlapping words are not the same chip' );

echo "\n== NEGATIVE: nothing to compare against changes nothing\n";

$none = $fn->invoke( null, array( $chip( $BADGE ), $cta ), '' );
$ok( 2 === count( $none ), 'an empty topbar drops nothing' );
$ok( in_array( $BADGE, $labels( $none ), true ), 'and the chip is left where it was' );

echo "\n== NEGATIVE: only CHIPS are considered, never the CTA\n";

// A CTA whose label happens to appear in the topbar (a phone number shown in both) is still the main row's.
$phone_tb = '<span>Call 415-789-2200</span>';
$keep_cta = $fn->invoke( null, array( $cta ), $phone_tb );
$ok( 1 === count( $keep_cta ), 'the CTA is never dropped by this rule' );

printf( "\n%s\n", $fails ? "{$fails} FAIL(S)" : 'ALL PASS' );
exit( $fails ? 1 : 0 );
