<?php
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
/** Infrastructure-level restore workspace tests using a deterministic wpdb emulator. */
define( 'ABSPATH', __DIR__ . '/fake-wp/' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'MINUTE_IN_SECONDS', 60 );

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $code, $message, $data;
		public function __construct( $code = '', $message = '', $data = null ) { $this->code=$code; $this->message=$message; $this->data=$data; }
		public function get_error_code(){ return $this->code; }
		public function get_error_message(){ return $this->message; }
		public function get_error_data(){ return $this->data; }
	}
}
function is_wp_error($v){ return $v instanceof WP_Error; }
function __($t){ return $t; }
function absint($v){ return abs((int)$v); }
function sanitize_key($v){ return strtolower(preg_replace('/[^a-z0-9_\-]/','',(string)$v)); }
function current_time($type,$gmt=false){ return '2026-08-22 00:00:00'; }
function wp_cache_delete(){ return true; }

$GLOBALS['restore_tamper_once_key']='';
$GLOBALS['restore_options']=array(
	'certverify_fields'=>array('old-field'),
	'certverify_retired_fields'=>array('old-retired'),
	'certverify_settings'=>array('verification_page'=>9),
	'certloom_domain_data_generation'=>'old-generation',
);
function get_option($key,$default=false){ return array_key_exists($key,$GLOBALS['restore_options'])?$GLOBALS['restore_options'][$key]:$default; }
function update_option($key,$value,$autoload=false){ $changed=!array_key_exists($key,$GLOBALS['restore_options'])||$GLOBALS['restore_options'][$key]!==$value;if(!empty($GLOBALS['restore_tamper_once_key'])&&$GLOBALS['restore_tamper_once_key']===$key){$GLOBALS['restore_tamper_once_key']='';$GLOBALS['restore_options'][$key]=array('__tampered__'=>true);return true;}$GLOBALS['restore_options'][$key]=$value;return $changed; }
function delete_option($key){ if(!array_key_exists($key,$GLOBALS['restore_options']))return false;unset($GLOBALS['restore_options'][$key]);return true; }
function add_option($key,$value,$deprecated='',$autoload=false){ if(array_key_exists($key,$GLOBALS['restore_options']))return false;$GLOBALS['restore_options'][$key]=$value;return true; }

final class RestoreWpdb {
	public $prefix='wp_'; public $options='wp_options'; public $last_error=''; public $tables=array(); public $insert_id=0; public $fail_maintenance_delete_once=false; public $fail_drop_contains_once=''; public $fail_drop_skip=0; public $mutex_busy=false;
	public function prepare($query,...$args){ if(count($args)===1&&is_array($args[0]))$args=$args[0];return array('query'=>$query,'args'=>$args); }
	public function esc_like($v){ return (string)$v; }
	public function query($prepared){
		$this->last_error='';
		if(is_string($prepared)){ return true; }
		$q=$prepared['query'];$a=$prepared['args'];
		if(strpos($q,'DROP TABLE IF EXISTS')===0){$table=(string)$a[0];if(''!==$this->fail_drop_contains_once&&false!==strpos($table,$this->fail_drop_contains_once)){if($this->fail_drop_skip>0){$this->fail_drop_skip--;}else{$this->fail_drop_contains_once='';$this->last_error='forced drop failure';return false;}}unset($this->tables[$table]);return 1; }
		if(strpos($q,'CREATE TABLE')===0){ $target=(string)$a[0];$source=(string)$a[1];if(!array_key_exists($source,$this->tables)){ $this->last_error='source missing';return false; }$this->tables[$target]=array();return 1; }
		if(strpos($q,'RENAME TABLE')===0){
			$ops=array();for($i=0;$i<count($a);$i+=2){$ops[]=array((string)$a[$i],(string)$a[$i+1]);}
			$sources=array();foreach($ops as $op){$sources[$op[0]]=true;}
			foreach($ops as $op){ if(!array_key_exists($op[0],$this->tables)||(array_key_exists($op[1],$this->tables)&&!isset($sources[$op[1]]))){ $this->last_error='rename conflict';return false; } }
			$copies=array();foreach($ops as $op){$copies[$op[1]]=$this->tables[$op[0]];}foreach($ops as $op){unset($this->tables[$op[0]]);}foreach($copies as $name=>$rows){$this->tables[$name]=$rows;}return 1;
		}
		if(strpos($q,'UPDATE %i SET option_value')===0){ $name=(string)$a[2];$new=$a[1];$old=$a[3];if(!array_key_exists($name,$GLOBALS['restore_options'])||(string)$GLOBALS['restore_options'][$name]!== (string)$old)return 0;$GLOBALS['restore_options'][$name]=$new;return 1; }
		if(strpos($q,'DELETE FROM %i WHERE option_name')===0){ $name=(string)$a[1];$old=$a[2];if($this->fail_maintenance_delete_once && 'certloom_domain_maintenance_lock'===$name){$this->fail_maintenance_delete_once=false;return 0;}if(!array_key_exists($name,$GLOBALS['restore_options'])||(string)$GLOBALS['restore_options'][$name]!== (string)$old)return 0;unset($GLOBALS['restore_options'][$name]);return 1; }
		return 1;
	}
	public function get_var($prepared){
		$this->last_error='';if(is_string($prepared))return 0;$q=$prepared['query'];$a=$prepared['args'];
		if(strpos($q,'SELECT GET_LOCK')===0)return $this->mutex_busy ? '0' : '1';
		if(strpos($q,'SELECT RELEASE_LOCK')===0)return '1';
		if(strpos($q,'SELECT option_value')===0)return $GLOBALS['restore_options'][$a[1]]??null;
		if(strpos($q,'SELECT option_name')===0){foreach(array_slice($a,1)as $key)if(isset($GLOBALS['restore_options'][$key]))return $key;return null;}
		if(strpos($q,'SHOW TABLES LIKE')===0){$name=(string)$a[0];return array_key_exists($name,$this->tables)?$name:null;}
		if(strpos($q,'SELECT COUNT(*)')===0)return 0;
		return null;
	}
	public function get_row($prepared,$format){ $table=(string)$prepared['args'][0];$id=(int)$prepared['args'][1];return $this->tables[$table][$id]??null; }
	public function insert($table,$row){ $id=(int)$row['id'];if(isset($this->tables[$table][$id])){$this->last_error='duplicate';return false;}$this->tables[$table][$id]=$row;$this->insert_id=$id;return 1; }
}
$GLOBALS['wpdb']=new RestoreWpdb();

