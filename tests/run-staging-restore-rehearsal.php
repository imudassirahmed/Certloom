<?php
/** Evidence/privacy regression tests for staging populated backup/restore rehearsal. */
namespace {
    define( 'ABSPATH', __DIR__ . '/fake-wp/' );
    define( 'DAY_IN_SECONDS', 86400 );
    define( 'MINUTE_IN_SECONDS', 60 );
    define( 'ECAPLA_CERTVERIFY_VERSION', '1.5.15-test' );
    define( 'CERTLOOM_DOMAIN_WRITE_PATHS_READY', false );
    define( 'CERTLOOM_DOMAIN_ADMIN_PREVIEW', false );
    define( 'CERTLOOM_DOMAIN_DATA_TOOLS_READY', false );
    define( 'CERTLOOM_ALLOW_STAGING_RESTORE_REHEARSAL', true );
    $GLOBALS['srr_options'] = array();
    $GLOBALS['srr_source'] = array( 'count' => 2, 'max_id' => 9, 'generation' => 'legacy-gen-a' );
    $GLOBALS['srr_migration_status'] = 'complete';
    $GLOBALS['srr_cutover_authority'] = false;
    $GLOBALS['srr_cutover_receipt'] = false;
    if ( ! class_exists( 'WP_Error' ) ) {
        class WP_Error { private $c; private $m; public function __construct($c='',$m=''){ $this->c=$c; $this->m=$m; } public function get_error_code(){return $this->c;} public function get_error_message(){return $this->m;} }
    }
    function is_wp_error( $v ) { return $v instanceof WP_Error; }
    function __( $t ) { return $t; }
    function absint( $v ) { return abs( (int) $v ); }
    function sanitize_key( $v ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $v ) ); }
    function sanitize_text_field( $v ) { return trim( strip_tags( (string) $v ) ); }
    function wp_get_environment_type() { return 'staging'; }
    function get_option( $key, $default = false ) { return array_key_exists( $key, $GLOBALS['srr_options'] ) ? $GLOBALS['srr_options'][ $key ] : $default; }
    function update_option( $key, $value, $autoload = null ) { $GLOBALS['srr_options'][ $key ] = $value; return true; }
    function srr_same( $e, $a, $m ) { if ( $e !== $a ) { throw new \RuntimeException( $m . ': expected ' . var_export($e,true) . ', got ' . var_export($a,true) ); } }
}

namespace Certloom\Infrastructure\Backup {
    final class DomainBackupRepository { public static function entity_types(){ return array('issuers','programs','recipients','signers','batches','batch_recipients','signer_assignments','templates','template_revisions','template_assignments','credential_series','credential_numbers','credential_versions','credential_events'); } }
}
namespace Certloom\Infrastructure\Repository {
    final class LegacyCertificateRepository { public function snapshot(){ return $GLOBALS['srr_source']; } }
}
namespace Certloom\Application\Migration {
    final class LegacyMigrationState { const COMPLETE='complete'; public function status(){ return (string)$GLOBALS['srr_migration_status']; } }
    final class DomainCutoverState { public function is_domain_authoritative(){ return (bool)$GLOBALS['srr_cutover_authority']; } public function has_committed_receipt(){ return (bool)$GLOBALS['srr_cutover_receipt']; } public function is_clean_legacy_state(){ return ! $GLOBALS['srr_cutover_authority'] && ! $GLOBALS['srr_cutover_receipt']; } }
}
namespace Certloom\Infrastructure\Diagnostics { final class StagingMigrationRehearsal {} final class DomainStateFingerprint {} }
namespace Certloom\Application\Backup { final class DomainBackupBuilder {} final class DomainBackupReader {} }
namespace Certloom\Application\Restore { final class DomainRestoreStagingService {} }
namespace Certloom\Infrastructure\Database { final class SchemaManager {} }
namespace Certloom\Infrastructure\Files { final class PrivateJobFileStore {} }
namespace Certloom\Infrastructure\Restore { final class DomainRestoreMergeService {} }

