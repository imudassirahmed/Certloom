<?php
namespace Certloom\Infrastructure\Admin;

use Certloom\Application\Program\ProgramLifecyclePolicy;
use Certloom\Domain\Job\JobStates;
use Certloom\Domain\Job\JobTypes;
use Certloom\Infrastructure\Job\JobRuntime;
use Certloom\Infrastructure\Repository\BatchRepository;
use Certloom\Infrastructure\Repository\ProgramRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Premium reviewed CSV → durable background Bulk Issuance workflow. */
final class BulkIssuanceAdmin {
	const PAGE        = 'certloom-bulk-issuance';
	const INTAKE_PAGE = 'certloom-file-intake';
	const NOTICE = 'certloom_bulk_notice_';

	/** @var JobRuntime */
	private $runtime;

	public function __construct( JobRuntime $runtime ) {
		$this->runtime = $runtime;
	}

	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ), 56 );
		add_action( 'admin_post_certloom_bulk_issuance_upload', array( $this, 'upload' ) );
		add_action( 'admin_post_certloom_bulk_issuance_commit', array( $this, 'commit' ) );
	}

	public function menu() {
		add_submenu_page(
			null,
			__( 'Bulk Issuance', 'certloom' ),
			__( 'Bulk Issuance', 'certloom' ),
			\Ecapla_CertVerify_Permissions::CAP_MANAGE_CREDENTIALS,
			self::PAGE,
			array( $this, 'render' )
		);
		add_submenu_page(
			null,
			__( 'Import / issue from file', 'certloom' ),
			__( 'Import / issue from file', 'certloom' ),
			\Ecapla_CertVerify_Permissions::CAP_MANAGE_CREDENTIALS,
			self::INTAKE_PAGE,
			array( $this, 'render_intake' )
		);
	}


	/** Phase 2 unified file-workflow entry. Existing import/issuance engines remain authoritative behind it. */
	public function render_intake() {
		$this->cap();
		$issue_url  = add_query_arg( 'page', self::PAGE, admin_url( 'admin.php' ) );
		$import_url = admin_url( 'admin.php?page=certloom-import#certloom-csv-import' );

		echo '<div class="wrap ecapla-certverify-admin-wrap certloom-admin-shell certloom-file-intake">';
		echo '<div class="certloom-page-head"><div><h1>' . esc_html__( 'Import / issue from file', 'certloom' ) . '</h1><p>' . esc_html__( 'Start with what the rows mean. Both paths use CSV review before records are changed.', 'certloom' ) . '</p></div></div>';
		echo '<div class="certloom-file-intake-options" role="list">';
		echo '<a class="certloom-file-intake-option" role="listitem" href="' . esc_url( $issue_url ) . '"><strong>' . esc_html__( 'Issue new credentials', 'certloom' ) . '</strong><span>' . esc_html__( 'Use recipient rows to create new credentials after review. The current alpha still uses the proven Program + design bulk engine behind this entry while standalone/presentation authority is implemented later.', 'certloom' ) . '</span></a>';
		echo '<a class="certloom-file-intake-option" role="listitem" href="' . esc_url( $import_url ) . '"><strong>' . esc_html__( 'Import existing credential records', 'certloom' ) . '</strong><span>' . esc_html__( 'Bring existing certificate records into Certloom, map the detected columns, preview sample rows, then confirm the reviewed import.', 'certloom' ) . '</span></a>';
		echo '</div>';
		echo '<p class="certloom-file-intake-note">' . esc_html__( '1.7 will converge these paths on one reusable profile-aware mapper. This UI does not pretend that backend convergence is already complete.', 'certloom' ) . '</p>';
		echo '</div>';
	}

	public function render() {
		$this->cap();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only route state.
		$job_key = isset( $_GET['bulk_job'] ) ? strtolower( sanitize_text_field( wp_unslash( $_GET['bulk_job'] ) ) ) : '';

		echo '<div class="wrap ecapla-certverify-admin-wrap certloom-admin-shell certloom-bulk-issuance">';
		echo '<div class="certloom-page-head"><div><span class="certloom-eyebrow">' . esc_html__( 'Premium issuance workflow', 'certloom' ) . '</span><h1>' . esc_html__( 'Bulk Issuance', 'certloom' ) . '</h1><p>' . esc_html__( 'Review the entire CSV before any credential is created, then issue only the accepted rows through the same checks used for individual issuance.', 'certloom' ) . '</p></div><span class="certloom-domain-preview-badge">' . esc_html__( 'Credential preview', 'certloom' ) . '</span></div>';

		$this->notice();
		echo '<div class="notice notice-warning inline"><p><strong>' . esc_html__( 'Availability:', 'certloom' ) . '</strong> ' . esc_html__( 'Bulk Issuance is available in the credential-management preview. Public verification from the new credential store and PDF/PNG/JPEG exports are not enabled in this build yet.', 'certloom' ) . '</p></div>';

		if ( '' === $job_key ) {
			$this->form();
			echo '</div>';
			return;
		}

		$job = $this->runtime->bulk_issuance()->owned_job( $job_key, get_current_user_id() );
		if ( is_wp_error( $job ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $job->get_error_message() ) . '</p></div>';
			$this->form();
		} else {
			$this->job( $job );
		}

		echo '</div>';
	}

	public function upload() {
		$this->cap();
		check_admin_referer( 'certloom_bulk_issuance_upload' );

		if ( empty( $_FILES['bulk_csv'] ) || ! is_array( $_FILES['bulk_csv'] ) ) {
			$this->fail( __( 'Choose a CSV file before starting Bulk Issuance.', 'certloom' ) );
		}

		$file = $_FILES['bulk_csv'];
		if ( UPLOAD_ERR_OK !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
			$this->fail( __( 'The Bulk Issuance CSV upload did not complete successfully.', 'certloom' ) );
		}

		$tmp = (string) ( $file['tmp_name'] ?? '' );
		if ( '' === $tmp || ! is_file( $tmp ) ) {
			$this->fail( __( 'The temporary Bulk Issuance upload is unavailable.', 'certloom' ) );
		}

		$shared = array(
			'program_id'  => $_POST['program_id'] ?? 0,
			'batch_id'    => $_POST['batch_id'] ?? 0,
			'template_id' => $_POST['template_id'] ?? 0,
			'valid_from'  => isset( $_POST['valid_from'] ) ? wp_unslash( $_POST['valid_from'] ) : '',
			'expiry_mode' => isset( $_POST['expiry_mode'] ) ? wp_unslash( $_POST['expiry_mode'] ) : 'program',
			'expires_at'  => isset( $_POST['expires_at'] ) ? wp_unslash( $_POST['expires_at'] ) : '',
			'grade'       => isset( $_POST['grade'] ) ? wp_unslash( $_POST['grade'] ) : '',
		);
		$shared_policy = $this->validate_shared_lifecycle( $shared );
		if ( is_wp_error( $shared_policy ) ) {
			$this->fail( $shared_policy->get_error_message() );
		}

		$key = $this->runtime->bulk_issuance()->enqueue_preflight(
			$tmp,
			(string) ( $file['name'] ?? 'bulk.csv' ),
			get_current_user_id(),
			$shared
		);
		if ( is_wp_error( $key ) ) {
			$this->fail( $key->get_error_message() );
		}

		$this->success( __( 'Bulk Issuance review started. No credential has been created yet.', 'certloom' ) );
		$this->go( $key );
	}

	public function commit() {
		$this->cap();

		$pre = isset( $_POST['preflight_job_key'] ) ? strtolower( sanitize_text_field( wp_unslash( $_POST['preflight_job_key'] ) ) ) : '';
		$fp  = isset( $_POST['review_fingerprint'] ) ? strtolower( sanitize_text_field( wp_unslash( $_POST['review_fingerprint'] ) ) ) : '';

		check_admin_referer( 'certloom_bulk_issuance_commit_' . $fp );
		$key = $this->runtime->bulk_issuance()->enqueue_commit( $pre, get_current_user_id(), $fp, true );
		if ( is_wp_error( $key ) ) {
			$this->fail( $key->get_error_message(), $pre );
		}

		$this->success( __( 'Bulk Issuance started. Each row is checked independently and can be retried safely without creating duplicates.', 'certloom' ) );
		$this->go( $key );
	}

	private function form() {
		$context_choices = new ScopedContextChoices();
		$programs        = $context_choices->programs();
		$templates       = $context_choices->templates();
		$p               = is_wp_error( $programs ) ? array() : (array) $programs;
		$t               = is_wp_error( $templates ) ? array() : (array) $templates;
		$context_nonce   = wp_create_nonce( ScopedFieldAdminAjax::NONCE_ACTION );

		echo '<section class="certloom-manual-card certloom-bulk-card"><div class="certloom-manual-card-head"><div><span class="certloom-eyebrow">' . esc_html__( 'Step 1', 'certloom' ) . '</span><h2>' . esc_html__( 'Upload & define shared issuance context', 'certloom' ) . '</h2></div><span class="certloom-template-state">' . esc_html__( 'Review before issuing', 'certloom' ) . '</span></div>';
		echo '<p>' . esc_html__( 'Choose the Program, optional Batch, dates and expiry policy. The normal design resolves through Global → Issuer → Program → Batch; you can still choose a one-off design override for this bulk job. Certloom validates the same field and design context again for every CSV row.', 'certloom' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" enctype="multipart/form-data" class="certloom-manual-form" data-certloom-scoped-fields-form data-certloom-scoped-fields-nonce="' . esc_attr( $context_nonce ) . '" data-certloom-bulk-lifecycle><input type="hidden" name="action" value="certloom_bulk_issuance_upload">';
		wp_nonce_field( 'certloom_bulk_issuance_upload' );

		echo '<div class="certloom-manual-grid certloom-manual-grid-2">';
		echo '<label><span>' . esc_html__( 'Active Program', 'certloom' ) . '</span><select name="program_id" required data-certloom-context-lookup="program" data-certloom-context-nonce="' . esc_attr( $context_nonce ) . '" data-certloom-context-search-placeholder="' . esc_attr__( 'Search active Programs…', 'certloom' ) . '"><option value="">' . esc_html__( 'Choose Program', 'certloom' ) . '</option>';
		foreach ( $p as $program ) {
			echo '<option value="' . esc_attr( (string) absint( $program['id'] ?? 0 ) ) . '" data-program-title="' . esc_attr( (string) ( $program['title'] ?? '' ) ) . '" data-validity-mode="' . esc_attr( (string) ( $program['validity_mode'] ?? '' ) ) . '" data-validity-value="' . esc_attr( (string) absint( $program['validity_value'] ?? 0 ) ) . '" data-validity-unit="' . esc_attr( (string) ( $program['validity_unit'] ?? 'days' ) ) . '" data-program-validity-available="' . esc_attr( ! empty( $program['program_default_available'] ) ? '1' : '0' ) . '" data-validity-summary="' . esc_attr( (string) ( $program['validity_summary'] ?? '' ) ) . '" data-renewal-policy="' . esc_attr( (string) ( $program['renewal'] ?? 'not_applicable' ) ) . '" data-repeat-policy="' . esc_attr( (string) ( $program['repeat_policy'] ?? ProgramLifecyclePolicy::REPEAT_LIFECYCLE ) ) . '">' . esc_html( ScopedContextChoices::program_label( $program ) ) . '</option>';
		}
		echo '</select><small>' . esc_html__( 'Searches the complete active Program registry without loading every Program into this page.', 'certloom' ) . '</small></label>';

		echo '<label><span>' . esc_html__( 'Batch / cohort', 'certloom' ) . '</span><select name="batch_id" data-certloom-context-lookup="batch" data-certloom-context-nonce="' . esc_attr( $context_nonce ) . '" data-certloom-context-search-placeholder="' . esc_attr__( 'Search Batches in this Program…', 'certloom' ) . '"><option value="0">' . esc_html__( 'No Batch', 'certloom' ) . '</option></select><small data-certloom-bulk-batch-policy>' . esc_html__( 'Optional unless the selected Program issues one credential per Batch or session. Search is limited to the selected Program.', 'certloom' ) . '</small></label>';

		echo '<div class="certloom-bulk-design-source" data-certloom-bulk-design-source><span class="certloom-bulk-design-label">' . esc_html__( 'Certificate design', 'certloom' ) . '</span><div class="certloom-manual-choice-row"><label><input type="radio" name="bulk_design_source" value="inherited" checked> ' . esc_html__( 'Use inherited design', 'certloom' ) . '</label><label><input type="radio" name="bulk_design_source" value="assigned"> ' . esc_html__( 'Use assigned design', 'certloom' ) . '</label></div><label data-certloom-bulk-assigned-design hidden><span>' . esc_html__( 'Assigned design', 'certloom' ) . '</span><select name="template_id" data-certloom-context-lookup="template" data-certloom-context-empty-value="0" data-certloom-context-nonce="' . esc_attr( $context_nonce ) . '" data-certloom-context-search-placeholder="' . esc_attr__( 'Search published Templates…', 'certloom' ) . '"><option value="0">' . esc_html__( 'Choose published design…', 'certloom' ) . '</option>';
		foreach ( $t as $template ) {
			echo '<option value="' . esc_attr( (string) absint( $template['id'] ?? 0 ) ) . '">' . esc_html( ScopedContextChoices::template_label( $template ) ) . '</option>';
		}
		echo '</select></label><small data-certloom-bulk-design-source-status>' . esc_html__( 'Inherited design follows Global → Issuer → Program → Batch. Certloom will show the effective source after you choose a Program.', 'certloom' ) . '</small></div>';
		echo '<label><span>' . esc_html__( 'Effective date', 'certloom' ) . '</span><input type="date" name="valid_from" required value="' . esc_attr( gmdate( 'Y-m-d' ) ) . '"></label>';
		echo '<label><span>' . esc_html__( 'Default grade (optional)', 'certloom' ) . '</span><input type="text" maxlength="191" name="grade"></label>';
		echo '</div>';

		echo '<div class="certloom-manual-choice-row" data-certloom-bulk-expiry-policy>';
		echo '<label><input type="radio" name="expiry_mode" value="program" checked required> ' . esc_html__( 'Use Program validity', 'certloom' ) . '</label>';
		echo '<label><input type="radio" name="expiry_mode" value="permanent"> ' . esc_html__( 'Permanent', 'certloom' ) . '</label>';
		echo '<label><input type="radio" name="expiry_mode" value="date"> ' . esc_html__( 'Specific expiry', 'certloom' ) . '</label>';
		echo '</div>';
		echo '<p class="description" data-certloom-bulk-validity-summary>' . esc_html__( 'Choose a Program to confirm its default validity and repeat-issuance policy.', 'certloom' ) . '</p>';
		echo '<label class="certloom-bulk-expiry"><span>' . esc_html__( 'Specific expiry date', 'certloom' ) . '</span><input type="date" name="expires_at"></label>';

		echo '<div class="certloom-bulk-csv"><label><span>' . esc_html__( 'Bulk Issuance CSV', 'certloom' ) . '</span><input type="file" name="bulk_csv" accept=".csv,text/csv" required></label><div class="certloom-bulk-schema" data-certloom-bulk-field-schema><strong>' . esc_html__( 'Accepted columns', 'certloom' ) . '</strong><div data-certloom-bulk-field-schema-body><code>recipient_id</code> <em>' . esc_html__( 'or', 'certloom' ) . '</em> <code>display_name</code> + <code>email</code>/<code>external_id</code>, <code>first_name</code>, <code>middle_name</code>, <code>last_name</code>, <code>grade</code>, <code>certificate_number</code>, <code>custom:machine_key</code>.</div><small>' . esc_html__( 'Choose a Program and optional Batch to see the exact credential-field columns before uploading.', 'certloom' ) . '</small></div></div>';
		echo '<div class="certloom-manual-actions"><button class="button button-primary button-hero" type="submit" ' . ( empty( $p ) ? 'disabled' : '' ) . '>' . esc_html__( 'Review CSV', 'certloom' ) . '</button><span>' . esc_html__( 'Certloom reviews the file in the background. No credentials are created during review.', 'certloom' ) . '</span></div>';
		echo '</form></section>';
	}

	/** Fail fast on shared Program lifecycle choices before queueing a CSV review. @return true|\WP_Error */
	private function validate_shared_lifecycle( array $shared ) {
		$program_id = absint( $shared['program_id'] ?? 0 );
		if ( ! $program_id ) { return new \WP_Error( 'certloom_bulk_program_required', __( 'Choose an active Program before reviewing the CSV.', 'certloom' ) ); }
		$program = ( new ProgramRepository() )->find( $program_id );
		if ( is_wp_error( $program ) ) { return $program; }
		if ( ! is_array( $program ) || empty( $program['active'] ) ) { return new \WP_Error( 'certloom_bulk_program_unavailable', __( 'Choose an active Program that still exists.', 'certloom' ) ); }
		$policy = ( new ProgramLifecyclePolicy() )->resolve( $program );
		if ( is_wp_error( $policy ) ) { return $policy; }

		$batch_id = absint( $shared['batch_id'] ?? 0 );
		if ( ProgramLifecyclePolicy::REPEAT_BATCH === (string) ( $policy['repeat_policy'] ?? '' ) && ! $batch_id ) {
			return new \WP_Error( 'certloom_bulk_repeat_batch_required', __( 'This Program issues one credential per Batch or session. Choose a Batch before reviewing the CSV.', 'certloom' ) );
		}
		if ( $batch_id ) {
			$batch = ( new BatchRepository() )->find( $batch_id );
			if ( is_wp_error( $batch ) ) { return $batch; }
			if ( ! is_array( $batch ) || empty( $batch['active'] ) || $program_id !== absint( $batch['program_id'] ?? 0 ) ) {
				return new \WP_Error( 'certloom_bulk_batch_program_mismatch', __( 'Choose an active Batch that belongs to the selected Program.', 'certloom' ) );
			}
		}

		$expiry_mode = sanitize_key( (string) ( $shared['expiry_mode'] ?? '' ) );
		if ( ! in_array( $expiry_mode, array( 'program', 'permanent', 'date' ), true ) ) {
			return new \WP_Error( 'certloom_bulk_expiry_required', __( 'Choose how expiry should be set for this bulk issuance.', 'certloom' ) );
		}
		if ( 'program' === $expiry_mode && empty( $policy['program_default_available'] ) ) {
			return new \WP_Error( 'certloom_bulk_program_expiry_unavailable', __( 'This Program decides validity at issuance. Choose Permanent or a specific expiry date for this bulk job.', 'certloom' ) );
		}
		if ( 'permanent' === $expiry_mode && 'required' === (string) ( $policy['renewal'] ?? '' ) ) {
			return new \WP_Error( 'certloom_bulk_renewal_requires_expiry', __( 'This Program requires renewal after expiry, so the bulk job must use the Program expiry or a specific expiry date.', 'certloom' ) );
		}
		return true;
	}

	private function job( array $job ) {
		$type    = (string) ( $job['job_type'] ?? '' );
		$status  = (string) ( $job['status'] ?? '' );
		$result  = is_array( $job['result'] ?? null ) ? $job['result'] : array();
		$summary = is_array( $result['summary'] ?? null ) ? $result['summary'] : array();

		echo '<section class="certloom-manual-card certloom-bulk-card">';
		echo '<div class="certloom-manual-card-head"><div><span class="certloom-eyebrow">' . esc_html( JobTypes::BULK_ISSUANCE_PREFLIGHT === $type ? __( 'Step 2', 'certloom' ) : __( 'Step 3', 'certloom' ) ) . '</span><h2>' . esc_html( JobTypes::BULK_ISSUANCE_PREFLIGHT === $type ? __( 'Bulk review', 'certloom' ) : __( 'Bulk issuance progress', 'certloom' ) ) . '</h2></div><span class="certloom-bulk-status is-' . esc_attr( sanitize_html_class( $status ) ) . '">' . esc_html( str_replace( '_', ' ', ucfirst( $status ) ) ) . '</span></div>';
		$this->metrics( $type, $summary );

		if ( ! empty( $summary['error_details'] ) ) {
			echo '<div class="notice notice-error inline"><p><strong>' . esc_html__( 'Row issues', 'certloom' ) . '</strong></p><ol class="certloom-bulk-errors">';
			foreach ( array_slice( (array) $summary['error_details'], 0, 25 ) as $error ) {
				$row_label = sprintf( /* translators: %d: CSV row number. */ __( 'Row %d', 'certloom' ), absint( $error['row'] ?? 0 ) );
				echo '<li><strong>' . esc_html( $row_label ) . ':</strong> ' . esc_html( (string) ( $error['message'] ?? '' ) ) . '</li>';
			}
			echo '</ol></div>';
		}

		if ( in_array( $status, array( JobStates::QUEUED, JobStates::RUNNING ), true ) ) {
			echo '<p>' . esc_html__( 'Certloom is still processing this batch. Refresh to see the latest progress.', 'certloom' ) . '</p>';
			echo '<a class="button button-primary" href="' . esc_url( add_query_arg( array( 'page' => self::PAGE, 'bulk_job' => (string) $job['job_key'] ), admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'Refresh progress', 'certloom' ) . '</a>';
		} elseif ( JobTypes::BULK_ISSUANCE_PREFLIGHT === $type && ! empty( $result['ready_for_issue'] ) ) {
			$this->render_ready_review( $job, $result );
		} elseif ( JobTypes::BULK_ISSUANCE_PREFLIGHT === $type ) {
			echo '<p><strong>' . esc_html__( 'Nothing was issued.', 'certloom' ) . '</strong> ' . esc_html__( 'Correct the blocking CSV rows and upload a new review.', 'certloom' ) . '</p>';
			echo '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ) . '">' . esc_html__( 'Start new review', 'certloom' ) . '</a>';
		} else {
			echo '<p><strong>' . esc_html__( 'Bulk issuance complete.', 'certloom' ) . '</strong> ' . esc_html__( 'Credential Management now contains every successfully issued credential. You can continue with Email Delivery or the Recipient Portal when needed.', 'certloom' ) . '</p>';
			echo '<a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=certloom-credentials' ) ) . '">' . esc_html__( 'Open Credential Management', 'certloom' ) . '</a> ';
			echo '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ) . '">' . esc_html__( 'Issue another batch', 'certloom' ) . '</a>';
		}

		echo '</section>';
	}

	private function render_ready_review( array $job, array $result ) {
		$fp = (string) $result['review_fingerprint'];

		echo '<details class="certloom-technical-details"><summary>' . esc_html__( 'Technical details', 'certloom' ) . '</summary><p class="description">' . esc_html__( 'These integrity identifiers are checked automatically before issuance and are available for support or auditing.', 'certloom' ) . '</p><div class="certloom-template-preview-proof"><div><span>' . esc_html__( 'Reviewed batch fingerprint', 'certloom' ) . '</span><code data-certloom-batch-fingerprint>' . esc_html( $fp ) . '</code></div><div><span>' . esc_html__( 'Issuance plan fingerprint', 'certloom' ) . '</span><code data-certloom-plan-fingerprint>' . esc_html( (string) ( $result['plan_sha256'] ?? '' ) ) . '</code></div></div></details>';
		echo '<div class="notice notice-info inline"><p>' . esc_html__( 'When you continue, Certloom checks every row again before creating a credential. If a reviewed row has changed, that row stops safely instead of using outdated data.', 'certloom' ) . '</p></div>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="certloom_bulk_issuance_commit"><input type="hidden" name="preflight_job_key" value="' . esc_attr( (string) $job['job_key'] ) . '"><input type="hidden" name="review_fingerprint" value="' . esc_attr( $fp ) . '">';
		wp_nonce_field( 'certloom_bulk_issuance_commit_' . $fp );
		echo '<button class="button button-primary button-hero" type="submit">' . esc_html__( 'Issue reviewed batch', 'certloom' ) . '</button></form>';
	}

	private function metrics( $type, array $summary ) {
		if ( JobTypes::BULK_ISSUANCE_PREFLIGHT === $type ) {
			$items = array(
				__( 'Processed', 'certloom' ) => absint( $summary['processed'] ?? 0 ),
				__( 'Ready', 'certloom' )     => absint( $summary['ready'] ?? 0 ),
				__( 'Errors', 'certloom' )    => absint( $summary['errors'] ?? 0 ),
			);
		} else {
			$items = array(
				__( 'Processed', 'certloom' )   => absint( $summary['processed'] ?? 0 ),
				__( 'Issued', 'certloom' )      => absint( $summary['succeeded'] ?? 0 ),
				__( 'Failed', 'certloom' )      => absint( $summary['failed'] ?? 0 ),
				__( 'Safe retries', 'certloom' ) => absint( $summary['idempotent_replays'] ?? 0 ),
			);
		}

		echo '<div class="certloom-bulk-metrics">';
		foreach ( $items as $label => $value ) {
			echo '<div><span>' . esc_html( $label ) . '</span><strong>' . esc_html( number_format_i18n( $value ) ) . '</strong></div>';
		}
		echo '</div>';
	}

	private function cap() {
		if ( ! current_user_can( \Ecapla_CertVerify_Permissions::CAP_MANAGE_CREDENTIALS ) ) {
			wp_die( esc_html__( 'You do not have permission to issue Certloom credentials.', 'certloom' ) );
		}
	}

	private function go( $key ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'     => self::PAGE,
					'bulk_job' => (string) $key,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	private function fail( $message, $key = '' ) {
		$this->store( 'error', $message );
		$this->go( $key );
	}

	private function success( $message ) {
		$this->store( 'success', $message );
	}

	private function store( $type, $message ) {
		set_transient(
			self::NOTICE . get_current_user_id(),
			array(
				'type'    => $type,
				'message' => (string) $message,
			),
			120
		);
	}

	private function notice() {
		$key    = self::NOTICE . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! is_array( $notice ) || empty( $notice['message'] ) ) {
			return;
		}

		delete_transient( $key );
		$class = 'success' === ( $notice['type'] ?? '' ) ? 'notice-success' : 'notice-error';
		echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( (string) $notice['message'] ) . '</p></div>';
	}
}
