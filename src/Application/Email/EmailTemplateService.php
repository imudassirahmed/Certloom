<?php
namespace Certloom\Application\Email;

use Certloom\Application\Rendering\CanonicalJson;

/**
 * Deterministic credential-email composition from an immutable Version snapshot.
 * Templates never query current Recipient/Program/Template rows after issuance.
 */
final class EmailTemplateService {
	const TEMPLATE_SCHEMA = 'certloom-email-template';
	const TEMPLATE_VERSION = 1;

	/** @var CanonicalJson */
	private $json;

	public function __construct( ?CanonicalJson $json = null ) {
		$this->json = $json ?: new CanonicalJson();
	}

	/** @return array<string,mixed> */
	public static function defaults() {
		return array(
			'schema' => self::TEMPLATE_SCHEMA,
			'schema_version' => self::TEMPLATE_VERSION,
			'subject' => __( 'Your {{program_title}} credential', 'certloom' ),
			'html_body' => '<p>' . esc_html__( 'Hello {{recipient_name}},', 'certloom' ) . '</p>'
				. '<p>' . esc_html__( 'Your credential for {{program_title}} has been issued.', 'certloom' ) . '</p>'
				. '<p><strong>' . esc_html__( 'Certificate number:', 'certloom' ) . '</strong> {{credential_number}}</p>'
				. '<p>{{verification_link}}</p>',
			'text_body' => __( "Hello {{recipient_name}},\n\nYour credential for {{program_title}} has been issued.\nCertificate number: {{credential_number}}\n{{verification_url}}", 'certloom' ),
		);
	}

	/** @return array<int,string> */
	public static function tokens() {
		return array( 'recipient_name', 'program_title', 'issuer_name', 'credential_number', 'issue_date', 'expiry_date', 'grade', 'verification_url', 'verification_link' );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function compose( array $snapshot, array $template, $verification_authoritative ) {
		$valid = $this->validate_snapshot( $snapshot );
		if ( is_wp_error( $valid ) ) { return $valid; }
		$template = $this->normalize_template( $template );
		if ( is_wp_error( $template ) ) { return $template; }

		$recipient = (array) $snapshot['recipient'];
		$program = (array) $snapshot['program'];
		$issuer = is_array( $snapshot['issuer'] ?? null ) ? $snapshot['issuer'] : array();
		$credential = (array) $snapshot['credential'];
		$url = $verification_authoritative ? trim( (string) ( $credential['verification_url'] ?? '' ) ) : '';
		$link_html = '' !== $url
			? '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Verify this credential', 'certloom' ) . '</a>'
			: '<span>' . esc_html__( 'Online verification is not available for this credential yet.', 'certloom' ) . '</span>';

		$plain = array(
			'recipient_name' => (string) ( $recipient['display_name'] ?? '' ),
			'program_title' => (string) ( $program['title'] ?? '' ),
			'issuer_name' => (string) ( $issuer['name'] ?? get_bloginfo( 'name' ) ),
			'credential_number' => (string) ( $credential['display_number'] ?? '' ),
			'issue_date' => (string) ( $credential['valid_from'] ?? $credential['issued_at'] ?? '' ),
			'expiry_date' => (string) ( $credential['expires_at'] ?? '' ),
			'grade' => (string) ( $credential['grade'] ?? '' ),
			'verification_url' => $url,
			'verification_link' => $url,
		);
		$html = array();
		foreach ( $plain as $key => $value ) { $html[ $key ] = esc_html( $value ); }
		$html['verification_url'] = '' !== $url ? '<a href="' . esc_url( $url ) . '">' . esc_html( $url ) . '</a>' : '';
		$html['verification_link'] = $link_html;

		$subject = $this->replace( (string) $template['subject'], $plain );
		$subject = sanitize_text_field( wp_strip_all_tags( $subject ) );
		$html_body = $this->replace( (string) $template['html_body'], $html );
		$html_body = wp_kses_post( $html_body );
		$text_body = $this->replace( (string) $template['text_body'], $plain );
		$text_body = trim( wp_strip_all_tags( $text_body ) );
		if ( preg_match( '/\{\{[a-z0-9_:-]+\}\}/i', $subject . "\n" . $html_body . "\n" . $text_body ) ) {
			return new \WP_Error( 'certloom_email_template_token_unknown', __( 'The credential email template contains an unsupported merge token. Use only the documented Certloom placeholders.', 'certloom' ) );
		}
		if ( '' === $subject || '' === trim( $html_body ) || '' === $text_body ) {
			return new \WP_Error( 'certloom_email_template_empty', __( 'The credential email template must produce a subject, HTML body, and plain-text fallback.', 'certloom' ) );
		}

		try {
			$fingerprint = $this->json->hash( array(
				'template_schema' => self::TEMPLATE_SCHEMA,
				'template_version' => self::TEMPLATE_VERSION,
				'subject' => $subject,
				'html_body' => $html_body,
				'text_body' => $text_body,
				'credential_version' => absint( $credential['version_id'] ?? 0 ),
			) );
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'certloom_email_fingerprint_failed', __( 'Certloom could not fingerprint the composed credential email.', 'certloom' ) );
		}

