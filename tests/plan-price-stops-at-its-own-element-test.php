<?php
/**
 * Regression guard: a plan's price must not swallow the first digit of the NEXT element.
 *
 * WHY THIS FILE EXISTS. `cell_price_parts()` matches the amount with a GREEDY digit run and no trailing
 * word boundary, deliberately — the boundary was what broke a real price list (`From$120Flat Rate`
 * counted as unpriced) and what let `$4.2B` backtrack to a plan price of 4. That fix was right. What it
 * missed is the other side of the same root cause: **`self::text()` concatenates WITHOUT separators**,
 * so a plan card reads
 *
 *     Basic$91 projectEmail support            <- <span>$9</span> then <li>1 project</li>
 *
 * and the greedy run continues straight into the feature list. Measured on a rendered conversion of a
 * four-plan grid: **three of the four prices were wrong** — $9 → $91, $29 → $2910, and only $59/$99
 * survived (because a letter happened to follow them). This is the worst class of conversion defect,
 * because 91 is a perfectly plausible price: nothing on the page looks broken.
 *
 * Text alone cannot settle it. `$9` followed by `1 project` and `$91` are the same characters, since a
 * real price is written with no space either. Only the DOM knows, so `price_text()` reads the amount
 * from the SMALLEST descendant whose own text carries a currency sign and a digit — the amount span, or
 * the parent of a split `<span>$</span><span>29</span>` pair — and falls back to the whole cell's text
 * when no descendant qualifies (a price written directly on the cell). The PERIOD is still read from the
 * whole cell, because `/mo` routinely sits in a sibling element.
 *
 * Both earlier behaviours are asserted here as negatives, since the greedy run still has to protect them.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/plan-price-stops-at-its-own-element-test.php"
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

$cpp = new ReflectionMethod( 'FW_Site_Converter_Stitch', 'cell_price_parts' );
$cpp->setAccessible( true );

$cell = function ( $inner ) {
	$d = new DOMDocument();
	libxml_use_internal_errors( true );
	$d->loadHTML( '<?xml encoding="utf-8"?><body><div id="c">' . $inner . '</div></body>' );
	libxml_clear_errors();
	return $d->getElementById( 'c' );
};
$price = function ( $el ) use ( $cpp ) {
	$r = $cpp->invoke( null, $el );
	return is_array( $r ) ? (string) ( $r['price'] ?? '' ) : null;
};

echo "\n== A price stops at its own element, whatever follows it\n";

// the exact shape that produced the wrong figures: an amount span, then a feature list opening with a digit
$p = $price( $cell( '<h3>Basic</h3><span class="amt">$9</span><ul><li>1 project</li><li>Email support</li></ul>' ) );
$ok( '9' === $p, 'a $9 plan above "1 project" reads 9, not 91 (got ' . var_export( $p, true ) . ')' );

$p = $price( $cell( '<h3>Plus</h3><span class="amt">$29</span><ul><li>10 GB storage</li></ul>' ) );
$ok( '29' === $p, 'a $29 plan above "10 GB" reads 29, not 2910 (got ' . var_export( $p, true ) . ')' );

// the cases that happened to survive before must keep working
$p = $price( $cell( '<h3>Pro</h3><span class="amt">$59</span><ul><li>Unlimited projects</li></ul>' ) );
$ok( '59' === $p, 'a price followed by a letter still reads correctly' );

echo "\n== A price SPLIT across elements is still found\n";

// currency and amount in sibling spans: neither child qualifies alone, so their parent is the tightest node
$p = $price( $cell( '<h3>Team</h3><div class="price"><span>$</span><span>49</span></div><ul><li>5 seats</li></ul>' ) );
$ok( '49' === $p, 'a <span>$</span><span>49</span> pair reads 49 (got ' . var_export( $p, true ) . ')' );

// no descendant carries the price at all — the whole cell's text is the fallback, as before
$p = $price( $cell( 'Starter $19 per month' ) );
$ok( '19' === $p, 'a price written on the cell itself still reads (the fallback path)' );

echo "\n== The period still comes from the whole cell, not the tight node\n";

$r = $cpp->invoke( null, $cell( '<span class="amt">$29</span><span class="per">/mo</span><ul><li>3 projects</li></ul>' ) );
$ok( is_array( $r ) && '29' === (string) ( $r['price'] ?? '' ), 'the amount comes from its own span' );
$ok( is_array( $r ) && '/mo' === (string) ( $r['period'] ?? '' ), '…while /mo is still read from the sibling' );

echo "\n== NEGATIVE: the cases the greedy run exists to protect are unchanged\n";

// the price LIST that the trailing boundary used to break (price-list-is-detected-test.php)
$p = $price( $cell( '<div>Residential Cleaning</div><div>From</div><div>$120</div><div>Flat Rate</div>' ) );
$ok( '120' === $p, 'a row whose price runs into the next word still reads 120' );

// a headline metric must never become a plan price
foreach ( array( '$4.2B', '$18M', '$9K' ) as $stat ) {
	$ok( null === $cpp->invoke( null, $cell( '<div class="stat">' . $stat . '</div><div>processed</div>' ) ),
		"$stat is still rejected as a stat" );
}
// NOT GUARDED, deliberately: a magnitude letter in its OWN element. The tight node reads "$4.2" and
// cannot see the "B" next door, and a guard against the cell's whole text cannot help, because
// self::text() concatenates WITHOUT separators - so "$120" + "monthly" reads "$120monthly" and any such
// guard rejects a real price. That was tried and removed; the two assertions below are what it broke.
// This shape has no population in the capture corpus, while "$120 monthly" is everywhere.
$ok( is_array( $cpp->invoke( null, $cell( '<div class="stat"><span>$4.2</span><span>B</span></div><div>processed</div>' ) ) ),
	'a suffix in a SEPARATE element is read as a price - accepted, not guarded (see above)' );
$ok( null === $cpp->invoke( null, $cell( '<div>$18,240,000.00</div>' ) ), 'a seven-figure number is still rejected' );

// The two cases the removed guard broke. A word that merely BEGINS with a magnitude letter is not a
// magnitude, and after concatenation it sits flush against the price - so these must stay prices.
$p = $price( $cell( '<span class="amt">$120</span><span>monthly</span>' ) );
$ok( '120' === $p, 'a price followed by a SPACED word starting with m is still a price (got ' . var_export( $p, true ) . ')' );
$p = $price( $cell( '<span class="amt">$45</span><div>Book now</div>' ) );
$ok( '45' === $p, '…same for a b-word ("Book now") after a space' );

echo $fails ? "\n$fails FAILED\n" : "\nALL PASS\n";
exit( $fails ? 1 : 0 );
