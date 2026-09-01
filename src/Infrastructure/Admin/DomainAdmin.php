<?php
namespace Certloom\Infrastructure\Admin;

use Certloom\Application\CredentialLifecycleService;
use Certloom\Application\CredentialLifecycleReviewService;
use Certloom\Application\CredentialRelationshipBulkService;
use Certloom\Application\Program\ProgramProfile;
use Certloom\Application\I18n\CredentialLocale;
use Certloom\Application\Fields\ScopedFieldContract;
use Certloom\Application\Templates\TemplateAssignmentPolicy;
use Certloom\Domain\Credential\States;
use Certloom\Domain\Credential\StatusService;
use Certloom\Infrastructure\Repository\CredentialRepository;
use Certloom\Infrastructure\Repository\BatchRepository;
use Certloom\Infrastructure\Repository\EventRepository;
use Certloom\Infrastructure\Repository\IssuerRepository;
use Certloom\Infrastructure\Repository\ProgramRepository;
use Certloom\Infrastructure\Repository\RecipientRepository;
use Certloom\Infrastructure\Repository\SignerRepository;
use Certloom\Infrastructure\Repository\TemplateRepository;
use Certloom\Infrastructure\Repository\UnifiedCredentialRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 1.5.3 preview admin for the additive Recipient / Program / Credential domain.
 *
 * This entire admin is hidden behind manifest-backed normalized-domain admin authority until the
 * real WordPress/MySQL gate passes. The proven 1.5 registry remains the source
 * of truth, so developing these screens cannot strand or rewrite live data.
 */
final class DomainAdmin {
	const RECIPIENTS_PAGE  = 'certloom-domain-recipients';
	const PROGRAMS_PAGE    = 'certloom-domain-programs';
	const ISSUERS_PAGE     = 'certloom-domain-issuers';
	const CREDENTIALS_PAGE = 'certloom-domain-credentials';

