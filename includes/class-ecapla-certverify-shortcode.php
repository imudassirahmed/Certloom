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
		add_action( 'elementor/preview/enqueue_styles', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'elementor/preview/enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_filter( 'wp_robots', array( __CLASS__, 'protect_direct_verification_robots' ) );
		add_action( 'send_headers', array( __CLASS__, 'protect_direct_verification_headers' ) );
	}

	public static function register() {
		add_shortcode( 'certloom', array( __CLASS__, 'render' ) );
		add_shortcode( 'certloom_credential', array( __CLASS__, 'render_credential' ) );
		add_shortcode( 'certloom_my_credentials', array( __CLASS__, 'render_my_credentials' ) );
		// Compatibility alias retained from the original POR-01 roadmap terminology.
		add_shortcode( 'certloom_my_certificates', array( __CLASS__, 'render_my_credentials' ) );
	}

	public static function register_assets() {
		wp_register_style( 'certloom-tokens', ECAPLA_CERTVERIFY_URL . 'assets/css/certloom-tokens.css', array(), ECAPLA_CERTVERIFY_VERSION );
		wp_register_style( 'ecapla-certloom-public', ECAPLA_CERTVERIFY_URL . 'public/assets/certloom-public.css', array( 'certloom-tokens' ), ECAPLA_CERTVERIFY_VERSION );
		wp_register_style( 'certloom-artifact-foundation', ECAPLA_CERTVERIFY_URL . 'public/assets/certloom-artifact-foundation.css', array( 'ecapla-certloom-public' ), ECAPLA_CERTVERIFY_VERSION );
		wp_register_script( 'ecapla-certloom-public', ECAPLA_CERTVERIFY_URL . 'public/assets/certloom-public.js', array(), ECAPLA_CERTVERIFY_VERSION, true );
	}

	public static function enqueue_assets() {
		if ( ! wp_style_is( 'ecapla-certloom-public', 'registered' ) ) {
			self::register_assets();
		}
		wp_enqueue_style( 'certloom-artifact-foundation' );
		wp_enqueue_script( 'ecapla-certloom-public' );
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
		foreach ( array( 'certloom_number', 'cv_number' ) as $key ) {
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
			'certloom'
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
	/** Renders a known credential profile with the same public presenter used by REST/routes/widgets. */
	public static function render_credential( $atts ) {
		$atts = shortcode_atts(
			array(
				'number' => '',
				'heading' => __( 'Credential profile', 'certloom' ),
				'heading_tag' => 'h2',
				'notfound_text' => __( 'This credential could not be found or is not available.', 'certloom' ),
			),
			is_array( $atts ) ? $atts : array(),
			'certloom_credential'
		);
		$number = trim( sanitize_text_field( (string) $atts['number'] ) );
		$heading_tag = sanitize_key( (string) $atts['heading_tag'] );
		if ( ! in_array( $heading_tag, array( 'h2', 'h3', 'h4', 'h5', 'h6' ), true ) ) { $heading_tag = 'h2'; }
		if ( '' === $number && class_exists( 'Ecapla_CertVerify_Public_Router' ) ) {
			$number = Ecapla_CertVerify_Public_Router::requested_number();
		}
		return Ecapla_CertVerify_Render::credential_profile(
			$number,
			array(
				'heading' => $atts['heading'],
				'heading_tag' => $heading_tag,
				'notfound_text' => $atts['notfound_text'],
			)
		);
	}

	/** Renders the authenticated POR-01 Recipient Portal collection. */
	public static function render_my_credentials( $atts ) {
		return Ecapla_CertVerify_Recipient_Portal::render( is_array( $atts ) ? $atts : array() );
	}

}
