<?php
/** Pure tests for backup job handler resumability. */

if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
if(!class_exists('WP_Error')){class WP_Error{private $c,$m;function __construct($c='',$m=''){ $this->c=$c;$this->m=$m;}function get_error_code(){return $this->c;}function get_error_message(){return $this->m;}}}
function is_wp_error($v){return $v instanceof WP_Error;}function absint($v){return abs((int)$v);}function sanitize_key($k){return strtolower(preg_replace('/[^a-z0-9_\-]/','',(string)$k));}
require_once __DIR__ . '/bootstrap-autoload.php';
use Certloom\Application\Contract\DomainBackupBuilderInterface;use Certloom\Application\Backup\DomainBackupJobHandler;use Certloom\Application\Job\JobSliceResult;
final class FakeBackupBuilder implements DomainBackupBuilderInterface{public $starts=0,$continues=0;public function start($key,$version){$this->starts++;return array('path'=>'/private/x','records'=>0,'hash'=>'h0','complete'=>false,'step'=>0);}public function continue_build(array $s,$limit=200){$this->continues++;$s['step']++;$s['records']+=10;$s['hash']='h'.$s['step'];if($s['step']>=2)$s['complete']=true;return $s;}}
function bj_same($e,$a,$m=''){if($e!==$a)throw new RuntimeException(($m?$m.': ':'').'Expected '.var_export($e,true).', got '.var_export($a,true));}
$b=new FakeBackupBuilder();$h=new DomainBackupJobHandler($b,'1.5.4',50);$job=array('job_key'=>str_repeat('a',32),'result'=>array());$one=$h->handle($job,str_repeat('b',32));bj_same(JobSliceResult::PROGRESS,$one->state(),'progress');bj_same(10,$one->cursor(),'cursor');bj_same(1,$b->starts,'start once');$job['result']=$one->result();$two=$h->handle($job,str_repeat('c',32));bj_same(JobSliceResult::SUCCEEDED,$two->state(),'done');bj_same(20,$two->result()['records'],'records');bj_same(1,$b->starts,'state resumed');bj_same(2,$b->continues,'two bounded slices');echo "PASS  backup handler persists builder state and resumes without rebuilding the manifest\n\n1 tests, 0 failures\n";
