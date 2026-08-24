<?php
/**
 * Source-only PDF-04 production-candidate bundle producer.
 *
 * IMPORTANT: this file is excluded from the installable plugin. It intentionally
 * provides an evaluation authority gate so an unaccepted candidate can be run
 * against the frozen PDF-04 fixtures. Production runtime authority remains owned
 * exclusively by RendererAuthorityGate + resources/renderer/accepted.json.
 */
require_once dirname( __DIR__ ) . '/bootstrap-autoload.php';
require_once dirname( __DIR__ ) . '/render-plan-reference/reference_lib.php';

use Certloom\Application\Contract\ArtifactAuthorityGateInterface;
use Certloom\Application\Contract\RendererInterface;
use Certloom\Application\Rendering\ArtifactReceipt;
use Certloom\Application\Rendering\CanonicalJson;
use Certloom\Application\Rendering\FontManifest;
use Certloom\Application\Rendering\RendererCandidateManifest;
use Certloom\Application\Rendering\RendererRegistry;
use Certloom\Infrastructure\Rendering\ArtifactRenderCoordinator;
use Certloom\Infrastructure\Rendering\ContentAddressedFontResolver;
use Certloom\Infrastructure\Rendering\RendererCapabilityDetector;

final class CertloomCandidateEvaluationAuthorityGate implements ArtifactAuthorityGateInterface {
	/** @var array<string,array<string,string>> */
	private $expected;

	/** @param array<string,array<string,string>> $expected */
	public function __construct( array $expected ) { $this->expected = $expected; }

	public function artifact_status( $format, RendererRegistry $registry ) {
		$format = strtolower( trim( (string) $format ) );
		if ( ! isset( $this->expected[ $format ] ) ) {
			return array( 'accepted' => false, 'reason' => 'candidate_format_not_configured', 'message' => 'Candidate evaluation does not configure this artifact format.' );
		}
		try {
			$renderer = $registry->for_format( $format );
			$id = strtolower( trim( (string) $renderer->id() ) );
			$version = trim( (string) $renderer->version() );
			$context = (array) $renderer->context();
		} catch ( Throwable $error ) {
			return array( 'accepted' => false, 'reason' => 'candidate_renderer_unavailable', 'message' => $error->getMessage() );
		}
		$expected = $this->expected[ $format ];
		$candidate_hash = strtolower( trim( (string) ( $context['candidate_manifest_sha256'] ?? '' ) ) );
		if ( ! hash_equals( $expected['id'], $id ) || ! hash_equals( $expected['version'], $version ) || ! hash_equals( $expected['candidate_manifest_sha256'], $candidate_hash ) ) {
			return array( 'accepted' => false, 'reason' => 'candidate_identity_mismatch', 'message' => 'Registered renderer does not match the exact candidate manifest under evaluation.' );
		}
		return array( 'accepted' => true, 'reason' => 'artifact_authorized', 'message' => 'Source-only PDF-04 candidate evaluation authorizes this exact candidate for fixture execution.', 'format' => $format );
	}

	public function can_render( $format, RendererRegistry $registry ) {
		$status = $this->artifact_status( $format, $registry );
		return ! empty( $status['accepted'] ) && 'artifact_authorized' === (string) ( $status['reason'] ?? '' );
	}
}

final class CertloomProductionCandidateBundleProducer {
	const BUNDLE_SCHEMA = 'certloom-renderer-acceptance-bundle';
	const BUNDLE_VERSION = 3;
	const MAX_PROVENANCE_BYTES = 104857600; // 100 MiB per source/lock evidence file.
	const PRODUCTION_ACK = 'CERTLOOM_PDF04_PRODUCTION_CANDIDATE';

	private $root;
	private $canonical;
	private $candidate_contract;
	private $font_manifest;

	public function __construct( $source_root ) {
		$root = realpath( (string) $source_root );
		if ( false === $root || ! is_dir( $root ) || ! is_file( $root . '/certloom.php' ) ) {
			throw new InvalidArgumentException( 'Certloom source root is invalid for candidate production.' );
		}
		$this->root = $root;
		$this->canonical = new CanonicalJson();
		$this->candidate_contract = new RendererCandidateManifest();
		$this->font_manifest = new FontManifest();
	}

