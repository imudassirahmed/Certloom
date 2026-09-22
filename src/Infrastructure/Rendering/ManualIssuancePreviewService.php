<?php
namespace Certloom\Infrastructure\Rendering;

use Certloom\Application\Contract\LocalAssetResolverInterface;
use Certloom\Application\Rendering\BrowserPreviewFontManifest;
use Certloom\Application\Rendering\LayoutResolver;
use Certloom\Application\Rendering\RenderPlanBuilder;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Builds the review-stage Manual Issuance certificate Preview from the exact
 * published Template revision and the mutation-free merge snapshot candidate.
 *
 * This is intentionally browser-preview authority only. It shares the same
 * LayoutResolver -> RenderPlanBuilder path as artifact adapters, but does not
 * manufacture an accepted PDF/PNG/JPEG renderer receipt.
 */
final class ManualIssuancePreviewService {
	/** @var LayoutResolver */ private $resolver;
	/** @var RenderPlanBuilder */ private $plans;
	/** @var BrowserPreviewFontManifest */ private $fonts;
	/** @var LocalAssetResolverInterface */ private $assets;

	public function __construct( ?LayoutResolver $resolver = null, ?RenderPlanBuilder $plans = null, ?BrowserPreviewFontManifest $fonts = null, ?LocalAssetResolverInterface $assets = null ) {
		$this->resolver = $resolver ?: new LayoutResolver( null, null, null, new LocalQrEncoder(), new LocalCode128Encoder() );
		$this->plans = $plans ?: new RenderPlanBuilder();
		$this->fonts = $fonts ?: new BrowserPreviewFontManifest();
		$this->assets = $assets ?: new WordPressLocalAssetResolver();
	}

	/** @return array<string,mixed>|\WP_Error */
	public function build( array $review ) {
		$revision = isset( $review['template_revision'] ) && is_array( $review['template_revision'] ) ? $review['template_revision'] : null;
		$merge = isset( $review['preview_merge_values'] ) && is_array( $review['preview_merge_values'] ) ? $review['preview_merge_values'] : null;
		if ( ! is_array( $revision ) || empty( $revision['layout'] ) || ! is_array( $revision['layout'] ) || ! is_array( $merge ) ) {
			return new \WP_Error( 'certloom_manual_issue_preview_input_invalid', __( 'The reviewed published Template or merge data is unavailable for Preview.', 'certloom' ) );
		}
		$verification_url = trim( (string) ( $merge['verification_url'] ?? $merge['verification.url'] ?? '' ) );
		if ( '' === $verification_url ) {
			$verification_url = 'https://example.invalid/certloom/manual-issuance-review';
		}
		try {
			$resolved = $this->resolver->resolve( $revision['layout'], $merge, $verification_url, $this->assets );
			$font_manifest = $this->fonts->for_document( $resolved );
			$plan = $this->plans->build( $resolved, array( 'font_manifest' => $font_manifest ) );
			$plan_hash = $this->plans->hash( $plan );
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'certloom_manual_issue_preview_build_failed', sprintf( /* translators: %s: safe rendering error. */ __( 'The reviewed credential could not build its canonical browser Preview: %s', 'certloom' ), $error->getMessage() ) );
		}
		return array(
			'render_plan' => $plan,
			'render_plan_sha256' => $plan_hash,
			'layout_sha256' => strtolower( (string) ( $revision['layout_sha256'] ?? '' ) ),
			'composition_path' => 'LayoutResolver → RenderPlanBuilder',
			'font_mode' => 'browser-preview-contract',
			'authority' => 'preview_only',
		);
	}
}
