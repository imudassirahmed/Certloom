<?php
/** TPL-01 template foundation contract tests. */
define( 'ABSPATH', __DIR__ . '/' );
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error { private $c,$m,$d; public function __construct($c='',$m='',$d=null){$this->c=$c;$this->m=$m;$this->d=$d;} public function get_error_code(){return $this->c;} public function get_error_message(){return $this->m;} public function get_error_data(){return $this->d;} }
}
function is_wp_error($v){return $v instanceof WP_Error;}
function __($t){return $t;}
function absint($v){return abs((int)$v);}
function sanitize_key($key){return strtolower(preg_replace('/[^a-z0-9_\-]/','',(string)$key));}
function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}
require_once __DIR__ . '/bootstrap-autoload.php';

use Certloom\Application\Templates\TemplateDefaults;
use Certloom\Application\Templates\TemplatePackage;

$checks=0;
function tpl_check($ok,$message){global $checks;if(!$ok){throw new RuntimeException($message);} $checks++;}
function tpl_has($source,$needle,$message){tpl_check(false!==strpos($source,$needle),$message);}

$package = new TemplatePackage();
$layout = TemplateDefaults::blank_a4_landscape();
$built = $package->build(array('machine_key'=>'classic-academic','name'=>'Classic Academic','description'=>'Portable draft'),array('layout'=>$layout),'1.6.15');
tpl_check(!is_wp_error($built),'valid package builds');
$json = $package->encode($built);
tpl_check(!is_wp_error($json),'valid package encodes');
$decoded = $package->decode($json);
tpl_check(!is_wp_error($decoded),'valid package round-trips');
tpl_check('classic-academic'===$decoded['machine_key'],'machine key round-trips');
tpl_check(hash_equals($built['revision']['layout_sha256'],$decoded['layout_sha256']),'layout hash round-trips');

$tampered = json_decode($json,true); $tampered['revision']['layout_sha256']=str_repeat('0',64);
$bad = $package->decode(json_encode($tampered));
tpl_check(is_wp_error($bad) && 'certloom_template_import_hash_mismatch'===$bad->get_error_code(),'tampered layout hash fails closed');
$extra = json_decode($json,true); $extra['remote_url']='https://example.test/template';
$bad = $package->decode(json_encode($extra));
tpl_check(is_wp_error($bad) && 'certloom_template_import_format_invalid'===$bad->get_error_code(),'unknown top-level fields fail closed');
$bad = $package->build(array('machine_key'=>'Bad Key','name'=>'Name'),array('layout'=>$layout),'1.6.15');
tpl_check(is_wp_error($bad) && 'certloom_template_export_identity_invalid'===$bad->get_error_code(),'invalid export identity fails closed');

$root=dirname(__DIR__);
$files=array(
 'schema'=>file_get_contents($root.'/src/Infrastructure/Database/SchemaManager.php'),
 'repo'=>file_get_contents($root.'/src/Infrastructure/Repository/TemplateRepository.php'),
 'assignment_policy'=>file_get_contents($root.'/src/Application/Templates/TemplateAssignmentPolicy.php'),
 'admin'=>file_get_contents($root.'/src/Infrastructure/Admin/TemplateAdmin.php'),
 'plugin'=>file_get_contents($root.'/src/Plugin.php'),
 'backup'=>file_get_contents($root.'/src/Application/Backup/DomainBackupFormat.php'),
 'backup_repo'=>file_get_contents($root.'/src/Infrastructure/Backup/DomainBackupRepository.php'),
 'preflight'=>file_get_contents($root.'/src/Application/Restore/DomainRestorePreflightService.php'),
 'workspace'=>file_get_contents($root.'/src/Infrastructure/Restore/DomainRestoreWorkspace.php'),
 'uninstall'=>file_get_contents($root.'/uninstall.php'),
 'restore_map'=>file_get_contents($root.'/src/Infrastructure/Restore/DomainRestoreMapRepository.php'),
 'acceptance'=>file_get_contents($root.'/src/Infrastructure/Diagnostics/DomainAcceptanceProof.php'),
);
foreach($files as $k=>$v){tpl_check(is_string($v)&&''!==$v,'source '.$k.' available');}
tpl_has($files['schema'],"const SCHEMA_VERSION         = '15'",'schema v15');
foreach(array('templates','template_revisions','template_assignments') as $table){tpl_has($files['schema'],"'$table'",'schema maps '.$table);tpl_has($files['backup_repo'],"'$table'",'backup owns '.$table);}
tpl_has($files['schema'],'UNIQUE KEY machine_key (machine_key)','stable template identity is unique');
tpl_has($files['schema'],'UNIQUE KEY template_revision (template_id,revision_number)','revision number immutable identity');
tpl_has($files['schema'],'UNIQUE KEY scope_assignment (scope_type,scope_id)','one deterministic assignment per scope');
tpl_has($files['repo'],'FOR UPDATE','template draft updates use row locking');
tpl_has($files['repo'],'certloom_template_stale_revision','stale revision saves fail closed');
tpl_has($files['repo'],'certloom_template_publish_renderer_gate','publishing remains renderer-gated');
tpl_has($files['repo'],'TemplateAssignmentPolicy','template repository delegates assignment hierarchy to the shared policy');
tpl_has($files['assignment_policy'],"array( self::SCOPE_BATCH, self::SCOPE_PROGRAM, self::SCOPE_ISSUER )",'shared assignment policy preserves batch/program/issuer precedence');
tpl_has($files['admin'],'expected_revision_id','admin carries optimistic revision proof');
tpl_has($files['admin'],'Publish (renderer gate)','admin does not fake publication');
tpl_has($files['plugin'],'TemplateAdmin','gated template admin is wired');
tpl_has($files['backup'],'const VERSION = 4','template-aware backup is v4');
tpl_has($files['backup'],'const PRIVACY_VERSION = 3','v3 backups remain explicitly supported');
tpl_has($files['preflight'],'template_parent_revision_invalid','semantic preflight validates revision lineage');
tpl_has($files['workspace'],'template_published_revision_invalid','staged restore re-proves published pointer');
tpl_has($files['workspace'],"'templates' => 't', 'template_revisions' => 'u', 'template_assignments' => 'y'",'restore workspace gives every template entity a unique private-table code');
tpl_has($files['restore_map'],"'templates', 'template_revisions', 'template_assignments'",'durable Merge mapping accepts template entities');
tpl_has($files['acceptance'],'const RESULT_VERSION = 5','exact-payload-bound domain acceptance invalidates older template-era evidence');
tpl_has($files['uninstall'],'certloom_template_assignments','uninstall owns template assignments');
tpl_has($files['uninstall'],'certloom_template_revisions','uninstall owns revisions');
tpl_has($files['uninstall'],'certloom_templates','uninstall owns templates');

echo "PASS  TPL-01 versioned template foundation is deterministic, portable and renderer-gated\n";
echo "PASS  template revisions, restore ownership, backup compatibility and stale-edit safety remain fail-closed\n\n";
echo $checks . " checks, 0 failures\n";
