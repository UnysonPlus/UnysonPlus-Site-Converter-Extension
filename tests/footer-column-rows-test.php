<?php
/**
 * Regression guard: a footer column keeps ALL its rows, its title rhythm, and ONE vertical inset.
 *
 * Three defects on one band, all measured against a real source whose footer is 370px tall and whose
 * conversion came back 495px:
 *
 *  1. A MIXED column lost its text. `<li>Erzgießereistraße 40</li><li>80335 München</li>` followed by a
 *     `tel:` link and the legal links is one column, but the emitter's nav branch walks `links` and RETURNS,
 *     so every plain-text row was dropped. The contact-column branch that would have kept them only fires
 *     when MOST links are tel:/mailto: — here one of four. The converted footer lost its street address and
 *     its city while the links beside them survived.
 *  2. The column TITLE's gap was the theme's. The footer heading element carries text and level only, so
 *     nothing conveyed the source's `mb-3`; the default 19px above AND below stood against 0 and 12.
 *  3. The band's inset was applied TWICE. `footer_padding_top/bottom` pads `.footer__body`, which WRAPS the
 *     main-footer band — writing the same measured 56px to both nested 56 inside 56.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/footer-column-rows-test.php"
 *
 * Exit code 0 = all PASS, 1 = at least one FAIL.
 */

if ( ! class_exists( 'FW_Site_Converter_Sources' ) ) {
	fwrite( STDERR, "FAIL: site-converter not loaded (run inside a WP install with the plugin active)\n" );
	exit( 1 );
}

$fails = 0;
$ok    = function ( $cond, $msg ) use ( &$fails ) {
	if ( $cond ) { echo "  \xe2\x9c\x93 $msg\n"; } else { $fails++; echo "  \xe2\x9c\x97 FAIL: $msg\n"; }
};

$INK = 'color:lab(25.76 0 0);font-family:Inter, sans-serif;font-size:16px;line-height:24px;text-align:start;';

/* A real footer: a brand column and three link columns, the last MIXING address text with links. */
$MARK = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" class="lucide lucide-map-pin" aria-hidden="true"><circle cx="12" cy="10" r="3"></circle></svg>';
$PHONE = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" class="lucide lucide-phone" aria-hidden="true"><path d="M13 16a1 1 0 0 0 1 0"></path></svg>';

/** $icon: a mark before the column title. A row given as array(label, href, icon?) carries its own. */
$col = function ( $title, $rows, $icon = '' ) use ( $INK ) {
	$lis = '';
	foreach ( $rows as $r ) {
		if ( is_array( $r ) ) {
			$ri = isset( $r[2] ) ? $r[2] : '';
			$lis .= '<li data-sc-cs="' . $INK . 'display:list-item;font-size:14px">' . $ri
				. '<a href="' . $r[1] . '" data-sc-cs="' . $INK . 'display:block;font-size:14px">' . $r[0] . '</a></li>';
		} else {
			$lis .= '<li data-sc-cs="' . $INK . 'display:list-item;font-size:14px">' . $r . '</li>';
		}
	}
	return '<div data-sc-cs="' . $INK . 'display:block">'
		. '<h4 class="font-semibold mb-3" data-sc-cs="' . $INK . 'display:block;font-size:14px;font-weight:600;margin:0px 0px 12px">' . $icon . $title . '</h4>'
		. '<ul data-sc-cs="' . $INK . 'display:block">' . $lis . '</ul></div>';
};

$FOOTER = '<footer data-sc-cs="' . $INK . 'display:block;height:370px">'
	. '<div class="container" data-sc-cs="' . $INK . 'display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:40px;padding:56px 20px 56px 20px;height:312px">'
	. '<div data-sc-cs="' . $INK . 'display:block"><img src="/logo.png" alt="Brand" width="167" height="40">'
	. '<p data-sc-cs="' . $INK . 'display:block;font-size:14px">Reparaturwerkstatt f&uuml;r Laptop und PC in M&uuml;nchen-Maxvorstadt.</p></div>'
	. $col( 'Leistungen', array( array( 'Laptop Reparatur', '/laptop' ), array( 'Desktop', '/desktop' ), array( 'Wunsch-PC', '/pc' ) ) )
	. $col( '&Ouml;ffnungszeiten', array( 'Mo&ndash;Fr: 11:00&ndash;18:00', 'Samstag: 11:00&ndash;13:00', 'Sonntag: geschlossen' ) )
	// THE MIXED COLUMN: two plain-text address rows, a tel: link, then two legal links.
	. $col( 'Werkstatt', array( 'Erzgie&szlig;ereistra&szlig;e 40', '80335 M&uuml;nchen', array( '089 542 444 34', 'tel:08954244434', $PHONE ), array( 'Impressum', '/impressum' ), array( 'Datenschutz', '/datenschutz' ) ), $MARK )
	. '</div></footer>';

