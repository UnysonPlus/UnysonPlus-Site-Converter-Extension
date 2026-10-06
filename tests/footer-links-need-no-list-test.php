<?php
/**
 * Regression guard: a footer's links are its links whether or not they are a <ul>.
 *
 * `footer_link_css()` carries the footer links' residual typography — family, transform, letter-spacing,
 * weight, size, line-height — as a scoped `.footer-column .footer-link` rule. It found its sample by walking
 * the footer's <li> elements and taking the first <a> inside one.
 *
 * Measured on a captured footer: 0 <li> elements and 8 <a>s. A page builder renders each footer link as its
 * own button widget — `div.widget-container > div.button-wrapper > a` — so there is no list to walk, the
 * lookup returned nothing, and the function returned ''. Not one footer-link rule reached the generated
 * theme, and every measured value was lost at once:
 *
 *     source     font-weight 500   line-height 21px     text-align center
 *     converted  font-weight 400   line-height 24.08px  text-align start      (all theme defaults)
 *
 * So the sample falls back to scanning the footer's own <a> elements when no list item holds one, under the
 * guards the list walk already applied: short link text (<= 4 words) and not a social icon link.
 *
 * Two further things this pins, both measured on the same footer:
 *
 *   - TEXT-ALIGN was never carried at all. A footer whose links centre under their column heading rendered
 *     left-aligned, which is a visible misalignment rather than a subtle one.
 *   - LINE-HEIGHT was only ever applied to the list items and the list-item text span. The theme sets its own
 *     line-height on `.footer-link` itself, which out-ranks anything inherited from the <li>, so the measured
 *     value has to land on the link too.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/footer-links-need-no-list-test.php"
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

$fn = new ReflectionMethod( 'FW_Site_Converter_Stitch', 'footer_link_css' );
$fn->setAccessible( true );

$LINK_CS = 'font-size:14px;line-height:21px;font-weight:500;text-align:center;color:rgb(27,143,209)';

/** A footer whose links are BUTTON WIDGETS — no list anywhere, which is the measured shape. */
$widget_footer = '<!DOCTYPE html><html><body><footer data-sc-cs="display:block">'
	. '<div class="footer-col" data-sc-cs="display:block;text-align:center">'
	. '<div class="widget-container" data-sc-cs="display:block"><div class="button-wrapper" data-sc-cs="display:block">'
	. '<a href="/how-it-works" class="btn" data-sc-cs="' . $LINK_CS . '">How it works</a></div></div>'
	. '<div class="widget-container" data-sc-cs="display:block"><div class="button-wrapper" data-sc-cs="display:block">'
	. '<a href="/schedule" class="btn" data-sc-cs="' . $LINK_CS . '">Schedule a Call</a></div></div>'
	. '</div></footer></body></html>';

/** The same footer as a real list — the shape that already worked and must not move. */
$list_footer = '<!DOCTYPE html><html><body><footer data-sc-cs="display:block">'
	. '<div class="footer-col" data-sc-cs="display:block"><ul data-sc-cs="display:block">'
	. '<li data-sc-cs="display:list-item"><a href="/how-it-works" data-sc-cs="' . $LINK_CS . '">How it works</a></li>'
	. '<li data-sc-cs="display:list-item"><a href="/schedule" data-sc-cs="' . $LINK_CS . '">Schedule a Call</a></li>'
	. '</ul></div></footer></body></html>';

echo "\n== The measured shape: footer links that are not a list\n";

$css = (string) $fn->invoke( null, $widget_footer );
$ok( '' !== trim( $css ), 'a listless footer yields a rule at all (was: empty)' );
$ok( false !== strpos( $css, '.footer-link' ), 'scoped to the footer link: ' . substr( $css, 0, 90 ) );

echo "\n== …carrying every value the source measured\n";

$ok( false !== strpos( $css, 'font-weight:500' ), 'the 500 weight is carried' );
$ok( false !== strpos( $css, 'font-size:14px' ), 'the 14px size is carried' );
$ok( false !== strpos( $css, 'line-height:21px' ), 'the 21px line-height is carried' );
$ok( false !== strpos( $css, 'text-align:center' ), 'and the centred alignment, which was never carried at all' );

echo "\n== The line-height lands on the LINK, not only on the list item\n";

// The theme sets line-height on .footer-link itself, which out-ranks anything inherited from the <li>.
$lh_pos = strpos( $css, 'line-height:21px' );
$seg    = false !== $lh_pos ? substr( $css, max( 0, $lh_pos - 160 ), 180 ) : '';
$ok( false !== strpos( $seg, '.footer-link' ), 'a .footer-link selector carries the line-height' );

echo "\n== NEGATIVE: the list shape still works exactly as it did\n";

$lcss = (string) $fn->invoke( null, $list_footer );
$ok( '' !== trim( $lcss ), 'a list footer still yields a rule' );
$ok( false !== strpos( $lcss, 'font-weight:500' ) && false !== strpos( $lcss, 'line-height:21px' ),
	'with the same measured values' );

echo "\n== NEGATIVE: nothing is invented for a footer with no links\n";

$bare = '<!DOCTYPE html><html><body><footer data-sc-cs="display:block">'
	. '<div class="footer-col" data-sc-cs="display:block"><p data-sc-cs="font-size:14px">All rights reserved.</p></div>'
	. '</footer></body></html>';
$ok( '' === trim( (string) $fn->invoke( null, $bare ) ), 'a linkless footer yields no rule' );

echo "\n== NEGATIVE: a social icon row is not the typography sample\n";

// Social links are icons, not labelled nav links; sampling one would carry an icon font's metrics.
$social = '<!DOCTYPE html><html><body><footer data-sc-cs="display:block">'
	. '<div class="footer-col" data-sc-cs="display:block">'
	. '<a href="https://facebook.com/x" data-sc-cs="font-size:9px;font-weight:900;line-height:9px"></a>'
	. '<a href="https://twitter.com/x" data-sc-cs="font-size:9px;font-weight:900;line-height:9px"></a>'
	. '</div></footer></body></html>';
$scss = (string) $fn->invoke( null, $social );
$ok( false === strpos( $scss, 'font-size:9px' ), 'an icon-only social row does not supply the metrics' );

echo "\n== NEGATIVE: a long prose link is not a nav link\n";

$prose = '<!DOCTYPE html><html><body><footer data-sc-cs="display:block">'
	. '<div class="footer-col" data-sc-cs="display:block">'
	. '<a href="/terms" data-sc-cs="font-size:11px;font-weight:300;line-height:33px">Read the full terms and conditions of service here</a>'
	. '</div></footer></body></html>';
$pcss = (string) $fn->invoke( null, $prose );
$ok( false === strpos( $pcss, 'line-height:33px' ), 'a sentence-length link is not sampled as a nav link' );

printf( "\n%s\n", $fails ? "{$fails} FAIL(S)" : 'ALL PASS' );
exit( $fails ? 1 : 0 );
