<?php
namespace Certloom\Application\Contract;

interface LocalAssetResolverInterface {
	/**
	 * @param string $reference Canonical attachment:ID or asset:key reference.
	 * @return array{path:string,sha256:string,mime:string,size:int}
	 */
	public function resolve( $reference );
}
