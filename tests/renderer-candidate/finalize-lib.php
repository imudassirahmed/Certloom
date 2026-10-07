<?php
/**
 * Source-only PDF-04 acceptance finalizer.
 *
 * This is release-engineering tooling, never production runtime authority. It
 * cross-links already-created acceptance evidence with the independent verifier
 * report/environment/index, imports the evidence through Certloom's production
 * contracts, then derives the exact release-bound accepted.json candidate.
 */

if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
require_once dirname( __DIR__ ) . '/bootstrap-autoload.php';

use Certloom\Application\Rendering\CanonicalJson;
use Certloom\Application\Rendering\RendererAcceptanceContract;
use Certloom\Application\Rendering\RendererAcceptanceImporter;
use Certloom\Application\Rendering\RendererReleaseManifest;
use Certloom\Application\Rendering\RuntimePayloadFingerprint;
use Certloom\Application\Rendering\RendererVerificationReport;
use Certloom\Application\Rendering\RendererVerifierEnvironment;

final class CertloomProductionAcceptanceFinalizer {
	const INDEX_SCHEMA = 'certloom-renderer-authority-finalization';
	const INDEX_VERSION = 2;
	const VERIFICATION_INDEX_SCHEMA = 'certloom-renderer-verification-index';
	const VERIFICATION_INDEX_VERSION = 1;
	const MAX_JSON_BYTES = 2097152;

	private $root;
	private $canonical;
	private $acceptance;
	private $reports;
	private $environments;
	private $importer;
	private $release_manifest;
	private $runtime_payloads;

	public function __construct( $source_root ) {
		$root = realpath( (string) $source_root );
		if ( false === $root || ! is_dir( $root ) || ! is_file( $root . '/certloom.php' ) ) {
			throw new InvalidArgumentException( 'Certloom source root is invalid for renderer acceptance finalization.' );
		}
		$this->root = $root;
		$this->canonical = new CanonicalJson();
		$this->acceptance = new RendererAcceptanceContract();
		$this->reports = new RendererVerificationReport();
		$this->environments = new RendererVerifierEnvironment();
		$this->importer = new RendererAcceptanceImporter( $this->acceptance, $this->reports, $this->environments );
		$this->release_manifest = new RendererReleaseManifest();
		$this->runtime_payloads = new RuntimePayloadFingerprint();
	}

	/** @return array<string,mixed> */
	public function finalize( $evidence_path, $report_path, $environment_path, $verification_index_path, $output_dir ) {
		$evidence = $this->decode_json_file( $evidence_path, 'renderer acceptance evidence' );
		$report = $this->decode_json_file( $report_path, 'renderer verification report' );
		$environment = $this->decode_json_file( $environment_path, 'renderer verifier environment' );
		$verification_index = $this->normalize_verification_index( $this->decode_json_file( $verification_index_path, 'renderer verification index' ) );

		$plugin_version = $this->plugin_version();
		$normalized_report = $this->reports->normalize( $report );
		$normalized_environment = $this->environments->normalize( $environment );
		$report_hash = $this->reports->hash( $normalized_report );
		$environment_hash = $this->environments->hash( $normalized_environment );

		if ( ! hash_equals( $verification_index['verification_report_sha256'], $report_hash ) || ! hash_equals( $verification_index['verifier_environment_sha256'], $environment_hash ) ) {
			throw new InvalidArgumentException( 'Renderer verification index does not bind the exact report/environment supplied for finalization.' );
		}
		if ( $normalized_environment['plugin_version'] !== $plugin_version ) {
			throw new InvalidArgumentException( 'Renderer verifier environment does not target this exact Certloom source version.' );
		}

		$accepted = $this->importer->import( $evidence, $report, $environment );
		if ( $accepted['plugin_version'] !== $plugin_version ) {
			throw new InvalidArgumentException( 'Renderer acceptance evidence does not target this exact Certloom source version.' );
		}
		if ( ! hash_equals( $verification_index['bundle_manifest_sha256'], $accepted['bundle_manifest_sha256'] ) ) {
			throw new InvalidArgumentException( 'Renderer verification index does not bind the accepted production bundle.' );
		}

		$runtime_payload = $this->runtime_payloads->fingerprint( $this->root );
		$manifest = $this->release_manifest->from_acceptance( $accepted, $runtime_payload );
		if ( $manifest['plugin_version'] !== $plugin_version ) {
			throw new RuntimeException( 'Derived renderer release manifest version drifted during finalization.' );
		}

		$accepted_hash = $this->acceptance->hash( $accepted );
		$release_hash = $this->release_manifest->hash( $manifest );
		$index = array(
			'schema' => self::INDEX_SCHEMA,
			'version' => self::INDEX_VERSION,
			'plugin_version' => $plugin_version,
			'runtime_payload' => $runtime_payload,
			'bundle_manifest_sha256' => $accepted['bundle_manifest_sha256'],
			'acceptance_sha256' => $accepted_hash,
			'verification_report_sha256' => $report_hash,
			'verifier_environment_sha256' => $environment_hash,
			'fixture_manifest_sha256' => $accepted['fixture_manifest_sha256'],
			'render_plan_manifest_sha256' => $accepted['render_plan_manifest_sha256'],
			'release_manifest_sha256' => $release_hash,
		);

		$output = $this->publish_staging_directory(
			$output_dir,
			array(
				'acceptance-evidence.json' => $accepted,
				'verification-report.json' => $normalized_report,
				'verifier-environment.json' => $normalized_environment,
				'verification-index.json' => $verification_index,
				'accepted.json' => $manifest,
				'authority-index.json' => $index,
			)
		);

		return array(
			'output_dir' => $output,
			'acceptance' => $accepted,
			'acceptance_sha256' => $accepted_hash,
			'release_manifest' => $manifest,
			'release_manifest_sha256' => $release_hash,
			'authority_index' => $index,
		);
	}

