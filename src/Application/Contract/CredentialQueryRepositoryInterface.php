<?php
namespace Certloom\Application\Contract;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/** Read model required by public credential verification. */
interface CredentialQueryRepositoryInterface {
	/**
	 * Returns one Credential Series joined to its current immutable Version.
	 *
	 * The public credential ID must bind directly to the Series. Implementations
	 * must not resolve a recipient first and then infer a program/certificate from
	 * request context.
	 *
	 * @param string $public_id Normalized checksummed public credential ID.
	 * @return array<string,mixed>|null|\WP_Error
	 */
	public function find_current_by_public_id( $public_id );
}
