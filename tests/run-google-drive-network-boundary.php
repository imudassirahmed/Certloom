<?php
error_reporting( E_ALL );
$root = dirname( __DIR__ );
$client = file_get_contents( $root . '/src/Infrastructure/Backup/GoogleDriveClient.php' );
$oauth  = file_get_contents( $root . '/src/Infrastructure/Backup/GoogleDriveOAuth.php' );
$prod   = '';
$rii = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/src', FilesystemIterator::SKIP_DOTS ) );
foreach ( $rii as $file ) {
    if ( ! $file->isFile() || 'php' !== strtolower( $file->getExtension() ) ) { continue; }
    $prod .= "\n/* " . str_replace( $root . '/', '', $file->getPathname() ) . " */\n" . file_get_contents( $file->getPathname() );
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
ck( 2 === count( $all_http[0] ), 'production contains exactly two WordPress outbound HTTP call sites' );
ck( false !== strpos( $client, 'wp_safe_remote_request( $url, $args )' ), 'Drive API traffic uses WordPress safe remote request transport' );
ck( false !== strpos( $oauth, 'wp_safe_remote_post( self::TOKEN_URL' ), 'OAuth token traffic uses the fixed Google token endpoint' );
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
        $google_sources[] = str_replace( $root . '/', '', $file->getPathname() );
    }
}
sort( $google_sources );
ck(
    array( 'src/Infrastructure/Backup/GoogleDriveClient.php', 'src/Infrastructure/Backup/GoogleDriveOAuth.php' ) === $google_sources,
    'Google network endpoints are confined to the dedicated Drive client and OAuth adapter'
);

echo "\n{$checks} Google Drive network-boundary checks, {$failures} failures\n";
exit( $failures ? 1 : 0 );
