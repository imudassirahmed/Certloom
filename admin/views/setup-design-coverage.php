<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
?>
<section class="certloom-setup-field-review" data-certloom-design-coverage aria-live="polite">
	<h2><?php esc_html_e( 'Do your fields fit this design?', 'certloom' ); ?></h2>
	<p><?php esc_html_e( 'Required information can be collected without appearing on the certificate. Private ID and enrollment details stay private unless you deliberately change their exposure in Fields Manager.', 'certloom' ); ?></p>
	<ul data-certloom-design-coverage-rows><?php foreach ( $wizard_design_coverage['rows'] as $row ) : ?><li><strong><?php echo esc_html( $row['label'] ); ?></strong><span><?php echo esc_html( $row['message'] ); ?></span></li><?php endforeach; ?></ul>
	<div class="certloom-setup-v2-actions"><a href="<?php echo esc_url( $certloom_step_url( 'fields' ) ); ?>"><?php esc_html_e( 'Review field choices', 'certloom' ); ?></a><a href="<?php echo esc_url( admin_url( 'admin.php?page=certloom-templates&design_view=templates' ) ); ?>"><?php esc_html_e( 'Add fields to a design', 'certloom' ); ?></a><span><?php esc_html_e( 'You can also continue and customize the design later.', 'certloom' ); ?></span></div>
</section>
