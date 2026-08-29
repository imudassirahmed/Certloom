<?php
/** ANL-02 issuance analytics truth, forecasting, privacy and roadmap-boundary acceptance. */
define( 'ABSPATH', __DIR__ . '/webroot/' );
define( 'DAY_IN_SECONDS', 86400 );
class WP_Error { private $c; private $m; public function __construct($c,$m){$this->c=$c;$this->m=$m;} public function get_error_code(){return $this->c;} public function get_error_message(){return $this->m;} }
function is_wp_error($v){return $v instanceof WP_Error;}
function __($v,$d=null){return $v;}
function absint($v){return abs((int)$v);}
function wp_strip_all_tags($v){return strip_tags((string)$v);}
require_once __DIR__ . '/bootstrap-autoload.php';
require_once dirname(__DIR__) . '/src/Application/Contract/IssuanceAnalyticsRepositoryInterface.php';
require_once dirname(__DIR__) . '/src/Application/Analytics/IssuanceAnalyticsService.php';

use Certloom\Application\Contract\IssuanceAnalyticsRepositoryInterface;
use Certloom\Application\Analytics\IssuanceAnalyticsService;

final class FakeIssuanceAnalyticsRepository implements IssuanceAnalyticsRepositoryInterface {
    public $rows=array(); public $forecast=array(); public $error=null; public $scan_calls=0;
    public function scan_issuance_rows($start,$end,$consumer){$this->scan_calls++; if($this->error)return $this->error; foreach($this->rows as $row)call_user_func($consumer,$row); return true;}
    public function expiry_forecast($today,$horizon_days=90){if($this->error)return $this->error; return $this->forecast;}
}

$pass=0;$fail=0;
function ia($ok,$label){global $pass,$fail;if($ok){$pass++;echo "PASS  $label\n";}else{$fail++;echo "FAIL  $label\n";}}
function src($p){return file_get_contents(dirname(__DIR__).'/'.$p);}

