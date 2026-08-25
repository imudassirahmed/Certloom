<?php
/** Source-only helpers for Certloom's real WordPress/MySQL acceptance ceremony. */

function certloom_db_canonicalize( $value ) {
	if ( is_array( $value ) ) {
		$is_list = array_keys( $value ) === range( 0, count( $value ) - 1 );
		if ( ! $is_list ) { ksort( $value, SORT_STRING ); }
		foreach ( $value as $key => $item ) { $value[ $key ] = certloom_db_canonicalize( $item ); }
	}
	return $value;
}
function certloom_db_canonical_json( $value ) {
	$json = json_encode( certloom_db_canonicalize( $value ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION );
	if ( ! is_string( $json ) ) { throw new RuntimeException( 'Could not encode canonical Certloom database acceptance JSON.' ); }
	return $json;
}
function certloom_db_environment_snapshot() {
	global $wpdb, $wp_version;
	$db_server = ''; $db_version = '';
	if ( is_object( $wpdb ) ) {
		if ( method_exists( $wpdb, 'db_server_info' ) ) { try { $db_server = (string) $wpdb->db_server_info(); } catch ( Throwable $error ) {} }
		if ( method_exists( $wpdb, 'db_version' ) ) { try { $db_version = (string) $wpdb->db_version(); } catch ( Throwable $error ) {} }
	}
	$result = array(
		'wp_version' => isset( $wp_version ) ? trim( (string) $wp_version ) : '',
		'php_version' => PHP_VERSION,
		'wp_environment_type' => function_exists( 'wp_get_environment_type' ) ? strtolower( trim( (string) wp_get_environment_type() ) ) : '',
		'db_server_info' => trim( preg_replace( '/[\x00-\x1F\x7F]+/', ' ', $db_server ) ),
		'db_version' => trim( preg_replace( '/[\x00-\x1F\x7F]+/', ' ', $db_version ) ),
		'multisite' => function_exists( 'is_multisite' ) ? (bool) is_multisite() : false,
	);
	foreach ( array( 'wp_version', 'php_version', 'wp_environment_type', 'db_server_info', 'db_version' ) as $key ) {
		if ( '' === $result[ $key ] ) { throw new RuntimeException( 'Could not establish complete WordPress/database environment evidence: ' . $key ); }
	}
	return $result;
}
function certloom_db_normalize_payload( $value ) {
	if ( ! is_array( $value ) ) { return null; }
	$keys = array_keys( $value ); sort( $keys, SORT_STRING );
	if ( $keys !== array( 'file_count', 'schema', 'sha256', 'total_bytes', 'version' ) ) { return null; }
	if ( 'certloom-runtime-payload-fingerprint' !== (string) $value['schema'] || 1 !== (int) $value['version'] ) { return null; }
	$hash = strtolower( trim( (string) $value['sha256'] ) );
	$count = filter_var( $value['file_count'], FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
	$bytes = filter_var( $value['total_bytes'], FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) );
	if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $hash ) || false === $count || false === $bytes ) { return null; }
	return array( 'schema' => 'certloom-runtime-payload-fingerprint', 'version' => 1, 'sha256' => $hash, 'file_count' => (int) $count, 'total_bytes' => (int) $bytes );
}
function certloom_db_payload_equals( $left, $right ) {
	$left = certloom_db_normalize_payload( $left ); $right = certloom_db_normalize_payload( $right );
	return is_array( $left ) && is_array( $right ) && hash_equals( $left['sha256'], $right['sha256'] ) && $left['file_count'] === $right['file_count'] && $left['total_bytes'] === $right['total_bytes'];
}
function certloom_db_gate_contracts() {
	return array(
		'numbering-policy' => array( 'file' => 'run-numbering-policy.php', 'passes' => array(
			'PASS  real wp_options sequence allocation','PASS  manual collision skip + unique legacy-table inserts','PASS  preview is non-reserving','PASS  raised sequence floor advances and lowering never rewinds','PASS  explicit manual identifier remains independent and immutable','PASS  Random 100-bit and UUID v4 human-number strategies preserve sequential state' ) ),
		'domain-schema' => array( 'file' => 'run-domain-schema.php', 'passes' => array(
			'PASS  schema activation and exact health verification','PASS  recipient/program/issuer/Series/Version persistence','PASS  transaction-backed issue/suspend/reinstate/revoke events','PASS  immutable Program/Batch relationship correction and stale-review rejection','PASS  WordPress privacy export + mutable Recipient anonymization with immutable evidence retained','PASS  direct public-ID verification and read-time Status Service','PASS  legacy registry remained untouched' ) ),
		'domain-premium' => array( 'file' => 'run-domain-premium-gate.php', 'passes' => array(
			'PASS  all next-generation tables use InnoDB','PASS  recipient privacy identity storage is present and private to normalized storage','PASS  critical unique/non-unique indexes match the domain contract','PASS  template identity/revision storage, assignment hierarchy/concurrency and renderer publication gate','PASS  Transaction wrapper performs a real database rollback','PASS  certificate-number ownership and Version lineage constraints','PASS  invalid immutable snapshot is side-effect free','PASS  failed idempotent Version insert releases its number reservation' ) ),
		'domain-row-lock' => array( 'file' => 'run-domain-row-lock-gate.php', 'passes' => array( 'PASS  concurrent FOR UPDATE blocks on the same Credential Series row (' ) ),
	);
}
function certloom_db_parse_transcript_evidence( $text ) {
	if ( ! is_string( $text ) || '' === $text ) { throw new RuntimeException( 'Integration-gate transcript is empty.' ); }
	if ( preg_match( '/^FAIL\s{2}/m', $text ) ) { throw new RuntimeException( 'Integration-gate transcript contains a FAIL record.' ); }
	if ( 1 !== preg_match_all( '/^EVIDENCE_JSON\s{2}(.+)$/m', $text, $matches ) ) { throw new RuntimeException( 'Integration-gate transcript must contain exactly one machine-readable evidence record.' ); }
	$value = json_decode( trim( $matches[1][0] ), true );
	if ( ! is_array( $value ) || JSON_ERROR_NONE !== json_last_error() ) { throw new RuntimeException( 'Integration-gate evidence JSON is invalid.' ); }
	$keys = array_keys( $value ); sort( $keys, SORT_STRING );
	if ( $keys !== array( 'environment','gate','gate_sha256','helper_sha256','plugin_version','run_id','runtime_payload','schema','version' ) ) { throw new RuntimeException( 'Integration-gate evidence fields are not canonical.' ); }
	if ( 'certloom-wp-integration-gate-evidence' !== (string) $value['schema'] || 2 !== (int) $value['version'] ) { throw new RuntimeException( 'Integration-gate evidence schema/version is unsupported.' ); }
	if ( 1 !== preg_match( '/^[a-f0-9]{32}$/', (string) $value['run_id'] ) || '' === trim( (string) $value['plugin_version'] ) || ! is_array( certloom_db_normalize_payload( $value['runtime_payload'] ) ) ) { throw new RuntimeException( 'Integration-gate evidence identity is invalid.' ); }
	foreach ( array( 'gate_sha256','helper_sha256' ) as $key ) { if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', (string) $value[ $key ] ) ) { throw new RuntimeException( 'Integration-gate evidence hash is invalid.' ); } }
	$env_keys = is_array( $value['environment'] ) ? array_keys( $value['environment'] ) : array(); sort( $env_keys, SORT_STRING );
	if ( $env_keys !== array( 'db_server_info','db_version','multisite','php_version','wp_environment_type','wp_version' ) ) { throw new RuntimeException( 'Integration-gate environment evidence is invalid.' ); }
	foreach ( array( 'wp_version','php_version','wp_environment_type','db_server_info','db_version' ) as $key ) { if ( '' === trim( (string) $value['environment'][ $key ] ) ) { throw new RuntimeException( 'Integration-gate environment evidence is incomplete.' ); } }
	$value['runtime_payload'] = certloom_db_normalize_payload( $value['runtime_payload'] ); $value['environment']['multisite'] = (bool) $value['environment']['multisite'];
	return $value;
}
function certloom_db_build_base_gate_index( $gate_dir, $source_dir, $expected_run_id = '' ) {
	$gate_dir = rtrim( (string) $gate_dir, '/\\' ); $source_dir = rtrim( (string) $source_dir, '/\\' );
	if ( ! is_dir( $gate_dir ) || ! is_dir( $source_dir ) || is_link( $gate_dir ) ) { throw new RuntimeException( 'Base-gate index input directories are unavailable or symbolic.' ); }
	$helper = $source_dir . '/gate-evidence.php'; $helper_hash = is_file( $helper ) ? hash_file( 'sha256', $helper ) : false;
	if ( ! is_string( $helper_hash ) ) { throw new RuntimeException( 'Could not hash the shared gate-evidence helper.' ); }
	$index = array( 'schema'=>'certloom-wp-integration-base-gate-index','version'=>1,'run_id'=>'','plugin_version'=>'','runtime_payload'=>null,'environment'=>null,'gates'=>array() );
	foreach ( certloom_db_gate_contracts() as $gate => $contract ) {
		$file = $gate_dir . '/' . $gate . '.log';
		if ( ! is_file( $file ) || ! is_readable( $file ) || is_link( $file ) ) { throw new RuntimeException( 'Required non-symbolic integration transcript is missing: ' . $gate ); }
		$text = file_get_contents( $file ); if ( ! is_string( $text ) || strlen( $text ) > 2*1024*1024 ) { throw new RuntimeException( 'Integration transcript is unreadable or too large: ' . $gate ); }
		$e = certloom_db_parse_transcript_evidence( $text ); if ( $gate !== (string) $e['gate'] ) { throw new RuntimeException( 'Integration transcript gate identity mismatch.' ); }
		$gate_hash = hash_file( 'sha256', $source_dir . '/' . $contract['file'] );
		if ( ! is_string( $gate_hash ) || ! hash_equals( strtolower( $gate_hash ), (string) $e['gate_sha256'] ) || ! hash_equals( strtolower( $helper_hash ), (string) $e['helper_sha256'] ) ) { throw new RuntimeException( 'Integration transcript source provenance mismatch: ' . $gate ); }
		foreach ( $contract['passes'] as $marker ) { if ( false === strpos( $text, $marker ) ) { throw new RuntimeException( 'Integration transcript is missing required PASS evidence: ' . $gate ); } }
		if ( '' === $index['run_id'] ) { $index['run_id']=$e['run_id']; $index['plugin_version']=$e['plugin_version']; $index['runtime_payload']=$e['runtime_payload']; $index['environment']=$e['environment']; }
		elseif ( ! hash_equals( $index['run_id'], (string) $e['run_id'] ) || ! hash_equals( $index['plugin_version'], (string) $e['plugin_version'] ) || ! certloom_db_payload_equals( $index['runtime_payload'], $e['runtime_payload'] ) || certloom_db_canonical_json( $index['environment'] ) !== certloom_db_canonical_json( $e['environment'] ) ) { throw new RuntimeException( 'Integration transcripts do not belong to one exact Certloom/database run.' ); }
		$index['gates'][ $gate ] = array( 'transcript_file'=>$gate.'.log','transcript_sha256'=>hash('sha256',$text),'transcript_bytes'=>strlen($text),'gate_sha256'=>$e['gate_sha256'],'helper_sha256'=>$e['helper_sha256'],'pass_markers'=>count($contract['passes']) );
	}
	if ( '' !== $expected_run_id && ! hash_equals( strtolower( $expected_run_id ), $index['run_id'] ) ) { throw new RuntimeException( 'Base-gate run identifier mismatch.' ); }
	ksort( $index['gates'], SORT_STRING ); return $index;
}
function certloom_db_validate_base_gate_bundle( $gate_dir, $source_dir ) {
	$file = rtrim( (string) $gate_dir, '/\\' ) . '/base-gate-index.json';
	if ( ! is_file( $file ) || ! is_readable( $file ) || is_link( $file ) ) { throw new RuntimeException( 'Base-gate index is missing or symbolic.' ); }
	$stored = json_decode( (string) file_get_contents( $file ), true );
	$rebuilt = certloom_db_build_base_gate_index( $gate_dir, $source_dir, is_array($stored)?(string)($stored['run_id']??''):'' );
	if ( ! is_array( $stored ) || certloom_db_canonical_json( $stored ) !== certloom_db_canonical_json( $rebuilt ) ) { throw new RuntimeException( 'Base-gate index does not match exact transcript bytes.' ); }
	return $rebuilt;
}
function certloom_db_build_final_acceptance( array $base, $plugin_version, array $payload, array $environment, array $stored, $generated_at ) {
	$payload = certloom_db_normalize_payload( $payload );
	if ( ! is_array($payload) || ''===trim((string)$plugin_version) || $generated_at<1 ) { throw new RuntimeException('Current Certloom acceptance identity is incomplete.'); }
	if ( ! hash_equals((string)$base['plugin_version'],(string)$plugin_version) || ! certloom_db_payload_equals($base['runtime_payload'],$payload) || certloom_db_canonical_json($base['environment'])!==certloom_db_canonical_json($environment) ) { throw new RuntimeException('Base gates do not match current Certloom/database environment.'); }
	$required=array('database_proof'=>'certloom-database-proof','domain_acceptance'=>'certloom-domain-acceptance-proof','migration_preflight'=>'certloom-legacy-migration-preflight-proof','staging_migration'=>'certloom-staging-migration-rehearsal','staging_restore'=>'certloom-staging-restore-rehearsal','staging_cutover'=>'certloom-staging-cutover-rehearsal');
	if ( array_keys($stored)!==array_keys($required) ) { throw new RuntimeException('Stored normalized-domain evidence set is incomplete or non-canonical.'); }
	$out=array();
	foreach($required as $name=>$schema){ $item=$stored[$name]; $result=$item['result']??null;
		if(!is_array($item)||'passed'!==(string)($item['state']??'')||!is_array($result)){throw new RuntimeException('Stored normalized-domain evidence is not currently passed: '.$name);}
		if($schema!==(string)($result['schema']??'')||!hash_equals((string)$plugin_version,(string)($result['plugin_version']??''))||!certloom_db_payload_equals($payload,$result['runtime_payload']??null)){throw new RuntimeException('Stored normalized-domain evidence identity mismatch: '.$name);}
		$report = isset($item['report']) && is_string($item['report']) ? trim($item['report']) : '';
		if(''===$report || strlen($report)>65536){throw new RuntimeException('Privacy-safe normalized-domain evidence report is unavailable: '.$name);}
		$out[$name]=array('schema'=>$schema,'version'=>(int)($result['version']??0),'checked_at'=>(int)($result['checked_at']??$result['updated_at']??0),'result_sha256'=>hash('sha256',certloom_db_canonical_json($result)),'report_sha256'=>hash('sha256',$report."\n"),'report'=>$report);
	}
	return array('schema'=>'certloom-normalized-domain-acceptance-bundle','version'=>1,'status'=>'qualified','generated_at'=>(int)$generated_at,'plugin_version'=>(string)$plugin_version,'runtime_payload'=>$payload,'environment'=>$environment,'base_gates'=>$base,'stored_evidence'=>$out);
}