		return array(
			'subject' => $subject,
			'html_body' => $html_body,
			'text_body' => $text_body,
			'subject_sha256' => hash( 'sha256', $subject ),
			'html_sha256' => hash( 'sha256', $html_body ),
			'text_sha256' => hash( 'sha256', $text_body ),
			'composition_sha256' => $fingerprint,
			'verification_authoritative' => (bool) $verification_authoritative,
			'verification_url' => $url,
			'attachment_status' => $this->renderer_authoritative() ? 'available_later_contract' : 'renderer_authority_gated',
			'attachments' => array(),
		);
	}

	/** @return array<string,mixed>|\WP_Error */
	public function normalize_template( array $template ) {
		$template = array_merge( self::defaults(), $template );
		$subject = trim( sanitize_text_field( (string) $template['subject'] ) );
		$html = wp_kses_post( (string) $template['html_body'] );
		$text = sanitize_textarea_field( (string) $template['text_body'] );
		if ( '' === $subject || strlen( $subject ) > 200 ) { return new \WP_Error( 'certloom_email_subject_invalid', __( 'Email subject must contain between 1 and 200 characters.', 'certloom' ) ); }
		if ( '' === trim( $html ) || strlen( $html ) > 20000 ) { return new \WP_Error( 'certloom_email_html_invalid', __( 'Email HTML body must contain between 1 and 20,000 bytes.', 'certloom' ) ); }
		if ( '' === trim( $text ) || strlen( $text ) > 20000 ) { return new \WP_Error( 'certloom_email_text_invalid', __( 'Email plain-text body must contain between 1 and 20,000 bytes.', 'certloom' ) ); }
		return array( 'schema' => self::TEMPLATE_SCHEMA, 'schema_version' => self::TEMPLATE_VERSION, 'subject' => $subject, 'html_body' => $html, 'text_body' => $text );
	}

	/** @return true|\WP_Error */
	private function validate_snapshot( array $snapshot ) {
		if ( 'certloom-credential-snapshot' !== (string) ( $snapshot['_meta']['schema'] ?? '' ) || empty( $snapshot['recipient'] ) || empty( $snapshot['program'] ) || empty( $snapshot['credential'] ) ) {
			return new \WP_Error( 'certloom_email_snapshot_invalid', __( 'Email Delivery requires an immutable Certloom credential snapshot.', 'certloom' ) );
		}
		if ( 'issued' !== (string) ( $snapshot['credential']['issuance_state'] ?? '' ) ) {
			return new \WP_Error( 'certloom_email_snapshot_not_issued', __( 'Only an issued credential Version can be delivered by email.', 'certloom' ) );
		}
		return true;
	}

	/** @param array<string,string> $values */
	private function replace( $template, array $values ) {
		$out = (string) $template;
		foreach ( self::tokens() as $token ) { $out = str_replace( '{{' . $token . '}}', (string) ( $values[ $token ] ?? '' ), $out ); }
		return $out;
	}

	private function renderer_authoritative() {
		return defined( 'CERTLOOM_PATH' ) && is_file( rtrim( CERTLOOM_PATH, '/\\' ) . '/resources/renderer/accepted.json' );
	}
}
