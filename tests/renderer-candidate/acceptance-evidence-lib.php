<?php
/**
 * Source-only PDF-04 acceptance-evidence builder.
 *
 * Derives RendererAcceptanceContract v7 from a production-candidate bundle and
 * the exact independently published verification evidence. No acceptance hash,
 * renderer identity or mandatory check is copied by an operator by hand.
 */
require_once dirname( __DIR__ ) . '/bootstrap-autoload.php';

use Certloom\Application\Rendering\CanonicalJson;
use Certloom\Application\Rendering\RendererAcceptanceContract;
use Certloom\Application\Rendering\RendererAcceptanceImporter;
use Certloom\Application\Rendering\RendererVerificationReport;
use Certloom\Application\Rendering\RendererVerifierEnvironment;

final class CertloomProductionAcceptanceEvidenceBuilder {
	const BUNDLE_SCHEMA = 'certloom-renderer-acceptance-bundle';
	const BUNDLE_VERSION = 3;
	const VERIFICATION_INDEX_SCHEMA = 'certloom-renderer-verification-index';
	const VERIFICATION_INDEX_VERSION = 1;
	const MAX_JSON_BYTES = 2097152;

	private $root;
	private $canonical;
	private $acceptance;
	private $reports;
	private $environments;
	private $importer;

	public function __construct( $source_root ) {
		$root = realpath( (string) $source_root );
		if ( false === $root || ! is_dir( $root ) || ! is_file( $root . '/certloom.php' ) ) {
			throw new InvalidArgumentException( 'Certloom source root is invalid for renderer acceptance evidence.' );
		}
		$this->root = $root;
		$this->canonical = new CanonicalJson();
		$this->acceptance = new RendererAcceptanceContract();
		$this->reports = new RendererVerificationReport();
		$this->environments = new RendererVerifierEnvironment();
		$this->importer = new RendererAcceptanceImporter( $this->acceptance, $this->reports, $this->environments );
	}

