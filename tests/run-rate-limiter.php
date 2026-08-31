<?php
/** Pure-ish object-cache branch tests for the shared scoped RateLimiter. */
define( 'MINUTE_IN_SECONDS', 60 );
$GLOBALS['rl_cache'] = array();
$GLOBALS['rl_fail_incr'] = false;
function sanitize_key( $key ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $key ) ); }
function absint( $value ) { return abs( (int) $value ); }
function apply_filters( $hook, $value ) { return $value; }
function wp_salt( $scheme = '' ) { return 'test-salt-' . $scheme; }
function wp_using_ext_object_cache() { return true; }
function wp_cache_add( $key, $value, $group = '', $ttl = 0 ) {
	$full = $group . ':' . $key;
	if ( array_key_exists( $full, $GLOBALS['rl_cache'] ) ) { return false; }
	$GLOBALS['rl_cache'][ $full ] = $value; return true;
}
function wp_cache_incr( $key, $offset = 1, $group = '' ) {
	if ( $GLOBALS['rl_fail_incr'] ) { return false; }
	$full = $group . ':' . $key;
	if ( ! array_key_exists( $full, $GLOBALS['rl_cache'] ) ) { return false; }
	$GLOBALS['rl_cache'][ $full ] += $offset; return $GLOBALS['rl_cache'][ $full ];
}
function wp_cache_set( $key, $value, $group = '', $ttl = 0 ) {
	if ( $GLOBALS['rl_fail_incr'] ) { return false; }
	$GLOBALS['rl_cache'][ $group . ':' . $key ] = $value; return true;
}
function sanitize_text_field( $value ) { return (string) $value; }
function wp_unslash( $value ) { return $value; }
function wp_rand( $min, $max ) { return $max; }

require_once __DIR__ . '/bootstrap-autoload.php';
use Certloom\Infrastructure\Security\RateLimiter;

function rl_same( $expected, $actual, $message = '' ) {
	if ( $expected !== $actual ) { throw new RuntimeException( ( $message ? $message . ': ' : '' ) . 'Expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) ); }
}

$limiter = new RateLimiter();
$identifier = '203.0.113.4|test';
rl_same( false, $limiter->is_limited( 'verify', true, 2, $identifier ), 'first allowed' );
rl_same( false, $limiter->is_limited( 'verify', true, 2, $identifier ), 'second allowed' );
rl_same( true, $limiter->is_limited( 'verify', true, 2, $identifier ), 'third limited' );
echo "PASS  scoped counter enforces configured limit\n";

rl_same( false, $limiter->is_limited( 'claim', true, 2, $identifier ), 'claim has independent bucket' );
echo "PASS  operation scopes do not share counters\n";

$GLOBALS['rl_cache'] = array();
$GLOBALS['rl_fail_incr'] = true;
rl_same( false, $limiter->is_limited( 'credential_verify', true, 2, $identifier ), 'first add succeeds' );
rl_same( true, $limiter->is_limited( 'credential_verify', true, 2, $identifier ), 'counter failure fails closed' );
rl_same( false, $limiter->is_limited( 'legacy_verify', false, 2, $identifier ), 'legacy-compatible fail-open mode explicit' );
echo "PASS  fail-closed behavior is explicit per operation\n";

$GLOBALS['rl_cache'] = array();
$GLOBALS['rl_fail_incr'] = false;
$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
for ( $i = 1; $i <= 8; $i++ ) {
	$_SERVER['HTTP_USER_AGENT'] = 'rotating-agent-' . $i;
	rl_same( false, $limiter->is_limited( 'verify', true, 2 ), 'network ceiling allows bounded UA rotation request ' . $i );
}
$_SERVER['HTTP_USER_AGENT'] = 'rotating-agent-9';
rl_same( true, $limiter->is_limited( 'verify', true, 2 ), 'network ceiling blocks User-Agent rotation after four-times headroom' );
echo "PASS  attacker-controlled User-Agent rotation cannot create unlimited public capacity
";

$GLOBALS['rl_cache'] = array();
for ( $i = 1; $i <= 8; $i++ ) {
	$_SERVER['REMOTE_ADDR'] = '2001:db8:1234:5678::' . dechex( $i );
	$_SERVER['HTTP_USER_AGENT'] = 'ipv6-agent-' . $i;
	rl_same( false, $limiter->is_limited( 'verify', true, 2 ), 'IPv6 /64 network ceiling allows bounded request ' . $i );
}
$_SERVER['REMOTE_ADDR'] = '2001:db8:1234:5678::ffff';
$_SERVER['HTTP_USER_AGENT'] = 'ipv6-agent-9';
rl_same( true, $limiter->is_limited( 'verify', true, 2 ), 'IPv6 /64 address rotation shares one network ceiling' );
echo "PASS  IPv6 interface-address rotation cannot bypass the /64 network bucket
";

$GLOBALS['rl_cache'] = array();
$_SERVER['REMOTE_ADDR'] = '192.0.2.44';
$_SERVER['HTTP_USER_AGENT'] = 'mapped-a';
rl_same( false, $limiter->is_limited( 'mapped', true, 1 ), 'native IPv4 first request allowed' );
$_SERVER['REMOTE_ADDR'] = '::ffff:192.0.2.44';
for ( $i = 0; $i < 3; $i++ ) {
	$_SERVER['HTTP_USER_AGENT'] = 'mapped-' . $i;
	$mapped_result = $limiter->is_limited( 'mapped', true, 1 );
}
rl_same( false, $mapped_result, 'mapped IPv4 shares network bucket before ceiling' );
$_SERVER['HTTP_USER_AGENT'] = 'mapped-final';
rl_same( true, $limiter->is_limited( 'mapped', true, 1 ), 'mapped IPv4 representation reaches the same network ceiling' );
echo "PASS  IPv4-mapped IPv6 normalizes to the IPv4 network identity
";

$precision = ini_get( 'precision' );
@ini_set( 'precision', '6' );
$bucket_method = new ReflectionMethod( RateLimiter::class, 'bucket' );
$bucket_method->setAccessible( true );
$bucket_value = $bucket_method->invoke( $limiter );
rl_same( 1, preg_match( '/^[0-9]+$/', $bucket_value ), 'minute bucket remains decimal under low precision ini' );
if ( false !== $precision ) { @ini_set( 'precision', (string) $precision ); }
echo "PASS  limiter minute identity is integer and precision-independent
";

echo "\n7 tests, 0 failures\n";
