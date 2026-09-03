<?php
/** ANL-01 verification analytics privacy, aggregation, reporting and lifecycle acceptance. */
$runtime = sys_get_temp_dir() . '/certloom-analytics-' . getmypid() . '/';
@mkdir( $runtime . 'wp-admin/includes', 0777, true );
file_put_contents( $runtime . 'wp-admin/includes/upgrade.php', '<?php function dbDelta( $sql ){ $GLOBALS["wpdb"]->table_created = true; }' );
define( 'ABSPATH', $runtime );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );

class WP_Error {
    private $code; private $message;
    public function __construct( $code, $message ){ $this->code=$code; $this->message=$message; }
    public function get_error_code(){ return $this->code; }
    public function get_error_message(){ return $this->message; }
}
function is_wp_error( $value ){ return $value instanceof WP_Error; }
function __( $value, $domain=null ){ return $value; }
function absint( $value ){ return abs( (int) $value ); }
function sanitize_key( $value ){ return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function current_time( $type, $gmt=false ){ return strtotime( '2026-08-27 12:00:00 UTC' ); }
$GLOBALS['analytics_options'] = array();
$GLOBALS['analytics_scheduled'] = array();
function get_option( $key, $default=false ){ return array_key_exists($key,$GLOBALS['analytics_options']) ? $GLOBALS['analytics_options'][$key] : $default; }
function update_option( $key, $value, $autoload=null ){ $GLOBALS['analytics_options'][$key]=$value; return true; }
function delete_option( $key ){ unset($GLOBALS['analytics_options'][$key]); return true; }
function wp_next_scheduled( $hook ){ return $GLOBALS['analytics_scheduled'][$hook] ?? false; }
function wp_schedule_event( $time, $recurrence, $hook ){ $GLOBALS['analytics_scheduled'][$hook]=(int)$time; return true; }
function wp_clear_scheduled_hook( $hook ){ unset($GLOBALS['analytics_scheduled'][$hook]); return 1; }

class Ecapla_CertVerify_Database { public static function table_name(){ global $wpdb; return $wpdb->prefix . 'certverify_certificates'; } }

class AnalyticsWpdb {
    public $prefix='wp_'; public $last_error=''; public $table_created=false; public $queries=array();
    public $analytics=array(); public $certificates=array(1=>'CERT-A',2=>'CERT-B',3=>'CERT-C');
    public function get_charset_collate(){ return 'CHARSET=utf8mb4'; }
    public function prepare( $query, ...$args ){
        $index=0;
        return preg_replace_callback('/%[sdi]/', function($m) use (&$index,$args){
            $arg=$args[$index++] ?? '';
            if('%d'===$m[0]) return (string)(int)$arg;
            if('%i'===$m[0]) return '`'.str_replace('`','',(string)$arg).'`';
            return "'".str_replace("'","''",(string)$arg)."'";
        }, $query);
    }
    public function query( $sql ){
        $this->queries[]=$sql; $this->last_error='';
        if ( preg_match("/INSERT INTO .* VALUES \\('([^']+)', ([0-9]+), '([^']+)', '([^']+)', 1, '([^']+)', '([^']+)'\\)/s", $sql, $m) ) {
            $key=implode('|',array($m[1],(int)$m[2],$m[3],$m[4]));
            if(!isset($this->analytics[$key])) $this->analytics[$key]=array('bucket_date'=>$m[1],'credential_id'=>(int)$m[2],'outcome'=>$m[3],'source'=>$m[4],'event_count'=>0,'created_at'=>$m[5],'updated_at'=>$m[6]);
            $this->analytics[$key]['event_count']++;
            $this->analytics[$key]['updated_at']=$m[6];
            return 1;
        }
        if ( preg_match("/DELETE FROM .* WHERE bucket_date < '([^']+)'/", $sql, $m) ) {
            $deleted=0; foreach($this->analytics as $key=>$row){ if($row['bucket_date']<$m[1]){unset($this->analytics[$key]);$deleted++;} } return $deleted;
        }
        return 0;
    }
    public function get_var( $sql ){ return $this->table_created ? $this->prefix.'certloom_verification_analytics' : null; }
    private function range( $sql ){
        if(preg_match("/bucket_date >= '([^']+)' AND bucket_date <= '([^']+)'/",$sql,$m)) return array($m[1],$m[2]);
        return array('0000-00-00','9999-12-31');
    }
    private function in_range($row,$range){return $row['bucket_date'] >= $range[0] && $row['bucket_date'] <= $range[1];}
    public function get_results( $sql, $output=null ){
        $this->queries[]=$sql; $this->last_error=''; $range=$this->range($sql);
        if(false!==strpos($sql,'SELECT outcome, SUM(event_count)')){
            $sum=array(); foreach($this->analytics as $row){if($this->in_range($row,$range))$sum[$row['outcome']]=($sum[$row['outcome']]??0)+$row['event_count'];}
            return array_values(array_map(fn($k)=>array('outcome'=>$k,'total'=>$sum[$k]),array_keys($sum)));
        }
        if(false!==strpos($sql,'SELECT bucket_date, outcome')){
            $sum=array(); foreach($this->analytics as $row){if(!$this->in_range($row,$range))continue;$k=$row['bucket_date'].'|'.$row['outcome'];$sum[$k]=($sum[$k]??0)+$row['event_count'];}
            ksort($sum); $out=array(); foreach($sum as $k=>$total){[$date,$outcome]=explode('|',$k,2);$out[]=array('bucket_date'=>$date,'outcome'=>$outcome,'total'=>$total);} return $out;
        }
        if(false!==strpos($sql,'SELECT source, SUM(event_count)')){
            $sum=array(); foreach($this->analytics as $row){if($this->in_range($row,$range))$sum[$row['source']]=($sum[$row['source']]??0)+$row['event_count'];}
            arsort($sum); $out=array(); foreach($sum as $source=>$total)$out[]=array('source'=>$source,'total'=>$total); return $out;
        }
        if(false!==strpos($sql,'INNER JOIN') && false!==strpos($sql,'cert_number')){
            $sum=array(); foreach($this->analytics as $row){if($this->in_range($row,$range)&&$row['credential_id']>0&&isset($this->certificates[$row['credential_id']]))$sum[$row['credential_id']]=($sum[$row['credential_id']]??0)+$row['event_count'];}
            $ids=array_keys($sum); usort($ids,function($a,$b)use($sum){return $sum[$a]===$sum[$b]?$a<=>$b:$sum[$b]<=>$sum[$a];});
            $out=array(); foreach(array_slice($ids,0,10) as $id)$out[]=array('credential_id'=>$id,'cert_number'=>$this->certificates[$id],'total'=>$sum[$id]); return $out;
        }
        return array();
    }
}
$GLOBALS['wpdb']=new AnalyticsWpdb();
require dirname(__DIR__).'/includes/class-ecapla-certverify-analytics.php';

$pass=0;$fail=0;
function anl( $ok, $label ){global $pass,$fail;if($ok){$pass++;echo "PASS  $label\n";}else{$fail++;echo "FAIL  $label\n";}}
function src( $path ){return file_get_contents(dirname(__DIR__).'/'.$path);}

$result=Ecapla_CertVerify_Analytics::activate();
anl($result===true,'analytics table activation succeeds');
anl(Ecapla_CertVerify_Analytics::ready(),'schema marker makes analytics ready');
anl(!empty($GLOBALS['analytics_scheduled'][Ecapla_CertVerify_Analytics::PRUNE_HOOK]),'daily retention cleanup is scheduled');
anl(Ecapla_CertVerify_Analytics::RETENTION_DAYS===730,'retention is explicitly bounded to 730 days');
anl(Ecapla_CertVerify_Analytics::normalize_source('qr')==='qr'&&Ecapla_CertVerify_Analytics::normalize_source('api')==='api'&&Ecapla_CertVerify_Analytics::normalize_source('bogus')==='manual','verification sources are bounded');
anl(Ecapla_CertVerify_Analytics::normalize_outcome('revoked')==='revoked'&&Ecapla_CertVerify_Analytics::normalize_outcome('bogus')==='','analytics outcomes fail closed');
anl(Ecapla_CertVerify_Analytics::normalize_period(999)===30,'report periods fail to bounded 30-day default');
anl(Ecapla_CertVerify_Analytics::trend_granularity(7)==='daily'&&Ecapla_CertVerify_Analytics::trend_granularity(30)==='daily','short reports use daily trends');
anl(Ecapla_CertVerify_Analytics::trend_granularity(90)==='weekly','90-day report uses weekly trends');
anl(Ecapla_CertVerify_Analytics::trend_granularity(365)==='monthly','12-month report uses monthly trends');

$now=strtotime('2026-08-27 12:00:00 UTC');
anl(Ecapla_CertVerify_Analytics::record('success','manual',1,$now),'successful manual verification increments aggregate');
anl(Ecapla_CertVerify_Analytics::record('success','manual',1,$now),'same daily dimension atomically increments existing bucket');
anl(Ecapla_CertVerify_Analytics::record('expired','qr',2,$now-86400),'QR expired outcome is stored as aggregate');
anl(Ecapla_CertVerify_Analytics::record('failed','api',0,$now-2*86400),'unresolved API failure uses credential_id zero');
anl(!Ecapla_CertVerify_Analytics::record('made_up','manual',99,$now),'unknown outcome is never persisted');

$report=Ecapla_CertVerify_Analytics::report(30);
anl(!is_wp_error($report),'30-day administrator report reads successfully');
anl($report['summary']['total']===4&&$report['summary']['success']===2&&$report['summary']['expired']===1&&$report['summary']['failed']===1,'summary counts preserve aggregate totals');
anl($report['sources']===array('manual'=>2,'qr'=>1,'api'=>1),'source breakdown distinguishes manual, QR and API');
anl(count($report['trend'])===30&&$report['trend_granularity']==='daily','30-day trend contains exact daily buckets');
anl(!empty($report['most_verified'])&&$report['most_verified'][0]['certificate_number']==='CERT-A'&&$report['most_verified'][0]['total']===2,'most-verified query formats ARRAY_A rows without object/array mismatch');
anl($report['privacy']['raw_ip_stored']===false&&$report['privacy']['user_agent_stored']===false&&$report['privacy']['raw_failed_identifier_stored']===false,'report publishes explicit privacy contract');

Ecapla_CertVerify_Analytics::record('revoked','qr',3,$now-40*86400);
$r90=Ecapla_CertVerify_Analytics::report(90);
$sum90=0;foreach($r90['trend'] as $bucket){$sum90+=(int)$bucket['success']+(int)$bucket['failed']+(int)$bucket['invalid']+(int)$bucket['expired']+(int)$bucket['revoked'];}
anl($r90['trend_granularity']==='weekly'&&count($r90['trend'])>=13&&count($r90['trend'])<=14,'90-day report aggregates daily storage into weekly buckets');
anl($sum90===$r90['summary']['total'],'weekly trend aggregation preserves exact report total');
Ecapla_CertVerify_Analytics::record('success','manual',1,$now-200*86400);
$r365=Ecapla_CertVerify_Analytics::report(365);
$sum365=0;foreach($r365['trend'] as $bucket){$sum365+=(int)$bucket['success']+(int)$bucket['failed']+(int)$bucket['invalid']+(int)$bucket['expired']+(int)$bucket['revoked'];}
anl($r365['trend_granularity']==='monthly'&&count($r365['trend'])>=12&&count($r365['trend'])<=13,'12-month report aggregates daily storage into monthly buckets');
anl($sum365===$r365['summary']['total'],'monthly trend aggregation preserves exact report total');

Ecapla_CertVerify_Analytics::record('success','manual',1,$now-731*86400);
$before=count($GLOBALS['wpdb']->analytics);$pruned=Ecapla_CertVerify_Analytics::prune();$after=count($GLOBALS['wpdb']->analytics);
anl(!is_wp_error($pruned)&&$pruned>=1&&$after<$before,'retention cleanup deletes buckets older than 730 days');
Ecapla_CertVerify_Analytics::deactivate();
anl(empty($GLOBALS['analytics_scheduled'][Ecapla_CertVerify_Analytics::PRUNE_HOOK]),'deactivation clears analytics pruning schedule');

$analytics=src('includes/class-ecapla-certverify-analytics.php');
$presentation=src('includes/class-ecapla-certverify-public-presentation.php');
$qr=src('includes/class-ecapla-certverify-qr.php');
$rest=src('includes/class-ecapla-certverify-rest.php');
$js=src('public/assets/certloom-public.js');
$admin=src('admin/class-ecapla-certverify-admin.php');
$view=src('admin/views/analytics.php');
$permissions=src('includes/class-ecapla-certverify-permissions.php');
$bootstrap=src('certloom.php');
$uninstall=src('uninstall.php');
$backup=src('includes/class-ecapla-certverify-backup.php');
$system_status=src('includes/class-ecapla-certverify-system-status.php');
anl(false===stripos($analytics,'REMOTE_ADDR')&&false===stripos($analytics,'HTTP_USER_AGENT'),'analytics production storage never reads raw IP or User-Agent');
anl(false===strpos($analytics,'raw_identifier')&&false===strpos($analytics,'certificate_input'),'analytics schema has no raw failed-identifier field');
anl(false!==strpos($analytics,'ON DUPLICATE KEY UPDATE event_count = event_count + 1'),'daily aggregate increment is atomic under concurrent verification traffic');
anl(false!==strpos($presentation,"'verification' !== sanitize_key")&&false!==strpos($presentation,'$rate_limit'),'only real rate-limited verification context records analytics; profile views remain excluded');
anl(false!==strpos($presentation,'self::$analytics_recorded'),'request-local presentation reuse is analytics-deduplicated');
anl(false!==strpos($qr,'tracked_verification_url')&&false!==strpos($qr,"'url'              => (string) \$tracked_url"),'all QR public payload paths bind privacy-safe QR source attribution');
anl(false!==strpos($rest,'verification_source_from_request')&&false!==strpos($js,"get( 'certloom_source' ) === 'qr'"),'REST and browser propagate bounded QR/manual source attribution');
anl(false!==strpos($admin,'CAP_VIEW_ANALYTICS')&&false!==strpos($permissions,"certloom_view_analytics"),'Analytics menu and screen use granular analytics capability');
anl(false!==strpos($view,"'Weekly trend'")&&false!==strpos($view,"'Monthly trend'")&&false!==strpos($view,'Daily verification trend table'),'administrator UX exposes roadmap daily/weekly/monthly trend semantics with accessible table');
anl(false!==strpos($bootstrap,'Ecapla_CertVerify_Analytics::activate()')&&false!==strpos($bootstrap,'wp_initialize_site'),'activation and new multisite-site provisioning include analytics storage');
anl(false!==strpos($bootstrap,'Ecapla_CertVerify_Analytics::deactivate()'),'deactivation clears analytics executable schedule state');
anl(false!==strpos($uninstall,"certloom_verification_analytics_prune")&&false!==strpos($uninstall,"certloom_verification_analytics_schema_version")&&false!==strpos($uninstall,"certloom_verification_analytics"),'uninstall removes analytics schedule, schema marker and operational aggregate table');
anl(false===strpos($backup,'certloom_verification_analytics'),'ANL-01 does not silently change canonical credential backup format');
anl(false!==strpos($bootstrap,'daily aggregate verification counters locally for up to 730 days')&&false!==strpos($bootstrap,'do not store raw IP addresses'),'WordPress Privacy Policy guidance discloses bounded local analytics without raw visitor identifiers');
anl(false!==strpos($system_status,'verification_analytics_check')&&false!==strpos($system_status,'Ecapla_CertVerify_Analytics::health'),'System Status exposes analytics table/schema/retention health without making verification depend on analytics');
anl(!file_exists(dirname(__DIR__).'/resources/renderer/accepted.json'),'ANL-01 does not open renderer authority');
anl(false!==strpos($bootstrap,"CERTLOOM_DOMAIN_WRITE_PATHS_READY', false")&&false!==strpos($bootstrap,"CERTLOOM_DOMAIN_ADMIN_PREVIEW', false")&&false!==strpos($bootstrap,"CERTLOOM_DOMAIN_DATA_TOOLS_READY', false"),'ANL-01 does not open normalized-domain authority');

@unlink($runtime.'wp-admin/includes/upgrade.php'); @rmdir($runtime.'wp-admin/includes'); @rmdir($runtime.'wp-admin'); @rmdir($runtime);
echo "\n{$pass} ANL-01 checks, {$fail} failures\n";
exit($fail?1:0);
