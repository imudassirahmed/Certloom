<?php
namespace Certloom\Infrastructure\Admin;

use Certloom\Application\Fields\ScopedFieldContract;
use Certloom\Application\Issuance\ManualIssuanceService;
use Certloom\Domain\Rendering\TableDataContract;
use Certloom\Infrastructure\Email\EmailDesignPurposes;
use Certloom\Infrastructure\Email\EmailTemplateCatalogRepository;
use Certloom\Infrastructure\Issuance\ManualIssuanceNumberAllocator;
use Certloom\Infrastructure\Repository\BatchRepository;
use Certloom\Infrastructure\Repository\IssuerRepository;
use Certloom\Infrastructure\Repository\ProgramRepository;
use Certloom\Infrastructure\Repository\RecipientRepository;
use Certloom\Infrastructure\Repository\ScopedFieldGlobalRepository;
use Certloom\Infrastructure\Repository\SignerRepository;
use Certloom\Infrastructure\Repository\TemplateRepository;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * REST application boundary for the Issue Credential workspace.
 *
 * This controller owns only the administrator experience. Canonical issuance
 * remains ManualIssuanceService, which revalidates Review fingerprints and
 * commits the immutable Recipient/Series/Version lifecycle transaction.
 */
final class IssueCredentialRest {
	const NAMESPACE = 'certloom/v1';
	const BASE      = '/admin/issue';

	/** @return void */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	/** @return void */
	public function routes() {
		register_rest_route( self::NAMESPACE, self::BASE . '/bootstrap', array(
			'methods' => \WP_REST_Server::READABLE,
			'callback' => array( $this, 'bootstrap' ),
			'permission_callback' => array( $this, 'permission' ),
			'args' => array(
				'recipient_id' => array( 'default' => 0, 'sanitize_callback' => 'absint' ),
				'program_id'   => array( 'default' => 0, 'sanitize_callback' => 'absint' ),
				'batch_id'     => array( 'default' => 0, 'sanitize_callback' => 'absint' ),
			),
		) );

		register_rest_route( self::NAMESPACE, self::BASE . '/program-context', array(
			'methods' => \WP_REST_Server::READABLE,
			'callback' => array( $this, 'program_context' ),
			'permission_callback' => array( $this, 'permission' ),
			'args' => array(
				'program_id' => array( 'required' => true, 'sanitize_callback' => 'absint' ),
				'batch_id'   => array( 'default' => 0, 'sanitize_callback' => 'absint' ),
			),
		) );

		register_rest_route( self::NAMESPACE, self::BASE . '/quick-program', array(
			'methods' => \WP_REST_Server::CREATABLE,
			'callback' => array( $this, 'quick_program' ),
			'permission_callback' => array( $this, 'permission' ),
		) );

		register_rest_route( self::NAMESPACE, self::BASE . '/quick-batch', array(
			'methods' => \WP_REST_Server::CREATABLE,
			'callback' => array( $this, 'quick_batch' ),
			'permission_callback' => array( $this, 'permission' ),
		) );

		register_rest_route( self::NAMESPACE, self::BASE . '/review', array(
			'methods' => \WP_REST_Server::CREATABLE,
			'callback' => array( $this, 'review' ),
			'permission_callback' => array( $this, 'permission' ),
		) );

		register_rest_route( self::NAMESPACE, self::BASE . '/issue', array(
			'methods' => \WP_REST_Server::CREATABLE,
			'callback' => array( $this, 'issue' ),
			'permission_callback' => array( $this, 'permission' ),
		) );

		register_rest_route( self::NAMESPACE, self::BASE . '/receipt/(?P<version_id>\\d+)', array(
			'methods' => \WP_REST_Server::READABLE,
			'callback' => array( $this, 'receipt' ),
			'permission_callback' => array( $this, 'permission' ),
			'args' => array( 'version_id' => array( 'required' => true, 'sanitize_callback' => 'absint' ) ),
		) );
	}

