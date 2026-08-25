<?php
/** Evidence/privacy regression tests for staging final authority-cutover rehearsal. */
namespace {
    define( 'ABSPATH', __DIR__ . '/fake-wp/' );
define( 'DB_NAME', 'certloom_test_db' );
define( 'DB_HOST', 'db.test.local' );
define( 'DB_USER', 'certloom_test_user' );
    define( 'DAY_IN_SECONDS', 86400 );
    define( 'MINUTE_IN_SECONDS', 60 );
    define( 'ECAPLA_CERTVERIFY_VERSION', '1.5.16-test' );
$GLOBALS['wp_version'] = '6.6-test';
    define( 'CERTLOOM_DOMAIN_WRITE_PATHS_READY', false );
    define( 'CERTLOOM_DOMAIN_ADMIN_PREVIEW', false );
    define( 'CERTLOOM_DOMAIN_DATA_TOOLS_READY', false );
    define( 'CERTLOOM_ALLOW_STAGING_CUTOVER_REHEARSAL', true );
    $GLOBALS['scr_options']=array();
    $GLOBALS['scr_source']=array('count'=>2,'max_id'=>9,'generation'=>'legacy-gen-a');
    $GLOBALS['scr_migration_status']='complete';
    $GLOBALS['scr_restore_passed']=true;
    $GLOBALS['scr_authority']='legacy';
    $GLOBALS['scr_receipt']=array();
    $GLOBALS['scr_maintenance']=false;
    $GLOBALS['scr_domain_generation']='domain-gen-a';
    if(!class_exists('WP_Error')){class WP_Error{private $c;private $m;public function __construct($c='',$m=''){$this->c=$c;$this->m=$m;}public function get_error_code(){return $this->c;}public function get_error_message(){return $this->m;}}}
    function is_wp_error($v){return $v instanceof WP_Error;}
    function __($t){return $t;}
    function absint($v){return abs((int)$v);}
    function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/','',(string)$v));}
    function sanitize_text_field($v){return trim(strip_tags((string)$v));}
    function wp_get_environment_type(){return 'staging';}
    function get_option($k,$d=false){return array_key_exists($k,$GLOBALS['scr_options'])?$GLOBALS['scr_options'][$k]:$d;}
    function update_option($k,$v,$autoload=null){$GLOBALS['scr_options'][$k]=$v;return true;}
    function add_option($k,$v,$deprecated='',$autoload=null){if(array_key_exists($k,$GLOBALS['scr_options']))return false;$GLOBALS['scr_options'][$k]=$v;return true;}
    function delete_option($k){if(array_key_exists($k,$GLOBALS['scr_options'])){unset($GLOBALS['scr_options'][$k]);return true;}return false;}
    function scr_same($e,$a,$m){if($e!==$a)throw new \RuntimeException($m.': expected '.var_export($e,true).', got '.var_export($a,true));}
}
namespace Certloom\Infrastructure\Repository { final class LegacyCertificateRepository { public function snapshot(){return $GLOBALS['scr_source'];} } }
namespace Certloom\Application\Migration {
    final class LegacyMigrationState { const COMPLETE='complete'; public function status(){return (string)$GLOBALS['scr_migration_status'];} public function is_complete(){return self::COMPLETE===$this->status();} }
    final class DomainCutoverState { const LEGACY='legacy'; const DOMAIN='domain'; public function authority(){return (string)$GLOBALS['scr_authority'];} public function is_domain_authoritative(){return self::DOMAIN===$this->authority();} public function is_corrupt(){return !in_array($this->authority(),array(self::LEGACY,self::DOMAIN),true);} public function receipt(){return is_array($GLOBALS['scr_receipt'])?$GLOBALS['scr_receipt']:array();} public function has_any_receipt_state(){return false!==$GLOBALS['scr_receipt']&&''!==$GLOBALS['scr_receipt']&&array()!==$GLOBALS['scr_receipt'];} public function is_clean_legacy_state(){return self::LEGACY===$this->authority()&&!$this->has_any_receipt_state();} public function has_committed_receipt(){return !empty($GLOBALS['scr_receipt']);} public function receipt_matches_completed_migration($m=null){return !empty($GLOBALS['scr_receipt']);} public function keep_legacy_authoritative(){$GLOBALS['scr_authority']=self::LEGACY;$GLOBALS['scr_receipt']=array();return true;} }
    final class LegacyRegistryWriteGuard { public function assert_allowed(){return true;} }
}
namespace Certloom\Infrastructure\Data { final class DomainMaintenanceLock { public function active(){return (bool)$GLOBALS['scr_maintenance'];} } final class DomainGeneration { public function current(){return (string)$GLOBALS['scr_domain_generation'];} } }
namespace Certloom\Infrastructure\Migration { final class DomainCutoverCoordinator { const OWNER_OPTION='certloom_domain_cutover_owner'; public function __construct(...$args){} public function promote(){return true;} } }
namespace Certloom\Infrastructure\Verification { final class PublicVerificationGate { const OPTION='certloom_series_public_verification_ready'; public function disable(){unset($GLOBALS['scr_options'][self::OPTION]);return true;} public function is_ready(){return false;} } }
namespace Certloom\Infrastructure\Diagnostics { final class StagingRestoreRehearsal { public static function is_current_passed(){return (bool)$GLOBALS['scr_restore_passed'];} } final class DomainStateFingerprint {} }
namespace {
    
function is_multisite(){ return false; }
$GLOBALS['wpdb'] = new class { public $prefix = 'wp_'; public function db_server_info(){ return 'MySQL test-server'; } public function db_version(){ return '8.0.36'; } };
require dirname(__DIR__).'/src/Application/Rendering/CanonicalJson.php';
    require dirname(__DIR__).'/src/Application/Rendering/RuntimePayloadFingerprint.php';
    require dirname(__DIR__).'/src/Infrastructure/Diagnostics/RuntimePayloadEvidence.php';
require dirname(__DIR__).'/src/Infrastructure/Diagnostics/RuntimeEnvironmentEvidence.php';
    require dirname(__DIR__).'/src/Infrastructure/Diagnostics/StagingCutoverRehearsal.php';
    use Certloom\Infrastructure\Diagnostics\StagingCutoverRehearsal;
    use Certloom\Infrastructure\Diagnostics\RuntimePayloadEvidence;
    use Certloom\Infrastructure\Diagnostics\RuntimeEnvironmentEvidence;
    scr_same(true,StagingCutoverRehearsal::available(),'cutover rehearsal requires staging plus explicit opt-in');
    $now=time();
    $payload=RuntimePayloadEvidence::current(); scr_same(true,is_array($payload),'current payload identity available');
    $runtime_environment=RuntimeEnvironmentEvidence::current(); scr_same(true,is_array($runtime_environment),'current runtime environment identity available');
    $GLOBALS['scr_options'][StagingCutoverRehearsal::RESULT_OPTION]=array(
        'schema'=>StagingCutoverRehearsal::RESULT_SCHEMA,'version'=>StagingCutoverRehearsal::RESULT_VERSION,'plugin_version'=>ECAPLA_CERTVERIFY_VERSION,'runtime_payload'=>$payload,'runtime_environment'=>$runtime_environment,'environment'=>'staging',
        'status'=>'passed','phase'=>'complete','reason_code'=>'','started_at'=>$now-20,'updated_at'=>$now,'checked_at'=>$now,
        'source_count'=>2,'source_max_id'=>9,'source_generation'=>'legacy-gen-a','restore_checked_at'=>$now-30,
        'baseline_hash'=>str_repeat('a',64),'baseline_config_hash'=>str_repeat('b',64),'baseline_records'=>16,'baseline_counts'=>array('issuers'=>1,'programs'=>1,'recipients'=>2,'signers'=>1,'batches'=>1,'batch_recipients'=>2,'signer_assignments'=>1,'credential_series'=>2,'credential_numbers'=>2,'credential_versions'=>2,'credential_events'=>3),
        'receipt_verified'=>true,'legacy_guard_blocked'=>true,'public_gate_verified'=>true,'rollback_verified'=>true,'final_verified'=>true,'domain_generation'=>'domain-gen-a','fingerprint_state'=>null,'error_message'=>'',
    );
    scr_same('passed',StagingCutoverRehearsal::evidence_state(),'fresh coherent cutover rehearsal evidence is green');
    $valid_state=$GLOBALS['scr_options'][StagingCutoverRehearsal::RESULT_OPTION];
    $GLOBALS['scr_options'][StagingCutoverRehearsal::RESULT_OPTION]['runtime_payload']['sha256']=str_repeat('e',64);
    scr_same('stale',StagingCutoverRehearsal::evidence_state(),'same-version production-byte change stales cutover rehearsal evidence');
    $GLOBALS['scr_options'][StagingCutoverRehearsal::RESULT_OPTION]=$valid_state;
    $GLOBALS['scr_options'][StagingCutoverRehearsal::RESULT_OPTION]['runtime_environment']['db_version']='8.0.99';
    scr_same('stale',StagingCutoverRehearsal::evidence_state(),'same payload on a different runtime environment stales cutover rehearsal evidence');
    $GLOBALS['scr_options'][StagingCutoverRehearsal::RESULT_OPTION]=$valid_state;
    $report=StagingCutoverRehearsal::get_text_report();
    if(false===strpos($report,'Final credential authority after rehearsal: LEGACY')||false!==strpos($report,'CERT-SECRET')||false!==strpos($report,'/var/www'))throw new \RuntimeException('Cutover report lost privacy/final-authority contract.');
    $GLOBALS['scr_options'][StagingCutoverRehearsal::RESULT_OPTION]['final_verified']=false;
    scr_same(null,StagingCutoverRehearsal::get_last_result(),'tampered passed evidence without final graph proof is rejected');
    $GLOBALS['scr_options'][StagingCutoverRehearsal::RESULT_OPTION]['final_verified']=true;
    $GLOBALS['scr_domain_generation']='domain-gen-b';
    scr_same('stale',StagingCutoverRehearsal::evidence_state(),'normalized-domain mutation after cutover rehearsal stales cutover evidence');
    $GLOBALS['scr_domain_generation']='domain-gen-a';
    $GLOBALS['scr_source']['generation']='legacy-gen-b';
    scr_same('stale',StagingCutoverRehearsal::evidence_state(),'legacy source change stales cutover rehearsal evidence');
    $GLOBALS['scr_source']['generation']='legacy-gen-a';
    $GLOBALS['scr_authority']='domain';
    scr_same('stale',StagingCutoverRehearsal::evidence_state(),'authority no longer at legacy baseline stales rehearsal evidence');
    echo "PASS  staging cutover rehearsal evidence is privacy-safe, self-consistent, source-bound and requires final legacy baseline\n\n8 checks, 0 failures\n";
}
