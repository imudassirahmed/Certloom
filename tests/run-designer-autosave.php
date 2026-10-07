<?php
/** DSN-07 Designer recovery autosave + optimistic-concurrency gate. */

if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', __DIR__ . '/' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'DAY_IN_SECONDS', 86400 );
if ( ! class_exists( 'WP_Error' ) ) { class WP_Error { private $c,$m,$d; public function __construct($c='',$m='',$d=null){$this->c=$c;$this->m=$m;$this->d=$d;} public function get_error_code(){return $this->c;} public function get_error_message(){return $this->m;} public function get_error_data(){return $this->d;} } }
function is_wp_error($v){return $v instanceof WP_Error;} function __($v){return $v;} function absint($v){return abs((int)$v);} function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/','',(string)$v));} function wp_json_encode($v,$flags=0){return json_encode($v,$flags);} function get_current_blog_id(){return 4;}
function wp_slash($v){return addslashes($v);}
$GLOBALS['dsn7_meta']=array(); function update_user_meta($u,$k,$v){if(!empty($GLOBALS['dsn7_deny_write'])){return false;}$GLOBALS['dsn7_meta'][$u][$k]=stripslashes($v);return true;} function get_user_meta($u,$k,$single=true){$value=$GLOBALS['dsn7_meta'][$u][$k]??'';if(isset($GLOBALS['dsn7_on_read'])){$hook=$GLOBALS['dsn7_on_read'];unset($GLOBALS['dsn7_on_read']);$hook();}return $value;} function delete_user_meta($u,$k){unset($GLOBALS['dsn7_meta'][$u][$k]);return true;}
function wp_cache_delete($key,$group){return true;}
require_once __DIR__ . '/bootstrap-autoload.php';

use Certloom\Application\Rendering\CanonicalJson;
use Certloom\Application\Rendering\LayoutValidator;
use Certloom\Application\Templates\TemplateDefaults;
use Certloom\Domain\Rendering\LayoutSchema;
use Certloom\Infrastructure\Admin\TemplateDesignerAutosave;

final class Dsn7Wpdb {
 public $prefix='wp_'; public $usermeta='wp_usermeta'; public $last_error=''; public $template; public $revision; public $release_error=false;
 public function prepare($query,...$args){return array($query,$args);}
 public function get_row($prepared,$output=null){$args=$prepared[1];$table=$args[0]??''; if('wp_certloom_templates'===$table){return $this->template;} if('wp_certloom_template_revisions'===$table){return $this->revision;} return null;}
 public function get_var($prepared){
  $query=$prepared[0];$name=$prepared[1][0];$owner=spl_object_id($this);$held=$GLOBALS['dsn7_locks'][$name]??null;
  if(str_contains($query,'GET_LOCK')){if($held&&$held['owner']!==$owner){return '0';}$GLOBALS['dsn7_locks'][$name]=array('owner'=>$owner,'depth'=>($held['depth']??0)+1);return '1';}
  if(str_contains($query,'RELEASE_LOCK')){if(!$held||$held['owner']!==$owner){return null;}if(1===$held['depth']){unset($GLOBALS['dsn7_locks'][$name]);}else{$GLOBALS['dsn7_locks'][$name]['depth']--;}return $this->release_error?null:'1';}
  return null;
 }
}
$pass=0;$fail=0; function dsn7($ok,$label){global$pass,$fail;if($ok){$pass++;echo"PASS  $label\n";}else{$fail++;echo"FAIL  $label\n";}} function dsn7has($s,$n,$l){dsn7(false!==strpos($s,$n),$l);} 

