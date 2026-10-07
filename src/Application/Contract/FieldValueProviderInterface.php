<?php
namespace Certloom\Application\Contract;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Server-owned sources are resolved independently of submitted issuance values. */
interface FieldValueProviderInterface {
	/** @return array{value:string,status:string,provenance:array}|\WP_Error */
	public function resolve( array $definition, array $policy );
}
