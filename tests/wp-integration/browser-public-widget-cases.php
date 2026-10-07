<?php
/** Disposable public widget cases, journaled separately from canonical issuance acceptance. */
if ( PHP_SAPI !== 'cli' ) { exit( 1 ); }
$project = dirname( __DIR__, 2 );
$snapshot = json_decode( file_get_contents( $project . '/output/program-recipient-20261003/private-snapshot.json' ), true );
if ( ! is_array( $snapshot ) || ! is_file( $snapshot['path'] ) || ! hash_equals( $snapshot['sha256'], hash_file( 'sha256', $snapshot['path'] ) ) ) { exit( 1 ); }
define( 'DISABLE_WP_CRON', true ); require 'C:/Users/Admin/Local Sites/ecapla-test-wp-site/app/public/wp-load.php';
if ( 'local' !== wp_get_environment_type() || 'http://ecapla.local' !== untrailingslashit( home_url() ) || 'local' !== DB_NAME || 10006 !== (int) $wpdb->get_var( 'SELECT @@port' ) ) { exit( 1 ); }
add_filter( 'pre_wp_mail', '__return_false', PHP_INT_MAX ); add_filter( 'pre_http_request', static function () { return new WP_Error( 'qa_no_transport' ); }, PHP_INT_MAX ); wp_set_current_user( 1 );
function pw_ok( $v ) { if ( is_wp_error( $v ) ) { throw new RuntimeException( $v->get_error_code() ); } return $v; }
function pw_check( $ok, $message ) { if ( ! $ok ) { throw new RuntimeException( $message ); } }
function pw_digest( array $tables ) { global $wpdb; $out = array(); foreach ( $tables as $table ) { $wpdb->last_error = ''; $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY 1', $table ), ARRAY_A ); pw_check( '' === $wpdb->last_error && is_array( $rows ), 'Baseline read failed.' ); $out[$table] = hash( 'sha256', wp_json_encode( $rows ) ); } return $out; }
function pw_options() { global $wpdb; $wpdb->last_error = ''; $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT option_id,option_name,option_value,autoload FROM %i WHERE option_name IN (%s,%s) ORDER BY option_name', $wpdb->options, \Certloom\Infrastructure\Data\DomainGeneration::OPTION, Ecapla_CertVerify_Settings::OPTION_KEY ), ARRAY_A ); pw_check( is_array( $rows ) && '' === $wpdb->last_error, 'Checked option preservation read required.' ); return $rows; }
function pw_non_css_digest() { global $wpdb; $wpdb->last_error = ''; $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE meta_key<>%s ORDER BY 1', $wpdb->postmeta, '_elementor_css' ), ARRAY_A ); pw_check( '' === (string) $wpdb->last_error && is_array( $rows ), 'Checked non-cache metadata read required.' ); return hash( 'sha256', wp_json_encode( $rows ) ); }
$pointer = $project . '/output/program-recipient-20261004/public-widget-journal.json'; $mode = $argv[1] ?? '';
try {
 if ( 'stage' === $mode ) {
  pw_check( ! is_file( $pointer ), 'Existing public fixture needs cleanup first.' );
  $dir = sys_get_temp_dir() . '/certloom-public-widgets-' . bin2hex( random_bytes( 8 ) ); pw_check( mkdir( $dir, 0700 ), 'Private fixture directory failed.' ); $path = $dir . '/journal.json';
  $tables = array( $wpdb->posts, $wpdb->postmeta ); foreach ( array( 'recipients','credential_series','credential_versions','credential_numbers' ) as $entity ) { $tables[] = \Certloom\Infrastructure\Database\SchemaManager::table( $entity ); }
  $state = array( 'token' => bin2hex( random_bytes( 8 ) ), 'before' => pw_digest( $tables ), 'options_before' => pw_options(), 'options_after' => pw_options(), 'recipient' => 0, 'attachment' => 0, 'cases' => array(), 'image' => $dir . '/certificate.png' );
  $state['before_non_css'] = pw_non_css_digest();
  $save = static function () use ( &$state, $path, $pointer ) { $state['options_after'] = pw_options(); pw_check( false !== file_put_contents( $path, wp_json_encode( $state ) ), 'Private public fixture journal failed.' ); pw_check( false !== file_put_contents( $pointer, wp_json_encode( array( 'path' => $path, 'sha256' => hash_file( 'sha256', $path ) ) ) ), 'Private public fixture pointer failed.' ); }; $save();
  $state['recipient'] = pw_ok( ( new \Certloom\Infrastructure\Repository\RecipientRepository() )->create( array( 'display_name' => 'Amina Khan — Widget QA ' . $state['token'] ) ) ); $save();
  pw_check( copy( $project . '/output/audit-20261001/current-template-independent-review/catalog-png-1.png', $state['image'] ), 'Owned image copy failed.' );
  $state['attachment'] = pw_ok( wp_insert_attachment( array( 'post_title' => 'QA Public widget image ' . $state['token'], 'post_mime_type' => 'image/png', 'post_status' => 'inherit' ), false, 0, true ) ); $save(); pw_check( update_attached_file( $state['attachment'], wp_slash( str_replace( '/', DIRECTORY_SEPARATOR, realpath( $state['image'] ) ) ) ), 'Owned image metadata save failed.' );
  $layout = ( new \Certloom\Application\Templates\PresetCatalog() )->get( 'minimal-a4-landscape' )['layout']; $merge = ( new \Certloom\Application\Templates\TemplatePreviewSamples() )->snapshot_for_layout( $layout ); $json = new \Certloom\Application\Rendering\CanonicalJson(); $repo = new \Certloom\Infrastructure\Repository\CredentialRepository();
  foreach ( array( 'design','details_only','image','summary','private','revoked','unsafe' ) as $case ) {
   $series = pw_ok( $repo->create_series( array( 'recipient_id' => $state['recipient'], 'administrative_state' => 'revoked' === $case ? 'revoked' : 'active', 'visibility' => 'summary' === $case ? 'verification_summary' : ( 'private' === $case ? 'private' : 'public' ) ) ) );
   $public_id = pw_ok( $repo->find_series( $series ) )['public_id']; $number = 'QA-WIDGET-' . strtoupper( $case ) . '-' . $state['token']; $url = pw_ok( ( new \Certloom\Infrastructure\Issuance\NormalizedPublicCredentialUrl() )->verification_url( $public_id ) );
   $state['cases'][$case] = array( 'series' => $series, 'public_id' => $public_id, 'number' => $number, 'version' => 0, 'url' => $url ); $save();
   $values = $merge; foreach ( array( 'recipient.name' => 'Amina Khan — Widget QA', 'credential.title' => 'Professional Certificate in Digital Marketing', 'program.name' => 'Digital Marketing & E-commerce', 'credential.display_number' => $number, 'verification.url' => $url ) as $key => $value ) { $values[$key] = $value; }
   foreach ( array( 'recipient_name' => 'recipient.name', 'credential_title' => 'credential.title', 'program_name' => 'program.name', 'course_name' => 'program.name', 'certificate_number' => 'credential.display_number', 'verification_url' => 'verification.url' ) as $alias => $key ) { $values[$alias] = $values[$key]; }
   $art = in_array( $case, array( 'design','revoked','unsafe','summary','private' ), true ); $frozen = $layout;
   if ( 'unsafe' === $case ) { foreach ( $frozen['elements'] as &$element ) { if ( 'merge' === $element['type'] ) { $element['token'] = 'recipient.email'; break; } } unset( $element ); $values['recipient.email'] = 'PRIVATE-ART@example.invalid'; }
   $issued = array( 'presentation' => array( 'mode' => $art ? 'design' : $case, 'certificate_image_id' => 'image' === $case ? $state['attachment'] : null ), 'recipient' => array( 'display_name' => $values['recipient.name'] ), 'program' => array( 'title' => $values['program.name'], 'description' => 'Completed practical work in marketing strategy, customer journeys and e-commerce.' ), 'issuer' => array( 'name' => 'Certloom QA Academy' ), 'credential' => array( 'title' => $values['credential.title'], 'display_number' => $number, 'verification_url' => $url ), 'template' => array( 'layout' => $art ? $frozen : array(), 'layout_sha256' => $json->hash( $frozen ) ), 'merge' => array( 'values' => $values, 'sha256' => $json->hash( $values ) ), 'media' => 'image' === $case ? pw_ok( ( new \Certloom\Application\Rendering\LayoutAssetManifest() )->capture_image( 'attachment:' . $state['attachment'], new \Certloom\Infrastructure\Rendering\WordPressLocalAssetResolver() ) ) : null, 'fields' => array( 'contract' => array( 'fields' => array( array( 'key' => 'email', 'label' => 'Email', 'type' => 'text', 'public_exposure' => 'private', 'sensitivity' => 'personal' ) ) ), 'values' => array( 'email' => 'PRIVATE-ART@example.invalid' ) ) );
   $version = pw_ok( $repo->create_version( $series, array( 'display_number' => $number, 'issuance_state' => 'issued', 'issued_at' => '2026-09-07 10:00:00', 'source_type' => 'manual_admin', 'snapshot' => $issued ) ) ); $state['cases'][$case]['version'] = $version; $save(); pw_ok( $repo->set_current_version( $series, $version ) );
   $state['cases'][$case]['immutable_sha256'] = hash( 'sha256', $wpdb->get_var( $wpdb->prepare( 'SELECT snapshot_json FROM %i WHERE id=%d', \Certloom\Infrastructure\Database\SchemaManager::table( 'credential_versions' ), $version ) ) ); $save();
  }
  $public = array( 'cases' => array_map( static function ( $case ) { return array_intersect_key( $case, array_flip( array( 'number','url' ) ) ); }, $state['cases'] ) ); file_put_contents( $project . '/output/program-recipient-20261004/public-widget-cases.json', wp_json_encode( $public ) ); echo "{\"staged\":true,\"cases\":7,\"no_license_override\":true}\n"; exit;
 }
 pw_check( is_file( $pointer ), 'Owned public fixture journal required.' ); $record = json_decode( file_get_contents( $pointer ), true ); $path = realpath( $record['path'] );
 pw_check( $path && 'journal.json' === basename( $path ) && realpath( dirname( dirname( $path ) ) ) === realpath( sys_get_temp_dir() ) && preg_match( '/^certloom-public-widgets-[a-f0-9]{16}$/D', basename( dirname( $path ) ) ) && hash_equals( $record['sha256'], hash_file( 'sha256', $path ) ), 'Exact owned public journal required.' ); $state = json_decode( file_get_contents( $path ), true );
 pw_check( $state['options_after'] === pw_options(), 'Concurrent configuration edits retained.' );
 if ( 'layout' === $mode ) {
  $position = $argv[2] ?? ''; pw_check( in_array( $position, array( 'left','right','center' ), true ), 'Invalid QA layout.' ); $settings = Ecapla_CertVerify_Settings::get_all(); $settings['certificate_layout'] = $position; $settings['certificate_center_width'] = 800; pw_check( Ecapla_CertVerify_Settings::save( $settings ), 'Native layout save failed.' ); $state['options_after'] = pw_options(); file_put_contents( $path, wp_json_encode( $state ) ); file_put_contents( $pointer, wp_json_encode( array( 'path' => $path, 'sha256' => hash_file( 'sha256', $path ) ) ) ); echo "{\"layout_saved\":true}\n"; exit;
 }
 pw_check( 'restore' === $mode, 'Unknown QA operation.' );
 foreach ( $state['cases'] as $case ) {
  $series = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d', \Certloom\Infrastructure\Database\SchemaManager::table( 'credential_series' ), $case['series'] ), ARRAY_A ); if ( ! $series ) { continue; } pw_check( $series['public_id'] === $case['public_id'] && (int) $series['recipient_id'] === $state['recipient'], 'Unowned Series retained.' );
  if ( $case['version'] ) { $stored = $wpdb->get_var( $wpdb->prepare( 'SELECT snapshot_json FROM %i WHERE id=%d AND series_id=%d', \Certloom\Infrastructure\Database\SchemaManager::table( 'credential_versions' ), $case['version'], $case['series'] ) ); pw_check( ! isset( $case['immutable_sha256'] ) || hash_equals( $case['immutable_sha256'], hash( 'sha256', $stored ) ), 'Changed issued bytes retained.' ); }
  foreach ( array( 'credential_numbers','credential_versions','credential_series' ) as $entity ) { pw_check( false !== $wpdb->delete( \Certloom\Infrastructure\Database\SchemaManager::table( $entity ), array( 'credential_series' === $entity ? 'id' : 'series_id' => $case['series'] ) ), 'Owned row cleanup failed.' ); }
 }
 if ( $state['recipient'] ) { $name = $wpdb->get_var( $wpdb->prepare( 'SELECT display_name FROM %i WHERE id=%d', \Certloom\Infrastructure\Database\SchemaManager::table( 'recipients' ), $state['recipient'] ) ); pw_check( null === $name || 'Amina Khan — Widget QA ' . $state['token'] === $name, 'Unowned Recipient retained.' ); if ( null !== $name ) { $wpdb->delete( \Certloom\Infrastructure\Database\SchemaManager::table( 'recipients' ), array( 'id' => $state['recipient'] ) ); } }
 if ( $state['attachment'] && get_post( $state['attachment'] ) ) { $stored_path = get_post_meta( $state['attachment'], '_wp_attached_file', true ); pw_check( get_post( $state['attachment'] )->post_title === 'QA Public widget image ' . $state['token'] && wp_normalize_path( $stored_path ) === wp_normalize_path( $state['image'] ), 'Unowned image retained.' ); pw_check( (bool) wp_delete_attachment( $state['attachment'], true ), 'Owned attachment cleanup failed.' ); }
 if ( is_file( $state['image'] ) ) { pw_check( unlink( $state['image'] ), 'Owned image cleanup failed.' ); }
 $after = pw_digest( array_keys( $state['before'] ) );
 $cache_preserved = false;
 if ( $state['before'] !== $after && $state['before'][$wpdb->posts] === $after[$wpdb->posts] && isset( $state['before_non_css'] ) && hash_equals( $state['before_non_css'], pw_non_css_digest() ) ) {
  foreach ( $state['before'] as $table => $hash ) { if ( $table !== $wpdb->postmeta ) { pw_check( hash_equals( $hash, $after[$table] ), 'Unrelated business or page changes retained.' ); } }
  $cache_preserved = true;
 } elseif ( $state['before'] !== $after ) {
  // Elementor may regenerate only the enclosing owned pages' CSS metadata after
  // a frontend request. Prove all other core rows against their parent journal.
  $widget_pointer = json_decode( file_get_contents( $project . '/output/program-recipient-20261004/widget-native-journal.json' ), true );
  pw_check( is_array( $widget_pointer ) && is_file( $widget_pointer['path'] ) && hash_equals( $widget_pointer['sha256'], hash_file( 'sha256', $widget_pointer['path'] ) ), 'Parent widget journal required for owned builder metadata.' ); $widgets = json_decode( file_get_contents( $widget_pointer['path'] ), true );
  foreach ( $widgets['pages'] as $kind => $id ) { pw_check( get_post( $id )->post_title === 'QA Current widgets ' . $widgets['token'] . ' ' . $kind && get_post_meta( $id, '_certloom_widget_qa_owner', true ) === $widgets['token'], 'Changed parent widget page retained.' ); }
  $ids = array_map( 'absint', array_values( $widgets['pages'] ) ); $ids = array_merge( $ids, array_map( 'absint', $wpdb->get_col( 'SELECT ID FROM ' . $wpdb->posts . ' WHERE post_parent IN (' . implode( ',', $ids ) . ')' ) ) );
  foreach ( array_keys( $state['before'] ) as $table ) {
   if ( $table === $wpdb->posts || $table === $wpdb->postmeta ) { $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE ' . ( $table === $wpdb->posts ? 'ID' : 'post_id' ) . ' NOT IN (' . implode( ',', $ids ) . ') ORDER BY 1', $table ), ARRAY_A ); pw_check( $widgets['before'][$table] === hash( 'sha256', wp_json_encode( $rows ) ), 'Unrelated core changes retained.' ); }
   else { pw_check( $state['before'][$table] === $after[$table], 'Unrelated business changes retained.' ); }
  }
 }
 $prior_options = array_column( $state['options_before'], null, 'option_name' ); $current_options = array_column( pw_options(), null, 'option_name' );
 foreach ( array_unique( array_merge( array_keys( $prior_options ), array_keys( $current_options ) ) ) as $key ) {
  $prior = $prior_options[$key] ?? null; $current = $current_options[$key] ?? null; if ( $prior === $current ) { continue; }
  if ( null === $prior ) { $changed = $wpdb->delete( $wpdb->options, array( 'option_id' => $current['option_id'], 'option_value' => $current['option_value'] ) ); }
  elseif ( null === $current ) { $changed = $wpdb->insert( $wpdb->options, $prior ); }
  else { pw_check( $prior['option_id'] === $current['option_id'], 'Changed original option identity retained.' ); $changed = $wpdb->update( $wpdb->options, array( 'option_value' => $prior['option_value'], 'autoload' => $prior['autoload'] ), array( 'option_id' => $current['option_id'], 'option_value' => $current['option_value'], 'autoload' => $current['autoload'] ) ); }
  pw_check( 1 === $changed && '' === $wpdb->last_error, 'Exact options restoration failed.' );
 }
 pw_check( $state['options_before'] === pw_options(), 'Original option bytes and row identities preserved.' ); \Certloom\Infrastructure\Data\DatabaseOptions::invalidate( array( \Certloom\Infrastructure\Data\DomainGeneration::OPTION, Ecapla_CertVerify_Settings::OPTION_KEY ) );
 pw_check( unlink( $path ) && rmdir( dirname( $path ) ) && unlink( $pointer ), 'Exact public journal cleanup failed.' ); echo wp_json_encode( array( 'restored' => true, 'original_business_pages_and_non_cache_metadata_preserved' => true, 'generated_elementor_css_progress_preserved' => $cache_preserved, 'issued_bytes_unchanged' => true ) ) . "\n";
} catch ( Throwable $error ) { fwrite( STDERR, $error->getMessage() . "\n" ); exit( 1 ); }
