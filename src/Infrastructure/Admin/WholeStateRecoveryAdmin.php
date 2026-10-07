<?php
namespace Certloom\Infrastructure\Admin;

use Certloom\Application\Backup\WholeStateBackupCoordinator;
use Certloom\Application\Backup\WholeStateBackupFormat;
use Certloom\Application\Restore\WholeStateRestoreCoordinator;
use Certloom\Domain\Job\JobStates;
use Certloom\Domain\Job\JobTypes;
use Certloom\Infrastructure\Backup\LocalBackupStore;
use Certloom\Infrastructure\Backup\WholeStateProvenanceRepository;
use Certloom\Infrastructure\Restore\WholeStateRecoveryControl;
use Certloom\Infrastructure\Restore\WholeStateRestoreJournal;
use Certloom\Infrastructure\Restore\WholeStateRestoreUploadSession;
use Certloom\Infrastructure\Restore\WholeStateRestoreWorkspace;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Native organization recovery journey, available even while ordinary writes are fenced. */
final class WholeStateRecoveryAdmin {
	const PAGE = 'certloom-recovery';
	const NOTICE = 'certloom_whole_recovery_notice_';

	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ), 55 );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_filter( 'admin_title', array( $this, 'title' ) );
		foreach ( array( 'capture', 'download', 'delete', 'upload', 'review', 'commit', 'discard', 'control' ) as $action ) { add_action( 'admin_post_certloom_recovery_' . $action, array( $this, $action ) ); }
	}
	public function title( $title ) { return isset( $_GET['page'] ) && self::PAGE === $_GET['page'] ? __( 'Recovery snapshots', 'certloom' ) . ' ‹ ' . get_bloginfo( 'name' ) . ' — WordPress' : $title; }
	public function assets() {
		if ( ! isset( $_GET['page'] ) || self::PAGE !== $_GET['page'] || ! current_user_can( \Ecapla_CertVerify_Permissions::CAP_MANAGE_DATA_TOOLS ) ) { return; }
		wp_enqueue_script( 'certloom-recovery-connections', CERTLOOM_URL . 'admin/assets/recovery-connections.js', array(), (string) filemtime( CERTLOOM_PATH . 'admin/assets/recovery-connections.js' ), true );
		wp_localize_script( 'certloom-recovery-connections', 'certloomRecoveryConnections', array( 'endpoint' => rest_url( 'certloom/v1/admin/recovery-connections' ), 'nonce' => wp_create_nonce( 'wp_rest' ), 'search' => __( 'Search by name or ID', 'certloom' ), 'disconnect' => __( 'Disconnect', 'certloom' ), 'selected' => __( 'Connection selected. It will be verified before recovery.', 'certloom' ), 'disconnected' => __( 'Disconnected. You can link this later.', 'certloom' ), 'choose' => __( 'Choose a result or select Disconnect.', 'certloom' ), 'empty' => __( 'No matching item found. Try another name or ID.', 'certloom' ), 'loading' => __( 'Finding matches…', 'certloom' ), 'failed' => __( 'Search could not finish. Try again before confirming.', 'certloom' ) ) );
	}
	public function menu() { add_submenu_page( null, __( 'Recovery snapshots', 'certloom' ), __( 'Recovery snapshots', 'certloom' ), \Ecapla_CertVerify_Permissions::CAP_MANAGE_DATA_TOOLS, self::PAGE, array( $this, 'render' ) ); }
	private function access() { if ( ! current_user_can( \Ecapla_CertVerify_Permissions::CAP_MANAGE_DATA_TOOLS ) ) { wp_die( esc_html__( 'You do not have permission to recover this organization’s certificates.', 'certloom' ), '', array( 'response' => 403 ) ); } }
	private function runtime() { $runtime = \Certloom\Plugin::job_runtime(); return $runtime ?: new \WP_Error( 'certloom_recovery_unavailable', __( 'Recovery jobs are unavailable. Check Certloom System Status before continuing.', 'certloom' ) ); }
	private function token( $value ) { return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{32}$/D', $value ); }
	private function url( $token = '' ) { return add_query_arg( array_filter( array( 'page' => self::PAGE, 'review' => $token ) ), admin_url( 'admin.php' ) ); }
	private function redirect( $result, $message = '', $token = '' ) {
		if ( is_wp_error( $result ) || '' !== $message ) { set_transient( self::NOTICE . get_current_user_id(), array( 'error' => is_wp_error( $result ), 'message' => is_wp_error( $result ) ? $result->get_error_message() : $message ), 120 ); }
		wp_safe_redirect( $this->url( $token ) ); exit;
	}
	private function begin_form( $action, $token = '', $multipart = false ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"' . ( $multipart ? ' enctype="multipart/form-data"' : '' ) . '><input type="hidden" name="action" value="certloom_recovery_' . esc_attr( $action ) . '">';
		if ( '' !== $token ) { echo '<input type="hidden" name="review_token" value="' . esc_attr( $token ) . '">'; }
		wp_nonce_field( 'certloom_recovery_' . $action . ( '' === $token ? '' : '_' . $token ) );
	}
	private function requested_session( $action ) {
		$this->access(); $token = isset( $_POST['review_token'] ) && is_string( $_POST['review_token'] ) ? wp_unslash( $_POST['review_token'] ) : '';
		if ( ! $this->token( $token ) ) { $this->redirect( new \WP_Error( 'certloom_recovery_token_invalid', __( 'Open your recovery review again.', 'certloom' ) ) ); }
		check_admin_referer( 'certloom_recovery_' . $action . '_' . $token );
		$session = ( new WholeStateRestoreUploadSession() )->get( $token );
		if ( is_wp_error( $session ) ) { $this->redirect( $session ); }
		return $session;
	}
	public function capture() {
		$this->access(); check_admin_referer( 'certloom_recovery_capture' );
		$runtime = $this->runtime();
		$result = is_wp_error( $runtime ) ? $runtime : ( new WholeStateBackupCoordinator( $runtime->queue() ) )->enqueue( 'manual', (int) get_current_user_id() );
		$this->redirect( $result, __( 'Your recovery snapshot is queued. Download it when the background job finishes.', 'certloom' ) );
	}
	public function download() {
		$this->access(); $id = isset( $_GET['backup_id'] ) && is_string( $_GET['backup_id'] ) ? wp_unslash( $_GET['backup_id'] ) : '';
		if ( ! $this->token( $id ) ) { wp_die( esc_html__( 'Choose a stored recovery snapshot.', 'certloom' ) ); }
		check_admin_referer( 'certloom_recovery_download_' . $id );
		$entry = ( new LocalBackupStore() )->resolve( $id );
		if ( is_wp_error( $entry ) || 'whole_state' !== ( $entry['scope'] ?? '' ) ) { wp_die( esc_html( is_wp_error( $entry ) ? $entry->get_error_message() : __( 'That file is not a recovery snapshot.', 'certloom' ) ) ); }
		$handle = fopen( $entry['path'], 'rb' ); if ( ! is_resource( $handle ) ) { wp_die( esc_html__( 'The private snapshot could not be opened.', 'certloom' ) ); }
		nocache_headers(); header( 'Content-Type: application/octet-stream' ); header( 'X-Content-Type-Options: nosniff' ); header( 'Content-Disposition: attachment; filename="certloom-recovery-' . $id . '.cvrecovery"' ); header( 'Content-Length: ' . $entry['bytes'] );
		fpassthru( $handle ); fclose( $handle ); exit;
	}
	public function delete() {
		$this->access(); $id = isset( $_POST['backup_id'] ) && is_string( $_POST['backup_id'] ) ? wp_unslash( $_POST['backup_id'] ) : '';
		if ( ! $this->token( $id ) ) { $this->redirect( new \WP_Error( 'certloom_backup_id_invalid', __( 'Choose a stored recovery snapshot.', 'certloom' ) ) ); }
		check_admin_referer( 'certloom_recovery_delete_' . $id );
		if ( 'REMOVE' !== ( $_POST['confirm_phrase'] ?? null ) ) { $this->redirect( new \WP_Error( 'certloom_recovery_delete_confirmation', __( 'Type REMOVE to delete the selected private recovery snapshot.', 'certloom' ) ) ); }
		$store = new LocalBackupStore(); $entry = $store->resolve( $id );
		if ( is_wp_error( $entry ) || 'whole_state' !== ( $entry['scope'] ?? '' ) ) { $this->redirect( is_wp_error( $entry ) ? $entry : new \WP_Error( 'certloom_recovery_scope_invalid', __( 'That file is not a recovery snapshot.', 'certloom' ) ) ); }
		$this->redirect( $store->delete( $id ), __( 'The selected private recovery snapshot was removed.', 'certloom' ) );
	}
	public function upload() {
		$this->access(); check_admin_referer( 'certloom_recovery_upload' );
		$file = $_FILES['snapshot'] ?? null;
		if ( ! is_array( $file ) || UPLOAD_ERR_OK !== ( $file['error'] ?? null ) || ! is_string( $file['tmp_name'] ?? null ) || ! is_uploaded_file( $file['tmp_name'] ) || ! is_int( $file['size'] ?? null ) || $file['size'] < 1 || $file['size'] > WholeStateBackupFormat::MAX_ARCHIVE_BYTES || ! is_string( $file['name'] ?? null ) ) { $this->redirect( new \WP_Error( 'certloom_recovery_upload_invalid', __( 'Choose a valid recovery snapshot within the upload limit.', 'certloom' ) ) ); }
		$runtime = $this->runtime(); if ( is_wp_error( $runtime ) ) { $this->redirect( $runtime ); }
		$source = ( new \Certloom\Application\Backup\WholeStateBackupReader() )->analyze( $file['tmp_name'] );
		if ( is_wp_error( $source ) || $source['uncompressed_bytes'] > \Certloom\Application\Backup\WholeStateBackupBuilder::MAX_CAPTURE_BYTES ) { $this->redirect( is_wp_error( $source ) ? $source : new \WP_Error( 'certloom_recovery_source_too_large', __( 'This snapshot exceeds the supported recovery size. Keep its private backup and contact support.', 'certloom' ) ) ); }
		$root = ( new WholeStateProvenanceRepository() )->ensure( (int) get_current_user_id() ); if ( is_wp_error( $root ) ) { $this->redirect( $root ); }
		// Capture is requested before final review, so its catalog generation is
		// part of the approved target baseline rather than a later surprise.
		$safety = ( new WholeStateBackupCoordinator( $runtime->queue() ) )->enqueue( 'safety', (int) get_current_user_id() );
		if ( is_wp_error( $safety ) ) { $this->redirect( $safety ); }
		$token = ( new WholeStateRestoreUploadSession() )->create( $file['tmp_name'], $file['name'], $safety );
		if ( is_wp_error( $token ) ) { $this->redirect( $token ); }
		$this->redirect( true, __( 'Snapshot uploaded privately. Your current certificates are being saved before you review the replacement.', 'certloom' ), $token );
	}
	private function safety_ready( array $session ) {
		$runtime = $this->runtime(); if ( is_wp_error( $runtime ) ) { return $runtime; }
		$job = $runtime->repository()->find( $session['safety_job'] );
		if ( ! is_array( $job ) || JobTypes::WHOLE_BACKUP !== $job['job_type'] || JobStates::SUCCEEDED !== $job['status'] || 'safety' !== ( $job['payload']['purpose'] ?? null ) || $job['owner_user_id'] !== $session['actor'] || empty( $job['result']['working_files_removed'] ) ) { return new \WP_Error( 'certloom_recovery_safety_pending', __( 'Wait for your safety snapshot to finish, then refresh this review. You can retry a failed capture in recovery activity below.', 'certloom' ) ); }
		$entry = ( new LocalBackupStore() )->resolve( $job['result']['backup_id'] ?? '' );
		return ! is_wp_error( $entry ) && 'whole_state' === ( $entry['scope'] ?? '' ) && 'safety' === $entry['purpose'] && $session['safety_job'] === $entry['export_id'] ? true : new \WP_Error( 'certloom_recovery_safety_missing', __( 'Your safety snapshot could not be verified. Start a fresh recovery review.', 'certloom' ) );
	}
	private function mapping_input( $key ) {
		$input = $_POST[$key] ?? array();
		if ( ! is_array( $input ) || count( $input ) > 5000 ) { return new \WP_Error( 'certloom_recovery_mapping_invalid', __( 'Review every connection before continuing.', 'certloom' ) ); }
		$out = array();
		foreach ( wp_unslash( $input ) as $source => $target ) {
			if ( ! preg_match( '/^[1-9][0-9]*$/D', (string) $source ) || (string) (int) $source !== (string) $source || ! is_string( $target ) || ( '' !== $target && ( ! preg_match( '/^[1-9][0-9]*$/D', $target ) || (string) (int) $target !== $target ) ) ) { return new \WP_Error( 'certloom_recovery_mapping_invalid', __( 'Choose an existing account or item, or disconnect it explicitly.', 'certloom' ) ); }
			$out[(int) $source] = '' === $target ? null : (int) $target;
		}
		return $out;
	}
	public function review() {
		$session = $this->requested_session( 'review' ); $ready = $this->safety_ready( $session ); if ( is_wp_error( $ready ) ) { $this->redirect( $ready, '', $session['token'] ); }
		$accounts = $this->mapping_input( 'accounts' ); $objects = $this->mapping_input( 'objects' );
		if ( is_wp_error( $accounts ) || is_wp_error( $objects ) ) { $this->redirect( is_wp_error( $accounts ) ? $accounts : $objects, '', $session['token'] ); }
		$images = isset( $_POST['images'] ) && is_array( $_POST['images'] ) ? wp_unslash( $_POST['images'] ) : array();
		if ( empty( $_POST['confirm_connections'] ) || count( $images ) > 5000 ) { $this->redirect( new \WP_Error( 'certloom_recovery_review_required', __( 'Confirm that you have reviewed these connections.', 'certloom' ) ), '', $session['token'] ); }
		$store = new WholeStateRestoreUploadSession();
		$result = $store->with_session( $session['token'], function ( $current ) use ( $store, $accounts, $objects, $images ) {
			if ( null !== $current['queued_job'] ) { return new \WP_Error( 'certloom_recovery_already_queued', __( 'This recovery is already queued. Follow its progress in recovery activity.', 'certloom' ) ); }
			$ready = $this->safety_ready( $current ); if ( is_wp_error( $ready ) ) { return $ready; }
			$review = $store->review( $current, $accounts, $objects, $images );
			return is_wp_error( $review ) ? $review : $store->remember_review( $current, $review );
		} );
		$this->redirect( $result, __( 'Connections verified. Review the replacement summary and confirm when ready.', 'certloom' ), $session['token'] );
	}
	public function commit() {
		$session = $this->requested_session( 'commit' );
		if ( 'REPLACE' !== ( $_POST['confirm_phrase'] ?? null ) || ! is_string( $session['fingerprint'] ) ) { $this->redirect( new \WP_Error( 'certloom_recovery_confirmation_required', __( 'Complete the connection review and type REPLACE to confirm.', 'certloom' ) ), '', $session['token'] ); }
		$store = new WholeStateRestoreUploadSession();
		$result = $store->with_session( $session['token'], function ( $current ) use ( $store ) {
			if ( null !== $current['queued_job'] ) { return $current['queued_job']; }
			$ready = $this->safety_ready( $current ); if ( is_wp_error( $ready ) ) { return $ready; }
			$review = $store->review( $current, $current['accounts'], $current['objects'], $current['images'] );
			if ( is_wp_error( $review ) || ! is_string( $current['fingerprint'] ) || ! hash_equals( $current['fingerprint'], $review->fingerprint() ) ) { return is_wp_error( $review ) ? $review : new \WP_Error( 'certloom_recovery_changed', __( 'Certificates, settings or connections changed since your review. Review them again before replacing data.', 'certloom' ) ); }
			$runtime = $this->runtime(); if ( is_wp_error( $runtime ) ) { return $runtime; }
			$marked = null;
			$queued = ( new WholeStateRestoreCoordinator( $runtime->queue() ) )->enqueue( $review, function ( $key ) use ( $store, $current, &$marked ) { $marked = $store->mark_queued( $current, $key ); return is_wp_error( $marked ) ? $marked : true; } );
			if ( is_wp_error( $queued ) ) { return $queued; }
			$discarded = $store->discard( $marked );
			return is_wp_error( $discarded ) ? new \WP_Error( 'certloom_recovery_upload_cleanup_pending', __( 'Recovery is queued. Its uploaded review could not be fully removed; follow the existing job in activity rather than starting another replacement.', 'certloom' ) ) : $queued;
		} );
		if ( is_wp_error( $result ) ) { $this->redirect( $result, '', $session['token'] ); }
		$this->redirect( $result, __( 'Recovery queued. Certificate changes pause while the reviewed snapshot is restored; follow its progress below.', 'certloom' ) );
	}
	public function discard() { $session = $this->requested_session( 'discard' ); $store = new WholeStateRestoreUploadSession(); $result = $store->with_session( $session['token'], function ( $current ) use ( $store ) { return $store->discard( $current ); } ); $this->redirect( $result, __( 'Uploaded review removed. Any recovery already queued remains listed in activity.', 'certloom' ) ); }
	public function control() {
		$this->access(); $key = isset( $_POST['job_key'] ) && is_string( $_POST['job_key'] ) ? wp_unslash( $_POST['job_key'] ) : '';
		if ( ! $this->token( $key ) ) { $this->redirect( new \WP_Error( 'certloom_recovery_job_invalid', __( 'Choose a recovery job.', 'certloom' ) ) ); }
		check_admin_referer( 'certloom_recovery_control_' . $key );
		$runtime = $this->runtime(); if ( is_wp_error( $runtime ) ) { $this->redirect( $runtime ); }
		$job = $runtime->repository()->find( $key );
		if ( ! is_array( $job ) || ! in_array( $job['job_type'], array( JobTypes::WHOLE_BACKUP, JobTypes::WHOLE_RESTORE ), true ) ) { $this->redirect( new \WP_Error( 'certloom_recovery_job_invalid', __( 'That job is not a recovery operation.', 'certloom' ) ) ); }
		$command = $_POST['command'] ?? '';
		if ( 'retry' === $command ) { $result = $runtime->retry_failed( $key ); }
		elseif ( 'resume' === $command ) { $result = $runtime->resume_paused( $key ); }
		elseif ( 'pause' === $command ) { $result = $runtime->pause_queued( $key ); }
		elseif ( 'cleanup' === $command && JobTypes::WHOLE_RESTORE === $job['job_type'] ) { $journal = ( new WholeStateRestoreJournal() )->read(); $result = is_array( $journal ) && $journal['owner_token'] === $key && in_array( $journal['phase'], array( 'committed', 'rolled_back' ), true ) ? ( new WholeStateRestoreWorkspace() )->recover() : new \WP_Error( 'certloom_recovery_cleanup_changed', __( 'Recovery cleanup changed. Refresh its progress.', 'certloom' ) ); }
		elseif ( 'rollback' === $command && 'RETURN' === ( $_POST['confirm_phrase'] ?? null ) ) { $result = ( new WholeStateRecoveryControl() )->rollback( $runtime, $key ); }
		else { $result = new \WP_Error( 'certloom_recovery_control_invalid', __( 'Choose a recovery action and confirm returning to previous data when requested.', 'certloom' ) ); }
		$this->redirect( $result, is_array( $result ) && empty( $result['complete'] ) ? __( 'Recovery cleanup advanced. Continue until certificate changes reopen.', 'certloom' ) : __( 'Recovery action completed. Refresh the activity for its current progress.', 'certloom' ) );
	}

	public function render() {
		$this->access();
		echo '<div class="wrap ecapla-certverify-admin-wrap certloom-admin-shell certloom-data-tools-workspace certloom-recovery-workspace"><section class="certloom-page-hero"><div><span class="certloom-eyebrow">' . esc_html__( 'Protect your organization', 'certloom' ) . '</span><h1>' . esc_html__( 'Recovery snapshots', 'certloom' ) . '</h1><p>' . esc_html__( 'Save your Certloom records together, review their website connections, and recover with a verified safety copy.', 'certloom' ) . '</p></div></section>';
		( new AdminProductNavigation() )->render_current_subnav();
		$notice = get_transient( self::NOTICE . get_current_user_id() );
		if ( is_array( $notice ) ) { delete_transient( self::NOTICE . get_current_user_id() ); echo '<div class="notice notice-' . ( ! empty( $notice['error'] ) ? 'error' : 'success' ) . '"><p>' . esc_html( $notice['message'] ?? '' ) . '</p></div>'; }
		$journal = ( new WholeStateRestoreJournal() )->read();
		if ( is_wp_error( $journal ) ) { echo '<div class="notice notice-error"><p>' . esc_html( $journal->get_error_message() ) . '</p></div>'; }
		elseif ( $journal ) { echo '<div class="notice notice-warning"><p>' . esc_html__( 'Recovery is in progress. Certificate changes are paused until recovery and cleanup finish.', 'certloom' ) . '</p></div>'; }
		$this->render_scope();
		$token = isset( $_GET['review'] ) && is_string( $_GET['review'] ) ? wp_unslash( $_GET['review'] ) : '';
		if ( $this->token( $token ) ) { $this->render_review( $token ); }
		elseif ( ! $journal ) {
			echo '<section class="certloom-panel certloom-data-tools-panel"><h2>' . esc_html__( 'Save a recovery snapshot', 'certloom' ) . '</h2><p>' . esc_html__( 'Certloom works in the background. You can keep using your site while the snapshot is captured.', 'certloom' ) . '</p>';
			$this->begin_form( 'capture' ); submit_button( __( 'Create snapshot', 'certloom' ), 'primary', 'submit', false ); echo '</form></section>';
			echo '<section class="certloom-panel certloom-data-tools-panel"><h2>' . esc_html__( 'Recover from a snapshot', 'certloom' ) . '</h2><ol><li>' . esc_html__( 'Upload your .cvrecovery file. Certloom saves the current state first.', 'certloom' ) . '</li><li>' . esc_html__( 'Review who can access recovered credentials and which website items they connect to.', 'certloom' ) . '</li><li>' . esc_html__( 'Confirm replacement and follow the background recovery.', 'certloom' ) . '</li></ol>';
			$this->begin_form( 'upload', '', true ); echo '<p><label for="certloom-recovery-file">' . esc_html__( 'Recovery snapshot', 'certloom' ) . '</label> <input id="certloom-recovery-file" type="file" name="snapshot" accept=".cvrecovery" required></p>'; submit_button( __( 'Upload and review', 'certloom' ), 'secondary', 'submit', false ); echo '</form></section>';
		}
		$this->render_catalog(); $this->render_activity( is_array( $journal ) ? $journal : null ); echo '</div>';
	}
	private function render_scope() {
		echo '<section class="certloom-panel certloom-data-tools-panel"><h2>' . esc_html__( 'What your snapshot covers', 'certloom' ) . '</h2><p>' . esc_html__( 'The legacy registry, Program credentials, recipient requests and answers, certificate and email designs, Certloom settings, referenced PNG/JPEG artwork, custom TTF/OTF fonts and static email attachment files are captured together.', 'certloom' ) . '</p><p>' . esc_html__( 'Keep a WordPress database and files backup too. WordPress accounts, courses, products and other uploaded files belong to your website. Recovery reviews their connections instead of importing those objects. Connect artwork and email attachments to exact copies in this website’s Media Library, or explicitly disconnect them. Email image and background links follow those reviewed connections.', 'certloom' ) . '</p><p>' . esc_html__( 'Recovered automation, pending requests and delivery work stay paused until your organization reviews and enables them. Previously issued certificate snapshots retain their original content.', 'certloom' ) . '</p></section>';
	}
	private function render_review( $token ) {
		$store = new WholeStateRestoreUploadSession(); $session = $store->get( $token );
		echo '<section class="certloom-panel certloom-data-tools-panel"><h2>' . esc_html__( 'Review your recovery', 'certloom' ) . '</h2>';
		if ( is_wp_error( $session ) ) { echo '<p>' . esc_html( $session->get_error_message() ) . '</p></section>'; return; }
		if ( null !== $session['queued_job'] ) { echo '<p role="status">' . esc_html__( 'Recovery is already queued. Follow its progress in recovery activity below.', 'certloom' ) . '</p>'; $this->begin_form( 'discard', $token ); submit_button( __( 'Remove uploaded review', 'certloom' ), 'secondary', 'submit', false ); echo '</form></section>'; return; }
		echo '<p><strong>' . esc_html( $session['name'] ) . '</strong></p>';
		$ready = $this->safety_ready( $session );
		if ( is_wp_error( $ready ) ) { echo '<p role="status">' . esc_html( $ready->get_error_message() ) . '</p><p><a class="button" href="' . esc_url( $this->url( $token ) ) . '">' . esc_html__( 'Refresh safety snapshot progress', 'certloom' ) . '</a></p>'; }
		else {
			$inventory = $store->inventory( $session );
			if ( is_wp_error( $inventory ) ) { echo '<p>' . esc_html( $inventory->get_error_message() ) . '</p>'; }
			else {
				echo '<p>' . esc_html( $inventory['same_installation'] ? __( 'This snapshot is verified as belonging to this installation. Suggested connections still need your review.', 'certloom' ) : __( 'This snapshot comes from another recovery installation. Choose the correct accounts and website items here, or leave connections disconnected.', 'certloom' ) ) . '</p>';
				if ( 1 === $inventory['references_version'] ) { echo '<p class="description">' . esc_html__( 'This older snapshot does not include static email attachment files or portable email image links. Disconnect unproven attachment connections and review email artwork in Email Studio after recovery.', 'certloom' ) . '</p>'; }
				$this->begin_form( 'review', $token );
				echo '<div class="certloom-domain-table-wrap"><table class="widefat"><thead><tr><th>' . esc_html__( 'Snapshot connection', 'certloom' ) . '</th><th>' . esc_html__( 'Connect on this website', 'certloom' ) . '</th></tr></thead><tbody>';
				foreach ( array( 'accounts', 'objects' ) as $kind ) { foreach ( $inventory[$kind] as $id => $row ) {
					$default = null;
					if ( $inventory['same_installation'] && is_string( $row['identity_sha256'] ) ) {
						$repository = new \Certloom\Infrastructure\Backup\WholeStateReferenceRepository();
						$identity = 'accounts' === $kind ? $repository->account_identity( $row['source_user_id'] ) : $repository->object( $id );
						$digest = is_array( $identity ) ? $identity['identity_sha256'] : $identity;
						if ( is_string( $digest ) && hash_equals( $row['identity_sha256'], $digest ) ) { $default = 'accounts' === $kind ? $row['source_user_id'] : $id; }
					}
					$target = array_key_exists( $id, $session[$kind] ) ? $session[$kind][$id] : $default;
					$label = 'accounts' === $kind ? sprintf( __( 'Recipient #%d → account #%d', 'certloom' ), $id, $row['source_user_id'] ) : sprintf( __( '%1$s #%2$d', 'certloom' ), $row['post_type'] ?: __( 'Website item', 'certloom' ), $id );
					$target_label = '';
					if ( $target ) { $item = 'accounts' === $kind ? get_userdata( $target ) : get_post( $target ); if ( $item ) { $target_label = ( 'accounts' === $kind ? $item->display_name : wp_strip_all_tags( $item->post_title ) ) . ' #' . $target; } }
					echo '<tr><td>' . esc_html( $label ) . '</td><td><label><span class="screen-reader-text">' . esc_html( $label ) . '</span><input data-recovery-connection data-kind="' . esc_attr( $kind ) . '" data-type="' . esc_attr( 'objects' === $kind ? (string) $row['post_type'] : '' ) . '" data-label="' . esc_attr( $target_label ) . '" aria-label="' . esc_attr( $label ) . '" type="number" min="1" step="1" name="' . esc_attr( $kind . '[' . $id . ']' ) . '" value="' . esc_attr( null === $target ? '' : (string) $target ) . '" placeholder="' . esc_attr__( 'Leave blank to disconnect', 'certloom' ) . '"></label></td></tr>';
				} }
				echo '</tbody></table></div><p class="description">' . esc_html__( 'Search for the matching account, course, product or media item by name. Disconnect anything you cannot confirm. A disconnected account cannot access recovered credentials until you link it. Artwork and email attachment connections must contain the exact archived file bytes; restore or upload those files first.', 'certloom' ) . '</p>';
				foreach ( $inventory['images'] as $reference => $descriptors ) { if ( count( $descriptors ) < 2 ) { continue; } echo '<p><label>' . esc_html( sprintf( __( 'Artwork version for %s', 'certloom' ), $reference ) ) . ' <select name="images[' . esc_attr( $reference ) . ']">'; foreach ( $descriptors as $sha => $descriptor ) { echo '<option value="' . esc_attr( $sha ) . '"' . selected( $session['images'][$reference] ?? '', $sha, false ) . '>' . esc_html( $descriptor['width_px'] . ' × ' . $descriptor['height_px'] . ' · ' . substr( $sha, 0, 12 ) ) . '</option>'; } echo '</select></label></p>'; }
				if ( empty( $inventory['accounts'] ) && empty( $inventory['objects'] ) ) { echo '<p>' . esc_html__( 'No WordPress account or website item connections need mapping.', 'certloom' ) . '</p>'; }
				echo '<p><label><input type="checkbox" name="confirm_connections" value="1" required> ' . esc_html__( 'I reviewed every connection and intentional disconnection.', 'certloom' ) . '</label></p>'; submit_button( __( 'Verify connections', 'certloom' ), 'secondary', 'submit', false ); echo '</form>';
				$verified = is_string( $session['fingerprint'] ) ? $store->review( $session, $session['accounts'], $session['objects'], $session['images'] ) : null;
				if ( $verified && ( is_wp_error( $verified ) || ! hash_equals( $session['fingerprint'], $verified->fingerprint() ) ) ) { echo '<p role="status">' . esc_html__( 'This review changed. Verify the connections again before confirming replacement.', 'certloom' ) . '</p>'; }
				elseif ( $verified ) {
					$counts = $verified->counts();
					echo '<p>' . esc_html( sprintf( __( 'Verified snapshot: %1$s legacy certificates, %2$s Programs, %3$s recipients and %4$s certificate requests. Designs and delivery history are included.', 'certloom' ), number_format_i18n( $counts['legacy_registry'] ), number_format_i18n( $counts['programs'] ), number_format_i18n( $counts['recipients'] ), number_format_i18n( $counts['issuance_requests'] ) ) ) . '</p>';
					echo '<hr><h3>' . esc_html__( 'Confirm replacement', 'certloom' ) . '</h3><p>' . esc_html__( 'This replaces Certloom records and settings with the reviewed snapshot. Your verified safety snapshot remains available. Website objects and user roles stay under WordPress control.', 'certloom' ) . '</p>';
					$this->begin_form( 'commit', $token ); echo '<p><label for="certloom-recovery-confirm">' . esc_html__( 'Type REPLACE to recover this snapshot', 'certloom' ) . '</label> <input id="certloom-recovery-confirm" name="confirm_phrase" autocomplete="off" required pattern="REPLACE"></p>'; submit_button( __( 'Confirm and recover', 'certloom' ), 'primary', 'submit', false ); echo '</form>';
				}
			}
		}
		$this->begin_form( 'discard', $token ); submit_button( __( 'Discard review', 'certloom' ), 'secondary', 'submit', false ); echo '</form></section>';
	}
	private function render_catalog() {
		echo '<section class="certloom-panel certloom-data-tools-panel"><h2>' . esc_html__( 'Your recovery snapshots', 'certloom' ) . '</h2><div class="certloom-domain-table-wrap"><table class="widefat"><thead><tr><th>' . esc_html__( 'Created', 'certloom' ) . '</th><th>' . esc_html__( 'Purpose', 'certloom' ) . '</th><th>' . esc_html__( 'Size', 'certloom' ) . '</th><th>' . esc_html__( 'Download', 'certloom' ) . '</th></tr></thead><tbody>';
		$found = false;
		$purposes = array( 'manual' => __( 'Created by your team', 'certloom' ), 'scheduled' => __( 'Scheduled', 'certloom' ), 'safety' => __( 'Before recovery or changes', 'certloom' ) );
		foreach ( ( new LocalBackupStore() )->list_backups() as $entry ) {
			if ( 'whole_state' !== ( $entry['scope'] ?? '' ) ) { continue; } $found = true;
			$url = wp_nonce_url( add_query_arg( array( 'action' => 'certloom_recovery_download', 'backup_id' => $entry['id'] ), admin_url( 'admin-post.php' ) ), 'certloom_recovery_download_' . $entry['id'] );
			echo '<tr><td>' . esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $entry['created_ts'] ) ) . '</td><td>' . esc_html( $purposes[$entry['purpose']] ?? $entry['purpose'] ) . '</td><td>' . esc_html( size_format( $entry['bytes'] ) ) . '</td><td><a class="button button-small" href="' . esc_url( $url ) . '">' . esc_html__( 'Download snapshot', 'certloom' ) . '</a><details><summary>' . esc_html__( 'Remove snapshot', 'certloom' ) . '</summary><p>' . esc_html__( 'This removes this private recovery copy. Keep another verified backup before removing it. An uploaded review using it as its safety copy will need a new safety snapshot.', 'certloom' ) . '</p>';
			$this->begin_form( 'delete', $entry['id'] ); echo '<input type="hidden" name="backup_id" value="' . esc_attr( $entry['id'] ) . '"><p><label>' . esc_html__( 'Type REMOVE to delete this copy', 'certloom' ) . ' <input name="confirm_phrase" required pattern="REMOVE" autocomplete="off"></label></p>'; submit_button( __( 'Remove this snapshot', 'certloom' ), 'secondary', 'submit', false ); echo '</form></details></td></tr>';
		}
		if ( ! $found ) { echo '<tr><td colspan="4">' . esc_html__( 'Create your first recovery snapshot to protect your Certloom records.', 'certloom' ) . '</td></tr>'; }
		echo '</tbody></table></div></section>';
	}
	private function render_activity( $journal ) {
		echo '<section class="certloom-panel certloom-data-tools-panel"><h2>' . esc_html__( 'Recovery activity', 'certloom' ) . '</h2><p><a class="button" href="' . esc_url( $this->url( isset( $_GET['review'] ) && is_string( $_GET['review'] ) ? wp_unslash( $_GET['review'] ) : '' ) ) . '">' . esc_html__( 'Refresh progress', 'certloom' ) . '</a></p>';
		$page = isset( $_GET['activity_page'] ) ? max( 1, absint( $_GET['activity_page'] ) ) : 1;
		$runtime = $this->runtime(); $result = is_wp_error( $runtime ) ? $runtime : $runtime->repository()->list_page( $page, 50, array( JobTypes::WHOLE_BACKUP, JobTypes::WHOLE_RESTORE ) );
		if ( is_wp_error( $result ) ) { echo '<p>' . esc_html( $result->get_error_message() ) . '</p></section>'; return; }
		echo '<div class="certloom-domain-table-wrap"><table class="widefat"><thead><tr><th>' . esc_html__( 'Operation', 'certloom' ) . '</th><th>' . esc_html__( 'Progress', 'certloom' ) . '</th><th>' . esc_html__( 'Actions', 'certloom' ) . '</th></tr></thead><tbody>';
		$found = false;
		$items = $result['items'];
		if ( $journal && ! in_array( $journal['owner_token'], array_column( $items, 'job_key' ), true ) ) { $active = $runtime->repository()->find( $journal['owner_token'] ); if ( is_array( $active ) ) { array_unshift( $items, $active ); } }
		foreach ( $items as $job ) {
			if ( ! in_array( $job['job_type'], array( JobTypes::WHOLE_BACKUP, JobTypes::WHOLE_RESTORE ), true ) ) { continue; } $found = true; $key = $job['job_key'];
			echo '<tr><td>' . esc_html( JobTypes::WHOLE_BACKUP === $job['job_type'] ? __( 'Snapshot capture', 'certloom' ) : __( 'Snapshot recovery', 'certloom' ) ) . ' <code>' . esc_html( substr( $key, 0, 8 ) ) . '</code></td><td>' . esc_html( $this->progress_label( $job ) );
			if ( JobStates::FAILED === $job['status'] ) { echo '<p class="description">' . esc_html( $this->failure_guidance( $job ) ) . '</p>'; }
			echo '</td><td>';
			$actions = array();
			if ( JobStates::FAILED === $job['status'] ) { $actions['retry'] = __( 'Retry', 'certloom' ); }
			if ( JobStates::PAUSED === $job['status'] ) { $actions['resume'] = __( 'Resume', 'certloom' ); }
			if ( JobStates::QUEUED === $job['status'] ) { $actions['pause'] = __( 'Pause', 'certloom' ); }
			if ( $journal && $journal['owner_token'] === $key && in_array( $journal['phase'], array( 'committed', 'rolled_back' ), true ) ) { $actions['cleanup'] = __( 'Continue cleanup', 'certloom' ); }
			foreach ( $actions as $command => $label ) { $this->job_form( $key, $command, $label ); }
			if ( JobTypes::WHOLE_RESTORE === $job['job_type'] && in_array( $job['status'], array( JobStates::FAILED, JobStates::PAUSED, JobStates::QUEUED, JobStates::CANCELLED ), true ) && ( ! $journal || ( $journal['owner_token'] === $key && 'committed' !== $journal['phase'] ) ) ) { echo '<details><summary>' . esc_html__( 'Return to previous data', 'certloom' ) . '</summary>'; $this->job_form( $key, 'rollback', __( 'Return and clean up', 'certloom' ), true ); echo '</details>'; }
			echo '</td></tr>';
		}
		if ( ! $found ) { echo '<tr><td colspan="3">' . esc_html__( 'No recovery activity yet.', 'certloom' ) . '</td></tr>'; }
		echo '</tbody></table></div>';
		$pages = max( 1, (int) ceil( $result['total'] / 50 ) );
		if ( $pages > 1 ) { echo '<nav aria-label="' . esc_attr__( 'Recovery activity pages', 'certloom' ) . '">' . wp_kses_post( paginate_links( array( 'base' => str_replace( '999999999', '%#%', add_query_arg( 'activity_page', 999999999, $this->url() ) ), 'format' => '', 'current' => $page, 'total' => $pages ) ) ) . '</nav>'; }
		echo '</section>';
	}
	private function failure_guidance( array $job ) {
		$code = $job['error']['code'] ?? '';
		if ( in_array( $code, array( 'certloom_whole_field_catalog_incomplete', 'certloom_whole_configuration_graph_invalid', 'certloom_whole_option_preflight_failed', 'certloom_whole_template_variant_missing' ), true ) ) { return __( 'Repair the source fields, Program connections or design choices, then create and review a new snapshot.', 'certloom' ); }
		if ( false !== strpos( $code, 'missing_email_image' ) ) { return __( 'An email design uses local artwork that could not be found. Reconnect its image in Email Studio, then retry capture.', 'certloom' ); }
		if ( false !== strpos( $code, 'catalog_full' ) ) { return __( 'Download and remove an older manual snapshot to make room, then retry capture.', 'certloom' ); }
		if ( JobTypes::WHOLE_RESTORE === $job['job_type'] ) { return __( 'Check that the reviewed accounts, website items and exact artwork files still exist. Retry after correcting the cause, or return to the previous data below.', 'certloom' ); }
		return __( 'Check private storage and database access in System Status, then retry this capture. Existing snapshots remain available.', 'certloom' );
	}
	private function progress_label( array $job ) {
		$statuses = array( JobStates::QUEUED => __( 'Waiting for the next step', 'certloom' ), JobStates::RUNNING => __( 'In progress', 'certloom' ), JobStates::PAUSED => __( 'Paused', 'certloom' ), JobStates::FAILED => __( 'Needs attention', 'certloom' ), JobStates::SUCCEEDED => __( 'Complete', 'certloom' ), JobStates::CANCELLED => __( 'Cancelled', 'certloom' ) );
		$phases = array( 'domain' => __( 'Saving Program records', 'certloom' ), 'legacy' => __( 'Saving the certificate registry', 'certloom' ), 'deliveries' => __( 'Saving delivery history', 'certloom' ), 'references' => __( 'Collecting website connections and artwork', 'certloom' ), 'members' => __( 'Checking snapshot files', 'certloom' ), 'archive' => __( 'Building your snapshot', 'certloom' ), 'verify' => __( 'Verifying your snapshot', 'certloom' ), 'publish' => __( 'Saving the verified snapshot', 'certloom' ), 'building' => __( 'Preparing recovered records and artwork', 'certloom' ), 'ready' => __( 'Ready to apply the reviewed snapshot', 'certloom' ), 'cleanup' => __( 'Finishing private recovery cleanup', 'certloom' ) );
		$status = $statuses[$job['status']] ?? __( 'Review progress', 'certloom' );
		if ( in_array( $job['status'], array( JobStates::SUCCEEDED, JobStates::CANCELLED ), true ) ) { return $status; }
		return isset( $phases[$job['phase'] ?? ''] ) ? $status . ' · ' . $phases[$job['phase']] : $status;
	}
	private function job_form( $key, $command, $label, $confirm = false ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="certloom_recovery_control"><input type="hidden" name="job_key" value="' . esc_attr( $key ) . '"><input type="hidden" name="command" value="' . esc_attr( $command ) . '">'; wp_nonce_field( 'certloom_recovery_control_' . $key );
		if ( $confirm ) { echo '<p><label>' . esc_html__( 'Type RETURN to cancel recovery and preserve the previous data', 'certloom' ) . ' <input name="confirm_phrase" autocomplete="off" required pattern="RETURN"></label></p>'; }
		echo '<button class="button button-small" type="submit">' . esc_html( $label ) . '</button></form>';
	}
}