	/** @return array<string,mixed> */
	private function normalize_verification_index( array $index ) {
		$keys = array( 'schema', 'version', 'bundle_manifest_sha256', 'verification_report_sha256', 'verifier_environment_sha256' );
		if ( array_diff( array_keys( $index ), $keys ) || array_diff( $keys, array_keys( $index ) ) ) {
			throw new InvalidArgumentException( 'Renderer verification index has an invalid shape.' );
		}
		if ( self::VERIFICATION_INDEX_SCHEMA !== (string) $index['schema'] || self::VERIFICATION_INDEX_VERSION !== $index['version'] ) {
			throw new InvalidArgumentException( 'Renderer verification index schema/version is unsupported.' );
		}
		$out = array( 'schema' => self::VERIFICATION_INDEX_SCHEMA, 'version' => self::VERIFICATION_INDEX_VERSION );
		foreach ( array( 'bundle_manifest_sha256', 'verification_report_sha256', 'verifier_environment_sha256' ) as $key ) {
			$value = strtolower( trim( (string) $index[ $key ] ) );
			if ( 1 !== preg_match( '/^[0-9a-f]{64}$/', $value ) ) {
				throw new InvalidArgumentException( 'Renderer verification index contains an invalid SHA-256.' );
			}
			$out[ $key ] = $value;
		}
		return $out;
	}

	/** @return array<string,mixed> */
	private function decode_json_file( $path, $label ) {
		if ( ! is_string( $path ) || '' === trim( $path ) || false !== strpos( $path, "\0" ) ) {
			throw new InvalidArgumentException( $label . ' path is invalid or symbolic.' );
		}
		$this->assert_non_symbolic_path_components( $path, $label );
		$real = realpath( $path );
		if ( false === $real || ! is_file( $real ) || ! is_readable( $real ) ) {
			throw new InvalidArgumentException( $label . ' must be a readable regular file.' );
		}
		$size = filesize( $real );
		if ( false === $size || $size < 2 || $size > self::MAX_JSON_BYTES ) {
			throw new InvalidArgumentException( $label . ' size is outside the allowed range.' );
		}
		$bytes = file_get_contents( $real );
		$data = is_string( $bytes ) ? json_decode( $bytes, true ) : null;
		if ( ! is_array( $data ) ) {
			throw new InvalidArgumentException( $label . ' is not valid JSON.' );
		}
		return $data;
	}


	private function assert_non_symbolic_path_components( $path, $label ) {
		$path = (string) $path;
		$absolute = '/' === substr( $path, 0, 1 );
		$parts = preg_split( '#[\\/]#', $path );
		$current = $absolute ? DIRECTORY_SEPARATOR : '';
		foreach ( $parts as $part ) {
			if ( '' === $part ) { continue; }
			if ( '.' === $part || '..' === $part ) { throw new InvalidArgumentException( $label . ' path contains an ambiguous dot segment.' ); }
			$current = ( '' === $current || DIRECTORY_SEPARATOR === $current ) ? $current . $part : $current . DIRECTORY_SEPARATOR . $part;
			if ( is_link( $current ) ) { throw new InvalidArgumentException( $label . ' path contains a symbolic component.' ); }
		}
	}

