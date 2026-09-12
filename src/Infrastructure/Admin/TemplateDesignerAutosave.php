<?php
namespace Certloom\Infrastructure\Admin;

use Certloom\Application\Rendering\CanonicalJson;
use Certloom\Application\Rendering\LayoutValidator;
use Certloom\Application\Templates\TemplateDesignerState;
use Certloom\Infrastructure\Repository\TemplateRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * DSN-07 per-user Designer recovery checkpoints.
 *
 * Autosave is deliberately NOT Template authority. One bounded recovery record
 * is stored in user meta and is accepted only while its exact base revision ID
 * and SHA-256 still match the current immutable Template revision. Manual save
 * remains the only path that creates a new Template revision.
 */
final class TemplateDesignerAutosave {
	const VERSION = 1;
	const MAX_BYTES = 2097152;
	const META_PREFIX = 'certloom_template_designer_autosave_';

	/** @var TemplateRepository */ private $templates;
	/** @var LayoutValidator */ private $layouts;
	/** @var CanonicalJson */ private $json;
	/** @var TemplateDesignerState */ private $state;

	public function __construct( ?TemplateRepository $templates = null, ?LayoutValidator $layouts = null, ?CanonicalJson $json = null, ?TemplateDesignerState $state = null ) {
		$this->templates = $templates ?: new TemplateRepository();
		$this->layouts   = $layouts ?: new LayoutValidator();
		$this->json      = $json ?: new CanonicalJson();
		$this->state     = $state ?: new TemplateDesignerState();
	}

