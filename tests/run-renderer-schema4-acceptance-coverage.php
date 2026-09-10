<?php
/** Phase 4 final-renderer gate: the production ceremony must cover Schema 4. */
require_once __DIR__ . '/bootstrap-autoload.php';
require_once __DIR__ . '/render-plan-reference/reference_lib.php';

use Certloom\Application\Rendering\RendererAcceptanceContract;
use Certloom\Application\Rendering\RendererVerificationReport;

$checks=0; function rs4($c,$m){global $checks;$checks++;if(!$c){throw new RuntimeException($m);}}
$legacy_golden=__DIR__.'/fixtures/rendering/reference/manifest.json';
$legacy_plan=__DIR__.'/fixtures/rendering/render-plan-manifest.json';
$accept_golden=__DIR__.'/fixtures/rendering/reference/acceptance-manifest.json';
$accept_plan=__DIR__.'/fixtures/rendering/render-plan-acceptance-manifest.json';
rs4('fb5a3db32dbf24b1f7554bfab6f12a3a274f314226f5bb6501733f51adc4cb6f'===hash_file('sha256',$legacy_golden),'Legacy seven-fixture golden manifest must remain byte frozen.');
rs4('99a1e13fc48bf877699f22a76645be3e31f3762c8436ab6c57b6bb8a2e9da98a'===hash_file('sha256',$legacy_plan),'Legacy seven-fixture Render Plan manifest must remain byte frozen.');
$gold=json_decode(file_get_contents($accept_golden),true);$plan=json_decode(file_get_contents($accept_plan),true);
rs4(is_array($gold)&&5===($gold['version']??0)&&11===count($gold['fixtures']??array()),'Combined golden acceptance manifest must contain eleven fixtures.');
rs4(is_array($plan)&&1===($plan['version']??0)&&11===count($plan['fixtures']??array()),'Combined Render Plan acceptance manifest must contain eleven fixtures.');
rs4(hash_file('sha256',$legacy_golden)===($gold['legacy_manifest_sha256']??''),'Combined golden acceptance must bind the frozen legacy manifest.');
rs4(hash_file('sha256',$legacy_plan)===($plan['legacy_render_plan_manifest_sha256']??''),'Combined Render Plan acceptance must bind the frozen legacy manifest.');
$schema4=array('schema4-rich-text'=>'rich_text','schema4-signer'=>'signature','schema4-table'=>'table','schema4-data-table'=>'table');
foreach($schema4 as $name=>$op){rs4(isset($gold['fixtures'][$name]),$name.' golden artifact must exist.');rs4(4===($plan['fixtures'][$name]['layout_schema_version']??0),$name.' must be a Schema-4 fixture.');rs4(array($op)===($plan['fixtures'][$name]['operation_types']??null),$name.' must prove its intended Render Plan operation.');foreach(array('pdf','png','jpeg') as $fmt){$path=__DIR__.'/fixtures/rendering/reference/'.$name.'/reference.'.$fmt;rs4(is_file($path)&&hash_file('sha256',$path)===($gold['fixtures'][$name][$fmt.'_sha256']??''),$name.' '.$fmt.' golden artifact must match its manifest hash.');}}
rs4(8===RendererAcceptanceContract::VERSION,'Renderer acceptance contract must advance to v8 for mandatory Schema-4 coverage.');
rs4(6===RendererVerificationReport::VERSION,'Independent renderer verification report must advance to v6 for Schema-4 proof flags.');
rs4(RendererAcceptanceContract::legacy_fixtures()===array('english','urdu-rtl','mixed-script','long-name-shrink','rotation','qr','primitives'),'Legacy seven-fixture identity must remain explicit.');
rs4(RendererAcceptanceContract::schema4_fixtures()===array_keys($schema4),'Schema-4 acceptance fixture set must be explicit and complete.');
rs4(11===count(RendererAcceptanceContract::required_fixtures()),'Production renderer acceptance must require all eleven fixtures.');
foreach(array('schema4_rich_text','schema4_signer_signature','schema4_fixed_table','schema4_data_table') as $key){rs4(in_array($key,RendererAcceptanceContract::required_checks(),true),'Acceptance must require '.$key.'.');}
$producer=file_get_contents(__DIR__.'/renderer-candidate/producer-lib.php');$verifier=file_get_contents(__DIR__.'/renderer-candidate/verify-bundle.py');$evidence=file_get_contents(__DIR__.'/renderer-candidate/acceptance-evidence-lib.php');
rs4(false!==strpos($producer,'render-plan-acceptance-manifest.json')&&false!==strpos($producer,'reference/acceptance-manifest.json')&&false!==strpos($producer,'const BUNDLE_VERSION = 4'),'Production producer must bind the eleven-fixture manifests and bundle v4.');
rs4(false!==strpos($verifier,"SCHEMA4_FIXTURES")&&false!==strpos($verifier,"EXPECTED_SCHEMA4_OPS")&&false!==strpos($verifier,"'version':6"),'Independent verifier must prove Schema-4 paths and report v6.');
rs4(false!==strpos($evidence,'RendererAcceptanceContract v8')&&false!==strpos($evidence,'const BUNDLE_VERSION = 4'),'Acceptance evidence builder must consume the upgraded contract/bundle.');
$generated=certloom_render_plan_acceptance_json();rs4(hash('sha256',$generated)===hash_file('sha256',$accept_plan),'Stored eleven-fixture Render Plan acceptance manifest must reproduce exactly from canonical source.');
echo "PASS  renderer acceptance coverage requires legacy + Schema-4 rich text/signer/table/data-table evidence\n\n{$checks} checks, 0 failures\n";
