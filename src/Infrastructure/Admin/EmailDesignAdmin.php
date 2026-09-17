<?php
namespace Certloom\Infrastructure\Admin;

use Certloom\Application\Email\EmailTemplateService;
use Certloom\Application\Rendering\DesignerFontCatalog;
use Certloom\Infrastructure\Email\EmailDesignAssignmentRepository;
use Certloom\Infrastructure\Email\EmailDesignCompiler;
use Certloom\Infrastructure\Email\EmailDesignPackage;
use Certloom\Infrastructure\Email\EmailDesignPurposes;
use Certloom\Infrastructure\Email\EmailMergeTokenCatalog;
use Certloom\Infrastructure\Email\EmailTemplateCatalogRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Modern Email Design library and dedicated designer.
 *
 * Assignment authority intentionally lives in Design Rules. The compatibility
 * mutation routes at the bottom remain only for older submitted forms/links.
 */
final class EmailDesignAdmin {
	const PAGE = 'certloom-email-designs';

	/** @return void */
	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ), 53 );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_head', array( $this, 'print_local_font_faces' ) );
		add_action( 'admin_post_certloom_email_design_save', array( $this, 'save' ) );
		add_action( 'admin_post_certloom_email_design_delete', array( $this, 'delete' ) );
		add_action( 'admin_post_certloom_email_design_publish', array( $this, 'publish' ) );
		add_action( 'admin_post_certloom_email_design_duplicate', array( $this, 'duplicate' ) );
		add_action( 'admin_post_certloom_email_design_import', array( $this, 'import' ) );
		add_action( 'admin_post_certloom_email_design_export', array( $this, 'export' ) );

		// Backward-compatible mutation routes. The current UI does not expose them.
		add_action( 'admin_post_certloom_email_design_assign', array( $this, 'assign' ) );
		add_action( 'admin_post_certloom_email_design_inherit', array( $this, 'inherit' ) );
	}

	/** Print WordPress/theme local font faces inside Email Studio. @return void */
	public function print_local_font_faces() {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin routing.
		if ( self::PAGE !== $page || ! function_exists( 'wp_print_font_faces' ) ) { return; }
		wp_print_font_faces();
	}

	/** @return void */
	public function menu() {
		\Ecapla_CertVerify_Permissions::maybe_upgrade_capabilities();
		add_submenu_page(
			null,
			__( 'Email designs', 'certloom' ),
			__( 'Email designs', 'certloom' ),
			\Ecapla_CertVerify_Permissions::CAP_MANAGE_CREDENTIALS,
			self::PAGE,
			array( $this, 'render' )
		);
	}

	/** @return void */
	public function assets() {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only asset routing.
		if ( self::PAGE !== $page ) {
			return;
		}

		wp_enqueue_media();
		wp_enqueue_style( 'certloom-font-picker', CERTLOOM_URL . 'admin/assets/font-picker.css', array( 'ecapla-certverify-admin' ), CERTLOOM_VERSION );
		wp_enqueue_script( 'certloom-font-picker', CERTLOOM_URL . 'admin/assets/font-picker.js', array(), CERTLOOM_VERSION, true );
		wp_enqueue_style(
			'certloom-email-designs',
			CERTLOOM_URL . 'admin/assets/email-designs.css',
			array( 'ecapla-certverify-admin', 'certloom-font-picker' ),
			CERTLOOM_VERSION
		);
		wp_enqueue_script(
			'certloom-email-designer',
			CERTLOOM_URL . 'admin/assets/email-designer.js',
			array( 'certloom-font-picker' ),
			CERTLOOM_VERSION,
			true
		);
		wp_localize_script( 'certloom-font-picker', 'certloomFontPickerConfig', $this->font_picker_config() );
		$merge_tokens = new EmailMergeTokenCatalog();
		$font_catalog = new DesignerFontCatalog();

		wp_localize_script(
			'certloom-email-designer',
			'certloomEmailDesignerL10n',
			array(
				'addHeading'            => __( 'Heading', 'certloom' ),
				'addText'               => __( 'Text', 'certloom' ),
				'addButton'             => __( 'Button', 'certloom' ),
				'addImage'              => __( 'Image', 'certloom' ),
				'addDivider'            => __( 'Divider', 'certloom' ),
				'addSpacer'             => __( 'Spacer', 'certloom' ),
				'addContainer'          => __( 'Container', 'certloom' ),
				'container'             => __( 'Container', 'certloom' ),
				'containerDirection'    => __( 'Direction', 'certloom' ),
				'containerGap'          => __( 'Gap', 'certloom' ),
				'containerBasis'        => __( 'Width', 'certloom' ),
				'containerBackground'   => __( 'Container background', 'certloom' ),
				'containerBackgroundImage' => __( 'Container background image', 'certloom' ),
				'containerChildren'     => __( 'Items', 'certloom' ),
				'containerDepthLimit'   => __( 'Container nesting has reached the supported depth.', 'certloom' ),
				'emptyContainer'        => __( 'Empty container', 'certloom' ),
				'dropBlocksHere'        => __( 'Drop blocks here', 'certloom' ),
				'addBlock'              => __( 'Add block', 'certloom' ),
				'containerAddHelp'      => __( 'Add blocks from Insert or drag existing blocks into this Container.', 'certloom' ),
				'emailRoot'             => __( 'Email', 'certloom' ),
				'horizontal'            => __( 'Horizontal', 'certloom' ),
				'vertical'              => __( 'Vertical', 'certloom' ),
				'background'            => __( 'Background', 'certloom' ),
				'backgroundImage'       => __( 'Background image', 'certloom' ),
				'border'                => __( 'Border', 'certloom' ),
				'blockLimit'            => __( 'This email has reached the supported block limit.', 'certloom' ),
				'addHtml'               => __( 'Custom HTML', 'certloom' ),
				'htmlLabel'             => __( 'HTML', 'certloom' ),
				'searchFields'          => __( 'Search fields', 'certloom' ),
				'attachments'           => __( 'Attachments', 'certloom' ),
				'addAttachments'        => __( 'Add attachments', 'certloom' ),
				'chooseAttachments'     => __( 'Choose email attachments', 'certloom' ),
				'useAttachments'        => __( 'Attach selected files', 'certloom' ),
				'pageBackgroundImage'   => __( 'Page background image', 'certloom' ),
				'contentBackgroundImage'=> __( 'Content background image', 'certloom' ),
				'chooseBackground'      => __( 'Choose background image', 'certloom' ),
				'removeBackground'      => __( 'Remove background', 'certloom' ),
				'headingPlaceholder'    => __( 'Your credential is ready', 'certloom' ),
				'textPlaceholder'       => __( 'Write the message recipients should receive.', 'certloom' ),
				'buttonText'            => __( 'View credential', 'certloom' ),
				'buttonUrl'             => '{{verification_url}}',
				'chooseImage'           => __( 'Choose an email image', 'certloom' ),
				'useImage'              => __( 'Use this image', 'certloom' ),
				'remove'                => __( 'Remove', 'certloom' ),
				'duplicate'             => __( 'Duplicate', 'certloom' ),
				'moveUp'                => __( 'Move up', 'certloom' ),
				'moveDown'              => __( 'Move down', 'certloom' ),
				'dragBlock'             => __( 'Drag to reorder', 'certloom' ),
				'urlLabel'              => __( 'URL', 'certloom' ),
				'altText'               => __( 'Alternative text', 'certloom' ),
				'spacingPixels'         => __( 'Spacing', 'certloom' ),
				'headingLevel'          => __( 'Heading level', 'certloom' ),
				'contentLabel'          => __( 'Content', 'certloom' ),
				'textLabel'             => __( 'Text', 'certloom' ),
				'buttonTextLabel'       => __( 'Button text', 'certloom' ),
				'textFormatting'        => __( 'Text formatting', 'certloom' ),
				'bold'                  => __( 'Bold', 'certloom' ),
				'italic'                => __( 'Italic', 'certloom' ),
				'underline'             => __( 'Underline', 'certloom' ),
				'bulletedList'          => __( 'Bulleted list', 'certloom' ),
				'numberedList'          => __( 'Numbered list', 'certloom' ),
				'addLink'               => __( 'Add link', 'certloom' ),
				'removeLink'            => __( 'Remove link', 'certloom' ),
				'insertData'            => __( 'Insert data', 'certloom' ),
				'fontFamily'            => __( 'Font family', 'certloom' ),
				'fontSize'              => __( 'Font size', 'certloom' ),
				'fontWeight'            => __( 'Font weight', 'certloom' ),
				'lineHeight'            => __( 'Line height', 'certloom' ),
				'textColor'             => __( 'Text color', 'certloom' ),
				'backgroundColor'       => __( 'Background color', 'certloom' ),
				'alignment'             => __( 'Alignment', 'certloom' ),
				'horizontalPadding'     => __( 'Horizontal padding', 'certloom' ),
				'verticalPadding'       => __( 'Vertical padding', 'certloom' ),
				'cornerRadius'          => __( 'Corner radius', 'certloom' ),
				'imageWidth'            => __( 'Image width', 'certloom' ),
				'dividerColor'          => __( 'Divider color', 'certloom' ),
				'dividerThickness'      => __( 'Thickness', 'certloom' ),
				'dividerWidth'          => __( 'Divider width', 'certloom' ),
				'verticalSpacing'       => __( 'Vertical spacing', 'certloom' ),
				'letterSpacing'          => __( 'Letter spacing', 'certloom' ),
				'textTransform'          => __( 'Text transform', 'certloom' ),
				'none'                   => __( 'None', 'certloom' ),
				'uppercase'              => __( 'Uppercase', 'certloom' ),
				'lowercase'              => __( 'Lowercase', 'certloom' ),
				'capitalize'             => __( 'Capitalize', 'certloom' ),
				'backgroundOpacity'      => __( 'Background opacity', 'certloom' ),
				'backgroundColorOpacity' => __( 'Background color opacity', 'certloom' ),
				'overlayColor'           => __( 'Overlay color', 'certloom' ),
				'overlayOpacity'         => __( 'Overlay opacity', 'certloom' ),
				'borderColor'            => __( 'Border color', 'certloom' ),
				'borderWidth'            => __( 'Border width', 'certloom' ),
				'borderStyle'            => __( 'Border style', 'certloom' ),
				'fullWidthButton'        => __( 'Full width button', 'certloom' ),
				'verticalMargin'         => __( 'Vertical margin', 'certloom' ),
				'imageOpacity'           => __( 'Image opacity', 'certloom' ),
				'lineStyle'              => __( 'Line style', 'certloom' ),
				'solid'                  => __( 'Solid', 'certloom' ),
				'dashed'                 => __( 'Dashed', 'certloom' ),
				'dotted'                 => __( 'Dotted', 'certloom' ),
				'reset'                 => __( 'Reset', 'certloom' ),
				'inheritGlobal'         => __( 'Use email default', 'certloom' ),
				'typographySection'     => __( 'Typography', 'certloom' ),
				'colorsSection'         => __( 'Colors', 'certloom' ),
				'layoutSection'         => __( 'Layout', 'certloom' ),
				'spacingSection'        => __( 'Spacing', 'certloom' ),
				'blockActions'          => __( 'Block actions', 'certloom' ),
				'sampleRecipient'       => __( 'Sara Khan', 'certloom' ),
				'sampleProgram'         => __( 'Professional Leadership Program', 'certloom' ),
				'sampleIssuer'          => __( 'Example Institute', 'certloom' ),
				'sampleGrade'           => __( 'Distinction', 'certloom' ),
				'verifyThisCredential'  => __( 'Verify this credential', 'certloom' ),
				'contentBlocksRequired' => __( 'Add at least one usable content block before saving this email design.', 'certloom' ),
				'emptyContent'          => __( 'Add a content block to start the message.', 'certloom' ),
				'selectBlock'           => __( 'Select a block on the canvas to edit it.', 'certloom' ),
				'tokens'                => $merge_tokens->flat(),
				'tokenGroups'           => $merge_tokens->groups(),
				'tokenSamples'          => $merge_tokens->sample_values(),
				'fontCatalog'           => $font_catalog->browser_contract(),
				'fontLocalLabel'        => __( 'Installed locally', 'certloom' ),
				'fontFallbackLabel'     => __( 'Uses fallback on this device', 'certloom' ),
				'fontAvailabilityHelp'   => __( 'Local/system and site-local custom fonts. Custom web fonts are emitted only when used; email clients that block web fonts fall back safely.', 'certloom' ),
				'scanLocalFonts'          => __( 'Scan local fonts', 'certloom' ),
				'localDeviceFonts'        => __( 'Local device fonts', 'certloom' ),
				'savedLocalFont'          => __( 'saved local font', 'certloom' ),
				'localFontScanHelp'       => __( 'Optional: allow your browser to list fonts installed on this device. No font files are uploaded.', 'certloom' ),
				'localFontScanning'       => __( 'Checking fonts installed on this device…', 'certloom' ),
				'localFontsFound'         => __( 'local font families available.', 'certloom' ),
				'localFontsNone'          => __( 'No additional local fonts were returned by the browser.', 'certloom' ),
				'localFontPermission'     => __( 'Local font access was not allowed. You can grant permission and scan again.', 'certloom' ),
				'localFontUnsupported'    => __( 'This browser cannot list every installed font. WordPress local fonts and the built-in system catalog remain available.', 'certloom' ),
				'localFontScanFailed'     => __( 'Local fonts could not be read. The built-in and WordPress-local font lists are still available.', 'certloom' ),
			)
		);
	}


	/** Shared visual/custom font picker configuration. @return array<string,mixed> */
	private function font_picker_config() {
		return array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'certloom_custom_font_upload' ),
			'chooseFont' => __( 'Choose font', 'certloom' ), 'searchFonts' => __( 'Search fonts', 'certloom' ),
			'addCustomFont' => __( 'Upload custom font', 'certloom' ), 'customFonts' => __( 'Custom fonts', 'certloom' ),
			'familyName' => __( 'Family name', 'certloom' ), 'weight' => __( 'Weight', 'certloom' ), 'style' => __( 'Style', 'certloom' ),
			'normal' => __( 'Normal', 'certloom' ), 'italic' => __( 'Italic', 'certloom' ), 'fontFile' => __( 'Font file', 'certloom' ),
			'upload' => __( 'Upload font', 'certloom' ), 'uploading' => __( 'Uploading…', 'certloom' ),
			'uploadIncomplete' => __( 'Choose a font file and enter its family name.', 'certloom' ), 'uploadFailed' => __( 'Custom font upload failed.', 'certloom' ),
		);
	}

	/** @return void */
	public function render() {
		$this->cap();

		$catalog = new EmailTemplateCatalogRepository();
		$catalog->ensure_factory_presets();
		$view = isset( $_GET['email_view'] ) ? sanitize_key( wp_unslash( $_GET['email_view'] ) ) : 'library'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation.
		$edit_id = isset( $_GET['design_id'] ) ? sanitize_key( wp_unslash( $_GET['design_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation.

		if ( '' !== $edit_id || 'designer' === $view ) {
			$view = 'designer';
		}
		if ( ! in_array( $view, array( 'library', 'mine', 'designer' ), true ) ) {
			$view = 'library';
		}

		$notice = isset( $_GET['certloom_notice'] ) ? sanitize_text_field( wp_unslash( $_GET['certloom_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice rendering.
		$error = isset( $_GET['certloom_error'] ) ? sanitize_text_field( wp_unslash( $_GET['certloom_error'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice rendering.
		$purposes = EmailDesignPurposes::labels();

		if ( 'designer' === $view ) {
			$editing = '' !== $edit_id ? $catalog->get( $edit_id ) : null;
			if ( is_array( $editing ) && 'factory' === (string) ( $editing['source'] ?? '' ) ) {
				wp_safe_redirect(
					add_query_arg(
						array(
							'page'           => self::PAGE,
							'email_view'     => 'library',
							'certloom_error' => __( 'Built-in email designs are customized by creating your own copy.', 'certloom' ),
						),
						admin_url( 'admin.php' )
					)
				);
				exit;
			}
			if ( ! is_array( $editing ) ) {
				$compiled = ( new EmailDesignCompiler() )->compile(
					EmailDesignCompiler::default_blocks(),
					EmailDesignCompiler::default_theme()
				);
				$editing = array(
					'id'        => '',
					'name'      => __( 'Untitled email', 'certloom' ),
					'purpose'   => EmailDesignPurposes::ISSUED,
					'preheader' => __( 'Your credential is ready to view and verify.', 'certloom' ),
					'active'    => 0,
					'subject'   => __( 'Your {{program_title}} credential', 'certloom' ),
					'text_body' => '',
					'blocks'    => $compiled['blocks'],
					'theme'     => $compiled['theme'],
					'source'    => 'customer',
				);
			}

			$email_font_definitions = ( new DesignerFontCatalog() )->definitions();
			include CERTLOOM_PATH . 'admin/views/email-designer.php';
			return;
		}

		$all = $catalog->all();
		$source = 'library' === $view ? 'factory' : 'mine';
		$search = isset( $_GET['email_s'] ) ? trim( sanitize_text_field( wp_unslash( $_GET['email_s'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filtering.
		$purpose_filter = isset( $_GET['email_purpose'] ) ? sanitize_key( wp_unslash( $_GET['email_purpose'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filtering.
		$status_filter = isset( $_GET['email_status'] ) ? sanitize_key( wp_unslash( $_GET['email_status'] ) ) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filtering.
		if ( ! in_array( $status_filter, array( 'all', 'published', 'unpublished' ), true ) ) {
			$status_filter = 'all';
		}

		$designs = array_values(
			array_filter(
				$all,
				static function( $design ) use ( $source, $search, $purpose_filter, $status_filter ) {
					$is_factory = 'factory' === (string) ( $design['source'] ?? '' );
					if ( ( 'factory' === $source ) !== $is_factory ) {
						return false;
					}
					if ( '' !== $search && false === stripos( (string) ( $design['name'] ?? '' ), $search ) ) {
						return false;
					}
					if ( '' !== $purpose_filter && $purpose_filter !== (string) ( $design['purpose'] ?? '' ) ) {
						return false;
					}
					if ( 'published' === $status_filter && empty( $design['active'] ) ) {
						return false;
					}
					if ( 'unpublished' === $status_filter && ! empty( $design['active'] ) ) {
						return false;
					}
					return true;
				}
			)
		);

		$usage = new EmailDesignAssignmentRepository();
		foreach ( $designs as &$design ) {
			$design['usage_count'] = $usage->usage_count( (string) $design['id'] );
		}
		unset( $design );

		$package_upload_limit_label = $this->design_package_upload_limit_label( EmailDesignPackage::MAX_BYTES );
		include CERTLOOM_PATH . 'admin/views/email-designs.php';
	}

	/** @return void */
	public function save() {
		$this->cap();
		check_admin_referer( 'certloom_email_design_save' );

		$raw = isset( $_POST['blocks_json'] ) ? (string) wp_unslash( $_POST['blocks_json'] ) : '';
		$blocks = json_decode( $raw, true );
		$compiler = new EmailDesignCompiler();
		if ( ! is_array( $blocks ) || JSON_ERROR_NONE !== json_last_error() || ! $compiler->sanitize_blocks( $blocks ) ) {
			$this->redirect(
				new \WP_Error(
					'certloom_email_design_blocks_invalid',
					__( 'Add at least one usable content block before saving this email design.', 'certloom' )
				),
				''
			);
		}

		$theme = array(
			'background'        => $_POST['theme_background'] ?? '',
			'content_background'=> $_POST['theme_content_background'] ?? '',
			'background_image' => isset( $_POST['theme_background_image'] ) ? esc_url_raw( wp_unslash( $_POST['theme_background_image'] ) ) : '',
			'background_size' => isset( $_POST['theme_background_size'] ) ? sanitize_key( wp_unslash( $_POST['theme_background_size'] ) ) : 'cover',
			'background_position' => isset( $_POST['theme_background_position'] ) ? sanitize_key( wp_unslash( $_POST['theme_background_position'] ) ) : 'center',
			'background_repeat' => isset( $_POST['theme_background_repeat'] ) ? sanitize_key( wp_unslash( $_POST['theme_background_repeat'] ) ) : 'no-repeat',
			'content_background_image' => isset( $_POST['theme_content_background_image'] ) ? esc_url_raw( wp_unslash( $_POST['theme_content_background_image'] ) ) : '',
			'content_background_size' => isset( $_POST['theme_content_background_size'] ) ? sanitize_key( wp_unslash( $_POST['theme_content_background_size'] ) ) : 'cover',
			'content_background_position' => isset( $_POST['theme_content_background_position'] ) ? sanitize_key( wp_unslash( $_POST['theme_content_background_position'] ) ) : 'center',
			'content_background_repeat' => isset( $_POST['theme_content_background_repeat'] ) ? sanitize_key( wp_unslash( $_POST['theme_content_background_repeat'] ) ) : 'no-repeat',
			'text_color'        => $_POST['theme_text_color'] ?? '',
			'heading_color'     => $_POST['theme_heading_color'] ?? '',
			'button_background' => $_POST['theme_button_background'] ?? '',
			'button_text'       => $_POST['theme_button_text'] ?? '',
			'link_color'        => $_POST['theme_link_color'] ?? '',
			'font_family'       => isset( $_POST['theme_font_family'] ) ? sanitize_text_field( wp_unslash( $_POST['theme_font_family'] ) ) : '',
			'email_width'       => absint( $_POST['theme_email_width'] ?? 640 ),
			'border_radius'     => absint( $_POST['theme_border_radius'] ?? 12 ),
		);
		$attachments_raw = isset( $_POST['attachments_json'] ) ? (string) wp_unslash( $_POST['attachments_json'] ) : '[]';
		$attachments = json_decode( $attachments_raw, true );
		$attachments = is_array( $attachments ) ? $attachments : array();
		$attachments = ( new EmailTemplateService() )->validate_static_attachment_ids( $attachments );
		if ( is_wp_error( $attachments ) ) { $this->redirect( $attachments, '', '', 'designer' ); }

		$id = isset( $_POST['design_id'] ) ? sanitize_key( wp_unslash( $_POST['design_id'] ) ) : '';
		$catalog = new EmailTemplateCatalogRepository();
		$existing = '' !== $id ? $catalog->get( $id ) : null;
		$intent = isset( $_POST['save_intent'] ) ? sanitize_key( wp_unslash( $_POST['save_intent'] ) ) : 'save';
		$active = 'publish' === $intent ? 1 : ( is_array( $existing ) && ! empty( $existing['active'] ) ? 1 : 0 );

		$result = $catalog->save(
			array(
				'id'        => $id,
				'name'      => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
				'purpose'   => isset( $_POST['purpose'] ) ? sanitize_key( wp_unslash( $_POST['purpose'] ) ) : '',
				'preheader' => isset( $_POST['preheader'] ) ? sanitize_text_field( wp_unslash( $_POST['preheader'] ) ) : '',
				'active'    => $active,
				'subject'   => isset( $_POST['subject'] ) ? sanitize_text_field( wp_unslash( $_POST['subject'] ) ) : '',
				'text_body' => isset( $_POST['text_body'] ) ? sanitize_textarea_field( wp_unslash( $_POST['text_body'] ) ) : '',
				'blocks'    => $blocks,
				'theme'     => $theme,
				'attachments' => $attachments,
			)
		);
		$this->redirect(
			$result,
			'publish' === $intent ? __( 'Email design published.', 'certloom' ) : __( 'Email design saved.', 'certloom' ),
			is_wp_error( $result ) ? '' : (string) $result,
			'designer'
		);
	}

	/** @return void */
	public function publish() {
		$this->cap();
		$id = isset( $_POST['design_id'] ) ? sanitize_key( wp_unslash( $_POST['design_id'] ) ) : '';
		$published = ! empty( $_POST['published'] );
		check_admin_referer( 'certloom_email_design_publish_' . $id );
		$result = ( new EmailTemplateCatalogRepository() )->set_published( $id, $published );
		$return_view = isset( $_POST['return_view'] ) ? sanitize_key( wp_unslash( $_POST['return_view'] ) ) : 'mine';
		$this->redirect(
			$result,
			$published ? __( 'Email design published.', 'certloom' ) : __( 'Email design unpublished.', 'certloom' ),
			'',
			$return_view
		);
	}

	/** @return void */
	public function duplicate() {
		$this->cap();
		$id = isset( $_POST['design_id'] ) ? sanitize_key( wp_unslash( $_POST['design_id'] ) ) : '';
		check_admin_referer( 'certloom_email_design_duplicate_' . $id );
		$result = ( new EmailTemplateCatalogRepository() )->duplicate( $id );
		$this->redirect(
			$result,
			__( 'Email design copy created.', 'certloom' ),
			is_wp_error( $result ) ? '' : (string) $result,
			'designer'
		);
	}

	/** @return void */
	public function delete() {
		$this->cap();
		$id = isset( $_POST['design_id'] ) ? sanitize_key( wp_unslash( $_POST['design_id'] ) ) : '';
		check_admin_referer( 'certloom_email_design_delete_' . $id );
		$this->redirect(
			( new EmailTemplateCatalogRepository() )->delete( $id ),
			__( 'Email design deleted.', 'certloom' ),
			'',
			'mine'
		);
	}

	/** @return void */
	public function import() {
		$this->cap();
		check_admin_referer( 'certloom_email_design_import' );
		$json = isset( $_POST['package_json'] ) ? (string) wp_unslash( $_POST['package_json'] ) : '';

		if ( '' === trim( $json ) && ! empty( $_FILES['design_package'] ) && is_array( $_FILES['design_package'] ) ) {
			$file = $_FILES['design_package'];
			$size = absint( $file['size'] ?? 0 );
			$tmp = (string) ( $file['tmp_name'] ?? '' );
			$upload_limit = $this->design_package_upload_limit( EmailDesignPackage::MAX_BYTES );
			if (
				UPLOAD_ERR_OK !== absint( $file['error'] ?? UPLOAD_ERR_NO_FILE ) ||
				$size < 1 ||
				$size > $upload_limit ||
				'' === $tmp ||
				! is_uploaded_file( $tmp )
			) {
				$this->redirect(
					new \WP_Error(
						'certloom_email_design_import_upload',
						sprintf(
							/* translators: %s: maximum upload size for this site. */
							__( 'Choose a Certloom email design package no larger than %s.', 'certloom' ),
							$this->design_package_upload_limit_label( EmailDesignPackage::MAX_BYTES )
						)
					),
					'',
					'',
					'mine'
				);
			}
			$contents = file_get_contents( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Bounded local upload.
			$json = is_string( $contents ) ? $contents : '';
		}

		$result = ( new EmailTemplateCatalogRepository() )->import_package( $json );
		$this->redirect(
			$result,
			__( 'Email design imported as unpublished.', 'certloom' ),
			is_wp_error( $result ) ? '' : (string) $result,
			'designer'
		);
	}

	/** @return void */
	public function export() {
		$this->cap();
		$id = isset( $_GET['design_id'] ) ? sanitize_key( wp_unslash( $_GET['design_id'] ) ) : '';
		check_admin_referer( 'certloom_email_design_export_' . $id );
		$result = ( new EmailTemplateCatalogRepository() )->export_package(
			$id,
			defined( 'ECAPLA_CERTVERIFY_VERSION' ) ? ECAPLA_CERTVERIFY_VERSION : ''
		);
		if ( is_wp_error( $result ) ) {
			$this->redirect( $result, '', '', 'mine' );
		}
		$json = ( new EmailDesignPackage( new EmailDesignCompiler() ) )->encode( $result );
		if ( is_wp_error( $json ) ) {
			$this->redirect( $json, '', '', 'mine' );
		}

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="certloom-email-design-' . sanitize_file_name( $id ) . '.json"' );
		header( 'X-Content-Type-Options: nosniff' );
		echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bounded package JSON download.
		exit;
	}

	/** Compatibility-only old route; the current UI uses Design Rules. @return void */
	public function assign() {
		$this->cap();
		check_admin_referer( 'certloom_email_design_assign' );
		$scope = isset( $_POST['scope_type'] ) ? sanitize_key( wp_unslash( $_POST['scope_type'] ) ) : 'global';
		if ( 'issuer' === $scope ) {
			$scope_id = absint( $_POST['issuer_id'] ?? 0 );
		} elseif ( 'program' === $scope ) {
			$scope_id = absint( $_POST['program_id'] ?? 0 );
		} elseif ( 'batch' === $scope ) {
			$scope_id = absint( $_POST['batch_id'] ?? 0 );
		} else {
			$scope_id = 0;
		}
		$purpose = isset( $_POST['purpose'] ) ? sanitize_key( wp_unslash( $_POST['purpose'] ) ) : '';
		$design_id = isset( $_POST['design_id'] ) ? sanitize_key( wp_unslash( $_POST['design_id'] ) ) : '';
		$result = ( new EmailDesignAssignmentRepository() )->save( $scope, $scope_id, $purpose, $design_id );
		$this->rules_redirect( $result, __( 'Design rule updated.', 'certloom' ), $scope, $scope_id );
	}

	/** Compatibility-only old route; the current UI uses Design Rules. @return void */
	public function inherit() {
		$this->cap();
		$scope = isset( $_POST['scope_type'] ) ? sanitize_key( wp_unslash( $_POST['scope_type'] ) ) : '';
		$scope_id = absint( $_POST['scope_id'] ?? 0 );
		$purpose = isset( $_POST['purpose'] ) ? sanitize_key( wp_unslash( $_POST['purpose'] ) ) : '';
		check_admin_referer( 'certloom_email_design_inherit_' . $scope . '_' . $scope_id . '_' . $purpose );
		$result = ( new EmailDesignAssignmentRepository() )->remove( $scope, $scope_id, $purpose );
		$this->rules_redirect( $result, __( 'Design inheritance restored.', 'certloom' ), $scope, $scope_id );
	}

	/** @return void */
	private function rules_redirect( $result, $success, $scope = '', $scope_id = 0 ) {
		$args = array( 'page' => DesignRulesAdmin::PAGE );
		if ( in_array( $scope, array( 'issuer', 'program', 'batch' ), true ) && absint( $scope_id ) ) {
			$args['rule_scope'] = $scope;
			$args['rule_scope_id'] = absint( $scope_id );
		}
		if ( is_wp_error( $result ) ) {
			$args['certloom_error'] = '1';
			$args['certloom_message'] = $result->get_error_message();
		} else {
			$args['certloom_notice'] = 'rules_saved';
			$args['certloom_message'] = $success;
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/** @return int */
	private function design_package_upload_limit( $package_limit ) {
		$package_limit = max( 1, absint( $package_limit ) );
		$site_limit = function_exists( 'wp_max_upload_size' ) ? absint( wp_max_upload_size() ) : 0;
		return $site_limit > 0 ? min( $package_limit, $site_limit ) : $package_limit;
	}

	/** @return string */
	private function design_package_upload_limit_label( $package_limit ) {
		$bytes = $this->design_package_upload_limit( $package_limit );
		if ( function_exists( 'size_format' ) ) {
			return size_format( $bytes, 0 );
		}
		return (string) max( 1, (int) floor( $bytes / 1048576 ) ) . ' MB';
	}

	/** @return void */
	private function cap() {
		if ( ! current_user_can( \Ecapla_CertVerify_Permissions::CAP_MANAGE_CREDENTIALS ) && ! current_user_can( ECAPLA_CERTVERIFY_CAP ) ) {
			wp_die(
				esc_html__( 'You do not have permission to manage Certloom email designs.', 'certloom' ),
				'',
				array( 'response' => 403 )
			);
		}
	}

	/** @return void */
	private function redirect( $result, $success, $design_id = '', $view = 'library' ) {
		$args = array(
			'page'       => self::PAGE,
			'email_view' => $view,
		);
		if ( is_wp_error( $result ) ) {
			$args['certloom_error'] = $result->get_error_message();
		} else {
			$args['certloom_notice'] = $success;
			if ( '' !== $design_id ) {
				$args['design_id'] = $design_id;
			}
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}
}