	/** @return void */
	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ), 50 );
		add_action( 'admin_post_certloom_domain_create_recipient', array( $this, 'create_recipient' ) );
		add_action( 'admin_post_certloom_domain_update_recipient', array( $this, 'update_recipient' ) );
		add_action( 'admin_post_certloom_domain_create_program', array( $this, 'create_program' ) );
		add_action( 'admin_post_certloom_domain_update_program', array( $this, 'update_program' ) );
		add_action( 'admin_post_certloom_program_quick_issuer', array( $this, 'program_quick_issuer' ) );
		add_action( 'admin_post_certloom_program_quick_batch', array( $this, 'program_quick_batch' ) );
		add_action( 'admin_post_certloom_program_quick_signer', array( $this, 'program_quick_signer' ) );
		add_action( 'admin_post_certloom_domain_create_issuer', array( $this, 'create_issuer' ) );
		add_action( 'admin_post_certloom_domain_update_issuer', array( $this, 'update_issuer' ) );
		add_action( 'admin_post_certloom_domain_lifecycle', array( $this, 'lifecycle' ) );
		add_action( 'admin_post_certloom_domain_relationship_correct', array( $this, 'relationship_correct' ) );
	}

	/** @return void */
	public function menu() {
		add_submenu_page( null, __( 'Recipients', 'certloom' ), __( 'Recipients', 'certloom' ), \Ecapla_CertVerify_Permissions::CAP_MANAGE_RECIPIENTS, self::RECIPIENTS_PAGE, array( $this, 'render_recipients' ) );
		add_submenu_page( 'certloom', __( 'Programs', 'certloom' ), __( 'Programs', 'certloom' ), \Ecapla_CertVerify_Permissions::CAP_MANAGE_PROGRAMS, self::PROGRAMS_PAGE, array( $this, 'render_programs' ) );
		add_submenu_page( null, __( 'Issuers (New Domain)', 'certloom' ), __( 'Issuers (New)', 'certloom' ), \Ecapla_CertVerify_Permissions::CAP_MANAGE_ISSUERS, self::ISSUERS_PAGE, array( $this, 'render_issuers' ) );
		add_submenu_page( AdminUiMode::is_simple() ? 'certloom' : null, __( 'Credential Management', 'certloom' ), __( 'Credentials', 'certloom' ), \Ecapla_CertVerify_Permissions::CAP_VIEW_CREDENTIALS, self::CREDENTIALS_PAGE, array( $this, 'render_credentials' ) );
	}

	/** @return void */
	public function create_recipient() {
		$this->require_capability( \Ecapla_CertVerify_Permissions::CAP_MANAGE_RECIPIENTS );
		check_admin_referer( 'certloom_domain_create_recipient' );
		$data = $this->recipient_request();
		if ( is_wp_error( $data ) ) {
			$this->redirect_result( self::RECIPIENTS_PAGE, $data, 'recipient_created', array( 'certloom_new' => 1, 'certloom_retry' => 1 ) );
		}
		$result = ( new RecipientRepository() )->create( $data );
		if ( is_wp_error( $result ) ) { $this->redirect_result( self::RECIPIENTS_PAGE, $result, 'recipient_created', array( 'certloom_new' => 1, 'certloom_retry' => 1 ) ); }
		$this->redirect_result( self::RECIPIENTS_PAGE, $result, 'recipient_created' );
	}

	/** @return void */
	public function update_recipient() {
		$this->require_capability( \Ecapla_CertVerify_Permissions::CAP_MANAGE_RECIPIENTS );
		$id = isset( $_POST['recipient_id'] ) ? absint( $_POST['recipient_id'] ) : 0;
		check_admin_referer( 'certloom_domain_update_recipient_' . $id );
		$repo = new RecipientRepository();
		$existing = $repo->find( $id );
		if ( is_wp_error( $existing ) || ! is_array( $existing ) ) {
			$result = is_wp_error( $existing ) ? $existing : new \WP_Error( 'certloom_recipient_missing', __( 'The recipient no longer exists.', 'certloom' ) );
			$this->redirect_result( self::RECIPIENTS_PAGE, $result, 'recipient_updated' );
		}
		$data = $this->recipient_request( $existing );
		if ( is_wp_error( $data ) ) {
			$this->redirect_result( self::RECIPIENTS_PAGE, $data, 'recipient_updated', array( 'recipient_id' => $id, 'certloom_retry' => 1 ) );
		}
		$result = $repo->update( $id, $data );
		if ( is_wp_error( $result ) ) { $this->redirect_result( self::RECIPIENTS_PAGE, $result, 'recipient_updated', array( 'recipient_id' => $id, 'certloom_retry' => 1 ) ); }
		$this->redirect_result( self::RECIPIENTS_PAGE, $result, 'recipient_updated', array( 'recipient_id' => $id ) );
	}

	/** @return void */
	public function create_program() {
		$this->require_capability( \Ecapla_CertVerify_Permissions::CAP_MANAGE_PROGRAMS );
		check_admin_referer( 'certloom_domain_create_program' );
		$data = $this->program_request();
		if ( is_wp_error( $data ) ) {
			$this->redirect_result( self::PROGRAMS_PAGE, $data, 'program_created', array( 'certloom_new' => 1, 'certloom_retry' => 1 ) );
		}
		$result = ( new ProgramRepository() )->create( $data );
		if ( is_wp_error( $result ) ) { $this->redirect_result( self::PROGRAMS_PAGE, $result, 'program_created', array( 'certloom_new' => 1, 'certloom_retry' => 1 ) ); }
		$template_result = $this->save_scope_template_assignment( 'program', absint( $result ), isset( $_POST['program_template_id'] ) ? absint( $_POST['program_template_id'] ) : 0 );
		if ( is_wp_error( $template_result ) ) { $this->redirect_result( self::PROGRAMS_PAGE, $template_result, 'program_created', array( 'program_id' => absint( $result ) ) ); }
		$this->redirect_result( self::PROGRAMS_PAGE, $result, 'program_created' );
	}

	/** @return void */
	public function update_program() {
		$this->require_capability( \Ecapla_CertVerify_Permissions::CAP_MANAGE_PROGRAMS );
		$id = isset( $_POST['program_id'] ) ? absint( $_POST['program_id'] ) : 0;
		check_admin_referer( 'certloom_domain_update_program_' . $id );
		$repo = new ProgramRepository();
		$existing = $repo->find( $id );
		if ( is_wp_error( $existing ) || ! is_array( $existing ) ) {
			$result = is_wp_error( $existing ) ? $existing : new \WP_Error( 'certloom_program_missing', __( 'The program no longer exists.', 'certloom' ) );
			$this->redirect_result( self::PROGRAMS_PAGE, $result, 'program_updated' );
		}
		$data = $this->program_request( $existing );
		if ( is_wp_error( $data ) ) {
			$this->redirect_result( self::PROGRAMS_PAGE, $data, 'program_updated', array( 'program_id' => $id, 'certloom_retry' => 1 ) );
		}
		$result = $repo->update( $id, $data );
		if ( is_wp_error( $result ) ) { $this->redirect_result( self::PROGRAMS_PAGE, $result, 'program_updated', array( 'program_id' => $id, 'certloom_retry' => 1 ) ); }
		$template_result = $this->save_scope_template_assignment( 'program', $id, isset( $_POST['program_template_id'] ) ? absint( $_POST['program_template_id'] ) : 0 );
		if ( is_wp_error( $template_result ) ) { $this->redirect_result( self::PROGRAMS_PAGE, $template_result, 'program_updated', array( 'program_id' => $id ) ); }
		$this->redirect_result( self::PROGRAMS_PAGE, $result, 'program_updated', array( 'program_id' => $id ) );
	}



	/** Creates an Issuer in context and immediately connects it to one Program. */
	public function program_quick_issuer() {
		$this->require_capability( \Ecapla_CertVerify_Permissions::CAP_MANAGE_PROGRAMS );
		$this->require_capability( \Ecapla_CertVerify_Permissions::CAP_MANAGE_ISSUERS );
		$program_id = isset( $_POST['program_id'] ) ? absint( $_POST['program_id'] ) : 0;
		check_admin_referer( 'certloom_program_quick_issuer_' . $program_id );
		$program = ( new ProgramRepository() )->find( $program_id );
		if ( ! is_array( $program ) ) {
			$this->redirect_program_workspace( $program_id, is_wp_error( $program ) ? $program : new \WP_Error( 'certloom_program_missing', __( 'The Program no longer exists.', 'certloom' ) ) );
		}
		$name = isset( $_POST['issuer_name'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['issuer_name'] ) ) ) : '';
		$result = ( new IssuerRepository() )->create( array( 'name' => $name, 'slug' => sanitize_title( $name ) ) );
		if ( is_wp_error( $result ) ) { $this->redirect_program_workspace( $program_id, $result ); }
		$connected = ( new ProgramRepository() )->update( $program_id, array( 'issuer_id' => absint( $result ) ) );
		if ( is_wp_error( $connected ) ) { $this->redirect_program_workspace( $program_id, $connected ); }
		$this->redirect_program_workspace( $program_id, true, 'issuer_added' );
	}

	/** Creates a Batch without forcing the administrator out of the Program workspace. */
	public function program_quick_batch() {
		$this->require_capability( \Ecapla_CertVerify_Permissions::CAP_MANAGE_PROGRAMS );
		$this->require_capability( \Ecapla_CertVerify_Permissions::CAP_MANAGE_BATCHES );
		$program_id = isset( $_POST['program_id'] ) ? absint( $_POST['program_id'] ) : 0;
		check_admin_referer( 'certloom_program_quick_batch_' . $program_id );
		$name = isset( $_POST['batch_name'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['batch_name'] ) ) ) : '';
		$code = isset( $_POST['batch_code'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['batch_code'] ) ) ) : '';
		if ( '' === $code && '' !== $name ) {
			$code = strtoupper( substr( preg_replace( '/[^A-Za-z0-9]+/', '-', $name ), 0, 80 ) );
			$code = trim( $code, '-' );
		}
		$result = ( new BatchRepository() )->create(
			array(
				'program_id' => $program_id,
				'name'       => $name,
				'code'       => $code,
				'active'     => 1,
			)
		);
		if ( is_wp_error( $result ) ) { $this->redirect_program_workspace( $program_id, $result ); }
		$this->redirect_program_workspace( $program_id, true, 'batch_added' );
	}

	/** Creates a Signer and immediately assigns that signer to the current Program. */
	public function program_quick_signer() {
		$this->require_capability( \Ecapla_CertVerify_Permissions::CAP_MANAGE_PROGRAMS );
		$this->require_capability( \Ecapla_CertVerify_Permissions::CAP_MANAGE_SIGNERS );
		$program_id = isset( $_POST['program_id'] ) ? absint( $_POST['program_id'] ) : 0;
		check_admin_referer( 'certloom_program_quick_signer_' . $program_id );
		$name  = isset( $_POST['signer_name'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['signer_name'] ) ) ) : '';
		$title = isset( $_POST['signer_title'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['signer_title'] ) ) ) : '';
		$email = isset( $_POST['signer_email'] ) ? sanitize_email( wp_unslash( $_POST['signer_email'] ) ) : '';
		$slug  = sanitize_key( sanitize_title( $name ) );
		if ( '' === $slug ) { $slug = 'signer-' . gmdate( 'Ymd-His' ); }
		$repo = new SignerRepository();
		$result = $repo->create( array( 'display_name' => $name, 'slug' => $slug, 'title' => $title, 'email' => $email, 'active' => 1 ) );
		if ( is_wp_error( $result ) ) { $this->redirect_program_workspace( $program_id, $result ); }
		$assigned = $repo->add_assignment( absint( $result ), 'program', $program_id, 'signer', 100 );
		if ( is_wp_error( $assigned ) ) { $this->redirect_program_workspace( $program_id, $assigned ); }
		$this->redirect_program_workspace( $program_id, true, 'signer_added' );
	}

	/** @return void */
	public function create_issuer() {
		$this->require_capability( \Ecapla_CertVerify_Permissions::CAP_MANAGE_ISSUERS );
		check_admin_referer( 'certloom_domain_create_issuer' );
		$data = $this->issuer_request();
		if ( is_wp_error( $data ) ) { $this->redirect_result( self::ISSUERS_PAGE, $data, 'issuer_created', array( 'certloom_new' => 1, 'certloom_retry' => 1 ) ); }
		$result = ( new IssuerRepository() )->create( $data );
		if ( is_wp_error( $result ) ) { $this->redirect_result( self::ISSUERS_PAGE, $result, 'issuer_created', array( 'certloom_new' => 1, 'certloom_retry' => 1 ) ); }
		$this->redirect_result( self::ISSUERS_PAGE, $result, 'issuer_created' );
	}

	/** @return void */
	public function update_issuer() {
		$this->require_capability( \Ecapla_CertVerify_Permissions::CAP_MANAGE_ISSUERS );
		$id = isset( $_POST['issuer_id'] ) ? absint( $_POST['issuer_id'] ) : 0;
		check_admin_referer( 'certloom_domain_update_issuer_' . $id );
		$repo = new IssuerRepository();
		$existing = $repo->find( $id );
		if ( is_wp_error( $existing ) || ! is_array( $existing ) ) { $this->redirect_result( self::ISSUERS_PAGE, is_wp_error( $existing ) ? $existing : new \WP_Error( 'certloom_issuer_missing', __( 'The issuer no longer exists.', 'certloom' ) ), 'issuer_updated' ); }
		$data = $this->issuer_request( $existing );
		if ( is_wp_error( $data ) ) { $this->redirect_result( self::ISSUERS_PAGE, $data, 'issuer_updated', array( 'issuer_id' => $id, 'certloom_retry' => 1 ) ); }
		$result = $repo->update( $id, $data );
		if ( is_wp_error( $result ) ) { $this->redirect_result( self::ISSUERS_PAGE, $result, 'issuer_updated', array( 'issuer_id' => $id, 'certloom_retry' => 1 ) ); }
		$this->redirect_result( self::ISSUERS_PAGE, $result, 'issuer_updated', array( 'issuer_id' => $id ) );
	}

	/** @return void */
	public function lifecycle() {
		$this->require_capability( \Ecapla_CertVerify_Permissions::CAP_MANAGE_CREDENTIALS );
		$series_id = isset( $_POST['series_id'] ) ? absint( $_POST['series_id'] ) : 0;
		check_admin_referer( 'certloom_domain_lifecycle_' . $series_id );
		$review = $this->decode_review_request( 'lifecycle_review' );
		if ( is_wp_error( $review ) ) {
			$this->redirect_result( self::CREDENTIALS_PAGE, $review, 'credential_changed', array( 'series_id' => $series_id, 'credential_tab' => 'actions' ) );
		}
		$verified = ( new CredentialLifecycleReviewService() )->verify( $review, get_current_user_id() );
		if ( is_wp_error( $verified ) ) {
			$this->redirect_result( self::CREDENTIALS_PAGE, $verified, 'credential_changed', array( 'series_id' => $series_id, 'credential_tab' => 'actions' ) );
		}
		if ( absint( $verified['series_id'] ?? 0 ) !== $series_id ) {
			$this->redirect_result( self::CREDENTIALS_PAGE, new \WP_Error( 'certloom_lifecycle_review_series_mismatch', __( 'The reviewed lifecycle action belongs to another credential.', 'certloom' ) ), 'credential_changed', array( 'series_id' => $series_id, 'credential_tab' => 'actions' ) );
		}

		$context = array(
			'actor_user_id'              => get_current_user_id(),
			'reason_code'                => $verified['reason_code'] ?? null,
			'public_reason'              => $verified['public_reason'] ?? null,
			'internal_note'              => $verified['internal_note'] ?? null,
			'expected_series_updated_at' => (string) ( $verified['series_updated_at'] ?? '' ),
			'expected_current_version_id'=> absint( $verified['version_id'] ?? 0 ),
			'expected_version_id'        => absint( $verified['version_id'] ?? 0 ),
			'expected_version_updated_at'=> (string) ( $verified['version_updated_at'] ?? '' ),
			'meta'                       => array(
				'source'        => 'credential_management',
				'review_digest' => (string) ( $verified['digest'] ?? '' ),
			),
		);
		$action  = (string) ( $verified['action'] ?? '' );
		$service = new CredentialLifecycleService();
		switch ( $action ) {
			case 'suspend':
				$result = $service->suspend( $series_id, $context );
				break;
			case 'revoke':
				$result = $service->revoke( $series_id, $context );
				break;
			case 'reinstate':
				$result = $service->reinstate( $series_id, $context );
				break;
			case 'void':
				$result = $service->void( $series_id, absint( $verified['version_id'] ?? 0 ), $context );
				break;
			default:
				$result = new \WP_Error( 'certloom_domain_lifecycle_action_invalid', __( 'Choose a valid credential lifecycle action.', 'certloom' ) );
		}
		$this->redirect_result( self::CREDENTIALS_PAGE, $result, 'credential_changed', array( 'series_id' => $series_id, 'credential_tab' => 'activity' ) );
	}

	/** @return void */
	public function relationship_correct() {
		$this->require_capability( \Ecapla_CertVerify_Permissions::CAP_MANAGE_CREDENTIALS );
		$series_id = isset( $_POST['series_id'] ) ? absint( $_POST['series_id'] ) : 0;
		check_admin_referer( 'certloom_domain_relationship_' . $series_id );
		$review = $this->decode_review_request( 'relationship_review' );
		if ( is_wp_error( $review ) ) {
			$this->redirect_result( self::CREDENTIALS_PAGE, $review, 'credential_relationship_changed', array( 'series_id' => $series_id, 'credential_tab' => 'program' ) );
		}
		$rows = isset( $review['rows'] ) && is_array( $review['rows'] ) ? $review['rows'] : array();
		if ( 1 !== count( $rows ) || absint( $rows[0]['series_id'] ?? 0 ) !== $series_id ) {
			$this->redirect_result( self::CREDENTIALS_PAGE, new \WP_Error( 'certloom_relationship_review_series_mismatch', __( 'The reviewed relationship correction belongs to another credential.', 'certloom' ) ), 'credential_relationship_changed', array( 'series_id' => $series_id, 'credential_tab' => 'program' ) );
		}
		$context = array(
			'actor_user_id' => get_current_user_id(),
			'source_ref'    => 'credential_management',
		);
		$result = ( new CredentialRelationshipBulkService() )->commit( $review, $context );
		if ( ! is_wp_error( $result ) && ! empty( $result['failed'] ) ) {
			$message = isset( $result['failed'][0]['reason'] ) ? (string) $result['failed'][0]['reason'] : __( 'The relationship correction could not be completed.', 'certloom' );
			$result = new \WP_Error( 'certloom_relationship_correction_failed', $message );
		}
		$this->redirect_result( self::CREDENTIALS_PAGE, $result, 'credential_relationship_changed', array( 'series_id' => $series_id, 'credential_tab' => 'versions' ) );
	}

	/** @return void */
	public function render_recipients() {
		$this->require_capability( \Ecapla_CertVerify_Permissions::CAP_MANAGE_RECIPIENTS );
		$search = $this->search_value();
		$page   = $this->page_value();
		$repo   = new RecipientRepository();
		$result = $repo->list_page( $search, $page, 50 );
		$summary = $repo->summary();
		$edit_id = isset( $_GET['recipient_id'] ) ? absint( $_GET['recipient_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin navigation.
		$editing = $edit_id ? $repo->find( $edit_id ) : null;
		$creating = isset( $_GET['certloom_new'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin navigation.
		$action_url = $creating || $edit_id ? add_query_arg( 'page', self::RECIPIENTS_PAGE, admin_url( 'admin.php' ) ) : add_query_arg( array( 'page' => self::RECIPIENTS_PAGE, 'certloom_new' => 1 ), admin_url( 'admin.php' ) );

		$this->header( __( 'Recipients', 'certloom' ), __( 'People and organizations who receive credentials. Recipient profile details can be reused across their credentials.', 'certloom' ), array( 'label' => $creating || $edit_id ? __( 'Back to recipients', 'certloom' ) : __( 'Add recipient', 'certloom' ), 'url' => $action_url, 'secondary' => (bool) ( $creating || $edit_id ) ) );
		$this->notice();
		if ( $creating || $edit_id ) {
			if ( is_wp_error( $editing ) ) { $this->error( $editing->get_error_message() ); }
			elseif ( is_array( $editing ) ) { $this->recipient_form( $editing ); $this->recipient_history( $repo, $editing ); }
			else { $this->recipient_form(); }
			$this->footer();
			return;
		}

		if ( ! is_wp_error( $summary ) ) {
			$this->metric_grid(
				array(
					array( 'value' => $summary['total'], 'label' => __( 'Recipients', 'certloom' ) ),
					array( 'value' => $summary['with_credentials'], 'label' => __( 'With credentials', 'certloom' ) ),
					array( 'value' => $summary['linked_users'], 'label' => __( 'Linked WordPress accounts', 'certloom' ) ),
					array( 'value' => $summary['with_email'], 'label' => __( 'With email', 'certloom' ) ),
				)
			);
		}
		$this->render_search( self::RECIPIENTS_PAGE, $search, __( 'Search name, email, or external ID', 'certloom' ) );
		if ( is_wp_error( $result ) ) {
			$this->error( $result->get_error_message() );
		} else {
			$this->prime_wp_users( $result['items'] );
			if ( AdminUiMode::is_simple() ) {
				echo '<div class="certloom-premium-list certloom-recipient-list" role="list">';
				foreach ( $result['items'] as $item ) {
					$edit_url = add_query_arg( array( 'page' => self::RECIPIENTS_PAGE, 'recipient_id' => absint( $item['id'] ) ), admin_url( 'admin.php' ) );
					$issue_url = add_query_arg( array( 'page' => ManualIssuanceAdmin::PAGE, 'recipient_id' => absint( $item['id'] ) ), admin_url( 'admin.php' ) );
					$user_label = $this->wp_user_label( $item['wp_user_id'] ?? null );
					echo '<article class="certloom-premium-list-row" role="listitem"><div class="certloom-premium-list-main"><strong><a href="' . esc_url( $edit_url ) . '">' . esc_html( (string) $item['display_name'] ) . '</a></strong><span>' . esc_html( (string) ( $item['email'] ?: ( '—' !== $user_label ? $user_label : __( 'No email added', 'certloom' ) ) ) ) . '</span></div>';
					echo '<div class="certloom-premium-list-meta"><span><small>' . esc_html__( 'Credentials', 'certloom' ) . '</small><strong>' . esc_html( number_format_i18n( absint( $item['credential_count'] ) ) ) . '</strong></span><span><small>' . esc_html__( 'Latest', 'certloom' ) . '</small><strong>' . esc_html( (string) ( $item['latest_issued_at'] ?: '—' ) ) . '</strong></span></div>';
					echo '<div class="certloom-premium-list-actions"><a class="button button-primary" href="' . esc_url( $issue_url ) . '">' . esc_html__( 'Issue', 'certloom' ) . '</a><a class="certloom-premium-row-link" href="' . esc_url( $edit_url ) . '">' . esc_html__( 'Profile', 'certloom' ) . ' <span aria-hidden="true">→</span></a></div></article>';
				}
				if ( empty( $result['items'] ) ) { $add_url = add_query_arg( array( 'page' => self::RECIPIENTS_PAGE, 'certloom_new' => 1 ), admin_url( 'admin.php' ) ); echo '<div class="certloom-premium-empty-state certloom-empty-compact"><span class="dashicons dashicons-groups" aria-hidden="true"></span><h3>' . esc_html__( 'No recipients found', 'certloom' ) . '</h3><p>' . esc_html__( 'Add a recipient or try another search.', 'certloom' ) . '</p><a class="button button-primary" href="' . esc_url( $add_url ) . '">' . esc_html__( 'Add recipient', 'certloom' ) . '</a></div>'; }
				echo '</div>';
			} else {
				echo '<div class="certloom-domain-table-wrap"><table class="widefat striped certloom-domain-table"><thead><tr><th>' . esc_html__( 'Recipient', 'certloom' ) . '</th><th>' . esc_html__( 'Email', 'certloom' ) . '</th><th>' . esc_html__( 'WordPress account', 'certloom' ) . '</th><th>' . esc_html__( 'External ID', 'certloom' ) . '</th><th>' . esc_html__( 'Credentials', 'certloom' ) . '</th><th>' . esc_html__( 'Latest issuance', 'certloom' ) . '</th><th>' . esc_html__( 'Actions', 'certloom' ) . '</th></tr></thead><tbody>';
				foreach ( $result['items'] as $item ) {
					$edit_url = add_query_arg( array( 'page' => self::RECIPIENTS_PAGE, 'recipient_id' => absint( $item['id'] ) ), admin_url( 'admin.php' ) );
					$issue_url = add_query_arg( array( 'page' => ManualIssuanceAdmin::PAGE, 'recipient_id' => absint( $item['id'] ) ), admin_url( 'admin.php' ) );
					$user_label = $this->wp_user_label( $item['wp_user_id'] ?? null );
					echo '<tr><td><strong><a href="' . esc_url( $edit_url ) . '">' . esc_html( (string) $item['display_name'] ) . '</a></strong>';
					if ( ! empty( $item['first_name'] ) || ! empty( $item['last_name'] ) ) { echo '<div class="certloom-domain-muted">' . esc_html( trim( (string) $item['first_name'] . ' ' . (string) $item['last_name'] ) ) . '</div>'; }
					echo '</td><td>' . esc_html( (string) ( $item['email'] ?: '—' ) ) . '</td><td>' . esc_html( $user_label ) . '</td><td>' . ( $item['external_id'] ? '<code>' . esc_html( (string) $item['external_id'] ) . '</code>' : '—' ) . '</td><td><span class="certloom-domain-count">' . esc_html( (string) absint( $item['credential_count'] ) ) . '</span></td><td>' . esc_html( (string) ( $item['latest_issued_at'] ?: '—' ) ) . '</td><td><div class="certloom-domain-row-actions"><a class="button button-small" href="' . esc_url( $edit_url ) . '">' . esc_html__( 'Open', 'certloom' ) . '</a><a class="button button-small" href="' . esc_url( $issue_url ) . '">' . esc_html__( 'Issue credential', 'certloom' ) . '</a></div></td></tr>';
				}
				if ( empty( $result['items'] ) ) { echo '<tr><td colspan="7"><div class="certloom-domain-empty"><strong>' . esc_html__( 'No recipients found.', 'certloom' ) . '</strong><span>' . esc_html__( 'Add a recipient or adjust the search.', 'certloom' ) . '</span></div></td></tr>'; }
				echo '</tbody></table></div>';
			}
			$this->pagination( self::RECIPIENTS_PAGE, $page, (int) $result['total'], 50, $search );
		}
		if ( is_wp_error( $editing ) ) {
			$this->error( $editing->get_error_message() );
		} elseif ( is_array( $editing ) ) {
			$this->recipient_form( $editing );
			$this->recipient_history( $repo, $editing );
		} elseif ( $creating ) {
			$this->recipient_form();
		}
		$this->footer();
	}

	/** @return void */
	public function render_programs() {
		$this->require_capability( \Ecapla_CertVerify_Permissions::CAP_MANAGE_PROGRAMS );
		$search = $this->search_value();
		$page   = $this->page_value();
		$status = isset( $_GET['program_status'] ) ? sanitize_key( wp_unslash( $_GET['program_status'] ) ) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter.
		$status = in_array( $status, array( 'all', 'active', 'inactive' ), true ) ? $status : 'all';
		$repo   = new ProgramRepository();
		$result = $repo->list_page( $search, $page, 50, $status );
		$summary = $repo->summary();
		$edit_id = isset( $_GET['program_id'] ) ? absint( $_GET['program_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin navigation.
		$editing = $edit_id ? $repo->find( $edit_id ) : null;
		$creating = isset( $_GET['certloom_new'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin navigation.
		$action_url = $creating || $edit_id ? add_query_arg( 'page', self::PROGRAMS_PAGE, admin_url( 'admin.php' ) ) : add_query_arg( array( 'page' => self::PROGRAMS_PAGE, 'certloom_new' => 1 ), admin_url( 'admin.php' ) );

		$this->header( __( 'Programs', 'certloom' ), __( 'Standalone credential programs that work with or without an LMS.', 'certloom' ), array( 'label' => $creating || $edit_id ? __( 'Back to programs', 'certloom' ) : __( 'Add program', 'certloom' ), 'url' => $action_url, 'secondary' => (bool) ( $creating || $edit_id ) ) );
		$this->notice();
		if ( $creating || $edit_id ) {
			if ( is_wp_error( $editing ) ) { $this->error( $editing->get_error_message() ); }
			elseif ( is_array( $editing ) ) {
				$this->program_form( $editing );
				if ( AdminUiMode::is_simple() ) { $this->program_workspace( $editing ); }
				else { $this->program_history( $repo, $editing ); }
			}
			else { $this->program_form(); }
			$this->footer();
			return;
		}

		if ( ! is_wp_error( $summary ) ) {
			$this->metric_grid(
				array(
					array( 'value' => $summary['total'], 'label' => __( 'Programs', 'certloom' ) ),
					array( 'value' => $summary['active'], 'label' => _x( 'Active', 'entity status', 'certloom' ) ),
					array( 'value' => $summary['inactive'], 'label' => _x( 'Inactive', 'entity status', 'certloom' ) ),
					array( 'value' => $summary['with_credentials'], 'label' => __( 'With credentials', 'certloom' ) ),
				)
			);
		}
		$this->program_views( $status, $search );
		$this->render_search( self::PROGRAMS_PAGE, $search, __( 'Search title, code, or description', 'certloom' ), array( 'program_status' => $status ) );
		if ( is_wp_error( $result ) ) {
			$this->error( $result->get_error_message() );
		} elseif ( AdminUiMode::is_simple() ) {
			$this->simple_program_list( (array) $result['items'] );
			$this->pagination( self::PROGRAMS_PAGE, $page, (int) $result['total'], 50, $search, array( 'program_status' => $status ) );
		} else {
			echo '<div class="certloom-domain-table-wrap"><table class="widefat striped certloom-domain-table"><thead><tr><th>' . esc_html__( 'Program', 'certloom' ) . '</th><th>' . esc_html__( 'Purpose', 'certloom' ) . '</th><th>' . esc_html__( 'Code', 'certloom' ) . '</th><th>' . esc_html__( 'Issuer', 'certloom' ) . '</th><th>' . esc_html__( 'Default validity', 'certloom' ) . '</th><th>' . esc_html__( 'Credentials', 'certloom' ) . '</th><th>' . esc_html__( 'Status', 'certloom' ) . '</th><th>' . esc_html__( 'Actions', 'certloom' ) . '</th></tr></thead><tbody>';
			foreach ( $result['items'] as $item ) {
				$edit_url = add_query_arg( array( 'page' => self::PROGRAMS_PAGE, 'program_id' => absint( $item['id'] ) ), admin_url( 'admin.php' ) );
				$validity = null === $item['validity_days'] ? __( 'No default', 'certloom' ) : sprintf( /* translators: %d: days. */ _n( '%d day', '%d days', absint( $item['validity_days'] ), 'certloom' ), absint( $item['validity_days'] ) );
				echo '<tr><td><strong><a href="' . esc_url( $edit_url ) . '">' . esc_html( (string) $item['title'] ) . '</a></strong></td><td><span class="certloom-program-purpose' . ( ! empty( $item['profile_error'] ) ? ' is-review' : '' ) . '">' . esc_html( (string) ( $item['purpose_label'] ?: '—' ) ) . '</span></td><td>' . ( $item['code'] ? '<code>' . esc_html( (string) $item['code'] ) . '</code>' : '—' ) . '</td><td>' . esc_html( (string) ( $item['issuer_name'] ?: '—' ) ) . '</td><td>' . esc_html( $validity ) . '</td><td><span class="certloom-domain-count">' . esc_html( (string) absint( $item['credential_count'] ) ) . '</span></td><td><span class="certloom-domain-status ' . ( $item['active'] ? 'is-active' : 'is-inactive' ) . '">' . esc_html( $item['active'] ? _x( 'Active', 'entity status', 'certloom' ) : _x( 'Inactive', 'entity status', 'certloom' ) ) . '</span></td><td><a class="button button-small" href="' . esc_url( $edit_url ) . '">' . esc_html__( 'Open', 'certloom' ) . '</a></td></tr>';
			}
			if ( empty( $result['items'] ) ) { echo '<tr><td colspan="8"><div class="certloom-domain-empty"><strong>' . esc_html__( 'No programs found.', 'certloom' ) . '</strong><span>' . esc_html__( 'Add a program or adjust the filters.', 'certloom' ) . '</span></div></td></tr>'; }
			echo '</tbody></table></div>';
			$this->pagination( self::PROGRAMS_PAGE, $page, (int) $result['total'], 50, $search, array( 'program_status' => $status ) );
		}
		if ( is_wp_error( $editing ) ) {
			$this->error( $editing->get_error_message() );
		} elseif ( is_array( $editing ) ) {
			$this->program_form( $editing );
			if ( AdminUiMode::is_simple() ) { $this->program_workspace( $editing ); }
			else { $this->program_history( $repo, $editing ); }
		} elseif ( $creating ) {
			$this->program_form();
		}
		$this->footer();
	}


	/** @return void */
	public function render_issuers() {
		$this->require_capability( \Ecapla_CertVerify_Permissions::CAP_MANAGE_ISSUERS );
		$search = $this->search_value();
		$page   = $this->page_value();
		$repo   = new IssuerRepository();
		$result = $repo->list_page( $search, $page, 50 );
		$edit_id = isset( $_GET['issuer_id'] ) ? absint( $_GET['issuer_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin navigation.
		$editing = $edit_id ? $repo->find( $edit_id ) : null;
		$creating = isset( $_GET['certloom_new'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin navigation.
		$action_url = $creating || $edit_id ? add_query_arg( 'page', self::ISSUERS_PAGE, admin_url( 'admin.php' ) ) : add_query_arg( array( 'page' => self::ISSUERS_PAGE, 'certloom_new' => 1 ), admin_url( 'admin.php' ) );

		$this->header( __( 'Issuers & brands', 'certloom' ), __( 'Manage stable issuing identities and local brand defaults without changing historical credential snapshots.', 'certloom' ), array( 'label' => $creating || $edit_id ? __( 'Back to issuers', 'certloom' ) : __( 'Add issuer', 'certloom' ), 'url' => $action_url, 'secondary' => (bool) ( $creating || $edit_id ) ) );
		$this->notice();
		if ( $creating || $edit_id ) {
			if ( is_wp_error( $editing ) ) { $this->error( $editing->get_error_message() ); }
			elseif ( is_array( $editing ) ) { $this->issuer_form( $editing ); }
			else { $this->issuer_form(); }
			$this->footer();
			return;
		}

		$this->render_search( self::ISSUERS_PAGE, $search );
		if ( is_wp_error( $result ) ) {
			$this->error( $result->get_error_message() );
		} elseif ( AdminUiMode::is_simple() ) {
			echo '<div class="certloom-premium-list certloom-premium-list-issuers">';
			foreach ( $result['items'] as $item ) {
				$edit_url = add_query_arg( array( 'page' => self::ISSUERS_PAGE, 'issuer_id' => absint( $item['id'] ) ), admin_url( 'admin.php' ) );
				$program_count = absint( $item['program_count'] ?? 0 );
				$signer_count = absint( $item['signer_count'] ?? 0 );
				echo '<article class="certloom-premium-list-row"><div class="certloom-premium-list-main"><strong><a href="' . esc_url( $edit_url ) . '">' . esc_html( (string) $item['name'] ) . '</a></strong><div class="certloom-premium-list-meta">' . esc_html( sprintf( /* translators: %d: program count. */ _n( '%d Program', '%d Programs', $program_count, 'certloom' ), $program_count ) ) . ' · ' . esc_html( sprintf( /* translators: %d: signer count. */ _n( '%d signer', '%d signers', $signer_count, 'certloom' ), $signer_count ) ) . '</div></div><div class="certloom-premium-list-actions"><a class="certloom-premium-row-link" href="' . esc_url( $edit_url ) . '">' . esc_html__( 'Details', 'certloom' ) . ' <span aria-hidden="true">→</span></a></div></article>';
			}
			if ( empty( $result['items'] ) ) { $add_url = add_query_arg( array( 'page' => self::ISSUERS_PAGE, 'certloom_new' => 1 ), admin_url( 'admin.php' ) ); echo '<div class="certloom-premium-empty-state certloom-empty-compact"><h3>' . esc_html__( 'No issuers yet', 'certloom' ) . '</h3><p>' . esc_html__( 'Add the organization or brand that issues credentials.', 'certloom' ) . '</p><a class="button button-primary" href="' . esc_url( $add_url ) . '">' . esc_html__( 'Add issuer', 'certloom' ) . '</a></div>'; }
			echo '</div>';
			$this->pagination( self::ISSUERS_PAGE, $page, (int) $result['total'], 50, $search );
		} else {
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Issuer', 'certloom' ) . '</th><th>' . esc_html__( 'Slug', 'certloom' ) . '</th><th>' . esc_html__( 'Programs', 'certloom' ) . '</th><th>' . esc_html__( 'Signers', 'certloom' ) . '</th><th>' . esc_html__( 'Created', 'certloom' ) . '</th><th>' . esc_html__( 'Actions', 'certloom' ) . '</th></tr></thead><tbody>';
			foreach ( $result['items'] as $item ) {
				$edit_url = add_query_arg( array( 'page' => self::ISSUERS_PAGE, 'issuer_id' => absint( $item['id'] ) ), admin_url( 'admin.php' ) );
				echo '<tr><td><strong>' . esc_html( (string) $item['name'] ) . '</strong></td><td><code>' . esc_html( (string) $item['slug'] ) . '</code></td><td>' . esc_html( (string) absint( $item['program_count'] ?? 0 ) ) . '</td><td>' . esc_html( (string) absint( $item['signer_count'] ?? 0 ) ) . '</td><td>' . esc_html( (string) $item['created_at'] ) . '</td><td><a class="button button-small" href="' . esc_url( $edit_url ) . '">' . esc_html__( 'Open', 'certloom' ) . '</a></td></tr>';
			}
			if ( empty( $result['items'] ) ) { echo '<tr><td colspan="6">' . esc_html__( 'No issuers found.', 'certloom' ) . '</td></tr>'; }
			echo '</tbody></table>';
			$this->pagination( self::ISSUERS_PAGE, $page, (int) $result['total'], 50, $search );
		}
		if ( is_wp_error( $editing ) ) {
			$this->error( $editing->get_error_message() );
		} elseif ( is_array( $editing ) ) {
			$this->issuer_form( $editing );
		} elseif ( $creating ) {
			$this->issuer_form();
		}
		$this->footer();
	}

	/** @return void */
	public function render_credentials() {
		$this->require_capability( \Ecapla_CertVerify_Permissions::CAP_VIEW_CREDENTIALS );
		$series_id = isset( $_GET['series_id'] ) ? absint( $_GET['series_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin navigation.
		$legacy_id = isset( $_GET['legacy_id'] ) ? absint( $_GET['legacy_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only historical Credential navigation.
		if ( $legacy_id ) {
			$this->render_historical_credential_detail( $legacy_id );
			return;
		}
		if ( $series_id ) {
			$this->render_credential_detail( $series_id );
			return;
		}

		$search     = $this->search_value();
		$page       = $this->page_value();
		$status     = isset( $_GET['credential_status'] ) ? sanitize_key( wp_unslash( $_GET['credential_status'] ) ) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter.
		$program_id = isset( $_GET['program_id'] ) ? absint( $_GET['program_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter.
		$allowed    = array( 'all', 'valid', 'suspended', 'revoked', 'expired', 'not_yet_valid', 'pending_review', 'noncompliant', 'voided', 'not_issued' );
		$status     = in_array( $status, $allowed, true ) ? $status : 'all';
		$repo       = new UnifiedCredentialRegistry();
		$result     = $repo->list_page( $search, $page, 50, $status, $program_id );
		$summary    = $repo->summary();
		$programs   = ( new ScopedContextChoices() )->programs( $program_id, 'all' );
		$status_service = new StatusService();

		$simple = AdminUiMode::is_simple();
		$issue_action = $simple && current_user_can( \Ecapla_CertVerify_Permissions::CAP_MANAGE_CREDENTIALS )
			? array( 'label' => __( 'Issue credential', 'certloom' ), 'url' => add_query_arg( 'page', 'certloom-issuance', admin_url( 'admin.php' ) ) )
			: null;
		$this->header(
			$simple ? __( 'Credentials', 'certloom' ) : __( 'Credential Management', 'certloom' ),
			$simple ? __( 'See who received each credential, open the public view, or continue managing it without navigating through internal record structures.', 'certloom' ) : __( 'Inspect continuing Credential Series, immutable Version lineage, effective status, verification identity, and reviewed lifecycle actions from one premium workspace.', 'certloom' ),
			$issue_action
		);
		$this->notice();
		if ( ! is_wp_error( $summary ) ) {
			$this->metric_grid(
				array(
					array( 'value' => $summary['total'], 'label' => __( 'Credentials', 'certloom' ) ),
					array( 'value' => $summary['valid'], 'label' => __( 'Valid now', 'certloom' ) ),
					array( 'value' => $summary['suspended'], 'label' => __( 'Suspended', 'certloom' ) ),
					array( 'value' => $summary['revoked'], 'label' => __( 'Revoked', 'certloom' ) ),
					array( 'value' => $summary['expired'] + $summary['pending_review'] + $summary['noncompliant'], 'label' => __( 'Needs attention', 'certloom' ) ),
				)
			);
		}
		$this->credential_views( $status, $search, $program_id, is_wp_error( $summary ) ? array() : $summary );
		$this->credential_filters( $search, $status, $program_id, is_wp_error( $programs ) ? array() : $programs );
		if ( is_wp_error( $result ) ) {
			$this->error( $result->get_error_message() );
		} elseif ( $simple ) {
			$this->simple_credential_list( (array) $result['items'], $status_service );
			$this->pagination( self::CREDENTIALS_PAGE, $page, (int) $result['total'], 50, $search, array( 'credential_status' => $status, 'program_id' => $program_id ) );
		} else {
			echo '<div class="certloom-domain-table-wrap"><table class="widefat striped certloom-domain-table certloom-credential-table"><thead><tr><th>' . esc_html__( 'Credential', 'certloom' ) . '</th><th>' . esc_html__( 'Recipient', 'certloom' ) . '</th><th>' . esc_html__( 'Program / batch', 'certloom' ) . '</th><th>' . esc_html__( 'Issued / expiry', 'certloom' ) . '</th><th>' . esc_html__( 'Version', 'certloom' ) . '</th><th>' . esc_html__( 'Status', 'certloom' ) . '</th><th>' . esc_html__( 'Actions', 'certloom' ) . '</th></tr></thead><tbody>';
			foreach ( $result['items'] as $item ) {
				$is_historical = 'historical' === (string) ( $item['_credential_source'] ?? '' );
				$source_label = (string) ( $item['_credential_source_label'] ?? ( $is_historical ? __( 'Historical', 'certloom' ) : __( 'Current', 'certloom' ) ) );
				$source_class = 'Historical' === $source_label ? 'is-historical' : 'is-current';
				$effective = $is_historical ? null : $this->status_from_row( $status_service, $item );
				$detail_url = $is_historical
					? add_query_arg( array( 'page' => self::CREDENTIALS_PAGE, 'legacy_id' => absint( $item['legacy_id'] ?? 0 ) ), admin_url( 'admin.php' ) )
					: add_query_arg( array( 'page' => self::CREDENTIALS_PAGE, 'series_id' => absint( $item['series_id'] ?? 0 ) ), admin_url( 'admin.php' ) );
				$number = trim( (string) ( $item['display_number'] ?? '' ) );
				$program = trim( (string) ( $item['program_title'] ?? '' ) );
				$batch = trim( (string) ( $item['batch_name'] ?? '' ) );
				echo '<tr>';
				echo '<td data-col="' . esc_attr__( 'Credential', 'certloom' ) . '"><strong><a href="' . esc_url( $detail_url ) . '">' . esc_html( '' !== $number ? $number : __( 'Unnumbered credential', 'certloom' ) ) . '</a></strong><div class="certloom-credential-secondary certloom-credential-source-meta"><span class="certloom-credential-source ' . esc_attr( $source_class ) . '">' . esc_html( $source_label ) . '</span>' . ( ! $is_historical && ! empty( $item['public_id'] ) ? '<code>' . esc_html( (string) $item['public_id'] ) . '</code>' : '' ) . '</div></td>';
				echo '<td data-col="' . esc_attr__( 'Recipient', 'certloom' ) . '"><strong>' . esc_html( (string) ( $item['display_name'] ?: '—' ) ) . '</strong>' . ( ! empty( $item['recipient_email'] ) ? '<div class="certloom-credential-secondary">' . esc_html( (string) $item['recipient_email'] ) . '</div>' : '' ) . '</td>';
				echo '<td data-col="' . esc_attr__( 'Program / batch', 'certloom' ) . '">' . esc_html( '' !== $program ? $program : '—' ) . ( '' !== $batch ? '<div class="certloom-credential-secondary">' . esc_html( $batch ) . '</div>' : '' ) . '</td>';
				echo '<td data-col="' . esc_attr__( 'Issued / expiry', 'certloom' ) . '"><span>' . esc_html( (string) ( $item['issued_at'] ?: '—' ) ) . '</span><div class="certloom-credential-secondary">' . esc_html( ! empty( $item['expires_at'] ) ? sprintf( /* translators: %s: expiry date. */ __( 'Expires %s', 'certloom' ), (string) $item['expires_at'] ) : __( 'No expiry', 'certloom' ) ) . '</div></td>';
				echo '<td data-col="' . esc_attr__( 'Version', 'certloom' ) . '">' . ( ! empty( $item['version_number'] ) ? '<strong>v' . esc_html( (string) absint( $item['version_number'] ) ) . '</strong>' : '—' ) . '</td>';
				echo '<td data-col="' . esc_attr__( 'Status', 'certloom' ) . '">' . ( $is_historical ? $this->historical_status_badge( (string) ( $item['legacy_status_code'] ?? '' ) ) : $this->credential_status_badge( $effective ) ) . '</td>';
				echo '<td data-col="' . esc_attr__( 'Actions', 'certloom' ) . '"><a class="button button-small" href="' . esc_url( $detail_url ) . '">' . esc_html__( 'Open workspace', 'certloom' ) . '</a></td>';
				echo '</tr>';
			}
			if ( empty( $result['items'] ) ) {
				echo '<tr><td colspan="7"><div class="certloom-domain-empty"><strong>' . esc_html__( 'No credentials match these filters.', 'certloom' ) . '</strong><span>' . esc_html__( 'Adjust the search, status, or Program filter. Manual Issuance creates new Credential Series here.', 'certloom' ) . '</span></div></td></tr>';
			}
			echo '</tbody></table></div>';
			$this->pagination( self::CREDENTIALS_PAGE, $page, (int) $result['total'], 50, $search, array( 'credential_status' => $status, 'program_id' => $program_id ) );
		}
		$this->footer();
	}


	/** Read-only adapter view for a pre-normalized credential kept in historical storage. @return void */
	private function render_historical_credential_detail( $legacy_id ) {
		$registry = new UnifiedCredentialRegistry();
		$row = $registry->find_historical( $legacy_id );
		$this->header(
			__( 'Credential', 'certloom' ),
			__( 'This credential was issued before the current Program-based data model. It stays in its original storage for safety, but is managed from the same Credentials workspace.', 'certloom' )
		);
		$this->notice();
		if ( is_wp_error( $row ) || ! is_array( $row ) ) {
			$this->error( is_wp_error( $row ) ? $row->get_error_message() : __( 'The historical credential could not be found.', 'certloom' ) );
			$this->footer();
			return;
		}
		$number = (string) ( $row['cert_number'] ?? '' );
		$verify_url = class_exists( 'Ecapla_CertVerify_Public_Router' ) ? \Ecapla_CertVerify_Public_Router::verification_url( $number ) : '';
		$profile_url = class_exists( 'Ecapla_CertVerify_Public_Router' ) ? \Ecapla_CertVerify_Public_Router::profile_url( $number ) : '';
		$edit_url = add_query_arg( array( 'page' => 'certloom-add', 'id' => absint( $row['id'] ?? 0 ) ), admin_url( 'admin.php' ) );
		echo '<section class="certloom-credential-hero certloom-historical-credential-hero"><div><span class="certloom-credential-source is-historical">' . esc_html__( 'Historical', 'certloom' ) . '</span><h2>' . esc_html( '' !== $number ? $number : __( 'Historical credential', 'certloom' ) ) . '</h2><p>' . esc_html( (string) ( $row['holder_name'] ?? '' ) ) . '</p></div><div class="certloom-credential-hero-actions">';
		if ( '' !== $profile_url ) { echo '<a class="button button-primary" href="' . esc_url( $profile_url ) . '" target="_blank" rel="noopener">' . esc_html__( 'View credential', 'certloom' ) . ' <span aria-hidden="true">↗</span></a>'; }
		if ( current_user_can( \ECAPLA_CERTVERIFY_CAP ) ) { echo '<a class="button" href="' . esc_url( $edit_url ) . '">' . esc_html__( 'Edit historical record', 'certloom' ) . '</a>'; }
		echo '</div></section>';
		echo '<section class="certloom-credential-panel"><div class="certloom-credential-panel-head"><div><h3>' . esc_html__( 'Credential summary', 'certloom' ) . '</h3><p>' . esc_html__( 'Historical records are labeled so you can distinguish them from current Program-based credentials without maintaining a second registry screen.', 'certloom' ) . '</p></div>' . $this->historical_status_badge( (string) ( $row['legacy_status_code'] ?? '' ) ) . '</div>';
		$this->credential_info_card( __( 'Issued record', 'certloom' ), array(
			__( 'Recipient', 'certloom' ) => esc_html( (string) ( $row['holder_name'] ?? '—' ) ),
			__( 'Certificate number', 'certloom' ) => esc_html( '' !== $number ? $number : '—' ),
			__( 'Issued', 'certloom' ) => esc_html( ! empty( $row['issue_date'] ) ? (string) $row['issue_date'] : '—' ),
			__( 'Expires', 'certloom' ) => esc_html( ! empty( $row['expiry_date'] ) ? (string) $row['expiry_date'] : __( 'No expiry', 'certloom' ) ),
		) );
		if ( '' !== $verify_url ) { echo '<p class="certloom-credential-panel-actions"><a class="button" href="' . esc_url( $verify_url ) . '" target="_blank" rel="noopener">' . esc_html__( 'Open verification result', 'certloom' ) . ' <span aria-hidden="true">↗</span></a></p>'; }
		echo '</section>';
		$this->footer();
	}

	/** @return void */
	private function render_credential_detail( $series_id ) {
		$simple      = AdminUiMode::is_simple();
		$credentials = new CredentialRepository();
		$series      = $credentials->find_series( $series_id );
		if ( is_wp_error( $series ) || ! is_array( $series ) ) {
			$this->header( __( 'Credential Management', 'certloom' ) );
			$this->notice();
			$this->error( is_wp_error( $series ) ? $series->get_error_message() : __( 'The credential could not be found.', 'certloom' ) );
			$this->footer();
			return;
		}

		$versions = $credentials->list_versions_for_series( $series_id, 250 );
		if ( is_wp_error( $versions ) ) {
			$this->header( __( 'Credential Management', 'certloom' ) );
			$this->notice();
			$this->error( $versions->get_error_message() );
			$this->footer();
			return;
		}
		$current_version_id = absint( $series['current_version_id'] ?? 0 );
		$selected_version_id = isset( $_GET['version_id'] ) ? absint( $_GET['version_id'] ) : $current_version_id; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only immutable Version inspection.
		$version = null;
		foreach ( $versions as $candidate ) {
			if ( absint( $candidate['id'] ?? 0 ) === $selected_version_id ) { $version = $candidate; break; }
		}
		if ( ! $version && $current_version_id ) {
			foreach ( $versions as $candidate ) { if ( absint( $candidate['id'] ?? 0 ) === $current_version_id ) { $version = $candidate; $selected_version_id = $current_version_id; break; } }
		}
		$is_current = is_array( $version ) && absint( $version['id'] ?? 0 ) === $current_version_id;
		$requested_tab = isset( $_GET['credential_tab'] ) ? sanitize_key( wp_unslash( $_GET['credential_tab'] ) ) : ( $simple ? 'summary' : 'overview' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only workspace tab.
		$requested_section = isset( $_GET['credential_section'] ) ? sanitize_key( wp_unslash( $_GET['credential_section'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only contextual workspace subtab.
		$tab = $this->credential_panel_target( $requested_tab, $requested_section, $simple );

		$recipient = ( new RecipientRepository() )->find( $series['recipient_id'] );
		$program   = ! empty( $series['program_id'] ) ? ( new ProgramRepository() )->find( $series['program_id'] ) : null;
		$batch     = ! empty( $series['batch_id'] ) ? ( new BatchRepository() )->find( $series['batch_id'] ) : null;
		$issuer    = ! empty( $series['issuer_id'] ) ? ( new IssuerRepository() )->find( $series['issuer_id'] ) : null;
		foreach ( array( 'recipient', 'program', 'batch', 'issuer' ) as $name ) {
			if ( is_wp_error( ${$name} ) ) { ${$name} = null; }
		}
		$status = is_array( $version ) ? $this->status_from_row( new StatusService(), array_merge( $version, array( 'administrative_state' => $series['administrative_state'], 'compliance_state' => $series['compliance_state'] ) ) ) : null;

		$lifecycle_review = null;
		$relationship_review = null;
		if ( 'POST' === strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) && current_user_can( \Ecapla_CertVerify_Permissions::CAP_MANAGE_CREDENTIALS ) ) {
			if ( isset( $_POST['certloom_prepare_lifecycle'] ) ) {
				check_admin_referer( 'certloom_domain_lifecycle_review_' . $series_id );
				if ( ! $is_current || ! is_array( $version ) ) {
					$lifecycle_review = new \WP_Error( 'certloom_lifecycle_historical_read_only', __( 'Historical Versions are read-only. Return to the current Version before reviewing a lifecycle action.', 'certloom' ) );
				} else {
					$context = array(
						'reason_code'   => isset( $_POST['reason_code'] ) ? sanitize_key( wp_unslash( $_POST['reason_code'] ) ) : null,
						'public_reason' => isset( $_POST['public_reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['public_reason'] ) ) : null,
						'internal_note' => isset( $_POST['internal_note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['internal_note'] ) ) : null,
					);
					$action = isset( $_POST['lifecycle_action'] ) ? sanitize_key( wp_unslash( $_POST['lifecycle_action'] ) ) : '';
					$lifecycle_review = ( new CredentialLifecycleReviewService() )->prepare( $series, $version, $action, $context, get_current_user_id() );
				}
				$tab = 'actions';
			}
			if ( isset( $_POST['certloom_prepare_relationship'] ) ) {
				check_admin_referer( 'certloom_domain_relationship_review_' . $series_id );
				$target_program = isset( $_POST['program_id'] ) ? absint( $_POST['program_id'] ) : 0;
				$target_batch   = isset( $_POST['batch_id'] ) ? absint( $_POST['batch_id'] ) : 0;
				if ( ! $target_program && ! $target_batch ) {
					$relationship_review = new \WP_Error( 'certloom_relationship_program_required', __( 'Choose a Program or Batch before reviewing a relationship correction.', 'certloom' ) );
				} else {
					$relationship_context = array(
						'reason_code'   => 'relationship_correction',
						'public_reason' => isset( $_POST['public_reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['public_reason'] ) ) : null,
						'internal_note' => isset( $_POST['internal_note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['internal_note'] ) ) : null,
					);
					$relationship_review = ( new CredentialRelationshipBulkService() )->prepare( array( $series_id ), $target_program ?: null, $target_batch ?: null, get_current_user_id(), $relationship_context );
				}
				$tab = 'program';
			}
		}

		$number = is_array( $version ) && ! empty( $version['display_number'] ) ? (string) $version['display_number'] : __( 'Unnumbered credential', 'certloom' );
		$this->header(
			$simple ? __( 'Credential', 'certloom' ) : __( 'Credential Management', 'certloom' ),
			$simple ? __( 'View the credential, its recipient and Program, deliver it, or manage its current status from one place.', 'certloom' ) : __( 'One continuing credential identity with immutable historical Versions and review-before-commit administration.', 'certloom' )
		);
		$this->notice();
		echo '<div class="certloom-credential-back"><a href="' . esc_url( add_query_arg( 'page', self::CREDENTIALS_PAGE, admin_url( 'admin.php' ) ) ) . '">&larr; ' . esc_html__( 'Back to credentials', 'certloom' ) . '</a></div>';
		if ( $simple ) {
			$recipient_label = is_array( $recipient ) ? trim( (string) ( $recipient['display_name'] ?? '' ) ) : '';
			$program_label = is_array( $program ) ? trim( (string) ( $program['title'] ?? '' ) ) : '';
			$public_url = ( new \Certloom\Infrastructure\Issuance\NormalizedPublicCredentialUrl() )->verification_url( (string) $series['public_id'] );
			$issue_again_url = add_query_arg( array( 'page' => ManualIssuanceAdmin::PAGE, 'recipient_id' => absint( $series['recipient_id'] ?? 0 ), 'program_id' => absint( $series['program_id'] ?? 0 ), 'batch_id' => absint( $series['batch_id'] ?? 0 ) ), admin_url( 'admin.php' ) );
			echo '<section class="certloom-credential-hero certloom-credential-hero-simple"><div><div class="certloom-credential-eyebrow">' . esc_html( $is_current ? __( 'Credential', 'certloom' ) : __( 'Credential history', 'certloom' ) ) . '</div><h2>' . esc_html( $number ) . '</h2><div class="certloom-credential-hero-meta">' . ( '' !== $recipient_label ? '<span>' . esc_html( $recipient_label ) . '</span>' : '' ) . ( '' !== $program_label ? '<span>' . esc_html( $program_label ) . '</span>' : '' ) . '</div></div><div class="certloom-credential-hero-actions">' . $this->credential_status_badge( $status );
			if ( $is_current && ! is_wp_error( $public_url ) ) { echo '<a class="button button-primary" href="' . esc_url( (string) $public_url ) . '" target="_blank" rel="noopener">' . esc_html__( 'View credential', 'certloom' ) . ' <span aria-hidden="true">↗</span></a>'; }
			if ( $is_current ) { echo '<a class="button" href="' . esc_url( $issue_again_url ) . '">' . esc_html__( 'Issue another', 'certloom' ) . '</a>'; }
			echo '</div></section>';
		} else {
			echo '<section class="certloom-credential-hero"><div><div class="certloom-credential-eyebrow">' . esc_html( $is_current ? __( 'Current immutable Version', 'certloom' ) : __( 'Historical immutable Version', 'certloom' ) ) . '</div><h2>' . esc_html( $number ) . '</h2><div class="certloom-credential-hero-meta"><code>' . esc_html( (string) $series['public_id'] ) . '</code>' . ( is_array( $version ) ? '<span>v' . esc_html( (string) absint( $version['version_number'] ?? 0 ) ) . '</span>' : '' ) . '</div></div><div>' . $this->credential_status_badge( $status ) . '</div></section>';
		}
		if ( ! $is_current && is_array( $version ) ) {
			echo '<div class="notice notice-info inline"><p><strong>' . esc_html( $simple ? __( 'Earlier credential record selected.', 'certloom' ) : __( 'Historical Version selected.', 'certloom' ) ) . '</strong> ' . esc_html( $simple ? __( 'You can review this earlier record, but changes are made only to the current credential.', 'certloom' ) : __( 'Its immutable snapshot can be inspected, but lifecycle and relationship mutations always target the current Version and never rewrite this history.', 'certloom' ) ) . ' <a href="' . esc_url( add_query_arg( array( 'page' => self::CREDENTIALS_PAGE, 'series_id' => $series_id, 'credential_tab' => $tab ), admin_url( 'admin.php' ) ) ) . '">' . esc_html( $simple ? __( 'Return to current credential', 'certloom' ) : __( 'Return to current Version', 'certloom' ) ) . '</a></p></div>';
		}
		$workspace = $this->credential_workspace_state( $tab, $simple );
		$this->credential_tabs( $series_id, $workspace['primary'], $selected_version_id, $current_version_id, $simple );
		if ( $simple && in_array( $workspace['primary'], array( 'credential', 'history' ), true ) ) {
			$this->credential_subtabs( $series_id, $workspace['primary'], $workspace['section'], $selected_version_id, $current_version_id );
		}

		switch ( $tab ) {
			case 'recipient':
				$this->credential_recipient_panel( $recipient );
				break;
			case 'program':
				$this->credential_program_panel( $series, $program, $batch, $issuer, $relationship_review, $is_current );
				break;
			case 'status':
				$this->credential_status_panel( $series, $version, $status );
				break;
			case 'verification':
				$this->credential_verification_panel( $series, $version );
				break;
			case 'versions':
				$this->credential_versions_panel( $series_id, $versions, $current_version_id, $selected_version_id );
				break;
			case 'activity':
				$this->event_history( $series_id );
				break;
			case 'actions':
				$this->credential_actions_panel( $series, $version, $lifecycle_review, $is_current );
				break;
			case 'overview':
			default:
				$this->credential_overview_panel( $series, $version, $recipient, $program, $batch, $issuer, $status, $is_current );
				break;
		}
		$this->footer();
	}

	/** @return void */
	private function credential_views( $current, $search, $program_id, array $summary ) {
		$views = array(
			'all'            => __( 'All', 'certloom' ),
			'valid'          => __( 'Valid', 'certloom' ),
			'suspended'      => __( 'Suspended', 'certloom' ),
			'revoked'        => __( 'Revoked', 'certloom' ),
			'expired'        => __( 'Expired', 'certloom' ),
			'not_yet_valid'  => __( 'Not yet valid', 'certloom' ),
			'pending_review' => __( 'Pending review', 'certloom' ),
			'noncompliant'   => __( 'Non-compliant', 'certloom' ),
			'voided'         => __( 'Voided', 'certloom' ),
			'not_issued'     => __( 'Not issued', 'certloom' ),
		);
		echo '<nav class="certloom-credential-views" aria-label="' . esc_attr__( 'Credential status views', 'certloom' ) . '">';
		foreach ( $views as $key => $label ) {
			$args = array( 'page' => self::CREDENTIALS_PAGE, 'credential_status' => $key );
			if ( '' !== $search ) { $args['s'] = $search; }
			if ( $program_id ) { $args['program_id'] = $program_id; }
			$count_key = 'all' === $key ? 'total' : $key;
			$count = array_key_exists( $count_key, $summary ) ? '<span>' . esc_html( (string) absint( $summary[ $count_key ] ) ) . '</span>' : '';
			echo '<a class="' . ( $key === $current ? 'is-current' : '' ) . '" href="' . esc_url( add_query_arg( $args, admin_url( 'admin.php' ) ) ) . '">' . esc_html( $label ) . $count . '</a>';
		}
		echo '</nav>';
	}

	/** @param array<int,array<string,mixed>> $programs @return void */
	private function credential_filters( $search, $status, $program_id, array $programs ) {
		$simple = AdminUiMode::is_simple();
		$search_placeholder = $simple ? __( 'Certificate number, recipient, email, Program, or batch', 'certloom' ) : __( 'Certificate, recipient, email, Program, batch, or public ID', 'certloom' );
		echo '<form method="get" class="certloom-domain-search certloom-credential-filters"><input type="hidden" name="page" value="' . esc_attr( self::CREDENTIALS_PAGE ) . '"><input type="hidden" name="credential_status" value="' . esc_attr( $status ) . '">';
		echo '<label><span class="screen-reader-text">' . esc_html__( 'Search credentials', 'certloom' ) . '</span><input type="search" name="s" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr( $search_placeholder ) . '"></label>';
		$context_nonce = wp_create_nonce( ScopedFieldAdminAjax::NONCE_ACTION );
		echo '<label><span class="screen-reader-text">' . esc_html__( 'Filter by Program', 'certloom' ) . '</span><select name="program_id" data-certloom-context-lookup="program" data-certloom-context-program-status="all" data-certloom-context-nonce="' . esc_attr( $context_nonce ) . '" data-certloom-context-search-placeholder="' . esc_attr__( 'Search all Programs…', 'certloom' ) . '"><option value="0">' . esc_html__( 'All Programs', 'certloom' ) . '</option>';
		foreach ( $programs as $program ) {
			echo '<option value="' . esc_attr( (string) absint( $program['id'] ?? 0 ) ) . '" ' . selected( $program_id, absint( $program['id'] ?? 0 ), false ) . '>' . esc_html( (string) ( $program['title'] ?? '' ) ) . ( ! empty( $program['code'] ) ? ' — ' . esc_html( (string) $program['code'] ) : '' ) . '</option>';
		}
		echo '</select></label><button class="button">' . esc_html__( 'Filter', 'certloom' ) . '</button>';
		if ( '' !== $search || $program_id ) {
			echo '<a class="button-link" href="' . esc_url( add_query_arg( array( 'page' => self::CREDENTIALS_PAGE, 'credential_status' => $status ), admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'Clear search/filter', 'certloom' ) . '</a>';
		}
		echo '</form>';
	}

	/** Compact Simple-mode credential list with direct public-view continuity. @return void */
	private function simple_credential_list( array $items, StatusService $status_service ) {
		if ( empty( $items ) ) {
			echo '<div class="certloom-premium-empty-state certloom-empty-compact"><span class="dashicons dashicons-awards" aria-hidden="true"></span><h3>' . esc_html__( 'No credentials match these filters', 'certloom' ) . '</h3><p>' . esc_html__( 'Try another filter, or issue a credential to a Recipient.', 'certloom' ) . '</p><a class="button button-primary" href="' . esc_url( add_query_arg( 'page', 'certloom-issuance', admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'Issue credential', 'certloom' ) . '</a></div>';
			return;
		}
		$public_ready = ( new \Certloom\Infrastructure\Verification\PublicVerificationGate() )->is_ready();
		$url_builder  = new \Certloom\Infrastructure\Issuance\NormalizedPublicCredentialUrl();
		echo '<div class="certloom-premium-list certloom-credential-list" role="list">';
		foreach ( $items as $item ) {
			$is_historical = 'historical' === (string) ( $item['_credential_source'] ?? '' );
			$number        = trim( (string) ( $item['display_number'] ?? '' ) );
			$recipient     = trim( (string) ( $item['display_name'] ?? '' ) );
			$program       = trim( (string) ( $item['program_title'] ?? '' ) );
			if ( $is_historical ) {
				$detail_url = add_query_arg( array( 'page' => self::CREDENTIALS_PAGE, 'legacy_id' => absint( $item['legacy_id'] ?? 0 ) ), admin_url( 'admin.php' ) );
				$public_url = class_exists( 'Ecapla_CertVerify_Public_Router' ) && '' !== $number ? \Ecapla_CertVerify_Public_Router::profile_url( $number ) : '';
				$status_html = $this->historical_status_badge( (string) ( $item['legacy_status_code'] ?? '' ) );
			} else {
				$detail_url  = add_query_arg( array( 'page' => self::CREDENTIALS_PAGE, 'series_id' => absint( $item['series_id'] ?? 0 ) ), admin_url( 'admin.php' ) );
				$public_url  = $public_ready ? $url_builder->verification_url( (string) ( $item['public_id'] ?? '' ) ) : null;
				$status_html = $this->credential_status_badge( $this->status_from_row( $status_service, $item ) );
			}
			echo '<article class="certloom-premium-list-row" role="listitem"><div class="certloom-premium-list-main"><span class="certloom-eyebrow">' . esc_html( '' !== $number ? $number : __( 'Credential', 'certloom' ) ) . '</span><div class="certloom-credential-source-line"><strong>' . esc_html( '' !== $recipient ? $recipient : __( 'Unnamed recipient', 'certloom' ) ) . '</strong><span class="certloom-credential-source is-' . esc_attr( $is_historical ? 'historical' : 'current' ) . '">' . esc_html( $is_historical ? __( 'Historical', 'certloom' ) : __( 'Current', 'certloom' ) ) . '</span></div><span>' . esc_html( '' !== $program ? $program : ( $is_historical ? __( 'Imported / earlier Certloom record', 'certloom' ) : __( 'No Program', 'certloom' ) ) ) . '</span></div>';
			echo '<div class="certloom-premium-list-status">' . $status_html . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Status helper returns fixed escaped markup.
			echo '<div class="certloom-premium-list-meta"><span><small>' . esc_html__( 'Issued', 'certloom' ) . '</small><strong>' . esc_html( ! empty( $item['issued_at'] ) ? (string) $item['issued_at'] : '—' ) . '</strong></span><span><small>' . esc_html__( 'Expiry', 'certloom' ) . '</small><strong>' . esc_html( ! empty( $item['expires_at'] ) ? (string) $item['expires_at'] : __( 'No expiry', 'certloom' ) ) . '</strong></span></div>';
			echo '<div class="certloom-premium-list-actions">';
			if ( is_string( $public_url ) && '' !== $public_url ) { echo '<a class="button button-primary" href="' . esc_url( $public_url ) . '" target="_blank" rel="noopener">' . esc_html__( 'View', 'certloom' ) . ' <span aria-hidden="true">↗</span></a>'; }
			echo '<a class="certloom-premium-row-link" href="' . esc_url( $detail_url ) . '">' . esc_html__( 'Manage', 'certloom' ) . ' <span aria-hidden="true">→</span></a></div></article>';
		}
		echo '</div>';
	}

	/** @return string */
	private function credential_status_badge( $status ) {
		if ( ! $status || ! is_object( $status ) || ! method_exists( $status, 'code' ) ) {
			return '<span class="certloom-credential-status is-unavailable">' . esc_html__( 'Unavailable', 'certloom' ) . '</span>';
		}
		$code = sanitize_key( (string) $status->code() );
		$labels = array(
			'valid'                  => __( 'Valid', 'certloom' ),
			'suspended'              => __( 'Suspended', 'certloom' ),
			'revoked'                => __( 'Revoked', 'certloom' ),
			'expired'                => __( 'Expired', 'certloom' ),
			'not_yet_valid'          => __( 'Not yet valid', 'certloom' ),
			'pending_review'         => __( 'Pending review', 'certloom' ),
			'suspended_noncompliant' => __( 'Non-compliant', 'certloom' ),
			'voided'                 => __( 'Voided', 'certloom' ),
			'not_issued'             => __( 'Not issued', 'certloom' ),
		);
		return '<span class="certloom-credential-status is-' . esc_attr( sanitize_html_class( $code ) ) . '">' . esc_html( $labels[ $code ] ?? str_replace( '_', ' ', ucfirst( $code ) ) ) . '</span>';
	}


	/** Historical registry status rendered with the same fail-safe visual vocabulary. @return string */
	private function historical_status_badge( $code ) {
		$code = sanitize_key( (string) $code );
		$labels = array(
			'valid'     => __( 'Valid', 'certloom' ),
			'suspended' => __( 'Suspended', 'certloom' ),
			'revoked'   => __( 'Revoked', 'certloom' ),
			'expired'   => __( 'Expired', 'certloom' ),
		);
		if ( ! isset( $labels[ $code ] ) ) { $code = 'unavailable'; }
		return '<span class="certloom-credential-status is-' . esc_attr( sanitize_html_class( $code ) ) . '">' . esc_html( $labels[ $code ] ?? __( 'Unavailable', 'certloom' ) ) . '</span>';
	}

	/** @return void */
	private function credential_tabs( $series_id, $current, $selected_version_id, $current_version_id, $simple = false ) {
		$tabs = $simple ? array(
			'summary'    => __( 'Summary', 'certloom' ),
			'credential' => __( 'Credential', 'certloom' ),
			'history'    => __( 'History', 'certloom' ),
			'actions'    => __( 'Actions', 'certloom' ),
		) : array(
			'overview'     => __( 'Overview', 'certloom' ),
			'recipient'    => __( 'Recipient', 'certloom' ),
			'program'      => __( 'Program', 'certloom' ),
			'status'       => __( 'Status', 'certloom' ),
			'verification' => __( 'Verification', 'certloom' ),
			'versions'     => __( 'Versions', 'certloom' ),
			'activity'     => __( 'Activity', 'certloom' ),
			'actions'      => __( 'Actions', 'certloom' ),
		);
		$classes = $simple ? 'certloom-credential-tabs certloom-credential-tabs-simple' : 'nav-tab-wrapper certloom-credential-tabs';
		echo '<nav class="' . esc_attr( $classes ) . '" aria-label="' . esc_attr__( 'Credential workspace', 'certloom' ) . '">';
		foreach ( $tabs as $key => $label ) {
			$args = array( 'page' => self::CREDENTIALS_PAGE, 'series_id' => $series_id, 'credential_tab' => $key );
			if ( $simple && 'credential' === $key ) { $args['credential_section'] = 'recipient'; }
			if ( $simple && 'history' === $key ) { $args['credential_section'] = 'versions'; }
			if ( $selected_version_id && $selected_version_id !== $current_version_id ) { $args['version_id'] = $selected_version_id; }
			if ( $simple ) {
				echo '<a class="certloom-credential-primary-tab ' . ( $key === $current ? 'is-current' : '' ) . '" ' . ( $key === $current ? 'aria-current="page" ' : '' ) . 'href="' . esc_url( add_query_arg( $args, admin_url( 'admin.php' ) ) ) . '">' . esc_html( $label ) . '</a>';
			} else {
				echo '<a class="nav-tab ' . ( $key === $current ? 'nav-tab-active' : '' ) . '" href="' . esc_url( add_query_arg( $args, admin_url( 'admin.php' ) ) ) . '">' . esc_html( $label ) . '</a>';
			}
		}
		echo '</nav>';
	}

	/** Renders restrained contextual navigation inside grouped Simple-mode workspaces. */
	private function credential_subtabs( $series_id, $primary, $current, $selected_version_id, $current_version_id ) {
		$tabs = 'history' === $primary ? array(
			'versions' => __( 'Versions', 'certloom' ),
			'activity' => __( 'Activity', 'certloom' ),
		) : array(
			'recipient'    => __( 'Recipient', 'certloom' ),
			'program'      => __( 'Program', 'certloom' ),
			'status'       => __( 'Status', 'certloom' ),
			'verification' => __( 'Verification', 'certloom' ),
		);
		echo '<nav class="certloom-credential-subtabs" aria-label="' . esc_attr( 'history' === $primary ? __( 'Credential history sections', 'certloom' ) : __( 'Credential detail sections', 'certloom' ) ) . '">';
		foreach ( $tabs as $key => $label ) {
			$args = array( 'page' => self::CREDENTIALS_PAGE, 'series_id' => $series_id, 'credential_tab' => $primary, 'credential_section' => $key );
			if ( $selected_version_id && $selected_version_id !== $current_version_id ) { $args['version_id'] = $selected_version_id; }
			echo '<a class="' . ( $key === $current ? 'is-current' : '' ) . '" ' . ( $key === $current ? 'aria-current="page" ' : '' ) . 'href="' . esc_url( add_query_arg( $args, admin_url( 'admin.php' ) ) ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';
	}

	/** Maps Simple grouped tabs and old deep links to one existing panel renderer. */
	private function credential_panel_target( $tab, $section, $simple ) {
		$direct = array( 'overview', 'recipient', 'program', 'status', 'verification', 'versions', 'activity', 'actions' );
		if ( ! $simple ) {
			return in_array( $tab, $direct, true ) ? $tab : 'overview';
		}
		if ( in_array( $tab, $direct, true ) ) { return $tab; }
		if ( 'summary' === $tab ) { return 'overview'; }
		if ( 'credential' === $tab ) {
			return in_array( $section, array( 'recipient', 'program', 'status', 'verification' ), true ) ? $section : 'recipient';
		}
		if ( 'history' === $tab ) {
			return in_array( $section, array( 'versions', 'activity' ), true ) ? $section : 'versions';
		}
		return 'overview';
	}

	/** @return array{primary:string,section:string} */
	private function credential_workspace_state( $panel, $simple ) {
		if ( ! $simple ) { return array( 'primary' => $panel, 'section' => '' ); }
		if ( in_array( $panel, array( 'recipient', 'program', 'status', 'verification' ), true ) ) {
			return array( 'primary' => 'credential', 'section' => $panel );
		}
		if ( in_array( $panel, array( 'versions', 'activity' ), true ) ) {
			return array( 'primary' => 'history', 'section' => $panel );
		}
		return array( 'primary' => 'actions' === $panel ? 'actions' : 'summary', 'section' => '' );
	}

	/** @return void */
	private function credential_overview_panel( array $series, ?array $version, $recipient, $program, $batch, $issuer, $status, $is_current ) {
		if ( AdminUiMode::is_simple() ) {
			echo '<section class="certloom-credential-panel"><div class="certloom-credential-panel-head"><div><h3>' . esc_html__( 'At a glance', 'certloom' ) . '</h3><p>' . esc_html__( 'The important information about this credential, without the internal record structure.', 'certloom' ) . '</p></div></div><div class="certloom-credential-card-grid">';
			$this->credential_info_card( __( 'Credential', 'certloom' ), array(
				__( 'Certificate number', 'certloom' ) => esc_html( is_array( $version ) ? (string) $version['display_number'] : '—' ),
				__( 'Issued', 'certloom' ) => esc_html( is_array( $version ) && ! empty( $version['issued_at'] ) ? (string) $version['issued_at'] : '—' ),
				__( 'Expires', 'certloom' ) => esc_html( is_array( $version ) && ! empty( $version['expires_at'] ) ? (string) $version['expires_at'] : __( 'No expiry', 'certloom' ) ),
				__( 'Status', 'certloom' ) => $this->credential_status_badge( $status ),
			) );
			$this->credential_info_card( __( 'Recipient & Program', 'certloom' ), array(
				__( 'Recipient', 'certloom' ) => esc_html( is_array( $recipient ) ? (string) $recipient['display_name'] : '—' ),
				__( 'Program', 'certloom' ) => esc_html( is_array( $program ) ? (string) $program['title'] : '—' ),
				__( 'Batch', 'certloom' ) => esc_html( is_array( $batch ) ? (string) $batch['name'] : '—' ),
				__( 'Issuer', 'certloom' ) => esc_html( is_array( $issuer ) ? (string) $issuer['name'] : '—' ),
			) );
			echo '</div></section>';
			return;
		}
		echo '<section class="certloom-credential-panel"><div class="certloom-credential-panel-head"><div><h3>' . esc_html__( 'Credential overview', 'certloom' ) . '</h3><p>' . esc_html__( 'Continuing Series identity and immutable Version facts are displayed separately so current relationships never masquerade as historical snapshot truth.', 'certloom' ) . '</p></div></div>';
		echo '<div class="certloom-credential-card-grid">';
		$this->credential_info_card( __( 'Continuing identity', 'certloom' ), array(
			__( 'Public ID', 'certloom' ) => '<code>' . esc_html( (string) $series['public_id'] ) . '</code>',
			__( 'Series ID', 'certloom' ) => '#' . esc_html( (string) absint( $series['id'] ) ),
			__( 'Visibility', 'certloom' ) => esc_html( (string) $series['visibility'] ),
			__( 'Last Series change', 'certloom' ) => esc_html( (string) $series['updated_at'] ),
		) );
		$this->credential_info_card( __( 'Selected immutable Version', 'certloom' ), array(
			__( 'Version', 'certloom' ) => is_array( $version ) ? 'v' . esc_html( (string) absint( $version['version_number'] ?? 0 ) ) . ( $is_current ? ' · ' . esc_html__( 'Current', 'certloom' ) : ' · ' . esc_html__( 'Historical', 'certloom' ) ) : '—',
			__( 'Certificate number', 'certloom' ) => esc_html( is_array( $version ) ? (string) $version['display_number'] : '—' ),
			__( 'Issued', 'certloom' ) => esc_html( is_array( $version ) && ! empty( $version['issued_at'] ) ? (string) $version['issued_at'] : '—' ),
			__( 'Expires', 'certloom' ) => esc_html( is_array( $version ) && ! empty( $version['expires_at'] ) ? (string) $version['expires_at'] : __( 'No expiry', 'certloom' ) ),
		) );
		$this->credential_info_card( __( 'Current relationships', 'certloom' ), array(
			__( 'Recipient', 'certloom' ) => esc_html( is_array( $recipient ) ? (string) $recipient['display_name'] : '—' ),
			__( 'Program', 'certloom' ) => esc_html( is_array( $program ) ? (string) $program['title'] : '—' ),
			__( 'Batch', 'certloom' ) => esc_html( is_array( $batch ) ? (string) $batch['name'] : '—' ),
			__( 'Issuer', 'certloom' ) => esc_html( is_array( $issuer ) ? (string) $issuer['name'] : '—' ),
		) );
		$this->credential_info_card( __( 'Effective status', 'certloom' ), array(
			__( 'Public result', 'certloom' ) => $this->credential_status_badge( $status ),
			__( 'Issuance', 'certloom' ) => esc_html( $status ? (string) $status->issuance_state() : '—' ),
			__( 'Administrative', 'certloom' ) => esc_html( $status ? (string) $status->administrative_state() : '—' ),
			__( 'Temporal', 'certloom' ) => esc_html( $status ? (string) $status->temporal_state() : '—' ),
		) );
		echo '</div>';
		$this->credential_snapshot_proof( $version );
		echo '</section>';
	}

	/** @return void */
	private function credential_recipient_panel( $recipient ) {
		$simple = AdminUiMode::is_simple();
		echo '<section class="certloom-credential-panel"><div class="certloom-credential-panel-head"><div><h3>' . esc_html__( 'Recipient', 'certloom' ) . '</h3><p>' . esc_html( $simple ? __( 'This is the person or organization that owns this credential. One Recipient can receive more credentials over time.', 'certloom' ) : __( 'This is the current first-class Recipient linked to the continuing Credential Series. Issued Version snapshots remain immutable.', 'certloom' ) ) . '</p></div></div>';
		if ( ! is_array( $recipient ) ) { $this->error( __( 'The current Recipient could not be loaded.', 'certloom' ) ); echo '</section>'; return; }
		$url = add_query_arg( array( 'page' => self::RECIPIENTS_PAGE, 'recipient_id' => absint( $recipient['id'] ) ), admin_url( 'admin.php' ) );
		$identity = array(
			__( 'Display name', 'certloom' ) => esc_html( (string) $recipient['display_name'] ),
			__( 'Email', 'certloom' ) => esc_html( (string) ( $recipient['email'] ?: '—' ) ),
			__( 'WordPress account', 'certloom' ) => esc_html( $this->wp_user_label( $recipient['wp_user_id'] ?? null ) ),
		);
		if ( ! $simple ) { $identity[ __( 'External ID', 'certloom' ) ] = esc_html( (string) ( $recipient['external_id'] ?: '—' ) ); }
		$this->credential_info_card( $simple ? __( 'Recipient profile', 'certloom' ) : __( 'Recipient identity', 'certloom' ), $identity );
		echo '<p class="certloom-credential-panel-actions"><a class="button" href="' . esc_url( $url ) . '">' . esc_html__( 'Open Recipient profile', 'certloom' ) . '</a></p></section>';
	}

	/** @return void */
	private function credential_program_panel( array $series, $program, $batch, $issuer, $review, $is_current ) {
		if ( AdminUiMode::is_simple() ) {
			echo '<section class="certloom-credential-panel"><div class="certloom-credential-panel-head"><div><h3>' . esc_html__( 'Program', 'certloom' ) . '</h3><p>' . esc_html__( 'The Program, cohort and issuer connected to this credential.', 'certloom' ) . '</p></div></div>';
			$this->credential_info_card( __( 'Credential setup', 'certloom' ), array(
				__( 'Program', 'certloom' ) => esc_html( is_array( $program ) ? (string) $program['title'] : '—' ),
				__( 'Batch', 'certloom' ) => esc_html( is_array( $batch ) ? (string) $batch['name'] : '—' ),
				__( 'Issuer', 'certloom' ) => esc_html( is_array( $issuer ) ? (string) $issuer['name'] : '—' ),
			) );
			if ( is_array( $program ) ) { $program_url = add_query_arg( array( 'page' => self::PROGRAMS_PAGE, 'program_id' => absint( $program['id'] ?? 0 ) ), admin_url( 'admin.php' ) ); echo '<p class="certloom-credential-panel-actions"><a class="button" href="' . esc_url( $program_url ) . '">' . esc_html__( 'Open Program', 'certloom' ) . '</a></p>'; }
			echo '</section>';
			return;
		}
		echo '<section class="certloom-credential-panel"><div class="certloom-credential-panel-head"><div><h3>' . esc_html__( 'Program & batch relationship', 'certloom' ) . '</h3><p>' . esc_html__( 'A correction creates a successor immutable Version, moves the continuing Series relationship atomically, and appends an audit Event. No historical Version is edited.', 'certloom' ) . '</p></div></div>';
		$this->credential_info_card( __( 'Current Series relationship', 'certloom' ), array(
			__( 'Program', 'certloom' ) => esc_html( is_array( $program ) ? (string) $program['title'] : '—' ),
			__( 'Batch', 'certloom' ) => esc_html( is_array( $batch ) ? (string) $batch['name'] . ( ! empty( $batch['code'] ) ? ' (' . (string) $batch['code'] . ')' : '' ) : '—' ),
			__( 'Issuer', 'certloom' ) => esc_html( is_array( $issuer ) ? (string) $issuer['name'] : '—' ),
			__( 'Series updated', 'certloom' ) => esc_html( (string) $series['updated_at'] ),
		) );
		if ( ! current_user_can( \Ecapla_CertVerify_Permissions::CAP_MANAGE_CREDENTIALS ) ) { echo '</section>'; return; }
		if ( ! $is_current ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Relationship correction is disabled while inspecting a historical Version. Return to the current Version first.', 'certloom' ) . '</p></div></section>';
			return;
		}
		if ( is_wp_error( $review ) ) { $this->error( $review->get_error_message() ); $review = null; }
		if ( is_array( $review ) ) {
			$target_program_id = absint( $review['target']['program_id'] ?? 0 );
			$target_batch_id = absint( $review['target']['batch_id'] ?? 0 );
			$target_program = $target_program_id ? ( new ProgramRepository() )->find( $target_program_id ) : null;
			$target_batch = $target_batch_id ? ( new BatchRepository() )->find( $target_batch_id ) : null;
			echo '<div class="certloom-credential-review"><div class="certloom-credential-review-badge">' . esc_html__( 'Review required', 'certloom' ) . '</div><h4>' . esc_html__( 'Confirm immutable relationship correction', 'certloom' ) . '</h4><p>' . esc_html__( 'Certloom will create a successor Version and preserve the current Version unchanged.', 'certloom' ) . '</p>';
			echo '<dl><div><dt>' . esc_html__( 'Program', 'certloom' ) . '</dt><dd>' . esc_html( is_array( $target_program ) ? (string) $target_program['title'] : '—' ) . '</dd></div><div><dt>' . esc_html__( 'Batch', 'certloom' ) . '</dt><dd>' . esc_html( is_array( $target_batch ) ? (string) $target_batch['name'] : '—' ) . '</dd></div><div><dt>' . esc_html__( 'Public reason', 'certloom' ) . '</dt><dd>' . esc_html( (string) ( $review['public_reason'] ?? '—' ) ) . '</dd></div><div><dt>' . esc_html__( 'Internal note', 'certloom' ) . '</dt><dd>' . esc_html( (string) ( $review['internal_note'] ?? '—' ) ) . '</dd></div></dl>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-certloom-preserve-on-error><input type="hidden" name="action" value="certloom_domain_relationship_correct"><input type="hidden" name="series_id" value="' . esc_attr( (string) absint( $series['id'] ) ) . '"><input type="hidden" name="relationship_review" value="' . esc_attr( $this->encode_review_value( $review ) ) . '">';
			wp_nonce_field( 'certloom_domain_relationship_' . absint( $series['id'] ) );
			submit_button( __( 'Confirm and create successor Version', 'certloom' ), 'primary', 'submit', false );
			echo ' <a class="button" href="' . esc_url( add_query_arg( array( 'page' => self::CREDENTIALS_PAGE, 'series_id' => absint( $series['id'] ), 'credential_tab' => 'program' ), admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'Cancel', 'certloom' ) . '</a></form></div>';
			echo '</section>'; return;
		}

		$current_program_id = absint( $series['program_id'] ?? 0 );
		$current_batch_id = absint( $series['batch_id'] ?? 0 );
		$choices = new ScopedContextChoices();
		$programs = $choices->programs( $current_program_id );
		$batches = $choices->batches( $current_program_id, $current_batch_id );
		$context_nonce = wp_create_nonce( ScopedFieldAdminAjax::NONCE_ACTION );
		echo '<div class="certloom-credential-subpanel"><h4>' . esc_html__( 'Correct Program / Batch', 'certloom' ) . '</h4><form method="post" action="' . esc_url( add_query_arg( array( 'page' => self::CREDENTIALS_PAGE, 'series_id' => absint( $series['id'] ), 'credential_tab' => 'program' ), admin_url( 'admin.php' ) ) ) . '">';
		wp_nonce_field( 'certloom_domain_relationship_review_' . absint( $series['id'] ) );
		echo '<input type="hidden" name="certloom_prepare_relationship" value="1"><div class="certloom-domain-form-grid"><div class="certloom-domain-field"><label><strong>' . esc_html__( 'Target Program', 'certloom' ) . '</strong></label><select name="program_id" required data-certloom-context-lookup="program" data-certloom-context-nonce="' . esc_attr( $context_nonce ) . '" data-certloom-context-search-placeholder="' . esc_attr__( 'Search active Programs…', 'certloom' ) . '"><option value="">' . esc_html__( 'Choose Program…', 'certloom' ) . '</option>';
		if ( ! is_wp_error( $programs ) ) { foreach ( $programs as $item ) { echo '<option value="' . esc_attr( (string) absint( $item['id'] ?? 0 ) ) . '" ' . selected( $current_program_id, absint( $item['id'] ?? 0 ), false ) . '>' . esc_html( ScopedContextChoices::program_label( $item ) ) . '</option>'; } }
		echo '</select></div><div class="certloom-domain-field"><label><strong>' . esc_html__( 'Target Batch', 'certloom' ) . '</strong></label><select name="batch_id" data-certloom-context-lookup="batch" data-certloom-context-nonce="' . esc_attr( $context_nonce ) . '" data-certloom-context-search-placeholder="' . esc_attr__( 'Search Batches in this Program…', 'certloom' ) . '"><option value="0">' . esc_html__( 'No batch', 'certloom' ) . '</option>';
		if ( ! is_wp_error( $batches ) ) { foreach ( $batches as $item ) { echo '<option value="' . esc_attr( (string) absint( $item['id'] ?? 0 ) ) . '" data-program-id="' . esc_attr( (string) absint( $item['program_id'] ?? 0 ) ) . '" ' . selected( $current_batch_id, absint( $item['id'] ?? 0 ), false ) . '>' . esc_html( ScopedContextChoices::batch_label( $item ) ) . '</option>'; } }
		echo '</select><p class="description">' . esc_html__( 'Search is constrained to the selected Program. The domain service validates the Program/Batch relationship again inside the immutable correction transaction.', 'certloom' ) . '</p></div><div class="certloom-domain-field certloom-domain-field-wide"><label><strong>' . esc_html__( 'Public correction reason (optional)', 'certloom' ) . '</strong></label><textarea class="large-text" rows="2" name="public_reason"></textarea></div><div class="certloom-domain-field certloom-domain-field-wide"><label><strong>' . esc_html__( 'Internal note (optional)', 'certloom' ) . '</strong></label><textarea class="large-text" rows="3" name="internal_note"></textarea></div></div>';
		submit_button( __( 'Review correction', 'certloom' ), 'secondary' );
		echo '</form></div></section>';
	}

	/** @return void */
	private function credential_status_panel( array $series, ?array $version, $status ) {
		$simple = AdminUiMode::is_simple();
		if ( $simple ) {
			echo '<section class="certloom-credential-panel"><div class="certloom-credential-panel-head"><div><h3>' . esc_html__( 'Credential status', 'certloom' ) . '</h3><p>' . esc_html__( 'See whether this credential is currently valid and the dates that affect it.', 'certloom' ) . '</p></div></div>';
			if ( ! $status ) { $this->error( __( 'Certloom could not determine the current status of this credential.', 'certloom' ) ); echo '</section>'; return; }
			$this->credential_info_card( __( 'Current status', 'certloom' ), array(
				__( 'Status', 'certloom' ) => $this->credential_status_badge( $status ),
				__( 'Valid now', 'certloom' ) => esc_html( $status->is_valid() ? __( 'Yes', 'certloom' ) : __( 'No', 'certloom' ) ),
				__( 'Valid from', 'certloom' ) => esc_html( is_array( $version ) && ! empty( $version['valid_from'] ) ? (string) $version['valid_from'] : __( 'No start restriction', 'certloom' ) ),
				__( 'Expires', 'certloom' ) => esc_html( is_array( $version ) && ! empty( $version['expires_at'] ) ? (string) $version['expires_at'] : __( 'No expiry', 'certloom' ) ),
			) );
			echo '<p class="description">' . esc_html__( 'For suspensions, revocations or other status changes, use Credential actions.', 'certloom' ) . '</p></section>';
			return;
		}
		echo '<section class="certloom-credential-panel"><div class="certloom-credential-panel-head"><div><h3>' . esc_html__( 'Four-dimensional credential status', 'certloom' ) . '</h3><p>' . esc_html__( 'The public result is derived at read time from issuance, administrative, temporal, and compliance state rather than trusting one mutable status string.', 'certloom' ) . '</p></div></div>';
		if ( ! $status ) { $this->error( __( 'Status could not be evaluated for this Version.', 'certloom' ) ); echo '</section>'; return; }
		$this->credential_info_card( __( 'Effective truth', 'certloom' ), array(
			__( 'Public result', 'certloom' ) => $this->credential_status_badge( $status ),
			__( 'Primary reason', 'certloom' ) => '<code>' . esc_html( (string) $status->reason_code() ) . '</code>',
			__( 'Publicly valid now', 'certloom' ) => esc_html( $status->is_valid() ? __( 'Yes', 'certloom' ) : __( 'No', 'certloom' ) ),
			__( 'Contributors', 'certloom' ) => esc_html( $status->contributors() ? implode( ', ', $status->contributors() ) : __( 'None', 'certloom' ) ),
		) );
		echo '<div class="certloom-credential-dimensions">';
		foreach ( array(
			__( 'Issuance', 'certloom' ) => $status->issuance_state(),
			__( 'Administrative', 'certloom' ) => $status->administrative_state(),
			__( 'Temporal', 'certloom' ) => $status->temporal_state(),
			__( 'Compliance', 'certloom' ) => $status->compliance_state(),
		) as $label => $value ) { echo '<div><span>' . esc_html( $label ) . '</span><strong>' . esc_html( (string) $value ) . '</strong></div>'; }
		echo '</div>';
		if ( is_array( $version ) ) {
			echo '<p class="description">' . esc_html( sprintf( /* translators: 1: valid from, 2: expiry. */ __( 'Validity window: %1$s → %2$s', 'certloom' ), (string) ( $version['valid_from'] ?: __( 'No start restriction', 'certloom' ) ), (string) ( $version['expires_at'] ?: __( 'No expiry', 'certloom' ) ) ) ) . '</p>';
		}
		echo '</section>';
	}

	/** @return void */
	private function credential_verification_panel( array $series, ?array $version ) {
		if ( AdminUiMode::is_simple() ) {
			$ready = ( new \Certloom\Infrastructure\Verification\PublicVerificationGate() )->is_ready();
			$url = ( new \Certloom\Infrastructure\Issuance\NormalizedPublicCredentialUrl() )->verification_url( (string) $series['public_id'] );
			echo '<section class="certloom-credential-panel"><div class="certloom-credential-panel-head"><div><h3>' . esc_html__( 'View & verify', 'certloom' ) . '</h3><p>' . esc_html__( 'Open the public credential page that recipients and verifiers can use.', 'certloom' ) . '</p></div></div>';
			$this->credential_info_card( __( 'Public credential', 'certloom' ), array( __( 'Certificate number', 'certloom' ) => esc_html( is_array( $version ) ? (string) $version['display_number'] : '—' ), __( 'Online verification', 'certloom' ) => '<strong>' . esc_html( $ready ? __( 'Available', 'certloom' ) : __( 'Not available yet', 'certloom' ) ) . '</strong>' ) );
			if ( $ready && ! is_wp_error( $url ) ) { echo '<p class="certloom-credential-panel-actions"><a class="button button-primary" href="' . esc_url( (string) $url ) . '" target="_blank" rel="noopener">' . esc_html__( 'View public credential', 'certloom' ) . ' <span aria-hidden="true">↗</span></a></p>'; }
			else { echo '<div class="certloom-context-note"><span class="dashicons dashicons-info-outline" aria-hidden="true"></span><p>' . esc_html__( 'Online verification is not available for this credential yet. You can still manage and deliver the credential from Certloom.', 'certloom' ) . '</p></div>'; }
			echo '</section>';
			return;
		}
		echo '<section class="certloom-credential-panel"><div class="certloom-credential-panel-head"><div><h3>' . esc_html__( 'Verification identity', 'certloom' ) . '</h3><p>' . esc_html__( 'The non-enumerable public credential ID is separate from the human certificate number. Production normalized verification remains gated until final database cutover acceptance.', 'certloom' ) . '</p></div></div>';
		$url = ( new \Certloom\Infrastructure\Issuance\NormalizedPublicCredentialUrl() )->verification_url( (string) $series['public_id'] );
		$this->credential_info_card( __( 'Self-hosted verification', 'certloom' ), array(
			__( 'Public credential ID', 'certloom' ) => '<code>' . esc_html( (string) $series['public_id'] ) . '</code>',
			__( 'Certificate number', 'certloom' ) => esc_html( is_array( $version ) ? (string) $version['display_number'] : '—' ),
			__( 'Normalized endpoint', 'certloom' ) => is_wp_error( $url ) ? esc_html( $url->get_error_message() ) : '<code>' . esc_html( (string) $url ) . '</code>',
			__( 'Production authority', 'certloom' ) => '<strong>' . esc_html__( 'Gated', 'certloom' ) . '</strong>',
		) );
		echo '<div class="notice notice-warning inline"><p><strong>' . esc_html__( 'Authority remains closed.', 'certloom' ) . '</strong> ' . esc_html__( 'Credential Management may inspect normalized verification identity, but it does not enable the normalized public verifier or create renderer authority.', 'certloom' ) . '</p></div></section>';
	}

	/** @param array<int,array<string,mixed>> $versions @return void */
	private function credential_versions_panel( $series_id, array $versions, $current_version_id, $selected_version_id ) {
		if ( AdminUiMode::is_simple() ) {
			echo '<section class="certloom-credential-panel"><div class="certloom-credential-panel-head"><div><h3>' . esc_html__( 'Credential history', 'certloom' ) . '</h3><p>' . esc_html__( 'Earlier issued records stay available when a credential is corrected, renewed or re-certified.', 'certloom' ) . '</p></div></div><div class="certloom-clean-list">';
			foreach ( $versions as $item ) {
				$id = absint( $item['id'] ?? 0 ); $is_current = $id === absint( $current_version_id ); $is_selected = $id === absint( $selected_version_id );
				$url = add_query_arg( array( 'page' => self::CREDENTIALS_PAGE, 'series_id' => $series_id, 'version_id' => $id, 'credential_tab' => 'summary' ), admin_url( 'admin.php' ) );
				echo '<article class="certloom-clean-list-row ' . ( $is_selected ? 'is-selected-version' : '' ) . '"><div class="certloom-clean-list-main"><div class="certloom-clean-list-title"><strong>' . esc_html( (string) ( $item['display_number'] ?: __( 'Credential record', 'certloom' ) ) ) . '</strong>' . ( $is_current ? '<span class="certloom-state-pill is-success">' . esc_html__( 'Current', 'certloom' ) . '</span>' : '' ) . '</div><p>' . esc_html( sprintf( /* translators: 1: issue date, 2: expiry date. */ __( 'Issued %1$s · Expires %2$s', 'certloom' ), (string) ( $item['issued_at'] ?: '—' ), (string) ( $item['expires_at'] ?: __( 'Never', 'certloom' ) ) ) ) . '</p></div><a class="button button-small" href="' . esc_url( $url ) . '">' . esc_html__( 'View record', 'certloom' ) . '</a></article>';
			}
			if ( empty( $versions ) ) { echo '<div class="certloom-premium-empty-state certloom-empty-compact"><h3>' . esc_html__( 'No credential history yet', 'certloom' ) . '</h3></div>'; }
			echo '</div></section>';
			return;
		}
		echo '<section class="certloom-credential-panel"><div class="certloom-credential-panel-head"><div><h3>' . esc_html__( 'Immutable Version lineage', 'certloom' ) . '</h3><p>' . esc_html__( 'Corrections, renewals, and re-certifications must create successor Versions. Historical rows are inspection-only.', 'certloom' ) . '</p></div></div><div class="certloom-domain-table-wrap"><table class="widefat striped certloom-domain-table"><thead><tr><th>' . esc_html__( 'Version', 'certloom' ) . '</th><th>' . esc_html__( 'Certificate number', 'certloom' ) . '</th><th>' . esc_html__( 'Issued', 'certloom' ) . '</th><th>' . esc_html__( 'Expiry', 'certloom' ) . '</th><th>' . esc_html__( 'Source', 'certloom' ) . '</th><th>' . esc_html__( 'Lineage', 'certloom' ) . '</th><th>' . esc_html__( 'Inspect', 'certloom' ) . '</th></tr></thead><tbody>';
		foreach ( $versions as $item ) {
			$id = absint( $item['id'] ?? 0 );
			$is_current = $id === absint( $current_version_id );
			$is_selected = $id === absint( $selected_version_id );
			$url = add_query_arg( array( 'page' => self::CREDENTIALS_PAGE, 'series_id' => $series_id, 'version_id' => $id, 'credential_tab' => 'overview' ), admin_url( 'admin.php' ) );
			echo '<tr class="' . ( $is_selected ? 'is-selected-version' : '' ) . '"><td><strong>v' . esc_html( (string) absint( $item['version_number'] ?? 0 ) ) . '</strong>' . ( $is_current ? ' <span class="certloom-version-current">' . esc_html__( 'Current', 'certloom' ) . '</span>' : '' ) . '</td><td>' . esc_html( (string) $item['display_number'] ) . '</td><td>' . esc_html( (string) ( $item['issued_at'] ?: '—' ) ) . '</td><td>' . esc_html( (string) ( $item['expires_at'] ?: '—' ) ) . '</td><td><code>' . esc_html( (string) ( $item['source_type'] ?: '—' ) ) . '</code></td><td>' . esc_html( ! empty( $item['previous_version_id'] ) ? sprintf( /* translators: %d: Version database ID. */ __( 'Previous #%d', 'certloom' ), absint( $item['previous_version_id'] ) ) : __( 'Origin', 'certloom' ) ) . '</td><td><a class="button button-small" href="' . esc_url( $url ) . '">' . esc_html__( 'Inspect Version', 'certloom' ) . '</a></td></tr>';
		}
		if ( empty( $versions ) ) { echo '<tr><td colspan="7">' . esc_html__( 'No credential Versions recorded.', 'certloom' ) . '</td></tr>'; }
		echo '</tbody></table></div></section>';
	}

	/** @return void */
	private function credential_actions_panel( array $series, ?array $version, $review, $is_current ) {
		$simple = AdminUiMode::is_simple();
		echo '<section class="certloom-credential-panel certloom-credential-actions-panel"><div class="certloom-credential-panel-head"><div><h3>' . esc_html( $simple ? __( 'Credential actions', 'certloom' ) : __( 'Reviewed lifecycle actions', 'certloom' ) ) . '</h3><p>' . esc_html( $simple ? __( 'Deliver this credential or review a status change before applying it.', 'certloom' ) : __( 'Every state-changing action is reviewed first, integrity-bound to this administrator and exact current Version, then revalidated after transactional row locks before commit.', 'certloom' ) ) . '</p></div></div>';
		if ( ! current_user_can( \Ecapla_CertVerify_Permissions::CAP_MANAGE_CREDENTIALS ) ) { echo '<p>' . esc_html( $simple ? __( 'You can view this credential but do not have permission to change its status.', 'certloom' ) : __( 'You can inspect this credential but do not have permission to change lifecycle state.', 'certloom' ) ) . '</p></section>'; return; }
		if ( ! $is_current || ! is_array( $version ) ) { echo '<div class="notice notice-info inline"><p>' . esc_html( $simple ? __( 'Earlier credential records are read-only. Return to the current credential before changing its status.', 'certloom' ) : __( 'Historical Versions are read-only. Return to the current Version to review a lifecycle action.', 'certloom' ) ) . '</p></div></section>'; return; }
		if ( States::ISSUANCE_ISSUED === (string) ( $version['issuance_state'] ?? '' ) ) {
			$email_url = add_query_arg( array( 'page' => 'certloom-email-delivery', 'version_id' => absint( $version['id'] ?? 0 ) ), admin_url( 'admin.php' ) );
			echo '<div class="certloom-credential-subpanel certloom-credential-email-action"><h4>' . esc_html__( 'Email credential', 'certloom' ) . '</h4><p>' . esc_html( $simple ? __( 'Choose one of your reusable messages, preview it, then send it to the Recipient.', 'certloom' ) : __( 'Preview an email composed from this exact immutable Version, then explicitly queue it through WordPress mail. Sending does not change credential truth.', 'certloom' ) ) . '</p><p class="certloom-credential-panel-actions"><a class="button button-primary" href="' . esc_url( $email_url ) . '">' . esc_html__( 'Preview credential email', 'certloom' ) . '</a></p></div>';
		}
		if ( is_wp_error( $review ) ) { $this->error( $review->get_error_message() ); $review = null; }
		if ( is_array( $review ) ) {
			if ( $simple ) {
				echo '<div class="certloom-credential-review"><div class="certloom-credential-review-badge">' . esc_html__( 'Review first', 'certloom' ) . '</div><h4>' . esc_html__( 'Confirm credential status change', 'certloom' ) . '</h4><dl><div><dt>' . esc_html__( 'Action', 'certloom' ) . '</dt><dd>' . esc_html( ucfirst( (string) $review['action'] ) ) . '</dd></div><div><dt>' . esc_html__( 'Reason', 'certloom' ) . '</dt><dd>' . esc_html( (string) ( $review['public_reason'] ?: $review['reason_code'] ?: '—' ) ) . '</dd></div></dl>';
				echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Certloom will confirm that the credential has not changed since you opened this review.', 'certloom' ) . '</p></div><form';
			} else {
				echo '<div class="certloom-credential-review"><div class="certloom-credential-review-badge">' . esc_html__( 'Review required', 'certloom' ) . '</div><h4>' . esc_html__( 'Confirm lifecycle change', 'certloom' ) . '</h4><dl><div><dt>' . esc_html__( 'Action', 'certloom' ) . '</dt><dd>' . esc_html( ucfirst( (string) $review['action'] ) ) . '</dd></div><div><dt>' . esc_html__( 'Before', 'certloom' ) . '</dt><dd><code>' . esc_html( (string) $review['old_state']['issuance_state'] . ' / ' . (string) $review['old_state']['administrative_state'] ) . '</code></dd></div><div><dt>' . esc_html__( 'After', 'certloom' ) . '</dt><dd><code>' . esc_html( (string) $review['new_state']['issuance_state'] . ' / ' . (string) $review['new_state']['administrative_state'] ) . '</code></dd></div><div><dt>' . esc_html__( 'Reason', 'certloom' ) . '</dt><dd>' . esc_html( (string) ( $review['public_reason'] ?: $review['reason_code'] ?: '—' ) ) . '</dd></div></dl>';
				echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Certloom will reject this confirmation if the Series or current Version changed after this review was prepared.', 'certloom' ) . '</p></div><form';
			}
			echo ' method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-certloom-preserve-on-error><input type="hidden" name="action" value="certloom_domain_lifecycle"><input type="hidden" name="series_id" value="' . esc_attr( (string) absint( $series['id'] ) ) . '"><input type="hidden" name="lifecycle_review" value="' . esc_attr( $this->encode_review_value( $review ) ) . '">';
			wp_nonce_field( 'certloom_domain_lifecycle_' . absint( $series['id'] ) );
			submit_button( $simple ? __( 'Confirm status change', 'certloom' ) : __( 'Confirm lifecycle action', 'certloom' ), 'primary', 'submit', false );
			echo ' <a class="button" href="' . esc_url( add_query_arg( array( 'page' => self::CREDENTIALS_PAGE, 'series_id' => absint( $series['id'] ), 'credential_tab' => 'actions' ), admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'Cancel', 'certloom' ) . '</a></form></div></section>';
			return;
		}
		$actions = array();
		if ( States::ISSUANCE_ISSUED === (string) $version['issuance_state'] ) {
			if ( States::ADMIN_ACTIVE === (string) $series['administrative_state'] ) { $actions['suspend'] = __( 'Suspend', 'certloom' ); $actions['revoke'] = __( 'Revoke', 'certloom' ); }
			if ( States::ADMIN_SUSPENDED === (string) $series['administrative_state'] ) { $actions['reinstate'] = __( 'Reinstate', 'certloom' ); $actions['revoke'] = __( 'Revoke', 'certloom' ); }
			if ( States::ADMIN_REVOKED === (string) $series['administrative_state'] ) { $actions['reinstate'] = __( 'Reinstate', 'certloom' ); }
		}
		if ( States::ISSUANCE_VOIDED !== (string) $version['issuance_state'] ) { $actions['void'] = $simple ? __( 'Void credential', 'certloom' ) : __( 'Void current Version', 'certloom' ); }
		if ( empty( $actions ) ) { echo '<p>' . esc_html( $simple ? __( 'No status change is currently available for this credential.', 'certloom' ) : __( 'No lifecycle action is currently available for this state.', 'certloom' ) ) . '</p></section>'; return; }
		echo '<form method="post" action="' . esc_url( add_query_arg( array( 'page' => self::CREDENTIALS_PAGE, 'series_id' => absint( $series['id'] ), 'credential_tab' => 'actions' ), admin_url( 'admin.php' ) ) ) . '">';
		wp_nonce_field( 'certloom_domain_lifecycle_review_' . absint( $series['id'] ) );
		echo '<input type="hidden" name="certloom_prepare_lifecycle" value="1"><div class="certloom-domain-form-grid"><div class="certloom-domain-field"><label><strong>' . esc_html__( 'Action', 'certloom' ) . '</strong></label><select name="lifecycle_action" required><option value="">' . esc_html__( 'Choose…', 'certloom' ) . '</option>';
		foreach ( $actions as $key => $label ) { echo '<option value="' . esc_attr( $key ) . '">' . esc_html( $label ) . '</option>'; }
		echo '</select></div>';
		if ( $simple ) { echo '<input type="hidden" name="reason_code" value=""><div class="certloom-domain-field certloom-domain-field-wide"><label><strong>' . esc_html__( 'Reason (optional)', 'certloom' ) . '</strong></label><textarea class="large-text" rows="2" maxlength="1000" name="public_reason" placeholder="' . esc_attr__( 'Short explanation shown with the credential when appropriate', 'certloom' ) . '"></textarea></div>'; }
		else { echo '<div class="certloom-domain-field"><label><strong>' . esc_html__( 'Reason code', 'certloom' ) . '</strong></label><input class="regular-text" name="reason_code" maxlength="64" placeholder="' . esc_attr__( 'e.g. issuer_request', 'certloom' ) . '"></div><div class="certloom-domain-field certloom-domain-field-wide"><label><strong>' . esc_html__( 'Public reason (optional)', 'certloom' ) . '</strong></label><textarea class="large-text" rows="2" maxlength="1000" name="public_reason"></textarea></div><div class="certloom-domain-field certloom-domain-field-wide"><label><strong>' . esc_html__( 'Internal note (optional)', 'certloom' ) . '</strong></label><textarea class="large-text" rows="3" maxlength="2000" name="internal_note"></textarea></div>'; }
		echo '</div>';
		submit_button( $simple ? __( 'Review status change', 'certloom' ) : __( 'Review lifecycle action', 'certloom' ), 'secondary' );
		echo '</form></section>';
	}

	/** @param array<string,string> $rows @return void */
	private function credential_info_card( $title, array $rows ) {
		echo '<div class="certloom-credential-info-card"><h4>' . esc_html( $title ) . '</h4><dl>';
		foreach ( $rows as $label => $value ) { echo '<div><dt>' . esc_html( $label ) . '</dt><dd>' . wp_kses_post( $value ) . '</dd></div>'; }
		echo '</dl></div>';
	}

	/** @return void */
	private function credential_snapshot_proof( ?array $version ) {
		if ( ! is_array( $version ) || empty( $version['snapshot'] ) || ! is_array( $version['snapshot'] ) ) { return; }
		$snapshot = $version['snapshot'];
		$template = isset( $snapshot['template'] ) && is_array( $snapshot['template'] ) ? $snapshot['template'] : array();
		$merge = isset( $snapshot['merge'] ) && is_array( $snapshot['merge'] ) ? $snapshot['merge'] : array();
		$artifact = isset( $snapshot['artifact'] ) && is_array( $snapshot['artifact'] ) ? $snapshot['artifact'] : array();
		echo '<div class="certloom-credential-snapshot"><div><h4>' . esc_html__( 'Immutable issuance snapshot evidence', 'certloom' ) . '</h4><p>' . esc_html__( 'These identifiers belong to the selected Version snapshot; changing current Recipient, Program, Template, or renderer configuration does not rewrite them.', 'certloom' ) . '</p></div><dl>';
		foreach ( array(
			__( 'Snapshot schema', 'certloom' ) => (string) ( $snapshot['_meta']['schema'] ?? '—' ),
			__( 'Template revision', 'certloom' ) => ! empty( $template['revision_number'] ) ? 'v' . absint( $template['revision_number'] ) : '—',
			__( 'Template SHA-256', 'certloom' ) => (string) ( $template['layout_sha256'] ?? '—' ),
			__( 'Merge SHA-256', 'certloom' ) => (string) ( $merge['sha256'] ?? '—' ),
			__( 'Artifact authority', 'certloom' ) => (string) ( $artifact['authority'] ?? '—' ),
		) as $label => $value ) { echo '<div><dt>' . esc_html( $label ) . '</dt><dd><code>' . esc_html( $value ) . '</code></dd></div>'; }
		echo '</dl></div>';
	}

	/** @return string */
	private function encode_review_value( array $review ) {
		$json = wp_json_encode( $review, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return false === $json ? '' : base64_encode( $json );
	}

	/** @return array<string,mixed>|\WP_Error */
	private function decode_review_request( $name ) {
		$raw = isset( $_POST[ $name ] ) ? trim( (string) wp_unslash( $_POST[ $name ] ) ) : '';
		if ( '' === $raw || strlen( $raw ) > 65536 || ! preg_match( '/^[A-Za-z0-9+\/=]+$/', $raw ) ) {
			return new \WP_Error( 'certloom_credential_review_missing', __( 'The reviewed credential action is missing or malformed. Review it again.', 'certloom' ) );
		}
		$json = base64_decode( $raw, true );
		$data = false !== $json ? json_decode( $json, true ) : null;
		if ( ! is_array( $data ) || JSON_ERROR_NONE !== json_last_error() ) {
			return new \WP_Error( 'certloom_credential_review_decode_failed', __( 'The reviewed credential action could not be decoded safely. Review it again.', 'certloom' ) );
		}
		return $data;
	}

	/** @param array<string,mixed>|null $editing @return void */
	private function recipient_form( ?array $editing = null ) {
		$is_edit = null !== $editing;
		$id = $is_edit ? absint( $editing['id'] ) : 0;
		$simple = AdminUiMode::is_simple();
		$custom_fields = $is_edit && isset( $editing['custom_fields'] ) && is_array( $editing['custom_fields'] ) ? $editing['custom_fields'] : array();
		$custom_editable = $this->is_scalar_map( $custom_fields );
		echo '<section id="certloom-domain-editor" class="ecapla-certverify-admin-card certloom-domain-editor"><div class="certloom-domain-editor-head"><div><h2>' . esc_html( $is_edit ? __( 'Recipient profile', 'certloom' ) : __( 'Add recipient', 'certloom' ) ) . '</h2><p>' . esc_html__( 'Keep recipient identity separate from credential history so renewals and future credentials share one stable recipient record.', 'certloom' ) . '</p></div>';
		if ( $is_edit && ! $simple ) { echo '<span class="certloom-domain-id">#' . esc_html( (string) $id ) . '</span>'; }
		echo '</div><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-certloom-preserve-on-error data-certloom-entity-form="recipient" data-certloom-saving-message="' . esc_attr( $is_edit ? __( 'Saving recipient…', 'certloom' ) : __( 'Adding recipient…', 'certloom' ) ) . '" data-certloom-invalid-message="' . esc_attr__( 'Please complete the highlighted required field before saving this recipient.', 'certloom' ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( $is_edit ? 'certloom_domain_update_recipient' : 'certloom_domain_create_recipient' ) . '">';
		if ( $is_edit ) { echo '<input type="hidden" name="recipient_id" value="' . esc_attr( $id ) . '">'; wp_nonce_field( 'certloom_domain_update_recipient_' . $id ); } else { wp_nonce_field( 'certloom_domain_create_recipient' ); }
		echo '<div class="certloom-domain-form-grid">';
		$this->text_field( 'display_name', __( 'Display name', 'certloom' ), $is_edit ? (string) $editing['display_name'] : '', true, $simple ? __( 'The name shown in Certloom and on credentials where this Recipient is used.', 'certloom' ) : __( 'The canonical name shown in administrator workflows and credential presentation.', 'certloom' ), 'text', '', __( 'Example: Amina Khan or Northstar Academy', 'certloom' ) );
		$this->text_field( 'email', __( 'Email', 'certloom' ), $is_edit ? (string) $editing['email'] : '', false, __( 'Optional. Used by later issuance and email workflows.', 'certloom' ), 'email' );
		$this->text_field( 'first_name', __( 'First name', 'certloom' ), $is_edit ? (string) $editing['first_name'] : '' );
		$this->text_field( 'middle_name', __( 'Middle name', 'certloom' ), $is_edit ? (string) $editing['middle_name'] : '' );
		$this->text_field( 'last_name', __( 'Last name', 'certloom' ), $is_edit ? (string) $editing['last_name'] : '' );
		$this->text_field( 'external_id', $simple ? __( 'External learner / customer ID', 'certloom' ) : __( 'External ID', 'certloom' ), $is_edit ? (string) $editing['external_id'] : '', false, $simple ? __( 'Optional. Use the same ID supplied by Coursera, a webhook, CRM or another connected system when you want exact identity matching.', 'certloom' ) : __( 'Optional identifier from an LMS, CRM, SIS, or other source.', 'certloom' ) );
		$wp_user_id = $is_edit && ! empty( $editing['wp_user_id'] ) ? absint( $editing['wp_user_id'] ) : 0;
		$wp_user_label = $wp_user_id ? $this->wp_user_label( $wp_user_id ) : '';
		echo '<div class="certloom-domain-field"><label for="certloom-recipient-wp-user"><strong>' . esc_html__( 'WordPress account', 'certloom' ) . '</strong></label><select id="certloom-recipient-wp-user" name="wp_user_id" data-certloom-context-lookup="wp_user" data-certloom-context-nonce="' . esc_attr( wp_create_nonce( ScopedFieldAdminAjax::NONCE_ACTION ) ) . '" data-certloom-context-search-placeholder="' . esc_attr__( 'Search WordPress users…', 'certloom' ) . '"><option value="">' . esc_html__( 'No linked WordPress account', 'certloom' ) . '</option>';
		if ( $wp_user_id ) { echo '<option value="' . esc_attr( (string) $wp_user_id ) . '" selected>' . esc_html( $wp_user_label ) . '</option>'; }
		echo '</select><p class="description">' . esc_html__( 'Optional. Link the account owned by this Recipient to power My Credentials and LMS identity matching. Certloom never guesses ownership from a matching name or email.', 'certloom' ) . '</p></div>';
		echo '</div>';
		if ( ! $simple ) {
			echo '<div class="certloom-domain-subsection"><div class="certloom-domain-subsection-head"><div><h3>' . esc_html__( 'Recipient custom fields', 'certloom' ) . '</h3><p>' . esc_html__( 'Structured recipient attributes remain local to WordPress and can later feed merge fields and integrations.', 'certloom' ) . '</p></div></div>';
			if ( $custom_editable ) {
				echo '<input type="hidden" name="recipient_custom_fields_present" value="1"><div class="certloom-domain-kv-list" data-certloom-kv-list="recipient">';
				$rows = $custom_fields ?: array( '' => '' );
				foreach ( $rows as $key => $value ) { $this->key_value_row( 'recipient_custom_key[]', 'recipient_custom_value[]', (string) $key, (string) $value ); }
				echo '</div><button type="button" class="button certloom-domain-add-kv" data-certloom-add-kv="recipient">' . esc_html__( 'Add custom field', 'certloom' ) . '</button>';
			} else {
				echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'This recipient contains advanced structured custom data. Certloom will preserve it unchanged until a dedicated editor can represent it safely.', 'certloom' ) . '</p></div>';
			}
			echo '</div>';
		}
		echo '<div class="certloom-domain-form-feedback" data-certloom-domain-form-feedback aria-live="polite"></div>';
		submit_button( $is_edit ? __( 'Update recipient', 'certloom' ) : __( 'Add recipient', 'certloom' ), 'primary' );
		echo '</form></section>';
	}

	/** @param array<string,mixed>|null $editing @return void */
	private function program_form( ?array $editing = null ) {
		$is_edit = null !== $editing;
		$id = $is_edit ? absint( $editing['id'] ) : 0;
		$simple = AdminUiMode::is_simple();
		$metadata = $is_edit && isset( $editing['metadata'] ) && is_array( $editing['metadata'] ) ? $editing['metadata'] : array();
		$metadata_editable = $this->is_scalar_map( $metadata );
		$profile = $is_edit && isset( $editing['profile'] ) && is_array( $editing['profile'] ) ? $editing['profile'] : ProgramProfile::defaults();
		$field_contract = $is_edit && isset( $editing['field_contract'] ) && is_array( $editing['field_contract'] ) ? $editing['field_contract'] : ScopedFieldContract::empty_program();
		$scopable_fields = \Ecapla_CertVerify_Fields::get_scopable_fields();
		$template_repo = new TemplateRepository();
		$design = null;
		if ( $is_edit ) {
			$design = $template_repo->resolve_published_assignment_details( array( 'issuer_id' => absint( $editing['issuer_id'] ?? 0 ), 'program_id' => $id ) );
		}
		$direct_design = $is_edit ? $template_repo->get_assignment( 'program', $id ) : null;
		$direct_template_id = is_array( $direct_design ) && ! empty( $direct_design['active'] ) ? absint( $direct_design['template_id'] ?? 0 ) : 0;
		$published_templates = ( new ScopedContextChoices() )->templates();
		$published_templates = is_wp_error( $published_templates ) ? array() : (array) $published_templates;
		$variant_template_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) ( $profile['template_variants'] ?? array() ) ) ) ) );
		foreach ( $variant_template_ids as $variant_template_id ) {
			$already_loaded = false; foreach ( $published_templates as $candidate ) { if ( absint( $candidate['id'] ?? 0 ) === $variant_template_id ) { $already_loaded = true; break; } }
			if ( $already_loaded ) { continue; }
			$selected_template = $template_repo->find_published( $variant_template_id );
			if ( is_array( $selected_template ) ) { $published_templates[] = array( 'id' => absint( $selected_template['id'] ?? 0 ), 'name' => (string) ( $selected_template['name'] ?? '' ), 'machine_key' => (string) ( $selected_template['machine_key'] ?? '' ), 'revision_number' => absint( $selected_template['published_revision']['revision_number'] ?? 0 ) ); }
		}
		$locale_policy = new CredentialLocale();
		$locale_preview = $locale_policy->resolve( $profile['credential_locale'] ?? CredentialLocale::SITE_DEFAULT );
		echo '<section id="certloom-domain-editor" class="ecapla-certverify-admin-card certloom-domain-editor"><div class="certloom-domain-editor-head"><div><h2>' . esc_html( $is_edit ? ( $simple ? __( 'Program', 'certloom' ) : __( 'Program profile', 'certloom' ) ) : __( 'Add program', 'certloom' ) ) . '</h2><p>' . esc_html( $simple ? __( 'Set the defaults for this course, certification or award. Related people, cohorts, design and issuance continue from this same workspace.', 'certloom' ) : __( 'Program Profiles centralize purpose, language, lifecycle and experience defaults while issued credentials keep immutable snapshots.', 'certloom' ) ) . '</p></div>';
		if ( $is_edit && ! $simple ) { echo '<span class="certloom-domain-id">#' . esc_html( (string) $id ) . '</span>'; }
		echo '</div><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-certloom-preserve-on-error data-certloom-entity-form="program" data-certloom-saving-message="' . esc_attr( $is_edit ? __( 'Saving program…', 'certloom' ) : __( 'Adding program…', 'certloom' ) ) . '" data-certloom-invalid-message="' . esc_attr__( 'Please complete the highlighted required field before saving this program.', 'certloom' ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( $is_edit ? 'certloom_domain_update_program' : 'certloom_domain_create_program' ) . '">';
		if ( $is_edit ) { echo '<input type="hidden" name="program_id" value="' . esc_attr( $id ) . '">'; wp_nonce_field( 'certloom_domain_update_program_' . $id ); } else { wp_nonce_field( 'certloom_domain_create_program' ); }
		echo '<div class="certloom-domain-form-grid">';
		$this->text_field( 'title', __( 'Program title', 'certloom' ), $is_edit ? (string) $editing['title'] : '', true, __( 'The human-readable credential program or course name.', 'certloom' ), 'text', '', __( 'Example: Internal Auditor Certification', 'certloom' ) );
		$this->text_field( 'code', $simple ? __( 'Short code', 'certloom' ) : __( 'Program code', 'certloom' ), $is_edit ? (string) $editing['code'] : '', false, $simple ? __( 'Optional. A short label such as BSC-CS or SAFETY-101 can make Programs easier to recognize.', 'certloom' ) : __( 'Optional unique machine-friendly code used in imports and future automation.', 'certloom' ) );
		$this->text_field( 'validity_days', __( 'Default validity (days)', 'certloom' ), $is_edit && null !== $editing['validity_days'] ? (string) $editing['validity_days'] : '', false, __( 'Leave blank when the program has no default expiry. Issuance may override this later.', 'certloom' ), 'number', 'min="0" step="1"' );
		$this->issuer_field( $is_edit ? ( $editing['issuer_id'] ?? null ) : null );
		echo '<div class="certloom-domain-field certloom-domain-field-wide"><label for="certloom-program-description"><strong>' . esc_html__( 'Description', 'certloom' ) . '</strong></label><textarea id="certloom-program-description" class="large-text" rows="5" name="description">' . esc_textarea( $is_edit ? (string) $editing['description'] : '' ) . '</textarea><p class="description">' . esc_html( $simple ? __( 'Optional internal note to help administrators understand this Program.', 'certloom' ) : __( 'Internal program context. Public credential copy will use explicit presentation fields rather than exposing this automatically.', 'certloom' ) ) . '</p></div>';
		echo '<div class="certloom-domain-field"><label><input type="checkbox" name="active" value="1" ' . checked( $is_edit ? ! empty( $editing['active'] ) : true, true, false ) . '> <strong>' . esc_html__( 'Active program', 'certloom' ) . '</strong></label><p class="description">' . esc_html__( 'Inactive programs remain in historical credentials but should not be offered for new issuance.', 'certloom' ) . '</p></div>';
		echo '</div>';
		echo '<div class="certloom-domain-subsection certloom-program-profile"><div class="certloom-domain-subsection-head"><div><h3>' . esc_html__( 'Program defaults', 'certloom' ) . '</h3><p>' . esc_html( $simple ? __( 'These settings are suggested whenever you issue a new credential for this Program.', 'certloom' ) : __( 'These are reusable Program configuration defaults. They never rewrite an issued Credential Version.', 'certloom' ) ) . '</p></div></div><div class="certloom-domain-form-grid">';
		echo '<div class="certloom-domain-field"><label for="certloom-program-purpose"><strong>' . esc_html__( 'Purpose / category', 'certloom' ) . '</strong></label><select id="certloom-program-purpose" name="profile[purpose]">';
		foreach ( ProgramProfile::purpose_labels() as $value => $label ) { echo '<option value="' . esc_attr( $value ) . '" ' . selected( (string) ( $profile['purpose'] ?? '' ), $value, false ) . '>' . esc_html( $label ) . '</option>'; }
		echo '</select><p class="description">' . esc_html( $simple ? __( 'Helps Certloom suggest and filter suitable designs and setup choices.', 'certloom' ) : __( 'Used by the future adaptive Wizard and Template Library filters; it does not claim a legal certification scheme.', 'certloom' ) ) . '</p></div>';
		$subs = ProgramProfile::subcategory_labels();
		echo '<div class="certloom-domain-field"><label for="certloom-program-subcategory"><strong>' . esc_html__( 'Subcategory', 'certloom' ) . '</strong></label><select id="certloom-program-subcategory" name="profile[subcategory]">';
		foreach ( $subs as $purpose => $items ) { foreach ( $items as $value => $label ) { echo '<option data-purpose="' . esc_attr( $purpose ) . '" value="' . esc_attr( $value ) . '" ' . selected( (string) ( $profile['subcategory'] ?? '' ), $value, false ) . '>' . esc_html( $label ) . '</option>'; } }
		echo '</select></div>';
		echo '<div class="certloom-domain-field"><label for="certloom-program-credential-type"><strong>' . esc_html__( 'Credential type', 'certloom' ) . '</strong></label><select id="certloom-program-credential-type" name="profile[credential_type]">';
		foreach ( array( 'certificate' => __( 'Certificate', 'certloom' ), 'certification' => __( 'Certification', 'certloom' ), 'degree' => __( 'Degree', 'certloom' ), 'diploma' => __( 'Diploma', 'certloom' ), 'license' => __( 'License', 'certloom' ), 'membership' => __( 'Membership', 'certloom' ), 'award' => __( 'Award', 'certloom' ), 'participation' => __( 'Participation', 'certloom' ), 'custom' => __( 'Custom', 'certloom' ) ) as $value => $label ) { echo '<option value="' . esc_attr( $value ) . '" ' . selected( (string) ( $profile['credential_type'] ?? 'certificate' ), $value, false ) . '>' . esc_html( $label ) . '</option>'; }
		echo '</select></div>';
		$this->text_field( 'profile[eligibility_context]', $simple ? __( 'Who qualifies?', 'certloom' ) : __( 'Eligibility context', 'certloom' ), (string) ( $profile['eligibility_context'] ?? '' ), false, $simple ? __( 'Optional short note such as “Complete all modules” or “Pass the final assessment”.', 'certloom' ) : __( 'Optional concise administrator-owned rule/context. LMS adapters later map qualifying attempts to this Program; this field is not a compliance assertion.', 'certloom' ) );
		if ( $simple ) {
			echo '<div class="certloom-domain-field certloom-domain-field-wide certloom-program-more-options"><details class="certloom-create-drawer"><summary><span class="dashicons dashicons-admin-settings" aria-hidden="true"></span>' . esc_html__( 'More Program options', 'certloom' ) . '</summary><div class="certloom-create-drawer-body"><p class="description">' . esc_html__( 'Language, renewal, visibility and delivery defaults are optional. Most Programs can keep the site defaults.', 'certloom' ) . '</p><div class="certloom-domain-form-grid">';
		}
		$this->credential_locale_field( 'profile[credential_locale]', (string) ( $profile['credential_locale'] ?? CredentialLocale::SITE_DEFAULT ) );
		if ( ! $simple && ! is_wp_error( $locale_preview ) ) { echo '<div class="certloom-domain-field certloom-program-locale-summary"><span><strong>' . esc_html__( 'Credential direction', 'certloom' ) . '</strong></span><div class="certloom-program-readonly-value">' . esc_html( strtoupper( (string) ( $locale_preview['direction'] ?? 'ltr' ) ) ) . '</div><p class="description">' . esc_html( sprintf( /* translators: %1$s: effective. */ __( 'Effective locale: %1$s. Element direction remains Auto / LTR / RTL, so mixed-script certificates can override individual elements.', 'certloom' ), (string) ( $locale_preview['effective'] ?? '' ) ) ) . '</p></div>'; }
		echo '<div class="certloom-domain-field"><label for="certloom-program-renewal"><strong>' . esc_html__( 'Renewal relevance', 'certloom' ) . '</strong></label><select id="certloom-program-renewal" name="profile[renewal]">';
		foreach ( array( 'not_applicable' => __( 'Not applicable', 'certloom' ), 'optional' => __( 'Optional', 'certloom' ), 'required' => __( 'Required', 'certloom' ) ) as $value => $label ) { echo '<option value="' . esc_attr( $value ) . '" ' . selected( (string) ( $profile['renewal'] ?? '' ), $value, false ) . '>' . esc_html( $label ) . '</option>'; }
		echo '</select></div>';
		echo '<div class="certloom-domain-field"><label for="certloom-program-verification-visibility"><strong>' . esc_html__( 'Verification visibility default', 'certloom' ) . '</strong></label><select id="certloom-program-verification-visibility" name="profile[verification_visibility]">';
		foreach ( array( 'inherit' => __( 'Inherit current safe issuance default', 'certloom' ), 'verification_summary' => __( 'Verification summary', 'certloom' ), 'public' => __( 'Public', 'certloom' ), 'unlisted' => __( 'Unlisted', 'certloom' ), 'recipient_only' => __( 'Recipient only', 'certloom' ), 'organization_member' => __( 'Organization member', 'certloom' ), 'private' => __( 'Private', 'certloom' ) ) as $value => $label ) { echo '<option value="' . esc_attr( $value ) . '" ' . selected( (string) ( $profile['verification_visibility'] ?? '' ), $value, false ) . '>' . esc_html( $label ) . '</option>'; }
		echo '</select><p class="description">' . esc_html( $simple ? __( 'Choose who should normally be able to view credentials issued for this Program.', 'certloom' ) : __( 'Stored as the Program default contract. Inherit preserves Certloom’s current verification-summary issuance behavior; future activation must remain explicit and review-bound.', 'certloom' ) ) . '</p></div>';
		foreach ( array( 'portal' => __( 'Recipient Portal', 'certloom' ), 'sharing' => __( 'Public sharing', 'certloom' ), 'email_delivery' => __( 'Email delivery', 'certloom' ) ) as $field => $label ) { $field_id = 'certloom-program-' . str_replace( '_', '-', $field ); echo '<div class="certloom-domain-field"><label for="' . esc_attr( $field_id ) . '"><strong>' . esc_html( $label ) . '</strong></label><select id="' . esc_attr( $field_id ) . '" name="profile[' . esc_attr( $field ) . ']">'; foreach ( array( 'inherit' => __( 'Inherit / system default', 'certloom' ), 'enabled' => __( 'Enabled by default', 'certloom' ), 'disabled' => __( 'Disabled by default', 'certloom' ) ) as $value => $option ) { echo '<option value="' . esc_attr( $value ) . '" ' . selected( (string) ( $profile[ $field ] ?? '' ), $value, false ) . '>' . esc_html( $option ) . '</option>'; } echo '</select></div>'; }
		if ( $simple ) {
			echo '<input type="hidden" name="profile[integration_mapping_key]" value="' . esc_attr( (string) ( $profile['integration_mapping_key'] ?? '' ) ) . '"><input type="hidden" name="profile[external_scheme_id]" value="' . esc_attr( (string) ( $profile['external_scheme_id'] ?? '' ) ) . '">';
		} else {
			$this->text_field( 'profile[integration_mapping_key]', __( 'Integration mapping key', 'certloom' ), (string) ( $profile['integration_mapping_key'] ?? '' ), false, __( 'Optional stable administrator-owned key for future LMS/automation mappings. No provider payload is stored here.', 'certloom' ) );
			$this->text_field( 'profile[external_scheme_id]', __( 'External scheme / reference ID', 'certloom' ), (string) ( $profile['external_scheme_id'] ?? '' ), false, __( 'Optional provenance reference; Certloom does not infer legal equivalence from this value.', 'certloom' ) );
		}
		$numbering_policy = \Ecapla_CertVerify_Numbering::policy();
		$numbering_label = ucfirst( (string) ( $numbering_policy['numbering_mode'] ?? 'manual' ) );
		echo '<div class="certloom-domain-field certloom-program-numbering-summary"><span><strong>' . esc_html__( 'Certificate numbering', 'certloom' ) . '</strong></span><div class="certloom-program-readonly-value">' . esc_html( $numbering_label ) . '</div><p class="description">' . esc_html( $simple ? __( 'Uses the site-wide numbering settings. Change numbering from Settings when needed.', 'certloom' ) : __( 'This Program currently inherits the established site numbering policy. Program-scoped numbering overrides are not enabled by PRG-02, so Certloom cannot silently create competing sequence authorities.', 'certloom' ) ) . '</p></div>';
		if ( $simple ) { echo '</div></div></details></div>'; }
		echo '</div>';
		if ( $simple ) { echo '<details class="certloom-create-drawer certloom-program-language-options"><summary><span class="dashicons dashicons-translation" aria-hidden="true"></span>' . esc_html__( 'Language-specific designs', 'certloom' ) . '</summary><div class="certloom-create-drawer-body">'; }
		echo '<div class="certloom-program-language-variants"><div class="certloom-domain-subsection-head"><div><h4>' . esc_html__( 'Language-specific certificate designs', 'certloom' ) . '</h4><p>' . esc_html( $simple ? __( 'Choose a different published design for a specific certificate language. If none is selected, Certloom uses the normal Program design.', 'certloom' ) : __( 'Map a credential locale to a published Template. Issuance tries the exact locale, then its base language, then the declared fallback, then the normal Program/Issuer/Global design.', 'certloom' ) ) . '</p></div></div>';
		$variants = isset( $profile['template_variants'] ) && is_array( $profile['template_variants'] ) ? $profile['template_variants'] : array();
		$variant_rows = $variants ?: array( '' => 0 );
		echo '<div class="certloom-locale-variant-list" data-certloom-locale-variant-list>';
		foreach ( $variant_rows as $variant_locale => $variant_template_id ) {
			echo '<div class="certloom-locale-variant-row"><label><span>' . esc_html__( 'Certificate language', 'certloom' ) . '</span><select name="profile_template_variant_locale[]" data-certloom-locale-select>' . $this->locale_options_html( (string) $variant_locale, false, true ) . '</select></label><label><span>' . esc_html( $simple ? __( 'Published design', 'certloom' ) : __( 'Published Template', 'certloom' ) ) . '</span><select name="profile_template_variant_template_id[]" data-certloom-context-lookup="template" data-certloom-context-nonce="' . esc_attr( wp_create_nonce( ScopedFieldAdminAjax::NONCE_ACTION ) ) . '" data-certloom-context-empty-value="0" data-certloom-context-search-placeholder="' . esc_attr( $simple ? __( 'Search published designs…', 'certloom' ) : __( 'Search published Templates…', 'certloom' ) ) . '"><option value="0">' . esc_html( $simple ? __( 'Choose design', 'certloom' ) : __( 'Choose Template', 'certloom' ) ) . '</option>';
			foreach ( $published_templates as $candidate ) { echo '<option value="' . esc_attr( (string) absint( $candidate['id'] ?? 0 ) ) . '" ' . selected( absint( $variant_template_id ), absint( $candidate['id'] ?? 0 ), false ) . '>' . esc_html( (string) ( $candidate['name'] ?? '' ) ) . '</option>'; }
			echo '</select></label><button type="button" class="button-link-delete certloom-locale-variant-remove" data-certloom-remove-locale-variant>' . esc_html__( 'Remove', 'certloom' ) . '</button></div>';
		}
		echo '</div>';
		$options_html = '<option value="0">' . esc_html( $simple ? __( 'Choose design', 'certloom' ) : __( 'Choose Template', 'certloom' ) ) . '</option>'; foreach ( $published_templates as $candidate ) { $options_html .= '<option value="' . esc_attr( (string) absint( $candidate['id'] ?? 0 ) ) . '">' . esc_html( (string) ( $candidate['name'] ?? '' ) ) . '</option>'; }
		echo '<template id="certloom-program-locale-variant-template"><div class="certloom-locale-variant-row"><label><span>' . esc_html__( 'Certificate language', 'certloom' ) . '</span><select name="profile_template_variant_locale[]" data-certloom-locale-select>' . $this->locale_options_html( '', false, true ) . '</select></label><label><span>' . esc_html( $simple ? __( 'Published design', 'certloom' ) : __( 'Published Template', 'certloom' ) ) . '</span><select name="profile_template_variant_template_id[]" data-certloom-context-lookup="template" data-certloom-context-nonce="' . esc_attr( wp_create_nonce( ScopedFieldAdminAjax::NONCE_ACTION ) ) . '" data-certloom-context-empty-value="0" data-certloom-context-search-placeholder="' . esc_attr( $simple ? __( 'Search published designs…', 'certloom' ) : __( 'Search published Templates…', 'certloom' ) ) . '">' . $options_html . '</select></label><button type="button" class="button-link-delete certloom-locale-variant-remove" data-certloom-remove-locale-variant>' . esc_html__( 'Remove', 'certloom' ) . '</button></div></template>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- options assembled from escaped values.
		echo '<div class="certloom-locale-variant-actions"><button type="button" class="button" data-certloom-add-locale-variant>' . esc_html__( 'Add language variant', 'certloom' ) . '</button><label><span>' . esc_html( $simple ? __( 'Fallback design', 'certloom' ) : __( 'Declared fallback', 'certloom' ) ) . '</span><select name="profile_template_variant_fallback" data-certloom-locale-fallback data-selected="' . esc_attr( (string) ( $profile['template_variant_fallback'] ?? 'program_default' ) ) . '"><option value="program_default">' . esc_html__( 'Program default design', 'certloom' ) . '</option>'; foreach ( array_keys( $variants ) as $variant_locale ) { echo '<option value="' . esc_attr( (string) $variant_locale ) . '" ' . selected( (string) ( $profile['template_variant_fallback'] ?? 'program_default' ), (string) $variant_locale, false ) . '>' . esc_html( (string) $variant_locale ) . '</option>'; } echo '</select></label></div><p class="description">' . esc_html( $simple ? __( 'If a language-specific design is no longer published, Certloom stops and asks you to choose another instead of sending the wrong design.', 'certloom' ) : __( 'A configured locale variant must remain published. If it becomes unavailable, issuance fails closed instead of silently switching certificate artwork.', 'certloom' ) ) . '</p></div>';
		if ( $simple ) { echo '</div></details>'; }
		echo '<div class="certloom-program-design-default"><div class="certloom-domain-subsection-head"><div><h4>' . esc_html__( 'Certificate design', 'certloom' ) . '</h4><p>' . esc_html__( 'Choose a published design for this Program, or inherit the next available Issuer / site default.', 'certloom' ) . '</p></div></div>';
		echo '<label class="certloom-domain-field certloom-domain-field-wide"><span><strong>' . esc_html__( 'Program design', 'certloom' ) . '</strong></span><select name="program_template_id" data-certloom-context-lookup="template" data-certloom-context-nonce="' . esc_attr( wp_create_nonce( ScopedFieldAdminAjax::NONCE_ACTION ) ) . '" data-certloom-context-empty-value="0" data-certloom-context-search-placeholder="' . esc_attr__( 'Search published designs…', 'certloom' ) . '"><option value="0">' . esc_html__( 'Inherit Issuer / site design', 'certloom' ) . '</option>';
		foreach ( $published_templates as $candidate ) { echo '<option value="' . esc_attr( (string) absint( $candidate['id'] ?? 0 ) ) . '" ' . selected( $direct_template_id, absint( $candidate['id'] ?? 0 ), false ) . '>' . esc_html( (string) ( $candidate['name'] ?? '' ) ) . '</option>'; }
		echo '</select><small>' . esc_html__( 'This is the default for credentials issued under this Program. A Batch can override it for that cohort.', 'certloom' ) . '</small></label>';
		if ( $is_edit && is_array( $design ) ) { echo '<p class="certloom-context-note"><span class="dashicons dashicons-art" aria-hidden="true"></span><span>' . esc_html( sprintf( /* translators: %s: resolved design name. */ __( 'Effective design now: %s', 'certloom' ), (string) ( $design['template_name'] ?? __( 'Assigned design', 'certloom' ) ) ) ) . '</span></p>'; }
		if ( $is_edit ) { $design_url = add_query_arg( array( 'page' => 'certloom-templates', 'design_view' => 'library', 'library_program_id' => $id ), admin_url( 'admin.php' ) ); echo '<p class="description"><a href="' . esc_url( $design_url ) . '">' . esc_html__( 'Open Design to preview, publish or make deeper changes', 'certloom' ) . '</a></p>'; }
		echo '</div></div>';
		echo '<div class="certloom-domain-subsection certloom-program-field-contract"><div class="certloom-domain-subsection-head"><div><h3>' . esc_html__( 'Credential fields', 'certloom' ) . '</h3><p>' . esc_html__( 'Add only the fields this Program needs. Batches can add or tighten fields later without rewriting issued credentials.', 'certloom' ) . '</p></div></div>';
		$current_rules = is_array( $field_contract['fields'] ?? null ) ? $field_contract['fields'] : array();
		$this->program_field_editor( $scopable_fields, $current_rules );
		echo '</div>';
		if ( ! $simple ) {
			echo '<div class="certloom-domain-subsection"><div class="certloom-domain-subsection-head"><div><h3>' . esc_html__( 'Advanced metadata', 'certloom' ) . '</h3><p>' . esc_html__( 'Optional local scalar metadata remains available for advanced integrations. Program Profile configuration above uses a reserved typed envelope and is not duplicated here.', 'certloom' ) . '</p></div></div>';
			if ( $metadata_editable ) {
				echo '<input type="hidden" name="program_metadata_present" value="1"><div class="certloom-domain-kv-list" data-certloom-kv-list="program">';
				$rows = $metadata ?: array( '' => '' );
				foreach ( $rows as $key => $value ) { $this->key_value_row( 'program_meta_key[]', 'program_meta_value[]', (string) $key, (string) $value ); }
				echo '</div><button type="button" class="button certloom-domain-add-kv" data-certloom-add-kv="program">' . esc_html__( 'Add metadata field', 'certloom' ) . '</button>';
			} else {
				echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'This program contains advanced structured metadata. Certloom will preserve it unchanged until a dedicated editor can represent it safely.', 'certloom' ) . '</p></div>';
			}
			echo '</div>';
		}
		echo '<div class="certloom-domain-form-feedback" data-certloom-domain-form-feedback aria-live="polite"></div>';
		submit_button( $is_edit ? __( 'Update program', 'certloom' ) : __( 'Add program', 'certloom' ), 'primary' );
		echo '</form></section>';
	}


	/** @param array<string,mixed>|null $editing @return void */
	private function issuer_form( ?array $editing = null ) {
		$is_edit = null !== $editing; $id = $is_edit ? absint( $editing['id'] ) : 0;
		$settings = $is_edit && isset( $editing['settings'] ) && is_array( $editing['settings'] ) ? $editing['settings'] : array();
		$simple = AdminUiMode::is_simple();
		echo '<section id="certloom-domain-editor" class="ecapla-certverify-admin-card certloom-domain-editor"><div class="certloom-domain-editor-head"><div><h2>' . esc_html( $is_edit ? ( $simple ? __( 'Issuer', 'certloom' ) : __( 'Issuer brand profile', 'certloom' ) ) : __( 'Add issuer / brand', 'certloom' ) ) . '</h2><p>' . esc_html( $simple ? __( 'Add the organization or brand shown on credentials. Its logo, colors and contact details can be reused across Programs.', 'certloom' ) : __( 'Brand defaults are local configuration. Issued credential Versions preserve their own immutable snapshot.', 'certloom' ) ) . '</p></div></div><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-certloom-preserve-on-error data-certloom-entity-form="issuer" data-certloom-saving-message="' . esc_attr( $is_edit ? __( 'Saving issuer…', 'certloom' ) : __( 'Adding issuer…', 'certloom' ) ) . '" data-certloom-invalid-message="' . esc_attr__( 'Please complete the highlighted required field before saving this issuer.', 'certloom' ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( $is_edit ? 'certloom_domain_update_issuer' : 'certloom_domain_create_issuer' ) . '">';
		if ( $is_edit ) { echo '<input type="hidden" name="issuer_id" value="' . esc_attr( $id ) . '">'; wp_nonce_field( 'certloom_domain_update_issuer_' . $id ); } else { wp_nonce_field( 'certloom_domain_create_issuer' ); }
		echo '<div class="certloom-domain-form-grid">';
		$this->text_field( 'name', __( 'Organization / issuer name', 'certloom' ), $is_edit ? (string) $editing['name'] : '', true, '', 'text', '', __( 'Example: Northstar Training Institute', 'certloom' ) );
		if ( $simple ) {
			$issuer_slug = $is_edit ? (string) $editing['slug'] : '';
			echo '<input type="hidden" name="slug" value="' . esc_attr( $issuer_slug ) . '">';
		} else {
			$this->text_field( 'slug', __( 'Machine slug', 'certloom' ), $is_edit ? (string) $editing['slug'] : '', true, __( 'Stable identity used by restores, assignments, integrations, and future APIs.', 'certloom' ), 'text', '', __( 'Example: northstar-training', 'certloom' ) );
		}
		$this->text_field( 'website', __( 'Website', 'certloom' ), (string) ( $settings['website'] ?? '' ), false, '', 'url' );
		$this->text_field( 'contact_name', __( 'Contact name', 'certloom' ), (string) ( $settings['contact_name'] ?? '' ) );
		$this->text_field( 'contact_email', __( 'Contact email', 'certloom' ), (string) ( $settings['contact_email'] ?? '' ), false, '', 'email' );
		$this->text_field( 'certificate_prefix', __( 'Certificate prefix', 'certloom' ), (string) ( $settings['certificate_prefix'] ?? '' ), false, __( 'A future numbering policy may use this as an issuer default; it does not rewrite existing numbers.', 'certloom' ) );
		$this->color_field( 'primary_color', __( 'Primary color', 'certloom' ), (string) ( $settings['primary_color'] ?? '' ), __( 'Used as the main issuer brand color where a design inherits issuer branding.', 'certloom' ) );
		$this->color_field( 'accent_color', __( 'Accent color', 'certloom' ), (string) ( $settings['accent_color'] ?? '' ), __( 'Optional secondary issuer brand color for designs that support it.', 'certloom' ) );
		$this->media_field( 'logo_attachment_id', __( 'Logo', 'certloom' ), $is_edit ? absint( $editing['logo_attachment_id'] ?? 0 ) : 0, __( 'Choose or upload the issuer logo from WordPress Media.', 'certloom' ) );
		$this->media_field( 'seal_attachment_id', __( 'Seal', 'certloom' ), $is_edit ? absint( $editing['seal_attachment_id'] ?? 0 ) : 0, __( 'Optional seal or emblem used by designs that include an issuer seal.', 'certloom' ) );
		echo '<div class="certloom-domain-field certloom-domain-field-wide"><label for="certloom-domain-address"><strong>' . esc_html__( 'Address', 'certloom' ) . '</strong></label><textarea id="certloom-domain-address" class="large-text" rows="3" name="address">' . esc_textarea( (string) ( $settings['address'] ?? '' ) ) . '</textarea></div>';
		echo '</div><p class="description">' . esc_html__( 'Signer profiles are assigned separately so the same person can serve at global, issuer, program, or batch scope without duplicating identity data.', 'certloom' ) . '</p>';
		echo '<div class="certloom-domain-form-feedback" data-certloom-domain-form-feedback aria-live="polite"></div>';
		submit_button( $is_edit ? __( 'Update issuer', 'certloom' ) : __( 'Add issuer', 'certloom' ), 'primary' ); echo '</form></section>';
	}

	/** @param array<string,mixed> $series @param array<string,mixed> $version @return void */
	private function lifecycle_form( array $series, array $version ) {
		$series_id = absint( $series['id'] );
		echo '<hr><h2>' . esc_html__( 'Lifecycle action', 'certloom' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'This preview routes every state change through the transaction-backed lifecycle service and writes an audit event. It does not directly edit state columns.', 'certloom' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-certloom-preserve-on-error>';
		echo '<input type="hidden" name="action" value="certloom_domain_lifecycle"><input type="hidden" name="series_id" value="' . esc_attr( $series_id ) . '">';
		wp_nonce_field( 'certloom_domain_lifecycle_' . $series_id );
		echo '<p><label>' . esc_html__( 'Action', 'certloom' ) . '<br><select name="lifecycle_action" required><option value="">' . esc_html__( 'Choose…', 'certloom' ) . '</option>';
		if ( States::ADMIN_ACTIVE === (string) $series['administrative_state'] ) { echo '<option value="suspend">' . esc_html__( 'Suspend', 'certloom' ) . '</option><option value="revoke">' . esc_html__( 'Revoke', 'certloom' ) . '</option>'; }
		if ( in_array( (string) $series['administrative_state'], array( States::ADMIN_SUSPENDED, States::ADMIN_REVOKED ), true ) ) { echo '<option value="reinstate">' . esc_html__( 'Reinstate', 'certloom' ) . '</option>'; }
		if ( States::ISSUANCE_VOIDED !== (string) $version['issuance_state'] ) { echo '<option value="void">' . esc_html__( 'Void current version', 'certloom' ) . '</option>'; }
		echo '</select></label></p>';
		echo '<p><label>' . esc_html__( 'Reason code', 'certloom' ) . '<br><input class="regular-text" name="reason_code" placeholder="' . esc_attr__( 'e.g. issuer_request', 'certloom' ) . '"></label></p>';
		echo '<p><label>' . esc_html__( 'Public reason (optional)', 'certloom' ) . '<br><textarea class="large-text" rows="2" name="public_reason"></textarea></label></p>';
		echo '<p><label>' . esc_html__( 'Internal note (optional)', 'certloom' ) . '<br><textarea class="large-text" rows="3" name="internal_note"></textarea></label></p>';
		submit_button( __( 'Apply lifecycle action', 'certloom' ), 'secondary' );
		echo '</form>';
	}

	/** @return void */
	private function version_history( CredentialRepository $repo, $series_id, $current_version_id ) {
		$versions = $repo->list_versions_for_series( $series_id, 100 );
		echo '<hr><h2>' . esc_html__( 'Immutable Version lineage', 'certloom' ) . '</h2><p class="description">' . esc_html__( 'Every issued correction or renewal remains a separate immutable Version. The current pointer does not erase earlier artifacts.', 'certloom' ) . '</p>';
		if ( is_wp_error( $versions ) ) { $this->error( $versions->get_error_message() ); return; }
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Version', 'certloom' ) . '</th><th>' . esc_html__( 'Certificate number', 'certloom' ) . '</th><th>' . esc_html__( 'Issuance state', 'certloom' ) . '</th><th>' . esc_html__( 'Issued', 'certloom' ) . '</th><th>' . esc_html__( 'Expires', 'certloom' ) . '</th><th>' . esc_html__( 'Lineage', 'certloom' ) . '</th></tr></thead><tbody>';
		foreach ( $versions as $v ) { $lineage = ! empty( $v['previous_version_id'] ) ? sprintf( /* translators: %d: previous version id. */ __( 'Previous #%d', 'certloom' ), absint( $v['previous_version_id'] ) ) : __( 'Origin', 'certloom' ); if ( absint( $v['id'] ) === absint( $current_version_id ) ) { $lineage .= ' · ' . __( 'Current', 'certloom' ); } echo '<tr><td><strong>#' . esc_html( (string) absint( $v['version_number'] ) ) . '</strong></td><td>' . esc_html( (string) $v['display_number'] ) . '</td><td>' . esc_html( (string) $v['issuance_state'] ) . '</td><td>' . esc_html( (string) ( $v['issued_at'] ?: '—' ) ) . '</td><td>' . esc_html( (string) ( $v['expires_at'] ?: '—' ) ) . '</td><td>' . esc_html( $lineage ) . '</td></tr>'; }
		if ( empty( $versions ) ) { echo '<tr><td colspan="6">' . esc_html__( 'No credential Versions recorded.', 'certloom' ) . '</td></tr>'; } echo '</tbody></table>';
	}

	/** @return void */
	private function event_history( $series_id ) {
		$events = ( new EventRepository() )->list_for_series( $series_id, 100 );
		echo '<hr><h2>' . esc_html__( 'Append-only activity history', 'certloom' ) . '</h2><p class="description">' . esc_html__( 'Lifecycle and domain events are shown newest first. Internal notes remain administrator-only.', 'certloom' ) . '</p>';
		if ( is_wp_error( $events ) ) { $this->error( $events->get_error_message() ); return; }
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Time (UTC)', 'certloom' ) . '</th><th>' . esc_html__( 'Event', 'certloom' ) . '</th><th>' . esc_html__( 'Version', 'certloom' ) . '</th><th>' . esc_html__( 'Reason', 'certloom' ) . '</th><th>' . esc_html__( 'Actor', 'certloom' ) . '</th><th>' . esc_html__( 'Details', 'certloom' ) . '</th></tr></thead><tbody>';
		foreach ( $events as $event ) { $details = array(); if ( ! empty( $event['old_state'] ) ) { $details[] = __( 'Old state recorded', 'certloom' ); } if ( ! empty( $event['new_state'] ) ) { $details[] = __( 'New state recorded', 'certloom' ); } if ( ! empty( $event['internal_note'] ) ) { $details[] = __( 'Internal note:', 'certloom' ) . ' ' . (string) $event['internal_note']; } if ( ! empty( $event['meta'] ) ) { $details[] = __( 'Metadata recorded', 'certloom' ); } echo '<tr><td>' . esc_html( (string) $event['created_at'] ) . '</td><td><code>' . esc_html( (string) $event['event_type'] ) . '</code></td><td>' . esc_html( $event['version_id'] ? '#' . (string) $event['version_id'] : '—' ) . '</td><td>' . esc_html( (string) ( $event['public_reason'] ?: $event['reason_code'] ?: '—' ) ) . '</td><td>' . esc_html( $event['actor_user_id'] ? '#' . (string) $event['actor_user_id'] : '—' ) . '</td><td>' . esc_html( $details ? implode( ' · ', $details ) : '—' ) . '</td></tr>'; }
		if ( empty( $events ) ) { echo '<tr><td colspan="6">' . esc_html__( 'No domain events recorded.', 'certloom' ) . '</td></tr>'; } echo '</tbody></table>';
	}

	/** @param RecipientRepository $repo @param array<string,mixed> $recipient @return void */
	private function recipient_history( RecipientRepository $repo, array $recipient ) {
		$rows = $repo->credential_history( absint( $recipient['id'] ), 25 );
		$issue_url = add_query_arg( array( 'page' => ManualIssuanceAdmin::PAGE, 'recipient_id' => absint( $recipient['id'] ) ), admin_url( 'admin.php' ) );
		echo '<section class="ecapla-certverify-admin-card certloom-domain-history"><div class="certloom-domain-subsection-head"><div><h2>' . esc_html__( 'Credential history', 'certloom' ) . '</h2><p>' . esc_html( AdminUiMode::is_simple() ? __( 'Credentials issued to this Recipient appear here. Use the same Recipient again so their history and portal stay connected.', 'certloom' ) : __( 'Current credentials linked to this Recipient profile. Issue future credentials to this same Recipient so identity, portal ownership and integrations stay connected while each issued credential keeps its own immutable snapshot.', 'certloom' ) ) . '</p></div><a class="button button-primary" href="' . esc_url( $issue_url ) . '">' . esc_html__( 'Issue credential to this recipient', 'certloom' ) . '</a></div>';
		if ( is_wp_error( $rows ) ) { $this->error( $rows->get_error_message() ); echo '</section>'; return; }
		$this->history_table( $rows, 'program_title' );
		echo '</section>';
	}

	/** @param ProgramRepository $repo @param array<string,mixed> $program @return void */
	private function program_history( ProgramRepository $repo, array $program ) {
		$rows = $repo->credential_history( absint( $program['id'] ), 25 );
		echo '<section class="ecapla-certverify-admin-card certloom-domain-history"><div class="certloom-domain-subsection-head"><div><h2>' . esc_html__( 'Program credential activity', 'certloom' ) . '</h2><p>' . esc_html__( 'Recent current credential identities issued under this program.', 'certloom' ) . '</p></div></div>';
		if ( is_wp_error( $rows ) ) { $this->error( $rows->get_error_message() ); echo '</section>'; return; }
		$this->history_table( $rows, 'display_name' );
		echo '</section>';
	}

	/** @param array<int,array<string,mixed>> $rows @param string $subject_key @return void */
	private function history_table( array $rows, $subject_key ) {
		$simple = AdminUiMode::is_simple();
		$status_service = new StatusService();
		$subject_label = 'program_title' === $subject_key ? __( 'Program', 'certloom' ) : __( 'Recipient', 'certloom' );
		if ( $simple ) {
			echo '<div class="certloom-domain-table-wrap"><table class="widefat striped certloom-domain-table"><thead><tr><th>' . esc_html__( 'Certificate', 'certloom' ) . '</th><th>' . esc_html( $subject_label ) . '</th><th>' . esc_html__( 'Issued', 'certloom' ) . '</th><th>' . esc_html__( 'Expires', 'certloom' ) . '</th><th>' . esc_html__( 'Status', 'certloom' ) . '</th><th>' . esc_html__( 'Action', 'certloom' ) . '</th></tr></thead><tbody>';
		} else {
			echo '<div class="certloom-domain-table-wrap"><table class="widefat striped certloom-domain-table"><thead><tr><th>' . esc_html__( 'Certificate', 'certloom' ) . '</th><th>' . esc_html( $subject_label ) . '</th><th>' . esc_html__( 'Public ID', 'certloom' ) . '</th><th>' . esc_html__( 'Issued', 'certloom' ) . '</th><th>' . esc_html__( 'Expires', 'certloom' ) . '</th><th>' . esc_html__( 'Status', 'certloom' ) . '</th></tr></thead><tbody>';
		}
		foreach ( $rows as $row ) {
			$status = $this->status_from_row( $status_service, $row );
			$credential_url = add_query_arg( array( 'page' => self::CREDENTIALS_PAGE, 'series_id' => absint( $row['series_id'] ) ), admin_url( 'admin.php' ) );
			if ( $simple ) {
				echo '<tr><td><a href="' . esc_url( $credential_url ) . '">' . esc_html( (string) ( $row['display_number'] ?: '—' ) ) . '</a></td><td>' . esc_html( (string) ( $row[ $subject_key ] ?: '—' ) ) . '</td><td>' . esc_html( (string) ( $row['issued_at'] ?: '—' ) ) . '</td><td>' . esc_html( (string) ( $row['expires_at'] ?: '—' ) ) . '</td><td>' . $this->credential_status_badge( $status ) . '</td><td><a class="button button-small" href="' . esc_url( $credential_url ) . '">' . esc_html__( 'Open credential', 'certloom' ) . '</a></td></tr>';
			} else {
				echo '<tr><td><a href="' . esc_url( $credential_url ) . '">' . esc_html( (string) ( $row['display_number'] ?: '—' ) ) . '</a></td><td>' . esc_html( (string) ( $row[ $subject_key ] ?: '—' ) ) . '</td><td><code>' . esc_html( (string) $row['public_id'] ) . '</code></td><td>' . esc_html( (string) ( $row['issued_at'] ?: '—' ) ) . '</td><td>' . esc_html( (string) ( $row['expires_at'] ?: '—' ) ) . '</td><td><span class="certloom-domain-status">' . esc_html( $status ? $status->code() : __( 'Unavailable', 'certloom' ) ) . '</span></td></tr>';
			}
		}
		if ( empty( $rows ) ) { echo '<tr><td colspan="6"><div class="certloom-domain-empty"><strong>' . esc_html__( 'No linked credentials yet.', 'certloom' ) . '</strong></div></td></tr>'; }
		echo '</tbody></table></div>';
	}

	/** @param array<string,mixed>|null $existing @return array<string,mixed>|\WP_Error */
	private function recipient_request( ?array $existing = null ) {
		$raw_email = isset( $_POST['email'] ) ? trim( (string) wp_unslash( $_POST['email'] ) ) : '';
		$email = '' !== $raw_email ? sanitize_email( $raw_email ) : '';
		if ( '' !== $raw_email && ( '' === $email || ( function_exists( 'is_email' ) && ! is_email( $email ) ) ) ) {
			return new \WP_Error( 'certloom_recipient_email_invalid', __( 'Enter a valid recipient email address or leave it blank.', 'certloom' ) );
		}
		$wp_user_id = isset( $_POST['wp_user_id'] ) && '' !== trim( (string) $_POST['wp_user_id'] ) ? absint( $_POST['wp_user_id'] ) : null;
		if ( $wp_user_id && function_exists( 'get_userdata' ) && ! get_userdata( $wp_user_id ) ) {
			return new \WP_Error( 'certloom_recipient_wp_user_missing', __( 'The selected WordPress user does not exist.', 'certloom' ) );
		}
		$data = array(
			'display_name' => isset( $_POST['display_name'] ) ? sanitize_text_field( wp_unslash( $_POST['display_name'] ) ) : '',
			'first_name'   => isset( $_POST['first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['first_name'] ) ) : '',
			'middle_name'  => isset( $_POST['middle_name'] ) ? sanitize_text_field( wp_unslash( $_POST['middle_name'] ) ) : '',
			'last_name'    => isset( $_POST['last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['last_name'] ) ) : '',
			'email'        => $email,
			'wp_user_id'   => $wp_user_id,
			'external_id'  => isset( $_POST['external_id'] ) ? sanitize_text_field( wp_unslash( $_POST['external_id'] ) ) : '',
		);
		if ( isset( $_POST['recipient_custom_fields_present'] ) ) {
			$parsed = $this->parse_key_value_request( 'recipient_custom_key', 'recipient_custom_value', 30 );
			if ( is_wp_error( $parsed ) ) { return $parsed; }
			$data['custom_fields'] = $parsed;
		} elseif ( null === $existing ) {
			$data['custom_fields'] = array();
		}
		return $data;
	}

	/** @param array<string,mixed>|null $existing @return array<string,mixed>|\WP_Error */
	private function program_request( ?array $existing = null ) {
		$issuer_id = isset( $_POST['issuer_id'] ) && '' !== trim( (string) $_POST['issuer_id'] ) ? absint( $_POST['issuer_id'] ) : null;
		if ( $issuer_id ) {
			$issuer = ( new IssuerRepository() )->find( $issuer_id );
			if ( is_wp_error( $issuer ) ) { return $issuer; }
			if ( ! is_array( $issuer ) ) { return new \WP_Error( 'certloom_program_issuer_missing', __( 'The selected issuer does not exist.', 'certloom' ) ); }
		}
		$data = array(
			'code'          => isset( $_POST['code'] ) ? sanitize_text_field( wp_unslash( $_POST['code'] ) ) : '',
			'title'         => isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '',
			'description'   => isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '',
			'issuer_id'     => $issuer_id,
			'validity_days' => isset( $_POST['validity_days'] ) && '' !== trim( (string) $_POST['validity_days'] ) ? absint( $_POST['validity_days'] ) : null,
			'active'        => isset( $_POST['active'] ),
		);
		$metadata = null !== $existing && isset( $existing['metadata'] ) && is_array( $existing['metadata'] ) ? $existing['metadata'] : array();
		if ( isset( $_POST['program_metadata_present'] ) ) {
			$parsed = $this->parse_key_value_request( 'program_meta_key', 'program_meta_value', 30 );
			if ( is_wp_error( $parsed ) ) { return $parsed; }
			$metadata = $parsed;
		}
		$raw_profile = isset( $_POST['profile'] ) && is_array( $_POST['profile'] ) ? wp_unslash( $_POST['profile'] ) : array();
		$variant_locales = isset( $_POST['profile_template_variant_locale'] ) && is_array( $_POST['profile_template_variant_locale'] ) ? wp_unslash( $_POST['profile_template_variant_locale'] ) : array();
		$variant_template_ids = isset( $_POST['profile_template_variant_template_id'] ) && is_array( $_POST['profile_template_variant_template_id'] ) ? wp_unslash( $_POST['profile_template_variant_template_id'] ) : array();
		if ( count( $variant_locales ) !== count( $variant_template_ids ) || count( $variant_locales ) > ProgramProfile::MAX_TEMPLATE_VARIANTS ) { return new \WP_Error( 'certloom_program_profile_template_variants_invalid', __( 'Program language variants are structurally invalid.', 'certloom' ) ); }
		$variant_map = array(); $locale_policy = new CredentialLocale(); $template_repo = new TemplateRepository();
		foreach ( $variant_locales as $index => $variant_locale ) {
			$variant_locale = trim( sanitize_text_field( (string) $variant_locale ) ); $variant_template_id = absint( $variant_template_ids[ $index ] ?? 0 );
			if ( '' === $variant_locale && ! $variant_template_id ) { continue; }
			if ( '' === $variant_locale || ! $variant_template_id ) { return new \WP_Error( 'certloom_program_profile_template_variant_incomplete', __( 'Each language variant needs both a locale and a published Template.', 'certloom' ) ); }
			$normalized_locale = $locale_policy->normalize_locale( $variant_locale ); if ( is_wp_error( $normalized_locale ) ) { return $normalized_locale; }
			if ( isset( $variant_map[ $normalized_locale ] ) ) { return new \WP_Error( 'certloom_program_profile_template_variant_duplicate', __( 'A Program cannot define the same locale Template variant more than once.', 'certloom' ) ); }
			$published = $template_repo->find_published( $variant_template_id );
			if ( is_wp_error( $published ) || ! is_array( $published ) || ! empty( $published['archived_at'] ) ) { return new \WP_Error( 'certloom_program_profile_template_variant_unpublished', __( 'Each Program language variant must reference an active published Template.', 'certloom' ) ); }
			$variant_map[ $normalized_locale ] = (string) ( $published['machine_key'] ?? '' );
		}
		$raw_profile['template_variants'] = $variant_map;
		$raw_profile['template_variant_fallback'] = isset( $_POST['profile_template_variant_fallback'] ) ? sanitize_text_field( wp_unslash( $_POST['profile_template_variant_fallback'] ) ) : 'program_default';
		$profile = ( new ProgramProfile() )->normalize( $raw_profile );
		if ( is_wp_error( $profile ) ) { return $profile; }
		$combined = ( new ProgramProfile() )->with_metadata( $metadata, $profile );
		if ( is_wp_error( $combined ) ) { return $combined; }
		$raw_field_rules = isset( $_POST['program_field_requirement'] ) && is_array( $_POST['program_field_requirement'] ) ? wp_unslash( $_POST['program_field_requirement'] ) : array();
		$field_rules = array();
		foreach ( $raw_field_rules as $raw_key => $raw_rule ) {
			$key = sanitize_key( (string) $raw_key );
			$rule = sanitize_key( (string) $raw_rule );
			if ( '' !== $key && in_array( $rule, array( 'optional', 'required' ), true ) ) { $field_rules[ $key ] = $rule; }
		}
		$contract = new ScopedFieldContract();
		$catalog = \Ecapla_CertVerify_Fields::get_field_map();
		$combined = $contract->with_program_metadata( $combined, $field_rules, $catalog );
		if ( is_wp_error( $combined ) ) { return $combined; }
		$program_contract = $contract->from_program_metadata( $combined, $catalog );
		if ( is_wp_error( $program_contract ) ) { return $program_contract; }
		$guarded = ( new ScopedFieldConfigurationGuard() )->validate_program_change( absint( $existing['id'] ?? 0 ), $program_contract, $profile, absint( $issuer_id ) );
		if ( is_wp_error( $guarded ) ) { return $guarded; }
		$data['metadata'] = $combined;
		return $data;
	}


	/** @return array<string,mixed>|\WP_Error */
	private function issuer_request( ?array $existing = null ) {
		$raw_website = isset( $_POST['website'] ) ? trim( (string) wp_unslash( $_POST['website'] ) ) : '';
		$website = '' !== $raw_website ? esc_url_raw( $raw_website, array( 'http', 'https' ) ) : '';
		if ( '' !== $raw_website && '' === $website ) {
			return new \WP_Error( 'certloom_issuer_website_invalid', __( 'Enter a valid HTTP or HTTPS issuer website or leave it blank.', 'certloom' ) );
		}

		$raw_email = isset( $_POST['contact_email'] ) ? trim( (string) wp_unslash( $_POST['contact_email'] ) ) : '';
		$email = '' !== $raw_email ? sanitize_email( $raw_email ) : '';
		if ( '' !== $raw_email && ( '' === $email || ! is_email( $email ) ) ) {
			return new \WP_Error( 'certloom_issuer_contact_email_invalid', __( 'Enter a valid issuer contact email or leave it blank.', 'certloom' ) );
		}

		$primary = isset( $_POST['primary_color'] ) ? strtoupper( trim( (string) wp_unslash( $_POST['primary_color'] ) ) ) : '';
		$accent = isset( $_POST['accent_color'] ) ? strtoupper( trim( (string) wp_unslash( $_POST['accent_color'] ) ) ) : '';
		foreach ( array( $primary, $accent ) as $color ) {
			if ( '' !== $color && ! preg_match( '/^#[A-F0-9]{6}$/', $color ) ) {
				return new \WP_Error( 'certloom_issuer_color_invalid', __( 'Issuer colors must use six-digit hexadecimal notation such as #183153.', 'certloom' ) );
			}
		}

		$address = isset( $_POST['address'] ) ? sanitize_textarea_field( wp_unslash( $_POST['address'] ) ) : '';
		$contact_name = isset( $_POST['contact_name'] ) ? sanitize_text_field( wp_unslash( $_POST['contact_name'] ) ) : '';
		$certificate_prefix = isset( $_POST['certificate_prefix'] ) ? sanitize_text_field( wp_unslash( $_POST['certificate_prefix'] ) ) : '';
		if ( $this->string_length( $address ) > 2000 ) {
			return new \WP_Error( 'certloom_issuer_address_too_long', __( 'Issuer address must be 2,000 characters or fewer.', 'certloom' ) );
		}
		if ( $this->string_length( $contact_name ) > 255 ) {
			return new \WP_Error( 'certloom_issuer_contact_name_too_long', __( 'Issuer contact name must be 255 characters or fewer.', 'certloom' ) );
		}
		if ( $this->string_length( $certificate_prefix ) > 100 ) {
			return new \WP_Error( 'certloom_issuer_prefix_too_long', __( 'Issuer certificate prefix must be 100 characters or fewer.', 'certloom' ) );
		}

		$known = array(
			'address' => $address,
			'website' => $website,
			'contact_name' => $contact_name,
			'contact_email' => $email,
			'primary_color' => $primary,
			'accent_color' => $accent,
			'certificate_prefix' => $certificate_prefix,
		);
		$settings = null !== $existing && isset( $existing['settings'] ) && is_array( $existing['settings'] ) ? $existing['settings'] : array();
		foreach ( $known as $key => $value ) { if ( '' === $value ) { unset( $settings[ $key ] ); } else { $settings[ $key ] = $value; } }
		$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$slug = isset( $_POST['slug'] ) ? sanitize_title( wp_unslash( $_POST['slug'] ) ) : '';
		if ( AdminUiMode::is_simple() && '' === $slug ) {
			$slug = null !== $existing && ! empty( $existing['slug'] ) ? sanitize_title( (string) $existing['slug'] ) : sanitize_title( $name );
		}
		return array(
			'name' => $name,
			'slug' => $slug,
			'logo_attachment_id' => isset( $_POST['logo_attachment_id'] ) && '' !== trim( (string) $_POST['logo_attachment_id'] ) ? absint( $_POST['logo_attachment_id'] ) : null,
			'seal_attachment_id' => isset( $_POST['seal_attachment_id'] ) && '' !== trim( (string) $_POST['seal_attachment_id'] ) ? absint( $_POST['seal_attachment_id'] ) : null,
			'settings' => $settings,
		);
	}


	/** @return int */
	private function string_length( $value ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( (string) $value, 'UTF-8' ) : strlen( (string) $value );
	}

	/** @return \Certloom\Domain\Credential\StatusResult|null */
	private function status_from_row( StatusService $service, array $item ) {
		try {
			return $service->evaluate(
				array(
					'issuance_state'       => (string) ( $item['issuance_state'] ?? States::ISSUANCE_DRAFT ),
					'administrative_state' => (string) ( $item['administrative_state'] ?? States::ADMIN_ACTIVE ),
					'legacy_expired'       => ! empty( $item['legacy_expired'] ),
					'valid_from'           => $item['valid_from'] ?? null,
					'expires_at'            => $item['expires_at'] ?? null,
					'compliance_state'      => (string) ( $item['compliance_state'] ?? States::COMPLIANCE_NOT_APPLICABLE ),
				)
			);
		} catch ( \Throwable $error ) {
			return null;
		}
	}

	/** @return string */
	private function search_value() {
		return isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only search.
	}

	/** @return int */
	private function page_value() {
		return isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination.
	}

	/** @param array{label:string,url:string,secondary?:bool}|null $action @return void */
	private function header( $title, $subtitle = '', ?array $action = null ) {
		echo '<div class="wrap ecapla-certverify-admin-wrap certloom-admin-shell certloom-domain-screen"><div class="certloom-domain-title-row"><div><h1>' . esc_html( $title ) . '</h1>';
		if ( '' !== (string) $subtitle ) { echo '<p class="certloom-domain-lead">' . esc_html( $subtitle ) . '</p>'; }
		echo '</div><div class="certloom-domain-title-actions">';
		if ( is_array( $action ) && ! empty( $action['label'] ) && ! empty( $action['url'] ) ) {
			echo '<a class="button ' . ( empty( $action['secondary'] ) ? 'button-primary' : '' ) . '" href="' . esc_url( (string) $action['url'] ) . '">' . esc_html( (string) $action['label'] ) . '</a>';
		}
		if ( ! AdminUiMode::is_simple() ) { echo '<span class="certloom-domain-preview-badge">' . esc_html__( 'Acceptance preview', 'certloom' ) . '</span>'; }
		echo '</div></div>';
	}

	/** @return void */
	private function footer() { echo '</div>'; }


	/** Task-focused Program workspace: configure related people and continue without tab-hopping. */
	private function program_workspace( array $program ) {
		$program_id = absint( $program['id'] ?? 0 );
		if ( ! $program_id ) { return; }
		$batches = ( new BatchRepository() )->search_active_for_program( $program_id, '', 8 );
		$batches = is_wp_error( $batches ) ? array() : $batches;
		$signers = ( new SignerRepository() )->resolve_hierarchy( absint( $program['issuer_id'] ?? 0 ), $program_id, 0, 'signer' );
		$signers = is_wp_error( $signers ) ? array() : $signers;
		$design = ( new TemplateRepository() )->resolve_published_assignment_details( array( 'issuer_id' => absint( $program['issuer_id'] ?? 0 ), 'program_id' => $program_id ) );
		$design_ready = is_array( $design ) && ! empty( $design['published_revision'] );
		$issue_url = add_query_arg( array( 'page' => 'certloom-issuance', 'program_id' => $program_id ), admin_url( 'admin.php' ) );
		$design_url = add_query_arg( array( 'page' => 'certloom-templates', 'program_id' => $program_id ), admin_url( 'admin.php' ) );

		echo '<section class="certloom-program-workspace certloom-workspace-card" aria-labelledby="certloom-program-next-title">';
		echo '<header class="certloom-workspace-card-head"><div><span class="certloom-eyebrow">' . esc_html__( 'Continue setup', 'certloom' ) . '</span><h2 id="certloom-program-next-title">' . esc_html__( 'Everything this Program needs', 'certloom' ) . '</h2><p>' . esc_html__( 'Add the related organization, cohorts and signers here, then move straight into design or issuance.', 'certloom' ) . '</p></div></header>';
		echo '<div class="certloom-program-flow-grid">';

		// Issuer.
		echo '<article class="certloom-program-flow-card"><div class="certloom-program-flow-icon dashicons dashicons-building" aria-hidden="true"></div><div><span class="certloom-eyebrow">' . esc_html__( 'Organization', 'certloom' ) . '</span><h3>' . esc_html__( 'Issuer', 'certloom' ) . '</h3>';
		if ( ! empty( $program['issuer_id'] ) ) {
			$issuer = ( new IssuerRepository() )->find( absint( $program['issuer_id'] ) );
			echo '<p><strong>' . esc_html( is_array( $issuer ) ? (string) ( $issuer['name'] ?? __( 'Connected issuer', 'certloom' ) ) : __( 'Connected issuer', 'certloom' ) ) . '</strong><br><span>' . esc_html__( 'Used as the organization identity for this Program.', 'certloom' ) . '</span></p>';
		} elseif ( current_user_can( \Ecapla_CertVerify_Permissions::CAP_MANAGE_ISSUERS ) ) {
			echo '<p>' . esc_html__( 'Add the organization issuing these credentials.', 'certloom' ) . '</p><form class="certloom-quick-inline-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="certloom_program_quick_issuer"><input type="hidden" name="program_id" value="' . esc_attr( (string) $program_id ) . '">'; wp_nonce_field( 'certloom_program_quick_issuer_' . $program_id ); echo '<label><span class="screen-reader-text">' . esc_html__( 'Issuer name', 'certloom' ) . '</span><input name="issuer_name" required maxlength="255" placeholder="' . esc_attr__( 'Organization name', 'certloom' ) . '"></label><button class="button" type="submit">' . esc_html__( 'Add issuer', 'certloom' ) . '</button></form>';
		} else { echo '<p>' . esc_html__( 'No issuer connected yet.', 'certloom' ) . '</p>'; }
		echo '</div></article>';

		// Batches.
		echo '<article class="certloom-program-flow-card"><div class="certloom-program-flow-icon dashicons dashicons-groups" aria-hidden="true"></div><div><span class="certloom-eyebrow">' . esc_html__( 'Optional grouping', 'certloom' ) . '</span><h3>' . esc_html__( 'Batches', 'certloom' ) . '</h3><p>' . esc_html( $batches ? sprintf( /* translators: %d: batches. */ _n( '%d active batch is connected.', '%d active batches are connected.', count( $batches ), 'certloom' ), count( $batches ) ) : __( 'Use Batches for cohorts, classes or intakes. Skip them if you do not need grouping.', 'certloom' ) ) . '</p>';
		if ( current_user_can( \Ecapla_CertVerify_Permissions::CAP_MANAGE_BATCHES ) ) { echo '<details class="certloom-quick-drawer"><summary>' . esc_html__( 'Quick add batch', 'certloom' ) . '</summary><form class="certloom-quick-stack-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="certloom_program_quick_batch"><input type="hidden" name="program_id" value="' . esc_attr( (string) $program_id ) . '">'; wp_nonce_field( 'certloom_program_quick_batch_' . $program_id ); echo '<label><span>' . esc_html__( 'Batch name', 'certloom' ) . '</span><input name="batch_name" required maxlength="255" placeholder="' . esc_attr__( 'e.g. Spring 2027', 'certloom' ) . '"></label><label><span>' . esc_html__( 'Code', 'certloom' ) . '</span><input name="batch_code" maxlength="100" placeholder="' . esc_attr__( 'Optional — generated from name', 'certloom' ) . '"></label><button class="button" type="submit">' . esc_html__( 'Add batch', 'certloom' ) . '</button></form></details>'; }
		echo '</div></article>';

		// Signers.
		echo '<article class="certloom-program-flow-card"><div class="certloom-program-flow-icon dashicons dashicons-edit-page" aria-hidden="true"></div><div><span class="certloom-eyebrow">' . esc_html__( 'People', 'certloom' ) . '</span><h3>' . esc_html__( 'Signers', 'certloom' ) . '</h3><p>' . esc_html( $signers ? sprintf( /* translators: %d: signers. */ _n( '%d signer is available to this Program.', '%d signers are available to this Program.', count( $signers ), 'certloom' ), count( $signers ) ) : __( 'Add someone whose signature or approval belongs on credentials.', 'certloom' ) ) . '</p>';
		if ( current_user_can( \Ecapla_CertVerify_Permissions::CAP_MANAGE_SIGNERS ) ) { echo '<details class="certloom-quick-drawer"><summary>' . esc_html__( 'Quick add signer', 'certloom' ) . '</summary><form class="certloom-quick-stack-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="certloom_program_quick_signer"><input type="hidden" name="program_id" value="' . esc_attr( (string) $program_id ) . '">'; wp_nonce_field( 'certloom_program_quick_signer_' . $program_id ); echo '<label><span>' . esc_html__( 'Name', 'certloom' ) . '</span><input name="signer_name" required maxlength="255"></label><label><span>' . esc_html__( 'Title', 'certloom' ) . '</span><input name="signer_title" maxlength="191" placeholder="' . esc_attr__( 'e.g. Dean', 'certloom' ) . '"></label><label><span>' . esc_html__( 'Email', 'certloom' ) . '</span><input type="email" name="signer_email"></label><button class="button" type="submit">' . esc_html__( 'Add signer', 'certloom' ) . '</button></form></details>'; }
		echo '</div></article>';

		// Design & issuance continuation.
		echo '<article class="certloom-program-flow-card is-primary"><div class="certloom-program-flow-icon dashicons dashicons-art" aria-hidden="true"></div><div><span class="certloom-eyebrow">' . esc_html__( 'Next step', 'certloom' ) . '</span><h3>' . esc_html__( 'Design & issue', 'certloom' ) . '</h3><p>' . esc_html( $design_ready ? __( 'A published design is ready. You can issue a credential now.', 'certloom' ) : __( 'Choose or publish a design, then issue a credential to a Recipient.', 'certloom' ) ) . '</p><div class="certloom-program-flow-actions"><a class="button" href="' . esc_url( $design_url ) . '">' . esc_html__( 'Choose design', 'certloom' ) . '</a><a class="button button-primary" href="' . esc_url( $issue_url ) . '">' . esc_html__( 'Issue credential', 'certloom' ) . '</a></div></div></article>';

		echo '</div></section>';
	}

	/** @return void */
	private function redirect_program_workspace( $program_id, $result, $success_key = '' ) {
		$args = array( 'page' => self::PROGRAMS_PAGE, 'program_id' => absint( $program_id ) );
		if ( is_wp_error( $result ) ) { $args['certloom_error'] = $result->get_error_message(); }
		elseif ( '' !== $success_key ) { $args[ sanitize_key( $success_key ) ] = 1; }
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/** @return void */
	private function notice() {
		if ( ! AdminUiMode::is_simple() ) {
			echo '<div class="notice notice-warning inline certloom-domain-gate-notice"><p><strong>' . esc_html__( 'Safety gate active.', 'certloom' ) . '</strong> ' . esc_html__( 'These normalized-domain screens are available only when the explicit admin-preview gate is enabled. The proven historical credential store remains production authority until real migration, restore, cutover, and database acceptance pass.', 'certloom' ) . '</p></div>';
		}
		if ( isset( $_GET['certloom_error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Redirected admin notice only.
			$this->error( sanitize_text_field( wp_unslash( $_GET['certloom_error'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		foreach ( array(
			'recipient_created' => __( 'Recipient created.', 'certloom' ),
			'recipient_updated' => __( 'Recipient updated.', 'certloom' ),
			'program_created'    => __( 'Program created.', 'certloom' ),
			'program_updated'    => __( 'Program updated.', 'certloom' ),
			'issuer_created'      => __( 'Issuer created.', 'certloom' ),
			'issuer_updated'      => __( 'Issuer updated.', 'certloom' ),
			'issuer_added'        => __( 'Issuer created and connected to this Program.', 'certloom' ),
			'batch_added'         => __( 'Batch added to this Program.', 'certloom' ),
			'signer_added'        => __( 'Signer created and assigned to this Program.', 'certloom' ),
			'credential_changed' => __( 'Credential lifecycle updated.', 'certloom' ),
		) as $key => $message ) {
			if ( isset( $_GET[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Presence-only redirected notice.
				echo '<div class="notice notice-success is-dismissible inline"><p>' . esc_html( $message ) . '</p></div>';
			}
		}
	}

	/** @return void */
	private function error( $message ) { echo '<div class="notice notice-error inline"><p>' . esc_html( $message ) . '</p></div>'; }

	/** @return void */
	private function detail_row( $label, $value_html ) {
		echo '<tr><th style="width:220px">' . esc_html( $label ) . '</th><td>' . wp_kses_post( $value_html ) . '</td></tr>';
	}

	/** @param array<int,array{value:mixed,label:string}> $items @return void */
	private function metric_grid( array $items ) {
		echo '<div class="certloom-domain-metrics">';
		foreach ( $items as $item ) {
			echo '<div class="certloom-domain-metric"><strong>' . esc_html( (string) $item['value'] ) . '</strong><span>' . esc_html( $item['label'] ) . '</span></div>';
		}
		echo '</div>';
	}

	/** Task-oriented Program list for Simple mode. @return void */
	private function simple_program_list( array $items ) {
		if ( empty( $items ) ) {
			echo '<div class="certloom-premium-empty-state certloom-empty-compact"><span class="dashicons dashicons-welcome-learn-more" aria-hidden="true"></span><h3>' . esc_html__( 'Create your first Program', 'certloom' ) . '</h3><p>' . esc_html__( 'A Program connects the issuer, optional cohorts, signers, design and credentials in one place.', 'certloom' ) . '</p><a class="button button-primary" href="' . esc_url( add_query_arg( array( 'page' => self::PROGRAMS_PAGE, 'certloom_new' => 1 ), admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'Add program', 'certloom' ) . '</a></div>';
			return;
		}
		echo '<div class="certloom-premium-list certloom-program-list" role="list">';
		foreach ( $items as $item ) {
			$program_id = absint( $item['id'] ?? 0 );
			$edit_url   = add_query_arg( array( 'page' => self::PROGRAMS_PAGE, 'program_id' => $program_id ), admin_url( 'admin.php' ) );
			$issue_url  = add_query_arg( array( 'page' => 'certloom-issuance', 'program_id' => $program_id ), admin_url( 'admin.php' ) );
			$design_url = add_query_arg( array( 'page' => 'certloom-templates', 'program_id' => $program_id ), admin_url( 'admin.php' ) );
			$issuer     = trim( (string) ( $item['issuer_name'] ?? '' ) );
			echo '<article class="certloom-premium-list-row" role="listitem"><div class="certloom-premium-list-main"><span class="certloom-eyebrow">' . esc_html( (string) ( $item['purpose_label'] ?: __( 'Program', 'certloom' ) ) ) . '</span><strong><a href="' . esc_url( $edit_url ) . '">' . esc_html( (string) ( $item['title'] ?? '' ) ) . '</a></strong><span>' . esc_html( '' !== $issuer ? $issuer : __( 'Issuer not added yet', 'certloom' ) ) . '</span></div>';
			echo '<div class="certloom-premium-list-status"><span class="certloom-state-pill ' . ( ! empty( $item['active'] ) ? 'is-success' : '' ) . '">' . esc_html( ! empty( $item['active'] ) ? _x( 'Active', 'entity status', 'certloom' ) : _x( 'Paused', 'entity status', 'certloom' ) ) . '</span></div>';
			echo '<div class="certloom-premium-list-meta"><span><small>' . esc_html__( 'Credentials', 'certloom' ) . '</small><strong>' . esc_html( number_format_i18n( absint( $item['credential_count'] ?? 0 ) ) ) . '</strong></span><span><small>' . esc_html__( 'Code', 'certloom' ) . '</small><strong>' . esc_html( (string) ( $item['code'] ?: '—' ) ) . '</strong></span></div>';
			echo '<div class="certloom-premium-list-actions"><a class="button button-primary" href="' . esc_url( $issue_url ) . '">' . esc_html__( 'Issue', 'certloom' ) . '</a><a class="certloom-premium-row-link" href="' . esc_url( $design_url ) . '">' . esc_html__( 'Design', 'certloom' ) . '</a><a class="certloom-premium-row-link" href="' . esc_url( $edit_url ) . '">' . esc_html__( 'Manage', 'certloom' ) . ' <span aria-hidden="true">→</span></a></div></article>';
		}
		echo '</div>';
	}

	/** @return void */
	private function program_views( $current, $search ) {
		echo '<nav class="certloom-domain-views" aria-label="' . esc_attr__( 'Program status filters', 'certloom' ) . '">';
		foreach ( array( 'all' => __( 'All', 'certloom' ), 'active' => _x( 'Active', 'entity status', 'certloom' ), 'inactive' => _x( 'Inactive', 'entity status', 'certloom' ) ) as $key => $label ) {
			$url = add_query_arg( array_filter( array( 'page' => self::PROGRAMS_PAGE, 'program_status' => $key, 's' => $search ) ), admin_url( 'admin.php' ) );
			echo '<a class="' . esc_attr( $current === $key ? 'is-current' : '' ) . '" href="' . esc_url( $url ) . '"' . ( $current === $key ? ' aria-current="page"' : '' ) . '>' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';
	}

	/** @return void */
	private function render_search( $page_slug, $search, $placeholder = '', array $hidden = array() ) {
		$id = 'certloom-domain-search-' . sanitize_html_class( $page_slug );
		echo '<form method="get" class="certloom-domain-search"><input type="hidden" name="page" value="' . esc_attr( $page_slug ) . '">';
		foreach ( $hidden as $key => $value ) { echo '<input type="hidden" name="' . esc_attr( sanitize_key( $key ) ) . '" value="' . esc_attr( (string) $value ) . '">'; }
		echo '<label class="screen-reader-text" for="' . esc_attr( $id ) . '">' . esc_html_x( 'Search', 'search field label', 'certloom' ) . '</label><input id="' . esc_attr( $id ) . '" type="search" name="s" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr( $placeholder ) . '"><button class="button">' . esc_html_x( 'Search', 'search action button', 'certloom' ) . '</button>';
		if ( '' !== $search ) { echo '<a class="button button-link" href="' . esc_url( add_query_arg( array_merge( array( 'page' => $page_slug ), $hidden ), admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'Clear', 'certloom' ) . '</a>'; }
		echo '</form>';
	}

	/** @return void */
	private function pagination( $page_slug, $page, $total, $per_page, $search, array $extra = array() ) {
		$total_pages = max( 1, (int) ceil( $total / $per_page ) );
		if ( $total_pages <= 1 ) { return; }
		$args = array_merge( array( 'page' => $page_slug, 's' => $search, 'paged' => '%#%' ), $extra );
		$base = add_query_arg( $args, admin_url( 'admin.php' ) );
		echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( paginate_links( array( 'base' => $base, 'format' => '', 'current' => $page, 'total' => $total_pages ) ) ) . '</div></div>';
	}

	/** @return void */

	/**
	 * Render the Program's certificate-locale selector without turning the
	 * convenience list into a server-side allow-list.
	 *
	 * Existing valid locales outside the curated list are retained as a selected
	 * option, so upgrades never silently rewrite administrator-owned Program data.
	 * Interface translation remains a separate WordPress/user-locale concern.
	 *
	 * @param string $name Field name.
	 * @param string $selected Current configured locale.
	 * @return void
	 */
	private function credential_locale_field( $name, $selected ) {
		echo '<div class="certloom-domain-field"><label for="certloom-program-credential-locale"><strong>' . esc_html__( 'Certificate language', 'certloom' ) . '</strong></label><select id="certloom-program-credential-locale" name="' . esc_attr( $name ) . '" data-certloom-credential-locale>' . $this->locale_options_html( (string) $selected, true, false ) . '</select><p class="description">' . esc_html__( 'Controls certificate locale, text direction and language-specific design selection. Certloom’s interface still follows the WordPress user language and only uses translations that are actually installed.', 'certloom' ) . '</p></div>';
	}

	/**
	 * @param string $selected Current locale.
	 * @param bool   $include_site_default Include site-default sentinel.
	 * @param bool   $include_blank Include an empty “Choose language” option.
	 * @return string Escaped option markup.
	 */
	private function locale_options_html( $selected, $include_site_default = true, $include_blank = false ) {
		$policy = new CredentialLocale();
		$selected = trim( (string) $selected );
		$options = $policy->selection_options( $include_site_default );
		if ( '' !== $selected && ! isset( $options[ $selected ] ) ) {
			$normalized = $include_site_default ? $policy->normalize_configured( $selected ) : $policy->normalize_locale( $selected );
			if ( ! is_wp_error( $normalized ) && CredentialLocale::SITE_DEFAULT !== $normalized ) {
				$selected = (string) $normalized;
				$options[ $selected ] = sprintf( /* translators: %s: selected locale. */ __( '%s — current custom locale', 'certloom' ), $selected );
			}
		}
		$html = $include_blank ? '<option value="">' . esc_html__( 'Choose language', 'certloom' ) . '</option>' : '';
		foreach ( $options as $locale => $label ) {
			$html .= '<option value="' . esc_attr( (string) $locale ) . '" ' . selected( $selected, (string) $locale, false ) . '>' . esc_html( (string) $label ) . '</option>';
		}
		return $html;
	}

	private function text_field( $name, $label, $value, $required = false, $description = '', $type = 'text', $attributes = '', $example = '' ) {
		$id = 'certloom-domain-' . sanitize_html_class( $name );
		$example_attr = $required && '' !== trim( (string) $example ) ? ' data-certloom-required-example="' . esc_attr( $example ) . '"' : '';
		echo '<div class="certloom-domain-field"><label class="certloom-field-label" for="' . esc_attr( $id ) . '"><strong>' . esc_html( $label ) . '</strong>' . ( $required ? '<span class="certloom-field-required">' . esc_html__( 'Required', 'certloom' ) . '</span>' : '' ) . $this->help_tip( $description ) . '</label><input id="' . esc_attr( $id ) . '" class="regular-text" type="' . esc_attr( $type ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" ' . ( $required ? 'required aria-required="true"' . $example_attr . ' ' : '' ) . wp_kses_post( $attributes ) . '></div>';
	}

	/** Compact accessible inline help, intentionally replacing most below-field explanatory paragraphs. */
	private function help_tip( $description ) {
		$description = trim( (string) $description ); if ( '' === $description ) { return ''; }
		return '<span class="certloom-help-tip" tabindex="0" aria-label="' . esc_attr( $description ) . '">?<span role="tooltip">' . esc_html( $description ) . '</span></span>';
	}

	private function color_field( $name, $label, $value, $description = '' ) {
		$id = 'certloom-domain-' . sanitize_html_class( $name ); $clean = preg_match( '/^#[0-9A-Fa-f]{6}$/', (string) $value ) ? strtoupper( (string) $value ) : ''; $picker = '' !== $clean ? $clean : '#176B57';
		echo '<div class="certloom-domain-field"><label class="certloom-field-label" for="' . esc_attr( $id ) . '-picker"><strong>' . esc_html( $label ) . '</strong>' . $this->help_tip( $description ) . '</label><div class="certloom-color-control" data-certloom-color-control><input type="hidden" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $clean ) . '" data-certloom-color-value><input type="color" id="' . esc_attr( $id ) . '-picker" value="' . esc_attr( $picker ) . '" data-certloom-color-picker><span data-certloom-color-label>' . esc_html( '' !== $clean ? $clean : __( 'Default', 'certloom' ) ) . '</span><button type="button" class="button button-small" data-certloom-color-clear>' . esc_html__( 'Use default', 'certloom' ) . '</button></div></div>';
	}

	private function media_field( $name, $label, $attachment_id, $description = '' ) {
		$id = 'certloom-domain-' . sanitize_html_class( $name ); $attachment_id = absint( $attachment_id ); $url = $attachment_id ? wp_get_attachment_image_url( $attachment_id, 'thumbnail' ) : ''; $filename = $attachment_id ? basename( (string) get_attached_file( $attachment_id ) ) : '';
		echo '<div class="certloom-domain-field"><label class="certloom-field-label"><strong>' . esc_html( $label ) . '</strong>' . $this->help_tip( $description ) . '</label><div class="certloom-media-control" data-certloom-media-control><input type="hidden" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $attachment_id ) . '" data-certloom-media-id><div class="certloom-media-preview" data-certloom-media-preview>';
		if ( $url ) { echo '<img src="' . esc_url( $url ) . '" alt=""><span>' . esc_html( $filename ) . '</span>'; } else { echo '<span>' . esc_html__( 'No file selected', 'certloom' ) . '</span>'; }
		echo '</div><div class="certloom-media-actions"><button type="button" class="button" data-certloom-media-select>' . esc_html__( 'Choose or upload', 'certloom' ) . '</button><button type="button" class="button-link-delete" data-certloom-media-clear ' . ( $attachment_id ? '' : 'hidden' ) . '>' . esc_html__( 'Remove', 'certloom' ) . '</button></div></div></div>';
	}

	/** Selected-only Program field contract editor. Backend contract remains unchanged. */

	/** Saves or clears one direct published-design override for a Program/Batch. @return true|\WP_Error */
	private function save_scope_template_assignment( $scope_type, $scope_id, $template_id ) {
		$repo = new TemplateRepository();
		$existing = $repo->get_assignment( $scope_type, absint( $scope_id ) );
		if ( is_wp_error( $existing ) ) { return $existing; }
		$expected_id = is_array( $existing ) ? absint( $existing['id'] ?? 0 ) : 0;
		$expected_updated_at = is_array( $existing ) ? (string) ( $existing['updated_at'] ?? '' ) : '';
		if ( ! absint( $template_id ) ) {
			if ( ! $expected_id ) { return true; }
			return $repo->clear_assignment( $scope_type, absint( $scope_id ), $expected_id, $expected_updated_at );
		}
		return $repo->assign( absint( $template_id ), $scope_type, absint( $scope_id ), 100, $expected_id, $expected_updated_at );
	}

	private function program_field_editor( array $fields, array $rules ) {
		$manage_url = add_query_arg( 'page', 'certloom-fields', admin_url( 'admin.php' ) );
		echo '<div class="certloom-scoped-field-editor" data-certloom-scope-field-editor="program" data-certloom-field-nonce="' . esc_attr( wp_create_nonce( ScopedFieldAdminAjax::NONCE_ACTION ) ) . '"><div class="certloom-scoped-field-toolbar"><label><span>' . esc_html__( 'Add an existing field', 'certloom' ) . '</span><select data-certloom-scope-field-picker><option value="">' . esc_html__( 'Choose field…', 'certloom' ) . '</option>';
		foreach ( $fields as $field ) { $key = sanitize_key( (string) ( $field['key'] ?? '' ) ); if ( '' === $key || ! empty( $rules[ $key ] ) ) { continue; } echo '<option value="' . esc_attr( $key ) . '">' . esc_html( (string) ( $field['label'] ?? $key ) ) . '</option>'; }
		echo '</select></label><button type="button" class="button" data-certloom-scope-field-add>' . esc_html__( 'Add field', 'certloom' ) . '</button><button type="button" class="button" data-certloom-quick-field-open>' . esc_html__( 'Create new field', 'certloom' ) . '</button><a href="' . esc_url( $manage_url ) . '">' . esc_html__( 'Manage all fields', 'certloom' ) . '</a></div><p class="certloom-scoped-field-ownership-note">' . esc_html__( 'Recipient profile fields (such as name, email and external ID) are reused for that person. Credential record fields can be different on each issued credential.', 'certloom' ) . '</p><div class="certloom-scoped-field-list" data-certloom-scope-field-list>';
		foreach ( $fields as $field ) { $key = sanitize_key( (string) ( $field['key'] ?? '' ) ); if ( '' === $key ) { continue; } $rule = (string) ( $rules[ $key ] ?? '' ); $source = ScopedFieldContract::value_source( $key ); $source_label = 'recipient' === $source ? __( 'Recipient profile', 'certloom' ) : __( 'Credential record', 'certloom' ); echo '<div class="certloom-scoped-field-row" data-certloom-scope-field-row="' . esc_attr( $key ) . '"' . ( '' === $rule ? ' hidden' : '' ) . '><div><strong data-certloom-scope-field-label>' . esc_html( (string) ( $field['label'] ?? $key ) ) . '</strong><span class="certloom-scoped-field-meta">' . esc_html( (string) ( $field['type'] ?? 'text' ) ) . ' · ' . esc_html( $source_label ) . ' · ' . esc_html( (string) ( $field['public_exposure'] ?? 'private' ) ) . '</span></div><label><span class="screen-reader-text">' . esc_html( sprintf( /* translators: %s: label. */ __( 'Program rule for %s', 'certloom' ), (string) ( $field['label'] ?? $key ) ) ) . '</span><select name="program_field_requirement[' . esc_attr( $key ) . ']" data-certloom-scope-field-rule><option value="" ' . selected( $rule, '', false ) . '>' . esc_html__( 'Not used', 'certloom' ) . '</option><option value="optional" ' . selected( $rule, 'optional', false ) . '>' . esc_html__( 'Optional', 'certloom' ) . '</option><option value="required" ' . selected( $rule, 'required', false ) . '>' . esc_html__( 'Required', 'certloom' ) . '</option></select></label><button type="button" class="button-link-delete" data-certloom-scope-field-remove>' . esc_html__( 'Remove', 'certloom' ) . '</button></div>'; }
		echo '</div><p class="certloom-scoped-field-empty" data-certloom-scope-field-empty' . ( array_filter( $rules ) ? ' hidden' : '' ) . '>' . esc_html__( 'No additional credential fields are assigned to this Program yet.', 'certloom' ) . '</p>' . $this->quick_field_dialog() . '</div>';
	}

	private function quick_field_dialog() {
		$sensitivity_options = \Ecapla_CertVerify_Fields::get_sensitivity_options();
		$exposure_options = \Ecapla_CertVerify_Fields::get_public_exposure_options();
		$html = '<dialog class="certloom-quick-field-dialog" data-certloom-quick-field-dialog><div class="certloom-quick-field-dialog-shell" role="document"><div class="certloom-dialog-head"><div><span class="certloom-eyebrow">' . esc_html__( 'Credential fields', 'certloom' ) . '</span><h3>' . esc_html__( 'Create a field', 'certloom' ) . '</h3></div><button type="button" class="button-link certloom-dialog-close" data-certloom-quick-field-close aria-label="' . esc_attr__( 'Close', 'certloom' ) . '">×</button></div>';
		$html .= '<p class="certloom-quick-field-intro">' . esc_html__( 'Create a reusable credential field and add it to this Program now. You can still change ordering or retire fields later in Settings → Fields.', 'certloom' ) . '</p>';
		$html .= '<div class="certloom-quick-field-grid"><div class="certloom-quick-field-field certloom-quick-field-wide"><label for="certloom-quick-field-label">' . esc_html__( 'Field label', 'certloom' ) . '</label><input id="certloom-quick-field-label" type="text" maxlength="100" data-certloom-quick-field-label required placeholder="' . esc_attr__( 'e.g. Student ID', 'certloom' ) . '"></div><div class="certloom-quick-field-field"><label for="certloom-quick-field-type">' . esc_html__( 'Field type', 'certloom' ) . '</label><select id="certloom-quick-field-type" data-certloom-quick-field-type><option value="text">' . esc_html__( 'Short text', 'certloom' ) . '</option><option value="textarea">' . esc_html__( 'Long text', 'certloom' ) . '</option><option value="number">' . esc_html__( 'Number', 'certloom' ) . '</option><option value="date">' . esc_html__( 'Date', 'certloom' ) . '</option></select></div>';
		$html .= '<div class="certloom-quick-field-field"><label for="certloom-quick-field-sensitivity">' . esc_html__( 'Sensitivity', 'certloom' ) . '</label><select id="certloom-quick-field-sensitivity" data-certloom-quick-field-sensitivity>'; foreach ( $sensitivity_options as $value => $label ) { $html .= '<option value="' . esc_attr( $value ) . '" ' . selected( 'personal', $value, false ) . '>' . esc_html( $label ) . '</option>'; } $html .= '</select></div>';
		$html .= '<div class="certloom-quick-field-field certloom-quick-field-wide"><label for="certloom-quick-field-exposure">' . esc_html__( 'Public visibility', 'certloom' ) . '</label><select id="certloom-quick-field-exposure" data-certloom-quick-field-exposure>'; foreach ( $exposure_options as $value => $label ) { $html .= '<option value="' . esc_attr( $value ) . '" ' . selected( 'private', $value, false ) . '>' . esc_html( $label ) . '</option>'; } $html .= '</select><small>' . esc_html__( 'Internal fields are always private. Choose public visibility only when the data is appropriate to show on verification or profile pages.', 'certloom' ) . '</small></div></div>';
		$html .= '<div class="certloom-quick-field-context"><strong>' . esc_html__( 'Where is this value stored?', 'certloom' ) . '</strong><span>' . esc_html__( 'Quick-created custom fields belong to the credential record, so the value can differ for each credential. Person-level details such as name, email and external ID belong to the Recipient profile.', 'certloom' ) . '</span></div>';
		$html .= '<div class="certloom-quick-field-feedback" data-certloom-quick-field-feedback aria-live="polite"></div><div class="certloom-dialog-actions"><button type="button" class="button" data-certloom-quick-field-close>' . esc_html__( 'Cancel', 'certloom' ) . '</button><button type="button" class="button button-primary" data-certloom-quick-field-create>' . esc_html__( 'Create & add', 'certloom' ) . '</button></div></div></dialog>';
		return $html;
	}

	/** @return void */
	private function issuer_field( $selected ) {
		$items = ( new ScopedContextChoices() )->issuers( $selected );
		echo '<div class="certloom-domain-field"><label for="certloom-domain-issuer-id"><strong>' . esc_html__( 'Default issuer', 'certloom' ) . '</strong></label><select id="certloom-domain-issuer-id" name="issuer_id" data-certloom-context-lookup="issuer" data-certloom-context-nonce="' . esc_attr( wp_create_nonce( ScopedFieldAdminAjax::NONCE_ACTION ) ) . '" data-certloom-context-search-placeholder="' . esc_attr__( 'Search issuers…', 'certloom' ) . '"><option value="">' . esc_html__( 'No default issuer', 'certloom' ) . '</option>';
		if ( ! is_wp_error( $items ) ) { foreach ( $items as $issuer ) { echo '<option value="' . esc_attr( (string) absint( $issuer['id'] ?? 0 ) ) . '" ' . selected( absint( $selected ), absint( $issuer['id'] ?? 0 ), false ) . '>' . esc_html( (string) ( $issuer['name'] ?? '' ) ) . '</option>'; } }
		echo '</select>' . $this->help_tip( __( 'Optional default issuer. Search covers the complete issuer registry without loading every record at once.', 'certloom' ) ) . '</div>';
	}

	/** @return void */
	private function key_value_row( $key_name, $value_name, $key, $value ) {
		echo '<div class="certloom-domain-kv-row"><label><span class="screen-reader-text">' . esc_html__( 'Field key', 'certloom' ) . '</span><input type="text" name="' . esc_attr( $key_name ) . '" value="' . esc_attr( $key ) . '" placeholder="' . esc_attr__( 'field_key', 'certloom' ) . '"></label><label><span class="screen-reader-text">' . esc_html__( 'Field value', 'certloom' ) . '</span><input type="text" name="' . esc_attr( $value_name ) . '" value="' . esc_attr( $value ) . '" placeholder="' . esc_attr__( 'Value', 'certloom' ) . '"></label><button type="button" class="button-link-delete certloom-domain-remove-kv">' . esc_html__( 'Remove', 'certloom' ) . '</button></div>';
	}

	/** @return array<string,string>|\WP_Error */
	private function parse_key_value_request( $key_name, $value_name, $max_rows ) {
		$keys = isset( $_POST[ $key_name ] ) && is_array( $_POST[ $key_name ] ) ? wp_unslash( $_POST[ $key_name ] ) : array();
		$values = isset( $_POST[ $value_name ] ) && is_array( $_POST[ $value_name ] ) ? wp_unslash( $_POST[ $value_name ] ) : array();
		if ( count( $keys ) > $max_rows || count( $values ) > $max_rows ) { return new \WP_Error( 'certloom_domain_metadata_limit', __( 'Too many custom metadata rows were submitted.', 'certloom' ) ); }
		$result = array();
		$count = max( count( $keys ), count( $values ) );
		for ( $i = 0; $i < $count; $i++ ) {
			$raw_key = isset( $keys[ $i ] ) ? trim( (string) $keys[ $i ] ) : '';
			$value = isset( $values[ $i ] ) ? sanitize_text_field( (string) $values[ $i ] ) : '';
			if ( '' === $raw_key && '' === $value ) { continue; }
			$key = sanitize_key( $raw_key );
			if ( '' === $key ) { return new \WP_Error( 'certloom_domain_metadata_key_invalid', __( 'Every custom metadata value needs a valid machine key.', 'certloom' ) ); }
			if ( isset( $result[ $key ] ) ) { return new \WP_Error( 'certloom_domain_metadata_key_duplicate', __( 'Custom metadata keys must be unique.', 'certloom' ) ); }
			$result[ $key ] = $value;
		}
		return $result;
	}

	/** @return bool */
	private function is_scalar_map( array $value ) {
		foreach ( $value as $key => $item ) {
			$key = (string) $key;
			if ( '' === $key || sanitize_key( $key ) !== $key || ! is_string( $item ) ) { return false; }
		}
		return true;
	}

	/** @param array<int,array<string,mixed>> $items @return void */
	private function prime_wp_users( array $items ) {
		if ( ! function_exists( 'cache_users' ) ) { return; }
		$ids = array();
		foreach ( $items as $item ) { if ( ! empty( $item['wp_user_id'] ) ) { $ids[] = absint( $item['wp_user_id'] ); } }
		$ids = array_values( array_unique( array_filter( $ids ) ) );
		if ( $ids ) { cache_users( $ids ); }
	}

	/** @return string */
	private function wp_user_label( $user_id ) {
		$user_id = absint( $user_id );
		if ( ! $user_id ) { return '—'; }
		if ( function_exists( 'get_userdata' ) ) {
			$user = get_userdata( $user_id );
			if ( $user ) {
				$name  = trim( (string) $user->display_name );
				$email = sanitize_email( (string) $user->user_email );
				if ( '' !== $name && '' !== $email ) { return sprintf( '%1$s · %2$s', $name, $email ); }
				if ( '' !== $name ) { return $name; }
				if ( '' !== $email ) { return $email; }
			}
		}
		return __( 'Linked account unavailable', 'certloom' );
	}


	/** @return void */
	private function require_capability( $capability ) {
		if ( ! current_user_can( $capability ) ) {
			wp_die( esc_html__( 'You do not have permission to manage this Certloom area.', 'certloom' ) );
		}
	}

	/** @return void */
	private function redirect_result( $page_slug, $result, $success_key, array $args = array() ) {
		$args['page'] = $page_slug;
		$url = add_query_arg( $args, admin_url( 'admin.php' ) );
		if ( is_wp_error( $result ) ) {
			$url = add_query_arg( 'certloom_error', $result->get_error_message(), $url );
		} else {
			$url = add_query_arg( $success_key, '1', $url );
		}
		wp_safe_redirect( $url );
		exit;
	}
}
