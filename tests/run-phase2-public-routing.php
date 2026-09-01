<?php
/** Functional tests for Phase 2 clean routing/token behavior without WordPress. */
define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['opts'] = array( 'permalink_structure' => '/%postname%/' );
$GLOBALS['qvars'] = array();
function __( $v ) { return $v; }
function sanitize_key( $v ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $v ) ); }
function sanitize_text_field( $v ) { return trim( strip_tags( (string) $v ) ); }
function wp_unslash( $v ) { return $v; }
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['opts'] ) ? $GLOBALS['opts'][$k] : $d; }
function update_option( $k, $v ) { $GLOBALS['opts'][$k] = $v; return true; }
function home_url( $p = '/' ) { return 'https://example.test' . ( '/' === substr( $p, 0, 1 ) ? $p : '/' . $p ); }
function add_query_arg( $args, $url ) { return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $args ); }
function get_query_var( $k ) { return $GLOBALS['qvars'][$k] ?? ''; }
function add_action() {}
function add_filter() {}
function add_rewrite_rule() {}
function flush_rewrite_rules() {}
function nocache_headers() {}
require dirname( __DIR__ ) . '/includes/class-ecapla-certverify-public-router.php';
function ok( $c, $m ) { if ( ! $c ) { throw new RuntimeException( $m ); } echo "PASS  $m\n"; }
$safe='CERT-2026-0001';
$token=Ecapla_CertVerify_Public_Router::token_for_number($safe);
ok($token===$safe,'safe certificate number remains readable token');
ok(Ecapla_CertVerify_Public_Router::number_from_token($token)===$safe,'safe token round trips');
$complex='Urdu / Batch ۱';
$complex_token=Ecapla_CertVerify_Public_Router::token_for_number($complex);
ok(0===strpos($complex_token,'~'),'complex number uses encoded transport token');
ok(false===strpos($complex_token,'/'),'encoded token remains one URL segment');
ok(Ecapla_CertVerify_Public_Router::number_from_token($complex_token)===$complex,'complex token round trips UTF-8 and slash');
ok(''===Ecapla_CertVerify_Public_Router::number_from_token('~%%%'),'malformed encoded token fails closed');
ok(''===Ecapla_CertVerify_Public_Router::token_for_number(str_repeat('A',101)),'overlong number rejected');
$url=Ecapla_CertVerify_Public_Router::verification_url($safe);
ok($url==='https://example.test/certloom/verify/CERT-2026-0001/','pretty verification URL canonical');
$profile=Ecapla_CertVerify_Public_Router::profile_url($safe);
ok($profile==='https://example.test/certloom/credential/CERT-2026-0001/','pretty profile URL canonical');
$GLOBALS['opts']['permalink_structure']='';
$plain=Ecapla_CertVerify_Public_Router::verification_url($safe);
ok(false!==strpos($plain,'certloom_route=verify'),'plain permalink fallback keeps route query var');
ok(false!==strpos($plain,'certloom_token=CERT-2026-0001'),'plain permalink fallback keeps token');
$GLOBALS['qvars']=array('certloom_route'=>'credential','certloom_token'=>$complex_token);
ok(Ecapla_CertVerify_Public_Router::current_route()==='credential','credential route recognized');
ok(Ecapla_CertVerify_Public_Router::requested_number()===$complex,'route token resolves requested number');
$vars=Ecapla_CertVerify_Public_Router::query_vars(array('p'));
ok(in_array('certloom_route',$vars,true)&&in_array('certloom_token',$vars,true),'query vars registered');
$robots=Ecapla_CertVerify_Public_Router::robots(array());
ok(!empty($robots['noindex'])&&!empty($robots['nofollow']),'clean routes fail private/noindex by default');
echo "\n15 tests, 0 failures\n";
