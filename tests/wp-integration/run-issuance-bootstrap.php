<?php
/** Real compact issuance, inherited settings, owned data and recovery; every fixture rolls back. */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
$site = 'C:/Users/Admin/Local Sites/ecapla-test-wp-site/app/public';
$backup = getenv( 'CERTLOOM_TEST_DB_BACKUP' ); $sha = getenv( 'CERTLOOM_TEST_DB_BACKUP_SHA256' );
if ( realpath( (string) getenv( 'CERTLOOM_TEST_WP_ROOT' ) ) !== realpath( $site ) || ! $backup || ! is_file( $backup ) || ! is_string( $sha ) || ! hash_equals( $sha, hash_file( 'sha256', $backup ) ) ) { fwrite( STDERR, "Exact Local site and verified private snapshot required.\n" ); exit( 1 ); }
$GLOBALS['wp_filter']['pre_http_request'][PHP_INT_MAX][] = array( 'function' => static function () { return new WP_Error( 'qa_transport_disabled' ); }, 'accepted_args' => 3 );
fwrite( STDOUT, "BOOT Local WordPress with external transport disabled\n" );
if ( ! defined( 'DISABLE_WP_CRON' ) ) { define( 'DISABLE_WP_CRON', true ); } if ( ! defined( 'WP_HTTP_BLOCK_EXTERNAL' ) ) { define( 'WP_HTTP_BLOCK_EXTERNAL', true ); } require $site . '/wp-load.php';
if ( 'local' !== wp_get_environment_type() || 'http://ecapla.local' !== untrailingslashit( home_url() ) || 'local' !== DB_NAME || 10006 !== (int) $wpdb->get_var( 'SELECT @@port' ) ) { throw new RuntimeException( 'Refusing another site.' ); }
add_filter( 'pre_wp_mail', '__return_false', PHP_INT_MAX ); add_filter( 'pre_http_request', static function () { return new WP_Error( 'qa_transport_disabled' ); }, PHP_INT_MAX );

