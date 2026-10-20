<?php
/**
 * Regression guard: a blog POST is read as prose + metadata, not as a page layout.
 *
 * The converter produces a builder mapping for everything it touches. That is right for a designed page and
 * wrong for an article: a 600-word post becomes an unmaintainable builder tree, and as a WordPress PAGE it
 * never appears in the blog index, the feed, a category or a tag. A post wants its body in the editor and its
 * metadata on the post.
 *
 * `post_from_html()` reads one rendered post page and returns what a post is made of: title, body HTML, date,
 * author, excerpt, featured image, categories and tags.
 *
 * What it must get right, and why each is here:
 *
 * - The BODY is the article, not the page. A post template wraps the article in the site header, footer and
 *   nav, and usually follows it with share buttons, an author box, related posts and comments. All of that is
 *   chrome the converted site already has; pulled into post_content it would be duplicated on every article.
 * - The body is EDITOR HTML. Headings, paragraphs, lists, quotes, images and links survive; the source's
 *   classes, ids and data-* attributes do not, because they refer to a stylesheet the post will not be
 *   rendered under.
 * - The title is the ARTICLE's h1, not the site title.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/post-body-is-extracted-as-prose-test.php"
 *
 * Exit code 0 = all PASS, 1 = at least one FAIL.
 */

if ( ! class_exists( 'FW_Site_Converter_Stitch' ) ) {
	fwrite( STDERR, "FAIL: site-converter stitch not loaded\n" );
	exit( 1 );
}

$fails = 0;
$ok    = function ( $cond, $msg ) use ( &$fails ) {
	if ( $cond ) { echo "  \xe2\x9c\x93 $msg\n"; } else { $fails++; echo "  \xe2\x9c\x97 FAIL: $msg\n"; }
};

$fn = new ReflectionMethod( 'FW_Site_Converter_Stitch', 'post_from_html' );
$fn->setAccessible( true );

$POST = '<!DOCTYPE html><html><head>'
	. '<title>Leadership principles | Example Advisory</title>'
	. '<meta property="og:image" content="https://example-domain.test/wp-content/uploads/2026/04/hero.jpg">'
	. '<meta property="article:published_time" content="2026-04-18T09:30:00+10:00">'
	. '<meta name="author" content="Jane Example">'
	. '</head><body>'
	. '<header class="site-header"><nav><a href="/">Home</a><a href="/about-us">About</a></nav></header>'
	. '<main>'
	. '<article class="post">'
		. '<h1 class="entry-title">Leadership principles</h1>'
		. '<div class="entry-meta"><time datetime="2026-04-18T09:30:00+10:00">18 April 2026</time>'
			. '<span class="author">Jane Example</span>'
			. '<a href="/category/blog/">Blog</a><a href="/tag/leadership/">Leadership</a></div>'
		. '<div class="entry-content" data-elementor-id="99">'
			. '<p class="lead">Leadership is the invisible force behind every great organisation.</p>'
			. '<h2 class="h2">Why it matters</h2>'
			. '<p>Teams follow <strong>clarity</strong>, not charisma. See <a href="/services">our services</a>.</p>'
			. '<ul><li>Set the direction</li><li>Remove the obstacles</li></ul>'
			. '<blockquote><p>Culture eats strategy for breakfast.</p></blockquote>'
			. '<img src="/wp-content/uploads/2026/04/inline.jpg" alt="A team meeting" class="wp-image-12">'
		. '</div>'
		. '<div class="share-buttons"><a href="#">Share on X</a><a href="#">Share on LinkedIn</a></div>'
		. '<section class="related-posts"><h3>Related</h3><a href="/talent-without-direction">Talent without direction</a></section>'
		. '<section id="comments"><h3>Comments</h3><p>Great read!</p></section>'
	. '</article>'
	. '</main>'
	. '<footer class="site-footer"><p>&copy; 2026 Example Advisory</p></footer>'
	. '</body></html>';

$p = (array) $fn->invoke( null, $POST );

echo "\n== The article's own parts\n";

$ok( 'Leadership principles' === trim( (string) ( $p['title'] ?? '' ) ), 'the title is the article h1, not the site title (' . ( $p['title'] ?? '?' ) . ')' );
$ok( false !== strpos( (string) ( $p['date'] ?? '' ), '2026-04-18' ), 'the published date is read (' . ( $p['date'] ?? '?' ) . ')' );
$ok( 'Jane Example' === trim( (string) ( $p['author'] ?? '' ) ), 'the author is read (' . ( $p['author'] ?? '?' ) . ')' );
$ok( false !== strpos( (string) ( $p['featured'] ?? '' ), 'hero.jpg' ), 'the featured image comes from og:image' );
$ok( in_array( 'Blog', (array) ( $p['categories'] ?? array() ), true ), 'its category is read' );
$ok( in_array( 'Leadership', (array) ( $p['tags'] ?? array() ), true ), 'and its tag' );

echo "\n== The body is the ARTICLE\n";

