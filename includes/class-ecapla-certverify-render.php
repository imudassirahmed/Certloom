<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared frontend renderer for the legacy widget, shortcode, and new templates.
 */
class Ecapla_CertVerify_Render {

	/** @var array<string,array> Per-request direct lookup cache shared by all widgets. */
	private static $direct_result_cache = array();

	/**
	 * Legacy defaults retained so existing Elementor pages render exactly as before.
	 *
	 * @return array
	 */
	public static function default_atts() {
		return self::legacy_default_atts();
	}

	/**
	 * Defaults for the original Certloom widget and shortcode.
	 *
	 * @return array
	 */
	public static function legacy_default_atts() {
		return array(
			'eyebrow'          => __( 'Certificate search', 'certloom' ),
			'heading'          => __( 'Verify a Certificate', 'certloom' ),
			'description'      => __( 'Enter the certificate number exactly as it appears on the face of the certificate. No login required.', 'certloom' ),
			'placeholder'      => __( 'e.g. CERT-9001-2026-00142', 'certloom' ),
			'button_text'      => _x( 'Search', 'search action button', 'certloom' ),
			'notfound_text'    => __( 'We couldn\'t find a certificate with that number. Double check it matches exactly what\'s printed on the certificate.', 'certloom' ),
			'show_eyebrow'     => true,
			'show_heading'     => true,
			'show_description' => true,
			'content_order'    => 'eyebrow-heading-description',
			'heading_tag'      => 'h2',
			'text_align'       => 'left',
			'show_guide'       => true,
			'guide_eyebrow'    => __( 'Certificate status guide', 'certloom' ),
			'guide_heading'    => '',
			'show_credit'      => (bool) Ecapla_CertVerify_Settings::get( 'show_credit', false ),
			'credit_text'      => Ecapla_CertVerify_Settings::get( 'credit_text', __( 'Verification powered by Certloom.', 'certloom' ) ),
			'accent'           => Ecapla_CertVerify_Settings::get( 'accent_color', '#0f6e56' ),
			'credit_color'     => Ecapla_CertVerify_Settings::get( 'credit_color', '' ),
			'credit_size'      => Ecapla_CertVerify_Settings::get( 'credit_size', '' ),
			'credit_align'     => Ecapla_CertVerify_Settings::get( 'credit_align', 'center' ),
		);
	}

	/**
	 * Defaults shared by the five standalone template widgets.
	 *
	 * @return array
	 */
	public static function template_default_atts() {
		return array(
			'eyebrow'          => __( 'Certificate verification', 'certloom' ),
			'heading'          => __( 'Verify a certificate with confidence', 'certloom' ),
			'description'      => __( 'Enter the certificate number exactly as printed to view its current status and published certification details.', 'certloom' ),
			'placeholder'      => __( 'Enter certificate number', 'certloom' ),
			'button_text'      => __( 'Verify certificate', 'certloom' ),
			'notfound_text'    => __( 'No certificate matched that number. Check the characters, spacing, and hyphens, then try again.', 'certloom' ),
			'show_eyebrow'     => true,
			'show_heading'     => true,
			'show_description' => true,
			'content_order'    => 'eyebrow-heading-description',
			'heading_tag'      => 'h2',
			'text_align'       => 'left',
			'show_guide'       => true,
			'guide_eyebrow'    => __( 'Status guide', 'certloom' ),
			'guide_heading'    => __( 'Understand each certificate status', 'certloom' ),
			'show_credit'      => (bool) Ecapla_CertVerify_Settings::get( 'show_credit', false ),
			'credit_text'      => Ecapla_CertVerify_Settings::get( 'credit_text', __( 'Verification powered by Certloom.', 'certloom' ) ),
			'accent'           => Ecapla_CertVerify_Settings::get( 'accent_color', '#0f6e56' ),
			'credit_color'     => Ecapla_CertVerify_Settings::get( 'credit_color', '' ),
			'credit_size'      => Ecapla_CertVerify_Settings::get( 'credit_size', '' ),
			'credit_align'     => Ecapla_CertVerify_Settings::get( 'credit_align', 'center' ),
		);
	}

	/**
	 * Backward-compatible public renderer used by [certloom].
	 *
	 * @param array $atts Rendering attributes.
	 * @return string
	 */
	public static function widget( $atts = array() ) {
		return self::legacy_widget( $atts );
	}

