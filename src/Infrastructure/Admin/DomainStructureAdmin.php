<?php
namespace Certloom\Infrastructure\Admin;

use Certloom\Application\Fields\ScopedFieldContract;

use Certloom\Infrastructure\Repository\BatchRepository;
use Certloom\Infrastructure\Repository\IssuerRepository;
use Certloom\Infrastructure\Repository\ProgramRepository;
use Certloom\Infrastructure\Repository\RecipientRepository;
use Certloom\Infrastructure\Repository\SignerRepository;
use Certloom\Infrastructure\Repository\TemplateRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Gated Phase-1 administration for Batches and signer hierarchy. */
final class DomainStructureAdmin {
	const BATCHES_PAGE = 'certloom-domain-batches';
	const SIGNERS_PAGE = 'certloom-domain-signers';
	/** @var array<string,mixed>|null Program fixed by the embedded view or a verified write. */
	private $program_context = null;

	/** @return void */
	public function register() {
		add_action( 'admin_init', array( $this, 'redirect_legacy_batches' ) );
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
			null,
			__( 'Batches', 'certloom' ),
			__( 'Batches', 'certloom' ),
			\Ecapla_CertVerify_Permissions::CAP_MANAGE_BATCHES,
			self::BATCHES_PAGE,
			array( $this, 'render_batches' )
		);
		add_submenu_page(
			null,
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
		$data = $this->batch_request();
		if ( is_wp_error( $data ) ) { $this->redirect_result( self::BATCHES_PAGE, $data, 'batch_created', array( 'certloom_new' => 1, 'certloom_retry' => 1 ) ); }
		$result = ( new BatchRepository() )->create( $data );
		if ( is_wp_error( $result ) ) { $this->redirect_result( self::BATCHES_PAGE, $result, 'batch_created', array( 'certloom_new' => 1, 'certloom_retry' => 1 ) ); }
		$template_result = isset( $_POST['batch_template_id'] ) ? $this->save_batch_template_assignment( absint( $result ), absint( $_POST['batch_template_id'] ) ) : true;
		if ( is_wp_error( $template_result ) ) { $this->redirect_result( self::BATCHES_PAGE, $template_result, 'batch_created', array( 'batch_id' => absint( $result ) ) ); }
		$this->redirect_result( self::BATCHES_PAGE, $result, 'batch_created', array( 'batch_id' => absint( $result ) ) );
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

		$data = $this->batch_request( $existing );
		if ( is_wp_error( $data ) ) { $this->redirect_result( self::BATCHES_PAGE, $data, 'batch_updated', array( 'batch_id' => $id, 'certloom_retry' => 1 ) ); }
		$result = $repo->update( $id, $data );
		if ( is_wp_error( $result ) ) { $this->redirect_result( self::BATCHES_PAGE, $result, 'batch_updated', array( 'batch_id' => $id, 'certloom_retry' => 1 ) ); }
		$template_result = isset( $_POST['batch_template_id'] ) ? $this->save_batch_template_assignment( $id, absint( $_POST['batch_template_id'] ) ) : true;
		if ( is_wp_error( $template_result ) ) { $this->redirect_result( self::BATCHES_PAGE, $template_result, 'batch_updated', array( 'batch_id' => $id ) ); }
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
		if ( isset( $_POST['certloom_program_context'] ) ) {
			$batch = $repo->find( $batch_id );
			$context = is_wp_error( $batch ) ? $batch : ( is_array( $batch ) ? $this->verify_program_context( $batch, absint( $batch['program_id'] ) ) : new \WP_Error( 'certloom_batch_missing', __( 'The batch no longer exists.', 'certloom' ) ) );
			if ( is_wp_error( $context ) ) { $this->redirect_result( self::BATCHES_PAGE, $context, 'batch_membership_changed', array( 'batch_id' => $batch_id ) ); }
		}
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
		$data = $this->signer_request();
		if ( is_wp_error( $data ) ) { $this->redirect_result( self::SIGNERS_PAGE, $data, 'signer_created', array( 'certloom_new' => 1, 'certloom_retry' => 1 ) ); }
		$result = ( new SignerRepository() )->create( $data );
		if ( is_wp_error( $result ) ) { $this->redirect_result( self::SIGNERS_PAGE, $result, 'signer_created', array( 'certloom_new' => 1, 'certloom_retry' => 1 ) ); }
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

		$data = $this->signer_request( $existing );
		if ( is_wp_error( $data ) ) { $this->redirect_result( self::SIGNERS_PAGE, $data, 'signer_updated', array( 'signer_id' => $id, 'certloom_retry' => 1 ) ); }
		$result = $repo->update( $id, $data );
		if ( is_wp_error( $result ) ) { $this->redirect_result( self::SIGNERS_PAGE, $result, 'signer_updated', array( 'signer_id' => $id, 'certloom_retry' => 1 ) ); }
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

	/** Preserve bookmarks while keeping batch-only roles on their existing authorized route. */
	public function redirect_legacy_batches() {
		if ( ! isset( $_GET['page'] ) || self::BATCHES_PAGE !== $_GET['page'] || ! current_user_can( \Ecapla_CertVerify_Permissions::CAP_MANAGE_PROGRAMS ) || ! current_user_can( \Ecapla_CertVerify_Permissions::CAP_MANAGE_BATCHES ) ) { return; }
		$batch_id = isset( $_GET['batch_id'] ) ? absint( $_GET['batch_id'] ) : 0;
		$program_id = isset( $_GET['program_id'] ) ? absint( $_GET['program_id'] ) : 0;
		if ( $batch_id ) {
			$batch = ( new BatchRepository() )->find( $batch_id );
			if ( ! is_array( $batch ) ) { return; }
			$program_id = absint( $batch['program_id'] );
		}
		$args = array( 'page' => DomainAdmin::PROGRAMS_PAGE );
		if ( $program_id ) {
			$program = ( new ProgramRepository() )->find( $program_id );
			if ( ! is_array( $program ) ) { return; }
			$this->program_context = $program;
			$args = $this->batch_route_args();
			if ( $batch_id ) { $args['batch_id'] = $batch_id; }
			if ( isset( $_GET['certloom_new'] ) ) { $args['batch_new'] = 1; }
			foreach ( array( 's', 'status', 'paged', 'member_s', 'member_paged', 'recipient_s', 'certloom_error', 'certloom_retry', 'batch_created', 'batch_updated', 'batch_membership_changed' ) as $key ) {
				if ( isset( $_GET[ $key ] ) && is_scalar( $_GET[ $key ] ) ) { $args[ $key ] = sanitize_text_field( wp_unslash( $_GET[ $key ] ) ); }
			}
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/** Render only the selected Program's batches inside its existing admin shell. */
	public function render_program_batches( array $program ) {
		$this->require_capability( \Ecapla_CertVerify_Permissions::CAP_MANAGE_PROGRAMS );
		$this->require_capability( \Ecapla_CertVerify_Permissions::CAP_MANAGE_BATCHES );
		if ( empty( $program['id'] ) ) { $this->error( __( 'Choose a Program to manage its batches.', 'certloom' ) ); return; }
		$this->program_context = $program;
		$repo = new BatchRepository();
		$edit_id = isset( $_GET['batch_id'] ) ? absint( $_GET['batch_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation.
		$creating = isset( $_GET['batch_new'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation.
		$editing = $edit_id ? $repo->find( $edit_id ) : null;
		$this->notice();
		$back = $creating || $edit_id;
		echo '<section class="ecapla-certverify-admin-card certloom-workspace-card"><header class="certloom-workspace-card-head certloom-program-batches-head"><div><span class="certloom-eyebrow">' . esc_html( (string) $program['title'] ) . '</span><h2>' . esc_html__( 'Batches', 'certloom' ) . '</h2><p>' . esc_html__( 'Optional groups for this Program, such as classes, cohorts or intakes. Each batch starts with the Program’s fields and certificate design.', 'certloom' ) . '</p></div><a class="button' . ( $back ? '' : ' button-primary' ) . '" href="' . esc_url( $this->batch_url( $back ? array() : array( 'batch_new' => 1 ) ) ) . '">' . esc_html( $back ? __( 'Back to batches', 'certloom' ) : __( 'Add batch', 'certloom' ) ) . '</a></header></section>';
		if ( $edit_id ) {
			if ( is_wp_error( $editing ) ) { $this->error( $editing->get_error_message() ); return; }
			if ( ! is_array( $editing ) || absint( $editing['program_id'] ) !== absint( $program['id'] ) ) { $this->error( __( 'This batch does not belong to this Program. Return to its batches to choose another.', 'certloom' ) ); return; }
			$this->batch_form( $editing );
			$this->batch_members( $repo, $editing );
			return;
		}
		if ( $creating ) { $this->batch_form(); return; }
		$search = $this->search_value();
		$page = $this->page_value();
		$status = $this->status_filter();
		$result = $repo->list_page( $search, $page, 50, $status, absint( $program['id'] ) );
		$this->filters( self::BATCHES_PAGE, $search, $status, __( 'Search batches in this Program', 'certloom' ) );
		$this->batch_list( $result, $page, $search, $status );
	}

	/** Compatibility workspace for roles that can manage batches but not Programs. */
	public function render_batches() {
		$this->require_capability( \Ecapla_CertVerify_Permissions::CAP_MANAGE_BATCHES );
		$search  = $this->search_value();
		$page    = $this->page_value();
		$status  = $this->status_filter();
		$repo    = new BatchRepository();
		$result  = $repo->list_page( $search, $page, 50, $status );
		$summary = $repo->summary();
		$edit_id = isset( $_GET['batch_id'] ) ? absint( $_GET['batch_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation.
		$editing = $edit_id ? $repo->find( $edit_id ) : null;
		if ( $edit_id && null === $editing ) { $editing = new \WP_Error( 'certloom_batch_missing', __( 'The batch no longer exists.', 'certloom' ) ); }
		$creating = isset( $_GET['certloom_new'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin navigation.
		$action_url = $creating || $edit_id ? add_query_arg( 'page', self::BATCHES_PAGE, admin_url( 'admin.php' ) ) : add_query_arg( array( 'page' => self::BATCHES_PAGE, 'certloom_new' => 1 ), admin_url( 'admin.php' ) );

		$this->header( __( 'Batches & cohorts', 'certloom' ), AdminUiMode::is_simple() ? __( 'Organize recipients into cohorts or intakes for a Program.', 'certloom' ) : __( 'Group recipients under a stable batch code and Program without collapsing credential history into LMS-specific records.', 'certloom' ), array( 'label' => $creating || $edit_id ? __( 'Back to batches', 'certloom' ) : __( 'Add batch', 'certloom' ), 'url' => $action_url, 'secondary' => (bool) ( $creating || $edit_id ) ) );
		$this->notice();
		if ( $creating || $edit_id ) {
			if ( is_wp_error( $editing ) ) { $this->error( $editing->get_error_message() ); }
			elseif ( is_array( $editing ) ) { $this->batch_form( $editing ); $this->batch_members( $repo, $editing ); }
			else { $this->batch_form(); }
			$this->footer();
			return;
		}

		if ( ! is_wp_error( $summary ) ) {
			$this->metric_grid(
				array(
					array( 'value' => $summary['total'], 'label' => __( 'Batches', 'certloom' ) ),
					array( 'value' => $summary['active'], 'label' => _x( 'Active', 'entity status', 'certloom' ) ),
					array( 'value' => $summary['members'], 'label' => __( 'Memberships', 'certloom' ) ),
					array( 'value' => $summary['with_credentials'], 'label' => __( 'With credentials', 'certloom' ) ),
				)
			);
		}
		$this->filters( self::BATCHES_PAGE, $search, $status, __( 'Search batch name, code, or Program', 'certloom' ) );

		$this->batch_list( $result, $page, $search, $status );

		if ( is_wp_error( $editing ) ) {
			$this->error( $editing->get_error_message() );
		} elseif ( is_array( $editing ) ) {
			$this->batch_form( $editing );
			$this->batch_members( $repo, $editing );
		} elseif ( $creating ) {
			$this->batch_form();
		}
		$this->footer();
	}

	/** Shared bounded list for the Program tab and legacy capability-only workspace. */
	private function batch_list( $result, $page, $search, $status ) {
		if ( is_wp_error( $result ) ) {
			$this->error( $result->get_error_message() );
		} elseif ( AdminUiMode::is_simple() ) {
			echo '<div class="certloom-premium-list certloom-premium-list-batches">';
			foreach ( $result['items'] as $item ) {
				$url = $this->batch_url( array( 'batch_id' => absint( $item['id'] ) ) );
				$start = trim( (string) ( $item['start_date'] ?? '' ) );
				$end = trim( (string) ( $item['end_date'] ?? '' ) );
				$dates = ( '' !== $start || '' !== $end ) ? ( ( '' !== $start ? $start : __( 'No start date', 'certloom' ) ) . ' → ' . ( '' !== $end ? $end : __( 'No end date', 'certloom' ) ) ) : __( 'Dates not set', 'certloom' );
				$instructor = trim( (string) ( $item['instructor_signer_name'] ?? '' ) );
				if ( '' === $instructor ) { $instructor = trim( (string) ( $item['instructor_name'] ?? '' ) ); }
				$context = array();
				if ( ! empty( $item['program_title'] ) ) { $context[] = (string) $item['program_title']; }
				$context[] = $dates;
				$context[] = '' !== $instructor ? sprintf( /* translators: %s: instructor name. */ __( 'Instructor: %s', 'certloom' ), $instructor ) : __( 'No instructor', 'certloom' );
				$status_label = ! empty( $item['active'] ) ? _x( 'Active', 'entity status', 'certloom' ) : _x( 'Inactive', 'entity status', 'certloom' );
				echo '<article class="certloom-premium-list-row certloom-batch-list-row"><div class="certloom-premium-list-main"><strong><a href="' . esc_url( $url ) . '">' . esc_html( (string) $item['name'] ) . '</a></strong><span>' . esc_html( implode( ' · ', $context ) ) . '</span></div><span class="certloom-domain-status ' . ( ! empty( $item['active'] ) ? 'is-active' : 'is-inactive' ) . '">' . esc_html( $status_label ) . '</span><div class="certloom-premium-list-meta"><span><small>' . esc_html__( 'Credentials issued', 'certloom' ) . '</small><strong>' . esc_html( number_format_i18n( absint( $item['credential_count'] ?? 0 ) ) ) . '</strong></span><span><small>' . esc_html__( 'Recipients', 'certloom' ) . '</small><strong>' . esc_html( number_format_i18n( absint( $item['recipient_count'] ?? 0 ) ) ) . '</strong></span></div><div class="certloom-premium-list-actions"><a class="certloom-premium-row-link" href="' . esc_url( $url ) . '">' . esc_html__( 'Details', 'certloom' ) . ' <span aria-hidden="true">→</span></a></div></article>';
			}
			if ( empty( $result['items'] ) ) { $add_url = $this->batch_url( array( 'certloom_new' => 1 ) ); echo '<div class="certloom-premium-empty-state certloom-empty-compact"><h3>' . esc_html( '' !== $search || 'all' !== $status ? __( 'No matching batches', 'certloom' ) : __( 'No batches yet', 'certloom' ) ) . '</h3><p>' . esc_html__( 'Add a cohort or intake when a Program needs grouped issuance.', 'certloom' ) . '</p><a class="button button-primary" href="' . esc_url( $add_url ) . '">' . esc_html__( 'Add batch', 'certloom' ) . '</a></div>'; }
			echo '</div>';
			$this->pagination( self::BATCHES_PAGE, $page, (int) $result['total'], 50, $search, $status );
		} else {
			echo '<div class="certloom-domain-table-wrap"><table class="widefat striped certloom-domain-table"><thead><tr><th>' . esc_html__( 'Batch', 'certloom' ) . '</th><th>' . esc_html__( 'Program', 'certloom' ) . '</th><th>' . esc_html__( 'Dates', 'certloom' ) . '</th><th>' . esc_html__( 'Instructor', 'certloom' ) . '</th><th>' . esc_html__( 'Recipients', 'certloom' ) . '</th><th>' . esc_html__( 'Credentials', 'certloom' ) . '</th><th>' . esc_html__( 'Status', 'certloom' ) . '</th></tr></thead><tbody>';
			foreach ( $result['items'] as $item ) {
				$url        = $this->batch_url( array( 'batch_id' => absint( $item['id'] ) ) );
				$dates      = trim( (string) ( $item['start_date'] ?: '—' ) . ' → ' . (string) ( $item['end_date'] ?: '—' ) );
				$instructor = (string) ( $item['instructor_signer_name'] ?: $item['instructor_name'] ?: '—' );
				echo '<tr><td><strong><a href="' . esc_url( $url ) . '">' . esc_html( (string) $item['name'] ) . '</a></strong><div><code>' . esc_html( (string) $item['code'] ) . '</code></div></td><td>' . esc_html( (string) $item['program_title'] ) . '</td><td>' . esc_html( $dates ) . '</td><td>' . esc_html( $instructor ) . '</td><td>' . esc_html( (string) absint( $item['recipient_count'] ) ) . '</td><td>' . esc_html( (string) absint( $item['credential_count'] ) ) . '</td><td><span class="certloom-domain-status ' . ( ! empty( $item['active'] ) ? 'is-active' : 'is-inactive' ) . '">' . esc_html( ! empty( $item['active'] ) ? _x( 'Active', 'entity status', 'certloom' ) : _x( 'Inactive', 'entity status', 'certloom' ) ) . '</span></td></tr>';
			}
			if ( empty( $result['items'] ) ) { echo '<tr><td colspan="7">' . esc_html__( 'No batches found.', 'certloom' ) . '</td></tr>'; }
			echo '</tbody></table></div>';
			$this->pagination( self::BATCHES_PAGE, $page, (int) $result['total'], 50, $search, $status );
		}
	}

	/** @return void */
	public function render_signers() {
		$this->require_capability( \Ecapla_CertVerify_Permissions::CAP_MANAGE_SIGNERS );
		$search  = $this->search_value();
		$page    = $this->page_value();
		$status  = $this->status_filter();
		$repo    = new SignerRepository();
		$result  = $repo->list_page( $search, $page, 50, $status );
		$summary = $repo->summary();
		$edit_id = isset( $_GET['signer_id'] ) ? absint( $_GET['signer_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation.
		$editing = $edit_id ? $repo->find( $edit_id ) : null;
		$creating = isset( $_GET['certloom_new'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin navigation.
		$action_url = $creating || $edit_id ? add_query_arg( 'page', self::SIGNERS_PAGE, admin_url( 'admin.php' ) ) : add_query_arg( array( 'page' => self::SIGNERS_PAGE, 'certloom_new' => 1 ), admin_url( 'admin.php' ) );

		$this->header( __( 'Signers', 'certloom' ), AdminUiMode::is_simple() ? __( 'Add the people whose names or signatures can appear on credentials.', 'certloom' ) : __( 'Reusable signer identities with deterministic Batch → Program → Issuer → Global hierarchy.', 'certloom' ), array( 'label' => $creating || $edit_id ? __( 'Back to signers', 'certloom' ) : __( 'Add signer', 'certloom' ), 'url' => $action_url, 'secondary' => (bool) ( $creating || $edit_id ) ) );
		$this->notice();
		if ( $creating || $edit_id ) {
			if ( is_wp_error( $editing ) ) { $this->error( $editing->get_error_message() ); }
			elseif ( is_array( $editing ) ) { $this->signer_form( $editing ); $this->signer_assignments( $repo, $editing ); }
			else { $this->signer_form(); }
			$this->footer();
			return;
		}

		if ( ! is_wp_error( $summary ) ) {
			$this->metric_grid(
				array(
					array( 'value' => $summary['total'], 'label' => __( 'Signers', 'certloom' ) ),
					array( 'value' => $summary['active'], 'label' => _x( 'Active', 'entity status', 'certloom' ) ),
					array( 'value' => $summary['assignments'], 'label' => AdminUiMode::is_simple() ? __( 'Places used', 'certloom' ) : __( 'Hierarchy uses', 'certloom' ) ),
				)
			);
		}
		$this->filters( self::SIGNERS_PAGE, $search, $status, AdminUiMode::is_simple() ? __( 'Search signer name, title, or email', 'certloom' ) : __( 'Search signer name, key, title, or email', 'certloom' ) );

		if ( is_wp_error( $result ) ) {
			$this->error( $result->get_error_message() );
		} elseif ( AdminUiMode::is_simple() ) {
			echo '<div class="certloom-premium-list certloom-premium-list-signers">';
			foreach ( $result['items'] as $item ) {
				$url = add_query_arg( array( 'page' => self::SIGNERS_PAGE, 'signer_id' => absint( $item['id'] ) ), admin_url( 'admin.php' ) );
				$status_label = ! empty( $item['active'] ) ? _x( 'Active', 'entity status', 'certloom' ) : _x( 'Inactive', 'entity status', 'certloom' );
				echo '<article class="certloom-premium-list-row"><div class="certloom-premium-list-main"><strong><a href="' . esc_url( $url ) . '">' . esc_html( (string) $item['display_name'] ) . '</a></strong><div class="certloom-premium-list-meta">' . esc_html( (string) ( $item['title'] ?: __( 'Signer', 'certloom' ) ) ) . ( ! empty( $item['email'] ) ? ' · ' . esc_html( (string) $item['email'] ) : '' ) . ' · ' . esc_html( sprintf( /* translators: %d: number of places where this signer is used. */ _n( 'Used in %d place', 'Used in %d places', absint( $item['assignment_count'] ), 'certloom' ), absint( $item['assignment_count'] ) ) ) . '</div></div><span class="certloom-domain-status ' . ( ! empty( $item['active'] ) ? 'is-active' : 'is-inactive' ) . '">' . esc_html( $status_label ) . '</span><div class="certloom-premium-list-actions"><a class="certloom-premium-row-link" href="' . esc_url( $url ) . '">' . esc_html__( 'Details', 'certloom' ) . ' <span aria-hidden="true">→</span></a></div></article>';
			}
			if ( empty( $result['items'] ) ) { $add_url = add_query_arg( array( 'page' => self::SIGNERS_PAGE, 'certloom_new' => 1 ), admin_url( 'admin.php' ) ); echo '<div class="certloom-premium-empty-state certloom-empty-compact"><h3>' . esc_html__( 'No signers yet', 'certloom' ) . '</h3><p>' . esc_html__( 'Add a signer whose name or signature can appear on credentials.', 'certloom' ) . '</p><a class="button button-primary" href="' . esc_url( $add_url ) . '">' . esc_html__( 'Add signer', 'certloom' ) . '</a></div>'; }
			echo '</div>';
			$this->pagination( self::SIGNERS_PAGE, $page, (int) $result['total'], 50, $search, $status );
		} else {
			echo '<div class="certloom-domain-table-wrap"><table class="widefat striped certloom-domain-table"><thead><tr><th>' . esc_html__( 'Signer', 'certloom' ) . '</th><th>' . esc_html__( 'Machine key', 'certloom' ) . '</th><th>' . esc_html__( 'Title', 'certloom' ) . '</th><th>' . esc_html__( 'Email', 'certloom' ) . '</th><th>' . esc_html__( 'Used in', 'certloom' ) . '</th><th>' . esc_html__( 'Status', 'certloom' ) . '</th></tr></thead><tbody>';
			foreach ( $result['items'] as $item ) {
				$url = add_query_arg( array( 'page' => self::SIGNERS_PAGE, 'signer_id' => absint( $item['id'] ) ), admin_url( 'admin.php' ) );
				echo '<tr><td><strong><a href="' . esc_url( $url ) . '">' . esc_html( (string) $item['display_name'] ) . '</a></strong></td><td><code>' . esc_html( (string) $item['slug'] ) . '</code></td><td>' . esc_html( (string) ( $item['title'] ?: '—' ) ) . '</td><td>' . esc_html( (string) ( $item['email'] ?: '—' ) ) . '</td><td>' . esc_html( (string) absint( $item['assignment_count'] ) ) . '</td><td><span class="certloom-domain-status ' . ( ! empty( $item['active'] ) ? 'is-active' : 'is-inactive' ) . '">' . esc_html( ! empty( $item['active'] ) ? _x( 'Active', 'entity status', 'certloom' ) : _x( 'Inactive', 'entity status', 'certloom' ) ) . '</span></td></tr>';
			}
			if ( empty( $result['items'] ) ) { echo '<tr><td colspan="6">' . esc_html__( 'No signer profiles found.', 'certloom' ) . '</td></tr>'; }
			echo '</tbody></table></div>';
			$this->pagination( self::SIGNERS_PAGE, $page, (int) $result['total'], 50, $search, $status );
		}

		if ( is_wp_error( $editing ) ) {
			$this->error( $editing->get_error_message() );
		} elseif ( is_array( $editing ) ) {
			$this->signer_form( $editing );
			$this->signer_assignments( $repo, $editing );
		} elseif ( $creating ) {
			$this->signer_form();
		}
		$this->footer();
	}

	/** Validate the fixed Program in embedded forms before any mutation. Legacy forms retain their contract. */
	private function verify_program_context( ?array $existing, $program_id ) {
		if ( ! isset( $_POST['certloom_program_context'] ) ) { return true; }
		$raw = $_POST['certloom_program_context'];
		$nonce = $_POST['_certloom_program_nonce'] ?? '';
		if ( ! is_scalar( $raw ) || ! ctype_digit( (string) $raw ) || (int) $raw < 1 || ! current_user_can( \Ecapla_CertVerify_Permissions::CAP_MANAGE_PROGRAMS ) || ! is_string( $nonce ) || ! wp_verify_nonce( wp_unslash( $nonce ), 'certloom_program_batches_' . (int) $raw ) ) {
			return new \WP_Error( 'certloom_batch_program_context_invalid', __( 'The Program context has expired. Reopen the Program and try again.', 'certloom' ) );
		}
		if ( (int) $raw !== (int) $program_id || ( is_array( $existing ) && (int) $raw !== (int) $existing['program_id'] ) ) {
			return new \WP_Error( 'certloom_batch_program_context_mismatch', __( 'The batch must belong to the Program shown in this form.', 'certloom' ) );
		}
		$program = ( new ProgramRepository() )->find( (int) $raw );
		if ( is_wp_error( $program ) || ! is_array( $program ) ) { return is_wp_error( $program ) ? $program : new \WP_Error( 'certloom_batch_program_missing', __( 'The Program no longer exists.', 'certloom' ) ); }
		$this->program_context = $program;
		return true;
	}

	/** @return array<string,mixed> */
	private function batch_route_args( array $extra = array() ) {
		$args = array( 'page' => self::BATCHES_PAGE );
		if ( is_array( $this->program_context ) ) {
			$args = array( 'page' => DomainAdmin::PROGRAMS_PAGE, 'program_id' => absint( $this->program_context['id'] ) );
			if ( isset( $extra['certloom_new'] ) ) { $extra['batch_new'] = $extra['certloom_new']; unset( $extra['certloom_new'] ); }
		}
		return array_merge( $args, $extra );
	}

	private function batch_url( array $extra = array() ) {
		return add_query_arg( $this->batch_route_args( $extra ), admin_url( 'admin.php' ) );
	}

	/** Escaped GET route fields shared by batch/member searches. */
	private function batch_route_inputs() {
		$out = '';
		foreach ( $this->batch_route_args() as $name => $value ) { $out .= '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '">'; }
		return $out;
	}

	private function batch_context_fields() {
		if ( ! is_array( $this->program_context ) ) { return; }
		$id = absint( $this->program_context['id'] );
		echo '<input type="hidden" name="certloom_program_context" value="' . esc_attr( (string) $id ) . '">';
		wp_nonce_field( 'certloom_program_batches_' . $id, '_certloom_program_nonce', false );
	}

	/** @param array<string,mixed>|null $editing @return void */
	private function batch_form( ?array $editing = null ) {
		$is_edit  = is_array( $editing );
		$id       = $is_edit ? absint( $editing['id'] ) : 0;
		$metadata = $is_edit && is_array( $editing['metadata'] ?? null ) ? $editing['metadata'] : array();
		$editable = $this->is_scalar_map( $metadata );
		$field_contract = $is_edit && isset( $editing['field_contract'] ) && is_array( $editing['field_contract'] ) ? $editing['field_contract'] : ScopedFieldContract::empty_batch();
		$scopable_fields = \Ecapla_CertVerify_Fields::get_scopable_fields();
		$embedded = is_array( $this->program_context );

		echo '<section id="certloom-domain-editor" class="ecapla-certverify-admin-card certloom-domain-editor"><h2>' . esc_html( $is_edit ? __( 'Batch profile', 'certloom' ) : __( 'Add batch', 'certloom' ) ) . '</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-certloom-preserve-on-error data-certloom-entity-form="batch" data-certloom-saving-message="' . esc_attr( $is_edit ? __( 'Saving batch…', 'certloom' ) : __( 'Adding batch…', 'certloom' ) ) . '" data-certloom-invalid-message="' . esc_attr__( 'Please complete the highlighted required field before saving this batch.', 'certloom' ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( $is_edit ? 'certloom_domain_update_batch' : 'certloom_domain_create_batch' ) . '">';
		if ( $is_edit ) {
			echo '<input type="hidden" name="batch_id" value="' . esc_attr( $id ) . '">';
			wp_nonce_field( 'certloom_domain_update_batch_' . $id );
		} else {
			wp_nonce_field( 'certloom_domain_create_batch' );
		}

		$this->batch_context_fields();
		echo '<div class="certloom-domain-form-grid">';
		if ( $embedded ) {
			echo '<input type="hidden" name="program_id" value="' . esc_attr( (string) absint( $this->program_context['id'] ) ) . '">';
		} else { $this->program_select( $is_edit ? $editing['program_id'] : 0 ); }
		$this->text_field( 'name', __( 'Batch name', 'certloom' ), $is_edit ? (string) $editing['name'] : '', true, 'text', '', '', __( 'Example: Spring 2026 Cohort', 'certloom' ) );
		if ( $embedded ) { echo '</div><p class="description">' . esc_html__( 'That is all you need to start. Dates, signers and batch-specific changes are optional.', 'certloom' ) . '</p><details class="certloom-technical-details certloom-batch-options"><summary>' . esc_html__( 'Optional batch settings', 'certloom' ) . '</summary><div class="certloom-domain-form-grid">'; }
		if ( ! $embedded && AdminUiMode::is_simple() && ! $is_edit ) {
			echo '<div class="certloom-domain-field certloom-domain-field-wide"><details class="certloom-technical-details certloom-inline-technical"><summary>' . esc_html__( 'Technical details', 'certloom' ) . '</summary>';
		}
		$this->text_field( 'code', $is_edit ? __( 'Batch code', 'certloom' ) : __( 'Batch code (optional)', 'certloom' ), $is_edit ? (string) $editing['code'] : '', $is_edit, 'text', __( 'A stable reference for imports and integrations. Leave blank when adding a Batch to generate one automatically.', 'certloom' ), 'maxlength="100"', __( 'Example: SPRING-2026', 'certloom' ) );
		if ( ! $embedded && AdminUiMode::is_simple() && ! $is_edit ) { echo '</details></div>'; }
		$this->text_field( 'start_date', __( 'Start date', 'certloom' ), $is_edit ? (string) $editing['start_date'] : '', false, 'date' );
		$this->text_field( 'end_date', __( 'End date', 'certloom' ), $is_edit ? (string) $editing['end_date'] : '', false, 'date' );
		$this->signer_select( 'instructor_signer_id', __( 'Signer / instructor profile', 'certloom' ), $is_edit ? $editing['instructor_signer_id'] : 0 );
		if ( $is_edit && empty( $editing['instructor_signer_id'] ) && '' !== trim( (string) ( $editing['instructor_name'] ?? '' ) ) ) {
			echo '<div class="certloom-domain-field certloom-domain-field-wide"><div class="notice notice-warning inline"><p>' . esc_html( sprintf( /* translators: %s: legacy instructor display name. */ __( 'Legacy instructor label preserved: %s. Saving this Batch will reuse an exact-name Signer when one exists or create one lightweight Signer profile, then record the canonical Batch instructor assignment.', 'certloom' ), (string) $editing['instructor_name'] ) ) . '</p></div></div>';
		}
		echo '<div class="certloom-domain-field"><label><input type="checkbox" name="active" value="1" ' . checked( $is_edit ? ! empty( $editing['active'] ) : true, true, false ) . '> <strong>' . esc_html__( 'Active batch', 'certloom' ) . '</strong></label></div></div>';

		echo '<div class="certloom-domain-subsection certloom-batch-field-contract"><div class="certloom-domain-subsection-head"><div><h3>' . esc_html__( 'Credential field changes', 'certloom' ) . '</h3><p>' . esc_html__( 'Choose only the fields this Batch adds, tightens or excludes. Program-required fields remain required automatically; technical metadata stays out of this setup.', 'certloom' ) . '</p></div></div>';
		$program = $embedded ? $this->program_context : ( $is_edit && absint( $editing['program_id'] ?? 0 ) ? ( new ProgramRepository() )->find( absint( $editing['program_id'] ) ) : null );
		$parent_rules = is_array( $program ) && is_array( $program['field_contract']['fields'] ?? null ) ? $program['field_contract']['fields'] : array();
		$current_rules = is_array( $field_contract['fields'] ?? null ) ? $field_contract['fields'] : array();
		$this->batch_field_editor( $scopable_fields, $parent_rules, $current_rules );
		if ( ! $is_edit && ! $embedded ) { echo '<p class="description">' . esc_html__( 'The selected Program is validated when you save; any Program-required field remains protected even if this new Batch form was opened before the Program was selected.', 'certloom' ) . '</p>'; }
		echo '</div>';
		$this->metadata_editor( 'batch', $metadata, $editable );
		if ( $embedded ) { echo '</details>'; }
		echo '<div class="certloom-domain-form-feedback" data-certloom-domain-form-feedback aria-live="polite"></div>';
		submit_button( $is_edit ? __( 'Update batch', 'certloom' ) : __( 'Add batch', 'certloom' ), 'primary' );
		echo '</form></section>';
		if ( $is_edit ) { ( new DesignRulesAdmin() )->context_card( 'batch', $id ); }
		if ( ! $embedded ) { $this->batch_quick_program_dialog(); }
	}

	/** Quick-create a Program without abandoning an unsaved Batch form. */
	private function batch_quick_program_dialog() {
		$fields = \Ecapla_CertVerify_Fields::get_scopable_fields();
		echo '<dialog id="certloom-batch-quick-program-dialog" class="certloom-premium-dialog certloom-premium-dialog-scroll"><form data-certloom-quick-create="program" data-certloom-quick-target="#certloom-batch-program-id" data-certloom-human-form data-certloom-invalid-message="' . esc_attr__( 'Complete the highlighted Program field.', 'certloom' ) . '"><input type="hidden" name="action" value="certloom_manual_quick_program"><input type="hidden" name="nonce" value="' . esc_attr( wp_create_nonce( 'certloom_manual_quick_program' ) ) . '">';
		echo '<div class="certloom-dialog-head"><div><span class="certloom-eyebrow">' . esc_html__( 'Quick setup', 'certloom' ) . '</span><h2>' . esc_html__( 'Add Program', 'certloom' ) . '</h2><p>' . esc_html__( 'Create the Program and its required credential fields, then return to this unfinished Batch automatically. The new Program inherits its parent designs by default.', 'certloom' ) . '</p></div><button type="button" class="button-link" data-certloom-dialog-close aria-label="' . esc_attr__( 'Close Add Program', 'certloom' ) . '">×</button></div>';
		echo '<div class="certloom-dialog-body"><div class="certloom-manual-grid certloom-manual-grid-2"><label><span>' . esc_html__( 'Program title', 'certloom' ) . '</span><input type="text" name="program_title" maxlength="255" required data-certloom-required-example="' . esc_attr__( 'Example: Internal Auditor Certification', 'certloom' ) . '"><small>' . esc_html__( 'Example: Internal Auditor Certification', 'certloom' ) . '</small></label><label><span>' . esc_html__( 'Short code (optional)', 'certloom' ) . '</span><input type="text" name="program_code" maxlength="100" placeholder="IA-101"></label></div>';
		echo '<div class="certloom-dialog-section certloom-dialog-inheritance-note"><h3>' . esc_html__( 'Designs', 'certloom' ) . '</h3><p>' . esc_html__( 'This quick-created Program inherits its Issuer and Global certificate/email designs. Use its Designs card afterward only when it needs an override.', 'certloom' ) . '</p></div>';
		echo '<div class="certloom-dialog-section"><h3>' . esc_html__( 'Credential fields', 'certloom' ) . '</h3><p>' . esc_html__( 'Choose only fields this Program needs when credentials are issued. Technical metadata is intentionally kept out of quick setup.', 'certloom' ) . '</p><div class="certloom-quick-field-requirements">';
		foreach ( $fields as $field ) { $key = sanitize_key( (string) ( $field['key'] ?? '' ) ); if ( '' === $key ) { continue; } echo '<label><span>' . esc_html( (string) ( $field['label'] ?? $key ) ) . '</span><select name="program_field_requirement[' . esc_attr( $key ) . ']"><option value="">' . esc_html__( 'Not used', 'certloom' ) . '</option><option value="optional">' . esc_html__( 'Optional', 'certloom' ) . '</option><option value="required">' . esc_html__( 'Required', 'certloom' ) . '</option></select></label>'; }
		echo '</div><p class="description">' . esc_html__( 'Visit Programs after saving for language variants, lifecycle defaults and other deeper Program settings.', 'certloom' ) . '</p></div></div>';
		echo '<div class="certloom-dialog-actions"><button type="button" class="button" data-certloom-dialog-close>' . esc_html__( 'Cancel', 'certloom' ) . '</button><button type="submit" class="button button-primary">' . esc_html__( 'Add Program', 'certloom' ) . '</button></div></form></dialog>';
	}

	/** @param BatchRepository $repo @param array<string,mixed> $batch @return void */
	public function render_workspace_members( array $program, array $batch ) {
		$this->require_capability( \Ecapla_CertVerify_Permissions::CAP_MANAGE_PROGRAMS );
		$this->require_capability( \Ecapla_CertVerify_Permissions::CAP_MANAGE_BATCHES );
		if ( (int) $batch['program_id'] !== (int) $program['id'] ) { return; }
		$this->program_context = $program;
		$this->batch_members( new BatchRepository(), $batch );
	}

	private function batch_members( BatchRepository $repo, array $batch ) {
		$batch_id       = absint( $batch['id'] );
		$member_search  = isset( $_GET['member_s'] ) ? sanitize_text_field( wp_unslash( $_GET['member_s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only search.
		$member_page    = isset( $_GET['member_paged'] ) ? max( 1, absint( $_GET['member_paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination.
		$recipient_find = isset( $_GET['recipient_s'] ) ? sanitize_text_field( wp_unslash( $_GET['recipient_s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only search.
		$members        = $repo->members_page( $batch_id, $member_search, $member_page, 50 );

		echo '<section class="ecapla-certverify-admin-card certloom-domain-history"><h2>' . esc_html__( 'Batch recipients', 'certloom' ) . '</h2><p>' . esc_html__( 'Adding or removing a recipient changes this Batch going forward. Already-issued credential records are never rewritten.', 'certloom' ) . '</p>';
		echo '<form method="get" class="certloom-domain-search">' . $this->batch_route_inputs() . '<input type="hidden" name="batch_id" value="' . esc_attr( $batch_id ) . '"><label class="screen-reader-text" for="certloom-batch-member-search">' . esc_html__( 'Search batch recipients', 'certloom' ) . '</label><input id="certloom-batch-member-search" type="search" name="member_s" value="' . esc_attr( $member_search ) . '" placeholder="' . esc_attr__( 'Search assigned recipients', 'certloom' ) . '"><button type="submit" class="button">' . esc_html__( 'Search members', 'certloom' ) . '</button></form>';

		if ( is_wp_error( $members ) ) {
			$this->error( $members->get_error_message() );
			echo '</section>';
			return;
		}

		echo '<div class="certloom-domain-table-wrap"><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Recipient', 'certloom' ) . '</th><th>' . esc_html__( 'Email', 'certloom' ) . '</th><th>' . esc_html__( 'External ID', 'certloom' ) . '</th><th>' . esc_html__( 'Action', 'certloom' ) . '</th></tr></thead><tbody>';
		foreach ( $members['items'] as $member ) {
			echo '<tr><td>' . esc_html( (string) $member['display_name'] ) . '</td><td>' . esc_html( (string) ( $member['email'] ?: '—' ) ) . '</td><td>' . esc_html( (string) ( $member['external_id'] ?: '—' ) ) . '</td><td><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-certloom-preserve-on-error>';
			wp_nonce_field( 'certloom_domain_batch_member_' . $batch_id );
			$this->batch_context_fields();
			echo '<input type="hidden" name="action" value="certloom_domain_batch_member"><input type="hidden" name="membership_action" value="remove"><input type="hidden" name="batch_id" value="' . esc_attr( $batch_id ) . '"><input type="hidden" name="recipient_id" value="' . esc_attr( absint( $member['id'] ) ) . '"><button type="submit" class="button button-small">' . esc_html__( 'Remove', 'certloom' ) . '</button></form></td></tr>';
		}
		if ( empty( $members['items'] ) ) {
			echo '<tr><td colspan="4">' . esc_html__( 'No recipients match this Batch view.', 'certloom' ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
		$this->member_pagination( $batch_id, $member_page, (int) $members['total'], 50, $member_search, $recipient_find );

		echo '<div class="certloom-domain-subsection"><h3>' . esc_html__( 'Add recipient', 'certloom' ) . '</h3><form method="get" class="certloom-domain-search">' . $this->batch_route_inputs() . '<input type="hidden" name="batch_id" value="' . esc_attr( $batch_id ) . '"><input type="hidden" name="member_s" value="' . esc_attr( $member_search ) . '"><label class="screen-reader-text" for="certloom-batch-recipient-search">' . esc_html__( 'Find a recipient to add', 'certloom' ) . '</label><input id="certloom-batch-recipient-search" type="search" name="recipient_s" value="' . esc_attr( $recipient_find ) . '" placeholder="' . esc_attr__( 'Find recipient by name, email, or external ID', 'certloom' ) . '"><button type="submit" class="button">' . esc_html__( 'Find recipient', 'certloom' ) . '</button></form>';
		$recipients = ( new RecipientRepository() )->list_page( $recipient_find, 1, 50 );
		if ( is_wp_error( $recipients ) ) {
			$this->error( $recipients->get_error_message() );
		} else {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-certloom-preserve-on-error>';
			wp_nonce_field( 'certloom_domain_batch_member_' . $batch_id );
			$this->batch_context_fields();
			echo '<input type="hidden" name="action" value="certloom_domain_batch_member"><input type="hidden" name="membership_action" value="add"><input type="hidden" name="batch_id" value="' . esc_attr( $batch_id ) . '"><label for="certloom-batch-recipient-id"><strong>' . esc_html__( 'Recipient', 'certloom' ) . '</strong></label> <select id="certloom-batch-recipient-id" name="recipient_id" required><option value="">' . esc_html__( 'Choose recipient…', 'certloom' ) . '</option>';
			foreach ( $recipients['items'] as $recipient ) {
				$label = (string) $recipient['display_name'];
				if ( ! empty( $recipient['email'] ) ) {
					$label .= ' — ' . (string) $recipient['email'];
				}
				echo '<option value="' . esc_attr( absint( $recipient['id'] ) ) . '">' . esc_html( $label ) . '</option>';
			}
			echo '</select> <button type="submit" class="button">' . esc_html__( 'Add to batch', 'certloom' ) . '</button></form>';
			if ( (int) $recipients['total'] > 50 ) {
				echo '<p class="description">' . esc_html__( 'More than 50 recipients match. Refine the search before choosing a recipient.', 'certloom' ) . '</p>';
			}
		}
		echo '</div></section>';
	}

	/** @param array<string,mixed>|null $editing @return void */
	private function signer_form( ?array $editing = null ) {
		$is_edit  = is_array( $editing );
		$id       = $is_edit ? absint( $editing['id'] ) : 0;
		$metadata = $is_edit && is_array( $editing['metadata'] ?? null ) ? $editing['metadata'] : array();
		$editable = $this->is_scalar_map( $metadata );

		echo '<section id="certloom-domain-editor" class="ecapla-certverify-admin-card certloom-domain-editor"><h2>' . esc_html( $is_edit ? __( 'Signer profile', 'certloom' ) : __( 'Add signer', 'certloom' ) ) . '</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-certloom-preserve-on-error data-certloom-entity-form="signer" data-certloom-saving-message="' . esc_attr( $is_edit ? __( 'Saving signer…', 'certloom' ) : __( 'Adding signer…', 'certloom' ) ) . '" data-certloom-invalid-message="' . esc_attr__( 'Please complete the highlighted required field before saving this signer.', 'certloom' ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( $is_edit ? 'certloom_domain_update_signer' : 'certloom_domain_create_signer' ) . '">';
		if ( $is_edit ) {
			echo '<input type="hidden" name="signer_id" value="' . esc_attr( $id ) . '">';
			wp_nonce_field( 'certloom_domain_update_signer_' . $id );
		} else {
			wp_nonce_field( 'certloom_domain_create_signer' );
		}

		echo '<div class="certloom-domain-form-grid">';
		$this->text_field( 'display_name', __( 'Signer name', 'certloom' ), $is_edit ? (string) $editing['display_name'] : '', true, 'text', '', '', __( 'Example: Dr. Amina Khan', 'certloom' ) );
		if ( AdminUiMode::is_simple() ) {
			echo '<div class="certloom-domain-field certloom-domain-field-wide"><details class="certloom-technical-details certloom-inline-technical"><summary>' . esc_html__( 'Technical details', 'certloom' ) . '</summary><label><span><strong>' . esc_html__( 'Machine key', 'certloom' ) . '</strong></span><input type="text" name="slug" maxlength="191" pattern="[a-z0-9_-]+" value="' . esc_attr( $is_edit ? (string) $editing['slug'] : '' ) . '" placeholder="' . esc_attr__( 'Generated automatically when left blank', 'certloom' ) . '"><small>' . esc_html__( 'Stable machine identity used by restore, hierarchy rules and integrations. Leave blank when creating a signer to generate it from the signer name.', 'certloom' ) . '</small></label></details></div>';
		} else {
			$this->text_field( 'slug', __( 'Machine key', 'certloom' ), $is_edit ? (string) $editing['slug'] : '', true, 'text', __( 'Stable machine identity used by restore, hierarchy assignment, integrations, and future APIs.', 'certloom' ), '', __( 'Example: amina-khan', 'certloom' ) );
		}
		$this->text_field( 'title', __( 'Position / designation', 'certloom' ), $is_edit ? (string) $editing['title'] : '', false, 'text', __( 'The person’s professional title or designation. Their contextual use as Signer or Instructor is assigned separately below.', 'certloom' ) );
		$this->text_field( 'email', __( 'Email', 'certloom' ), $is_edit ? (string) $editing['email'] : '', false, 'email' );
		$this->media_field( 'signature_attachment_id', __( 'Signature image', 'certloom' ), $is_edit ? absint( $editing['signature_attachment_id'] ?? 0 ) : 0, __( 'Choose or upload the signer signature image from WordPress Media.', 'certloom' ) );
		echo '<div class="certloom-domain-field"><label><input type="checkbox" name="active" value="1" ' . checked( $is_edit ? ! empty( $editing['active'] ) : true, true, false ) . '> <strong>' . esc_html__( 'Active signer', 'certloom' ) . '</strong></label></div></div>';
		$this->metadata_editor( 'signer', $metadata, $editable );
		echo '<div class="certloom-domain-form-feedback" data-certloom-domain-form-feedback aria-live="polite"></div>';
		submit_button( $is_edit ? __( 'Update signer', 'certloom' ) : __( 'Add signer', 'certloom' ), 'primary' );
		echo '</form></section>';
	}

	/** @param SignerRepository $repo @param array<string,mixed> $signer @return void */
	private function signer_assignments( SignerRepository $repo, array $signer ) {
		$signer_id = absint( $signer['id'] );
		$rows      = $repo->assignments( $signer_id );
		$simple    = AdminUiMode::is_simple();
		echo '<section class="ecapla-certverify-admin-card certloom-domain-history certloom-signer-usage"><h2>' . esc_html( $simple ? __( 'Where this signer is used', 'certloom' ) : __( 'Signer hierarchy rules', 'certloom' ) ) . '</h2><p>' . esc_html( $simple ? __( 'Choose where this person should be available on credentials. A Batch choice is most specific, followed by Program, Issuer and Global.', 'certloom' ) : __( 'These rules determine where the signer is available. Certloom resolves Batch → Program → Issuer → Global; when multiple rules apply at the same level, the lower priority number wins.', 'certloom' ) ) . '</p>';
		if ( is_wp_error( $rows ) ) {
			$this->error( $rows->get_error_message() );
			echo '</section>';
			return;
		}

		echo '<div class="certloom-domain-table-wrap"><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Used for', 'certloom' ) . '</th><th>' . esc_html__( 'Use as', 'certloom' ) . '</th>' . ( $simple ? '' : '<th>' . esc_html__( 'Priority', 'certloom' ) . '</th>' ) . '<th>' . esc_html__( 'Action', 'certloom' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$scope_cell = esc_html( (string) $row['scope_label'] );
			if ( $simple ) {
				$scope_cell .= '<details class="certloom-technical-details certloom-signer-rule-technical"><summary>' . esc_html__( 'Technical details', 'certloom' ) . '</summary><small>' . esc_html( sprintf( /* translators: %d: signer hierarchy priority. */ __( 'Priority: %d (lower numbers win when two rules apply at the same level).', 'certloom' ), absint( $row['priority'] ?? 100 ) ) ) . '</small></details>';
			}
			echo '<tr><td>' . $scope_cell . '</td><td>' . esc_html( 'instructor' === (string) $row['role_type'] ? __( 'Instructor', 'certloom' ) : __( 'Signer', 'certloom' ) ) . '</td>' . ( $simple ? '' : '<td>' . esc_html( (string) $row['priority'] ) . '</td>' ) . '<td><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-certloom-preserve-on-error>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Scope cell contains escaped fixed markup.
			wp_nonce_field( 'certloom_domain_signer_assignment_' . $signer_id );
			echo '<input type="hidden" name="action" value="certloom_domain_signer_assignment"><input type="hidden" name="assignment_action" value="remove"><input type="hidden" name="signer_id" value="' . esc_attr( $signer_id ) . '"><input type="hidden" name="assignment_id" value="' . esc_attr( absint( $row['id'] ) ) . '"><button type="submit" class="button button-small">' . esc_html__( 'Remove', 'certloom' ) . '</button></form></td></tr>';
		}
		if ( empty( $rows ) ) {
			echo '<tr><td colspan="' . esc_attr( $simple ? '3' : '4' ) . '">' . esc_html__( 'This signer is not connected to a Global, Issuer, Program or Batch context yet.', 'certloom' ) . '</td></tr>';
		}
		echo '</tbody></table></div>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="certloom-domain-inline-form certloom-signer-usage-form">';
		wp_nonce_field( 'certloom_domain_signer_assignment_' . $signer_id );
		echo '<input type="hidden" name="action" value="certloom_domain_signer_assignment"><input type="hidden" name="assignment_action" value="add"><input type="hidden" name="signer_id" value="' . esc_attr( $signer_id ) . '"><label><strong>' . esc_html( $simple ? __( 'Use for', 'certloom' ) : __( 'Scope', 'certloom' ) ) . '</strong> ';
		$this->scope_select();
		echo '</label> <label><strong>' . esc_html__( 'Use as', 'certloom' ) . '</strong> <select name="role_type"><option value="signer">' . esc_html__( 'Signer', 'certloom' ) . '</option><option value="instructor">' . esc_html__( 'Instructor', 'certloom' ) . '</option></select></label> ';
		if ( $simple ) {
			echo '<details class="certloom-technical-details certloom-signer-priority-control"><summary>' . esc_html__( 'Technical options', 'certloom' ) . '</summary><label><strong>' . esc_html__( 'Priority', 'certloom' ) . '</strong> <input type="number" min="0" max="1000" name="priority" value="100" style="width:90px"><small>' . esc_html__( 'Keep 100 for normal use. Lower numbers win only when two signer rules apply at the same hierarchy level.', 'certloom' ) . '</small></label></details> ';
		} else {
			echo '<label><strong>' . esc_html__( 'Priority', 'certloom' ) . '</strong> <input type="number" min="0" max="1000" name="priority" value="100" style="width:90px"></label> ';
		}
		echo '<button type="submit" class="button">' . esc_html( $simple ? __( 'Add use', 'certloom' ) : __( 'Add hierarchy rule', 'certloom' ) ) . '</button></form></section>';
	}

	/** @param array<string,mixed>|null $existing @return array<string,mixed>|\WP_Error */
	private function batch_request( ?array $existing = null, ?array $input = null, ?array $workspace_program = null ) {
		$input = null === $input ? wp_unslash( $_POST ) : $input;
		$program_id = isset( $input['program_id'] ) ? absint( $input['program_id'] ) : 0;
		$context = null === $workspace_program ? $this->verify_program_context( $existing, $program_id ) : ( (int) $workspace_program['id'] === $program_id && ( null === $existing || (int) $existing['program_id'] === $program_id ) ? true : new \WP_Error( 'certloom_batch_program_context_mismatch', __( 'The batch must belong to this Program.', 'certloom' ) ) );
		if ( is_wp_error( $context ) ) { return $context; }
		if ( ! $program_id ) {
			return new \WP_Error( 'certloom_batch_program_required', __( 'Choose a Program for the Batch.', 'certloom' ) );
		}
		$program = null !== $workspace_program ? $workspace_program : ( new ProgramRepository() )->find( $program_id );
		if ( is_wp_error( $program ) || ! is_array( $program ) ) {
			return is_wp_error( $program ) ? $program : new \WP_Error( 'certloom_batch_program_missing', __( 'The selected Program no longer exists.', 'certloom' ) );
		}

		$signer_id = isset( $input['instructor_signer_id'] ) && '' !== trim( (string) $input['instructor_signer_id'] ) ? absint( $input['instructor_signer_id'] ) : null;
		if ( $signer_id ) {
			$signer = ( new SignerRepository() )->find( $signer_id );
			if ( is_wp_error( $signer ) || ! is_array( $signer ) ) {
				return is_wp_error( $signer ) ? $signer : new \WP_Error( 'certloom_batch_signer_missing', __( 'The selected instructor signer no longer exists.', 'certloom' ) );
			}
		}

		$data = array(
			'program_id'           => $program_id,
			'code'                 => isset( $input['code'] ) ? sanitize_text_field( $input['code'] ) : '',
			'name'                 => isset( $input['name'] ) ? sanitize_text_field( $input['name'] ) : '',
			'start_date'           => isset( $input['start_date'] ) ? sanitize_text_field( $input['start_date'] ) : '',
			'end_date'             => isset( $input['end_date'] ) ? sanitize_text_field( $input['end_date'] ) : '',
			// Free-form instructor identity is legacy-only. Preserve an existing fallback
			// until a reusable Signer is deliberately selected, then clear the duplicate.
			'instructor_name'      => $signer_id ? '' : ( null !== $existing ? (string) ( $existing['instructor_name'] ?? '' ) : '' ),
			'instructor_signer_id' => $signer_id,
			'active'               => ! empty( $input['active'] ),
		);
		$metadata = null !== $existing && isset( $existing['metadata'] ) && is_array( $existing['metadata'] ) ? $existing['metadata'] : array();
		if ( isset( $input['batch_metadata_present'] ) ) {
			$parsed = $this->parse_key_value_request( 'batch_meta_key', 'batch_meta_value', 30 );
			if ( is_wp_error( $parsed ) ) { return $parsed; }
			$metadata = $parsed;
		}
		$raw_field_rules = isset( $input['batch_field_requirement'] ) && is_array( $input['batch_field_requirement'] ) ? $input['batch_field_requirement'] : array();
		$field_rules = array();
		foreach ( $raw_field_rules as $raw_key => $raw_rule ) {
			$key = sanitize_key( (string) $raw_key ); $rule = sanitize_key( (string) $raw_rule );
			if ( '' !== $key && in_array( $rule, array( 'optional', 'required', 'exclude' ), true ) ) { $field_rules[ $key ] = $rule; }
		}
		$contract = new ScopedFieldContract();
		if ( ! array_key_exists( 'batch_field_requirement', $input ) && null !== $existing ) {
			if ( ! empty( $existing['field_contract']['_meta']['configured'] ) ) { $metadata[ ScopedFieldContract::STORAGE_KEY ] = $existing['field_contract']; }
		} else {
			$metadata = $contract->with_batch_metadata( $metadata, $field_rules, \Ecapla_CertVerify_Fields::get_field_map() );
			if ( null !== $existing && empty( $existing['field_contract']['_meta']['configured'] ) && empty( $field_rules ) ) { unset( $metadata[ ScopedFieldContract::STORAGE_KEY ] ); }
		}
		if ( is_wp_error( $metadata ) ) { return $metadata; }
		$batch_contract = $contract->from_batch_metadata( $metadata, \Ecapla_CertVerify_Fields::get_field_map() );
		if ( is_wp_error( $batch_contract ) ) { return $batch_contract; }
		$resolved = $contract->resolve( is_array( $program['field_contract'] ?? null ) ? $program['field_contract'] : ScopedFieldContract::empty_program(), $batch_contract, \Ecapla_CertVerify_Fields::get_field_map() );
		if ( is_wp_error( $resolved ) ) { return $resolved; }
		if ( null !== $existing && absint( $existing['program_id'] ?? 0 ) !== $program_id ) {
			$integration_dependency = ( new IntegrationConfigurationDependencyGuard() )->validate_batch_program_change( absint( $existing['id'] ?? 0 ), $program_id );
			if ( is_wp_error( $integration_dependency ) ) { return $integration_dependency; }
			$automation_dependency = ( new AutomationConfigurationDependencyGuard() )->validate_batch_program_change( absint( $existing['id'] ?? 0 ), $program_id );
			if ( is_wp_error( $automation_dependency ) ) { return $automation_dependency; }
		}
		$guarded = ( new ScopedFieldConfigurationGuard() )->validate_batch_change( $program, absint( $existing['id'] ?? 0 ), $batch_contract );
		if ( is_wp_error( $guarded ) ) { return $guarded; }
		$proposed_batch = null !== $existing ? $existing : array( 'id' => 0 );
		$proposed_batch['program_id'] = $program_id;
		$proposed_batch['active'] = ! empty( $data['active'] );
		$proposed_batch['field_contract'] = $batch_contract;
		$batch_template_id = isset( $input['batch_template_id'] ) ? absint( $input['batch_template_id'] ) : $this->stored_batch_template_id( absint( $existing['id'] ?? 0 ) );
		if ( is_wp_error( $batch_template_id ) ) { return $batch_template_id; }
		if ( null !== $existing ) {
			$integration_dependency = ( new IntegrationConfigurationDependencyGuard() )->validate_batch_context_change(
				$program,
				$proposed_batch,
				array( 'scope_type' => 'batch', 'scope_id' => absint( $existing['id'] ?? 0 ), 'template_id' => $batch_template_id )
			);
			if ( is_wp_error( $integration_dependency ) ) { return $integration_dependency; }
		}
		$design_dependency = ( new DesignHierarchyConfigurationGuard() )->validate_batch(
			$program,
			$proposed_batch,
			$batch_template_id
		);
		if ( is_wp_error( $design_dependency ) ) { return $design_dependency; }
		$data['metadata'] = $metadata;
		return $data;
	}

	/** Reuses the canonical batch parser and dependency guards inside a Program command. */
	public function save_workspace_batch( array $program, ?array $existing, array $patch ) {
		$input = null !== $existing ? array_intersect_key( $existing, array_flip( array( 'name', 'code', 'start_date', 'end_date', 'active', 'instructor_signer_id' ) ) ) : array( 'name' => '', 'code' => '', 'start_date' => '', 'end_date' => '', 'active' => true, 'instructor_signer_id' => '' );
		$input = array_merge( $input, $patch, array( 'program_id' => (int) $program['id'] ) );
		if ( isset( $patch['batch_template_id'] ) ) {
			$current = $this->stored_batch_template_id( $existing['id'] ?? 0 );
			if ( is_wp_error( $current ) ) { return $current; }
			if ( (int) $patch['batch_template_id'] !== $current && ! current_user_can( \Ecapla_CertVerify_Permissions::CAP_MANAGE_TEMPLATES ) ) { return new \WP_Error( 'certloom_program_design_forbidden', __( 'You do not have permission to change certificate designs.', 'certloom' ) ); }
		}
		$data = $this->batch_request( $existing, $input, $program );
		if ( is_wp_error( $data ) ) { return $data; }
		$repo = new BatchRepository();
		$result = null !== $existing ? $repo->update( $existing['id'], $data ) : $repo->create( $data );
		if ( is_wp_error( $result ) ) { return $result; }
		$id = null !== $existing ? (int) $existing['id'] : (int) $result;
		return array_key_exists( 'batch_template_id', $patch ) ? $this->save_batch_template_assignment( $id, (int) $patch['batch_template_id'] ) : $result;
	}

	/** @return int|\WP_Error */
	private function stored_batch_template_id( $batch_id ) {
		$batch_id = absint( $batch_id );
		if ( ! $batch_id ) { return 0; }
		$assignment = ( new TemplateRepository() )->get_assignment( 'batch', $batch_id );
		if ( is_wp_error( $assignment ) ) { return $assignment; }
		return is_array( $assignment ) && ! empty( $assignment['active'] ) ? absint( $assignment['template_id'] ?? 0 ) : 0;
	}

	/** @param array<string,mixed>|null $existing @return array<string,mixed>|\WP_Error */
	private function signer_request( ?array $existing = null ) {
		$email = isset( $_POST['email'] ) ? trim( (string) wp_unslash( $_POST['email'] ) ) : '';
		if ( '' !== $email && ! is_email( $email ) ) {
			return new \WP_Error( 'certloom_signer_email_invalid', __( 'Enter a valid signer email address or leave it blank.', 'certloom' ) );
		}

		$display_name = isset( $_POST['display_name'] ) ? sanitize_text_field( wp_unslash( $_POST['display_name'] ) ) : '';
		$slug = isset( $_POST['slug'] ) ? sanitize_key( wp_unslash( $_POST['slug'] ) ) : '';
		if ( AdminUiMode::is_simple() && '' === $slug ) {
			$slug = null !== $existing && ! empty( $existing['slug'] ) ? sanitize_key( (string) $existing['slug'] ) : sanitize_title( $display_name );
		}
		$data = array(
			'display_name'            => $display_name,
			'slug'                    => $slug,
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
		echo '<details class="certloom-technical-details certloom-domain-kv-details"><summary>' . esc_html__( 'Technical metadata', 'certloom' ) . '</summary><div class="certloom-domain-kv"><p class="description">' . esc_html__( 'Optional machine-readable metadata for integrations and specialist workflows. Most sites can leave this section unchanged.', 'certloom' ) . '</p>';
		if ( ! $editable ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'This record contains structured metadata that the key/value editor cannot round-trip exactly. Certloom will preserve it unchanged.', 'certloom' ) . '</p></div>';
		} else {
			echo '<input type="hidden" name="' . esc_attr( $prefix . '_metadata_present' ) . '" value="1"><div class="certloom-domain-kv-list" data-certloom-kv-list="' . esc_attr( $prefix ) . '">';
			$rows = $metadata ?: array( '' => '' );
			foreach ( $rows as $key => $value ) {
				$this->key_value_row( $prefix . '_meta_key[]', $prefix . '_meta_value[]', $key, $value );
			}
			echo '</div><button type="button" class="button certloom-domain-add-kv" data-certloom-add-kv="' . esc_attr( $prefix ) . '">' . esc_html__( 'Add metadata field', 'certloom' ) . '</button>';
		}
		echo '</div></details>';
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
		$items = ( new ScopedContextChoices() )->programs( $selected );
		$nonce = wp_create_nonce( ScopedFieldAdminAjax::NONCE_ACTION );
		echo '<div class="certloom-domain-field"><label class="certloom-field-label" for="certloom-batch-program-id"><strong>' . esc_html__( 'Program', 'certloom' ) . '</strong><span class="certloom-field-required">' . esc_html__( 'Required', 'certloom' ) . '</span></label><select id="certloom-batch-program-id" name="program_id" required aria-required="true" data-certloom-context-lookup="program" data-certloom-context-nonce="' . esc_attr( $nonce ) . '" data-certloom-required-example="' . esc_attr__( 'Choose the Program this Batch belongs to.', 'certloom' ) . '" data-certloom-context-search-placeholder="' . esc_attr__( 'Search active Programs…', 'certloom' ) . '"><option value="">' . esc_html__( 'Choose Program…', 'certloom' ) . '</option>';
		if ( ! is_wp_error( $items ) ) {
			foreach ( $items as $item ) {
				echo '<option value="' . esc_attr( absint( $item['id'] ?? 0 ) ) . '" ' . selected( absint( $selected ), absint( $item['id'] ?? 0 ), false ) . '>' . esc_html( ScopedContextChoices::program_label( $item ) ) . '</option>';
			}
		}
		echo '</select><div class="certloom-context-inline-actions"><button type="button" class="button-link" data-certloom-dialog-open="certloom-batch-quick-program-dialog">' . esc_html__( 'Add new Program', 'certloom' ) . '</button></div><p class="description">' . esc_html__( 'Choose the Program this cohort belongs to.', 'certloom' ) . '</p></div>';
	}

	/** @return void */
	private function signer_select( $name, $label, $selected ) {
		$items = ( new ScopedContextChoices() )->signers( $selected );
		$id = 'certloom-domain-' . sanitize_html_class( $name );
		$nonce = wp_create_nonce( ScopedFieldAdminAjax::NONCE_ACTION );
		echo '<div class="certloom-domain-field"><label for="' . esc_attr( $id ) . '"><strong>' . esc_html( $label ) . '</strong></label><select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" data-certloom-context-lookup="signer" data-certloom-context-nonce="' . esc_attr( $nonce ) . '" data-certloom-context-search-placeholder="' . esc_attr__( 'Search active Signers…', 'certloom' ) . '"><option value="">' . esc_html__( 'None', 'certloom' ) . '</option>';
		if ( ! is_wp_error( $items ) ) {
			foreach ( $items as $item ) { echo '<option value="' . esc_attr( absint( $item['id'] ?? 0 ) ) . '" ' . selected( absint( $selected ), absint( $item['id'] ?? 0 ), false ) . '>' . esc_html( (string) ( $item['display_name'] ?? '' ) ) . '</option>'; }
		}
		echo '</select><p class="description">' . esc_html__( 'Search the complete active Signer list. Your current selection remains available even when it is not in the first results shown.', 'certloom' ) . '</p></div>';
	}

	/** @return void */
	private function scope_select() {
		$nonce = wp_create_nonce( ScopedFieldAdminAjax::NONCE_ACTION );
		echo '<select name="scope" required data-certloom-context-lookup="signer_scope" data-certloom-context-nonce="' . esc_attr( $nonce ) . '" data-certloom-context-empty-value="global:0" data-certloom-context-search-placeholder="' . esc_attr__( 'Search issuer, Program, or Batch…', 'certloom' ) . '"><option value="global:0">' . esc_html__( 'Global', 'certloom' ) . '</option>';
		$issuers = ( new IssuerRepository() )->list_page( '', 1, 7 );
		if ( ! is_wp_error( $issuers ) ) { foreach ( (array) ( $issuers['items'] ?? array() ) as $item ) { echo '<option value="issuer:' . esc_attr( absint( $item['id'] ?? 0 ) ) . '">' . esc_html__( 'Issuer', 'certloom' ) . ' · ' . esc_html( (string) ( $item['name'] ?? '' ) ) . '</option>'; } }
		$programs = ( new ProgramRepository() )->list_page( '', 1, 7, 'active' );
		if ( ! is_wp_error( $programs ) ) { foreach ( (array) ( $programs['items'] ?? array() ) as $item ) { echo '<option value="program:' . esc_attr( absint( $item['id'] ?? 0 ) ) . '">' . esc_html__( 'Program', 'certloom' ) . ' · ' . esc_html( ScopedContextChoices::program_label( $item ) ) . '</option>'; } }
		$batches = ( new BatchRepository() )->list_page( '', 1, 7, 'active' );
		if ( ! is_wp_error( $batches ) ) { foreach ( (array) ( $batches['items'] ?? array() ) as $item ) { echo '<option value="batch:' . esc_attr( absint( $item['id'] ?? 0 ) ) . '">' . esc_html__( 'Batch', 'certloom' ) . ' · ' . esc_html( ScopedContextChoices::batch_label( $item ) ) . '</option>'; } }
		echo '</select>';
	}

	/** @return void */
	private function text_field( $name, $label, $value, $required = false, $type = 'text', $description = '', $attributes = '', $example = '' ) {
		$id = 'certloom-domain-' . sanitize_html_class( $name );
		$example_attr = $required && '' !== trim( (string) $example ) ? ' data-certloom-required-example="' . esc_attr( $example ) . '"' : '';
		echo '<div class="certloom-domain-field"><label class="certloom-field-label" for="' . esc_attr( $id ) . '"><strong>' . esc_html( $label ) . '</strong>' . ( $required ? '<span class="certloom-field-required">' . esc_html__( 'Required', 'certloom' ) . '</span>' : '' ) . $this->help_tip( $description ) . '</label><input id="' . esc_attr( $id ) . '" class="regular-text" type="' . esc_attr( $type ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" ' . ( $required ? 'required aria-required="true"' . $example_attr . ' ' : '' ) . wp_kses_post( $attributes ) . '></div>';
	}

	private function help_tip( $description ) { $description = trim( (string) $description ); return '' === $description ? '' : '<span class="certloom-help-tip" tabindex="0" aria-label="' . esc_attr( $description ) . '">?<span role="tooltip">' . esc_html( $description ) . '</span></span>'; }

	private function media_field( $name, $label, $attachment_id, $description = '' ) {
		$id = 'certloom-domain-' . sanitize_html_class( $name ); $attachment_id = absint( $attachment_id ); $url = $attachment_id ? wp_get_attachment_image_url( $attachment_id, 'thumbnail' ) : ''; $filename = $attachment_id ? basename( (string) get_attached_file( $attachment_id ) ) : '';
		echo '<div class="certloom-domain-field"><label class="certloom-field-label"><strong>' . esc_html( $label ) . '</strong>' . $this->help_tip( $description ) . '</label><div class="certloom-media-control" data-certloom-media-control><input type="hidden" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $attachment_id ) . '" data-certloom-media-id><div class="certloom-media-preview" data-certloom-media-preview>';
		if ( $url ) { echo '<img src="' . esc_url( $url ) . '" alt=""><span>' . esc_html( $filename ) . '</span>'; } else { echo '<span>' . esc_html__( 'No file selected', 'certloom' ) . '</span>'; }
		echo '</div><div class="certloom-media-actions"><button type="button" class="button" data-certloom-media-select>' . esc_html__( 'Choose or upload', 'certloom' ) . '</button><button type="button" class="button-link-delete" data-certloom-media-clear ' . ( $attachment_id ? '' : 'hidden' ) . '>' . esc_html__( 'Remove', 'certloom' ) . '</button></div></div></div>';
	}

	private function batch_field_editor( array $fields, array $parent_rules, array $rules ) {
		$manage_url = add_query_arg( 'page', 'certloom-fields', admin_url( 'admin.php' ) );
		echo '<div class="certloom-scoped-field-editor" data-certloom-scope-field-editor="batch" data-certloom-field-nonce="' . esc_attr( wp_create_nonce( ScopedFieldAdminAjax::NONCE_ACTION ) ) . '"><div class="certloom-scoped-field-toolbar"><label><span>' . esc_html__( 'Add a field change', 'certloom' ) . '</span><select data-certloom-scope-field-picker><option value="">' . esc_html__( 'Choose field…', 'certloom' ) . '</option>';
		foreach ( $fields as $field ) { $key = sanitize_key( (string) ( $field['key'] ?? '' ) ); if ( '' === $key || ! empty( $rules[ $key ] ) ) { continue; } echo '<option value="' . esc_attr( $key ) . '">' . esc_html( (string) ( $field['label'] ?? $key ) ) . '</option>'; }
		echo '</select></label><button type="button" class="button" data-certloom-scope-field-add>' . esc_html__( 'Add field', 'certloom' ) . '</button><button type="button" class="button" data-certloom-quick-field-open>' . esc_html__( 'Create new field', 'certloom' ) . '</button><a href="' . esc_url( $manage_url ) . '">' . esc_html__( 'Manage all fields', 'certloom' ) . '</a></div><div class="certloom-scoped-field-list" data-certloom-scope-field-list>';
		foreach ( $fields as $field ) { $key = sanitize_key( (string) ( $field['key'] ?? '' ) ); if ( '' === $key ) { continue; } $rule = (string) ( $rules[ $key ] ?? '' ); $parent = (string) ( $parent_rules[ $key ] ?? '' ); $parent_label = '' === $parent ? __( 'Not used by Program', 'certloom' ) : sprintf( /* translators: %s: parent. */ __( 'Program: %s', 'certloom' ), ucfirst( $parent ) ); echo '<div class="certloom-scoped-field-row" data-certloom-scope-field-row="' . esc_attr( $key ) . '"' . ( '' === $rule ? ' hidden' : '' ) . '><div><strong data-certloom-scope-field-label>' . esc_html( (string) ( $field['label'] ?? $key ) ) . '</strong><span class="certloom-scoped-field-meta">' . esc_html( (string) ( $field['type'] ?? 'text' ) ) . ' · ' . esc_html( $parent_label ) . '</span></div><label><span class="screen-reader-text">' . esc_html( sprintf( /* translators: %s: label. */ __( 'Batch rule for %s', 'certloom' ), (string) ( $field['label'] ?? $key ) ) ) . '</span><select name="batch_field_requirement[' . esc_attr( $key ) . ']" data-certloom-scope-field-rule><option value="" ' . selected( $rule, '', false ) . '>' . esc_html__( 'Inherit Program', 'certloom' ) . '</option><option value="optional" ' . selected( $rule, 'optional', false ) . '>' . esc_html__( 'Optional', 'certloom' ) . '</option><option value="required" ' . selected( $rule, 'required', false ) . '>' . esc_html__( 'Required', 'certloom' ) . '</option><option value="exclude" ' . selected( $rule, 'exclude', false ) . '>' . esc_html__( 'Exclude', 'certloom' ) . '</option></select></label><button type="button" class="button-link-delete" data-certloom-scope-field-remove>' . esc_html__( 'Remove change', 'certloom' ) . '</button></div>'; }
		echo '</div><p class="certloom-scoped-field-empty" data-certloom-scope-field-empty' . ( array_filter( $rules ) ? ' hidden' : '' ) . '>' . esc_html__( 'This Batch currently inherits its Program field contract without changes.', 'certloom' ) . '</p>' . $this->quick_field_dialog() . '</div>';
	}

	private function quick_field_dialog() {
		$sensitivity_options = \Ecapla_CertVerify_Fields::get_sensitivity_options();
		$exposure_options = \Ecapla_CertVerify_Fields::get_public_exposure_options();
		$html = '<dialog class="certloom-quick-field-dialog" data-certloom-quick-field-dialog><div class="certloom-quick-field-dialog-shell" role="document"><div class="certloom-dialog-head"><div><span class="certloom-eyebrow">' . esc_html__( 'Credential fields', 'certloom' ) . '</span><h3>' . esc_html__( 'Create a field', 'certloom' ) . '</h3></div><button type="button" class="button-link certloom-dialog-close" data-certloom-quick-field-close aria-label="' . esc_attr__( 'Close', 'certloom' ) . '">×</button></div>';
		$html .= '<p class="certloom-quick-field-intro">' . esc_html__( 'Create a reusable credential field and add it as a change for this Batch now. You can still change ordering or retire fields later in Settings → Fields.', 'certloom' ) . '</p>';
		$html .= '<div class="certloom-quick-field-grid"><div class="certloom-quick-field-field certloom-quick-field-wide"><label for="certloom-quick-field-label">' . esc_html__( 'Field label', 'certloom' ) . '</label><input id="certloom-quick-field-label" type="text" maxlength="100" data-certloom-quick-field-label placeholder="' . esc_attr__( 'e.g. Student ID', 'certloom' ) . '"></div><div class="certloom-quick-field-field"><label for="certloom-quick-field-type">' . esc_html__( 'Field type', 'certloom' ) . '</label><select id="certloom-quick-field-type" data-certloom-quick-field-type><option value="text">' . esc_html__( 'Short text', 'certloom' ) . '</option><option value="textarea">' . esc_html__( 'Long text', 'certloom' ) . '</option><option value="number">' . esc_html__( 'Number', 'certloom' ) . '</option><option value="date">' . esc_html__( 'Date', 'certloom' ) . '</option></select></div>';
		$html .= '<div class="certloom-quick-field-field"><label for="certloom-quick-field-sensitivity">' . esc_html__( 'Sensitivity', 'certloom' ) . '</label><select id="certloom-quick-field-sensitivity" data-certloom-quick-field-sensitivity>'; foreach ( $sensitivity_options as $value => $label ) { $html .= '<option value="' . esc_attr( $value ) . '" ' . selected( 'personal', $value, false ) . '>' . esc_html( $label ) . '</option>'; } $html .= '</select></div>';
		$html .= '<div class="certloom-quick-field-field certloom-quick-field-wide"><label for="certloom-quick-field-exposure">' . esc_html__( 'Public visibility', 'certloom' ) . '</label><select id="certloom-quick-field-exposure" data-certloom-quick-field-exposure>'; foreach ( $exposure_options as $value => $label ) { $html .= '<option value="' . esc_attr( $value ) . '" ' . selected( 'private', $value, false ) . '>' . esc_html( $label ) . '</option>'; } $html .= '</select><small>' . esc_html__( 'Internal fields are always private. Choose public visibility only when the data is appropriate to show on verification or profile pages.', 'certloom' ) . '</small></div></div>';
		$html .= '<div class="certloom-quick-field-context"><strong>' . esc_html__( 'Where is this value stored?', 'certloom' ) . '</strong><span>' . esc_html__( 'Quick-created custom fields belong to the credential record, so the value can differ for each credential. Person-level details such as name, email and external ID belong to the Recipient profile.', 'certloom' ) . '</span></div>';
		$html .= '<div class="certloom-quick-field-feedback" data-certloom-quick-field-feedback aria-live="polite"></div><div class="certloom-dialog-actions"><button type="button" class="button" data-certloom-quick-field-close>' . esc_html__( 'Cancel', 'certloom' ) . '</button><button type="button" class="button button-primary" data-certloom-quick-field-create>' . esc_html__( 'Create & add', 'certloom' ) . '</button></div></div></dialog>';
		return $html;
	}

	/** @param array{label:string,url:string,secondary?:bool}|null $action @return void */
	private function header( $title, $subtitle, ?array $action = null ) {
		echo '<div class="wrap ecapla-certverify-admin-wrap certloom-admin-shell certloom-domain-screen"><section class="certloom-page-hero certloom-domain-title-row"><div><span class="certloom-eyebrow">' . esc_html__( 'Credential structure', 'certloom' ) . '</span><h1>' . esc_html( $title ) . '</h1><p class="certloom-domain-lead">' . esc_html( $subtitle ) . '</p></div><div class="certloom-domain-title-actions">';
		if ( is_array( $action ) && ! empty( $action['label'] ) && ! empty( $action['url'] ) ) {
			echo '<a class="button ' . ( empty( $action['secondary'] ) ? 'button-primary' : '' ) . '" href="' . esc_url( (string) $action['url'] ) . '">' . esc_html( (string) $action['label'] ) . '</a>';
		}
		echo '</div></section>';
		( new AdminProductNavigation() )->render_current_subnav();
	}

	/** @return void */
	private function notice() {
		if ( isset( $_GET['certloom_error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Redirected admin notice only.
			$this->error( sanitize_text_field( wp_unslash( $_GET['certloom_error'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		$messages = array(
			'batch_added'               => __( 'Batch added.', 'certloom' ),
			'batch_created'             => __( 'Batch created.', 'certloom' ),
			'batch_updated'             => __( 'Batch updated.', 'certloom' ),
			'batch_membership_changed'  => __( 'Batch membership updated.', 'certloom' ),
			'signer_created'            => __( 'Signer created.', 'certloom' ),
			'signer_updated'            => __( 'Signer updated.', 'certloom' ),
			'signer_assignment_changed' => __( 'Signer usage updated.', 'certloom' ),
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
		echo '<form method="get" class="certloom-domain-search">' . ( self::BATCHES_PAGE === $page_slug ? $this->batch_route_inputs() : '<input type="hidden" name="page" value="' . esc_attr( $page_slug ) . '">' ) . '<label class="screen-reader-text" for="' . esc_attr( $id ) . '">' . esc_html_x( 'Search', 'search field label', 'certloom' ) . '</label><input id="' . esc_attr( $id ) . '" type="search" name="s" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr( $placeholder ) . '"><label class="screen-reader-text" for="' . esc_attr( $id . '-status' ) . '">' . esc_html__( 'Status', 'certloom' ) . '</label><select id="' . esc_attr( $id . '-status' ) . '" name="status"><option value="all" ' . selected( $status, 'all', false ) . '>' . esc_html__( 'All statuses', 'certloom' ) . '</option><option value="active" ' . selected( $status, 'active', false ) . '>' . esc_html_x( 'Active', 'entity status', 'certloom' ) . '</option><option value="inactive" ' . selected( $status, 'inactive', false ) . '>' . esc_html_x( 'Inactive', 'entity status', 'certloom' ) . '</option></select><button type="submit" class="button">' . esc_html__( 'Filter', 'certloom' ) . '</button></form>';
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
		$args = array( 's' => $search, 'status' => $status, 'paged' => '%#%' );
		$base = add_query_arg( self::BATCHES_PAGE === $slug ? $this->batch_route_args( $args ) : array_merge( array( 'page' => $slug ), $args ), admin_url( 'admin.php' ) );
		echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( paginate_links( array( 'base' => $base, 'format' => '', 'current' => $page, 'total' => $total_pages ) ) ) . '</div></div>';
	}

	/** @return void */
	private function member_pagination( $batch_id, $page, $total, $per_page, $search, $recipient_search ) {
		$total_pages = max( 1, (int) ceil( $total / $per_page ) );
		if ( $total_pages <= 1 ) {
			return;
		}
		$base = add_query_arg(
			$this->batch_route_args( array(
				'batch_id'     => absint( $batch_id ),
				'member_s'     => $search,
				'recipient_s'  => $recipient_search,
				'member_paged' => '%#%',
			) ),
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
	/** Save or clear the Batch-specific design override. Zero means inherit Program. @return true|\WP_Error */
	private function save_batch_template_assignment( $batch_id, $template_id ) {
		$repo = new TemplateRepository();
		$existing = $repo->get_assignment( 'batch', absint( $batch_id ) );
		if ( is_wp_error( $existing ) ) { return $existing; }
		$expected_id = is_array( $existing ) ? absint( $existing['id'] ?? 0 ) : 0;
		$expected_updated_at = is_array( $existing ) ? (string) ( $existing['updated_at'] ?? '' ) : '';
		if ( ! absint( $template_id ) ) {
			if ( ! $expected_id ) { return true; }
			return $repo->clear_assignment( 'batch', absint( $batch_id ), $expected_id, $expected_updated_at );
		}
		return $repo->assign( absint( $template_id ), 'batch', absint( $batch_id ), 100, $expected_id, $expected_updated_at );
	}

	private function redirect_result( $page_slug, $result, $success_key, array $extra = array() ) {
		if ( self::BATCHES_PAGE === $page_slug && ! is_array( $this->program_context ) && ! empty( $extra['batch_id'] ) && current_user_can( \Ecapla_CertVerify_Permissions::CAP_MANAGE_PROGRAMS ) ) {
			$batch = ( new BatchRepository() )->find( absint( $extra['batch_id'] ) );
			if ( is_array( $batch ) ) {
				$program = ( new ProgramRepository() )->find( absint( $batch['program_id'] ) );
				if ( is_array( $program ) ) { $this->program_context = $program; }
			}
		}
		$args = self::BATCHES_PAGE === $page_slug ? $this->batch_route_args( $extra ) : array_merge( array( 'page' => $page_slug ), $extra );
		if ( is_wp_error( $result ) ) {
			$args['certloom_error'] = $result->get_error_message();
		} else {
			$args[ $success_key ] = 1;
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}
}
