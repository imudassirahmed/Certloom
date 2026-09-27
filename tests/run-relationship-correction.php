<?php
/** Pure application tests for immutable Program/Batch relationship correction. */
define( 'ABSPATH', __DIR__ . '/fake-wp/' );
if ( ! class_exists( 'WP_Error' ) ) { class WP_Error { private $code; private $message; public function __construct($c='',$m=''){ $this->code=$c;$this->message=$m;} public function get_error_code(){return $this->code;} public function get_error_message(){return $this->message;} } }
function is_wp_error($v){ return $v instanceof WP_Error; }
function __($t){ return $t; }
function absint($v){ return abs((int)$v); }
function sanitize_text_field($v){ return trim((string)$v); }
function wp_json_encode($v){ return json_encode($v, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE); }
function wp_salt($scheme='auth'){ return 'unit-test-secret-'.$scheme; }
require_once __DIR__ . '/bootstrap-autoload.php';
use Certloom\Application\Contract\CredentialRelationshipRepositoryInterface;
use Certloom\Application\Contract\EventRepositoryInterface;
use Certloom\Application\Contract\TransactionInterface;
use Certloom\Application\CredentialRelationshipCorrectionService;
use Certloom\Application\CredentialRelationshipBulkService;

final class RCRepo implements CredentialRelationshipRepositoryInterface {
 public $series=array(); public $versions=array(); public $next=100;
 public function find_series($id){ return $this->series[(int)$id]??null; }
 public function find_series_for_update($id){ return $this->find_series($id); }
 public function find_version_for_update($id){ return $this->versions[(int)$id]??null; }
 public function validate_relationship_target($program,$batch){ $p=$program?absint($program):null;$b=$batch?absint($batch):null; if($b===9){ if($p&&$p!==2)return new WP_Error('mismatch','mismatch'); $p=2; } if($p===999)return new WP_Error('missing','missing'); return array('program_id'=>$p,'batch_id'=>$b); }
 public function create_version($sid,array $data){ $id=$this->next++; $this->versions[$id]=array_merge(array('id'=>$id,'series_id'=>(int)$sid),$data); return $id; }
 public function set_relationships($sid,$ep,$eb,$p,$b,$expected_updated_at=null){ $s=&$this->series[(int)$sid]; if(($s['program_id']??null)!==$ep||($s['batch_id']??null)!==$eb||($expected_updated_at!==null&&($s['updated_at']??'')!==$expected_updated_at)) return new WP_Error('conflict','conflict'); $s['program_id']=$p;$s['batch_id']=$b;$s['updated_at']='2026-08-23 12:00:01'; return true; }
 public function set_current_version($sid,$vid){ $this->series[(int)$sid]['current_version_id']=(int)$vid; return true; }
}
final class RCEvents implements EventRepositoryInterface { public $events=array(); public $fail=false; public function append(array $e){ if($this->fail)return new WP_Error('event_failed','event failed'); $this->events[]=$e; return count($this->events); } }
final class RCTx implements TransactionInterface { private $r,$e; public function __construct($r,$e){$this->r=$r;$this->e=$e;} public function run(callable $cb){$s=$this->r->series;$v=$this->r->versions;$n=$this->r->next;$e=$this->e->events;$out=$cb(); if(is_wp_error($out)){ $this->r->series=$s;$this->r->versions=$v;$this->r->next=$n;$this->e->events=$e; } return $out;} }
function rc_assert($c,$m){if(!$c)throw new RuntimeException($m);} function rc_same($e,$a,$m){rc_assert($e===$a,$m.' expected '.var_export($e,true).' got '.var_export($a,true));}
$r=new RCRepo();$e=new RCEvents();$r->series[1]=array('id'=>1,'program_id'=>1,'batch_id'=>null,'current_version_id'=>10,'updated_at'=>'2026-08-23 12:00:00');$r->versions[10]=array('id'=>10,'series_id'=>1,'display_number'=>'CERT-1','issuance_state'=>'issued','legacy_expired'=>false,'valid_from'=>'2026-01-01','issued_at'=>'2026-01-01 00:00:00','expires_at'=>null,'grade'=>null,'snapshot'=>array('recipient_name'=>'Original'));
$svc=new CredentialRelationshipCorrectionService($r,$e,new RCTx($r,$e));

