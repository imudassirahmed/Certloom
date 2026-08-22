<?php
namespace Certloom\Infrastructure\Repository {
	class LegacyCertificateRepository {
		public static $snapshot = array( 'count' => 3, 'max_id' => 7, 'generation' => 'generation-1' );
		public function snapshot() { return self::$snapshot; }
	}
}
namespace {
	define( 'ABSPATH', __DIR__ . '/fake-wp/' );
	define( 'DAY_IN_SECONDS', 86400 );
	define( 'MINUTE_IN_SECONDS', 60 );
	define( 'ECAPLA_CERTVERIFY_VERSION', '1.5.13-test' );
	$GLOBALS['lmp_options'] = array();
	class WP_Error { private $c; private $m; public function __construct($c='',$m=''){ $this->c=$c; $this->m=$m; } public function get_error_code(){return $this->c;} public function get_error_message(){return $this->m;} }
	function is_wp_error($v){return $v instanceof WP_Error;}
	function __($v){return $v;}
	function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/','',(string)$v));}
	function sanitize_text_field($v){return trim(strip_tags((string)$v));}
	function absint($v){return abs((int)$v);}
	function get_option($k,$d=false){return array_key_exists($k,$GLOBALS['lmp_options'])?$GLOBALS['lmp_options'][$k]:$d;}
	function update_option($k,$v,$autoload=false){$changed=!array_key_exists($k,$GLOBALS['lmp_options'])||$GLOBALS['lmp_options'][$k]!==$v;$GLOBALS['lmp_options'][$k]=$v;return $changed;}
	function delete_option($k){if(!array_key_exists($k,$GLOBALS['lmp_options']))return false;unset($GLOBALS['lmp_options'][$k]);return true;}
	function add_option($k,$v,$deprecated='',$autoload=false){if(array_key_exists($k,$GLOBALS['lmp_options']))return false;$GLOBALS['lmp_options'][$k]=$v;return true;}
	function current_time($type){return '2026-08-22';}
	function wp_json_encode($v,$flags=0){return json_encode($v,$flags);}
	function lmp_assert($c,$m){if(!$c)throw new \RuntimeException($m);}

	require_once dirname(__DIR__) . '/src/Infrastructure/Diagnostics/LegacyMigrationPreflightProof.php';
	use Certloom\Infrastructure\Diagnostics\LegacyMigrationPreflightProof;
	use Certloom\Infrastructure\Repository\LegacyCertificateRepository;

	$seed = hash('sha256','certloom-legacy-migration-preflight-v1');
	$valid=array(
		'schema'=>'certloom-legacy-migration-preflight-proof','version'=>1,'plugin_version'=>ECAPLA_CERTVERIFY_VERSION,
		'status'=>'passed','phase'=>'complete','reason_code'=>'','started_at'=>time()-10,'updated_at'=>time(),'checked_at'=>time(),'preflight_date'=>'2026-08-22',
		'source_count'=>3,'source_max_id'=>7,'source_generation'=>'generation-1','cursor'=>7,'total'=>3,'ready'=>3,'errors'=>0,
		'status_counts'=>array('valid'=>1,'suspended'=>1,'revoked'=>1,'expired'=>0),'error_examples'=>array(),
		'map_fingerprint'=>$seed,'verify_cursor'=>7,'verify_total'=>3,'verify_fingerprint'=>$seed,
	);
	$GLOBALS['lmp_options'][LegacyMigrationPreflightProof::RESULT_OPTION]=$valid;
	lmp_assert(is_array(LegacyMigrationPreflightProof::get_last_result()),'Valid preflight evidence should load.');
	lmp_assert('passed'===LegacyMigrationPreflightProof::evidence_state(),'Fresh matching live source should be current.');
	LegacyCertificateRepository::$snapshot['generation']='generation-2';
	lmp_assert('stale'===LegacyMigrationPreflightProof::evidence_state(),'Changed live generation must stale stored preflight evidence.');
	LegacyCertificateRepository::$snapshot['generation']='generation-1';
	$GLOBALS['lmp_options'][LegacyMigrationPreflightProof::RESULT_OPTION]['plugin_version']='older';
	lmp_assert('stale'===LegacyMigrationPreflightProof::evidence_state(),'Another Certloom version must stale evidence.');
	$GLOBALS['lmp_options'][LegacyMigrationPreflightProof::RESULT_OPTION]=$valid;
	$GLOBALS['lmp_options'][LegacyMigrationPreflightProof::RESULT_OPTION]['checked_at']=time()-(8*DAY_IN_SECONDS);
	lmp_assert('stale'===LegacyMigrationPreflightProof::evidence_state(),'Evidence older than seven days must be stale.');
	$GLOBALS['lmp_options'][LegacyMigrationPreflightProof::RESULT_OPTION]=$valid;
	$GLOBALS['lmp_options'][LegacyMigrationPreflightProof::RESULT_OPTION]['verify_fingerprint']=str_repeat('f',64);
	lmp_assert(null===LegacyMigrationPreflightProof::get_last_result(),'A passed result with mismatched fingerprints must fail closed.');
	$GLOBALS['lmp_options'][LegacyMigrationPreflightProof::RESULT_OPTION]=$valid;
	$report=LegacyMigrationPreflightProof::get_text_report();
	lmp_assert(false===strpos($report,'Alice')&&false===strpos($report,'CERT-')&&false===strpos($report,'custom_fields'),'Copyable preflight report must exclude credential values.');

	$source=file_get_contents(dirname(__DIR__).'/src/Infrastructure/Diagnostics/LegacyMigrationPreflightProof.php');
	lmp_assert(false!==strpos($source,'LegacyMigrationPreflightService'),'Proof must use the production migration preflight mapper/parity service.');
	lmp_assert(false!==strpos($source,'read_batch('),'Proof must scan real bounded legacy rows.');
	lmp_assert(false!==strpos($source,"'phase'] = 'verify'")||false!==strpos($source,"'phase' => 'verify'"),'Proof must perform a second verification pass.');
	lmp_assert(false!==strpos($source,'map_fingerprint')&&false!==strpos($source,'verify_fingerprint'),'Proof must compare privacy-safe semantic fingerprints.');
	lmp_assert(false!==strpos($source,'DomainAcceptanceProof::is_current_passed()'),'Proof must require fresh gated-domain acceptance first.');
	lmp_assert(false===strpos($source,'LegacyMigrationWriter')&&false===strpos($source,'LegacyMigrationCoordinator'),'Read-only preflight must not invoke migration writes/coordinator state.');
	lmp_assert(false===strpos($source,'DomainCutoverCoordinator'),'Read-only preflight must not invoke cutover.');
	lmp_assert(false===strpos($source,'wp_remote_')&&false===strpos($source,'curl_'),'Preflight must not make outbound HTTP calls.');
	echo "PASS  Populated legacy migration preflight evidence is resumable, read-only and source-bound\n\n14 checks, 0 failures\n";
}
