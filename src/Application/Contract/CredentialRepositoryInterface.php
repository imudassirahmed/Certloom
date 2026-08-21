<?php
namespace Certloom\Application\Contract;

/** Persistence operations required by lifecycle commands. */
interface CredentialRepositoryInterface {
	/** @return array<string,mixed>|null|\WP_Error */
	public function find_series_for_update( $series_id );

	/** @return array<string,mixed>|null|\WP_Error */
	public function find_version_for_update( $version_id );

	/** @return true|\WP_Error */
	public function set_administrative_state( $series_id, $expected_state, $new_state );

	/** @return true|\WP_Error */
	public function set_version_issuance_state( $version_id, $expected_state, $new_state );

	/** @return true|\WP_Error */
	public function set_current_version( $series_id, $version_id );
}