	/** @return string */
	public static function meta_key( $template_id ) {
		return self::META_PREFIX . get_current_blog_id() . '_' . absint( $template_id );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function save( $template_id, $user_id, $expected_revision_id, $expected_revision_sha256, $session_id, $layout, $designer_state ) {
		$template_id = absint( $template_id );
		$user_id = absint( $user_id );
		$expected_revision_id = absint( $expected_revision_id );
		$expected_revision_sha256 = strtolower( trim( (string) $expected_revision_sha256 ) );
		$session_id = strtolower( trim( (string) $session_id ) );
		if ( ! $template_id || ! $user_id || ! $expected_revision_id || ! preg_match( '/\A[a-f0-9]{64}\z/', $expected_revision_sha256 ) || ! preg_match( '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $session_id ) ) {
			return new \WP_Error( 'certloom_designer_autosave_review_required', __( 'Autosave requires the exact Template revision, SHA-256 and browser editing session that were opened in Designer.', 'certloom' ) );
		}
		$base = $this->current_base( $template_id, $expected_revision_id, $expected_revision_sha256 );
		if ( is_wp_error( $base ) ) { return $base; }
		$existing_raw = get_user_meta( $user_id, self::meta_key( $template_id ), true );
		if ( is_string( $existing_raw ) && '' !== trim( $existing_raw ) ) {
			$existing = json_decode( $existing_raw, true );
			$existing_session = is_array( $existing ) ? strtolower( trim( (string) ( $existing['session_id'] ?? '' ) ) ) : '';
			$existing_base_id = is_array( $existing ) ? absint( $existing['base_revision_id'] ?? 0 ) : 0;
			$existing_base_sha = is_array( $existing ) ? strtolower( trim( (string) ( $existing['base_revision_sha256'] ?? '' ) ) ) : '';
			if ( ! is_array( $existing ) || self::VERSION !== absint( $existing['version'] ?? 0 ) || ! preg_match( '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $existing_session ) || $existing_base_id !== $expected_revision_id || ! hash_equals( $existing_base_sha, $expected_revision_sha256 ) || ! hash_equals( $existing_session, $session_id ) ) {
				return new \WP_Error( 'certloom_designer_autosave_session_conflict', __( 'Another Designer session owns the existing recovery checkpoint. Certloom refused to overwrite it.', 'certloom' ) );
			}
		}
		try { $normalized_layout = $this->layouts->normalize( $layout ); }
		catch ( \Throwable $error ) { return new \WP_Error( 'certloom_designer_autosave_layout_invalid', __( 'Designer autosave refused an invalid canonical layout.', 'certloom' ) ); }
		$normalized_state = $this->state->normalize( is_array( $designer_state ) ? $designer_state : array(), $normalized_layout );
		try { $layout_json = $this->json->encode( $normalized_layout ); $layout_sha256 = $this->json->hash( $normalized_layout ); }
		catch ( \Throwable $error ) { return new \WP_Error( 'certloom_designer_autosave_encode_failed', __( 'Designer autosave could not canonicalize the recovery layout.', 'certloom' ) ); }
		if ( strlen( $layout_json ) > self::MAX_BYTES ) { return new \WP_Error( 'certloom_designer_autosave_too_large', __( 'Designer autosave exceeded the bounded recovery-checkpoint size.', 'certloom' ) ); }
		$record = array(
			'version' => self::VERSION,
			'template_id' => $template_id,
			'base_revision_id' => $expected_revision_id,
			'base_revision_sha256' => $expected_revision_sha256,
			'session_id' => $session_id,
			'layout_sha256' => $layout_sha256,
			'layout_json' => $layout_json,
			'designer_state' => $normalized_state,
			'saved_at_gmt' => gmdate( 'Y-m-d H:i:s' ),
		);
		$encoded = wp_json_encode( $record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $encoded ) || strlen( $encoded ) > self::MAX_BYTES + 131072 ) { return new \WP_Error( 'certloom_designer_autosave_encode_failed', __( 'Designer autosave could not encode the bounded recovery record.', 'certloom' ) ); }
		update_user_meta( $user_id, self::meta_key( $template_id ), $encoded );
		$stored = get_user_meta( $user_id, self::meta_key( $template_id ), true );
		if ( ! is_string( $stored ) || ! hash_equals( hash( 'sha256', $encoded ), hash( 'sha256', $stored ) ) ) {
			return new \WP_Error( 'certloom_designer_autosave_store_failed', __( 'Designer autosave could not verify the stored recovery checkpoint.', 'certloom' ) );
		}
		// A manual save can advance the immutable revision while an already-started
		// autosave request is finishing. Re-check the exact base after storage so
		// that late request cannot leave a stale recovery warning on the freshly
		// saved Designer screen. Discard only this editing session's checkpoint.
		$still_current = $this->current_base( $template_id, $expected_revision_id, $expected_revision_sha256 );
		if ( is_wp_error( $still_current ) ) {
			$this->discard( $template_id, $user_id, $session_id );
			return $still_current;
		}
		return array( 'status' => 'saved', 'layout_sha256' => $layout_sha256, 'saved_at_gmt' => $record['saved_at_gmt'], 'base_revision_id' => $expected_revision_id );
	}

	/** @return array<string,mixed> */
	public function inspect( $template_id, $user_id ) {
		$template_id = absint( $template_id ); $user_id = absint( $user_id );
		if ( ! $template_id || ! $user_id ) { return array( 'status' => 'none' ); }
		$raw = get_user_meta( $user_id, self::meta_key( $template_id ), true );
		if ( ! is_string( $raw ) || '' === trim( $raw ) ) { return array( 'status' => 'none' ); }
		$record = json_decode( $raw, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $record ) || self::VERSION !== absint( $record['version'] ?? 0 ) || $template_id !== absint( $record['template_id'] ?? 0 ) ) {
			return array( 'status' => 'invalid' );
		}
		$base_id = absint( $record['base_revision_id'] ?? 0 );
		$base_sha = strtolower( trim( (string) ( $record['base_revision_sha256'] ?? '' ) ) );
		$layout_sha = strtolower( trim( (string) ( $record['layout_sha256'] ?? '' ) ) );
		$session_id = strtolower( trim( (string) ( $record['session_id'] ?? '' ) ) );
		$layout_json = isset( $record['layout_json'] ) && is_string( $record['layout_json'] ) ? $record['layout_json'] : '';
		if ( ! $base_id || ! preg_match( '/\A[a-f0-9]{64}\z/', $base_sha ) || ! preg_match( '/\A[a-f0-9]{64}\z/', $layout_sha ) || ! preg_match( '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $session_id ) || '' === $layout_json || strlen( $layout_json ) > self::MAX_BYTES ) {
			return array( 'status' => 'invalid' );
		}
		$layout = json_decode( $layout_json, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $layout ) ) { return array( 'status' => 'invalid' ); }
		try { $layout = $this->layouts->normalize( $layout ); $actual_sha = $this->json->hash( $layout ); }
		catch ( \Throwable $error ) { return array( 'status' => 'invalid' ); }
		if ( ! hash_equals( $layout_sha, $actual_sha ) ) { return array( 'status' => 'invalid' ); }
		$template = $this->templates->find( $template_id );
		if ( is_wp_error( $template ) || ! is_array( $template ) || ! empty( $template['archived_at'] ) || empty( $template['current_revision'] ) ) { return array( 'status' => 'stale', 'record' => $record ); }
		$current_id = absint( $template['current_revision_id'] ?? 0 );
		$current_sha = strtolower( (string) ( $template['current_revision']['layout_sha256'] ?? '' ) );
		if ( $current_id !== $base_id || ! hash_equals( $current_sha, $base_sha ) ) { return array( 'status' => 'stale', 'record' => $record, 'current_revision_id' => $current_id, 'current_revision_sha256' => $current_sha ); }
		$record['layout'] = $layout;
		$record['designer_state'] = $this->state->normalize( isset( $record['designer_state'] ) && is_array( $record['designer_state'] ) ? $record['designer_state'] : array(), $layout );
		return array( 'status' => 'ready', 'record' => $record );
	}

	/** @return true|\WP_Error */
	public function discard( $template_id, $user_id, $session_id = '' ) {
		$template_id = absint( $template_id ); $user_id = absint( $user_id ); $session_id = strtolower( trim( (string) $session_id ) );
		if ( ! $template_id || ! $user_id ) { return new \WP_Error( 'certloom_designer_autosave_discard_invalid', __( 'A valid Template and user are required to discard Designer recovery state.', 'certloom' ) ); }
		$raw = get_user_meta( $user_id, self::meta_key( $template_id ), true );
		if ( ! is_string( $raw ) || '' === trim( $raw ) ) { return true; }
		if ( '' !== $session_id ) {
			if ( ! preg_match( '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $session_id ) ) { return new \WP_Error( 'certloom_designer_autosave_session_invalid', __( 'The Designer recovery session identifier is invalid.', 'certloom' ) ); }
			$record = json_decode( $raw, true ); $stored_session = is_array( $record ) ? strtolower( trim( (string) ( $record['session_id'] ?? '' ) ) ) : '';
			if ( '' === $stored_session || ! hash_equals( $stored_session, $session_id ) ) { return new \WP_Error( 'certloom_designer_autosave_session_conflict', __( 'Another Designer session owns this recovery checkpoint, so Certloom did not discard it.', 'certloom' ) ); }
		}
		delete_user_meta( $user_id, self::meta_key( $template_id ) );
		return true;
	}

	/** @return array<string,mixed>|\WP_Error */
	private function current_base( $template_id, $expected_revision_id, $expected_revision_sha256 ) {
		$template = $this->templates->find( $template_id );
		if ( is_wp_error( $template ) ) { return $template; }
		if ( ! is_array( $template ) || ! empty( $template['archived_at'] ) || empty( $template['current_revision'] ) ) {
			return new \WP_Error( 'certloom_designer_autosave_unavailable', __( 'This Template is unavailable for Designer autosave.', 'certloom' ) );
		}
		$current_id = absint( $template['current_revision_id'] ?? 0 );
		$current_sha = strtolower( (string) ( $template['current_revision']['layout_sha256'] ?? '' ) );
		if ( $current_id !== absint( $expected_revision_id ) || ! hash_equals( $current_sha, strtolower( (string) $expected_revision_sha256 ) ) ) {
			return new \WP_Error( 'certloom_designer_autosave_conflict', __( 'This Template changed after Designer opened it. Autosave stopped without overwriting the newer revision.', 'certloom' ), array( 'current_revision_id' => $current_id, 'current_revision_sha256' => $current_sha ) );
		}
		return $template;
	}
}
