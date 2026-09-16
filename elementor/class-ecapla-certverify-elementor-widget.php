<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Elementor widget for the certificate verification search. Content is
 * produced by the exact same Ecapla_CertVerify_Render::widget() call the [certloom]
 * shortcode uses, so results can never differ between the two, only the
 * controls surrounding them do.
 *
 * Style controls are grouped by what they touch, in the order it
 * appears on the page: the search box itself, then everything that
 * shows up after the search button is pressed (the result card, the
 * status badges, the status guide, and the credit line). Every
 * spacing and sizing control uses add_responsive_control so it can be
 * dialled in separately for desktop, tablet, and mobile.
 */
class Ecapla_CertVerify_Elementor_Widget extends \Elementor\Widget_Base {

	public function get_name() {
		return 'certloom';
	}

	public function get_title() {
		return __( 'Certloom', 'certloom' );
	}

	public function get_icon() {
		return 'eicon-lock-user';
	}

	public function get_categories() {
		return array( 'general' );
	}

	public function get_keywords() {
		return array( 'certificate', 'verify', 'verification', 'lookup', 'ecapla' );
	}

	public function get_style_depends() {
		return array( 'ecapla-certloom-public' );
	}

	public function get_script_depends() {
		return array( 'ecapla-certloom-public' );
	}

	protected function register_controls() {
		$defaults = Ecapla_CertVerify_Render::default_atts();

		$this->register_content_controls( $defaults );
		$this->register_color_controls();
		$this->register_typography_controls();
		$this->register_search_input_controls();
		$this->register_search_button_controls();
		$this->register_result_card_controls();
		$this->register_badge_controls();
		$this->register_guide_controls();
		$this->register_credit_controls();
		$this->register_layout_controls();
	}