$repo=new FakeIssuanceAnalyticsRepository();
$repo->rows=array(
 array('id'=>1,'created_at'=>'2026-08-27 10:00:00','source'=>'manual','program_id'=>10,'program_title'=>'Leadership','issuer_id'=>20,'issuer_name'=>'Ecapla Academy','template_id'=>30,'template_name'=>'Premium A4','batch_id'=>null,'batch_name'=>''),
 array('id'=>2,'created_at'=>'2026-08-27 11:00:00','source'=>'bulk','program_id'=>10,'program_title'=>'Leadership','issuer_id'=>20,'issuer_name'=>'Ecapla Academy','template_id'=>31,'template_name'=>'Modern Letter','batch_id'=>40,'batch_name'=>'August Cohort'),
 array('id'=>4,'created_at'=>'2026-08-25 09:00:00','source'=>'manual','program_id'=>12,'program_title'=>'','issuer_id'=>22,'issuer_name'=>'','template_id'=>32,'template_name'=>'','batch_id'=>41,'batch_name'=>''),
 array('id'=>3,'created_at'=>'2026-08-26 09:00:00','source'=>'other','program_id'=>11,'program_title'=>'Safety','issuer_id'=>21,'issuer_name'=>'Training Org','template_id'=>30,'template_name'=>'Premium A4','batch_id'=>null,'batch_name'=>''),
);
$repo->forecast=array('today'=>'2026-08-27','end'=>'2026-11-25','permanent'=>7,'rows'=>array(
 array('forecast_bucket'=>'0_30','program_id'=>10,'program_title'=>'Leadership','total'=>3),
 array('forecast_bucket'=>'31_60','program_id'=>10,'program_title'=>'Leadership','total'=>2),
 array('forecast_bucket'=>'61_90','program_id'=>11,'program_title'=>'Safety','total'=>4),
));
$svc=new IssuanceAnalyticsService($repo,function(){return strtotime('2026-08-27 12:00:00 UTC');});
$r=$svc->report(30);
ia(!is_wp_error($r),'30-day issuance report succeeds');
ia($r['summary']===array('total'=>4,'manual'=>2,'bulk'=>1,'other'=>1),'summary separates manual, bulk and other normalized issuance');
ia($r['trend_granularity']==='daily'&&count($r['trend'])===30,'30-day report uses exact daily buckets');
ia(array_sum(array_column($r['trend'],'total'))===4,'trend totals preserve exact issuance count');
ia($r['dimensions']['programs'][0]['label']==='Leadership'&&$r['dimensions']['programs'][0]['total']===2,'Programs rank from immutable issuance facts');
ia($r['dimensions']['templates'][0]['label']==='Premium A4'&&$r['dimensions']['templates'][0]['total']===2,'Templates rank from immutable issuance facts');
ia($r['dimensions']['organizations'][0]['label']==='Ecapla Academy'&&$r['dimensions']['organizations'][0]['total']===2,'organizations rank from immutable issuer snapshot');
ia(count($r['dimensions']['batches'])===3,'captured, captured-without-label and unattributed Batch issuance remain separate dimensions');
$batch_labels=array_column($r['dimensions']['batches'],'label');
ia(in_array('Unattributed batch',$batch_labels,true),'missing historical Batch remains visibly unattributed');
ia(in_array('Batch #41',$batch_labels,true),'captured Batch ID without a frozen label stays attributed instead of being mislabeled as unattributed');
ia($r['expiry_forecast']['buckets']===array('0_30'=>3,'31_60'=>2,'61_90'=>4),'expiry forecast preserves 30/60/90-day buckets');
ia($r['expiry_forecast']['total_90']===9,'90-day forecast total is exact');
ia($r['expiry_forecast']['permanent']===7,'current permanent credentials are reported separately');
ia($r['expiry_forecast']['top_programs'][0]['label']==='Leadership'&&$r['expiry_forecast']['top_programs'][0]['total']===5,'expiry forecast ranks Programs by upcoming expiries');
ia($r['provenance']['batch_uncaptured_is_unattributed']===true,'report publishes fail-closed Batch attribution contract');
ia($r['provenance']['visitor_data_stored']===false&&$r['provenance']['analytics_table_written']===false,'ANL-02 is a read-only credential-truth projection without visitor telemetry');
ia(IssuanceAnalyticsService::normalize_period(999)===30,'period input fails to bounded 30-day default');
ia(IssuanceAnalyticsService::trend_granularity(7)==='daily'&&IssuanceAnalyticsService::trend_granularity(30)==='daily','short issuance reports are daily');
ia(IssuanceAnalyticsService::trend_granularity(90)==='weekly','90-day issuance report is weekly');
ia(IssuanceAnalyticsService::trend_granularity(365)==='monthly','12-month issuance report is monthly');
$repo->error=new WP_Error('read_failed','Read failed');
ia(is_wp_error($svc->report(30)),'repository read failures fail the report rather than inventing zeros');

$repository=src('src/Infrastructure/Analytics/IssuanceAnalyticsRepository.php');
$service=src('src/Application/Analytics/IssuanceAnalyticsService.php');
$manual=src('src/Application/Issuance/ManualIssuanceService.php');
$admin=src('admin/class-ecapla-certverify-admin.php');
$view=src('admin/views/issuance-analytics.php');
$verification_view=src('admin/views/analytics.php');
$css=src('admin/assets/admin.css');
$schema=src('src/Infrastructure/Database/SchemaManager.php');
$backup=src('includes/class-ecapla-certverify-backup.php');
$main=src('certloom.php');