require_once __DIR__ . '/bootstrap-autoload.php';

use Certloom\Infrastructure\Backup\DomainBackupRepository;
use Certloom\Infrastructure\Database\SchemaManager;
use Certloom\Infrastructure\Restore\DomainRestoreWorkspace;

function rw_same($e,$a,$m=''){if($e!==$a)throw new RuntimeException(($m?$m.': ':'').'Expected '.var_export($e,true).', got '.var_export($a,true));}
function rw_true($a,$m=''){rw_same(true,(bool)$a,$m);}
function rw_row($entity,$id=1){
	$base=array_fill_keys(SchemaManager::column_names($entity),null);$base['id']=(string)$id;
	foreach($base as $k=>$v){ if(strpos($k,'created_at')!==false||strpos($k,'updated_at')!==false)$base[$k]='2026-08-22 00:00:00'; }
	return $base;
}
function rw_stage_name($owner,$entity,$kind='stage'){global $wpdb;$codes=array('issuers'=>'i','programs'=>'p','recipients'=>'r','signers'=>'g','batches'=>'b','batch_recipients'=>'m','signer_assignments'=>'a','templates'=>'t','template_revisions'=>'u','template_assignments'=>'y','credential_series'=>'s','credential_numbers'=>'n','credential_versions'=>'v','credential_events'=>'e');$prefix='rollback'===$kind?'certloom_rb_':('merge'===$kind?'certloom_rm_':'certloom_rs_');return $wpdb->prefix.$prefix.substr($owner,0,12).'_'.$codes[$entity];}

