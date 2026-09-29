<?php
/**
 * Guard for the AI-suggestion queue (framework/includes/ai-suggestions.php).
 *
 * The queue exists so a job offered by one extension survives the assistant being switched off. That is the
 * assertion that matters: the AI Assistant ships INACTIVE, so the common case is the Site Converter
 * finishing a conversion while nothing is listening. If the suggestion were lost there, a user would click
 * "Enable the AI Assistant", the page would reload with it on, and the panel would be empty — they would
 * have done what was asked and got nothing, which is worse than never having offered.
 *
 * The rest are negatives around the launcher's attention rule: each suggestion may be pointed at ONCE, or
 * the button pulses on every admin page load for as long as the job lives, which is the nag the whole
 * design is trying to avoid.
 *
 * Run:
 *   php D:/xampp/wp-cli.phar --path=D:/xampp/htdocs eval-file \
 *       "D:/xampp/htdocs/wp-content/plugins/unysonplus/framework/extensions/site-converter/tests/suggestions-test.php"
 */

if ( ! class_exists( 'FW_AI_Suggestions' ) ) {
	fwrite( STDERR, "FAIL: FW_AI_Suggestions not loaded — it belongs in framework/includes/, loaded from bootstrap\n" );
	exit( 1 );
}

$pass = 0; $fail = 0;
$ok = function ( $c, $what, $d = '' ) use ( &$pass, &$fail ) {
	if ( $c ) { $pass++; echo "  \xE2\x9C\x93 {$what}\n"; }
	else { $fail++; echo "  \xE2\x9C\x97 FAIL: {$what}" . ( '' !== $d ? " -- {$d}" : '' ) . "\n"; }
};

// The queue is per-user and add() correctly refuses with no current user, but wp-cli has none unless
// it is invoked with --user=1. Without this the whole suite reported 10 failures on a perfectly healthy
// install, which is worse than no suite at all: it trains a reader to ignore the result. So the test
// establishes its own user rather than depending on how it was launched.
if ( ! get_current_user_id() ) {
	$admin = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
	if ( $admin ) { wp_set_current_user( (int) $admin[0] ); }
}
$uid = get_current_user_id();
if ( ! $uid ) {
	fwrite( STDERR, "No administrator to run as — the per-user queue cannot be tested.
" );
	exit( 1 );
}
$restore = get_option( FW_AI_Suggestions::OPTION, array() );
$restore_seen = get_user_meta( $uid, FW_AI_Suggestions::SEEN_META, true );
delete_option( FW_AI_Suggestions::OPTION );
delete_user_meta( $uid, FW_AI_Suggestions::SEEN_META );

echo "\n=== 0. It lives in CORE, not in the assistant ===\n";
$ok( function_exists( 'fw_ai_suggest' ), 'fw_ai_suggest() exists' );
$ref = new ReflectionClass( 'FW_AI_Suggestions' );
$file = str_replace( '\\', '/', $ref->getFileName() );
$ok( false !== strpos( $file, '/framework/includes/' ), 'the queue is in framework/includes, so it loads with the assistant OFF', $file );
$ok( false === strpos( $file, '/extensions/' ), 'NEGATIVE: it is NOT inside an extension (which would not load when inactive)' );

echo "\n=== 1. Queue and read ===\n";
$ok( true === fw_ai_suggest( array( 'id' => 'sc_test_one', 'title' => 'Finish the conversion', 'prompt' => 'Fix these things…', 'source' => 'site-converter' ) ), 'a suggestion is accepted' );
$mine = FW_AI_Suggestions::for_user();
$ok( 1 === count( $mine ), 'it comes back for this user', count( $mine ) . ' found' );
$ok( 'Finish the conversion' === $mine[0]['title'], 'the title survives' );
$ok( 'Fix these things…' === $mine[0]['prompt'], 'the PROMPT survives separately from the title (a chip label is not a message)' );

echo "\n=== 2. Rejected input ===\n";
$ok( false === fw_ai_suggest( array( 'id' => 'x', 'title' => 'no prompt' ) ), 'NEGATIVE: no prompt is refused' );
$ok( false === fw_ai_suggest( array( 'title' => 't', 'prompt' => 'p' ) ), 'NEGATIVE: no id is refused' );
$ok( 1 === count( FW_AI_Suggestions::for_user() ), 'neither was stored' );

echo "\n=== 3. Re-running refreshes, it does not pile up ===\n";
fw_ai_suggest( array( 'id' => 'sc_test_one', 'title' => 'Finish the conversion — 2 things', 'prompt' => 'Updated…' ) );
$mine = FW_AI_Suggestions::for_user();
$ok( 1 === count( $mine ), 'the same id replaces rather than duplicates', count( $mine ) . ' found' );
$ok( false !== strpos( $mine[0]['title'], '2 things' ), 'the newer title wins' );

echo "\n=== 4. The launcher points at each job once ===\n";
$unseen = FW_AI_Suggestions::unseen_for_user();
$ok( in_array( 'sc_test_one', $unseen, true ), 'a new suggestion is unseen' );
FW_AI_Suggestions::mark_seen( array( 'sc_test_one' ) );
$ok( ! in_array( 'sc_test_one', FW_AI_Suggestions::unseen_for_user(), true ), 'NEGATIVE: once pointed at, it is not unseen again (no pulse on every page load)' );
$ok( 1 === count( FW_AI_Suggestions::for_user() ), 'but the suggestion itself is still there — seen is not done' );

echo "\n=== 5. Per user ===\n";
fw_ai_suggest( array( 'id' => 'sc_other', 'title' => "Another user job", 'prompt' => 'p', 'user_id' => $uid + 9999 ) );
$ok( 1 === count( FW_AI_Suggestions::for_user() ), 'NEGATIVE: another user suggestion does not show in mine', count( FW_AI_Suggestions::for_user() ) . ' found' );
$ok( 1 === count( FW_AI_Suggestions::for_user( $uid + 9999 ) ), 'and it is there for them' );

echo "\n=== 6. Taking the job clears it ===\n";
FW_AI_Suggestions::remove( 'sc_test_one' );
$ok( 0 === count( FW_AI_Suggestions::for_user() ), 'remove() drops it' );

/* -------------------------------------------------- leave nothing behind */
FW_AI_Suggestions::remove( 'sc_other', $uid + 9999 );
delete_option( FW_AI_Suggestions::OPTION );
delete_user_meta( $uid, FW_AI_Suggestions::SEEN_META );
if ( $restore ) { update_option( FW_AI_Suggestions::OPTION, $restore, false ); }
if ( $restore_seen ) { update_user_meta( $uid, FW_AI_Suggestions::SEEN_META, $restore_seen ); }

echo "\n========================================\n";
echo 'SUGGESTIONS RESULT: ' . ( 0 === $fail ? 'PASS' : 'FAIL' ) . "   ({$pass} passed, {$fail} failed)\n";
echo "========================================\n";
exit( 0 === $fail ? 0 : 1 );
