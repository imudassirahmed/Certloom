<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
?>
<details id="certloom-woocommerce-details" class="certloom-technical-details" <?php echo ! empty( $woocommerce['pending_mappings'] ) || is_wp_error( $woocommerce['pending_error'] ?? null ) ? 'open' : ''; ?>>
<summary><?php esc_html_e( 'Collect customer details after completion', 'certloom' ); ?></summary>
<div class="certloom-scope-guidance">
 <h3><?php esc_html_e( 'Let customers complete missing details', 'certloom' ); ?></h3>
 <p><?php esc_html_e( 'Choose this flow when the Program needs customer information such as a personal ID or enrollment number. A confirmed Completed order creates a request in the customer account. Certloom waits for their details and any organization approval, then rechecks the original order before issuing.', 'certloom' ); ?></p>
 <p><?php esc_html_e( 'WooCommerce supplies Completed order confirmation. Payment or Processing alone does not qualify. This flow supports enabled personal/enrollment fields and shared Program values; it does not fetch arbitrary exam scores or results. Remove or pause the completion-only mapping for this product first. Existing orders are not backfilled automatically. One certificate is assessed per mapped product in an order, regardless of quantity. Orders with refunds require organization review.', 'certloom' ); ?></p>
</div>
<?php if ( is_wp_error( $woocommerce['pending_error'] ?? null ) ) : ?>
 <div class="notice notice-error inline"><p><?php echo esc_html( $woocommerce['pending_error']->get_error_message() ); ?></p></div>
<?php elseif ( ! empty( $woocommerce['configuration_ready'] ) ) : ?>
 <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="certloom-clean-form certloom-integration-map-form">
  <input type="hidden" name="action" value="certloom_woocommerce_pending_create"><?php wp_nonce_field( 'certloom_woocommerce_pending_create' ); ?>
  <div class="certloom-form-grid certloom-form-grid-3">
   <label class="certloom-field-control"><span><?php esc_html_e( 'WooCommerce product', 'certloom' ); ?></span><select name="product_id" required data-certloom-context-lookup="woocommerce_product" data-certloom-context-search-placeholder="<?php esc_attr_e( 'Search products by name, SKU or ID…', 'certloom' ); ?>" data-certloom-context-nonce="<?php echo esc_attr( $certloom_context_nonce ); ?>"><option value=""><?php esc_html_e( 'Choose a product', 'certloom' ); ?></option><?php foreach ( $woocommerce['products'] as $product ) : ?><option value="<?php echo esc_attr( $product['id'] ); ?>"><?php echo esc_html( $product['title'] ); ?></option><?php endforeach; ?></select></label>
   <label class="certloom-field-control"><span><?php esc_html_e( 'Certloom Program', 'certloom' ); ?></span><select name="program_id" required data-certloom-context-lookup="program" data-certloom-context-nonce="<?php echo esc_attr( $certloom_context_nonce ); ?>"><option value=""><?php esc_html_e( 'Choose a Program', 'certloom' ); ?></option><?php foreach ( $woocommerce['programs'] as $program ) : ?><option value="<?php echo esc_attr( $program['id'] ); ?>"><?php echo esc_html( $program['title'] ); ?></option><?php endforeach; ?></select></label>
   <label class="certloom-field-control"><span><?php esc_html_e( 'Batch (optional)', 'certloom' ); ?></span><select name="batch_id" data-certloom-context-lookup="batch" data-certloom-context-nonce="<?php echo esc_attr( $certloom_context_nonce ); ?>"><option value="0"><?php esc_html_e( 'No Batch — use Program context', 'certloom' ); ?></option></select></label>
  </div>
  <div class="certloom-form-actions"><button class="button button-primary" type="submit"><?php esc_html_e( 'Connect product with customer details', 'certloom' ); ?></button></div>
 </form>
<?php endif; ?>
<?php if ( ! empty( $woocommerce['pending_mappings'] ) ) : ?>
 <div class="certloom-mapping-list">
 <?php foreach ( $woocommerce['pending_mappings'] as $connection ) : ?>
  <article class="certloom-mapping-row"><div><strong><?php echo esc_html( $woo_product_labels[ $connection['product_id'] ] ?? sprintf( __( 'Product %d', 'certloom' ), $connection['product_id'] ) ); ?></strong><small><?php echo esc_html( $woocommerce['pending_program_labels'][ $connection['program_id'] ] ?? __( 'Program unavailable', 'certloom' ) ); ?> · <?php esc_html_e( 'Customer details and completion checks', 'certloom' ); ?></small></div>
   <span class="certloom-state-pill <?php echo $connection['active'] ? 'is-success' : ''; ?>"><?php echo $connection['active'] ? esc_html__( 'On', 'certloom' ) : esc_html__( 'Paused', 'certloom' ); ?></span>
   <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="certloom_woocommerce_pending_change"><input type="hidden" name="source_key" value="<?php echo esc_attr( $connection['key'] ); ?>"><input type="hidden" name="fingerprint" value="<?php echo esc_attr( $connection['fingerprint'] ); ?>"><?php wp_nonce_field( 'certloom_woocommerce_pending_change_' . $connection['key'] ); ?>
    <button class="button button-small" name="command" value="<?php echo $connection['active'] ? 'pause' : 'activate'; ?>" type="submit"><?php echo $connection['active'] ? esc_html__( 'Pause', 'certloom' ) : esc_html__( 'Activate', 'certloom' ); ?></button>
    <button class="button button-small" name="command" value="delete" type="submit" data-ecapla-confirm="<?php esc_attr_e( 'Remove this customer-detail connection? Existing requests will remain held for organization review.', 'certloom' ); ?>"><?php esc_html_e( 'Remove', 'certloom' ); ?></button>
   </form>
  </article>
 <?php endforeach; ?>
 </div>
<?php endif; ?>
</details>
