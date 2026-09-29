<?php
/**
 * Guard for MENU LINK LOCALIZATION — the converted site's nav must point at the converted site.
 *
 * The defect this exists for: `resolve_target()` decided whether a link was internal by comparing its host
 * to `home_url()` — the DESTINATION. Every link the source site made to its own pages therefore classified
 * as EXTERNAL and was imported verbatim, so a converted site's primary menu pointed at the live original.
 * Measured on a real conversion: clicking "Financing" on the converted site navigated to the source domain,
 * and the 200 it returned came from the source, which is why nothing looked broken in a status check.
 *
 * "Internal" has to be judged against the site the markup CAME FROM, not the site it landed on.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs --allow-root eval-file \
 *     "D:/Web Dev/unysonplus/framework/extensions/site-converter/tests/menus-test.php"
 */

if ( ! class_exists( 'FW_Site_Converter_Menus' ) ) {
	fwrite( STDERR, "Site Converter not loaded — activate the extension first.\n" );
	exit( 1 );
}

// $GLOBALS, not `global`: wp-cli's eval-file runs this inside a FUNCTION, so a top-level $pass is not a
// global and `global $pass` bound a separate, always-zero variable. Every assertion printed PASS while the
// summary read "(0 passed, 0 failed)" -- and, worse, the exit code was computed from that zero, so a real
// failure would still have exited 0 and reported success.
$GLOBALS['__mt_pass'] = 0; $GLOBALS['__mt_fail'] = 0;
function mt( $label, $cond, $got = '' ) {
	if ( $cond ) { $GLOBALS['__mt_pass']++; echo "  PASS  $label\n"; }
	else { $GLOBALS['__mt_fail']++; echo "  FAIL  $label" . ( '' !== $got ? "  (got: $got)" : '' ) . "\n"; }
}

$SRC = 'https://source-fixture-01.example/';
FW_Site_Converter_Menus::set_source_origin( $SRC );

// A page the conversion created, so an internal link has something to resolve to.
$slug = 'mt-fixture-financing';
$existing = get_page_by_path( $slug );
$page_id = $existing ? (int) $existing->ID : (int) wp_insert_post( array(
	'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'MT Fixture Financing', 'post_name' => $slug,
) );

$menu_name = 'MT Fixture Menu';
$old = wp_get_nav_menu_object( $menu_name );
if ( $old ) { wp_delete_nav_menu( $old->term_id ); }

$res = FW_Site_Converter_Menus::import( array( array(
	'name'     => $menu_name,
	'location' => '',                       // no location: this test must not steal the live site's nav
	'items'    => array(
		array( 'label' => 'Financing',  'url' => $SRC . $slug ),              // source-absolute → the local page
		array( 'label' => 'Unbuilt',    'url' => $SRC . 'not-converted-yet' ), // source-absolute, no page → site-relative
		array( 'label' => 'Anchor',     'url' => $SRC . '#faq' ),              // source root + fragment → bare anchor
		array( 'label' => 'Elsewhere',  'url' => 'https://unrelated-third-party.example/docs' ), // genuinely external
		// A DEAD END: an overflow toggle arrives with no destination and no children below it.
		array( 'label' => 'More',       'url' => '#' ),
		// …but a real DROPDOWN PARENT also has url '#', and it must survive, because its children do.
		array( 'label' => 'Products',   'url' => '#', 'children' => array(
			array( 'label' => 'Widgets', 'url' => $SRC . 'not-converted-yet' ),
		) ),
	),
) ) );

$menu  = wp_get_nav_menu_object( $menu_name );
$items = $menu ? (array) wp_get_nav_menu_items( $menu->term_id, array( 'post_status' => 'any' ) ) : array();
$by    = array();
foreach ( $items as $it ) { $by[ $it->title ] = $it; }

// 4 real entries + the dropdown parent + its one child = 6; the dead end must NOT be among them.
mt( '[M1] the menu imported, dropping the dead end', 6 === count( $items ), 'items=' . count( $items ) );

$titles = array();
foreach ( $items as $it ) { $titles[] = $it->title; }
mt( '[M7] a nav entry with no destination AND no children is dropped', ! in_array( 'More', $titles, true ), implode( ', ', $titles ) );
/* NEGATIVE: the rule is about the DESTINATION, not the control. A real dropdown parent also carries url
   '#'; dropping those would delete every menu with a submenu. Its children are what earn it a place. */
mt( '[M8] NEGATIVE: a dropdown parent with children survives the same rule', in_array( 'Products', $titles, true ) && in_array( 'Widgets', $titles, true ), implode( ', ', $titles ) );

// (a) a source link to a page that WAS converted becomes a real page link, not a URL.
$fin = $by['Financing'] ?? null;
mt( '[M2] a source-absolute link to a converted page resolves to that PAGE',
	$fin && 'post_type' === $fin->type && (int) $fin->object_id === $page_id,
	$fin ? $fin->type . ' -> ' . $fin->url : '(item missing)' );

// (b) a source link with no page yet stays SITE-RELATIVE so it works on this domain.
$unb = $by['Unbuilt'] ?? null;
mt( '[M3] a source-absolute link with no page yet becomes site-relative (not the source domain)',
	$unb && false === stripos( (string) $unb->url, 'source-fixture-01.example' ),
	$unb ? $unb->url : '(item missing)' );

// (c) the source root + an anchor is an on-page anchor here, not a trip to the original.
$anc = $by['Anchor'] ?? null;
mt( '[M4] source root + fragment becomes a local anchor',
	$anc && false === stripos( (string) $anc->url, 'source-fixture-01.example' ) && false !== strpos( (string) $anc->url, '#faq' ),
	$anc ? $anc->url : '(item missing)' );

/* NEGATIVE: a genuinely third-party link must be left ALONE. The fix widens what counts as internal, and
   the way to get that wrong is to swallow every outbound link — a footer's real external references. */
$ext = $by['Elsewhere'] ?? null;
mt( '[M5] NEGATIVE: a real external link is left untouched',
	$ext && 'https://unrelated-third-party.example/docs' === (string) $ext->url,
	$ext ? $ext->url : '(item missing)' );

/* NEGATIVE: nothing in the imported menu may reference the source host at all. */
$offsite = 0;
foreach ( $items as $it ) { if ( false !== stripos( (string) $it->url, 'source-fixture-01.example' ) ) { $offsite++; } }
mt( '[M6] NEGATIVE: no menu item points back at the source site', 0 === $offsite, 'offsite=' . $offsite );

// clean up so the fixture never shows in a real site's menus
if ( $menu ) { wp_delete_nav_menu( $menu->term_id ); }
if ( ! $existing && $page_id ) { wp_delete_post( $page_id, true ); }

$pass = (int) $GLOBALS['__mt_pass'];
$fail = (int) $GLOBALS['__mt_fail'];
// A run that asserted NOTHING is not a pass. Without this, a harness bug that stops mt() being reached
// reports "PASS (0 passed)" -- silence dressed as success.
if ( 0 === $pass + $fail ) { echo "\nMENUS RESULT: FAIL -- no assertions ran\n"; exit( 1 ); }
echo "\n========================================\n";
echo 'MENUS RESULT: ' . ( 0 === $fail ? 'PASS' : 'FAIL' ) . "   ($pass passed, $fail failed)\n";
echo "========================================\n";
exit( 0 === $fail ? 0 : 1 );
