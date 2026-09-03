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
		var sizeControl = root.querySelector( '[data-certloom-designer-page-size]' );
		var orientationControl = root.querySelector( '[data-certloom-designer-orientation]' );
		var zoomControl = root.querySelector( '[data-certloom-designer-zoom]' );
		var zoomOutput = root.querySelector( '[data-certloom-designer-zoom-output]' );
		var fitButton = root.querySelector( '[data-certloom-designer-fit]' );
		var resetButton = root.querySelector( '[data-certloom-designer-reset]' );
		var undoButton = root.querySelector( '[data-certloom-designer-undo]' );
		var redoButton = root.querySelector( '[data-certloom-designer-redo]' );
		var snapControl = root.querySelector( '[data-certloom-designer-snap]' );
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
		var lockStatus = root.querySelector( '[data-certloom-designer-lock-status]' );
		var liveRegion = root.querySelector( '[data-certloom-designer-live]' );
		var error = root.querySelector( '[data-certloom-designer-error]' );
		var fieldInputs = root.querySelectorAll( '[data-certloom-designer-field]' );
		var addButtons = root.querySelectorAll( '[data-certloom-designer-add]' );
		var removeButton = root.querySelector( '[data-certloom-designer-remove]' );
		var duplicateButton = root.querySelector( '[data-certloom-designer-duplicate]' );
		var modeButtons = root.querySelectorAll( '[data-certloom-designer-mode-button]' );
		var previewScrollButton = root.querySelector( '[data-certloom-designer-preview-scroll]' );
		var publicationScrollButton = root.querySelector( '[data-certloom-designer-publication-scroll]' );
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
		var distributionGroup = root.querySelector( '[data-certloom-designer-distribute-group]' );
		var selectionStatus = root.querySelector( '[data-certloom-designer-selection-status]' );
		var spacingStatus = root.querySelector( '[data-certloom-designer-spacing-status]' );
		var groupInspector = root.querySelector( '[data-certloom-designer-group]' );
		var groupCount = root.querySelector( '[data-certloom-designer-group-count]' );
		var quickTextBar = root.querySelector( '[data-certloom-designer-quick-text]' );
		var quickStyleFields = root.querySelectorAll( '[data-certloom-designer-quick-style]' );
		var quickToggleButtons = root.querySelectorAll( '[data-certloom-designer-quick-toggle]' );
		var quickTextAlignButtons = root.querySelectorAll( '[data-certloom-designer-quick-text-align]' );
		var colorPicker = root.querySelector( '[data-certloom-designer-color-picker]' );
		var colorTextField = root.querySelector( '[data-certloom-designer-style-field="color"]' );
		var mediaButton = root.querySelector( '[data-certloom-designer-media]' );
		var recoveryField = root.querySelector( '[data-certloom-designer-recovery-json]' );
		var recoveryStateField = root.querySelector( '[data-certloom-designer-recovery-state-json]' );
		var autosaveStatusEl = root.querySelector( '[data-certloom-designer-autosave-status]' );
		var autosaveDiscardButton = root.querySelector( '[data-certloom-designer-autosave-discard]' );
		var autosaveSessionField = root.querySelector( '[data-certloom-designer-autosave-session-field]' );
		if ( ! form || ! jsonField || ! pageEl || ! workspace ) { return; }
		if ( ! registryReady() ) { showError( t( 'elementRegistryUnavailable', 'The Designer could not load its editing controls. Reload the page before editing this design.' ) ); root.classList.add( 'has-registry-error' ); return; }

		var savedLayout;
		try { savedLayout = JSON.parse( jsonField.value ); } catch ( parseError ) { showError( t( 'currentLayoutLoadError', 'The current canonical layout could not be loaded into Designer.' ) ); return; }
		var savedState = { version: 1, locked_ids: [], snap_enabled: true };
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
		var zoom = Number( zoomControl ? zoomControl.value : 75 ) / 100;
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
		var lastElementPointer = { id: '', at: 0 };

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
			window.setTimeout( function () { fitWorkspace( true ); }, 0 );
		}
		function scrollDesignerTarget( selector ) { var target = document.querySelector( selector ); if ( target && target.scrollIntoView ) { target.scrollIntoView( { behavior: 'smooth', block: 'start' } ); } }
		var isSubmitting = false;
		var autosaveTimer = null;
		var autosavePending = false;
		var autosaveQueued = false;
		var autosaveConflict = 'stale' === autosaveStatus || 'invalid' === autosaveStatus;
		var hasServerAutosave = 'ready' === autosaveStatus;
		var templateId = Number( root.getAttribute( 'data-template-id' ) || 0 );
		var baseRevisionId = Number( root.getAttribute( 'data-saved-revision' ) || 0 );
		var baseRevisionSha256 = root.getAttribute( 'data-saved-sha256' ) || '';
		var autosaveNonce = root.getAttribute( 'data-autosave-nonce' ) || '';
		var autosaveSessionId = root.getAttribute( 'data-autosave-session' ) || secureUuidV4();
		var ajaxUrl = root.getAttribute( 'data-ajax-url' ) || '';
		var lastAutosaveFingerprint = recovered ? stable( { layout: layout, state: designerState } ) : '';
		if ( autosaveSessionField ) { autosaveSessionField.value = autosaveSessionId; }

		function normalizeState( input ) {
			var result = { version: 1, locked_ids: [], snap_enabled: true };
			var ids = {};
			var stateLayout = layout && Array.isArray( layout.elements ) ? layout : savedLayout;
			var list = stateLayout && Array.isArray( stateLayout.elements ) ? stateLayout.elements : [];
			for ( var i = 0; i < list.length; i++ ) { ids[ list[ i ].id ] = true; }
			if ( input && false === input.snap_enabled ) { result.snap_enabled = false; }
			if ( input && Array.isArray( input.locked_ids ) ) {
				for ( var j = 0; j < input.locked_ids.length; j++ ) { if ( ids[ input.locked_ids[ j ] ] ) { result.locked_ids.push( input.locked_ids[ j ] ); } }
			}
			result.locked_ids.sort();
			return result;
		}

		if ( recovered ) { designerState = normalizeState( designerState ); lastAutosaveFingerprint = stable( { layout: layout, state: designerState } ); }

		function setAutosaveStatus( message, state ) {
			if ( autosaveStatusEl ) { autosaveStatusEl.textContent = message || ''; autosaveStatusEl.setAttribute( 'data-state', state || '' ); }
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
		function discardAutosave( announceResult ) {
			if ( autosaveTimer ) { window.clearTimeout( autosaveTimer ); autosaveTimer = null; }
			if ( ! hasServerAutosave && ! autosaveConflict ) { return; }
			autosaveRequest( 'certloom_template_designer_autosave_discard', autosaveSessionId ? { session_id: autosaveSessionId } : null, false ).then( function ( payload ) {
				if ( payload && payload.success ) { hasServerAutosave = false; autosaveConflict = false; lastAutosaveFingerprint = ''; setAutosaveStatus( t( 'savedDraft', 'Saved draft' ), 'clean' ); if ( autosaveDiscardButton ) { autosaveDiscardButton.disabled = true; } if ( announceResult ) { announce( t( 'autosaveDiscarded', 'Recovery checkpoint discarded.' ) ); } }
				else { setAutosaveStatus( t( 'autosaveConflict', 'Autosave stopped because this Template changed in another session. Your browser changes were not overwritten; reload only after preserving anything you still need.' ), 'conflict' ); if ( announceResult ) { showError( t( 'autosaveConflict', 'Autosave stopped because this Template changed in another session. Your browser changes were not overwritten; reload only after preserving anything you still need.' ) ); } }
			} ).catch( function () { if ( announceResult ) { showError( t( 'autosaveFailed', 'Recovery autosave is temporarily unavailable. Manual save remains available.' ) ); } } );
		}
		function runAutosave() {
			if ( autosaveConflict || isSubmitting || ! root.classList.contains( 'has-unsaved-changes' ) ) { return; }
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
					if ( 409 === payload.httpStatus || 'certloom_designer_autosave_conflict' === code || 'certloom_designer_autosave_session_conflict' === code ) { autosaveConflict = true; root.classList.add( 'has-autosave-conflict' ); setAutosaveStatus( t( 'autosaveConflict', 'Autosave stopped because this Template changed in another session. Your browser changes were not overwritten; reload only after preserving anything you still need.' ), 'conflict' ); showError( t( 'autosaveConflict', 'Autosave stopped because this Template changed in another session. Your browser changes were not overwritten; reload only after preserving anything you still need.' ) ); }
					else { setAutosaveStatus( t( 'autosaveFailed', 'Recovery autosave is temporarily unavailable. Manual save remains available.' ), 'error' ); }
				}
			} ).catch( function () { setAutosaveStatus( t( 'autosaveFailed', 'Recovery autosave is temporarily unavailable. Manual save remains available.' ), 'error' ); window.setTimeout( function () { scheduleAutosave(); }, AUTOSAVE_RETRY_MS ); } ).then( function () {
				autosavePending = false; if ( autosaveQueued ) { autosaveQueued = false; scheduleAutosave(); }
			} );
		}
		function scheduleAutosave() {
			if ( autosaveConflict || isSubmitting ) { return; }
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
			activeGuides = { x: null, y: null }; syncDirty(); render();
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
		function syncDirty() {
			var dirty = stable( savedLayout ) !== stable( layout ) || stable( savedState ) !== stable( designerState );
			if ( dirtyBadge ) { dirtyBadge.textContent = dirty ? t( 'unsavedChanges', 'Unsaved changes' ) : t( 'savedDraft', 'Saved draft' ); dirtyBadge.classList.toggle( 'is-dirty', dirty ); }
			root.classList.toggle( 'has-unsaved-changes', dirty );
			if ( dirty ) { scheduleAutosave(); } else if ( hasServerAutosave && ! autosavePending ) { discardAutosave( false ); }
		}
		function syncControls() {
			var p = page(); if ( ! p ) { return; }
			if ( sizeControl ) { sizeControl.value = p.size; }
			if ( orientationControl ) { orientationControl.value = p.orientation; }
			if ( zoomControl ) { zoomControl.value = String( Math.round( zoom * 100 ) ); }
			if ( zoomOutput ) { zoomOutput.textContent = Math.round( zoom * 100 ) + '%'; }
			if ( snapControl ) { snapControl.checked = !! designerState.snap_enabled; }
			if ( pageReadout ) { pageReadout.textContent = 'layout v' + schemaVersion + ' · ' + p.size + ' · ' + p.orientation + ' · ' + round( p.width_mm, 1 ) + ' × ' + round( p.height_mm, 1 ) + ' mm · ' + elements().length + ' ' + ( 1 === elements().length ? t( 'elementCountSingular', 'element' ) : t( 'elementCountPlural', 'elements' ) ); }
			syncHistoryButtons();
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
				lockButton.disabled = ! element || multi; lockButton.setAttribute( 'aria-pressed', locked ? 'true' : 'false' ); lockButton.textContent = locked ? t( 'unlockElement', 'Unlock element' ) : t( 'lockElement', 'Lock element' );
			}
			if ( removeButton ) { removeButton.disabled = ! selection.length || groupLocked; }
			if ( duplicateButton ) { duplicateButton.disabled = ! element || locked || multi || elements().length >= 500; }
			syncSelectionStatus(); syncQuickTextControls();
		}
		function contentGroup( element ) {
			var definition = element ? registryType( element.type ) : null;
			return definition && definition.content_group ? definition.content_group : '';
		}
		function knownQuickTokens() { return [ 'recipient.name', 'credential.display_number', 'program.name', 'credential.issue_date', 'credential.expiry_date', 'credential.grade', 'issuer.name', 'credential.status' ]; }
		function dynamicFieldSample( token ) {
			var samples = { 'recipient.name': t( 'sampleRecipientName', 'Alexandria Noor Rahman' ), 'credential.display_number': t( 'sampleCertificateNumber', 'CERT-2026-001' ), 'program.name': t( 'sampleProgramName', 'Professional Credential Program' ), 'credential.issue_date': t( 'sampleIssueDate', '28 August 2026' ), 'credential.expiry_date': t( 'sampleExpiryDate', '28 August 2027' ), 'credential.grade': t( 'sampleGrade', 'A' ), 'issuer.name': t( 'sampleIssuerName', 'Northstar Learning Institute' ), 'credential.status': t( 'sampleStatus', 'Valid' ) };
			return Object.prototype.hasOwnProperty.call( samples, token ) ? samples[ token ] : '{{ ' + escapeText( token ) + ' }}';
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
		function setInspector() {
			var selection = selectedElements(); var element = selected();
			for ( var sectionIndex = 0; sectionIndex < contentSections.length; sectionIndex++ ) { contentSections[ sectionIndex ].hidden = true; }
			if ( groupInspector ) { groupInspector.hidden = selection.length < 2; }
			if ( selection.length > 1 ) {
				if ( emptyInspector ) { emptyInspector.hidden = true; }
				if ( fieldsInspector ) { fieldsInspector.hidden = true; }
				syncProductivityControls(); return;
			}
			if ( ! element ) {
				if ( emptyInspector ) { emptyInspector.hidden = false; }
				if ( fieldsInspector ) { fieldsInspector.hidden = true; }
				syncProductivityControls(); return;
			}
			var locked = isLocked( element );
			if ( emptyInspector ) { emptyInspector.hidden = true; }
			if ( fieldsInspector ) { fieldsInspector.hidden = false; }
			if ( typeOutput ) { typeOutput.textContent = typeLabel( element.type ).toUpperCase(); }
			if ( idOutput ) { idOutput.textContent = element.id || ''; }
			if ( lockStatus ) { lockStatus.textContent = locked ? t( 'lockedForAuthor', 'Locked for this author' ) : t( 'editable', 'Editable' ); lockStatus.classList.toggle( 'is-locked', locked ); }
			for ( var i = 0; i < fieldInputs.length; i++ ) {
				var key = fieldInputs[ i ].getAttribute( 'data-certloom-designer-field' );
				var map = { x: 'x_mm', y: 'y_mm', width: 'width_mm', height: 'height_mm', rotation: 'rotation_deg' };
				fieldInputs[ i ].value = round( Number( element[ map[ key ] ] || 0 ), 3 ); fieldInputs[ i ].disabled = locked;
			}
			var group = contentGroup( element );
			for ( var section = 0; section < contentSections.length; section++ ) {
				if ( contentSections[ section ].getAttribute( 'data-certloom-designer-content' ) === group ) { contentSections[ section ].hidden = false; }
			}
			for ( var field = 0; field < contentFields.length; field++ ) {
				var contentKey = contentFields[ field ].getAttribute( 'data-certloom-designer-content-field' );
				if ( null == contentKey ) { continue; }
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
			if ( mediaButton ) { mediaButton.disabled = locked || ! elementDefinition || ! elementDefinition.media; }
			syncQuickToken();
			syncProductivityControls();
		}

		function elementTitle( element ) {
			var preview = previewKind( element );
			if ( 'text' === preview ) { return escapeText( element.text ); }
			if ( 'merge' === preview ) { return 'quick' === designerMode ? dynamicFieldSample( String( element.token || '' ) ) : '{{ ' + escapeText( element.token ) + ' }}'; }
			if ( 'qr' === preview ) { return t( 'qr', 'QR' ); }
			if ( 'barcode' === preview ) { return t( 'barcode', 'Barcode' ) + ' · ' + escapeText( element.source || 'credential.display_number' ); }
			if ( 'list' === preview ) { return t( 'list', 'List' ) + ' · ' + ( Array.isArray( element.items ) ? element.items.length : 0 ) + ' ' + t( 'items', 'items' ); }
			if ( 'shape' === preview ) { return escapeText( element.shape || t( 'shape', 'Shape' ) ); }
			if ( 'line' === preview ) { return t( 'line', 'Line' ); }
			if ( 'asset' === preview ) { return typeLabel( element.type ) + ' · ' + escapeText( element.asset ); }
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
			var id = secureUuidV4(); if ( ! id ) { showError( t( 'uuidUnavailable', 'This browser cannot safely add a new design element. Update the browser and try again.' ) ); return null; }
			var p = page(), defaults = palette.defaults || {}, geometry;
			if ( defaults.full_page ) { geometry = { x_mm: 0, y_mm: 0, width_mm: Number( p.width_mm ), height_mm: Number( p.height_mm ) }; }
			else { geometry = geometryAtPoint( Number( palette.width_mm || 80 ), Number( palette.height_mm || 15 ), point ); }
			var element = { id: id, type: palette.type, x_mm: geometry.x_mm, y_mm: geometry.y_mm, width_mm: geometry.width_mm, height_mm: geometry.height_mm, z: elements().length, rotation_deg: 0, opacity: 1 };
			for ( var key in defaults ) {
				if ( ! Object.prototype.hasOwnProperty.call( defaults, key ) || 'full_page' === key || 'text_i18n' === key || 'items_i18n' === key || 'style_preset' === key ) { continue; }
				element[ key ] = clone( defaults[ key ] );
			}
			if ( defaults.text_i18n ) { element.text = t( defaults.text_i18n, 'Text' ); }
			if ( Array.isArray( defaults.items_i18n ) ) { element.items = defaults.items_i18n.map( function ( key ) { return t( key, key ); } ); }
			if ( defaults.style_preset ) { element.style = defaultTextStyle(); if ( 'list' === defaults.style_preset ) { element.style.align = 'start'; element.style.vertical_align = 'top'; element.style.overflow = 'wrap'; } }
			if ( palette.requires_media ) { element.asset = assetReference || ''; }
			return element;
		}
		function appendElement( kind, assetReference, point ) {
			if ( elements().length >= 500 ) { showError( t( 'elementLimit', 'This Template already contains the maximum 500 elements.' ) ); return; }
			var element = buildNewElement( kind, assetReference, point ); if ( ! element ) { return; }
			var before = snapshot();
			if ( point && ! ( paletteDefinition( kind ) || {} ).full_page ) { var snapped = snapDragged( clampDragged( clone( element ) ) ); element.x_mm = snapped.x_mm; element.y_mm = snapped.y_mm; activeGuides = { x: null, y: null }; }
			elements().push( element ); normalizeZOrder(); selectOnly( element.id ); pushHistory( before ); render(); announce( point ? t( 'elementDropped', 'Element placed on the canvas.' ) : t( 'elementAdded', 'Element added.' ) );
		}
		function removeSelected() {
			var ids = selectionIds(); if ( ! ids.length ) { return; }
			if ( selectionHasLocked() ) { showError( t( 'deleteLockedElement', 'Unlock the selected element or selection before removing it.' ) ); return; }
			var before = snapshot(); layout.elements = elements().filter( function ( item ) { return ids.indexOf( item.id ) === -1; } ); designerState.locked_ids = designerState.locked_ids.filter( function ( id ) { return ids.indexOf( id ) === -1; } ); selectOnly( '' ); normalizeZOrder(); pushHistory( before ); render(); announce( ids.length > 1 ? t( 'elementsRemoved', 'Selected elements removed.' ) : t( 'elementRemoved', 'Element removed.' ) );
		}
		function duplicateSelected() {
			var element = selected(); if ( ! element ) { return; }
			if ( isLocked( element ) ) { showError( t( 'duplicateLockedElement', 'Unlock this element before duplicating it.' ) ); return; }
			if ( elements().length >= 500 ) { showError( t( 'elementLimit', 'This Template already contains the maximum 500 elements.' ) ); return; }
			var id = secureUuidV4(); if ( ! id ) { showError( t( 'uuidUnavailable', 'This browser cannot safely add a new design element. Update the browser and try again.' ) ); return; }
			var before = snapshot(); var copy = clone( element ); copy.id = id; copy.z = elements().length; copy.x_mm = round( copy.x_mm + 4, 3 ); copy.y_mm = round( copy.y_mm + 4, 3 ); copy = clampDragged( copy );
			elements().push( copy ); normalizeZOrder(); selectOnly( copy.id ); pushHistory( before ); render(); announce( t( 'elementDuplicated', 'Element duplicated.' ) );
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
			if ( palette.requires_media ) { mediaFrame( function ( reference ) { appendElement( kind, reference, point ); } ); return; }
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
			} else if ( registryType( element.type ) && [ 'shape', 'line' ].indexOf( registryType( element.type ).style_group ) !== -1 ) {
				if ( 'fill' === key && 'shape' === element.type ) { value = canonicalColor( raw ); if ( ! value ) { showError( t( 'invalidColor', 'Colors must use #RRGGBB or #RRGGBBAA.' ) ); setInspector(); return; } proposed.fill = value; }
				else if ( 'stroke' === key ) { value = canonicalColor( raw ); if ( ! value ) { showError( t( 'invalidColor', 'Colors must use #RRGGBB or #RRGGBBAA.' ) ); setInspector(); return; } proposed.stroke = value; }
				else if ( 'stroke_width_mm' === key ) { value = Number( raw ); var minStroke = 'line' === element.type ? 0.001 : 0; if ( ! isFinite( value ) || value < minStroke || value > 20 ) { showError( t( 'invalidStyleValue', 'Invalid style value.' ) ); setInspector(); return; } proposed.stroke_width_mm = round( value, 3 ); }
				else { return; }
			} else { return; }
			var before = snapshot(); if ( updateElement( element.id, proposed, false ) ) { pushHistory( before ); render(); announce( t( 'styleUpdated', 'Element styling updated.' ) ); }
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
			rotate.className = 'certloom-template-designer-rotate'; rotate.setAttribute( 'data-certloom-designer-rotate', '1' ); rotate.setAttribute( 'aria-hidden', 'true' ); node.appendChild( rotate );
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
		function renderLayers() {
			if ( ! layersContainer ) { return; }
			layersContainer.innerHTML = '';
			var list = elements().slice().sort( function ( a, b ) { return Number( b.z || 0 ) - Number( a.z || 0 ); } );
			for ( var i = 0; i < list.length; i++ ) {
				( function ( element ) {
					var row = document.createElement( 'div' ); row.className = 'certloom-template-designer-layer'; row.classList.toggle( 'is-selected', isSelectedId( element.id ) ); row.classList.toggle( 'is-primary-selected', element.id === selectedId ); row.classList.toggle( 'is-locked', isLocked( element ) );
					var select = document.createElement( 'button' ); select.type = 'button'; select.className = 'button button-small certloom-template-designer-layer-select'; select.setAttribute( 'data-certloom-designer-layer-select', element.id ); select.setAttribute( 'aria-pressed', isSelectedId( element.id ) ? 'true' : 'false' ); select.textContent = elementTitle( element ) || typeLabel( element.type );
					var meta = document.createElement( 'small' ); meta.textContent = String( element.type || 'element' ).toUpperCase() + ' · z ' + Number( element.z || 0 ); select.appendChild( meta ); row.appendChild( select );
					var lock = document.createElement( 'button' ); lock.type = 'button'; lock.className = 'button button-small certloom-template-designer-layer-lock'; lock.setAttribute( 'data-certloom-designer-layer-lock', element.id ); lock.setAttribute( 'aria-pressed', isLocked( element ) ? 'true' : 'false' ); lock.setAttribute( 'aria-label', ( isLocked( element ) ? t( 'unlock', 'Unlock' ) : t( 'lock', 'Lock' ) ) + ' ' + elementTitle( element ) ); lock.textContent = isLocked( element ) ? t( 'locked', 'Locked' ) : t( 'lock', 'Lock' ); row.appendChild( lock );
					layersContainer.appendChild( row );
				} )( list[ i ] );
			}
		}
		function render() {
			var p = page(); if ( ! p ) { showError( t( 'canonicalPageRequired', 'Designer requires a canonical page definition.' ) ); return; }
			pageEl.innerHTML = '';
			pageEl.style.width = px( p.width_mm, zoom ) + 'px'; pageEl.style.height = px( p.height_mm, zoom ) + 'px';
			var list = elements();
			for ( var i = 0; i < list.length; i++ ) {
				var element = list[ i ]; var node = document.createElement( 'button' ); node.type = 'button';
				node.className = 'certloom-template-designer-element';
				node.setAttribute( 'data-certloom-designer-element', element.id ); node.setAttribute( 'aria-label', typeLabel( element.type ) + ': ' + elementTitle( element ) + ( isLocked( element ) ? ' (' + t( 'lockedSuffix', 'locked' ) + ')' : '' ) );
				node.style.left = px( element.x_mm, zoom ) + 'px'; node.style.top = px( element.y_mm, zoom ) + 'px'; node.style.width = px( element.width_mm, zoom ) + 'px'; node.style.height = px( element.height_mm, zoom ) + 'px'; node.style.zIndex = String( 100001 + Number( element.z || 0 ) ); node.style.opacity = String( null == element.opacity ? 1 : element.opacity ); node.style.transform = 'rotate(' + Number( element.rotation_deg || 0 ) + 'deg)';
				node.setAttribute( 'aria-pressed', isSelectedId( element.id ) ? 'true' : 'false' );
				if ( isSelectedId( element.id ) ) { node.classList.add( 'is-multi-selected' ); }
				if ( element.id === selectedId ) { node.classList.add( 'is-selected', 'is-primary-selected' ); }
				if ( isLocked( element ) ) { node.classList.add( 'is-locked' ); }
				styleContent( node, element ); addHandles( node, element ); pageEl.appendChild( node );
			}
			renderGuides(); renderLayers(); syncControls(); setInspector(); syncDirty(); showError( '' );
		}
		function updateElement( id, proposed, mark ) {
			var list = elements(); var p = page();
			for ( var i = 0; i < list.length; i++ ) {
				if ( list[ i ].id !== id ) { continue; }
				if ( ! elementGeometryValid( proposed, p ) ) { return false; }
				list[ i ] = proposed; if ( mark ) { syncDirty(); } render(); return true;
			}
			return false;
		}
		function updateElementSet( proposedById, mark ) {
			var list = elements(); var p = page(); var indexes = [];
			for ( var i = 0; i < list.length; i++ ) {
				if ( ! Object.prototype.hasOwnProperty.call( proposedById, list[ i ].id ) ) { continue; }
				if ( ! elementGeometryValid( proposedById[ list[ i ].id ], p ) ) { return false; }
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
		function snapDragged( element, bypass ) {
			activeGuides = { x: null, y: null };
			if ( bypass || ! designerState.snap_enabled ) { return element; }
			var bounds = rotatedBounds( element );
			var sx = closestSnap( [ bounds.minX, bounds.centerX, bounds.maxX ], snapTargets( 'x', element.id ) );
			var sy = closestSnap( [ bounds.minY, bounds.centerY, bounds.maxY ], snapTargets( 'y', element.id ) );
			var candidate = clone( element );
			if ( sx ) { candidate.x_mm = round( Number( candidate.x_mm ) + sx.delta, 3 ); activeGuides.x = round( sx.target, 3 ); }
			if ( sy ) { candidate.y_mm = round( Number( candidate.y_mm ) + sy.delta, 3 ); activeGuides.y = round( sy.target, 3 ); }
			candidate = clampDragged( candidate );
			if ( elementGeometryValid( candidate, page() ) ) { return candidate; }
			activeGuides = { x: null, y: null }; return element;
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
			activeGuides = { x: null, y: null }; if ( bypass || ! designerState.snap_enabled ) { return map; }
			var ids = Object.keys( map ); var bounds = combinedBounds( selectionArrayFromMap( map ) ); if ( ! bounds ) { return map; }
			var sx = closestSnap( [ bounds.minX, bounds.centerX, bounds.maxX ], snapTargets( 'x', ids ) );
			var sy = closestSnap( [ bounds.minY, bounds.centerY, bounds.maxY ], snapTargets( 'y', ids ) );
			if ( sx || sy ) { for ( var id in map ) { if ( Object.prototype.hasOwnProperty.call( map, id ) ) { if ( sx ) { map[ id ].x_mm = round( Number( map[ id ].x_mm ) + sx.delta, 3 ); } if ( sy ) { map[ id ].y_mm = round( Number( map[ id ].y_mm ) + sy.delta, 3 ); } } } }
			if ( sx ) { activeGuides.x = round( sx.target, 3 ); } if ( sy ) { activeGuides.y = round( sy.target, 3 ); }
			return clampSelectionMap( map );
		}
		function pointerStart( event ) {
			var node = event.target.closest( '[data-certloom-designer-element]' ); if ( ! node || ! pageEl.contains( node ) ) { return; }
			var id = node.getAttribute( 'data-certloom-designer-element' ); var additive = event.shiftKey || event.ctrlKey || event.metaKey;
			if ( additive && ! event.target.hasAttribute( 'data-certloom-designer-resize' ) && ! event.target.hasAttribute( 'data-certloom-designer-rotate' ) ) { toggleSelection( id ); render(); event.preventDefault(); announce( selectionIds().length > 1 ? t( 'multiSelectionUpdated', 'Multi-selection updated.' ) : t( 'selectionUpdated', 'Selection updated.' ) ); return; }
			if ( ! isSelectedId( id ) ) { selectOnly( id ); } else { selectedId = id; }
			var resize = event.target.getAttribute( 'data-certloom-designer-resize' ); var rotate = event.target.getAttribute( 'data-certloom-designer-rotate' );
			if ( ( resize || rotate ) && selectionIds().length > 1 ) { selectOnly( id ); }
			render();
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
				activeGuides = { x: null, y: null }; var currentAngle = Math.atan2( event.clientY - pointer.centerY, event.clientX - pointer.centerX ) * 180 / Math.PI; proposed.rotation_deg = normalizedAngle( pointer.initialRotation + currentAngle - pointer.startAngle );
			}
			updateElement( pointer.id, proposed, false ); syncDirty(); event.preventDefault();
		}
		function pointerEnd() {
			if ( ! pointer ) { return; }
			var before = pointer.before; var completed = pointer; pointer = null; activeGuides = { x: null, y: null }; if ( 'drag' === completed.mode && ! completed.moved ) { lastElementPointer = { id: completed.id, at: Date.now() }; } else { lastElementPointer = { id: '', at: 0 }; } pushHistory( before ); render();
		}
		function pageChange() {
			var p = page(); if ( ! p ) { return; }
			var size = sizeControl ? sizeControl.value : p.size; var orientation = orientationControl ? orientationControl.value : p.orientation;
			if ( ! PAGE_DIMENSIONS[ size ] || ! PAGE_DIMENSIONS[ size ][ orientation ] ) { render(); return; }
			if ( size === p.size && orientation === p.orientation ) { return; }
			var before = snapshot(); var dims = PAGE_DIMENSIONS[ size ][ orientation ]; var newW = dims[ 0 ], newH = dims[ 1 ]; var oldW = Number( p.width_mm ), oldH = Number( p.height_mm ); var scale = Math.min( newW / oldW, newH / oldH ); var offsetX = ( newW - oldW * scale ) / 2; var offsetY = ( newH - oldH * scale ) / 2;
			var next = clone( layout ); next.page = { size: size, orientation: orientation, width_mm: newW, height_mm: newH };
			for ( var i = 0; i < next.elements.length; i++ ) {
				next.elements[ i ].x_mm = round( Number( next.elements[ i ].x_mm ) * scale + offsetX, 3 ); next.elements[ i ].y_mm = round( Number( next.elements[ i ].y_mm ) * scale + offsetY, 3 ); next.elements[ i ].width_mm = round( Number( next.elements[ i ].width_mm ) * scale, 3 ); next.elements[ i ].height_mm = round( Number( next.elements[ i ].height_mm ) * scale, 3 );
				if ( ! elementGeometryValid( next.elements[ i ], next.page ) ) { showError( t( 'pageConversionBoundary', 'This page conversion would place an element outside the canonical page bounds. No change was applied.' ) ); syncControls(); return; }
			}
			layout = next; pushHistory( before ); render();
		}
		function fitWorkspace( comfortableEditing ) {
			var p = page(); if ( ! p ) { return; }
			var availableW = Math.max( 160, workspace.clientWidth - 54 ); var availableH = Math.max( 260, workspace.clientHeight - 54 );
			var factor = Math.min( availableW / ( p.width_mm * BASE_PX_PER_MM ), availableH / ( p.height_mm * BASE_PX_PER_MM ) );
			var naturalPercent = Math.floor( factor * 100 / 5 ) * 5;
			var minimumPercent = comfortableEditing && root.classList.contains( 'is-quick-mode' ) && workspace.clientWidth < 520 ? 40 : 25;
			var percent = clamp( Math.max( naturalPercent, minimumPercent ), 25, 200 ); zoom = percent / 100; render();
			if ( comfortableEditing && percent > naturalPercent ) { window.setTimeout( function () { workspace.scrollLeft = Math.max( 0, ( workspace.scrollWidth - workspace.clientWidth ) / 2 ); }, 0 ); }
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
			var modifier = event.ctrlKey || event.metaKey; var inside = root.contains( event.target );
			if ( modifier && ! event.altKey && 's' === event.key.toLowerCase() ) {
				if ( ! inside || isFormControl( event.target ) && ! root.contains( event.target ) ) { return; }
				event.preventDefault();
				for ( var saveIndex = 0; saveIndex < saveButtons.length; saveIndex++ ) { if ( ! saveButtons[ saveIndex ].disabled ) { saveButtons[ saveIndex ].click(); break; } }
				return;
			}
			if ( modifier && ! event.altKey && ( 'z' === event.key.toLowerCase() || 'y' === event.key.toLowerCase() ) ) {
				if ( isFormControl( event.target ) || ( ! inside && event.target !== document.body ) ) { return; }
				if ( 'y' === event.key.toLowerCase() || event.shiftKey ) { redo(); } else { undo(); }
				event.preventDefault(); return;
			}
			if ( ! inside || isFormControl( event.target ) ) { return; }
			if ( 'Escape' === event.key && selectionIds().length > 1 ) { selectOnly( selectedId ); render(); announce( t( 'multiSelectionCleared', 'Multi-selection cleared.' ) ); event.preventDefault(); return; }
			if ( 'Enter' === event.key && ! modifier && selected() && 'text' === selected().type ) { if ( startDirectTextEdit( selected().id ) ) { event.preventDefault(); } return; }
			if ( ( 'Delete' === event.key || 'Backspace' === event.key ) && selectionIds().length && ! selectionHasLocked() ) { event.preventDefault(); removeSelected(); return; }
			if ( nudgeSelected( event ) ) { event.preventDefault(); }
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
		pageEl.addEventListener( 'pointerdown', pointerStart ); window.addEventListener( 'pointermove', pointerMove ); window.addEventListener( 'pointerup', pointerEnd ); window.addEventListener( 'pointercancel', pointerEnd );
		pageEl.addEventListener( 'click', function ( event ) {
			var node = event.target.closest( '[data-certloom-designer-element]' );
			if ( event.shiftKey || event.ctrlKey || event.metaKey ) { return; }
			if ( node ) { var id = node.getAttribute( 'data-certloom-designer-element' ); if ( isSelectedId( id ) && selectionIds().length > 1 ) { selectedId = id; } else { selectOnly( id ); } render(); return; }
			selectOnly( '' ); render();
		} );
		pageEl.addEventListener( 'dblclick', function ( event ) { var node = event.target.closest( '[data-certloom-designer-element]' ); if ( node && startDirectTextEdit( node.getAttribute( 'data-certloom-designer-element' ) ) ) { event.preventDefault(); } } );
		pageEl.addEventListener( 'dragover', function ( event ) { if ( ! draggedPaletteKind ) { return; } event.preventDefault(); if ( event.dataTransfer ) { event.dataTransfer.dropEffect = 'copy'; } pageEl.classList.add( 'is-drop-target' ); } );
		pageEl.addEventListener( 'dragleave', function ( event ) { if ( event.relatedTarget && pageEl.contains( event.relatedTarget ) ) { return; } pageEl.classList.remove( 'is-drop-target' ); } );
		pageEl.addEventListener( 'drop', function ( event ) {
			var kind = draggedPaletteKind || ( event.dataTransfer ? event.dataTransfer.getData( 'application/x-certloom-element' ) : '' ); if ( ! kind ) { return; } event.preventDefault(); pageEl.classList.remove( 'is-drop-target' ); draggedPaletteKind = '';
			var rect = pageEl.getBoundingClientRect(); var point = { x_mm: round( mmFromPx( event.clientX - rect.left, zoom ), 3 ), y_mm: round( mmFromPx( event.clientY - rect.top, zoom ), 3 ) }; addRequested( kind, point );
		} );
		if ( layersContainer ) { layersContainer.addEventListener( 'click', function ( event ) { var lock = event.target.closest( '[data-certloom-designer-layer-lock]' ); if ( lock ) { selectOnly( lock.getAttribute( 'data-certloom-designer-layer-lock' ) ); toggleLock( selectedId ); return; } var select = event.target.closest( '[data-certloom-designer-layer-select]' ); if ( select ) { var id = select.getAttribute( 'data-certloom-designer-layer-select' ); if ( event.shiftKey || event.ctrlKey || event.metaKey ) { toggleSelection( id ); } else { selectOnly( id ); } render(); } } ); }
		if ( sizeControl ) { sizeControl.addEventListener( 'change', pageChange ); }
		if ( orientationControl ) { orientationControl.addEventListener( 'change', pageChange ); }
		if ( zoomControl ) { zoomControl.addEventListener( 'input', function () { zoom = clamp( Number( zoomControl.value ) / 100, .25, 2 ); render(); } ); }
		if ( snapControl ) { snapControl.addEventListener( 'change', function () { var before = snapshot(); designerState.snap_enabled = !! snapControl.checked; pushHistory( before ); render(); announce( designerState.snap_enabled ? t( 'snappingEnabled', 'Snapping enabled.' ) : t( 'snappingDisabled', 'Snapping disabled.' ) ); } ); }
		if ( fitButton ) { fitButton.addEventListener( 'click', function () { fitWorkspace( false ); } ); }
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
		for ( var modeIndex = 0; modeIndex < modeButtons.length; modeIndex++ ) { modeButtons[ modeIndex ].addEventListener( 'click', function () { applyDesignerMode( this.getAttribute( 'data-certloom-designer-mode-button' ), true ); } ); }
		if ( previewScrollButton ) { previewScrollButton.addEventListener( 'click', function () { scrollDesignerTarget( '.certloom-template-preview-card' ); } ); }
		if ( publicationScrollButton ) { publicationScrollButton.addEventListener( 'click', function () { scrollDesignerTarget( '.certloom-template-publication-card' ); } ); }
		if ( layersToggle ) { layersToggle.addEventListener( 'click', function () { root.classList.toggle( 'show-quick-layers' ); layersToggle.setAttribute( 'aria-expanded', root.classList.contains( 'show-quick-layers' ) ? 'true' : 'false' ); } ); }
		if ( quickTokenSelect && rawTokenInput ) { quickTokenSelect.addEventListener( 'change', function () { var value = String( quickTokenSelect.value || '' ); if ( '__custom__' === value ) { if ( customTokenWrap ) { customTokenWrap.classList.add( 'is-custom' ); } rawTokenInput.focus(); return; } if ( customTokenWrap ) { customTokenWrap.classList.remove( 'is-custom' ); } rawTokenInput.value = value; applyContentField( rawTokenInput ); } ); }
		for ( var contentIndex = 0; contentIndex < contentFields.length; contentIndex++ ) { contentFields[ contentIndex ].addEventListener( 'change', function () { applyContentField( this ); } ); }
		for ( var styleIndex = 0; styleIndex < styleFields.length; styleIndex++ ) { styleFields[ styleIndex ].addEventListener( 'change', function () { applyStyleField( this ); } ); }
		for ( var quickStyleIndex = 0; quickStyleIndex < quickStyleFields.length; quickStyleIndex++ ) { quickStyleFields[ quickStyleIndex ].addEventListener( 'change', function () { applyQuickStyleValue( this.getAttribute( 'data-certloom-designer-quick-style' ), this.value ); } ); }
		for ( var quickToggleIndex = 0; quickToggleIndex < quickToggleButtons.length; quickToggleIndex++ ) { quickToggleButtons[ quickToggleIndex ].addEventListener( 'click', function () { var key = this.getAttribute( 'data-certloom-designer-quick-toggle' ); if ( 'bold' === key ) { applyQuickStyleValue( 'font_weight', 'true' === this.getAttribute( 'aria-pressed' ) ? 400 : 700 ); } if ( 'italic' === key ) { applyQuickStyleValue( 'italic', 'true' !== this.getAttribute( 'aria-pressed' ) ); } } ); }
		for ( var quickAlignIndex = 0; quickAlignIndex < quickTextAlignButtons.length; quickAlignIndex++ ) { quickTextAlignButtons[ quickAlignIndex ].addEventListener( 'click', function () { applyQuickStyleValue( 'align', this.getAttribute( 'data-certloom-designer-quick-text-align' ) ); } ); }
		if ( colorPicker && colorTextField ) { colorPicker.addEventListener( 'change', function () { if ( colorPicker.disabled ) { return; } colorTextField.value = String( colorPicker.value || '#000000' ).toUpperCase(); applyStyleField( colorTextField ); } ); }
		if ( mediaButton ) { mediaButton.addEventListener( 'click', function () { var element = selected(); if ( ! element || isLocked( element ) || [ 'image', 'logo', 'signature' ].indexOf( element.type ) === -1 ) { return; } mediaFrame( function ( reference ) { var current = selected(); if ( ! current || isLocked( current ) ) { return; } var before = snapshot(); var proposed = clone( current ); proposed.asset = reference; if ( updateElement( current.id, proposed, false ) ) { pushHistory( before ); render(); announce( t( 'assetUpdated', 'Image updated.' ) ); } } ); } ); }
		if ( resetButton ) { resetButton.addEventListener( 'click', function () { if ( directTextEditor ) { closeDirectTextEditor( false ); } layout = clone( savedLayout ); designerState = clone( savedState ); selectOnly( '' ); history = []; future = []; activeGuides = { x: null, y: null }; render(); fitWorkspace( true ); discardAutosave( false ); announce( t( 'unsavedChangesReset', 'Unsaved design changes reset.' ) ); } ); }
		if ( autosaveDiscardButton ) { autosaveDiscardButton.addEventListener( 'click', function () { discardAutosave( true ); } ); }
		for ( var fieldIndex = 0; fieldIndex < fieldInputs.length; fieldIndex++ ) {
			fieldInputs[ fieldIndex ].addEventListener( 'change', function () {
				var element = selected(); if ( ! element || isLocked( element ) ) { setInspector(); return; } var before = snapshot(); var proposed = clone( element ); var value = Number( this.value ); if ( ! isFinite( value ) ) { setInspector(); return; }
				var key = this.getAttribute( 'data-certloom-designer-field' ); var map = { x: 'x_mm', y: 'y_mm', width: 'width_mm', height: 'height_mm', rotation: 'rotation_deg' };
				proposed[ map[ key ] ] = 'rotation' === key ? normalizedAngle( value ) : round( value, 3 );
				if ( updateElement( element.id, proposed, false ) ) { pushHistory( before ); render(); } else { showError( t( 'geometryBoundary', 'That geometry would violate the canonical page boundary. The previous value was kept.' ) ); setInspector(); }
			} );
		}
		document.addEventListener( 'keydown', keyboard );
		form.addEventListener( 'submit', function ( event ) {
			showError( '' );
			if ( directTextEditor ) { closeDirectTextEditor( true ); if ( directTextEditor ) { event.preventDefault(); blockSave( t( 'directTextInvalid', 'Direct text must stay within the canonical 20,000-byte text limit.' ) ); return; } }
			if ( autosaveTimer ) { window.clearTimeout( autosaveTimer ); autosaveTimer = null; }
			var p = page(); var list = elements();
			for ( var i = 0; i < list.length; i++ ) { if ( 'barcode' === list[ i ].type && schemaVersion < 2 ) { event.preventDefault(); blockSave( t( 'barcodeDeferred', 'Barcode elements require certloom-layout v2. Upgrade this draft first.' ) ); return; } if ( ! elementGeometryValid( list[ i ], p ) ) { event.preventDefault(); blockSave( t( 'invalidGeometrySave', 'Designer found invalid element geometry and stopped the save.' ) ); return; } }
			jsonField.value = JSON.stringify( layout ); if ( stateField ) { stateField.value = JSON.stringify( designerState ); } if ( autosaveSessionField ) { autosaveSessionField.value = autosaveSessionId; } isSubmitting = true; setSaveFeedback( t( 'savingRevision', 'Saving revision…' ), 'saving', true ); announce( t( 'savingRevision', 'Saving revision…' ) );
		} );
		window.addEventListener( 'beforeunload', function ( event ) { if ( isSubmitting || ! root.classList.contains( 'has-unsaved-changes' ) ) { return; } event.preventDefault(); event.returnValue = ''; } );

		applyDesignerMode( rememberedDesignerMode(), false );
		render(); if ( recovered ) { setAutosaveStatus( t( 'autosaveRecovered', 'Recovered unsaved Designer changes from your latest safe checkpoint.' ), 'recovered' ); announce( t( 'autosaveRecovered', 'Recovered unsaved Designer changes from your latest safe checkpoint.' ) ); } window.setTimeout( function () { fitWorkspace( true ); }, 0 );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var roots = document.querySelectorAll( '[data-certloom-designer]' );
		for ( var i = 0; i < roots.length; i++ ) { initDesigner( roots[ i ] ); }
	} );
} )();
