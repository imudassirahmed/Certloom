<?php

if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
require_once __DIR__ . '/reference_lib.php';
$target = isset( $argv[1] ) && '' !== trim( (string) $argv[1] ) ? (string) $argv[1] : dirname( __DIR__ ) . '/fixtures/rendering/render-plan-manifest.json';
$json = certloom_render_plan_reference_json();
if ( false === file_put_contents( $target, $json ) ) { fwrite( STDERR, "Could not write render-plan manifest.\n" ); exit( 2 ); }
echo 'render_plan_manifest_sha256=' . hash( 'sha256', $json ) . "\n";
echo 'fixtures=' . count( certloom_render_plan_reference_manifest()['fixtures'] ) . "\n";
