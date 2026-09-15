<?php
namespace Certloom\Infrastructure\Email;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Calm, email-client-safe starter designs. Factory designs are customized by duplication, not in-place mutation. */
final class EmailDesignPresetCatalog {
	const PREFIX = 'certloom-email-preset-';
	const VERSION = 2;

	/** @return array<int,array<string,mixed>> */
	public function all() {
		$definitions = array(
			EmailDesignPurposes::ISSUED => array( 'Credential ready', 'Your {{program_title}} credential', 'Your credential is ready to view and verify.', 'Your credential is ready', 'Your credential for {{program_title}} has been issued.', 'View credential' ),
			EmailDesignPurposes::EXPIRY_REMINDER => array( 'Expiry reminder', 'Your {{program_title}} credential expires soon', 'A reminder about your credential expiry date.', 'Your credential expires soon', 'Your {{program_title}} credential is approaching its expiry date.', 'Review credential' ),
			EmailDesignPurposes::EXPIRED => array( 'Credential expired', 'Your {{program_title}} credential has expired', 'An update about your credential status.', 'Your credential has expired', 'Your {{program_title}} credential has reached its expiry date.', 'View credential' ),
			EmailDesignPurposes::SUSPENDED => array( 'Credential suspended', 'Update to your {{program_title}} credential', 'An important credential status update.', 'Credential status update', 'Your {{program_title}} credential is currently suspended.', 'View credential' ),
			EmailDesignPurposes::REVOKED => array( 'Credential revoked', 'Update to your {{program_title}} credential', 'An important credential status update.', 'Credential status update', 'Your {{program_title}} credential has been revoked.', 'View credential' ),
			EmailDesignPurposes::REINSTATED => array( 'Credential reinstated', 'Your {{program_title}} credential is active again', 'Your credential has been reinstated.', 'Your credential is active again', 'Your {{program_title}} credential has been reinstated.', 'View credential' ),
			EmailDesignPurposes::UPDATED => array( 'Credential updated', 'Your {{program_title}} credential was updated', 'New credential information is available.', 'Your credential was updated', 'There is an update to your {{program_title}} credential.', 'View credential' ),
		);
		$out = array();
		foreach ( $definitions as $purpose => $copy ) {
			$out[] = array(
				'id' => self::PREFIX . str_replace( '_', '-', $purpose ),
				'name' => $copy[0], 'purpose' => $purpose, 'preheader' => $copy[2], 'subject' => $copy[1], 'active' => 1,
				'source' => 'factory', 'factory_version' => self::VERSION,
				'blocks' => array(
					array( 'type' => 'heading', 'text' => $copy[3], 'level' => 1 ),
					array( 'type' => 'text', 'html' => '<p>' . esc_html__( 'Hello {{recipient_name}},', 'certloom' ) . '</p><p>' . esc_html( $copy[4] ) . '</p>' ),
					array( 'type' => 'button', 'text' => $copy[5], 'url' => '{{verification_url}}', 'align' => 'left' ),
					array( 'type' => 'divider' ),
					array(
						'type' => 'container', 'direction' => 'horizontal', 'gap' => 20, 'stack_mobile' => true,
						'children' => array(
							array( 'type' => 'container', 'direction' => 'vertical', 'basis' => 50, 'children' => array( array( 'type' => 'text', 'html' => '<p><strong>' . esc_html__( 'Credential', 'certloom' ) . '</strong><br>{{credential_number}}</p>' ) ) ),
							array( 'type' => 'container', 'direction' => 'vertical', 'basis' => 50, 'children' => array( array( 'type' => 'text', 'html' => '<p><strong>' . esc_html__( 'Issuer', 'certloom' ) . '</strong><br>{{issuer_name}}</p>' ) ) ),
						),
					),
				),
				'theme' => EmailDesignCompiler::default_theme(),
			);
		}
		return $out;
	}
}