$body = (string) ( $p['content'] ?? '' );
$ok( '' !== trim( $body ), 'a body is produced at all' );
$ok( false !== strpos( $body, 'invisible force behind every great organisation' ), 'it carries the opening paragraph' );
$ok( false !== strpos( $body, 'Why it matters' ), 'and the sub-heading' );
$ok( false !== strpos( $body, 'Set the direction' ), 'and the list' );
$ok( false !== strpos( $body, 'Culture eats strategy' ), 'and the quote' );
$ok( false !== strpos( $body, 'inline.jpg' ), 'and the inline image' );
$ok( false !== strpos( $body, '/services' ), 'and keeps a real link' );
$ok( false !== strpos( $body, '<strong>' ), 'and inline emphasis' );

echo "\n== …and NOT the chrome around it\n";

$ok( false === strpos( $body, 'Share on X' ), 'share buttons are left out' );
$ok( false === stripos( $body, 'Related' ), 'the related-posts block is left out' );
$ok( false === strpos( $body, 'Great read!' ), 'comments are left out' );
$ok( false === strpos( $body, 'site-footer' ) && false === strpos( $body, '2026 Example Advisory' ), 'the site footer is left out' );
$ok( false === strpos( $body, '<nav' ) && false === strpos( $body, 'Home</a>' ), 'the site nav is left out' );
$ok( false === strpos( $body, 'Leadership principles</h1>' ), 'the title is not repeated inside the body' );

echo "\n== The body is EDITOR html\n";

$ok( false === strpos( $body, 'data-elementor-id' ), 'source data-* attributes are stripped' );
$ok( false === strpos( $body, 'class="lead"' ) && false === strpos( $body, 'wp-image-12' ), 'source classes are stripped' );

echo "\n== NEGATIVE: a blog INDEX is not a post (a teaser card is not a body)\n";

// MEASURED ON A REAL CAPTURE, and it is why this block exists. A captured blog index carried FOUR <article>
// elements -- one per teaser card, each a single paragraph of ~24 words -- and the page's only <h1> was a
// featured headline sitting OUTSIDE all of them. Taking "the first <article>" produced: no title, the site's
// own tagline as the author (picked off a `.author`-ish class in the page furniture), and a 543-byte "body"
// that was a card linking to /post/2. Imported, that is one junk post per card on every listing.
//
// A post page has the same trap one step further in: it holds the body AND a row of related-post cards, also
// in <article>. So the body is chosen by PROSE WEIGHT, not document order, and it must clear a floor before
// anything is claimed at all.
//
// The asymmetry that sets the floor: a false accept invents junk posts, while a false reject just leaves the
// URL to convert as a page, which is the behaviour that existed before any of this. So the floor leans toward
// refusing.
$INDEX = '<!DOCTYPE html><html><head><title>Journal</title>'
	. '<meta name="description" content="A publication about design and technology.">'
	. '</head><body>'
	. '<header class="site-header"><nav><a href="/">Home</a></nav>'
		. '<div class="author">Editorial Team</div></header>'
	. '<main>'
	. '<h1>The Art of Minimalist Web Design</h1>'
	. '<div class="grid">'
	. '<article class="group flex flex-col"><a href="/post/2"><img src="/a.jpg" alt="AI interfaces"></a>'
		. 'Technology&#8226;May 20, 2026<a href="/post/2"><h3>Understanding the New Wave of AI Interfaces</h3></a>'
		. '<p>How conversational UI and generative models are reshaping the way we interact with software.</p></article>'
	. '<article class="group flex flex-col"><a href="/post/3"><img src="/b.jpg" alt="Architecture"></a>'
		. 'Architecture&#8226;May 15, 2026<a href="/post/3"><h3>Sustainable Architecture</h3></a>'
		. '<p>A deep dive into materials that will define the next decade of building.</p></article>'
	. '<article class="group flex flex-col"><a href="/post/4"><img src="/c.jpg" alt="Typography"></a>'
		. 'Design&#8226;May 10, 2026<a href="/post/4"><h3>The Psychology of Typography</h3></a>'
		. '<p>Why the fonts we choose matter more than we think.</p></article>'
	. '</div></main></body></html>';

$ix = (array) $fn->invoke( null, $INDEX );
$ok( '' === trim( (string) ( $ix['content'] ?? '' ) ), 'a listing of teaser cards yields no post body (' . strlen( (string) ( $ix['content'] ?? '' ) ) . ' bytes)' );
$ok( false === strpos( (string) ( $ix['content'] ?? '' ), '/post/2' ), 'so no card is mistaken for an article' );
$ok( 'Editorial Team' !== trim( (string) ( $ix['author'] ?? '' ) ), 'and no author is scavenged from the page furniture (' . ( $ix['author'] ?? '' ) . ')' );

echo "\n== ...but the body IS found when related cards sit beside it\n";

