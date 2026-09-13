( function () {
	'use strict';

	var BASE_PX_PER_MM = 3.15;
	var MIN_RESIZE_MM = 1;
	var VALID_MIN_ELEMENT_MM = 0.001;
	var SNAP_THRESHOLD_MM = 1.75;
	var DEFAULT_SAFE_MARGIN_MM = 10;
	var MAX_HISTORY = 100;
	var AUTOSAVE_DELAY_MS = 1800;
	var AUTOSAVE_RETRY_MS = 12000;
	var I18N = window.certloomTemplateDesignerI18n || {};
	function registrySnapshot( value ) { try { return JSON.parse( JSON.stringify( value || {} ) ); } catch ( error ) { return {}; } }
	function deepFreeze( value ) { if ( ! value || 'object' !== typeof value || Object.isFrozen( value ) ) { return value; } Object.freeze( value ); Object.keys( value ).forEach( function ( key ) { deepFreeze( value[ key ] ); } ); return value; }
	var ELEMENT_REGISTRY = deepFreeze( registrySnapshot( window.certloomTemplateElementRegistry ) );
	var FONT_CATALOG = deepFreeze( registrySnapshot( window.certloomTemplateFontCatalog ) );
	var SAMPLE_DATA = deepFreeze( registrySnapshot( window.certloomTemplateDesignerSamples ) );
	var PAGE_DIMENSIONS = {
		A4: { portrait: [ 210, 297 ], landscape: [ 297, 210 ] },
		Letter: { portrait: [ 215.9, 279.4 ], landscape: [ 279.4, 215.9 ] }
	};

	function clone( value ) { return JSON.parse( JSON.stringify( value ) ); }
	function stable( value ) { return JSON.stringify( value ); }
	function round( value, places ) {
		var factor = Math.pow( 10, places || 3 );
		var out = Math.round( Number( value ) * factor ) / factor;
		return 0 === out ? 0 : out;
	}
	function clamp( value, min, max ) { return Math.max( min, Math.min( max, value ) ); }
	function normalizedAngle( value ) {
		value = Number( value ) % 360;
		if ( value >= 180 ) { value -= 360; }
		if ( value < -180 ) { value += 360; }
		return round( value, 2 );
	}
	function px( mm, zoom ) { return Number( mm ) * BASE_PX_PER_MM * zoom; }
	function mmFromPx( pixels, zoom ) { return Number( pixels ) / ( BASE_PX_PER_MM * zoom ); }
	function escapeText( value ) { return String( null == value ? '' : value ); }
	function t( key, fallback ) { return 'string' === typeof I18N[ key ] && I18N[ key ] ? I18N[ key ] : fallback; }
	function fontDefinition( key ) { key = String( key || '' ).toLowerCase(); return FONT_CATALOG && FONT_CATALOG[ key ] && 'string' === typeof FONT_CATALOG[ key ].css ? FONT_CATALOG[ key ] : null; }
	function fontCss( key ) { var definition = fontDefinition( key ); return definition ? definition.css : ( fontDefinition( 'sans-serif' ) ? fontDefinition( 'sans-serif' ).css : 'sans-serif' ); }
	function fontKeyAllowed( key ) { return !! fontDefinition( key ); }

	function registryReady() { return 1 === Number( ELEMENT_REGISTRY.contract_version ) && ELEMENT_REGISTRY.types && 'object' === typeof ELEMENT_REGISTRY.types && Array.isArray( ELEMENT_REGISTRY.palette ); }
	function registryType( type ) { return registryReady() && ELEMENT_REGISTRY.types[ type ] && 'object' === typeof ELEMENT_REGISTRY.types[ type ] ? ELEMENT_REGISTRY.types[ type ] : null; }
	function paletteDefinition( kind ) {
		if ( ! registryReady() ) { return null; }
		for ( var i = 0; i < ELEMENT_REGISTRY.palette.length; i++ ) { if ( ELEMENT_REGISTRY.palette[ i ] && ELEMENT_REGISTRY.palette[ i ].kind === kind ) { return ELEMENT_REGISTRY.palette[ i ]; } }
		return null;
	}
	function typeLabel( type ) {
		var definition = registryType( type ); var key = definition && definition.label_key ? definition.label_key : 'element';
		return t( key, type ? escapeText( type ) : t( 'element', 'Element' ) );
	}
	function previewKind( element ) { var definition = element ? registryType( element.type ) : null; return definition && definition.preview ? definition.preview : ''; }

	function rotatedBounds( element ) {
		var x = Number( element.x_mm ), y = Number( element.y_mm );
		var width = Number( element.width_mm ), height = Number( element.height_mm );
		var angle = Number( element.rotation_deg || 0 ) * Math.PI / 180;
		var cx = x + width / 2, cy = y + height / 2;
		var cos = Math.cos( angle ), sin = Math.sin( angle );
		var points = [ [ -width / 2, -height / 2 ], [ width / 2, -height / 2 ], [ width / 2, height / 2 ], [ -width / 2, height / 2 ] ];
		var minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity;
		for ( var i = 0; i < points.length; i++ ) {
			var rx = cx + points[ i ][ 0 ] * cos - points[ i ][ 1 ] * sin;
			var ry = cy + points[ i ][ 0 ] * sin + points[ i ][ 1 ] * cos;
			minX = Math.min( minX, rx ); minY = Math.min( minY, ry ); maxX = Math.max( maxX, rx ); maxY = Math.max( maxY, ry );
		}
		return { minX: minX, minY: minY, maxX: maxX, maxY: maxY, centerX: ( minX + maxX ) / 2, centerY: ( minY + maxY ) / 2 };
	}

	function elementGeometryValid( element, page ) {
		if ( ! isFinite( element.x_mm ) || ! isFinite( element.y_mm ) || ! isFinite( element.width_mm ) || ! isFinite( element.height_mm ) || ! isFinite( element.rotation_deg || 0 ) ) { return false; }
		if ( Number( element.x_mm ) < 0 || Number( element.y_mm ) < 0 || Number( element.width_mm ) < VALID_MIN_ELEMENT_MM || Number( element.height_mm ) < VALID_MIN_ELEMENT_MM ) { return false; }
		if ( Number( element.x_mm ) + Number( element.width_mm ) > Number( page.width_mm ) + 0.001 || Number( element.y_mm ) + Number( element.height_mm ) > Number( page.height_mm ) + 0.001 ) { return false; }
		var bounds = rotatedBounds( element );
		return bounds.minX >= -0.001 && bounds.minY >= -0.001 && bounds.maxX <= Number( page.width_mm ) + 0.001 && bounds.maxY <= Number( page.height_mm ) + 0.001;
	}

	function makeSvgLine() {
		var ns = 'http://www.w3.org/2000/svg';
		var svg = document.createElementNS( ns, 'svg' );
		svg.setAttribute( 'viewBox', '0 0 100 100' ); svg.setAttribute( 'preserveAspectRatio', 'none' ); svg.setAttribute( 'aria-hidden', 'true' );
		var line = document.createElementNS( ns, 'line' );
		line.setAttribute( 'x1', '0' ); line.setAttribute( 'y1', '0' ); line.setAttribute( 'x2', '100' ); line.setAttribute( 'y2', '100' );
		svg.appendChild( line ); return svg;
	}

	function initDesigner( root ) {
		var form = root.querySelector( '[data-certloom-designer-form]' );
		var jsonField = root.querySelector( '[data-certloom-designer-json]' );
		var stateField = root.querySelector( '[data-certloom-designer-state-json]' );
		var pageEl = root.querySelector( '[data-certloom-designer-page]' );
		var workspace = root.querySelector( '[data-certloom-designer-workspace]' );
		var rulerX = null, rulerY = null;
		var sizeControl = root.querySelector( '[data-certloom-designer-page-size]' );
		var orientationControl = root.querySelector( '[data-certloom-designer-orientation]' );
		var customSizeWrap = root.querySelector( '[data-certloom-designer-custom-size]' );
		var customWidthControl = root.querySelector( '[data-certloom-designer-custom-width]' );
		var customHeightControl = root.querySelector( '[data-certloom-designer-custom-height]' );
		var zoomControl = root.querySelector( '[data-certloom-designer-zoom]' );
		var zoomOutput = root.querySelector( '[data-certloom-designer-zoom-output]' );
		var zoomInButton = root.querySelector( '[data-certloom-designer-zoom-in]' );
		var zoomOutButton = root.querySelector( '[data-certloom-designer-zoom-out]' );
		var testPrintButton = root.querySelector( '[data-certloom-designer-test-print]' );
		var studioImportOpen = root.querySelector( '[data-certloom-studio-import-open]' );
		var studioImportDialog = document.querySelector( '[data-certloom-studio-import-dialog]' );
		var studioImportClose = studioImportDialog ? studioImportDialog.querySelector( '[data-certloom-studio-import-close]' ) : null;
		var insertPanel = root.querySelector( '[data-certloom-designer-insert-panel]' );
		var paletteSearch = root.querySelector( '[data-certloom-designer-palette-search]' );
		var paletteItems = root.querySelectorAll( '[data-certloom-designer-palette-item]' );
		var paletteCategories = root.querySelectorAll( '[data-certloom-insert-category]' );
		var sampleDataControl = root.querySelector( '[data-certloom-designer-sample-data]' );
		var previewModeButtons = root.querySelectorAll( '[data-certloom-designer-preview-mode]' );
		var fitButton = root.querySelector( '[data-certloom-designer-fit]' );
		var trueSizeButton = root.querySelector( '[data-certloom-designer-true-size]' );
		var resetButton = root.querySelector( '[data-certloom-designer-reset]' );
		var undoButton = root.querySelector( '[data-certloom-designer-undo]' );
		var redoButton = root.querySelector( '[data-certloom-designer-redo]' );
		var snapControl = root.querySelector( '[data-certloom-designer-snap]' );
		var safeAreaButton = root.querySelector( '[data-certloom-designer-safe-area]' );
		var panToolButton = root.querySelector( '[data-certloom-designer-pan-tool]' );
		var alignButtons = root.querySelectorAll( '[data-certloom-designer-align]' );
		var layerActionButtons = root.querySelectorAll( '[data-certloom-designer-layer-action]' );
		var lockButton = root.querySelector( '[data-certloom-designer-lock]' );
		var layersContainer = root.querySelector( '[data-certloom-designer-layers]' );
		var dirtyBadge = root.querySelector( '[data-certloom-designer-dirty]' );
		var saveButtons = root.querySelectorAll( '[data-certloom-designer-quick-save], [data-certloom-designer-save]' );
		var pageReadout = root.querySelector( '[data-certloom-designer-page-readout]' );
		var emptyInspector = root.querySelector( '[data-certloom-designer-empty]' );
		var fieldsInspector = root.querySelector( '[data-certloom-designer-fields]' );
		var typeOutput = root.querySelector( '[data-certloom-designer-type]' );
		var idOutput = root.querySelector( '[data-certloom-designer-id]' );
		var advancedIdOutput = root.querySelector( '[data-certloom-designer-advanced-id]' );
		var lockStatus = root.querySelector( '[data-certloom-designer-lock-status]' );
		var liveRegion = root.querySelector( '[data-certloom-designer-live]' );
		var error = root.querySelector( '[data-certloom-designer-error]' );
		var fieldInputs = root.querySelectorAll( '[data-certloom-designer-field]' );
		var addButtons = root.querySelectorAll( '[data-certloom-designer-add]' );
		var removeButton = root.querySelector( '[data-certloom-designer-remove]' );
		var duplicateButton = root.querySelector( '[data-certloom-designer-duplicate]' );
		var modeButtons = root.querySelectorAll( '[data-certloom-designer-mode-button]' );
		var insertFocusButton = root.querySelector( '[data-certloom-designer-insert-focus]' );
		var layersToggle = root.querySelector( '[data-certloom-designer-layers-toggle]' );
		var quickTokenSelect = root.querySelector( '[data-certloom-designer-quick-token]' );
		var customTokenWrap = root.querySelector( '[data-certloom-designer-custom-token]' );
		var rawTokenInput = root.querySelector( '[data-certloom-designer-content-field="token"]' );
		var contentSections = root.querySelectorAll( '[data-certloom-designer-content]' );
		var contentFields = root.querySelectorAll( '[data-certloom-designer-content-field]' );
		var styleSections = root.querySelectorAll( '[data-certloom-designer-style]' );
		var styleFields = root.querySelectorAll( '[data-certloom-designer-style-field]' );
		var distributeButtons = root.querySelectorAll( '[data-certloom-designer-distribute]' );
		var quickArrangeBar = root.querySelector( '[data-certloom-designer-quick-align]' );
		var contextHint = root.querySelector( '[data-certloom-designer-context-hint]' );
		var contextGroups = root.querySelectorAll( '[data-certloom-designer-context-group]' );
		var contextEditTextButton = root.querySelector( '[data-certloom-designer-context-edit-text]' );
		var contextRichTextButton = root.querySelector( '[data-certloom-designer-context-rich-text]' );
		var richRunsContainer = root.querySelector( '[data-certloom-designer-rich-runs]' );
		var richAddButtons = root.querySelectorAll( '[data-certloom-designer-rich-add]' );
		var contextTokenWrap = root.querySelector( '[data-certloom-designer-context-token-wrap]' );
		var contextTokenSelect = root.querySelector( '[data-certloom-designer-context-token]' );
		var distributionGroup = root.querySelector( '[data-certloom-designer-distribute-group]' );
		var selectionStatus = root.querySelector( '[data-certloom-designer-selection-status]' );
		var spacingStatus = root.querySelector( '[data-certloom-designer-spacing-status]' );
		var propertySections = root.querySelectorAll( '[data-certloom-designer-property-section]' );
		var groupInspector = root.querySelector( '[data-certloom-designer-group]' );
		var groupCount = root.querySelector( '[data-certloom-designer-group-count]' );
		var quickTextBar = root.querySelector( '[data-certloom-designer-quick-text]' );
		var quickStyleFields = root.querySelectorAll( '[data-certloom-designer-quick-style]' );
		var quickToggleButtons = root.querySelectorAll( '[data-certloom-designer-quick-toggle]' );
		var quickTextAlignButtons = root.querySelectorAll( '[data-certloom-designer-quick-text-align]' );
		var colorPicker = root.querySelector( '[data-certloom-designer-color-picker]' );
		var colorTextField = root.querySelector( '[data-certloom-designer-style-field="color"]' );
		var mediaButtons = root.querySelectorAll( '[data-certloom-designer-media], [data-certloom-designer-context-media]' );
		var signatureSourceButtons = root.querySelectorAll( '[data-certloom-designer-signature-source]' );
		var signatureSourceStatus = root.querySelector( '[data-certloom-designer-signature-status]' );
		var tableColumnContainers = root.querySelectorAll( '[data-certloom-designer-table-columns]' );
		var tableRowContainers = root.querySelectorAll( '[data-certloom-designer-table-rows]' );
		var tableAddColumnButtons = root.querySelectorAll( '[data-certloom-designer-table-add-column]' );
		var tableAddRowButtons = root.querySelectorAll( '[data-certloom-designer-table-add-row]' );
		var tableSourceControls = root.querySelectorAll( '[data-certloom-designer-table-source]' );
		var tableMaxRowsControls = root.querySelectorAll( '[data-certloom-designer-table-max-rows]' );
		var tableHeaderControls = root.querySelectorAll( '[data-certloom-designer-table-header]' );
		var recoveryField = root.querySelector( '[data-certloom-designer-recovery-json]' );
		var recoveryStateField = root.querySelector( '[data-certloom-designer-recovery-state-json]' );
		var autosaveStatusEl = root.querySelector( '[data-certloom-designer-autosave-status]' );
		var autosaveDiscardButton = root.querySelector( '[data-certloom-designer-autosave-discard]' );
		var autosaveSessionField = root.querySelector( '[data-certloom-designer-autosave-session-field]' );
		var saveIntentField = root.querySelector( '[data-certloom-designer-save-intent-field]' );
		var studioWorkspaceShell = root.closest ? root.closest( '.certloom-template-admin.is-designer-workspace' ) : null;
		var conflictBanner = root.querySelector( '[data-certloom-designer-conflict-banner]' );
		var conflictReviewButton = conflictBanner ? conflictBanner.querySelector( '[data-certloom-designer-conflict-review]' ) : null;
		var conflictReloadButton = conflictBanner ? conflictBanner.querySelector( '[data-certloom-designer-conflict-reload]' ) : null;
		var studioNameDisplay = root.querySelector( '[data-certloom-studio-name-display]' );
		var studioNameText = root.querySelector( '[data-certloom-studio-name-text]' );
		var studioNameInput = root.querySelector( '[data-certloom-studio-name-input]' );
		var studioProfilePicker = root.querySelector( '[data-certloom-studio-profile-picker]' );
		var studioProfileInputs = root.querySelectorAll( '[data-certloom-studio-profile]' );
		var studioProfileSummary = root.querySelector( '[data-certloom-studio-profile-summary]' );
		var studioProfileStatus = root.querySelector( '[data-certloom-studio-profile-status]' );
		var studioToast = root.querySelector( '[data-certloom-studio-toast]' );
		var copyButton = root.querySelector( '[data-certloom-designer-copy]' );
		var cutButton = root.querySelector( '[data-certloom-designer-cut]' );
		var pasteButton = root.querySelector( '[data-certloom-designer-paste]' );
		if ( ! form || ! jsonField || ! pageEl || ! workspace ) { return; }
		if ( ! registryReady() ) { showError( t( 'elementRegistryUnavailable', 'The Designer could not load its editing controls. Reload the page before editing this design.' ) ); root.classList.add( 'has-registry-error' ); return; }

		var savedLayout;
		try { savedLayout = JSON.parse( jsonField.value ); } catch ( parseError ) { showError( t( 'currentLayoutLoadError', 'The current canonical layout could not be loaded into Designer.' ) ); return; }
		var savedState = { version: 2, locked_ids: [], hidden_ids: [], layer_labels: {}, snap_enabled: true };
		if ( stateField ) {
			try { savedState = normalizeState( JSON.parse( stateField.value ) ); } catch ( stateError ) { savedState = normalizeState( savedState ); }
		}
		var layout = clone( savedLayout );
		var designerState = clone( savedState );
		var autosaveStatus = root.getAttribute( 'data-autosave-status' ) || 'none';
		var recovered = false;
		if ( 'ready' === autosaveStatus && recoveryField && recoveryField.value ) {
			try {
				layout = JSON.parse( recoveryField.value );
				if ( recoveryStateField && recoveryStateField.value ) { designerState = JSON.parse( recoveryStateField.value ); }
				recovered = true;
			} catch ( recoveryError ) { layout = clone( savedLayout ); designerState = clone( savedState ); recovered = false; }
		}
		var schemaVersion = Number( layout && layout._meta ? layout._meta.schema_version : root.getAttribute( 'data-layout-schema-version' ) || 0 );
		var fitZoom = 1;
		var zoom = 1;
		var selectedId = '';
		var selectedIds = [];
		var pointer = null;
		var activeGuides = { x: null, y: null };
		var history = [];
		var future = [];
		var designerMode = 'quick';
		var directTextEditor = null;
		var directTextBefore = null;
		var draggedPaletteKind = '';
		var draggedLayerId = '';
		var lastElementPointer = { id: '', at: 0 };
		var designerClipboard = [];
		var designerClipboardLabels = {};
		var renamingLayerId = '';
		var safeAreaVisible = false;
		var panToolActive = false;
		var spacePanHeld = false;
		var panGesture = null;
		var marqueeGesture = null;
		var suppressCanvasClick = false;
		var activeSpacingGuides = [];
		var contextMenu = null;
		var activeSampleProfile = sampleDataControl && sampleDataControl.value ? String( sampleDataControl.value ) : ( SAMPLE_DATA.default || 'standard' );
		var activePreviewMode = 'sample';
		function syncPreviewModeButtons() {
			for ( var previewButtonIndex = 0; previewButtonIndex < previewModeButtons.length; previewButtonIndex++ ) {
				var previewMode = String( previewModeButtons[ previewButtonIndex ].getAttribute( 'data-certloom-designer-preview-mode' ) || 'sample' );
				var previewActive = previewMode === activePreviewMode;
				previewModeButtons[ previewButtonIndex ].classList.toggle( 'is-active', previewActive );
				previewModeButtons[ previewButtonIndex ].setAttribute( 'aria-pressed', previewActive ? 'true' : 'false' );
			}
		}
		function setPreviewMode( mode, speak ) {
			activePreviewMode = 'tokens' === mode ? 'tokens' : 'sample';
			syncPreviewModeButtons();
			render();
			if ( speak ) { announce( 'tokens' === activePreviewMode ? t( 'tokenPreviewEnabled', 'Token preview enabled. Template data was not changed.' ) : t( 'samplePreviewEnabled', 'Sample preview enabled. Template data was not changed.' ) ); }
		}

		function rememberedDesignerMode() {
			var fallback = root.getAttribute( 'data-designer-default-mode' ) || 'pro';
			return 'pro' === fallback ? 'pro' : 'quick';
		}
		function applyDesignerMode( mode, speak ) {
			designerMode = 'pro' === mode ? 'pro' : 'quick';
			root.classList.toggle( 'is-quick-mode', 'quick' === designerMode );
			root.classList.toggle( 'is-pro-mode', 'pro' === designerMode );
			root.setAttribute( 'data-designer-mode', designerMode );
			if ( 'pro' === designerMode ) { root.classList.remove( 'show-quick-layers' ); }
			for ( var i = 0; i < modeButtons.length; i++ ) { var active = modeButtons[ i ].getAttribute( 'data-certloom-designer-mode-button' ) === designerMode; modeButtons[ i ].setAttribute( 'aria-pressed', active ? 'true' : 'false' ); modeButtons[ i ].classList.toggle( 'button-primary', active ); }
			if ( layersToggle ) { layersToggle.setAttribute( 'aria-expanded', root.classList.contains( 'show-quick-layers' ) ? 'true' : 'false' ); }
			syncQuickToken();
			if ( speak ) { announce( 'quick' === designerMode ? t( 'quickDesignerEnabled', 'Quick Designer enabled.' ) : t( 'proDesignerEnabled', 'Pro Designer enabled.' ) ); }
			window.setTimeout( function () { fitWorkspace(); }, 0 );
		}
		var isSubmitting = false;
		var autosaveTimer = null;
		var autosavePending = false;
		var autosaveQueued = false;
		var autosaveNeedsCleanup = 'stale' === autosaveStatus || 'invalid' === autosaveStatus || 'cleanup_failed' === autosaveStatus;
		var autosaveConflict = false;
		var autosaveRecoverySessionBlocked = false;
		var hasServerAutosave = 'ready' === autosaveStatus || autosaveNeedsCleanup;
		var templateId = Number( root.getAttribute( 'data-template-id' ) || 0 );
		var baseRevisionId = Number( root.getAttribute( 'data-saved-revision' ) || 0 );
		var baseRevisionSha256 = root.getAttribute( 'data-saved-sha256' ) || '';
		var autosaveNonce = root.getAttribute( 'data-autosave-nonce' ) || '';
		var autosaveSessionId = root.getAttribute( 'data-autosave-session' ) || secureUuidV4();
		var ajaxUrl = root.getAttribute( 'data-ajax-url' ) || '';
		var studioMetadataNonce = root.getAttribute( 'data-studio-metadata-nonce' ) || '';
		var studioUpdatedAt = root.getAttribute( 'data-template-updated-at' ) || '';
		var studioNameSaving = false;
		var studioProfileSaving = false;
		var studioProfilePending = false;
		var studioProfileTimer = null;
		var studioSavedProfiles = [];
		for ( var studioProfileInitialIndex = 0; studioProfileInitialIndex < studioProfileInputs.length; studioProfileInitialIndex++ ) { if ( studioProfileInputs[ studioProfileInitialIndex ].checked ) { studioSavedProfiles.push( String( studioProfileInputs[ studioProfileInitialIndex ].value || '' ) ); } }
		var lastAutosaveFingerprint = recovered ? stable( { layout: layout, state: designerState } ) : '';
		if ( autosaveSessionField ) { autosaveSessionField.value = autosaveSessionId; }

		function normalizeState( input ) {
			var result = { version: 2, locked_ids: [], hidden_ids: [], layer_labels: {}, snap_enabled: true };
			var ids = {};
			var stateLayout = layout && Array.isArray( layout.elements ) ? layout : savedLayout;
			var list = stateLayout && Array.isArray( stateLayout.elements ) ? stateLayout.elements : [];
			for ( var i = 0; i < list.length; i++ ) { ids[ list[ i ].id ] = true; }
			if ( input && false === input.snap_enabled ) { result.snap_enabled = false; }
			if ( input && Array.isArray( input.locked_ids ) ) {
				for ( var j = 0; j < input.locked_ids.length; j++ ) { if ( ids[ input.locked_ids[ j ] ] && result.locked_ids.indexOf( input.locked_ids[ j ] ) === -1 ) { result.locked_ids.push( input.locked_ids[ j ] ); } }
			}
			if ( input && Array.isArray( input.hidden_ids ) ) {
				for ( var hiddenIndex = 0; hiddenIndex < input.hidden_ids.length; hiddenIndex++ ) { if ( ids[ input.hidden_ids[ hiddenIndex ] ] && result.hidden_ids.indexOf( input.hidden_ids[ hiddenIndex ] ) === -1 ) { result.hidden_ids.push( input.hidden_ids[ hiddenIndex ] ); } }
			}
			if ( input && input.layer_labels && 'object' === typeof input.layer_labels && ! Array.isArray( input.layer_labels ) ) {
				Object.keys( input.layer_labels ).forEach( function ( id ) {
					if ( ! ids[ id ] ) { return; }
					var label = String( input.layer_labels[ id ] || '' ).replace( /[\u0000-\u001F\u007F]/g, '' ).trim();
					if ( label ) { result.layer_labels[ id ] = label.slice( 0, 80 ); }
				} );
			}
			result.locked_ids.sort(); result.hidden_ids.sort();
			return result;
		}

		if ( recovered ) { designerState = normalizeState( designerState ); lastAutosaveFingerprint = stable( { layout: layout, state: designerState } ); }

		function setAutosaveStatus( message, state ) {
			var statusMessage = 'conflict' === state ? '' : ( message || '' );
			if ( autosaveStatusEl ) { autosaveStatusEl.textContent = statusMessage; autosaveStatusEl.setAttribute( 'data-state', state || '' ); }
			if ( conflictBanner ) { conflictBanner.hidden = 'conflict' !== state; }
			autosaveConflict = 'conflict' === state;
			root.classList.toggle( 'has-autosave-conflict', autosaveConflict );
			if ( dirtyBadge && autosaveConflict ) { dirtyBadge.textContent = t( 'autosaveConflictShort', 'Save conflict' ); dirtyBadge.classList.add( 'is-dirty' ); }
		}
		function autosaveFingerprint() { return stable( { layout: layout, state: designerState } ); }
		function autosaveRequest( action, body, keepalive ) {
			if ( ! ajaxUrl || ! autosaveNonce || ! templateId || ! autosaveSessionId ) { return Promise.reject( new Error( 'autosave-unavailable' ) ); }
			var data = new FormData(); data.append( 'action', action ); data.append( 'nonce', autosaveNonce ); data.append( 'template_id', String( templateId ) );
			if ( body ) { for ( var key in body ) { if ( Object.prototype.hasOwnProperty.call( body, key ) ) { data.append( key, body[ key ] ); } } }
			return window.fetch( ajaxUrl, { method: 'POST', credentials: 'same-origin', body: data, keepalive: !! keepalive } ).then( function ( response ) {
				return response.json().catch( function () { return { success: false, data: { code: 'invalid-json' } }; } ).then( function ( payload ) { payload.httpStatus = response.status; return payload; } );
			} );
		}
		function studioMetadataRequest( key, body ) {
			if ( ! ajaxUrl || ! studioMetadataNonce || ! templateId ) { return Promise.reject( new Error( 'studio-metadata-unavailable' ) ); }
			var data = new FormData(); data.append( 'action', 'certloom_template_studio_metadata' ); data.append( 'nonce', studioMetadataNonce ); data.append( 'template_id', String( templateId ) ); data.append( 'metadata_key', key );
			if ( body ) { for ( var field in body ) { if ( ! Object.prototype.hasOwnProperty.call( body, field ) ) { continue; } var value = body[ field ]; if ( Array.isArray( value ) ) { for ( var i = 0; i < value.length; i++ ) { data.append( field + '[]', value[ i ] ); } } else { data.append( field, value ); } } }
			return window.fetch( ajaxUrl, { method: 'POST', credentials: 'same-origin', body: data } ).then( function ( response ) {
				return response.json().catch( function () { return { success: false, data: { code: 'invalid-json', message: t( 'studioMetadataInvalidResponse', 'Certloom could not read the metadata save response.' ) } }; } ).then( function ( payload ) { payload.httpStatus = response.status; return payload; } );
			} );
		}
		function showStudioToast( message, state ) {
			if ( ! studioToast ) { announce( message ); return; }
			studioToast.textContent = message || ''; studioToast.hidden = ! message; studioToast.classList.toggle( 'is-error', 'error' === state ); studioToast.classList.toggle( 'is-success', 'error' !== state );
			if ( message ) { window.clearTimeout( showStudioToast.timer ); showStudioToast.timer = window.setTimeout( function () { if ( studioToast ) { studioToast.hidden = true; } }, 3200 ); }
			announce( message );
		}
		function studioProfileValues() {
			var values = []; for ( var i = 0; i < studioProfileInputs.length; i++ ) { if ( studioProfileInputs[ i ].checked ) { values.push( String( studioProfileInputs[ i ].value || '' ) ); } } return values;
		}
		function studioProfileSummaryText() {
			var labels = []; for ( var i = 0; i < studioProfileInputs.length; i++ ) { if ( ! studioProfileInputs[ i ].checked ) { continue; } var label = studioProfileInputs[ i ].closest( 'label' ); var span = label ? label.querySelector( 'span' ) : null; labels.push( span ? String( span.textContent || '' ).trim() : String( studioProfileInputs[ i ].value || '' ) ); }
			if ( ! labels.length ) { return t( 'studioProfiles', 'Profiles' ); }
			return labels.length > 1 ? labels[ 0 ] + ' +' + String( labels.length - 1 ) : labels[ 0 ];
		}
		function syncStudioProfileSummary() { if ( studioProfileSummary ) { studioProfileSummary.textContent = studioProfileSummaryText(); } }
		function restoreStudioProfileSelection() { for ( var i = 0; i < studioProfileInputs.length; i++ ) { studioProfileInputs[ i ].checked = studioSavedProfiles.indexOf( String( studioProfileInputs[ i ].value || '' ) ) !== -1; } syncStudioProfileSummary(); }
		function saveStudioProfiles() {
			if ( studioProfileSaving ) { studioProfilePending = true; return; }
			studioProfileSaving = true; studioProfilePending = false; var values = studioProfileValues(); if ( studioProfileStatus ) { studioProfileStatus.textContent = t( 'studioProfilesSaving', 'Saving…' ); }
			studioMetadataRequest( 'recommended_profiles', { recommended_profiles: values } ).then( function ( payload ) {
				if ( payload && payload.success ) { studioSavedProfiles = payload.data && Array.isArray( payload.data.recommended_profiles ) ? payload.data.recommended_profiles.slice() : values.slice(); if ( payload.data && payload.data.updated_at ) { studioUpdatedAt = String( payload.data.updated_at ); root.setAttribute( 'data-template-updated-at', studioUpdatedAt ); } if ( studioProfileStatus ) { studioProfileStatus.textContent = t( 'studioProfilesSaved', 'Saved' ); } syncStudioProfileSummary(); }
				else { restoreStudioProfileSelection(); if ( studioProfileStatus ) { studioProfileStatus.textContent = t( 'studioProfilesSaveFailed', 'Could not save profiles' ); } showStudioToast( payload && payload.data && payload.data.message ? payload.data.message : t( 'studioProfilesSaveFailed', 'Could not save recommended profiles.' ), 'error' ); }
			} ).catch( function () { restoreStudioProfileSelection(); if ( studioProfileStatus ) { studioProfileStatus.textContent = t( 'studioProfilesSaveFailed', 'Could not save profiles' ); } showStudioToast( t( 'studioProfilesSaveFailed', 'Could not save recommended profiles.' ), 'error' ); } ).then( function () { studioProfileSaving = false; if ( studioProfilePending ) { studioProfilePending = false; saveStudioProfiles(); } } );
		}
		function beginStudioRename() {
			if ( ! studioNameDisplay || ! studioNameInput || studioNameSaving ) { return; }
			studioNameInput.value = studioNameText ? String( studioNameText.textContent || '' ).trim() : String( studioNameInput.value || '' ); studioNameDisplay.hidden = true; studioNameInput.hidden = false; studioNameInput.focus(); studioNameInput.select();
		}
		function finishStudioRename( commit ) {
			if ( ! studioNameDisplay || ! studioNameInput || studioNameInput.hidden ) { return; }
			var original = studioNameText ? String( studioNameText.textContent || '' ).trim() : ''; var value = String( studioNameInput.value || '' ).trim();
			studioNameInput.hidden = true; studioNameDisplay.hidden = false;
			if ( ! commit ) { studioNameInput.value = original; return; }
			if ( ! value ) { studioNameInput.value = original; showStudioToast( t( 'studioNameRequired', 'Enter a certificate design name.' ), 'error' ); return; }
			if ( value === original ) { studioNameInput.value = original; return; }
			studioNameSaving = true; studioNameDisplay.disabled = true;
			studioMetadataRequest( 'name', { name: value, expected_updated_at: studioUpdatedAt } ).then( function ( payload ) {
				if ( payload && payload.success ) { if ( studioNameText ) { studioNameText.textContent = String( payload.data && payload.data.name ? payload.data.name : value ); } studioNameInput.value = studioNameText ? studioNameText.textContent : value; if ( payload.data && payload.data.updated_at ) { studioUpdatedAt = String( payload.data.updated_at ); root.setAttribute( 'data-template-updated-at', studioUpdatedAt ); } showStudioToast( t( 'studioNameSaved', 'Design name updated.' ), 'success' ); }
				else { studioNameInput.value = original; if ( 409 === ( payload && payload.httpStatus ) && conflictBanner ) { conflictBanner.hidden = false; root.classList.add( 'has-autosave-conflict' ); } showStudioToast( payload && payload.data && payload.data.message ? payload.data.message : t( 'studioNameSaveFailed', 'Could not rename this design.' ), 'error' ); }
			} ).catch( function () { studioNameInput.value = original; showStudioToast( t( 'studioNameSaveFailed', 'Could not rename this design.' ), 'error' ); } ).then( function () { studioNameSaving = false; if ( studioNameDisplay ) { studioNameDisplay.disabled = false; } } );
		}
		function discardAutosave( announceResult ) {
			if ( autosaveTimer ) { window.clearTimeout( autosaveTimer ); autosaveTimer = null; }
			if ( ! hasServerAutosave && ! autosaveConflict ) { return; }
			var cleanupBody = autosaveNeedsCleanup ? null : ( autosaveSessionId ? { session_id: autosaveSessionId } : null );
			autosaveRequest( 'certloom_template_designer_autosave_discard', cleanupBody, false ).then( function ( payload ) {
				if ( payload && payload.success ) {
					hasServerAutosave = false; autosaveNeedsCleanup = false; autosaveConflict = false; lastAutosaveFingerprint = ''; setAutosaveStatus( t( 'savedDraft', 'Saved draft' ), 'clean' ); if ( autosaveDiscardButton ) { autosaveDiscardButton.disabled = true; } if ( announceResult ) { announce( t( 'autosaveDiscarded', 'Recovery checkpoint discarded.' ) ); }
					return;
				}
				var code = payload && payload.data ? String( payload.data.code || '' ) : '';
				if ( 'certloom_designer_autosave_conflict' === code ) {
					setAutosaveStatus( t( 'autosaveConflict', 'Autosave stopped because this Template changed in another session. Your browser changes were not overwritten; reload only after preserving anything you still need.' ), 'conflict' );
					if ( announceResult ) { showError( t( 'autosaveConflict', 'Autosave stopped because this Template changed in another session. Your browser changes were not overwritten; reload only after preserving anything you still need.' ) ); }
				} else if ( 'certloom_designer_autosave_session_conflict' === code ) {
					autosaveRecoverySessionBlocked = true;
					setAutosaveStatus( t( 'autosaveSessionPaused', 'Recovery autosave is paused because another Studio session owns the current recovery checkpoint. Draft/Save is still available.' ), 'session' );
					if ( announceResult ) { showError( t( 'autosaveSessionPaused', 'Recovery autosave is paused because another Studio session owns the current recovery checkpoint. Draft/Save is still available.' ) ); }
				} else {
					setAutosaveStatus( t( 'autosaveCleanupFailed', 'Recovery cleanup is temporarily unavailable. Manual save remains available.' ), 'error' );
					if ( announceResult ) { showError( t( 'autosaveCleanupFailed', 'Recovery cleanup is temporarily unavailable. Manual save remains available.' ) ); }
				}
			} ).catch( function () { setAutosaveStatus( t( 'autosaveCleanupFailed', 'Recovery cleanup is temporarily unavailable. Manual save remains available.' ), 'error' ); if ( announceResult ) { showError( t( 'autosaveCleanupFailed', 'Recovery cleanup is temporarily unavailable. Manual save remains available.' ) ); } } );
		}
		function runAutosave() {
			if ( autosaveConflict || autosaveRecoverySessionBlocked || isSubmitting || ! root.classList.contains( 'has-unsaved-changes' ) ) { return; }
			var fingerprint = autosaveFingerprint();
			if ( fingerprint === lastAutosaveFingerprint ) { return; }
			if ( autosavePending ) { autosaveQueued = true; return; }
			autosavePending = true; setAutosaveStatus( t( 'autosaving', 'Saving recovery checkpoint…' ), 'saving' );
			autosaveRequest( 'certloom_template_designer_autosave', {
				expected_revision_id: String( baseRevisionId ), expected_revision_sha256: baseRevisionSha256, session_id: autosaveSessionId,
				layout_json: JSON.stringify( layout ), designer_state_json: JSON.stringify( designerState )
			}, false ).then( function ( payload ) {
				if ( payload && payload.success ) {
					lastAutosaveFingerprint = fingerprint; hasServerAutosave = true; setAutosaveStatus( t( 'autosaved', 'Recovery checkpoint saved' ), 'saved' );
				} else {
					var code = payload && payload.data ? payload.data.code : '';
					if ( 'certloom_designer_autosave_conflict' === code ) { autosaveConflict = true; setAutosaveStatus( t( 'autosaveConflict', 'Autosave stopped because this Template changed in another session. Your browser changes were not overwritten; reload only after preserving anything you still need.' ), 'conflict' ); }
					else if ( 'certloom_designer_autosave_session_conflict' === code ) { autosaveRecoverySessionBlocked = true; setAutosaveStatus( t( 'autosaveSessionPaused', 'Recovery autosave is paused because another Studio session owns the current recovery checkpoint. Draft/Save is still available.' ), 'session' ); }
					else { setAutosaveStatus( t( 'autosaveFailed', 'Recovery autosave is temporarily unavailable. Manual save remains available.' ), 'error' ); }
				}
			} ).catch( function () { setAutosaveStatus( t( 'autosaveFailed', 'Recovery autosave is temporarily unavailable. Manual save remains available.' ), 'error' ); window.setTimeout( function () { scheduleAutosave(); }, AUTOSAVE_RETRY_MS ); } ).then( function () {
				autosavePending = false; if ( autosaveQueued ) { autosaveQueued = false; scheduleAutosave(); }
			} );
		}
		function scheduleAutosave() {
			if ( autosaveConflict || autosaveRecoverySessionBlocked || isSubmitting ) { return; }
			if ( autosaveTimer ) { window.clearTimeout( autosaveTimer ); }
			autosaveTimer = window.setTimeout( function () { autosaveTimer = null; runAutosave(); }, AUTOSAVE_DELAY_MS );
		}
		function announce( message ) { if ( liveRegion ) { liveRegion.textContent = ''; window.setTimeout( function () { liveRegion.textContent = message || ''; }, 0 ); } }
		function showError( message ) {
			if ( ! error ) { return; }
			error.hidden = ! message;
			var p = error.querySelector( 'p' ); if ( p ) { p.textContent = message || ''; }
		}
		function setSaveFeedback( label, state, disabled ) {
			if ( dirtyBadge ) { dirtyBadge.textContent = label; dirtyBadge.classList.toggle( 'is-dirty', 'blocked' === state ); dirtyBadge.classList.toggle( 'is-saving', 'saving' === state ); }
			for ( var i = 0; i < saveButtons.length; i++ ) { saveButtons[ i ].disabled = !!disabled; if ( disabled ) { saveButtons[ i ].setAttribute( 'aria-busy', 'true' ); } else { saveButtons[ i ].removeAttribute( 'aria-busy' ); } }
		}
		function setSaveIntent( intent ) {
			intent = 'publish' === String( intent || '' ) ? 'publish' : 'draft';
			if ( saveIntentField ) { saveIntentField.value = intent; }
			return intent;
		}
		function saveIntentFromSubmitter( submitter ) {
			if ( ! submitter ) { return saveIntentField ? String( saveIntentField.value || 'draft' ) : 'draft'; }
			var intent = submitter.getAttribute ? submitter.getAttribute( 'data-certloom-designer-save-intent' ) : '';
			if ( ! intent && submitter.name === 'studio_intent' ) { intent = submitter.value; }
			return setSaveIntent( intent );
		}
		function syncStudioViewportHeight() {
			if ( ! studioWorkspaceShell ) { return; }
			if ( window.matchMedia && ! window.matchMedia( '(min-width: 901px)' ).matches ) { studioWorkspaceShell.style.removeProperty( '--certloom-studio-viewport-height' ); return; }
			var rect = studioWorkspaceShell.getBoundingClientRect();
			var viewportHeight = window.visualViewport && isFinite( window.visualViewport.height ) ? Number( window.visualViewport.height ) : Number( window.innerHeight || document.documentElement.clientHeight || 0 );
			var viewportTop = window.visualViewport && isFinite( window.visualViewport.offsetTop ) ? Number( window.visualViewport.offsetTop ) : 0;
			var available = Math.floor( viewportTop + viewportHeight - rect.top );
			if ( isFinite( available ) && available > 320 ) { studioWorkspaceShell.style.setProperty( '--certloom-studio-viewport-height', available + 'px' ); }
		}
		function blockSave( message ) {
			showError( message ); setSaveFeedback( t( 'saveBlocked', 'Save blocked' ), 'blocked', false ); announce( message );
			if ( error && error.scrollIntoView ) { error.scrollIntoView( { behavior: 'smooth', block: 'nearest' } ); }
		}
		function page() { return layout && layout.page ? layout.page : null; }
		function elements() { return layout && Array.isArray( layout.elements ) ? layout.elements : []; }
		function safeMarginMm() {
			var p = page(); if ( ! p ) { return DEFAULT_SAFE_MARGIN_MM; }
			var configured = Number( root.getAttribute( 'data-designer-safe-margin-mm' ) || DEFAULT_SAFE_MARGIN_MM );
			var max = Math.max( 0, Math.min( Number( p.width_mm ), Number( p.height_mm ) ) / 2 - 0.001 );
			return round( clamp( isFinite( configured ) ? configured : DEFAULT_SAFE_MARGIN_MM, 0, max ), 3 );
		}
		function selected() {
			var list = elements();
			for ( var i = 0; i < list.length; i++ ) { if ( list[ i ].id === selectedId ) { return list[ i ]; } }
			return null;
		}
		function selectionIds() {
			var valid = {}; var list = elements(); var result = [];
			for ( var i = 0; i < list.length; i++ ) { valid[ list[ i ].id ] = true; }
			for ( var j = 0; j < selectedIds.length; j++ ) { if ( valid[ selectedIds[ j ] ] && result.indexOf( selectedIds[ j ] ) === -1 ) { result.push( selectedIds[ j ] ); } }
			if ( selectedId && valid[ selectedId ] && result.indexOf( selectedId ) === -1 ) { result.push( selectedId ); }
			selectedIds = result;
			if ( selectedId && ! valid[ selectedId ] ) { selectedId = result.length ? result[ result.length - 1 ] : ''; }
			return result.slice();
		}
		function selectedElements() {
			var ids = selectionIds(); var list = elements(); var result = [];
			for ( var i = 0; i < list.length; i++ ) { if ( ids.indexOf( list[ i ].id ) !== -1 ) { result.push( list[ i ] ); } }
			return result;
		}
		function isSelectedId( id ) { return selectionIds().indexOf( id ) !== -1; }
		function selectOnly( id ) { selectedId = id || ''; selectedIds = selectedId ? [ selectedId ] : []; }
		function toggleSelection( id ) {
			if ( ! id ) { return; }
			var ids = selectionIds(); var at = ids.indexOf( id );
			if ( at === -1 ) { ids.push( id ); selectedId = id; }
			else if ( ids.length > 1 ) { ids.splice( at, 1 ); if ( selectedId === id ) { selectedId = ids[ ids.length - 1 ] || ''; } }
			else { ids = []; selectedId = ''; }
			selectedIds = ids;
		}
		function selectionHasLocked() { var list = selectedElements(); for ( var i = 0; i < list.length; i++ ) { if ( isLocked( list[ i ] ) ) { return true; } } return false; }
		function isLockedId( id ) { return designerState.locked_ids.indexOf( id ) !== -1; }
		function isLocked( element ) { return !! element && isLockedId( element.id ); }
		function isHiddenId( id ) { return designerState.hidden_ids.indexOf( id ) !== -1; }
		function layerLabel( id ) { return designerState.layer_labels && designerState.layer_labels[ id ] ? designerState.layer_labels[ id ] : ''; }
		function layerDisplayName( element ) { return layerLabel( element.id ) || elementTitle( element ) || typeLabel( element.type ); }
		function compactLayerDisplayName( element ) { var full = String( layerDisplayName( element ) || '' ); var max = 26; return full.length > max ? full.slice( 0, max ).replace( /\s+$/, '' ) + '....' : full; }
		function normalizeLayerLabel( value ) { return String( value || '' ).replace( /[\u0000-\u001F\u007F]/g, '' ).trim().slice( 0, 80 ); }
		function snapshot() { return { layout: clone( layout ), state: clone( designerState ), selectedId: selectedId, selectedIds: selectionIds() }; }
		function snapshotChanged( before ) { return stable( before.layout ) !== stable( layout ) || stable( before.state ) !== stable( designerState ); }
		function pushHistory( before ) {
			if ( ! before || ! snapshotChanged( before ) ) { return; }
			history.push( before );
			if ( history.length > MAX_HISTORY ) { history.shift(); }
			future = [];
		}
		function restoreSnapshot( value ) {
			layout = clone( value.layout ); designerState = normalizeState( value.state ); selectedId = value.selectedId || ''; selectedIds = Array.isArray( value.selectedIds ) ? value.selectedIds.slice() : ( selectedId ? [ selectedId ] : [] ); selectionIds();
			activeGuides = { x: null, y: null }; activeSpacingGuides = []; syncDirty(); render();
		}
		function undo() {
			if ( ! history.length ) { return; }
			future.push( snapshot() ); restoreSnapshot( history.pop() ); announce( t( 'designerChangeUndone', 'Designer change undone.' ) );
		}
		function redo() {
			if ( ! future.length ) { return; }
			history.push( snapshot() ); restoreSnapshot( future.pop() ); announce( t( 'designerChangeRedone', 'Designer change redone.' ) );
		}
		function syncHistoryButtons() {
			if ( undoButton ) { undoButton.disabled = ! history.length; }
			if ( redoButton ) { redoButton.disabled = ! future.length; }
		}
		function cleanSaveLabel() {
			var publicationState = String( root.getAttribute( 'data-publication-state' ) || 'draft' );
			if ( 'published' === publicationState ) { return t( 'savedPublished', 'Published' ); }
			if ( 'changes_pending' === publicationState ) { return t( 'savedPendingChanges', 'Draft changes saved' ); }
			return t( 'savedDraft', 'Saved draft' );
		}
		function syncDirty() {
			var dirty = stable( savedLayout ) !== stable( layout ) || stable( savedState ) !== stable( designerState );
			if ( dirtyBadge ) {
				dirtyBadge.textContent = autosaveConflict ? t( 'autosaveConflictShort', 'Save conflict' ) : ( dirty ? t( 'unsavedChanges', 'Unsaved changes' ) : cleanSaveLabel() );
				dirtyBadge.classList.toggle( 'is-dirty', dirty || autosaveConflict );
			}
			root.classList.toggle( 'has-unsaved-changes', dirty );
			if ( dirty ) { scheduleAutosave(); } else if ( hasServerAutosave && ! autosavePending ) { discardAutosave( false ); }
		}
		function syncCanvasTools() {
			if ( safeAreaButton ) { safeAreaButton.setAttribute( 'aria-pressed', safeAreaVisible ? 'true' : 'false' ); safeAreaButton.classList.toggle( 'button-primary', safeAreaVisible ); }
			if ( panToolButton ) { panToolButton.setAttribute( 'aria-pressed', panToolActive ? 'true' : 'false' ); panToolButton.classList.toggle( 'button-primary', panToolActive ); }
			root.classList.toggle( 'is-pan-tool-active', panToolActive ); workspace.classList.toggle( 'is-pan-ready', panToolActive || spacePanHeld );
		}
		function relativeZoomPercent() { return Math.round( ( zoom / Math.max( 0.0001, fitZoom ) ) * 100 ); }
		var inspectorSectionFor = {};
		function enhancePropertySections() {
			for ( var i = 0; i < propertySections.length; i++ ) {
				( function ( section ) {
					if ( section.classList.contains( 'certloom-studio-accordion' ) ) { return; }
					var heading = section.querySelector( 'h4' ); var title = heading ? String( heading.textContent || '' ).trim() : t( 'properties', 'Properties' ); if ( heading ) { heading.remove(); }
					var body = document.createElement( 'div' ); body.className = 'certloom-studio-accordion-body';
					while ( section.firstChild ) { body.appendChild( section.firstChild ); }
					var trigger = document.createElement( 'button' ); trigger.type = 'button'; trigger.className = 'certloom-studio-accordion-trigger'; trigger.setAttribute( 'aria-expanded', 'false' ); trigger.innerHTML = '<span>' + escapeText( title ) + '</span><span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>';
					section.classList.add( 'certloom-studio-accordion' ); section.appendChild( trigger ); section.appendChild( body ); body.hidden = true;
					trigger.addEventListener( 'click', function () {
						/* A selected element always keeps one Inspector pane open. Clicking the
						 * active pane therefore leaves it open; choosing another pane closes it. */
						if ( ! body.hidden ) { return; }
						var key = String( section.getAttribute( 'data-certloom-designer-property-section' ) || '' );
						openInspectorSection( key );
					} );
				} )( propertySections[ i ] );
			}
		}
		function openInspectorSection( key ) {
			closeInspectorSections();
			for ( var i = 0; i < propertySections.length; i++ ) { var section = propertySections[ i ]; if ( section.getAttribute( 'data-certloom-designer-property-section' ) !== key ) { continue; } var body = section.querySelector( '.certloom-studio-accordion-body' ); var trigger = section.querySelector( '.certloom-studio-accordion-trigger' ); if ( body ) { body.hidden = false; } section.classList.add( 'is-open' ); if ( trigger ) { trigger.setAttribute( 'aria-expanded', 'true' ); } if ( selectedId ) { inspectorSectionFor[ selectedId ] = key; } break; }
		}
		function closeInspectorSections() {
			for ( var i = 0; i < propertySections.length; i++ ) { var section = propertySections[ i ]; var body = section.querySelector( '.certloom-studio-accordion-body' ); var trigger = section.querySelector( '.certloom-studio-accordion-trigger' ); if ( body ) { body.hidden = true; } section.classList.remove( 'is-open' ); if ( trigger ) { trigger.setAttribute( 'aria-expanded', 'false' ); } }
		}
		function orderInspectorSections( element ) {
			if ( ! element ) { return; }
			var type = String( element.type || '' ); var order;
			if ( [ 'shape', 'line' ].indexOf( type ) !== -1 ) { order = [ 'appearance', 'geometry', 'transform', 'arrange', 'layer', 'content', 'advanced' ]; }
			else if ( [ 'qr', 'barcode', 'merge', 'dynamic_field', 'field' ].indexOf( type ) !== -1 ) { order = [ 'content', 'appearance', 'geometry', 'transform', 'arrange', 'layer', 'advanced' ]; }
			else if ( [ 'image', 'logo', 'seal', 'signature' ].indexOf( type ) !== -1 ) { order = [ 'content', 'appearance', 'geometry', 'transform', 'arrange', 'layer', 'advanced' ]; }
			else { order = [ 'content', 'appearance', 'geometry', 'transform', 'arrange', 'layer', 'advanced' ]; }
			for ( var i = 0; i < propertySections.length; i++ ) { var key = String( propertySections[ i ].getAttribute( 'data-certloom-designer-property-section' ) || '' ); var at = order.indexOf( key ); propertySections[ i ].style.order = String( at === -1 ? 90 : at + 1 ); }
		}
		function openPreferredInspectorSection( element ) {
			if ( ! element ) { return; }
			orderInspectorSections( element );
			var type = String( element.type || '' );
			var preferred = [ 'shape', 'line' ].indexOf( type ) !== -1 ? 'appearance' : ( [ 'text', 'merge', 'rich_text', 'list', 'image', 'logo', 'seal', 'signature', 'qr', 'barcode', 'table', 'data_table', 'dynamic_field', 'field' ].indexOf( type ) !== -1 ? 'content' : 'geometry' );
			var key = inspectorSectionFor[ element.id ] || preferred;
			var current = null;
			for ( var i = 0; i < propertySections.length; i++ ) { if ( propertySections[ i ].classList.contains( 'is-open' ) ) { current = String( propertySections[ i ].getAttribute( 'data-certloom-designer-property-section' ) || '' ); break; } }
			if ( current !== key ) { openInspectorSection( key ); }
		}
		function closeInsertPanel() {
			if ( ! insertPanel || insertPanel.hidden ) { return; }
			insertPanel.hidden = true;
			root.classList.remove( 'is-insert-open' );
			if ( insertFocusButton ) { insertFocusButton.setAttribute( 'aria-expanded', 'false' ); }
		}
		function ensureStudioRulers() {
			if ( ! root.classList.contains( 'certloom-template-designer-v2' ) ) { return false; }
			if ( ! rulerX ) { rulerX = document.createElement( 'div' ); rulerX.className = 'certloom-studio-ruler certloom-studio-ruler-x'; rulerX.setAttribute( 'data-certloom-designer-ruler', 'x' ); rulerX.setAttribute( 'aria-hidden', 'true' ); workspace.appendChild( rulerX ); }
			if ( ! rulerY ) { rulerY = document.createElement( 'div' ); rulerY.className = 'certloom-studio-ruler certloom-studio-ruler-y'; rulerY.setAttribute( 'data-certloom-designer-ruler', 'y' ); rulerY.setAttribute( 'aria-hidden', 'true' ); workspace.appendChild( rulerY ); }
			return true;
		}
		function rulerStepMm() { var ten = px( 10, zoom ); return ten >= 24 ? 10 : ( ten >= 12 ? 20 : 50 ); }
		function renderStudioRulers() {
			if ( ! ensureStudioRulers() ) { return; } var p = page(); if ( ! p ) { return; }
			var scrollLeft = Number( workspace.scrollLeft || 0 ), scrollTop = Number( workspace.scrollTop || 0 ); var step = rulerStepMm(); var left = Number( pageEl.offsetLeft || 0 ) - scrollLeft; var top = Number( pageEl.offsetTop || 0 ) - scrollTop;
			rulerX.style.transform = 'translate(' + scrollLeft + 'px,' + scrollTop + 'px)'; rulerY.style.transform = 'translate(' + scrollLeft + 'px,' + scrollTop + 'px)';
			rulerX.innerHTML = ''; rulerY.innerHTML = '';
			for ( var x = 0; x <= Number( p.width_mm ) + 0.001; x += step ) { var markX = document.createElement( 'span' ); markX.className = 'certloom-studio-ruler-mark'; markX.style.left = ( left + px( x, zoom ) ) + 'px'; markX.innerHTML = '<i></i><b>' + escapeText( String( round( x, 1 ) ) ) + '</b>'; rulerX.appendChild( markX ); }
			for ( var y = 0; y <= Number( p.height_mm ) + 0.001; y += step ) { var markY = document.createElement( 'span' ); markY.className = 'certloom-studio-ruler-mark'; markY.style.top = ( top + px( y, zoom ) ) + 'px'; markY.innerHTML = '<i></i><b>' + escapeText( String( round( y, 1 ) ) ) + '</b>'; rulerY.appendChild( markY ); }
		}

		function syncControls() {
			var p = page(); if ( ! p ) { return; }
			if ( sizeControl ) { sizeControl.value = p.size; }
			if ( orientationControl ) { orientationControl.value = p.orientation; }
			if ( customSizeWrap ) { customSizeWrap.hidden = 'Custom' !== p.size; }
			if ( customWidthControl ) { customWidthControl.value = String( round( Number( p.width_mm ), 1 ) ); }
			if ( customHeightControl ) { customHeightControl.value = String( round( Number( p.height_mm ), 1 ) ); }
			var displayedZoom = relativeZoomPercent();
			if ( zoomControl ) { zoomControl.value = String( displayedZoom ); }
			if ( zoomOutput ) { zoomOutput.textContent = displayedZoom + '%'; }
			if ( snapControl ) { snapControl.checked = !! designerState.snap_enabled; }
			if ( pageReadout ) { var publicReadout = p.size + ' · ' + String( p.orientation || '' ).replace( /^./, function ( first ) { return first.toUpperCase(); } ) + ' · ' + round( p.width_mm, 1 ) + ' × ' + round( p.height_mm, 1 ) + ' mm · ' + elements().length + ' ' + ( 1 === elements().length ? t( 'elementCountSingular', 'element' ) : t( 'elementCountPlural', 'elements' ) ); pageReadout.textContent = root.classList.contains( 'certloom-template-designer-v2' ) ? publicReadout : 'layout v' + schemaVersion + ' · ' + publicReadout; }
			syncHistoryButtons(); syncCanvasTools();
		}
		function combinedBounds( list ) {
			if ( ! list || ! list.length ) { return null; }
			var first = rotatedBounds( list[ 0 ] ); var minX = first.minX, maxX = first.maxX, minY = first.minY, maxY = first.maxY;
			for ( var i = 1; i < list.length; i++ ) { var b = rotatedBounds( list[ i ] ); minX = Math.min( minX, b.minX ); maxX = Math.max( maxX, b.maxX ); minY = Math.min( minY, b.minY ); maxY = Math.max( maxY, b.maxY ); }
			return { minX: minX, maxX: maxX, minY: minY, maxY: maxY, centerX: ( minX + maxX ) / 2, centerY: ( minY + maxY ) / 2, width: maxX - minX, height: maxY - minY };
		}
		function spacingSummary( list, axis ) {
			if ( ! list || list.length < 3 ) { return null; }
			var rows = list.map( function ( element ) { var b = rotatedBounds( element ); return { min: 'x' === axis ? b.minX : b.minY, max: 'x' === axis ? b.maxX : b.maxY }; } ).sort( function ( a, b ) { return a.min - b.min; } );
			var gaps = []; for ( var i = 1; i < rows.length; i++ ) { gaps.push( rows[ i ].min - rows[ i - 1 ].max ); }
			var min = Math.min.apply( Math, gaps ), max = Math.max.apply( Math, gaps ), average = gaps.reduce( function ( sum, value ) { return sum + value; }, 0 ) / gaps.length;
			return { equal: max - min <= 0.5, gap: round( average, 1 ), overlapping: min < -0.001 };
		}
		function syncSelectionStatus() {
			var list = selectedElements(); var count = list.length;
			if ( quickArrangeBar ) { quickArrangeBar.hidden = 0 === count; }
			if ( contextHint ) { contextHint.hidden = count > 0; }
			if ( distributionGroup ) { distributionGroup.hidden = count < 3; }
			if ( selectionStatus ) { selectionStatus.textContent = count > 1 ? count + ' ' + t( 'elementsSelected', 'elements selected' ) : ( 1 === count ? t( 'oneElementSelected', '1 element selected · Shift/Ctrl/Cmd-click to add more' ) : t( 'selectElementsHint', 'Shift/Ctrl/Cmd-click to select multiple' ) ); }
			if ( spacingStatus ) {
				if ( count < 3 ) { spacingStatus.textContent = t( 'spacingRequiresThree', 'Select 3 or more elements to equalize spacing.' ); }
				else if ( selectionHasLocked() ) { spacingStatus.textContent = t( 'spacingLocked', 'Unlock the selection before arranging it.' ); }
				else { var h = spacingSummary( list, 'x' ), v = spacingSummary( list, 'y' ); spacingStatus.textContent = t( 'spacingHorizontalShort', 'H' ) + ': ' + ( h && h.equal && ! h.overlapping ? h.gap + ' mm ' + t( 'equal', 'equal' ) : t( 'varied', 'varied' ) ) + ' · ' + t( 'spacingVerticalShort', 'V' ) + ': ' + ( v && v.equal && ! v.overlapping ? v.gap + ' mm ' + t( 'equal', 'equal' ) : t( 'varied', 'varied' ) ); }
			}
			if ( groupCount ) { groupCount.textContent = String( count ); }
		}
		function syncProductivityControls() {
			var element = selected(); var selection = selectedElements(); var locked = isLocked( element ); var groupLocked = selectionHasLocked(); var multi = selection.length > 1;
			for ( var i = 0; i < alignButtons.length; i++ ) { alignButtons[ i ].disabled = ! selection.length || groupLocked; }
			for ( var distributeIndex = 0; distributeIndex < distributeButtons.length; distributeIndex++ ) { distributeButtons[ distributeIndex ].disabled = selection.length < 3 || groupLocked; }
			for ( var j = 0; j < layerActionButtons.length; j++ ) { layerActionButtons[ j ].disabled = ! element || locked || multi; }
			if ( lockButton ) {
				var lockLabel = locked ? t( 'unlockElement', 'Unlock element' ) : t( 'lockElement', 'Lock element' );
				lockButton.disabled = ! element || multi; lockButton.setAttribute( 'aria-pressed', locked ? 'true' : 'false' ); lockButton.setAttribute( 'title', lockLabel ); lockButton.setAttribute( 'aria-label', lockLabel );
				var lockIcon = lockButton.querySelector( '.dashicons' ); if ( lockIcon ) { lockIcon.className = 'dashicons ' + ( locked ? 'dashicons-lock' : 'dashicons-unlock' ); }
				var lockText = lockButton.querySelector( 'span:last-child:not(.dashicons)' ); if ( lockText ) { lockText.textContent = locked ? t( 'unlock', 'Unlock' ) : t( 'lock', 'Lock' ); }
			}
			if ( removeButton ) { removeButton.disabled = ! selection.length || groupLocked; }
			if ( duplicateButton ) { duplicateButton.disabled = ! element || locked || multi || elements().length >= 500; }
			if ( copyButton ) { copyButton.disabled = ! selection.length; }
			if ( cutButton ) { cutButton.disabled = ! selection.length || groupLocked; }
			if ( pasteButton ) { pasteButton.disabled = ! designerClipboard.length || elements().length + designerClipboard.length > 500; }
			syncSelectionStatus(); syncQuickTextControls(); syncContextGroups();
		}
		function contentGroup( element ) {
			var definition = element ? registryType( element.type ) : null;
			return definition && definition.content_group ? definition.content_group : '';
		}
		function knownQuickTokens() { return [ 'recipient.name', 'credential.display_number', 'program.name', 'credential.issue_date', 'credential.expiry_date', 'credential.grade', 'issuer.name', 'credential.status' ]; }
		function sampleProfileDefinition() { return SAMPLE_DATA && SAMPLE_DATA.profiles && SAMPLE_DATA.profiles[ activeSampleProfile ] ? SAMPLE_DATA.profiles[ activeSampleProfile ] : ( SAMPLE_DATA && SAMPLE_DATA.profiles ? SAMPLE_DATA.profiles[ SAMPLE_DATA.default || 'standard' ] : null ); }
		function dynamicFieldSample( token ) {
			token = String( token || '' );
			if ( 'tokens' === activePreviewMode ) { return token ? '{{' + token + '}}' : '{{field}}'; }
			var profile = sampleProfileDefinition(); var samples = profile && profile.values && 'object' === typeof profile.values ? profile.values : {};
			if ( Object.prototype.hasOwnProperty.call( samples, token ) ) { return String( samples[ token ] ); }
			var label = token.replace( /[^a-z0-9]+/gi, ' ' ).replace( /\s+/g, ' ' ).trim();
			return t( 'sampleFallbackPrefix', 'Sample' ) + ( label ? ' · ' + label : '' );
		}
		function richRunText( run ) {
			if ( ! run || 'object' !== typeof run ) { return ''; }
			return 'token' === run.kind ? dynamicFieldSample( String( run.token || '' ) ) : String( run.text || '' );
		}
		function richTextPlain( element ) {
			var out = ''; var runs = element && Array.isArray( element.runs ) ? element.runs : [];
			for ( var i = 0; i < runs.length; i++ ) { out += richRunText( runs[ i ] ); }
			return out;
		}
		function richRunStyleCanonical( raw ) {
			if ( null == raw ) { return {}; }
			if ( 'object' !== typeof raw || Array.isArray( raw ) ) { return false; }
			var allowed = [ 'font_family', 'font_size_pt', 'font_weight', 'italic', 'color', 'letter_spacing_pt' ]; var keys = Object.keys( raw ); var out = {};
			for ( var i = 0; i < keys.length; i++ ) { if ( allowed.indexOf( keys[ i ] ) === -1 ) { return false; } }
			if ( Object.prototype.hasOwnProperty.call( raw, 'font_family' ) ) { var family = String( raw.font_family || '' ).toLowerCase(); if ( ! fontKeyAllowed( family ) ) { return false; } out.font_family = family; }
			if ( Object.prototype.hasOwnProperty.call( raw, 'font_size_pt' ) ) { var size = Number( raw.font_size_pt ); if ( ! isFinite( size ) || size < 1 || size > 300 ) { return false; } out.font_size_pt = round( size, 3 ); }
			if ( Object.prototype.hasOwnProperty.call( raw, 'font_weight' ) ) { var weight = Number( raw.font_weight ); if ( ! Number.isInteger( weight ) || weight < 100 || weight > 900 || 0 !== weight % 100 ) { return false; } out.font_weight = weight; }
			if ( Object.prototype.hasOwnProperty.call( raw, 'italic' ) ) { if ( 'boolean' !== typeof raw.italic ) { return false; } out.italic = raw.italic; }
			if ( Object.prototype.hasOwnProperty.call( raw, 'color' ) ) { var color = canonicalColor( raw.color ); if ( ! color ) { return false; } out.color = color; }
			if ( Object.prototype.hasOwnProperty.call( raw, 'letter_spacing_pt' ) ) { var spacing = Number( raw.letter_spacing_pt ); if ( ! isFinite( spacing ) || spacing < -20 || spacing > 50 ) { return false; } out.letter_spacing_pt = round( spacing, 3 ); }
			return out;
		}
		function richRunsCanonical( runs ) {
			if ( ! Array.isArray( runs ) || runs.length < 1 || runs.length > 256 ) { return false; }
			var out = [], total = 0;
			for ( var i = 0; i < runs.length; i++ ) {
				var source = runs[ i ]; if ( ! source || 'object' !== typeof source || Array.isArray( source ) ) { return false; }
				var keys = Object.keys( source ); for ( var k = 0; k < keys.length; k++ ) { if ( [ 'kind', 'text', 'token', 'style' ].indexOf( keys[ k ] ) === -1 ) { return false; } }
				var kind = String( source.kind || '' ); var run = { kind: kind };
				if ( 'text' === kind ) { if ( ! Object.prototype.hasOwnProperty.call( source, 'text' ) || Object.prototype.hasOwnProperty.call( source, 'token' ) ) { return false; } run.text = String( source.text || '' ); if ( '' === run.text || run.text.indexOf( '\0' ) !== -1 ) { return false; } total += directTextByteLength( run.text ); }
				else if ( 'token' === kind ) { if ( ! Object.prototype.hasOwnProperty.call( source, 'token' ) || Object.prototype.hasOwnProperty.call( source, 'text' ) ) { return false; } run.token = String( source.token || '' ).trim(); if ( ! /^[a-z][a-z0-9_.:-]{0,127}$/.test( run.token ) ) { return false; } total += directTextByteLength( run.token ); }
				else { return false; }
				if ( total > 20000 ) { return false; }
				if ( Object.prototype.hasOwnProperty.call( source, 'style' ) ) { var style = richRunStyleCanonical( source.style ); if ( false === style ) { return false; } if ( Object.keys( style ).length ) { run.style = style; } }
				out.push( run );
			}
			return out;
		}
		function materializeRichRunDefaults( runs ) {
			if ( ! Array.isArray( runs ) ) { return []; }
			var out = [];
			for ( var i = 0; i < runs.length; i++ ) {
				var source = clone( runs[ i ] || {} ); var run = { kind: String( source.kind || '' ) };
				if ( 'text' === run.kind ) { run.text = source.text_i18n ? t( String( source.text_i18n ), 'Text' ) : String( source.text || '' ); }
				else if ( 'token' === run.kind ) { run.token = String( source.token || 'recipient.name' ); }
				if ( source.style && 'object' === typeof source.style ) { run.style = clone( source.style ); }
				out.push( run );
			}
			var canonical = richRunsCanonical( out ); return false === canonical ? [] : canonical;
		}
		function updateRichRuns( nextRuns ) {
			var element = selected(); if ( ! element || 'rich_text' !== element.type || isLocked( element ) ) { setInspector(); return false; }
			var canonical = richRunsCanonical( nextRuns ); if ( false === canonical ) { showError( t( 'invalidRichText', 'That inline content is outside the supported rich-text contract. The previous value was kept.' ) ); setInspector(); return false; }
			var proposed = clone( element ); proposed.runs = canonical; if ( stable( proposed.runs ) === stable( element.runs || [] ) ) { setInspector(); return true; }
			var before = snapshot(); if ( updateElement( element.id, proposed, false ) ) { pushHistory( before ); render(); announce( t( 'richTextUpdated', 'Inline rich text updated.' ) ); return true; } return false;
		}
		function richStyleSelect( key, value, label ) {
			var select = document.createElement( 'select' ); select.setAttribute( 'data-certloom-designer-rich-style', key ); select.setAttribute( 'aria-label', label ); var inherit = document.createElement( 'option' ); inherit.value = ''; inherit.textContent = t( 'inherit', 'Inherit' ); select.appendChild( inherit );
			if ( 'font_family' === key ) { Object.keys( FONT_CATALOG || {} ).sort().forEach( function ( family ) { var option = document.createElement( 'option' ); option.value = family; option.textContent = ( FONT_CATALOG[ family ] && FONT_CATALOG[ family ].label ) ? FONT_CATALOG[ family ].label : family; select.appendChild( option ); } ); }
			else if ( 'font_weight' === key ) { for ( var weight = 100; weight <= 900; weight += 100 ) { var weightOption = document.createElement( 'option' ); weightOption.value = String( weight ); weightOption.textContent = String( weight ); select.appendChild( weightOption ); } }
			else if ( 'italic' === key ) { var italic = document.createElement( 'option' ); italic.value = 'true'; italic.textContent = t( 'italic', 'Italic' ); select.appendChild( italic ); var normal = document.createElement( 'option' ); normal.value = 'false'; normal.textContent = t( 'normal', 'Normal' ); select.appendChild( normal ); }
			select.value = null == value ? '' : String( value ); return select;
		}
		function renderRichRunEditor( element ) {
			if ( ! richRunsContainer ) { return; } richRunsContainer.innerHTML = '';
			if ( ! element || 'rich_text' !== element.type ) { return; }
			var locked = isLocked( element ); var runs = Array.isArray( element.runs ) ? element.runs : [];
			for ( var i = 0; i < runs.length; i++ ) {
				( function ( index, run ) {
					var row = document.createElement( 'div' ); row.className = 'certloom-template-designer-rich-run'; row.setAttribute( 'data-certloom-designer-rich-run', String( index ) );
					var head = document.createElement( 'div' ); head.className = 'certloom-template-designer-rich-run-head'; var badge = document.createElement( 'strong' ); badge.textContent = 'token' === run.kind ? t( 'inlineField', 'Field' ) : t( 'inlineText', 'Text' ); head.appendChild( badge );
					[ [ 'up', '↑', t( 'moveRunUp', 'Move run up' ) ], [ 'down', '↓', t( 'moveRunDown', 'Move run down' ) ], [ 'delete', '×', t( 'deleteRun', 'Delete run' ) ] ].forEach( function ( action ) { var button = document.createElement( 'button' ); button.type = 'button'; button.className = 'button button-small'; button.setAttribute( 'data-certloom-designer-rich-action', action[ 0 ] ); button.setAttribute( 'data-certloom-designer-rich-index', String( index ) ); button.setAttribute( 'aria-label', action[ 2 ] ); button.title = action[ 2 ]; button.textContent = action[ 1 ]; button.disabled = locked || ( 'up' === action[ 0 ] && 0 === index ) || ( 'down' === action[ 0 ] && index === runs.length - 1 ) || ( 'delete' === action[ 0 ] && runs.length <= 1 ); head.appendChild( button ); } ); row.appendChild( head );
					var valueLabel = document.createElement( 'label' ); var valueCaption = document.createElement( 'span' ); valueCaption.textContent = 'token' === run.kind ? t( 'inlineField', 'Field' ) : t( 'inlineText', 'Text' ); valueLabel.appendChild( valueCaption ); var valueInput;
					if ( 'token' === run.kind ) { valueInput = document.createElement( 'input' ); valueInput.type = 'text'; valueInput.maxLength = 128; valueInput.setAttribute( 'list', 'certloom-designer-merge-tokens' ); valueInput.value = String( run.token || '' ); }
					else { valueInput = document.createElement( 'textarea' ); valueInput.rows = 2; valueInput.maxLength = 20000; valueInput.value = String( run.text || '' ); }
					valueInput.setAttribute( 'data-certloom-designer-rich-value', run.kind ); valueInput.setAttribute( 'data-certloom-designer-rich-index', String( index ) ); valueInput.disabled = locked; valueLabel.appendChild( valueInput );
					if ( 'text' === run.kind ) { var insertField = document.createElement( 'button' ); insertField.type = 'button'; insertField.className = 'button button-small certloom-template-designer-rich-insert-field'; insertField.setAttribute( 'data-certloom-designer-rich-insert-field', String( index ) ); insertField.textContent = t( 'insertFieldAtCaret', 'Insert field at caret' ); insertField.disabled = locked || runs.length >= 256; valueLabel.appendChild( insertField ); }
					row.appendChild( valueLabel );
					var details = document.createElement( 'details' ); details.className = 'certloom-template-designer-rich-run-style'; var summary = document.createElement( 'summary' ); summary.textContent = t( 'appearance', 'Inline style' ); details.appendChild( summary ); var grid = document.createElement( 'div' ); grid.className = 'certloom-template-designer-rich-run-style-grid'; var style = run.style || {};
					var fontLabel = document.createElement( 'label' ); fontLabel.appendChild( document.createTextNode( t( 'font', 'Font' ) ) ); fontLabel.appendChild( richStyleSelect( 'font_family', style.font_family, t( 'font', 'Font' ) ) ); grid.appendChild( fontLabel );
					var sizeLabel = document.createElement( 'label' ); sizeLabel.appendChild( document.createTextNode( t( 'size', 'Size (pt)' ) ) ); var size = document.createElement( 'input' ); size.type = 'number'; size.min = '1'; size.max = '300'; size.step = '0.5'; size.placeholder = t( 'inherit', 'Inherit' ); size.setAttribute( 'data-certloom-designer-rich-style', 'font_size_pt' ); size.value = null == style.font_size_pt ? '' : String( style.font_size_pt ); sizeLabel.appendChild( size ); grid.appendChild( sizeLabel );
					var weightLabel = document.createElement( 'label' ); weightLabel.appendChild( document.createTextNode( t( 'weight', 'Weight' ) ) ); weightLabel.appendChild( richStyleSelect( 'font_weight', style.font_weight, t( 'weight', 'Weight' ) ) ); grid.appendChild( weightLabel );
					var italicLabel = document.createElement( 'label' ); italicLabel.appendChild( document.createTextNode( t( 'italic', 'Italic' ) ) ); italicLabel.appendChild( richStyleSelect( 'italic', Object.prototype.hasOwnProperty.call( style, 'italic' ) ? style.italic : null, t( 'italic', 'Italic' ) ) ); grid.appendChild( italicLabel );
					var colorLabel = document.createElement( 'label' ); colorLabel.appendChild( document.createTextNode( t( 'textColor', 'Color' ) ) ); var color = document.createElement( 'input' ); color.type = 'text'; color.maxLength = 9; color.placeholder = t( 'inherit', 'Inherit' ); color.setAttribute( 'data-certloom-designer-rich-style', 'color' ); color.value = style.color || ''; colorLabel.appendChild( color ); grid.appendChild( colorLabel );
					var spacingLabel = document.createElement( 'label' ); spacingLabel.appendChild( document.createTextNode( t( 'letterSpacing', 'Letter spacing (pt)' ) ) ); var spacing = document.createElement( 'input' ); spacing.type = 'number'; spacing.min = '-20'; spacing.max = '50'; spacing.step = '0.1'; spacing.placeholder = t( 'inherit', 'Inherit' ); spacing.setAttribute( 'data-certloom-designer-rich-style', 'letter_spacing_pt' ); spacing.value = null == style.letter_spacing_pt ? '' : String( style.letter_spacing_pt ); spacingLabel.appendChild( spacing ); grid.appendChild( spacingLabel );
					for ( var fieldIndex = 0; fieldIndex < grid.querySelectorAll( 'input,select' ).length; fieldIndex++ ) { var styleInput = grid.querySelectorAll( 'input,select' )[ fieldIndex ]; styleInput.setAttribute( 'data-certloom-designer-rich-index', String( index ) ); styleInput.disabled = locked; }
					details.appendChild( grid ); row.appendChild( details ); richRunsContainer.appendChild( row );
				} )( i, runs[ i ] );
			}
			for ( var b = 0; b < richAddButtons.length; b++ ) { richAddButtons[ b ].disabled = locked || runs.length >= 256; }
		}
		function focusRichTextEditor() {
			var element = selected(); if ( ! element || 'rich_text' !== element.type || ! richRunsContainer ) { return; }
			var section = richRunsContainer.closest( '[data-certloom-designer-content="rich_text"]' ); if ( section && section.scrollIntoView ) { try { section.scrollIntoView( { block: 'nearest', behavior: 'smooth' } ); } catch ( error ) { section.scrollIntoView(); } }
			var first = richRunsContainer.querySelector( '[data-certloom-designer-rich-value]' ); if ( first ) { window.setTimeout( function () { first.focus(); }, 0 ); }
		}

		function addRichRun( kind ) {
			var element = selected(); if ( ! element || 'rich_text' !== element.type || isLocked( element ) ) { return; }
			var runs = Array.isArray( element.runs ) ? clone( element.runs ) : []; if ( runs.length >= 256 ) { showError( t( 'richTextRunLimit', 'Inline rich text supports at most 256 runs.' ) ); return; }
			runs.push( 'token' === kind ? { kind: 'token', token: 'recipient.name' } : { kind: 'text', text: t( 'text', 'Text' ) } );
			if ( updateRichRuns( runs ) ) { window.setTimeout( function () { var fields = richRunsContainer ? richRunsContainer.querySelectorAll( '[data-certloom-designer-rich-value]' ) : []; if ( fields.length ) { fields[ fields.length - 1 ].focus(); } }, 0 ); }
		}
		function changeRichRunValue( input ) {
			var element = selected(); if ( ! element || 'rich_text' !== element.type || isLocked( element ) ) { return; }
			var index = Number( input.getAttribute( 'data-certloom-designer-rich-index' ) ); if ( ! Number.isInteger( index ) || ! element.runs || ! element.runs[ index ] ) { return; }
			var runs = clone( element.runs ); if ( 'token' === input.getAttribute( 'data-certloom-designer-rich-value' ) ) { runs[ index ].token = String( input.value || '' ).trim(); } else { runs[ index ].text = String( input.value || '' ); }
			updateRichRuns( runs );
		}
		function changeRichRunStyle( input ) {
			var element = selected(); if ( ! element || 'rich_text' !== element.type || isLocked( element ) ) { return; }
			var index = Number( input.getAttribute( 'data-certloom-designer-rich-index' ) ); var key = String( input.getAttribute( 'data-certloom-designer-rich-style' ) || '' ); if ( ! Number.isInteger( index ) || ! element.runs || ! element.runs[ index ] || ! key ) { return; }
			var runs = clone( element.runs ); var style = runs[ index ].style && 'object' === typeof runs[ index ].style ? clone( runs[ index ].style ) : {}; var raw = String( input.value || '' ).trim();
			if ( '' === raw ) { delete style[ key ]; }
			else if ( 'italic' === key ) { if ( 'true' !== raw && 'false' !== raw ) { renderRichRunEditor( element ); return; } style[ key ] = 'true' === raw; }
			else if ( [ 'font_size_pt', 'font_weight', 'letter_spacing_pt' ].indexOf( key ) !== -1 ) { style[ key ] = Number( raw ); }
			else { style[ key ] = raw; }
			if ( Object.keys( style ).length ) { runs[ index ].style = style; } else { delete runs[ index ].style; }
			updateRichRuns( runs );
		}
		function richRunAction( action, index ) {
			var element = selected(); if ( ! element || 'rich_text' !== element.type || isLocked( element ) || ! Array.isArray( element.runs ) ) { return; }
			var runs = clone( element.runs ); if ( ! Number.isInteger( index ) || index < 0 || index >= runs.length ) { return; }
			if ( 'delete' === action ) { if ( runs.length <= 1 ) { return; } runs.splice( index, 1 ); }
			else if ( 'up' === action ) { if ( index <= 0 ) { return; } var up = runs.splice( index, 1 )[ 0 ]; runs.splice( index - 1, 0, up ); }
			else if ( 'down' === action ) { if ( index >= runs.length - 1 ) { return; } var down = runs.splice( index, 1 )[ 0 ]; runs.splice( index + 1, 0, down ); }
			else { return; }
			updateRichRuns( runs );
		}
		function insertRichTokenAtCaret( index, textarea ) {
			var element = selected(); if ( ! element || 'rich_text' !== element.type || isLocked( element ) || ! Array.isArray( element.runs ) || ! element.runs[ index ] || 'text' !== element.runs[ index ].kind ) { return; }
			var source = clone( element.runs[ index ] ); var text = String( source.text || '' ); var caret = textarea && Number.isInteger( textarea.selectionStart ) ? textarea.selectionStart : text.length; caret = Math.max( 0, Math.min( text.length, caret ) );
			var replacement = []; var left = text.slice( 0, caret ), right = text.slice( caret ); var inherited = source.style && 'object' === typeof source.style ? clone( source.style ) : null;
			if ( left ) { var leftRun = { kind: 'text', text: left }; if ( inherited ) { leftRun.style = clone( inherited ); } replacement.push( leftRun ); }
			var tokenRun = { kind: 'token', token: 'recipient.name' }; if ( inherited ) { tokenRun.style = clone( inherited ); } var tokenOffset = replacement.length; replacement.push( tokenRun );
			if ( right ) { var rightRun = { kind: 'text', text: right }; if ( inherited ) { rightRun.style = clone( inherited ); } replacement.push( rightRun ); }
			var runs = clone( element.runs ); runs.splice.apply( runs, [ index, 1 ].concat( replacement ) ); if ( runs.length > 256 ) { showError( t( 'richTextRunLimit', 'Inline rich text supports at most 256 runs.' ) ); return; }
			if ( updateRichRuns( runs ) ) { var tokenIndex = index + tokenOffset; window.setTimeout( function () { if ( ! richRunsContainer ) { return; } var field = richRunsContainer.querySelector( '[data-certloom-designer-rich-value="token"][data-certloom-designer-rich-index="' + tokenIndex + '"]' ); if ( field ) { field.focus(); if ( field.select ) { field.select(); } } }, 0 ); }
		}
		function syncQuickToken() {
			if ( ! quickTokenSelect || ! rawTokenInput ) { return; }
			var element = selected(); var token = element && 'merge' === element.type ? String( element.token || '' ) : ''; var known = knownQuickTokens(); var custom = token && known.indexOf( token ) === -1;
			quickTokenSelect.value = custom ? '__custom__' : ( token || 'recipient.name' );
			if ( customTokenWrap ) { customTokenWrap.classList.toggle( 'is-custom', custom || '__custom__' === quickTokenSelect.value ); }
		}
		function textStyleElement() {
			var selection = selectedElements(); if ( 1 !== selection.length ) { return null; }
			var definition = registryType( selection[ 0 ].type ); return definition && 'text' === definition.style_group ? selection[ 0 ] : null;
		}
		function syncContextGroups() {
			var selection = selectedElements(); var element = selected(); var locked = isLocked( element );
			for ( var i = 0; i < contextGroups.length; i++ ) { contextGroups[ i ].hidden = true; }
			if ( contextEditTextButton ) { contextEditTextButton.hidden = ! element || 'text' !== element.type; contextEditTextButton.disabled = ! element || locked || 'text' !== element.type; }
			if ( contextRichTextButton ) { contextRichTextButton.hidden = ! element || 'rich_text' !== element.type; contextRichTextButton.disabled = ! element || locked || 'rich_text' !== element.type; }
			if ( contextTokenWrap ) { contextTokenWrap.hidden = ! element || 'merge' !== element.type; }
			if ( contextTokenSelect ) { contextTokenSelect.disabled = ! element || locked || 'merge' !== element.type; if ( element && 'merge' === element.type ) { var token = String( element.token || 'recipient.name' ); var option = contextTokenSelect.querySelector( 'option[value="' + token.replace( /"/g, '\\"' ) + '"]' ); contextTokenSelect.value = option ? token : 'recipient.name'; } }
			if ( 1 !== selection.length || ! element ) { return; }
			var group = contentGroup( element );
			for ( var j = 0; j < contextGroups.length; j++ ) { if ( contextGroups[ j ].getAttribute( 'data-certloom-designer-context-group' ) === group ) { contextGroups[ j ].hidden = false; } }
		}

		function syncQuickTextControls() {
			if ( ! quickTextBar ) { return; }
			var element = textStyleElement(); var locked = isLocked( element ); quickTextBar.hidden = ! element;
			for ( var i = 0; i < quickStyleFields.length; i++ ) {
				var key = quickStyleFields[ i ].getAttribute( 'data-certloom-designer-quick-style' ); var value = element ? styleValueForElement( element, key ) : '';
				quickStyleFields[ i ].disabled = ! element || locked; if ( element ) { quickStyleFields[ i ].value = null == value ? '' : String( value ); }
			}
			for ( var toggleIndex = 0; toggleIndex < quickToggleButtons.length; toggleIndex++ ) {
				var toggle = quickToggleButtons[ toggleIndex ].getAttribute( 'data-certloom-designer-quick-toggle' ); var pressed = false;
				if ( element && 'bold' === toggle ) { pressed = Number( styleValueForElement( element, 'font_weight' ) || 400 ) >= 700; }
				if ( element && 'italic' === toggle ) { pressed = !! styleValueForElement( element, 'italic' ); }
				quickToggleButtons[ toggleIndex ].disabled = ! element || locked; quickToggleButtons[ toggleIndex ].setAttribute( 'aria-pressed', pressed ? 'true' : 'false' ); quickToggleButtons[ toggleIndex ].classList.toggle( 'button-primary', pressed );
			}
			for ( var alignIndex = 0; alignIndex < quickTextAlignButtons.length; alignIndex++ ) {
				var mode = quickTextAlignButtons[ alignIndex ].getAttribute( 'data-certloom-designer-quick-text-align' ); var active = element && String( styleValueForElement( element, 'align' ) || 'center' ) === mode;
				quickTextAlignButtons[ alignIndex ].disabled = ! element || locked; quickTextAlignButtons[ alignIndex ].setAttribute( 'aria-pressed', active ? 'true' : 'false' ); quickTextAlignButtons[ alignIndex ].classList.toggle( 'button-primary', active );
			}
		}
		function tableRowCapacity( element, showHeader ) {
			var slots = Math.floor( Number( element && element.height_mm || 0 ) / 4 ) - ( showHeader ? 1 : 0 );
			return Math.max( 1, Math.min( 50, slots ) );
		}
		function commitTableElement( element, proposed, message ) {
			if ( ! element || isLocked( element ) ) { setInspector(); return false; }
			var before = snapshot(); if ( updateElement( element.id, proposed, false ) ) { pushHistory( before ); render(); announce( message || t( 'tableUpdated', 'Table updated.' ) ); return true; } return false;
		}
		function tableSourceRows( element ) {
			if ( ! element ) { return []; }
			if ( 'table' === element.type ) { return Array.isArray( element.rows ) ? element.rows : []; }
			var profile = sampleProfileDefinition(); var values = profile && profile.values && 'object' === typeof profile.values ? profile.values : {}; var rows = values[ String( element.source || '' ) ];
			return Array.isArray( rows ) ? rows.slice( 0, Number( element.max_rows || 8 ) ) : [];
		}
		function renderTableEditor( element ) {
			var tableElement = element && ( 'table' === element.type || 'data_table' === element.type ) ? element : null; var locked = isLocked( tableElement );
			for ( var c = 0; c < tableColumnContainers.length; c++ ) {
				var container = tableColumnContainers[ c ]; container.innerHTML = ''; var section = container.closest( '[data-certloom-designer-content]' ); if ( ! tableElement || ! section || section.getAttribute( 'data-certloom-designer-content' ) !== tableElement.type ) { continue; }
				var columns = Array.isArray( tableElement.columns ) ? tableElement.columns : [];
				for ( var ci = 0; ci < columns.length; ci++ ) { ( function ( index ) {
					var column = columns[ index ]; var row = document.createElement( 'div' ); row.className = 'certloom-template-designer-table-column'; row.setAttribute( 'data-certloom-designer-table-column', String( index ) );
					var label = document.createElement( 'label' ); label.innerHTML = '<span>' + t( 'columnLabel', 'Label' ) + '</span>'; var labelInput = document.createElement( 'input' ); labelInput.type = 'text'; labelInput.maxLength = 200; labelInput.value = String( column.label || '' ); labelInput.disabled = locked; label.appendChild( labelInput ); row.appendChild( label );
					if ( 'data_table' === tableElement.type ) { var fieldLabel = document.createElement( 'label' ); fieldLabel.innerHTML = '<span>' + t( 'dataField', 'Data field' ) + '</span>'; var fieldSelect = document.createElement( 'select' ); var fields = tableFieldsForSource( String( tableElement.source || 'credential.results' ) ); for ( var fi = 0; fi < fields.length; fi++ ) { var option = document.createElement( 'option' ); option.value = fields[ fi ]; option.textContent = fields[ fi ].replace( /_/g, ' ' ); fieldSelect.appendChild( option ); } fieldSelect.value = String( column.field || fields[ 0 ] || '' ); fieldSelect.disabled = locked; fieldLabel.appendChild( fieldSelect ); row.appendChild( fieldLabel ); fieldSelect.addEventListener( 'change', function () { var current = selected(); if ( ! current || current.id !== tableElement.id ) { return; } var proposed = clone( current ); proposed.columns[ index ].field = String( this.value || '' ); commitTableElement( current, proposed ); } ); }
					var widthLabel = document.createElement( 'label' ); widthLabel.innerHTML = '<span>' + t( 'columnWidth', 'Width %' ) + '</span>'; var widthInput = document.createElement( 'input' ); widthInput.type = 'number'; widthInput.min = '2'; widthInput.max = '98'; widthInput.step = '1'; widthInput.value = String( column.width_pct || 0 ); widthInput.disabled = locked || columns.length < 2; widthLabel.appendChild( widthInput ); row.appendChild( widthLabel );
					var alignLabel = document.createElement( 'label' ); alignLabel.innerHTML = '<span>' + t( 'columnAlign', 'Align' ) + '</span>'; var alignSelect = document.createElement( 'select' ); [ 'left', 'center', 'right', 'start', 'end' ].forEach( function ( value ) { var option = document.createElement( 'option' ); option.value = value; option.textContent = value; alignSelect.appendChild( option ); } ); alignSelect.value = String( column.align || 'left' ); alignSelect.disabled = locked; alignLabel.appendChild( alignSelect ); row.appendChild( alignLabel );
					var remove = document.createElement( 'button' ); remove.type = 'button'; remove.className = 'button button-small'; remove.textContent = t( 'removeColumn', 'Remove' ); remove.disabled = locked || columns.length <= 1; row.appendChild( remove ); container.appendChild( row );
					labelInput.addEventListener( 'change', function () { var current = selected(); var value = String( this.value || '' ).trim(); if ( ! current || current.id !== tableElement.id || ! value || directTextByteLength( value ) > 200 ) { setInspector(); return; } var proposed = clone( current ); proposed.columns[ index ].label = value; commitTableElement( current, proposed ); } );
					alignSelect.addEventListener( 'change', function () { var current = selected(); if ( ! current || current.id !== tableElement.id ) { return; } var proposed = clone( current ); proposed.columns[ index ].align = String( this.value || 'left' ); commitTableElement( current, proposed ); } );
					widthInput.addEventListener( 'change', function () { var current = selected(); var width = Number( this.value ); if ( ! current || current.id !== tableElement.id || ! isFinite( width ) || width < 2 || width > 98 || current.columns.length < 2 ) { setInspector(); return; } var proposed = clone( current ), others = proposed.columns.length - 1, remaining = 100 - width, used = 0; if ( remaining < 2 * others ) { setInspector(); return; } proposed.columns[ index ].width_pct = round( width, 3 ); for ( var wi = 0; wi < proposed.columns.length; wi++ ) { if ( wi === index ) { continue; } var share = wi === proposed.columns.length - 1 || ( index === proposed.columns.length - 1 && wi === proposed.columns.length - 2 ) ? round( remaining - used, 3 ) : round( remaining / others, 3 ); proposed.columns[ wi ].width_pct = share; used += share; } commitTableElement( current, proposed ); } );
					remove.addEventListener( 'click', function () { var current = selected(); if ( ! current || current.id !== tableElement.id || ! Array.isArray( current.columns ) || current.columns.length <= 1 ) { return; } var proposed = clone( current ); var removedKey = proposed.columns[ index ].key; proposed.columns.splice( index, 1 ); rebalanceTableWidths( proposed.columns ); if ( 'table' === proposed.type && Array.isArray( proposed.rows ) ) { proposed.rows.forEach( function ( item ) { if ( item && 'object' === typeof item ) { delete item[ removedKey ]; } } ); } commitTableElement( current, proposed, t( 'tableColumnRemoved', 'Table column removed.' ) ); } );
				} )( ci ); }
			}
			for ( var r = 0; r < tableRowContainers.length; r++ ) {
				var rowsContainer = tableRowContainers[ r ]; rowsContainer.innerHTML = ''; var rowSection = rowsContainer.closest( '[data-certloom-designer-content]' ); if ( ! tableElement || 'table' !== tableElement.type || ! rowSection || 'table' !== rowSection.getAttribute( 'data-certloom-designer-content' ) ) { continue; }
				var tableRows = Array.isArray( tableElement.rows ) ? tableElement.rows : [], tableColumns = Array.isArray( tableElement.columns ) ? tableElement.columns : [];
				for ( var ri = 0; ri < tableRows.length; ri++ ) { ( function ( rowIndex ) { var rowData = tableRows[ rowIndex ]; var rowNode = document.createElement( 'div' ); rowNode.className = 'certloom-template-designer-table-row'; rowNode.setAttribute( 'data-certloom-designer-table-row', String( rowIndex ) ); for ( var coli = 0; coli < tableColumns.length; coli++ ) { ( function ( columnIndex ) { var col = tableColumns[ columnIndex ]; var label = document.createElement( 'label' ); label.innerHTML = '<span>' + escapeText( col.label || col.key ) + '</span>'; var input = document.createElement( 'input' ); input.type = 'text'; input.maxLength = 1000; input.value = String( rowData[ col.key ] || '' ); input.disabled = locked; label.appendChild( input ); rowNode.appendChild( label ); input.addEventListener( 'change', function () { var current = selected(); var value = String( this.value || '' ); if ( ! current || current.id !== tableElement.id || directTextByteLength( value ) > 1000 ) { setInspector(); return; } var proposed = clone( current ); proposed.rows[ rowIndex ][ col.key ] = value; commitTableElement( current, proposed ); } ); } )( coli ); } var removeRow = document.createElement( 'button' ); removeRow.type = 'button'; removeRow.className = 'button button-small'; removeRow.textContent = t( 'removeRow', 'Remove row' ); removeRow.disabled = locked || tableRows.length <= 1; rowNode.appendChild( removeRow ); rowsContainer.appendChild( rowNode ); removeRow.addEventListener( 'click', function () { var current = selected(); if ( ! current || current.id !== tableElement.id || current.rows.length <= 1 ) { return; } var proposed = clone( current ); proposed.rows.splice( rowIndex, 1 ); commitTableElement( current, proposed, t( 'tableRowRemoved', 'Table row removed.' ) ); } ); } )( ri ); }
			}
			for ( var i = 0; i < tableSourceControls.length; i++ ) { tableSourceControls[ i ].disabled = locked || ! tableElement || 'data_table' !== tableElement.type; if ( tableElement && 'data_table' === tableElement.type ) { tableSourceControls[ i ].value = String( tableElement.source || 'credential.results' ); } }
			for ( var j = 0; j < tableMaxRowsControls.length; j++ ) { tableMaxRowsControls[ j ].disabled = locked || ! tableElement || 'data_table' !== tableElement.type; if ( tableElement && 'data_table' === tableElement.type ) { tableMaxRowsControls[ j ].value = String( tableElement.max_rows || 8 ); tableMaxRowsControls[ j ].max = String( tableRowCapacity( tableElement, !! tableElement.show_header ) ); } }
			for ( var h = 0; h < tableHeaderControls.length; h++ ) { tableHeaderControls[ h ].disabled = locked || ! tableElement; if ( tableElement ) { tableHeaderControls[ h ].checked = !! tableElement.show_header; } }
			for ( var ac = 0; ac < tableAddColumnButtons.length; ac++ ) { var acSection = tableAddColumnButtons[ ac ].closest( '[data-certloom-designer-content]' ); tableAddColumnButtons[ ac ].disabled = locked || ! tableElement || ! acSection || acSection.getAttribute( 'data-certloom-designer-content' ) !== tableElement.type || ( tableElement.columns || [] ).length >= 12; }
			for ( var ar = 0; ar < tableAddRowButtons.length; ar++ ) { tableAddRowButtons[ ar ].disabled = locked || ! tableElement || 'table' !== tableElement.type || ( tableElement.rows || [] ).length >= tableRowCapacity( tableElement, !! tableElement.show_header ); }
		}
		function setInspector() {
			var selection = selectedElements(); var element = selected();
			for ( var sectionIndex = 0; sectionIndex < contentSections.length; sectionIndex++ ) {
				contentSections[ sectionIndex ].hidden = true;
				var inactiveControls = contentSections[ sectionIndex ].querySelectorAll( 'input, select, textarea, button' );
				for ( var inactiveIndex = 0; inactiveIndex < inactiveControls.length; inactiveIndex++ ) { inactiveControls[ inactiveIndex ].disabled = true; }
			}
			if ( groupInspector ) { groupInspector.hidden = selection.length < 2; }
			if ( selection.length > 1 ) {
				if ( emptyInspector ) { emptyInspector.hidden = true; }
				if ( fieldsInspector ) { fieldsInspector.hidden = true; }
				renderTableEditor( null ); syncProductivityControls(); return;
			}
			if ( ! element ) {
				if ( emptyInspector ) { emptyInspector.hidden = false; }
				if ( fieldsInspector ) { fieldsInspector.hidden = true; }
				renderTableEditor( null ); syncProductivityControls(); return;
			}
			var locked = isLocked( element );
			if ( emptyInspector ) { emptyInspector.hidden = true; }
			if ( fieldsInspector ) { fieldsInspector.hidden = false; }
			openPreferredInspectorSection( element );
			if ( typeOutput ) { typeOutput.textContent = typeLabel( element.type ).toUpperCase(); }
			if ( idOutput ) { idOutput.textContent = element.id || ''; }
			if ( advancedIdOutput ) { advancedIdOutput.textContent = element.id || ''; }
			if ( lockStatus ) { lockStatus.textContent = locked ? t( 'lockedForAuthor', 'Locked for this author' ) : t( 'editable', 'Editable' ); lockStatus.classList.toggle( 'is-locked', locked ); }
			for ( var i = 0; i < fieldInputs.length; i++ ) {
				var key = fieldInputs[ i ].getAttribute( 'data-certloom-designer-field' );
				var map = { x: 'x_mm', y: 'y_mm', width: 'width_mm', height: 'height_mm', rotation: 'rotation_deg' };
				fieldInputs[ i ].value = round( Number( element[ map[ key ] ] || 0 ), 3 ); fieldInputs[ i ].disabled = locked;
			}
			var group = contentGroup( element );
			for ( var section = 0; section < contentSections.length; section++ ) {
				if ( contentSections[ section ].getAttribute( 'data-certloom-designer-content' ) === group ) {
					contentSections[ section ].hidden = false;
					var activeControls = contentSections[ section ].querySelectorAll( 'input, select, textarea, button' );
					for ( var activeIndex = 0; activeIndex < activeControls.length; activeIndex++ ) { activeControls[ activeIndex ].disabled = locked; }
				}
			}
			for ( var field = 0; field < contentFields.length; field++ ) {
				var contentKey = contentFields[ field ].getAttribute( 'data-certloom-designer-content-field' );
				if ( null == contentKey ) { continue; }
				var ownerSection = contentFields[ field ].closest( '[data-certloom-designer-content]' );
				var activeField = !! ownerSection && ownerSection.getAttribute( 'data-certloom-designer-content' ) === group && ! ownerSection.hidden;
				if ( ! activeField ) { contentFields[ field ].disabled = true; continue; }
				var value = null == element[ contentKey ] ? '' : element[ contentKey ];
				if ( 'items' === contentKey && Array.isArray( value ) ) { value = value.join( '\n' ); }
				contentFields[ field ].value = String( value ); contentFields[ field ].disabled = locked;
			}
			var elementDefinition = registryType( element.type ); var styleGroup = elementDefinition && elementDefinition.style_group ? elementDefinition.style_group : '';
			for ( var styleSection = 0; styleSection < styleSections.length; styleSection++ ) {
				var sectionGroup = styleSections[ styleSection ].getAttribute( 'data-certloom-designer-style' );
				styleSections[ styleSection ].hidden = 'general' !== sectionGroup && sectionGroup !== styleGroup;
			}
			for ( var styleField = 0; styleField < styleFields.length; styleField++ ) {
				var styleKey = styleFields[ styleField ].getAttribute( 'data-certloom-designer-style-field' );
				var styleValue = styleValueForElement( element, styleKey );
				if ( 'checkbox' === styleFields[ styleField ].type ) { styleFields[ styleField ].checked = !! styleValue; } else { styleFields[ styleField ].value = null == styleValue ? '' : String( styleValue ); }
				styleFields[ styleField ].disabled = locked || ( 'font_family' === styleKey && ! fontDefinition( String( styleFields[ styleField ].value || '' ) || 'sans-serif' ) && ! fontDefinition( 'sans-serif' ) );
			}
			if ( colorPicker ) {
				var inspectorColor = canonicalColor( styleValueForElement( element, 'color' ) );
				colorPicker.value = inspectorColor ? inspectorColor.slice( 0, 7 ) : '#000000';
				colorPicker.disabled = locked || ! elementDefinition || 'text' !== elementDefinition.style_group;
			}
			for ( var mediaIndex = 0; mediaIndex < mediaButtons.length; mediaIndex++ ) { mediaButtons[ mediaIndex ].disabled = locked || ! elementDefinition || ! elementDefinition.media; }
			if ( signatureSourceStatus ) {
				signatureSourceStatus.textContent = 'signature' === element.type && 'signer.signature' === String( element.source || '' )
					? t( 'signatureUsesSigner', 'Source: credential Signer snapshot. The issued credential must resolve a Signer with a signature image.' )
					: ( 'signature' === element.type ? t( 'signatureUsesFixedAsset', 'Source: fixed WordPress Media Library image.' ) : '' );
			}
			for ( var signatureSourceIndex = 0; signatureSourceIndex < signatureSourceButtons.length; signatureSourceIndex++ ) {
				signatureSourceButtons[ signatureSourceIndex ].disabled = locked || 'signature' !== element.type || schemaVersion < 4;
			}
			renderTableEditor( element );
			renderRichRunEditor( element );
			syncQuickToken();
			syncProductivityControls();
		}

		function elementTitle( element ) {
			var preview = previewKind( element );
			if ( 'text' === preview ) { return escapeText( element.text ); }
			if ( 'merge' === preview ) { return dynamicFieldSample( String( element.token || '' ) ); }
			if ( 'rich_text' === preview ) { return richTextPlain( element ); }
			if ( 'qr' === preview ) { return t( 'qr', 'QR' ); }
			if ( 'barcode' === preview ) { return t( 'barcode', 'Barcode' ) + ' · ' + dynamicFieldSample( String( element.source || 'credential.display_number' ) ); }
			if ( 'list' === preview ) { return t( 'list', 'List' ) + ' · ' + ( Array.isArray( element.items ) ? element.items.length : 0 ) + ' ' + t( 'items', 'items' ); }
			if ( 'shape' === preview ) { return escapeText( element.shape || t( 'shape', 'Shape' ) ); }
			if ( 'line' === preview ) { return t( 'line', 'Line' ); }
			if ( 'asset' === preview ) { return typeLabel( element.type ) + ' · ' + ( 'signature' === element.type && 'signer.signature' === String( element.source || '' ) ? t( 'credentialSigner', 'Credential Signer' ) : escapeText( element.asset || '' ) ); }
			return escapeText( element.type );
		}
		function secureUuidV4() {
			var cryptoObject = window.crypto || window.msCrypto;
			if ( ! cryptoObject || ! cryptoObject.getRandomValues ) { return ''; }
			var bytes = new Uint8Array( 16 ); cryptoObject.getRandomValues( bytes ); bytes[ 6 ] = ( bytes[ 6 ] & 15 ) | 64; bytes[ 8 ] = ( bytes[ 8 ] & 63 ) | 128;
			var hex = []; for ( var i = 0; i < bytes.length; i++ ) { hex.push( ( '0' + bytes[ i ].toString( 16 ) ).slice( -2 ) ); }
			return hex.slice( 0, 4 ).join( '' ) + '-' + hex.slice( 4, 6 ).join( '' ) + '-' + hex.slice( 6, 8 ).join( '' ) + '-' + hex.slice( 8, 10 ).join( '' ) + '-' + hex.slice( 10, 16 ).join( '' );
		}
		function defaultTextStyle() {
			return { font_family: 'sans-serif', font_size_pt: 18, font_weight: 400, italic: false, color: '#000000', align: 'center', vertical_align: 'middle', direction: 'auto', line_height: 1.2, letter_spacing_pt: 0, overflow: 'shrink' };
		}
		function defaultTableStyle() {
			return { font_family: 'sans-serif', font_size_pt: 10, font_weight: 400, italic: false, color: '#111827', align: 'left', vertical_align: 'middle', direction: 'auto', line_height: 1.2, letter_spacing_pt: 0, overflow: 'shrink', header_fill: '#F3F4F6', header_color: '#111827', header_font_weight: 600, row_fill: '#FFFFFF', alternate_row_fill: '#F9FAFB', border_color: '#D1D5DB', border_width_mm: 0.2, cell_padding_mm: 1.5 };
		}
		function tableFieldsForSource( source ) { return 'credential.subjects' === source ? [ 'subject', 'code', 'credits' ] : [ 'subject', 'code', 'score', 'max_score', 'percentage', 'grade', 'credits', 'status' ]; }
		function tableColumnsForSource( source ) {
			if ( 'credential.subjects' === source ) { return [ { key: 'subject', label: t( 'subject', 'Subject' ), field: 'subject', width_pct: 60, align: 'left' }, { key: 'code', label: t( 'code', 'Code' ), field: 'code', width_pct: 20, align: 'center' }, { key: 'credits', label: t( 'credits', 'Credits' ), field: 'credits', width_pct: 20, align: 'center' } ]; }
			return [ { key: 'subject', label: t( 'subject', 'Subject' ), field: 'subject', width_pct: 50, align: 'left' }, { key: 'score', label: t( 'score', 'Score' ), field: 'score', width_pct: 25, align: 'center' }, { key: 'grade', label: t( 'grade', 'Grade' ), field: 'grade', width_pct: 25, align: 'center' } ];
		}
		function materializeTableDefaults( element, preset ) {
			element.show_header = true; element.style = defaultTableStyle();
			if ( 'data_table' === element.type ) { element.source = element.source || 'credential.results'; element.max_rows = Number( element.max_rows || 8 ); element.columns = tableColumnsForSource( element.source ); return; }
			element.columns = [ { key: 'label', label: t( 'label', 'Label' ), width_pct: 40, align: 'left' }, { key: 'value', label: t( 'value', 'Value' ), width_pct: 60, align: 'left' } ];
			element.rows = [ { label: t( 'item', 'Item' ), value: t( 'value', 'Value' ) } ];
		}
		function rebalanceTableWidths( columns ) { var count = columns.length || 1, used = 0; for ( var i = 0; i < columns.length; i++ ) { var width = i === columns.length - 1 ? round( 100 - used, 3 ) : round( 100 / count, 3 ); columns[ i ].width_pct = width; used += width; } return columns; }
		function normalizeZOrder() {
			var ordered = elements().slice().sort( function ( a, b ) { var diff = Number( a.z || 0 ) - Number( b.z || 0 ); return diff || String( a.id ).localeCompare( String( b.id ) ); } );
			for ( var i = 0; i < ordered.length; i++ ) { ordered[ i ].z = i; }
		}
		function centeredGeometry( width, height ) {
			var p = page(); var offset = Math.min( 16, elements().length % 9 * 2 );
			return { x_mm: round( clamp( ( Number( p.width_mm ) - width ) / 2 + offset, 0, Math.max( 0, Number( p.width_mm ) - width ) ), 3 ), y_mm: round( clamp( ( Number( p.height_mm ) - height ) / 2 + offset, 0, Math.max( 0, Number( p.height_mm ) - height ) ), 3 ), width_mm: width, height_mm: height };
		}
		function geometryAtPoint( width, height, point ) {
			if ( ! point || ! isFinite( point.x_mm ) || ! isFinite( point.y_mm ) ) { return centeredGeometry( width, height ); }
			var p = page();
			return { x_mm: round( clamp( Number( point.x_mm ) - width / 2, 0, Math.max( 0, Number( p.width_mm ) - width ) ), 3 ), y_mm: round( clamp( Number( point.y_mm ) - height / 2, 0, Math.max( 0, Number( p.height_mm ) - height ) ), 3 ), width_mm: width, height_mm: height };
		}
		function buildNewElement( kind, assetReference, point ) {
			var palette = paletteDefinition( kind );
			if ( ! palette ) { showError( t( 'elementRegistryUnavailable', 'The Designer could not load its editing controls. Reload the page before editing this design.' ) ); return null; }
			if ( schemaVersion < Number( palette.min_schema_version || 1 ) ) { showError( t( 'elementRequiresSchemaUpgrade', 'This element needs a newer design format. Save or upgrade the design before adding it.' ) ); return null; }
			if ( Number( palette.max_schema_version || 0 ) && schemaVersion > Number( palette.max_schema_version ) ) { showError( t( 'legacyElementAuthoring', 'This legacy element remains readable, but new designs use inline rich text instead.' ) ); return null; }
			var id = secureUuidV4(); if ( ! id ) { showError( t( 'uuidUnavailable', 'This browser cannot safely add a new design element. Update the browser and try again.' ) ); return null; }
			var p = page(), defaults = palette.defaults || {}, geometry;
			if ( defaults.full_page ) { geometry = { x_mm: 0, y_mm: 0, width_mm: Number( p.width_mm ), height_mm: Number( p.height_mm ) }; }
			else { geometry = geometryAtPoint( Number( palette.width_mm || 80 ), Number( palette.height_mm || 15 ), point ); }
			var element = { id: id, type: palette.type, x_mm: geometry.x_mm, y_mm: geometry.y_mm, width_mm: geometry.width_mm, height_mm: geometry.height_mm, z: elements().length, rotation_deg: 0, opacity: 1 };
			for ( var key in defaults ) {
				if ( ! Object.prototype.hasOwnProperty.call( defaults, key ) || 'full_page' === key || 'text_i18n' === key || 'items_i18n' === key || 'style_preset' === key || 'rich_preset' === key || 'table_preset' === key ) { continue; }
				element[ key ] = clone( defaults[ key ] );
			}
			if ( defaults.text_i18n ) { element.text = t( defaults.text_i18n, 'Text' ); }
			if ( Array.isArray( defaults.items_i18n ) ) { element.items = defaults.items_i18n.map( function ( key ) { return t( key, key ); } ); }
			if ( 'certificate_line' === defaults.rich_preset ) { element.runs = materializeRichRunDefaults( [ { kind: 'text', text_i18n: 'richTextPrefix' }, { kind: 'token', token: 'recipient.name' } ] ); }
			if ( 'dynamic_field' === defaults.rich_preset ) { element.runs = materializeRichRunDefaults( [ { kind: 'token', token: 'recipient.name' } ] ); }
			if ( defaults.table_preset ) { materializeTableDefaults( element, defaults.table_preset ); }
			if ( defaults.style_preset ) {
				element.style = defaultTextStyle();
				if ( 'list' === defaults.style_preset ) { element.style.align = 'start'; element.style.vertical_align = 'top'; element.style.overflow = 'wrap'; }
				if ( 'heading' === defaults.style_preset ) { element.style.font_size_pt = 30; element.style.font_weight = 700; element.style.overflow = 'shrink'; }
				if ( 'field' === defaults.style_preset ) { element.style.font_size_pt = 14; element.style.font_weight = 500; element.style.align = 'start'; }
			}
			if ( palette.requires_media ) {
				if ( 'signature' === palette.type && schemaVersion >= 4 && ! assetReference ) { element.source = 'signer.signature'; }
				else { element.asset = assetReference || ''; }
			}
			return element;
		}
		function appendElement( kind, assetReference, point ) {
			if ( elements().length >= 500 ) { showError( t( 'elementLimit', 'This Template already contains the maximum 500 elements.' ) ); return; }
			var element = buildNewElement( kind, assetReference, point ); if ( ! element ) { return; }
			var before = snapshot();
			if ( point && ! ( paletteDefinition( kind ) || {} ).full_page ) { var snapped = snapDragged( clampDragged( clone( element ) ) ); element.x_mm = snapped.x_mm; element.y_mm = snapped.y_mm; activeGuides = { x: null, y: null }; activeSpacingGuides = []; }
			elements().push( element ); normalizeZOrder(); selectOnly( element.id ); pushHistory( before ); closeInsertPanel(); render(); announce( point ? t( 'elementDropped', 'Element placed on the canvas.' ) : t( 'elementAdded', 'Element added.' ) );
		}
		function removeSelected() {
			var ids = selectionIds(); if ( ! ids.length ) { return; }
			if ( selectionHasLocked() ) { showError( t( 'deleteLockedElement', 'Unlock the selected element or selection before removing it.' ) ); return; }
			var before = snapshot(); layout.elements = elements().filter( function ( item ) { return ids.indexOf( item.id ) === -1; } ); designerState.locked_ids = designerState.locked_ids.filter( function ( id ) { return ids.indexOf( id ) === -1; } ); designerState.hidden_ids = designerState.hidden_ids.filter( function ( id ) { return ids.indexOf( id ) === -1; } ); ids.forEach( function ( id ) { delete designerState.layer_labels[ id ]; } ); selectOnly( '' ); normalizeZOrder(); pushHistory( before ); render(); announce( ids.length > 1 ? t( 'elementsRemoved', 'Selected elements removed.' ) : t( 'elementRemoved', 'Element removed.' ) );
		}
		function duplicateSelected() {
			var element = selected(); if ( ! element ) { return; }
			if ( isLocked( element ) ) { showError( t( 'duplicateLockedElement', 'Unlock this element before duplicating it.' ) ); return; }
			if ( elements().length >= 500 ) { showError( t( 'elementLimit', 'This Template already contains the maximum 500 elements.' ) ); return; }
			var id = secureUuidV4(); if ( ! id ) { showError( t( 'uuidUnavailable', 'This browser cannot safely add a new design element. Update the browser and try again.' ) ); return; }
			var before = snapshot(); var copy = clone( element ); copy.id = id; copy.z = elements().length; copy.x_mm = round( copy.x_mm + 4, 3 ); copy.y_mm = round( copy.y_mm + 4, 3 ); copy = clampDragged( copy );
			elements().push( copy ); normalizeZOrder(); selectOnly( copy.id ); pushHistory( before ); render(); announce( t( 'elementDuplicated', 'Element duplicated.' ) );
		}
		function copySelectionToDesignerClipboard( cut ) {
			var selection = selectedElements(); if ( ! selection.length ) { return; }
			if ( cut && selectionHasLocked() ) { showError( t( 'deleteLockedElement', 'Unlock the selected element or selection before removing it.' ) ); return; }
			designerClipboard = selection.map( function ( element ) { return clone( element ); } ); designerClipboardLabels = {};
			for ( var i = 0; i < selection.length; i++ ) { var label = layerLabel( selection[ i ].id ); if ( label ) { designerClipboardLabels[ selection[ i ].id ] = label; } }
			if ( cut ) { removeSelected(); try { workspace.focus( { preventScroll: true } ); } catch ( ignore ) { workspace.focus(); } announce( t( 'cutToClipboard', 'Selection cut to Designer clipboard.' ) ); }
			else { syncProductivityControls(); announce( t( 'copied', 'Selection copied.' ) ); }
		}
		function pasteDesignerClipboard() {
			if ( ! designerClipboard.length ) { return; }
			if ( elements().length + designerClipboard.length > 500 ) { showError( t( 'elementLimit', 'This Template already contains the maximum 500 elements.' ) ); return; }
			var before = snapshot(); var newIds = []; var labelUpdates = {}; var baseZ = elements().length;
			for ( var i = 0; i < designerClipboard.length; i++ ) {
				var source = designerClipboard[ i ]; var id = secureUuidV4(); if ( ! id ) { restoreSnapshot( before ); showError( t( 'uuidUnavailable', 'This browser cannot safely add a new design element. Update the browser and try again.' ) ); return; }
				var copy = clone( source ); var sourceId = copy.id; copy.id = id; copy.z = baseZ + i; copy.x_mm = round( Number( copy.x_mm || 0 ) + 4, 3 ); copy.y_mm = round( Number( copy.y_mm || 0 ) + 4, 3 ); copy = clampDragged( copy ); elements().push( copy ); newIds.push( id );
				if ( designerClipboardLabels[ sourceId ] ) { labelUpdates[ id ] = designerClipboardLabels[ sourceId ]; }
			}
			Object.keys( labelUpdates ).forEach( function ( id ) { designerState.layer_labels[ id ] = labelUpdates[ id ]; } ); normalizeZOrder(); selectedIds = newIds.slice(); selectedId = newIds.length ? newIds[ newIds.length - 1 ] : ''; pushHistory( before ); render(); focusSelectedCanvasElement(); announce( t( 'pasted', 'Designer clipboard pasted.' ) );
		}
		function setLayerLabel( id, raw ) {
			if ( ! id ) { return; } var label = normalizeLayerLabel( raw ); var before = snapshot();
			if ( label ) { designerState.layer_labels[ id ] = label; } else { delete designerState.layer_labels[ id ]; }
			renamingLayerId = ''; pushHistory( before ); render(); announce( t( 'layerRenamed', 'Layer name updated for this editor.' ) );
		}
		function toggleEditorVisibility( id ) {
			if ( ! id ) { return; } var before = snapshot(); var at = designerState.hidden_ids.indexOf( id );
			if ( at === -1 ) { designerState.hidden_ids.push( id ); designerState.hidden_ids.sort(); } else { designerState.hidden_ids.splice( at, 1 ); }
			pushHistory( before ); render(); announce( at === -1 ? t( 'layerHiddenInEditor', 'Layer hidden in this editor only. Certificate output is unchanged.' ) : t( 'layerShownInEditor', 'Layer shown in the editor.' ) );
		}
		function mediaFrame( callback ) {
			if ( ! window.wp || ! window.wp.media ) { showError( t( 'assetRequired', 'Choose a local WordPress Media Library image before adding this element.' ) ); return; }
			var frame = window.wp.media( { title: t( 'chooseMedia', 'Choose local media' ), button: { text: t( 'useMedia', 'Use this media' ) }, library: { type: 'image' }, multiple: false } );
			frame.on( 'select', function () {
				var chosen = frame.state().get( 'selection' ).first(); var data = chosen && chosen.toJSON ? chosen.toJSON() : null;
				if ( ! data || Number( data.id ) < 1 ) { return; }
				var mime = String( data.mime || '' ).toLowerCase();
				if ( [ 'image/png', 'image/jpeg' ].indexOf( mime ) === -1 ) { showError( t( 'unsupportedMediaType', 'Certloom Designer currently supports local PNG and JPEG Media Library images only.' ) ); return; }
				callback( 'attachment:' + Number( data.id ) );
			} ); frame.open();
		}
		function addRequested( kind, point ) {
			var palette = paletteDefinition( kind );
			if ( ! palette ) { showError( t( 'elementRegistryUnavailable', 'The Designer could not load its editing controls. Reload the page before editing this design.' ) ); return; }
			if ( schemaVersion < Number( palette.min_schema_version || 1 ) ) { showError( t( 'elementRequiresSchemaUpgrade', 'This element needs a newer design format. Save or upgrade the design before adding it.' ) ); return; }
			if ( palette.requires_media && ! ( 'signature' === palette.type && schemaVersion >= 4 ) ) { mediaFrame( function ( reference ) { appendElement( kind, reference, point ); } ); return; }
			appendElement( kind, '', point );
		}
		function applyContentField( input ) {
			var element = selected(); if ( ! element || isLocked( element ) ) { setInspector(); return; }
			var key = input.getAttribute( 'data-certloom-designer-content-field' ); var raw = String( input.value || '' ); var proposed = clone( element );
			if ( 'text' === key ) { proposed.text = raw; }
			else if ( 'token' === key ) { raw = raw.trim(); if ( ! /^[a-z][a-z0-9_.:-]{0,127}$/.test( raw ) ) { showError( t( 'invalidMergeToken', 'This dynamic field is not valid. Choose another field or check its technical field name.' ) ); setInspector(); return; } proposed.token = raw; }
			else if ( 'asset' === key ) { raw = raw.trim(); if ( ! /^attachment:[1-9][0-9]*$/.test( raw ) ) { showError( t( 'invalidAssetReference', 'Choose an image from the WordPress Media Library.' ) ); setInspector(); return; } proposed.asset = raw; }
			else if ( 'fit' === key ) { if ( [ 'contain', 'cover', 'fill' ].indexOf( raw ) === -1 ) { setInspector(); return; } proposed.fit = raw; }
			else if ( 'shape' === key ) { if ( [ 'rectangle', 'ellipse' ].indexOf( raw ) === -1 ) { setInspector(); return; } proposed.shape = raw; }
			else if ( 'error_correction' === key ) { raw = raw.toUpperCase(); if ( [ 'L', 'M', 'Q', 'H' ].indexOf( raw ) === -1 ) { setInspector(); return; } proposed.error_correction = raw; }
			else if ( 'quiet_zone_modules' === key ) { var quiet = Number( raw ); var minQuiet = 'barcode' === element.type ? 10 : 4; var maxQuiet = 'barcode' === element.type ? 50 : 16; if ( ! Number.isInteger( quiet ) || quiet < minQuiet || quiet > maxQuiet ) { setInspector(); return; } proposed.quiet_zone_modules = quiet; }
			else if ( 'items' === key ) { var listItems = raw.split( /\r?\n/ ).map( function ( item ) { return item.trim(); } ).filter( function ( item ) { return '' !== item; } ); var bytes = 0; for ( var li = 0; li < listItems.length; li++ ) { bytes += unescape( encodeURIComponent( listItems[ li ] ) ).length; } if ( listItems.length < 1 || listItems.length > 100 || bytes > 20000 ) { showError( t( 'invalidListItems', 'Lists need 1–100 non-empty lines and must stay within the supported text limit.' ) ); setInspector(); return; } proposed.items = listItems; }
			else if ( 'list_style' === key ) { if ( [ 'bullet', 'decimal' ].indexOf( raw ) === -1 ) { setInspector(); return; } proposed.list_style = raw; }
			else { return; }
			var before = snapshot(); if ( updateElement( element.id, proposed, false ) ) { pushHistory( before ); render(); announce( t( 'contentUpdated', 'Element content updated.' ) ); }
		}


		function directTextByteLength( value ) { try { return new Blob( [ String( value || '' ) ] ).size; } catch ( error ) { return String( value || '' ).length; } }
		function closeDirectTextEditor( save ) {
			if ( ! directTextEditor ) { return; }
			var editor = directTextEditor; var id = editor.getAttribute( 'data-certloom-designer-direct-text' ); var before = directTextBefore; var element = selected();
			directTextEditor = null; directTextBefore = null;
			if ( save && element && element.id === id && 'text' === element.type && ! isLocked( element ) ) {
				var value = String( editor.value || '' );
				if ( directTextByteLength( value ) > 20000 || value.indexOf( '\0' ) !== -1 ) {
					directTextEditor = editor; directTextBefore = before; showError( t( 'directTextInvalid', 'Direct text must stay within the canonical 20,000-byte text limit.' ) ); window.setTimeout( function () { editor.focus(); }, 0 ); return;
				}
				var proposed = clone( element ); proposed.text = value;
				if ( stable( proposed ) !== stable( element ) && updateElement( element.id, proposed, false ) ) { if ( before ) { pushHistory( before ); } announce( t( 'directTextEditSaved', 'Text updated on the canvas.' ) ); return; }
			}
			if ( editor.parentNode ) { editor.parentNode.removeChild( editor ); }
			render(); announce( save ? t( 'directTextEditSaved', 'Text updated on the canvas.' ) : t( 'directTextEditCancelled', 'Direct text edit cancelled.' ) );
		}
		function startDirectTextEdit( id ) {
			var element = null; var list = elements(); for ( var i = 0; i < list.length; i++ ) { if ( list[ i ].id === id ) { element = list[ i ]; break; } }
			if ( ! element || 'text' !== element.type || isLocked( element ) ) { return false; }
			if ( directTextEditor ) { closeDirectTextEditor( true ); if ( directTextEditor ) { return false; } }
			selectOnly( id ); render(); element = selected(); if ( ! element ) { return false; }
			var editor = document.createElement( 'textarea' ); var textStyle = element.style || {};
			editor.className = 'certloom-template-designer-direct-text'; editor.setAttribute( 'data-certloom-designer-direct-text', id ); editor.setAttribute( 'aria-label', t( 'directTextEditLabel', 'Edit certificate text directly on the canvas' ) ); editor.maxLength = 20000; editor.value = String( element.text || '' );
			editor.style.left = px( element.x_mm, zoom ) + 'px'; editor.style.top = px( element.y_mm, zoom ) + 'px'; editor.style.width = px( element.width_mm, zoom ) + 'px'; editor.style.height = px( element.height_mm, zoom ) + 'px'; editor.style.transform = 'rotate(' + Number( element.rotation_deg || 0 ) + 'deg)'; editor.style.color = textStyle.color || '#000'; editor.style.fontFamily = fontCss( textStyle.font_family || 'sans-serif' ); editor.style.fontWeight = String( textStyle.font_weight || 400 ); editor.style.fontStyle = textStyle.italic ? 'italic' : 'normal'; editor.style.lineHeight = String( textStyle.line_height || 1.2 ); editor.style.fontSize = px( Number( textStyle.font_size_pt || 18 ) * 25.4 / 72, zoom ) + 'px'; editor.style.letterSpacing = px( Number( textStyle.letter_spacing_pt || 0 ) * 25.4 / 72, zoom ) + 'px'; editor.style.textAlign = textStyle.align || 'center'; if ( textStyle.direction && 'auto' !== textStyle.direction ) { editor.style.direction = textStyle.direction; } else { editor.setAttribute( 'dir', 'auto' ); }
			directTextBefore = snapshot(); directTextEditor = editor; pageEl.appendChild( editor ); var source = pageEl.querySelector( '[data-certloom-designer-element="' + id + '"]' ); if ( source ) { source.classList.add( 'is-direct-editing' ); }
			editor.addEventListener( 'keydown', function ( event ) { if ( 'Escape' === event.key ) { event.preventDefault(); closeDirectTextEditor( false ); return; } if ( 'Enter' === event.key && ( event.ctrlKey || event.metaKey ) ) { event.preventDefault(); closeDirectTextEditor( true ); } } );
			editor.addEventListener( 'blur', function () { window.setTimeout( function () { if ( directTextEditor === editor ) { closeDirectTextEditor( true ); } }, 0 ); } );
			window.setTimeout( function () { editor.focus(); editor.setSelectionRange( editor.value.length, editor.value.length ); }, 0 ); announce( t( 'directTextEditStarted', 'Direct text editing started. Press Control or Command plus Enter to apply, or Escape to cancel.' ) ); return true;
		}

		function canonicalColor( raw ) {
			raw = String( raw || '' ).trim().toUpperCase();
			return /^#[0-9A-F]{6}(?:[0-9A-F]{2})?$/.test( raw ) ? raw : '';
		}
		function styleValueForElement( element, key ) {
			if ( 'opacity' === key ) { return null == element.opacity ? 1 : element.opacity; }
			var definition = registryType( element.type ); if ( definition && 'text' === definition.style_group ) { return element.style && Object.prototype.hasOwnProperty.call( element.style, key ) ? element.style[ key ] : defaultTextStyle()[ key ]; }
			if ( definition && 'table' === definition.style_group ) { return element.style && Object.prototype.hasOwnProperty.call( element.style, key ) ? element.style[ key ] : defaultTableStyle()[ key ]; }
			return Object.prototype.hasOwnProperty.call( element, key ) ? element[ key ] : '';
		}
		function applyStyleField( input ) {
			var element = selected(); if ( ! element || isLocked( element ) ) { setInspector(); return; }
			var key = input.getAttribute( 'data-certloom-designer-style-field' ); if ( ! key ) { setInspector(); return; }
			var raw = 'checkbox' === input.type ? !! input.checked : String( input.value || '' ).trim(); var proposed = clone( element ); var value;
			if ( 'opacity' === key ) { value = Number( raw ); if ( ! isFinite( value ) || value < 0 || value > 1 ) { showError( t( 'invalidStyleValue', 'That styling value is outside the canonical certloom-layout range. The previous value was kept.' ) ); setInspector(); return; } proposed.opacity = round( value, 3 ); }
			else if ( registryType( element.type ) && 'text' === registryType( element.type ).style_group ) {
				proposed.style = Object.assign( defaultTextStyle(), proposed.style || {} );
				if ( 'font_family' === key ) { value = String( raw || '' ).toLowerCase(); if ( ! fontKeyAllowed( value ) ) { showError( t( 'invalidFontFamily', 'Choose a font from the controlled Certloom font list.' ) ); setInspector(); return; } proposed.style[ key ] = value; }
				else if ( 'font_size_pt' === key ) { value = Number( raw ); if ( ! isFinite( value ) || value < 1 || value > 300 ) { showError( t( 'invalidStyleValue', 'Invalid style value.' ) ); setInspector(); return; } proposed.style[ key ] = round( value, 3 ); }
				else if ( 'font_weight' === key ) { value = Number( raw ); if ( ! Number.isInteger( value ) || value < 100 || value > 900 || 0 !== value % 100 ) { showError( t( 'invalidStyleValue', 'Invalid style value.' ) ); setInspector(); return; } proposed.style[ key ] = value; }
				else if ( 'italic' === key ) { proposed.style[ key ] = !! raw; }
				else if ( 'color' === key ) { value = canonicalColor( raw ); if ( ! value ) { showError( t( 'invalidColor', 'Colors must use #RRGGBB or #RRGGBBAA.' ) ); setInspector(); return; } proposed.style[ key ] = value; }
				else if ( 'align' === key ) { if ( [ 'left', 'center', 'right', 'start', 'end' ].indexOf( raw ) === -1 ) { setInspector(); return; } proposed.style[ key ] = raw; }
				else if ( 'vertical_align' === key ) { if ( [ 'top', 'middle', 'bottom' ].indexOf( raw ) === -1 ) { setInspector(); return; } proposed.style[ key ] = raw; }
				else if ( 'direction' === key ) { if ( [ 'auto', 'ltr', 'rtl' ].indexOf( raw ) === -1 ) { setInspector(); return; } proposed.style[ key ] = raw; }
				else if ( 'line_height' === key ) { value = Number( raw ); if ( ! isFinite( value ) || value < 0.5 || value > 5 ) { showError( t( 'invalidStyleValue', 'Invalid style value.' ) ); setInspector(); return; } proposed.style[ key ] = round( value, 3 ); }
				else if ( 'letter_spacing_pt' === key ) { value = Number( raw ); if ( ! isFinite( value ) || value < -20 || value > 50 ) { showError( t( 'invalidStyleValue', 'Invalid style value.' ) ); setInspector(); return; } proposed.style[ key ] = round( value, 3 ); }
				else if ( 'overflow' === key ) { if ( [ 'wrap', 'shrink', 'clip' ].indexOf( raw ) === -1 ) { setInspector(); return; } proposed.style[ key ] = raw; }
				else { return; }
			} else if ( registryType( element.type ) && 'table' === registryType( element.type ).style_group ) {
				proposed.style = Object.assign( defaultTableStyle(), proposed.style || {} );
				if ( 'font_family' === key ) { value = String( raw || '' ).toLowerCase(); if ( ! fontKeyAllowed( value ) ) { showError( t( 'invalidFontFamily', 'Choose a font from the controlled Certloom font list.' ) ); setInspector(); return; } proposed.style[ key ] = value; }
				else if ( 'font_size_pt' === key ) { value = Number( raw ); if ( ! isFinite( value ) || value < 1 || value > 300 ) { showError( t( 'invalidStyleValue', 'Invalid style value.' ) ); setInspector(); return; } proposed.style[ key ] = round( value, 3 ); }
				else if ( [ 'color', 'header_fill', 'header_color', 'row_fill', 'alternate_row_fill', 'border_color' ].indexOf( key ) !== -1 ) { value = canonicalColor( raw ); if ( ! value ) { showError( t( 'invalidColor', 'Colors must use #RRGGBB or #RRGGBBAA.' ) ); setInspector(); return; } proposed.style[ key ] = value; }
				else if ( 'header_font_weight' === key ) { value = Number( raw ); if ( ! Number.isInteger( value ) || value < 100 || value > 900 || value % 100 ) { setInspector(); return; } proposed.style[ key ] = value; }
				else if ( 'border_width_mm' === key ) { value = Number( raw ); if ( ! isFinite( value ) || value < 0 || value > 5 ) { setInspector(); return; } proposed.style[ key ] = round( value, 3 ); }
				else if ( 'cell_padding_mm' === key ) { value = Number( raw ); if ( ! isFinite( value ) || value < 0 || value > 10 ) { setInspector(); return; } proposed.style[ key ] = round( value, 3 ); }
				else { return; }
			} else if ( registryType( element.type ) && [ 'shape', 'line' ].indexOf( registryType( element.type ).style_group ) !== -1 ) {
				if ( 'fill' === key && 'shape' === element.type ) { value = canonicalColor( raw ); if ( ! value ) { showError( t( 'invalidColor', 'Colors must use #RRGGBB or #RRGGBBAA.' ) ); setInspector(); return; } proposed.fill = value; }
				else if ( 'stroke' === key ) { value = canonicalColor( raw ); if ( ! value ) { showError( t( 'invalidColor', 'Colors must use #RRGGBB or #RRGGBBAA.' ) ); setInspector(); return; } proposed.stroke = value; }
				else if ( 'stroke_width_mm' === key ) { value = Number( raw ); var minStroke = 'line' === element.type ? 0.001 : 0; if ( ! isFinite( value ) || value < minStroke || value > 20 ) { showError( t( 'invalidStyleValue', 'Invalid style value.' ) ); setInspector(); return; } proposed.stroke_width_mm = round( value, 3 ); }
				else { return; }
			} else { return; }
			var before = snapshot(); if ( updateElement( element.id, proposed, false ) ) { pushHistory( before ); render(); announce( t( 'styleUpdated', 'Element styling updated.' ) ); }
		}

		function rotateHandlePlacement( element ) {
			var bounds = rotatedBounds( element ); var p = page(); if ( ! bounds || ! p ) { return 'top'; }
			var reach = Math.max( 4, 32 / Math.max( 0.01, BASE_PX_PER_MM * zoom ) );
			function occupied( zone ) {
				var list = elements();
				for ( var i = 0; i < list.length; i++ ) { if ( list[ i ].id === element.id || isHiddenId( list[ i ].id ) ) { continue; } if ( boundsIntersect( zone, rotatedBounds( list[ i ] ) ) ) { return true; } }
				return false;
			}
			var centerX = ( bounds.minX + bounds.maxX ) / 2; var centerY = ( bounds.minY + bounds.maxY ) / 2;
			var topZone = { minX: centerX - reach, maxX: centerX + reach, minY: bounds.minY - reach, maxY: bounds.minY };
			if ( topZone.minY >= 0 && ! occupied( topZone ) ) { return 'top'; }
			var rightZone = { minX: bounds.maxX, maxX: bounds.maxX + reach, minY: centerY - reach, maxY: centerY + reach };
			if ( rightZone.maxX <= Number( p.width_mm ) && ! occupied( rightZone ) ) { return 'right'; }
			var leftZone = { minX: bounds.minX - reach, maxX: bounds.minX, minY: centerY - reach, maxY: centerY + reach };
			if ( leftZone.minX >= 0 && ! occupied( leftZone ) ) { return 'left'; }
			return 'top';
		}
		function addHandles( node, element ) {
			if ( isLocked( element ) ) { return; }
			var handles = [ 'nw', 'ne', 'sw', 'se' ];
			for ( var i = 0; i < handles.length; i++ ) {
				var handle = document.createElement( 'span' );
				handle.className = 'certloom-template-designer-resize certloom-template-designer-resize-' + handles[ i ];
				handle.setAttribute( 'data-certloom-designer-resize', handles[ i ] );
				handle.setAttribute( 'aria-hidden', 'true' ); node.appendChild( handle );
			}
			var rotate = document.createElement( 'span' );
			var rotatePlacement = rotateHandlePlacement( element );
			rotate.className = 'certloom-template-designer-rotate is-' + rotatePlacement; rotate.setAttribute( 'data-certloom-designer-rotate', '1' ); rotate.setAttribute( 'data-certloom-designer-rotate-placement', rotatePlacement ); rotate.setAttribute( 'aria-hidden', 'true' ); node.appendChild( rotate );
		}
		function styleContent( node, element ) {
			var preview = previewKind( element );
			var content = document.createElement( 'span' ); content.className = 'certloom-template-designer-content';
			if ( 'shape' === preview ) {
				node.classList.add( 'is-shape' );
				node.style.background = element.fill || 'transparent'; node.style.borderStyle = 'solid'; node.style.borderColor = element.stroke || '#000'; node.style.borderWidth = Math.max( 0, px( Number( element.stroke_width_mm || 0 ), zoom ) ) + 'px';
				if ( 'ellipse' === element.shape ) { node.style.borderRadius = '50%'; }
				content.textContent = '';
			} else if ( 'line' === preview ) {
				node.classList.add( 'is-line' ); var svg = makeSvgLine(); var line = svg.querySelector( 'line' ); line.setAttribute( 'stroke', element.stroke || '#000' ); line.setAttribute( 'stroke-width', Math.max( 1, px( Number( element.stroke_width_mm || 0.3 ), zoom ) ) ); node.appendChild( svg ); content.textContent = '';
			} else if ( 'qr' === preview ) {
				node.classList.add( 'is-qr' ); content.textContent = t( 'qr', 'QR' );
			} else if ( 'barcode' === preview ) {
				node.classList.add( 'is-barcode' ); content.textContent = t( 'barcode', 'Barcode' ) + ' · ' + escapeText( element.source || 'credential.display_number' );
			} else if ( 'asset' === preview ) {
				node.classList.add( 'is-asset' ); content.textContent = elementTitle( element );
			} else if ( 'table' === preview || 'data_table' === preview ) {
				node.classList.add( 'is-table' ); content.classList.add( 'is-table-preview' ); content.textContent = ''; var table = document.createElement( 'table' ); var columns = Array.isArray( element.columns ) ? element.columns : []; var rows = tableSourceRows( element ); var ts = Object.assign( defaultTableStyle(), element.style || {} ); table.style.fontFamily = fontCss( ts.font_family ); table.style.fontSize = px( Number( ts.font_size_pt || 10 ) * 25.4 / 72, zoom ) + 'px'; table.style.color = ts.color || '#111827'; table.style.borderColor = ts.border_color || '#D1D5DB'; table.style.borderCollapse = 'collapse'; table.style.width = '100%'; table.style.height = '100%'; table.style.tableLayout = 'fixed';
				if ( element.show_header ) { var thead = document.createElement( 'thead' ), trh = document.createElement( 'tr' ); for ( var hc = 0; hc < columns.length; hc++ ) { var th = document.createElement( 'th' ); th.textContent = String( columns[ hc ].label || '' ); th.style.width = Number( columns[ hc ].width_pct || 0 ) + '%'; th.style.textAlign = String( columns[ hc ].align || 'left' ); th.style.background = ts.header_fill; th.style.color = ts.header_color; th.style.fontWeight = String( ts.header_font_weight || 600 ); th.style.border = Math.max( 1, px( Number( ts.border_width_mm || 0.2 ), zoom ) ) + 'px solid ' + ts.border_color; th.style.padding = Math.max( 0, px( Number( ts.cell_padding_mm || 1.5 ), zoom ) ) + 'px'; trh.appendChild( th ); } thead.appendChild( trh ); table.appendChild( thead ); }
				var tbody = document.createElement( 'tbody' ); for ( var tri = 0; tri < rows.length; tri++ ) { var tr = document.createElement( 'tr' ); for ( var tci = 0; tci < columns.length; tci++ ) { var tc = columns[ tci ], td = document.createElement( 'td' ); var key = 'data_table' === element.type ? String( tc.field || tc.key || '' ) : String( tc.key || '' ); td.textContent = String( rows[ tri ] && null != rows[ tri ][ key ] ? rows[ tri ][ key ] : '' ); td.style.textAlign = String( tc.align || 'left' ); td.style.background = 0 === tri % 2 ? ts.row_fill : ts.alternate_row_fill; td.style.border = Math.max( 1, px( Number( ts.border_width_mm || 0.2 ), zoom ) ) + 'px solid ' + ts.border_color; td.style.padding = Math.max( 0, px( Number( ts.cell_padding_mm || 1.5 ), zoom ) ) + 'px'; tr.appendChild( td ); } tbody.appendChild( tr ); } table.appendChild( tbody ); content.appendChild( table );
			} else if ( 'rich_text' === preview ) {
				node.classList.add( 'is-text', 'is-rich-text' ); var richBaseStyle = element.style || {}; var richVertical = richBaseStyle.vertical_align || 'middle';
				content.style.justifyContent = 'top' === richVertical ? 'flex-start' : ( 'bottom' === richVertical ? 'flex-end' : 'center' ); content.style.textAlign = richBaseStyle.align || 'center';
				if ( richBaseStyle.direction && 'auto' !== richBaseStyle.direction ) { content.style.direction = richBaseStyle.direction; content.removeAttribute( 'dir' ); } else { content.style.removeProperty( 'direction' ); content.setAttribute( 'dir', 'auto' ); }
				content.style.color = richBaseStyle.color || '#000'; content.style.fontFamily = fontCss( richBaseStyle.font_family || 'sans-serif' ); content.style.fontWeight = String( richBaseStyle.font_weight || 400 ); content.style.fontStyle = richBaseStyle.italic ? 'italic' : 'normal'; content.style.lineHeight = String( richBaseStyle.line_height || 1.2 ); content.style.fontSize = px( Number( richBaseStyle.font_size_pt || 18 ) * 25.4 / 72, zoom ) + 'px'; content.style.letterSpacing = px( Number( richBaseStyle.letter_spacing_pt || 0 ) * 25.4 / 72, zoom ) + 'px'; content.style.whiteSpace = 'clip' === richBaseStyle.overflow ? 'pre' : 'pre-wrap'; content.style.overflow = 'hidden';
				var richRuns = Array.isArray( element.runs ) ? element.runs : []; for ( var richIndex = 0; richIndex < richRuns.length; richIndex++ ) { var richRun = richRuns[ richIndex ]; var runSpan = document.createElement( 'span' ); runSpan.className = 'certloom-template-designer-rich-preview-run'; if ( 'token' === richRun.kind ) { runSpan.classList.add( 'is-token' ); runSpan.setAttribute( 'data-certloom-designer-rich-token', String( richRun.token || '' ) ); } runSpan.textContent = richRunText( richRun ); var inline = richRun.style || {}; if ( inline.font_family ) { runSpan.style.fontFamily = fontCss( inline.font_family ); } if ( null != inline.font_size_pt ) { runSpan.style.fontSize = px( Number( inline.font_size_pt ) * 25.4 / 72, zoom ) + 'px'; } if ( null != inline.font_weight ) { runSpan.style.fontWeight = String( inline.font_weight ); } if ( Object.prototype.hasOwnProperty.call( inline, 'italic' ) ) { runSpan.style.fontStyle = inline.italic ? 'italic' : 'normal'; } if ( inline.color ) { runSpan.style.color = inline.color; } if ( null != inline.letter_spacing_pt ) { runSpan.style.letterSpacing = px( Number( inline.letter_spacing_pt ) * 25.4 / 72, zoom ) + 'px'; } content.appendChild( runSpan ); }
			} else if ( 'list' === preview ) {
				node.classList.add( 'is-text', 'is-list' ); var listLines = []; var rawItems = Array.isArray( element.items ) ? element.items : [];
				for ( var listIndex = 0; listIndex < rawItems.length; listIndex++ ) { listLines.push( ( 'decimal' === element.list_style ? String( listIndex + 1 ) + '. ' : '• ' ) + rawItems[ listIndex ] ); }
				content.textContent = listLines.join( '\n' );
				var listTextStyle = element.style || {}; var listVertical = listTextStyle.vertical_align || 'top'; content.style.justifyContent = 'top' === listVertical ? 'flex-start' : ( 'bottom' === listVertical ? 'flex-end' : 'center' ); content.style.textAlign = listTextStyle.align || 'start';
				if ( listTextStyle.direction && 'auto' !== listTextStyle.direction ) { content.style.direction = listTextStyle.direction; content.removeAttribute( 'dir' ); } else { content.style.removeProperty( 'direction' ); content.setAttribute( 'dir', 'auto' ); }
				content.style.color = listTextStyle.color || '#000'; content.style.fontFamily = fontCss( listTextStyle.font_family || 'sans-serif' ); content.style.fontWeight = String( listTextStyle.font_weight || 400 ); content.style.fontStyle = listTextStyle.italic ? 'italic' : 'normal'; content.style.lineHeight = String( listTextStyle.line_height || 1.2 ); content.style.fontSize = px( Number( listTextStyle.font_size_pt || 18 ) * 25.4 / 72, zoom ) + 'px'; content.style.letterSpacing = px( Number( listTextStyle.letter_spacing_pt || 0 ) * 25.4 / 72, zoom ) + 'px'; content.style.whiteSpace = 'pre-wrap'; content.style.overflow = 'hidden';
			} else {
				node.classList.add( 'is-text' ); content.textContent = elementTitle( element );
				var textStyle = element.style || {}; var vertical = textStyle.vertical_align || 'middle';
				content.style.justifyContent = 'top' === vertical ? 'flex-start' : ( 'bottom' === vertical ? 'flex-end' : 'center' );
				content.style.textAlign = textStyle.align || 'center';
				if ( textStyle.direction && 'auto' !== textStyle.direction ) { content.style.direction = textStyle.direction; content.removeAttribute( 'dir' ); } else { content.style.removeProperty( 'direction' ); content.setAttribute( 'dir', 'auto' ); }
				content.style.color = textStyle.color || '#000'; content.style.fontFamily = fontCss( textStyle.font_family || 'sans-serif' ); content.style.fontWeight = String( textStyle.font_weight || 400 ); content.style.fontStyle = textStyle.italic ? 'italic' : 'normal'; content.style.lineHeight = String( textStyle.line_height || 1.2 );
				content.style.fontSize = px( Number( textStyle.font_size_pt || 18 ) * 25.4 / 72, zoom ) + 'px'; content.style.letterSpacing = px( Number( textStyle.letter_spacing_pt || 0 ) * 25.4 / 72, zoom ) + 'px';
				content.style.whiteSpace = 'clip' === textStyle.overflow ? 'pre' : 'pre-wrap'; content.style.overflow = 'hidden';
			}
			node.appendChild( content );
		}
		function renderGuides() {
			if ( null != activeGuides.x ) { var gx = document.createElement( 'span' ); gx.className = 'certloom-template-designer-guide is-vertical'; gx.style.left = px( activeGuides.x, zoom ) + 'px'; gx.setAttribute( 'aria-hidden', 'true' ); pageEl.appendChild( gx ); }
			if ( null != activeGuides.y ) { var gy = document.createElement( 'span' ); gy.className = 'certloom-template-designer-guide is-horizontal'; gy.style.top = px( activeGuides.y, zoom ) + 'px'; gy.setAttribute( 'aria-hidden', 'true' ); pageEl.appendChild( gy ); }
		}
		function renderSafeArea() {
			if ( ! safeAreaVisible ) { return; }
			var margin = safeMarginMm(); if ( margin <= 0 ) { return; }
			var safe = document.createElement( 'span' ); safe.className = 'certloom-template-designer-safe-area'; safe.setAttribute( 'data-certloom-designer-safe-area-overlay', '' ); safe.setAttribute( 'aria-hidden', 'true' );
			safe.style.left = px( margin, zoom ) + 'px'; safe.style.top = px( margin, zoom ) + 'px'; safe.style.right = px( margin, zoom ) + 'px'; safe.style.bottom = px( margin, zoom ) + 'px'; pageEl.appendChild( safe );
		}
		function renderSpacingGuides() {
			for ( var i = 0; i < activeSpacingGuides.length; i++ ) {
				var guide = activeSpacingGuides[ i ]; var line = document.createElement( 'span' ); line.className = 'certloom-template-designer-spacing-guide ' + ( 'x' === guide.axis ? 'is-horizontal-gap' : 'is-vertical-gap' ); line.setAttribute( 'aria-hidden', 'true' );
				if ( 'x' === guide.axis ) { line.style.left = px( guide.start, zoom ) + 'px'; line.style.top = px( guide.cross, zoom ) + 'px'; line.style.width = Math.max( 1, px( guide.end - guide.start, zoom ) ) + 'px'; }
				else { line.style.left = px( guide.cross, zoom ) + 'px'; line.style.top = px( guide.start, zoom ) + 'px'; line.style.height = Math.max( 1, px( guide.end - guide.start, zoom ) ) + 'px'; }
				if ( guide.label ) { var label = document.createElement( 'span' ); label.className = 'certloom-template-designer-spacing-guide-label'; label.textContent = round( guide.gap, 1 ) + ' mm'; line.appendChild( label ); }
				pageEl.appendChild( line );
			}
		}
		function layerActionButton( element, action, label, glyph, disabled ) {
			var icons = { visibility: isHiddenId( element.id ) ? 'dashicons-hidden' : 'dashicons-visibility', lock: isLocked( element ) ? 'dashicons-lock' : 'dashicons-unlock', duplicate: 'dashicons-admin-page', delete: 'dashicons-trash' };
			var button = document.createElement( 'button' ); button.type = 'button'; button.className = 'certloom-studio-layer-icon';
			button.setAttribute( 'data-certloom-designer-layer-row-action', action ); button.setAttribute( 'data-certloom-designer-layer-id', element.id ); button.setAttribute( 'aria-label', label + ' ' + layerDisplayName( element ) ); button.setAttribute( 'title', label );
			button.disabled = !! disabled; var icon = document.createElement( 'span' ); icon.className = 'dashicons ' + ( icons[ action ] || 'dashicons-admin-generic' ); icon.setAttribute( 'aria-hidden', 'true' ); button.appendChild( icon ); return button;
		}
		function renderLayerRenameEditor( row, element ) {
			var editor = document.createElement( 'div' ); editor.className = 'certloom-template-designer-layer-rename'; editor.setAttribute( 'data-certloom-designer-layer-rename-editor', element.id );
			var input = document.createElement( 'input' ); input.type = 'text'; input.maxLength = 80; input.value = layerLabel( element.id ) || layerDisplayName( element ); input.setAttribute( 'data-certloom-designer-layer-rename-input', element.id ); input.setAttribute( 'aria-label', t( 'layerRenameLabel', 'Layer name' ) );
			var save = document.createElement( 'button' ); save.type = 'button'; save.className = 'button button-small'; save.setAttribute( 'data-certloom-designer-layer-rename-save', element.id ); save.textContent = t( 'saveLayerName', 'Save layer name' );
			var cancel = document.createElement( 'button' ); cancel.type = 'button'; cancel.className = 'button button-small'; cancel.setAttribute( 'data-certloom-designer-layer-rename-cancel', element.id ); cancel.textContent = t( 'cancelLayerRename', 'Cancel' );
			editor.appendChild( input ); editor.appendChild( save ); editor.appendChild( cancel ); row.appendChild( editor );
			window.setTimeout( function () { input.focus(); input.select(); }, 0 );
		}
		function ensureSelectedLayerVisible() {
			if ( ! layersContainer || ! selectedId ) { return; }
			var row = layersContainer.querySelector( '[data-certloom-designer-layer-row="' + selectedId + '"]' ); if ( ! row ) { return; }
			var top = row.offsetTop, bottom = top + row.offsetHeight, viewTop = layersContainer.scrollTop, viewBottom = viewTop + layersContainer.clientHeight;
			if ( top < viewTop ) { layersContainer.scrollTop = top; }
			else if ( bottom > viewBottom ) { layersContainer.scrollTop = Math.max( 0, bottom - layersContainer.clientHeight ); }
		}
		function renderLayers() {
			if ( ! layersContainer ) { return; }
			layersContainer.innerHTML = '';
			var list = elements().slice().sort( function ( a, b ) { return Number( b.z || 0 ) - Number( a.z || 0 ); } );
			for ( var i = 0; i < list.length; i++ ) {
				( function ( element ) {
					var locked = isLocked( element ); var hidden = isHiddenId( element.id );
					var row = document.createElement( 'div' ); row.className = 'certloom-template-designer-layer'; row.classList.toggle( 'is-selected', isSelectedId( element.id ) ); row.classList.toggle( 'is-primary-selected', element.id === selectedId ); row.classList.toggle( 'is-locked', locked ); row.classList.toggle( 'is-hidden-in-editor', hidden ); row.setAttribute( 'data-certloom-designer-layer-row', element.id ); row.setAttribute( 'draggable', locked ? 'false' : 'true' ); row.setAttribute( 'title', layerDisplayName( element ) );
					row.appendChild( layerActionButton( element, 'visibility', hidden ? t( 'showInEditor', 'Show in editor' ) : t( 'hideInEditor', 'Hide in editor' ), '', false ) );
					var select = document.createElement( 'button' ); select.type = 'button'; select.className = 'certloom-template-designer-layer-select'; select.setAttribute( 'data-certloom-designer-layer-select', element.id ); select.setAttribute( 'aria-pressed', isSelectedId( element.id ) ? 'true' : 'false' ); select.setAttribute( 'title', layerDisplayName( element ) + ' — ' + t( 'layerSelectHint', 'Select layer. Shift/Ctrl/Cmd-click adds it to the selection.' ) );
					var typeIcon = document.createElement( 'span' ); typeIcon.className = 'dashicons dashicons-' + ( 'image' === element.type || 'logo' === element.type || 'signature' === element.type ? 'format-image' : ( 'qr' === element.type ? 'screenoptions' : ( 'text' === element.type || 'rich_text' === element.type || 'merge' === element.type ? 'editor-textcolor' : 'admin-generic' ) ) ); typeIcon.setAttribute( 'aria-hidden', 'true' ); select.appendChild( typeIcon );
					var name = document.createElement( 'span' ); name.className = 'certloom-studio-layer-name'; name.textContent = compactLayerDisplayName( element ); select.appendChild( name ); row.appendChild( select );
					var actions = document.createElement( 'div' ); actions.className = 'certloom-template-designer-layer-actions'; actions.setAttribute( 'role', 'group' ); actions.setAttribute( 'aria-label', t( 'layerActions', 'Layer actions' ) );
					var lock = layerActionButton( element, 'lock', locked ? t( 'unlock', 'Unlock' ) : t( 'lock', 'Lock' ), '', false ); lock.setAttribute( 'aria-pressed', locked ? 'true' : 'false' ); actions.appendChild( lock );
					actions.appendChild( layerActionButton( element, 'delete', t( 'delete', 'Delete' ), '', locked ) );
					row.appendChild( actions ); layersContainer.appendChild( row );
				} )( list[ i ] );
			}
			ensureSelectedLayerVisible();
		}

		function selectionToolbarButton( action, label, iconClass, disabled ) {
			var button = document.createElement( 'button' ); button.type = 'button'; button.className = 'certloom-studio-selection-tool'; button.setAttribute( 'data-certloom-designer-selection-action', action ); button.setAttribute( 'aria-label', label ); button.setAttribute( 'title', label ); button.disabled = !! disabled;
			var icon = document.createElement( 'span' ); icon.className = 'dashicons ' + iconClass; icon.setAttribute( 'aria-hidden', 'true' ); button.appendChild( icon ); return button;
		}
		function renderSelectionToolbar() {
			var selection = selectedElements(); var element = selected(); if ( selection.length !== 1 || ! element ) { return; }
			var bounds = rotatedBounds( element ); var pageWidthPx = px( Number( page().width_mm ), zoom ); var toolbar = document.createElement( 'div' ); toolbar.className = 'certloom-studio-selection-toolbar'; toolbar.setAttribute( 'data-certloom-designer-selection-toolbar', '' ); toolbar.setAttribute( 'role', 'toolbar' ); toolbar.setAttribute( 'aria-label', t( 'selectedElementTools', 'Selected element tools' ) );
			var drag = document.createElement( 'span' ); drag.className = 'certloom-studio-selection-drag dashicons dashicons-move'; drag.setAttribute( 'aria-hidden', 'true' ); drag.setAttribute( 'title', t( 'dragElementHint', 'Drag the selected element on the canvas' ) ); toolbar.appendChild( drag );
			toolbar.appendChild( selectionToolbarButton( 'duplicate', t( 'duplicate', 'Duplicate' ), 'dashicons-admin-page', isLocked( element ) || elements().length >= 500 ) );
			toolbar.appendChild( selectionToolbarButton( 'lock', isLocked( element ) ? t( 'unlock', 'Unlock' ) : t( 'lock', 'Lock' ), isLocked( element ) ? 'dashicons-lock' : 'dashicons-unlock', false ) );
			toolbar.appendChild( selectionToolbarButton( 'delete', t( 'delete', 'Delete' ), 'dashicons-trash', isLocked( element ) ) );
			toolbar.appendChild( selectionToolbarButton( 'more', t( 'moreActions', 'More actions' ), 'dashicons-ellipsis', false ) );
			var pageHeightPx = px( Number( page().height_mm ), zoom ); var toolbarHeight = 34;
			var x = clamp( px( bounds.centerX, zoom ), 72, Math.max( 72, pageWidthPx - 72 ) ); var below = px( bounds.maxY, zoom ) + 10; var above = px( bounds.minY, zoom ) - toolbarHeight - 30; var y;
			/* Prefer below so the always-on rotate handle above the selection remains unobstructed.
			 * When there is no room below, leave a larger clearance above for the rotate stem/handle. */
			if ( below + toolbarHeight <= pageHeightPx - 8 ) { y = below; } else if ( above >= 8 ) { y = above; } else { y = Math.max( 8, Math.min( pageHeightPx - toolbarHeight - 8, px( bounds.minY, zoom ) - toolbarHeight - 30 ) ); }
			toolbar.style.left = x + 'px'; toolbar.style.top = y + 'px'; pageEl.appendChild( toolbar );
		}

		function render() {
			var p = page(); if ( ! p ) { showError( t( 'canonicalPageRequired', 'Designer requires a canonical page definition.' ) ); return; }
			pageEl.innerHTML = '';
			pageEl.style.width = px( p.width_mm, zoom ) + 'px'; pageEl.style.height = px( p.height_mm, zoom ) + 'px';
			var list = elements();
			for ( var i = 0; i < list.length; i++ ) {
				var element = list[ i ]; if ( isHiddenId( element.id ) ) { continue; } var node = document.createElement( 'button' ); node.type = 'button';
				node.className = 'certloom-template-designer-element';
				node.setAttribute( 'data-certloom-designer-element', element.id ); node.setAttribute( 'tabindex', '-1' ); node.setAttribute( 'aria-label', typeLabel( element.type ) + ': ' + elementTitle( element ) + ( isLocked( element ) ? ' (' + t( 'lockedSuffix', 'locked' ) + ')' : '' ) );
				node.style.left = px( element.x_mm, zoom ) + 'px'; node.style.top = px( element.y_mm, zoom ) + 'px'; node.style.width = px( element.width_mm, zoom ) + 'px'; node.style.height = px( element.height_mm, zoom ) + 'px'; node.style.zIndex = String( 100001 + Number( element.z || 0 ) ); node.style.opacity = String( null == element.opacity ? 1 : element.opacity ); node.style.transform = 'rotate(' + Number( element.rotation_deg || 0 ) + 'deg)';
				node.setAttribute( 'aria-pressed', isSelectedId( element.id ) ? 'true' : 'false' );
				if ( isSelectedId( element.id ) ) { node.classList.add( 'is-multi-selected' ); }
				if ( element.id === selectedId ) { node.classList.add( 'is-selected', 'is-primary-selected' ); }
				if ( isLocked( element ) ) { node.classList.add( 'is-locked' ); }
				styleContent( node, element ); addHandles( node, element ); pageEl.appendChild( node );
			}
			renderSafeArea(); renderGuides(); renderSpacingGuides(); renderSelectionToolbar(); renderLayers(); syncControls(); setInspector(); renderStudioRulers(); syncDirty(); showError( '' );
		}
		function tableElementGeometryValid( element ) {
			if ( ! element || ( 'table' !== element.type && 'data_table' !== element.type ) ) { return true; }
			var rows = 'data_table' === element.type ? Number( element.max_rows || 0 ) : ( Array.isArray( element.rows ) ? element.rows.length : 0 ); var visual = rows + ( element.show_header ? 1 : 0 ); if ( rows < 1 || rows > 50 || Number( element.height_mm || 0 ) / Math.max( 1, visual ) < 4 - 0.0001 ) { return false; }
			var style = Object.assign( defaultTableStyle(), element.style || {} ), padding = Number( style.cell_padding_mm || 0 ), rowHeight = Number( element.height_mm || 0 ) / Math.max( 1, visual ); if ( rowHeight <= 2 * padding + 0.1 ) { return false; }
			var columns = Array.isArray( element.columns ) ? element.columns : []; if ( columns.length < 1 || columns.length > 12 ) { return false; } var total = 0; for ( var i = 0; i < columns.length; i++ ) { var pct = Number( columns[ i ].width_pct || 0 ); total += pct; if ( pct < 2 || Number( element.width_mm || 0 ) * pct / 100 <= 2 * padding + 0.1 ) { return false; } } return Math.abs( total - 100 ) <= 0.01;
		}
		function updateElement( id, proposed, mark ) {
			var list = elements(); var p = page();
			for ( var i = 0; i < list.length; i++ ) {
				if ( list[ i ].id !== id ) { continue; }
				if ( ! elementGeometryValid( proposed, p ) || ! tableElementGeometryValid( proposed ) ) { return false; }
				list[ i ] = proposed; if ( mark ) { syncDirty(); } render(); return true;
			}
			return false;
		}
		function updateElementSet( proposedById, mark ) {
			var list = elements(); var p = page(); var indexes = [];
			for ( var i = 0; i < list.length; i++ ) {
				if ( ! Object.prototype.hasOwnProperty.call( proposedById, list[ i ].id ) ) { continue; }
				if ( ! elementGeometryValid( proposedById[ list[ i ].id ], p ) || ! tableElementGeometryValid( proposedById[ list[ i ].id ] ) ) { return false; }
				indexes.push( i );
			}
			if ( ! indexes.length ) { return false; }
			for ( var j = 0; j < indexes.length; j++ ) { var at = indexes[ j ]; list[ at ] = proposedById[ list[ at ].id ]; }
			if ( mark ) { syncDirty(); } render(); return true;
		}
		function clampDragged( element ) {
			var p = page(); var bounds = rotatedBounds( element );
			if ( bounds.minX < 0 ) { element.x_mm += -bounds.minX; }
			if ( bounds.maxX > p.width_mm ) { element.x_mm += p.width_mm - bounds.maxX; }
			if ( bounds.minY < 0 ) { element.y_mm += -bounds.minY; }
			if ( bounds.maxY > p.height_mm ) { element.y_mm += p.height_mm - bounds.maxY; }
			element.x_mm = round( element.x_mm, 3 ); element.y_mm = round( element.y_mm, 3 ); return element;
		}
		function snapTargets( axis, movingIds ) {
			var p = page(); var margin = safeMarginMm(); var extent = 'x' === axis ? Number( p.width_mm ) : Number( p.height_mm );
			var targets = [ 0, extent / 2, extent ]; if ( margin > 0 && margin < extent / 2 ) { targets.push( margin, extent - margin ); }
			var excluded = Array.isArray( movingIds ) ? movingIds : [ movingIds ];
			var list = elements();
			for ( var i = 0; i < list.length; i++ ) {
				if ( excluded.indexOf( list[ i ].id ) !== -1 ) { continue; }
				var b = rotatedBounds( list[ i ] );
				if ( 'x' === axis ) { targets.push( b.minX, b.centerX, b.maxX ); } else { targets.push( b.minY, b.centerY, b.maxY ); }
			}
			return targets;
		}
		function closestSnap( anchors, targets ) {
			var best = null;
			for ( var i = 0; i < anchors.length; i++ ) {
				for ( var j = 0; j < targets.length; j++ ) {
					var delta = targets[ j ] - anchors[ i ]; var distance = Math.abs( delta );
					if ( distance <= SNAP_THRESHOLD_MM && ( ! best || distance < best.distance ) ) { best = { delta: delta, target: targets[ j ], distance: distance }; }
				}
			}
			return best;
		}
		function equalSpacingCandidateForBounds( bounds, axis, excludedIds ) {
			if ( ! bounds ) { return null; }
			var useX = 'x' === axis; var excluded = Array.isArray( excludedIds ) ? excludedIds : [ excludedIds ];
			var currentMin = useX ? bounds.minX : bounds.minY; var currentMax = useX ? bounds.maxX : bounds.maxY; var before = null; var after = null;
			var list = elements();
			for ( var i = 0; i < list.length; i++ ) {
				if ( excluded.indexOf( list[ i ].id ) !== -1 || isHiddenId( list[ i ].id ) ) { continue; }
				var b = rotatedBounds( list[ i ] ); if ( useX && ( b.maxY < bounds.minY || b.minY > bounds.maxY ) ) { continue; } if ( ! useX && ( b.maxX < bounds.minX || b.minX > bounds.maxX ) ) { continue; } var min = useX ? b.minX : b.minY; var max = useX ? b.maxX : b.maxY;
				if ( max <= currentMin + 0.001 && ( ! before || max > before.max ) ) { before = { min: min, max: max }; }
				if ( min >= currentMax - 0.001 && ( ! after || min < after.min ) ) { after = { min: min, max: max }; }
			}
			if ( ! before || ! after ) { return null; }
			var size = currentMax - currentMin; var gap = ( after.min - before.max - size ) / 2; if ( gap < -0.001 ) { return null; }
			var desiredMin = before.max + gap; var delta = desiredMin - currentMin; var distance = Math.abs( delta );
			if ( distance > SNAP_THRESHOLD_MM ) { return null; }
			return { axis: axis, delta: delta, distance: distance, gap: Math.max( 0, gap ), beforeEdge: before.max, afterEdge: after.min };
		}
		function setEqualSpacingGuides( candidate, movedBounds ) {
			if ( ! candidate || ! movedBounds ) { return; }
			var useX = 'x' === candidate.axis; var min = ( useX ? movedBounds.minX : movedBounds.minY ) + candidate.delta; var max = ( useX ? movedBounds.maxX : movedBounds.maxY ) + candidate.delta; var cross = useX ? movedBounds.centerY : movedBounds.centerX;
			activeSpacingGuides.push( { axis: candidate.axis, start: candidate.beforeEdge, end: min, cross: cross, gap: candidate.gap, label: true } );
			activeSpacingGuides.push( { axis: candidate.axis, start: max, end: candidate.afterEdge, cross: cross, gap: candidate.gap, label: false } );
		}
		function snapDragged( element, bypass ) {
			activeGuides = { x: null, y: null }; activeSpacingGuides = [];
			if ( bypass || ! designerState.snap_enabled ) { return element; }
			var bounds = rotatedBounds( element );
			var sx = closestSnap( [ bounds.minX, bounds.centerX, bounds.maxX ], snapTargets( 'x', element.id ) );
			var sy = closestSnap( [ bounds.minY, bounds.centerY, bounds.maxY ], snapTargets( 'y', element.id ) );
			var ex = equalSpacingCandidateForBounds( bounds, 'x', [ element.id ] ); var ey = equalSpacingCandidateForBounds( bounds, 'y', [ element.id ] );
			var useEx = ex && ( ! sx || ex.distance < sx.distance ); var useEy = ey && ( ! sy || ey.distance < sy.distance );
			var candidate = clone( element );
			if ( useEx ) { candidate.x_mm = round( Number( candidate.x_mm ) + ex.delta, 3 ); setEqualSpacingGuides( ex, bounds ); }
			else if ( sx ) { candidate.x_mm = round( Number( candidate.x_mm ) + sx.delta, 3 ); activeGuides.x = round( sx.target, 3 ); }
			if ( useEy ) { candidate.y_mm = round( Number( candidate.y_mm ) + ey.delta, 3 ); setEqualSpacingGuides( ey, bounds ); }
			else if ( sy ) { candidate.y_mm = round( Number( candidate.y_mm ) + sy.delta, 3 ); activeGuides.y = round( sy.target, 3 ); }
			candidate = clampDragged( candidate );
			if ( elementGeometryValid( candidate, page() ) ) { return candidate; }
			activeGuides = { x: null, y: null }; activeSpacingGuides = []; return element;
		}

		function translatedSelection( initialById, dx, dy ) {
			var result = {}; for ( var id in initialById ) { if ( Object.prototype.hasOwnProperty.call( initialById, id ) ) { var element = clone( initialById[ id ] ); element.x_mm = round( Number( element.x_mm ) + dx, 3 ); element.y_mm = round( Number( element.y_mm ) + dy, 3 ); result[ id ] = element; } } return result;
		}
		function selectionArrayFromMap( map ) { var result = []; for ( var id in map ) { if ( Object.prototype.hasOwnProperty.call( map, id ) ) { result.push( map[ id ] ); } } return result; }
		function clampSelectionMap( map ) {
			var list = selectionArrayFromMap( map ); var bounds = combinedBounds( list ); var p = page(); if ( ! bounds || ! p ) { return map; }
			var dx = 0, dy = 0;
			if ( bounds.minX < 0 ) { dx = -bounds.minX; } else if ( bounds.maxX > Number( p.width_mm ) ) { dx = Number( p.width_mm ) - bounds.maxX; }
			if ( bounds.minY < 0 ) { dy = -bounds.minY; } else if ( bounds.maxY > Number( p.height_mm ) ) { dy = Number( p.height_mm ) - bounds.maxY; }
			if ( dx || dy ) { for ( var id in map ) { if ( Object.prototype.hasOwnProperty.call( map, id ) ) { map[ id ].x_mm = round( Number( map[ id ].x_mm ) + dx, 3 ); map[ id ].y_mm = round( Number( map[ id ].y_mm ) + dy, 3 ); } } }
			return map;
		}
		function snapSelectionMap( map, bypass ) {
			activeGuides = { x: null, y: null }; activeSpacingGuides = []; if ( bypass || ! designerState.snap_enabled ) { return map; }
			var ids = Object.keys( map ); var bounds = combinedBounds( selectionArrayFromMap( map ) ); if ( ! bounds ) { return map; }
			var sx = closestSnap( [ bounds.minX, bounds.centerX, bounds.maxX ], snapTargets( 'x', ids ) );
			var sy = closestSnap( [ bounds.minY, bounds.centerY, bounds.maxY ], snapTargets( 'y', ids ) );
			var ex = equalSpacingCandidateForBounds( bounds, 'x', ids ); var ey = equalSpacingCandidateForBounds( bounds, 'y', ids );
			var dx = ex && ( ! sx || ex.distance < sx.distance ) ? ex.delta : ( sx ? sx.delta : 0 ); var dy = ey && ( ! sy || ey.distance < sy.distance ) ? ey.delta : ( sy ? sy.delta : 0 );
			if ( dx || dy ) { for ( var id in map ) { if ( Object.prototype.hasOwnProperty.call( map, id ) ) { if ( dx ) { map[ id ].x_mm = round( Number( map[ id ].x_mm ) + dx, 3 ); } if ( dy ) { map[ id ].y_mm = round( Number( map[ id ].y_mm ) + dy, 3 ); } } } }
			if ( ex && dx === ex.delta && ( ! sx || ex.distance < sx.distance ) ) { setEqualSpacingGuides( ex, bounds ); } else if ( sx ) { activeGuides.x = round( sx.target, 3 ); }
			if ( ey && dy === ey.delta && ( ! sy || ey.distance < sy.distance ) ) { setEqualSpacingGuides( ey, bounds ); } else if ( sy ) { activeGuides.y = round( sy.target, 3 ); }
			return clampSelectionMap( map );
		}


		function canvasPointFromEvent( event ) {
			var rect = pageEl.getBoundingClientRect(); var p = page();
			return { x: round( clamp( mmFromPx( event.clientX - rect.left, zoom ), 0, Number( p.width_mm ) ), 3 ), y: round( clamp( mmFromPx( event.clientY - rect.top, zoom ), 0, Number( p.height_mm ) ), 3 ) };
		}
		function marqueeRect() {
			if ( ! marqueeGesture ) { return null; } var a = marqueeGesture.start, b = marqueeGesture.current;
			return { minX: Math.min( a.x, b.x ), minY: Math.min( a.y, b.y ), maxX: Math.max( a.x, b.x ), maxY: Math.max( a.y, b.y ) };
		}
		function renderMarqueeGesture() {
			var existing = pageEl.querySelector( '[data-certloom-designer-marquee]' ); if ( existing ) { existing.remove(); }
			if ( ! marqueeGesture || ! marqueeGesture.moved ) { return; } var rect = marqueeRect(); if ( ! rect ) { return; }
			var marquee = document.createElement( 'span' ); marquee.className = 'certloom-template-designer-marquee'; marquee.setAttribute( 'data-certloom-designer-marquee', '' ); marquee.setAttribute( 'aria-hidden', 'true' );
			marquee.style.left = px( rect.minX, zoom ) + 'px'; marquee.style.top = px( rect.minY, zoom ) + 'px'; marquee.style.width = Math.max( 1, px( rect.maxX - rect.minX, zoom ) ) + 'px'; marquee.style.height = Math.max( 1, px( rect.maxY - rect.minY, zoom ) ) + 'px'; pageEl.appendChild( marquee );
		}
		function boundsIntersect( a, b ) { return !! a && !! b && a.maxX >= b.minX && a.minX <= b.maxX && a.maxY >= b.minY && a.minY <= b.maxY; }
		function startMarqueeSelection( event ) {
			if ( event.button !== 0 || panToolActive || spacePanHeld || event.target.closest( '[data-certloom-designer-element], [data-certloom-designer-selection-toolbar]' ) ) { return false; }
			var point = canvasPointFromEvent( event ); marqueeGesture = { pointerId: event.pointerId, start: point, current: point, startClientX: event.clientX, startClientY: event.clientY, moved: false, additive: !!( event.shiftKey || event.ctrlKey || event.metaKey ), baseIds: ( event.shiftKey || event.ctrlKey || event.metaKey ) ? selectionIds() : [] };
			closeContextMenu(); try { pageEl.setPointerCapture( event.pointerId ); } catch ( ignore ) {} event.preventDefault(); return true;
		}
		function moveMarqueeSelection( event ) {
			if ( ! marqueeGesture ) { return false; } if ( marqueeGesture.pointerId !== event.pointerId ) { return true; }
			marqueeGesture.current = canvasPointFromEvent( event ); if ( Math.abs( event.clientX - marqueeGesture.startClientX ) >= 3 || Math.abs( event.clientY - marqueeGesture.startClientY ) >= 3 ) { marqueeGesture.moved = true; }
			renderMarqueeGesture(); event.preventDefault(); return true;
		}
		function endMarqueeSelection( event ) {
			if ( ! marqueeGesture || ( null != event.pointerId && marqueeGesture.pointerId !== event.pointerId ) ) { return false; }
			var gesture = marqueeGesture; var rect = marqueeRect(); marqueeGesture = null; var overlay = pageEl.querySelector( '[data-certloom-designer-marquee]' ); if ( overlay ) { overlay.remove(); }
			if ( ! gesture.moved || ! rect ) { return true; }
			var hits = []; var list = elements(); for ( var i = 0; i < list.length; i++ ) { if ( isHiddenId( list[ i ].id ) ) { continue; } if ( boundsIntersect( rect, rotatedBounds( list[ i ] ) ) ) { hits.push( list[ i ].id ); } }
			var next = gesture.additive ? gesture.baseIds.slice() : []; for ( var h = 0; h < hits.length; h++ ) { if ( next.indexOf( hits[ h ] ) === -1 ) { next.push( hits[ h ] ); } }
			selectedIds = next; selectedId = hits.length ? hits[ hits.length - 1 ] : ( next.length ? next[ next.length - 1 ] : '' ); suppressCanvasClick = true; render(); announce( next.length ? t( 'marqueeSelectionUpdated', 'Marquee selection updated.' ) : t( 'selectionCleared', 'Selection cleared.' ) ); return true;
		}
		function startCanvasPan( event ) {
			var temporary = spacePanHeld || event.button === 1; if ( event.target.closest( '[data-certloom-designer-selection-toolbar]' ) ) { return false; } if ( ! temporary && ! panToolActive ) { return false; } if ( event.button !== 0 && event.button !== 1 ) { return false; }
			panGesture = { pointerId: event.pointerId, startX: event.clientX, startY: event.clientY, scrollLeft: workspace.scrollLeft, scrollTop: workspace.scrollTop, moved: false, temporary: temporary };
			workspace.classList.add( 'is-panning' ); closeContextMenu(); try { workspace.setPointerCapture( event.pointerId ); } catch ( ignore ) {} event.preventDefault(); event.stopPropagation(); return true;
		}
		function moveCanvasPan( event ) {
			if ( ! panGesture ) { return false; } if ( panGesture.pointerId !== event.pointerId ) { return true; }
			var dx = event.clientX - panGesture.startX, dy = event.clientY - panGesture.startY; if ( Math.abs( dx ) >= 2 || Math.abs( dy ) >= 2 ) { panGesture.moved = true; }
			workspace.scrollLeft = Math.max( 0, panGesture.scrollLeft - dx ); workspace.scrollTop = Math.max( 0, panGesture.scrollTop - dy ); event.preventDefault(); return true;
		}
		function endCanvasPan( event ) {
			if ( ! panGesture || ( null != event.pointerId && panGesture.pointerId !== event.pointerId ) ) { return false; }
			var moved = panGesture.moved; panGesture = null; workspace.classList.remove( 'is-panning' ); if ( moved ) { suppressCanvasClick = true; } syncCanvasTools(); return true;
		}
		var contextMenuSurface = 'canvas';
		function closeContextMenu() { if ( contextMenu ) { contextMenu.hidden = true; contextMenu.removeAttribute( 'data-open' ); contextMenu.removeAttribute( 'data-surface' ); } contextMenuSurface = 'canvas'; }
		function contextMenuActionButton( action, label, surface ) { var button = document.createElement( 'button' ); button.type = 'button'; button.setAttribute( 'role', 'menuitem' ); button.setAttribute( 'data-certloom-designer-context-action', action ); if ( surface ) { button.setAttribute( 'data-certloom-context-surface', surface ); } button.textContent = label; return button; }
		function ensureContextMenu() {
			if ( contextMenu ) { return contextMenu; } contextMenu = document.createElement( 'div' ); contextMenu.className = 'certloom-template-designer-context-menu'; contextMenu.setAttribute( 'data-certloom-designer-context-menu', '' ); contextMenu.setAttribute( 'role', 'menu' ); contextMenu.setAttribute( 'aria-label', t( 'elementActions', 'Element actions' ) ); contextMenu.hidden = true;
			contextMenu.appendChild( contextMenuActionButton( 'visibility', t( 'hideInEditor', 'Hide in editor' ), 'layer' ) ); contextMenu.appendChild( contextMenuActionButton( 'copy', t( 'copy', 'Copy' ), 'canvas' ) ); contextMenu.appendChild( contextMenuActionButton( 'cut', t( 'cut', 'Cut' ), 'canvas' ) ); contextMenu.appendChild( contextMenuActionButton( 'duplicate', t( 'duplicate', 'Duplicate' ) ) ); contextMenu.appendChild( contextMenuActionButton( 'front', t( 'bringToFront', 'Bring to front' ) ) ); contextMenu.appendChild( contextMenuActionButton( 'forward', t( 'bringForward', 'Bring forward' ), 'layer' ) ); contextMenu.appendChild( contextMenuActionButton( 'backward', t( 'sendBackward', 'Send backward' ), 'layer' ) ); contextMenu.appendChild( contextMenuActionButton( 'back', t( 'sendToBack', 'Send to back' ) ) ); contextMenu.appendChild( contextMenuActionButton( 'lock', t( 'lock', 'Lock' ) ) ); contextMenu.appendChild( contextMenuActionButton( 'delete', t( 'delete', 'Delete' ) ) ); root.appendChild( contextMenu );
			contextMenu.addEventListener( 'click', function ( event ) { var button = event.target.closest( '[data-certloom-designer-context-action]' ); if ( ! button || button.disabled ) { return; } var action = button.getAttribute( 'data-certloom-designer-context-action' ); closeContextMenu(); if ( 'visibility' === action ) { if ( selectedId ) { toggleEditorVisibility( selectedId ); } } else if ( 'copy' === action ) { copySelectionToDesignerClipboard( false ); } else if ( 'cut' === action ) { copySelectionToDesignerClipboard( true ); } else if ( 'duplicate' === action ) { duplicateSelected(); } else if ( [ 'front', 'forward', 'backward', 'back' ].indexOf( action ) !== -1 ) { reorderSelected( action ); } else if ( 'lock' === action ) { if ( selectedId ) { toggleLock( selectedId ); } } else if ( 'delete' === action ) { removeSelected(); } } ); return contextMenu;
		}
		function syncContextMenu() {
			var menu = ensureContextMenu(); var selection = selectedElements(); var element = selected(); var locked = selectionHasLocked(); var multi = selection.length > 1; var buttons = menu.querySelectorAll( '[data-certloom-designer-context-action]' );
			for ( var i = 0; i < buttons.length; i++ ) {
				var action = buttons[ i ].getAttribute( 'data-certloom-designer-context-action' ); var surface = buttons[ i ].getAttribute( 'data-certloom-context-surface' ); buttons[ i ].hidden = !! surface && surface !== contextMenuSurface;
				buttons[ i ].disabled = ! selection.length || ( [ 'cut', 'delete' ].indexOf( action ) !== -1 && locked ) || ( [ 'duplicate', 'front', 'forward', 'backward', 'back', 'lock', 'visibility' ].indexOf( action ) !== -1 && ( multi || ! element || isLocked( element ) && [ 'lock', 'visibility' ].indexOf( action ) === -1 ) );
				if ( 'lock' === action ) { buttons[ i ].textContent = element && isLocked( element ) ? t( 'unlock', 'Unlock' ) : t( 'lock', 'Lock' ); buttons[ i ].disabled = multi || ! element; }
				if ( 'visibility' === action ) { buttons[ i ].textContent = element && isHiddenId( element.id ) ? t( 'showInEditor', 'Show in editor' ) : t( 'hideInEditor', 'Hide in editor' ); buttons[ i ].disabled = multi || ! element; }
							}
		}
		function openContextMenuAt( clientX, clientY, surface ) {
			if ( ! selectionIds().length ) { return; } contextMenuSurface = surface || 'canvas'; var menu = ensureContextMenu(); menu.setAttribute( 'data-surface', contextMenuSurface ); syncContextMenu(); menu.hidden = false; menu.setAttribute( 'data-open', 'true' ); menu.style.left = '0px'; menu.style.top = '0px'; var rect = menu.getBoundingClientRect(); var left = clamp( Number( clientX ), 8, Math.max( 8, window.innerWidth - rect.width - 8 ) ); var top = clamp( Number( clientY ), 8, Math.max( 8, window.innerHeight - rect.height - 8 ) ); menu.style.left = Math.round( left ) + 'px'; menu.style.top = Math.round( top ) + 'px'; var first = menu.querySelector( '[role="menuitem"]:not([hidden]):not([disabled])' ); if ( first ) { try { first.focus( { preventScroll: true } ); } catch ( ignore ) { first.focus(); } }
		}
		function openContextMenuForSelection() {
			if ( ! selectedId ) { return; } var node = pageEl.querySelector( '[data-certloom-designer-element="' + selectedId + '"]' ); if ( ! node ) { return; } var rect = node.getBoundingClientRect(); openContextMenuAt( rect.left + rect.width / 2, rect.top + rect.height / 2, 'canvas' );
		}
		function canvasPointerMove( event ) { if ( moveCanvasPan( event ) ) { return; } if ( moveMarqueeSelection( event ) ) { return; } pointerMove( event ); }
		function canvasPointerEnd( event ) { if ( endCanvasPan( event ) ) { return; } if ( endMarqueeSelection( event ) ) { return; } pointerEnd(); }

		function pointerStart( event ) {
			var node = event.target.closest( '[data-certloom-designer-element]' ); if ( ! node || ! pageEl.contains( node ) ) { return; }
			var id = node.getAttribute( 'data-certloom-designer-element' ); var additive = event.shiftKey || event.ctrlKey || event.metaKey;
			if ( additive && ! event.target.hasAttribute( 'data-certloom-designer-resize' ) && ! event.target.hasAttribute( 'data-certloom-designer-rotate' ) ) { toggleSelection( id ); render(); event.preventDefault(); announce( selectionIds().length > 1 ? t( 'multiSelectionUpdated', 'Multi-selection updated.' ) : t( 'selectionUpdated', 'Selection updated.' ) ); return; }
			if ( ! isSelectedId( id ) ) { selectOnly( id ); } else { selectedId = id; }
			var resize = event.target.getAttribute( 'data-certloom-designer-resize' ); var rotate = event.target.getAttribute( 'data-certloom-designer-rotate' );
			if ( ( resize || rotate ) && selectionIds().length > 1 ) { selectOnly( id ); }
			render(); focusSelectedCanvasElement();
			var element = selected(); if ( ! element || isLocked( element ) ) { event.preventDefault(); announce( t( 'lockedElementSelected', 'Locked element selected.' ) ); return; }
			if ( 'drag' !== ( rotate ? 'rotate' : ( resize ? 'resize' : 'drag' ) ) && selectionHasLocked() ) { selectOnly( id ); }
			if ( ! resize && ! rotate && selectionIds().length > 1 && selectionHasLocked() ) { event.preventDefault(); announce( t( 'unlockSelectionBeforeMove', 'Unlock the selected elements before moving the selection.' ) ); return; }
			var initialSelection = {}; var selectedList = selectedElements(); for ( var initialIndex = 0; initialIndex < selectedList.length; initialIndex++ ) { initialSelection[ selectedList[ initialIndex ].id ] = clone( selectedList[ initialIndex ] ); }
			pointer = { id: id, mode: rotate ? 'rotate' : ( resize ? 'resize' : 'drag' ), handle: resize || '', startX: event.clientX, startY: event.clientY, initial: clone( element ), initialSelection: initialSelection, before: snapshot(), moved: false };
			if ( 'rotate' === pointer.mode ) {
				var rect = node.getBoundingClientRect(); pointer.centerX = rect.left + rect.width / 2; pointer.centerY = rect.top + rect.height / 2; pointer.startAngle = Math.atan2( event.clientY - pointer.centerY, event.clientX - pointer.centerX ) * 180 / Math.PI; pointer.initialRotation = Number( element.rotation_deg || 0 );
			}
			try { node.setPointerCapture( event.pointerId ); } catch ( ignore ) {}
			event.preventDefault();
		}
		function pointerMove( event ) {
			if ( ! pointer ) { return; }
			var proposed = clone( pointer.initial ); var pixelDx = event.clientX - pointer.startX; var pixelDy = event.clientY - pointer.startY; if ( Math.abs( pixelDx ) > 3 || Math.abs( pixelDy ) > 3 || 'drag' !== pointer.mode ) { pointer.moved = true; } var dx = mmFromPx( pixelDx, zoom ); var dy = mmFromPx( pixelDy, zoom );
			if ( 'drag' === pointer.mode ) {
				if ( pointer.initialSelection && Object.keys( pointer.initialSelection ).length > 1 ) {
					var groupMap = translatedSelection( pointer.initialSelection, dx, dy ); groupMap = snapSelectionMap( clampSelectionMap( groupMap ), !! event.altKey ); updateElementSet( groupMap, false ); syncDirty(); event.preventDefault(); return;
				}
				proposed.x_mm = round( Number( pointer.initial.x_mm ) + dx, 3 ); proposed.y_mm = round( Number( pointer.initial.y_mm ) + dy, 3 ); proposed = snapDragged( clampDragged( proposed ), !! event.altKey );
			} else if ( 'resize' === pointer.mode ) {
				activeGuides = { x: null, y: null };
				if ( pointer.handle.indexOf( 'e' ) !== -1 ) { proposed.width_mm = round( Math.max( MIN_RESIZE_MM, Number( pointer.initial.width_mm ) + dx ), 3 ); }
				if ( pointer.handle.indexOf( 's' ) !== -1 ) { proposed.height_mm = round( Math.max( MIN_RESIZE_MM, Number( pointer.initial.height_mm ) + dy ), 3 ); }
				if ( pointer.handle.indexOf( 'w' ) !== -1 ) { var maxDx = Number( pointer.initial.width_mm ) - MIN_RESIZE_MM; var usedDx = Math.min( dx, maxDx ); proposed.x_mm = round( Number( pointer.initial.x_mm ) + usedDx, 3 ); proposed.width_mm = round( Number( pointer.initial.width_mm ) - usedDx, 3 ); }
				if ( pointer.handle.indexOf( 'n' ) !== -1 ) { var maxDy = Number( pointer.initial.height_mm ) - MIN_RESIZE_MM; var usedDy = Math.min( dy, maxDy ); proposed.y_mm = round( Number( pointer.initial.y_mm ) + usedDy, 3 ); proposed.height_mm = round( Number( pointer.initial.height_mm ) - usedDy, 3 ); }
			} else {
				activeGuides = { x: null, y: null }; activeSpacingGuides = []; var currentAngle = Math.atan2( event.clientY - pointer.centerY, event.clientX - pointer.centerX ) * 180 / Math.PI; proposed.rotation_deg = normalizedAngle( pointer.initialRotation + currentAngle - pointer.startAngle );
			}
			updateElement( pointer.id, proposed, false ); syncDirty(); event.preventDefault();
		}
		function pointerEnd() {
			if ( ! pointer ) { return; }
			var before = pointer.before; var completed = pointer; pointer = null; activeGuides = { x: null, y: null }; activeSpacingGuides = [];
			if ( completed.moved ) { suppressCanvasClick = true; }
			if ( 'drag' === completed.mode && ! completed.moved ) { lastElementPointer = { id: completed.id, at: Date.now() }; } else { lastElementPointer = { id: '', at: 0 }; }
			pushHistory( before ); render();
		}
		function pageChange() {
			var p = page(); if ( ! p ) { return; }
			var size = sizeControl ? sizeControl.value : p.size; var orientation = orientationControl ? orientationControl.value : p.orientation;
			var newW = 0, newH = 0;
			if ( 'Custom' === size ) {
				newW = Number( customWidthControl ? customWidthControl.value : p.width_mm ); newH = Number( customHeightControl ? customHeightControl.value : p.height_mm );
				if ( ! isFinite( newW ) || ! isFinite( newH ) || newW < 25 || newH < 25 || newW > 1200 || newH > 1200 ) { showError( t( 'customPageSizeInvalid', 'Custom page width and height must each be between 25 and 1200 mm.' ) ); syncControls(); return; }
				if ( ( 'landscape' === orientation && newW < newH ) || ( 'portrait' === orientation && newH < newW ) ) { var swap = newW; newW = newH; newH = swap; }
			} else {
				if ( ! PAGE_DIMENSIONS[ size ] || ! PAGE_DIMENSIONS[ size ][ orientation ] ) { render(); return; }
				var dims = PAGE_DIMENSIONS[ size ][ orientation ]; newW = dims[ 0 ]; newH = dims[ 1 ];
			}
			if ( size === p.size && orientation === p.orientation && Math.abs( Number( p.width_mm ) - newW ) < 0.001 && Math.abs( Number( p.height_mm ) - newH ) < 0.001 ) { syncControls(); return; }
			var before = snapshot(); var oldW = Number( p.width_mm ), oldH = Number( p.height_mm ); var scale = Math.min( newW / oldW, newH / oldH ); var offsetX = ( newW - oldW * scale ) / 2; var offsetY = ( newH - oldH * scale ) / 2;
			var next = clone( layout ); next.page = { size: size, orientation: orientation, width_mm: round( newW, 3 ), height_mm: round( newH, 3 ) };
			for ( var i = 0; i < next.elements.length; i++ ) {
				next.elements[ i ].x_mm = round( Number( next.elements[ i ].x_mm ) * scale + offsetX, 3 ); next.elements[ i ].y_mm = round( Number( next.elements[ i ].y_mm ) * scale + offsetY, 3 ); next.elements[ i ].width_mm = round( Number( next.elements[ i ].width_mm ) * scale, 3 ); next.elements[ i ].height_mm = round( Number( next.elements[ i ].height_mm ) * scale, 3 );
				if ( ! elementGeometryValid( next.elements[ i ], next.page ) ) { showError( t( 'pageConversionBoundary', 'This page conversion would place an element outside the canonical page bounds. No change was applied.' ) ); syncControls(); return; }
			}
			layout = next; pushHistory( before ); render(); window.setTimeout( fitWorkspace, 0 );
		}
		function setZoomLevel( nextPercent, speak, restoreCanvasFocus ) {
			var percent = clamp( Number( nextPercent || 100 ), 25, 400 ); zoom = fitZoom * percent / 100; render();
			if ( restoreCanvasFocus ) { if ( selectedId ) { focusSelectedCanvasElement(); } else if ( workspace ) { try { workspace.focus( { preventScroll: true } ); } catch ( ignore ) { workspace.focus(); } } }
			if ( speak ) { announce( t( 'zoomUpdated', 'Canvas zoom updated.' ) ); }
		}
		function fitWorkspace() {
			var p = page(); if ( ! p ) { return; }
			var availableW = Math.max( 120, workspace.clientWidth - 72 ); var availableH = Math.max( 140, workspace.clientHeight - 72 );
			fitZoom = clamp( Math.min( availableW / ( p.width_mm * BASE_PX_PER_MM ), availableH / ( p.height_mm * BASE_PX_PER_MM ) ), .04, 8 ); zoom = fitZoom; render();
			window.setTimeout( function () { workspace.scrollLeft = 0; workspace.scrollTop = 0; }, 0 );
		}

		function alignSelected( mode ) {
			var selection = selectedElements(); var element = selected(); if ( ! element || ! selection.length || selectionHasLocked() ) { return; }
			if ( selection.length > 1 ) {
				var selectionBounds = combinedBounds( selection ); if ( ! selectionBounds ) { return; }
				var proposedSet = {};
				for ( var selectionIndex = 0; selectionIndex < selection.length; selectionIndex++ ) {
					var current = selection[ selectionIndex ]; var currentBounds = rotatedBounds( current ); var aligned = clone( current ); var groupDelta = 0;
					if ( 'left' === mode ) { groupDelta = selectionBounds.minX - currentBounds.minX; aligned.x_mm += groupDelta; }
					if ( 'center-x' === mode ) { groupDelta = selectionBounds.centerX - currentBounds.centerX; aligned.x_mm += groupDelta; }
					if ( 'right' === mode ) { groupDelta = selectionBounds.maxX - currentBounds.maxX; aligned.x_mm += groupDelta; }
					if ( 'top' === mode ) { groupDelta = selectionBounds.minY - currentBounds.minY; aligned.y_mm += groupDelta; }
					if ( 'center-y' === mode ) { groupDelta = selectionBounds.centerY - currentBounds.centerY; aligned.y_mm += groupDelta; }
					if ( 'bottom' === mode ) { groupDelta = selectionBounds.maxY - currentBounds.maxY; aligned.y_mm += groupDelta; }
					aligned.x_mm = round( aligned.x_mm, 3 ); aligned.y_mm = round( aligned.y_mm, 3 ); proposedSet[ aligned.id ] = aligned;
				}
				var groupBefore = snapshot(); if ( updateElementSet( proposedSet, false ) ) { pushHistory( groupBefore ); render(); announce( t( 'selectionAligned', 'Selected elements aligned.' ) ); } return;
			}
			var before = snapshot(); var proposed = clone( element ); var p = page(); var b = rotatedBounds( proposed ); var delta = 0;
			if ( 'left' === mode ) { delta = -b.minX; proposed.x_mm += delta; }
			if ( 'center-x' === mode ) { delta = Number( p.width_mm ) / 2 - b.centerX; proposed.x_mm += delta; }
			if ( 'right' === mode ) { delta = Number( p.width_mm ) - b.maxX; proposed.x_mm += delta; }
			if ( 'top' === mode ) { delta = -b.minY; proposed.y_mm += delta; }
			if ( 'center-y' === mode ) { delta = Number( p.height_mm ) / 2 - b.centerY; proposed.y_mm += delta; }
			if ( 'bottom' === mode ) { delta = Number( p.height_mm ) - b.maxY; proposed.y_mm += delta; }
			proposed.x_mm = round( proposed.x_mm, 3 ); proposed.y_mm = round( proposed.y_mm, 3 );
			if ( updateElement( element.id, proposed, false ) ) { pushHistory( before ); render(); announce( t( 'selectedAligned', 'Selected element aligned to page.' ) ); }
		}
		function distributeSelection( axis ) {
			var selection = selectedElements(); if ( selection.length < 3 || selectionHasLocked() || [ 'horizontal', 'vertical' ].indexOf( axis ) === -1 ) { return; }
			var useX = 'horizontal' === axis; var ordered = selection.map( function ( element ) { var b = rotatedBounds( element ); return { element: element, bounds: b, min: useX ? b.minX : b.minY, max: useX ? b.maxX : b.maxY, size: useX ? b.maxX - b.minX : b.maxY - b.minY }; } ).sort( function ( a, b ) { return a.min - b.min || String( a.element.id ).localeCompare( String( b.element.id ) ); } );
			var span = ordered[ ordered.length - 1 ].max - ordered[ 0 ].min; var total = 0; for ( var i = 0; i < ordered.length; i++ ) { total += ordered[ i ].size; }
			var gap = ( span - total ) / ( ordered.length - 1 ); if ( gap < -0.001 ) { showError( t( 'distributionOverlap', 'The selected elements overlap too much to create equal non-overlapping gaps.' ) ); return; }
			var proposedSet = {}; var cursor = ordered[ 0 ].min;
			for ( var index = 0; index < ordered.length; index++ ) {
				var row = ordered[ index ]; var proposed = clone( row.element ); if ( index > 0 && index < ordered.length - 1 ) { var delta = cursor - row.min; if ( useX ) { proposed.x_mm = round( Number( proposed.x_mm ) + delta, 3 ); } else { proposed.y_mm = round( Number( proposed.y_mm ) + delta, 3 ); } }
				proposedSet[ proposed.id ] = proposed; cursor = ( index === 0 ? row.min + row.size : cursor + row.size ) + gap;
			}
			var before = snapshot(); if ( updateElementSet( proposedSet, false ) ) { pushHistory( before ); render(); announce( useX ? t( 'distributedHorizontally', 'Selection spaced evenly horizontally.' ) : t( 'distributedVertically', 'Selection spaced evenly vertically.' ) ); }
		}
		function layerOrder() { return elements().slice().sort( function ( a, b ) { return Number( a.z || 0 ) - Number( b.z || 0 ); } ); }
		function reorderSelected( mode ) {
			var element = selected(); if ( ! element || isLocked( element ) ) { return; }
			var order = layerOrder(); var index = -1; for ( var i = 0; i < order.length; i++ ) { if ( order[ i ].id === element.id ) { index = i; break; } }
			if ( index < 0 ) { return; }
			var target = index;
			if ( 'front' === mode ) { target = order.length - 1; }
			if ( 'forward' === mode ) { target = Math.min( order.length - 1, index + 1 ); }
			if ( 'backward' === mode ) { target = Math.max( 0, index - 1 ); }
			if ( 'back' === mode ) { target = 0; }
			if ( target === index ) { return; }
			var before = snapshot(); var moved = order.splice( index, 1 )[ 0 ]; order.splice( target, 0, moved );
			for ( var j = 0; j < order.length; j++ ) { order[ j ].z = j; }
			pushHistory( before ); render(); announce( t( 'layerOrderUpdated', 'Layer order updated.' ) );
		}
		function moveLayerToVisualTarget( movedId, targetId ) {
			if ( ! movedId || ! targetId || movedId === targetId || isLockedId( movedId ) ) { return; }
			var visual = layerOrder().slice().reverse(); var from = -1; var targetBefore = -1;
			for ( var i = 0; i < visual.length; i++ ) {
				if ( visual[ i ].id === movedId ) { from = i; }
				if ( visual[ i ].id === targetId ) { targetBefore = i; }
			}
			if ( from < 0 || targetBefore < 0 ) { return; }
			var before = snapshot(); var moved = visual.splice( from, 1 )[ 0 ]; var target = -1;
			for ( var j = 0; j < visual.length; j++ ) { if ( visual[ j ].id === targetId ) { target = j; break; } }
			if ( target < 0 ) { return; }
			// Rows are rendered front → back. Dropping a row onto another should move
			// it past that row when travelling downward, and before it when travelling upward.
			var insertAt = target + ( from < targetBefore ? 1 : 0 );
			visual.splice( insertAt, 0, moved );
			var order = visual.slice().reverse();
			for ( var z = 0; z < order.length; z++ ) { order[ z ].z = z; }
			selectOnly( movedId ); pushHistory( before ); render(); announce( t( 'layerOrderUpdated', 'Layer order updated.' ) );
		}
		function toggleLock( id ) {
			if ( ! id ) { return; }
			var before = snapshot(); var at = designerState.locked_ids.indexOf( id );
			if ( at === -1 ) { designerState.locked_ids.push( id ); designerState.locked_ids.sort(); } else { designerState.locked_ids.splice( at, 1 ); }
			pushHistory( before ); render(); announce( at === -1 ? t( 'elementLocked', 'Element locked for this author.' ) : t( 'elementUnlocked', 'Element unlocked.' ) );
		}
		function focusSelectedCanvasElement() {
			if ( ! selectedId ) { return; }
			window.setTimeout( function () {
				var nodes = pageEl.querySelectorAll( '[data-certloom-designer-element]' );
				for ( var i = 0; i < nodes.length; i++ ) { if ( nodes[ i ].getAttribute( 'data-certloom-designer-element' ) === selectedId ) { try { nodes[ i ].focus( { preventScroll: true } ); } catch ( ignore ) { nodes[ i ].focus(); } return; } }
			}, 0 );
		}
		function nudgeSelected( event ) {
			var selection = selectedElements(); var element = selected(); if ( ! element || ! selection.length || selectionHasLocked() ) { return false; }
			var dx = 0, dy = 0; var step = event.altKey ? 0.1 : ( event.shiftKey ? 5 : 1 );
			if ( 'ArrowLeft' === event.key ) { dx = -step; } if ( 'ArrowRight' === event.key ) { dx = step; } if ( 'ArrowUp' === event.key ) { dy = -step; } if ( 'ArrowDown' === event.key ) { dy = step; }
			if ( 0 === dx && 0 === dy ) { return false; }
			var before = snapshot();
			if ( selection.length > 1 ) {
				var initialSet = {}; for ( var i = 0; i < selection.length; i++ ) { initialSet[ selection[ i ].id ] = clone( selection[ i ] ); }
				var proposedSet = clampSelectionMap( translatedSelection( initialSet, dx, dy ) ); if ( updateElementSet( proposedSet, false ) ) { pushHistory( before ); render(); announce( t( 'selectionNudged', 'Selection nudged.' ) ); }
			} else {
				var proposed = clone( element ); proposed.x_mm = round( Number( proposed.x_mm ) + dx, 3 ); proposed.y_mm = round( Number( proposed.y_mm ) + dy, 3 ); proposed = clampDragged( proposed );
				if ( updateElement( element.id, proposed, false ) ) { pushHistory( before ); render(); announce( t( 'elementNudged', 'Element nudged.' ) ); }
			}
			focusSelectedCanvasElement();
			return true;
		}
		function isFormControl( target ) { return !! target && ( /^(INPUT|SELECT|TEXTAREA)$/.test( target.tagName ) || ( 'BUTTON' === target.tagName && ! target.hasAttribute( 'data-certloom-designer-element' ) ) ); }
		function keyboard( event ) {
			var modifier = event.ctrlKey || event.metaKey; var inside = root.contains( event.target ); var key = String( event.key || '' ); var lower = key.toLowerCase(); var passiveDocumentTarget = event.target === document.body || event.target === document.documentElement; var canvasFocused = event.target === workspace || pageEl.contains( event.target ) || ( passiveDocumentTarget && selectionIds().length > 0 );
			if ( 'Escape' === key && contextMenu && ! contextMenu.hidden ) { closeContextMenu(); focusSelectedCanvasElement(); event.preventDefault(); return; }
			if ( modifier && ! event.altKey && 's' === lower ) {
				if ( ! inside || isFormControl( event.target ) && ! root.contains( event.target ) ) { return; }
				event.preventDefault();
				for ( var saveIndex = 0; saveIndex < saveButtons.length; saveIndex++ ) { if ( ! saveButtons[ saveIndex ].disabled ) { saveButtons[ saveIndex ].click(); break; } }
				return;
			}
			if ( modifier && ! event.altKey && ( 'z' === lower || 'y' === lower ) ) {
				if ( isFormControl( event.target ) || ( ! inside && event.target !== document.body ) ) { return; }
				if ( 'y' === lower || event.shiftKey ) { redo(); } else { undo(); }
				event.preventDefault(); return;
			}
			if ( ( ! inside && ! passiveDocumentTarget ) || isFormControl( event.target ) ) { return; }
			if ( canvasFocused && ! modifier && ! event.altKey && ( ' ' === key || 'Spacebar' === key ) ) { spacePanHeld = true; syncCanvasTools(); event.preventDefault(); return; }
			if ( canvasFocused && selectionIds().length && ( 'ContextMenu' === key || ( event.shiftKey && 'F10' === key ) ) ) { event.preventDefault(); openContextMenuForSelection(); return; }
			if ( modifier && ! event.altKey && 'c' === lower ) { event.preventDefault(); copySelectionToDesignerClipboard( false ); return; }
			if ( modifier && ! event.altKey && 'x' === lower ) { event.preventDefault(); copySelectionToDesignerClipboard( true ); return; }
			if ( modifier && ! event.altKey && 'v' === lower ) { event.preventDefault(); pasteDesignerClipboard(); return; }
			if ( modifier && ! event.altKey && 'd' === lower ) { event.preventDefault(); duplicateSelected(); return; }
			if ( modifier && ! event.altKey && ( ']' === key || '[' === key ) ) {
				event.preventDefault();
				if ( ']' === key ) { reorderSelected( event.shiftKey ? 'front' : 'forward' ); } else { reorderSelected( event.shiftKey ? 'back' : 'backward' ); }
				return;
			}
			if ( modifier && ! event.altKey && 'a' === lower ) {
				event.preventDefault(); selectedIds = elements().map( function ( element ) { return element.id; } ); selectedId = selectedIds.length ? selectedIds[ selectedIds.length - 1 ] : ''; render(); focusSelectedCanvasElement(); announce( t( 'allElementsSelected', 'All canvas elements selected.' ) ); return;
			}
			if ( 'Escape' === key && insertPanel && ! insertPanel.hidden ) { closeInsertPanel(); if ( insertFocusButton ) { insertFocusButton.focus(); } event.preventDefault(); return; }
			if ( 'Escape' === key && panToolActive ) { panToolActive = false; syncCanvasTools(); announce( t( 'panToolDisabled', 'Pan tool off.' ) ); event.preventDefault(); return; }
			if ( 'Escape' === key && selectionIds().length ) { selectOnly( '' ); render(); announce( t( 'selectionCleared', 'Selection cleared.' ) ); event.preventDefault(); return; }
			if ( 'Enter' === key && ! modifier && selected() && 'text' === selected().type ) { if ( startDirectTextEdit( selected().id ) ) { event.preventDefault(); } return; }
			if ( 'Enter' === key && ! modifier && selected() && 'rich_text' === selected().type ) { focusRichTextEditor(); event.preventDefault(); return; }
			if ( ( 'Delete' === key || 'Backspace' === key ) && selectionIds().length && ! selectionHasLocked() ) { event.preventDefault(); removeSelected(); return; }
			if ( canvasFocused && ! event.altKey && ( '+' === key || '=' === key ) ) { event.preventDefault(); setZoomLevel( relativeZoomPercent() + 10, true, true ); return; }
			if ( canvasFocused && ! event.altKey && '-' === key ) { event.preventDefault(); setZoomLevel( relativeZoomPercent() - 10, true, true ); return; }
			if ( canvasFocused && modifier && ! event.altKey && '0' === key ) { event.preventDefault(); fitWorkspace(); focusSelectedCanvasElement(); return; }
			if ( [ 'ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown' ].indexOf( key ) !== -1 && selectionIds().length && ! isFormControl( event.target ) ) { event.preventDefault(); if ( nudgeSelected( event ) ) { return; } return; }
		}

		function keyboardUp( event ) {
			var key = String( event.key || '' ); if ( ' ' !== key && 'Spacebar' !== key ) { return; }
			if ( spacePanHeld ) { spacePanHeld = false; syncCanvasTools(); }
		}

		function applyQuickStyleValue( key, value ) {
			var field = root.querySelector( '[data-certloom-designer-style-field="' + key + '"]' ); if ( ! field || field.disabled ) { return; }
			if ( 'checkbox' === field.type ) { field.checked = !! value; } else { field.value = String( value ); }
			applyStyleField( field );
		}

		root.addEventListener( 'pointerdown', function ( event ) { if ( ! event.target.closest( '[data-certloom-designer-element]' ) ) { lastElementPointer = { id: '', at: 0 }; } }, true );
		pageEl.addEventListener( 'pointerdown', function ( event ) {
			var node = event.target.closest( '[data-certloom-designer-element]' ); if ( ! node || ! pageEl.contains( node ) || event.target.hasAttribute( 'data-certloom-designer-resize' ) || event.target.hasAttribute( 'data-certloom-designer-rotate' ) ) { return; }
			if ( event.shiftKey || event.ctrlKey || event.metaKey ) { lastElementPointer = { id: '', at: 0 }; return; }
			var id = node.getAttribute( 'data-certloom-designer-element' ); var now = Date.now(); var previous = lastElementPointer;
			if ( previous.id === id && now - previous.at <= 500 ) { var list = elements(); var element = null; for ( var i = 0; i < list.length; i++ ) { if ( list[ i ].id === id ) { element = list[ i ]; break; } } if ( element && 'text' === element.type && ! isLocked( element ) ) { event.preventDefault(); event.stopPropagation(); lastElementPointer = { id: '', at: 0 }; startDirectTextEdit( id ); } }
		}, true );
		workspace.addEventListener( 'pointerdown', function ( event ) { startCanvasPan( event ); }, true );
		workspace.addEventListener( 'scroll', renderStudioRulers, { passive: true } );
		pageEl.addEventListener( 'pointerdown', function ( event ) { startMarqueeSelection( event ); } );
		pageEl.addEventListener( 'pointerdown', pointerStart ); window.addEventListener( 'pointermove', canvasPointerMove ); window.addEventListener( 'pointerup', canvasPointerEnd ); window.addEventListener( 'pointercancel', canvasPointerEnd );
		pageEl.addEventListener( 'click', function ( event ) {
			if ( suppressCanvasClick ) { suppressCanvasClick = false; return; }
			var selectionAction = event.target.closest( '[data-certloom-designer-selection-action]' );
			if ( selectionAction ) {
				var action = String( selectionAction.getAttribute( 'data-certloom-designer-selection-action' ) || '' ); event.preventDefault(); event.stopPropagation();
				if ( 'duplicate' === action ) { duplicateSelected(); }
				else if ( 'lock' === action && selectedId ) { toggleLock( selectedId ); }
				else if ( 'delete' === action ) { removeSelected(); }
				else if ( 'more' === action ) { var rect = selectionAction.getBoundingClientRect(); openContextMenuAt( rect.left, rect.bottom + 4 ); }
				return;
			}
			var node = event.target.closest( '[data-certloom-designer-element]' );
			if ( event.shiftKey || event.ctrlKey || event.metaKey ) { return; }
			if ( node ) { var id = node.getAttribute( 'data-certloom-designer-element' ); if ( isSelectedId( id ) && selectionIds().length > 1 ) { selectedId = id; } else { selectOnly( id ); } render(); focusSelectedCanvasElement(); return; }
			selectOnly( '' ); render();
		} );
		pageEl.addEventListener( 'dblclick', function ( event ) { var node = event.target.closest( '[data-certloom-designer-element]' ); if ( ! node ) { return; } var id = node.getAttribute( 'data-certloom-designer-element' ); var element = null; var list = elements(); for ( var itemIndex = 0; itemIndex < list.length; itemIndex++ ) { if ( list[ itemIndex ].id === id ) { element = list[ itemIndex ]; break; } } if ( element && 'rich_text' === element.type && ! isLocked( element ) ) { selectOnly( id ); render(); focusRichTextEditor(); event.preventDefault(); return; } if ( startDirectTextEdit( id ) ) { event.preventDefault(); } } );
		pageEl.addEventListener( 'contextmenu', function ( event ) { var node = event.target.closest( '[data-certloom-designer-element]' ); if ( ! node || ! pageEl.contains( node ) ) { closeContextMenu(); return; } event.preventDefault(); var id = node.getAttribute( 'data-certloom-designer-element' ); if ( ! isSelectedId( id ) ) { selectOnly( id ); } else { selectedId = id; } render(); openContextMenuAt( event.clientX, event.clientY, 'canvas' ); } );
		document.addEventListener( 'pointerdown', function ( event ) { if ( contextMenu && ! contextMenu.hidden && ! contextMenu.contains( event.target ) ) { closeContextMenu(); } var insideLeftRail = !! ( event.target && event.target.closest && event.target.closest( '.certloom-studio-left' ) ); if ( insertPanel && ! insertPanel.hidden && ! insideLeftRail && ! insertPanel.contains( event.target ) && ( ! insertFocusButton || ! insertFocusButton.contains( event.target ) ) ) { closeInsertPanel(); } } );
		workspace.addEventListener( 'scroll', closeContextMenu ); window.addEventListener( 'scroll', closeContextMenu, true ); window.addEventListener( 'resize', closeContextMenu );
		pageEl.addEventListener( 'dragover', function ( event ) { if ( ! draggedPaletteKind ) { return; } event.preventDefault(); if ( event.dataTransfer ) { event.dataTransfer.dropEffect = 'copy'; } pageEl.classList.add( 'is-drop-target' ); } );
		pageEl.addEventListener( 'dragleave', function ( event ) { if ( event.relatedTarget && pageEl.contains( event.relatedTarget ) ) { return; } pageEl.classList.remove( 'is-drop-target' ); } );
		pageEl.addEventListener( 'drop', function ( event ) {
			var kind = draggedPaletteKind || ( event.dataTransfer ? event.dataTransfer.getData( 'application/x-certloom-element' ) : '' ); if ( ! kind ) { return; } event.preventDefault(); pageEl.classList.remove( 'is-drop-target' ); draggedPaletteKind = '';
			var rect = pageEl.getBoundingClientRect(); var point = { x_mm: round( mmFromPx( event.clientX - rect.left, zoom ), 3 ), y_mm: round( mmFromPx( event.clientY - rect.top, zoom ), 3 ) }; addRequested( kind, point );
		} );
		if ( layersContainer ) {
			layersContainer.addEventListener( 'click', function ( event ) {
				var renameSave = event.target.closest( '[data-certloom-designer-layer-rename-save]' );
				if ( renameSave ) { var saveId = renameSave.getAttribute( 'data-certloom-designer-layer-rename-save' ); var saveInput = layersContainer.querySelector( '[data-certloom-designer-layer-rename-input="' + saveId + '"]' ); setLayerLabel( saveId, saveInput ? saveInput.value : '' ); return; }
				var renameCancel = event.target.closest( '[data-certloom-designer-layer-rename-cancel]' );
				if ( renameCancel ) { renamingLayerId = ''; render(); return; }
				var action = event.target.closest( '[data-certloom-designer-layer-row-action]' );
				if ( action ) { var actionId = action.getAttribute( 'data-certloom-designer-layer-id' ); var mode = action.getAttribute( 'data-certloom-designer-layer-row-action' ); selectOnly( actionId ); if ( 'visibility' === mode ) { toggleEditorVisibility( actionId ); } else if ( 'rename' === mode ) { renamingLayerId = actionId; render(); } else if ( 'lock' === mode ) { toggleLock( actionId ); } else if ( 'duplicate' === mode ) { duplicateSelected(); } else if ( 'delete' === mode ) { removeSelected(); } else { reorderSelected( mode ); } return; }
				var select = event.target.closest( '[data-certloom-designer-layer-select]' ); if ( select ) { var id = select.getAttribute( 'data-certloom-designer-layer-select' ); if ( event.shiftKey || event.ctrlKey || event.metaKey ) { toggleSelection( id ); } else { selectOnly( id ); } render(); focusSelectedCanvasElement(); }
			} );
			layersContainer.addEventListener( 'contextmenu', function ( event ) {
				var row = event.target.closest( '[data-certloom-designer-layer-row]' ); if ( ! row ) { return; } event.preventDefault(); var id = row.getAttribute( 'data-certloom-designer-layer-row' ); if ( ! id ) { return; } if ( ! isSelectedId( id ) || selectionIds().length !== 1 ) { selectOnly( id ); render(); } else { selectedId = id; } openContextMenuAt( event.clientX, event.clientY, 'layer' );
			} );
			layersContainer.addEventListener( 'keydown', function ( event ) {
				var input = event.target.closest( '[data-certloom-designer-layer-rename-input]' ); if ( ! input ) { return; }
				if ( 'Enter' === event.key ) { event.preventDefault(); setLayerLabel( input.getAttribute( 'data-certloom-designer-layer-rename-input' ), input.value ); }
				else if ( 'Escape' === event.key ) { event.preventDefault(); renamingLayerId = ''; render(); }
			} );
			layersContainer.addEventListener( 'dragstart', function ( event ) { var row = event.target.closest( '[data-certloom-designer-layer-row]' ); if ( ! row ) { return; } var id = row.getAttribute( 'data-certloom-designer-layer-row' ); if ( ! id || isLockedId( id ) ) { event.preventDefault(); return; } draggedLayerId = id; row.classList.add( 'is-dragging' ); if ( event.dataTransfer ) { event.dataTransfer.effectAllowed = 'move'; event.dataTransfer.setData( 'application/x-certloom-layer', id ); event.dataTransfer.setData( 'text/plain', id ); } } );
			layersContainer.addEventListener( 'dragover', function ( event ) { if ( ! draggedLayerId ) { return; } var row = event.target.closest( '[data-certloom-designer-layer-row]' ); if ( ! row || row.getAttribute( 'data-certloom-designer-layer-row' ) === draggedLayerId ) { return; } event.preventDefault(); row.classList.add( 'is-layer-drop-target' ); if ( event.dataTransfer ) { event.dataTransfer.dropEffect = 'move'; } } );
			layersContainer.addEventListener( 'dragleave', function ( event ) { var row = event.target.closest( '[data-certloom-designer-layer-row]' ); if ( row && ( ! event.relatedTarget || ! row.contains( event.relatedTarget ) ) ) { row.classList.remove( 'is-layer-drop-target' ); } } );
			layersContainer.addEventListener( 'drop', function ( event ) { var sourceId = draggedLayerId || ( event.dataTransfer ? event.dataTransfer.getData( 'application/x-certloom-layer' ) || event.dataTransfer.getData( 'text/plain' ) : '' ); if ( ! sourceId ) { return; } var row = event.target.closest( '[data-certloom-designer-layer-row]' ); if ( ! row ) { return; } var targetId = row.getAttribute( 'data-certloom-designer-layer-row' ); event.preventDefault(); moveLayerToVisualTarget( sourceId, targetId ); draggedLayerId = ''; } );
			layersContainer.addEventListener( 'dragend', function () { draggedLayerId = ''; var rows = layersContainer.querySelectorAll( '.is-dragging, .is-layer-drop-target' ); for ( var i = 0; i < rows.length; i++ ) { rows[ i ].classList.remove( 'is-dragging', 'is-layer-drop-target' ); } } );
		}
		if ( sizeControl ) { sizeControl.addEventListener( 'change', function () { if ( 'Custom' === sizeControl.value && customSizeWrap ) { customSizeWrap.hidden = false; if ( customWidthControl && ! customWidthControl.value ) { customWidthControl.value = String( round( page().width_mm, 1 ) ); } if ( customHeightControl && ! customHeightControl.value ) { customHeightControl.value = String( round( page().height_mm, 1 ) ); } } pageChange(); } ); }
		if ( orientationControl ) { orientationControl.addEventListener( 'change', pageChange ); }
		function customSizeKeydown( event ) { if ( 'Enter' !== event.key ) { return; } event.preventDefault(); pageChange(); }
		if ( customWidthControl ) { customWidthControl.addEventListener( 'change', pageChange ); customWidthControl.addEventListener( 'keydown', customSizeKeydown ); }
		if ( customHeightControl ) { customHeightControl.addEventListener( 'change', pageChange ); customHeightControl.addEventListener( 'keydown', customSizeKeydown ); }
		if ( zoomControl ) { zoomControl.addEventListener( 'input', function () { setZoomLevel( Number( zoomControl.value ), false ); } ); }
		if ( zoomInButton ) { zoomInButton.addEventListener( 'click', function () { setZoomLevel( relativeZoomPercent() + 10, true, false ); } ); }
		if ( zoomOutButton ) { zoomOutButton.addEventListener( 'click', function () { setZoomLevel( relativeZoomPercent() - 10, true, false ); } ); }
		if ( snapControl ) { snapControl.addEventListener( 'change', function () { var before = snapshot(); designerState.snap_enabled = !! snapControl.checked; pushHistory( before ); render(); announce( designerState.snap_enabled ? t( 'snappingEnabled', 'Snapping enabled.' ) : t( 'snappingDisabled', 'Snapping disabled.' ) ); } ); }
		if ( safeAreaButton ) { safeAreaButton.addEventListener( 'click', function () { safeAreaVisible = ! safeAreaVisible; render(); announce( safeAreaVisible ? t( 'safeAreaShown', 'Safe area shown.' ) : t( 'safeAreaHidden', 'Safe area hidden.' ) ); } ); }
		if ( panToolButton ) { panToolButton.addEventListener( 'click', function () { panToolActive = ! panToolActive; syncCanvasTools(); announce( panToolActive ? t( 'panToolEnabled', 'Pan tool on. Drag the canvas workspace to move around.' ) : t( 'panToolDisabled', 'Pan tool off.' ) ); } ); }
		if ( fitButton ) { fitButton.addEventListener( 'click', fitWorkspace ); }
		if ( trueSizeButton ) { trueSizeButton.addEventListener( 'click', fitWorkspace ); }
		if ( testPrintButton ) { testPrintButton.addEventListener( 'click', function () {
			var p = page(); if ( ! p ) { return; }
			var previousZoom = zoom, previousFitZoom = fitZoom; var printZoom = ( 96 / 25.4 ) / BASE_PX_PER_MM;
			var printStyle = document.createElement( 'style' ); printStyle.setAttribute( 'data-certloom-test-print-style', '' ); printStyle.textContent = '@page { size: ' + Number( p.width_mm ) + 'mm ' + Number( p.height_mm ) + 'mm; margin: 0; }'; document.head.appendChild( printStyle );
			root.classList.add( 'is-test-printing' ); zoom = printZoom; render(); pageEl.style.width = Number( p.width_mm ) + 'mm'; pageEl.style.height = Number( p.height_mm ) + 'mm';
			window.setTimeout( function () { window.print(); zoom = previousZoom; fitZoom = previousFitZoom; if ( printStyle.parentNode ) { printStyle.parentNode.removeChild( printStyle ); } root.classList.remove( 'is-test-printing' ); render(); }, 0 );
		} ); }
		if ( studioImportOpen && studioImportDialog ) { studioImportOpen.addEventListener( 'click', function () { if ( 'function' === typeof studioImportDialog.showModal ) { studioImportDialog.showModal(); } else { studioImportDialog.removeAttribute( 'hidden' ); studioImportDialog.setAttribute( 'open', 'open' ); } } ); }
		if ( studioImportClose && studioImportDialog ) { studioImportClose.addEventListener( 'click', function () { if ( 'function' === typeof studioImportDialog.close ) { studioImportDialog.close(); } else { studioImportDialog.setAttribute( 'hidden', 'hidden' ); studioImportDialog.removeAttribute( 'open' ); } } ); }
		if ( undoButton ) { undoButton.addEventListener( 'click', undo ); }
		if ( redoButton ) { redoButton.addEventListener( 'click', redo ); }
		if ( lockButton ) { lockButton.addEventListener( 'click', function () { if ( selectedId ) { toggleLock( selectedId ); } } ); }
		for ( var alignIndex = 0; alignIndex < alignButtons.length; alignIndex++ ) { alignButtons[ alignIndex ].addEventListener( 'click', function () { alignSelected( this.getAttribute( 'data-certloom-designer-align' ) ); } ); }
		for ( var distributeIndex = 0; distributeIndex < distributeButtons.length; distributeIndex++ ) { distributeButtons[ distributeIndex ].addEventListener( 'click', function () { distributeSelection( this.getAttribute( 'data-certloom-designer-distribute' ) ); } ); }
		for ( var actionIndex = 0; actionIndex < layerActionButtons.length; actionIndex++ ) { layerActionButtons[ actionIndex ].addEventListener( 'click', function () { reorderSelected( this.getAttribute( 'data-certloom-designer-layer-action' ) ); } ); }
		for ( var addIndex = 0; addIndex < addButtons.length; addIndex++ ) {
			addButtons[ addIndex ].addEventListener( 'click', function () { addRequested( this.getAttribute( 'data-certloom-designer-add' ) ); } );
			addButtons[ addIndex ].addEventListener( 'dragstart', function ( event ) { if ( this.disabled || 'true' !== this.getAttribute( 'draggable' ) ) { event.preventDefault(); return; } draggedPaletteKind = this.getAttribute( 'data-certloom-designer-add' ) || ''; root.classList.add( 'is-palette-dragging' ); if ( event.dataTransfer ) { event.dataTransfer.effectAllowed = 'copy'; event.dataTransfer.setData( 'application/x-certloom-element', draggedPaletteKind ); event.dataTransfer.setData( 'text/plain', draggedPaletteKind ); } } );
			addButtons[ addIndex ].addEventListener( 'dragend', function () { draggedPaletteKind = ''; root.classList.remove( 'is-palette-dragging' ); pageEl.classList.remove( 'is-drop-target' ); } );
		}
		if ( removeButton ) { removeButton.addEventListener( 'click', removeSelected ); }
		if ( duplicateButton ) { duplicateButton.addEventListener( 'click', duplicateSelected ); }
		if ( copyButton ) { copyButton.addEventListener( 'click', function () { copySelectionToDesignerClipboard( false ); } ); }
		if ( cutButton ) { cutButton.addEventListener( 'click', function () { copySelectionToDesignerClipboard( true ); } ); }
		if ( pasteButton ) { pasteButton.addEventListener( 'click', pasteDesignerClipboard ); }
		for ( var modeIndex = 0; modeIndex < modeButtons.length; modeIndex++ ) { modeButtons[ modeIndex ].addEventListener( 'click', function () { applyDesignerMode( this.getAttribute( 'data-certloom-designer-mode-button' ), true ); } ); }
		if ( insertFocusButton ) { insertFocusButton.addEventListener( 'click', function () { var open = insertPanel ? insertPanel.hidden : true; if ( ! open ) { closeInsertPanel(); return; } if ( insertPanel ) { insertPanel.hidden = false; } insertFocusButton.setAttribute( 'aria-expanded', 'true' ); root.classList.add( 'is-insert-open' ); if ( paletteSearch ) { paletteSearch.focus(); } else { var firstInsert = root.querySelector( '[data-certloom-designer-add]:not([disabled])' ); if ( firstInsert ) { firstInsert.focus(); } } } ); }
		if ( paletteSearch ) { paletteSearch.addEventListener( 'input', function () { var query = String( paletteSearch.value || '' ).trim().toLowerCase(); for ( var pi = 0; pi < paletteItems.length; pi++ ) { var item = paletteItems[ pi ]; var label = String( item.getAttribute( 'data-certloom-palette-label' ) || '' ); item.hidden = !! query && label.indexOf( query ) === -1; } for ( var pc = 0; pc < paletteCategories.length; pc++ ) { paletteCategories[ pc ].hidden = ! paletteCategories[ pc ].querySelector( '[data-certloom-designer-palette-item]:not([hidden])' ); } } ); }
		if ( layersToggle ) { layersToggle.addEventListener( 'click', function () { root.classList.toggle( 'show-quick-layers' ); layersToggle.setAttribute( 'aria-expanded', root.classList.contains( 'show-quick-layers' ) ? 'true' : 'false' ); } ); }
		if ( quickTokenSelect && rawTokenInput ) { quickTokenSelect.addEventListener( 'change', function () { var value = String( quickTokenSelect.value || '' ); if ( '__custom__' === value ) { if ( customTokenWrap ) { customTokenWrap.classList.add( 'is-custom' ); } rawTokenInput.focus(); return; } if ( customTokenWrap ) { customTokenWrap.classList.remove( 'is-custom' ); } rawTokenInput.value = value; applyContentField( rawTokenInput ); } ); }
		for ( var contentIndex = 0; contentIndex < contentFields.length; contentIndex++ ) { contentFields[ contentIndex ].addEventListener( 'change', function () { applyContentField( this ); } ); }
		for ( var styleIndex = 0; styleIndex < styleFields.length; styleIndex++ ) { styleFields[ styleIndex ].addEventListener( 'change', function () { applyStyleField( this ); } ); }
		for ( var tableSourceIndex = 0; tableSourceIndex < tableSourceControls.length; tableSourceIndex++ ) { tableSourceControls[ tableSourceIndex ].addEventListener( 'change', function () { var element = selected(); var source = String( this.value || '' ); if ( ! element || 'data_table' !== element.type || isLocked( element ) || [ 'credential.results', 'credential.subjects' ].indexOf( source ) === -1 ) { setInspector(); return; } var proposed = clone( element ); proposed.source = source; proposed.columns = tableColumnsForSource( source ); commitTableElement( element, proposed, t( 'dataTableSourceUpdated', 'Data Table source and columns updated.' ) ); } ); }
		for ( var tableMaxIndex = 0; tableMaxIndex < tableMaxRowsControls.length; tableMaxIndex++ ) { tableMaxRowsControls[ tableMaxIndex ].addEventListener( 'change', function () { var element = selected(); var rows = Number( this.value ); if ( ! element || 'data_table' !== element.type || isLocked( element ) || ! Number.isInteger( rows ) || rows < 1 || rows > tableRowCapacity( element, !! element.show_header ) ) { showError( t( 'dataTableRowsInvalid', 'Maximum rows must fit the Table box with at least 4 mm per visible row.' ) ); setInspector(); return; } var proposed = clone( element ); proposed.max_rows = rows; commitTableElement( element, proposed, t( 'dataTableRowsUpdated', 'Data Table row limit updated.' ) ); } ); }
		for ( var tableHeaderIndex = 0; tableHeaderIndex < tableHeaderControls.length; tableHeaderIndex++ ) { tableHeaderControls[ tableHeaderIndex ].addEventListener( 'change', function () { var element = selected(); if ( ! element || ( 'table' !== element.type && 'data_table' !== element.type ) || isLocked( element ) ) { setInspector(); return; } var proposed = clone( element ); proposed.show_header = !! this.checked; var rows = 'data_table' === proposed.type ? Number( proposed.max_rows || 0 ) : ( proposed.rows || [] ).length; if ( rows > tableRowCapacity( proposed, proposed.show_header ) ) { showError( t( 'tableHeaderNeedsSpace', 'The Table needs more height before a header can be shown with this many rows.' ) ); setInspector(); return; } commitTableElement( element, proposed, t( 'tableHeaderUpdated', 'Table header updated.' ) ); } ); }
		for ( var tableColumnButtonIndex = 0; tableColumnButtonIndex < tableAddColumnButtons.length; tableColumnButtonIndex++ ) { tableAddColumnButtons[ tableColumnButtonIndex ].addEventListener( 'click', function () { var element = selected(); var section = this.closest( '[data-certloom-designer-content]' ); if ( ! element || isLocked( element ) || ! section || section.getAttribute( 'data-certloom-designer-content' ) !== element.type || [ 'table', 'data_table' ].indexOf( element.type ) === -1 || element.columns.length >= 12 ) { return; } var proposed = clone( element ), index = proposed.columns.length + 1, key = 'column_' + index; while ( proposed.columns.some( function ( c ) { return c.key === key; } ) ) { index++; key = 'column_' + index; } var column = { key: key, label: t( 'column', 'Column' ) + ' ' + index, width_pct: 10, align: 'left' }; if ( 'data_table' === proposed.type ) { var allowed = tableFieldsForSource( proposed.source ), used = proposed.columns.map( function ( item ) { return item.field; } ), field = allowed.find( function ( item ) { return used.indexOf( item ) === -1; } ); if ( ! field ) { showError( t( 'noMoreDataTableFields', 'All supported fields for this Data Table source are already in use.' ) ); return; } column.field = field; column.key = field; column.label = field.replace( /_/g, ' ' ); } proposed.columns.push( column ); rebalanceTableWidths( proposed.columns ); if ( 'table' === proposed.type ) { proposed.rows.forEach( function ( row ) { row[ column.key ] = ''; } ); } commitTableElement( element, proposed, t( 'tableColumnAdded', 'Table column added.' ) ); } ); }
		for ( var tableRowButtonIndex = 0; tableRowButtonIndex < tableAddRowButtons.length; tableRowButtonIndex++ ) { tableAddRowButtons[ tableRowButtonIndex ].addEventListener( 'click', function () { var element = selected(); if ( ! element || 'table' !== element.type || isLocked( element ) || element.rows.length >= tableRowCapacity( element, !! element.show_header ) ) { return; } var proposed = clone( element ), row = {}; proposed.columns.forEach( function ( column ) { row[ column.key ] = ''; } ); proposed.rows.push( row ); commitTableElement( element, proposed, t( 'tableRowAdded', 'Table row added.' ) ); } ); }
		for ( var quickStyleIndex = 0; quickStyleIndex < quickStyleFields.length; quickStyleIndex++ ) { quickStyleFields[ quickStyleIndex ].addEventListener( 'change', function () { applyQuickStyleValue( this.getAttribute( 'data-certloom-designer-quick-style' ), this.value ); } ); }
		if ( contextEditTextButton ) { contextEditTextButton.addEventListener( 'click', function () { var element = selected(); if ( element && 'text' === element.type && ! isLocked( element ) ) { startDirectTextEdit( element.id ); } } ); }
		if ( contextRichTextButton ) { contextRichTextButton.addEventListener( 'click', function () { var element = selected(); if ( element && 'rich_text' === element.type && ! isLocked( element ) ) { focusRichTextEditor(); } } ); }
		if ( contextTokenSelect ) { contextTokenSelect.addEventListener( 'change', function () { var element = selected(); if ( ! element || 'merge' !== element.type || isLocked( element ) ) { setInspector(); return; } var token = String( contextTokenSelect.value || '' ); if ( knownQuickTokens().indexOf( token ) === -1 && !/^[a-z][a-z0-9_.:-]{0,127}$/.test( token ) ) { setInspector(); return; } var before = snapshot(); var proposed = clone( element ); proposed.token = token; if ( updateElement( element.id, proposed, false ) ) { pushHistory( before ); render(); announce( t( 'dynamicFieldUpdated', 'Dynamic field updated.' ) ); } } ); }
		for ( var richAddIndex = 0; richAddIndex < richAddButtons.length; richAddIndex++ ) { richAddButtons[ richAddIndex ].addEventListener( 'click', function () { addRichRun( this.getAttribute( 'data-certloom-designer-rich-add' ) ); } ); }
		if ( richRunsContainer ) {
			richRunsContainer.addEventListener( 'pointerdown', function ( event ) { if ( event.target.closest( '[data-certloom-designer-rich-insert-field]' ) ) { event.preventDefault(); } } );
			richRunsContainer.addEventListener( 'click', function ( event ) {
				var action = event.target.closest( '[data-certloom-designer-rich-action]' ); if ( action ) { richRunAction( String( action.getAttribute( 'data-certloom-designer-rich-action' ) || '' ), Number( action.getAttribute( 'data-certloom-designer-rich-index' ) ) ); return; }
				var insert = event.target.closest( '[data-certloom-designer-rich-insert-field]' ); if ( insert ) { var index = Number( insert.getAttribute( 'data-certloom-designer-rich-insert-field' ) ); var row = insert.closest( '[data-certloom-designer-rich-run]' ); var textarea = row ? row.querySelector( 'textarea[data-certloom-designer-rich-value="text"]' ) : null; insertRichTokenAtCaret( index, textarea ); }
			} );
			richRunsContainer.addEventListener( 'change', function ( event ) { var value = event.target.closest( '[data-certloom-designer-rich-value]' ); if ( value ) { changeRichRunValue( value ); return; } var style = event.target.closest( '[data-certloom-designer-rich-style]' ); if ( style ) { changeRichRunStyle( style ); } } );
		}
		for ( var quickToggleIndex = 0; quickToggleIndex < quickToggleButtons.length; quickToggleIndex++ ) { quickToggleButtons[ quickToggleIndex ].addEventListener( 'click', function () { var key = this.getAttribute( 'data-certloom-designer-quick-toggle' ); if ( 'bold' === key ) { applyQuickStyleValue( 'font_weight', 'true' === this.getAttribute( 'aria-pressed' ) ? 400 : 700 ); } if ( 'italic' === key ) { applyQuickStyleValue( 'italic', 'true' !== this.getAttribute( 'aria-pressed' ) ); } } ); }
		for ( var quickAlignIndex = 0; quickAlignIndex < quickTextAlignButtons.length; quickAlignIndex++ ) { quickTextAlignButtons[ quickAlignIndex ].addEventListener( 'click', function () { applyQuickStyleValue( 'align', this.getAttribute( 'data-certloom-designer-quick-text-align' ) ); } ); }
		if ( colorPicker && colorTextField ) { colorPicker.addEventListener( 'change', function () { if ( colorPicker.disabled ) { return; } colorTextField.value = String( colorPicker.value || '#000000' ).toUpperCase(); applyStyleField( colorTextField ); } ); }
		for ( var mediaButtonIndex = 0; mediaButtonIndex < mediaButtons.length; mediaButtonIndex++ ) { mediaButtons[ mediaButtonIndex ].addEventListener( 'click', function () { var element = selected(); if ( ! element || isLocked( element ) || [ 'image', 'logo', 'signature' ].indexOf( element.type ) === -1 ) { return; } mediaFrame( function ( reference ) { var current = selected(); if ( ! current || isLocked( current ) ) { return; } var before = snapshot(); var proposed = clone( current ); proposed.asset = reference; if ( 'signature' === proposed.type ) { delete proposed.source; } if ( updateElement( current.id, proposed, false ) ) { pushHistory( before ); render(); announce( 'signature' === proposed.type ? t( 'signatureFixedAssetUpdated', 'Signature now uses the selected fixed image.' ) : t( 'assetUpdated', 'Image updated.' ) ); } } ); } ); }
		for ( var signatureSourceIndex = 0; signatureSourceIndex < signatureSourceButtons.length; signatureSourceIndex++ ) { signatureSourceButtons[ signatureSourceIndex ].addEventListener( 'click', function () {
			var element = selected(); if ( ! element || 'signature' !== element.type || isLocked( element ) ) { return; }
			if ( schemaVersion < 4 ) { showError( t( 'signatureSourceRequiresSchema4', 'Credential Signer signatures require certloom-layout v4 or newer.' ) ); return; }
			var before = snapshot(); var proposed = clone( element ); proposed.source = 'signer.signature'; delete proposed.asset;
			if ( updateElement( element.id, proposed, false ) ) { pushHistory( before ); render(); announce( t( 'signatureSignerSourceUpdated', 'Signature now uses the immutable credential Signer snapshot.' ) ); }
		} ); }
		if ( sampleDataControl ) { sampleDataControl.addEventListener( 'change', function () {
			var requested = String( sampleDataControl.value || '' ); if ( ! SAMPLE_DATA.profiles || ! SAMPLE_DATA.profiles[ requested ] ) { sampleDataControl.value = SAMPLE_DATA.default || 'standard'; requested = String( sampleDataControl.value || 'standard' ); }
			activeSampleProfile = requested; render(); announce( t( 'sampleDataChanged', 'Designer sample data changed. Saved certificate data is unchanged.' ) );
		} ); }
		for ( var previewModeIndex = 0; previewModeIndex < previewModeButtons.length; previewModeIndex++ ) {
			previewModeButtons[ previewModeIndex ].addEventListener( 'click', function () { setPreviewMode( String( this.getAttribute( 'data-certloom-designer-preview-mode' ) || 'sample' ), true ); } );
		}
		syncPreviewModeButtons();
		if ( studioNameDisplay ) { studioNameDisplay.addEventListener( 'click', beginStudioRename ); }
		if ( studioNameInput ) {
			studioNameInput.addEventListener( 'keydown', function ( event ) { if ( 'Enter' === event.key ) { event.preventDefault(); finishStudioRename( true ); } else if ( 'Escape' === event.key ) { event.preventDefault(); finishStudioRename( false ); } } );
			studioNameInput.addEventListener( 'blur', function () { window.setTimeout( function () { finishStudioRename( true ); }, 0 ); } );
		}
		for ( var studioProfileIndex = 0; studioProfileIndex < studioProfileInputs.length; studioProfileIndex++ ) {
			studioProfileInputs[ studioProfileIndex ].addEventListener( 'change', function () { syncStudioProfileSummary(); if ( studioProfileStatus ) { studioProfileStatus.textContent = t( 'studioProfilesPending', 'Not saved yet' ); } if ( studioProfileTimer ) { window.clearTimeout( studioProfileTimer ); } studioProfileTimer = window.setTimeout( function () { studioProfileTimer = null; saveStudioProfiles(); }, 260 ); } );
		}
		syncStudioProfileSummary();
		if ( studioToast && ! studioToast.hidden && String( studioToast.textContent || '' ).trim() ) { window.setTimeout( function () { if ( studioToast ) { studioToast.hidden = true; } }, 3200 ); }
		for ( var saveIntentIndex = 0; saveIntentIndex < saveButtons.length; saveIntentIndex++ ) {
			saveButtons[ saveIntentIndex ].addEventListener( 'click', function () { setSaveIntent( this.getAttribute( 'data-certloom-designer-save-intent' ) || ( this.name === 'studio_intent' ? this.value : 'draft' ) ); } );
		}
		if ( resetButton ) { resetButton.addEventListener( 'click', function () { if ( root.classList.contains( 'has-unsaved-changes' ) && ! window.confirm( t( 'restoreConfirm', 'Discard current unsaved changes and restore the last saved design?' ) ) ) { return; } if ( directTextEditor ) { closeDirectTextEditor( false ); } layout = clone( savedLayout ); designerState = clone( savedState ); selectOnly( '' ); history = []; future = []; activeGuides = { x: null, y: null }; activeSpacingGuides = []; safeAreaVisible = false; panToolActive = false; spacePanHeld = false; closeContextMenu(); render(); fitWorkspace(); discardAutosave( false ); announce( t( 'unsavedChangesReset', 'Unsaved design changes reset.' ) ); } ); }
		if ( autosaveDiscardButton ) { autosaveDiscardButton.addEventListener( 'click', function () { discardAutosave( true ); } ); }
		for ( var fieldIndex = 0; fieldIndex < fieldInputs.length; fieldIndex++ ) {
			fieldInputs[ fieldIndex ].addEventListener( 'change', function () {
				var element = selected(); if ( ! element || isLocked( element ) ) { setInspector(); return; } var before = snapshot(); var proposed = clone( element ); var value = Number( this.value ); if ( ! isFinite( value ) ) { setInspector(); return; }
				var key = this.getAttribute( 'data-certloom-designer-field' ); var map = { x: 'x_mm', y: 'y_mm', width: 'width_mm', height: 'height_mm', rotation: 'rotation_deg' };
				proposed[ map[ key ] ] = 'rotation' === key ? normalizedAngle( value ) : round( value, 3 );
				if ( updateElement( element.id, proposed, false ) ) { pushHistory( before ); render(); } else { showError( t( 'geometryBoundary', 'That geometry would violate the canonical page boundary. The previous value was kept.' ) ); setInspector(); }
			} );
		}
		if ( conflictReviewButton ) { conflictReviewButton.addEventListener( 'click', function () { if ( conflictBanner ) { conflictBanner.hidden = true; } focusSelectedCanvasElement(); } ); }
		if ( conflictReloadButton ) { conflictReloadButton.addEventListener( 'click', function () {
			var warning = t( 'reloadConflictConfirm', 'Reload the saved template and discard the current browser edits? This cannot be undone.' );
			if ( window.confirm && ! window.confirm( warning ) ) { return; }
			isSubmitting = true; window.location.reload();
		} ); }
		document.addEventListener( 'keydown', keyboard, true ); document.addEventListener( 'keyup', keyboardUp, true );
		form.addEventListener( 'submit', function ( event ) {
			saveIntentFromSubmitter( event.submitter || null );
			showError( '' );
			if ( directTextEditor ) { closeDirectTextEditor( true ); if ( directTextEditor ) { event.preventDefault(); blockSave( t( 'directTextInvalid', 'Direct text must stay within the canonical 20,000-byte text limit.' ) ); return; } }
			if ( autosaveTimer ) { window.clearTimeout( autosaveTimer ); autosaveTimer = null; }
			var p = page(); var list = elements();
			for ( var i = 0; i < list.length; i++ ) { if ( 'barcode' === list[ i ].type && schemaVersion < 2 ) { event.preventDefault(); blockSave( t( 'barcodeDeferred', 'Barcode elements require certloom-layout v2. Upgrade this draft first.' ) ); return; } if ( ! elementGeometryValid( list[ i ], p ) ) { event.preventDefault(); blockSave( t( 'invalidGeometrySave', 'Designer found invalid element geometry and stopped the save.' ) ); return; } }
			jsonField.value = JSON.stringify( layout ); if ( stateField ) { stateField.value = JSON.stringify( designerState ); } if ( autosaveSessionField ) { autosaveSessionField.value = autosaveSessionId; } isSubmitting = true; setSaveFeedback( t( 'savingRevision', 'Saving revision…' ), 'saving', true ); announce( t( 'savingRevision', 'Saving revision…' ) );
		} );
		window.addEventListener( 'beforeunload', function ( event ) { if ( isSubmitting || ! root.classList.contains( 'has-unsaved-changes' ) ) { return; } event.preventDefault(); event.returnValue = ''; } );

		enhancePropertySections();
		applyDesignerMode( rememberedDesignerMode(), false );
		syncStudioViewportHeight();
		window.addEventListener( 'resize', syncStudioViewportHeight );
		if ( window.visualViewport && window.visualViewport.addEventListener ) { window.visualViewport.addEventListener( 'resize', syncStudioViewportHeight ); }
		if ( 'ResizeObserver' in window ) { var studioResizeObserver = new ResizeObserver( function () { if ( Math.abs( relativeZoomPercent() - 100 ) <= 1 ) { fitWorkspace(); } } ); studioResizeObserver.observe( workspace ); }
		syncPreviewModeButtons(); render(); if ( recovered ) { setAutosaveStatus( t( 'autosaveRecovered', 'Recovered unsaved Designer changes from your latest safe checkpoint.' ), 'recovered' ); announce( t( 'autosaveRecovered', 'Recovered unsaved Designer changes from your latest safe checkpoint.' ) ); } else if ( autosaveNeedsCleanup ) { setAutosaveStatus( t( 'savedDraft', 'Saved draft' ), 'clean' ); window.setTimeout( function () { discardAutosave( false ); }, 0 ); } window.setTimeout( function () { fitWorkspace(); }, 0 );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var roots = document.querySelectorAll( '[data-certloom-designer]' );
		for ( var i = 0; i < roots.length; i++ ) { initDesigner( roots[ i ] ); }
	} );
} )();
