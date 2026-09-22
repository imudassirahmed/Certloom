<?php
namespace Certloom\Infrastructure\Rendering;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
use Certloom\Application\Contract\LocalAssetResolverInterface;
use Certloom\Application\Rendering\BrowserPreviewFontManifest;
use Certloom\Application\Rendering\LayoutResolver;
use Certloom\Application\Rendering\RenderPlanBuilder;
use Certloom\Application\Templates\TemplatePreviewSamples;

/**
 * Builds a read-only Template Preview through the same LayoutResolver and
 * RenderPlanBuilder used by PDF/PNG/JPEG artifact generation.
 */
final class TemplatePreviewService {
	private $resolver;
	private $plans;
	private $samples;
	private $fonts;
	private $assets;

	public function __construct( ?LayoutResolver $resolver = null, ?RenderPlanBuilder $plans = null, ?TemplatePreviewSamples $samples = null, ?BrowserPreviewFontManifest $fonts = null, ?LocalAssetResolverInterface $assets = null ) {
		$this->resolver = $resolver ?: new LayoutResolver( null, null, null, new LocalQrEncoder(), new LocalCode128Encoder() );
		$this->plans = $plans ?: new RenderPlanBuilder();
		$this->samples = $samples ?: new TemplatePreviewSamples();
		$this->fonts = $fonts ?: new BrowserPreviewFontManifest();
		$this->assets = $assets ?: new TemplatePreviewAssetResolver();
	}

	/** @return array<string,mixed>|\WP_Error */
	public function build( array $template, $revision_mode = 'current', $sample_profile = TemplatePreviewSamples::STANDARD ) {
		$revision_mode = 'published' === (string) $revision_mode ? 'published' : 'current';
		$sample_profile = in_array( (string) $sample_profile, TemplatePreviewSamples::profiles(), true ) ? (string) $sample_profile : TemplatePreviewSamples::STANDARD;
		$key = $revision_mode . '_revision';
		$id_key = $revision_mode . '_revision_id';
		$revision = isset( $template[ $key ] ) && is_array( $template[ $key ] ) ? $template[ $key ] : null;
		$revision_id = absint( $template[ $id_key ] ?? 0 );
		if ( ! $revision_id || ! is_array( $revision ) || empty( $revision['layout'] ) || ! is_array( $revision['layout'] ) ) {
			return new \WP_Error( 'certloom_template_preview_revision_unavailable', 'The selected Template revision is unavailable for Preview.' );
		}
		try {
			$snapshot = $this->samples->snapshot_for_layout( $revision['layout'], $sample_profile );
			$resolved = $this->resolver->resolve( $revision['layout'], $snapshot, $this->samples->verification_url( $sample_profile ), $this->assets );
			$font_manifest = $this->fonts->for_document( $resolved );
			$plan = $this->plans->build( $resolved, array( 'font_manifest' => $font_manifest ) );
			$plan_hash = $this->plans->hash( $plan );
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'certloom_template_preview_build_failed', 'Template Preview could not build the canonical Render Plan: ' . $error->getMessage() );
		}
		return array(
			'revision_mode' => $revision_mode,
			'revision_id' => $revision_id,
			'revision_number' => absint( $revision['revision_number'] ?? 0 ),
			'layout_sha256' => strtolower( (string) ( $revision['layout_sha256'] ?? '' ) ),
			'sample_profile' => $sample_profile,
			'merge_snapshot' => $snapshot,
			'verification_url' => $this->samples->verification_url( $sample_profile ),
			'render_plan' => $plan,
			'render_plan_sha256' => $plan_hash,
			'composition_path' => 'LayoutResolver → RenderPlanBuilder',
			'font_mode' => 'browser-preview-contract',
		);
	}
}
