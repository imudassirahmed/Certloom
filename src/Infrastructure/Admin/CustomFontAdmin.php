<?php
namespace Certloom\Infrastructure\Admin;

use Certloom\Application\Rendering\CustomFontLibrary;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class CustomFontAdmin {
	public function register() { add_action( 'wp_ajax_certloom_custom_font_upload', array( $this, 'upload' ) ); }
	public function upload() {
		if ( ! current_user_can( \Ecapla_CertVerify_Permissions::CAP_MANAGE_CREDENTIALS ) ) { wp_send_json_error( array( 'message' => __( 'You do not have permission to manage custom fonts.', 'certloom' ) ), 403 ); }
		check_ajax_referer( 'certloom_custom_font_upload', 'nonce' );
		if ( empty( $_FILES['font'] ) || ! is_array( $_FILES['font'] ) ) { wp_send_json_error( array( 'message' => __( 'Choose a TTF or OTF font file.', 'certloom' ) ), 400 ); }
		$label = isset( $_POST['family'] ) ? sanitize_text_field( wp_unslash( $_POST['family'] ) ) : '';
		$weight = isset( $_POST['weight'] ) ? absint( $_POST['weight'] ) : 400;
		$style = isset( $_POST['style'] ) ? sanitize_key( wp_unslash( $_POST['style'] ) ) : 'normal';
		$result = ( new CustomFontLibrary() )->store_upload( $_FILES['font'], $label, $weight, $style ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- file bytes validated by library.
		if ( is_wp_error( $result ) ) { wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 ); }
		wp_send_json_success( $result );
	}
}