	/** @return bool */
	public function permission() {
		if ( ! is_user_logged_in() ) { return false; }
		if ( current_user_can( \Ecapla_CertVerify_Permissions::CAP_ISSUE_CREDENTIALS ) ) { return true; }
		return current_user_can( \Ecapla_CertVerify_Permissions::CAP_MANAGE_CREDENTIALS );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function bootstrap( \WP_REST_Request $request ) {
		$profile = UsageProfilePresentation::active_profile();
		$terms = UsageProfilePresentation::terms( $profile );
		$issuance = UsageProfilePresentation::issuance_contract( $profile );
		$recipient_id = absint( $request->get_param( 'recipient_id' ) );
		$program_id = absint( $request->get_param( 'program_id' ) );
		$batch_id = absint( $request->get_param( 'batch_id' ) );

		$recipient = null;
		if ( $recipient_id ) {
			$recipient = ( new RecipientRepository() )->find( $recipient_id );
			if ( is_wp_error( $recipient ) ) { return $recipient; }
			if ( ! is_array( $recipient ) ) { $recipient = null; }
		}

		$program = null;
		if ( $program_id ) {
			$program = ( new ProgramRepository() )->find( $program_id );
			if ( is_wp_error( $program ) ) { return $program; }
			if ( ! is_array( $program ) || empty( $program['active'] ) ) { $program = null; $program_id = 0; $batch_id = 0; }
		}

		$batch = null;
		if ( $program_id && $batch_id ) {
			$batch = ( new BatchRepository() )->find( $batch_id );
			if ( is_wp_error( $batch ) ) { return $batch; }
			if ( ! is_array( $batch ) || empty( $batch['active'] ) || $program_id !== absint( $batch['program_id'] ?? 0 ) ) { $batch = null; $batch_id = 0; }
		}

		$email_designs = ( new EmailTemplateCatalogRepository() )->for_purpose( EmailDesignPurposes::ISSUED, true );
		if ( ! is_array( $email_designs ) ) { $email_designs = array(); }

		return rest_ensure_response( array(
			'profile' => array(
				'key' => $profile,
				'label' => UsageProfilePresentation::profile_label(),
				'terms' => $terms,
				'issuance' => $issuance,
			),
			'today' => current_time( 'Y-m-d' ),
			'numbering' => array( 'automatic' => ( new ManualIssuanceNumberAllocator() )->is_automatic() ),
			'deep_link' => array(
				'recipient' => $recipient ? $this->recipient_choice( $recipient ) : null,
				'program' => $program ? $this->program_choice( $program ) : null,
				'batch' => $batch ? $this->batch_choice( $batch ) : null,
			),
			'email_designs' => array_values( array_map( array( $this, 'email_design_choice' ), $email_designs ) ),
			'limits' => array( 'search' => 20, 'custom_fields' => 30, 'repeating_rows' => TableDataContract::MAX_ROWS ),
		) );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function program_context( \WP_REST_Request $request ) {
		$program_id = absint( $request->get_param( 'program_id' ) );
		$batch_id = absint( $request->get_param( 'batch_id' ) );
		if ( ! $program_id ) { return new \WP_Error( 'certloom_issue_program_required', __( 'Choose a Program first.', 'certloom' ), array( 'status' => 400 ) ); }
		$program = ( new ProgramRepository() )->find( $program_id );
		if ( is_wp_error( $program ) ) { return $program; }
		if ( ! is_array( $program ) || empty( $program['active'] ) ) { return new \WP_Error( 'certloom_issue_program_missing', __( 'The selected Program is no longer available.', 'certloom' ), array( 'status' => 404 ) ); }

		$batch = null;
		if ( $batch_id ) {
			$batch = ( new BatchRepository() )->find( $batch_id );
			if ( is_wp_error( $batch ) ) { return $batch; }
			if ( ! is_array( $batch ) || empty( $batch['active'] ) ) { return new \WP_Error( 'certloom_issue_batch_missing', __( 'The selected Batch is no longer available.', 'certloom' ), array( 'status' => 404 ) ); }
			if ( $program_id !== absint( $batch['program_id'] ?? 0 ) ) { return new \WP_Error( 'certloom_issue_batch_program_mismatch', __( 'The selected Batch does not belong to the selected Program.', 'certloom' ), array( 'status' => 400 ) ); }
		}

		$issuer = null;
		$issuer_id = absint( $program['issuer_id'] ?? 0 );
		if ( $issuer_id ) {
			$issuer = ( new IssuerRepository() )->find( $issuer_id );
			if ( is_wp_error( $issuer ) ) { return $issuer; }
		}

		$catalog = class_exists( '\\Ecapla_CertVerify_Fields' ) ? \Ecapla_CertVerify_Fields::get_field_map() : array();
		$global_contract = ( new ScopedFieldGlobalRepository() )->get( $catalog );
		if ( is_wp_error( $global_contract ) ) { return $global_contract; }
		$field_contract = ( new ScopedFieldContract() )->resolve_hierarchy(
			$global_contract,
			is_array( $issuer ) && is_array( $issuer['field_contract'] ?? null ) ? $issuer['field_contract'] : null,
			is_array( $program['field_contract'] ?? null ) ? $program['field_contract'] : ScopedFieldContract::empty_program(),
			is_array( $batch ) && is_array( $batch['field_contract'] ?? null ) ? $batch['field_contract'] : null,
			$catalog
		);
		if ( is_wp_error( $field_contract ) ) { return $field_contract; }

		return rest_ensure_response( array(
			'program' => $this->program_choice( $program ),
			'batch' => $batch ? $this->batch_choice( $batch ) : null,
			'issuer' => is_array( $issuer ) ? $this->issuer_choice( $issuer ) : null,
			'field_contract' => $field_contract,
			'validity' => array(
				'mode' => (string) ( $program['validity_mode'] ?? '' ),
				'value' => absint( $program['validity_value'] ?? 0 ),
				'unit' => (string) ( $program['validity_unit'] ?? 'days' ),
				'summary' => (string) ( $program['validity_summary'] ?? '' ),
				'program_default_available' => ! empty( $program['program_default_available'] ),
			),
		) );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function quick_program( \WP_REST_Request $request ) {
		$body = $this->body( $request );
		$title = trim( sanitize_text_field( (string) ( $body['title'] ?? '' ) ) );
		if ( '' === $title ) { return new \WP_Error( 'certloom_issue_quick_program_title_required', __( 'Enter a Program title.', 'certloom' ), array( 'status' => 400 ) ); }
		$data = array(
			'title' => $title,
			'code' => trim( sanitize_text_field( (string) ( $body['code'] ?? '' ) ) ),
			'issuer_id' => absint( $body['issuer_id'] ?? 0 ) ?: null,
			'active' => 1,
		);
		$id = ( new ProgramRepository() )->create( $data );
		if ( is_wp_error( $id ) ) { return $this->rest_error( $id ); }
		$program = ( new ProgramRepository() )->find( $id );
		if ( is_wp_error( $program ) ) { return $program; }
		return rest_ensure_response( array( 'program' => $this->program_choice( is_array( $program ) ? $program : array( 'id' => $id, 'title' => $title ) ) ) );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function quick_batch( \WP_REST_Request $request ) {
		$body = $this->body( $request );
		$program_id = absint( $body['program_id'] ?? 0 );
		$name = trim( sanitize_text_field( (string) ( $body['name'] ?? '' ) ) );
		if ( ! $program_id ) { return new \WP_Error( 'certloom_issue_quick_batch_program_required', __( 'Choose a Program before adding a Batch.', 'certloom' ), array( 'status' => 400 ) ); }
		if ( '' === $name ) { return new \WP_Error( 'certloom_issue_quick_batch_name_required', __( 'Enter a Batch name.', 'certloom' ), array( 'status' => 400 ) ); }
		$program = ( new ProgramRepository() )->find( $program_id );
		if ( is_wp_error( $program ) ) { return $program; }
		if ( ! is_array( $program ) || empty( $program['active'] ) ) { return new \WP_Error( 'certloom_issue_quick_batch_program_missing', __( 'The selected Program is no longer available.', 'certloom' ), array( 'status' => 404 ) ); }
		$code = trim( sanitize_text_field( (string) ( $body['code'] ?? '' ) ) );
		if ( '' === $code ) {
			$base = strtoupper( sanitize_key( $name ) );
			$base = '' !== $base ? substr( $base, 0, 72 ) : 'BATCH';
			$code = $base . '-' . strtoupper( substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 8 ) );
		}
		$id = ( new BatchRepository() )->create( array(
			'program_id' => $program_id,
			'name' => $name,
			'code' => $code,
			'active' => 1,
		) );
		if ( is_wp_error( $id ) ) { return $this->rest_error( $id ); }
		$batch = ( new BatchRepository() )->find( $id );
		if ( is_wp_error( $batch ) ) { return $batch; }
		return rest_ensure_response( array( 'batch' => $this->batch_choice( is_array( $batch ) ? $batch : array( 'id' => $id, 'program_id' => $program_id, 'name' => $name ) ) ) );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function review( \WP_REST_Request $request ) {
		$input = $this->issuance_request( $request );
		if ( is_wp_error( $input ) ) { return $input; }
		$review = ( new ManualIssuanceService() )->review( $input['issuance'] );
		if ( is_wp_error( $review ) ) { return $this->rest_error( $review ); }
		return rest_ensure_response( array(
			'review_fingerprint' => (string) ( $review['review_fingerprint'] ?? '' ),
			'review' => $this->review_payload( $review ),
			'email' => $input['email'],
		) );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function issue( \WP_REST_Request $request ) {
		$input = $this->issuance_request( $request );
		if ( is_wp_error( $input ) ) { return $input; }
		$body = $this->body( $request );
		$fingerprint = strtolower( trim( sanitize_text_field( (string) ( $body['review_fingerprint'] ?? '' ) ) ) );
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $fingerprint ) ) { return new \WP_Error( 'certloom_issue_review_fingerprint_invalid', __( 'Review this credential again before issuing.', 'certloom' ), array( 'status' => 400 ) ); }
		$result = ( new ManualIssuanceService() )->issue( $input['issuance'], $fingerprint, get_current_user_id() );
		if ( is_wp_error( $result ) ) { return $this->rest_error( $result ); }
		$version_id = absint( $result['version_id'] ?? 0 );
		$email_url = add_query_arg( array(
			'page' => EmailDeliveryAdmin::PAGE,
			'version_id' => $version_id,
			'template_id' => sanitize_key( (string) ( $input['email']['template_id'] ?? 'automatic' ) ),
		), admin_url( 'admin.php' ) );
		return rest_ensure_response( array(
			'receipt' => $this->receipt_payload( $result ),
			'continuations' => array(
				'email_url' => esc_url_raw( $email_url ),
				'issue_another_url' => esc_url_raw( admin_url( 'admin.php?page=' . ManualIssuanceAdmin::PAGE ) ),
				'credentials_url' => esc_url_raw( admin_url( 'admin.php?page=certloom-domain-credentials' ) ),
			),
			'email' => $input['email'],
		) );
	}

	/** @return \WP_REST_Response|\WP_Error */
	public function receipt( \WP_REST_Request $request ) {
		$receipt = ( new ManualIssuanceService() )->receipt( absint( $request->get_param( 'version_id' ) ) );
		if ( is_wp_error( $receipt ) ) { return $this->rest_error( $receipt ); }
		return rest_ensure_response( array( 'receipt' => $this->receipt_payload( $receipt ) ) );
	}

	/** @return array<string,mixed>|\WP_Error */
	private function issuance_request( \WP_REST_Request $request ) {
		$body = $this->body( $request );
		$issuance = isset( $body['issuance'] ) && is_array( $body['issuance'] ) ? $body['issuance'] : $body;
		$issuance = $this->deep_unslash( $issuance );
		$email = isset( $body['email'] ) && is_array( $body['email'] ) ? $this->deep_unslash( $body['email'] ) : array();

		$contract = UsageProfilePresentation::issuance_contract();
		$allowed = isset( $contract['repeating_rowsets'] ) && is_array( $contract['repeating_rowsets'] ) ? array_values( array_map( 'sanitize_key', $contract['repeating_rowsets'] ) ) : array();
		$repeating = isset( $issuance['repeating_data'] ) && is_array( $issuance['repeating_data'] ) ? $issuance['repeating_data'] : array();
		foreach ( array_keys( $repeating ) as $rowset ) {
			if ( ! in_array( sanitize_key( (string) $rowset ), $allowed, true ) ) {
				return new \WP_Error( 'certloom_issue_profile_repeating_data_invalid', __( 'This site profile does not support the submitted Subjects/Results data for Manual Issuance.', 'certloom' ), array( 'status' => 400 ) );
			}
		}

		$presentation_mode = sanitize_key( (string) ( $issuance['presentation_mode'] ?? 'details_only' ) );
		if ( 'image' === $presentation_mode ) {
			$image_id = absint( $issuance['certificate_image_id'] ?? 0 );
			$mime = $image_id && function_exists( 'get_post_mime_type' ) ? (string) get_post_mime_type( $image_id ) : '';
			if ( ! $image_id || ! in_array( $mime, array( 'image/png', 'image/jpeg' ), true ) ) {
				return new \WP_Error( 'certloom_issue_certificate_image_invalid', __( 'Choose a PNG or JPEG certificate image from the Media Library.', 'certloom' ), array( 'status' => 400 ) );
			}
		}

		$email_mode = sanitize_key( (string) ( $email['mode'] ?? 'review' ) );
		if ( ! in_array( $email_mode, array( 'review', 'later' ), true ) ) { $email_mode = 'review'; }
		$email_template = sanitize_key( (string) ( $email['template_id'] ?? 'automatic' ) );
		if ( '' === $email_template ) { $email_template = 'automatic'; }

		return array(
			'issuance' => $issuance,
			'email' => array( 'mode' => $email_mode, 'template_id' => $email_template ),
		);
	}

	/** @return array<string,mixed> */
	private function review_payload( array $review ) {
		$request = isset( $review['request'] ) && is_array( $review['request'] ) ? $review['request'] : array();
		$recipient = isset( $review['recipient'] ) && is_array( $review['recipient'] ) ? $review['recipient'] : array();
		$program = isset( $review['program'] ) && is_array( $review['program'] ) ? $review['program'] : array();
		$batch = isset( $review['batch'] ) && is_array( $review['batch'] ) ? $review['batch'] : array();
		$issuer = isset( $review['issuer'] ) && is_array( $review['issuer'] ) ? $review['issuer'] : array();
		$signer = isset( $review['signer'] ) && is_array( $review['signer'] ) ? $review['signer'] : array();
		$template = isset( $review['template'] ) && is_array( $review['template'] ) ? $review['template'] : array();
		$locale = isset( $review['credential_locale'] ) && is_array( $review['credential_locale'] ) ? $review['credential_locale'] : array();
		$verification_fields = array();
		$field_rows = isset( $review['field_contract']['fields'] ) && is_array( $review['field_contract']['fields'] ) ? $review['field_contract']['fields'] : array();
		$field_values = isset( $review['field_values'] ) && is_array( $review['field_values'] ) ? $review['field_values'] : array();
		foreach ( $field_rows as $field_row ) {
			if ( ! is_array( $field_row ) ) { continue; }
			$key = sanitize_key( (string) ( $field_row['key'] ?? '' ) );
			$exposure = sanitize_key( (string) ( $field_row['public_exposure'] ?? 'private' ) );
			if ( '' === $key || ! in_array( $exposure, array( 'verification', 'both' ), true ) || ! array_key_exists( $key, $field_values ) || '' === trim( (string) $field_values[ $key ] ) ) { continue; }
			$verification_fields[] = array( 'key' => $key, 'label' => (string) ( $field_row['label'] ?? $key ) );
		}
		return array(
			'recipient' => array( 'id' => absint( $recipient['id'] ?? 0 ), 'name' => (string) ( $recipient['display_name'] ?? '' ), 'email' => (string) ( $recipient['email'] ?? '' ) ),
			'program' => array( 'id' => absint( $program['id'] ?? 0 ), 'title' => (string) ( $program['title'] ?? '' ) ),
			'batch' => array( 'id' => absint( $batch['id'] ?? 0 ), 'name' => (string) ( $batch['name'] ?? '' ) ),
			'issuer' => array( 'id' => absint( $issuer['id'] ?? 0 ), 'name' => (string) ( $issuer['name'] ?? '' ) ),
			'signer' => array( 'id' => absint( $signer['id'] ?? 0 ), 'name' => (string) ( $signer['display_name'] ?? '' ) ),
			'credential_title' => (string) ( $request['credential_title'] ?? '' ),
			'title_source' => in_array( (string) ( $request['title_source'] ?? '' ), array( 'program', 'custom' ), true ) ? (string) $request['title_source'] : 'custom',
			'valid_from' => (string) ( $review['valid_from'] ?? $request['valid_from'] ?? '' ),
			'expires_at' => isset( $review['expires_at'] ) ? $review['expires_at'] : null,
			'presentation_mode' => (string) ( $request['presentation_mode'] ?? 'details_only' ),
			'template' => array( 'id' => absint( $template['id'] ?? 0 ), 'name' => (string) ( $template['name'] ?? '' ) ),
			'template_resolution' => (string) ( $review['template_resolution'] ?? '' ),
			'template_locale_variant' => isset( $review['template_locale_variant'] ) && is_array( $review['template_locale_variant'] ) ? array(
				'matched_locale' => (string) ( $review['template_locale_variant']['matched_locale'] ?? '' ),
				'template_key' => (string) ( $review['template_locale_variant']['template_key'] ?? '' ),
			) : null,
			'locale' => array( 'configured' => (string) ( $locale['configured'] ?? '' ), 'effective' => (string) ( $locale['effective'] ?? '' ), 'direction' => (string) ( $locale['direction'] ?? '' ) ),
			'verification_fields' => $verification_fields,
			'data_readiness' => isset( $review['data_readiness'] ) && is_array( $review['data_readiness'] ) ? $review['data_readiness'] : array(),
			'numbering' => isset( $review['numbering'] ) && is_array( $review['numbering'] ) ? $review['numbering'] : array(),
			'technical' => array(
				'review_fingerprint' => (string) ( $review['review_fingerprint'] ?? '' ),
				'template_revision_id' => absint( $review['template_revision']['id'] ?? 0 ),
			),
		);
	}

	/** @return array<string,mixed> */
	private function receipt_payload( array $receipt ) {
		return array(
			'version_id' => absint( $receipt['version_id'] ?? 0 ),
			'series_id' => absint( $receipt['series_id'] ?? 0 ),
			'display_number' => (string) ( $receipt['display_number'] ?? '' ),
			'public_id' => (string) ( $receipt['public_id'] ?? '' ),
			'issued_at' => (string) ( $receipt['issued_at'] ?? '' ),
			'expires_at' => $receipt['expires_at'] ?? null,
			'verification_url' => (string) ( $receipt['verification_url'] ?? '' ),
			'idempotent_replay' => ! empty( $receipt['idempotent_replay'] ),
		);
	}

	/** @return array<string,mixed> */
	private function body( \WP_REST_Request $request ) {
		$json = $request->get_json_params();
		if ( is_array( $json ) ) { return $json; }
		$body = $request->get_body_params();
		return is_array( $body ) ? $body : array();
	}

	/** @return mixed */
	private function deep_unslash( $value ) {
		if ( is_array( $value ) ) { foreach ( $value as $key => $item ) { $value[ $key ] = $this->deep_unslash( $item ); } return $value; }
		return is_string( $value ) ? wp_unslash( $value ) : $value;
	}

	/** @return \WP_Error */
	private function rest_error( \WP_Error $error ) {
		$data = $error->get_error_data();
		if ( ! is_array( $data ) ) { $data = array(); }
		if ( empty( $data['status'] ) ) { $data['status'] = 400; }
		return new \WP_Error( $error->get_error_code(), $error->get_error_message(), $data );
	}

	/** @return array<string,mixed> */
	private function recipient_choice( array $row ) { return array( 'id' => absint( $row['id'] ?? 0 ), 'label' => (string) ( $row['display_name'] ?? '' ) . ( ! empty( $row['email'] ) ? ' — ' . (string) $row['email'] : '' ), 'name' => (string) ( $row['display_name'] ?? '' ), 'email' => (string) ( $row['email'] ?? '' ), 'external_id' => (string) ( $row['external_id'] ?? '' ) ); }
	/** @return array<string,mixed> */
	private function program_choice( array $row ) { return array( 'id' => absint( $row['id'] ?? 0 ), 'label' => ScopedContextChoices::program_label( $row ), 'title' => (string) ( $row['title'] ?? '' ), 'issuer_id' => absint( $row['issuer_id'] ?? 0 ), 'issuer_name' => (string) ( $row['issuer_name'] ?? '' ), 'validity_summary' => (string) ( $row['validity_summary'] ?? '' ), 'program_default_available' => ! empty( $row['program_default_available'] ) ); }
	/** @return array<string,mixed> */
	private function batch_choice( array $row ) { return array( 'id' => absint( $row['id'] ?? 0 ), 'program_id' => absint( $row['program_id'] ?? 0 ), 'label' => ScopedContextChoices::batch_label( $row ), 'name' => (string) ( $row['name'] ?? '' ) ); }
	/** @return array<string,mixed> */
	private function issuer_choice( array $row ) { return array( 'id' => absint( $row['id'] ?? 0 ), 'label' => (string) ( $row['name'] ?? '' ), 'name' => (string) ( $row['name'] ?? '' ) ); }
	/** @return array<string,mixed> */
	private function signer_choice( array $row ) { return array( 'id' => absint( $row['id'] ?? 0 ), 'label' => (string) ( $row['display_name'] ?? '' ), 'name' => (string) ( $row['display_name'] ?? '' ) ); }
	/** @return array<string,mixed> */
	private function email_design_choice( $row ) {
		$row = is_array( $row ) ? $row : array();
		return array( 'id' => sanitize_key( (string) ( $row['id'] ?? $row['machine_key'] ?? '' ) ), 'name' => (string) ( $row['name'] ?? $row['title'] ?? '' ), 'active' => ! empty( $row['active'] ) );
	}
}
