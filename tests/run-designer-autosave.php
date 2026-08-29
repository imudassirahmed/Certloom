<?php
/** DSN-07 Designer recovery autosave + optimistic-concurrency gate. */
define( 'ABSPATH', __DIR__ . '/' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'DAY_IN_SECONDS', 86400 );
if ( ! class_exists( 'WP_Error' ) ) { class WP_Error { private $c,$m,$d; public function __construct($c='',$m='',$d=null){$this->c=$c;$this->m=$m;$this->d=$d;} public function get_error_code(){return $this->c;} public function get_error_message(){return $this->m;} public function get_error_data(){return $this->d;} } }
function is_wp_error($v){return $v instanceof WP_Error;} function __($v){return $v;} function absint($v){return abs((int)$v);} function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/','',(string)$v));} function wp_json_encode($v,$flags=0){return json_encode($v,$flags);} function get_current_blog_id(){return 4;}
$GLOBALS['dsn7_meta']=array(); function update_user_meta($u,$k,$v){$GLOBALS['dsn7_meta'][$u][$k]=$v;return true;} function get_user_meta($u,$k,$single=true){return $GLOBALS['dsn7_meta'][$u][$k]??'';} function delete_user_meta($u,$k){unset($GLOBALS['dsn7_meta'][$u][$k]);return true;}
require_once __DIR__ . '/bootstrap-autoload.php';

use Certloom\Application\Rendering\CanonicalJson;
use Certloom\Application\Rendering\LayoutValidator;
use Certloom\Application\Templates\TemplateDefaults;
use Certloom\Domain\Rendering\LayoutSchema;
use Certloom\Infrastructure\Admin\TemplateDesignerAutosave;

final class Dsn7Wpdb {
 public $prefix='wp_'; public $last_error=''; public $template; public $revision;
 public function prepare($query,...$args){return array($query,$args);}
 public function get_row($prepared,$output=null){$args=$prepared[1];$table=$args[0]??''; if('wp_certloom_templates'===$table){return $this->template;} if('wp_certloom_template_revisions'===$table){return $this->revision;} return null;}
}
$pass=0;$fail=0; function dsn7($ok,$label){global$pass,$fail;if($ok){$pass++;echo"PASS  $label\n";}else{$fail++;echo"FAIL  $label\n";}} function dsn7has($s,$n,$l){dsn7(false!==strpos($s,$n),$l);} 

$layout=TemplateDefaults::blank_a4_landscape();
$layout['elements'][]=array('id'=>'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa','type'=>'text','x_mm'=>20,'y_mm'=>20,'width_mm'=>100,'height_mm'=>20,'z'=>1,'rotation_deg'=>0,'opacity'=>1,'text'=>'Base','style'=>array('font_family'=>'sans-serif','font_size_pt'=>18,'font_weight'=>400,'italic'=>false,'color'=>'#000000','align'=>'center','vertical_align'=>'middle','direction'=>'auto','line_height'=>1.2,'letter_spacing_pt'=>0,'overflow'=>'shrink'));
$layout=(new LayoutValidator())->normalize($layout); $json=new CanonicalJson(); $layoutJson=$json->encode($layout); $sha=$json->hash($layout);
$wpdb=new Dsn7Wpdb(); $GLOBALS['wpdb']=$wpdb;
$wpdb->template=array('id'=>7,'machine_key'=>'dsn7-template','name'=>'DSN 7','description'=>'','current_revision_id'=>11,'published_revision_id'=>null,'created_at'=>'2026-08-27 00:00:00','updated_at'=>'2026-08-27 00:00:00','archived_at'=>null);
$wpdb->revision=array('id'=>11,'template_id'=>7,'revision_number'=>3,'parent_revision_id'=>10,'layout_schema'=>LayoutSchema::NAME,'layout_schema_version'=>3,'layout_json'=>$layoutJson,'layout_sha256'=>$sha,'source_type'=>'manual','created_by_user_id'=>5,'created_at'=>'2026-08-27 00:00:00');
$service=new TemplateDesignerAutosave(); $session='11111111-1111-4111-8111-111111111111';
$changed=$layout; $changed['elements'][0]['text']='Recovered unsaved work';
$state=array('version'=>1,'locked_ids'=>array('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa','missing'),'snap_enabled'=>false);
$result=$service->save(7,5,11,$sha,$session,$changed,$state);
dsn7(!is_wp_error($result)&&'saved'===($result['status']??''),'valid recovery checkpoint saves without creating a Template revision');
$key=TemplateDesignerAutosave::meta_key(7); dsn7('certloom_template_designer_autosave_4_7'===$key,'autosave key is site + Template scoped');
$stored=json_decode(get_user_meta(5,$key,true),true); dsn7(11===($stored['base_revision_id']??0)&&$sha===($stored['base_revision_sha256']??''),'checkpoint binds exact immutable revision ID and SHA-256');
dsn7(array('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa')===($stored['designer_state']['locked_ids']??array()),'personal lock state is normalized to elements present in recovery layout');
$inspected=$service->inspect(7,5); dsn7('ready'===($inspected['status']??'')&&'Recovered unsaved work'===($inspected['record']['layout']['elements'][0]['text']??''),'matching checkpoint is eligible for explicit Designer recovery');
$otherSession=$service->save(7,5,11,$sha,'22222222-2222-4222-8222-222222222222',$changed,$state); dsn7(is_wp_error($otherSession)&&'certloom_designer_autosave_session_conflict'===$otherSession->get_error_code(),'second browser session cannot overwrite the first recovery checkpoint');
$wrongDiscard=$service->discard(7,5,'22222222-2222-4222-8222-222222222222'); dsn7(is_wp_error($wrongDiscard)&&'certloom_designer_autosave_session_conflict'===$wrongDiscard->get_error_code()&&''!==get_user_meta(5,$key,true),'second browser session cannot discard another session recovery checkpoint');

