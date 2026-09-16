<?php
$root = dirname( __DIR__ );
$plugin = file_get_contents( $root . '/certloom.php' );
$readme = file_get_contents( $root . '/readme.txt' );
if ( ! is_string( $plugin ) || 1 !== preg_match( '/^\s*\*\s*Version:\s*([^\s]+)/m', $plugin, $pm ) ) { throw new RuntimeException( 'Plugin version header missing.' ); }
if ( ! is_string( $readme ) || 1 !== preg_match( '/^Stable tag:\s*([^\s]+)/m', $readme, $rm ) ) { throw new RuntimeException( 'Readme Stable tag missing.' ); }
if ( trim( $pm[1] ) !== trim( $rm[1] ) ) { throw new RuntimeException( 'Plugin version and readme Stable tag must match exactly.' ); }
if ( 1 !== preg_match( "/define\(\s*'ECAPLA_CERTVERIFY_VERSION'\s*,\s*'([^']+)'\s*\)/", $plugin, $cm ) ) { throw new RuntimeException( 'Runtime plugin version constant missing.' ); }
if ( trim( $pm[1] ) !== trim( $cm[1] ) ) { throw new RuntimeException( 'Plugin header and runtime version constant must match exactly.' ); }
if ( false === strpos( file_get_contents( $root . '/tests/build-release.sh' ), 'README_STABLE_TAG' ) ) { throw new RuntimeException( 'Release builder must fail closed on version/readme drift.' ); }
echo "PASS  plugin/readme release identity is synchronized and build-enforced\n\n5 checks, 0 failures\n";
