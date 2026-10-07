<?php
namespace Certloom\Infrastructure\Recipient;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/** One account surface for WordPress, WooCommerce and the recipient portal. */
final class RecipientCollectionUi {
	public function register() {
		add_action( 'show_user_profile', array( $this, 'profile' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'profile_assets' ) );
		add_action( 'woocommerce_edit_account_form_start', array( $this, 'account' ) );
		add_action( 'init', array( $this, 'shortcode' ) );
		add_action( 'template_redirect', array( $this, 'private_page' ) );
		add_action( 'template_redirect', array( $this, 'account_page' ), 1 );
	}
	public static function account_url() { return add_query_arg( 'certloom_account', '1', home_url( '/' ) ); }
	public function account_page() {
		if ( ! isset( $_GET['certloom_account'] ) || '1' !== $_GET['certloom_account'] ) { return; } // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only account route.
		self::prevent_cache();
		if ( ! is_user_logged_in() ) { wp_safe_redirect( wp_login_url( self::account_url() ) ); exit; }
		self::assets(); status_header( 200 );
		get_header(); echo '<main class="certloom-recipient-account-page">' . self::render() . '</main>'; get_footer(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped account shell.
		exit;
	}
	public function shortcode() { add_shortcode( 'certloom_my_details', array( __CLASS__, 'render' ) ); }
	public function profile_assets( $hook ) { if ( 'profile.php' === $hook && ! current_user_can( ECAPLA_CERTVERIFY_CAP ) ) { self::assets(); } }
	public function profile( $user ) {
		// Organization staff configure collection and manage recipient records in
		// Certloom. The optional WordPress profile adapter is for recipients only.
		if ( (int) $user->ID !== get_current_user_id() || current_user_can( ECAPLA_CERTVERIFY_CAP ) ) { return; }
		echo self::render(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped account shell.
	}
	public function account() { echo self::render(); } // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped account shell.
	public function private_page() {
		if ( ! is_user_logged_in() ) { return; }
		$post = get_post();
		$is_account = function_exists( 'is_account_page' ) && is_account_page();
		$content = $post instanceof \WP_Post ? $post->post_content : '';
		foreach ( array( 'certloom_my_details', 'certloom_my_credentials', 'certloom_my_certificates' ) as $tag ) { $is_account = $is_account || has_shortcode( $content, $tag ); }
		$is_account = $is_account || has_block( 'certloom/my-credentials', $content );
		if ( $is_account ) { self::prevent_cache(); self::assets(); }
	}
	private static function prevent_cache() {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); }
		if ( ! headers_sent() ) { nocache_headers(); header( 'X-Robots-Tag: noindex', true ); }
	}
	public static function assets() {
		$css = 'public/assets/certloom-recipient-account.css'; $js = 'public/assets/certloom-recipient-account.js';
		wp_enqueue_style( 'certloom-tokens', ECAPLA_CERTVERIFY_URL . 'assets/css/certloom-tokens.css', array(), (string) filemtime( ECAPLA_CERTVERIFY_PATH . 'assets/css/certloom-tokens.css' ) );
		wp_enqueue_style( 'certloom-recipient-account', ECAPLA_CERTVERIFY_URL . $css, array( 'certloom-tokens' ), (string) filemtime( ECAPLA_CERTVERIFY_PATH . $css ) );
		wp_enqueue_script( 'certloom-recipient-account', ECAPLA_CERTVERIFY_URL . $js, array(), (string) filemtime( ECAPLA_CERTVERIFY_PATH . $js ), true );
	}
	/** No field values in cached markup; data comes from the authenticated API. */
	public static function render( $atts = array() ) {
		if ( ! is_user_logged_in() ) { return '<p class="certloom-account-sign-in">' . esc_html__( 'Sign in to complete your certificate details.', 'certloom' ) . '</p>'; }
		self::prevent_cache(); self::assets();
		static $counter = 0; $id = 'certloom-account-' . ++$counter;
		ob_start(); ?>
		<section class="certloom-account" aria-labelledby="<?php echo esc_attr( $id ); ?>" data-certloom-account data-endpoint="<?php echo esc_url( rest_url( 'certloom/v1/me/details' ) ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>" data-messages="<?php echo esc_attr( wp_json_encode( self::messages() ) ); ?>">
			<header class="certloom-account__header">
				<div><p class="certloom-account__eyebrow"><?php esc_html_e( 'Certloom · Your account', 'certloom' ); ?></p><h2 id="<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'Your certificate details', 'certloom' ); ?></h2><p><?php esc_html_e( 'Keep your details ready for your next certificate. Some information may need the issuer’s approval.', 'certloom' ); ?></p></div>
				<span class="certloom-account__mark" aria-hidden="true">✓</span>
			</header>
			<div class="certloom-account__workspace" data-account-workspace aria-busy="true"><p><?php esc_html_e( 'Loading your details…', 'certloom' ); ?></p></div>
			<p class="certloom-account__feedback" data-account-feedback role="status" aria-live="polite" aria-atomic="true"></p>
			<noscript><p><?php esc_html_e( 'Enable JavaScript to load and save your certificate details. Contact your issuer if you need help.', 'certloom' ); ?></p></noscript>
		</section>
		<?php return (string) ob_get_clean();
	}
	public static function messages() {
		return array(
			'requests' => __( 'Certificate requests', 'certloom' ), 'requestsIntro' => __( 'See what happens next and who needs to act. Issued certificates are available in My credentials.', 'certloom' ),
			'noRequests' => __( 'No certificates are waiting right now.', 'certloom' ), 'moreRequests' => __( 'Load more requests', 'certloom' ),
			'activeRequests' => __( 'In progress', 'certloom' ), 'closedRequests' => __( 'Closed requests', 'certloom' ), 'noClosedRequests' => __( 'No closed requests yet.', 'certloom' ),
			'cancelled' => __( 'Your organization closed this request without issuing a certificate. Contact them if you think this needs review.', 'certloom' ),
			'expired' => __( 'The deadline for this certificate request has passed. Your details remain saved. Contact your organization to discuss a new request.', 'certloom' ),
			'issued' => __( 'Your certificate has been issued. You can find it in My credentials.', 'certloom' ),
			'details_needed' => __( 'Your details are needed. Complete them so your organization can prepare your certificate.', 'certloom' ), 'approval_pending' => __( 'Your details have been submitted. Your organization is reviewing them.', 'certloom' ),
			'source_pending' => __( 'Your organization is waiting for completion or result confirmation from the learning platform.', 'certloom' ), 'issuer_review' => __( 'Your organization is reviewing this certificate request. Contact them if you need help.', 'certloom' ),
			'preparing' => __( 'Your certificate request is being prepared. Refresh shortly to see its progress.', 'certloom' ), 'qualification_pending' => __( 'Completion or eligibility has not been confirmed. Contact your organization to review the requirements.', 'certloom' ),
			'personal' => __( 'Personal details', 'certloom' ), 'programs' => __( 'Your Programs', 'certloom' ), 'programIntro' => __( 'Choose a Program to complete the details your organization needs for your certificate.', 'certloom' ),
			'certificateName' => __( 'Name on your certificate', 'certloom' ),
			'nameFromAccount' => __( 'Check your account name before completing your details. Account name changes apply to future certificates.', 'certloom' ),
			'nameFromOrganization' => __( 'Your organization manages this name. Contact them if it needs a correction.', 'certloom' ),
			'nameNeedsApproval' => __( 'Enter your certificate name in Personal details. Your organization reviews the exact name before issuing. A proposed correction holds future requests until it is reviewed.', 'certloom' ),
			'nameHistorical' => __( 'For a correction to an issued certificate, contact your organization. Editing your profile keeps the original certificate unchanged.', 'certloom' ),
			'personalIntro' => __( 'These details can be reused across your Programs.', 'certloom' ), 'registrationIntro' => __( 'Complete the details required by this Program. Previously saved personal details are reused.', 'certloom' ),
			'reusableHelp' => __( 'Saved in your personal details and reused across Programs.', 'certloom' ), 'enrollmentHelp' => __( 'Saved only for this Program enrollment.', 'certloom' ),
			'save' => __( 'Save details', 'certloom' ), 'saving' => __( 'Saving…', 'certloom' ), 'saved' => __( 'Your details are saved. Items awaiting approval will be reviewed by the issuer.', 'certloom' ),
			'required' => __( 'Required', 'certloom' ), 'optional' => __( 'Optional', 'certloom' ), 'complete' => __( 'Saved', 'certloom' ), 'missing' => __( 'To complete', 'certloom' ), 'awaiting_approval' => __( 'Awaiting approval', 'certloom' ), 'rejected' => __( 'Please update and resubmit', 'certloom' ),
			'approval' => __( 'The issuer reviews this detail before it can be used for issuance.', 'certloom' ),
			'empty' => __( 'No personal details to complete right now.', 'certloom' ), 'emptyRegistration' => __( 'No details to complete for this Program right now.', 'certloom' ), 'noPrograms' => __( 'Your Program registrations will appear here when the issuer adds them.', 'certloom' ),
			'unlinked' => __( 'Your account is not linked to a Certloom recipient yet. Contact the issuing organization to connect your account.', 'certloom' ),
			'issuer' => __( 'Provided by the issuer', 'certloom' ), 'issuerIntro' => __( 'Results and other issuer-owned information are supplied by staff or the connected learning platform.', 'certloom' ),
			'refresh' => __( 'Refresh details', 'certloom' ), 'retry' => __( 'Try again', 'certloom' ), 'loadError' => __( 'Your details could not be loaded. Try again shortly.', 'certloom' ), 'saveError' => __( 'Your details could not be saved. Your entries are still here; please try again.', 'certloom' ),
			'stale' => __( 'Your details or their settings changed in another session. Your entries are still here. Refresh to load the latest details before saving.', 'certloom' ),
			'discard' => __( 'You have unsaved changes. Discard them and load the latest details?', 'certloom' ), 'requiredError' => __( 'Complete the required details before saving.', 'certloom' ),
			'view' => __( 'Complete details', 'certloom' ), 'more' => __( 'Load more Programs', 'certloom' ), 'future' => __( 'Changes apply to future certificates. Certificates already issued keep their original details.', 'certloom' ),
		);
	}
}