	/**
	 * @param array<string,mixed> $config Bootstrap result.
	 * @return array<string,mixed> Bundle manifest.
	 */
	public function produce( $output_dir, array $config, $qualification = 'production-candidate' ) {
		if ( ! in_array( $qualification, array( 'production-candidate', 'development-reference' ), true ) ) {
			throw new InvalidArgumentException( 'Candidate producer qualification is unsupported.' );
		}
		if ( 'production-candidate' === $qualification && self::PRODUCTION_ACK !== (string) ( $config['production_candidate_ack'] ?? '' ) ) {
			throw new InvalidArgumentException( 'Production candidate generation requires the explicit source-only PDF-04 acknowledgement.' );
		}
		$this->assert_config_shape( $config );
		$output = $this->prepare_output_dir( $output_dir );
		$setup = certloom_render_plan_reference_setup();
		$cases = certloom_render_plan_reference_cases();
		$plan_manifest_path = $this->root . '/tests/fixtures/rendering/render-plan-manifest.json';
		$golden_manifest_path = $this->root . '/tests/fixtures/rendering/reference/manifest.json';
		$this->copy_exact( $golden_manifest_path, $output . '/manifests/golden.json' );
		$this->copy_exact( $plan_manifest_path, $output . '/manifests/render-plan.json' );
		$plan_manifest = $this->decode_json_file( $plan_manifest_path, 1048576, 'frozen render-plan manifest' );

		$font_root = $this->real_directory( $config['font_root'], 'candidate exact-font root' );
		$font_resolver = new ContentAddressedFontResolver( $font_root );
		// Resolve and inspect the exact frozen fixture face up-front, before any renderer runs.
		$font_resolver->resolve( $setup['font_face_sha256'], 'ttf' );

		list( $registry, $renderer_rows, $authority_expected ) = $this->prepare_renderers( $config['formats'], $output, $setup['font_manifest'] );
		$authority = new CertloomCandidateEvaluationAuthorityGate( $authority_expected );
		$coordinator = new ArtifactRenderCoordinator( $registry, new RendererCapabilityDetector(), null, new ArtifactReceipt(), null, null, $font_resolver, null, null, $authority );

		$runs = array();
		foreach ( array( 'a', 'b' ) as $run ) {
			$runs[ $run ] = array( 'fixtures' => array() );
			foreach ( $cases as $name => $case ) {
				$expected = $plan_manifest['fixtures'][ $name ] ?? null;
				if ( ! is_array( $expected ) || ! hash_equals( (string) $expected['layout_sha256'], (string) $case['layout_sha256'] ) || ! hash_equals( (string) $expected['render_request_sha256'], (string) $case['render_request_sha256'] ) || ! hash_equals( (string) $expected['render_plan_sha256'], (string) $case['render_plan_sha256'] ) ) {
					throw new RuntimeException( 'Candidate fixture preparation drifted from the frozen render-plan manifest: ' . $name );
				}
				$row = array( 'render_plan_sha256' => $case['render_plan_sha256'] );
				foreach ( array( 'pdf', 'png', 'jpeg' ) as $format ) {
					$result = $coordinator->render( $format, $case['layout_sha256'], $case['resolved_document'] );
					if ( ! hash_equals( $case['render_request_sha256'], (string) $result['request_hash'] ) || ! hash_equals( $case['render_plan_sha256'], (string) $result['render_plan_hash'] ) ) {
						throw new RuntimeException( 'Candidate execution did not preserve frozen request/render-plan identity for ' . $name . '/' . $format );
					}
					$artifact_rel = 'artifacts/' . $run . '/' . $name . '.' . $format;
					$artifact_path = $output . '/' . $artifact_rel;
					$this->write_binary( $artifact_path, $result['bytes'] );
					$receipt = null;
					if ( 'production-candidate' === $qualification ) {
						$receipt_rel = 'receipts/' . $run . '/' . $name . '.' . $format . '.json';
						$receipt_path = $output . '/' . $receipt_rel;
						$this->write_json( $receipt_path, $result['receipt'], true );
						$receipt = array( 'path' => $receipt_rel, 'sha256' => hash_file( 'sha256', $receipt_path ) );
					}
					$row[ $format ] = array( 'path' => $artifact_rel, 'sha256' => hash( 'sha256', $result['bytes'] ), 'receipt' => $receipt );
				}
				$runs[ $run ]['fixtures'][ $name ] = $row;
			}
		}
		$this->assert_deterministic_runs( $output, $runs );

		$manifest = array(
			'schema' => self::BUNDLE_SCHEMA,
			'version' => self::BUNDLE_VERSION,
			'qualification' => $qualification,
			'plugin_version' => $this->plugin_version(),
			'fixture_manifest' => array( 'path' => 'manifests/golden.json', 'sha256' => hash_file( 'sha256', $output . '/manifests/golden.json' ) ),
			'render_plan_manifest' => array( 'path' => 'manifests/render-plan.json', 'sha256' => hash_file( 'sha256', $output . '/manifests/render-plan.json' ) ),
			'raster_dpi' => 96,
			'renderers' => $renderer_rows,
			'runs' => $runs,
		);
		$this->write_json( $output . '/bundle.json', $manifest, true );
		return $manifest;
	}