	private function register_content_controls( $defaults ) {
		$this->start_controls_section(
			'content_search',
			array(
				'label' => __( 'Search box', 'certloom' ),
			)
		);

		$this->add_control(
			'eyebrow',
			array(
				'label'   => __( 'Eyebrow label', 'certloom' ),
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => $defaults['eyebrow'],
			)
		);

		$this->add_control(
			'heading',
			array(
				'label'   => __( 'Heading', 'certloom' ),
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => $defaults['heading'],
			)
		);

		$this->add_control(
			'description',
			array(
				'label'   => __( 'Description', 'certloom' ),
				'type'    => \Elementor\Controls_Manager::TEXTAREA,
				'default' => $defaults['description'],
			)
		);

		$this->add_control(
			'placeholder',
			array(
				'label'   => __( 'Input placeholder', 'certloom' ),
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => $defaults['placeholder'],
			)
		);

		$this->add_control(
			'button_text',
			array(
				'label'   => __( 'Button text', 'certloom' ),
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => $defaults['button_text'],
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'content_result',
			array(
				'label' => __( 'Result', 'certloom' ),
			)
		);

		$this->add_control(
			'notfound_text',
			array(
				'label'   => __( 'Not found message', 'certloom' ),
				'type'    => \Elementor\Controls_Manager::TEXTAREA,
				'default' => $defaults['notfound_text'],
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'content_guide',
			array(
				'label' => __( 'Status guide', 'certloom' ),
			)
		);

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

		$this->end_controls_section();

		$this->start_controls_section(
			'content_credit',
			array(
				'label' => __( 'Credit line', 'certloom' ),
			)
		);

		$this->add_control(
			'show_credit',
			array(
				'label'        => __( 'Show credit line', 'certloom' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Show', 'certloom' ),
				'label_off'    => __( 'Hide', 'certloom' ),
				'return_value' => 'yes',
				'default'      => $defaults['show_credit'] ? 'yes' : '',
			)
		);

		$this->add_control(
			'credit_text',
			array(
				'label'     => __( 'Credit text', 'certloom' ),
				'type'      => \Elementor\Controls_Manager::TEXT,
				'default'   => $defaults['credit_text'],
				'condition' => array(
					'show_credit' => 'yes',
				),
			)
		);

		$this->end_controls_section();
	}

	private function register_color_controls() {
		$this->start_controls_section(
			'style_colors',
			array(
				'label' => __( 'Colors', 'certloom' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'accent_color',
			array(
				'label'     => __( 'Accent color', 'certloom' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => $this->get_default( 'accent' ),
				'selectors' => array(
					'{{WRAPPER}} .cv-widget' => '--cv-accent: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'heading_color',
			array(
				'label'     => __( 'Heading color', 'certloom' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .cv-widget' => '--cv-heading: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'muted_color',
			array(
				'label'     => __( 'Muted text color', 'certloom' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .cv-widget' => '--cv-muted: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'border_color',
			array(
				'label'     => __( 'Border color', 'certloom' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .cv-widget' => '--cv-border: {{VALUE}};',
				),
			)
		);

		$this->end_controls_section();
	}

	private function register_typography_controls() {
		$this->start_controls_section(
			'style_typography',
			array(
				'label' => __( 'Typography', 'certloom' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'eyebrow_typography',
				'label'    => __( 'Eyebrow', 'certloom' ),
				'selector' => '{{WRAPPER}} .cv-eyebrow',
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'heading_typography',
				'label'    => __( 'Heading', 'certloom' ),
				'selector' => '{{WRAPPER}} .cv-heading',
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'description_typography',
				'label'    => __( 'Description', 'certloom' ),
				'selector' => '{{WRAPPER}} .cv-description',
			)
		);

		$this->end_controls_section();
	}

	private function register_search_input_controls() {
		$this->start_controls_section(
			'style_input',
			array(
				'label' => __( 'Search input', 'certloom' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'input_border_color',
			array(
				'label'     => __( 'Border color', 'certloom' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .cv-search-input' => 'border-color: {{VALUE}};',
				),
			)
		);

		$this->add_responsive_control(
			'input_border_width',
			array(
				'label'      => __( 'Border width', 'certloom' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 6,
					),
				),
				'default'    => array(
					'unit' => 'px',
					'size' => 1,
				),
				'selectors'  => array(
					'{{WRAPPER}} .cv-search-input' => 'border-width: {{SIZE}}{{UNIT}}; border-style: solid;',
				),
			)
		);

		$this->add_responsive_control(
			'input_border_radius',
			array(
				'label'      => __( 'Corner radius', 'certloom' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 40,
					),
				),
				'default'    => array(
					'unit' => 'px',
					'size' => 8,
				),
				'selectors'  => array(
					'{{WRAPPER}} .cv-search-input' => 'border-radius: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			'input_padding',
			array(
				'label'      => __( 'Padding', 'certloom' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em', '%' ),
				'default'    => array(
					'top'      => '12',
					'right'    => '16',
					'bottom'   => '12',
					'left'     => '16',
					'unit'     => 'px',
					'isLinked' => false,
				),
				'selectors'  => array(
					'{{WRAPPER}} .cv-search-input' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'input_typography',
				'label'    => __( 'Typography', 'certloom' ),
				'selector' => '{{WRAPPER}} .cv-search-input',
			)
		);

		$this->end_controls_section();
	}

	private function register_search_button_controls() {
		$this->start_controls_section(
			'style_button',
			array(
				'label' => __( 'Search button', 'certloom' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'button_bg_color',
			array(
				'label'     => __( 'Background color', 'certloom' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .cv-search-btn' => 'background: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'button_text_color',
			array(
				'label'     => __( 'Text color', 'certloom' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .cv-search-btn' => 'color: {{VALUE}};',
				),
			)
		);

		$this->add_responsive_control(
			'button_border_radius',
			array(
				'label'      => __( 'Corner radius', 'certloom' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 40,
					),
				),
				'default'    => array(
					'unit' => 'px',
					'size' => 8,
				),
				'selectors'  => array(
					'{{WRAPPER}} .cv-search-btn' => 'border-radius: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			'button_padding',
			array(
				'label'      => __( 'Padding', 'certloom' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em', '%' ),
				'default'    => array(
					'top'      => '12',
					'right'    => '22',
					'bottom'   => '12',
					'left'     => '22',
					'unit'     => 'px',
					'isLinked' => false,
				),
				'selectors'  => array(
					'{{WRAPPER}} .cv-search-btn' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'button_typography',
				'label'    => __( 'Typography', 'certloom' ),
				'selector' => '{{WRAPPER}} .cv-search-btn',
			)
		);

		$this->end_controls_section();
	}

	private function register_result_card_controls() {
		$this->start_controls_section(
			'style_result',
			array(
				'label' => __( 'Result card', 'certloom' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'result_bg_color',
			array(
				'label'     => __( 'Background color', 'certloom' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#fafaf8',
				'selectors' => array(
					'{{WRAPPER}} .cv-result-card, {{WRAPPER}} .cv-result-notfound' => 'background: {{VALUE}};',
				),
			)
		);

		$this->add_responsive_control(
			'result_border_radius',
			array(
				'label'      => __( 'Corner radius', 'certloom' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 40,
					),
				),
				'default'    => array(
					'unit' => 'px',
					'size' => 8,
				),
				'selectors'  => array(
					'{{WRAPPER}} .cv-result-card, {{WRAPPER}} .cv-result-notfound' => 'border-radius: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			'result_padding',
			array(
				'label'      => __( 'Padding', 'certloom' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em', '%' ),
				'default'    => array(
					'top'      => '20',
					'right'    => '24',
					'bottom'   => '20',
					'left'     => '24',
					'unit'     => 'px',
					'isLinked' => false,
				),
				'selectors'  => array(
					'{{WRAPPER}} .cv-result-card, {{WRAPPER}} .cv-result-notfound' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			'row_spacing',
			array(
				'label'      => __( 'Row spacing', 'certloom' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 30,
					),
				),
				'default'    => array(
					'unit' => 'px',
					'size' => 10,
				),
				'selectors'  => array(
					'{{WRAPPER}} .cv-result-row' => 'padding-top: {{SIZE}}{{UNIT}}; padding-bottom: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'result_label_typography',
				'label'    => __( 'Label typography', 'certloom' ),
				'selector' => '{{WRAPPER}} .cv-result-row dt',
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'result_value_typography',
				'label'    => __( 'Value typography', 'certloom' ),
				'selector' => '{{WRAPPER}} .cv-result-row dd',
			)
		);

		$this->end_controls_section();
	}

	private function register_badge_controls() {
		$this->start_controls_section(
			'style_badges',
			array(
				'label' => __( 'Status badges', 'certloom' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_responsive_control(
			'badge_padding',
			array(
				'label'      => __( 'Padding', 'certloom' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em' ),
				'default'    => array(
					'top'      => '3',
					'right'    => '12',
					'bottom'   => '3',
					'left'     => '12',
					'unit'     => 'px',
					'isLinked' => false,
				),
				'selectors'  => array(
					'{{WRAPPER}} .cv-badge' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			'badge_border_radius',
			array(
				'label'      => __( 'Corner radius', 'certloom' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 999,
					),
				),
				'default'    => array(
					'unit' => 'px',
					'size' => 999,
				),
				'selectors'  => array(
					'{{WRAPPER}} .cv-badge' => 'border-radius: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'badge_typography',
				'label'    => __( 'Typography', 'certloom' ),
				'selector' => '{{WRAPPER}} .cv-badge',
			)
		);


		$this->add_control(
			'status_color_authority_note',
			array(
				'type' => \Elementor\Controls_Manager::RAW_HTML,
				'raw' => __( 'Credential verdict colors are semantic and controlled by Certloom so a revoked, withdrawn, suspended, or expired credential cannot be restyled to look valid. You can still customize badge spacing, shape and typography.', 'certloom' ),
				'content_classes' => 'elementor-panel-alert elementor-panel-alert-info',
			)
		);

		$this->end_controls_section();
	}

	private function register_guide_controls() {
		$this->start_controls_section(
			'style_guide',
			array(
				'label'     => __( 'Status guide cards', 'certloom' ),
				'tab'       => \Elementor\Controls_Manager::TAB_STYLE,
				'condition' => array(
					'show_guide' => 'yes',
				),
			)
		);

		$this->add_responsive_control(
			'guide_columns',
			array(
				'label'          => __( 'Columns', 'certloom' ),
				'type'           => \Elementor\Controls_Manager::SELECT,
				'options'        => array(
					'1' => '1',
					'2' => '2',
					'3' => '3',
					'4' => '4',
				),
				'default'        => '3',
				'tablet_default' => '2',
				'mobile_default' => '1',
				'selectors'      => array(
					'{{WRAPPER}} .cv-widget' => '--cv-guide-cols: {{VALUE}};',
				),
			)
		);

		$this->add_responsive_control(
			'guide_gap',
			array(
				'label'      => __( 'Gap between cards', 'certloom' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 40,
					),
				),
				'default'    => array(
					'unit' => 'px',
					'size' => 14,
				),
				'selectors'  => array(
					'{{WRAPPER}} .cv-guide-grid' => 'gap: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			'guide_padding',
			array(
				'label'      => __( 'Card padding', 'certloom' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em' ),
				'default'    => array(
					'top'      => '16',
					'right'    => '18',
					'bottom'   => '16',
					'left'     => '18',
					'unit'     => 'px',
					'isLinked' => false,
				),
				'selectors'  => array(
					'{{WRAPPER}} .cv-guide-card' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			'guide_border_radius',
			array(
				'label'      => __( 'Corner radius', 'certloom' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 40,
					),
				),
				'default'    => array(
					'unit' => 'px',
					'size' => 8,
				),
				'selectors'  => array(
					'{{WRAPPER}} .cv-guide-card' => 'border-radius: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'guide_title_typography',
				'label'    => __( 'Title typography', 'certloom' ),
				'selector' => '{{WRAPPER}} .cv-guide-title',
			)
		);

		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'guide_desc_typography',
				'label'    => __( 'Description typography', 'certloom' ),
				'selector' => '{{WRAPPER}} .cv-guide-desc',
			)
		);

		$this->end_controls_section();
	}

	private function register_credit_controls() {
		$this->start_controls_section(
			'style_credit',
			array(
				'label'     => __( 'Credit line', 'certloom' ),
				'tab'       => \Elementor\Controls_Manager::TAB_STYLE,
				'condition' => array(
					'show_credit' => 'yes',
				),
			)
		);

		$this->add_control(
			'credit_color',
			array(
				'label'     => __( 'Text color', 'certloom' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => array(
					'{{WRAPPER}} .cv-widget' => '--cv-credit-color: {{VALUE}};',
				),
			)
		);

		$this->add_responsive_control(
			'credit_size',
			array(
				'label'      => __( 'Font size', 'certloom' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 8,
						'max' => 24,
					),
				),
				'default'    => array(
					'unit' => 'px',
					'size' => 13,
				),
				'selectors'  => array(
					'{{WRAPPER}} .cv-widget' => '--cv-credit-size: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			'credit_align',
			array(
				'label'     => __( 'Alignment', 'certloom' ),
				'type'      => \Elementor\Controls_Manager::CHOOSE,
				'options'   => array(
					'left'   => array(
						'title' => __( 'Left', 'certloom' ),
						'icon'  => 'eicon-text-align-left',
					),
					'center' => array(
						'title' => __( 'Center', 'certloom' ),
						'icon'  => 'eicon-text-align-center',
					),
					'right'  => array(
						'title' => __( 'Right', 'certloom' ),
						'icon'  => 'eicon-text-align-right',
					),
				),
				'default'   => 'center',
				'selectors' => array(
					'{{WRAPPER}} .cv-widget' => '--cv-credit-align: {{VALUE}};',
				),
			)
		);

		$this->end_controls_section();
	}

	private function register_layout_controls() {
		$this->start_controls_section(
			'style_layout',
			array(
				'label' => __( 'Layout', 'certloom' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);


		$this->add_responsive_control(
			'card_padding',
			array(
				'label'      => __( 'Search card padding', 'certloom' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em', '%' ),
				'selectors'  => array(
					'{{WRAPPER}} .cv-search-card' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			'border_radius',
			array(
				'label'      => __( 'Search card corner radius', 'certloom' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 40,
					),
				),
				'selectors'  => array(
					'{{WRAPPER}} .cv-widget' => '--cv-radius: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->end_controls_section();
	}

	private function get_default( $key ) {
		$defaults = Ecapla_CertVerify_Render::default_atts();
		return isset( $defaults[ $key ] ) ? $defaults[ $key ] : '';
	}

	protected function render() {
		$settings = $this->get_settings_for_display();
		$defaults = Ecapla_CertVerify_Render::default_atts();

		$atts = array(
			'eyebrow'       => $this->value_or_default( $settings, 'eyebrow', $defaults['eyebrow'] ),
			'heading'       => $this->value_or_default( $settings, 'heading', $defaults['heading'] ),
			'description'   => $this->value_or_default( $settings, 'description', $defaults['description'] ),
			'placeholder'   => $this->value_or_default( $settings, 'placeholder', $defaults['placeholder'] ),
			'button_text'   => $this->value_or_default( $settings, 'button_text', $defaults['button_text'] ),
			'notfound_text' => $this->value_or_default( $settings, 'notfound_text', $defaults['notfound_text'] ),
			'show_guide'    => isset( $settings['show_guide'] ) && 'yes' === $settings['show_guide'],
			'show_credit'   => isset( $settings['show_credit'] ) && 'yes' === $settings['show_credit'],
			'credit_text'   => $this->value_or_default( $settings, 'credit_text', $defaults['credit_text'] ),
			// Colors below are all driven by this widget's own Style tab
			// selectors (targeting the same CSS custom properties
			// Ecapla_CertVerify_Render would otherwise set inline). Passing empty
			// strings here stops Ecapla_CertVerify_Render from ALSO writing an inline
			// override, which would win over Elementor's generated CSS
			// by specificity and make every color control look broken.
			'accent'        => '',
			'credit_color'  => '',
			'credit_size'   => '',
			'credit_align'  => '',
		);

		echo Ecapla_CertVerify_Render::legacy_widget( $atts ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ecapla_CertVerify_Render escapes every value it prints.
	}

	/**
	 * Reads a saved control value, falling back to the shared default.
	 *
	 * Widgets saved by an older release can be missing controls that were added
	 * later, so every key is checked before it is read.
	 *
	 * @param array  $settings Elementor settings for display.
	 * @param string $key      Control name.
	 * @param mixed  $default  Fallback value.
	 * @return mixed
	 */
	private function value_or_default( $settings, $key, $default ) {
		return isset( $settings[ $key ] ) && '' !== $settings[ $key ] ? $settings[ $key ] : $default;
	}
}
