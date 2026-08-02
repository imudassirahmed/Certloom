<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shortcode and frontend asset registration.
 */
class Ecapla_CertVerify_Shortcode {

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ), 5 );
		add_action( 'elementor/frontend/after_register_styles', array( __CLASS__, 'register_assets' ) );
		add_action( 'elementor/frontend/after_register_scripts', array( __CLASS__, 'register_assets' ) );
		add_filter( 'wp_robots', array( __CLASS__, 'protect_direct_verification_robots' ) );
		add_action( 'send_headers', array( __CLASS__, 'protect_direct_verification_headers' ) );
	}

	public static function register() {
		add_shortcode( 'certverify', array( __CLASS__, 'render' ) );
		add_shortcode( 'ecapla_certverify', array( __CLASS__, 'render' ) ); // Compatibility alias introduced during the Ecapla launch refactor.
	}

	public static function register_assets() {
		wp_register_style( 'ecapla-certverify-public', ECAPLA_CERTVERIFY_URL . 'public/assets/certverify-public.css', array(), ECAPLA_CERTVERIFY_VERSION );
		wp_register_script( 'ecapla-certverify-public', ECAPLA_CERTVERIFY_URL . 'public/assets/certverify-public.js', array(), ECAPLA_CERTVERIFY_VERSION, true );
	}

	public static function enqueue_assets() {
		if ( ! wp_style_is( 'ecapla-certverify-public', 'registered' ) ) {
			self::register_assets();
		}
		wp_enqueue_style( 'ecapla-certverify-public' );
		wp_enqueue_script( 'ecapla-certverify-public' );
	}

	/**
	 * Prevents direct certificate-number URLs from being indexed.
	 *
	 * @param array $robots Existing robots directives.
	 * @return array
	 */
	public static function protect_direct_verification_robots( $robots ) {
		if ( ! self::has_direct_verification_request() ) {
			return $robots;
		}
		$robots['noindex']  = true;
		$robots['nofollow'] = true;
		return $robots;
	}

	/**
	 * Sends private-cache and robots headers for direct verification pages, not
	 * only for the REST response used by the widget.
	 */
	public static function protect_direct_verification_headers() {
		if ( ! self::has_direct_verification_request() || headers_sent() ) {
			return;
		}
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow', true );
		header( 'Referrer-Policy: no-referrer', true );
	}

	/**
	 * Whether a supported direct-number query parameter is present.
	 *
	 * @return bool
	 */
	private static function has_direct_verification_request() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Public read-only direct-link routing.
		foreach ( array( 'cv_number', 'certverify_number', 'ecapla_certverify_number' ) as $key ) {
			if ( isset( $_GET[ $key ] ) ) {
				return true;
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		return false;
	}

	public static function render( $atts ) {
		$defaults = Ecapla_CertVerify_Render::default_atts();
		$atts     = shortcode_atts(
			array(
				'accent'           => '',
				'design'           => 'professional',
				'show_guide'       => 'yes',
				'show_eyebrow'     => 'yes',
				'show_heading'     => 'yes',
				'show_description' => 'yes',
				'show_credit'      => $defaults['show_credit'] ? 'yes' : 'no',
				'content_order'    => 'eyebrow-heading-description',
				'text_align'       => 'left',
				'heading_tag'      => 'h2',
				'eyebrow'          => $defaults['eyebrow'],
				'heading'          => $defaults['heading'],
				'description'      => $defaults['description'],
				'placeholder'      => $defaults['placeholder'],
				'button_text'      => $defaults['button_text'],
				'notfound_text'    => $defaults['notfound_text'],
				'guide_eyebrow'    => $defaults['guide_eyebrow'],
				'guide_heading'    => $defaults['guide_heading'],
				'credit_text'      => $defaults['credit_text'],
			),
			$atts,
			'certverify'
		);

		$render_atts = array(
			'design'           => sanitize_key( $atts['design'] ),
			'show_guide'       => 'no' !== strtolower( (string) $atts['show_guide'] ),
			'show_eyebrow'     => 'no' !== strtolower( (string) $atts['show_eyebrow'] ),
			'show_heading'     => 'no' !== strtolower( (string) $atts['show_heading'] ),
			'show_description' => 'no' !== strtolower( (string) $atts['show_description'] ),
			'show_credit'      => 'yes' === strtolower( (string) $atts['show_credit'] ),
			'content_order'    => sanitize_key( $atts['content_order'] ),
			'text_align'       => sanitize_key( $atts['text_align'] ),
			'heading_tag'      => sanitize_key( $atts['heading_tag'] ),
			'eyebrow'          => $atts['eyebrow'],
			'heading'          => $atts['heading'],
			'description'      => $atts['description'],
			'placeholder'      => $atts['placeholder'],
			'button_text'      => $atts['button_text'],
			'notfound_text'    => $atts['notfound_text'],
			'guide_eyebrow'    => $atts['guide_eyebrow'],
			'guide_heading'    => $atts['guide_heading'],
			'credit_text'      => $atts['credit_text'],
		);

		if ( '' !== $atts['accent'] ) {
			$accent                = sanitize_hex_color( (string) $atts['accent'] );
			$render_atts['accent'] = is_string( $accent ) ? $accent : '';
		}

		$design = sanitize_key( $atts['design'] );
		if ( in_array( $design, array( 'meridian', 'halo', 'axis', 'nova', 'forge' ), true ) ) {
			return Ecapla_CertVerify_Render::template_widget( $design, $render_atts );
		}

		return Ecapla_CertVerify_Render::legacy_widget( $render_atts );
	}
}
