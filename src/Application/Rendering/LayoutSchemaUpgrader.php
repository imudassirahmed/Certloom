<?php
namespace Certloom\Application\Rendering;

use Certloom\Domain\Rendering\LayoutSchema;
use InvalidArgumentException;

/** Deterministic, explicit upgrade boundary for immutable Certloom layouts. */
final class LayoutSchemaUpgrader {
	/** @var LayoutValidator */
	private $validator;

	public function __construct( ?LayoutValidator $validator = null ) {
		$this->validator = $validator ?: new LayoutValidator();
	}

	/** @return array<string,mixed> */
	public function upgrade( array $layout, $target_version = LayoutSchema::VERSION ) {
		$target_version = (int) $target_version;
		if ( ! LayoutSchema::supports_version( $target_version ) ) {
			throw new InvalidArgumentException( 'Unsupported target layout schema version.' );
		}
		$normalized = $this->validator->normalize( $layout );
		$current = (int) $normalized['_meta']['schema_version'];
		if ( $current > $target_version ) {
			throw new InvalidArgumentException( 'Certloom does not downgrade immutable layout schemas.' );
		}
		if ( $current === $target_version ) {
			return $normalized;
		}
		while ( $current < $target_version ) {
			if ( LayoutSchema::VERSION_V1 === $current ) {
				// v2 is additive: v1 primitives retain identical semantics while Code 128 becomes authorable.
				$normalized['_meta']['schema_version'] = LayoutSchema::VERSION_V2;
				$current = LayoutSchema::VERSION_V2;
				$normalized = $this->validator->normalize( $normalized );
				continue;
			}
			if ( LayoutSchema::VERSION_V2 === $current ) {
				// v3 is additive: v2 primitives retain identical semantics while canonical list elements become authorable.
				$normalized['_meta']['schema_version'] = LayoutSchema::VERSION_V3;
				$current = LayoutSchema::VERSION_V3;
				$normalized = $this->validator->normalize( $normalized );
				continue;
			}
			throw new InvalidArgumentException( 'No deterministic layout upgrade path is available.' );
		}
		return $normalized;
	}
}