$layout=TemplateDefaults::blank_a4_landscape();
$layout['_meta']['schema_version']=LayoutSchema::VERSION_V3; // Explicit historical-revision recovery fixture; latest authoring schema may advance.
$layout['elements'][]=array('id'=>'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa','type'=>'text','x_mm'=>20,'y_mm'=>20,'width_mm'=>100,'height_mm'=>20,'z'=>1,'rotation_deg'=>0,'opacity'=>1,'text'=>'Base','style'=>array('font_family'=>'sans-serif','font_size_pt'=>18,'font_weight'=>400,'italic'=>false,'color'=>'#000000','align'=>'center','vertical_align'=>'middle','direction'=>'auto','line_height'=>1.2,'letter_spacing_pt'=>0,'overflow'=>'shrink'));
$layout=(new LayoutValidator())->normalize($layout); $json=new CanonicalJson(); $layoutJson=$json->encode($layout); $sha=$json->hash($layout);
$wpdb=new Dsn7Wpdb(); $GLOBALS['wpdb']=$wpdb;
$wpdb->template=array('id'=>7,'machine_key'=>'dsn7-template','name'=>'DSN 7','description'=>'','current_revision_id'=>11,'published_revision_id'=>null,'created_at'=>'2026-08-27 00:00:00','updated_at'=>'2026-08-27 00:00:00','archived_at'=>null);
$wpdb->revision=array('id'=>11,'template_id'=>7,'revision_number'=>3,'parent_revision_id'=>10,'layout_schema'=>LayoutSchema::NAME,'layout_schema_version'=>3,'layout_json'=>$layoutJson,'layout_sha256'=>$sha,'source_type'=>'manual','created_by_user_id'=>5,'created_at'=>'2026-08-27 00:00:00');
$service=new TemplateDesignerAutosave(); $session='11111111-1111-4111-8111-111111111111';
$changed=$layout; $changed['elements'][0]['text']='Recovered unsaved work';
$state=array('version'=>2,'locked_ids'=>array('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa','missing'),'hidden_ids'=>array('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'),'layer_labels'=>array('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa'=>'Primary title','missing'=>'Nope'),'snap_enabled'=>false);
$result=$service->save(7,5,11,$sha,$session,$changed,$state);
dsn7(!is_wp_error($result)&&'saved'===($result['status']??''),'valid recovery checkpoint saves without creating a Template revision');
$key=TemplateDesignerAutosave::meta_key(7); dsn7('certloom_template_designer_autosave_4_7'===$key,'autosave key is site + Template scoped');
$stored=json_decode(get_user_meta(5,$key,true),true); dsn7(11===($stored['base_revision_id']??0)&&$sha===($stored['base_revision_sha256']??''),'checkpoint binds exact immutable revision ID and SHA-256');
dsn7(array('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa')===($stored['designer_state']['locked_ids']??array())&&array('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa')===($stored['designer_state']['hidden_ids']??array())&&'Primary title'===($stored['designer_state']['layer_labels']['aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa']??''),'personal authoring organization state is normalized to elements present in recovery layout');
$inspected=$service->inspect(7,5); dsn7('ready'===($inspected['status']??'')&&'Recovered unsaved work'===($inspected['record']['layout']['elements'][0]['text']??''),'matching checkpoint is eligible for explicit Designer recovery');
$otherSession=$service->save(7,5,11,$sha,'22222222-2222-4222-8222-222222222222',$changed,$state); dsn7(is_wp_error($otherSession)&&'certloom_designer_autosave_session_conflict'===$otherSession->get_error_code(),'second browser session cannot overwrite the first recovery checkpoint');
$wrongDiscard=$service->discard(7,5,'22222222-2222-4222-8222-222222222222'); dsn7(is_wp_error($wrongDiscard)&&'certloom_designer_autosave_session_conflict'===$wrongDiscard->get_error_code()&&''!==get_user_meta(5,$key,true),'second browser session cannot discard another session recovery checkpoint');

