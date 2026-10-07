<?php
/**
 * Source-only validator/exporter for packaging reviewed PDF-04 renderer authority.
 *
 * This is deliberately not part of the installable plugin. It validates the
 * complete finalized authority directory against the exact source payload, then
 * returns canonical accepted.json bytes suitable for deterministic staging by
 * tests/build-release.sh.
 */

if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
require_once dirname( __DIR__ ) . '/bootstrap-autoload.php';

use Certloom\Application\Rendering\CanonicalJson;
use Certloom\Application\Rendering\RendererAcceptanceContract;
use Certloom\Application\Rendering\RendererAcceptanceImporter;
use Certloom\Application\Rendering\RendererReleaseManifest;
use Certloom\Application\Rendering\RendererVerificationReport;
use Certloom\Application\Rendering\RendererVerifierEnvironment;
use Certloom\Application\Rendering\RuntimePayloadFingerprint;

final class CertloomAuthorizedReleaseAuthority {
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
        $this->assert_non_symbolic_path_components( (string) $source_root, 'Certloom source root' );
        $root = realpath( (string) $source_root );
        if ( false === $root || ! is_dir( $root ) || ! is_readable( $root ) || ! is_file( $root . '/certloom.php' ) ) {
            throw new InvalidArgumentException( 'Certloom source root is invalid for authorized release packaging.' );
        }
        $this->root = rtrim( $root, '/\\' );
        $this->canonical = new CanonicalJson();
        $this->acceptance = new RendererAcceptanceContract();
        $this->reports = new RendererVerificationReport();
        $this->environments = new RendererVerifierEnvironment();
        $this->importer = new RendererAcceptanceImporter( $this->acceptance, $this->reports, $this->environments );
        $this->release_manifest = new RendererReleaseManifest();
        $this->runtime_payloads = new RuntimePayloadFingerprint();
    }

    /** @return array<string,mixed> */
    public function validate_authority_directory( $authority_dir ) {
        $dir = $this->resolve_external_directory( $authority_dir );
        $expected_files = array(
            'acceptance-evidence.json',
            'accepted.json',
            'authority-index.json',
            'verification-index.json',
            'verification-report.json',
            'verifier-environment.json',
        );
        $actual = array();
        foreach ( scandir( $dir ) ?: array() as $name ) {
            if ( '.' === $name || '..' === $name ) { continue; }
            $path = $dir . DIRECTORY_SEPARATOR . $name;
            if ( is_link( $path ) || ! is_file( $path ) ) {
                throw new InvalidArgumentException( 'Renderer authority directory may contain only the canonical regular JSON evidence files.' );
            }
            $actual[] = $name;
        }
        sort( $actual, SORT_STRING );
        $sorted_expected = $expected_files;
        sort( $sorted_expected, SORT_STRING );
        if ( $actual !== $sorted_expected ) {
            throw new InvalidArgumentException( 'Renderer authority directory file set is incomplete or contains unexpected entries.' );
        }

        $accepted_raw = $this->decode_json_file( $dir . '/accepted.json', 'accepted renderer release manifest' );
        $acceptance_raw = $this->decode_json_file( $dir . '/acceptance-evidence.json', 'renderer acceptance evidence' );
        $report_raw = $this->decode_json_file( $dir . '/verification-report.json', 'renderer verification report' );
        $environment_raw = $this->decode_json_file( $dir . '/verifier-environment.json', 'renderer verifier environment' );
        $verification_index = $this->normalize_verification_index( $this->decode_json_file( $dir . '/verification-index.json', 'renderer verification index' ) );
        $authority_index = $this->normalize_authority_index( $this->decode_json_file( $dir . '/authority-index.json', 'renderer authority index' ) );

        $manifest = $this->release_manifest->normalize( $accepted_raw );
        $accepted = $this->importer->import( $acceptance_raw, $report_raw, $environment_raw );
        $report = $this->reports->normalize( $report_raw );
        $environment = $this->environments->normalize( $environment_raw );
        $runtime_payload = $this->runtime_payloads->fingerprint( $this->root );
        $plugin_version = $this->plugin_version();

        $acceptance_hash = $this->acceptance->hash( $accepted );
        $report_hash = $this->reports->hash( $report );
        $environment_hash = $this->environments->hash( $environment );
        $release_hash = $this->release_manifest->hash( $manifest );

        if ( $manifest['plugin_version'] !== $plugin_version || $accepted['plugin_version'] !== $plugin_version || $environment['plugin_version'] !== $plugin_version || $authority_index['plugin_version'] !== $plugin_version ) {
            throw new InvalidArgumentException( 'Renderer authority does not target this exact Certloom source version.' );
        }
        if ( $manifest['runtime_payload'] !== $runtime_payload || $authority_index['runtime_payload'] !== $runtime_payload ) {
            throw new InvalidArgumentException( 'Renderer authority does not target these exact Certloom production bytes.' );
        }
        if ( ! hash_equals( $release_hash, $authority_index['release_manifest_sha256'] ) ) {
            throw new InvalidArgumentException( 'Renderer authority index does not bind the exact accepted.json release manifest.' );
        }
        if ( ! hash_equals( $acceptance_hash, $authority_index['acceptance_sha256'] ) || ! hash_equals( $acceptance_hash, $manifest['acceptance']['acceptance_sha256'] ) ) {
            throw new InvalidArgumentException( 'Renderer authority does not bind the exact imported acceptance evidence.' );
        }
        if ( ! hash_equals( $report_hash, $authority_index['verification_report_sha256'] ) || ! hash_equals( $report_hash, $verification_index['verification_report_sha256'] ) || ! hash_equals( $report_hash, $manifest['acceptance']['verification_report_sha256'] ) ) {
            throw new InvalidArgumentException( 'Renderer authority does not bind the exact independent verification report.' );
        }
        if ( ! hash_equals( $environment_hash, $authority_index['verifier_environment_sha256'] ) || ! hash_equals( $environment_hash, $verification_index['verifier_environment_sha256'] ) || ! hash_equals( $environment_hash, $manifest['acceptance']['verifier_environment_sha256'] ) ) {
            throw new InvalidArgumentException( 'Renderer authority does not bind the exact verifier environment.' );
        }
        if ( ! hash_equals( $accepted['bundle_manifest_sha256'], $authority_index['bundle_manifest_sha256'] ) || ! hash_equals( $accepted['bundle_manifest_sha256'], $verification_index['bundle_manifest_sha256'] ) ) {
            throw new InvalidArgumentException( 'Renderer authority does not bind the exact production bundle.' );
        }
        if ( ! hash_equals( $accepted['fixture_manifest_sha256'], $authority_index['fixture_manifest_sha256'] ) || ! hash_equals( $accepted['fixture_manifest_sha256'], $manifest['acceptance']['fixture_manifest_sha256'] ) ) {
            throw new InvalidArgumentException( 'Renderer authority does not bind the exact frozen fixture manifest.' );
        }
        if ( ! hash_equals( $accepted['render_plan_manifest_sha256'], $authority_index['render_plan_manifest_sha256'] ) || ! hash_equals( $accepted['render_plan_manifest_sha256'], $manifest['acceptance']['render_plan_manifest_sha256'] ) ) {
            throw new InvalidArgumentException( 'Renderer authority does not bind the exact frozen Render Plan manifest.' );
        }

        foreach ( array( 'pdf', 'png', 'jpeg' ) as $format ) {
            $expected_renderer = array(
                'id' => $accepted['renderers'][ $format ]['id'],
                'version' => $accepted['renderers'][ $format ]['version'],
                'candidate_manifest_sha256' => $accepted['renderers'][ $format ]['candidate_manifest_sha256'],
            );
            if ( $manifest['renderers'][ $format ] !== $expected_renderer ) {
                throw new InvalidArgumentException( 'Renderer authority release manifest drifted from imported renderer identity for ' . strtoupper( $format ) . '.' );
            }
        }

        return array(
            'authority_dir' => $dir,
            'plugin_version' => $plugin_version,
            'runtime_payload' => $runtime_payload,
            'release_manifest' => $manifest,
            'release_manifest_sha256' => $release_hash,
            'accepted_json_bytes' => $this->canonical->encode( $manifest ) . "\n",
        );
    }

    /** @return array<string,mixed> */
    public function validate_manifest_file( $manifest_path ) {
        $manifest = $this->release_manifest->normalize( $this->decode_json_file( $manifest_path, 'accepted renderer release manifest' ) );
        $runtime_payload = $this->runtime_payloads->fingerprint( $this->root );
        $plugin_version = $this->plugin_version();
        if ( $manifest['plugin_version'] !== $plugin_version ) {
            throw new InvalidArgumentException( 'Bundled renderer authority belongs to a different Certloom version.' );
        }
        if ( $manifest['runtime_payload'] !== $runtime_payload ) {
            throw new InvalidArgumentException( 'Bundled renderer authority belongs to different Certloom production bytes.' );
        }
        return array(
            'plugin_version' => $plugin_version,
            'runtime_payload' => $runtime_payload,
            'release_manifest' => $manifest,
            'release_manifest_sha256' => $this->release_manifest->hash( $manifest ),
            'accepted_json_bytes' => $this->canonical->encode( $manifest ) . "\n",
        );
    }

    private function resolve_external_directory( $path ) {
        if ( ! is_string( $path ) || '' === trim( $path ) || false !== strpos( $path, "\0" ) ) {
            throw new InvalidArgumentException( 'Renderer authority directory path is invalid.' );
        }
        $this->assert_non_symbolic_path_components( $path, 'renderer authority directory' );
        $real = realpath( $path );
        if ( false === $real || ! is_dir( $real ) || ! is_readable( $real ) ) {
            throw new InvalidArgumentException( 'Renderer authority directory is invalid or unreadable.' );
        }
        $real = rtrim( $real, '/\\' );
        if ( $real === $this->root || 0 === strpos( $real . DIRECTORY_SEPARATOR, $this->root . DIRECTORY_SEPARATOR ) ) {
            throw new InvalidArgumentException( 'Renderer authority directory must remain outside the Certloom source tree.' );
        }
        return $real;
    }

    /** @return array<string,mixed> */
    private function decode_json_file( $path, $label ) {
        $this->assert_non_symbolic_path_components( (string) $path, $label );
        $real = realpath( (string) $path );
        if ( false === $real || ! is_file( $real ) || ! is_readable( $real ) || is_link( $path ) ) {
            throw new InvalidArgumentException( $label . ' must be a readable non-symbolic regular file.' );
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

    /** @return array<string,mixed> */
    private function normalize_verification_index( array $value ) {
        $keys = array( 'schema', 'version', 'bundle_manifest_sha256', 'verification_report_sha256', 'verifier_environment_sha256' );
        $this->assert_exact_keys( $value, $keys, 'renderer verification index' );
        if ( self::VERIFICATION_INDEX_SCHEMA !== (string) $value['schema'] || self::VERIFICATION_INDEX_VERSION !== $value['version'] ) {
            throw new InvalidArgumentException( 'Renderer verification index schema/version is unsupported.' );
        }
        $out = array( 'schema' => self::VERIFICATION_INDEX_SCHEMA, 'version' => self::VERIFICATION_INDEX_VERSION );
        foreach ( array_slice( $keys, 2 ) as $key ) { $out[ $key ] = $this->sha256( $value[ $key ], 'renderer verification index' ); }
        return $out;
    }

    /** @return array<string,mixed> */
    private function normalize_authority_index( array $value ) {
        $keys = array( 'schema', 'version', 'plugin_version', 'runtime_payload', 'bundle_manifest_sha256', 'acceptance_sha256', 'verification_report_sha256', 'verifier_environment_sha256', 'fixture_manifest_sha256', 'render_plan_manifest_sha256', 'release_manifest_sha256' );
        $this->assert_exact_keys( $value, $keys, 'renderer authority index' );
        if ( self::INDEX_SCHEMA !== (string) $value['schema'] || self::INDEX_VERSION !== $value['version'] ) {
            throw new InvalidArgumentException( 'Renderer authority index schema/version is unsupported.' );
        }
        $plugin_version = trim( (string) $value['plugin_version'] );
        if ( 1 !== preg_match( '/^[0-9]+\.[0-9]+\.[0-9]+(?:[-+][A-Za-z0-9.-]+)?$/', $plugin_version ) || strlen( $plugin_version ) > 64 ) {
            throw new InvalidArgumentException( 'Renderer authority index plugin version is invalid.' );
        }
        $out = array(
            'schema' => self::INDEX_SCHEMA,
            'version' => self::INDEX_VERSION,
            'plugin_version' => $plugin_version,
            'runtime_payload' => $this->runtime_payloads->normalize( $value['runtime_payload'] ),
        );
        foreach ( array_slice( $keys, 4 ) as $key ) { $out[ $key ] = $this->sha256( $value[ $key ], 'renderer authority index' ); }
        return $out;
    }

    private function assert_exact_keys( array $value, array $keys, $label ) {
        if ( array_diff( array_keys( $value ), $keys ) || array_diff( $keys, array_keys( $value ) ) ) {
            throw new InvalidArgumentException( $label . ' has an invalid shape.' );
        }
    }

    private function sha256( $value, $label ) {
        $value = strtolower( trim( (string) $value ) );
        if ( 1 !== preg_match( '/^[0-9a-f]{64}$/', $value ) ) {
            throw new InvalidArgumentException( $label . ' contains an invalid SHA-256.' );
        }
        return $value;
    }

    private function assert_non_symbolic_path_components( $path, $label ) {
        $path = (string) $path;
        if ( '' === trim( $path ) || false !== strpos( $path, "\0" ) ) {
            throw new InvalidArgumentException( $label . ' path is invalid.' );
        }
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
