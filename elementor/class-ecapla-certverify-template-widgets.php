<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared control system for Certloom's standalone template widgets.
 */
abstract class Ecapla_CertVerify_Elementor_Template_Widget extends \Elementor\Widget_Base {

	/** @return string */
	abstract protected function get_template_slug();

	public function get_name() {
		return 'certloom-' . $this->get_template_slug();
	}

	public function get_icon() {
		return 'eicon-lock-user';
	}

	/**
	 * Default text alignment for this template.
	 *
	 * @return string
	 */
	protected function get_default_content_alignment() {
		return 'left';
	}

	/**
	 * Whether this design has a distinct search-panel surface.
	 *
	 * @return bool
	 */
	protected function supports_action_surface_controls() {
		return true;
	}

	public function get_categories() {
		return array( 'general' );
	}

	public function get_keywords() {
		return array( 'certificate', 'verify', 'verification', 'lookup', 'credential', 'certloom' );
	}

	public function get_style_depends() {
		return array( 'ecapla-certloom-public' );
	}

	public function get_script_depends() {
		return array( 'ecapla-certloom-public' );
	}

	protected function register_controls() {
		$defaults = Ecapla_CertVerify_Render::template_default_atts();
		$this->register_content_controls( $defaults );
		$this->register_search_controls( $defaults );
		$this->register_guide_controls( $defaults );
		$this->register_responsive_layout_controls();
		$this->register_surface_controls();
		$this->register_typography_controls();
		$this->register_form_style_controls();
		$this->register_result_style_controls();
		$this->register_guide_style_controls();
	}

