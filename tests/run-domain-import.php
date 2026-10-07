<?php
/** Pure tests for new-domain import mapping/preflight. */

if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
if ( ! class_exists( 'WP_Error' ) ) { class WP_Error {} }
function absint($v){ return abs((int)$v); }
function sanitize_key($k){ return strtolower(preg_replace('/[^a-z0-9_\-]/','',(string)$k)); }
function is_email($email){ return false !== filter_var($email,FILTER_VALIDATE_EMAIL); }
require_once __DIR__ . '/bootstrap-autoload.php';
use Certloom\Application\Import\DomainImportRowMapper;
use Certloom\Application\Import\DomainImportPreflightService;
use Certloom\Domain\Credential\States;
function di_same($e,$a,$m=''){ if($e!==$a) throw new RuntimeException(($m?$m.': ':'').'Expected '.var_export($e,true).', got '.var_export($a,true)); }
$key=str_repeat('b',32);
$mapper=new DomainImportRowMapper();
$plan=$mapper->map(array('cert_number'=>'CERT-1','holder_name'=>'Alice','status'=>'Suspended','issue_date'=>'2026-01-01','expiry_date'=>'2026-12-31','custom_fields'=>array('score'=>95)),$key,7);
di_same(States::ADMIN_SUSPENDED,$plan['series']['administrative_state'],'suspension');
di_same('certloom_csv',$plan['version']['source_type'],'source');
di_same(hash('sha256','certloom-csv:'.$key.':7'),$plan['version']['source_idempotency_key'],'idempotency');
di_same(95,$plan['version']['snapshot']['custom_fields']['score'],'custom field');
echo "PASS  import row maps to immutable new-domain write plan\n";
$partial=$mapper->map(array('cert_number'=>'CERT-P','holder_name'=>'Patch Only'),$key,8);
di_same(array('display_name'=>'Patch Only'),$partial['recipient_patch'],'omitted recipient fields preserved on correction');
di_same(array(),$partial['series_patch'],'omitted status does not silently reactivate correction');
di_same(false,$partial['provided_fields']['issue_date'],'omitted issue date tracked');
di_same(false,$partial['provided_fields']['expiry_date'],'omitted expiry date tracked');
di_same(false,$partial['provided_fields']['status'],'omitted status tracked');
$explicit=$mapper->map(array('cert_number'=>'CERT-E','holder_name'=>'Explicit','email'=>'','custom_fields'=>array(),'status'=>'Suspended','issue_date'=>'2026-01-02','expiry_date'=>''),$key,9);
di_same(States::ADMIN_SUSPENDED,$explicit['series_patch']['administrative_state'],'explicit status correction tracked');
di_same(true,$explicit['provided_fields']['issue_date'],'explicit issue date tracked');
di_same(true,$explicit['provided_fields']['expiry_date'],'explicit blank expiry date tracked as deliberate clear');
di_same(true,array_key_exists('email',$explicit['recipient_patch']),'explicit email column represented');
di_same(null,$explicit['recipient_patch']['email'],'explicit empty email clears');
di_same(true,array_key_exists('custom_fields',$explicit['recipient_patch']),'explicit custom fields represented');
echo "PASS  correction PATCH plan distinguishes omitted fields from explicit clears\n";


try { $mapper->map(array('cert_number'=>'C2','holder_name'=>'Bob','status'=>'Expired'),$key,1,false); throw new RuntimeException('Expected expired rejection'); } catch(InvalidArgumentException $e){}
$expired=$mapper->map(array('cert_number'=>'C2','holder_name'=>'Bob','status'=>'Expired'),$key,1,true);
di_same(true,$expired['version']['legacy_expired'],'historical expired flag');
echo "PASS  historical Expired import is explicit/guarded\n";

try { $mapper->map(array('cert_number'=>'C3','holder_name'=>'Bob','issue_date'=>'2026-05-01','expiry_date'=>'2026-04-01'),$key,2); throw new RuntimeException('Expected date order failure'); } catch(InvalidArgumentException $e){}
echo "PASS  invalid date ranges fail preflight\n";

$pre=new DomainImportPreflightService();
$summary=$pre->preflight(array(
 array('cert_number'=>'A','holder_name'=>'Alpha','expiry_date'=>'2026-12-31'),
 array('cert_number'=>'B','holder_name'=>'Beta','status'=>'Withdrawn'),
 array('cert_number'=>'A','holder_name'=>'Duplicate'),
 array('cert_number'=>'D','holder_name'=>'Delta','expiry_date'=>'2025-01-01'),
),$key,0,false,'2026-08-22');
di_same(4,$summary['total']); di_same(3,$summary['ready']); di_same(1,$summary['errors']);
di_same(1,$summary['status_counts']['valid']); di_same(1,$summary['status_counts']['revoked']); di_same(1,$summary['status_counts']['expired']);
echo "PASS  preflight summarizes validity and duplicate errors before writes\n";

echo "\n5 tests, 0 failures\n";
