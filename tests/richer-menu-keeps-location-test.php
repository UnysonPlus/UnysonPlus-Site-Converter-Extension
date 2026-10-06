<?php
/**
 * Regression guard: the generated theme's menu bootstrap must not take a location away from a RICHER menu.
 *
 * Two different code paths build a menu for the same location, from the same capture, and they disagree:
 *
 *   - the converter's MENU IMPORTER parses the source's real <ul> and keeps the nesting, so it produces
 *     (on one measured source) 3 top-level items carrying 5 children;
 *   - the generated theme's BOOTSTRAP is baked from the capture's FLAT nav list, so it produces the same
 *     links with no structure — 8 items, all top-level.
 *
 * Neither knows the other exists, so whichever ran last won. The bootstrap claimed the location on the
 * first front-end request whenever its own per-slug flag was unset — and a reconvert can produce a NEW
 * theme slug (the slug is derived from the source's home-page title), which means a FRESH flag. So a
 * reconvert that renamed the theme silently replaced the nested menu with the flat one, and 9 links
 * overflowed a header bar built for 4. It read as a header "regression" with nothing in the diff to
 * explain it, because the generated theme was byte-identical — only the slug had changed.
 *
 * Comparing item counts settles it without either side having to learn about the other: a menu with MORE
 * items than the bootstrap's own carries structure the bootstrap lacks, so it stays. An equal or smaller
 * menu is a stale/demo assignment and is still replaced, which is what the flag was added for.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/richer-menu-keeps-location-test.php"
 *
 * Exit code 0 = all PASS, 1 = at least one FAIL.
 */

if ( ! class_exists( 'FW_Site_Converter_Theme_Generator' ) ) {
	fwrite( STDERR, "FAIL: site-converter theme generator not loaded\n" );
	exit( 1 );
}

$fails = 0;
$ok    = function ( $cond, $msg ) use ( &$fails ) {
	if ( $cond ) { echo "  \xe2\x9c\x93 $msg\n"; } else { $fails++; echo "  \xe2\x9c\x97 FAIL: $msg\n"; }
};

// ---------------------------------------------------------------------------
// Everything below mutates real menus + the nav_menu_locations theme_mod, so snapshot first and restore
// on ANY exit path (including a fatal), never on reaching the end of the file.
// ---------------------------------------------------------------------------
$loc_key   = 'sc_test_primary';
$fn        = 'sc_gold';
$suffix    = 'header_menu';
$flag      = $fn . '_' . $suffix . '_assigned';
$menu_name = 'SC Golden Header';

$snap_loc  = get_theme_mod( 'nav_menu_locations' );
$snap_flag = get_option( $flag, null );
$created   = array();

$restore = function () use ( &$created, $snap_loc, $snap_flag, $flag ) {
	foreach ( $created as $id ) { wp_delete_nav_menu( (int) $id ); }
	$created = array();
	if ( null === $snap_loc ) { remove_theme_mod( 'nav_menu_locations' ); } else { set_theme_mod( 'nav_menu_locations', $snap_loc ); }
	if ( null === $snap_flag ) { delete_option( $flag ); } else { update_option( $flag, $snap_flag ); }
};
register_shutdown_function( $restore );

if ( ! function_exists( 'wp_create_nav_menu' ) ) { require_once ABSPATH . 'wp-admin/includes/nav-menu.php'; }

// The bootstrap's own menu: the FLAT shape the capture's nav list produces — 4 items, no nesting.
$flat = array(
	array( 'label' => 'Join',    'url' => '/join',    'children' => array() ),
	array( 'label' => 'Why',     'url' => '/why',     'children' => array() ),
	array( 'label' => 'Tech',    'url' => '/tech',    'children' => array() ),
	array( 'label' => 'Contact', 'url' => '/contact', 'children' => array() ),
);

$gen = new ReflectionMethod( 'FW_Site_Converter_Theme_Generator', 'menu_bootstrap_code' );
$gen->setAccessible( true );
$code = (string) $gen->invoke( null, $fn, $suffix, $menu_name, $loc_key, $flat );

$fnname = $fn . '_bootstrap_' . $suffix;
if ( ! function_exists( $fnname ) ) {
	// The generator emits a function definition as text; the generated theme requires it. Evaluate the
	// real emitted string rather than a hand-written copy, so the test exercises the shipped code path.
	eval( $code ); // phpcs:ignore Squiz.PHP.Eval.Discouraged
}
$ok( function_exists( $fnname ), "generated bootstrap {$fnname}() is defined" );
if ( ! function_exists( $fnname ) ) { exit( 1 ); }

