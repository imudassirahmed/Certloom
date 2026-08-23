<?php
namespace Certloom\Infrastructure\Admin;

use Certloom\Application\CredentialLifecycleService;
use Certloom\Domain\Credential\States;
use Certloom\Domain\Credential\StatusService;
use Certloom\Infrastructure\Repository\CredentialRepository;
use Certloom\Infrastructure\Repository\BatchRepository;
use Certloom\Infrastructure\Repository\EventRepository;
use Certloom\Infrastructure\Repository\IssuerRepository;
use Certloom\Infrastructure\Repository\ProgramRepository;
use Certloom\Infrastructure\Repository\RecipientRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 1.5.3 preview admin for the additive Recipient / Program / Credential domain.
 *
 * This entire admin is hidden behind CERTLOOM_DOMAIN_ADMIN_PREVIEW until the
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
		add_action( 'admin_post_certloom_domain_create_issuer', array( $this, 'create_issuer' ) );
		add_action( 'admin_post_certloom_domain_update_issuer', array( $this, 'update_issuer' ) );
		add_action( 'admin_post_certloom_domain_lifecycle', array( $this, 'lifecycle' ) );
	}

	/** @return void */
	public function menu() {
		add_submenu_page( 'certloom', __( 'Recipients', 'certloom' ), __( 'Recipients', 'certloom' ), \Ecapla_CertVerify_Permissions::CAP_MANAGE_RECIPIENTS, self::RECIPIENTS_PAGE, array( $this, 'render_recipients' ) );
		add_submenu_page( 'certloom', __( 'Programs', 'certloom' ), __( 'Programs', 'certloom' ), \Ecapla_CertVerify_Permissions::CAP_MANAGE_PROGRAMS, self::PROGRAMS_PAGE, array( $this, 'render_programs' ) );
		add_submenu_page( 'certloom', __( 'Issuers (New Domain)', 'certloom' ), __( 'Issuers (New)', 'certloom' ), \Ecapla_CertVerify_Permissions::CAP_MANAGE_ISSUERS, self::ISSUERS_PAGE, array( $this, 'render_issuers' ) );
		add_submenu_page( 'certloom', __( 'Credentials (New Domain)', 'certloom' ), __( 'Credentials (New)', 'certloom' ), \Ecapla_CertVerify_Permissions::CAP_VIEW_CREDENTIALS, self::CREDENTIALS_PAGE, array( $this, 'render_credentials' ) );
	}

	/** @return void */
	public function create_recipient() {
		$this->require_capability( \Ecapla_CertVerify_Permissions::CAP_MANAGE_RECIPIENTS );
		check_admin_referer( 'certloom_domain_create_recipient' );
		$data = $this->recipient_request();
		if ( is_wp_error( $data ) ) {
			$this->redirect_result( self::RECIPIENTS_PAGE, $data, 'recipient_created' );
		}
		$result = ( new RecipientRepository() )->create( $data );
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
			$this->redirect_result( self::RECIPIENTS_PAGE, $data, 'recipient_updated', array( 'recipient_id' => $id ) );
		}
		$result = $repo->update( $id, $data );
		$this->redirect_result( self::RECIPIENTS_PAGE, $result, 'recipient_updated', array( 'recipient_id' => $id ) );
	}

	/** @return void */
	public function create_program() {
		$this->require_capability( \Ecapla_CertVerify_Permissions::CAP_MANAGE_PROGRAMS );
		check_admin_referer( 'certloom_domain_create_program' );
		$data = $this->program_request();
		if ( is_wp_error( $data ) ) {
			$this->redirect_result( self::PROGRAMS_PAGE, $data, 'program_created' );
		}
		$result = ( new ProgramRepository() )->create( $data );
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
			$this->redirect_result( self::PROGRAMS_PAGE, $data, 'program_updated', array( 'program_id' => $id ) );
		}
		$result = $repo->update( $id, $data );
		$this->redirect_result( self::PROGRAMS_PAGE, $result, 'program_updated', array( 'program_id' => $id ) );
	}


	/** @return void */
	public function create_issuer() {
		$this->require_capability( \Ecapla_CertVerify_Permissions::CAP_MANAGE_ISSUERS );
		check_admin_referer( 'certloom_domain_create_issuer' );
		$data = $this->issuer_request();
		$result = is_wp_error( $data ) ? $data : ( new IssuerRepository() )->create( $data );
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
		$result = is_wp_error( $data ) ? $data : $repo->update( $id, $data );
		$this->redirect_result( self::ISSUERS_PAGE, $result, 'issuer_updated', array( 'issuer_id' => $id ) );
	}

	/** @return void */
	public function lifecycle() {
		$this->require_capability( \Ecapla_CertVerify_Permissions::CAP_MANAGE_CREDENTIALS );
		$series_id = isset( $_POST['series_id'] ) ? absint( $_POST['series_id'] ) : 0;
		check_admin_referer( 'certloom_domain_lifecycle_' . $series_id );
		$action = isset( $_POST['lifecycle_action'] ) ? sanitize_key( wp_unslash( $_POST['lifecycle_action'] ) ) : '';
		$credentials = new CredentialRepository();
		$series = $credentials->find_series( $series_id );
		if ( is_wp_error( $series ) || ! is_array( $series ) ) {
			$result = is_wp_error( $series ) ? $series : new \WP_Error( 'certloom_domain_series_missing', __( 'The credential could not be found.', 'certloom' ) );
			$this->redirect_result( self::CREDENTIALS_PAGE, $result, 'credential_changed' );
		}

		$context = array(
			'actor_user_id' => get_current_user_id(),
			'reason_code'   => isset( $_POST['reason_code'] ) ? sanitize_key( wp_unslash( $_POST['reason_code'] ) ) : null,
			'public_reason' => isset( $_POST['public_reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['public_reason'] ) ) : null,
			'internal_note' => isset( $_POST['internal_note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['internal_note'] ) ) : null,
			'meta'          => array( 'source' => 'domain_admin_preview' ),
		);
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
				$version_id = absint( $series['current_version_id'] ?? 0 );
				$result = $version_id ? $service->void( $series_id, $version_id, $context ) : new \WP_Error( 'certloom_domain_version_missing', __( 'The credential has no current version to void.', 'certloom' ) );
				break;
			default:
				$result = new \WP_Error( 'certloom_domain_lifecycle_action_invalid', __( 'Choose a valid credential lifecycle action.', 'certloom' ) );
		}
		$this->redirect_result( self::CREDENTIALS_PAGE, $result, 'credential_changed', array( 'series_id' => $series_id ) );
	}

	/** @return void */
	public function render_recipients() {
		$this->require_capability( \Ecapla_CertVerify_Permissions::CAP_MANAGE_RECIPIENTS );
		$search = $this->search_value();
		$page   = $this->page_value();
		$repo   = new RecipientRepository();
		$result = $repo->list_page( $search, $page, 20 );
		$summary = $repo->summary();
		$edit_id = isset( $_GET['recipient_id'] ) ? absint( $_GET['recipient_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin navigation.
		$editing = $edit_id ? $repo->find( $edit_id ) : null;

		$this->header( __( 'Recipients', 'certloom' ), __( 'Normalized people and organizations who receive Certloom credentials.', 'certloom' ) );
		$this->notice();
		if ( ! is_wp_error( $summary ) ) {
			$this->metric_grid(
				array(
					array( 'value' => $summary['total'], 'label' => __( 'Recipients', 'certloom' ) ),
					array( 'value' => $summary['with_credentials'], 'label' => __( 'With credentials', 'certloom' ) ),
					array( 'value' => $summary['linked_users'], 'label' => __( 'Linked WordPress users', 'certloom' ) ),
					array( 'value' => $summary['with_email'], 'label' => __( 'With email', 'certloom' ) ),
				)
			);
		}
		$this->render_search( self::RECIPIENTS_PAGE, $search, __( 'Search name, email, or external ID', 'certloom' ) );
		if ( is_wp_error( $result ) ) {
			$this->error( $result->get_error_message() );
		} else {
			$this->prime_wp_users( $result['items'] );
			echo '<div class="certloom-domain-table-wrap"><table class="widefat striped certloom-domain-table"><thead><tr><th>' . esc_html__( 'Recipient', 'certloom' ) . '</th><th>' . esc_html__( 'Email', 'certloom' ) . '</th><th>' . esc_html__( 'WordPress user', 'certloom' ) . '</th><th>' . esc_html__( 'External ID', 'certloom' ) . '</th><th>' . esc_html__( 'Credentials', 'certloom' ) . '</th><th>' . esc_html__( 'Latest issuance', 'certloom' ) . '</th><th>' . esc_html__( 'Actions', 'certloom' ) . '</th></tr></thead><tbody>';
			foreach ( $result['items'] as $item ) {
				$edit_url = add_query_arg( array( 'page' => self::RECIPIENTS_PAGE, 'recipient_id' => absint( $item['id'] ) ), admin_url( 'admin.php' ) );
				$user_label = $this->wp_user_label( $item['wp_user_id'] ?? null );
				echo '<tr><td><strong><a href="' . esc_url( $edit_url ) . '">' . esc_html( (string) $item['display_name'] ) . '</a></strong>';
				if ( ! empty( $item['first_name'] ) || ! empty( $item['last_name'] ) ) {
					echo '<div class="certloom-domain-muted">' . esc_html( trim( (string) $item['first_name'] . ' ' . (string) $item['last_name'] ) ) . '</div>';
				}
				echo '</td><td>' . esc_html( (string) ( $item['email'] ?: '—' ) ) . '</td><td>' . esc_html( $user_label ) . '</td><td>' . ( $item['external_id'] ? '<code>' . esc_html( (string) $item['external_id'] ) . '</code>' : '—' ) . '</td><td><span class="certloom-domain-count">' . esc_html( (string) absint( $item['credential_count'] ) ) . '</span></td><td>' . esc_html( (string) ( $item['latest_issued_at'] ?: '—' ) ) . '</td><td><a class="button button-small" href="' . esc_url( $edit_url ) . '">' . esc_html__( 'Open', 'certloom' ) . '</a></td></tr>';
			}
			if ( empty( $result['items'] ) ) { echo '<tr><td colspan="7"><div class="certloom-domain-empty"><strong>' . esc_html__( 'No recipients found.', 'certloom' ) . '</strong><span>' . esc_html__( 'Add a recipient or adjust the search.', 'certloom' ) . '</span></div></td></tr>'; }
			echo '</tbody></table></div>';
			$this->pagination( self::RECIPIENTS_PAGE, $page, (int) $result['total'], 20, $search );
		}
		if ( is_wp_error( $editing ) ) {
			$this->error( $editing->get_error_message() );
		} elseif ( is_array( $editing ) ) {
			$this->recipient_form( $editing );
			$this->recipient_history( $repo, $editing );
		} else {
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
		$result = $repo->list_page( $search, $page, 20, $status );
		$summary = $repo->summary();
		$edit_id = isset( $_GET['program_id'] ) ? absint( $_GET['program_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin navigation.
		$editing = $edit_id ? $repo->find( $edit_id ) : null;

		$this->header( __( 'Programs', 'certloom' ), __( 'Standalone credential programs that work with or without an LMS.', 'certloom' ) );
		$this->notice();
		if ( ! is_wp_error( $summary ) ) {
			$this->metric_grid(
				array(
					array( 'value' => $summary['total'], 'label' => __( 'Programs', 'certloom' ) ),
					array( 'value' => $summary['active'], 'label' => __( 'Active', 'certloom' ) ),
					array( 'value' => $summary['inactive'], 'label' => __( 'Inactive', 'certloom' ) ),
					array( 'value' => $summary['with_credentials'], 'label' => __( 'With credentials', 'certloom' ) ),
				)
			);
		}
		$this->program_views( $status, $search );
		$this->render_search( self::PROGRAMS_PAGE, $search, __( 'Search title, code, or description', 'certloom' ), array( 'program_status' => $status ) );
		if ( is_wp_error( $result ) ) {
			$this->error( $result->get_error_message() );
		} else {
			echo '<div class="certloom-domain-table-wrap"><table class="widefat striped certloom-domain-table"><thead><tr><th>' . esc_html__( 'Program', 'certloom' ) . '</th><th>' . esc_html__( 'Code', 'certloom' ) . '</th><th>' . esc_html__( 'Issuer', 'certloom' ) . '</th><th>' . esc_html__( 'Default validity', 'certloom' ) . '</th><th>' . esc_html__( 'Credentials', 'certloom' ) . '</th><th>' . esc_html__( 'Status', 'certloom' ) . '</th><th>' . esc_html__( 'Actions', 'certloom' ) . '</th></tr></thead><tbody>';
			foreach ( $result['items'] as $item ) {
				$edit_url = add_query_arg( array( 'page' => self::PROGRAMS_PAGE, 'program_id' => absint( $item['id'] ) ), admin_url( 'admin.php' ) );
				$validity = null === $item['validity_days'] ? __( 'No default', 'certloom' ) : sprintf( /* translators: %d: days. */ _n( '%d day', '%d days', absint( $item['validity_days'] ), 'certloom' ), absint( $item['validity_days'] ) );
				echo '<tr><td><strong><a href="' . esc_url( $edit_url ) . '">' . esc_html( (string) $item['title'] ) . '</a></strong></td><td>' . ( $item['code'] ? '<code>' . esc_html( (string) $item['code'] ) . '</code>' : '—' ) . '</td><td>' . esc_html( (string) ( $item['issuer_name'] ?: '—' ) ) . '</td><td>' . esc_html( $validity ) . '</td><td><span class="certloom-domain-count">' . esc_html( (string) absint( $item['credential_count'] ) ) . '</span></td><td><span class="certloom-domain-status ' . ( $item['active'] ? 'is-active' : 'is-inactive' ) . '">' . esc_html( $item['active'] ? __( 'Active', 'certloom' ) : __( 'Inactive', 'certloom' ) ) . '</span></td><td><a class="button button-small" href="' . esc_url( $edit_url ) . '">' . esc_html__( 'Open', 'certloom' ) . '</a></td></tr>';
			}
			if ( empty( $result['items'] ) ) { echo '<tr><td colspan="7"><div class="certloom-domain-empty"><strong>' . esc_html__( 'No programs found.', 'certloom' ) . '</strong><span>' . esc_html__( 'Add a program or adjust the filters.', 'certloom' ) . '</span></div></td></tr>'; }
			echo '</tbody></table></div>';
			$this->pagination( self::PROGRAMS_PAGE, $page, (int) $result['total'], 20, $search, array( 'program_status' => $status ) );
		}
		if ( is_wp_error( $editing ) ) {
			$this->error( $editing->get_error_message() );
		} elseif ( is_array( $editing ) ) {
			$this->program_form( $editing );
			$this->program_history( $repo, $editing );
		} else {
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
		$result = $repo->list_page( $search, $page, 20 );
		$edit_id = isset( $_GET['issuer_id'] ) ? absint( $_GET['issuer_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin navigation.
		$editing = $edit_id ? $repo->find( $edit_id ) : null;

		$this->header( __( 'Issuers & brands', 'certloom' ), __( 'Manage stable issuing identities and local brand defaults without changing historical credential snapshots.', 'certloom' ) );
		$this->notice();
		$this->render_search( self::ISSUERS_PAGE, $search );
		if ( is_wp_error( $result ) ) {
			$this->error( $result->get_error_message() );
		} else {
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Issuer', 'certloom' ) . '</th><th>' . esc_html__( 'Slug', 'certloom' ) . '</th><th>' . esc_html__( 'Programs', 'certloom' ) . '</th><th>' . esc_html__( 'Signers', 'certloom' ) . '</th><th>' . esc_html__( 'Created', 'certloom' ) . '</th><th>' . esc_html__( 'Actions', 'certloom' ) . '</th></tr></thead><tbody>';
			foreach ( $result['items'] as $item ) {
				$edit_url = add_query_arg( array( 'page' => self::ISSUERS_PAGE, 'issuer_id' => absint( $item['id'] ) ), admin_url( 'admin.php' ) );
				echo '<tr><td><strong>' . esc_html( (string) $item['name'] ) . '</strong></td><td><code>' . esc_html( (string) $item['slug'] ) . '</code></td><td>' . esc_html( (string) absint( $item['program_count'] ?? 0 ) ) . '</td><td>' . esc_html( (string) absint( $item['signer_count'] ?? 0 ) ) . '</td><td>' . esc_html( (string) $item['created_at'] ) . '</td><td><a class="button button-small" href="' . esc_url( $edit_url ) . '">' . esc_html__( 'Open', 'certloom' ) . '</a></td></tr>';
			}
			if ( empty( $result['items'] ) ) { echo '<tr><td colspan="6">' . esc_html__( 'No issuers found.', 'certloom' ) . '</td></tr>'; }
			echo '</tbody></table>';
			$this->pagination( self::ISSUERS_PAGE, $page, (int) $result['total'], 20, $search );
		}
		if ( is_wp_error( $editing ) ) {
			$this->error( $editing->get_error_message() );
		} elseif ( is_array( $editing ) ) {
			$this->issuer_form( $editing );
		} else {
			$this->issuer_form();
		}
		$this->footer();
	}

	/** @return void */
	public function render_credentials() {
		$this->require_capability( \Ecapla_CertVerify_Permissions::CAP_VIEW_CREDENTIALS );
		$series_id = isset( $_GET['series_id'] ) ? absint( $_GET['series_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin navigation.
		if ( $series_id ) {
			$this->render_credential_detail( $series_id );
			return;
		}

		$search = $this->search_value();
		$page   = $this->page_value();
		$result = ( new CredentialRepository() )->list_page( $search, $page, 20 );
		$status_service = new StatusService();
		$this->header( __( 'Credentials — New Domain Preview', 'certloom' ) );
		$this->notice();
		$this->render_search( self::CREDENTIALS_PAGE, $search );
		if ( is_wp_error( $result ) ) {
			$this->error( $result->get_error_message() );
		} else {
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Certificate', 'certloom' ) . '</th><th>' . esc_html__( 'Recipient', 'certloom' ) . '</th><th>' . esc_html__( 'Program', 'certloom' ) . '</th><th>' . esc_html__( 'Public ID', 'certloom' ) . '</th><th>' . esc_html__( 'Status', 'certloom' ) . '</th></tr></thead><tbody>';
			foreach ( $result['items'] as $item ) {
				$status = $this->status_from_row( $status_service, $item );
				$detail_url = add_query_arg( array( 'page' => self::CREDENTIALS_PAGE, 'series_id' => absint( $item['series_id'] ) ), admin_url( 'admin.php' ) );
				echo '<tr><td><a href="' . esc_url( $detail_url ) . '">' . esc_html( (string) $item['display_number'] ) . '</a></td><td>' . esc_html( (string) $item['display_name'] ) . '</td><td>' . esc_html( (string) $item['program_title'] ) . '</td><td><code>' . esc_html( (string) $item['public_id'] ) . '</code></td><td>' . esc_html( $status ? $status->code() : __( 'Unavailable', 'certloom' ) ) . '</td></tr>';
			}
			if ( empty( $result['items'] ) ) { echo '<tr><td colspan="5">' . esc_html__( 'No new-domain credentials found.', 'certloom' ) . '</td></tr>'; }
			echo '</tbody></table>';
			$this->pagination( self::CREDENTIALS_PAGE, $page, (int) $result['total'], 20, $search );
		}
		$this->footer();
	}

	/** @return void */
	private function render_credential_detail( $series_id ) {
		$credentials = new CredentialRepository();
		$series = $credentials->find_series( $series_id );
		$this->header( __( 'Credential — New Domain Preview', 'certloom' ) );
		$this->notice();
		echo '<p><a href="' . esc_url( add_query_arg( 'page', self::CREDENTIALS_PAGE, admin_url( 'admin.php' ) ) ) . '">&larr; ' . esc_html__( 'Back to credentials', 'certloom' ) . '</a></p>';
		if ( is_wp_error( $series ) ) {
			$this->error( $series->get_error_message() );
			$this->footer();
			return;
		}
		if ( ! is_array( $series ) ) {
			$this->error( __( 'The credential could not be found.', 'certloom' ) );
			$this->footer();
			return;
		}
		$version = ! empty( $series['current_version_id'] ) ? $credentials->find_version( $series['current_version_id'] ) : null;
		if ( is_wp_error( $version ) ) {
			$this->error( $version->get_error_message() );
			$version = null;
		}
		$recipient = ( new RecipientRepository() )->find( $series['recipient_id'] );
		$program = ! empty( $series['program_id'] ) ? ( new ProgramRepository() )->find( $series['program_id'] ) : null;
		$batch = ! empty( $series['batch_id'] ) ? ( new BatchRepository() )->find( $series['batch_id'] ) : null;
		$issuer = ! empty( $series['issuer_id'] ) ? ( new IssuerRepository() )->find( $series['issuer_id'] ) : null;
		if ( is_wp_error( $recipient ) ) { $this->error( $recipient->get_error_message() ); $recipient = null; }
		if ( is_wp_error( $program ) ) { $this->error( $program->get_error_message() ); $program = null; }
		if ( is_wp_error( $batch ) ) { $this->error( $batch->get_error_message() ); $batch = null; }
		if ( is_wp_error( $issuer ) ) { $this->error( $issuer->get_error_message() ); $issuer = null; }
		$status = is_array( $version ) ? $this->status_from_row( new StatusService(), array_merge( $version, array( 'administrative_state' => $series['administrative_state'] ) ) ) : null;

		echo '<table class="widefat striped" style="max-width:900px"><tbody>';
		$this->detail_row( __( 'Public credential ID', 'certloom' ), '<code>' . esc_html( (string) $series['public_id'] ) . '</code>' );
		$this->detail_row( __( 'Certificate number', 'certloom' ), esc_html( is_array( $version ) ? (string) $version['display_number'] : '—' ) );
		$this->detail_row( __( 'Recipient', 'certloom' ), esc_html( is_array( $recipient ) ? (string) $recipient['display_name'] : '—' ) );
		$this->detail_row( __( 'Program', 'certloom' ), esc_html( is_array( $program ) ? (string) $program['title'] : '—' ) );
		$this->detail_row( __( 'Batch', 'certloom' ), esc_html( is_array( $batch ) ? (string) $batch['name'] . ' (' . (string) $batch['code'] . ')' : '—' ) );
		$this->detail_row( __( 'Issuer', 'certloom' ), esc_html( is_array( $issuer ) ? (string) $issuer['name'] : '—' ) );
		$this->detail_row( __( 'Administrative state', 'certloom' ), esc_html( (string) $series['administrative_state'] ) );
		$this->detail_row( __( 'Issuance state', 'certloom' ), esc_html( is_array( $version ) ? (string) $version['issuance_state'] : '—' ) );
		$this->detail_row( __( 'Effective status', 'certloom' ), esc_html( $status ? $status->code() : __( 'Unavailable', 'certloom' ) ) );
		$this->detail_row( __( 'Issued', 'certloom' ), esc_html( is_array( $version ) && ! empty( $version['issued_at'] ) ? (string) $version['issued_at'] : '—' ) );
		$this->detail_row( __( 'Expires', 'certloom' ), esc_html( is_array( $version ) && ! empty( $version['expires_at'] ) ? (string) $version['expires_at'] : '—' ) );
		echo '</tbody></table>';

		if ( current_user_can( \Ecapla_CertVerify_Permissions::CAP_MANAGE_CREDENTIALS ) && is_array( $version ) ) {
			$this->lifecycle_form( $series, $version );
		}
		$this->version_history( $credentials, $series_id, absint( $series['current_version_id'] ?? 0 ) );
		$this->event_history( $series_id );
		$this->footer();
	}

	/** @param array<string,mixed>|null $editing @return void */
	private function recipient_form( array $editing = null ) {
		$is_edit = null !== $editing;
		$id = $is_edit ? absint( $editing['id'] ) : 0;
		$custom_fields = $is_edit && isset( $editing['custom_fields'] ) && is_array( $editing['custom_fields'] ) ? $editing['custom_fields'] : array();
		$custom_editable = $this->is_scalar_map( $custom_fields );
		echo '<section class="ecapla-certverify-admin-card certloom-domain-editor"><div class="certloom-domain-editor-head"><div><h2>' . esc_html( $is_edit ? __( 'Recipient profile', 'certloom' ) : __( 'Add recipient', 'certloom' ) ) . '</h2><p>' . esc_html__( 'Keep recipient identity separate from credential history so renewals and future credentials share one stable recipient record.', 'certloom' ) . '</p></div>';
		if ( $is_edit ) { echo '<span class="certloom-domain-id">#' . esc_html( (string) $id ) . '</span>'; }
		echo '</div><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( $is_edit ? 'certloom_domain_update_recipient' : 'certloom_domain_create_recipient' ) . '">';
		if ( $is_edit ) { echo '<input type="hidden" name="recipient_id" value="' . esc_attr( $id ) . '">'; wp_nonce_field( 'certloom_domain_update_recipient_' . $id ); } else { wp_nonce_field( 'certloom_domain_create_recipient' ); }
		echo '<div class="certloom-domain-form-grid">';
		$this->text_field( 'display_name', __( 'Display name', 'certloom' ), $is_edit ? (string) $editing['display_name'] : '', true, __( 'The canonical name shown in administrator workflows and credential presentation.', 'certloom' ) );
		$this->text_field( 'email', __( 'Email', 'certloom' ), $is_edit ? (string) $editing['email'] : '', false, __( 'Optional. Used by later issuance and email workflows.', 'certloom' ), 'email' );
		$this->text_field( 'first_name', __( 'First name', 'certloom' ), $is_edit ? (string) $editing['first_name'] : '' );
		$this->text_field( 'middle_name', __( 'Middle name', 'certloom' ), $is_edit ? (string) $editing['middle_name'] : '' );
		$this->text_field( 'last_name', __( 'Last name', 'certloom' ), $is_edit ? (string) $editing['last_name'] : '' );
		$this->text_field( 'external_id', __( 'External ID', 'certloom' ), $is_edit ? (string) $editing['external_id'] : '', false, __( 'Optional identifier from an LMS, CRM, SIS, or other source.', 'certloom' ) );
		$this->text_field( 'wp_user_id', __( 'WordPress user ID', 'certloom' ), $is_edit && ! empty( $editing['wp_user_id'] ) ? (string) $editing['wp_user_id'] : '', false, __( 'Optional numeric WordPress user association. Leave blank for recipients without a site account.', 'certloom' ), 'number', 'min="1" step="1"' );
		echo '</div>';
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
		submit_button( $is_edit ? __( 'Update recipient', 'certloom' ) : __( 'Add recipient', 'certloom' ), 'primary' );
		echo '</form></section>';
	}

	/** @param array<string,mixed>|null $editing @return void */
	private function program_form( array $editing = null ) {
		$is_edit = null !== $editing;
		$id = $is_edit ? absint( $editing['id'] ) : 0;
		$metadata = $is_edit && isset( $editing['metadata'] ) && is_array( $editing['metadata'] ) ? $editing['metadata'] : array();
		$metadata_editable = $this->is_scalar_map( $metadata );
		echo '<section class="ecapla-certverify-admin-card certloom-domain-editor"><div class="certloom-domain-editor-head"><div><h2>' . esc_html( $is_edit ? __( 'Program profile', 'certloom' ) : __( 'Add program', 'certloom' ) ) . '</h2><p>' . esc_html__( 'Programs are credential definitions independent of LMS products. LMS integrations will map into these records later.', 'certloom' ) . '</p></div>';
		if ( $is_edit ) { echo '<span class="certloom-domain-id">#' . esc_html( (string) $id ) . '</span>'; }
		echo '</div><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( $is_edit ? 'certloom_domain_update_program' : 'certloom_domain_create_program' ) . '">';
		if ( $is_edit ) { echo '<input type="hidden" name="program_id" value="' . esc_attr( $id ) . '">'; wp_nonce_field( 'certloom_domain_update_program_' . $id ); } else { wp_nonce_field( 'certloom_domain_create_program' ); }
		echo '<div class="certloom-domain-form-grid">';
		$this->text_field( 'title', __( 'Program title', 'certloom' ), $is_edit ? (string) $editing['title'] : '', true, __( 'The human-readable credential program or course name.', 'certloom' ) );
		$this->text_field( 'code', __( 'Program code', 'certloom' ), $is_edit ? (string) $editing['code'] : '', false, __( 'Optional unique machine-friendly code used in imports and future automation.', 'certloom' ) );
		$this->text_field( 'validity_days', __( 'Default validity (days)', 'certloom' ), $is_edit && null !== $editing['validity_days'] ? (string) $editing['validity_days'] : '', false, __( 'Leave blank when the program has no default expiry. Issuance may override this later.', 'certloom' ), 'number', 'min="0" step="1"' );
		$this->issuer_field( $is_edit ? ( $editing['issuer_id'] ?? null ) : null );
		echo '<div class="certloom-domain-field certloom-domain-field-wide"><label for="certloom-program-description"><strong>' . esc_html__( 'Description', 'certloom' ) . '</strong></label><textarea id="certloom-program-description" class="large-text" rows="5" name="description">' . esc_textarea( $is_edit ? (string) $editing['description'] : '' ) . '</textarea><p class="description">' . esc_html__( 'Internal program context. Public credential copy will use explicit presentation fields rather than exposing this automatically.', 'certloom' ) . '</p></div>';
		echo '<div class="certloom-domain-field"><label><input type="checkbox" name="active" value="1" ' . checked( $is_edit ? ! empty( $editing['active'] ) : true, true, false ) . '> <strong>' . esc_html__( 'Active program', 'certloom' ) . '</strong></label><p class="description">' . esc_html__( 'Inactive programs remain in historical credentials but should not be offered for new issuance.', 'certloom' ) . '</p></div>';
		echo '</div>';
		echo '<div class="certloom-domain-subsection"><div class="certloom-domain-subsection-head"><div><h3>' . esc_html__( 'Program metadata', 'certloom' ) . '</h3><p>' . esc_html__( 'Optional local key/value metadata for future automation and integrations. Template and signer assignment remain their dedicated roadmap features.', 'certloom' ) . '</p></div></div>';
		if ( $metadata_editable ) {
			echo '<input type="hidden" name="program_metadata_present" value="1"><div class="certloom-domain-kv-list" data-certloom-kv-list="program">';
			$rows = $metadata ?: array( '' => '' );
			foreach ( $rows as $key => $value ) { $this->key_value_row( 'program_meta_key[]', 'program_meta_value[]', (string) $key, (string) $value ); }
			echo '</div><button type="button" class="button certloom-domain-add-kv" data-certloom-add-kv="program">' . esc_html__( 'Add metadata field', 'certloom' ) . '</button>';
		} else {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'This program contains advanced structured metadata. Certloom will preserve it unchanged until a dedicated editor can represent it safely.', 'certloom' ) . '</p></div>';
		}
		echo '</div>';
		submit_button( $is_edit ? __( 'Update program', 'certloom' ) : __( 'Add program', 'certloom' ), 'primary' );
		echo '</form></section>';
	}


	/** @param array<string,mixed>|null $editing @return void */
	private function issuer_form( array $editing = null ) {
		$is_edit = null !== $editing; $id = $is_edit ? absint( $editing['id'] ) : 0;
		$settings = $is_edit && isset( $editing['settings'] ) && is_array( $editing['settings'] ) ? $editing['settings'] : array();
		echo '<section class="ecapla-certverify-admin-card certloom-domain-editor"><div class="certloom-domain-editor-head"><div><h2>' . esc_html( $is_edit ? __( 'Issuer brand profile', 'certloom' ) : __( 'Add issuer / brand', 'certloom' ) ) . '</h2><p>' . esc_html__( 'Brand defaults are local configuration. Issued credential Versions preserve their own immutable snapshot.', 'certloom' ) . '</p></div></div><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( $is_edit ? 'certloom_domain_update_issuer' : 'certloom_domain_create_issuer' ) . '">';
		if ( $is_edit ) { echo '<input type="hidden" name="issuer_id" value="' . esc_attr( $id ) . '">'; wp_nonce_field( 'certloom_domain_update_issuer_' . $id ); } else { wp_nonce_field( 'certloom_domain_create_issuer' ); }
		echo '<div class="certloom-domain-form-grid">';
		$this->text_field( 'name', __( 'Organization / issuer name', 'certloom' ), $is_edit ? (string) $editing['name'] : '', true );
		$this->text_field( 'slug', __( 'Machine slug', 'certloom' ), $is_edit ? (string) $editing['slug'] : '', true, __( 'Stable identity used by restores, assignments, integrations, and future APIs.', 'certloom' ) );
		$this->text_field( 'website', __( 'Website', 'certloom' ), (string) ( $settings['website'] ?? '' ), false, '', 'url' );
		$this->text_field( 'contact_name', __( 'Contact name', 'certloom' ), (string) ( $settings['contact_name'] ?? '' ) );
		$this->text_field( 'contact_email', __( 'Contact email', 'certloom' ), (string) ( $settings['contact_email'] ?? '' ), false, '', 'email' );
		$this->text_field( 'certificate_prefix', __( 'Certificate prefix', 'certloom' ), (string) ( $settings['certificate_prefix'] ?? '' ), false, __( 'A future numbering policy may use this as an issuer default; it does not rewrite existing numbers.', 'certloom' ) );
		$this->text_field( 'primary_color', __( 'Primary color', 'certloom' ), (string) ( $settings['primary_color'] ?? '' ), false, __( 'Hex color such as #183153.', 'certloom' ) );
		$this->text_field( 'accent_color', __( 'Accent color', 'certloom' ), (string) ( $settings['accent_color'] ?? '' ), false, __( 'Hex color such as #C89B3C.', 'certloom' ) );
		$this->text_field( 'logo_attachment_id', __( 'Logo media ID', 'certloom' ), $is_edit ? (string) ( $editing['logo_attachment_id'] ?: '' ) : '', false, __( 'WordPress Media attachment ID. The renderer will validate actual asset bytes when artifacts are generated.', 'certloom' ), 'number', 'min="1"' );
		$this->text_field( 'seal_attachment_id', __( 'Seal media ID', 'certloom' ), $is_edit ? (string) ( $editing['seal_attachment_id'] ?: '' ) : '', false, '', 'number', 'min="1"' );
		echo '<div class="certloom-domain-field certloom-domain-field-wide"><label for="certloom-domain-address"><strong>' . esc_html__( 'Address', 'certloom' ) . '</strong></label><textarea id="certloom-domain-address" class="large-text" rows="3" name="address">' . esc_textarea( (string) ( $settings['address'] ?? '' ) ) . '</textarea></div>';
		echo '</div><p class="description">' . esc_html__( 'Signer profiles are assigned separately so the same person can serve at global, issuer, program, or batch scope without duplicating identity data.', 'certloom' ) . '</p>';
		submit_button( $is_edit ? __( 'Update issuer', 'certloom' ) : __( 'Add issuer', 'certloom' ), 'primary' ); echo '</form></section>';
	}

	/** @param array<string,mixed> $series @param array<string,mixed> $version @return void */
	private function lifecycle_form( array $series, array $version ) {
		$series_id = absint( $series['id'] );
		echo '<hr><h2>' . esc_html__( 'Lifecycle action', 'certloom' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'This preview routes every state change through the transaction-backed lifecycle service and writes an audit event. It does not directly edit state columns.', 'certloom' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
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
		foreach ( $versions as $v ) { $lineage = ! empty( $v['previous_version_id'] ) ? sprintf( __( 'Previous #%d', 'certloom' ), absint( $v['previous_version_id'] ) ) : __( 'Origin', 'certloom' ); if ( absint( $v['id'] ) === absint( $current_version_id ) ) { $lineage .= ' · ' . __( 'Current', 'certloom' ); } echo '<tr><td><strong>#' . esc_html( (string) absint( $v['version_number'] ) ) . '</strong></td><td>' . esc_html( (string) $v['display_number'] ) . '</td><td>' . esc_html( (string) $v['issuance_state'] ) . '</td><td>' . esc_html( (string) ( $v['issued_at'] ?: '—' ) ) . '</td><td>' . esc_html( (string) ( $v['expires_at'] ?: '—' ) ) . '</td><td>' . esc_html( $lineage ) . '</td></tr>'; }
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
		echo '<section class="ecapla-certverify-admin-card certloom-domain-history"><div class="certloom-domain-subsection-head"><div><h2>' . esc_html__( 'Credential history', 'certloom' ) . '</h2><p>' . esc_html__( 'Current credential identities linked to this recipient. Historical Version lineage remains immutable inside each Credential Series.', 'certloom' ) . '</p></div></div>';
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
		$status_service = new StatusService();
		echo '<div class="certloom-domain-table-wrap"><table class="widefat striped certloom-domain-table"><thead><tr><th>' . esc_html__( 'Certificate', 'certloom' ) . '</th><th>' . esc_html( 'program_title' === $subject_key ? __( 'Program', 'certloom' ) : __( 'Recipient', 'certloom' ) ) . '</th><th>' . esc_html__( 'Public ID', 'certloom' ) . '</th><th>' . esc_html__( 'Issued', 'certloom' ) . '</th><th>' . esc_html__( 'Expires', 'certloom' ) . '</th><th>' . esc_html__( 'Status', 'certloom' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$status = $this->status_from_row( $status_service, $row );
			$credential_url = add_query_arg( array( 'page' => self::CREDENTIALS_PAGE, 'series_id' => absint( $row['series_id'] ) ), admin_url( 'admin.php' ) );
			echo '<tr><td><a href="' . esc_url( $credential_url ) . '">' . esc_html( (string) ( $row['display_number'] ?: '—' ) ) . '</a></td><td>' . esc_html( (string) ( $row[ $subject_key ] ?: '—' ) ) . '</td><td><code>' . esc_html( (string) $row['public_id'] ) . '</code></td><td>' . esc_html( (string) ( $row['issued_at'] ?: '—' ) ) . '</td><td>' . esc_html( (string) ( $row['expires_at'] ?: '—' ) ) . '</td><td><span class="certloom-domain-status">' . esc_html( $status ? $status->code() : __( 'Unavailable', 'certloom' ) ) . '</span></td></tr>';
		}
		if ( empty( $rows ) ) { echo '<tr><td colspan="6"><div class="certloom-domain-empty"><strong>' . esc_html__( 'No linked credentials yet.', 'certloom' ) . '</strong></div></td></tr>'; }
		echo '</tbody></table></div>';
	}

	/** @param array<string,mixed>|null $existing @return array<string,mixed>|\WP_Error */
	private function recipient_request( array $existing = null ) {
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
	private function program_request( array $existing = null ) {
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
		if ( isset( $_POST['program_metadata_present'] ) ) {
			$parsed = $this->parse_key_value_request( 'program_meta_key', 'program_meta_value', 30 );
			if ( is_wp_error( $parsed ) ) { return $parsed; }
			$data['metadata'] = $parsed;
		} elseif ( null === $existing ) {
			$data['metadata'] = array();
		}
		return $data;
	}


	/** @return array<string,mixed>|\WP_Error */
	private function issuer_request( array $existing = null ) {
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
		return array(
			'name' => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
			'slug' => isset( $_POST['slug'] ) ? sanitize_title( wp_unslash( $_POST['slug'] ) ) : '',
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

	/** @return void */
	private function header( $title, $subtitle = '' ) {
		echo '<div class="wrap ecapla-certverify-admin-wrap certloom-admin-shell certloom-domain-screen"><div class="certloom-domain-title-row"><div><h1>' . esc_html( $title ) . '</h1>';
		if ( '' !== (string) $subtitle ) { echo '<p class="certloom-domain-lead">' . esc_html( $subtitle ) . '</p>'; }
		echo '</div><span class="certloom-domain-preview-badge">' . esc_html__( 'Acceptance preview', 'certloom' ) . '</span></div>';
	}

	/** @return void */
	private function footer() { echo '</div>'; }

	/** @return void */
	private function notice() {
		echo '<div class="notice notice-warning inline certloom-domain-gate-notice"><p><strong>' . esc_html__( 'Safety gate active.', 'certloom' ) . '</strong> ' . esc_html__( 'These normalized-domain screens are available only when the explicit admin-preview gate is enabled. The proven legacy registry remains production authority until real migration, restore, cutover, and database acceptance pass.', 'certloom' ) . '</p></div>';
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

	/** @return void */
	private function program_views( $current, $search ) {
		echo '<nav class="certloom-domain-views" aria-label="' . esc_attr__( 'Program status filters', 'certloom' ) . '">';
		foreach ( array( 'all' => __( 'All', 'certloom' ), 'active' => __( 'Active', 'certloom' ), 'inactive' => __( 'Inactive', 'certloom' ) ) as $key => $label ) {
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
		echo '<label class="screen-reader-text" for="' . esc_attr( $id ) . '">' . esc_html__( 'Search', 'certloom' ) . '</label><input id="' . esc_attr( $id ) . '" type="search" name="s" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr( $placeholder ) . '"><button class="button">' . esc_html__( 'Search', 'certloom' ) . '</button>';
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
	private function text_field( $name, $label, $value, $required = false, $description = '', $type = 'text', $attributes = '' ) {
		$id = 'certloom-domain-' . sanitize_html_class( $name );
		echo '<div class="certloom-domain-field"><label for="' . esc_attr( $id ) . '"><strong>' . esc_html( $label ) . '</strong></label><input id="' . esc_attr( $id ) . '" class="regular-text" type="' . esc_attr( $type ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" ' . ( $required ? 'required ' : '' ) . wp_kses_post( $attributes ) . '>';
		if ( '' !== $description ) { echo '<p class="description">' . esc_html( $description ) . '</p>'; }
		echo '</div>';
	}

	/** @return void */
	private function issuer_field( $selected ) {
		$result = ( new IssuerRepository() )->list_page( '', 1, 100 );
		echo '<div class="certloom-domain-field"><label for="certloom-domain-issuer-id"><strong>' . esc_html__( 'Default issuer', 'certloom' ) . '</strong></label><select id="certloom-domain-issuer-id" name="issuer_id"><option value="">' . esc_html__( 'No default issuer', 'certloom' ) . '</option>';
		if ( ! is_wp_error( $result ) ) {
			foreach ( $result['items'] as $issuer ) { echo '<option value="' . esc_attr( (string) absint( $issuer['id'] ) ) . '" ' . selected( absint( $selected ), absint( $issuer['id'] ), false ) . '>' . esc_html( (string) $issuer['name'] ) . '</option>'; }
		}
		echo '</select><p class="description">' . esc_html__( 'Optional. Full multi-brand policy remains ORG-01; this stores only the normalized issuer relationship already present in the domain.', 'certloom' ) . '</p></div>';
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
			if ( $user ) { return sprintf( '%1$s (#%2$d)', (string) $user->display_name, $user_id ); }
		}
		return sprintf( /* translators: %d: WordPress user ID. */ __( '#%d (missing)', 'certloom' ), $user_id );
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