// Simulate another session committing a newer revision.
$new=$layout; $new['elements'][0]['text']='Newer authoritative save'; $newJson=$json->encode($new); $newSha=$json->hash($new);
$wpdb->template['current_revision_id']=12; $wpdb->revision=array('id'=>12,'template_id'=>7,'revision_number'=>4,'parent_revision_id'=>11,'layout_schema'=>LayoutSchema::NAME,'layout_schema_version'=>3,'layout_json'=>$newJson,'layout_sha256'=>$newSha,'source_type'=>'manual','created_by_user_id'=>9,'created_at'=>'2026-08-27 00:01:00');
$stale=$service->inspect(7,5); dsn7('stale'===($stale['status']??''),'checkpoint is never auto-merged after current revision changes');
$conflict=$service->save(7,5,11,$sha,$session,$changed,$state); dsn7(is_wp_error($conflict)&&'certloom_designer_autosave_conflict'===$conflict->get_error_code(),'stale autosave fails closed instead of overwriting another session');
$prepared=$service->prepare_for_editor(7,5); dsn7('none'===($prepared['status']??'')&&'stale'===($prepared['retired_status']??'')&&''===get_user_meta(5,$key,true),'editor open retires an obsolete checkpoint instead of surfacing it as a live conflict');
$retired=json_decode(get_user_meta(5,TemplateDesignerAutosave::retired_meta_key(7),true),true); dsn7('stale'===($retired['reason']??'')&&64===strlen((string)($retired['checkpoint_sha256']??''))&&!empty($retired['checkpoint_bytes'])&&!isset($retired['checkpoint_raw']),'retired stale recovery keeps only a compact diagnostic receipt instead of nesting the full checkpoint');
$newSession='33333333-3333-4333-8333-333333333333'; $newRecovery=$new; $newRecovery['elements'][0]['text']='Unsaved against newer base';
$fresh=$service->save(7,5,12,$newSha,$newSession,$newRecovery,$state); dsn7(!is_wp_error($fresh)&&'saved'===($fresh['status']??''),'new Studio session can autosave immediately after stale checkpoint retirement');
$service->discard(7,5,$newSession); dsn7(''===get_user_meta(5,$key,true),'owning current session can still discard its personal recovery checkpoint');


// A current recoverable checkpoint still requires its owning browser session to discard it.
$wpdb->template['current_revision_id']=11; $wpdb->revision=array('id'=>11,'template_id'=>7,'revision_number'=>3,'parent_revision_id'=>10,'layout_schema'=>LayoutSchema::NAME,'layout_schema_version'=>3,'layout_json'=>$layoutJson,'layout_sha256'=>$sha,'source_type'=>'manual','created_by_user_id'=>5,'created_at'=>'2026-08-27 00:00:00');
$service->save(7,5,11,$sha,$session,$changed,$state);
$missingSession=$service->discard(7,5,''); dsn7(is_wp_error($missingSession)&&'certloom_designer_autosave_session_required'===$missingSession->get_error_code()&&''!==get_user_meta(5,$key,true),'ready recovery cannot be discarded without the owning session');

// Large/escape-heavy obsolete records must never fail cleanup merely because a
// diagnostic copy would expand when JSON-encoded a second time.
$largeRecord=json_decode(get_user_meta(5,$key,true),true); $largeRecord['diagnostic_padding']=str_repeat('"',700000); update_user_meta(5,$key,wp_slash(json_encode($largeRecord)));
$wpdb->template['current_revision_id']=12; $wpdb->revision=array('id'=>12,'template_id'=>7,'revision_number'=>4,'parent_revision_id'=>11,'layout_schema'=>LayoutSchema::NAME,'layout_schema_version'=>3,'layout_json'=>$newJson,'layout_sha256'=>$newSha,'source_type'=>'manual','created_by_user_id'=>9,'created_at'=>'2026-08-27 00:01:00');
$largePrepared=$service->prepare_for_editor(7,5); dsn7('none'===($largePrepared['status']??'')&&''===get_user_meta(5,$key,true),'escape-heavy stale recovery is cleared without a secondary JSON size failure');
$largeRetired=json_decode(get_user_meta(5,TemplateDesignerAutosave::retired_meta_key(7),true),true); dsn7(($largeRetired['checkpoint_bytes']??0)>1000000&&64===strlen((string)($largeRetired['checkpoint_sha256']??'')),'large stale recovery leaves only a bounded hash/size diagnostic receipt');