	/** @return array<string,mixed> */
	public function build( $bundle_dir, $report_path, $environment_path, $verification_index_path, $output_path ) {
		$bundle_root = $this->safe_existing_directory( $bundle_dir, 'production bundle' );
		$bundle_path = $this->safe_child_file( $bundle_root, 'bundle.json', 'production bundle manifest' );
		$bundle = $this->decode_json_file( $bundle_path, 'production bundle manifest' );
		$report = $this->reports->normalize( $this->decode_json_file( $report_path, 'renderer verification report' ) );
		$environment = $this->environments->normalize( $this->decode_json_file( $environment_path, 'renderer verifier environment' ) );
		$index = $this->normalize_verification_index( $this->decode_json_file( $verification_index_path, 'renderer verification index' ) );
		$this->assert_bundle_shape( $bundle );

		$plugin_version = $this->plugin_version();
		if ( 'production-candidate' !== $bundle['qualification'] || 'production-candidate' !== $report['qualification'] || true !== $report['qualifies_pdf04'] ) {
			throw new InvalidArgumentException( 'Renderer acceptance evidence can be derived only from a qualifying production-candidate bundle.' );
		}
		if ( $plugin_version !== $bundle['plugin_version'] || $plugin_version !== $environment['plugin_version'] ) {
			throw new InvalidArgumentException( 'Renderer acceptance bundle/environment does not target this exact Certloom source version.' );
		}

		$bundle_hash = hash_file( 'sha256', $bundle_path );
		$report_hash = $this->reports->hash( $report );
		$environment_hash = $this->environments->hash( $environment );
		if ( ! hash_equals( $bundle_hash, $report['bundle_manifest_sha256'] ) || ! hash_equals( $bundle_hash, $index['bundle_manifest_sha256'] ) ) {
			throw new InvalidArgumentException( 'Renderer acceptance inputs do not bind the exact production bundle manifest.' );
		}
		if ( ! hash_equals( $report_hash, $index['verification_report_sha256'] ) || ! hash_equals( $environment_hash, $index['verifier_environment_sha256'] ) || ! hash_equals( $environment_hash, $report['verifier_environment_sha256'] ) ) {
			throw new InvalidArgumentException( 'Renderer acceptance inputs do not bind the exact independent verification report/environment.' );
		}

		$fixture_manifest = $this->normalize_bound_manifest( $bundle_root, $bundle['fixture_manifest'], 'fixture manifest' );
		$render_plan_manifest = $this->normalize_bound_manifest( $bundle_root, $bundle['render_plan_manifest'], 'render-plan manifest' );
		if ( ! hash_equals( $fixture_manifest['sha256'], $report['fixture_manifest_sha256'] ) || ! hash_equals( $render_plan_manifest['sha256'], $report['render_plan_manifest_sha256'] ) ) {
			throw new InvalidArgumentException( 'Renderer acceptance bundle manifests do not match independently verified identities.' );
		}

		$renderers = array();
		$formats = RendererAcceptanceContract::required_formats();
		if ( ! is_array( $bundle['renderers'] ) || array_diff( array_keys( $bundle['renderers'] ), $formats ) || array_diff( $formats, array_keys( $bundle['renderers'] ) ) ) {
			throw new InvalidArgumentException( 'Renderer production bundle must contain exactly PDF, PNG and JPEG renderer identities.' );
		}
		foreach ( $formats as $format ) {
			$row = $bundle['renderers'][ $format ];
			if ( ! is_array( $row ) || ! isset( $row['id'], $row['version'], $row['context_hash'], $row['candidate_manifest']['sha256'] ) ) {
				throw new InvalidArgumentException( 'Renderer production bundle renderer identity is malformed.' );
			}
			$candidate = $this->normalize_bound_manifest( $bundle_root, $row['candidate_manifest'], strtoupper( $format ) . ' candidate manifest' );
			if ( $row['id'] !== $report['renderers'][ $format ]['id'] || $row['version'] !== $report['renderers'][ $format ]['version'] || ! hash_equals( $candidate['sha256'], $report['renderers'][ $format ]['candidate_manifest_sha256'] ) ) {
				throw new InvalidArgumentException( 'Renderer production bundle identity does not match the independent verification report.' );
			}
			$renderers[ $format ] = array(
				'id' => $row['id'],
				'version' => $row['version'],
				'context_hash' => strtolower( trim( (string) $row['context_hash'] ) ),
				'candidate_manifest_sha256' => $candidate['sha256'],
			);
		}

		$fixtures = $this->crosscheck_runs( $bundle, $report );
		$checks = array();
		foreach ( RendererAcceptanceContract::required_checks() as $key ) {
			$checks[ $key ] = true;
		}

		$evidence = array(
			'schema' => RendererAcceptanceContract::SCHEMA,
			'version' => RendererAcceptanceContract::VERSION,
			'qualification' => 'production-candidate',
			'plugin_version' => $plugin_version,
			'fixture_manifest_sha256' => $fixture_manifest['sha256'],
			'render_plan_manifest_sha256' => $render_plan_manifest['sha256'],
			'bundle_manifest_sha256' => $bundle_hash,
			'verification_report_sha256' => $report_hash,
			'verifier_environment_sha256' => $environment_hash,
			'renderers' => $renderers,
			'checks' => $checks,
			'fixtures' => $fixtures,
		);

		$normalized = $this->acceptance->normalize( $evidence );
		$imported = $this->importer->import( $normalized, $report, $environment );
		if ( $normalized !== $imported ) {
			throw new RuntimeException( 'Derived renderer acceptance evidence changed during production import validation.' );
		}
		$this->publish_json_file( $output_path, $normalized );
		return $normalized;
	}

	/** @return array<string,array<string,string>> */
	private function crosscheck_runs( array $bundle, array $report ) {
		if ( ! is_array( $bundle['runs'] ) || array_diff( array_keys( $bundle['runs'] ), array( 'a', 'b' ) ) || array_diff( array( 'a', 'b' ), array_keys( $bundle['runs'] ) ) ) {
			throw new InvalidArgumentException( 'Renderer production bundle must contain deterministic runs a and b.' );
		}
		$fixtures = array();
		foreach ( RendererAcceptanceContract::required_fixtures() as $name ) {
			foreach ( array( 'a', 'b' ) as $run ) {
				if ( ! isset( $bundle['runs'][ $run ]['fixtures'][ $name ] ) || ! is_array( $bundle['runs'][ $run ]['fixtures'][ $name ] ) ) {
					throw new InvalidArgumentException( 'Renderer production bundle is missing a permanent fixture.' );
				}
			}
			$a = $bundle['runs']['a']['fixtures'][ $name ];
			$b = $bundle['runs']['b']['fixtures'][ $name ];
			if ( ! isset( $a['render_plan_sha256'], $b['render_plan_sha256'] ) || ! hash_equals( (string) $a['render_plan_sha256'], (string) $b['render_plan_sha256'] ) || ! hash_equals( (string) $a['render_plan_sha256'], $report['fixtures'][ $name ]['render_plan_sha256'] ) ) {
				throw new InvalidArgumentException( 'Renderer production bundle fixture plan identity does not match independent verification.' );
			}
			$row = array( 'render_plan_sha256' => strtolower( (string) $a['render_plan_sha256'] ) );
			foreach ( RendererAcceptanceContract::required_formats() as $format ) {
				if ( ! isset( $a[ $format ]['sha256'], $b[ $format ]['sha256'] ) || ! hash_equals( (string) $a[ $format ]['sha256'], (string) $b[ $format ]['sha256'] ) || ! hash_equals( (string) $a[ $format ]['sha256'], $report['fixtures'][ $name ][ $format . '_sha256' ] ) ) {
					throw new InvalidArgumentException( 'Renderer production bundle fixture artifact identity does not match independent verification.' );
				}
				$row[ $format . '_sha256' ] = strtolower( (string) $a[ $format ]['sha256'] );
			}
			$fixtures[ $name ] = $row;
		}
		return $fixtures;
	}