	private function publish_staging_directory( $path, array $files ) {
		$path = rtrim( (string) $path, '/\\' );
		if ( '' === $path || false !== strpos( $path, "\0" ) || is_link( $path ) || file_exists( $path ) ) {
			throw new InvalidArgumentException( 'Renderer authority output directory must be a new, non-symbolic path.' );
		}
		$this->assert_non_symbolic_path_components( dirname( $path ), 'renderer authority output parent' );
		$parent = realpath( dirname( $path ) );
		if ( false === $parent || ! is_dir( $parent ) || ! is_writable( $parent ) ) {
			throw new InvalidArgumentException( 'Renderer authority output parent directory is invalid or not writable.' );
		}
		$source_prefix = $this->root . DIRECTORY_SEPARATOR;
		if ( $parent === $this->root || 0 === strpos( $parent . DIRECTORY_SEPARATOR, $source_prefix ) ) {
			throw new InvalidArgumentException( 'Renderer authority finalization must never write inside the Certloom source tree.' );
		}

		$temp = $parent . DIRECTORY_SEPARATOR . '.certloom-authority-' . bin2hex( random_bytes( 12 ) );
		if ( ! mkdir( $temp, 0700 ) ) {
			throw new RuntimeException( 'Renderer authority temporary staging directory could not be created.' );
		}
		@chmod( $temp, 0700 );
		try {
			foreach ( $files as $name => $value ) {
				if ( 1 !== preg_match( '/^[a-z0-9][a-z0-9.-]*\\.json$/', (string) $name ) || ! is_array( $value ) ) {
					throw new InvalidArgumentException( 'Renderer authority staging file set is invalid.' );
				}
				$this->write_json( $temp . DIRECTORY_SEPARATOR . $name, $value );
			}
			if ( ! rename( $temp, $path ) ) {
				throw new RuntimeException( 'Renderer authority staging directory could not be published atomically.' );
			}
		} catch ( Throwable $e ) {
			if ( is_dir( $temp ) ) {
				foreach ( glob( $temp . DIRECTORY_SEPARATOR . '*' ) ?: array() as $entry ) {
					if ( is_file( $entry ) || is_link( $entry ) ) { @unlink( $entry ); }
				}
				@rmdir( $temp );
			}
			throw $e;
		}
		$real = realpath( $path );
		if ( false === $real ) {
			throw new RuntimeException( 'Published renderer authority staging directory could not be resolved.' );
		}
		return $real;
	}

	private function write_json( $path, array $value ) {
		$bytes = $this->canonical->encode( $value ) . "\n";
		$handle = @fopen( $path, 'xb' );
		if ( false === $handle ) {
			throw new RuntimeException( 'Renderer authority output could not be created exclusively.' );
		}
		$written = 0;
		try {
			if ( ! flock( $handle, LOCK_EX ) ) {
				throw new RuntimeException( 'Renderer authority output could not be locked.' );
			}
			while ( $written < strlen( $bytes ) ) {
				$n = fwrite( $handle, substr( $bytes, $written ) );
				if ( false === $n || 0 === $n ) {
					throw new RuntimeException( 'Renderer authority output could not be written completely.' );
				}
				$written += $n;
			}
			fflush( $handle );
		} finally {
			fclose( $handle );
		}
		@chmod( $path, 0600 );
	}

	private function plugin_version() {
		$bytes = file_get_contents( $this->root . '/certloom.php' );
		if ( ! is_string( $bytes ) || 1 !== preg_match( '/^\s*\*\s*Version:\s*([^\s]+)/m', $bytes, $match ) ) {
			throw new RuntimeException( 'Certloom plugin version is unavailable.' );
		}
		$version = trim( $match[1] );
		if ( 1 !== preg_match( '/^[0-9]+\.[0-9]+\.[0-9]+(?:[-+][A-Za-z0-9.-]+)?$/', $version ) ) {
			throw new RuntimeException( 'Certloom plugin version is invalid.' );
		}
		return $version;
	}
}
