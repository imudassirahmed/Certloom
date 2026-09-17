<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
$base_url = add_query_arg( array( 'page' => \Certloom\Infrastructure\Admin\EmailDesignAdmin::PAGE ), admin_url( 'admin.php' ) );
$blocks = is_array( $editing['blocks'] ?? null ) ? $editing['blocks'] : array();
$theme = is_array( $editing['theme'] ?? null ) ? $editing['theme'] : \Certloom\Infrastructure\Email\EmailDesignCompiler::default_theme();
$is_published = ! empty( $editing['active'] );
$brand_mark = CERTLOOM_URL . 'admin/assets/certloom-brand-mark.png';
?>
<div class="wrap ecapla-certverify-admin-wrap certloom-admin-shell certloom-email-designer-wrap" data-certloom-email-designer-root data-certloom-email-studio>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-certloom-email-designer>
<input type="hidden" name="action" value="certloom_email_design_save"><input type="hidden" name="design_id" value="<?php echo esc_attr( (string) ( $editing['id'] ?? '' ) ); ?>"><input type="hidden" name="name" value="<?php echo esc_attr( (string) ( $editing['name'] ?? '' ) ); ?>" data-certloom-template-name><input type="hidden" name="blocks_json" value="<?php echo esc_attr( wp_json_encode( $blocks ) ); ?>" data-certloom-blocks-json><input type="hidden" name="attachments_json" value="<?php echo esc_attr( wp_json_encode( array_values( (array) ( $editing['attachments'] ?? array() ) ) ) ); ?>" data-certloom-attachments-json><?php wp_nonce_field( 'certloom_email_design_save' ); ?>

<header class="certloom-email-studio-toolbar">
	<div class="certloom-email-studio-toolbar__identity">
		<div class="certloom-email-studio-brand" aria-label="<?php esc_attr_e( 'Certloom Email Studio', 'certloom' ); ?>">
			<img src="<?php echo esc_url( $brand_mark ); ?>" alt="" width="28" height="28">
			<strong><?php esc_html_e( 'Certloom Email Studio', 'certloom' ); ?></strong>
		</div>
		<span class="certloom-email-studio-toolbar__divider" aria-hidden="true"></span>
		<a class="certloom-designer-back" href="<?php echo esc_url( add_query_arg( array( 'email_view' => 'mine' ), $base_url ) ); ?>">← <?php esc_html_e( 'Email designs', 'certloom' ); ?></a>
		<span class="certloom-email-studio-toolbar__divider" aria-hidden="true"></span>
		<div class="certloom-email-studio-document">
			<button type="button" class="certloom-email-document-name" data-certloom-name-edit aria-label="<?php esc_attr_e( 'Rename email design', 'certloom' ); ?>"><strong data-certloom-designer-title><?php echo esc_html( (string) ( $editing['name'] ?? '' ) ); ?></strong></button>
			<input type="text" class="certloom-email-document-name-input" value="<?php echo esc_attr( (string) ( $editing['name'] ?? '' ) ); ?>" maxlength="120" data-certloom-name-input hidden aria-label="<?php esc_attr_e( 'Email design name', 'certloom' ); ?>">
			<span class="certloom-status <?php echo $is_published ? 'is-published' : 'is-unpublished'; ?>"><?php echo $is_published ? esc_html__( 'Published', 'certloom' ) : esc_html__( 'Unpublished', 'certloom' ); ?></span>
			<small data-certloom-save-state><?php esc_html_e( 'Saved', 'certloom' ); ?></small>
		</div>
	</div>
	<div class="certloom-email-studio-toolbar__actions">
		<div class="certloom-view-switch" role="group" aria-label="<?php esc_attr_e( 'Preview size', 'certloom' ); ?>">
			<button type="button" class="is-active" data-certloom-device="desktop" aria-pressed="true"><span aria-hidden="true">▣</span> <?php esc_html_e( 'Desktop', 'certloom' ); ?></button>
			<button type="button" data-certloom-device="mobile" aria-pressed="false"><span aria-hidden="true">▯</span> <?php esc_html_e( 'Mobile', 'certloom' ); ?></button>
		</div>
		<button type="button" class="button certloom-email-studio-action" data-certloom-full-preview><span aria-hidden="true">◉</span> <?php esc_html_e( 'Preview', 'certloom' ); ?></button>
		<button class="button certloom-email-studio-action" type="submit" name="save_intent" value="save"><?php esc_html_e( 'Save', 'certloom' ); ?></button>
		<?php if ( ! $is_published ) : ?>
			<button class="button button-primary certloom-email-studio-action" type="submit" name="save_intent" value="publish"><?php esc_html_e( 'Publish', 'certloom' ); ?></button>
		<?php else : ?>
			<button class="button button-primary certloom-email-studio-action" type="submit" name="save_intent" value="save"><?php esc_html_e( 'Update', 'certloom' ); ?></button>
		<?php endif; ?>
	</div>
