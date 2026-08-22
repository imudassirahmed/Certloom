<?php
namespace Certloom\Infrastructure\Admin;

use Certloom\Application\CredentialLifecycleService;
use Certloom\Domain\Credential\States;
use Certloom\Domain\Credential\StatusService;
use Certloom\Infrastructure\Repository\CredentialRepository;
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
		add_submenu_page( 'certloom', __( 'Recipients (New Domain)', 'certloom' ), __( 'Recipients (New)', 'certloom' ), \Ecapla_CertVerify_Permissions::CAP_MANAGE_RECIPIENTS, self::RECIPIENTS_PAGE, array( $this, 'render_recipients' ) );
		add_submenu_page( 'certloom', __( 'Programs (New Domain)', 'certloom' ), __( 'Programs (New)', 'certloom' ), \Ecapla_CertVerify_Permissions::CAP_MANAGE_PROGRAMS, self::PROGRAMS_PAGE, array( $this, 'render_programs' ) );
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
		$data = $this->recipient_request();
		if ( is_wp_error( $data ) ) {
			$this->redirect_result( self::RECIPIENTS_PAGE, $data, 'recipient_updated', array( 'recipient_id' => $id ) );
		}
		$result = ( new RecipientRepository() )->update( $id, $data );
		$this->redirect_result( self::RECIPIENTS_PAGE, $result, 'recipient_updated', array( 'recipient_id' => $id ) );
	}

	/** @return void */
	public function create_program() {
		$this->require_capability( \Ecapla_CertVerify_Permissions::CAP_MANAGE_PROGRAMS );
		check_admin_referer( 'certloom_domain_create_program' );
		$result = ( new ProgramRepository() )->create( $this->program_request() );
		$this->redirect_result( self::PROGRAMS_PAGE, $result, 'program_created' );
	}

	/** @return void */
	public function update_program() {
		$this->require_capability( \Ecapla_CertVerify_Permissions::CAP_MANAGE_PROGRAMS );
		$id = isset( $_POST['program_id'] ) ? absint( $_POST['program_id'] ) : 0;
		check_admin_referer( 'certloom_domain_update_program_' . $id );
		$result = ( new ProgramRepository() )->update( $id, $this->program_request() );
		$this->redirect_result( self::PROGRAMS_PAGE, $result, 'program_updated', array( 'program_id' => $id ) );
	}


	/** @return void */
	public function create_issuer() {
		$this->require_capability( \Ecapla_CertVerify_Permissions::CAP_MANAGE_ISSUERS );
		check_admin_referer( 'certloom_domain_create_issuer' );
		$result = ( new IssuerRepository() )->create( $this->issuer_request() );
		$this->redirect_result( self::ISSUERS_PAGE, $result, 'issuer_created' );
	}

	/** @return void */
	public function update_issuer() {
		$this->require_capability( \Ecapla_CertVerify_Permissions::CAP_MANAGE_ISSUERS );
		$id = isset( $_POST['issuer_id'] ) ? absint( $_POST['issuer_id'] ) : 0;
		check_admin_referer( 'certloom_domain_update_issuer_' . $id );
		$result = ( new IssuerRepository() )->update( $id, $this->issuer_request() );
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
		$edit_id = isset( $_GET['recipient_id'] ) ? absint( $_GET['recipient_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin navigation.
		$editing = $edit_id ? $repo->find( $edit_id ) : null;

		$this->header( __( 'Recipients — New Domain Preview', 'certloom' ) );
		$this->notice();
		$this->render_search( self::RECIPIENTS_PAGE, $search );
		if ( is_wp_error( $result ) ) {
			$this->error( $result->get_error_message() );
		} else {
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Name', 'certloom' ) . '</th><th>' . esc_html__( 'Email', 'certloom' ) . '</th><th>' . esc_html__( 'External ID', 'certloom' ) . '</th><th>' . esc_html__( 'Created', 'certloom' ) . '</th><th>' . esc_html__( 'Actions', 'certloom' ) . '</th></tr></thead><tbody>';
			foreach ( $result['items'] as $item ) {
				$edit_url = add_query_arg( array( 'page' => self::RECIPIENTS_PAGE, 'recipient_id' => absint( $item['id'] ) ), admin_url( 'admin.php' ) );
				echo '<tr><td>' . esc_html( $item['display_name'] ) . '</td><td>' . esc_html( (string) $item['email'] ) . '</td><td>' . esc_html( (string) $item['external_id'] ) . '</td><td>' . esc_html( (string) $item['created_at'] ) . '</td><td><a href="' . esc_url( $edit_url ) . '">' . esc_html__( 'Edit', 'certloom' ) . '</a></td></tr>';
			}
			if ( empty( $result['items'] ) ) { echo '<tr><td colspan="5">' . esc_html__( 'No recipients found.', 'certloom' ) . '</td></tr>'; }
			echo '</tbody></table>';
			$this->pagination( self::RECIPIENTS_PAGE, $page, (int) $result['total'], 20, $search );
		}
		if ( is_wp_error( $editing ) ) {
			$this->error( $editing->get_error_message() );
		} elseif ( is_array( $editing ) ) {
			$this->recipient_form( $editing );
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
		$repo   = new ProgramRepository();
		$result = $repo->list_page( $search, $page, 20 );
		$edit_id = isset( $_GET['program_id'] ) ? absint( $_GET['program_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin navigation.
		$editing = $edit_id ? $repo->find( $edit_id ) : null;

		$this->header( __( 'Programs — New Domain Preview', 'certloom' ) );
		$this->notice();
		$this->render_search( self::PROGRAMS_PAGE, $search );
		if ( is_wp_error( $result ) ) {
			$this->error( $result->get_error_message() );
		} else {
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Code', 'certloom' ) . '</th><th>' . esc_html__( 'Program', 'certloom' ) . '</th><th>' . esc_html__( 'Validity days', 'certloom' ) . '</th><th>' . esc_html__( 'Active', 'certloom' ) . '</th><th>' . esc_html__( 'Actions', 'certloom' ) . '</th></tr></thead><tbody>';
			foreach ( $result['items'] as $item ) {
				$edit_url = add_query_arg( array( 'page' => self::PROGRAMS_PAGE, 'program_id' => absint( $item['id'] ) ), admin_url( 'admin.php' ) );
				echo '<tr><td>' . esc_html( (string) $item['code'] ) . '</td><td>' . esc_html( $item['title'] ) . '</td><td>' . esc_html( null === $item['validity_days'] ? '—' : (string) $item['validity_days'] ) . '</td><td>' . esc_html( $item['active'] ? __( 'Yes', 'certloom' ) : __( 'No', 'certloom' ) ) . '</td><td><a href="' . esc_url( $edit_url ) . '">' . esc_html__( 'Edit', 'certloom' ) . '</a></td></tr>';
			}
			if ( empty( $result['items'] ) ) { echo '<tr><td colspan="5">' . esc_html__( 'No programs found.', 'certloom' ) . '</td></tr>'; }
			echo '</tbody></table>';
			$this->pagination( self::PROGRAMS_PAGE, $page, (int) $result['total'], 20, $search );
		}
		if ( is_wp_error( $editing ) ) {
			$this->error( $editing->get_error_message() );
		} elseif ( is_array( $editing ) ) {
			$this->program_form( $editing );
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

		$this->header( __( 'Issuers — New Domain Preview', 'certloom' ) );
		$this->notice();
		$this->render_search( self::ISSUERS_PAGE, $search );
		if ( is_wp_error( $result ) ) {
			$this->error( $result->get_error_message() );
		} else {
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Issuer', 'certloom' ) . '</th><th>' . esc_html__( 'Slug', 'certloom' ) . '</th><th>' . esc_html__( 'Created', 'certloom' ) . '</th><th>' . esc_html__( 'Actions', 'certloom' ) . '</th></tr></thead><tbody>';
			foreach ( $result['items'] as $item ) {
				$edit_url = add_query_arg( array( 'page' => self::ISSUERS_PAGE, 'issuer_id' => absint( $item['id'] ) ), admin_url( 'admin.php' ) );
				echo '<tr><td>' . esc_html( (string) $item['name'] ) . '</td><td><code>' . esc_html( (string) $item['slug'] ) . '</code></td><td>' . esc_html( (string) $item['created_at'] ) . '</td><td><a href="' . esc_url( $edit_url ) . '">' . esc_html__( 'Edit', 'certloom' ) . '</a></td></tr>';
			}
			if ( empty( $result['items'] ) ) { echo '<tr><td colspan="4">' . esc_html__( 'No issuers found.', 'certloom' ) . '</td></tr>'; }
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
		$status = is_array( $version ) ? $this->status_from_row( new StatusService(), array_merge( $version, array( 'administrative_state' => $series['administrative_state'] ) ) ) : null;

		echo '<table class="widefat striped" style="max-width:900px"><tbody>';
		$this->detail_row( __( 'Public credential ID', 'certloom' ), '<code>' . esc_html( (string) $series['public_id'] ) . '</code>' );
		$this->detail_row( __( 'Certificate number', 'certloom' ), esc_html( is_array( $version ) ? (string) $version['display_number'] : '—' ) );
		$this->detail_row( __( 'Recipient', 'certloom' ), esc_html( is_array( $recipient ) ? (string) $recipient['display_name'] : '—' ) );
		$this->detail_row( __( 'Program', 'certloom' ), esc_html( is_array( $program ) ? (string) $program['title'] : '—' ) );
		$this->detail_row( __( 'Administrative state', 'certloom' ), esc_html( (string) $series['administrative_state'] ) );
		$this->detail_row( __( 'Issuance state', 'certloom' ), esc_html( is_array( $version ) ? (string) $version['issuance_state'] : '—' ) );
		$this->detail_row( __( 'Effective status', 'certloom' ), esc_html( $status ? $status->code() : __( 'Unavailable', 'certloom' ) ) );
		$this->detail_row( __( 'Issued', 'certloom' ), esc_html( is_array( $version ) && ! empty( $version['issued_at'] ) ? (string) $version['issued_at'] : '—' ) );
		$this->detail_row( __( 'Expires', 'certloom' ), esc_html( is_array( $version ) && ! empty( $version['expires_at'] ) ? (string) $version['expires_at'] : '—' ) );
		echo '</tbody></table>';

		if ( current_user_can( \Ecapla_CertVerify_Permissions::CAP_MANAGE_CREDENTIALS ) && is_array( $version ) ) {
			$this->lifecycle_form( $series, $version );
		}
		$this->event_history( $series_id );
		$this->footer();
	}

	/** @param array<string,mixed>|null $editing @return void */
	private function recipient_form( array $editing = null ) {
		$is_edit = null !== $editing;
		$id = $is_edit ? absint( $editing['id'] ) : 0;
		echo '<hr><h2>' . esc_html( $is_edit ? __( 'Edit recipient', 'certloom' ) : __( 'Add recipient', 'certloom' ) ) . '</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( $is_edit ? 'certloom_domain_update_recipient' : 'certloom_domain_create_recipient' ) . '">';
		if ( $is_edit ) { echo '<input type="hidden" name="recipient_id" value="' . esc_attr( $id ) . '">'; wp_nonce_field( 'certloom_domain_update_recipient_' . $id ); } else { wp_nonce_field( 'certloom_domain_create_recipient' ); }
		echo '<p><label>' . esc_html__( 'Display name', 'certloom' ) . '<br><input class="regular-text" name="display_name" required value="' . esc_attr( $is_edit ? (string) $editing['display_name'] : '' ) . '"></label></p>';
		echo '<p><label>' . esc_html__( 'Email', 'certloom' ) . '<br><input class="regular-text" type="email" name="email" value="' . esc_attr( $is_edit ? (string) $editing['email'] : '' ) . '"></label></p>';
		echo '<p><label>' . esc_html__( 'External ID', 'certloom' ) . '<br><input class="regular-text" name="external_id" value="' . esc_attr( $is_edit ? (string) $editing['external_id'] : '' ) . '"></label></p>';
		submit_button( $is_edit ? __( 'Update recipient', 'certloom' ) : __( 'Add recipient', 'certloom' ) );
		echo '</form>';
	}

	/** @param array<string,mixed>|null $editing @return void */
	private function program_form( array $editing = null ) {
		$is_edit = null !== $editing;
		$id = $is_edit ? absint( $editing['id'] ) : 0;
		echo '<hr><h2>' . esc_html( $is_edit ? __( 'Edit program', 'certloom' ) : __( 'Add program', 'certloom' ) ) . '</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( $is_edit ? 'certloom_domain_update_program' : 'certloom_domain_create_program' ) . '">';
		if ( $is_edit ) { echo '<input type="hidden" name="program_id" value="' . esc_attr( $id ) . '">'; wp_nonce_field( 'certloom_domain_update_program_' . $id ); } else { wp_nonce_field( 'certloom_domain_create_program' ); }
		echo '<p><label>' . esc_html__( 'Program title', 'certloom' ) . '<br><input class="regular-text" name="title" required value="' . esc_attr( $is_edit ? (string) $editing['title'] : '' ) . '"></label></p>';
		echo '<p><label>' . esc_html__( 'Code', 'certloom' ) . '<br><input class="regular-text" name="code" value="' . esc_attr( $is_edit ? (string) $editing['code'] : '' ) . '"></label></p>';
		echo '<p><label>' . esc_html__( 'Validity days', 'certloom' ) . '<br><input type="number" min="0" name="validity_days" value="' . esc_attr( $is_edit && null !== $editing['validity_days'] ? (string) $editing['validity_days'] : '' ) . '"></label></p>';
		echo '<p><label><input type="checkbox" name="active" value="1" ' . checked( $is_edit ? ! empty( $editing['active'] ) : true, true, false ) . '> ' . esc_html__( 'Active program', 'certloom' ) . '</label></p>';
		submit_button( $is_edit ? __( 'Update program', 'certloom' ) : __( 'Add program', 'certloom' ) );
		echo '</form>';
	}


	/** @param array<string,mixed>|null $editing @return void */
	private function issuer_form( array $editing = null ) {
		$is_edit = null !== $editing;
		$id = $is_edit ? absint( $editing['id'] ) : 0;
		echo '<hr><h2>' . esc_html( $is_edit ? __( 'Edit issuer', 'certloom' ) : __( 'Add issuer', 'certloom' ) ) . '</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( $is_edit ? 'certloom_domain_update_issuer' : 'certloom_domain_create_issuer' ) . '">';
		if ( $is_edit ) { echo '<input type="hidden" name="issuer_id" value="' . esc_attr( $id ) . '">'; wp_nonce_field( 'certloom_domain_update_issuer_' . $id ); } else { wp_nonce_field( 'certloom_domain_create_issuer' ); }
		echo '<p><label>' . esc_html__( 'Issuer name', 'certloom' ) . '<br><input class="regular-text" name="name" required value="' . esc_attr( $is_edit ? (string) $editing['name'] : '' ) . '"></label></p>';
		echo '<p><label>' . esc_html__( 'Slug', 'certloom' ) . '<br><input class="regular-text" name="slug" value="' . esc_attr( $is_edit ? (string) $editing['slug'] : '' ) . '"></label></p>';
		echo '<p class="description">' . esc_html__( 'Brand assets, signer profiles, colors, and multi-brand policy remain later issuer-management work. This preview establishes safe identity/PATCH behavior first.', 'certloom' ) . '</p>';
		submit_button( $is_edit ? __( 'Update issuer', 'certloom' ) : __( 'Add issuer', 'certloom' ) );
		echo '</form>';
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
	private function event_history( $series_id ) {
		$events = ( new EventRepository() )->list_for_series( $series_id, 100 );
		echo '<hr><h2>' . esc_html__( 'Credential history', 'certloom' ) . '</h2>';
		if ( is_wp_error( $events ) ) {
			$this->error( $events->get_error_message() );
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Time (UTC)', 'certloom' ) . '</th><th>' . esc_html__( 'Event', 'certloom' ) . '</th><th>' . esc_html__( 'Reason', 'certloom' ) . '</th><th>' . esc_html__( 'Actor', 'certloom' ) . '</th></tr></thead><tbody>';
		foreach ( $events as $event ) {
			echo '<tr><td>' . esc_html( (string) $event['created_at'] ) . '</td><td><code>' . esc_html( (string) $event['event_type'] ) . '</code></td><td>' . esc_html( (string) ( $event['public_reason'] ?: $event['reason_code'] ) ) . '</td><td>' . esc_html( $event['actor_user_id'] ? '#' . (string) $event['actor_user_id'] : '—' ) . '</td></tr>';
		}
		if ( empty( $events ) ) { echo '<tr><td colspan="4">' . esc_html__( 'No lifecycle events recorded.', 'certloom' ) . '</td></tr>'; }
		echo '</tbody></table>';
	}

	/** @return array<string,mixed>|\WP_Error */
	private function recipient_request() {
		$raw_email = isset( $_POST['email'] ) ? trim( (string) wp_unslash( $_POST['email'] ) ) : '';
		$email = '' !== $raw_email ? sanitize_email( $raw_email ) : '';
		if ( '' !== $raw_email && '' === $email ) {
			return new \WP_Error( 'certloom_recipient_email_invalid', __( 'Enter a valid recipient email address or leave it blank.', 'certloom' ) );
		}
		return array(
			'display_name' => isset( $_POST['display_name'] ) ? sanitize_text_field( wp_unslash( $_POST['display_name'] ) ) : '',
			'email'        => $email,
			'external_id'  => isset( $_POST['external_id'] ) ? sanitize_text_field( wp_unslash( $_POST['external_id'] ) ) : '',
		);
	}

	/** @return array<string,mixed> */
	private function program_request() {
		return array(
			'code'          => isset( $_POST['code'] ) ? sanitize_text_field( wp_unslash( $_POST['code'] ) ) : '',
			'title'         => isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '',
			'validity_days' => isset( $_POST['validity_days'] ) && '' !== (string) $_POST['validity_days'] ? absint( $_POST['validity_days'] ) : null,
			'active'        => isset( $_POST['active'] ),
		);
	}


	/** @return array<string,mixed> */
	private function issuer_request() {
		return array(
			'name' => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
			'slug' => isset( $_POST['slug'] ) ? sanitize_title( wp_unslash( $_POST['slug'] ) ) : '',
		);
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
	private function header( $title ) {
		echo '<div class="wrap"><h1>' . esc_html( $title ) . '</h1>';
	}

	/** @return void */
	private function footer() { echo '</div>'; }

	/** @return void */
	private function notice() {
		echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Development preview only. The legacy 1.5 registry remains authoritative until migration, new-admin, data-tool, and database gates pass.', 'certloom' ) . '</p></div>';
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

	/** @return void */
	private function render_search( $page_slug, $search ) {
		echo '<form method="get"><input type="hidden" name="page" value="' . esc_attr( $page_slug ) . '"><p class="search-box"><label class="screen-reader-text" for="certloom-domain-search">' . esc_html__( 'Search', 'certloom' ) . '</label><input id="certloom-domain-search" type="search" name="s" value="' . esc_attr( $search ) . '"><button class="button">' . esc_html__( 'Search', 'certloom' ) . '</button></p></form><div class="clear"></div>';
	}

	/** @return void */
	private function pagination( $page_slug, $page, $total, $per_page, $search ) {
		$total_pages = max( 1, (int) ceil( $total / $per_page ) );
		if ( $total_pages <= 1 ) { return; }
		$base = add_query_arg( array( 'page' => $page_slug, 's' => $search, 'paged' => '%#%' ), admin_url( 'admin.php' ) );
		echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( paginate_links( array( 'base' => $base, 'format' => '', 'current' => $page, 'total' => $total_pages ) ) ) . '</div></div>';
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