// Seed every live table and commit a complete replacement.
foreach(DomainBackupRepository::entity_types() as $entity){$live=SchemaManager::table($entity);$GLOBALS['wpdb']->tables[$live]=array(99=>array('id'=>'99','marker'=>'old-'.$entity));}
$owner=str_repeat('a',32);$ws=new DomainRestoreWorkspace();$workspace=$ws->create($owner);rw_same(false,is_wp_error($workspace),'workspace create');
foreach(DomainBackupRepository::entity_types() as $entity){$row=rw_row($entity);$GLOBALS['wpdb']->tables[rw_stage_name($owner,$entity)][1]=$row;}
$new_config=array(
	'certverify_fields'=>array('exists'=>true,'value'=>array('new-field')),
	'certverify_retired_fields'=>array('exists'=>true,'value'=>array('new-retired')),
	'certverify_settings'=>array('exists'=>true,'value'=>array('verification_page'=>10)),
);
$entity_count=count(DomainBackupRepository::entity_types());
$GLOBALS['wpdb']->fail_maintenance_delete_once=true;
$result=$ws->commit_replace($workspace,$new_config,array('mode'=>'replace','records'=>$entity_count,'counts'=>array_fill_keys(DomainBackupRepository::entity_types(),1)));
rw_same('certloom_domain_maintenance_release_failed',$result->get_error_code(),'committed restore surfaces transient lock-release failure');
foreach(DomainBackupRepository::entity_types() as $entity){$live=SchemaManager::table($entity);rw_true(isset($GLOBALS['wpdb']->tables[$live][1]),'new live '.$entity);rw_same(false,isset($GLOBALS['wpdb']->tables[$live][99]),'old removed '.$entity);rw_same(false,array_key_exists(rw_stage_name($owner,$entity,'rollback'),$GLOBALS['wpdb']->tables),'rollback cleaned');}
rw_same(array('new-field'),get_option('certverify_fields'),'configuration committed');rw_same(true,array_key_exists(DomainRestoreWorkspace::JOURNAL_OPTION,$GLOBALS['restore_options']),'committed recovery journal retained until lock release is confirmed');rw_same(true,array_key_exists('certloom_domain_maintenance_lock',$GLOBALS['restore_options']),'failed release leaves lock active');
$receipt=$ws->completion_receipt($owner);rw_same(false,is_wp_error($receipt),'receipt readable');rw_same($entity_count,$receipt['records'],'receipt record count');
rw_same(true,$ws->commit_replace($workspace,$new_config),'committed workspace replay retries post-commit cleanup without re-promoting data');
rw_same(false,array_key_exists('certloom_domain_maintenance_lock',$GLOBALS['restore_options']),'replay releases previously stuck lock');
rw_same(false,array_key_exists(DomainRestoreWorkspace::JOURNAL_OPTION,$GLOBALS['restore_options']),'replay clears journal only after release succeeds');
echo "PASS  complete domain Replace is durable and a committed replay repairs transient post-commit lock cleanup\n";

// Simulate the narrow crash window: atomic table rename succeeded, but journal still says prepared.
$owner2=str_repeat('b',32);$workspace2=$ws->create($owner2);rw_same(false,is_wp_error($workspace2),'second workspace');
foreach(DomainBackupRepository::entity_types() as $entity){$live=SchemaManager::table($entity);$stage=rw_stage_name($owner2,$entity);$rollback=rw_stage_name($owner2,$entity,'rollback');$GLOBALS['wpdb']->tables[$stage]=array(2=>array('id'=>'2','marker'=>'replacement'));$GLOBALS['wpdb']->tables[$rollback]=$GLOBALS['wpdb']->tables[$live];unset($GLOBALS['wpdb']->tables[$live]);$GLOBALS['wpdb']->tables[$live]=$GLOBALS['wpdb']->tables[$stage];unset($GLOBALS['wpdb']->tables[$stage]);}
$GLOBALS['restore_options'][DomainRestoreWorkspace::JOURNAL_OPTION]=array('state'=>'swapped','owner_token'=>$owner2,'suffix'=>substr($owner2,0,12),'old_configuration'=>array(
	'certverify_fields'=>array('exists'=>true,'value'=>array('new-field')),
	'certverify_retired_fields'=>array('exists'=>true,'value'=>array('new-retired')),
	'certverify_settings'=>array('exists'=>true,'value'=>array('verification_page'=>10)),
),'old_generation'=>get_option('certloom_domain_data_generation'),'created_at'=>time());
$active_recovery=$ws->recover_interrupted_commit();rw_same(true,$active_recovery,'active worker lease prevents request-bootstrap recovery');
foreach(DomainBackupRepository::entity_types() as $entity){$live=SchemaManager::table($entity);rw_true(isset($GLOBALS['wpdb']->tables[$live][2]),'active worker replacement graph remains untouched '.$entity);}
$lease_parts=explode('|',$GLOBALS['restore_options']['certloom_domain_maintenance_lock']);$lease_parts[1]=(string)(time()-2101);$lease_parts[2]=(string)(time()-1);$GLOBALS['restore_options']['certloom_domain_maintenance_lock']=implode('|',$lease_parts);
$GLOBALS['wpdb']->fail_maintenance_delete_once=true;$recovered=$ws->recover_interrupted_commit();rw_same('certloom_domain_maintenance_release_failed',$recovered->get_error_code(),'prepared recovery surfaces transient release failure');rw_same(true,array_key_exists(DomainRestoreWorkspace::JOURNAL_OPTION,$GLOBALS['restore_options']),'rollback journal remains retryable until cleanup release succeeds');
foreach(DomainBackupRepository::entity_types() as $entity){$live=SchemaManager::table($entity);rw_true(isset($GLOBALS['wpdb']->tables[$live][1]),'old graph restored '.$entity);rw_same(false,isset($GLOBALS['wpdb']->tables[$live][2]),'replacement rolled back '.$entity);}
rw_same(true,$ws->recover_interrupted_commit(),'prepared recovery replay finishes cleanup');rw_same(false,array_key_exists(DomainRestoreWorkspace::JOURNAL_OPTION,$GLOBALS['restore_options']),'rollback journal cleared after release succeeds');
echo "PASS  recovery detects rollback tables and retains recovery state until maintenance cleanup succeeds\n";

