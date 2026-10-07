<?php
/** Actual native connection normalizers; preflight must not repair or discard corrupt source rows. */
if ( PHP_SAPI !== 'cli' ) { exit; }
require __DIR__ . '/bootstrap-autoload.php';
class WP_Error {}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function __( $value ) { return $value; }
function absint( $value ) { return abs( (int) $value ); }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', $value ) ); }
function sanitize_text_field( $value ) { return trim( strip_tags( $value ) ); }
function current_time() { throw new RuntimeException( 'Read-only recovery validation attempted to repair a timestamp.' ); }
$checks = 0;
function ss_check( $value, $label ) { global $checks; if ( ! $value ) { throw new RuntimeException( $label ); } ++$checks; }
$course = array( 'course_id' => 17, 'program_id' => 8, 'batch_id' => 0, 'template_id' => 0, 'certificate_threshold' => 100.0, 'expiry_mode' => 'permanent', 'expires_at' => null, 'active' => true, 'updated_at' => '2026-10-04 12:00:00' );
$woo = $course; unset( $woo['course_id'], $woo['certificate_threshold'] ); $woo['product_id'] = 17;
$coursera = $course; unset( $coursera['certificate_threshold'] ); $coursera['course_id'] = '00123';
$credly = array( 'program_id' => 8, 'badge_template_id' => '00123', 'suppress_badge_notification_email' => false, 'active' => true, 'updated_at' => $course['updated_at'] );
$hook = array( 'key' => 'hook-aabbccddeeff', 'name' => 'QA App', 'program_id' => 8, 'batch_id' => 0, 'template_id' => 0, 'secret_sealed' => 'v1:gcm:' . base64_encode( str_repeat( 'x', 60 ) ), 'active' => true, 'updated_at' => $course['updated_at'] );
$fixtures = array(
 array( new \Certloom\Infrastructure\Integration\TutorLmsMappingStore(), 'mappings', $course ),
 array( new \Certloom\Infrastructure\Integration\LearnDashMappingStore(), 'mappings', $course ),
 array( new \Certloom\Infrastructure\Integration\WooCommerceMappingStore(), 'mappings', $woo ),
 array( new \Certloom\Infrastructure\Integration\CourseraMappingStore(), 'mappings', $coursera ),
 array( new \Certloom\Infrastructure\Integration\CredlyMappingStore(), 'mappings', $credly ),
 array( new \Certloom\Infrastructure\Integration\WebhookSourceRepository(), 'sources', $hook ),
);
foreach ( $fixtures as list( $store, $container, $row ) ) {
 $index = 'sources' === $container ? 0 : ( $row['course_id'] ?? $row['product_id'] ?? $row['program_id'] );
 $value = array( 'schema_version' => 1, $container => array( $index => $row ) );
 ss_check( $value === $store->validate_recovery_value( $value ), get_class( $store ) . ': native valid storage remains byte-reconstructable without normalization' );
 $reordered = $value; $reordered[$container][$index] = array_reverse( $row, true ); ss_check( $reordered === $store->validate_recovery_value( $reordered ), 'row key order is preserved, not interpreted as authority' );
 foreach ( array( -8, '8', true, null, array( 8 ) ) as $id ) { $bad = $value; $bad[$container][$index]['program_id'] = $id; ss_check( is_wp_error( $store->validate_recovery_value( $bad ) ), 'negative, loosely typed and nested Program references cannot be silently repaired' ); }
 foreach ( array( '2026-02-30 12:00:00', '2026-10-04', str_repeat( 'x', 19 ), "2026-10-04 12:00:0\0" ) as $date ) { $bad = $value; $bad[$container][$index]['updated_at'] = $date; ss_check( is_wp_error( $store->validate_recovery_value( $bad ) ), 'corrupt dates cannot regenerate imported timestamps or throw parser errors' ); }
 foreach ( array( 1, 'true', array( true ) ) as $active ) { $bad = $value; $bad[$container][$index]['active'] = $active; ss_check( is_wp_error( $store->validate_recovery_value( $bad ) ), 'loosely typed activation cannot become runnable import authority' ); }
 $bad = $value; $bad[$container][] = $row; ss_check( is_wp_error( $store->validate_recovery_value( $bad ) ), 'duplicate source identities cannot overwrite one another during preflight' );
 $bad = $value; $bad['unreviewed_scope'] = true; ss_check( is_wp_error( $store->validate_recovery_value( $bad ) ), 'source payload has a closed current scope' );
 $bad = $value; $bad[$container][$index]['unreviewed_row_flag'] = true; ss_check( is_wp_error( $store->validate_recovery_value( $bad ) ), 'unknown source row fields cannot be silently dropped' );
 $bad = $value; unset( $bad[$container][$index]['active'] ); ss_check( is_wp_error( $store->validate_recovery_value( $bad ) ), 'missing activation cannot default to active during recovery' );
 $empty = array( 'schema_version' => 1, $container => array() ); ss_check( $empty === $store->validate_recovery_value( $empty ), 'explicit empty current source catalog stays distinct from absence' );
 if ( isset( $row['certificate_threshold'] ) ) { $zero = $value; $zero[$container][$index]['certificate_threshold'] = 0.0; ss_check( $zero === $store->validate_recovery_value( $zero ), 'a real zero threshold retains its exact numeric value' ); }
 if ( isset( $row['expiry_mode'] ) ) { $bad = $value; $bad[$container][$index]['expiry_mode'] = 'date'; $bad[$container][$index]['expires_at'] = '2026-02-30'; ss_check( is_wp_error( $store->validate_recovery_value( $bad ) ), 'invalid explicit expiry cannot enter staged connection settings' ); }
 if ( isset( $row['secret_sealed'] ) ) { $bad = $value; $bad[$container][$index]['secret_sealed'] = 'plaintext-secret'; ss_check( is_wp_error( $store->validate_recovery_value( $bad ) ), 'malformed encrypted connection storage cannot be accepted as a source secret' ); }
}
$cipher = \Certloom\Application\Restore\WholeStateSealedConnectionFormat::class;
foreach ( array( 'gcm' => 29, 'sodium' => 41 ) as $algorithm => $minimum ) { $value = 'v1:' . $algorithm . ':' . base64_encode( str_repeat( 'a', $minimum ) ); ss_check( $value === $cipher::validate( $value ), 'foreign ciphertext is structurally checked without decryption or authentication claims' ); ss_check( is_wp_error( $cipher::validate( 'v1:' . $algorithm . ':' . base64_encode( str_repeat( 'a', $minimum - 1 ) ) ) ), 'truncated nonce/tag envelopes are refused' ); }
foreach ( array( '', 'plaintext', 'v2:gcm:AAAA', 'v1:unknown:AAAA', 'v1:gcm:not-base64!', 'v1:gcm:' . base64_encode( str_repeat( 'a', 29 ) ) . "\n", 'v1:gcm:' . base64_encode( str_repeat( 'a', 29 ) ) . '=', 'v1:gcm:' . base64_encode( str_repeat( 'a', 70000 ) ) ) as $bad ) { ss_check( is_wp_error( $cipher::validate( $bad ) ), 'malformed, noncanonical and oversized encrypted transport is refused' ); }
$pending = array( 'key' => 'tp-aabbccddeeff', 'course_id' => 17, 'program_id' => 8, 'batch_id' => 0, 'active' => false, 'revision' => 1, 'updated_at' => $course['updated_at'] );
$pending_woo = $pending; $pending_woo['key'] = 'wp-aabbccddeeff'; unset( $pending_woo['course_id'] ); $pending_woo['product_id'] = 17;
$v2 = array( 'key' => 'v2-aabbccddeeff', 'name' => 'QA App', 'program_id' => 8, 'batch_id' => 0, 'fields' => array( 'Result' => 'qa_result', 'Score01' => 'qa_score' ), 'active' => false, 'revision' => 1, 'mapping_revision' => 1, 'secret_sealed' => $hook['secret_sealed'], 'updated_at' => $course['updated_at'] );
foreach ( array( array( new \Certloom\Infrastructure\Integration\TutorPendingMappingRepository(), 1, 'mappings', $pending ), array( new \Certloom\Infrastructure\Integration\WooCommercePendingMappingRepository(), 1, 'mappings', $pending_woo ), array( new \Certloom\Infrastructure\Integration\WebhookV2ConnectionRepository(), 2, 'connections', $v2 ) ) as list( $store, $version, $container, $row ) ) {
 $value = array( 'schema_version' => $version, $container => array( $row ) );
 ss_check( $value === $store->validate_recovery_value( $value ), 'paused native collection and V2 connections preserve exact stored values' );
 $reordered = $value; $reordered[$container][0] = array_reverse( $row, true ); if ( isset( $row['fields'] ) ) { $reordered[$container][0]['fields'] = array_reverse( $row['fields'], true ); }
 ss_check( $reordered === $store->validate_recovery_value( $reordered ), 'nested mapping order can differ while all original scalar types remain strict' );
 foreach ( array( 'program_id', 'batch_id', 'revision' ) as $column ) { foreach ( array( -1, '1', true, null, array( 1 ) ) as $bad_value ) { $bad = $value; $bad[$container][0][$column] = $bad_value; ss_check( is_wp_error( $store->validate_recovery_value( $bad ) ), 'strict Program, Batch and revision values cannot be repaired' ); } }
 foreach ( array( '2026-02-30 12:00:00', "2026-10-04 12:00:0\0" ) as $date ) { $bad = $value; $bad[$container][0]['updated_at'] = $date; ss_check( is_wp_error( $store->validate_recovery_value( $bad ) ), 'real dates are required without parser exceptions' ); }
 foreach ( array( 0, 'false', array( false ) ) as $active ) { $bad = $value; $bad[$container][0]['active'] = $active; ss_check( is_wp_error( $store->validate_recovery_value( $bad ) ), 'paused activation must remain an actual boolean' ); }
 $bad = $value; $bad[$container][] = $row; ss_check( is_wp_error( $store->validate_recovery_value( $bad ) ), 'duplicate connection keys cannot replace a reviewed row' );
 $bad = $value; $bad[$container][0]['unreviewed_scope'] = true; ss_check( is_wp_error( $store->validate_recovery_value( $bad ) ), 'unknown collection connection fields are rejected' );
 $bad = $value; $bad['unreviewed_scope'] = true; ss_check( is_wp_error( $store->validate_recovery_value( $bad ) ), 'closed collection payload shape rejects additional import flags' );
 $empty = array( 'schema_version' => $version, $container => array() ); ss_check( $empty === $store->validate_recovery_value( $empty ), 'empty collection catalog remains explicit' );
 if ( isset( $row['fields'] ) ) {
  foreach ( array( array( 'Result' => 0 ), array( 'Result' => 'qa_result', 'Score01' => 'qa_result' ), array( '01' => 'qa_result' ), array( 'Result' => array( 'qa_result' ) ) ) as $fields ) { $bad = $value; $bad[$container][0]['fields'] = $fields; ss_check( is_wp_error( $store->validate_recovery_value( $bad ) ), 'invalid or duplicate field targets cannot enter a recovered app' ); }
  $bad = $value; $bad[$container][0]['mapping_revision'] = '1'; ss_check( is_wp_error( $store->validate_recovery_value( $bad ) ), 'mapping revision is independently strict' );
  $bad = $value; $bad[$container][0]['secret_sealed'] = 'plaintext'; ss_check( is_wp_error( $store->validate_recovery_value( $bad ) ), 'V2 sealed credentials remain opaque and structurally checked' );
 } else {
  $bad = $value; $other = $row; $other['key'] = substr( $row['key'], 0, 3 ) . '112233445566'; $bad[$container][] = $other; ss_check( is_wp_error( $store->validate_recovery_value( $bad ) ), 'a second connection cannot duplicate a course or product under a new key' );
  foreach ( array( 0, -1, '17', true ) as $source_id ) { $bad = $value; $bad[$container][0][isset( $row['course_id'] ) ? 'course_id' : 'product_id'] = $source_id; ss_check( is_wp_error( $store->validate_recovery_value( $bad ) ), 'native object reference must be a positive actual integer' ); }
 }
}
$markup = '<p style="background-image:url(&#039;https://example.invalid/image.png&#039;)">Hello</p>';
ss_check( \Certloom\Infrastructure\Email\EmailDesignCompiler::same_inline_html( $markup, str_replace( '&#039;', '&apos;', $markup ) ), 'native apostrophe entity normalization does not reject valid background markup' );
ss_check( \Certloom\Infrastructure\Email\EmailDesignCompiler::same_inline_html( $markup, str_replace( '&#039;', '&#x27;', $markup ) ), 'numeric apostrophe aliases preserve the same encoded markup contract' );
ss_check( ! \Certloom\Infrastructure\Email\EmailDesignCompiler::same_inline_html( $markup . '<script>alert(1)</script>', $markup ), 'sanitizer removal of executable markup remains a preflight failure' );
ss_check( ! \Certloom\Infrastructure\Email\EmailDesignCompiler::same_inline_html( $markup, str_replace( '&#039;', "'", $markup ) ), 'literal quotes do not acquire encoded-attribute authority' );
ss_check( ! \Certloom\Infrastructure\Email\EmailDesignCompiler::same_inline_html( $markup, str_replace( 'image.png', 'different.png', $markup ) ), 'changed image identities cannot pass as entity normalization' );
$compiler_css = \Certloom\Infrastructure\Email\EmailDesignCompiler::RESPONSIVE_CSS;
ss_check( \Certloom\Infrastructure\Email\EmailDesignCompiler::same_inline_html( $compiler_css . $markup, $markup ), 'exact legacy compiler CSS left by KSES is repairable without altering email content' );
ss_check( ! \Certloom\Infrastructure\Email\EmailDesignCompiler::same_inline_html( str_replace( 'display:block', 'display:none', $compiler_css ) . $markup, $markup ), 'arbitrary media CSS cannot acquire legacy compiler authority' );
ss_check( ! \Certloom\Infrastructure\Email\EmailDesignCompiler::same_inline_html( $compiler_css . '<script>alert(1)</script>' . $markup, $markup ), 'legacy compiler repair cannot conceal executable markup' );
ss_check( ! \Certloom\Infrastructure\Email\EmailDesignCompiler::same_inline_html( $markup . $compiler_css, $markup ), 'compiler text inside customer content is not silently removed' );
echo $checks . " source semantic checks, 0 failures\n";