	private function assert_bundle_shape( array $bundle ) {
		$keys = array( 'schema', 'version', 'qualification', 'plugin_version', 'fixture_manifest', 'render_plan_manifest', 'raster_dpi', 'renderers', 'runs' );
		if ( array_diff( array_keys( $bundle ), $keys ) || array_diff( $keys, array_keys( $bundle ) ) || self::BUNDLE_SCHEMA !== (string) $bundle['schema'] || self::BUNDLE_VERSION !== $bundle['version'] ) {
			throw new InvalidArgumentException( 'Renderer production bundle schema/version or shape is unsupported.' );
		}
	}

	/** @return array{path:string,sha256:string} */
	private function normalize_bound_manifest( $root, $bound, $label ) {
		if ( ! is_array( $bound ) || array_diff( array_keys( $bound ), array( 'path', 'sha256' ) ) || array_diff( array( 'path', 'sha256' ), array_keys( $bound ) ) ) {
			throw new InvalidArgumentException( $label . ' binding is malformed.' );
		}
		$path = $this->safe_child_file( $root, $bound['path'], $label );
		$hash = strtolower( trim( (string) $bound['sha256'] ) );
		if ( 1 !== preg_match( '/^[0-9a-f]{64}$/', $hash ) || ! hash_equals( $hash, hash_file( 'sha256', $path ) ) ) {
			throw new InvalidArgumentException( $label . ' binding does not match the exact file bytes.' );
		}
		return array( 'path' => $path, 'sha256' => $hash );
	}

	/** @return array<string,string|int> */
	private function normalize_verification_index( array $index ) {
		$keys = array( 'schema', 'version', 'bundle_manifest_sha256', 'verification_report_sha256', 'verifier_environment_sha256' );
		if ( array_diff( array_keys( $index ), $keys ) || array_diff( $keys, array_keys( $index ) ) || self::VERIFICATION_INDEX_SCHEMA !== (string) $index['schema'] || self::VERIFICATION_INDEX_VERSION !== $index['version'] ) {
			throw new InvalidArgumentException( 'Renderer verification index schema/version or shape is unsupported.' );
		}
		$out = array( 'schema' => self::VERIFICATION_INDEX_SCHEMA, 'version' => self::VERIFICATION_INDEX_VERSION );
		foreach ( array( 'bundle_manifest_sha256', 'verification_report_sha256', 'verifier_environment_sha256' ) as $key ) {
			$value = strtolower( trim( (string) $index[ $key ] ) );
			if ( 1 !== preg_match( '/^[0-9a-f]{64}$/', $value ) ) { throw new InvalidArgumentException( 'Renderer verification index contains an invalid SHA-256.' ); }
			$out[ $key ] = $value;
		}
		return $out;
	}

	private function safe_existing_directory( $path, $label ) {
		$this->assert_non_symbolic_path( $path, true, $label );
		$real = realpath( (string) $path );
		if ( false === $real || ! is_dir( $real ) ) { throw new InvalidArgumentException( $label . ' must be an existing directory.' ); }
		return $real;
	}