	/**
	 * Renders the original Certloom markup and CSS hooks.
	 *
	 * @param array $atts Rendering attributes.
	 * @return string
	 */
	public static function legacy_widget( $atts = array() ) {
		$atts = self::sanitize_common_atts( wp_parse_args( $atts, self::legacy_default_atts() ), false );
		Ecapla_CertVerify_Shortcode::enqueue_assets();

		$instance_id = wp_unique_id( 'certverify-' );
		$endpoint    = esc_url_raw( rest_url( 'certloom/v1/verify' ) );
		$direct      = self::direct_number();
		$result_id   = $instance_id . '-result';
		$live_id     = $instance_id . '-live';
		$server_html = self::server_result_html( $direct, 'cv', $atts['notfound_text'], __( 'Something went wrong, please try again.', 'certloom' ) );

		ob_start();
		?>
		<div
			id="<?php echo esc_attr( $instance_id ); ?>"
			class="<?php echo esc_attr( 'ecapla-certverify-widget cv-widget cv-align-' . $atts['text_align'] ); ?>"
			style="<?php echo esc_attr( self::legacy_style_vars( $atts ) ); ?>"
			data-endpoint="<?php echo esc_attr( $endpoint ); ?>"
			<?php echo $direct ? 'data-direct-number="' . esc_attr( $direct ) . '" data-server-rendered="1"' : ''; ?>
			data-notfound-text="<?php echo esc_attr( $atts['notfound_text'] ); ?>"
			data-found-text="<?php echo esc_attr__( 'Certificate found.', 'certloom' ); ?>"
			data-error-text="<?php echo esc_attr__( 'Something went wrong, please try again.', 'certloom' ); ?>"
			data-loading-text="<?php echo esc_attr__( 'Checking…', 'certloom' ); ?>"
			data-required-text="<?php echo esc_attr__( 'Enter a certificate number before searching.', 'certloom' ); ?>"
			data-copy-success-text="<?php echo esc_attr__( 'Verification link copied.', 'certloom' ); ?>"
			data-copy-error-text="<?php echo esc_attr__( 'Copy the verification link from your browser.', 'certloom' ); ?>"
		>
			<div class="cv-search-card">
				<?php echo self::header_content( $atts, 'cv' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

				<form class="cv-search-form" method="post" action="">
					<?php echo self::routing_hidden_inputs(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Values are escaped by the helper. ?>
					<label class="screen-reader-text" for="<?php echo esc_attr( $instance_id . '-number' ); ?>"><?php esc_html_e( 'Certificate number', 'certloom' ); ?></label>
					<input type="text" id="<?php echo esc_attr( $instance_id . '-number' ); ?>" name="certloom_number" class="cv-search-input" value="<?php echo esc_attr( $direct ); ?>" placeholder="<?php echo esc_attr( $atts['placeholder'] ); ?>" autocomplete="off" autocapitalize="off" spellcheck="false" dir="auto" maxlength="100" aria-controls="<?php echo esc_attr( $result_id ); ?>" required />
					<label class="screen-reader-text" for="<?php echo esc_attr( $instance_id . '-code' ); ?>"><?php esc_html_e( 'Secondary verification code, if printed', 'certloom' ); ?></label>
					<input type="text" id="<?php echo esc_attr( $instance_id . '-code' ); ?>" name="certloom_code" class="cv-search-input certloom-secondary-code-input" value="" placeholder="<?php echo esc_attr__( 'Verification code (optional)', 'certloom' ); ?>" autocomplete="one-time-code" autocapitalize="characters" spellcheck="false" maxlength="19" aria-controls="<?php echo esc_attr( $result_id ); ?>" />
					<button type="submit" class="cv-search-btn">
						<span class="cv-btn-text"><?php echo esc_html( $atts['button_text'] ); ?></span>
						<span class="cv-arrow" aria-hidden="true">&rarr;</span>
					</button>
				</form>

				<div id="<?php echo esc_attr( $result_id ); ?>" class="cv-result" role="region" aria-label="<?php esc_attr_e( 'Verification result', 'certloom' ); ?>"><?php echo $server_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped values. ?></div>
				<div id="<?php echo esc_attr( $live_id ); ?>" class="screen-reader-text certloom-live-status" role="status" aria-live="polite" aria-atomic="true"></div>
				<noscript><p class="cv-noscript"><?php esc_html_e( 'JavaScript is unavailable. Submit the form to reload this page with the verification result.', 'certloom' ); ?></p></noscript>
			</div>

			<?php if ( $atts['show_guide'] ) : ?>
				<div class="cv-guide">
					<div class="cv-guide-header">
						<?php if ( '' !== $atts['guide_eyebrow'] ) : ?>
							<p class="cv-eyebrow"><?php echo esc_html( $atts['guide_eyebrow'] ); ?></p>
						<?php endif; ?>
						<?php if ( '' !== $atts['guide_heading'] ) : ?>
							<h3 class="cv-guide-heading"><?php echo esc_html( $atts['guide_heading'] ); ?></h3>
						<?php endif; ?>
					</div>
					<div class="cv-guide-grid">
						<?php foreach ( Ecapla_CertVerify_Fields::get_statuses() as $key => $status ) : ?>
							<div class="cv-guide-card cv-guide-<?php echo esc_attr( $key ); ?>">
								<p class="cv-guide-title"><?php echo wp_kses( self::status_icon_entity( $key ), array() ) . ' ' . esc_html( $status['label'] ); ?></p>
								<p class="cv-guide-desc"><?php echo esc_html( $status['desc'] ); ?></p>
							</div>
						<?php endforeach; ?>
					</div>
				</div>
			<?php endif; ?>

			<?php if ( $atts['show_credit'] && '' !== $atts['credit_text'] ) : ?>
				<p class="cv-credit"><?php echo esc_html( $atts['credit_text'] ); ?></p>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Renders one of the five isolated templates.
	 *
	 * @param string $template Template slug.
	 * @param array  $atts     Rendering attributes.
	 * @return string
	 */
	public static function template_widget( $template, $atts = array() ) {
		$templates = array( 'meridian', 'halo', 'axis', 'nova', 'forge' );
		$template  = in_array( $template, $templates, true ) ? $template : 'meridian';
		$atts      = self::sanitize_common_atts( wp_parse_args( $atts, self::template_default_atts() ), true );
		Ecapla_CertVerify_Shortcode::enqueue_assets();

		$instance_id = wp_unique_id( 'certverify-' . $template . '-' );
		$endpoint    = esc_url_raw( rest_url( 'certloom/v1/verify' ) );
		$direct      = self::direct_number();
		$result_id   = $instance_id . '-result';
		$live_id     = $instance_id . '-live';
		$server_html = self::server_result_html( $direct, 'cvx', $atts['notfound_text'], __( 'The verification service could not complete the request. Please try again.', 'certloom' ) );
		$classes     = array(
			'ecapla-certverify-template',
			'cvx-widget',
			'cvx-template-' . $template,
			'cvx-align-' . $atts['text_align'],
		);

		ob_start();
		?>
		<div
			id="<?php echo esc_attr( $instance_id ); ?>"
			class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>"
			style="<?php echo esc_attr( self::template_style_vars( $atts ) ); ?>"
			data-endpoint="<?php echo esc_attr( $endpoint ); ?>"
			<?php echo $direct ? 'data-direct-number="' . esc_attr( $direct ) . '" data-server-rendered="1"' : ''; ?>
			data-notfound-text="<?php echo esc_attr( $atts['notfound_text'] ); ?>"
			data-found-text="<?php echo esc_attr__( 'Certificate found.', 'certloom' ); ?>"
			data-error-text="<?php echo esc_attr__( 'The verification service could not complete the request. Please try again.', 'certloom' ); ?>"
			data-loading-text="<?php echo esc_attr__( 'Checking certificate…', 'certloom' ); ?>"
			data-required-text="<?php echo esc_attr__( 'Enter a certificate number before searching.', 'certloom' ); ?>"
			data-copy-success-text="<?php echo esc_attr__( 'Verification link copied.', 'certloom' ); ?>"
			data-copy-error-text="<?php echo esc_attr__( 'Copy the verification link from your browser.', 'certloom' ); ?>"
		>
			<section class="cvx-shell" aria-label="<?php esc_attr_e( 'Certificate verification search', 'certloom' ); ?>">
				<div class="cvx-copy">
					<?php echo self::header_content( $atts, 'cvx' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>
				<div class="cvx-action">
					<form class="cvx-search-form" method="post" action="">
						<?php echo self::routing_hidden_inputs(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Values are escaped by the helper. ?>
						<label class="screen-reader-text" for="<?php echo esc_attr( $instance_id . '-number' ); ?>"><?php esc_html_e( 'Certificate number', 'certloom' ); ?></label>
						<div class="cvx-input-wrap">
							<span class="cvx-input-icon" aria-hidden="true"></span>
							<input type="text" id="<?php echo esc_attr( $instance_id . '-number' ); ?>" name="certloom_number" class="cvx-search-input" value="<?php echo esc_attr( $direct ); ?>" placeholder="<?php echo esc_attr( $atts['placeholder'] ); ?>" autocomplete="off" autocapitalize="off" spellcheck="false" dir="auto" maxlength="100" aria-controls="<?php echo esc_attr( $result_id ); ?>" required />
						</div>
						<div class="cvx-input-wrap certloom-secondary-code-wrap">
							<span class="cvx-input-icon certloom-code-icon" aria-hidden="true"></span>
							<label class="screen-reader-text" for="<?php echo esc_attr( $instance_id . '-code' ); ?>"><?php esc_html_e( 'Secondary verification code, if printed', 'certloom' ); ?></label>
							<input type="text" id="<?php echo esc_attr( $instance_id . '-code' ); ?>" name="certloom_code" class="cvx-search-input certloom-secondary-code-input" value="" placeholder="<?php echo esc_attr__( 'Verification code (optional)', 'certloom' ); ?>" autocomplete="one-time-code" autocapitalize="characters" spellcheck="false" maxlength="19" aria-controls="<?php echo esc_attr( $result_id ); ?>" />
						</div>
						<button type="submit" class="cvx-search-btn">
							<span class="cvx-btn-text"><?php echo esc_html( $atts['button_text'] ); ?></span>
							<span class="cvx-arrow" aria-hidden="true">&rarr;</span>
						</button>
					</form>
					<div id="<?php echo esc_attr( $result_id ); ?>" class="cvx-result" role="region" aria-label="<?php esc_attr_e( 'Verification result', 'certloom' ); ?>"><?php echo $server_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped values. ?></div>
					<div id="<?php echo esc_attr( $live_id ); ?>" class="screen-reader-text certloom-live-status" role="status" aria-live="polite" aria-atomic="true"></div>
					<noscript><p class="cvx-noscript"><?php esc_html_e( 'JavaScript is unavailable. Submit the form to reload this page with the verification result.', 'certloom' ); ?></p></noscript>
				</div>
			</section>

			<?php if ( $atts['show_guide'] ) : ?>
				<section class="cvx-guide" aria-label="<?php esc_attr_e( 'Certificate status guide', 'certloom' ); ?>">
					<div class="cvx-guide-header">
						<?php if ( '' !== $atts['guide_eyebrow'] ) : ?>
							<p class="cvx-guide-eyebrow"><?php echo esc_html( $atts['guide_eyebrow'] ); ?></p>
						<?php endif; ?>
						<?php if ( '' !== $atts['guide_heading'] ) : ?>
							<h3 class="cvx-guide-heading"><?php echo esc_html( $atts['guide_heading'] ); ?></h3>
						<?php endif; ?>
					</div>
					<div class="cvx-guide-grid">
						<?php foreach ( Ecapla_CertVerify_Fields::get_statuses() as $key => $status ) : ?>
							<article class="cvx-guide-card cvx-guide-<?php echo esc_attr( $key ); ?>">
								<span class="cvx-guide-mark" aria-hidden="true"><?php echo esc_html( self::status_icon( $key ) ); ?></span>
								<div>
									<p class="cvx-guide-title"><?php echo esc_html( $status['label'] ); ?></p>
									<p class="cvx-guide-desc"><?php echo esc_html( $status['desc'] ); ?></p>
								</div>
							</article>
						<?php endforeach; ?>
					</div>
				</section>
			<?php endif; ?>

			<?php if ( $atts['show_credit'] && '' !== $atts['credit_text'] ) : ?>
				<p class="cvx-credit"><?php echo esc_html( $atts['credit_text'] ); ?></p>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Server-renders direct-link results so verification remains functional without JavaScript.
	 *
	 * @param string $number    Certificate number.
	 * @param string $prefix    CSS class prefix.
	 * @param string $not_found Not-found message.
	 * @param string $error     Temporary error message.
	 * @return string
	 */
	private static function server_result_html( $number, $prefix, $not_found, $error ) {
		if ( '' === $number ) {
			return '';
		}
		$presentation = Ecapla_CertVerify_Public_Presentation::verify( $number, true, 'verification', self::direct_code(), self::analytics_source() );
		if ( is_wp_error( $presentation ) ) {
			$message = 'ecapla_certverify_rate_limited' === $presentation->get_error_code() ? $presentation->get_error_message() : $error;
			return self::message_html( $prefix, $message );
		}
		if ( empty( $presentation['found'] ) ) {
			return self::message_html( $prefix, $not_found );
		}
		return self::result_card_html( $presentation, $prefix );
	}

	/**
	 * Shapes a certificate record into public result rows.
	 *
	 * @param array $certificate Certificate record.
	 * @return array
	 */
	public static function fields_for_output( $certificate ) {
		return is_array( $certificate ) ? Ecapla_CertVerify_Public_Presentation::fields_for_output( $certificate ) : array();
	}

	/**
	 * Renders a normalized Credential Series profile through the same guest-safe
	 * verification service used by the REST adapter. This route remains hard-gated
	 * until normalized public cutover has genuinely earned authority.
	 *
	 * @param string $public_id Checksummed public Credential Series ID.
	 * @param array  $atts      Optional presentation attributes.
	 * @return string
	 */
	public static function normalized_credential_profile( $public_id, $atts = array() ) {
		Ecapla_CertVerify_Shortcode::enqueue_assets();
		$atts = wp_parse_args(
			is_array( $atts ) ? $atts : array(),
			array(
				'heading'       => __( 'Credential profile', 'certloom' ),
				'notfound_text' => __( 'This credential could not be found or is not available.', 'certloom' ),
			)
		);
		$heading = self::limit_text( sanitize_text_field( (string) $atts['heading'] ), 200 );
		$heading_tag = isset( $atts['heading_tag'] ) ? sanitize_key( (string) $atts['heading_tag'] ) : 'h2';
		if ( ! in_array( $heading_tag, array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ), true ) ) { $heading_tag = 'h2'; }
		$notfound = self::limit_text( sanitize_textarea_field( (string) $atts['notfound_text'] ), 500 );
		$instance_id = wp_unique_id( 'certloom-series-credential-' );
		$heading_id = $instance_id . '-heading';
		$content = '';

		$gate = new \Certloom\Infrastructure\Verification\PublicVerificationGate();
		if ( ! $gate->is_ready() ) {
			$content = self::message_html( 'cvp', __( 'Credential verification is temporarily unavailable while Certloom completes its protected data transition.', 'certloom' ) );
		} elseif ( class_exists( 'Ecapla_CertVerify_REST' ) && Ecapla_CertVerify_REST::is_rate_limited( 'credential_verify', true ) ) {
			$content = self::message_html( 'cvp', __( 'Too many verification requests. Please wait a minute and try again.', 'certloom' ) );
		} else {
			$repo = new \Certloom\Infrastructure\Repository\CredentialRepository();
			$result = ( new \Certloom\Application\CredentialVerificationService( $repo ) )->verify_public_id( $public_id );
			if ( is_wp_error( $result ) ) {
				$content = self::message_html( 'cvp', __( 'Credential verification is temporarily unavailable. Please try again later.', 'certloom' ) );
			} else {
				$payload = ( new \Certloom\Application\PublicCredentialPresenter() )->present( $result, 'profile' );
				if ( empty( $payload['found'] ) ) {
					$content = self::message_html( 'cvp', $notfound );
				} else {
					$status = isset( $payload['status'] ) && is_array( $payload['status'] ) ? $payload['status'] : array();
					$code = sanitize_key( (string) ( $status['code'] ?? 'unknown' ) );
					$fields = array();
					foreach ( array(
						__( 'Certificate number', 'certloom' ) => (string) ( $payload['certificate_number'] ?? '' ),
						__( 'Issued', 'certloom' ) => (string) ( $payload['issued_at'] ?? '' ),
						__( 'Expires', 'certloom' ) => (string) ( $payload['expires_at'] ?? '' ),
					) as $label => $value ) {
						if ( '' !== $value ) { $fields[] = array( 'label' => $label, 'value' => $value, 'is_status' => false ); }
					}
					$fields = array_merge( $fields, (array) ( $payload['fields'] ?? array() ) );
					$url = ( new \Certloom\Infrastructure\Issuance\NormalizedPublicCredentialUrl() )->verification_url( $result->public_id() );
					$credential = $result->credential();
					$snapshot = is_array( $credential ) && is_array( $credential['snapshot'] ?? null ) ? $credential['snapshot'] : array();
					$issuer = is_array( $snapshot['issuer'] ?? null ) ? (string) ( $snapshot['issuer']['name'] ?? '' ) : '';
					$presentation = array(
						'found' => true,
						'status' => array(
							'key' => $code,
							'label' => self::normalized_status_label( $code ),
							'description' => self::normalized_status_description( $code ),
						),
						'credential_id' => (string) ( $payload['credential_id'] ?? '' ),
						'certificate_number' => (string) ( $payload['certificate_number'] ?? '' ),
						'profile' => isset( $payload['profile'] ) && is_array( $payload['profile'] ) ? $payload['profile'] : array(),
						'fields' => $fields,
						'issuer' => $issuer,
						'snapshot' => $snapshot,
						'verified_at' => current_time( 'mysql' ),
						'verification_url' => is_wp_error( $url ) ? '' : (string) $url,
						'actions' => is_wp_error( $url ) ? array( array( 'type' => 'print', 'label' => __( 'Print', 'certloom' ) ) ) : array(
							array( 'type' => 'copy', 'label' => __( 'Copy link', 'certloom' ), 'url' => (string) $url ),
							array( 'type' => 'print', 'label' => __( 'Print', 'certloom' ) ),
						),
					);
					$content = self::normalized_profile_card_html( $presentation, 'cvp' );
				}
			}
		}

		return '<section id="' . esc_attr( $instance_id ) . '" class="certloom-credential-profile cvp-widget cvp-widget--normalized" aria-labelledby="' . esc_attr( $heading_id ) . '" data-copy-success-text="' . esc_attr__( 'Verification link copied.', 'certloom' ) . '" data-copy-error-text="' . esc_attr__( 'Copy the verification link from your browser.', 'certloom' ) . '"><header class="cvp-header"><p class="cvp-eyebrow">' . esc_html__( 'Verified credential', 'certloom' ) . '</p><' . $heading_tag . ' id="' . esc_attr( $heading_id ) . '" class="cvp-heading">' . esc_html( $heading ) . '</' . $heading_tag . '></header><div class="cvp-result" role="region" aria-labelledby="' . esc_attr( $heading_id ) . '">' . $content . '</div><div class="screen-reader-text certloom-live-status" role="status" aria-live="polite" aria-atomic="true"></div></section>';
	}

	/**
	 * Admin-only setup sample. It deliberately uses obvious sample data and never
	 * queries or creates a credential record.
	 *
	 * @return string
	 */
	public static function setup_sample_credential_profile() {
		Ecapla_CertVerify_Shortcode::enqueue_assets();
		$site_name = trim( (string) get_bloginfo( 'name' ) );
		if ( '' === $site_name ) { $site_name = __( 'Example Organization', 'certloom' ); }
		$presentation = array(
			'found' => true,
			'status' => array( 'key' => 'valid', 'label' => __( 'Valid credential', 'certloom' ), 'description' => __( 'Sample status: this is how a valid issued credential is presented to a verifier.', 'certloom' ) ),
			'credential_id' => 'SAMPLE-CREDENTIAL-ID',
			'certificate_number' => 'CERT-EXAMPLE-0001',
			'profile' => array(
				'recipient_name' => __( 'Amina Khan', 'certloom' ),
				'program_title' => __( 'Example Professional Certificate', 'certloom' ),
				'program_description' => __( 'Sample public presentation used only during setup.', 'certloom' ),
				'issuer_name' => $site_name,
			),
			'fields' => array(
				array( 'label' => __( 'Issued', 'certloom' ), 'value' => wp_date( 'Y-m-d' ), 'is_status' => false ),
			),
			'issuer' => $site_name,
			'snapshot' => array(),
			'verified_at' => current_time( 'mysql' ),
			'verification_url' => home_url( '/' ),
			'actions' => array(),
		);
		$content = self::normalized_profile_card_html( $presentation, 'cvp' );
		return '<section class="certloom-credential-profile cvp-widget cvp-widget--normalized certloom-credential-profile--sample" aria-label="' . esc_attr__( 'Sample credential verification result', 'certloom' ) . '"><div class="cvp-result">' . $content . '</div><p class="certloom-sample-watermark">' . esc_html__( 'Sample preview — not a real credential', 'certloom' ) . '</p></section>';
	}

	/** Premium human-facing normalized credential profile. @return string */
	private static function normalized_profile_card_html( array $presentation, $prefix ) {
		$status = isset( $presentation['status'] ) && is_array( $presentation['status'] ) ? $presentation['status'] : array();
		$status_key = sanitize_key( (string) ( $status['key'] ?? 'unknown' ) );
		$status_label = (string) ( $status['label'] ?? __( 'Credential status', 'certloom' ) );
		$status_desc = (string) ( $status['description'] ?? '' );
		$profile = isset( $presentation['profile'] ) && is_array( $presentation['profile'] ) ? $presentation['profile'] : array();
		$recipient = trim( (string) ( $profile['recipient_name'] ?? '' ) );
		$program = trim( (string) ( $profile['program_title'] ?? '' ) );
		$program_desc = trim( (string) ( $profile['program_description'] ?? '' ) );
		$batch = trim( (string) ( $profile['batch_name'] ?? '' ) );
		$issuer = trim( (string) ( $profile['issuer_name'] ?? ( $presentation['issuer'] ?? '' ) ) );
		$number = trim( (string) ( $presentation['certificate_number'] ?? '' ) );
		$credential_id = trim( (string) ( $presentation['credential_id'] ?? '' ) );

		$hero = '<div class="cvp-profile-hero"><div class="cvp-profile-status-row"><span class="cvp-status-mark" aria-hidden="true">' . esc_html( self::status_icon( $status_key ) ) . '</span><div><span class="cvp-profile-status-label">' . esc_html( $status_label ) . '</span>' . ( '' !== $status_desc ? '<p>' . esc_html( $status_desc ) . '</p>' : '' ) . '</div></div>';
		if ( '' !== $recipient ) { $hero .= '<h2 class="cvp-profile-recipient" dir="auto">' . esc_html( $recipient ) . '</h2>'; }
		if ( '' !== $program ) { $hero .= '<p class="cvp-profile-program" dir="auto">' . esc_html( $program ) . '</p>'; }
		if ( '' !== $program_desc ) { $hero .= '<p class="cvp-profile-program-description" dir="auto">' . esc_html( $program_desc ) . '</p>'; }
		$hero .= '</div>';

		$facts = array();
		if ( '' !== $number ) { $facts[] = array( __( 'Credential number', 'certloom' ), $number ); }
		foreach ( (array) ( $presentation['fields'] ?? array() ) as $field ) {
			if ( ! is_array( $field ) || ! empty( $field['is_status'] ) ) { continue; }
			$label = trim( (string) ( $field['label'] ?? '' ) );
			$value = trim( (string) ( $field['value'] ?? '' ) );
			if ( '' !== $label && '' !== $value ) { $facts[] = array( $label, $value ); }
		}
		if ( '' !== $batch ) { $facts[] = array( __( 'Cohort / batch', 'certloom' ), $batch ); }
		if ( '' !== $issuer ) { $facts[] = array( __( 'Issued by', 'certloom' ), $issuer ); }
		$facts_html = '';
		if ( $facts ) {
			$facts_html = '<dl class="cvp-profile-facts">';
			foreach ( array_slice( $facts, 0, 20 ) as $fact ) { $facts_html .= '<div><dt>' . esc_html( (string) $fact[0] ) . '</dt><dd dir="auto">' . esc_html( (string) $fact[1] ) . '</dd></div>'; }
			$facts_html .= '</dl>';
		}

		$snapshot = isset( $presentation['snapshot'] ) && is_array( $presentation['snapshot'] ) ? $presentation['snapshot'] : array();
		$certificate_preview = self::normalized_certificate_preview_html( $snapshot );
		$qr = class_exists( 'Ecapla_CertVerify_QR' ) && ! empty( $presentation['verification_url'] ) ? Ecapla_CertVerify_QR::figure_html( (string) $presentation['verification_url'], $prefix ) : '';
		$barcode = class_exists( 'Ecapla_CertVerify_Barcode' ) && '' !== $number ? Ecapla_CertVerify_Barcode::figure_html( $number, $prefix ) : '';
		$verified = ! empty( $presentation['verified_at'] ) ? '<p class="cvp-profile-verified">' . esc_html__( 'Verified from the issuer’s Certloom registry', 'certloom' ) . ' · <time datetime="' . esc_attr( (string) $presentation['verified_at'] ) . '">' . esc_html( self::format_verified_at( $presentation['verified_at'] ) ) . '</time></p>' : '';
		$proof = '<section class="cvp-profile-proof" aria-label="' . esc_attr__( 'Credential verification', 'certloom' ) . '"><div class="cvp-profile-proof-copy"><span class="cvp-eyebrow">' . esc_html__( 'Verification', 'certloom' ) . '</span><h3>' . esc_html__( 'Authentic credential record', 'certloom' ) . '</h3><p>' . esc_html__( 'This page is generated from the credential’s current verified record. Use the QR code or copy this page link to verify it again.', 'certloom' ) . '</p>' . $verified;
		if ( '' !== $credential_id ) { $proof .= '<details class="cvp-profile-technical"><summary>' . esc_html__( 'Credential ID', 'certloom' ) . '</summary><code>' . esc_html( $credential_id ) . '</code></details>'; }
		$proof .= '</div>' . ( '' !== $qr ? '<div class="cvp-profile-qr">' . $qr . '</div>' : '' ) . '</section>';
		$actions = self::actions_html( (array) ( $presentation['actions'] ?? array() ), $prefix );
		return '<article class="cvp-result-card cvp-profile-card cvp-status-' . esc_attr( $status_key ) . '">' . $hero . $facts_html . $certificate_preview . ( '' !== $barcode ? '<div class="cvp-profile-barcode">' . $barcode . '</div>' : '' ) . $proof . $actions . '</article>';
	}


	/**
	 * Browser-only certificate presentation built from the immutable issued
	 * snapshot. This does not manufacture PDF/PNG/JPEG authority; it gives the
	 * recipient a faithful visual certificate on the public Credential Profile.
	 *
	 * @return string
	 */
	private static function normalized_certificate_preview_html( array $snapshot ) {
		$template = isset( $snapshot['template'] ) && is_array( $snapshot['template'] ) ? $snapshot['template'] : array();
		$merge = isset( $snapshot['merge']['values'] ) && is_array( $snapshot['merge']['values'] ) ? $snapshot['merge']['values'] : array();
		$layout = isset( $template['layout'] ) && is_array( $template['layout'] ) ? $template['layout'] : array();
		if ( empty( $layout ) || empty( $merge ) ) { return ''; }
		$verification_url = trim( (string) ( $snapshot['credential']['verification_url'] ?? $merge['verification_url'] ?? $merge['verification.url'] ?? '' ) );
		if ( '' === $verification_url ) { $verification_url = home_url( '/' ); }
		try {
			$resolver = new \Certloom\Application\Rendering\LayoutResolver(
				null,
				null,
				null,
				new \Certloom\Infrastructure\Rendering\LocalQrEncoder(),
				new \Certloom\Infrastructure\Rendering\LocalCode128Encoder()
			);
			$resolved = $resolver->resolve( $layout, $merge, $verification_url, new \Certloom\Infrastructure\Rendering\WordPressLocalAssetResolver() );
			$fonts = ( new \Certloom\Application\Rendering\BrowserPreviewFontManifest() )->for_document( $resolved );
			$plan = ( new \Certloom\Application\Rendering\RenderPlanBuilder() )->build( $resolved, array( 'font_manifest' => $fonts ) );
			$presented = ( new \Certloom\Infrastructure\Rendering\RenderPlanHtmlPreview() )->present( $plan );
		} catch ( \Throwable $error ) {
			return '<section class="cvp-profile-certificate cvp-profile-certificate-unavailable"><h3>' . esc_html__( 'Certificate', 'certloom' ) . '</h3><p>' . esc_html__( 'The credential record is valid, but its visual certificate could not be displayed in this browser.', 'certloom' ) . '</p></section>';
		}
		$html = (string) ( $presented['html'] ?? '' );
		if ( '' === $html ) { return ''; }
		return '<section class="cvp-profile-certificate" aria-labelledby="cvp-profile-certificate-title"><div class="cvp-profile-section-head"><span class="cvp-eyebrow">' . esc_html__( 'Certificate', 'certloom' ) . '</span><h3 id="cvp-profile-certificate-title">' . esc_html__( 'Issued certificate', 'certloom' ) . '</h3></div><div class="cvp-certificate-preview">' . $html . '</div></section>';
	}


	/** @return string */
	private static function normalized_status_label( $code ) {
		$labels = array(
			'valid' => __( 'Valid credential', 'certloom' ),
			'suspended' => __( 'Suspended credential', 'certloom' ),
			'revoked' => __( 'Revoked credential', 'certloom' ),
			'expired' => __( 'Expired credential', 'certloom' ),
			'not_yet_valid' => __( 'Not yet valid', 'certloom' ),
			'pending_review' => __( 'Pending review', 'certloom' ),
			'suspended_noncompliant' => __( 'Requirements not met', 'certloom' ),
			'voided' => __( 'Voided credential', 'certloom' ),
			'not_issued' => __( 'Not issued', 'certloom' ),
		);
		return (string) ( $labels[ sanitize_key( (string) $code ) ] ?? __( 'Credential status', 'certloom' ) );
	}

	/** @return string */
	private static function normalized_status_description( $code ) {
		$descriptions = array(
			'valid' => __( 'This credential currently satisfies its issuance, administrative, validity and compliance requirements.', 'certloom' ),
			'suspended' => __( 'This credential has been administratively suspended.', 'certloom' ),
			'revoked' => __( 'This credential has been revoked.', 'certloom' ),
			'expired' => __( 'This credential is outside its validity period.', 'certloom' ),
			'not_yet_valid' => __( 'This credential’s validity period has not started yet.', 'certloom' ),
			'pending_review' => __( 'This credential is awaiting a required review.', 'certloom' ),
			'suspended_noncompliant' => __( 'This credential does not currently satisfy its compliance requirements.', 'certloom' ),
			'voided' => __( 'This credential was voided and is not valid.', 'certloom' ),
			'not_issued' => __( 'This credential has not reached the issued state.', 'certloom' ),
		);
		return (string) ( $descriptions[ sanitize_key( (string) $code ) ] ?? '' );
	}

	/**
	 * Renders a known legacy certificate as a profile without a search form.
	 *
	 * @param string $number Certificate number.
	 * @param array  $atts   Optional presentation attributes.
	 * @return string
	 */
	public static function credential_profile( $number, $atts = array() ) {
		Ecapla_CertVerify_Shortcode::enqueue_assets();
		$atts = wp_parse_args(
			is_array( $atts ) ? $atts : array(),
			array(
				'heading'       => __( 'Credential profile', 'certloom' ),
				'notfound_text' => __( 'This credential could not be found or is not available.', 'certloom' ),
			)
		);
		$heading = self::limit_text( sanitize_text_field( (string) $atts['heading'] ), 200 );
		$heading_tag = isset( $atts['heading_tag'] ) ? sanitize_key( (string) $atts['heading_tag'] ) : 'h2';
		if ( ! in_array( $heading_tag, array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ), true ) ) {
			$heading_tag = 'h2';
		}
		$notfound = self::limit_text( sanitize_textarea_field( (string) $atts['notfound_text'] ), 500 );
		$instance_id = wp_unique_id( 'certloom-credential-' );
		$heading_id  = $instance_id . '-heading';
		$presentation = Ecapla_CertVerify_Public_Presentation::verify( $number, true, 'profile' );
		if ( is_wp_error( $presentation ) ) {
			$message = 'ecapla_certverify_rate_limited' === $presentation->get_error_code()
				? $presentation->get_error_message()
				: __( 'Credential verification is temporarily unavailable. Please try again later.', 'certloom' );
			$content = self::message_html( 'cvp', $message );
		} elseif ( empty( $presentation['found'] ) ) {
			$content = self::message_html( 'cvp', $notfound );
		} else {
			$content = self::result_card_html( $presentation, 'cvp' );
		}
		return '<section id="' . esc_attr( $instance_id ) . '" class="certloom-credential-profile cvp-widget" aria-labelledby="' . esc_attr( $heading_id ) . '" data-copy-success-text="' . esc_attr__( 'Verification link copied.', 'certloom' ) . '" data-copy-error-text="' . esc_attr__( 'Copy the verification link from your browser.', 'certloom' ) . '"><header class="cvp-header"><p class="cvp-eyebrow">' . esc_html__( 'Verified credential', 'certloom' ) . '</p><' . $heading_tag . ' id="' . esc_attr( $heading_id ) . '" class="cvp-heading">' . esc_html( $heading ) . '</' . $heading_tag . '></header><div class="cvp-result" role="region" aria-labelledby="' . esc_attr( $heading_id ) . '">' . $content . '</div><div class="screen-reader-text certloom-live-status" role="status" aria-live="polite" aria-atomic="true"></div></section>';
	}

	/** @return string */
	private static function result_card_html( $presentation, $prefix ) {
		$status = isset( $presentation['status'] ) && is_array( $presentation['status'] ) ? $presentation['status'] : array();
		$status_key = isset( $status['key'] ) ? sanitize_key( (string) $status['key'] ) : 'unknown';
		$status_label = isset( $status['label'] ) ? (string) $status['label'] : __( 'Certificate found', 'certloom' );
		$status_desc = isset( $status['description'] ) ? (string) $status['description'] : '';
		$rows = '';
		foreach ( (array) ( $presentation['fields'] ?? array() ) as $field ) {
			$value = '' === (string) ( $field['value'] ?? '' ) ? '—' : (string) $field['value'];
			if ( ! empty( $field['is_status'] ) ) {
				$field_status = sanitize_key( (string) ( $field['status_key'] ?? $status_key ) );
				$value_html = '<span class="' . esc_attr( $prefix . '-badge ' . $prefix . '-badge-' . $field_status ) . '">' . esc_html( $value ) . '</span>';
			} else {
				$value_html = esc_html( $value );
			}
			$rows .= '<div class="' . esc_attr( $prefix ) . '-result-row"><dt>' . esc_html( (string) ( $field['label'] ?? '' ) ) . '</dt><dd dir="auto">' . $value_html . '</dd></div>';
		}
		$list = '' !== $rows ? '<dl class="' . esc_attr( $prefix ) . '-result-list">' . $rows . '</dl>' : '';
		$qr = class_exists( 'Ecapla_CertVerify_QR' ) && ! empty( $presentation['verification_url'] ) ? Ecapla_CertVerify_QR::figure_html( (string) $presentation['verification_url'], $prefix ) : '';
		$barcode = class_exists( 'Ecapla_CertVerify_Barcode' ) && ! empty( $presentation['certificate_number'] ) ? Ecapla_CertVerify_Barcode::figure_html( (string) $presentation['certificate_number'], $prefix ) : '';
		$issuer = ! empty( $presentation['issuer'] ) ? '<p class="' . esc_attr( $prefix ) . '-result-issuer"><span>' . esc_html__( 'Issued by', 'certloom' ) . '</span> ' . esc_html( (string) $presentation['issuer'] ) . '</p>' : '';
		$verified = ! empty( $presentation['verified_at'] ) ? '<p class="' . esc_attr( $prefix ) . '-verified-at">' . esc_html__( 'Verified', 'certloom' ) . ' <time datetime="' . esc_attr( (string) $presentation['verified_at'] ) . '">' . esc_html( self::format_verified_at( $presentation['verified_at'] ) ) . '</time></p>' : '';
		$desc = '' !== $status_desc ? '<p class="' . esc_attr( $prefix ) . '-status-description">' . esc_html( $status_desc ) . '</p>' : '';
		$actions = self::actions_html( (array) ( $presentation['actions'] ?? array() ), $prefix );
		$qr_block = '' !== $qr ? '<div class="certloom-result-qr">' . $qr . '</div>' : '';
		return '<div class="' . esc_attr( $prefix ) . '-result-card ' . esc_attr( $prefix . '-status-' . $status_key ) . '"><div class="' . esc_attr( $prefix ) . '-result-summary"><span class="' . esc_attr( $prefix . '-status-mark' ) . '" aria-hidden="true">' . esc_html( self::status_icon( $status_key ) ) . '</span><div><p class="' . esc_attr( $prefix ) . '-result-success">' . esc_html( $status_label ) . '</p>' . $desc . $issuer . '</div></div>' . $list . $barcode . $qr_block . $verified . $actions . '</div>';
	}

	/** @return string */
	private static function actions_html( $actions, $prefix ) {
		$html = '';
		foreach ( $actions as $action ) {
			if ( ! is_array( $action ) ) { continue; }
			$type = sanitize_key( (string) ( $action['type'] ?? '' ) );
			$label = sanitize_text_field( (string) ( $action['label'] ?? '' ) );
			$url = isset( $action['url'] ) ? esc_url( (string) $action['url'] ) : '';
			if ( '' === $label || ! in_array( $type, array( 'copy', 'profile', 'print', 'share' ), true ) ) { continue; }
			if ( 'share' === $type ) {
				$links_html = '';
				foreach ( (array) ( $action['links'] ?? array() ) as $link ) {
					if ( ! is_array( $link ) ) { continue; }
					$platform = sanitize_key( (string) ( $link['platform'] ?? '' ) );
					$link_label = sanitize_text_field( (string) ( $link['label'] ?? '' ) );
					$link_url = esc_url( (string) ( $link['url'] ?? '' ), array( 'http', 'https', 'mailto' ) );
					if ( '' === $link_label || '' === $link_url || ! in_array( $platform, array( 'linkedin', 'whatsapp', 'facebook', 'x', 'email' ), true ) ) { continue; }
					$external = 'email' === $platform ? '' : ' target="_blank" rel="nofollow noopener noreferrer"';
					$links_html .= '<a class="certloom-share-link certloom-share-link--' . esc_attr( $platform ) . '" href="' . $link_url . '"' . $external . '>' . esc_html( $link_label ) . '</a>';
				}
				if ( '' !== $links_html && '' !== $url ) {
					$html .= '<details class="certloom-share"><summary class="certloom-result-action ' . esc_attr( $prefix ) . '-result-action">' . esc_html( $label ) . '</summary><div class="certloom-share-menu">' . $links_html . '<button type="button" class="certloom-share-link certloom-share-copy certloom-copy-link" data-copy-url="' . esc_attr( $url ) . '">' . esc_html__( 'Copy URL', 'certloom' ) . '</button></div></details>';
				}
			} elseif ( 'copy' === $type && '' !== $url ) {
				$html .= '<button type="button" class="certloom-result-action ' . esc_attr( $prefix ) . '-result-action certloom-copy-link" data-copy-url="' . esc_attr( $url ) . '">' . esc_html( $label ) . '</button>';
			} elseif ( 'print' === $type ) {
				$html .= '<button type="button" class="certloom-result-action ' . esc_attr( $prefix ) . '-result-action certloom-print is-secondary">' . esc_html( $label ) . '</button>';
			} elseif ( '' !== $url ) {
				$html .= '<a class="certloom-result-action ' . esc_attr( $prefix ) . '-result-action" href="' . $url . '">' . esc_html( $label ) . '</a>';
			}
		}
		return '' !== $html ? '<div class="' . esc_attr( $prefix ) . '-result-actions">' . $html . '<span class="certloom-action-feedback" aria-live="polite"></span></div>' : '';
	}

	/** @return string */
	private static function message_html( $prefix, $message ) {
		return '<div class="' . esc_attr( $prefix ) . '-result-notfound"><p>' . esc_html( (string) $message ) . '</p></div>';
	}

	/** @return string */
	private static function format_verified_at( $value ) {
		$timestamp = strtotime( (string) $value );
		if ( false === $timestamp ) { return (string) $value; }
		if ( function_exists( 'wp_date' ) ) {
			return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
		}
		return gmdate( 'Y-m-d H:i', $timestamp );
	}

	/**
	 * Builds ordered heading content for either class family.
	 *
	 * @param array  $atts   Sanitized attributes.
	 * @param string $prefix CSS prefix.
	 * @return string
	 */
	private static function header_content( $atts, $prefix ) {
		$parts = array(
			'eyebrow' => $atts['show_eyebrow'] && '' !== $atts['eyebrow']
				? '<p class="' . esc_attr( $prefix ) . '-eyebrow">' . esc_html( $atts['eyebrow'] ) . '</p>'
				: '',
			'heading' => $atts['show_heading'] && '' !== $atts['heading']
				? '<' . $atts['heading_tag'] . ' class="' . esc_attr( $prefix ) . '-heading">' . esc_html( $atts['heading'] ) . '</' . $atts['heading_tag'] . '>'
				: '',
			'description' => $atts['show_description'] && '' !== $atts['description']
				? '<p class="' . esc_attr( $prefix ) . '-description">' . esc_html( $atts['description'] ) . '</p>'
				: '',
		);

		$html = '';
		foreach ( explode( '-', $atts['content_order'] ) as $part ) {
			if ( isset( $parts[ $part ] ) ) {
				$html .= $parts[ $part ];
			}
		}
		return $html;
	}

	/**
	 * Sanitizes content and layout attributes.
	 *
	 * @param array $atts      Attributes.
	 * @param bool  $is_modern Whether modern-only values are accepted.
	 * @return array
	 */
	private static function sanitize_common_atts( $atts, $is_modern ) {
		$orders = array(
			'eyebrow-heading-description',
			'eyebrow-description-heading',
			'heading-eyebrow-description',
			'heading-description-eyebrow',
			'description-eyebrow-heading',
			'description-heading-eyebrow',
		);
		$tags = array( 'h1', 'h2', 'h3', 'h4', 'div', 'p' );

		foreach ( array( 'eyebrow', 'heading', 'placeholder', 'button_text', 'guide_eyebrow', 'guide_heading', 'credit_text' ) as $key ) {
			if ( isset( $atts[ $key ] ) ) {
				$atts[ $key ] = sanitize_text_field( (string) $atts[ $key ] );
			}
		}
		$atts['description']   = sanitize_textarea_field( (string) $atts['description'] );
		$atts['notfound_text'] = sanitize_textarea_field( (string) $atts['notfound_text'] );

		$limits = array(
			'eyebrow'       => 120,
			'heading'       => 200,
			'description'   => 2000,
			'placeholder'   => 150,
			'button_text'   => 100,
			'notfound_text' => 500,
			'guide_eyebrow' => 120,
			'guide_heading' => 200,
			'credit_text'   => 200,
		);
		foreach ( $limits as $key => $length ) {
			if ( isset( $atts[ $key ] ) ) {
				$atts[ $key ] = self::limit_text( $atts[ $key ], $length );
			}
		}

		$atts['content_order'] = in_array( $atts['content_order'], $orders, true ) ? $atts['content_order'] : $orders[0];
		$atts['heading_tag']   = in_array( $atts['heading_tag'], $tags, true ) ? $atts['heading_tag'] : 'h2';
		$atts['text_align']    = in_array( $atts['text_align'], array( 'left', 'center', 'right' ), true ) ? $atts['text_align'] : 'left';
		foreach ( array( 'show_eyebrow', 'show_heading', 'show_description', 'show_guide', 'show_credit' ) as $key ) {
			$atts[ $key ] = filter_var( $atts[ $key ], FILTER_VALIDATE_BOOLEAN );
		}
		return $atts;
	}

	/**
	 * Darkens a validated hexadecimal color for a consistent hover/focus accent.
	 *
	 * @param string $hex     Hex color.
	 * @param int    $percent Darkening percentage.
	 * @return string
	 */
	private static function darken_hex_color( $hex, $percent ) {
		$hex = ltrim( (string) $hex, '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( 6 !== strlen( $hex ) || ! ctype_xdigit( $hex ) ) {
			return '#0b5543';
		}
		$factor = max( 0, min( 100, 100 - absint( $percent ) ) ) / 100;
		$red    = (int) round( hexdec( substr( $hex, 0, 2 ) ) * $factor );
		$green  = (int) round( hexdec( substr( $hex, 2, 2 ) ) * $factor );
		$blue   = (int) round( hexdec( substr( $hex, 4, 2 ) ) * $factor );
		return sprintf( '#%02x%02x%02x', $red, $green, $blue );
	}

	/** Preserves only WordPress routing parameters needed by plain permalinks. */
	private static function routing_hidden_inputs() {
		$html = '';
		foreach ( array( 'p', 'page_id', 'name', 'post_type', 'lang' ) as $key ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only public routing state.
			if ( ! isset( $_GET[ $key ] ) || is_array( $_GET[ $key ] ) ) {
				continue;
			}
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only public routing state.
			$value = sanitize_text_field( wp_unslash( $_GET[ $key ] ) );
			$html .= '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '" />';
		}
		return $html;
	}

	/**
	 * Gets a bounded direct-search number from supported query aliases.
	 *
	 * @return string
	 */
	private static function direct_number() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Public read-only verification submission.
		if ( isset( $_POST['certloom_number'] ) && ! is_array( $_POST['certloom_number'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Public read-only verification submission.
			return self::limit_text( sanitize_text_field( wp_unslash( $_POST['certloom_number'] ) ), 100 );
		}
		$keys = array( 'certloom_number', 'cv_number' );
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Public direct-link lookup is read-only and intentionally unauthenticated.
		foreach ( $keys as $key ) {
			if ( isset( $_GET[ $key ] ) ) {
				$number = self::limit_text( sanitize_text_field( wp_unslash( $_GET[ $key ] ) ), 100 );
				// phpcs:enable WordPress.Security.NonceVerification.Recommended
				return $number;
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( class_exists( 'Ecapla_CertVerify_Public_Router' ) ) {
			return self::limit_text( Ecapla_CertVerify_Public_Router::requested_number(), 100 );
		}
		return '';
	}


	/** Privacy-safe verification-source attribution. */
	private static function analytics_source() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public read-only source tag.
		$value = isset( $_GET['certloom_source'] ) && ! is_array( $_GET['certloom_source'] ) ? sanitize_key( wp_unslash( $_GET['certloom_source'] ) ) : 'manual';
		return class_exists( 'Ecapla_CertVerify_Analytics' ) ? Ecapla_CertVerify_Analytics::normalize_source( $value ) : 'manual';
	}

	/** Secondary proof comes only from POST so it never becomes part of a shareable URL. */
	private static function direct_code() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Public read-only proof submission.
		if ( ! isset( $_POST['certloom_code'] ) || is_array( $_POST['certloom_code'] ) ) { return ''; }
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Public read-only proof submission.
		return self::limit_text( sanitize_text_field( wp_unslash( $_POST['certloom_code'] ) ), 32 );
	}

	/**
	 * Legacy inline CSS variables.
	 *
	 * @param array $atts Attributes.
	 * @return string
	 */
	private static function legacy_style_vars( $atts ) {
		$css    = '';
		$accent = sanitize_hex_color( (string) $atts['accent'] );
		if ( $accent ) {
			$css .= '--cv-accent:' . $accent . ';';
		}
		$credit_color = sanitize_hex_color( (string) $atts['credit_color'] );
		if ( $credit_color ) {
			$css .= '--cv-credit-color:' . $credit_color . ';';
		}
		if ( is_numeric( $atts['credit_size'] ) ) {
			$css .= '--cv-credit-size:' . min( 24, max( 8, absint( $atts['credit_size'] ) ) ) . 'px;';
		}
		if ( in_array( $atts['credit_align'], array( 'left', 'center', 'right' ), true ) ) {
			$css .= '--cv-credit-align:' . $atts['credit_align'] . ';';
		}
		return $css;
	}

	/**
	 * Modern inline CSS variables.
	 *
	 * @param array $atts Attributes.
	 * @return string
	 */
	private static function template_style_vars( $atts ) {
		$css    = '';
		$accent = sanitize_hex_color( (string) $atts['accent'] );
		if ( $accent ) {
			$css .= '--cvx-accent:' . $accent . ';';
			$css .= '--cvx-accent-strong:' . self::darken_hex_color( $accent, 18 ) . ';';
		}
		$credit_color = sanitize_hex_color( (string) $atts['credit_color'] );
		if ( $credit_color ) {
			$css .= '--cvx-credit-color:' . $credit_color . ';';
		}
		if ( is_numeric( $atts['credit_size'] ) ) {
			$css .= '--cvx-credit-size:' . min( 24, max( 8, absint( $atts['credit_size'] ) ) ) . 'px;';
		}
		if ( in_array( $atts['credit_align'], array( 'left', 'center', 'right' ), true ) ) {
			$css .= '--cvx-credit-align:' . $atts['credit_align'] . ';';
		}
		return $css;
	}

	private static function limit_text( $value, $length ) {
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( (string) $value, 0, $length );
		}
		return substr( (string) $value, 0, $length );
	}

	private static function status_icon( $key ) {
		$icons = array(
			'valid'     => '✓',
			'suspended' => '!',
			'withdrawn' => '×',
			'expired'   => '○',
		);
		return isset( $icons[ $key ] ) ? $icons[ $key ] : '';
	}

	private static function status_icon_entity( $key ) {
		$icons = array(
			'valid'     => '&#10003;',
			'suspended' => '&#9888;',
			'withdrawn' => '&#10005;',
			'expired'   => '&#9675;',
		);
		return isset( $icons[ $key ] ) ? $icons[ $key ] : '';
	}
}
