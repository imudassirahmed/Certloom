<?php
namespace Certloom\Application\Contract;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
/** Persistence boundary for resumable complete-domain restore staging. */
interface DomainRestoreWorkspaceInterface {
	/** @return array<string,mixed>|\WP_Error */
	public function create( $owner_token );

	/** @return true|\WP_Error */
	public function insert_row( array $workspace, $entity, array $row );

	/** @return true|\WP_Error */
	public function validate_relationships( array $workspace );

	/** @return true|\WP_Error */
	public function touch( array $workspace );

	/** @return true|\WP_Error */
	public function abandon( array $workspace );

	/** @return true|\WP_Error */
	public function commit_replace( array $workspace, array $configuration, array $receipt = array() );

	/** @return true|\WP_Error */
	public function commit_merge( array $workspace, array $configuration, array $receipt = array() );

	/** Return a replay receipt only after any owner-matched committed cleanup succeeds. @return array<string,mixed>|null|\WP_Error */
	public function completion_receipt( $owner_token );

	/** @return true|\WP_Error */
	public function recover_interrupted_commit();
}