// Server-side stale cleanup is permitted without a stale browser session token;
// this is distinct from discarding a still-current recoverable checkpoint.
$wpdb->template['current_revision_id']=11; $wpdb->revision=array('id'=>11,'template_id'=>7,'revision_number'=>3,'parent_revision_id'=>10,'layout_schema'=>LayoutSchema::NAME,'layout_schema_version'=>3,'layout_json'=>$layoutJson,'layout_sha256'=>$sha,'source_type'=>'manual','created_by_user_id'=>5,'created_at'=>'2026-08-27 00:00:00');
$service->save(7,5,11,$sha,$session,$changed,$state);
$wpdb->template['current_revision_id']=12; $wpdb->revision=array('id'=>12,'template_id'=>7,'revision_number'=>4,'parent_revision_id'=>11,'layout_schema'=>LayoutSchema::NAME,'layout_schema_version'=>3,'layout_json'=>$newJson,'layout_sha256'=>$newSha,'source_type'=>'manual','created_by_user_id'=>9,'created_at'=>'2026-08-27 00:01:00');
$staleDiscard=$service->discard(7,5,''); dsn7(true===$staleDiscard&&''===get_user_meta(5,$key,true),'stale recovery cleanup does not require an obsolete browser session token');

// Integrity tamper should not recover.
$wpdb->template['current_revision_id']=11; $wpdb->revision=array('id'=>11,'template_id'=>7,'revision_number'=>3,'parent_revision_id'=>10,'layout_schema'=>LayoutSchema::NAME,'layout_schema_version'=>3,'layout_json'=>$layoutJson,'layout_sha256'=>$sha,'source_type'=>'manual','created_by_user_id'=>5,'created_at'=>'2026-08-27 00:00:00');
$service->save(7,5,11,$sha,$session,$changed,$state); $tampered=json_decode(get_user_meta(5,$key,true),true); $tampered['layout_sha256']=str_repeat('0',64); update_user_meta(5,$key,wp_slash(json_encode($tampered)));
dsn7('invalid'===($service->inspect(7,5)['status']??''),'tampered recovery layout/hash fails closed');
$invalidPrepared=$service->prepare_for_editor(7,5); dsn7('none'===($invalidPrepared['status']??'')&&'invalid'===($invalidPrepared['retired_status']??'')&&''===get_user_meta(5,$key,true),'invalid recovery is retired before Studio opens instead of repeating a warning on every reload');

// Pause the first read and let a genuinely separate database-session fixture
// attempt the same empty slot. Both requests used to succeed, losing one tab.
$firstConnection=$wpdb;$secondConnection=clone $wpdb;$secondResult=null;
$GLOBALS['dsn7_on_read']=static function()use($secondConnection,$firstConnection,$service,$sha,$changed,$state,&$secondResult){
 $GLOBALS['wpdb']=$secondConnection;
 try{$secondResult=$service->save(7,5,11,$sha,'22222222-2222-4222-8222-222222222222',$changed,$state);}finally{$GLOBALS['wpdb']=$firstConnection;}
};
$firstResult=$service->save(7,5,11,$sha,$session,$changed,$state);
dsn7(!is_wp_error($firstResult)&&is_wp_error($secondResult)&&'certloom_designer_autosave_busy'===$secondResult->get_error_code(),'overlapping first saves cannot both claim the same empty recovery slot');
$retry=$service->save(7,5,11,$sha,'22222222-2222-4222-8222-222222222222',$changed,$state);
dsn7(is_wp_error($retry)&&'certloom_designer_autosave_session_conflict'===$retry->get_error_code(),'a competing tab retry preserves the first owning session');
$retireReady=$service->retire_active(7,5,'stale');
dsn7(is_wp_error($retireReady)&&'ready'===$service->inspect(7,5)['status'],'retirement rechecks current eligibility and cannot delete recoverable work');
$service->discard(7,5,$session);
$GLOBALS['dsn7_deny_write']=true;
$denied=$service->save(7,5,11,$sha,$session,$changed,$state);
dsn7(is_wp_error($denied)&&'certloom_designer_autosave_store_failed'===$denied->get_error_code()&&empty($GLOBALS['dsn7_locks']),'denied persistence releases all acquired session locks');
$GLOBALS['dsn7_deny_write']=false;$wpdb->release_error=true;
$uncertain=$service->save(7,5,11,$sha,$session,$changed,$state);
dsn7(is_wp_error($uncertain)&&'certloom_designer_autosave_lock_release_failed'===$uncertain->get_error_code(),'uncertain database ownership never returns a successful recovery receipt');
$wpdb->release_error=false;$service->discard(7,5,$session);
dsn7(empty($GLOBALS['dsn7_locks']),'nested recovery cleanup releases every lock acquisition');

