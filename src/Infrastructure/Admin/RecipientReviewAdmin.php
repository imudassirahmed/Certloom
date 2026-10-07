<?php
namespace Certloom\Infrastructure\Admin;

use Certloom\Infrastructure\Recipient\RecipientCollectionUi;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class RecipientReviewAdmin {
	private $page_hook;
	public function register() { add_action( 'admin_menu', array( $this, 'menu' ), 30 ); add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) ); add_filter( 'admin_title', array( $this, 'title' ) ); }
	public function menu() {
		$this->page_hook = add_submenu_page( null, __( 'Review recipient details', 'certloom' ), __( 'Recipient reviews', 'certloom' ), \Ecapla_CertVerify_Permissions::CAP_MANAGE_RECIPIENTS, 'certloom-recipient-reviews', array( $this, 'render' ) );
	}
	public function enqueue( $hook ) { if ( $hook === $this->page_hook ) { RecipientCollectionUi::assets(); } }
	public function title( $title ) {
		$screen = get_current_screen();
		return $screen && $screen->id === $this->page_hook ? __( 'Recipient reviews', 'certloom' ) . ' ‹ ' . get_bloginfo( 'name' ) . ' — WordPress' : $title;
	}
	public function render() {
		if ( ! current_user_can( \Ecapla_CertVerify_Permissions::CAP_MANAGE_RECIPIENTS ) ) { wp_die( esc_html__( 'You cannot review recipient details.', 'certloom' ), '', array( 'response' => 403 ) ); }
		RecipientCollectionUi::assets();
		$messages = array( 'loadError' => __( 'Submitted details could not be loaded. Try again shortly.', 'certloom' ), 'empty' => __( 'You’re all caught up. No recipient details are awaiting review.', 'certloom' ), 'personal' => __( 'Reusable personal detail', 'certloom' ), 'approve' => __( 'Approve detail', 'certloom' ), 'reject' => __( 'Request an update', 'certloom' ), 'changed' => __( 'The field settings changed. Request an update so the recipient can submit under the current settings.', 'certloom' ), 'saved' => __( 'Your decision is saved.', 'certloom' ), 'more' => __( 'Next page', 'certloom' ), 'refresh' => __( 'Refresh reviews', 'certloom' ), 'value' => __( 'Submitted value', 'certloom' ) );
		?>
		<div class="wrap"><section class="certloom-account" aria-labelledby="certloom-reviews-title" data-certloom-reviews data-endpoint="<?php echo esc_url( rest_url( 'certloom/v1/admin/recipient-submissions' ) ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>" data-messages="<?php echo esc_attr( wp_json_encode( $messages ) ); ?>">
			<header class="certloom-account__header"><div><p class="certloom-account__eyebrow"><?php esc_html_e( 'Certloom · Recipient reviews', 'certloom' ); ?></p><h1 id="certloom-reviews-title"><?php esc_html_e( 'Help recipients get certificate-ready', 'certloom' ); ?></h1><p><?php esc_html_e( 'Check each submitted detail before approving it. Request an update when the recipient needs to make a correction.', 'certloom' ); ?></p></div></header>
			<?php ( new AdminProductNavigation() )->render_current_subnav(); ?>
			<div class="certloom-account__workspace" data-account-workspace aria-busy="true"><p><?php esc_html_e( 'Loading submitted details…', 'certloom' ); ?></p></div><p class="certloom-account__feedback" data-account-feedback role="status" aria-live="polite" aria-atomic="true"></p>
			<noscript><p><?php esc_html_e( 'Enable JavaScript to review submitted details.', 'certloom' ); ?></p></noscript>
		</section></div>
		<?php
	}
}
