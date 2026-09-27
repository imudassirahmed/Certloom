<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
$view = isset( $view ) ? $view : 'library';
$base_url = add_query_arg( array( 'page' => \Certloom\Infrastructure\Admin\EmailDesignAdmin::PAGE ), admin_url( 'admin.php' ) );
$compiler = new \Certloom\Infrastructure\Email\EmailDesignCompiler();
$sample = array(
	'{{recipient_name}}' => __( 'Sara Khan', 'certloom' ),
	'{{program_title}}' => __( 'Professional Leadership Program', 'certloom' ),
	'{{issuer_name}}' => __( 'Example Institute', 'certloom' ),
	'{{credential_number}}' => 'CL-2026-1042',
	'{{issue_date}}' => '4 Sep 2026',
	'{{expiry_date}}' => '4 Sep 2028',
	'{{grade}}' => __( 'Distinction', 'certloom' ),
	'{{verification_url}}' => '#',
	'{{verification_link}}' => __( 'Verify this credential', 'certloom' ),
);
$render_preview = static function( array $design ) use ( $compiler, $sample ) {
	$compiled = $compiler->compile( $design['blocks'] ?? array(), $design['theme'] ?? array() );
	return \Certloom\Infrastructure\Email\EmailDesignCompiler::sanitize_inline_html( strtr( (string) ( $compiled['html_body'] ?? '' ), $sample ) );
};
$purpose_labels = isset( $purposes ) && is_array( $purposes ) ? $purposes : array();
?>
<div class="wrap ecapla-certverify-admin-wrap certloom-admin-shell certloom-email-designs">
	<header class="certloom-email-hero">
		<div>
			<p class="certloom-eyebrow"><?php esc_html_e( 'Recipient communication', 'certloom' ); ?></p>
			<h1><?php esc_html_e( 'Email designs', 'certloom' ); ?></h1>
			<p class="certloom-email-lede"><?php esc_html_e( 'Create polished credential messages here. Where a design is used is managed separately in Design Rules.', 'certloom' ); ?></p>
		</div>
		<div class="certloom-email-hero__actions">
			<button type="button" class="button" data-certloom-email-import-open><?php esc_html_e( 'Import', 'certloom' ); ?></button>
			<a class="button button-primary" href="<?php echo esc_url( add_query_arg( array( 'email_view' => 'designer' ), $base_url ) ); ?>"><?php esc_html_e( 'New Template', 'certloom' ); ?></a>
		</div>
	</header>
	<?php ( new \Certloom\Infrastructure\Admin\AdminProductNavigation() )->render_current_subnav(); ?>

	<nav class="certloom-email-tabs" aria-label="<?php esc_attr_e( 'Email design views', 'certloom' ); ?>">
		<a class="<?php echo 'library' === $view ? 'is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'email_view'=>'library' ), $base_url ) ); ?>"><?php esc_html_e( 'Library', 'certloom' ); ?></a>
		<a class="<?php echo 'mine' === $view ? 'is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'email_view'=>'mine' ), $base_url ) ); ?>"><?php esc_html_e( 'My Templates', 'certloom' ); ?></a>
	</nav>

	<?php if ( ! empty( $notice ) ) : ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div><?php endif; ?>
	<?php if ( ! empty( $error ) ) : ?><div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div><?php endif; ?>
	<?php if ( isset( $seed ) && is_wp_error( $seed ) ) : ?><div class="notice notice-warning"><p><?php esc_html_e( 'The built-in email library could not be repaired automatically. Existing email designs are unchanged.', 'certloom' ); ?></p></div><?php endif; ?>

	<form method="get" class="certloom-email-filters">
		<input type="hidden" name="page" value="<?php echo esc_attr( \Certloom\Infrastructure\Admin\EmailDesignAdmin::PAGE ); ?>">
		<input type="hidden" name="email_view" value="<?php echo esc_attr( $view ); ?>">
		<label><span><?php esc_html_e( 'Search', 'certloom' ); ?></span><input type="search" name="email_s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search email designs', 'certloom' ); ?>"></label>
		<label><span><?php esc_html_e( 'Purpose', 'certloom' ); ?></span><select name="email_purpose"><option value=""><?php esc_html_e( 'All purposes', 'certloom' ); ?></option><?php foreach ( $purpose_labels as $key=>$label ) : ?><option value="<?php echo esc_attr($key); ?>" <?php selected($purpose_filter,$key); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select></label>
		<label><span><?php esc_html_e( 'Status', 'certloom' ); ?></span><select name="email_status"><option value="all" <?php selected($status_filter,'all'); ?>><?php esc_html_e('All statuses','certloom'); ?></option><option value="published" <?php selected($status_filter,'published'); ?>><?php esc_html_e('Published','certloom'); ?></option><option value="unpublished" <?php selected($status_filter,'unpublished'); ?>><?php esc_html_e('Unpublished','certloom'); ?></option></select></label>
		<button class="button"><?php esc_html_e( 'Filter', 'certloom' ); ?></button>
	</form>

	<?php if ( empty( $designs ) ) : ?>
		<section class="certloom-email-empty"><h2><?php echo 'library' === $view ? esc_html__( 'No built-in designs match these filters.', 'certloom' ) : esc_html__( 'Your template library is ready.', 'certloom' ); ?></h2><p><?php echo 'library' === $view ? esc_html__( 'Clear the filters to see the full Certloom email library.', 'certloom' ) : esc_html__( 'Create a template from scratch, customize a built-in design, or import a Certloom email design package.', 'certloom' ); ?></p><?php if ( 'mine' === $view ) : ?><a class="button button-primary" href="<?php echo esc_url( add_query_arg( array( 'email_view'=>'designer' ), $base_url ) ); ?>"><?php esc_html_e('New Template','certloom'); ?></a><?php endif; ?></section>
	<?php else : ?>
		<div class="certloom-email-grid">
		<?php foreach ( $designs as $design ) : $id=(string)($design['id']??''); $published=!empty($design['active']); $factory='factory'===(string)($design['source']??''); ?>
			<article class="certloom-email-card">
				<button type="button" class="certloom-email-card__preview" data-certloom-email-preview-open data-title="<?php echo esc_attr((string)($design['name']??'')); ?>" data-preview-id="email-preview-<?php echo esc_attr($id); ?>" aria-label="<?php echo esc_attr(sprintf( /* translators: %s: email design name. */ __('Preview %s','certloom'),(string)($design['name']??''))); ?>">
					<span class="certloom-email-card__frame"><?php echo wp_kses_post( $render_preview( $design ) ); ?></span>
				</button>
				<div class="certloom-email-card__body">
					<div class="certloom-email-card__meta"><span class="certloom-status <?php echo $published?'is-published':'is-unpublished'; ?>"><?php echo $published?esc_html__('Published','certloom'):esc_html__('Unpublished','certloom'); ?></span><span><?php echo esc_html($purpose_labels[(string)($design['purpose']??'')]??(string)($design['purpose']??'')); ?></span></div>
					<h2><?php echo esc_html((string)($design['name']??'')); ?></h2>
					<p class="certloom-email-card__source"><?php echo $factory?esc_html__('Built-in Certloom design','certloom'):esc_html__('Your template','certloom'); ?> · <?php echo esc_html(sprintf( /* translators: %d: number of direct design rules. */ _n('%d rule','%d rules',absint($design['usage_count']??0),'certloom'),absint($design['usage_count']??0))); ?></p>
					<div class="certloom-email-card__actions">
						<button type="button" class="button" data-certloom-email-preview-open data-title="<?php echo esc_attr((string)($design['name']??'')); ?>" data-preview-id="email-preview-<?php echo esc_attr($id); ?>"><?php esc_html_e('Preview','certloom'); ?></button>
						<?php if ( $factory ) : ?>
						<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="certloom_email_design_duplicate"><input type="hidden" name="design_id" value="<?php echo esc_attr($id); ?>"><?php wp_nonce_field('certloom_email_design_duplicate_'.$id); ?><button class="button button-primary"><?php esc_html_e('Customize','certloom'); ?></button></form>
						<?php else : ?><a class="button button-primary" href="<?php echo esc_url(add_query_arg(array('email_view'=>'designer','design_id'=>$id),$base_url)); ?>"><?php esc_html_e('Edit','certloom'); ?></a><?php endif; ?>
						<details class="certloom-email-more"><summary class="button" aria-label="<?php esc_attr_e('More actions','certloom'); ?>">•••</summary><div class="certloom-email-more__menu">
							<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="certloom_email_design_publish"><input type="hidden" name="design_id" value="<?php echo esc_attr($id); ?>"><input type="hidden" name="published" value="<?php echo $published?'0':'1'; ?>"><input type="hidden" name="return_view" value="<?php echo esc_attr($view); ?>"><?php wp_nonce_field('certloom_email_design_publish_'.$id); ?><button><?php echo $published?esc_html__('Unpublish','certloom'):esc_html__('Publish','certloom'); ?></button></form>
							<a href="<?php echo esc_url(wp_nonce_url(add_query_arg(array('action'=>'certloom_email_design_export','design_id'=>$id),admin_url('admin-post.php')),'certloom_email_design_export_'.$id)); ?>"><?php esc_html_e('Export','certloom'); ?></a>
							<a href="<?php echo esc_url(add_query_arg(array('page'=>\Certloom\Infrastructure\Admin\DesignRulesAdmin::PAGE,'design_type'=>'email','design_id'=>$id),admin_url('admin.php'))); ?>"><?php esc_html_e('View usage','certloom'); ?></a>
							<?php if ( !$factory ) : ?><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="certloom_email_design_duplicate"><input type="hidden" name="design_id" value="<?php echo esc_attr($id); ?>"><?php wp_nonce_field('certloom_email_design_duplicate_'.$id); ?><button><?php esc_html_e('Duplicate','certloom'); ?></button></form><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('<?php echo esc_js(__('Delete this email design?','certloom')); ?>');"><input type="hidden" name="action" value="certloom_email_design_delete"><input type="hidden" name="design_id" value="<?php echo esc_attr($id); ?>"><?php wp_nonce_field('certloom_email_design_delete_'.$id); ?><button class="is-destructive"><?php esc_html_e('Delete','certloom'); ?></button></form><?php endif; ?>
						</div></details>
					</div>
				</div>
				<template id="email-preview-<?php echo esc_attr($id); ?>"><?php echo wp_kses_post($render_preview($design)); ?></template>
			</article>
		<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<dialog class="certloom-email-dialog" data-certloom-email-preview-dialog><div class="certloom-email-dialog__bar"><strong data-certloom-email-preview-title></strong><button type="button" class="button" data-certloom-email-dialog-close><?php esc_html_e('Close','certloom'); ?></button></div><div class="certloom-email-dialog__preview" data-certloom-email-preview-target></div></dialog>
		<dialog class="certloom-email-dialog certloom-email-import" data-certloom-email-import-dialog><form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="certloom_email_design_import"><?php wp_nonce_field('certloom_email_design_import'); ?><div class="certloom-email-dialog__bar"><div><p class="certloom-eyebrow"><?php esc_html_e('Portable design package','certloom'); ?></p><h2><?php esc_html_e('Import email design','certloom'); ?></h2></div><button type="button" class="button" data-certloom-email-dialog-close><?php esc_html_e('Cancel','certloom'); ?></button></div><label class="certloom-email-drop"><strong><?php esc_html_e('Choose a Certloom email design package','certloom'); ?></strong><span><?php echo esc_html( sprintf( /* translators: %s: maximum upload size for this site. */ __( 'Imported designs stay unpublished until you review them. Assignments are never imported. Maximum package size on this site: %s.', 'certloom' ), (string) ( $package_upload_limit_label ?? '18 MB' ) ) ); ?></span><input type="file" name="design_package" accept="application/json,.json" required></label><div class="certloom-email-dialog__footer"><button class="button button-primary"><?php esc_html_e('Import design','certloom'); ?></button></div></form></dialog>
</div>
