<?php
namespace Certloom\Infrastructure\Email;

use Certloom\Application\Email\EmailTemplateService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Backward-compatible access to the default issued-credential email template.
 *
 * New product surfaces use EmailTemplateCatalogRepository. Existing code that
 * still asks for the historic single template transparently resolves the
 * catalog's default item so there is only one customer-editable authority.
 */
final class EmailTemplateSettingsRepository {
	const OPTION = 'certloom_email_template_issued_v1';

	/** @return array<string,mixed> */
	public function get() {
		$template = ( new EmailTemplateCatalogRepository() )->get( EmailTemplateCatalogRepository::DEFAULT_ID );
		if ( is_array( $template ) ) {
			return array(
				'schema'         => $template['schema'],
				'schema_version' => $template['schema_version'],
				'subject'        => $template['subject'],
				'html_body'      => $template['html_body'],
				'text_body'      => $template['text_body'],
			);
		}
		$value = get_option( self::OPTION, array() );
		return is_array( $value ) ? array_merge( EmailTemplateService::defaults(), $value ) : EmailTemplateService::defaults();
	}

	/** @return true|\WP_Error */
	public function save( array $template ) {
		$service = new EmailTemplateService();
		$clean   = $service->normalize_template( $template );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}

		$catalog_result = ( new EmailTemplateCatalogRepository() )->save(
			array_merge(
				$clean,
				array(
					'id'     => EmailTemplateCatalogRepository::DEFAULT_ID,
					'name'   => __( 'Default credential email', 'certloom' ),
					'active' => 1,
				)
			)
		);
		if ( is_wp_error( $catalog_result ) ) {
			return $catalog_result;
		}

		// Keep the historic option synchronized for rollback/read compatibility.
		$saved = update_option( self::OPTION, $clean, false );
		return $saved || $clean === get_option( self::OPTION, array() )
			? true
			: new \WP_Error( 'certloom_email_template_save_failed', __( 'Certloom could not save the credential email template.', 'certloom' ) );
	}
}
