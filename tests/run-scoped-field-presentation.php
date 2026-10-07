<?php
/** FLD-02 immutable frontend/privacy presentation contract. */

if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
define('ABSPATH',__DIR__.'/');
function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/','',(string)$v));}
class Ecapla_CertVerify_Fields { public static $map=array(); public static function get_field_map(){return self::$map;} }
require_once __DIR__.'/bootstrap-autoload.php';
use Certloom\Application\Fields\ScopedFieldPresentation;
$pass=0;$fail=0;function sp($ok,$label){global$pass,$fail;if($ok){$pass++;echo"PASS  $label\n";}else{$fail++;echo"FAIL  $label\n";}}
Ecapla_CertVerify_Fields::$map=array(
 'gpa'=>array('key'=>'gpa','label'=>'Current GPA','type'=>'number','sensitivity'=>'personal','public_exposure'=>'both','locked_visibility'=>false),
 'campus'=>array('key'=>'campus','label'=>'Campus','type'=>'text','sensitivity'=>'personal','public_exposure'=>'private','locked_visibility'=>false),
 'secret'=>array('key'=>'secret','label'=>'Secret','type'=>'text','sensitivity'=>'internal','public_exposure'=>'both','locked_visibility'=>true),
);
$snapshot=array('fields'=>array('contract'=>array('fields'=>array(
 array('key'=>'gpa','label'=>'GPA at issue','type'=>'number','sensitivity'=>'personal','public_exposure'=>'verification','required'=>true,'value_source'=>'credential_custom'),
 array('key'=>'campus','label'=>'Campus at issue','type'=>'text','sensitivity'=>'personal','public_exposure'=>'private','required'=>false,'value_source'=>'credential_custom'),
 array('key'=>'secret','label'=>'Internal','type'=>'text','sensitivity'=>'internal','public_exposure'=>'both','required'=>false,'value_source'=>'credential_custom'),
)), 'values'=>array('gpa'=>'3.8','campus'=>'North','secret'=>'x')));
$p=new ScopedFieldPresentation();
$v=$p->public_fields($snapshot,'verification');sp(1===count($v)&&'gpa'===$v[0]['key']&&'GPA at issue'===$v[0]['label'],'verification requires both frozen and current exposure permission and preserves issued label');
$profile=$p->public_fields($snapshot,'profile');sp(0===count($profile),'current/frozen privacy intersection prevents retroactive widening and respects current tightening');
$mine=$p->recipient_fields($snapshot);$keys=array_column($mine,'key');sp(in_array('gpa',$keys,true)&&in_array('campus',$keys,true)&&!in_array('secret',$keys,true),'Recipient Portal may show own non-internal frozen fields while internal remains hidden');
Ecapla_CertVerify_Fields::$map['gpa']['public_exposure']='private';$v=$p->public_fields($snapshot,'verification');sp(0===count($v),'current policy can tighten old credential exposure immediately');
Ecapla_CertVerify_Fields::$map['campus']['public_exposure']='both';$v=$p->public_fields($snapshot,'verification');$keys=array_column($v,'key');sp(!in_array('campus',$keys,true),'current policy cannot loosen beyond the frozen issuance privacy ceiling');
unset(Ecapla_CertVerify_Fields::$map['gpa']);$mine=$p->recipient_fields($snapshot);$keys=array_column($mine,'key');sp(in_array('gpa',$keys,true),'retired field definition does not erase authenticated historical snapshot data');
echo"\n$pass scoped field presentation checks, $fail failures\n";exit($fail?1:0);
