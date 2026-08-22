<?php
namespace Certloom\Application\Migration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Prevents the retired 1.5 certificate table from becoming a second mutable
 * source of truth after final domain cutover.
 *
 * The guard is dormant while legacy authority is active. Once cutover occurs,
 * old certificate CRUD/import paths must fail closed and the new registry is
 * the only allowed credential write surface.
 */
final class LegacyRegistryWriteGuard {
	/** @return true|\WP_Error */
	public function assert_allowed() {
		if ( ( new DomainCutoverState() )->is_domain_authoritative() ) {
			return new \WP_Error(
				'certloom_legacy_registry_read_only',
				__( 'The legacy Certloom 1.5 certificate registry is read-only after the new credential domain becomes authoritative.', 'certloom' )
			);
		}
		return true;
	}
}