	private function assert_config_shape( array $config ) {
		$allowed = array( 'font_root', 'formats', 'production_candidate_ack' );
		if ( array_diff( array_keys( $config ), $allowed ) || ! isset( $config['font_root'], $config['formats'] ) || ! is_array( $config['formats'] ) ) {
			throw new InvalidArgumentException( 'Candidate bootstrap must return only font_root, formats, and optional production_candidate_ack.' );
		}
		$formats = array( 'pdf', 'png', 'jpeg' );
		if ( array_diff( array_keys( $config['formats'] ), $formats ) || array_diff( $formats, array_keys( $config['formats'] ) ) ) {
			throw new InvalidArgumentException( 'Candidate bootstrap must configure exactly PDF, PNG and JPEG.' );
		}
	}

	/** @return array{0:RendererRegistry,1:array<string,mixed>,2:array<string,array<string,string>>} */
	private function prepare_renderers( array $formats, $output, array $expected_font_manifest ) {
		$registry = new RendererRegistry();
		$registered = array();
		$rows = array();
		$authority = array();
		$normalized_font_manifest = $this->font_manifest->normalize( $expected_font_manifest );
		foreach ( array( 'pdf', 'png', 'jpeg' ) as $format ) {
			$item = $formats[ $format ];
			if ( ! is_array( $item ) || array_diff( array_keys( $item ), array( 'renderer', 'candidate_manifest', 'source_archive', 'dependency_lock' ) ) || ! isset( $item['renderer'], $item['candidate_manifest'], $item['source_archive'], $item['dependency_lock'] ) || ! $item['renderer'] instanceof RendererInterface ) {
				throw new InvalidArgumentException( 'Candidate format configuration is malformed: ' . $format );
			}
			$renderer = $item['renderer'];
			$manifest_raw = $this->decode_json_file( $item['candidate_manifest'], 65536, strtoupper( $format ) . ' candidate manifest' );
			$manifest = $this->candidate_contract->normalize( $manifest_raw );
			$manifest_hash = $this->candidate_contract->hash( $manifest_raw );
			$source = $this->bound_file( $item['source_archive'], self::MAX_PROVENANCE_BYTES, strtoupper( $format ) . ' candidate source archive' );
			$lock = $this->bound_file( $item['dependency_lock'], self::MAX_PROVENANCE_BYTES, strtoupper( $format ) . ' candidate dependency lock' );
			if ( ! hash_equals( $manifest['implementation']['source_sha256'], hash_file( 'sha256', $source ) ) || ! hash_equals( $manifest['implementation']['dependency_lock_sha256'], hash_file( 'sha256', $lock ) ) ) {
				throw new InvalidArgumentException( 'Candidate source/dependency bytes do not match the manifest: ' . $format );
			}
			$id = strtolower( trim( (string) $renderer->id() ) );
			$version = trim( (string) $renderer->version() );
			$renderer_formats = $this->normalize_format_list( $renderer->formats() );
			if ( ! hash_equals( $manifest['renderer_id'], $id ) || ! hash_equals( $manifest['renderer_version'], $version ) || $manifest['formats'] !== $renderer_formats || ! in_array( $format, $renderer_formats, true ) ) {
				throw new InvalidArgumentException( 'Candidate manifest identity/formats do not match the registered renderer: ' . $format );
			}
			if ( $manifest['requirements'] !== $this->normalize_requirements( $renderer->requirements() ) ) {
				throw new InvalidArgumentException( 'Candidate manifest requirements do not exactly match renderer runtime requirements: ' . $format );
			}
			$context = (array) $renderer->context();
			if ( ! isset( $context['candidate_manifest_sha256'] ) || ! hash_equals( $manifest_hash, strtolower( trim( (string) $context['candidate_manifest_sha256'] ) ) ) ) {
				throw new InvalidArgumentException( 'Renderer context must bind its exact canonical candidate-manifest SHA-256: ' . $format );
			}
			if ( ! isset( $context['font_manifest'] ) || $normalized_font_manifest !== $this->font_manifest->normalize( (array) $context['font_manifest'] ) ) {
				throw new InvalidArgumentException( 'Renderer context must bind the exact frozen PDF-04 font manifest: ' . $format );
			}
			$this->assert_portable_context( $context, strtoupper( $format ) . ' renderer context' );
			$object_key = spl_object_hash( $renderer );
			if ( ! isset( $registered[ $object_key ] ) ) { $registry->register( $renderer ); $registered[ $object_key ] = true; }

			$manifest_rel = $this->copy_canonical_manifest( $output, $manifest, $manifest_hash );
			$source_rel = $this->copy_content_addressed( $output, $source, 'candidates/source', 'source' );
			$lock_rel = $this->copy_content_addressed( $output, $lock, 'candidates/locks', 'lock' );
			$context_hash = $this->canonical->hash( array( 'renderer_id' => $id, 'renderer_version' => $version, 'context' => $context ) );
			$rows[ $format ] = array(
				'id' => $id,
				'version' => $version,
				'context_hash' => $context_hash,
				'candidate_manifest' => array( 'path' => $manifest_rel, 'sha256' => $manifest_hash ),
				'source_archive' => array( 'path' => $source_rel, 'sha256' => hash_file( 'sha256', $source ) ),
				'dependency_lock' => array( 'path' => $lock_rel, 'sha256' => hash_file( 'sha256', $lock ) ),
			);
			$authority[ $format ] = array( 'id' => $id, 'version' => $version, 'candidate_manifest_sha256' => $manifest_hash );
		}
		return array( $registry, $rows, $authority );
	}

