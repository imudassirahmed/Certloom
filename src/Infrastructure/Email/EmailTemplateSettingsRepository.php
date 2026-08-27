<?php
namespace Certloom\Infrastructure\Email;

use Certloom\Application\Email\EmailTemplateService;

/** WordPress-local versioned settings for the issued-credential email template. */
final class EmailTemplateSettingsRepository {
	const OPTION = 'certloom_email_template_issued_v1';

	/** @return array<string,mixed> */
	public function get() {
		$value = get_option( self::OPTION, array() );
		return is_array( $value ) ? array_merge( EmailTemplateService::defaults(), $value ) : EmailTemplateService::defaults();
	}

	/** @return true|\WP_Error */
	public function save( array $template ) {
		$service = new EmailTemplateService();
		$clean = $service->normalize_template( $template );
		if ( is_wp_error( $clean ) ) { return $clean; }
		$saved = update_option( self::OPTION, $clean, false );
		return $saved || $clean === get_option( self::OPTION, array() )
			? true
			: new \WP_Error( 'certloom_email_template_save_failed', __( 'Certloom could not save the credential email template.', 'certloom' ) );
	}
}
