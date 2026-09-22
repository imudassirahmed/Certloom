<?php
namespace Certloom\Application\Migration;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
use Certloom\Application\Rendering\CanonicalJson;
use Certloom\Application\Rendering\RuntimePayloadFingerprint;
use InvalidArgumentException;

/**
 * Strict source/runtime-neutral importer for genuine normalized-domain v5
 * acceptance evidence. It validates the externally produced acceptance bundle
 * plus its companion index, but never grants authority by itself.
 */
final class DomainAcceptanceImporter {
	const BUNDLE_SCHEMA = 'certloom-normalized-domain-acceptance-bundle';
	const BUNDLE_VERSION = 5;
	const INDEX_SCHEMA = 'certloom-normalized-domain-acceptance-index';
	const INDEX_VERSION = 5;
	const MAX_REPORT_BYTES = 65536;
	const MAX_ENTITY_TYPES = 64;
	const MAX_ENTITY_RECORDS = 1000000000;

	private $canonical;
	private $payloads;

	public function __construct( ?CanonicalJson $canonical = null, ?RuntimePayloadFingerprint $payloads = null ) {
		$this->canonical = $canonical ?: new CanonicalJson();
		$this->payloads = $payloads ?: new RuntimePayloadFingerprint();
	}

	/** @return array{bundle:array<string,mixed>,index:array<string,mixed>,acceptance_sha256:string,index_sha256:string} */
	public function import( array $bundle, array $index ) {
		$bundle = $this->normalize_bundle( $bundle );
		$index = $this->normalize_index( $index );
		$acceptance_bytes = $this->canonical->encode( $bundle ) . "\n";
		$acceptance_sha = hash( 'sha256', $acceptance_bytes );
		$index_sha = hash( 'sha256', $this->canonical->encode( $index ) . "\n" );
		if ( ! hash_equals( $bundle['plugin_version'], $index['plugin_version'] ) ) {
			throw new InvalidArgumentException( 'Normalized-domain acceptance index targets a different Certloom version.' );
		}
		if ( $bundle['runtime_payload'] !== $index['runtime_payload'] ) {
			throw new InvalidArgumentException( 'Normalized-domain acceptance index targets different Certloom production bytes.' );
		}
		if ( ! hash_equals( $acceptance_sha, $index['acceptance_sha256'] ) || strlen( $acceptance_bytes ) !== $index['acceptance_bytes'] ) {
			throw new InvalidArgumentException( 'Normalized-domain acceptance index does not bind the exact canonical acceptance bytes.' );
		}
		if ( ! hash_equals( $bundle['environment_sha256'], $index['environment_sha256'] ) ) {
			throw new InvalidArgumentException( 'Normalized-domain acceptance index does not bind the exact tested environment.' );
		}
		if ( ! hash_equals( $bundle['domain_state_sha256'], $index['domain_state_sha256'] ) ) {
			throw new InvalidArgumentException( 'Normalized-domain acceptance index does not bind the exact final normalized-domain graph.' );
		}
		return array(
			'bundle' => $bundle,
			'index' => $index,
			'acceptance_sha256' => $acceptance_sha,
			'index_sha256' => $index_sha,
		);
	}

	/** @return array<string,mixed> */
	public function normalize_bundle( array $bundle ) {
		$keys = array( 'schema', 'version', 'status', 'generated_at', 'plugin_version', 'runtime_payload', 'environment', 'environment_sha256', 'domain_state', 'domain_state_sha256', 'base_gates', 'stored_evidence' );
		$this->assert_exact_keys( $bundle, $keys, 'normalized-domain acceptance bundle' );
		if ( self::BUNDLE_SCHEMA !== (string) $bundle['schema'] || ! is_int( $bundle['version'] ) || self::BUNDLE_VERSION !== $bundle['version'] || 'qualified' !== (string) $bundle['status'] ) {
			throw new InvalidArgumentException( 'Normalized-domain acceptance bundle schema/version/status is unsupported.' );
		}
		$generated_at = $this->positive_int( $bundle['generated_at'], 'Normalized-domain acceptance generated_at is invalid.' );
		$plugin_version = $this->plugin_version( $bundle['plugin_version'] );
		$runtime_payload = $this->payloads->normalize( $bundle['runtime_payload'] );
		$environment = $this->normalize_environment( $bundle['environment'] );
		$environment_sha = $this->sha256( $bundle['environment_sha256'], 'Normalized-domain acceptance environment SHA-256 is invalid.' );
		if ( ! hash_equals( $this->canonical->hash( $environment ), $environment_sha ) ) {
			throw new InvalidArgumentException( 'Normalized-domain acceptance environment hash does not match the canonical environment evidence.' );
		}
		$domain_state = $this->normalize_domain_state( $bundle['domain_state'] );
		$domain_state_sha = $this->sha256( $bundle['domain_state_sha256'], 'Normalized-domain acceptance domain-state SHA-256 is invalid.' );
		if ( ! hash_equals( $this->canonical->hash( $domain_state ), $domain_state_sha ) ) {
			throw new InvalidArgumentException( 'Normalized-domain acceptance domain-state hash does not match the canonical final graph evidence.' );
		}
		$base_gates = $this->normalize_base_gates( $bundle['base_gates'], $plugin_version, $runtime_payload, $environment );
		$stored = $this->normalize_stored_evidence( $bundle['stored_evidence'] );
		return array(
			'schema' => self::BUNDLE_SCHEMA,
			'version' => self::BUNDLE_VERSION,
			'status' => 'qualified',
			'generated_at' => $generated_at,
			'plugin_version' => $plugin_version,
			'runtime_payload' => $runtime_payload,
			'environment' => $environment,
			'environment_sha256' => $environment_sha,
			'domain_state' => $domain_state,
			'domain_state_sha256' => $domain_state_sha,
			'base_gates' => $base_gates,
			'stored_evidence' => $stored,
		);
	}