	private function normalize_format_list( $formats ) {
		if ( ! is_array( $formats ) ) { throw new InvalidArgumentException( 'Renderer formats must be an array.' ); }
		$out = array();
		foreach ( $formats as $format ) {
			$format = strtolower( trim( (string) $format ) );
			if ( ! in_array( $format, array( 'pdf', 'png', 'jpeg' ), true ) || in_array( $format, $out, true ) ) { throw new InvalidArgumentException( 'Renderer formats are invalid or duplicated.' ); }
			$out[] = $format;
		}
		sort( $out, SORT_STRING );
		return $out;
	}

	private function normalize_requirements( $requirements ) {
		$requirements = (array) $requirements;
		$allowed = array( 'php_min', 'extensions', 'classes', 'functions' );
		if ( array_diff( array_keys( $requirements ), $allowed ) ) { throw new InvalidArgumentException( 'Renderer requirements contain an unsupported property.' ); }
		$out = array(
			'php_min' => trim( (string) ( $requirements['php_min'] ?? '' ) ),
			'extensions' => array_values( array_map( 'strval', (array) ( $requirements['extensions'] ?? array() ) ) ),
			'classes' => array_values( array_map( 'strval', (array) ( $requirements['classes'] ?? array() ) ) ),
			'functions' => array_values( array_map( 'strval', (array) ( $requirements['functions'] ?? array() ) ) ),
		);
		foreach ( array( 'extensions', 'classes', 'functions' ) as $key ) { sort( $out[ $key ], SORT_STRING ); }
		return $out;
	}