$r->series[2]=array('id'=>2,'program_id'=>1,'batch_id'=>null,'current_version_id'=>20,'updated_at'=>'2026-08-23 12:00:00');
$r->versions[20]=array('id'=>20,'series_id'=>2,'display_number'=>'CERT-2','issuance_state'=>'issued','legacy_expired'=>false,'valid_from'=>'2026-01-01','issued_at'=>'2026-01-01 00:00:00','expires_at'=>null,'grade'=>null,'snapshot'=>array('recipient_name'=>'Second'));
$bulk=new CredentialRelationshipBulkService($r,$svc);
$tests=array();
$tests['correction creates immutable successor and event']=function()use($svc,$r,$e){$out=$svc->correct(1,2,9,array('actor_user_id'=>7,'expected_updated_at'=>'2026-08-23 12:00:00','expected_current_version_id'=>10,'internal_note'=>'approved'));rc_assert(!is_wp_error($out),'correction failed');rc_assert($out['changed'],'must change');$vid=$out['version_id'];rc_same(10,$r->versions[$vid]['previous_version_id'],'lineage');rc_same('CERT-1',$r->versions[$vid]['display_number'],'human number reused');rc_same(2,$r->series[1]['program_id'],'program');rc_same(9,$r->series[1]['batch_id'],'batch');rc_same($vid,$r->series[1]['current_version_id'],'current pointer');rc_same('Original',$r->versions[10]['snapshot']['recipient_name'],'old snapshot untouched');rc_same(2,$r->versions[$vid]['snapshot']['__certloom_relationship']['program_id'],'new snapshot relationship');rc_same('credential.relationship_corrected',$e->events[0]['event_type'],'event');};
$tests['same target is idempotent']=function()use($svc,$r,$e){$before=count($r->versions);$out=$svc->correct(1,2,9,array('expected_updated_at'=>$r->series[1]['updated_at'],'expected_current_version_id'=>$r->series[1]['current_version_id']));rc_assert(!is_wp_error($out),'noop failed');rc_assert(!$out['changed'],'noop must not create evidence');rc_same($before,count($r->versions),'no version');};
$tests['stale review fails before mutation']=function()use($svc,$r){$before=$r->series[1];$out=$svc->correct(1,1,null,array('expected_updated_at'=>'stale'));rc_assert(is_wp_error($out),'stale must fail');rc_same('certloom_relationship_review_stale',$out->get_error_code(),'stale code');rc_same($before,$r->series[1],'no mutation');};
$tests['same-second stale version review fails']=function()use($svc,$r){$saved=$r->series[1]['updated_at'];$r->series[1]['updated_at']='2026-08-23 12:00:00';$before=$r->series[1];$out=$svc->correct(1,1,null,array('expected_updated_at'=>'2026-08-23 12:00:00','expected_current_version_id'=>10));rc_assert(is_wp_error($out),'same-second stale review must fail');rc_same('certloom_relationship_review_stale',$out->get_error_code(),'stale version code');rc_same($before,$r->series[1],'no stale mutation');$r->series[1]['updated_at']=$saved;};
$tests['event failure rolls entire correction back']=function()use($svc,$r,$e){$beforeS=$r->series;$beforeV=$r->versions;$e->fail=true;$out=$svc->correct(1,1,null,array('expected_updated_at'=>$r->series[1]['updated_at'],'expected_current_version_id'=>$r->series[1]['current_version_id']));rc_assert(is_wp_error($out),'event failure expected');rc_same($beforeS,$r->series,'series rollback');rc_same($beforeV,$r->versions,'version rollback');$e->fail=false;};
$tests['invalid relationship fails before version creation']=function()use($svc,$r){$before=count($r->versions);$out=$svc->correct(1,999,null,array('expected_updated_at'=>$r->series[1]['updated_at'],'expected_current_version_id'=>$r->series[1]['current_version_id']));rc_assert(is_wp_error($out),'invalid target expected');rc_same($before,count($r->versions),'no version');};

$tests['bulk review is frozen and tamper evident']=function()use($bulk){$review=$bulk->prepare(array(2),2,9,8);rc_assert(!is_wp_error($review),'bulk prepare');rc_same(2,$review['target']['program_id'],'target program');$bad=$review;$bad['target']['program_id']=1;$out=$bulk->commit($bad);rc_assert(is_wp_error($out),'tampered review must fail');rc_same('certloom_relationship_bulk_review_corrupt',$out->get_error_code(),'tamper code');};
$tests['bulk commit delegates to immutable correction command']=function()use($bulk,$r){$review=$bulk->prepare(array(2),2,9,8);$out=$bulk->commit($review,array('actor_user_id'=>8));rc_assert(!is_wp_error($out),'bulk commit');rc_same(1,$out['changed'],'changed count');rc_same(9,$r->series[2]['batch_id'],'batch applied');rc_assert($r->series[2]['current_version_id']!==20,'new immutable version');};
$failed=0;foreach($tests as $name=>$fn){try{$fn();echo "PASS  $name\n";}catch(Throwable $x){$failed++;echo "FAIL  $name\n      {$x->getMessage()}\n";}}echo "\n".count($tests)." tests, $failed failures\n";exit($failed?1:0);
