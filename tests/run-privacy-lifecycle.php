<?php
/** Pure application tests for Certloom PRV-02 mutable-profile anonymization. */
define( 'ABSPATH', __DIR__ . '/fake-wp/' );
if ( ! class_exists( 'WP_Error' ) ) { class WP_Error { private $code; private $message; public function __construct($c='',$m=''){ $this->code=$c;$this->message=$m;} public function get_error_code(){return $this->code;} public function get_error_message(){return $this->message;} } }
function is_wp_error($v){return $v instanceof WP_Error;} function __($t){return $t;} function absint($v){return abs((int)$v);} function wp_salt($s='auth'){return 'privacy-unit-'.$s;}
require_once __DIR__ . '/bootstrap-autoload.php';
use Certloom\Application\Contract\PrivacyRepositoryInterface;
use Certloom\Application\Contract\EventRepositoryInterface;
use Certloom\Application\Contract\TransactionInterface;
use Certloom\Application\Privacy\RecipientAnonymizationService;

final class PRepo implements PrivacyRepositoryInterface {
 public $recipients=array(); public $series=array(); public $anonymized=array();
 public function domain_storage_exists(){return true;}
 public function find_recipients_for_identity($e,$u=0,$o=0,$l=50){return array();}
 public function find_recipient_for_update($id){return $this->recipients[(int)$id]??null;}
 public function series_ids_for_recipient($id,$limit=101){$ids=$this->series[(int)$id]??array();return array_slice($ids,0,$limit);}
 public function anonymize_recipient($id,$name,$expected,array $identity_hashes=array()){$r=&$this->recipients[(int)$id]; if(($r['updated_at']??'')!==$expected)return new WP_Error('stale','stale'); $this->anonymized[]=(int)$id; $r=array('id'=>(int)$id,'display_name'=>$name,'first_name'=>null,'middle_name'=>null,'last_name'=>null,'email'=>null,'privacy_identity_hashes'=>$identity_hashes,'privacy_anonymized_at'=>'2026-08-23 00:00:00','wp_user_id'=>null,'external_id'=>null,'custom_fields'=>null,'updated_at'=>'new'); return true;}
 public function export_series_for_identity($e,$u=0,$o=0,$l=50){return array();} public function export_versions_for_identity($e,$u=0,$o=0,$l=50){return array();} public function export_events_for_identity($e,$u=0,$o=0,$l=50){return array();} public function export_signers_for_email($e,$o=0,$l=50){return array();} public function export_issuers_for_email($e,$o=0,$l=50){return array();}
}
final class PEvents implements EventRepositoryInterface { public $events=array(); public $fail=false; public function append(array $e){if($this->fail)return new WP_Error('event','event');$this->events[]=$e;return count($this->events);} }
final class PTx implements TransactionInterface {private $r,$e; public function __construct($r,$e){$this->r=$r;$this->e=$e;} public function run(callable $cb){$rr=$this->r->recipients;$aa=$this->r->anonymized;$ee=$this->e->events;$out=$cb(); if(is_wp_error($out)){$this->r->recipients=$rr;$this->r->anonymized=$aa;$this->e->events=$ee;} return $out;} }
function pa($c,$m){if(!$c)throw new RuntimeException($m);} function ps($e,$a,$m){pa($e===$a,$m.' expected '.var_export($e,true).' got '.var_export($a,true));}
$r=new PRepo();$e=new PEvents();$tx=new PTx($r,$e);$svc=new RecipientAnonymizationService($r,$e,$tx);
$r->recipients[5]=array('id'=>5,'display_name'=>'Jane Doe','email'=>'jane@example.test','custom_fields'=>array('phone'=>'123'),'updated_at'=>'old');$r->series[5]=array(11,12);
$tests=array();
$tests['mutable profile anonymizes but history identifiers remain external']=function()use($svc,$r,$e){$out=$svc->anonymize(5);pa(!is_wp_error($out),'anonymize failed');ps(true,$out['anonymized'],'flag');ps(2,$out['credential_count'],'count');pa(strpos($r->recipients[5]['display_name'],'Recipient-ANON-')===0,'pseudonym');ps(null,$r->recipients[5]['email'],'email cleared');ps(null,$r->recipients[5]['custom_fields'],'custom cleared');pa(count($r->recipients[5]['privacy_identity_hashes'])===1,'identity digest retained');ps(2,count($e->events),'event per series');ps('recipient.profile_anonymized',$e->events[0]['event_type'],'event type');};
$tests['pseudonym is deterministic and contains no original name/email']=function()use($svc,$r){$r->recipients[6]=array('id'=>6,'display_name'=>'Secret Name','email'=>'secret@example.test','updated_at'=>'x');$r->series[6]=array();$out=$svc->anonymize(6);pa(!is_wp_error($out),'anonymize');pa(false===stripos($out['replacement_name'],'secret'),'no pii');pa(false===stripos($out['replacement_name'],'name'),'no name');};
$tests['event failure rolls recipient mutation back']=function()use($svc,$r,$e){$r->recipients[7]=array('id'=>7,'display_name'=>'Rollback','email'=>'rollback@example.test','updated_at'=>'r');$r->series[7]=array(21);$before=$r->recipients[7];$e->fail=true;$out=$svc->anonymize(7);pa(is_wp_error($out),'must fail');ps($before,$r->recipients[7],'rollback recipient');$e->fail=false;};
$tests['more than synchronous bound fails before mutation']=function()use($svc,$r){$r->recipients[8]=array('id'=>8,'display_name'=>'Large','email'=>'large@example.test','updated_at'=>'l');$r->series[8]=range(1,101);$out=$svc->anonymize(8);pa(is_wp_error($out),'must fail');ps('certloom_privacy_anonymize_too_many_credentials',$out->get_error_code(),'code');ps('large@example.test',$r->recipients[8]['email'],'unchanged');};
$tests['repeat anonymization is idempotent and emits no duplicate events']=function()use($svc,$r,$e){$before=count($e->events);$out=$svc->anonymize(5);pa(!is_wp_error($out),'repeat failed');ps(false,$out['anonymized'],'no second mutation');ps(true,$out['already_anonymized'],'idempotent marker');ps($before,count($e->events),'no duplicate events');ps(1,count($r->recipients[5]['privacy_identity_hashes']),'digest history preserved');};
$tests['missing recipient fails closed']=function()use($svc){$out=$svc->anonymize(999);pa(is_wp_error($out),'must fail');ps('certloom_privacy_recipient_missing',$out->get_error_code(),'code');};
$failed=0;foreach($tests as $n=>$f){try{$f();echo "PASS  $n\n";}catch(Throwable $x){$failed++;echo "FAIL  $n\n      {$x->getMessage()}\n";}}echo "\n".count($tests)." tests, $failed failures\n";exit($failed?1:0);
