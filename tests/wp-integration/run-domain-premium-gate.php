<?php
/**
 * Certloom real WordPress/MySQL premium gate.
 *
 * Run only on a disposable WordPress site after the plugin is active:
 *   wp eval-file wp-content/plugins/certloom/tests/wp-integration/run-domain-premium-gate.php
 *
 * This supplements run-domain-schema.php with database-engine/index/rollback
 * and failure-path invariants that pure-PHP tests cannot prove.
 */
if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "Run inside WordPress.\n" ); exit( 1 ); }
if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) && ! defined( 'CERTLOOM_RUN_INTEGRATION_TESTS' ) ) {
	wp_die( 'Certloom integration tests require WP-CLI or CERTLOOM_RUN_INTEGRATION_TESTS.' );
}

use Certloom\Infrastructure\Database\SchemaManager;
use Certloom\Infrastructure\Database\Transaction;
use Certloom\Infrastructure\Data\DomainGeneration;
use Certloom\Infrastructure\Repository\CredentialRepository;
use Certloom\Infrastructure\Repository\IssuerRepository;
use Certloom\Infrastructure\Repository\ProgramRepository;
use Certloom\Infrastructure\Repository\RecipientRepository;

function cl_pg_assert( $condition, $message ) { if ( ! $condition ) { throw new RuntimeException( $message ); } }
function cl_pg_ok( $value, $message ) { if ( is_wp_error( $value ) ) { throw new RuntimeException( $message . ': ' . $value->get_error_code() . ' — ' . $value->get_error_message() ); } return $value; }
function cl_pg_index( $table, $key, $unique ) {
	global $wpdb;
	$wpdb->last_error='';
	$rows=$wpdb->get_results($wpdb->prepare('SHOW INDEX FROM %i WHERE Key_name = %s',$table,$key),ARRAY_A);
	cl_pg_assert(''===(string)$wpdb->last_error && !empty($rows),"Missing required index $key on $table.");
	foreach($rows as $row){cl_pg_assert((int)$row['Non_unique']===($unique?0:1),"Index $key uniqueness mismatch on $table.");}
}

