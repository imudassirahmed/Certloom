<?php

if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
error_reporting( E_ALL );
$root = dirname( __DIR__ );
$client = file_get_contents( $root . '/src/Infrastructure/Backup/GoogleDriveClient.php' );
$oauth  = file_get_contents( $root . '/src/Infrastructure/Backup/GoogleDriveOAuth.php' );
$credly = file_get_contents( $root . '/src/Infrastructure/Integration/CredlyClient.php' );
$relay  = file_get_contents( $root . '/src/Infrastructure/Backup/EcaplaOAuthRelay.php' );
$prod   = '';
$rii = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/src', FilesystemIterator::SKIP_DOTS ) );
foreach ( $rii as $file ) {
    if ( ! $file->isFile() || 'php' !== strtolower( $file->getExtension() ) ) { continue; }
    $prod .= "\n/* " . str_replace( str_replace( '\\', '/', $root ) . '/', '', str_replace( '\\', '/', $file->getPathname() ) ) . " */\n" . file_get_contents( $file->getPathname() );
}
$prod .= "\n" . file_get_contents( $root . '/certloom.php' ) . "\n" . file_get_contents( $root . '/uninstall.php' );

$checks = 0; $failures = 0;
function ck( $value, $message ) {
    global $checks, $failures;
    $checks++;
    if ( $value ) { echo "PASS  {$message}\n"; }
    else { $failures++; echo "FAIL  {$message}\n"; }
}

preg_match_all( '/\bwp_(?:safe_)?remote_(?:get|post|request|head)\s*\(/', $prod, $all_http );
ck( 4 === count( $all_http[0] ), 'production contains exactly four reviewed WordPress outbound HTTP call sites' );
ck( false !== strpos( $client, 'wp_safe_remote_request( $url, $args )' ), 'Drive API traffic uses WordPress safe remote request transport' );
ck( false !== strpos( $oauth, 'wp_safe_remote_post( self::TOKEN_URL' ), 'OAuth token traffic uses the fixed Google token endpoint' );
ck( false !== strpos( $credly, 'wp_safe_remote_post( $url, $args )' ), 'Credly publishing uses WordPress safe remote post transport' );
ck( false !== strpos( $relay, 'wp_safe_remote_post( $url' ), 'Managed OAuth relay exchange/refresh uses WordPress safe remote post transport' );
ck( false !== strpos( $relay, "const BASE_URL         = 'https://auth.ecapla.com';" ) && false !== strpos( $relay, "'/v1/google/exchange'" ) && false !== strpos( $relay, "'/v1/google/refresh'" ), 'managed OAuth relay is fixed to reviewed auth.ecapla.com HTTPS endpoints' );
ck( preg_match( "/'redirection'\s*=>\s*0/", $relay ) && false !== strpos( $relay, "'limit_response_size' => self::MAX_RESPONSE" ), 'managed OAuth relay transport disables redirects and bounds response size' );
ck( false !== strpos( $credly, "const PRODUCTION_BASE = 'https://api.credly.com/v1';" ) && false !== strpos( $credly, "const SANDBOX_BASE    = 'https://sandbox-api.credly.com/v1';" ), 'Credly API bases are fixed HTTPS production/sandbox endpoints' );
ck( false !== strpos( $credly, "'api.credly.com'" ) && false !== strpos( $credly, "'sandbox-api.credly.com'" ) && false !== strpos( $credly, "'https' !== strtolower" ), 'Credly client rejects non-HTTPS and unexpected hosts' );
ck( preg_match( "/'redirection'\s*=>\s*0/", $credly ) && false !== strpos( $credly, "'limit_response_size' => 262144" ), 'Credly transport disables redirects and bounds response size' );
ck( false !== strpos( $client, "const API_BASE    = 'https://www.googleapis.com/drive/v3';" ) && false !== strpos( $client, "const UPLOAD_BASE = 'https://www.googleapis.com/upload/drive/v3';" ), 'Drive API bases are fixed HTTPS Google endpoints' );
ck( false !== strpos( $oauth, "const AUTH_URL       = 'https://accounts.google.com/o/oauth2/v2/auth';" ) && false !== strpos( $oauth, "const TOKEN_URL      = 'https://oauth2.googleapis.com/token';" ), 'OAuth authorization/token endpoints are fixed HTTPS Google endpoints' );
ck( false !== strpos( $oauth, "const SCOPE          = 'https://www.googleapis.com/auth/drive.file';" ), 'OAuth uses least-privilege drive.file scope' );
ck( false !== strpos( $client, "'www.googleapis.com' !== \$host" ) && false !== strpos( $client, "'https' !== strtolower" ), 'Drive client rejects non-HTTPS and non-Google API hosts' );
ck( false !== strpos( $client, "if ( \$upload_session ) { return 0 === strpos( \$path, '/upload/drive/v3/files' ); }" ), 'resumable session URLs are path-bounded to Drive upload endpoints' );
ck( false !== strpos( $client, "\$args['redirection'] = 0;" ) && preg_match( "/'redirection'\s*=>\s*0/", $oauth ), 'Drive and OAuth transports disable redirects' );
ck( 0 === preg_match( '/\bcurl_(?:init|exec)\s*\(/', $prod ), 'production does not bypass WordPress HTTP API with cURL execution' );
ck( 0 === preg_match( '/\b(?:file_get_contents|fopen)\s*\(\s*[\'\"]https?:\/\//i', $prod ), 'production does not open arbitrary HTTP URLs through filesystem functions' );
ck( 0 === preg_match( '/\bwp_remote_(?:get|post|request|head)\s*\(/', $prod ), 'all intentional production HTTP uses the safe WordPress transport variant' );