</header>

<?php if ( ! empty( $notice ) ) : ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div><?php endif; ?>
<?php if ( ! empty( $error ) ) : ?><div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div><?php endif; ?>

<section class="certloom-message-settings certloom-message-settings--compact" aria-label="<?php esc_attr_e( 'Email message settings', 'certloom' ); ?>">
	<label class="is-purpose"><span><?php esc_html_e( 'Purpose', 'certloom' ); ?></span><select name="purpose" required><?php foreach ( $purposes as $key => $label ) : ?><option value="<?php echo esc_attr( $key ); ?>" <?php selected( (string) ( $editing['purpose'] ?? '' ), $key ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label>
	<label class="is-subject"><span><?php esc_html_e( 'Subject', 'certloom' ); ?></span><input type="text" name="subject" value="<?php echo esc_attr( (string) ( $editing['subject'] ?? '' ) ); ?>" required data-certloom-token-input></label>
	<label class="is-preheader"><span><?php esc_html_e( 'Preheader', 'certloom' ); ?></span><input type="text" name="preheader" value="<?php echo esc_attr( (string) ( $editing['preheader'] ?? '' ) ); ?>" data-certloom-token-input></label>
</section>

<div class="certloom-designer-feedback" data-certloom-designer-feedback role="alert" tabindex="-1" hidden></div>

<div class="certloom-designer-shell">
	<aside class="certloom-designer-palette" aria-label="<?php esc_attr_e( 'Email builder tools', 'certloom' ); ?>">
		<div class="certloom-email-sidebar-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Email builder tools', 'certloom' ); ?>">
			<button type="button" role="tab" class="is-active" aria-selected="true" data-certloom-sidebar-tab="insert"><span aria-hidden="true">＋</span><?php esc_html_e( 'Insert', 'certloom' ); ?></button>
			<button type="button" role="tab" aria-selected="false" data-certloom-sidebar-tab="structure"><span aria-hidden="true">▥</span><?php esc_html_e( 'Structure', 'certloom' ); ?></button>
			<button type="button" role="tab" aria-selected="false" data-certloom-sidebar-tab="data"><span aria-hidden="true">◫</span><?php esc_html_e( 'Data', 'certloom' ); ?></button>
			<button type="button" role="tab" aria-selected="false" data-certloom-sidebar-tab="styles"><span aria-hidden="true">◆</span><?php esc_html_e( 'Styles', 'certloom' ); ?></button>
		</div>

		<div class="certloom-email-sidebar-panel is-active" role="tabpanel" data-certloom-sidebar-panel="insert">
			<p class="certloom-eyebrow"><?php esc_html_e( 'Content blocks', 'certloom' ); ?></p>
			<div class="certloom-block-palette">
				<button type="button" data-certloom-add-block="heading"><b aria-hidden="true">H</b><span><?php esc_html_e( 'Heading', 'certloom' ); ?></span></button>
				<button type="button" data-certloom-add-block="text"><b aria-hidden="true">¶</b><span><?php esc_html_e( 'Text', 'certloom' ); ?></span></button>
				<button type="button" data-certloom-add-block="button"><b aria-hidden="true">↗</b><span><?php esc_html_e( 'Button', 'certloom' ); ?></span></button>
				<button type="button" data-certloom-add-block="image"><b aria-hidden="true">▧</b><span><?php esc_html_e( 'Image', 'certloom' ); ?></span></button>
				<button type="button" data-certloom-add-block="divider"><b aria-hidden="true">—</b><span><?php esc_html_e( 'Divider', 'certloom' ); ?></span></button>
				<button type="button" data-certloom-add-block="spacer"><b aria-hidden="true">↕</b><span><?php esc_html_e( 'Spacer', 'certloom' ); ?></span></button>
				<button type="button" data-certloom-add-block="container"><b aria-hidden="true">▥</b><span><?php esc_html_e( 'Container', 'certloom' ); ?></span></button>
				<button type="button" data-certloom-add-block="html"><b aria-hidden="true">&lt;/&gt;</b><span><?php esc_html_e( 'Custom HTML', 'certloom' ); ?></span></button>
			</div>
			<div class="certloom-container-presets" aria-label="<?php esc_attr_e( 'Container layout presets', 'certloom' ); ?>">
				<span><?php esc_html_e( 'Container presets', 'certloom' ); ?></span>
				<div>
					<button type="button" data-certloom-container-preset="single" aria-label="<?php esc_attr_e( 'Single container', 'certloom' ); ?>"><span class="certloom-container-preset__preview"><i></i></span><small>1</small></button>
					<button type="button" data-certloom-container-preset="50-50" aria-label="<?php esc_attr_e( 'Two equal columns', 'certloom' ); ?>"><span class="certloom-container-preset__preview"><i></i><i></i></span><small>50 / 50</small></button>
					<button type="button" data-certloom-container-preset="33-67" aria-label="<?php esc_attr_e( 'One third and two thirds', 'certloom' ); ?>"><span class="certloom-container-preset__preview is-33-67"><i></i><i></i></span><small>33 / 67</small></button>
					<button type="button" data-certloom-container-preset="67-33" aria-label="<?php esc_attr_e( 'Two thirds and one third', 'certloom' ); ?>"><span class="certloom-container-preset__preview is-67-33"><i></i><i></i></span><small>67 / 33</small></button>
					<button type="button" data-certloom-container-preset="thirds" aria-label="<?php esc_attr_e( 'Three equal columns', 'certloom' ); ?>"><span class="certloom-container-preset__preview"><i></i><i></i><i></i></span><small><?php esc_html_e( 'Thirds', 'certloom' ); ?></small></button>
				</div>
			</div>
		</div>

		<div class="certloom-email-sidebar-panel" role="tabpanel" data-certloom-sidebar-panel="structure" hidden>
			<div class="certloom-email-sidebar-heading"><div><p class="certloom-eyebrow"><?php esc_html_e( 'Message', 'certloom' ); ?></p><h2><?php esc_html_e( 'Structure', 'certloom' ); ?></h2></div><span data-certloom-structure-count></span></div>
			<div class="certloom-email-structure-list" data-certloom-structure-list></div>
			<p class="description"><?php esc_html_e( 'Select a block here to edit it on the canvas.', 'certloom' ); ?></p>
		</div>

		<div class="certloom-email-sidebar-panel" role="tabpanel" data-certloom-sidebar-panel="data" hidden>
			<div class="certloom-token-browser">
				<p class="certloom-eyebrow"><?php esc_html_e( 'Dynamic data', 'certloom' ); ?></p>
				<h2><?php esc_html_e( 'Insert data', 'certloom' ); ?></h2>
				<label class="certloom-token-search"><span class="screen-reader-text"><?php esc_html_e( 'Search fields', 'certloom' ); ?></span><input type="search" placeholder="<?php esc_attr_e( 'Search fields…', 'certloom' ); ?>" data-certloom-token-search></label>
				<div data-certloom-token-list></div>
				<p class="description"><?php esc_html_e( 'Click a field first, then insert the data it needs.', 'certloom' ); ?></p>
			</div>
			<section class="certloom-email-attachments" data-certloom-attachments-manager>
				<div class="certloom-email-sidebar-heading"><div><p class="certloom-eyebrow"><?php esc_html_e( 'Delivery', 'certloom' ); ?></p><h2><?php esc_html_e( 'Attachments', 'certloom' ); ?></h2></div><span data-certloom-attachment-count></span></div>
				<div class="certloom-email-attachment-list" data-certloom-attachment-list></div>
				<button type="button" class="button" data-certloom-add-attachments><?php esc_html_e( 'Add attachments', 'certloom' ); ?></button>
				<p class="description"><?php esc_html_e( 'Up to 5 Media Library files. Static attachments stay site-local and are intentionally excluded from portable design exports.', 'certloom' ); ?></p>
			</section>
		</div>

		<div class="certloom-email-sidebar-panel" role="tabpanel" data-certloom-sidebar-panel="styles" hidden>
			<p class="certloom-eyebrow"><?php esc_html_e( 'Global', 'certloom' ); ?></p>
			<h2><?php esc_html_e( 'Email style', 'certloom' ); ?></h2>
			<div class="certloom-theme-grid">
				<?php $colors = array( 'background' => __( 'Page', 'certloom' ), 'content_background' => __( 'Content', 'certloom' ), 'text_color' => __( 'Text', 'certloom' ), 'heading_color' => __( 'Headings', 'certloom' ), 'button_background' => __( 'Button', 'certloom' ), 'button_text' => __( 'Button text', 'certloom' ), 'link_color' => __( 'Links', 'certloom' ) ); foreach ( $colors as $key => $label ) : $color_value = (string) ( $theme[ $key ] ?? '#ffffff' ); ?>
					<label class="certloom-theme-color-field"><span><?php echo esc_html( $label ); ?></span><span class="certloom-theme-color-control"><input type="color" name="theme_<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $color_value ); ?>" data-certloom-theme data-certloom-theme-color="<?php echo esc_attr( $key ); ?>" aria-label="<?php echo esc_attr( $label ); ?>"><input type="text" value="<?php echo esc_attr( strtoupper( $color_value ) ); ?>" maxlength="7" pattern="#[0-9A-Fa-f]{6}" data-certloom-theme-hex="<?php echo esc_attr( $key ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: email color setting label. */ __( '%s hex color', 'certloom' ), $label ) ); ?>"></span></label>
				<?php endforeach; ?>
				<label class="is-wide certloom-font-picker-field"><span><?php esc_html_e( 'Font', 'certloom' ); ?></span><select name="theme_font_family" data-certloom-theme data-certloom-email-font-select>
					<?php
					$current_theme_font = (string) ( $theme['font_family'] ?? '' );
					$font_group = '';
					$font_matched = false;
					foreach ( (array) ( $email_font_definitions ?? array() ) as $font_key => $font_definition ) :
						$next_group = (string) ( $font_definition['group'] ?? __( 'Other', 'certloom' ) );
						if ( $next_group !== $font_group ) :
							if ( '' !== $font_group ) : ?></optgroup><?php endif;
							$font_group = $next_group; ?><optgroup label="<?php echo esc_attr( $font_group ); ?>"><?php
						endif;
						$font_css = (string) ( $font_definition['css'] ?? '' );
						$font_matched = $font_matched || $current_theme_font === $font_css;
						?><option value="<?php echo esc_attr( $font_css ); ?>" data-font-key="<?php echo esc_attr( (string) $font_key ); ?>" data-font-label="<?php echo esc_attr( (string) ( $font_definition['label'] ?? $font_key ) ); ?>" <?php selected( $current_theme_font, $font_css ); ?>><?php echo esc_html( (string) ( $font_definition['label'] ?? $font_key ) ); ?></option><?php
					endforeach;
					if ( '' !== $font_group ) : ?></optgroup><?php endif;
					if ( '' !== $current_theme_font && ! $font_matched ) :
						$font_parts = explode( ',', $current_theme_font );
						$font_label = trim( (string) ( $font_parts[0] ?? $current_theme_font ), " \t\n\r\0\x0B\"'" );
						?><optgroup label="<?php esc_attr_e( 'Local device fonts', 'certloom' ); ?>"><option value="<?php echo esc_attr( $current_theme_font ); ?>" selected data-font-label="<?php echo esc_attr( $font_label ); ?>"><?php echo esc_html( $font_label . ' · ' . __( 'saved local font', 'certloom' ) ); ?></option></optgroup><?php
					endif;
					?>
				</select><small class="certloom-font-availability-note" data-certloom-email-font-note><?php esc_html_e( 'Local/system and site-local custom fonts. Custom web fonts are included only when used; unsupported email clients keep the safe fallback stack.', 'certloom' ); ?></small></label>
				<label><span><?php esc_html_e( 'Width', 'certloom' ); ?></span><input type="number" min="480" max="760" step="10" name="theme_email_width" value="<?php echo esc_attr( absint( $theme['email_width'] ?? 640 ) ); ?>" data-certloom-theme></label>
				<label><span><?php esc_html_e( 'Corners', 'certloom' ); ?></span><input type="number" min="0" max="32" name="theme_border_radius" value="<?php echo esc_attr( absint( $theme['border_radius'] ?? 12 ) ); ?>" data-certloom-theme></label>
			</div>
			<div class="certloom-theme-backgrounds">
				<?php foreach ( array( 'background' => __( 'Page background image', 'certloom' ), 'content_background' => __( 'Content background image', 'certloom' ) ) as $bg_key => $bg_label ) : $image_key = $bg_key . '_image'; ?>
					<div class="certloom-theme-background-card" data-certloom-theme-media-card="<?php echo esc_attr( $image_key ); ?>">
						<div><strong><?php echo esc_html( $bg_label ); ?></strong><small data-certloom-theme-media-name><?php echo ! empty( $theme[ $image_key ] ) ? esc_html( wp_basename( (string) $theme[ $image_key ] ) ) : esc_html__( 'No image', 'certloom' ); ?></small></div>
						<input type="hidden" name="theme_<?php echo esc_attr( $image_key ); ?>" value="<?php echo esc_attr( (string) ( $theme[ $image_key ] ?? '' ) ); ?>" data-certloom-theme data-certloom-theme-media="<?php echo esc_attr( $image_key ); ?>">
						<div class="certloom-theme-background-actions"><button type="button" class="button" data-certloom-choose-theme-media="<?php echo esc_attr( $image_key ); ?>"><?php esc_html_e( 'Choose', 'certloom' ); ?></button><button type="button" class="button" data-certloom-remove-theme-media="<?php echo esc_attr( $image_key ); ?>"><?php esc_html_e( 'Remove', 'certloom' ); ?></button></div>
						<div class="certloom-theme-background-depth">
							<label class="certloom-email-range-field"><span><?php esc_html_e( 'Background color opacity', 'certloom' ); ?></span><span class="certloom-email-range-control"><input type="range" min="0" max="100" step="1" name="theme_<?php echo esc_attr( $bg_key ); ?>_opacity" value="<?php echo esc_attr( absint( $theme[ $bg_key . '_opacity' ] ?? 100 ) ); ?>" data-certloom-theme><output><?php echo esc_html( absint( $theme[ $bg_key . '_opacity' ] ?? 100 ) ); ?>%</output></span></label>
							<label class="certloom-theme-color-field"><span><?php esc_html_e( 'Image overlay', 'certloom' ); ?></span><span class="certloom-theme-color-control"><input type="color" name="theme_<?php echo esc_attr( $bg_key ); ?>_overlay_color" value="<?php echo esc_attr( (string) ( $theme[ $bg_key . '_overlay_color' ] ?? '#000000' ) ); ?>" data-certloom-theme data-certloom-theme-color="<?php echo esc_attr( $bg_key . '_overlay_color' ); ?>"><input type="text" maxlength="7" pattern="#[0-9A-Fa-f]{6}" value="<?php echo esc_attr( strtoupper( (string) ( $theme[ $bg_key . '_overlay_color' ] ?? '#000000' ) ) ); ?>" data-certloom-theme-hex="<?php echo esc_attr( $bg_key . '_overlay_color' ); ?>"></span></label>
							<label class="certloom-email-range-field"><span><?php esc_html_e( 'Overlay opacity', 'certloom' ); ?></span><span class="certloom-email-range-control"><input type="range" min="0" max="100" step="1" name="theme_<?php echo esc_attr( $bg_key ); ?>_overlay_opacity" value="<?php echo esc_attr( absint( $theme[ $bg_key . '_overlay_opacity' ] ?? 0 ) ); ?>" data-certloom-theme><output><?php echo esc_html( absint( $theme[ $bg_key . '_overlay_opacity' ] ?? 0 ) ); ?>%</output></span></label>
						</div>
						<div class="certloom-theme-background-options"><label><span><?php esc_html_e( 'Size', 'certloom' ); ?></span><select name="theme_<?php echo esc_attr( $bg_key ); ?>_size" data-certloom-theme><?php foreach ( array( 'cover' => __( 'Cover', 'certloom' ), 'contain' => __( 'Contain', 'certloom' ), 'auto' => __( 'Auto', 'certloom' ) ) as $value => $label ) : ?><option value="<?php echo esc_attr( $value ); ?>" <?php selected( (string) ( $theme[ $bg_key . '_size' ] ?? 'cover' ), $value ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label><label><span><?php esc_html_e( 'Position', 'certloom' ); ?></span><select name="theme_<?php echo esc_attr( $bg_key ); ?>_position" data-certloom-theme><?php foreach ( array( 'center' => __( 'Center', 'certloom' ), 'top' => __( 'Top', 'certloom' ), 'bottom' => __( 'Bottom', 'certloom' ), 'left' => __( 'Left', 'certloom' ), 'right' => __( 'Right', 'certloom' ) ) as $value => $label ) : ?><option value="<?php echo esc_attr( $value ); ?>" <?php selected( (string) ( $theme[ $bg_key . '_position' ] ?? 'center' ), $value ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label><label><span><?php esc_html_e( 'Repeat', 'certloom' ); ?></span><select name="theme_<?php echo esc_attr( $bg_key ); ?>_repeat" data-certloom-theme><?php foreach ( array( 'no-repeat' => __( 'No repeat', 'certloom' ), 'repeat' => __( 'Repeat', 'certloom' ), 'repeat-x' => __( 'Horizontal', 'certloom' ), 'repeat-y' => __( 'Vertical', 'certloom' ) ) as $value => $label ) : ?><option value="<?php echo esc_attr( $value ); ?>" <?php selected( (string) ( $theme[ $bg_key . '_repeat' ] ?? 'no-repeat' ), $value ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label></div>
					</div>
				<?php endforeach; ?>
			</div>
			<details class="certloom-plain-text">
				<summary><?php esc_html_e( 'Plain-text fallback', 'certloom' ); ?></summary>
				<label><span class="screen-reader-text"><?php esc_html_e( 'Plain-text fallback', 'certloom' ); ?></span><textarea name="text_body" rows="8"><?php echo esc_textarea( (string) ( $editing['text_body'] ?? '' ) ); ?></textarea></label>
				<p class="description"><?php esc_html_e( 'Optional. Leave blank to let Certloom derive plain text from the message blocks.', 'certloom' ); ?></p>
			</details>
		</div>
	</aside>

	<main class="certloom-designer-stage">
		<div class="certloom-email-stage-toolbar">
			<div class="certloom-email-stage-toolbar__group" role="group" aria-label="<?php esc_attr_e( 'Preview data', 'certloom' ); ?>">
				<button type="button" class="is-active" aria-pressed="true" data-certloom-data-mode="sample"><?php esc_html_e( 'Sample', 'certloom' ); ?></button>
				<button type="button" aria-pressed="false" data-certloom-data-mode="tokens"><?php esc_html_e( 'Tokens', 'certloom' ); ?></button>
			</div>
			<span class="certloom-email-stage-meta" data-certloom-stage-meta><?php echo esc_html( absint( $theme['email_width'] ?? 640 ) ); ?>px · <?php echo esc_html( sprintf( /* translators: %d: number of email content blocks. */ _n( '%d block', '%d blocks', count( $blocks ), 'certloom' ), count( $blocks ) ) ); ?></span>
		</div>
		<div class="certloom-email-stage-viewport" data-certloom-email-stage-viewport>
			<div class="certloom-canvas-frame" data-certloom-canvas-frame>
				<div class="certloom-email-canvas" data-certloom-email-canvas role="list" aria-label="<?php esc_attr_e( 'Email content blocks', 'certloom' ); ?>"></div>
			</div>
		</div>
	</main>

	<aside class="certloom-designer-inspector" aria-label="<?php esc_attr_e( 'Properties', 'certloom' ); ?>">
		<div class="certloom-email-inspector-heading">
			<p class="certloom-eyebrow"><?php esc_html_e( 'Properties', 'certloom' ); ?></p>
			<h2 data-certloom-inspector-heading><?php esc_html_e( 'Block settings', 'certloom' ); ?></h2>
			<small class="certloom-email-inspector-path" data-certloom-inspector-path hidden></small>
		</div>
		<div class="certloom-email-inspector-scroll" data-certloom-email-inspector>
			<div class="certloom-inspector-empty"><p><?php esc_html_e( 'Select a block on the canvas to edit its content and layout.', 'certloom' ); ?></p></div>
		</div>
	</aside>
</div>
</form>

<dialog class="certloom-email-dialog certloom-designer-preview" data-certloom-designer-preview-dialog><div class="certloom-email-dialog__bar"><div><strong><?php esc_html_e( 'Email preview', 'certloom' ); ?></strong><small><?php esc_html_e( 'Final rendered message', 'certloom' ); ?></small></div><div class="certloom-preview-dialog-actions"><div class="certloom-view-switch certloom-preview-device-switch" role="group" aria-label="<?php esc_attr_e( 'Preview size', 'certloom' ); ?>"><button type="button" class="is-active" data-certloom-preview-device="desktop" aria-pressed="true">▣ <?php esc_html_e( 'Desktop', 'certloom' ); ?></button><button type="button" data-certloom-preview-device="mobile" aria-pressed="false">▯ <?php esc_html_e( 'Mobile', 'certloom' ); ?></button></div><button type="button" class="button" data-certloom-preview-close><?php esc_html_e( 'Close', 'certloom' ); ?></button></div></div><div class="certloom-preview-dialog-stage"><iframe title="<?php esc_attr_e( 'Email preview', 'certloom' ); ?>" sandbox="" data-certloom-preview-frame></iframe></div></dialog>
</div>
