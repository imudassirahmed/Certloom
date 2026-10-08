<?php
namespace Certloom\Infrastructure\Admin;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Home entry point for existing recipient approval and pending issuance services. */
final class AttentionWorkspace {
	public static function assets() {
		wp_enqueue_style( 'certloom-attention', CERTLOOM_URL . 'admin/assets/attention-workspace.css', array( 'certloom-admin-foundation' ), (string) filemtime( CERTLOOM_PATH . 'admin/assets/attention-workspace.css' ) );
		wp_enqueue_script( 'certloom-attention', CERTLOOM_URL . 'admin/assets/attention-workspace.js', array(), (string) filemtime( CERTLOOM_PATH . 'admin/assets/attention-workspace.js' ), true );
	}
	public static function render() {
		$reviews = current_user_can( \Ecapla_CertVerify_Permissions::CAP_MANAGE_RECIPIENTS );
		$requests = current_user_can( \Ecapla_CertVerify_Permissions::CAP_MANAGE_CREDENTIALS );
		if ( ! $reviews && ! $requests ) { return; }
		echo '<section id="needs-attention" class="ecapla-certverify-admin-card certloom-attention-home"><div><span class="certloom-eyebrow">' . esc_html__( 'Keep certificates moving', 'certloom' ) . '</span><h2>' . esc_html__( 'Needs attention', 'certloom' ) . '</h2><p>' . esc_html__( 'Review customer details or see certificates waiting for information, approval or results.', 'certloom' ) . '</p></div><div class="certloom-attention-actions">';
		foreach ( array( 'reviews' => $reviews, 'requests' => $requests ) as $kind => $allowed ) {
			if ( $allowed ) { echo '<button type="button" class="button" data-attention-open="' . esc_attr( $kind ) . '">' . esc_html( 'reviews' === $kind ? __( 'Review customer details', 'certloom' ) : __( 'Waiting certificates', 'certloom' ) ) . '</button>'; }
		}
		echo '</div></section>';
		foreach ( array( 'reviews' => $reviews, 'requests' => $requests ) as $kind => $allowed ) {
			if ( ! $allowed ) { continue; }
			echo '<dialog class="certloom-attention-dialog ecapla-certverify-admin-wrap certloom-admin-shell" data-attention-dialog="' . esc_attr( $kind ) . '" aria-labelledby="certloom-attention-' . esc_attr( $kind ) . '"><header><h2 id="certloom-attention-' . esc_attr( $kind ) . '">' . esc_html( 'reviews' === $kind ? __( 'Customer details', 'certloom' ) : __( 'Waiting certificates', 'certloom' ) ) . '</h2><button type="button" class="button" data-attention-close aria-label="' . esc_attr__( 'Close', 'certloom' ) . '">×</button></header>';
			if ( 'reviews' === $kind ) { ( new RecipientReviewAdmin() )->render( true ); } else { ( new PendingRequestAdmin() )->render( true ); }
			echo '</dialog>';
		}
	}
}
