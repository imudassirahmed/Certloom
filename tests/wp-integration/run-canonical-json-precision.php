<?php
/** Read-only Local WordPress proof that existing immutable template hashes remain valid. */

if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! function_exists( 'wp_get_environment_type' ) || ! in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) ) { exit( 1 ); }

use Certloom\Application\Rendering\CanonicalJson;
use Certloom\Application\Rendering\LayoutValidator;
use Certloom\Infrastructure\Database\SchemaManager;
use Certloom\Infrastructure\Repository\TemplateRepository;

global $wpdb;
$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id,template_id,layout_json,layout_sha256 FROM %i ORDER BY id ASC', SchemaManager::table( 'template_revisions' ) ), ARRAY_A );
if ( ! is_array( $rows ) || '' !== (string) $wpdb->last_error || ! $rows ) { throw new RuntimeException( 'Existing template revisions were not available for the local compatibility proof.' ); }
$canonical = new CanonicalJson();
$validator = new LayoutValidator();
$templates = new TemplateRepository();
$legacy = 0;
foreach ( $rows as $row ) {
	$decoded = json_decode( (string) $row['layout_json'], true );
	if ( ! is_array( $decoded ) ) { throw new RuntimeException( 'An existing template revision has invalid JSON.' ); }
	$layout = $validator->normalize( $decoded );
	if ( ! $canonical->matches_hash( $layout, $row['layout_sha256'] ) ) { throw new RuntimeException( 'Existing template revision ' . $row['id'] . ' failed historical hash verification.' ); }
	if ( ! hash_equals( (string) $row['layout_sha256'], $canonical->hash( $layout ) ) ) { ++$legacy; }
	$found = $templates->find( (int) $row['template_id'] );
	if ( is_wp_error( $found ) ) { throw new RuntimeException( 'Template ' . $row['template_id'] . ' failed to load: ' . $found->get_error_message() ); }
}
echo 'PASS  ' . count( $rows ) . ' stored template revisions loaded and verified (' . $legacy . " historical 17-digit hashes)\n";