$cleanup=array();
$rollback_slug='certloom-rollback-' . strtolower(wp_generate_password(8,false,false));
try {
	cl_pg_ok( SchemaManager::activate(), 'Schema activation failed' );
	global $wpdb;

	$tables=array('recipients','issuers','programs','signers','batches','batch_recipients','signer_assignments','credential_series','credential_versions','credential_numbers','credential_events','jobs','import_rows','restore_map');
	foreach($tables as $entity){
		$table=SchemaManager::table($entity);
		$status=$wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS LIKE %s',$wpdb->esc_like($table)),ARRAY_A);
		cl_pg_assert(is_array($status) && 'InnoDB'===($status['Engine']??''),"$entity must use InnoDB for Certloom transaction guarantees.");
	}

	cl_pg_index(SchemaManager::table('recipients'),'wp_user_id',true);
	cl_pg_index(SchemaManager::table('issuers'),'slug',true);
	cl_pg_index(SchemaManager::table('programs'),'code',true);
	cl_pg_index(SchemaManager::table('signers'),'slug',true);
	cl_pg_index(SchemaManager::table('batches'),'code',true);
	cl_pg_index(SchemaManager::table('batch_recipients'),'batch_recipient',true);
	cl_pg_index(SchemaManager::table('signer_assignments'),'signer_scope',true);
	cl_pg_index(SchemaManager::table('credential_series'),'public_id',true);
	cl_pg_index(SchemaManager::table('credential_versions'),'series_version',true);
	cl_pg_index(SchemaManager::table('credential_versions'),'source_idempotency',true);
	cl_pg_index(SchemaManager::table('credential_versions'),'display_number',false);
	cl_pg_index(SchemaManager::table('credential_numbers'),'display_number',true);
	cl_pg_index(SchemaManager::table('jobs'),'job_key',true);
	$privacy_column=$wpdb->get_row($wpdb->prepare('SHOW COLUMNS FROM %i LIKE %s',SchemaManager::table('recipients'),'privacy_identity_hashes'),ARRAY_A);
	cl_pg_assert(is_array($privacy_column) && 'YES'===($privacy_column['Null']??''),'Recipient privacy identity digest column is missing or not nullable.');

	$transaction=new Transaction();
	$result=$transaction->run(function()use($wpdb,$rollback_slug){
		$now=current_time('mysql',true);
		$ok=$wpdb->insert(SchemaManager::table('issuers'),array('name'=>'Rollback Probe','slug'=>$rollback_slug,'settings_json'=>null,'created_at'=>$now,'updated_at'=>$now));
		if(false===$ok){return new WP_Error('certloom_gate_insert_failed','Could not create rollback probe.');}
		return new WP_Error('certloom_gate_force_rollback','Intentional rollback probe.');
	});
	cl_pg_assert(is_wp_error($result) && 'certloom_gate_force_rollback'===$result->get_error_code(),'Transaction did not return intentional rollback result.');
	$rolled=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE slug = %s',SchemaManager::table('issuers'),$rollback_slug));
	cl_pg_assert(0===$rolled,'Transaction rollback left the probe row committed.');

	$suffix=strtolower(wp_generate_password(10,false,false));
	$recipient_repo=new RecipientRepository(); $issuer_repo=new IssuerRepository(); $program_repo=new ProgramRepository(); $credentials=new CredentialRepository();
	$issuer=cl_pg_ok($issuer_repo->create(array('name'=>'Premium Gate '.$suffix,'slug'=>'premium-gate-'.$suffix)),'Issuer create failed'); $cleanup['issuer']=$issuer;
	$recipient=cl_pg_ok($recipient_repo->create(array('display_name'=>'Premium Gate Recipient '.$suffix)),'Recipient create failed'); $cleanup['recipient']=$recipient;
	$program=cl_pg_ok($program_repo->create(array('issuer_id'=>$issuer,'code'=>'PG-'.strtoupper($suffix),'title'=>'Premium Gate Program')),'Program create failed'); $cleanup['program']=$program;
	$series1=cl_pg_ok($credentials->create_series(array('recipient_id'=>$recipient,'issuer_id'=>$issuer,'program_id'=>$program)),'Series 1 failed'); $cleanup['series'][]=$series1;
	$series2=cl_pg_ok($credentials->create_series(array('recipient_id'=>$recipient,'issuer_id'=>$issuer,'program_id'=>$program)),'Series 2 failed'); $cleanup['series'][]=$series2;
	$number='PG-'.strtoupper($suffix).'-001';
	$v1=cl_pg_ok($credentials->create_version($series1,array('display_number'=>$number,'snapshot'=>array('ok'=>true))),'Version 1 failed'); $cleanup['versions'][]=$v1;

	$collision=$credentials->create_version($series2,array('display_number'=>$number,'snapshot'=>array('ok'=>true)));
	cl_pg_assert(is_wp_error($collision) && 'certloom_display_number_duplicate'===$collision->get_error_code(),'Global human certificate-number ownership is not enforced across Series.');

	$lineage=$credentials->create_version($series2,array('display_number'=>'PG-'.strtoupper($suffix).'-002','previous_version_id'=>$v1,'snapshot'=>array('ok'=>true)));
	cl_pg_assert(is_wp_error($lineage) && 'certloom_previous_version_wrong_series'===$lineage->get_error_code(),'Cross-Series previous_version_id was not rejected.');
	$lineage_reservation=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE display_number = %s',SchemaManager::table('credential_numbers'),'PG-'.strtoupper($suffix).'-002'));
	cl_pg_assert(0===$lineage_reservation,'Rejected lineage validation stranded a number reservation.');

	$generation_before=(new DomainGeneration())->current();
	$resource=fopen('php://temp','r');
	$bad_number='PG-'.strtoupper($suffix).'-BADSNAP';
	$bad=$credentials->create_version($series1,array('display_number'=>$bad_number,'snapshot'=>array('resource'=>$resource)));
	fclose($resource);
	cl_pg_assert(is_wp_error($bad) && 'certloom_version_snapshot_invalid'===$bad->get_error_code(),'Invalid snapshot did not fail through the expected safe path.');
	cl_pg_assert($generation_before===(new DomainGeneration())->current(),'Invalid snapshot advanced domain generation despite making no data mutation.');
	$bad_reserved=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE display_number = %s',SchemaManager::table('credential_numbers'),$bad_number));
	cl_pg_assert(0===$bad_reserved,'Invalid snapshot stranded a globally reserved certificate number.');

	$idempotency=hash('sha256','premium-gate-'.$suffix);
	$idem1=cl_pg_ok($credentials->create_version($series1,array('display_number'=>'PG-'.strtoupper($suffix).'-IDEM1','source_type'=>'integration','source_idempotency_key'=>$idempotency,'snapshot'=>array('ok'=>true))),'Idempotent Version 1 failed'); $cleanup['versions'][]=$idem1;
	$idem2_number='PG-'.strtoupper($suffix).'-IDEM2';
	$idem2=$credentials->create_version($series1,array('display_number'=>$idem2_number,'source_type'=>'integration','source_idempotency_key'=>$idempotency,'snapshot'=>array('ok'=>true)));
	cl_pg_assert(is_wp_error($idem2),'Duplicate source idempotency key was accepted.');
	$idem2_reserved=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i WHERE display_number = %s',SchemaManager::table('credential_numbers'),$idem2_number));
	cl_pg_assert(0===$idem2_reserved,'Failed idempotent Version insert stranded its new certificate-number reservation.');

	echo "PASS  all next-generation tables use InnoDB\n";
	echo "PASS  recipient privacy identity storage is present and private to normalized storage\n";
	echo "PASS  critical unique/non-unique indexes match the domain contract\n";
	echo "PASS  Transaction wrapper performs a real database rollback\n";
	echo "PASS  certificate-number ownership and Version lineage constraints\n";
	echo "PASS  invalid immutable snapshot is side-effect free\n";
	echo "PASS  failed idempotent Version insert releases its number reservation\n";
} catch(Throwable $e){ fwrite(STDERR,'FAIL  '.$e->getMessage()."\n"); $exit_code=1; }
finally {
	global $wpdb;
	foreach(array_reverse($cleanup['series']??array()) as $sid){$wpdb->delete(SchemaManager::table('credential_events'),array('series_id'=>(int)$sid),array('%d'));}
	foreach(array_reverse($cleanup['versions']??array()) as $vid){$wpdb->delete(SchemaManager::table('credential_versions'),array('id'=>(int)$vid),array('%d'));}
	foreach(array_reverse($cleanup['series']??array()) as $sid){$wpdb->delete(SchemaManager::table('credential_numbers'),array('series_id'=>(int)$sid),array('%d'));$wpdb->delete(SchemaManager::table('credential_series'),array('id'=>(int)$sid),array('%d'));}
	if(!empty($cleanup['program'])){$wpdb->delete(SchemaManager::table('programs'),array('id'=>(int)$cleanup['program']),array('%d'));}
	if(!empty($cleanup['issuer'])){$wpdb->delete(SchemaManager::table('issuers'),array('id'=>(int)$cleanup['issuer']),array('%d'));}
	if(!empty($cleanup['recipient'])){$wpdb->delete(SchemaManager::table('recipients'),array('id'=>(int)$cleanup['recipient']),array('%d'));}
}
exit(isset($exit_code)?$exit_code:0);
