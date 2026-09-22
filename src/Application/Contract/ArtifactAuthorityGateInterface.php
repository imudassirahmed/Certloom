<?php
namespace Certloom\Application\Contract;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
use Certloom\Application\Rendering\RendererRegistry;

/**
 * Runtime authorization boundary for production artifact execution.
 * Implementations must fail closed unless the exact shipped release and
 * renderer candidate have earned authority for the requested format.
 */
interface ArtifactAuthorityGateInterface {
	/** @return array<string,mixed> */
	public function artifact_status( $format, RendererRegistry $registry );

	/** @return bool */
	public function can_render( $format, RendererRegistry $registry );
}
