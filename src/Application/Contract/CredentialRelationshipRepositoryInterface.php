<?php
namespace Certloom\Application\Contract;

/** Persistence boundary for immutable Program/Batch relationship corrections. */
interface CredentialRelationshipRepositoryInterface {
	/** @return array<string,mixed>|null|\WP_Error */
	public function find_series( $series_id );

	/** @return array<string,mixed>|null|\WP_Error */
	public function find_series_for_update( $series_id );

	/** @return array<string,mixed>|null|\WP_Error */
	public function find_version_for_update( $version_id );

	/** @return int|\WP_Error */
	public function create_version( $series_id, array $data );

	/** @return true|\WP_Error */
	public function set_current_version( $series_id, $version_id );

	/** @return true|\WP_Error */
	public function set_relationships( $series_id, $expected_program_id, $expected_batch_id, $program_id, $batch_id, $expected_updated_at = null );

	/** @return array<string,mixed>|null|\WP_Error */
	public function validate_relationship_target( $program_id, $batch_id );
}
