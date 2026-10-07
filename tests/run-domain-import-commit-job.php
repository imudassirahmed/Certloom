<?php
/** Job-level tests for explicit, crash-safe staged import commit orchestration. */

if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
require_once __DIR__ . '/support/option-database.php';
if(!class_exists('WP_Error')){class WP_Error{private $c,$m;function __construct($c='',$m=''){ $this->c=$c;$this->m=$m;}function get_error_code(){return $this->c;}function get_error_message(){return $this->m;}}}
function is_wp_error($v){return $v instanceof WP_Error;} function __($t){return $t;} function absint($v){return abs((int)$v);} function sanitize_key($k){$k=strtolower((string)$k);return preg_replace('/[^a-z0-9_\-]/','',$k);} function current_time($type,$gmt=false){return '2026-08-22 00:00:00';} function wp_json_encode($v,$flags=0){return json_encode($v,$flags);} function wp_cache_delete($k,$g=''){return true;}
$dcj_options=array('certloom_domain_data_generation'=>'G0','certverify_fields'=>array(),'certverify_retired_fields'=>array(),'certverify_settings'=>array());
function get_option($k,$d=false){global $dcj_options;return array_key_exists($k,$dcj_options)?$dcj_options[$k]:$d;} function add_option($k,$v,$deprecated='',$autoload=false){global $dcj_options;if(array_key_exists($k,$dcj_options))return false;$dcj_options[$k]=$v;return true;} function update_option($k,$v,$autoload=false){global $dcj_options;$changed=!array_key_exists($k,$dcj_options)||$dcj_options[$k]!==$v;$dcj_options[$k]=$v;return $changed;} function delete_option($k){global $dcj_options;if(!array_key_exists($k,$dcj_options))return false;unset($dcj_options[$k]);return true;}
final class DCJWpdb {
	public $options = 'wp_options';
	public $last_error = '';
	public $fail_delete_once = false;
	public function prepare( $query, ...$args ) { return array( $query, $args ); }
	public function get_var( $prepared ) {
		global $dcj_options;
		$this->last_error = '';
		if ( is_string( $prepared ) && 'SELECT @@SESSION.innodb_lock_wait_timeout' === $prepared ) { return 50; }
		list( $query, $args ) = $prepared;
		if ( strpos( $query, 'SELECT ENGINE' ) === 0 ) { return 'InnoDB'; }
		if ( strpos( $query, 'SELECT GET_LOCK' ) === 0 || strpos( $query, 'SELECT RELEASE_LOCK' ) === 0 ) { return '1'; }
		if ( strpos( $query, 'SELECT option_value' ) === 0 ) { return $args[1] === 'certloom_domain_write_fence' ? '1' : ( $dcj_options[ $args[1] ] ?? null ); }
		if ( strpos( $query, 'SELECT option_name' ) === 0 ) { foreach ( array_slice( $args, 1 ) as $key ) { if ( isset( $dcj_options[ $key ] ) ) { return $key; } } }
		return null;
	}
	public function query( $prepared ) {
		global $dcj_options;
		$this->last_error = '';
		if ( is_string( $prepared ) ) { return 0; }
		list( $query, $args ) = $prepared;
		if ( strpos( $query, 'UPDATE' ) === 0 ) {
			$new = $args[1]; $name = $args[2]; $old = $args[3];
			if ( ( $dcj_options[ $name ] ?? null ) !== $old ) { return 0; }
			$dcj_options[ $name ] = $new; return 1;
		}
		if ( strpos( $query, 'DELETE' ) === 0 ) {
			if ( $this->fail_delete_once ) { $this->fail_delete_once = false; return 0; }
			$name = $args[1]; $old = $args[2];
			if ( ( $dcj_options[ $name ] ?? null ) !== $old ) { return 0; }
			unset( $dcj_options[ $name ] ); return 1;
		}
		return 0;
	}
	public function get_results( $prepared, $format ) {
		return certloom_test_option_rows( array( 'query' => $prepared[0], 'args' => $prepared[1] ), $GLOBALS['dcj_options'] );
	}
}
$wpdb=new DCJWpdb();
require_once __DIR__.'/bootstrap-autoload.php';
use Certloom\Application\Contract\DomainImportStageInterface;use Certloom\Application\Contract\DomainImportTargetInterface;use Certloom\Application\Contract\JobRepositoryInterface;use Certloom\Application\Import\DomainImportCommitJobHandler;use Certloom\Application\Import\DomainImportConsistencyGuard;use Certloom\Application\Import\DomainImportStagedCommitService;use Certloom\Application\Job\JobSliceResult;use Certloom\Domain\Job\JobStates;use Certloom\Domain\Job\JobTypes;
final class DCJJobs implements JobRepositoryInterface{public $preflight;function __construct($p){$this->preflight=$p;}public function find($k){return $this->preflight;}public function create(array $d){return '';}public function claim($a,$b,$c){return array();}public function save_progress($a,$b,array $c){return true;}public function find_due_queued($l=20){return array();}public function recover_stale_running($l=20){return 0;}public function transition($a,$b,$c,array $d=array(),$e=null){return true;}}
final class DCJStage implements DomainImportStageInterface{public $rows=array();function __construct(){foreach(array(1,2) as $n)$this->rows[$n]=array('row_number'=>$n,'plan'=>array('version'=>array('display_number'=>'C-'.$n)),'outcome'=>'ready','commit'=>null);}public function stage($j,$n,array $p){return array('outcome'=>'ready');}public function read_batch($j,$after,$limit=500,$outcome='ready'){$out=array();foreach($this->rows as $r){if($r['row_number']>$after&&$r['outcome']===$outcome&&empty($r['commit']))$out[]=array('row_number'=>$r['row_number'],'plan'=>$r['plan']);}return array_slice($out,0,$limit);}public function summary($j){return array('total'=>2,'ready'=>2,'duplicate'=>0);}public function mark_committed($j,$n,$outcome,$sid=null,$vid=null){$this->rows[$n]['commit']=$outcome;$this->rows[$n]['sid']=$sid;$this->rows[$n]['vid']=$vid;return true;}public function commit_summary($j){$s=array('committed'=>0,'created'=>0,'corrected'=>0);foreach($this->rows as $r){if(empty($r['commit']))continue;$s['committed']++;$s[$r['commit']]++;}return $s;}public function clear($j){$this->rows=array();return true;}}
final class DCJTarget implements DomainImportTargetInterface{public $calls=array();public function import($row,array $plan,$policy='skip'){$this->calls[]=$row;return array('outcome'=>'created','series_id'=>$row+10,'version_id'=>$row+20);}}
function dcj_same($e,$a,$m=''){if($e!==$a)throw new RuntimeException(($m?$m.': ':'').'Expected '.var_export($e,true).', got '.var_export($a,true));}
$config=json_encode(array('certverify_fields'=>array('exists'=>true,'value'=>array()),'certverify_retired_fields'=>array('exists'=>true,'value'=>array()),'certverify_settings'=>array('exists'=>true,'value'=>array())),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
$pre=array('job_key'=>str_repeat('a',32),'job_type'=>JobTypes::DOMAIN_IMPORT_PREFLIGHT,'status'=>JobStates::SUCCEEDED,'owner_user_id'=>7,'result'=>array('ready_for_commit'=>true,'baseline_generation'=>'G0','baseline_configuration_hash'=>hash('sha256',$config),'summary'=>array('ready'=>2,'duplicates'=>0,'errors'=>0)));
$jobs=new DCJJobs($pre);$stage=new DCJStage();$target=new DCJTarget();$handler=new DomainImportCommitJobHandler($jobs,new DomainImportStagedCommitService($stage,$target),$stage,new DomainImportConsistencyGuard(),null,1);
$job=array('job_key'=>str_repeat('b',32),'owner_user_id'=>7,'payload'=>array('confirmed_commit'=>true,'preflight_job_key'=>str_repeat('a',32),'duplicate_policy'=>'skip'),'result'=>array());
$one=$handler->handle($job,str_repeat('c',32));dcj_same(JobSliceResult::PROGRESS,$one->state(),'first slice');dcj_same(1,$one->cursor(),'first cursor');$job['result']=$one->result();$wpdb->fail_delete_once=true;$two=$handler->handle($job,str_repeat('d',32));dcj_same(JobSliceResult::PROGRESS,$two->state(),'cleanup retry slice');dcj_same('import_cleanup',$two->phase(),'cleanup phase');dcj_same(true,array_key_exists('certloom_domain_maintenance_lock',$dcj_options),'lock remains while cleanup is pending');$job['result']=$two->result();$three=$handler->handle($job,str_repeat('d',32));dcj_same(JobSliceResult::SUCCEEDED,$three->state(),'cleanup replay succeeds');dcj_same(2,$three->result()['created'],'durable exact created count');dcj_same(2,$three->result()['committed'],'durable exact committed count');dcj_same(false,array_key_exists('certloom_domain_maintenance_lock',$dcj_options),'lock released after cleanup replay');dcj_same(2,count($target->calls),'committed rows are not duplicated during cleanup replay');echo "PASS  confirmed import commit requeues transient maintenance cleanup without replaying committed rows\n";

$unconfirmed=$job;$unconfirmed['job_key']=str_repeat('e',32);$unconfirmed['payload']['confirmed_commit']=false;$bad=$handler->handle($unconfirmed,str_repeat('f',32));dcj_same('certloom_import_commit_confirmation_required',$bad->get_error_code(),'confirmation');echo "PASS  commit job refuses to write without explicit administrator confirmation\n";
echo "\n2 tests, 0 failures\n";
