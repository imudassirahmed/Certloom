<?php
/** Actual installed Tutor completion hook, customer collection and canonical pending issuance. */
if ( PHP_SAPI !== 'cli' ) { exit( 1 ); }
$root = getenv( 'CERTLOOM_TEST_WP_ROOT' ); $backup = getenv( 'CERTLOOM_TEST_DB_BACKUP' ); $sha = getenv( 'CERTLOOM_TEST_DB_BACKUP_SHA256' );
$site = 'C:/Users/Admin/Local Sites/ecapla-test-wp-site/app/public';
if ( ! $root || realpath( $root ) !== realpath( $site ) || ! $backup || ! is_file( $backup ) || ! is_string( $sha ) || ! hash_equals( $sha, hash_file( 'sha256', $backup ) ) ) { exit( 1 ); }
define( 'DISABLE_WP_CRON', true ); if ( ! defined( 'WP_HTTP_BLOCK_EXTERNAL' ) ) { define( 'WP_HTTP_BLOCK_EXTERNAL', true ); } require $root . '/wp-load.php';
if ( 'local' !== wp_get_environment_type() || 'http://ecapla.local' !== untrailingslashit( home_url() ) || 'local' !== DB_NAME || 10006 !== (int) $wpdb->get_var( 'SELECT @@port' ) ) { exit( 1 ); }
add_filter( 'pre_wp_mail', '__return_false', PHP_INT_MAX ); add_filter( 'pre_http_request', static function () { return new WP_Error( 'qa_no_transport', 'QA' ); }, PHP_INT_MAX );
use Certloom\Application\Fields\FieldCollectionPolicy;
use Certloom\Application\Fields\ScopedFieldContract;
use Certloom\Application\Recipient\RecipientCollectionService;
use Certloom\Application\Issuance\PendingIssuanceService;
use Certloom\Application\Issuance\ManualIssuanceService;
use Certloom\Application\Integration\TutorPendingContext;
use Certloom\Application\Integration\TutorPendingIntake;
use Certloom\Application\Templates\TemplateDefaults;
use Certloom\Infrastructure\Integration\TutorLmsAdapter;
use Certloom\Infrastructure\Integration\TutorLmsBridge;
use Certloom\Infrastructure\Integration\TutorLmsMappingStore;
use Certloom\Infrastructure\Integration\TutorPendingMappingRepository;
use Certloom\Infrastructure\Integration\TutorCompletionEvidence;
use Certloom\Infrastructure\Database\SchemaManager;
use Certloom\Infrastructure\Database\Transaction;
use Certloom\Infrastructure\Data\DatabaseOptions;
use Certloom\Infrastructure\Repository\RecipientRepository;
use Certloom\Infrastructure\Repository\ProgramRepository;
use Certloom\Infrastructure\Repository\TemplateRepository;
use Certloom\Infrastructure\Repository\IssuanceRequestRepository;
use Certloom\Infrastructure\Repository\FieldSubmissionRepository;
use Certloom\Infrastructure\Repository\CredentialRepository;
$checks = array(); $failure = null; $users = array(); $posts = array();
$check = static function ( $ok, $label ) use ( &$checks ) { if ( ! $ok ) { throw new RuntimeException( $label ); } $checks[] = $label; };
$unwrap = static function ( $value ) { if ( is_wp_error( $value ) ) { throw new RuntimeException( $value->get_error_code() . ': ' . $value->get_error_message() ); } return $value; };
$tables = array_merge( array( $wpdb->users,$wpdb->usermeta,$wpdb->posts,$wpdb->postmeta,$wpdb->comments,$wpdb->commentmeta ), array_map( array( SchemaManager::class, 'table' ), array( 'recipients','programs','issuers','signers','batches','batch_recipients','signer_assignments','templates','template_revisions','template_assignments','credential_series','credential_versions','credential_numbers','credential_events','jobs','email_deliveries','destination_deliveries','import_rows','restore_map','program_registrations','field_submissions','issuance_requests','issuance_request_events' ) ) );
$fingerprints = static function () use ( $tables ) { global $wpdb; $out = array(); foreach ( $tables as $table ) { $wpdb->last_error = ''; $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY 1', $table ), ARRAY_A ); if ( $wpdb->last_error || ! is_array( $rows ) ) { throw new RuntimeException( 'Checked preservation read required.' ); } $out[ $table ] = hash( 'sha256', wp_json_encode( $rows ) ); } return $out; };
$options = static function () { global $wpdb; $wpdb->last_error = ''; $rows = $wpdb->get_results( "SELECT option_name,option_value,autoload FROM {$wpdb->options} WHERE LEFT(option_name,9)='certloom_' OR LEFT(option_name,11)='certverify_' OR option_name='cron' ORDER BY option_name", ARRAY_A ); if ( $wpdb->last_error || ! is_array( $rows ) ) { throw new RuntimeException( 'Checked option read required.' ); } return array( 'sha256' => hash( 'sha256', wp_json_encode( $rows ) ), 'keys' => array_column( $rows, 'option_name' ), 'hashes' => array_combine( array_column( $rows, 'option_name' ), array_map( static function ( $row ) { return hash( 'sha256', wp_json_encode( $row ) ); }, $rows ) ) ); };
$before = $fingerprints(); $before_options = $options();
$result = ( new Transaction() )->run( static function () use ( $check, $unwrap, $fingerprints, $options, &$failure, &$users, &$posts ) {
 try {
  global $wpdb; $nonce = bin2hex( random_bytes( 6 ) ); $actor = (int) get_users( array( 'role' => 'administrator','number' => 1,'fields' => 'ids' ) )[0]; wp_set_current_user( $actor );
  $check( TutorLmsAdapter::is_available() && class_exists( '\Tutor\Models\CourseModel' ), 'the actual installed Tutor provider is available' );
  $settings = Ecapla_CertVerify_Settings::get_all(); $settings['numbering_mode'] = 'sequential'; $settings['certificate_name_source'] = 'profile'; $settings['certificate_name_field_key'] = ''; $check( Ecapla_CertVerify_Settings::save( $settings ), 'fixture uses canonical automatic numbering and ordinary profile names' );
  $users[] = $user = $unwrap( wp_insert_user( array( 'user_login' => 'qa-tutor-pending-' . $nonce,'user_pass' => wp_generate_password( 32 ),'display_name' => 'QA Tutor learner','role' => 'subscriber','user_email' => '' ) ) );
  $users[] = $other = $unwrap( wp_insert_user( array( 'user_login' => 'qa-tutor-other-' . $nonce,'user_pass' => wp_generate_password( 32 ),'role' => 'subscriber','user_email' => '' ) ) );
  $recipient = $unwrap( ( new RecipientRepository() )->find_by_wp_user_id( $user ) ); $check( is_array( $recipient ), 'actual subscriber registration creates an exact account connection' );
  $fields = Ecapla_CertVerify_Fields::get_fields(); $keys = array( 'personal' => 'qa_tutor_id_' . $nonce,'enrollment' => 'qa_tutor_enrollment_' . $nonce,'result' => 'qa_tutor_result_' . $nonce );
  foreach ( $keys as $kind => $key ) { $fields[] = array( 'key' => $key,'label' => 'QA Tutor ' . $kind,'type' => 'result' === $kind ? 'number' : 'text','sensitivity' => 'personal','public_exposure' => 'private','collection_policy' => array( '_meta' => array( 'schema' => FieldCollectionPolicy::SCHEMA,'schema_version' => 1,'configured' => true ),'writer' => 'result' === $kind ? 'source' : 'recipient','lifetime' => array( 'personal' => 'recipient','enrollment' => 'registration','result' => 'achievement' )[ $kind ],'collect_when' => 'result' === $kind ? 'never' : 'before_issue','approval_required' => 'personal' === $kind,'show_on_certificate' => false,'help' => '' ) ); }
  $check( Ecapla_CertVerify_Fields::save_fields( $fields ), 'organization enables private personal/enrollment collection and keeps results source owned' );
  $catalog = Ecapla_CertVerify_Fields::get_field_map(); $contract = new ScopedFieldContract(); $metadata = $unwrap( $contract->with_program_metadata( array(), array_fill_keys( array( $keys['personal'],$keys['enrollment'] ), 'required' ), $catalog ) );
  $program = $unwrap( ( new ProgramRepository() )->create( array( 'title' => 'QA Tutor Program ' . $nonce,'code' => 'QTP-' . $nonce,'metadata' => $metadata ) ) );
  $templates = new TemplateRepository(); $design = $unwrap( $templates->create( array( 'name' => 'QA Tutor design ' . $nonce,'machine_key' => 'qa-tutor-' . $nonce,'layout' => TemplateDefaults::blank_a4_landscape() ) ) ); $design_row = $unwrap( $templates->find( $design ) ); $unwrap( $templates->publish( $design,$design_row['current_revision_id'],$design_row['current_revision']['layout_sha256'] ) ); $unwrap( $templates->assign( $design,'program',$program ) );
  $posts[] = $course = $unwrap( wp_insert_post( array( 'post_type' => TutorLmsAdapter::course_post_type(),'post_status' => 'publish','post_title' => 'QA Tutor course ' . $nonce ), true ) );
  $posts[] = $fault_course = $unwrap( wp_insert_post( array( 'post_type' => TutorLmsAdapter::course_post_type(),'post_status' => 'publish','post_title' => 'QA Tutor fault course ' . $nonce ), true ) );
  $mappings = new TutorPendingMappingRepository(); $intake = new TutorPendingIntake(); $requests = new IssuanceRequestRepository();
  $key = $unwrap( $mappings->create( $course,$program,0 ) ); $mapping = $unwrap( $mappings->find( $key ) );
  $check( true === $mapping['active'] && 'tutor_lms_pending' === TutorPendingContext::SOURCE, 'organization explicitly enables the separate customer-detail flow' );
  $check( is_wp_error( $mappings->create( $course,$program,0 ) ), 'one course cannot have two pending connections' );
  $none = $intake->receive( $course,$user ); $check( is_wp_error( $none ) && 'certloom_pending_missing_current_evidence' === $none->get_error_code() && null === $unwrap( $requests->find_by_intake_key( TutorPendingContext::intake_key( $course,$user ) ) ), 'a synthetic completion callback cannot invent a current-time qualification or create intent' );
  $before_noop = $fingerprints(); $noop_options = $options(); $unwrap( $mappings->change( $key,$mappings->revision( $mapping ),'activate' ) ); $check( $before_noop === $fingerprints() && $noop_options['sha256'] === $options()['sha256'], 'unchanged activation is a real configuration no-op' );
  foreach ( array( 'error','zero' ) as $mode ) {
   $fault = static function ( $sql ) use ( $mode,$nonce ) { global $wpdb; if ( 0 === strpos( $sql,'UPDATE ' ) && false !== strpos( $sql, \Certloom\Infrastructure\Recipient\PendingConfigurationQueue::OPTION ) ) { return 'error' === $mode ? 'UPDATE `__qa_missing_tutor_' . $nonce . '` SET option_value=0' : 'UPDATE `' . $wpdb->options . '` SET option_value=option_value WHERE 1=0'; } return $sql; };
   foreach ( array( 'create' => static function () use ( $mappings,$fault_course,$program ) { return $mappings->create( $fault_course,$program,0 ); },'pause' => static function () use ( $mappings,$key,$mapping ) { return $mappings->change( $key,$mappings->revision( $mapping ),'pause' ); },'delete' => static function () use ( $mappings,$key,$mapping ) { return $mappings->change( $key,$mappings->revision( $mapping ),'delete' ); } ) as $command => $operation ) {
    $prior = $fingerprints(); $prior_options = $options(); $old_errors = $wpdb->suppress_errors( true ); add_filter( 'query',$fault,PHP_INT_MAX ); try { $bad = $operation(); } finally { remove_filter( 'query',$fault,PHP_INT_MAX ); $wpdb->suppress_errors( $old_errors ); }
    $check( is_wp_error( $bad ) && $prior === $fingerprints() && $prior_options['sha256'] === $options()['sha256'], $command . ' rolls back ' . $mode . ' outbox failure, configuration, generation and cron' );
   }
  }
  $check( \Tutor\Models\CourseModel::mark_course_as_completed( $course,$user ), 'the actual Tutor completion API writes its proof and invokes the real completion hook' );
  $waiting = $unwrap( $requests->find_by_intake_key( TutorPendingContext::intake_key( $course,$user ) ) );
  $check( is_array( $waiting ) && 'waiting' === $waiting['status'] && TutorPendingContext::SOURCE === $waiting['source_type'] && 'waiting' === get_option( TutorLmsBridge::LAST_RESULT_OPTION )['status'], 'real provider hook creates a durable waiting request with useful operational status' );
  $check( ! $waiting['version_id'] && ! $waiting['series_id'] && (int) $waiting['registration_id'] > 0, 'missing customer information creates only one exact server-owned enrollment and no certificate' );
  $gate = new ReflectionProperty( \Certloom\Infrastructure\Migration\DomainFeatureGate::class,'scopes' ); $authority = $gate->getValue();
  try {
   $gate->setValue( null,array( 'write_paths' => false,'admin_preview' => true,'data_tools' => false ) );
   $check( is_wp_error( $intake->receive( $course,$user ) ), 'closed current writing authority rejects automatic Tutor intake' );
   $held = $unwrap( ( new PendingIssuanceService() )->process( $waiting['id'] ) );
   $check( 'blocked' === $held['status'] && 'source_authority_closed' === json_decode( $held['blockers_json'],true )[0]['code'], 'canonical worker independently checks current Tutor source authority' );
  } finally { $gate->setValue( null,$authority ); }
  $check( 'waiting' === $unwrap( ( new PendingIssuanceService() )->process( $waiting['id'] ) )['status'], 'restored authority rechecks original completion without supplying customer answers' );
  $replay = $unwrap( $intake->receive( $course,$user ) ); $check( $replay['id'] === $waiting['id'] && $replay['idempotent_replay'], 'provider redelivery returns the original waiting intent' );
  $collection = new RecipientCollectionService(); wp_set_current_user( $user ); $view = $unwrap( $collection->read_for_user( $user,$waiting['registration_id'],0,$waiting['id'] ) );
  $check( array( $keys['personal'],$keys['enrollment'] ) === array_column( $view['fields'],'key' ), 'learner can enter required before-issue personal and exact enrollment values; results are excluded' );
  $check( is_wp_error( $collection->read_for_user( $other,$waiting['registration_id'],0,$waiting['id'] ) ), 'another subscriber cannot open this learner’s completion form' );
  $check( is_wp_error( $collection->save_for_user( $user,$waiting['registration_id'],$view['revision'],array( $keys['result'] => '100' ),$waiting['id'] ) ), 'the learner cannot forge a trusted result through an owned form' );
  $unwrap( $collection->save_for_user( $user,$waiting['registration_id'],$view['revision'],array( $keys['personal'] => '00123',$keys['enrollment'] => 'EN-00012' ),$waiting['id'] ) );
  $pending = new PendingIssuanceService(); $check( 'waiting' === $unwrap( $pending->process( $waiting['id'] ) )['status'], 'customer save does not replace organization approval' );
  $proposal = $unwrap( ( new FieldSubmissionRepository() )->latest( $recipient['id'],'recipient',$recipient['id'],$keys['personal'] ) ); wp_set_current_user( $actor ); $unwrap( $collection->decide( $proposal['id'],$proposal['revision'],true,$actor ) );
  $source = TutorPendingContext::parse( $waiting['source_ref'] ); $comment = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE comment_ID=%d',$wpdb->comments,$source['comment'] ),ARRAY_A );
  $manual = new ManualIssuanceService(); $review = $unwrap( $manual->review_pending( $waiting['id'] ) );
  $row = $unwrap( $requests->find( $waiting['id'] ) ); $row = $unwrap( $requests->transition( $row['id'],$row['revision'],array( 'status' => 'ready','review_sha256' => $review['review_fingerprint'],'blockers_json' => '[]','next_attempt_at' => null ),'readiness_confirmed' ) ); $row = $unwrap( $requests->transition( $row['id'],$row['revision'],array( 'status' => 'processing' ),'issuance_started' ) );
  $check( 1 === $wpdb->delete( $wpdb->comments,array( 'comment_ID' => $source['comment'] ) ), 'fixture removes only its own completion between review and commit' );
  $bad = $manual->issue_pending( $row['id'],$review['review_fingerprint'] ); $check( is_wp_error( $bad ) && 'certloom_pending_missing_current_evidence' === $bad->get_error_code(), 'an earlier ready review cannot issue after its actual completion was removed' );
  $check( 'waiting' === $unwrap( $pending->process( $waiting['id'] ) )['status'], 'interrupted processing returns to actionable waiting when original completion is missing' );
  $check( 1 === $wpdb->insert( $wpdb->comments,$comment ), 'fixture restores its exact original proof for the deliberate next assessment' );
  $map = $unwrap( $mappings->find( $key ) ); $unwrap( $mappings->change( $key,$mappings->revision( $map ),'pause' ) ); $check( 'blocked' === $unwrap( $pending->process( $waiting['id'] ) )['status'], 'pausing the connection holds an approved request without issuing' );
  $check( is_wp_error( $mappings->change( $key,$mappings->revision( $map ),'activate' ) ), 'stale mapping command cannot reactivate a paused connection' );
  $map = $unwrap( $mappings->find( $key ) ); $unwrap( $mappings->change( $key,$mappings->revision( $map ),'activate' ) );
  $runtime = new \Certloom\Infrastructure\Job\JobRuntime(); $jobs = $runtime->repository();
  $unwrap( $requests->mark_due_for_recipient( $recipient['id'] ) );
  $job_key = $unwrap( $runtime->service()->create( \Certloom\Domain\Job\JobTypes::PENDING_REASSESSMENT,0,array( 'schema_version' => 1,'cutoff' => current_time( 'mysql',true ),'max_id' => $waiting['id'] ),null,$waiting['id'] ) );
  $runner = new \Certloom\Application\Job\JobRunner( $runtime->service(),$jobs,array( \Certloom\Domain\Job\JobTypes::PENDING_REASSESSMENT => new \Certloom\Application\Issuance\PendingIssuanceJobHandler() ) );
  $unwrap( $runner->run( $job_key,bin2hex( random_bytes( 16 ) ) ) ); $job = $unwrap( $jobs->find( $job_key ) );
  $issued = $unwrap( $requests->find( $waiting['id'] ) ); $check( 'issued' === $issued['status'] && 'succeeded' === $job['status'] && 1 === $job['result']['issued'], 'deliberate activation and approved inputs issue through the actual durable JobRunner and canonical pending executor' );
  $version = $unwrap( ( new CredentialRepository() )->find_version( $issued['version_id'] ) );
  $check( '00123' === $version['snapshot']['fields']['values'][ $keys['personal'] ] && 'EN-00012' === $version['snapshot']['fields']['values'][ $keys['enrollment'] ] && TutorPendingContext::SOURCE === $version['snapshot']['issuance']['pending_request_context']['source_type'], 'immutable Version retains exact leading-zero personal/enrollment values and Tutor pending provenance' );
  $late = $unwrap( $intake->receive( $course,$user ) ); $check( $late['idempotent_replay'] && $late['version_id'] === $issued['version_id'] && '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE recipient_id=%d AND program_id=%d',SchemaManager::table( 'credential_series' ),$recipient['id'],$program ) ), 'late provider replay reconciles one immutable Version and never duplicates its number or certificate' );
  $check( '0' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE version_id=%d',SchemaManager::table( 'email_deliveries' ),$issued['version_id'] ) ), 'blank-email QA customer generates no delivery' );
  $bad_meta = $unwrap( $contract->with_program_metadata( array(),array( $keys['result'] => 'required' ),$catalog ) ); $bad_program = $unwrap( ( new ProgramRepository() )->create( array( 'title' => 'QA Tutor result-only ' . $nonce,'code' => 'QTR-' . $nonce,'metadata' => $bad_meta ) ) ); $unwrap( $templates->assign( $design,'program',$bad_program ) );
  $check( is_wp_error( $mappings->create( $fault_course,$bad_program,0 ) ), 'completion-only Tutor pending cannot invent a required source-owned assessment score' );
  $stored = get_option( TutorPendingMappingRepository::OPTION ); update_option( TutorPendingMappingRepository::OPTION,array( 'schema_version' => 1,'mappings' => array( $mapping,$mapping ) ),false );
  $check( is_wp_error( $mappings->read() ) && is_wp_error( ( new \Certloom\Infrastructure\Admin\IntegrationConfigurationDependencyGuard() )->validate_program_context_change( $unwrap( ( new ProgramRepository() )->find( $program ) ) ) ), 'duplicate or malformed pending configuration fails closed in reads and dependency guards' ); update_option( TutorPendingMappingRepository::OPTION,$stored,false );
  $check( is_wp_error( ( new \Certloom\Infrastructure\Integration\TutorCompletionMappingCommand() )->save( array( 'course_id' => $course,'program_id' => $program,'active' => true ),$actor ) ), 'supported completion-only writer cannot overlap an active customer-detail connection' );
  $proof = $unwrap( ( new TutorCompletionEvidence() )->read( $course,$user,$source['comment'] ) ); $check( hash_equals( $source['proof'],$proof['sha256'] ), 'current completion proof is deterministic and read directly from stored Tutor authority' );
  $plain_program = $unwrap( ( new ProgramRepository() )->create( array( 'title' => 'QA Tutor previous flow ' . $nonce,'code' => 'QTL-' . $nonce ) ) ); $unwrap( $templates->assign( $design,'program',$plain_program ) );
  $legacy_store = new TutorLmsMappingStore(); $legacy_command = new \Certloom\Infrastructure\Integration\TutorCompletionMappingCommand();
  $unwrap( $legacy_command->save( array( 'course_id' => $fault_course,'program_id' => $plain_program,'template_id' => 0,'active' => true ),$actor ) );
  foreach ( array( TutorLmsMappingStore::OPTION, \Certloom\Infrastructure\Recipient\PendingConfigurationQueue::OPTION ) as $fault_option ) { foreach ( array( 'error','zero' ) as $mode ) {
   $fault = static function ( $sql ) use ( $mode,$nonce,$fault_option ) { global $wpdb; if ( 0 === strpos( $sql,'UPDATE ' ) && false !== strpos( $sql,$fault_option ) ) { return 'error' === $mode ? 'UPDATE `__qa_missing_tutor_' . $nonce . '` SET option_value=0' : 'UPDATE `' . $wpdb->options . '` SET option_value=option_value WHERE 1=0'; } return $sql; };
   foreach ( array( 'save' => static function () use ( $legacy_command,$fault_course,$plain_program,$actor ) { return $legacy_command->save( array( 'course_id' => $fault_course,'program_id' => $plain_program,'active' => false ),$actor ); }, 'delete' => static function () use ( $legacy_command,$fault_course,$actor ) { return $legacy_command->delete( $fault_course,$actor ); } ) as $command => $operation ) {
    $prior = $fingerprints(); $prior_options = $options(); $old_errors = $wpdb->suppress_errors( true ); add_filter( 'query',$fault,PHP_INT_MAX ); try { $bad = $operation(); } finally { remove_filter( 'query',$fault,PHP_INT_MAX ); $wpdb->suppress_errors( $old_errors ); }
    $check( is_wp_error( $bad ) && $prior === $fingerprints() && $prior_options['sha256'] === $options()['sha256'], 'completion-only ' . $command . ' rolls back ' . $mode . ' failure of ' . $fault_option . ' with all configuration, generation, jobs and cron' );
   }
  } }
  $check( is_wp_error( $mappings->create( $fault_course,$plain_program,0 ) ), 'pending setup refuses an existing active completion-only mapping for the same course' );
  $check( \Tutor\Models\CourseModel::mark_course_as_completed( $fault_course,$other ) && 'issued' === get_option( TutorLmsBridge::LAST_RESULT_OPTION )['status'], 'unconfigured courses retain actual legacy completion-only hook issuance' );
  $unwrap( $legacy_command->delete( $fault_course,$actor ) );
  $prior_options = $options(); $unwrap( $legacy_command->delete( $fault_course,$actor ) ); $check( $prior_options['sha256'] === $options()['sha256'], 'removing an already absent completion-only mapping creates no generation or scheduling write' );
  $other_key = $unwrap( $mappings->create( $fault_course,$plain_program,0 ) );
  $old_receipt = $intake->receive( $fault_course,$other );
  $check( is_wp_error( $old_receipt ) && 'certloom_tutor_pending_existing_receipt' === $old_receipt->get_error_code() && null === $unwrap( $requests->find_by_intake_key( TutorPendingContext::intake_key( $fault_course,$other ) ) ), 'switching a previously issued completion to pending cannot create another intent, number or certificate' );
  $check( \Tutor\Models\CourseModel::mark_course_as_completed( $course,$other ), 'a second actual learner has their own source completion' );
  $other_waiting = $unwrap( $requests->find_by_intake_key( TutorPendingContext::intake_key( $course,$other ) ) );
  $unwrap( ( new \Certloom\Infrastructure\Repository\RecipientWorkflowStore() )->patch( 'issuance_requests',$other_waiting['id'],$other_waiting['revision'],array( 'restore_state' => 'paused' ) ) );
  $restored = $unwrap( $pending->process( $other_waiting['id'] ) );
  $check( 'blocked' === $restored['status'] && 'restore_review_required' === json_decode( $restored['blockers_json'],true )[0]['code'] && empty( $restored['version_id'] ), 'restored Tutor work remains paused even with a current real completion' );
  $check( is_wp_error( $collection->read_for_user( $other,$other_waiting['registration_id'],0,$other_waiting['id'] ) ), 'paused restored work cannot expose a customer completion command' );
  $switch_program = $unwrap( ( new ProgramRepository() )->create( array( 'title' => 'QA Tutor switched target ' . $nonce,'code' => 'QTS-' . $nonce ) ) ); $unwrap( $templates->assign( $design,'program',$switch_program ) );
  $closed = $unwrap( $requests->find( $other_waiting['id'] ) ); $unwrap( ( new \Certloom\Application\Issuance\PendingRequestAdminService() )->command( $actor,$closed['id'],$closed['revision'],'cancel' ) );
  $original = $unwrap( $mappings->find( $key ) ); $unwrap( $mappings->change( $key,$mappings->revision( $original ),'delete' ) );
  $new_key = $unwrap( $mappings->create( $course,$plain_program,0 ) );
  $stable_counts = static function () { global $wpdb; $out = array(); foreach ( array( 'issuance_requests','program_registrations','credential_versions','credential_numbers' ) as $entity ) { $out[ $entity ] = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i',SchemaManager::table( $entity ) ) ); } return $out; }; $before_switch = $stable_counts();
  $recreated = $unwrap( $intake->receive( $course,$user ) );
  $check( $new_key !== $key && $recreated['id'] === $issued['id'] && $recreated['version_id'] === $issued['version_id'] && (int) $recreated['program_id'] === $program && $before_switch === $stable_counts(), 'removing and recreating a connection for a different Program retains the original issued intent, certificate and number' );
  $cancelled_replay = $unwrap( $intake->receive( $course,$other ) );
  $check( $cancelled_replay['id'] === $closed['id'] && 'cancelled' === $cancelled_replay['status'] && $before_switch === $stable_counts(), 'recreated connection cannot reopen a cancelled original completion or replace its enrollment' );
  $recreated_mapping = $unwrap( $mappings->find( $new_key ) ); $unwrap( $mappings->change( $new_key,$mappings->revision( $recreated_mapping ),'delete' ) );
  $unwrap( $legacy_command->save( array( 'course_id' => $course,'program_id' => $switch_program,'active' => true ),$actor ) );
  foreach ( array( $user,$other ) as $identity ) {
   TutorLmsBridge::course_completed( $course,$identity ); $last_switch = get_option( TutorLmsBridge::LAST_RESULT_OPTION );
   $check( 'certloom_integration_existing_pending_request' === $last_switch['error_code'] && $before_switch === $stable_counts(), 'switching an issued or cancelled native completion into completion-only for a different Program cannot create a new certificate (' . ( $identity === $user ? 'issued' : 'cancelled' ) . ')' );
  }
  $check( $version === $unwrap( ( new CredentialRepository() )->find_version( $issued['version_id'] ) ), 'connection and flow switches preserve every original immutable Version field' );
  $unwrap( $legacy_command->delete( $course,$actor ) );
  $check( 1 === $wpdb->delete( SchemaManager::table( 'programs' ),array( 'id' => $plain_program ) ), 'fixture removes only its owned target to verify orphan repair' );
  $orphan = $unwrap( $mappings->find( $other_key ) ); $unwrap( $mappings->change( $other_key,$mappings->revision( $orphan ),'pause' ) );
  $orphan = $unwrap( $mappings->find( $other_key ) ); $check( ! $orphan['active'] && is_wp_error( $mappings->change( $other_key,$mappings->revision( $orphan ),'activate' ) ), 'an orphan connection can be paused but cannot be activated without its valid target' );
  $unwrap( $mappings->change( $other_key,$mappings->revision( $orphan ),'delete' ) ); $check( null === $unwrap( $mappings->find( $other_key ) ), 'an orphan connection can be removed without rewriting historical credentials' );
  return new WP_Error( 'qa_tutor_rollback','Rollback owned fixture.' );
 } catch ( Throwable $error ) { $failure = get_class( $error ) . ': ' . $error->getMessage(); return new WP_Error( 'qa_tutor_failed','Rollback failed fixture.' ); }
} );
foreach ( $users as $user ) { clean_user_cache( $user ); } foreach ( $posts as $post ) { clean_post_cache( $post ); } wp_set_current_user( 0 );
$after_options = $options(); DatabaseOptions::invalidate( array_unique( array_merge( $before_options['keys'],$after_options['keys'] ) ) ); Ecapla_CertVerify_Fields::reset_runtime_cache();
$after = $fingerprints(); $changed_tables = array_keys( array_diff_assoc( $after,$before ) ); $changed_options = array_values( array_unique( array_merge( array_keys( array_diff_assoc( $after_options['hashes'],$before_options['hashes'] ) ),array_keys( array_diff_assoc( $before_options['hashes'],$after_options['hashes'] ) ) ) ) );
$preserved = $after === $before && $after_options['sha256'] === $before_options['sha256']; $success = null === $failure && is_wp_error( $result ) && 'qa_tutor_rollback' === $result->get_error_code() && $preserved;
echo wp_json_encode( array( 'success' => $success,'checks_passed' => count( $checks ),'checks' => $checks,'failure' => $failure,'tracked_rows_options_cron_preserved' => $preserved,'changed_tables' => $changed_tables,'changed_option_names' => $changed_options,'php' => PHP_VERSION,'tutor_version' => defined( 'TUTOR_VERSION' ) ? TUTOR_VERSION : '' ),JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n"; exit( $success ? 0 : 1 );
