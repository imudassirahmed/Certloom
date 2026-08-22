<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are local to the rendering method that includes this file.
// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Templates only read display state; all mutations are handled by nonce-protected controllers.
?>
<div class="wrap ecapla-certverify-admin-wrap">
	<?php if ( isset( $database_error ) && is_wp_error( $database_error ) ) : ?>
		<div class="notice notice-error inline"><p><?php echo esc_html( $database_error->get_error_message() ); ?></p></div>
	<?php endif; ?>
	<?php include ECAPLA_CERTVERIFY_PATH . 'admin/views/partials/brand-bar.php'; ?>
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Certificates', 'certloom' ); ?></h1>
	<a href="<?php echo esc_url( admin_url( 'admin.php?page=certloom-add' ) ); ?>" class="page-title-action"><?php esc_html_e( 'Add New', 'certloom' ); ?></a>
	<hr class="wp-header-end">

	<?php if ( isset( $_GET['ecapla_certverify_saved'] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Certificate saved.', 'certloom' ); ?></p></div>
	<?php endif; ?>
	<?php if ( isset( $_GET['ecapla_certverify_deleted'] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Certificate deleted.', 'certloom' ); ?></p></div>
	<?php endif; ?>
	<?php if ( isset( $_GET['ecapla_certverify_bulk_deleted'] ) ) : ?>
		<?php $ecapla_certverify_deleted_count = absint( $_GET['ecapla_certverify_bulk_deleted'] ); ?>
		<div class="notice notice-success is-dismissible"><p>
			<?php
			echo esc_html(
				sprintf(
					/* translators: %d: number of deleted certificates. */
					_n( '%d certificate deleted.', '%d certificates deleted.', $ecapla_certverify_deleted_count, 'certloom' ),
					$ecapla_certverify_deleted_count
				)
			);
			?>
		</p></div>
	<?php endif; ?>
	<?php if ( isset( $_GET['ecapla_certverify_error'] ) ) : ?>
		<div class="notice notice-error"><p><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['ecapla_certverify_error'] ) ) ); ?></p></div>
	<?php endif; ?>

	<?php if ( ! empty( $_GET['legacy_expired'] ) ) : ?>
		<div class="notice notice-info"><p>
			<?php esc_html_e( 'Showing ambiguous legacy Expired records that require an explicit workflow-status review.', 'certloom' ); ?>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=certloom-certificates' ) ); ?>"><?php esc_html_e( 'Show all certificates', 'certloom' ); ?></a>
		</p></div>
	<?php endif; ?>

	<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="ecapla-certverify-list-filters">
		<input type="hidden" name="page" value="certloom-certificates" />
		<?php if ( ! empty( $_GET['legacy_expired'] ) ) : ?>
			<input type="hidden" name="legacy_expired" value="1" />
		<?php endif; ?>
		<div class="alignleft actions">
			<label class="screen-reader-text" for="certverify-search-mode"><?php esc_html_e( 'Certificate search matching', 'certloom' ); ?></label>
			<?php $ecapla_certverify_search_mode = isset( $_GET['search_mode'] ) ? sanitize_key( wp_unslash( $_GET['search_mode'] ) ) : 'prefix'; ?>
			<select id="certverify-search-mode" name="search_mode">
				<option value="prefix" <?php selected( $ecapla_certverify_search_mode, 'prefix' ); ?>><?php esc_html_e( 'Starts with', 'certloom' ); ?></option>
				<option value="exact" <?php selected( $ecapla_certverify_search_mode, 'exact' ); ?>><?php esc_html_e( 'Exact match', 'certloom' ); ?></option>
				<option value="contains" <?php selected( $ecapla_certverify_search_mode, 'contains' ); ?>><?php esc_html_e( 'Contains (slower)', 'certloom' ); ?></option>
			</select>
			<label class="screen-reader-text" for="filter-by-certificate-status"><?php esc_html_e( 'Filter by certificate status', 'certloom' ); ?></label>
			<select id="filter-by-certificate-status" name="status">
				<option value=""><?php esc_html_e( 'All statuses', 'certloom' ); ?></option>
				<?php $ecapla_certverify_current_status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : ''; ?>
				<?php foreach ( Ecapla_CertVerify_Fields::get_statuses() as $ecapla_certverify_status_key => $ecapla_certverify_status ) : ?>
					<option value="<?php echo esc_attr( $ecapla_certverify_status_key ); ?>" <?php selected( $ecapla_certverify_current_status, $ecapla_certverify_status_key ); ?>><?php echo esc_html( $ecapla_certverify_status['label'] ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php submit_button( __( 'Filter', 'certloom' ), '', 'filter_action', false ); ?>
		</div>
		<?php $table->search_box( __( 'Search certificates', 'certloom' ), 'certloom' ); ?>
	</form>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
		<input type="hidden" name="page" value="certloom-certificates" />
		<?php // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only list state is carried through only for the post-delete redirect. ?>
		<?php if ( ! empty( $_GET['legacy_expired'] ) ) : ?><input type="hidden" name="legacy_expired" value="1" /><?php endif; ?>
		<?php if ( isset( $_GET['status'] ) && '' !== $_GET['status'] ) : ?><input type="hidden" name="status" value="<?php echo esc_attr( sanitize_key( wp_unslash( $_GET['status'] ) ) ); ?>" /><?php endif; ?>
		<?php if ( isset( $_GET['search_mode'] ) ) : ?><input type="hidden" name="search_mode" value="<?php echo esc_attr( sanitize_key( wp_unslash( $_GET['search_mode'] ) ) ); ?>" /><?php endif; ?>
		<?php if ( isset( $_GET['s'] ) && '' !== $_GET['s'] ) : ?><input type="hidden" name="s" value="<?php echo esc_attr( sanitize_text_field( wp_unslash( $_GET['s'] ) ) ); ?>" /><?php endif; ?>
		<?php if ( ! empty( $_GET['paged'] ) ) : ?><input type="hidden" name="paged" value="<?php echo absint( $_GET['paged'] ); ?>" /><?php endif; ?>
		<?php if ( isset( $_GET['orderby'] ) ) : ?><input type="hidden" name="orderby" value="<?php echo esc_attr( sanitize_key( wp_unslash( $_GET['orderby'] ) ) ); ?>" /><?php endif; ?>
		<?php if ( isset( $_GET['order'] ) ) : ?><input type="hidden" name="order" value="<?php echo esc_attr( strtoupper( sanitize_key( wp_unslash( $_GET['order'] ) ) ) ); ?>" /><?php endif; ?>
		<?php // phpcs:enable WordPress.Security.NonceVerification.Recommended ?>
		<?php $table->display(); ?>
	</form>
	<form id="ecapla-certverify-delete-certificate-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" hidden>
		<input type="hidden" name="action" value="ecapla_certverify_delete_certificate" />
		<?php // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only list state carried through so the delete redirect returns to the same view. ?>
		<?php if ( ! empty( $_GET['legacy_expired'] ) ) : ?><input type="hidden" name="legacy_expired" value="1" /><?php endif; ?>
		<?php if ( isset( $_GET['status'] ) && '' !== $_GET['status'] ) : ?><input type="hidden" name="status" value="<?php echo esc_attr( sanitize_key( wp_unslash( $_GET['status'] ) ) ); ?>" /><?php endif; ?>
		<?php if ( isset( $_GET['search_mode'] ) ) : ?><input type="hidden" name="search_mode" value="<?php echo esc_attr( sanitize_key( wp_unslash( $_GET['search_mode'] ) ) ); ?>" /><?php endif; ?>
		<?php if ( isset( $_GET['s'] ) && '' !== $_GET['s'] ) : ?><input type="hidden" name="s" value="<?php echo esc_attr( sanitize_text_field( wp_unslash( $_GET['s'] ) ) ); ?>" /><?php endif; ?>
		<?php if ( ! empty( $_GET['paged'] ) ) : ?><input type="hidden" name="paged" value="<?php echo absint( $_GET['paged'] ); ?>" /><?php endif; ?>
		<?php if ( isset( $_GET['orderby'] ) ) : ?><input type="hidden" name="orderby" value="<?php echo esc_attr( sanitize_key( wp_unslash( $_GET['orderby'] ) ) ); ?>" /><?php endif; ?>
		<?php if ( isset( $_GET['order'] ) ) : ?><input type="hidden" name="order" value="<?php echo esc_attr( strtoupper( sanitize_key( wp_unslash( $_GET['order'] ) ) ) ); ?>" /><?php endif; ?>
		<?php // phpcs:enable WordPress.Security.NonceVerification.Recommended ?>
		<?php wp_nonce_field( 'ecapla_certverify_delete_certificate' ); ?>
	</form>
</div>
