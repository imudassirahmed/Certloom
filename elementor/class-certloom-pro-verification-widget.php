<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

use Certloom\Infrastructure\Verification\ProVerificationAccess;
use Certloom\Infrastructure\Verification\ProVerificationStudio;

/** Pro editor controls share the native verification studio renderer. */
class Certloom_Pro_Verification_Widget extends \Elementor\Widget_Base {
	public function get_name() { return 'certloom-pro-verification'; }
	public function get_title() { return __( 'Certloom Pro — Verification Studio', 'certloom' ); }
	public function get_icon() { return 'eicon-lock-user'; }
	public function get_categories() { return array( 'general' ); }
	public function get_keywords() { return array( 'certificate', 'credential', 'verify', 'certloom', 'pro' ); }
	public function get_style_depends() { return array( 'certloom-pro-studio' ); }
	public function get_script_depends() { return array( 'ecapla-certloom-public' ); }
	public function show_in_panel() { return ProVerificationAccess::enabled(); }
	public function is_dynamic_content(): bool { return true; }

	protected function register_controls() {
		$this->start_controls_section( 'studio_design', array( 'label' => __( 'Verification Studio', 'certloom' ) ) );
		if ( ! ProVerificationAccess::enabled() ) {
			$this->add_control( 'pro_required', array( 'type' => \Elementor\Controls_Manager::RAW_HTML, 'raw' => esc_html__( 'Verification Studio requires Certloom Pro. This saved widget uses standard verification while Pro is unavailable.', 'certloom' ) ) );
			$this->end_controls_section();
			return;
		}
		$this->add_control( 'preset', array( 'label' => __( 'Design', 'certloom' ), 'type' => \Elementor\Controls_Manager::SELECT, 'options' => ProVerificationStudio::choices(), 'default' => ProVerificationStudio::recommended() ) );
		$this->add_control( 'studio_help', array( 'type' => \Elementor\Controls_Manager::RAW_HTML, 'raw' => esc_html__( 'Choose a ready-made style or Custom to configure each device. Certificate position follows the global public certificate layout setting.', 'certloom' ) ) );
		$this->end_controls_section();
		$this->start_controls_section( 'studio_content', array( 'label' => __( 'Content', 'certloom' ) ) );
		$defaults = Ecapla_CertVerify_Render::default_atts();
		$labels = array( 'eyebrow' => __( 'Eyebrow label', 'certloom' ), 'heading' => __( 'Heading', 'certloom' ), 'description' => __( 'Description', 'certloom' ), 'placeholder' => __( 'Input placeholder', 'certloom' ), 'button_text' => __( 'Button text', 'certloom' ), 'notfound_text' => __( 'Not found message', 'certloom' ) );
		foreach ( $labels as $key => $label ) {
			$this->add_control( $key, array( 'label' => $label, 'type' => in_array( $key, array( 'description', 'notfound_text' ), true ) ? \Elementor\Controls_Manager::TEXTAREA : \Elementor\Controls_Manager::TEXT, 'default' => $defaults[ $key ] ) );
		}
		$this->add_control( 'show_guide', array( 'label' => __( 'Show status guide', 'certloom' ), 'type' => \Elementor\Controls_Manager::SWITCHER, 'return_value' => 'yes', 'default' => 'yes' ) );
		$this->add_control( 'heading_tag', array( 'label' => __( 'Heading tag', 'certloom' ), 'type' => \Elementor\Controls_Manager::SELECT, 'options' => array_combine( array( 'h2', 'h3', 'h4', 'p' ), array( 'H2', 'H3', 'H4', 'P' ) ), 'default' => 'h2' ) );
		$this->add_control( 'content_order', array( 'label' => __( 'Content order', 'certloom' ), 'type' => \Elementor\Controls_Manager::SELECT, 'options' => array( 'eyebrow-heading-description' => __( 'Eyebrow, heading, description', 'certloom' ), 'heading-description-eyebrow' => __( 'Heading, description, eyebrow', 'certloom' ), 'description-heading-eyebrow' => __( 'Description, heading, eyebrow', 'certloom' ) ), 'default' => 'eyebrow-heading-description' ) );
		$this->end_controls_section();
		$this->start_controls_section( 'studio_custom_layout', array( 'label' => __( 'Custom layout • responsive', 'certloom' ), 'condition' => array( 'preset' => 'custom' ) ) );
		$this->add_responsive_control( 'layout', array( 'label' => __( 'Layout', 'certloom' ), 'type' => \Elementor\Controls_Manager::SELECT, 'options' => array( 'split' => __( 'Split columns', 'certloom' ), 'stack' => __( 'Stacked', 'certloom' ), 'inline' => __( 'Compact inline', 'certloom' ) ), 'default' => 'split', 'tablet_default' => 'stack', 'mobile_default' => 'stack' ) );
		$this->add_responsive_control( 'align', array( 'label' => __( 'Intro alignment', 'certloom' ), 'type' => \Elementor\Controls_Manager::SELECT, 'options' => array( 'left' => __( 'Left', 'certloom' ), 'center' => __( 'Center', 'certloom' ), 'right' => __( 'Right', 'certloom' ) ), 'default' => 'left' ) );
		$this->add_responsive_control( 'form_layout', array( 'label' => __( 'Input and button', 'certloom' ), 'type' => \Elementor\Controls_Manager::SELECT, 'options' => array( 'row' => __( 'Same row', 'certloom' ), 'stack' => __( 'Stacked', 'certloom' ) ), 'default' => 'row', 'mobile_default' => 'stack' ) );
		foreach ( ProVerificationStudio::numeric_controls() as $key => $rule ) {
			$this->add_responsive_control( $key, array( 'label' => $rule[0], 'type' => \Elementor\Controls_Manager::NUMBER, 'default' => $rule[1], 'min' => $rule[2], 'max' => $rule[3], 'step' => 1 ) );
		}
		$this->end_controls_section();
		$this->start_controls_section( 'studio_custom_style', array( 'label' => __( 'Custom colors and typography', 'certloom' ), 'tab' => \Elementor\Controls_Manager::TAB_STYLE, 'condition' => array( 'preset' => 'custom' ) ) );
		$this->add_control( 'font_family', array( 'label' => __( 'Font family', 'certloom' ), 'type' => \Elementor\Controls_Manager::SELECT, 'options' => array( 'system' => __( 'System sans serif', 'certloom' ), 'serif' => __( 'Serif', 'certloom' ), 'mono' => __( 'Monospace', 'certloom' ) ), 'default' => 'system' ) );
		$this->add_control( 'heading_weight', array( 'label' => __( 'Heading weight', 'certloom' ), 'type' => \Elementor\Controls_Manager::SELECT, 'options' => array_combine( array( '400', '500', '600', '700' ), array( '400', '500', '600', '700' ) ), 'default' => '700' ) );
		$this->add_control( 'shadow', array( 'label' => __( 'Panel shadow', 'certloom' ), 'type' => \Elementor\Controls_Manager::SELECT, 'options' => array( 'none' => __( 'None', 'certloom' ), 'soft' => __( 'Soft', 'certloom' ), 'elevated' => __( 'Elevated', 'certloom' ) ), 'default' => 'soft' ) );
		foreach ( ProVerificationStudio::color_controls() as $key => $rule ) {
			$this->add_control( 'color_' . $key, array( 'label' => $rule[0], 'type' => \Elementor\Controls_Manager::COLOR, 'default' => $rule[1], 'alpha' => false ) );
		}
		$this->add_group_control( \Elementor\Group_Control_Typography::get_type(), array( 'name' => 'studio_heading_type', 'label' => __( 'Heading typography', 'certloom' ), 'selector' => '{{WRAPPER}} .certloom-pro-custom .cv-widget .cv-heading' ) );
		$this->add_group_control( \Elementor\Group_Control_Typography::get_type(), array( 'name' => 'studio_body_type', 'label' => __( 'Body typography', 'certloom' ), 'selector' => '{{WRAPPER}} .certloom-pro-custom .cv-widget .cv-description' ) );
		$this->add_group_control( \Elementor\Group_Control_Box_Shadow::get_type(), array( 'name' => 'studio_shadow', 'label' => __( 'Panel shadow', 'certloom' ), 'selector' => '{{WRAPPER}} .certloom-pro-custom .cv-widget .cv-search-card' ) );
		$this->end_controls_section();
	}
	protected function render() {
		$settings = $this->get_settings_for_display();
		$config = array_intersect_key( $settings, array_flip( array( 'font_family', 'heading_weight', 'shadow' ) ) );
		$config['preset'] = $settings['preset'] ?? ProVerificationStudio::recommended();
		$config['colors'] = array();
		foreach ( array( 'desktop' => '', 'tablet' => '_tablet', 'mobile' => '_mobile' ) as $device => $suffix ) {
			$config[ $device ] = array();
			foreach ( array_merge( array_keys( ProVerificationStudio::numeric_controls() ), array( 'layout', 'align', 'form_layout' ) ) as $key ) {
				if ( isset( $settings[ $key . $suffix ] ) && '' !== $settings[ $key . $suffix ] ) { $config[ $device ][ $key ] = $settings[ $key . $suffix ]; }
			}
		}
		foreach ( ProVerificationStudio::color_controls() as $key => $rule ) { $config['colors'][ $key ] = $settings[ 'color_' . $key ] ?? ''; }
		$content = array_intersect_key( $settings, array_flip( array( 'eyebrow', 'heading', 'description', 'placeholder', 'button_text', 'notfound_text', 'heading_tag', 'content_order' ) ) );
		$content['show_guide'] = 'yes' === ( $settings['show_guide'] ?? 'yes' );
		echo ProVerificationStudio::render( $config, $content ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Shared renderer escapes content; studio validates all CSS.
	}
}