use Certloom\Infrastructure\Database\Transaction;
use Certloom\Infrastructure\Database\SchemaManager;
use Certloom\Infrastructure\Data\LegacyWriteGuard;
$checks = array(); $failure = null;
$check = static function ( $ok, $label ) use ( &$checks ) { if ( ! $ok ) { throw new RuntimeException( $label ); } $checks[] = $label; fwrite( STDOUT, 'PASS ' . $label . "\n" ); };
$ok = static function ( $value ) { if ( is_wp_error( $value ) ) { throw new RuntimeException( $value->get_error_code() ); } return $value; };
$fingerprint = static function () {
	global $wpdb; $out = array();
	$tables = array_merge( array( $wpdb->users, $wpdb->usermeta, $wpdb->posts, $wpdb->postmeta, $wpdb->options ), array_map( array( SchemaManager::class, 'table' ), \Certloom\Infrastructure\Backup\DomainBackupRepository::entity_types() ) );
	foreach ( $tables as $table ) { $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i', $table ), ARRAY_A ); if ( $wpdb->last_error ) { throw new RuntimeException( 'Fingerprint read failed.' ); } $encoded = array_map( 'wp_json_encode', $rows ); sort( $encoded ); $out[ $table ] = hash( 'sha256', implode( "\n", $encoded ) ); }
	return $out;
};

fwrite( STDOUT, "BOOT complete; fingerprinting database\n" );
$before = $fingerprint();
fwrite( STDOUT, "Starting owned rollback fixtures\n" );
$result = ( new Transaction() )->run( static function () use ( $check, $ok, &$failure ) {
 try {
  global $wpdb;
  $admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) ); $actor = (int) $admins[0]; wp_set_current_user( $actor );
  $nonce = 'qa-short-' . bin2hex( random_bytes( 6 ) );
  $programs = new \Certloom\Infrastructure\Repository\ProgramRepository(); $batches = new \Certloom\Infrastructure\Repository\BatchRepository(); $recipients = new \Certloom\Infrastructure\Repository\RecipientRepository();
  $templates = new \Certloom\Infrastructure\Repository\TemplateRepository(); $registrations = new \Certloom\Infrastructure\Repository\ProgramRegistrationRepository();
  $profile = \Certloom\Application\Program\ProgramProfile::defaults(); $profile['validity_mode'] = 'duration'; $profile['validity_value'] = 1; $profile['validity_unit'] = 'years';
  $metadata = $ok( ( new \Certloom\Application\Program\ProgramProfile() )->with_metadata( array(), $profile ) );
  $program = $ok( $programs->create( array( 'title' => 'QA compact Program', 'code' => $nonce, 'metadata' => $metadata ) ) );
  $batch = $ok( $batches->create( array( 'program_id' => $program, 'name' => 'QA intake', 'code' => $nonce, 'end_date' => '2026-06-30', 'active' => true ) ) );
  $recipient = $ok( $recipients->create( array( 'display_name' => 'QA compact customer' ) ) );
  $other_recipient = $ok( $recipients->create( array( 'display_name' => 'QA other customer' ) ) );
  // The global contract is replaced only inside the fully rolled-back fixture.
  update_option( \Certloom\Infrastructure\Repository\ScopedFieldGlobalRepository::OPTION, \Certloom\Application\Fields\ScopedFieldContract::empty_global( true ), false );
  $make_design = static function ( $suffix ) use ( $ok, $templates, $nonce ) {
   $id = $ok( $templates->create( array( 'name' => 'QA design ' . $suffix, 'machine_key' => $nonce . $suffix, 'layout' => \Certloom\Application\Templates\TemplateDefaults::blank_a4_landscape() ) ) );
   $row = $ok( $templates->find( $id ) ); $ok( $templates->publish( $id, $row['current_revision_id'], $row['current_revision']['layout_sha256'] ) ); return $id;
  };
  $design = $make_design( '-program' ); $batch_design = $make_design( '-batch' ); $ok( $templates->assign( $design, 'program', $program ) ); $ok( $templates->assign( $batch_design, 'batch', $batch ) );
  $signers = new \Certloom\Infrastructure\Repository\SignerRepository(); $signer = $ok( $signers->create( array( 'display_name' => 'QA signer', 'slug' => $nonce, 'active' => true ) ) ); $ok( $signers->add_assignment( $signer, 'batch', $batch ) );
  $controller = new \Certloom\Infrastructure\Admin\IssueCredentialRest(); $settings_service = new \Certloom\Infrastructure\Admin\ProgramIssuanceSettings();
  $request = new WP_REST_Request( 'GET' ); foreach ( array( 'program_id' => $program, 'batch_id' => $batch, 'recipient_id' => $recipient ) as $key => $value ) { $request->set_param( $key, $value ); }
  $boot = $ok( $controller->program_bootstrap( $request ) ); $data = $boot->get_data();
  $check( $data['settings'] === $ok( $settings_service->resolve( $program, $batch ) ), 'One-request startup returns current Program and Batch settings.' );
  $check( '2026-06-30' === $data['settings']['valid_from'] && 'design' === $data['settings']['presentation_mode'] && $recipient === $data['recipient']['id'], 'Batch date, configured certificate appearance and recipient identity are inherited.' );
  $check( false !== strpos( $boot->get_headers()['Cache-Control'], 'no-store' ) && ! isset( $data['email_designs'], $data['field_contract'] ), 'Startup is private and omits catalogs and field editor data.' );
  $call = static function ( $method, $payload ) use ( $controller ) { $r = new WP_REST_Request( 'POST' ); $r->set_header( 'Content-Type', 'application/json' ); $r->set_body( wp_json_encode( $payload ) ); return $controller->$method( $r ); };
  $input = array( 'program_id' => $program, 'batch_id' => $batch, 'recipient_mode' => 'existing', 'recipient_id' => $recipient, 'valid_from' => '1999-01-01', 'display_number' => $nonce, 'title_source' => 'custom', 'credential_title' => 'Forged title', 'presentation_mode' => 'details_only', 'template_id' => $design, 'signer_id' => 2147483647, 'expiry_mode' => 'permanent', 'custom_merge_fields' => array( 'score' => 'Forged result' ) );
  $derived = $ok( $settings_service->request( $input ) );
  $check( 'program' === $derived['title_source'] && 'inherited' === $derived['design_source'] && 'design' === $derived['presentation_mode'] && 'program' === $derived['expiry_mode'] && '2026-06-30' === $derived['valid_from'] && ! isset( $derived['custom_merge_fields'], $derived['signer_id'], $derived['template_id'] ), 'Short issuance cannot override title, design, signing, expiry rules, Batch date or collected data.' );
  $payload = array( 'use_program_settings' => true, 'issuance' => $input, 'email' => array( 'mode' => 'review', 'template_id' => 'forged' ) );
  $review = $ok( $call( 'review', $payload ) )->get_data();
  $check( 'QA compact Program' === $review['review']['credential_title'] && $batch_design === $review['review']['template']['id'] && 'batch' === $review['review']['template_resolution'] && $signer === $review['review']['signer']['id'] && '2027-06-30' === $review['review']['expires_at'], 'Canonical review applies Batch design/signing and Program title/expiry.' );
  $check( array( 'mode' => 'later', 'template_id' => 'automatic' ) === $review['email'], 'The compact flow preserves inherited email rules and provides manual delivery after issuance.' );
  $check( 0 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE recipient_id=%d', SchemaManager::table( 'credential_series' ), $recipient ) ), 'Review never creates a credential or recipient enrollment.' );
  $payload['review_fingerprint'] = $review['review_fingerprint'];
  $receipt = $ok( $call( 'issue', $payload ) )->get_data()['receipt'];
  $check( $receipt['version_id'] > 0 && '2027-06-30' === $receipt['expires_at'], 'Compact confirmation uses the real canonical issuance transaction.' );
  $replayed = $ok( $call( 'issue', $payload ) )->get_data()['receipt'];
  $check( $replayed['version_id'] === $receipt['version_id'] && $replayed['idempotent_replay'], 'Retrying the same signed issuance returns the original Version.' );
  $ok( $programs->update( $program, array( 'active' => false ) ) );
  $replayed = $ok( $call( 'issue', $payload ) )->get_data()['receipt'];
  $check( $replayed['version_id'] === $receipt['version_id'] && $replayed['idempotent_replay'], 'A committed result is reconciled even if Program settings subsequently become unavailable.' );
  $ok( $programs->update( $program, array( 'active' => true ) ) );
  $service = new \Certloom\Application\Issuance\ManualIssuanceService();
  $check( is_wp_error( $service->receipt_for_review( $payload['review_fingerprint'], $actor + 100000 ) ), 'Receipt reconciliation is bound to the original issuing administrator.' );
  $second = $payload; $second['issuance']['recipient_id'] = $other_recipient; unset( $second['review_fingerprint'] ); $fresh = $ok( $call( 'review', $second ) )->get_data();
  $existing_assignment = $ok( $templates->get_assignment( 'batch', $batch ) ); $ok( $templates->assign( $design, 'batch', $batch, 100, $existing_assignment['id'], $existing_assignment['updated_at'] ) ); $second['review_fingerprint'] = $fresh['review_fingerprint'];
  $error = $call( 'issue', $second ); $check( is_wp_error( $error ) && 'certloom_manual_issue_review_stale' === $error->get_error_code(), 'Changing an inherited design after review prevents an unreviewed commit.' );
  $check( 0 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE recipient_id=%d', SchemaManager::table( 'credential_series' ), $other_recipient ) ), 'A stale review creates no credential.' );
  $r1 = $ok( $registrations->ensure( $other_recipient, $program, $batch, 'manual', $nonce . '-attempt-1' ) ); $r2 = $ok( $registrations->ensure( $other_recipient, $program, $batch, 'manual', $nonce . '-attempt-2' ) );
  $ok( $registrations->ensure( $recipient, $program, $batch, 'manual', $nonce . '-other-owner' ) );
  $choices = $ok( $registrations->choices_for_issuance( $other_recipient, $program, $batch ) );
  $check( array( $r2['id'], $r1['id'] ) === array_column( $choices, 'id' ) && ! isset( $choices[0]['fields'] ), 'Enrollment choices expose only matching owned identities.' );
  $second['issuance']['registration_id'] = $r1['id']; unset( $second['review_fingerprint'] ); $owned = $ok( $call( 'review', $second ) )->get_data();
  $check( preg_match( '/^[a-f0-9]{64}$/', $owned['review_fingerprint'] ), 'An explicitly chosen owned enrollment can be reviewed.' );
  $fields = Ecapla_CertVerify_Fields::get_fields(); $key_suffix = str_replace( '-', '_', $nonce );
  $program_key = $key_suffix . '_hours'; $registration_key = $key_suffix . '_enrollment'; $recipient_key = $key_suffix . '_father'; $source_key = $key_suffix . '_result';
  foreach ( array( $program_key => array( 'staff', 'program', 'never' ), $registration_key => array( 'recipient', 'registration', 'before_issue' ), $recipient_key => array( 'recipient', 'recipient', 'profile' ), $source_key => array( 'source', 'achievement', 'never' ) ) as $key => $config ) {
   $fields[] = array( 'key' => $key, 'label' => 'QA ' . $key, 'type' => 'text', 'sensitivity' => 'personal', 'public_exposure' => 'private', 'collection_policy' => array( '_meta' => array( 'schema' => \Certloom\Application\Fields\FieldCollectionPolicy::SCHEMA, 'schema_version' => 1, 'configured' => true ), 'writer' => $config[0], 'lifetime' => $config[1], 'collect_when' => $config[2], 'approval_required' => false, 'show_on_certificate' => false, 'help' => '' ) );
  }
  $check( Ecapla_CertVerify_Fields::save_fields( $fields ), 'Owned fixture configures Program, recipient, enrollment and source field policies.' );
  $catalog = Ecapla_CertVerify_Fields::get_field_map(); $scope = new \Certloom\Application\Fields\ScopedFieldContract(); $program_row = $ok( $programs->find( $program ) );
  $metadata = $ok( $scope->with_program_metadata( $program_row['metadata'], array( $program_key => 'required', $registration_key => 'required', $recipient_key => 'required' ), $catalog ) );
  $metadata[\Certloom\Application\Program\ProgramProfile::STORAGE_KEY] = $program_row['profile'];
  $metadata[\Certloom\Application\Fields\ProgramFieldDefaults::KEY] = array( '_meta' => array( 'schema' => \Certloom\Application\Fields\ProgramFieldDefaults::SCHEMA, 'schema_version' => 1 ), 'values' => array( $program_key => '40 hours' ) );
  $ok( $programs->update( $program, array( 'metadata' => $metadata ) ) );
  $ok( $recipients->update( $other_recipient, array( 'custom_fields' => array( $recipient_key => 'Customer supplied father name' ) ) ) );
  $r1 = $ok( $registrations->save_fields( $r1['id'], $r1['revision'], array( $registration_key => 'Enrollment supplied ID' ) ) );
  $second['issuance']['custom_merge_fields'] = array( $recipient_key => 'Forged father name', $program_key => 'Forged hours', $registration_key => 'Forged ID' );
  $canonical = $ok( $service->review( $ok( $settings_service->request( $second['issuance'] ) ) ) );
  $check( '40 hours' === $canonical['field_values'][$program_key] && 'Customer supplied father name' === $canonical['field_values'][$recipient_key] && 'Enrollment supplied ID' === $canonical['field_values'][$registration_key], 'Short issuance reads the real Program default, customer profile and exact enrollment instead of browser overrides.' );
  $r1 = $ok( $registrations->save_fields( $r1['id'], $r1['revision'], array() ) );
  $check( is_wp_error( $call( 'review', $second ) ), 'A missing required customer enrollment field blocks review without an issuance-only editor.' );
  $r1 = $ok( $registrations->save_fields( $r1['id'], $r1['revision'], array( $registration_key => 'Enrollment supplied ID' ) ) );
  $metadata = $ok( $scope->with_program_metadata( $metadata, array( $program_key => 'required', $registration_key => 'required', $recipient_key => 'required', $source_key => 'required' ), $catalog ) );
  $ok( $programs->update( $program, array( 'metadata' => $metadata ) ) ); $second['issuance']['custom_merge_fields'][$source_key] = 'Forged passing score';
  $check( is_wp_error( $call( 'review', $second ) ), 'A source-owned result cannot be invented by compact manual issuance.' );
  $check( 0 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE recipient_id=%d', SchemaManager::table( 'credential_series' ), $other_recipient ) ), 'Missing managed data and missing source evidence create no credential.' );
  $second['issuance']['recipient_id'] = $recipient; $foreign = $call( 'review', $second ); $check( is_wp_error( $foreign ), 'Another recipient cannot borrow an enrollment.' );
  $other = $ok( $programs->create( array( 'title' => 'QA other Program', 'code' => $nonce . '-other' ) ) );
  $request->set_param( 'program_id', $other ); $check( is_wp_error( $controller->program_bootstrap( $request ) ), 'A foreign Batch is rejected rather than silently cleared.' );
  $request->set_param( 'program_id', 0 ); $request->set_param( 'batch_id', 0 ); $request->set_param( 'recipient_id', 0 ); $check( null === $ok( $controller->program_bootstrap( $request ) )->get_data()['settings'], 'An unselected Program waits for the picker without loading a workspace.' );
  wp_set_current_user( 0 ); $check( ! $controller->permission(), 'Anonymous visitors cannot access administrator issuance data.' );
 } catch ( Throwable $error ) { $failure = $error->getMessage(); }
 return new WP_Error( 'bootstrap_probe_rollback', 'Always roll back owned compact-issuance fixtures.' );
} );
wp_set_current_user( 0 ); LegacyWriteGuard::invalidate_cache(); wp_cache_flush();
$check( is_wp_error( $result ) && 'bootstrap_probe_rollback' === $result->get_error_code(), 'Owned native fixtures always roll back.' );
$check( $before === $fingerprint(), 'Every database row matches its pre-probe fingerprint.' );
if ( $failure ) { fwrite( STDERR, $failure . "\n" ); exit( 1 ); }
echo wp_json_encode( array( 'success' => true, 'checks_passed' => count( $checks ), 'fixtures_rolled_back' => true, 'external_transport_disabled' => true ) ) . "\n";
