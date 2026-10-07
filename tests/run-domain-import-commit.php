<?php
/** Pure commit-accounting/idempotency-boundary tests for domain import. */

if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
if(!class_exists('WP_Error')){ class WP_Error{private $c,$m;function __construct($c='',$m=''){ $this->c=$c;$this->m=$m;} function get_error_code(){return $this->c;} function get_error_message(){return $this->m;} } }
function is_wp_error($v){return $v instanceof WP_Error;} function absint($v){return abs((int)$v);} function sanitize_key($k){return strtolower(preg_replace('/[^a-z0-9_\-]/','',(string)$k));} function is_email($e){return false!==filter_var($e,FILTER_VALIDATE_EMAIL);}
require_once __DIR__ . '/bootstrap-autoload.php';
use Certloom\Application\Contract\DomainImportTargetInterface; use Certloom\Application\Import\DomainImportCommitService;
final class FakeImportTarget implements DomainImportTargetInterface{ public $calls=0; public $failAt=0; public $duplicateAt=0; public function import($row,array $plan,$policy='skip'){ $this->calls++; if($this->failAt===$row)return new WP_Error('write_failed','boom'); if($this->duplicateAt===$row)return array('outcome'=>'duplicate'); return array('outcome'=>'created'); }}
function dic_same($e,$a,$m=''){if($e!==$a)throw new RuntimeException(($m?$m.': ':'').'Expected '.var_export($e,true).', got '.var_export($a,true));}
$key=str_repeat('c',32); $rows=array(array('cert_number'=>'A','holder_name'=>'A'),array('cert_number'=>'B','holder_name'=>'B'),array('cert_number'=>'C','holder_name'=>'C'));
$t=new FakeImportTarget(); $t->duplicateAt=2; $r=(new DomainImportCommitService($t))->commit($rows,$key); dic_same(2,$r['created']);dic_same(1,$r['duplicates']);dic_same(0,$r['failed']); echo "PASS  commit accounts created and duplicate outcomes exactly\n";
$t=new FakeImportTarget();$t->failAt=2;$r=(new DomainImportCommitService($t))->commit($rows,$key);dic_same(1,$r['created']);dic_same(1,$r['failed']);dic_same(2,$r['last_row']);dic_same(2,$t->calls);echo "PASS  commit stops at first unsafe write failure\n";
echo "\n2 tests, 0 failures\n";
