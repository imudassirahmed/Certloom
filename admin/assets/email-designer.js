(function () {
	'use strict';

	const L = window.certloomEmailDesignerL10n || {};
	const q = ( selector, context = document ) => context.querySelector( selector );
	const qa = ( selector, context = document ) => Array.from( context.querySelectorAll( selector ) );

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

		let blocks = [];
		try {
			blocks = JSON.parse( hidden?.value || '[]' );
		} catch ( error ) {
			blocks = [];
		}
		if ( ! Array.isArray( blocks ) ) {
			blocks = [];
		}
		let attachments = [];
		try { attachments = JSON.parse( attachmentsHidden?.value || '[]' ); } catch ( error ) { attachments = []; }
		if ( ! Array.isArray( attachments ) ) { attachments = []; }
		attachments = attachments.filter( ( item ) => item && Number( item.id || item ) > 0 ).slice( 0, 5 ).map( ( item ) => typeof item === 'object' ? item : { id:Number( item ) } );

		let selected = blocks.length ? 0 : -1;
		let dirty = false;
		let lastTokenField = null;
		let dragIndex = -1;
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

		function safeColor( value, fallback ) {
			const color = String( value || '' ).trim();
			return /^#[0-9A-Fa-f]{6}$/.test( color ) ? color : fallback;
		}

		function safeFont( value ) {
			const allowed = [
				'Arial, Helvetica, sans-serif',
				'Georgia, Times New Roman, serif',
				'Verdana, Geneva, sans-serif',
				'Trebuchet MS, Arial, sans-serif',
			];
			return allowed.includes( value ) ? value : '';
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

		function ensureColumnsBlock( block ) {
			if ( ! Array.isArray( block.columns ) ) {
				block.columns = [
					{ kind: 'text', html: block.left_html || '<p>' + esc( L.leftColumn || 'Left column' ) + '</p>' },
					{ kind: 'text', html: block.right_html || '<p>' + esc( L.rightColumn || 'Right column' ) + '</p>' },
				];
				delete block.left_html; delete block.right_html;
			}
			block.count = [ 2, 3 ].includes( Number( block.count ) ) ? Number( block.count ) : 2;
			block.gap = Math.max( 0, Math.min( 40, Number( block.gap ) || 20 ) );
			while ( block.columns.length < block.count ) { block.columns.push( { kind: 'text', html: '<p></p>' } ); }
			block.columns = block.columns.slice( 0, block.count );
			if ( block.count === 3 && ! [ '33-33-33', '25-50-25' ].includes( block.ratio ) ) { block.ratio = '33-33-33'; }
			if ( block.count === 2 && ! [ '50-50', '33-67', '67-33', '40-60', '60-40' ].includes( block.ratio ) ) { block.ratio = '50-50'; }
			return block;
		}

		function columnHtml( column ) {
			if ( ! column ) { return ''; }
			if ( column.kind === 'image' ) {
				if ( ! column.url ) { return '<div class="certloom-email-empty">Image</div>'; }
				const width = Math.max( 10, Math.min( 100, Number( column.width ) || 100 ) );
				const align = [ 'left', 'center', 'right' ].includes( column.align ) ? column.align : 'center';
				let image = `<img src="${ esc( column.url ) }" alt="${ esc( column.alt || '' ) }" style="display:inline-block;width:${ width }%;max-width:100%;height:auto">`;
				if ( column.link ) { image = `<a href="${ esc( sample( column.link ) ) }">${ image }</a>`; }
				return `<div style="text-align:${ align }">${ image }</div>`;
			}
			return sampleHtml( column.html || '' );
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
					buttonStyles.push( `background:${ safeColor( style.background_color, 'var(--certloom-email-button-bg,#0f6e56)' ) }` );
					buttonStyles.push( `color:${ safeColor( style.color, 'var(--certloom-email-button-text,#ffffff)' ) }` );
					buttonStyles.push( `display:inline-block` );
					buttonStyles.push( `border:0` );
					buttonStyles.push( `text-decoration:${ style.underline ? 'underline' : 'none' }` );
					buttonStyles.push( `border-radius:${ Math.max( 0, Number( style.border_radius ?? 12 ) || 0 ) }px` );
					buttonStyles.push( `padding:${ Math.max( 6, Number( style.padding_y ?? 13 ) || 13 ) }px ${ Math.max( 8, Number( style.padding_x ?? 20 ) || 20 ) }px` );
					return `<div style="text-align:${ esc( block.align || 'left' ) }"><span class="button button-primary" style="${ esc( buttonStyles.join( ';' ) ) }">${ esc( sample( block.text ) ) }</span></div>`;
				}
				case 'image': {
					const width = Math.max( 10, Math.min( 100, Number( style.width ) || 100 ) ); const radius = Math.max( 0, Number( style.border_radius ) || 0 ); const align = [ 'left', 'center', 'right' ].includes( style.align ) ? style.align : 'left';
					if ( ! block.url ) { return '<div class="certloom-email-empty">Image</div>'; }
					let image = `<img src="${ esc( block.url ) }" alt="${ esc( block.alt || '' ) }" style="display:inline-block;width:${ width }%;max-width:100%;height:auto;border-radius:${ radius }px">`;
					if ( block.link ) { image = `<a href="${ esc( sample( block.link ) ) }">${ image }</a>`; }
					return `<div style="text-align:${ align }">${ image }</div>`;
				}
				case 'divider': {
					const color = safeColor( style.color, '#dce6e1' ); const width = Math.max( 10, Math.min( 100, Number( style.width ) || 100 ) ); const thickness = Math.max( 1, Math.min( 8, Number( style.thickness ) || 1 ) ); const margin = Math.max( 4, Math.min( 60, Number( style.margin_y ) || 24 ) );
					return `<div style="border-top:${ thickness }px solid ${ color };width:${ width }%;margin:${ margin }px auto"></div>`;
				}
				case 'spacer':
					return `<div style="height:${ Number( block.height ) || 24 }px"></div>`;
				case 'columns': {
					ensureColumnsBlock( block );
					const ratios = { '50-50':'1fr 1fr', '33-67':'1fr 2fr', '67-33':'2fr 1fr', '40-60':'2fr 3fr', '60-40':'3fr 2fr', '33-33-33':'1fr 1fr 1fr', '25-50-25':'1fr 2fr 1fr' };
					return `<div class="certloom-email-columns" style="display:grid;grid-template-columns:${ ratios[ block.ratio ] || ( block.count === 3 ? '1fr 1fr 1fr' : '1fr 1fr' ) };gap:${ Math.max( 0, Number( block.gap ) || 0 ) }px">${ block.columns.map( ( column ) => `<div>${ columnHtml( column ) }</div>` ).join( '' ) }</div>`;
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
				columns: L.addColumns || 'Columns',
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
			if ( block.type === 'columns' ) {
				return `${ Number( block.count ) === 3 ? 3 : 2 } ${ ( L.addColumns || 'Columns' ).toLowerCase() }`;
			}
			if ( block.type === 'html' ) {
				const holder = document.createElement( 'div' );
				holder.innerHTML = sampleHtml( block.html || '' );
				return ( holder.textContent || '' ).trim().slice( 0, 80 ) || ( L.addHtml || 'Custom HTML' );
			}
			return blockLabel( block );
		}

		function renderStructure() {
			if ( structureCount ) {
				structureCount.textContent = String( blocks.length );
			}
			if ( ! structureList ) {
				return;
			}
			structureList.innerHTML = '';
			if ( ! blocks.length ) {
				structureList.innerHTML = `<div class="certloom-inspector-empty"><p>${ esc( L.emptyContent || 'Add a content block to start the message.' ) }</p></div>`;
				return;
			}
			blocks.forEach( ( block, index ) => {
				const button = document.createElement( 'button' );
				button.type = 'button';
				button.className = 'certloom-email-structure-item' + ( index === selected ? ' is-selected' : '' );
				button.dataset.structureIndex = String( index );
				button.innerHTML = `<span class="certloom-email-structure-item__index">${ index + 1 }</span><span class="certloom-email-structure-item__copy"><strong>${ esc( blockLabel( block ) ) }</strong><small>${ esc( blockSummary( block ) ) }</small></span>`;
				button.addEventListener( 'click', () => {
					selected = index;
					render();
					q( `.certloom-canvas-block[data-index="${ index }"]`, canvas )?.focus( { preventScroll: true } );
				} );
				structureList.appendChild( button );
			} );
		}

		function render() {
			canvas.innerHTML = '';
			if ( ! blocks.length ) {
				canvas.innerHTML = `<div class="certloom-email-empty"><p>${ esc( L.emptyContent || 'Add a content block to start the message.' ) }</p></div>`;
			}

			blocks.forEach( ( block, index ) => {
				const element = document.createElement( 'div' );
				element.className = 'certloom-canvas-block' + ( index === selected ? ' is-selected' : '' );
				element.setAttribute( 'role', 'listitem' );
				element.tabIndex = 0;
				element.dataset.index = index;
				element.innerHTML = `
					<div class="certloom-canvas-block__controls">
						<span class="certloom-canvas-drag-handle" draggable="true" title="${ esc( L.dragBlock || 'Drag to reorder' ) }" aria-hidden="true">⋮⋮</span>
						<button type="button" data-up aria-label="${ esc( L.moveUp || 'Move up' ) }">↑</button>
						<button type="button" data-down aria-label="${ esc( L.moveDown || 'Move down' ) }">↓</button>
						<button type="button" data-copy aria-label="${ esc( L.duplicate || 'Duplicate' ) }">⧉</button>
						<button type="button" data-remove aria-label="${ esc( L.remove || 'Remove' ) }">×</button>
					</div>
					<div>${ blockHtml( block ) }</div>`;

				element.addEventListener( 'click', ( event ) => {
					if ( event.target.closest( '[data-up]' ) ) {
						move( index, -1 );
					} else if ( event.target.closest( '[data-down]' ) ) {
						move( index, 1 );
					} else if ( event.target.closest( '[data-copy]' ) ) {
						copy( index );
					} else if ( event.target.closest( '[data-remove]' ) ) {
						remove( index );
					} else if ( ! event.target.closest( '.certloom-canvas-drag-handle' ) ) {
						selected = index;
						render();
						renderInspector();
					}
				} );

				element.addEventListener( 'keydown', ( event ) => {
					if ( event.key === 'Enter' || event.key === ' ' ) {
						event.preventDefault();
						selected = index;
						render();
						renderInspector();
					}
				} );

				const handle = q( '.certloom-canvas-drag-handle', element );
				handle?.addEventListener( 'dragstart', ( event ) => {
					dragIndex = index;
					element.classList.add( 'is-dragging' );
					if ( event.dataTransfer ) {
						event.dataTransfer.effectAllowed = 'move';
						event.dataTransfer.setData( 'text/plain', String( index ) );
					}
				} );
				handle?.addEventListener( 'dragend', () => {
					dragIndex = -1;
					qa( '.certloom-canvas-block', canvas ).forEach( ( node ) => node.classList.remove( 'is-dragging', 'is-drop-target' ) );
				} );

				element.addEventListener( 'dragover', ( event ) => {
					if ( dragIndex < 0 || dragIndex === index ) {
						return;
					}
					event.preventDefault();
					if ( event.dataTransfer ) {
						event.dataTransfer.dropEffect = 'move';
					}
					element.classList.add( 'is-drop-target' );
				} );
				element.addEventListener( 'dragleave', () => element.classList.remove( 'is-drop-target' ) );
				element.addEventListener( 'drop', ( event ) => {
					if ( dragIndex < 0 || dragIndex === index ) {
						return;
					}
					event.preventDefault();
					const moved = blocks.splice( dragIndex, 1 )[ 0 ];
					let destination = index;
					if ( dragIndex < index ) {
						destination--;
					}
					blocks.splice( destination, 0, moved );
					selected = destination;
					dragIndex = -1;
					clearFeedback();
					markDirty();
					render();
				} );

				canvas.appendChild( element );
			} );

			renderStructure();
			renderInspector();
			applyTheme();
			scheduleCanvasFit();
		}

		function defaultBlock( type ) {
			if ( type === 'heading' ) {
				return { type: 'heading', text: L.headingPlaceholder || 'Your credential is ready', level: 2 };
			}
			if ( type === 'text' ) {
				return { type: 'text', html: '<p>' + ( L.textPlaceholder || 'Write the message recipients should receive.' ) + '</p>' };
			}
			if ( type === 'button' ) {
				return { type: 'button', text: L.buttonText || 'View credential', url: L.buttonUrl || '{{verification_url}}', align: 'left' };
			}
			if ( type === 'image' ) {
				return { type: 'image', url: '', alt: '' };
			}
			if ( type === 'divider' ) {
				return { type: 'divider' };
			}
			if ( type === 'spacer' ) {
				return { type: 'spacer', height: 24 };
			}
			if ( type === 'html' ) { return { type: 'html', html: '<p>Custom HTML</p>' }; }
			return { type: 'columns', count: 2, ratio: '50-50', gap: 20, columns: [ { kind: 'text', html: '<p>' + esc( L.leftColumn || 'Left column' ) + '</p>' }, { kind: 'text', html: '<p>' + esc( L.rightColumn || 'Right column' ) + '</p>' } ] };
		}

		qa( '[data-certloom-add-block]' ).forEach( ( button ) => {
			button.addEventListener( 'click', () => {
				blocks.push( defaultBlock( button.dataset.certloomAddBlock ) );
				selected = blocks.length - 1;
				clearFeedback();
				markDirty();
				render();
			} );
		} );

		function move( index, delta ) {
			const destination = index + delta;
			if ( destination < 0 || destination >= blocks.length ) {
				return;
			}
			[ blocks[ index ], blocks[ destination ] ] = [ blocks[ destination ], blocks[ index ] ];
			selected = destination;
			markDirty();
			render();
		}

		function copy( index ) {
			blocks.splice( index + 1, 0, JSON.parse( JSON.stringify( blocks[ index ] ) ) );
			selected = index + 1;
			markDirty();
			render();
		}

		function remove( index ) {
			blocks.splice( index, 1 );
			selected = Math.min( index, blocks.length - 1 );
			markDirty();
			render();
		}

		function field( label, key, type = 'text', options = [] ) {
			const block = blocks[ selected ] || {};
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
			const style = blockStyle( blocks[ selected ] || {} );
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
			const style = blockStyle( blocks[ selected ] || {} );
			return `<label class="certloom-email-number-field"><span>${ esc( label ) }</span><span class="certloom-email-number-control"><input type="number" min="${ min }" max="${ max }" step="${ step }" data-style-field="${ key }" value="${ esc( style[ key ] ?? '' ) }"><span>${ esc( unit ) }</span></span></label>`;
		}

		function segmentedStyleField( label, key, options ) {
			const style = blockStyle( blocks[ selected ] || {} );
			const current = String( style[ key ] || '' );
			const buttons = options.map( ( option ) => `<button type="button" class="${ current === String( option[ 0 ] ) ? 'is-active' : '' }" data-style-choice="${ key }" data-style-value="${ esc( option[ 0 ] ) }" aria-pressed="${ current === String( option[ 0 ] ) ? 'true' : 'false' }" title="${ esc( option[ 1 ] ) }">${ option[ 2 ] || esc( option[ 1 ] ) }</button>` ).join( '' );
			return `<div class="certloom-email-segmented-field"><span>${ esc( label ) }</span><div class="certloom-email-segmented" role="group" aria-label="${ esc( label ) }">${ buttons }</div></div>`;
		}

		function segmentedBlockField( label, key, options ) {
			const block = blocks[ selected ] || {};
			const current = String( block[ key ] || '' );
			const buttons = options.map( ( option ) => `<button type="button" class="${ current === String( option[ 0 ] ) ? 'is-active' : '' }" data-block-choice="${ key }" data-block-value="${ esc( option[ 0 ] ) }" aria-pressed="${ current === String( option[ 0 ] ) ? 'true' : 'false' }" title="${ esc( option[ 1 ] ) }">${ option[ 2 ] || esc( option[ 1 ] ) }</button>` ).join( '' );
			return `<div class="certloom-email-segmented-field"><span>${ esc( label ) }</span><div class="certloom-email-segmented" role="group" aria-label="${ esc( label ) }">${ buttons }</div></div>`;
		}

		function toggleStyleButtons( options ) {
			const style = blockStyle( blocks[ selected ] || {} );
			return `<div class="certloom-email-inline-toggles" role="group">${ options.map( ( option ) => `<button type="button" class="${ style[ option[ 0 ] ] ? 'is-active' : '' }" data-style-toggle="${ option[ 0 ] }" aria-pressed="${ style[ option[ 0 ] ] ? 'true' : 'false' }" title="${ esc( option[ 1 ] ) }">${ option[ 2 ] }</button>` ).join( '' ) }</div>`;
		}

		function colorStyleField( label, key, fallback ) {
			const style = blockStyle( blocks[ selected ] || {} );
			const value = safeColor( style[ key ], fallback );
			return `<label class="certloom-email-color-field"><span>${ esc( label ) }</span><span class="certloom-email-color-control"><input type="color" value="${ esc( value ) }" data-style-color-picker="${ key }" aria-label="${ esc( label ) }"><input type="text" maxlength="7" pattern="#[0-9A-Fa-f]{6}" value="${ esc( style[ key ] || '' ) }" placeholder="${ esc( fallback ) }" data-style-field="${ key }"><button type="button" class="certloom-email-color-reset" data-style-reset="${ key }" title="${ esc( L.reset || 'Reset' ) }" aria-label="${ esc( ( L.reset || 'Reset' ) + ' ' + label ) }">&#8634;</button></span></label>`;
		}

		function richTextField() {
			const block = blocks[ selected ] || {};
			return `<div class="certloom-email-rich-field"><span class="certloom-email-rich-label">${ esc( L.contentLabel || 'Content' ) }</span><div class="certloom-email-rich-toolbar" role="toolbar" aria-label="${ esc( L.textFormatting || 'Text formatting' ) }"><button type="button" data-rich-command="bold" aria-label="${ esc( L.bold || 'Bold' ) }" title="${ esc( L.bold || 'Bold' ) }"><strong>B</strong></button><button type="button" data-rich-command="italic" aria-label="${ esc( L.italic || 'Italic' ) }" title="${ esc( L.italic || 'Italic' ) }"><em>I</em></button><button type="button" data-rich-command="underline" aria-label="${ esc( L.underline || 'Underline' ) }" title="${ esc( L.underline || 'Underline' ) }"><u>U</u></button><span class="certloom-email-rich-toolbar__divider"></span><button type="button" data-rich-command="insertUnorderedList" aria-label="${ esc( L.bulletedList || 'Bulleted list' ) }" title="${ esc( L.bulletedList || 'Bulleted list' ) }">&bull;&#8801;</button><button type="button" data-rich-command="insertOrderedList" aria-label="${ esc( L.numberedList || 'Numbered list' ) }" title="${ esc( L.numberedList || 'Numbered list' ) }">1&#8801;</button><span class="certloom-email-rich-toolbar__divider"></span><button type="button" data-rich-link="add" aria-label="${ esc( L.addLink || 'Add link' ) }" title="${ esc( L.addLink || 'Add link' ) }">&#8599;</button><button type="button" data-rich-link="remove" aria-label="${ esc( L.removeLink || 'Remove link' ) }" title="${ esc( L.removeLink || 'Remove link' ) }">Ã&#8599;</button><button type="button" data-rich-token-picker aria-label="${ esc( L.insertData || 'Insert data' ) }" title="${ esc( L.insertData || 'Insert data' ) }">{{}}</button></div><div class="certloom-email-rich-editor" contenteditable="true" role="textbox" aria-multiline="true" data-rich-text-editor data-certloom-token-input>${ block.html || '' }</div></div>`;
		}

		function inspectorSection( titleText, contents, open = true ) {
			return `<details class="certloom-inspector-section" ${ open ? 'open' : '' }><summary><span>${ esc( titleText ) }</span></summary><div class="certloom-inspector-section__body">${ contents }</div></details>`;
		}

		function columnsInspectorFields( block ) {
			ensureColumnsBlock( block );
			const ratioOptions = block.count === 3 ? [ [ '33-33-33', 'Equal thirds' ], [ '25-50-25', 'Narrow / wide / narrow' ] ] : [ [ '50-50', 'Equal halves' ], [ '33-67', '1 / 2' ], [ '67-33', '2 / 1' ], [ '40-60', '40 / 60' ], [ '60-40', '60 / 40' ] ];
			let html = `<div class="certloom-inspector-grid certloom-inspector-grid--3"><label><span>${ esc( L.columnCount || 'Columns' ) }</span><select data-columns-count><option value="2" ${ block.count === 2 ? 'selected' : '' }>2</option><option value="3" ${ block.count === 3 ? 'selected' : '' }>3</option></select></label><label><span>${ esc( L.columnLayout || 'Layout' ) }</span><select data-columns-ratio>${ ratioOptions.map( ( option ) => `<option value="${ option[0] }" ${ block.ratio === option[0] ? 'selected' : '' }>${ esc( option[1] ) }</option>` ).join( '' ) }</select></label><label><span>${ esc( L.columnGap || 'Gap' ) }</span><span class="certloom-email-number-control"><input type="number" min="0" max="40" step="1" data-columns-gap value="${ esc( block.gap ) }"><span>px</span></span></label></div>`;
			html += '<div class="certloom-email-column-editors">';
			block.columns.forEach( ( column, index ) => {
				const kind = [ 'text', 'image', 'html' ].includes( column.kind ) ? column.kind : 'text';
				html += `<section class="certloom-email-column-editor"><div class="certloom-email-column-editor__head"><strong>Column ${ index + 1 }</strong><select data-column-kind="${ index }"><option value="text" ${ kind === 'text' ? 'selected' : '' }>Text</option><option value="image" ${ kind === 'image' ? 'selected' : '' }>Image</option><option value="html" ${ kind === 'html' ? 'selected' : '' }>HTML</option></select></div>`;
				if ( kind === 'image' ) {
					html += `<label><span>${ esc( L.urlLabel || 'URL' ) }</span><input type="text" data-column-field="url" data-column-index="${ index }" value="${ esc( column.url || '' ) }"></label><label><span>${ esc( L.altText || 'Alternative text' ) }</span><input type="text" data-column-field="alt" data-column-index="${ index }" value="${ esc( column.alt || '' ) }"></label><label><span>Link</span><input type="text" data-column-field="link" data-column-index="${ index }" value="${ esc( column.link || '' ) }" data-certloom-token-input></label><div class="certloom-inspector-grid certloom-inspector-grid--2"><label><span>Width</span><span class="certloom-email-number-control"><input type="number" min="10" max="100" data-column-field="width" data-column-index="${ index }" value="${ esc( column.width || 100 ) }"><span>%</span></span></label><label><span>Align</span><select data-column-field="align" data-column-index="${ index }"><option value="left" ${ column.align === 'left' ? 'selected' : '' }>Left</option><option value="center" ${ !column.align || column.align === 'center' ? 'selected' : '' }>Center</option><option value="right" ${ column.align === 'right' ? 'selected' : '' }>Right</option></select></label></div><button type="button" class="button" data-column-image="${ index }">${ esc( L.chooseImage || 'Choose image' ) }</button>`;
				} else {
					html += `<label><span>${ kind === 'html' ? esc( L.htmlLabel || 'HTML' ) : esc( L.contentLabel || 'Content' ) }</span><textarea rows="6" data-column-field="html" data-column-index="${ index }" data-certloom-token-input class="${ kind === 'html' ? 'certloom-code-field' : '' }">${ esc( column.html || '' ) }</textarea></label>`;
				}
				html += '</section>';
			} );
			return html + '</div>';
		}

		function renderInspector() {
			if ( selected < 0 || ! blocks[ selected ] ) {
				if ( inspectorHeading ) { inspectorHeading.textContent = 'Block settings'; }
				inspector.innerHTML = `<div class="certloom-inspector-empty"><p>${ esc( L.selectBlock || 'Select a block on the canvas to edit it.' ) }</p></div>`;
				return;
			}

			const block = blocks[ selected ];
			if ( inspectorHeading ) { inspectorHeading.textContent = blockLabel( block ); }
			let contentFields = '', typographyFields = '', colorFields = '', layoutFields = '', spacingFields = '';
			const fontOptions = [ [ '', L.inheritGlobal || 'Use email default' ], [ 'Arial, Helvetica, sans-serif', 'Arial' ], [ 'Georgia, Times New Roman, serif', 'Georgia' ], [ 'Verdana, Geneva, sans-serif', 'Verdana' ], [ 'Trebuchet MS, Arial, sans-serif', 'Trebuchet MS' ] ];
			const weightOptions = [ [ '', L.inheritGlobal || 'Default' ], [ 400, 'Regular' ], [ 500, 'Medium' ], [ 600, 'Semi-bold' ], [ 700, 'Bold' ], [ 800, 'Extra bold' ], [ 900, 'Black' ] ];
			const alignOptions = [ [ 'left', 'Align left', 'L' ], [ 'center', 'Align center', 'C' ], [ 'right', 'Align right', 'R' ] ];
			const textToggles = [ [ 'italic', L.italic || 'Italic', '<em>I</em>' ], [ 'underline', L.underline || 'Underline', '<u>U</u>' ] ];

			if ( block.type === 'heading' ) {
				contentFields += `<div class="certloom-inspector-grid certloom-inspector-grid--content">${ field( L.textLabel || 'Text', 'text' ) }${ field( L.headingLevel || 'Heading level', 'level', 'select', [ [ 1, 'H1' ], [ 2, 'H2' ], [ 3, 'H3' ] ] ) }</div>`;
				typographyFields += `<div class="certloom-inspector-grid certloom-inspector-grid--2">${ styleField( L.fontFamily || 'Font family', 'font_family', 'select', fontOptions, '', 'is-wide' ) }${ numberStyleField( L.fontSize || 'Font size', 'font_size', 12, 72 ) }${ styleField( L.fontWeight || 'Font weight', 'font_weight', 'select', weightOptions ) }${ numberStyleField( L.lineHeight || 'Line height', 'line_height', 1, 2.5, .05, 'x' ) }</div>${ toggleStyleButtons( textToggles ) }${ segmentedStyleField( L.alignment || 'Alignment', 'align', alignOptions ) }`;
				colorFields += colorStyleField( L.textColor || 'Text color', 'color', '#173f34' );
			}
			if ( block.type === 'text' ) {
				contentFields += richTextField();
				typographyFields += `<div class="certloom-inspector-grid certloom-inspector-grid--2">${ styleField( L.fontFamily || 'Font family', 'font_family', 'select', fontOptions, '', 'is-wide' ) }${ numberStyleField( L.fontSize || 'Font size', 'font_size', 10, 32 ) }${ styleField( L.fontWeight || 'Font weight', 'font_weight', 'select', weightOptions ) }${ numberStyleField( L.lineHeight || 'Line height', 'line_height', 1, 2.5, .05, 'x' ) }</div>${ toggleStyleButtons( textToggles ) }${ segmentedStyleField( L.alignment || 'Alignment', 'align', alignOptions ) }`;
				colorFields += colorStyleField( L.textColor || 'Text color', 'color', '#24322e' );
			}
			if ( block.type === 'button' ) {
				contentFields += `<div class="certloom-inspector-grid certloom-inspector-grid--content">${ field( L.buttonTextLabel || 'Button text', 'text' ) }${ field( L.urlLabel || 'URL', 'url' ) }</div>`;
				typographyFields += `<div class="certloom-inspector-grid certloom-inspector-grid--2">${ styleField( L.fontFamily || 'Font family', 'font_family', 'select', fontOptions, '', 'is-wide' ) }${ numberStyleField( L.fontSize || 'Font size', 'font_size', 10, 28 ) }${ styleField( L.fontWeight || 'Font weight', 'font_weight', 'select', weightOptions ) }</div>${ toggleStyleButtons( textToggles ) }`;
				colorFields += `<div class="certloom-inspector-grid certloom-inspector-grid--colors">${ colorStyleField( L.textColor || 'Text color', 'color', '#ffffff' ) }${ colorStyleField( L.backgroundColor || 'Background color', 'background_color', '#0f6e56' ) }</div>`;
				layoutFields += segmentedBlockField( L.alignment || 'Alignment', 'align', alignOptions );
				spacingFields += `<div class="certloom-inspector-grid certloom-inspector-grid--3">${ numberStyleField( L.horizontalPadding || 'Horizontal', 'padding_x', 8, 40 ) }${ numberStyleField( L.verticalPadding || 'Vertical', 'padding_y', 6, 24 ) }${ numberStyleField( L.cornerRadius || 'Radius', 'border_radius', 0, 32 ) }</div>`;
			}
			if ( block.type === 'image' ) {
				contentFields += `<div class="certloom-inspector-stack">${ field( L.urlLabel || 'URL', 'url' ) }${ field( L.altText || 'Alternative text', 'alt' ) }${ field( 'Link (optional)', 'link' ) }<button type="button" class="button certloom-inspector-media-button" data-choose-image>${ esc( L.chooseImage || 'Choose image' ) }</button></div>`;
				layoutFields += segmentedStyleField( L.alignment || 'Alignment', 'align', alignOptions );
				spacingFields += `<div class="certloom-inspector-grid certloom-inspector-grid--2">${ numberStyleField( L.imageWidth || 'Image width', 'width', 10, 100, 1, '%' ) }${ numberStyleField( L.cornerRadius || 'Corner radius', 'border_radius', 0, 32 ) }</div>`;
			}
			if ( block.type === 'spacer' ) { layoutFields += field( L.spacingPixels || 'Spacing', 'height', 'number' ); }
			if ( block.type === 'columns' ) { contentFields += columnsInspectorFields( block ); }
			if ( block.type === 'html' ) { contentFields += `<label><span>${ esc( L.htmlLabel || 'HTML' ) }</span><textarea rows="14" class="certloom-code-field" data-field="html" data-certloom-token-input>${ esc( block.html || '' ) }</textarea><small>Safe email HTML is rendered on the canvas and in Preview even when the visual builder has no equivalent controls.</small></label>`; }
			if ( block.type === 'divider' ) {
				colorFields += colorStyleField( L.dividerColor || 'Divider color', 'color', '#dce6e1' );
				layoutFields += `<div class="certloom-inspector-grid certloom-inspector-grid--2">${ numberStyleField( L.dividerWidth || 'Divider width', 'width', 10, 100, 1, '%' ) }${ numberStyleField( L.dividerThickness || 'Thickness', 'thickness', 1, 8 ) }</div>`;
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

			qa( '[data-field]', inspector ).forEach( ( input ) => {
				input.addEventListener( 'focus', () => { lastTokenField = input; } );
				input.addEventListener( 'input', () => {
					let value = input.value;
					if ( input.type === 'number' ) { value = parseInt( value || '0', 10 ); }
					blocks[ selected ][ input.dataset.field ] = value;
					markDirty(); renderCanvasOnly(); renderStructure();
				} );
			} );

			qa( '[data-style-field]', inspector ).forEach( ( input ) => {
				input.addEventListener( 'input', () => {
					const state = blockStyle( blocks[ selected ] ); blocks[ selected ].style = state;
					let value = input.value; if ( input.type === 'number' ) { value = input.value === '' ? '' : Number( input.value ); }
					if ( value === '' ) { delete state[ input.dataset.styleField ]; } else { state[ input.dataset.styleField ] = value; }
					const picker = q( `[data-style-color-picker="${ input.dataset.styleField }"]`, inspector ); if ( picker && /^#[0-9A-Fa-f]{6}$/.test( String( value ) ) ) { picker.value = String( value ); }
					markDirty(); renderCanvasOnly();
				} );
			} );

			qa( '[data-style-choice]', inspector ).forEach( ( button ) => button.addEventListener( 'click', () => {
				const state = blockStyle( blocks[ selected ] ); blocks[ selected ].style = state;
				state[ button.dataset.styleChoice ] = button.dataset.styleValue;
				button.parentElement.querySelectorAll( 'button' ).forEach( ( item ) => { const active = item === button; item.classList.toggle( 'is-active', active ); item.setAttribute( 'aria-pressed', active ? 'true' : 'false' ); } );
				markDirty(); renderCanvasOnly();
			} ) );
			qa( '[data-block-choice]', inspector ).forEach( ( button ) => button.addEventListener( 'click', () => {
				blocks[ selected ][ button.dataset.blockChoice ] = button.dataset.blockValue;
				button.parentElement.querySelectorAll( 'button' ).forEach( ( item ) => { const active = item === button; item.classList.toggle( 'is-active', active ); item.setAttribute( 'aria-pressed', active ? 'true' : 'false' ); } );
				markDirty(); renderCanvasOnly();
			} ) );
			qa( '[data-style-toggle]', inspector ).forEach( ( button ) => button.addEventListener( 'click', () => {
				const state = blockStyle( blocks[ selected ] ); blocks[ selected ].style = state; const key = button.dataset.styleToggle;
				if ( state[ key ] ) { delete state[ key ]; } else { state[ key ] = true; }
				const active = !! state[ key ]; button.classList.toggle( 'is-active', active ); button.setAttribute( 'aria-pressed', active ? 'true' : 'false' ); markDirty(); renderCanvasOnly();
			} ) );

			qa( '[data-style-color-picker]', inspector ).forEach( ( picker ) => picker.addEventListener( 'input', () => {
				const key = picker.dataset.styleColorPicker; const fieldNode = q( `[data-style-field="${ key }"]`, inspector ); if ( fieldNode ) { fieldNode.value = picker.value.toUpperCase(); fieldNode.dispatchEvent( new Event( 'input', { bubbles: true } ) ); }
			} ) );
			qa( '[data-style-reset]', inspector ).forEach( ( button ) => button.addEventListener( 'click', () => {
				const state = blockStyle( blocks[ selected ] ); delete state[ button.dataset.styleReset ]; blocks[ selected ].style = state; markDirty(); renderCanvasOnly(); renderInspector();
			} ) );

			q( '[data-columns-count]', inspector )?.addEventListener( 'change', ( event ) => {
				const state = ensureColumnsBlock( blocks[ selected ] ); state.count = Number( event.target.value ) === 3 ? 3 : 2; if ( state.count === 3 ) { state.ratio = '33-33-33'; } else { state.ratio = '50-50'; } ensureColumnsBlock( state ); markDirty(); render();
			} );
			q( '[data-columns-ratio]', inspector )?.addEventListener( 'change', ( event ) => { blocks[ selected ].ratio = event.target.value; markDirty(); renderCanvasOnly(); renderStructure(); } );
			q( '[data-columns-gap]', inspector )?.addEventListener( 'input', ( event ) => { blocks[ selected ].gap = Math.max( 0, Math.min( 40, Number( event.target.value ) || 0 ) ); markDirty(); renderCanvasOnly(); } );
			qa( '[data-column-kind]', inspector ).forEach( ( input ) => input.addEventListener( 'change', () => { const index = Number( input.dataset.columnKind ); const state = ensureColumnsBlock( blocks[ selected ] ); const previous = state.columns[ index ] || {}; state.columns[ index ] = input.value === 'image' ? { kind:'image', url:'', alt:'', link:'', width:100, align:'center' } : { kind: input.value === 'html' ? 'html' : 'text', html: previous.html || '<p></p>' }; markDirty(); render(); } ) );
			qa( '[data-column-field]', inspector ).forEach( ( input ) => { input.addEventListener( 'focus', () => { lastTokenField = input; } ); input.addEventListener( 'input', () => { const index=Number(input.dataset.columnIndex);const state=ensureColumnsBlock(blocks[selected]);let value=input.value;if(input.type==='number'){value=Number(value)||0;}state.columns[index][input.dataset.columnField]=value;markDirty();renderCanvasOnly();renderStructure(); } ); } );
			qa( '[data-column-image]', inspector ).forEach( ( button ) => button.addEventListener( 'click', () => chooseImage( Number( button.dataset.columnImage ) ) ) );

			const richEditor = q( '[data-rich-text-editor]', inspector );
			if ( richEditor ) {
				lastTokenField = richEditor;
				const syncRich = () => { blocks[ selected ].html = richEditor.innerHTML; markDirty(); renderCanvasOnly(); renderStructure(); };
				richEditor.addEventListener( 'focus', () => { lastTokenField = richEditor; } ); richEditor.addEventListener( 'input', syncRich );
				qa( '[data-rich-command]', inspector ).forEach( ( button ) => { button.addEventListener( 'mousedown', ( event ) => event.preventDefault() ); button.addEventListener( 'click', () => { richEditor.focus(); document.execCommand( button.dataset.richCommand, false, null ); syncRich(); } ); } );
				qa( '[data-rich-link]', inspector ).forEach( ( button ) => { button.addEventListener( 'mousedown', ( event ) => event.preventDefault() ); button.addEventListener( 'click', () => { richEditor.focus(); if ( button.dataset.richLink === 'remove' ) { document.execCommand( 'unlink', false, null ); } else { const url = window.prompt( L.urlLabel || 'URL', 'https://' ); if ( url ) { document.execCommand( 'createLink', false, url ); } } syncRich(); } ); } );
				q( '[data-rich-token-picker]', inspector )?.addEventListener( 'click', () => { lastTokenField = richEditor; q( '[data-certloom-sidebar-tab="data"]' )?.click(); } );
			}

			q( '[data-inspector-up]', inspector )?.addEventListener( 'click', () => move( selected, -1 ) ); q( '[data-inspector-down]', inspector )?.addEventListener( 'click', () => move( selected, 1 ) ); q( '[data-inspector-copy]', inspector )?.addEventListener( 'click', () => copy( selected ) ); q( '[data-inspector-remove]', inspector )?.addEventListener( 'click', () => remove( selected ) ); q( '[data-choose-image]', inspector )?.addEventListener( 'click', chooseImage );
		}

		function renderCanvasOnly() {
			qa( '.certloom-canvas-block', canvas ).forEach( ( element, index ) => {
				const content = element.children[ 1 ];
				if ( content && blocks[ index ] ) {
					content.innerHTML = blockHtml( blocks[ index ] );
				}
			} );
			applyTheme();
			scheduleCanvasFit();
		}

		function chooseImage( columnIndex = null ) {
			if ( ! window.wp?.media ) {
				return;
			}
			const frame = wp.media( {
				title: L.chooseImage || 'Choose an email image',
				button: { text: L.useImage || 'Use this image' },
				multiple: false,
			} );
			frame.on( 'select', () => {
				const attachment = frame.state().get( 'selection' ).first().toJSON();
				if ( columnIndex !== null && blocks[ selected ]?.type === 'columns' ) { const state=ensureColumnsBlock(blocks[selected]);state.columns[columnIndex]={...(state.columns[columnIndex]||{}),kind:'image',url:attachment.url||'',alt:attachment.alt||'',width:Number(state.columns[columnIndex]?.width)||100,align:state.columns[columnIndex]?.align||'center'};markDirty();render();return; }
				if ( blocks[ selected ]?.type === 'image' ) {
					blocks[ selected ].url = attachment.url || '';
					blocks[ selected ].alt = attachment.alt || '';
					markDirty();
					render();
				}
			} );
			frame.open();
		}

		function applyTheme() {
			const values = {};
			qa( '[data-certloom-theme]', form ).forEach( ( input ) => {
				values[ input.name.replace( 'theme_', '' ) ] = input.value;
			} );
			const pageImage = values.background_image || '';
			const contentImage = values.content_background_image || '';
			if ( stageViewport ) {
				stageViewport.style.backgroundColor = values.background || '#f4f7f6';
				stageViewport.style.backgroundImage = pageImage ? `url("${ String( pageImage ).replace( /"/g, '%22' ) }")` : '';
				stageViewport.style.backgroundSize = values.background_size || 'cover';
				stageViewport.style.backgroundPosition = values.background_position || 'center';
				stageViewport.style.backgroundRepeat = values.background_repeat || 'no-repeat';
			}
			canvas.style.backgroundColor = values.content_background || '#fff';
			canvas.style.backgroundImage = contentImage ? `url("${ String( contentImage ).replace( /"/g, '%22' ) }")` : '';
			canvas.style.backgroundSize = values.content_background_size || 'cover';
			canvas.style.backgroundPosition = values.content_background_position || 'center';
			canvas.style.backgroundRepeat = values.content_background_repeat || 'no-repeat';
			canvas.style.color = values.text_color || '#24322e';
			canvas.style.fontFamily = values.font_family || 'Arial, Helvetica, sans-serif';
			canvas.style.borderRadius = ( parseInt( values.border_radius || '12', 10 ) ) + 'px';
			const emailWidth = parseInt( values.email_width || '640', 10 );
			canvas.style.maxWidth = emailWidth + 'px';
			canvas.style.setProperty( '--certloom-email-heading-color', values.heading_color || '#173f34' );
			canvas.style.setProperty( '--certloom-email-button-bg', values.button_background || '#0f6e56' );
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

		function fullHtml() {
			const styles = getComputedStyle( canvas );
			const values = {}; qa( '[data-certloom-theme]', form ).forEach( ( input ) => { values[ input.name.replace( 'theme_', '' ) ] = input.value; } );
			const body = blocks.map( blockHtml ).join( '' );
			const pageBackground = values.background || '#f4f7f6'; const contentBackground = values.content_background || '#ffffff'; const heading = values.heading_color || '#173f34'; const buttonBg = values.button_background || '#0f6e56'; const buttonText = values.button_text || '#ffffff'; const link = values.link_color || '#0f6e56';
			const pageBgImage = values.background_image ? `background-image:url('${ esc( values.background_image ) }');background-size:${ esc( values.background_size || 'cover' ) };background-position:${ esc( values.background_position || 'center' ) };background-repeat:${ esc( values.background_repeat || 'no-repeat' ) };` : '';
			const contentBgImage = values.content_background_image ? `background-image:url('${ esc( values.content_background_image ) }');background-size:${ esc( values.content_background_size || 'cover' ) };background-position:${ esc( values.content_background_position || 'center' ) };background-repeat:${ esc( values.content_background_repeat || 'no-repeat' ) };` : '';
			const css = `*{box-sizing:border-box}body{margin:0;background-color:${ esc( pageBackground ) };${ pageBgImage }padding:28px 14px}h1,h2,h3{margin:0 0 18px;color:var(--certloom-email-heading-color);line-height:1.25}h1{font-size:30px}h2{font-size:24px}h3{font-size:19px}p{margin:0 0 12px;line-height:1.65}a{color:var(--certloom-email-link-color)}hr{border:0;border-top:1px solid #dce6e1;margin:24px 0}.button-primary{background:var(--certloom-email-button-bg);color:var(--certloom-email-button-text)}`;
			return `<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta http-equiv="Content-Security-Policy" content="default-src 'none'; img-src data: https: http:; style-src 'unsafe-inline';"><style>${ css }</style></head><body><div style="--certloom-email-heading-color:${ esc( heading ) };--certloom-email-button-bg:${ esc( buttonBg ) };--certloom-email-button-text:${ esc( buttonText ) };--certloom-email-link-color:${ esc( link ) };box-sizing:border-box;max-width:${ esc( canvas.style.maxWidth || '640px' ) };margin:auto;padding:36px;background-color:${ esc( contentBackground ) };${ contentBgImage }color:${ esc( styles.color ) };font-family:${ esc( styles.fontFamily ) };border-radius:${ esc( canvas.style.borderRadius || '12px' ) }">${ body }</div></body></html>`;
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
