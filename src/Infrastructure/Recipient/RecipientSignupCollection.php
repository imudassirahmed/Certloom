<?php
namespace Certloom\Infrastructure\Recipient;

use Certloom\Application\Fields\FieldCollectionPolicy;
use Certloom\Application\Recipient\RecipientCollectionService;
use Certloom\Application\Recipient\RecipientFieldEditValidator;
use Certloom\Application\Rendering\CanonicalJson;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Standard signup forms collect only explicitly enabled reusable customer details. */
final class RecipientSignupCollection {
	private $validated;
	public function register() {
		add_action( 'register_form', array( $this, 'render' ) );
		add_action( 'woocommerce_register_form', array( $this, 'render' ) );
		add_filter( 'registration_errors', array( $this, 'validate' ), 10, 3 );
		add_filter( 'woocommerce_registration_errors', array( $this, 'validate_woocommerce' ), 10, 3 );
		add_action( 'user_register', array( $this, 'save' ), 30 );
	}
	private function context() {
		// No opted-in signup fields means ordinary site registration is unaffected,
		// including when an unrelated Program's collection configuration needs repair.
		$signup_configured = false;
		foreach ( \Ecapla_CertVerify_Fields::get_field_map() as $field ) {
			if ( 'signup' === ( $field[ FieldCollectionPolicy::KEY ]['collect_when'] ?? '' ) ) { $signup_configured = true; break; }
		}
		if ( ! $signup_configured ) { return array( 'fields' => array(), 'required' => array(), 'revision' => '' ); }
		$context = ( new RecipientFieldContext() )->load(); if ( is_wp_error( $context ) ) { return $context; }
		$fields = array(); $policies = new FieldCollectionPolicy(); $required = array();
		foreach ( $context['contract']['fields'] as $row ) { $required[ $row['key'] ] = ! empty( $row['required'] ); }
		foreach ( $context['catalog'] as $key => $definition ) {
			$policy = $policies->for_definition( $definition ); if ( is_wp_error( $policy ) ) { return $policy; }
			if ( $policies->recipient_editable( $policy ) && 'recipient' === $policy['lifetime'] && 'signup' === $policy['collect_when'] ) { $fields[ $key ] = $definition; }
		}
		try { $hash = ( new CanonicalJson() )->hash( array( 'fields' => $fields, 'required' => array_intersect_key( $required, $fields ) ) ); }
		catch ( \Throwable $error ) { return new \WP_Error( 'certloom_signup_settings_invalid', __( 'Certificate detail settings need attention.', 'certloom' ) ); }
		return array( 'fields' => $fields, 'required' => $required, 'revision' => $hash );
	}
	public function render() {
		$context = $this->context(); if ( is_wp_error( $context ) || ! $context['fields'] ) { return; }
		echo '<fieldset class="certloom-signup-details"><legend>' . esc_html__( 'Details for your certificates', 'certloom' ) . '</legend><p>' . esc_html__( 'Your organization asks for these details. You can update them later in your certificate profile.', 'certloom' ) . '</p>';
		wp_nonce_field( 'certloom_recipient_signup', 'certloom_signup_nonce', false );
		echo '<input type="hidden" name="certloom_signup_revision" value="' . esc_attr( $context['revision'] ) . '">';
		$values = isset( $_POST['certloom_signup_values'] ) && is_array( $_POST['certloom_signup_values'] ) ? wp_unslash( $_POST['certloom_signup_values'] ) : array(); // Display-only escaped retry values.
		foreach ( $context['fields'] as $key => $field ) {
			$id = 'certloom-signup-' . $key; $required = ! empty( $context['required'][ $key ] ); $value = is_string( $values[ $key ] ?? null ) ? $values[ $key ] : '';
			$policy = ( new FieldCollectionPolicy() )->for_definition( $field );
			echo '<p><label for="' . esc_attr( $id ) . '">' . esc_html( $field['label'] ?? $key ) . ( $required ? ' <span aria-hidden="true">*</span>' : '' ) . '</label><br>';
			$type = $field['type'] ?? 'text'; $attributes = ' id="' . esc_attr( $id ) . '" name="certloom_signup_values[' . esc_attr( $key ) . ']" aria-describedby="' . esc_attr( $id . '-help' ) . '"' . ( $required ? ' required aria-required="true"' : '' );
			if ( 'textarea' === $type ) { echo '<textarea' . $attributes . ' rows="3" maxlength="8192">' . esc_textarea( $value ) . '</textarea>'; }
			else { echo '<input class="input" type="' . esc_attr( in_array( $type, array( 'number', 'date' ), true ) ? $type : 'text' ) . '"' . $attributes . ( 'number' === $type ? ' step="any"' : ' maxlength="1000"' ) . ' value="' . esc_attr( $value ) . '">'; }
			echo '<small id="' . esc_attr( $id . '-help' ) . '">' . esc_html( $policy['help'] ) . ( $policy['approval_required'] ? ' ' . esc_html__( 'Your organization reviews this answer before using it.', 'certloom' ) : '' ) . '</small></p>';
		}
		echo '</fieldset>';
	}
	/** Checkout/API account creation has no signup controls; collect missing details in profile. */
	public function validate_woocommerce( $errors, $username, $email ) {
		$this->validated = null;
		if ( ! isset( $_POST['certloom_signup_nonce'] ) && ! isset( $_POST['woocommerce-register-nonce'] ) ) { return $errors; }
		return $this->validate( $errors, $username, $email );
	}
	public function validate( $errors, $username, $email ) {
		$this->validated = null; $context = $this->context();
		if ( is_wp_error( $context ) ) { $errors->add( 'certloom_signup_configuration', __( 'Certificate detail settings need attention. Please contact the organization.', 'certloom' ) ); return $errors; }
		if ( ! $context['fields'] ) { return $errors; }
		$nonce = $_POST['certloom_signup_nonce'] ?? ''; $revision = $_POST['certloom_signup_revision'] ?? '';
		if ( ! is_string( $nonce ) || ! wp_verify_nonce( wp_unslash( $nonce ), 'certloom_recipient_signup' ) || ! is_string( $revision ) || ! hash_equals( $context['revision'], wp_unslash( $revision ) ) ) { $errors->add( 'certloom_signup_changed', __( 'Refresh the signup form to load the current certificate details, then try again.', 'certloom' ) ); return $errors; }
		$values = $_POST['certloom_signup_values'] ?? array();
		if ( ! is_array( $values ) || strlen( (string) wp_json_encode( $values ) ) > 131072 ) { $errors->add( 'certloom_signup_values_invalid', __( 'Review your certificate details and try again.', 'certloom' ) ); return $errors; }
		$clean = ( new RecipientFieldEditValidator() )->validate_form( is_array( $values ) ? wp_unslash( $values ) : $values, $context['fields'], 'recipient' );
		if ( is_wp_error( $clean ) ) { $errors->add( $clean->get_error_code(), $clean->get_error_message() ); return $errors; }
		foreach ( $context['fields'] as $key => $field ) { if ( ! empty( $context['required'][ $key ] ) && '' === trim( $clean['recipient'][ $key ] ?? '' ) ) { $errors->add( 'certloom_signup_required_' . $key, sprintf( __( 'Please complete %s for your certificate profile.', 'certloom' ), $field['label'] ?? $key ) ); } }
		if ( ! $errors->has_errors() ) { $this->validated = array( 'values' => $clean['recipient'], 'revision' => $context['revision'], 'email' => strtolower( trim( (string) $email ) ) ); }
		return $errors;
	}
	public function save( $user_id ) {
		$validated = $this->validated; $this->validated = null;
		if ( ! is_array( $validated ) || ! $validated['values'] ) { return; }
		$user = get_userdata( $user_id );
		if ( ! $user || user_can( $user_id, ECAPLA_CERTVERIFY_CAP ) || strtolower( trim( $user->user_email ) ) !== $validated['email'] ) { return; }
		$context = $this->context(); if ( is_wp_error( $context ) || ! hash_equals( $context['revision'], $validated['revision'] ) ) { return; }
		$recipient = ( new RecipientUserSync() )->ensure_for_user( (int) $user_id ); if ( is_wp_error( $recipient ) ) { return; }
		$service = new RecipientCollectionService(); $view = $service->read_for_user( (int) $user_id );
		if ( is_wp_error( $view ) || empty( $view['linked'] ) ) { return; }
		// Approval and exact account ownership are rechecked by the existing service.
		$service->save_for_user( (int) $user_id, 0, $view['revision'], $validated['values'] );
	}
}