	private function safe_child_file( $root, $relative, $label ) {
		$relative = str_replace( '\\', '/', trim( (string) $relative ) );
		if ( '' === $relative || '/' === substr( $relative, 0, 1 ) || preg_match( '/(^|\/)\.\.?($|\/)/', $relative ) ) {
			throw new InvalidArgumentException( $label . ' path is not a safe relative bundle path.' );
		}
		$path = $root . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative );
		$this->assert_non_symbolic_path( $path, true, $label );
		$real = realpath( $path );
		$prefix = $root . DIRECTORY_SEPARATOR;
		if ( false === $real || ! is_file( $real ) || 0 !== strpos( $real, $prefix ) ) { throw new InvalidArgumentException( $label . ' must remain inside the production bundle.' ); }
		return $real;
	}

	/** @return array<string,mixed> */
	private function decode_json_file( $path, $label ) {
		$this->assert_non_symbolic_path( $path, true, $label );
		$real = realpath( (string) $path );
		if ( false === $real || ! is_file( $real ) || ! is_readable( $real ) ) { throw new InvalidArgumentException( $label . ' must be a readable regular file.' ); }
		$size = filesize( $real );
		if ( false === $size || $size < 2 || $size > self::MAX_JSON_BYTES ) { throw new InvalidArgumentException( $label . ' size is outside the allowed range.' ); }
		$bytes = file_get_contents( $real );
		$data = is_string( $bytes ) ? json_decode( $bytes, true ) : null;
		if ( ! is_array( $data ) ) { throw new InvalidArgumentException( $label . ' is not valid JSON.' ); }
		return $data;
	}

	private function publish_json_file( $path, array $value ) {
		$path = (string) $path;
		if ( '' === trim( $path ) || false !== strpos( $path, "\0" ) || file_exists( $path ) || is_link( $path ) || preg_match( '/(^|[\\\/])\.\.?(?:[\\\/]|$)/', $path ) ) {
			throw new InvalidArgumentException( 'Renderer acceptance output must be a new, unambiguous non-symbolic file path.' );
		}
		$parent_path = dirname( $path );
		$this->assert_non_symbolic_path( $parent_path, true, 'renderer acceptance output parent' );
		$parent = realpath( $parent_path );
		if ( false === $parent || ! is_dir( $parent ) || ! is_writable( $parent ) ) { throw new InvalidArgumentException( 'Renderer acceptance output parent is invalid or not writable.' ); }
		$source_prefix = $this->root . DIRECTORY_SEPARATOR;
		if ( $parent === $this->root || 0 === strpos( $parent . DIRECTORY_SEPARATOR, $source_prefix ) ) { throw new InvalidArgumentException( 'Renderer acceptance evidence must never be written inside the Certloom source tree.' ); }
		$temp = $parent . DIRECTORY_SEPARATOR . '.certloom-acceptance-' . bin2hex( random_bytes( 12 ) ) . '.json';
		$bytes = $this->canonical->encode( $value ) . "\n";
		$handle = @fopen( $temp, 'x+b' );
		if ( false === $handle ) { throw new RuntimeException( 'Renderer acceptance temporary file could not be created.' ); }
		try {
			@chmod( $temp, 0600 );
			$written = fwrite( $handle, $bytes );
			if ( ! is_int( $written ) || $written !== strlen( $bytes ) || ! fflush( $handle ) ) { throw new RuntimeException( 'Renderer acceptance evidence could not be written completely.' ); }
			fclose( $handle ); $handle = null;
			if ( ! rename( $temp, $path ) ) { throw new RuntimeException( 'Renderer acceptance evidence could not be atomically published.' ); }
			@chmod( $path, 0600 );
		} catch ( Throwable $error ) {
			if ( is_resource( $handle ) ) { fclose( $handle ); }
			if ( file_exists( $temp ) || is_link( $temp ) ) { @unlink( $temp ); }
			throw $error;
		}
	}

	private function assert_non_symbolic_path( $path, $must_exist, $label ) {
		$path = (string) $path;
		if ( '' === trim( $path ) || false !== strpos( $path, "\0" ) ) { throw new InvalidArgumentException( $label . ' path is invalid.' ); }
		$absolute = '/' === substr( $path, 0, 1 );
		$parts = preg_split( '#[\\\\/]#', $path );
		$current = $absolute ? DIRECTORY_SEPARATOR : '';
		foreach ( $parts as $part ) {
			if ( '' === $part ) { continue; }
			if ( '.' === $part || '..' === $part ) { throw new InvalidArgumentException( $label . ' path contains an ambiguous dot segment.' ); }
			$current = ( '' === $current || DIRECTORY_SEPARATOR === $current ) ? $current . $part : $current . DIRECTORY_SEPARATOR . $part;
			if ( is_link( $current ) ) { throw new InvalidArgumentException( $label . ' path contains a symbolic component.' ); }
		}
		if ( $must_exist && ! file_exists( $path ) ) { throw new InvalidArgumentException( $label . ' path does not exist.' ); }
	}

	private function plugin_version() {
		$bytes = file_get_contents( $this->root . '/certloom.php' );
		if ( ! is_string( $bytes ) || 1 !== preg_match( '/^\s*\*\s*Version:\s*([^\s]+)/m', $bytes, $match ) ) { throw new RuntimeException( 'Certloom plugin version could not be derived for renderer acceptance.' ); }
		return trim( $match[1] );
	}
}
