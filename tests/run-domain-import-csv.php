<?php
/** Pure/resumable CSV reader tests using actual private-file storage. */
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error { private $c,$m; function __construct($c='',$m=''){ $this->c=$c;$this->m=$m;} function get_error_code(){return $this->c;} function get_error_message(){return $this->m;} }
}
function is_wp_error($v){return $v instanceof WP_Error;}
function __($t){return $t;}
function absint($v){return abs((int)$v);}
function sanitize_key($k){$k=strtolower((string)$k);return preg_replace('/[^a-z0-9_\-]/','',$k);}
function sanitize_text_field($v){$v=str_replace(array("\r","\n","\t"),' ',(string)$v);return trim(preg_replace('/\s+/',' ',$v));}
function sanitize_textarea_field($v){return trim((string)$v);}
function sanitize_email($v){return filter_var((string)$v,FILTER_SANITIZE_EMAIL);}
function trailingslashit($v){return rtrim((string)$v,'/\\').'/';}
function untrailingslashit($v){return rtrim((string)$v,'/\\');}
function wp_normalize_path($v){return str_replace('\\','/',(string)$v);}
function wp_mkdir_p($v){return is_dir($v)||mkdir($v,0700,true);}
$csv_private_dir=sys_get_temp_dir().'/certloom-csv-test-'.bin2hex(random_bytes(4));
function apply_filters($name,$value){global $csv_private_dir;return 'certloom_private_job_directory'===$name?$csv_private_dir:$value;}
require_once __DIR__ . '/bootstrap-autoload.php';
use Certloom\Infrastructure\Files\PrivateJobFileStore;
use Certloom\Infrastructure\Import\DomainImportCsvReader;
function csv_same($e,$a,$m=''){if($e!==$a)throw new RuntimeException(($m?$m.': ':'').'Expected '.var_export($e,true).', got '.var_export($a,true));}

$source=tempnam(sys_get_temp_dir(),'certloom-src-');
file_put_contents($source,"Certificate Number,Holder,Status,Issue,Expiry,Standard\nCERT-1,\"Alice\nSmith\",Valid,2026-01-01,2026-12-31,ISO 1\n\nCERT-2,Bob,Suspended,2026-02-01,,ISO 2\n");
$files=new PrivateJobFileStore();
$path=$files->store_copy(str_repeat('a',32),$source,'import');
if(is_wp_error($path))throw new RuntimeException($path->get_error_message());
$reader=new DomainImportCsvReader($files,array('standard'=>array('key'=>'standard','type'=>'text','column'=>null)));
$inspected=$reader->inspect_header($path); if(is_wp_error($inspected))throw new RuntimeException($inspected->get_error_message());
csv_same(array('Certificate Number','Holder','Status','Issue','Expiry','Standard'),$inspected['header'],'header inspection');
$choices=$reader->field_choices();
csv_same('Certificate number',$choices['cert_number'],'core field choice');
csv_same('standard',$choices['standard'],'custom field choice fallback');
echo "PASS  protected header inspection and field choices support the mapping UI without staging data\n";
$state=$reader->start($path,array(0=>'cert_number',1=>'holder_name',2=>'status',3=>'issue_date',4=>'expiry_date',5=>'standard'));
if(is_wp_error($state))throw new RuntimeException($state->get_error_message());
$one=$reader->continue_read($state,1); if(is_wp_error($one))throw new RuntimeException($one->get_error_message());
csv_same(false,$one['state']['complete'],'first slice incomplete');
csv_same(1,count($one['records']),'first slice count');
csv_same('Alice Smith',$one['records'][0]['row']['holder_name'],'multiline record parsed as one logical row');
csv_same('ISO 1',$one['records'][0]['row']['custom_fields']['standard'],'custom mapped');
$two=$reader->continue_read($one['state'],1); if(is_wp_error($two))throw new RuntimeException($two->get_error_message());
csv_same(2,$two['records'][0]['row_number'],'blank row ignored without shifting logical record numbers');
csv_same('suspended',$two['records'][0]['row']['status'],'status normalized');
csv_same(false,array_key_exists('expiry_date',$two['records'][0]['row']),'blank optional cell omitted for PATCH semantics');
echo "PASS  byte-offset CSV reader resumes safely across multiline quoted records and blank lines\n";

$bad=tempnam(sys_get_temp_dir(),'certloom-src-');file_put_contents($bad,"Number,Number,Holder\nA,B,C\n");
$badpath=$files->store_copy(str_repeat('b',32),$bad,'import');
$err=$reader->start($badpath,array(0=>'cert_number',2=>'holder_name'));
csv_same('certloom_import_csv_duplicate_header',$err->get_error_code(),'duplicate header blocked');
echo "PASS  duplicate CSV headers are rejected before a job is created\n";

$bad2=tempnam(sys_get_temp_dir(),'certloom-src-');file_put_contents($bad2,"Number,Holder\nA,Alice,EXTRA\n");
$badpath2=$files->store_copy(str_repeat('c',32),$bad2,'import');
$st=$reader->start($badpath2,array(0=>'cert_number',1=>'holder_name'));
$r=$reader->continue_read($st,5);csv_same('certloom_import_csv_column_mismatch',$r['records'][0]['error']->get_error_code(),'column mismatch');
echo "PASS  malformed record column counts are row errors rather than silent column shifts\n";

foreach(array($path,$badpath,$badpath2) as $f){$files->delete($f);} @unlink($source);@unlink($bad);@unlink($bad2);
foreach(glob($csv_private_dir.'/*')?:array() as $f){@unlink($f);}@rmdir($csv_private_dir);
echo "\n4 tests, 0 failures\n";
