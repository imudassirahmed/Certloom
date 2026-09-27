<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
Ecapla_CertVerify_Shortcode::enqueue_assets();
$route  = Ecapla_CertVerify_Public_Router::current_route();
$number = Ecapla_CertVerify_Public_Router::requested_number();

$branding_enabled = (bool) Ecapla_CertVerify_Settings::get( 'verification_branding_enabled', false );
$brand_name        = '';
$brand_tagline     = '';
$brand_icon        = '';
$brand_accent      = '#1450e0';
$brand_surface     = '#ffffff';
$brand_text        = '#01133d';
if ( $branding_enabled ) {
	$brand_name = trim( (string) Ecapla_CertVerify_Settings::get( 'verification_brand_name', '' ) );
	if ( '' === $brand_name && class_exists( 'Ecapla_CertVerify_Onboarding' ) ) {
		$onboarding_state = Ecapla_CertVerify_Onboarding::get_state();
		$brand_name       = isset( $onboarding_state['organization_name'] ) ? trim( (string) $onboarding_state['organization_name'] ) : '';
	}
	if ( '' === $brand_name ) {
		$brand_name = get_bloginfo( 'name' );
	}
	$brand_tagline = trim( (string) Ecapla_CertVerify_Settings::get( 'verification_brand_tagline', '' ) );

	$accent  = sanitize_hex_color( (string) Ecapla_CertVerify_Settings::get( 'accent_color', '#1450e0' ) );
	$surface = sanitize_hex_color( (string) Ecapla_CertVerify_Settings::get( 'verification_surface_color', '#ffffff' ) );
	$text    = sanitize_hex_color( (string) Ecapla_CertVerify_Settings::get( 'verification_text_color', '#01133d' ) );
	if ( $accent ) {
		$brand_accent = $accent;
	}
	if ( $surface ) {
		$brand_surface = $surface;
	}
	if ( $text ) {
		$brand_text = $text;
	}
	$brand_text = Ecapla_CertVerify_Settings::accessible_text_color( $brand_surface, $brand_text );

	if ( Ecapla_CertVerify_Settings::get( 'verification_use_site_icon', false ) && function_exists( 'get_site_icon_url' ) ) {
		$brand_icon = (string) get_site_icon_url( 96 );
	}
}

$block_theme_route = function_exists( 'wp_is_block_theme' ) && wp_is_block_theme();
if ( $block_theme_route ) :
	?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<?php wp_head(); ?>
</head>
<body <?php body_class( 'certloom-route-page' ); ?>>
	<?php wp_body_open(); ?>
	<header class="certloom-route-site-header">
		<a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></a>
	</header>
	<?php
else :
	get_header();
endif;
?>
<a class="certloom-skip-link screen-reader-text" href="#certloom-route-content"><?php esc_html_e( 'Skip to credential content', 'certloom' ); ?></a>
<main id="primary" class="certloom-public certloom-route-main certloom-route-<?php echo esc_attr( $route ); ?><?php echo $branding_enabled ? ' is-certloom-branded' : ''; ?>"<?php if ( $branding_enabled ) : ?> style="--certloom-brand-accent:<?php echo esc_attr( $brand_accent ); ?>;--certloom-brand-surface:<?php echo esc_attr( $brand_surface ); ?>;--certloom-brand-text:<?php echo esc_attr( $brand_text ); ?>;"<?php endif; ?>>
	<div id="certloom-route-content" class="certloom-route-shell" tabindex="-1">
		<?php if ( $branding_enabled ) : ?>
			<section class="certloom-route-brand" aria-label="<?php esc_attr_e( 'Credential issuer', 'certloom' ); ?>">
				<?php if ( '' !== $brand_icon ) : ?>
					<img class="certloom-route-brand-icon" src="<?php echo esc_url( $brand_icon ); ?>" alt="" width="48" height="48" decoding="async" />
				<?php endif; ?>
				<div class="certloom-route-brand-copy">
					<strong class="certloom-route-brand-name"><?php echo esc_html( $brand_name ); ?></strong>
					<?php if ( '' !== $brand_tagline ) : ?>
						<span class="certloom-route-brand-tagline"><?php echo esc_html( $brand_tagline ); ?></span>
					<?php endif; ?>
				</div>
			</section>
		<?php endif; ?>
		<?php
		if ( 'series' === $route ) {
			echo Ecapla_CertVerify_Render::normalized_credential_profile( $number, array( 'heading_tag' => 'h1' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Shared renderer escapes output.
		} elseif ( 'credential' === $route ) {
			echo Ecapla_CertVerify_Render::credential_profile( $number, array( 'heading_tag' => 'h1' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Shared renderer escapes output.
		} else {
			echo Ecapla_CertVerify_Render::legacy_widget( array( 'show_guide' => false ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Shared renderer escapes output.
		}
		?>
	</div>
</main>
<?php
if ( $block_theme_route ) {
	wp_footer();
	echo '</body></html>';
} else {
	get_footer();
}
