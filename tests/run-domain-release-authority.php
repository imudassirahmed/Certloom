<?php
require_once __DIR__ . '/bootstrap-autoload.php';

use Certloom\Application\Migration\DomainAcceptanceImporter;
use Certloom\Application\Migration\DomainReleaseManifest;
use Certloom\Application\Rendering\CanonicalJson;
use Certloom\Application\Rendering\RuntimePayloadFingerprint;
use Certloom\Infrastructure\Migration\DomainAuthorityGate;
use Certloom\Infrastructure\Migration\DomainFeatureGate;

$checks=0;
function darg_check($ok,$message){global $checks;++$checks;if(!$ok){fwrite(STDERR,"FAIL  $message\n");exit(1);}}
function darg_throws($fn,$message){try{$fn();}catch(Throwable $e){darg_check(true,$message);return;}darg_check(false,$message);}
function darg_rrmdir($path){if(!is_dir($path))return;foreach(scandir($path)?:array() as $leaf){if('.'===$leaf||'..'===$leaf)continue;$full=$path.DIRECTORY_SEPARATOR.$leaf;if(is_dir($full)&&!is_link($full))darg_rrmdir($full);else @unlink($full);}@rmdir($path);}
function darg_environment(){return array('wp_version'=>'7.0','php_version'=>'8.4.0','wp_environment_type'=>'staging','db_server_info'=>'8.0.42 MySQL','db_version'=>'8.0.42','database_instance_sha256'=>str_repeat('9',64),'multisite'=>false);}
function darg_source_version(){
	$bytes=file_get_contents(dirname(__DIR__).'/certloom.php');
	if(!is_string($bytes)||1!==preg_match("/define\(\s*'ECAPLA_CERTVERIFY_VERSION'\s*,\s*'([^']+)'\s*\)/",$bytes,$m))throw new RuntimeException('Could not determine Certloom source version.');
	return trim($m[1]);
}
function darg_base($version,$payload,$environment){$gates=array();foreach(array('domain-premium','domain-row-lock','domain-schema','numbering-policy') as $name){$gates[$name]=array('transcript_file'=>$name.'.log','transcript_sha256'=>hash('sha256','t-'.$name),'transcript_bytes'=>128,'gate_sha256'=>hash('sha256','g-'.$name),'helper_sha256'=>hash('sha256','h-'.$name),'pass_markers'=>1);}ksort($gates,SORT_STRING);$c=new CanonicalJson();return array('schema'=>'certloom-wp-integration-base-gate-index','version'=>2,'run_id'=>str_repeat('a',32),'plugin_version'=>$version,'runtime_payload'=>$payload,'environment'=>$environment,'environment_sha256'=>$c->hash($environment),'gates'=>$gates);}
function darg_stored(){ $contracts=array('database_proof'=>array('certloom-database-proof',4),'domain_acceptance'=>array('certloom-domain-acceptance-proof',7),'migration_preflight'=>array('certloom-legacy-migration-preflight-proof',4),'staging_migration'=>array('certloom-staging-migration-rehearsal',4),'staging_restore'=>array('certloom-staging-restore-rehearsal',5),'staging_cutover'=>array('certloom-staging-cutover-rehearsal',5));$out=array();$i=0;foreach($contracts as $name=>$sv){$report='Privacy-safe '.$name.' report.';$out[$name]=array('schema'=>$sv[0],'version'=>$sv[1],'checked_at'=>1700000000+$i,'result_sha256'=>hash('sha256','result-'.$name),'report_sha256'=>hash('sha256',$report."\n"),'report'=>$report);++$i;}return $out; }
function darg_imported($version,$payload){$c=new CanonicalJson();$env=darg_environment();$state=array('hash'=>str_repeat('f',64),'configuration_hash'=>str_repeat('1',64),'records'=>3,'entity_counts'=>array('issuers'=>1,'programs'=>1,'recipients'=>1),'generation_sha256'=>str_repeat('2',64));$bundle=array('schema'=>DomainAcceptanceImporter::BUNDLE_SCHEMA,'version'=>DomainAcceptanceImporter::BUNDLE_VERSION,'status'=>'qualified','generated_at'=>1700001000,'plugin_version'=>$version,'runtime_payload'=>$payload,'environment'=>$env,'environment_sha256'=>$c->hash($env),'domain_state'=>$state,'domain_state_sha256'=>$c->hash($state),'base_gates'=>darg_base($version,$payload,$env),'stored_evidence'=>darg_stored());$acceptance_bytes=$c->encode($bundle)."\n";$index=array('schema'=>DomainAcceptanceImporter::INDEX_SCHEMA,'version'=>DomainAcceptanceImporter::INDEX_VERSION,'plugin_version'=>$version,'runtime_payload'=>$payload,'environment_sha256'=>$bundle['environment_sha256'],'domain_state_sha256'=>$bundle['domain_state_sha256'],'acceptance_sha256'=>hash('sha256',$acceptance_bytes),'acceptance_bytes'=>strlen($acceptance_bytes));return (new DomainAcceptanceImporter())->import($bundle,$index); }

