<?php
namespace Certloom\Infrastructure\Admin;

use Certloom\Application\Fields\ScopedFieldContract;
use Certloom\Infrastructure\Integration\LearnDashAdapter;
use Certloom\Infrastructure\Integration\TutorLmsAdapter;
use Certloom\Infrastructure\Repository\BatchRepository;
use Certloom\Infrastructure\Repository\IssuerRepository;
use Certloom\Infrastructure\Repository\ProgramRepository;
use Certloom\Infrastructure\Repository\RecipientRepository;
use Certloom\Infrastructure\Repository\SignerRepository;
use Certloom\Infrastructure\Repository\TemplateRepository;

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
		$resolved = ( new ScopedFieldContract() )->resolve(
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
			);
		}

		wp_send_json_success(
			array(
				'configured'       => ! empty( $resolved['_meta']['configured'] ),
				'batch_configured' => ! empty( $resolved['_meta']['batch_configured'] ),
				'fields'           => $fields,
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
		$fields[] = $field;
		if ( ! \Ecapla_CertVerify_Fields::save_fields( $fields ) ) { wp_send_json_error( array( 'message' => __( 'Certloom could not save the new field.', 'certloom' ) ), 500 ); }
		wp_send_json_success( array( 'field' => array_merge( $field, array( 'sensitivity_label' => (string) $sensitivity_options[ $sensitivity ], 'public_exposure_label' => (string) $exposure_options[ $public_exposure ] ) ), 'message' => __( 'Field created and added. You can manage its policy later in Settings → Fields.', 'certloom' ) ) );
	}

	/** Read-only bounded Program/Batch lookup for relationship selectors. @return void */
	public function lookup() {
		$allowed = false;
		foreach ( array(
			\Ecapla_CertVerify_Permissions::CAP_VIEW_CREDENTIALS,
			\Ecapla_CertVerify_Permissions::CAP_ISSUE_CREDENTIALS,
			\Ecapla_CertVerify_Permissions::CAP_MANAGE_CREDENTIALS,
			\Ecapla_CertVerify_Permissions::CAP_MANAGE_RECIPIENTS,
			\Ecapla_CertVerify_Permissions::CAP_MANAGE_PROGRAMS,
			\Ecapla_CertVerify_Permissions::CAP_MANAGE_BATCHES,
			\Ecapla_CertVerify_Permissions::CAP_MANAGE_TEMPLATES,
			\Ecapla_CertVerify_Permissions::CAP_MANAGE_INTEGRATIONS,
		) as $capability ) { if ( current_user_can( $capability ) ) { $allowed = true; break; } }
		if ( ! $allowed ) { wp_send_json_error( array( 'message' => __( 'You do not have permission to search Certloom relationship choices.', 'certloom' ) ), 403 ); }
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$kind = isset( $_POST['kind'] ) ? sanitize_key( wp_unslash( $_POST['kind'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
		$query = isset( $_POST['query'] ) ? sanitize_text_field( wp_unslash( $_POST['query'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
		$query = function_exists( 'mb_substr' ) ? mb_substr( $query, 0, 120, 'UTF-8' ) : substr( $query, 0, 120 );
		$choices = array();
		if ( 'recipient' === $kind ) {
			$result = ( new RecipientRepository() )->list_page( $query, 1, 20 );
			if ( is_wp_error( $result ) ) { wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 ); }
			foreach ( (array) ( $result['items'] ?? array() ) as $row ) {
				$label = trim( (string) ( $row['display_name'] ?? '' ) );
				if ( ! empty( $row['email'] ) ) { $label .= ( '' !== $label ? ' · ' : '' ) . (string) $row['email']; }
				if ( '' === $label && ! empty( $row['external_id'] ) ) { $label = (string) $row['external_id']; }
				$choices[] = array( 'id' => absint( $row['id'] ?? 0 ), 'label' => $label );
			}
		} elseif ( 'program' === $kind ) {
			$program_status = isset( $_POST['program_status'] ) ? sanitize_key( wp_unslash( $_POST['program_status'] ) ) : 'active'; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
			$program_status = in_array( $program_status, array( 'active', 'inactive', 'all' ), true ) ? $program_status : 'active';
			$result = ( new ProgramRepository() )->list_page( $query, 1, 20, $program_status );
			if ( is_wp_error( $result ) ) { wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 ); }
			foreach ( (array) ( $result['items'] ?? array() ) as $row ) {
				$label = (string) ( $row['title'] ?? '' ); if ( ! empty( $row['code'] ) ) { $label .= ' · ' . (string) $row['code']; }
				$choices[] = array( 'id' => absint( $row['id'] ?? 0 ), 'label' => $label );
			}
		} elseif ( 'batch' === $kind ) {
			$program_id = isset( $_POST['program_id'] ) ? absint( $_POST['program_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
			$result = ( new BatchRepository() )->search_active_for_program( $program_id, $query, 20 );
			if ( is_wp_error( $result ) ) { wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 ); }
			foreach ( (array) $result as $row ) {
				$label = (string) ( $row['name'] ?? '' ); if ( ! empty( $row['code'] ) ) { $label .= ' · ' . (string) $row['code']; }
				$choices[] = array( 'id' => absint( $row['id'] ?? 0 ), 'label' => $label, 'program_id' => absint( $row['program_id'] ?? 0 ) );
			}
		} elseif ( 'template' === $kind ) {
			$result = ( new TemplateRepository() )->search_published_candidates( $query, 20 );
			if ( is_wp_error( $result ) ) { wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 ); }
			foreach ( (array) $result as $row ) {
				$label = ScopedContextChoices::template_label( $row );
				$choices[] = array( 'id' => absint( $row['id'] ?? 0 ), 'label' => $label );
			}
		} elseif ( 'issuer' === $kind ) {
			$result = ( new IssuerRepository() )->list_page( $query, 1, 20 );
			if ( is_wp_error( $result ) ) { wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 ); }
			foreach ( (array) ( $result['items'] ?? array() ) as $row ) {
				$label = (string) ( $row['name'] ?? '' ); if ( ! empty( $row['slug'] ) ) { $label .= ' · ' . (string) $row['slug']; }
				$choices[] = array( 'id' => absint( $row['id'] ?? 0 ), 'label' => $label );
			}
		} elseif ( 'signer' === $kind ) {
			$result = ( new SignerRepository() )->list_page( $query, 1, 20, 'active' );
			if ( is_wp_error( $result ) ) { wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 ); }
			foreach ( (array) ( $result['items'] ?? array() ) as $row ) {
				$label = (string) ( $row['display_name'] ?? '' ); if ( ! empty( $row['title'] ) ) { $label .= ' · ' . (string) $row['title']; }
				$choices[] = array( 'id' => absint( $row['id'] ?? 0 ), 'label' => $label );
			}
		} elseif ( 'signer_scope' === $kind ) {
			$choices = $this->signer_scope_choices( $query );
			if ( is_wp_error( $choices ) ) { wp_send_json_error( array( 'message' => $choices->get_error_message() ), 500 ); }
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
		} else {
			wp_send_json_error( array( 'message' => __( 'Choose a valid Certloom lookup type.', 'certloom' ) ), 400 );
		}
		wp_send_json_success( array( 'choices' => $choices, 'limit' => 20 ) );
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
