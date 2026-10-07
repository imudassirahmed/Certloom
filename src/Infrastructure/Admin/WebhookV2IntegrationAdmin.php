<?php
namespace Certloom\Infrastructure\Admin;

use Certloom\Application\Fields\FieldCollectionPolicy;
use Certloom\Infrastructure\Integration\WebhookV2ConnectionRepository;
use Certloom\Infrastructure\Recipient\RecipientFieldContext;
use Certloom\Infrastructure\Migration\DomainFeatureGate;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Organization-only connection setup. Recipients never configure result authority. */
final class WebhookV2IntegrationAdmin {
	const SECRET_PREFIX = 'certloom_webhook_v2_setup_secret_';
	public function register() { foreach ( array( 'create', 'save', 'rotate', 'delete' ) as $action ) { add_action( 'admin_post_certloom_webhook_v2_' . $action, array( $this, $action ) ); } }
	public function screen_data() {
		$data = array( 'connections' => array(), 'programs' => array(), 'program_id' => 0, 'batch_id' => 0, 'fields' => array(), 'context_ready' => false, 'one_time_secret' => array(), 'error' => null );
		if ( ! current_user_can( \Ecapla_CertVerify_Permissions::CAP_MANAGE_INTEGRATIONS ) || ! DomainFeatureGate::admin_preview_ready() ) { return $data; }
		$repo = new WebhookV2ConnectionRepository(); $rows = $repo->read(); if ( is_wp_error( $rows ) ) { $data['error'] = $rows; return $data; }
		foreach ( $rows as $row ) {
			$row['fingerprint'] = $repo->revision( $row ); unset( $row['secret_sealed'] );
			$choices = $this->fields( $row['program_id'], $row['batch_id'] );
			$row['field_error'] = is_wp_error( $choices ) ? $choices->get_error_message() : '';
			$row['field_choices'] = is_wp_error( $choices ) ? array() : $choices;
			// Keep obsolete mappings visible so the organization can remove or pause them.
			$available = array_fill_keys( array_column( $row['field_choices'], 'key' ), true );
			foreach ( $row['fields'] as $target ) { if ( ! isset( $available[ $target ] ) ) { $row['field_choices'][] = array( 'key' => $target, 'label' => $target, 'type' => '', 'required' => false, 'unavailable' => true ); } }
			$data['connections'][] = $row;
		}
		$labels = ( new \Certloom\Infrastructure\Repository\ProgramRepository() )->labels_by_ids( array_column( $data['connections'], 'program_id' ) ); if ( is_wp_error( $labels ) ) { $data['error'] = $labels; return $data; } $data['program_labels'] = $labels;

		// Read-only context selection; writes still validate the posted Program and mapping.
		$data['program_id'] = $this->id( isset( $_GET['v2_context'], $_GET['program_id'] ) && '1' === $_GET['v2_context'] ? wp_unslash( $_GET['program_id'] ) : '0' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$data['batch_id'] = $this->id( isset( $_GET['v2_context'], $_GET['batch_id'] ) && '1' === $_GET['v2_context'] ? wp_unslash( $_GET['batch_id'] ) : '0' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$programs = ( new ScopedContextChoices() )->programs( $data['program_id'] ?? 0 ); if ( is_wp_error( $programs ) ) { $data['error'] = $programs; return $data; } $data['programs'] = $programs;
		if ( $data['program_id'] ) {
			$fields = $this->fields( $data['program_id'], $data['batch_id'] ); if ( is_wp_error( $fields ) ) { $data['error'] = $fields; } else { $data['fields'] = $fields; $data['context_ready'] = true; }
		}
		$key = self::SECRET_PREFIX . get_current_user_id(); $secret = get_transient( $key ); delete_transient( $key );
		if ( is_array( $secret ) && \Certloom\Application\Integration\WebhookV2Contract::source_key( $secret['key'] ?? null ) ) {
			$opened = $repo->secret( $secret );
			if ( ! is_wp_error( $opened ) ) { $data['one_time_secret'] = array( 'key' => $secret['key'], 'secret' => $opened ); }
		}
		return $data;
	}
	public function fields( $program, $batch ) {
		$context = ( new RecipientFieldContext() )->load( array( 'program_id' => $program, 'batch_id' => $batch, 'status' => 'active' ) ); if ( is_wp_error( $context ) ) { return $context; }
		$out = array();
		foreach ( $context['contract']['fields'] as $row ) {
			$field = $context['catalog'][ $row['key'] ]; if ( ! isset( $field[ FieldCollectionPolicy::KEY ] ) ) { continue; }
			$policy = ( new FieldCollectionPolicy() )->for_definition( $field ); if ( is_wp_error( $policy ) ) { return $policy; }
			if ( 'source' === $policy['writer'] && 'achievement' === $policy['lifetime'] ) { $out[] = array( 'key' => $row['key'], 'label' => $field['label'], 'type' => $field['type'], 'required' => $row['required'] ); }
		}
		return $out;
	}
	public function create() {
		$this->cap(); check_admin_referer( 'certloom_webhook_v2_create' );
		$fields = $this->posted_fields(); if ( is_wp_error( $fields ) ) { $this->redirect( $fields ); }
		$name = isset( $_POST['name'] ) && is_string( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$result = ( new WebhookV2ConnectionRepository() )->create( $name, $this->id( $_POST['program_id'] ?? null ), $this->id( $_POST['batch_id'] ?? '0' ), $fields );
		$this->secret_result( $result, true );
	}
	public function save() {
		$this->cap(); $key = $this->key(); check_admin_referer( 'certloom_webhook_v2_save_' . $key );
		$fields = $this->posted_fields(); if ( is_wp_error( $fields ) ) { $this->redirect( $fields ); }
		$this->redirect( ( new WebhookV2ConnectionRepository() )->update_mapping( $key, $this->fingerprint(), $fields, isset( $_POST['active'] ) && '1' === $_POST['active'] ) );
	}
	public function rotate() { $this->cap(); $key = $this->key(); check_admin_referer( 'certloom_webhook_v2_rotate_' . $key ); $this->secret_result( ( new WebhookV2ConnectionRepository() )->rotate( $key, $this->fingerprint() ), false ); }
	public function delete() { $this->cap(); $key = $this->key(); check_admin_referer( 'certloom_webhook_v2_delete_' . $key ); $this->redirect( ( new WebhookV2ConnectionRepository() )->delete( $key, $this->fingerprint() ) ); }
	private function posted_fields() {
		$posted = isset( $_POST['field_slots'] ) ? wp_unslash( $_POST['field_slots'] ) : array();
		if ( ! is_array( $posted ) || count( $posted ) > 128 ) { return $this->invalid(); }
		$out = array();
		foreach ( $posted as $target => $slot ) { if ( ! is_string( $slot ) || ! is_string( $target ) ) { return $this->invalid(); } $slot = trim( $slot ); if ( '' === $slot ) { continue; } if ( isset( $out[ $slot ] ) ) { return $this->invalid(); } $out[ $slot ] = $target; }
		return $out;
	}
	private function secret_result( $result, $new ) {
		if ( is_wp_error( $result ) ) { $this->redirect( $result ); }
		if ( ! $this->store_setup_secret( $result ) ) {
			if ( $new ) { $repo = new WebhookV2ConnectionRepository(); $row = $repo->find( $result['key'] ); if ( is_array( $row ) ) { $repo->delete( $row['key'], $repo->revision( $row ) ); } }
			$this->redirect( new \WP_Error( 'certloom_webhook_v2_secret_display_failed', __( 'The signing secret could not be displayed. Review the connection and rotate its secret before sending events.', 'certloom' ) ) );
		}
		$this->redirect( true );
	}
	private function store_setup_secret( array $result ) {
		$sealed = \Certloom\Infrastructure\Integration\IntegrationSecretVault::seal( array( 'secret' => $result['secret'] ) );
		return ! is_wp_error( $sealed ) && set_transient( self::SECRET_PREFIX . get_current_user_id(), array( 'key' => $result['key'], 'secret_sealed' => $sealed ), 10 * MINUTE_IN_SECONDS );
	}
	private function id( $value ) { return is_string( $value ) && 1 === preg_match( '/^(?:0|[1-9][0-9]*)$/D', $value ) && strlen( $value ) <= strlen( (string) PHP_INT_MAX ) && ( strlen( $value ) < strlen( (string) PHP_INT_MAX ) || strcmp( $value, (string) PHP_INT_MAX ) <= 0 ) ? (int) $value : null; }
	private function key() { return isset( $_POST['source_key'] ) && is_string( $_POST['source_key'] ) ? wp_unslash( $_POST['source_key'] ) : ''; }
	private function fingerprint() { return isset( $_POST['fingerprint'] ) && is_string( $_POST['fingerprint'] ) ? wp_unslash( $_POST['fingerprint'] ) : ''; }
	private function cap() { if ( ! current_user_can( \Ecapla_CertVerify_Permissions::CAP_MANAGE_INTEGRATIONS ) || ! DomainFeatureGate::admin_preview_ready() ) { wp_die( esc_html__( 'You do not have permission to configure app connections.', 'certloom' ), '', array( 'response' => 403 ) ); } }
	private function invalid() { return new \WP_Error( 'certloom_webhook_v2_mapping_form_invalid', __( 'Use a different incoming name for each mapped result, with up to 30 fields.', 'certloom' ) ); }
	private function redirect( $result ) { $url = admin_url( 'admin.php?page=certloom-integrations' ); $url = is_wp_error( $result ) ? add_query_arg( 'certloom_error', $result->get_error_message(), $url ) : add_query_arg( 'certloom_notice', 'webhook_v2_saved', $url ); wp_safe_redirect( $url . '#certloom-webhook-v2-setup' ); exit; }
}
