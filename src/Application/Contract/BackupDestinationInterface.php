<?php
namespace Certloom\Application\Contract;

/** Provider-neutral remote destination for already-verified Certloom backup bytes. */
interface BackupDestinationInterface {
	/** @return string Stable provider key. */
	public function provider();

	/** @return true|\WP_Error */
	public function test_connection();

	/** @param array<string,mixed> $local_entry @return array<string,mixed>|\WP_Error */
	public function upload( array $local_entry );

	/** @param array<string,mixed> $remote_entry @return string|\WP_Error Temporary downloaded file path. */
	public function download( array $remote_entry );

	/** @param array<string,mixed> $remote_entry @return true|\WP_Error */
	public function delete( array $remote_entry );
}
