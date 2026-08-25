<?php
namespace Certloom\Application\Rendering;

use InvalidArgumentException;

/**
 * Cross-check a complete PDF-04 evidence set before it may be recorded as accepted.
 * Individual hashes are insufficient: every independent report/environment fact
 * must agree with the normalized acceptance evidence and each other.
 */
final class RendererAcceptanceImporter {
	private $acceptance;
	private $reports;
	private $environments;

	public function __construct( ?RendererAcceptanceContract $acceptance = null, ?RendererVerificationReport $reports = null, ?RendererVerifierEnvironment $environments = null ) {
		$this->acceptance = $acceptance ?: new RendererAcceptanceContract();
		$this->reports = $reports ?: new RendererVerificationReport();
		$this->environments = $environments ?: new RendererVerifierEnvironment();
	}

	/** @return array<string,mixed> normalized acceptance evidence */
	public function import( array $evidence, array $report, array $environment ) {
		$accepted = $this->acceptance->normalize( $evidence );
		$verified = $this->reports->normalize( $report );
		$env = $this->environments->normalize( $environment );
		$report_hash = $this->reports->hash( $verified );
		$environment_hash = $this->environments->hash( $env );

		if ( ! hash_equals( $accepted['verification_report_sha256'], $report_hash ) || ! hash_equals( $accepted['verifier_environment_sha256'], $environment_hash ) || ! hash_equals( $verified['verifier_environment_sha256'], $environment_hash ) ) {
			throw new InvalidArgumentException( 'Renderer acceptance does not bind the exact independently verified report/environment.' );
		}
		if ( 'production-candidate' !== $verified['qualification'] || true !== $verified['qualifies_pdf04'] || $env['plugin_version'] !== $accepted['plugin_version'] ) {
			throw new InvalidArgumentException( 'Renderer verifier qualification/plugin version does not match acceptance evidence.' );
		}
		foreach ( array( 'bundle_manifest_sha256', 'fixture_manifest_sha256', 'render_plan_manifest_sha256' ) as $key ) {
			if ( ! hash_equals( $accepted[ $key ], $verified[ $key ] ) ) { throw new InvalidArgumentException( 'Renderer verifier report does not match the accepted frozen bundle/manifest identity.' ); }
		}
		foreach ( RendererAcceptanceContract::required_formats() as $format ) {
			if ( $accepted['renderers'][ $format ]['id'] !== $verified['renderers'][ $format ]['id'] || $accepted['renderers'][ $format ]['version'] !== $verified['renderers'][ $format ]['version'] || ! hash_equals( $accepted['renderers'][ $format ]['candidate_manifest_sha256'], $verified['renderers'][ $format ]['candidate_manifest_sha256'] ) ) {
				throw new InvalidArgumentException( 'Renderer verifier report does not match accepted candidate identity/provenance.' );
			}
		}
		foreach ( RendererAcceptanceContract::required_fixtures() as $name ) {
			foreach ( array( 'render_plan_sha256', 'pdf_sha256', 'png_sha256', 'jpeg_sha256' ) as $key ) {
				if ( ! hash_equals( $accepted['fixtures'][ $name ][ $key ], $verified['fixtures'][ $name ][ $key ] ) ) { throw new InvalidArgumentException( 'Renderer verifier fixture result does not match acceptance evidence.' ); }
			}
		}
		return $accepted;
	}
}
