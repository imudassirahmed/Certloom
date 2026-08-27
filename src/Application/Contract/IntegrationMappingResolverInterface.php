<?php
namespace Certloom\Application\Contract;

use Certloom\Application\Integration\IntegrationEventContext;

/** Server-owned source-object → Certloom Program/Template mapping boundary. */
interface IntegrationMappingResolverInterface {
	/** @return array<string,mixed>|null|\WP_Error */
	public function resolve_mapping( IntegrationEventContext $context );
}
