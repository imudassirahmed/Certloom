<?php
namespace Certloom\Infrastructure\Admin;

use Certloom\Application\Fields\ScopedFieldContract;
use Certloom\Application\Issuance\BulkIssuanceCsvSchema;
use Certloom\Infrastructure\Integration\LearnDashAdapter;
use Certloom\Infrastructure\Integration\TutorLmsAdapter;
use Certloom\Infrastructure\Repository\BatchRepository;
use Certloom\Infrastructure\Repository\IssuerRepository;
use Certloom\Infrastructure\Repository\ProgramRepository;
use Certloom\Infrastructure\Repository\RecipientRepository;
use Certloom\Infrastructure\Repository\SignerRepository;
use Certloom\Infrastructure\Repository\TemplateRepository;
use Certloom\Infrastructure\Repository\ScopedFieldGlobalRepository;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Read-only FLD-02 resolver used by premium admin forms.
 *
 * The browser receives only the bounded effective field schema needed to render
 * inputs/guidance. The server always resolves and validates the contract again
 * during issuance, so this endpoint is never an authority boundary.
 */
final class ScopedFieldAdminAjax {
	const ACTION = 'certloom_resolve_scoped_fields';
	const LOOKUP_ACTION = 'certloom_lookup_scoped_context';
	const QUICK_FIELD_ACTION = 'certloom_quick_add_scoped_field';
	const NONCE_ACTION = 'certloom_scoped_fields_admin';

	/** @return void */
	public function register() {
		add_action( 'wp_ajax_' . self::ACTION, array( $this, 'resolve' ) );
		add_action( 'wp_ajax_' . self::LOOKUP_ACTION, array( $this, 'lookup' ) );
		add_action( 'wp_ajax_' . self::QUICK_FIELD_ACTION, array( $this, 'quick_add_field' ) );
	}

