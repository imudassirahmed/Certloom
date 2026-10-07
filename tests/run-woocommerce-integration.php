<?php
/** UX-CORE-05 WooCommerce source contract. */

if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
define( 'ABSPATH', __DIR__ . '/fake-wp/' );
define( 'CERTLOOM_DOMAIN_WRITE_PATHS_READY', false );
define( 'CERTLOOM_DOMAIN_ADMIN_PREVIEW', false );
define( 'CERTLOOM_DOMAIN_DATA_TOOLS_READY', false );
if ( ! class_exists( 'WP_Error' ) ) { class WP_Error { private $c,$m,$d; public function __construct($c='',$m='',$d=null){$this->c=$c;$this->m=$m;$this->d=$d;} public function get_error_code(){return$this->c;} public function get_error_message(){return$this->m;} public function get_error_data(){return$this->d;} } }
$GLOBALS['woo_options']=array();$GLOBALS['woo_actions']=array();$GLOBALS['woo_orders']=array();
function is_wp_error($v){return$v instanceof WP_Error;} function __($v,$d=null){return$v;} function absint($v){return abs((int)$v);} function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/','',(string)$v));} function sanitize_text_field($v){return trim(strip_tags((string)$v));} function current_time($f,$gmt=false){return'timestamp'===$f?1788177600:'2026-08-31 12:00:00';} function wp_json_encode($v,$flags=0){return json_encode($v,$flags);} function get_option($k,$d=false){return array_key_exists($k,$GLOBALS['woo_options'])?$GLOBALS['woo_options'][$k]:$d;} function update_option($k,$v,$autoload=null){$o=get_option($k,null);$GLOBALS['woo_options'][$k]=$v;return$o!==$v;} function delete_option($k){unset($GLOBALS['woo_options'][$k]);return true;} function add_action($h,$c,$p=10,$a=1){$GLOBALS['woo_actions'][]=array($h,$c,$p,$a);} function wc_get_order($id){return $GLOBALS['woo_orders'][(int)$id]??null;}
class WooOrderDate {private $ts;public function __construct($ts){$this->ts=$ts;}public function getTimestamp(){return$this->ts;}}
class WooLineItem {private $product;public function __construct($product){$this->product=$product;}public function get_product_id(){return$this->product;}}
class WooOrderStub {private $user,$items,$date;public function __construct($user,$products){$this->user=$user;$this->items=array_map(fn($p)=>new WooLineItem($p),$products);$this->date=new WooOrderDate(strtotime('2026-08-31T10:30:00Z'));}public function get_user_id(){return$this->user;}public function get_items($type='line_item'){return$this->items;}public function get_date_completed(){return$this->date;}}
require_once __DIR__.'/bootstrap-autoload.php';

use Certloom\Application\Integration\IntegrationEventContext;
use Certloom\Infrastructure\Integration\WooCommerceAdapter;
use Certloom\Infrastructure\Integration\WooCommerceBridge;
use Certloom\Infrastructure\Integration\WooCommerceMappingStore;

$pass=0;$fail=0;function wo($ok,$label){global$pass,$fail;if($ok){$pass++;echo"PASS  $label\n";}else{$fail++;echo"FAIL  $label\n";}} function woerr($v,$c){return is_wp_error($v)&&$c===$v->get_error_code();}

$adapter=new WooCommerceAdapter();
wo('woocommerce'===$adapter->key(),'adapter key is stable WooCommerce machine key');
wo('WooCommerce'===$adapter->label(),'adapter label is customer-facing WooCommerce');
wo(WooCommerceAdapter::is_available(),'WooCommerce adapter detects reviewed wc_get_order boundary');
$event=$adapter->normalize_event(array('order_id'=>101,'product_id'=>55,'user_id'=>17,'completed_at'=>'2026-08-31T10:30:00Z','billing_email'=>'must-not-survive@example.test','billing_first_name'=>'Must Not Survive'));
wo(!is_wp_error($event),'valid authenticated completed-order event normalizes');
wo(17===($event['wp_user_id']??0),'explicit WordPress account identity enters integration context');
wo('product'===($event['source_object']['type']??'')&&'55'===($event['source_object']['id']??''),'only stable WooCommerce product identity is retained');
wo('order:101:product:55:user:17'===($event['source_event_id']??''),'order/product/user facts create stable replay identity');
wo(!isset($event['billing_email'])&&!isset($event['billing_first_name'])&&!isset($event['order_payload']),'checkout identity and arbitrary order payload are not retained');
wo(100.0===(float)($event['certificate_threshold']??0)&&!empty($event['attempts'][0]['completed']),'completed order becomes exact eligibility evidence');
wo(woerr($adapter->normalize_event(array('order_id'=>0,'product_id'=>55,'user_id'=>17)),'certloom_woocommerce_order_invalid'),'missing order fails closed');
wo(woerr($adapter->normalize_event(array('order_id'=>101,'product_id'=>0,'user_id'=>17)),'certloom_woocommerce_product_invalid'),'missing product fails closed');
wo(woerr($adapter->normalize_event(array('order_id'=>101,'product_id'=>55,'user_id'=>0)),'certloom_woocommerce_user_invalid'),'guest/unlinked customer fails closed instead of email matching');
wo(woerr($adapter->normalize_event(array('order_id'=>101,'product_id'=>55,'user_id'=>17,'completed_at'=>'yesterday')),'certloom_woocommerce_completion_time_invalid'),'unsafe completion time fails closed');
$context=IntegrationEventContext::from_array($event);wo(!is_wp_error($context),'WooCommerce adapter output enters generic IntegrationEventContext');
wo(17===$context->wp_user_id()&&0===$context->recipient_id(),'generic context preserves exact WordPress user identity for explicit Recipient resolution');
wo($context->is_eligible()&&'55'===$context->source_object_id(),'WooCommerce context is eligible and product-stable');

