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

		let selected = blocks.length ? 0 : -1;
		let dirty = false;
		let lastTokenField = null;
		let dragIndex = -1;
		let sampleMode = true;

		function sync() {
			if ( hidden ) {
				hidden.value = JSON.stringify( blocks );
			}
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
			if ( ! sampleMode ) {
				return String( value || '' );
			}
			const replacements = {
				'{{recipient_name}}': L.sampleRecipient || 'Sara Khan',
				'{{program_title}}': L.sampleProgram || 'Professional Leadership Program',
				'{{issuer_name}}': L.sampleIssuer || 'Example Institute',
				'{{credential_number}}': 'CL-2026-1042',
				'{{issue_date}}': '4 Sep 2026',
				'{{expiry_date}}': '4 Sep 2028',
				'{{grade}}': L.sampleGrade || 'Distinction',
				'{{verification_url}}': '#',
				'{{verification_link}}': L.verifyThisCredential || 'Verify this credential',
			};
			let output = String( value || '' );
			Object.keys( replacements ).forEach( ( token ) => {
				output = output.split( token ).join( replacements[ token ] );
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

		function blockHtml( block ) {
			const style = blockStyle( block );
			switch ( block.type ) {
				case 'heading':
					return `<h${ block.level || 2 } style="${ esc( typographyStyle( block, 'heading' ) ) }">${ esc( sample( block.text ) ) }</h${ block.level || 2 }>`;
				case 'text':
					return `<div style="${ esc( typographyStyle( block, 'text' ) ) }">${ sample( block.html || '' ) }</div>`;
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
					return block.url ? `<div style="text-align:${ align }"><img src="${ esc( block.url ) }" alt="${ esc( block.alt || '' ) }" style="display:inline-block;width:${ width }%;max-width:100%;height:auto;border-radius:${ radius }px"></div>` : '<div class="certloom-email-empty">Image</div>';
				}
				case 'divider': {
					const color = safeColor( style.color, '#dce6e1' ); const width = Math.max( 10, Math.min( 100, Number( style.width ) || 100 ) ); const thickness = Math.max( 1, Math.min( 8, Number( style.thickness ) || 1 ) ); const margin = Math.max( 4, Math.min( 60, Number( style.margin_y ) || 24 ) );
					return `<div style="border-top:${ thickness }px solid ${ color };width:${ width }%;margin:${ margin }px auto"></div>`;
				}
				case 'spacer':
					return `<div style="height:${ Number( block.height ) || 24 }px"></div>`;
				case 'columns':
					return `<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px"><div>${ sample( block.left_html || '' ) }</div><div>${ sample( block.right_html || '' ) }</div></div>`;
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
				columns: L.addColumns || 'Two columns',
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
				return L.leftColumn && L.rightColumn ? `${ L.leftColumn } + ${ L.rightColumn }` : 'Two columns';
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
			return {
				type: 'columns',
				left_html: '<p>' + esc( L.leftColumn || 'Left column' ) + '</p>',
				right_html: '<p>' + esc( L.rightColumn || 'Right column' ) + '</p>',
			};
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
				contentFields += `<div class="certloom-inspector-stack">${ field( L.urlLabel || 'URL', 'url' ) }${ field( L.altText || 'Alternative text', 'alt' ) }<button type="button" class="button certloom-inspector-media-button" data-choose-image>${ esc( L.chooseImage || 'Choose image' ) }</button></div>`;
				layoutFields += segmentedStyleField( L.alignment || 'Alignment', 'align', alignOptions );
				spacingFields += `<div class="certloom-inspector-grid certloom-inspector-grid--2">${ numberStyleField( L.imageWidth || 'Image width', 'width', 10, 100, 1, '%' ) }${ numberStyleField( L.cornerRadius || 'Corner radius', 'border_radius', 0, 32 ) }</div>`;
			}
			if ( block.type === 'spacer' ) { layoutFields += field( L.spacingPixels || 'Spacing', 'height', 'number' ); }
			if ( block.type === 'columns' ) { contentFields += `<div class="certloom-inspector-stack">${ field( L.leftColumn || 'Left column', 'left_html', 'textarea' ) }${ field( L.rightColumn || 'Right column', 'right_html', 'textarea' ) }</div>`; }
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

		function chooseImage() {
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
			qa( '[data-certloom-theme-color]', form ).forEach( ( picker ) => {
			picker.addEventListener( 'input', () => { const hex = q( `[data-certloom-theme-hex="${ picker.dataset.certloomThemeColor }"]`, form ); if ( hex ) { hex.value = picker.value.toUpperCase(); } } );
		} );
		qa( '[data-certloom-theme-hex]', form ).forEach( ( hex ) => {
			hex.addEventListener( 'input', () => { const value = String( hex.value || '' ).trim(); if ( /^#[0-9A-Fa-f]{6}$/.test( value ) ) { const picker = q( `[data-certloom-theme-color="${ hex.dataset.certloomThemeHex }"]`, form ); if ( picker ) { picker.value = value; picker.dispatchEvent( new Event( 'input', { bubbles: true } ) ); } } } );
		} );

		qa( '[data-certloom-theme]', form ).forEach( ( input ) => {
				values[ input.name.replace( 'theme_', '' ) ] = input.value;
			} );
			canvas.style.background = values.content_background || '#fff';
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

		qa( '[data-certloom-theme]', form ).forEach( ( input ) => {
			input.addEventListener( 'input', () => {
				markDirty();
				applyTheme();
			} );
		} );

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
		if ( tokenList && L.tokens ) {
			tokenList.classList.add( 'certloom-token-list' );
			Object.entries( L.tokens ).forEach( ( [ key, label ] ) => {
				const button = document.createElement( 'button' );
				button.type = 'button';
				button.textContent = label;
				button.addEventListener( 'click', () => insertToken( '{{' + key + '}}' ) );
				tokenList.appendChild( button );
			} );
		}

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

		nameInput?.addEventListener( 'input', () => {
			if ( title ) {
				title.textContent = nameInput.value || 'Untitled email';
			}
			markDirty();
		} );
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
			const css = `*{box-sizing:border-box}body{margin:0;background:${ esc( pageBackground ) };padding:28px 14px}h1,h2,h3{margin:0 0 18px;color:var(--certloom-email-heading-color);line-height:1.25}h1{font-size:30px}h2{font-size:24px}h3{font-size:19px}p{margin:0 0 12px;line-height:1.65}a{color:var(--certloom-email-link-color)}hr{border:0;border-top:1px solid #dce6e1;margin:24px 0}.button-primary{background:var(--certloom-email-button-bg);color:var(--certloom-email-button-text)}`;
			return `<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta http-equiv="Content-Security-Policy" content="default-src 'none'; img-src data: https: http:; style-src 'unsafe-inline';"><style>${ css }</style></head><body><div style="--certloom-email-heading-color:${ esc( heading ) };--certloom-email-button-bg:${ esc( buttonBg ) };--certloom-email-button-text:${ esc( buttonText ) };--certloom-email-link-color:${ esc( link ) };box-sizing:border-box;max-width:${ esc( canvas.style.maxWidth || '640px' ) };margin:auto;padding:36px;background:${ esc( contentBackground ) };color:${ esc( styles.color ) };font-family:${ esc( styles.fontFamily ) };border-radius:${ esc( canvas.style.borderRadius || '12px' ) }">${ body }</div></body></html>`;
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