	private function register_content_controls( $defaults ) {
		$this->start_controls_section( 'cvx_content', array( 'label' => __( 'Content', 'certloom' ) ) );

		$this->add_control(
			'show_eyebrow',
			array(
				'label'        => __( 'Show subheading', 'certloom' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Show', 'certloom' ),
				'label_off'    => __( 'Hide', 'certloom' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);
		$this->add_control(
			'eyebrow',
			array(
				'label'     => __( 'Subheading', 'certloom' ),
				'type'      => \Elementor\Controls_Manager::TEXT,
				'default'   => $defaults['eyebrow'],
				'condition' => array( 'show_eyebrow' => 'yes' ),
			)
		);

		$this->add_control(
			'show_heading',
			array(
				'label'        => __( 'Show heading', 'certloom' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Show', 'certloom' ),
				'label_off'    => __( 'Hide', 'certloom' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);
		$this->add_control(
			'heading',
			array(
				'label'     => __( 'Heading', 'certloom' ),
				'type'      => \Elementor\Controls_Manager::TEXT,
				'default'   => $defaults['heading'],
				'condition' => array( 'show_heading' => 'yes' ),
			)
		);
		$this->add_control(
			'heading_tag',
			array(
				'label'     => __( 'Heading HTML tag', 'certloom' ),
				'type'      => \Elementor\Controls_Manager::SELECT,
				'default'   => 'h2',
				'options'   => array( 'h1' => 'H1', 'h2' => 'H2', 'h3' => 'H3', 'h4' => 'H4', 'p' => 'P', 'div' => 'DIV' ),
				'condition' => array( 'show_heading' => 'yes' ),
			)
		);

		$this->add_control(
			'show_description',
			array(
				'label'        => __( 'Show content', 'certloom' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Show', 'certloom' ),
				'label_off'    => __( 'Hide', 'certloom' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);
		$this->add_control(
			'description',
			array(
				'label'     => __( 'Content', 'certloom' ),
				'type'      => \Elementor\Controls_Manager::TEXTAREA,
				'rows'      => 5,
				'default'   => $defaults['description'],
				'condition' => array( 'show_description' => 'yes' ),
			)
		);

		$this->add_control(
			'content_order',
			array(
				'label'   => __( 'Default content order', 'certloom' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => 'eyebrow-heading-description',
				'options' => array(
					'eyebrow-heading-description' => __( 'Subheading · Heading · Content', 'certloom' ),
					'eyebrow-description-heading' => __( 'Subheading · Content · Heading', 'certloom' ),
					'heading-eyebrow-description' => __( 'Heading · Subheading · Content', 'certloom' ),
					'heading-description-eyebrow' => __( 'Heading · Content · Subheading', 'certloom' ),
					'description-eyebrow-heading' => __( 'Content · Subheading · Heading', 'certloom' ),
					'description-heading-eyebrow' => __( 'Content · Heading · Subheading', 'certloom' ),
				),
			)
		);

		$this->end_controls_section();
	}

	private function register_search_controls( $defaults ) {
		$this->start_controls_section( 'cvx_search_content', array( 'label' => __( 'Search and messages', 'certloom' ) ) );
		$this->add_control( 'placeholder', array( 'label' => __( 'Input placeholder', 'certloom' ), 'type' => \Elementor\Controls_Manager::TEXT, 'default' => $defaults['placeholder'] ) );
		$this->add_control( 'button_text', array( 'label' => __( 'Button text', 'certloom' ), 'type' => \Elementor\Controls_Manager::TEXT, 'default' => $defaults['button_text'] ) );
		$this->add_control( 'notfound_text', array( 'label' => __( 'Not found message', 'certloom' ), 'type' => \Elementor\Controls_Manager::TEXTAREA, 'rows' => 4, 'default' => $defaults['notfound_text'] ) );
		$this->end_controls_section();
	}

	private function register_guide_controls( $defaults ) {
		$this->start_controls_section( 'cvx_guide_content', array( 'label' => __( 'Status guide and credit', 'certloom' ) ) );
		$this->add_control(
			'show_guide',
			array(
				'label'        => __( 'Show status guide', 'certloom' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Show', 'certloom' ),
				'label_off'    => __( 'Hide', 'certloom' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);
		$this->add_control( 'guide_eyebrow', array( 'label' => __( 'Guide subheading', 'certloom' ), 'type' => \Elementor\Controls_Manager::TEXT, 'default' => $defaults['guide_eyebrow'], 'condition' => array( 'show_guide' => 'yes' ) ) );
		$this->add_control( 'guide_heading', array( 'label' => __( 'Guide heading', 'certloom' ), 'type' => \Elementor\Controls_Manager::TEXT, 'default' => $defaults['guide_heading'], 'condition' => array( 'show_guide' => 'yes' ) ) );
		$this->add_control(
			'show_credit',
			array(
				'label'        => __( 'Show credit', 'certloom' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Show', 'certloom' ),
				'label_off'    => __( 'Hide', 'certloom' ),
				'return_value' => 'yes',
				'default'      => $defaults['show_credit'] ? 'yes' : '',
				'separator'    => 'before',
			)
		);
		$this->add_control( 'credit_text', array( 'label' => __( 'Credit text', 'certloom' ), 'type' => \Elementor\Controls_Manager::TEXT, 'default' => $defaults['credit_text'], 'condition' => array( 'show_credit' => 'yes' ) ) );
		$this->end_controls_section();
	}

	private function register_responsive_layout_controls() {
		$this->start_controls_section(
			'cvx_responsive_style',
			array(
				'label' => __( 'Responsive styling', 'certloom' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_responsive_control(
			'content_align',
			array(
				'label'   => __( 'Text alignment', 'certloom' ),
				'type'    => \Elementor\Controls_Manager::CHOOSE,
				'options' => array(
					'left'   => array( 'title' => __( 'Left', 'certloom' ), 'icon' => 'eicon-text-align-left' ),
					'center' => array( 'title' => __( 'Center', 'certloom' ), 'icon' => 'eicon-text-align-center' ),
					'right'  => array( 'title' => __( 'Right', 'certloom' ), 'icon' => 'eicon-text-align-right' ),
				),
				'default'   => $this->get_default_content_alignment(),
				'selectors' => array( '{{WRAPPER}} .cvx-copy, {{WRAPPER}} .cvx-guide-header' => 'text-align: {{VALUE}};' ),
			)
		);

		$this->add_control( 'cvx_panel_style_heading', array( 'label' => __( 'Panel styling', 'certloom' ), 'type' => \Elementor\Controls_Manager::HEADING, 'separator' => 'before' ) );
		$this->add_responsive_control(
			'shell_padding',
			array(
				'label'      => __( 'Main panel padding', 'certloom' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em', 'rem' ),
				'selectors'  => array( '{{WRAPPER}} .cvx-shell' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ),
			)
		);
		$this->add_responsive_control( 'shell_gap', array( 'label' => __( 'Panel section spacing', 'certloom' ), 'type' => \Elementor\Controls_Manager::SLIDER, 'range' => array( 'px' => array( 'min' => 0, 'max' => 96 ) ), 'selectors' => array( '{{WRAPPER}} .cvx-shell' => 'gap: {{SIZE}}{{UNIT}};' ) ) );
		$this->add_responsive_control( 'panel_radius', array( 'label' => __( 'Main panel corner radius', 'certloom' ), 'type' => \Elementor\Controls_Manager::SLIDER, 'range' => array( 'px' => array( 'min' => 0, 'max' => 48 ) ), 'selectors' => array( '{{WRAPPER}} .cvx-shell' => 'border-radius: {{SIZE}}{{UNIT}};' ) ) );
		if ( $this->supports_action_surface_controls() ) {
			$this->add_responsive_control( 'action_padding', array( 'label' => __( 'Search panel padding', 'certloom' ), 'type' => \Elementor\Controls_Manager::DIMENSIONS, 'size_units' => array( 'px', 'em', 'rem' ), 'selectors' => array( '{{WRAPPER}} .cvx-action' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ) ) );
			$this->add_responsive_control( 'action_radius', array( 'label' => __( 'Search panel corner radius', 'certloom' ), 'type' => \Elementor\Controls_Manager::SLIDER, 'range' => array( 'px' => array( 'min' => 0, 'max' => 40 ) ), 'selectors' => array( '{{WRAPPER}} .cvx-action' => 'border-radius: {{SIZE}}{{UNIT}};' ) ) );
		}

		$this->add_control( 'cvx_content_style_heading', array( 'label' => __( 'Content styling', 'certloom' ), 'type' => \Elementor\Controls_Manager::HEADING, 'separator' => 'before' ) );
		$this->add_responsive_control( 'copy_gap', array( 'label' => __( 'Heading content spacing', 'certloom' ), 'type' => \Elementor\Controls_Manager::SLIDER, 'range' => array( 'px' => array( 'min' => 0, 'max' => 48 ) ), 'selectors' => array( '{{WRAPPER}} .cvx-copy' => 'gap: {{SIZE}}{{UNIT}};' ) ) );

		$this->add_control( 'cvx_search_style_heading', array( 'label' => __( 'Search control styling', 'certloom' ), 'type' => \Elementor\Controls_Manager::HEADING, 'separator' => 'before' ) );
		$this->add_responsive_control( 'form_gap', array( 'label' => __( 'Input and button spacing', 'certloom' ), 'type' => \Elementor\Controls_Manager::SLIDER, 'range' => array( 'px' => array( 'min' => 0, 'max' => 40 ) ), 'selectors' => array( '{{WRAPPER}} .cvx-search-form' => 'gap: {{SIZE}}{{UNIT}};' ) ) );
		$this->add_responsive_control( 'control_height', array( 'label' => __( 'Input and button height', 'certloom' ), 'type' => \Elementor\Controls_Manager::SLIDER, 'range' => array( 'px' => array( 'min' => 44, 'max' => 88 ) ), 'selectors' => array( '{{WRAPPER}} .cvx-input-wrap, {{WRAPPER}} .cvx-search-btn' => 'min-height: {{SIZE}}{{UNIT}};' ) ) );
		$this->add_responsive_control( 'input_padding', array( 'label' => __( 'Input shell padding', 'certloom' ), 'type' => \Elementor\Controls_Manager::DIMENSIONS, 'size_units' => array( 'px', 'em', 'rem' ), 'selectors' => array( '{{WRAPPER}} .cvx-input-wrap' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ) ) );
		$this->add_responsive_control( 'input_radius', array( 'label' => __( 'Input corner radius', 'certloom' ), 'type' => \Elementor\Controls_Manager::SLIDER, 'range' => array( 'px' => array( 'min' => 0, 'max' => 36 ) ), 'selectors' => array( '{{WRAPPER}} .cvx-input-wrap' => 'border-radius: {{SIZE}}{{UNIT}};' ) ) );
		$this->add_responsive_control( 'input_icon_size', array( 'label' => __( 'Search icon size', 'certloom' ), 'type' => \Elementor\Controls_Manager::SLIDER, 'range' => array( 'px' => array( 'min' => 12, 'max' => 30 ) ), 'selectors' => array( '{{WRAPPER}} .cvx-input-wrap' => '--cvx-icon-size: {{SIZE}}{{UNIT}};' ) ) );
		$this->add_responsive_control( 'button_padding', array( 'label' => __( 'Button padding', 'certloom' ), 'type' => \Elementor\Controls_Manager::DIMENSIONS, 'size_units' => array( 'px', 'em', 'rem' ), 'selectors' => array( '{{WRAPPER}} .cvx-search-btn' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ) ) );
		$this->add_responsive_control( 'button_radius', array( 'label' => __( 'Button corner radius', 'certloom' ), 'type' => \Elementor\Controls_Manager::SLIDER, 'range' => array( 'px' => array( 'min' => 0, 'max' => 36 ) ), 'selectors' => array( '{{WRAPPER}} .cvx-search-btn' => 'border-radius: {{SIZE}}{{UNIT}};' ) ) );

		$this->add_control( 'cvx_result_guide_style_heading', array( 'label' => __( 'Result and guide styling', 'certloom' ), 'type' => \Elementor\Controls_Manager::HEADING, 'separator' => 'before' ) );
		$this->add_responsive_control( 'result_padding', array( 'label' => __( 'Result card padding', 'certloom' ), 'type' => \Elementor\Controls_Manager::DIMENSIONS, 'size_units' => array( 'px', 'em', 'rem' ), 'selectors' => array( '{{WRAPPER}} .cvx-result-card, {{WRAPPER}} .cvx-result-notfound' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ) ) );
		$this->add_responsive_control( 'guide_gap', array( 'label' => __( 'Guide card spacing', 'certloom' ), 'type' => \Elementor\Controls_Manager::SLIDER, 'range' => array( 'px' => array( 'min' => 0, 'max' => 48 ) ), 'selectors' => array( '{{WRAPPER}} .cvx-guide-grid' => 'gap: {{SIZE}}{{UNIT}};' ) ) );
		$this->add_responsive_control( 'guide_card_padding', array( 'label' => __( 'Guide card padding', 'certloom' ), 'type' => \Elementor\Controls_Manager::DIMENSIONS, 'size_units' => array( 'px', 'em', 'rem' ), 'selectors' => array( '{{WRAPPER}} .cvx-guide-card' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};' ) ) );
		$this->add_responsive_control( 'guide_card_radius', array( 'label' => __( 'Guide card corner radius', 'certloom' ), 'type' => \Elementor\Controls_Manager::SLIDER, 'range' => array( 'px' => array( 'min' => 0, 'max' => 36 ) ), 'selectors' => array( '{{WRAPPER}} .cvx-guide-card' => 'border-radius: {{SIZE}}{{UNIT}};' ) ) );

		$this->end_controls_section();
	}

	private function register_surface_controls() {
		$this->start_controls_section( 'cvx_surface', array( 'label' => __( 'Surface and colors', 'certloom' ), 'tab' => \Elementor\Controls_Manager::TAB_STYLE ) );
		$this->add_control( 'accent_color', array( 'label' => __( 'Accent color', 'certloom' ), 'type' => \Elementor\Controls_Manager::COLOR, 'selectors' => array( '{{WRAPPER}} .cvx-widget' => '--cvx-accent: {{VALUE}}; --cvx-accent-strong: color-mix(in srgb, {{VALUE}} 82%, black);' ) ) );
		$this->add_control( 'heading_color', array( 'label' => __( 'Heading color', 'certloom' ), 'type' => \Elementor\Controls_Manager::COLOR, 'selectors' => array( '{{WRAPPER}} .cvx-widget' => '--cvx-heading: {{VALUE}};' ) ) );
		$this->add_control( 'muted_color', array( 'label' => __( 'Supporting text color', 'certloom' ), 'type' => \Elementor\Controls_Manager::COLOR, 'selectors' => array( '{{WRAPPER}} .cvx-widget' => '--cvx-muted: {{VALUE}};' ) ) );
		$this->add_control( 'panel_background', array( 'label' => __( 'Main panel background', 'certloom' ), 'type' => \Elementor\Controls_Manager::COLOR, 'selectors' => array( '{{WRAPPER}} .cvx-shell' => 'background: {{VALUE}};' ) ) );
		if ( $this->supports_action_surface_controls() ) {
			$this->add_control( 'action_background', array( 'label' => __( 'Search panel background', 'certloom' ), 'type' => \Elementor\Controls_Manager::COLOR, 'selectors' => array( '{{WRAPPER}} .cvx-action' => 'background: {{VALUE}};' ) ) );
			$this->add_control( 'action_border_color', array( 'label' => __( 'Search panel border color', 'certloom' ), 'type' => \Elementor\Controls_Manager::COLOR, 'selectors' => array( '{{WRAPPER}} .cvx-action' => 'border-color: {{VALUE}};' ) ) );
		}
		$this->add_control( 'border_color', array( 'label' => __( 'Border color', 'certloom' ), 'type' => \Elementor\Controls_Manager::COLOR, 'selectors' => array( '{{WRAPPER}} .cvx-widget' => '--cvx-border: {{VALUE}};' ) ) );
		$this->add_group_control( \Elementor\Group_Control_Box_Shadow::get_type(), array( 'name' => 'panel_shadow', 'selector' => '{{WRAPPER}} .cvx-shell' ) );
		$this->end_controls_section();
	}

	private function register_typography_controls() {
		$this->start_controls_section( 'cvx_typography', array( 'label' => __( 'Typography', 'certloom' ), 'tab' => \Elementor\Controls_Manager::TAB_STYLE ) );
		$this->add_group_control( \Elementor\Group_Control_Typography::get_type(), array( 'name' => 'eyebrow_typography', 'label' => __( 'Subheading', 'certloom' ), 'selector' => '{{WRAPPER}} .cvx-eyebrow, {{WRAPPER}} .cvx-guide-eyebrow' ) );
		$this->add_group_control( \Elementor\Group_Control_Typography::get_type(), array( 'name' => 'heading_typography', 'label' => __( 'Heading', 'certloom' ), 'selector' => '{{WRAPPER}} .cvx-heading' ) );
		$this->add_group_control( \Elementor\Group_Control_Typography::get_type(), array( 'name' => 'description_typography', 'label' => __( 'Content', 'certloom' ), 'selector' => '{{WRAPPER}} .cvx-description' ) );
		$this->end_controls_section();
	}

	private function register_form_style_controls() {
		$this->start_controls_section( 'cvx_form_style', array( 'label' => __( 'Search form colors and type', 'certloom' ), 'tab' => \Elementor\Controls_Manager::TAB_STYLE ) );
		$this->add_control( 'input_background', array( 'label' => __( 'Input background', 'certloom' ), 'type' => \Elementor\Controls_Manager::COLOR, 'selectors' => array( '{{WRAPPER}} .cvx-input-wrap' => 'background: {{VALUE}};' ) ) );
		$this->add_control( 'input_border_color', array( 'label' => __( 'Input border color', 'certloom' ), 'type' => \Elementor\Controls_Manager::COLOR, 'selectors' => array( '{{WRAPPER}} .cvx-input-wrap' => 'border-color: {{VALUE}};' ) ) );
		$this->add_control( 'input_focus_color', array( 'label' => __( 'Input focus color', 'certloom' ), 'type' => \Elementor\Controls_Manager::COLOR, 'selectors' => array( '{{WRAPPER}} .cvx-input-wrap:focus-within' => 'border-color: {{VALUE}}; box-shadow: 0 0 0 4px color-mix(in srgb, {{VALUE}} 16%, transparent);' ) ) );
		$this->add_control( 'input_text_color', array( 'label' => __( 'Input text color', 'certloom' ), 'type' => \Elementor\Controls_Manager::COLOR, 'selectors' => array( '{{WRAPPER}} .cvx-widget input.cvx-search-input[type="text"]' => 'color: {{VALUE}};' ) ) );
		$this->add_control( 'placeholder_color', array( 'label' => __( 'Placeholder color', 'certloom' ), 'type' => \Elementor\Controls_Manager::COLOR, 'selectors' => array( '{{WRAPPER}} .cvx-widget input.cvx-search-input[type="text"]::placeholder' => 'color: {{VALUE}};' ) ) );
		$this->add_control( 'input_icon_color', array( 'label' => __( 'Search icon color', 'certloom' ), 'type' => \Elementor\Controls_Manager::COLOR, 'selectors' => array( '{{WRAPPER}} .cvx-input-icon' => 'border-color: {{VALUE}};', '{{WRAPPER}} .cvx-input-icon::after' => 'background: {{VALUE}};' ) ) );
		$this->add_group_control( \Elementor\Group_Control_Typography::get_type(), array( 'name' => 'input_typography', 'label' => __( 'Input typography', 'certloom' ), 'selector' => '{{WRAPPER}} .cvx-widget input.cvx-search-input[type="text"]' ) );
		$this->add_control( 'button_background', array( 'label' => __( 'Button background', 'certloom' ), 'type' => \Elementor\Controls_Manager::COLOR, 'selectors' => array( '{{WRAPPER}} .cvx-widget button.cvx-search-btn' => 'background: {{VALUE}};' ) ) );
		$this->add_control( 'button_hover_background', array( 'label' => __( 'Button hover background', 'certloom' ), 'type' => \Elementor\Controls_Manager::COLOR, 'selectors' => array( '{{WRAPPER}} .cvx-widget button.cvx-search-btn:hover, {{WRAPPER}} .cvx-widget button.cvx-search-btn:focus-visible' => 'background: {{VALUE}};' ) ) );
		$this->add_control( 'button_text_color', array( 'label' => __( 'Button text color', 'certloom' ), 'type' => \Elementor\Controls_Manager::COLOR, 'selectors' => array( '{{WRAPPER}} .cvx-widget button.cvx-search-btn' => 'color: {{VALUE}};' ) ) );
		$this->add_group_control( \Elementor\Group_Control_Typography::get_type(), array( 'name' => 'button_typography', 'label' => __( 'Button typography', 'certloom' ), 'selector' => '{{WRAPPER}} .cvx-widget button.cvx-search-btn' ) );
		$this->end_controls_section();
	}

	private function register_result_style_controls() {
		$this->start_controls_section( 'cvx_result_style', array( 'label' => __( 'Verification result', 'certloom' ), 'tab' => \Elementor\Controls_Manager::TAB_STYLE ) );
		$this->add_control( 'result_background', array( 'label' => __( 'Background', 'certloom' ), 'type' => \Elementor\Controls_Manager::COLOR, 'selectors' => array( '{{WRAPPER}} .cvx-result-card, {{WRAPPER}} .cvx-result-notfound' => 'background: {{VALUE}};' ) ) );
		$this->add_control( 'result_border_color', array( 'label' => __( 'Border color', 'certloom' ), 'type' => \Elementor\Controls_Manager::COLOR, 'selectors' => array( '{{WRAPPER}} .cvx-result-card, {{WRAPPER}} .cvx-result-notfound' => 'border-color: {{VALUE}};' ) ) );
		$this->end_controls_section();
	}

	private function register_guide_style_controls() {
		$this->start_controls_section( 'cvx_guide_style', array( 'label' => __( 'Status guide', 'certloom' ), 'tab' => \Elementor\Controls_Manager::TAB_STYLE, 'condition' => array( 'show_guide' => 'yes' ) ) );
		$this->add_control( 'guide_heading_color', array( 'label' => __( 'Guide heading color', 'certloom' ), 'type' => \Elementor\Controls_Manager::COLOR, 'selectors' => array( '{{WRAPPER}} .cvx-guide-heading' => 'color: {{VALUE}};' ) ) );
		$this->add_control( 'guide_card_background', array( 'label' => __( 'Guide card background', 'certloom' ), 'type' => \Elementor\Controls_Manager::COLOR, 'selectors' => array( '{{WRAPPER}} .cvx-guide-card' => 'background: {{VALUE}};' ) ) );
		$this->end_controls_section();
	}

	protected function render() {
		$settings = $this->get_settings_for_display();
		$defaults = Ecapla_CertVerify_Render::template_default_atts();
		$atts     = array(
			'eyebrow'          => $this->value_or_default( $settings, 'eyebrow', $defaults['eyebrow'] ),
			'heading'          => $this->value_or_default( $settings, 'heading', $defaults['heading'] ),
			'description'      => $this->value_or_default( $settings, 'description', $defaults['description'] ),
			'placeholder'      => $this->value_or_default( $settings, 'placeholder', $defaults['placeholder'] ),
			'button_text'      => $this->value_or_default( $settings, 'button_text', $defaults['button_text'] ),
			'notfound_text'    => $this->value_or_default( $settings, 'notfound_text', $defaults['notfound_text'] ),
			'show_eyebrow'     => isset( $settings['show_eyebrow'] ) && 'yes' === $settings['show_eyebrow'],
			'show_heading'     => isset( $settings['show_heading'] ) && 'yes' === $settings['show_heading'],
			'show_description' => isset( $settings['show_description'] ) && 'yes' === $settings['show_description'],
			'content_order'    => isset( $settings['content_order'] ) ? $settings['content_order'] : $defaults['content_order'],
			'heading_tag'      => isset( $settings['heading_tag'] ) ? $settings['heading_tag'] : 'h2',
			'text_align'       => isset( $settings['content_align'] ) && $settings['content_align'] ? $settings['content_align'] : $this->get_default_content_alignment(),
			'show_guide'       => isset( $settings['show_guide'] ) && 'yes' === $settings['show_guide'],
			'guide_eyebrow'    => $this->value_or_default( $settings, 'guide_eyebrow', $defaults['guide_eyebrow'] ),
			'guide_heading'    => $this->value_or_default( $settings, 'guide_heading', $defaults['guide_heading'] ),
			'show_credit'      => isset( $settings['show_credit'] ) && 'yes' === $settings['show_credit'],
			'credit_text'      => $this->value_or_default( $settings, 'credit_text', $defaults['credit_text'] ),
			'accent'           => $this->value_or_default( $settings, 'accent_color', $defaults['accent'] ),
			'credit_color'     => $defaults['credit_color'],
			'credit_size'      => $defaults['credit_size'],
			'credit_align'     => $defaults['credit_align'],
		);

		echo Ecapla_CertVerify_Render::template_widget( $this->get_template_slug(), $atts ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	private function value_or_default( $settings, $key, $default ) {
		return isset( $settings[ $key ] ) && '' !== $settings[ $key ] ? $settings[ $key ] : $default;
	}
}

class Ecapla_CertVerify_Elementor_Meridian_Widget extends Ecapla_CertVerify_Elementor_Template_Widget {
	protected function get_template_slug() { return 'meridian'; }
	public function get_title() { return __( 'Certloom — Meridian', 'certloom' ); }
}

class Ecapla_CertVerify_Elementor_Halo_Widget extends Ecapla_CertVerify_Elementor_Template_Widget {
	protected function get_template_slug() { return 'halo'; }
	protected function get_default_content_alignment() { return 'center'; }
	public function get_title() { return __( 'Certloom — Halo', 'certloom' ); }
}

class Ecapla_CertVerify_Elementor_Axis_Widget extends Ecapla_CertVerify_Elementor_Template_Widget {
	protected function get_template_slug() { return 'axis'; }
	protected function supports_action_surface_controls() { return false; }
	public function get_title() { return __( 'Certloom — Axis', 'certloom' ); }
}

class Ecapla_CertVerify_Elementor_Nova_Widget extends Ecapla_CertVerify_Elementor_Template_Widget {
	protected function get_template_slug() { return 'nova'; }
	public function get_title() { return __( 'Certloom — Nova', 'certloom' ); }
}

class Ecapla_CertVerify_Elementor_Forge_Widget extends Ecapla_CertVerify_Elementor_Template_Widget {
	protected function get_template_slug() { return 'forge'; }
	public function get_title() { return __( 'Certloom — Forge', 'certloom' ); }
}