	/** @return void */
	public function resolve() {
		if ( ! current_user_can( \Ecapla_CertVerify_Permissions::CAP_MANAGE_CREDENTIALS ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to inspect credential field requirements.', 'certloom' ) ), 403 );
		}
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		$program_id = isset( $_POST['program_id'] ) ? absint( $_POST['program_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
		$batch_id   = isset( $_POST['batch_id'] ) ? absint( $_POST['batch_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
		if ( ! $program_id ) {
			wp_send_json_success( array( 'configured' => false, 'fields' => array() ) );
		}

		$program = ( new ProgramRepository() )->find( $program_id );
		if ( is_wp_error( $program ) ) { wp_send_json_error( array( 'message' => $program->get_error_message() ), 500 ); }
		if ( ! is_array( $program ) || empty( $program['active'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Choose an active Program that still exists.', 'certloom' ) ), 400 );
		}

		$batch = null;
		if ( $batch_id ) {
			$batch = ( new BatchRepository() )->find( $batch_id );
			if ( is_wp_error( $batch ) ) { wp_send_json_error( array( 'message' => $batch->get_error_message() ), 500 ); }
			if ( ! is_array( $batch ) || empty( $batch['active'] ) || absint( $batch['program_id'] ?? 0 ) !== $program_id ) {
				wp_send_json_error( array( 'message' => __( 'Choose an active Batch that belongs to the selected Program.', 'certloom' ) ), 400 );
			}
		}

		$catalog = class_exists( '\\Ecapla_CertVerify_Fields' ) ? \Ecapla_CertVerify_Fields::get_field_map() : array();
		$global = ( new ScopedFieldGlobalRepository() )->get( $catalog );
		if ( is_wp_error( $global ) ) { wp_send_json_error( array( 'message' => $global->get_error_message() ), 400 ); }
		$issuer = ! empty( $program['issuer_id'] ) ? ( new IssuerRepository() )->find( $program['issuer_id'] ) : null;
		if ( is_wp_error( $issuer ) || ( ! empty( $program['issuer_id'] ) && ! is_array( $issuer ) ) ) { wp_send_json_error( array( 'message' => __( 'The Program’s Issuer is unavailable. Review its issuing configuration.', 'certloom' ) ), 400 ); }
		$resolved = ( new ScopedFieldContract() )->resolve_hierarchy(
			$global,
			is_array( $issuer ) && is_array( $issuer['field_contract'] ?? null ) ? $issuer['field_contract'] : null,
			is_array( $program['field_contract'] ?? null ) ? $program['field_contract'] : ScopedFieldContract::empty_program( false ),
			is_array( $batch ) && is_array( $batch['field_contract'] ?? null ) ? $batch['field_contract'] : null,
			$catalog
		);
		if ( is_wp_error( $resolved ) ) { wp_send_json_error( array( 'message' => $resolved->get_error_message() ), 400 ); }

		$fields = array();
		foreach ( (array) ( $resolved['fields'] ?? array() ) as $field ) {
			if ( ! is_array( $field ) || empty( $field['key'] ) ) { continue; }
			$fields[] = array(
				'key'             => sanitize_key( (string) $field['key'] ),
				'label'           => (string) ( $field['label'] ?? $field['key'] ),
				'type'            => sanitize_key( (string) ( $field['type'] ?? 'text' ) ),
				'required'        => ! empty( $field['required'] ),
				'requirement'     => sanitize_key( (string) ( $field['requirement'] ?? 'optional' ) ),
				'origin'          => sanitize_key( (string) ( $field['origin'] ?? 'program' ) ),
				'value_source'    => sanitize_key( (string) ( $field['value_source'] ?? 'credential_custom' ) ),
				'sensitivity'     => sanitize_key( (string) ( $field['sensitivity'] ?? 'personal' ) ),
				'public_exposure' => sanitize_key( (string) ( $field['public_exposure'] ?? 'private' ) ),
				'collection_policy' => $field['collection_policy'] ?? null,
			);
		}
		$csv_schema = ( new BulkIssuanceCsvSchema() )->describe( $resolved, $catalog );
		if ( is_wp_error( $csv_schema ) ) { wp_send_json_error( array( 'message' => $csv_schema->get_error_message() ), 400 ); }

		wp_send_json_success(
			array(
				'configured'       => ! empty( $resolved['_meta']['configured'] ),
				'batch_configured' => ! empty( $resolved['_meta']['batch_configured'] ),
				'fields'           => $fields,
				'bulk_csv_schema'  => $csv_schema,
			)
		);
	}
	/** Creates a conservative catalog field from Program/Batch setup without exposing the full Field Manager. @return void */
	public function quick_add_field() {
		if ( ! current_user_can( \Ecapla_CertVerify_Permissions::CAP_MANAGE_PROGRAMS ) && ! current_user_can( \Ecapla_CertVerify_Permissions::CAP_MANAGE_BATCHES ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to create credential fields.', 'certloom' ) ), 403 );
		}
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! \Certloom\Infrastructure\Migration\DomainFeatureGate::write_paths_ready() ) {
			wp_send_json_error( array( 'message' => __( 'Credential field changes are not available on this site yet.', 'certloom' ) ), 409 );
		}
		$label = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
		$label = function_exists( 'mb_substr' ) ? mb_substr( trim( $label ), 0, 100 ) : substr( trim( $label ), 0, 100 );
		if ( '' === $label ) { wp_send_json_error( array( 'message' => __( 'Give the new field a label.', 'certloom' ) ), 400 ); }
		$allowed_types = array( 'text', 'textarea', 'date', 'number' );
		$type = isset( $_POST['field_type'] ) ? sanitize_key( wp_unslash( $_POST['field_type'] ) ) : 'text'; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
		if ( ! in_array( $type, $allowed_types, true ) ) { $type = 'text'; }
		$sensitivity_options = \Ecapla_CertVerify_Fields::get_sensitivity_options();
		$exposure_options = \Ecapla_CertVerify_Fields::get_public_exposure_options();
		$sensitivity = isset( $_POST['sensitivity'] ) ? sanitize_key( wp_unslash( $_POST['sensitivity'] ) ) : 'personal'; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
		if ( ! array_key_exists( $sensitivity, $sensitivity_options ) ) { $sensitivity = 'personal'; }
		$public_exposure = isset( $_POST['public_exposure'] ) ? sanitize_key( wp_unslash( $_POST['public_exposure'] ) ) : 'private'; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
		if ( ! array_key_exists( $public_exposure, $exposure_options ) ) { $public_exposure = 'private'; }
		if ( 'internal' === $sensitivity ) { $public_exposure = 'private'; }
		$fields = \Ecapla_CertVerify_Fields::get_fields();
		$base_key = sanitize_key( str_replace( '-', '_', sanitize_title( $label ) ) );
		$base_key = '' !== $base_key ? $base_key : 'field';
		$base_key = function_exists( 'mb_substr' ) ? mb_substr( $base_key, 0, 50 ) : substr( $base_key, 0, 50 );
		$existing = \Ecapla_CertVerify_Fields::get_reserved_keys();
		$key = $base_key; $suffix = 2;
		while ( in_array( $key, $existing, true ) ) {
			$suffix_text = '_' . $suffix++; $stem_length = max( 1, 50 - strlen( $suffix_text ) );
			$stem = function_exists( 'mb_substr' ) ? mb_substr( $base_key, 0, $stem_length ) : substr( $base_key, 0, $stem_length ); $key = $stem . $suffix_text;
		}
		$max_order = $fields ? max( array_map( 'absint', wp_list_pluck( $fields, 'order' ) ) ) : 0;
		$field = array( 'key' => $key, 'label' => $label, 'type' => $type, 'column' => null, 'core' => false, 'protected' => false, 'locked_visibility' => false, 'sensitivity' => $sensitivity, 'public_exposure' => $public_exposure, 'show_on_frontend' => 'private' !== $public_exposure, 'order' => $max_order + 1 );
		$writer = $_POST['collection_writer'] ?? 'legacy'; $lifetime = $_POST['collection_lifetime'] ?? 'registration';
		$collection = ( new \Certloom\Application\Fields\FieldCollectionForm() )->normalize( array(
			'writer' => is_string( $writer ) ? wp_unslash( $writer ) : $writer,
			'lifetime' => is_string( $lifetime ) ? wp_unslash( $lifetime ) : $lifetime,
			'collect_when' => 'recipient' === $lifetime ? 'profile' : 'registration',
			'approval_required' => $_POST['collection_approval'] ?? '0', 'show_on_certificate' => '0', 'help' => '',
		), $field );
		if ( is_wp_error( $collection ) ) { wp_send_json_error( array( 'message' => $collection->get_error_message() ), 400 ); }
		if ( is_array( $collection ) ) { $field[ \Certloom\Application\Fields\FieldCollectionPolicy::KEY ] = $collection; }
		$fields[] = $field;
		if ( ! \Ecapla_CertVerify_Fields::save_fields( $fields ) ) { wp_send_json_error( array( 'message' => __( 'Certloom could not save the new field.', 'certloom' ) ), 500 ); }
		wp_send_json_success( array( 'field' => array_merge( $field, array( 'sensitivity_label' => (string) $sensitivity_options[ $sensitivity ], 'public_exposure_label' => (string) $exposure_options[ $public_exposure ] ) ), 'message' => __( 'Field created and added. You can manage its policy later in Settings → Fields.', 'certloom' ) ) );
	}

	/** Whether the current authenticated administrator may use bounded relationship search. */
	public static function can_lookup() {
		foreach ( array(
			\Ecapla_CertVerify_Permissions::CAP_VIEW_CREDENTIALS,
			\Ecapla_CertVerify_Permissions::CAP_ISSUE_CREDENTIALS,
			\Ecapla_CertVerify_Permissions::CAP_MANAGE_CREDENTIALS,
			\Ecapla_CertVerify_Permissions::CAP_MANAGE_RECIPIENTS,
			\Ecapla_CertVerify_Permissions::CAP_MANAGE_PROGRAMS,
			\Ecapla_CertVerify_Permissions::CAP_MANAGE_BATCHES,
			\Ecapla_CertVerify_Permissions::CAP_MANAGE_TEMPLATES,
			\Ecapla_CertVerify_Permissions::CAP_MANAGE_INTEGRATIONS,
		) as $capability ) {
			if ( current_user_can( $capability ) ) { return true; }
		}
		return false;
	}

	/** Read-only bounded Program/Batch lookup for relationship selectors. @return void */
	public function lookup() {
		if ( ! self::can_lookup() ) { wp_send_json_error( array( 'message' => __( 'You do not have permission to search Certloom relationship choices.', 'certloom' ) ), 403 ); }
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$kind = isset( $_POST['kind'] ) ? sanitize_key( wp_unslash( $_POST['kind'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
		$query = isset( $_POST['query'] ) ? sanitize_text_field( wp_unslash( $_POST['query'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
		$program_id = isset( $_POST['program_id'] ) ? absint( $_POST['program_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
		$program_status = isset( $_POST['program_status'] ) ? sanitize_key( wp_unslash( $_POST['program_status'] ) ) : 'active'; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
		$choices = $this->choices( $kind, $query, $program_id, $program_status );
		if ( is_wp_error( $choices ) ) {
			$data = $choices->get_error_data(); $status = is_array( $data ) ? absint( $data['status'] ?? 400 ) : 400;
			wp_send_json_error( array( 'message' => $choices->get_error_message() ), $status >= 400 && $status <= 599 ? $status : 400 );
		}
		wp_send_json_success( array( 'choices' => $choices, 'limit' => 20 ) );
	}

	/**
	 * Shared bounded relationship search used by both the legacy admin-ajax
	 * fallback and the authenticated REST search surface.
	 *
	 * @return array<int,array<string,mixed>>|\WP_Error
	 */
	public function choices( $kind, $query = '', $program_id = 0, $program_status = 'active' ) {
		$kind = sanitize_key( (string) $kind );
		$query = sanitize_text_field( (string) $query );
		$query = function_exists( 'mb_substr' ) ? mb_substr( $query, 0, 120, 'UTF-8' ) : substr( $query, 0, 120 );
		$program_id = absint( $program_id );
		$program_status = sanitize_key( (string) $program_status );
		$program_status = in_array( $program_status, array( 'active', 'inactive', 'all' ), true ) ? $program_status : 'active';
		$choices = array();
		if ( 'recipient' === $kind ) {
			$result = ( new RecipientRepository() )->list_page( $query, 1, 20 );
			if ( is_wp_error( $result ) ) { return $result; }
			foreach ( (array) ( $result['items'] ?? array() ) as $row ) {
				$label = trim( (string) ( $row['display_name'] ?? '' ) );
				if ( ! empty( $row['email'] ) ) { $label .= ( '' !== $label ? ' · ' : '' ) . (string) $row['email']; }
				if ( '' === $label && ! empty( $row['external_id'] ) ) { $label = (string) $row['external_id']; }
				$choices[] = array( 'id' => absint( $row['id'] ?? 0 ), 'label' => $label, 'title' => (string) ( $row['title'] ?? '' ) );
			}
		} elseif ( 'program' === $kind ) {
			$result = ( new ProgramRepository() )->list_page( $query, 1, 20, $program_status );
			if ( is_wp_error( $result ) ) { return $result; }
			foreach ( (array) ( $result['items'] ?? array() ) as $row ) {
				$label = (string) ( $row['title'] ?? '' ); if ( ! empty( $row['code'] ) ) { $label .= ' · ' . (string) $row['code']; }
				$choices[] = array( 'id' => absint( $row['id'] ?? 0 ), 'label' => $label, 'title' => (string) ( $row['title'] ?? '' ), 'issuer_id' => absint( $row['issuer_id'] ?? 0 ), 'issuer_name' => (string) ( $row['issuer_name'] ?? '' ), 'validity_mode' => (string) ( $row['validity_mode'] ?? '' ), 'validity_value' => absint( $row['validity_value'] ?? 0 ), 'validity_unit' => (string) ( $row['validity_unit'] ?? 'days' ), 'program_validity_available' => ! empty( $row['program_default_available'] ), 'validity_summary' => (string) ( $row['validity_summary'] ?? '' ), 'renewal' => (string) ( $row['renewal'] ?? 'not_applicable' ), 'repeat_policy' => (string) ( $row['repeat_policy'] ?? 'lifecycle' ) );
			}
		} elseif ( 'batch' === $kind ) {
			$result = ( new BatchRepository() )->search_active_for_program( $program_id, $query, 20 );
			if ( is_wp_error( $result ) ) { return $result; }
			foreach ( (array) $result as $row ) {
				$label = (string) ( $row['name'] ?? '' ); if ( ! empty( $row['code'] ) ) { $label .= ' · ' . (string) $row['code']; }
				$choices[] = array( 'id' => absint( $row['id'] ?? 0 ), 'label' => $label, 'program_id' => absint( $row['program_id'] ?? 0 ) );
			}
		} elseif ( 'template' === $kind ) {
			$result = ( new TemplateRepository() )->search_published_candidates( $query, 20 );
			if ( is_wp_error( $result ) ) { return $result; }
			foreach ( (array) $result as $row ) {
				$choices[] = array( 'id' => absint( $row['id'] ?? 0 ), 'label' => ScopedContextChoices::template_label( $row ) );
			}
		} elseif ( 'issuer' === $kind ) {
			$result = ( new IssuerRepository() )->list_page( $query, 1, 20 );
			if ( is_wp_error( $result ) ) { return $result; }
			foreach ( (array) ( $result['items'] ?? array() ) as $row ) {
				$label = (string) ( $row['name'] ?? '' ); if ( ! empty( $row['slug'] ) ) { $label .= ' · ' . (string) $row['slug']; }
				$choices[] = array( 'id' => absint( $row['id'] ?? 0 ), 'label' => $label );
			}
		} elseif ( 'signer' === $kind ) {
			$result = ( new SignerRepository() )->list_page( $query, 1, 20, 'active' );
			if ( is_wp_error( $result ) ) { return $result; }
			foreach ( (array) ( $result['items'] ?? array() ) as $row ) {
				$label = (string) ( $row['display_name'] ?? '' ); if ( ! empty( $row['title'] ) ) { $label .= ' · ' . (string) $row['title']; }
				$choices[] = array( 'id' => absint( $row['id'] ?? 0 ), 'label' => $label );
			}
		} elseif ( 'signer_scope' === $kind ) {
			$choices = $this->signer_scope_choices( $query );
			if ( is_wp_error( $choices ) ) { return $choices; }
		} elseif ( 'wp_user' === $kind ) {
			$args = array(
				'number'  => 20,
				'orderby' => 'display_name',
				'order'   => 'ASC',
				'fields'  => array( 'ID', 'display_name', 'user_email', 'user_login' ),
			);
			if ( '' !== trim( $query ) ) {
				$args['search'] = '*' . $query . '*';
				$args['search_columns'] = array( 'user_login', 'user_email', 'display_name' );
			}
			$users = get_users( $args );
			foreach ( is_array( $users ) ? $users : array() as $user ) {
				if ( ! is_object( $user ) || ! absint( $user->ID ?? 0 ) ) { continue; }
				$label = trim( (string) ( $user->display_name ?? '' ) );
				if ( ! empty( $user->user_email ) ) { $label .= ( '' !== $label ? ' · ' : '' ) . (string) $user->user_email; }
				if ( '' === $label ) { $label = (string) ( $user->user_login ?? ( '#' . absint( $user->ID ) ) ); }
				$choices[] = array( 'id' => absint( $user->ID ), 'label' => $label );
			}
		} elseif ( 'tutor_course' === $kind ) {
			$choices = $this->course_choices( TutorLmsAdapter::is_available() ? TutorLmsAdapter::course_post_type() : '', $query );
		} elseif ( 'learndash_course' === $kind ) {
			$choices = $this->course_choices( LearnDashAdapter::is_available() ? LearnDashAdapter::course_post_type() : '', $query );
		} elseif ( 'woocommerce_product' === $kind ) {
			$choices = ( new WooCommerceProductChoices() )->search( $query );
		} else {
			return new \WP_Error( 'certloom_lookup_kind_invalid', __( 'Choose a valid Certloom lookup type.', 'certloom' ), array( 'status' => 400 ) );
		}
		return $choices;
	}

	/** @return array<int,array<string,mixed>>|\WP_Error */
	private function signer_scope_choices( $query ) {
		$choices = array();
		if ( '' === trim( (string) $query ) || false !== stripos( __( 'Global', 'certloom' ), (string) $query ) ) {
			$choices[] = array( 'value' => 'global:0', 'label' => __( 'Global', 'certloom' ) );
		}
		$issuer_result = ( new IssuerRepository() )->list_page( $query, 1, 10 );
		if ( is_wp_error( $issuer_result ) ) { return $issuer_result; }
		foreach ( (array) ( $issuer_result['items'] ?? array() ) as $row ) { $choices[] = array( 'value' => 'issuer:' . absint( $row['id'] ?? 0 ), 'label' => __( 'Issuer', 'certloom' ) . ' · ' . (string) ( $row['name'] ?? '' ) ); }
		$program_result = ( new ProgramRepository() )->list_page( $query, 1, 10, 'active' );
		if ( is_wp_error( $program_result ) ) { return $program_result; }
		foreach ( (array) ( $program_result['items'] ?? array() ) as $row ) { $choices[] = array( 'value' => 'program:' . absint( $row['id'] ?? 0 ), 'label' => __( 'Program', 'certloom' ) . ' · ' . ScopedContextChoices::program_label( $row ) ); }
		$batch_result = ( new BatchRepository() )->list_page( $query, 1, 10, 'active' );
		if ( is_wp_error( $batch_result ) ) { return $batch_result; }
		foreach ( (array) ( $batch_result['items'] ?? array() ) as $row ) { $choices[] = array( 'value' => 'batch:' . absint( $row['id'] ?? 0 ), 'label' => __( 'Batch', 'certloom' ) . ' · ' . ScopedContextChoices::batch_label( $row ) ); }
		return $choices;
	}

	/** @return array<int,array<string,mixed>> */
	private function course_choices( $post_type, $query ) {
		$post_type = sanitize_key( (string) $post_type );
		if ( '' === $post_type ) { return array(); }
		$args = array( 'post_type' => $post_type, 'post_status' => array( 'publish', 'private', 'draft' ), 'posts_per_page' => 20, 'orderby' => 'title', 'order' => 'ASC', 'suppress_filters' => false );
		if ( '' !== trim( (string) $query ) ) { $args['s'] = (string) $query; }
		$posts = get_posts( $args );
		$out = array();
		foreach ( is_array( $posts ) ? $posts : array() as $post ) { if ( is_object( $post ) && absint( $post->ID ?? 0 ) ) { $out[] = array( 'id' => absint( $post->ID ), 'label' => (string) get_the_title( $post ) ); } }
		return $out;
	}

}
