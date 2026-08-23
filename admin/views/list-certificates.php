<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are local to the rendering method that includes this file.
// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Templates only read display state; all mutations are handled by nonce-protected controllers.
$ecapla_certverify_search_mode    = isset( $_GET['search_mode'] ) ? sanitize_key( wp_unslash( $_GET['search_mode'] ) ) : 'prefix';
$ecapla_certverify_current_status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
$ecapla_certverify_attention      = isset( $_GET['attention'] ) ? sanitize_key( wp_unslash( $_GET['attention'] ) ) : '';
$ecapla_certverify_search         = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
$ecapla_certverify_issue_from     = isset( $_GET['issue_from'] ) ? sanitize_text_field( wp_unslash( $_GET['issue_from'] ) ) : '';
$ecapla_certverify_issue_to       = isset( $_GET['issue_to'] ) ? sanitize_text_field( wp_unslash( $_GET['issue_to'] ) ) : '';
$ecapla_certverify_expiry_from    = isset( $_GET['expiry_from'] ) ? sanitize_text_field( wp_unslash( $_GET['expiry_from'] ) ) : '';
$ecapla_certverify_expiry_to      = isset( $_GET['expiry_to'] ) ? sanitize_text_field( wp_unslash( $_GET['expiry_to'] ) ) : '';
$ecapla_certverify_filters_active = '' !== $ecapla_certverify_search || '' !== $ecapla_certverify_current_status || '' !== $ecapla_certverify_attention || '' !== $ecapla_certverify_issue_from || '' !== $ecapla_certverify_issue_to || '' !== $ecapla_certverify_expiry_from || '' !== $ecapla_certverify_expiry_to || ! empty( $_GET['legacy_expired'] );
$ecapla_certverify_status_map      = Ecapla_CertVerify_Fields::get_statuses();
$ecapla_certverify_status_label    = isset( $ecapla_certverify_status_map[ $ecapla_certverify_current_status ]['label'] ) ? $ecapla_certverify_status_map[ $ecapla_certverify_current_status ]['label'] : $ecapla_certverify_current_status;
$ecapla_certverify_hidden_columns  = get_user_meta( get_current_user_id(), 'certloom_registry_hidden_columns', true );
$ecapla_certverify_hidden_columns  = is_array( $ecapla_certverify_hidden_columns ) ? $ecapla_certverify_hidden_columns : array();
?>
<div class="wrap ecapla-certverify-admin-wrap certloom-admin-shell certloom-registry-screen">
	<section class="certloom-page-hero" aria-labelledby="certloom-registry-title">
		<div>
			<span class="certloom-eyebrow"><?php esc_html_e( 'Certificate registry', 'certloom' ); ?></span>
			<h1 id="certloom-registry-title"><?php esc_html_e( 'Certificates', 'certloom' ); ?></h1>
			<p><?php esc_html_e( 'Search, review and maintain the authoritative certificate registry without changing verification truth.', 'certloom' ); ?></p>
		</div>
		<div class="certloom-page-actions">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=certloom-add' ) ); ?>" class="button button-primary button-hero"><span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span><?php esc_html_e( 'Add certificate', 'certloom' ); ?></a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=certloom-import' ) ); ?>" class="button button-secondary button-hero"><span class="dashicons dashicons-database-import" aria-hidden="true"></span><?php esc_html_e( 'Import / export', 'certloom' ); ?></a>
		</div>
	</section>

	<?php if ( isset( $database_error ) && is_wp_error( $database_error ) ) : ?>
		<div class="notice notice-error inline"><p><?php echo esc_html( $database_error->get_error_message() ); ?></p></div>
	<?php endif; ?>

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
	<?php if ( isset( $_GET['ecapla_certverify_columns_saved'] ) ) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Registry column preferences saved.', 'certloom' ); ?></p></div><?php endif; ?>
	<?php if ( isset( $_GET['ecapla_certverify_bulk_changed'] ) ) : ?>
		<?php $ecapla_certverify_bulk_changed = absint( $_GET['ecapla_certverify_bulk_changed'] ); $ecapla_certverify_bulk_failed = isset( $_GET['ecapla_certverify_bulk_failed'] ) ? absint( $_GET['ecapla_certverify_bulk_failed'] ) : 0; $ecapla_certverify_bulk_skipped = isset( $_GET['ecapla_certverify_bulk_skipped'] ) ? absint( $_GET['ecapla_certverify_bulk_skipped'] ) : 0; ?>
		<div class="notice <?php echo $ecapla_certverify_bulk_failed ? 'notice-warning' : 'notice-success'; ?> is-dismissible"><p>
			<?php echo esc_html( sprintf( __( 'Bulk change complete: %1$d changed, %2$d skipped during review, %3$d failed during commit.', 'certloom' ), $ecapla_certverify_bulk_changed, $ecapla_certverify_bulk_skipped, $ecapla_certverify_bulk_failed ) ); ?>
		</p></div>
	<?php endif; ?>
	<?php if ( isset( $_GET['ecapla_certverify_error'] ) ) : ?>
		<div class="notice notice-error"><p><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['ecapla_certverify_error'] ) ) ); ?></p></div>
	<?php endif; ?>

	<?php if ( ! empty( $_GET['legacy_expired'] ) ) : ?>
		<div class="certloom-callout certloom-callout-warning" role="status">
			<span class="dashicons dashicons-warning" aria-hidden="true"></span>
			<div>
				<strong><?php esc_html_e( 'Legacy Expired records need explicit review', 'certloom' ); ?></strong>
				<p><?php esc_html_e( 'These records remain Expired until an administrator deliberately chooses a current workflow status and saves the certificate.', 'certloom' ); ?></p>
			</div>
			<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=certloom-certificates' ) ); ?>"><?php esc_html_e( 'Show all', 'certloom' ); ?></a>
		</div>
	<?php endif; ?>

	<section class="certloom-panel certloom-registry-panel" aria-labelledby="certloom-registry-table-title">
		<div class="certloom-panel-header certloom-registry-header">
			<div>
				<span class="certloom-eyebrow"><?php esc_html_e( 'Authoritative records', 'certloom' ); ?></span>
				<h2 id="certloom-registry-table-title"><?php esc_html_e( 'Registry', 'certloom' ); ?></h2>
			</div>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=certloom-fields' ) ); ?>" class="certloom-inline-link"><span class="dashicons dashicons-editor-table" aria-hidden="true"></span><?php esc_html_e( 'Manage fields', 'certloom' ); ?></a>
		</div>

		<nav class="certloom-registry-views" aria-label="<?php esc_attr_e( 'Certificate status views', 'certloom' ); ?>">
			<?php $table->views(); ?>
		</nav>

		<details class="certloom-column-preferences">
			<summary><span class="dashicons dashicons-columns" aria-hidden="true"></span><?php esc_html_e( 'Columns', 'certloom' ); ?></summary>
			<form method="post" action="" class="certloom-column-preferences-form">
				<?php wp_nonce_field( 'certverify_registry_columns_save' ); ?>
				<input type="hidden" name="page" value="certloom-certificates" />
				<?php if ( ! empty( $_GET['legacy_expired'] ) ) : ?><input type="hidden" name="legacy_expired" value="1" /><?php endif; ?>
				<?php if ( '' !== $ecapla_certverify_current_status ) : ?><input type="hidden" name="status" value="<?php echo esc_attr( $ecapla_certverify_current_status ); ?>" /><?php endif; ?>
				<?php if ( '' !== $ecapla_certverify_attention ) : ?><input type="hidden" name="attention" value="<?php echo esc_attr( $ecapla_certverify_attention ); ?>" /><?php endif; ?>
				<?php if ( '' !== $ecapla_certverify_search_mode ) : ?><input type="hidden" name="search_mode" value="<?php echo esc_attr( $ecapla_certverify_search_mode ); ?>" /><?php endif; ?>
				<?php if ( '' !== $ecapla_certverify_search ) : ?><input type="hidden" name="s" value="<?php echo esc_attr( $ecapla_certverify_search ); ?>" /><?php endif; ?>
				<?php if ( '' !== $ecapla_certverify_issue_from ) : ?><input type="hidden" name="issue_from" value="<?php echo esc_attr( $ecapla_certverify_issue_from ); ?>" /><?php endif; ?>
				<?php if ( '' !== $ecapla_certverify_issue_to ) : ?><input type="hidden" name="issue_to" value="<?php echo esc_attr( $ecapla_certverify_issue_to ); ?>" /><?php endif; ?>
				<?php if ( '' !== $ecapla_certverify_expiry_from ) : ?><input type="hidden" name="expiry_from" value="<?php echo esc_attr( $ecapla_certverify_expiry_from ); ?>" /><?php endif; ?>
				<?php if ( '' !== $ecapla_certverify_expiry_to ) : ?><input type="hidden" name="expiry_to" value="<?php echo esc_attr( $ecapla_certverify_expiry_to ); ?>" /><?php endif; ?>
				<?php if ( ! empty( $_GET['paged'] ) ) : ?><input type="hidden" name="paged" value="<?php echo absint( $_GET['paged'] ); ?>" /><?php endif; ?>
				<?php if ( isset( $_GET['orderby'] ) ) : ?><input type="hidden" name="orderby" value="<?php echo esc_attr( sanitize_key( wp_unslash( $_GET['orderby'] ) ) ); ?>" /><?php endif; ?>
				<?php if ( isset( $_GET['order'] ) ) : ?><input type="hidden" name="order" value="<?php echo esc_attr( strtoupper( sanitize_key( wp_unslash( $_GET['order'] ) ) ) ); ?>" /><?php endif; ?>
				<p class="description"><?php esc_html_e( 'Certificate number always remains visible. These preferences apply only to your WordPress user.', 'certloom' ); ?></p>
				<div class="certloom-column-options">
				<?php foreach ( array( 'holder_name' => __( 'Holder', 'certloom' ), 'status' => __( 'Status', 'certloom' ), 'issue_date' => __( 'Issue date', 'certloom' ), 'expiry_date' => __( 'Expiry date', 'certloom' ) ) as $column_key => $column_label ) : ?>
					<label><input type="checkbox" name="visible_columns[<?php echo esc_attr( $column_key ); ?>]" value="1" <?php checked( ! in_array( $column_key, $ecapla_certverify_hidden_columns, true ) ); ?> /> <?php echo esc_html( $column_label ); ?></label>
				<?php endforeach; ?>
				</div>
				<?php submit_button( __( 'Save columns', 'certloom' ), 'secondary', 'certverify_registry_columns_save', false ); ?>
			</form>
		</details>

		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="ecapla-certverify-list-filters certloom-filter-bar">
			<input type="hidden" name="page" value="certloom-certificates" />
			<?php if ( ! empty( $_GET['legacy_expired'] ) ) : ?>
				<input type="hidden" name="legacy_expired" value="1" />
			<?php endif; ?>
			<div class="certloom-filter-fields">
				<div class="certloom-filter-field certloom-filter-search">
					<label for="certloom-registry-search"><?php esc_html_e( 'Search', 'certloom' ); ?></label>
					<input type="search" id="certloom-registry-search" name="s" value="<?php echo esc_attr( $ecapla_certverify_search ); ?>" placeholder="<?php esc_attr_e( 'Certificate number or holder', 'certloom' ); ?>" />
				</div>
				<div class="certloom-filter-field">
					<label for="certverify-search-mode"><?php esc_html_e( 'Match', 'certloom' ); ?></label>
					<select id="certverify-search-mode" name="search_mode">
						<option value="prefix" <?php selected( $ecapla_certverify_search_mode, 'prefix' ); ?>><?php esc_html_e( 'Starts with', 'certloom' ); ?></option>
						<option value="exact" <?php selected( $ecapla_certverify_search_mode, 'exact' ); ?>><?php esc_html_e( 'Exact match', 'certloom' ); ?></option>
						<option value="contains" <?php selected( $ecapla_certverify_search_mode, 'contains' ); ?>><?php esc_html_e( 'Contains (slower)', 'certloom' ); ?></option>
					</select>
				</div>
				<div class="certloom-filter-field">
					<label for="filter-by-certificate-status"><?php esc_html_e( 'Status', 'certloom' ); ?></label>
					<select id="filter-by-certificate-status" name="status">
						<option value=""><?php esc_html_e( 'All statuses', 'certloom' ); ?></option>
						<?php foreach ( $ecapla_certverify_status_map as $ecapla_certverify_status_key => $ecapla_certverify_status ) : ?>
							<option value="<?php echo esc_attr( $ecapla_certverify_status_key ); ?>" <?php selected( $ecapla_certverify_current_status, $ecapla_certverify_status_key ); ?>><?php echo esc_html( $ecapla_certverify_status['label'] ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="certloom-filter-field">
					<label for="certloom-attention-filter"><?php esc_html_e( 'Attention', 'certloom' ); ?></label>
					<select id="certloom-attention-filter" name="attention">
						<option value=""><?php esc_html_e( 'Any', 'certloom' ); ?></option>
						<option value="expiring_soon" <?php selected( $ecapla_certverify_attention, 'expiring_soon' ); ?>><?php esc_html_e( 'Expiring in 30 days', 'certloom' ); ?></option>
					</select>
				</div>
			</div>
			<div class="certloom-date-filter-grid" aria-label="<?php esc_attr_e( 'Date filters', 'certloom' ); ?>">
				<div class="certloom-filter-field"><label for="certloom-issue-from"><?php esc_html_e( 'Issued from', 'certloom' ); ?></label><input id="certloom-issue-from" type="date" name="issue_from" value="<?php echo esc_attr( $ecapla_certverify_issue_from ); ?>" /></div>
				<div class="certloom-filter-field"><label for="certloom-issue-to"><?php esc_html_e( 'Issued to', 'certloom' ); ?></label><input id="certloom-issue-to" type="date" name="issue_to" value="<?php echo esc_attr( $ecapla_certverify_issue_to ); ?>" /></div>
				<div class="certloom-filter-field"><label for="certloom-expiry-from"><?php esc_html_e( 'Expires from', 'certloom' ); ?></label><input id="certloom-expiry-from" type="date" name="expiry_from" value="<?php echo esc_attr( $ecapla_certverify_expiry_from ); ?>" /></div>
				<div class="certloom-filter-field"><label for="certloom-expiry-to"><?php esc_html_e( 'Expires to', 'certloom' ); ?></label><input id="certloom-expiry-to" type="date" name="expiry_to" value="<?php echo esc_attr( $ecapla_certverify_expiry_to ); ?>" /></div>
			</div>
			<div class="certloom-filter-actions">
				<?php submit_button( __( 'Apply filters', 'certloom' ), 'secondary', 'filter_action', false ); ?>
				<?php if ( $ecapla_certverify_filters_active ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=certloom-certificates' ) ); ?>"><?php esc_html_e( 'Clear filters', 'certloom' ); ?></a>
				<?php endif; ?>
			</div>
		</form>

		<?php if ( $ecapla_certverify_filters_active ) : ?>
			<div class="certloom-active-filters" role="status" aria-live="polite">
				<span class="dashicons dashicons-filter" aria-hidden="true"></span>
				<strong><?php esc_html_e( 'Filtered registry', 'certloom' ); ?></strong>
				<?php if ( '' !== $ecapla_certverify_search ) : ?><span><?php echo esc_html( sprintf( __( 'Search: %s', 'certloom' ), $ecapla_certverify_search ) ); ?></span><?php endif; ?>
				<?php if ( '' !== $ecapla_certverify_current_status ) : ?><span><?php echo esc_html( sprintf( __( 'Status: %s', 'certloom' ), $ecapla_certverify_status_label ) ); ?></span><?php endif; ?>
				<?php if ( 'expiring_soon' === $ecapla_certverify_attention ) : ?><span><?php esc_html_e( 'Expiring in 30 days', 'certloom' ); ?></span><?php endif; ?>
				<?php if ( '' !== $ecapla_certverify_issue_from || '' !== $ecapla_certverify_issue_to ) : ?><span><?php echo esc_html( sprintf( __( 'Issued: %1$s → %2$s', 'certloom' ), $ecapla_certverify_issue_from ?: '…', $ecapla_certverify_issue_to ?: '…' ) ); ?></span><?php endif; ?>
				<?php if ( '' !== $ecapla_certverify_expiry_from || '' !== $ecapla_certverify_expiry_to ) : ?><span><?php echo esc_html( sprintf( __( 'Expires: %1$s → %2$s', 'certloom' ), $ecapla_certverify_expiry_from ?: '…', $ecapla_certverify_expiry_to ?: '…' ) ); ?></span><?php endif; ?>
			</div>
		<?php endif; ?>

		<div class="certloom-registry-table-wrap">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
				<input type="hidden" name="page" value="certloom-certificates" />
				<?php // Read-only list state is carried through only for the post-delete redirect. ?>
				<?php if ( ! empty( $_GET['legacy_expired'] ) ) : ?><input type="hidden" name="legacy_expired" value="1" /><?php endif; ?>
				<?php if ( '' !== $ecapla_certverify_current_status ) : ?><input type="hidden" name="status" value="<?php echo esc_attr( $ecapla_certverify_current_status ); ?>" /><?php endif; ?>
				<?php if ( '' !== $ecapla_certverify_attention ) : ?><input type="hidden" name="attention" value="<?php echo esc_attr( $ecapla_certverify_attention ); ?>" /><?php endif; ?>
				<?php if ( '' !== $ecapla_certverify_search_mode ) : ?><input type="hidden" name="search_mode" value="<?php echo esc_attr( $ecapla_certverify_search_mode ); ?>" /><?php endif; ?>
				<?php if ( '' !== $ecapla_certverify_search ) : ?><input type="hidden" name="s" value="<?php echo esc_attr( $ecapla_certverify_search ); ?>" /><?php endif; ?>
				<?php if ( '' !== $ecapla_certverify_issue_from ) : ?><input type="hidden" name="issue_from" value="<?php echo esc_attr( $ecapla_certverify_issue_from ); ?>" /><?php endif; ?>
				<?php if ( '' !== $ecapla_certverify_issue_to ) : ?><input type="hidden" name="issue_to" value="<?php echo esc_attr( $ecapla_certverify_issue_to ); ?>" /><?php endif; ?>
				<?php if ( '' !== $ecapla_certverify_expiry_from ) : ?><input type="hidden" name="expiry_from" value="<?php echo esc_attr( $ecapla_certverify_expiry_from ); ?>" /><?php endif; ?>
				<?php if ( '' !== $ecapla_certverify_expiry_to ) : ?><input type="hidden" name="expiry_to" value="<?php echo esc_attr( $ecapla_certverify_expiry_to ); ?>" /><?php endif; ?>
				<?php if ( ! empty( $_GET['paged'] ) ) : ?><input type="hidden" name="paged" value="<?php echo absint( $_GET['paged'] ); ?>" /><?php endif; ?>
				<?php if ( isset( $_GET['orderby'] ) ) : ?><input type="hidden" name="orderby" value="<?php echo esc_attr( sanitize_key( wp_unslash( $_GET['orderby'] ) ) ); ?>" /><?php endif; ?>
				<?php if ( isset( $_GET['order'] ) ) : ?><input type="hidden" name="order" value="<?php echo esc_attr( strtoupper( sanitize_key( wp_unslash( $_GET['order'] ) ) ) ); ?>" /><?php endif; ?>
				<?php $table->display(); ?>
			</form>
		</div>
	</section>

	<form id="ecapla-certverify-delete-certificate-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" hidden>
		<input type="hidden" name="action" value="ecapla_certverify_delete_certificate" />
		<?php if ( ! empty( $_GET['legacy_expired'] ) ) : ?><input type="hidden" name="legacy_expired" value="1" /><?php endif; ?>
		<?php if ( '' !== $ecapla_certverify_current_status ) : ?><input type="hidden" name="status" value="<?php echo esc_attr( $ecapla_certverify_current_status ); ?>" /><?php endif; ?>
		<?php if ( '' !== $ecapla_certverify_attention ) : ?><input type="hidden" name="attention" value="<?php echo esc_attr( $ecapla_certverify_attention ); ?>" /><?php endif; ?>
		<?php if ( '' !== $ecapla_certverify_search_mode ) : ?><input type="hidden" name="search_mode" value="<?php echo esc_attr( $ecapla_certverify_search_mode ); ?>" /><?php endif; ?>
		<?php if ( '' !== $ecapla_certverify_search ) : ?><input type="hidden" name="s" value="<?php echo esc_attr( $ecapla_certverify_search ); ?>" /><?php endif; ?>
		<?php if ( '' !== $ecapla_certverify_issue_from ) : ?><input type="hidden" name="issue_from" value="<?php echo esc_attr( $ecapla_certverify_issue_from ); ?>" /><?php endif; ?>
		<?php if ( '' !== $ecapla_certverify_issue_to ) : ?><input type="hidden" name="issue_to" value="<?php echo esc_attr( $ecapla_certverify_issue_to ); ?>" /><?php endif; ?>
		<?php if ( '' !== $ecapla_certverify_expiry_from ) : ?><input type="hidden" name="expiry_from" value="<?php echo esc_attr( $ecapla_certverify_expiry_from ); ?>" /><?php endif; ?>
		<?php if ( '' !== $ecapla_certverify_expiry_to ) : ?><input type="hidden" name="expiry_to" value="<?php echo esc_attr( $ecapla_certverify_expiry_to ); ?>" /><?php endif; ?>
		<?php if ( ! empty( $_GET['paged'] ) ) : ?><input type="hidden" name="paged" value="<?php echo absint( $_GET['paged'] ); ?>" /><?php endif; ?>
		<?php if ( isset( $_GET['orderby'] ) ) : ?><input type="hidden" name="orderby" value="<?php echo esc_attr( sanitize_key( wp_unslash( $_GET['orderby'] ) ) ); ?>" /><?php endif; ?>
		<?php if ( isset( $_GET['order'] ) ) : ?><input type="hidden" name="order" value="<?php echo esc_attr( strtoupper( sanitize_key( wp_unslash( $_GET['order'] ) ) ) ); ?>" /><?php endif; ?>
		<?php wp_nonce_field( 'ecapla_certverify_delete_certificate' ); ?>
	</form>
</div>
