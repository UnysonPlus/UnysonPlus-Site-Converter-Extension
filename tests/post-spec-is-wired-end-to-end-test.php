<?php
/**
 * Regression guard: the post path is actually WIRED, and it refuses when it should.
 *
 * WHY THIS FILE EXISTS. Post import shipped with every piece in place and nothing joining them.
 * `import_post()` stored a post correctly when handed a spec, discovery classified URLs as page / post /
 * archive, and `post_from_html()` read an article out of a rendered page — and `post_from_html()` had **no
 * callers**. Both unit goldens passed. The feature could not produce a single post end to end.
 *
 * So this pins the JOIN, not the parts:
 *
 *  - `post_spec_from_html()` turns a rendered article into a spec the pages importer accepts, and that spec
 *    round-trips into a real WordPress post.
 *  - It returns NULL when the page is not a dated article, so the caller falls through to the page path.
 *    Discovery's classification is evidence, not instruction: the extractor reads the page and may disagree.
 *  - The slug comes from the URL, because that is what the source's own permalink was.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/post-spec-is-wired-end-to-end-test.php"
 *
 * Exit code 0 = all PASS, 1 = at least one FAIL.
 */

if ( ! class_exists( 'FW_Site_Converter_Stitch' ) || ! class_exists( 'FW_Site_Converter_Pages' ) ) {
	fwrite( STDERR, "FAIL: site-converter not loaded\n" );
	exit( 1 );
}

$fails = 0;
$ok    = function ( $cond, $msg ) use ( &$fails ) {
	if ( $cond ) { echo "  \xe2\x9c\x93 $msg\n"; } else { $fails++; echo "  \xe2\x9c\x97 FAIL: $msg\n"; }
};

$ok( method_exists( 'FW_Site_Converter_Stitch', 'post_spec_from_html' ),
	'the public entry point exists (this is the join that was missing)' );

$prose = '';
for ( $i = 0; $i < 5; $i++ ) {
	$prose .= '<p>Paragraph ' . $i . ' of a real article, written at enough length to read as prose rather'
		. ' than as a teaser, which is the distinction the extractor measures.</p>';
}
$ARTICLE = '<!DOCTYPE html><html><head><title>Leadership | Example</title>'
	. '<meta property="article:published_time" content="2026-04-18T09:30:00+10:00">'
	. '<meta name="author" content="Jane Example">'
	. '<meta property="og:description" content="Why leadership is the invisible force.">'
	. '</head><body>'
	. '<header class="site-header"><nav><a href="/">Home</a></nav></header>'
	. '<main><article class="post"><h1>Leadership principles</h1>'
	. '<div class="entry-meta"><time datetime="2026-04-18T09:30:00+10:00">18 April 2026</time>'
	. '<a href="/category/blog/">Blog</a><a href="/tag/leadership/">Leadership</a></div>'
	. '<div class="entry-content">' . $prose . '<h2>Why it matters</h2></div>'
	. '</article></main>'
	. '<footer class="site-footer"><p>&copy; 2026 Example</p></footer></body></html>';

$URL = 'https://example-domain.test/leadership-principles-the-invisible-force/';

echo "\n== The spec\n";

$spec = FW_Site_Converter_Stitch::post_spec_from_html( $ARTICLE, $URL, 'Fallback' );
$ok( is_array( $spec ), 'a rendered article yields a spec' );
if ( is_array( $spec ) ) {
	$ok( 'post' === ( $spec['kind'] ?? '' ), 'it is marked kind=post (so the importer routes it)' );
	$ok( 'leadership-principles-the-invisible-force' === ( $spec['slug'] ?? '' ),
		'the slug comes from the source URL (' . ( $spec['slug'] ?? '?' ) . ')' );
	$ok( 'Leadership principles' === ( $spec['title'] ?? '' ), 'the title is the article h1, not the fallback' );
	$ok( $URL === ( $spec['source_url'] ?? '' ), 'the source URL rides along as the idempotency key' );
	$ok( false !== strpos( (string) ( $spec['content'] ?? '' ), 'Paragraph 0 of a real article' ), 'the body is carried' );
	$ok( false === strpos( (string) ( $spec['content'] ?? '' ), 'site-footer' ), 'without the chrome' );
}

echo "\n== ...round-trips into a real post\n";

$slug = (string) ( $spec['slug'] ?? '' );
foreach ( get_posts( array( 'name' => $slug, 'post_type' => array( 'post', 'page' ), 'post_status' => 'any', 'numberposts' => 5 ) ) as $pp ) {
	wp_delete_post( (int) $pp->ID, true );
}
FW_Site_Converter_Pages::import( array( 'pages' => array( $spec ) ) );
$found = get_posts( array( 'name' => $slug, 'post_type' => 'post', 'post_status' => 'any', 'numberposts' => 1 ) );
$ok( ! empty( $found ), 'a post exists at that slug' );
if ( ! empty( $found ) ) {
	$p = $found[0];
	$ok( 'post' === $p->post_type, 'as a post, not a page (' . $p->post_type . ')' );
	$ok( false !== strpos( $p->post_content, 'Paragraph 0 of a real article' ), 'with its body in post_content' );
	$ok( false !== strpos( (string) $p->post_date, '2026-04-18' ), 'and the source date (' . $p->post_date . ')' );
	$cats = wp_get_post_terms( (int) $p->ID, 'category', array( 'fields' => 'names' ) );
	$ok( is_array( $cats ) && in_array( 'Blog', $cats, true ), 'and its category (' . implode( ', ', (array) $cats ) . ')' );
}
foreach ( get_posts( array( 'name' => $slug, 'post_type' => array( 'post', 'page' ), 'post_status' => 'any', 'numberposts' => 5 ) ) as $pp ) {
	wp_delete_post( (int) $pp->ID, true );
}

echo "\n== NEGATIVE: the extractor may disagree with the classification, and then it refuses\n";

// Discovery can only read the sitemap; the extractor reads the page. A URL filed under posts that turns out
// to be an undated landing page must come back NULL so the caller converts it as a page — the behaviour that
// existed before posts. A refusal is the cheap error; a post minted from a teaser card is the expensive one.
$LANDING = '<!DOCTYPE html><html><head><title>Objects</title></head><body><main>'
	. '<article><h1>Objects of Quiet Beauty</h1><div class="entry-content">' . $prose . '</div></article>'
	. '</main></body></html>';
$ok( null === FW_Site_Converter_Stitch::post_spec_from_html( $LANDING, 'https://example-domain.test/objects/', 'Objects' ),
	'an undated page classified as a post is refused, not converted into one' );

$CARD_LIST = '<!DOCTYPE html><html><head><title>Journal</title></head><body><main>'
	. '<article><a href="/post/2"><h3>A teaser</h3></a><p>One promo line, May 20, 2026.</p></article>'
	. '<article><a href="/post/3"><h3>Another teaser</h3></a><p>Another promo line, May 15, 2026.</p></article>'
	. '</main></body></html>';
$ok( null === FW_Site_Converter_Stitch::post_spec_from_html( $CARD_LIST, 'https://example-domain.test/journal/', 'Journal' ),
	'...and a listing of dated teaser cards is refused too (a date alone is not enough)' );

printf( "\n%s\n", $fails ? "{$fails} FAIL(S)" : 'ALL PASS' );
exit( $fails ? 1 : 0 );
