<?php
/** End-to-end application tests for non-writing CSV preflight job orchestration. */

if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
require_once __DIR__ . '/support/option-database.php';
if(!class_exists('WP_Error')){class WP_Error{private $c,$m;function __construct($c='',$m=''){ $this->c=$c;$this->m=$m;}function get_error_code(){return $this->c;}function get_error_message(){return $this->m;}}}
function is_wp_error($v){return $v instanceof WP_Error;} function __($t){return $t;} function absint($v){return abs((int)$v);} function sanitize_key($k){$k=strtolower((string)$k);return preg_replace('/[^a-z0-9_\-]/','',$k);} function sanitize_text_field($v){return trim(preg_replace('/\s+/', ' ', str_replace(array("\r","\n","\t"),' ',(string)$v)));} function sanitize_textarea_field($v){return trim((string)$v);} function sanitize_email($v){return filter_var((string)$v,FILTER_SANITIZE_EMAIL);} function is_email($v){return false!==filter_var((string)$v,FILTER_VALIDATE_EMAIL);} function trailingslashit($v){return rtrim((string)$v,'/\\').'/';} function untrailingslashit($v){return rtrim((string)$v,'/\\');} function wp_normalize_path($v){return str_replace('\\','/',(string)$v);} function wp_mkdir_p($v){return is_dir($v)||mkdir($v,0700,true);} function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}
$di_options=array('certloom_domain_data_generation'=>'G0','certverify_fields'=>array(),'certverify_retired_fields'=>array(),'certverify_settings'=>array());
function get_option($k,$d=false){global $di_options;return array_key_exists($k,$di_options)?$di_options[$k]:$d;}
$wpdb = new CertloomOptionDatabase( $di_options );
$di_private_dir=sys_get_temp_dir().'/certloom-import-job-'.bin2hex(random_bytes(4));
function apply_filters($name,$value){global $di_private_dir;return 'certloom_private_job_directory'===$name?$di_private_dir:$value;}
require_once __DIR__.'/bootstrap-autoload.php';
use Certloom\Application\Contract\DomainImportStageInterface;
use Certloom\Application\Import\DomainImportConsistencyGuard;
use Certloom\Application\Import\DomainImportPreflightJobHandler;
use Certloom\Application\Import\DomainImportStagingService;
use Certloom\Application\Job\JobSliceResult;
use Certloom\Infrastructure\Files\PrivateJobFileStore;
use Certloom\Infrastructure\Import\DomainImportCsvReader;
final class JobMemoryStage implements DomainImportStageInterface{
 public $rows=array();
 public function stage($job,$n,array $plan){$num=$plan['version']['display_number'];foreach($this->rows as $r){if($r['job']===$job&&$r['num']===$num&&$r['row']!==$n)return new WP_Error('dup','duplicate');} $key=$job.':'.$n;if(isset($this->rows[$key]))return array('outcome'=>'already_staged');$this->rows[$key]=array('job'=>$job,'row'=>$n,'num'=>$num,'plan'=>$plan,'outcome'=>'ready','commit'=>null);return array('outcome'=>'ready');}
 public function read_batch($job,$after,$limit=500,$outcome='ready'){return array();}
 public function summary($job){$n=0;foreach($this->rows as $r){if($r['job']===$job)$n++;}return array('total'=>$n,'ready'=>$n,'duplicate'=>0);}
 public function mark_committed($job,$row,$outcome,$series_id=null,$version_id=null){return true;}
 public function commit_summary($job){return array('committed'=>0,'created'=>0,'corrected'=>0);}
 public function clear($job){foreach(array_keys($this->rows) as $k){if($this->rows[$k]['job']===$job)unset($this->rows[$k]);}return true;}
}
function dij_same($e,$a,$m=''){if($e!==$a)throw new RuntimeException(($m?$m.': ':'').'Expected '.var_export($e,true).', got '.var_export($a,true));}
$source=tempnam(sys_get_temp_dir(),'certloom-job-src-');file_put_contents($source,"Number,Holder,Status\nA-1,Alice,Valid\nA-2,Bob,Suspended\n");
$files=new PrivateJobFileStore();$path=$files->store_copy(str_repeat('a',32),$source,'import');
$reader=new DomainImportCsvReader($files,array());$stage=new JobMemoryStage();$staging=new DomainImportStagingService($stage);$guard=new DomainImportConsistencyGuard();
$handler=new DomainImportPreflightJobHandler($reader,$staging,$stage,$guard,$files,1);
$config=json_encode(array('certverify_fields'=>array('exists'=>true,'value'=>array()),'certverify_retired_fields'=>array('exists'=>true,'value'=>array()),'certverify_settings'=>array('exists'=>true,'value'=>array())),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
$payload=array('path'=>$path,'mapping'=>array(0=>'cert_number',1=>'holder_name',2=>'status'),'baseline_generation'=>'G0','baseline_configuration_hash'=>hash('sha256',$config),'strict'=>true,'today'=>'2026-08-22');
$job=array('job_key'=>str_repeat('b',32),'payload'=>$payload,'result'=>array());
$one=$handler->handle($job,str_repeat('c',32));dij_same(JobSliceResult::PROGRESS,$one->state(),'first slice progress');dij_same(1,$one->cursor(),'first cursor');
$job['result']=$one->result();$two=$handler->handle($job,str_repeat('d',32));dij_same(JobSliceResult::SUCCEEDED,$two->state(),'second slice succeeds');$result=$two->result();dij_same(true,$result['ready_for_commit'],'ready');dij_same(2,$result['summary']['ready'],'two ready');dij_same(false,file_exists($path),'private source deleted after preflight');
echo "PASS  preflight job reads bounded slices, stages normalized plans, and deletes source only after review completes\n";

// A live generation change between slices must fail closed and clean staged/source state.
$source2=tempnam(sys_get_temp_dir(),'certloom-job-src-');file_put_contents($source2,"Number,Holder\nB-1,Bea\nB-2,Ben\n");$path2=$files->store_copy(str_repeat('e',32),$source2,'import');$payload2=$payload;$payload2['path']=$path2;$payload2['mapping']=array(0=>'cert_number',1=>'holder_name');$job2=array('job_key'=>str_repeat('f',32),'payload'=>$payload2,'result'=>array());$first=$handler->handle($job2,str_repeat('1',32));global $di_options;$di_options['certloom_domain_data_generation']='G1';$job2['result']=$first->result();$failed=$handler->handle($job2,str_repeat('2',32));dij_same('certloom_import_domain_changed',$failed->get_error_code(),'generation drift');dij_same(false,file_exists($path2),'source cleaned on drift');$job2_rows=array_filter($stage->rows,fn($r)=>$r['job']===str_repeat('f',32));dij_same(0,count($job2_rows),'staging cleaned on drift');echo "PASS  live domain drift invalidates multi-request preflight before any commit is possible\n";

@unlink($source);@unlink($source2);foreach(glob($di_private_dir.'/*')?:array() as $f){@unlink($f);}@rmdir($di_private_dir);
echo "\n2 tests, 0 failures\n";