$assign = function ( $id ) use ( $loc_key ) {
	$l = (array) get_theme_mod( 'nav_menu_locations' );
	$l[ $loc_key ] = (int) $id;
	set_theme_mod( 'nav_menu_locations', $l );
};
$held_by = function () use ( $loc_key ) {
	$l = (array) get_theme_mod( 'nav_menu_locations' );
	return isset( $l[ $loc_key ] ) ? (int) $l[ $loc_key ] : 0;
};
$make = function ( $name, array $tops ) use ( &$created ) {
	$id = wp_create_nav_menu( $name );
	if ( is_wp_error( $id ) ) { return 0; }
	$created[] = (int) $id;
	foreach ( $tops as $t ) {
		$p = wp_update_nav_menu_item( (int) $id, 0, array(
			'menu-item-title'  => $t[0],
			'menu-item-url'    => home_url( '/' . sanitize_title( $t[0] ) ),
			'menu-item-status' => 'publish',
		) );
		for ( $i = 0; $i < (int) $t[1]; $i++ ) {
			wp_update_nav_menu_item( (int) $id, 0, array(
				'menu-item-title'     => $t[0] . ' child ' . ( $i + 1 ),
				'menu-item-url'       => home_url( '/c' . $i ),
				'menu-item-parent-id' => (int) $p,
				'menu-item-status'    => 'publish',
			) );
		}
	}
	return (int) $id;
};

echo "\n== A richer menu already on the location KEEPS it, even with the flag unset\n";

// The importer's shape: 3 top-level items carrying 5 children = 8 items, against the bootstrap's 4.
$rich = $make( 'SC Golden Imported', array( array( 'Join', 4 ), array( 'Why', 1 ), array( 'Tech', 0 ) ) );
$ok( $rich > 0, 'built the nested importer-style menu' );
$ok( 8 === count( (array) wp_get_nav_menu_items( $rich ) ), 'nested menu carries 8 items (3 top + 5 children)' );

$assign( $rich );
delete_option( $flag );              // exactly what a NEW theme slug produces: a fresh, unset flag.
call_user_func( $fnname );

$ok( $rich === $held_by(), 'nested menu still holds the location after the bootstrap ran (was: replaced by the flat one)' );

$own = wp_get_nav_menu_object( $menu_name );
$ok( $own && (int) $own->term_id !== $held_by(), 'the bootstrap still BUILT its own menu — it only declined to claim the location' );
if ( $own ) { $created[] = (int) $own->term_id; }

echo "\n== Running again is stable (no flag means no repeated attempt to take over)\n";
call_user_func( $fnname );
$ok( $rich === $held_by(), 'a second run leaves the nested menu in place' );

echo "\n== An EMPTY location takes the RICHEST menu available, not necessarily this theme\'s own\n";

// The window that caused the real regression: `nav_menu_locations` is a PER-THEME theme_mod, so the moment
// the converter switches to the generated theme the location is empty, and it stays empty until the menu
// importer runs seconds later. A request landing in that window used to claim it with the baked FLAT menu.
$l = (array) get_theme_mod( 'nav_menu_locations' );
unset( $l[ $loc_key ] );
set_theme_mod( 'nav_menu_locations', $l );
delete_option( $flag );
call_user_func( $fnname );
$ok( $rich === $held_by(), 'an EMPTY location takes the nested importer menu, not the flat baked one' );

// From here each scenario needs the field to itself: with the rich menu still lying around unassigned the
// rule above would (correctly) keep choosing it, which is not what the negatives below are measuring.
wp_delete_nav_menu( $rich );
$created = array_values( array_diff( $created, array( $rich ) ) );

echo "\n== NEGATIVE: a POORER menu on the location is still replaced (the flag's original purpose)\n";
$poor = $make( 'SC Golden Demo', array( array( 'Sample Page', 0 ), array( 'Blog', 0 ) ) );
$ok( $poor > 0 && 2 === count( (array) wp_get_nav_menu_items( $poor ) ), 'built a 2-item demo menu' );
$assign( $poor );
delete_option( $flag );
call_user_func( $fnname );
$own = wp_get_nav_menu_object( $menu_name );
$ok( $own && (int) $own->term_id === $held_by(), 'the 2-item demo menu was replaced by the converted menu' );

echo "\n== NEGATIVE: an EMPTY location is still claimed\n";
$l = (array) get_theme_mod( 'nav_menu_locations' );
unset( $l[ $loc_key ] );
set_theme_mod( 'nav_menu_locations', $l );
delete_option( $flag );
call_user_func( $fnname );
$own = wp_get_nav_menu_object( $menu_name );
$ok( $own && (int) $own->term_id === $held_by(), 'an unassigned location is claimed by the converted menu' );

echo "\n== NEGATIVE: a DANGLING location (menu deleted by a reconvert) is reclaimed\n";
$gone = $make( 'SC Golden Doomed', array( array( 'A', 0 ), array( 'B', 0 ), array( 'C', 0 ), array( 'D', 0 ), array( 'E', 0 ) ) );
$assign( $gone );
wp_delete_nav_menu( $gone );
$created = array_values( array_diff( $created, array( $gone ) ) );
update_option( $flag, 1 );          // flag SET, as an earlier run of the same slug would leave it.
call_user_func( $fnname );
$own = wp_get_nav_menu_object( $menu_name );
$ok( $own && (int) $own->term_id === $held_by(), 'a location pointing at a deleted menu is reclaimed despite the flag' );

printf( "\n%s\n", $fails ? "{$fails} FAIL(S)" : 'ALL PASS' );
exit( $fails ? 1 : 0 );
