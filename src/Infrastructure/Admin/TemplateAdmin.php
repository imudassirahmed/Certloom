<?php
namespace Certloom\Infrastructure\Admin;

use Certloom\Application\Rendering\CanonicalJson;
use Certloom\Application\Rendering\LayoutValidator;
use Certloom\Domain\Rendering\ElementRegistry;
use Certloom\Domain\Rendering\LayoutSchema;
use Certloom\Application\Templates\TemplateDefaults;
use Certloom\Application\Templates\FactoryPresetService;
use Certloom\Application\Templates\PresetCatalog;
use Certloom\Application\Templates\TemplateAssignmentPolicy;
use Certloom\Application\Templates\TemplateLifecycle;
use Certloom\Application\Templates\TemplatePreviewSamples;
use Certloom\Application\Templates\TemplateDesignerState;
use Certloom\Infrastructure\Repository\TemplateRepository;
use Certloom\Infrastructure\Repository\IssuerRepository;
use Certloom\Infrastructure\Repository\ProgramRepository;
use Certloom\Infrastructure\Repository\BatchRepository;
use Certloom\Infrastructure\Rendering\RendererAuthorityGate;
use Certloom\Infrastructure\Rendering\TemplatePreviewService;
use Certloom\Infrastructure\Rendering\RenderPlanHtmlPreview;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Template lifecycle + canonical Render Plan Preview administration. Artifact authority remains renderer-gated. */
final class TemplateAdmin {
	const PAGE = 'certloom-templates';

