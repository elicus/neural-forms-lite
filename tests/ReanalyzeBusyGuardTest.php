<?php
/**
 * EntriesEndpoint::classify_active_jobs() — "Analyze with AI" must only be
 * refused while a worker is genuinely mid-run. Pending rows (cron never
 * fired) and stale 'processing' rows are superseded so the manual run can
 * proceed instead of the entry sitting "Queued" forever.
 */
declare( strict_types=1 );

$src = file_get_contents( __DIR__ . '/../modules/rest/Endpoints/EntriesEndpoint.php' );
preg_match( '/private static function classify_active_jobs\(.*?\n\t}\n/s', $src, $m ) || exit( "classify_active_jobs not found\n" );
preg_match( '/const AI_JOB_ACTIVE_WINDOW = \d+;/', $src, $c ) || exit( "AI_JOB_ACTIVE_WINDOW not found\n" );
eval( 'class BusyGuardHarness { ' . $c[0] . ' public static function call( array $jobs, int $now ) { return self::classify_active_jobs( $jobs, $now ); } ' . $m[0] . ' }' ); // phpcs:ignore

$pass = 0; $fail = 0;
function check( string $name, bool $ok ): void { global $pass, $fail; if ( $ok ) { $pass++; } else { $fail++; echo "FAIL: {$name}\n"; } }
$now = 1_800_000_000;
$ts  = static fn( int $ago ): string => gmdate( 'Y-m-d H:i:s', $now - $ago );

$r = BusyGuardHarness::call( array( array( 'id' => 7, 'status' => 'pending', 'scheduled_at' => $ts( 1800 ) ) ), $now );
check( 'pending job (cron never ran) is superseded, not blocking', ! $r['running'] && $r['supersede'] === array( 7 ) );

$r = BusyGuardHarness::call( array( array( 'id' => 8, 'status' => 'processing', 'scheduled_at' => $ts( 30 ) ) ), $now );
check( 'processing job claimed 30s ago blocks', $r['running'] && $r['supersede'] === array() );

$r = BusyGuardHarness::call( array( array( 'id' => 9, 'status' => 'processing', 'scheduled_at' => $ts( 600 ) ) ), $now );
check( 'processing job stuck 10 min is superseded', ! $r['running'] && $r['supersede'] === array( 9 ) );

$r = BusyGuardHarness::call( array(
	array( 'id' => 10, 'status' => 'pending', 'scheduled_at' => $ts( 900 ) ),
	array( 'id' => 11, 'status' => 'processing', 'scheduled_at' => $ts( 5 ) ),
), $now );
check( 'any live worker blocks and nothing is superseded', $r['running'] && $r['supersede'] === array() );

$r = BusyGuardHarness::call( array(), $now );
check( 'no rows → proceed, nothing to supersede', ! $r['running'] && $r['supersede'] === array() );

$r = BusyGuardHarness::call( array( array( 'id' => 12, 'status' => 'processing', 'scheduled_at' => 'garbage' ) ), $now );
check( 'unparseable claim time is treated as stale', ! $r['running'] && $r['supersede'] === array( 12 ) );

echo "ReanalyzeBusyGuardTest: {$pass} passed, {$fail} failed\n";
exit( $fail === 0 ? 0 : 1 );
