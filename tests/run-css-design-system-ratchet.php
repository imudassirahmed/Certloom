<?php
/** Phase 4.74 CSS debt ratchet. Prevent renewed raw-color/specificity drift. */

if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', __DIR__ . '/' );
$root = dirname( __DIR__ );
$files = array_merge( glob( $root . '/admin/assets/*.css' ) ?: array(), glob( $root . '/public/assets/*.css' ) ?: array(), glob( $root . '/assets/css/*.css' ) ?: array() );
$hex = array(); $important = 0; $raw_radius_values = array(); $raw_radius_occurrences = 0; $raw_shadow_values = array();
foreach ( $files as $file ) {
    $css = (string) file_get_contents( $file );
    preg_match_all( '/#[0-9a-f]{3,8}\b/i', $css, $hm ); foreach ( $hm[0] as $value ) { $hex[ strtoupper( $value ) ] = true; }
    $important += substr_count( $css, '!important' );
    preg_match_all( '/border-radius\s*:\s*([^;}{]+)/i', $css, $rm ); foreach ( $rm[1] as $value ) { $value = trim($value); if ( false === strpos($value,'var(') ) { $raw_radius_values[$value]=true; $raw_radius_occurrences++; } }
    preg_match_all( '/box-shadow\s*:\s*([^;}{]+)/i', $css, $sm ); foreach ( $sm[1] as $value ) { $value=trim($value); if ( 'none' !== strtolower(preg_replace('/\s*!important\s*$/i','',$value)) && false === strpos($value,'var(') && false === strpos($value,'color-mix(') ) { $raw_shadow_values[$value]=true; } }
}
$checks = array(
    array( count($hex) <= 22, 'unique raw hex colors remain at or below the 4.74 token-sweep ceiling (' . count($hex) . '/22)' ),
    array( $important <= 143, '!important usage does not regress above the reviewed interoperability ceiling (' . $important . '/143)' ),
    array( count($raw_radius_values) <= 4, 'raw radius vocabulary does not regress (' . count($raw_radius_values) . '/4)' ),
    array( $raw_radius_occurrences <= 4, 'raw radius occurrences remain bounded after token migration (' . $raw_radius_occurrences . '/4)' ),
    array( count($raw_shadow_values) <= 0, 'raw non-semantic shadow vocabulary does not regress (' . count($raw_shadow_values) . '/0)' ),
);
$fail=0; foreach($checks as $c){ echo ($c[0]?'PASS  ':'FAIL  ') . $c[1] . PHP_EOL; if(!$c[0])$fail++; }
echo "\n" . count($checks) . " CSS design-system ratchet checks, {$fail} failures\n"; exit($fail?1:0);
