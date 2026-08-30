( function () {
	'use strict';

	function escapeHtml( value ) {
		return String( value ).replace( /[&<>"']/g, function ( character ) {
			return { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;' }[ character ];
		} );
	}

	function escapeSelector( value ) { return window.CSS && 'function' === typeof window.CSS.escape ? window.CSS.escape( String( value ) ) : String( value ).replace( /[^a-zA-Z0-9_-]/g, function ( character ) { return '\\' + character; } ); }

	function updateFieldIndexes( list ) {
		var rows = list.querySelectorAll( '.ecapla-certverify-field-row' );
		for ( var index = 0; index < rows.length; index++ ) {
			var inputs = rows[ index ].querySelectorAll( '[name^="fields["]' );
			for ( var inputIndex = 0; inputIndex < inputs.length; inputIndex++ ) {
				inputs[ inputIndex ].name = inputs[ inputIndex ].name.replace( /^fields\[\d+\]/, 'fields[' + index + ']' );
			}
		}
	}

	function moveRow( row, direction ) {
		if ( 'up' === direction && row.previousElementSibling ) {
			row.parentNode.insertBefore( row, row.previousElementSibling );
		}
		if ( 'down' === direction && row.nextElementSibling ) {
			row.parentNode.insertBefore( row.nextElementSibling, row );
		}
		updateFieldIndexes( row.parentNode );
		row.querySelector( '.ecapla-certverify-field-label' ).focus();
	}

	function initFields() {
		var list = document.getElementById( 'ecapla-certloom-fields-list' );
		if ( ! list ) {
			return;
		}
		var dragged = null;
		list.addEventListener( 'dragstart', function ( event ) {
			var row = event.target.closest( '.ecapla-certverify-field-row' );
			if ( ! row ) {
				return;
			}
			dragged = row;
			row.classList.add( 'is-dragging' );
			event.dataTransfer.effectAllowed = 'move';
		} );
		list.addEventListener( 'dragover', function ( event ) {
			event.preventDefault();
			var target = event.target.closest( '.ecapla-certverify-field-row' );
			if ( ! dragged || ! target || dragged === target ) {
				return;
			}
			var rect = target.getBoundingClientRect();
			list.insertBefore( dragged, event.clientY < rect.top + rect.height / 2 ? target : target.nextElementSibling );
		} );
		list.addEventListener( 'dragend', function () {
			if ( dragged ) {
				dragged.classList.remove( 'is-dragging' );
			}
			dragged = null;
			updateFieldIndexes( list );
		} );
		list.addEventListener( 'click', function ( event ) {
			var row = event.target.closest( '.ecapla-certverify-field-row' );
			if ( ! row ) {
				return;
			}
			if ( event.target.classList.contains( 'ecapla-certverify-move-up' ) ) {
				moveRow( row, 'up' );
			}
			if ( event.target.classList.contains( 'ecapla-certverify-move-down' ) ) {
				moveRow( row, 'down' );
			}
		} );
	}

	function initConfirmations() {
		document.addEventListener( 'click', function ( event ) {
			var control = event.target.closest( '[data-ecapla-confirm]' );
			if ( ! control ) {
				return;
			}
			if ( ! window.confirm( control.getAttribute( 'data-ecapla-confirm' ) ) ) {
				event.preventDefault();
				return;
			}
		} );
	}

	function initBulkOperationFields() {
		var select = document.querySelector( '[data-certloom-bulk-operation]' );
		if ( ! select ) { return; }
		function update() {
			var value = select.value;
			var groups = document.querySelectorAll( '[data-certloom-bulk-fields]' );
			for ( var i = 0; i < groups.length; i++ ) {
				var allowed = groups[ i ].getAttribute( 'data-certloom-bulk-fields' ).split( ',' );
				groups[ i ].classList.toggle( 'is-active', allowed.indexOf( value ) !== -1 );
			}
			var valueField = document.querySelector( '[data-certloom-bulk-value]' );
			if ( valueField ) { valueField.hidden = 'clear_custom_field' === value; }
		}
		select.addEventListener( 'change', update );
		update();
	}

	function initSecondaryCodeGenerator() {
		var button = document.querySelector( '[data-certloom-generate-secondary-code]' );
		var input = document.getElementById( 'certloom-secondary-code' );
		if ( ! button || ! input ) { return; }
		button.addEventListener( 'click', function () {
			if ( ! window.crypto || typeof window.crypto.getRandomValues !== 'function' ) { input.focus(); return; }
			var alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
			var bytes = new Uint8Array( 16 );
			window.crypto.getRandomValues( bytes );
			var raw = '';
			for ( var i = 0; i < bytes.length; i++ ) { raw += alphabet.charAt( bytes[ i ] % 32 ); }
			input.value = raw.match( /.{1,4}/g ).join( '-' );
			input.focus();
			input.select();
		} );
	}

	function initDomainKeyValueLists() {
		document.addEventListener( 'click', function ( event ) {
			var add = event.target.closest( '[data-certloom-add-kv]' );
			if ( add ) {
				var type = add.getAttribute( 'data-certloom-add-kv' );
				var list = document.querySelector( '[data-certloom-kv-list="' + type + '"]' );
				var source = list ? list.querySelector( '.certloom-domain-kv-row' ) : null;
				if ( source ) {
					var clone = source.cloneNode( true );
					var inputs = clone.querySelectorAll( 'input' );
					for ( var i = 0; i < inputs.length; i++ ) { inputs[ i ].value = ''; }
					list.appendChild( clone );
					inputs[ 0 ].focus();
				}
				return;
			}
			var remove = event.target.closest( '.certloom-domain-remove-kv' );
			if ( ! remove ) { return; }
			var row = remove.closest( '.certloom-domain-kv-row' );
			var parent = row ? row.parentNode : null;
			if ( ! row || ! parent ) { return; }
			if ( parent.querySelectorAll( '.certloom-domain-kv-row' ).length <= 1 ) {
				var rowInputs = row.querySelectorAll( 'input' );
				for ( var j = 0; j < rowInputs.length; j++ ) { rowInputs[ j ].value = ''; }
				rowInputs[ 0 ].focus();
				return;
			}
			row.remove();
		} );
	}


	function initProgramProfile() {
		var purpose = document.getElementById( 'certloom-program-purpose' );
		var subcategory = document.getElementById( 'certloom-program-subcategory' );
		if ( ! purpose || ! subcategory ) { return; }

		function updateSubcategories() {
			var activePurpose = purpose.value;
			var options = Array.prototype.slice.call( subcategory.options );
			var firstAllowed = null;
			var currentAllowed = false;
			options.forEach( function ( option ) {
				var allowed = option.getAttribute( 'data-purpose' ) === activePurpose;
				option.hidden = ! allowed;
				option.disabled = ! allowed;
				if ( allowed && ! firstAllowed ) { firstAllowed = option; }
				if ( allowed && option.selected ) { currentAllowed = true; }
			} );
			if ( ! currentAllowed && firstAllowed ) {
				firstAllowed.selected = true;
			}
			subcategory.disabled = ! firstAllowed;
		}

		function initLocaleVariants() {
			var list = document.querySelector( '[data-certloom-locale-variant-list]' );
			var add = document.querySelector( '[data-certloom-add-locale-variant]' );
			var tpl = document.getElementById( 'certloom-program-locale-variant-template' );
			var fallback = document.querySelector( '[data-certloom-locale-fallback]' );
			if ( ! list || ! add || ! tpl || ! fallback ) { return; }

			function normalizedLocale( value ) {
				return String( value || '' ).trim().replace( /-/g, '_' );
			}
			function refreshFallback() {
				var selected = fallback.value || fallback.getAttribute( 'data-selected' ) || 'program_default';
				var seen = {};
				var values = [];
				Array.prototype.forEach.call( list.querySelectorAll( '[name="profile_template_variant_locale[]"]' ), function ( input ) {
					var locale = normalizedLocale( input.value );
					if ( locale && ! seen[ locale ] ) { seen[ locale ] = true; values.push( locale ); }
				} );
				fallback.textContent = '';
				var base = document.createElement( 'option' );
				base.value = 'program_default';
				base.textContent = 'Program default design';
				fallback.appendChild( base );
				values.forEach( function ( locale ) { var option = document.createElement( 'option' ); option.value = locale; option.textContent = locale; fallback.appendChild( option ); } );
				fallback.value = values.indexOf( selected ) !== -1 ? selected : 'program_default';
				fallback.setAttribute( 'data-selected', fallback.value );
			}

			add.addEventListener( 'click', function () {
				if ( list.querySelectorAll( '.certloom-locale-variant-row' ).length >= 30 ) { return; }
				var fragment = tpl.content.cloneNode( true );
				list.appendChild( fragment );
				var rows = list.querySelectorAll( '.certloom-locale-variant-row' );
				var newRow = rows[ rows.length - 1 ];
				initScopedContextLookups( newRow );
				var localeSelect = newRow.querySelector( '[name="profile_template_variant_locale[]"]' );
				if ( localeSelect ) { localeSelect.focus(); }
				refreshFallback();
			} );
			list.addEventListener( 'click', function ( event ) {
				var remove = event.target.closest( '[data-certloom-remove-locale-variant]' );
				if ( ! remove ) { return; }
				var row = remove.closest( '.certloom-locale-variant-row' );
				if ( row ) { row.remove(); refreshFallback(); }
			} );
			list.addEventListener( 'change', function ( event ) {
				if ( event.target.matches( '[name="profile_template_variant_locale[]"]' ) ) { refreshFallback(); }
			} );
			fallback.addEventListener( 'change', function () { fallback.setAttribute( 'data-selected', fallback.value ); } );
			refreshFallback();
		}

		purpose.addEventListener( 'change', updateSubcategories );
		updateSubcategories();
		initLocaleVariants();
	}



	function initRestoreAutoContinue() {
		var form = document.querySelector( 'form[data-certverify-auto-continue="1"]' );
		if ( ! form ) { return; }
		var button = form.querySelector( '[data-certverify-continue-button]' );
		var status = form.querySelector( '[data-certverify-auto-continue-status]' );
		var submitting = false;
		var timer = null;
		function setStatus( message ) { if ( status ) { status.textContent = message; } }
		function submitRestore() {
			if ( submitting || ! document.documentElement.contains( form ) ) { return; }
			submitting = true;
			if ( button ) { button.disabled = true; }
			setStatus( 'Continuing restore…' );
			try {
				if ( form.requestSubmit ) { form.requestSubmit( button || undefined ); } else { form.submit(); }
			} catch ( error ) {
				submitting = false;
				if ( button ) { button.disabled = false; }
				setStatus( 'Automatic continuation could not start. Use Continue restore.' );
			}
		}
		form.addEventListener( 'submit', function () {
			submitting = true;
			if ( timer ) { window.clearTimeout( timer ); }
			if ( button ) { button.disabled = true; }
			setStatus( 'Continuing restore…' );
		} );
		timer = window.setTimeout( submitRestore, 700 );
		window.setTimeout( function () {
			if ( document.documentElement.contains( form ) ) {
				submitting = false;
				if ( button ) { button.disabled = false; }
				setStatus( 'This restore is still on this step. Use Continue restore to retry safely.' );
			}
		}, 7000 );
	}



	function initRecoveryIntakeGuide() {
		var guide = document.querySelector( '[data-certloom-recovery-intake]' );
		if ( ! guide ) { return; }
		var status = guide.querySelector( '[data-certloom-recovery-intake-status]' );
		var steps = guide.querySelectorAll( '[data-certloom-recovery-step]' );
		function activate( kind ) {
			var targetId = 'backup' === kind ? 'ecapla-certverify-backup-file' : 'ecapla-certloom-import-file';
			var target = document.getElementById( targetId );
			if ( ! target ) { return; }
			for ( var i = 0; i < steps.length; i++ ) {
				steps[ i ].classList.toggle( 'is-complete', '1' === steps[ i ].getAttribute( 'data-certloom-recovery-step' ) );
				steps[ i ].classList.toggle( 'is-current', '2' === steps[ i ].getAttribute( 'data-certloom-recovery-step' ) );
			}
			var buttons = guide.querySelectorAll( '[data-certloom-recovery-choice]' );
			for ( var j = 0; j < buttons.length; j++ ) {
				var active = buttons[ j ].getAttribute( 'data-certloom-recovery-choice' ) === kind;
				buttons[ j ].classList.toggle( 'button-primary', active );
				buttons[ j ].setAttribute( 'aria-pressed', active ? 'true' : 'false' );
			}
			if ( status ) { status.textContent = 'backup' === kind ? 'Step 2: choose the Certloom backup you want to review.' : 'Step 2: choose the CSV file you want to review.'; }
			target.scrollIntoView( { block: 'center', behavior: window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches ? 'auto' : 'smooth' } );
			window.setTimeout( function () { target.focus(); }, 0 );
		}
		guide.addEventListener( 'click', function ( event ) {
			var button = event.target.closest( '[data-certloom-recovery-choice]' );
			if ( button ) { activate( button.getAttribute( 'data-certloom-recovery-choice' ) ); }
		} );
	}

	function initProductNoticeRail() {
		var wrap = document.querySelector( '.wrap' );
		if ( ! wrap ) { return; }
		var rail = wrap.querySelector( ':scope > .certloom-product-notice-rail' );
		var notices = Array.prototype.slice.call( wrap.querySelectorAll( ':scope > .notice.certloom-owned-notice, .certloom-dashboard-notice' ) ).filter( function ( notice, index, list ) {
			return list.indexOf( notice ) === index && ( ! rail || ! rail.contains( notice ) );
		} );
		if ( ! rail && ! notices.length ) { return; }
		if ( ! rail ) {
			rail = document.createElement( 'div' );
			rail.className = 'certloom-product-notice-rail';
			rail.setAttribute( 'aria-live', 'polite' );
		}
		var heading = wrap.querySelector( ':scope > h1' );
		var pageHead = wrap.querySelector( ':scope > .certloom-page-head' );
		var anchor = heading || pageHead;
		if ( anchor ) {
			if ( anchor.nextSibling !== rail ) { anchor.parentNode.insertBefore( rail, anchor.nextSibling ); }
		} else if ( wrap.firstChild !== rail ) {
			wrap.insertBefore( rail, wrap.firstChild );
		}
		notices.forEach( function ( notice ) { rail.appendChild( notice ); } );
	}

	function initSettingsSearch() {
		var workspace = document.querySelector( '.certloom-settings-workspace' );
		var input = document.getElementById( 'certloom-settings-search' );
		var results = document.getElementById( 'certloom-settings-search-results' );
		if ( ! workspace || ! input || ! results ) { return; }
		var headings = Array.prototype.slice.call( workspace.querySelectorAll( 'h2[id], h3[id]' ) );
		function update() {
			var query = input.value.trim().toLocaleLowerCase();
			if ( query.length < 2 ) {
				results.hidden = true;
				results.innerHTML = '';
				return;
			}
			var matches = headings.filter( function ( heading ) {
				return heading.textContent.toLocaleLowerCase().indexOf( query ) !== -1;
			} ).slice( 0, 8 );
			if ( ! matches.length ) {
				results.innerHTML = '<span>' + escapeHtml( input.getAttribute( 'data-empty-label' ) || 'No settings sections match.' ) + '</span>';
				results.hidden = false;
				return;
			}
			results.innerHTML = matches.map( function ( heading ) {
				return '<a href="#' + heading.id.replace( /[^A-Za-z0-9_-]/g, '' ) + '">' + escapeHtml( heading.textContent ) + '</a>';
			} ).join( '' );
			results.hidden = false;
		}
		input.setAttribute( 'data-empty-label', input.getAttribute( 'data-empty-label' ) || 'No settings sections match.' );
		input.addEventListener( 'input', update );
		results.addEventListener( 'click', function ( event ) {
			var link = event.target.closest( 'a[href^="#"]' );
			if ( link ) { results.hidden = true; }
		} );
	}



	function initScopedContextLookups( root ) {
		root = root && root.querySelectorAll ? root : document;
		Array.prototype.forEach.call( root.querySelectorAll( 'select[data-certloom-context-lookup]' ), function ( select ) {
			if ( '1' === select.getAttribute( 'data-certloom-context-ready' ) ) { return; }
			var kind = select.getAttribute( 'data-certloom-context-lookup' );
			var nonce = select.getAttribute( 'data-certloom-context-nonce' ) || '';
			if ( [ 'program', 'batch', 'template', 'issuer', 'signer', 'signer_scope', 'tutor_course', 'learndash_course' ].indexOf( kind ) === -1 || ! nonce || 'undefined' === typeof window.ajaxurl ) { return; }
			select.setAttribute( 'data-certloom-context-ready', '1' );

			var wrapper = document.createElement( 'div' );
			wrapper.className = 'certloom-context-lookup';
			var search = document.createElement( 'input' );
			search.type = 'search';
			search.className = 'certloom-context-lookup__search';
			search.autocomplete = 'off';
			search.placeholder = select.getAttribute( 'data-certloom-context-search-placeholder' ) || ( 'batch' === kind ? 'Search Batches…' : ( 'template' === kind ? 'Search published Templates…' : 'Search choices…' ) );
			search.setAttribute( 'aria-label', search.placeholder.replace( /…/g, '' ) );
			var status = document.createElement( 'span' );
			status.className = 'certloom-context-lookup__status';
			status.setAttribute( 'role', 'status' );
			status.setAttribute( 'aria-live', 'polite' );
			status.setAttribute( 'aria-atomic', 'true' );
			select.parentNode.insertBefore( wrapper, select );
			wrapper.appendChild( search );
			wrapper.appendChild( select );
			wrapper.appendChild( status );
			if ( 'template' === kind ) {
				var manageDesigns = document.createElement( 'a' );
				manageDesigns.className = 'certloom-context-lookup__manage';
				manageDesigns.href = window.location.pathname + '?page=certloom-templates&design_view=templates';
				manageDesigns.textContent = 'Manage published designs';
				wrapper.appendChild( manageDesigns );
			}

			var form = select.closest( 'form' );
			var requestSequence = 0;
			var timer = null;
			var defaultValue = select.hasAttribute( 'data-certloom-context-empty-value' ) ? select.getAttribute( 'data-certloom-context-empty-value' ) : ( 'batch' === kind ? '0' : '' );
			var defaultLabel = select.options.length ? select.options[ 0 ].textContent : ( 'batch' === kind ? 'No Batch' : 'Choose Program…' );

			function optionLabel( choice ) { return String( choice && choice.label ? choice.label : '' ); }
			function selectedSnapshot() {
				var selected = select.options[ select.selectedIndex ];
				return selected && select.value && select.value !== defaultValue ? { id: String( select.value ), label: selected.textContent } : null;
			}
			function programId() {
				if ( 'batch' !== kind || ! form ) { return 0; }
				var program = form.querySelector( 'select[name="program_id"]' );
				return program ? parseInt( program.value || '0', 10 ) || 0 : 0;
			}
			function replaceChoices( choices ) {
				var previous = selectedSnapshot();
				var chosen = previous ? previous.id : defaultValue;
				select.innerHTML = '';
				var first = document.createElement( 'option' );
				first.value = defaultValue;
				first.textContent = defaultLabel;
				select.appendChild( first );
				var seen = {};
				( Array.isArray( choices ) ? choices : [] ).forEach( function ( choice ) {
					var value = choice && choice.value !== undefined && choice.value !== null ? String( choice.value ) : String( parseInt( choice.id || '0', 10 ) || 0 );
					if ( ! value || '0' === value || seen[ value ] ) { return; }
					seen[ value ] = true;
					var option = document.createElement( 'option' );
					option.value = value;
					option.textContent = optionLabel( choice );
					if ( 'batch' === kind && choice.program_id ) { option.setAttribute( 'data-program-id', String( choice.program_id ) ); }
					select.appendChild( option );
				} );
				if ( previous && ! seen[ previous.id ] ) {
					var keep = document.createElement( 'option' );
					keep.value = previous.id;
					keep.textContent = previous.label;
					if ( 'batch' === kind && programId() ) { keep.setAttribute( 'data-program-id', String( programId() ) ); }
					select.appendChild( keep );
				}
				select.value = chosen;
			}
			function lookup( query ) {
				if ( 'batch' === kind && ! programId() ) {
					replaceChoices( [] );
					select.disabled = true;
					search.disabled = true;
					status.textContent = 'Choose a Program first.';
					return;
				}
				select.disabled = false;
				search.disabled = false;
				requestSequence++;
				var sequence = requestSequence;
				status.textContent = 'Searching…';
				var body = new FormData();
				body.append( 'action', 'certloom_lookup_scoped_context' );
				body.append( 'nonce', nonce );
				body.append( 'kind', kind );
				body.append( 'query', String( query || '' ) );
				if ( 'program' === kind ) {
					var programStatus = select.getAttribute( 'data-certloom-context-program-status' ) || 'active';
					if ( [ 'active', 'inactive', 'all' ].indexOf( programStatus ) === -1 ) { programStatus = 'active'; }
					body.append( 'program_status', programStatus );
				}
				if ( 'batch' === kind ) { body.append( 'program_id', String( programId() ) ); }
				fetch( window.ajaxurl, { method: 'POST', credentials: 'same-origin', body: body } )
					.then( function ( response ) { return response.json(); } )
					.then( function ( response ) {
						if ( sequence !== requestSequence ) { return; }
						if ( ! response || ! response.success || ! response.data ) { throw new Error( response && response.data && response.data.message ? response.data.message : 'Search failed.' ); }
						var choices = Array.isArray( response.data.choices ) ? response.data.choices : [];
						replaceChoices( choices );
						status.textContent = choices.length ? choices.length + ( 1 === choices.length ? ' match.' : ' matches.' ) : ( 'template' === kind ? 'No published designs match. Publish a design in Design first.' : 'No matching choices.' );
					} )
					.catch( function ( error ) { if ( sequence === requestSequence ) { status.textContent = error && error.message ? error.message : 'Search failed.'; } } );
			}
			function schedule() {
				if ( timer ) { window.clearTimeout( timer ); }
				timer = window.setTimeout( function () { lookup( search.value.trim() ); }, 220 );
			}
			search.addEventListener( 'input', schedule );
			search.addEventListener( 'focus', function () { if ( ! search.value ) { lookup( '' ); } } );

			if ( 'batch' === kind && form ) {
				var program = form.querySelector( 'select[name="program_id"]' );
				if ( program ) {
					program.addEventListener( 'change', function () {
						search.value = '';
						replaceChoices( [] );
						select.value = defaultValue;
						select.dispatchEvent( new Event( 'change', { bubbles: true } ) );
						lookup( '' );
					} );
				}
			}
			if ( 'batch' === kind && ! programId() ) { select.disabled = true; search.disabled = true; status.textContent = 'Choose a Program first.'; }
		} );
	}


	function initProgramBatchSelectors() {
		Array.prototype.forEach.call( document.querySelectorAll( 'form' ), function ( form ) {
			var program = form.querySelector( 'select[name="program_id"]' );
			var batch = form.querySelector( 'select[name="batch_id"]' );
			if ( ! program || ! batch ) { return; }
			function update() {
				var programId = String( program.value || '' );
				var selectedStillValid = false;
				Array.prototype.forEach.call( batch.options, function ( option ) {
					var owner = option.getAttribute( 'data-program-id' );
					var allowed = ! owner || ( programId && owner === programId );
					option.hidden = ! allowed;
					option.disabled = ! allowed;
					if ( allowed && option.selected ) { selectedStillValid = true; }
				} );
				if ( ! selectedStillValid ) { batch.value = '0'; }
			}
			program.addEventListener( 'change', update );
			update();
		} );
	}


	function initScopedFieldForms() {
		Array.prototype.forEach.call( document.querySelectorAll( 'form[data-certloom-scoped-fields-form]' ), function ( form ) {
			var program = form.querySelector( 'select[name="program_id"]' );
			var batch = form.querySelector( 'select[name="batch_id"]' );
			var nonce = form.getAttribute( 'data-certloom-scoped-fields-nonce' ) || '';
			if ( ! program || ! nonce ) { return; }
			var status = form.querySelector( '[data-certloom-scoped-field-status]' );
			var container = form.querySelector( '[data-certloom-scoped-field-inputs]' );
			var legacy = form.querySelector( '[data-certloom-legacy-custom-fields]' );
			var bulkSchema = form.querySelector( '[data-certloom-bulk-field-schema-body]' );
			var existingScript = form.querySelector( '[data-certloom-scoped-existing-values]' );
			var values = {};
			var requestSequence = 0;
			try { values = existingScript ? JSON.parse( existingScript.textContent || '{}' ) : {}; } catch ( error ) { values = {}; }

			function cacheCurrentValues() {
				if ( ! container ) { return; }
				Array.prototype.forEach.call( container.querySelectorAll( '[data-certloom-scoped-value-key]' ), function ( input ) {
					values[ input.getAttribute( 'data-certloom-scoped-value-key' ) ] = input.value;
				} );
			}

			function setLegacyEnabled( enabled ) {
				if ( ! legacy ) { return; }
				legacy.hidden = ! enabled;
				Array.prototype.forEach.call( legacy.querySelectorAll( 'input,select,textarea,button' ), function ( control ) { control.disabled = ! enabled; } );
			}

			function resetRecipientRequirements() {
				Array.prototype.forEach.call( form.querySelectorAll( '[data-certloom-field-contract-required="1"]' ), function ( input ) {
					input.required = false;
					input.removeAttribute( 'data-certloom-field-contract-required' );
				} );
			}

			function applyRecipientRequirements( fields ) {
				resetRecipientRequirements();
				var newMode = form.querySelector( 'input[name="recipient_mode"][value="new"]' );
				if ( ! newMode || ! newMode.checked ) { return; }
				fields.forEach( function ( field ) {
					if ( ! field.required || 'recipient' !== field.value_source ) { return; }
					var input = form.querySelector( '[name="new_recipient[' + field.key.replace( /[^a-z0-9_-]/g, '' ) + ']"]' );
					if ( input ) { input.required = true; input.setAttribute( 'data-certloom-field-contract-required', '1' ); }
				} );
			}

			function renderManual( payload ) {
				if ( ! container ) { return; }
				container.innerHTML = '';
				var fields = Array.isArray( payload.fields ) ? payload.fields : [];
				setLegacyEnabled( ! payload.configured );
				applyRecipientRequirements( fields );

				var gradeInput = form.querySelector( 'input[name="grade"]' );
				var gradeWrap = form.querySelector( '[data-certloom-grade-field]' );
				if ( gradeInput ) { gradeInput.required = false; gradeInput.removeAttribute( 'data-certloom-field-contract-required' ); gradeInput.disabled = false; }
				var gradeField = fields.filter( function ( field ) { return 'grade' === field.key; } )[ 0 ];
				var gradeAvailable = ! payload.configured || !! gradeField;
				if ( gradeWrap ) { gradeWrap.hidden = ! gradeAvailable; }
				if ( gradeInput ) { gradeInput.disabled = ! gradeAvailable; }
				if ( gradeInput && gradeField && gradeField.required ) { gradeInput.required = true; gradeInput.setAttribute( 'data-certloom-field-contract-required', '1' ); }

				if ( ! payload.configured ) {
					if ( status ) { status.textContent = 'Legacy Program field mode — optional free custom keys remain available until this Program adopts Credential fields.'; }
					return;
				}
				if ( ! fields.length ) {
					if ( status ) { status.textContent = 'This Program uses Credential fields but currently assigns no additional fields.'; }
					return;
				}
				if ( status ) { status.textContent = 'Credential fields loaded from the selected Program' + ( batch && batch.value && '0' !== batch.value ? ' + Batch' : '' ) + '.'; }

				fields.forEach( function ( field ) {
					var row = document.createElement( 'div' );
					row.className = 'certloom-scoped-field-row';
					var heading = document.createElement( 'div' );
					heading.className = 'certloom-scoped-field-row__label';
					var strong = document.createElement( 'strong' );
					strong.textContent = field.label + ( field.required ? ' *' : '' );
					heading.appendChild( strong );
					var meta = document.createElement( 'small' );
					meta.textContent = ( 'batch' === field.origin ? 'Batch' : 'Program' ) + ' · ' + ( field.required ? 'Required' : 'Optional' );
					heading.appendChild( meta );
					row.appendChild( heading );

					if ( 'recipient' === field.value_source ) {
						var source = document.createElement( 'p' );
						source.className = 'description';
						source.textContent = 'Value comes from the Recipient profile and is frozen into the issued credential.';
						row.appendChild( source );
					} else if ( 'credential' === field.value_source && 'grade' === field.key ) {
						var grade = document.createElement( 'p' );
						grade.className = 'description';
						grade.textContent = 'Use the Grade / result field above.';
						row.appendChild( grade );
					} else {
						var input;
						if ( 'textarea' === field.type ) {
							input = document.createElement( 'textarea' ); input.rows = 3;
						} else {
							input = document.createElement( 'input' );
							input.type = 'date' === field.type ? 'date' : ( 'number' === field.type ? 'number' : 'text' );
							if ( 'number' === field.type ) { input.step = 'any'; }
						}
						input.name = 'custom_merge_fields[' + field.key + ']';
						input.setAttribute( 'data-certloom-scoped-value-key', field.key );
						input.maxLength = 2000;
						input.required = !! field.required;
						input.value = Object.prototype.hasOwnProperty.call( values, field.key ) ? String( values[ field.key ] ) : '';
						input.setAttribute( 'aria-label', field.label );
						row.appendChild( input );
					}
					var privacy = document.createElement( 'small' );
					privacy.className = 'description';
					privacy.textContent = 'Privacy: ' + String( field.public_exposure || 'private' ).replace( /_/g, ' ' ) + ' · ' + String( field.sensitivity || 'personal' ).replace( /_/g, ' ' );
					row.appendChild( privacy );
					container.appendChild( row );
				} );
			}

			function renderBulkSchema( payload ) {
				if ( ! bulkSchema ) { return; }
				var fields = Array.isArray( payload.fields ) ? payload.fields : [];
				var html = '<code>recipient_id</code> <em>or</em> <code>display_name</code> + <code>email</code>/<code>external_id</code>, <code>first_name</code>, <code>middle_name</code>, <code>last_name</code>, <code>certificate_number</code>';
				var extras = [];
				fields.forEach( function ( field ) {
					if ( 'recipient' === field.value_source ) { return; }
					var column = 'grade' === field.key ? 'grade' : 'custom:' + field.key;
					extras.push( '<code>' + escapeHtml( column ) + '</code>' + ( field.required ? ' <strong>(required)</strong>' : '' ) );
				} );
				if ( ! payload.configured ) { extras.push( '<code>grade</code>', '<code>custom:machine_key</code>' ); }
				bulkSchema.innerHTML = html + ( extras.length ? ', ' + extras.join( ', ' ) : '' ) + '.';
			}

			function renderError( message ) {
				if ( status ) { status.textContent = message || 'Credential fields could not be loaded. The server will still validate before issuance.'; }
			}

			function resolve() {
				cacheCurrentValues();
				requestSequence++;
				var sequence = requestSequence;
				if ( ! program.value ) {
					renderManual( { configured: false, fields: [] } );
					renderBulkSchema( { configured: false, fields: [] } );
					if ( status ) { status.textContent = 'Choose a Program to load its credential fields.'; }
					return;
				}
				if ( status ) { status.textContent = 'Loading credential fields…'; }
				var body = new FormData();
				body.append( 'action', 'certloom_resolve_scoped_fields' );
				body.append( 'nonce', nonce );
				body.append( 'program_id', program.value );
				body.append( 'batch_id', batch ? ( batch.value || '0' ) : '0' );
				window.fetch( window.ajaxurl || '/wp-admin/admin-ajax.php', { method: 'POST', credentials: 'same-origin', body: body } )
					.then( function ( response ) { if ( ! response.ok ) { throw new Error( 'HTTP ' + response.status ); } return response.json(); } )
					.then( function ( response ) {
						if ( sequence !== requestSequence ) { return; }
						if ( ! response || ! response.success || ! response.data ) { throw new Error( response && response.data && response.data.message ? response.data.message : 'Invalid response' ); }
						renderManual( response.data );
						renderBulkSchema( response.data );
					} )
					.catch( function ( error ) { if ( sequence === requestSequence ) { renderError( error && error.message ? error.message : '' ); } } );
			}

			program.addEventListener( 'change', resolve );
			if ( batch ) { batch.addEventListener( 'change', resolve ); }
			Array.prototype.forEach.call( form.querySelectorAll( 'input[name="recipient_mode"]' ), function ( input ) {
				input.addEventListener( 'change', resolve );
			} );
			resolve();
		} );
	}

	function initAdaptiveSetupWizard() {
		var form = document.querySelector( '[data-certloom-purpose-form]' );
		if ( ! form ) { return; }
		var purpose = document.getElementById( 'certloom-wizard-purpose' );
		var subcategory = document.getElementById( 'certloom-wizard-subcategory' );
		var scheme = form.querySelector( '[data-certloom-scheme-field]' );
		if ( ! purpose || ! subcategory ) { return; }
		var summaryPurpose = document.querySelector( '[data-certloom-wizard-summary-purpose]' );
		var summaryType = document.querySelector( '[data-certloom-wizard-summary-type]' );
		var summaryTerminology = document.querySelector( '[data-certloom-wizard-summary-terminology]' );
		var summaryDesign = document.querySelector( '[data-certloom-wizard-summary-design]' );

		function update() {
			var activePurpose = purpose.value;
			var firstAllowed = null;
			var currentAllowed = false;
			Array.prototype.forEach.call( subcategory.options, function ( option ) {
				var allowed = option.getAttribute( 'data-purpose' ) === activePurpose;
				option.hidden = ! allowed;
				option.disabled = ! allowed;
				if ( allowed && ! firstAllowed ) { firstAllowed = option; }
				if ( allowed && option.selected ) { currentAllowed = true; }
			} );
			if ( ! currentAllowed && firstAllowed ) { firstAllowed.selected = true; }
			subcategory.disabled = ! firstAllowed;
			var selected = subcategory.options[ subcategory.selectedIndex ];
			var purposeOption = purpose.options[ purpose.selectedIndex ];
			if ( scheme ) { scheme.hidden = 'certification_standards' !== activePurpose; }
			if ( summaryPurpose && purposeOption ) { summaryPurpose.textContent = purposeOption.textContent; }
			if ( summaryType && selected ) { summaryType.textContent = selected.textContent; }
			if ( summaryTerminology && selected ) { summaryTerminology.textContent = selected.getAttribute( 'data-terminology' ) || 'Credential'; }
			if ( summaryDesign && selected ) { summaryDesign.textContent = selected.getAttribute( 'data-design' ) || 'Clear and professional'; }
		}
		purpose.addEventListener( 'change', update );
		subcategory.addEventListener( 'change', update );
		update();
	}


	function initSetupNumberingPreview() {
		var form = document.querySelector( '[data-certloom-numbering-form]' );
		if ( ! form ) { return; }
		var mode = form.querySelector( '[data-certloom-numbering-mode]' );
		var prefix = form.querySelector( '[data-certloom-numbering-prefix]' );
		var suffix = form.querySelector( '[data-certloom-numbering-suffix]' );
		var start = form.querySelector( '[data-certloom-numbering-start]' );
		var padding = form.querySelector( '[data-certloom-numbering-padding]' );
		var example = form.querySelector( '[data-certloom-numbering-example]' );
		var format = form.querySelector( '[data-certloom-numbering-format]' );
		if ( ! mode || ! example || ! format ) { return; }
		function update() {
			var value = mode.value || 'manual';
			format.hidden = 'manual' === value;
			Array.prototype.forEach.call( form.querySelectorAll( '[data-certloom-sequential-only]' ), function ( row ) { row.hidden = 'sequential' !== value; } );
			if ( 'manual' === value ) { example.textContent = 'Entered when issuing'; return; }
			var lead = prefix ? prefix.value : '';
			var tail = suffix ? suffix.value : '';
			if ( 'sequential' === value ) {
				var n = Math.max( 1, parseInt( start && start.value ? start.value : '1', 10 ) || 1 );
				var width = Math.max( 1, Math.min( 12, parseInt( padding && padding.value ? padding.value : '6', 10 ) || 6 ) );
				example.textContent = lead + String( n ).padStart( width, '0' ) + tail;
			} else if ( 'random' === value ) { example.textContent = lead + '8K3M-2Q7P' + tail; }
			else { example.textContent = lead + '550e8400-e29b-41d4-a716-446655440000' + tail; }
		}
		[ mode, prefix, suffix, start, padding ].forEach( function ( input ) { if ( input ) { input.addEventListener( 'input', update ); input.addEventListener( 'change', update ); } } );
		update();
	}

	function initWizardTemplatePreview() {
		var select = document.getElementById( 'certloom-wizard-template' );
		var panel = document.querySelector( '[data-certloom-wizard-template-preview]' );
		if ( ! select || ! panel || 'undefined' === typeof window.ajaxurl ) { return; }
		var name = panel.querySelector( '[data-certloom-template-preview-name]' );
		var description = panel.querySelector( '[data-certloom-template-preview-description]' );
		var canvas = panel.querySelector( '[data-certloom-template-preview-canvas]' );
		var nonce = panel.getAttribute( 'data-preview-nonce' ) || '';
		var sequence = 0;
		select.addEventListener( 'change', function () {
			sequence++;
			var request = sequence;
			panel.setAttribute( 'aria-busy', 'true' );
			if ( name ) { name.textContent = 'Loading preview…'; }
			if ( description ) { description.textContent = ''; }
			if ( canvas ) { canvas.innerHTML = ''; }
			var body = new FormData();
			body.append( 'action', 'certloom_wizard_template_preview' ); body.append( 'nonce', nonce ); body.append( 'template_id', select.value || '0' );
			fetch( window.ajaxurl, { method: 'POST', credentials: 'same-origin', body: body } ).then( function ( response ) { return response.json(); } ).then( function ( response ) {
				if ( request !== sequence ) { return; }
				if ( ! response || ! response.success || ! response.data ) { throw new Error( response && response.data && response.data.message ? response.data.message : 'Template preview could not be loaded.' ); }
				if ( name ) { name.textContent = response.data.name || 'No default design selected'; }
				if ( description ) { description.textContent = response.data.description || ''; }
				if ( canvas ) { canvas.innerHTML = response.data.html || ''; }
				panel.setAttribute( 'aria-busy', 'false' );
			} ).catch( function ( error ) {
				if ( request !== sequence ) { return; }
				if ( name ) { name.textContent = 'Preview unavailable'; }
				if ( description ) { description.textContent = error && error.message ? error.message : 'Template preview could not be loaded.'; }
				panel.setAttribute( 'aria-busy', 'false' );
			} );
		} );
	}

	function initGoogleDrivePopupConnect() {
		Array.prototype.forEach.call( document.querySelectorAll( 'form[data-certloom-google-popup-form]' ), function ( form ) {
			form.addEventListener( 'submit', function () {
				var popup = null;
				try { popup = window.open( '', 'certloomGoogleDriveOAuth', 'width=620,height=720,resizable=yes,scrollbars=yes' ); } catch ( error ) { popup = null; }
				if ( ! popup ) { form.removeAttribute( 'target' ); var returnMode = form.querySelector( 'input[name="certloom_google_return_mode"]' ); if ( returnMode ) { returnMode.value = 'backup'; } }
				var button = document.querySelector( '[form="' + form.id + '"][data-certloom-google-connect]' ) || form.querySelector( '[data-certloom-google-connect]' );
				var feedback = document.querySelector( '[data-certloom-google-popup-feedback]' );
				if ( button ) { button.setAttribute( 'aria-busy', 'true' ); button.dataset.certloomOriginalLabel = button.textContent; button.textContent = popup ? 'Waiting for Google…' : 'Opening Google…'; }
				if ( feedback ) { feedback.textContent = popup ? 'Complete permission in the Google window.' : 'Your browser blocked the popup, so Google will open in this tab.'; }
			} );
		} );
		window.addEventListener( 'message', function ( event ) {
			if ( event.origin !== window.location.origin || ! event.data || 'certloom-google-drive-oauth' !== event.data.type ) { return; }
			var success = 'connected' === event.data.status;
			Array.prototype.forEach.call( document.querySelectorAll( '[data-certloom-google-popup-feedback]' ), function ( feedback ) { feedback.textContent = event.data.message || ( success ? 'Google Drive connected.' : 'Google Drive was not connected.' ); } );
			Array.prototype.forEach.call( document.querySelectorAll( '[data-certloom-google-status]' ), function ( status ) { if ( success ) { status.textContent = 'Connected'; status.classList.remove( 'is-disconnected' ); status.classList.add( 'is-connected' ); } } );
			Array.prototype.forEach.call( document.querySelectorAll( '[data-certloom-google-enable]' ), function ( input ) { if ( success ) { input.disabled = false; } } );
			Array.prototype.forEach.call( document.querySelectorAll( '.certloom-google-advanced' ), function ( details ) { if ( success ) { details.hidden = true; } } );
			Array.prototype.forEach.call( document.querySelectorAll( '[data-certloom-google-connect]' ), function ( button ) { button.removeAttribute( 'aria-busy' ); if ( success ) { button.textContent = 'Connected'; button.disabled = true; } else if ( button.dataset.certloomOriginalLabel ) { button.textContent = button.dataset.certloomOriginalLabel; } } );
			var refresh = document.querySelector( 'form[data-certloom-google-popup-form][data-certloom-google-refresh-on-success="1"]' );
			if ( success && refresh ) { window.setTimeout( function () { window.location.reload(); }, 350 ); }
		} );
	}



	function initCertloomWordPressMenuState() {
		var params = new URLSearchParams( window.location.search || '' );
		var page = params.get( 'page' ) || '';
		if ( 0 !== page.indexOf( 'certloom' ) ) { return; }
		var top = document.getElementById( 'toplevel_page_certloom' );
		if ( ! top ) { return; }
		top.classList.add( 'wp-has-current-submenu', 'wp-menu-open' );
		top.classList.remove( 'wp-not-current-submenu' );
		var link = top.querySelector( ':scope > a.menu-top' ) || top.querySelector( 'a.menu-top' );
		if ( link ) { link.classList.add( 'wp-has-current-submenu', 'wp-menu-open' ); link.setAttribute( 'aria-current', 'page' ); }
		var submenu = top.querySelector( '.wp-submenu' );
		if ( submenu ) { submenu.removeAttribute( 'hidden' ); }
	}

	function initDomainFormPersistence() {
		var prefix = 'certloomDomainDraft:';
		var storage = null;
		try { storage = window.sessionStorage; } catch ( error ) { storage = null; }
		// Form-draft recovery is a progressive enhancement. If browser/storage policy denies
		// sessionStorage, the rest of Certloom's admin JavaScript must continue normally.
		if ( ! storage ) { return; }
		var params = new URLSearchParams( window.location.search || '' );
		var retry = '1' === params.get( 'certloom_retry' );
		var forms = document.querySelectorAll( 'form[data-certloom-preserve-on-error]' );
		if ( ! forms.length ) {
			if ( ! retry ) { Object.keys( storage ).forEach( function ( key ) { if ( 0 === key.indexOf( prefix ) ) { storage.removeItem( key ); } } ); }
			return;
		}
		function keyFor( form ) {
			var action = form.querySelector( 'input[name="action"]' );
			var id = form.querySelector( 'input[name$="_id"]' );
			return prefix + ( action ? action.value : 'domain' ) + ':' + ( id ? id.value : 'new' );
		}
		forms.forEach( function ( form ) {
			var key = keyFor( form );
			if ( retry ) {
				try {
					var saved = JSON.parse( storage.getItem( key ) || 'null' );
					if ( saved && Array.isArray( saved ) ) {
						saved.forEach( function ( item ) {
							if ( ! item || ! item.name ) { return; }
							var nodes = form.querySelectorAll( '[name="' + escapeSelector( item.name ) + '"]' );
							nodes.forEach( function ( node ) {
								if ( 'checkbox' === node.type || 'radio' === node.type ) { node.checked = Array.isArray( item.value ) ? item.value.indexOf( node.value ) !== -1 : item.value === node.value; }
								else { node.value = item.value; node.dispatchEvent( new Event( 'change', { bubbles:true } ) ); }
							} );
						} );
						var editor = document.getElementById( 'certloom-domain-editor' ); if ( editor ) { editor.scrollIntoView( { block:'start' } ); }
					}
				} catch ( error ) { try { storage.removeItem( key ); } catch ( ignored ) {} }
			} else { try { storage.removeItem( key ); } catch ( ignored ) {} }
			form.addEventListener( 'submit', function () {
				var map = {}, data = new FormData( form );
				data.forEach( function ( value, name ) {
					if ( '_wpnonce' === name || '_wp_http_referer' === name || value instanceof File ) { return; }
					if ( Object.prototype.hasOwnProperty.call( map, name ) ) { map[ name ] = Array.isArray( map[ name ] ) ? map[ name ].concat( [ String( value ) ] ) : [ map[ name ], String( value ) ]; }
					else { map[ name ] = String( value ); }
				} );
				var payload = Object.keys( map ).map( function ( name ) { return { name:name, value:map[ name ] }; } );
				try { storage.setItem( key, JSON.stringify( payload ) ); } catch ( error ) {}
			} );
		} );
	}

	function initDomainMediaAndColorControls() {
		document.querySelectorAll( '[data-certloom-color-control]' ).forEach( function ( control ) {
			var hidden = control.querySelector( '[data-certloom-color-value]' ), picker = control.querySelector( '[data-certloom-color-picker]' ), label = control.querySelector( '[data-certloom-color-label]' ), clear = control.querySelector( '[data-certloom-color-clear]' );
			if ( picker && hidden ) { picker.addEventListener( 'input', function () { hidden.value = picker.value.toUpperCase(); if ( label ) { label.textContent = hidden.value; } } ); }
			if ( clear && hidden ) { clear.addEventListener( 'click', function () { hidden.value = ''; if ( label ) { label.textContent = 'Default'; } } ); }
		} );
		document.querySelectorAll( '[data-certloom-media-control]' ).forEach( function ( control ) {
			var id = control.querySelector( '[data-certloom-media-id]' ), preview = control.querySelector( '[data-certloom-media-preview]' ), choose = control.querySelector( '[data-certloom-media-select]' ), clear = control.querySelector( '[data-certloom-media-clear]' );
			if ( choose ) { choose.addEventListener( 'click', function () {
				if ( ! window.wp || ! wp.media ) { return; }
				var frame = wp.media( { title:'Choose media', button:{ text:'Use this file' }, multiple:false, library:{ type:'image' } } );
				frame.on( 'select', function () { var attachment = frame.state().get( 'selection' ).first().toJSON(); id.value = attachment.id || ''; var src = attachment.sizes && attachment.sizes.thumbnail ? attachment.sizes.thumbnail.url : attachment.url; preview.innerHTML = ( src ? '<img src="' + escapeHtml( src ) + '" alt="">' : '' ) + '<span>' + escapeHtml( attachment.filename || attachment.title || 'Selected file' ) + '</span>'; if ( clear ) { clear.hidden = false; } } );
				frame.open();
			} ); }
			if ( clear ) { clear.addEventListener( 'click', function () { id.value = ''; preview.innerHTML = '<span>No file selected</span>'; clear.hidden = true; } ); }
		} );
	}

	function initScopedFieldEditors() {
		document.querySelectorAll( '[data-certloom-scope-field-editor]' ).forEach( function ( editor ) {
			var picker = editor.querySelector( '[data-certloom-scope-field-picker]' ), add = editor.querySelector( '[data-certloom-scope-field-add]' ), empty = editor.querySelector( '[data-certloom-scope-field-empty]' );
			function refreshEmpty() { if ( empty ) { empty.hidden = !!editor.querySelector( '[data-certloom-scope-field-row]:not([hidden])' ); } }
			function activate( key, defaultRule ) { var row = editor.querySelector( '[data-certloom-scope-field-row="' + escapeSelector( key ) + '"]' ); if ( ! row ) { return; } row.hidden = false; var select = row.querySelector( '[data-certloom-scope-field-rule]' ); if ( select && ! select.value ) { select.value = defaultRule || 'optional'; } var option = picker ? picker.querySelector( 'option[value="' + escapeSelector( key ) + '"]' ) : null; if ( option ) { option.remove(); } if ( picker ) { picker.value = ''; } refreshEmpty(); }
			if ( add && picker ) { add.addEventListener( 'click', function () { if ( picker.value ) { activate( picker.value, 'optional' ); } } ); }
			editor.addEventListener( 'click', function ( event ) { var remove = event.target.closest( '[data-certloom-scope-field-remove]' ); if ( ! remove ) { return; } var row = remove.closest( '[data-certloom-scope-field-row]' ), select = row ? row.querySelector( '[data-certloom-scope-field-rule]' ) : null; if ( ! row || ! select ) { return; } select.value = ''; row.hidden = true; var key = row.getAttribute( 'data-certloom-scope-field-row' ), label = row.querySelector( '[data-certloom-scope-field-label]' ); if ( picker && key && ! picker.querySelector( 'option[value="' + escapeSelector( key ) + '"]' ) ) { var option = document.createElement( 'option' ); option.value = key; option.textContent = label ? label.textContent : key; picker.appendChild( option ); } refreshEmpty(); } );
			var dialog = editor.querySelector( '[data-certloom-quick-field-dialog]' );
			Array.prototype.forEach.call( editor.querySelectorAll( '[data-certloom-quick-field-open]' ), function ( button ) { button.addEventListener( 'click', function () { if ( dialog && dialog.showModal ) { dialog.showModal(); var input = dialog.querySelector( '[data-certloom-quick-field-label]' ); if ( input ) { input.focus(); } } } ); } );
			Array.prototype.forEach.call( editor.querySelectorAll( '[data-certloom-quick-field-close]' ), function ( button ) { button.addEventListener( 'click', function () { if ( dialog && dialog.open ) { dialog.close(); } } ); } );
			var create = editor.querySelector( '[data-certloom-quick-field-create]' );
			if ( create && dialog ) { create.addEventListener( 'click', function () {
				var labelInput = dialog.querySelector( '[data-certloom-quick-field-label]' ), typeInput = dialog.querySelector( '[data-certloom-quick-field-type]' ), feedback = dialog.querySelector( '[data-certloom-quick-field-feedback]' ), labelValue = labelInput ? labelInput.value.trim() : '';
				if ( ! labelValue ) { if ( feedback ) { feedback.textContent = 'Enter a field label.'; } return; }
				create.disabled = true; if ( feedback ) { feedback.textContent = 'Creating field…'; }
				var body = new URLSearchParams(); body.set( 'action', 'certloom_quick_add_scoped_field' ); body.set( 'nonce', editor.getAttribute( 'data-certloom-field-nonce' ) || '' ); body.set( 'label', labelValue ); body.set( 'field_type', typeInput ? typeInput.value : 'text' );
				fetch( window.ajaxurl || '', { method:'POST', credentials:'same-origin', headers:{ 'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8' }, body:body.toString() } ).then( function ( response ) { return response.json(); } ).then( function ( response ) {
					if ( ! response || ! response.success || ! response.data || ! response.data.field ) { throw new Error( response && response.data && response.data.message ? response.data.message : 'Field could not be created.' ); }
					var field = response.data.field, list = editor.querySelector( '[data-certloom-scope-field-list]' ), scope = editor.getAttribute( 'data-certloom-scope-field-editor' ), row = document.createElement( 'div' ); row.className = 'certloom-scoped-field-row'; row.setAttribute( 'data-certloom-scope-field-row', field.key );
					var name = 'program' === scope ? 'program_field_requirement[' + field.key + ']' : 'batch_field_requirement[' + field.key + ']';
					var options = 'program' === scope ? '<option value="">Not used</option><option value="optional" selected>Optional</option><option value="required">Required</option>' : '<option value="">Inherit Program</option><option value="optional" selected>Optional</option><option value="required">Required</option><option value="exclude">Exclude</option>';
					row.innerHTML = '<div><strong data-certloom-scope-field-label>' + escapeHtml( field.label ) + '</strong><span class="certloom-scoped-field-meta">' + escapeHtml( field.type ) + ' · Personal · Private</span></div><label><span class="screen-reader-text">Field rule</span><select name="' + escapeHtml( name ) + '" data-certloom-scope-field-rule>' + options + '</select></label><button type="button" class="button-link-delete" data-certloom-scope-field-remove>Remove</button>';
					if ( list ) { list.appendChild( row ); } if ( feedback ) { feedback.textContent = response.data.message || 'Field created.'; } if ( labelInput ) { labelInput.value = ''; } window.setTimeout( function () { if ( dialog.open ) { dialog.close(); } }, 450 ); refreshEmpty();
				} ).catch( function ( error ) { if ( feedback ) { feedback.textContent = error.message || 'Field could not be created.'; } } ).finally( function () { create.disabled = false; } );
			} ); }
			refreshEmpty();
		} );
	}


	function initTemplateCardPublishing() {
		document.addEventListener( 'click', function ( event ) {
			var button = event.target.closest( '[data-certloom-card-publish]' );
			if ( ! button ) { return; }
			var card = button.closest( '.certloom-template-library-card' ), feedback = card ? card.querySelector( '[data-certloom-card-feedback]' ) : null;
			button.disabled = true; button.setAttribute( 'aria-busy', 'true' ); var original = button.textContent; button.textContent = 'Publishing…'; if ( feedback ) { feedback.textContent = ''; }
			var body = new URLSearchParams(); body.set( 'action', 'certloom_template_quick_publish' ); body.set( 'template_id', button.getAttribute( 'data-template-id' ) || '0' ); body.set( 'expected_revision_id', button.getAttribute( 'data-revision-id' ) || '0' ); body.set( 'expected_revision_sha256', button.getAttribute( 'data-revision-sha' ) || '' ); body.set( 'nonce', button.getAttribute( 'data-nonce' ) || '' );
			fetch( window.ajaxurl || '', { method:'POST', credentials:'same-origin', headers:{ 'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8' }, body:body.toString() } ).then( function ( response ) { return response.json(); } ).then( function ( response ) {
				if ( ! response || ! response.success ) { throw new Error( response && response.data && response.data.message ? response.data.message : 'This design could not be published.' ); }
				if ( feedback ) { feedback.classList.remove( 'is-error' ); feedback.classList.add( 'is-success' ); feedback.textContent = response.data.message || 'Design published.'; }
				var state = card ? card.querySelector( '.certloom-template-publish-state' ) : null; if ( state ) { state.textContent = 'Published'; state.classList.remove( 'is-draft' ); state.classList.add( 'is-published' ); }
				button.remove();
			} ).catch( function ( error ) {
				button.disabled = false; button.removeAttribute( 'aria-busy' ); button.textContent = original;
				if ( feedback ) { feedback.classList.remove( 'is-success' ); feedback.classList.add( 'is-error' ); feedback.textContent = error && error.message ? error.message : 'This design could not be published.'; }
			} );
		} );
	}

	function initPluginLifecycleDialog() {
		var dialog = document.getElementById( 'certloom-plugin-lifecycle-dialog' );
		if ( ! dialog ) { return; }
		var pluginFile = dialog.getAttribute( 'data-certloom-plugin-file' ) || '';
		if ( ! pluginFile ) { return; }
		var row = Array.prototype.find.call( document.querySelectorAll( 'tr[data-plugin]' ), function ( candidate ) { return candidate.getAttribute( 'data-plugin' ) === pluginFile; } ) || null;
		var deactivate = row ? row.querySelector( '.deactivate a' ) : null;
		var redirect = dialog.querySelector( '[data-certloom-deactivate-redirect]' );
		var firstChoice = dialog.querySelector( 'input[name="delete_data_on_uninstall"]' );
		if ( ! deactivate || ! redirect ) { return; }
		deactivate.addEventListener( 'click', function ( event ) {
			event.preventDefault(); redirect.value = deactivate.href;
			if ( 'function' === typeof dialog.showModal ) { dialog.showModal(); if ( firstChoice ) { window.setTimeout( function () { firstChoice.focus(); }, 0 ); } }
			else if ( window.confirm( 'Deactivating Certloom stops public credential verification. Continue?' ) ) { window.location.href = deactivate.href; }
		} );
		Array.prototype.forEach.call( dialog.querySelectorAll( '[data-certloom-dialog-close]' ), function ( button ) { button.addEventListener( 'click', function () { if ( dialog.open ) { dialog.close(); } } ); } );
		dialog.addEventListener( 'click', function ( event ) { if ( event.target === dialog ) { dialog.close(); } } );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		initCertloomWordPressMenuState();
		initDomainFormPersistence();
		initDomainMediaAndColorControls();
		initScopedFieldEditors();
		initTemplateCardPublishing();
		initFields();
		initConfirmations();
		initBulkOperationFields();
		initSecondaryCodeGenerator();
		initDomainKeyValueLists();
		initProgramProfile();
		initSettingsSearch();
		initRecoveryIntakeGuide();
		initProductNoticeRail();
		initRestoreAutoContinue();
		initAdaptiveSetupWizard();
		initSetupNumberingPreview();
		initWizardTemplatePreview();
		initGoogleDrivePopupConnect();
		initPluginLifecycleDialog();
		initScopedContextLookups();
		initProgramBatchSelectors();
		initScopedFieldForms();
	} );
} )();