	private function assert_portable_context( $value, $label ) {
		$walk = function ( $item ) use ( &$walk, $label ) {
			if ( is_array( $item ) ) { foreach ( $item as $child ) { $walk( $child ); } return; }
			if ( ! is_string( $item ) ) { return; }
			if ( false !== strpos( $item, "\0" ) || 1 === preg_match( '#^(?:/|[A-Za-z]:[\\\\/])#', $item ) || 1 === preg_match( '#^[a-z][a-z0-9+.-]*://#i', $item ) ) {
				throw new InvalidArgumentException( $label . ' must not contain local absolute paths, URLs, or NUL bytes.' );
			}
		};
		$walk( $value );
	}

	private function assert_deterministic_runs( $output, array $runs ) {
		foreach ( $runs['a']['fixtures'] as $name => $row ) {
			foreach ( array( 'pdf', 'png', 'jpeg' ) as $format ) {
				$a = $runs['a']['fixtures'][ $name ][ $format ];
				$b = $runs['b']['fixtures'][ $name ][ $format ];
				if ( ! hash_equals( $a['sha256'], $b['sha256'] ) || file_get_contents( $output . '/' . $a['path'] ) !== file_get_contents( $output . '/' . $b['path'] ) ) {
					throw new RuntimeException( 'Candidate output is not byte-identical across deterministic runs: ' . $name . '/' . $format );
				}
			}
		}
	}

	private function copy_canonical_manifest( $output, array $manifest, $hash ) {
		$relative = 'candidates/manifests/' . $hash . '.json';
		$path = $output . '/' . $relative;
		$this->ensure_parent( $path );
		$bytes = $this->canonical->encode( $manifest ); // no newline: file SHA must equal canonical manifest SHA.
		if ( ! hash_equals( $hash, hash( 'sha256', $bytes ) ) ) { throw new RuntimeException( 'Canonical candidate manifest hash drifted during bundle production.' ); }
		$this->write_binary( $path, $bytes );
		return $relative;
	}

	private function copy_content_addressed( $output, $source, $directory, $fallback_extension ) {
		$hash = hash_file( 'sha256', $source );
		$extension = strtolower( pathinfo( $source, PATHINFO_EXTENSION ) );
		if ( ! preg_match( '/^[a-z0-9]{1,8}$/', $extension ) ) { $extension = $fallback_extension; }
		$relative = $directory . '/' . $hash . '.' . $extension;
		$target = $output . '/' . $relative;
		$this->ensure_parent( $target );
		if ( ! is_file( $target ) ) { $this->copy_exact( $source, $target ); }
		elseif ( ! hash_equals( $hash, hash_file( 'sha256', $target ) ) ) { throw new RuntimeException( 'Content-addressed candidate evidence path collided.' ); }
		return $relative;
	}

	private function prepare_output_dir( $path ) {
		$path = rtrim( (string) $path, '/\\' );
		if ( '' === $path || false !== strpos( $path, "\0" ) || is_link( $path ) ) { throw new InvalidArgumentException( 'Candidate output directory is invalid.' ); }
		if ( file_exists( $path ) ) {
			if ( ! is_dir( $path ) ) { throw new InvalidArgumentException( 'Candidate output path already exists and is not a directory.' ); }
			$items = array_diff( scandir( $path ), array( '.', '..' ) );
			if ( ! empty( $items ) ) { throw new InvalidArgumentException( 'Candidate output directory must be absent or empty.' ); }
		} elseif ( ! mkdir( $path, 0700, true ) ) { throw new RuntimeException( 'Candidate output directory could not be created.' ); }
		$real = realpath( $path );
		if ( false === $real ) { throw new RuntimeException( 'Candidate output directory could not be resolved.' ); }
		foreach ( array( 'manifests', 'candidates/manifests', 'candidates/source', 'candidates/locks', 'artifacts/a', 'artifacts/b', 'receipts/a', 'receipts/b' ) as $sub ) {
			if ( ! is_dir( $real . '/' . $sub ) && ! mkdir( $real . '/' . $sub, 0700, true ) ) { throw new RuntimeException( 'Candidate output subdirectory could not be created.' ); }
		}
		return $real;
	}