	/** @return array<string,mixed> */
	public function normalize_index( array $index ) {
		$keys = array( 'schema', 'version', 'plugin_version', 'runtime_payload', 'environment_sha256', 'domain_state_sha256', 'acceptance_sha256', 'acceptance_bytes' );
		$this->assert_exact_keys( $index, $keys, 'normalized-domain acceptance index' );
		if ( self::INDEX_SCHEMA !== (string) $index['schema'] || ! is_int( $index['version'] ) || self::INDEX_VERSION !== $index['version'] ) {
			throw new InvalidArgumentException( 'Normalized-domain acceptance index schema/version is unsupported.' );
		}
		$bytes = $this->positive_int( $index['acceptance_bytes'], 'Normalized-domain acceptance byte count is invalid.' );
		if ( $bytes > 16 * 1024 * 1024 ) { throw new InvalidArgumentException( 'Normalized-domain acceptance evidence exceeds the release byte ceiling.' ); }
		return array(
			'schema' => self::INDEX_SCHEMA,
			'version' => self::INDEX_VERSION,
			'plugin_version' => $this->plugin_version( $index['plugin_version'] ),
			'runtime_payload' => $this->payloads->normalize( $index['runtime_payload'] ),
			'environment_sha256' => $this->sha256( $index['environment_sha256'], 'Normalized-domain acceptance index environment hash is invalid.' ),
			'domain_state_sha256' => $this->sha256( $index['domain_state_sha256'], 'Normalized-domain acceptance index graph hash is invalid.' ),
			'acceptance_sha256' => $this->sha256( $index['acceptance_sha256'], 'Normalized-domain acceptance index acceptance hash is invalid.' ),
			'acceptance_bytes' => $bytes,
		);
	}

