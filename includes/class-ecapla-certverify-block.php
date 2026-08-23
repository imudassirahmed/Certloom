<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Native WordPress block integration for frontend verification without Elementor.
 */
class Ecapla_CertVerify_Block {

	/**
	 * Registers the dynamic block on init.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
	}


	/**
	 * Registers block metadata and its server-side renderer.
	 */
	public static function register() {
		$path = ECAPLA_CERTVERIFY_PATH . 'blocks/certloom';
		if ( ! function_exists( 'register_block_type' ) || ! file_exists( $path . '/block.json' ) ) {
			return;
		}

		// The metadata uses this registered handle for both the frontend and the
		// iframe editor canvas. This keeps API v3 previews faithful without loading
		// the stylesheet across unrelated editor screens.
		Ecapla_CertVerify_Shortcode::register_assets();

		$block = register_block_type(
			$path,
			array(
				'render_callback' => array( __CLASS__, 'render' ),
			)
		);

		if ( ! $block || ! function_exists( 'wp_set_script_translations' ) ) {
			return;
		}

		// editor_script_handles exists from WordPress 6.1; editor_script is the
		// 6.0 equivalent. Without this the block sidebar labels stay untranslated.
		$handles = array();
		if ( isset( $block->editor_script_handles ) && ! empty( $block->editor_script_handles ) ) {
			$handles = (array) $block->editor_script_handles;
		} elseif ( isset( $block->editor_script ) && ! empty( $block->editor_script ) ) {
			$handles = array( $block->editor_script );
		}

		foreach ( $handles as $handle ) {
			wp_set_script_translations( $handle, 'certloom', ECAPLA_CERTVERIFY_PATH . 'languages' );
		}
		$credential_path = ECAPLA_CERTVERIFY_PATH . 'blocks/credential';
		if ( file_exists( $credential_path . '/block.json' ) ) {
			$credential = register_block_type(
				$credential_path,
				array( 'render_callback' => array( __CLASS__, 'render_credential' ) )
			);
			if ( $credential && function_exists( 'wp_set_script_translations' ) ) {
				$credential_handles = ! empty( $credential->editor_script_handles ) ? (array) $credential->editor_script_handles : ( ! empty( $credential->editor_script ) ? array( $credential->editor_script ) : array() );
				foreach ( $credential_handles as $handle ) {
					wp_set_script_translations( $handle, 'certloom', ECAPLA_CERTVERIFY_PATH . 'languages' );
				}
			}
		}
	}

	/**
	 * Renders the same verified frontend component used by the shortcode and
	 * Elementor widgets, keeping all data, accessibility, and REST behavior aligned.
	 *
	 * @param array $attributes Block attributes.
	 * @return string
	 */
	public static function render( $attributes ) {
		$defaults = Ecapla_CertVerify_Render::default_atts();
		$attributes = wp_parse_args(
			is_array( $attributes ) ? $attributes : array(),
			array(
				'design'           => 'professional',
				'showGuide'        => true,
				'showEyebrow'      => true,
				'showHeading'      => true,
				'showDescription'  => true,
				'showCredit'       => (bool) $defaults['show_credit'],
				'headingTag'       => 'h2',
				'textAlign'        => 'left',
				'eyebrow'          => $defaults['eyebrow'],
				'heading'          => $defaults['heading'],
				'description'      => $defaults['description'],
				'placeholder'      => $defaults['placeholder'],
				'buttonText'       => $defaults['button_text'],
				'notFoundText'     => $defaults['notfound_text'],
				'guideEyebrow'     => $defaults['guide_eyebrow'],
				'guideHeading'     => $defaults['guide_heading'],
				'creditText'       => $defaults['credit_text'],
			)
		);

		$render_atts = array(
			'show_guide'       => ! empty( $attributes['showGuide'] ),
			'show_eyebrow'     => ! empty( $attributes['showEyebrow'] ),
			'show_heading'     => ! empty( $attributes['showHeading'] ),
			'show_description' => ! empty( $attributes['showDescription'] ),
			'show_credit'      => ! empty( $attributes['showCredit'] ),
			'heading_tag'      => sanitize_key( $attributes['headingTag'] ),
			'text_align'       => sanitize_key( $attributes['textAlign'] ),
			'eyebrow'          => $attributes['eyebrow'],
			'heading'          => $attributes['heading'],
			'description'      => $attributes['description'],
			'placeholder'      => $attributes['placeholder'],
			'button_text'      => $attributes['buttonText'],
			'notfound_text'    => $attributes['notFoundText'],
			'guide_eyebrow'    => $attributes['guideEyebrow'],
			'guide_heading'    => $attributes['guideHeading'],
			'credit_text'      => $attributes['creditText'],
		);

		$design = sanitize_key( $attributes['design'] );
		if ( in_array( $design, array( 'meridian', 'halo', 'axis', 'nova', 'forge' ), true ) ) {
			$widget_html = Ecapla_CertVerify_Render::template_widget( $design, $render_atts );
		} else {
			$widget_html = Ecapla_CertVerify_Render::legacy_widget( $render_atts );
		}

		$wrapper_attributes = function_exists( 'get_block_wrapper_attributes' )
			? get_block_wrapper_attributes( array( 'class' => 'ecapla-certverify-block' ) )
			: 'class="ecapla-certverify-block"';

		return sprintf(
			'<div %1$s>%2$s</div>',
			$wrapper_attributes,
			$widget_html
		);
	}
	/** Renders the dynamic Credential Profile block. */
	public static function render_credential( $attributes ) {
		$attributes = wp_parse_args( is_array( $attributes ) ? $attributes : array(), array( 'number' => '', 'heading' => __( 'Credential profile', 'certloom' ), 'notFoundText' => __( 'This credential could not be found or is not available.', 'certloom' ) ) );
		$number = trim( sanitize_text_field( (string) $attributes['number'] ) );
		$html = Ecapla_CertVerify_Render::credential_profile( $number, array( 'heading' => $attributes['heading'], 'notfound_text' => $attributes['notFoundText'] ) );
		$wrapper = function_exists( 'get_block_wrapper_attributes' ) ? get_block_wrapper_attributes( array( 'class' => 'certloom-credential-block' ) ) : 'class="certloom-credential-block"';
		return '<div ' . $wrapper . '>' . $html . '</div>';
	}

}
