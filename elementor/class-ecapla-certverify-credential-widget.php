<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Elementor Credential Profile widget. Existing six verification widgets remain unchanged. */
class Ecapla_CertVerify_Elementor_Credential_Widget extends \Elementor\Widget_Base {
	public function get_name() { return 'certloom-credential'; }
	public function get_title() { return __( 'Certloom — Credential Profile', 'certloom' ); }
	public function get_icon() { return 'eicon-id'; }
	public function get_categories() { return array( 'general' ); }
	public function get_keywords() { return array( 'certificate', 'credential', 'profile', 'verification', 'certloom' ); }
	public function get_style_depends() { return array( 'ecapla-certloom-public' ); }
	public function get_script_depends() { return array( 'ecapla-certloom-public' ); }

	protected function register_controls() {
		$this->start_controls_section( 'credential_content', array( 'label' => __( 'Credential', 'certloom' ) ) );
		$this->add_control( 'certificate_number', array( 'label' => __( 'Certificate number', 'certloom' ), 'type' => \Elementor\Controls_Manager::TEXT, 'placeholder' => 'CERT-2026-0001' ) );
		$this->add_control( 'heading', array( 'label' => __( 'Heading', 'certloom' ), 'type' => \Elementor\Controls_Manager::TEXT, 'default' => __( 'Credential profile', 'certloom' ) ) );
		$this->add_control( 'notfound_text', array( 'label' => __( 'Not found message', 'certloom' ), 'type' => \Elementor\Controls_Manager::TEXTAREA, 'default' => __( 'This credential could not be found or is not available.', 'certloom' ) ) );
		$this->end_controls_section();
	}

	protected function render() {
		$settings = $this->get_settings_for_display();
		$number = isset( $settings['certificate_number'] ) ? trim( sanitize_text_field( (string) $settings['certificate_number'] ) ) : '';
		if ( '' === $number && \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
			echo '<div class="certloom-credential-profile cvp-widget"><div class="cvp-result-notfound"><p>' . esc_html__( 'Enter a certificate number in the widget settings to preview the credential profile.', 'certloom' ) . '</p></div></div>';
			return;
		}
		echo Ecapla_CertVerify_Render::credential_profile( $number, array( 'heading' => $settings['heading'] ?? '', 'notfound_text' => $settings['notfound_text'] ?? '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