$store=new WooCommerceMappingStore();
wo(true===$store->save(array('product_id'=>55,'program_id'=>7,'template_id'=>9,'active'=>true,'expiry_mode'=>'program')),'WooCommerce product mapping persists');
$found=$store->find(55);wo(is_array($found)&&7===($found['program_id']??0)&&9===($found['template_id']??0),'WooCommerce mapping round-trips Program/design');
$resolved=$store->resolve_mapping($context);wo(is_array($resolved)&&'woocommerce'===($resolved['adapter']??'')&&'55'===($resolved['source_object_id']??''),'WooCommerce mapping resolves through generic mapping contract');
wo(woerr($store->save(array('product_id'=>0,'program_id'=>7,'template_id'=>9)),'certloom_woocommerce_mapping_target_invalid'),'mapping requires WooCommerce product');
wo(woerr($store->save(array('product_id'=>56,'program_id'=>0,'template_id'=>9)),'certloom_woocommerce_mapping_target_invalid'),'mapping requires Certloom Program');
wo(true===$store->save(array('product_id'=>56,'program_id'=>7,'batch_id'=>0,'template_id'=>0)),'mapping stores Program context without pinning a certificate design');
wo(woerr($store->save(array('product_id'=>56,'program_id'=>7,'template_id'=>9,'expiry_mode'=>'date','expires_at'=>'bad')),'certloom_woocommerce_mapping_expiry_invalid'),'invalid explicit expiry fails closed');

$GLOBALS['woo_orders'][201]=new WooOrderStub(0,array(55));WooCommerceBridge::order_completed(201);$last=get_option(WooCommerceBridge::LAST_RESULT_OPTION,array());
wo('blocked'===($last['status']??'')&&'certloom_woocommerce_guest_unlinked'===($last['error_code']??''),'guest completed order records a privacy-safe blocked result');
wo(!isset($last['email'])&&!isset($last['name'])&&!isset($last['order_payload']),'operational result stores no checkout identity or raw order payload');
$GLOBALS['woo_orders'][202]=new WooOrderStub(17,array(55));WooCommerceBridge::order_completed(202);$last=get_option(WooCommerceBridge::LAST_RESULT_OPTION,array());
wo('blocked'===($last['status']??'')&&'certloom_woocommerce_authority_closed'===($last['error_code']??''),'mapped authenticated order still fails closed while production write authority is closed');
wo(!WooCommerceBridge::authority_ready(),'WooCommerce cannot manufacture normalized-domain write authority');
wo(true===$store->delete(55)&&null===$store->find(55),'WooCommerce mapping deletes cleanly');

$root=dirname(__DIR__);$bridge=file_get_contents($root.'/src/Infrastructure/Integration/WooCommerceBridge.php');$admin=file_get_contents($root.'/src/Infrastructure/Admin/WooCommerceIntegrationAdmin.php');$view=file_get_contents($root.'/admin/views/integrations.php');$uninstall=file_get_contents($root.'/uninstall.php');$plugin=file_get_contents($root.'/certloom.php').file_get_contents($root.'/src/Plugin.php');
wo(false!==strpos($bridge,"const HOOK               = 'woocommerce_order_status_completed'")&&false!==strpos($bridge,'wc_get_order'),'runtime is bound to completed WooCommerce orders');
wo(false===strpos($bridge,'get_billing_email')&&false===strpos($bridge,'get_billing_first_name')&&false===strpos($bridge,'get_billing_last_name'),'runtime never guesses Recipient ownership from billing identity');
wo(false!==strpos($bridge,'LinkedRecipientResolver')&&false!==strpos($bridge,'IntegrationIssuanceCoordinator'),'WooCommerce uses explicit WP-user Recipient resolver and canonical issuance coordinator');
wo(false!==strpos($admin,'CAP_MANAGE_INTEGRATIONS')&&false!==strpos($admin,'check_admin_referer'),'WooCommerce mapping mutations are capability and nonce protected');
wo(false!==strpos($admin,'WooCommerceCompletionMappingCommand')&&false!==strpos(file_get_contents($root.'/src/Infrastructure/Integration/WooCommerceCompletionMappingCommand.php'),'IntegrationMappingTargetGuard'),'WooCommerce mappings validate Program/Batch field and hierarchy compatibility through the shared target guard');
wo(false!==strpos($view,"'Commerce source'")&&false!==strpos($view,"'WooCommerce product'")&&false!==strpos($view,"'Issue automatically after completed order'"),'WooCommerce has a human source → Program → design configuration flow');
wo(false!==strpos($plugin,'WooCommerceBridge::register()')&&false!==strpos($plugin,'WooCommerceIntegrationAdmin()'),'plugin boot registers WooCommerce runtime and admin boundaries');
wo(false!==strpos($uninstall,"'certloom_woocommerce_mappings'")&&false!==strpos($uninstall,"'certloom_woocommerce_last_result'"),'uninstall removes WooCommerce mappings and operational evidence');
wo(!file_exists($root.'/resources/domain/accepted.json')&&!file_exists($root.'/resources/renderer/accepted.json'),'WooCommerce implementation manufactures no production authority manifests');

echo "\n$pass UX-CORE-05 WooCommerce checks, $fail failures\n";exit($fail?1:0);
