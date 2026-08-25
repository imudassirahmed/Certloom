<?php
if ( ! defined( 'ABSPATH' ) ) { fwrite( STDERR, "Run inside WordPress.\n" ); exit( 1 ); }
require_once __DIR__ . '/domain-acceptance-bundle-lib.php';
function certloom_wp_integration_evidence( $gate_id, $gate_file ) {
	$gate_id=strtolower(preg_replace('/[^a-z0-9_-]/','',(string)$gate_id)); $gate_real=realpath((string)$gate_file); $helper_real=realpath(__FILE__);
	if(''===$gate_id||false===$gate_real||!is_file($gate_real)||!is_readable($gate_real)||is_link($gate_file)||false===$helper_real){throw new RuntimeException('Certloom could not establish the real integration-gate evidence boundary.');}
	$payload=\Certloom\Infrastructure\Diagnostics\RuntimePayloadEvidence::current(); if(!is_array($payload)){throw new RuntimeException('Certloom could not fingerprint exact installed production payload.');}
	$gate_hash=hash_file('sha256',$gate_real); $helper_hash=hash_file('sha256',$helper_real); if(!is_string($gate_hash)||!is_string($helper_hash)){throw new RuntimeException('Certloom could not hash integration-gate source bytes.');}
	$run_id=strtolower(trim((string)getenv('CERTLOOM_DB_GATE_RUN_ID'))); if(1!==preg_match('/^[a-f0-9]{32}$/',$run_id)){try{$run_id=bin2hex(random_bytes(16));}catch(Throwable $e){throw new RuntimeException('Could not create integration-gate run identity.');}}
	$e=array('schema'=>'certloom-wp-integration-gate-evidence','version'=>2,'gate'=>$gate_id,'run_id'=>$run_id,'plugin_version'=>defined('ECAPLA_CERTVERIFY_VERSION')?(string)ECAPLA_CERTVERIFY_VERSION:'','runtime_payload'=>$payload,'environment'=>certloom_db_environment_snapshot(),'gate_sha256'=>strtolower($gate_hash),'helper_sha256'=>strtolower($helper_hash));
	if(''===$e['plugin_version']){throw new RuntimeException('Certloom plugin version is unavailable.');}
	echo "EVIDENCE  certloom-wp-integration-gate-evidence v2\n"; echo 'EVIDENCE  gate: '.$e['gate']."\n"; echo 'EVIDENCE  run ID: '.$e['run_id']."\n"; echo 'EVIDENCE  Certloom version: '.$e['plugin_version']."\n"; echo 'EVIDENCE  Certloom payload SHA-256: '.$payload['sha256']."\n"; echo 'EVIDENCE  WordPress: '.$e['environment']['wp_version']."\n"; echo 'EVIDENCE  PHP: '.$e['environment']['php_version']."\n"; echo 'EVIDENCE  DB server: '.$e['environment']['db_server_info']."\n"; echo 'EVIDENCE  gate SHA-256: '.$e['gate_sha256']."\n"; echo 'EVIDENCE  evidence-helper SHA-256: '.$e['helper_sha256']."\n"; echo 'EVIDENCE_JSON  '.certloom_db_canonical_json($e)."\n"; return $e;
}