$payload_contract=new RuntimePayloadFingerprint();$release=new DomainReleaseManifest();$current_version=darg_source_version();
$synthetic=array('schema'=>RuntimePayloadFingerprint::SCHEMA,'version'=>RuntimePayloadFingerprint::VERSION,'sha256'=>str_repeat('8',64),'file_count'=>5,'total_bytes'=>1024);
$imported=darg_imported($current_version,$synthetic);$manifest=$release->from_imported_acceptance($imported);
darg_check(DomainAcceptanceImporter::BUNDLE_VERSION===5&&DomainAcceptanceImporter::INDEX_VERSION===5,'DBAUTH importer requires normalized-domain acceptance bundle/index v5.');
darg_check(DomainReleaseManifest::VERSION===1&&$manifest['version']===1,'DBAUTH release manifest uses the explicit v1 authority contract.');
darg_check($manifest['runtime_payload']===$synthetic,'DBAUTH release authority binds the exact production payload fingerprint.');
darg_check($manifest['authority']===array('write_paths'=>true,'admin_preview'=>true,'data_tools'=>true),'DBAUTH authority proves the complete reviewed scope atomically.');
darg_check(hash_equals($imported['acceptance_sha256'],$manifest['acceptance']['acceptance_sha256'])&&hash_equals($imported['index_sha256'],$manifest['acceptance']['acceptance_index_sha256']),'DBAUTH release manifest binds exact acceptance and acceptance-index identities.');
$reordered_bundle=$imported['bundle'];$reordered_bundle['stored_evidence']=array_reverse($reordered_bundle['stored_evidence'],true);$c_order=new CanonicalJson();$reordered_bytes=$c_order->encode($reordered_bundle)."\n";$reordered_index=$imported['index'];$reordered_index['acceptance_sha256']=hash('sha256',$reordered_bytes);$reordered_index['acceptance_bytes']=strlen($reordered_bytes);$reordered=(new DomainAcceptanceImporter())->import($reordered_bundle,$reordered_index);darg_check($reordered['bundle']['stored_evidence']===$imported['bundle']['stored_evidence'],'stored-evidence JSON object member order is semantically irrelevant and canonicalized deterministically.');
$bad=$manifest;$bad['authority']['data_tools']=false;darg_throws(fn()=>$release->normalize($bad),'partial DBAUTH scope is rejected.');
$bad_bundle=$imported['bundle'];$bad_bundle['runtime_payload']['sha256']=str_repeat('7',64);darg_throws(fn()=>(new DomainAcceptanceImporter())->import($bad_bundle,$imported['index']),'acceptance payload tamper is rejected.');
$bad_index=$imported['index'];$bad_index['acceptance_sha256']=str_repeat('6',64);darg_throws(fn()=>(new DomainAcceptanceImporter())->import($imported['bundle'],$bad_index),'acceptance-index tamper is rejected.');
$bad_report=$imported['bundle'];$bad_report['stored_evidence']['database_proof']['report'].=' changed';darg_throws(fn()=>(new DomainAcceptanceImporter())->import($bad_report,$imported['index']),'stored privacy-safe report tamper is rejected.');

$tmp=sys_get_temp_dir().'/certloom-domain-authority-'.bin2hex(random_bytes(6));mkdir($tmp.'/resources/domain',0777,true);mkdir($tmp.'/src',0777,true);file_put_contents($tmp.'/certloom.php',"<?php /* exact runtime seed */\n");file_put_contents($tmp.'/src/runtime.php',"<?php return 'exact';\n");
$missing=new DomainAuthorityGate(null,$tmp,$current_version);$status=$missing->manifest_status();darg_check(!$status['accepted']&&'manifest_missing'===$status['reason'],'missing DBAUTH manifest fails closed.');
$runtime=$payload_contract->fingerprint($tmp);$runtime_imported=darg_imported($current_version,$runtime);$runtime_manifest=$release->from_imported_acceptance($runtime_imported);file_put_contents($tmp.'/resources/domain/accepted.json',$release->encode($runtime_manifest)."\n");$gate=new DomainAuthorityGate(null,$tmp,$current_version);
darg_check($gate->manifest_status()['accepted']===true,'exact DBAUTH manifest validates for exact version/payload.');
darg_check($gate->allows('write_paths')&&$gate->allows('admin_preview')&&$gate->allows('data_tools'),'exact DBAUTH manifest authorizes all reviewed normalized-domain scopes.');
darg_check(!$gate->allows('unknown'),'unknown DBAUTH scope fails closed.');
file_put_contents($tmp.'/src/runtime.php',"<?php return 'mutated';\n");darg_check('runtime_payload_mismatch'===(new DomainAuthorityGate(null,$tmp,$current_version))->manifest_status()['reason'],'DBAUTH fails closed after production-byte mutation.');
file_put_contents($tmp.'/src/runtime.php',"<?php return 'exact';\n");darg_check((new DomainAuthorityGate(null,$tmp,$current_version))->manifest_status()['accepted']===true,'DBAUTH recovers only when exact accepted production bytes are restored.');
darg_check('plugin_version_mismatch'===(new DomainAuthorityGate(null,$tmp,'9.9.9'))->manifest_status()['reason'],'DBAUTH cannot survive a semantic-version mismatch.');
file_put_contents($tmp.'/resources/domain/accepted.json','{broken');darg_check('manifest_invalid'===(new DomainAuthorityGate(null,$tmp,$current_version))->manifest_status()['reason'],'malformed DBAUTH JSON fails closed.');

