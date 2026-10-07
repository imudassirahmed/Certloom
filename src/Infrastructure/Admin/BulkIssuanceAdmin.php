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


	/** Route each CSV task to the workflow that owns that record type. */
	public function render_intake() {
		$this->cap();
		$issue_url  = add_query_arg( 'page', self::PAGE, admin_url( 'admin.php' ) );
		$import_url = admin_url( 'admin.php?page=certloom-import#certloom-csv-import' );
		$domain_import_url = admin_url( 'admin.php?page=certloom-domain-data-tools#certloom-domain-import' );

		echo '<div class="wrap ecapla-certverify-admin-wrap certloom-admin-shell certloom-file-intake">';
		echo '<section class="certloom-page-hero certloom-page-head"><div><span class="certloom-eyebrow">' . esc_html__( 'Credential operations', 'certloom' ) . '</span><h1>' . esc_html__( 'Import / issue from file', 'certloom' ) . '</h1><p>' . esc_html__( 'Choose what the rows mean. Each path reviews the CSV before changing records.', 'certloom' ) . '</p></div></section>';
		( new AdminProductNavigation() )->render_current_subnav();
		echo '<div class="certloom-file-intake-options">';
		echo '<a class="certloom-file-intake-option" href="' . esc_url( $issue_url ) . '"><strong>' . esc_html__( 'Issue new credentials', 'certloom' ) . '</strong><span>' . esc_html__( 'Issue multiple credentials from recipient rows. Choose an active Program, review the CSV, then confirm the accepted rows.', 'certloom' ) . '</span></a>';
		echo '<a class="certloom-file-intake-option" href="' . esc_url( $domain_import_url ) . '"><strong>' . esc_html__( 'Import Program credentials', 'certloom' ) . '</strong><span>' . esc_html__( 'Import existing credentials into the Program-based registry. Review field mapping and preflight results before applying changes.', 'certloom' ) . '</span></a>';
		echo '<a class="certloom-file-intake-option" href="' . esc_url( $import_url ) . '"><strong>' . esc_html__( 'Import legacy certificates', 'certloom' ) . '</strong><span>' . esc_html__( 'Import records into the older certificate registry. Review detected columns and sample rows before confirming.', 'certloom' ) . '</span></a>';
		echo '</div>';
		echo '</div>';
	}

	public function render() {
		$this->cap();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only route state.
		$job_key = isset( $_GET['bulk_job'] ) ? strtolower( sanitize_text_field( wp_unslash( $_GET['bulk_job'] ) ) ) : '';

		echo '<div class="wrap ecapla-certverify-admin-wrap certloom-admin-shell certloom-bulk-issuance">';
		echo '<section class="certloom-page-hero certloom-page-head"><div><span class="certloom-eyebrow">' . esc_html__( 'CSV issuance workflow', 'certloom' ) . '</span><h1>' . esc_html__( 'Bulk Issuance', 'certloom' ) . '</h1><p>' . esc_html__( 'Review the entire CSV before any credential is created, then issue only the accepted rows through the same checks used for individual issuance.', 'certloom' ) . '</p></div><span class="certloom-domain-preview-badge">' . esc_html__( 'Bulk issuance', 'certloom' ) . '</span></section>';
		( new AdminProductNavigation() )->render_current_subnav();

		$this->notice();
		echo '<div class="notice notice-warning inline"><p><strong>' . esc_html__( 'Availability:', 'certloom' ) . '</strong> ' . esc_html__( 'Bulk Issuance and normalized public verification are operational when the credential store is healthy. PDF/PNG/JPEG exports remain unavailable until the certificate renderer is approved.', 'certloom' ) . '</p></div>';

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
		if ( isset( $_POST['download_template'] ) ) {
			$program_id = isset( $_POST['program_id'] ) && is_scalar( $_POST['program_id'] ) ? absint( $_POST['program_id'] ) : 0;
			$batch_id = isset( $_POST['batch_id'] ) && is_scalar( $_POST['batch_id'] ) ? absint( $_POST['batch_id'] ) : 0;
			$schema = $this->runtime->bulk_issuance()->csv_schema( $program_id, $batch_id );
			if ( is_wp_error( $schema ) ) { $this->fail( $schema->get_error_message() ); }
			$numbering = \Ecapla_CertVerify_Numbering::policy();
			$columns = ( new \Certloom\Application\Issuance\BulkIssuanceCsvSchema() )->columns( $schema, 'manual' !== ( $numbering['numbering_mode'] ?? 'manual' ) );
			if ( is_wp_error( $columns ) ) { $this->fail( $columns->get_error_message() ); }
			$stream = fopen( 'php://output', 'wb' );
			if ( false === $stream ) { $this->fail( __( 'The CSV template could not be downloaded. Try again.', 'certloom' ) ); }
			nocache_headers();
			header( 'Content-Type: text/csv; charset=UTF-8' );
			header( 'Content-Disposition: attachment; filename="certloom-program-' . $program_id . '-bulk.csv"' );
			fputcsv( $stream, $columns, ',', '"', '' ); fclose( $stream ); exit;
		}

		if ( empty( $_FILES['bulk_csv'] ) || ! is_array( $_FILES['bulk_csv'] ) ) {
			$this->fail( __( 'Choose a CSV file before starting Bulk Issuance.', 'certloom' ) );
		}

		$file = $_FILES['bulk_csv'];
		if ( UPLOAD_ERR_OK !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
			$this->fail( __( 'The Bulk Issuance CSV upload did not complete successfully.', 'certloom' ) );
		}

		$tmp = (string) ( $file['tmp_name'] ?? '' );
		if ( '' === $tmp || ! is_uploaded_file( $tmp ) || ! is_file( $tmp ) ) {
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
			'waiting_policy' => isset( $_POST['waiting_policy'] ) && is_string( $_POST['waiting_policy'] ) ? wp_unslash( $_POST['waiting_policy'] ) : 'stop',
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
		$qualified_waiting = isset( $_POST['qualified_waiting'] ) && '1' === $_POST['qualified_waiting'];
		$key = $this->runtime->bulk_issuance()->enqueue_commit( $pre, get_current_user_id(), $fp, true, $qualified_waiting );
		if ( is_wp_error( $key ) ) {
			$this->fail( $key->get_error_message(), $pre );
		}

		$this->success( __( 'The reviewed rows are being checked independently. Ready rows issue now; confirmed qualified rows with missing customer details become waiting requests. Retries preserve their original receipts.', 'certloom' ) );
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
		echo '<label><span>' . esc_html__( 'When customer details are missing', 'certloom' ) . '</span><select name="waiting_policy"><option value="stop">' . esc_html__( 'Stop and fix the CSV review first', 'certloom' ) . '</option><option value="customer">' . esc_html__( 'Create waiting requests for qualified customers', 'certloom' ) . '</option></select><small>' . esc_html__( 'Waiting requests require an existing connected account and exact Program participation where needed. Staff results and issuing configuration must already be complete. Qualification is confirmed separately after review.', 'certloom' ) . '</small></label>';
		echo '<div class="certloom-manual-actions"><button class="button" type="submit" name="download_template" value="1" formnovalidate ' . ( empty( $p ) ? 'disabled' : '' ) . '>' . esc_html__( 'Download Program CSV', 'certloom' ) . '</button><button class="button button-primary button-hero" type="submit" ' . ( empty( $p ) ? 'disabled' : '' ) . '>' . esc_html__( 'Review CSV', 'certloom' ) . '</button><span>' . esc_html__( 'The CSV uses exact Recipient IDs and includes staff-entered results. Customer details, connected-source values and Program defaults keep their own source. Review creates no credentials.', 'certloom' ) . '</span></div>';
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
			echo '<p><strong>' . esc_html__( 'Bulk processing complete.', 'certloom' ) . '</strong> ' . esc_html__( 'Credential Management contains successfully issued credentials. Waiting requests remain separate until customer details and any required approval are complete.', 'certloom' ) . '</p>';
			if ( ! empty( $summary['requests_accepted'] ) ) { echo '<p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=' . PendingRequestAdmin::PAGE ) ) . '">' . esc_html__( 'Open certificate requests', 'certloom' ) . '</a> ' . esc_html__( 'Customers can complete their required details from their website account. Completing a form does not change their qualification.', 'certloom' ) . '</p>'; }
			echo '<a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=' . DomainAdmin::CREDENTIALS_PAGE ) ) . '">' . esc_html__( 'Open Credential Management', 'certloom' ) . '</a> ';
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
		$waiting = ! empty( $result['summary']['waiting'] );
		if ( $waiting ) { echo '<p><label><input type="checkbox" name="qualified_waiting" value="1" required> ' . esc_html__( 'I confirm that every waiting recipient has already earned this certificate. Certloom will collect only their missing customer details and required approval before issuing.', 'certloom' ) . '</label></p>'; }
		echo '<button class="button button-primary button-hero" type="submit">' . esc_html( $waiting ? __( 'Confirm reviewed rows', 'certloom' ) : __( 'Issue reviewed batch', 'certloom' ) ) . '</button></form>';
	}

	private function metrics( $type, array $summary ) {
		if ( JobTypes::BULK_ISSUANCE_PREFLIGHT === $type ) {
			$items = array(
				__( 'Processed', 'certloom' ) => absint( $summary['processed'] ?? 0 ),
				__( 'Ready', 'certloom' )     => absint( $summary['ready'] ?? 0 ),
				__( 'Waiting for customer', 'certloom' ) => absint( $summary['waiting'] ?? 0 ),
				__( 'Errors', 'certloom' )    => absint( $summary['errors'] ?? 0 ),
			);
		} else {
			$items = array(
				__( 'Processed', 'certloom' )   => absint( $summary['processed'] ?? 0 ),
				__( 'Issued', 'certloom' )      => absint( $summary['succeeded'] ?? 0 ),
				__( 'Requests accepted', 'certloom' ) => absint( $summary['requests_accepted'] ?? 0 ),
				__( 'Failed', 'certloom' )      => absint( $summary['failed'] ?? 0 ),
				__( 'Safe retries', 'certloom' ) => absint( $summary['idempotent_replays'] ?? 0 ) + absint( $summary['request_replays'] ?? 0 ),
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
