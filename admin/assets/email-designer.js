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

		function blockHtml( block ) {
			switch ( block.type ) {
				case 'heading':
					return `<h${ block.level || 2 }>${ esc( sample( block.text ) ) }</h${ block.level || 2 }>`;
				case 'text':
					return `<div>${ sample( block.html || '' ) }</div>`;
				case 'button':
					return `<div style="text-align:${ esc( block.align || 'left' ) }"><span class="button button-primary">${ esc( sample( block.text ) ) }</span></div>`;
				case 'image':
					return block.url
						? `<img src="${ esc( block.url ) }" alt="${ esc( block.alt || '' ) }" style="max-width:100%;height:auto">`
						: '<div class="certloom-email-empty">Image</div>';
				case 'divider':
					return '<hr>';
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

		function inspectorSection( titleText, contents, open = true ) {
			return `<details class="certloom-inspector-section" ${ open ? 'open' : '' }><summary>${ esc( titleText ) }</summary><div class="certloom-inspector-section__body">${ contents }</div></details>`;
		}

		function renderInspector() {
			if ( selected < 0 || ! blocks[ selected ] ) {
				if ( inspectorHeading ) {
					inspectorHeading.textContent = 'Block settings';
				}
				inspector.innerHTML = `<div class="certloom-inspector-empty"><p>${ esc( L.selectBlock || 'Select a block on the canvas to edit it.' ) }</p></div>`;
				return;
			}

			const block = blocks[ selected ];
			const titleText = blockLabel( block );
			if ( inspectorHeading ) {
				inspectorHeading.textContent = titleText;
			}
			let contentFields = '';
			let layoutFields = '';

			if ( block.type === 'heading' ) {
				contentFields += field( 'Text', 'text' );
				contentFields += field( L.headingLevel || 'Heading level', 'level', 'select', [ [ 1, 'H1' ], [ 2, 'H2' ], [ 3, 'H3' ] ] );
			}
			if ( block.type === 'text' ) {
				contentFields += field( 'Content', 'html', 'textarea' );
			}
			if ( block.type === 'button' ) {
				contentFields += field( 'Button text', 'text' );
				contentFields += field( L.urlLabel || 'URL', 'url' );
				layoutFields += field( 'Alignment', 'align', 'select', [ [ 'left', 'Left' ], [ 'center', 'Center' ], [ 'right', 'Right' ] ] );
			}
			if ( block.type === 'image' ) {
				contentFields += field( L.urlLabel || 'URL', 'url' );
				contentFields += field( L.altText || 'Alternative text', 'alt' );
				contentFields += '<button type="button" class="button" data-choose-image>' + esc( L.chooseImage || 'Choose image' ) + '</button>';
			}
			if ( block.type === 'spacer' ) {
				layoutFields += field( L.spacingPixels || 'Spacing', 'height', 'number' );
			}
			if ( block.type === 'columns' ) {
				contentFields += field( L.leftColumn || 'Left column', 'left_html', 'textarea' );
				contentFields += field( L.rightColumn || 'Right column', 'right_html', 'textarea' );
			}
			if ( block.type === 'divider' ) {
				contentFields = `<p class="description">${ esc( L.dividerDescription || 'A divider separates sections of your email.' ) }</p>`;
			}

			const actions = `<div class="certloom-inspector-actions">
				<button type="button" class="button" data-inspector-up>${ esc( L.moveUp || 'Move up' ) }</button>
				<button type="button" class="button" data-inspector-down>${ esc( L.moveDown || 'Move down' ) }</button>
				<button type="button" class="button" data-inspector-copy>${ esc( L.duplicate || 'Duplicate' ) }</button>
				<button type="button" class="button is-destructive" data-inspector-remove>${ esc( L.remove || 'Remove' ) }</button>
			</div>`;

			let body = '<div class="certloom-inspector-form">';
			if ( contentFields ) {
				body += inspectorSection( L.contentSection || 'Content', contentFields, true );
			}
			if ( layoutFields ) {
				body += inspectorSection( L.layoutSection || 'Layout', layoutFields, true );
			}
			body += inspectorSection( L.blockActions || 'Block actions', actions, true );
			body += '</div>';
			inspector.innerHTML = body;

			qa( '[data-field]', inspector ).forEach( ( input ) => {
				input.addEventListener( 'focus', () => {
					lastTokenField = input;
				} );
				input.addEventListener( 'input', () => {
					let value = input.value;
					if ( input.type === 'number' ) {
						value = parseInt( value || '0', 10 );
					}
					blocks[ selected ][ input.dataset.field ] = value;
					markDirty();
					renderCanvasOnly();
					renderStructure();
				} );
			} );

			q( '[data-inspector-up]', inspector )?.addEventListener( 'click', () => move( selected, -1 ) );
			q( '[data-inspector-down]', inspector )?.addEventListener( 'click', () => move( selected, 1 ) );
			q( '[data-inspector-copy]', inspector )?.addEventListener( 'click', () => copy( selected ) );
			q( '[data-inspector-remove]', inspector )?.addEventListener( 'click', () => remove( selected ) );
			q( '[data-choose-image]', inspector )?.addEventListener( 'click', chooseImage );
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
			canvasFrame?.style.setProperty( '--certloom-email-width', emailWidth + 'px' );
			if ( stageMeta ) {
				stageMeta.textContent = emailWidth + 'px · ' + blocks.length + ( blocks.length === 1 ? ' block' : ' blocks' );
			}
			qa( 'h1,h2,h3', canvas ).forEach( ( heading ) => {
				heading.style.color = values.heading_color || '#173f34';
			} );
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
			const body = blocks.map( blockHtml ).join( '' );
			return `<!doctype html><html><head><meta charset="utf-8"><meta http-equiv="Content-Security-Policy" content="default-src 'none'; img-src data: https: http:; style-src 'unsafe-inline';"></head><body style="margin:0;background:#eef2f0;padding:28px"><div style="box-sizing:border-box;max-width:${ esc( canvas.style.maxWidth || '640px' ) };margin:auto;padding:36px;background:${ esc( canvas.style.background || '#fff' ) };color:${ esc( styles.color ) };font-family:${ esc( styles.fontFamily ) };border-radius:${ esc( canvas.style.borderRadius || '12px' ) }">${ body }</div></body></html>`;
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
