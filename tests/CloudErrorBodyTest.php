<?php
/**
 * NeuralFormsCloudProvider::flatten_error_body() — the cloud's generic
 * exception filter nests {error:{code,message,status}}; plan/safety errors
 * are flat. Both must surface a code + message, never a bare "HTTP 503".
 */
declare( strict_types=1 );

$src = file_get_contents( __DIR__ . '/../modules/ai/Providers/NeuralFormsCloudProvider.php' );
preg_match( '/private static function flatten_error_body\(.*?\n\t}\n/s', $src, $m ) || exit( "flatten_error_body not found\n" );
eval( 'class CloudErrBodyHarness { public static function call( $d ) { return self::flatten_error_body( $d ); } ' . $m[0] . ' }' ); // phpcs:ignore

$pass = 0; $fail = 0;
function check( string $name, bool $ok ): void { global $pass, $fail; if ( $ok ) { $pass++; } else { $fail++; echo "FAIL: {$name}\n"; } }

$nested = CloudErrBodyHarness::call( array( 'error' => array( 'code' => 'provider_unavailable', 'message' => 'No AI provider is currently reachable.', 'status' => 503 ) ) );
check( 'nested code lifted', ( $nested['code'] ?? '' ) === 'provider_unavailable' );
check( 'nested message lifted', ( $nested['message'] ?? '' ) === 'No AI provider is currently reachable.' );

$flat = CloudErrBodyHarness::call( array( 'code' => 'quota_exceeded', 'message' => 'Credit balance exhausted.', 'quota' => array( 'kind' => 'credits' ) ) );
check( 'flat untouched', $flat['code'] === 'quota_exceeded' && isset( $flat['quota'] ) );

$string_err = CloudErrBodyHarness::call( array( 'error' => 'blocked_by_safety', 'code' => 'blocked_by_safety', 'message' => 'x' ) );
check( 'flat with string error key untouched', $string_err['code'] === 'blocked_by_safety' );

check( 'non-array passthrough', CloudErrBodyHarness::call( null ) === null );
check( 'html body passthrough', CloudErrBodyHarness::call( 'Service Unavailable' ) === 'Service Unavailable' );

echo "CloudErrorBodyTest: {$pass} passed, {$fail} failed\n";
exit( $fail === 0 ? 0 : 1 );
