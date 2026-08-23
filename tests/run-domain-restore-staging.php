<?php
/** Resumable staging tests for complete-domain Replace restore. */
$root = sys_get_temp_dir() . '/certloom-domain-restore-stage-' . bin2hex( random_bytes( 4 ) );
define( 'ABSPATH', $root . '/web/' );
define( 'WP_CONTENT_DIR', $root . '/web/wp-content' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'MINUTE_IN_SECONDS', 60 );

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $code, $message, $data;
		public function __construct( $code = '', $message = '', $data = null ) { $this->code = $code; $this->message = $message; $this->data = $data; }
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
		public function get_error_data() { return $this->data; }
	}
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function __( $t ) { return $t; }
function absint( $v ) { return abs( (int) $v ); }
function sanitize_key( $key ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $key ) ); }
function wp_json_encode( $value, $flags = 0 ) { return json_encode( $value, $flags ); }
function wp_normalize_path( $path ) { return str_replace( '\\', '/', (string) $path ); }
function trailingslashit( $path ) { return rtrim( (string) $path, '/\\' ) . '/'; }
function untrailingslashit( $path ) { return rtrim( (string) $path, '/\\' ); }
function wp_mkdir_p( $path ) { return is_dir( $path ) || mkdir( $path, 0700, true ); }
function apply_filters( $hook, $value ) { global $root; return 'certloom_private_job_directory' === $hook ? $root . '/private' : $value; }

require_once __DIR__ . '/bootstrap-autoload.php';

use Certloom\Application\Backup\DomainBackupFormat;
use Certloom\Application\Contract\DomainRestoreWorkspaceInterface;
use Certloom\Application\Restore\DomainRestoreStagingService;
use Certloom\Domain\Credential\PublicCredentialId;
use Certloom\Infrastructure\Backup\DomainBackupRepository;
use Certloom\Infrastructure\Files\PrivateJobFileStore;

final class RestoreStageFakeWorkspace implements DomainRestoreWorkspaceInterface {
	public $rows = array();
	public $abandoned = false;
	public $committed = false;
	public $relationship_checks = 0;
	public $receipt = array();
	public function create( $owner_token ) { return array( 'owner_token' => $owner_token, 'suffix' => substr( $owner_token, 0, 12 ) ); }
	public function insert_row( array $workspace, $entity, array $row ) {
		$key = $entity . ':' . (string) $row['id'];
		if ( isset( $this->rows[ $key ] ) ) {
			return $this->rows[ $key ] === $row ? true : new WP_Error( 'retry_conflict', 'x' );
		}
		$this->rows[ $key ] = $row;
		return true;
	}
	public function validate_relationships( array $workspace ) { $this->relationship_checks++; return true; }
	public function touch( array $workspace ) { return true; }
	public function abandon( array $workspace ) { $this->abandoned = true; return true; }
	public function commit_replace( array $workspace, array $configuration, array $receipt = array() ) { $this->committed = true; $this->receipt = $receipt; return true; }
	public function commit_merge( array $workspace, array $configuration, array $receipt = array() ) { $this->committed = true; return true; }
	public function completion_receipt( $owner_token ) { return null; }
	public function recover_interrupted_commit() { return true; }
}