// Merge uses a second complete private graph; the raw backup staging graph is never promoted.
$owner3=str_repeat('c',32);$workspace3=$ws->create($owner3);rw_same(false,is_wp_error($workspace3),'merge workspace');
$merge_created=$ws->create_merge_target($workspace3);rw_same(true,$merge_created,'merge target create');
foreach(DomainBackupRepository::entity_types() as $entity){
	$GLOBALS['wpdb']->tables[rw_stage_name($owner3,$entity)] = array(7=>array('id'=>'7','marker'=>'raw-backup-'.$entity));
	$GLOBALS['wpdb']->tables[rw_stage_name($owner3,$entity,'merge')] = array(8=>rw_row($entity,8));
}
$merged=$ws->commit_merge($workspace3,$new_config,array('mode'=>'merge','records'=>$entity_count,'counts'=>array('inserted'=>$entity_count,'existing'=>0,'skipped'=>0)));rw_same(true,$merged,'merge commit');
foreach(DomainBackupRepository::entity_types() as $entity){$live=SchemaManager::table($entity);rw_true(isset($GLOBALS['wpdb']->tables[$live][8]),'merged graph promoted '.$entity);rw_same(false,isset($GLOBALS['wpdb']->tables[$live][7]),'raw backup stage never promoted '.$entity);rw_same(false,array_key_exists(rw_stage_name($owner3,$entity),$GLOBALS['wpdb']->tables),'raw stage cleaned '.$entity);rw_same(false,array_key_exists(rw_stage_name($owner3,$entity,'merge'),$GLOBALS['wpdb']->tables),'merge source cleaned '.$entity);}
$merge_receipt=$ws->completion_receipt($owner3);rw_same('merge',$merge_receipt['mode'],'merge receipt mode');rw_same($entity_count,$merge_receipt['records'],'merge receipt record count');rw_same(array('inserted'=>$entity_count,'existing'=>0,'skipped'=>0),$merge_receipt['counts'],'merge receipt action counts');
echo "PASS  Merge promotes only the reconciled private graph, cleans raw staging data and preserves replay counts\n";

// A post-swap configuration write that is altered by a WordPress option filter must be detected and rolled back.
$owner4=str_repeat('d',32);$workspace4=$ws->create($owner4);rw_same(false,is_wp_error($workspace4),'tamper workspace');
foreach(DomainBackupRepository::entity_types() as $entity){$GLOBALS['wpdb']->tables[rw_stage_name($owner4,$entity)]=array(11=>rw_row($entity,11));}
$before_config=get_option('certverify_fields');$GLOBALS['restore_tamper_once_key']='certverify_fields';
$tampered=$ws->commit_replace($workspace4,$new_config,array('mode'=>'replace','records'=>$entity_count,'counts'=>array_fill_keys(DomainBackupRepository::entity_types(),1)));
rw_same('certloom_domain_restore_configuration_failed',$tampered->get_error_code(),'altered configuration is detected after swap');
rw_same($before_config,get_option('certverify_fields'),'old configuration restored after altered option save');
foreach(DomainBackupRepository::entity_types() as $entity){$live=SchemaManager::table($entity);rw_true(isset($GLOBALS['wpdb']->tables[$live][8]),'pre-restore graph restored '.$entity);rw_same(false,isset($GLOBALS['wpdb']->tables[$live][11]),'tampered replacement rolled back '.$entity);rw_same(false,array_key_exists(rw_stage_name($owner4,$entity),$GLOBALS['wpdb']->tables),'failed replacement stage cleaned '.$entity);}
rw_same(null,$ws->completion_receipt($owner4),'rolled-back restore has no completion receipt');
echo "PASS  exact option verification detects post-swap configuration tampering and restores the previous graph\n";