// Only the bounded Google adapter may carry Google endpoint constants.
$google_sources = array();
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/src', FilesystemIterator::SKIP_DOTS ) ) as $file ) {
    if ( ! $file->isFile() || 'php' !== strtolower( $file->getExtension() ) ) { continue; }
    $body = file_get_contents( $file->getPathname() );
    if ( false !== strpos( $body, 'googleapis.com' ) || false !== strpos( $body, 'accounts.google.com' ) ) {
        $google_sources[] = str_replace( str_replace( '\\', '/', $root ) . '/', '', str_replace( '\\', '/', $file->getPathname() ) );
    }
}
sort( $google_sources );
ck(
    array( 'src/Infrastructure/Backup/GoogleDriveClient.php', 'src/Infrastructure/Backup/GoogleDriveOAuth.php' ) === $google_sources,
    'Google network endpoints are confined to the dedicated Drive client and OAuth adapter'
);

$credly_sources = array();
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/src', FilesystemIterator::SKIP_DOTS ) ) as $file ) {
    if ( ! $file->isFile() || 'php' !== strtolower( $file->getExtension() ) ) { continue; }
    $body = file_get_contents( $file->getPathname() );
    if ( false !== strpos( $body, 'api.credly.com' ) || false !== strpos( $body, 'sandbox-api.credly.com' ) ) {
        $credly_sources[] = str_replace( str_replace( '\\', '/', $root ) . '/', '', str_replace( '\\', '/', $file->getPathname() ) );
    }
}
sort( $credly_sources );
ck( array( 'src/Infrastructure/Integration/CredlyClient.php' ) === $credly_sources, 'Credly network endpoints are confined to the dedicated Credly client' );

$relay_sources = array();
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/src', FilesystemIterator::SKIP_DOTS ) ) as $file ) {
    if ( ! $file->isFile() || 'php' !== strtolower( $file->getExtension() ) ) { continue; }
    $body = file_get_contents( $file->getPathname() );
    if ( false !== strpos( $body, 'auth.ecapla.com' ) ) { $relay_sources[] = str_replace( str_replace( '\\', '/', $root ) . '/', '', str_replace( '\\', '/', $file->getPathname() ) ); }
}
sort( $relay_sources );
ck( array( 'src/Infrastructure/Backup/EcaplaOAuthRelay.php' ) === $relay_sources, 'Ecapla OAuth relay endpoints are confined to the dedicated relay client' );

echo "\n{$checks} reviewed network-boundary checks, {$failures} failures\n";
exit( $failures ? 1 : 0 );
