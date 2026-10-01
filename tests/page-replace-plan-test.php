<?php
/**
 * Regression guard: retargeting a site to a NEW source replaces its pages — but only where approved.
 *
 * Converting a new source into an install that still held a PREVIOUS source's pages used to fork: `about`
 * was taken, so the new page became `about-2`. The guard behind that is right in principle — one source
 * must not silently eat another's page — but wrong as a default when the whole point is to retarget the
 * site: the live URL kept stale content while the fresh page hid at a slug nothing linked to, and every
 * reconvert added another duplicate.
 *
 * So the decision moved to the user. plan_collisions() reports WHOSE each colliding page is, and only the
 * slugs handed back in `import( …, array( 'replace' => … ) )` may cross a source boundary.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/page-replace-plan-test.php"
 *
 * Exit code 0 = all PASS, 1 = at least one FAIL. Creates its own pages and deletes them again.
 */

if ( ! class_exists( 'FW_Site_Converter_Pages' ) ) {
	fwrite( STDERR, "FAIL: site-converter not loaded (run inside a WP install with the plugin active)\n" );
	exit( 1 );
}

$fails = 0;
$ok    = function ( $cond, $msg ) use ( &$fails ) {
	if ( $cond ) { echo "  ✓ $msg\n"; } else { $fails++; echo "  ✗ FAIL: $msg\n"; }
};

$mk = function ( $slug, $src ) {
	$id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish',
		'post_title' => 'T ' . $slug, 'post_name' => $slug ) );
	if ( $src ) { update_post_meta( $id, '_upw_source_url', $src ); }
	return (int) $id;
};

$sfx   = '-pr' . substr( md5( (string) microtime( true ) ), 0, 6 );
$old   = $mk( 'oldsrc' . $sfx, 'https://previous.example/oldsrc' ); // a PREVIOUS source's page
$mine  = $mk( 'mine' . $sfx, '' );                                  // the user's own page
$same  = $mk( 'same' . $sfx, 'https://new.example/same' );          // this source's page
$made  = array( $old, $mine, $same );

$specs = array(
	array( 'slug' => 'oldsrc' . $sfx, 'title' => 'Old',  'source_url' => 'https://new.example/oldsrc' ),
	array( 'slug' => 'mine' . $sfx,   'title' => 'Mine', 'source_url' => 'https://new.example/mine' ),
	array( 'slug' => 'same' . $sfx,   'title' => 'Same', 'source_url' => 'https://new.example/same' ),
	array( 'slug' => 'fresh' . $sfx,  'title' => 'Fresh','source_url' => 'https://new.example/fresh' ),
);

echo "\n== Retarget: plan, then replace only what was approved\n";
$rows = FW_Site_Converter_Pages::plan_collisions( $specs );
$by   = array();
foreach ( $rows as $r ) { $by[ $r['slug'] ] = $r; }

$ok( 3 === count( $rows ), 'only the COLLIDING pages are listed; a brand-new slug is not (got ' . count( $rows ) . ')' );
$ok( ! isset( $by[ 'fresh' . $sfx ] ), 'a page that does not exist yet is absent from the plan' );

$ok( 'converter' === ( $by[ 'oldsrc' . $sfx ]['provenance'] ?? '' ),
	'a PREVIOUS source\'s page reads as `converter`' );
$ok( true === ( $by[ 'oldsrc' . $sfx ]['recommended'] ?? null ),
	'…and is recommended for replacement (retargeting is the whole point)' );

$ok( 'user' === ( $by[ 'mine' . $sfx ]['provenance'] ?? '' ),
	'a page NOBODY converted reads as `user`' );
$ok( false === ( $by[ 'mine' . $sfx ]['recommended'] ?? null ),
	'…and is NOT recommended: replacing it would destroy their own work' );

$ok( 'same' === ( $by[ 'same' . $sfx ]['provenance'] ?? '' ),
	'this source\'s own page reads as `same` (an ordinary idempotent update)' );

// WITHOUT approval the cross-source guard still holds.
$r1 = FW_Site_Converter_Pages::import( array( 'pages' => array( $specs[0] ) ) );
$ok( ( $r1['pages'][0]['id'] ?? 0 ) !== $old,
	'NEGATIVE: with no approval a different source does NOT overwrite the existing page' );
if ( ! empty( $r1['pages'][0]['id'] ) ) { $made[] = (int) $r1['pages'][0]['id']; }

// WITH approval it updates that very page in place.
$r2 = FW_Site_Converter_Pages::import( array( 'pages' => array( $specs[0] ) ),
	array( 'replace' => array( 'oldsrc' . $sfx ) ) );
$ok( (int) ( $r2['pages'][0]['id'] ?? 0 ) === $old,
	'an APPROVED slug updates the existing page in place (no `-2` fork)' );
$ok( empty( $r2['pages'][0]['created'] ), '…and is reported as an update, not a creation' );

// Approval is per slug: approving one page must not license replacing another.
$r3 = FW_Site_Converter_Pages::import( array( 'pages' => array( $specs[1] ) ),
	array( 'replace' => array( 'oldsrc' . $sfx ) ) );
$ok( (int) ( $r3['pages'][0]['id'] ?? 0 ) === $mine,
	'NEGATIVE: a user page with no recorded source is still matched normally (approval is per slug)' );

foreach ( array_unique( $made ) as $id ) { wp_delete_post( $id, true ); }

echo $fails ? "\n✗ $fails FAILED\n" : "\n✓ ALL PASS — retargeting replaces only the pages the user approved\n";
exit( $fails ? 1 : 0 );