// Legacy source constants remain non-authoritative even when an external host predefines them.
if(!defined('CERTLOOM_DOMAIN_WRITE_PATHS_READY'))define('CERTLOOM_DOMAIN_WRITE_PATHS_READY',true);if(!defined('CERTLOOM_DOMAIN_ADMIN_PREVIEW'))define('CERTLOOM_DOMAIN_ADMIN_PREVIEW',true);if(!defined('CERTLOOM_DOMAIN_DATA_TOOLS_READY'))define('CERTLOOM_DOMAIN_DATA_TOOLS_READY',true);DomainFeatureGate::reset_cache();
darg_check(!DomainFeatureGate::any_ready(),'legacy readiness constants cannot enable normalized-domain release authority without accepted.json.');
$plugin_source=file_get_contents(dirname(__DIR__).'/certloom.php');
darg_check(strpos($plugin_source,"define( 'CERTLOOM_DOMAIN_WRITE_PATHS_READY', false )")!==false&&strpos($plugin_source,"define( 'CERTLOOM_DOMAIN_ADMIN_PREVIEW', false )")!==false&&strpos($plugin_source,"define( 'CERTLOOM_DOMAIN_DATA_TOOLS_READY', false )")!==false,'canonical source keeps all historical readiness constants false by default.');
$cutover_source=file_get_contents(dirname(__DIR__).'/src/Infrastructure/Migration/DomainCutoverCoordinator.php');
darg_check(strpos($cutover_source,'DomainFeatureGate::write_paths_ready()')!==false&&strpos($cutover_source,'DomainFeatureGate::data_tools_ready()')!==false,'site cutover requires manifest-backed write and Data Tools release authority.');
$bootstrap_source=file_get_contents(dirname(__DIR__).'/src/Plugin.php');
darg_check(strpos($bootstrap_source,'DomainFeatureGate::admin_preview_ready()')!==false&&strpos($bootstrap_source,'DomainFeatureGate::any_ready()')!==false,'domain bootstrap/admin storage uses effective manifest-backed authority.');
$tutor_source=file_get_contents(dirname(__DIR__).'/src/Infrastructure/Integration/TutorLmsBridge.php');$learn_source=file_get_contents(dirname(__DIR__).'/src/Infrastructure/Integration/LearnDashBridge.php');
darg_check(strpos($tutor_source,'DomainFeatureGate::write_paths_ready()')!==false&&strpos($learn_source,'DomainFeatureGate::write_paths_ready()')!==false,'Tutor and LearnDash automatic issuance require effective DBAUTH write authority.');
$fingerprint_source=file_get_contents(dirname(__DIR__).'/src/Application/Rendering/RuntimePayloadFingerprint.php');
darg_check(strpos($fingerprint_source,"'resources/domain/accepted.json'")!==false,'runtime production fingerprint excludes only immutable DBAUTH authority metadata to avoid self-reference.');
$build_source=file_get_contents(__DIR__.'/build-release.sh');
darg_check(strpos($build_source,'CERTLOOM_DOMAIN_AUTHORITY_DIR')!==false&&strpos($build_source,'validate-authorized-release.php')!==false&&strpos($build_source,'domain_authority=validated')!==false,'deterministic builder has an explicit validated DBAUTH packaging path.');
darg_check(is_file(dirname(__DIR__).'/resources/domain/README.txt'),'production source documents immutable DBAUTH authority semantics.');

@unlink($tmp.'/resources/domain/accepted.json');@unlink($tmp.'/src/runtime.php');@unlink($tmp.'/certloom.php');@rmdir($tmp.'/src');@rmdir($tmp.'/resources/domain');@rmdir($tmp.'/resources');@rmdir($tmp);
echo "PASS  DBAUTH release authority is exact-payload bound, complete-scope, fail-closed and independent from legacy constants\n\n{$checks} checks, 0 failures\n";
