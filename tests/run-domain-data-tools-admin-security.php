<?php
/** Static release guard for protected data-tool controller boundaries. */
$source = file_get_contents( dirname( __DIR__ ) . '/src/Infrastructure/Admin/DomainDataToolsAdmin.php' );
if ( false === $source ) { throw new RuntimeException( 'Could not read DomainDataToolsAdmin.' ); }
$actions = array(
	'backup_start'    => 'certloom_domain_backup_start',
	'backup_download' => 'certloom_domain_backup_download_',
	'import_upload'   => 'certloom_domain_import_upload',
	'import_map'      => 'certloom_domain_import_map_',
	'import_commit'   => 'certloom_domain_import_commit_',
	'restore_upload'  => 'certloom_domain_restore_upload',
	'restore_commit'  => 'certloom_domain_restore_commit_',
	'job_retry'       => 'certloom_domain_job_retry_',
	'job_pause'       => 'certloom_domain_job_pause_',
	'job_resume'      => 'certloom_domain_job_resume_',
	'job_cancel'      => 'certloom_domain_job_cancel_',
);
foreach ( $actions as $method => $nonce ) {
	$pattern = '/public function ' . preg_quote( $method, '/' ) . '\(\)\s*\{(.*?)(?=\n\t\/\*\*|\n\tpublic function|\n\tprivate function)/s';
	if ( ! preg_match( $pattern, $source, $matches ) ) { throw new RuntimeException( 'Could not inspect data-tool action ' . $method ); }
	$body = $matches[1];
	if ( false === strpos( $body, '$this->require_access();' ) ) { throw new RuntimeException( $method . ' lost its capability gate.' ); }
	if ( false === strpos( $body, 'check_admin_referer(' ) || false === strpos( $body, $nonce ) ) { throw new RuntimeException( $method . ' lost its nonce gate.' ); }
}
if ( false !== strpos( $source, 'wp_ajax_nopriv' ) ) { throw new RuntimeException( 'Data tools must never expose logged-out mutation endpoints.' ); }
if ( false === strpos( $source, 'is_uploaded_file' ) ) { throw new RuntimeException( 'Data tools no longer verify real PHP uploads before private copy.' ); }
if ( false === strpos( $source, "CAP_MANAGE_DATA_TOOLS" ) ) { throw new RuntimeException( 'Dedicated data-tools capability was removed.' ); }
if ( false === strpos( $source, 'confirm_commit' ) || false === strpos( $source, 'confirm_phrase' ) ) { throw new RuntimeException( 'Explicit import/restore commit confirmations were removed.' ); }
if ( false === strpos( $source, 'Retry failed job' ) || false === strpos( $source, 'job_error_summary' ) ) { throw new RuntimeException( 'Protected failed-job diagnostics/retry UI was removed.' ); }
if ( false === strpos( $source, 'confirm_cancel' ) || false === strpos( $source, 'render_job_action' ) ) { throw new RuntimeException( 'Pause/resume/cancel operational controls were removed.' ); }
echo "PASS  every new-domain data-tool endpoint remains capability-gated, nonce-gated, upload-validated and explicitly confirmed\n";
echo "\n1 test, 0 failures\n";