	/** @return array<string,mixed> */
	private function normalize_environment( $value ) {
		if ( ! is_array( $value ) ) { throw new InvalidArgumentException( 'Normalized-domain acceptance environment is required.' ); }
		$keys = array( 'wp_version', 'php_version', 'wp_environment_type', 'db_server_info', 'db_version', 'database_instance_sha256', 'multisite' );
		$this->assert_exact_keys( $value, $keys, 'normalized-domain acceptance environment' );
		$out = array();
		foreach ( array( 'wp_version', 'php_version', 'wp_environment_type', 'db_server_info', 'db_version' ) as $key ) {
			$text = trim( (string) $value[ $key ] );
			if ( '' === $text || strlen( $text ) > 1024 || preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $text ) ) { throw new InvalidArgumentException( 'Normalized-domain acceptance environment field is invalid: ' . $key ); }
			$out[ $key ] = $text;
		}
		$out['database_instance_sha256'] = $this->sha256( $value['database_instance_sha256'], 'Normalized-domain database-instance identity is invalid.' );
		if ( ! is_bool( $value['multisite'] ) ) { throw new InvalidArgumentException( 'Normalized-domain acceptance multisite evidence must be boolean.' ); }
		$out['multisite'] = $value['multisite'];
		return $out;
	}

	/** @return array<string,mixed> */
	private function normalize_domain_state( $value ) {
		if ( ! is_array( $value ) ) { throw new InvalidArgumentException( 'Normalized-domain final graph evidence is required.' ); }
		$keys = array( 'hash', 'configuration_hash', 'records', 'entity_counts', 'generation_sha256' );
		$this->assert_exact_keys( $value, $keys, 'normalized-domain final graph evidence' );
		$records = $this->positive_int( $value['records'], 'Normalized-domain final graph record count is invalid.' );
		if ( $records > self::MAX_ENTITY_RECORDS ) { throw new InvalidArgumentException( 'Normalized-domain final graph record count exceeds the release ceiling.' ); }
		if ( ! is_array( $value['entity_counts'] ) || $this->is_list( $value['entity_counts'] ) || count( $value['entity_counts'] ) < 1 || count( $value['entity_counts'] ) > self::MAX_ENTITY_TYPES ) {
			throw new InvalidArgumentException( 'Normalized-domain final graph entity counts are invalid.' );
		}
		$counts = array();
		foreach ( $value['entity_counts'] as $name => $count ) {
			$name = strtolower( trim( (string) $name ) );
			if ( 1 !== preg_match( '/^[a-z][a-z0-9_]{0,63}$/', $name ) ) { throw new InvalidArgumentException( 'Normalized-domain final graph entity name is invalid.' ); }
			$count = filter_var( $count, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 0, 'max_range' => self::MAX_ENTITY_RECORDS ) ) );
			if ( false === $count ) { throw new InvalidArgumentException( 'Normalized-domain final graph entity count is invalid.' ); }
			$counts[ $name ] = (int) $count;
		}
		ksort( $counts, SORT_STRING );
		if ( array_sum( $counts ) !== $records ) { throw new InvalidArgumentException( 'Normalized-domain final graph entity counts do not equal the total record count.' ); }
		return array(
			'hash' => $this->sha256( $value['hash'], 'Normalized-domain final graph hash is invalid.' ),
			'configuration_hash' => $this->sha256( $value['configuration_hash'], 'Normalized-domain final graph configuration hash is invalid.' ),
			'records' => $records,
			'entity_counts' => $counts,
			'generation_sha256' => $this->sha256( $value['generation_sha256'], 'Normalized-domain final graph generation hash is invalid.' ),
		);
	}

	/** @return array<string,mixed> */
	private function normalize_base_gates( $value, $plugin_version, array $payload, array $environment ) {
		if ( ! is_array( $value ) ) { throw new InvalidArgumentException( 'Normalized-domain base-gate evidence is required.' ); }
		$keys = array( 'schema', 'version', 'run_id', 'plugin_version', 'runtime_payload', 'environment', 'environment_sha256', 'gates' );
		$this->assert_exact_keys( $value, $keys, 'normalized-domain base-gate index' );
		if ( 'certloom-wp-integration-base-gate-index' !== (string) $value['schema'] || 2 !== (int) $value['version'] ) { throw new InvalidArgumentException( 'Normalized-domain base-gate index schema/version is unsupported.' ); }
		$run_id = strtolower( trim( (string) $value['run_id'] ) );
		if ( 1 !== preg_match( '/^[a-f0-9]{32}$/', $run_id ) ) { throw new InvalidArgumentException( 'Normalized-domain base-gate run identity is invalid.' ); }
		if ( ! hash_equals( $plugin_version, $this->plugin_version( $value['plugin_version'] ) ) || $payload !== $this->payloads->normalize( $value['runtime_payload'] ) || $environment !== $this->normalize_environment( $value['environment'] ) || ! hash_equals( $this->canonical->hash( $environment ), $this->sha256( $value['environment_sha256'], 'Normalized-domain base-gate environment hash is invalid.' ) ) ) {
			throw new InvalidArgumentException( 'Normalized-domain base-gate identity does not match the final acceptance boundary.' );
		}
		if ( ! is_array( $value['gates'] ) || count( $value['gates'] ) !== 4 || $this->is_list( $value['gates'] ) ) { throw new InvalidArgumentException( 'Normalized-domain base-gate set is incomplete.' ); }
		$gates = array();
		foreach ( $value['gates'] as $name => $gate ) {
			$name = strtolower( trim( (string) $name ) );
			if ( ! in_array( $name, array( 'numbering-policy', 'domain-schema', 'domain-premium', 'domain-row-lock' ), true ) || ! is_array( $gate ) ) { throw new InvalidArgumentException( 'Normalized-domain base-gate identity is invalid.' ); }
			$gates[ $name ] = $this->normalize_scalar_tree( $gate, 0, 64 );
		}
		ksort( $gates, SORT_STRING );
		return array( 'schema' => 'certloom-wp-integration-base-gate-index', 'version' => 2, 'run_id' => $run_id, 'plugin_version' => $plugin_version, 'runtime_payload' => $payload, 'environment' => $environment, 'environment_sha256' => $this->canonical->hash( $environment ), 'gates' => $gates );
	}

	/** @return array<string,mixed> */
	private function normalize_stored_evidence( $value ) {
		if ( ! is_array( $value ) || $this->is_list( $value ) ) { throw new InvalidArgumentException( 'Normalized-domain stored evidence is required.' ); }
		$contracts = array(
			'database_proof' => array( 'certloom-database-proof', 4 ),
			'domain_acceptance' => array( 'certloom-domain-acceptance-proof', 7 ),
			'migration_preflight' => array( 'certloom-legacy-migration-preflight-proof', 4 ),
			'staging_migration' => array( 'certloom-staging-migration-rehearsal', 4 ),
			'staging_restore' => array( 'certloom-staging-restore-rehearsal', 5 ),
			'staging_cutover' => array( 'certloom-staging-cutover-rehearsal', 5 ),
		);
		$actual_names = array_keys( $value );
		$expected_names = array_keys( $contracts );
		sort( $actual_names, SORT_STRING );
		sort( $expected_names, SORT_STRING );
		if ( $actual_names !== $expected_names ) { throw new InvalidArgumentException( 'Normalized-domain stored evidence set is incomplete or invalid.' ); }
		$out = array();
		foreach ( $contracts as $name => $contract ) {
			$item = $value[ $name ];
			if ( ! is_array( $item ) ) { throw new InvalidArgumentException( 'Normalized-domain stored evidence item is invalid: ' . $name ); }
			$keys = array( 'schema', 'version', 'checked_at', 'result_sha256', 'report_sha256', 'report' );
			$this->assert_exact_keys( $item, $keys, 'normalized-domain stored evidence item' );
			if ( $contract[0] !== (string) $item['schema'] || $contract[1] !== (int) $item['version'] ) { throw new InvalidArgumentException( 'Normalized-domain stored evidence contract is stale: ' . $name ); }
			$report = trim( (string) $item['report'] );
			if ( '' === $report || strlen( $report ) > self::MAX_REPORT_BYTES || preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $report ) ) { throw new InvalidArgumentException( 'Normalized-domain privacy-safe report is invalid: ' . $name ); }
			$report_sha = $this->sha256( $item['report_sha256'], 'Normalized-domain report hash is invalid.' );
			if ( ! hash_equals( hash( 'sha256', $report . "\n" ), $report_sha ) ) { throw new InvalidArgumentException( 'Normalized-domain privacy-safe report hash mismatch: ' . $name ); }
			$out[ $name ] = array(
				'schema' => $contract[0],
				'version' => $contract[1],
				'checked_at' => $this->positive_int( $item['checked_at'], 'Normalized-domain evidence timestamp is invalid.' ),
				'result_sha256' => $this->sha256( $item['result_sha256'], 'Normalized-domain stored result hash is invalid.' ),
				'report_sha256' => $report_sha,
				'report' => $report,
			);
		}
		return $out;
	}

	/** @return mixed */
	private function normalize_scalar_tree( $value, $depth, $remaining ) {
		if ( $depth > 8 || $remaining < 0 ) { throw new InvalidArgumentException( 'Normalized-domain base-gate evidence exceeds structural bounds.' ); }
		if ( is_array( $value ) ) {
			if ( count( $value ) > 64 ) { throw new InvalidArgumentException( 'Normalized-domain base-gate evidence exceeds array bounds.' ); }
			$out = array();
			foreach ( $value as $key => $item ) {
				if ( ! is_int( $key ) && ( ! is_string( $key ) || strlen( $key ) > 128 ) ) { throw new InvalidArgumentException( 'Normalized-domain base-gate evidence key is invalid.' ); }
				$out[ $key ] = $this->normalize_scalar_tree( $item, $depth + 1, $remaining - 1 );
			}
			return $out;
		}
		if ( is_string( $value ) ) { if ( strlen( $value ) > 8192 ) { throw new InvalidArgumentException( 'Normalized-domain base-gate evidence string exceeds bounds.' ); } return $value; }
		if ( is_int( $value ) || is_float( $value ) || is_bool( $value ) || null === $value ) { return $value; }
		throw new InvalidArgumentException( 'Normalized-domain base-gate evidence contains an unsupported value.' );
	}

	private function assert_exact_keys( array $value, array $keys, $label ) {
		$actual = array_keys( $value ); sort( $actual, SORT_STRING ); $expected = $keys; sort( $expected, SORT_STRING );
		if ( $actual !== $expected ) { throw new InvalidArgumentException( $label . ' has an invalid property set.' ); }
	}
	private function plugin_version( $value ) { $value = trim( (string) $value ); if ( ! preg_match( '/^[0-9]+\.[0-9]+\.[0-9]+(?:[-+][A-Za-z0-9.-]+)?$/', $value ) || strlen( $value ) > 64 ) { throw new InvalidArgumentException( 'Normalized-domain Certloom version is invalid.' ); } return $value; }
	private function sha256( $value, $message ) { $value = strtolower( trim( (string) $value ) ); if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $value ) ) { throw new InvalidArgumentException( $message ); } return $value; }
	private function positive_int( $value, $message ) { if ( ! is_int( $value ) || $value < 1 ) { throw new InvalidArgumentException( $message ); } return $value; }
	private function is_list( array $value ) { $index = 0; foreach ( $value as $key => $unused ) { if ( $key !== $index++ ) return false; } return true; }
}