$HTML = '<!DOCTYPE html><html><head><title>Repair</title></head><body><main>'
	. '<section data-sc-cs="' . $INK . 'display:block;padding:96px 24px"><h1 data-sc-cs="font-size:48px">Repair</h1>'
	. '<p data-sc-cs="font-size:16px;line-height:26px">Body copy long enough to count as a real paragraph here.</p></section></main>'
	. $FOOTER . '</body></html>';

$v = FW_Site_Converter_Sources::build_from_html( $HTML, 'Repair', array( 'dynamic_chrome' => true ) )['files']['theme-settings.json']['values'] ?? array();

/** Every li_text the footer columns carry. */
$texts = array();
$walk = function ( $n ) use ( &$walk, &$texts ) {
	foreach ( (array) $n as $k => $x ) {
		if ( ! is_array( $x ) ) { continue; }
		if ( isset( $x['element_type']['list_item']['li_text'] ) ) { $texts[] = (string) $x['element_type']['list_item']['li_text']; }
		$walk( $x );
	}
};
$walk( (array) ( $v['main_footer_columns'] ?? array() ) );

echo "\n== A MIXED column keeps its text rows AND its links\n";

$ok( ! empty( $texts ), 'the footer columns produce list items at all (' . count( $texts ) . ')' );
$ok( in_array( 'Erzgießereistraße 40', $texts, true ),
	'the street address survives (texts: ' . mb_substr( implode( ' | ', $texts ), 0, 110 ) . ')' );
$ok( in_array( '80335 München', $texts, true ),
	'...and the city' );
$ok( in_array( '089 542 444 34', $texts, true ),
	'...alongside the phone' );
$ok( in_array( 'Impressum', $texts, true ) && in_array( 'Datenschutz', $texts, true ),
	'...and the legal links are NOT displaced by them' );

echo "\n== ...in the source's own order\n";

$ia = array_search( 'Erzgießereistraße 40', $texts, true );
$ip = array_search( '089 542 444 34', $texts, true );
$il = array_search( 'Impressum', $texts, true );
$ok( false !== $ia && false !== $ip && false !== $il && $ia < $ip && $ip < $il,
	'address → phone → legal, as the source lists them (got ' . var_export( array( $ia, $ip, $il ), true ) . ')' );

echo "\n== NEGATIVE: a pure NAV column is unchanged\n";

$ok( in_array( 'Laptop Reparatur', $texts, true ) && in_array( 'Desktop', $texts, true ) && in_array( 'Wunsch-PC', $texts, true ),
	'NEGATIVE: the links-only column still yields exactly its links' );

echo "\n== The column title's own gap is carried\n";

$css = (string) ( $v['misc_custom_css']['custom_css'] ?? '' );
$ok( false !== strpos( $css, 'footer-links-title' ),
	'a rule is emitted for the column titles' );
$ok( (bool) preg_match( '/footer-links-title[^}]*margin-bottom:\s*12px/', $css ),
	'...carrying the source\'s 12px `mb-3`, not the theme\'s default' );
$ok( (bool) preg_match( '/footer-links-title[^}]*margin-top:\s*0/', $css ),
	'...and zeroing the default space ABOVE it, which the source does not have' );

echo "\n== A column title's own MARK, and a row's own, are carried\n";

// The footer Heading element had no icon field at all, so a pin over an address column or a clock over
// opening hours was dropped on every such column — three missing images on a real four-column footer.
// A ROW's mark (the phone glyph beside a number) is the same story; list_item has always had `li_icon`.
$icons = array( 'heading' => 0, 'item' => 0 );
$iwalk = function ( $n ) use ( &$iwalk, &$icons ) {
	foreach ( (array) $n as $x ) {
		if ( ! is_array( $x ) ) { continue; }
		$et = $x['element_type'] ?? null;
		if ( is_array( $et ) ) {
			if ( ! empty( $et['heading']['heading_icon']['markup'] ) ) { $icons['heading']++; }
			if ( ! empty( $et['list_item']['li_icon']['markup'] ) ) { $icons['item']++; }
		}
		$iwalk( $x );
	}
};
$iwalk( (array) ( $v['main_footer_columns'] ?? array() ) );

$ok( $icons['heading'] >= 1,
	'the column title keeps its leading mark as the native Heading Icon (got ' . $icons['heading'] . ')' );
$ok( $icons['item'] >= 1,
	'...and the phone row keeps its own glyph as the list item\'s icon (got ' . $icons['item'] . ')' );

echo "\n== The band's vertical inset is applied ONCE\n";

$g_top = (string) ( $v['footer_padding_top'] ?? '' );
$band  = $v['main_footer_custom_styling']['yes']['main_footer_padding']['padding']['top'] ?? '';
$ok( '' !== $g_top,
	'the footer carries the source\'s 56px inset (got "' . $g_top . '")' );
$ok( '' === (string) $band,
	'NEGATIVE: ...and the BAND inside it does not repeat it — 56 nested in 56 doubled the footer (got "' . (string) $band . '")' );

echo $fails ? "\n\xe2\x9c\x97 $fails FAILED\n" : "\n\xe2\x9c\x93 ALL PASS - the footer keeps every row, the title rhythm, and one inset\n";
exit( $fails ? 1 : 0 );
