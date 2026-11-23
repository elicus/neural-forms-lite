<?php
/**
 * No REST error may carry HTTP 502 or 504: Cloudflare and most CDNs
 * replace an origin 502/504 body with their own error page, so the
 * plugin's message never reaches the admin UI. Use 424 for "an upstream
 * call failed".
 */
declare( strict_types=1 );

$root = realpath( __DIR__ . '/../modules' );
$it   = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
$hits = array();
foreach ( $it as $file ) {
	if ( $file->getExtension() !== 'php' ) { continue; }
	$path = $file->getPathname();
	if ( strpos( $path, '/vendor/' ) !== false || strpos( $path, '/node_modules/' ) !== false ) { continue; }
	foreach ( file( $path ) as $n => $line ) {
		if ( preg_match( "/'status'\s*=>\s*50[24]\b/", $line ) ) {
			$hits[] = substr( $path, strlen( $root ) + 1 ) . ':' . ( $n + 1 );
		}
	}
}
foreach ( $hits as $h ) { echo "FAIL: 502/504 status at {$h}\n"; }
$pass = empty( $hits ) ? 1 : 0;
echo 'NoProxySwallowedStatusTest: ' . $pass . ' passed, ' . count( $hits ) . " failed\n";
exit( empty( $hits ) ? 0 : 1 );