	/** @return void */
	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ), 52 );
		add_action( 'admin_post_certloom_template_create', array( $this, 'create' ) );
		add_action( 'admin_post_certloom_template_metadata', array( $this, 'metadata' ) );
		add_action( 'admin_post_certloom_template_revision', array( $this, 'revision' ) );
		add_action( 'admin_post_certloom_template_layout_upgrade', array( $this, 'layout_upgrade' ) );
		add_action( 'admin_post_certloom_template_publish', array( $this, 'publish' ) );
		add_action( 'admin_post_certloom_template_duplicate', array( $this, 'duplicate' ) );
		add_action( 'admin_post_certloom_template_archive', array( $this, 'archive' ) );
		add_action( 'admin_post_certloom_template_restore', array( $this, 'restore' ) );
		add_action( 'admin_post_certloom_template_import', array( $this, 'import' ) );
		add_action( 'admin_post_certloom_template_export', array( $this, 'export' ) );
		add_action( 'admin_post_certloom_template_presets_sync', array( $this, 'sync_presets' ) );
		add_action( 'admin_post_certloom_template_preset_reset', array( $this, 'reset_preset' ) );
		add_action( 'admin_post_certloom_template_assignment_save', array( $this, 'assignment_save' ) );
		add_action( 'admin_post_certloom_template_assignment_clear', array( $this, 'assignment_clear' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_designer_assets' ) );
		add_action( 'wp_ajax_certloom_template_designer_autosave', array( $this, 'designer_autosave' ) );
		add_action( 'wp_ajax_certloom_template_designer_autosave_discard', array( $this, 'designer_autosave_discard' ) );
	}


	/** Load the Designer interaction layer only on Certloom Templates. @return void */
	public function enqueue_designer_assets( $hook ) {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin routing.
		if ( self::PAGE !== $page && false === strpos( (string) $hook, self::PAGE ) ) { return; }
		wp_enqueue_media();
		wp_enqueue_style( 'certloom-template-designer', CERTLOOM_URL . 'admin/assets/designer.css', array( 'ecapla-certverify-admin' ), CERTLOOM_VERSION );
		wp_enqueue_script( 'certloom-template-designer', CERTLOOM_URL . 'admin/assets/designer.js', array( 'ecapla-certverify-admin' ), CERTLOOM_VERSION, true );
		wp_localize_script(
			'certloom-template-designer',
			'certloomTemplateDesignerI18n',
			array(
				'currentLayoutLoadError' => __( 'The current canonical layout could not be loaded into Designer.', 'certloom' ),
				'designerChangeUndone' => __( 'Designer change undone.', 'certloom' ),
				'designerChangeRedone' => __( 'Designer change redone.', 'certloom' ),
				'unsavedChanges' => __( 'Unsaved changes', 'certloom' ),
				'savedDraft' => __( 'Saved draft', 'certloom' ),
				'elementCountSingular' => __( 'element', 'certloom' ),
				'elementCountPlural' => __( 'elements', 'certloom' ),
				'unlockElement' => __( 'Unlock element', 'certloom' ),
				'lockElement' => __( 'Lock element', 'certloom' ),
				'lockedForAuthor' => __( 'Locked for this author', 'certloom' ),
				'editable' => __( 'Editable', 'certloom' ),
				'qr' => __( 'QR', 'certloom' ),
				'shape' => __( 'Shape', 'certloom' ),
				'line' => __( 'Line', 'certloom' ),
				'image' => __( 'Image', 'certloom' ),
				'logo' => __( 'Logo', 'certloom' ),
				'signature' => __( 'Signature', 'certloom' ),
				'seal' => __( 'Seal', 'certloom' ),
				'rectangle' => __( 'Rectangle', 'certloom' ),
				'ellipse' => __( 'Ellipse', 'certloom' ),
				'list' => __( 'List', 'certloom' ),
				'items' => __( 'items', 'certloom' ),
				'firstListItem' => __( 'First item', 'certloom' ),
				'secondListItem' => __( 'Second item', 'certloom' ),
				'elementRegistryUnavailable' => __( 'The typed Designer Element Registry is unavailable or invalid. Reload the page before editing this Template.', 'certloom' ),
				'elementRequiresSchemaUpgrade' => __( 'This element requires a newer certloom-layout schema. Upgrade the draft before adding it.', 'certloom' ),
				'element' => __( 'Element', 'certloom' ),
				'unlock' => __( 'Unlock', 'certloom' ),
				'lock' => __( 'Lock', 'certloom' ),
				'locked' => __( 'Locked', 'certloom' ),
				'lockedSuffix' => __( 'locked', 'certloom' ),
				'canonicalPageRequired' => __( 'Designer requires a canonical page definition.', 'certloom' ),
				'lockedElementSelected' => __( 'Locked element selected.', 'certloom' ),
				'pageConversionBoundary' => __( 'This page conversion would place an element outside the canonical page bounds. No change was applied.', 'certloom' ),
				'selectedAligned' => __( 'Selected element aligned to page.', 'certloom' ),
				'layerOrderUpdated' => __( 'Layer order updated.', 'certloom' ),
				'elementLocked' => __( 'Element locked for this author.', 'certloom' ),
				'elementUnlocked' => __( 'Element unlocked.', 'certloom' ),
				'elementNudged' => __( 'Element nudged.', 'certloom' ),
				'snappingEnabled' => __( 'Snapping enabled.', 'certloom' ),
				'snappingDisabled' => __( 'Snapping disabled.', 'certloom' ),
				'unsavedChangesReset' => __( 'Unsaved Designer changes reset.', 'certloom' ),
				'geometryBoundary' => __( 'That geometry would violate the canonical page boundary. The previous value was kept.', 'certloom' ),
				'invalidGeometrySave' => __( 'Designer found invalid element geometry and stopped the save.', 'certloom' ),
				'text' => __( 'Text', 'certloom' ),
				'mergeField' => __( 'Merge field', 'certloom' ),
				'background' => __( 'Background', 'certloom' ),
				'elementAdded' => __( 'Element added.', 'certloom' ),
				'elementRemoved' => __( 'Element removed.', 'certloom' ),
				'elementLimit' => __( 'This Template already contains the maximum 500 elements.', 'certloom' ),
				'uuidUnavailable' => __( 'This browser cannot create a secure element identifier. Update the browser before adding elements.', 'certloom' ),
				'assetRequired' => __( 'Choose a local WordPress Media Library image before adding this element.', 'certloom' ),
				'assetUpdated' => __( 'Local media asset updated.', 'certloom' ),
				'contentUpdated' => __( 'Element content updated.', 'certloom' ),
				'invalidMergeToken' => __( 'Merge field tokens must start with a letter and contain only letters, numbers, dot, underscore, colon or hyphen.', 'certloom' ),
				'invalidAssetReference' => __( 'Designer assets must use a local WordPress attachment:ID reference. Controlled asset:key references remain reserved for the future local asset catalog.', 'certloom' ),
				'unsupportedMediaType' => __( 'Certloom Designer currently supports local PNG and JPEG Media Library images only.', 'certloom' ),
				'deleteLockedElement' => __( 'Unlock this element before removing it.', 'certloom' ),
				'chooseMedia' => __( 'Choose local media', 'certloom' ),
				'useMedia' => __( 'Use this media', 'certloom' ),
				'barcodeDeferred' => __( 'Barcode elements require certloom-layout v2. Upgrade this draft first.', 'certloom' ),
				'barcode' => __( 'Barcode', 'certloom' ),
				'styleUpdated' => __( 'Element styling updated.', 'certloom' ),
				'invalidStyleValue' => __( 'That styling value is outside the canonical certloom-layout range. The previous value was kept.', 'certloom' ),
				'invalidColor' => __( 'Colors must use #RRGGBB or #RRGGBBAA.', 'certloom' ),
				'autosaving' => __( 'Saving recovery checkpoint…', 'certloom' ),
				'autosaved' => __( 'Recovery checkpoint saved', 'certloom' ),
				'autosaveFailed' => __( 'Recovery autosave is temporarily unavailable. Manual save remains available.', 'certloom' ),
				'autosaveConflict' => __( 'Autosave stopped because this Template changed in another session. Your browser changes were not overwritten; reload only after preserving anything you still need.', 'certloom' ),
				'autosaveRecovered' => __( 'Recovered unsaved Designer changes from your latest safe checkpoint.', 'certloom' ),
				'autosaveDiscarded' => __( 'Recovery checkpoint discarded.', 'certloom' ),
			)
		);
		wp_localize_script(
			'certloom-template-designer',
			'certloomTemplateElementRegistry',
			ElementRegistry::browser_contract()
		);
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
			$result = ( new TemplateRepository() )->save_revision(
				$id,
				$layout,
				get_current_user_id(),
				isset( $_POST['expected_revision_id'] ) ? absint( $_POST['expected_revision_id'] ) : 0,
				TemplateRepository::SOURCE_MANUAL,
				isset( $_POST['expected_revision_sha256'] ) ? sanitize_text_field( wp_unslash( $_POST['expected_revision_sha256'] ) ) : ''
			);
			if ( ! is_wp_error( $result ) ) {
				if ( isset( $_POST['designer_state_json'] ) ) {
					$state = ( new TemplateDesignerState() )->decode( (string) wp_unslash( $_POST['designer_state_json'] ), $layout );
					update_user_meta( get_current_user_id(), $this->designer_state_key( $id ), wp_json_encode( $state ) );
				}
				if ( isset( $_POST['designer_autosave_session_id'] ) ) { ( new TemplateDesignerAutosave() )->discard( $id, get_current_user_id(), sanitize_text_field( wp_unslash( $_POST['designer_autosave_session_id'] ) ) ); }
			}
		}
		$this->redirect( $result, 'revision_saved', array( 'template_id' => $id ) );
	}


	/** DSN-07 AJAX recovery autosave. @return void */
	public function designer_autosave() {
		$this->require_capability();
		$id = isset( $_POST['template_id'] ) ? absint( $_POST['template_id'] ) : 0;
		check_ajax_referer( 'certloom_template_designer_autosave_' . $id, 'nonce' );
		$raw_layout = isset( $_POST['layout_json'] ) ? wp_unslash( $_POST['layout_json'] ) : '';
		$layout = json_decode( (string) $raw_layout, true );
		$raw_state = isset( $_POST['designer_state_json'] ) ? wp_unslash( $_POST['designer_state_json'] ) : '';
		$state = json_decode( (string) $raw_state, true );
		if ( JSON_ERROR_NONE !== json_last_error() && '' !== trim( (string) $raw_state ) ) { $state = array(); }
		if ( ! is_array( $layout ) ) { wp_send_json_error( array( 'code' => 'certloom_designer_autosave_layout_invalid', 'message' => __( 'Designer autosave received invalid layout JSON.', 'certloom' ) ), 400 ); }
		$result = ( new TemplateDesignerAutosave() )->save(
			$id,
			get_current_user_id(),
			isset( $_POST['expected_revision_id'] ) ? absint( $_POST['expected_revision_id'] ) : 0,
			isset( $_POST['expected_revision_sha256'] ) ? sanitize_text_field( wp_unslash( $_POST['expected_revision_sha256'] ) ) : '',
			isset( $_POST['session_id'] ) ? sanitize_text_field( wp_unslash( $_POST['session_id'] ) ) : '',
			$layout,
			is_array( $state ) ? $state : array()
		);
		if ( is_wp_error( $result ) ) {
			$status = in_array( $result->get_error_code(), array( 'certloom_designer_autosave_conflict', 'certloom_designer_autosave_session_conflict' ), true ) ? 409 : 400;
			wp_send_json_error( array( 'code' => $result->get_error_code(), 'message' => $result->get_error_message(), 'data' => $result->get_error_data() ), $status );
		}
		wp_send_json_success( $result );
	}

	/** DSN-07 discard one personal recovery checkpoint. @return void */
	public function designer_autosave_discard() {
		$this->require_capability();
		$id = isset( $_POST['template_id'] ) ? absint( $_POST['template_id'] ) : 0;
		check_ajax_referer( 'certloom_template_designer_autosave_' . $id, 'nonce' );
		$result = ( new TemplateDesignerAutosave() )->discard( $id, get_current_user_id(), isset( $_POST['session_id'] ) ? sanitize_text_field( wp_unslash( $_POST['session_id'] ) ) : '' );
		if ( is_wp_error( $result ) ) { wp_send_json_error( array( 'code' => $result->get_error_code(), 'message' => $result->get_error_message() ), 409 ); }
		wp_send_json_success( array( 'status' => 'discarded' ) );
	}


	/** @return void */
	public function layout_upgrade() {
		$this->require_capability();
		$id = isset( $_POST['template_id'] ) ? absint( $_POST['template_id'] ) : 0;
		check_admin_referer( 'certloom_template_layout_upgrade_' . $id );
		$result = ( new TemplateRepository() )->upgrade_current_layout(
			$id,
			isset( $_POST['expected_revision_id'] ) ? absint( $_POST['expected_revision_id'] ) : 0,
			isset( $_POST['expected_revision_sha256'] ) ? sanitize_text_field( wp_unslash( $_POST['expected_revision_sha256'] ) ) : '',
			get_current_user_id(),
			LayoutSchema::VERSION
		);
		$this->redirect( $result, 'layout_upgraded', array( 'template_id' => $id ) );
	}

	/** @return void */
	public function publish() {
		$this->require_capability();
		$id = isset( $_POST['template_id'] ) ? absint( $_POST['template_id'] ) : 0;
		check_admin_referer( 'certloom_template_publish_' . $id );
		$result = ( new TemplateRepository() )->publish(
			$id,
			isset( $_POST['expected_revision_id'] ) ? absint( $_POST['expected_revision_id'] ) : 0,
			isset( $_POST['expected_revision_sha256'] ) ? sanitize_text_field( wp_unslash( $_POST['expected_revision_sha256'] ) ) : ''
		);
		$this->redirect( $result, 'published', array( 'template_id' => $id ) );
	}

	/** @return void */
	public function duplicate() {
		$this->require_capability();
		$id = isset( $_POST['template_id'] ) ? absint( $_POST['template_id'] ) : 0;
		check_admin_referer( 'certloom_template_duplicate_' . $id );
		$result = ( new TemplateRepository() )->duplicate(
			$id,
			array(
				'machine_key' => isset( $_POST['machine_key'] ) ? sanitize_key( wp_unslash( $_POST['machine_key'] ) ) : '',
				'name' => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
				'description' => isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '',
			),
			get_current_user_id(),
			isset( $_POST['expected_revision_id'] ) ? absint( $_POST['expected_revision_id'] ) : 0
		);
		$this->redirect( $result, 'duplicated', is_wp_error( $result ) ? array( 'template_id' => $id ) : array( 'template_id' => absint( $result ) ) );
	}

	/** @return void */
	public function archive() {
		$this->require_capability();
		$id = isset( $_POST['template_id'] ) ? absint( $_POST['template_id'] ) : 0;
		check_admin_referer( 'certloom_template_archive_' . $id );
		$result = ( new TemplateRepository() )->archive( $id, isset( $_POST['expected_updated_at'] ) ? sanitize_text_field( wp_unslash( $_POST['expected_updated_at'] ) ) : '' );
		$this->redirect( $result, 'archived', array( 'template_status' => is_wp_error( $result ) ? 'active' : 'archived', 'template_id' => $id ) );
	}

	/** @return void */
	public function restore() {
		$this->require_capability();
		$id = isset( $_POST['template_id'] ) ? absint( $_POST['template_id'] ) : 0;
		check_admin_referer( 'certloom_template_restore_' . $id );
		$result = ( new TemplateRepository() )->restore( $id, isset( $_POST['expected_updated_at'] ) ? sanitize_text_field( wp_unslash( $_POST['expected_updated_at'] ) ) : '' );
		$this->redirect( $result, 'restored', array( 'template_id' => $id ) );
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
		$status = isset( $_GET['template_status'] ) ? sanitize_key( wp_unslash( $_GET['template_status'] ) ) : 'active'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter.
		if ( ! in_array( $status, array( 'active', 'archived', 'all' ), true ) ) { $status = 'active'; }
		$result = $repo->list_page( $search, $page, 20, $status );
		$edit_id = isset( $_GET['template_id'] ) ? absint( $_GET['template_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation.
		$editing = $edit_id ? $repo->find( $edit_id ) : null;
		echo '<div class="wrap certloom-domain-admin certloom-template-admin"><h1>' . esc_html__( 'Templates', 'certloom' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'Templates use immutable revisions and an explicit Draft → Published → Changes pending → Archived lifecycle. Publication remains fail-closed until this exact Certloom release has accepted production-renderer authority.', 'certloom' ) . '</p>';
		$this->notice();
		$this->preset_section();
		$this->assignment_workspace( $repo );
		echo '<div class="certloom-template-list-head"><div><h2>' . esc_html__( 'Template library', 'certloom' ) . '</h2><p class="description">' . esc_html__( 'Archive is non-destructive: revisions and any published history remain intact. Active assignments must be cleared before archival.', 'certloom' ) . '</p></div>';
		echo '<nav class="certloom-template-lifecycle-tabs" aria-label="' . esc_attr__( 'Template lifecycle filter', 'certloom' ) . '">';
		foreach ( array( 'active' => __( 'Active', 'certloom' ), 'archived' => __( 'Archived', 'certloom' ), 'all' => __( 'All', 'certloom' ) ) as $key => $label ) {
			$url = add_query_arg( array( 'page' => self::PAGE, 'template_status' => $key, 's' => $search ?: null ), admin_url( 'admin.php' ) );
			echo '<a class="' . ( $key === $status ? 'is-current' : '' ) . '" ' . ( $key === $status ? 'aria-current="page" ' : '' ) . 'href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</nav></div>';
		if ( is_wp_error( $result ) ) { echo '<div class="notice notice-error"><p>' . esc_html( $result->get_error_message() ) . '</p></div>'; }
		else {
			echo '<form method="get" class="certloom-template-library-search"><input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '"><input type="hidden" name="template_status" value="' . esc_attr( $status ) . '"><label class="screen-reader-text" for="certloom-template-search">' . esc_html__( 'Search templates', 'certloom' ) . '</label><input id="certloom-template-search" type="search" name="s" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr__( 'Search name, key, or description', 'certloom' ) . '"><button class="button">' . esc_html_x( 'Search', 'search action button', 'certloom' ) . '</button></form>';
			echo '<div class="certloom-template-table-wrap"><table class="widefat striped certloom-template-table"><thead><tr><th>' . esc_html__( 'Template', 'certloom' ) . '</th><th>' . esc_html__( 'Machine key', 'certloom' ) . '</th><th>' . esc_html__( 'Draft revision', 'certloom' ) . '</th><th>' . esc_html__( 'Published revision', 'certloom' ) . '</th><th>' . esc_html__( 'Lifecycle', 'certloom' ) . '</th></tr></thead><tbody>';
			foreach ( $result['items'] as $item ) {
				$url = add_query_arg( array( 'page' => self::PAGE, 'template_id' => absint( $item['id'] ), 'template_status' => $status, 's' => $search ?: null ), admin_url( 'admin.php' ) );
				$state = $repo->lifecycle_state( $item );
				echo '<tr><td><strong><a href="' . esc_url( $url ) . '">' . esc_html( (string) $item['name'] ) . '</a></strong><br><span class="description">' . esc_html( (string) ( $item['description'] ?? '' ) ) . '</span></td><td><code>' . esc_html( (string) $item['machine_key'] ) . '</code></td><td>' . esc_html( (string) absint( $item['current_revision_number'] ?? 0 ) ) . '</td><td>' . esc_html( empty( $item['published_revision_number'] ) ? '—' : '#' . absint( $item['published_revision_number'] ) ) . '</td><td>' . $this->lifecycle_badge( $state ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Helper returns escaped fixed markup.
			}
			if ( empty( $result['items'] ) ) { echo '<tr><td colspan="5">' . esc_html__( 'No templates found for this lifecycle filter.', 'certloom' ) . '</td></tr>'; }
			echo '</tbody></table></div>';
			$this->template_list_pagination( $page, (int) $result['total'], 20, $search, $status );
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

		echo '<section class="certloom-template-assignment-workspace"><div class="certloom-template-assignment-head"><div><h2>' . esc_html__( 'Template assignments', 'certloom' ) . '</h2><p>' . esc_html__( 'Choose a direct Template for a scope, or choose None to inherit the next available fallback. Most-specific scope wins: Batch → Program → Issuer → Global.', 'certloom' ) . '</p></div><span class="certloom-domain-preview-badge">' . esc_html__( 'Template candidates · publication-aware', 'certloom' ) . '</span></div>';
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
		echo '<form method="get" class="certloom-domain-search"><input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '"><input type="hidden" name="assignment_scope" value="' . esc_attr( $scope_type ) . '"><label class="screen-reader-text" for="certloom-template-scope-search">' . esc_html__( 'Search assignment targets', 'certloom' ) . '</label><input id="certloom-template-scope-search" type="search" name="scope_s" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr__( 'Search name or code', 'certloom' ) . '"><button class="button">' . esc_html_x( 'Search', 'search action button', 'certloom' ) . '</button></form>';
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
		echo '<div class="certloom-template-candidates"><div class="certloom-template-candidates-head"><div><h3>' . esc_html__( 'Choose a Template', 'certloom' ) . '</h3><p>' . esc_html__( 'This card is assignment metadata only—not the canonical Render Plan Preview. Open a Template to inspect Current or Published Preview. Production artifact authority remains renderer-gated.', 'certloom' ) . '</p></div></div>';
		echo '<form method="get" class="certloom-template-candidate-filters"><input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '"><input type="hidden" name="assignment_scope" value="' . esc_attr( $scope_type ) . '"><input type="hidden" name="assignment_scope_id" value="' . esc_attr( (string) $scope_id ) . '"><label class="screen-reader-text" for="certloom-template-candidate-search">' . esc_html__( 'Search assignable Templates', 'certloom' ) . '</label><input id="certloom-template-candidate-search" type="search" name="template_s" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr__( 'Search Template name, key, or description', 'certloom' ) . '"><label class="screen-reader-text" for="certloom-template-kind">' . esc_html__( 'Template kind', 'certloom' ) . '</label><select id="certloom-template-kind" name="template_kind"><option value="all" ' . selected( $kind, 'all', false ) . '>' . esc_html__( 'All Templates', 'certloom' ) . '</option><option value="factory" ' . selected( $kind, 'factory', false ) . '>' . esc_html__( 'Factory', 'certloom' ) . '</option><option value="custom" ' . selected( $kind, 'custom', false ) . '>' . esc_html__( 'Custom / imported', 'certloom' ) . '</option></select><button class="button">' . esc_html__( 'Filter', 'certloom' ) . '</button></form>';
		if ( is_wp_error( $result ) ) { echo '<div class="notice notice-error inline"><p>' . esc_html( $result->get_error_message() ) . '</p></div></div>'; return; }
		echo '<div class="certloom-template-candidate-grid">';
		foreach ( $result['items'] as $item ) {
			$is_direct = is_array( $direct ) && ! empty( $direct['active'] ) && absint( $direct['template_id'] ?? 0 ) === absint( $item['id'] );
			$is_effective = is_array( $effective ) && absint( $effective['template_id'] ?? 0 ) === absint( $item['id'] );
			echo '<article class="certloom-template-candidate ' . ( $is_direct ? 'is-assigned' : '' ) . '"><div class="certloom-template-candidate-title"><div><span class="certloom-template-kind">' . esc_html( ! empty( $item['factory'] ) ? __( 'Factory', 'certloom' ) : __( 'Custom', 'certloom' ) ) . '</span><h4>' . esc_html( (string) $item['name'] ) . '</h4><code>' . esc_html( (string) $item['machine_key'] ) . '</code></div><div class="certloom-template-candidate-badges">' . $this->lifecycle_badge( (string) ( $item['lifecycle_state'] ?? TemplateLifecycle::DRAFT ) ) . ( $is_effective ? '<span class="certloom-domain-preview-badge">' . esc_html__( 'Effective', 'certloom' ) . '</span>' : '' ) . '</div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Helper returns escaped fixed markup.
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
	private function template_list_pagination( $page, $total, $per_page, $search, $status = 'active' ) {
		$this->assignment_pagination( array( 'page' => self::PAGE, 's' => $search, 'template_status' => $status ), 'paged', $page, $total, $per_page );
	}

	/** @return void */
	private function editor( $editing ) {
		if ( is_wp_error( $editing ) ) { echo '<div class="notice notice-error"><p>' . esc_html( $editing->get_error_message() ) . '</p></div>'; return; }
		if ( ! is_array( $editing ) ) {
			echo '<section class="certloom-template-editor"><h2>' . esc_html__( 'Create template draft', 'certloom' ) . '</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="certloom_template_create">'; wp_nonce_field( 'certloom_template_create' );
			$this->identity_fields( array() ); submit_button( __( 'Create template', 'certloom' ) ); echo '</form></section>'; return;
		}
		$id = absint( $editing['id'] );
		$state = (string) ( $editing['lifecycle_state'] ?? TemplateLifecycle::state( $editing ) );
		$current = $editing['current_revision'] ?? array();
		$published = $editing['published_revision'] ?? array();
		echo '<section class="certloom-template-editor"><div class="certloom-template-editor-head"><div><h2>' . esc_html__( 'Template lifecycle & draft', 'certloom' ) . '</h2><p><strong>' . esc_html( (string) $editing['name'] ) . '</strong> <code>' . esc_html( (string) $editing['machine_key'] ) . '</code></p></div>' . $this->lifecycle_badge( $state ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Helper returns escaped fixed markup.
		echo '<div class="certloom-template-lifecycle-summary"><div><span>' . esc_html__( 'Current revision', 'certloom' ) . '</span><strong>#' . esc_html( (string) absint( $editing['current_revision_id'] ?? 0 ) ) . '</strong><small><code>' . esc_html( substr( (string) ( $current['layout_sha256'] ?? '' ), 0, 12 ) ) . ( ! empty( $current['layout_sha256'] ) ? '…' : '' ) . '</code></small></div><div><span>' . esc_html__( 'Published revision', 'certloom' ) . '</span><strong>' . esc_html( empty( $editing['published_revision_id'] ) ? __( 'None', 'certloom' ) : '#' . absint( $editing['published_revision_id'] ) ) . '</strong><small><code>' . esc_html( substr( (string) ( $published['layout_sha256'] ?? '' ), 0, 12 ) ) . ( ! empty( $published['layout_sha256'] ) ? '…' : '' ) . '</code></small></div><div><span>' . esc_html__( 'Archive state', 'certloom' ) . '</span><strong>' . esc_html( empty( $editing['archived_at'] ) ? __( 'Active', 'certloom' ) : __( 'Archived', 'certloom' ) ) . '</strong><small>' . esc_html( (string) ( $editing['archived_at'] ?? '' ) ) . '</small></div></div>';

		$export = wp_nonce_url( add_query_arg( array( 'action' => 'certloom_template_export', 'template_id' => $id ), admin_url( 'admin-post.php' ) ), 'certloom_template_export_' . $id );
		echo '<div class="certloom-template-actionbar"><a class="button" href="' . esc_url( $export ) . '">' . esc_html__( 'Export template JSON', 'certloom' ) . '</a>';
		if ( TemplateLifecycle::ARCHIVED === $state ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="certloom_template_restore"><input type="hidden" name="template_id" value="' . esc_attr( (string) $id ) . '"><input type="hidden" name="expected_updated_at" value="' . esc_attr( (string) $editing['updated_at'] ) . '">'; wp_nonce_field( 'certloom_template_restore_' . $id ); echo '<button class="button button-primary">' . esc_html__( 'Restore template', 'certloom' ) . '</button></form>';
		} else {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="certloom_template_archive"><input type="hidden" name="template_id" value="' . esc_attr( (string) $id ) . '"><input type="hidden" name="expected_updated_at" value="' . esc_attr( (string) $editing['updated_at'] ) . '">'; wp_nonce_field( 'certloom_template_archive_' . $id ); echo '<button class="button certloom-button-danger">' . esc_html__( 'Archive template', 'certloom' ) . '</button></form>';
		}
		echo '</div>';

		$this->duplicate_form( $editing );
		$this->preview_panel( $editing );
		if ( TemplateLifecycle::ARCHIVED === $state ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Archived templates are read-only. Their immutable revision history and published revision remain preserved. Restore this template before changing metadata, saving a new draft, publishing, or resetting a factory preset.', 'certloom' ) . '</p></div></section>';
			return;
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="certloom_template_metadata"><input type="hidden" name="template_id" value="' . esc_attr( (string) $id ) . '"><input type="hidden" name="expected_updated_at" value="' . esc_attr( (string) $editing['updated_at'] ) . '">'; wp_nonce_field( 'certloom_template_metadata_' . $id ); $this->identity_fields( $editing ); submit_button( __( 'Save metadata', 'certloom' ) ); echo '</form>';
		try { $layout_json = ( new CanonicalJson() )->encode( $current['layout'] ?? TemplateDefaults::blank_a4_landscape() ); } catch ( \Throwable $e ) { $layout_json = ''; }
		$this->schema_upgrade_panel( $editing );
		$this->designer_panel( $editing, $layout_json );
		echo '<details class="certloom-template-json-editor"><summary>' . esc_html__( 'Advanced canonical JSON editor', 'certloom' ) . '</summary><p class="description">' . esc_html__( 'The Designer and this advanced editor save the same certloom-layout document. Every successful save creates a new immutable revision; there is no separate browser-only design format.', 'certloom' ) . '</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="certloom_template_revision"><input type="hidden" name="template_id" value="' . esc_attr( (string) $id ) . '"><input type="hidden" name="expected_revision_id" value="' . esc_attr( (string) absint( $editing['current_revision_id'] ) ) . '"><input type="hidden" name="expected_revision_sha256" value="' . esc_attr( (string) ( $current['layout_sha256'] ?? '' ) ) . '">'; wp_nonce_field( 'certloom_template_revision_' . $id );
		echo '<textarea name="layout_json" rows="18" class="large-text code" spellcheck="false">' . esc_textarea( $layout_json ) . '</textarea>'; submit_button( __( 'Save JSON as new revision', 'certloom' ) ); echo '</form></details>';

		$renderer_authority = ( new RendererAuthorityGate() )->publication_status();
		echo '<div class="certloom-template-publication-card"><div><h3>' . esc_html__( 'Publication review', 'certloom' ) . '</h3><p>' . esc_html__( 'Publication is bound to the exact immutable revision ID and SHA-256 shown here. A stale revision or hash is rejected even after renderer authority is accepted.', 'certloom' ) . '</p><p><code>#' . esc_html( (string) absint( $editing['current_revision_id'] ) ) . ' · ' . esc_html( (string) ( $current['layout_sha256'] ?? '' ) ) . '</code></p></div>';
		if ( ! empty( $renderer_authority['accepted'] ) && ! empty( $current['layout_sha256'] ) ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="certloom_template_publish"><input type="hidden" name="template_id" value="' . esc_attr( (string) $id ) . '"><input type="hidden" name="expected_revision_id" value="' . esc_attr( (string) absint( $editing['current_revision_id'] ) ) . '"><input type="hidden" name="expected_revision_sha256" value="' . esc_attr( (string) $current['layout_sha256'] ) . '">'; wp_nonce_field( 'certloom_template_publish_' . $id ); echo '<button class="button button-primary">' . esc_html__( 'Publish reviewed revision', 'certloom' ) . '</button></form>';
		} else {
			echo '<div><button class="button" disabled aria-disabled="true">' . esc_html__( 'Publish (renderer gate)', 'certloom' ) . '</button><p class="description">' . esc_html__( 'The lifecycle workflow is ready, but this release intentionally cannot publish until PDF-04 renderer authority is accepted and packaged.', 'certloom' ) . '</p></div>';
		}
		echo '</div>';

		if ( 0 === strpos( (string) ( $editing['machine_key'] ?? '' ), 'certloom-preset-' ) ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="certloom_template_preset_reset"><input type="hidden" name="template_id" value="' . esc_attr( (string) $id ) . '"><input type="hidden" name="expected_revision_id" value="' . esc_attr( (string) absint( $editing['current_revision_id'] ) ) . '">';
			wp_nonce_field( 'certloom_template_preset_reset_' . $id );
			echo '<p class="description">' . esc_html__( 'Restoring a factory design creates another immutable revision. Your current customized revision and any published revision remain in history.', 'certloom' ) . '</p>';
			submit_button( __( 'Restore current factory design', 'certloom' ), 'secondary', 'submit', false );
			echo '</form>';
		}
		echo '</section>';
	}


	/** Explicit immutable revision upgrade boundary to the current authoring schema. @return void */
	private function schema_upgrade_panel( array $editing ) {
		$current = isset( $editing['current_revision'] ) && is_array( $editing['current_revision'] ) ? $editing['current_revision'] : array();
		$layout = isset( $current['layout'] ) && is_array( $current['layout'] ) ? $current['layout'] : array();
		$version = absint( $layout['_meta']['schema_version'] ?? 0 );
		if ( $version < 1 ) { return; }
		echo '<div class="certloom-template-publication-card certloom-template-schema-card"><div><h3>' . esc_html__( 'Layout schema', 'certloom' ) . '</h3><p>' . esc_html( sprintf( __( 'Current draft uses certloom-layout v%d. Historical revisions are immutable and remain readable.', 'certloom' ), $version ) ) . '</p></div>';
		if ( $version < LayoutSchema::VERSION ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="certloom_template_layout_upgrade"><input type="hidden" name="template_id" value="' . esc_attr( (string) absint( $editing['id'] ?? 0 ) ) . '"><input type="hidden" name="expected_revision_id" value="' . esc_attr( (string) absint( $editing['current_revision_id'] ?? 0 ) ) . '"><input type="hidden" name="expected_revision_sha256" value="' . esc_attr( (string) ( $current['layout_sha256'] ?? '' ) ) . '">';
			wp_nonce_field( 'certloom_template_layout_upgrade_' . absint( $editing['id'] ?? 0 ) );
			echo '<button class="button button-primary">' . esc_html( sprintf( __( 'Upgrade draft to layout schema v%d', 'certloom' ), LayoutSchema::VERSION ) ) . '</button><p class="description">' . esc_html__( 'Creates one new immutable revision after rechecking this exact revision ID and SHA-256. Certloom applies every deterministic intermediate schema step internally; historical revisions and factory presets are never rewritten.', 'certloom' ) . '</p></form>';
		} else {
			echo '<div><span class="certloom-template-preview-authority is-accepted">' . esc_html( sprintf( __( 'v%d authoring enabled', 'certloom' ), $version ) ) . '</span><p class="description">' . esc_html__( 'This draft can author the controlled Code 128 primitive and DSN-06 canonical list elements while older schema revisions remain immutable.', 'certloom' ) . '</p></div>';
		}
		echo '</div>';
	}


	/** DSN-07 visual Designer with bounded per-user recovery autosave. @return void */
	private function designer_panel( array $editing, $layout_json ) {
		$id = absint( $editing['id'] ?? 0 );
		$current_id = absint( $editing['current_revision_id'] ?? 0 );
		if ( $id < 1 || $current_id < 1 || '' === (string) $layout_json ) { return; }
		$decoded_layout = json_decode( (string) $layout_json, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded_layout ) ) { return; }
		$stored_state = get_user_meta( get_current_user_id(), $this->designer_state_key( $id ), true );
		$designer_state = ( new TemplateDesignerState() )->decode( is_string( $stored_state ) ? $stored_state : '', $decoded_layout );
		$schema_version = absint( $decoded_layout['_meta']['schema_version'] ?? 0 );
		$designer_state_json = wp_json_encode( $designer_state );
		if ( ! is_string( $designer_state_json ) ) { $designer_state_json = '{"version":1,"locked_ids":[],"snap_enabled":true}'; }
		$current_sha256 = strtolower( (string) ( $editing['current_revision']['layout_sha256'] ?? '' ) );
		$autosave = ( new TemplateDesignerAutosave() )->inspect( $id, get_current_user_id() );
		$autosave_status = (string) ( $autosave['status'] ?? 'none' );
		$recovery_layout_json = '';
		$recovery_state_json = '';
		if ( 'ready' === $autosave_status && ! empty( $autosave['record']['layout'] ) && is_array( $autosave['record']['layout'] ) ) {
			try { $recovery_layout_json = ( new CanonicalJson() )->encode( $autosave['record']['layout'] ); } catch ( \Throwable $error ) { $recovery_layout_json = ''; }
			$recovery_state_json = wp_json_encode( $autosave['record']['designer_state'] ?? array() );
			if ( ! is_string( $recovery_state_json ) ) { $recovery_state_json = ''; }
		}
		$autosave_nonce = wp_create_nonce( 'certloom_template_designer_autosave_' . $id );
		$autosave_session = in_array( $autosave_status, array( 'ready', 'stale' ), true ) ? (string) ( $autosave['record']['session_id'] ?? '' ) : '';

		echo '<section class="certloom-template-designer" data-certloom-designer data-layout-schema-version="' . esc_attr( (string) $schema_version ) . '" data-template-id="' . esc_attr( (string) $id ) . '" data-saved-revision="' . esc_attr( (string) $current_id ) . '" data-saved-sha256="' . esc_attr( $current_sha256 ) . '" data-autosave-status="' . esc_attr( $autosave_status ) . '" data-autosave-session="' . esc_attr( $autosave_session ) . '" data-autosave-nonce="' . esc_attr( $autosave_nonce ) . '" data-ajax-url="' . esc_url( admin_url( 'admin-ajax.php' ) ) . '">';
		echo '<div class="certloom-template-designer-head"><div><span class="certloom-template-designer-kicker">' . esc_html__( 'DSN-08 · Typed Element Registry', 'certloom' ) . '</span><h3>' . esc_html__( 'Certificate Designer', 'certloom' ) . '</h3><p>' . esc_html__( 'Edit the same versioned canonical layout used by Preview and rendering. DSN-08 centralizes every supported element type behind one closed typed registry for schema availability, allowed properties, inspector groups, authoring defaults, merge requirements and renderer mapping. Recovery autosave remains non-authoritative and manual save remains the only immutable revision write.', 'certloom' ) . '</p></div><div class="certloom-template-designer-state"><span data-certloom-designer-dirty>' . esc_html__( 'Saved draft', 'certloom' ) . '</span><small>' . esc_html( sprintf( __( 'Revision ID %d', 'certloom' ), $current_id ) ) . '</small></div></div>';
		echo '<div class="notice notice-info inline certloom-template-designer-boundary"><p><strong>' . esc_html__( 'Designer boundary:', 'certloom' ) . '</strong> ' . esc_html__( 'DSN-08 is a closed built-in registry: no WordPress filter, arbitrary HTML/CSS callback or unversioned third-party type can mutate the canonical element contract. Future extensions must first earn an explicit layout-schema version plus validator/resolver/renderer support. Manual save and publication authority remain unchanged.', 'certloom' ) . '</p></div>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-certloom-designer-form><input type="hidden" name="action" value="certloom_template_revision"><input type="hidden" name="template_id" value="' . esc_attr( (string) $id ) . '"><input type="hidden" name="expected_revision_id" value="' . esc_attr( (string) $current_id ) . '"><input type="hidden" name="expected_revision_sha256" value="' . esc_attr( $current_sha256 ) . '">';
		wp_nonce_field( 'certloom_template_revision_' . $id );
		echo '<textarea hidden name="layout_json" data-certloom-designer-json>' . esc_textarea( (string) $layout_json ) . '</textarea>';
		echo '<textarea hidden name="designer_state_json" data-certloom-designer-state-json>' . esc_textarea( $designer_state_json ) . '</textarea>';
		echo '<input type="hidden" name="designer_autosave_session_id" value="" data-certloom-designer-autosave-session-field>';
		if ( '' !== $recovery_layout_json ) { echo '<textarea hidden data-certloom-designer-recovery-json>' . esc_textarea( $recovery_layout_json ) . '</textarea><textarea hidden data-certloom-designer-recovery-state-json>' . esc_textarea( $recovery_state_json ) . '</textarea>'; }
		if ( 'stale' === $autosave_status || 'invalid' === $autosave_status ) { echo '<div class="notice notice-warning inline certloom-template-autosave-warning"><p><strong>' . esc_html__( 'Recovery checkpoint not applied.', 'certloom' ) . '</strong> ' . esc_html__( 'The stored personal autosave is stale or invalid, so Certloom did not merge it into the authoritative current revision. Discard it after confirming you no longer need the older recovery copy.', 'certloom' ) . ' <button type="button" class="button button-small" data-certloom-designer-autosave-discard>' . esc_html__( 'Discard recovery checkpoint', 'certloom' ) . '</button></p></div>'; }
		echo '<div class="certloom-template-designer-toolbar" role="group" aria-label="' . esc_attr__( 'Designer page, history and zoom controls', 'certloom' ) . '">';
		echo '<div class="certloom-template-designer-history" role="group" aria-label="' . esc_attr__( 'Designer history', 'certloom' ) . '"><button type="button" class="button" data-certloom-designer-undo disabled>' . esc_html__( 'Undo', 'certloom' ) . '</button><button type="button" class="button" data-certloom-designer-redo disabled>' . esc_html__( 'Redo', 'certloom' ) . '</button></div>';
		echo '<label><span>' . esc_html__( 'Page size', 'certloom' ) . '</span><select data-certloom-designer-page-size><option value="A4">A4</option><option value="Letter">' . esc_html__( 'Letter', 'certloom' ) . '</option></select></label>';
		echo '<label><span>' . esc_html__( 'Orientation', 'certloom' ) . '</span><select data-certloom-designer-orientation><option value="landscape">' . esc_html__( 'Landscape', 'certloom' ) . '</option><option value="portrait">' . esc_html__( 'Portrait', 'certloom' ) . '</option></select></label>';
		echo '<label class="certloom-template-designer-zoom"><span>' . esc_html__( 'Zoom', 'certloom' ) . '</span><input type="range" min="25" max="200" step="5" value="75" data-certloom-designer-zoom><output data-certloom-designer-zoom-output>75%</output></label>';
		echo '<label class="certloom-template-designer-snap"><span>' . esc_html__( 'Guides', 'certloom' ) . '</span><span class="certloom-template-designer-check"><input type="checkbox" value="1" data-certloom-designer-snap> ' . esc_html__( 'Snap while moving', 'certloom' ) . '</span></label>';
		echo '<button type="button" class="button" data-certloom-designer-fit>' . esc_html__( 'Fit workspace', 'certloom' ) . '</button><button type="button" class="button" data-certloom-designer-reset>' . esc_html__( 'Reset unsaved changes', 'certloom' ) . '</button>';
		echo '</div>';
		echo '<div class="certloom-template-designer-productivity" role="toolbar" aria-label="' . esc_attr__( 'Selected element alignment and layer controls', 'certloom' ) . '"><span class="certloom-template-designer-productivity-label">' . esc_html__( 'Align to page', 'certloom' ) . '</span>';
		foreach ( array( 'left' => __( 'Left', 'certloom' ), 'center-x' => __( 'Center horizontally', 'certloom' ), 'right' => __( 'Right', 'certloom' ), 'top' => __( 'Top', 'certloom' ), 'center-y' => __( 'Center vertically', 'certloom' ), 'bottom' => __( 'Bottom', 'certloom' ) ) as $mode => $label ) {
			echo '<button type="button" class="button button-small" data-certloom-designer-align="' . esc_attr( $mode ) . '" disabled>' . esc_html( $label ) . '</button>';
		}
		echo '<span class="certloom-template-designer-productivity-label">' . esc_html__( 'Layer', 'certloom' ) . '</span>';
		foreach ( array( 'front' => __( 'Bring to front', 'certloom' ), 'forward' => __( 'Bring forward', 'certloom' ), 'backward' => __( 'Send backward', 'certloom' ), 'back' => __( 'Send to back', 'certloom' ) ) as $mode => $label ) {
			echo '<button type="button" class="button button-small" data-certloom-designer-layer-action="' . esc_attr( $mode ) . '" disabled>' . esc_html( $label ) . '</button>';
		}
		echo '<button type="button" class="button button-small" data-certloom-designer-lock disabled aria-pressed="false">' . esc_html__( 'Lock element', 'certloom' ) . '</button></div>';
		echo '<div class="certloom-template-designer-palette" role="toolbar" aria-label="' . esc_attr__( 'Add or remove Designer elements', 'certloom' ) . '"><span class="certloom-template-designer-productivity-label">' . esc_html__( 'Add', 'certloom' ) . '</span>';
		$registry_labels = $this->designer_registry_labels();
		foreach ( ElementRegistry::palette() as $palette_item ) {
			$kind = (string) $palette_item['kind'];
			$minimum = absint( $palette_item['min_schema_version'] ?? 1 );
			$label_key = (string) ( $palette_item['label_key'] ?? $kind );
			$label = isset( $registry_labels[ $label_key ] ) ? $registry_labels[ $label_key ] : $kind;
			$disabled = $schema_version < $minimum;
			$title = $disabled ? sprintf( __( 'Upgrade this draft to certloom-layout v%d before adding this element.', 'certloom' ), $minimum ) : '';
			echo '<button type="button" class="button button-small" data-certloom-designer-add="' . esc_attr( $kind ) . '" ' . ( $disabled ? 'disabled aria-disabled="true" title="' . esc_attr( $title ) . '"' : '' ) . '>' . esc_html( $label ) . '</button>';
		}
		echo '<span class="certloom-template-designer-palette-spacer"></span><button type="button" class="button button-small certloom-button-danger" data-certloom-designer-remove disabled>' . esc_html__( 'Remove selected', 'certloom' ) . '</button></div>';
		echo '<div class="certloom-template-designer-shell"><div class="certloom-template-designer-workspace" data-certloom-designer-workspace tabindex="0" aria-label="' . esc_attr__( 'Certificate Designer canvas workspace', 'certloom' ) . '"><div class="certloom-template-designer-page" data-certloom-designer-page role="application" aria-label="' . esc_attr__( 'Certificate page canvas', 'certloom' ) . '"></div></div>';
		echo '<aside class="certloom-template-designer-inspector" aria-label="' . esc_attr__( 'Designer inspector and layers', 'certloom' ) . '"><div data-certloom-designer-empty><h4>' . esc_html__( 'Element geometry', 'certloom' ) . '</h4><p>' . esc_html__( 'Select an element on the page or in Layers to edit its canonical content, geometry and productivity controls.', 'certloom' ) . '</p></div><div hidden data-certloom-designer-fields><div class="certloom-template-designer-element-meta"><span data-certloom-designer-type></span><code data-certloom-designer-id></code><em data-certloom-designer-lock-status></em></div><div class="certloom-template-designer-geometry-grid">';
		foreach ( array( 'x' => __( 'X (mm)', 'certloom' ), 'y' => __( 'Y (mm)', 'certloom' ), 'width' => __( 'Width (mm)', 'certloom' ), 'height' => __( 'Height (mm)', 'certloom' ), 'rotation' => __( 'Rotation (°)', 'certloom' ) ) as $key => $label ) {
			echo '<label><span>' . esc_html( $label ) . '</span><input type="number" step="any" inputmode="decimal" data-certloom-designer-field="' . esc_attr( $key ) . '"></label>';
		}
		echo '</div><div class="certloom-template-designer-content-editor" data-certloom-designer-content-editor>';
		echo '<div hidden data-certloom-designer-content="text"><label><span>' . esc_html__( 'Text content', 'certloom' ) . '</span><textarea rows="4" maxlength="20000" data-certloom-designer-content-field="text"></textarea></label></div>';
		echo '<div hidden data-certloom-designer-content="merge"><label><span>' . esc_html__( 'Merge field token', 'certloom' ) . '</span><input type="text" maxlength="128" pattern="[a-z][a-z0-9_.:-]{0,127}" data-certloom-designer-content-field="token" list="certloom-designer-merge-tokens"></label><datalist id="certloom-designer-merge-tokens"><option value="recipient.name"><option value="recipient.first_name"><option value="recipient.last_name"><option value="program.name"><option value="program.title"><option value="issuer.name"><option value="credential.display_number"><option value="credential.public_id"><option value="credential.issue_date"><option value="credential.expiry_date"><option value="credential.status"><option value="batch.name"></datalist></div>';
		echo '<div hidden data-certloom-designer-content="asset"><label><span>' . esc_html__( 'Local asset reference', 'certloom' ) . '</span><input type="text" maxlength="150" data-certloom-designer-content-field="asset" placeholder="attachment:123"></label><label><span>' . esc_html__( 'Fit', 'certloom' ) . '</span><select data-certloom-designer-content-field="fit"><option value="contain">' . esc_html__( 'Contain', 'certloom' ) . '</option><option value="cover">' . esc_html__( 'Cover', 'certloom' ) . '</option><option value="fill">' . esc_html__( 'Fill', 'certloom' ) . '</option></select></label><button type="button" class="button" data-certloom-designer-media>' . esc_html__( 'Choose from Media Library', 'certloom' ) . '</button></div>';
		echo '<div hidden data-certloom-designer-content="shape"><label><span>' . esc_html__( 'Shape', 'certloom' ) . '</span><select data-certloom-designer-content-field="shape"><option value="rectangle">' . esc_html__( 'Rectangle', 'certloom' ) . '</option><option value="ellipse">' . esc_html__( 'Ellipse', 'certloom' ) . '</option></select></label></div>';
		echo '<div hidden data-certloom-designer-content="qr"><label><span>' . esc_html__( 'Error correction', 'certloom' ) . '</span><select data-certloom-designer-content-field="error_correction"><option value="L">L</option><option value="M">M</option><option value="Q">Q</option><option value="H">H</option></select></label><label><span>' . esc_html__( 'Quiet zone (modules)', 'certloom' ) . '</span><input type="number" min="4" max="16" step="1" data-certloom-designer-content-field="quiet_zone_modules"></label><p class="description">' . esc_html__( 'QR source remains the canonical verification URL in both supported layout schemas.', 'certloom' ) . '</p></div>';
		echo '<div hidden data-certloom-designer-content="barcode"><label><span>' . esc_html__( 'Symbology', 'certloom' ) . '</span><input type="text" value="Code 128" readonly aria-readonly="true"></label><label><span>' . esc_html__( 'Source', 'certloom' ) . '</span><input type="text" value="credential.display_number" readonly aria-readonly="true"></label><label><span>' . esc_html__( 'Quiet zone (modules)', 'certloom' ) . '</span><input type="number" min="10" max="50" step="1" data-certloom-designer-content-field="quiet_zone_modules"></label><p class="description">' . esc_html__( 'Code 128 represents the existing human certificate number only. It creates no alternate credential identity or lookup endpoint.', 'certloom' ) . '</p></div>';
		echo '<div hidden data-certloom-designer-content="list"><label><span>' . esc_html__( 'List items', 'certloom' ) . '</span><textarea rows="6" maxlength="20000" data-certloom-designer-content-field="items" placeholder="First item&#10;Second item"></textarea></label><label><span>' . esc_html__( 'Marker style', 'certloom' ) . '</span><select data-certloom-designer-content-field="list_style"><option value="bullet">' . esc_html__( 'Bullets', 'certloom' ) . '</option><option value="decimal">' . esc_html__( 'Numbered', 'certloom' ) . '</option></select></label><p class="description">' . esc_html__( 'Each non-empty line becomes one canonical list item. Rendering resolves the list to the shared text pipeline, so Preview/PDF/image adapters do not invent separate list geometry.', 'certloom' ) . '</p></div>';
		echo '<div hidden data-certloom-designer-content="line"><p class="description">' . esc_html__( 'Line content is geometric; its canonical stroke styling is edited below.', 'certloom' ) . '</p></div></div>';
		echo '<div class="certloom-template-designer-style-editor" data-certloom-designer-style-editor><div class="certloom-template-designer-style-head"><h4>' . esc_html__( 'Appearance', 'certloom' ) . '</h4><span>' . esc_html__( 'Canonical DSN-08', 'certloom' ) . '</span></div>';
		echo '<div class="certloom-template-designer-style-grid" data-certloom-designer-style="general"><label><span>' . esc_html__( 'Opacity', 'certloom' ) . '</span><input type="number" min="0" max="1" step="0.05" inputmode="decimal" data-certloom-designer-style-field="opacity"></label></div>';
		echo '<div class="certloom-template-designer-style-group" hidden data-certloom-designer-style="text"><div class="certloom-template-designer-style-grid"><label><span>' . esc_html__( 'Font key', 'certloom' ) . '</span><input type="text" data-certloom-designer-style-field="font_family" readonly aria-readonly="true"></label><label><span>' . esc_html__( 'Size (pt)', 'certloom' ) . '</span><input type="number" min="1" max="300" step="0.5" inputmode="decimal" data-certloom-designer-style-field="font_size_pt"></label><label><span>' . esc_html__( 'Weight', 'certloom' ) . '</span><select data-certloom-designer-style-field="font_weight">';
		foreach ( range( 100, 900, 100 ) as $weight ) { echo '<option value="' . esc_attr( (string) $weight ) . '">' . esc_html( (string) $weight ) . '</option>'; }
		echo '</select></label><label class="certloom-template-designer-style-check"><span>' . esc_html__( 'Italic', 'certloom' ) . '</span><input type="checkbox" value="1" data-certloom-designer-style-field="italic"></label><label><span>' . esc_html__( 'Text color', 'certloom' ) . '</span><input type="text" maxlength="9" pattern="#[0-9A-Fa-f]{6}([0-9A-Fa-f]{2})?" placeholder="#000000" data-certloom-designer-style-field="color"></label><label><span>' . esc_html__( 'Horizontal align', 'certloom' ) . '</span><select data-certloom-designer-style-field="align"><option value="left">' . esc_html__( 'Left', 'certloom' ) . '</option><option value="center">' . esc_html__( 'Center', 'certloom' ) . '</option><option value="right">' . esc_html__( 'Right', 'certloom' ) . '</option><option value="start">' . esc_html__( 'Start', 'certloom' ) . '</option><option value="end">' . esc_html__( 'End', 'certloom' ) . '</option></select></label><label><span>' . esc_html__( 'Vertical align', 'certloom' ) . '</span><select data-certloom-designer-style-field="vertical_align"><option value="top">' . esc_html__( 'Top', 'certloom' ) . '</option><option value="middle">' . esc_html__( 'Middle', 'certloom' ) . '</option><option value="bottom">' . esc_html__( 'Bottom', 'certloom' ) . '</option></select></label><label><span>' . esc_html__( 'Direction', 'certloom' ) . '</span><select data-certloom-designer-style-field="direction"><option value="auto">' . esc_html__( 'Auto', 'certloom' ) . '</option><option value="ltr">LTR</option><option value="rtl">RTL</option></select></label><label><span>' . esc_html__( 'Line height', 'certloom' ) . '</span><input type="number" min="0.5" max="5" step="0.05" inputmode="decimal" data-certloom-designer-style-field="line_height"></label><label><span>' . esc_html__( 'Letter spacing (pt)', 'certloom' ) . '</span><input type="number" min="-20" max="50" step="0.1" inputmode="decimal" data-certloom-designer-style-field="letter_spacing_pt"></label><label><span>' . esc_html__( 'Overflow', 'certloom' ) . '</span><select data-certloom-designer-style-field="overflow"><option value="shrink">' . esc_html__( 'Shrink', 'certloom' ) . '</option><option value="wrap">' . esc_html__( 'Wrap', 'certloom' ) . '</option><option value="clip">' . esc_html__( 'Clip', 'certloom' ) . '</option></select></label></div><p class="description">' . esc_html__( 'Font keys remain preserved but read-only through DSN-08. FONT-02 will own controlled local font import/catalog and exact renderer-manifest binding; Designer will not create an unrenderable font dependency today.', 'certloom' ) . '</p></div>';
		echo '<div class="certloom-template-designer-style-group" hidden data-certloom-designer-style="shape"><div class="certloom-template-designer-style-grid"><label><span>' . esc_html__( 'Fill', 'certloom' ) . '</span><input type="text" maxlength="9" placeholder="#FFFFFF00" data-certloom-designer-style-field="fill"></label><label><span>' . esc_html__( 'Stroke', 'certloom' ) . '</span><input type="text" maxlength="9" placeholder="#000000" data-certloom-designer-style-field="stroke"></label><label><span>' . esc_html__( 'Stroke width (mm)', 'certloom' ) . '</span><input type="number" min="0" max="20" step="0.1" inputmode="decimal" data-certloom-designer-style-field="stroke_width_mm"></label></div></div>';
		echo '<div class="certloom-template-designer-style-group" hidden data-certloom-designer-style="line"><div class="certloom-template-designer-style-grid"><label><span>' . esc_html__( 'Stroke', 'certloom' ) . '</span><input type="text" maxlength="9" placeholder="#000000" data-certloom-designer-style-field="stroke"></label><label><span>' . esc_html__( 'Stroke width (mm)', 'certloom' ) . '</span><input type="number" min="0.001" max="20" step="0.1" inputmode="decimal" data-certloom-designer-style-field="stroke_width_mm"></label></div></div></div>';
		echo '<p class="description">' . esc_html__( 'Arrow keys nudge 1 mm, Shift+Arrow 5 mm and Alt/Option+Arrow 0.1 mm. Ctrl/Cmd+Z undoes; Ctrl/Cmd+Shift+Z or Ctrl/Cmd+Y redoes. Locked elements remain selectable but cannot be transformed, reordered, removed or content-edited.', 'certloom' ) . '</p></div><div class="certloom-template-designer-layers"><div class="certloom-template-designer-layers-head"><h4>' . esc_html__( 'Layers', 'certloom' ) . '</h4><span>' . esc_html__( 'Front → back', 'certloom' ) . '</span></div><div data-certloom-designer-layers></div></div></aside></div>';
		echo '<div class="certloom-template-designer-footer"><p class="description"><span data-certloom-designer-page-readout></span> · <span data-certloom-designer-autosave-status>' . esc_html__( 'Recovery autosave ready', 'certloom' ) . '</span> · ' . esc_html__( 'Manual save remains the only authoritative revision write.', 'certloom' ) . '</p><button type="submit" class="button button-primary button-large" data-certloom-designer-save>' . esc_html__( 'Save Designer revision', 'certloom' ) . '</button></div>';
		echo '</form><div class="notice notice-error inline" hidden data-certloom-designer-error><p></p></div><div class="screen-reader-text" aria-live="polite" data-certloom-designer-live></div></section>';
	}


	/** Translatable labels referenced by the executable-free typed registry contract. @return array<string,string> */
	private function designer_registry_labels() {
		return array(
			'text' => __( 'Text', 'certloom' ), 'mergeField' => __( 'Merge field', 'certloom' ), 'image' => __( 'Image', 'certloom' ),
			'logo' => __( 'Logo', 'certloom' ), 'signature' => __( 'Signature', 'certloom' ), 'seal' => __( 'Seal', 'certloom' ),
			'qr' => __( 'QR', 'certloom' ), 'barcode' => __( 'Barcode', 'certloom' ), 'list' => __( 'List', 'certloom' ),
			'rectangle' => __( 'Rectangle', 'certloom' ), 'ellipse' => __( 'Ellipse', 'certloom' ), 'line' => __( 'Line', 'certloom' ),
			'background' => __( 'Background', 'certloom' ), 'shape' => __( 'Shape', 'certloom' ), 'asset' => __( 'Image asset', 'certloom' ),
		);
	}

	/** Personal, non-artifact Designer workspace state key. @return string */
	private function designer_state_key( $template_id ) {
		return 'certloom_template_designer_state_' . get_current_blog_id() . '_' . absint( $template_id );
	}


	/** @return void */
	private function preview_panel( array $editing ) {
		$id = absint( $editing['id'] ?? 0 );
		$revision_mode = isset( $_GET['preview_revision'] ) && 'published' === sanitize_key( wp_unslash( $_GET['preview_revision'] ) ) ? 'published' : 'current'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only Preview selector.
		$sample_profile = isset( $_GET['preview_sample'] ) ? sanitize_key( wp_unslash( $_GET['preview_sample'] ) ) : TemplatePreviewSamples::STANDARD; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only Preview selector.
		if ( ! in_array( $sample_profile, TemplatePreviewSamples::profiles(), true ) ) { $sample_profile = TemplatePreviewSamples::STANDARD; }
		$has_published = ! empty( $editing['published_revision_id'] ) && ! empty( $editing['published_revision'] ) && is_array( $editing['published_revision'] );
		if ( 'published' === $revision_mode && ! $has_published ) { $revision_mode = 'current'; }

		$authority = ( new RendererAuthorityGate() )->manifest_status();
		$preview = ( new TemplatePreviewService() )->build( $editing, $revision_mode, $sample_profile );
		echo '<section class="certloom-template-preview-card"><div class="certloom-template-preview-head"><div><h3>' . esc_html__( 'Template Preview', 'certloom' ) . '</h3><p>' . esc_html__( 'Read-only sample Preview built through the same LayoutResolver → RenderPlanBuilder composition path consumed by Certloom PDF, PNG and JPEG adapters.', 'certloom' ) . '</p></div><div class="certloom-template-preview-badges"><span class="certloom-domain-preview-badge">' . esc_html__( 'Canonical Render Plan', 'certloom' ) . '</span>';
		if ( ! empty( $authority['accepted'] ) ) {
			echo '<span class="certloom-template-preview-authority is-accepted">' . esc_html__( 'Renderer release accepted', 'certloom' ) . '</span>';
		} else {
			echo '<span class="certloom-template-preview-authority">' . esc_html__( 'Artifact authority pending', 'certloom' ) . '</span>';
		}
		echo '</div></div>';
		echo '<div class="notice notice-info inline certloom-template-preview-honesty"><p><strong>' . esc_html__( 'Preview boundary:', 'certloom' ) . '</strong> ' . esc_html__( 'Geometry, merge resolution, direction, QR modules, colors, ordering and element composition come from the canonical Render Plan. Browser font rasterization is illustrative until PDF-04 exact renderer/font authority is accepted; this screen never manufactures production artifact authority.', 'certloom' ) . '</p></div>';

		echo '<div class="certloom-template-preview-controls"><div><span class="certloom-template-preview-control-label">' . esc_html__( 'Revision', 'certloom' ) . '</span><nav aria-label="' . esc_attr__( 'Template Preview revision', 'certloom' ) . '">';
		foreach ( array( 'current' => __( 'Current draft', 'certloom' ), 'published' => __( 'Published', 'certloom' ) ) as $key => $label ) {
			if ( 'published' === $key && ! $has_published ) { echo '<span class="is-disabled" aria-disabled="true">' . esc_html( $label ) . '</span>'; continue; }
			$url = add_query_arg( array( 'page' => self::PAGE, 'template_id' => $id, 'preview_revision' => $key, 'preview_sample' => $sample_profile ), admin_url( 'admin.php' ) );
			echo '<a class="' . ( $revision_mode === $key ? 'is-current' : '' ) . '" ' . ( $revision_mode === $key ? 'aria-current="page" ' : '' ) . 'href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</nav></div><div><span class="certloom-template-preview-control-label">' . esc_html__( 'Sample data', 'certloom' ) . '</span><nav aria-label="' . esc_attr__( 'Template Preview sample data', 'certloom' ) . '">';
		foreach ( array( TemplatePreviewSamples::STANDARD => __( 'Standard', 'certloom' ), TemplatePreviewSamples::LONG => __( 'Long content', 'certloom' ), TemplatePreviewSamples::RTL => __( 'RTL / Urdu', 'certloom' ) ) as $key => $label ) {
			$url = add_query_arg( array( 'page' => self::PAGE, 'template_id' => $id, 'preview_revision' => $revision_mode, 'preview_sample' => $key ), admin_url( 'admin.php' ) );
			echo '<a class="' . ( $sample_profile === $key ? 'is-current' : '' ) . '" ' . ( $sample_profile === $key ? 'aria-current="page" ' : '' ) . 'href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</nav></div></div>';

		if ( is_wp_error( $preview ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $preview->get_error_message() ) . '</p></div></section>';
			return;
		}
		try {
			$presented = ( new RenderPlanHtmlPreview() )->present( $preview['render_plan'] );
		} catch ( \Throwable $error ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'The canonical Render Plan passed preparation but could not be presented safely in the browser.', 'certloom' ) . '</p></div></section>';
			return;
		}

		echo '<div class="certloom-template-preview-proof"><div><span>' . esc_html__( 'Revision', 'certloom' ) . '</span><strong>#' . esc_html( (string) absint( $preview['revision_number'] ) ) . '</strong><small>ID ' . esc_html( (string) absint( $preview['revision_id'] ) ) . '</small></div><div><span>' . esc_html__( 'Layout SHA-256', 'certloom' ) . '</span><code>' . esc_html( (string) $preview['layout_sha256'] ) . '</code></div><div><span>' . esc_html__( 'Render Plan SHA-256', 'certloom' ) . '</span><code>' . esc_html( (string) $preview['render_plan_sha256'] ) . '</code></div></div>';
		foreach ( (array) ( $presented['warnings'] ?? array() ) as $warning ) { echo '<div class="notice notice-warning inline"><p>' . esc_html( (string) $warning ) . '</p></div>'; }
		echo $presented['html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- RenderPlanHtmlPreview escapes every Render Plan field and emits only fixed markup.
		echo '<p class="description certloom-template-preview-footnote">' . esc_html__( 'Synthetic Preview values never read or mutate credential records. Use Standard, Long content and RTL / Urdu samples to stress the design before Designer editing and issuance are introduced.', 'certloom' ) . '</p></section>';
	}

	/** @return void */
	private function duplicate_form( array $editing ) {
		$id = absint( $editing['id'] ?? 0 );
		$base_key = preg_replace( '/-copy(?:-[0-9]+)?$/', '', (string) ( $editing['machine_key'] ?? 'template' ) );
		$suggested_key = sanitize_key( $base_key . '-copy' );
		echo '<details class="certloom-template-duplicate"><summary>' . esc_html__( 'Duplicate as a new draft', 'certloom' ) . '</summary><p class="description">' . esc_html__( 'Duplication copies only the reviewed current revision. It creates a fresh template identity with no published state and no assignments.', 'certloom' ) . '</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="certloom_template_duplicate"><input type="hidden" name="template_id" value="' . esc_attr( (string) $id ) . '"><input type="hidden" name="expected_revision_id" value="' . esc_attr( (string) absint( $editing['current_revision_id'] ?? 0 ) ) . '"><input type="hidden" name="description" value="' . esc_attr( (string) ( $editing['description'] ?? '' ) ) . '">'; wp_nonce_field( 'certloom_template_duplicate_' . $id );
		echo '<div class="certloom-template-duplicate-fields"><label><span>' . esc_html__( 'New name', 'certloom' ) . '</span><input class="regular-text" name="name" maxlength="255" required value="' . esc_attr( (string) $editing['name'] . ' ' . __( 'Copy', 'certloom' ) ) . '"></label><label><span>' . esc_html__( 'New machine key', 'certloom' ) . '</span><input class="regular-text code" name="machine_key" maxlength="191" required value="' . esc_attr( $suggested_key ) . '"></label></div>'; submit_button( __( 'Create duplicate draft', 'certloom' ), 'secondary', 'submit', false ); echo '</form></details>';
	}

	/** @return string */
	private function lifecycle_badge( $state ) {
		$labels = array(
			TemplateLifecycle::DRAFT => __( 'Draft', 'certloom' ),
			TemplateLifecycle::PUBLISHED => __( 'Published', 'certloom' ),
			TemplateLifecycle::CHANGES_PENDING => __( 'Changes pending', 'certloom' ),
			TemplateLifecycle::ARCHIVED => __( 'Archived', 'certloom' ),
		);
		$state = isset( $labels[ $state ] ) ? $state : TemplateLifecycle::DRAFT;
		return '<span class="certloom-template-state certloom-template-state-' . esc_attr( $state ) . '">' . esc_html( $labels[ $state ] ) . '</span>';
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