ia(false!==strpos($repository,"e.event_type = 'credential.issued'"),'issuance analytics counts append-only credential.issued events');
ia(false!==strpos($repository,'INNER JOIN %i v ON v.id = e.version_id'),'issuance events are bound to the exact immutable Version');
ia(false!==strpos($repository,"JSON_EXTRACT(v.snapshot_json, '$.program.id')")&&false!==strpos($repository,"JSON_EXTRACT(v.snapshot_json, '$.template.id')"),'Program and Template dimensions are projected from immutable Version snapshot');
ia(false!==strpos($repository,"JSON_EXTRACT(v.snapshot_json, '$.issuer.id')"),'organization attribution comes from immutable issuer snapshot');
ia(false!==strpos($repository,"JSON_EXTRACT(e.meta_json, '$.batch_id')"),'Batch attribution comes only from immutable issuance-event metadata');
ia(false===strpos($repository,'s.batch_id') || strpos($repository,'s.batch_id')>strpos($repository,'expiry_forecast'),'historical issuance query never guesses Batch from mutable current Series');
ia(false!==strpos($repository,'CHUNK_SIZE = 500')&&false!==strpos($repository,'e.id > %d'),'issuance fact scan is cursor-bounded rather than one unbounded payload read');
ia(false!==strpos($repository,'json_projection_supported')&&false!==strpos($repository,'scan_issuance_rows_fallback'),'SQL JSON projection has a bounded portability fallback');
ia(false!==strpos($repository,"v.issuance_state = 'issued'")&&false!==strpos($repository,"s.administrative_state <> 'revoked'"),'expiry forecast uses current issued non-revoked credential truth');
ia(false!==strpos($repository,'v.expires_at IS NOT NULL')&&false!==strpos($repository,'v.expires_at IS NULL'),'expiry forecast distinguishes expiring and permanent current credentials');
ia(false!==strpos($repository,'min( 90, absint( $horizon_days ) )'),'expiry forecast repository hard-clamps its public horizon to the ANL-02 90-day contract');
ia(false!==strpos($manual,"'program_id' => absint")&&false!==strpos($manual,"'issuer_id' => absint")&&false!==strpos($manual,"'batch_id' =>"),'new issuance events freeze compact ANL-02 dimension identifiers');
ia(false!==strpos($manual,"'template_name'")&&false!==strpos($manual,"'program_title'")&&false!==strpos($manual,"'issuer_name'"),'new issuance events preserve compact human labels without re-reading mutable entity names later');
ia(false!==strpos($admin,"'issuance' === \$analytics_section")&&false!==strpos($admin,'IssuanceAnalyticsService'),'Analytics controller exposes issuance report as a bounded read-only section');
ia(false!==strpos($view,'Issuance insights')&&false!==strpos($verification_view,'Analytics reports'),'Verification and Issuance analytics share one premium Analytics workspace');
ia(false!==strpos($view,'Programs')&&false!==strpos($view,'Templates')&&false!==strpos($view,'Organizations')&&false!==strpos($view,'Batches'),'ANL-02 UI exposes every roadmap issuance dimension');
ia(false!==strpos($view,'90-day expiry forecast')&&false!==strpos($view,'0–30 days')&&false!==strpos($view,'31–60 days')&&false!==strpos($view,'61–90 days'),'ANL-02 UI exposes bounded expiry forecast');
ia(false!==strpos($view,'renewal reminders and re-certification remain the separate REN-01 workflow'),'expiry forecasting does not falsely claim the separate renewal workflow');
ia(false!==strpos($css,'.certloom-issuance-dimension-grid')&&false!==strpos($css,'@media(max-width:640px)'),'Issuance Analytics has responsive layout');
ia(false!==strpos($css,'@media(prefers-reduced-motion:reduce)')&&false!==strpos($css,'@media(forced-colors:active)'),'Analytics workspace preserves reduced-motion and forced-colors support');
ia(false===stripos($repository,'recipient')&&false===stripos($repository,'email')&&false===stripos($repository,'ip_address'),'ANL-02 repository does not select recipient PII or visitor identifiers');
ia(false===strpos($service,'INSERT ')&&false===strpos($service,'UPDATE ')&&false===strpos($service,'DELETE '),'ANL-02 application service is read-only');
ia(false===strpos($schema,'issuance_analytics'),'ANL-02 adds no duplicate analytics truth table to normalized credential schema');
ia(false===strpos($backup,'issuance_analytics'),'ANL-02 does not silently change canonical backup format');
ia(false!==strpos($main,"CERTLOOM_DOMAIN_WRITE_PATHS_READY', false")&&false!==strpos($main,"CERTLOOM_DOMAIN_ADMIN_PREVIEW', false")&&false!==strpos($main,"CERTLOOM_DOMAIN_DATA_TOOLS_READY', false"),'ANL-02 does not open normalized-domain authority');
ia(!file_exists(dirname(__DIR__).'/resources/renderer/accepted.json'),'ANL-02 does not open renderer authority');
ia(false===strpos($view,'Share clicks')&&false===strpos($view,'Profile views'),'ANL-02 does not absorb ANL-03 engagement analytics');
ia(false===strpos($view,'Export CSV')&&false===strpos($view,'XLSX'),'ANL-02 does not absorb later IMP-03 analytics export');

echo "\n{$pass} ANL-02 checks, {$fail} failures\n";
exit($fail?1:0);
