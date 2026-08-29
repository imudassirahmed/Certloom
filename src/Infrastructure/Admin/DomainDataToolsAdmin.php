<?php
namespace Certloom\Infrastructure\Admin;

use Certloom\Domain\Job\JobStates;
use Certloom\Domain\Job\JobTypes;
use Certloom\Infrastructure\Job\JobRuntime;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Protected 1.5.4 preview UI for the new-domain import/backup/restore pipeline.
 *
 * The screen is deliberately unavailable unless manifest-backed normalized-domain admin authority
 * is enabled. It owns authorization/nonces/upload intent only; all credential
 * work is delegated to application coordinators and durable jobs.
 */
final class DomainDataToolsAdmin {
	const PAGE = 'certloom-domain-data-tools';
	const NOTICE_PREFIX = 'certloom_domain_data_tools_notice_';
	const NOTICE_TTL = 120;

	/** @var JobRuntime */
	private $runtime;

	public function __construct( JobRuntime $runtime ) {
		$this->runtime = $runtime;
	}

	/** @return void */
	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ), 55 );
		add_action( 'admin_post_certloom_domain_backup_start', array( $this, 'backup_start' ) );
		add_action( 'admin_post_certloom_domain_backup_download', array( $this, 'backup_download' ) );
		add_action( 'admin_post_certloom_domain_import_upload', array( $this, 'import_upload' ) );
		add_action( 'admin_post_certloom_domain_import_map', array( $this, 'import_map' ) );
		add_action( 'admin_post_certloom_domain_import_commit', array( $this, 'import_commit' ) );
		add_action( 'admin_post_certloom_domain_restore_upload', array( $this, 'restore_upload' ) );
		add_action( 'admin_post_certloom_domain_restore_commit', array( $this, 'restore_commit' ) );
		add_action( 'admin_post_certloom_domain_job_retry', array( $this, 'job_retry' ) );
		add_action( 'admin_post_certloom_domain_job_pause', array( $this, 'job_pause' ) );
		add_action( 'admin_post_certloom_domain_job_resume', array( $this, 'job_resume' ) );
		add_action( 'admin_post_certloom_domain_job_cancel', array( $this, 'job_cancel' ) );
	}

	/** @return void */
	public function menu() {
		add_submenu_page(
			'certloom',
			__( 'Data Tools (New Domain)', 'certloom' ),
			__( 'Data Tools (New)', 'certloom' ),
			\Ecapla_CertVerify_Permissions::CAP_MANAGE_DATA_TOOLS,
			self::PAGE,
			array( $this, 'render' )
		);
	}

	/** @return void */
	public function render() {
		$this->require_access();
		echo '<div class="wrap"><h1>' . esc_html__( 'Certloom Data Tools — New Domain Preview', 'certloom' ) . '</h1>';
		echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Development preview only. Legacy Certloom 1.5 data remains authoritative. These tools exercise the staged new-domain pipeline but do not enable source-of-truth cutover.', 'certloom' ) . '</p></div>';
		$this->render_notice();
		$this->render_backup();
		$this->render_import();
		$this->render_restore();
		$this->render_jobs();
		echo '</div>';
	}

	/** @return void */
	public function backup_start() {
		$this->require_access();
		check_admin_referer( 'certloom_domain_backup_start' );
		$result = $this->runtime->data_tools()->enqueue_backup( get_current_user_id() );
		$this->redirect_result( $result, __( 'Complete-domain backup queued.', 'certloom' ) );
	}

	/** @return void */
	public function backup_download() {
		$this->require_access();
		$job_key = $this->request_job_key( $_GET['job_key'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce checked below.
		if ( is_wp_error( $job_key ) ) {
			wp_die( esc_html( $job_key->get_error_message() ) );
		}
		check_admin_referer( 'certloom_domain_backup_download_' . $job_key );
		$job = $this->runtime->repository()->find( $job_key );
		if ( is_wp_error( $job ) || ! is_array( $job ) ) {
			wp_die( esc_html__( 'That Certloom backup job is no longer available.', 'certloom' ) );
		}
		if ( JobTypes::DOMAIN_BACKUP !== (string) ( $job['job_type'] ?? '' ) || JobStates::SUCCEEDED !== (string) ( $job['status'] ?? '' ) ) {
			wp_die( esc_html__( 'That Certloom backup has not completed successfully.', 'certloom' ) );
		}
		$path = (string) ( $job['result']['path'] ?? '' );
		if ( ! $this->runtime->private_files()->owns( $path ) || ! is_file( $path ) || ! is_readable( $path ) ) {
			wp_die( esc_html__( 'The protected Certloom backup file is no longer available.', 'certloom' ) );
		}

		$filename = 'certloom-domain-' . gmdate( 'Ymd-His' ) . '.cvbackup';
		nocache_headers();
		header( 'Content-Type: application/octet-stream' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );
		$size = @filesize( $path );
		if ( false !== $size ) {
			header( 'Content-Length: ' . (string) $size );
		}
		$handle = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Authorized private streaming download.
		if ( false === $handle ) {
			wp_die( esc_html__( 'Certloom could not open the protected backup for download.', 'certloom' ) );
		}
		fpassthru( $handle );
		fclose( $handle );
		exit;
	}

	/** @return void */
	public function import_upload() {
		$this->require_access();
		check_admin_referer( 'certloom_domain_import_upload' );
		$file = $this->uploaded_file( 'certloom_import_file' );
		if ( is_wp_error( $file ) ) {
			$this->redirect_result( $file );
		}
		$token = $this->runtime->import_uploads()->create( $file['tmp_name'], $file['name'], get_current_user_id() );
		if ( is_wp_error( $token ) ) {
			$this->redirect_result( $token );
		}
		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE, 'mapping' => $token ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/** @return void */
	public function import_map() {
		$this->require_access();
		$token = $this->request_token( $_POST['mapping_token'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce checked below.
		if ( is_wp_error( $token ) ) {
			$this->redirect_result( $token );
		}
		check_admin_referer( 'certloom_domain_import_map_' . $token );
		$session = $this->runtime->import_uploads()->get( $token, get_current_user_id() );
		if ( is_wp_error( $session ) ) {
			$this->redirect_result( $session );
		}
		$mapping = array();
		$raw = isset( $_POST['mapping'] ) && is_array( $_POST['mapping'] ) ? wp_unslash( $_POST['mapping'] ) : array();
		foreach ( $raw as $column => $target ) {
			$column = absint( $column );
			$target = sanitize_key( (string) $target );
			if ( '' !== $target ) {
				$mapping[ $column ] = $target;
			}
		}
		$options = array(
			'clear_empty'              => ! empty( $_POST['clear_empty'] ),
			'allow_historical_expired' => ! empty( $_POST['allow_historical_expired'] ),
			'strict'                   => empty( $_POST['skip_invalid_rows'] ),
			'original_name'            => (string) $session['original_name'],
		);
		$validated = $this->runtime->import_uploads()->validate_mapping( $token, get_current_user_id(), $mapping, $options );
		if ( is_wp_error( $validated ) ) {
			$this->set_notice( 'error', $validated->get_error_message() );
			wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE, 'mapping' => $token ), admin_url( 'admin.php' ) ) );
			exit;
		}
		$job_key = $this->runtime->imports()->enqueue_preflight_private( (string) $session['path'], get_current_user_id(), $mapping, $options );
		if ( is_wp_error( $job_key ) ) {
			$this->runtime->import_uploads()->consume( $token, get_current_user_id() );
			$this->redirect_result( $job_key );
		}
		$consumed = $this->runtime->import_uploads()->consume( $token, get_current_user_id() );
		if ( is_wp_error( $consumed ) ) {
			$this->set_notice( 'warning', __( 'Import preflight was queued, but Certloom could not clear its temporary mapping session. The protected-file retention job will clean it later.', 'certloom' ) );
		}
		$this->redirect_result( $job_key, __( 'CSV preflight queued. No credential data has been changed.', 'certloom' ) );
	}

	/** @return void */
	public function import_commit() {
		$this->require_access();
		$job_key = $this->request_job_key( $_POST['preflight_job_key'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce checked below.
		if ( is_wp_error( $job_key ) ) {
			$this->redirect_result( $job_key );
		}
		check_admin_referer( 'certloom_domain_import_commit_' . $job_key );
		if ( empty( $_POST['confirm_commit'] ) ) {
			$this->redirect_result( new \WP_Error( 'certloom_import_commit_confirmation_required', __( 'Confirm the reviewed CSV before creating its commit job.', 'certloom' ) ) );
		}
		$policy = isset( $_POST['duplicate_policy'] ) ? sanitize_key( wp_unslash( $_POST['duplicate_policy'] ) ) : 'skip';
		$result = $this->runtime->imports()->enqueue_commit( $job_key, get_current_user_id(), $policy, true );
		$this->redirect_result( $result, __( 'Reviewed CSV commit queued.', 'certloom' ) );
	}

	/** @return void */
	public function restore_upload() {
		$this->require_access();
		check_admin_referer( 'certloom_domain_restore_upload' );
		$file = $this->uploaded_file( 'certloom_restore_file' );
		if ( is_wp_error( $file ) ) {
			$this->redirect_result( $file );
		}
		$mode = isset( $_POST['restore_mode'] ) ? sanitize_key( wp_unslash( $_POST['restore_mode'] ) ) : '';
		if ( empty( $_POST['confirm_review'] ) ) {
			$this->redirect_result( new \WP_Error( 'certloom_domain_restore_review_confirmation_required', __( 'Confirm that Certloom may run the non-destructive restore review.', 'certloom' ) ) );
		}
		$result = $this->runtime->data_tools()->enqueue_restore_review( $file['tmp_name'], $file['name'], get_current_user_id(), $mode, true );
		$this->redirect_result( $result, sprintf( __( '%s restore review queued. No live credential data will be replaced by the review job.', 'certloom' ), ucfirst( $mode ) ) );
	}

	/** @return void */
	public function restore_commit() {
		$this->require_access();
		$job_key = $this->request_job_key( $_POST['preflight_job_key'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce checked below.
		if ( is_wp_error( $job_key ) ) {
			$this->redirect_result( $job_key );
		}
		check_admin_referer( 'certloom_domain_restore_commit_' . $job_key );
		$review = $this->runtime->repository()->find( $job_key );
		if ( is_wp_error( $review ) || ! is_array( $review ) ) {
			$this->redirect_result( is_wp_error( $review ) ? $review : new \WP_Error( 'certloom_domain_restore_review_missing', __( 'That restore review is no longer available.', 'certloom' ) ) );
		}
		$mode = sanitize_key( (string) ( $review['result']['mode'] ?? '' ) );
		$phrase = isset( $_POST['confirm_phrase'] ) ? strtoupper( trim( sanitize_text_field( wp_unslash( $_POST['confirm_phrase'] ) ) ) ) : '';
		$expected = 'replace' === $mode ? 'REPLACE' : ( 'merge' === $mode ? 'MERGE' : '' );
		if ( '' === $expected || ! hash_equals( $expected, $phrase ) ) {
			$this->redirect_result( new \WP_Error( 'certloom_domain_restore_confirmation_phrase_invalid', sprintf( __( 'Type %s exactly to commit this reviewed restore.', 'certloom' ), $expected ?: __( 'the reviewed mode', 'certloom' ) ) ) );
		}
		$result = $this->runtime->data_tools()->enqueue_restore_commit( $job_key, get_current_user_id(), true );
		$this->redirect_result( $result, sprintf( __( '%s restore commit queued. Certloom will restage and revalidate before any atomic promotion.', 'certloom' ), ucfirst( $mode ) ) );
	}

	/** @return void */
	public function job_retry() {
		$this->require_access();
		$job_key = $this->request_job_key( $_POST['job_key'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce checked below.
		if ( is_wp_error( $job_key ) ) {
			$this->redirect_result( $job_key );
		}
		check_admin_referer( 'certloom_domain_job_retry_' . $job_key );

		$job = $this->runtime->repository()->find( $job_key );
		if ( is_wp_error( $job ) || ! is_array( $job ) ) {
			$this->redirect_result( is_wp_error( $job ) ? $job : new \WP_Error( 'certloom_job_retry_missing', __( 'That Certloom job is no longer available.', 'certloom' ) ) );
		}
		if ( JobStates::FAILED !== (string) ( $job['status'] ?? '' ) ) {
			$this->redirect_result( new \WP_Error( 'certloom_job_retry_state_invalid', __( 'Only failed Certloom jobs can be retried.', 'certloom' ) ) );
		}

		$result = $this->runtime->retry_failed( $job_key );
		$this->redirect_result( $result, __( 'Failed Certloom job requeued. The background-job watchdog will recover scheduling automatically if needed.', 'certloom' ) );
	}

	/** @return void */
	public function job_pause() {
		$this->require_access();
		$job_key = $this->request_job_key( $_POST['job_key'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce checked below.
		if ( is_wp_error( $job_key ) ) { $this->redirect_result( $job_key ); }
		check_admin_referer( 'certloom_domain_job_pause_' . $job_key );
		$this->redirect_result( $this->runtime->pause_queued( $job_key ), __( 'Certloom job paused.', 'certloom' ) );
	}

	/** @return void */
	public function job_resume() {
		$this->require_access();
		$job_key = $this->request_job_key( $_POST['job_key'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce checked below.
		if ( is_wp_error( $job_key ) ) { $this->redirect_result( $job_key ); }
		check_admin_referer( 'certloom_domain_job_resume_' . $job_key );
		$this->redirect_result( $this->runtime->resume_paused( $job_key ), __( 'Certloom job resumed.', 'certloom' ) );
	}

	/** @return void */
	public function job_cancel() {
		$this->require_access();
		$job_key = $this->request_job_key( $_POST['job_key'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce checked below.
		if ( is_wp_error( $job_key ) ) { $this->redirect_result( $job_key ); }
		check_admin_referer( 'certloom_domain_job_cancel_' . $job_key );
		if ( empty( $_POST['confirm_cancel'] ) ) {
			$this->redirect_result( new \WP_Error( 'certloom_job_cancel_confirmation_required', __( 'Confirm cancellation of this Certloom job.', 'certloom' ) ) );
		}
		$this->redirect_result( $this->runtime->cancel_non_running( $job_key ), __( 'Certloom job cancelled.', 'certloom' ) );
	}

	/** @return void */
	private function render_backup() {
		echo '<hr><h2>' . esc_html__( 'Complete-domain backup', 'certloom' ) . '</h2>';
		echo '<p>' . esc_html__( 'Creates a resumable integrity-checked backup in Certloom private storage. Download becomes available only after the job succeeds.', 'certloom' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="certloom_domain_backup_start">';
		wp_nonce_field( 'certloom_domain_backup_start' );
		submit_button( __( 'Queue complete backup', 'certloom' ), 'secondary', 'submit', false );
		echo '</form>';
	}

	/** @return void */
	private function render_import() {
		echo '<hr><h2>' . esc_html__( 'CSV import — Preflight first', 'certloom' ) . '</h2>';
		$token = isset( $_GET['mapping'] ) ? $this->request_token( wp_unslash( $_GET['mapping'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only owner-scoped mapping view.
		if ( is_string( $token ) && '' !== $token ) {
			$session = $this->runtime->import_uploads()->get( $token, get_current_user_id() );
			if ( ! is_wp_error( $session ) ) {
				$this->render_mapping_form( $token, $session );
				return;
			}
			$this->error( $session->get_error_message() );
		}
		echo '<p>' . esc_html__( 'Upload is copied immediately into randomized private storage. This step only opens a short-lived field-mapping session; it does not stage or write credentials.', 'certloom' ) . '</p>';
		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="certloom_domain_import_upload">';
		wp_nonce_field( 'certloom_domain_import_upload' );
		echo '<input type="file" name="certloom_import_file" accept=".csv,text/csv" required> ';
		submit_button( __( 'Upload and map CSV', 'certloom' ), 'secondary', 'submit', false );
		echo '</form>';
	}

	/** @param array<string,mixed> $session @return void */
	private function render_mapping_form( $token, array $session ) {
		$choices = $this->runtime->import_uploads()->field_choices();
		echo '<p><strong>' . esc_html( (string) $session['original_name'] ) . '</strong></p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="certloom_domain_import_map"><input type="hidden" name="mapping_token" value="' . esc_attr( $token ) . '">';
		wp_nonce_field( 'certloom_domain_import_map_' . $token );
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'CSV column', 'certloom' ) . '</th><th>' . esc_html__( 'Certloom field', 'certloom' ) . '</th></tr></thead><tbody>';
		foreach ( (array) $session['header'] as $index => $heading ) {
			echo '<tr><td>' . esc_html( (string) $heading ) . '</td><td><select name="mapping[' . esc_attr( (string) $index ) . ']"><option value="">' . esc_html__( 'Ignore column', 'certloom' ) . '</option>';
			foreach ( $choices as $key => $label ) {
				echo '<option value="' . esc_attr( $key ) . '">' . esc_html( $label ) . '</option>';
			}
			echo '</select></td></tr>';
		}
		echo '</tbody></table>';
		echo '<p><label><input type="checkbox" name="clear_empty" value="1"> ' . esc_html__( 'Treat mapped empty optional cells as explicit clears during corrections', 'certloom' ) . '</label></p>';
		echo '<p><label><input type="checkbox" name="allow_historical_expired" value="1"> ' . esc_html__( 'Allow legacy historical Expired compatibility rows', 'certloom' ) . '</label></p>';
		echo '<p><label><input type="checkbox" name="skip_invalid_rows" value="1"> ' . esc_html__( 'Lenient preflight: allow commit-ready rows while invalid rows are skipped and reported', 'certloom' ) . '</label></p>';
		submit_button( __( 'Queue non-writing preflight', 'certloom' ) );
		echo '</form>';
	}

	/** @return void */
	private function render_restore() {
		echo '<hr><h2>' . esc_html__( 'Complete-domain restore', 'certloom' ) . '</h2>';
		echo '<p>' . esc_html__( 'Upload starts a non-destructive review job. Certloom stages and validates the selected mode, shows the resulting summary, then requires a separate explicit commit. Commit restages/revalidates and refuses stale reviews.', 'certloom' ) . '</p>';
		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="certloom_domain_restore_upload">';
		wp_nonce_field( 'certloom_domain_restore_upload' );
		echo '<p><input type="file" name="certloom_restore_file" accept=".cvbackup,application/octet-stream" required></p>';
		echo '<p><label>' . esc_html__( 'Mode', 'certloom' ) . ' <select name="restore_mode"><option value="merge">' . esc_html__( 'Merge — current live history wins', 'certloom' ) . '</option><option value="replace">' . esc_html__( 'Replace — atomic replacement after validation', 'certloom' ) . '</option></select></label></p>';
		echo '<p><label><input type="checkbox" name="confirm_review" value="1" required> ' . esc_html__( 'Run a non-destructive private review of this restore mode', 'certloom' ) . '</label></p>';
		submit_button( __( 'Queue restore review', 'certloom' ), 'secondary' );
		echo '</form>';
	}

	/** @return void */
	private function render_jobs() {
		$jobs = $this->runtime->repository()->recent( 50 );
		echo '<hr><h2>' . esc_html__( 'Recent new-domain jobs', 'certloom' ) . '</h2>';
		if ( is_wp_error( $jobs ) ) {
			$this->error( $jobs->get_error_message() );
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Job', 'certloom' ) . '</th><th>' . esc_html__( 'Type', 'certloom' ) . '</th><th>' . esc_html__( 'Status', 'certloom' ) . '</th><th>' . esc_html__( 'Progress', 'certloom' ) . '</th><th>' . esc_html__( 'Actions', 'certloom' ) . '</th></tr></thead><tbody>';
		foreach ( $jobs as $job ) {
			$this->render_job_row( $job );
		}
		if ( empty( $jobs ) ) {
			echo '<tr><td colspan="5">' . esc_html__( 'No new-domain background jobs yet.', 'certloom' ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/** @param array<string,mixed> $job @return void */
	private function render_job_row( array $job ) {
		$key      = (string) ( $job['job_key'] ?? '' );
		$type     = (string) ( $job['job_type'] ?? '' );
		$status   = (string) ( $job['status'] ?? '' );
		$cursor   = absint( $job['cursor'] ?? 0 );
		$total    = isset( $job['total'] ) ? absint( $job['total'] ) : 0;
		$progress = $total > 0 ? sprintf( '%d / %d', $cursor, $total ) : (string) $cursor;
		echo '<tr><td><code>' . esc_html( substr( $key, 0, 8 ) ) . '…</code></td><td><code>' . esc_html( $type ) . '</code></td><td>' . esc_html( $status ) . '</td><td>' . esc_html( $progress ) . '</td><td>';
		if ( JobStates::QUEUED === $status ) {
			$this->render_job_action( 'certloom_domain_job_pause', 'certloom_domain_job_pause_' . $key, $key, __( 'Pause', 'certloom' ) );
			$this->render_job_action( 'certloom_domain_job_cancel', 'certloom_domain_job_cancel_' . $key, $key, __( 'Cancel', 'certloom' ), true );
		} elseif ( JobStates::PAUSED === $status ) {
			$this->render_job_action( 'certloom_domain_job_resume', 'certloom_domain_job_resume_' . $key, $key, __( 'Resume', 'certloom' ) );
			$this->render_job_action( 'certloom_domain_job_cancel', 'certloom_domain_job_cancel_' . $key, $key, __( 'Cancel', 'certloom' ), true );
		}
		if ( JobTypes::DOMAIN_BACKUP === $type && JobStates::SUCCEEDED === $status ) {
			$url = wp_nonce_url( add_query_arg( array( 'action' => 'certloom_domain_backup_download', 'job_key' => $key ), admin_url( 'admin-post.php' ) ), 'certloom_domain_backup_download_' . $key );
			echo '<a class="button button-small" href="' . esc_url( $url ) . '">' . esc_html__( 'Download', 'certloom' ) . '</a> ';
		}
		if ( JobTypes::DOMAIN_IMPORT_PREFLIGHT === $type && in_array( $status, array( JobStates::SUCCEEDED, JobStates::PARTIALLY_SUCCEEDED ), true ) && ! empty( $job['result']['ready_for_commit'] ) ) {
			echo '<form style="display:inline" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="certloom_domain_import_commit"><input type="hidden" name="preflight_job_key" value="' . esc_attr( $key ) . '">';
			wp_nonce_field( 'certloom_domain_import_commit_' . $key );
			echo '<select name="duplicate_policy"><option value="skip">' . esc_html__( 'Skip existing', 'certloom' ) . '</option><option value="correct">' . esc_html__( 'Create correction versions', 'certloom' ) . '</option></select> ';
			echo '<label><input type="checkbox" name="confirm_commit" value="1" required> ' . esc_html__( 'Confirm commit', 'certloom' ) . '</label> ';
			echo '<button class="button button-small">' . esc_html__( 'Queue commit', 'certloom' ) . '</button></form>';
		}
		if ( JobStates::FAILED === $status ) {
			$error = $this->job_error_summary( (array) ( $job['error'] ?? array() ) );
			if ( '' !== $error ) {
				echo '<div class="description"><strong>' . esc_html__( 'Failure:', 'certloom' ) . '</strong> ' . esc_html( $error ) . '</div>';
			}
			echo '<form style="display:inline" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="certloom_domain_job_retry"><input type="hidden" name="job_key" value="' . esc_attr( $key ) . '">';
			wp_nonce_field( 'certloom_domain_job_retry_' . $key );
			echo '<button class="button button-small">' . esc_html__( 'Retry failed job', 'certloom' ) . '</button></form> ';
			$this->render_job_action( 'certloom_domain_job_cancel', 'certloom_domain_job_cancel_' . $key, $key, __( 'Cancel', 'certloom' ), true );
		}
		if ( JobTypes::DOMAIN_RESTORE_PREFLIGHT === $type && JobStates::SUCCEEDED === $status && ! empty( $job['result']['ready_for_commit'] ) ) {
			$mode = sanitize_key( (string) ( $job['result']['mode'] ?? '' ) );
			$expected = 'replace' === $mode ? 'REPLACE' : 'MERGE';
			$summary = 'merge' === $mode ? (array) ( $job['result']['merge_counts'] ?? array() ) : (array) ( $job['result']['backup_counts'] ?? array() );
			if ( $summary ) {
				echo '<div class="description">' . esc_html( $this->compact_counts( $summary ) ) . '</div>';
			}
			echo '<form style="display:inline" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="certloom_domain_restore_commit"><input type="hidden" name="preflight_job_key" value="' . esc_attr( $key ) . '">';
			wp_nonce_field( 'certloom_domain_restore_commit_' . $key );
			echo '<label>' . esc_html( sprintf( __( 'Type %s to commit: ', 'certloom' ), $expected ) ) . '<input name="confirm_phrase" autocomplete="off" required></label> ';
			echo '<button class="button button-small">' . esc_html__( 'Queue restore commit', 'certloom' ) . '</button></form>';
		}
		echo '</td></tr>';
	}

	/** @return void */
	private function render_job_action( $action, $nonce_action, $job_key, $label, $confirm = false ) {
		echo '<form style="display:inline" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="' . esc_attr( $action ) . '"><input type="hidden" name="job_key" value="' . esc_attr( $job_key ) . '">';
		wp_nonce_field( $nonce_action );
		if ( $confirm ) {
			echo '<label><input type="checkbox" name="confirm_cancel" value="1" required> ' . esc_html__( 'Confirm', 'certloom' ) . '</label> ';
		}
		echo '<button class="button button-small">' . esc_html( $label ) . '</button></form> ';
	}

	/**
	 * Produces a bounded, protected-admin failure summary without exposing raw
	 * payload/result data or private paths.
	 *
	 * @param array<string,mixed> $error
	 * @return string
	 */
	private function job_error_summary( array $error ) {
		$code    = sanitize_key( (string) ( $error['code'] ?? '' ) );
		$message = sanitize_text_field( (string) ( $error['message'] ?? '' ) );
		if ( function_exists( 'mb_substr' ) ) {
			$message = mb_substr( $message, 0, 220 );
		} else {
			$message = substr( $message, 0, 220 );
		}
		if ( '' === $message ) {
			$message = __( 'The background job stopped before completion. Review the job and retry it when the underlying problem is resolved.', 'certloom' );
		}
		return '' !== $code ? $code . ' — ' . $message : $message;
	}

	/** @param array<string,mixed> $counts @return string */
	private function compact_counts( array $counts ) {
		$parts = array();
		foreach ( $counts as $key => $value ) {
			if ( is_scalar( $value ) ) {
				$parts[] = sanitize_key( (string) $key ) . ': ' . absint( $value );
			}
		}
		return implode( ' · ', $parts );
	}

	/** @return array<string,mixed>|\WP_Error */
	private function uploaded_file( $key ) {
		if ( empty( $_FILES[ $key ] ) || ! is_array( $_FILES[ $key ] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validated structurally below; tmp path is never rendered.
			return new \WP_Error( 'certloom_data_tools_upload_missing', __( 'Choose a file to upload.', 'certloom' ) );
		}
		$file = $_FILES[ $key ]; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- PHP upload metadata validated before private copy.
		if ( UPLOAD_ERR_OK !== absint( $file['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
			return new \WP_Error( 'certloom_data_tools_upload_failed', __( 'The file upload did not complete successfully.', 'certloom' ) );
		}
		$tmp = (string) ( $file['tmp_name'] ?? '' );
		if ( '' === $tmp || ! is_uploaded_file( $tmp ) || ! is_file( $tmp ) || ! is_readable( $tmp ) ) {
			return new \WP_Error( 'certloom_data_tools_upload_invalid', __( 'Certloom could not verify the uploaded temporary file.', 'certloom' ) );
		}
		return array( 'tmp_name' => $tmp, 'name' => sanitize_file_name( (string) ( $file['name'] ?? '' ) ) );
	}

	/** @return string|\WP_Error */
	private function request_job_key( $value ) {
		$value = strtolower( trim( sanitize_text_field( wp_unslash( (string) $value ) ) ) );
		return preg_match( '/^[a-f0-9]{32}$/', $value ) ? $value : new \WP_Error( 'certloom_job_key_invalid', __( 'The Certloom job identifier is invalid.', 'certloom' ) );
	}

	/** @return string|\WP_Error */
	private function request_token( $value ) {
		$value = strtolower( trim( sanitize_text_field( wp_unslash( (string) $value ) ) ) );
		return preg_match( '/^[a-f0-9]{32}$/', $value ) ? $value : new \WP_Error( 'certloom_mapping_token_invalid', __( 'The CSV mapping-session identifier is invalid.', 'certloom' ) );
	}

	/** @return void */
	private function require_access() {
		if ( ! current_user_can( \Ecapla_CertVerify_Permissions::CAP_MANAGE_DATA_TOOLS ) ) {
			wp_die( esc_html__( 'You do not have permission to use Certloom data tools.', 'certloom' ) );
		}
	}

	/** @return void */
	private function redirect_result( $result, $success_message = '' ) {
		if ( is_wp_error( $result ) ) {
			$this->set_notice( 'error', $result->get_error_message() );
		} elseif ( '' !== $success_message ) {
			$this->set_notice( 'success', $success_message );
		}
		wp_safe_redirect( add_query_arg( 'page', self::PAGE, admin_url( 'admin.php' ) ) );
		exit;
	}

	/** @return void */
	private function set_notice( $type, $message ) {
		$type = in_array( $type, array( 'success', 'error', 'warning' ), true ) ? $type : 'warning';
		set_transient( self::NOTICE_PREFIX . get_current_user_id(), array( 'type' => $type, 'message' => sanitize_text_field( (string) $message ) ), self::NOTICE_TTL );
	}

	/** @return void */
	private function render_notice() {
		$key = self::NOTICE_PREFIX . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! is_array( $notice ) ) { return; }
		delete_transient( $key );
		$type = in_array( (string) ( $notice['type'] ?? '' ), array( 'success', 'error', 'warning' ), true ) ? (string) $notice['type'] : 'warning';
		echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( (string) ( $notice['message'] ?? '' ) ) . '</p></div>';
	}

	/** @return void */
	private function error( $message ) {
		echo '<div class="notice notice-error inline"><p>' . esc_html( (string) $message ) . '</p></div>';
	}
}
