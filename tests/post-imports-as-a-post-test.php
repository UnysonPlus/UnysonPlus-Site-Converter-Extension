<?php
/**
 * Regression guard: a converted blog article is imported as a POST, not a page.
 *
 * Every converted URL was inserted with `post_type => 'page'` — two hardcoded places, no notion of posts. An
 * article imported that way is invisible where a reader looks for it: absent from the blog index, the feed,
 * its category and its tag. On a real source that is 120 articles in the wrong place, each one carrying a
 * builder tree nobody wants to edit.
 *
 * A post spec is imported as a post: title, body into `post_content` for the editor, the source's published
 * date, its excerpt, its categories and tags as real terms, and the author recorded as metadata.
 *
 * Two deliberate restraints, because a conversion must not take liberties with the site it lands on:
 *
 * - NO WordPress USER is created for the author. The source gives us a display name, not an account; minting
 *   users as a side effect of an import is not something anyone asked for. The name rides as post meta.
 * - Re-importing the SAME source URL updates that post rather than making a second one, which is how the
 *   page path already behaves.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/post-imports-as-a-post-test.php"
 *
 * Exit code 0 = all PASS, 1 = at least one FAIL.
 */

if ( ! class_exists( 'FW_Site_Converter_Pages' ) ) {
	fwrite( STDERR, "FAIL: site-converter pages importer not loaded\n" );
	exit( 1 );
}

$fails = 0;
$ok    = function ( $cond, $msg ) use ( &$fails ) {
	if ( $cond ) { echo "  \xe2\x9c\x93 $msg\n"; } else { $fails++; echo "  \xe2\x9c\x97 FAIL: $msg\n"; }
};

$SLUG = 'upw-test-leadership-principles';
$SRC  = 'https://example-domain.test/' . $SLUG;

// Clean up anything a previous run left, so the test says the same thing every time.
$prev = get_posts( array( 'name' => $SLUG, 'post_type' => array( 'post', 'page' ), 'post_status' => 'any', 'numberposts' => 5 ) );
foreach ( $prev as $pp ) { wp_delete_post( (int) $pp->ID, true ); }

$spec = array(
	'kind'       => 'post',
	'title'      => 'Leadership principles',
	'slug'       => $SLUG,
	'source_url' => $SRC,
	'content'    => '<p>Leadership is the invisible force.</p><h2>Why it matters</h2><p>Teams follow clarity.</p>',
	'date'       => '2026-04-18T09:30:00+10:00',
	'author'     => 'Jane Example',
	'excerpt'    => 'Why leadership is the invisible force.',
	'categories' => array( 'Blog' ),
	'tags'       => array( 'Leadership' ),
);

$res = FW_Site_Converter_Pages::import( array( 'pages' => array( $spec ) ) );

echo "\n== It lands as a post\n";

$found = get_posts( array( 'name' => $SLUG, 'post_type' => 'post', 'post_status' => 'any', 'numberposts' => 1 ) );
$ok( ! empty( $found ), 'a post exists at that slug' );

$post = ! empty( $found ) ? $found[0] : null;
if ( $post ) {
	$ok( 'post' === $post->post_type, 'its post type is post, not page (' . $post->post_type . ')' );
	$ok( 'Leadership principles' === $post->post_title, 'the title is carried' );
	$ok( false !== strpos( $post->post_content, 'invisible force' ), 'the body is in post_content, where the editor reads it' );
	$ok( false !== strpos( $post->post_content, '<h2>' ), '…as editor HTML' );
	$ok( false !== strpos( (string) $post->post_date, '2026-04-18' ), 'the source published date is kept (' . $post->post_date . ')' );
	$ok( false !== strpos( (string) $post->post_excerpt, 'invisible force' ), 'the excerpt is carried' );

	echo "\n== …with its taxonomy\n";
	$cats = wp_get_post_terms( (int) $post->ID, 'category', array( 'fields' => 'names' ) );
	$tags = wp_get_post_terms( (int) $post->ID, 'post_tag', array( 'fields' => 'names' ) );
	$ok( is_array( $cats ) && in_array( 'Blog', $cats, true ), 'its category is set (' . implode( ', ', (array) $cats ) . ')' );
	$ok( is_array( $tags ) && in_array( 'Leadership', $tags, true ), 'and its tag (' . implode( ', ', (array) $tags ) . ')' );

	echo "\n== …and the restraints\n";
	$ok( 'Jane Example' === (string) get_post_meta( (int) $post->ID, '_upw_source_author', true ), 'the author name is recorded as meta' );
	$ok( null === get_user_by( 'login', 'Jane Example' ) || false === get_user_by( 'login', 'Jane Example' ), 'no WordPress user was minted for the author' );
	$ok( $SRC === (string) get_post_meta( (int) $post->ID, '_upw_source_url', true ), 'the source URL is recorded, as it is for pages' );

	echo "\n== Re-importing the same article updates it\n";
	$before = (int) $post->ID;
	$spec2  = $spec;
	$spec2['title'] = 'Leadership principles, revised';
	FW_Site_Converter_Pages::import( array( 'pages' => array( $spec2 ) ) );
	$again  = get_posts( array( 'name' => $SLUG, 'post_type' => 'post', 'post_status' => 'any', 'numberposts' => 5 ) );
	$ok( 1 === count( $again ), 'there is still exactly one post (' . count( $again ) . ')' );
	$ok( ! empty( $again ) && (int) $again[0]->ID === $before, 'and it is the same post, updated' );
	$ok( ! empty( $again ) && 'Leadership principles, revised' === $again[0]->post_title, 'carrying the new title' );
}

echo "\n== NEGATIVE: a page spec is untouched by any of this\n";

$pslug = 'upw-test-about-page';
foreach ( get_posts( array( 'name' => $pslug, 'post_type' => array( 'post', 'page' ), 'post_status' => 'any', 'numberposts' => 5 ) ) as $pp ) { wp_delete_post( (int) $pp->ID, true ); }
FW_Site_Converter_Pages::import( array( 'pages' => array( array( 'title' => 'About', 'slug' => $pslug, 'builder' => array() ) ) ) );
$pg = get_posts( array( 'name' => $pslug, 'post_type' => 'page', 'post_status' => 'any', 'numberposts' => 1 ) );
$ok( ! empty( $pg ) && 'page' === $pg[0]->post_type, 'a spec with no kind still imports as a page' );

// tidy up
foreach ( get_posts( array( 'name' => $SLUG,  'post_type' => array( 'post', 'page' ), 'post_status' => 'any', 'numberposts' => 5 ) ) as $pp ) { wp_delete_post( (int) $pp->ID, true ); }
foreach ( get_posts( array( 'name' => $pslug, 'post_type' => array( 'post', 'page' ), 'post_status' => 'any', 'numberposts' => 5 ) ) as $pp ) { wp_delete_post( (int) $pp->ID, true ); }

printf( "\n%s\n", $fails ? "{$fails} FAIL(S)" : 'ALL PASS' );
exit( $fails ? 1 : 0 );
