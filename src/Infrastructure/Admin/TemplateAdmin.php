<?php
namespace Certloom\Infrastructure\Admin;

use Certloom\Application\Rendering\CanonicalJson;
use Certloom\Application\Rendering\LayoutValidator;
use Certloom\Application\Templates\TemplateDefaults;
use Certloom\Application\Templates\FactoryPresetService;
use Certloom\Application\Templates\PresetCatalog;
use Certloom\Infrastructure\Repository\TemplateRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Gated TPL-01 administration. Publishing/authoritative previews remain renderer-gated. */
final class TemplateAdmin {
	const PAGE = 'certloom-templates';

	/** @return void */
	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ), 52 );
		add_action( 'admin_post_certloom_template_create', array( $this, 'create' ) );
		add_action( 'admin_post_certloom_template_metadata', array( $this, 'metadata' ) );
		add_action( 'admin_post_certloom_template_revision', array( $this, 'revision' ) );
		add_action( 'admin_post_certloom_template_import', array( $this, 'import' ) );
		add_action( 'admin_post_certloom_template_export', array( $this, 'export' ) );
		add_action( 'admin_post_certloom_template_presets_sync', array( $this, 'sync_presets' ) );
		add_action( 'admin_post_certloom_template_preset_reset', array( $this, 'reset_preset' ) );
	}

	/** @return void */
	public function menu() {
		remove_submenu_page( 'certloom', self::PAGE );
		add_submenu_page( 'certloom', __( 'Templates', 'certloom' ), __( 'Templates', 'certloom' ), \Ecapla_CertVerify_Permissions::CAP_MANAGE_TEMPLATES, self::PAGE, array( $this, 'render' ) );
	}

	/** @return void */
	public function create() {
		$this->require_capability();
		check_admin_referer( 'certloom_template_create' );
		$result = ( new TemplateRepository() )->create(
			array(
				'machine_key' => isset( $_POST['machine_key'] ) ? sanitize_key( wp_unslash( $_POST['machine_key'] ) ) : '',
				'name' => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
				'description' => isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '',
				'layout' => TemplateDefaults::blank_a4_landscape(),
			),
			get_current_user_id()
		);
		$this->redirect( $result, 'created' );
	}

	/** @return void */
	public function metadata() {
		$this->require_capability();
		$id = isset( $_POST['template_id'] ) ? absint( $_POST['template_id'] ) : 0;
		check_admin_referer( 'certloom_template_metadata_' . $id );
		$result = ( new TemplateRepository() )->update_metadata(
			$id,
			array(
				'machine_key' => isset( $_POST['machine_key'] ) ? sanitize_key( wp_unslash( $_POST['machine_key'] ) ) : '',
				'name' => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
				'description' => isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '',
			),
			isset( $_POST['expected_updated_at'] ) ? sanitize_text_field( wp_unslash( $_POST['expected_updated_at'] ) ) : ''
		);
		$this->redirect( $result, 'metadata_saved', array( 'template_id' => $id ) );
	}

	/** @return void */
	public function revision() {
		$this->require_capability();
		$id = isset( $_POST['template_id'] ) ? absint( $_POST['template_id'] ) : 0;
		check_admin_referer( 'certloom_template_revision_' . $id );
		$raw = isset( $_POST['layout_json'] ) ? wp_unslash( $_POST['layout_json'] ) : '';
		$layout = json_decode( (string) $raw, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $layout ) ) {
			$result = new \WP_Error( 'certloom_template_layout_json_invalid', __( 'Enter valid canonical layout JSON.', 'certloom' ) );
		} else {
			$result = ( new TemplateRepository() )->save_revision( $id, $layout, get_current_user_id(), isset( $_POST['expected_revision_id'] ) ? absint( $_POST['expected_revision_id'] ) : 0 );
		}
		$this->redirect( $result, 'revision_saved', array( 'template_id' => $id ) );
	}

	/** @return void */
	public function import() {
		$this->require_capability();
		check_admin_referer( 'certloom_template_import' );
		$json = isset( $_POST['package_json'] ) ? wp_unslash( $_POST['package_json'] ) : '';
		$result = ( new TemplateRepository() )->import_package( $json, get_current_user_id() );
		$this->redirect( $result, 'imported', is_wp_error( $result ) ? array() : array( 'template_id' => absint( $result ) ) );
	}

	/** @return void */
	public function sync_presets() {
		$this->require_capability();
		check_admin_referer( 'certloom_template_presets_sync' );
		$result = ( new FactoryPresetService() )->sync_all( get_current_user_id() );
		if ( is_wp_error( $result ) ) { $this->redirect( $result, 'presets_synced' ); }
		$message = sprintf(
			/* translators: 1: created templates, 2: updated templates, 3: unchanged templates, 4: customer-customized templates. */
			__( 'Factory templates synchronized: %1$d created, %2$d updated, %3$d unchanged, %4$d customer-customized and preserved.', 'certloom' ),
			absint( $result['created'] ?? 0 ), absint( $result['updated'] ?? 0 ), absint( $result['unchanged'] ?? 0 ), absint( $result['customized'] ?? 0 )
		);
		$this->redirect_message( $message, 'presets_synced' );
	}

	/** @return void */
	public function reset_preset() {
		$this->require_capability();
		$id = isset( $_POST['template_id'] ) ? absint( $_POST['template_id'] ) : 0;
		check_admin_referer( 'certloom_template_preset_reset_' . $id );
		$result = ( new FactoryPresetService() )->reset_to_factory( $id, get_current_user_id(), isset( $_POST['expected_revision_id'] ) ? absint( $_POST['expected_revision_id'] ) : 0 );
		$this->redirect( $result, 'preset_reset', array( 'template_id' => $id ) );
	}

	/** @return void */
	public function export() {
		$this->require_capability();
		$id = isset( $_GET['template_id'] ) ? absint( $_GET['template_id'] ) : 0;
		check_admin_referer( 'certloom_template_export_' . $id );
		$result = ( new TemplateRepository() )->export_package( $id, defined( 'ECAPLA_CERTVERIFY_VERSION' ) ? ECAPLA_CERTVERIFY_VERSION : '' );
		if ( is_wp_error( $result ) ) { $this->redirect( $result, 'exported', array( 'template_id' => $id ) ); }
		try {
			$json = ( new CanonicalJson() )->encode( $result );
		} catch ( \Throwable $error ) {
			$this->redirect( new \WP_Error( 'certloom_template_export_encode_failed', __( 'Certloom could not encode the template export safely.', 'certloom' ) ), 'exported', array( 'template_id' => $id ) );
		}
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="certloom-template-' . $id . '.json"' );
		header( 'X-Content-Type-Options: nosniff' );
		echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Canonical JSON download.
		exit;
	}

	/** @return void */
	public function render() {
		$this->require_capability();
		$repo = new TemplateRepository();
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter.
		$page = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter.
		$result = $repo->list_page( $search, $page, 20 );
		$edit_id = isset( $_GET['template_id'] ) ? absint( $_GET['template_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation.
		$editing = $edit_id ? $repo->find( $edit_id ) : null;
		echo '<div class="wrap certloom-domain-admin"><h1>' . esc_html__( 'Templates', 'certloom' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'Template drafts are versioned and local. Authoritative publishing and rendered preview remain disabled until Certloom’s production renderer acceptance gate passes.', 'certloom' ) . '</p>';
		$this->notice();
		$this->preset_section();
		if ( is_wp_error( $result ) ) { echo '<div class="notice notice-error"><p>' . esc_html( $result->get_error_message() ) . '</p></div>'; }
		else {
			echo '<form method="get"><input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '"><p class="search-box"><label class="screen-reader-text" for="certloom-template-search">' . esc_html__( 'Search templates', 'certloom' ) . '</label><input id="certloom-template-search" type="search" name="s" value="' . esc_attr( $search ) . '"><button class="button">' . esc_html__( 'Search', 'certloom' ) . '</button></p></form>';
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Template', 'certloom' ) . '</th><th>' . esc_html__( 'Machine key', 'certloom' ) . '</th><th>' . esc_html__( 'Draft revision', 'certloom' ) . '</th><th>' . esc_html__( 'Published', 'certloom' ) . '</th><th>' . esc_html__( 'Status', 'certloom' ) . '</th></tr></thead><tbody>';
			foreach ( $result['items'] as $item ) {
				$url = add_query_arg( array( 'page' => self::PAGE, 'template_id' => absint( $item['id'] ) ), admin_url( 'admin.php' ) );
				echo '<tr><td><strong><a href="' . esc_url( $url ) . '">' . esc_html( (string) $item['name'] ) . '</a></strong></td><td><code>' . esc_html( (string) $item['machine_key'] ) . '</code></td><td>' . esc_html( (string) absint( $item['current_revision_number'] ?? 0 ) ) . '</td><td>' . esc_html( empty( $item['published_revision_id'] ) ? '—' : (string) absint( $item['published_revision_id'] ) ) . '</td><td>' . esc_html( empty( $item['archived_at'] ) ? __( 'Draft', 'certloom' ) : __( 'Archived', 'certloom' ) ) . '</td></tr>';
			}
			if ( empty( $result['items'] ) ) { echo '<tr><td colspan="5">' . esc_html__( 'No templates found.', 'certloom' ) . '</td></tr>'; }
			echo '</tbody></table>';
		}
		$this->editor( $editing );
		$this->import_form();
		echo '</div>';
	}

	/** @return void */
	private function editor( $editing ) {
		if ( is_wp_error( $editing ) ) { echo '<div class="notice notice-error"><p>' . esc_html( $editing->get_error_message() ) . '</p></div>'; return; }
		if ( ! is_array( $editing ) ) {
			echo '<hr><h2>' . esc_html__( 'Create template draft', 'certloom' ) . '</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="certloom_template_create">'; wp_nonce_field( 'certloom_template_create' );
			$this->identity_fields( array() ); submit_button( __( 'Create template', 'certloom' ) ); echo '</form>'; return;
		}
		$id = absint( $editing['id'] );
		echo '<hr><h2>' . esc_html__( 'Edit template draft', 'certloom' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="certloom_template_metadata"><input type="hidden" name="template_id" value="' . esc_attr( (string) $id ) . '"><input type="hidden" name="expected_updated_at" value="' . esc_attr( (string) $editing['updated_at'] ) . '">'; wp_nonce_field( 'certloom_template_metadata_' . $id ); $this->identity_fields( $editing ); submit_button( __( 'Save metadata', 'certloom' ) ); echo '</form>';
		$revision = $editing['current_revision'] ?? array();
		try { $layout_json = ( new CanonicalJson() )->encode( $revision['layout'] ?? TemplateDefaults::blank_a4_landscape() ); } catch ( \Throwable $e ) { $layout_json = ''; }
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="certloom_template_revision"><input type="hidden" name="template_id" value="' . esc_attr( (string) $id ) . '"><input type="hidden" name="expected_revision_id" value="' . esc_attr( (string) absint( $editing['current_revision_id'] ) ) . '">'; wp_nonce_field( 'certloom_template_revision_' . $id );
		echo '<h3>' . esc_html__( 'Canonical layout JSON', 'certloom' ) . '</h3><p class="description">' . esc_html__( 'Each successful save creates a new immutable revision. A stale browser tab is rejected instead of overwriting newer work.', 'certloom' ) . '</p><textarea name="layout_json" rows="18" class="large-text code" spellcheck="false">' . esc_textarea( $layout_json ) . '</textarea>'; submit_button( __( 'Save new revision', 'certloom' ) ); echo '</form>';
		$export = wp_nonce_url( add_query_arg( array( 'action' => 'certloom_template_export', 'template_id' => $id ), admin_url( 'admin-post.php' ) ), 'certloom_template_export_' . $id );
		echo '<p><a class="button" href="' . esc_url( $export ) . '">' . esc_html__( 'Export template JSON', 'certloom' ) . '</a> <button class="button" disabled aria-disabled="true">' . esc_html__( 'Publish (renderer gate)', 'certloom' ) . '</button></p>';
		if ( 0 === strpos( (string) ( $editing['machine_key'] ?? '' ), 'certloom-preset-' ) ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="certloom_template_preset_reset"><input type="hidden" name="template_id" value="' . esc_attr( (string) $id ) . '"><input type="hidden" name="expected_revision_id" value="' . esc_attr( (string) absint( $editing['current_revision_id'] ) ) . '">';
			wp_nonce_field( 'certloom_template_preset_reset_' . $id );
			echo '<p class="description">' . esc_html__( 'Restoring a factory design creates another immutable revision. Your current customized revision remains in history and is never deleted.', 'certloom' ) . '</p>';
			submit_button( __( 'Restore current factory design', 'certloom' ), 'secondary', 'submit', false );
			echo '</form>';
		}
	}

	/** @return void */
	private function preset_section() {
		$catalog = new PresetCatalog();
		$families = PresetCatalog::families();
		$variants = PresetCatalog::variants();
		echo '<div class="card" style="max-width:none"><h2>' . esc_html__( 'Premium starter templates', 'certloom' ) . '</h2>';
		echo '<p>' . esc_html( sprintf( __( '%1$d design families · %2$d page/orientation variants · %3$d canonical factory layouts. Factory layouts are local drafts until the production renderer gate is accepted.', 'certloom' ), count( $families ), count( $variants ), count( $catalog->all() ) ) ) . '</p>';
		echo '<ul style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:8px 18px">';
		foreach ( $families as $family ) { echo '<li><strong>' . esc_html( $family['name'] ) . '</strong><br><span class="description">' . esc_html( $family['description'] ) . '</span></li>'; }
		echo '</ul><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="certloom_template_presets_sync">';
		wp_nonce_field( 'certloom_template_presets_sync' ); submit_button( __( 'Install / synchronize factory drafts', 'certloom' ), 'secondary', 'submit', false ); echo '</form></div>';
	}

	/** @return void */
	private function identity_fields( array $data ) {
		$is_factory = 0 === strpos( (string) ( $data['machine_key'] ?? '' ), 'certloom-preset-' );
		echo '<table class="form-table"><tr><th><label for="certloom-template-name">' . esc_html__( 'Name', 'certloom' ) . '</label></th><td><input class="regular-text" id="certloom-template-name" name="name" maxlength="255" required value="' . esc_attr( (string) ( $data['name'] ?? '' ) ) . '"></td></tr><tr><th><label for="certloom-template-key">' . esc_html__( 'Machine key', 'certloom' ) . '</label></th><td><input class="regular-text code" id="certloom-template-key" name="machine_key" maxlength="191" required ' . ( $is_factory ? 'readonly aria-readonly="true" ' : '' ) . 'value="' . esc_attr( (string) ( $data['machine_key'] ?? '' ) ) . '"><p class="description">' . esc_html( $is_factory ? __( 'Factory template identity is permanent. Duplicate/export the design for a separately named template.', 'certloom' ) : __( 'Stable lowercase key used by assignments, imports and future integrations.', 'certloom' ) ) . '</p></td></tr><tr><th><label for="certloom-template-description">' . esc_html__( 'Description', 'certloom' ) . '</label></th><td><textarea class="large-text" rows="4" id="certloom-template-description" name="description">' . esc_textarea( (string) ( $data['description'] ?? '' ) ) . '</textarea></td></tr></table>';
	}

	/** @return void */
	private function import_form() {
		echo '<hr><h2>' . esc_html__( 'Import template', 'certloom' ) . '</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="certloom_template_import">'; wp_nonce_field( 'certloom_template_import' ); echo '<textarea class="large-text code" rows="8" name="package_json" required></textarea>'; submit_button( __( 'Validate and import', 'certloom' ), 'secondary' ); echo '</form>';
	}

	/** @return void */
	private function notice() {
		if ( empty( $_GET['certloom_notice'] ) ) { return; } // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Redirect status only.
		$type = isset( $_GET['certloom_error'] ) ? 'error' : 'success'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$message = isset( $_GET['certloom_message'] ) ? sanitize_text_field( wp_unslash( $_GET['certloom_message'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $message ) { echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( $message ) . '</p></div>'; }
	}

	/** @return never */
	private function redirect_message( $message, $notice, array $args = array() ) {
		$query = array_merge( array( 'page' => self::PAGE, 'certloom_notice' => sanitize_key( $notice ), 'certloom_message' => sanitize_text_field( (string) $message ) ), $args );
		wp_safe_redirect( add_query_arg( $query, admin_url( 'admin.php' ) ) ); exit;
	}

	/** @return never */
	private function redirect( $result, $notice, array $args = array() ) {
		$query = array_merge( array( 'page' => self::PAGE, 'certloom_notice' => sanitize_key( $notice ) ), $args );
		if ( is_wp_error( $result ) ) { $query['certloom_error'] = '1'; $query['certloom_message'] = $result->get_error_message(); }
		else { $query['certloom_message'] = __( 'Template changes were saved.', 'certloom' ); }
		wp_safe_redirect( add_query_arg( $query, admin_url( 'admin.php' ) ) ); exit;
	}

	/** @return void */
	private function require_capability() {
		if ( ! current_user_can( \Ecapla_CertVerify_Permissions::CAP_MANAGE_TEMPLATES ) ) { wp_die( esc_html__( 'You are not allowed to manage Certloom templates.', 'certloom' ), '', array( 'response' => 403 ) ); }
	}
}