// A private workspace DROP failure after a durable commit must retain recovery evidence until replay cleans it.
$owner5=str_repeat('e',32);$workspace5=$ws->create($owner5);rw_same(false,is_wp_error($workspace5),'cleanup workspace');
foreach(DomainBackupRepository::entity_types() as $entity){$GLOBALS['wpdb']->tables[rw_stage_name($owner5,$entity)]=array(12=>rw_row($entity,12));}
$GLOBALS['wpdb']->fail_drop_contains_once='certloom_rb_';$GLOBALS['wpdb']->fail_drop_skip=count(DomainBackupRepository::entity_types());
$cleanup_fail=$ws->commit_replace($workspace5,$new_config,array('mode'=>'replace','records'=>$entity_count,'counts'=>array_fill_keys(DomainBackupRepository::entity_types(),1)));
rw_same('certloom_domain_restore_workspace_cleanup_failed',$cleanup_fail->get_error_code(),'private rollback cleanup failure surfaced');
rw_same(true,array_key_exists(DomainRestoreWorkspace::JOURNAL_OPTION,$GLOBALS['restore_options']),'cleanup failure keeps durable recovery journal');
rw_same(true,array_key_exists('certloom_domain_maintenance_lock',$GLOBALS['restore_options']),'cleanup failure keeps maintenance boundary');
rw_same(true,$ws->commit_replace($workspace5,$new_config,array('mode'=>'replace','records'=>$entity_count,'counts'=>array_fill_keys(DomainBackupRepository::entity_types(),1))),'committed cleanup replay succeeds');
rw_same(false,array_key_exists(DomainRestoreWorkspace::JOURNAL_OPTION,$GLOBALS['restore_options']),'cleanup replay clears journal');
rw_same(false,array_key_exists('certloom_domain_maintenance_lock',$GLOBALS['restore_options']),'cleanup replay releases maintenance');
echo "PASS  committed restore cleanup failures remain durable and retryable until private tables are removed\n";

// Merge rollback must clean the promoted failed merge graph as well as the raw staging graph.
$owner6=str_repeat('f',32);$workspace6=$ws->create($owner6);rw_same(false,is_wp_error($workspace6),'merge rollback workspace');
rw_same(true,$ws->create_merge_target($workspace6),'merge rollback target');
foreach(DomainBackupRepository::entity_types() as $entity){$GLOBALS['wpdb']->tables[rw_stage_name($owner6,$entity)]=array(13=>rw_row($entity,13));$GLOBALS['wpdb']->tables[rw_stage_name($owner6,$entity,'merge')]=array(14=>rw_row($entity,14));}
$GLOBALS['restore_tamper_once_key']='certverify_fields';
$merge_rollback=$ws->commit_merge($workspace6,$new_config,array('mode'=>'merge','records'=>$entity_count,'counts'=>array('inserted'=>$entity_count,'existing'=>0,'skipped'=>0)));
rw_same('certloom_domain_restore_configuration_failed',$merge_rollback->get_error_code(),'merge configuration failure rolled back');
foreach(DomainBackupRepository::entity_types() as $entity){$live=SchemaManager::table($entity);rw_true(isset($GLOBALS['wpdb']->tables[$live][12]),'pre-merge graph restored '.$entity);rw_same(false,isset($GLOBALS['wpdb']->tables[$live][14]),'failed merge graph not live '.$entity);rw_same(false,array_key_exists(rw_stage_name($owner6,$entity),$GLOBALS['wpdb']->tables),'merge raw stage cleaned '.$entity);rw_same(false,array_key_exists(rw_stage_name($owner6,$entity,'merge'),$GLOBALS['wpdb']->tables),'failed promoted merge graph cleaned '.$entity);}
echo "PASS  Merge rollback restores the previous graph and removes both private source graphs\n";

