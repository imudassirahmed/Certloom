<?php
/** Merge-restore policy tests that do not require a database. */

if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', __DIR__ . '/fake-wp/' );
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error { private $c,$m; public function __construct($c='',$m=''){ $this->c=$c;$this->m=$m; } public function get_error_code(){return $this->c;} public function get_error_message(){return $this->m;} }
}
function is_wp_error($v){return $v instanceof WP_Error;}
function __($t){return $t;}
function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/','',(string)$v));}
function sanitize_text_field($v){return trim((string)$v);}
function current_time($type,$gmt=false){return '2026-08-22 00:00:00';}
require_once __DIR__ . '/bootstrap-autoload.php';

use Certloom\Application\Restore\DomainRestoreConfigurationMerger;

function rm_same($e,$a,$m=''){if($e!==$a)throw new RuntimeException(($m?$m.': ':'').'Expected '.var_export($e,true).', got '.var_export($a,true));}

$current=array(
	'certverify_fields'=>array('exists'=>true,'value'=>array(array('key'=>'department','label'=>'Department','type'=>'text'))),
	'certverify_retired_fields'=>array('exists'=>true,'value'=>array('legacy'=>array('key'=>'legacy','label'=>'Legacy','type'=>'text','retired_at'=>'2025-01-01 00:00:00'))),
	'certverify_settings'=>array('exists'=>true,'value'=>array('verification_page'=>10,'brand'=>'current')),
);
$backup=array(
	'certverify_fields'=>array('exists'=>true,'value'=>array(
		array('key'=>'department','label'=>'Old Department','type'=>'text'),
		array('key'=>'score','label'=>'Score','type'=>'number'),
		array('key'=>'holder_name','label'=>'Holder','type'=>'text','core'=>true),
	)),
	'certverify_retired_fields'=>array('exists'=>true,'value'=>array(
		'course_code'=>array('key'=>'course_code','label'=>'Course Code','type'=>'text','retired_at'=>'2024-01-01 00:00:00'),
	)),
	'certverify_settings'=>array('exists'=>true,'value'=>array('verification_page'=>2,'brand'=>'backup')),
);
$merged=(new DomainRestoreConfigurationMerger())->merge($current,$backup);
rm_same(false,is_wp_error($merged),'merge configuration');
rm_same($current['certverify_fields'],$merged['certverify_fields'],'current active fields win');
rm_same($current['certverify_settings'],$merged['certverify_settings'],'current settings win');
$retired=$merged['certverify_retired_fields']['value'];
rm_same(true,isset($retired['legacy']),'current retired retained');
rm_same(true,isset($retired['score']),'backup-only active field retired');
rm_same('number',$retired['score']['type'],'backup field type preserved');
rm_same(true,isset($retired['course_code']),'backup-only retired field retained');
rm_same(false,isset($retired['department']),'current active key not duplicated as retired');
rm_same(false,isset($retired['holder_name']),'core field not retired');
echo "PASS  Merge preserves current configuration while reserving backup-only custom-field keys\n";

echo "\n1 tests, 0 failures\n";
