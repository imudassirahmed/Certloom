<?php
namespace Certloom\Infrastructure\Admin;

use Certloom\Application\Rendering\CanonicalJson;
use Certloom\Application\Rendering\LayoutValidator;
use Certloom\Application\Templates\TemplateDefaults;
use Certloom\Application\Templates\FactoryPresetService;
use Certloom\Application\Templates\PresetCatalog;
use Certloom\Application\Templates\TemplateAssignmentPolicy;
use Certloom\Infrastructure\Repository\TemplateRepository;
use Certloom\Infrastructure\Repository\IssuerRepository;
use Certloom\Infrastructure\Repository\ProgramRepository;
use Certloom\Infrastructure\Repository\BatchRepository;
use Certloom\Infrastructure\Rendering\RendererAuthorityGate;

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
		add_action( 'admin_post_certloom_template_publish', array( $this, 'publish' ) );
		add_action( 'admin_post_certloom_template_import', array( $this, 'import' ) );
		add_action( 'admin_post_certloom_template_export', array( $this, 'export' ) );
		add_action( 'admin_post_certloom_template_presets_sync', array( $this, 'sync_presets' ) );
		add_action( 'admin_post_certloom_template_preset_reset', array( $this, 'reset_preset' ) );
		add_action( 'admin_post_certloom_template_assignment_save', array( $this, 'assignment_save' ) );
		add_action( 'admin_post_certloom_template_assignment_clear', array( $this, 'assignment_clear' ) );
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
	public function publish() {
		$this->require_capability();
		$id = isset( $_POST['template_id'] ) ? absint( $_POST['template_id'] ) : 0;
		check_admin_referer( 'certloom_template_publish_' . $id );
		$result = ( new TemplateRepository() )->publish( $id, isset( $_POST['expected_revision_id'] ) ? absint( $_POST['expected_revision_id'] ) : 0 );
		$this->redirect( $result, 'published', array( 'template_id' => $id ) );
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
	public function assignment_save() {
		$this->require_capability();
		$scope_type = isset( $_POST['scope_type'] ) ? sanitize_key( wp_unslash( $_POST['scope_type'] ) ) : '';
		$scope_id = isset( $_POST['scope_id'] ) ? absint( $_POST['scope_id'] ) : 0;
		check_admin_referer( 'certloom_template_assignment_' . $scope_type . '_' . $scope_id );
		$result = ( new TemplateRepository() )->assign(
			isset( $_POST['template_id'] ) ? absint( $_POST['template_id'] ) : 0,
			$scope_type,
			$scope_id,
			100,
			isset( $_POST['expected_assignment_id'] ) ? absint( $_POST['expected_assignment_id'] ) : 0,
			isset( $_POST['expected_updated_at'] ) ? sanitize_text_field( wp_unslash( $_POST['expected_updated_at'] ) ) : ''
		);
		$this->redirect( $result, 'assignment_saved', $this->assignment_redirect_args( $scope_type, $scope_id ) );
	}

	/** @return void */
	public function assignment_clear() {
		$this->require_capability();
		$scope_type = isset( $_POST['scope_type'] ) ? sanitize_key( wp_unslash( $_POST['scope_type'] ) ) : '';
		$scope_id = isset( $_POST['scope_id'] ) ? absint( $_POST['scope_id'] ) : 0;
		check_admin_referer( 'certloom_template_assignment_' . $scope_type . '_' . $scope_id );
		$result = ( new TemplateRepository() )->clear_assignment(
			$scope_type,
			$scope_id,
			isset( $_POST['expected_assignment_id'] ) ? absint( $_POST['expected_assignment_id'] ) : 0,
			isset( $_POST['expected_updated_at'] ) ? sanitize_text_field( wp_unslash( $_POST['expected_updated_at'] ) ) : ''
		);
		$this->redirect( $result, 'assignment_cleared', $this->assignment_redirect_args( $scope_type, $scope_id ) );
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
		$this->assignment_workspace( $repo );
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
	private function assignment_workspace( TemplateRepository $repo ) {
		$policy = new TemplateAssignmentPolicy();
		$scope_type = isset( $_GET['assignment_scope'] ) ? sanitize_key( wp_unslash( $_GET['assignment_scope'] ) ) : TemplateAssignmentPolicy::SCOPE_GLOBAL; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation.
		if ( ! in_array( $scope_type, TemplateAssignmentPolicy::scopes(), true ) ) { $scope_type = TemplateAssignmentPolicy::SCOPE_GLOBAL; }
		$scope_id = TemplateAssignmentPolicy::SCOPE_GLOBAL === $scope_type ? 0 : ( isset( $_GET['assignment_scope_id'] ) ? absint( $_GET['assignment_scope_id'] ) : 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation.
		$scope_search = isset( $_GET['scope_s'] ) ? sanitize_text_field( wp_unslash( $_GET['scope_s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only search.
		$scope_page = isset( $_GET['scope_paged'] ) ? max( 1, absint( $_GET['scope_paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination.

		echo '<section class="certloom-template-assignment-workspace"><div class="certloom-template-assignment-head"><div><h2>' . esc_html__( 'Template assignments', 'certloom' ) . '</h2><p>' . esc_html__( 'Choose a direct Template for a scope, or choose None to inherit the next available fallback. Most-specific scope wins: Batch → Program → Issuer → Global.', 'certloom' ) . '</p></div><span class="certloom-domain-preview-badge">' . esc_html__( 'Draft candidates · renderer gate', 'certloom' ) . '</span></div>';
		echo '<nav class="certloom-template-scope-tabs" aria-label="' . esc_attr__( 'Template assignment scopes', 'certloom' ) . '">';
		foreach ( TemplateAssignmentPolicy::scopes() as $scope ) {
			$url = add_query_arg( array( 'page' => self::PAGE, 'assignment_scope' => $scope ), admin_url( 'admin.php' ) );
			echo '<a class="' . ( $scope === $scope_type ? 'is-current' : '' ) . '" ' . ( $scope === $scope_type ? 'aria-current="page" ' : '' ) . 'href="' . esc_url( $url ) . '">' . esc_html( TemplateAssignmentPolicy::scope_label( $scope ) ) . '</a>';
		}
		echo '</nav>';

		if ( TemplateAssignmentPolicy::SCOPE_GLOBAL !== $scope_type ) {
			$this->assignment_target_picker( $scope_type, $scope_id, $scope_search, $scope_page );
			if ( ! $scope_id ) { echo '</section>'; return; }
		}

		$target = $this->assignment_target( $scope_type, $scope_id );
		if ( is_wp_error( $target ) ) { echo '<div class="notice notice-error inline"><p>' . esc_html( $target->get_error_message() ) . '</p></div></section>'; return; }
		$context = $this->assignment_context( $scope_type, $scope_id );
		if ( is_wp_error( $context ) ) { echo '<div class="notice notice-error inline"><p>' . esc_html( $context->get_error_message() ) . '</p></div></section>'; return; }
		$direct = $repo->get_assignment( $scope_type, $scope_id );
		$effective = $repo->resolve_assignment_details( $context );
		if ( is_wp_error( $direct ) || is_wp_error( $effective ) ) {
			$error = is_wp_error( $direct ) ? $direct : $effective;
			echo '<div class="notice notice-error inline"><p>' . esc_html( $error->get_error_message() ) . '</p></div></section>'; return;
		}

		$this->assignment_summary( $scope_type, $scope_id, $target, $direct, $effective, $context );
		$this->assignment_candidates( $repo, $scope_type, $scope_id, $direct, $effective );
		echo '</section>';
	}

	/** @return void */
	private function assignment_target_picker( $scope_type, $selected_id, $search, $page ) {
		$result = $this->assignment_target_page( $scope_type, $search, $page, 12 );
		echo '<div class="certloom-template-target-picker"><h3>' . esc_html( sprintf( __( 'Choose %s', 'certloom' ), TemplateAssignmentPolicy::scope_label( $scope_type ) ) ) . '</h3>';
		echo '<form method="get" class="certloom-domain-search"><input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '"><input type="hidden" name="assignment_scope" value="' . esc_attr( $scope_type ) . '"><label class="screen-reader-text" for="certloom-template-scope-search">' . esc_html__( 'Search assignment targets', 'certloom' ) . '</label><input id="certloom-template-scope-search" type="search" name="scope_s" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr__( 'Search name or code', 'certloom' ) . '"><button class="button">' . esc_html__( 'Search', 'certloom' ) . '</button></form>';
		if ( is_wp_error( $result ) ) { echo '<div class="notice notice-error inline"><p>' . esc_html( $result->get_error_message() ) . '</p></div></div>'; return; }
		echo '<div class="certloom-template-target-list">';
		foreach ( $result['items'] as $item ) {
			$id = absint( $item['id'] ?? 0 ); $label = $this->assignment_target_list_label( $scope_type, $item );
			$url = add_query_arg( array( 'page' => self::PAGE, 'assignment_scope' => $scope_type, 'assignment_scope_id' => $id, 'scope_s' => $search ), admin_url( 'admin.php' ) );
			echo '<a class="certloom-template-target ' . ( $id === absint( $selected_id ) ? 'is-current' : '' ) . '" ' . ( $id === absint( $selected_id ) ? 'aria-current="true" ' : '' ) . 'href="' . esc_url( $url ) . '"><strong>' . esc_html( $label['title'] ) . '</strong><span>' . esc_html( $label['meta'] ) . '</span></a>';
		}
		if ( empty( $result['items'] ) ) { echo '<p class="certloom-domain-empty">' . esc_html__( 'No matching assignment targets.', 'certloom' ) . '</p>'; }
		echo '</div>';
		$this->assignment_pagination( array( 'page' => self::PAGE, 'assignment_scope' => $scope_type, 'scope_s' => $search, 'assignment_scope_id' => absint( $selected_id ) ?: null ), 'scope_paged', $page, (int) $result['total'], 12 );
		echo '</div>';
	}

	/** @return void */
	private function assignment_summary( $scope_type, $scope_id, array $target, $direct, $effective, array $context ) {
		$direct_active = is_array( $direct ) && ! empty( $direct['active'] );
		$source = is_array( $effective ) ? TemplateAssignmentPolicy::scope_label( $effective['scope_type'] ?? '' ) : __( 'None', 'certloom' );
		echo '<div class="certloom-template-assignment-summary"><div><span>' . esc_html__( 'Assignment target', 'certloom' ) . '</span><strong>' . esc_html( $target['label'] ) . '</strong><small>' . esc_html( TemplateAssignmentPolicy::scope_label( $scope_type ) ) . '</small></div>';
		echo '<div><span>' . esc_html__( 'Direct assignment', 'certloom' ) . '</span><strong>' . esc_html( $direct_active ? (string) $direct['template_name'] : __( 'None · inherit/default', 'certloom' ) ) . '</strong><small>' . esc_html( $direct_active ? sprintf( __( 'Revision %d', 'certloom' ), absint( $direct['current_revision_number'] ?? 0 ) ) : __( 'No Template is forced at this scope.', 'certloom' ) ) . '</small></div>';
		echo '<div><span>' . esc_html__( 'Effective Template', 'certloom' ) . '</span><strong>' . esc_html( is_array( $effective ) ? (string) $effective['template_name'] : __( 'None', 'certloom' ) ) . '</strong><small>' . esc_html( is_array( $effective ) ? sprintf( __( 'Source: %1$s · fallback depth %2$d', 'certloom' ), $source, absint( $effective['fallback_depth'] ?? 0 ) ) : __( 'No assignment exists anywhere in the fallback chain.', 'certloom' ) ) . '</small></div></div>';
		echo '<p class="certloom-template-fallback"><strong>' . esc_html__( 'Fallback path:', 'certloom' ) . '</strong> ' . esc_html( $this->fallback_explanation( $context ) ) . '</p>';
		if ( is_array( $direct ) ) {
			echo '<form class="certloom-template-none-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="certloom_template_assignment_clear"><input type="hidden" name="scope_type" value="' . esc_attr( $scope_type ) . '"><input type="hidden" name="scope_id" value="' . esc_attr( (string) $scope_id ) . '"><input type="hidden" name="expected_assignment_id" value="' . esc_attr( (string) absint( $direct['id'] ?? 0 ) ) . '"><input type="hidden" name="expected_updated_at" value="' . esc_attr( (string) ( $direct['updated_at'] ?? '' ) ) . '">';
			wp_nonce_field( 'certloom_template_assignment_' . $scope_type . '_' . $scope_id );
			echo '<button class="button" ' . ( $direct_active ? '' : 'disabled aria-disabled="true"' ) . '>' . esc_html__( 'Use inherited/default (None)', 'certloom' ) . '</button><span class="description">' . esc_html__( 'Clearing a direct assignment preserves the lower-scope fallback rules; it does not delete any Template.', 'certloom' ) . '</span></form>';
		}
	}

	/** @return void */
	private function assignment_candidates( TemplateRepository $repo, $scope_type, $scope_id, $direct, $effective ) {
		$search = isset( $_GET['template_s'] ) ? sanitize_text_field( wp_unslash( $_GET['template_s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only search.
		$page = isset( $_GET['template_paged'] ) ? max( 1, absint( $_GET['template_paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination.
		$kind = isset( $_GET['template_kind'] ) ? sanitize_key( wp_unslash( $_GET['template_kind'] ) ) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter.
		if ( ! in_array( $kind, array( 'all', 'factory', 'custom' ), true ) ) { $kind = 'all'; }
		$result = $repo->list_candidates( $search, $page, 12, $kind );
		echo '<div class="certloom-template-candidates"><div class="certloom-template-candidates-head"><div><h3>' . esc_html__( 'Choose a Template', 'certloom' ) . '</h3><p>' . esc_html__( 'This is candidate metadata only—not an authoritative rendered preview. Publishing and rendered Preview remain blocked by the production renderer gate.', 'certloom' ) . '</p></div></div>';
		echo '<form method="get" class="certloom-template-candidate-filters"><input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '"><input type="hidden" name="assignment_scope" value="' . esc_attr( $scope_type ) . '"><input type="hidden" name="assignment_scope_id" value="' . esc_attr( (string) $scope_id ) . '"><label class="screen-reader-text" for="certloom-template-candidate-search">' . esc_html__( 'Search assignable Templates', 'certloom' ) . '</label><input id="certloom-template-candidate-search" type="search" name="template_s" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr__( 'Search Template name, key, or description', 'certloom' ) . '"><label class="screen-reader-text" for="certloom-template-kind">' . esc_html__( 'Template kind', 'certloom' ) . '</label><select id="certloom-template-kind" name="template_kind"><option value="all" ' . selected( $kind, 'all', false ) . '>' . esc_html__( 'All Templates', 'certloom' ) . '</option><option value="factory" ' . selected( $kind, 'factory', false ) . '>' . esc_html__( 'Factory', 'certloom' ) . '</option><option value="custom" ' . selected( $kind, 'custom', false ) . '>' . esc_html__( 'Custom / imported', 'certloom' ) . '</option></select><button class="button">' . esc_html__( 'Filter', 'certloom' ) . '</button></form>';
		if ( is_wp_error( $result ) ) { echo '<div class="notice notice-error inline"><p>' . esc_html( $result->get_error_message() ) . '</p></div></div>'; return; }
		echo '<div class="certloom-template-candidate-grid">';
		foreach ( $result['items'] as $item ) {
			$is_direct = is_array( $direct ) && ! empty( $direct['active'] ) && absint( $direct['template_id'] ?? 0 ) === absint( $item['id'] );
			$is_effective = is_array( $effective ) && absint( $effective['template_id'] ?? 0 ) === absint( $item['id'] );
			echo '<article class="certloom-template-candidate ' . ( $is_direct ? 'is-assigned' : '' ) . '"><div class="certloom-template-candidate-title"><div><span class="certloom-template-kind">' . esc_html( ! empty( $item['factory'] ) ? __( 'Factory', 'certloom' ) : __( 'Custom', 'certloom' ) ) . '</span><h4>' . esc_html( (string) $item['name'] ) . '</h4><code>' . esc_html( (string) $item['machine_key'] ) . '</code></div>' . ( $is_effective ? '<span class="certloom-domain-preview-badge">' . esc_html__( 'Effective', 'certloom' ) . '</span>' : '' ) . '</div>';
			echo '<p>' . esc_html( (string) ( $item['description'] ?: __( 'No description.', 'certloom' ) ) ) . '</p><dl class="certloom-template-metadata"><div><dt>' . esc_html__( 'Page', 'certloom' ) . '</dt><dd>' . esc_html( strtoupper( (string) $item['page_size'] ) . ' · ' . ucfirst( (string) $item['orientation'] ) ) . '</dd></div><div><dt>' . esc_html__( 'Revision', 'certloom' ) . '</dt><dd>' . esc_html( (string) absint( $item['revision_number'] ) ) . '</dd></div><div><dt>' . esc_html__( 'Elements', 'certloom' ) . '</dt><dd>' . esc_html( (string) absint( $item['element_count'] ) ) . '</dd></div><div><dt>' . esc_html__( 'Layout hash', 'certloom' ) . '</dt><dd><code>' . esc_html( substr( (string) $item['layout_sha256'], 0, 12 ) ) . '…</code></dd></div></dl>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="certloom_template_assignment_save"><input type="hidden" name="scope_type" value="' . esc_attr( $scope_type ) . '"><input type="hidden" name="scope_id" value="' . esc_attr( (string) $scope_id ) . '"><input type="hidden" name="template_id" value="' . esc_attr( (string) absint( $item['id'] ) ) . '"><input type="hidden" name="expected_assignment_id" value="' . esc_attr( (string) ( is_array( $direct ) ? absint( $direct['id'] ?? 0 ) : 0 ) ) . '"><input type="hidden" name="expected_updated_at" value="' . esc_attr( (string) ( is_array( $direct ) ? ( $direct['updated_at'] ?? '' ) : '' ) ) . '">';
			wp_nonce_field( 'certloom_template_assignment_' . $scope_type . '_' . $scope_id );
			echo '<button class="button ' . ( $is_direct ? 'button-primary' : '' ) . '" ' . ( $is_direct ? 'disabled aria-disabled="true"' : '' ) . '>' . esc_html( $is_direct ? __( 'Assigned here', 'certloom' ) : __( 'Assign at this scope', 'certloom' ) ) . '</button></form></article>';
		}
		if ( empty( $result['items'] ) ) { echo '<p class="certloom-domain-empty">' . esc_html__( 'No assignable Templates match these filters.', 'certloom' ) . '</p>'; }
		echo '</div>';
		$this->assignment_pagination( array( 'page' => self::PAGE, 'assignment_scope' => $scope_type, 'assignment_scope_id' => $scope_id, 'template_s' => $search, 'template_kind' => $kind ), 'template_paged', $page, (int) $result['total'], 12 );
		echo '</div>';
	}

	/** @return array{items:array<int,array<string,mixed>>,total:int}|\WP_Error */
	private function assignment_target_page( $scope_type, $search, $page, $per_page ) {
		switch ( $scope_type ) {
			case TemplateAssignmentPolicy::SCOPE_ISSUER: return ( new IssuerRepository() )->list_page( $search, $page, $per_page );
			case TemplateAssignmentPolicy::SCOPE_PROGRAM: return ( new ProgramRepository() )->list_page( $search, $page, $per_page, 'all' );
			case TemplateAssignmentPolicy::SCOPE_BATCH: return ( new BatchRepository() )->list_page( $search, $page, $per_page, 'all' );
			default: return array( 'items' => array(), 'total' => 0 );
		}
	}

	/** @return array<string,string>|\WP_Error */
	private function assignment_target( $scope_type, $scope_id ) {
		if ( TemplateAssignmentPolicy::SCOPE_GLOBAL === $scope_type ) { return array( 'label' => __( 'All credentials without a more-specific assignment', 'certloom' ) ); }
		switch ( $scope_type ) {
			case TemplateAssignmentPolicy::SCOPE_ISSUER: $row = ( new IssuerRepository() )->find( $scope_id ); $label = is_array( $row ) ? (string) $row['name'] : ''; break;
			case TemplateAssignmentPolicy::SCOPE_PROGRAM: $row = ( new ProgramRepository() )->find( $scope_id ); $label = is_array( $row ) ? (string) $row['title'] : ''; break;
			case TemplateAssignmentPolicy::SCOPE_BATCH: $row = ( new BatchRepository() )->find( $scope_id ); $label = is_array( $row ) ? (string) $row['name'] : ''; break;
			default: return new \WP_Error( 'certloom_template_assignment_scope_invalid', __( 'Choose a valid template assignment scope.', 'certloom' ) );
		}
		if ( is_wp_error( $row ) ) { return $row; }
		return is_array( $row ) ? array( 'label' => $label ) : new \WP_Error( 'certloom_template_assignment_scope_missing', __( 'The selected template assignment target no longer exists.', 'certloom' ) );
	}

	/** @return array<string,int>|\WP_Error */
	private function assignment_context( $scope_type, $scope_id ) {
		$context = array( 'issuer_id' => 0, 'program_id' => 0, 'batch_id' => 0 );
		if ( TemplateAssignmentPolicy::SCOPE_GLOBAL === $scope_type ) { return $context; }
		if ( TemplateAssignmentPolicy::SCOPE_ISSUER === $scope_type ) { $context['issuer_id'] = absint( $scope_id ); return $context; }
		if ( TemplateAssignmentPolicy::SCOPE_PROGRAM === $scope_type ) {
			$program = ( new ProgramRepository() )->find( $scope_id ); if ( is_wp_error( $program ) || ! is_array( $program ) ) { return is_wp_error( $program ) ? $program : new \WP_Error( 'certloom_template_assignment_scope_missing', __( 'The selected Program no longer exists.', 'certloom' ) ); }
			$context['program_id'] = absint( $program['id'] ); $context['issuer_id'] = absint( $program['issuer_id'] ?? 0 ); return $context;
		}
		$batch = ( new BatchRepository() )->find( $scope_id ); if ( is_wp_error( $batch ) || ! is_array( $batch ) ) { return is_wp_error( $batch ) ? $batch : new \WP_Error( 'certloom_template_assignment_scope_missing', __( 'The selected Batch no longer exists.', 'certloom' ) ); }
		$program = ( new ProgramRepository() )->find( absint( $batch['program_id'] ?? 0 ) ); if ( is_wp_error( $program ) || ! is_array( $program ) ) { return is_wp_error( $program ) ? $program : new \WP_Error( 'certloom_template_assignment_relationship_corrupt', __( 'The selected Batch no longer has a valid Program.', 'certloom' ) ); }
		$context['batch_id'] = absint( $batch['id'] ); $context['program_id'] = absint( $program['id'] ); $context['issuer_id'] = absint( $program['issuer_id'] ?? 0 ); return $context;
	}

	/** @return array{title:string,meta:string} */
	private function assignment_target_list_label( $scope_type, array $item ) {
		if ( TemplateAssignmentPolicy::SCOPE_ISSUER === $scope_type ) { return array( 'title' => (string) $item['name'], 'meta' => (string) ( $item['slug'] ?? '' ) ); }
		if ( TemplateAssignmentPolicy::SCOPE_PROGRAM === $scope_type ) { return array( 'title' => (string) $item['title'], 'meta' => trim( (string) ( $item['code'] ?? '' ) . ( ! empty( $item['issuer_name'] ) ? ' · ' . (string) $item['issuer_name'] : '' ) ) ); }
		return array( 'title' => (string) $item['name'], 'meta' => trim( (string) ( $item['code'] ?? '' ) . ( ! empty( $item['program_title'] ) ? ' · ' . (string) $item['program_title'] : '' ) ) );
	}

	/** @return string */
	private function fallback_explanation( array $context ) {
		$labels = array();
		foreach ( ( new TemplateAssignmentPolicy() )->candidates( $context ) as $candidate ) { $labels[] = TemplateAssignmentPolicy::scope_label( $candidate['scope_type'] ); }
		return implode( ' → ', $labels );
	}

	/** @return array<string,mixed> */
	private function assignment_redirect_args( $scope_type, $scope_id ) {
		return array( 'assignment_scope' => sanitize_key( (string) $scope_type ), 'assignment_scope_id' => absint( $scope_id ) );
	}

	/** @return void */
	private function assignment_pagination( array $args, $paged_key, $page, $total, $per_page ) {
		$total_pages = max( 1, (int) ceil( max( 0, $total ) / max( 1, $per_page ) ) );
		if ( $total_pages <= 1 ) { return; }
		$args[ $paged_key ] = '%#%';
		$base = add_query_arg( array_filter( $args, static function ( $value ) { return null !== $value && '' !== $value; } ), admin_url( 'admin.php' ) );
		echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( paginate_links( array( 'base' => $base, 'format' => '', 'current' => max( 1, absint( $page ) ), 'total' => $total_pages ) ) ) . '</div></div>';
	}

	/** @return void */
	private function template_list_pagination( $page, $total, $per_page, $search ) {
		$this->assignment_pagination( array( 'page' => self::PAGE, 's' => $search ), 'paged', $page, $total, $per_page );
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
		$renderer_authority = ( new RendererAuthorityGate() )->manifest_status();
		echo '<p><a class="button" href="' . esc_url( $export ) . '">' . esc_html__( 'Export template JSON', 'certloom' ) . '</a> ';
		if ( ! empty( $renderer_authority['accepted'] ) ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline"><input type="hidden" name="action" value="certloom_template_publish"><input type="hidden" name="template_id" value="' . esc_attr( (string) $id ) . '"><input type="hidden" name="expected_revision_id" value="' . esc_attr( (string) absint( $editing['current_revision_id'] ) ) . '">';
			wp_nonce_field( 'certloom_template_publish_' . $id );
			echo '<button class="button button-primary">' . esc_html__( 'Publish current revision', 'certloom' ) . '</button></form>';
		} else {
			echo '<button class="button" disabled aria-disabled="true">' . esc_html__( 'Publish (renderer gate)', 'certloom' ) . '</button>';
		}
		echo '</p>';
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
