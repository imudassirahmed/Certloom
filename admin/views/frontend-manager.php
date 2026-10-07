<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are local to the rendering method that includes this file.
// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Templates only read display state; all mutations are handled by nonce-protected controllers.

$elementor_active = defined( 'ELEMENTOR_VERSION' );
$elementor_ready  = $elementor_active && version_compare( ELEMENTOR_VERSION, ECAPLA_CERTVERIFY_MINIMUM_ELEMENTOR_VERSION, '>=' );
$studio_enabled  = \Certloom\Infrastructure\Verification\ProVerificationAccess::enabled();
$designs          = array(
	'professional' => __( 'Professional', 'certloom' ),
	'meridian'     => __( 'Meridian', 'certloom' ),
	'halo'         => __( 'Halo', 'certloom' ),
	'axis'         => __( 'Axis', 'certloom' ),
	'nova'         => __( 'Nova', 'certloom' ),
	'forge'        => __( 'Forge', 'certloom' ),
);
?>
<div class="wrap ecapla-certverify-admin-wrap certloom-admin-shell certloom-appearance-workspace">
	<section class="certloom-page-hero" aria-labelledby="certloom-frontend-title"><div>
		<span class="certloom-eyebrow"><?php esc_html_e( 'Public verification', 'certloom' ); ?></span>
		<h1 id="certloom-frontend-title"><?php esc_html_e( 'Verification page', 'certloom' ); ?></h1>
		<p><?php esc_html_e( 'Publish certificate verification with the native WordPress block, a shortcode, or one of six optional Elementor widgets. Certificate lookup works without Elementor.', 'certloom' ); ?></p>
	</div></section>
	<?php ( new \Certloom\Infrastructure\Admin\AdminProductNavigation() )->render_current_subnav(); ?>

	<section class="ecapla-certverify-admin-card certloom-studio-intro-panel" aria-labelledby="certloom-studio-admin-title">
		<div class="certloom-panel-header"><div><span class="certloom-eyebrow"><?php esc_html_e( 'Certloom Pro', 'certloom' ); ?></span><h2 id="certloom-studio-admin-title"><?php esc_html_e( 'Verification Studio', 'certloom' ); ?></h2></div><span class="certloom-studio-edition-status"><?php echo esc_html( $studio_enabled ? __( 'Pro active', 'certloom' ) : __( 'Requires Pro', 'certloom' ) ); ?></span></div>
		<p><?php esc_html_e( 'Use one powerful Elementor widget or native WordPress block. Start with one of five designs chosen for Certloom usage profiles, or select Custom for responsive columns, alignment, spacing, typography and colors.', 'certloom' ); ?></p>
		<div class="certloom-studio-preset-catalog">
			<?php $studio_recommended = \Certloom\Infrastructure\Verification\ProVerificationStudio::recommended(); foreach ( \Certloom\Infrastructure\Verification\ProVerificationStudio::presets() as $key => $preset ) : ?>
				<article class="certloom-studio-preset-summary<?php echo $key === $studio_recommended ? ' is-recommended' : ''; ?>">
					<h3><?php echo esc_html( $preset['label'] ); ?><?php if ( $key === $studio_recommended ) : ?><span><?php esc_html_e( 'Recommended', 'certloom' ); ?></span><?php endif; ?></h3><p><?php echo esc_html( $preset['description'] ); ?></p>
				</article>
			<?php endforeach; ?>
		</div>
		<p class="description"><?php if ( $studio_enabled ) : ?><?php esc_html_e( 'Find “Certloom Pro — Verification Studio” in your editor. The Design dropdown has five presets and a sixth Custom option. Custom settings can differ for desktop, tablet and mobile. Existing standard widgets remain available.', 'certloom' ); ?><?php else : ?><?php esc_html_e( 'Activate Certloom Pro to use Verification Studio in Elementor or the WordPress block editor. Standard verification remains available.', 'certloom' ); ?><?php endif; ?></p>
		<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=certloom-settings#certloom-settings-certificate-layout' ) ); ?>"><?php esc_html_e( 'Set the global certificate layout', 'certloom' ); ?></a></p>
	</section>

	<div class="ecapla-certverify-admin-grid ecapla-certloom-frontend-grid">
		<section class="ecapla-certverify-admin-card">
			<span class="dashicons dashicons-block-default ecapla-certverify-feature-icon" aria-hidden="true"></span>
			<h2><?php esc_html_e( 'Native WordPress block', 'certloom' ); ?></h2>
			<p><?php esc_html_e( 'Edit any page or post, open the block inserter, and search for “Certloom”. Choose a design and edit its content from the block sidebar. No page builder is required.', 'certloom' ); ?></p>
			<p><strong><?php esc_html_e( 'Block name:', 'certloom' ); ?></strong> <?php esc_html_e( 'Certloom – Certificate Verification', 'certloom' ); ?></p>
		</section>

		<section class="ecapla-certverify-admin-card">
			<span class="dashicons dashicons-shortcode ecapla-certverify-feature-icon" aria-hidden="true"></span>
			<h2><?php esc_html_e( 'Shortcode', 'certloom' ); ?></h2>
			<p><?php esc_html_e( 'Use the shortcode in the Classic Editor, a Shortcode block, a theme template, or another page builder.', 'certloom' ); ?></p>
			<div class="ecapla-certverify-code-copy"><code>[certloom]</code></div>
			<p class="description"><?php esc_html_e( 'Choose another design with the design attribute, for example:', 'certloom' ); ?></p>
			<div class="ecapla-certverify-code-copy"><code>[certloom design=&quot;halo&quot;]</code></div>
		</section>

		<section class="ecapla-certverify-admin-card">
			<span class="dashicons dashicons-admin-generic ecapla-certverify-feature-icon" aria-hidden="true"></span>
			<h2><?php esc_html_e( 'Elementor widgets', 'certloom' ); ?></h2>
			<?php if ( $elementor_ready ) : ?>
				<p class="ecapla-certverify-integration-status is-ready"><?php printf( /* translators: %s: active Elementor version number. */ esc_html__( 'Elementor %s is active. Six Certloom widgets are available in the Elementor panel.', 'certloom' ), esc_html( ELEMENTOR_VERSION ) ); ?></p>
			<?php elseif ( $elementor_active ) : ?>
				<p class="ecapla-certverify-integration-status is-warning"><?php printf( /* translators: 1: active Elementor version, 2: minimum required Elementor version. */ esc_html__( 'Elementor %1$s is active, but Certloom widgets require version %2$s or later.', 'certloom' ), esc_html( ELEMENTOR_VERSION ), esc_html( ECAPLA_CERTVERIFY_MINIMUM_ELEMENTOR_VERSION ) ); ?></p>
			<?php else : ?>
				<p><?php esc_html_e( 'Elementor is not active. The native block and shortcode remain fully available.', 'certloom' ); ?></p>
			<?php endif; ?>
			<ul class="ecapla-certverify-design-list">
				<?php foreach ( $designs as $label ) : ?><li><?php echo esc_html( $label ); ?></li><?php endforeach; ?>
			</ul>
		</section>
	</div>

	<section class="ecapla-certverify-admin-card ecapla-certverify-embed-reference">
		<h2><?php esc_html_e( 'Credential profile & clean links', 'certloom' ); ?></h2>
		<p><?php esc_html_e( 'Credential profiles use the same public field and status rules as verification, so visitors see consistent certificate information.', 'certloom' ); ?></p>
		<p><strong><?php esc_html_e( 'Profile shortcode:', 'certloom' ); ?></strong> <code>[certloom_credential number=&quot;CERT-2026-0001&quot;]</code></p>
		<p><strong><?php esc_html_e( 'Native block:', 'certloom' ); ?></strong> <?php esc_html_e( 'Certloom – Credential Profile', 'certloom' ); ?></p>
		<p><strong><?php esc_html_e( 'Elementor:', 'certloom' ); ?></strong> <?php esc_html_e( 'Certloom — Credential Profile', 'certloom' ); ?></p>
		<p class="description"><?php esc_html_e( 'Clean links use /certloom/verify/{certificate}/ and /certloom/credential/{certificate}/ when pretty permalinks are enabled. Complex certificate numbers stay safely encoded without changing the exact number used for verification.', 'certloom' ); ?></p>
	</section>

	<section class="ecapla-certverify-admin-card ecapla-certverify-embed-reference certloom-design-reference">
		<div class="certloom-panel-header">
			<div><span class="certloom-eyebrow"><?php esc_html_e( 'Verification appearance', 'certloom' ); ?></span><h2><?php esc_html_e( 'Choose a design visually', 'certloom' ); ?></h2></div>
		</div>
		<p><?php esc_html_e( 'Each design below is a visual preview of the public verification experience. Copy its shortcode when you find the presentation that fits your site.', 'certloom' ); ?></p>
		<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=certloom-settings#certloom-settings-certificate-layout' ) ); ?>"><?php esc_html_e( 'Set the public certificate position and centered width', 'certloom' ); ?></a> — <?php esc_html_e( 'One global setting applies to every certificate. Designed certificates replace the search view after verification; details and uploaded images appear below it.', 'certloom' ); ?></p>
		<div class="certloom-embed-design-grid">
			<?php foreach ( $designs as $slug => $label ) : ?>
				<article class="certloom-embed-design-card certloom-embed-design-<?php echo esc_attr( $slug ); ?>">
					<div class="certloom-embed-design-preview" aria-hidden="true">
						<span class="certloom-embed-preview-kicker"><?php echo esc_html( $label ); ?></span>
						<span class="certloom-embed-preview-title"><?php esc_html_e( 'Verify a credential', 'certloom' ); ?></span>
						<span class="certloom-embed-preview-copy"><?php esc_html_e( 'Enter a credential number to check its status.', 'certloom' ); ?></span>
						<span class="certloom-embed-preview-field"></span>
						<span class="certloom-embed-preview-button"></span>
					</div>
					<div class="certloom-embed-design-card-body">
						<strong><?php echo esc_html( $label ); ?></strong>
						<div class="ecapla-certverify-code-copy"><code>[certloom design=&quot;<?php echo esc_attr( $slug ); ?>&quot;]</code></div>
					</div>
				</article>
			<?php endforeach; ?>
		</div>
		<p class="description"><?php esc_html_e( 'The native block, shortcode and Elementor widgets use the same secure credential lookup, field visibility rules and status logic. The preview represents the design language; your site typography and content can affect final spacing.', 'certloom' ); ?></p>
	</section>
</div>