// Preparing a new private graph must stop if an earlier target table cannot be cleared.
$owner7=str_repeat('1',32);
$GLOBALS['wpdb']->fail_drop_contains_once='certloom_rs_';
$pre_drop=$ws->create($owner7);
rw_same('certloom_domain_restore_stage_create_failed',$pre_drop->get_error_code(),'private target pre-drop failure blocks workspace creation');
rw_same(false,array_key_exists('certloom_domain_maintenance_lock',$GLOBALS['restore_options']),'failed workspace preparation releases maintenance boundary');
echo "PASS  future-domain restore workspace refuses uncertain pre-existing private-table cleanup\n";

// A competing recovery request must never mutate any table while the transition mutex is held.
$GLOBALS['wpdb']->mutex_busy=true;
$before_tables=$GLOBALS['wpdb']->tables;
rw_same(true,$ws->recover_interrupted_commit(),'busy bootstrap recovery is a harmless no-op');
rw_same('certloom_maintenance_busy',$ws->create(str_repeat('2',32))->get_error_code(),'new restore is retryable while transition busy');
rw_same($before_tables,$GLOBALS['wpdb']->tables,'busy recovery preserves all tables');
$GLOBALS['wpdb']->mutex_busy=false;
echo "PASS  competing recovery skips a live transition without mutating tables\n";

// A receipt failure after the committed journal must not roll a durable commit back.
$owner8=str_repeat('3',32);$workspace8=$ws->create($owner8);rw_same(false,is_wp_error($workspace8),'receipt failure workspace');
foreach(DomainBackupRepository::entity_types() as $entity){$GLOBALS['wpdb']->tables[rw_stage_name($owner8,$entity)]=array(20=>rw_row($entity,20));}
$GLOBALS['restore_tamper_once_key']=DomainRestoreWorkspace::RECEIPTS_OPTION;
$receipt_fail=$ws->commit_replace($workspace8,$new_config,array('records'=>$entity_count,'counts'=>array_fill_keys(DomainBackupRepository::entity_types(),1)));
rw_same('certloom_domain_restore_receipt_failed',$receipt_fail->get_error_code(),'receipt failure reported');
rw_same('committed',get_option(DomainRestoreWorkspace::JOURNAL_OPTION)['state'],'durable commit retained');
foreach(DomainBackupRepository::entity_types() as $entity){rw_true(isset($GLOBALS['wpdb']->tables[SchemaManager::table($entity)][20]),'committed data retained after receipt failure');}
$lease_parts=explode('|',$GLOBALS['restore_options']['certloom_domain_maintenance_lock']);$lease_parts[1]=(string)(time()-2101);$lease_parts[2]=(string)(time()-1);$GLOBALS['restore_options']['certloom_domain_maintenance_lock']=implode('|',$lease_parts);
rw_same(true,$ws->recover_interrupted_commit(),'receipt repair after worker expiry');
rw_same(false,array_key_exists('certloom_domain_maintenance_lock',$GLOBALS['restore_options']),'committed cleanup removes expired lease');
rw_same($entity_count,$ws->completion_receipt($owner8)['records'],'recovered receipt retains counts');
echo "PASS  receipt failure preserves the durable commit and recovery repairs its receipt and expired lease\n";

// Completion readers are used by job replay: they must retry cleanup before reporting success.
$owner9=str_repeat('4',32);$workspace9=$ws->create($owner9);
foreach(DomainBackupRepository::entity_types() as $entity){$GLOBALS['wpdb']->tables[rw_stage_name($owner9,$entity)]=array(21=>rw_row($entity,21));}
$GLOBALS['wpdb']->fail_maintenance_delete_once=true;
$pending=$ws->commit_replace($workspace9,$new_config,array('records'=>$entity_count,'counts'=>array_fill_keys(DomainBackupRepository::entity_types(),1)));
rw_same('certloom_domain_maintenance_release_failed',$pending->get_error_code(),'commit leaves retryable cleanup');
$GLOBALS['wpdb']->fail_maintenance_delete_once=true;
rw_same('certloom_domain_maintenance_release_failed',$ws->completion_receipt($owner9)->get_error_code(),'job replay does not succeed while cleanup fails');
rw_same($entity_count,$ws->completion_receipt($owner9)['records'],'job replay succeeds after cleanup retry');
rw_same(false,array_key_exists(DomainRestoreWorkspace::JOURNAL_OPTION,$GLOBALS['restore_options']),'receipt replay clears journal');
echo "PASS  job replay cannot report success until committed cleanup finishes\n";

echo "\n10 tests, 0 failures\n";