	private function plugin_version() {
		$bytes = file_get_contents( $this->root . '/certloom.php' );
		if ( ! is_string( $bytes ) || 1 !== preg_match( '/^\s*\*\s*Version:\s*([^\s]+)/m', $bytes, $match ) ) { throw new RuntimeException( 'Certloom plugin version is unavailable.' ); }
		$version = trim( $match[1] );
		if ( 1 !== preg_match( '/^[0-9]+\.[0-9]+\.[0-9]+(?:[-+][A-Za-z0-9.-]+)?$/', $version ) ) { throw new RuntimeException( 'Certloom plugin version is invalid.' ); }
		return $version;
	}

	private function decode_json_file( $path, $max_bytes, $label ) {
		$path = $this->bound_file( $path, $max_bytes, $label );
		$bytes = file_get_contents( $path );
		$data = is_string( $bytes ) ? json_decode( $bytes, true ) : null;
		if ( ! is_array( $data ) ) { throw new InvalidArgumentException( $label . ' is not valid JSON.' ); }
		return $data;
	}

	private function bound_file( $path, $max_bytes, $label ) {
		if ( ! is_string( $path ) || '' === trim( $path ) || false !== strpos( $path, "\0" ) || is_link( $path ) ) { throw new InvalidArgumentException( $label . ' path is invalid or symbolic.' ); }
		$real = realpath( $path );
		if ( false === $real || ! is_file( $real ) || ! is_readable( $real ) ) { throw new InvalidArgumentException( $label . ' must be a readable regular file.' ); }
		$size = filesize( $real );
		if ( false === $size || $size < 1 || $size > $max_bytes ) { throw new InvalidArgumentException( $label . ' size is outside the allowed range.' ); }
		return $real;
	}

	private function real_directory( $path, $label ) {
		if ( ! is_string( $path ) || '' === trim( $path ) || false !== strpos( $path, "\0" ) || is_link( $path ) ) { throw new InvalidArgumentException( $label . ' path is invalid or symbolic.' ); }
		$real = realpath( $path );
		if ( false === $real || ! is_dir( $real ) || ! is_readable( $real ) ) { throw new InvalidArgumentException( $label . ' must be a readable directory.' ); }
		return $real;
	}

	private function ensure_parent( $path ) {
		$parent = dirname( $path );
		if ( ! is_dir( $parent ) && ! mkdir( $parent, 0700, true ) ) { throw new RuntimeException( 'Candidate bundle directory could not be created.' ); }
	}

	private function copy_exact( $source, $target ) {
		$this->ensure_parent( $target );
		if ( ! copy( $source, $target ) || ! hash_equals( hash_file( 'sha256', $source ), hash_file( 'sha256', $target ) ) ) { throw new RuntimeException( 'Candidate evidence copy did not preserve exact bytes.' ); }
	}

	private function write_binary( $path, $bytes ) {
		$this->ensure_parent( $path );
		$written = file_put_contents( $path, $bytes, LOCK_EX );
		if ( ! is_int( $written ) || $written !== strlen( $bytes ) ) { throw new RuntimeException( 'Candidate bundle file could not be written completely.' ); }
	}

	private function write_json( $path, array $value, $newline ) {
		$bytes = $this->canonical->encode( $value ) . ( $newline ? "\n" : '' );
		$this->write_binary( $path, $bytes );
	}
}
