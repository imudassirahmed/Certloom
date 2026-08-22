<?php
/** Regression tests for the privacy-safe gated Credential Series acceptance proof contract. */
define( 'ABSPATH', __DIR__ . '/fake-wp/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'ECAPLA_CERTVERIFY_VERSION', '1.5.14-test' );
$GLOBALS['dap_options'] = array();

class WP_Error { private $c; private $m; public function __construct($c='',$m=''){ $this->c=$c; $this->m=$m; } public function get_error_code(){return $this->c;} public function get_error_message(){return $this->m;} }
function is_wp_error($v){return $v instanceof WP_Error;}
function __($v){return $v;}
function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/','',(string)$v));}
function sanitize_text_field($v){return trim(strip_tags((string)$v));}
function absint($v){return abs((int)$v);}
function get_option($k,$d=false){return array_key_exists($k,$GLOBALS['dap_options'])?$GLOBALS['dap_options'][$k]:$d;}
function update_option($k,$v,$autoload=false){$changed=!array_key_exists($k,$GLOBALS['dap_options'])||$GLOBALS['dap_options'][$k]!==$v;$GLOBALS['dap_options'][$k]=$v;return $changed;}
function delete_option($k){if(!array_key_exists($k,$GLOBALS['dap_options']))return false;unset($GLOBALS['dap_options'][$k]);return true;}

require_once dirname(__DIR__) . '/src/Infrastructure/Diagnostics/DomainAcceptanceProof.php';
use Certloom\Infrastructure\Diagnostics\DomainAcceptanceProof;
function dap_assert($c,$m){if(!$c)throw new RuntimeException($m);}

$valid=array(
 'schema'=>'certloom-domain-acceptance-proof','version'=>1,'plugin_version'=>ECAPLA_CERTVERIFY_VERSION,
 'overall'=>'passed','checked_at'=>time(),
 'checks'=>array(array('id'=>'domain_schema','status'=>'pass','label'=>'Credential-domain schema','message'=>'Synthetic acceptance passed.')),
);
$GLOBALS['dap_options'][DomainAcceptanceProof::RESULT_OPTION]=$valid;
dap_assert(is_array(DomainAcceptanceProof::get_last_result()),'Valid stored acceptance evidence should load.');
dap_assert('passed'===DomainAcceptanceProof::evidence_state(),'Fresh evidence for this version should be current.');
dap_assert(DomainAcceptanceProof::is_current_passed(),'Current passing acceptance helper should return true.');
$GLOBALS['dap_options'][DomainAcceptanceProof::RESULT_OPTION]['checked_at']=time()-(8*DAY_IN_SECONDS);
dap_assert('stale'===DomainAcceptanceProof::evidence_state(),'Evidence older than seven days must be stale.');
$GLOBALS['dap_options'][DomainAcceptanceProof::RESULT_OPTION]=$valid;
$GLOBALS['dap_options'][DomainAcceptanceProof::RESULT_OPTION]['plugin_version']='older';
dap_assert('stale'===DomainAcceptanceProof::evidence_state(),'Another Certloom version must make proof evidence stale.');
$GLOBALS['dap_options'][DomainAcceptanceProof::RESULT_OPTION]=$valid;
$GLOBALS['dap_options'][DomainAcceptanceProof::RESULT_OPTION]['checked_at']=time()+3600;
dap_assert('stale'===DomainAcceptanceProof::evidence_state(),'Implausibly future-dated evidence must be stale.');
$GLOBALS['dap_options'][DomainAcceptanceProof::RESULT_OPTION]=$valid;
$GLOBALS['dap_options'][DomainAcceptanceProof::RESULT_OPTION]['checks'][0]['status']='fail';
dap_assert(null===DomainAcceptanceProof::get_last_result(),'Inconsistent overall/child check evidence must fail closed.');
$GLOBALS['dap_options'][DomainAcceptanceProof::RESULT_OPTION]=$valid;
$report=DomainAcceptanceProof::get_text_report();
dap_assert(false===strpos($report,'/var/')&&false===strpos($report,'https://')&&false===strpos($report,'wp_certloom'),'Acceptance report must not expose paths, URLs or table names.');

$source=file_get_contents(dirname(__DIR__).'/src/Infrastructure/Diagnostics/DomainAcceptanceProof.php');
dap_assert(false!==strpos($source,'SchemaManager::activate()'),'Proof must exercise the real additive schema manager.');
dap_assert(false!==strpos($source,'new Transaction()'),'Proof must use the real transaction boundary.');
dap_assert(false!==strpos($source,'DomainMaintenanceLock::run_internal'),'Proof must use the domain maintenance boundary.');
dap_assert(false!==strpos($source,'new RecipientRepository()')&&false!==strpos($source,'new CredentialRepository()')&&false!==strpos($source,'new CredentialLifecycleService'),'Proof must exercise real domain repositories and lifecycle services.');
dap_assert(false!==strpos($source,'LegacyMigrationPreflightService'),'Proof must exercise synthetic legacy migration semantics.');
dap_assert(false!==strpos($source,'ROLLBACK_CODE'),'Proof must intentionally roll back synthetic writes.');
dap_assert(false!==strpos($source,'assert_existing_domain_empty'),'Proof must refuse to mix synthetic rows with existing future-domain data.');
dap_assert(false===strpos($source,'DomainCutoverCoordinator'),'Acceptance proof must not call final cutover.');
dap_assert(false===strpos($source,'LegacyMigrationCoordinator'),'Acceptance proof must not run live migration.');
dap_assert(false===strpos($source,'read_batch('),'Acceptance proof must not read individual live legacy certificate rows.');
dap_assert(false===strpos($source,'wp_remote_')&&false===strpos($source,'curl_'),'Acceptance proof must not make outbound HTTP calls.');

echo "PASS  Gated-domain acceptance evidence and architecture fail closed without touching live credential authority\n\n17 checks, 0 failures\n";
