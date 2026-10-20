<?php
/**
 * Regression guard: a post card's META (author / date) is placed where the source puts it — above the title.
 *
 * A decomposed post card arrives with its title in the title slot and everything else in the body, plus the
 * meta line isolated as `overline`. `icon_box` renders title-then-content and has no eyebrow slot, so the
 * meta rendered BELOW the title however the source ordered it.
 *
 * Measured on a captured blog band of three cards. The source order is author, date, title, excerpt. The card
 * arrives as:
 *
 *     title    "Understanding Insurance Settlement Negotiations: ..."
 *     overline { text: "March 15, 2024", cs: 12px/400/rgb(101,117,139) }
 *     text     <p>Sarah Martinez</p><p>March 15, 2024</p><p>Insurance companies often ...</p>
 *
 * So the date is also DUPLICATED — once as the overline, once in the body — and the converted card rendered
 * title, author, date, excerpt: the meta in the wrong place and the date twice over. The body carries ONE
 * style for all three paragraphs, so the 600-weight author and the 12px grey date could not be told apart
 * either; the property diff reported four deltas per card on this band alone.
 *
 * `card_meta_lift()` takes the body's LEADING paragraphs up to and including the one that repeats the
 * overline, hands them back as their own block (carrying the overline's measured style) and strips them from
 * the body. That is exact rather than heuristic: the overline names where the meta ends.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/card-meta-sits-above-the-title-test.php"
 *
 * Exit code 0 = all PASS, 1 = at least one FAIL.
 */

if ( ! class_exists( 'FW_Site_Converter_Mapper' ) ) {
	fwrite( STDERR, "FAIL: site-converter mapper not loaded\n" );
	exit( 1 );
}

$fails = 0;
$ok    = function ( $cond, $msg ) use ( &$fails ) {
	if ( $cond ) { echo "  \xe2\x9c\x93 $msg\n"; } else { $fails++; echo "  \xe2\x9c\x97 FAIL: $msg\n"; }
};

$fn = new ReflectionMethod( 'FW_Site_Converter_Mapper', 'card_meta_lift' );
$fn->setAccessible( true );

$OVCS = 'color:rgb(101, 117, 139);font-size:12px;font-weight:400;line-height:16px';
$EXC  = 'Insurance companies often make initial settlement offers that are far below what your claim is worth.';

echo "\n== The measured shape: author, date, excerpt with the date repeated as the overline\n";

$card = array(
	'title'    => 'Understanding Insurance Settlement Negotiations: What You Need to Know',
	'overline' => array( 'text' => 'March 15, 2024', 'cs' => $OVCS ),
	'text'     => '<p>Sarah Martinez</p><p>March 15, 2024</p><p>' . $EXC . '</p>',
	'bodyCs'   => 'color:rgb(12, 31, 64);font-size:14px;font-weight:600;line-height:20px',
);
$meta = $fn->invokeArgs( null, array( &$card ) );

$ok( is_array( $meta ), 'a meta block is lifted out' );
if ( is_array( $meta ) ) {
	$ok( false !== strpos( (string) $meta['html'], 'Sarah Martinez' ), 'it carries the author' );
	$ok( false !== strpos( (string) $meta['html'], 'March 15, 2024' ), 'and the date' );
	$ok( false === strpos( (string) $meta['html'], 'Insurance companies' ), 'but NOT the excerpt' );
	$ok( false !== strpos( (string) $meta['cs'], 'font-size:12px' ), 'with the overline\'s measured style' );
	$au = substr( (string) $meta['html'], 0, strpos( (string) $meta['html'], 'March 15' ) );
	$dt = substr( (string) $meta['html'], strpos( (string) $meta['html'], 'March 15' ) );
	$ok( false !== strpos( $au, 'font-weight:600' ), 'the author keeps its own measured weight' );
	$ok( false === strpos( $dt, 'font-weight:600' ), 'and the date does not inherit it' );
}
$ok( false === strpos( (string) $card['text'], 'Sarah Martinez' ), 'the author is removed from the body (no duplicate)' );
$ok( false === strpos( (string) $card['text'], 'March 15, 2024' ), 'and the date is not rendered twice' );
$ok( false !== strpos( (string) $card['text'], 'Insurance companies' ), 'while the excerpt stays in the body' );

echo "\n== NEGATIVE: no overline, nothing is lifted\n";

$plain = array( 'title' => 'A card', 'text' => '<p>Sarah Martinez</p><p>' . $EXC . '</p>' );
$ok( null === $fn->invokeArgs( null, array( &$plain ) ), 'a card with no overline is untouched' );
$ok( false !== strpos( (string) $plain['text'], 'Sarah Martinez' ), '…and its body is left alone' );

echo "\n== NEGATIVE: an overline that does NOT repeat in the body is not a meta run\n";

$norepeat = array(
	'title' => 'A card',
	'overline' => array( 'text' => 'Case study', 'cs' => $OVCS ),
	'text'  => '<p>Sarah Martinez</p><p>' . $EXC . '</p>',
);
$ok( null === $fn->invokeArgs( null, array( &$norepeat ) ), 'nothing is lifted when the overline is not echoed in the body' );
$ok( false !== strpos( (string) $norepeat['text'], 'Sarah Martinez' ), '…and the body is left alone' );

echo "\n== NEGATIVE: the body is never emptied\n";

// The overline repeats the ONLY paragraph — lifting it would leave the card with no body at all.
$only = array(
	'title' => 'A card',
	'overline' => array( 'text' => 'March 15, 2024', 'cs' => $OVCS ),
	'text'  => '<p>March 15, 2024</p>',
);
$ok( null === $fn->invokeArgs( null, array( &$only ) ), 'a card whose body IS the meta keeps it' );

echo "\n== NEGATIVE: a meta run deep in the body is not leading meta\n";

$deep = array(
	'title' => 'A card',
	'overline' => array( 'text' => 'March 15, 2024', 'cs' => $OVCS ),
	'text'  => '<p>' . $EXC . '</p><p>More prose here that carries the body of the card.</p><p>Another line.</p><p>March 15, 2024</p>',
);
$ok( null === $fn->invokeArgs( null, array( &$deep ) ), 'a date at the END of a long body is not lifted above the title' );

printf( "\n%s\n", $fails ? "{$fails} FAIL(S)" : 'ALL PASS' );
exit( $fails ? 1 : 0 );