namespace {
    require dirname( __DIR__ ) . '/src/Infrastructure/Diagnostics/StagingRestoreRehearsal.php';
    use Certloom\Infrastructure\Diagnostics\StagingRestoreRehearsal;

    srr_same( true, StagingRestoreRehearsal::available(), 'restore rehearsal requires non-production environment plus explicit opt-in' );
    $now=time();
    $entities=Certloom\Infrastructure\Backup\DomainBackupRepository::entity_types();
    $baseline=array('issuers'=>1,'programs'=>1,'recipients'=>2,'signers'=>1,'batches'=>1,'batch_recipients'=>2,'signer_assignments'=>1,'templates'=>0,'template_revisions'=>0,'template_assignments'=>0,'credential_series'=>2,'credential_numbers'=>2,'credential_versions'=>2,'credential_events'=>3);
    $records=array_sum($baseline);
    $GLOBALS['srr_options'][StagingRestoreRehearsal::RESULT_OPTION]=array(
        'schema'=>StagingRestoreRehearsal::RESULT_SCHEMA,'version'=>StagingRestoreRehearsal::RESULT_VERSION,
        'plugin_version'=>ECAPLA_CERTVERIFY_VERSION,'environment'=>'staging','status'=>'passed','phase'=>'complete','reason_code'=>'',
        'started_at'=>$now-20,'updated_at'=>$now,'checked_at'=>$now,'source_count'=>2,'source_max_id'=>9,'source_generation'=>'legacy-gen-a','migration_checked_at'=>$now-30,
        'baseline_hash'=>str_repeat('a',64),'baseline_records'=>$records,'baseline_config_hash'=>str_repeat('b',64),'baseline_counts'=>$baseline,
        'backup_records'=>$records,'backup_counts'=>$baseline,'backup_chain'=>str_repeat('c',64),'replace_verified'=>true,'merge_verified'=>true,'rollback_verified'=>true,
        'merge_counts'=>array('inserted'=>0,'existing'=>$records,'skipped'=>0),'fingerprint_state'=>null,'backup_state'=>null,'backup_path'=>'',
        'replace_state'=>null,'merge_restore_state'=>null,'merge_state'=>null,'rollback_state'=>null,'error_message'=>'',
    );
    srr_same('passed',StagingRestoreRehearsal::evidence_state(),'fresh internally coherent restore evidence is green');
    $report=StagingRestoreRehearsal::get_text_report();
    if(false!==strpos($report,'/var/www/private.cvbackup')||false!==strpos($report,'CERT-SECRET'))throw new \RuntimeException('Restore report leaked sensitive fixture data.');
    if(false===strpos($report,'Credential authority changed: NO'))throw new \RuntimeException('Restore report lost explicit no-cutover statement.');

    $GLOBALS['srr_options'][StagingRestoreRehearsal::RESULT_OPTION]['merge_counts']['inserted']=1;
    srr_same(null,StagingRestoreRehearsal::get_last_result(),'impossible passed merge counts are rejected as tampered/incoherent evidence');
    $GLOBALS['srr_options'][StagingRestoreRehearsal::RESULT_OPTION]['merge_counts']=array('inserted'=>0,'existing'=>$records,'skipped'=>0);

    $GLOBALS['srr_source']['generation']='legacy-gen-b';
    srr_same('stale',StagingRestoreRehearsal::evidence_state(),'legacy source change stales restore rehearsal evidence');
    $GLOBALS['srr_source']['generation']='legacy-gen-a';
    $GLOBALS['srr_cutover_receipt']=true;
    srr_same('stale',StagingRestoreRehearsal::evidence_state(),'cutover after rehearsal stales pre-cutover restore evidence');
    $GLOBALS['srr_cutover_receipt']=false;
    $GLOBALS['srr_migration_status']='blocked';
    srr_same('stale',StagingRestoreRehearsal::evidence_state(),'migration-state regression stales restore evidence');

    echo "PASS  staging restore rehearsal evidence is privacy-safe, internally consistent, source-bound and pre-cutover-bound\n\n5 checks, 0 failures\n";
}
