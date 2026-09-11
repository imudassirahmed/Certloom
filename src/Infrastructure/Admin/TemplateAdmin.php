<?php
namespace Certloom\Infrastructure\Admin;

use Certloom\Application\Rendering\CanonicalJson;
use Certloom\Application\Rendering\LayoutValidator;
use Certloom\Application\Rendering\DesignerFontCatalog;
use Certloom\Domain\Rendering\ElementRegistry;
use Certloom\Domain\Rendering\LayoutSchema;
use Certloom\Application\Templates\TemplateDefaults;
use Certloom\Application\Templates\FactoryPresetService;
use Certloom\Application\Templates\PresetCatalog;
use Certloom\Application\Templates\TemplateAssignmentPolicy;
use Certloom\Application\Templates\TemplateLifecycle;
use Certloom\Application\Templates\TemplatePreviewSamples;
use Certloom\Application\Templates\TemplateDesignerState;
use Certloom\Application\Templates\TemplateLibraryMetadata;
use Certloom\Application\Templates\TemplatePackage;
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
		add_action( 'admin_post_certloom_template_background_create', array( $this, 'background_create' ) );
		add_action( 'admin_post_certloom_template_metadata', array( $this, 'metadata' ) );
		add_action( 'admin_post_certloom_template_revision', array( $this, 'revision' ) );
		add_action( 'admin_post_certloom_template_layout_upgrade', array( $this, 'layout_upgrade' ) );
		add_action( 'admin_post_certloom_template_publish', array( $this, 'publish' ) );
		add_action( 'admin_post_certloom_template_prepare_publish', array( $this, 'prepare_publish' ) );
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
		add_action( 'wp_ajax_certloom_template_quick_publish', array( $this, 'quick_publish' ) );
		add_action( 'wp_ajax_certloom_template_quick_unpublish', array( $this, 'quick_unpublish' ) );
	}


	/** Load the Designer interaction layer only on Certloom Templates. @return void */
	public function enqueue_designer_assets( $hook ) {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin routing.
		if ( self::PAGE !== $page && false === strpos( (string) $hook, self::PAGE ) ) { return; }
		wp_enqueue_media();
		wp_enqueue_style( 'certloom-template-designer', CERTLOOM_URL . 'admin/assets/designer.css', array( 'ecapla-certverify-admin' ), CERTLOOM_VERSION );
		wp_enqueue_script( 'certloom-template-designer', CERTLOOM_URL . 'admin/assets/designer.js', array( 'ecapla-certverify-admin' ), CERTLOOM_VERSION, true );
		wp_enqueue_style( 'certloom-template-library', CERTLOOM_URL . 'admin/assets/template-library.css', array( 'ecapla-certverify-admin' ), CERTLOOM_VERSION );
		wp_enqueue_script( 'certloom-template-library', CERTLOOM_URL . 'admin/assets/template-library.js', array( 'ecapla-certverify-admin' ), CERTLOOM_VERSION, true );
		wp_localize_script( 'certloom-template-designer', 'certloomTemplateFontCatalog', ( new DesignerFontCatalog() )->browser_contract() );
		wp_localize_script( 'certloom-template-designer', 'certloomTemplateDesignerSamples', ( new TemplatePreviewSamples() )->browser_contract() );
		wp_localize_script(
			'certloom-template-designer',
			'certloomTemplateDesignerI18n',
			array(
				'currentLayoutLoadError' => __( 'The current design could not be loaded into Designer.', 'certloom' ),
				'designerChangeUndone' => __( 'Designer change undone.', 'certloom' ),
				'designerChangeRedone' => __( 'Designer change redone.', 'certloom' ),
				'unsavedChanges' => __( 'Unsaved changes', 'certloom' ),
				'savedDraft' => __( 'Saved draft', 'certloom' ),
				'savingRevision' => __( 'Saving revision…', 'certloom' ),
				'saveBlocked' => __( 'Save blocked', 'certloom' ),
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
				'elementRequiresSchemaUpgrade' => __( 'This element needs the newer design format. Update this draft before adding it.', 'certloom' ),
				'element' => __( 'Element', 'certloom' ),
				'unlock' => __( 'Unlock', 'certloom' ),
				'lock' => __( 'Lock', 'certloom' ),
				'locked' => __( 'Locked', 'certloom' ),
				'lockedSuffix' => __( 'locked', 'certloom' ),
				'canonicalPageRequired' => __( 'Designer needs a valid page setup before editing can continue.', 'certloom' ),
				'lockedElementSelected' => __( 'Locked element selected.', 'certloom' ),
				'pageConversionBoundary' => __( 'This page change would place an element outside the page. No change was applied.', 'certloom' ),
				'selectedAligned' => __( 'Selected element aligned to page.', 'certloom' ),
				'layerOrderUpdated' => __( 'Layer order updated.', 'certloom' ),
				'zoomUpdated' => __( 'Canvas zoom updated.', 'certloom' ),
				'layerSelectHint' => __( 'Select layer. Shift/Ctrl/Cmd-click adds it to the selection.', 'certloom' ),
				'layerActions' => __( 'Layer actions', 'certloom' ),
				'bringToFront' => __( 'Bring to front', 'certloom' ),
				'bringForward' => __( 'Bring forward', 'certloom' ),
				'sendBackward' => __( 'Send backward', 'certloom' ),
				'sendToBack' => __( 'Send to back', 'certloom' ),
				'duplicate' => __( 'Duplicate', 'certloom' ),
				'delete' => __( 'Delete', 'certloom' ),
				'copy' => __( 'Copy', 'certloom' ),
				'cut' => __( 'Cut', 'certloom' ),
				'paste' => __( 'Paste', 'certloom' ),
				'copied' => __( 'Selection copied.', 'certloom' ),
				'cutToClipboard' => __( 'Selection cut to Designer clipboard.', 'certloom' ),
				'pasted' => __( 'Designer clipboard pasted.', 'certloom' ),
				'renameLayer' => __( 'Rename layer', 'certloom' ),
				'layerRenameLabel' => __( 'Layer name', 'certloom' ),
				'saveLayerName' => __( 'Save layer name', 'certloom' ),
				'cancelLayerRename' => __( 'Cancel layer rename', 'certloom' ),
				'layerRenamed' => __( 'Layer name updated for this editor.', 'certloom' ),
				'hideInEditor' => __( 'Hide in editor', 'certloom' ),
				'hiddenInEditor' => __( 'Hidden in editor', 'certloom' ),
				'showInEditor' => __( 'Show in editor', 'certloom' ),
				'layerHiddenInEditor' => __( 'Layer hidden in this editor only. Certificate output is unchanged.', 'certloom' ),
				'layerShownInEditor' => __( 'Layer shown in the editor.', 'certloom' ),
				'allElementsSelected' => __( 'All canvas elements selected.', 'certloom' ),
				'selectionCleared' => __( 'Selection cleared.', 'certloom' ),
				'elementLocked' => __( 'Element locked for this author.', 'certloom' ),
				'elementUnlocked' => __( 'Element unlocked.', 'certloom' ),
				'elementNudged' => __( 'Element nudged.', 'certloom' ),
				'elementDropped' => __( 'Element placed on the canvas.', 'certloom' ),
				'snappingEnabled' => __( 'Snapping enabled.', 'certloom' ),
				'snappingDisabled' => __( 'Snapping disabled.', 'certloom' ),
				'safeAreaShown' => __( 'Safe area shown.', 'certloom' ),
				'safeAreaHidden' => __( 'Safe area hidden.', 'certloom' ),
				'panToolEnabled' => __( 'Pan tool on. Drag the canvas workspace to move around.', 'certloom' ),
				'panToolDisabled' => __( 'Pan tool off.', 'certloom' ),
				'marqueeSelectionUpdated' => __( 'Marquee selection updated.', 'certloom' ),
				'elementActions' => __( 'Element actions', 'certloom' ),
				'directTextEditStarted' => __( 'Direct text editing started. Press Control or Command plus Enter to apply, or Escape to cancel.', 'certloom' ),
				'directTextEditSaved' => __( 'Text updated on the canvas.', 'certloom' ),
				'directTextEditCancelled' => __( 'Direct text edit cancelled.', 'certloom' ),
				'directTextEditLabel' => __( 'Edit certificate text directly on the canvas', 'certloom' ),
				'directTextInvalid' => __( 'This text is too long. Keep it within the supported text limit.', 'certloom' ),
				'unsavedChangesReset' => __( 'Unsaved Designer changes reset.', 'certloom' ),
				'geometryBoundary' => __( 'That change would place the element outside the supported page area. The previous value was kept.', 'certloom' ),
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
				'invalidAssetReference' => __( 'Choose an image from this site’s WordPress Media Library before using it in Designer.', 'certloom' ),
				'unsupportedMediaType' => __( 'Certloom Designer currently supports local PNG and JPEG Media Library images only.', 'certloom' ),
				'deleteLockedElement' => __( 'Unlock this element before removing it.', 'certloom' ),
				'chooseMedia' => __( 'Choose local media', 'certloom' ),
				'useMedia' => __( 'Use this media', 'certloom' ),
				'barcodeDeferred' => __( 'Barcode elements need the newer design format. Update this draft first.', 'certloom' ),
				'barcode' => __( 'Barcode', 'certloom' ),
				'styleUpdated' => __( 'Element styling updated.', 'certloom' ),
				'invalidStyleValue' => __( 'That styling value is outside the supported range. The previous value was kept.', 'certloom' ),
				'invalidColor' => __( 'Colors must use #RRGGBB or #RRGGBBAA.', 'certloom' ),
				'autosaving' => __( 'Saving recovery checkpoint…', 'certloom' ),
				'autosaved' => __( 'Recovery checkpoint saved', 'certloom' ),
				'autosaveFailed' => __( 'Recovery autosave is temporarily unavailable. Manual save remains available.', 'certloom' ),
				'autosaveConflict' => __( 'Autosave stopped because this Template changed in another session. Your browser changes were not overwritten; reload only after preserving anything you still need.', 'certloom' ),
				'autosaveRecovered' => __( 'Recovered unsaved Designer changes from your latest safe checkpoint.', 'certloom' ),
				'autosaveDiscarded' => __( 'Recovery checkpoint discarded.', 'certloom' ),
				'quickDesignerEnabled' => __( 'Quick Designer enabled.', 'certloom' ),
				'proDesignerEnabled' => __( 'Pro Designer enabled.', 'certloom' ),
				'duplicateLockedElement' => __( 'Unlock this element before duplicating it.', 'certloom' ),
				'elementDuplicated' => __( 'Element duplicated.', 'certloom' ),
				'sampleRecipientName' => __( 'Alexandria Noor Rahman', 'certloom' ),
				'sampleCertificateNumber' => __( 'CERT-2026-001', 'certloom' ),
				'sampleProgramName' => __( 'Professional Credential Program', 'certloom' ),
				'sampleIssueDate' => __( '28 August 2026', 'certloom' ),
				'sampleExpiryDate' => __( '28 August 2027', 'certloom' ),
				'sampleIssuerName' => __( 'Northstar Learning Institute', 'certloom' ),
				'sampleStatus' => __( 'Valid', 'certloom' ),
				'sampleDataChanged' => __( 'Designer sample data changed. Saved certificate data is unchanged.', 'certloom' ),
				'sampleFallbackPrefix' => __( 'Sample', 'certloom' ),
				'richText' => __( 'Rich text', 'certloom' ),
				'richTextPrefix' => __( 'This certifies that ', 'certloom' ),
				'editInlineContent' => __( 'Edit inline content', 'certloom' ),
				'inlineContent' => __( 'Inline content', 'certloom' ),
				'inlineText' => __( 'Text', 'certloom' ),
				'inlineField' => __( 'Field', 'certloom' ),
				'addTextRun' => __( 'Add text', 'certloom' ),
				'addFieldRun' => __( 'Add field', 'certloom' ),
				'moveRunUp' => __( 'Move run up', 'certloom' ),
				'moveRunDown' => __( 'Move run down', 'certloom' ),
				'deleteRun' => __( 'Delete run', 'certloom' ),
				'inherit' => __( 'Inherit', 'certloom' ),
				'normal' => __( 'Normal', 'certloom' ),
				'richTextUpdated' => __( 'Inline rich text updated.', 'certloom' ),
				'richTextRunLimit' => __( 'Rich text supports up to 256 inline runs.', 'certloom' ),
				'richTextRunRequired' => __( 'Rich text must keep at least one inline run.', 'certloom' ),
				'invalidRichText' => __( 'That inline content is outside the supported rich-text contract. The previous value was kept.', 'certloom' ),
				'insertFieldAtCaret' => __( 'Insert field at caret', 'certloom' ),
				'legacyElementAuthoring' => __( 'This legacy element remains readable, but new designs use inline rich text instead.', 'certloom' ),
				'sampleGrade' => __( 'A', 'certloom' ),
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
		$size_key = isset( $_POST['page_size'] ) ? strtolower( sanitize_text_field( wp_unslash( $_POST['page_size'] ) ) ) : 'a4';
		$size = 'letter' === $size_key ? LayoutSchema::PAGE_LETTER : LayoutSchema::PAGE_A4;
		$orientation = isset( $_POST['orientation'] ) ? sanitize_key( wp_unslash( $_POST['orientation'] ) ) : LayoutSchema::ORIENTATION_LANDSCAPE;
		$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$machine_key = $this->posted_machine_key( $name );
		$result = ( new TemplateRepository() )->create(
			array(
				'machine_key' => $machine_key,
				'name' => $name,
				'description' => isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '',
				'layout' => TemplateDefaults::blank( $size, $orientation ),
			),
			get_current_user_id()
		);
		$this->redirect( $result, 'created' );
	}

	/** TPLUX-01 creates a draft whose first canonical image covers the document. @return void */
	public function background_create() {
		$this->require_capability();
		check_admin_referer( 'certloom_template_background_create' );
		$attachment_id = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;
		$mime = $attachment_id ? (string) get_post_mime_type( $attachment_id ) : '';
		$path = $attachment_id ? get_attached_file( $attachment_id ) : '';
		if ( ! $attachment_id || 'attachment' !== get_post_type( $attachment_id ) || ! in_array( $mime, array( 'image/png', 'image/jpeg' ), true ) || ! is_string( $path ) || '' === $path || ! is_file( $path ) || ! is_readable( $path ) ) {
			$this->redirect( new \WP_Error( 'certloom_template_background_invalid', __( 'Choose a readable local PNG or JPEG from the WordPress Media Library.', 'certloom' ) ), 'background_created', array( 'design_view' => 'library' ) );
		}
		$size_key = isset( $_POST['page_size'] ) ? strtolower( sanitize_text_field( wp_unslash( $_POST['page_size'] ) ) ) : 'a4';
		$size = 'letter' === $size_key ? LayoutSchema::PAGE_LETTER : LayoutSchema::PAGE_A4;
		$orientation = isset( $_POST['orientation'] ) ? sanitize_key( wp_unslash( $_POST['orientation'] ) ) : LayoutSchema::ORIENTATION_LANDSCAPE;
		$layout = TemplateDefaults::blank( $size, $orientation );
		$layout['elements'][] = array(
			'id' => wp_generate_uuid4(), 'type' => 'image', 'x_mm' => 0.0, 'y_mm' => 0.0,
			'width_mm' => (float) $layout['page']['width_mm'], 'height_mm' => (float) $layout['page']['height_mm'],
			'z' => 0, 'rotation_deg' => 0.0, 'opacity' => 1.0, 'asset' => 'attachment:' . $attachment_id, 'fit' => 'cover',
		);
		$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$machine_key = $this->posted_machine_key( $name );
		$result = ( new TemplateRepository() )->create(
			array(
				'machine_key' => $machine_key,
				'name' => $name,
				'description' => __( 'Created from a local WordPress Media Library background. You can move, replace or edit the image in Designer like any other image element.', 'certloom' ),
				'layout' => $layout,
			),
			get_current_user_id()
		);
		$this->redirect( $result, 'background_created', is_wp_error( $result ) ? array( 'design_view' => 'library' ) : array( 'design_view' => 'editor', 'template_id' => absint( $result ) ) );
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
		$this->redirect( $result, 'revision_saved', array( 'design_view' => 'editor', 'template_id' => $id ), 'certloom-template-designer' );
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

	/** One-click customer publishing boundary: deterministically updates an older draft format when needed, then publishes that exact new revision. @return void */
	public function prepare_publish() {
		$this->require_capability();
		$id = isset( $_POST['template_id'] ) ? absint( $_POST['template_id'] ) : 0;
		check_admin_referer( 'certloom_template_prepare_publish_' . $id );
		$result = $this->prepare_publish_result( $id, isset( $_POST['expected_revision_id'] ) ? absint( $_POST['expected_revision_id'] ) : 0, isset( $_POST['expected_revision_sha256'] ) ? strtolower( sanitize_text_field( wp_unslash( $_POST['expected_revision_sha256'] ) ) ) : '' );
		$this->redirect( $result, 'published', array( 'template_id' => $id ) );
	}

	/** Card-level publish action with an in-place customer notification. @return void */
	public function quick_publish() {
		$this->require_capability();
		$id = isset( $_POST['template_id'] ) ? absint( $_POST['template_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked immediately below.
		check_ajax_referer( 'certloom_template_prepare_publish_' . $id, 'nonce' );
		$result = $this->prepare_publish_result( $id, isset( $_POST['expected_revision_id'] ) ? absint( $_POST['expected_revision_id'] ) : 0, isset( $_POST['expected_revision_sha256'] ) ? strtolower( sanitize_text_field( wp_unslash( $_POST['expected_revision_sha256'] ) ) ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
		if ( is_wp_error( $result ) ) { wp_send_json_error( array( 'message' => $result->get_error_message() ), 409 ); }
		$template = ( new TemplateRepository() )->find( $id );
		wp_send_json_success( array( 'message' => __( 'Design published. Programs and issuance can use it now.', 'certloom' ), 'updated_at' => is_array( $template ) ? (string) ( $template['updated_at'] ?? '' ) : '', 'unpublish_nonce' => wp_create_nonce( 'certloom_template_quick_unpublish_' . $id ) ) );
	}


	/** Card-level Draft action. Assigned designs fail closed instead of breaking issuance. @return void */
	public function quick_unpublish() {
		$this->require_capability();
		$id = isset( $_POST['template_id'] ) ? absint( $_POST['template_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked immediately below.
		check_ajax_referer( 'certloom_template_quick_unpublish_' . $id, 'nonce' );
		$expected_updated_at = isset( $_POST['expected_updated_at'] ) ? sanitize_text_field( wp_unslash( $_POST['expected_updated_at'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
		$result = ( new TemplateRepository() )->unpublish( $id, $expected_updated_at );
		if ( is_wp_error( $result ) ) { wp_send_json_error( array( 'message' => $result->get_error_message() ), 409 ); }
		$template = ( new TemplateRepository() )->find( $id );
		$current = is_array( $template ) && is_array( $template['current_revision'] ?? null ) ? $template['current_revision'] : array();
		wp_send_json_success( array( 'message' => __( 'Design moved back to Draft.', 'certloom' ), 'updated_at' => is_array( $template ) ? (string) ( $template['updated_at'] ?? '' ) : '', 'revision_id' => is_array( $template ) ? absint( $template['current_revision_id'] ?? 0 ) : 0, 'revision_sha256' => (string) ( $current['layout_sha256'] ?? '' ), 'publish_nonce' => wp_create_nonce( 'certloom_template_prepare_publish_' . $id ) ) );
	}

	/** @return true|\WP_Error */
	private function prepare_publish_result( $id, $expected_id, $expected_sha ) {
		$repo = new TemplateRepository(); $template = $repo->find( $id );
		if ( is_wp_error( $template ) || ! is_array( $template ) ) { return is_wp_error( $template ) ? $template : new \WP_Error( 'certloom_template_missing', __( 'The design no longer exists.', 'certloom' ) ); }
		$current = isset( $template['current_revision'] ) && is_array( $template['current_revision'] ) ? $template['current_revision'] : array();
		if ( absint( $template['current_revision_id'] ?? 0 ) !== absint( $expected_id ) || empty( $current['layout_sha256'] ) || ! preg_match( '/^[a-f0-9]{64}$/', (string) $expected_sha ) || ! hash_equals( strtolower( (string) $current['layout_sha256'] ), strtolower( (string) $expected_sha ) ) ) { return new \WP_Error( 'certloom_template_publish_review_stale', __( 'This design changed after you opened it. Reload it before publishing.', 'certloom' ) ); }
		$layout = isset( $current['layout'] ) && is_array( $current['layout'] ) ? $current['layout'] : array();
		if ( absint( $layout['_meta']['schema_version'] ?? 0 ) < LayoutSchema::VERSION ) {
			$upgraded = $repo->upgrade_current_layout( $id, $expected_id, $expected_sha, get_current_user_id(), LayoutSchema::VERSION ); if ( is_wp_error( $upgraded ) ) { return $upgraded; }
			$template = $repo->find( $id ); if ( is_wp_error( $template ) || ! is_array( $template ) ) { return is_wp_error( $template ) ? $template : new \WP_Error( 'certloom_template_missing', __( 'The design could not be reloaded after its format update.', 'certloom' ) ); }
			$current = isset( $template['current_revision'] ) && is_array( $template['current_revision'] ) ? $template['current_revision'] : array();
		}
		return $repo->publish( $id, absint( $template['current_revision_id'] ?? 0 ), (string) ( $current['layout_sha256'] ?? '' ) );
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
		$repo = new TemplateRepository();
		$source = $repo->find( $id );
		$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$key = isset( $_POST['machine_key'] ) ? sanitize_key( wp_unslash( $_POST['machine_key'] ) ) : '';
		if ( is_array( $source ) ) {
			if ( '' === $name ) { $name = sprintf( /* translators: %s: source certificate design name. */ __( '%s — Custom', 'certloom' ), (string) ( $source['name'] ?? __( 'Certificate design', 'certloom' ) ) ); }
			if ( '' === $key ) { $key = sanitize_key( 'custom-' . absint( $id ) . '-' . wp_generate_password( 8, false, false ) ); }
		}
		$result = $repo->duplicate(
			$id,
			array(
				'machine_key' => $key,
				'name' => $name,
				'description' => isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : ( is_array( $source ) ? (string) ( $source['description'] ?? '' ) : '' ),
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
		$json = isset( $_POST['package_json'] ) ? (string) wp_unslash( $_POST['package_json'] ) : '';
		if ( '' === trim( $json ) && ! empty( $_FILES['design_package'] ) && is_array( $_FILES['design_package'] ) ) {
			$file = $_FILES['design_package'];
			if ( UPLOAD_ERR_OK !== absint( $file['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
				$this->redirect( new \WP_Error( 'certloom_template_import_upload_failed', __( 'Certloom could not read that design package. Choose the file again and retry.', 'certloom' ) ), 'imported' );
			}
			$size = absint( $file['size'] ?? 0 );
			$tmp  = isset( $file['tmp_name'] ) ? (string) $file['tmp_name'] : '';
			$upload_limit = $this->design_package_upload_limit( TemplatePackage::MAX_BYTES );
			if ( $size < 1 || $size > $upload_limit || '' === $tmp || ! is_uploaded_file( $tmp ) ) {
				$this->redirect(
					new \WP_Error(
						'certloom_template_import_upload_invalid',
						sprintf(
							/* translators: %s: maximum upload size for this site. */
							__( 'Choose a Certloom certificate design package no larger than %s.', 'certloom' ),
							$this->design_package_upload_limit_label( TemplatePackage::MAX_BYTES )
						)
					),
					'imported'
				);
			}
			$contents = file_get_contents( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Bounded local upload.
			$json = is_string( $contents ) ? $contents : '';
		}
		if ( '' === trim( $json ) ) {
			$this->redirect( new \WP_Error( 'certloom_template_import_required', __( 'Choose a Certloom certificate design package to import.', 'certloom' ) ), 'imported' );
		}
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
		$template_id = isset( $_POST['template_id'] ) ? absint( $_POST['template_id'] ) : 0;
		$redirect_args = $this->assignment_redirect_args( $scope_type, $scope_id );
		$preflight = $this->assignment_hierarchy_preflight( $scope_type, $scope_id, $template_id );
		if ( is_wp_error( $preflight ) ) {
			$this->redirect( $preflight, 'assignment_saved', $redirect_args );
		}
		$result = ( new TemplateRepository() )->assign(
			$template_id,
			$scope_type,
			$scope_id,
			100,
			isset( $_POST['expected_assignment_id'] ) ? absint( $_POST['expected_assignment_id'] ) : 0,
			isset( $_POST['expected_updated_at'] ) ? sanitize_text_field( wp_unslash( $_POST['expected_updated_at'] ) ) : ''
		);
		$this->redirect( $result, 'assignment_saved', $redirect_args );
	}

	/** @return void */
	public function assignment_clear() {
		$this->require_capability();
		$scope_type = isset( $_POST['scope_type'] ) ? sanitize_key( wp_unslash( $_POST['scope_type'] ) ) : '';
		$scope_id = isset( $_POST['scope_id'] ) ? absint( $_POST['scope_id'] ) : 0;
		check_admin_referer( 'certloom_template_assignment_' . $scope_type . '_' . $scope_id );
		$preflight = $this->assignment_hierarchy_preflight( $scope_type, $scope_id, 0 );
		if ( is_wp_error( $preflight ) ) {
			$this->redirect( $preflight, 'assignment_cleared', $this->assignment_redirect_args( $scope_type, $scope_id ) );
		}
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
		$json = ( new TemplatePackage() )->encode( $result );
		if ( is_wp_error( $json ) ) { $this->redirect( $json, 'exported', array( 'template_id' => $id ) ); }
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
		$seed = ( new FactoryPresetService() )->ensure_current( get_current_user_id() );
		$edit_id = isset( $_GET['template_id'] ) ? absint( $_GET['template_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation.
		$editing = $edit_id ? $repo->find( $edit_id ) : null;
		$requested = isset( $_GET['design_view'] ) ? sanitize_key( wp_unslash( $_GET['design_view'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only workspace navigation.
		if ( 'assignments' === $requested || ( isset( $_GET['assignment_scope'] ) && '' !== (string) $_GET['assignment_scope'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Compatibility deep link.
			$redirect_args = array( 'page' => DesignRulesAdmin::PAGE );
			$scope = isset( $_GET['assignment_scope'] ) ? sanitize_key( wp_unslash( $_GET['assignment_scope'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Compatibility deep link.
			$scope_id = isset( $_GET['assignment_scope_id'] ) ? absint( $_GET['assignment_scope_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Compatibility deep link.
			if ( in_array( $scope, array( 'issuer', 'program', 'batch' ), true ) && $scope_id ) {
				$redirect_args['rule_scope'] = $scope;
				$redirect_args['rule_scope_id'] = $scope_id;
			}
			wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) );
			exit;
		}
		$view = in_array( $requested, array( 'library', 'templates', 'editor' ), true ) ? $requested : 'library';
		if ( $edit_id ) { $view = 'editor'; }
		$simple = AdminUiMode::is_simple();
		$new_url = add_query_arg( array( 'page' => self::PAGE, 'design_view' => 'editor', 'library_action' => 'start_blank' ), admin_url( 'admin.php' ) ) . '#certloom-template-editor';
		$library_url = add_query_arg( array( 'page' => self::PAGE, 'design_view' => 'library' ), admin_url( 'admin.php' ) );
		echo '<div class="wrap ecapla-certverify-admin-wrap certloom-admin-shell certloom-template-admin"><section class="certloom-page-hero certloom-template-page-head"><div><span class="certloom-eyebrow">' . esc_html__( 'Certificate design', 'certloom' ) . '</span><h1>' . esc_html__( 'Certificate designs', 'certloom' ) . '</h1><p class="description">' . esc_html__( 'Start from a built-in certificate, create your own, or move a design between Certloom sites. Where a design is used is managed separately in Design Rules.', 'certloom' ) . '</p></div><div class="certloom-template-page-actions">';
		if ( 'editor' === $view ) { echo '<a class="button" href="' . esc_url( $library_url ) . '">' . esc_html__( 'Back to library', 'certloom' ) . '</a>'; }
		if ( 'editor' !== $view ) { echo '<button type="button" class="button" data-certloom-certificate-import-open>' . esc_html__( 'Import', 'certloom' ) . '</button>'; }
		echo '<a class="button button-primary" href="' . esc_url( $new_url ) . '">' . esc_html__( 'New design', 'certloom' ) . '</a></div></section>';
		$this->notice();
		if ( is_wp_error( $seed ) ) { echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'The built-in certificate library could not be repaired automatically. Your existing designs are unchanged.', 'certloom' ) . '</p></div>'; }
		$this->design_nav( $view );
		if ( 'library' === $view ) { $this->visual_library( $repo ); }
		elseif ( 'templates' === $view ) { $this->my_templates( $repo ); }
		else { $this->editor( $editing ); }
		if ( 'editor' !== $view ) { $this->import_dialog(); }
		echo '</div>';
	}

	/** @return void */
	private function design_nav( $view ) {
		echo '<nav class="certloom-design-nav" aria-label="' . esc_attr__( 'Certificate design workspace', 'certloom' ) . '">';
		foreach ( array( 'library' => __( 'Library', 'certloom' ), 'templates' => __( 'My designs', 'certloom' ) ) as $key => $label ) {
			$url = add_query_arg( array( 'page' => self::PAGE, 'design_view' => $key ), admin_url( 'admin.php' ) );
			echo '<a class="' . ( $key === $view ? 'is-current' : '' ) . '" ' . ( $key === $view ? 'aria-current="page" ' : '' ) . 'href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
		}
		if ( 'editor' === $view ) { echo '<span class="is-current" aria-current="page">' . esc_html__( 'Designer', 'certloom' ) . '</span>'; }
		echo '</nav>';
	}

	/** TPLUX-01 thumbnail-first library over the existing canonical Template/Preview model. @return void */
	private function visual_library( TemplateRepository $repo ) {
		$search = isset( $_GET['library_s'] ) ? sanitize_text_field( wp_unslash( $_GET['library_s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter.
		$kind = 'factory';
		$filters = array(
			'purpose' => isset( $_GET['library_purpose'] ) ? sanitize_key( wp_unslash( $_GET['library_purpose'] ) ) : '',
			'orientation' => isset( $_GET['library_orientation'] ) ? sanitize_key( wp_unslash( $_GET['library_orientation'] ) ) : '',
			'style' => isset( $_GET['library_style'] ) ? sanitize_key( wp_unslash( $_GET['library_style'] ) ) : '',
			'color' => isset( $_GET['library_color'] ) ? sanitize_key( wp_unslash( $_GET['library_color'] ) ) : '',
			'language' => isset( $_GET['library_language'] ) ? sanitize_key( wp_unslash( $_GET['library_language'] ) ) : '',
			'rtl' => isset( $_GET['library_rtl'] ) ? sanitize_key( wp_unslash( $_GET['library_rtl'] ) ) : '',
		); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filters.
		$page = isset( $_GET['library_paged'] ) ? max( 1, absint( $_GET['library_paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination.
		$pool = $repo->list_candidates( $search, 1, 250, $kind );
		$metadata = new TemplateLibraryMetadata();
		$items = array();
		if ( ! is_wp_error( $pool ) ) {
			foreach ( $pool['items'] as $item ) {
				$library = $metadata->describe( $item );
				if ( ! $metadata->matches( $library, $filters ) ) { continue; }
				$item['_library'] = $library;
				$items[] = $item;
			}
			usort( $items, static function( $a, $b ) { return strcasecmp( (string) ( $a['name'] ?? '' ), (string) ( $b['name'] ?? '' ) ); } );
		}
		$per_page = 12; $total = count( $items ); $pages = max( 1, (int) ceil( $total / $per_page ) ); $page = min( $page, $pages );
		$visible = array_slice( $items, ( $page - 1 ) * $per_page, $per_page );

		echo '<section class="certloom-template-library-visual"><header class="certloom-template-library-hero"><div><span class="certloom-template-library-kicker">' . esc_html__( 'Built-in Certloom collection', 'certloom' ) . '</span><h2>' . esc_html__( 'Certificate library', 'certloom' ) . '</h2><p>' . esc_html__( 'Browse Certloom’s built-in certificate designs. Preview a design, then customize it as your own without changing the built-in original.', 'certloom' ) . '</p></div><div class="certloom-template-library-create"><button type="button" class="button" data-certloom-library-background-toggle>' . esc_html__( 'Start from background', 'certloom' ) . '</button></div></header>';
		$this->background_creation_form();
		$this->library_filters( $filters, $search, $kind );
		if ( is_wp_error( $pool ) ) { echo '<div class="notice notice-error inline"><p>' . esc_html( $pool->get_error_message() ) . '</p></div></section>'; return; }
		if ( (int) $pool['total'] > 250 ) { echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Use search to narrow very large design libraries before applying visual filters.', 'certloom' ) . '</p></div>'; }
		echo '<div class="certloom-template-library-results-head"><p>' . esc_html( sprintf( /* translators: %d: number of certificate designs. */ _n( '%d design', '%d designs', $total, 'certloom' ), $total ) ) . '</p></div>';
		if ( empty( $visible ) ) {
			echo '<div class="certloom-template-library-empty"><strong>' . esc_html__( 'No designs match these filters.', 'certloom' ) . '</strong><p>' . esc_html__( 'Clear a filter or create a new certificate design.', 'certloom' ) . '</p></div>';
		} else {
			echo '<div class="certloom-template-library-grid">';
			foreach ( $visible as $item ) { $this->visual_library_card( $repo, $item ); }
			echo '</div>';
		}
		$this->library_pagination( $page, $total, $per_page, $filters, $search, $kind );
		$this->preview_dialog();
		echo '</section>';
	}

	/** @return void */
	private function library_filters( array $filters, $search, $kind ) {
		$labels = TemplateLibraryMetadata::filter_labels(); $purposes = TemplateLibraryMetadata::purpose_labels();
		echo '<form id="certloom-template-library-filters" method="get" class="certloom-template-library-filters"><input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '"><input type="hidden" name="design_view" value="library">';
		echo '<div class="certloom-template-library-filter-primary"><label class="certloom-library-filter-search"><span>' . esc_html__( 'Search', 'certloom' ) . '</span><input type="search" name="library_s" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr__( 'Name or description', 'certloom' ) . '"></label>';
		echo '<label><span>' . esc_html__( 'Purpose', 'certloom' ) . '</span><select name="library_purpose"><option value="">' . esc_html__( 'All purposes', 'certloom' ) . '</option>'; foreach ( $purposes as $key => $label ) { echo '<option value="' . esc_attr( $key ) . '" ' . selected( $filters['purpose'], $key, false ) . '>' . esc_html( $label ) . '</option>'; } echo '</select></label>';
		echo '<label><span>' . esc_html__( 'Orientation', 'certloom' ) . '</span><select name="library_orientation"><option value="">' . esc_html__( 'Any', 'certloom' ) . '</option><option value="landscape" ' . selected( $filters['orientation'], 'landscape', false ) . '>' . esc_html__( 'Landscape', 'certloom' ) . '</option><option value="portrait" ' . selected( $filters['orientation'], 'portrait', false ) . '>' . esc_html__( 'Portrait', 'certloom' ) . '</option></select></label>';
		echo '<div class="certloom-library-filter-action"><button type="submit" class="button button-primary">' . esc_html__( 'Apply', 'certloom' ) . '</button></div></div>';
		echo '<details class="certloom-template-library-more"><summary>' . esc_html__( 'More filters', 'certloom' ) . '</summary><div class="certloom-template-library-filter-advanced">';
		foreach ( array( 'style' => __( 'Style', 'certloom' ), 'color' => __( 'Color family', 'certloom' ), 'language' => __( 'Language', 'certloom' ), 'rtl' => __( 'RTL readiness', 'certloom' ) ) as $key => $label ) { echo '<label><span>' . esc_html( $label ) . '</span><select name="library_' . esc_attr( $key ) . '"><option value="">' . esc_html__( 'Any', 'certloom' ) . '</option>'; foreach ( $labels[ $key ] as $value => $option ) { echo '<option value="' . esc_attr( $value ) . '" ' . selected( $filters[ $key ], $value, false ) . '>' . esc_html( $option ) . '</option>'; } echo '</select></label>'; }
		echo '</div></details></form>';
	}

	/** @return void */
	private function visual_library_card( TemplateRepository $repo, array $item ) {
		$id = absint( $item['id'] ?? 0 ); $library = (array) ( $item['_library'] ?? array() ); $preview = $this->visual_library_preview( $item );
		$edit_url = add_query_arg( array( 'page' => self::PAGE, 'design_view' => 'editor', 'template_id' => $id ), admin_url( 'admin.php' ) ); $labels = TemplateLibraryMetadata::filter_labels(); $style_label = (string) ( $labels['style'][ $library['style'] ?? '' ] ?? ucfirst( (string) ( $library['style'] ?? '' ) ) );
		$current_is_published = absint( $item['published_revision_id'] ?? 0 ) > 0 && absint( $item['published_revision_id'] ?? 0 ) === absint( $item['current_revision_id'] ?? 0 ); $renderer = ( new RendererAuthorityGate() )->publication_status();
		echo '<article class="certloom-template-library-card"><div class="certloom-template-library-thumbnail" data-certloom-library-preview-source data-template-title="' . esc_attr( (string) $item['name'] ) . '" data-page-width="' . esc_attr( (string) $preview['page_width'] ) . '" data-page-height="' . esc_attr( (string) $preview['page_height'] ) . '"><div class="certloom-template-library-thumbnail-stage" style="--certloom-thumb-scale:' . esc_attr( (string) $preview['scale'] ) . ';--certloom-thumb-width:' . esc_attr( (string) $preview['page_width'] ) . 'px;--certloom-thumb-height:' . esc_attr( (string) $preview['page_height'] ) . 'px">' . $preview['html'] . '</div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<div class="certloom-template-library-card-body"><div class="certloom-template-library-badges"><span>' . esc_html( (string) ( $library['family_label'] ?? __( 'Custom', 'certloom' ) ) ) . '</span><span>' . esc_html( $style_label ) . '</span><span>' . esc_html( ucfirst( (string) ( $library['orientation'] ?? '' ) ) ) . '</span>' . $this->lifecycle_badge( (string) ( $item['lifecycle_state'] ?? TemplateLifecycle::DRAFT ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div><h3>' . esc_html( (string) $item['name'] ) . '</h3><p>' . esc_html( (string) ( $item['description'] ?: __( 'No description.', 'certloom' ) ) ) . '</p><div class="certloom-template-library-card-meta"><span>' . esc_html( strtoupper( (string) ( $item['page_size'] ?? '' ) ) ) . '</span><span>' . esc_html( sprintf( /* translators: %d: revision number. */ __( 'Revision %d', 'certloom' ), absint( $item['revision_number'] ?? 0 ) ) ) . '</span><span>' . esc_html( sprintf( /* translators: %d: element count. */ _n( '%d element', '%d elements', absint( $item['element_count'] ?? 0 ), 'certloom' ), absint( $item['element_count'] ?? 0 ) ) ) . '</span></div>';
		echo '<div class="certloom-template-card-publication"><div class="certloom-template-publication-copy"><strong>' . esc_html__( 'Publication', 'certloom' ) . '</strong><span class="certloom-template-publish-state ' . ( $current_is_published ? 'is-published' : 'is-draft' ) . '" data-certloom-publish-state>' . esc_html( $current_is_published ? __( 'Published', 'certloom' ) : ( ! empty( $item['published_revision_id'] ) ? __( 'Draft changes', 'certloom' ) : __( 'Draft', 'certloom' ) ) ) . '</span></div>';
		if ( $current_is_published ) {
			echo '<button type="button" class="certloom-publication-switch is-on" role="switch" aria-checked="true" data-certloom-card-unpublish data-template-id="' . esc_attr( (string) $id ) . '" data-expected-updated-at="' . esc_attr( (string) ( $item['updated_at'] ?? '' ) ) . '" data-nonce="' . esc_attr( wp_create_nonce( 'certloom_template_quick_unpublish_' . $id ) ) . '"><span aria-hidden="true"></span><span class="screen-reader-text">' . esc_html__( 'Move published design back to Draft', 'certloom' ) . '</span></button>';
		} elseif ( ! empty( $renderer['accepted'] ) && ! empty( $item['layout_sha256'] ) ) {
			echo '<button type="button" class="certloom-publication-switch" role="switch" aria-checked="false" data-certloom-card-publish data-template-id="' . esc_attr( (string) $id ) . '" data-revision-id="' . esc_attr( (string) absint( $item['current_revision_id'] ?? 0 ) ) . '" data-revision-sha="' . esc_attr( (string) $item['layout_sha256'] ) . '" data-nonce="' . esc_attr( wp_create_nonce( 'certloom_template_prepare_publish_' . $id ) ) . '"><span aria-hidden="true"></span><span class="screen-reader-text">' . esc_html__( 'Publish this draft design', 'certloom' ) . '</span></button>';
		} else {
			echo '<button type="button" class="certloom-publication-switch" role="switch" aria-checked="false" disabled aria-disabled="true" title="' . esc_attr( AdminUiMode::is_simple() ? __( 'Publishing is not available on this site yet.', 'certloom' ) : __( 'Publishing is waiting for renderer release authority.', 'certloom' ) ) . '"><span aria-hidden="true"></span><span class="screen-reader-text">' . esc_html__( 'Publishing unavailable', 'certloom' ) . '</span></button>';
		}
		$usage = $repo->usage_count( $id ); $usage = is_wp_error( $usage ) ? 0 : absint( $usage );
		$export_url = wp_nonce_url( add_query_arg( array( 'action' => 'certloom_template_export', 'template_id' => $id ), admin_url( 'admin-post.php' ) ), 'certloom_template_export_' . $id );
		echo '</div><div class="certloom-template-library-actions"><button type="button" class="button" data-certloom-library-preview>' . esc_html__( 'Preview', 'certloom' ) . '</button>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="certloom-template-customize-form"><input type="hidden" name="action" value="certloom_template_duplicate"><input type="hidden" name="template_id" value="' . esc_attr( (string) $id ) . '"><input type="hidden" name="expected_revision_id" value="' . esc_attr( (string) absint( $item['current_revision_id'] ?? 0 ) ) . '">'; wp_nonce_field( 'certloom_template_duplicate_' . $id ); echo '<button class="button button-primary">' . esc_html__( 'Customize', 'certloom' ) . '</button></form>';
		echo '<details class="certloom-template-card-more"><summary class="button" aria-label="' . esc_attr__( 'More actions', 'certloom' ) . '">•••</summary><div><a href="' . esc_url( $export_url ) . '">' . esc_html__( 'Export', 'certloom' ) . '</a><a href="' . esc_url( add_query_arg( array( 'page' => DesignRulesAdmin::PAGE, 'design_type' => 'certificate', 'design_id' => $id ), admin_url( 'admin.php' ) ) ) . '">' . esc_html( sprintf( /* translators: %d: number of direct design rules using this certificate design. */ _n( 'Used by %d rule', 'Used by %d rules', $usage, 'certloom' ), $usage ) ) . '</a></div></details></div><div class="certloom-template-card-feedback" data-certloom-card-feedback aria-live="polite"></div></div></article>';
	}

	/** @return array{html:string,scale:float,page_width:float,page_height:float} */
	private function visual_library_preview( array $item ) {
		$template = array(
			'current_revision_id' => absint( $item['current_revision_id'] ?? 0 ),
			'current_revision' => array( 'layout' => (array) ( $item['layout'] ?? array() ), 'revision_number' => absint( $item['revision_number'] ?? 0 ), 'layout_sha256' => (string) ( $item['layout_sha256'] ?? '' ) ),
		);
		$built = ( new TemplatePreviewService() )->build( $template, 'current', TemplatePreviewSamples::STANDARD );
		if ( is_wp_error( $built ) ) { return array( 'html' => '<div class="certloom-template-library-preview-failed">' . esc_html__( 'Preview unavailable', 'certloom' ) . '</div>', 'scale' => 1.0, 'page_width' => 260.0, 'page_height' => 190.0 ); }
		try { $presented = ( new RenderPlanHtmlPreview() )->present( $built['render_plan'] ); } catch ( \Throwable $error ) { return array( 'html' => '<div class="certloom-template-library-preview-failed">' . esc_html__( 'Preview unavailable', 'certloom' ) . '</div>', 'scale' => 1.0, 'page_width' => 260.0, 'page_height' => 190.0 ); }
		$width = (float) ( $built['render_plan']['page']['width_mm'] ?? 0 ) * RenderPlanHtmlPreview::PX_PER_MM;
		$height = (float) ( $built['render_plan']['page']['height_mm'] ?? 0 ) * RenderPlanHtmlPreview::PX_PER_MM;
		$scale = $width > 0 && $height > 0 ? min( 260.0 / $width, 190.0 / $height ) : 1.0;
		$html = str_replace( 'tabindex="0"', 'tabindex="-1"', (string) $presented['html'] );
		return array( 'html' => $html, 'scale' => round( $scale, 6 ), 'page_width' => round( $width, 4 ), 'page_height' => round( $height, 4 ) );
	}

	/** @return void */
	private function background_creation_form() {
		echo '<section class="certloom-template-library-background" hidden data-certloom-library-background-panel><div><h3>' . esc_html__( 'Start from a certificate background', 'certloom' ) . '</h3><p>' . esc_html__( 'Choose a local PNG or JPEG. Certloom creates a normal draft with that image filling the page; you can then add text, dynamic fields, QR and other elements in Designer.', 'certloom' ) . '</p></div><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="certloom_template_background_create"><input type="hidden" name="attachment_id" value="" data-certloom-library-background-id>'; wp_nonce_field( 'certloom_template_background_create' );
		echo '<label><span>' . esc_html__( 'Design name', 'certloom' ) . '</span><input type="text" name="name" maxlength="255" required></label>';
		if ( AdminUiMode::is_simple() ) { echo '<details class="certloom-technical-details certloom-inline-technical"><summary>' . esc_html__( 'Technical details', 'certloom' ) . '</summary><label><span>' . esc_html__( 'Machine key', 'certloom' ) . '</span><input type="text" name="machine_key" maxlength="191" pattern="[a-z0-9_-]+" placeholder="' . esc_attr__( 'Generated automatically when left blank', 'certloom' ) . '"><small>' . esc_html__( 'Stable internal identity used by inheritance, imports and integrations.', 'certloom' ) . '</small></label></details>'; } else { echo '<label><span>' . esc_html__( 'Machine key', 'certloom' ) . '</span><input type="text" name="machine_key" maxlength="191" pattern="[a-z0-9_-]+" required></label>'; }
		echo '<label><span>' . esc_html__( 'Page size', 'certloom' ) . '</span><select name="page_size"><option value="A4">A4</option><option value="LETTER">' . esc_html__( 'Letter', 'certloom' ) . '</option></select></label><label><span>' . esc_html__( 'Orientation', 'certloom' ) . '</span><select name="orientation"><option value="landscape">' . esc_html__( 'Landscape', 'certloom' ) . '</option><option value="portrait">' . esc_html__( 'Portrait', 'certloom' ) . '</option></select></label><div class="certloom-template-library-media"><button type="button" class="button" data-certloom-library-background-media>' . esc_html__( 'Choose image', 'certloom' ) . '</button><span data-certloom-library-background-name>' . esc_html__( 'No image selected', 'certloom' ) . '</span></div><button class="button button-primary" type="submit" disabled data-certloom-library-background-submit>' . esc_html__( 'Create background draft', 'certloom' ) . '</button></form></section>';
	}

	/** @return void */
	private function library_pagination( $page, $total, $per_page, array $filters, $search, $kind ) {
		$pages = max( 1, (int) ceil( $total / $per_page ) ); if ( $pages <= 1 ) { return; }
		$base = array( 'page' => self::PAGE, 'design_view' => 'library', 'library_s' => $search ?: null, 'library_kind' => 'all' === $kind ? null : $kind );
		foreach ( $filters as $key => $value ) { if ( '' !== (string) $value ) { $base[ 'library_' . $key ] = $value; } }
		echo '<nav class="certloom-domain-pagination" aria-label="' . esc_attr__( 'Template Library pages', 'certloom' ) . '">';
		for ( $i = 1; $i <= $pages; $i++ ) { $url = add_query_arg( $base + array( 'library_paged' => $i ), admin_url( 'admin.php' ) ); echo '<a class="' . ( $i === (int) $page ? 'is-current' : '' ) . '" ' . ( $i === (int) $page ? 'aria-current="page" ' : '' ) . 'href="' . esc_url( $url ) . '">' . esc_html( (string) $i ) . '</a>'; }
		echo '</nav>';
	}

	/** Unified customer-design library with visual lifecycle controls. @return void */
	private function my_templates( TemplateRepository $repo ) {
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter.
		$page = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter.
		$status = isset( $_GET['template_status'] ) ? sanitize_key( wp_unslash( $_GET['template_status'] ) ) : 'active'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter.
		if ( ! in_array( $status, array( 'active', 'archived', 'all' ), true ) ) { $status = 'active'; }
		$per_page = 18;
		$result = $repo->list_page( $search, $page, $per_page, $status, 'custom' );
		$new_url = add_query_arg( array( 'page' => self::PAGE, 'design_view' => 'editor', 'library_action' => 'start_blank' ), admin_url( 'admin.php' ) ) . '#certloom-template-editor';

		echo '<section class="certloom-template-library-visual certloom-my-designs">';
		echo '<header class="certloom-template-library-hero"><div><span class="certloom-template-library-kicker">' . esc_html__( 'Your certificate collection', 'certloom' ) . '</span><h2>' . esc_html__( 'My designs', 'certloom' ) . '</h2><p>' . esc_html__( 'Create, review and publish your certificate designs here. Assignment stays in Design Rules, so editing a design never changes where it is used.', 'certloom' ) . '</p></div><div class="certloom-template-library-create"><a class="button button-primary" href="' . esc_url( $new_url ) . '">' . esc_html__( 'New design', 'certloom' ) . '</a></div></header>';
		echo '<nav class="certloom-template-lifecycle-tabs" aria-label="' . esc_attr__( 'Design status filter', 'certloom' ) . '">';
		foreach ( array( 'active' => __( 'Current', 'certloom' ), 'archived' => __( 'Archived', 'certloom' ), 'all' => __( 'All', 'certloom' ) ) as $key => $label ) {
			$url = add_query_arg( array( 'page' => self::PAGE, 'design_view' => 'templates', 'template_status' => $key, 's' => $search ?: null ), admin_url( 'admin.php' ) );
			echo '<a class="' . ( $key === $status ? 'is-current' : '' ) . '" ' . ( $key === $status ? 'aria-current="page" ' : '' ) . 'href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';
		echo '<p class="description certloom-template-archive-note">' . esc_html__( 'Archive is non-destructive: revisions and published history stay preserved. Restore an archived design before editing or publishing it again.', 'certloom' ) . '</p>';

		if ( is_wp_error( $result ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $result->get_error_message() ) . '</p></div></section>';
			return;
		}

		echo '<form method="get" class="certloom-template-library-search"><input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '"><input type="hidden" name="design_view" value="templates"><input type="hidden" name="template_status" value="' . esc_attr( $status ) . '"><label class="screen-reader-text" for="certloom-template-search">' . esc_html__( 'Search designs', 'certloom' ) . '</label><input id="certloom-template-search" type="search" name="s" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr__( 'Search your designs', 'certloom' ) . '"><button type="submit" class="button">' . esc_html_x( 'Search', 'search action button', 'certloom' ) . '</button></form>';
		if ( empty( $result['items'] ) ) {
			$clear_url = add_query_arg( array( 'page' => self::PAGE, 'design_view' => 'templates' ), admin_url( 'admin.php' ) );
			echo '<div class="certloom-template-library-empty"><strong>' . esc_html__( 'No designs found.', 'certloom' ) . '</strong><p>' . esc_html( '' !== $search || 'active' !== $status ? __( 'No designs match these filters. Clear the filters or create a new design.', 'certloom' ) : __( 'Create from scratch, customize a built-in certificate, or import a Certloom design package.', 'certloom' ) ) . '</p><div class="certloom-empty-state-actions"><a class="button button-primary" href="' . esc_url( $new_url ) . '">' . esc_html__( 'New design', 'certloom' ) . '</a>' . ( '' !== $search || 'active' !== $status ? '<a class="button" href="' . esc_url( $clear_url ) . '">' . esc_html__( 'Clear filters', 'certloom' ) . '</a>' : '' ) . '</div></div>';
		} else {
			echo '<div class="certloom-template-library-grid certloom-my-design-card-grid">';
			foreach ( $result['items'] as $item ) { $this->my_design_card( $repo, $item ); }
			echo '</div>';
		}
		$this->template_list_pagination( $page, (int) $result['total'], $per_page, $search, $status );
		$this->preview_dialog();
		echo '</section>';
	}

	/** @return void */
	private function my_design_card( TemplateRepository $repo, array $item ) {
		$id = absint( $item['id'] ?? 0 );
		$layout = is_array( $item['current_layout'] ?? null ) ? $item['current_layout'] : array();
		$preview = $this->visual_library_preview( array(
			'current_revision_id' => absint( $item['current_revision_id'] ?? 0 ),
			'revision_number' => absint( $item['current_revision_number'] ?? 0 ),
			'layout_sha256' => (string) ( $item['layout_sha256'] ?? '' ),
			'layout' => $layout,
		) );
		$edit_url = add_query_arg( array( 'page' => self::PAGE, 'design_view' => 'editor', 'template_id' => $id ), admin_url( 'admin.php' ) );
		$export_url = wp_nonce_url( add_query_arg( array( 'action' => 'certloom_template_export', 'template_id' => $id ), admin_url( 'admin-post.php' ) ), 'certloom_template_export_' . $id );
		$usage = $repo->usage_count( $id );
		$usage = is_wp_error( $usage ) ? 0 : absint( $usage );
		$archived = ! empty( $item['archived_at'] );
		$current_is_published = ! $archived && absint( $item['published_revision_id'] ?? 0 ) > 0 && absint( $item['published_revision_id'] ?? 0 ) === absint( $item['current_revision_id'] ?? 0 );
		$has_published = ! empty( $item['published_revision_id'] );
		$renderer = ( new RendererAuthorityGate() )->publication_status();
		$page = (array) ( $layout['page'] ?? array() );

		echo '<article class="certloom-template-library-card certloom-my-design-card"><div class="certloom-template-library-thumbnail" data-certloom-library-preview-source data-template-title="' . esc_attr( (string) ( $item['name'] ?? '' ) ) . '" data-page-width="' . esc_attr( (string) $preview['page_width'] ) . '" data-page-height="' . esc_attr( (string) $preview['page_height'] ) . '"><div class="certloom-template-library-thumbnail-stage" style="--certloom-thumb-scale:' . esc_attr( (string) $preview['scale'] ) . ';--certloom-thumb-width:' . esc_attr( (string) $preview['page_width'] ) . 'px;--certloom-thumb-height:' . esc_attr( (string) $preview['page_height'] ) . 'px">' . $preview['html'] . '</div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Canonical preview presenter output.
		echo '<div class="certloom-template-library-card-body"><div class="certloom-template-library-badges">' . $this->lifecycle_badge( $repo->lifecycle_state( $item ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped helper.
		if ( $archived ) { echo '<span>' . esc_html__( 'Archived', 'certloom' ) . '</span>'; }
		echo '</div><h3>' . esc_html( (string) ( $item['name'] ?? '' ) ) . '</h3><p>' . esc_html( (string) ( $item['description'] ?: __( 'No description yet.', 'certloom' ) ) ) . '</p>';
		echo '<div class="certloom-template-library-card-meta"><span>' . esc_html( strtoupper( (string) ( $page['size'] ?? '' ) ) ) . '</span><span>' . esc_html( ucfirst( (string) ( $page['orientation'] ?? '' ) ) ) . '</span><span>' . esc_html( sprintf( /* translators: %d: revision number. */ __( 'Revision %d', 'certloom' ), absint( $item['current_revision_number'] ?? 0 ) ) ) . '</span></div>';
		echo '<div class="certloom-template-card-publication"><div class="certloom-template-publication-copy"><strong>' . esc_html__( 'Publication', 'certloom' ) . '</strong><span class="certloom-template-publish-state ' . ( $current_is_published ? 'is-published' : 'is-draft' ) . '" data-certloom-publish-state>' . esc_html( $archived ? __( 'Archived', 'certloom' ) : ( $current_is_published ? __( 'Published', 'certloom' ) : ( $has_published ? __( 'Draft changes', 'certloom' ) : __( 'Draft', 'certloom' ) ) ) ) . '</span></div>';
		if ( $archived ) {
			echo '<span class="certloom-publication-switch-placeholder" aria-hidden="true"></span>';
		} elseif ( $current_is_published ) {
			echo '<button type="button" class="certloom-publication-switch is-on" role="switch" aria-checked="true" data-certloom-card-unpublish data-template-id="' . esc_attr( (string) $id ) . '" data-expected-updated-at="' . esc_attr( (string) ( $item['updated_at'] ?? '' ) ) . '" data-nonce="' . esc_attr( wp_create_nonce( 'certloom_template_quick_unpublish_' . $id ) ) . '"><span aria-hidden="true"></span><span class="screen-reader-text">' . esc_html__( 'Move published design back to Draft', 'certloom' ) . '</span></button>';
		} elseif ( ! empty( $renderer['accepted'] ) && ! empty( $item['layout_sha256'] ) ) {
			echo '<button type="button" class="certloom-publication-switch" role="switch" aria-checked="false" data-certloom-card-publish data-template-id="' . esc_attr( (string) $id ) . '" data-revision-id="' . esc_attr( (string) absint( $item['current_revision_id'] ?? 0 ) ) . '" data-revision-sha="' . esc_attr( (string) $item['layout_sha256'] ) . '" data-nonce="' . esc_attr( wp_create_nonce( 'certloom_template_prepare_publish_' . $id ) ) . '"><span aria-hidden="true"></span><span class="screen-reader-text">' . esc_html__( 'Publish this draft design', 'certloom' ) . '</span></button>';
		} else {
			echo '<button type="button" class="certloom-publication-switch" role="switch" aria-checked="false" disabled aria-disabled="true" title="' . esc_attr__( 'Publishing is waiting for final renderer acceptance on this site.', 'certloom' ) . '"><span aria-hidden="true"></span><span class="screen-reader-text">' . esc_html__( 'Publishing unavailable', 'certloom' ) . '</span></button>';
		}
		echo '</div><div class="certloom-template-library-actions"><button type="button" class="button" data-certloom-library-preview>' . esc_html__( 'Preview', 'certloom' ) . '</button><a class="button button-primary" href="' . esc_url( $edit_url ) . '">' . esc_html( $archived ? __( 'View design', 'certloom' ) : __( 'Edit', 'certloom' ) ) . '</a>';
		echo '<details class="certloom-template-card-more"><summary class="button" aria-label="' . esc_attr__( 'More actions', 'certloom' ) . '">•••</summary><div><a href="' . esc_url( $export_url ) . '">' . esc_html__( 'Export', 'certloom' ) . '</a><a href="' . esc_url( add_query_arg( array( 'page' => DesignRulesAdmin::PAGE, 'design_type' => 'certificate', 'design_id' => $id ), admin_url( 'admin.php' ) ) ) . '">' . esc_html( sprintf( /* translators: %d: number of direct design rules using this certificate design. */ _n( 'Used by %d rule', 'Used by %d rules', $usage, 'certloom' ), $usage ) ) . '</a>';
		if ( ! $archived ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="certloom_template_duplicate"><input type="hidden" name="template_id" value="' . esc_attr( (string) $id ) . '"><input type="hidden" name="expected_revision_id" value="' . esc_attr( (string) absint( $item['current_revision_id'] ?? 0 ) ) . '">'; wp_nonce_field( 'certloom_template_duplicate_' . $id ); echo '<button>' . esc_html__( 'Duplicate', 'certloom' ) . '</button></form>';
		}
		echo '</div></details></div><div class="certloom-template-card-feedback" data-certloom-card-feedback aria-live="polite"></div></div></article>';
	}

	/** @return void */
	private function preview_dialog() {
		echo '<dialog class="certloom-template-library-dialog" data-certloom-library-dialog aria-labelledby="certloom-template-library-dialog-title"><div class="certloom-template-library-dialog-head"><div><span>' . esc_html__( 'Certificate preview', 'certloom' ) . '</span><h2 id="certloom-template-library-dialog-title" data-certloom-library-dialog-title>' . esc_html__( 'Certificate preview', 'certloom' ) . '</h2></div><button type="button" class="button" data-certloom-library-dialog-close>' . esc_html__( 'Close', 'certloom' ) . '</button></div><div class="certloom-template-library-dialog-body" data-certloom-library-dialog-body></div><p class="description">' . esc_html__( 'This is an on-screen preview. PDF, PNG and JPEG remain renderer-gated until release acceptance is complete.', 'certloom' ) . '</p></dialog>';
	}

	/**
	 * Apply the same Global → Issuer → Program → Batch safety checks when an
	 * assignment is changed from the Design workspace instead of an entity form.
	 *
	 * @return true|\WP_Error
	 */
	private function assignment_hierarchy_preflight( $scope_type, $scope_id, $template_id ) {
		$scope_type = sanitize_key( (string) $scope_type );
		$scope_id = absint( $scope_id );
		$template_id = absint( $template_id );
		$guard = new DesignHierarchyConfigurationGuard();
		if ( TemplateAssignmentPolicy::SCOPE_GLOBAL === $scope_type ) {
			return $guard->validate_global( $template_id );
		}
		if ( TemplateAssignmentPolicy::SCOPE_ISSUER === $scope_type ) {
			$issuer = ( new IssuerRepository() )->find( $scope_id );
			if ( is_wp_error( $issuer ) ) { return $issuer; }
			if ( ! is_array( $issuer ) ) { return new \WP_Error( 'certloom_template_assignment_scope_missing', __( 'The selected Issuer no longer exists.', 'certloom' ) ); }
			return $guard->validate_issuer( $issuer, $template_id );
		}
		if ( TemplateAssignmentPolicy::SCOPE_PROGRAM === $scope_type ) {
			$program = ( new ProgramRepository() )->find( $scope_id );
			if ( is_wp_error( $program ) ) { return $program; }
			if ( ! is_array( $program ) ) { return new \WP_Error( 'certloom_template_assignment_scope_missing', __( 'The selected Program no longer exists.', 'certloom' ) ); }
			return $guard->validate_program( $program, $template_id );
		}
		if ( TemplateAssignmentPolicy::SCOPE_BATCH === $scope_type ) {
			$batch = ( new BatchRepository() )->find( $scope_id );
			if ( is_wp_error( $batch ) ) { return $batch; }
			if ( ! is_array( $batch ) ) { return new \WP_Error( 'certloom_template_assignment_scope_missing', __( 'The selected Batch no longer exists.', 'certloom' ) ); }
			$program = ( new ProgramRepository() )->find( absint( $batch['program_id'] ?? 0 ) );
			if ( is_wp_error( $program ) ) { return $program; }
			if ( ! is_array( $program ) ) { return new \WP_Error( 'certloom_template_assignment_relationship_corrupt', __( 'The selected Batch no longer has a valid Program.', 'certloom' ) ); }
			return $guard->validate_batch( $program, $batch, $template_id );
		}
		return new \WP_Error( 'certloom_template_assignment_scope_invalid', __( 'Choose a valid template assignment scope.', 'certloom' ) );
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
		$simple = AdminUiMode::is_simple();
		if ( is_wp_error( $editing ) ) { echo '<div class="notice notice-error"><p>' . esc_html( $editing->get_error_message() ) . '</p></div>'; return; }
		if ( ! is_array( $editing ) ) {
			echo '<section id="certloom-template-editor" class="certloom-template-editor"><h2>' . esc_html__( 'Create template draft', 'certloom' ) . '</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="certloom_template_create">'; wp_nonce_field( 'certloom_template_create' );
			$this->identity_fields( array() ); echo '<div class="certloom-template-create-page"><label><span>' . esc_html__( 'Page size', 'certloom' ) . '</span><select name="page_size"><option value="A4">A4</option><option value="LETTER">' . esc_html__( 'Letter', 'certloom' ) . '</option></select></label><label><span>' . esc_html__( 'Orientation', 'certloom' ) . '</span><select name="orientation"><option value="landscape">' . esc_html__( 'Landscape', 'certloom' ) . '</option><option value="portrait">' . esc_html__( 'Portrait', 'certloom' ) . '</option></select></label></div>'; submit_button( __( 'Create template', 'certloom' ) ); echo '</form></section>'; return;
		}
		$id = absint( $editing['id'] );
		$state = (string) ( $editing['lifecycle_state'] ?? TemplateLifecycle::state( $editing ) );
		$current = $editing['current_revision'] ?? array();
		$published = $editing['published_revision'] ?? array();
		$current_is_published = absint( $editing['published_revision_id'] ?? 0 ) > 0 && absint( $editing['published_revision_id'] ?? 0 ) === absint( $editing['current_revision_id'] ?? 0 );
		echo '<section id="certloom-template-editor" class="certloom-template-editor"><div class="certloom-template-editor-head"><div><h2>' . esc_html( $simple ? __( 'Edit design', 'certloom' ) : __( 'Template lifecycle & draft', 'certloom' ) ) . '</h2><p><strong>' . esc_html( (string) $editing['name'] ) . '</strong>' . ( $simple ? '' : ' <code>' . esc_html( (string) $editing['machine_key'] ) . '</code>' ) . '</p></div>' . $this->lifecycle_badge( $state ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Helper returns escaped fixed markup.
		if ( $simple ) {
			$simple_state_labels = array(
				TemplateLifecycle::DRAFT           => __( 'Draft', 'certloom' ),
				TemplateLifecycle::PUBLISHED       => __( 'Published', 'certloom' ),
				TemplateLifecycle::CHANGES_PENDING => __( 'Changes pending', 'certloom' ),
				TemplateLifecycle::ARCHIVED        => __( 'Archived', 'certloom' ),
			);
			$simple_state_label = $simple_state_labels[ $state ] ?? __( 'Draft', 'certloom' );
			echo '<div class="certloom-design-simple-status"><div><span class="certloom-eyebrow">' . esc_html__( 'Design status', 'certloom' ) . '</span><h3>' . esc_html( $simple_state_label ) . '</h3><p>' . esc_html__( 'Certloom keeps revision history automatically in the background.', 'certloom' ) . '</p></div></div>';
		} else { echo '<div class="certloom-template-lifecycle-summary"><div><span>' . esc_html__( 'Current revision', 'certloom' ) . '</span><strong>#' . esc_html( (string) absint( $editing['current_revision_id'] ?? 0 ) ) . '</strong><small><code>' . esc_html( substr( (string) ( $current['layout_sha256'] ?? '' ), 0, 12 ) ) . ( ! empty( $current['layout_sha256'] ) ? '…' : '' ) . '</code></small></div><div><span>' . esc_html__( 'Published revision', 'certloom' ) . '</span><strong>' . esc_html( empty( $editing['published_revision_id'] ) ? __( 'None', 'certloom' ) : '#' . absint( $editing['published_revision_id'] ) ) . '</strong><small><code>' . esc_html( substr( (string) ( $published['layout_sha256'] ?? '' ), 0, 12 ) ) . ( ! empty( $published['layout_sha256'] ) ? '…' : '' ) . '</code></small></div><div><span>' . esc_html__( 'Archive state', 'certloom' ) . '</span><strong>' . esc_html( empty( $editing['archived_at'] ) ? __( 'Active', 'certloom' ) : __( 'Archived', 'certloom' ) ) . '</strong><small>' . esc_html( (string) ( $editing['archived_at'] ?? '' ) ) . '</small></div></div>'; }

		$renderer_authority = ( new RendererAuthorityGate() )->publication_status();
		if ( TemplateLifecycle::ARCHIVED !== $state ) {
			echo '<div class="certloom-template-publish-callout"><div><strong>' . esc_html( $current_is_published ? __( 'This design is published', 'certloom' ) : __( 'Ready to use this design?', 'certloom' ) ) . '</strong><p>' . esc_html( $current_is_published ? __( 'Programs and issuance searches can find this published design now.', 'certloom' ) : __( 'Publish the current design so it becomes available in Programs, issuance and language-specific design selectors.', 'certloom' ) ) . '</p></div>';
			if ( ! $current_is_published && ! empty( $renderer_authority['accepted'] ) && ! empty( $current['layout_sha256'] ) ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="certloom_template_prepare_publish"><input type="hidden" name="template_id" value="' . esc_attr( (string) $id ) . '"><input type="hidden" name="expected_revision_id" value="' . esc_attr( (string) absint( $editing['current_revision_id'] ) ) . '"><input type="hidden" name="expected_revision_sha256" value="' . esc_attr( (string) $current['layout_sha256'] ) . '">'; wp_nonce_field( 'certloom_template_prepare_publish_' . $id ); echo '<button type="submit" class="button button-primary">' . esc_html__( 'Publish design', 'certloom' ) . '</button></form>';
			} elseif ( ! $current_is_published ) {
				echo '<span class="certloom-template-preview-authority">' . esc_html( $simple ? __( 'Publishing is not available on this site yet.', 'certloom' ) : __( 'Publishing is unavailable until renderer authority is available.', 'certloom' ) ) . '</span>';
			}
			echo '</div>';
		}

		$export = wp_nonce_url( add_query_arg( array( 'action' => 'certloom_template_export', 'template_id' => $id ), admin_url( 'admin-post.php' ) ), 'certloom_template_export_' . $id );
		echo '<div class="certloom-template-actionbar">'; if ( ! $simple ) { echo '<a class="button" href="' . esc_url( $export ) . '">' . esc_html__( 'Export template JSON', 'certloom' ) . '</a>'; }
		if ( TemplateLifecycle::ARCHIVED === $state ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="certloom_template_restore"><input type="hidden" name="template_id" value="' . esc_attr( (string) $id ) . '"><input type="hidden" name="expected_updated_at" value="' . esc_attr( (string) $editing['updated_at'] ) . '">'; wp_nonce_field( 'certloom_template_restore_' . $id ); echo '<button type="submit" class="button button-primary">' . esc_html__( 'Restore template', 'certloom' ) . '</button></form>';
		} else {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="certloom_template_archive"><input type="hidden" name="template_id" value="' . esc_attr( (string) $id ) . '"><input type="hidden" name="expected_updated_at" value="' . esc_attr( (string) $editing['updated_at'] ) . '">'; wp_nonce_field( 'certloom_template_archive_' . $id ); echo '<button type="submit" class="button certloom-button-danger" data-ecapla-confirm="' . esc_attr__( 'Archive this certificate design? It will stop appearing in normal active-design lists, while existing issued credential history remains unchanged.', 'certloom' ) . '">' . esc_html__( 'Archive template', 'certloom' ) . '</button></form>';
		}
		echo '</div>';
		if ( $simple ) { echo '<details class="certloom-technical-details certloom-template-maintenance-tools"><summary>' . esc_html__( 'Technical details & export', 'certloom' ) . '</summary><p class="description">' . esc_html__( 'Use these tools for support, migration or controlled maintenance. They do not change which design is published unless you explicitly save or publish a new revision.', 'certloom' ) . '</p><p><a class="button" href="' . esc_url( $export ) . '">' . esc_html__( 'Export template JSON', 'certloom' ) . '</a></p><div class="certloom-template-lifecycle-summary"><div><span>' . esc_html__( 'Current revision', 'certloom' ) . '</span><strong>#' . esc_html( (string) absint( $editing['current_revision_id'] ?? 0 ) ) . '</strong><small><code>' . esc_html( substr( (string) ( $current['layout_sha256'] ?? '' ), 0, 12 ) ) . ( ! empty( $current['layout_sha256'] ) ? '…' : '' ) . '</code></small></div><div><span>' . esc_html__( 'Published revision', 'certloom' ) . '</span><strong>' . esc_html( empty( $editing['published_revision_id'] ) ? __( 'None', 'certloom' ) : '#' . absint( $editing['published_revision_id'] ) ) . '</strong></div></div></details>'; }

		$this->duplicate_form( $editing );
		$this->preview_panel( $editing );
		if ( TemplateLifecycle::ARCHIVED === $state ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html( $simple ? __( 'Archived designs are read-only. Restore this design before editing, publishing or resetting it.', 'certloom' ) : __( 'Archived templates are read-only. Their immutable revision history and published revision remain preserved. Restore this template before changing metadata, saving a new draft, publishing, or resetting a factory preset.', 'certloom' ) ) . '</p></div></section>';
			return;
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="certloom_template_metadata"><input type="hidden" name="template_id" value="' . esc_attr( (string) $id ) . '"><input type="hidden" name="expected_updated_at" value="' . esc_attr( (string) $editing['updated_at'] ) . '">'; wp_nonce_field( 'certloom_template_metadata_' . $id ); $this->identity_fields( $editing ); submit_button( __( 'Save metadata', 'certloom' ) ); echo '</form>';
		try { $layout_json = ( new CanonicalJson() )->encode( $current['layout'] ?? TemplateDefaults::blank_a4_landscape() ); } catch ( \Throwable $e ) { $layout_json = ''; }
		$this->schema_upgrade_panel( $editing );
		$this->designer_panel( $editing, $layout_json );
		echo '<details class="certloom-template-json-editor"><summary>' . esc_html__( 'Canonical JSON editor', 'certloom' ) . '</summary><p class="description">' . esc_html__( 'The Designer and this technical editor save the same certloom-layout document. Every successful save creates a new immutable revision; there is no separate browser-only design format.', 'certloom' ) . '</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="certloom_template_revision"><input type="hidden" name="template_id" value="' . esc_attr( (string) $id ) . '"><input type="hidden" name="expected_revision_id" value="' . esc_attr( (string) absint( $editing['current_revision_id'] ) ) . '"><input type="hidden" name="expected_revision_sha256" value="' . esc_attr( (string) ( $current['layout_sha256'] ?? '' ) ) . '">'; wp_nonce_field( 'certloom_template_revision_' . $id );
		echo '<textarea name="layout_json" rows="18" class="large-text code" spellcheck="false">' . esc_textarea( $layout_json ) . '</textarea>'; submit_button( __( 'Save JSON as new revision', 'certloom' ) ); echo '</form></details>';

		echo '<details class="certloom-template-publication-details"><summary>' . esc_html__( 'Publication details', 'certloom' ) . '</summary><div class="certloom-template-publication-card"><div><h3>' . esc_html__( 'Publication review', 'certloom' ) . '</h3><p>' . esc_html__( 'Publication is bound to the exact immutable revision ID and SHA-256 shown here. A stale revision or hash is rejected even after renderer authority is accepted.', 'certloom' ) . '</p><p><code>#' . esc_html( (string) absint( $editing['current_revision_id'] ) ) . ' · ' . esc_html( (string) ( $current['layout_sha256'] ?? '' ) ) . '</code></p></div>';
		echo '<div><p class="description">' . esc_html__( 'Use the single Publish design action at the top of this editor. This section only shows the technical revision identity used for integrity checks.', 'certloom' ) . '</p></div>';
		echo '</div></details>';

		if ( 0 === strpos( (string) ( $editing['machine_key'] ?? '' ), 'certloom-preset-' ) ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="certloom_template_preset_reset"><input type="hidden" name="template_id" value="' . esc_attr( (string) $id ) . '"><input type="hidden" name="expected_revision_id" value="' . esc_attr( (string) absint( $editing['current_revision_id'] ) ) . '">';
			wp_nonce_field( 'certloom_template_preset_reset_' . $id );
			echo '<p class="description">' . esc_html( $simple ? __( 'Restoring the factory design keeps your existing design history so you can still review earlier changes.', 'certloom' ) : __( 'Restoring a factory design creates another immutable revision. Your current customized revision and any published revision remain in history.', 'certloom' ) ) . '</p>';
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
		if ( $version < 1 || $version >= LayoutSchema::VERSION ) { return; }
		echo '<details class="certloom-template-format-update"><summary>' . esc_html__( 'Older design format', 'certloom' ) . '</summary><p>' . esc_html__( 'This draft uses an older Certloom design format. You can keep editing compatible content, or update it without rewriting any historical revision.', 'certloom' ) . '</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="certloom_template_layout_upgrade"><input type="hidden" name="template_id" value="' . esc_attr( (string) absint( $editing['id'] ?? 0 ) ) . '"><input type="hidden" name="expected_revision_id" value="' . esc_attr( (string) absint( $editing['current_revision_id'] ?? 0 ) ) . '"><input type="hidden" name="expected_revision_sha256" value="' . esc_attr( (string) ( $current['layout_sha256'] ?? '' ) ) . '">';
		wp_nonce_field( 'certloom_template_layout_upgrade_' . absint( $editing['id'] ?? 0 ) );
		echo '<button type="submit" class="button">' . esc_html__( 'Update design format', 'certloom' ) . '</button></form></details>';
	}


	/** DSN-07 visual Designer with bounded per-user recovery autosave. @return void */
	private function designer_panel( array $editing, $layout_json ) {
		$simple = AdminUiMode::is_simple();
		$id = absint( $editing['id'] ?? 0 );
		$current_id = absint( $editing['current_revision_id'] ?? 0 );
		if ( $id < 1 || $current_id < 1 || '' === (string) $layout_json ) { return; }
		$decoded_layout = json_decode( (string) $layout_json, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded_layout ) ) { return; }
		$stored_state = get_user_meta( get_current_user_id(), $this->designer_state_key( $id ), true );
		$designer_state = ( new TemplateDesignerState() )->decode( is_string( $stored_state ) ? $stored_state : '', $decoded_layout );
		$schema_version = absint( $decoded_layout['_meta']['schema_version'] ?? 0 );
		$designer_state_json = wp_json_encode( $designer_state );
		if ( ! is_string( $designer_state_json ) ) { $designer_state_json = '{"version":2,"locked_ids":[],"hidden_ids":[],"layer_labels":{},"snap_enabled":true}'; }
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

		echo '<section id="certloom-template-designer" class="certloom-template-designer certloom-template-designer-v2" data-certloom-designer data-designer-default-mode="pro" data-designer-safe-margin-mm="10" data-layout-schema-version="' . esc_attr( (string) $schema_version ) . '" data-template-id="' . esc_attr( (string) $id ) . '" data-saved-revision="' . esc_attr( (string) $current_id ) . '" data-saved-sha256="' . esc_attr( $current_sha256 ) . '" data-autosave-status="' . esc_attr( $autosave_status ) . '" data-autosave-session="' . esc_attr( $autosave_session ) . '" data-autosave-nonce="' . esc_attr( $autosave_nonce ) . '" data-ajax-url="' . esc_url( admin_url( 'admin-ajax.php' ) ) . '">';
		echo '<div class="certloom-template-designer-head"><div><h3>' . esc_html__( 'Certificate Designer', 'certloom' ) . '</h3><p>' . esc_html__( 'Edit directly on the certificate. The canvas stays in view while you work through Layers and Properties.', 'certloom' ) . '</p></div><div class="certloom-template-designer-state"><span data-certloom-designer-dirty>' . esc_html__( 'Saved draft', 'certloom' ) . '</span>' . ( $simple ? '' : '<small>' . esc_html( sprintf( /* translators: %d: current id. */ __( 'Revision ID %d', 'certloom' ), $current_id ) ) . '</small>' ) . '</div></div>';
		if ( isset( $_GET['certloom_notice'] ) && 'revision_saved' === sanitize_key( wp_unslash( $_GET['certloom_notice'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Redirect status only.
			$is_error = isset( $_GET['certloom_error'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Redirect status only.
			$message = isset( $_GET['certloom_message'] ) ? sanitize_text_field( wp_unslash( $_GET['certloom_message'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Redirect status only.
			if ( ! $message ) { $message = $is_error ? __( 'Designer revision was not saved.', 'certloom' ) : sprintf( /* translators: %d: current revision number. */ __( 'Designer revision saved. You are now editing revision #%d.', 'certloom' ), absint( $editing['current_revision_number'] ?? 0 ) ); }
			echo '<div class="notice notice-' . ( $is_error ? 'error' : 'success' ) . ' inline certloom-designer-save-notice" role="status"><p><strong>' . esc_html( $is_error ? __( 'Save failed.', 'certloom' ) : __( 'Saved.', 'certloom' ) ) . '</strong> ' . esc_html( $message ) . '</p></div>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-certloom-designer-form><input type="hidden" name="action" value="certloom_template_revision"><input type="hidden" name="template_id" value="' . esc_attr( (string) $id ) . '"><input type="hidden" name="expected_revision_id" value="' . esc_attr( (string) $current_id ) . '"><input type="hidden" name="expected_revision_sha256" value="' . esc_attr( $current_sha256 ) . '">';
		wp_nonce_field( 'certloom_template_revision_' . $id );
		echo '<textarea hidden name="layout_json" data-certloom-designer-json>' . esc_textarea( (string) $layout_json ) . '</textarea>';
		echo '<textarea hidden name="designer_state_json" data-certloom-designer-state-json>' . esc_textarea( $designer_state_json ) . '</textarea>';
		echo '<input type="hidden" name="designer_autosave_session_id" value="" data-certloom-designer-autosave-session-field>';
		if ( '' !== $recovery_layout_json ) { echo '<textarea hidden data-certloom-designer-recovery-json>' . esc_textarea( $recovery_layout_json ) . '</textarea><textarea hidden data-certloom-designer-recovery-state-json>' . esc_textarea( $recovery_state_json ) . '</textarea>'; }
		if ( 'stale' === $autosave_status || 'invalid' === $autosave_status ) { echo '<div class="notice notice-warning inline certloom-template-autosave-warning"><p><strong>' . esc_html__( 'Recovery checkpoint not applied.', 'certloom' ) . '</strong> ' . esc_html__( 'The stored personal autosave is stale or invalid, so Certloom did not merge it into the authoritative current revision. Discard it after confirming you no longer need the older recovery copy.', 'certloom' ) . ' <button type="button" class="button button-small" data-certloom-designer-autosave-discard>' . esc_html__( 'Discard recovery checkpoint', 'certloom' ) . '</button></p></div>'; }
		echo '<div class="certloom-template-designer-toolbar" role="toolbar" aria-label="' . esc_attr__( 'Certificate Designer tools', 'certloom' ) . '"><button type="button" class="button certloom-designer-v2-insert" data-certloom-designer-insert-focus title="' . esc_attr__( 'Insert element', 'certloom' ) . '">' . esc_html__( '+ Insert', 'certloom' ) . '</button>';
		echo '<div class="certloom-template-designer-history" role="group" aria-label="' . esc_attr__( 'Designer history', 'certloom' ) . '"><button type="button" class="button" data-certloom-designer-undo disabled title="' . esc_attr__( 'Undo (Ctrl/Cmd+Z)', 'certloom' ) . '">' . esc_html__( 'Undo', 'certloom' ) . '</button><button type="button" class="button" data-certloom-designer-redo disabled title="' . esc_attr__( 'Redo (Ctrl/Cmd+Shift+Z)', 'certloom' ) . '">' . esc_html__( 'Redo', 'certloom' ) . '</button></div>';
		echo '<div class="certloom-template-designer-clipboard" role="group" aria-label="' . esc_attr__( 'Designer clipboard', 'certloom' ) . '"><button type="button" class="button" data-certloom-designer-copy disabled title="' . esc_attr__( 'Copy selection (Ctrl/Cmd+C)', 'certloom' ) . '">' . esc_html__( 'Copy', 'certloom' ) . '</button><button type="button" class="button" data-certloom-designer-cut disabled title="' . esc_attr__( 'Cut selection (Ctrl/Cmd+X)', 'certloom' ) . '">' . esc_html__( 'Cut', 'certloom' ) . '</button><button type="button" class="button" data-certloom-designer-paste disabled title="' . esc_attr__( 'Paste Designer clipboard (Ctrl/Cmd+V)', 'certloom' ) . '">' . esc_html__( 'Paste', 'certloom' ) . '</button></div>';
		echo '<label class="certloom-designer-pro-only"><span>' . esc_html__( 'Page size', 'certloom' ) . '</span><select data-certloom-designer-page-size><option value="A4">A4</option><option value="Letter">' . esc_html__( 'Letter', 'certloom' ) . '</option></select></label>';
		echo '<label class="certloom-designer-pro-only"><span>' . esc_html__( 'Orientation', 'certloom' ) . '</span><select data-certloom-designer-orientation><option value="landscape">' . esc_html__( 'Landscape', 'certloom' ) . '</option><option value="portrait">' . esc_html__( 'Portrait', 'certloom' ) . '</option></select></label>';
		echo '<label class="certloom-template-designer-zoom"><span>' . esc_html__( 'Zoom', 'certloom' ) . '</span><input type="range" min="25" max="200" step="5" value="75" data-certloom-designer-zoom><output data-certloom-designer-zoom-output>75%</output></label>';
		echo '<label class="certloom-template-designer-sample-data"><span>' . esc_html__( 'Sample data', 'certloom' ) . '</span><select data-certloom-designer-sample-data aria-describedby="certloom-designer-sample-data-note">';
		foreach ( array( TemplatePreviewSamples::STANDARD => __( 'Normal person', 'certloom' ), TemplatePreviewSamples::LONG => __( 'Long name', 'certloom' ), TemplatePreviewSamples::VERY_LONG_NAME => __( 'Very long name', 'certloom' ), TemplatePreviewSamples::RTL => __( 'RTL person', 'certloom' ), TemplatePreviewSamples::ORGANIZATION => __( 'Organization', 'certloom' ), TemplatePreviewSamples::LONG_ORGANIZATION => __( 'Long organization', 'certloom' ), TemplatePreviewSamples::LONG_ISO_SCOPE => __( 'Long ISO scope', 'certloom' ), TemplatePreviewSamples::SCHOOL_RESULT => __( 'School result', 'certloom' ) ) as $profile_key => $profile_label ) { echo '<option value="' . esc_attr( $profile_key ) . '">' . esc_html( $profile_label ) . '</option>'; }
		echo '</select><small id="certloom-designer-sample-data-note">' . esc_html__( 'Preview only', 'certloom' ) . '</small></label>';
		echo '<label class="certloom-template-designer-snap"><span>' . esc_html__( 'Guides', 'certloom' ) . '</span><span class="certloom-template-designer-check"><input type="checkbox" value="1" data-certloom-designer-snap> ' . esc_html__( 'Snap while moving', 'certloom' ) . '</span></label>';
		echo '<button type="button" class="button" data-certloom-designer-fit title="' . esc_attr__( 'Fit canvas (0)', 'certloom' ) . '">' . esc_html__( 'Fit', 'certloom' ) . '</button><button type="button" class="button" data-certloom-designer-true-size title="' . esc_attr__( 'Actual size / 100% (1)', 'certloom' ) . '">' . esc_html__( '1:1', 'certloom' ) . '</button><button type="button" class="button" data-certloom-designer-safe-area aria-pressed="false" title="' . esc_attr__( 'Show or hide the 10 mm safe area', 'certloom' ) . '">' . esc_html__( 'Safe area', 'certloom' ) . '</button><button type="button" class="button" data-certloom-designer-pan-tool aria-pressed="false" title="' . esc_attr__( 'Pan canvas (Space + drag)', 'certloom' ) . '">' . esc_html__( 'Pan', 'certloom' ) . '</button><button type="button" class="button" data-certloom-designer-preview-scroll>' . esc_html__( 'Preview', 'certloom' ) . '</button><button type="submit" class="button button-primary certloom-designer-v2-save" data-certloom-designer-quick-save title="' . esc_attr__( 'Save design (Ctrl/Cmd+S)', 'certloom' ) . '">' . esc_html__( 'Save design', 'certloom' ) . '</button><button type="button" class="button certloom-designer-pro-only" data-certloom-designer-reset title="' . esc_attr__( 'Reset unsaved changes', 'certloom' ) . '">' . esc_html__( 'Reset', 'certloom' ) . '</button>';
		echo '</div>';
		echo '<div class="certloom-template-designer-context-dock" data-certloom-designer-context-dock aria-label="' . esc_attr__( 'Selection tools', 'certloom' ) . '"><div class="certloom-template-designer-context-hint" data-certloom-designer-context-hint><strong>' . esc_html__( 'Selection tools', 'certloom' ) . '</strong><span>' . esc_html__( 'Select an element to show contextual controls.', 'certloom' ) . '</span></div>';
		echo '<div class="certloom-template-designer-quick-align" data-certloom-designer-quick-align role="toolbar" aria-label="' . esc_attr__( 'Context alignment and distribution controls', 'certloom' ) . '" hidden><span class="certloom-template-designer-productivity-label">' . esc_html__( 'Arrange', 'certloom' ) . '</span><span class="certloom-template-designer-selection-status" data-certloom-designer-selection-status>' . esc_html__( 'Shift/Ctrl/Cmd-click to select multiple', 'certloom' ) . '</span>';
		foreach ( array( 'left' => __( 'Left', 'certloom' ), 'center-x' => __( 'Center horizontally', 'certloom' ), 'right' => __( 'Right', 'certloom' ), 'top' => __( 'Top', 'certloom' ), 'center-y' => __( 'Center vertically', 'certloom' ), 'bottom' => __( 'Bottom', 'certloom' ) ) as $mode => $label ) {
			echo '<button type="button" class="button button-small" data-certloom-designer-align="' . esc_attr( $mode ) . '" disabled>' . esc_html( $label ) . '</button>';
		}
		echo '<span class="certloom-template-designer-distribute-group" data-certloom-designer-distribute-group hidden><span class="certloom-template-designer-productivity-label">' . esc_html__( 'Distribute', 'certloom' ) . '</span><button type="button" class="button button-small" data-certloom-designer-distribute="horizontal" disabled>' . esc_html__( 'Space horizontally', 'certloom' ) . '</button><button type="button" class="button button-small" data-certloom-designer-distribute="vertical" disabled>' . esc_html__( 'Space vertically', 'certloom' ) . '</button><span class="certloom-template-designer-spacing-status" data-certloom-designer-spacing-status>' . esc_html__( 'Select 3 or more elements to equalize spacing.', 'certloom' ) . '</span></span></div>';
		echo '<div class="certloom-template-designer-quick-text" data-certloom-designer-quick-text hidden role="toolbar" aria-label="' . esc_attr__( 'Text controls', 'certloom' ) . '"><span class="certloom-template-designer-productivity-label">' . esc_html__( 'Text', 'certloom' ) . '</span><label><span class="screen-reader-text">' . esc_html__( 'Font', 'certloom' ) . '</span><select data-certloom-designer-quick-style="font_family"><option value="sans-serif">' . esc_html__( 'Sans Serif', 'certloom' ) . '</option><option value="serif">' . esc_html__( 'Serif', 'certloom' ) . '</option><option value="monospace">' . esc_html__( 'Monospace', 'certloom' ) . '</option></select></label><label><span class="screen-reader-text">' . esc_html__( 'Size (pt)', 'certloom' ) . '</span><input type="number" min="1" max="300" step="0.5" inputmode="decimal" data-certloom-designer-quick-style="font_size_pt" aria-label="' . esc_attr__( 'Text size in points', 'certloom' ) . '"></label><button type="button" class="button button-small" data-certloom-designer-quick-toggle="bold" aria-pressed="false">' . esc_html__( 'Bold', 'certloom' ) . '</button><button type="button" class="button button-small" data-certloom-designer-quick-toggle="italic" aria-pressed="false">' . esc_html__( 'Italic', 'certloom' ) . '</button><label><span class="screen-reader-text">' . esc_html__( 'Text color', 'certloom' ) . '</span><input type="color" value="#000000" data-certloom-designer-quick-style="color" aria-label="' . esc_attr__( 'Text color', 'certloom' ) . '"></label><button type="button" class="button button-small" data-certloom-designer-quick-text-align="left" aria-pressed="false">' . esc_html__( 'Left', 'certloom' ) . '</button><button type="button" class="button button-small" data-certloom-designer-quick-text-align="center" aria-pressed="false">' . esc_html__( 'Center', 'certloom' ) . '</button><button type="button" class="button button-small" data-certloom-designer-quick-text-align="right" aria-pressed="false">' . esc_html__( 'Right', 'certloom' ) . '</button><button type="button" class="button button-small" data-certloom-designer-context-edit-text hidden>' . esc_html__( 'Edit text', 'certloom' ) . '</button><button type="button" class="button button-small" data-certloom-designer-context-rich-text hidden>' . esc_html__( 'Edit inline content', 'certloom' ) . '</button><label data-certloom-designer-context-token-wrap hidden><span class="screen-reader-text">' . esc_html__( 'Dynamic field', 'certloom' ) . '</span><select data-certloom-designer-context-token><option value="recipient.name">' . esc_html__( 'Recipient Name', 'certloom' ) . '</option><option value="credential.display_number">' . esc_html__( 'Certificate Number', 'certloom' ) . '</option><option value="credential.title">' . esc_html__( 'Credential Title', 'certloom' ) . '</option><option value="program.name">' . esc_html__( 'Program', 'certloom' ) . '</option><option value="credential.issue_date">' . esc_html__( 'Issue Date', 'certloom' ) . '</option><option value="credential.expiry_date">' . esc_html__( 'Expiry Date', 'certloom' ) . '</option><option value="credential.grade">' . esc_html__( 'Grade', 'certloom' ) . '</option><option value="issuer.name">' . esc_html__( 'Issuer', 'certloom' ) . '</option><option value="signer.name">' . esc_html__( 'Signer name', 'certloom' ) . '</option><option value="signer.title">' . esc_html__( 'Signer title', 'certloom' ) . '</option><option value="signer.email">' . esc_html__( 'Signer email', 'certloom' ) . '</option><option value="credential.status">' . esc_html__( 'Status', 'certloom' ) . '</option></select></label></div>';
		echo '<div class="certloom-template-designer-context-controls" data-certloom-designer-context-group="asset" hidden><span class="certloom-template-designer-productivity-label">' . esc_html__( 'Image', 'certloom' ) . '</span><label><span>' . esc_html__( 'Fit', 'certloom' ) . '</span><select data-certloom-designer-content-field="fit"><option value="contain">' . esc_html__( 'Contain', 'certloom' ) . '</option><option value="cover">' . esc_html__( 'Cover', 'certloom' ) . '</option><option value="fill">' . esc_html__( 'Fill', 'certloom' ) . '</option></select></label><button type="button" class="button button-small" data-certloom-designer-context-media>' . esc_html__( 'Replace media', 'certloom' ) . '</button></div>';
		echo '<div class="certloom-template-designer-context-controls" data-certloom-designer-context-group="signature" hidden><span class="certloom-template-designer-productivity-label">' . esc_html__( 'Signature', 'certloom' ) . '</span><label><span>' . esc_html__( 'Fit', 'certloom' ) . '</span><select data-certloom-designer-content-field="fit"><option value="contain">' . esc_html__( 'Contain', 'certloom' ) . '</option><option value="cover">' . esc_html__( 'Cover', 'certloom' ) . '</option><option value="fill">' . esc_html__( 'Fill', 'certloom' ) . '</option></select></label><button type="button" class="button button-small" data-certloom-designer-signature-source="signer">' . esc_html__( 'Use credential Signer', 'certloom' ) . '</button><button type="button" class="button button-small" data-certloom-designer-context-media>' . esc_html__( 'Choose fixed image', 'certloom' ) . '</button></div>';
		echo '<div class="certloom-template-designer-context-controls" data-certloom-designer-context-group="shape" hidden><span class="certloom-template-designer-productivity-label">' . esc_html__( 'Shape', 'certloom' ) . '</span><label><span>' . esc_html__( 'Fill', 'certloom' ) . '</span><input type="text" maxlength="9" placeholder="#FFFFFF00" data-certloom-designer-style-field="fill"></label><label><span>' . esc_html__( 'Stroke', 'certloom' ) . '</span><input type="text" maxlength="9" placeholder="#000000" data-certloom-designer-style-field="stroke"></label><label><span>' . esc_html__( 'Stroke width (mm)', 'certloom' ) . '</span><input type="number" min="0" max="20" step="0.1" inputmode="decimal" data-certloom-designer-style-field="stroke_width_mm"></label></div>';
		echo '<div class="certloom-template-designer-context-controls" data-certloom-designer-context-group="line" hidden><span class="certloom-template-designer-productivity-label">' . esc_html__( 'Line', 'certloom' ) . '</span><label><span>' . esc_html__( 'Stroke', 'certloom' ) . '</span><input type="text" maxlength="9" placeholder="#000000" data-certloom-designer-style-field="stroke"></label><label><span>' . esc_html__( 'Stroke width (mm)', 'certloom' ) . '</span><input type="number" min="0.001" max="20" step="0.1" inputmode="decimal" data-certloom-designer-style-field="stroke_width_mm"></label></div>';
		echo '<div class="certloom-template-designer-context-controls" data-certloom-designer-context-group="qr" hidden><span class="certloom-template-designer-productivity-label">' . esc_html__( 'QR', 'certloom' ) . '</span><label><span>' . esc_html__( 'Error correction', 'certloom' ) . '</span><select data-certloom-designer-content-field="error_correction"><option value="L">L</option><option value="M">M</option><option value="Q">Q</option><option value="H">H</option></select></label><label><span>' . esc_html__( 'Quiet zone (modules)', 'certloom' ) . '</span><input type="number" min="4" max="16" step="1" data-certloom-designer-content-field="quiet_zone_modules"></label><span class="description">' . esc_html__( 'Verification URL is the canonical source.', 'certloom' ) . '</span></div>';
		echo '<div class="certloom-template-designer-context-controls" data-certloom-designer-context-group="barcode" hidden><span class="certloom-template-designer-productivity-label">' . esc_html__( 'Barcode', 'certloom' ) . '</span><span class="description">' . esc_html__( 'Code 128 · certificate number', 'certloom' ) . '</span><label><span>' . esc_html__( 'Quiet zone (modules)', 'certloom' ) . '</span><input type="number" min="10" max="50" step="1" data-certloom-designer-content-field="quiet_zone_modules"></label></div>';
		echo '<div class="certloom-template-designer-context-controls" data-certloom-designer-context-group="table" hidden><span class="certloom-template-designer-productivity-label">' . esc_html__( 'Table', 'certloom' ) . '</span><label class="certloom-template-designer-style-check"><span>' . esc_html__( 'Header', 'certloom' ) . '</span><input type="checkbox" value="1" data-certloom-designer-table-header></label><span class="description">' . esc_html__( 'Fixed rows · edit columns and cells in Properties.', 'certloom' ) . '</span></div>';
		echo '<div class="certloom-template-designer-context-controls" data-certloom-designer-context-group="data_table" hidden><span class="certloom-template-designer-productivity-label">' . esc_html__( 'Data Table', 'certloom' ) . '</span><label><span>' . esc_html__( 'Rows', 'certloom' ) . '</span><input type="number" min="1" max="50" step="1" data-certloom-designer-table-max-rows></label><label class="certloom-template-designer-style-check"><span>' . esc_html__( 'Header', 'certloom' ) . '</span><input type="checkbox" value="1" data-certloom-designer-table-header></label></div>';
		echo '</div>';
		echo '<div class="certloom-template-designer-productivity certloom-designer-pro-only" data-certloom-designer-productivity role="toolbar" aria-label="' . esc_attr__( 'Selected element alignment and layer controls', 'certloom' ) . '"><span class="certloom-template-designer-productivity-label">' . esc_html__( 'Align', 'certloom' ) . '</span>';
		foreach ( array( 'left' => __( 'Left', 'certloom' ), 'center-x' => __( 'Center horizontally', 'certloom' ), 'right' => __( 'Right', 'certloom' ), 'top' => __( 'Top', 'certloom' ), 'center-y' => __( 'Center vertically', 'certloom' ), 'bottom' => __( 'Bottom', 'certloom' ) ) as $mode => $label ) {
			echo '<button type="button" class="button button-small" data-certloom-designer-align="' . esc_attr( $mode ) . '" disabled>' . esc_html( $label ) . '</button>';
		}
		echo '<span class="certloom-template-designer-productivity-label">' . esc_html__( 'Distribute', 'certloom' ) . '</span><button type="button" class="button button-small" data-certloom-designer-distribute="horizontal" disabled>' . esc_html__( 'Space horizontally', 'certloom' ) . '</button><button type="button" class="button button-small" data-certloom-designer-distribute="vertical" disabled>' . esc_html__( 'Space vertically', 'certloom' ) . '</button>';
		echo '<span class="certloom-template-designer-productivity-label">' . esc_html__( 'Layer', 'certloom' ) . '</span>';
		$layer_shortcuts = array( 'front' => 'Ctrl/Cmd+Shift+]', 'forward' => 'Ctrl/Cmd+]', 'backward' => 'Ctrl/Cmd+[', 'back' => 'Ctrl/Cmd+Shift+[' );
		foreach ( array( 'front' => __( 'Bring to front', 'certloom' ), 'forward' => __( 'Bring forward', 'certloom' ), 'backward' => __( 'Send backward', 'certloom' ), 'back' => __( 'Send to back', 'certloom' ) ) as $mode => $label ) {
			echo '<button type="button" class="button button-small" data-certloom-designer-layer-action="' . esc_attr( $mode ) . '" disabled title="' . esc_attr( $label . ' (' . $layer_shortcuts[ $mode ] . ')' ) . '">' . esc_html( $label ) . '</button>';
		}
		echo '<button type="button" class="button button-small" data-certloom-designer-lock disabled aria-pressed="false">' . esc_html__( 'Lock element', 'certloom' ) . '</button></div>';
		echo '<div class="certloom-template-designer-stage"><aside class="certloom-template-designer-add-panel" aria-label="' . esc_attr__( 'Layers and insert elements', 'certloom' ) . '"><div class="certloom-template-designer-layers certloom-template-designer-layers-panel certloom-template-designer-layers-primary"><div class="certloom-template-designer-layers-head"><h4>' . esc_html__( 'Layers', 'certloom' ) . '</h4><span>' . esc_html__( 'Front → back', 'certloom' ) . '</span></div><div data-certloom-designer-layers></div></div><div class="certloom-template-designer-add-head"><strong>' . esc_html__( 'Insert', 'certloom' ) . '</strong><small>' . esc_html__( 'Click or drag onto canvas', 'certloom' ) . '</small></div><div class="certloom-template-designer-palette" role="toolbar" aria-label="' . esc_attr__( 'Insert or remove Designer elements', 'certloom' ) . '"><span class="certloom-template-designer-productivity-label">' . esc_html__( 'Insert', 'certloom' ) . '</span>';
		$registry_labels = $this->designer_registry_labels();
		foreach ( ElementRegistry::palette() as $palette_item ) {
			$kind = (string) $palette_item['kind'];
			$minimum = absint( $palette_item['min_schema_version'] ?? 1 );
			$maximum = absint( $palette_item['max_schema_version'] ?? 0 );
			if ( $maximum && $schema_version > $maximum ) { continue; }
			$label_key = (string) ( $palette_item['label_key'] ?? $kind );
			$label = isset( $registry_labels[ $label_key ] ) ? $registry_labels[ $label_key ] : $kind;
			$disabled = $schema_version < $minimum;
			$title = $disabled ? sprintf( /* translators: %d: minimum schema version. */ __( 'Upgrade this draft to certloom-layout v%d before adding this element.', 'certloom' ), $minimum ) : '';
			$quick_kinds = array( 'text', 'image', 'logo', 'merge', 'qr', 'rectangle', 'line' );
			$quick = in_array( $kind, $quick_kinds, true );
			$button_label = 'merge' === $kind ? __( 'Dynamic Field', 'certloom' ) : ( 'rectangle' === $kind ? __( 'Shape', 'certloom' ) : $label );
			$category = in_array( $kind, array( 'text', 'merge', 'rich_text', 'list', 'table', 'data_table' ), true ) ? 'content' : ( in_array( $kind, array( 'image', 'logo', 'seal', 'signature' ), true ) ? 'media' : 'graphics' );
			echo '<button type="button" class="button button-small ' . ( $quick ? 'certloom-designer-quick-palette' : 'certloom-designer-pro-only' ) . '" data-certloom-designer-add="' . esc_attr( $kind ) . '" data-certloom-designer-palette-item data-certloom-designer-palette-category="' . esc_attr( $category ) . '" ' . ( ! $disabled ? 'draggable="true" ' : '' ) . ( $disabled ? 'disabled aria-disabled="true" title="' . esc_attr( $title ) . '"' : 'title="' . esc_attr( sprintf( /* translators: %s: element name. */ __( 'Add %s', 'certloom' ), $button_label ) ) . '"' ) . '>' . esc_html( $button_label ) . '</button>';
		}
		echo '<span class="certloom-template-designer-palette-spacer"></span><button type="button" class="button button-small" data-certloom-designer-duplicate disabled title="' . esc_attr__( 'Duplicate selected (Ctrl/Cmd+D)', 'certloom' ) . '">' . esc_html__( 'Duplicate selected', 'certloom' ) . '</button><button type="button" class="button button-small certloom-button-danger" data-certloom-designer-remove disabled title="' . esc_attr__( 'Delete selected (Delete/Backspace)', 'certloom' ) . '">' . esc_html__( 'Delete selected', 'certloom' ) . '</button></div></aside>';
		echo '<div class="certloom-template-designer-shell"><div class="certloom-template-designer-workspace" data-certloom-designer-workspace tabindex="0" aria-label="' . esc_attr__( 'Certificate Designer canvas workspace', 'certloom' ) . '"><div class="certloom-template-designer-page" data-certloom-designer-page role="application" aria-label="' . esc_attr__( 'Certificate page canvas', 'certloom' ) . '"></div></div>';
		echo '<aside class="certloom-template-designer-inspector" aria-label="' . esc_attr__( 'Designer properties', 'certloom' ) . '"><div data-certloom-designer-empty><h4>' . esc_html__( 'Properties', 'certloom' ) . '</h4><p>' . esc_html__( 'Select an element to edit its content, appearance and exact geometry.', 'certloom' ) . '</p></div><div hidden data-certloom-designer-group><h4>' . esc_html__( 'Selection', 'certloom' ) . '</h4><p><strong data-certloom-designer-group-count>0</strong> ' . esc_html__( 'elements selected. Drag the selection together or use Align and Distribute. Click one item without a modifier to return to its individual properties.', 'certloom' ) . '</p></div><div hidden data-certloom-designer-fields><div class="certloom-template-designer-element-meta"><span data-certloom-designer-type></span><code data-certloom-designer-id></code><em data-certloom-designer-lock-status></em></div><section class="certloom-template-designer-property-section" data-certloom-designer-property-section="geometry"><h4>' . esc_html__( 'Position & size', 'certloom' ) . '</h4><div class="certloom-template-designer-geometry-grid">';
		foreach ( array( 'x' => __( 'X (mm)', 'certloom' ), 'y' => __( 'Y (mm)', 'certloom' ), 'width' => __( 'Width (mm)', 'certloom' ), 'height' => __( 'Height (mm)', 'certloom' ), 'rotation' => __( 'Rotation (°)', 'certloom' ) ) as $key => $label ) {
			echo '<label><span>' . esc_html( $label ) . '</span><input type="number" step="any" inputmode="decimal" data-certloom-designer-field="' . esc_attr( $key ) . '"></label>';
		}
		echo '</div></section><section class="certloom-template-designer-property-section" data-certloom-designer-property-section="content"><h4>' . esc_html__( 'Content', 'certloom' ) . '</h4><div class="certloom-template-designer-content-editor" data-certloom-designer-content-editor>';
		echo '<div hidden data-certloom-designer-content="text"><label><span>' . esc_html__( 'Text content', 'certloom' ) . '</span><textarea rows="4" maxlength="20000" data-certloom-designer-content-field="text"></textarea></label></div>';
		$catalog_options = $this->designer_catalog_field_options();
		$catalog_datalist = $this->designer_catalog_field_datalist();
		$custom_token_option = '<option value="__custom__">' . esc_html__( 'Technical custom field…', 'certloom' ) . '</option>';
		$custom_token_editor = '<label class="certloom-designer-token-advanced" data-certloom-designer-custom-token><span>' . esc_html__( 'Technical field token', 'certloom' ) . '</span><input type="text" maxlength="128" pattern="[a-z][a-z0-9_.:-]{0,127}" data-certloom-designer-content-field="token" list="certloom-designer-merge-tokens"><small>' . esc_html__( 'Use this only when the field you need is not listed above.', 'certloom' ) . '</small></label>';
		echo '<div hidden data-certloom-designer-content="merge"><label class="certloom-designer-quick-only"><span>' . esc_html__( 'Dynamic field', 'certloom' ) . '</span><select data-certloom-designer-quick-token><option value="recipient.name">' . esc_html__( 'Recipient Name', 'certloom' ) . '</option><option value="credential.display_number">' . esc_html__( 'Certificate Number', 'certloom' ) . '</option><option value="credential.title">' . esc_html__( 'Credential Title', 'certloom' ) . '</option><option value="program.name">' . esc_html__( 'Program', 'certloom' ) . '</option><option value="credential.issue_date">' . esc_html__( 'Issue Date', 'certloom' ) . '</option><option value="credential.expiry_date">' . esc_html__( 'Expiry Date', 'certloom' ) . '</option><option value="credential.grade">' . esc_html__( 'Grade', 'certloom' ) . '</option><option value="issuer.name">' . esc_html__( 'Issuer', 'certloom' ) . '</option><option value="signer.name">' . esc_html__( 'Signer name', 'certloom' ) . '</option><option value="signer.title">' . esc_html__( 'Signer title', 'certloom' ) . '</option><option value="signer.email">' . esc_html__( 'Signer email', 'certloom' ) . '</option><option value="credential.status">' . esc_html__( 'Status', 'certloom' ) . '</option>' . $catalog_options . $custom_token_option . '</select><small>' . esc_html__( 'Choose information that should be filled automatically when the credential is issued.', 'certloom' ) . '</small></label>' . $custom_token_editor . '<datalist id="certloom-designer-merge-tokens"><option value="recipient.name"><option value="recipient.first_name"><option value="recipient.last_name"><option value="program.name"><option value="program.title"><option value="issuer.name"><option value="signer.name"><option value="signer.title"><option value="signer.email"><option value="credential.display_number"><option value="credential.title"><option value="credential.public_id"><option value="credential.issue_date"><option value="credential.expiry_date"><option value="credential.grade"><option value="credential.status"><option value="batch.name">' . $catalog_datalist . '</datalist></div>';
		echo '<div hidden data-certloom-designer-content="rich_text" class="certloom-template-designer-rich-text-editor"><div class="certloom-template-designer-rich-text-head"><div><strong>' . esc_html__( 'Inline content', 'certloom' ) . '</strong><p class="description">' . esc_html__( 'Combine literal text and dynamic fields without HTML. Inline styles inherit from Appearance unless you override them here.', 'certloom' ) . '</p></div><div class="certloom-template-designer-rich-text-add"><button type="button" class="button button-small" data-certloom-designer-rich-add="text">' . esc_html__( 'Add text', 'certloom' ) . '</button><button type="button" class="button button-small" data-certloom-designer-rich-add="token">' . esc_html__( 'Add field', 'certloom' ) . '</button></div></div><div data-certloom-designer-rich-runs></div><p class="description">' . esc_html__( 'Each run stays structured and bounded. Dynamic fields resolve from the immutable credential snapshot when issued.', 'certloom' ) . '</p></div>';
		echo '<div hidden data-certloom-designer-content="asset"><label><span>' . esc_html__( 'Local asset reference', 'certloom' ) . '</span><input type="text" maxlength="150" data-certloom-designer-content-field="asset" placeholder="attachment:123"></label><label><span>' . esc_html__( 'Fit', 'certloom' ) . '</span><select data-certloom-designer-content-field="fit"><option value="contain">' . esc_html__( 'Contain', 'certloom' ) . '</option><option value="cover">' . esc_html__( 'Cover', 'certloom' ) . '</option><option value="fill">' . esc_html__( 'Fill', 'certloom' ) . '</option></select></label><button type="button" class="button" data-certloom-designer-media>' . esc_html__( 'Choose from Media Library', 'certloom' ) . '</button></div>';
		echo '<div hidden data-certloom-designer-content="signature"><p class="description" data-certloom-designer-signature-status></p><label><span>' . esc_html__( 'Fit', 'certloom' ) . '</span><select data-certloom-designer-content-field="fit"><option value="contain">' . esc_html__( 'Contain', 'certloom' ) . '</option><option value="cover">' . esc_html__( 'Cover', 'certloom' ) . '</option><option value="fill">' . esc_html__( 'Fill', 'certloom' ) . '</option></select></label><div class="certloom-template-designer-inline-actions"><button type="button" class="button" data-certloom-designer-signature-source="signer">' . esc_html__( 'Use credential Signer signature', 'certloom' ) . '</button><button type="button" class="button" data-certloom-designer-media>' . esc_html__( 'Choose fixed signature image', 'certloom' ) . '</button></div><p class="description">' . esc_html__( 'Credential Signer uses the immutable Signer snapshot captured when the credential is issued. Fixed image keeps the selected WordPress Media Library asset.', 'certloom' ) . '</p></div>';
		echo '<div hidden data-certloom-designer-content="shape"><label><span>' . esc_html__( 'Shape', 'certloom' ) . '</span><select data-certloom-designer-content-field="shape"><option value="rectangle">' . esc_html__( 'Rectangle', 'certloom' ) . '</option><option value="ellipse">' . esc_html__( 'Ellipse', 'certloom' ) . '</option></select></label></div>';
		echo '<div hidden data-certloom-designer-content="qr"><label><span>' . esc_html__( 'Error correction', 'certloom' ) . '</span><select data-certloom-designer-content-field="error_correction"><option value="L">L</option><option value="M">M</option><option value="Q">Q</option><option value="H">H</option></select></label><label><span>' . esc_html__( 'Quiet zone (modules)', 'certloom' ) . '</span><input type="number" min="4" max="16" step="1" data-certloom-designer-content-field="quiet_zone_modules"></label><p class="description">' . esc_html__( 'The QR code always uses the credential’s verification link.', 'certloom' ) . '</p></div>';
		echo '<div hidden data-certloom-designer-content="barcode"><label><span>' . esc_html__( 'Barcode format', 'certloom' ) . '</span><input type="text" value="Code 128" readonly aria-readonly="true"></label><label><span>' . esc_html__( 'Uses', 'certloom' ) . '</span><input type="text" value="' . esc_attr__( 'Certificate number', 'certloom' ) . '" readonly aria-readonly="true"></label><label><span>' . esc_html__( 'Quiet zone (modules)', 'certloom' ) . '</span><input type="number" min="10" max="50" step="1" data-certloom-designer-content-field="quiet_zone_modules"></label><p class="description">' . esc_html__( 'The barcode represents the credential’s certificate number.', 'certloom' ) . '</p></div>';
		echo '<div hidden data-certloom-designer-content="list"><label><span>' . esc_html__( 'List items', 'certloom' ) . '</span><textarea rows="6" maxlength="20000" data-certloom-designer-content-field="items" placeholder="First item&#10;Second item"></textarea></label><label><span>' . esc_html__( 'Marker style', 'certloom' ) . '</span><select data-certloom-designer-content-field="list_style"><option value="bullet">' . esc_html__( 'Bullets', 'certloom' ) . '</option><option value="decimal">' . esc_html__( 'Numbered', 'certloom' ) . '</option></select></label><p class="description">' . esc_html__( 'Each non-empty line becomes one list item. Certloom keeps wrapping and spacing consistent across preview and downloadable files.', 'certloom' ) . '</p></div>';
		echo '<div hidden data-certloom-designer-content="table" class="certloom-template-designer-table-editor"><div class="certloom-template-designer-table-head"><strong>' . esc_html__( 'Columns', 'certloom' ) . '</strong><button type="button" class="button button-small" data-certloom-designer-table-add-column>' . esc_html__( 'Add column', 'certloom' ) . '</button></div><div data-certloom-designer-table-columns></div><div class="certloom-template-designer-table-head"><strong>' . esc_html__( 'Rows', 'certloom' ) . '</strong><button type="button" class="button button-small" data-certloom-designer-table-add-row>' . esc_html__( 'Add row', 'certloom' ) . '</button></div><div data-certloom-designer-table-rows></div><label class="certloom-template-designer-style-check"><span>' . esc_html__( 'Show header row', 'certloom' ) . '</span><input type="checkbox" value="1" data-certloom-designer-table-header></label><p class="description">' . esc_html__( 'Fixed Table cells are literal certificate content. Use Data Table when rows should come from immutable credential results or subjects.', 'certloom' ) . '</p></div>';
		echo '<div hidden data-certloom-designer-content="data_table" class="certloom-template-designer-table-editor"><label><span>' . esc_html__( 'Immutable row source', 'certloom' ) . '</span><select data-certloom-designer-table-source><option value="credential.results">' . esc_html__( 'Credential results', 'certloom' ) . '</option><option value="credential.subjects">' . esc_html__( 'Credential subjects', 'certloom' ) . '</option></select></label><label><span>' . esc_html__( 'Maximum rows', 'certloom' ) . '</span><input type="number" min="1" max="50" step="1" data-certloom-designer-table-max-rows></label><label class="certloom-template-designer-style-check"><span>' . esc_html__( 'Show header row', 'certloom' ) . '</span><input type="checkbox" value="1" data-certloom-designer-table-header></label><div class="certloom-template-designer-table-head"><strong>' . esc_html__( 'Columns', 'certloom' ) . '</strong><button type="button" class="button button-small" data-certloom-designer-table-add-column>' . esc_html__( 'Add column', 'certloom' ) . '</button></div><div data-certloom-designer-table-columns></div><p class="description">' . esc_html__( 'Data Table rows resolve only from the credential’s immutable issuance snapshot. Live Recipient, Program or remote data is never read during rendering.', 'certloom' ) . '</p></div>';
		echo '<div hidden data-certloom-designer-content="line"><p class="description">' . esc_html__( 'Adjust the line’s appearance below.', 'certloom' ) . '</p></div></div></section>';
		echo '<section class="certloom-template-designer-property-section" data-certloom-designer-property-section="appearance"><div class="certloom-template-designer-style-editor" data-certloom-designer-style-editor><div class="certloom-template-designer-style-head"><h4>' . esc_html__( 'Appearance', 'certloom' ) . '</h4><span>' . esc_html__( 'Controlled styles', 'certloom' ) . '</span></div>';
		echo '<div class="certloom-template-designer-style-grid" data-certloom-designer-style="general"><label><span>' . esc_html__( 'Opacity', 'certloom' ) . '</span><input type="number" min="0" max="1" step="0.05" inputmode="decimal" data-certloom-designer-style-field="opacity"></label></div>';
		echo '<div class="certloom-template-designer-style-group" hidden data-certloom-designer-style="text"><div class="certloom-template-designer-style-grid"><label><span>' . esc_html__( 'Font', 'certloom' ) . '</span><select data-certloom-designer-style-field="font_family"><option value="sans-serif">' . esc_html__( 'Sans Serif', 'certloom' ) . '</option><option value="serif">' . esc_html__( 'Serif', 'certloom' ) . '</option><option value="monospace">' . esc_html__( 'Monospace', 'certloom' ) . '</option></select></label><label><span>' . esc_html__( 'Size (pt)', 'certloom' ) . '</span><input type="number" min="1" max="300" step="0.5" inputmode="decimal" data-certloom-designer-style-field="font_size_pt"></label><label><span>' . esc_html__( 'Weight', 'certloom' ) . '</span><select data-certloom-designer-style-field="font_weight">';
		foreach ( range( 100, 900, 100 ) as $weight ) { echo '<option value="' . esc_attr( (string) $weight ) . '">' . esc_html( (string) $weight ) . '</option>'; }
		echo '</select></label><label class="certloom-template-designer-style-check"><span>' . esc_html__( 'Italic', 'certloom' ) . '</span><input type="checkbox" value="1" data-certloom-designer-style-field="italic"></label><label><span>' . esc_html__( 'Text color', 'certloom' ) . '</span><span class="certloom-template-designer-color-control"><input type="color" value="#000000" data-certloom-designer-color-picker aria-label="' . esc_attr__( 'Choose text color', 'certloom' ) . '"><input type="text" maxlength="9" pattern="#[0-9A-Fa-f]{6}([0-9A-Fa-f]{2})?" placeholder="#000000" data-certloom-designer-style-field="color"></span></label><label><span>' . esc_html__( 'Horizontal align', 'certloom' ) . '</span><select data-certloom-designer-style-field="align"><option value="left">' . esc_html__( 'Left', 'certloom' ) . '</option><option value="center">' . esc_html__( 'Center', 'certloom' ) . '</option><option value="right">' . esc_html__( 'Right', 'certloom' ) . '</option><option value="start">' . esc_html__( 'Start', 'certloom' ) . '</option><option value="end">' . esc_html__( 'End', 'certloom' ) . '</option></select></label><label><span>' . esc_html__( 'Vertical align', 'certloom' ) . '</span><select data-certloom-designer-style-field="vertical_align"><option value="top">' . esc_html__( 'Top', 'certloom' ) . '</option><option value="middle">' . esc_html__( 'Middle', 'certloom' ) . '</option><option value="bottom">' . esc_html__( 'Bottom', 'certloom' ) . '</option></select></label><label><span>' . esc_html__( 'Direction', 'certloom' ) . '</span><select data-certloom-designer-style-field="direction"><option value="auto">' . esc_html__( 'Auto', 'certloom' ) . '</option><option value="ltr">LTR</option><option value="rtl">RTL</option></select></label><label><span>' . esc_html__( 'Line height', 'certloom' ) . '</span><input type="number" min="0.5" max="5" step="0.05" inputmode="decimal" data-certloom-designer-style-field="line_height"></label><label><span>' . esc_html__( 'Letter spacing (pt)', 'certloom' ) . '</span><input type="number" min="-20" max="50" step="0.1" inputmode="decimal" data-certloom-designer-style-field="letter_spacing_pt"></label><label><span>' . esc_html__( 'Overflow', 'certloom' ) . '</span><select data-certloom-designer-style-field="overflow"><option value="shrink">' . esc_html__( 'Shrink', 'certloom' ) . '</option><option value="wrap">' . esc_html__( 'Wrap', 'certloom' ) . '</option><option value="clip">' . esc_html__( 'Clip', 'certloom' ) . '</option></select></label></div><p class="description">' . esc_html( $simple ? __( 'Choose from Certloom’s supported local font families. The same supported font choice is kept for preview and downloadable files.', 'certloom' ) : __( 'Font choices use stable controlled local/browser family keys only. No remote font URL or arbitrary CSS family is accepted; production artifacts still fail closed unless the exact renderer FontManifest provides the selected family, weight and style bytes.', 'certloom' ) ) . '</p></div>';
		echo '<div class="certloom-template-designer-style-group" hidden data-certloom-designer-style="shape"><div class="certloom-template-designer-style-grid"><label><span>' . esc_html__( 'Fill', 'certloom' ) . '</span><input type="text" maxlength="9" placeholder="#FFFFFF00" data-certloom-designer-style-field="fill"></label><label><span>' . esc_html__( 'Stroke', 'certloom' ) . '</span><input type="text" maxlength="9" placeholder="#000000" data-certloom-designer-style-field="stroke"></label><label><span>' . esc_html__( 'Stroke width (mm)', 'certloom' ) . '</span><input type="number" min="0" max="20" step="0.1" inputmode="decimal" data-certloom-designer-style-field="stroke_width_mm"></label></div></div>';
		echo '<div class="certloom-template-designer-style-group" hidden data-certloom-designer-style="line"><div class="certloom-template-designer-style-grid"><label><span>' . esc_html__( 'Stroke', 'certloom' ) . '</span><input type="text" maxlength="9" placeholder="#000000" data-certloom-designer-style-field="stroke"></label><label><span>' . esc_html__( 'Stroke width (mm)', 'certloom' ) . '</span><input type="number" min="0.001" max="20" step="0.1" inputmode="decimal" data-certloom-designer-style-field="stroke_width_mm"></label></div></div></div>';
		echo '<div class="certloom-template-designer-style-group" hidden data-certloom-designer-style="table"><div class="certloom-template-designer-style-grid"><label><span>' . esc_html__( 'Font', 'certloom' ) . '</span><select data-certloom-designer-style-field="font_family"><option value="sans-serif">' . esc_html__( 'Sans Serif', 'certloom' ) . '</option><option value="serif">' . esc_html__( 'Serif', 'certloom' ) . '</option><option value="monospace">' . esc_html__( 'Monospace', 'certloom' ) . '</option></select></label><label><span>' . esc_html__( 'Size (pt)', 'certloom' ) . '</span><input type="number" min="1" max="300" step="0.5" data-certloom-designer-style-field="font_size_pt"></label><label><span>' . esc_html__( 'Text color', 'certloom' ) . '</span><input type="text" maxlength="9" data-certloom-designer-style-field="color"></label><label><span>' . esc_html__( 'Header fill', 'certloom' ) . '</span><input type="text" maxlength="9" data-certloom-designer-style-field="header_fill"></label><label><span>' . esc_html__( 'Header text', 'certloom' ) . '</span><input type="text" maxlength="9" data-certloom-designer-style-field="header_color"></label><label><span>' . esc_html__( 'Header weight', 'certloom' ) . '</span><select data-certloom-designer-style-field="header_font_weight">'; foreach ( range( 100, 900, 100 ) as $weight ) { echo '<option value="' . esc_attr( (string) $weight ) . '">' . esc_html( (string) $weight ) . '</option>'; } echo '</select></label><label><span>' . esc_html__( 'Row fill', 'certloom' ) . '</span><input type="text" maxlength="9" data-certloom-designer-style-field="row_fill"></label><label><span>' . esc_html__( 'Alternate row', 'certloom' ) . '</span><input type="text" maxlength="9" data-certloom-designer-style-field="alternate_row_fill"></label><label><span>' . esc_html__( 'Border color', 'certloom' ) . '</span><input type="text" maxlength="9" data-certloom-designer-style-field="border_color"></label><label><span>' . esc_html__( 'Border width (mm)', 'certloom' ) . '</span><input type="number" min="0" max="5" step="0.1" data-certloom-designer-style-field="border_width_mm"></label><label><span>' . esc_html__( 'Cell padding (mm)', 'certloom' ) . '</span><input type="number" min="0" max="10" step="0.1" data-certloom-designer-style-field="cell_padding_mm"></label></div></div>';
		echo '</div></section>';
		echo '<p class="description">' . esc_html__( 'Arrow keys nudge 1 mm, Shift+Arrow 5 mm and Alt/Option+Arrow 0.1 mm. Space + drag temporarily pans the canvas; Shift+F10 opens element actions for the selection. Ctrl/Cmd+Z undoes; Ctrl/Cmd+Shift+Z or Ctrl/Cmd+Y redoes. Locked elements remain selectable but cannot be transformed, reordered, removed or content-edited.', 'certloom' ) . '</p></div></aside></div></div>';
		echo '<div class="certloom-template-designer-footer"><p class="description"><span data-certloom-designer-page-readout></span> · <span data-certloom-designer-autosave-status>' . esc_html__( 'Recovery autosave ready', 'certloom' ) . '</span>' . ( $simple ? '' : ' · ' . esc_html__( 'Manual save remains the only authoritative revision write.', 'certloom' ) ) . '</p><button type="submit" class="button button-primary button-large" data-certloom-designer-save>' . esc_html( $simple ? __( 'Save design', 'certloom' ) : __( 'Save Designer revision', 'certloom' ) ) . '</button></div>';
		echo '</form>';
		echo '<details class="certloom-template-designer-boundary"><summary>' . esc_html__( 'Technical integrity', 'certloom' ) . '</summary><p><strong>' . esc_html__( 'Designer boundary:', 'certloom' ) . '</strong> ' . esc_html__( 'DSN-08 is a closed built-in registry: no WordPress filter, arbitrary HTML/CSS callback or unversioned third-party type can mutate the canonical element contract. Future extensions must first earn an explicit layout-schema version plus validator/resolver/renderer support. Manual save and publication authority remain unchanged.', 'certloom' ) . '</p></details>';
		echo '<div class="notice notice-error inline" hidden data-certloom-designer-error><p></p></div><div class="screen-reader-text" aria-live="polite" data-certloom-designer-live></div></section>';
	}



	/** Catalog-backed custom merge options for the Designer quick picker. @return string */
	private function designer_catalog_field_options() {
		if ( ! class_exists( '\\Ecapla_CertVerify_Fields' ) ) { return ''; }
		$html = '';
		foreach ( \Ecapla_CertVerify_Fields::get_scopable_fields() as $field ) {
			$key = sanitize_key( (string) ( $field['key'] ?? '' ) );
			if ( '' === $key ) { continue; }
			$label = trim( (string) ( $field['label'] ?? $key ) );
			$token = \Certloom\Application\Fields\ScopedFieldContract::merge_token( $key );
			if ( '' === $token || in_array( $token, array( 'credential.grade' ), true ) ) { continue; }
			$owner = 'recipient' === \Certloom\Application\Fields\ScopedFieldContract::value_source( $key ) ? __( 'Recipient field', 'certloom' ) : __( 'Credential field', 'certloom' );
			$html .= '<option value="' . esc_attr( $token ) . '">' . esc_html( sprintf( /* translators: 1: field ownership, 2: field label. */ __( '%1$s · %2$s', 'certloom' ), $owner, $label ) ) . '</option>';
		}
		return $html;
	}

	/** Catalog-backed datalist tokens for advanced Designer entry. @return string */
	private function designer_catalog_field_datalist() {
		if ( ! class_exists( '\\Ecapla_CertVerify_Fields' ) ) { return ''; }
		$html = '';
		foreach ( \Ecapla_CertVerify_Fields::get_scopable_fields() as $field ) {
			$key = sanitize_key( (string) ( $field['key'] ?? '' ) );
			if ( '' === $key ) { continue; }
			$token = \Certloom\Application\Fields\ScopedFieldContract::merge_token( $key );
			if ( '' === $token || in_array( $token, array( 'credential.grade' ), true ) ) { continue; }
			$html .= '<option value="' . esc_attr( $token ) . '"></option>';
		}
		return $html;
	}

	/** Translatable labels referenced by the executable-free typed registry contract. @return array<string,string> */
	private function designer_registry_labels() {
		return array(
			'text' => __( 'Text', 'certloom' ), 'mergeField' => __( 'Merge field', 'certloom' ), 'image' => __( 'Image', 'certloom' ),
			'logo' => __( 'Logo', 'certloom' ), 'signature' => __( 'Signature', 'certloom' ), 'seal' => __( 'Seal', 'certloom' ),
			'qr' => __( 'QR', 'certloom' ), 'barcode' => __( 'Barcode', 'certloom' ), 'list' => __( 'List', 'certloom' ), 'richText' => __( 'Rich text', 'certloom' ),
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
		$simple = AdminUiMode::is_simple();
		$id = absint( $editing['id'] ?? 0 );
		$revision_mode = isset( $_GET['preview_revision'] ) && 'published' === sanitize_key( wp_unslash( $_GET['preview_revision'] ) ) ? 'published' : 'current'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only Preview selector.
		$sample_profile = isset( $_GET['preview_sample'] ) ? sanitize_key( wp_unslash( $_GET['preview_sample'] ) ) : TemplatePreviewSamples::STANDARD; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only Preview selector.
		if ( ! in_array( $sample_profile, TemplatePreviewSamples::profiles(), true ) ) { $sample_profile = TemplatePreviewSamples::STANDARD; }
		$has_published = ! empty( $editing['published_revision_id'] ) && ! empty( $editing['published_revision'] ) && is_array( $editing['published_revision'] );
		if ( 'published' === $revision_mode && ! $has_published ) { $revision_mode = 'current'; }

		$authority = ( new RendererAuthorityGate() )->manifest_status();
		$preview = ( new TemplatePreviewService() )->build( $editing, $revision_mode, $sample_profile );
		echo '<section class="certloom-template-preview-card"><div class="certloom-template-preview-head"><div><h3>' . esc_html__( 'Template Preview', 'certloom' ) . '</h3><p>' . esc_html__( 'Read-only on-screen sample of this certificate design.', 'certloom' ) . '</p></div><div class="certloom-template-preview-badges"><span class="certloom-domain-preview-badge">' . esc_html( $simple ? __( 'On-screen preview', 'certloom' ) : __( 'Shared layout preview', 'certloom' ) ) . '</span>';
		if ( ! empty( $authority['accepted'] ) ) {
			echo '<span class="certloom-template-preview-authority is-accepted">' . esc_html__( 'Production rendering ready', 'certloom' ) . '</span>';
		} else {
			echo '<span class="certloom-template-preview-authority">' . esc_html__( 'Production downloads unavailable', 'certloom' ) . '</span>';
		}
		echo '</div></div>';
		echo '<div class="notice notice-info inline certloom-template-preview-honesty"><p><strong>' . esc_html__( 'Preview only:', 'certloom' ) . '</strong> ' . esc_html__( 'This screen shows layout, direction, QR, colors and element composition for review. PDF, PNG and JPEG downloads remain unavailable until production rendering checks pass.', 'certloom' ) . '</p></div>';
		echo '<details class="certloom-template-preview-technical"><summary>' . esc_html__( 'Rendering details', 'certloom' ) . '</summary><p>' . esc_html__( 'Canonical Render Plan evidence remains available here for support and release verification. Artifact authority pending means PDF-04 exact renderer/font acceptance has not been approved; this screen never manufactures production artifact authority.', 'certloom' ) . '</p></details>';

		echo '<div class="certloom-template-preview-controls"><div><span class="certloom-template-preview-control-label">' . esc_html__( 'Revision', 'certloom' ) . '</span><nav aria-label="' . esc_attr__( 'Template Preview revision', 'certloom' ) . '">';
		foreach ( array( 'current' => __( 'Current draft', 'certloom' ), 'published' => __( 'Published', 'certloom' ) ) as $key => $label ) {
			if ( 'published' === $key && ! $has_published ) { echo '<span class="is-disabled" aria-disabled="true">' . esc_html( $label ) . '</span>'; continue; }
			$url = add_query_arg( array( 'page' => self::PAGE, 'template_id' => $id, 'preview_revision' => $key, 'preview_sample' => $sample_profile ), admin_url( 'admin.php' ) );
			echo '<a class="' . ( $revision_mode === $key ? 'is-current' : '' ) . '" ' . ( $revision_mode === $key ? 'aria-current="page" ' : '' ) . 'href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</nav></div><div><span class="certloom-template-preview-control-label">' . esc_html__( 'Sample data', 'certloom' ) . '</span><nav aria-label="' . esc_attr__( 'Template Preview sample data', 'certloom' ) . '">';
		foreach ( array( TemplatePreviewSamples::STANDARD => __( 'Normal person', 'certloom' ), TemplatePreviewSamples::LONG => __( 'Long name', 'certloom' ), TemplatePreviewSamples::VERY_LONG_NAME => __( 'Very long name', 'certloom' ), TemplatePreviewSamples::RTL => __( 'RTL person', 'certloom' ), TemplatePreviewSamples::ORGANIZATION => __( 'Organization', 'certloom' ), TemplatePreviewSamples::LONG_ORGANIZATION => __( 'Long organization', 'certloom' ), TemplatePreviewSamples::LONG_ISO_SCOPE => __( 'Long ISO scope', 'certloom' ), TemplatePreviewSamples::SCHOOL_RESULT => __( 'School result', 'certloom' ) ) as $key => $label ) {
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
			echo '<div class="notice notice-error inline"><p>' . esc_html( $simple ? __( 'The design preview could not be shown safely in this browser.', 'certloom' ) : __( 'The canonical Render Plan passed preparation but could not be presented safely in the browser.', 'certloom' ) ) . '</p></div></section>';
			return;
		}

		echo '<details class="certloom-technical-details certloom-template-preview-technical"><summary>' . esc_html__( 'Technical details', 'certloom' ) . '</summary><p class="description">' . esc_html__( 'Revision and integrity identifiers are available for support and auditing. They are checked automatically when Certloom uses this design.', 'certloom' ) . '</p><div class="certloom-template-preview-proof"><div><span>' . esc_html__( 'Revision', 'certloom' ) . '</span><strong>#' . esc_html( (string) absint( $preview['revision_number'] ) ) . '</strong><small>ID ' . esc_html( (string) absint( $preview['revision_id'] ) ) . '</small></div><div><span>' . esc_html__( 'Layout SHA-256', 'certloom' ) . '</span><code>' . esc_html( (string) $preview['layout_sha256'] ) . '</code></div><div><span>' . esc_html__( 'Render Plan SHA-256', 'certloom' ) . '</span><code>' . esc_html( (string) $preview['render_plan_sha256'] ) . '</code></div></div></details>';
		foreach ( (array) ( $presented['warnings'] ?? array() ) as $warning ) { echo '<div class="notice notice-warning inline"><p>' . esc_html( (string) $warning ) . '</p></div>'; }
		echo $presented['html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- RenderPlanHtmlPreview escapes every Render Plan field and emits only fixed markup.
		echo '<p class="description certloom-template-preview-footnote">' . esc_html( $simple ? __( 'Sample preview data never uses real Recipient records. Use the sample profiles to stress names, organizations, RTL text, long scopes and school results before issuing.', 'certloom' ) : __( 'Synthetic Preview values never read or mutate credential records. Use the sample profiles to stress names, organizations, RTL text, long scopes and school results without reading real recipient data.', 'certloom' ) ) . '</p></section>';
	}

	/** @return void */
	private function duplicate_form( array $editing ) {
		$id = absint( $editing['id'] ?? 0 );
		$base_key = preg_replace( '/-copy(?:-[0-9]+)?$/', '', (string) ( $editing['machine_key'] ?? 'template' ) );
		$suggested_key = sanitize_key( $base_key . '-copy' );
		echo '<details id="certloom-template-duplicate" class="certloom-template-duplicate"><summary>' . esc_html__( 'Duplicate as a new draft', 'certloom' ) . '</summary><p class="description">' . esc_html( AdminUiMode::is_simple() ? __( 'Create a separate draft copy of this design. The copy starts unpublished and is not assigned to any Program.', 'certloom' ) : __( 'Duplication copies only the reviewed current revision. It creates a fresh template identity with no published state and no assignments.', 'certloom' ) ) . '</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="certloom_template_duplicate"><input type="hidden" name="template_id" value="' . esc_attr( (string) $id ) . '"><input type="hidden" name="expected_revision_id" value="' . esc_attr( (string) absint( $editing['current_revision_id'] ?? 0 ) ) . '"><input type="hidden" name="description" value="' . esc_attr( (string) ( $editing['description'] ?? '' ) ) . '">'; wp_nonce_field( 'certloom_template_duplicate_' . $id );
		echo '<div class="certloom-template-duplicate-fields"><label><span>' . esc_html__( 'New name', 'certloom' ) . '</span><input class="regular-text" name="name" maxlength="255" required value="' . esc_attr( (string) $editing['name'] . ' ' . __( 'Copy', 'certloom' ) ) . '"></label>'; if ( AdminUiMode::is_simple() ) { echo '<details class="certloom-technical-details certloom-inline-technical"><summary>' . esc_html__( 'Technical details', 'certloom' ) . '</summary><label><span>' . esc_html__( 'New machine key', 'certloom' ) . '</span><input class="regular-text code" name="machine_key" maxlength="191" pattern="[a-z0-9_-]+" value="' . esc_attr( $suggested_key ) . '"></label></details>'; } else { echo '<label><span>' . esc_html__( 'New machine key', 'certloom' ) . '</span><input class="regular-text code" name="machine_key" maxlength="191" required value="' . esc_attr( $suggested_key ) . '"></label>'; } echo '</div>'; submit_button( __( 'Create duplicate draft', 'certloom' ), 'secondary', 'submit', false ); echo '</form></details>';
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
		$curated = new \Certloom\Application\Templates\CuratedPresetCatalog();
		$families = PresetCatalog::families();
		$variants = PresetCatalog::variants();
		echo '<div class="card" style="max-width:none"><h2>' . esc_html__( 'Premium factory designs', 'certloom' ) . '</h2>';
		echo '<p>' . esc_html( sprintf( /* translators: %1$d: families; %2$d: variants; %3$d: catalog; %4$d: curated; %5$d: curated. */ __( '%1$d historical design families × %2$d compatibility variants = %3$d preserved layouts, plus %4$d additive curated premium concepts. Total factory drafts after synchronization: %5$d. Factory layouts remain local drafts until the production renderer gate is accepted.', 'certloom' ), count( $families ), count( $variants ), count( $catalog->all() ), count( $curated->all() ), count( $catalog->all() ) + count( $curated->all() ) ) ) . '</p>';
		echo '<ul style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:8px 18px">';
		foreach ( $families as $family ) { echo '<li><strong>' . esc_html( $family['name'] ) . '</strong><br><span class="description">' . esc_html( $family['description'] ) . '</span></li>'; }
		echo '</ul><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="certloom_template_presets_sync">';
		wp_nonce_field( 'certloom_template_presets_sync' ); submit_button( __( 'Install / synchronize factory drafts', 'certloom' ), 'secondary', 'submit', false ); echo '</form></div>';
	}

	/** @return void */
	private function identity_fields( array $data ) {
		$is_factory = 0 === strpos( (string) ( $data['machine_key'] ?? '' ), 'certloom-preset-' );
		$simple = AdminUiMode::is_simple();
		if ( $simple ) {
			echo '<div class="certloom-form-grid certloom-form-grid-2 certloom-design-identity-grid"><label class="certloom-field-control"><span>' . esc_html__( 'Design name', 'certloom' ) . '</span><input id="certloom-template-name" name="name" maxlength="255" required value="' . esc_attr( (string) ( $data['name'] ?? '' ) ) . '"></label><label class="certloom-field-control"><span>' . esc_html__( 'Description', 'certloom' ) . '</span><textarea rows="3" id="certloom-template-description" name="description" placeholder="' . esc_attr__( 'Optional note about when to use this design', 'certloom' ) . '">' . esc_textarea( (string) ( $data['description'] ?? '' ) ) . '</textarea></label></div>';
			echo '<details class="certloom-technical-details certloom-design-identity-technical"><summary>' . esc_html__( 'Technical details', 'certloom' ) . '</summary><label class="certloom-field-control" for="certloom-template-key"><span>' . esc_html__( 'Machine key', 'certloom' ) . '</span><input class="regular-text code" id="certloom-template-key" name="machine_key" maxlength="191" pattern="[a-z0-9_-]+" ' . ( $is_factory ? 'readonly aria-readonly="true" ' : '' ) . 'value="' . esc_attr( (string) ( $data['machine_key'] ?? '' ) ) . '" placeholder="' . esc_attr__( 'Generated automatically when left blank', 'certloom' ) . '"><small>' . esc_html( $is_factory ? __( 'Factory design identity is permanent.', 'certloom' ) : __( 'Stable internal identity used by inheritance, imports and integrations. Leave blank on a new design to generate one automatically.', 'certloom' ) ) . '</small></label></details>';
			return;
		}
		echo '<table class="form-table"><tr><th><label for="certloom-template-name">' . esc_html__( 'Name', 'certloom' ) . '</label></th><td><input class="regular-text" id="certloom-template-name" name="name" maxlength="255" required value="' . esc_attr( (string) ( $data['name'] ?? '' ) ) . '"></td></tr><tr><th><label for="certloom-template-key">' . esc_html__( 'Machine key', 'certloom' ) . '</label></th><td><input class="regular-text code" id="certloom-template-key" name="machine_key" maxlength="191" required ' . ( $is_factory ? 'readonly aria-readonly="true" ' : '' ) . 'value="' . esc_attr( (string) ( $data['machine_key'] ?? '' ) ) . '"><p class="description">' . esc_html( $is_factory ? __( 'Factory template identity is permanent. Duplicate/export the design for a separately named template.', 'certloom' ) : __( 'Stable lowercase key used by assignments, imports and future integrations.', 'certloom' ) ) . '</p></td></tr><tr><th><label for="certloom-template-description">' . esc_html__( 'Description', 'certloom' ) . '</label></th><td><textarea class="large-text" rows="4" id="certloom-template-description" name="description">' . esc_textarea( (string) ( $data['description'] ?? '' ) ) . '</textarea></td></tr></table>';
	}

	/** Generate a stable internal key when the task-focused editor leaves the machine identity blank. @return string */
	private function posted_machine_key( $name ) {
		$key = isset( $_POST['machine_key'] ) ? sanitize_key( wp_unslash( $_POST['machine_key'] ) ) : '';
		if ( '' !== $key ) { return $key; }
		$base = sanitize_key( preg_replace( '/[^a-z0-9]+/i', '-', strtolower( (string) $name ) ) );
		$base = '' !== $base ? trim( $base, '-' ) : 'design';
		$suffix = substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 10 );
		return substr( $base . '-' . $suffix, 0, 191 );
	}

	/** @return void */
	private function import_form() {
		echo '<div class="certloom-design-import-inline"><h3>' . esc_html__( 'Import certificate design', 'certloom' ) . '</h3><p>' . esc_html__( 'Import a validated Certloom certificate design package. The imported design is created as a draft and is not assigned anywhere automatically.', 'certloom' ) . '</p><form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="certloom_template_import">'; wp_nonce_field( 'certloom_template_import' ); echo '<label class="certloom-design-file-drop"><span>' . esc_html__( 'Choose design package', 'certloom' ) . '</span><input type="file" name="design_package" accept="application/json,.json" required><small>' . esc_html( sprintf( /* translators: %s: maximum upload size for this site. */ __( 'Certloom JSON package · up to %s', 'certloom' ), $this->design_package_upload_limit_label( TemplatePackage::MAX_BYTES ) ) ) . '</small></label><button type="submit" class="button button-primary">' . esc_html__( 'Import design', 'certloom' ) . '</button></form></div>';
	}

	private function design_package_upload_limit( $package_limit ) {
		$package_limit = max( 1, absint( $package_limit ) );
		$site_limit = function_exists( 'wp_max_upload_size' ) ? absint( wp_max_upload_size() ) : 0;
		return $site_limit > 0 ? min( $package_limit, $site_limit ) : $package_limit;
	}

	private function design_package_upload_limit_label( $package_limit ) {
		$bytes = $this->design_package_upload_limit( $package_limit );
		return function_exists( 'size_format' ) ? size_format( $bytes, 0 ) : (string) max( 1, (int) floor( $bytes / 1048576 ) ) . ' MB';
	}


	/** @return void */
	private function import_dialog() {
		echo '<dialog class="certloom-design-import-dialog" data-certloom-certificate-import-dialog aria-labelledby="certloom-certificate-import-title"><div class="certloom-template-library-dialog-head"><div><span>' . esc_html__( 'Portable design package', 'certloom' ) . '</span><h2 id="certloom-certificate-import-title">' . esc_html__( 'Import certificate design', 'certloom' ) . '</h2></div><button type="button" class="button" data-certloom-certificate-import-close>' . esc_html__( 'Close', 'certloom' ) . '</button></div><div class="certloom-design-import-dialog-body">';
		$this->import_form();
		echo '</div></dialog>';
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
	private function redirect( $result, $notice, array $args = array(), $fragment = '' ) {
		$query = array_merge( array( 'page' => self::PAGE, 'certloom_notice' => sanitize_key( $notice ) ), $args );
		if ( is_wp_error( $result ) ) { $query['certloom_error'] = '1'; $query['certloom_message'] = $result->get_error_message(); }
		else { $query['certloom_message'] = __( 'Template changes were saved.', 'certloom' ); }
		$url = add_query_arg( $query, admin_url( 'admin.php' ) );
		if ( '' !== (string) $fragment ) { $url .= '#' . sanitize_html_class( (string) $fragment ); }
		wp_safe_redirect( $url ); exit;
	}

	/** @return void */
	private function require_capability() {
		if ( ! current_user_can( \Ecapla_CertVerify_Permissions::CAP_MANAGE_TEMPLATES ) ) { wp_die( esc_html__( 'You are not allowed to manage Certloom templates.', 'certloom' ), '', array( 'response' => 403 ) ); }
	}
}
