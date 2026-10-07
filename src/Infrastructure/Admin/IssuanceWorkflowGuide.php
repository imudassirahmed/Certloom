<?php
namespace Certloom\Infrastructure\Admin;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Contextual help explains actor and source boundaries without exposing customer data. */
final class IssuanceWorkflowGuide {
	public function register() { add_action( 'current_screen', array( $this, 'add_help' ) ); }
	public function add_help( $screen ) {
		if ( ! is_object( $screen ) || ! method_exists( $screen, 'add_help_tab' ) || false === strpos( (string) ( $screen->id ?? '' ), 'certloom' ) || ! \Ecapla_CertVerify_Permissions::current_user_can_manage() ) { return; }
		$screen->add_help_tab( array( 'id' => 'certloom-issuance-workflow', 'title' => __( 'How certificates are issued', 'certloom' ), 'content' => self::content() ) );
	}
	public static function content() {
		$paragraphs = array(
			__( 'Start with a Program: choose its requirements and design, then decide who supplies each detail. Your organization configures Certloom; your customer completes the permitted details on your website.', 'certloom' ),
			__( 'Personal details are reused across Programs. Enrollment details belong to one exact participation. Staff or a trusted app supplies results. Program defaults hold shared values such as training hours. Matching field labels do not make these sources interchangeable.', 'certloom' ),
			__( 'A trigger confirms what the customer has earned and identifies the exact recipient, Program and participation. Signing up, purchasing and completing a course are different events. Completing a profile never grants qualification.', 'certloom' ),
			__( 'For bulk issuance, select the Program and download its CSV. Use Recipient IDs; include the enrollment ID when needed and enter only staff-owned achievement values. Customer answers, connected-source results and Program defaults are fetched from their configured source.', 'certloom' ),
			__( 'Choose waiting requests when already-qualified customers still need to supply details. Review the CSV, confirm qualification separately, and then process the rows. Ready rows issue; waiting rows appear in the customer account. Required staff results and invalid settings must be fixed before this confirmation.', 'certloom' ),
			__( 'The customer chooses Complete details on their request. Certloom shows that Program’s requirements, including details collected just before issuance. The organization reviews exact submissions where approval is required. A worker then rechecks current qualification, ownership, requirements and design before issuing once.', 'certloom' ),
			__( 'Open Certificate requests to review held work and its activity. Recheck requirements queues an assessment; it does not fill answers or grant qualification. Cancellation checks for an already-issued receipt first. Issued certificates keep their original details when a profile changes.', 'certloom' ),
			__( 'Connected apps use their own supported mappings and trusted evidence. Do not send arbitrary customer fields to a completion-only integration. A source without a supported field mapping stays blocked until its requirements are supplied through an accepted source.', 'certloom' ),
		);
		$html = '';
		foreach ( $paragraphs as $text ) { $html .= '<p>' . esc_html( $text ) . '</p>'; }
		return $html . '<p><a href="' . esc_url( admin_url( 'admin.php?page=certloom-pending-requests' ) ) . '">' . esc_html__( 'Open Certificate requests', 'certloom' ) . '</a></p>';
	}
}