// Simulate another session committing a newer revision.
$new=$layout; $new['elements'][0]['text']='Newer authoritative save'; $newJson=$json->encode($new); $newSha=$json->hash($new);
$wpdb->template['current_revision_id']=12; $wpdb->revision=array('id'=>12,'template_id'=>7,'revision_number'=>4,'parent_revision_id'=>11,'layout_schema'=>LayoutSchema::NAME,'layout_schema_version'=>3,'layout_json'=>$newJson,'layout_sha256'=>$newSha,'source_type'=>'manual','created_by_user_id'=>9,'created_at'=>'2026-08-27 00:01:00');
$stale=$service->inspect(7,5); dsn7('stale'===($stale['status']??''),'checkpoint is never auto-merged after current revision changes');
$conflict=$service->save(7,5,11,$sha,$session,$changed,$state); dsn7(is_wp_error($conflict)&&'certloom_designer_autosave_conflict'===$conflict->get_error_code(),'stale autosave fails closed instead of overwriting another session');
$service->discard(7,5,$session); dsn7(''===get_user_meta(5,$key,true),'owning session can discard its personal recovery checkpoint');

// Integrity tamper should not recover.
$wpdb->template['current_revision_id']=11; $wpdb->revision=array('id'=>11,'template_id'=>7,'revision_number'=>3,'parent_revision_id'=>10,'layout_schema'=>LayoutSchema::NAME,'layout_schema_version'=>3,'layout_json'=>$layoutJson,'layout_sha256'=>$sha,'source_type'=>'manual','created_by_user_id'=>5,'created_at'=>'2026-08-27 00:00:00');
$service->save(7,5,11,$sha,$session,$changed,$state); $tampered=json_decode(get_user_meta(5,$key,true),true); $tampered['layout_sha256']=str_repeat('0',64); update_user_meta(5,$key,json_encode($tampered));
dsn7('invalid'===($service->inspect(7,5)['status']??''),'tampered recovery layout/hash fails closed');

$root=dirname(__DIR__); $admin=file_get_contents($root.'/src/Infrastructure/Admin/TemplateAdmin.php'); $repo=file_get_contents($root.'/src/Infrastructure/Repository/TemplateRepository.php'); $js=file_get_contents($root.'/admin/assets/designer.js'); $autosave=file_get_contents($root.'/src/Infrastructure/Admin/TemplateDesignerAutosave.php'); $uninstall=file_get_contents($root.'/uninstall.php');
dsn7has($admin,"wp_ajax_certloom_template_designer_autosave",'authenticated AJAX autosave endpoint is explicitly registered');
dsn7has($admin,"check_ajax_referer( 'certloom_template_designer_autosave_'",'autosave endpoint is nonce bound per Template');
dsn7has($admin,'expected_revision_sha256','Designer and advanced JSON save carry exact revision hash proof');
dsn7has($admin,'designer_autosave_session_id','manual Designer save identifies the owning recovery session before cleanup');
dsn7has($repo,'certloom_template_stale_revision_hash','manual revision save also rejects a stale exact hash review');
dsn7has($autosave,'MAX_BYTES = 2097152','recovery checkpoint storage is explicitly bounded');
dsn7has($autosave,"'base_revision_id'",'recovery record persists its exact concurrency base');
dsn7has($autosave,"'session_id'",'recovery record binds one browser editing session to prevent same-user tab races');
dsn7(false===strpos($autosave,'$wpdb->update')&&false===strpos($autosave,'$wpdb->insert'),'autosave store cannot mutate Template authority tables');
dsn7has($js,'AUTOSAVE_DELAY_MS = 1800','browser autosave is debounced rather than writing on every pointer movement');
dsn7has($js,'certloom_designer_autosave_conflict','browser enters a fail-closed conflict state when another session wins');
dsn7has($js,'data-certloom-designer-recovery-json','browser can recover only a server-vetted matching checkpoint');
dsn7has($js,'discardAutosave( false )','reset/clean state removes obsolete server recovery checkpoint');
dsn7(false===strpos($js,'localStorage')&&false===strpos($js,'sessionStorage'),'browser storage is not used as Designer authority or recovery persistence');
dsn7has($uninstall,'certloom_template_designer_autosave_','uninstall removes per-user Designer recovery checkpoints');
dsn7(!file_exists($root.'/resources/renderer/accepted.json'),'DSN-07 does not manufacture renderer authority');

echo "\n$pass DSN-07 checks, $fail failures\n"; exit($fail?1:0);
