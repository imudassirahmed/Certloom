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

	/** Load only the current composer and the shared design library styles. */
	public function assets() {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only asset routing.
		if ( self::PAGE !== $page ) { return; }
		wp_enqueue_style( 'certloom-email-designs', CERTLOOM_URL . 'admin/assets/email-designs.css', array( 'ecapla-certverify-admin' ), CERTLOOM_VERSION );
		$view = isset( $_GET['email_view'] ) ? sanitize_key( wp_unslash( $_GET['email_view'] ) ) : 'library'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only asset routing.
		if ( 'designer' !== $view ) { return; }
		wp_enqueue_media();
		wp_enqueue_style( 'certloom-email-composer', CERTLOOM_URL . 'admin/assets/email-composer.css', array( 'certloom-admin-foundation', 'certloom-email-designs' ), (string) filemtime( CERTLOOM_PATH . 'admin/assets/email-composer.css' ) );
		wp_enqueue_script( 'certloom-email-composer', CERTLOOM_URL . 'admin/assets/email-composer.js', array( 'ecapla-certverify-admin' ), (string) filemtime( CERTLOOM_PATH . 'admin/assets/email-composer.js' ), true );
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
					\Certloom\Infrastructure\Email\EmailComposerDocument::defaults(),
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
			include CERTLOOM_PATH . 'admin/views/email-composer.php';
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
		if ( isset( $_POST['composer_theme_json'] ) ) {
			$raw_theme = $_POST['composer_theme_json'];
			$theme = is_string( $raw_theme ) ? json_decode( wp_unslash( $raw_theme ), true ) : null;
			if ( ! is_array( $theme ) || JSON_ERROR_NONE !== json_last_error() ) { $this->redirect( new \WP_Error( 'certloom_email_composer_theme_invalid', __( 'The email appearance settings could not be read. Please try again.', 'certloom' ) ), '', '', 'designer' ); }
		}
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