$root=dirname(__DIR__); $admin=file_get_contents($root.'/src/Infrastructure/Admin/TemplateAdmin.php'); $repo=file_get_contents($root.'/src/Infrastructure/Repository/TemplateRepository.php'); $js=file_get_contents($root.'/admin/assets/designer.js'); $autosave=file_get_contents($root.'/src/Infrastructure/Admin/TemplateDesignerAutosave.php'); $uninstall=file_get_contents($root.'/uninstall.php');
dsn7has($admin,"wp_ajax_certloom_template_designer_autosave",'authenticated AJAX autosave endpoint is explicitly registered');
dsn7has($admin,"check_ajax_referer( 'certloom_template_designer_autosave_'",'autosave endpoint is nonce bound per Template');
dsn7has($admin,'expected_revision_sha256','Designer and advanced JSON save carry exact revision hash proof');
dsn7has($admin,'designer_autosave_session_id','manual Designer save identifies the owning recovery session before cleanup');
dsn7has($repo,'certloom_template_stale_revision_hash','manual revision save also rejects a stale exact hash review');
dsn7has($autosave,'MAX_BYTES = 2097152','recovery checkpoint storage is explicitly bounded');
dsn7has($autosave,"'base_revision_id'",'recovery record persists its exact concurrency base');
dsn7has($autosave,"'session_id'",'recovery record binds one browser editing session to prevent same-user tab races');
dsn7has($autosave,'prepare_for_editor','Studio preparation retires stale or invalid recovery before rendering');
dsn7has($autosave,'retired_meta_key','obsolete recovery keeps a compact diagnostic receipt outside the active autosave slot');
dsn7has($autosave,"'checkpoint_sha256'",'retired recovery diagnostics use bounded hash/size metadata instead of nesting the full checkpoint');
dsn7(false===strpos($autosave,"'checkpoint_raw'"),'retired recovery no longer duplicates the full checkpoint into diagnostic user meta');
dsn7has($admin,'prepare_for_editor( $id, get_current_user_id() )','Designer render and successful manual save clean obsolete recovery state');
dsn7(false===strpos($autosave,'$wpdb->update')&&false===strpos($autosave,'$wpdb->insert'),'autosave store cannot mutate Template authority tables');
dsn7has($js,'AUTOSAVE_DELAY_MS = 1800','browser autosave is debounced rather than writing on every pointer movement');
dsn7has($js,'certloom_designer_autosave_conflict','browser enters a fail-closed conflict state when another session wins');
dsn7has($js,'data-certloom-designer-recovery-json','browser can recover only a server-vetted matching checkpoint');
dsn7has($js,'discardAutosave( false )','reset/clean state removes obsolete server recovery checkpoint');
dsn7has($js,'var cleanupBody = autosaveNeedsCleanup ? null','stale startup cleanup does not send a newly generated browser session as false ownership proof');
dsn7has($js,"'certloom_designer_autosave_session_conflict' === code",'discard failures enter live-conflict state only for explicit concurrency codes');
dsn7(false===strpos($js,'localStorage')&&false===strpos($js,'sessionStorage'),'browser storage is not used as Designer authority or recovery persistence');
dsn7has($uninstall,'certloom_template_designer_autosave_','uninstall removes per-user Designer recovery checkpoints');
dsn7(!file_exists($root.'/resources/renderer/accepted.json'),'DSN-07 does not manufacture renderer authority');

echo "\n$pass DSN-07 checks, $fail failures\n"; exit($fail?1:0);
