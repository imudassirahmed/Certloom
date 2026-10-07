<?php
namespace Certloom\Infrastructure\Admin;

use Certloom\Application\Setup\FirstCredentialSetupPlan;
use Certloom\Infrastructure\Email\EmailDesignPurposes;
use Certloom\Infrastructure\Email\EmailTemplateCatalogRepository;
use Certloom\Infrastructure\Rendering\RenderPlanHtmlPreview;
use Certloom\Infrastructure\Rendering\TemplatePreviewService;
use Certloom\Infrastructure\Repository\TemplateRepository;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Read-only setup assessment with synthetic artwork; never recipient eligibility or a delivery test. */
final class WizardSetupReadiness {
	public function inspect( array $draft ) {
		$sections = array(); $blocked = 0; $preview = null;
		$add = static function ( $key, $label, $status, $message, $step ) use ( &$sections, &$blocked ) {
			if ( 'blocked' === $status ) { ++$blocked; }
			$sections[] = array( 'key' => $key, 'label' => $label, 'status' => $status, 'message' => $message, 'step' => $step );
		};
		$organization_ok = '' !== trim( (string) ( $draft['organization_name'] ?? '' ) );
		$add( 'organization', __( 'Organization', 'certloom' ), $organization_ok ? 'ready' : 'blocked', $organization_ok ? __( 'Your issuing organization has a name.', 'certloom' ) : __( 'Add the organization name before saving setup.', 'certloom' ), 'organization' );
		foreach ( array( 'organization_logo_id' => 'organization', 'setup_new_signer_signature_id' => 'context' ) as $key => $step ) {
			if ( empty( $draft[ $key ] ) ) { continue; }
			if ( ! $this->image_available( absint( $draft[ $key ] ), true ) ) { $add( 'media-' . $key, __( 'Organization image', 'certloom' ), 'blocked', __( 'A selected logo, seal or signature is unavailable or has an unsupported image format. Choose another image.', 'certloom' ), $step ); }
		}
		$field_check = ( new WizardFieldSetupCheck() )->inspect( $draft );
		$add( 'fields', __( 'Fields and sources', 'certloom' ), is_wp_error( $field_check ) ? 'blocked' : 'ready', is_wp_error( $field_check ) ? $field_check->get_error_message() : __( 'Field types, sources, inherited requirements and private artwork rules passed the configuration check.', 'certloom' ), 'fields' );
		$template = null; $mode = $draft['presentation_mode'] ?? 'details_only';
		if ( 'design' === $mode ) {
			$template = ( new TemplateRepository() )->find_published( absint( $draft['design_template_id'] ?? 0 ) );
			if ( is_wp_error( $template ) || ! is_array( $template ) || ! empty( $template['archived_at'] ) ) {
				$add( 'artwork', __( 'Certificate design', 'certloom' ), 'blocked', __( 'Choose an available published design, or use details only.', 'certloom' ), 'appearance' ); $template = null;
			} else {
				$render = ( new TemplatePreviewService() )->build( $template, 'published', 'standard', (string) ( $draft['usage_profile'] ?? '' ) );
				if ( is_wp_error( $render ) ) { $add( 'artwork', __( 'Certificate design', 'certloom' ), 'blocked', __( 'The design preview could not be built. Review its elements and assets in Design Center.', 'certloom' ), 'appearance' ); }
				else {
					try {
						$html = ( new RenderPlanHtmlPreview() )->present( $render['render_plan'] );
						$preview = array( 'example_only' => true, 'html' => (string) $html['html'], 'caption' => __( 'Example only. These are synthetic profile values, not a customer certificate or proof of eligibility.', 'certloom' ) );
						$add( 'artwork', __( 'Certificate design', 'certloom' ), 'ready', __( 'The published design renders with synthetic profile examples. Final issuance resolves actual approved values.', 'certloom' ), 'appearance' );
					} catch ( \Throwable $error ) { $add( 'artwork', __( 'Certificate design', 'certloom' ), 'blocked', __( 'The artwork preview could not be presented. Review the design before continuing.', 'certloom' ), 'appearance' ); }
				}
			}
		} elseif ( 'image' === $mode ) {
			$available = $this->image_available( absint( $draft['certificate_image_id'] ?? 0 ), false );
			$add( 'artwork', __( 'Certificate image', 'certloom' ), $available ? 'ready' : 'blocked', $available ? __( 'The selected PNG/JPEG is available. Confirm its recipient-specific content when issuing.', 'certloom' ) : __( 'Choose an available PNG or JPEG certificate image.', 'certloom' ), 'appearance' );
		} elseif ( 'details_only' === $mode ) {
			$add( 'artwork', __( 'Presentation', 'certloom' ), 'ready', __( 'Details-only credentials can be issued without artwork.', 'certloom' ), 'appearance' );
		} else { $add( 'artwork', __( 'Presentation', 'certloom' ), 'blocked', __( 'Choose details only, an image or a certificate design.', 'certloom' ), 'appearance' ); }
		$plan = ( new FirstCredentialSetupPlan() )->build( $template, $draft['selected_fields'] ?? array(), \Ecapla_CertVerify_Fields::get_field_map(), $draft['custom_fields'] ?? array() );
		$context = ( new WizardContextRequirementsCheck() )->validate( $draft, $plan, false );
		$add( 'context', __( 'Program and relationships', 'certloom' ), is_wp_error( $context ) ? 'blocked' : 'ready', is_wp_error( $context ) ? $context->get_error_message() : __( 'Selected relationships and reusable facts required by the design passed the context check. New named records are created only when you save setup.', 'certloom' ), 'context' );
		$email_mode = $draft['setup_email_mode'] ?? 'inherit';
		if ( 'selected' === $email_mode ) {
			$email = ( new EmailTemplateCatalogRepository() )->get( $draft['setup_email_design_id'] ?? '' );
			$ok = is_array( $email ) && ! empty( $email['active'] ) && EmailDesignPurposes::ISSUED === ( $email['purpose'] ?? '' );
			$add( 'email', __( 'Email design', 'certloom' ), $ok ? 'ready' : 'blocked', $ok ? __( 'The selected credential-issued email design is available. No test email has been sent.', 'certloom' ) : __( 'Choose an active credential-issued email design or keep the current email settings.', 'certloom' ), 'email' );
		} elseif ( 'inherit' === $email_mode ) {
			$add( 'email', __( 'Email delivery', 'certloom' ), 'next_step', __( 'Current email settings will be kept. Delivery and mail-provider configuration are checked separately; this preview sends nothing.', 'certloom' ), 'email' );
		} else { $add( 'email', __( 'Email delivery', 'certloom' ), 'blocked', __( 'Choose the current email settings or an available email design.', 'certloom' ), 'email' ); }
		$next = is_wp_error( $field_check ) ? array() : $field_check['next_steps'];
		$add( 'issuance', __( 'Issuing certificates', 'certloom' ), 'next_step', __( 'Save this reviewed setup, then issue manually, review a CSV import, or configure a supported automation. Enrollment or filling a profile alone does not qualify someone. Required customer answers, approvals and verified results must be ready at final issuance.', 'certloom' ), 'configuration' );
		return array( 'configuration_only' => true, 'recipient_evaluated' => false, 'ready_to_save' => 0 === $blocked, 'blocker_count' => $blocked, 'sections' => $sections, 'checks' => array(), 'next_steps' => $next, 'preview' => $preview, 'message' => 0 === $blocked ? __( 'The setup checks passed. Review the next steps below; no certificate has been issued and recipient eligibility has not been evaluated.', 'certloom' ) : __( 'Some setup choices need attention. Review each item below before saving. Your draft remains unchanged.', 'certloom' ) );
	}
	private function image_available( $id, $allow_webp ) {
		$types = $allow_webp ? array( 'image/png', 'image/jpeg', 'image/webp' ) : array( 'image/png', 'image/jpeg' );
		if ( ! $id || ! in_array( get_post_mime_type( $id ), $types, true ) ) { return false; }
		$path = get_attached_file( $id );
		return is_string( $path ) && is_file( $path ) && is_readable( $path );
	}
}