function rs_same( $expected, $actual, $message = '' ) {
	if ( $expected !== $actual ) { throw new RuntimeException( ( $message ? $message . ': ' : '' ) . 'Expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) ); }
}
function rs_true( $value, $message = '' ) { rs_same( true, (bool) $value, $message ); }

function rs_rows() {
	$public = ( new PublicCredentialId() )->generate();
	return array(
		'issuers' => array( array( 'id'=>'1','name'=>'Certloom Test','slug'=>'certloom-test','logo_attachment_id'=>null,'seal_attachment_id'=>null,'settings_json'=>'{}','created_at'=>'2026-08-22 00:00:00','updated_at'=>'2026-08-22 00:00:00' ) ),
		'programs' => array( array( 'id'=>'1','issuer_id'=>'1','code'=>'COURSE-1','title'=>'Course 1','description'=>null,'validity_days'=>null,'active'=>'1','metadata_json'=>'{}','created_at'=>'2026-08-22 00:00:00','updated_at'=>'2026-08-22 00:00:00' ) ),
		'recipients' => array( array( 'id'=>'1','display_name'=>'Learner','first_name'=>null,'middle_name'=>null,'last_name'=>null,'email'=>'learner@example.test','privacy_identity_hashes'=>'[]','privacy_anonymized_at'=>null,'wp_user_id'=>null,'external_id'=>null,'custom_fields'=>'{}','created_at'=>'2026-08-22 00:00:00','updated_at'=>'2026-08-22 00:00:00' ) ),
		'signers' => array(),
		'batches' => array(),
		'batch_recipients' => array(),
		'signer_assignments' => array(),
		'templates' => array(),
		'template_revisions' => array(),
		'template_assignments' => array(),
		'credential_series' => array( array( 'id'=>'1','public_id'=>$public,'recipient_id'=>'1','program_id'=>'1','batch_id'=>null,'issuer_id'=>'1','current_version_id'=>'1','administrative_state'=>'active','compliance_state'=>'not_applicable','visibility'=>'verification_summary','created_at'=>'2026-08-22 00:00:00','updated_at'=>'2026-08-22 00:00:00','closed_at'=>null ) ),
		'credential_numbers' => array( array( 'id'=>'1','display_number'=>'CERT-0001','series_id'=>'1','first_version_id'=>'1','created_at'=>'2026-08-22 00:00:00','retired_at'=>null ) ),
		'credential_versions' => array( array( 'id'=>'1','series_id'=>'1','version_number'=>'1','previous_version_id'=>null,'display_number'=>'CERT-0001','issuance_state'=>'issued','legacy_expired'=>'0','valid_from'=>'2026-08-22','issued_at'=>'2026-08-22 00:00:00','expires_at'=>null,'grade'=>null,'source_type'=>'manual','source_ref'=>null,'source_idempotency_key'=>null,'snapshot_json'=>'{}','created_at'=>'2026-08-22 00:00:00','updated_at'=>'2026-08-22 00:00:00' ) ),
		'credential_events' => array( array( 'id'=>'1','series_id'=>'1','version_id'=>'1','event_type'=>'credential.issued','actor_user_id'=>null,'old_state_json'=>null,'new_state_json'=>'{}','reason_code'=>null,'public_reason'=>null,'internal_note'=>null,'meta_json'=>'{}','created_at'=>'2026-08-22 00:00:00' ) ),
	);
}

function rs_make_backup( $job_key, $trailing = '', $version = DomainBackupFormat::VERSION ) {
	$format = new DomainBackupFormat();
	$files  = new PrivateJobFileStore();
	$path   = $files->create_path( $job_key, 'restore-test' );
	if ( is_wp_error( $path ) ) { throw new RuntimeException( $path->get_error_message() ); }
	$rows = rs_rows();
	$config = array(
		'certverify_fields' => array( 'exists'=>true, 'value'=>array() ),
		'certverify_retired_fields' => array( 'exists'=>true, 'value'=>array() ),
		'certverify_settings' => array( 'exists'=>true, 'value'=>array( 'verification_page'=>10 ) ),
	);
	$config_json = wp_json_encode( $config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	$source_entities = DomainBackupFormat::entity_types_for_version( $version );
	if ( DomainBackupFormat::LEGACY_VERSION === absint( $version ) ) {
		foreach ( $rows['credential_series'] as &$legacy_series ) { unset( $legacy_series['batch_id'] ); } unset( $legacy_series );
	}
	if ( absint( $version ) < DomainBackupFormat::VERSION ) {
		foreach ( $rows['recipients'] as &$old_recipient ) { unset( $old_recipient['privacy_identity_hashes'], $old_recipient['privacy_anonymized_at'] ); } unset( $old_recipient );
	}
	$entities = array();
	foreach ( $source_entities as $entity ) {
		$entities[$entity] = array( 'count'=>count($rows[$entity]), 'max_id'=>empty($rows[$entity])?0:1 );
	}
	$manifest = array( 'type'=>'manifest','format'=>DomainBackupFormat::FORMAT,'format_version'=>$version,'plugin_version'=>'1.5.4','generated_at'=>'2026-08-22T00:00:00+00:00','generation'=>'g1','entities'=>$entities,'configuration'=>$config,'configuration_hash'=>hash('sha256',$config_json) );
	$contents = $format->encode_line( $manifest );
	$hash = $format->next_hash( '', $contents, $version );
	$records = 0;
	foreach ( $source_entities as $entity ) {
		foreach ( $rows[$entity] as $row ) {
			$line = $format->encode_line( array( 'type'=>'entity','entity'=>$entity,'data'=>$row ) );
			$contents .= $line;
			$hash = $format->next_hash( $hash, $line, $version );
			$records++;
		}
	}
	$contents .= $format->encode_line( array( 'type'=>'integrity','records'=>$records,'chain_sha256'=>$hash ) );
	$contents .= $trailing;
	file_put_contents( $path, $contents );
	return $path;
}

$owner = str_repeat( 'a', 32 );
$path = rs_make_backup( str_repeat( '1', 32 ) );
$workspace = new RestoreStageFakeWorkspace();
$service = new DomainRestoreStagingService( $workspace );
$state = $service->start( $path, $owner );
rs_same( false, is_wp_error( $state ), 'start' );
rs_same( 'certloom_domain_restore_not_ready', $service->commit_replace( $state )->get_error_code(), 'cannot commit early' );
$original = $state;
$first = $service->continue_stage( $state, 1 );
rs_same( false, is_wp_error( $first ), 'first slice' );
rs_same( 1, count( $workspace->rows ), 'one staged row' );
$retried = $service->continue_stage( $original, 1 );
rs_same( false, is_wp_error( $retried ), 'retry same slice' );
rs_same( 1, count( $workspace->rows ), 'retry idempotent' );
$state = $first;
$loops=0;
while ( empty( $state['complete'] ) && $loops++ < 20 ) {
	$state = $service->continue_stage( $state, 2 );
	if ( is_wp_error( $state ) ) { throw new RuntimeException( $state->get_error_code() ); }
}
rs_true( $state['complete'], 'staging complete' );
rs_same( 7, count( $workspace->rows ), 'all rows staged' );
rs_same( 1, $workspace->relationship_checks, 'relationship validation once' );
rs_same( true, $service->commit_replace( $state ), 'commit delegated only after ready' );
rs_true( $workspace->committed, 'commit reached workspace' );
rs_same( 'replace', $workspace->receipt['mode'] ?? '', 'replace receipt mode' );
rs_same( 7, $workspace->receipt['records'] ?? 0, 'replace receipt records' );
$expected_counts=array_fill_keys(DomainBackupRepository::entity_types(),0);foreach(rs_rows() as $entity=>$rows){$expected_counts[$entity]=count($rows);}rs_same( $expected_counts, $workspace->receipt['counts'] ?? array(), 'replace receipt entity counts' );
echo "PASS  restore staging is bounded, retry-idempotent, integrity-checked and carries crash-replay receipt counts\n";

$bad_path = rs_make_backup( str_repeat( '2', 32 ), "{\"unexpected\":true}\n" );
$bad_ws = new RestoreStageFakeWorkspace();
$bad_service = new DomainRestoreStagingService( $bad_ws );
$bad = $bad_service->start( $bad_path, str_repeat('b',32) );
while ( ! is_wp_error( $bad ) && empty( $bad['complete'] ) ) { $bad = $bad_service->continue_stage( $bad, 50 ); }
rs_same( 'certloom_domain_backup_trailing_data', $bad->get_error_code(), 'trailing data rejected' );
rs_true( $bad_ws->abandoned, 'unsafe stage abandoned' );
echo "PASS  trailing content after integrity footer aborts and cleans the restore workspace\n";

// Corrupt a scalar field while recomputing the chain: semantic validation must still reject it.
$semantic_path = rs_make_backup( str_repeat( '3', 32 ) );
$lines = file( $semantic_path );
$format = new DomainBackupFormat();
for ( $i=1; $i<count($lines)-1; $i++ ) {
	$item=json_decode(trim($lines[$i]),true);
	if ( 'entity'===($item['type']??'') && 'credential_series'===($item['entity']??'') ) { $item['data']['visibility']='made_up_mode'; $lines[$i]=$format->encode_line($item); break; }
}
$hash=$format->next_hash('', $lines[0]);$records=0;
for($i=1;$i<count($lines)-1;$i++){ $hash=$format->next_hash($hash,$lines[$i]);$records++; }
$lines[count($lines)-1]=$format->encode_line(array('type'=>'integrity','records'=>$records,'chain_sha256'=>$hash));
file_put_contents($semantic_path,implode('',$lines));
$semantic_ws=new RestoreStageFakeWorkspace();$semantic_service=new DomainRestoreStagingService($semantic_ws);$semantic=$semantic_service->start($semantic_path,str_repeat('c',32));
while(!is_wp_error($semantic)&&empty($semantic['complete'])){$semantic=$semantic_service->continue_stage($semantic,50);}
rs_same('certloom_domain_restore_credential_series_invalid',$semantic->get_error_code(),'invalid visibility');
rs_true($semantic_ws->abandoned,'semantic stage abandoned');
echo "PASS  a hash-consistent but semantically invalid credential row is rejected before promotion\n";


// A hash-consistent JSON scalar must be rejected for fields whose repositories require structured arrays.
$scalar_json_path = rs_make_backup( str_repeat( '4', 32 ) );
$lines = file( $scalar_json_path );
$format = new DomainBackupFormat();
for ( $i=1; $i<count($lines)-1; $i++ ) {
	$item=json_decode(trim($lines[$i]),true);
	if ( 'entity'===($item['type']??'') && 'credential_versions'===($item['entity']??'') ) { $item['data']['snapshot_json']='"scalar-not-structured"'; $lines[$i]=$format->encode_line($item); break; }
}
$hash=$format->next_hash('', $lines[0]);$records=0;
for($i=1;$i<count($lines)-1;$i++){ $hash=$format->next_hash($hash,$lines[$i]);$records++; }
$lines[count($lines)-1]=$format->encode_line(array('type'=>'integrity','records'=>$records,'chain_sha256'=>$hash));
file_put_contents($scalar_json_path,implode('',$lines));
$scalar_ws=new RestoreStageFakeWorkspace();$scalar_service=new DomainRestoreStagingService($scalar_ws);$scalar=$scalar_service->start($scalar_json_path,str_repeat('d',32));
while(!is_wp_error($scalar)&&empty($scalar['complete'])){$scalar=$scalar_service->continue_stage($scalar,50);}
rs_same('certloom_domain_restore_json_field_invalid',$scalar->get_error_code(),'structured JSON scalar rejected');
rs_true($scalar_ws->abandoned,'structured JSON scalar stage abandoned');
echo "PASS  hash-consistent JSON scalars cannot enter structured domain columns\n";

// A genuine pre-1.6.4 v1 stream lacks batch_id in credential_series and the new entity families entirely.
$legacy_path = rs_make_backup( str_repeat( '5', 32 ), '', DomainBackupFormat::LEGACY_VERSION );
$legacy_ws = new RestoreStageFakeWorkspace();
$legacy_service = new DomainRestoreStagingService( $legacy_ws );
$legacy = $legacy_service->start( $legacy_path, str_repeat( 'e', 32 ) );
while ( ! is_wp_error( $legacy ) && empty( $legacy['complete'] ) ) { $legacy = $legacy_service->continue_stage( $legacy, 50 ); }
rs_same( false, is_wp_error( $legacy ), 'legacy v1 staging' );
rs_true( $legacy['complete'], 'legacy v1 ready' );
rs_true( array_key_exists( 'batch_id', $legacy_ws->rows['credential_series:1'] ), 'legacy batch_id key synthesized' );
rs_same( null, $legacy_ws->rows['credential_series:1']['batch_id'], 'legacy batch_id synthesized null' );
rs_same( 0, $legacy['counts']['signers'], 'legacy signers empty' );
rs_same( 0, $legacy['counts']['batches'], 'legacy batches empty' );
echo "PASS  genuine v1 backups are normalized into the current staging contract without inventing relationships\n";

// A genuine 1.6.4/1.6.5 v2 stream has Batch/Signer families but predates recipient privacy identity digests.
$v2_path = rs_make_backup( str_repeat( '6', 32 ), '', DomainBackupFormat::STRUCTURE_VERSION );
$v2_ws = new RestoreStageFakeWorkspace();
$v2_service = new DomainRestoreStagingService( $v2_ws );
$v2 = $v2_service->start( $v2_path, str_repeat( 'f', 32 ) );
while ( ! is_wp_error( $v2 ) && empty( $v2['complete'] ) ) { $v2 = $v2_service->continue_stage( $v2, 50 ); }
rs_same( false, is_wp_error( $v2 ), 'v2 staging' );
rs_true( $v2['complete'], 'v2 ready' );
rs_true( array_key_exists( 'privacy_identity_hashes', $v2_ws->rows['recipients:1'] ), 'v2 privacy identity key synthesized' );
rs_same( null, $v2_ws->rows['recipients:1']['privacy_identity_hashes'], 'v2 privacy identity synthesized null' );
rs_same( null, $v2_ws->rows['recipients:1']['privacy_anonymized_at'], 'v2 privacy lifecycle marker synthesized null' );
echo "PASS  genuine v2 backups are normalized into the current recipient privacy contract\n";

// A genuine 1.6.6+ v3 stream already has privacy identity fields but predates template entities.
$v3_path = rs_make_backup( str_repeat( '7', 32 ), '', DomainBackupFormat::PRIVACY_VERSION );
$v3_ws = new RestoreStageFakeWorkspace();
$v3_service = new DomainRestoreStagingService( $v3_ws );
$v3 = $v3_service->start( $v3_path, str_repeat( '1', 32 ) );
while ( ! is_wp_error( $v3 ) && empty( $v3['complete'] ) ) { $v3 = $v3_service->continue_stage( $v3, 50 ); }
rs_same( false, is_wp_error( $v3 ), 'v3 staging' );
rs_true( $v3['complete'], 'v3 ready' );
rs_same( 0, $v3['counts']['templates'], 'v3 templates synthesized empty' );
rs_same( 0, $v3['counts']['template_revisions'], 'v3 revisions synthesized empty' );
rs_same( 0, $v3['counts']['template_assignments'], 'v3 assignments synthesized empty' );
echo "PASS  genuine v3 backups remain restorable with template families synthesized empty\n";

echo "\n7 tests, 0 failures\n";
