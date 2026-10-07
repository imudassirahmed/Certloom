<?php
/** Browser-only ANL-01 administrator analytics fixture using the production view/CSS. */

if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', __DIR__ . '/' );
class WP_Error { private $message; public function __construct($c,$m){$this->message=$m;} public function get_error_message(){return $this->message;} }
function is_wp_error($v){return $v instanceof WP_Error;}
function __($v,$d=null){return $v;} function esc_html__($v,$d=null){return $v;} function esc_attr__($v,$d=null){return $v;}
function esc_html_e($v,$d=null){echo htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');} function esc_attr_e($v,$d=null){echo htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function esc_html($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');} function esc_attr($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function esc_url($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');} function admin_url($v=''){return 'https://example.test/wp-admin/'.ltrim((string)$v,'/');}
function add_query_arg($args,$url){$sep=strpos($url,'?')===false?'?':'&';return $url.$sep.http_build_query($args);}
function absint($v){return abs((int)$v);} function sanitize_key($v){return preg_replace('/[^a-z0-9_\-]/','',strtolower((string)$v));}
function selected($a,$b,$echo=true){$out=((string)$a===(string)$b)?'selected="selected"':'';if($echo)echo $out;return $out;} function number_format_i18n($v){return number_format((int)$v);}
require dirname(__DIR__,2).'/includes/class-ecapla-certverify-analytics.php';
$period=(int)(getenv('CERTLOOM_ANALYTICS_PERIOD')?:30); $analytics_days=Ecapla_CertVerify_Analytics::normalize_period($period);
$granularity=Ecapla_CertVerify_Analytics::trend_granularity($analytics_days);
$count='monthly'===$granularity?13:('weekly'===$granularity?14:$analytics_days);
$trend=array();for($i=0;$i<$count;$i++){$label='monthly'===$granularity?sprintf('2025-%02d',(($i+8)%12)+1):('weekly'===$granularity?'2026-'.str_pad((string)(5+$i),2,'0',STR_PAD_LEFT).'-03':'2026-08-'.str_pad((string)(max(1,28-$count+$i)),2,'0',STR_PAD_LEFT));$trend[]=array('date'=>$label,'label'=>$label,'success'=>8+($i%5),'failed'=>2,'invalid'=>1,'expired'=>$i%3===0?1:0,'revoked'=>$i%7===0?1:0);}
$analytics_report=array(
 'days'=>$analytics_days,'start'=>'2026-07-29','end'=>'2026-08-27','trend_granularity'=>$granularity,
 'summary'=>array('success'=>218,'failed'=>41,'invalid'=>17,'expired'=>12,'revoked'=>4,'total'=>292),
 'trend'=>$trend,'sources'=>array('manual'=>184,'qr'=>91,'api'=>17),
 'most_verified'=>array(array('credential_id'=>7,'certificate_number'=>'CERT-2026-0007','total'=>38),array('credential_id'=>12,'certificate_number'=>'CERT-2026-0012','total'=>31)),
 'retention_days'=>730,'privacy'=>array('raw_ip_stored'=>false,'user_agent_stored'=>false,'raw_failed_identifier_stored'=>false,'aggregation'=>'daily'),
);
$tokens=file_get_contents(dirname(__DIR__,2).'/assets/css/certloom-tokens.css');$css=file_get_contents(dirname(__DIR__,2).'/admin/assets/admin.css');$foundation=file_get_contents(dirname(__DIR__,2).'/admin/assets/certloom-foundation.css');
ob_start(); include dirname(__DIR__,2).'/admin/views/analytics.php'; $view=ob_get_clean();
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>
html{box-sizing:border-box}*,*:before,*:after{box-sizing:inherit}body{margin:0;background:#f0f0f1;color:#1d2327;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;font-size:13px;line-height:1.5}.wrap{margin:20px}.button{display:inline-flex;align-items:center;justify-content:center;min-height:32px;padding:0 12px;border:1px solid #2271b1;background:#f6f7f7;color:#2271b1}.widefat{width:100%;border-collapse:collapse;background:#fff}.widefat th,.widefat td{padding:9px;border-bottom:1px solid #dcdcde;text-align:left}.certloom-dashboard-hero{display:flex;justify-content:space-between}.certloom-domain-metric{background:#fff;border:1px solid #dcdcde;padding:14px;border-radius:8px}.certloom-domain-metric strong,.certloom-domain-metric span{display:block}.certloom-manual-card{background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:16px}.certloom-eyebrow{font-size:11px;font-weight:700;text-transform:uppercase}.certloom-analytics-bar{transition:height 2s ease}
<?php echo $tokens, "\n", $css, "\n", $foundation; ?></style></head><body><?php echo $view; ?></body></html>
