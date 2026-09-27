(function () {
	'use strict';

	const L = window.certloomEmailDesignerL10n || {};
	const q = ( selector, context = document ) => context.querySelector( selector );
	const qa = ( selector, context = document ) => Array.from( context.querySelectorAll( selector ) );

	const FONT_CATALOG = L.fontCatalog && typeof L.fontCatalog === 'object' ? L.fontCatalog : {};
	const FONT_AVAILABILITY = {};

	function fontCatalogEntries() {
		return Object.entries( FONT_CATALOG ).filter( ( entry ) => entry[ 1 ] && typeof entry[ 1 ].css === 'string' && entry[ 1 ].css );
	}

	function refreshFontAvailabilityLabels( context = document ) {
		qa( '[data-certloom-email-font-select] option[data-font-key]', context ).forEach( ( option ) => {
			const key = option.dataset.fontKey || '';
			const definition = FONT_CATALOG[ key ] || {};
			const base = option.dataset.fontLabel || definition.label || option.textContent.replace( /^✓\s*/, '' );
			option.dataset.fontLabel = base;
			if ( FONT_AVAILABILITY[ key ] === true ) { option.textContent = `✓ ${ base }`; option.dataset.fontAvailable = '1'; }
			else { option.textContent = base; option.dataset.fontAvailable = '0'; }
		} );
	}

	function probeLocalFontAvailability() {
		const queue = fontCatalogEntries().slice();
		const schedule = typeof window.requestIdleCallback === 'function' ? ( cb ) => window.requestIdleCallback( cb, { timeout: 700 } ) : ( cb ) => window.setTimeout( () => cb( { timeRemaining: () => 8 } ), 60 );
		const work = ( deadline ) => {
			let budget = 3;
			while ( queue.length && budget-- > 0 && ( !deadline || typeof deadline.timeRemaining !== 'function' || deadline.timeRemaining() > 2 ) ) {
				const [ key, definition = {} ] = queue.shift();
				if ( definition.generic || definition.custom ) { FONT_AVAILABILITY[ key ] = true; continue; }
				const names = Array.isArray( definition.local_names ) ? definition.local_names.slice( 0, 2 ) : [];
				if ( typeof FontFace !== 'function' || ! names.length ) { FONT_AVAILABILITY[ key ] = false; continue; }
				const escaped = String( names[ 0 ] ).replace( /["\\]/g, '\\$&' );
				try {
					const face = new FontFace( `__certloom_local_probe_${ key.replace( /[^a-z0-9_-]/gi, '_' ) }`, `local("${ escaped }")` );
					face.load().then( () => { FONT_AVAILABILITY[ key ] = true; refreshFontAvailabilityLabels(); }, () => { FONT_AVAILABILITY[ key ] = false; } );
				} catch ( error ) { FONT_AVAILABILITY[ key ] = false; }
			}
			refreshFontAvailabilityLabels();
			if ( queue.length ) { schedule( work ); }
		};
		schedule( work );
	}


	function base32LocalFontName( name ) {
		if ( typeof TextEncoder !== 'function' ) { return ''; }
		const bytes = new TextEncoder().encode( String( name || '' ).trim() );
		if ( ! bytes.length || bytes.length > 76 ) { return ''; }
		const alphabet = 'abcdefghijklmnopqrstuvwxyz234567'; let out = '', buffer = 0, bits = 0;
		for ( const byte of bytes ) {
			buffer = ( buffer << 8 ) | byte; bits += 8;
			while ( bits >= 5 ) { bits -= 5; out += alphabet[ ( buffer >> bits ) & 31 ]; }
			buffer &= ( 1 << bits ) - 1;
		}
		if ( bits > 0 ) { out += alphabet[ ( buffer << ( 5 - bits ) ) & 31 ]; }
		return out ? `local-${ out }` : '';
	}

	function addLocalFontToCatalog( family ) {
		family = String( family || '' ).trim(); const key = base32LocalFontName( family );
		if ( ! key ) { return ''; }
		if ( ! FONT_CATALOG[ key ] ) {
			const escaped = family.replace( /[\"\\]/g, '\\$&' );
			FONT_CATALOG[ key ] = { label: family, css: `"${ escaped }",Arial,sans-serif`, group: L.localDeviceFonts || 'Local device fonts', local_names: [ family ], local_device: true };
		}
		FONT_AVAILABILITY[ key ] = true; return key;
	}

	function syncEmailLocalFontOptions( context = document ) {
		const localEntries = fontCatalogEntries().filter( ( entry ) => entry[ 1 ].local_device ).sort( ( a, b ) => String( a[ 1 ].label ).localeCompare( String( b[ 1 ].label ) ) );
		qa( '[data-certloom-email-font-select]', context ).forEach( ( select ) => {
			const current = select.value; let group = select.querySelector( 'optgroup[data-certloom-local-font-group]' );
			if ( ! group ) { group = document.createElement( 'optgroup' ); group.label = L.localDeviceFonts || 'Local device fonts'; group.dataset.certloomLocalFontGroup = '1'; select.appendChild( group ); }
			group.textContent = '';
			localEntries.forEach( ( [ key, definition ] ) => { const option = document.createElement( 'option' ); option.value = definition.css; option.dataset.fontKey = key; option.dataset.fontLabel = definition.label; option.textContent = definition.label; group.appendChild( option ); } );
			if ( current ) { select.value = current; }
		} );
		refreshFontAvailabilityLabels( context );
	}

	async function scanLocalDeviceFonts( root, button, status ) {
		if ( typeof window.queryLocalFonts !== 'function' ) { status.textContent = L.localFontUnsupported || 'This browser cannot list every installed font. WordPress local fonts and the built-in system catalog remain available.'; return; }
		button.disabled = true; status.textContent = L.localFontScanning || 'Checking fonts installed on this device…';
		try {
			const faces = await window.queryLocalFonts(); const families = new Set();
			faces.forEach( ( face ) => { const family = String( face?.family || '' ).trim(); if ( family ) { families.add( family ); } } );
			families.forEach( addLocalFontToCatalog ); syncEmailLocalFontOptions( root ); document.dispatchEvent( new CustomEvent( 'certloom:fonts-updated' ) );
			status.textContent = families.size ? `${ families.size } ${ L.localFontsFound || 'local font families available.' }` : ( L.localFontsNone || 'No additional local fonts were returned by the browser.' );
		} catch ( error ) {
			status.textContent = String( error?.name || '' ) === 'NotAllowedError' ? ( L.localFontPermission || 'Local font access was not allowed. You can grant permission and scan again.' ) : ( L.localFontScanFailed || 'Local fonts could not be read. The built-in and WordPress-local font lists are still available.' );
		}
		button.disabled = false;
	}

	function installEmailLocalFontScanControl( root ) {
		const first = q( '[data-certloom-email-font-select]', root ); if ( ! first || q( '[data-certloom-email-local-font-scan]', root ) ) { return; }
		const wrap = document.createElement( 'div' ); wrap.className = 'certloom-email-font-discovery';
		const button = document.createElement( 'button' ); button.type = 'button'; button.className = 'button button-small'; button.dataset.certloomEmailLocalFontScan = '1'; button.textContent = L.scanLocalFonts || 'Scan local fonts';
		const status = document.createElement( 'small' ); status.className = 'certloom-email-font-discovery__status'; status.textContent = L.localFontScanHelp || 'Optional: allow your browser to list fonts installed on this device. No font files are uploaded.';
		wrap.append( button, status ); first.parentNode.appendChild( wrap ); button.addEventListener( 'click', () => scanLocalDeviceFonts( root, button, status ) );
	}

	function initDialogs() {
		const preview = q( '[data-certloom-email-preview-dialog]' );
		const target = q( '[data-certloom-email-preview-target]' );
		const title = q( '[data-certloom-email-preview-title]' );

		qa( '[data-certloom-email-preview-open]' ).forEach( ( button ) => {
			button.addEventListener( 'click', () => {
				const source = document.getElementById( button.dataset.previewId );
				if ( ! source || ! preview ) {
					return;
				}
				if ( title ) {
					title.textContent = button.dataset.title || '';
				}
				if ( target ) {
					target.innerHTML = source.innerHTML;
				}
				preview.showModal();
			} );
		} );

		const importDialog = q( '[data-certloom-email-import-dialog]' );
		q( '[data-certloom-email-import-open]' )?.addEventListener( 'click', () => importDialog?.showModal() );

		qa( '[data-certloom-email-dialog-close]' ).forEach( ( button ) => {
			button.addEventListener( 'click', () => button.closest( 'dialog' )?.close() );
		} );

		qa( 'dialog.certloom-email-dialog' ).forEach( ( dialog ) => {
			dialog.addEventListener( 'click', ( event ) => {
				if ( event.target === dialog ) {
					dialog.close();
				}
			} );
		} );
	}

	function initDesigner() {
		const form = q( '[data-certloom-email-designer]' );
		if ( ! form ) {
			return;
		}

		document.body.classList.add( 'certloom-email-studio-active' );
		refreshFontAvailabilityLabels();
		probeLocalFontAvailability();
		installEmailLocalFontScanControl( form );

		const studioRoot = q( '[data-certloom-email-studio]' );
		const studioHost = document.getElementById( 'wpbody-content' ) || studioRoot?.parentElement;
		let viewportSyncFrame = 0;
		let studioTop = null;
		function clearStudioFrame() {
			if ( ! studioRoot ) {
				return;
			}
			studioRoot.classList.remove( 'is-viewport-framed' );
			studioRoot.style.removeProperty( '--certloom-email-studio-top' );
			studioRoot.style.removeProperty( '--certloom-email-studio-left' );
			studioRoot.style.removeProperty( '--certloom-email-studio-right' );
			studioTop = null;
		}
		function syncStudioViewport() {
			if ( ! studioRoot ) {
				return;
			}
			if ( window.matchMedia( '(max-width: 960px)' ).matches ) {
				clearStudioFrame();
				return;
			}

			if ( studioTop === null || ! studioRoot.classList.contains( 'is-viewport-framed' ) ) {
				studioTop = Math.max( 0, Math.round( studioRoot.getBoundingClientRect().top ) );
			}
			const hostRect = studioHost?.getBoundingClientRect();
			const currentRect = studioRoot.getBoundingClientRect();
			const left = Math.max( 0, Math.round( hostRect?.left ?? currentRect.left ) );
			const right = Math.max( 0, Math.round( window.innerWidth - ( hostRect?.right ?? currentRect.right ) ) );
			studioRoot.style.setProperty( '--certloom-email-studio-top', `${ studioTop }px` );
			studioRoot.style.setProperty( '--certloom-email-studio-left', `${ left }px` );
			studioRoot.style.setProperty( '--certloom-email-studio-right', `${ right }px` );
			studioRoot.classList.add( 'is-viewport-framed' );
			// Framing changes the stage from its short document-flow height to the
			// viewport height. Refit after that layout change, even when a browser
			// does not deliver a ResizeObserver notification for the stage.
			scheduleCanvasFit();
		}
		function scheduleStudioViewportSync() {
			if ( viewportSyncFrame ) {
				cancelAnimationFrame( viewportSyncFrame );
			}
			viewportSyncFrame = requestAnimationFrame( () => {
				viewportSyncFrame = requestAnimationFrame( () => {
					viewportSyncFrame = 0;
					syncStudioViewport();
				} );
			} );
		}
		scheduleStudioViewportSync();
		window.addEventListener( 'resize', scheduleStudioViewportSync );
		window.addEventListener( 'orientationchange', scheduleStudioViewportSync );
		window.addEventListener( 'load', scheduleStudioViewportSync, { once: true } );
		[ 120, 360, 900 ].forEach( ( delay ) => window.setTimeout( scheduleStudioViewportSync, delay ) );
		if ( 'ResizeObserver' in window && studioHost ) {
			const studioViewportObserver = new ResizeObserver( scheduleStudioViewportSync );
			studioViewportObserver.observe( studioHost );
		}

		const hidden = q( '[data-certloom-blocks-json]', form );
		const canvas = q( '[data-certloom-email-canvas]' );
		const inspector = q( '[data-certloom-email-inspector]' );
		const saveState = q( '[data-certloom-save-state]' );
		const title = q( '[data-certloom-designer-title]' );
		const nameInput = q( '[data-certloom-template-name]' );
		const nameEdit = q( '[data-certloom-name-edit]' );
		const nameEditor = q( '[data-certloom-name-input]' );
		const attachmentsHidden = q( '[data-certloom-attachments-json]', form );
		const feedback = q( '[data-certloom-designer-feedback]' );
		const structureList = q( '[data-certloom-structure-list]' );
		const structureCount = q( '[data-certloom-structure-count]' );
		const inspectorHeading = q( '[data-certloom-inspector-heading]' );
		const inspectorPath = q( '[data-certloom-inspector-path]' );
		const stageMeta = q( '[data-certloom-stage-meta]' );
		const canvasFrame = q( '[data-certloom-canvas-frame]' );
		const stageViewport = q( '[data-certloom-email-stage-viewport]' );
		let canvasFitFrame = 0;
		function syncCanvasFit() {
			if ( ! stageViewport || ! canvasFrame ) {
				return;
			}
			if ( window.matchMedia( '(max-width: 960px)' ).matches ) {
				canvasFrame.style.removeProperty( '--certloom-email-fit-scale' );
				canvasFrame.removeAttribute( 'data-certloom-fit-scale' );
				return;
			}
			const availableWidth = Math.max( 1, stageViewport.clientWidth - 48 );
			const availableHeight = Math.max( 1, stageViewport.clientHeight - 48 );
			const naturalWidth = Math.max( 1, canvasFrame.offsetWidth );
			const naturalHeight = Math.max( 1, canvasFrame.offsetHeight );
			const scale = Math.min( 1, availableWidth / naturalWidth, availableHeight / naturalHeight );
			const safeScale = Math.max( 0.1, Math.floor( scale * 1000 ) / 1000 );
			canvasFrame.style.setProperty( '--certloom-email-fit-scale', String( safeScale ) );
			canvasFrame.setAttribute( 'data-certloom-fit-scale', String( safeScale ) );
		}
		function scheduleCanvasFit() {
			if ( canvasFitFrame ) {
				cancelAnimationFrame( canvasFitFrame );
			}
			canvasFitFrame = requestAnimationFrame( () => {
				canvasFitFrame = 0;
				syncCanvasFit();
			} );
		}
		if ( 'ResizeObserver' in window && stageViewport ) {
			const canvasFitObserver = new ResizeObserver( scheduleCanvasFit );
			canvasFitObserver.observe( stageViewport );
			canvasFitObserver.observe( canvasFrame );
		}
		window.addEventListener( 'resize', scheduleCanvasFit );
		syncStudioViewport();

		let blocks = [];
		try {
			blocks = JSON.parse( hidden?.value || '[]' );
		} catch ( error ) {
			blocks = [];
		}
		if ( ! Array.isArray( blocks ) ) {
			blocks = [];
		}
		blocks = normalizeClientBlocks( blocks );

		function usedCustomFontDefinitions() {
			const serialized = JSON.stringify( blocks );
			const themeValues = qa( '[data-certloom-theme]', form ).map( ( input ) => String( input.value || '' ) ).join( '\n' );
			return fontCatalogEntries().filter( ( [ key, definition ] ) => definition.custom && ( serialized.includes( String( definition.css ) ) || themeValues.includes( String( definition.css ) ) ) );
		}
		function ensureUsedCustomFontsLoaded() {
			if ( ! window.CertloomFontPicker ) { return; }
			usedCustomFontDefinitions().forEach( ( [ key, definition ] ) => window.CertloomFontPicker.loadCustom( definition, key ) );
		}
		ensureUsedCustomFontsLoaded();
		let attachments = [];
		try { attachments = JSON.parse( attachmentsHidden?.value || '[]' ); } catch ( error ) { attachments = []; }
		if ( ! Array.isArray( attachments ) ) { attachments = []; }
		attachments = attachments.filter( ( item ) => item && Number( item.id || item ) > 0 ).slice( 0, 5 ).map( ( item ) => typeof item === 'object' ? item : { id:Number( item ) } );

		const MAX_BLOCKS = 80;
		const MAX_CONTAINER_DEPTH = 4;
		let selectedPath = blocks.length ? [ 0 ] : null;
		let dirty = false;
		let lastTokenField = null;
		let dragPath = null;
		let sampleMode = true;

		function sync() {
			if ( hidden ) { hidden.value = JSON.stringify( blocks ); }
			if ( attachmentsHidden ) { attachmentsHidden.value = JSON.stringify( attachments.map( ( item ) => Number( item.id || item ) ).filter( Boolean ) ); }
		}

		function markDirty() {
			dirty = true;
			if ( saveState ) {
				saveState.textContent = 'Unsaved changes';
			}
			sync();
		}

		function clearFeedback() {
			if ( ! feedback ) {
				return;
			}
			feedback.hidden = true;
			feedback.textContent = '';
			feedback.classList.remove( 'is-error' );
		}

		function showFeedback( message ) {
			if ( ! feedback ) {
				return;
			}
			feedback.textContent = String( message || '' );
			feedback.classList.add( 'is-error' );
			feedback.hidden = false;
			feedback.focus();
		}

		function esc( value ) {
			return String( value ?? '' ).replace( /[&<>"']/g, ( match ) => ( {
				'&': '&amp;',
				'<': '&lt;',
				'>': '&gt;',
				'"': '&quot;',
				"'": '&#039;',
			} )[ match ] );
		}

		function sample( value ) {
			if ( ! sampleMode ) { return String( value || '' ); }
			let output = String( value || '' );
			const samples = L.tokenSamples && typeof L.tokenSamples === 'object' ? L.tokenSamples : {};
			Object.entries( samples ).forEach( ( [ key, replacement ] ) => {
				output = output.split( '{{' + key + '}}' ).join( String( replacement ?? '' ) );
			} );
			return output;
		}

		function blockStyle( block ) {
			return block && block.style && typeof block.style === 'object' ? block.style : {};
		}

		function fontStyleField( label, key ) {
			const style = blockStyle( selectedBlock() || {} );
			const current = String( style[ key ] || '' );
			let html = `<option value="">${ esc( L.inheritGlobal || 'Use email default' ) }</option>`;
			let group = '', matched = false;
			fontCatalogEntries().forEach( ( entry ) => {
				const fontKey = entry[ 0 ];
				const definition = entry[ 1 ];
				const nextGroup = definition.group || 'Other';
				if ( nextGroup !== group ) {
					if ( group ) { html += '</optgroup>'; }
					group = nextGroup;
					html += `<optgroup label="${ esc( group ) }">`;
				}
				const selectedAttr = current === String( definition.css ) ? ' selected' : '';
				if ( selectedAttr ) { matched = true; }
				html += `<option value="${ esc( definition.css ) }" data-font-key="${ esc( fontKey ) }" data-font-label="${ esc( definition.label || fontKey ) }"${ selectedAttr }>${ esc( definition.label || fontKey ) }</option>`;
			} );
			if ( group ) { html += '</optgroup>'; }
			if ( current && ! matched && safeFont( current ) ) {
				const localLabel = current.split( ',' )[ 0 ].replace( /^[\s"']+|[\s"']+$/g, '' ) || current;
				html += `<optgroup label="${ esc( L.localDeviceFonts || 'Local device fonts' ) }"><option value="${ esc( current ) }" data-font-label="${ esc( localLabel ) }" selected>${ esc( localLabel ) } · ${ esc( L.savedLocalFont || 'saved local font' ) }</option></optgroup>`;
			}
			return `<label class="is-wide certloom-font-picker-field"><span>${ esc( label ) }</span><select data-style-field="${ esc( key ) }" data-certloom-email-font-select>${ html }</select><small class="certloom-font-availability-note">${ esc( L.fontAvailabilityHelp || 'Local/system and site-local custom fonts. Custom web fonts are emitted only when used; email clients that block web fonts fall back safely.' ) }</small></label>`;
		}

		function safeColor( value, fallback ) {
			const color = String( value || '' ).trim();
			return /^#[0-9A-Fa-f]{6}$/.test( color ) ? color : fallback;
		}

		function rgbaColor( value, opacity, fallback = '#000000' ) {
			const color = safeColor( value, fallback ).slice( 1 );
			const alpha = Math.max( 0, Math.min( 100, Number( opacity ) || 0 ) ) / 100;
			return `rgba(${ parseInt( color.slice( 0, 2 ), 16 ) },${ parseInt( color.slice( 2, 4 ), 16 ) },${ parseInt( color.slice( 4, 6 ), 16 ) },${ alpha.toFixed( 3 ).replace( /0+$/, '' ).replace( /\.$/, '' ) || '0' })`;
		}

		function safeFont( value ) {
			value = String( value || '' ).trim();
			if ( ! value ) { return ''; }
			if ( fontCatalogEntries().some( ( entry ) => String( entry[ 1 ].css ) === value ) ) { return value; }
			return value.length <= 240 && /^[\p{L}\p{N}\p{M}\s,._\-"'&+()\/]+$/u.test( value ) && ! /(?:url|expression|var)\s*\(/i.test( value ) ? value : '';
		}

		function typographyStyle( block, kind ) {
			const style = blockStyle( block );
			const parts = [];
			const font = safeFont( style.font_family );
			if ( font ) { parts.push( `font-family:${ font }` ); }
			if ( style.font_size ) { parts.push( `font-size:${ Math.max( 1, Number( style.font_size ) || 0 ) }px` ); }
			if ( style.font_weight ) { parts.push( `font-weight:${ Number( style.font_weight ) || ( kind === 'heading' ? 700 : 400 ) }` ); }
			if ( style.italic ) { parts.push( 'font-style:italic' ); }
			if ( style.underline ) { parts.push( 'text-decoration:underline' ); }
			if ( style.color ) { parts.push( `color:${ safeColor( style.color, kind === 'heading' ? 'var(--certloom-email-heading-color,#173f34)' : 'inherit' ) }` ); }
			if ( style.align ) { parts.push( `text-align:${ [ 'left', 'center', 'right' ].includes( style.align ) ? style.align : 'left' }` ); }
			if ( style.line_height ) { parts.push( `line-height:${ Math.max( 1, Math.min( 2.5, Number( style.line_height ) || 1.5 ) ) }` ); }
			if ( style.letter_spacing !== undefined && style.letter_spacing !== '' ) { parts.push( `letter-spacing:${ Math.max( -5, Math.min( 20, Number( style.letter_spacing ) || 0 ) ) }px` ); }
			if ( [ 'none', 'uppercase', 'lowercase', 'capitalize' ].includes( style.text_transform ) ) { parts.push( `text-transform:${ style.text_transform }` ); }
			return parts.join( ';' );
		}

		function sanitizeCustomHtml( html ) {
			const template = document.createElement( 'template' );
			template.innerHTML = String( html || '' );
			const allowedTags = new Set( [ 'TABLE','THEAD','TBODY','TFOOT','TR','TD','TH','DIV','SPAN','P','H1','H2','H3','H4','STRONG','B','EM','I','U','S','BR','HR','UL','OL','LI','A','IMG' ] );
			const commonAttributes = new Set( [ 'style','align','valign','width','height','title' ] );
			const attributesByTag = {
				TABLE: new Set( [ 'role','cellspacing','cellpadding','border','bgcolor' ] ),
				TD: new Set( [ 'colspan','rowspan','bgcolor' ] ), TH: new Set( [ 'colspan','rowspan','scope','bgcolor' ] ),
				A: new Set( [ 'href','target','rel','title','style' ] ),
				IMG: new Set( [ 'src','alt','title','width','height','style','align','border' ] ),
			};
			const safeStyle = ( value ) => String( value || '' )
				.replace( /(?:^|;)\s*(?:position|z-index|behavior|filter|animation|transition|transform|content)\s*:[^;]*/gi, '' )
				.replace( /expression\s*\([^)]*\)/gi, '' )
				.replace( /url\s*\(\s*['"]?\s*(?:javascript|data:text\/html)[^)]*\)/gi, '' );
			Array.from( template.content.querySelectorAll( '*' ) ).forEach( ( node ) => {
				if ( ! allowedTags.has( node.tagName ) ) {
					const fragment = document.createDocumentFragment();
					while ( node.firstChild ) { fragment.appendChild( node.firstChild ); }
					node.replaceWith( fragment );
					return;
				}
				const tagAllowed = attributesByTag[ node.tagName ] || new Set();
				Array.from( node.attributes ).forEach( ( attribute ) => {
					const name = attribute.name.toLowerCase();
					const value = String( attribute.value || '' ).trim();
					if ( name.startsWith( 'on' ) || name === 'srcdoc' || ( ! commonAttributes.has( name ) && ! tagAllowed.has( name ) ) ) { node.removeAttribute( attribute.name ); return; }
					if ( ( name === 'href' || name === 'src' ) && /^\s*(?:javascript|data:text\/html)/i.test( value ) ) { node.removeAttribute( attribute.name ); return; }
					if ( name === 'style' ) { const cleaned = safeStyle( value ); if ( cleaned.trim() ) { node.setAttribute( 'style', cleaned ); } else { node.removeAttribute( 'style' ); } }
				} );
			} );
			return template.innerHTML;
		}

		function sampleHtml( value ) {
			let output = sanitizeCustomHtml( value );
			if ( ! sampleMode ) { return output; }
			const samples = L.tokenSamples && typeof L.tokenSamples === 'object' ? L.tokenSamples : {};
			Object.entries( samples ).forEach( ( [ key, replacement ] ) => {
				output = output.split( '{{' + key + '}}' ).join( esc( String( replacement ?? '' ) ) );
			} );
			return output;
		}

		function defaultContainer( direction = 'vertical', children = [] ) {
			return {
				type: 'container', direction: direction === 'horizontal' ? 'horizontal' : 'vertical', gap: 16, basis: 100, valign: 'top', align: 'left', stack_mobile: true,
				padding: { top: 0, right: 0, bottom: 0, left: 0 }, background_color: '', background_opacity: 100, background_image: '', overlay_color: '#000000', overlay_opacity: 0, background_size: 'cover', background_position: 'center', background_repeat: 'no-repeat',
				border_color: '', border_width: 0, border_style: 'solid', border_radius: 0, min_height: 0, children: Array.isArray( children ) ? children : [],
			};
		}

		function legacyColumnToBlock( column ) {
			column = column && typeof column === 'object' ? column : {};
			if ( column.kind === 'image' ) { return { type: 'image', url: column.url || '', alt: column.alt || '', link: column.link || '', style: { width: Number( column.width ) || 100, align: column.align || 'center' } }; }
			if ( column.kind === 'html' ) { return { type: 'html', html: column.html || '<p></p>' }; }
			return { type: 'text', html: column.html || '<p></p>' };
		}

		function legacyColumnsToContainer( block ) {
			const count = Number( block.count ) === 3 ? 3 : 2;
			const widths = { '50-50':[50,50], '33-67':[33,67], '67-33':[67,33], '40-60':[40,60], '60-40':[60,40], '33-33-33':[33,34,33], '25-50-25':[25,50,25] }[ block.ratio ] || ( count === 3 ? [ 33, 34, 33 ] : [ 50, 50 ] );
			let columns = Array.isArray( block.columns ) ? block.columns.slice( 0, count ) : [];
			if ( ! columns.length && ( block.left_html !== undefined || block.right_html !== undefined ) ) { columns = [ { kind:'text', html:block.left_html || '<p></p>' }, { kind:'text', html:block.right_html || '<p></p>' } ]; }
			while ( columns.length < count ) { columns.push( { kind:'text', html:'<p></p>' } ); }
			const children = columns.slice( 0, count ).map( ( column, index ) => { const child=defaultContainer( 'vertical', [ legacyColumnToBlock( column ) ] ); child.basis=widths[index] || Math.floor(100/count); return child; } );
			const container = defaultContainer( 'horizontal', children ); container.gap=Math.max(0,Math.min(48,Number(block.gap)||20)); return container;
		}

		function normalizeClientBlock( block, depth = 0 ) {
			if ( ! block || typeof block !== 'object' ) { return null; }
			if ( block.type === 'columns' ) { block = legacyColumnsToContainer( block ); }
			if ( block.type === 'container' ) {
				const normalized = { ...defaultContainer( block.direction, [] ), ...block };
				normalized.padding = { ...defaultContainer().padding, ...( block.padding && typeof block.padding === 'object' ? block.padding : {} ) };
				normalized.children = depth < 4 && Array.isArray( block.children ) ? block.children.map( ( child ) => normalizeClientBlock( child, depth + 1 ) ).filter( Boolean ) : [];
				return normalized;
			}
			return { ...block };
		}

		function normalizeClientBlocks( input ) { return Array.isArray( input ) ? input.map( ( block ) => normalizeClientBlock( block, 0 ) ).filter( Boolean ) : []; }
		function pathKey( path ) { return Array.isArray( path ) ? path.join( '.' ) : ''; }
		function samePath( a, b ) { return pathKey( a ) === pathKey( b ); }
		function getBlockAtPath( path ) {
			if ( ! Array.isArray( path ) || ! path.length ) { return null; }
			let list = blocks, block = null;
			for ( let i = 0; i < path.length; i++ ) { block = list?.[ path[i] ] || null; if ( ! block ) { return null; } if ( i < path.length - 1 ) { list = block.type === 'container' && Array.isArray( block.children ) ? block.children : null; } }
			return block;
		}
		function getParentList( path ) {
			if ( ! Array.isArray( path ) || ! path.length ) { return null; }
			if ( path.length === 1 ) { return blocks; }
			const parent = getBlockAtPath( path.slice( 0, -1 ) ); return parent?.type === 'container' && Array.isArray( parent.children ) ? parent.children : null;
		}
		function selectedBlock() { return getBlockAtPath( selectedPath ); }
		function inspectorPathText( path ) {
			if ( ! Array.isArray( path ) || ! path.length ) { return ''; }
			let list = blocks;
			const parts = [ L.emailRoot || 'Email' ];
			path.forEach( ( index ) => {
				const block = Array.isArray( list ) ? list[ index ] : null;
				if ( ! block ) { return; }
				parts.push( `${ blockLabel( block ) } ${ Number( index ) + 1 }` );
				list = block.type === 'container' && Array.isArray( block.children ) ? block.children : [];
			} );
			return parts.join( ' › ' );
		}
		function findPathByIdentity( target, list = blocks, prefix = [] ) {
			for ( let index = 0; index < list.length; index++ ) { const block=list[index];const path=[...prefix,index];if(block===target){return path;}if(block?.type==='container'&&Array.isArray(block.children)){const found=findPathByIdentity(target,block.children,path);if(found){return found;}} } return null;
		}
		function containsBlock( root, candidate ) { return root===candidate || ( root?.type==='container' && Array.isArray(root.children) && root.children.some( ( child ) => containsBlock( child, candidate ) ) ); }
		function blockDepth( path ) { return Array.isArray(path) ? Math.max(0,path.length-1) : 0; }
		function subtreeDepth( block ) {
			if ( block?.type !== 'container' || ! Array.isArray( block.children ) || ! block.children.length ) { return 0; }
			return 1 + Math.max( ...block.children.map( subtreeDepth ) );
		}
		function subtreeSize( block ) { return block && typeof block === 'object' ? 1 + ( block.type === 'container' && Array.isArray( block.children ) ? block.children.reduce( ( total, child ) => total + subtreeSize( child ), 0 ) : 0 ) : 0; }
		function totalBlockCount( list = blocks ) { return list.reduce( ( total, block ) => total + subtreeSize( block ), 0 ); }
		function canPlaceAtDepth( block, depth ) { return Number( depth ) + subtreeDepth( block ) <= MAX_CONTAINER_DEPTH; }
		function placementError( message ) { showFeedback( message ); return false; }

		function containerPreviewStyle( block ) {
			const pad = { top:0,right:0,bottom:0,left:0,...( block.padding || {} ) };
			const styles = [ `padding:${Number(pad.top)||0}px ${Number(pad.right)||0}px ${Number(pad.bottom)||0}px ${Number(pad.left)||0}px`, `min-height:${Math.max(0,Number(block.min_height)||0)}px` ];
			if ( block.background_color ) { styles.push( `background-color:${ rgbaColor( block.background_color, block.background_opacity ?? 100, '#ffffff' ) }` ); }
			if ( block.background_image ) {
				const image = `url('${ String(block.background_image).replace(/'/g,'') }')`;
				const overlay = Math.max( 0, Math.min( 100, Number( block.overlay_opacity ) || 0 ) );
				styles.push( `background-image:${ overlay ? `linear-gradient(${ rgbaColor( block.overlay_color, overlay ) },${ rgbaColor( block.overlay_color, overlay ) }),` : '' }${ image }`, `background-size:${ ['auto','cover','contain'].includes(block.background_size)?block.background_size:'cover' }`, `background-position:${ ['center','top','bottom','left','right'].includes(block.background_position)?block.background_position:'center' }`, `background-repeat:${ ['no-repeat','repeat','repeat-x','repeat-y'].includes(block.background_repeat)?block.background_repeat:'no-repeat' }` );
			}
			if ( Number(block.border_width) > 0 && block.border_color ) { styles.push( `border:${Math.min(8,Number(block.border_width)||0)}px ${ ['solid','dashed','dotted'].includes(block.border_style)?block.border_style:'solid' } ${safeColor(block.border_color,'#d8dee8')}` ); }
			if ( Number(block.border_radius) > 0 ) { styles.push( `border-radius:${Math.min(40,Number(block.border_radius)||0)}px` ); }
			return styles.join(';');
		}

		function blockHtml( block ) {
			const style = blockStyle( block );
			switch ( block.type ) {
				case 'heading':
					return `<h${ block.level || 2 } style="${ esc( typographyStyle( block, 'heading' ) ) }">${ esc( sample( block.text ) ) }</h${ block.level || 2 }>`;
				case 'text':
					return `<div style="${ esc( typographyStyle( block, 'text' ) ) }">${ sampleHtml( block.html || '' ) }</div>`;
				case 'html':
					return `<div class="certloom-email-custom-html">${ sampleHtml( block.html || '' ) }</div>`;
				case 'button': {
					const buttonStyles = typographyStyle( block, 'button' ).split( ';' ).filter( Boolean );
					const bg = safeColor( style.background_color, '#1369fc' );
					buttonStyles.push( `background:${ rgbaColor( bg, style.background_opacity ?? 100, '#1369fc' ) }` );
					buttonStyles.push( `color:${ safeColor( style.color, '#ffffff' ) }` );
					buttonStyles.push( `display:${ style.full_width ? 'block' : 'inline-block' }` );
					if ( style.full_width ) { buttonStyles.push( 'width:100%', 'box-sizing:border-box', 'text-align:center' ); }
					const borderWidth = Math.max( 0, Math.min( 8, Number( style.border_width ) || 0 ) );
					const borderStyle = [ 'solid', 'dashed', 'dotted' ].includes( style.border_style ) ? style.border_style : 'solid';
					buttonStyles.push( borderWidth && style.border_color ? `border:${ borderWidth }px ${ borderStyle } ${ safeColor( style.border_color, '#1369fc' ) }` : 'border:0' );
					buttonStyles.push( `text-decoration:${ style.underline ? 'underline' : 'none' }` );
					buttonStyles.push( `border-radius:${ Math.max( 0, Number( style.border_radius ?? 12 ) || 0 ) }px` );
					buttonStyles.push( `padding:${ Math.max( 6, Number( style.padding_y ?? 13 ) || 13 ) }px ${ Math.max( 8, Number( style.padding_x ?? 20 ) || 20 ) }px` );
					return `<div style="text-align:${ esc( block.align || 'left' ) };margin:${ Math.max( 0, Math.min( 60, Number( style.margin_y ) || 4 ) ) }px 0 22px"><span class="button button-primary" style="${ esc( buttonStyles.join( ';' ) ) }">${ esc( sample( block.text ) ) }</span></div>`;
				}
				case 'image': {
					const width = Math.max( 10, Math.min( 100, Number( style.width ) || 100 ) ); const radius = Math.max( 0, Number( style.border_radius ) || 0 ); const align = [ 'left', 'center', 'right' ].includes( style.align ) ? style.align : 'left';
					if ( ! block.url ) { return '<div class="certloom-email-empty">Image</div>'; }
					const opacity = Math.max( 0, Math.min( 100, Number( style.opacity ?? 100 ) ) ) / 100;
					const borderWidth = Math.max( 0, Math.min( 8, Number( style.border_width ) || 0 ) );
					const borderStyle = [ 'solid', 'dashed', 'dotted' ].includes( style.border_style ) ? style.border_style : 'solid';
					const border = borderWidth && style.border_color ? `${ borderWidth }px ${ borderStyle } ${ safeColor( style.border_color, '#d8dee8' ) }` : '0';
					let image = `<img src="${ esc( block.url ) }" alt="${ esc( block.alt || '' ) }" style="display:inline-block;width:${ width }%;max-width:100%;height:auto;border:${ border };border-radius:${ radius }px;opacity:${ opacity }">`;
					if ( block.link ) { image = `<a href="${ esc( sample( block.link ) ) }">${ image }</a>`; }
					return `<div style="text-align:${ align }">${ image }</div>`;
				}
				case 'divider': {
					const color = safeColor( style.color, '#dce6e1' ); const width = Math.max( 10, Math.min( 100, Number( style.width ) || 100 ) ); const thickness = Math.max( 1, Math.min( 8, Number( style.thickness ) || 1 ) ); const margin = Math.max( 4, Math.min( 60, Number( style.margin_y ) || 24 ) );
					const lineStyle = [ 'solid', 'dashed', 'dotted' ].includes( style.line_style ) ? style.line_style : 'solid';
					const align = [ 'left', 'center', 'right' ].includes( style.align ) ? style.align : 'center';
					const marginLR = align === 'left' ? '0 auto' : ( align === 'right' ? 'auto 0' : 'auto' );
					return `<div style="border-top:${ thickness }px ${ lineStyle } ${ color };width:${ width }%;margin:${ margin }px ${ marginLR }"></div>`;
				}
				case 'spacer':
					return `<div style="height:${ Number( block.height ) || 24 }px"></div>`;
				case 'container': {
					const direction = block.direction === 'horizontal' ? 'row' : 'column';
					const alignItems = { top:'flex-start', middle:'center', bottom:'flex-end' }[ block.valign ] || 'flex-start';
					const justifyContent = { left:'flex-start', center:'center', right:'flex-end' }[ block.align ] || 'flex-start';
					const children = Array.isArray( block.children ) ? block.children : [];
					return `<div class="certloom-email-container-preview is-${ direction }${ block.stack_mobile !== false ? ' is-stack-mobile' : '' }" style="display:flex;flex-direction:${ direction };gap:${ Math.max(0,Number(block.gap)||0) }px;align-items:${ alignItems };justify-content:${ justifyContent };${ esc( containerPreviewStyle( block ) ) }">${ children.map( ( child ) => { const basis=Math.max(5,Math.min(100,Number(child.basis)||100)); return `<div style="${ direction==='row' ? `flex:${basis} 1 0px;min-width:0;max-width:100%` : 'width:100%;min-width:0;max-width:100%' }">${ blockHtml( child ) }</div>`; } ).join('') }</div>`;
				}
				default:
					return '';
			}
		}

		function blockLabel( block ) {
			const labels = {
				heading: L.addHeading || 'Heading',
				text: L.addText || 'Text',
				button: L.addButton || 'Button',
				image: L.addImage || 'Image',
				divider: L.addDivider || 'Divider',
				spacer: L.addSpacer || 'Spacer',
				container: L.addContainer || L.container || 'Container',
				html: L.addHtml || 'Custom HTML',
			};
			return labels[ block?.type ] || 'Block';
		}

		function blockSummary( block ) {
			if ( ! block ) {
				return '';
			}
			if ( block.type === 'heading' ) {
				return sample( block.text || '' );
			}
			if ( block.type === 'text' ) {
				const holder = document.createElement( 'div' );
				holder.innerHTML = sample( block.html || '' );
				return ( holder.textContent || '' ).trim();
			}
			if ( block.type === 'button' ) {
				return sample( block.text || '' );
			}
			if ( block.type === 'image' ) {
				return block.alt || ( block.url ? 'Image' : 'Choose image' );
			}
			if ( block.type === 'spacer' ) {
				return `${ Number( block.height ) || 24 }px`;
			}
			if ( block.type === 'container' ) {
				const children = Array.isArray( block.children ) ? block.children.length : 0;
				return `${ block.direction === 'horizontal' ? 'Horizontal' : 'Vertical' } · ${ children } ${ children === 1 ? 'item' : 'items' }`;
			}
			if ( block.type === 'html' ) {
				const holder = document.createElement( 'div' );
				holder.innerHTML = sampleHtml( block.html || '' );
				return ( holder.textContent || '' ).trim().slice( 0, 80 ) || ( L.addHtml || 'Custom HTML' );
			}
			return blockLabel( block );
		}

		function renderStructure() {
			if ( structureCount ) { structureCount.textContent = String( totalBlockCount() ); }
			if ( ! structureList ) { return; }
			structureList.innerHTML = '';
			if ( ! blocks.length ) { structureList.innerHTML = `<div class="certloom-inspector-empty"><p>${ esc( L.emptyContent || 'Add a content block to start the message.' ) }</p></div>`; return; }
			const appendItems = ( list, parent, prefix = [], depth = 0 ) => {
				list.forEach( ( block, index ) => {
					const path = [ ...prefix, index ];
					const button = document.createElement( 'button' ); button.type='button'; button.className='certloom-email-structure-item' + ( samePath(path,selectedPath) ? ' is-selected' : '' ) + ( block.type === 'container' ? ' is-container' : '' ); button.dataset.structurePath=pathKey(path); button.style.setProperty('--certloom-structure-depth',String(depth));
					button.innerHTML=`<span class="certloom-email-structure-item__index">${ block.type === 'container' ? '▥' : index + 1 }</span><span class="certloom-email-structure-item__copy"><strong>${ esc(blockLabel(block)) }</strong><small>${ esc(blockSummary(block)) }</small></span>`;
					button.addEventListener('click',()=>{selectedPath=path;render();q(`.certloom-canvas-block[data-path="${pathKey(path)}"]`,canvas)?.focus({preventScroll:true});});parent.appendChild(button);
					if ( block.type === 'container' && Array.isArray(block.children) && block.children.length ) { const branch=document.createElement('div');branch.className='certloom-email-structure-branch';parent.appendChild(branch);appendItems(block.children,branch,path,depth+1); }
				} );
			};
			appendItems( blocks, structureList );
		}

		function blockControlsHtml() {
			return `<div class="certloom-canvas-block__controls"><span class="certloom-canvas-drag-handle" draggable="true" title="${esc(L.dragBlock||'Drag to reorder')}" aria-hidden="true">⋮⋮</span><button type="button" data-up aria-label="${esc(L.moveUp||'Move up')}">↑</button><button type="button" data-down aria-label="${esc(L.moveDown||'Move down')}">↓</button><button type="button" data-copy aria-label="${esc(L.duplicate||'Duplicate')}">⧉</button><button type="button" data-remove aria-label="${esc(L.remove||'Remove')}">×</button></div>`;
		}

		function renderBlockNode( block, path ) {
			const element=document.createElement('div'); element.className='certloom-canvas-block'+(samePath(path,selectedPath)?' is-selected':'')+(block.type==='container'?' certloom-canvas-container':''); element.setAttribute('role','listitem');element.tabIndex=0;element.dataset.path=pathKey(path);
			if ( block.type === 'container' ) {
				const surface=document.createElement('div');surface.className='certloom-email-container-surface '+(block.direction==='horizontal'?'is-horizontal':'is-vertical');surface.setAttribute('style',containerPreviewStyle(block));
				const badge=document.createElement('span');badge.className='certloom-email-container-badge';badge.textContent=`${L.container||'Container'} · ${block.direction==='horizontal'?(L.horizontal||'Horizontal'):(L.vertical||'Vertical')}`;surface.appendChild(badge);
				const children=document.createElement('div');children.className='certloom-email-container-children '+(block.direction==='horizontal'?'is-horizontal':'is-vertical')+(block.stack_mobile!==false?' is-stack-mobile':'');children.style.gap=`${Math.max(0,Number(block.gap)||0)}px`;
				const childList=Array.isArray(block.children)?block.children:[];
				childList.forEach((child,index)=>{const wrap=document.createElement('div');wrap.className='certloom-email-container-child';if(block.direction==='horizontal'){const basis=Math.max(5,Math.min(100,Number(child.basis)||100));wrap.style.flex=`${basis} 1 0px`;}else{wrap.style.width='100%';}wrap.appendChild(renderBlockNode(child,[...path,index]));children.appendChild(wrap);});
				if(!childList.length){
					const empty=document.createElement('div');empty.className='certloom-email-container-empty';
					empty.innerHTML=`<span class="certloom-email-container-empty__copy"><strong>${esc(L.emptyContainer||'Empty container')}</strong><small>${esc(L.dropBlocksHere||'Drop blocks here')}</small></span><button type="button" class="certloom-email-container-empty__add">+ ${esc(L.addBlock||'Add block')}</button>`;
					q('.certloom-email-container-empty__add',empty)?.addEventListener('click',(event)=>{event.preventDefault();event.stopPropagation();selectedPath=path;render();requestAnimationFrame(()=>{q('[data-certloom-sidebar-tab="insert"]')?.click();q('[data-certloom-add-block]')?.focus();});});
					children.appendChild(empty);
				}
				surface.appendChild(children);element.innerHTML=blockControlsHtml();element.appendChild(surface);
				surface.addEventListener('dragover',(event)=>{const source=getBlockAtPath(dragPath);const targetPath=findPathByIdentity(block);if(!source||source===block||containsBlock(source,block)||!targetPath||!canPlaceAtDepth(source,blockDepth(targetPath)+1)){return;}event.preventDefault();surface.classList.add('is-drop-target');if(event.dataTransfer){event.dataTransfer.dropEffect='move';}});
				surface.addEventListener('dragleave',(event)=>{if(!surface.contains(event.relatedTarget)){surface.classList.remove('is-drop-target');}});
				surface.addEventListener('drop',(event)=>{const source=getBlockAtPath(dragPath);const containerPath=findPathByIdentity(block);if(!source||source===block||containsBlock(source,block)||!containerPath||!canPlaceAtDepth(source,blockDepth(containerPath)+1)){placementError(L.containerDepthLimit||'Container nesting has reached the supported depth.');return;}event.preventDefault();event.stopPropagation();const sourcePath=findPathByIdentity(source);const sourceList=getParentList(sourcePath);if(!sourceList){return;}sourceList.splice(sourcePath[sourcePath.length-1],1);block.children=Array.isArray(block.children)?block.children:[];block.children.push(source);selectedPath=[...containerPath,block.children.length-1];dragPath=null;clearFeedback();markDirty();render();});
			} else { element.innerHTML=blockControlsHtml()+`<div>${blockHtml(block)}</div>`; }
			element.addEventListener('click',(event)=>{event.stopPropagation();if(event.target.closest('[data-up]')){move(path,-1);}else if(event.target.closest('[data-down]')){move(path,1);}else if(event.target.closest('[data-copy]')){copy(path);}else if(event.target.closest('[data-remove]')){remove(path);}else if(!event.target.closest('.certloom-canvas-drag-handle')){selectedPath=path;render();}});
			element.addEventListener('keydown',(event)=>{if(event.key==='Enter'||event.key===' '){event.preventDefault();event.stopPropagation();selectedPath=path;render();}});
			const handle=q('.certloom-canvas-drag-handle',element);handle?.addEventListener('dragstart',(event)=>{dragPath=path;element.classList.add('is-dragging');if(event.dataTransfer){event.dataTransfer.effectAllowed='move';event.dataTransfer.setData('text/plain',pathKey(path));}});handle?.addEventListener('dragend',()=>{dragPath=null;qa('.certloom-canvas-block',canvas).forEach((node)=>node.classList.remove('is-dragging','is-drop-target'));qa('.certloom-email-container-surface',canvas).forEach((node)=>node.classList.remove('is-drop-target'));});
			element.addEventListener('dragover',(event)=>{const source=getBlockAtPath(dragPath);const targetPath=findPathByIdentity(block);if(!source||source===block||containsBlock(source,block)||!targetPath||!canPlaceAtDepth(source,blockDepth(targetPath))){return;}event.preventDefault();event.stopPropagation();element.classList.add('is-drop-target');});
			element.addEventListener('dragleave',()=>element.classList.remove('is-drop-target'));
			element.addEventListener('drop',(event)=>{const source=getBlockAtPath(dragPath);const target=block;const targetPath=findPathByIdentity(target);if(!source||source===block||containsBlock(source,block)||!targetPath||!canPlaceAtDepth(source,blockDepth(targetPath))){placementError(L.containerDepthLimit||'Container nesting has reached the supported depth.');return;}event.preventDefault();event.stopPropagation();const sourcePath=findPathByIdentity(source);const sourceList=getParentList(sourcePath);if(!sourceList){return;}sourceList.splice(sourcePath[sourcePath.length-1],1);const liveTargetPath=findPathByIdentity(target);const targetList=getParentList(liveTargetPath);if(!targetList){return;}const targetIndex=liveTargetPath[liveTargetPath.length-1];targetList.splice(targetIndex,0,source);selectedPath=findPathByIdentity(source);dragPath=null;clearFeedback();markDirty();render();});
			return element;
		}

		function render() {
			canvas.innerHTML='';if(!blocks.length){canvas.innerHTML=`<div class="certloom-email-empty"><p>${esc(L.emptyContent||'Add a content block to start the message.')}</p></div>`;}blocks.forEach((block,index)=>canvas.appendChild(renderBlockNode(block,[index])));renderStructure();renderInspector();applyTheme();scheduleCanvasFit();
		}

		function defaultBlock( type ) {
			if(type==='heading'){return{type:'heading',text:L.headingPlaceholder||'Your credential is ready',level:2};}
			if(type==='text'){return{type:'text',html:'<p>'+(L.textPlaceholder||'Write the message recipients should receive.')+'</p>'};}
			if(type==='button'){return{type:'button',text:L.buttonText||'View credential',url:L.buttonUrl||'{{verification_url}}',align:'left'};}
			if(type==='image'){return{type:'image',url:'',alt:''};}if(type==='divider'){return{type:'divider'};}if(type==='spacer'){return{type:'spacer',height:24};}if(type==='html'){return{type:'html',html:'<p>Custom HTML</p>'};}
			return defaultContainer('vertical',[]);
		}

		function containerPreset( preset ) {
			if(preset==='single'){return defaultContainer('vertical',[]);}
			const widths={ '50-50':[50,50], '33-67':[33,67], '67-33':[67,33], thirds:[33,34,33] }[preset]||[50,50];
			const children=widths.map((basis)=>{const child=defaultContainer('vertical',[]);child.basis=basis;return child;});const parent=defaultContainer('horizontal',children);parent.gap=16;return parent;
		}

		function insertNewBlock( block ) {
			if ( totalBlockCount() + subtreeSize( block ) > MAX_BLOCKS ) { return placementError( L.blockLimit || 'This email has reached the supported block limit.' ); }
			const selected = selectedBlock();
			if ( selected?.type === 'container' ) {
				const depth = blockDepth( selectedPath ) + 1;
				if ( ! canPlaceAtDepth( block, depth ) ) { return placementError( L.containerDepthLimit || 'Container nesting has reached the supported depth.' ); }
				selected.children = Array.isArray( selected.children ) ? selected.children : [];
				if ( selected.direction === 'horizontal' ) {
					const siblingBasis = selected.children.map( ( child ) => Math.max( 5, Math.min( 100, Number( child?.basis ) || 100 ) ) );
					block.basis = siblingBasis.length ? Math.max( 5, Math.min( 100, Math.round( siblingBasis.reduce( ( total, value ) => total + value, 0 ) / siblingBasis.length ) ) ) : 100;
				}
				selected.children.push( block );
				selectedPath = [ ...selectedPath, selected.children.length - 1 ];
			} else if ( selectedPath ) {
				const list = getParentList( selectedPath );
				const depth = blockDepth( selectedPath );
				if ( ! canPlaceAtDepth( block, depth ) ) { return placementError( L.containerDepthLimit || 'Container nesting has reached the supported depth.' ); }
				if ( list ) { const index = selectedPath[ selectedPath.length - 1 ] + 1; list.splice( index, 0, block ); selectedPath = [ ...selectedPath.slice( 0, -1 ), index ]; }
				else { blocks.push( block ); selectedPath = [ blocks.length - 1 ]; }
			} else {
				if ( ! canPlaceAtDepth( block, 0 ) ) { return placementError( L.containerDepthLimit || 'Container nesting has reached the supported depth.' ); }
				blocks.push( block ); selectedPath = [ blocks.length - 1 ];
			}
			clearFeedback(); markDirty(); render(); return true;
		}

		qa('[data-certloom-add-block]').forEach((button)=>button.addEventListener('click',()=>insertNewBlock(defaultBlock(button.dataset.certloomAddBlock))));
		qa('[data-certloom-container-preset]').forEach((button)=>button.addEventListener('click',()=>insertNewBlock(containerPreset(button.dataset.certloomContainerPreset))));

		function move( path, delta ) { const list=getParentList(path);if(!list){return;}const index=path[path.length-1],destination=index+delta;if(destination<0||destination>=list.length){return;}[list[index],list[destination]]=[list[destination],list[index]];selectedPath=[...path.slice(0,-1),destination];markDirty();render(); }
		function copy( path ) { const list=getParentList(path);const block=getBlockAtPath(path);if(!list||!block){return;}if(totalBlockCount()+subtreeSize(block)>MAX_BLOCKS){placementError(L.blockLimit||'This email has reached the supported block limit.');return;}const index=path[path.length-1];list.splice(index+1,0,JSON.parse(JSON.stringify(block)));selectedPath=[...path.slice(0,-1),index+1];clearFeedback();markDirty();render(); }
		function remove( path ) { const list=getParentList(path);if(!list){return;}const index=path[path.length-1];list.splice(index,1);if(list.length){selectedPath=[...path.slice(0,-1),Math.min(index,list.length-1)];}else{selectedPath=path.length>1?path.slice(0,-1):(blocks.length?[Math.min(index,blocks.length-1)]:null);}markDirty();render(); }

		function field( label, key, type = 'text', options = [] ) {
			const block = selectedBlock() || {};
			if ( type === 'select' ) {
				const optionHtml = options.map( ( option ) => {
					const isSelected = String( block[ key ] ?? '' ) === String( option[ 0 ] );
					return `<option value="${ esc( option[ 0 ] ) }" ${ isSelected ? 'selected' : '' }>${ esc( option[ 1 ] ) }</option>`;
				} ).join( '' );
				return `<label><span>${ esc( label ) }</span><select data-field="${ key }">${ optionHtml }</select></label>`;
			}
			if ( type === 'textarea' ) {
				return `<label><span>${ esc( label ) }</span><textarea rows="6" data-field="${ key }" data-certloom-token-input>${ esc( block[ key ] || '' ) }</textarea></label>`;
			}
			return `<label><span>${ esc( label ) }</span><input type="${ type }" data-field="${ key }" value="${ esc( block[ key ] ?? '' ) }" data-certloom-token-input></label>`;
		}

		function styleField( label, key, type = 'text', options = [], attrs = '', className = '' ) {
			const style = blockStyle( selectedBlock() || {} );
			if ( type === 'select' ) {
				const optionHtml = options.map( ( option ) => {
					const isSelected = String( style[ key ] ?? '' ) === String( option[ 0 ] );
					return `<option value="${ esc( option[ 0 ] ) }" ${ isSelected ? 'selected' : '' }>${ esc( option[ 1 ] ) }</option>`;
				} ).join( '' );
				return `<label class="${ esc( className ) }"><span>${ esc( label ) }</span><select data-style-field="${ key }">${ optionHtml }</select></label>`;
			}
			return `<label class="${ esc( className ) }"><span>${ esc( label ) }</span><input type="${ type }" ${ attrs } data-style-field="${ key }" value="${ esc( style[ key ] ?? '' ) }"></label>`;
		}

		function numberStyleField( label, key, min, max, step = 1, unit = 'px' ) {
			const style = blockStyle( selectedBlock() || {} );
			return `<label class="certloom-email-number-field"><span>${ esc( label ) }</span><span class="certloom-email-number-control"><input type="number" min="${ min }" max="${ max }" step="${ step }" data-style-field="${ key }" value="${ esc( style[ key ] ?? '' ) }"><span>${ esc( unit ) }</span></span></label>`;
		}

		function rangeStyleField( label, key, min = 0, max = 100, step = 1, fallback = 100 ) {
			const style = blockStyle( selectedBlock() || {} );
			const value = style[ key ] === undefined || style[ key ] === '' ? fallback : Number( style[ key ] );
			return `<label class="certloom-email-range-field"><span>${ esc( label ) }</span><span class="certloom-email-range-control"><input type="range" min="${ min }" max="${ max }" step="${ step }" data-style-field="${ key }" value="${ esc( value ) }"><output>${ esc( value ) }%</output></span></label>`;
		}

		function segmentedStyleField( label, key, options ) {
			const style = blockStyle( selectedBlock() || {} );
			const current = String( style[ key ] || '' );
			const buttons = options.map( ( option ) => `<button type="button" class="${ current === String( option[ 0 ] ) ? 'is-active' : '' }" data-style-choice="${ key }" data-style-value="${ esc( option[ 0 ] ) }" aria-pressed="${ current === String( option[ 0 ] ) ? 'true' : 'false' }" title="${ esc( option[ 1 ] ) }">${ option[ 2 ] || esc( option[ 1 ] ) }</button>` ).join( '' );
			return `<div class="certloom-email-segmented-field"><span>${ esc( label ) }</span><div class="certloom-email-segmented" role="group" aria-label="${ esc( label ) }">${ buttons }</div></div>`;
		}

		function segmentedBlockField( label, key, options ) {
			const block = selectedBlock() || {};
			const current = String( block[ key ] || '' );
			const buttons = options.map( ( option ) => `<button type="button" class="${ current === String( option[ 0 ] ) ? 'is-active' : '' }" data-block-choice="${ key }" data-block-value="${ esc( option[ 0 ] ) }" aria-pressed="${ current === String( option[ 0 ] ) ? 'true' : 'false' }" title="${ esc( option[ 1 ] ) }">${ option[ 2 ] || esc( option[ 1 ] ) }</button>` ).join( '' );
			return `<div class="certloom-email-segmented-field"><span>${ esc( label ) }</span><div class="certloom-email-segmented" role="group" aria-label="${ esc( label ) }">${ buttons }</div></div>`;
		}

		function toggleStyleButtons( options ) {
			const style = blockStyle( selectedBlock() || {} );
			return `<div class="certloom-email-inline-toggles" role="group">${ options.map( ( option ) => `<button type="button" class="${ style[ option[ 0 ] ] ? 'is-active' : '' }" data-style-toggle="${ option[ 0 ] }" aria-pressed="${ style[ option[ 0 ] ] ? 'true' : 'false' }" title="${ esc( option[ 1 ] ) }">${ option[ 2 ] }</button>` ).join( '' ) }</div>`;
		}

		function colorStyleField( label, key, fallback ) {
			const style = blockStyle( selectedBlock() || {} );
			const value = safeColor( style[ key ], fallback );
			return `<label class="certloom-email-color-field"><span>${ esc( label ) }</span><span class="certloom-email-color-control"><input type="color" value="${ esc( value ) }" data-style-color-picker="${ key }" aria-label="${ esc( label ) }"><input type="text" maxlength="7" pattern="#[0-9A-Fa-f]{6}" value="${ esc( style[ key ] || '' ) }" placeholder="${ esc( fallback ) }" data-style-field="${ key }"><button type="button" class="certloom-email-color-reset" data-style-reset="${ key }" title="${ esc( L.reset || 'Reset' ) }" aria-label="${ esc( ( L.reset || 'Reset' ) + ' ' + label ) }">&#8634;</button></span></label>`;
		}

		function richTextField() {
			const block = selectedBlock() || {};
			return `<div class="certloom-email-rich-field"><span class="certloom-email-rich-label">${ esc( L.contentLabel || 'Content' ) }</span><div class="certloom-email-rich-toolbar" role="toolbar" aria-label="${ esc( L.textFormatting || 'Text formatting' ) }"><button type="button" data-rich-command="bold" aria-label="${ esc( L.bold || 'Bold' ) }" title="${ esc( L.bold || 'Bold' ) }"><strong>B</strong></button><button type="button" data-rich-command="italic" aria-label="${ esc( L.italic || 'Italic' ) }" title="${ esc( L.italic || 'Italic' ) }"><em>I</em></button><button type="button" data-rich-command="underline" aria-label="${ esc( L.underline || 'Underline' ) }" title="${ esc( L.underline || 'Underline' ) }"><u>U</u></button><span class="certloom-email-rich-toolbar__divider"></span><button type="button" data-rich-command="insertUnorderedList" aria-label="${ esc( L.bulletedList || 'Bulleted list' ) }" title="${ esc( L.bulletedList || 'Bulleted list' ) }">&bull;&#8801;</button><button type="button" data-rich-command="insertOrderedList" aria-label="${ esc( L.numberedList || 'Numbered list' ) }" title="${ esc( L.numberedList || 'Numbered list' ) }">1&#8801;</button><span class="certloom-email-rich-toolbar__divider"></span><button type="button" data-rich-link="add" aria-label="${ esc( L.addLink || 'Add link' ) }" title="${ esc( L.addLink || 'Add link' ) }">&#8599;</button><button type="button" data-rich-link="remove" aria-label="${ esc( L.removeLink || 'Remove link' ) }" title="${ esc( L.removeLink || 'Remove link' ) }">Ã&#8599;</button><button type="button" data-rich-token-picker aria-label="${ esc( L.insertData || 'Insert data' ) }" title="${ esc( L.insertData || 'Insert data' ) }">{{}}</button></div><div class="certloom-email-rich-editor" contenteditable="true" role="textbox" aria-multiline="true" data-rich-text-editor data-certloom-token-input>${ block.html || '' }</div></div>`;
		}

		function inspectorSection( titleText, contents, open = true ) {
			return `<details class="certloom-inspector-section" ${ open ? 'open' : '' }><summary><span>${ esc( titleText ) }</span></summary><div class="certloom-inspector-section__body">${ contents }</div></details>`;
		}

		function containerSelectField( label, key, options ) {
			const block=selectedBlock()||{};return `<label><span>${esc(label)}</span><select data-container-field="${esc(key)}">${options.map((option)=>`<option value="${esc(option[0])}" ${String(block[key]??'')===String(option[0])?'selected':''}>${esc(option[1])}</option>`).join('')}</select></label>`;
		}
		function containerNumberField( label, key, min, max, unit='px' ) { const block=selectedBlock()||{};return `<label class="certloom-email-number-field"><span>${esc(label)}</span><span class="certloom-email-number-control"><input type="number" min="${min}" max="${max}" step="1" data-container-field="${esc(key)}" value="${esc(block[key]??'')}"><span>${esc(unit)}</span></span></label>`; }
		function containerRangeField( label, key, fallback=100 ) { const block=selectedBlock()||{};const value=block[key]===undefined||block[key]===''?fallback:Number(block[key]);return `<label class="certloom-email-range-field"><span>${esc(label)}</span><span class="certloom-email-range-control"><input type="range" min="0" max="100" step="1" data-container-field="${esc(key)}" value="${esc(value)}"><output>${esc(value)}%</output></span></label>`; }
		function containerColorField( label, key, fallback='#ffffff' ) { const block=selectedBlock()||{};const value=safeColor(block[key],fallback);return `<label class="certloom-email-color-field"><span>${esc(label)}</span><span class="certloom-email-color-control"><input type="color" value="${esc(value)}" data-container-color-picker="${esc(key)}" aria-label="${esc(label)}"><input type="text" maxlength="7" pattern="#[0-9A-Fa-f]{6}" value="${esc(block[key]||'')}" placeholder="${esc(fallback)}" data-container-field="${esc(key)}"><button type="button" class="certloom-email-color-reset" data-container-reset="${esc(key)}" aria-label="${esc((L.reset||'Reset')+' '+label)}">&#8634;</button></span></label>`; }
		function containerCompactSelectField( label, key, options ) {
			const block=selectedBlock()||{};
			return `<label class="certloom-container-compact-select"><span>${esc(label)}</span><select data-container-field="${esc(key)}">${options.map((option)=>`<option value="${esc(option[0])}" ${String(block[key]??'')===String(option[0])?'selected':''}>${esc(option[1])}</option>`).join('')}</select></label>`;
		}

		function containerInspectorFields( block ) {
			const direction=containerSelectField(L.containerDirection||'Direction','direction',[['vertical',L.vertical||'Vertical'],['horizontal',L.horizontal||'Horizontal']]);
			const basis=selectedPath&&selectedPath.length>1?containerNumberField(L.containerBasis||'Width','basis',5,100,'%'):'';
			const layout=`<div class="certloom-inspector-grid certloom-inspector-grid--2">${direction}${basis}${containerNumberField(L.containerGap||'Gap','gap',0,48)}${containerNumberField('Minimum height','min_height',0,800)}</div><div class="certloom-inspector-grid certloom-inspector-grid--2">${containerSelectField('Vertical alignment','valign',[['top','Top'],['middle','Middle'],['bottom','Bottom']])}${containerSelectField('Content alignment','align',[['left','Left'],['center','Center'],['right','Right']])}</div><label class="certloom-email-toggle-row"><input type="checkbox" data-container-toggle="stack_mobile" ${block.stack_mobile!==false?'checked':''}><span>Stack horizontal items on mobile</span></label>`;
			const pad={top:0,right:0,bottom:0,left:0,...(block.padding||{})};
			const spacing=`<div class="certloom-container-padding-grid">${['top','right','bottom','left'].map((edge)=>`<label><span>${edge[0].toUpperCase()+edge.slice(1)}</span><span class="certloom-email-number-control"><input type="number" min="0" max="80" value="${esc(pad[edge]||0)}" data-container-padding="${edge}"><span>px</span></span></label>`).join('')}</div>`;
			const bgThumb=block.background_image?`<div class="certloom-container-background-preview"><img src="${esc(block.background_image)}" alt=""><span>${esc(block.background_image.split('/').pop()||'Background image')}</span></div>`:'';
			const background=`<div class="certloom-container-subsection certloom-container-background-panel"><div class="certloom-container-subsection__heading"><strong>${esc(L.background||'Background')}</strong><small>${esc(L.containerBackground||'Container background')}</small></div>${containerColorField(L.backgroundColor || 'Background color','background_color','#ffffff')}${containerRangeField(L.backgroundColorOpacity || 'Background color opacity','background_opacity',100)}<div class="certloom-container-background-media"><div class="certloom-container-background-media__label">${esc(L.backgroundImage||'Background image')}</div>${bgThumb}<div class="certloom-theme-background-actions"><button type="button" class="button" data-container-background-image>${esc(block.background_image?'Replace image':(L.chooseBackground||'Choose background image'))}</button>${block.background_image?`<button type="button" class="button" data-container-background-remove>${esc(L.removeBackground||'Remove background')}</button>`:''}</div></div><div class="certloom-container-background-settings">${containerCompactSelectField('Size','background_size',[['cover','Cover'],['contain','Contain'],['auto','Auto']])}${containerCompactSelectField('Position','background_position',[['center','Center'],['top','Top'],['bottom','Bottom'],['left','Left'],['right','Right']])}${containerCompactSelectField('Repeat','background_repeat',[['no-repeat','No repeat'],['repeat','Repeat'],['repeat-x','Repeat X'],['repeat-y','Repeat Y']])}</div><div class="certloom-container-overlay-settings ${block.background_image?'':'is-muted'}">${containerColorField(L.overlayColor || 'Overlay color','overlay_color','#000000')}${containerRangeField(L.overlayOpacity || 'Overlay opacity','overlay_opacity',0)}</div></div>`;
			const border=`<div class="certloom-container-subsection"><div class="certloom-container-subsection__heading"><strong>${esc(L.border||'Border')}</strong></div><div class="certloom-inspector-grid certloom-inspector-grid--2">${containerColorField(L.borderColor || 'Border color','border_color','#d8dee8')}${containerNumberField(L.borderWidth || 'Border width','border_width',0,8)}${containerSelectField(L.borderStyle || 'Border style','border_style',[['solid',L.solid || 'Solid'],['dashed',L.dashed || 'Dashed'],['dotted',L.dotted || 'Dotted']])}${containerNumberField('Corner radius','border_radius',0,40)}</div></div>`;
			const count=Array.isArray(block.children)?block.children.length:0;
			const content=`<div class="certloom-container-summary"><span class="certloom-container-summary__icon">▥</span><span><strong>${esc(count+' '+(count===1?'item':'items'))}</strong><small>${esc(L.containerAddHelp||'Add blocks from Insert or drag existing blocks into this Container.')}</small></span></div>`;
			return { content, layout, spacing, colors:background+border };
		}

		function renderInspector() {
			if ( ! selectedBlock() ) {
				if ( inspectorHeading ) { inspectorHeading.textContent = 'Block settings'; }
				if ( inspectorPath ) { inspectorPath.hidden = true; inspectorPath.textContent = ''; }
				inspector.innerHTML = `<div class="certloom-inspector-empty"><p>${ esc( L.selectBlock || 'Select a block on the canvas to edit it.' ) }</p></div>`;
				return;
			}

			const block = selectedBlock();
			if ( inspectorHeading ) { inspectorHeading.textContent = blockLabel( block ); }
			if ( inspectorPath ) { inspectorPath.textContent = inspectorPathText( selectedPath ); inspectorPath.hidden = false; }
			let contentFields = '', typographyFields = '', colorFields = '', layoutFields = '', spacingFields = '';
			const weightOptions = [ [ '', L.inheritGlobal || 'Default' ], [ 400, 'Regular' ], [ 500, 'Medium' ], [ 600, 'Semi-bold' ], [ 700, 'Bold' ], [ 800, 'Extra bold' ], [ 900, 'Black' ] ];
			const alignOptions = [ [ 'left', 'Align left', 'L' ], [ 'center', 'Align center', 'C' ], [ 'right', 'Align right', 'R' ] ];
			const textToggles = [ [ 'italic', L.italic || 'Italic', '<em>I</em>' ], [ 'underline', L.underline || 'Underline', '<u>U</u>' ] ];

			if ( block.type === 'heading' ) {
				contentFields += `<div class="certloom-inspector-grid certloom-inspector-grid--content">${ field( L.textLabel || 'Text', 'text' ) }${ field( L.headingLevel || 'Heading level', 'level', 'select', [ [ 1, 'H1' ], [ 2, 'H2' ], [ 3, 'H3' ] ] ) }</div>`;
				typographyFields += `<div class="certloom-inspector-grid certloom-inspector-grid--2">${ fontStyleField( L.fontFamily || 'Font family', 'font_family' ) }${ numberStyleField( L.fontSize || 'Font size', 'font_size', 12, 72 ) }${ styleField( L.fontWeight || 'Font weight', 'font_weight', 'select', weightOptions ) }${ numberStyleField( L.lineHeight || 'Line height', 'line_height', 1, 2.5, .05, 'x' ) }${ numberStyleField( L.letterSpacing || 'Letter spacing', 'letter_spacing', -5, 20, .1, 'px' ) }${ styleField( L.textTransform || 'Text transform', 'text_transform', 'select', [ [ 'none', L.none || 'None' ], [ 'uppercase', L.uppercase || 'Uppercase' ], [ 'lowercase', L.lowercase || 'Lowercase' ], [ 'capitalize', L.capitalize || 'Capitalize' ] ] ) }</div>${ toggleStyleButtons( textToggles ) }${ segmentedStyleField( L.alignment || 'Alignment', 'align', alignOptions ) }`;
				colorFields += colorStyleField( L.textColor || 'Text color', 'color', '#173f34' );
			}
			if ( block.type === 'text' ) {
				contentFields += richTextField();
				typographyFields += `<div class="certloom-inspector-grid certloom-inspector-grid--2">${ fontStyleField( L.fontFamily || 'Font family', 'font_family' ) }${ numberStyleField( L.fontSize || 'Font size', 'font_size', 10, 32 ) }${ styleField( L.fontWeight || 'Font weight', 'font_weight', 'select', weightOptions ) }${ numberStyleField( L.lineHeight || 'Line height', 'line_height', 1, 2.5, .05, 'x' ) }${ numberStyleField( L.letterSpacing || 'Letter spacing', 'letter_spacing', -5, 20, .1, 'px' ) }${ styleField( L.textTransform || 'Text transform', 'text_transform', 'select', [ [ 'none', L.none || 'None' ], [ 'uppercase', L.uppercase || 'Uppercase' ], [ 'lowercase', L.lowercase || 'Lowercase' ], [ 'capitalize', L.capitalize || 'Capitalize' ] ] ) }</div>${ toggleStyleButtons( textToggles ) }${ segmentedStyleField( L.alignment || 'Alignment', 'align', alignOptions ) }`;
				colorFields += colorStyleField( L.textColor || 'Text color', 'color', '#24322e' );
			}
			if ( block.type === 'button' ) {
				contentFields += `<div class="certloom-inspector-grid certloom-inspector-grid--content">${ field( L.buttonTextLabel || 'Button text', 'text' ) }${ field( L.urlLabel || 'URL', 'url' ) }</div>`;
				typographyFields += `<div class="certloom-inspector-grid certloom-inspector-grid--2">${ fontStyleField( L.fontFamily || 'Font family', 'font_family' ) }${ numberStyleField( L.fontSize || 'Font size', 'font_size', 10, 28 ) }${ styleField( L.fontWeight || 'Font weight', 'font_weight', 'select', weightOptions ) }${ numberStyleField( L.letterSpacing || 'Letter spacing', 'letter_spacing', -5, 20, .1, 'px' ) }${ styleField( L.textTransform || 'Text transform', 'text_transform', 'select', [ [ 'none', L.none || 'None' ], [ 'uppercase', L.uppercase || 'Uppercase' ], [ 'lowercase', L.lowercase || 'Lowercase' ], [ 'capitalize', L.capitalize || 'Capitalize' ] ] ) }</div>${ toggleStyleButtons( textToggles ) }`;
				colorFields += `<div class="certloom-inspector-grid certloom-inspector-grid--colors">${ colorStyleField( L.textColor || 'Text color', 'color', '#ffffff' ) }${ colorStyleField( L.backgroundColor || 'Background color', 'background_color', '#1369fc' ) }${ rangeStyleField( L.backgroundOpacity || 'Background opacity', 'background_opacity', 0, 100, 1, 100 ) }${ colorStyleField( L.borderColor || 'Border color', 'border_color', '#1369fc' ) }</div>`;
				layoutFields += segmentedBlockField( L.alignment || 'Alignment', 'align', alignOptions ) + `<label class="certloom-email-toggle-row"><input type="checkbox" data-style-boolean="full_width" ${ blockStyle( block ).full_width ? 'checked' : '' }><span>${esc(L.fullWidthButton || 'Full width button')}</span></label>`;
				spacingFields += `<div class="certloom-inspector-grid certloom-inspector-grid--3">${ numberStyleField( L.horizontalPadding || 'Horizontal', 'padding_x', 8, 48 ) }${ numberStyleField( L.verticalPadding || 'Vertical', 'padding_y', 6, 32 ) }${ numberStyleField( L.cornerRadius || 'Radius', 'border_radius', 0, 32 ) }${ numberStyleField( L.verticalMargin || 'Vertical margin', 'margin_y', 0, 60 ) }${ numberStyleField( L.borderWidth || 'Border width', 'border_width', 0, 8 ) }${ styleField( L.borderStyle || 'Border style', 'border_style', 'select', [ [ 'solid', L.solid || 'Solid' ], [ 'dashed', L.dashed || 'Dashed' ], [ 'dotted', L.dotted || 'Dotted' ] ] ) }</div>`;
			}
			if ( block.type === 'image' ) {
				contentFields += `<div class="certloom-inspector-stack">${ field( L.urlLabel || 'URL', 'url' ) }${ field( L.altText || 'Alternative text', 'alt' ) }${ field( 'Link (optional)', 'link' ) }<button type="button" class="button certloom-inspector-media-button" data-choose-image>${ esc( L.chooseImage || 'Choose image' ) }</button></div>`;
				layoutFields += segmentedStyleField( L.alignment || 'Alignment', 'align', alignOptions );
				colorFields += `${ rangeStyleField( L.imageOpacity || 'Image opacity', 'opacity', 0, 100, 1, 100 ) }${ colorStyleField( L.borderColor || 'Border color', 'border_color', '#d8dee8' ) }`;
				spacingFields += `<div class="certloom-inspector-grid certloom-inspector-grid--2">${ numberStyleField( L.imageWidth || 'Image width', 'width', 10, 100, 1, '%' ) }${ numberStyleField( L.cornerRadius || 'Corner radius', 'border_radius', 0, 32 ) }${ numberStyleField( L.borderWidth || 'Border width', 'border_width', 0, 8 ) }${ styleField( L.borderStyle || 'Border style', 'border_style', 'select', [ [ 'solid', L.solid || 'Solid' ], [ 'dashed', L.dashed || 'Dashed' ], [ 'dotted', L.dotted || 'Dotted' ] ] ) }</div>`;
			}
			if ( block.type === 'spacer' ) { layoutFields += field( L.spacingPixels || 'Spacing', 'height', 'number' ); }
			if ( block.type === 'container' ) { const containerFields=containerInspectorFields(block);contentFields+=containerFields.content;layoutFields+=containerFields.layout;colorFields+=containerFields.colors;spacingFields+=containerFields.spacing; }
			if ( block.type === 'html' ) { contentFields += `<label><span>${ esc( L.htmlLabel || 'HTML' ) }</span><textarea rows="14" class="certloom-code-field" data-field="html" data-certloom-token-input>${ esc( block.html || '' ) }</textarea><small>Safe email HTML is rendered on the canvas and in Preview even when the visual builder has no equivalent controls.</small></label>`; }
			if ( block.type === 'divider' ) {
				colorFields += colorStyleField( L.dividerColor || 'Divider color', 'color', '#dce6e1' );
				layoutFields += `<div class="certloom-inspector-grid certloom-inspector-grid--2">${ numberStyleField( L.dividerWidth || 'Divider width', 'width', 10, 100, 1, '%' ) }${ numberStyleField( L.dividerThickness || 'Thickness', 'thickness', 1, 8 ) }${ styleField( L.lineStyle || 'Line style', 'line_style', 'select', [ [ 'solid', L.solid || 'Solid' ], [ 'dashed', L.dashed || 'Dashed' ], [ 'dotted', L.dotted || 'Dotted' ] ] ) }</div>${ segmentedStyleField( L.alignment || 'Alignment', 'align', alignOptions ) }`;
				spacingFields += numberStyleField( L.verticalSpacing || 'Vertical spacing', 'margin_y', 4, 60 );
			}

			const actions = `<div class="certloom-inspector-actions certloom-inspector-actions--compact"><button type="button" class="button" data-inspector-up title="${ esc( L.moveUp || 'Move up' ) }" aria-label="${ esc( L.moveUp || 'Move up' ) }"> &uarr;</button><button type="button" class="button" data-inspector-down title="${ esc( L.moveDown || 'Move down' ) }" aria-label="${ esc( L.moveDown || 'Move down' ) }"> &darr;</button><button type="button" class="button" data-inspector-copy title="${ esc( L.duplicate || 'Duplicate' ) }" aria-label="${ esc( L.duplicate || 'Duplicate' ) }"> &#10697;</button><button type="button" class="button is-destructive" data-inspector-remove title="${ esc( L.remove || 'Remove' ) }" aria-label="${ esc( L.remove || 'Remove' ) }"> &#9003;</button></div>`;

			let body = '<div class="certloom-inspector-form">';
			if ( contentFields ) { body += inspectorSection( L.contentSection || 'Content', contentFields, true ); }
			if ( typographyFields ) { body += inspectorSection( L.typographySection || 'Typography', typographyFields, true ); }
			if ( colorFields ) { body += inspectorSection( L.colorsSection || 'Colors', colorFields, true ); }
			if ( layoutFields ) { body += inspectorSection( L.layoutSection || 'Layout', layoutFields, true ); }
			if ( spacingFields ) { body += inspectorSection( L.spacingSection || 'Spacing', spacingFields, false ); }
			body += inspectorSection( L.blockActions || 'Block actions', actions, false ) + '</div>';
			inspector.innerHTML = body;
			refreshFontAvailabilityLabels( inspector );

			qa( '[data-field]', inspector ).forEach( ( input ) => {
				input.addEventListener( 'focus', () => { lastTokenField = input; } );
				input.addEventListener( 'input', () => {
					let value = input.value;
					if ( input.type === 'number' ) { value = parseInt( value || '0', 10 ); }
					selectedBlock()[ input.dataset.field ] = value;
					markDirty(); renderCanvasOnly(); renderStructure();
				} );
			} );

			qa( '[data-style-field]', inspector ).forEach( ( input ) => {
				input.addEventListener( 'input', () => {
					const state = blockStyle( selectedBlock() ); selectedBlock().style = state;
					let value = input.value; if ( input.type === 'number' || input.type === 'range' ) { value = input.value === '' ? '' : Number( input.value ); }
					if ( value === '' ) { delete state[ input.dataset.styleField ]; } else { state[ input.dataset.styleField ] = value; }
					const picker = q( `[data-style-color-picker="${ input.dataset.styleField }"]`, inspector ); if ( picker && /^#[0-9A-Fa-f]{6}$/.test( String( value ) ) ) { picker.value = String( value ); }
					const output = input.closest( '.certloom-email-range-control' )?.querySelector( 'output' ); if ( output ) { output.textContent = `${ value }%`; }
					markDirty(); renderCanvasOnly();
				} );
			} );

			qa( '[data-style-choice]', inspector ).forEach( ( button ) => button.addEventListener( 'click', () => {
				const state = blockStyle( selectedBlock() ); selectedBlock().style = state;
				state[ button.dataset.styleChoice ] = button.dataset.styleValue;
				button.parentElement.querySelectorAll( 'button' ).forEach( ( item ) => { const active = item === button; item.classList.toggle( 'is-active', active ); item.setAttribute( 'aria-pressed', active ? 'true' : 'false' ); } );
				markDirty(); renderCanvasOnly();
			} ) );
			qa( '[data-block-choice]', inspector ).forEach( ( button ) => button.addEventListener( 'click', () => {
				selectedBlock()[ button.dataset.blockChoice ] = button.dataset.blockValue;
				button.parentElement.querySelectorAll( 'button' ).forEach( ( item ) => { const active = item === button; item.classList.toggle( 'is-active', active ); item.setAttribute( 'aria-pressed', active ? 'true' : 'false' ); } );
				markDirty(); renderCanvasOnly();
			} ) );
			qa( '[data-style-boolean]', inspector ).forEach( ( input ) => input.addEventListener( 'change', () => {
				const state = blockStyle( selectedBlock() ); selectedBlock().style = state; state[ input.dataset.styleBoolean ] = !! input.checked; markDirty(); renderCanvasOnly();
			} ) );

			qa( '[data-style-toggle]', inspector ).forEach( ( button ) => button.addEventListener( 'click', () => {
				const state = blockStyle( selectedBlock() ); selectedBlock().style = state; const key = button.dataset.styleToggle;
				if ( state[ key ] ) { delete state[ key ]; } else { state[ key ] = true; }
				const active = !! state[ key ]; button.classList.toggle( 'is-active', active ); button.setAttribute( 'aria-pressed', active ? 'true' : 'false' ); markDirty(); renderCanvasOnly();
			} ) );

			qa( '[data-style-color-picker]', inspector ).forEach( ( picker ) => picker.addEventListener( 'input', () => {
				const key = picker.dataset.styleColorPicker; const fieldNode = q( `[data-style-field="${ key }"]`, inspector ); if ( fieldNode ) { fieldNode.value = picker.value.toUpperCase(); fieldNode.dispatchEvent( new Event( 'input', { bubbles: true } ) ); }
			} ) );
			qa( '[data-style-reset]', inspector ).forEach( ( button ) => button.addEventListener( 'click', () => {
				const state = blockStyle( selectedBlock() ); delete state[ button.dataset.styleReset ]; selectedBlock().style = state; markDirty(); renderCanvasOnly(); renderInspector();
			} ) );

			qa( '[data-container-field]', inspector ).forEach( ( input ) => input.addEventListener( input.tagName === 'SELECT' ? 'change' : 'input', () => { const block=selectedBlock();if(!block||block.type!=='container'){return;}let value=input.value;if(input.type==='number'||input.type==='range'){value=Number(value)||0;}block[input.dataset.containerField]=value;const picker=q(`[data-container-color-picker="${input.dataset.containerField}"]`,inspector);if(picker&&/^#[0-9A-Fa-f]{6}$/.test(String(value))){picker.value=String(value);}const output=input.closest('.certloom-email-range-control')?.querySelector('output');if(output){output.textContent=`${value}%`;}markDirty();renderCanvasOnly();renderStructure(); } ) );
			qa( '[data-container-padding]', inspector ).forEach( ( input ) => input.addEventListener( 'input', () => { const block=selectedBlock();if(!block||block.type!=='container'){return;}block.padding={top:0,right:0,bottom:0,left:0,...(block.padding||{})};block.padding[input.dataset.containerPadding]=Math.max(0,Math.min(80,Number(input.value)||0));markDirty();renderCanvasOnly(); } ) );
			qa( '[data-container-toggle]', inspector ).forEach( ( input ) => input.addEventListener( 'change', () => { const block=selectedBlock();if(!block||block.type!=='container'){return;}block[input.dataset.containerToggle]=!!input.checked;markDirty();renderCanvasOnly(); } ) );
			qa( '[data-container-color-picker]', inspector ).forEach( ( picker ) => picker.addEventListener( 'input', () => { const fieldNode=q(`[data-container-field="${picker.dataset.containerColorPicker}"]`,inspector);if(fieldNode){fieldNode.value=picker.value.toUpperCase();fieldNode.dispatchEvent(new Event('input',{bubbles:true}));} } ) );
			qa( '[data-container-reset]', inspector ).forEach( ( button ) => button.addEventListener( 'click', () => { const block=selectedBlock();if(!block||block.type!=='container'){return;}block[button.dataset.containerReset]='';markDirty();renderCanvasOnly();renderInspector(); } ) );
			q( '[data-container-background-image]', inspector )?.addEventListener( 'click', chooseContainerBackground );
			q( '[data-container-background-remove]', inspector )?.addEventListener( 'click', () => { const block=selectedBlock();if(!block||block.type!=='container'){return;}block.background_image='';markDirty();renderCanvasOnly();renderInspector(); } );

			const richEditor = q( '[data-rich-text-editor]', inspector );
			if ( richEditor ) {
				lastTokenField = richEditor;
				const syncRich = () => { selectedBlock().html = richEditor.innerHTML; markDirty(); renderCanvasOnly(); renderStructure(); };
				richEditor.addEventListener( 'focus', () => { lastTokenField = richEditor; } ); richEditor.addEventListener( 'input', syncRich );
				qa( '[data-rich-command]', inspector ).forEach( ( button ) => { button.addEventListener( 'mousedown', ( event ) => event.preventDefault() ); button.addEventListener( 'click', () => { richEditor.focus(); document.execCommand( button.dataset.richCommand, false, null ); syncRich(); } ); } );
				qa( '[data-rich-link]', inspector ).forEach( ( button ) => { button.addEventListener( 'mousedown', ( event ) => event.preventDefault() ); button.addEventListener( 'click', () => { richEditor.focus(); if ( button.dataset.richLink === 'remove' ) { document.execCommand( 'unlink', false, null ); } else { const url = window.prompt( L.urlLabel || 'URL', 'https://' ); if ( url ) { document.execCommand( 'createLink', false, url ); } } syncRich(); } ); } );
				q( '[data-rich-token-picker]', inspector )?.addEventListener( 'click', () => { lastTokenField = richEditor; q( '[data-certloom-sidebar-tab="data"]' )?.click(); } );
			}

			q( '[data-inspector-up]', inspector )?.addEventListener( 'click', () => move( selectedPath, -1 ) ); q( '[data-inspector-down]', inspector )?.addEventListener( 'click', () => move( selectedPath, 1 ) ); q( '[data-inspector-copy]', inspector )?.addEventListener( 'click', () => copy( selectedPath ) ); q( '[data-inspector-remove]', inspector )?.addEventListener( 'click', () => remove( selectedPath ) ); q( '[data-choose-image]', inspector )?.addEventListener( 'click', chooseImage );
		}

		function renderCanvasOnly() {
			canvas.innerHTML='';if(!blocks.length){canvas.innerHTML=`<div class="certloom-email-empty"><p>${esc(L.emptyContent||'Add a content block to start the message.')}</p></div>`;}blocks.forEach((block,index)=>canvas.appendChild(renderBlockNode(block,[index])));renderStructure();applyTheme();scheduleCanvasFit();
		}

		function chooseImage() {
			if(!window.wp?.media){return;}const target=selectedBlock();if(!target||target.type!=='image'){return;}const frame=wp.media({title:L.chooseImage||'Choose an email image',button:{text:L.useImage||'Use this image'},multiple:false});frame.on('select',()=>{const attachment=frame.state().get('selection').first().toJSON();target.url=attachment.url||'';target.alt=attachment.alt||'';markDirty();render();});frame.open();
		}

		function chooseContainerBackground() {
			if(!window.wp?.media){return;}const target=selectedBlock();if(!target||target.type!=='container'){return;}const frame=wp.media({title:L.chooseBackground||'Choose background image',button:{text:L.useImage||'Use this image'},multiple:false});frame.on('select',()=>{const attachment=frame.state().get('selection').first().toJSON();target.background_image=attachment.url||'';markDirty();render();});frame.open();
		}

		function applyTheme() {
			const values = {};
			qa( '[data-certloom-theme]', form ).forEach( ( input ) => {
				values[ input.name.replace( 'theme_', '' ) ] = input.value;
			} );
			const pageImage = values.background_image || '';
			const contentImage = values.content_background_image || '';
			if ( stageViewport ) {
				stageViewport.style.backgroundColor = rgbaColor( values.background, values.background_opacity ?? 100, '#f4f7f6' );
				const pageOverlay = Math.max( 0, Math.min( 100, Number( values.background_overlay_opacity ) || 0 ) );
				const pageUrl = pageImage ? `url("${ String( pageImage ).replace( /"/g, '%22' ) }")` : '';
				stageViewport.style.backgroundImage = pageUrl ? `${ pageOverlay ? `linear-gradient(${ rgbaColor( values.background_overlay_color, pageOverlay ) },${ rgbaColor( values.background_overlay_color, pageOverlay ) }),` : '' }${ pageUrl }` : '';
				stageViewport.style.backgroundSize = values.background_size || 'cover';
				stageViewport.style.backgroundPosition = values.background_position || 'center';
				stageViewport.style.backgroundRepeat = values.background_repeat || 'no-repeat';
			}
			canvas.style.backgroundColor = rgbaColor( values.content_background, values.content_background_opacity ?? 100, '#ffffff' );
			const contentOverlay = Math.max( 0, Math.min( 100, Number( values.content_background_overlay_opacity ) || 0 ) );
			const contentUrl = contentImage ? `url("${ String( contentImage ).replace( /"/g, '%22' ) }")` : '';
			canvas.style.backgroundImage = contentUrl ? `${ contentOverlay ? `linear-gradient(${ rgbaColor( values.content_background_overlay_color, contentOverlay ) },${ rgbaColor( values.content_background_overlay_color, contentOverlay ) }),` : '' }${ contentUrl }` : '';
			canvas.style.backgroundSize = values.content_background_size || 'cover';
			canvas.style.backgroundPosition = values.content_background_position || 'center';
			canvas.style.backgroundRepeat = values.content_background_repeat || 'no-repeat';
			canvas.style.color = values.text_color || '#24322e';
			canvas.style.fontFamily = values.font_family || 'Arial, Helvetica, sans-serif';
			canvas.style.borderRadius = ( parseInt( values.border_radius || '12', 10 ) ) + 'px';
			const emailWidth = parseInt( values.email_width || '640', 10 );
			canvas.style.maxWidth = emailWidth + 'px';
			canvas.style.setProperty( '--certloom-email-heading-color', values.heading_color || '#173f34' );
			canvas.style.setProperty( '--certloom-email-button-bg', values.button_background || '#1369fc' );
			canvas.style.setProperty( '--certloom-email-button-text', values.button_text || '#ffffff' );
			canvas.style.setProperty( '--certloom-email-link-color', values.link_color || '#0f6e56' );
			canvasFrame?.style.setProperty( '--certloom-email-width', emailWidth + 'px' );
			if ( stageMeta ) {
				stageMeta.textContent = emailWidth + 'px · ' + blocks.length + ( blocks.length === 1 ? ' block' : ' blocks' );
			}
			scheduleCanvasFit();
		}

		qa( '[data-certloom-theme-color]', form ).forEach( ( picker ) => {
			picker.addEventListener( 'input', () => {
				const hex = q( `[data-certloom-theme-hex="${ picker.dataset.certloomThemeColor }"]`, form );
				if ( hex ) { hex.value = picker.value.toUpperCase(); }
			} );
		} );
		qa( '[data-certloom-theme-hex]', form ).forEach( ( hex ) => {
			hex.addEventListener( 'input', () => {
				const value = String( hex.value || '' ).trim();
				if ( /^#[0-9A-Fa-f]{6}$/.test( value ) ) {
					const picker = q( `[data-certloom-theme-color="${ hex.dataset.certloomThemeHex }"]`, form );
					if ( picker ) { picker.value = value; }
				}
			} );
		} );
		qa( '[data-certloom-theme]', form ).forEach( ( input ) => {
			input.addEventListener( 'input', () => {
				const output = input.closest( '.certloom-email-range-control' )?.querySelector( 'output' ); if ( output ) { output.textContent = `${ input.value }%`; }
				markDirty();
				applyTheme();
			} );
		} );

		function setThemeMedia( key, url, label = '' ) {
			const input = q( `[data-certloom-theme-media="${ key }"]`, form );
			if ( ! input ) { return; }
			input.value = url || '';
			const card = q( `[data-certloom-theme-media-card="${ key }"]`, form );
			const name = q( '[data-certloom-theme-media-name]', card );
			if ( name ) { name.textContent = label || ( url ? url.split( '/' ).pop() : '' ) || 'No image'; }
			input.dispatchEvent( new Event( 'input', { bubbles:true } ) );
		}
		qa( '[data-certloom-choose-theme-media]', form ).forEach( ( button ) => button.addEventListener( 'click', () => {
			if ( ! window.wp?.media ) { return; }
			const frame = wp.media( { title:L.chooseBackground || 'Choose background image', button:{ text:L.useImage || 'Use this image' }, multiple:false, library:{ type:'image' } } );
			frame.on( 'select', () => {
				const attachment = frame.state().get( 'selection' ).first().toJSON();
				setThemeMedia( button.dataset.certloomChooseThemeMedia, attachment.url || '', attachment.filename || attachment.title || '' );
			} );
			frame.open();
		} ) );
		qa( '[data-certloom-remove-theme-media]', form ).forEach( ( button ) => button.addEventListener( 'click', () => setThemeMedia( button.dataset.certloomRemoveThemeMedia, '', 'No image' ) ) );

		qa( '[data-certloom-sidebar-tab]' ).forEach( ( button ) => {
			button.addEventListener( 'click', () => {
				const target = button.dataset.certloomSidebarTab;
				qa( '[data-certloom-sidebar-tab]' ).forEach( ( item ) => {
					const active = item === button;
					item.classList.toggle( 'is-active', active );
					item.setAttribute( 'aria-selected', active ? 'true' : 'false' );
				} );
				qa( '[data-certloom-sidebar-panel]' ).forEach( ( panel ) => {
					const active = panel.dataset.certloomSidebarPanel === target;
					panel.classList.toggle( 'is-active', active );
					panel.hidden = ! active;
				} );
			} );
		} );

		qa( '[data-certloom-data-mode]' ).forEach( ( button ) => {
			button.addEventListener( 'click', () => {
				sampleMode = button.dataset.certloomDataMode !== 'tokens';
				qa( '[data-certloom-data-mode]' ).forEach( ( item ) => {
					const active = item === button;
					item.classList.toggle( 'is-active', active );
					item.setAttribute( 'aria-pressed', active ? 'true' : 'false' );
				} );
				render();
			} );
		} );

		const tokenList = q( '[data-certloom-token-list]' );
		const tokenSearch = q( '[data-certloom-token-search]' );
		function renderTokenBrowser( query = '' ) {
			if ( ! tokenList ) { return; }
			const groups = L.tokenGroups && typeof L.tokenGroups === 'object' ? L.tokenGroups : { fields:{ label:'Fields', tokens:L.tokens || {} } };
			const needle = String( query || '' ).trim().toLowerCase();
			tokenList.innerHTML = '';
			Object.entries( groups ).forEach( ( [ groupKey, group ] ) => {
				const matches = Object.entries( group.tokens || {} ).filter( ( [ key, label ] ) => ! needle || key.toLowerCase().includes( needle ) || String( label ).toLowerCase().includes( needle ) );
				if ( ! matches.length ) { return; }
				const section = document.createElement( groupKey === 'legacy' ? 'details' : 'section' );
				section.className = 'certloom-token-group' + ( groupKey === 'legacy' ? ' is-legacy' : '' );
				if ( groupKey === 'legacy' ) { const summary=document.createElement('summary');summary.textContent=group.label||'Compatibility aliases';section.appendChild(summary); }
				else { const heading=document.createElement('strong');heading.className='certloom-token-group__title';heading.textContent=group.label||groupKey;section.appendChild(heading); }
				const list = document.createElement( 'div' ); list.className = 'certloom-token-list';
				matches.forEach( ( [ key, label ] ) => { const button=document.createElement('button');button.type='button';button.innerHTML=`<span>${ esc( label ) }</span><code>{{${ esc( key ) }}}</code>`;button.addEventListener('click',()=>insertToken('{{'+key+'}}'));list.appendChild(button); } );
				section.appendChild( list ); tokenList.appendChild( section );
			} );
		}
		renderTokenBrowser();
		tokenSearch?.addEventListener( 'input', () => renderTokenBrowser( tokenSearch.value ) );

		const attachmentList = q( '[data-certloom-attachment-list]' );
		const attachmentCount = q( '[data-certloom-attachment-count]' );
		function renderAttachments() {
			if ( attachmentCount ) { attachmentCount.textContent = `${ attachments.length }/5`; }
			if ( ! attachmentList ) { return; }
			attachmentList.innerHTML = '';
			if ( ! attachments.length ) { attachmentList.innerHTML = '<p class="description">No static attachments.</p>'; return; }
			attachments.forEach( ( item, index ) => { const row=document.createElement('div');row.className='certloom-email-attachment-item';row.innerHTML=`<span><strong>${ esc( item.filename || item.title || ('Media #' + item.id) ) }</strong><small>${ esc( item.filesizeHumanReadable || item.mime || '' ) }</small></span><button type="button" aria-label="Remove">×</button>`;row.querySelector('button').addEventListener('click',()=>{attachments.splice(index,1);markDirty();renderAttachments();});attachmentList.appendChild(row); } );
		}
		renderAttachments();
		q( '[data-certloom-add-attachments]' )?.addEventListener( 'click', () => {
			if ( ! window.wp?.media ) { return; }
			const frame=wp.media({title:L.chooseAttachments||'Choose email attachments',button:{text:L.useAttachments||'Attach selected files'},multiple:true});
			frame.on('select',()=>{ const chosen=frame.state().get('selection').toJSON();chosen.forEach((attachment)=>{if(attachments.length>=5){return;}if(!attachments.some((item)=>Number(item.id)===Number(attachment.id))){attachments.push({id:Number(attachment.id),filename:attachment.filename||attachment.title||'',title:attachment.title||'',mime:attachment.mime||'',filesizeHumanReadable:attachment.filesizeHumanReadable||''});}});markDirty();renderAttachments(); });
			frame.open();
		} );

		qa( '[data-certloom-token-input]', form ).forEach( ( input ) => {
			input.addEventListener( 'focus', () => {
				lastTokenField = input;
			} );
		} );

		function insertToken( token ) {
			const input = lastTokenField || q( '[data-field]', inspector ) || q( '[name=subject]', form );
			if ( ! input ) {
				return;
			}
			if ( input.isContentEditable ) {
				input.focus();
				document.execCommand( 'insertText', false, token );
				input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
				return;
			}
			const start = input.selectionStart ?? input.value.length;
			const end = input.selectionEnd ?? start;
			input.value = input.value.slice( 0, start ) + token + input.value.slice( end );
			input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
			input.focus();
			input.setSelectionRange?.( start + token.length, start + token.length );
		}

		function finishRename( commit = true ) {
			if ( ! nameEditor || ! nameEdit || ! nameInput ) { return; }
			if ( commit ) { const value=String(nameEditor.value||'').trim().slice(0,120); if(value){nameInput.value=value;if(title){title.textContent=value;}markDirty();} else { nameEditor.value=nameInput.value; } }
			nameEditor.hidden=true;nameEdit.hidden=false;
		}
		nameEdit?.addEventListener( 'click', () => { if(!nameEditor){return;}nameEditor.value=nameInput?.value||title?.textContent||'';nameEdit.hidden=true;nameEditor.hidden=false;nameEditor.focus();nameEditor.select(); } );
		nameEditor?.addEventListener( 'keydown', ( event ) => { if(event.key==='Enter'){event.preventDefault();finishRename(true);}if(event.key==='Escape'){event.preventDefault();finishRename(false);} } );
		nameEditor?.addEventListener( 'blur', () => finishRename( true ) );
		qa( '[name=subject],[name=preheader],[name=purpose],[name=text_body]', form ).forEach( ( input ) => input.addEventListener( 'input', markDirty ) );
		qa( '[data-certloom-device]' ).forEach( ( button ) => {
			button.addEventListener( 'click', () => {
				qa( '[data-certloom-device]' ).forEach( ( item ) => {
					const active = item === button;
					item.classList.toggle( 'is-active', active );
					item.setAttribute( 'aria-pressed', active ? 'true' : 'false' );
				} );
				canvasFrame?.classList.toggle( 'is-mobile', button.dataset.certloomDevice === 'mobile' );
				scheduleCanvasFit();
			} );
		} );

		function usedCustomFontFaceCss() {
			return usedCustomFontDefinitions().map( ( [ key, definition ] ) => {
				const family = String( definition.preview_family || '' ).replace( /["\\]/g, '' );
				return ( Array.isArray( definition.faces ) ? definition.faces : [] ).map( ( face ) => {
					const url = String( face.url || '' ).replace( /["\\]/g, '\\$&' );
					const format = face.format === 'otf' ? 'opentype' : 'truetype';
					return `@font-face{font-family:"${ family }";src:url("${ url }") format("${ format }");font-weight:${ Number( face.weight ) || 400 };font-style:${ face.style === 'italic' ? 'italic' : 'normal' };font-display:swap}`;
				} ).join( '' );
			} ).join( '' );
		}

		function fullHtml() {
			const styles = getComputedStyle( canvas );
			const values = {}; qa( '[data-certloom-theme]', form ).forEach( ( input ) => { values[ input.name.replace( 'theme_', '' ) ] = input.value; } );
			const body = blocks.map( blockHtml ).join( '' );
			const pageBackground = values.background || '#f4f7f6'; const contentBackground = values.content_background || '#ffffff'; const heading = values.heading_color || '#173f34'; const buttonBg = values.button_background || '#1369fc'; const buttonText = values.button_text || '#ffffff'; const link = values.link_color || '#0f6e56';
			const pageBgImage = values.background_image ? `background-image:url('${ esc( values.background_image ) }');background-size:${ esc( values.background_size || 'cover' ) };background-position:${ esc( values.background_position || 'center' ) };background-repeat:${ esc( values.background_repeat || 'no-repeat' ) };` : '';
			const contentBgImage = values.content_background_image ? `background-image:url('${ esc( values.content_background_image ) }');background-size:${ esc( values.content_background_size || 'cover' ) };background-position:${ esc( values.content_background_position || 'center' ) };background-repeat:${ esc( values.content_background_repeat || 'no-repeat' ) };` : '';
			const css = `${ usedCustomFontFaceCss() }*{box-sizing:border-box}body{margin:0;background-color:${ esc( pageBackground ) };${ pageBgImage }padding:28px 14px}h1,h2,h3{margin:0 0 18px;color:var(--certloom-email-heading-color);line-height:1.25}h1{font-size:30px}h2{font-size:24px}h3{font-size:19px}p{margin:0 0 12px;line-height:1.65}a{color:var(--certloom-email-link-color)}hr{border:0;border-top:1px solid #dce6e1;margin:24px 0}.button-primary{background:var(--certloom-email-button-bg);color:var(--certloom-email-button-text)}@media(max-width:600px){.certloom-email-container-preview.is-row.is-stack-mobile{flex-direction:column!important}.certloom-email-container-preview.is-row.is-stack-mobile>div{width:100%!important;flex-basis:auto!important}}`;
			return `<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta http-equiv="Content-Security-Policy" content="default-src 'none'; img-src data: https: http:; font-src data: https: http:; style-src 'unsafe-inline';"><style>${ css }</style></head><body><div style="--certloom-email-heading-color:${ esc( heading ) };--certloom-email-button-bg:${ esc( buttonBg ) };--certloom-email-button-text:${ esc( buttonText ) };--certloom-email-link-color:${ esc( link ) };box-sizing:border-box;max-width:${ esc( canvas.style.maxWidth || '640px' ) };margin:auto;padding:36px;background-color:${ esc( contentBackground ) };${ contentBgImage }color:${ esc( styles.color ) };font-family:${ esc( styles.fontFamily ) };border-radius:${ esc( canvas.style.borderRadius || '12px' ) }">${ body }</div></body></html>`;
		}

		const previewDialog = q( '[data-certloom-designer-preview-dialog]' );
		const previewFrame = q( '[data-certloom-preview-frame]' );
		q( '[data-certloom-full-preview]' )?.addEventListener( 'click', () => {
			if ( previewFrame ) {
				previewFrame.srcdoc = fullHtml();
			}
			previewDialog?.showModal();
		} );
		q( '[data-certloom-preview-close]' )?.addEventListener( 'click', () => previewDialog?.close() );
		qa( '[data-certloom-preview-device]' ).forEach( ( button ) => button.addEventListener( 'click', () => { qa( '[data-certloom-preview-device]' ).forEach( ( item ) => { const active = item === button; item.classList.toggle( 'is-active', active ); item.setAttribute( 'aria-pressed', active ? 'true' : 'false' ); } ); previewFrame?.classList.toggle( 'is-mobile', button.dataset.certloomPreviewDevice === 'mobile' ); } ) );
		previewDialog?.addEventListener( 'click', ( event ) => {
			if ( event.target === previewDialog ) {
				previewDialog.close();
			}
		} );

		form.addEventListener( 'submit', ( event ) => {
			sync();
			if ( ! blocks.length ) {
				event.preventDefault();
				showFeedback( L.contentBlocksRequired || 'Add at least one content block.' );
				return;
			}
			clearFeedback();
			dirty = false;
		} );

		window.addEventListener( 'beforeunload', ( event ) => {
			if ( dirty ) {
				event.preventDefault();
				event.returnValue = '';
			}
		} );

		render();
	}

	document.addEventListener( 'DOMContentLoaded', () => {
		initDialogs();
		initDesigner();
	} );
}());
