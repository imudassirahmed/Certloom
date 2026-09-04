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

		const hidden = q( '[data-certloom-blocks-json]', form );
		const canvas = q( '[data-certloom-email-canvas]' );
		const inspector = q( '[data-certloom-email-inspector]' );
		const saveState = q( '[data-certloom-save-state]' );
		const title = q( '[data-certloom-designer-title]' );
		const nameInput = q( '[data-certloom-template-name]' );
		const feedback = q( '[data-certloom-designer-feedback]' );

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

			renderInspector();
			applyTheme();
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

		function renderInspector() {
			if ( selected < 0 || ! blocks[ selected ] ) {
				inspector.innerHTML = `<div class="certloom-inspector-empty"><h2>Block settings</h2><p>${ esc( L.selectBlock || 'Select a block on the canvas to edit it.' ) }</p></div>`;
				return;
			}

			const block = blocks[ selected ];
			let body = `<div class="certloom-inspector-form"><h2>${ esc( ( block.type || 'block' ).replace( /^./, ( character ) => character.toUpperCase() ) ) }</h2>`;

			if ( block.type === 'heading' ) {
				body += field( 'Text', 'text' );
				body += field( L.headingLevel || 'Heading level', 'level', 'select', [ [ 1, 'H1' ], [ 2, 'H2' ], [ 3, 'H3' ] ] );
			}
			if ( block.type === 'text' ) {
				body += field( 'Content', 'html', 'textarea' );
			}
			if ( block.type === 'button' ) {
				body += field( 'Button text', 'text' );
				body += field( L.urlLabel || 'URL', 'url' );
				body += field( 'Alignment', 'align', 'select', [ [ 'left', 'Left' ], [ 'center', 'Center' ], [ 'right', 'Right' ] ] );
			}
			if ( block.type === 'image' ) {
				body += field( L.urlLabel || 'URL', 'url' );
				body += field( L.altText || 'Alternative text', 'alt' );
				body += '<button type="button" class="button" data-choose-image>' + esc( L.chooseImage || 'Choose image' ) + '</button>';
			}
			if ( block.type === 'spacer' ) {
				body += field( L.spacingPixels || 'Spacing', 'height', 'number' );
			}
			if ( block.type === 'columns' ) {
				body += field( L.leftColumn || 'Left column', 'left_html', 'textarea' );
				body += field( L.rightColumn || 'Right column', 'right_html', 'textarea' );
			}

			body += `
				<div class="certloom-inspector-actions">
					<button type="button" class="button" data-inspector-up>${ esc( L.moveUp || 'Move up' ) }</button>
					<button type="button" class="button" data-inspector-down>${ esc( L.moveDown || 'Move down' ) }</button>
					<button type="button" class="button" data-inspector-copy>${ esc( L.duplicate || 'Duplicate' ) }</button>
					<button type="button" class="button is-destructive" data-inspector-remove>${ esc( L.remove || 'Remove' ) }</button>
				</div>
			</div>`;
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
			canvas.style.maxWidth = ( parseInt( values.email_width || '640', 10 ) ) + 'px';
			qa( 'h1,h2,h3', canvas ).forEach( ( heading ) => {
				heading.style.color = values.heading_color || '#173f34';
			} );
		}

		qa( '[data-certloom-theme]', form ).forEach( ( input ) => {
			input.addEventListener( 'input', () => {
				markDirty();
				applyTheme();
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
				qa( '[data-certloom-device]' ).forEach( ( item ) => item.classList.toggle( 'is-active', item === button ) );
				q( '[data-certloom-canvas-frame]' )?.classList.toggle( 'is-mobile', button.dataset.certloomDevice === 'mobile' );
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