// The realistic post page: the article body, FOLLOWED by related-post cards that are also <article>. Picking
// by document order gets this right only by luck; picking by prose weight gets it right on purpose.
$body_prose = '';
for ( $i = 0; $i < 6; $i++ ) {
	$body_prose .= '<p>Paragraph ' . $i . ' of the real article, long enough to read as prose rather than as a'
		. ' teaser, which is the whole distinction being measured here and the reason this text runs on.</p>';
}
$WITH_RELATED = '<!DOCTYPE html><html><head><title>Minimalism</title></head><body><main>'
	. '<article class="teaser"><a href="/post/9"><h3>A related teaser</h3></a><p>Short promo line.</p></article>'
	. '<article class="post"><h1>Minimalism in 2026</h1>'
		// A post page carries a DATE, and the extractor now requires one -- see the next block for why.
		. '<time datetime="2026-05-02T08:00:00+10:00">2 May 2026</time>'
		. '<div class="entry-content">' . $body_prose . '</div></article>'
	. '<article class="teaser"><a href="/post/8"><h3>Another related teaser</h3></a><p>Another promo line.</p></article>'
	. '</main></body></html>';

$wr = (array) $fn->invoke( null, $WITH_RELATED );
$wb = (string) ( $wr['content'] ?? '' );
$ok( false !== strpos( $wb, 'Paragraph 0 of the real article' ), 'the real body is chosen over the cards around it' );
$ok( false === strpos( $wb, 'A related teaser' ) && false === strpos( $wb, 'Another promo line' ), 'and the cards are left out' );
$ok( 'Minimalism in 2026' === trim( (string) ( $wr['title'] ?? '' ) ), 'with the body article own title (' . ( $wr['title'] ?? '' ) . ')' );

echo "\n== NEGATIVE: an undated landing page in one <article> is not a post\n";

// The two false accepts the CORPUS produced, after the prose floor was in place. A marketing landing page
// whose whole body sits in ONE <article> -- hero copy, a CTA, a logo wall -- clears any length-based floor
// easily, because it is long. What it has not got is a date.
//
// A post is DATED. The date is what orders an archive and what a reader looks for; a page has none. So that
// is the gate.
//
// AND `entry-content` IS NOT A POST SIGNAL -- pinned here because this code used to treat it as one. BOTH
// corpus false accepts carry `class="entry-content"`: they are captures of WordPress sites, whose theme wraps
// EVERY entry in it, a page exactly as much as a post. It marks the editor's output, not the post type.
$long_copy = '';
for ( $i = 0; $i < 5; $i++ ) {
	$long_copy .= '<p>Handcrafted goods designed to bring warmth and intention to everyday moments, written at'
		. ' marketing length so that no floor based on how MUCH text there is could ever tell this apart'
		. ' from an article, which is the entire point of the assertion below.</p>';
}
$LANDING = '<!DOCTYPE html><html><head><title>Objects</title></head><body><main>'
	. '<article><h1>Objects of Quiet Beauty</h1><div class="entry-content">'
	. '<p>Curated for Considered Living</p>' . $long_copy
	. '<a href="/products">Shop Now</a><p>Trusted by</p><p>Studio One</p><p>Studio Two</p>'
	. '</div></article></main></body></html>';

$ld = (array) $fn->invoke( null, $LANDING );
$ok( '' === trim( (string) ( $ld['content'] ?? '' ) ),
	'a long but undated page yields no post body (' . strlen( (string) ( $ld['content'] ?? '' ) ) . ' bytes)' );
$ok( '' === trim( (string) ( $ld['title'] ?? '' ) ), 'and no title is claimed from its hero heading' );

// …and the same page WITH a date is a post again, so the gate is the date and nothing else.
$DATED = str_replace( '<div class="entry-content">', '<time datetime="2026-05-20">20 May 2026</time><div class="entry-content">', $LANDING );
$dt = (array) $fn->invoke( null, $DATED );
$ok( '' !== trim( (string) ( $dt['content'] ?? '' ) ), 'POSITIVE: the same markup WITH a date is read as a post' );

// A date written only as TEXT counts too: builders that emit no <time> still print it, because a reader wants it.
$TEXTDATE = str_replace( '<p>Curated for Considered Living</p>', '<p>Published May 20, 2026</p>', $LANDING );
$td = (array) $fn->invoke( null, $TEXTDATE );
$ok( '' !== trim( (string) ( $td['content'] ?? '' ) ), 'POSITIVE: a date written only as text counts as well' );

echo "\n== NEGATIVE: a page with no article yields nothing\n";

$plain = '<!DOCTYPE html><html><head><title>About</title></head><body><main>'
	. '<section><h2>About us</h2><p>We are a team.</p></section></main></body></html>';
$np = (array) $fn->invoke( null, $plain );
$ok( '' === trim( (string) ( $np['content'] ?? '' ) ), 'no post body is invented for a non-article page' );

printf( "\n%s\n", $fails ? "{$fails} FAIL(S)" : 'ALL PASS' );
exit( $fails ? 1 : 0 );
