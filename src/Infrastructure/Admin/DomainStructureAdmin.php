<?php
namespace Certloom\Infrastructure\Admin;

use Certloom\Infrastructure\Repository\BatchRepository;
use Certloom\Infrastructure\Repository\IssuerRepository;
use Certloom\Infrastructure\Repository\ProgramRepository;
use Certloom\Infrastructure\Repository\RecipientRepository;
use Certloom\Infrastructure\Repository\SignerRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Gated Phase-1 administration for Batches and signer hierarchy. */
final class DomainStructureAdmin {
	const BATCHES_PAGE = 'certloom-domain-batches';
	const SIGNERS_PAGE = 'certloom-domain-signers';

	/** @return void */
	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ), 51 );
		add_action( 'admin_post_certloom_domain_create_batch', array( $this, 'create_batch' ) );
		add_action( 'admin_post_certloom_domain_update_batch', array( $this, 'update_batch' ) );
		add_action( 'admin_post_certloom_domain_batch_member', array( $this, 'batch_member' ) );
		add_action( 'admin_post_certloom_domain_create_signer', array( $this, 'create_signer' ) );
		add_action( 'admin_post_certloom_domain_update_signer', array( $this, 'update_signer' ) );
		add_action( 'admin_post_certloom_domain_signer_assignment', array( $this, 'signer_assignment' ) );
	}

	/** @return void */
	public function menu() {
		add_submenu_page(
			'certloom',
			__( 'Batches', 'certloom' ),
			__( 'Batches', 'certloom' ),
			\Ecapla_CertVerify_Permissions::CAP_MANAGE_BATCHES,
			self::BATCHES_PAGE,
			array( $this, 'render_batches' )
		);
		add_submenu_page(
			'certloom',
			__( 'Signers', 'certloom' ),
			__( 'Signers', 'certloom' ),
			\Ecapla_CertVerify_Permissions::CAP_MANAGE_SIGNERS,
			self::SIGNERS_PAGE,
			array( $this, 'render_signers' )
		);
	}

	/** @return void */
	public function create_batch() {
		$this->require_capability( \Ecapla_CertVerify_Permissions::CAP_MANAGE_BATCHES );
		check_admin_referer( 'certloom_domain_create_batch' );
		$data   = $this->batch_request();
		$result = is_wp_error( $data ) ? $data : ( new BatchRepository() )->create( $data );
		$this->redirect_result( self::BATCHES_PAGE, $result, 'batch_created' );
	}

	/** @return void */
	public function update_batch() {
		$this->require_capability( \Ecapla_CertVerify_Permissions::CAP_MANAGE_BATCHES );
		$id = isset( $_POST['batch_id'] ) ? absint( $_POST['batch_id'] ) : 0;
		check_admin_referer( 'certloom_domain_update_batch_' . $id );

		$repo     = new BatchRepository();
		$existing = $repo->find( $id );
		if ( is_wp_error( $existing ) || ! is_array( $existing ) ) {
			$result = is_wp_error( $existing ) ? $existing : new \WP_Error( 'certloom_batch_missing', __( 'The batch no longer exists.', 'certloom' ) );
			$this->redirect_result( self::BATCHES_PAGE, $result, 'batch_updated' );
		}

		$data   = $this->batch_request( $existing );
		$result = is_wp_error( $data ) ? $data : $repo->update( $id, $data );
		$this->redirect_result( self::BATCHES_PAGE, $result, 'batch_updated', array( 'batch_id' => $id ) );
	}

	/** @return void */
	public function batch_member() {
		$this->require_capability( \Ecapla_CertVerify_Permissions::CAP_MANAGE_BATCHES );
		$batch_id = isset( $_POST['batch_id'] ) ? absint( $_POST['batch_id'] ) : 0;
		check_admin_referer( 'certloom_domain_batch_member_' . $batch_id );

		$mode         = isset( $_POST['membership_action'] ) ? sanitize_key( wp_unslash( $_POST['membership_action'] ) ) : '';
		$recipient_id = isset( $_POST['recipient_id'] ) ? absint( $_POST['recipient_id'] ) : 0;
		$repo         = new BatchRepository();
		if ( 'add' === $mode ) {
			$result = $repo->add_recipient( $batch_id, $recipient_id );
		} elseif ( 'remove' === $mode ) {
			$result = $repo->remove_recipient( $batch_id, $recipient_id );
		} else {
			$result = new \WP_Error( 'certloom_batch_membership_action_invalid', __( 'Choose a valid batch membership action.', 'certloom' ) );
		}
		$this->redirect_result( self::BATCHES_PAGE, $result, 'batch_membership_changed', array( 'batch_id' => $batch_id ) );
	}

	/** @return void */
	public function create_signer() {
		$this->require_capability( \Ecapla_CertVerify_Permissions::CAP_MANAGE_SIGNERS );
		check_admin_referer( 'certloom_domain_create_signer' );
		$data   = $this->signer_request();
		$result = is_wp_error( $data ) ? $data : ( new SignerRepository() )->create( $data );
		$this->redirect_result( self::SIGNERS_PAGE, $result, 'signer_created' );
	}

	/** @return void */
	public function update_signer() {
		$this->require_capability( \Ecapla_CertVerify_Permissions::CAP_MANAGE_SIGNERS );
		$id = isset( $_POST['signer_id'] ) ? absint( $_POST['signer_id'] ) : 0;
		check_admin_referer( 'certloom_domain_update_signer_' . $id );

		$repo     = new SignerRepository();
		$existing = $repo->find( $id );
		if ( is_wp_error( $existing ) || ! is_array( $existing ) ) {
			$result = is_wp_error( $existing ) ? $existing : new \WP_Error( 'certloom_signer_missing', __( 'The signer no longer exists.', 'certloom' ) );
			$this->redirect_result( self::SIGNERS_PAGE, $result, 'signer_updated' );
		}

		$data   = $this->signer_request( $existing );
		$result = is_wp_error( $data ) ? $data : $repo->update( $id, $data );
		$this->redirect_result( self::SIGNERS_PAGE, $result, 'signer_updated', array( 'signer_id' => $id ) );
	}

	/** @return void */
	public function signer_assignment() {
		$this->require_capability( \Ecapla_CertVerify_Permissions::CAP_MANAGE_SIGNERS );
		$signer_id = isset( $_POST['signer_id'] ) ? absint( $_POST['signer_id'] ) : 0;
		check_admin_referer( 'certloom_domain_signer_assignment_' . $signer_id );

		$repo = new SignerRepository();
		$mode = isset( $_POST['assignment_action'] ) ? sanitize_key( wp_unslash( $_POST['assignment_action'] ) ) : '';
		if ( 'remove' === $mode ) {
			$result = $repo->remove_assignment( $signer_id, isset( $_POST['assignment_id'] ) ? absint( $_POST['assignment_id'] ) : 0 );
		} elseif ( 'add' === $mode ) {
			$scope      = isset( $_POST['scope'] ) ? sanitize_text_field( wp_unslash( $_POST['scope'] ) ) : 'global:0';
			$parts      = explode( ':', $scope, 2 );
			$scope_type = sanitize_key( $parts[0] ?? '' );
			$scope_id   = absint( $parts[1] ?? 0 );
			$role       = isset( $_POST['role_type'] ) ? sanitize_key( wp_unslash( $_POST['role_type'] ) ) : 'signer';
			$priority   = isset( $_POST['priority'] ) ? absint( $_POST['priority'] ) : 100;
			$result     = $repo->add_assignment( $signer_id, $scope_type, $scope_id, $role, $priority );
		} else {
			$result = new \WP_Error( 'certloom_signer_assignment_action_invalid', __( 'Choose a valid signer assignment action.', 'certloom' ) );
		}
		$this->redirect_result( self::SIGNERS_PAGE, $result, 'signer_assignment_changed', array( 'signer_id' => $signer_id ) );
	}

	/** @return void */
	public function render_batches() {
		$this->require_capability( \Ecapla_CertVerify_Permissions::CAP_MANAGE_BATCHES );
		$search  = $this->search_value();
		$page    = $this->page_value();
		$status  = $this->status_filter();
		$repo    = new BatchRepository();
		$result  = $repo->list_page( $search, $page, 20, $status );
		$summary = $repo->summary();
		$edit_id = isset( $_GET['batch_id'] ) ? absint( $_GET['batch_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation.
		$editing = $edit_id ? $repo->find( $edit_id ) : null;

		$this->header( __( 'Batches & cohorts', 'certloom' ), __( 'Group recipients under a stable batch code and Program without collapsing credential history into LMS-specific records.', 'certloom' ) );
		$this->notice();
		if ( ! is_wp_error( $summary ) ) {
			$this->metric_grid(
				array(
					array( 'value' => $summary['total'], 'label' => __( 'Batches', 'certloom' ) ),
					array( 'value' => $summary['active'], 'label' => __( 'Active', 'certloom' ) ),
					array( 'value' => $summary['members'], 'label' => __( 'Memberships', 'certloom' ) ),
					array( 'value' => $summary['with_credentials'], 'label' => __( 'With credentials', 'certloom' ) ),
				)
			);
		}
		$this->filters( self::BATCHES_PAGE, $search, $status, __( 'Search batch name, code, or Program', 'certloom' ) );

		if ( is_wp_error( $result ) ) {
			$this->error( $result->get_error_message() );
		} else {
			echo '<div class="certloom-domain-table-wrap"><table class="widefat striped certloom-domain-table"><thead><tr><th>' . esc_html__( 'Batch', 'certloom' ) . '</th><th>' . esc_html__( 'Program', 'certloom' ) . '</th><th>' . esc_html__( 'Dates', 'certloom' ) . '</th><th>' . esc_html__( 'Instructor', 'certloom' ) . '</th><th>' . esc_html__( 'Recipients', 'certloom' ) . '</th><th>' . esc_html__( 'Credentials', 'certloom' ) . '</th><th>' . esc_html__( 'Status', 'certloom' ) . '</th></tr></thead><tbody>';
			foreach ( $result['items'] as $item ) {
				$url        = add_query_arg( array( 'page' => self::BATCHES_PAGE, 'batch_id' => absint( $item['id'] ) ), admin_url( 'admin.php' ) );
				$dates      = trim( (string) ( $item['start_date'] ?: '—' ) . ' → ' . (string) ( $item['end_date'] ?: '—' ) );
				$instructor = (string) ( $item['instructor_signer_name'] ?: $item['instructor_name'] ?: '—' );
				echo '<tr><td><strong><a href="' . esc_url( $url ) . '">' . esc_html( (string) $item['name'] ) . '</a></strong><div><code>' . esc_html( (string) $item['code'] ) . '</code></div></td><td>' . esc_html( (string) $item['program_title'] ) . '</td><td>' . esc_html( $dates ) . '</td><td>' . esc_html( $instructor ) . '</td><td>' . esc_html( (string) absint( $item['recipient_count'] ) ) . '</td><td>' . esc_html( (string) absint( $item['credential_count'] ) ) . '</td><td><span class="certloom-domain-status ' . ( ! empty( $item['active'] ) ? 'is-active' : 'is-inactive' ) . '">' . esc_html( ! empty( $item['active'] ) ? __( 'Active', 'certloom' ) : __( 'Inactive', 'certloom' ) ) . '</span></td></tr>';
			}
			if ( empty( $result['items'] ) ) {
				echo '<tr><td colspan="7">' . esc_html__( 'No batches found.', 'certloom' ) . '</td></tr>';
			}
			echo '</tbody></table></div>';
			$this->pagination( self::BATCHES_PAGE, $page, (int) $result['total'], 20, $search, $status );
		}

		if ( is_wp_error( $editing ) ) {
			$this->error( $editing->get_error_message() );
		} else {
			$this->batch_form( is_array( $editing ) ? $editing : null );
			if ( is_array( $editing ) ) {
				$this->batch_members( $repo, $editing );
			}
		}
		$this->footer();
	}

	/** @return void */
	public function render_signers() {
		$this->require_capability( \Ecapla_CertVerify_Permissions::CAP_MANAGE_SIGNERS );
		$search  = $this->search_value();
		$page    = $this->page_value();
		$status  = $this->status_filter();
		$repo    = new SignerRepository();
		$result  = $repo->list_page( $search, $page, 20, $status );
		$summary = $repo->summary();
		$edit_id = isset( $_GET['signer_id'] ) ? absint( $_GET['signer_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation.
		$editing = $edit_id ? $repo->find( $edit_id ) : null;

		$this->header( __( 'Signer profiles', 'certloom' ), __( 'Reusable signer identities with deterministic Batch → Program → Issuer → Global hierarchy.', 'certloom' ) );
		$this->notice();
		if ( ! is_wp_error( $summary ) ) {
			$this->metric_grid(
				array(
					array( 'value' => $summary['total'], 'label' => __( 'Signers', 'certloom' ) ),
					array( 'value' => $summary['active'], 'label' => __( 'Active', 'certloom' ) ),
					array( 'value' => $summary['assignments'], 'label' => __( 'Active assignments', 'certloom' ) ),
				)
			);
		}
		$this->filters( self::SIGNERS_PAGE, $search, $status, __( 'Search signer name, key, title, or email', 'certloom' ) );

		if ( is_wp_error( $result ) ) {
			$this->error( $result->get_error_message() );
		} else {
			echo '<div class="certloom-domain-table-wrap"><table class="widefat striped certloom-domain-table"><thead><tr><th>' . esc_html__( 'Signer', 'certloom' ) . '</th><th>' . esc_html__( 'Machine key', 'certloom' ) . '</th><th>' . esc_html__( 'Title', 'certloom' ) . '</th><th>' . esc_html__( 'Email', 'certloom' ) . '</th><th>' . esc_html__( 'Assignments', 'certloom' ) . '</th><th>' . esc_html__( 'Status', 'certloom' ) . '</th></tr></thead><tbody>';
			foreach ( $result['items'] as $item ) {
				$url = add_query_arg( array( 'page' => self::SIGNERS_PAGE, 'signer_id' => absint( $item['id'] ) ), admin_url( 'admin.php' ) );
				echo '<tr><td><strong><a href="' . esc_url( $url ) . '">' . esc_html( (string) $item['display_name'] ) . '</a></strong></td><td><code>' . esc_html( (string) $item['slug'] ) . '</code></td><td>' . esc_html( (string) ( $item['title'] ?: '—' ) ) . '</td><td>' . esc_html( (string) ( $item['email'] ?: '—' ) ) . '</td><td>' . esc_html( (string) absint( $item['assignment_count'] ) ) . '</td><td><span class="certloom-domain-status ' . ( ! empty( $item['active'] ) ? 'is-active' : 'is-inactive' ) . '">' . esc_html( ! empty( $item['active'] ) ? __( 'Active', 'certloom' ) : __( 'Inactive', 'certloom' ) ) . '</span></td></tr>';
			}
			if ( empty( $result['items'] ) ) {
				echo '<tr><td colspan="6">' . esc_html__( 'No signer profiles found.', 'certloom' ) . '</td></tr>';
			}
			echo '</tbody></table></div>';
			$this->pagination( self::SIGNERS_PAGE, $page, (int) $result['total'], 20, $search, $status );
		}

		if ( is_wp_error( $editing ) ) {
			$this->error( $editing->get_error_message() );
		} else {
			$this->signer_form( is_array( $editing ) ? $editing : null );
			if ( is_array( $editing ) ) {
				$this->signer_assignments( $repo, $editing );
			}
		}
		$this->footer();
	}

	/** @param array<string,mixed>|null $editing @return void */
	private function batch_form( array $editing = null ) {
		$is_edit  = is_array( $editing );
		$id       = $is_edit ? absint( $editing['id'] ) : 0;
		$metadata = $is_edit && is_array( $editing['metadata'] ?? null ) ? $editing['metadata'] : array();
		$editable = $this->is_scalar_map( $metadata );

		echo '<section class="ecapla-certverify-admin-card certloom-domain-editor"><h2>' . esc_html( $is_edit ? __( 'Batch profile', 'certloom' ) : __( 'Add batch', 'certloom' ) ) . '</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( $is_edit ? 'certloom_domain_update_batch' : 'certloom_domain_create_batch' ) . '">';
		if ( $is_edit ) {
			echo '<input type="hidden" name="batch_id" value="' . esc_attr( $id ) . '">';
			wp_nonce_field( 'certloom_domain_update_batch_' . $id );
		} else {
			wp_nonce_field( 'certloom_domain_create_batch' );
		}

		echo '<div class="certloom-domain-form-grid">';
		$this->program_select( $is_edit ? $editing['program_id'] : 0 );
		$this->text_field( 'code', __( 'Batch code', 'certloom' ), $is_edit ? (string) $editing['code'] : '', true, 'text', __( 'Stable code used by restores, integrations, and future automation.', 'certloom' ) );
		$this->text_field( 'name', __( 'Batch name', 'certloom' ), $is_edit ? (string) $editing['name'] : '', true );
		$this->text_field( 'start_date', __( 'Start date', 'certloom' ), $is_edit ? (string) $editing['start_date'] : '', false, 'date' );
		$this->text_field( 'end_date', __( 'End date', 'certloom' ), $is_edit ? (string) $editing['end_date'] : '', false, 'date' );
		$this->signer_select( 'instructor_signer_id', __( 'Instructor signer profile', 'certloom' ), $is_edit ? $editing['instructor_signer_id'] : 0 );
		$this->text_field( 'instructor_name', __( 'Instructor display fallback', 'certloom' ), $is_edit ? (string) $editing['instructor_name'] : '', false, 'text', __( 'Optional display fallback when no reusable signer profile is assigned.', 'certloom' ) );
		echo '<div class="certloom-domain-field"><label><input type="checkbox" name="active" value="1" ' . checked( $is_edit ? ! empty( $editing['active'] ) : true, true, false ) . '> <strong>' . esc_html__( 'Active batch', 'certloom' ) . '</strong></label></div></div>';
		$this->metadata_editor( 'batch', $metadata, $editable );
		submit_button( $is_edit ? __( 'Update batch', 'certloom' ) : __( 'Add batch', 'certloom' ), 'primary' );
		echo '</form></section>';
	}

	/** @param BatchRepository $repo @param array<string,mixed> $batch @return void */
	private function batch_members( BatchRepository $repo, array $batch ) {
		$batch_id       = absint( $batch['id'] );
		$member_search  = isset( $_GET['member_s'] ) ? sanitize_text_field( wp_unslash( $_GET['member_s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only search.
		$member_page    = isset( $_GET['member_paged'] ) ? max( 1, absint( $_GET['member_paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination.
		$recipient_find = isset( $_GET['recipient_s'] ) ? sanitize_text_field( wp_unslash( $_GET['recipient_s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only search.
		$members        = $repo->members_page( $batch_id, $member_search, $member_page, 20 );

		echo '<section class="ecapla-certverify-admin-card certloom-domain-history"><h2>' . esc_html__( 'Batch recipients', 'certloom' ) . '</h2><p>' . esc_html__( 'Membership is a normalized relationship. Adding or removing a recipient does not rewrite already-issued credential snapshots.', 'certloom' ) . '</p>';
		echo '<form method="get" class="certloom-domain-search"><input type="hidden" name="page" value="' . esc_attr( self::BATCHES_PAGE ) . '"><input type="hidden" name="batch_id" value="' . esc_attr( $batch_id ) . '"><label class="screen-reader-text" for="certloom-batch-member-search">' . esc_html__( 'Search batch recipients', 'certloom' ) . '</label><input id="certloom-batch-member-search" type="search" name="member_s" value="' . esc_attr( $member_search ) . '" placeholder="' . esc_attr__( 'Search assigned recipients', 'certloom' ) . '"><button class="button">' . esc_html__( 'Search members', 'certloom' ) . '</button></form>';

		if ( is_wp_error( $members ) ) {
			$this->error( $members->get_error_message() );
			echo '</section>';
			return;
		}

		echo '<div class="certloom-domain-table-wrap"><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Recipient', 'certloom' ) . '</th><th>' . esc_html__( 'Email', 'certloom' ) . '</th><th>' . esc_html__( 'External ID', 'certloom' ) . '</th><th>' . esc_html__( 'Action', 'certloom' ) . '</th></tr></thead><tbody>';
		foreach ( $members['items'] as $member ) {
			echo '<tr><td>' . esc_html( (string) $member['display_name'] ) . '</td><td>' . esc_html( (string) ( $member['email'] ?: '—' ) ) . '</td><td>' . esc_html( (string) ( $member['external_id'] ?: '—' ) ) . '</td><td><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'certloom_domain_batch_member_' . $batch_id );
			echo '<input type="hidden" name="action" value="certloom_domain_batch_member"><input type="hidden" name="membership_action" value="remove"><input type="hidden" name="batch_id" value="' . esc_attr( $batch_id ) . '"><input type="hidden" name="recipient_id" value="' . esc_attr( absint( $member['id'] ) ) . '"><button class="button button-small">' . esc_html__( 'Remove', 'certloom' ) . '</button></form></td></tr>';
		}
		if ( empty( $members['items'] ) ) {
			echo '<tr><td colspan="4">' . esc_html__( 'No recipients match this Batch view.', 'certloom' ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
		$this->member_pagination( $batch_id, $member_page, (int) $members['total'], 20, $member_search, $recipient_find );

		echo '<div class="certloom-domain-subsection"><h3>' . esc_html__( 'Add recipient', 'certloom' ) . '</h3><form method="get" class="certloom-domain-search"><input type="hidden" name="page" value="' . esc_attr( self::BATCHES_PAGE ) . '"><input type="hidden" name="batch_id" value="' . esc_attr( $batch_id ) . '"><input type="hidden" name="member_s" value="' . esc_attr( $member_search ) . '"><label class="screen-reader-text" for="certloom-batch-recipient-search">' . esc_html__( 'Find a recipient to add', 'certloom' ) . '</label><input id="certloom-batch-recipient-search" type="search" name="recipient_s" value="' . esc_attr( $recipient_find ) . '" placeholder="' . esc_attr__( 'Find recipient by name, email, or external ID', 'certloom' ) . '"><button class="button">' . esc_html__( 'Find recipient', 'certloom' ) . '</button></form>';
		$recipients = ( new RecipientRepository() )->list_page( $recipient_find, 1, 50 );
		if ( is_wp_error( $recipients ) ) {
			$this->error( $recipients->get_error_message() );
		} else {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'certloom_domain_batch_member_' . $batch_id );
			echo '<input type="hidden" name="action" value="certloom_domain_batch_member"><input type="hidden" name="membership_action" value="add"><input type="hidden" name="batch_id" value="' . esc_attr( $batch_id ) . '"><label for="certloom-batch-recipient-id"><strong>' . esc_html__( 'Recipient', 'certloom' ) . '</strong></label> <select id="certloom-batch-recipient-id" name="recipient_id" required><option value="">' . esc_html__( 'Choose recipient…', 'certloom' ) . '</option>';
			foreach ( $recipients['items'] as $recipient ) {
				$label = (string) $recipient['display_name'];
				if ( ! empty( $recipient['email'] ) ) {
					$label .= ' — ' . (string) $recipient['email'];
				}
				echo '<option value="' . esc_attr( absint( $recipient['id'] ) ) . '">' . esc_html( $label ) . '</option>';
			}
			echo '</select> <button class="button">' . esc_html__( 'Add to batch', 'certloom' ) . '</button></form>';
			if ( (int) $recipients['total'] > 50 ) {
				echo '<p class="description">' . esc_html__( 'More than 50 recipients match. Refine the search before choosing a recipient.', 'certloom' ) . '</p>';
			}
		}
		echo '</div></section>';
	}

	/** @param array<string,mixed>|null $editing @return void */
	private function signer_form( array $editing = null ) {
		$is_edit  = is_array( $editing );
		$id       = $is_edit ? absint( $editing['id'] ) : 0;
		$metadata = $is_edit && is_array( $editing['metadata'] ?? null ) ? $editing['metadata'] : array();
		$editable = $this->is_scalar_map( $metadata );

		echo '<section class="ecapla-certverify-admin-card certloom-domain-editor"><h2>' . esc_html( $is_edit ? __( 'Signer profile', 'certloom' ) : __( 'Add signer', 'certloom' ) ) . '</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( $is_edit ? 'certloom_domain_update_signer' : 'certloom_domain_create_signer' ) . '">';
		if ( $is_edit ) {
			echo '<input type="hidden" name="signer_id" value="' . esc_attr( $id ) . '">';
			wp_nonce_field( 'certloom_domain_update_signer_' . $id );
		} else {
			wp_nonce_field( 'certloom_domain_create_signer' );
		}

		echo '<div class="certloom-domain-form-grid">';
		$this->text_field( 'display_name', __( 'Signer name', 'certloom' ), $is_edit ? (string) $editing['display_name'] : '', true );
		$this->text_field( 'slug', __( 'Machine key', 'certloom' ), $is_edit ? (string) $editing['slug'] : '', true, 'text', __( 'Stable machine identity used by restore, hierarchy assignment, integrations, and future APIs.', 'certloom' ) );
		$this->text_field( 'title', __( 'Title / role', 'certloom' ), $is_edit ? (string) $editing['title'] : '' );
		$this->text_field( 'email', __( 'Email', 'certloom' ), $is_edit ? (string) $editing['email'] : '', false, 'email' );
		$this->text_field( 'signature_attachment_id', __( 'Signature media ID', 'certloom' ), $is_edit ? (string) ( $editing['signature_attachment_id'] ?: '' ) : '', false, 'number', __( 'WordPress Media attachment ID. Artifact rendering validates the actual local asset before use.', 'certloom' ), 'min="1"' );
		echo '<div class="certloom-domain-field"><label><input type="checkbox" name="active" value="1" ' . checked( $is_edit ? ! empty( $editing['active'] ) : true, true, false ) . '> <strong>' . esc_html__( 'Active signer', 'certloom' ) . '</strong></label></div></div>';
		$this->metadata_editor( 'signer', $metadata, $editable );
		submit_button( $is_edit ? __( 'Update signer', 'certloom' ) : __( 'Add signer', 'certloom' ), 'primary' );
		echo '</form></section>';
	}

	/** @param SignerRepository $repo @param array<string,mixed> $signer @return void */
	private function signer_assignments( SignerRepository $repo, array $signer ) {
		$signer_id = absint( $signer['id'] );
		$rows      = $repo->assignments( $signer_id );
		echo '<section class="ecapla-certverify-admin-card certloom-domain-history"><h2>' . esc_html__( 'Signer hierarchy assignments', 'certloom' ) . '</h2><p>' . esc_html__( 'Most-specific scope wins: Batch → Program → Issuer → Global. Lower priority numbers win inside the same scope.', 'certloom' ) . '</p>';
		if ( is_wp_error( $rows ) ) {
			$this->error( $rows->get_error_message() );
			echo '</section>';
			return;
		}

		echo '<div class="certloom-domain-table-wrap"><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Scope', 'certloom' ) . '</th><th>' . esc_html__( 'Role', 'certloom' ) . '</th><th>' . esc_html__( 'Priority', 'certloom' ) . '</th><th>' . esc_html__( 'Action', 'certloom' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr><td>' . esc_html( (string) $row['scope_label'] ) . '</td><td>' . esc_html( (string) $row['role_type'] ) . '</td><td>' . esc_html( (string) $row['priority'] ) . '</td><td><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'certloom_domain_signer_assignment_' . $signer_id );
			echo '<input type="hidden" name="action" value="certloom_domain_signer_assignment"><input type="hidden" name="assignment_action" value="remove"><input type="hidden" name="signer_id" value="' . esc_attr( $signer_id ) . '"><input type="hidden" name="assignment_id" value="' . esc_attr( absint( $row['id'] ) ) . '"><button class="button button-small">' . esc_html__( 'Remove', 'certloom' ) . '</button></form></td></tr>';
		}
		if ( empty( $rows ) ) {
			echo '<tr><td colspan="4">' . esc_html__( 'No hierarchy assignments yet.', 'certloom' ) . '</td></tr>';
		}
		echo '</tbody></table></div>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="certloom-domain-inline-form">';
		wp_nonce_field( 'certloom_domain_signer_assignment_' . $signer_id );
		echo '<input type="hidden" name="action" value="certloom_domain_signer_assignment"><input type="hidden" name="assignment_action" value="add"><input type="hidden" name="signer_id" value="' . esc_attr( $signer_id ) . '"><label><strong>' . esc_html__( 'Scope', 'certloom' ) . '</strong> ';
		$this->scope_select();
		echo '</label> <label><strong>' . esc_html__( 'Role', 'certloom' ) . '</strong> <select name="role_type"><option value="signer">' . esc_html__( 'Signer', 'certloom' ) . '</option><option value="instructor">' . esc_html__( 'Instructor', 'certloom' ) . '</option></select></label> <label><strong>' . esc_html__( 'Priority', 'certloom' ) . '</strong> <input type="number" min="0" max="1000" name="priority" value="100" style="width:90px"></label> <button class="button">' . esc_html__( 'Add assignment', 'certloom' ) . '</button></form></section>';
	}

	/** @param array<string,mixed>|null $existing @return array<string,mixed>|\WP_Error */
	private function batch_request( array $existing = null ) {
		$program_id = isset( $_POST['program_id'] ) ? absint( $_POST['program_id'] ) : 0;
		if ( ! $program_id ) {
			return new \WP_Error( 'certloom_batch_program_required', __( 'Choose a Program for the Batch.', 'certloom' ) );
		}
		$program = ( new ProgramRepository() )->find( $program_id );
		if ( is_wp_error( $program ) || ! is_array( $program ) ) {
			return is_wp_error( $program ) ? $program : new \WP_Error( 'certloom_batch_program_missing', __( 'The selected Program no longer exists.', 'certloom' ) );
		}

		$signer_id = isset( $_POST['instructor_signer_id'] ) && '' !== trim( (string) $_POST['instructor_signer_id'] ) ? absint( $_POST['instructor_signer_id'] ) : null;
		if ( $signer_id ) {
			$signer = ( new SignerRepository() )->find( $signer_id );
			if ( is_wp_error( $signer ) || ! is_array( $signer ) ) {
				return is_wp_error( $signer ) ? $signer : new \WP_Error( 'certloom_batch_signer_missing', __( 'The selected instructor signer no longer exists.', 'certloom' ) );
			}
		}

		$data = array(
			'program_id'           => $program_id,
			'code'                 => isset( $_POST['code'] ) ? sanitize_text_field( wp_unslash( $_POST['code'] ) ) : '',
			'name'                 => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
			'start_date'           => isset( $_POST['start_date'] ) ? sanitize_text_field( wp_unslash( $_POST['start_date'] ) ) : '',
			'end_date'             => isset( $_POST['end_date'] ) ? sanitize_text_field( wp_unslash( $_POST['end_date'] ) ) : '',
			'instructor_name'      => isset( $_POST['instructor_name'] ) ? sanitize_text_field( wp_unslash( $_POST['instructor_name'] ) ) : '',
			'instructor_signer_id' => $signer_id,
			'active'               => isset( $_POST['active'] ),
		);
		if ( isset( $_POST['batch_metadata_present'] ) ) {
			$parsed = $this->parse_key_value_request( 'batch_meta_key', 'batch_meta_value', 30 );
			if ( is_wp_error( $parsed ) ) {
				return $parsed;
			}
			$data['metadata'] = $parsed;
		} elseif ( null === $existing ) {
			$data['metadata'] = array();
		}
		return $data;
	}

	/** @param array<string,mixed>|null $existing @return array<string,mixed>|\WP_Error */
	private function signer_request( array $existing = null ) {
		$email = isset( $_POST['email'] ) ? trim( (string) wp_unslash( $_POST['email'] ) ) : '';
		if ( '' !== $email && ! is_email( $email ) ) {
			return new \WP_Error( 'certloom_signer_email_invalid', __( 'Enter a valid signer email address or leave it blank.', 'certloom' ) );
		}

		$data = array(
			'display_name'            => isset( $_POST['display_name'] ) ? sanitize_text_field( wp_unslash( $_POST['display_name'] ) ) : '',
			'slug'                    => isset( $_POST['slug'] ) ? sanitize_key( wp_unslash( $_POST['slug'] ) ) : '',
			'title'                   => isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '',
			'email'                   => sanitize_email( $email ),
			'signature_attachment_id' => isset( $_POST['signature_attachment_id'] ) ? absint( $_POST['signature_attachment_id'] ) : null,
			'active'                  => isset( $_POST['active'] ),
		);
		if ( isset( $_POST['signer_metadata_present'] ) ) {
			$parsed = $this->parse_key_value_request( 'signer_meta_key', 'signer_meta_value', 30 );
			if ( is_wp_error( $parsed ) ) {
				return $parsed;
			}
			$data['metadata'] = $parsed;
		} elseif ( null === $existing ) {
			$data['metadata'] = array();
		}
		return $data;
	}

	/** @param string $prefix @param array<string,mixed> $metadata @param bool $editable @return void */
	private function metadata_editor( $prefix, array $metadata, $editable ) {
		echo '<div class="certloom-domain-kv"><h3>' . esc_html__( 'Metadata', 'certloom' ) . '</h3>';
		if ( ! $editable ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'This record contains structured metadata that the simple editor cannot round-trip exactly. Certloom will preserve it unchanged.', 'certloom' ) . '</p></div>';
		} else {
			echo '<input type="hidden" name="' . esc_attr( $prefix . '_metadata_present' ) . '" value="1"><div class="certloom-domain-kv-list" data-certloom-kv-list="' . esc_attr( $prefix ) . '">';
			$rows = $metadata ?: array( '' => '' );
			foreach ( $rows as $key => $value ) {
				$this->key_value_row( $prefix . '_meta_key[]', $prefix . '_meta_value[]', $key, $value );
			}
			echo '</div><button type="button" class="button certloom-domain-add-kv" data-certloom-add-kv="' . esc_attr( $prefix ) . '">' . esc_html__( 'Add metadata field', 'certloom' ) . '</button>';
		}
		echo '</div>';
	}

	/** @return void */
	private function key_value_row( $key_name, $value_name, $key, $value ) {
		echo '<div class="certloom-domain-kv-row"><label><span class="screen-reader-text">' . esc_html__( 'Metadata key', 'certloom' ) . '</span><input type="text" name="' . esc_attr( $key_name ) . '" value="' . esc_attr( $key ) . '" placeholder="field_key"></label><label><span class="screen-reader-text">' . esc_html__( 'Metadata value', 'certloom' ) . '</span><input type="text" name="' . esc_attr( $value_name ) . '" value="' . esc_attr( $value ) . '" placeholder="' . esc_attr__( 'Value', 'certloom' ) . '"></label><button type="button" class="button-link-delete certloom-domain-remove-kv">' . esc_html__( 'Remove', 'certloom' ) . '</button></div>';
	}

	/** @return array<string,string>|\WP_Error */
	private function parse_key_value_request( $key_name, $value_name, $max_rows ) {
		$keys   = isset( $_POST[ $key_name ] ) && is_array( $_POST[ $key_name ] ) ? wp_unslash( $_POST[ $key_name ] ) : array();
		$values = isset( $_POST[ $value_name ] ) && is_array( $_POST[ $value_name ] ) ? wp_unslash( $_POST[ $value_name ] ) : array();
		if ( count( $keys ) > $max_rows || count( $values ) > $max_rows ) {
			return new \WP_Error( 'certloom_domain_metadata_limit', __( 'Too many custom metadata rows were submitted.', 'certloom' ) );
		}

		$result = array();
		$count  = max( count( $keys ), count( $values ) );
		for ( $i = 0; $i < $count; $i++ ) {
			$raw_key = isset( $keys[ $i ] ) ? trim( (string) $keys[ $i ] ) : '';
			$value   = isset( $values[ $i ] ) ? sanitize_text_field( (string) $values[ $i ] ) : '';
			if ( '' === $raw_key && '' === $value ) {
				continue;
			}
			$key = sanitize_key( $raw_key );
			if ( '' === $key || $key !== $raw_key ) {
				return new \WP_Error( 'certloom_domain_metadata_key_invalid', __( 'Metadata keys must be stable machine keys.', 'certloom' ) );
			}
			if ( isset( $result[ $key ] ) ) {
				return new \WP_Error( 'certloom_domain_metadata_key_duplicate', __( 'Metadata keys must be unique.', 'certloom' ) );
			}
			$result[ $key ] = $value;
		}
		return $result;
	}

	/** @return bool */
	private function is_scalar_map( array $value ) {
		foreach ( $value as $key => $item ) {
			$key = (string) $key;
			if ( '' === $key || sanitize_key( $key ) !== $key || ! is_string( $item ) ) {
				return false;
			}
		}
		return true;
	}

	/** @return void */
	private function program_select( $selected ) {
		$result = ( new ProgramRepository() )->list_page( '', 1, 100, 'active' );
		echo '<div class="certloom-domain-field"><label for="certloom-batch-program-id"><strong>' . esc_html__( 'Program', 'certloom' ) . '</strong></label><select id="certloom-batch-program-id" name="program_id" required><option value="">' . esc_html__( 'Choose Program…', 'certloom' ) . '</option>';
		if ( ! is_wp_error( $result ) ) {
			foreach ( $result['items'] as $item ) {
				echo '<option value="' . esc_attr( absint( $item['id'] ) ) . '" ' . selected( absint( $selected ), absint( $item['id'] ), false ) . '>' . esc_html( (string) $item['title'] ) . '</option>';
			}
		}
		echo '</select></div>';
	}

	/** @return void */
	private function signer_select( $name, $label, $selected ) {
		$result = ( new SignerRepository() )->list_page( '', 1, 100, 'active' );
		$id     = 'certloom-domain-' . sanitize_html_class( $name );
		echo '<div class="certloom-domain-field"><label for="' . esc_attr( $id ) . '"><strong>' . esc_html( $label ) . '</strong></label><select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '"><option value="">' . esc_html__( 'None', 'certloom' ) . '</option>';
		if ( ! is_wp_error( $result ) ) {
			foreach ( $result['items'] as $item ) {
				echo '<option value="' . esc_attr( absint( $item['id'] ) ) . '" ' . selected( absint( $selected ), absint( $item['id'] ), false ) . '>' . esc_html( (string) $item['display_name'] ) . '</option>';
			}
		}
		echo '</select></div>';
	}

	/** @return void */
	private function scope_select() {
		echo '<select name="scope" required><option value="global:0">' . esc_html__( 'Global', 'certloom' ) . '</option>';
		$groups = array(
			'issuer'  => array( new IssuerRepository(), __( 'Issuers', 'certloom' ) ),
			'program' => array( new ProgramRepository(), __( 'Programs', 'certloom' ) ),
			'batch'   => array( new BatchRepository(), __( 'Batches', 'certloom' ) ),
		);
		foreach ( $groups as $type => $group ) {
			if ( 'batch' === $type ) {
				$result = $group[0]->list_page( '', 1, 100, 'active' );
			} elseif ( 'program' === $type ) {
				$result = $group[0]->list_page( '', 1, 100, 'active' );
			} else {
				$result = $group[0]->list_page( '', 1, 100 );
			}
			if ( is_wp_error( $result ) ) {
				continue;
			}
			echo '<optgroup label="' . esc_attr( $group[1] ) . '">';
			foreach ( $result['items'] as $item ) {
				$label = 'issuer' === $type ? $item['name'] : ( 'program' === $type ? $item['title'] : $item['name'] );
				echo '<option value="' . esc_attr( $type . ':' . absint( $item['id'] ) ) . '">' . esc_html( (string) $label ) . '</option>';
			}
			echo '</optgroup>';
		}
		echo '</select>';
	}

	/** @return void */
	private function text_field( $name, $label, $value, $required = false, $type = 'text', $description = '', $attributes = '' ) {
		$id = 'certloom-domain-' . sanitize_html_class( $name );
		echo '<div class="certloom-domain-field"><label for="' . esc_attr( $id ) . '"><strong>' . esc_html( $label ) . '</strong></label><input id="' . esc_attr( $id ) . '" class="regular-text" type="' . esc_attr( $type ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" ' . ( $required ? 'required ' : '' ) . wp_kses_post( $attributes ) . '>';
		if ( '' !== $description ) {
			echo '<p class="description">' . esc_html( $description ) . '</p>';
		}
		echo '</div>';
	}

	/** @return void */
	private function header( $title, $subtitle ) {
		echo '<div class="wrap ecapla-certverify-admin-wrap certloom-admin-shell certloom-domain-screen"><div class="certloom-domain-title-row"><div><h1>' . esc_html( $title ) . '</h1><p class="certloom-domain-lead">' . esc_html( $subtitle ) . '</p></div><span class="certloom-domain-preview-badge">' . esc_html__( 'Acceptance preview', 'certloom' ) . '</span></div>';
	}

	/** @return void */
	private function notice() {
		echo '<div class="notice notice-warning inline certloom-domain-gate-notice"><p><strong>' . esc_html__( 'Safety gate active.', 'certloom' ) . '</strong> ' . esc_html__( 'These normalized-domain screens remain unavailable in production until the real database, migration, restore, and cutover acceptance gates pass.', 'certloom' ) . '</p></div>';
		if ( isset( $_GET['certloom_error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Redirected admin notice only.
			$this->error( sanitize_text_field( wp_unslash( $_GET['certloom_error'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		$messages = array(
			'batch_created'             => __( 'Batch created.', 'certloom' ),
			'batch_updated'             => __( 'Batch updated.', 'certloom' ),
			'batch_membership_changed'  => __( 'Batch membership updated.', 'certloom' ),
			'signer_created'            => __( 'Signer created.', 'certloom' ),
			'signer_updated'            => __( 'Signer updated.', 'certloom' ),
			'signer_assignment_changed' => __( 'Signer hierarchy updated.', 'certloom' ),
		);
		foreach ( $messages as $key => $message ) {
			if ( isset( $_GET[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Presence-only redirected notice.
				echo '<div class="notice notice-success is-dismissible inline"><p>' . esc_html( $message ) . '</p></div>';
			}
		}
	}

	/** @return void */
	private function filters( $page_slug, $search, $status, $placeholder ) {
		$id = 'certloom-domain-search-' . sanitize_html_class( $page_slug );
		echo '<form method="get" class="certloom-domain-search"><input type="hidden" name="page" value="' . esc_attr( $page_slug ) . '"><label class="screen-reader-text" for="' . esc_attr( $id ) . '">' . esc_html__( 'Search', 'certloom' ) . '</label><input id="' . esc_attr( $id ) . '" type="search" name="s" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr( $placeholder ) . '"><label class="screen-reader-text" for="' . esc_attr( $id . '-status' ) . '">' . esc_html__( 'Status', 'certloom' ) . '</label><select id="' . esc_attr( $id . '-status' ) . '" name="status"><option value="all" ' . selected( $status, 'all', false ) . '>' . esc_html__( 'All statuses', 'certloom' ) . '</option><option value="active" ' . selected( $status, 'active', false ) . '>' . esc_html__( 'Active', 'certloom' ) . '</option><option value="inactive" ' . selected( $status, 'inactive', false ) . '>' . esc_html__( 'Inactive', 'certloom' ) . '</option></select><button class="button">' . esc_html__( 'Filter', 'certloom' ) . '</button></form>';
	}

	/** @param array<int,array{value:mixed,label:string}> $items @return void */
	private function metric_grid( array $items ) {
		echo '<div class="certloom-domain-metrics">';
		foreach ( $items as $item ) {
			echo '<div class="certloom-domain-metric"><strong>' . esc_html( (string) $item['value'] ) . '</strong><span>' . esc_html( $item['label'] ) . '</span></div>';
		}
		echo '</div>';
	}

	/** @return void */
	private function pagination( $slug, $page, $total, $per_page, $search, $status ) {
		$total_pages = max( 1, (int) ceil( $total / $per_page ) );
		if ( $total_pages <= 1 ) {
			return;
		}
		$base = add_query_arg( array( 'page' => $slug, 's' => $search, 'status' => $status, 'paged' => '%#%' ), admin_url( 'admin.php' ) );
		echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( paginate_links( array( 'base' => $base, 'format' => '', 'current' => $page, 'total' => $total_pages ) ) ) . '</div></div>';
	}

	/** @return void */
	private function member_pagination( $batch_id, $page, $total, $per_page, $search, $recipient_search ) {
		$total_pages = max( 1, (int) ceil( $total / $per_page ) );
		if ( $total_pages <= 1 ) {
			return;
		}
		$base = add_query_arg(
			array(
				'page'         => self::BATCHES_PAGE,
				'batch_id'     => absint( $batch_id ),
				'member_s'     => $search,
				'recipient_s'  => $recipient_search,
				'member_paged' => '%#%',
			),
			admin_url( 'admin.php' )
		);
		echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( paginate_links( array( 'base' => $base, 'format' => '', 'current' => $page, 'total' => $total_pages ) ) ) . '</div></div>';
	}

	/** @return string */
	private function search_value() {
		return isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only search.
	}

	/** @return string */
	private function status_filter() {
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter.
		return in_array( $status, array( 'all', 'active', 'inactive' ), true ) ? $status : 'all';
	}

	/** @return int */
	private function page_value() {
		return isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination.
	}

	/** @return void */
	private function error( $message ) {
		echo '<div class="notice notice-error inline"><p>' . esc_html( $message ) . '</p></div>';
	}

	/** @return void */
	private function footer() {
		echo '</div>';
	}

	/** @return void */
	private function require_capability( $capability ) {
		if ( ! current_user_can( $capability ) ) {
			wp_die( esc_html__( 'You do not have permission to manage this Certloom area.', 'certloom' ), '', array( 'response' => 403 ) );
		}
	}

	/** @return void */
	private function redirect_result( $page_slug, $result, $success_key, array $extra = array() ) {
		$args = array_merge( array( 'page' => $page_slug ), $extra );
		if ( is_wp_error( $result ) ) {
			$args['certloom_error'] = $result->get_error_message();
		} else {
			$args[ $success_key ] = 1;
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}
}
